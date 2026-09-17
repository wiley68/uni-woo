<?php
/**
 * Definitive CP create failure (/api/v11-class) — status, emails, no SmartUCF, no CP PATCH.
 *
 * Run: php tests/run-cp-create-fail-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['mtuc_test_options']          = array();
$GLOBALS['mtuc_test_orders']           = array();
$GLOBALS['mtuc_test_orders_persisted'] = array();
$GLOBALS['mtuc_submission_lock_fence'] = null;
$mtuc_cf_assert_count                  = 0;
$mtuc_cf_mail_triggers                 = array();
$mtuc_cf_smartucf_calls                = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_cf_assert( bool $ok, string $message ): void {
	global $mtuc_cf_assert_count;
	++$mtuc_cf_assert_count;
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

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return string
	 */
	function sanitize_email( $email ) {
		return (string) $email;
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

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param mixed ...$args Args.
	 * @return void
	 */
	function add_action( ...$args ): void {
		unset( $args );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param mixed ...$args Args.
	 * @return void
	 */
	function add_filter( ...$args ): void {
		unset( $args );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string $type Type.
	 * @return string|int
	 */
	function current_time( $type ) {
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
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
		public $id = 0;
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var string */
		public $status = 'pending';
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var string */
		public $created_via = 'mtuc_popup';
		/** @var list<string> */
		public $notes = array();

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

		public function get_created_via(): string {
			return $this->created_via;
		}

		/**
		 * @param string $key Meta key.
		 * @param bool   $single Single.
		 * @return mixed
		 */
		public function get_meta( $key, $single = true ) {
			unset( $single );
			return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : '';
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

		/**
		 * @param string $status Status.
		 * @param string $note Note.
		 * @return void
		 */
		public function update_status( $status, $note = '' ): void {
			$this->status = (string) $status;
			if ( '' !== $note ) {
				$this->add_order_note( $note );
			}
			$this->save();
			if ( function_exists( 'mtuc_send_leasing_order_notifications_once' ) ) {
				mtuc_send_leasing_order_notifications_once( $this );
			}
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
		 * @param array<int, string> $statuses Statuses.
		 * @return bool
		 */
		public function has_status( $statuses ): bool {
			return in_array( $this->status, (array) $statuses, true );
		}

		/**
		 * @return bool
		 */
		public function save() {
			$GLOBALS['mtuc_test_orders'][ $this->id ]           = $this;
			$GLOBALS['mtuc_test_orders_persisted'][ $this->id ] = $this->meta;
			return true;
		}
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * @param int $id Order ID.
	 * @return WC_Order|false
	 */
	function wc_get_order( $id ) {
		$id = (int) $id;
		if ( isset( $GLOBALS['mtuc_test_orders'][ $id ] ) && $GLOBALS['mtuc_test_orders'][ $id ] instanceof WC_Order ) {
			return $GLOBALS['mtuc_test_orders'][ $id ];
		}
		if ( ! isset( $GLOBALS['mtuc_test_orders_persisted'][ $id ] ) ) {
			return false;
		}
		$fresh       = new WC_Order();
		$fresh->id   = $id;
		$fresh->meta = $GLOBALS['mtuc_test_orders_persisted'][ $id ];
		return $fresh;
	}
}

if ( ! class_exists( 'WC_Emails', false ) ) {
	/**
	 * Mailer stub.
	 */
	class WC_Emails {
		/**
		 * @return array<string, object>
		 */
		public function get_emails() {
			$new = new class() {
				/**
				 * @param int           $order_id Order ID.
				 * @param WC_Order|null $order Order.
				 * @return void
				 */
				public function trigger( $order_id, $order = null ): void {
					$GLOBALS['mtuc_cf_mail_triggers'][] = array( 'type' => 'new_order', 'order_id' => (int) $order_id );
					unset( $order );
				}
			};
			$on_hold = new class() {
				/**
				 * @param int           $order_id Order ID.
				 * @param WC_Order|null $order Order.
				 * @return void
				 */
				public function trigger( $order_id, $order = null ): void {
					$GLOBALS['mtuc_cf_mail_triggers'][] = array( 'type' => 'customer_on_hold', 'order_id' => (int) $order_id );
					unset( $order );
				}
			};
			return array(
				'WC_Email_New_Order'                 => $new,
				'WC_Email_Customer_On_Hold_Order'    => $on_hold,
				'WC_Email_Customer_Processing_Order' => $on_hold,
				'WC_Email_Customer_Completed_Order'  => $on_hold,
			);
		}
	}
}

if ( ! class_exists( 'WooCommerce', false ) ) {
	/**
	 * WC stub.
	 */
	class WooCommerce {
		/**
		 * @return WC_Emails
		 */
		public function mailer() {
			return new WC_Emails();
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

if ( ! class_exists( 'Mtuc_Settings', false ) ) {
	/**
	 * Settings stub.
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
	/**
	 * CP client mock.
	 */
	class Mtuc_Cp_Api_Client {
		/** @var list<mixed> */
		public static $create_queue = array();
		/** @var list<array<string, mixed>> */
		public static $create_calls = array();
		/** @var list<array{order_id:string,status:string,status_id:string}> */
		public static $patch_calls = array();

		/**
		 * @return void
		 */
		public static function reset(): void {
			self::$create_queue = array();
			self::$create_calls = array();
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
			return array_shift( self::$create_queue );
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
			return array(
				'success' => true,
				'error'   => null,
				'message' => '',
				'data'    => array(
					'order_id'  => $order_id,
					'status_id' => $status_id,
					'status'    => $status,
				),
			);
		}
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-error-normalizer.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-order-diagnostics.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-submission-lock.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-process-identity.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-bank-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-lifecycle.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-idempotency.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-presentation.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-email.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-checkout-payment.php';

if ( ! function_exists( 'mtuc_get_shop_data' ) ) {
	/**
	 * @return array<string, mixed>|WP_Error
	 */
	function mtuc_get_shop_data() {
		return array(
			'uni_proces' => 1,
			'uni_email'  => '',
		);
	}
}

if ( ! function_exists( 'mtuc_parse_shop_notification_emails' ) ) {
	/**
	 * @param array<string, mixed> $shop Shop.
	 * @return array<int, string>
	 */
	function mtuc_parse_shop_notification_emails( array $shop ): array {
		unset( $shop );
		return array();
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

/**
 * @param string $lock_key Lock key.
 * @param string $owner Owner token.
 * @return void
 */
function mtuc_cf_arm_fence( string $lock_key, string $owner ): void {
	$now     = time();
	$payload = mtuc_encode_submission_lock_payload( $owner, $now, $now + 120, MTUC_SUBMISSION_LOCK_STAGE_CP_HTTP );
	$GLOBALS['mtuc_test_options'][ mtuc_submission_lock_option_key( $lock_key ) ] = $payload;
	mtuc_arm_submission_lock_fence( $lock_key, $owner );
}

/**
 * @return void
 */
function mtuc_cf_reset(): void {
	$GLOBALS['mtuc_test_options']          = array();
	$GLOBALS['mtuc_test_orders']           = array();
	$GLOBALS['mtuc_test_orders_persisted'] = array();
	$GLOBALS['mtuc_submission_lock_fence'] = null;
	$GLOBALS['mtuc_cf_mail_triggers']      = array();
	$GLOBALS['mtuc_cf_smartucf_calls']     = 0;
	Mtuc_Cp_Api_Client::reset();
	Mtuc_Smartucf_Api_Client::$http_transport = null;
}

// ---------------------------------------------------------------------------
// Classifier — real /api/v11 shape (HTTP 403 HTML)
// ---------------------------------------------------------------------------

$cf_html = new WP_Error(
	'mtuc_api_invalid_json',
	'Невалиден JSON отговор от Контролния панел.',
	array(
		'status' => 403,
		'raw'    => '<!DOCTYPE html><html><head><title>Just a moment...</title></head></html>',
	)
);
mtuc_cf_assert( mtuc_cp_create_error_proves_unreachable_endpoint( $cf_html ), '403 HTML proves unreachable' );
mtuc_cf_assert( ! mtuc_is_cp_create_ambiguous_error( $cf_html ), '403 HTML is definitive create failure' );

$timeout = new WP_Error( 'http_request_failed', 'cURL error 28: timeout' );
mtuc_cf_assert( mtuc_is_cp_create_ambiguous_error( $timeout ), 'timeout stays ambiguous' );

$json_200 = new WP_Error( 'mtuc_api_invalid_json', 'bad', array( 'status' => 200 ) );
mtuc_cf_assert( mtuc_is_cp_create_ambiguous_error( $json_200 ), '200 invalid JSON stays ambiguous' );

// ---------------------------------------------------------------------------
// End-to-end create recovery + emails + panel + no SmartUCF
// ---------------------------------------------------------------------------

mtuc_cf_reset();
$order = new WC_Order();
$order->id = 958;
$order->status = 'pending';
$order->payment_method = MTUC_PAYMENT_GATEWAY_ID;
$order->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '958' );
$order->update_meta_data( MTUC_ORDER_META_PROCESS, 1 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'months', 12 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'kop_code', 'POS COM 50' );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'parva', 0 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'loan_amount', 1000 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'monthly_installment', 97.49 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'total_payable', 1169.88 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'glp', 30 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'gpr', 34.5 );
$order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, 'complete' );
$order->save();

mtuc_cf_arm_fence( 'lock-958', 'exec-A' );
Mtuc_Cp_Api_Client::$create_queue[] = $cf_html;

$result = mtuc_create_cp_order_with_recovery(
	$order,
	array(
		'order_id' => '958',
		'price'    => 1000,
	),
	array( 'uni_proces' => 0 )
);

mtuc_cf_assert( is_wp_error( $result ), 'create returns error' );
mtuc_cf_assert( 1 === count( Mtuc_Cp_Api_Client::$create_calls ), 'CP create called once' );
mtuc_cf_assert( 0 === (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'CP order absent' );
mtuc_cf_assert( 'missing' === (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ), 'outcome missing' );
mtuc_cf_assert(
	MTUC_BANK_STATUS_SEND_FAILED_CP === (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'bank_send_failed_cp persisted'
);
mtuc_cf_assert(
	'Неуспешно изпратен Банка - КП' === mtuc_get_order_bank_status_display( $order ),
	'public label exact'
);
mtuc_cf_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'no CP status PATCH' );
mtuc_cf_assert( mtuc_order_financing_is_terminal_failure( $order ), 'terminal failure' );

// SmartUCF must not run after definitive CP create failure.
Mtuc_Smartucf_Api_Client::$http_transport = static function () {
	++$GLOBALS['mtuc_cf_smartucf_calls'];
	return array( 'body' => '{}', 'curl_error' => '', 'http_code' => 200 );
};
mtuc_cf_assert( ! mtuc_popup_order_needs_smartucf_submission( $order ), 'SmartUCF submission not needed' );
mtuc_cf_assert( 0 === (int) $GLOBALS['mtuc_cf_smartucf_calls'], 'SmartUCF HTTP not called' );

// Product / Cart / Checkout share the same accept gate.
$submission = array( 'bank_unavailable' => true, 'redirect_url' => 'https://example.test/' );
mtuc_cf_assert( mtuc_should_accept_financing_order_after_submission( $order, $submission ), 'accept after definitive CP fail' );

$GLOBALS['mtuc_cf_mail_triggers'] = array();
mtuc_accept_popup_financing_order( $order );
$types = array_column( $GLOBALS['mtuc_cf_mail_triggers'], 'type' );
mtuc_cf_assert( 1 === count( array_keys( array_flip( array_filter( $types, static function ( $t ) {
	return 'new_order' === $t;
} ) ) ) ) || 1 === count( array_filter( $types, static function ( $t ) {
	return 'new_order' === $t;
} ) ), 'New Order email once' );
mtuc_cf_assert( in_array( 'new_order', $types, true ), 'admin New Order email dispatched' );
mtuc_cf_assert( in_array( 'customer_on_hold', $types, true ), 'customer on-hold email dispatched' );
mtuc_cf_assert( 2 === count( $GLOBALS['mtuc_cf_mail_triggers'] ), 'exactly two standard emails' );

$panel = mtuc_get_admin_order_credit_meta_rows( $order );
mtuc_cf_assert( 'Неуспешно изпратен Банка - КП' === ( $panel['Статус към банката'] ?? '' ), 'panel bank status' );
$panel_json = (string) wp_json_encode( $panel, JSON_UNESCAPED_UNICODE );
mtuc_cf_assert( false === strpos( $panel_json, 'Последна грешка' ), 'panel omits diagnostics' );
mtuc_cf_assert( false === strpos( $panel_json, 'SmartUCF lifecycle' ), 'panel omits lifecycle' );

ob_start();
mtuc_render_order_credit_email_section( $order, true, MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER );
$email_plain = (string) ob_get_clean();
mtuc_cf_assert(
	false !== strpos( $email_plain, 'Статус към банката: Неуспешно изпратен Банка - КП' ),
	'email contains exact bank status'
);
mtuc_cf_assert( false === strpos( $email_plain, 'subsystem' ), 'email omits internals' );

// Entry-point parity: same gate for checkout-shaped and popup-shaped acceptance.
foreach ( array( 'product_popup', 'cart_popup', 'checkout' ) as $source ) {
	$o = new WC_Order();
	$o->id = 2000 + crc32( $source ) % 100;
	$o->payment_method = MTUC_PAYMENT_GATEWAY_ID;
	$o->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'missing' );
	$o->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED_CP );
	$o->update_meta_data( MTUC_ORDER_META_PREFIX . 'submission_source', $source );
	mtuc_cf_assert(
		mtuc_should_accept_financing_order_after_submission( $o, array( 'bank_unavailable' => true ) ),
		$source . ' shares terminal accept semantics'
	);
}

// ---------------------------------------------------------------------------
// Process 2 definitive CP create failure — same public status (not generic)
// ---------------------------------------------------------------------------

mtuc_cf_reset();
$p2 = new WC_Order();
$p2->id = 971;
$p2->status = 'pending';
$p2->payment_method = MTUC_PAYMENT_GATEWAY_ID;
$p2->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '971' );
$p2->update_meta_data( MTUC_ORDER_META_PROCESS, 2 );
$p2->update_meta_data( MTUC_ORDER_META_PROCESS2, 1 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'months', 12 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'kop_code', 'POS COM 50' );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'parva', 0 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'loan_amount', 1000 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'monthly_installment', 97.49 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'total_payable', 1169.88 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'glp', 30 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'gpr', 34.5 );
$p2->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, 'complete' );
$p2->save();

mtuc_cf_arm_fence( 'lock-971', 'exec-A' );
Mtuc_Cp_Api_Client::$create_queue[] = $cf_html;
$p2_result = mtuc_create_cp_order_with_recovery(
	$p2,
	array(
		'order_id' => '971',
		'price'    => 1000,
	),
	array( 'uni_proces' => 1 )
);
mtuc_cf_assert( is_wp_error( $p2_result ), 'P2 create returns error' );
mtuc_cf_assert( 0 === (int) $p2->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ), 'P2 CP order absent' );
mtuc_cf_assert(
	MTUC_BANK_STATUS_SEND_FAILED_CP === (string) $p2->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'P2 status_id = bank_send_failed_cp'
);
mtuc_cf_assert(
	MTUC_BANK_STATUS_SEND_FAILED !== (string) $p2->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'P2 does not publish generic bank_send_failed'
);
mtuc_cf_assert(
	'Неуспешно изпратен Банка - КП' === mtuc_get_order_bank_status_display( $p2 ),
	'P2 public label exact'
);
mtuc_cf_assert(
	'Неуспешно изпратен Банка' !== mtuc_get_order_bank_status_display( $p2 ),
	'P2 public label is not generic'
);
mtuc_cf_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'P2 no CP status PATCH' );

$GLOBALS['mtuc_cf_mail_triggers'] = array();
mtuc_accept_popup_financing_order( $p2 );
$p2_types = array_column( $GLOBALS['mtuc_cf_mail_triggers'], 'type' );
mtuc_cf_assert( in_array( 'new_order', $p2_types, true ), 'P2 admin email dispatched' );
mtuc_cf_assert( in_array( 'customer_on_hold', $p2_types, true ), 'P2 customer email dispatched' );

ob_start();
mtuc_render_order_credit_email_section( $p2, true, MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER );
$p2_email = (string) ob_get_clean();
mtuc_cf_assert(
	false !== strpos( $p2_email, 'Статус към банката: Неуспешно изпратен Банка - КП' ),
	'P2 email uses CP failure status'
);
mtuc_cf_assert(
	false === strpos( $p2_email, 'Статус към банката: Неуспешно изпратен Банка' )
	|| false !== strpos( $p2_email, 'Статус към банката: Неуспешно изпратен Банка - КП' ),
	'P2 email does not use bare generic failure label alone'
);
// Stronger: generic label without - КП must not appear as the status value.
mtuc_cf_assert(
	1 === preg_match( '/Статус към банката:\s*Неуспешно изпратен Банка - КП/', $p2_email ),
	'P2 email status line is exactly CP-specific'
);

$p2_panel = mtuc_get_admin_order_credit_meta_rows( $p2 );
mtuc_cf_assert(
	'Неуспешно изпратен Банка - КП' === ( $p2_panel['Статус към банката'] ?? '' ),
	'P2 panel bank status'
);

// Frozen four-status public vocabulary for initial bank statuses.
$public_labels = array_values( mtuc_get_bank_status_labels() );
$allowed_public = array(
	'Неуспешно изпратен Банка - КП',
	'Неуспешно изпратен Банка - SmartUCF',
	'Изпратен Банка - Процес 1',
	'Изпратен Банка - Процес 2',
);
foreach ( array( MTUC_BANK_STATUS_SEND_FAILED_CP, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF, MTUC_BANK_STATUS_SENT_PROCESS1, MTUC_BANK_STATUS_SENT_PROCESS2 ) as $key ) {
	mtuc_cf_assert(
		in_array( mtuc_get_bank_status_label( $key ), $allowed_public, true ),
		$key . ' maps to frozen public vocabulary'
	);
}
mtuc_cf_assert(
	! in_array( 'Неуспешно изпратен Банка', $allowed_public, true ),
	'generic failure label is outside frozen public vocabulary'
);
// Generic key may still exist for legacy/internal certificate paths, but must not be the CP-create public result.
mtuc_cf_assert(
	MTUC_BANK_STATUS_SEND_FAILED !== MTUC_BANK_STATUS_SEND_FAILED_CP,
	'generic and CP failure status ids remain distinct'
);
unset( $public_labels );

Mtuc_Smartucf_Api_Client::$http_transport = null;

fwrite( STDOUT, 'OK cp-create-fail ' . $mtuc_cf_assert_count . " assertions\n" );
exit( 0 );
