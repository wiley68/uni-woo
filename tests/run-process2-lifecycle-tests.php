<?php
/**
 * Process 2 lifecycle tests (AUD-WOO-014).
 *
 * Run: php8.1 tests/run-process2-lifecycle-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['mtuc_test_options'] = array(
	'admin_email' => 'store-admin@example.com',
);
$mtuc_p2_assert_count         = 0;
$GLOBALS['mtuc_p2_redirects'] = array();
$GLOBALS['mtuc_fe_mail_log']  = array();
$GLOBALS['mtuc_fe_mail_fail'] = false;
$GLOBALS['mtuc_woo_triggers'] = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_p2_assert( bool $ok, string $message ): void {
	global $mtuc_p2_assert_count;
	++$mtuc_p2_assert_count;
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

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function esc_html__( $text, $domain = 'default' ) {
		unset( $domain );
		return (string) $text;
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

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return string
	 */
	function sanitize_email( $email ) {
		$email = trim( (string) $email );
		return is_email( $email ) ? $email : '';
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

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'hash_equals' ) ) {
	/**
	 * @param string $a A.
	 * @param string $b B.
	 * @return bool
	 */
	function hash_equals( $a, $b ) {
		return (string) $a === (string) $b;
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

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * @param string $option Option.
	 * @param mixed  $value Value.
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
	 * @param string $option Option.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	function update_option( $option, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['mtuc_test_options'][ $option ] = is_string( $value ) ? $value : (string) wp_json_encode( $value );
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

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return bool
	 */
	function is_email( $email ) {
		return is_string( $email ) && false !== strpos( $email, '@' );
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
	 * @param int    $quote Quote.
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
		if ( ! empty( $GLOBALS['mtuc_fe_mail_fail'] ) ) {
			return false;
		}
		$GLOBALS['mtuc_fe_mail_log'][] = array(
			'to'      => $to,
			'subject' => $subject,
			'message' => $message,
			'headers' => $headers,
		);
		return true;
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal order stand-in.
	 */
	class WC_Order {
		/** @var int */
		public $id = 1401;
		/** @var string */
		public $status = 'processing';
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var string */
		public $order_key = 'wc_order_test';
		/** @var string */
		public $created_via = '';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var list<string> */
		public $notes = array();
		/** @var list<array<string, mixed>> */
		public $line_items = array( array( 'id' => 1 ) );

		public function get_id(): int {
			return $this->id;
		}

		public function get_order_number(): string {
			return (string) $this->id;
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

		public function get_order_key(): string {
			return $this->order_key;
		}

		public function get_checkout_order_received_url(): string {
			return 'https://shop.example/checkout/order-received/' . $this->id . '/?key=' . $this->order_key;
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
			if ( isset( $args['meta_key'], $args['meta_value'] )
				&& (string) $order->get_meta( (string) $args['meta_key'] ) !== (string) $args['meta_value']
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

if ( ! function_exists( 'mtuc_get_shop_data' ) ) {
	/**
	 * @param mixed $unicid Unused.
	 * @return array<string, mixed>
	 */
	function mtuc_get_shop_data( $unicid = null ) {
		unset( $unicid );
		return $GLOBALS['mtuc_p2_shop'] ?? array( 'uni_proces' => 0 );
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

if ( ! function_exists( 'mtuc_parse_shop_notification_emails' ) ) {
	/**
	 * @param array<string, mixed> $shop Shop.
	 * @return list<string>
	 */
	function mtuc_parse_shop_notification_emails( array $shop ): array {
		$raw   = isset( $shop['uni_email'] ) ? (string) $shop['uni_email'] : 'merchant@example.com';
		$parts = preg_split( '/\s*,\s*/', $raw );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			if ( is_email( $part ) ) {
				$out[] = $part;
			}
		}
		return $out;
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

if ( ! class_exists( 'WC_Emails', false ) ) {
	/**
	 * Minimal WC_Emails stand-in.
	 */
	class WC_Emails {
		/**
		 * @return array<string, object>
		 */
		public function get_emails() {
			$trigger = static function () {
				++$GLOBALS['mtuc_woo_triggers'];
			};
			$email = new class( $trigger ) {
				/** @var callable */
				private $cb;

				/**
				 * @param callable $cb Trigger callback.
				 */
				public function __construct( $cb ) {
					$this->cb = $cb;
				}
				/**
				 * @param mixed ...$args Unused.
				 * @return void
				 */
				public function trigger( ...$args ): void {
					unset( $args );
					( $this->cb )();
				}
			};
			return array(
				'WC_Email_New_Order'                 => $email,
				'WC_Email_Customer_Processing_Order' => $email,
			);
		}
	}
}

if ( ! class_exists( 'WooCommerce', false ) ) {
	/**
	 * Minimal WC stand-in for mailer access.
	 */
	class WooCommerce {
		/** @var object|null */
		public $cart = null;

		/**
		 * @return WC_Emails
		 */
		public function mailer() {
			static $mailer = null;
			if ( null === $mailer ) {
				$mailer = new WC_Emails();
			}
			return $mailer;
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	/**
	 * @return WooCommerce
	 */
	function WC() {
		static $wc = null;
		if ( null === $wc ) {
			$wc = new WooCommerce();
		}
		return $wc;
	}
}

// Thank-you redirect stubs.
if ( ! function_exists( 'is_order_received_page' ) ) {
	/**
	 * @return bool
	 */
	function is_order_received_page() {
		return ! empty( $GLOBALS['mtuc_p2_is_order_received'] );
	}
}

if ( ! function_exists( 'wp_redirect' ) ) {
	/**
	 * @param string $location URL.
	 * @param int    $status Status.
	 * @return bool
	 */
	function wp_redirect( $location, $status = 302 ) {
		unset( $status );
		$GLOBALS['mtuc_p2_redirects'][] = (string) $location;
		// Avoid Mtuc_Smartucf_Api_Client::redirect_browser() exit in harness.
		throw new RuntimeException( 'mtuc_test_redirect' );
	}
}

if ( ! function_exists( 'nocache_headers' ) ) {
	/**
	 * @return void
	 */
	function nocache_headers(): void {
	}
}

if ( ! function_exists( 'status_header' ) ) {
	/**
	 * @param int $code Code.
	 * @return void
	 */
	function status_header( $code ): void {
		unset( $code );
	}
}

$GLOBALS['wp'] = (object) array( 'query_vars' => array( 'order-received' => 0 ) );

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-submission-lock.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-error-normalizer.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-order-diagnostics.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-bank-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-process-identity.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-idempotency.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-presentation.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-email.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-checkout-payment.php';

/**
 * @return array<string, mixed>
 */
function mtuc_p2_shop( int $uni_proces ): array {
	return array(
		'uni_proces'                 => $uni_proces,
		'uni_env'                    => 0,
		'uni_email'                  => 'merchant@example.com',
		'uni_test_service'           => Mtuc_Smartucf_Endpoint_Policy::SERVICE_TEST,
		'uni_production_service'     => Mtuc_Smartucf_Endpoint_Policy::SERVICE_PRODUCTION,
		'uni_test_application'       => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_TEST,
		'uni_production_application' => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_PRODUCTION,
	);
}

/**
 * @param WC_Order $order Order.
 * @param int      $process Process.
 * @return void
 */
function mtuc_p2_seed_identity( WC_Order $order, int $process ): void {
	$GLOBALS['mtuc_test_orders'][ $order->id ] = $order;
	mtuc_persist_order_process_identity( $order, $process );
	$order->save();
}

// ---------------------------------------------------------------------------
// F01 — durable identity + config drift
// ---------------------------------------------------------------------------

$p2 = new WC_Order();
$p2->id = 1410;
$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 1 );
$ensured = mtuc_ensure_order_process_identity( $p2, $GLOBALS['mtuc_p2_shop'] );
mtuc_p2_assert( 2 === $ensured, 'new order from P2 shop gets identity 2' );
mtuc_p2_assert( 2 === (int) $p2->get_meta( MTUC_ORDER_META_PROCESS ), 'canonical _mtuc_process=2' );
mtuc_p2_assert( mtuc_is_process2_order( $p2 ), 'is_process2_order true' );

$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 0 ); // config drift to P1
$again = mtuc_resolve_order_process_for_banking( $p2, $GLOBALS['mtuc_p2_shop'] );
mtuc_p2_assert( 2 === $again, 'P2→config P1 drift keeps Process 2' );
$conflict = mtuc_persist_order_process_identity( $p2, 1 );
mtuc_p2_assert( is_wp_error( $conflict ), 'cannot switch P2 identity to P1' );

$p1 = new WC_Order();
$p1->id = 1411;
$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 0 );
mtuc_p2_assert( 1 === mtuc_ensure_order_process_identity( $p1, $GLOBALS['mtuc_p2_shop'] ), 'new order from P1 shop gets identity 1' );
$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 1 );
mtuc_p2_assert( 1 === mtuc_resolve_order_process_for_banking( $p1, $GLOBALS['mtuc_p2_shop'] ), 'P1→config P2 drift keeps Process 1' );
mtuc_p2_assert( ! mtuc_is_process2_order( $p1 ), 'P1 not process2' );

// Unknown empty order (no lifecycle) is "fresh", not legacy unknown.
$fresh_empty = new WC_Order();
$fresh_empty->id = 1413;
mtuc_p2_assert( null === mtuc_get_order_process_identity( $fresh_empty ), 'fresh empty has no clean identity yet' );
mtuc_p2_assert( 'fresh' === mtuc_classify_order_process_identity( $fresh_empty )['status'], 'empty order classifies as fresh' );

// Clean legacy P2 inference + canonicalization.
$legacy = new WC_Order();
$legacy->id = 1412;
$legacy->update_meta_data( MTUC_ORDER_META_PROCESS2, 1 );
mtuc_p2_assert( 2 === mtuc_get_order_process_identity( $legacy ), 'legacy _mtuc_process2=1 treated as Process 2' );
mtuc_p2_assert( mtuc_is_process2_order( $legacy ), 'legacy marker is_process2_order' );
$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 0 );
mtuc_p2_assert( 2 === mtuc_ensure_order_process_identity( $legacy, $GLOBALS['mtuc_p2_shop'] ), 'clean legacy P2 ensure ignores shop P1' );
mtuc_p2_assert( 2 === (int) $legacy->get_meta( MTUC_ORDER_META_PROCESS ), 'clean legacy P2 canonicalized' );

// Clean legacy P1 inference + canonicalization.
$legacy_p1 = new WC_Order();
$legacy_p1->id = 1415;
$legacy_p1->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'LegacySessP1' );
$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 1 );
mtuc_p2_assert( 1 === mtuc_get_order_process_identity( $legacy_p1 ), 'strong SmartUCF session is clean P1' );
mtuc_p2_assert( 1 === mtuc_ensure_order_process_identity( $legacy_p1, $GLOBALS['mtuc_p2_shop'] ), 'clean legacy P1 ensure ignores shop P2' );
mtuc_p2_assert( 1 === (int) $legacy_p1->get_meta( MTUC_ORDER_META_PROCESS ), 'clean legacy P1 canonicalized' );

// Existing/re-entry unknown: lifecycle evidence without process identity — shop irrelevant.
$unknown_p2cfg = new WC_Order();
$unknown_p2cfg->id = 1416;
$unknown_p2cfg->payment_method = 'mtunicredit';
$unknown_p2cfg->update_meta_data( MTUC_ORDER_META_PREFIX . 'submission_source', 'cart_popup' );
$unknown_p2cfg->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '1416' );
$unknown_p2cfg->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'missing' );
$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 1 );
mtuc_p2_assert( 'unknown' === mtuc_classify_order_process_identity( $unknown_p2cfg )['status'], 're-entry without process is unknown' );
$unk_err = mtuc_resolve_order_process_for_banking( $unknown_p2cfg, $GLOBALS['mtuc_p2_shop'] );
mtuc_p2_assert( is_wp_error( $unk_err ) && 'mtuc_process_identity_unknown' === $unk_err->get_error_code(), 'unknown + shop P2 fail closed' );
mtuc_p2_assert( '' === (string) $unknown_p2cfg->get_meta( MTUC_ORDER_META_PROCESS ), 'unknown does not persist process from shop P2' );
mtuc_p2_assert( '' === (string) $unknown_p2cfg->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'unknown does not write bank status' );

$unknown_p1cfg = new WC_Order();
$unknown_p1cfg->id = 1417;
$unknown_p1cfg->payment_method = 'mtunicredit';
$unknown_p1cfg->update_meta_data( MTUC_ORDER_META_PREFIX . 'submission_source', 'product_popup' );
$unknown_p1cfg->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '1417' );
$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 0 );
$unk_err2 = mtuc_resolve_order_process_for_banking( $unknown_p1cfg, $GLOBALS['mtuc_p2_shop'] );
mtuc_p2_assert( is_wp_error( $unk_err2 ) && 'mtuc_process_identity_unknown' === $unk_err2->get_error_code(), 'unknown + shop P1 fail closed' );
mtuc_p2_assert( '' === (string) $unknown_p1cfg->get_meta( MTUC_ORDER_META_PROCESS ), 'unknown does not persist process from shop P1' );

// Conflict: legacy P2 marker + strong P1 evidence.
$conf_legacy = new WC_Order();
$conf_legacy->id = 1418;
$conf_legacy->update_meta_data( MTUC_ORDER_META_PROCESS2, 1 );
$conf_legacy->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'ConflictSess' );
mtuc_p2_assert( 'conflict' === mtuc_classify_order_process_identity( $conf_legacy )['status'], 'legacy P2 + P1 session is conflict' );
$conf_err = mtuc_resolve_order_process_for_banking( $conf_legacy, mtuc_p2_shop( 1 ) );
mtuc_p2_assert( is_wp_error( $conf_err ) && 'mtuc_process_identity_conflict' === $conf_err->get_error_code(), 'conflict fail closed' );
mtuc_p2_assert( null === mtuc_get_order_process_identity( $conf_legacy ), 'conflict has no clean identity' );

