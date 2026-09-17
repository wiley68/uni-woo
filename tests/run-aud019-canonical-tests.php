<?php
/**
 * AUD-WOO-019 canonical contracts (F01–F10).
 *
 * Run: php tests/run-aud019-canonical-tests.php
 *
 * Every case here asserts one rule of the remediation: an HTTP 2xx is not a
 * success, an ambiguous outcome is not a failure, an inbound order_id is not an
 * authorisation, and a value that does not fit is rejected instead of silently
 * reshaped.
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$GLOBALS['mtuc_test_options'] = array();
$GLOBALS['mtuc_test_orders']  = array();
$mtuc_a19_assert_count        = 0;

/**
 * @param bool   $ok      Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_a19_assert( bool $ok, string $message ): void {
	global $mtuc_a19_assert_count;
	++$mtuc_a19_assert_count;
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
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
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

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $option  Option.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $option, $default = false ) {
		return $GLOBALS['mtuc_test_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * @param string $option   Option.
	 * @param mixed  $value    Value.
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

if ( ! function_exists( 'add_option' ) ) {
	/**
	 * Insert-if-absent, as wp_options behaves — the basis of the lock claim.
	 *
	 * @param string $option     Option.
	 * @param mixed  $value      Value.
	 * @param string $deprecated Unused.
	 * @param string $autoload   Autoload.
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

if ( ! class_exists( 'Mtuc_Settings', false ) ) {
	/**
	 * Settings stub.
	 */
	class Mtuc_Settings {
		public const OPTION_UNICID     = 'mtuc_unicid';
		public const OPTION_SECRET_KEY = 'mtuc_secret_key';

		/**
		 * @param string $key Option key.
		 * @return string
		 */
		public static function get( $key ) {
			if ( self::OPTION_UNICID === $key ) {
				return 'SHOP-UNICID';
			}
			if ( self::OPTION_SECRET_KEY === $key ) {
				return 'SHOP-SECRET';
			}
			return '';
		}

		public static function is_enabled(): bool {
			return true;
		}
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal order stand-in.
	 */
	class WC_Order {
		/** @var int */
		public $id = 4100;
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var list<string> */
		public $notes = array();

		/**
		 * @param int $id Order ID.
		 */
		public function __construct( int $id = 0 ) {
			if ( $id > 0 ) {
				$this->id = $id;
			}
		}

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
		 * @param string $key   Meta key.
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
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-idempotency.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-module-request-signature-protocol.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-module-request-authenticator.php';

/**
 * Build a canonical CP envelope body.
 *
 * @param bool                 $success Success flag.
 * @param string|null          $error   Machine error code.
 * @param array<string, mixed> $data    Data object.
 * @param string               $message Message.
 * @return string
 */
function mtuc_a19_body( bool $success, $error, array $data = array(), string $message = '' ): string {
	return (string) wp_json_encode(
		array(
			'success' => $success,
			'error'   => $error,
			'message' => $message,
			// REVIEW-05: an empty data object is `{}`; `[]` is a different value.
			'data'    => array() === $data ? (object) array() : $data,
		)
	);
}

// ---------------------------------------------------------------------------
// F02 — HTTP 2xx alone is not success
// ---------------------------------------------------------------------------

$ok = mtuc_decode_cp_envelope( mtuc_a19_body( true, null, array( 'id' => 5 ) ), 200 );
mtuc_a19_assert( is_array( $ok ) && true === $ok['success'], 'F02 canonical success decodes' );
mtuc_a19_assert( is_array( $ok ) && array( 'id' => 5 ) === $ok['data'], 'F02 success preserves data object' );

$failure_2xx = mtuc_decode_cp_envelope( mtuc_a19_body( false, 'validation', array(), 'bad input' ), 200 );
mtuc_a19_assert( is_wp_error( $failure_2xx ), 'F02 success:false on HTTP 200 is a failure' );
mtuc_a19_assert(
	is_wp_error( $failure_2xx ) && 'validation' === mtuc_cp_error_envelope_code( $failure_2xx ),
	'F02 canonical failure exposes its CP error code'
);
mtuc_a19_assert(
	is_wp_error( $failure_2xx ) && 200 === mtuc_cp_error_http_status( $failure_2xx ),
	'F02 failure keeps the transport status as evidence'
);

$success_500 = mtuc_decode_cp_envelope( mtuc_a19_body( true, null, array( 'id' => 5 ) ), 500 );
mtuc_a19_assert(
	is_wp_error( $success_500 ) && 'mtuc_api_invalid_envelope' === $success_500->get_error_code(),
	'F02 success:true on HTTP 500 is not usable'
);

$invalid_cases = array(
	'not json at all'                                         => 'mtuc_api_invalid_json',
	'[1,2,3]'                                                 => 'mtuc_api_invalid_envelope',
	'"a string"'                                              => 'mtuc_api_invalid_envelope',
	'{"success":true,"error":null,"message":"","data":[1,2]}' => 'mtuc_api_invalid_envelope',
	'{"success":true,"error":null,"data":{}}'                 => 'mtuc_api_invalid_envelope',
	'{"success":1,"error":null,"message":"","data":{}}'       => 'mtuc_api_invalid_envelope',
	'{"success":"true","error":null,"message":"","data":{}}'  => 'mtuc_api_invalid_envelope',
	'{"success":true,"error":"oops","message":"","data":{}}'  => 'mtuc_api_invalid_envelope',
	'{"success":false,"error":"Validation","message":"","data":{}}' => 'mtuc_api_invalid_envelope',
	'{"success":false,"error":null,"message":"","data":{}}'    => 'mtuc_api_invalid_envelope',
	'{"success":true,"error":null,"message":"","data":{},"x":1}' => 'mtuc_api_invalid_envelope',
	// REVIEW-05: `[]` is a JSON list, not the canonical empty data object.
	'{"success":true,"error":null,"message":"ok","data":[]}'   => 'mtuc_api_invalid_envelope',
);

foreach ( $invalid_cases as $raw => $expected_code ) {
	$decoded = mtuc_decode_cp_envelope( (string) $raw, 200 );
	mtuc_a19_assert(
		is_wp_error( $decoded ) && $expected_code === $decoded->get_error_code(),
		'F02 rejects non-canonical body: ' . substr( (string) $raw, 0, 48 )
	);
}

$violations = mtuc_decode_cp_envelope( '{"success":true}', 200 );
mtuc_a19_assert(
	is_wp_error( $violations ) && ! empty( $violations->get_error_data()['violations'] ),
	'F02 invalid envelope reports violations'
);
mtuc_a19_assert(
	is_wp_error( $violations ) && false === $violations->get_error_data()['envelope_valid'],
	'F02 invalid envelope is marked as non-canonical'
);
mtuc_a19_assert( '' === mtuc_cp_error_envelope_code( $violations ), 'F02 non-canonical body carries no CP error code' );

mtuc_a19_assert( mtuc_cp_is_json_object( array() ), 'F02 empty array is the canonical empty object' );
mtuc_a19_assert( ! mtuc_cp_is_json_object( array( 1, 2 ) ), 'F02 JSON list is not an object' );

$empty_data_ok = mtuc_decode_cp_envelope( '{"success":true,"error":null,"message":"ok","data":{}}', 200 );
mtuc_a19_assert(
	is_array( $empty_data_ok ) && array() === $empty_data_ok['data'],
	'F02/REVIEW-05 an explicit `{}` data object is accepted'
);
mtuc_a19_assert( ! mtuc_cp_is_canonical_error_code( 'Validation' ), 'F02 uppercase error code rejected' );
mtuc_a19_assert( ! mtuc_cp_is_canonical_error_code( '1_bad' ), 'F02 error code must start with a letter' );
mtuc_a19_assert( mtuc_cp_is_canonical_error_code( 'semantic_conflict' ), 'F02 snake_case error code accepted' );

// ---------------------------------------------------------------------------
// F08 — create payload allowlist + strict create/PATCH contracts
// ---------------------------------------------------------------------------

$allowlist = mtuc_cp_create_payload_allowlist();
mtuc_a19_assert( ! in_array( 'status', $allowlist, true ), 'F08 create allowlist excludes status' );
mtuc_a19_assert( ! in_array( 'status_id', $allowlist, true ), 'F08 create allowlist excludes status_id' );
mtuc_a19_assert( in_array( 'order_id', $allowlist, true ), 'F08 create allowlist keeps order_id' );

$filtered = mtuc_filter_cp_create_payload(
	array(
		'order_id'  => '100',
		'status'    => 'Изпратен',
		'status_id' => '85',
		'egn'       => '9001011234',
		'unicid'    => 'SHOP-UNICID',
		'name'      => 'Иван',
	)
);
mtuc_a19_assert( array( 'order_id' => '100', 'name' => 'Иван' ) === $filtered, 'F08 create payload drops everything off-allowlist' );

/**
 * @param array<string, mixed> $data Response data.
 * @return array<string, mixed>
 */
function mtuc_a19_create_data( array $data = array() ): array {
	return array_merge(
		array(
			'id'         => 7,
			'shop_id'    => 3,
			'order_id'   => '100',
			'unicid'     => 'SHOP-UNICID',
			'created_at' => '2026-01-01T00:00:00+00:00',
		),
		$data
	);
}

$create_request = array( 'order_id' => '100' );
$valid_create   = array(
	'success' => true,
	'error'   => null,
	'message' => '',
	'data'    => mtuc_a19_create_data(),
);
mtuc_a19_assert(
	true === mtuc_validate_cp_create_contract( $valid_create, $create_request, 'SHOP-UNICID' ),
	'F08 complete create response accepted'
);

$create_rejections = array(
	'missing id'           => array( 'id' => null ),
	'float id'             => array( 'id' => 7.0 ),
	'string id'            => array( 'id' => '7' ),
	'zero id'              => array( 'id' => 0 ),
	'bool shop_id'         => array( 'shop_id' => true ),
	'missing shop_id'      => array( 'shop_id' => null ),
	'int order_id'         => array( 'order_id' => 100 ),
	'empty order_id'       => array( 'order_id' => '' ),
	'int unicid'           => array( 'unicid' => 1 ),
	'empty created_at'     => array( 'created_at' => '' ),
	'whitespace created_at' => array( 'created_at' => '   ' ),
);

foreach ( $create_rejections as $label => $override ) {
	$data = mtuc_a19_create_data();
	foreach ( $override as $field => $value ) {
		if ( null === $value ) {
			unset( $data[ $field ] );
			continue;
		}
		$data[ $field ] = $value;
	}

	$result = mtuc_validate_cp_create_contract(
		array( 'success' => true, 'error' => null, 'message' => '', 'data' => $data ),
		$create_request,
		'SHOP-UNICID'
	);
	mtuc_a19_assert(
		is_wp_error( $result ) && 'mtuc_cp_unusable_success' === $result->get_error_code(),
		'F08 create rejects: ' . $label
	);
}

$echo_order = mtuc_validate_cp_create_contract(
	array( 'success' => true, 'error' => null, 'message' => '', 'data' => mtuc_a19_create_data( array( 'order_id' => '101' ) ) ),
	$create_request,
	'SHOP-UNICID'
);
mtuc_a19_assert(
	is_wp_error( $echo_order ) && 'mtuc_cp_identity_mismatch' === $echo_order->get_error_code(),
	'F08 create rejects a foreign order_id echo'
);

$echo_unicid = mtuc_validate_cp_create_contract(
	array( 'success' => true, 'error' => null, 'message' => '', 'data' => mtuc_a19_create_data( array( 'unicid' => 'OTHER-SHOP' ) ) ),
	$create_request,
	'SHOP-UNICID'
);
mtuc_a19_assert(
	is_wp_error( $echo_unicid ) && 'mtuc_cp_identity_mismatch' === $echo_unicid->get_error_code(),
	'F08 create rejects a foreign unicid echo'
);

$patch_request = array(
	'order_id'  => '100',
	'status_id' => '85',
	'status'    => 'Изпратен',
);
$patch_data    = array(
	'id'         => 7,
	'shop_id'    => 3,
	'order_id'   => '100',
	'status_id'  => '85',
	'status'     => 'Изпратен',
	'updated_at' => '2026-01-02T00:00:00+00:00',
);
mtuc_a19_assert(
	true === mtuc_validate_cp_patch_contract(
		array( 'success' => true, 'error' => null, 'message' => '', 'data' => $patch_data ),
		$patch_request
	),
	'F08 complete PATCH echo accepted'
);

$patch_missing = $patch_data;
unset( $patch_missing['updated_at'] );
mtuc_a19_assert(
	is_wp_error(
		mtuc_validate_cp_patch_contract(
			array( 'success' => true, 'error' => null, 'message' => '', 'data' => $patch_missing ),
			$patch_request
		)
	),
	'F08 PATCH without updated_at is unusable'
);

$patch_int = $patch_data;
$patch_int['status_id'] = 85;
mtuc_a19_assert(
	is_wp_error(
		mtuc_validate_cp_patch_contract(
			array( 'success' => true, 'error' => null, 'message' => '', 'data' => $patch_int ),
			$patch_request
		)
	),
	'F08 PATCH echo is not coerced from int'
);

$patch_wrong          = $patch_data;
$patch_wrong['status'] = 'Отказан';
$patch_mismatch        = mtuc_validate_cp_patch_contract(
	array( 'success' => true, 'error' => null, 'message' => '', 'data' => $patch_wrong ),
	$patch_request
);
mtuc_a19_assert(
	is_wp_error( $patch_mismatch ) && 'mtuc_cp_patch_echo_mismatch' === $patch_mismatch->get_error_code(),
	'F08 PATCH echo mismatch is its own failure'
);

// ---------------------------------------------------------------------------
// F01 — ambiguity by default, definitive only on canonical terminal semantics
// ---------------------------------------------------------------------------

/**
 * @param string $code   Error code.
 * @param mixed  $data   Error data.
 * @return WP_Error
 */
function mtuc_a19_error( string $code, $data = array() ): WP_Error {
	return new WP_Error( $code, 'x', $data );
}

/**
 * Canonical CP failure envelope error, as the decoder would build it.
 *
 * @param string $cp_error CP machine error code.
 * @param int    $status   HTTP status.
 * @return WP_Error
 */
function mtuc_a19_cp_failure( string $cp_error, int $status ): WP_Error {
	return mtuc_decode_cp_envelope( mtuc_a19_body( false, $cp_error, array() ), $status );
}

$ambiguous = array(
	'transport timeout'    => mtuc_a19_error( 'http_request_failed', array( 'is_timeout' => true ) ),
	'malformed JSON'       => mtuc_a19_error( 'mtuc_api_invalid_json', array( 'status' => 200 ) ),
	'non-canonical body'   => mtuc_a19_error( 'mtuc_api_invalid_envelope', array( 'status' => 200 ) ),
	'invalid JSON 5xx'     => mtuc_a19_error( 'mtuc_api_invalid_json', array( 'status' => 503 ) ),
	'unusable 2xx'         => mtuc_a19_error( 'mtuc_cp_unusable_success' ),
	'identity mismatch'    => mtuc_a19_error( 'mtuc_cp_identity_mismatch' ),
	'401 after send'       => mtuc_a19_cp_failure( 'unauthenticated', 401 ),
	'429 throttled'        => mtuc_a19_cp_failure( 'too_many_requests', 429 ),
	'500 server error'     => mtuc_a19_cp_failure( 'server_error', 500 ),
	'503 unavailable'      => mtuc_a19_cp_failure( 'service_unavailable', 503 ),
	'unknown 4xx code'     => mtuc_a19_cp_failure( 'teapot', 418 ),
	'non-canonical 422'    => mtuc_a19_error( 'mtuc_api_http_error', array( 'status' => 422 ) ),
	'non-canonical 409'    => mtuc_a19_error( 'mtuc_api_http_error', array( 'status' => 409 ) ),
);

foreach ( $ambiguous as $label => $error ) {
	mtuc_a19_assert( mtuc_is_cp_create_ambiguous_error( $error ), 'F01 ambiguous: ' . $label );
}

$definitive = array(
	'canonical validation 422'  => mtuc_a19_cp_failure( 'validation', 422 ),
	'canonical invalid_payload' => mtuc_a19_cp_failure( 'invalid_payload', 422 ),
	'wrong API HTML 403'        => mtuc_a19_error( 'mtuc_api_invalid_json', array( 'status' => 403 ) ),
	'missing route HTML 404'    => mtuc_a19_error( 'mtuc_api_invalid_json', array( 'status' => 404 ) ),
	'method not allowed 405'    => mtuc_a19_error( 'mtuc_api_invalid_envelope', array( 'status' => 405 ) ),
);

foreach ( $definitive as $label => $error ) {
	mtuc_a19_assert( ! mtuc_is_cp_create_ambiguous_error( $error ), 'F01 definitive rejection: ' . $label );
}

$conflict = mtuc_a19_cp_failure( 'semantic_conflict', 409 );
mtuc_a19_assert( mtuc_is_cp_idempotency_conflict_error( $conflict ), 'F01 canonical 409 is a definitive conflict' );
mtuc_a19_assert(
	! mtuc_is_cp_idempotency_conflict_error( mtuc_a19_error( 'mtuc_api_http_error', array( 'status' => 409 ) ) ),
	'F01 non-canonical 409 is not a definitive conflict'
);

$unknown_order = new WC_Order( 4201 );
mtuc_record_cp_create_outcome_unknown( $unknown_order, 'timeout' );
mtuc_a19_assert(
	'unknown' === (string) $unknown_order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ),
	'F01 ambiguous create is recorded as unknown'
);
mtuc_a19_assert(
	MTUC_BANK_STATUS_SEND_FAILED_CP !== (string) $unknown_order->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'F01 unknown outcome never claims bank_send_failed_cp'
);
$unknown_notes = implode( "\n", $unknown_order->notes );
mtuc_a19_assert( '' !== $unknown_notes, 'F01 unknown outcome is journalled for the operator' );
mtuc_a19_assert(
	false === mb_stripos( $unknown_notes, 'повторно изпращане ще' ),
	'F01 unknown note promises no automatic replay'
);

// ---------------------------------------------------------------------------
// F03 — generation-aware durable sync target
// ---------------------------------------------------------------------------

$sync                             = new WC_Order( 4300 );
$GLOBALS['mtuc_test_orders'][4300] = $sync;
mtuc_a19_assert( 'not_needed' === mtuc_get_cp_status_sync_state( $sync ), 'F03 fresh order needs no sync' );

$target = mtuc_admit_cp_status_sync_target( $sync, MTUC_BANK_STATUS_SENT_PROCESS2, 'Изпратен' );
mtuc_a19_assert( is_array( $target ) && 1 === $target['generation'], 'F03 first admission opens generation 1' );
mtuc_a19_assert( 'pending' === mtuc_get_cp_status_sync_state( $sync ), 'F03 admitted target is pending' );
mtuc_a19_assert( 'Изпратен' === $target['status'], 'F03 target carries the label to send' );

$again = mtuc_admit_cp_status_sync_target( $sync, MTUC_BANK_STATUS_SENT_PROCESS2, 'Изпратен' );
mtuc_a19_assert(
	is_array( $again ) && 1 === $again['generation'],
	'F03 re-admitting the same pending target is a no-op, not a new generation'
);

$conflicting = mtuc_admit_cp_status_sync_target( $sync, MTUC_BANK_STATUS_SENT_PROCESS1, 'Друг' );
mtuc_a19_assert(
	is_wp_error( $conflicting ) && 'mtuc_cp_sync_semantic_conflict' === $conflicting->get_error_code(),
	'F03 a different target cannot displace a pending one'
);

mtuc_a19_assert(
	false === mtuc_confirm_cp_status_sync_target( $sync, 0, MTUC_BANK_STATUS_SENT_PROCESS2 ),
	'F03 a stale generation cannot confirm'
);
mtuc_a19_assert(
	false === mtuc_confirm_cp_status_sync_target( $sync, 1, MTUC_BANK_STATUS_SENT_PROCESS1 ),
	'F03 a confirmation for another status cannot confirm'
);
mtuc_a19_assert( 'pending' === mtuc_get_cp_status_sync_state( $sync ), 'F03 stale confirmation leaves the target pending' );

mtuc_a19_assert(
	true === mtuc_confirm_cp_status_sync_target( $sync, 1, MTUC_BANK_STATUS_SENT_PROCESS2 ),
	'F03 matching generation and status confirms'
);
mtuc_a19_assert( 'confirmed' === mtuc_get_cp_status_sync_state( $sync ), 'F03 confirmed target is settled' );

$progress = mtuc_admit_cp_status_sync_target( $sync, 'bank_approved', 'Одобрен' );
mtuc_a19_assert(
	is_array( $progress ) && 2 === $progress['generation'],
	'F03 a new status after confirmation opens the next generation'
);

$failing                           = new WC_Order( 4301 );
$GLOBALS['mtuc_test_orders'][4301] = $failing;
$ft                                = mtuc_admit_cp_status_sync_target( $failing, MTUC_BANK_STATUS_SENT_PROCESS2, 'Изпратен' );
mtuc_a19_assert( is_array( $ft ), 'F03 failing-case target admitted' );

$retryable_failure = mtuc_a19_cp_failure( 'server_error', 500 );
mtuc_a19_assert(
	'pending' === mtuc_fail_cp_status_sync_target( $failing, $ft['generation'], MTUC_BANK_STATUS_SENT_PROCESS2, $retryable_failure ),
	'F03 retryable failure keeps the target pending'
);
mtuc_a19_assert( 'pending' === mtuc_get_cp_status_sync_state( $failing ), 'F03 pending target survives a retryable failure' );

mtuc_a19_assert(
	'' === mtuc_fail_cp_status_sync_target( $failing, 99, MTUC_BANK_STATUS_SENT_PROCESS2, $retryable_failure ),
	'F03 a stale failure cannot touch a newer generation'
);

$terminal_failure = mtuc_a19_cp_failure( 'invalid_payload', 422 );
mtuc_a19_assert(
	'terminal_failed' === mtuc_fail_cp_status_sync_target( $failing, $ft['generation'], MTUC_BANK_STATUS_SENT_PROCESS2, $terminal_failure ),
	'F03 terminal semantic failure stops retrying'
);
mtuc_a19_assert( 'terminal_failed' === mtuc_get_cp_status_sync_state( $failing ), 'F03 terminal state is durable' );

$terminal_codes = mtuc_cp_terminal_sync_error_codes();
sort( $terminal_codes );
mtuc_a19_assert(
	array( 'invalid_payload', 'order_not_found', 'semantic_conflict', 'unsupported_status' ) === $terminal_codes,
	'F03 terminal sync errors are exactly the four canonical semantic codes'
);

$retryable_errors = array(
	'500 server error'    => mtuc_a19_cp_failure( 'server_error', 500 ),
	'429 throttled'       => mtuc_a19_cp_failure( 'too_many_requests', 429 ),
	'401 after send'      => mtuc_a19_cp_failure( 'unauthenticated', 401 ),
	'transport failure'   => mtuc_a19_error( 'http_request_failed' ),
	'malformed response'  => mtuc_a19_error( 'mtuc_api_invalid_envelope', array( 'status' => 200 ) ),
	'unusable PATCH 2xx'  => mtuc_a19_error( 'mtuc_cp_patch_unusable_success' ),
	'echo mismatch'       => mtuc_a19_error( 'mtuc_cp_patch_echo_mismatch' ),
	'non-canonical 422'   => mtuc_a19_error( 'mtuc_api_http_error', array( 'status' => 422 ) ),
);

foreach ( $retryable_errors as $label => $error ) {
	mtuc_a19_assert( ! mtuc_is_terminal_cp_sync_error( $error ), 'F03 retryable sync error: ' . $label );
}

foreach ( mtuc_cp_terminal_sync_error_codes() as $terminal_code ) {
	mtuc_a19_assert(
		mtuc_is_terminal_cp_sync_error( mtuc_a19_cp_failure( $terminal_code, 422 ) ),
		'F03 terminal sync error: ' . $terminal_code
	);
}

$categories = array(
	'transport_timeout'  => mtuc_a19_error( 'http_request_failed' ),
	'malformed_response' => mtuc_a19_error( 'mtuc_api_invalid_envelope' ),
	'echo_mismatch'      => mtuc_a19_error( 'mtuc_cp_patch_echo_mismatch' ),
	'auth_401'           => mtuc_a19_cp_failure( 'unauthenticated', 401 ),
	'http_429'           => mtuc_a19_cp_failure( 'too_many_requests', 429 ),
	'http_5xx'           => mtuc_a19_cp_failure( 'server_error', 503 ),
);

foreach ( $categories as $expected_category => $error ) {
	mtuc_a19_assert(
		$expected_category === mtuc_sanitize_cp_sync_error_category( $error ),
		'F03 sync error category: ' . $expected_category
	);
}

// ---------------------------------------------------------------------------
// F05 — inbound order_id is identification, never authorisation
// ---------------------------------------------------------------------------

mtuc_a19_assert( mtuc_is_canonical_financing_order_id( '1' ), 'F05 single digit order_id is canonical' );
mtuc_a19_assert( mtuc_is_canonical_financing_order_id( str_repeat( '9', 13 ) ), 'F05 13 digits is the maximum' );
mtuc_a19_assert( ! mtuc_is_canonical_financing_order_id( str_repeat( '9', 14 ) ), 'F05 14 digits rejected' );
mtuc_a19_assert( ! mtuc_is_canonical_financing_order_id( '0123' ), 'F05 leading zero rejected' );
mtuc_a19_assert( ! mtuc_is_canonical_financing_order_id( ' 123' ), 'F05 whitespace rejected' );
mtuc_a19_assert( ! mtuc_is_canonical_financing_order_id( '12a' ), 'F05 non-decimal rejected' );
mtuc_a19_assert( ! mtuc_is_canonical_financing_order_id( 123 ), 'F05 int is not coerced' );
mtuc_a19_assert( ! mtuc_is_canonical_financing_order_id( null ), 'F05 null rejected' );

$owned = new WC_Order( 4400 );
$owned->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '4400' );
mtuc_a19_assert( true === mtuc_persist_financing_order_ownership( $owned ), 'F05 ownership persists on assignment' );
$GLOBALS['mtuc_test_orders'][4400] = $owned;

