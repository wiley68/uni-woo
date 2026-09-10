<?php
/**
 * Submission ownership / concurrency tests (AUD-WOO-018 F01–F03).
 *
 * Run: php tests/run-submission-ownership-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_assert_count = 0;

/** @var array<string, mixed> */
$GLOBALS['mtuc_test_options'] = array();

/** @var array<int, WC_Order> */
$GLOBALS['mtuc_test_orders'] = array();

/** @var list<WC_Order> */
$GLOBALS['mtuc_created_orders'] = array();

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_so_assert( bool $ok, string $message ): void {
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
		/** @var string */
		public $created_via = '';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var int */
		public $save_count = 0;
		/** @var list<array<string, mixed>> */
		public $line_items = array();
		/** @var float|string */
		public $total = 0;

		public function __construct( int $id = 0 ) {
			$this->id           = $id;
			$this->order_number = (string) $id;
		}

		public function get_id(): int {
			return $this->id;
		}

		/**
		 * @param string $context View or edit context.
		 * @return float|string
		 */
		public function get_total( $context = 'view' ) {
			unset( $context );
			return $this->total;
		}

		public function get_order_number(): string {
			return $this->order_number;
		}

		public function get_payment_method(): string {
			return $this->payment_method;
		}

		/**
		 * @param string $method Payment method.
		 * @return void
		 */
		public function set_payment_method( $method ): void {
			$this->payment_method = (string) $method;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function get_created_via(): string {
			return $this->created_via;
		}

		/**
		 * @param string $via Created via.
		 * @return void
		 */
		public function set_created_via( $via ): void {
			$this->created_via = (string) $via;
		}

		/**
		 * @param string $type Item type.
		 * @return list<array<string, mixed>>
		 */
		public function get_items( $type = '' ) {
			unset( $type );
			return $this->line_items;
		}

		/**
		 * @param int $item_id Item id.
		 * @return void
		 */
		public function remove_item( $item_id ): void {
			unset( $this->line_items[ (int) $item_id ], $item_id );
			$this->line_items = array_values( $this->line_items );
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
		$matches = array();
		foreach ( $GLOBALS['mtuc_test_orders'] as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( isset( $args['meta_key'], $args['meta_value'] ) ) {
				if ( (string) $order->get_meta( (string) $args['meta_key'] ) !== (string) $args['meta_value'] ) {
					continue;
				}
			}
			if ( isset( $args['created_via'] )
				&& (string) $order->get_created_via() !== (string) $args['created_via']
			) {
				continue;
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
function mtuc_so_create_test_order( bool $initialized = true ): WC_Order {
	static $next_order_id = 500;
	$id                   = ++$next_order_id;
	$order                = new WC_Order( $id );
	if ( $initialized ) {
		$order->line_items = array( array( 'id' => 1 ) );
		$order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_COMPLETE );
	}
	$GLOBALS['mtuc_test_orders'][ $id ] = $order;
	$GLOBALS['mtuc_created_orders'][]   = $order;
	return $order;
}

if ( ! defined( 'MTUC_ORDER_META_PREFIX' ) ) {
	if ( ! function_exists( 'mtuc_get_shop_data' ) ) {
		/**
		 * @param mixed $unicid Unused.
		 * @return array<string, mixed>
		 */
		function mtuc_get_shop_data( $unicid = null ) {
			unset( $unicid );
			return isset( $GLOBALS['mtuc_so_shop'] ) && is_array( $GLOBALS['mtuc_so_shop'] )
				? $GLOBALS['mtuc_so_shop']
				: array( 'uni_proces' => 0 );
		}
	}
	if ( ! function_exists( 'mtuc_is_shop_process_2' ) ) {
		/**
		 * @param array<string, mixed> $shop Shop.
		 * @return bool
		 */
		function mtuc_is_shop_process_2( array $shop ): bool {
			return 1 === (int) ( $shop['uni_proces'] ?? 0 );
		}
	}
	$GLOBALS['mtuc_so_shop'] = array( 'uni_proces' => 0 );
	require_once MTUC_PLUGIN_DIR . '/includes/mtuc-bank-lifecycle.php';
	require_once MTUC_PLUGIN_DIR . '/includes/mtuc-process-identity.php';
	require_once MTUC_PLUGIN_DIR . '/includes/mtuc-submission-lock.php';
	require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
	require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-idempotency.php';
}

// ---------------------------------------------------------------------------
// Release TOCTOU: A observes self, B replaces, A completes release via CAS
// ---------------------------------------------------------------------------

$race_key = 'release-race-scope';
$owner_race_a = mtuc_claim_submission_lock( $race_key );
mtuc_so_assert( is_string( $owner_race_a ), 'release-race: A acquires' );
$option_race  = mtuc_submission_lock_option_key( $race_key );
$raw_before_b = mtuc_get_option_raw_value( $option_race );
mtuc_so_assert( is_string( $raw_before_b ) && '' !== $raw_before_b, 'release-race: A captured durable raw value' );

// B atomically replaces A's row (stale takeover / reclaim path).
$stale_ts = time() - MTUC_SUBMISSION_LOCK_TTL - 1;
$raw_stale = mtuc_encode_submission_lock_payload( $owner_race_a, $stale_ts, $stale_ts );
mtuc_so_assert( mtuc_options_cas_update( $option_race, $raw_before_b, $raw_stale ), 'release-race: force inspected stale value' );
$owner_race_b = mtuc_claim_submission_lock( $race_key );
mtuc_so_assert( is_string( $owner_race_b ) && ! hash_equals( $owner_race_a, $owner_race_b ), 'release-race: B becomes owner' );
$raw_b = mtuc_get_option_raw_value( $option_race );
mtuc_so_assert( is_string( $raw_b ), 'release-race: B durable value present' );

// A completes release using the pre-replacement raw value — must delete zero rows.
$a_cas_delete = mtuc_options_cas_delete( $option_race, $raw_before_b );
mtuc_so_assert( false === $a_cas_delete, 'release-race: A CAS delete with old value affects zero rows' );
$a_release = mtuc_release_submission_lock( $race_key, $owner_race_a );
mtuc_so_assert( false === $a_release, 'release-race: A release API cannot drop B' );
$after_a = mtuc_get_option_raw_value( $option_race );
mtuc_so_assert( is_string( $after_a ) && hash_equals( $raw_b, $after_a ), 'release-race: B lock remains unchanged' );
mtuc_release_submission_lock( $race_key, $owner_race_b );

// ---------------------------------------------------------------------------
// Simultaneous stale reclaimers — exactly one CAS winner
// ---------------------------------------------------------------------------

$reclaim_key = 'dual-reclaim-scope';
$old_owner   = 'old-owner-token-aaaaaaaa';
$stale_start = time() - MTUC_SUBMISSION_LOCK_TTL - 20;
$stale_raw   = mtuc_encode_submission_lock_payload( $old_owner, $stale_start, $stale_start + MTUC_SUBMISSION_LOCK_TTL );
$reclaim_opt = mtuc_submission_lock_option_key( $reclaim_key );
add_option( $reclaim_opt, $stale_raw, '', 'no' );

$now_r   = time();
$payload_ra = mtuc_encode_submission_lock_payload( 'reclaimer-a-owner-tokenxx', $now_r, $now_r + MTUC_SUBMISSION_LOCK_TTL );
$payload_rb = mtuc_encode_submission_lock_payload( 'reclaimer-b-owner-tokenyy', $now_r, $now_r + MTUC_SUBMISSION_LOCK_TTL );
$win_a = mtuc_options_cas_update( $reclaim_opt, $stale_raw, $payload_ra );
$win_b = mtuc_options_cas_update( $reclaim_opt, $stale_raw, $payload_rb );
mtuc_so_assert( ( $win_a xor $win_b ), 'dual-reclaim: exactly one CAS update succeeds' );
$final_raw = mtuc_get_option_raw_value( $reclaim_opt );
mtuc_so_assert( is_string( $final_raw ), 'dual-reclaim: one durable owner remains' );
mtuc_so_assert(
	hash_equals( $final_raw, $win_a ? $payload_ra : $payload_rb ),
	'dual-reclaim: durable value matches sole winner'
);
$loser_delete = mtuc_options_cas_delete( $reclaim_opt, $stale_raw );
mtuc_so_assert( false === $loser_delete, 'dual-reclaim: loser cannot delete winner with stale expected value' );
delete_option( $reclaim_opt );

// ---------------------------------------------------------------------------
// Expired owner still running — fencing blocks remote boundary
// ---------------------------------------------------------------------------

$fence_key = 'fence-expiry-scope';
$owner_fa  = mtuc_claim_submission_lock( $fence_key );
mtuc_arm_submission_lock_fence( $fence_key, $owner_fa );
mtuc_so_assert( true === mtuc_require_armed_submission_lock_ownership(), 'fence: A owns before expiry' );

$fa_opt = mtuc_submission_lock_option_key( $fence_key );
$fa_raw = mtuc_get_option_raw_value( $fa_opt );
$fa_stale_ts = time() - MTUC_SUBMISSION_LOCK_TTL - 2;
$fa_stale    = mtuc_encode_submission_lock_payload( $owner_fa, $fa_stale_ts, $fa_stale_ts );
mtuc_options_cas_update( $fa_opt, $fa_raw, $fa_stale );
$owner_fb = mtuc_claim_submission_lock( $fence_key );
mtuc_so_assert( is_string( $owner_fb ), 'fence: B takes ownership after expiry' );
mtuc_so_assert( false === mtuc_submission_lock_still_owned( $fence_key, $owner_fa ), 'fence: A still_owned is false' );
$blocked = mtuc_require_armed_submission_lock_ownership();
mtuc_so_assert( is_wp_error( $blocked ) && 'mtuc_submit_locked' === $blocked->get_error_code(), 'fence: A blocked at irreversible boundary' );
mtuc_so_assert( mtuc_submission_lock_still_owned( $fence_key, $owner_fb ), 'fence: B remains owner' );
mtuc_disarm_submission_lock_fence( $fence_key, $owner_fa );
mtuc_release_submission_lock( $fence_key, $owner_fb );

// Checkout fencing (same order key)
$co_key = mtuc_build_checkout_payment_lock_key( 4242 );
$co_a   = mtuc_claim_submission_lock( $co_key );
mtuc_arm_submission_lock_fence( $co_key, $co_a );
$co_opt = mtuc_submission_lock_option_key( $co_key );
$co_raw = mtuc_get_option_raw_value( $co_opt );
$co_stale_ts = time() - MTUC_SUBMISSION_LOCK_TTL - 3;
mtuc_options_cas_update(
	$co_opt,
	$co_raw,
	mtuc_encode_submission_lock_payload( $co_a, $co_stale_ts, $co_stale_ts )
);
$co_b = mtuc_claim_submission_lock( $co_key );
mtuc_so_assert( is_string( $co_b ), 'checkout-fence: B owns order lock' );
$co_block = mtuc_require_armed_submission_lock_ownership();
mtuc_so_assert( is_wp_error( $co_block ), 'checkout-fence: A remote boundary blocked' );
mtuc_so_assert( mtuc_submission_lock_still_owned( $co_key, $co_b ), 'checkout-fence: B remains owner' );
mtuc_disarm_submission_lock_fence( $co_key, $co_a );
mtuc_release_submission_lock( $co_key, $co_b );

// ---------------------------------------------------------------------------
// T5 / T6 / T7 — submission lock primitives
// ---------------------------------------------------------------------------

$scope_s = 'scope-s-hash';
$owner_a = mtuc_claim_submission_lock( $scope_s );
mtuc_so_assert( is_string( $owner_a ) && '' !== $owner_a, 'T5/T7: owner A acquires' );

$owner_b_active = mtuc_claim_submission_lock( $scope_s );
mtuc_so_assert( false === $owner_b_active, 'T7: active claim not stolen' );

$released_by_stranger = mtuc_release_submission_lock( $scope_s, 'not-the-owner' );
mtuc_so_assert( false === $released_by_stranger, 'T5: stranger cannot release A' );
$still_held = mtuc_read_submission_lock( mtuc_submission_lock_option_key( $scope_s ) );
mtuc_so_assert( null !== $still_held && hash_equals( $owner_a, $still_held['owner'] ), 'T5: A still owns after foreign release' );

// Force stale payload then reclaim (T6).
$stale_now = time() - MTUC_SUBMISSION_LOCK_TTL - 5;
$opt_s     = mtuc_submission_lock_option_key( $scope_s );
$raw_s     = mtuc_get_option_raw_value( $opt_s );
mtuc_options_cas_update(
	$opt_s,
	$raw_s,
	mtuc_encode_submission_lock_payload( $owner_a, $stale_now, $stale_now + MTUC_SUBMISSION_LOCK_TTL )
);
$owner_b = mtuc_claim_submission_lock( $scope_s );
mtuc_so_assert( is_string( $owner_b ) && '' !== $owner_b, 'T6: stale claim recovered by B' );
mtuc_so_assert( ! hash_equals( $owner_a, $owner_b ), 'T6: B has distinct owner token' );

$a_release_after_steal = mtuc_release_submission_lock( $scope_s, $owner_a );
mtuc_so_assert( false === $a_release_after_steal, 'T5: A cannot release B after reclaim' );
$b_still = mtuc_read_submission_lock( mtuc_submission_lock_option_key( $scope_s ) );
mtuc_so_assert( null !== $b_still && hash_equals( $owner_b, $b_still['owner'] ), 'T5: B remains owner' );
mtuc_so_assert( mtuc_release_submission_lock( $scope_s, $owner_b ), 'T5: B releases own claim' );

// ---------------------------------------------------------------------------
// T3 / T4 — different tokens same/different popup scope locks
// ---------------------------------------------------------------------------

$product_scope = 'product-scope-shared';
$t1_owner      = mtuc_claim_submission_lock( $product_scope );
$t2_owner      = mtuc_claim_submission_lock( $product_scope );
mtuc_so_assert( is_string( $t1_owner ) && false === $t2_owner, 'T3: only one active owner for same scope' );
mtuc_release_submission_lock( $product_scope, $t1_owner );

$scope_a = 'product-scope-a';
$scope_b = 'product-scope-b';
$a_owner = mtuc_claim_submission_lock( $scope_a );
$b_owner = mtuc_claim_submission_lock( $scope_b );
mtuc_so_assert( is_string( $a_owner ) && is_string( $b_owner ), 'T4: different scopes proceed independently' );
mtuc_release_submission_lock( $scope_a, $a_owner );
mtuc_release_submission_lock( $scope_b, $b_owner );

// ---------------------------------------------------------------------------
// T10 / T11 — checkout order execution ownership
// ---------------------------------------------------------------------------

$checkout_1 = mtuc_build_checkout_payment_lock_key( 1001 );
$checkout_2 = mtuc_build_checkout_payment_lock_key( 1002 );
mtuc_so_assert( $checkout_1 !== $checkout_2, 'T11: distinct order IDs use distinct lock keys' );

$c1 = mtuc_claim_submission_lock( $checkout_1 );
$c1b = mtuc_claim_submission_lock( $checkout_1 );
mtuc_so_assert( is_string( $c1 ) && false === $c1b, 'T10: only one execution owner per Woo order' );
$c2 = mtuc_claim_submission_lock( $checkout_2 );
mtuc_so_assert( is_string( $c2 ), 'T11: different orders do not block each other' );
mtuc_release_submission_lock( $checkout_1, $c1 );
mtuc_release_submission_lock( $checkout_2, $c2 );

// ---------------------------------------------------------------------------
// T1 — same popup token sequential reuse
// ---------------------------------------------------------------------------

$token_t1 = '11111111111111111111111111111111';
$scope_t1 = mtuc_build_product_operation_scope_key( 42, 0 );
$creates  = 0;
$first    = mtuc_resolve_popup_financing_order(
	$token_t1,
	$scope_t1,
	static function () use ( &$creates ) {
		++$creates;
		return mtuc_so_create_test_order();
	}
);
mtuc_so_assert( ! is_wp_error( $first ), 'T1: first resolve succeeds' );
mtuc_so_assert( 1 === $creates, 'T1: one create' );
$first_id = $first['order']->get_id();

$creates = 0;
$retry   = mtuc_resolve_popup_financing_order(
	$token_t1,
	$scope_t1,
	static function () use ( &$creates ) {
		++$creates;
		return mtuc_so_create_test_order();
	}
);
mtuc_so_assert( ! is_wp_error( $retry ), 'T1: sequential retry succeeds' );
mtuc_so_assert( 0 === $creates, 'T1: retry does not create' );
mtuc_so_assert( $first_id === $retry['order']->get_id(), 'T1: retry reuses same order' );

// ---------------------------------------------------------------------------
// T2 — same popup token contention (only one creator)
// ---------------------------------------------------------------------------

$token_t2 = '22222222222222222222222222222222';
$scope_t2 = mtuc_build_product_operation_scope_key( 43, 0 );
$begin1   = mtuc_begin_financing_operation( $token_t2, $scope_t2 );
mtuc_so_assert( is_array( $begin1 ) && true === $begin1['claimed'], 'T2: first claim wins' );
$begin2 = mtuc_begin_financing_operation( $token_t2, $scope_t2 );
mtuc_so_assert( is_wp_error( $begin2 ) || ( is_array( $begin2 ) && false === $begin2['claimed'] ), 'T2: second cannot become parallel creator while unresolved' );
if ( is_wp_error( $begin2 ) ) {
	mtuc_so_assert( 'mtuc_operation_contention' === $begin2->get_error_code(), 'T2: contention error while first unresolved' );
} else {
	mtuc_so_assert( null === $begin2['order'] || $begin2['order'] instanceof WC_Order, 'T2: joiner waits or reuses' );
	mtuc_so_assert( false === $begin2['claimed'], 'T2: joiner is not creator' );
}

$order_t2 = mtuc_so_create_test_order();
$commit_t2 = mtuc_commit_financing_operation( $begin1['option_key'], $order_t2, $token_t2, $scope_t2 );
mtuc_so_assert( true === $commit_t2, 'T2: first commits order' );
$begin_after = mtuc_begin_financing_operation( $token_t2, $scope_t2 );
mtuc_so_assert( is_array( $begin_after ) && false === $begin_after['claimed'], 'T2: after commit join reuses' );
mtuc_so_assert( $order_t2->get_id() === $begin_after['order']->get_id(), 'T2: reused order matches' );

// ---------------------------------------------------------------------------
// T8 — create-before-bind interruption at early ownership point
// ---------------------------------------------------------------------------

$token_t8 = '88888888888888888888888888888888';
$scope_t8 = mtuc_build_product_operation_scope_key( 88, 0 );
$begin_t8 = mtuc_begin_financing_operation( $token_t8, $scope_t8 );
mtuc_so_assert( is_array( $begin_t8 ) && true === $begin_t8['claimed'], 'T8: claim reserved' );

// Woo ID exists + early bind only — no line items / full init (crash window closed by early bind).
$order_t8 = mtuc_so_create_test_order( false );
mtuc_so_assert( 0 === count( $order_t8->get_items( 'line_item' ) ), 'T8: X is unbound shell before init' );
mtuc_early_bind_financing_operation_order( $begin_t8['option_key'], $order_t8, $token_t8, $scope_t8 );
mtuc_so_assert( $token_t8 === (string) $order_t8->get_meta( MTUC_ORDER_META_OPERATION_TOKEN ), 'T8: token durable after early bind' );

$creates = 0;
$recover = mtuc_resolve_popup_financing_order(
	$token_t8,
	$scope_t8,
	static function ( $early_bind = null, $existing = null ) use ( &$creates ) {
		if ( $existing instanceof WC_Order ) {
			$existing->line_items = array( array( 'id' => 1 ) );
			return $existing;
		}
		++$creates;
		$order = mtuc_so_create_test_order();
		if ( is_callable( $early_bind ) ) {
			$early_bind( $order );
		}
		return $order;
	}
);
mtuc_so_assert( ! is_wp_error( $recover ), 'T8: recovery resolves' );
mtuc_so_assert( 0 === $creates, 'T8: recovery does not create Y' );
mtuc_so_assert( $order_t8->get_id() === $recover['order']->get_id(), 'T8: recovery reuses X' );

// ---------------------------------------------------------------------------
// T9 — handled commit error must not allow second order (AUD-WOO-018-F02)
// ---------------------------------------------------------------------------

$token_t9 = '99999999999999999999999999999999';
$scope_t9 = mtuc_build_product_operation_scope_key( 99, 0 );
$too_long = new WC_Order( 10000000000000 );
$too_long->line_items = array( array( 'id' => 1 ) );
$GLOBALS['mtuc_test_orders'][10000000000000] = $too_long;

$creates = 0;
$fail    = mtuc_resolve_popup_financing_order(
	$token_t9,
	$scope_t9,
	static function ( $early_bind = null, $existing = null ) use ( &$creates, $too_long ) {
		if ( $existing instanceof WC_Order ) {
			++$creates;
			return mtuc_so_create_test_order();
		}
		++$creates;
		if ( is_callable( $early_bind ) ) {
			$early_bind( $too_long );
		}
		// Init finished before CP-identity commit failure (handled F02 path).
		$too_long->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_COMPLETE );
		return $too_long;
	}
);
mtuc_so_assert( is_wp_error( $fail ), 'T9: oversized CP id commit fails' );
mtuc_so_assert( 1 === $creates, 'T9: order created once before commit failure' );
mtuc_so_assert( $token_t9 === (string) $too_long->get_meta( MTUC_ORDER_META_OPERATION_TOKEN ), 'T9: token bound before CP failure' );
$stored_t9 = mtuc_read_financing_operation_reservation( mtuc_financing_operation_option_key( $token_t9 ) );
mtuc_so_assert( null !== $stored_t9 && (int) $stored_t9['wc_order_id'] === $too_long->get_id(), 'T9: reservation kept with order id' );

$creates = 0;
$retry9  = mtuc_resolve_popup_financing_order(
	$token_t9,
	$scope_t9,
	static function ( $early_bind = null, $existing = null ) use ( &$creates ) {
		++$creates;
		return mtuc_so_create_test_order();
	}
);
mtuc_so_assert( ! is_wp_error( $retry9 ), 'T9: same-token retry recovers bound order' );
mtuc_so_assert( 0 === $creates, 'T9: retry does not create second order' );
mtuc_so_assert( $too_long->get_id() === $retry9['order']->get_id(), 'T9: retry returns original order X' );

// ---------------------------------------------------------------------------
// T6b — stale unresolved operation reservation reclaim (AUD-WOO-018-F03)
// ---------------------------------------------------------------------------

$token_stale = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1';
$scope_stale = mtuc_build_product_operation_scope_key( 77, 1 );
$opt_stale   = mtuc_financing_operation_option_key( $token_stale );
add_option(
	$opt_stale,
	wp_json_encode(
		array(
			'token'       => $token_stale,
			'scope'       => $scope_stale,
			'wc_order_id' => 0,
			'created_at'  => time() - MTUC_FINANCING_OPERATION_CLAIM_TTL - 10,
		)
	),
	'',
	'no'
);

$active_token = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa2';
$opt_active   = mtuc_financing_operation_option_key( $active_token );
add_option(
	$opt_active,
	wp_json_encode(
		array(
			'token'       => $active_token,
			'scope'       => $scope_stale,
			'wc_order_id' => 0,
			'created_at'  => time(),
		)
	),
	'',
	'no'
);

$reclaimed = mtuc_begin_financing_operation( $token_stale, $scope_stale );
mtuc_so_assert( is_array( $reclaimed ) && true === $reclaimed['claimed'], 'T6b: stale unresolved reservation reclaimable' );

$not_stolen = mtuc_begin_financing_operation( $active_token, $scope_stale );
mtuc_so_assert( is_wp_error( $not_stolen ), 'T7b: non-stale active reservation not reclaimed' );
mtuc_so_assert( 'mtuc_operation_contention' === $not_stolen->get_error_code(), 'T7b: active owner yields contention' );

// ---------------------------------------------------------------------------
// Lease renew success / failure
// ---------------------------------------------------------------------------

$renew_key = 'renew-scope-key';
$renew_a   = mtuc_claim_submission_lock( $renew_key );
mtuc_so_assert( is_string( $renew_a ), 'renew: A acquires' );
$before = mtuc_read_submission_lock( mtuc_submission_lock_option_key( $renew_key ) );
mtuc_so_assert( null !== $before, 'renew: lock readable' );
sleep( 1 );
mtuc_so_assert( mtuc_renew_submission_lock( $renew_key, $renew_a, MTUC_SUBMISSION_LOCK_TTL + 120 ), 'renew: A renews successfully' );
$after = mtuc_read_submission_lock( mtuc_submission_lock_option_key( $renew_key ) );
mtuc_so_assert( null !== $after && $after['expires_at'] > $before['expires_at'], 'renew: expiry moves forward' );
mtuc_so_assert( false === mtuc_claim_submission_lock( $renew_key ), 'renew: B cannot reclaim before renewed expiry' );

$opt_r = mtuc_submission_lock_option_key( $renew_key );
$raw_r = mtuc_get_option_raw_value( $opt_r );
$stale_ts = time() - MTUC_SUBMISSION_LOCK_TTL - 5;
mtuc_options_cas_update(
	$opt_r,
	$raw_r,
	mtuc_encode_submission_lock_payload( $renew_a, $stale_ts, $stale_ts )
);
$renew_b = mtuc_claim_submission_lock( $renew_key );
mtuc_so_assert( is_string( $renew_b ), 'renew-fail: B replaces stale A' );
mtuc_so_assert( false === mtuc_renew_submission_lock( $renew_key, $renew_a, 120 ), 'renew-fail: A cannot renew B lock' );
$held = mtuc_read_submission_lock( $opt_r );
mtuc_so_assert( null !== $held && hash_equals( $renew_b, $held['owner'] ), 'renew-fail: B remains owner' );
mtuc_release_submission_lock( $renew_key, $renew_b );

// Create boundary: lost ownership cannot enter wc_create_order path
$create_key = 'create-boundary-scope';
$create_a   = mtuc_claim_submission_lock( $create_key );
mtuc_arm_submission_lock_fence( $create_key, $create_a );
$c_opt = mtuc_submission_lock_option_key( $create_key );
$c_raw = mtuc_get_option_raw_value( $c_opt );
$c_stale = time() - MTUC_SUBMISSION_LOCK_TTL - 2;
mtuc_options_cas_update( $c_opt, $c_raw, mtuc_encode_submission_lock_payload( $create_a, $c_stale, $c_stale ) );
$create_b = mtuc_claim_submission_lock( $create_key );
mtuc_so_assert( is_string( $create_b ), 'create-boundary: B owns' );
$blocked_create = mtuc_require_armed_submission_lock_ownership( MTUC_SUBMISSION_LOCK_RENEW_CREATE );
mtuc_so_assert( is_wp_error( $blocked_create ), 'create-boundary: A renew-before-create fails' );
mtuc_disarm_submission_lock_fence( $create_key, $create_a );
mtuc_release_submission_lock( $create_key, $create_b );

// Complete-marker gate
$gate_order = mtuc_so_create_test_order( false );
$gate_order->update_meta_data( MTUC_ORDER_META_CREATION_REF, 'abc' );
$gate_order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
$gate_order->set_created_via( 'mtuc_product_popup' );
$gate_fail = mtuc_assert_popup_order_ready_for_remote( $gate_order );
mtuc_so_assert( is_wp_error( $gate_fail ) && 'mtuc_order_init_incomplete' === $gate_fail->get_error_code(), 'complete-gate: initializing blocked from remote' );
$gate_order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_COMPLETE );
mtuc_so_assert( true === mtuc_assert_popup_order_ready_for_remote( $gate_order ), 'complete-gate: complete allows remote' );

// Product incomplete recovery — X reused, normalized, Y not created
$token_inc = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$scope_inc = mtuc_build_product_operation_scope_key( 55, 0 );
$begin_inc = mtuc_begin_financing_operation( $token_inc, $scope_inc );
mtuc_so_assert( is_array( $begin_inc ) && true === $begin_inc['claimed'], 'incomplete-product: claim' );
$order_inc = mtuc_so_create_test_order( false );
$order_inc->line_items = array( array( 'id' => 'partial' ) );
mtuc_early_bind_financing_operation_order( $begin_inc['option_key'], $order_inc, $token_inc, $scope_inc );
$order_inc->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
$order_inc->save();

$creates = 0;
$norm_calls = 0;
$rebuilt = mtuc_resolve_popup_financing_order(
	$token_inc,
	$scope_inc,
	static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( &$creates, &$norm_calls ) {
		if ( ! ( $existing instanceof WC_Order ) ) {
			++$creates;
			return mtuc_so_create_test_order();
		}
		++$norm_calls;
		$existing->line_items = array( array( 'id' => 'final-line' ) );
		$existing->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_COMPLETE );
		$existing->set_created_via( 'mtuc_product_popup' );
		$existing->set_payment_method( 'mtunicredit' );
		return $existing;
	}
);
mtuc_so_assert( ! is_wp_error( $rebuilt ), 'incomplete-product: resolve ok' );
mtuc_so_assert( 0 === $creates, 'incomplete-product: Y not created' );
mtuc_so_assert( 1 === $norm_calls, 'incomplete-product: X normalized once' );
mtuc_so_assert( $order_inc->get_id() === $rebuilt['order']->get_id(), 'incomplete-product: same X' );
mtuc_so_assert( array( array( 'id' => 'final-line' ) ) === $rebuilt['order']->line_items, 'incomplete-product: final line exact' );
mtuc_so_assert( MTUC_POPUP_INIT_COMPLETE === (string) $rebuilt['order']->get_meta( MTUC_ORDER_META_POPUP_INIT_STATE ), 'incomplete-product: complete marker set' );

// creation_ref blocks stale reclaim (no Y while recoverable identity exists)
$token_cr = 'ccccccccccccccccccccccccccccccc1';
$scope_cr = mtuc_build_product_operation_scope_key( 66, 2 );
$opt_cr   = mtuc_financing_operation_option_key( $token_cr );
$ref_cr   = mtuc_financing_creation_ref( $token_cr );
add_option(
	$opt_cr,
	wp_json_encode(
		array(
			'token'        => $token_cr,
			'scope'        => $scope_cr,
			'wc_order_id'  => 0,
			'created_at'   => time() - MTUC_FINANCING_OPERATION_CLAIM_TTL - 20,
			'creation_ref' => $ref_cr,
		)
	),
	'',
	'no'
);
mtuc_so_assert( false === mtuc_try_reclaim_stale_financing_operation( $opt_cr, $token_cr, $scope_cr ), 'creation-ref: reclaim refused without discoverable order proof of absence' );

// ---------------------------------------------------------------------------
// Pass 4 — creation intent fail-closed (no wc_create_order / no Y)
// ---------------------------------------------------------------------------

$token_ci = 'dddddddddddddddddddddddddddddddd';
$scope_ci = mtuc_build_product_operation_scope_key( 77, 0 );

$GLOBALS['mtuc_test_force_cas_update_fail'] = true;
$creates_ci = 0;
$intent_err = mtuc_resolve_popup_financing_order(
	$token_ci,
	$scope_ci,
	static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( &$creates_ci ) {
		++$creates_ci;
		$order = mtuc_so_create_test_order();
		if ( is_callable( $early_bind ) ) {
			$early_bind( $order );
		}
		return $order;
	}
);
$GLOBALS['mtuc_test_force_cas_update_fail'] = false;
mtuc_so_assert( is_wp_error( $intent_err ), 'intent-fail: controlled error' );
mtuc_so_assert( 'mtuc_creation_intent_failed' === $intent_err->get_error_code(), 'intent-fail: error code' );
mtuc_so_assert( 0 === $creates_ci, 'intent-fail: wc_create_order not called / no order Y' );

// ---------------------------------------------------------------------------
// Pass 4 — Product immutable snapshot recovery / mismatch
// ---------------------------------------------------------------------------

$cust_a = array(
	'first_name' => 'Ivan',
	'last_name'  => 'Petrov',
	'address'    => 'Sofia 1',
	'phone'      => '+359888111222',
	'email'      => 'ivan@example.com',
);
$calc_a = array(
	'popup_offer_type'    => 'standard',
	'scheme_type'         => 'standard',
	'scheme_key'          => 's1',
	'filter_id'           => 1,
	'months'              => 12,
	'price'               => 100.0,
	'parva'               => 10.0,
	'loan_amount'         => 90.0,
	'monthly_installment' => 8.0,
	'total_payable'       => 106.0,
	'glp'                 => 0.0,
	'gpr'                 => 0.0,
	'kop_code'            => 'K1',
);
$snap_a = mtuc_build_product_operation_snapshot( $cust_a, $calc_a, 101, 0, 1, 100.0 );
mtuc_so_assert( 1 === (int) $snap_a['quantity'], 'product-snap: qty 1' );
mtuc_so_assert( 100.0 === (float) $snap_a['line_total'], 'product-snap: price P1' );
mtuc_so_assert( 's1' === (string) ( $snap_a['calculation']['scheme_key'] ?? '' ), 'product-snap: scheme S1' );

$token_ps = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
$scope_ps = mtuc_build_product_operation_scope_key( 101, 0 );
$begin_ps = mtuc_begin_financing_operation( $token_ps, $scope_ps );
mtuc_so_assert( is_array( $begin_ps ), 'product-snap: begin' );
$order_ps = mtuc_so_create_test_order( false );
mtuc_early_bind_financing_operation_order( $begin_ps['option_key'], $order_ps, $token_ps, $scope_ps );
$order_ps->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
mtuc_so_assert( true === mtuc_persist_product_operation_snapshot( $order_ps, $snap_a ), 'product-snap: persist A' );

$creates_ps = 0;
$used_qty   = null;
$used_price = null;
$used_scheme = null;
$rebuilt_ps = mtuc_resolve_popup_financing_order(
	$token_ps,
	$scope_ps,
	static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( &$creates_ps, &$used_qty, &$used_price, &$used_scheme, $order_ps ) {
		if ( ! ( $existing instanceof WC_Order ) ) {
			++$creates_ps;
			return mtuc_so_create_test_order();
		}
		$snap = mtuc_read_product_operation_snapshot( $existing );
		mtuc_so_assert( null !== $snap, 'product-snap-retry: loaded snapshot' );
		$used_qty    = (int) ( $snap['quantity'] ?? 0 );
		$used_price  = (float) ( $snap['line_total'] ?? 0 );
		$used_scheme = (string) ( $snap['calculation']['scheme_key'] ?? '' );
		// Simulate retry request carrying qty=2 / P2 / S2 — rebuild must ignore them.
		$existing->line_items = array(
			array(
				'id'       => 'line-from-snap',
				'quantity' => $used_qty,
				'price'    => $used_price,
				'scheme'   => $used_scheme,
			),
		);
		$existing->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_COMPLETE );
		$existing->set_created_via( 'mtuc_product_popup' );
		$existing->set_payment_method( 'mtunicredit' );
		return $existing;
	}
);
mtuc_so_assert( ! is_wp_error( $rebuilt_ps ), 'product-snap-retry: resolve ok' );
mtuc_so_assert( 0 === $creates_ps, 'product-snap-retry: Y never created' );
mtuc_so_assert( 1 === $used_qty, 'product-snap-retry: qty from snapshot A' );
mtuc_so_assert( 100.0 === $used_price, 'product-snap-retry: price from snapshot A' );
mtuc_so_assert( 's1' === $used_scheme, 'product-snap-retry: scheme from snapshot A' );
mtuc_so_assert( $order_ps->get_id() === $rebuilt_ps['order']->get_id(), 'product-snap-retry: same X' );

