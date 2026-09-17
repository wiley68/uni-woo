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

/*
 * Durable option store backing the CP sync target lock (REVIEW-01). The CAS
 * primitives in mtuc-submission-lock.php switch to this array whenever it is
 * set, so the lock behaves here exactly as it does against wp_options.
 */
$GLOBALS['mtuc_test_options'] = array();

if ( ! function_exists( 'add_option' ) ) {
	/**
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
	 * @param bool   $autoload Autoload flag.
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
		/** @var int */
		private static $next_id = 1000;

		/**
		 * Every order is reachable by ID: REVIEW-02 proves durability by
		 * reloading through wc_get_order() rather than trusting memory.
		 *
		 * @param int $id Order ID.
		 */
		public function __construct( int $id = 0 ) {
			$this->id = $id > 0 ? $id : self::$next_id++;
			$GLOBALS['mtuc_test_orders'][ $this->id ] = $this;
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
				// Default: canonical echo envelope that satisfies the F08 contract.
				return mtuc_bl_patch_success( $status_id, $status, $order_id );
			}
			$next = array_shift( self::$patch_queue );
			if ( true === $next ) {
				return mtuc_bl_patch_success( $status_id, $status, $order_id );
			}
			return $next;
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
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-process-identity.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';

if ( ! function_exists( 'mtuc_order_financing_is_terminal_failure' ) ) {
	/**
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	function mtuc_order_financing_is_terminal_failure( WC_Order $order ): bool {
		$outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ) );
		if ( 'unknown' === $outcome ) {
			return false;
		}
		if ( defined( 'MTUC_ORDER_META_SMARTUCF_START_OUTCOME' ) ) {
			$smartucf_outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ) );
			if ( 'unknown' === $smartucf_outcome ) {
				return false;
			}
		}
		$bank_status = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ) );
		return in_array(
			$bank_status,
			array(
				MTUC_BANK_STATUS_SEND_FAILED,
				MTUC_BANK_STATUS_SEND_FAILED_CP,
				MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF,
			),
			true
		);
	}
}

if ( ! function_exists( 'mtuc_should_accept_financing_order_after_submission' ) ) {
	/**
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $submission Submission.
	 * @return bool
	 */
	function mtuc_should_accept_financing_order_after_submission( WC_Order $order, array $submission ): bool {
		if ( empty( $submission['bank_unavailable'] ) ) {
			return true;
		}
		return mtuc_order_financing_is_terminal_failure( $order );
	}
}

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
	return mtuc_bl_envelope(
		array_merge(
			array(
				'id'         => $id,
				'order_id'   => $order_id,
				'shop_id'    => 1,
				'unicid'     => (string) ( $GLOBALS['mtuc_test_unicid'] ?? 'SHOP-UNICID' ),
				'created_at' => '2026-01-01T00:00:00+00:00',
			),
			$extra
		)
	);
}

/**
 * Canonical success envelope wrapper for CP client stubs (AUD-WOO-019-F02).
 *
 * @param array<string, mixed> $data Envelope data object.
 * @return array<string, mixed>
 */
function mtuc_bl_envelope( array $data = array() ): array {
	return array(
		'success' => true,
		'error'   => null,
		'message' => '',
		'data'    => $data,
	);
}

/**
 * Canonical failure envelope error for CP client stubs.
 *
 * @param string $error   Canonical snake_case CP error code.
 * @param int    $status  HTTP status.
 * @param string $message Human message.
 * @return WP_Error
 */
function mtuc_bl_cp_failure( string $error, int $status, string $message = 'rejected' ): WP_Error {
	return new WP_Error(
		'mtuc_api_http_error',
		$message,
		array(
			'status'         => $status,
			'raw'            => '',
			'response'       => array(),
			'envelope'       => array(
				'success' => false,
				'error'   => $error,
				'message' => $message,
				'data'    => array(),
			),
			'envelope_valid' => true,
			'cp_error'       => $error,
		)
	);
}

/**
 * Canonical PATCH echo envelope for CP client stubs (AUD-WOO-019-F08).
 *
 * @param string $status_id Echoed status_id.
 * @param string $status    Echoed status label.
 * @param string $order_id  Echoed order_id.
 * @return array<string, mixed>
 */