mtuc_a19_assert(
	mtuc_resolve_financing_order( '4400', 'SHOP-UNICID' ) === $owned,
	'F05 owned order resolves for its own shop'
);

mtuc_a19_assert(
	true === mtuc_persist_financing_order_ownership( $owned, 'SHOP-UNICID' ),
	'F05 rebinding to the same owner is idempotent'
);
mtuc_a19_assert(
	is_wp_error( mtuc_persist_financing_order_ownership( $owned, 'OTHER-SHOP' ) ),
	'F05 rebinding to a different shop is a conflict'
);

$legacy = new WC_Order( 4401 );
$legacy->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '4401' );
$GLOBALS['mtuc_test_orders'][4401] = $legacy;

$foreign = new WC_Order( 4402 );
$foreign->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '4402' );
$foreign->update_meta_data( MTUC_ORDER_META_FINANCING_UNICID, 'OTHER-SHOP' );
$foreign->update_meta_data( MTUC_ORDER_META_FINANCING_SITE, 'https://shop.example' );
$GLOBALS['mtuc_test_orders'][4402] = $foreign;

$other_site = new WC_Order( 4403 );
$other_site->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '4403' );
$other_site->update_meta_data( MTUC_ORDER_META_FINANCING_UNICID, 'SHOP-UNICID' );
$other_site->update_meta_data( MTUC_ORDER_META_FINANCING_SITE, 'https://other.example' );
$GLOBALS['mtuc_test_orders'][4403] = $other_site;