// Conflict: canonical P1 + strong P2 evidence.
$conf_c1 = new WC_Order();
$conf_c1->id = 1419;
$conf_c1->update_meta_data( MTUC_ORDER_META_PROCESS, 1 );
$conf_c1->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS2 );
mtuc_p2_assert( 'conflict' === mtuc_classify_order_process_identity( $conf_c1 )['status'], 'canonical P1 + bank_sent_process2 conflict' );

// Conflict: canonical P2 + strong P1 evidence.
$conf_c2 = new WC_Order();
$conf_c2->id = 1424;
$conf_c2->update_meta_data( MTUC_ORDER_META_PROCESS, 2 );
$conf_c2->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
mtuc_p2_assert( 'conflict' === mtuc_classify_order_process_identity( $conf_c2 )['status'], 'canonical P2 + SmartUCF outcome conflict' );

// Unknown/conflict must not start SmartUCF / CP via banking resolve path.
$no_transport = mtuc_resolve_order_process_for_banking( $unknown_p2cfg, mtuc_p2_shop( 1 ) );
mtuc_p2_assert( is_wp_error( $no_transport ), 'identity failure blocks before transport' );
$smart_block_unk = mtuc_send_cart_popup_order_to_smartucf(
	$unknown_p2cfg,
	array(
		'first_name' => 'A',
		'last_name'  => 'B',
		'address'    => 'Addr',
		'phone'      => '0888000000',
		'email'      => 'a@example.com',
	),
	array(),
	mtuc_p2_shop( 0 )
);
mtuc_p2_assert(
	is_wp_error( $smart_block_unk )
	&& in_array( $smart_block_unk->get_error_code(), array( 'mtuc_process_identity_unknown', 'mtuc_process_identity_conflict' ), true ),
	'unknown identity does not start SmartUCF transport'
);

