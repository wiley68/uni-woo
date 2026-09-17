<?php
/**
 * SmartUCF Process 1 durable lifecycle tests (AUD-WOO-012 Pass 2).
 *
 * Run: php8.1 tests/run-smartucf-lifecycle-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['mtuc_test_options']           = array();
$GLOBALS['mtuc_test_orders_persisted']  = array();
$GLOBALS['mtuc_test_block_order_save']  = false;
$GLOBALS['mtuc_test_block_bank_status'] = false;
$GLOBALS['mtuc_submission_lock_fence']  = null;
$mtuc_su_assert_count                   = 0;

/**
 * @param bool   $cond Condition.
 * @param string $msg  Message.
 * @return void
 */
function mtuc_su_lc_assert( bool $cond, string $msg ): void {
	global $mtuc_su_assert_count;
	++$mtuc_su_assert_count;
	if ( ! $cond ) {
		fwrite( STDERR, "FAIL: {$msg}\n" );
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

if ( ! function_exists( 'esc_url_raw' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url_raw( $url ) {
		return (string) $url;
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
	 * @param string $option Option.
	 * @param mixed  $value Value.
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
	 * Order stub with explicit persistence boundary.
	 */
	class WC_Order {
		/** @var int */
		public $id = 100;
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var list<string> */
		public $notes = array();

		public function get_id(): int {
			return $this->id;
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
			if ( ! empty( $GLOBALS['mtuc_test_block_order_save'] ) ) {
				return;
			}
			$GLOBALS['mtuc_test_orders_persisted'][ $this->id ] = $this->meta;
		}
	}
}

if ( ! function_exists( 'wc_get_order' ) ) {
	/**
	 * Independent reload from persisted store (not the caller's in-memory object).
	 *
	 * @param int $id Order ID.
	 * @return WC_Order|false
	 */
	function wc_get_order( $id ) {
		$id = (int) $id;
		if ( ! isset( $GLOBALS['mtuc_test_orders_persisted'][ $id ] ) ) {
			return false;
		}
		$fresh       = new WC_Order();
		$fresh->id   = $id;
		$fresh->meta = $GLOBALS['mtuc_test_orders_persisted'][ $id ];
		return $fresh;
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-submission-lock.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-lifecycle.php';

if ( ! defined( 'MTUC_ORDER_META_PREFIX' ) ) {
	define( 'MTUC_ORDER_META_PREFIX', '_mtuc_' );
}
if ( ! defined( 'MTUC_ORDER_META_BANK_STATUS' ) ) {
	define( 'MTUC_ORDER_META_BANK_STATUS', '_mtuc_bank_status' );
}
if ( ! defined( 'MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE' ) ) {
	define( 'MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE', '_mtuc_bank_unavailable_notice' );
}
if ( ! defined( 'MTUC_ORDER_META_SMARTUCF_REDIRECT_URL' ) ) {
	define( 'MTUC_ORDER_META_SMARTUCF_REDIRECT_URL', '_mtuc_smartucf_redirect_url' );
}
if ( ! defined( 'MTUC_BANK_STATUS_SENT_PROCESS1' ) ) {
	define( 'MTUC_BANK_STATUS_SENT_PROCESS1', 'bank_sent_process1' );
}
if ( ! defined( 'MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF' ) ) {
	define( 'MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF', 'bank_send_failed_smartucf' );
}

if ( ! function_exists( 'mtuc_record_order_bank_status' ) ) {
	/**
	 * Mirrors the REVIEW-02 contract: true when the local fact is durable,
	 * WP_Error when it was never claimed.
	 *
	 * @param WC_Order             $order Order.
	 * @param string               $status_key Status.
	 * @param array<string, mixed> $options Options.
	 * @return true|WP_Error
	 */
	function mtuc_record_order_bank_status( WC_Order $order, string $status_key, array $options = array() ) {
		unset( $options );
		if ( ! empty( $GLOBALS['mtuc_test_block_bank_status'] ) ) {
			return new WP_Error( 'mtuc_bank_status_not_durable', 'blocked' );
		}
		$order->update_meta_data( MTUC_ORDER_META_BANK_STATUS, $status_key );
		$order->save();

		return true;
	}
}

if ( ! function_exists( 'mtuc_fail_order_on_smartucf_error' ) ) {
	/**
	 * @param WC_Order        $order Order.
	 * @param WP_Error|string $error_or_reason Error.
	 * @param string          $error_code Code.
	 * @return void
	 */
	function mtuc_fail_order_on_smartucf_error( WC_Order $order, $error_or_reason = '', string $error_code = '' ): void {
		if ( $error_or_reason instanceof WP_Error
			&& function_exists( 'mtuc_is_smartucf_ambiguous_error' )
			&& mtuc_is_smartucf_ambiguous_error( $error_or_reason )
		) {
			mtuc_record_smartucf_start_outcome_unknown( $order, $error_or_reason );
			return;
		}
		$order->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF );
		$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'missing' );
		$order->save();
		unset( $error_code );
	}
}

if ( ! function_exists( 'mtuc_is_ssl_presend_error_code' ) ) {
	/**
	 * @param string $error_code Code.
	 * @return bool
	 */
	function mtuc_is_ssl_presend_error_code( string $error_code ): bool {
		return in_array(
			$error_code,
			array( 'mtuc_smartucf_missing_ssl', 'mtuc_smartucf_untrusted_service', 'mtuc_smartucf_untrusted_url' ),
			true
		);
	}
}

/**
 * @return array<string, mixed>
 */
function mtuc_su_lc_shop(): array {
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
 * @param WC_Order $order Order.
 * @return void
 */
function mtuc_su_lc_seed_cp( WC_Order $order ): void {
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 55 );
	$order->update_meta_data( '_mtuc_cp_create_outcome', 'created' );
}

/**
 * Arm a submission fence for distinct worker tokens.
 *
 * @param string $lock_key Lock key.
 * @param string $owner Owner token.
 * @return void
 */
function mtuc_su_lc_arm_fence( string $lock_key, string $owner ): void {
	$now     = time();
	$payload = mtuc_encode_submission_lock_payload( $owner, $now, $now + 120, MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP );
	$GLOBALS['mtuc_test_options'][ mtuc_submission_lock_option_key( $lock_key ) ] = $payload;
	mtuc_arm_submission_lock_fence( $lock_key, $owner );
}

/**
 * Reset shared test state.
 *
 * @return void
 */
function mtuc_su_lc_reset(): void {
	$GLOBALS['mtuc_test_options']           = array();
	$GLOBALS['mtuc_test_orders_persisted']  = array();
	$GLOBALS['mtuc_test_block_order_save']  = false;
	$GLOBALS['mtuc_test_block_bank_status'] = false;
	$GLOBALS['mtuc_test_force_cas_update_fail'] = false;
	$GLOBALS['mtuc_test_after_smartucf_p1_cas'] = null;
	$GLOBALS['mtuc_submission_lock_fence']  = null;
	mtuc_clear_smartucf_p1_claim_owner_context();
	Mtuc_Smartucf_Api_Client::$http_transport = null;
	Mtuc_Smartucf_Api_Client::$certificate_synchronizer = null;
}

// ---------------------------------------------------------------------------
// F01 — exact CAS transport authorization
// ---------------------------------------------------------------------------

mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 501;
mtuc_su_lc_arm_fence( 'lock-501', 'exec-A' );
$acq = mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_su_lc_assert( is_array( $acq ) && true === $acq['acquired'], 'A acquires armed claim' );
$owner_a = (string) $acq['claim']['owner'];

$ok1 = mtuc_mark_smartucf_p1_transport_boundary( 501 );
mtuc_su_lc_assert( true === $ok1, 'A CAS armed→sent_unknown grants transport once' );
$claim = mtuc_get_smartucf_p1_claim( 501 );
mtuc_su_lc_assert( is_array( $claim ) && MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $claim['state'], 'state sent_unknown after CAS' );
mtuc_su_lc_assert( $owner_a === (string) $claim['owner'], 'owner preserved across CAS' );

$ok2 = mtuc_mark_smartucf_p1_transport_boundary( 501 );
mtuc_su_lc_assert( false === $ok2, 'second boundary on sent_unknown denied' );

// sent_unknown must deny (explicit).
mtuc_su_lc_assert( false === mtuc_mark_smartucf_p1_transport_boundary( 501 ), 'sent_unknown denies transport' );

// confirmed must deny.
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 502;
mtuc_su_lc_arm_fence( 'lock-502', 'exec-A' );
$acq = mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_su_lc_assert( is_array( $acq ), '502 acquire' );
$key = mtuc_smartucf_p1_claim_option_key( 502 );
$raw = mtuc_get_option_raw_value( $key );
$c   = mtuc_decode_smartucf_p1_claim( (string) $raw );
$c['state'] = MTUC_SMARTUCF_P1_CLAIM_CONFIRMED;
mtuc_store_smartucf_p1_claim( 502, $c, $raw );
mtuc_su_lc_assert( false === mtuc_mark_smartucf_p1_transport_boundary( 502 ), 'confirmed denies transport' );

/**
 * @param string|int $order_no Order number for SmartUCF payload.
 * @return array<string, string>
 */
function mtuc_su_lc_session_payload( $order_no ): array {
	return array(
		'orderNo' => (string) $order_no,
		'user'    => 'demo-user',
		'pass'    => 'demo-pass',
	);
}


// missing claim must deny + curl not called.
mtuc_su_lc_reset();
mtuc_su_lc_arm_fence( 'lock-503', 'exec-A' );
mtuc_set_smartucf_p1_claim_owner_context( 503, 'orphan-owner' );
$http_calls = 0;
Mtuc_Smartucf_Api_Client::$http_transport = function () use ( &$http_calls ) {
	++$http_calls;
	return array(
		'body'       => wp_json_encode( array( 'sucfOnlineSessionID' => 'X' ) ),
		'curl_error' => '',
		'http_code'  => 200,
	);
};
$r_miss = Mtuc_Smartucf_Api_Client::start_session( mtuc_su_lc_session_payload( '503' ), mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_wp_error( $r_miss ), 'missing claim denies start_session' );
mtuc_su_lc_assert( 'mtuc_smartucf_transport_not_authorized' === $r_miss->get_error_code(), 'missing claim denied code' );
mtuc_su_lc_assert( 0 === $http_calls, 'missing claim must not call transport' );

// Malformed/unreadable durable claim must fail closed (Pass 4) — option exists, JSON invalid.
mtuc_su_lc_reset();
$order_mal = new WC_Order();
$order_mal->id = 5031;
mtuc_su_lc_seed_cp( $order_mal );
mtuc_su_lc_arm_fence( 'lock-5031', 'exec-A' );
$malformed_raw = '{not-valid-json-claim';
$claim_key     = mtuc_smartucf_p1_claim_option_key( 5031 );
$GLOBALS['mtuc_test_options'][ $claim_key ] = $malformed_raw;
mtuc_set_smartucf_p1_claim_owner_context( 5031, 'worker-A' );

mtuc_su_lc_assert( null === mtuc_get_smartucf_p1_claim( 5031 ), 'malformed claim not parseable as valid claim' );
mtuc_su_lc_assert( false === mtuc_mark_smartucf_p1_transport_boundary( 5031 ), 'malformed claim boundary denied' );
mtuc_su_lc_assert(
	$malformed_raw === (string) ( $GLOBALS['mtuc_test_options'][ $claim_key ] ?? '' ),
	'malformed claim not silently recreated/normalized'
);

$http_calls = 0;
Mtuc_Smartucf_Api_Client::$http_transport = function () use ( &$http_calls ) {
	++$http_calls;
	return array(
		'body'       => wp_json_encode( array( 'sucfOnlineSessionID' => 'MustNotSend' ) ),
		'curl_error' => '',
		'http_code'  => 200,
	);
};
$r_mal = Mtuc_Smartucf_Api_Client::start_session( mtuc_su_lc_session_payload( '5031'  ), mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_wp_error( $r_mal ), 'malformed claim denies start_session' );
mtuc_su_lc_assert( 'mtuc_smartucf_transport_not_authorized' === $r_mal->get_error_code(), 'malformed claim client denial code' );
mtuc_su_lc_assert( 0 === $http_calls, 'malformed claim transport count = 0' );
mtuc_su_lc_assert(
	$malformed_raw === (string) ( $GLOBALS['mtuc_test_options'][ $claim_key ] ?? '' ),
	'malformed claim still present after start_session denial'
);
mtuc_su_lc_assert( null === mtuc_decode_smartucf_p1_claim( $malformed_raw ), 'malformed payload still not treated as armed/sent_unknown' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $order_mal->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'malformed claim no bank_sent_process1' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order_mal->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'malformed claim no bank_send_failed_smartucf' );

// Structure-missing required fields (order_id/state) is also unreadable.
mtuc_su_lc_reset();
$order_struct = new WC_Order();
$order_struct->id = 5032;
mtuc_su_lc_arm_fence( 'lock-5032', 'exec-A' );
$struct_raw = wp_json_encode( array( 'owner' => 'worker-A', 'process' => 1 ) ); // missing order_id + state
$struct_key = mtuc_smartucf_p1_claim_option_key( 5032 );
$GLOBALS['mtuc_test_options'][ $struct_key ] = $struct_raw;
mtuc_set_smartucf_p1_claim_owner_context( 5032, 'worker-A' );
mtuc_su_lc_assert( false === mtuc_mark_smartucf_p1_transport_boundary( 5032 ), 'struct-incomplete claim boundary denied' );
$http_calls = 0;
Mtuc_Smartucf_Api_Client::$http_transport = function () use ( &$http_calls ) {
	++$http_calls;
	return array( 'body' => '{}', 'curl_error' => '', 'http_code' => 200 );
};
$r_struct = Mtuc_Smartucf_Api_Client::start_session( mtuc_su_lc_session_payload( '5032'  ), mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_wp_error( $r_struct ) && 0 === $http_calls, 'struct-incomplete claim no transport' );
mtuc_su_lc_assert( $struct_raw === (string) $GLOBALS['mtuc_test_options'][ $struct_key ], 'struct-incomplete claim not rewritten' );

// B cannot acquire A's armed claim.
mtuc_su_lc_reset();
$order_a = new WC_Order();
$order_a->id = 504;
mtuc_su_lc_arm_fence( 'lock-504', 'exec-A' );
$acq_a = mtuc_acquire_smartucf_p1_send_claim( $order_a );
mtuc_su_lc_assert( is_array( $acq_a ), 'A owns 504' );
mtuc_clear_smartucf_p1_claim_owner_context();
$order_b = new WC_Order();
$order_b->id = 504;
$acq_b = mtuc_acquire_smartucf_p1_send_claim( $order_b );
mtuc_su_lc_assert( is_wp_error( $acq_b ), 'B fail-closed on A armed claim' );
mtuc_su_lc_assert( 'mtuc_smartucf_claim_owned' === $acq_b->get_error_code(), 'B claim_owned code' );

// Stale-owner interleaving: A armed, B cannot take claim; only A with context+fence may CAS.
// Model B as new execution owner with separate order path denied; A resumes after B "active".
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 505;
mtuc_su_lc_arm_fence( 'lock-505', 'exec-A' );
$acq_a = mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_su_lc_assert( is_array( $acq_a ), '505 A acquire' );
$owner_a = (string) $acq_a['claim']['owner'];

// B becomes execution owner but cannot take claim (fail closed).
mtuc_su_lc_arm_fence( 'lock-505', 'exec-B' );
mtuc_clear_smartucf_p1_claim_owner_context();
mtuc_set_smartucf_p1_claim_owner_context( 505, 'fake-B-owner' );
mtuc_su_lc_assert( false === mtuc_mark_smartucf_p1_transport_boundary( 505 ), 'B denied — wrong claim owner' );

// A resumes with claim ownership but durable execution lock is now B.
mtuc_set_smartucf_p1_claim_owner_context( 505, $owner_a );
// Keep durable lock as B, re-arm A's request fence so renew fails against B's option row.
$now = time();
$GLOBALS['mtuc_test_options'][ mtuc_submission_lock_option_key( 'lock-505' ) ] = mtuc_encode_submission_lock_payload(
	'exec-B',
	$now,
	$now + 120,
	MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP
);
mtuc_arm_submission_lock_fence( 'lock-505', 'exec-A' );
mtuc_su_lc_assert( false === mtuc_mark_smartucf_p1_transport_boundary( 505 ), 'A denied — lost execution ownership' );

// Restore A as both claim+execution owner → CAS succeeds.
mtuc_su_lc_arm_fence( 'lock-505', 'exec-A' );
mtuc_set_smartucf_p1_claim_owner_context( 505, $owner_a );
mtuc_su_lc_assert( true === mtuc_mark_smartucf_p1_transport_boundary( 505 ), 'only matching claim+exec owner may CAS' );

// CAS loser: two workers attempt armed→sent_unknown; only one wins.
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 506;
mtuc_su_lc_arm_fence( 'lock-506', 'exec-A' );
$acq = mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_su_lc_assert( is_array( $acq ), '506 acquire' );
$owner_a = (string) $acq['claim']['owner'];
$key     = mtuc_smartucf_p1_claim_option_key( 506 );
$raw     = mtuc_get_option_raw_value( $key );

// Worker B crafts competing CAS with wrong expected raw after A already changed — first A wins.
mtuc_su_lc_assert( true === mtuc_mark_smartucf_p1_transport_boundary( 506 ), 'CAS winner A' );
// B uses stale raw armed payload.
$stale = mtuc_decode_smartucf_p1_claim( (string) $raw );
$stale['state'] = MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN;
$stale['owner'] = 'B-owner';
mtuc_su_lc_assert( false === mtuc_store_smartucf_p1_claim( 506, $stale, (string) $raw ), 'CAS loser B with stale expected' );
$after = mtuc_get_smartucf_p1_claim( 506 );
mtuc_su_lc_assert( is_array( $after ) && $owner_a === (string) $after['owner'], 'winner owner retained' );

// Execution lock ownership lost while claim owned.
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 507;
mtuc_su_lc_arm_fence( 'lock-507', 'exec-A' );
$acq = mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_su_lc_assert( is_array( $acq ), '507 acquire' );
$owner_507 = (string) $acq['claim']['owner'];
mtuc_set_smartucf_p1_claim_owner_context( 507, $owner_507 );
$now = time();
$GLOBALS['mtuc_test_options'][ mtuc_submission_lock_option_key( 'lock-507' ) ] = mtuc_encode_submission_lock_payload(
	'exec-OTHER',
	$now,
	$now + 120,
	MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP
);
mtuc_arm_submission_lock_fence( 'lock-507', 'exec-A' );
mtuc_su_lc_assert( false === mtuc_mark_smartucf_p1_transport_boundary( 507 ), 'claim owner without exec ownership denied' );

// Ownership loss AFTER successful CAS (mandatory Pass 3 race): A wins CAS, fence stolen, transport denied.
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 509;
mtuc_su_lc_arm_fence( 'lock-509', 'exec-A' );
$acq = mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_su_lc_assert( is_array( $acq ), '509 A acquire' );
$owner_a = (string) $acq['claim']['owner'];
mtuc_set_smartucf_p1_claim_owner_context( 509, $owner_a );

$GLOBALS['mtuc_test_after_smartucf_p1_cas'] = function ( $order_id ) {
	unset( $order_id );
	$now = time();
	// Durable execution ownership replaced by B between CAS and post-CAS check.
	$GLOBALS['mtuc_test_options'][ mtuc_submission_lock_option_key( 'lock-509' ) ] = mtuc_encode_submission_lock_payload(
		'exec-B',
		$now,
		$now + 120,
		MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP
	);
	// A's request fence still claims exec-A → still_owned fails against B's option row.
	mtuc_arm_submission_lock_fence( 'lock-509', 'exec-A' );
};

$http_calls = 0;
Mtuc_Smartucf_Api_Client::$http_transport = function () use ( &$http_calls ) {
	++$http_calls;
	return array(
		'body'       => wp_json_encode( array( 'sucfOnlineSessionID' => 'ShouldNotSend' ) ),
		'curl_error' => '',
		'http_code'  => 200,
	);
};
$r_race = Mtuc_Smartucf_Api_Client::start_session( mtuc_su_lc_session_payload( '509'  ), mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_wp_error( $r_race ), 'post-CAS fence loss denies start_session' );
mtuc_su_lc_assert( 'mtuc_smartucf_transport_not_authorized' === $r_race->get_error_code(), 'post-CAS denial code' );
mtuc_su_lc_assert( 0 === $http_calls, 'post-CAS fence loss must not curl_exec' );
$claim_509 = mtuc_get_smartucf_p1_claim( 509 );
mtuc_su_lc_assert( is_array( $claim_509 ) && MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $claim_509['state'], 'post-CAS loss keeps sent_unknown' );
mtuc_su_lc_assert( false === mtuc_mark_smartucf_p1_transport_boundary( 509 ), 'sent_unknown still denies after race' );

// B later enters: sees sent_unknown → no transport (no duplicate send).
mtuc_clear_smartucf_p1_claim_owner_context();
mtuc_su_lc_arm_fence( 'lock-509', 'exec-B' );
$order_b = new WC_Order();
$order_b->id = 509;
$acq_b = mtuc_acquire_smartucf_p1_send_claim( $order_b );
mtuc_su_lc_assert( is_wp_error( $acq_b ) && 'mtuc_smartucf_claim_ambiguous' === $acq_b->get_error_code(), 'B denied after A post-CAS race' );
mtuc_set_smartucf_p1_claim_owner_context( 509, 'B-owner' );
mtuc_su_lc_assert( false === mtuc_mark_smartucf_p1_transport_boundary( 509 ), 'B boundary denied on sent_unknown' );
$http_calls = 0;
$r_b = Mtuc_Smartucf_Api_Client::start_session( mtuc_su_lc_session_payload( '509'  ), mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_wp_error( $r_b ) && 0 === $http_calls, 'B cannot transport after race' );

// Handle post-CAS denial as ambiguous (no bank_send_failed_smartucf).
$order_race = new WC_Order();
$order_race->id = 509;
mtuc_su_lc_seed_cp( $order_race );
mtuc_handle_smartucf_start_error( $order_race, $r_race );
mtuc_su_lc_assert( 'unknown' === $order_race->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'post-CAS denial → unknown' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order_race->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'post-CAS denial not definitive fail' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $order_race->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'post-CAS denial no bank_sent' );

$GLOBALS['mtuc_test_after_smartucf_p1_cas'] = null;

// Remote-commit/local-crash: sent_unknown → B must not send.
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 508;
mtuc_su_lc_arm_fence( 'lock-508', 'exec-A' );
$acq = mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_mark_smartucf_p1_transport_boundary( 508 );
mtuc_clear_smartucf_p1_claim_owner_context();
$order_b = new WC_Order();
$order_b->id = 508;
mtuc_su_lc_arm_fence( 'lock-508', 'exec-B' );
$acq_b = mtuc_acquire_smartucf_p1_send_claim( $order_b );
mtuc_su_lc_assert( is_wp_error( $acq_b ) && 'mtuc_smartucf_claim_ambiguous' === $acq_b->get_error_code(), 'B sees sent_unknown' );
mtuc_su_lc_assert( mtuc_smartucf_p1_second_start_prohibited( $order_b ), 'second start prohibited after crash' );
$http_calls = 0;
Mtuc_Smartucf_Api_Client::$http_transport = function () use ( &$http_calls ) {
	++$http_calls;
	return array( 'body' => '{}', 'curl_error' => '', 'http_code' => 200 );
};
mtuc_set_smartucf_p1_claim_owner_context( 508, 'B' );
mtuc_su_lc_assert( false === mtuc_mark_smartucf_p1_transport_boundary( 508 ), 'B boundary denied on sent_unknown' );
mtuc_su_lc_assert( 0 === $http_calls, 'no transport after crash window' );

// ---------------------------------------------------------------------------
// F02/F03 regression — explicit ambiguous classes after authorized transport
// ---------------------------------------------------------------------------

/**
 * Authorize one transport for order id, then run client against injected transport.
 *
 * @param int      $order_id Order ID.
 * @param callable $transport Transport stub.
 * @return array{0:WC_Order,1:WP_Error|array}
 */
function mtuc_su_lc_authorized_start( int $order_id, callable $transport ) {
	mtuc_su_lc_reset();
	$order = new WC_Order();
	$order->id = $order_id;
	mtuc_su_lc_seed_cp( $order );
	mtuc_su_lc_arm_fence( 'lock-' . $order_id, 'exec-A' );
	mtuc_acquire_smartucf_p1_send_claim( $order );
	Mtuc_Smartucf_Api_Client::$http_transport = $transport;
	$result = Mtuc_Smartucf_Api_Client::start_session( mtuc_su_lc_session_payload( $order_id ), mtuc_su_lc_shop() );
	return array( $order, $result );
}

mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 601;
mtuc_su_lc_seed_cp( $order );
mtuc_su_lc_arm_fence( 'lock-601', 'exec-A' );
mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_mark_smartucf_p1_transport_boundary( 601 );
$timeout_err = new WP_Error( 'mtuc_smartucf_http_error', 'timeout', array( 'curl_error' => 'Operation timed out', 'http_code' => 0 ) );
mtuc_handle_smartucf_start_error( $order, $timeout_err );
mtuc_su_lc_assert( 'unknown' === $order->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'timeout unknown' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'timeout no definitive fail' );
mtuc_su_lc_assert( MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === mtuc_get_smartucf_p1_claim( 601 )['state'], 'timeout keeps sent_unknown' );

// F02: generic cURL/connection error after boundary.
list( $order_curl, $r_curl ) = mtuc_su_lc_authorized_start(
	610,
	function () {
		return array(
			'body'       => '',
			'curl_error' => 'Recv failure: Connection reset by peer',
			'http_code'  => 0,
		);
	}
);
mtuc_su_lc_assert( is_wp_error( $r_curl ) && 'mtuc_smartucf_http_error' === $r_curl->get_error_code(), 'curl error code' );
mtuc_su_lc_assert( MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === mtuc_get_smartucf_p1_claim( 610 )['state'], 'curl error left sent_unknown' );
mtuc_handle_smartucf_start_error( $order_curl, $r_curl );
mtuc_su_lc_assert( 'unknown' === $order_curl->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'curl error outcome unknown' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order_curl->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'curl error no definitive fail' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $order_curl->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'curl error no bank_sent' );
mtuc_su_lc_assert( mtuc_smartucf_p1_second_start_prohibited( $order_curl ), 'curl error second start denied' );

// F02: empty response.
list( $order_empty, $r_empty ) = mtuc_su_lc_authorized_start(
	611,
	function () {
		return array(
			'body'       => '',
			'curl_error' => '',
			'http_code'  => 200,
		);
	}
);
mtuc_su_lc_assert( is_wp_error( $r_empty ) && 'mtuc_smartucf_empty_response' === $r_empty->get_error_code(), 'empty response code' );
mtuc_handle_smartucf_start_error( $order_empty, $r_empty );
mtuc_su_lc_assert( 'unknown' === $order_empty->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'empty outcome unknown' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order_empty->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'empty no definitive fail' );
mtuc_su_lc_assert( MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === mtuc_get_smartucf_p1_claim( 611 )['state'], 'empty keeps sent_unknown' );

// F02: invalid JSON.
list( $order_json, $r_json ) = mtuc_su_lc_authorized_start(
	612,
	function () {
		return array(
			'body'       => '{not-json',
			'curl_error' => '',
			'http_code'  => 200,
		);
	}
);
mtuc_su_lc_assert( is_wp_error( $r_json ) && 'mtuc_smartucf_invalid_json' === $r_json->get_error_code(), 'invalid json code' );
mtuc_handle_smartucf_start_error( $order_json, $r_json );
mtuc_su_lc_assert( 'unknown' === $order_json->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'invalid json outcome unknown' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order_json->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'invalid json no definitive fail' );

// F02: missing session ID after 2xx.
list( $order_nosess, $r_nosess ) = mtuc_su_lc_authorized_start(
	613,
	function () {
		return array(
			'body'       => wp_json_encode( array( 'status' => 'ok' ) ),
			'curl_error' => '',
			'http_code'  => 200,
		);
	}
);
mtuc_su_lc_assert( is_wp_error( $r_nosess ) && 'mtuc_smartucf_no_session' === $r_nosess->get_error_code(), 'missing session code' );
mtuc_handle_smartucf_start_error( $order_nosess, $r_nosess );
mtuc_su_lc_assert( 'unknown' === $order_nosess->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'missing session outcome unknown' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order_nosess->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'missing session no definitive fail' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $order_nosess->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'missing session no bank_sent' );
mtuc_su_lc_assert( mtuc_smartucf_p1_second_start_prohibited( $order_nosess ), 'missing session no resend' );

// F02: invalid session ID (untrusted application path / injection).
list( $order_badsess, $r_badsess ) = mtuc_su_lc_authorized_start(
	614,
	function () {
		return array(
			'body'       => wp_json_encode( array( 'sucfOnlineSessionID' => 'bad/../sess' ) ),
			'curl_error' => '',
			'http_code'  => 200,
		);
	}
);
mtuc_su_lc_assert( is_wp_error( $r_badsess ), 'invalid session id fails' );
mtuc_su_lc_assert(
	in_array( $r_badsess->get_error_code(), array( 'mtuc_smartucf_invalid_session_id', 'mtuc_smartucf_untrusted_application' ), true ),
	'invalid session id error class'
);
mtuc_handle_smartucf_start_error( $order_badsess, $r_badsess );
mtuc_su_lc_assert( 'unknown' === $order_badsess->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'invalid session outcome unknown' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order_badsess->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'invalid session not definitive smartucf fail' );
mtuc_su_lc_assert( MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === mtuc_get_smartucf_p1_claim( 614 )['state'], 'invalid session keeps sent_unknown' );

mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 701;
mtuc_su_lc_seed_cp( $order );
mtuc_su_lc_arm_fence( 'lock-701', 'exec-A' );
mtuc_acquire_smartucf_p1_send_claim( $order );
Mtuc_Smartucf_Api_Client::$http_transport = function () {
	return array(
		'body'       => wp_json_encode( array( 'sucfOnlineSessionID' => 'SessFrom500' ) ),
		'curl_error' => '',
		'http_code'  => 500,
	);
};
$r500 = Mtuc_Smartucf_Api_Client::start_session( mtuc_su_lc_session_payload( '701'  ), mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_wp_error( $r500 ) && 'mtuc_smartucf_http_status' === $r500->get_error_code(), '500 not success' );
mtuc_handle_smartucf_start_error( $order, $r500 );
mtuc_su_lc_assert( 'unknown' === $order->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), '500 unknown' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ), '500 no bank_sent' );

// ---------------------------------------------------------------------------
// F04 — independent durable reload
// ---------------------------------------------------------------------------

mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 801;
mtuc_su_lc_seed_cp( $order );
mtuc_su_lc_arm_fence( 'lock-801', 'exec-A' );
mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_mark_smartucf_p1_transport_boundary( 801 );
$redirect = Mtuc_Smartucf_Api_Client::get_application_redirect_url( mtuc_su_lc_shop(), 'SessOK99' );
$final    = mtuc_finalize_smartucf_p1_success( $order, 'SessOK99', $redirect, mtuc_su_lc_shop() );
mtuc_su_lc_assert( true === $final, 'finalize with durable reload' );
$fresh = wc_get_order( 801 );
mtuc_su_lc_assert( $fresh instanceof WC_Order, 'fresh order exists' );
mtuc_su_lc_assert( 'SessOK99' === (string) $fresh->get_meta( MTUC_ORDER_META_SMARTUCF_SESSION_ID ), 'fresh session' );
mtuc_su_lc_assert( $redirect === (string) $fresh->get_meta( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL ), 'fresh redirect' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === (string) $fresh->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'fresh bank_sent after durable proof' );

// Session save failure → no bank_sent, claim not confirmed.
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 802;
mtuc_su_lc_seed_cp( $order );
mtuc_su_lc_arm_fence( 'lock-802', 'exec-A' );
mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_mark_smartucf_p1_transport_boundary( 802 );
$GLOBALS['mtuc_test_block_order_save'] = true;
$final = mtuc_finalize_smartucf_p1_success( $order, 'SessFail', $redirect, mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_wp_error( $final ), 'save failure blocks finalize' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'no bank_sent on save fail' );
mtuc_su_lc_assert( MTUC_SMARTUCF_P1_CLAIM_CONFIRMED !== (string) ( mtuc_get_smartucf_p1_claim( 802 )['state'] ?? '' ), 'claim not confirmed on save fail' );
$GLOBALS['mtuc_test_block_order_save'] = false;
// Later re-entry: no durable session → second start still prohibited by sent_unknown.
mtuc_su_lc_assert( mtuc_smartucf_p1_second_start_prohibited( $order ) || MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === mtuc_get_smartucf_p1_claim( 802 )['state'], 'sent_unknown blocks resend after save fail' );

// Fresh reload missing redirect (in-memory has it, persist omits redirect).
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 803;
mtuc_su_lc_seed_cp( $order );
$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'SessOnly' );
$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', 'SessOnly' );
// Persist only session — no redirect key.
$GLOBALS['mtuc_test_orders_persisted'][803] = array(
	MTUC_ORDER_META_PREFIX . 'cp_order_id'     => 55,
	MTUC_ORDER_META_SMARTUCF_SESSION_ID        => 'SessOnly',
	MTUC_ORDER_META_PREFIX . 'smartucf_session_id' => 'SessOnly',
);
// Force finalize to write then reload mismatch: block save so in-memory redirect never persists,
// then unblock and manually seed incomplete persist before calling verify path via finalize.
$GLOBALS['mtuc_test_block_order_save'] = true;
$final = mtuc_finalize_smartucf_p1_success( $order, 'SessOnly', $redirect, mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_wp_error( $final ), 'missing durable redirect blocks bank_sent' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'no bank_sent without durable redirect' );
$GLOBALS['mtuc_test_block_order_save'] = false;

// Bank-status save failure: session+redirect durable, status not written → recover without SmartUCF.
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 804;
mtuc_su_lc_seed_cp( $order );
mtuc_su_lc_arm_fence( 'lock-804', 'exec-A' );
mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_mark_smartucf_p1_transport_boundary( 804 );
$GLOBALS['mtuc_test_block_bank_status'] = true;
$final = mtuc_finalize_smartucf_p1_success( $order, 'SessBank', $redirect, mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_wp_error( $final ), 'bank status save failure blocks finalize success' );
$GLOBALS['mtuc_test_block_bank_status'] = false;
mtuc_su_lc_assert( 'SessBank' === (string) wc_get_order( 804 )->get_meta( MTUC_ORDER_META_SMARTUCF_SESSION_ID ), 'session durable' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 !== (string) wc_get_order( 804 )->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'bank status missing after inject' );
$http_calls = 0;
Mtuc_Smartucf_Api_Client::$http_transport = function () use ( &$http_calls ) {
	++$http_calls;
	return array( 'body' => '{}', 'curl_error' => '', 'http_code' => 200 );
};
$reenter = new WC_Order();
$reenter->id = 804;
$reenter->meta = $GLOBALS['mtuc_test_orders_persisted'][804];
$recovered = mtuc_try_recover_smartucf_p1_session( $reenter, mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_array( $recovered ), 're-entry recovers session' );
mtuc_su_lc_assert( 0 === $http_calls, 'recovery does not call SmartUCF' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === (string) $reenter->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'local bank status completed on retry' );

// Confirmed claim write failure: session+bank durable, claim stays sent_unknown → no resend.
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 805;
mtuc_su_lc_seed_cp( $order );
mtuc_su_lc_arm_fence( 'lock-805', 'exec-A' );
mtuc_acquire_smartucf_p1_send_claim( $order );
mtuc_mark_smartucf_p1_transport_boundary( 805 );
$GLOBALS['mtuc_test_force_cas_update_fail'] = true;
// After session save, confirm uses CAS — force fail by leaving claim as sent_unknown manually after finalize.
$GLOBALS['mtuc_test_force_cas_update_fail'] = false;
$final = mtuc_finalize_smartucf_p1_success( $order, 'SessClaim', $redirect, mtuc_su_lc_shop() );
mtuc_su_lc_assert( true === $final, 'finalize ok' );
// Force claim back to sent_unknown simulating confirm write failure.
$key = mtuc_smartucf_p1_claim_option_key( 805 );
$raw = mtuc_get_option_raw_value( $key );
$c   = mtuc_decode_smartucf_p1_claim( (string) $raw );
$c['state'] = MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN;
$GLOBALS['mtuc_test_options'][ $key ] = mtuc_encode_smartucf_p1_claim( $c );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SENT_PROCESS1 === (string) wc_get_order( 805 )->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'bank durable despite claim' );
mtuc_su_lc_assert( mtuc_smartucf_p1_second_start_prohibited( wc_get_order( 805 ) ), 'session evidence blocks resend' );
$http_calls = 0;
Mtuc_Smartucf_Api_Client::$http_transport = function () use ( &$http_calls ) {
	++$http_calls;
	return array( 'body' => '{}', 'curl_error' => '', 'http_code' => 200 );
};
$again = mtuc_try_recover_smartucf_p1_session( wc_get_order( 805 ), mtuc_su_lc_shop() );
mtuc_su_lc_assert( is_array( $again ), 'recovery recognizes completed local evidence' );
mtuc_su_lc_assert( 0 === $http_calls, 'no resend when claim confirm failed' );