$not_financing = new WC_Order( 4404 );
$not_financing->payment_method = 'cod';
$not_financing->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '4404' );
$not_financing->update_meta_data( MTUC_ORDER_META_FINANCING_UNICID, 'SHOP-UNICID' );
$not_financing->update_meta_data( MTUC_ORDER_META_FINANCING_SITE, 'https://shop.example' );
$GLOBALS['mtuc_test_orders'][4404] = $not_financing;

$dup_a = new WC_Order( 4405 );
$dup_a->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '4405' );
mtuc_persist_financing_order_ownership( $dup_a );
$GLOBALS['mtuc_test_orders'][4405] = $dup_a;

$dup_b = new WC_Order( 4406 );
$dup_b->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, '4405' );
mtuc_persist_financing_order_ownership( $dup_b );
$GLOBALS['mtuc_test_orders'][4406] = $dup_b;

$rejections = array(
	'absent order'          => '9999999',
	'legacy without owner'  => '4401',
	'foreign unicid'        => '4402',
	'foreign installation'  => '4403',
	'non-financing order'   => '4404',
	'duplicate binding'     => '4405',
	'non-canonical id'      => 'W0000000000OA',
);

$messages = array();
foreach ( $rejections as $label => $order_id ) {
	$result = mtuc_resolve_financing_order( $order_id, 'SHOP-UNICID' );
	mtuc_a19_assert(
		is_wp_error( $result ) && 'mtuc_financing_order_not_found' === $result->get_error_code(),
		'F05 rejects: ' . $label
	);
	$messages[] = $result->get_error_message();
	mtuc_a19_assert(
		404 === (int) $result->get_error_data()['status'],
		'F05 rejection is a plain 404: ' . $label
	);
}

