<?php
/**
 * Database cache for CP shop configuration (GET /shop).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists shop API payload with 24h TTL.
 */
class Mtuc_Shop_Cache {

	/**
	 * Cache table name without prefix.
	 *
	 * @var string
	 */
	public const TABLE = 'mtuc_shop_cache';

	/**
	 * Fresh cache lifetime — 24 hours.
	 *
	 * @var int
	 */
	public const TTL = DAY_IN_SECONDS;

	/**
	 * Full table name including $wpdb->prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create or upgrade cache table via dbDelta.
	 *
	 * @return void
	 */
	public static function create_table(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table           = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			unicid char(36) NOT NULL,
			shop_data longtext NOT NULL,
			coeff_list longtext NULL,
			kop_data longtext NULL,
			consents longtext NULL,
			uni_picture varchar(512) NULL,
			uni_picturem varchar(512) NULL,
			fetched_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY unicid (unicid),
			KEY expires_at (expires_at)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( 'mtuc_db_version', MTUC_DB_VERSION, false );
	}

	/**
	 * Drop cache table.
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Get shop data — from cache when fresh, otherwise refresh from CP API.
	 *
	 * This is the default entry point for reading shop configuration anywhere
	 * in the module. Missing cache rows and expired TTL trigger an API refresh.
	 * Use refresh_from_api() only for explicit manual refresh (admin button).
	 *
	 * When CP rejects access (deleted shop, wrong unicid/secret), the cache is
	 * purged and WP_Error is returned — stale rows are never used as fallback.
	 *
	 * @param string|null $unicid        Store unicid (defaults to settings).
	 * @param bool        $force_refresh Skip cache and always call CP API.
	 * @return array<string, mixed>|WP_Error Shop `data` object from API.
	 */
	public static function get_shop_data( $unicid = null, bool $force_refresh = false ) {
		if ( null === $unicid ) {
			$unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );
		}

		$unicid = sanitize_text_field( (string) $unicid );
		if ( '' === $unicid ) {
			return new WP_Error(
				'mtuc_cache_no_unicid',
				__( 'Липсва unicid за зареждане на данни от КП.', 'mtunicredit' )
			);
		}

		if ( ! $force_refresh ) {
			$cached = self::get_fresh_row( $unicid );
			if ( null !== $cached ) {
				$data = self::decode_shop_data( $cached['shop_data'] );
				if ( ! is_wp_error( $data ) ) {
					return $data;
				}
			}
		}