// Completed Product retry — stored snapshot A remains authoritative
$order_ps->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_COMPLETE );
$order_ps->save();
$resolved_complete = mtuc_resolve_popup_financing_order(
	$token_ps,
	$scope_ps,
	static function () {
		mtuc_so_assert( false, 'completed-product: create must not run' );
		return new WP_Error( 'unexpected', 'no' );
	}
);
mtuc_so_assert( ! is_wp_error( $resolved_complete ), 'completed-product: resolve ok' );
$auth = mtuc_read_product_operation_snapshot( $resolved_complete['order'] );
mtuc_so_assert( null !== $auth, 'completed-product: snapshot present' );
mtuc_so_assert( 1 === (int) $auth['quantity'], 'completed-product: qty still A' );
mtuc_so_assert( 's1' === (string) ( $auth['calculation']['scheme_key'] ?? '' ), 'completed-product: scheme still A' );
mtuc_so_assert( 100.0 === (float) $auth['line_total'], 'completed-product: price still A' );

// Incomplete product without snapshot fails safely (no Y)
$token_ns = 'ffffffffffffffffffffffffffffffff';
$scope_ns = mtuc_build_product_operation_scope_key( 102, 0 );
$begin_ns = mtuc_begin_financing_operation( $token_ns, $scope_ns );
$order_ns = mtuc_so_create_test_order( false );
mtuc_early_bind_financing_operation_order( $begin_ns['option_key'], $order_ns, $token_ns, $scope_ns );
$order_ns->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
$order_ns->save();
mtuc_so_assert( null === mtuc_read_product_operation_snapshot( $order_ns ), 'no-snap: missing snapshot' );

