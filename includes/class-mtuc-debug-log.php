<?php
/**
 * Database debug journal for CP and SmartUCF API responses.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists API response bodies when debug mode is enabled in plugin settings.
 */
class Mtuc_Debug_Log {

	/** @var string Table name without prefix. */
	public const TABLE = 'mtuc_debug_log';

	/** @var string Log type: CP POST /orders response. */
	public const TYPE_CP_ORDER = 'cp_order';

	/** @var string Log type: CP PATCH /orders/status response. */
	public const TYPE_CP_ORDER_STATUS = 'cp_order_status';

	/** @var string Log type: SmartUCF sucfOnlineSessionStart response. */
	public const TYPE_SMARTUCF = 'smartucf_session';

	/** @var int Delete entries older than this many months on each insert. */
	private const RETENTION_MONTHS = 3;

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
		switch ( $type ) {
			case self::TYPE_CP_ORDER:
				return __( 'КП — създаване на поръчка', 'mtunicredit' );
			case self::TYPE_CP_ORDER_STATUS:
				return __( 'КП — обновяване на статус', 'mtunicredit' );
			case self::TYPE_SMARTUCF:
				return __( 'SmartUCF — старт на сесия', 'mtunicredit' );
			default:
				return $type;
		}
	}

	/**
	 * Store a raw API response body in the debug journal.
	 *
	 * @param string $type          Log type (see TYPE_* constants).
	 * @param string $response_body Raw JSON response body.
	 * @param int    $http_code     HTTP status code (0 if unavailable).
	 * @param int    $wc_order_id   Related WooCommerce order ID.
	 * @return void
	 */
	public static function log_response( string $type, string $response_body, int $http_code = 0, int $wc_order_id = 0 ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		global $wpdb;

		self::purge_old_entries();

		$body = trim( $response_body );
		if ( '' === $body ) {
			$body = '{}';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			self::table_name(),
			array(
				'log_type'      => sanitize_key( $type ),
				'order_id'      => max( 0, $wc_order_id ),
				'http_code'     => max( 0, $http_code ),
				'response_json' => $body,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%s' )
		);
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
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );

		if ( ! is_array( $rows ) ) {
			$rows = array();
		}

		$datetime_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$entries         = array();

		foreach ( $rows as $row ) {
			$raw_response = isset( $row['response_json'] ) ? (string) $row['response_json'] : '';
			$decoded      = json_decode( $raw_response, true );
			$response     = ( JSON_ERROR_NONE === json_last_error() ) ? $decoded : $raw_response;

			$created_gmt = isset( $row['created_at'] ) ? (string) $row['created_at'] : '';

			$entries[] = array(
				'id'              => isset( $row['id'] ) ? (int) $row['id'] : 0,
				'type'            => isset( $row['log_type'] ) ? (string) $row['log_type'] : '',
				'type_label'      => self::get_type_label( isset( $row['log_type'] ) ? (string) $row['log_type'] : '' ),
				'order_id'        => isset( $row['order_id'] ) ? (int) $row['order_id'] : 0,
				'http_code'       => isset( $row['http_code'] ) ? (int) $row['http_code'] : 0,
				'created_at_gmt'  => $created_gmt,
				'created_at_site' => '' !== $created_gmt ? get_date_from_gmt( $created_gmt, $datetime_format ) : '',
				'created_at_iso'  => '' !== $created_gmt ? get_date_from_gmt( $created_gmt, 'c' ) : '',
				'response'        => $response,
			);
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