mtuc_a19_assert( 1 === count( array_unique( $messages ) ), 'F05 every rejection is indistinguishable to the caller' );

mtuc_a19_assert(
	is_wp_error( mtuc_resolve_financing_order( '4400', 'OTHER-SHOP' ) ),
	'F05 an unauthenticated unicid resolves nothing'
);

// ---------------------------------------------------------------------------
// F09/F04 — canonical inbound envelopes, exact bodies, bounded reads
// ---------------------------------------------------------------------------

$success_envelope = mtuc_inbound_success_envelope( 'ok', array( 'order_id' => '4400' ) );
mtuc_a19_assert(
	array( 'success', 'error', 'message', 'data' ) === array_keys( $success_envelope ),
	'F09 success envelope has exactly the canonical keys'
);
mtuc_a19_assert( true === $success_envelope['success'] && null === $success_envelope['error'], 'F09 success has a null error' );
mtuc_a19_assert(
	false !== strpos( (string) wp_json_encode( mtuc_inbound_success_envelope( 'ok' ) ), '"data":{}' ),
	'F09/REVIEW-05 an empty response data object serialises as {}'
);

$error_envelope = mtuc_inbound_error_envelope( 'semantic_conflict', 'nope' );
mtuc_a19_assert( false === $error_envelope['success'], 'F09 error envelope is success:false' );
mtuc_a19_assert(
	$error_envelope['data'] instanceof stdClass,
	'F09 error envelope still carries a data object (REVIEW-05)'
);
mtuc_a19_assert(
	'internal_error' === mtuc_inbound_error_envelope( 'Not A Code!', 'x' )['error'],
	'F09 a non-canonical error code never leaves the module'
);

