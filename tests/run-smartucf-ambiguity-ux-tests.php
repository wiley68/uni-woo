<?php
/**
 * SmartUCF ambiguity UX / callback / admin presentation (AUD-WOO-013).
 *
 * Run: php8.1 tests/run-smartucf-ambiguity-ux-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['mtuc_test_options'] = array();
$mtuc_su_amb_assert_count     = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_su_amb_assert( bool $ok, string $message ): void {
	global $mtuc_su_amb_assert_count;
	++$mtuc_su_amb_assert_count;
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

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
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

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param mixed ...$args Unused.
	 * @return true
	 */
	function add_action( ...$args ) {
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param mixed ...$args Unused.
	 * @return true
	 */
	function add_filter( ...$args ) {
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * @param string $cap Cap.
	 * @return bool
	 */
	function current_user_can( $cap ) {
		unset( $cap );
		return true;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	/**
	 * @param mixed ...$args Unused.
	 * @return bool
	 */
	function wp_schedule_single_event( ...$args ) {
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	/**
	 * @param mixed ...$args Unused.
	 * @return false
	 */
	function wp_next_scheduled( ...$args ) {
		unset( $args );
		return false;
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * @param string   $format Format.
	 * @param int|null $timestamp Timestamp.
	 * @return string
	 */
	function wp_date( $format, $timestamp = null ) {
		return gmdate( (string) $format, null === $timestamp ? time() : (int) $timestamp );
	}
}

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * @param string $option Option.
	 * @param mixed  $value Value.
	 * @param string $deprecated Deprecated.
	 * @param string $autoload Autoload.
	 * @return bool
	 */
	function add_option( $option, $value = '', $deprecated = '', $autoload = 'yes' ) {
		unset( $deprecated, $autoload );
		if ( array_key_exists( $option, $GLOBALS['mtuc_test_options'] ) ) {
			return false;
		}
		$GLOBALS['mtuc_test_options'][ $option ] = is_string( $value ) ? $value : (string) wp_json_encode( $value );
		return true;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string      $option Option.
	 * @param mixed       $value Value.
	 * @param string|null $autoload Autoload.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['mtuc_test_options'][ $option ] = is_string( $value ) ? $value : (string) wp_json_encode( $value );
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

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option Option.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return array_key_exists( $option, $GLOBALS['mtuc_test_options'] )
			? $GLOBALS['mtuc_test_options'][ $option ]
			: $default;
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal order stand-in.
	 */
	class WC_Order {
		/** @var int */
		public $id = 1301;
		/** @var string */
		public $status = 'processing';
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var list<string> */
		public $notes = array();

		public function get_id(): int {
			return $this->id;
		}

		public function get_order_number(): string {
			return (string) $this->id;
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
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-error-normalizer.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-order-diagnostics.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-presentation.php';

/**
 * @return array<string, mixed>
 */
function mtuc_su_amb_shop(): array {
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
		return mtuc_su_amb_shop();
	}
}

/**
 * @param string $session_id Session ID.
 * @return string
 */
function mtuc_su_amb_trusted_redirect( string $session_id ): string {
	return Mtuc_Smartucf_Endpoint_Policy::APPLICATION_TEST . '/' . $session_id;
}

/**
 * Seed unresolved SmartUCF ambiguity on an order.
 *
 * @param WC_Order             $order Order.
 * @param array<string, mixed> $opts Options.
 * @return void
 */
function mtuc_su_amb_seed_unresolved( WC_Order $order, array $opts = array() ): void {
	$order->id = (int) ( $opts['id'] ?? $order->id );
	$GLOBALS['mtuc_test_orders'][ $order->id ] = $order;

	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', (int) ( $opts['cp_order_id'] ?? 9001 ) );
	$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'unknown' );
	$order->update_meta_data( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE, 1 );

	if ( ! empty( $opts['with_claim'] ) ) {
		mtuc_store_smartucf_p1_claim(
			$order->id,
			array(
				'state'    => MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN,
				'order_id' => $order->id,
				'owner'    => 'test-owner',
			)
		);
	}

	if ( ! empty( $opts['with_session'] ) ) {
		$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'SessAmb01' );
		$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', 'SessAmb01' );
	}

	if ( ! empty( $opts['diagnostic'] ) ) {
		$order->update_meta_data(
			MTUC_ORDER_META_FINANCING_DIAGNOSTIC,
			wp_json_encode(
				array(
					'category'  => 'smartucf_transport',
					'subsystem' => 'smartucf',
					'retryable' => 1,
					'code'      => 'mtuc_smartucf_http_timeout',
					'ts'        => time(),
				)
			)
		);
	}

	$order->save();
}

/**
 * @param string $haystack Text.
 * @return void
 */
function mtuc_su_amb_assert_safe_customer_wording( string $haystack ): void {
	mtuc_su_amb_assert( false !== strpos( $haystack, 'неясен' ), 'customer wording must say uncertain' );
	mtuc_su_amb_assert( false !== strpos( $haystack, 'не подавайте отново' ), 'customer wording must forbid resubmit' );
	mtuc_su_amb_assert(
		false !== strpos( $haystack, 'Свържете се' ) || false !== strpos( $haystack, 'поддръж' ),
		'customer wording must direct to support'
	);
	mtuc_su_amb_assert( false === strpos( $haystack, 'опитайте по-късно' ), 'must not invite try later' );
	mtuc_su_amb_assert( false === strpos( $haystack, 'опитайте отново' ), 'must not invite try again' );
	mtuc_su_amb_assert( false === stripos( $haystack, 'rejected' ), 'must not say rejected' );
	mtuc_su_amb_assert( false === stripos( $haystack, 'declined' ), 'must not say declined' );
}

// ---------------------------------------------------------------------------
// F01 — Thank You wording + persistence across refresh
// ---------------------------------------------------------------------------

$ty = new WC_Order();
mtuc_su_amb_seed_unresolved( $ty, array( 'id' => 1310, 'with_claim' => true ) );

$first = mtuc_filter_thankyou_text_bank_unavailable( 'Thanks.', $ty );
mtuc_su_amb_assert_safe_customer_wording( $first );
mtuc_su_amb_assert( false !== strpos( $first, 'mtuc-thankyou-smartucf-ambiguous' ), 'ambiguity CSS marker on first render' );
mtuc_su_amb_assert( 1 === (int) $ty->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE ), 'notice retained after first render' );
mtuc_su_amb_assert( 'unknown' === (string) $ty->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'outcome unknown after first render' );
$claim_after_first = mtuc_get_smartucf_p1_claim( 1310 );
mtuc_su_amb_assert(
	is_array( $claim_after_first ) && MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $claim_after_first['state'],
	'sent_unknown retained after first Thank You render'
);

$second = mtuc_filter_thankyou_text_bank_unavailable( 'Thanks.', $ty );
mtuc_su_amb_assert_safe_customer_wording( $second );
mtuc_su_amb_assert( false !== strpos( $second, 'mtuc-thankyou-smartucf-ambiguous' ), 'ambiguity CSS marker on refresh' );
mtuc_su_amb_assert( 1 === (int) $ty->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE ), 'notice retained after refresh' );
mtuc_su_amb_assert( 'unknown' === (string) $ty->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'outcome unknown after refresh' );
$claim_after_second = mtuc_get_smartucf_p1_claim( 1310 );
mtuc_su_amb_assert(
	is_array( $claim_after_second ) && MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $claim_after_second['state'],
	'sent_unknown retained after refresh'
);
mtuc_su_amb_assert( mtuc_smartucf_p1_second_start_prohibited( $ty ), 'second SmartUCF start prohibited while ambiguous' );

// Outcome-only ambiguity (no claim) still persists wording.
$ty_outcome = new WC_Order();
mtuc_su_amb_seed_unresolved( $ty_outcome, array( 'id' => 1311 ) );
$out1 = mtuc_filter_thankyou_text_bank_unavailable( 'Thanks.', $ty_outcome );
$out2 = mtuc_filter_thankyou_text_bank_unavailable( 'Thanks.', $ty_outcome );
mtuc_su_amb_assert_safe_customer_wording( $out1 );
mtuc_su_amb_assert_safe_customer_wording( $out2 );
mtuc_su_amb_assert( 1 === (int) $ty_outcome->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE ), 'outcome-only notice persists' );

// Definitive bank-unavailable path still one-shot consumes notice.
$ty_def = new WC_Order();
$ty_def->id = 1312;
$ty_def->update_meta_data( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE, 1 );
$ty_def->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'missing' );
$def1 = mtuc_filter_thankyou_text_bank_unavailable( 'Thanks.', $ty_def );
mtuc_su_amb_assert( false !== strpos( $def1, 'опитайте по-късно' ), 'definitive path keeps try-later wording' );
mtuc_su_amb_assert( '' === (string) $ty_def->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE ), 'definitive notice consumed' );
$def2 = mtuc_filter_thankyou_text_bank_unavailable( 'Thanks.', $ty_def );
mtuc_su_amb_assert( 'Thanks.' === $def2, 'definitive notice gone on second render' );

