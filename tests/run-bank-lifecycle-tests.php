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

$p1_payload_status = mtuc_get_cp_order_create_status_payload( array( 'uni_proces' => 0 ) );
mtuc_bl_assert( null === $p1_payload_status, 'Process 1 create must omit bank_sent_process1' );

$p2_payload_status = mtuc_get_cp_order_create_status_payload( array( 'uni_proces' => 1 ) );
mtuc_bl_assert( is_array( $p2_payload_status ), 'Process 2 create missing status' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS2 === $p2_payload_status['status_id'], 'Process 2 wrong status_id' );

$seq_order = new WC_Order();
$seq_order->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = array( 'data' => array( 'id' => 501 ) );
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
Mtuc_Cp_Api_Client::$create_queue[] = array( 'data' => array( 'id' => 777 ) );
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
mtuc_bl_assert( 2 === count( Mtuc_Cp_Api_Client::$create_calls ), 'timeout must idempotent retry once' );
mtuc_bl_assert( Mtuc_Cp_Api_Client::$create_calls[0] === Mtuc_Cp_Api_Client::$create_calls[1], 'retry payload changed' );
mtuc_bl_assert( 'pending' === $ambiguous->status, 'timeout changed Woo status' );

$recover = new WC_Order();
$recover->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'http_request_failed', 'timeout' );
Mtuc_Cp_Api_Client::$create_queue[] = array( 'data' => array( 'id' => 902 ) );
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

fwrite( STDOUT, "OK: {$mtuc_assert_count} bank lifecycle assertions passed\n" );
exit( 0 );
