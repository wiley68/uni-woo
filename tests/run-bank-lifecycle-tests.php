<?php
/**
 * Bank lifecycle tests (AUD-WOO-004 / 005 / 008 / 009).
 *
 * Run: php tests/run-bank-lifecycle-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_bl_assert( bool $ok, string $message ): void {
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

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str Value.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/** @var list<array{timestamp:int,hook:string,args:array}> */
	$GLOBALS['mtuc_scheduled'] = array();

	/**
	 * @param int    $timestamp Timestamp.
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 * @return bool
	 */
	function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
		$GLOBALS['mtuc_scheduled'][] = array(
			'timestamp' => (int) $timestamp,
			'hook'      => (string) $hook,
			'args'      => $args,
		);
		return true;
	}

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

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param string   $hook          Action hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Accepted args count.
	 * @return true
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		unset( $hook, $callback, $priority, $accepted_args );
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * @param string $cap Capability name.
	 * @return bool
	 */
	function current_user_can( $cap ) {
		unset( $cap );
		return true;
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal WC_Order stand-in.
	 */
	class WC_Order {
		/** @var int */
		public $id = 100;
		/** @var string */
		public $status = 'pending';
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var list<string> */
		public $notes = array();
		/** @var int */
		public $status_change_count = 0;

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
			return $this->status;
		}

		/**
		 * @param string|array $statuses Statuses.
		 * @return bool
		 */
		public function has_status( $statuses ): bool {
			$statuses = (array) $statuses;
			return in_array( $this->status, $statuses, true );
		}

		/**
		 * @param string $status New status.
		 * @param string $note Note.
		 * @return void
		 */
		public function update_status( $status, $note = '' ): void {
			++$this->status_change_count;
			$this->status = (string) $status;
			if ( '' !== (string) $note ) {
				$this->notes[] = (string) $note;
			}
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

		public function save(): void {
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

if ( ! class_exists( 'Mtuc_Cp_Api_Client', false ) ) {
	/**
	 * Fake CP API client for lifecycle tests.
	 */
	class Mtuc_Cp_Api_Client {
		/** @var list<mixed> */
		public static $create_queue = array();
		/** @var list<array<string, mixed>> */
		public static $create_calls = array();
		/** @var list<mixed> */
		public static $patch_queue = array();
		/** @var list<array{order_id:string,status:string,status_id:string}> */
		public static $patch_calls = array();

		/**
		 * @return void
		 */
		public static function reset(): void {
			self::$create_queue = array();
			self::$create_calls = array();
			self::$patch_queue  = array();
			self::$patch_calls  = array();
		}

		/**
		 * @param array<string, mixed> $payload Payload.
		 * @param int                  $wc_order_id Order ID.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function create_order( array $payload, int $wc_order_id = 0 ) {
			unset( $wc_order_id );
			self::$create_calls[] = $payload;
			if ( empty( self::$create_queue ) ) {
				return new WP_Error( 'mtuc_api_http_error', 'empty queue', array( 'status' => 500 ) );
			}
			$next = array_shift( self::$create_queue );
			return $next;
		}

		/**
		 * @param string $order_id Order ID.
		 * @param string $status Status label.
		 * @param string $status_id Status id.
		 * @param int    $wc_order_id WC ID.
		 * @return array<string, mixed>|WP_Error
		 */
		public static function update_order_status( string $order_id, string $status, string $status_id, int $wc_order_id = 0 ) {
			unset( $wc_order_id );
			self::$patch_calls[] = array(
				'order_id'  => $order_id,
				'status'    => $status,
				'status_id' => $status_id,
			);
			if ( empty( self::$patch_queue ) ) {
				return array( 'success' => true );
			}
			return array_shift( self::$patch_queue );
		}
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

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-bank-lifecycle.php';

// Load bank-status helpers from popup-order without the whole file's runtime deps:
// define only the constants/functions we need by requiring the file after stubs for unused helpers.
if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return (string) $url;
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

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function admin_url( $path = '' ) {
		return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
	}
}

if ( ! function_exists( 'wp_nonce_url' ) ) {
	/**
	 * @param string $url URL.
	 * @param string $action Action.
	 * @return string
	 */
	function wp_nonce_url( $url, $action = -1 ) {
		unset( $action );
		return (string) $url;
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $id Order ID.
	 * @return WC_Order|null
	 */
	function wc_get_order( $id ) {
		if ( isset( $GLOBALS['mtuc_test_orders'][ (int) $id ] ) ) {
			return $GLOBALS['mtuc_test_orders'][ (int) $id ];
		}
		return null;
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-submission-lock.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';

// ---------------------------------------------------------------------------
// AUD-WOO-004 — native Woo status separation
// ---------------------------------------------------------------------------

$bank_status_samples = array(
	'bank_sent_process1',
	'bank_sent_process2',
	'bank_send_failed_smartucf',
	'05',
	'60',
	'65',
	'85',
	'90',
	'91',
	'94',
);

foreach ( $bank_status_samples as $sample_status ) {
	$order = new WC_Order();
	$order->status = 'on-hold';
	$before        = $order->status;
	mtuc_record_order_bank_status( $order, $sample_status );
	mtuc_bl_assert( $before === $order->status, 'Woo status mutated for ' . $sample_status );
	mtuc_bl_assert( 0 === $order->status_change_count, 'update_status called for ' . $sample_status );
	mtuc_bl_assert( $sample_status === $order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'bank meta missing for ' . $sample_status );
}

$cb_order = new WC_Order();
$cb_order->status = 'processing';
mtuc_apply_cp_bank_status_push( $cb_order, '85', 'Отказана' );
mtuc_bl_assert( 'processing' === $cb_order->status, 'callback mutated Woo status' );
mtuc_bl_assert( '85' === $cb_order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'callback did not store status' );

$notes_before = count( $cb_order->notes );
mtuc_apply_cp_bank_status_push( $cb_order, '85', 'Отказана' );
mtuc_bl_assert( count( $cb_order->notes ) === $notes_before, 'identical callback not idempotent' );

mtuc_apply_cp_bank_status_push( $cb_order, '99', 'Непознат статус' );
mtuc_bl_assert( '99' === $cb_order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'unknown authentic status discarded' );
mtuc_bl_assert( 'processing' === $cb_order->status, 'unknown status mutated Woo' );

$bad = mtuc_apply_cp_bank_status_push( $cb_order, '', '' );
mtuc_bl_assert( is_wp_error( $bad ), 'empty status_id accepted' );

$wrong_pm = new WC_Order();
$wrong_pm->payment_method = 'cod';
$wrong = mtuc_apply_cp_bank_status_push( $wrong_pm, '05', 'Регистрирана' );
mtuc_bl_assert( is_wp_error( $wrong ), 'non-mtuc order accepted' );

// ---------------------------------------------------------------------------
// AUD-WOO-008 — Process 1 / 2 sequencing
// ---------------------------------------------------------------------------

if ( ! class_exists( 'Mtuc_Settings', false ) ) {
	/**
	 * Minimal settings stub for CP identity unicid checks.
	 */
	class Mtuc_Settings {
		public const OPTION_UNICID = 'mtuc_unicid';

		/**
		 * @param string $key Option key.
		 * @return string
		 */
		public static function get( $key ) {
			if ( self::OPTION_UNICID === $key ) {
				return (string) ( $GLOBALS['mtuc_test_unicid'] ?? 'SHOP-UNICID' );
			}
			return '';
		}
	}
}
$GLOBALS['mtuc_test_unicid'] = 'SHOP-UNICID';

/**
 * Build a minimal valid CP create success body (AUD-WOO-011-F03 contract).
 *
 * @param int                  $id      CP data.id.
 * @param string               $order_id Request order_id.
 * @param array<string, mixed> $extra    Extra data fields.
 * @return array<string, mixed>
 */
function mtuc_bl_cp_success( int $id, string $order_id = '100', array $extra = array() ): array {
	return array(
		'data' => array_merge(
			array(
				'id'       => $id,
				'order_id' => $order_id,
				'shop_id'  => 1,
				'unicid'   => (string) ( $GLOBALS['mtuc_test_unicid'] ?? 'SHOP-UNICID' ),
			),
			$extra
		),
	);
}

$p1_payload_status = mtuc_get_cp_order_create_status_payload( array( 'uni_proces' => 0 ) );
mtuc_bl_assert( null === $p1_payload_status, 'Process 1 create must omit bank_sent_process1' );

$p2_payload_status = mtuc_get_cp_order_create_status_payload( array( 'uni_proces' => 1 ) );
mtuc_bl_assert( is_array( $p2_payload_status ), 'Process 2 create missing status' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS2 === $p2_payload_status['status_id'], 'Process 2 wrong status_id' );

$seq_order = new WC_Order();
$seq_order->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 501 );
$created = mtuc_create_cp_order_with_recovery(
	$seq_order,
	array( 'order_id' => '100', 'price' => 10 ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( ! is_wp_error( $created ), 'P1 CP create failed' );
mtuc_bl_assert( '' === (string) $seq_order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P1 premature bank_sent_process1 after CP only' );
mtuc_bl_assert( 'pending' === $seq_order->status, 'P1 CP create changed Woo status' );
mtuc_bl_assert( 'created' === $seq_order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'P1 create outcome not created' );

mtuc_record_order_bank_status( $seq_order, MTUC_BANK_STATUS_SENT_PROCESS1, array( 'sync_cp' => true ) );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === $seq_order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P1 success status missing' );
mtuc_bl_assert( 'pending' === $seq_order->status, 'P1 SmartUCF success changed Woo status' );

$fail_smart = new WC_Order();
$fail_smart->status = 'on-hold';
$fail_smart->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 77 );
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$patch_queue[] = array( 'success' => true );
mtuc_fail_order_on_smartucf_error( $fail_smart, 'smart fail', 'mtuc_smartucf_http_error' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF === $fail_smart->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'SmartUCF fail status wrong' );
mtuc_bl_assert( 'on-hold' === $fail_smart->status, 'SmartUCF fail changed Woo status' );

$p2_order = new WC_Order();
$p2_order->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 777 );
$p2 = mtuc_create_cp_order_with_recovery(
	$p2_order,
	array(
		'order_id'  => '100',
		'status'    => 'Изпратен Банка - Процес 2',
		'status_id' => MTUC_BANK_STATUS_SENT_PROCESS2,
	),
	array( 'uni_proces' => 1 )
);
mtuc_bl_assert( ! is_wp_error( $p2 ), 'P2 CP create failed' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS2 === $p2_order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P2 status missing' );
mtuc_bl_assert( 'pending' === $p2_order->status, 'P2 changed Woo status' );

// ---------------------------------------------------------------------------
// AUD-WOO-005 — CP create ambiguity
// ---------------------------------------------------------------------------

$pre_send = new WC_Order();
$pre_send->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'mtuc_api_http_error', 'validation', array( 'status' => 422 ) );
$r1 = mtuc_create_cp_order_with_recovery( $pre_send, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r1 ), '422 should fail' );
mtuc_bl_assert( 'missing' === $pre_send->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), '422 outcome not missing' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP === $pre_send->get_meta( MTUC_ORDER_META_BANK_STATUS ), '422 bank status wrong' );
mtuc_bl_assert( 'pending' === $pre_send->status, '422 changed Woo status' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), '422 should not retry' );

$ambiguous = new WC_Order();
$ambiguous->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
$r2 = mtuc_create_cp_order_with_recovery( $ambiguous, array( 'order_id' => '100', 'price' => 1 ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r2 ), 'timeout should fail' );
mtuc_bl_assert( 'unknown' === $ambiguous->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'timeout outcome not unknown' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP !== (string) $ambiguous->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01 timeout must not persist bank_send_failed_cp' );
mtuc_bl_assert( '' === (string) $ambiguous->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01 timeout must leave bank status unset' );
mtuc_bl_assert( 1 === (int) $ambiguous->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE ), 'F01 timeout may set bank-unavailable UX notice' );
mtuc_bl_assert( 2 === count( Mtuc_Cp_Api_Client::$create_calls ), 'timeout must idempotent retry once' );
mtuc_bl_assert( Mtuc_Cp_Api_Client::$create_calls[0] === Mtuc_Cp_Api_Client::$create_calls[1], 'retry payload changed' );
mtuc_bl_assert( 'pending' === $ambiguous->status, 'timeout changed Woo status' );

// AUD-WOO-011-F01 Process 2 ambiguity
$ambiguous_p2 = new WC_Order();
$ambiguous_p2->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'http_request_failed', 'timeout' );
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'mtuc_api_http_error', 'gateway', array( 'status' => 503 ) );
$r2p2 = mtuc_create_cp_order_with_recovery( $ambiguous_p2, array( 'order_id' => '100' ), array( 'uni_proces' => 1 ) );
mtuc_bl_assert( is_wp_error( $r2p2 ), 'P2 timeout should error' );
mtuc_bl_assert( 'unknown' === $ambiguous_p2->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'P2 timeout outcome not unknown' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS2 !== (string) $ambiguous_p2->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P2 ambiguous must not claim bank_sent_process2' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED !== (string) $ambiguous_p2->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P2 ambiguous must not persist definitive CP failure' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP !== (string) $ambiguous_p2->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P2 ambiguous must not persist bank_send_failed_cp' );

$recover = new WC_Order();
$recover->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'http_request_failed', 'timeout' );
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 902 );
$r3 = mtuc_create_cp_order_with_recovery( $recover, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( ! is_wp_error( $r3 ), 'idempotent recovery failed' );
mtuc_bl_assert( 'created' === $recover->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'recovery did not clear unknown' );
mtuc_bl_assert( 902 === (int) $recover->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'recovery CP id missing' );
mtuc_bl_assert( 2 === count( Mtuc_Cp_Api_Client::$create_calls ), 'recovery call count' );

$conflict = new WC_Order();
$conflict->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'mtuc_api_http_error', 'conflict', array( 'status' => 409 ) );
$r4 = mtuc_create_cp_order_with_recovery( $conflict, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r4 ), '409 should fail' );
mtuc_bl_assert( 'missing' === $conflict->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), '409 outcome' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), '409 must not invent duplicate create loop' );
mtuc_bl_assert( 'pending' === $conflict->status, '409 changed Woo status' );