// Shared Order Received filter is the Product/Cart/Classic/Blocks convergence point.
mtuc_su_amb_assert(
	function_exists( 'mtuc_filter_thankyou_text_bank_unavailable' )
	&& function_exists( 'mtuc_send_popup_bank_unavailable_response' )
	&& function_exists( 'mtuc_get_popup_order_thankyou_url' ),
	'Product/Cart popup and Thank You share production helpers'
);
$shared = new WC_Order();
mtuc_su_amb_seed_unresolved( $shared, array( 'id' => 1313, 'with_claim' => true ) );
$shared_text = mtuc_filter_thankyou_text_bank_unavailable( 'Thanks.', $shared );
mtuc_su_amb_assert_safe_customer_wording( $shared_text );
mtuc_su_amb_assert( false !== strpos( $shared_text, 'mtuc-thankyou-smartucf-ambiguous' ), 'shared Order Received ambiguity class' );

// ---------------------------------------------------------------------------
// Pass 2 — predicate with generic later status (does NOT resolve ambiguity)
// ---------------------------------------------------------------------------

$pred = new WC_Order();
mtuc_su_amb_seed_unresolved( $pred, array( 'id' => 1314, 'with_claim' => true ) );
mtuc_apply_cp_bank_status_push( $pred, '85', 'Отказана' );
mtuc_su_amb_assert( '85' === (string) $pred->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'generic 85 applied' );
mtuc_su_amb_assert( mtuc_order_has_unresolved_smartucf_ambiguity( $pred ), '85 does not resolve SmartUCF start ambiguity' );
$pred_text_1 = mtuc_filter_thankyou_text_bank_unavailable( 'Thanks.', $pred );
mtuc_su_amb_assert_safe_customer_wording( $pred_text_1 );
mtuc_su_amb_assert( 1 === (int) $pred->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE ), 'notice kept after generic status' );
$pred_text_2 = mtuc_filter_thankyou_text_bank_unavailable( 'Thanks.', $pred );
mtuc_su_amb_assert_safe_customer_wording( $pred_text_2 );
mtuc_su_amb_assert( false === strpos( $pred_text_2, 'опитайте по-късно' ), 'no try-later fallback after 85 + refresh' );
$pred_admin = mtuc_get_smartucf_ambiguity_admin_rows( $pred );
mtuc_su_amb_assert( isset( $pred_admin['SmartUCF резултат'] ), 'admin ambiguity rows remain with bank status 85' );
mtuc_su_amb_assert( 'Забранено' === $pred_admin['Автоматично повторно изпращане (SmartUCF)'], 'admin resend still prohibited with 85' );