// Product/Cart/Classic convergence: complete_order_bank_submission uses durable identity.
$cart_like = new WC_Order();
$cart_like->id = 1414;
mtuc_p2_seed_identity( $cart_like, 2 );
$cart_like->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 7001 );
$cart_like->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'created' );
$cart_like->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS2 );
$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 0 ); // drifted
$result = mtuc_complete_order_bank_submission(
	$cart_like,
	array(
		'first_name' => 'A',
		'last_name'  => 'B',
		'address'    => 'Addr',
		'phone'      => '0888000000',
		'email'      => 'a@example.com',
	),
	array( 'months' => 12 ),
	$GLOBALS['mtuc_p2_shop']
);
mtuc_p2_assert( is_array( $result ) && ! empty( $result['process2'] ), 're-entry with P2 identity stays Process 2 path' );
mtuc_p2_assert( 2 === mtuc_get_order_process_identity( $cart_like ), 'identity unchanged after re-entry' );

$p1_re = new WC_Order();
$p1_re->id = 1415;
mtuc_p2_seed_identity( $p1_re, 1 );
$p1_re->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 7002 );
$p1_re->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'created' );
$p1_re->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS1 );
$p1_re->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/SessP1' );
$p1_re->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'SessP1' );
$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 1 );
$p1_result = mtuc_complete_order_bank_submission(
	$p1_re,
	array(
		'first_name' => 'A',
		'last_name'  => 'B',
		'address'    => 'Addr',
		'phone'      => '0888000000',
		'email'      => 'a@example.com',
	),
	array( 'months' => 12 ),
	$GLOBALS['mtuc_p2_shop']
);
mtuc_p2_assert( is_array( $p1_result ) && empty( $p1_result['process2'] ), 'P1 identity ignores shop P2 drift' );
mtuc_p2_assert( 1 === mtuc_get_order_process_identity( $p1_re ), 'P1 identity retained' );

