<?php
/**
 * Local bank status state machine (AUD-WOO-015).
 *
 * Run: php8.1 tests/run-bank-status-state-machine-tests.php
 *
 * Contract notes:
 * - Generic numeric statuses have no documented rank; non-protected last-write-wins
 *   remains for clean identity only (EXTERNAL CONTRACT STILL REQUIRED).
 * - Unknown identity: generic 85 accepted for transport compatibility; does not
 *   establish P1/P2 identity. Protected markers fail closed.
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['mtuc_test_options'] = array();
$GLOBALS['mtuc_test_orders']  = array();
$mtuc_bssm_assert_count       = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_bssm_assert( bool $ok, string $message ): void {
	global $mtuc_bssm_assert_count;
	++$mtuc_bssm_assert_count;
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

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		unset( $domain );
		return (string) $text;
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

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option Option.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return $GLOBALS['mtuc_test_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option Option.
	 * @param mixed  $value Value.
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
	 * @param string $option Option.
	 * @return bool
	 */
	function delete_option( $option ) {
		unset( $GLOBALS['mtuc_test_options'][ $option ] );
		return true;
	}
}

if ( ! class_exists( 'WP_Error', false ) ) {
	/**
	 * Minimal WP_Error.
	 */
	class WP_Error {
		/** @var string */
		public $code;
		/** @var string */
		public $message;

		/**
		 * @param string $code Code.
		 * @param string $message Message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Thing.
	 * @return bool
	 */
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal order stand-in.
	 */
	class WC_Order {
		/** @var int */
		public $id = 1501;
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var list<string> */
		public $notes = array();

		public function get_id(): int {
			return $this->id;
		}

		public function get_payment_method(): string {
			return $this->payment_method;
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
	 * @param array<string, mixed> $args Args.
	 * @return list<WC_Order>
	 */
	function wc_get_orders( $args ) {
		$meta_key   = (string) ( $args['meta_key'] ?? '' );
		$meta_value = (string) ( $args['meta_value'] ?? '' );
		$matches    = array();
		foreach ( $GLOBALS['mtuc_test_orders'] as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( '' !== $meta_key && (string) $order->get_meta( $meta_key ) !== $meta_value ) {
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

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-submission-lock.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-error-normalizer.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-order-diagnostics.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-bank-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-process-identity.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';

/**
 * @return array<string, mixed>
 */
function mtuc_bssm_shop(): array {
	return array(
		'uni_env'                    => 0,
		'uni_sertificat'             => 0,
		'uni_test_service'           => Mtuc_Smartucf_Endpoint_Policy::SERVICE_TEST,
		'uni_production_service'     => Mtuc_Smartucf_Endpoint_Policy::SERVICE_PRODUCTION,
		'uni_test_application'       => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_TEST,
		'uni_production_application' => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_PRODUCTION,
	);
}

if ( ! function_exists( 'mtuc_get_shop_data' ) ) {
	/**
	 * @param mixed $unicid Unused.
	 * @return array<string, mixed>
	 */
	function mtuc_get_shop_data( $unicid = null ) {
		unset( $unicid );
		return mtuc_bssm_shop();
	}
}

/**
 * @param string $session_id Session.
 * @return string
 */
function mtuc_bssm_trusted_redirect( string $session_id ): string {
	return Mtuc_Smartucf_Endpoint_Policy::APPLICATION_TEST . '/' . $session_id;
}

/**
 * @param WC_Order $order Order.
 * @param int      $process Process.
 * @return void
 */
function mtuc_bssm_seed_identity( WC_Order $order, int $process ): void {
	$GLOBALS['mtuc_test_orders'][ $order->id ] = $order;
	mtuc_persist_order_process_identity( $order, $process );
	$order->save();
}

/**
 * Full trusted P1 SmartUCF success evidence (does not mutate process identity by itself).
 *
 * @param WC_Order $order Order.
 * @param int      $cp_id CP order id.
 * @param string   $session Session.
 * @return void
 */
function mtuc_bssm_seed_p1_success_evidence( WC_Order $order, int $cp_id, string $session ): void {
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', $cp_id );
	$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, $session );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', $session );
	$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, mtuc_bssm_trusted_redirect( $session ) );
	$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
}

/**
 * @param WC_Order $order Order.
 * @param int      $cp_id CP id.
 * @return void
 */
function mtuc_bssm_seed_p2_completion( WC_Order $order, int $cp_id ): void {
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', $cp_id );
	$order->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'created' );
}

mtuc_bssm_assert( function_exists( 'mtuc_is_protected_local_bank_status' ), 'protected helper present' );
mtuc_bssm_assert( mtuc_is_protected_local_bank_status( MTUC_BANK_STATUS_SENT_PROCESS1 ), 'P1 protected' );
mtuc_bssm_assert( mtuc_is_protected_local_bank_status( MTUC_BANK_STATUS_SENT_PROCESS2 ), 'P2 protected' );
mtuc_bssm_assert( mtuc_is_protected_local_bank_status( MTUC_BANK_STATUS_SEND_FAILED_CP ), 'CP fail protected' );
mtuc_bssm_assert( mtuc_is_protected_local_bank_status( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF ), 'SmartUCF fail protected' );
mtuc_bssm_assert( ! mtuc_is_protected_local_bank_status( '85' ), 'generic 85 not protected' );

// ---------------------------------------------------------------------------
// F01 — bank_send_failed_cp requires local definitive CP failure
// ---------------------------------------------------------------------------

$p2_ok = new WC_Order();
$p2_ok->id = 1501;
mtuc_bssm_seed_identity( $p2_ok, 2 );
mtuc_bssm_seed_p2_completion( $p2_ok, 8101 );
$r = mtuc_apply_cp_bank_status_push( $p2_ok, MTUC_BANK_STATUS_SENT_PROCESS2, 'P2' );
mtuc_bssm_assert( true === $r, 'F01 setup: valid P2 success' );
$r = mtuc_apply_cp_bank_status_push( $p2_ok, MTUC_BANK_STATUS_SEND_FAILED_CP, 'fail' );
mtuc_bssm_assert( is_wp_error( $r ), 'F01: P2 success → bank_send_failed_cp rejected' );
mtuc_bssm_assert( MTUC_BANK_STATUS_SENT_PROCESS2 === (string) $p2_ok->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01: P2 status unchanged' );

$p1_ok = new WC_Order();
$p1_ok->id = 1502;
mtuc_bssm_seed_identity( $p1_ok, 1 );
mtuc_bssm_seed_p1_success_evidence( $p1_ok, 8102, 'SessP1Ok' );
$r = mtuc_apply_cp_bank_status_push( $p1_ok, MTUC_BANK_STATUS_SENT_PROCESS1, 'P1' );
mtuc_bssm_assert( true === $r, 'F01 setup: valid P1 success' );
$r = mtuc_apply_cp_bank_status_push( $p1_ok, MTUC_BANK_STATUS_SEND_FAILED_CP, 'fail' );
mtuc_bssm_assert( is_wp_error( $r ), 'F01: P1 success → bank_send_failed_cp rejected' );
mtuc_bssm_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === (string) $p1_ok->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01: P1 status unchanged' );

$gen_then_fail = new WC_Order();
$gen_then_fail->id = 1503;
mtuc_bssm_seed_identity( $gen_then_fail, 1 );
$r = mtuc_apply_cp_bank_status_push( $gen_then_fail, '85', 'Отказана' );
mtuc_bssm_assert( true === $r, 'F01 setup: generic 85' );
$r = mtuc_apply_cp_bank_status_push( $gen_then_fail, MTUC_BANK_STATUS_SEND_FAILED_CP, 'fail' );
mtuc_bssm_assert( is_wp_error( $r ), 'F01: generic → bank_send_failed_cp without evidence rejected' );
mtuc_bssm_assert( '85' === (string) $gen_then_fail->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01: generic status unchanged' );

$cp_fail_ok = new WC_Order();
$cp_fail_ok->id = 1504;
mtuc_bssm_seed_identity( $cp_fail_ok, 1 );
$cp_fail_ok->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'missing' );
$r = mtuc_apply_cp_bank_status_push( $cp_fail_ok, MTUC_BANK_STATUS_SEND_FAILED_CP, 'CP fail' );
mtuc_bssm_assert( true === $r, 'F01: clean P1 + definitive CP failure evidence accepts bank_send_failed_cp' );
mtuc_bssm_assert( MTUC_BANK_STATUS_SEND_FAILED_CP === (string) $cp_fail_ok->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F01: CP failure marker persisted' );

// ---------------------------------------------------------------------------
// F03 — clean P1 required for bank_sent_process1
// ---------------------------------------------------------------------------

$p2_p1like = new WC_Order();
$p2_p1like->id = 1510;
mtuc_bssm_seed_identity( $p2_p1like, 2 );
mtuc_bssm_seed_p1_success_evidence( $p2_p1like, 8110, 'SessP2P1' );
// P2 identity + SmartUCF evidence → conflict classification.
$cls = mtuc_classify_order_process_identity( $p2_p1like );
mtuc_bssm_assert( 'conflict' === $cls['status'], 'F03 setup: P2 + SmartUCF is conflict' );
$r = mtuc_apply_cp_bank_status_push( $p2_p1like, MTUC_BANK_STATUS_SENT_PROCESS1, 'P1' );
mtuc_bssm_assert( is_wp_error( $r ), 'F03: conflict + P1-like evidence rejects bank_sent_process1' );
mtuc_bssm_assert(
	in_array( $r->get_error_code(), array( 'mtuc_callback_process_identity_conflict', 'mtuc_callback_process1_identity_required' ), true ),
	'F03: conflict error code'
);
mtuc_bssm_assert( '' === (string) $p2_p1like->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F03: conflict leaves status empty' );

// Clean P2 without SmartUCF (completion only) rejecting P1.
$p2_only = new WC_Order();
$p2_only->id = 1511;
mtuc_bssm_seed_identity( $p2_only, 2 );
mtuc_bssm_seed_p2_completion( $p2_only, 8111 );
// Add P1-like evidence without creating conflict via canonical: use temporary meta then...
// For clean P2 + fabricated P1 evidence that would conflict — already covered.
// Clean P2 alone + callback P1 without SmartUCF evidence:
$r = mtuc_apply_cp_bank_status_push( $p2_only, MTUC_BANK_STATUS_SENT_PROCESS1, 'P1' );
mtuc_bssm_assert( is_wp_error( $r ), 'F03: clean P2 rejects bank_sent_process1' );

$conf_p1 = new WC_Order();
$conf_p1->id = 1512;
$conf_p1->update_meta_data( MTUC_ORDER_META_PROCESS, 1 );
$conf_p1->update_meta_data( MTUC_ORDER_META_PROCESS2, 1 );
mtuc_bssm_seed_p1_success_evidence( $conf_p1, 8112, 'SessConf' );
$GLOBALS['mtuc_test_orders'][1512] = $conf_p1;
mtuc_bssm_assert( 'conflict' === mtuc_classify_order_process_identity( $conf_p1 )['status'], 'F03 conflict fixture' );
$r = mtuc_apply_cp_bank_status_push( $conf_p1, MTUC_BANK_STATUS_SENT_PROCESS1, 'P1' );
mtuc_bssm_assert( is_wp_error( $r ), 'F03: conflict + strong P1 evidence rejects bank_sent_process1' );

$p1_clean = new WC_Order();
$p1_clean->id = 1513;
mtuc_bssm_seed_identity( $p1_clean, 1 );
mtuc_bssm_seed_p1_success_evidence( $p1_clean, 8113, 'SessCleanP1' );
mtuc_bssm_assert( 'clean' === mtuc_classify_order_process_identity( $p1_clean )['status'], 'F03 clean P1' );
mtuc_bssm_assert( 1 === (int) mtuc_classify_order_process_identity( $p1_clean )['process'], 'F03 process=1' );
$r = mtuc_apply_cp_bank_status_push( $p1_clean, MTUC_BANK_STATUS_SENT_PROCESS1, 'P1' );
mtuc_bssm_assert( true === $r, 'F03: clean P1 + full evidence accepts bank_sent_process1' );

// ---------------------------------------------------------------------------
// F04 — identity conflict rejects every callback including generic
// ---------------------------------------------------------------------------

$conf_gen = new WC_Order();
$conf_gen->id = 1520;
$conf_gen->update_meta_data( MTUC_ORDER_META_PROCESS, 2 );
mtuc_bssm_seed_p1_success_evidence( $conf_gen, 8120, 'SessConfGen' );
$GLOBALS['mtuc_test_orders'][1520] = $conf_gen;
mtuc_bssm_assert( 'conflict' === mtuc_classify_order_process_identity( $conf_gen )['status'], 'F04 conflict fixture' );
$before = (string) $conf_gen->get_meta( MTUC_ORDER_META_BANK_STATUS );
$r      = mtuc_apply_cp_bank_status_push( $conf_gen, '85', 'Отказана' );
mtuc_bssm_assert( is_wp_error( $r ), 'F04: conflict + generic 85 rejected' );
mtuc_bssm_assert( 'mtuc_callback_process_identity_conflict' === $r->get_error_code(), 'F04 conflict code' );
mtuc_bssm_assert( $before === (string) $conf_gen->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F04: bank status unchanged' );
mtuc_bssm_assert( 2 === (int) $conf_gen->get_meta( MTUC_ORDER_META_PROCESS ), 'F04: process identity not mutated' );

// ---------------------------------------------------------------------------
// Unknown identity matrix
// ---------------------------------------------------------------------------

$unk = new WC_Order();
$unk->id = 1530;
$unk->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 8130 );
$GLOBALS['mtuc_test_orders'][1530] = $unk;
mtuc_bssm_assert( 'unknown' === mtuc_classify_order_process_identity( $unk )['status'], 'unknown fixture' );
$proc_before = (string) $unk->get_meta( MTUC_ORDER_META_PROCESS );

$r = mtuc_apply_cp_bank_status_push( $unk, '85', 'Отказана' );
mtuc_bssm_assert( true === $r, 'unknown + generic 85 accepted for compatibility' );
mtuc_bssm_assert( '85' === (string) $unk->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'unknown generic persisted' );
mtuc_bssm_assert( $proc_before === (string) $unk->get_meta( MTUC_ORDER_META_PROCESS ), 'unknown generic does not set _mtuc_process' );

foreach (
	array(
		MTUC_BANK_STATUS_SENT_PROCESS1,
		MTUC_BANK_STATUS_SENT_PROCESS2,
		MTUC_BANK_STATUS_SEND_FAILED_CP,
		MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF,
	) as $protected
) {
	$u = new WC_Order();
	$u->id = 1531 + array_search( $protected, array( MTUC_BANK_STATUS_SENT_PROCESS1, MTUC_BANK_STATUS_SENT_PROCESS2, MTUC_BANK_STATUS_SEND_FAILED_CP, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF ), true );
	$u->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 8131 );
	$GLOBALS['mtuc_test_orders'][ $u->id ] = $u;
	$r = mtuc_apply_cp_bank_status_push( $u, $protected, 'x' );
	mtuc_bssm_assert( is_wp_error( $r ), 'unknown + ' . $protected . ' rejected' );
	mtuc_bssm_assert( '' === (string) $u->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'unknown protected leaves status empty: ' . $protected );
}

// ---------------------------------------------------------------------------
// Protected transitions / cross-process
// ---------------------------------------------------------------------------

$p1_to_p2 = new WC_Order();
$p1_to_p2->id = 1540;
mtuc_bssm_seed_identity( $p1_to_p2, 1 );
mtuc_bssm_seed_p1_success_evidence( $p1_to_p2, 8140, 'SessX1' );
mtuc_apply_cp_bank_status_push( $p1_to_p2, MTUC_BANK_STATUS_SENT_PROCESS1, 'P1' );
$r = mtuc_apply_cp_bank_status_push( $p1_to_p2, MTUC_BANK_STATUS_SENT_PROCESS2, 'P2' );
mtuc_bssm_assert( is_wp_error( $r ), 'clean P1 → bank_sent_process2 rejected' );
mtuc_bssm_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === (string) $p1_to_p2->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P1 status retained' );

$p2_to_p1 = new WC_Order();
$p2_to_p1->id = 1541;
mtuc_bssm_seed_identity( $p2_to_p1, 2 );
mtuc_bssm_seed_p2_completion( $p2_to_p1, 8141 );
mtuc_apply_cp_bank_status_push( $p2_to_p1, MTUC_BANK_STATUS_SENT_PROCESS2, 'P2' );
$r = mtuc_apply_cp_bank_status_push( $p2_to_p1, MTUC_BANK_STATUS_SENT_PROCESS1, 'P1' );
mtuc_bssm_assert( is_wp_error( $r ), 'clean P2 → bank_sent_process1 rejected' );

$p1_su_fail = new WC_Order();
$p1_su_fail->id = 1542;
mtuc_bssm_seed_identity( $p1_su_fail, 1 );
mtuc_bssm_seed_p1_success_evidence( $p1_su_fail, 8142, 'SessSuFail' );
mtuc_apply_cp_bank_status_push( $p1_su_fail, MTUC_BANK_STATUS_SENT_PROCESS1, 'P1' );
$r = mtuc_apply_cp_bank_status_push( $p1_su_fail, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF, 'fail' );
mtuc_bssm_assert( is_wp_error( $r ), 'P1 success → SmartUCF failure callback rejected' );
mtuc_bssm_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === (string) $p1_su_fail->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P1 success retained vs SmartUCF fail' );

// Generic → protected without evidence (local writer).
$gen_local = new WC_Order();
$gen_local->id = 1543;
mtuc_bssm_seed_identity( $gen_local, 1 );
mtuc_apply_cp_bank_status_push( $gen_local, '85', 'Отказана' );
mtuc_record_order_bank_status( $gen_local, MTUC_BANK_STATUS_SENT_PROCESS1 );
mtuc_bssm_assert( '85' === (string) $gen_local->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'generic → protected without evidence not written locally' );

// Generic → protected WITH evidence (local recovery).
$gen_rec = new WC_Order();
$gen_rec->id = 1544;
mtuc_bssm_seed_identity( $gen_rec, 1 );
mtuc_apply_cp_bank_status_push( $gen_rec, '85', 'Отказана' );
mtuc_bssm_seed_p1_success_evidence( $gen_rec, 8144, 'SessGenRec' );
mtuc_record_order_bank_status( $gen_rec, MTUC_BANK_STATUS_SENT_PROCESS1 );
mtuc_bssm_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === (string) $gen_rec->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'generic → protected with evidence allowed' );

// ---------------------------------------------------------------------------
// Legitimate recovery
// ---------------------------------------------------------------------------

$cp_rec = new WC_Order();
$cp_rec->id = 1550;
mtuc_bssm_seed_identity( $cp_rec, 1 );
$cp_rec->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'missing' );
mtuc_apply_cp_bank_status_push( $cp_rec, MTUC_BANK_STATUS_SEND_FAILED_CP, 'fail' );
mtuc_bssm_assert( MTUC_BANK_STATUS_SEND_FAILED_CP === (string) $cp_rec->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'recovery setup CP fail' );
$cp_rec->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'created' );
mtuc_bssm_seed_p1_success_evidence( $cp_rec, 8150, 'SessCpRec' );
$r = mtuc_apply_cp_bank_status_push( $cp_rec, MTUC_BANK_STATUS_SENT_PROCESS1, 'P1' );
mtuc_bssm_assert( true === $r, 'recovery: CP failure → proven P1 success accepted' );

$su_rec = new WC_Order();
$su_rec->id = 1551;
mtuc_bssm_seed_identity( $su_rec, 1 );
$su_rec->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 8151 );
$su_rec->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'missing' );
mtuc_store_smartucf_p1_claim(
	1551,
	array(
		'state'    => MTUC_SMARTUCF_P1_CLAIM_DEFINITIVE_FAILED,
		'order_id' => 1551,
		'owner'    => 'test',
	)
);
$r = mtuc_apply_cp_bank_status_push( $su_rec, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF, 'fail' );
mtuc_bssm_assert( true === $r, 'recovery setup SmartUCF fail marker' );
mtuc_bssm_seed_p1_success_evidence( $su_rec, 8151, 'SessSuRec' );
$r = mtuc_apply_cp_bank_status_push( $su_rec, MTUC_BANK_STATUS_SENT_PROCESS1, 'P1' );
mtuc_bssm_assert( true === $r, 'recovery: SmartUCF failure → confirmed P1 success accepted' );

// P2 recovery from CP failure marker via local outcome then P2 success.
$p2_rec = new WC_Order();
$p2_rec->id = 1552;
mtuc_bssm_seed_identity( $p2_rec, 2 );
$p2_rec->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'missing' );
// Local writer may set bank_send_failed (P2) — use protected CP marker only if evidence;
// For P2, bank_send_failed_cp is rejected by callback; seed status via direct meta as prior local fail.
$p2_rec->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED_CP );
$p2_rec->update_meta_data( MTUC_ORDER_META_PREFIX . 'bank_status_label', 'fail' );
$p2_rec->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'created' );
$p2_rec->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 8152 );
$r = mtuc_apply_cp_bank_status_push( $p2_rec, MTUC_BANK_STATUS_SENT_PROCESS2, 'P2' );
mtuc_bssm_assert( true === $r, 'recovery: prior CP fail marker → proven P2 success accepted' );

