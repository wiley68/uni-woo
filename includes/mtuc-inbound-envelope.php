<?php
/**
 * Canonical inbound request contract for CP → shop endpoints
 * (AUD-WOO-019 F04/F06/F09).
 *
 * Every module endpoint answers with the same canonical envelope, bounds the
 * request body before spending any work on it, and requires an explicit
 * operation selector so a signature captured for one endpoint cannot be
 * replayed against another.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Hard inbound body ceiling — 1 MiB exactly. */
const MTUC_INBOUND_MAX_BODY_BYTES = 1048576;

/** Operation selector: CP pushes a fresh shop snapshot. */
const MTUC_INBOUND_OPERATION_SHOP_CACHE = 'shop-cache';

/** Operation selector: CP pushes a bank status for one order. */
const MTUC_INBOUND_OPERATION_ORDER_BANK_STATUS = 'order-bank-status';

/** Operation selector: CP reads the SmartUCF debug journal for one order. */
const MTUC_INBOUND_OPERATION_SMARTUCF_DEBUG_LOG = 'smartucf-debug-log';

/** Inbound order_id limit (mirrors the CP column). */
const MTUC_INBOUND_ORDER_ID_MAX_LEN = 13;

/** Inbound status_id limit. */
const MTUC_INBOUND_STATUS_ID_MAX_LEN = 255;

/** Inbound status label limit. */
const MTUC_INBOUND_STATUS_MAX_LEN = 255;

/**
 * Encode an inbound `data` payload so it always serialises as a JSON object.
 *
 * An empty PHP array encodes as `[]`, which breaks the canonical contract that
 * `data` is an object. Empty payloads become stdClass so `wp_json_encode`
 * yields `{}` (AUD-WOO-019-REVIEW-05).
 *
 * @param array<string, mixed> $data Response data payload.
 * @return array<string, mixed>|stdClass
 */
function mtuc_inbound_data_object( array $data ) {
	return array() === $data ? (object) array() : $data;
}

/**
 * Build a canonical inbound success envelope.
 *
 * @param string               $message Human-readable message.
 * @param array<string, mixed> $data    Response data object.
 * @return array<string, mixed>
 */
function mtuc_inbound_success_envelope( string $message, array $data = array() ): array {
	return array(
		'success' => true,
		'error'   => null,
		'message' => $message,
		'data'    => mtuc_inbound_data_object( $data ),
	);
}

/**
 * Build a canonical inbound failure envelope.
 *
 * @param string               $error   Lowercase snake_case machine error code.
 * @param string               $message Human-readable message.
 * @param array<string, mixed> $data    Response data object (violations, …).
 * @return array<string, mixed>
 */
function mtuc_inbound_error_envelope( string $error, string $message, array $data = array() ): array {
	$error = strtolower( trim( $error ) );
	if ( ! function_exists( 'mtuc_cp_is_canonical_error_code' ) || ! mtuc_cp_is_canonical_error_code( $error ) ) {
		$error = 'internal_error';
	}

	return array(
		'success' => false,
		'error'   => $error,
		'message' => $message,
		'data'    => mtuc_inbound_data_object( $data ),
	);
}

/**
 * Bound a raw inbound body before any parsing, hashing or lookup work.
 *
 * Reads at most MAX+1 bytes: exactly MAX is accepted, MAX+1 proves the body is
 * oversize. The Content-Length header is never trusted — only bytes actually
 * present decide.
 *
 * @param string $raw Raw request body.
 * @return string|WP_Error Bounded body, or a 413 error.
 */
function mtuc_read_bounded_inbound_body( string $raw ) {
	$bounded = substr( $raw, 0, MTUC_INBOUND_MAX_BODY_BYTES + 1 );

	if ( strlen( $bounded ) > MTUC_INBOUND_MAX_BODY_BYTES ) {
		return new WP_Error(
			'mtuc_inbound_payload_too_large',
			__( 'Заявката надвишава допустимия размер.', 'mtunicredit' ),
			array(
				'status' => 413,
				'error'  => 'payload_too_large',
			)
		);
	}

	return $bounded;
}