mtuc_bl_assert( mtuc_is_cp_transport_ambiguous_error( new WP_Error( 'http_request_failed', 'x' ) ), 'http_request_failed not ambiguous' );
mtuc_bl_assert( mtuc_is_cp_transport_ambiguous_error( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 503 ) ) ), '503 not ambiguous' );
mtuc_bl_assert( ! mtuc_is_cp_transport_ambiguous_error( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 422 ) ) ), '422 wrongly ambiguous' );
mtuc_bl_assert( mtuc_is_cp_idempotency_conflict_error( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 409 ) ) ), '409 not conflict' );

// ---------------------------------------------------------------------------
// AUD-WOO-009 — CP status sync persistence / retry
// ---------------------------------------------------------------------------

$sync_order = new WC_Order();
$sync_order->id = 55;
$sync_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 55 );
$GLOBALS['mtuc_test_orders'][55] = $sync_order;
$GLOBALS['mtuc_scheduled']       = array();

Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$patch_queue[] = new WP_Error( 'http_request_failed', 'timeout' );
$result = mtuc_sync_cp_order_bank_status( $sync_order, MTUC_BANK_STATUS_SENT_PROCESS1 );
mtuc_bl_assert( is_wp_error( $result ), 'sync timeout should error' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === $sync_order->get_meta( MTUC_ORDER_META_CP_SYNC_PENDING ), 'pending sync not set' );
mtuc_bl_assert( 'transport_timeout' === $sync_order->get_meta( MTUC_ORDER_META_CP_SYNC_ERROR ), 'sync error category' );
mtuc_bl_assert( 1 === (int) $sync_order->get_meta( MTUC_ORDER_META_CP_SYNC_ATTEMPTS ), 'sync attempts' );
mtuc_bl_assert( ! empty( $GLOBALS['mtuc_scheduled'] ), 'retry not scheduled' );

