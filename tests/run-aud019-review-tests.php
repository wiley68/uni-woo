<?php
/**
 * AUD-WOO-019-REVIEW remediation (REVIEW-01 … REVIEW-07).
 *
 * Run: php tests/run-aud019-review-tests.php
 *
 * The harness is deliberately closer to production than the canonical suite:
 * orders live in a storage array and every reload returns a *fresh* instance
 * hydrated from that storage, saves can fail, and the submission-lock CAS runs
 * against a real option store. That is the only way to tell "the code wrote
 * it" apart from "the code proved it was written".
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

/** @var array<string, mixed> Durable option rows backing the CAS primitives. */
$GLOBALS['mtuc_test_options'] = array();

/** @var array<int, array{meta:array<string,mixed>,payment_method:string,notes:list<string>}> */
$GLOBALS['mtuc_test_order_store'] = array();

/** @var array<int, bool> Order IDs whose every save() fails. */
$GLOBALS['mtuc_test_save_fail'] = array();

$mtuc_a19r_assert_count = 0;

/**
 * @param bool   $ok      Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_a19r_assert( bool $ok, string $message ): void {
	global $mtuc_a19r_assert_count;
	++$mtuc_a19r_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

// ---------------------------------------------------------------------------
// WordPress surface
// ---------------------------------------------------------------------------

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str Value.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return (string) $url;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return 'https://shop.example/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	/**
	 * @param string $url    URL.
	 * @param string $action Action.
	 * @return string
	 */
	function wp_nonce_url( $url, $action = -1 ) {
		unset( $action );
		return (string) $url;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param string   $hook          Hook.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args.
	 * @return true
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $hook, $callback, $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * @param string $cap Capability.
	 * @return bool
	 */
	function current_user_can( $cap ) {
		unset( $cap );
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * @param int    $timestamp Timestamp.
	 * @param string $hook      Hook.
	 * @param array  $args      Args.
	 * @return true
	 */
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		unset( $timestamp, $hook, $args );
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 * @return false
	 */
	function wp_next_scheduled( $hook, $args = array() ) {
		unset( $hook, $args );
		return false;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Insert-if-absent, exactly as wp_options behaves under the lock claim.
	 *
	 * @param string $option     Option name.
	 * @param mixed  $value      Value.
	 * @param string $deprecated Unused.
	 * @param string $autoload   Autoload flag.
	 * @return bool
	 */
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
	/**
	 * @param string $option        Option name.
	 * @param mixed  $default_value Default.
	 * @return mixed
	 */
	function get_option( $option, $default_value = false ) {
		return $GLOBALS['mtuc_test_options'][ $option ] ?? $default_value;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option   Option name.
	 * @param mixed  $value    Value.
	 * @param bool   $autoload Autoload.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = null ) {
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

if ( ! class_exists( 'Mtuc_Settings', false ) ) {
	/**
	 * Settings stub.
	 */
	class Mtuc_Settings {
		public const OPTION_UNICID     = 'mtuc_unicid';
		public const OPTION_SECRET_KEY = 'mtuc_secret_key';

		/**
		 * @param string $key Option key.
		 * @return string
		 */
		public static function get( $key ) {
			if ( self::OPTION_UNICID === $key ) {
				return 'SHOP-UNICID';
			}
			if ( self::OPTION_SECRET_KEY === $key ) {
				return 'SHOP-SECRET';
			}
			return '';
		}

		public static function is_enabled(): bool {
			return true;
		}
	}
}

// ---------------------------------------------------------------------------
// Order double with real storage semantics
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Order double: memory and storage are genuinely separate.
	 *
	 * get_meta() reads this instance. save() copies this instance into the
	 * storage array. wc_get_order() builds a *new* instance from storage, so a
	 * function that "verifies by reloading" cannot accidentally re-read its own
	 * unsaved memory.
	 */
	class WC_Order {
		/** @var int */
		public $id = 0;
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var list<string> */
		public $notes = array();
		/** @var bool Instance-local save failure (storage layer refuses this handle). */
		public $save_fails = false;
		/** @var int */
		public $save_count = 0;

		/**
		 * @param int $id Order ID.
		 */
		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_order_number(): string {
			return (string) $this->id;
		}

		public function get_currency(): string {
			return 'BGN';
		}

		public function get_payment_method(): string {
			return $this->payment_method;
		}

		public function get_status(): string {
			return 'pending';
		}

		/**
		 * @param string|array $statuses Statuses.
		 * @return bool
		 */
		public function has_status( $statuses ): bool {
			return in_array( 'pending', (array) $statuses, true );
		}

		/**
		 * @param string $status Status.
		 * @param string $note   Note.
		 * @return void
		 */
		public function update_status( $status, $note = '' ): void {
			unset( $status, $note );
		}

		/**
		 * @param string $key Meta key.
		 * @return mixed
		 */
		public function get_meta( $key ) {
			return $this->meta[ $key ] ?? '';
		}

		/**
		 * @param string $key   Meta key.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		/**
		 * @param string $key Meta key.
		 * @return void
		 */
		public function delete_meta_data( $key ): void {
			unset( $this->meta[ $key ] );
		}

		/**
		 * @param string $note Note.
		 * @return void
		 */
		public function add_order_note( $note ): void {
			$this->notes[] = (string) $note;
		}

		/**
		 * @return int|false Order ID, or false when the write did not commit.
		 */
		public function save() {
			++$this->save_count;

			if ( $this->save_fails || ! empty( $GLOBALS['mtuc_test_save_fail'][ $this->id ] ) ) {
				return false;
			}

			$GLOBALS['mtuc_test_order_store'][ $this->id ] = array(
				'meta'           => $this->meta,
				'payment_method' => $this->payment_method,
				'notes'          => $this->notes,
			);

			return $this->id;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
	/**
	 * Stub for type hints.
	 */
	class WC_Order_Item_Product {
	}
}

if ( ! class_exists( 'WC_Product', false ) ) {
	/**
	 * Stub for type hints.
	 */
	class WC_Product {
	}
}

if ( ! class_exists( 'WP_Post', false ) ) {
	/**
	 * Stub for type hints.
	 */
	class WP_Post {
		/** @var string */
		public $post_type = '';
		/** @var int */
		public $ID = 0;
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * Hydrate a fresh instance from storage — never hand back a cached handle.
	 *
	 * @param int $id Order ID.
	 * @return WC_Order|null
	 */
	function wc_get_order( $id ) {
		$id = (int) $id;
		if ( ! isset( $GLOBALS['mtuc_test_order_store'][ $id ] ) ) {
			return null;
		}

		$row                   = $GLOBALS['mtuc_test_order_store'][ $id ];
		$fresh                 = new WC_Order( $id );
		$fresh->meta           = $row['meta'];
		$fresh->payment_method = $row['payment_method'];
		$fresh->notes          = $row['notes'];

		return $fresh;
	}
}

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * @param array<string, mixed> $args Args.
	 * @return list<WC_Order>
	 */
	function wc_get_orders( $args ) {
		unset( $args );
		return array();
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

/**
 * Create an order that exists in storage from the start.
 *
 * @param int $id Order ID.
 * @return WC_Order
 */
function mtuc_a19r_order( int $id ): WC_Order {
	$order = new WC_Order( $id );
	$order->save();

	return $order;
}

/**
 * Read one meta value straight out of storage, bypassing every handle.
 *
 * @param int    $id  Order ID.
 * @param string $key Meta key.
 * @return mixed
 */
function mtuc_a19r_stored_meta( int $id, string $key ) {
	return $GLOBALS['mtuc_test_order_store'][ $id ]['meta'][ $key ] ?? '';
}

// ---------------------------------------------------------------------------
// CP client double
// ---------------------------------------------------------------------------

/**
 * Canonical CP envelope.
 *
 * @param array<string, mixed> $data Data object.
 * @return array<string, mixed>
 */
function mtuc_a19r_envelope( array $data ): array {
	return array(
		'success' => true,
		'error'   => null,
		'message' => '',
		'data'    => $data,
	);
}

if ( ! class_exists( 'Mtuc_Cp_Api_Client', false ) ) {
	/**
	 * CP client double that records what the order looked like *in storage* at
	 * the moment of each call. That is how the ordering rules are proven.
	 */
	class Mtuc_Cp_Api_Client {
		/** @var list<mixed> */
		public static $create_queue = array();
		/** @var list<array<string, mixed>> */
		public static $create_calls = array();
		/** @var list<array<string, mixed>> */
		public static $create_evidence_at_call = array();
		/** @var list<mixed> */
		public static $patch_queue = array();
		/** @var list<array<string, mixed>> */
		public static $patch_calls = array();
		/** @var list<string> */
		public static $bank_status_at_patch = array();

		/**
		 * @return void
		 */
		public static function reset(): void {
			self::$create_queue            = array();
			self::$create_calls            = array();
			self::$create_evidence_at_call = array();
			self::$patch_queue             = array();
			self::$patch_calls             = array();
			self::$bank_status_at_patch    = array();
		}

		/**
		 * @param array<string, mixed> $payload     Payload.
		 * @param int                  $wc_order_id Order ID.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function create_order( array $payload, int $wc_order_id = 0 ) {
			self::$create_calls[]            = $payload;
			self::$create_evidence_at_call[] = array(
				'fingerprint' => (string) mtuc_a19r_stored_meta( $wc_order_id, MTUC_ORDER_META_CP_CREATE_FINGERPRINT ),
				'payload'     => (string) mtuc_a19r_stored_meta( $wc_order_id, MTUC_ORDER_META_CP_CREATE_PAYLOAD ),
				'attempt_at'  => (int) mtuc_a19r_stored_meta( $wc_order_id, MTUC_ORDER_META_CP_CREATE_ATTEMPT_AT ),
			);

			if ( empty( self::$create_queue ) ) {
				return new WP_Error( 'mtuc_api_http_error', 'empty queue', array( 'status' => 500 ) );
			}

			return array_shift( self::$create_queue );
		}

		/**
		 * @param string $order_id    CP order ID.
		 * @param string $status      Status label.
		 * @param string $status_id   Status ID.
		 * @param int    $wc_order_id WC order ID.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function update_order_status( string $order_id, string $status, string $status_id, int $wc_order_id = 0 ) {
			self::$patch_calls[]          = array(
				'order_id'  => $order_id,
				'status'    => $status,
				'status_id' => $status_id,
			);
			self::$bank_status_at_patch[] = (string) mtuc_a19r_stored_meta( $wc_order_id, MTUC_ORDER_META_BANK_STATUS );

			$echo = mtuc_a19r_envelope(
				array(
					'id'         => 55,
					'shop_id'    => 1,
					'order_id'   => $order_id,
					'status_id'  => $status_id,
					'status'     => $status,
					'updated_at' => '2026-01-01T00:00:00+00:00',
				)
			);

			if ( empty( self::$patch_queue ) ) {
				return $echo;
			}

			$next = array_shift( self::$patch_queue );

			return true === $next ? $echo : $next;
		}
	}
}

if ( ! class_exists( 'Mtuc_Api_Nonce_Store', false ) ) {
	/**
	 * Nonce store spy: the pre-REST gate must never reach it.
	 */
	class Mtuc_Api_Nonce_Store {
		/** @var int */
		public static $claims = 0;

		/**
		 * @param string $nonce     Nonce.
		 * @param int    $timestamp Timestamp.
		 * @return bool
		 */
		public static function claim( string $nonce, int $timestamp = 0 ): bool {
			unset( $nonce, $timestamp );
			++self::$claims;
			return true;
		}
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-error-normalizer.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-order-diagnostics.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-submission-lock.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-bank-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-process-identity.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';

// ---------------------------------------------------------------------------
// REVIEW-01 — CP sync target transitions hold exclusive durable authority
// ---------------------------------------------------------------------------

$lock_key = mtuc_cp_status_sync_lock_key( 7001 );
mtuc_a19r_assert( 'cpsync_7001' === $lock_key, 'REVIEW-01 lock scope is per order' );
mtuc_a19r_assert(
	mtuc_cp_status_sync_lock_key( 7001 ) !== mtuc_cp_status_sync_lock_key( 7002 ),
	'REVIEW-01 two orders never share a lock'
);
mtuc_a19r_assert(
	'mtuc_slock_cpsync_7001' === mtuc_cp_status_sync_lock_option_key( 7001 ),
	'REVIEW-01 the lock is a real durable option row'
);

$owner_a = mtuc_acquire_cp_status_sync_mutation( 7001 );
mtuc_a19r_assert( is_string( $owner_a ) && '' !== $owner_a, 'REVIEW-01 first claim wins' );

$owner_b = mtuc_acquire_cp_status_sync_mutation( 7001 );
mtuc_a19r_assert(
	is_wp_error( $owner_b ) && 'mtuc_cp_sync_lock_contention' === $owner_b->get_error_code(),
	'REVIEW-01 a second claim on a live lease is refused'
);

mtuc_a19r_assert(
	is_string( mtuc_acquire_cp_status_sync_mutation( 7002 ) ),
	'REVIEW-01 a different order is unaffected by the held lock'
);
mtuc_a19r_assert(
	false === mtuc_release_cp_status_sync_mutation( 7001, 'not-the-owner' ),
	'REVIEW-01 a foreign token releases nothing'
);
mtuc_a19r_assert(
	is_wp_error( mtuc_acquire_cp_status_sync_mutation( 7001 ) ),
	'REVIEW-01 the failed release left the lock in place'
);
mtuc_a19r_assert( true === mtuc_release_cp_status_sync_mutation( 7001, (string) $owner_a ), 'REVIEW-01 the owner releases its own claim' );
mtuc_a19r_assert( false === mtuc_release_cp_status_sync_mutation( 7001, (string) $owner_a ), 'REVIEW-01 releasing twice is not a release' );

// Expired lease: reclaimable, but the displaced owner can no longer delete it.
$stale_owner = mtuc_acquire_cp_status_sync_mutation( 7003 );
mtuc_a19r_assert( is_string( $stale_owner ), 'REVIEW-01 stale-case claim acquired' );

$stale_key = mtuc_cp_status_sync_lock_option_key( 7003 );
$now       = time();
$GLOBALS['mtuc_test_options'][ $stale_key ] = mtuc_encode_submission_lock_payload(
	(string) $stale_owner,
	$now - 600,
	$now - 300,
	MTUC_CP_STATUS_SYNC_LOCK_STAGE
);

$taker = mtuc_acquire_cp_status_sync_mutation( 7003 );
mtuc_a19r_assert( is_string( $taker ) && $taker !== $stale_owner, 'REVIEW-01 an expired lease is reclaimable' );
mtuc_a19r_assert(
	false === mtuc_release_cp_status_sync_mutation( 7003, (string) $stale_owner ),
	'REVIEW-01 a displaced owner cannot delete its replacement'
);
mtuc_a19r_assert(
	is_wp_error( mtuc_acquire_cp_status_sync_mutation( 7003 ) ),
	'REVIEW-01 the replacement lock survived the stale release attempt'
);
mtuc_release_cp_status_sync_mutation( 7003, (string) $taker );
mtuc_release_cp_status_sync_mutation( 7002, '' );

// Worker A holds the lock; worker B's admit must fail closed, not guess.
$interleaved = mtuc_a19r_order( 7100 );
$held        = mtuc_acquire_cp_status_sync_mutation( 7100 );
mtuc_a19r_assert( is_string( $held ), 'REVIEW-01 worker A holds the transition lock' );

$blocked = mtuc_admit_cp_status_sync_target( $interleaved, MTUC_BANK_STATUS_SENT_PROCESS2, 'Изпратен' );
mtuc_a19r_assert(
	is_wp_error( $blocked ) && 'mtuc_cp_sync_lock_contention' === $blocked->get_error_code(),
	'REVIEW-01 worker B cannot admit a target it does not own'
);
mtuc_a19r_assert(
	'' === (string) mtuc_a19r_stored_meta( 7100, MTUC_ORDER_META_CP_SYNC_TARGET ),
	'REVIEW-01 a refused admission writes nothing at all'
);
mtuc_a19r_assert(
	false === mtuc_confirm_cp_status_sync_target( $interleaved, 1, MTUC_BANK_STATUS_SENT_PROCESS2 ),
	'REVIEW-01 confirmation fails closed under contention'
);
mtuc_a19r_assert(
	'' === mtuc_fail_cp_status_sync_target( $interleaved, 1, MTUC_BANK_STATUS_SENT_PROCESS2, new WP_Error( 'x', 'x' ) ),
	'REVIEW-01 failure recording fails closed under contention'
);

mtuc_release_cp_status_sync_mutation( 7100, (string) $held );

$admitted = mtuc_admit_cp_status_sync_target( $interleaved, MTUC_BANK_STATUS_SENT_PROCESS2, 'Изпратен' );
mtuc_a19r_assert( is_array( $admitted ) && 1 === $admitted['generation'], 'REVIEW-01 admission succeeds once the lock is free' );
mtuc_a19r_assert(
	'' !== (string) mtuc_a19r_stored_meta( 7100, MTUC_ORDER_META_CP_SYNC_TARGET ),
	'REVIEW-01 the admitted target is durable in storage, not just in memory'
);
mtuc_a19r_assert(
	! isset( $GLOBALS['mtuc_test_options'][ mtuc_cp_status_sync_lock_option_key( 7100 ) ] ),
	'REVIEW-01 the transition releases its own lock'
);

$retry = mtuc_admit_cp_status_sync_target( $interleaved, MTUC_BANK_STATUS_SENT_PROCESS2, 'Изпратен' );
mtuc_a19r_assert(
	is_array( $retry ) && 1 === $retry['generation'] && 'pending' === $retry['state'],
	'REVIEW-01 re-admitting the same pending target is a retry, not a new generation'
);

$conflicting = mtuc_admit_cp_status_sync_target( $interleaved, MTUC_BANK_STATUS_SENT_PROCESS1, 'Друг' );
mtuc_a19r_assert(
	is_wp_error( $conflicting ) && 'mtuc_cp_sync_semantic_conflict' === $conflicting->get_error_code(),
	'REVIEW-01 a conflicting target cannot displace a pending one'
);

mtuc_a19r_assert(
	false === mtuc_confirm_cp_status_sync_target( $interleaved, 0, MTUC_BANK_STATUS_SENT_PROCESS2 ),
	'REVIEW-01 a stale generation cannot confirm'
);
mtuc_a19r_assert( 'pending' === mtuc_get_cp_status_sync_state( $interleaved ), 'REVIEW-01 the stale confirm changed nothing' );

mtuc_a19r_assert(
	true === mtuc_confirm_cp_status_sync_target( $interleaved, 1, MTUC_BANK_STATUS_SENT_PROCESS2 ),
	'REVIEW-01 the matching generation confirms'
);
mtuc_a19r_assert(
	true === mtuc_confirm_cp_status_sync_target( $interleaved, 1, MTUC_BANK_STATUS_SENT_PROCESS2 ),
	'REVIEW-01 confirming an already-confirmed target is an idempotent no-op'
);

$confirmed_json = (string) mtuc_a19r_stored_meta( 7100, MTUC_ORDER_META_CP_SYNC_TARGET );
mtuc_a19r_assert( false !== strpos( $confirmed_json, '"state":"confirmed"' ), 'REVIEW-01 confirmation is durable' );
mtuc_a19r_assert( false !== strpos( $confirmed_json, '"generation":1' ), 'REVIEW-01 confirmation keeps its generation' );

// A late failure for an older generation must not disturb a newer authority.
$newer = mtuc_admit_cp_status_sync_target( $interleaved, 'bank_approved', 'Одобрен' );
mtuc_a19r_assert( is_array( $newer ) && 2 === $newer['generation'], 'REVIEW-01 a new status opens the next generation' );

mtuc_a19r_assert(
	'' === mtuc_fail_cp_status_sync_target( $interleaved, 1, MTUC_BANK_STATUS_SENT_PROCESS2, new WP_Error( 'http_request_failed', 'late' ) ),
	'REVIEW-01 a stale failure cannot touch a newer generation'
);
mtuc_a19r_assert( 'pending' === mtuc_get_cp_status_sync_state( $interleaved ), 'REVIEW-01 generation 2 is still pending its own PATCH' );
mtuc_a19r_assert(
	false !== strpos( (string) mtuc_a19r_stored_meta( 7100, MTUC_ORDER_META_CP_SYNC_TARGET ), '"generation":2' ),
	'REVIEW-01 the newer generation survives the stale failure'
);

mtuc_a19r_assert(
	'pending' === mtuc_fail_cp_status_sync_target( $interleaved, 2, 'bank_approved', new WP_Error( 'http_request_failed', 'retryable' ) ),
	'REVIEW-01 a retryable failure keeps the target pending'
);

$terminal = mtuc_decode_cp_envelope(
	(string) wp_json_encode(
		array(
			'success' => false,
			'error'   => 'invalid_payload',
			'message' => 'nope',
			'data'    => (object) array(),
		)
	),
	422
);
mtuc_a19r_assert(
	'terminal_failed' === mtuc_fail_cp_status_sync_target( $interleaved, 2, 'bank_approved', $terminal ),
	'REVIEW-01 a terminal semantic failure stops the retries'
);
mtuc_a19r_assert( 'terminal_failed' === mtuc_get_cp_status_sync_state( $interleaved ), 'REVIEW-01 the terminal state is durable' );

// ---------------------------------------------------------------------------
// REVIEW-02 — the local fact is durable before the PATCH, or there is no PATCH
// ---------------------------------------------------------------------------

Mtuc_Cp_Api_Client::reset();

$p1 = mtuc_a19r_order( 7200 );
$p1->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '7200' );
$p1->save();

$recorded = mtuc_record_order_bank_status( $p1, 'cp_sent', array( 'sync_cp' => true ) );
mtuc_a19r_assert( true === $recorded, 'REVIEW-02 a durable local fact returns true, not void' );
mtuc_a19r_assert( 1 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'REVIEW-02 exactly one PATCH followed' );
mtuc_a19r_assert(
	'cp_sent' === ( Mtuc_Cp_Api_Client::$bank_status_at_patch[0] ?? '' ),
	'REVIEW-02 the bank status was already committed to storage when the PATCH went out'
);
mtuc_a19r_assert(
	'cp_sent' === (string) mtuc_a19r_stored_meta( 7200, MTUC_ORDER_META_BANK_STATUS ),
	'REVIEW-02 the local fact survives the round trip'
);

// Storage refuses every write for this order: nothing is claimed, nothing is sent.
Mtuc_Cp_Api_Client::reset();
$dead                                  = mtuc_a19r_order( 7201 );
$dead->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '7201' );
$dead->save();
$GLOBALS['mtuc_test_save_fail'][7201] = true;

$dead_result = mtuc_record_order_bank_status( $dead, 'cp_sent', array( 'sync_cp' => true ) );
mtuc_a19r_assert( is_wp_error( $dead_result ), 'REVIEW-02 a storage failure is reported, not swallowed' );
mtuc_a19r_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'REVIEW-02 no PATCH is sent when nothing could be saved' );
mtuc_a19r_assert(
	'' === (string) mtuc_a19r_stored_meta( 7201, MTUC_ORDER_META_BANK_STATUS ),
	'REVIEW-02 no local bank status is claimed after a failed save'
);
mtuc_a19r_assert(
	'' === (string) mtuc_a19r_stored_meta( 7201, MTUC_ORDER_META_CP_SYNC_TARGET ),
	'REVIEW-02 a target that could not be admitted leaves no record'
);
unset( $GLOBALS['mtuc_test_save_fail'][7201] );

// Admission succeeds, but the caller's own save fails after it.
Mtuc_Cp_Api_Client::reset();
$half = mtuc_a19r_order( 7202 );
$half->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '7202' );
$half->save();
$half->save_fails = true;

$half_result = mtuc_record_order_bank_status( $half, 'cp_sent', array( 'sync_cp' => true ) );
mtuc_a19r_assert(
	is_wp_error( $half_result ) && 'mtuc_bank_status_not_durable' === $half_result->get_error_code(),
	'REVIEW-02 an unsaved local fact is a failure'
);
mtuc_a19r_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'REVIEW-02 an unsaved local fact never reaches CP' );
mtuc_a19r_assert(
	'' === (string) mtuc_a19r_stored_meta( 7202, MTUC_ORDER_META_BANK_STATUS ),
	'REVIEW-02 the in-memory write did not leak into storage'
);
$half->save_fails = false;

// Contention on the target lock also aborts before any local claim.
Mtuc_Cp_Api_Client::reset();
$locked_out = mtuc_a19r_order( 7203 );
$locked_out->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '7203' );
$locked_out->save();
$foreign_owner = mtuc_acquire_cp_status_sync_mutation( 7203 );

$locked_result = mtuc_record_order_bank_status( $locked_out, 'cp_sent', array( 'sync_cp' => true ) );
mtuc_a19r_assert(
	is_wp_error( $locked_result ) && 'mtuc_cp_sync_lock_contention' === $locked_result->get_error_code(),
	'REVIEW-02 admission contention aborts the whole write'
);
mtuc_a19r_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'REVIEW-02 contention sends no PATCH' );
mtuc_a19r_assert(
	'' === (string) mtuc_a19r_stored_meta( 7203, MTUC_ORDER_META_BANK_STATUS ),
	'REVIEW-02 contention claims no local status'
);
mtuc_release_cp_status_sync_mutation( 7203, (string) $foreign_owner );

// A failing PATCH does not undo a durable local fact.
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$patch_queue[] = new WP_Error( 'http_request_failed', 'timeout' );

$resilient = mtuc_a19r_order( 7204 );
$resilient->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '7204' );
$resilient->save();

$resilient_result = mtuc_record_order_bank_status( $resilient, 'cp_sent', array( 'sync_cp' => true ) );
mtuc_a19r_assert( true === $resilient_result, 'REVIEW-02 a PATCH timeout is not a local failure' );
mtuc_a19r_assert(
	'cp_sent' === (string) mtuc_a19r_stored_meta( 7204, MTUC_ORDER_META_BANK_STATUS ),
	'REVIEW-02 the local fact stays durable after a failed PATCH'
);
mtuc_a19r_assert( 'pending' === mtuc_get_cp_status_sync_state( wc_get_order( 7204 ) ), 'REVIEW-02 the PATCH stays owed and retryable' );

// Local-only statuses never touch CP and are still durable.
Mtuc_Cp_Api_Client::reset();
$local_only = mtuc_a19r_order( 7205 );
mtuc_a19r_assert( true === mtuc_record_order_bank_status( $local_only, 'cp_sent' ), 'REVIEW-02 a local-only status is durable' );
mtuc_a19r_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'REVIEW-02 a local-only status sends no PATCH' );

mtuc_a19r_assert(
	is_wp_error( mtuc_record_order_bank_status( $local_only, '' ) ),
	'REVIEW-02 an empty status key is a reported failure'
);

// ---------------------------------------------------------------------------
// REVIEW-03 — the create payload is frozen before the POST
// ---------------------------------------------------------------------------

$payload_a = array(
	'order_id' => '7300',
	'name'     => 'Иван Петров',
	'price'    => 100,
	'egn'      => '9001011234',
	'phone2'   => '+359888111222',
);
$payload_reordered = array(
	'price'    => 100,
	'phone2'   => '+359888111222',
	'name'     => 'Иван Петров',
	'egn'      => '9001011234',
	'order_id' => '7300',
);

mtuc_a19r_assert(
	mtuc_cp_create_payload_fingerprint( $payload_a ) === mtuc_cp_create_payload_fingerprint( $payload_reordered ),
	'REVIEW-03 the fingerprint does not depend on key order'
);
mtuc_a19r_assert(
	64 === strlen( mtuc_cp_create_payload_fingerprint( $payload_a ) ),
	'REVIEW-03 the fingerprint is a sha256 digest'
);
mtuc_a19r_assert(
	mtuc_cp_create_payload_fingerprint( $payload_a ) !== mtuc_cp_create_payload_fingerprint( array_merge( $payload_a, array( 'price' => 101 ) ) ),
	'REVIEW-03 a different request has a different fingerprint'
);

$canonical = mtuc_canonicalize_cp_create_payload( $payload_a );
mtuc_a19r_assert( ! array_key_exists( 'egn', $canonical ), 'REVIEW-03 ЕГН is never part of the evidence' );
mtuc_a19r_assert( ! array_key_exists( 'phone2', $canonical ), 'REVIEW-03 the second phone is never part of the evidence' );
mtuc_a19r_assert( array_keys( $canonical ) === array( 'name', 'order_id', 'price' ), 'REVIEW-03 the evidence is allowlisted and sorted' );

Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_a19r_envelope(
	array(
		'id'         => 900,
		'shop_id'    => 1,
		'order_id'   => '7300',
		'unicid'     => 'SHOP-UNICID',
		'created_at' => '2026-01-01T00:00:00+00:00',
	)
);

$creating = mtuc_a19r_order( 7300 );
$created  = mtuc_create_cp_order_with_recovery( $creating, $payload_a, array( 'uni_proces' => 0 ) );

mtuc_a19r_assert( ! is_wp_error( $created ), 'REVIEW-03 the create succeeded' );
mtuc_a19r_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'REVIEW-03 exactly one POST' );