// ---------------------------------------------------------------------------
// Same-status idempotency
// ---------------------------------------------------------------------------

$idem = new WC_Order();
$idem->id = 1560;
mtuc_bssm_seed_identity( $idem, 2 );
mtuc_bssm_seed_p2_completion( $idem, 8160 );
$label = mtuc_get_bank_status_label( MTUC_BANK_STATUS_SENT_PROCESS2 );
mtuc_apply_cp_bank_status_push( $idem, MTUC_BANK_STATUS_SENT_PROCESS2, $label );
$notes_n = count( $idem->notes );
mtuc_apply_cp_bank_status_push( $idem, MTUC_BANK_STATUS_SENT_PROCESS2, $label );
mtuc_bssm_assert( $notes_n === count( $idem->notes ), 'same status+label → no duplicate note' );

// Guard note idempotency on repeated reject.
$guard = new WC_Order();
$guard->id = 1561;
mtuc_bssm_seed_identity( $guard, 1 );
$n0 = count( $guard->notes );
mtuc_apply_cp_bank_status_push( $guard, MTUC_BANK_STATUS_SEND_FAILED_CP, 'x' );
mtuc_apply_cp_bank_status_push( $guard, MTUC_BANK_STATUS_SEND_FAILED_CP, 'x' );
mtuc_bssm_assert( 1 === ( count( $guard->notes ) - $n0 ), 'rejected callback adds one support note only' );
mtuc_bssm_assert( '' === (string) $guard->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'rejected does not write bank status' );