		return self::refresh_from_api( $unicid );
	}

	/**
	 * Force fetch from CP and overwrite cache (explicit refresh only).
	 *
	 * On irrecoverable CP/auth failures the entire cache table is cleared so the
	 * module cannot keep serving outdated shop data.
	 *
	 * @param string|null $unicid Store unicid (defaults to settings).
	 * @return array<string, mixed>|WP_Error
	 */
	public static function refresh_from_api( $unicid = null ) {
		if ( null === $unicid ) {
			$unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );
		}

		$unicid = sanitize_text_field( (string) $unicid );
		if ( '' === $unicid ) {
			return new WP_Error(
				'mtuc_cache_no_unicid',
				__( 'Липсва unicid за обновяване на данни от банката.', 'mtunicredit' )
			);
		}

		$response = Mtuc_Cp_Api_Client::fetch_shop();
		if ( is_wp_error( $response ) ) {
			self::purge_on_api_failure( $response );
			return $response;
		}

		if ( empty( $response['success'] ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			$message = isset( $response['message'] ) && is_string( $response['message'] )
				? $response['message']
				: __( 'КП не върна валидни shop данни.', 'mtunicredit' );

			self::purge_all();

			return new WP_Error( 'mtuc_cache_invalid_shop_payload', $message );
		}

		self::save( $unicid, $response['data'] );

		return $response['data'];
	}

	/**
	 * Update cache from CP push webhook (no outbound API call).
	 *
	 * @param string               $unicid Store unicid.
	 * @param array<string, mixed> $data   Shop `data` object.
	 * @return array<string, string>|WP_Error Cache metadata.
	 */
	public static function update_from_cp_push( string $unicid, array $data ) {
		$unicid = sanitize_text_field( $unicid );
		if ( '' === $unicid ) {
			return new WP_Error(
				'mtuc_cache_no_unicid',
				__( 'Липсва unicid за обновяване на кеша.', 'mtunicredit' )
			);
		}

		if ( empty( $data ) ) {
			return new WP_Error(
				'mtuc_cache_invalid_shop_payload',
				__( 'Липсват shop данни в заявката.', 'mtunicredit' )
			);
		}

		self::save( $unicid, $data );

		$meta = self::get_cache_meta( $unicid );
		if ( null === $meta ) {
			return new WP_Error(
				'mtuc_cache_save_failed',
				__( 'Кешът не може да бъде записан.', 'mtunicredit' )
			);
		}

		return $meta;
	}

	/**
	 * Cache metadata for admin UI.
	 *
	 * @param string $unicid Store unicid.
	 * @return array<string, string>|null
	 */
	public static function get_cache_meta( string $unicid ): ?array {
		global $wpdb;

		if ( '' === $unicid ) {
			return null;
		}

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT fetched_at, expires_at FROM {$table} WHERE unicid = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$unicid
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'fetched_at' => (string) $row['fetched_at'],
			'expires_at' => (string) $row['expires_at'],
			'is_fresh'   => self::is_fresh_expires( (string) $row['expires_at'] ) ? '1' : '0',
		);
	}

	/**
	 * Decode coeff_list JSON from cache for a store.
	 *
	 * @param string|null $unicid Store unicid (defaults to settings).
	 * @return array<int, array<string, mixed>>
	 */
	public static function get_coeff_list( $unicid = null ): array {
		if ( null === $unicid ) {
			$unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );
		}

		$unicid = sanitize_text_field( (string) $unicid );
		if ( '' === $unicid ) {
			return array();
		}

		global $wpdb;

		$table = self::table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT coeff_list FROM {$table} WHERE unicid = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$unicid
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) || empty( $row['coeff_list'] ) || ! is_string( $row['coeff_list'] ) ) {
			return array();
		}

		$coeff_list = json_decode( $row['coeff_list'], true );

		return is_array( $coeff_list ) ? $coeff_list : array();
	}

	/**
	 * Delete all rows from the cache table.
	 *
	 * @return void
	 */
	public static function clear_all(): void {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( "DELETE FROM {$table}" );
	}

	/**
	 * Purge cache and stored CP API token after irrecoverable shop/auth failure.
	 *
	 * @return void
	 */
	public static function purge_all(): void {
		self::clear_all();
		Mtuc_Cp_Api_Client::clear_token();
	}

	/**
	 * Delete cache row for unicid.
	 *
	 * @param string $unicid Store unicid.
	 * @return void
	 */
	public static function delete_by_unicid( string $unicid ): void {
		global $wpdb;

		if ( '' === $unicid ) {
			return;
		}

		$table = self::table_name();
		$wpdb->delete( $table, array( 'unicid' => $unicid ), array( '%s' ) );
	}

	/**
	 * Read non-expired cache row.
	 *
	 * @param string $unicid Store unicid.
	 * @return array<string, string>|null
	 */
	private static function get_fresh_row( string $unicid ): ?array {
		global $wpdb;

		$table = self::table_name();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT shop_data, fetched_at, expires_at FROM {$table} WHERE unicid = %s AND expires_at > %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$unicid,
				$now
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert or update cache row.
	 *
	 * @param string               $unicid Store unicid.
	 * @param array<string, mixed> $data   API `data` object.
	 * @return void
	 */
	private static function save( string $unicid, array $data ): void {
		global $wpdb;

		$table   = self::table_name();
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::TTL );

		$shop_json    = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );
		$coeff_list   = isset( $data['coeff_list'] ) ? wp_json_encode( $data['coeff_list'], JSON_UNESCAPED_UNICODE ) : null;
		$kop_data     = isset( $data['kop'] ) ? wp_json_encode( $data['kop'], JSON_UNESCAPED_UNICODE ) : null;
		$consents     = isset( $data['consents'] ) ? wp_json_encode( $data['consents'], JSON_UNESCAPED_UNICODE ) : null;
		$uni_picture  = self::sanitize_picture_url( $data['uni_picture'] ?? '' );
		$uni_picturem = self::sanitize_picture_url( $data['uni_picturem'] ?? '' );

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE unicid = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$unicid
			)
		);

		$row = array(
			'unicid'       => $unicid,
			'shop_data'    => $shop_json,
			'coeff_list'   => $coeff_list,
			'kop_data'     => $kop_data,
			'consents'     => $consents,
			'uni_picture'  => $uni_picture,
			'uni_picturem' => $uni_picturem,
			'fetched_at'   => $now,
			'expires_at'   => $expires,
		);

		if ( $existing_id ) {
			$wpdb->update(
				$table,
				$row,
				array( 'id' => (int) $existing_id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		$wpdb->insert(
			$table,
			$row,
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Sanitize CDN picture URL for cache columns.
	 *
	 * @param mixed $url Raw URL from API.
	 * @return string|null
	 */
	private static function sanitize_picture_url( $url ): ?string {
		$url = esc_url_raw( (string) $url );

		return '' !== $url ? $url : null;
	}

	/**
	 * Decode cached shop JSON.
	 *
	 * @param string $shop_json JSON encoded shop data.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function decode_shop_data( string $shop_json ) {
		$data = json_decode( $shop_json, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'mtuc_cache_corrupt',
				__( 'Кешираните shop данни са повредени.', 'mtunicredit' )
			);
		}

		return $data;
	}

	/**
	 * Whether expires_at is still in the future.
	 *
	 * @param string $expires_at MySQL datetime (GMT).
	 * @return bool
	 */
	private static function is_fresh_expires( string $expires_at ): bool {
		$expires_ts = strtotime( $expires_at . ' UTC' );
		if ( false === $expires_ts ) {
			return false;
		}

		return $expires_ts > time();
	}

	/**
	 * Whether a CP/API error means local shop cache must not be used anymore.
	 *
	 * Transient errors (timeouts, 5xx) keep the cache for a later retry.
	 *
	 * @param WP_Error $error API or auth failure.
	 * @return bool
	 */
	private static function is_irrecoverable_api_failure( WP_Error $error ): bool {
		$code = $error->get_error_code();

		if ( in_array(
			$code,
			array(
				'mtuc_api_missing_credentials',
				'mtuc_api_no_access_token',
				'mtuc_api_refresh_failed',
				'mtuc_cache_invalid_shop_payload',
			),
			true
		) ) {
			return true;
		}

		if ( 'mtuc_api_http_error' !== $code ) {
			return false;
		}

		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

		return in_array( $status, array( 400, 401, 403, 404 ), true );
	}

	/**
	 * Clear cache (and API token) when CP shop data can no longer be trusted.
	 *
	 * @param WP_Error $error API or auth failure.
	 * @return void
	 */
	private static function purge_on_api_failure( WP_Error $error ): void {
		if ( ! self::is_irrecoverable_api_failure( $error ) ) {
			return;
		}

		self::purge_all();
	}
}
