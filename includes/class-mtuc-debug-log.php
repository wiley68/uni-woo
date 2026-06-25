<?php
/**
 * Database debug journal for SmartUCF order creation requests.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists SmartUCF sucfOnlineSessionStart request/response when debug mode is enabled.
 */
class Mtuc_Debug_Log {

	/** @var string Table name without prefix. */
	public const TABLE = 'mtuc_debug_log';

	/** @var string Log type: SmartUCF sucfOnlineSessionStart. */
	public const TYPE_SMARTUCF = 'smartucf_session';

	/** @var int Delete entries older than this many months on each insert. */
	private const RETENTION_MONTHS = 3;

	/** @var string Placeholder for redacted PII in stored request bodies. */
	private const REDACTED_VALUE = '[REDACTED]';

	/** @var list<string> SmartUCF request keys to anonymize before journaling. */
	private const SMARTUCF_PII_KEYS = array(
		'user',
		'pass',
		'clientFirstName',
		'clientLastName',
		'clientPhone',
		'clientEmail',
		'clientDeliveryAddress',
	);

	/**
	 * Whether debug journaling is enabled in module settings.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 1 === (int) Mtuc_Settings::get( Mtuc_Settings::OPTION_DEBUG );
	}

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
	 * Create or upgrade debug log table via dbDelta.
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
			log_type varchar(32) NOT NULL,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			http_code smallint(5) unsigned NOT NULL DEFAULT 0,
			request_json longtext NOT NULL,
			response_json longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY log_type (log_type),
			KEY created_at (created_at),
			KEY order_id (order_id)
		) $charset_collate;";

		dbDelta( $sql );
	}

	/**
	 * Drop debug log table.
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
	 * Human-readable label for a log type.
	 *
	 * @param string $type Log type constant value.
	 * @return string
	 */
	public static function get_type_label( string $type ): string {
		if ( self::TYPE_SMARTUCF === $type ) {
			return __( 'SmartUCF — създаване на поръчка', 'mtunicredit' );
		}

		return $type;
	}

	/**
	 * Store SmartUCF sucfOnlineSessionStart request and response in the debug journal.
	 *
	 * @param string $request_body  Raw JSON request body.
	 * @param string $response_body Raw JSON response body.
	 * @param int    $http_code     HTTP status code (0 if unavailable).
	 * @param int    $wc_order_id   Related WooCommerce order ID.
	 * @return void
	 */
	public static function log_smartucf_session( string $request_body, string $response_body, int $http_code = 0, int $wc_order_id = 0 ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		global $wpdb;

		self::purge_old_entries();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table_name(),
			array(
				'log_type'      => self::TYPE_SMARTUCF,
				'order_id'      => max( 0, $wc_order_id ),
				'http_code'     => max( 0, $http_code ),
				'request_json'  => self::normalize_json_body( self::anonymize_request_body( $request_body ) ),
				'response_json' => self::normalize_json_body( $response_body ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Remove personal data from a SmartUCF request body before it is stored in the journal.
	 *
	 * @param string $request_body Raw JSON request body.
	 * @return string
	 */
	private static function anonymize_request_body( string $request_body ): string {
		if ( '' === trim( $request_body ) ) {
			return $request_body;
		}

		$decoded = json_decode( $request_body, true );
		if ( ! is_array( $decoded ) ) {
			return $request_body;
		}

		foreach ( self::SMARTUCF_PII_KEYS as $key ) {
			if ( array_key_exists( $key, $decoded ) ) {
				$decoded[ $key ] = self::REDACTED_VALUE;
			}
		}

		$encoded = wp_json_encode( $decoded );

		return is_string( $encoded ) ? $encoded : $request_body;
	}

	/**
	 * Normalize a JSON body string for storage.
	 *
	 * @param string $body Raw JSON body.
	 * @return string
	 */
	private static function normalize_json_body( string $body ): string {
		$body = trim( $body );

		return '' === $body ? '{}' : $body;
	}

	/**
	 * Decode a stored JSON body for export.
	 *
	 * @param string $raw_body Stored JSON string.
	 * @return mixed
	 */
	private static function decode_json_body( string $raw_body ) {
		$raw_body = trim( $raw_body );
		if ( '' === $raw_body ) {
			return null;
		}

		$decoded = json_decode( $raw_body, true );

		return ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $raw_body;
	}

	/**
	 * Format a database row as API/export entry.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, mixed>
	 */
	private static function format_entry_from_row( array $row ): array {
		$raw_request     = isset( $row['request_json'] ) ? (string) $row['request_json'] : '';
		$raw_response    = isset( $row['response_json'] ) ? (string) $row['response_json'] : '';
		$created_gmt     = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';
		$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		return array(
			'id'              => isset( $row['id'] ) ? (int) $row['id'] : 0,
			'type'            => self::TYPE_SMARTUCF,
			'type_label'      => self::get_type_label( self::TYPE_SMARTUCF ),
			'order_id'        => isset( $row['order_id'] ) ? (int) $row['order_id'] : 0,
			'http_code'       => isset( $row['http_code'] ) ? (int) $row['http_code'] : 0,
			'created_at_gmt'  => $created_gmt,
			'created_at_site' => '' !== $created_gmt ? get_date_from_gmt( $created_gmt, $datetime_format ) : '',
			'created_at_iso'  => '' !== $created_gmt ? get_date_from_gmt( $created_gmt, 'c' ) : '',
			'request'         => self::decode_json_body( $raw_request ),
			'response'        => self::decode_json_body( $raw_response ),
		);
	}

	/**
	 * Get the latest SmartUCF debug journal entry for a WooCommerce order.
	 *
	 * @param int $wc_order_id WooCommerce order ID.
	 * @return array<string, mixed>|null
	 */
	public static function get_entry_for_wc_order_id( int $wc_order_id ): ?array {
		if ( $wc_order_id <= 0 ) {
			return null;
		}

		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE log_type = %s AND order_id = %d ORDER BY id DESC LIMIT 1",
				self::TYPE_SMARTUCF,
				$wc_order_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return self::format_entry_from_row( $row );
	}

	/**
	 * Delete journal rows older than RETENTION_MONTHS.
	 *
	 * @return void
	 */
	public static function purge_old_entries(): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . self::RETENTION_MONTHS . ' months', time() ) );
		$table  = self::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) );
	}

	/**
	 * Build export payload with metadata for all journal rows.
	 *
	 * @return array<string, mixed>
	 */
	public static function build_export_data(): array {
		global $wpdb;

		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE log_type = %s ORDER BY id ASC", self::TYPE_SMARTUCF ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$entries = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$entries[] = self::format_entry_from_row( $row );
		}

		return array(
			'plugin'           => 'mtunicredit',
			'plugin_version'   => defined( 'MTUC_VERSION' ) ? MTUC_VERSION : '',
			'site_url'         => home_url(),
			'exported_at_gmt'  => gmdate( 'c' ),
			'exported_at_site' => wp_date( 'c' ),
			'debug_enabled'    => self::is_enabled(),
			'total_entries'    => count( $entries ),
			'entries'          => $entries,
		);
	}

	/**
	 * Send pretty-printed JSON file download to the browser.
	 *
	 * @return void
	 */
	public static function download_export(): void {
		$export = self::build_export_data();
		$json   = wp_json_encode(
			$export,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);

		if ( ! is_string( $json ) ) {
			$json = '{}';
		}

		$filename = 'mtuc-debug-log-' . gmdate( 'Y-m-d-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . (string) strlen( $json ) );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $json;
		exit;
	}
}