// ---------------------------------------------------------------------------
// F02 — bank_sent_process2 callback guard
// ---------------------------------------------------------------------------

$cb_p1 = new WC_Order();
$cb_p1->id = 1420;
mtuc_p2_seed_identity( $cb_p1, 1 );
$cb_p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 8001 );
$cb_p1->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'created' );
$r_p1 = mtuc_apply_cp_bank_status_push( $cb_p1, MTUC_BANK_STATUS_SENT_PROCESS2, 'P2' );
mtuc_p2_assert( is_wp_error( $r_p1 ), 'P1 + bank_sent_process2 rejected' );
mtuc_p2_assert( MTUC_BANK_STATUS_SENT_PROCESS2 !== (string) $cb_p1->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'P1 rejects writing bank_sent_process2' );
mtuc_p2_assert( 1 === mtuc_get_order_process_identity( $cb_p1 ), 'P1 identity not mutated by P2 callback' );

$cb_incomplete = new WC_Order();
$cb_incomplete->id = 1421;
mtuc_p2_seed_identity( $cb_incomplete, 2 );
$r_inc = mtuc_apply_cp_bank_status_push( $cb_incomplete, MTUC_BANK_STATUS_SENT_PROCESS2, 'P2' );
mtuc_p2_assert( is_wp_error( $r_inc ), 'incomplete P2 + bank_sent_process2 rejected' );
mtuc_p2_assert( '' === (string) $cb_incomplete->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'incomplete P2 bank status unchanged' );