$evidence_at_post = Mtuc_Cp_Api_Client::$create_evidence_at_call[0];
mtuc_a19r_assert(
	mtuc_cp_create_payload_fingerprint( $payload_a ) === $evidence_at_post['fingerprint'],
	'REVIEW-03 the evidence was already durable when the POST went out'
);
mtuc_a19r_assert( $evidence_at_post['attempt_at'] > 0, 'REVIEW-03 the attempt timestamp is recorded before the POST' );
mtuc_a19r_assert(
	false === strpos( $evidence_at_post['payload'], '9001011234' ),
	'REVIEW-03 the persisted evidence carries no ЕГН'
);

// A second call proposing a different payload is refused without a POST.
$mismatch = mtuc_create_cp_order_with_recovery(
	wc_get_order( 7300 ),
	array_merge( $payload_a, array( 'price' => 999 ) ),
	array( 'uni_proces' => 0 )
);
mtuc_a19r_assert( ! is_wp_error( $mismatch ), 'REVIEW-03 an already-created order short-circuits before the evidence check' );
mtuc_a19r_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'REVIEW-03 a created order is never POSTed again' );

// Unknown outcome: frozen, evidence untouched, no second POST.
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'http_request_failed', 'timeout' );

$ambiguous = mtuc_a19r_order( 7301 );
$first     = mtuc_create_cp_order_with_recovery( $ambiguous, array( 'order_id' => '7301', 'price' => 10 ), array( 'uni_proces' => 0 ) );