function mtuc_bl_patch_success( string $status_id, string $status, string $order_id = '100' ): array {
	return mtuc_bl_envelope(
		array(
			'id'         => 55,
			'shop_id'    => 1,
			'order_id'   => $order_id,
			'status_id'  => $status_id,
			'status'     => $status,
			'updated_at' => '2026-01-01T00:00:00+00:00',
		)
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
Mtuc_Cp_Api_Client::$patch_queue[] = true;
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
// AUD-WOO-005 / AUD-WOO-019-F01 — CP create ambiguity, exactly one POST
// ---------------------------------------------------------------------------

// Canonical semantic rejection: proven not created → definitive failure.
$pre_send = new WC_Order();
$pre_send->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_failure( 'validation', 422 );
$r1 = mtuc_create_cp_order_with_recovery( $pre_send, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r1 ), 'canonical 422 should fail' );
mtuc_bl_assert( 'missing' === $pre_send->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'canonical 422 outcome not missing' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP === $pre_send->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'canonical 422 bank status wrong' );
mtuc_bl_assert( 'pending' === $pre_send->status, 'canonical 422 changed Woo status' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01 canonical 422 exactly one POST' );

// Wrong API base (/api/v11): HTTP 403 HTML → invalid_json → definitive CP absence.
$wrong_base = new WC_Order();
$wrong_base->status = 'pending';
$wrong_base->payment_method = 'mtunicredit';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error(
	'mtuc_api_invalid_json',
	'Невалиден JSON отговор от Контролния панел.',
	array(
		'status' => 403,
		'raw'    => '<!DOCTYPE html><html><head><title>Just a moment...</title></head></html>',
	)
);
$r_v11 = mtuc_create_cp_order_with_recovery( $wrong_base, array( 'order_id' => '958' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_v11 ), 'wrong API base should fail' );
mtuc_bl_assert( 'missing' === $wrong_base->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'wrong API base outcome missing' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP === $wrong_base->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'wrong API base → bank_send_failed_cp' );
mtuc_bl_assert( 'Неуспешно изпратен Банка - КП' === mtuc_get_order_bank_status_display( $wrong_base ), 'wrong API base public label' );
mtuc_bl_assert( 0 === (int) $wrong_base->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'wrong API base no CP id' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'wrong API base exactly one POST' );
mtuc_bl_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'wrong API base no CP status PATCH' );
mtuc_bl_assert( mtuc_order_financing_is_terminal_failure( $wrong_base ), 'wrong API base is terminal for emails' );
mtuc_bl_assert(
	mtuc_should_accept_financing_order_after_submission( $wrong_base, array( 'bank_unavailable' => true ) ),
	'wrong API base accepts emails after definitive CP fail'
);

// Process 2 definitive CP create failure uses the same public CP status (not generic).
$wrong_base_p2 = new WC_Order();
$wrong_base_p2->status = 'pending';
$wrong_base_p2->payment_method = 'mtunicredit';
$wrong_base_p2->update_meta_data( MTUC_ORDER_META_PROCESS, 2 );
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error(
	'mtuc_api_invalid_json',
	'Невалиден JSON отговор от Контролния панел.',
	array(
		'status' => 403,
		'raw'    => '<!DOCTYPE html><html><head><title>Just a moment...</title></head></html>',
	)
);
$r_v11_p2 = mtuc_create_cp_order_with_recovery( $wrong_base_p2, array( 'order_id' => '970' ), array( 'uni_proces' => 1 ) );
mtuc_bl_assert( is_wp_error( $r_v11_p2 ), 'P2 wrong API base should fail' );
mtuc_bl_assert( 'missing' === $wrong_base_p2->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'P2 wrong API base outcome missing' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP === $wrong_base_p2->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P2 wrong API base → bank_send_failed_cp' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED !== (string) $wrong_base_p2->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P2 must not use generic bank_send_failed' );
mtuc_bl_assert( 'Неуспешно изпратен Банка - КП' === mtuc_get_order_bank_status_display( $wrong_base_p2 ), 'P2 public label is CP-specific' );
mtuc_bl_assert( 'Неуспешно изпратен Банка' !== mtuc_get_order_bank_status_display( $wrong_base_p2 ), 'P2 public label is not generic' );
mtuc_bl_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'P2 wrong API base no CP status PATCH' );

// HTML 404 missing route is also definitive.
$missing_route = new WC_Order();
$missing_route->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error(
	'mtuc_api_invalid_json',
	'bad json',
	array( 'status' => 404, 'raw' => '<html>Not Found</html>' )
);
$r_404 = mtuc_create_cp_order_with_recovery( $missing_route, array( 'order_id' => '959' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_404 ), '404 HTML should fail' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP === $missing_route->get_meta( MTUC_ORDER_META_BANK_STATUS ), '404 HTML → bank_send_failed_cp' );