$cb_ok = new WC_Order();
$cb_ok->id = 1422;
mtuc_p2_seed_identity( $cb_ok, 2 );
$cb_ok->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 8002 );
$cb_ok->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'created' );
$r_ok = mtuc_apply_cp_bank_status_push( $cb_ok, MTUC_BANK_STATUS_SENT_PROCESS2, mtuc_get_bank_status_label( MTUC_BANK_STATUS_SENT_PROCESS2 ) );
mtuc_p2_assert( true === $r_ok, 'valid P2 callback accepted' );
mtuc_p2_assert( MTUC_BANK_STATUS_SENT_PROCESS2 === (string) $cb_ok->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'valid P2 status written' );
$r_ok2 = mtuc_apply_cp_bank_status_push( $cb_ok, MTUC_BANK_STATUS_SENT_PROCESS2, mtuc_get_bank_status_label( MTUC_BANK_STATUS_SENT_PROCESS2 ) );
mtuc_p2_assert( true === $r_ok2, 'valid P2 callback idempotent' );

// Empty CP outcome + positive CP id remains valid completion evidence (legacy-compatible).
$cb_empty_outcome = new WC_Order();
$cb_empty_outcome->id = 1425;
mtuc_p2_seed_identity( $cb_empty_outcome, 2 );
$cb_empty_outcome->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 8005 );
// Intentionally no CP create outcome meta.
$r_empty_out = mtuc_apply_cp_bank_status_push( $cb_empty_outcome, MTUC_BANK_STATUS_SENT_PROCESS2, 'P2' );
mtuc_p2_assert( true === $r_empty_out, 'empty CP outcome + positive CP id accepts bank_sent_process2' );

// Explicit unknown CP outcome rejects P2 success callback.
$cb_unknown_out = new WC_Order();
$cb_unknown_out->id = 1426;
mtuc_p2_seed_identity( $cb_unknown_out, 2 );
$cb_unknown_out->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 8006 );
$cb_unknown_out->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'unknown' );
$r_unk_out = mtuc_apply_cp_bank_status_push( $cb_unknown_out, MTUC_BANK_STATUS_SENT_PROCESS2, 'P2' );
mtuc_p2_assert( is_wp_error( $r_unk_out ), 'unknown CP outcome rejects bank_sent_process2' );
mtuc_p2_assert( '' === (string) $cb_unknown_out->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'unknown outcome leaves bank status untouched' );

// Repeated invalid P2 callback → one guard note only.
$cb_note = new WC_Order();
$cb_note->id = 1427;
mtuc_p2_seed_identity( $cb_note, 1 );
$notes_before = count( $cb_note->notes );
mtuc_apply_cp_bank_status_push( $cb_note, MTUC_BANK_STATUS_SENT_PROCESS2, 'P2' );
mtuc_apply_cp_bank_status_push( $cb_note, MTUC_BANK_STATUS_SENT_PROCESS2, 'P2' );
mtuc_p2_assert( 1 === ( count( $cb_note->notes ) - $notes_before ), 'repeated invalid P2 callback adds one guard note only' );
mtuc_p2_assert( MTUC_BANK_STATUS_SENT_PROCESS2 !== (string) $cb_note->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'invalid P2 callback does not mutate bank status' );

$cb_gen = new WC_Order();
$cb_gen->id = 1423;
mtuc_p2_seed_identity( $cb_gen, 2 );
$r_gen = mtuc_apply_cp_bank_status_push( $cb_gen, '85', 'Отказана' );
mtuc_p2_assert( true === $r_gen, 'generic 85 still accepted' );
mtuc_p2_assert( '85' === (string) $cb_gen->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'generic 85 persisted' );

// ---------------------------------------------------------------------------
// F03 — SmartUCF redirect isolation
// ---------------------------------------------------------------------------

$GLOBALS['mtuc_p2_is_order_received'] = true;
$GLOBALS['mtuc_p2_redirects']         = array();
$GLOBALS['mtuc_p2_shop']              = mtuc_p2_shop( 0 );

$redir_p2 = new WC_Order();
$redir_p2->id = 1430;
$redir_p2->order_key = 'key_p2';
mtuc_p2_seed_identity( $redir_p2, 2 );
$redir_p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'submission_source', 'checkout' );
$redir_p2->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/StaleSess' );
$redir_p2->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'StaleSess' );
$GLOBALS['wp']->query_vars['order-received'] = 1430;
$GLOBALS['mtuc_test_orders'][1430] = $redir_p2;
$_GET['key'] = 'key_p2';
mtuc_checkout_maybe_redirect_to_bank_on_thankyou();
mtuc_p2_assert( 0 === count( $GLOBALS['mtuc_p2_redirects'] ), 'P2 + stale trusted redirect → no SmartUCF redirect' );