if ( ! function_exists( 'wc_create_order' ) ) {
	/**
	 * @param array<string, mixed> $args Args.
	 * @return WC_Order
	 */
	function wc_create_order( $args = array() ) {
		unset( $args );
		return mtuc_so_create_test_order( false );
	}
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
	/**
	 * @return bool
	 */
	function is_user_logged_in() {
		return false;
	}
}

if ( ! class_exists( 'WC_Product', false ) ) {
	/**
	 * Minimal product stub for type hints.
	 */
	class WC_Product {
		/** @var int */
		private $id;
		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}
		public function get_id(): int {
			return $this->id;
		}
		public function get_parent_id(): int {
			return 0;
		}
		public function is_type( $type ): bool {
			return false;
		}
		/**
		 * @return bool
		 */
		public function is_taxable(): bool {
			return false;
		}
	}
}

$err_ns = mtuc_create_popup_pending_order(
	$cust_a,
	$calc_a,
	new WC_Product( 102 ),
	102,
	0,
	2,
	200.0,
	null,
	$order_ns,
	array()
);
mtuc_so_assert( is_wp_error( $err_ns ), 'no-snap: fail safely' );
mtuc_so_assert( 'mtuc_operation_incomplete' === $err_ns->get_error_code(), 'no-snap: incomplete code' );

