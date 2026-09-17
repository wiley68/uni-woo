<?php
/**
 * Definitive SmartUCF remote rejection (Process 1 KOP) — status, CP sync, emails, panel.
 *
 * Run: php tests/run-smartucf-remote-reject-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['mtuc_test_options']          = array();
$GLOBALS['mtuc_test_orders']           = array();
$GLOBALS['mtuc_test_orders_persisted'] = array();
$GLOBALS['mtuc_submission_lock_fence'] = null;
$mtuc_rr_assert_count                  = 0;
$mtuc_rr_mail_triggers                 = array();

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_rr_assert( bool $ok, string $message ): void {
	global $mtuc_rr_assert_count;
	++$mtuc_rr_assert_count;
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

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $str String.
	 * @return string
	 */
	function sanitize_text_field( $str ) {
		return trim( (string) $str );
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
					$GLOBALS['mtuc_rr_mail_triggers'][] = array( 'type' => 'new_order', 'order_id' => (int) $order_id );
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
					$GLOBALS['mtuc_rr_mail_triggers'][] = array( 'type' => 'customer_on_hold', 'order_id' => (int) $order_id );
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

/**
 * @param string $status_id Status id.
 * @param string $status Status label.
 * @param string $order_id Shop order id.
 * @return array<string, mixed>
 */
function mtuc_rr_patch_success( string $status_id, string $status, string $order_id ): array {
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

if ( ! class_exists( 'Mtuc_Cp_Api_Client', false ) ) {
	/**
	 * CP client mock.
	 */
	class Mtuc_Cp_Api_Client {
		/** @var list<array{order_id:string,status:string,status_id:string}> */
		public static $patch_calls = array();

		/**
		 * @return void
		 */
		public static function reset(): void {
			self::$patch_calls = array();
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
			return mtuc_rr_patch_success( $status_id, $status, $order_id );
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

/**
 * @return array<string, mixed>
 */
function mtuc_rr_shop(): array {
	return array(
		'uni_env'                    => 0,
		'uni_sertificat'             => 0,
		'uni_test_service'           => Mtuc_Smartucf_Endpoint_Policy::SERVICE_TEST,
		'uni_production_service'     => Mtuc_Smartucf_Endpoint_Policy::SERVICE_PRODUCTION,
		'uni_test_application'       => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_TEST,
		'uni_production_application' => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_PRODUCTION,
	);
}

/**
 * @param int $order_id Order ID.
 * @return array<string, mixed>
 */
function mtuc_rr_payload( int $order_id ): array {
	return array(
		'user'    => 'u',
		'pass'    => 'p',
		'orderNo' => (string) $order_id,
	);
}

/**
 * @return void
 */
function mtuc_rr_reset(): void {
	$GLOBALS['mtuc_test_options']               = array();
	$GLOBALS['mtuc_test_orders']                = array();
	$GLOBALS['mtuc_test_orders_persisted']      = array();
	$GLOBALS['mtuc_submission_lock_fence']      = null;
	$GLOBALS['mtuc_rr_mail_triggers']           = array();
	Mtuc_Smartucf_Api_Client::$http_transport   = null;
	Mtuc_Smartucf_Api_Client::$certificate_synchronizer = null;
	Mtuc_Cp_Api_Client::reset();
	if ( function_exists( 'mtuc_clear_smartucf_p1_claim_owner_context' ) ) {
		mtuc_clear_smartucf_p1_claim_owner_context();
	}
}

/**
 * @param string $lock_key Lock key.
 * @param string $owner Owner token.
 * @return void
 */
function mtuc_rr_arm_fence( string $lock_key, string $owner ): void {
	$now     = time();
	$payload = mtuc_encode_submission_lock_payload( $owner, $now, $now + 120, MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP );
	$GLOBALS['mtuc_test_options'][ mtuc_submission_lock_option_key( $lock_key ) ] = $payload;
	mtuc_arm_submission_lock_fence( $lock_key, $owner );
}

// ---------------------------------------------------------------------------
// Definitive KOP rejection → status + CP PATCH + emails + panel
// ---------------------------------------------------------------------------

mtuc_rr_reset();
$order = new WC_Order();
$order->id = 951;
$order->status = 'pending';
$order->payment_method = MTUC_PAYMENT_GATEWAY_ID;
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 366 );
$order->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '951' );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'months', 12 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'kop_code', 'POS COM 100' );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'parva', 102.88 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'loan_amount', 1131.68 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'monthly_installment', 105.62 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'total_payable', 1267.44 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'glp', 21.45 );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'gpr', 23.69 );
$order->update_meta_data( MTUC_ORDER_META_PROCESS, 1 );
$order->save();

mtuc_rr_arm_fence( 'lock-951', 'exec-A' );
mtuc_acquire_smartucf_p1_send_claim( $order );

$http_calls = 0;
Mtuc_Smartucf_Api_Client::$http_transport = function () use ( &$http_calls ) {
	++$http_calls;
	return array(
		'body'       => wp_json_encode(
			array(
				'errorCode'           => 134,
				'errorText'           => 'Некоректен КОП – съответните му продукт/финансова таблица нямат активно разпространение за този ОТП',
				'sucfOnlineSessionID' => null,
			)
		),
		'curl_error' => '',
		'http_code'  => 200,
	);
};