// Invalid JSON on 2xx stays ambiguous (may have committed).
$json_2xx = new WC_Order();
$json_2xx->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error(
	'mtuc_api_invalid_json',
	'bad json',
	array( 'status' => 200, 'raw' => '{truncated' )
);
$r_json2xx = mtuc_create_cp_order_with_recovery( $json_2xx, array( 'order_id' => '960' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_json2xx ), '2xx invalid JSON should error' );
mtuc_bl_assert( 'unknown' === $json_2xx->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), '2xx invalid JSON stays unknown' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP !== (string) $json_2xx->get_meta( MTUC_ORDER_META_BANK_STATUS ), '2xx invalid JSON not definitive' );

// Non-canonical 4xx proves nothing: ambiguous, never a definitive CP failure.
$noncanonical_422 = new WC_Order();
$noncanonical_422->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'mtuc_api_http_error', 'validation', array( 'status' => 422 ) );
$r_nc422 = mtuc_create_cp_order_with_recovery( $noncanonical_422, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_nc422 ), 'noncanonical 422 should error' );
mtuc_bl_assert( 'unknown' === $noncanonical_422->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F01 noncanonical 422 must stay unknown' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP !== (string) $noncanonical_422->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01 noncanonical 422 must not claim CP failure' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01 noncanonical 422 exactly one POST' );

// Timeout after send: exactly one POST, frozen as unknown.
$ambiguous = new WC_Order();
$ambiguous->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
$r2 = mtuc_create_cp_order_with_recovery( $ambiguous, array( 'order_id' => '100', 'price' => 1 ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r2 ), 'timeout should fail' );
mtuc_bl_assert( 'unknown' === $ambiguous->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'timeout outcome not unknown' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP !== (string) $ambiguous->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01 timeout must not persist bank_send_failed_cp' );
mtuc_bl_assert( '' === (string) $ambiguous->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01 timeout must leave bank status unset' );
mtuc_bl_assert( 1 === (int) $ambiguous->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE ), 'F01 timeout may set bank-unavailable UX notice' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01 ambiguous create must POST exactly once' );
mtuc_bl_assert( 'pending' === $ambiguous->status, 'timeout changed Woo status' );

// Frozen: a second attempt on an unknown outcome must not reach the network.
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 4242 );
$r_frozen = mtuc_create_cp_order_with_recovery( $ambiguous, array( 'order_id' => '100', 'price' => 1 ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_frozen ), 'F01 frozen unknown must refuse a second create' );
mtuc_bl_assert( 'mtuc_cp_create_outcome_unknown' === $r_frozen->get_error_code(), 'F01 frozen error code' );
mtuc_bl_assert( 0 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01 frozen unknown must POST zero times' );
mtuc_bl_assert( 'unknown' === $ambiguous->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F01 frozen outcome preserved' );
mtuc_bl_assert( 0 === (int) $ambiguous->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F01 frozen must not persist a CP id' );
mtuc_bl_assert( mtuc_is_cp_create_ambiguous_error( $r_frozen ), 'F01 frozen error is itself ambiguous' );

// AUD-WOO-011-F01 Process 2 ambiguity
$ambiguous_p2 = new WC_Order();
$ambiguous_p2->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'http_request_failed', 'timeout' );
$r2p2 = mtuc_create_cp_order_with_recovery( $ambiguous_p2, array( 'order_id' => '100' ), array( 'uni_proces' => 1 ) );
mtuc_bl_assert( is_wp_error( $r2p2 ), 'P2 timeout should error' );
mtuc_bl_assert( 'unknown' === $ambiguous_p2->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'P2 timeout outcome not unknown' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS2 !== (string) $ambiguous_p2->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P2 ambiguous must not claim bank_sent_process2' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED !== (string) $ambiguous_p2->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P2 ambiguous must not persist definitive CP failure' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP !== (string) $ambiguous_p2->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P2 ambiguous must not persist bank_send_failed_cp' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01 P2 ambiguous exactly one POST' );

// Canonical 409 is a definitive conflict; a malformed 409 is not.
$conflict = new WC_Order();
$conflict->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_failure( 'order_already_exists', 409, 'conflict' );
$r4 = mtuc_create_cp_order_with_recovery( $conflict, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r4 ), 'canonical 409 should fail' );
mtuc_bl_assert( 'missing' === $conflict->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'canonical 409 outcome' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01 canonical 409 exactly one POST' );
mtuc_bl_assert( 'pending' === $conflict->status, '409 changed Woo status' );

$conflict_malformed = new WC_Order();
$conflict_malformed->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'mtuc_api_http_error', 'conflict', array( 'status' => 409 ) );
$r4m = mtuc_create_cp_order_with_recovery( $conflict_malformed, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r4m ), 'malformed 409 should error' );
mtuc_bl_assert( 'unknown' === $conflict_malformed->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F01 malformed 409 must stay ambiguous' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01 malformed 409 exactly one POST' );

// 401 after send cannot prove the order was not committed.
$auth_lost = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_failure( 'unauthenticated', 401 );
$r_401 = mtuc_create_cp_order_with_recovery( $auth_lost, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_401 ), '401 should error' );
mtuc_bl_assert( 'unknown' === $auth_lost->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F01 401 must stay ambiguous' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01 401 exactly one POST' );

// 429 throttling is ambiguous too.
$throttled = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_failure( 'too_many_requests', 429 );
$r_429 = mtuc_create_cp_order_with_recovery( $throttled, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_429 ), '429 should error' );
mtuc_bl_assert( 'unknown' === $throttled->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F01 429 must stay ambiguous' );

// Unknown canonical 4xx code is not proof of rejection.
$unknown_code = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_failure( 'some_future_code', 400 );
$r_unknown = mtuc_create_cp_order_with_recovery( $unknown_code, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_unknown ), 'unknown 4xx code should error' );
mtuc_bl_assert( 'unknown' === $unknown_code->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F01 unknown 4xx code must stay ambiguous' );

// Malformed envelope on an otherwise 2xx response is ambiguous.
$bad_envelope = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = new WP_Error( 'mtuc_api_invalid_envelope', 'noncanonical', array( 'status' => 200, 'envelope_valid' => false ) );
$r_env = mtuc_create_cp_order_with_recovery( $bad_envelope, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_env ), 'malformed envelope should error' );
mtuc_bl_assert( 'unknown' === $bad_envelope->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F01 malformed envelope must stay ambiguous' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01 malformed envelope exactly one POST' );