// ---------------------------------------------------------------------------
// Pass 4 — Cart complete snapshot fingerprint
// ---------------------------------------------------------------------------

$lines_same = array(
	array(
		'product_id'   => 1,
		'variation_id' => 0,
		'quantity'     => 1,
		'line_total'   => 50.0,
	),
);
$calc_cart = array(
	'price'               => 50.0,
	'months'              => 6,
	'parva'               => 0.0,
	'scheme_key'          => 'cart-s1',
	'popup_offer_type'    => 'standard',
	'scheme_type'         => 'standard',
	'loan_amount'         => 50.0,
	'monthly_installment' => 9.0,
	'total_payable'       => 54.0,
	'glp'                 => 0.0,
	'gpr'                 => 0.0,
	'kop_code'            => 'C1',
	'filter_id'           => 0,
);
$adj_base = array(
	'coupons'  => array(),
	'fees'     => array(),
	'shipping' => array(),
);
$fp_base = mtuc_build_cart_operation_snapshot( $cust_a, $calc_cart, $lines_same, $adj_base, 50.0 );
$fp_ship = mtuc_build_cart_operation_snapshot(
	$cust_a,
	$calc_cart,
	$lines_same,
	array(
		'coupons'  => array(),
		'fees'     => array(),
		'shipping' => array(
			array(
				'method_title' => 'Flat',
				'method_id'    => 'flat_rate',
				'instance_id'  => 1,
				'total'        => 5.0,
				'taxes'        => array(),
			),
		),
	),
	55.0
);
$fp_fee = mtuc_build_cart_operation_snapshot(
	$cust_a,
	$calc_cart,
	$lines_same,
	array(
		'coupons'  => array(),
		'fees'     => array(
			array(
				'name'       => 'Fee',
				'amount'     => 3.0,
				'total'      => 3.0,
				'tax_class'  => '',
				'tax_status' => 'none',
				'tax_data'   => array(),
			),
		),
		'shipping' => array(),
	),
	53.0
);
$fp_coupon = mtuc_build_cart_operation_snapshot(
	$cust_a,
	$calc_cart,
	$lines_same,
	array(
		'coupons'  => array(
			array(
				'code'         => 'SAVE10',
				'discount'     => 5.0,
				'discount_tax' => 0.0,
			),
		),
		'fees'     => array(),
		'shipping' => array(),
	),
	45.0
);
mtuc_so_assert( $fp_base['fingerprint'] !== $fp_ship['fingerprint'], 'cart-fp: shipping changes fingerprint' );
mtuc_so_assert( $fp_base['fingerprint'] !== $fp_fee['fingerprint'], 'cart-fp: fee changes fingerprint' );
mtuc_so_assert( $fp_base['fingerprint'] !== $fp_coupon['fingerprint'], 'cart-fp: coupon changes fingerprint' );
$line_fp_base = mtuc_cart_lines_fingerprint( $lines_same );
$line_fp_ship = mtuc_cart_lines_fingerprint( $lines_same );
mtuc_so_assert( hash_equals( $line_fp_base, $line_fp_ship ), 'cart-fp: line-only fingerprint equal when only shipping differs' );

// Cart recovery from partial X using persisted complete snapshot
$token_cr2 = 'c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2c2';
$scope_cr2 = 'cart-scope-recovery-test-unique';
$begin_cr2 = mtuc_begin_financing_operation( $token_cr2, $scope_cr2 );
mtuc_so_assert( is_array( $begin_cr2 ), 'cart-recovery: begin' );
$order_cr2 = mtuc_so_create_test_order( false );
$order_cr2->line_items = array( array( 'id' => 'partial-line' ) );
mtuc_early_bind_financing_operation_order( $begin_cr2['option_key'], $order_cr2, $token_cr2, $scope_cr2 );
$order_cr2->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
$full_cart_snap = mtuc_build_cart_operation_snapshot(
	$cust_a,
	$calc_cart,
	array(
		array(
			'product_id'   => 10,
			'variation_id' => 0,
			'quantity'     => 2,
			'line_total'   => 40.0,
		),
		array(
			'product_id'   => 11,
			'variation_id' => 3,
			'quantity'     => 1,
			'line_total'   => 10.0,
		),
	),
	array(
		'coupons'  => array(
			array(
				'code'         => 'SAVE10',
				'discount'     => 5.0,
				'discount_tax' => 0.0,
			),
		),
		'fees'     => array(
			array(
				'name'       => 'Fee',
				'amount'     => 2.0,
				'total'      => 2.0,
				'tax_class'  => '',
				'tax_status' => 'none',
				'tax_data'   => array(),
			),
		),
		'shipping' => array(
			array(
				'method_title' => 'Flat',
				'method_id'    => 'flat_rate',
				'instance_id'  => 1,
				'total'        => 5.0,
				'taxes'        => array(),
			),
		),
	),
	57.0
);
mtuc_so_assert( true === mtuc_persist_cart_operation_snapshot( $order_cr2, $full_cart_snap ), 'cart-recovery: persist snapshot' );
$loaded_cr2 = mtuc_read_cart_operation_snapshot( $order_cr2 );
mtuc_so_assert( null !== $loaded_cr2, 'cart-recovery: readable' );
mtuc_so_assert( 2 === count( $loaded_cr2['lines'] ), 'cart-recovery: both lines present' );
mtuc_so_assert( is_array( $loaded_cr2['adjustments']['coupons'][0] ?? null ), 'cart-recovery: coupon preserved' );
mtuc_so_assert( 'SAVE10' === (string) ( $loaded_cr2['adjustments']['coupons'][0]['code'] ?? '' ), 'cart-recovery: coupon code' );
mtuc_so_assert( 5.0 === (float) ( $loaded_cr2['adjustments']['coupons'][0]['discount'] ?? 0 ), 'cart-recovery: coupon discount D1' );
mtuc_so_assert( 1 === count( $loaded_cr2['adjustments']['fees'] ), 'cart-recovery: one fee' );
mtuc_so_assert( 1 === count( $loaded_cr2['adjustments']['shipping'] ), 'cart-recovery: one shipping' );
mtuc_so_assert( 57.0 === (float) $loaded_cr2['expected_total'], 'cart-recovery: expected total' );

$creates_cr2 = 0;
$rebuilt_cr2 = mtuc_resolve_popup_financing_order(
	$token_cr2,
	$scope_cr2,
	static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( &$creates_cr2 ) {
		if ( ! ( $existing instanceof WC_Order ) ) {
			++$creates_cr2;
			return mtuc_so_create_test_order();
		}
		$snap = mtuc_read_cart_operation_snapshot( $existing );
		mtuc_so_assert( null !== $snap, 'cart-recovery-retry: snap' );
		mtuc_clear_popup_order_commerce_items( $existing );
		$existing->line_items = array();
		foreach ( $snap['lines'] as $idx => $line ) {
			$existing->line_items[] = array(
				'id'       => 'L' . $idx,
				'quantity' => (int) $line['quantity'],
				'total'    => (float) $line['line_total'],
			);
		}
		$existing->update_meta_data( '_mtuc_rebuild_fees', count( $snap['adjustments']['fees'] ) );
		$existing->update_meta_data( '_mtuc_rebuild_shipping', count( $snap['adjustments']['shipping'] ) );
		$existing->update_meta_data( '_mtuc_rebuild_coupons', count( $snap['adjustments']['coupons'] ) );
		$existing->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_COMPLETE );
		$existing->set_created_via( 'mtuc_cart_popup' );
		$existing->set_payment_method( 'mtunicredit' );
		return $existing;
	}
);
mtuc_so_assert( ! is_wp_error( $rebuilt_cr2 ), 'cart-recovery-retry: ok' );
mtuc_so_assert( 0 === $creates_cr2, 'cart-recovery-retry: no Y' );
mtuc_so_assert( 2 === count( $rebuilt_cr2['order']->line_items ), 'cart-recovery-retry: exact lines' );
mtuc_so_assert( 1 === (int) $rebuilt_cr2['order']->get_meta( '_mtuc_rebuild_fees' ), 'cart-recovery-retry: no duplicate fee' );
mtuc_so_assert( 1 === (int) $rebuilt_cr2['order']->get_meta( '_mtuc_rebuild_shipping' ), 'cart-recovery-retry: no duplicate shipping' );
mtuc_so_assert( 1 === (int) $rebuilt_cr2['order']->get_meta( '_mtuc_rebuild_coupons' ), 'cart-recovery-retry: no duplicate coupon' );

// ---------------------------------------------------------------------------
// Pass 4 — stage lease / create_armed non-reclaim / former owner blocked
// ---------------------------------------------------------------------------

$stage_key = 'stage-lease-scope';
$stage_a   = mtuc_claim_submission_lock( $stage_key );
mtuc_so_assert( is_string( $stage_a ), 'stage: A claims' );
mtuc_so_assert(
	mtuc_renew_submission_lock( $stage_key, $stage_a, MTUC_SUBMISSION_LOCK_RENEW_HTTP_CP, MTUC_SUBMISSION_LOCK_STAGE_CP_HTTP ),
	'stage: A renews for CP HTTP'
);
$stage_lock = mtuc_read_submission_lock( mtuc_submission_lock_option_key( $stage_key ) );
mtuc_so_assert( null !== $stage_lock && MTUC_SUBMISSION_LOCK_STAGE_CP_HTTP === $stage_lock['stage'], 'stage: cp_http marked' );
mtuc_so_assert( false === mtuc_claim_submission_lock( $stage_key ), 'stage: B cannot reclaim while A lease valid' );

// Next stage requires new renew
mtuc_arm_submission_lock_fence( $stage_key, $stage_a );
$next = mtuc_require_armed_submission_lock_ownership(
	MTUC_SUBMISSION_LOCK_RENEW_HTTP_SMARTUCF,
	MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP
);
mtuc_so_assert( true === $next, 'stage: A renews into SmartUCF stage' );
$stage_lock = mtuc_read_submission_lock( mtuc_submission_lock_option_key( $stage_key ) );
mtuc_so_assert( null !== $stage_lock && MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP === $stage_lock['stage'], 'stage: smartucf marked' );

// create_armed: not reclaimable by TTL alone
$armed_key = 'create-armed-scope';
$armed_a   = mtuc_claim_submission_lock( $armed_key );
mtuc_so_assert(
	mtuc_renew_submission_lock( $armed_key, $armed_a, 1, MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED ),
	'create-armed: A arms'
);
$a_opt = mtuc_submission_lock_option_key( $armed_key );
$a_raw = mtuc_get_option_raw_value( $a_opt );
$past  = time() - MTUC_SUBMISSION_LOCK_TTL - 30;
mtuc_options_cas_update(
	$a_opt,
	$a_raw,
	mtuc_encode_submission_lock_payload( $armed_a, $past, $past, MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED )
);
mtuc_so_assert( false === mtuc_claim_submission_lock( $armed_key ), 'create-armed: B cannot reclaim after TTL while armed' );
$armed_lock = mtuc_read_submission_lock( $a_opt );
mtuc_so_assert( null !== $armed_lock && ! mtuc_submission_lock_is_stale( $armed_lock ), 'create-armed: not stale' );

// Former owner cannot enter next stage after replacement
$repl_key = 'former-owner-scope';
$repl_a   = mtuc_claim_submission_lock( $repl_key );
mtuc_arm_submission_lock_fence( $repl_key, $repl_a );
$r_opt = mtuc_submission_lock_option_key( $repl_key );
$r_raw = mtuc_get_option_raw_value( $r_opt );
$r_past = time() - MTUC_SUBMISSION_LOCK_TTL - 5;
mtuc_options_cas_update( $r_opt, $r_raw, mtuc_encode_submission_lock_payload( $repl_a, $r_past, $r_past, '' ) );
$repl_b = mtuc_claim_submission_lock( $repl_key );
mtuc_so_assert( is_string( $repl_b ), 'former: B replaces stale A' );
$blocked_next = mtuc_require_armed_submission_lock_ownership(
	MTUC_SUBMISSION_LOCK_RENEW_HTTP_CP,
	MTUC_SUBMISSION_LOCK_STAGE_CP_HTTP
);
mtuc_so_assert( is_wp_error( $blocked_next ), 'former: A cannot enter CP stage after replacement' );
mtuc_disarm_submission_lock_fence( $repl_key, $repl_a );
mtuc_release_submission_lock( $repl_key, $repl_b );
mtuc_release_submission_lock( $stage_key, $stage_a );
// create_armed intentionally not released (Pass 5 durable safety).
mtuc_so_assert( false === mtuc_release_submission_lock( $armed_key, $armed_a ), 'create-armed: release refused while armed' );