mtuc_a19r_assert( is_wp_error( $first ), 'REVIEW-03 an ambiguous create is an error' );
mtuc_a19r_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'REVIEW-03 the ambiguous attempt POSTed once' );
mtuc_a19r_assert(
	'unknown' === (string) mtuc_a19r_stored_meta( 7301, MTUC_ORDER_META_CP_CREATE_OUTCOME ),
	'REVIEW-03 the ambiguous outcome is recorded'
);

$frozen_fingerprint = (string) mtuc_a19r_stored_meta( 7301, MTUC_ORDER_META_CP_CREATE_FINGERPRINT );
$frozen_attempt_at  = (int) mtuc_a19r_stored_meta( 7301, MTUC_ORDER_META_CP_CREATE_ATTEMPT_AT );
mtuc_a19r_assert( '' !== $frozen_fingerprint, 'REVIEW-03 the ambiguous attempt left evidence behind' );

$second = mtuc_create_cp_order_with_recovery(
	wc_get_order( 7301 ),
	array( 'order_id' => '7301', 'price' => 99 ),
	array( 'uni_proces' => 0 )
);
mtuc_a19r_assert(
	is_wp_error( $second ) && 'mtuc_cp_create_outcome_unknown' === $second->get_error_code(),
	'REVIEW-03 an unknown outcome stays frozen'
);
mtuc_a19r_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'REVIEW-03 no second POST after an unknown outcome' );
mtuc_a19r_assert(
	$frozen_fingerprint === (string) mtuc_a19r_stored_meta( 7301, MTUC_ORDER_META_CP_CREATE_FINGERPRINT ),
	'REVIEW-03 the frozen evidence is not overwritten'
);
mtuc_a19r_assert(
	$frozen_attempt_at === (int) mtuc_a19r_stored_meta( 7301, MTUC_ORDER_META_CP_CREATE_ATTEMPT_AT ),
	'REVIEW-03 the recorded attempt time is not rewritten'
);

