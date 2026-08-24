<?php
/**
 * Resilience tests (AUD-WOO-010 / 011 / 012 / 013).
 *
 * Run: php tests/run-resilience-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_res_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_res_assert( bool $ok, string $message ): void {
	global $mtuc_res_assert_count;
	++$mtuc_res_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/** @param string $key Key. @return string */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/** @param string $str Value. @return string */
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/** @param string $url URL. @return string */
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/** @param mixed $data Data. @return string|false */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * @param string         $format    PHP date format.
	 * @param int|false|null $timestamp Unix timestamp.
	 * @return string
	 */
	function wp_date( $format, $timestamp = null ) {
		return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/** @param string $type Type. @param bool $gmt GMT. @return int|string */
	function current_time( $type, $gmt = 0 ) {
		if ( 'mysql' === $type ) {
			return $gmt ? gmdate( 'Y-m-d H:i:s', time() ) : date( 'Y-m-d H:i:s', time() );
		}
		return time();
	}
}

/** @var array<string, mixed> */
$GLOBALS['mtuc_test_options'] = array();
/** @var array<string, mixed> */
$GLOBALS['mtuc_test_transients'] = array();
/** @var array<string, array<string, array<string, string>>> */
$GLOBALS['mtuc_shop_cache_rows'] = array();

/** @var int CP fetch call counter for shop-cache tests. */
$mtuc_res_cp_fetch_calls = 0;

