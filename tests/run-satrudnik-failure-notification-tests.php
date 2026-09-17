<?php
/**
 * Focused tests: Satrudnik bank-failure notification mail.
 *
 * Run: php8.1 tests/run-satrudnik-failure-notification-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_sn_assert_count = 0;
$mtuc_sn_mail_log     = array();
$mtuc_sn_mail_result  = true;
$mtuc_sn_shop         = array(
	'satrudnik_email' => 'employee@example.com',
	'uni_email'       => 'owner-fallback@example.com',
);

/**
 * @param bool   $ok  Condition.
 * @param string $msg Failure message.
 * @return void
 */
function mtuc_sn_assert( bool $ok, string $msg ): void {
	global $mtuc_sn_assert_count;
	++$mtuc_sn_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $msg . PHP_EOL );
		exit( 1 );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return bool
	 */
	function is_email( $email ) {
		return is_string( $email ) && false !== strpos( $email, '@' ) && false !== strpos( $email, '.' );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return string
	 */
	function sanitize_email( $email ) {
		return is_string( $email ) ? trim( $email ) : '';
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
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

if ( ! function_exists( 'get_bloginfo' ) ) {
	/**
	 * @param string $show Show.
	 * @return string
	 */
	function get_bloginfo( $show = '' ) {
		unset( $show );
		return 'Test Shop';
	}
}

if ( ! function_exists( 'wp_specialchars_decode' ) ) {
	/**
	 * @param string $text Text.
	 * @param int    $quote_style Quote style.
	 * @return string
	 */
	function wp_specialchars_decode( $text, $quote_style = ENT_QUOTES ) {
		unset( $quote_style );
		return html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'wc_mail' ) ) {
	/**
	 * @param string       $to To.
	 * @param string       $subject Subject.
	 * @param string       $message Message.
	 * @param string|array $headers Headers.
	 * @return bool
	 */
	function wc_mail( $to, $subject, $message, $headers = '' ) {
		global $mtuc_sn_mail_log, $mtuc_sn_mail_result;
		$mtuc_sn_mail_log[] = array(
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
			'headers' => $headers,
		);
		return (bool) $mtuc_sn_mail_result;
	}
}

if ( ! function_exists( 'mtuc_get_shop_data' ) ) {
	/**
	 * @param mixed $unicid Unused.
	 * @return array<string, mixed>|WP_Error
	 */
	function mtuc_get_shop_data( $unicid = null ) {
		unset( $unicid );
		global $mtuc_sn_shop;
		return $mtuc_sn_shop;
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal order stand-in.
	 */
	class WC_Order {
		/** @var int */
		public $id = 1001;
		/** @var string */
		public $status = 'on-hold';
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var DateTimeImmutable|null */
		public $created = null;

		public function get_id(): int {
			return $this->id;
		}

		public function get_order_number(): string {
			return (string) $this->id;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function get_payment_method(): string {
			return $this->payment_method;
		}

		/**
		 * @return DateTimeImmutable|null
		 */
		public function get_date_created() {
			return $this->created;
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
		}

		/**
		 * @param array<int, string> $statuses Statuses.
		 * @return bool
		 */
		public function has_status( $statuses ): bool {
			return in_array( $this->status, (array) $statuses, true );
		}

		/**
		 * @param string $method Method.
		 * @return void
		 */
		public function set_payment_method( $method ): void {
			$this->payment_method = (string) $method;
		}

		/**
		 * @param string $title Title.
		 * @return void
		 */
		public function set_payment_method_title( $title ): void {
			unset( $title );
		}

		/**
		 * @param string $status Status.
		 * @param string $note Note.
		 * @return void
		 */
		public function update_status( $status, $note = '' ): void {
			unset( $note );
			$this->status = (string) $status;
		}

		/**
		 * @param string $note Note.
		 * @return void
		 */
		public function add_order_note( $note ): void {
			unset( $note );
		}
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-email.php';

/**
 * @param string               $status_id Bank status id.
 * @param array<string, mixed> $extra Extra meta.
 * @return WC_Order
 */
function mtuc_sn_order( string $status_id, array $extra = array() ): WC_Order {
	$order          = new WC_Order();
	$order->created = new DateTimeImmutable( '2026-04-01 10:15:00' );
	$order->update_meta_data( MTUC_ORDER_META_BANK_STATUS, $status_id );
	$order->update_meta_data(
		MTUC_ORDER_META_PREFIX . 'bank_status_label',
		mtuc_get_bank_status_label( $status_id )
	);
	foreach ( $extra as $key => $value ) {
		$order->update_meta_data( $key, $value );
	}
	return $order;
}

/**
 * @return void
 */
function mtuc_sn_reset_mail(): void {
	global $mtuc_sn_mail_log, $mtuc_sn_mail_result, $mtuc_sn_shop;
	$mtuc_sn_mail_log    = array();
	$mtuc_sn_mail_result = true;
	$mtuc_sn_shop        = array(
		'satrudnik_email' => 'employee@example.com',
		'uni_email'       => 'owner-fallback@example.com',
	);
}

// ---------------------------------------------------------------------------
// A. CP failure → mail once + marker
// ---------------------------------------------------------------------------

mtuc_sn_reset_mail();
$a = mtuc_sn_order( MTUC_BANK_STATUS_SEND_FAILED_CP );
mtuc_maybe_notify_satrudnik_bank_send_failure( $a );
mtuc_sn_assert( 1 === count( $mtuc_sn_mail_log ), 'A: one mail sent for bank_send_failed_cp' );
mtuc_sn_assert( 'employee@example.com' === $mtuc_sn_mail_log[0]['to'], 'A: recipient is satrudnik_email' );
mtuc_sn_assert(
	false !== strpos( $mtuc_sn_mail_log[0]['subject'], '1001' ),
	'A: subject includes order number'
);
mtuc_sn_assert(
	false !== strpos( $mtuc_sn_mail_log[0]['message'], 'Неуспешно изпратен Банка - КП' ),
	'A: body includes public label'
);
mtuc_sn_assert(
	false !== strpos( $mtuc_sn_mail_log[0]['message'], 'bank_send_failed_cp' ),
	'A: body includes status id'
);
mtuc_sn_assert(
	1 === (int) $a->get_meta( MTUC_ORDER_META_SATRUDNIK_FAILURE_NOTIFICATION_SENT ),
	'A: marker set after successful send'
);

// ---------------------------------------------------------------------------
// B. SmartUCF failure → includes CP order id
// ---------------------------------------------------------------------------

mtuc_sn_reset_mail();
$b = mtuc_sn_order(
	MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF,
	array( MTUC_ORDER_META_PREFIX . 'cp_order_id' => 7788 )
);
mtuc_maybe_notify_satrudnik_bank_send_failure( $b );
mtuc_sn_assert( 1 === count( $mtuc_sn_mail_log ), 'B: mail sent for bank_send_failed_smartucf' );
mtuc_sn_assert(
	false !== strpos( $mtuc_sn_mail_log[0]['message'], '7788' ),
	'B: body contains CP order id'
);
mtuc_sn_assert(
	false !== strpos( $mtuc_sn_mail_log[0]['message'], 'Неуспешно изпратен Банка - SmartUCF' ),
	'B: public SmartUCF label present'
);
mtuc_sn_assert(
	1 === (int) $b->get_meta( MTUC_ORDER_META_SATRUDNIK_FAILURE_NOTIFICATION_SENT ),
	'B: marker set'
);

// ---------------------------------------------------------------------------
// C. Missing recipient → no mail / no marker
// ---------------------------------------------------------------------------

mtuc_sn_reset_mail();
$mtuc_sn_shop = array(
	'satrudnik_email' => null,
	'uni_email'       => 'owner-fallback@example.com',
);
$c = mtuc_sn_order( MTUC_BANK_STATUS_SEND_FAILED_CP );
mtuc_maybe_notify_satrudnik_bank_send_failure( $c );
mtuc_sn_assert( 0 === count( $mtuc_sn_mail_log ), 'C: no mail when satrudnik_email null' );
mtuc_sn_assert(
	'' === (string) $c->get_meta( MTUC_ORDER_META_SATRUDNIK_FAILURE_NOTIFICATION_SENT )
		|| 0 === (int) $c->get_meta( MTUC_ORDER_META_SATRUDNIK_FAILURE_NOTIFICATION_SENT ),
	'C: marker absent'
);

// ---------------------------------------------------------------------------
// D. Non-target statuses
// ---------------------------------------------------------------------------

foreach (
	array(
		MTUC_BANK_STATUS_SENT_PROCESS1,
		MTUC_BANK_STATUS_SENT_PROCESS2,
		MTUC_BANK_STATUS_SEND_FAILED,
		'',
	) as $non_target
) {
	mtuc_sn_reset_mail();
	$d = mtuc_sn_order( $non_target );
	mtuc_maybe_notify_satrudnik_bank_send_failure( $d );
	mtuc_sn_assert( 0 === count( $mtuc_sn_mail_log ), 'D: no mail for status ' . $non_target );
}

// ---------------------------------------------------------------------------
// E. Duplicate protection
// ---------------------------------------------------------------------------

mtuc_sn_reset_mail();
$e = mtuc_sn_order( MTUC_BANK_STATUS_SEND_FAILED_CP );
mtuc_maybe_notify_satrudnik_bank_send_failure( $e );
mtuc_maybe_notify_satrudnik_bank_send_failure( $e );
mtuc_sn_assert( 1 === count( $mtuc_sn_mail_log ), 'E: second call does not send again' );

// ---------------------------------------------------------------------------
// F. Mail failure → no marker, status unchanged
// ---------------------------------------------------------------------------

mtuc_sn_reset_mail();
$mtuc_sn_mail_result = false;
$f                   = mtuc_sn_order( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF );
$f_status_before     = $f->get_meta( MTUC_ORDER_META_BANK_STATUS );
$f_wc_before         = $f->get_status();
mtuc_maybe_notify_satrudnik_bank_send_failure( $f );
mtuc_sn_assert( 1 === count( $mtuc_sn_mail_log ), 'F: mail attempted' );
mtuc_sn_assert(
	'' === (string) $f->get_meta( MTUC_ORDER_META_SATRUDNIK_FAILURE_NOTIFICATION_SENT )
		|| 0 === (int) $f->get_meta( MTUC_ORDER_META_SATRUDNIK_FAILURE_NOTIFICATION_SENT ),
	'F: marker not set on mail failure'
);
mtuc_sn_assert( $f_status_before === $f->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'F: bank status unchanged' );
mtuc_sn_assert( $f_wc_before === $f->get_status(), 'F: WC status unchanged' );

// ---------------------------------------------------------------------------
// G. No uni_email fallback
// ---------------------------------------------------------------------------

mtuc_sn_reset_mail();
$mtuc_sn_shop = array(
	'satrudnik_email' => null,
	'uni_email'       => 'owner-fallback@example.com',
);
$g = mtuc_sn_order( MTUC_BANK_STATUS_SEND_FAILED_CP );
mtuc_maybe_notify_satrudnik_bank_send_failure( $g );
mtuc_sn_assert( 0 === count( $mtuc_sn_mail_log ), 'G: no mail to uni_email fallback' );

// ---------------------------------------------------------------------------
// H. Shared call site canaries
// ---------------------------------------------------------------------------

$apply_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-checkout-payment.php' );
$email_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-financing-email.php' );
$fail_cp   = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php' );
$smart_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-lifecycle.php' );

mtuc_sn_assert(
	false !== strpos( $apply_src, 'mtuc_maybe_notify_satrudnik_bank_send_failure' ),
	'H: notifier called from mtuc_apply_payment_gateway_to_order file'
);
mtuc_sn_assert(
	substr_count( $apply_src, 'mtuc_maybe_notify_satrudnik_bank_send_failure' ) >= 2,
	'H: both apply_payment_gateway branches call notifier'
);
mtuc_sn_assert(
	false !== strpos( $email_src, 'function mtuc_maybe_notify_satrudnik_bank_send_failure' ),
	'H: notifier lives in financing-email module'
);
mtuc_sn_assert(
	false === strpos( $fail_cp, 'mtuc_maybe_notify_satrudnik_bank_send_failure' ),
	'H: not called from popup-order failure handlers'
);
mtuc_sn_assert(
	false === strpos( $smart_src, 'mtuc_maybe_notify_satrudnik_bank_send_failure' ),
	'H: not called from SmartUCF lifecycle'
);

// Product/Cart/Checkout converge on apply_payment_gateway.
$idem_src  = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-popup-idempotency.php' );
$popup_src = $fail_cp;
mtuc_sn_assert(
	false !== strpos( $idem_src, 'mtuc_apply_payment_gateway_to_order' ),
	'H: accept_popup_financing uses apply_payment_gateway (product/cart)'
);
mtuc_sn_assert(
	false !== strpos( $popup_src, 'mtuc_apply_payment_gateway_to_order' ),
	'H: checkout/popup paths reference apply_payment_gateway'
);

// CP id omitted when absent.
mtuc_sn_reset_mail();
$omit = mtuc_sn_order( MTUC_BANK_STATUS_SEND_FAILED_CP );
mtuc_maybe_notify_satrudnik_bank_send_failure( $omit );
mtuc_sn_assert(
	false === strpos( $mtuc_sn_mail_log[0]['message'], 'КП поръчка:' ),
	'B2/omit: CP order line omitted when id missing'
);

fwrite( STDOUT, 'OK: ' . $mtuc_sn_assert_count . " satrudnik failure notification assertions passed\n" );
exit( 0 );
