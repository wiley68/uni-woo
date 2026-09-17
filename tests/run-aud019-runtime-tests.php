<?php
/**
 * AUD-WOO-019 runtime remediation (operator findings).
 *
 * @package MTUC
 */

define( 'MTUC_TEST_USE_REAL_DEBUG_LOG', true );

require_once __DIR__ . '/bootstrap.php';

$mtuc_assert_count = 0;

/**
 * @param bool   $cond Condition.
 * @param string $msg  Message.
 * @return void
 */
function mtuc_rt_assert( bool $cond, string $msg ): void {
	global $mtuc_assert_count;
	++$mtuc_assert_count;
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$msg}\n" );
		exit( 1 );
	}
}

if ( ! defined( 'MTUC_VERSION' ) ) {
	define( 'MTUC_VERSION', '2.0.3' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'MTUC_ORDER_META_PREFIX' ) ) {
	define( 'MTUC_ORDER_META_PREFIX', '_mtuc_' );
}
if ( ! defined( 'MTUC_ORDER_META_CP_SHOP_ORDER_ID' ) ) {
	define( 'MTUC_ORDER_META_CP_SHOP_ORDER_ID', '_mtuc_cp_shop_order_id' );
}
if ( ! defined( 'MTUC_CP_SHOP_ORDER_ID_MAX_LEN' ) ) {
	define( 'MTUC_CP_SHOP_ORDER_ID_MAX_LEN', 13 );
}
if ( ! defined( 'MTUC_PAYMENT_GATEWAY_ID' ) ) {
	define( 'MTUC_PAYMENT_GATEWAY_ID', 'mtunicredit' );
}