// Definitive pre-send still releases claim.
mtuc_su_lc_reset();
$order = new WC_Order();
$order->id = 901;
mtuc_su_lc_arm_fence( 'lock-901', 'exec-A' );
mtuc_acquire_smartucf_p1_send_claim( $order );
$presend = new WP_Error( 'mtuc_smartucf_encode_failed', 'encode' );
mtuc_handle_smartucf_start_error( $order, $presend );
mtuc_su_lc_assert( null === mtuc_get_smartucf_p1_claim( 901 ), 'presend releases claim' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'presend does not persist bank_send_failed_smartucf' );

// Definitive remote reject still persists bank_send_failed_smartucf.
mtuc_su_lc_reset();
$order_def = new WC_Order();
$order_def->id = 902;
$remote = new WP_Error( 'mtuc_smartucf_remote_rejected', 'remote reject' );
mtuc_handle_smartucf_start_error( $order_def, $remote );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF === (string) $order_def->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'definitive remote reject persists bank_send_failed_smartucf' );

// Realistic KOP rejection (HTTP 200 + errorCode/errorText + null session) after transport boundary.
list( $order_kop, $r_kop ) = mtuc_su_lc_authorized_start(
	920,
	function () {
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
	}
);
mtuc_su_lc_assert( is_wp_error( $r_kop ) && 'mtuc_smartucf_remote_rejected' === $r_kop->get_error_code(), 'KOP reject → remote_rejected' );
mtuc_su_lc_assert( MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === mtuc_get_smartucf_p1_claim( 920 )['state'], 'KOP reject crossed boundary' );
mtuc_handle_smartucf_start_error( $order_kop, $r_kop );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF === (string) $order_kop->get_meta( MTUC_ORDER_META_BANK_STATUS ), 'KOP reject → bank_send_failed_smartucf after boundary' );
mtuc_su_lc_assert( 'missing' === (string) $order_kop->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), 'KOP reject outcome missing' );
mtuc_su_lc_assert( MTUC_SMARTUCF_P1_CLAIM_DEFINITIVE_FAILED === mtuc_get_smartucf_p1_claim( 920 )['state'], 'KOP reject claim definitive_failed' );
mtuc_su_lc_assert( ! mtuc_order_has_unresolved_smartucf_ambiguity( $order_kop ), 'KOP reject not unresolved ambiguity' );

