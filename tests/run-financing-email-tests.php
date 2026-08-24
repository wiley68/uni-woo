<?php
/**
 * Characterization tests for financing email orchestration (AUD-WOO-016 Step 7).
 *
 * Run: php tests/run-financing-email-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_fe_assert_count = 0;
$mtuc_fe_mail_log     = array();
$mtuc_fe_options      = array( 'admin_email' => 'store-admin@example.com' );
$mtuc_fe_shop         = array(
	'uni_email' => 'merchant@example.com, store-admin@example.com, other@example.com',
);

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_fe_assert( bool $ok, string $message ): void {
	global $mtuc_fe_assert_count;
	++$mtuc_fe_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param mixed ...$args Unused.
	 * @return void
	 */
	function add_action( ...$args ): void {
		unset( $args );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param mixed ...$args Unused.
	 * @return void
	 */
	function add_filter( ...$args ): void {
		unset( $args );
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

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 * @return void
	 */
	function esc_html_e( $text, $domain = 'default' ) {
		unset( $domain );
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );
		return esc_html( $text );
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

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return bool
	 */
	function is_email( $email ) {
		return is_string( $email ) && false !== strpos( $email, '@' );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $key Option key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		global $mtuc_fe_options;
		return $mtuc_fe_options[ $key ] ?? $default;
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
	 * @param int    $quote Quote style.
	 * @return string
	 */
	function wp_specialchars_decode( $text, $quote = ENT_QUOTES ) {
		return html_entity_decode( (string) $text, $quote, 'UTF-8' );
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
		global $mtuc_fe_mail_log;
		$mtuc_fe_mail_log[] = array(
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
			'headers' => $headers,
		);
		return true;
	}
}

if ( ! function_exists( 'mtuc_get_process2_confirmation_message' ) ) {
	/**
	 * @return string
	 */
	function mtuc_get_process2_confirmation_message(): string {
		return 'Process 2 confirmation';
	}
}

if ( ! function_exists( 'mtuc_parse_shop_notification_emails' ) ) {
	/**
	 * @param array<string, mixed> $shop Shop.
	 * @return array<int, string>
	 */
	function mtuc_parse_shop_notification_emails( array $shop ): array {
		$raw   = isset( $shop['uni_email'] ) ? (string) $shop['uni_email'] : '';
		$parts = preg_split( '/\s*,\s*/', $raw );
		$parts = is_array( $parts ) ? $parts : array();
		$out   = array();
		foreach ( $parts as $part ) {
			$part = trim( (string) $part );
			if ( '' !== $part && is_email( $part ) ) {
				$out[] = $part;
			}
		}
		return array_values( array_unique( $out ) );
	}
}

if ( ! function_exists( 'mtuc_get_shop_data' ) ) {
	/**
	 * @param mixed $unicid Unused.
	 * @return array<string, mixed>
	 */
	function mtuc_get_shop_data( $unicid = null ) {
		unset( $unicid );
		global $mtuc_fe_shop;
		return $mtuc_fe_shop;
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal order stand-in.
	 */
	class WC_Order {
		/** @var int */
		public $id = 55;
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var string */
		public $status = 'pending';
		/** @var array<string, mixed> */
		public $meta = array();

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

		public function save(): void {
		}
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-presentation.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-email.php';

$fake_egn = '9001011234';

/**
 * @param WC_Order $order Order.
 * @param int      $process2 Process 2 flag.
 * @return void
 */
function mtuc_fe_seed_order( WC_Order $order, int $process2 = 1 ): void {
	global $fake_egn;
	$order->payment_method = MTUC_PAYMENT_GATEWAY_ID;
	$order->update_meta_data( MTUC_ORDER_META_PROCESS2, $process2 );
	$order->update_meta_data( MTUC_ORDER_META_BANK_STATUS, 1 === $process2 ? MTUC_BANK_STATUS_SENT_PROCESS2 : MTUC_BANK_STATUS_SENT_PROCESS1 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'months', 12 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'egn', $fake_egn );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'phone2', '0888123456' );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'parva', 0 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'loan_amount', 1000 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'monthly_installment', 90 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'total_payable', 1080 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'glp', 0 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'gpr', 0 );
}

// ---------------------------------------------------------------------------
// Audience resolution (presentation contract)
// ---------------------------------------------------------------------------

mtuc_fe_assert(
	MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER === mtuc_resolve_financing_email_audience( false ),
	'customer transactional email uses CUSTOMER audience'
);
mtuc_fe_assert(
	MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL === mtuc_resolve_financing_email_audience( true ),
	'admin transactional email uses ADMIN_EMAIL audience'
);

$email_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-financing-email.php' );
mtuc_fe_assert(
	false !== strpos( $email_src, 'mtuc_get_order_credit_meta_rows' )
	&& false === strpos( $email_src, "get_meta( MTUC_ORDER_META_PREFIX . 'egn'" ),
	'email module requests presentation rows; does not read EGN meta directly'
);

// ---------------------------------------------------------------------------
// Recipient filtering
// ---------------------------------------------------------------------------

$filtered = mtuc_exclude_store_admin_from_notification_emails(
	array( 'merchant@example.com', 'store-admin@example.com', 'other@example.com' )
);
mtuc_fe_assert(
	array( 'merchant@example.com', 'other@example.com' ) === $filtered,
	'store admin_email excluded from Process 2 uni_email recipients'
);

mtuc_fe_assert(
	array() === mtuc_exclude_store_admin_from_notification_emails( array( 'store-admin@example.com' ) ),
	'only-store-admin list becomes empty (no invented fallback)'
);

// ---------------------------------------------------------------------------
// Process 2 merchant email — ADMIN_EMAIL + EGN + recipients
// ---------------------------------------------------------------------------

global $mtuc_fe_mail_log, $mtuc_fe_shop, $mtuc_fe_options;
$mtuc_fe_mail_log = array();
$p2               = new WC_Order();
mtuc_fe_seed_order( $p2, 1 );

mtuc_send_process2_uni_email_notifications( $p2 );
mtuc_fe_assert( 1 === count( $mtuc_fe_mail_log ), 'Process 2 merchant email sent once' );
mtuc_fe_assert( 'merchant@example.com' === $mtuc_fe_mail_log[0]['to'], 'Process 2 primary recipient is first uni_email' );
mtuc_fe_assert(
	is_array( $mtuc_fe_mail_log[0]['headers'] )
	&& in_array( 'Cc: other@example.com', $mtuc_fe_mail_log[0]['headers'], true ),
	'Process 2 Cc includes remaining non-admin recipients'
);
mtuc_fe_assert( false !== strpos( $mtuc_fe_mail_log[0]['message'], $fake_egn ), 'Process 2 merchant body includes EGN' );
mtuc_fe_assert( false !== strpos( $mtuc_fe_mail_log[0]['message'], 'ЕГН' ), 'Process 2 merchant body includes EGN label' );
mtuc_fe_assert( 1 === (int) $p2->get_meta( MTUC_ORDER_META_PROCESS2_UNI_EMAIL_SENT ), 'Process 2 send-once meta set after success' );

$mtuc_fe_mail_log = array();
mtuc_send_process2_uni_email_notifications( $p2 );
mtuc_fe_assert( 0 === count( $mtuc_fe_mail_log ), 'Process 2 duplicate dispatch blocked by send-once meta' );

// ---------------------------------------------------------------------------
// Process 2 customer email section — CUSTOMER audience, EGN absent
// ---------------------------------------------------------------------------

ob_start();
mtuc_email_after_order_table_credit_details( $p2, false, false, null );
$customer_html = (string) ob_get_clean();
mtuc_fe_assert( false === strpos( $customer_html, $fake_egn ), 'Process 2 customer email HTML omits EGN value' );
mtuc_fe_assert( false === stripos( $customer_html, 'ЕГН' ), 'Process 2 customer email HTML omits EGN label' );
mtuc_fe_assert( false !== strpos( $customer_html, 'Месечна вноска' ), 'Process 2 customer email still shows financing fields' );

ob_start();
mtuc_email_after_order_table_credit_details( $p2, true, false, null );
$admin_html = (string) ob_get_clean();
mtuc_fe_assert( false !== strpos( $admin_html, $fake_egn ), 'Process 2 admin Woo email HTML includes EGN' );

// ---------------------------------------------------------------------------
// Empty / invalid merchant email — no invented fallback
// ---------------------------------------------------------------------------

$mtuc_fe_mail_log = array();
$mtuc_fe_shop     = array( 'uni_email' => '' );
$p2_empty         = new WC_Order();
mtuc_fe_seed_order( $p2_empty, 1 );
mtuc_send_process2_uni_email_notifications( $p2_empty );
mtuc_fe_assert( 0 === count( $mtuc_fe_mail_log ), 'empty uni_email sends nothing' );
mtuc_fe_assert( '' === (string) $p2_empty->get_meta( MTUC_ORDER_META_PROCESS2_UNI_EMAIL_SENT ), 'empty uni_email does not mark sent' );

$mtuc_fe_shop = array( 'uni_email' => 'store-admin@example.com' );
$p2_admin_only = new WC_Order();
mtuc_fe_seed_order( $p2_admin_only, 1 );
mtuc_send_process2_uni_email_notifications( $p2_admin_only );
mtuc_fe_assert( 0 === count( $mtuc_fe_mail_log ), 'uni_email equal to store admin only → no mail' );
mtuc_fe_assert( 1 === (int) $p2_admin_only->get_meta( MTUC_ORDER_META_PROCESS2_UNI_EMAIL_SENT ), 'admin-only list marks sent without inventing recipients' );

// Restore shop for later tests.
$mtuc_fe_shop = array(
	'uni_email' => 'merchant@example.com, store-admin@example.com, other@example.com',
);

// ---------------------------------------------------------------------------
// Process 1 — no Process 2 merchant mail; EGN absent in email sections
// ---------------------------------------------------------------------------

$mtuc_fe_mail_log = array();
$p1               = new WC_Order();
mtuc_fe_seed_order( $p1, 0 );
mtuc_send_process2_uni_email_notifications( $p1 );
mtuc_fe_assert( 0 === count( $mtuc_fe_mail_log ), 'Process 1 does not send Process 2 uni_email' );

ob_start();
mtuc_email_after_order_table_credit_details( $p1, true, false, null );
$p1_admin_html = (string) ob_get_clean();
mtuc_fe_assert( false === strpos( $p1_admin_html, $fake_egn ), 'Process 1 admin email omits EGN' );
mtuc_fe_assert( false === stripos( $p1_admin_html, 'ЕГН' ), 'Process 1 admin email omits EGN label' );

ob_start();
mtuc_email_after_order_table_credit_details( $p1, false, false, null );
$p1_customer_html = (string) ob_get_clean();
mtuc_fe_assert( false === strpos( $p1_customer_html, $fake_egn ), 'Process 1 customer email omits EGN' );

// ---------------------------------------------------------------------------
// Leasing notifications once — send-once meta
// ---------------------------------------------------------------------------

$p_once = new WC_Order();
mtuc_fe_seed_order( $p_once, 1 );
$p_once->update_meta_data( MTUC_ORDER_META_LEASING_NOTIFICATIONS_SENT, 1 );
$mtuc_fe_mail_log = array();
mtuc_send_leasing_order_notifications_once( $p_once );
mtuc_fe_assert( 0 === count( $mtuc_fe_mail_log ), 'leasing notifications send-once meta blocks re-entry' );

$p_wrong_pm = new WC_Order();
mtuc_fe_seed_order( $p_wrong_pm, 1 );
$p_wrong_pm->payment_method = 'cod';
mtuc_send_leasing_order_notifications_once( $p_wrong_pm );
mtuc_fe_assert( 0 === count( $mtuc_fe_mail_log ), 'non-mtunicredit payment method skips leasing notifications' );

fwrite( STDOUT, 'OK financing-email ' . $mtuc_fe_assert_count . " assertions\n" );