// ---------------------------------------------------------------------------
// F02 — CP callback guards
// ---------------------------------------------------------------------------

$false_ok = new WC_Order();
mtuc_su_amb_seed_unresolved( $false_ok, array( 'id' => 1320, 'with_claim' => true ) );
$r_false_ok = mtuc_apply_cp_bank_status_push( $false_ok, MTUC_BANK_STATUS_SENT_PROCESS1, 'Изпратен' );
mtuc_su_amb_assert( is_wp_error( $r_false_ok ), 'false bank_sent_process1 rejected' );
mtuc_su_amb_assert( 'mtuc_callback_smartucf_evidence_missing' === $r_false_ok->get_error_code(), 'false success error code' );
mtuc_su_amb_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $false_ok->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'bank_sent_process1 not persisted' );
mtuc_su_amb_assert( 'unknown' === (string) $false_ok->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'ambiguity outcome unchanged after false success' );
$claim_false_ok = mtuc_get_smartucf_p1_claim( 1320 );
mtuc_su_amb_assert(
	is_array( $claim_false_ok ) && MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $claim_false_ok['state'],
	'sent_unknown retained after false success callback'
);
$joined_notes = implode( "\n", $false_ok->notes );
mtuc_su_amb_assert( false !== strpos( $joined_notes, 'не е приложен' ), 'diagnostic note for rejected success callback' );
mtuc_su_amb_assert( false === strpos( $joined_notes, 'Статус към банката:' ), 'no success bank-status note on rejected callback' );