$diag = mtuc_sanitize_cp_sync_error_category( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 401 ) ) );
mtuc_bl_assert( 'auth_401' === $diag, '401 category' );
$diag5 = mtuc_sanitize_cp_sync_error_category( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 502 ) ) );
mtuc_bl_assert( 'http_5xx' === $diag5, '5xx category' );
mtuc_bl_assert( false === strpos( $diag, 'Bearer' ), 'secrets in diagnostics' );

Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$patch_queue[] = array( 'success' => true );
$retry = mtuc_retry_cp_status_sync_for_order( 55 );
mtuc_bl_assert( ! is_wp_error( $retry ), 'retry failed' );
mtuc_bl_assert( '' === (string) $sync_order->get_meta( MTUC_ORDER_META_CP_SYNC_PENDING ), 'pending not cleared' );

Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$patch_queue[] = array( 'success' => true );
$dup = mtuc_sync_cp_order_bank_status( $sync_order, MTUC_BANK_STATUS_SENT_PROCESS1 );
mtuc_bl_assert( ! is_wp_error( $dup ), 'duplicate PATCH should succeed' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'duplicate patch call count' );

$malformed = new WC_Order();
$malformed->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 1 );
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$patch_queue[] = new WP_Error( 'mtuc_api_invalid_json', 'bad json' );
mtuc_sync_cp_order_bank_status( $malformed, 'bank_sent_process1' );
mtuc_bl_assert( 'malformed_response' === $malformed->get_meta( MTUC_ORDER_META_CP_SYNC_ERROR ), 'malformed category' );