// Ambiguity classification unit assertions.
mtuc_bl_assert( mtuc_is_cp_transport_ambiguous_error( new WP_Error( 'http_request_failed', 'x' ) ), 'http_request_failed not ambiguous' );
mtuc_bl_assert( mtuc_is_cp_transport_ambiguous_error( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 503 ) ) ), '503 not ambiguous' );
mtuc_bl_assert( mtuc_is_cp_transport_ambiguous_error( new WP_Error( 'mtuc_api_invalid_envelope', 'x' ) ), 'malformed envelope not ambiguous' );
mtuc_bl_assert( mtuc_is_cp_create_ambiguous_error( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 422 ) ) ), 'F01 noncanonical 422 must be create-ambiguous' );
mtuc_bl_assert( ! mtuc_is_cp_create_ambiguous_error( mtuc_bl_cp_failure( 'validation', 422 ) ), 'F01 canonical validation must be definitive' );
mtuc_bl_assert( ! mtuc_is_cp_create_ambiguous_error( mtuc_bl_cp_failure( 'invalid_payload', 400 ) ), 'F01 canonical invalid_payload must be definitive' );
mtuc_bl_assert( mtuc_is_cp_create_ambiguous_error( mtuc_bl_cp_failure( 'unauthenticated', 401 ) ), 'F01 401 must be ambiguous' );
mtuc_bl_assert( mtuc_is_cp_create_ambiguous_error( mtuc_bl_cp_failure( 'too_many_requests', 429 ) ), 'F01 429 must be ambiguous' );
mtuc_bl_assert( mtuc_is_cp_create_ambiguous_error( mtuc_bl_cp_failure( 'server_error', 500 ) ), 'F01 5xx must be ambiguous' );
mtuc_bl_assert( mtuc_is_cp_idempotency_conflict_error( mtuc_bl_cp_failure( 'order_already_exists', 409 ) ), 'canonical 409 not conflict' );
mtuc_bl_assert( ! mtuc_is_cp_idempotency_conflict_error( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 409 ) ) ), 'F01 malformed 409 must not be a definitive conflict' );

// ---------------------------------------------------------------------------
// AUD-WOO-009 / AUD-WOO-019-F03 — durable generation-aware CP status sync
// ---------------------------------------------------------------------------

$sync_order = new WC_Order();
$sync_order->id = 55;
$sync_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 55 );
$sync_order->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '100' );
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