// Repeated invalid protected success callback — note once.
$r_false_ok_2 = mtuc_apply_cp_bank_status_push( $false_ok, MTUC_BANK_STATUS_SENT_PROCESS1, 'Изпратен' );
mtuc_su_amb_assert( is_wp_error( $r_false_ok_2 ), 'second false bank_sent_process1 rejected' );
$success_note_count = 0;
foreach ( $false_ok->notes as $n ) {
	if ( false !== strpos( $n, 'bank_sent_process1 не е приложен' ) ) {
		++$success_note_count;
	}
}
mtuc_su_amb_assert( 1 === $success_note_count, 'rejected bank_sent_process1 note is idempotent' );

$false_fail = new WC_Order();
mtuc_su_amb_seed_unresolved( $false_fail, array( 'id' => 1321, 'with_claim' => true ) );
$r_false_fail = mtuc_apply_cp_bank_status_push( $false_fail, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF, 'Неуспех' );
mtuc_su_amb_assert( is_wp_error( $r_false_fail ), 'false bank_send_failed_smartucf rejected' );
mtuc_su_amb_assert(
	'mtuc_callback_smartucf_failure_evidence_missing' === $r_false_fail->get_error_code(),
	'false failure error code'
);
mtuc_su_amb_assert(
	MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $false_fail->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'bank_send_failed_smartucf not persisted over ambiguity'
);
mtuc_su_amb_assert( 'unknown' === (string) $false_fail->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'ambiguity unchanged after false failure' );

// Partial success evidence must fail closed.
$partial_cases = array(
	'session_only'           => static function ( WC_Order $o ): void {
		$o->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9101 );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'SessPart1' );
		$o->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', 'SessPart1' );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'unknown' );
	},
	'redirect_only'          => static function ( WC_Order $o ): void {
		$o->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9102 );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, mtuc_su_amb_trusted_redirect( 'SessPart2' ) );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
	},
	'session_redirect_unknown' => static function ( WC_Order $o ): void {
		$o->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9103 );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'SessPart3' );
		$o->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', 'SessPart3' );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, mtuc_su_amb_trusted_redirect( 'SessPart3' ) );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'unknown' );
	},
	'confirmed_no_session'   => static function ( WC_Order $o ): void {
		$o->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9104 );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, mtuc_su_amb_trusted_redirect( 'SessPart4' ) );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
	},
	'confirmed_untrusted'    => static function ( WC_Order $o ): void {
		$o->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9105 );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'SessPart5' );
		$o->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', 'SessPart5' );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, 'https://evil.example/phish/SessPart5' );
		$o->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
	},
);

$partial_id = 1340;
foreach ( $partial_cases as $label => $seeder ) {
	$partial = new WC_Order();
	$partial->id = $partial_id;
	$GLOBALS['mtuc_test_orders'][ $partial_id ] = $partial;
	$seeder( $partial );
	mtuc_su_amb_assert(
		! mtuc_order_has_process1_smartucf_success_evidence( $partial ),
		'partial evidence not enough: ' . $label
	);
	$r_partial = mtuc_apply_cp_bank_status_push( $partial, MTUC_BANK_STATUS_SENT_PROCESS1, 'Изпратен' );
	mtuc_su_amb_assert( is_wp_error( $r_partial ), 'callback rejects partial evidence: ' . $label );
	mtuc_su_amb_assert(
		MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $partial->get_meta( MTUC_ORDER_META_BANK_STATUS ),
		'partial evidence does not persist bank_sent_process1: ' . $label
	);
	++$partial_id;
}

// Legitimate bank_sent_process1 when local trusted evidence is complete.
$legit = new WC_Order();
$legit->id = 1322;
$GLOBALS['mtuc_test_orders'][1322] = $legit;
$legit->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9002 );
$legit->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'SessLegit' );
$legit->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', 'SessLegit' );
$legit->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, mtuc_su_amb_trusted_redirect( 'SessLegit' ) );
$legit->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
mtuc_su_amb_assert( mtuc_order_has_process1_smartucf_success_evidence( $legit ), 'local trusted P1 evidence present' );
$r_legit = mtuc_apply_cp_bank_status_push( $legit, MTUC_BANK_STATUS_SENT_PROCESS1, mtuc_get_bank_status_label( MTUC_BANK_STATUS_SENT_PROCESS1 ) );
mtuc_su_amb_assert( true === $r_legit, 'legitimate bank_sent_process1 accepted' );
mtuc_su_amb_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === (string) $legit->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'legitimate status persisted' );
mtuc_su_amb_assert( ! mtuc_order_has_unresolved_smartucf_ambiguity( $legit ), 'confirmed+bank_sent resolves ambiguity' );