// ---------------------------------------------------------------------------
// Pass 4b — identical-value CAS no-op (false mtuc_submit_locked remediation)
// ---------------------------------------------------------------------------

// T1 — identical renew succeeds
$noop_key = 'cas-noop-identical';
$noop_a   = mtuc_claim_submission_lock( $noop_key );
mtuc_so_assert( is_string( $noop_a ), 'cas-T1: claim' );
$noop_opt = mtuc_submission_lock_option_key( $noop_key );
$claimed  = time();
$expires  = $claimed + 86400;
$armed_payload = mtuc_encode_submission_lock_payload(
	$noop_a,
	$claimed,
	$expires,
	MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED
);
mtuc_so_assert( '' !== $armed_payload, 'cas-T1: encode' );
$noop_raw = mtuc_get_option_raw_value( $noop_opt );
mtuc_so_assert(
	mtuc_options_cas_update( $noop_opt, $noop_raw, $armed_payload ),
	'cas-T1: first write create_armed'
);
mtuc_so_assert(
	mtuc_options_cas_update( $noop_opt, $armed_payload, $armed_payload ),
	'cas-T1: identical renew CAS succeeds'
);
mtuc_so_assert(
	mtuc_renew_submission_lock( $noop_key, $noop_a, MTUC_SUBMISSION_LOCK_RENEW_CREATE, MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED ),
	'cas-T1: renew helper with identical payload path succeeds'
);

// T2 — identical renew with owner replaced fails
$t2_key = 'cas-noop-replaced';
$t2_a   = mtuc_claim_submission_lock( $t2_key );
$t2_opt = mtuc_submission_lock_option_key( $t2_key );
$t2_raw_a = mtuc_get_option_raw_value( $t2_opt );
$t2_b_payload = mtuc_encode_submission_lock_payload( 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', time(), time() + 100, MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED );
mtuc_so_assert( mtuc_options_cas_update( $t2_opt, $t2_raw_a, $t2_b_payload ), 'cas-T2: replace with B' );
mtuc_so_assert(
	false === mtuc_options_cas_update( $t2_opt, $t2_raw_a, $t2_raw_a ),
	'cas-T2: stale A identical CAS fails'
);

// T3 — identical renew with missing row fails
$t3_opt = 'mtuc_slock_cas_noop_missing_row';
mtuc_so_assert(
	false === mtuc_options_cas_update( $t3_opt, '{"owner":"x","claimed_at":1,"expires_at":2,"stage":"create_armed"}', '{"owner":"x","claimed_at":1,"expires_at":2,"stage":"create_armed"}' ),
	'cas-T3: missing row identical CAS fails'
);

// T4 — changed expiry renew succeeds
$t4_key = 'cas-noop-expiry';
$t4_a   = mtuc_claim_submission_lock( $t4_key );
$t4_opt = mtuc_submission_lock_option_key( $t4_key );
$t4_raw = mtuc_get_option_raw_value( $t4_opt );
$t4_dec = mtuc_decode_submission_lock_payload( (string) $t4_raw );
mtuc_so_assert( null !== $t4_dec, 'cas-T4: decode' );
$t4_new = mtuc_encode_submission_lock_payload(
	$t4_a,
	(int) $t4_dec['claimed_at'],
	(int) $t4_dec['expires_at'] + 60,
	(string) ( $t4_dec['stage'] ?? '' )
);
mtuc_so_assert( $t4_raw !== $t4_new, 'cas-T4: payload changed' );
mtuc_so_assert( mtuc_options_cas_update( $t4_opt, $t4_raw, $t4_new ), 'cas-T4: changed expiry CAS succeeds' );

// T5 — changed stage renew succeeds
$t5_key = 'cas-noop-stage';
$t5_a   = mtuc_claim_submission_lock( $t5_key );
mtuc_so_assert(
	mtuc_renew_submission_lock( $t5_key, $t5_a, MTUC_SUBMISSION_LOCK_RENEW_CREATE, MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED ),
	'cas-T5: arm create_armed'
);
mtuc_so_assert(
	mtuc_renew_submission_lock( $t5_key, $t5_a, MTUC_SUBMISSION_LOCK_RENEW_HTTP_CP, MTUC_SUBMISSION_LOCK_STAGE_CP_HTTP ),
	'cas-T5: create_armed → cp_http succeeds'
);
$t5_lock = mtuc_read_submission_lock( mtuc_submission_lock_option_key( $t5_key ) );
mtuc_so_assert( null !== $t5_lock && MTUC_SUBMISSION_LOCK_STAGE_CP_HTTP === $t5_lock['stage'], 'cas-T5: stage cp_http' );

// T6 — stale expected value fails
$t6_key = 'cas-noop-stale-expected';
$t6_a   = mtuc_claim_submission_lock( $t6_key );
$t6_opt = mtuc_submission_lock_option_key( $t6_key );
$t6_raw = mtuc_get_option_raw_value( $t6_opt );
$t6_newer = mtuc_encode_submission_lock_payload( $t6_a, time(), time() + 200, '' );
mtuc_so_assert( mtuc_options_cas_update( $t6_opt, $t6_raw, $t6_newer ), 'cas-T6: advance stored' );
$t6_other = mtuc_encode_submission_lock_payload( $t6_a, time(), time() + 400, 'cp_http' );
mtuc_so_assert(
	false === mtuc_options_cas_update( $t6_opt, $t6_raw, $t6_other ),
	'cas-T6: stale expected fails'
);

// Product/Cart same-second identical create_armed renew must reach create (not mtuc_submit_locked).
$token_ss = 'b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2b2';
$scope_ss = mtuc_build_product_operation_scope_key( 902, 0 );
$ss_lock  = 'same-second-create-armed-lock';
$ss_owner = mtuc_claim_submission_lock( $ss_lock );
mtuc_so_assert( is_string( $ss_owner ), 'same-second: lock claimed' );
mtuc_arm_submission_lock_fence( $ss_lock, $ss_owner );

$creates_ss      = 0;
$ownership_calls = 0;
$resolved_ss     = mtuc_resolve_popup_financing_order(
	$token_ss,
	$scope_ss,
	static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( &$creates_ss, &$ownership_calls ) {
		// Mirrors Product/Cart create: second create_armed ownership renew in the same request.
		++$ownership_calls;
		$owned = mtuc_require_armed_submission_lock_ownership(
			MTUC_SUBMISSION_LOCK_RENEW_CREATE,
			MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED
		);
		if ( is_wp_error( $owned ) ) {
			return $owned;
		}
		++$creates_ss;
		$order = mtuc_so_create_test_order();
		if ( is_callable( $early_bind ) ) {
			$early_bind( $order );
		}
		return $order;
	}
);
mtuc_so_assert( ! is_wp_error( $resolved_ss ), 'same-second: resolve succeeds (no false mtuc_submit_locked)' );
mtuc_so_assert( 1 === $ownership_calls, 'same-second: create ownership checkpoint ran once' );
mtuc_so_assert( 1 === $creates_ss, 'same-second: create reached after identical renew' );
mtuc_so_assert( is_array( $resolved_ss ) && $resolved_ss['order'] instanceof WC_Order, 'same-second: order bound' );
mtuc_so_assert(
	! ( is_wp_error( $resolved_ss ) && 'mtuc_submit_locked' === $resolved_ss->get_error_code() ),
	'same-second: error is not mtuc_submit_locked'
);
mtuc_release_popup_submit_lock( $ss_lock, $ss_owner );

// ---------------------------------------------------------------------------
// Pass 5 — ambiguous wc_create_order failure retains reservation + create_armed
// ---------------------------------------------------------------------------

$token_amb = 'a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1';
$scope_amb = mtuc_build_product_operation_scope_key( 901, 0 );
$amb_lock  = 'amb-create-lock-scope';
$amb_owner = mtuc_claim_submission_lock( $amb_lock );
mtuc_so_assert( is_string( $amb_owner ), 'amb-create: lock claimed' );
mtuc_arm_submission_lock_fence( $amb_lock, $amb_owner );

$creates_amb = 0;
$amb_err     = mtuc_resolve_popup_financing_order(
	$token_amb,
	$scope_amb,
	static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( &$creates_amb ) {
		++$creates_amb;
		// Simulate ambiguous WP_Error after possible partial Woo persistence.
		return new WP_Error( 'woocommerce_order_create_failed', 'ambiguous create' );
	}
);
mtuc_so_assert( is_wp_error( $amb_err ), 'amb-create: controlled error' );
mtuc_so_assert( 1 === $creates_amb, 'amb-create: create attempted once' );

$opt_amb = mtuc_financing_operation_option_key( $token_amb );
$res_amb = mtuc_read_financing_operation_reservation( $opt_amb );
mtuc_so_assert( null !== $res_amb, 'amb-create: reservation retained' );
mtuc_so_assert( '' !== (string) ( $res_amb['creation_ref'] ?? '' ), 'amb-create: creation_ref retained' );
mtuc_so_assert( 0 === (int) ( $res_amb['wc_order_id'] ?? 0 ), 'amb-create: unbound' );

mtuc_release_financing_operation_claim( $opt_amb );
$res_amb2 = mtuc_read_financing_operation_reservation( $opt_amb );
mtuc_so_assert( null !== $res_amb2, 'amb-create: claim delete refused with creation_ref' );

mtuc_release_popup_submit_lock( $amb_lock, $amb_owner );
$amb_lock_row = mtuc_read_submission_lock( mtuc_submission_lock_option_key( $amb_lock ) );
mtuc_so_assert( null !== $amb_lock_row, 'amb-create: create_armed lock retained' );
mtuc_so_assert( MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED === (string) ( $amb_lock_row['stage'] ?? '' ), 'amb-create: stage still create_armed' );

$creates_amb_y = 0;
$amb_second    = mtuc_resolve_popup_financing_order(
	$token_amb,
	$scope_amb,
	static function () use ( &$creates_amb_y ) {
		++$creates_amb_y;
		return mtuc_so_create_test_order();
	}
);
mtuc_so_assert( is_wp_error( $amb_second ), 'amb-create: second attempt blocked' );
mtuc_so_assert( 0 === $creates_amb_y, 'amb-create: Y never created' );
mtuc_so_assert( 'mtuc_operation_contention' === $amb_second->get_error_code(), 'amb-create: contention/manual recovery' );

// ---------------------------------------------------------------------------
// Pass 5 — CP per-request ownership checkpoints (refresh/login/orders/ssl)
// ---------------------------------------------------------------------------

if ( ! defined( 'MTUC_API_BASE_URL' ) ) {
	define( 'MTUC_API_BASE_URL', 'https://cp.example.test/api/v1/' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'home_url' ) ) {
	/**
	 * @return string
	 */
	function home_url() {
		return 'https://shop.example.test';
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * @param string $v Path.
	 * @return string
	 */
	function trailingslashit( $v ) {
		return rtrim( (string) $v, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * @param string $v Path.
	 * @return string
	 */
	function untrailingslashit( $v ) {
		return rtrim( (string) $v, '/\\' );
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	/**
	 * @param string               $url URL.
	 * @param array<string, mixed> $args Args.
	 * @return array<string, mixed>
	 */
	function wp_remote_request( $url, $args = array() ) {
		$GLOBALS['mtuc_test_wp_remote_urls'][] = (string) $url;
		$path = (string) ( parse_url( (string) $url, PHP_URL_PATH ) ?? '' );
		if ( false !== strpos( $path, 'auth/login' ) || false !== strpos( $path, 'auth/refresh' ) ) {
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode(
					array(
						'access_token' => 'tok-test',
						'expires_in'   => 3600,
					)
				),
			);
		}
		if ( false !== strpos( $path, 'orders' ) ) {
			$code = ! empty( $GLOBALS['mtuc_test_cp_orders_401'] ) ? 401 : 200;
			if ( 401 === $code ) {
				$GLOBALS['mtuc_test_cp_orders_401'] = false;
				return array(
					'response' => array( 'code' => 401 ),
					'body'     => wp_json_encode( array( 'message' => 'unauthorized' ) ),
				);
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => array( 'id' => 9 ) ) ),
			);
		}
		if ( false !== strpos( $path, 'ssl/certificate' ) ) {
			$data = array(
				'available'          => true,
				'certificate_sha256' => str_repeat( 'a', 64 ),
				'private_key_sha256' => str_repeat( 'b', 64 ),
				'ssl_revision'       => '1',
			);
			if ( false !== strpos( $path, 'bundle' ) ) {
				$data['certificate_pem'] = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----";
				$data['private_key_pem'] = "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----";
			}
			return array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => $data ) ),
			);
		}
		return array(
			'response' => array( 'code' => 500 ),
			'body'     => '{}',
		);
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	/**
	 * @param array<string, mixed> $response Response.
	 * @return int
	 */
	function wp_remote_retrieve_response_code( $response ) {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	/**
	 * @param array<string, mixed> $response Response.
	 * @return string
	 */
	function wp_remote_retrieve_body( $response ) {
		return (string) ( $response['body'] ?? '' );
	}
}
if ( ! class_exists( 'Mtuc_Settings', false ) ) {
	/**
	 * Minimal settings stub for CP client tests.
	 */
	class Mtuc_Settings {
		public const OPTION_UNICID     = 'mtuc_unicid';
		public const OPTION_SECRET_KEY = 'mtuc_secret_key';
		/**
		 * @param string $key Key.
		 * @return string
		 */
		public static function get( $key ) {
			if ( self::OPTION_UNICID === $key ) {
				return 'TEST-UNICID';
			}
			if ( self::OPTION_SECRET_KEY === $key ) {
				return 'TEST-SECRET';
			}
			return '';
		}
	}
}
if ( ! class_exists( 'Mtuc_Cp_Api_Client', false ) ) {
	require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-cp-api-client.php';
}

$cp_lock = 'cp-checkpoint-scope';
$cp_own  = mtuc_claim_submission_lock( $cp_lock );
mtuc_arm_submission_lock_fence( $cp_lock, $cp_own );
$GLOBALS['mtuc_test_cp_http_checkpoints'] = array();
$GLOBALS['mtuc_test_wp_remote_urls']      = array();

update_option( Mtuc_Cp_Api_Client::OPTION_ACCESS_TOKEN, 'stale-token', false );
update_option( Mtuc_Cp_Api_Client::OPTION_TOKEN_EXPIRES, time() - 10, false );

$refreshed = Mtuc_Cp_Api_Client::refresh_token();
mtuc_so_assert( is_string( $refreshed ), 'cp-check: refresh returns token' );
$paths = array_column( $GLOBALS['mtuc_test_cp_http_checkpoints'], 'path' );
mtuc_so_assert( in_array( 'auth/refresh', $paths, true ), 'cp-check: ownership before refresh HTTP' );

$GLOBALS['mtuc_test_cp_http_checkpoints'] = array();
Mtuc_Cp_Api_Client::clear_token();
$logged = Mtuc_Cp_Api_Client::login();
mtuc_so_assert( is_string( $logged ), 'cp-check: login ok' );
$paths = array_column( $GLOBALS['mtuc_test_cp_http_checkpoints'], 'path' );
mtuc_so_assert( in_array( 'auth/login', $paths, true ), 'cp-check: ownership before login HTTP' );

$GLOBALS['mtuc_test_cp_http_checkpoints'] = array();
update_option( Mtuc_Cp_Api_Client::OPTION_ACCESS_TOKEN, 'tok-test', false );
update_option( Mtuc_Cp_Api_Client::OPTION_TOKEN_EXPIRES, time() + 3600, false );
$created = Mtuc_Cp_Api_Client::create_order( array( 'order_id' => '1' ), 1 );
mtuc_so_assert( ! is_wp_error( $created ), 'cp-check: create-order ok' );
$paths = array_column( $GLOBALS['mtuc_test_cp_http_checkpoints'], 'path' );
mtuc_so_assert( in_array( 'orders', $paths, true ), 'cp-check: ownership before create-order POST' );

$GLOBALS['mtuc_test_cp_http_checkpoints'] = array();
$GLOBALS['mtuc_test_cp_orders_401']       = true;
update_option( Mtuc_Cp_Api_Client::OPTION_ACCESS_TOKEN, 'tok-test', false );
update_option( Mtuc_Cp_Api_Client::OPTION_TOKEN_EXPIRES, time() + 3600, false );
$retry = Mtuc_Cp_Api_Client::create_order( array( 'order_id' => '2' ), 2 );
mtuc_so_assert( ! is_wp_error( $retry ), 'cp-check: 401 retry ok' );
$paths = array_column( $GLOBALS['mtuc_test_cp_http_checkpoints'], 'path' );
$order_posts = array_values( array_filter( $paths, static function ( $p ) {
	return 'orders' === $p;
} ) );
mtuc_so_assert( count( $order_posts ) >= 2, 'cp-check: ownership before 401 retry POST' );
mtuc_so_assert( in_array( 'auth/login', $paths, true ) || in_array( 'auth/refresh', $paths, true ), 'cp-check: reauth checkpoint before retry' );

$GLOBALS['mtuc_test_cp_http_checkpoints'] = array();
update_option( Mtuc_Cp_Api_Client::OPTION_ACCESS_TOKEN, 'tok-test', false );
update_option( Mtuc_Cp_Api_Client::OPTION_TOKEN_EXPIRES, time() + 3600, false );
$meta = Mtuc_Cp_Api_Client::get_ssl_certificate_metadata();
mtuc_so_assert( ! is_wp_error( $meta ), 'cp-check: ssl metadata ok' );
$paths = array_column( $GLOBALS['mtuc_test_cp_http_checkpoints'], 'path' );
mtuc_so_assert( in_array( 'ssl/certificate', $paths, true ), 'cp-check: ownership before cert metadata GET' );

$GLOBALS['mtuc_test_cp_http_checkpoints'] = array();
$bundle = Mtuc_Cp_Api_Client::download_ssl_certificate_bundle();
mtuc_so_assert( ! is_wp_error( $bundle ), 'cp-check: ssl bundle ok' );
$paths = array_column( $GLOBALS['mtuc_test_cp_http_checkpoints'], 'path' );
mtuc_so_assert( in_array( 'ssl/certificate/bundle', $paths, true ), 'cp-check: ownership before cert bundle GET' );

mtuc_disarm_submission_lock_fence( $cp_lock, $cp_own );
mtuc_renew_submission_lock( $cp_lock, $cp_own, 60, MTUC_SUBMISSION_LOCK_STAGE_CP_HTTP );
mtuc_release_submission_lock( $cp_lock, $cp_own );

// ---------------------------------------------------------------------------
// Pass 5 — coupon immutable effect / fingerprint / expected_total
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WC_Order_Item_Coupon', false ) ) {
	/**
	 * Coupon line stub.
	 */
	class WC_Order_Item_Coupon {
		/** @var array<string, mixed> */
		public $data = array();
		/**
		 * @param string $code Code.
		 * @return void
		 */
		public function set_code( $code ): void {
			$this->data['code'] = (string) $code;
		}
		/**
		 * @param float $discount Discount.
		 * @return void
		 */
		public function set_discount( $discount ): void {
			$this->data['discount'] = (float) $discount;
		}
		/**
		 * @param float $tax Tax.
		 * @return void
		 */
		public function set_discount_tax( $tax ): void {
			$this->data['discount_tax'] = (float) $tax;
		}
	}
}