$admin_label = mtuc_get_cp_create_outcome_admin_label( $ambiguous );
mtuc_bl_assert( '' !== $admin_label && false !== strpos( $admin_label, 'неясен' ), 'admin unknown label' );

// ---------------------------------------------------------------------------
// AUD-WOO-011 Pass 2 — F01 stale definitive status cleanup
// ---------------------------------------------------------------------------

$legacy_failed_cp = new WC_Order();
$legacy_failed_cp->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'unknown' );
$legacy_failed_cp->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED_CP );
$legacy_failed_cp->update_meta_data( MTUC_ORDER_META_PREFIX . 'bank_status_label', 'stale' );
mtuc_record_cp_create_outcome_unknown( $legacy_failed_cp, 'retry still ambiguous' );
mtuc_bl_assert( 'unknown' === $legacy_failed_cp->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F01 legacy outcome remains unknown' );
mtuc_bl_assert( '' === (string) $legacy_failed_cp->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01 must clear stale bank_send_failed_cp' );
mtuc_bl_assert( '' === (string) $legacy_failed_cp->get_meta( MTUC_ORDER_META_PREFIX . 'bank_status_label' ), 'F01 must clear stale bank status label' );
mtuc_bl_assert( 1 === (int) $legacy_failed_cp->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE ), 'F01 legacy keeps unavailable notice' );

$legacy_failed_generic = new WC_Order();
$legacy_failed_generic->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'unknown' );
$legacy_failed_generic->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED );
mtuc_record_cp_create_outcome_unknown( $legacy_failed_generic, 'P2 legacy ambiguous' );
mtuc_bl_assert( '' === (string) $legacy_failed_generic->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01 must clear stale bank_send_failed' );

$preserve_smart = new WC_Order();
$preserve_smart->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF );
mtuc_record_cp_create_outcome_unknown( $preserve_smart, 'unrelated status' );
mtuc_bl_assert(
	MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF === $preserve_smart->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'F01 must preserve unrelated SmartUCF failure status'
);