$exact_max = mtuc_read_bounded_inbound_body( str_repeat( 'x', MTUC_INBOUND_MAX_BODY_BYTES ) );
mtuc_a19_assert(
	is_string( $exact_max ) && MTUC_INBOUND_MAX_BODY_BYTES === strlen( $exact_max ),
	'F04 exactly MAX bytes is accepted'
);

$over_max = mtuc_read_bounded_inbound_body( str_repeat( 'x', MTUC_INBOUND_MAX_BODY_BYTES + 1 ) );
mtuc_a19_assert( is_wp_error( $over_max ), 'F04 MAX+1 bytes is rejected' );
mtuc_a19_assert( 413 === (int) $over_max->get_error_data()['status'], 'F04 oversize body is a 413' );
mtuc_a19_assert( 'payload_too_large' === $over_max->get_error_data()['error'], 'F04 oversize uses the canonical error code' );
mtuc_a19_assert( 1048576 === MTUC_INBOUND_MAX_BODY_BYTES, 'F04 the bound is 1 MiB' );

foreach ( array( '', 'Order-Bank-Status', 'order_bank_status', 'shop-cache' ) as $bad_operation ) {
	$op = mtuc_validate_inbound_operation( array( 'operation' => $bad_operation ), MTUC_INBOUND_OPERATION_ORDER_BANK_STATUS );
	mtuc_a19_assert( is_wp_error( $op ), 'F04 wrong operation rejected: ' . ( '' === $bad_operation ? '(empty)' : $bad_operation ) );
}
mtuc_a19_assert(
	is_wp_error( mtuc_validate_inbound_operation( array(), MTUC_INBOUND_OPERATION_SHOP_CACHE ) ),
	'F04 missing operation rejected'
);
mtuc_a19_assert(
	is_wp_error( mtuc_validate_inbound_operation( array( 'operation' => 1 ), MTUC_INBOUND_OPERATION_SHOP_CACHE ) ),
	'F04 non-string operation rejected'
);
mtuc_a19_assert(
	true === mtuc_validate_inbound_operation(
		array( 'operation' => MTUC_INBOUND_OPERATION_SMARTUCF_DEBUG_LOG ),
		MTUC_INBOUND_OPERATION_SMARTUCF_DEBUG_LOG
	),
	'F04 exact operation accepted'
);