/**
 * Bound a raw inbound body read from a stream.
 *
 * @param resource $handle Readable stream (e.g. php://input).
 * @return string|WP_Error
 */
function mtuc_read_bounded_inbound_stream( $handle ) {
	if ( ! is_resource( $handle ) ) {
		return mtuc_read_bounded_inbound_body( '' );
	}

	$raw = (string) stream_get_contents( $handle, MTUC_INBOUND_MAX_BODY_BYTES + 1 );

	return mtuc_read_bounded_inbound_body( $raw );
}

/**
 * Canonical REST paths of the UniPayment inbound endpoints.
 *
 * @return array<int, string>
 */
function mtuc_inbound_rest_route_paths(): array {
	return array(
		'/mtunicredit/v1/shop-cache',
		'/mtunicredit/v1/order-bank-status',
		'/mtunicredit/v1/smartucf-debug-log',
	);
}

/**
 * Normalize a route candidate to a leading-slash, query-free path.
 *
 * @param string $candidate Raw route or URI path.
 * @return string
 */
function mtuc_normalize_inbound_route_candidate( string $candidate ): string {
	$candidate = trim( $candidate );
	if ( '' === $candidate ) {
		return '';
	}

	$query_at = strpos( $candidate, '?' );
	if ( false !== $query_at ) {
		$candidate = substr( $candidate, 0, $query_at );
	}

	$candidate = rtrim( $candidate, '/' );

	return '' === $candidate ? '' : '/' . ltrim( $candidate, '/' );
}

/**
 * Whether a request targets one of the UniPayment inbound REST routes.
 *
 * Both REST addressing forms are recognised: the `rest_route` query var used
 * by plain permalinks and the `/wp-json/…` path used by pretty permalinks.
 * Matching is anchored on the REST prefix so an unrelated site path that
 * merely ends with the same characters is not treated as ours.
 *
 * @param string $request_uri Raw REQUEST_URI.
 * @param string $rest_route  Value of the rest_route query var, when known.
 * @return bool
 */
