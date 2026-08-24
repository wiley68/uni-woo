<?php
/**
 * Popup idempotency and CP order identity tests (AUD-WOO-006/007).
 *
 * Run: php tests/run-popup-idempotency-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_assert_count = 0;

/** @var array<string, mixed> */
$GLOBALS['mtuc_test_options'] = array();

/** @var list<WC_Order> */
$GLOBALS['mtuc_created_orders'] = array();

/** @var list<array<string, mixed>> */
$GLOBALS['mtuc_order_queries'] = array();

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_pi_assert( bool $ok, string $message ): void {
	global $mtuc_assert_count;
	++$mtuc_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @param mixed  $value Value.
	 * @param string $deprecated Deprecated.
	 * @param string $autoload Autoload.
	 * @return bool
	 */
	function add_option( $option, $value, $deprecated = '', $autoload = 'yes' ) {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $option, $GLOBALS['mtuc_test_options'] ) ) {
			return false;
		}
		$GLOBALS['mtuc_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return $GLOBALS['mtuc_test_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @param mixed  $value Value.
	 * @param bool   $autoload Autoload.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = true ) {
		unset( $autoload );
		$GLOBALS['mtuc_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	/**
	 * @param string $option Option name.
	 * @return bool
	 */
	function delete_option( $option ) {
		unset( $GLOBALS['mtuc_test_options'][ $option ] );
		return true;
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * @return int
	 */
	function get_current_user_id() {
		return 0;
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Test order stand-in.
	 */
	class WC_Order {
		/** @var int */
		public $id;
		/** @var string */
		public $status = 'pending';
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var string */
		public $order_number = '';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var int */
		public $gateway_apply_count = 0;
		/** @var int */
		public $save_count = 0;

		public function __construct( int $id = 0 ) {
			$this->id           = $id;
			$this->order_number = (string) $id;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_order_number(): string {
			return $this->order_number;
		}

		public function get_payment_method(): string {
			return $this->payment_method;
		}

		public function get_status(): string {
			return $this->status;
		}

		/**
		 * @param string $key Meta key.
		 * @return mixed
		 */
		public function get_meta( $key ) {
			return $this->meta[ $key ] ?? '';
		}

		/**
		 * @param string $key Meta key.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		public function save(): void {
			++$this->save_count;
		}
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $id Order ID.
	 * @return WC_Order|null
	 */
	function wc_get_order( $id ) {
		return $GLOBALS['mtuc_test_orders'][ (int) $id ] ?? null;
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * @param array<string, mixed> $args Query args.
	 * @return list<WC_Order>
	 */
	function wc_get_orders( $args ) {
		$GLOBALS['mtuc_order_queries'][] = $args;
		$matches                         = array();

		foreach ( $GLOBALS['mtuc_test_orders'] as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			if ( isset( $args['meta_key'], $args['meta_value'] ) ) {
				if ( (string) $order->get_meta( (string) $args['meta_key'] ) !== (string) $args['meta_value'] ) {
					continue;
				}
			}

			if ( isset( $args['search'] ) ) {
				$needle = (string) $args['search'];
				if ( false === strpos( (string) $order->get_order_number(), $needle )
					&& (string) $order->get_meta( '_mtuc_cp_shop_order_id' ) !== $needle
				) {
					continue;
				}
			}

			$matches[] = $order;
		}

		$limit = isset( $args['limit'] ) ? (int) $args['limit'] : 0;
		if ( $limit > 0 ) {
			$matches = array_slice( $matches, 0, $limit );
		}

		return $matches;
	}
}

/**
 * @return WC_Order
 */
function mtuc_pi_create_test_order(): WC_Order {
	static $next_order_id = 200;

	$id    = ++$next_order_id;
	$order = new WC_Order( $id );
	$GLOBALS['mtuc_test_orders'][ $id ] = $order;
	$GLOBALS['mtuc_created_orders'][]   = $order;
	return $order;
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-bank-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-idempotency.php';

if ( ! function_exists( 'mtuc_apply_payment_gateway_to_order' ) ) {
	/**
	 * @param WC_Order $order Order.
	 * @return void
	 */
	function mtuc_apply_payment_gateway_to_order( WC_Order $order ): void {
		if ( 'pending' === $order->status ) {
			++$order->gateway_apply_count;
			$order->status = 'on-hold';
		}
	}
}

// ---------------------------------------------------------------------------
// AUD-WOO-007 — external CP shop order_id
// ---------------------------------------------------------------------------

$order_874 = new WC_Order( 874 );
$order_874->order_number = 'STORE-CUSTOM-874';
$GLOBALS['mtuc_test_orders'][874] = $order_874;
$id_874 = mtuc_assign_cp_shop_order_id( $order_874 );
mtuc_pi_assert( is_string( $id_874 ), '874 assign returns string' );
mtuc_pi_assert( '874' === $id_874, 'Woo internal ID 874 maps to decimal CP id' );
mtuc_pi_assert( ctype_digit( $id_874 ), '874 CP id is digits only' );
mtuc_pi_assert( $id_874 === mtuc_assign_cp_shop_order_id( $order_874 ), '874 CP id stable on repeat' );
mtuc_pi_assert( '874' === (string) $order_874->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID ), '874 meta equals internal Woo ID' );
mtuc_pi_assert( '874' === mtuc_get_cp_shop_order_id( $order_874 ), '874 getter uses persisted numeric id' );

$order_a = mtuc_pi_create_test_order();
$order_a->order_number = 'STORE-202600001-A';
$id_a                  = mtuc_assign_cp_shop_order_id( $order_a );
mtuc_pi_assert( is_string( $id_a ), 'CP id assign returns string' );
mtuc_pi_assert( (string) $order_a->get_id() === $id_a, 'CP id equals Woo internal numeric ID' );
mtuc_pi_assert( ctype_digit( $id_a ), 'CP id digits only' );
mtuc_pi_assert( 'W' !== $id_a[0], 'CP id has no W prefix' );
mtuc_pi_assert( $id_a === mtuc_assign_cp_shop_order_id( $order_a ), 'CP id stable on repeat' );
mtuc_pi_assert( $id_a === mtuc_get_cp_shop_order_id( $order_a ), 'getter uses persisted id' );

$order_b = mtuc_pi_create_test_order();
$order_b->order_number = 'STORE-202600001-B';
$id_b                  = mtuc_assign_cp_shop_order_id( $order_b );
mtuc_pi_assert( (string) $order_b->get_id() === $id_b, 'second order CP id equals internal ID' );
mtuc_pi_assert( $id_a !== $id_b, 'distinct Woo IDs must not collide after custom order numbers' );

$legacy = mtuc_pi_create_test_order();
$legacy->order_number = 'LEGACY-ORDER-999';
mtuc_pi_assert( 'LEGACY-ORDER-' === mtuc_get_cp_shop_order_id( $legacy ), 'legacy fallback truncates display number' );

$existing_w = new WC_Order( 875 );
$existing_w->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, 'W0000000000OA' );
$GLOBALS['mtuc_test_orders'][875] = $existing_w;
mtuc_pi_assert( 'W0000000000OA' === mtuc_get_cp_shop_order_id( $existing_w ), 'W-format persisted meta preserved' );
mtuc_pi_assert( 'W0000000000OA' === mtuc_assign_cp_shop_order_id( $existing_w ), 'W-format not rewritten on assign' );
$found_w = mtuc_find_order_by_cp_order_id( 'W0000000000OA' );
mtuc_pi_assert( $found_w instanceof WC_Order && 875 === $found_w->get_id(), 'W-format callback lookup resolves' );

$found = mtuc_find_order_by_cp_order_id( $id_a );
mtuc_pi_assert( $found instanceof WC_Order && $found->get_id() === $order_a->get_id(), 'exact meta lookup' );

$found_874 = mtuc_find_order_by_cp_order_id( '874' );
mtuc_pi_assert( $found_874 instanceof WC_Order && 874 === $found_874->get_id(), 'numeric CP id lookup by meta and wc_get_order' );

$max_valid = new WC_Order( 9999999999999 );
$GLOBALS['mtuc_test_orders'][9999999999999] = $max_valid;
$id_max = mtuc_assign_cp_shop_order_id( $max_valid );
mtuc_pi_assert( '9999999999999' === $id_max, '13-digit internal ID accepted at CP max' );
mtuc_pi_assert( strlen( $id_max ) === MTUC_CP_SHOP_ORDER_ID_MAX_LEN, 'CP id at max length boundary' );

$too_long = new WC_Order( 10000000000000 );
$GLOBALS['mtuc_test_orders'][10000000000000] = $too_long;
$too_long_result = mtuc_assign_cp_shop_order_id( $too_long );
mtuc_pi_assert( is_wp_error( $too_long_result ), 'internal ID exceeding CP max fails safely' );
mtuc_pi_assert( 'mtuc_cp_shop_order_id_too_long' === $too_long_result->get_error_code(), 'too-long CP id error code' );
mtuc_pi_assert( '' === (string) $too_long->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID ), 'no meta persisted when CP id too long' );

$long_token = 'dddddddddddddddddddddddddddddddd';
$scope_long = mtuc_build_product_operation_scope_key( 99, 0 );
$long_resolved = mtuc_resolve_popup_financing_order(
	$long_token,
	$scope_long,
	static function () use ( $too_long ) {
		return $too_long;
	}
);
mtuc_pi_assert( is_wp_error( $long_resolved ), 'resolve fails when CP id exceeds limit' );

// ---------------------------------------------------------------------------
// AUD-WOO-006 — durable operation token
// ---------------------------------------------------------------------------

$token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

$scope_a = mtuc_build_product_operation_scope_key( 10, 0 );
$scope_b = mtuc_build_product_operation_scope_key( 10, 0 );
mtuc_pi_assert( $scope_a === $scope_b, 'scope key stable' );

$claim_token = 'cccccccccccccccccccccccccccccccc';
$begin       = mtuc_begin_financing_operation( $claim_token, $scope_a );
mtuc_pi_assert( is_array( $begin ) && true === $begin['claimed'], 'first claim wins' );

$create_count = 0;
$resolved     = mtuc_resolve_popup_financing_order(
	$token,
	$scope_a,
	static function () use ( &$create_count ) {
		++$create_count;
		return mtuc_pi_create_test_order();
	}
);
mtuc_pi_assert( ! is_wp_error( $resolved ), 'resolve after claim' );
mtuc_pi_assert( 1 === $create_count, 'order created once' );
/** @var WC_Order $first_order */
$first_order = $resolved['order'];
mtuc_pi_assert( $token === $first_order->get_meta( MTUC_ORDER_META_OPERATION_TOKEN ), 'token persisted on order' );
mtuc_pi_assert( '' !== (string) $first_order->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID ), 'CP id assigned on commit' );
mtuc_pi_assert(
	(string) $first_order->get_id() === (string) $first_order->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID ),
	'commit persists numeric internal CP id'
);

$create_count = 0;
$retry        = mtuc_resolve_popup_financing_order(
	$token,
	$scope_a,
	static function () use ( &$create_count ) {
		++$create_count;
		return mtuc_pi_create_test_order();
	}
);
mtuc_pi_assert( ! is_wp_error( $retry ), 'sequential retry resolves' );
mtuc_pi_assert( 0 === $create_count, 'sequential retry does not create order' );
mtuc_pi_assert( $retry['order']->get_id() === $first_order->get_id(), 'sequential retry reuses order' );

$new_token = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$resolved2 = mtuc_resolve_popup_financing_order(
	$new_token,
	$scope_a,
	static function () {
		return mtuc_pi_create_test_order();
	}
);
mtuc_pi_assert( ! is_wp_error( $resolved2 ), 'new token creates order' );
mtuc_pi_assert( $resolved2['order']->get_id() !== $first_order->get_id(), 'new token new order' );

$failed = mtuc_pi_create_test_order();
$failed->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'missing' );
$failed->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED_CP );
$failed->update_meta_data( MTUC_ORDER_META_OPERATION_TOKEN, $token . '-failed' );
$GLOBALS['mtuc_test_orders'][ $failed->get_id() ] = $failed;
mtuc_pi_assert( mtuc_order_financing_is_terminal_failure( $failed ), 'terminal failure detected' );