$redir_p1 = new WC_Order();
$redir_p1->id = 1431;
$redir_p1->order_key = 'key_p1';
mtuc_p2_seed_identity( $redir_p1, 1 );
$redir_p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'submission_source', 'checkout' );
$trusted = 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/SessOK1';
$redir_p1->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, $trusted );
mtuc_p2_assert( ! mtuc_is_process2_order( $redir_p1 ), 'P1 redirect fixture is not Process 2' );
mtuc_p2_assert(
	Mtuc_Smartucf_Api_Client::is_trusted_redirect_url( $trusted, $GLOBALS['mtuc_p2_shop'] ),
	'P1 redirect fixture URL remains trusted'
);
$GLOBALS['wp']->query_vars['order-received'] = 1431;
$GLOBALS['mtuc_test_orders'][1431] = $redir_p1;
$_GET['key'] = 'key_p1';
$GLOBALS['mtuc_p2_redirects'] = array();
try {
	mtuc_checkout_maybe_redirect_to_bank_on_thankyou();
} catch ( RuntimeException $e ) {
	mtuc_p2_assert( 'mtuc_test_redirect' === $e->getMessage(), 'P1 thank-you redirect throws harness sentinel' );
}
mtuc_p2_assert( 1 === count( $GLOBALS['mtuc_p2_redirects'] ) && $trusted === $GLOBALS['mtuc_p2_redirects'][0], 'P1 + valid trusted redirect executes Thank You redirect' );
mtuc_p2_assert( 1 === (int) $redir_p1->get_meta( MTUC_ORDER_META_BANK_REDIRECT_DISPATCHED ), 'P1 redirect dispatch marker set' );

$src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-checkout-payment.php' );
mtuc_p2_assert(
	false !== strpos( $src, 'mtuc_classify_order_process_identity' )
	&& false !== strpos( $src, 'mtuc_checkout_maybe_redirect_to_bank_on_thankyou' ),
	'thank-you handler gates SmartUCF redirect on clean Process 1 identity'
);

$smartucf_block = mtuc_send_cart_popup_order_to_smartucf(
	$redir_p2,
	array(
		'first_name' => 'A',
		'last_name'  => 'B',
		'address'    => 'Addr',
		'phone'      => '0888000000',
		'email'      => 'a@example.com',
	),
	array(),
	$GLOBALS['mtuc_p2_shop']
);
mtuc_p2_assert( is_wp_error( $smartucf_block ) && in_array(
	$smartucf_block->get_error_code(),
	array( 'mtuc_process2_no_smartucf', 'mtuc_process_identity_conflict' ),
	true
), 'P2 (or P2+stale P1 meta conflict) blocks SmartUCF send helper' );

// ---------------------------------------------------------------------------
// Pass 3 — real Product/Cart first-attempt initialization ordering
// ---------------------------------------------------------------------------

/**
 * @param int $order_id Order ID.
 * @return WC_Order
 */
function mtuc_p2_make_popup_order( int $order_id ): WC_Order {
	$order = new WC_Order();
	$order->id = $order_id;
	$order->line_items = array( array( 'id' => 1 ) );
	$order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_COMPLETE );
	$order->set_payment_method( 'mtunicredit' );
	$GLOBALS['mtuc_test_orders'][ $order_id ] = $order;
	return $order;
}

/**
 * @param string               $token Token.
 * @param string               $scope Scope.
 * @param int                  $order_id Order ID.
 * @param array<string, mixed> $shop Shop.
 * @return array{order:WC_Order,created:bool,option_key:string}|WP_Error
 */
function mtuc_p2_resolve_first_attempt( string $token, string $scope, int $order_id, array $shop ) {
	$GLOBALS['mtuc_p2_shop'] = $shop;
	return mtuc_resolve_popup_financing_order(
		$token,
		$scope,
		static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( $order_id ) {
			unset( $existing, $bind_context );
			$order = mtuc_p2_make_popup_order( $order_id );
			if ( is_callable( $early_bind ) ) {
				$early_bind( $order );
			}
			return $order;
		}
	);
}