$sync_target = mtuc_read_cp_status_sync_target( $sync_order );
mtuc_bl_assert( is_array( $sync_target ), 'F03 durable target persisted' );
mtuc_bl_assert( 'pending' === $sync_target['state'], 'F03 transport failure keeps target pending' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === $sync_target['status_id'], 'F03 target status_id' );
mtuc_bl_assert( 1 === $sync_target['generation'], 'F03 first admission is generation 1' );
mtuc_bl_assert( 'pending' === mtuc_get_cp_status_sync_state( $sync_order ), 'F03 state accessor' );

$diag = mtuc_sanitize_cp_sync_error_category( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 401 ) ) );
mtuc_bl_assert( 'auth_401' === $diag, '401 category' );
$diag5 = mtuc_sanitize_cp_sync_error_category( new WP_Error( 'mtuc_api_http_error', 'x', array( 'status' => 502 ) ) );
mtuc_bl_assert( 'http_5xx' === $diag5, '5xx category' );
mtuc_bl_assert( false === strpos( $diag, 'Bearer' ), 'secrets in diagnostics' );
mtuc_bl_assert( 'echo_mismatch' === mtuc_sanitize_cp_sync_error_category( new WP_Error( 'mtuc_cp_patch_echo_mismatch', 'x' ) ), 'echo mismatch category' );

// Cron/manual retry is driven from the persisted target, then confirms it.
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$patch_queue[] = true;
$retry = mtuc_retry_cp_status_sync_for_order( 55 );
mtuc_bl_assert( ! is_wp_error( $retry ), 'retry failed' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'F03 retry issues exactly one PATCH' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === Mtuc_Cp_Api_Client::$patch_calls[0]['status_id'], 'F03 retry reuses persisted target' );
mtuc_bl_assert( '' === (string) $sync_order->get_meta( MTUC_ORDER_META_CP_SYNC_PENDING ), 'pending not cleared' );
mtuc_bl_assert( 'confirmed' === mtuc_get_cp_status_sync_state( $sync_order ), 'F03 validated echo confirms target' );

// Re-syncing a confirmed target is a no-op success: no second PATCH.
Mtuc_Cp_Api_Client::reset();
$dup = mtuc_sync_cp_order_bank_status( $sync_order, MTUC_BANK_STATUS_SENT_PROCESS1 );
mtuc_bl_assert( ! is_wp_error( $dup ), 'duplicate PATCH should succeed' );
mtuc_bl_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'F03 confirmed target must not re-PATCH' );

// A conflicting target while one is still in flight is rejected.
$conflict_sync = new WC_Order();
$conflict_sync->id = 56;
$conflict_sync->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '100' );
$GLOBALS['mtuc_test_orders'][56] = $conflict_sync;
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$patch_queue[] = new WP_Error( 'http_request_failed', 'timeout' );
mtuc_sync_cp_order_bank_status( $conflict_sync, MTUC_BANK_STATUS_SENT_PROCESS1 );
$rejected = mtuc_sync_cp_order_bank_status( $conflict_sync, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF );
mtuc_bl_assert( is_wp_error( $rejected ), 'F03 conflicting target must be rejected' );
mtuc_bl_assert( 'mtuc_cp_sync_semantic_conflict' === $rejected->get_error_code(), 'F03 conflict error code' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'F03 conflicting target must not PATCH' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === mtuc_read_cp_status_sync_target( $conflict_sync )['status_id'], 'F03 in-flight target preserved' );

// Terminal semantic failures stop retrying; everything else stays pending.
$terminal_sync = new WC_Order();
$terminal_sync->id = 57;
$terminal_sync->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '100' );
$GLOBALS['mtuc_test_orders'][57] = $terminal_sync;
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$patch_queue[] = mtuc_bl_cp_failure( 'unsupported_status', 422 );
$terminal_result = mtuc_sync_cp_order_bank_status( $terminal_sync, MTUC_BANK_STATUS_SENT_PROCESS1 );
mtuc_bl_assert( is_wp_error( $terminal_result ), 'F03 terminal failure errors' );
mtuc_bl_assert( 'terminal_failed' === mtuc_get_cp_status_sync_state( $terminal_sync ), 'F03 unsupported_status is terminal' );
Mtuc_Cp_Api_Client::reset();
$terminal_again = mtuc_sync_cp_order_bank_status( $terminal_sync, MTUC_BANK_STATUS_SENT_PROCESS1 );
mtuc_bl_assert( is_wp_error( $terminal_again ), 'F03 terminal target stays failed' );
mtuc_bl_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'F03 terminal target must not re-PATCH' );