/**
 * @param array<string, mixed> $overrides Overrides.
 * @return array<string, mixed>
 */
function mtuc_a19_status_body( array $overrides = array() ): array {
	return array_merge(
		array(
			'operation' => MTUC_INBOUND_OPERATION_ORDER_BANK_STATUS,
			'unicid'    => 'SHOP-UNICID',
			'order_id'  => '4400',
			'status_id' => '85',
			'status'    => 'Изпратен',
		),
		$overrides
	);
}

mtuc_a19_assert( is_array( mtuc_validate_order_bank_status_body( mtuc_a19_status_body() ) ), 'F09 canonical status body accepted' );

$body_rejections = array(
	'int status_id'        => array( 'status_id' => 85 ),
	'float status_id'      => array( 'status_id' => 85.0 ),
	'bool status'          => array( 'status' => true ),
	'null status'          => array( 'status' => null ),
	'empty status'         => array( 'status' => '' ),
	'array order_id'       => array( 'order_id' => array( '4400' ) ),
	'legacy status_label'  => array( 'status_label' => 'Изпратен' ),
	'extra field'          => array( 'note' => 'x' ),
	'over-long order_id'   => array( 'order_id' => str_repeat( '1', MTUC_INBOUND_ORDER_ID_MAX_LEN + 1 ) ),
	'over-long status_id'  => array( 'status_id' => str_repeat( 'a', MTUC_INBOUND_STATUS_ID_MAX_LEN + 1 ) ),
	'over-long status'     => array( 'status' => str_repeat( 'a', MTUC_INBOUND_STATUS_MAX_LEN + 1 ) ),
);

foreach ( $body_rejections as $label => $override ) {
	$result = mtuc_validate_order_bank_status_body( mtuc_a19_status_body( $override ) );
	mtuc_a19_assert( is_wp_error( $result ), 'F09 status body rejects: ' . $label );
	mtuc_a19_assert( 422 === (int) $result->get_error_data()['status'], 'F09 validation failure is 422: ' . $label );
	mtuc_a19_assert( 'validation' === $result->get_error_data()['error'], 'F09 validation error code: ' . $label );
}

foreach ( array( 'operation', 'unicid', 'order_id', 'status_id', 'status' ) as $required ) {
	$body = mtuc_a19_status_body();
	unset( $body[ $required ] );
	$result = mtuc_validate_order_bank_status_body( $body );
	mtuc_a19_assert( is_wp_error( $result ), 'F09 status body requires: ' . $required );
	mtuc_a19_assert(
		in_array( $required, $result->get_error_data()['violations'], true ),
		'F09 violation names the missing field: ' . $required
	);
}

$debug_body = array(
	'operation' => MTUC_INBOUND_OPERATION_SMARTUCF_DEBUG_LOG,
	'unicid'    => 'SHOP-UNICID',
	'order_id'  => '4400',
);
mtuc_a19_assert( is_array( mtuc_validate_smartucf_debug_log_body( $debug_body ) ), 'F09 canonical debug body accepted' );
mtuc_a19_assert(
	is_wp_error( mtuc_validate_smartucf_debug_log_body( array_merge( $debug_body, array( 'status' => 'x' ) ) ) ),
	'F09 debug body rejects extra fields'
);

// ---------------------------------------------------------------------------
// F06 — SmartUCF debug journal is P1-only and ownership-bound
// ---------------------------------------------------------------------------

$p1_debug = new WC_Order( 4500 );
mtuc_persist_order_process_identity( $p1_debug, 1 );
$p1_debug->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'sess-4500' );
mtuc_a19_assert( true === mtuc_authorize_smartucf_debug_read( $p1_debug ), 'F06 P1 order with a SmartUCF record is readable' );

$p1_no_record = new WC_Order( 4501 );
mtuc_persist_order_process_identity( $p1_no_record, 1 );
$denied_no_record = mtuc_authorize_smartucf_debug_read( $p1_no_record );
mtuc_a19_assert( is_wp_error( $denied_no_record ), 'F06 P1 without a SmartUCF lifecycle is denied' );

$p2_debug = new WC_Order( 4502 );
mtuc_persist_order_process_identity( $p2_debug, 2 );
$p2_debug->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'sess-4502' );
$denied_p2 = mtuc_authorize_smartucf_debug_read( $p2_debug );
mtuc_a19_assert( is_wp_error( $denied_p2 ), 'F06 P2 order is denied even with a SmartUCF record' );

// Both a SmartUCF session (P1) and a P2 bank status: identity is in conflict,
// so nobody can be said to own the journal.
$unknown_identity = new WC_Order( 4503 );
$unknown_identity->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, 'sess-4503' );
$unknown_identity->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS2 );
mtuc_a19_assert(
	'conflict' === mtuc_classify_order_process_identity( $unknown_identity )['status'],
	'F06 contradictory evidence yields a conflicting identity'
);
$denied_unknown = mtuc_authorize_smartucf_debug_read( $unknown_identity );
mtuc_a19_assert( is_wp_error( $denied_unknown ), 'F06 unresolved process identity is denied' );