// Session + recoverable redirect (empty redirect meta) still counts as evidence.
$recoverable = new WC_Order();
$recoverable->id = 1324;
$GLOBALS['mtuc_test_orders'][1324] = $recoverable;
$recoverable->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9003 );
$recoverable->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'SessRecover' );
$recoverable->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', 'SessRecover' );
$recoverable->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
mtuc_su_amb_assert(
	mtuc_order_has_trusted_or_recoverable_smartucf_redirect( $recoverable ),
	'recoverable trusted redirect from session'
);
mtuc_su_amb_assert( mtuc_order_has_process1_smartucf_success_evidence( $recoverable ), 'recoverable redirect evidence accepted' );
$r_recoverable = mtuc_apply_cp_bank_status_push(
	$recoverable,
	MTUC_BANK_STATUS_SENT_PROCESS1,
	mtuc_get_bank_status_label( MTUC_BANK_STATUS_SENT_PROCESS1 )
);
mtuc_su_amb_assert( true === $r_recoverable, 'callback accepted for recoverable redirect evidence' );

// Pass 3 — session A / trusted redirect B mismatch must fail closed.
$mismatch = new WC_Order();
$mismatch->id = 1360;
$GLOBALS['mtuc_test_orders'][1360] = $mismatch;
mtuc_store_smartucf_p1_claim(
	1360,
	array(
		'state'    => MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN,
		'order_id' => 1360,
		'owner'    => 'test-owner',
	)
);
$mismatch->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9601 );
$mismatch->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'SessAlpha' );
$mismatch->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', 'SessAlpha' );
$mismatch->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, mtuc_su_amb_trusted_redirect( 'SessBeta' ) );
$mismatch->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
$mismatch->update_meta_data( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE, 1 );
mtuc_su_amb_assert(
	Mtuc_Smartucf_Api_Client::is_trusted_redirect_url( mtuc_su_amb_trusted_redirect( 'SessAlpha' ), mtuc_su_amb_shop() ),
	'mismatch fixture: redirect A individually trusted'
);
mtuc_su_amb_assert(
	Mtuc_Smartucf_Api_Client::is_trusted_redirect_url( mtuc_su_amb_trusted_redirect( 'SessBeta' ), mtuc_su_amb_shop() ),
	'mismatch fixture: redirect B individually trusted'
);
mtuc_su_amb_assert(
	! is_wp_error( Mtuc_Smartucf_Endpoint_Policy::validate_session_id( 'SessAlpha' ) ),
	'mismatch fixture: session A individually valid'
);
mtuc_su_amb_assert(
	! is_wp_error( Mtuc_Smartucf_Endpoint_Policy::validate_session_id( 'SessBeta' ) ),
	'mismatch fixture: session B individually valid'
);
mtuc_su_amb_assert(
	! mtuc_order_has_process1_smartucf_success_evidence( $mismatch ),
	'session A + redirect B is not coherent P1 evidence'
);
$bank_before_mismatch = (string) $mismatch->get_meta( MTUC_ORDER_META_BANK_STATUS );
$notes_before_mismatch = count( $mismatch->notes );
$r_mismatch = mtuc_apply_cp_bank_status_push( $mismatch, MTUC_BANK_STATUS_SENT_PROCESS1, 'Изпратен' );
mtuc_su_amb_assert( is_wp_error( $r_mismatch ), 'callback rejects session/redirect identity mismatch' );
mtuc_su_amb_assert( $bank_before_mismatch === (string) $mismatch->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'mismatch leaves bank status unchanged' );
mtuc_su_amb_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $mismatch->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'mismatch does not write bank_sent_process1' );
mtuc_su_amb_assert( 'confirmed' === (string) $mismatch->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'mismatch leaves outcome unchanged' );
$claim_mismatch = mtuc_get_smartucf_p1_claim( 1360 );
mtuc_su_amb_assert(
	is_array( $claim_mismatch ) && MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $claim_mismatch['state'],
	'mismatch leaves sent_unknown claim unchanged'
);
$success_notes_mismatch = 0;
foreach ( $mismatch->notes as $n ) {
	if ( false !== strpos( $n, 'Статус към банката:' ) ) {
		++$success_notes_mismatch;
	}
}
mtuc_su_amb_assert( 0 === $success_notes_mismatch, 'mismatch adds no success bank-status note' );
mtuc_su_amb_assert( count( $mismatch->notes ) >= $notes_before_mismatch, 'mismatch may add guard diagnostic only' );

