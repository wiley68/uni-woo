<?php
/**
 * Offline DNS and WordPress hook stubs for CP transport tests only.
 *
 * @package MTUC
 */

namespace MTUC {
	/**
	 * @param string $host Hostname.
	 * @param int    $type DNS mask.
	 * @return array|false Deterministic DNS answers, never a live lookup.
	 */
	function dns_get_record( $host, $type ) {
		$GLOBALS['mtuc_cp_test_dns_calls'][] = array( $host, $type );
		return $GLOBALS['mtuc_cp_test_dns_records'] ?? array( array( 'type' => 'A', 'ip' => '93.184.216.34' ) );
	}

	/**
	 * @param resource|\CurlHandle $handle  cURL handle (never executed).
	 * @param array                $options Options.
	 * @return bool Whether options were applied.
	 */
	function curl_setopt_array( $handle, $options ) {
		$GLOBALS['mtuc_cp_test_curl_options'][] = $options;
		return empty( $GLOBALS['mtuc_cp_test_pin_failure'] ) && \curl_setopt_array( $handle, $options );
	}
}

namespace {
	if ( ! function_exists( 'add_action' ) ) {
		/**
		 * @param string   $hook          Hook.
		 * @param callable $callback      Callback.
		 * @param int      $priority      Priority.
		 * @param int      $accepted_args Argument count.
		 * @return void
		 */
		function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
			$GLOBALS['mtuc_cp_test_hooks'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
		}
	}
	if ( ! function_exists( 'remove_action' ) ) {
		/**
		 * @param string   $hook     Hook.
		 * @param callable $callback Callback.
		 * @param int      $priority Priority.
		 * @return void
		 */
		function remove_action( $hook, $callback, $priority = 10 ) {
			foreach ( $GLOBALS['mtuc_cp_test_hooks'][ $hook ][ $priority ] ?? array() as $key => $entry ) {
				if ( $entry[0] === $callback ) {
					unset( $GLOBALS['mtuc_cp_test_hooks'][ $hook ][ $priority ][ $key ] );
				}
			}
		}
	}
	if ( ! function_exists( 'do_action' ) ) {
		/**
		 * @param string $hook Hook.
		 * @param mixed  ...$args Arguments.
		 * @return void
		 */
		function do_action( $hook, ...$args ) {
			$priorities = $GLOBALS['mtuc_cp_test_hooks'][ $hook ] ?? array();
			ksort( $priorities );
			foreach ( $priorities as $entries ) {
				foreach ( $entries as $entry ) {
					call_user_func_array( $entry[0], array_slice( $args, 0, $entry[1] ) );
				}
			}
		}
	}

	/**
	 * Run the real pin hook without executing cURL or opening a connection.
	 *
	 * @param string $url  Request URL.
	 * @param array  $args HTTP arguments.
	 * @return void
	 */
	function mtuc_cp_test_prepare_http( string $url, array $args ): void {
		$handle = curl_init();
		do_action( 'http_api_curl', $handle, $args, $url );
		$GLOBALS['mtuc_cp_test_http_args'][] = $args;
	}
}