// HTTP 4xx with business error payload is definitive (not transport).
list( $order_4xx, $r_4xx ) = mtuc_su_lc_authorized_start(
	921,
	function () {
		return array(
			'body'       => wp_json_encode(
				array(
					'errorCode'           => 400,
					'errorText'           => 'няма такъв КОП за този търговец',
					'sucfOnlineSessionID' => null,
				)
			),
			'curl_error' => '',
			'http_code'  => 400,
		);
	}
);
mtuc_su_lc_assert( is_wp_error( $r_4xx ) && 'mtuc_smartucf_remote_rejected' === $r_4xx->get_error_code(), '4xx business reject → remote_rejected' );
mtuc_handle_smartucf_start_error( $order_4xx, $r_4xx );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF === (string) $order_4xx->get_meta( MTUC_ORDER_META_BANK_STATUS ), '4xx business reject definitive' );

// HTTP 5xx with error-looking body stays ambiguous.
list( $order_5xx, $r_5xx ) = mtuc_su_lc_authorized_start(
	922,
	function () {
		return array(
			'body'       => wp_json_encode(
				array(
					'errorCode'           => 500,
					'errorText'           => 'internal',
					'sucfOnlineSessionID' => null,
				)
			),
			'curl_error' => '',
			'http_code'  => 500,
		);
	}
);
mtuc_su_lc_assert( is_wp_error( $r_5xx ) && 'mtuc_smartucf_http_status' === $r_5xx->get_error_code(), '5xx stays http_status' );
mtuc_handle_smartucf_start_error( $order_5xx, $r_5xx );
mtuc_su_lc_assert( 'unknown' === (string) $order_5xx->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ), '5xx unknown' );
mtuc_su_lc_assert( MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order_5xx->get_meta( MTUC_ORDER_META_BANK_STATUS ), '5xx not definitive' );

Mtuc_Smartucf_Api_Client::$http_transport = null;

fwrite( STDOUT, "OK: {$mtuc_su_assert_count} smartucf lifecycle assertions passed\n" );
exit( 0 );