if ( ! function_exists( 'add_option' ) ) {
	/** @param string $option Option. @param mixed $value Value. @param string $deprecated Deprecated. @param string $autoload Autoload. @return bool */
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $option, $GLOBALS['mtuc_test_options'] ) ) {
			return false;
		}
		$GLOBALS['mtuc_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/** @param string $option Option. @param mixed $default Default. @return mixed */
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['mtuc_test_options'] )
			? $GLOBALS['mtuc_test_options'][ $option ]
			: $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option   Option name.
	 * @param mixed  $value    Option value.
	 * @param mixed  $autoload Autoload flag.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['mtuc_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/** @param string $option Option. @return bool */
	function delete_option( $option ) {
		unset( $GLOBALS['mtuc_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * @param string $transient  Transient name.
	 * @param mixed  $value      Transient value.
	 * @param int    $expiration Expiration in seconds.
	 * @return bool
	 */
	function set_transient( $transient, $value, $expiration ) {
		unset( $expiration );
		$GLOBALS['mtuc_test_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	/** @param string $transient Transient. @return mixed */
	function get_transient( $transient ) {
		return $GLOBALS['mtuc_test_transients'][ $transient ] ?? false;
	}
}

if ( ! class_exists( 'Mtuc_Settings', false ) ) {
	/** Minimal settings stub. */
	class Mtuc_Settings {
		public const OPTION_UNICID = 'mtuc_unicid';

		/** @return mixed */
		public static function get( string $key ) {
			if ( self::OPTION_UNICID === $key ) {
				return 'test-unicid-0001';
			}
			return '';
		}
	}
}

if ( ! class_exists( 'wpdb', false ) ) {
	/** In-memory wpdb stub for shop cache tests. */
	class wpdb {
		/** @var string */
		public $prefix = 'wp_';

		/**
		 * @param string $query  SQL query.
		 * @param mixed  $output Output format constant.
		 * @return array<string, string>|null
		 */
		public function get_row( $query, $output = OBJECT ) {
			unset( $output );
			$query = (string) $query;
			if ( preg_match( "/unicid = '([^']+)' AND expires_at > '([^']+)'/", $query, $m ) ) {
				$unicid = $m[1];
				$now    = $m[2];
				$row    = $GLOBALS['mtuc_shop_cache_rows'][ $unicid ] ?? null;
				if ( ! is_array( $row ) ) {
					return null;
				}
				return $row['expires_at'] > $now ? $row : null;
			}
			if ( preg_match( "/unicid = '([^']+)'/", $query, $m ) ) {
				$unicid = $m[1];
				return $GLOBALS['mtuc_shop_cache_rows'][ $unicid ] ?? null;
			}
			return null;
		}

		/**
		 * @param string               $table  Table name.
		 * @param array<string, mixed> $data   Row data.
		 * @param array<int, string>|null $format Column format placeholders.
		 * @return bool
		 */
		public function replace( $table, $data, $format = null ) {
			unset( $table, $format );
			if ( ! isset( $data['unicid'] ) ) {
				return false;
			}
			$unicid = (string) $data['unicid'];
			$GLOBALS['mtuc_shop_cache_rows'][ $unicid ] = array(
				'shop_data'  => (string) ( $data['shop_data'] ?? '' ),
				'fetched_at' => (string) ( $data['fetched_at'] ?? '' ),
				'expires_at' => (string) ( $data['expires_at'] ?? '' ),
			);
			return true;
		}

		/**
		 * @param string $query SQL query.
		 * @return bool
		 */
		public function query( $query ) {
			if ( false !== strpos( (string) $query, 'TRUNCATE' ) ) {
				$GLOBALS['mtuc_shop_cache_rows'] = array();
			}
			return true;
		}

		/**
		 * @param string $query SQL query.
		 * @return int|null
		 */
		public function get_var( $query ) {
			if ( preg_match( "/unicid = '([^']+)'/", (string) $query, $m ) ) {
				return isset( $GLOBALS['mtuc_shop_cache_rows'][ $m[1] ] ) ? 1 : null;
			}
			return null;
		}

		/**
		 * @param string                    $table        Table name.
		 * @param array<string, mixed>      $data         Row data to update.
		 * @param array<string, mixed>      $where        WHERE conditions.
		 * @param array<int, string>|null   $format       Column format placeholders.
		 * @param array<int, string>|null   $where_format WHERE format placeholders.
		 * @return bool
		 */
		public function update( $table, $data, $where, $format = null, $where_format = null ) {
			unset( $table, $where, $format, $where_format );
			if ( ! isset( $data['unicid'] ) ) {
				return false;
			}
			$unicid = (string) $data['unicid'];
			$GLOBALS['mtuc_shop_cache_rows'][ $unicid ] = array(
				'shop_data'  => (string) ( $data['shop_data'] ?? '' ),
				'fetched_at' => (string) ( $data['fetched_at'] ?? '' ),
				'expires_at' => (string) ( $data['expires_at'] ?? '' ),
			);
			return true;
		}

		/**
		 * @param string               $table  Table name.
		 * @param array<string, mixed> $data   Row data.
		 * @param array<int, string>|null $format Column format placeholders.
		 * @return bool
		 */
		public function insert( $table, $data, $format = null ) {
			unset( $format );
			return $this->replace( $table, $data );
		}

		/**
		 * @param string                  $table        Table name.
		 * @param array<string, mixed>    $where        WHERE conditions.
		 * @param array<int, string>|null $where_format WHERE format placeholders.
		 * @return bool
		 */
		public function delete( $table, $where, $where_format = null ) {
			unset( $table, $where_format );
			if ( isset( $where['unicid'] ) ) {
				unset( $GLOBALS['mtuc_shop_cache_rows'][ (string) $where['unicid'] ] );
			}
			return true;
		}

		/**
		 * @param string $query SQL query with placeholders.
		 * @param mixed  ...$args Values for placeholders.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			if ( 2 === count( $args ) ) {
				return sprintf( $query, "'" . (string) $args[0] . "'", "'" . (string) $args[1] . "'" );
			}
			if ( 1 === count( $args ) ) {
				return sprintf( $query, "'" . (string) $args[0] . "'" );
			}
			return $query;
		}
	}
}

global $wpdb;
$wpdb = new wpdb();

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-secrets.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-error-normalizer.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-order-diagnostics.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-bank-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-cp-api-client.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-shop-cache.php';

/**
 * Seed a cache row with explicit expiry.
 *
 * @param string $unicid     Unicid.
 * @param bool   $fresh        Whether within TTL.
 * @param bool   $stale_window Whether within stale-if-error window after expiry.
 * @return void
 */
function mtuc_res_seed_cache_row( string $unicid, bool $fresh, bool $stale_window = true ): void {
	$now        = time();
	$expires_ts = $fresh ? $now + HOUR_IN_SECONDS : ( $stale_window ? $now - HOUR_IN_SECONDS : $now - 8 * HOUR_IN_SECONDS );
	$payload    = array(
		'id'          => 10,
		'uni_zaglavie' => 'cached-title',
		'uni_eur'     => 0,
	);
	$GLOBALS['mtuc_shop_cache_rows'][ $unicid ] = array(
		'shop_data'  => wp_json_encode( $payload ),
		'fetched_at' => gmdate( 'Y-m-d H:i:s', $now - DAY_IN_SECONDS ),
		'expires_at' => gmdate( 'Y-m-d H:i:s', $expires_ts ),
	);
}

/**
 * @param array<string, mixed>|WP_Error $response Response.
 * @return void
 */
function mtuc_res_queue_cp_fetch( $response ): void {
	global $mtuc_res_cp_fetch_calls;
	Mtuc_Cp_Api_Client::$fetch_shop_override = static function () use ( $response, &$mtuc_res_cp_fetch_calls ) {
		++$mtuc_res_cp_fetch_calls;
		return $response;
	};
}

// --- AUD-WOO-010: shop cache stale-if-error ---

Mtuc_Cp_Api_Client::$fetch_shop_override = null;
$mtuc_res_cp_fetch_calls = 0;
mtuc_res_seed_cache_row( 'test-unicid-0001', true );
$fresh = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( ! is_wp_error( $fresh ) && 0 === $mtuc_res_cp_fetch_calls, 'fresh cache must avoid CP request' );

mtuc_res_seed_cache_row( 'test-unicid-0001', false, true );
mtuc_res_queue_cp_fetch(
	array(
		'success' => true,
		'data'    => array(
			'id'           => 10,
			'uni_zaglavie' => 'refreshed',
			'uni_eur'      => 0,
		),
	)
);
$mtuc_res_cp_fetch_calls = 0;
$refreshed = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( 1 === $mtuc_res_cp_fetch_calls && ! is_wp_error( $refreshed ) && 'refreshed' === $refreshed['uni_zaglavie'], 'expired cache + CP success must refresh' );

mtuc_res_seed_cache_row( 'test-unicid-0001', false, true );
mtuc_res_queue_cp_fetch( new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );
$mtuc_res_cp_fetch_calls = 0;
$stale_timeout = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( ! is_wp_error( $stale_timeout ) && 'cached-title' === $stale_timeout['uni_zaglavie'], 'timeout must use eligible stale config' );

mtuc_res_seed_cache_row( 'test-unicid-0001', false, true );
mtuc_res_queue_cp_fetch( new WP_Error( 'mtuc_api_http_error', 'server error', array( 'status' => 503 ) ) );
$stale_5xx = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( ! is_wp_error( $stale_5xx ) && 'cached-title' === $stale_5xx['uni_zaglavie'], '5xx must use eligible stale config' );

mtuc_res_seed_cache_row( 'test-unicid-0001', false, true );
mtuc_res_queue_cp_fetch( new WP_Error( 'http_request_failed', 'Could not resolve host' ) );
$stale_dns = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( ! is_wp_error( $stale_dns ) && 'cached-title' === $stale_dns['uni_zaglavie'], 'network/DNS must use eligible stale config' );

mtuc_res_seed_cache_row( 'test-unicid-0001', false, true );
mtuc_res_queue_cp_fetch( new WP_Error( 'mtuc_api_http_error', 'Unauthorized', array( 'status' => 401 ) ) );
$auth_fail = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( is_wp_error( $auth_fail ), '401 must not use stale config' );

mtuc_res_seed_cache_row( 'test-unicid-0001', false, true );
mtuc_res_queue_cp_fetch( new WP_Error( 'mtuc_api_refresh_failed', 'Token refresh failed' ) );
$revoked = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( is_wp_error( $revoked ), 'auth/revocation must not use stale config' );

mtuc_res_seed_cache_row( 'test-unicid-0001', false, false );
mtuc_res_queue_cp_fetch( new WP_Error( 'http_request_failed', 'timeout' ) );
$stale_expired = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( is_wp_error( $stale_expired ), 'stale window exceeded must not serve stale config' );

$GLOBALS['mtuc_shop_cache_rows']['test-unicid-0001'] = array(
	'shop_data'  => '{not-json',
	'fetched_at' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ),
	'expires_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
);
mtuc_res_queue_cp_fetch( new WP_Error( 'http_request_failed', 'timeout' ) );
$corrupt = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( is_wp_error( $corrupt ), 'corrupt stale data must not be served' );

// Refresh lock contention: active lock → use stale without CP call.
mtuc_res_seed_cache_row( 'test-unicid-0001', false, true );
$lock_key = 'mtuc_scr_' . md5( 'test-unicid-0001' );
$GLOBALS['mtuc_test_options'][ $lock_key ] = (string) ( time() - 10 );
mtuc_res_queue_cp_fetch( new WP_Error( 'http_request_failed', 'timeout' ) );
$mtuc_res_cp_fetch_calls = 0;
$lock_stale = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( ! is_wp_error( $lock_stale ) && 0 === $mtuc_res_cp_fetch_calls, 'lock contention with stale must not wait for CP timeout' );

// Abandoned lock recovery.
mtuc_res_seed_cache_row( 'test-unicid-0001', false, true );
$GLOBALS['mtuc_test_options'][ $lock_key ] = (string) ( time() - 200 );
mtuc_res_queue_cp_fetch(
	array(
		'success' => true,
		'data'    => array( 'id' => 10, 'uni_zaglavie' => 'after-lock', 'uni_eur' => 0 ),
	)
);
$mtuc_res_cp_fetch_calls = 0;
$after_lock = Mtuc_Shop_Cache::get_shop_data( 'test-unicid-0001' );
mtuc_res_assert( 1 === $mtuc_res_cp_fetch_calls && ! is_wp_error( $after_lock ), 'abandoned refresh lock must recover and refresh' );

// --- AUD-WOO-011: secrets ---

$config_source = file_get_contents( MTUC_PLUGIN_DIR . '/includes/config.php' );
mtuc_res_assert( is_string( $config_source ) && false === strpos( $config_source, "'1234'" ), 'plugin config must not contain hard-coded password 1234' );

putenv( 'MTUC_SMARTUCF_KEY_PASSWORD=env-secret' );
mtuc_res_assert( 'env-secret' === mtuc_get_smartucf_key_password(), 'password must resolve from environment variable when constant absent' );

if ( ! defined( 'MTUC_SMARTUCF_KEY_PASSWORD' ) ) {
	define( 'MTUC_SMARTUCF_KEY_PASSWORD', 'const-secret' );
}
mtuc_res_assert( 'const-secret' === mtuc_get_smartucf_key_password(), 'WordPress constant must take precedence over environment' );
putenv( 'MTUC_SMARTUCF_KEY_PASSWORD=1234' );

// --- AUD-WOO-012: customer-safe errors ---

$timeout_err = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15000 milliseconds' );
$customer_timeout = mtuc_customer_message_from_error( $timeout_err, 'cp' );
mtuc_res_assert( false === stripos( $customer_timeout, 'cURL' ), 'customer message must not expose cURL text' );

$auth_err = new WP_Error( 'mtuc_api_http_error', 'Bearer token rejected body={"secret":"x"}', array( 'status' => 401 ) );
$customer_auth = mtuc_customer_message_from_error( $auth_err, 'cp' );
mtuc_res_assert( false === stripos( $customer_auth, 'Bearer' ) && false === stripos( $customer_auth, 'body=' ), 'customer message must not expose auth/body details' );

$validation_err = new WP_Error( 'mtuc_invalid_quantity', 'Невалидно количество.' );
mtuc_res_assert( 'Невалидно количество.' === mtuc_customer_message_from_error( $validation_err, 'general' ), 'safe validation messages may pass through' );

$normalized = mtuc_normalize_error( new WP_Error( 'mtuc_smartucf_http_error', 'transport', array( 'curl_error' => 'secret detail' ) ), 'smartucf' );
mtuc_res_assert( 'smartucf_transport' === $normalized['category'], 'SmartUCF transport must normalize' );

// --- AUD-WOO-013: order diagnostics ---

if ( ! class_exists( 'WC_Order', false ) ) {
	/** Minimal order for diagnostics. */
	class WC_Order {
		/** @var int */
		public $id = 501;
		/** @var array<string, mixed> */
		public $meta = array();

		public function get_id(): int {
			return $this->id;
		}

		/**
		 * @param string $key   Meta key.
		 * @param mixed  $value Meta value.
		 * @return void
		 */
		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		/** @param string $key Key. @return mixed */
		public function get_meta( $key ) {
			return $this->meta[ $key ] ?? '';
		}

		/** @param string $key Key. @return void */
		public function delete_meta_data( $key ): void {
			unset( $this->meta[ $key ] );
		}

		public function save(): void {
		}
	}
}

$GLOBALS['WP_DEBUG'] = false;
$order = new WC_Order();
mtuc_record_order_financing_diagnostic( $order, new WP_Error( 'http_request_failed', 'timeout detail' ), 'cp' );
$diag = mtuc_get_order_financing_diagnostic( $order );
mtuc_res_assert( is_array( $diag ) && 'cp_timeout' === $diag['category'], 'diagnostic must exist with WP_DEBUG=false' );
mtuc_res_assert( false === stripos( wp_json_encode( $diag ), 'timeout detail' ), 'diagnostic must not store raw technical message' );

$rows = mtuc_get_order_diagnostic_admin_rows( $order );
mtuc_res_assert( ! empty( $rows ), 'admin diagnostic rows must render' );

mtuc_clear_order_financing_diagnostic( $order );
mtuc_res_assert( null === mtuc_get_order_financing_diagnostic( $order ), 'successful clear must remove active diagnostic' );

$history_raw = (string) $order->get_meta( MTUC_ORDER_META_FINANCING_DIAGNOSTIC_HISTORY );
$history     = json_decode( $history_raw, true );
mtuc_res_assert( is_array( $history ) && count( $history ) <= MTUC_ORDER_DIAGNOSTIC_HISTORY_MAX, 'diagnostic history must remain bounded' );

fwrite( STDOUT, 'OK (' . $GLOBALS['mtuc_res_assert_count'] . " assertions)\n" );