function mtuc_request_targets_inbound_rest_route( string $request_uri, string $rest_route = '' ): bool {
	$routes = mtuc_inbound_rest_route_paths();

	$from_query = mtuc_normalize_inbound_route_candidate( $rest_route );
	if ( '' !== $from_query && in_array( $from_query, $routes, true ) ) {
		return true;
	}

	$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
	$path = mtuc_normalize_inbound_route_candidate( $path );
	if ( '' === $path ) {
		return false;
	}

	$prefix = function_exists( 'rest_get_url_prefix' ) ? (string) rest_get_url_prefix() : 'wp-json';
	$prefix = trim( $prefix, '/' );
	if ( '' === $prefix ) {
		$prefix = 'wp-json';
	}

	foreach ( $routes as $route ) {
		$suffix = '/' . $prefix . $route;
		if ( strlen( $path ) >= strlen( $suffix ) && substr( $path, -strlen( $suffix ) ) === $suffix ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether an HTTP method may carry a request body worth bounding.
 *
 * @param string $method HTTP method.
 * @return bool
 */
function mtuc_inbound_method_carries_body( string $method ): bool {
	return ! in_array( strtoupper( trim( $method ) ), array( '', 'GET', 'HEAD', 'OPTIONS' ), true );
}

/**
 * Read at most $max_bytes + 1 bytes from a stream, in bounded chunks.
 *
 * Reading one byte past the ceiling is what proves the body is oversize; the
 * rest of the stream is never pulled into memory.
 *
 * @param resource $handle    Readable stream.
 * @param int      $max_bytes Hard ceiling.
 * @return string
 */
function mtuc_read_capped_stream( $handle, int $max_bytes ): string {
	if ( ! is_resource( $handle ) ) {
		return '';
	}

	$limit  = $max_bytes + 1;
	$buffer = '';

	while ( strlen( $buffer ) < $limit && ! feof( $handle ) ) {
		$chunk = fread( $handle, min( 8192, $limit - strlen( $buffer ) ) );
		if ( false === $chunk || '' === $chunk ) {
			break;
		}
		$buffer .= $chunk;
	}

	return $buffer;
}

/**
 * Open the raw request body stream (overridable in tests).
 *
 * @return resource|false
 */
function mtuc_open_inbound_body_stream() {
	if ( isset( $GLOBALS['mtuc_test_input_stream'] ) && is_resource( $GLOBALS['mtuc_test_input_stream'] ) ) {
		return $GLOBALS['mtuc_test_input_stream'];
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	return fopen( 'php://input', 'rb' );
}

/**
 * Canonical 413 envelope emitted by the pre-REST gate.
 *
 * @return array<string, mixed>
 */
function mtuc_inbound_payload_too_large_envelope(): array {
	return mtuc_inbound_error_envelope(
		'payload_too_large',
		__( 'Заявката надвишава допустимия размер.', 'mtunicredit' )
	);
}

/**
 * Apply the hard body ceiling before WordPress REST reads php://input.
 *
 * WP_REST_Server::get_raw_data() caches $GLOBALS['HTTP_RAW_POST_DATA'] the
 * first time it reads the input stream, so a bound applied here is the bound
 * the whole REST stack sees. On accept the exact bytes are published into that
 * global, which keeps the later HMAC verification byte-identical without a
 * second read (a second read of php://input would return nothing).
 *
 * No output and no exit: the caller decides how to answer, which keeps the
 * decision directly testable (AUD-WOO-019-REVIEW-04).
 *
 * @param string        $method      HTTP method.
 * @param string        $request_uri Raw REQUEST_URI.
 * @param string        $rest_route  rest_route query var, when known.
 * @param resource|null $stream      Optional pre-opened body stream.
 * @return array{action:string,status:int,bytes:int} action = skip|accept|reject.
 */
function mtuc_apply_inbound_body_bound( string $method, string $request_uri, string $rest_route = '', $stream = null ): array {
	if ( ! mtuc_inbound_method_carries_body( $method )
		|| ! mtuc_request_targets_inbound_rest_route( $request_uri, $rest_route )
	) {
		return array(
			'action' => 'skip',
			'status' => 0,
			'bytes'  => 0,
		);
	}

	$handle = is_resource( $stream ) ? $stream : mtuc_open_inbound_body_stream();
	$buffer = mtuc_read_capped_stream( $handle, MTUC_INBOUND_MAX_BODY_BYTES );

	if ( ! is_resource( $stream ) && is_resource( $handle ) ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	if ( strlen( $buffer ) > MTUC_INBOUND_MAX_BODY_BYTES ) {
		return array(
			'action' => 'reject',
			'status' => 413,
			'bytes'  => strlen( $buffer ),
		);
	}

	// Exact bytes for the REST stack and for HMAC verification downstream.
	$GLOBALS['HTTP_RAW_POST_DATA'] = $buffer;

	return array(
		'action' => 'accept',
		'status' => 0,
		'bytes'  => strlen( $buffer ),
	);
}

/**
 * parse_request gate (priority 0) — runs before rest_api_loaded at priority 10.
 *
 * @param mixed $wp Current WP environment instance, when provided by the hook.
 * @return void
 */
function mtuc_inbound_rest_body_gate( $wp = null ): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- pre-auth transport bound, no state change.
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? (string) $_SERVER['REQUEST_METHOD'] : '';
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

	$rest_route = '';
	if ( is_object( $wp ) && isset( $wp->query_vars['rest_route'] ) && is_string( $wp->query_vars['rest_route'] ) ) {
		$rest_route = $wp->query_vars['rest_route'];
	} elseif ( isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] ) ) {
		$rest_route = (string) wp_unslash( $_GET['rest_route'] );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	$decision = mtuc_apply_inbound_body_bound( $method, $uri, $rest_route );

	if ( 'reject' !== $decision['action'] ) {
		return;
	}

	if ( ! headers_sent() ) {
		status_header( 413 );
		header( 'Content-Type: application/json; charset=utf-8' );
	}

	echo wp_json_encode( mtuc_inbound_payload_too_large_envelope() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

	exit;
}

/**
 * Require the exact operation selector for an endpoint.
 *
 * Missing, wrong, wrong-case or non-string values are all rejected.
 *
 * @param array<string, mixed> $params   Decoded request body.
 * @param string               $expected Exact expected operation.
 * @return true|WP_Error
 */
function mtuc_validate_inbound_operation( array $params, string $expected ) {
	if ( ! array_key_exists( 'operation', $params )
		|| ! is_string( $params['operation'] )
		|| $params['operation'] !== $expected
	) {
		return new WP_Error(
			'mtuc_inbound_invalid_operation',
			__( 'Липсва или е невалидно полето operation в заявката.', 'mtunicredit' ),
			array(
				'status'     => 422,
				'error'      => 'validation',
				'violations' => array( 'operation' ),
			)
		);
	}

	return true;
}

/**
 * Named bank status identifiers CP may push (AUD-WOO-019-REVIEW-07).
 *
 * Anything outside this list that is not a numeric bank code is an unknown
 * status and is rejected at validation time rather than stored as delivered.
 *
 * @return array<int, string>
 */
function mtuc_inbound_named_bank_status_ids(): array {
	return array(
		'cp_sent',
		'smartucf_sent',
		'bank_sent_process1',
		'bank_sent_process2',
		'bank_send_failed',
		'bank_send_failed_cp',
		'bank_send_failed_smartucf',
	);
}

/**
 * Whether an inbound status_id is an accepted named ID or a numeric bank code.
 *
 * The comparison is byte-exact: no lowercasing, trimming or key sanitisation,
 * so a value that is accepted here is the value that gets stored.
 *
 * @param mixed $status_id Candidate status identifier.
 * @return bool
 */
function mtuc_is_accepted_inbound_status_id( $status_id ): bool {
	if ( ! is_string( $status_id ) || '' === $status_id ) {
		return false;
	}

	if ( in_array( $status_id, mtuc_inbound_named_bank_status_ids(), true ) ) {
		return true;
	}

	return 1 === preg_match( '/\A[0-9]+\z/', $status_id );
}

/**
 * Canonical validation error for inbound bodies.
 *
 * @param array<int, string> $violations Offending field names.
 * @return WP_Error
 */
function mtuc_inbound_validation_error( array $violations ): WP_Error {
	return new WP_Error(
		'mtuc_inbound_validation_failed',
		__( 'Заявката не отговаря на очаквания формат.', 'mtunicredit' ),
		array(
			'status'     => 422,
			'error'      => 'validation',
			'violations' => array_values( $violations ),
		)
	);
}

/**
 * Validate the exact order-bank-status request body.
 *
 * Exactly five string fields, nothing more: `status_label` is a legacy alias
 * and is rejected rather than silently accepted. No int/float/bool coercion.
 *
 * @param array<string, mixed> $params Decoded request body.
 * @return array{operation:string,unicid:string,order_id:string,status_id:string,status:string}|WP_Error
 */
function mtuc_validate_order_bank_status_body( array $params ) {
	$expected   = array( 'operation', 'unicid', 'order_id', 'status_id', 'status' );
	$violations = array();

	foreach ( array_keys( $params ) as $key ) {
		if ( ! in_array( $key, $expected, true ) ) {
			$violations[] = is_string( $key ) ? $key : 'unknown_field';
		}
	}

	foreach ( $expected as $field ) {
		if ( ! array_key_exists( $field, $params ) ) {
			$violations[] = $field;
			continue;
		}

		if ( ! is_string( $params[ $field ] ) || '' === $params[ $field ] ) {
			$violations[] = $field;
		}
	}

	if ( ! empty( $violations ) ) {
		return mtuc_inbound_validation_error( array_unique( $violations ) );
	}

	$limits = array(
		'order_id'  => MTUC_INBOUND_ORDER_ID_MAX_LEN,
		'status_id' => MTUC_INBOUND_STATUS_ID_MAX_LEN,
		'status'    => MTUC_INBOUND_STATUS_MAX_LEN,
	);

	foreach ( $limits as $field => $max ) {
		if ( strlen( $params[ $field ] ) > $max ) {
			$violations[] = $field;
		}
	}

	// REVIEW-07: an unknown named status is refused, never reshaped into one.
	if ( ! mtuc_is_accepted_inbound_status_id( $params['status_id'] ) ) {
		$violations[] = 'status_id';
	}

	if ( ! empty( $violations ) ) {
		return mtuc_inbound_validation_error( array_unique( $violations ) );
	}

	/*
	 * Byte-exact passthrough (REVIEW-07): the accepted strings are handed to the
	 * state machine unchanged — no sanitize_key, no sanitize_text_field.
	 */
	return array(
		'operation' => $params['operation'],
		'unicid'    => $params['unicid'],
		'order_id'  => $params['order_id'],
		'status_id' => $params['status_id'],
		'status'    => $params['status'],
	);
}

/**
 * Validate the exact smartucf-debug-log request body.
 *
 * @param array<string, mixed> $params Decoded request body.
 * @return array{operation:string,unicid:string,order_id:string}|WP_Error
 */
function mtuc_validate_smartucf_debug_log_body( array $params ) {
	$expected   = array( 'operation', 'unicid', 'order_id' );
	$violations = array();

	foreach ( array_keys( $params ) as $key ) {
		if ( ! in_array( $key, $expected, true ) ) {
			$violations[] = is_string( $key ) ? $key : 'unknown_field';
		}
	}

	foreach ( $expected as $field ) {
		if ( ! array_key_exists( $field, $params )
			|| ! is_string( $params[ $field ] )
			|| '' === $params[ $field ]
		) {
			$violations[] = $field;
		}
	}

	if ( empty( $violations ) && strlen( $params['order_id'] ) > MTUC_INBOUND_ORDER_ID_MAX_LEN ) {
		$violations[] = 'order_id';
	}

	if ( ! empty( $violations ) ) {
		return mtuc_inbound_validation_error( array_unique( $violations ) );
	}

	return array(
		'operation' => $params['operation'],
		'unicid'    => $params['unicid'],
		'order_id'  => $params['order_id'],
	);
}

/**
 * Whether an order carries a SmartUCF lifecycle record at all.
 *
 * Absence means the SmartUCF journal has no legitimate owner, so the debug read
 * is denied rather than served empty (F06).
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_order_has_smartucf_lifecycle_record( WC_Order $order ): bool {
	if ( defined( 'MTUC_ORDER_META_SMARTUCF_START_OUTCOME' ) ) {
		$outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ) );
		if ( '' !== $outcome ) {
			return true;
		}
	}

	if ( defined( 'MTUC_ORDER_META_SMARTUCF_SESSION_ID' )
		&& '' !== trim( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_SESSION_ID ) )
	) {
		return true;
	}

	$prefix = defined( 'MTUC_ORDER_META_PREFIX' ) ? MTUC_ORDER_META_PREFIX : '_mtuc_';

	return '' !== trim( (string) $order->get_meta( $prefix . 'smartucf_session_id' ) );
}

/**
 * Gate SmartUCF debug reads to clean Process 1 orders that own a lifecycle.
 *
 * Process 2 orders never talk to SmartUCF; unknown/conflicting identity cannot
 * establish who owns the journal. All denials are opaque and identical.
 *
 * @param WC_Order $order Resolved, owned financing order.
 * @return true|WP_Error
 */
function mtuc_authorize_smartucf_debug_read( WC_Order $order ) {
	$denied = function_exists( 'mtuc_financing_order_not_found_error' )
		? mtuc_financing_order_not_found_error( 'smartucf_debug_denied' )
		: new WP_Error(
			'mtuc_financing_order_not_found',
			__( 'Поръчката не е намерена в магазина.', 'mtunicredit' ),
			array( 'status' => 404 )
		);

	if ( ! function_exists( 'mtuc_classify_order_process_identity' ) ) {
		return $denied;
	}

	$classified = mtuc_classify_order_process_identity( $order );
	$status     = isset( $classified['status'] ) ? (string) $classified['status'] : '';
	$process    = isset( $classified['process'] ) ? (int) $classified['process'] : 0;

	if ( 'clean' !== $status || 1 !== $process ) {
		return $denied;
	}

	if ( ! mtuc_order_has_smartucf_lifecycle_record( $order ) ) {
		return $denied;
	}

	return true;
}