mtuc_a19_assert(
	$denied_no_record->get_error_message() === $denied_p2->get_error_message()
	&& $denied_p2->get_error_message() === $denied_unknown->get_error_message(),
	'F06 every denial looks identical from outside'
);
mtuc_a19_assert(
	$denied_p2->get_error_message() === mtuc_financing_order_not_found_error()->get_error_message(),
	'F06 denial reuses the opaque not-found message'
);

// ---------------------------------------------------------------------------
// F07 — shop snapshot validation and recursive secret redaction
// ---------------------------------------------------------------------------

mtuc_a19_assert( array( 'data_not_object' ) === mtuc_validate_shop_snapshot( 'x', 'SHOP-UNICID' ), 'F07 non-object snapshot rejected' );
mtuc_a19_assert( array( 'data_not_object' ) === mtuc_validate_shop_snapshot( array( 1, 2 ), 'SHOP-UNICID' ), 'F07 JSON list snapshot rejected' );
mtuc_a19_assert( array( 'data_empty' ) === mtuc_validate_shop_snapshot( array(), 'SHOP-UNICID' ), 'F07 empty snapshot rejected' );
mtuc_a19_assert(
	array() === mtuc_validate_shop_snapshot( array( 'id' => 1 ), 'SHOP-UNICID' ),
	'F07/REVIEW-06 a snapshot without a nested unicid is valid'
);
mtuc_a19_assert(
	array( 'unicid_mismatch' ) === mtuc_validate_shop_snapshot( array( 'unicid' => 'OTHER' ), 'SHOP-UNICID' ),
	'F07 snapshot for another shop rejected'
);
mtuc_a19_assert(
	array() === mtuc_validate_shop_snapshot( array( 'unicid' => 'SHOP-UNICID', 'id' => 1 ), 'SHOP-UNICID' ),
	'F07 matching snapshot accepted'
);

$invalid_snapshot = mtuc_prepare_shop_snapshot( array( 'unicid' => 'OTHER' ), 'SHOP-UNICID' );
mtuc_a19_assert( is_wp_error( $invalid_snapshot ), 'F07 invalid snapshot never reaches the cache' );
mtuc_a19_assert( 422 === (int) $invalid_snapshot->get_error_data()['status'], 'F07 invalid snapshot is a 422' );
mtuc_a19_assert( 'shop_snapshot_invalid' === $invalid_snapshot->get_error_data()['error'], 'F07 canonical snapshot error code' );
mtuc_a19_assert( ! empty( $invalid_snapshot->get_error_data()['violations'] ), 'F07 snapshot violations are reported' );

$raw_snapshot = array(
	'unicid'        => 'SHOP-UNICID',
	'uni_zaglavie'  => 'Магазин',
	'uni_eur'       => 1,
	'uni_proces'    => 2,
	'uni_user'      => 'bank-user',
	'uni_password'  => 'bank-pass',
	'secret_key'    => 'ss',
	'accessToken'   => 'tt',
	'x-bearer-token' => 'bb',
	'nested'        => array(
		'clientSecret' => 'cs',
		'deep'         => array(
			'privateKey' => 'pk',
			'API_KEY'    => 'ak',
			'uni_months' => 24,
		),
		'uni_kop'      => 'CAT',
	),
);

$clean = mtuc_prepare_shop_snapshot( $raw_snapshot, 'SHOP-UNICID' );
mtuc_a19_assert( is_array( $clean ), 'F07 valid snapshot prepared' );

mtuc_a19_assert( ! array_key_exists( 'secret_key', $clean ), 'F07 secret_key stripped' );
mtuc_a19_assert( ! array_key_exists( 'accessToken', $clean ), 'F07 accessToken stripped' );
mtuc_a19_assert( ! array_key_exists( 'x-bearer-token', $clean ), 'F07 x-bearer-token stripped' );
mtuc_a19_assert( ! array_key_exists( 'clientSecret', $clean['nested'] ), 'F07 nested clientSecret stripped' );
mtuc_a19_assert( ! array_key_exists( 'privateKey', $clean['nested']['deep'] ), 'F07 deep privateKey stripped' );
mtuc_a19_assert( ! array_key_exists( 'API_KEY', $clean['nested']['deep'] ), 'F07 deep API_KEY stripped' );

$clean_json = (string) wp_json_encode( $clean );
foreach ( array( 'tt', 'bb', 'cs', 'pk', 'ak' ) as $secret ) {
	mtuc_a19_assert( false === strpos( $clean_json, $secret ), 'F07 secret value stripped at every depth: ' . $secret );
}

mtuc_a19_assert( 'Магазин' === $clean['uni_zaglavie'], 'F07 business title preserved' );
mtuc_a19_assert( 1 === $clean['uni_eur'], 'F07 currency flag preserved' );
mtuc_a19_assert( 2 === $clean['uni_proces'], 'F07 process flag preserved' );
mtuc_a19_assert( 'CAT' === $clean['nested']['uni_kop'], 'F07 nested business field preserved' );
mtuc_a19_assert( 24 === $clean['nested']['deep']['uni_months'], 'F07 deeply nested business field preserved' );
mtuc_a19_assert( ! array_key_exists( 'uni_user', $clean ), 'F07 uni_user stripped from general shop snapshot' );
mtuc_a19_assert( ! array_key_exists( 'uni_password', $clean ), 'F07 uni_password stripped from general shop snapshot' );
mtuc_a19_assert(
	false === strpos( $clean_json, 'bank-user' ) && false === strpos( $clean_json, 'bank-pass' ),
	'F07 credential plaintext absent from cache JSON'
);
mtuc_a19_assert(
	! array_key_exists( 'mtuc_shop_credentials', $GLOBALS['mtuc_test_options'] ),
	'F07 credentials never reach a plaintext option'
);
mtuc_a19_assert(
	array_key_exists( MTUC_SMARTUCF_CREDENTIALS_OPTION, $GLOBALS['mtuc_test_options'] ),
	'F07 COMPLETE pair writes dedicated encrypted option'
);
$stored = $GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ];
mtuc_a19_assert( is_array( $stored ), 'F07 encrypted option is a structured record' );
$stored_json = (string) wp_json_encode( $stored );
mtuc_a19_assert(
	false === strpos( $stored_json, 'bank-user' ) && false === strpos( $stored_json, 'bank-pass' ),
	'F07 dedicated option contains no plaintext credentials'
);
$hydrated = mtuc_hydrate_smartucf_shop_credentials( $clean, 'SHOP-UNICID' );
mtuc_a19_assert( is_array( $hydrated ), 'F07 runtime hydration succeeds' );
mtuc_a19_assert( 'bank-user' === ( $hydrated['uni_user'] ?? '' ), 'F07 hydrated uni_user' );
mtuc_a19_assert( 'bank-pass' === mtuc_resolve_shop_credential( $hydrated, 'uni_password' ), 'F07 hydrated credential resolve' );
mtuc_a19_assert(
	! file_exists( MTUC_PLUGIN_DIR . '/secrets/shop-bank-credentials.php' ),
	'F07 prepare does not write secrets/shop-bank-credentials.php'
);