$preserve_sent = new WC_Order();
$preserve_sent->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS1 );
mtuc_record_cp_create_outcome_unknown( $preserve_sent, 'unrelated sent' );
mtuc_bl_assert(
	MTUC_BANK_STATUS_SENT_PROCESS1 === $preserve_sent->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'F01 must preserve unrelated bank_sent_process1'
);

// ---------------------------------------------------------------------------
// AUD-WOO-011 — F02 malformed 2xx success + F03 identity validation
// ---------------------------------------------------------------------------

mtuc_bl_assert( mtuc_is_cp_create_ambiguous_error( new WP_Error( 'mtuc_cp_unusable_success', 'x' ) ), 'unusable success not ambiguous' );
mtuc_bl_assert( mtuc_is_cp_create_ambiguous_error( new WP_Error( 'mtuc_cp_identity_mismatch', 'x' ) ), 'identity mismatch not ambiguous' );
mtuc_bl_assert( ! mtuc_is_cp_create_ambiguous_error( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 422 ) ) ), '422 wrongly create-ambiguous' );

// F02: malformed success then recovery with matching identity.
$malformed_recover = new WC_Order();
$malformed_recover->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'success' => true,
	'data'    => array(
		'order_id' => '100',
		'unicid'   => 'SHOP-UNICID',
		// missing/invalid id
		'id'       => 0,
	),
);
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'success' => true,
	'data'    => array(
		'id'       => 8801,
		'order_id' => '100',
		'shop_id'  => 9,
		'unicid'   => 'SHOP-UNICID',
	),
);
$r_mal_ok = mtuc_create_cp_order_with_recovery(
	$malformed_recover,
	array( 'order_id' => '100', 'price' => 10 ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( ! is_wp_error( $r_mal_ok ), 'F02 recovery after unusable 2xx failed' );
mtuc_bl_assert( 'created' === $malformed_recover->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F02 recovery outcome' );
mtuc_bl_assert( 8801 === (int) $malformed_recover->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F02 recovered CP id' );
mtuc_bl_assert( 2 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F02 must same-identity replay once' );
mtuc_bl_assert( Mtuc_Cp_Api_Client::$create_calls[0] === Mtuc_Cp_Api_Client::$create_calls[1], 'F02 replay payload changed' );
mtuc_bl_assert( '' === (string) $malformed_recover->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F02 P1 must not claim bank_sent after CP only' );

// F02: repeated malformed success → unknown, no definitive failure.
$malformed_twice = new WC_Order();
$malformed_twice->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array( 'data' => array( 'order_id' => '100' ) );
Mtuc_Cp_Api_Client::$create_queue[] = array( 'data' => array( 'id' => 'abc' ) );
$r_mal_bad = mtuc_create_cp_order_with_recovery(
	$malformed_twice,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( is_wp_error( $r_mal_bad ), 'F02 repeated unusable should error' );
mtuc_bl_assert( 'mtuc_cp_unusable_success' === $r_mal_bad->get_error_code(), 'F02 error code' );
mtuc_bl_assert( 'unknown' === $malformed_twice->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F02 repeated outcome unknown' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP !== (string) $malformed_twice->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F02 must not persist bank_send_failed_cp' );
mtuc_bl_assert( 0 === (int) $malformed_twice->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F02 must not persist CP id' );
mtuc_bl_assert( 2 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F02 repeated call count' );

// F03: matching identity accepted.
$ident_ok = new WC_Order();
$ident_ok->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 9901,
		'order_id' => '100',
		'shop_id'  => 3,
		'unicid'   => 'SHOP-UNICID',
	),
);
$r_id_ok = mtuc_create_cp_order_with_recovery(
	$ident_ok,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( ! is_wp_error( $r_id_ok ), 'F03 matching identity rejected' );
mtuc_bl_assert( 'created' === $ident_ok->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F03 match outcome' );
mtuc_bl_assert( 9901 === (int) $ident_ok->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F03 match CP id' );

// F03: wrong order_id → not success; no CP id persist; unknown after exhausted replay.
$ident_bad = new WC_Order();
$ident_bad->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 9910,
		'order_id' => 'OTHER',
		'unicid'   => 'SHOP-UNICID',
	),
);
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 9911,
		'order_id' => 'OTHER',
		'unicid'   => 'SHOP-UNICID',
	),
);
$r_id_bad = mtuc_create_cp_order_with_recovery(
	$ident_bad,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( is_wp_error( $r_id_bad ), 'F03 mismatch should error' );
mtuc_bl_assert( 'mtuc_cp_identity_mismatch' === $r_id_bad->get_error_code(), 'F03 mismatch code' );
mtuc_bl_assert( 'unknown' === $ident_bad->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F03 mismatch outcome unknown' );
mtuc_bl_assert( 0 === (int) $ident_bad->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F03 must not persist mismatched CP id' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP !== (string) $ident_bad->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F03 mismatch not definitive CP failure' );
mtuc_bl_assert( '' === (string) $ident_bad->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F03 mismatch must not claim bank_sent' );
mtuc_bl_assert( 2 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F03 mismatch same-key replay once' );
mtuc_bl_assert( Mtuc_Cp_Api_Client::$create_calls[0]['order_id'] === '100', 'F03 replay kept request order_id' );

// F03: wrong unicid when present.
$ident_shop = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 9920,
		'order_id' => '100',
		'unicid'   => 'OTHER-SHOP',
	),
);
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 9921,
		'order_id' => '100',
		'unicid'   => 'OTHER-SHOP',
	),
);
$r_shop_bad = mtuc_create_cp_order_with_recovery(
	$ident_shop,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 1 )
);
mtuc_bl_assert( is_wp_error( $r_shop_bad ), 'F03 unicid mismatch should error' );
mtuc_bl_assert( 'unknown' === $ident_shop->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F03 unicid mismatch outcome' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS2 !== (string) $ident_shop->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F03 unicid mismatch must not complete P2' );
mtuc_bl_assert( 0 === (int) $ident_shop->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F03 unicid mismatch must not persist CP id' );

// F03 Pass 2: missing guaranteed order_id → unusable success + same-key replay.
$missing_oid = new WC_Order();
$missing_oid->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'     => 123,
		'unicid' => 'SHOP-UNICID',
		// order_id missing
	),
);
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'     => 124,
		'unicid' => 'SHOP-UNICID',
	),
);
$r_miss_oid = mtuc_create_cp_order_with_recovery(
	$missing_oid,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( is_wp_error( $r_miss_oid ), 'F03 missing order_id should error' );
mtuc_bl_assert( 'mtuc_cp_unusable_success' === $r_miss_oid->get_error_code(), 'F03 missing order_id code' );
mtuc_bl_assert( 'unknown' === $missing_oid->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F03 missing order_id outcome' );
mtuc_bl_assert( 0 === (int) $missing_oid->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F03 missing order_id must not persist CP id' );
mtuc_bl_assert( '' === (string) $missing_oid->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F03 missing order_id no bank status' );
mtuc_bl_assert( 2 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F03 missing order_id same-key replay' );
mtuc_bl_assert( '100' === (string) Mtuc_Cp_Api_Client::$create_calls[0]['order_id'], 'F03 missing order_id replay identity' );

// F03 Pass 2: missing unicid.
$missing_uni = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 123,
		'order_id' => '100',
	),
);
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 124,
		'order_id' => '100',
	),
);
$r_miss_uni = mtuc_create_cp_order_with_recovery(
	$missing_uni,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 1 )
);
mtuc_bl_assert( is_wp_error( $r_miss_uni ), 'F03 missing unicid should error' );
mtuc_bl_assert( 'mtuc_cp_unusable_success' === $r_miss_uni->get_error_code(), 'F03 missing unicid code' );
mtuc_bl_assert( 'unknown' === $missing_uni->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F03 missing unicid outcome' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS2 !== (string) $missing_uni->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F03 missing unicid must not complete P2' );
mtuc_bl_assert( 0 === (int) $missing_uni->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F03 missing unicid no CP id' );

// F03 Pass 2: empty unicid.
$empty_uni = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 123,
		'order_id' => '100',
		'unicid'   => '',
	),
);
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 124,
		'order_id' => '100',
		'unicid'   => '',
	),
);
$r_empty_uni = mtuc_create_cp_order_with_recovery(
	$empty_uni,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( is_wp_error( $r_empty_uni ), 'F03 empty unicid should error' );
mtuc_bl_assert( 'mtuc_cp_unusable_success' === $r_empty_uni->get_error_code(), 'F03 empty unicid code' );
mtuc_bl_assert( 'unknown' === $empty_uni->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F03 empty unicid outcome' );
mtuc_bl_assert( 0 === (int) $empty_uni->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F03 empty unicid no CP id' );

// F03 Pass 2: empty order_id.
$empty_oid = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 123,
		'order_id' => '',
		'unicid'   => 'SHOP-UNICID',
	),
);
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 124,
		'order_id' => '',
		'unicid'   => 'SHOP-UNICID',
	),
);
$r_empty_oid = mtuc_create_cp_order_with_recovery(
	$empty_oid,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( is_wp_error( $r_empty_oid ), 'F03 empty order_id should error' );
mtuc_bl_assert( 'mtuc_cp_unusable_success' === $r_empty_oid->get_error_code(), 'F03 empty order_id code' );
mtuc_bl_assert( 'unknown' === $empty_oid->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F03 empty order_id outcome' );

// F03: integer order_id in response still matches string request.
$ident_int = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array(
	'data' => array(
		'id'       => 9950,
		'order_id' => 100,
		'shop_id'  => 3,
		'unicid'   => 'SHOP-UNICID',
	),
);
$r_int = mtuc_create_cp_order_with_recovery(
	$ident_int,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( ! is_wp_error( $r_int ), 'F03 int/string order_id must match' );
mtuc_bl_assert( 'created' === $ident_int->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F03 int order_id outcome' );
mtuc_bl_assert( 9950 === (int) $ident_int->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F03 int order_id CP id' );

// F02 regression: id-only success body remains unusable (missing guaranteed identity).
$id_only = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array( 'data' => array( 'id' => 7777 ) );
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 7778 );
$r_id_only = mtuc_create_cp_order_with_recovery(
	$id_only,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( ! is_wp_error( $r_id_only ), 'F02/F03 id-only then valid recovery failed' );
mtuc_bl_assert( 'created' === $id_only->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F02/F03 id-only recovery outcome' );
mtuc_bl_assert( 7778 === (int) $id_only->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F02/F03 id-only recovered CP id' );
mtuc_bl_assert( 2 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F02/F03 id-only same-key replay' );

fwrite( STDOUT, "OK: {$mtuc_assert_count} bank lifecycle assertions passed\n" );
exit( 0 );