// Evidence present, outcome never recorded (crash after send): a changed
// payload must be refused rather than allowed to rewrite history.
Mtuc_Cp_Api_Client::reset();
$interrupted = mtuc_a19r_order( 7302 );
mtuc_a19r_assert(
	true === mtuc_freeze_cp_create_attempt( $interrupted, array( 'order_id' => '7302', 'price' => 10 ) ),
	'REVIEW-03 the first freeze records the attempt'
);
mtuc_a19r_assert(
	true === mtuc_freeze_cp_create_attempt( $interrupted, array( 'order_id' => '7302', 'price' => 10 ) ),
	'REVIEW-03 re-freezing the identical payload is accepted'
);

$refused = mtuc_create_cp_order_with_recovery(
	wc_get_order( 7302 ),
	array( 'order_id' => '7302', 'price' => 4242 ),
	array( 'uni_proces' => 0 )
);
mtuc_a19r_assert(
	is_wp_error( $refused ) && 'mtuc_cp_create_payload_mismatch' === $refused->get_error_code(),
	'REVIEW-03 a payload that contradicts the evidence is refused'
);
mtuc_a19r_assert( 0 === count( Mtuc_Cp_Api_Client::$create_calls ), 'REVIEW-03 the refused mutation never POSTed' );

// Pre-send rejections leave no attempt record.
Mtuc_Cp_Api_Client::reset();
$blocked_presend = mtuc_a19r_order( 7303 );
$blocked_presend->update_meta_data( MTUC_ORDER_META_PROCESS2, 1 );
$blocked_presend->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS1 );
$blocked_presend->save();

