<?php
/**
 * Persistent replay protection store for signed module requests.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Atomic nonce claim storage backed by a dedicated plugin table.
 */
final class Mtuc_Api_Nonce_Store {

	/** @var string Table name without prefix. */
	public const TABLE = 'mtuc_api_nonce';

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
	 * Create or upgrade nonce table via dbDelta.
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
			unicid varchar(64) NOT NULL,
			nonce_hash char(64) NOT NULL,
			used_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uniq_mtuc_api_nonce (unicid, nonce_hash),
			KEY idx_mtuc_api_nonce_expires (expires_at)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Drop nonce table.
	 *
	 * @return void
	 */
	public static function drop_table(): void {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Atomically claim a nonce for a shop.
	 *
	 * @param string $unicid Shop UNICID.
	 * @param string $nonce Request nonce header value.
	 * @param int    $now Current unix timestamp.
	 * @return bool True when claim succeeds, false on replay or insert failure.
	 */
	public static function claim_nonce( string $unicid, string $nonce, int $now ): bool {
		global $wpdb;

		self::maybe_purge_expired( $now );

		$table      = self::table_name();
		$nonce_hash = hash( 'sha256', $nonce );
		$used_at    = gmdate( 'Y-m-d H:i:s', $now );
		$expires_at = gmdate( 'Y-m-d H:i:s', $now + Mtuc_Module_Request_Signature_Protocol::NONCE_RETENTION_SECONDS );

		$row = array(
			'unicid'     => $unicid,
			'nonce_hash' => $nonce_hash,
			'used_at'    => $used_at,
			'expires_at' => $expires_at,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%s' ) );

		if ( false === $inserted && self::is_missing_table_error() ) {
			self::create_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$inserted = $wpdb->insert( $table, $row, array( '%s', '%s', '%s', '%s' ) );
		}

		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id > 0;
	}

	/**
	 * Whether the last DB error means the nonce table is missing.
	 */
	private static function is_missing_table_error(): bool {
		global $wpdb;

		$error = (string) $wpdb->last_error;

		return '' !== $error && (
			false !== stripos( $error, "doesn't exist" )
			|| false !== stripos( $error, 'Base table or view not found' )
		);
	}

	/**
	 * Delete expired nonce rows opportunistically.
	 *
	 * @param int $now Current unix timestamp.
	 * @return void
	 */
	private static function maybe_purge_expired( int $now ): void {
		if ( 1 !== random_int( 1, 20 ) ) {
			return;
		}

		global $wpdb;

		$table = self::table_name();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_at < %s",
				gmdate( 'Y-m-d H:i:s', $now )
			)
		);
	}
}