mtuc_bl_assert( mtuc_is_terminal_cp_sync_error( mtuc_bl_cp_failure( 'invalid_payload', 422 ) ), 'F03 invalid_payload terminal' );
mtuc_bl_assert( mtuc_is_terminal_cp_sync_error( mtuc_bl_cp_failure( 'semantic_conflict', 409 ) ), 'F03 semantic_conflict terminal' );
mtuc_bl_assert( mtuc_is_terminal_cp_sync_error( mtuc_bl_cp_failure( 'order_not_found', 404 ) ), 'F03 order_not_found terminal' );
mtuc_bl_assert( ! mtuc_is_terminal_cp_sync_error( mtuc_bl_cp_failure( 'unauthenticated', 401 ) ), 'F03 auth stays pending' );
mtuc_bl_assert( ! mtuc_is_terminal_cp_sync_error( mtuc_bl_cp_failure( 'server_error', 503 ) ), 'F03 5xx stays pending' );
mtuc_bl_assert( ! mtuc_is_terminal_cp_sync_error( new WP_Error( 'http_request_failed', 'timeout' ) ), 'F03 transport stays pending' );
mtuc_bl_assert( ! mtuc_is_terminal_cp_sync_error( new WP_Error( 'mtuc_api_invalid_envelope', 'x' ) ), 'F03 malformed stays pending' );
mtuc_bl_assert( ! mtuc_is_terminal_cp_sync_error( new WP_Error( 'mtuc_cp_patch_echo_mismatch', 'x' ) ), 'F03 echo mismatch stays pending' );

// A decoded-but-wrong echo must not confirm the target.
$echo_sync = new WC_Order();
$echo_sync->id = 58;
$echo_sync->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '100' );
$GLOBALS['mtuc_test_orders'][58] = $echo_sync;
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$patch_queue[] = mtuc_bl_envelope(
	array(
		'id'         => 9,
		'shop_id'    => 1,
		'order_id'   => '100',
		'status_id'  => 'some_other_status',
		'status'     => 'Друг статус',
		'updated_at' => '2026-01-01T00:00:00+00:00',
	)
);
$echo_result = mtuc_sync_cp_order_bank_status( $echo_sync, MTUC_BANK_STATUS_SENT_PROCESS1 );
mtuc_bl_assert( is_wp_error( $echo_result ), 'F03 mismatched echo must error' );
mtuc_bl_assert( 'mtuc_cp_patch_echo_mismatch' === $echo_result->get_error_code(), 'F03 echo mismatch code' );
mtuc_bl_assert( 'pending' === mtuc_get_cp_status_sync_state( $echo_sync ), 'F03 decoded response alone must not confirm' );

// Stale confirmations/failures cannot cross generations.
$gen_order = new WC_Order();
$gen_order->id = 59;
$gen_order->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '100' );
$GLOBALS['mtuc_test_orders'][59] = $gen_order;
mtuc_write_cp_status_sync_target(
	$gen_order,
	array(
		'state'      => 'pending',
		'generation' => 7,
		'status_id'  => MTUC_BANK_STATUS_SENT_PROCESS1,
		'status'     => 'label',
		'error'      => '',
		'updated_at' => time(),
	)
);
mtuc_bl_assert( ! mtuc_confirm_cp_status_sync_target( $gen_order, 6, MTUC_BANK_STATUS_SENT_PROCESS1 ), 'F03 stale success cannot confirm newer generation' );
mtuc_bl_assert( 'pending' === mtuc_get_cp_status_sync_state( $gen_order ), 'F03 stale confirm left target pending' );
mtuc_bl_assert( '' === mtuc_fail_cp_status_sync_target( $gen_order, 6, MTUC_BANK_STATUS_SENT_PROCESS1, mtuc_bl_cp_failure( 'invalid_payload', 422 ) ), 'F03 stale failure cannot fail newer generation' );
mtuc_bl_assert( 'pending' === mtuc_get_cp_status_sync_state( $gen_order ), 'F03 stale failure left target pending' );
mtuc_bl_assert( mtuc_confirm_cp_status_sync_target( $gen_order, 7, MTUC_BANK_STATUS_SENT_PROCESS1 ), 'F03 matching generation confirms' );