$presend = mtuc_create_cp_order_with_recovery( $blocked_presend, array( 'order_id' => '7303' ), array( 'uni_proces' => 1 ) );
mtuc_a19r_assert( is_wp_error( $presend ), 'REVIEW-03 a conflicting identity is a pre-send rejection' );
mtuc_a19r_assert( 0 === count( Mtuc_Cp_Api_Client::$create_calls ), 'REVIEW-03 a pre-send rejection sends nothing' );
mtuc_a19r_assert(
	'' === (string) mtuc_a19r_stored_meta( 7303, MTUC_ORDER_META_CP_CREATE_FINGERPRINT ),
	'REVIEW-03 a pre-send rejection records no attempt'
);

// ---------------------------------------------------------------------------
// REVIEW-02 (P2) — an orchestration failure after a successful create
// ---------------------------------------------------------------------------

Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_a19r_envelope(
	array(
		'id'         => 4242,
		'shop_id'    => 1,
		'order_id'   => '7400',
		'unicid'     => 'SHOP-UNICID',
		'created_at' => '2026-01-01T00:00:00+00:00',
	)
);

$p2_broken = mtuc_a19r_order( 7400 );
$p2_broken->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '7400' );
mtuc_persist_order_process_identity( $p2_broken, 2 );
$p2_broken->save();

// Another status is already owed to CP, so the P2 admission must conflict.
mtuc_admit_cp_status_sync_target( $p2_broken, 'bank_other_pending', 'Друг' );

$p2_result = mtuc_create_cp_order_with_recovery(
	$p2_broken,
	array( 'order_id' => '7400', 'price' => 10 ),
	array( 'uni_proces' => 1 )
);