$unknown = mtuc_pi_create_test_order();
$unknown->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'unknown' );
$unknown->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED_CP );
$GLOBALS['mtuc_test_orders'][ $unknown->get_id() ] = $unknown;
mtuc_pi_assert( ! mtuc_order_financing_is_terminal_failure( $unknown ), 'unknown outcome not terminal' );

$success = mtuc_pi_create_test_order();
$success->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS1 );
$success->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, 'https://bank.example/redirect' );
$GLOBALS['mtuc_test_orders'][ $success->get_id() ] = $success;
$result = mtuc_build_existing_popup_submission_result( $success, array( 'uni_proces' => 0 ), false );
mtuc_pi_assert( is_array( $result ) && 'https://bank.example/redirect' === $result['redirect_url'], 'reuse successful redirect' );

// Cart preservation — empty once after accept, not at order create.
$cart_order = mtuc_pi_create_test_order();
$cart_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'submission_source', 'cart_popup' );
$GLOBALS['mtuc_test_orders'][ $cart_order->get_id() ] = $cart_order;

if ( ! function_exists( 'WC' ) ) {
	/**
	 * Fake cart for empty test.
	 */
	class Mtuc_Pi_Fake_Cart {
		/** @var int */
		public $empty_calls = 0;

		public function empty_cart(): void {
			++$this->empty_calls;
		}
	}

	/**
	 * @return object
	 */
	function WC() {
		static $wc = null;
		if ( null === $wc ) {
			$wc = (object) array(
				'cart' => new Mtuc_Pi_Fake_Cart(),
			);
		}
		return $wc;
	}
}

/** @var Mtuc_Pi_Fake_Cart $fake_cart */
$fake_cart = WC()->cart;
mtuc_maybe_empty_cart_for_financing_order( $cart_order );
mtuc_pi_assert( 1 === $fake_cart->empty_calls, 'cart emptied once on accept helper' );
mtuc_maybe_empty_cart_for_financing_order( $cart_order );
mtuc_pi_assert( 1 === $fake_cart->empty_calls, 'duplicate cart empty prevented' );
$cart_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'submission_source', 'cart_popup' );
mtuc_accept_popup_financing_order( $cart_order );
mtuc_pi_assert( 1 === $cart_order->gateway_apply_count, 'gateway applied once' );
mtuc_accept_popup_financing_order( $cart_order );
mtuc_pi_assert( 1 === $cart_order->gateway_apply_count, 'duplicate accept does not re-apply gateway' );

$invalid = mtuc_normalize_operation_token( 'not-valid' );
mtuc_pi_assert( is_wp_error( $invalid ), 'invalid token rejected' );

fwrite( STDOUT, "OK: {$mtuc_assert_count} popup idempotency assertions passed\n" );
exit( 0 );