// A malformed target record is treated as absent, never trusted.
$corrupt_target = new WC_Order();
$corrupt_target->update_meta_data( MTUC_ORDER_META_CP_SYNC_TARGET, '{not json' );
mtuc_bl_assert( null === mtuc_read_cp_status_sync_target( $corrupt_target ), 'F03 malformed target reads as absent' );
$corrupt_target->update_meta_data( MTUC_ORDER_META_CP_SYNC_TARGET, wp_json_encode( array( 'state' => 'bogus', 'generation' => 1, 'status_id' => 'x' ) ) );
mtuc_bl_assert( null === mtuc_read_cp_status_sync_target( $corrupt_target ), 'F03 unknown target state reads as absent' );

// Progression after confirmation admits a new generation rather than conflicting.
Mtuc_Cp_Api_Client::reset();
$progress = mtuc_admit_cp_status_sync_target( $gen_order, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF );
mtuc_bl_assert( ! is_wp_error( $progress ), 'F03 confirmed target allows a new intent' );
mtuc_bl_assert( 8 === $progress['generation'], 'F03 new intent bumps generation' );

$malformed = new WC_Order();
$malformed->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 1 );
$malformed->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '100' );
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
// ---------------------------------------------------------------------------
// AUD-WOO-011 F02/F03 under AUD-WOO-019-F01/F08 — unusable success and identity
// echo are ambiguous, and each of them costs exactly one POST.
// ---------------------------------------------------------------------------

mtuc_bl_assert( mtuc_is_cp_create_ambiguous_error( new WP_Error( 'mtuc_cp_unusable_success', 'x' ) ), 'unusable success not ambiguous' );
mtuc_bl_assert( mtuc_is_cp_create_ambiguous_error( new WP_Error( 'mtuc_cp_identity_mismatch', 'x' ) ), 'identity mismatch not ambiguous' );

// Success body without a usable data.id is unusable → ambiguous, one POST, frozen.
$malformed_success = new WC_Order();
$malformed_success->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_envelope(
	array(
		'order_id'   => '100',
		'unicid'     => 'SHOP-UNICID',
		'shop_id'    => 9,
		'created_at' => '2026-01-01T00:00:00+00:00',
		'id'         => 0,
	)
);
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 8801 );
$r_mal = mtuc_create_cp_order_with_recovery(
	$malformed_success,
	array( 'order_id' => '100', 'price' => 10 ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( is_wp_error( $r_mal ), 'F02 unusable 2xx must error' );
mtuc_bl_assert( 'mtuc_cp_unusable_success' === $r_mal->get_error_code(), 'F02 unusable success code' );
mtuc_bl_assert( 'unknown' === $malformed_success->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F02 unusable outcome unknown' );
mtuc_bl_assert( 0 === (int) $malformed_success->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F02 unusable must not persist CP id' );
mtuc_bl_assert( MTUC_BANK_STATUS_SEND_FAILED_CP !== (string) $malformed_success->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F02 must not persist bank_send_failed_cp' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01/F02 unusable success must POST exactly once' );

// F03: matching identity accepted (full F08 contract).
$ident_ok = new WC_Order();
$ident_ok->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 9901 );
$r_id_ok = mtuc_create_cp_order_with_recovery(
	$ident_ok,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 0 )
);
mtuc_bl_assert( ! is_wp_error( $r_id_ok ), 'F03 matching identity rejected' );
mtuc_bl_assert( 'created' === $ident_ok->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F03 match outcome' );
mtuc_bl_assert( 9901 === (int) $ident_ok->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F03 match CP id' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F03 success is exactly one POST' );

// F03: wrong order_id echo → ambiguous identity mismatch, one POST, no CP id.
$ident_bad = new WC_Order();
$ident_bad->status = 'pending';
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 9910, 'OTHER' );
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
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F01/F03 echo mismatch must POST exactly once' );
mtuc_bl_assert( '100' === (string) Mtuc_Cp_Api_Client::$create_calls[0]['order_id'], 'F03 request kept its own order_id' );

// F03: wrong unicid echo.
$ident_shop = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 9920, '100', array( 'unicid' => 'OTHER-SHOP' ) );
$r_shop_bad = mtuc_create_cp_order_with_recovery(
	$ident_shop,
	array( 'order_id' => '100' ),
	array( 'uni_proces' => 1 )
);
mtuc_bl_assert( is_wp_error( $r_shop_bad ), 'F03 unicid mismatch should error' );
mtuc_bl_assert( 'unknown' === $ident_shop->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F03 unicid mismatch outcome' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS2 !== (string) $ident_shop->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F03 unicid mismatch must not complete P2' );
mtuc_bl_assert( 0 === (int) $ident_shop->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'F03 unicid mismatch must not persist CP id' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F03 unicid mismatch exactly one POST' );

// F08 strict create contract: each guaranteed field is individually required.
$mtuc_bl_contract_cases = array(
	'order_id'   => mtuc_bl_envelope( array( 'id' => 1, 'shop_id' => 1, 'unicid' => 'SHOP-UNICID', 'created_at' => 'x' ) ),
	'unicid'     => mtuc_bl_envelope( array( 'id' => 1, 'shop_id' => 1, 'order_id' => '100', 'created_at' => 'x' ) ),
	'shop_id'    => mtuc_bl_envelope( array( 'id' => 1, 'order_id' => '100', 'unicid' => 'SHOP-UNICID', 'created_at' => 'x' ) ),
	'created_at' => mtuc_bl_envelope( array( 'id' => 1, 'shop_id' => 1, 'order_id' => '100', 'unicid' => 'SHOP-UNICID' ) ),
);
foreach ( $mtuc_bl_contract_cases as $mtuc_bl_missing => $mtuc_bl_envelope_case ) {
	$mtuc_bl_order = new WC_Order();
	Mtuc_Cp_Api_Client::reset();
	Mtuc_Cp_Api_Client::$create_queue[] = $mtuc_bl_envelope_case;
	$mtuc_bl_result = mtuc_create_cp_order_with_recovery( $mtuc_bl_order, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
	mtuc_bl_assert( is_wp_error( $mtuc_bl_result ), 'F08 create contract requires ' . $mtuc_bl_missing );
	mtuc_bl_assert( 'unknown' === $mtuc_bl_order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F08 missing ' . $mtuc_bl_missing . ' is ambiguous' );
	mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F08 missing ' . $mtuc_bl_missing . ' exactly one POST' );
}

// F08: scalar coercion is rejected — an int-typed order_id is not a string echo.
$coerced = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_envelope(
	array(
		'id'         => 9950,
		'shop_id'    => 3,
		'order_id'   => 100,
		'unicid'     => 'SHOP-UNICID',
		'created_at' => '2026-01-01T00:00:00+00:00',
	)
);
$r_coerced = mtuc_create_cp_order_with_recovery( $coerced, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_coerced ), 'F08 int order_id must not coerce to the string echo' );
mtuc_bl_assert( 'unknown' === $coerced->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'F08 coerced echo is ambiguous' );

$float_id = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 1, '100', array( 'id' => 12.0 ) );
$r_float = mtuc_create_cp_order_with_recovery( $float_id, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_float ), 'F08 float data.id must be rejected' );

$bool_id = new WC_Order();
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 1, '100', array( 'shop_id' => true ) );
$r_bool = mtuc_create_cp_order_with_recovery( $bool_id, array( 'order_id' => '100' ), array( 'uni_proces' => 0 ) );
mtuc_bl_assert( is_wp_error( $r_bool ), 'F08 bool shop_id must be rejected' );