mtuc_a19r_assert(
	is_wp_error( $p2_result ) && 'mtuc_cp_create_orchestration_failed' === $p2_result->get_error_code(),
	'REVIEW-02 a P2 bank-status failure after create is propagated, not hidden behind success'
);
mtuc_a19r_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'REVIEW-02 the create itself happened exactly once' );
mtuc_a19r_assert(
	4242 === (int) mtuc_a19r_stored_meta( 7400, MTUC_ORDER_META_PREFIX . 'cp_order_id' ),
	'REVIEW-02 the CP order id stays persisted — the order does exist in CP'
);
mtuc_a19r_assert(
	'created' === (string) mtuc_a19r_stored_meta( 7400, MTUC_ORDER_META_CP_CREATE_OUTCOME ),
	'REVIEW-02 the create outcome is not downgraded by the orchestration failure'
);
mtuc_a19r_assert(
	true === ( $p2_result->get_error_data()['cp_created'] ?? false ),
	'REVIEW-02 the error states plainly that CP already has the order'
);
mtuc_a19r_assert(
	MTUC_BANK_STATUS_SENT_PROCESS2 !== (string) mtuc_a19r_stored_meta( 7400, MTUC_ORDER_META_BANK_STATUS ),
	'REVIEW-02 no P2 bank status is claimed when it could not be admitted'
);

// The healthy P2 path still saves before it patches.
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_a19r_envelope(
	array(
		'id'         => 4343,
		'shop_id'    => 1,
		'order_id'   => '7401',
		'unicid'     => 'SHOP-UNICID',
		'created_at' => '2026-01-01T00:00:00+00:00',
	)
);

$p2_ok = mtuc_a19r_order( 7401 );
$p2_ok->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '7401' );
mtuc_persist_order_process_identity( $p2_ok, 2 );
$p2_ok->save();

$p2_ok_result = mtuc_create_cp_order_with_recovery(
	$p2_ok,
	array( 'order_id' => '7401', 'price' => 10 ),
	array( 'uni_proces' => 1 )
);

mtuc_a19r_assert( ! is_wp_error( $p2_ok_result ), 'REVIEW-02 the healthy P2 create returns the CP envelope' );
mtuc_a19r_assert( 1 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'REVIEW-02 the P2 status is patched once' );
mtuc_a19r_assert(
	MTUC_BANK_STATUS_SENT_PROCESS2 === ( Mtuc_Cp_Api_Client::$bank_status_at_patch[0] ?? '' ),
	'REVIEW-02 the P2 local fact was durable before the PATCH'
);

// ---------------------------------------------------------------------------
// REVIEW-04 — the body ceiling applies before WordPress reads php://input
// ---------------------------------------------------------------------------

/**
 * @param string $contents Body bytes.
 * @return resource
 */
function mtuc_a19r_stream( string $contents ) {
	$handle = fopen( 'php://memory', 'r+b' );
	fwrite( $handle, $contents );
	rewind( $handle );

	return $handle;
}

Mtuc_Api_Nonce_Store::$claims = 0;
unset( $GLOBALS['HTTP_RAW_POST_DATA'] );

$exact_body = str_repeat( 'x', MTUC_INBOUND_MAX_BODY_BYTES );
$accepted   = mtuc_apply_inbound_body_bound(
	'POST',
	'/wp-json/mtunicredit/v1/shop-cache',
	'',
	mtuc_a19r_stream( $exact_body )
);

mtuc_a19r_assert( 'accept' === $accepted['action'], 'REVIEW-04 exactly MAX bytes is accepted' );
mtuc_a19r_assert(
	isset( $GLOBALS['HTTP_RAW_POST_DATA'] ) && $GLOBALS['HTTP_RAW_POST_DATA'] === $exact_body,
	'REVIEW-04 the accepted bytes are published for the REST stack and the HMAC'
);

unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
$rejected = mtuc_apply_inbound_body_bound(
	'POST',
	'/wp-json/mtunicredit/v1/order-bank-status',
	'',
	mtuc_a19r_stream( str_repeat( 'x', MTUC_INBOUND_MAX_BODY_BYTES + 1 ) )
);

mtuc_a19r_assert( 'reject' === $rejected['action'], 'REVIEW-04 MAX+1 bytes is rejected' );
mtuc_a19r_assert( 413 === $rejected['status'], 'REVIEW-04 the rejection is a 413' );
mtuc_a19r_assert( ! isset( $GLOBALS['HTTP_RAW_POST_DATA'] ), 'REVIEW-04 an oversize body is never published downstream' );
mtuc_a19r_assert( 0 === Mtuc_Api_Nonce_Store::$claims, 'REVIEW-04 the gate rejects before any nonce is claimed' );
mtuc_a19r_assert(
	$rejected['bytes'] <= MTUC_INBOUND_MAX_BODY_BYTES + 1,
	'REVIEW-04 at most MAX+1 bytes are ever read into memory'
);

$too_large_envelope = mtuc_inbound_payload_too_large_envelope();
mtuc_a19r_assert( false === $too_large_envelope['success'], 'REVIEW-04 the 413 body is a canonical failure envelope' );
mtuc_a19r_assert( 'payload_too_large' === $too_large_envelope['error'], 'REVIEW-04 the 413 uses the canonical snake_case code' );
mtuc_a19r_assert( is_string( $too_large_envelope['message'] ), 'REVIEW-04 the 413 carries a message string' );
mtuc_a19r_assert(
	false !== strpos( (string) wp_json_encode( $too_large_envelope ), '"data":{}' ),
	'REVIEW-04 the 413 data is an empty object'
);

// Routes that are not ours, and methods without a body, are left alone.
unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
$foreign = mtuc_apply_inbound_body_bound(
	'POST',
	'/wp-json/wp/v2/posts',
	'',
	mtuc_a19r_stream( str_repeat( 'x', MTUC_INBOUND_MAX_BODY_BYTES + 1 ) )
);
mtuc_a19r_assert( 'skip' === $foreign['action'], 'REVIEW-04 a foreign REST route is not gated' );
mtuc_a19r_assert( ! isset( $GLOBALS['HTTP_RAW_POST_DATA'] ), 'REVIEW-04 a foreign route is left untouched' );

$get_request = mtuc_apply_inbound_body_bound(
	'GET',
	'/wp-json/mtunicredit/v1/shop-cache',
	'',
	mtuc_a19r_stream( 'x' )
);
mtuc_a19r_assert( 'skip' === $get_request['action'], 'REVIEW-04 a bodyless method is not gated' );

$non_rest = mtuc_apply_inbound_body_bound( 'POST', '/checkout/', '', mtuc_a19r_stream( 'x' ) );
mtuc_a19r_assert( 'skip' === $non_rest['action'], 'REVIEW-04 ordinary page requests are unaffected' );

// Plain-permalink addressing reaches the same routes.
unset( $GLOBALS['HTTP_RAW_POST_DATA'] );
$query_form = mtuc_apply_inbound_body_bound(
	'POST',
	'/index.php?rest_route=/mtunicredit/v1/smartucf-debug-log',
	'/mtunicredit/v1/smartucf-debug-log',
	mtuc_a19r_stream( 'body' )
);
mtuc_a19r_assert( 'accept' === $query_form['action'], 'REVIEW-04 the rest_route query form is recognised' );
mtuc_a19r_assert( 'body' === $GLOBALS['HTTP_RAW_POST_DATA'], 'REVIEW-04 the query form publishes the same exact bytes' );