$result = Mtuc_Smartucf_Api_Client::start_session( mtuc_rr_payload( 951 ), mtuc_rr_shop() );
mtuc_rr_assert( 1 === $http_calls, 'SmartUCF called once' );
mtuc_rr_assert( is_wp_error( $result ) && 'mtuc_smartucf_remote_rejected' === $result->get_error_code(), 'classified definitive remote rejection' );

mtuc_handle_smartucf_start_error( $order, $result );

mtuc_rr_assert(
	MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF === (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'Shop status = bank_send_failed_smartucf'
);
mtuc_rr_assert(
	'Неуспешно изпратен Банка - SmartUCF' === mtuc_get_order_bank_status_display( $order ),
	'public label exact'
);
mtuc_rr_assert( mtuc_order_financing_is_terminal_failure( $order ), 'terminal failure for email accept' );

$submission = array(
	'bank_unavailable' => true,
	'redirect_url'     => 'https://example.test/thankyou',
);
mtuc_rr_assert( mtuc_should_accept_financing_order_after_submission( $order, $submission ), 'accept after definitive reject' );

$GLOBALS['mtuc_rr_mail_triggers'] = array();
mtuc_accept_popup_financing_order( $order );
$types = array_column( $GLOBALS['mtuc_rr_mail_triggers'], 'type' );
mtuc_rr_assert( in_array( 'new_order', $types, true ), 'admin New Order email dispatched' );
mtuc_rr_assert( in_array( 'customer_on_hold', $types, true ), 'customer on-hold email dispatched' );

$panel = mtuc_get_admin_order_credit_meta_rows( $order );
mtuc_rr_assert( 'Неуспешно изпратен Банка - SmartUCF' === ( $panel['Статус към банката'] ?? '' ), 'panel bank status' );
mtuc_rr_assert( ! isset( $panel['SmartUCF lifecycle'] ), 'panel omits lifecycle' );
mtuc_rr_assert( ! isset( $panel['Последна грешка (категория)'] ), 'panel omits diagnostic category' );

ob_start();
mtuc_render_order_credit_email_section( $order, true, MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER );
$email_plain = (string) ob_get_clean();
mtuc_rr_assert( false !== strpos( $email_plain, 'Статус към банката: Неуспешно изпратен Банка - SmartUCF' ), 'email contains exact bank status' );
mtuc_rr_assert( false === strpos( $email_plain, 'SmartUCF lifecycle' ), 'email omits lifecycle' );
mtuc_rr_assert( false === strpos( $email_plain, 'Последна грешка' ), 'email omits diagnostics' );

mtuc_rr_assert( 1 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'exactly one CP status update' );
$patch = Mtuc_Cp_Api_Client::$patch_calls[0];
mtuc_rr_assert( 'bank_send_failed_smartucf' === (string) $patch['status_id'], 'CP status_id = bank_send_failed_smartucf' );
mtuc_rr_assert( 'Неуспешно изпратен Банка - SmartUCF' === (string) $patch['status'], 'CP status label exact' );

// ---------------------------------------------------------------------------
// True transport timeout must NOT use definitive rejection
// ---------------------------------------------------------------------------

mtuc_rr_reset();
$to = new WC_Order();
$to->id = 952;
$to->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 367 );
$to->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '952' );
$to->update_meta_data( MTUC_ORDER_META_PROCESS, 1 );
$to->save();
mtuc_rr_arm_fence( 'lock-952', 'exec-A' );
mtuc_acquire_smartucf_p1_send_claim( $to );
Mtuc_Smartucf_Api_Client::$http_transport = static function () {
	return array(
		'body'       => '',
		'curl_error' => 'Operation timed out after 10001 milliseconds',
		'http_code'  => 0,
	);
};
$r_to = Mtuc_Smartucf_Api_Client::start_session( mtuc_rr_payload( 952 ), mtuc_rr_shop() );
mtuc_rr_assert( is_wp_error( $r_to ) && 'mtuc_smartucf_http_error' === $r_to->get_error_code(), 'timeout → http_error' );
mtuc_handle_smartucf_start_error( $to, $r_to );
mtuc_rr_assert( 'unknown' === (string) $to->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'timeout outcome unknown' );
mtuc_rr_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $to->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'timeout not definitive' );
mtuc_rr_assert( ! mtuc_order_financing_is_terminal_failure( $to ), 'timeout not terminal for auto-accept emails' );
mtuc_rr_assert(
	! mtuc_should_accept_financing_order_after_submission( $to, array( 'bank_unavailable' => true ) ),
	'timeout bank_unavailable skips accept/emails'
);
mtuc_rr_assert( 0 === count( Mtuc_Cp_Api_Client::$patch_calls ), 'timeout does not PATCH CP fail status' );

Mtuc_Smartucf_Api_Client::$http_transport = null;

fwrite( STDOUT, 'OK smartucf-remote-reject ' . $mtuc_rr_assert_count . " assertions\n" );
exit( 0 );