// F03/F08: a Process 2 success admits a durable PATCH target and patches once.
$p2_durable = new WC_Order();
$p2_durable->id = 61;
$p2_durable->status = 'pending';
mtuc_persist_order_process_identity( $p2_durable, 2 );
$p2_durable->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '100' );
$GLOBALS['mtuc_test_orders'][61] = $p2_durable;
Mtuc_Cp_Api_Client::reset();
Mtuc_Cp_Api_Client::$create_queue[] = mtuc_bl_cp_success( 7100 );
$r_p2_durable = mtuc_create_cp_order_with_recovery( $p2_durable, array( 'order_id' => '100' ), array( 'uni_proces' => 1 ) );
mtuc_bl_assert( ! is_wp_error( $r_p2_durable ), 'F03 P2 create succeeded' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'F03 P2 create is exactly one POST' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS2 === $p2_durable->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F03 P2 local status recorded' );
mtuc_bl_assert( 1 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'F03 P2 status reaches CP via PATCH, not create' );
mtuc_bl_assert( MTUC_BANK_STATUS_SENT_PROCESS2 === Mtuc_Cp_Api_Client::$patch_calls[0]['status_id'], 'F03 P2 PATCH carries the target status' );
mtuc_bl_assert( 'confirmed' === mtuc_get_cp_status_sync_state( $p2_durable ), 'F03 P2 target confirmed by a valid echo' );
mtuc_bl_assert( ! array_key_exists( 'status', Mtuc_Cp_Api_Client::$create_calls[0] ), 'F08 P2 create payload carries no status' );

fwrite( STDOUT, "OK: {$mtuc_assert_count} bank lifecycle assertions passed\n" );
exit( 0 );