foreach ( mtuc_inbound_rest_route_paths() as $route ) {
	mtuc_a19r_assert(
		mtuc_request_targets_inbound_rest_route( '/wp-json' . $route, '' ),
		'REVIEW-04 route recognised: ' . $route
	);
	mtuc_a19r_assert(
		mtuc_request_targets_inbound_rest_route( '/wp-json' . $route . '/', '' ),
		'REVIEW-04 trailing slash recognised: ' . $route
	);
	mtuc_a19r_assert(
		mtuc_request_targets_inbound_rest_route( '/wp-json' . $route . '?x=1', '' ),
		'REVIEW-04 query string ignored: ' . $route
	);
}

mtuc_a19r_assert(
	! mtuc_request_targets_inbound_rest_route( '/mtunicredit/v1/shop-cache', '' ),
	'REVIEW-04 a bare site path outside the REST prefix is not ours'
);
mtuc_a19r_assert(
	! mtuc_request_targets_inbound_rest_route( '/wp-json/mtunicredit/v1/other', '' ),
	'REVIEW-04 an unrelated route in our namespace is not gated'
);

$chunked = mtuc_read_capped_stream( mtuc_a19r_stream( str_repeat( 'y', 30000 ) ), 20000 );
mtuc_a19r_assert( 20001 === strlen( $chunked ), 'REVIEW-04 the reader stops one byte past the ceiling' );

// ---------------------------------------------------------------------------
// REVIEW-05 — `{}` and `[]` are different values
// ---------------------------------------------------------------------------

mtuc_a19r_assert(
	'{"success":true,"error":null,"message":"ok","data":{}}' === (string) wp_json_encode( mtuc_inbound_success_envelope( 'ok' ) ),
	'REVIEW-05 an empty success data object encodes as {}'
);
mtuc_a19r_assert(
	false !== strpos( (string) wp_json_encode( mtuc_inbound_error_envelope( 'validation', 'no' ) ), '"data":{}' ),
	'REVIEW-05 an empty error data object encodes as {}'
);
mtuc_a19r_assert(
	false !== strpos( (string) wp_json_encode( mtuc_inbound_success_envelope( 'ok', array( 'order_id' => '1' ) ) ), '"data":{"order_id":"1"}' ),
	'REVIEW-05 a populated data object is unaffected'
);

$cp_object = mtuc_decode_cp_envelope( '{"success":true,"error":null,"message":"ok","data":{}}', 200 );
mtuc_a19r_assert( is_array( $cp_object ) && array() === $cp_object['data'], 'REVIEW-05 CP `data:{}` decodes to the empty object' );

$cp_list = mtuc_decode_cp_envelope( '{"success":true,"error":null,"message":"ok","data":[]}', 200 );
mtuc_a19r_assert(
	is_wp_error( $cp_list ) && 'mtuc_api_invalid_envelope' === $cp_list->get_error_code(),
	'REVIEW-05 CP `data:[]` is not the empty object'
);
mtuc_a19r_assert(
	in_array( 'data_not_object', (array) ( $cp_list->get_error_data()['violations'] ?? array() ), true ),
	'REVIEW-05 the violation names the data shape'
);

$cp_populated_list = mtuc_decode_cp_envelope( '{"success":true,"error":null,"message":"ok","data":[1,2]}', 200 );
mtuc_a19r_assert( is_wp_error( $cp_populated_list ), 'REVIEW-05 a populated JSON list is still not an object' );

$cp_envelope_list = mtuc_decode_cp_envelope( '[{"success":true}]', 200 );
mtuc_a19r_assert(
	is_wp_error( $cp_envelope_list )
	&& in_array( 'body_not_object', (array) ( $cp_envelope_list->get_error_data()['violations'] ?? array() ), true ),
	'REVIEW-05 the envelope itself must be a JSON object'
);

mtuc_a19r_assert( mtuc_cp_is_json_object_value( json_decode( '{}', false ) ), 'REVIEW-05 {} is an object' );
mtuc_a19r_assert( ! mtuc_cp_is_json_object_value( json_decode( '[]', false ) ), 'REVIEW-05 [] is not an object' );
mtuc_a19r_assert( ! mtuc_cp_is_json_object_value( json_decode( '"x"', false ) ), 'REVIEW-05 a string is not an object' );

$nested = mtuc_cp_object_to_array( json_decode( '{"a":{"b":[1,{"c":2}]}}', false ) );
mtuc_a19r_assert(
	array( 'a' => array( 'b' => array( 1, array( 'c' => 2 ) ) ) ) === $nested,
	'REVIEW-05 conversion keeps objects as maps and lists as lists'
);

$nested_success = mtuc_decode_cp_envelope( '{"success":true,"error":null,"message":"","data":{"inner":{}}}', 200 );
mtuc_a19r_assert(
	is_array( $nested_success ) && array() === $nested_success['data']['inner'],
	'REVIEW-05 a nested empty object survives conversion'
);

// ---------------------------------------------------------------------------
// REVIEW-06 — nested unicid optional; credentials stay in shop cache data
// ---------------------------------------------------------------------------

$credentials_file = MTUC_PLUGIN_DIR . '/tests/tmp/shop-bank-credentials.php';
if ( file_exists( $credentials_file ) ) {
	unlink( $credentials_file );
}

mtuc_a19r_assert(
	array() === mtuc_validate_shop_snapshot( array( 'uni_zaglavie' => 'Магазин' ), 'SHOP-UNICID' ),
	'REVIEW-06 a snapshot without a nested unicid is valid'
);
mtuc_a19r_assert(
	array() === mtuc_validate_shop_snapshot( array( 'unicid' => 'SHOP-UNICID' ), 'SHOP-UNICID' ),
	'REVIEW-06 a matching nested unicid is valid'
);
mtuc_a19r_assert(
	array( 'unicid_mismatch' ) === mtuc_validate_shop_snapshot( array( 'unicid' => 'OTHER-SHOP' ), 'SHOP-UNICID' ),
	'REVIEW-06 a snapshot naming another shop is rejected'
);
mtuc_a19r_assert(
	array( 'unicid_mismatch' ) === mtuc_validate_shop_snapshot( array( 'unicid' => 123 ), 'SHOP-UNICID' ),
	'REVIEW-06 a non-string nested unicid is rejected'
);
mtuc_a19r_assert(
	array( 'data_empty' ) === mtuc_validate_shop_snapshot( array(), 'SHOP-UNICID' ),
	'REVIEW-06 an empty snapshot is still rejected'
);

// prepare must never write the mistaken plaintext credentials option.
unset( $GLOBALS['mtuc_test_options']['mtuc_shop_credentials'] );

$prepared = mtuc_prepare_shop_snapshot(
	array(
		'uni_zaglavie' => 'Магазин',
		'uni_proces'   => 2,
		'uni_user'     => 'bank-user',
		'uni_password' => 'bank-pass',
		'access_token' => 'tok',
		'secret_key'   => 'sk',
	),
	'SHOP-UNICID'
);

