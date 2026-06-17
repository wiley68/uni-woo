<?php
/**
 * TEMPORARY — dev-only cache refresh statistics. Delete this file before production.
 *
 * Log file: dev-cache-refresh-stats.log (same directory).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Append one line to the dev refresh stats log.
 *
 * @param string                    $unicid Store unicid.
 * @param array<string, mixed>|WP_Error $result API/cache refresh outcome.
 * @return void
 */
function mtuc_dev_log_cache_refresh( string $unicid, $result ): void {
	if ( ! defined( 'MTUC_DEV_CACHE_REFRESH_LOG' ) || ! MTUC_DEV_CACHE_REFRESH_LOG ) {
		return;
	}

	$log_file = MTUC_PLUGIN_DIR . '/dev-cache-refresh-stats.log';
	$number   = 1;

	if ( is_readable( $log_file ) ) {
		$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( is_array( $lines ) ) {
			foreach ( array_reverse( $lines ) as $line ) {
				if ( '#' === substr( $line, 0, 1 ) || false === strpos( $line, ';' ) ) {
					continue;
				}
				$parts = explode( ';', $line, 2 );
				if ( isset( $parts[0] ) && is_numeric( $parts[0] ) ) {
					$number = (int) $parts[0] + 1;
					break;
				}
			}
		}
	}

	if ( is_wp_error( $result ) ) {
		$status = 'error: ' . $result->get_error_code();
		$detail = $result->get_error_message();
	} else {
		$status = 'ok';
		$detail = '';
	}

	$line = sprintf(
		"%d;%s;%s;%s;%s\n",
		$number,
		gmdate( 'Y-m-d H:i:s' ) . ' UTC',
		$unicid,
		$status,
		str_replace( array( "\r", "\n", ';' ), ' ', $detail )
	);

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
	$handle = fopen( $log_file, 'ab' );
	if ( false === $handle ) {
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
	flock( $handle, LOCK_EX );
	fwrite( $handle, $line );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
	flock( $handle, LOCK_UN );
	fclose( $handle );
}