// Pass 3 — invalid stored session + otherwise trusted redirect.
$bad_sess = new WC_Order();
$bad_sess->id = 1361;
$GLOBALS['mtuc_test_orders'][1361] = $bad_sess;
$bad_sess->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9602 );
$bad_sess->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'bad/session' );
$bad_sess->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', 'bad/session' );
$bad_sess->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, mtuc_su_amb_trusted_redirect( 'SessValidRedirect' ) );
$bad_sess->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
mtuc_su_amb_assert(
	is_wp_error( Mtuc_Smartucf_Endpoint_Policy::validate_session_id( 'bad/session' ) ),
	'invalid session fixture uses policy-invalid session'
);
mtuc_su_amb_assert(
	Mtuc_Smartucf_Api_Client::is_trusted_redirect_url( mtuc_su_amb_trusted_redirect( 'SessValidRedirect' ), mtuc_su_amb_shop() ),
	'invalid session fixture redirect remains individually trusted'
);
mtuc_su_amb_assert(
	! mtuc_order_has_process1_smartucf_success_evidence( $bad_sess ),
	'invalid session cannot be rescued by trusted redirect'
);
$bank_before_bad = (string) $bad_sess->get_meta( MTUC_ORDER_META_BANK_STATUS );
$r_bad_sess = mtuc_apply_cp_bank_status_push( $bad_sess, MTUC_BANK_STATUS_SENT_PROCESS1, 'Изпратен' );
mtuc_su_amb_assert( is_wp_error( $r_bad_sess ), 'callback rejects invalid session + trusted redirect' );
mtuc_su_amb_assert( $bank_before_bad === (string) $bad_sess->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'invalid session leaves bank status unchanged' );
mtuc_su_amb_assert( 'confirmed' === (string) $bad_sess->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'invalid session leaves outcome unchanged' );

// Genuine later bank status still applies over unresolved ambiguity (without resolving it).
$later = new WC_Order();
mtuc_su_amb_seed_unresolved( $later, array( 'id' => 1323, 'with_claim' => true ) );
$r_later = mtuc_apply_cp_bank_status_push( $later, '85', 'Отказана' );
mtuc_su_amb_assert( true === $r_later, 'later status 85 accepted on ambiguous order' );
mtuc_su_amb_assert( '85' === (string) $later->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'later status 85 persisted' );
mtuc_su_amb_assert( 'unknown' === (string) $later->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'outcome meta not cleared by later status alone' );
mtuc_su_amb_assert( mtuc_order_has_unresolved_smartucf_ambiguity( $later ), 'later bank status does not clear start ambiguity' );
$claim_later = mtuc_get_smartucf_p1_claim( 1323 );
mtuc_su_amb_assert(
	is_array( $claim_later ) && MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $claim_later['state'],
	'sent_unknown claim retained after later status (no claim rewrite)'
);

// Generic status then protected failure — must reject and keep ambiguity.
$r_fail_after = mtuc_apply_cp_bank_status_push( $later, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF, 'Неуспех' );
mtuc_su_amb_assert( is_wp_error( $r_fail_after ), 'bank_send_failed_smartucf rejected after generic 85' );
mtuc_su_amb_assert( '85' === (string) $later->get_meta( MTUC_ORDER_META_BANK_STATUS ), '85 retained; failure not written' );
mtuc_su_amb_assert( 'unknown' === (string) $later->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'outcome remains unknown after protected failure reject' );
mtuc_su_amb_assert( mtuc_order_has_unresolved_smartucf_ambiguity( $later ), 'ambiguity retained after protected failure reject' );
$fail_notes = 0;
foreach ( $later->notes as $n ) {
	if ( false !== strpos( $n, 'bank_send_failed_smartucf не е приложен' ) ) {
		++$fail_notes;
	}
	if ( false !== strpos( $n, 'Статус към банката:' ) && false !== stripos( $n, 'Неуспешно изпратен Банка - SmartUCF' ) ) {
		mtuc_su_amb_assert( false, 'must not add definitive SmartUCF failure status note' );
	}
}
mtuc_su_amb_assert( 1 === $fail_notes, 'one protective failure diagnostic note' );
$r_fail_after_2 = mtuc_apply_cp_bank_status_push( $later, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF, 'Неуспех' );
mtuc_su_amb_assert( is_wp_error( $r_fail_after_2 ), 'second protected failure still rejected' );
$fail_notes_2 = 0;
foreach ( $later->notes as $n ) {
	if ( false !== strpos( $n, 'bank_send_failed_smartucf не е приложен' ) ) {
		++$fail_notes_2;
	}
}
mtuc_su_amb_assert( 1 === $fail_notes_2, 'protected failure diagnostic note is idempotent' );