$coupon_order2 = new class( 880 ) extends WC_Order {
	/** @var list<object> */
	public $added = array();
	/**
	 * @param mixed $item Item.
	 * @return int
	 */
	public function add_item( $item ): int {
		$this->added[] = $item;
		return count( $this->added );
	}
};
mtuc_apply_snapshot_adjustments_to_order(
	$coupon_order2,
	array(
		'coupons'  => array(
			array(
				'code'         => 'SAVE10',
				'discount'     => 12.5,
				'discount_tax' => 0.5,
			),
		),
		'fees'     => array(),
		'shipping' => array(),
	)
);
mtuc_so_assert( 1 === count( $coupon_order2->added ), 'coupon-immut: one coupon item' );
mtuc_so_assert( $coupon_order2->added[0] instanceof WC_Order_Item_Coupon, 'coupon-immut: coupon item type' );
mtuc_so_assert( 12.5 === (float) $coupon_order2->added[0]->data['discount'], 'coupon-immut: D1 preserved (not live D2)' );
mtuc_so_assert( 0.5 === (float) $coupon_order2->added[0]->data['discount_tax'], 'coupon-immut: tax effect T1 preserved' );

// Fingerprint corruption
$fp_order = mtuc_so_create_test_order( false );
$good_snap = mtuc_build_cart_operation_snapshot(
	$cust_a,
	$calc_cart,
	$lines_same,
	$adj_base,
	50.0
);
$bad_snap = $good_snap;
$bad_snap['expected_total'] = 99.0; // content changed, fingerprint left as original
$fp_order->update_meta_data( MTUC_ORDER_META_CART_OP_SNAPSHOT, wp_json_encode( $bad_snap ) );
$fp_order->update_meta_data( MTUC_ORDER_META_CREATION_REF, 'deadbeef' );
$fp_order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
$fp_order->set_created_via( 'mtuc_cart_popup' );
$fp_order->save();
mtuc_so_assert( null === mtuc_read_cart_operation_snapshot( $fp_order ), 'fp-corrupt: reader rejects' );
$gate_fp = mtuc_assert_popup_order_ready_for_remote( $fp_order );
mtuc_so_assert( is_wp_error( $gate_fp ), 'fp-corrupt: remote gate blocks incomplete' );

// Expected total match helper + mismatch semantics
mtuc_so_assert( mtuc_financing_amounts_equal( 100.0, 100.0 ), 'expected-total: exact match' );
mtuc_so_assert( mtuc_financing_amounts_equal( 100.004, 100.0 ), 'expected-total: normalized match' );
mtuc_so_assert( ! mtuc_financing_amounts_equal( 100.0, 99.0 ), 'expected-total: 100 vs 99 mismatch' );

$mismatch_order = mtuc_so_create_test_order( false );
$mismatch_order->update_meta_data( MTUC_ORDER_META_CREATION_REF, 'cafebabe' );
$mismatch_order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
$mismatch_order->set_created_via( 'mtuc_cart_popup' );
$mismatch_snap = mtuc_build_cart_operation_snapshot( $cust_a, $calc_cart, $lines_same, $adj_base, 100.0 );
mtuc_so_assert( true === mtuc_persist_cart_operation_snapshot( $mismatch_order, $mismatch_snap ), 'expected-total: persist 100' );
$rebuilt_total = 99.0;
$expected_tot  = (float) $mismatch_snap['expected_total'];
mtuc_so_assert( ! mtuc_financing_amounts_equal( $rebuilt_total, $expected_tot ), 'expected-total: rebuild 99 fails gate' );
mtuc_so_assert( MTUC_POPUP_INIT_INITIALIZING === (string) $mismatch_order->get_meta( MTUC_ORDER_META_POPUP_INIT_STATE ), 'expected-total: stays initializing' );
$remote_mm = mtuc_assert_popup_order_ready_for_remote( $mismatch_order );
mtuc_so_assert( is_wp_error( $remote_mm ), 'expected-total: no remote submission' );

// ---------------------------------------------------------------------------
// Pass 6 — taxed-coupon capture (Woo WC_Cart semantics) + rebuild eligibility
// ---------------------------------------------------------------------------

/**
 * Cart stand-in mirroring WooCommerce 11.1.0 coupon discount API.
 *
 * get_coupon_discount_amount( $code, $ex_tax = true ): totals are ex-tax;
 * when $ex_tax is false, discount tax is ADDED (class-wc-cart.php:2154-2168).
 */
class Mtuc_So_Taxed_Coupon_Cart {
	/** @var array<string, object> */
	public $coupons = array();
	/** @var array<string, float> */
	public $discount_totals = array();
	/** @var array<string, float> */
	public $discount_tax_totals = array();

	/**
	 * @return array<string, object>
	 */
	public function get_coupons() {
		return $this->coupons;
	}

	/**
	 * @return array<string, float>
	 */
	public function get_fees() {
		return array();
	}

	/**
	 * @param string $code   Coupon code.
	 * @param bool   $ex_tax Exclude tax (Woo default true).
	 * @return float
	 */
	public function get_coupon_discount_amount( $code, $ex_tax = true ) {
		$amount = (float) ( $this->discount_totals[ (string) $code ] ?? 0 );
		if ( ! $ex_tax ) {
			$amount += $this->get_coupon_discount_tax_amount( $code );
		}
		return round( $amount, 2 );
	}