$prod_p1 = mtuc_p2_resolve_first_attempt(
	'p3productp1tokentokentokentoken01',
	mtuc_build_product_operation_scope_key( 901, 0 ),
	1501,
	mtuc_p2_shop( 0 )
);
mtuc_p2_assert( is_array( $prod_p1 ) && $prod_p1['order'] instanceof WC_Order, 'Product first-attempt P1 resolve succeeds' );
$op_prod_p1 = $prod_p1['order'];
mtuc_p2_assert( 1 === (int) $op_prod_p1->get_meta( MTUC_ORDER_META_PROCESS ), 'Product P1: process persisted before/at commit' );
mtuc_p2_assert( '' !== (string) $op_prod_p1->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID ), 'Product P1: CP shop-order ID assigned after process' );
mtuc_p2_assert( 'clean' === mtuc_classify_order_process_identity( $op_prod_p1 )['status'], 'Product P1: classification clean' );
mtuc_p2_assert( 1 === mtuc_resolve_order_process_for_banking( $op_prod_p1, mtuc_p2_shop( 1 ) ), 'Product P1: later banking resolve keeps P1 despite shop P2' );
mtuc_p2_assert( '' === (string) $op_prod_p1->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'Product P1 init does not write bank status' );

$prod_p2 = mtuc_p2_resolve_first_attempt(
	'p3productp2tokentokentokentoken02',
	mtuc_build_product_operation_scope_key( 902, 0 ),
	1502,
	mtuc_p2_shop( 1 )
);
mtuc_p2_assert( is_array( $prod_p2 ), 'Product first-attempt P2 resolve succeeds' );
$op_prod_p2 = $prod_p2['order'];
mtuc_p2_assert( 2 === (int) $op_prod_p2->get_meta( MTUC_ORDER_META_PROCESS ), 'Product P2: process persisted' );
mtuc_p2_assert( '' !== (string) $op_prod_p2->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID ), 'Product P2: CP shop-order ID assigned' );
mtuc_p2_assert( 2 === mtuc_get_order_process_identity( $op_prod_p2 ), 'Product P2: clean identity' );
mtuc_p2_assert( 2 === mtuc_resolve_order_process_for_banking( $op_prod_p2, mtuc_p2_shop( 0 ) ), 'Product P2: banking resolve ignores shop P1 drift' );

$cart_p1 = mtuc_p2_resolve_first_attempt(
	'p3cartp1tokentokentokentoken0001',
	mtuc_build_cart_operation_scope_key(),
	1503,
	mtuc_p2_shop( 0 )
);
mtuc_p2_assert( is_array( $cart_p1 ), 'Cart first-attempt P1 resolve succeeds' );
$op_cart_p1 = $cart_p1['order'];
mtuc_p2_assert( 1 === (int) $op_cart_p1->get_meta( MTUC_ORDER_META_PROCESS ), 'Cart P1: process persisted' );
mtuc_p2_assert( '' !== (string) $op_cart_p1->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID ), 'Cart P1: CP shop-order ID assigned' );
mtuc_p2_assert( 1 === mtuc_resolve_order_process_for_banking( $op_cart_p1, mtuc_p2_shop( 1 ) ), 'Cart P1 banking resolve stable' );

// Distinct cart token for P2 (cart scope is session-global in harness).
$cart_p2 = mtuc_p2_resolve_first_attempt(
	'p3cartp2tokentokentokentoken0002',
	'cart:' . md5( 'p3-cart-p2-scope' ),
	1504,
	mtuc_p2_shop( 1 )
);
mtuc_p2_assert( is_array( $cart_p2 ), 'Cart first-attempt P2 resolve succeeds' );
$op_cart_p2 = $cart_p2['order'];
mtuc_p2_assert( 2 === (int) $op_cart_p2->get_meta( MTUC_ORDER_META_PROCESS ), 'Cart P2: process persisted' );
mtuc_p2_assert( '' !== (string) $op_cart_p2->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID ), 'Cart P2: CP shop-order ID assigned' );
mtuc_p2_assert( 2 === mtuc_resolve_order_process_for_banking( $op_cart_p2, mtuc_p2_shop( 0 ) ), 'Cart P2 banking resolve stable' );

// No temporary unknown at banking resolve after commit sequence.
mtuc_p2_assert(
	'clean' === mtuc_classify_order_process_identity( $op_prod_p1 )['status']
	&& 'clean' === mtuc_classify_order_process_identity( $op_prod_p2 )['status']
	&& 'clean' === mtuc_classify_order_process_identity( $op_cart_p1 )['status']
	&& 'clean' === mtuc_classify_order_process_identity( $op_cart_p2 )['status'],
	'POPUP INITIALIZATION ORDER: SAFE — process present with CP shop-order ID'
);

// ---------------------------------------------------------------------------
// Pass 3 — dynamic unknown/conflict + stale Thank You redirect
// ---------------------------------------------------------------------------

$GLOBALS['mtuc_p2_is_order_received'] = true;
$GLOBALS['mtuc_p2_shop']              = mtuc_p2_shop( 0 );
$trusted_stale = 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/StaleUnk';