// Local definitive failure evidence allows idempotent callback apply.
$def_fail = new WC_Order();
$def_fail->id = 1325;
$GLOBALS['mtuc_test_orders'][1325] = $def_fail;
$def_fail->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9004 );
$def_fail->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'missing' );
$def_fail->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF );
$def_fail->update_meta_data(
	MTUC_ORDER_META_PREFIX . 'bank_status_label',
	mtuc_get_bank_status_label( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF )
);
mtuc_su_amb_assert( mtuc_order_has_definitive_smartucf_failure_evidence( $def_fail ), 'local definitive failure evidence present' );
$r_def = mtuc_apply_cp_bank_status_push(
	$def_fail,
	MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF,
	mtuc_get_bank_status_label( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF )
);
mtuc_su_amb_assert( true === $r_def, 'idempotent definitive failure callback accepted' );

// ---------------------------------------------------------------------------
// REST-level callback path (post-auth production branch + signed harness note)
// ---------------------------------------------------------------------------

if ( ! class_exists( 'WP_REST_Request', false ) ) {
	/**
	 * Minimal REST request stand-in.
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
		 * @param string $key Header key.
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
	 * Minimal REST response stand-in.
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
	 * Minimal REST server constants.
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

if ( ! function_exists( 'wc_get_orders' ) ) {
	/**
	 * @param array<string, mixed> $args Args.
	 * @return list<WC_Order>
	 */
	function wc_get_orders( $args ) {
		$meta_key   = (string) ( $args['meta_key'] ?? '' );
		$meta_value = (string) ( $args['meta_value'] ?? '' );
		foreach ( $GLOBALS['mtuc_test_orders'] as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			if ( $meta_key && (string) $order->get_meta( $meta_key ) === $meta_value ) {
				return array( $order );
			}
		}
		return array();
	}
}

if ( ! class_exists( 'Mtuc_Module_Request_Authenticator', false ) ) {
	/**
	 * Auth bypass for REST business-path coverage (signature suite covers auth separately).
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
	 * Minimal header-name constants for REST extract_signature_headers().
	 */
	class Mtuc_Module_Request_Signature_Protocol {
		public const HEADER_TIMESTAMP = 'x-unipayment-timestamp';
		public const HEADER_NONCE     = 'x-unipayment-nonce';
		public const HEADER_SIGNATURE = 'x-unipayment-signature';
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-rest-api.php';

$rest_order = new WC_Order();
mtuc_su_amb_seed_unresolved( $rest_order, array( 'id' => 1350, 'with_claim' => true, 'cp_order_id' => 9550 ) );
$rest_order->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '1350' );
$rest_order->save();

$rest_req = new WP_REST_Request();
$rest_req->json = array(
	'order_id'  => '1350',
	'status'    => 'Изпратен',
	'status_id' => MTUC_BANK_STATUS_SENT_PROCESS1,
);
$rest_req->body = (string) wp_json_encode( $rest_req->json );
$rest_resp      = Mtuc_Rest_Api::handle_order_bank_status_push( $rest_req );
mtuc_su_amb_assert( 400 === $rest_resp->get_status(), 'REST rejects bank_sent_process1 without evidence' );
$rest_data = $rest_resp->get_data();
mtuc_su_amb_assert( is_array( $rest_data ) && empty( $rest_data['success'] ), 'REST controlled rejection payload' );
mtuc_su_amb_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $rest_order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'REST path leaves bank status unchanged' );
mtuc_su_amb_assert( mtuc_order_has_unresolved_smartucf_ambiguity( $rest_order ), 'REST path preserves ambiguity' );