// Ambiguity: CP unknown is not bank_send_failed_cp.
$amb_cp = new WC_Order();
$amb_cp->id = 1570;
mtuc_bssm_seed_identity( $amb_cp, 1 );
$amb_cp->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'unknown' );
$r = mtuc_apply_cp_bank_status_push( $amb_cp, MTUC_BANK_STATUS_SEND_FAILED_CP, 'x' );
mtuc_bssm_assert( is_wp_error( $r ), 'CP unknown is not definitive failure' );

// ---------------------------------------------------------------------------
// F05 — REST scalar validation before cast
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Request', false ) ) {
	/**
	 * Minimal REST request.
	 */
	class WP_REST_Request {
		/** @var string */
		public $body = '';
		/** @var array<string, mixed> */
		public $json = array();
		/** @var array<string, string> */
		public $headers = array();

		public function get_body(): string {
			return $this->body;
		}

		/**
		 * @return array<string, mixed>
		 */
		public function get_json_params(): array {
			return $this->json;
		}

		/**
		 * @param string $key Header.
		 * @return string
		 */
		public function get_header( $key ) {
			$key = strtolower( str_replace( '-', '_', (string) $key ) );
			return $this->headers[ $key ] ?? '';
		}
	}
}

if ( ! class_exists( 'WP_REST_Response', false ) ) {
	/**
	 * Minimal REST response.
	 */
	class WP_REST_Response {
		/** @var mixed */
		public $data;
		/** @var int */
		public $status;

		/**
		 * @param mixed $data Data.
		 * @param int   $status Status.
		 */
		public function __construct( $data = null, $status = 200 ) {
			$this->data   = $data;
			$this->status = (int) $status;
		}

		public function get_status(): int {
			return $this->status;
		}

		/**
		 * @return mixed
		 */
		public function get_data() {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server', false ) ) {
	/**
	 * REST server constants.
	 */
	class WP_REST_Server {
		public const CREATABLE = 'POST';
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	/**
	 * @param string $path Path.
	 * @return string
	 */
	function rest_url( $path = '' ) {
		return 'https://shop.example/wp-json/' . ltrim( (string) $path, '/' );
	}
}

if ( ! class_exists( 'Mtuc_Module_Request_Authenticator', false ) ) {
	/**
	 * Auth bypass — signature suite covers auth.
	 */
	class Mtuc_Module_Request_Authenticator {
		/**
		 * @param array<string, mixed> $params Params.
		 * @param string               $raw_body Body.
		 * @param array<string, mixed> $headers Headers.
		 * @return string|WP_Error
		 */
		public static function authenticate( array $params, string $raw_body, array $headers ) {
			unset( $params, $raw_body, $headers );
			return 'TEST-UNICID';
		}
	}
}

if ( ! class_exists( 'Mtuc_Module_Request_Signature_Protocol', false ) ) {
	/**
	 * Header constants.
	 */
	class Mtuc_Module_Request_Signature_Protocol {
		public const HEADER_TIMESTAMP = 'x-unipayment-timestamp';
		public const HEADER_NONCE     = 'x-unipayment-nonce';
		public const HEADER_SIGNATURE = 'x-unipayment-signature';
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-rest-api.php';

mtuc_bssm_assert( ! Mtuc_Rest_Api::is_bank_status_field_scalar( array() ), 'array not scalar' );
mtuc_bssm_assert( ! Mtuc_Rest_Api::is_bank_status_field_scalar( (object) array( 'a' => 1 ) ), 'object not scalar' );
mtuc_bssm_assert( ! Mtuc_Rest_Api::is_bank_status_field_scalar( true ), 'bool not scalar' );
mtuc_bssm_assert( Mtuc_Rest_Api::is_bank_status_field_scalar( '85' ), 'string scalar' );
mtuc_bssm_assert( Mtuc_Rest_Api::is_bank_status_field_scalar( 85 ), 'int scalar' );

$rest_o = new WC_Order();
$rest_o->id = 1580;
mtuc_bssm_seed_identity( $rest_o, 1 );
$rest_o->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '1580' );
$GLOBALS['mtuc_test_orders'][1580] = $rest_o;

$cases = array(
	array( 'status_id' => array(), 'status' => 'x', 'label' => 'status_id array' ),
	array( 'status_id' => (object) array( 'x' => 1 ), 'status' => 'x', 'label' => 'status_id object' ),
	array( 'status_id' => '85', 'status' => array(), 'label' => 'status array' ),
	array( 'status_id' => '85', 'status_label' => (object) array( 'x' => 1 ), 'label' => 'status_label object' ),
);

foreach ( $cases as $case ) {
	$req       = new WP_REST_Request();
	$req->json = array_merge(
		array( 'order_id' => '1580' ),
		$case
	);
	unset( $req->json['label'] );
	$req->body = (string) wp_json_encode( $req->json );
	$resp      = Mtuc_Rest_Api::handle_order_bank_status_push( $req );
	mtuc_bssm_assert( 400 === $resp->get_status(), 'F05 REST reject: ' . $case['label'] );
	$data = $resp->get_data();
	$blob = (string) wp_json_encode( $data );
	mtuc_bssm_assert( false === stripos( $blob, 'Array' ) || false === strpos( $blob, '"Array"' ), 'F05 no Array cast leak: ' . $case['label'] );
	mtuc_bssm_assert( '' === (string) $rest_o->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F05 status untouched: ' . $case['label'] );
}

$over = new WP_REST_Request();
$over->json = array(
	'order_id'  => '1580',
	'status_id' => str_repeat( 'a', Mtuc_Rest_Api::BANK_STATUS_ID_MAX_LEN + 1 ),
	'status'    => 'ok',
);
$over->body = (string) wp_json_encode( $over->json );
$resp       = Mtuc_Rest_Api::handle_order_bank_status_push( $over );
mtuc_bssm_assert( 400 === $resp->get_status(), 'F05 over-length status_id rejected' );

fwrite( STDOUT, 'OK bank-status-state-machine ' . $mtuc_bssm_assert_count . ' assertions' . PHP_EOL );