mtuc_a19r_assert( is_array( $prepared ), 'REVIEW-06 a snapshot without a nested unicid is prepared' );
mtuc_a19r_assert( 'Магазин' === $prepared['uni_zaglavie'], 'REVIEW-06 business fields survive' );
mtuc_a19r_assert( ! array_key_exists( 'uni_user', $prepared ), 'REVIEW-06 uni_user stripped from general snapshot' );
mtuc_a19r_assert( ! array_key_exists( 'uni_password', $prepared ), 'REVIEW-06 uni_password stripped from general snapshot' );
mtuc_a19r_assert( ! array_key_exists( 'access_token', $prepared ), 'REVIEW-06 access_token is stripped' );
mtuc_a19r_assert( ! array_key_exists( 'secret_key', $prepared ), 'REVIEW-06 secret_key is stripped' );

mtuc_a19r_assert(
	! array_key_exists( 'mtuc_shop_credentials', $GLOBALS['mtuc_test_options'] ),
	'REVIEW-06 prepare writes no mtuc_shop_credentials option'
);
mtuc_a19r_assert(
	array_key_exists( MTUC_SMARTUCF_CREDENTIALS_OPTION, $GLOBALS['mtuc_test_options'] ),
	'REVIEW-06 COMPLETE pair writes dedicated encrypted option'
);
mtuc_a19r_assert( ! file_exists( $credentials_file ), 'REVIEW-06 prepare does not create a shop-bank-credentials file' );
mtuc_a19r_assert(
	! file_exists( MTUC_PLUGIN_DIR . '/secrets/shop-bank-credentials.php' ),
	'REVIEW-06 prepare does not create secrets/shop-bank-credentials.php'
);
$hydrated = mtuc_hydrate_smartucf_shop_credentials( $prepared, 'SHOP-UNICID' );
mtuc_a19r_assert( is_array( $hydrated ), 'REVIEW-06 runtime hydration succeeds' );
mtuc_a19r_assert(
	'bank-pass' === mtuc_resolve_shop_credential( $hydrated, 'uni_password' ),
	'REVIEW-06 SmartUCF resolves the credential from the hydrated shop array'
);
mtuc_a19r_assert(
	'bank-user' === mtuc_resolve_shop_credential( $hydrated, 'uni_user' ),
	'REVIEW-06 SmartUCF resolves uni_user from the hydrated shop array'
);
mtuc_a19r_assert(
	'' === mtuc_resolve_shop_credential( array( 'unicid' => 'OTHER-SHOP' ), 'uni_password' ),
	'REVIEW-06 resolve returns empty when the shop array has no credential'
);

// ---------------------------------------------------------------------------
// REVIEW-07 — an accepted status is stored exactly as it arrived
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $overrides Overrides.
 * @return array<string, mixed>
 */
function mtuc_a19r_status_body( array $overrides = array() ): array {
	return array_merge(
		array(
			'operation' => MTUC_INBOUND_OPERATION_ORDER_BANK_STATUS,
			'unicid'    => 'SHOP-UNICID',
			'order_id'  => '7500',
			'status_id' => 'cp_sent',
			'status'    => 'Изпратен',
		),
		$overrides
	);
}

foreach ( mtuc_inbound_named_bank_status_ids() as $named ) {
	mtuc_a19r_assert(
		is_array( mtuc_validate_order_bank_status_body( mtuc_a19r_status_body( array( 'status_id' => $named ) ) ) ),
		'REVIEW-07 named status accepted: ' . $named
	);
}

foreach ( array( '85', '0', '1234567890' ) as $numeric ) {
	mtuc_a19r_assert(
		is_array( mtuc_validate_order_bank_status_body( mtuc_a19r_status_body( array( 'status_id' => $numeric ) ) ) ),
		'REVIEW-07 numeric bank code accepted: ' . $numeric
	);
}

foreach ( array( 'bank_approved', 'CP_SENT', 'cp sent', 'cp-sent', '85a', ' 85', 'смарт' ) as $unknown ) {
	$rejected_status = mtuc_validate_order_bank_status_body( mtuc_a19r_status_body( array( 'status_id' => $unknown ) ) );
	mtuc_a19r_assert( is_wp_error( $rejected_status ), 'REVIEW-07 unknown status_id rejected: ' . $unknown );
	mtuc_a19r_assert( 422 === (int) $rejected_status->get_error_data()['status'], 'REVIEW-07 unknown status_id is a 422: ' . $unknown );
	mtuc_a19r_assert(
		in_array( 'status_id', (array) $rejected_status->get_error_data()['violations'], true ),
		'REVIEW-07 the violation names status_id: ' . $unknown
	);
}

$exact_label = '  Изпратен в <Банка> — Процес 1  ';
$validated   = mtuc_validate_order_bank_status_body(
	mtuc_a19r_status_body(
		array(
			'status_id' => 'smartucf_sent',
			'status'    => $exact_label,
		)
	)
);
mtuc_a19r_assert( is_array( $validated ), 'REVIEW-07 a label with markup and padding is accepted' );
mtuc_a19r_assert( $exact_label === $validated['status'], 'REVIEW-07 the validator returns the label byte-for-byte' );
mtuc_a19r_assert( 'smartucf_sent' === $validated['status_id'], 'REVIEW-07 the validator returns the status_id byte-for-byte' );

$push_order = mtuc_a19r_order( 7500 );
mtuc_a19r_assert(
	true === mtuc_apply_cp_bank_status_push( $push_order, $validated['status_id'], $validated['status'] ),
	'REVIEW-07 the push is applied'
);
mtuc_a19r_assert(
	'smartucf_sent' === (string) mtuc_a19r_stored_meta( 7500, MTUC_ORDER_META_BANK_STATUS ),
	'REVIEW-07 the stored status_id is the one that arrived'
);
mtuc_a19r_assert(
	$exact_label === (string) mtuc_a19r_stored_meta( 7500, MTUC_ORDER_META_PREFIX . 'bank_status_label' ),
	'REVIEW-07 the stored label keeps every byte, including padding and angle brackets'
);

$numeric_order = mtuc_a19r_order( 7501 );
mtuc_a19r_assert( true === mtuc_apply_cp_bank_status_push( $numeric_order, '85', 'Одобрен' ), 'REVIEW-07 a numeric bank code is applied' );
mtuc_a19r_assert( '85' === (string) mtuc_a19r_stored_meta( 7501, MTUC_ORDER_META_BANK_STATUS ), 'REVIEW-07 the numeric code is stored as sent' );

$unknown_order = mtuc_a19r_order( 7502 );
$unknown_push  = mtuc_apply_cp_bank_status_push( $unknown_order, 'Bank Approved!', 'Одобрен' );
mtuc_a19r_assert(
	is_wp_error( $unknown_push ) && 'mtuc_missing_status_id' === $unknown_push->get_error_code(),
	'REVIEW-07 an unknown status_id is refused rather than reshaped'
);
mtuc_a19r_assert(
	'' === (string) mtuc_a19r_stored_meta( 7502, MTUC_ORDER_META_BANK_STATUS ),
	'REVIEW-07 a refused push stores nothing'
);

fwrite( STDOUT, 'OK: ' . $mtuc_a19r_assert_count . ' AUD-WOO-019-REVIEW assertions passed' . PHP_EOL );
exit( 0 );
