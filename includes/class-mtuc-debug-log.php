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

	/** @var string Placeholder for redacted PII in stored request/response bodies. */
	private const REDACTED_VALUE = '[REDACTED]';

	/** @var string Marker when a request body cannot be parsed as JSON for journaling. */
	public const UNPARSEABLE_REQUEST_MARKER = '[UNPARSEABLE_REQUEST_REDACTED]';

	/** @var string Marker when a response body is not valid JSON for journaling. */
	public const NON_JSON_RESPONSE_MARKER = '[NON_JSON_RESPONSE_REDACTED]';

	/**
	 * SmartUCF keys to anonymize before journaling.
	 *
	 * Includes sucfOnlineSessionID: it is appended to the public application URL
	 * (`…/Request/Start/{id}`) and can resume a live financing session.
	 *
	 * @var list<string>
	 */
	private const SMARTUCF_PII_KEYS = array(
		'user',
		'pass',
		'clientFirstName',
		'clientLastName',
		'clientPhone',
		'clientEmail',
		'clientDeliveryAddress',
		'sucfOnlineSessionID',
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
	 * Sanitizes copies only; callers must keep using the original request/response for business logic.
	 *
	 * @param string $request_body  Raw JSON request body.
	 * @param string $response_body Raw response body (JSON or otherwise).
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
				'request_json'  => self::normalize_json_body( self::sanitize_request_for_journal( $request_body ) ),
				'response_json' => self::normalize_json_body( self::sanitize_response_for_journal( $response_body ) ),
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Sanitize a SmartUCF request body before journal persistence.
	 *
	 * Valid JSON: recursively redact known sensitive keys.
	 * Invalid / non-object JSON: persist a bounded safe marker (never the raw body).
	 *
	 * @param string $request_body Raw request body.
	 * @return string
	 */
	public static function sanitize_request_for_journal( string $request_body ): string {
		if ( '' === trim( $request_body ) ) {
			return $request_body;
		}

		$decoded = json_decode( $request_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return self::build_safe_body_marker( self::UNPARSEABLE_REQUEST_MARKER, strlen( $request_body ) );
		}

		$sanitized = self::redact_sensitive_tree( $decoded );
		$encoded   = wp_json_encode( $sanitized );

		if ( ! is_string( $encoded ) ) {
			return self::build_safe_body_marker( self::UNPARSEABLE_REQUEST_MARKER, strlen( $request_body ) );
		}

		return $encoded;
	}

	/**
	 * Sanitize a SmartUCF response body before journal persistence.
	 *
	 * Valid JSON object/array: recursively redact known sensitive keys.
	 * Non-JSON / non-object: persist a bounded safe marker (never the raw body).
	 *
	 * @param string $response_body Raw response body.
	 * @return string
	 */
	public static function sanitize_response_for_journal( string $response_body ): string {
		if ( '' === trim( $response_body ) ) {
			return $response_body;
		}

		$decoded = json_decode( $response_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return self::build_safe_body_marker( self::NON_JSON_RESPONSE_MARKER, strlen( $response_body ) );
		}

		$sanitized = self::redact_sensitive_tree( $decoded );
		$encoded   = wp_json_encode( $sanitized );

		if ( ! is_string( $encoded ) ) {
			return self::build_safe_body_marker( self::NON_JSON_RESPONSE_MARKER, strlen( $response_body ) );
		}

		return $encoded;
	}

	/**
	 * Recursively replace known sensitive key values with the redaction marker.
	 *
	 * Key comparison is exact (case-sensitive), matching the SmartUCF contract names.
	 *
	 * @param array<mixed> $data Decoded JSON structure.
	 * @return array<mixed>
	 */
	private static function redact_sensitive_tree( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && in_array( $key, self::SMARTUCF_PII_KEYS, true ) ) {
				$data[ $key ] = self::REDACTED_VALUE;
				continue;
			}

			if ( is_array( $value ) ) {
				$data[ $key ] = self::redact_sensitive_tree( $value );
			}
		}

		return $data;
	}

	/**
	 * Build a bounded JSON diagnostic stub for unparseable / non-JSON bodies.
	 *
	 * @param string $marker      Structural redaction marker.
	 * @param int    $byte_length Original body length in bytes.
	 * @return string
	 */
	private static function build_safe_body_marker( string $marker, int $byte_length ): string {
		$encoded = wp_json_encode(
			array(
				'message'     => $marker,
				'byte_length' => max( 0, $byte_length ),
			)
		);

		return is_string( $encoded ) ? $encoded : '{"message":"' . $marker . '"}';
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