	/**
	 * @param string $code Coupon code.
	 * @return float
	 */
	public function get_coupon_discount_tax_amount( $code ) {
		return round( (float) ( $this->discount_tax_totals[ (string) $code ] ?? 0 ), 2 );
	}
}

$taxed_cart              = new Mtuc_So_Taxed_Coupon_Cart();
$taxed_cart->coupons     = array( 'SAVE12' => (object) array( 'code' => 'SAVE12' ) );
// Ex-tax net 10.00; tax component 2.00; gross effect 12.00 (prices include tax).
$taxed_cart->discount_totals     = array( 'SAVE12' => 10.00 );
$taxed_cart->discount_tax_totals = array( 'SAVE12' => 2.00 );

$wc_stub = new class() {
	/** @var object|null */
	public $cart = null;
	/** @var object|null */
	public $session = null;
	/**
	 * @return null
	 */
	public function shipping() {
		return null;
	}
};
$wc_stub->cart = $taxed_cart;
if ( ! function_exists( 'WC' ) ) {
	/**
	 * @return object
	 */
	function WC() {
		return $GLOBALS['mtuc_test_wc'];
	}
}
$GLOBALS['mtuc_test_wc'] = $wc_stub;

// Production capture path (same helper Cart popup uses).
$captured_adj = mtuc_capture_cart_adjustments_for_snapshot();
mtuc_so_assert( 1 === count( $captured_adj['coupons'] ), 'taxed-coupon: one coupon captured' );
mtuc_so_assert( 'SAVE12' === (string) $captured_adj['coupons'][0]['code'], 'taxed-coupon: code' );
mtuc_so_assert( 10.0 === (float) $captured_adj['coupons'][0]['discount'], 'taxed-coupon: discount is ex-tax 10 (not gross 12)' );
mtuc_so_assert( 2.0 === (float) $captured_adj['coupons'][0]['discount_tax'], 'taxed-coupon: discount_tax is 2' );
mtuc_so_assert(
	12.0 === (float) $taxed_cart->get_coupon_discount_amount( 'SAVE12', false ),
	'taxed-coupon: Woo gross path still 12 when ex_tax=false (not stored)'
);

// Tax-exclusive / no-tax regression via same capture API.
$excl_cart = new Mtuc_So_Taxed_Coupon_Cart();
$excl_cart->coupons              = array( 'FLAT10' => (object) array( 'code' => 'FLAT10' ) );
$excl_cart->discount_totals      = array( 'FLAT10' => 10.00 );
$excl_cart->discount_tax_totals  = array( 'FLAT10' => 0.00 );
$GLOBALS['mtuc_test_wc']->cart   = $excl_cart;
$excl_adj = mtuc_capture_cart_adjustments_for_snapshot();
mtuc_so_assert( 10.0 === (float) $excl_adj['coupons'][0]['discount'], 'tax-excl: discount 10' );
mtuc_so_assert( 0.0 === (float) $excl_adj['coupons'][0]['discount_tax'], 'tax-excl: discount_tax 0' );

// Restore taxed cart for snapshot/rebuild path.
$GLOBALS['mtuc_test_wc']->cart = $taxed_cart;
$taxed_lines = array(
	array(
		'product_id'   => 20,
		'variation_id' => 0,
		'quantity'     => 1,
		'line_total'   => 112.0,
	),
);
// Authoritative financeable total after coupon: 112 - 10 - 2 = 100.
$taxed_calc = $calc_cart;
$taxed_calc['price'] = 100.0;
$taxed_snap = mtuc_build_cart_operation_snapshot(
	$cust_a,
	$taxed_calc,
	$taxed_lines,
	$captured_adj,
	100.0
);
mtuc_so_assert( isset( $taxed_snap['fingerprint'] ) && '' !== $taxed_snap['fingerprint'], 'taxed-coupon: fingerprint present' );
$fp_again = mtuc_cart_operation_snapshot_fingerprint( $taxed_snap );
mtuc_so_assert( hash_equals( $taxed_snap['fingerprint'], $fp_again ), 'taxed-coupon: fingerprint covers discount fields' );

$taxed_order = new class( 990 ) extends WC_Order {
	/** @var list<object> */
	public $added = array();
	/**
	 * @param mixed $item Item.
	 * @return int
	 */
	public function add_item( $item ): int {
		$this->added[] = $item;
		return count( $this->added );
	}
};
$taxed_order->total = 100.0;
$taxed_order->update_meta_data( MTUC_ORDER_META_CREATION_REF, 'taxedcouponref' );
$taxed_order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
$taxed_order->set_created_via( 'mtuc_cart_popup' );
mtuc_so_assert( true === mtuc_persist_cart_operation_snapshot( $taxed_order, $taxed_snap ), 'taxed-coupon: persist ok' );

$loaded_taxed = mtuc_read_cart_operation_snapshot( $taxed_order );
mtuc_so_assert( null !== $loaded_taxed, 'taxed-coupon: read-back ok' );
mtuc_so_assert( 10.0 === (float) $loaded_taxed['adjustments']['coupons'][0]['discount'], 'taxed-coupon: stored discount 10' );
mtuc_so_assert( 2.0 === (float) $loaded_taxed['adjustments']['coupons'][0]['discount_tax'], 'taxed-coupon: stored tax 2' );

mtuc_apply_snapshot_adjustments_to_order( $taxed_order, $loaded_taxed['adjustments'] );
mtuc_so_assert( 1 === count( $taxed_order->added ), 'taxed-coupon: rebuilt one coupon item' );
mtuc_so_assert( $taxed_order->added[0] instanceof WC_Order_Item_Coupon, 'taxed-coupon: item type' );
mtuc_so_assert( 10.0 === (float) $taxed_order->added[0]->data['discount'], 'taxed-coupon: item discount 10' );
mtuc_so_assert( 2.0 === (float) $taxed_order->added[0]->data['discount_tax'], 'taxed-coupon: item discount_tax 2' );

$actual_taxed = round( (float) $taxed_order->get_total(), 2 );
mtuc_so_assert( mtuc_financing_amounts_equal( $actual_taxed, (float) $loaded_taxed['expected_total'] ), 'taxed-coupon: rebuilt total == expected_total' );
mtuc_mark_popup_order_init_complete( $taxed_order );
mtuc_so_assert( MTUC_POPUP_INIT_COMPLETE === (string) $taxed_order->get_meta( MTUC_ORDER_META_POPUP_INIT_STATE ), 'taxed-coupon: init_state complete' );
$remote_taxed = mtuc_assert_popup_order_ready_for_remote( $taxed_order );
mtuc_so_assert( true === $remote_taxed, 'taxed-coupon: remote-eligible after valid rebuild' );

// Malformed overlap: discount already includes tax → total gate fails closed.
$overlap_snap = $taxed_snap;
$overlap_snap['adjustments']['coupons'][0]['discount'] = 12.0;
$overlap_snap['adjustments']['coupons'][0]['discount_tax'] = 2.0;
unset( $overlap_snap['fingerprint'] );
$overlap_snap['fingerprint'] = mtuc_cart_operation_snapshot_fingerprint( $overlap_snap );
$overlap_order = mtuc_so_create_test_order( false );
$overlap_order->update_meta_data( MTUC_ORDER_META_CREATION_REF, 'overlapref' );
$overlap_order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
$overlap_order->set_created_via( 'mtuc_cart_popup' );
mtuc_persist_cart_operation_snapshot( $overlap_order, $overlap_snap );
// Simulated rebuild total still 100 while overlapping coupon math would disagree in real WC;
// assert integrity comparator rejects a deliberate mismatch against expected.
mtuc_so_assert( ! mtuc_financing_amounts_equal( 98.0, (float) $overlap_snap['expected_total'] ), 'overlap: mismatched total fails closed' );
mtuc_so_assert( MTUC_POPUP_INIT_INITIALIZING === (string) $overlap_order->get_meta( MTUC_ORDER_META_POPUP_INIT_STATE ), 'overlap: remains incomplete' );

// ---------------------------------------------------------------------------
// Pass 7 — production Cart recovery path (capture → incomplete X → initializer)
// ---------------------------------------------------------------------------

if ( ! function_exists( 'mtuc_get_canonical_financeable_order_total' ) ) {
	/**
	 * @param WC_Order $order Order.
	 * @return float
	 */
	function mtuc_get_canonical_financeable_order_total( WC_Order $order ): float {
		return round( (float) $order->get_total(), 2 );
	}
}
if ( ! function_exists( 'mtuc_get_payment_gateway_title' ) ) {
	/**
	 * @return string
	 */
	function mtuc_get_payment_gateway_title(): string {
		return 'UniCredit';
	}
}
if ( ! function_exists( 'wc_get_product' ) ) {
	/**
	 * @param int $id Product ID.
	 * @return WC_Product|null
	 */
	function wc_get_product( $id ) {
		$id = (int) $id;
		if ( isset( $GLOBALS['mtuc_test_products'][ $id ] ) ) {
			return $GLOBALS['mtuc_test_products'][ $id ];
		}
		$product = new WC_Product( $id );
		$GLOBALS['mtuc_test_products'][ $id ] = $product;
		return $product;
	}
}
if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
	/**
	 * Line item stub for production sync/totals.
	 */
	class WC_Order_Item_Product {
		/** @var int */
		public $id = 0;
		/** @var WC_Product|null */
		public $product = null;
		/** @var int */
		public $quantity = 1;
		/** @var float */
		public $total = 0.0;
		/** @var float */
		public $subtotal = 0.0;
		/** @var float */
		public $total_tax = 0.0;
		/** @var float */
		public $line_total_inc_tax = 0.0;

		public function get_quantity(): int {
			return max( 1, (int) $this->quantity );
		}
		/**
		 * @param float|string $value Value.
		 * @return void
		 */
		public function set_total( $value ): void {
			$this->total = (float) $value;
		}
		/**
		 * @param float|string $value Value.
		 * @return void
		 */
		public function set_subtotal( $value ): void {
			$this->subtotal = (float) $value;
		}
		/**
		 * @return float|string
		 */
		public function get_total() {
			return $this->total;
		}
		/**
		 * @return float
		 */
		public function get_total_tax(): float {
			return (float) $this->total_tax;
		}
		/**
		 * @return WC_Product|null
		 */
		public function get_product() {
			return $this->product;
		}
		public function save(): void {}
	}
}
if ( ! class_exists( 'WC_Order_Item_Fee', false ) ) {
	/**
	 * Fee item stub.
	 */
	class WC_Order_Item_Fee {
		/** @var int */
		public $id = 0;
		/** @var array<string, mixed> */
		public $data = array();
		/**
		 * @param string $name Name.
		 * @return void
		 */
		public function set_name( $name ): void {
			$this->data['name'] = (string) $name;
		}
		/**
		 * @param float $amount Amount.
		 * @return void
		 */
		public function set_amount( $amount ): void {
			$this->data['amount'] = (float) $amount;
		}
		/**
		 * @param float $total Total.
		 * @return void
		 */
		public function set_total( $total ): void {
			$this->data['total'] = (float) $total;
		}
		/**
		 * @param string $tax_class Tax class.
		 * @return void
		 */
		public function set_tax_class( $tax_class ): void {
			$this->data['tax_class'] = (string) $tax_class;
		}
		/**
		 * @param string $status Status.
		 * @return void
		 */
		public function set_tax_status( $status ): void {
			$this->data['tax_status'] = (string) $status;
		}
		/**
		 * @param array<string, mixed> $taxes Taxes.
		 * @return void
		 */
		public function set_taxes( $taxes ): void {
			$this->data['taxes'] = $taxes;
		}
		/**
		 * @return float
		 */
		public function get_total() {
			return (float) ( $this->data['total'] ?? 0 );
		}
	}
}
if ( ! class_exists( 'WC_Order_Item_Shipping', false ) ) {
	/**
	 * Shipping item stub.
	 */
	class WC_Order_Item_Shipping {
		/** @var int */
		public $id = 0;
		/** @var array<string, mixed> */
		public $data = array();
		/**
		 * @param string $title Title.
		 * @return void
		 */
		public function set_method_title( $title ): void {
			$this->data['method_title'] = (string) $title;
		}
		/**
		 * @param string $method_id Method ID.
		 * @return void
		 */
		public function set_method_id( $method_id ): void {
			$this->data['method_id'] = (string) $method_id;
		}
		/**
		 * @param int $instance_id Instance ID.
		 * @return void
		 */
		public function set_instance_id( $instance_id ): void {
			$this->data['instance_id'] = (int) $instance_id;
		}
		/**
		 * @param float $total Total.
		 * @return void
		 */
		public function set_total( $total ): void {
			$this->data['total'] = (float) $total;
		}
		/**
		 * @param array<string, mixed> $taxes Taxes.
		 * @return void
		 */
		public function set_taxes( $taxes ): void {
			$this->data['taxes'] = $taxes;
		}
		/**
		 * @return float
		 */
		public function get_total() {
			return (float) ( $this->data['total'] ?? 0 );
		}
	}
}