// ---------------------------------------------------------------------------
// F03 — admin ambiguity presentation
// ---------------------------------------------------------------------------

$admin = new WC_Order();
mtuc_su_amb_seed_unresolved(
	$admin,
	array(
		'id'         => 1330,
		'with_claim' => true,
		'diagnostic' => true,
	)
);

$amb_rows = mtuc_get_smartucf_ambiguity_admin_rows( $admin );
mtuc_su_amb_assert( isset( $amb_rows['SmartUCF резултат'] ) && 'Неизвестен' === $amb_rows['SmartUCF резултат'], 'admin SmartUCF outcome unknown' );
mtuc_su_amb_assert(
	isset( $amb_rows['Автоматично повторно изпращане (SmartUCF)'] )
	&& 'Забранено' === $amb_rows['Автоматично повторно изпращане (SmartUCF)'],
	'admin automatic resend prohibited'
);
mtuc_su_amb_assert(
	isset( $amb_rows['Препоръчано действие'] )
	&& false !== strpos( $amb_rows['Препоръчано действие'], 'Не изпращайте отново' ),
	'admin support/reconciliation action'
);
mtuc_su_amb_assert( 'Липсва' === $amb_rows['SmartUCF сесия'], 'admin session absent' );
mtuc_su_amb_assert( 'Да' === $amb_rows['КП поръчка'], 'admin CP order present' );

$panel = mtuc_get_order_credit_meta_rows( $admin, MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL );
mtuc_su_amb_assert( isset( $panel['SmartUCF резултат'] ), 'admin panel merges ambiguity rows' );
mtuc_su_amb_assert( ! isset( $panel['Повторение възможно'] ), 'Retry possible not shown for SmartUCF ambiguity' );
$panel_json = (string) wp_json_encode( $panel );
mtuc_su_amb_assert( false === strpos( $panel_json, 'Повторение възможно' ), 'Retry possible string absent from admin panel' );

$admin_sess = new WC_Order();
mtuc_su_amb_seed_unresolved(
	$admin_sess,
	array(
		'id'           => 1331,
		'with_claim'   => true,
		'with_session' => true,
	)
);
$rows_sess = mtuc_get_smartucf_ambiguity_admin_rows( $admin_sess );
mtuc_su_amb_assert( 'Налична' === $rows_sess['SmartUCF сесия'], 'admin session available when present' );
mtuc_su_amb_assert( isset( $rows_sess['Локално възстановяване'] ), 'admin recovery guidance present' );
mtuc_su_amb_assert(
	false !== strpos( $rows_sess['Локално възстановяване'], 'преизползва' )
	&& false !== strpos( $rows_sess['Локално възстановяване'], 'не изпращайте отново' ),
	'admin recovery guidance forbids resend'
);
mtuc_su_amb_assert( false === strpos( (string) wp_json_encode( $rows_sess ), 'SessAmb01' ), 'admin does not expose raw session id' );

// Confirmed success — no ambiguity admin rows.
$ok_admin = new WC_Order();
$ok_admin->id = 1332;
$ok_admin->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 1 );
$ok_admin->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS1 );
$ok_admin->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'SessOK' );
$ok_admin->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', 'SessOK' );
$ok_admin->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, mtuc_su_amb_trusted_redirect( 'SessOK' ) );
$ok_admin->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
mtuc_su_amb_assert( array() === mtuc_get_smartucf_ambiguity_admin_rows( $ok_admin ), 'confirmed success has no ambiguity rows' );

// Definitive SmartUCF failure — no ambiguity admin rows.
$fail_admin = new WC_Order();
$fail_admin->id = 1333;
$fail_admin->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 1 );
$fail_admin->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF );
$fail_admin->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'missing' );
mtuc_su_amb_assert( array() === mtuc_get_smartucf_ambiguity_admin_rows( $fail_admin ), 'definitive failure has no ambiguity rows' );
mtuc_su_amb_assert( ! mtuc_order_has_unresolved_smartucf_ambiguity( $fail_admin ), 'definitive failure not unresolved ambiguity' );
mtuc_su_amb_assert( ! mtuc_order_has_unresolved_smartucf_ambiguity( $ok_admin ), 'confirmed not unresolved ambiguity' );

fwrite( STDOUT, 'OK smartucf-ambiguity-ux ' . $mtuc_su_amb_assert_count . " assertions\n" );
exit( 0 );