$secret_keys = array(
	'clientSecret',
	'secret_key',
	'x-bearer-token',
	'accessTokenExpiry',
	'API_KEY',
	'private_key',
	'certificate_pem',
	'Authorization',
	'uni_user',
	'uni_password',
);

foreach ( $secret_keys as $secret_key ) {
	mtuc_a19_assert( mtuc_is_shop_snapshot_secret_key( $secret_key ), 'F07 recognised as secret: ' . $secret_key );
}

foreach ( array( 'uni_zaglavie', 'uni_proces', 'uni_eur', 'uni_months', 'uni_kop', 'id', 'unicid' ) as $business_key ) {
	mtuc_a19_assert( ! mtuc_is_shop_snapshot_secret_key( $business_key ), 'F07 business key kept: ' . $business_key );
}

// ---------------------------------------------------------------------------
// F10 — canonical nonce, and free text that does not fit is rejected
// ---------------------------------------------------------------------------

$nonce_body = '{"unicid":"SHOP-UNICID"}';
$timestamp  = (string) time();

/**
 * @param string $nonce Nonce header value.
 * @return array<string, string>
 */
function mtuc_a19_signed_headers( string $nonce, string $timestamp, string $body ): array {
	return array(
		Mtuc_Module_Request_Signature_Protocol::HEADER_TIMESTAMP => $timestamp,
		Mtuc_Module_Request_Signature_Protocol::HEADER_NONCE     => $nonce,
		Mtuc_Module_Request_Signature_Protocol::HEADER_SIGNATURE => Mtuc_Module_Request_Signature_Protocol::compute_signature(
			'SHOP-SECRET',
			$timestamp,
			$nonce,
			$body
		),
	);
}

$lower_nonce = str_repeat( 'ab12', 16 );
mtuc_a19_assert(
	true === Mtuc_Module_Request_Authenticator::verify_signature(
		'SHOP-SECRET',
		$nonce_body,
		mtuc_a19_signed_headers( $lower_nonce, $timestamp, $nonce_body )
	),
	'F10 lowercase hex nonce accepted'
);

$upper_nonce = strtoupper( $lower_nonce );
mtuc_a19_assert(
	is_wp_error(
		Mtuc_Module_Request_Authenticator::verify_signature(
			'SHOP-SECRET',
			$nonce_body,
			mtuc_a19_signed_headers( $upper_nonce, $timestamp, $nonce_body )
		)
	),
	'F10 uppercase hex nonce rejected even with a valid signature'
);

foreach ( array( str_repeat( 'a', 63 ), str_repeat( 'a', 65 ), str_repeat( 'g', 64 ), '' ) as $bad_nonce ) {
	mtuc_a19_assert(
		is_wp_error(
			Mtuc_Module_Request_Authenticator::verify_signature(
				'SHOP-SECRET',
				$nonce_body,
				mtuc_a19_signed_headers( $bad_nonce, $timestamp, $nonce_body )
			)
		),
		'F10 non-canonical nonce rejected: len ' . strlen( $bad_nonce )
	);
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cp-order-payload.php';

mtuc_a19_assert( 65 === MTUC_CP_FIELD_MAX_NAME, 'F10 name limit mirrors the CP column' );
mtuc_a19_assert( 45 === MTUC_CP_FIELD_MAX_PHONE, 'F10 phone limit mirrors the CP column' );
mtuc_a19_assert( 128 === MTUC_CP_FIELD_MAX_EMAIL, 'F10 email limit mirrors the CP column' );
mtuc_a19_assert( 255 === MTUC_CP_FIELD_MAX_PRODUCTS_NAME, 'F10 products_name limit mirrors the CP column' );

$within_limits = array(
	'name'          => 'Иван Петров-Георгиев',
	'phone'         => '+359 888 123 456',
	'email'         => 'ivan@example.com',
	'products_name' => "Продукт_A, O'Brien Специален",
	'order_id'      => '4400',
);
mtuc_a19_assert( true === mtuc_validate_cp_order_field_limits( $within_limits ), 'F10 values within the limits pass' );

$limits = array(
	'name'          => MTUC_CP_FIELD_MAX_NAME,
	'phone'         => MTUC_CP_FIELD_MAX_PHONE,
	'email'         => MTUC_CP_FIELD_MAX_EMAIL,
	'products_name' => MTUC_CP_FIELD_MAX_PRODUCTS_NAME,
);

foreach ( $limits as $field => $max ) {
	$exact          = $within_limits;
	$exact[ $field ] = str_repeat( 'a', $max );
	mtuc_a19_assert( true === mtuc_validate_cp_order_field_limits( $exact ), 'F10 exactly the limit is accepted: ' . $field );

	$over           = $within_limits;
	$over[ $field ] = str_repeat( 'a', $max + 1 );
	$rejected       = mtuc_validate_cp_order_field_limits( $over );
	mtuc_a19_assert(
		is_wp_error( $rejected ) && 'mtuc_cp_field_too_long' === $rejected->get_error_code(),
		'F10 over the limit is rejected, not truncated: ' . $field
	);
}

$preserved = "Продукт_A / O'Brien — 2×";
mtuc_a19_assert( $preserved === mtuc_sanitize_cp_product_name( $preserved ), 'F10 product name is passed through verbatim' );
mtuc_a19_assert(
	false === strpos( mtuc_sanitize_cp_product_name( str_repeat( 'я', 400 ) ), '...' ),
	'F10 long product names are never ellipsised'
);
mtuc_a19_assert(
	400 === mb_strlen( mtuc_sanitize_cp_product_name( str_repeat( 'я', 400 ) ) ),
	'F10 multibyte product names are never byte-truncated'
);

fwrite( STDOUT, 'OK: ' . $mtuc_a19_assert_count . ' AUD-WOO-019 canonical assertions passed' . PHP_EOL );
exit( 0 );