$GLOBALS['mtuc_test_options'] = array();
$GLOBALS['mtuc_test_orders']  = array();

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option Option.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return array_key_exists( (string) $option, $GLOBALS['mtuc_test_options'] )
			? $GLOBALS['mtuc_test_options'][ (string) $option ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option Option.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( $option, $value ) {
		$GLOBALS['mtuc_test_options'][ (string) $option ] = $value;
		return true;
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-settings.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-debug-log.php';

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal order double.
	 */
	class WC_Order {
		/** @var int */
		public $id = 0;
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var bool */
		public $save_ok = true;

		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}
		public function get_id(): int {
			return $this->id;
		}
		/**
		 * @param string $key Key.
		 * @return mixed
		 */
		public function get_meta( $key ) {
			return $this->meta[ (string) $key ] ?? '';
		}
		/**
		 * @param string $key Key.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function update_meta_data( $key, $value ): void {
			$this->meta[ (string) $key ] = $value;
		}
		/**
		 * @return int|false
		 */
		public function save() {
			if ( ! $this->save_ok ) {
				return false;
			}
			$GLOBALS['mtuc_test_orders'][ $this->id ] = clone $this;
			return $this->id;
		}
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $id Order id.
	 * @return WC_Order|false
	 */
	function wc_get_order( $id ) {
		$id = (int) $id;
		if ( isset( $GLOBALS['mtuc_test_orders'][ $id ] ) && $GLOBALS['mtuc_test_orders'][ $id ] instanceof WC_Order ) {
			return clone $GLOBALS['mtuc_test_orders'][ $id ];
		}
		return false;
	}
}

if ( ! function_exists( 'mtuc_get_cp_shop_order_id' ) ) {
	/**
	 * Lightweight mirror of production getter (full popup-order.php is not loaded here).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	function mtuc_get_cp_shop_order_id( WC_Order $order ): string {
		return (string) $order->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID );
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-idempotency.php';

$GLOBALS['mtuc_test_options'][ Mtuc_Settings::OPTION_UNICID ]     = 'SHOP-RUNTIME-UNICID';
$GLOBALS['mtuc_test_options'][ Mtuc_Settings::OPTION_SECRET_KEY ] = 'shop-secret';

// ---------------------------------------------------------------------------
// ISSUE 1 — sucfOnlineSessionID preserved; secrets still redacted
// ---------------------------------------------------------------------------

$session = 'LiveSession-Runtime-001';
$req     = wp_json_encode(
	array(
		'user'            => 'bank-user',
		'pass'            => 'bank-pass',
		'clientFirstName' => 'Иван',
		'clientPhone'     => '0888123456',
		'clientEmail'     => 'ivan@example.com',
	)
);
$resp = wp_json_encode(
	array(
		'sucfOnlineSessionID' => $session,
		'clientEmail'         => 'ivan@example.com',
		'pass'                => 'should-hide',
	)
);

$san_req  = json_decode( Mtuc_Debug_Log::sanitize_request_for_journal( $req ), true );
$san_resp = json_decode( Mtuc_Debug_Log::sanitize_response_for_journal( $resp ), true );

mtuc_rt_assert( is_array( $san_req ), 'ISSUE1 request sanitizes to JSON' );
mtuc_rt_assert( '[REDACTED]' === ( $san_req['user'] ?? null ), 'ISSUE1 user redacted' );
mtuc_rt_assert( '[REDACTED]' === ( $san_req['pass'] ?? null ), 'ISSUE1 pass redacted' );
mtuc_rt_assert( '[REDACTED]' === ( $san_req['clientFirstName'] ?? null ), 'ISSUE1 PII redacted' );
mtuc_rt_assert( is_array( $san_resp ), 'ISSUE1 response sanitizes to JSON' );
mtuc_rt_assert( $session === ( $san_resp['sucfOnlineSessionID'] ?? null ), 'ISSUE1 session id preserved' );
mtuc_rt_assert( '[REDACTED]' === ( $san_resp['clientEmail'] ?? null ), 'ISSUE1 response email redacted' );
mtuc_rt_assert( '[REDACTED]' === ( $san_resp['pass'] ?? null ), 'ISSUE1 response pass redacted' );

// ---------------------------------------------------------------------------
// ISSUE 2 — Process 2 UI field order: phone2 before EGN
// ---------------------------------------------------------------------------

$checkout_tpl = (string) file_get_contents( MTUC_PLUGIN_DIR . '/templates/checkout-payment-fields.php' );
$popup_tpl    = (string) file_get_contents( MTUC_PLUGIN_DIR . '/templates/product-popup.php' );

$co_phone = strpos( $checkout_tpl, 'mtuc-checkout-phone2' );
$co_egn   = strpos( $checkout_tpl, 'mtuc-checkout-egn' );
$pu_phone = strpos( $popup_tpl, 'mtuc-popup-phone2' );
$pu_egn   = strpos( $popup_tpl, 'mtuc-popup-egn' );

mtuc_rt_assert( false !== $co_phone && false !== $co_egn && $co_phone < $co_egn, 'ISSUE2 checkout: phone2 before EGN' );
mtuc_rt_assert( false !== $pu_phone && false !== $pu_egn && $pu_phone < $pu_egn, 'ISSUE2 popup: phone2 before EGN' );
mtuc_rt_assert( false !== strpos( $checkout_tpl, 'name="mtuc_phone2"' ), 'ISSUE2 phone2 POST key unchanged' );
mtuc_rt_assert( false !== strpos( $checkout_tpl, 'name="mtuc_egn"' ), 'ISSUE2 egn POST key unchanged' );

// ---------------------------------------------------------------------------
// ISSUE 3 — encrypted SmartUCF credentials; general cache credential-free
// ---------------------------------------------------------------------------

mtuc_rt_assert( ! defined( 'MTUC_SECRET_SHOP_BANK_CREDENTIALS_FILE' ), 'ISSUE3 shop-bank-credentials constant absent' );
mtuc_rt_assert( ! function_exists( 'mtuc_rotate_shop_bank_credentials' ), 'ISSUE3 rotate helper absent' );
mtuc_rt_assert( ! file_exists( MTUC_PLUGIN_DIR . '/secrets/shop-bank-credentials.php' ), 'ISSUE3 secrets file absent' );
mtuc_rt_assert( defined( 'MTUC_SECRET_SMARTUCF_KEY_FILE' ), 'ISSUE3 certificate password file mechanism retained' );
mtuc_rt_assert( defined( 'MTUC_SMARTUCF_CREDENTIALS_OPTION' ), 'ISSUE3 dedicated credential option constant present' );

$snapshot = array(
	'unicid'       => 'SHOP-RUNTIME-UNICID',
	'uni_user'     => 'bank-user',
	'uni_password' => 'bank-pass',
	'access_token' => 'tok-secret',
	'coeff_list'   => array( array( 'id' => 1 ) ),
);
$prepared = mtuc_prepare_shop_snapshot( $snapshot, 'SHOP-RUNTIME-UNICID' );
mtuc_rt_assert( is_array( $prepared ), 'ISSUE3 prepare accepts snapshot' );
mtuc_rt_assert( ! array_key_exists( 'uni_user', $prepared ), 'ISSUE3 uni_user absent from shop cache' );
mtuc_rt_assert( ! array_key_exists( 'uni_password', $prepared ), 'ISSUE3 uni_password absent from shop cache' );
mtuc_rt_assert( ! array_key_exists( 'access_token', $prepared ), 'ISSUE3 access_token stripped from cache' );
mtuc_rt_assert(
	array_key_exists( MTUC_SMARTUCF_CREDENTIALS_OPTION, $GLOBALS['mtuc_test_options'] ),
	'ISSUE3 COMPLETE pair stored in dedicated option'
);
$hydrated = mtuc_hydrate_smartucf_shop_credentials( $prepared, 'SHOP-RUNTIME-UNICID' );
mtuc_rt_assert( is_array( $hydrated ) && 'bank-user' === ( $hydrated['uni_user'] ?? '' ), 'ISSUE3 runtime hydration restores uni_user' );
mtuc_rt_assert( 'SHOP-RUNTIME-UNICID' === (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID ), 'ISSUE3 UNICID from module config' );

$uninstall = (string) file_get_contents( MTUC_PLUGIN_DIR . '/uninstall.php' );
mtuc_rt_assert( false === strpos( $uninstall, 'shop-bank-credentials' ), 'ISSUE3 uninstall no longer references shop-bank-credentials file' );
mtuc_rt_assert( false !== strpos( $uninstall, 'mtuc_uninstall_smartucf_credentials' ), 'ISSUE3 uninstall removes dedicated credential option' );
$cred_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-credentials.php' );
mtuc_rt_assert( false !== strpos( $cred_src, 'mtuc_shop_credentials' ), 'ISSUE3 legacy mtuc_shop_credentials still cleaned via credential uninstall helper' );

// ---------------------------------------------------------------------------
// ISSUE 4 — checkout must mint durable CP shop order_id before create payload
// ---------------------------------------------------------------------------

$order = new WC_Order( 88421 );
$GLOBALS['mtuc_test_orders'][ 88421 ] = $order;

mtuc_rt_assert( '' === mtuc_get_cp_shop_order_id( $order ), 'ISSUE4 pre-assign: no durable shop order_id yet' );

$assigned = mtuc_assign_cp_shop_order_id( $order );
mtuc_rt_assert( ! is_wp_error( $assigned ), 'ISSUE4 assign succeeds for checkout-like order' );
mtuc_rt_assert( '88421' === (string) $assigned, 'ISSUE4 assign uses Woo internal id' );
$order->save();

mtuc_rt_assert( '88421' === mtuc_get_cp_shop_order_id( $order ), 'ISSUE4 get_cp_shop_order_id returns durable id' );
mtuc_rt_assert( '' !== (string) $order->get_meta( '_mtuc_financing_unicid' ), 'ISSUE4 ownership unicid bound' );

$send_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php' );
mtuc_rt_assert(
	false !== strpos( $send_src, 'function mtuc_send_cart_popup_order_to_cp' )
	&& false !== strpos( $send_src, 'mtuc_assign_cp_shop_order_id( $order )' ),
	'ISSUE4 cart/checkout CP send assigns shop order_id before payload'
);
mtuc_rt_assert(
	1 === preg_match(
		'/function mtuc_complete_order_bank_submission[\s\S]*?mtuc_assign_cp_shop_order_id\(\s*\$order\s*\)/',
		$send_src
	),
	'ISSUE4 complete_order_bank_submission also assigns before CP create'
);

fwrite( STDOUT, "OK: {$mtuc_assert_count} AUD-WOO-019 runtime assertions passed\n" );
exit( 0 );