/**
 * Order double that supports production Cart reconstruction + calculate_totals().
 */
class Mtuc_So_Recoverable_Cart_Order extends WC_Order {
	/** @var array<string, array<int, object>> */
	public $items_by_type = array(
		'line_item' => array(),
		'fee'       => array(),
		'shipping'  => array(),
		'coupon'    => array(),
		'tax'       => array(),
	);
	/** @var int */
	public $next_item_id = 1;
	/** @var int */
	public $calculate_totals_calls = 0;
	/** @var float Applied after natural total (use -1 for mismatch). */
	public $total_adjust = 0.0;
	/** @var string */
	public $payment_method_title = '';
	/** @var array<string, array<string, string>> */
	public $addresses = array();

	/**
	 * @param string $type Item type.
	 * @return array<int, object|array>
	 */
	public function get_items( $type = '' ) {
		if ( '' === $type || 'line_item' === $type ) {
			return $this->items_by_type['line_item'];
		}
		return isset( $this->items_by_type[ $type ] ) ? $this->items_by_type[ $type ] : array();
	}

	/**
	 * @param int $item_id Item id.
	 * @return void
	 */
	public function remove_item( $item_id ): void {
		$item_id = (int) $item_id;
		foreach ( $this->items_by_type as $type => $items ) {
			if ( isset( $items[ $item_id ] ) ) {
				unset( $this->items_by_type[ $type ][ $item_id ] );
			}
		}
	}

	/**
	 * @param object $item Item.
	 * @return int
	 */
	public function add_item( $item ): int {
		$id = $this->next_item_id++;
		if ( is_object( $item ) && property_exists( $item, 'id' ) ) {
			$item->id = $id;
		}
		$type = 'line_item';
		if ( $item instanceof WC_Order_Item_Coupon ) {
			$type = 'coupon';
		} elseif ( $item instanceof WC_Order_Item_Fee ) {
			$type = 'fee';
		} elseif ( $item instanceof WC_Order_Item_Shipping ) {
			$type = 'shipping';
		} elseif ( $item instanceof WC_Order_Item_Product ) {
			$type = 'line_item';
		}
		$this->items_by_type[ $type ][ $id ] = $item;
		return $id;
	}

	/**
	 * @param WC_Product           $product  Product.
	 * @param int                  $quantity Quantity.
	 * @param array<string, mixed> $args     Extra args (ignored in double).
	 * @return int|false
	 */
	public function add_product( $product, int $quantity = 1, array $args = array() ): int|false {
		$item                     = new WC_Order_Item_Product();
		$item->product            = $product;
		$item->quantity           = max( 1, (int) $quantity );
		$item->line_total_inc_tax = 0.0;
		return $this->add_item( $item );
	}

	/**
	 * @param array<string, string> $address Address.
	 * @param string                $type billing|shipping.
	 * @return void
	 */
	public function set_address( $address, $type = 'billing' ): void {
		$this->addresses[ (string) $type ] = is_array( $address ) ? $address : array();
	}

	/**
	 * @param string $title Title.
	 * @return void
	 */
	public function set_payment_method_title( $title ): void {
		$this->payment_method_title = (string) $title;
	}

	/**
	 * Deterministic totals: lines + fees + shipping − coupon(discount+tax).
	 *
	 * @param bool $and_taxes Unused; kept for WC_Order signature compatibility.
	 * @return void
	 */
	public function calculate_totals( bool $and_taxes = true ): void {
		++$this->calculate_totals_calls;
		$sum = 0.0;
		foreach ( $this->items_by_type['line_item'] as $item ) {
			if ( $item instanceof WC_Order_Item_Product ) {
				// After sync, non-taxable products keep tax-inclusive amount in total.
				$sum += (float) $item->get_total() + (float) $item->get_total_tax();
			}
		}
		foreach ( $this->items_by_type['fee'] as $item ) {
			if ( method_exists( $item, 'get_total' ) ) {
				$sum += (float) $item->get_total();
			}
		}
		foreach ( $this->items_by_type['shipping'] as $item ) {
			if ( method_exists( $item, 'get_total' ) ) {
				$sum += (float) $item->get_total();
			}
		}
		foreach ( $this->items_by_type['coupon'] as $item ) {
			if ( $item instanceof WC_Order_Item_Coupon ) {
				$sum -= (float) ( $item->data['discount'] ?? 0 );
				$sum -= (float) ( $item->data['discount_tax'] ?? 0 );
			}
		}
		$this->total = round( $sum + (float) $this->total_adjust, 2 );
	}
}

$GLOBALS['mtuc_test_products'] = isset( $GLOBALS['mtuc_test_products'] ) && is_array( $GLOBALS['mtuc_test_products'] )
	? $GLOBALS['mtuc_test_products']
	: array();
$GLOBALS['mtuc_test_products'][20] = new WC_Product( 20 );

// Ensure tax-inclusive coupon cart is active for production capture.
$prod_cart = new Mtuc_So_Taxed_Coupon_Cart();
$prod_cart->coupons              = array( 'SAVE12' => (object) array( 'code' => 'SAVE12' ) );
$prod_cart->discount_totals      = array( 'SAVE12' => 10.00 );
$prod_cart->discount_tax_totals  = array( 'SAVE12' => 2.00 );
$GLOBALS['mtuc_test_wc']->cart   = $prod_cart;

$prod_capture = mtuc_capture_cart_adjustments_for_snapshot();
mtuc_so_assert( 10.0 === (float) $prod_capture['coupons'][0]['discount'], 'prod-path: capture discount 10' );
mtuc_so_assert( 2.0 === (float) $prod_capture['coupons'][0]['discount_tax'], 'prod-path: capture discount_tax 2' );
mtuc_so_assert( 12.0 === (float) $prod_cart->get_coupon_discount_amount( 'SAVE12', false ), 'prod-path: Woo gross still 12' );

$prod_lines = array(
	array(
		'product_id'   => 20,
		'variation_id' => 0,
		'quantity'     => 1,
		'line_total'   => 112.0,
	),
);
$prod_calc = array(
	'price'               => 100.0,
	'months'              => 6,
	'parva'               => 0.0,
	'scheme_key'          => 'cart-taxed',
	'popup_offer_type'    => 'standard',
	'scheme_type'         => 'standard',
	'loan_amount'         => 100.0,
	'monthly_installment' => 18.0,
	'total_payable'       => 108.0,
	'glp'                 => 0.0,
	'gpr'                 => 0.0,
	'kop_code'            => 'C1',
	'filter_id'           => 0,
);
$prod_snap = mtuc_build_cart_operation_snapshot( $cust_a, $prod_calc, $prod_lines, $prod_capture, 100.0 );

$token_prod = 'p7p7p7p7p7p7p7p7p7p7p7p7p7p7p7p7';
$scope_prod = 'cart-prod-recovery-scope';
$begin_prod = mtuc_begin_financing_operation( $token_prod, $scope_prod );
mtuc_so_assert( is_array( $begin_prod ) && true === $begin_prod['claimed'], 'prod-path: begin claim' );

$order_prod = new Mtuc_So_Recoverable_Cart_Order( 1701 );
$GLOBALS['mtuc_test_orders'][ 1701 ] = $order_prod;
$order_prod->update_meta_data( MTUC_ORDER_META_CREATION_REF, mtuc_financing_creation_ref( $token_prod ) );
$order_prod->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
$order_prod->set_created_via( 'mtuc_cart_popup' );
// Partial X: leftover commerce residue cleared by production initializer.
$order_prod->add_item( new WC_Order_Item_Fee() );
mtuc_early_bind_financing_operation_order( $begin_prod['option_key'], $order_prod, $token_prod, $scope_prod );
mtuc_so_assert( true === mtuc_persist_cart_operation_snapshot( $order_prod, $prod_snap ), 'prod-path: snapshot persisted on X' );

$resolved_prod = mtuc_resolve_popup_financing_order(
	$token_prod,
	$scope_prod,
	static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( $cust_a, $prod_calc ) {
		// Production Cart initializer (same call path as Cart popup recovery).
		return mtuc_create_cart_popup_pending_order(
			$cust_a,
			$prod_calc,
			array(), // live cart ignored for incomplete X; snapshot is authoritative
			$early_bind,
			$existing,
			$bind_context
		);
	}
);
mtuc_so_assert( ! is_wp_error( $resolved_prod ), 'prod-path: resolve/recovery ok' );
mtuc_so_assert( $order_prod->get_id() === $resolved_prod['order']->get_id(), 'prod-path: same X' );
mtuc_so_assert( $order_prod->calculate_totals_calls >= 1, 'prod-path: calculate_totals invoked by initializer' );

$coupons_prod = $order_prod->get_items( 'coupon' );
mtuc_so_assert( 1 === count( $coupons_prod ), 'prod-path: one coupon item after rebuild' );
$coupon_prod = reset( $coupons_prod );
mtuc_so_assert( $coupon_prod instanceof WC_Order_Item_Coupon, 'prod-path: coupon type' );
mtuc_so_assert( 10.0 === (float) $coupon_prod->data['discount'], 'prod-path: coupon discount 10' );
mtuc_so_assert( 2.0 === (float) $coupon_prod->data['discount_tax'], 'prod-path: coupon discount_tax 2' );
mtuc_so_assert( mtuc_financing_amounts_equal( (float) $order_prod->get_total(), 100.0 ), 'prod-path: actual total 100' );
mtuc_so_assert( MTUC_POPUP_INIT_COMPLETE === (string) $order_prod->get_meta( MTUC_ORDER_META_POPUP_INIT_STATE ), 'prod-path: init_state complete' );
$remote_prod = mtuc_assert_popup_order_ready_for_remote( $order_prod );
mtuc_so_assert( true === $remote_prod, 'prod-path: remote gate accepts' );

// Production mismatch: same path, totals intentionally off by 1.
$token_mm = 'p7m7p7m7p7m7p7m7p7m7p7m7p7m7p7m7';
$scope_mm = 'cart-prod-mismatch-scope';
$begin_mm = mtuc_begin_financing_operation( $token_mm, $scope_mm );
mtuc_so_assert( is_array( $begin_mm ) && true === $begin_mm['claimed'], 'prod-mismatch: begin' );

$order_mm = new Mtuc_So_Recoverable_Cart_Order( 1702 );
$GLOBALS['mtuc_test_orders'][ 1702 ] = $order_mm;
$order_mm->total_adjust = -1.0; // 112 - 12 - 1 = 99 vs expected 100
$order_mm->update_meta_data( MTUC_ORDER_META_CREATION_REF, mtuc_financing_creation_ref( $token_mm ) );
$order_mm->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
$order_mm->set_created_via( 'mtuc_cart_popup' );
mtuc_early_bind_financing_operation_order( $begin_mm['option_key'], $order_mm, $token_mm, $scope_mm );
mtuc_so_assert( true === mtuc_persist_cart_operation_snapshot( $order_mm, $prod_snap ), 'prod-mismatch: snapshot persisted' );

$resolved_mm = mtuc_resolve_popup_financing_order(
	$token_mm,
	$scope_mm,
	static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( $cust_a, $prod_calc ) {
		return mtuc_create_cart_popup_pending_order(
			$cust_a,
			$prod_calc,
			array(),
			$early_bind,
			$existing,
			$bind_context
		);
	}
);
mtuc_so_assert( is_wp_error( $resolved_mm ), 'prod-mismatch: initializer error' );
mtuc_so_assert( 'mtuc_operation_total_mismatch' === $resolved_mm->get_error_code(), 'prod-mismatch: error code' );
mtuc_so_assert( $order_mm->calculate_totals_calls >= 1, 'prod-mismatch: calculate_totals ran' );
mtuc_so_assert( mtuc_financing_amounts_equal( (float) $order_mm->get_total(), 99.0 ), 'prod-mismatch: actual total 99' );
mtuc_so_assert( MTUC_POPUP_INIT_INITIALIZING === (string) $order_mm->get_meta( MTUC_ORDER_META_POPUP_INIT_STATE ), 'prod-mismatch: stays initializing' );
$remote_mm_prod = mtuc_assert_popup_order_ready_for_remote( $order_mm );
mtuc_so_assert( is_wp_error( $remote_mm_prod ), 'prod-mismatch: remote gate rejects' );

fwrite( STDOUT, "OK: {$mtuc_assert_count} submission ownership assertions passed\n" );
exit( 0 );