$unk_redir = new WC_Order();
$unk_redir->id = 1510;
$unk_redir->order_key = 'key_unk';
$unk_redir->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '1510' );
$unk_redir->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, $trusted_stale );
mtuc_p2_assert( 'unknown' === mtuc_classify_order_process_identity( $unk_redir )['status'], 'unknown fixture classified' );
$GLOBALS['wp']->query_vars['order-received'] = 1510;
$GLOBALS['mtuc_test_orders'][1510] = $unk_redir;
$_GET['key'] = 'key_unk';
$GLOBALS['mtuc_p2_redirects'] = array();
mtuc_checkout_maybe_redirect_to_bank_on_thankyou();
mtuc_p2_assert( 0 === count( $GLOBALS['mtuc_p2_redirects'] ), 'unknown + stale redirect → no wp_redirect' );
mtuc_p2_assert( 0 === (int) $unk_redir->get_meta( MTUC_ORDER_META_BANK_REDIRECT_DISPATCHED ), 'unknown does not set redirect dispatch marker' );

$conf_redir = new WC_Order();
$conf_redir->id = 1511;
$conf_redir->order_key = 'key_conf';
$conf_redir->update_meta_data( MTUC_ORDER_META_PROCESS2, 1 );
$conf_redir->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'ConflictSessTY' );
$conf_redir->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, $trusted_stale );
mtuc_p2_assert( 'conflict' === mtuc_classify_order_process_identity( $conf_redir )['status'], 'conflict fixture classified' );
$GLOBALS['wp']->query_vars['order-received'] = 1511;
$GLOBALS['mtuc_test_orders'][1511] = $conf_redir;
$_GET['key'] = 'key_conf';
$GLOBALS['mtuc_p2_redirects'] = array();
mtuc_checkout_maybe_redirect_to_bank_on_thankyou();
mtuc_p2_assert( 0 === count( $GLOBALS['mtuc_p2_redirects'] ), 'conflict + stale redirect → no redirect' );

// ---------------------------------------------------------------------------
// F04 — merchant email independent retry
// ---------------------------------------------------------------------------

$GLOBALS['mtuc_p2_shop'] = mtuc_p2_shop( 1 );
$mail_order = new WC_Order();
$mail_order->id = 1440;
mtuc_p2_seed_identity( $mail_order, 2 );
$mail_order->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS2 );
$mail_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 9001 );
$mail_order->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'created' );
$mail_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'months', 12 );
$mail_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'egn', '9001011234' );
$mail_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'phone2', '0888123456' );
$mail_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'loan_amount', 1000 );
$mail_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'monthly_installment', 90 );
$mail_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'total_payable', 1080 );
$mail_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'parva', 0 );
$mail_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'glp', 0 );
$mail_order->update_meta_data( MTUC_ORDER_META_PREFIX . 'gpr', 0 );

$GLOBALS['mtuc_fe_mail_fail'] = true;
$GLOBALS['mtuc_fe_mail_log']  = array();
$GLOBALS['mtuc_woo_triggers'] = 0;
mtuc_send_leasing_order_notifications_once( $mail_order );
mtuc_p2_assert( 1 === (int) $mail_order->get_meta( MTUC_ORDER_META_LEASING_NOTIFICATIONS_SENT ), 'Woo notifications marked sent' );
mtuc_p2_assert( 0 === (int) $mail_order->get_meta( MTUC_ORDER_META_PROCESS2_UNI_EMAIL_SENT ), 'merchant email not marked after failure' );
mtuc_p2_assert( MTUC_BANK_STATUS_SENT_PROCESS2 === (string) $mail_order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'mail failure does not change bank status' );
$woo_first = (int) $GLOBALS['mtuc_woo_triggers'];
mtuc_p2_assert( $woo_first > 0, 'Woo notifications triggered on first call' );

$GLOBALS['mtuc_fe_mail_fail'] = false;
$GLOBALS['mtuc_fe_mail_log']  = array();
$GLOBALS['mtuc_woo_triggers'] = 0;
mtuc_send_leasing_order_notifications_once( $mail_order );
mtuc_p2_assert( 0 === (int) $GLOBALS['mtuc_woo_triggers'], 'second call does not repeat Woo notifications' );
mtuc_p2_assert( 1 === count( $GLOBALS['mtuc_fe_mail_log'] ), 'second call retries merchant email only' );
mtuc_p2_assert( 1 === (int) $mail_order->get_meta( MTUC_ORDER_META_PROCESS2_UNI_EMAIL_SENT ), 'merchant email marked after success' );
mtuc_p2_assert( MTUC_BANK_STATUS_SENT_PROCESS2 === (string) $mail_order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'bank status still unchanged after retry success' );

$GLOBALS['mtuc_fe_mail_log'] = array();
mtuc_send_leasing_order_notifications_once( $mail_order );
mtuc_p2_assert( 0 === count( $GLOBALS['mtuc_fe_mail_log'] ), 'successful merchant email not resent' );

fwrite( STDOUT, 'OK process2-lifecycle ' . $mtuc_p2_assert_count . " assertions\n" );
exit( 0 );
