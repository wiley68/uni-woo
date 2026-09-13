<?php
/**
 * Canonical Control Panel JSON envelope contract (AUD-WOO-019 F02/F08).
 *
 * Pure decoding/validation helpers shared by every CP transport. No HTTP,
 * no WordPress option access, no order mutation — callers own side effects.
 *
 * Canonical envelope (both directions):
 *   success : bool  (strict true/false)
 *   error   : null on success; lowercase snake_case string on failure
 *   message : string
 *   data    : JSON object (associative array, never a JSON list)
 *
 * HTTP 2xx alone never proves success; only `success === true` does.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical envelope top-level keys — exactly these, nothing more. */
const MTUC_CP_ENVELOPE_KEYS = array( 'success', 'error', 'message', 'data' );

/** Canonical machine error code pattern (lowercase ASCII snake_case). */
const MTUC_CP_ERROR_CODE_PATTERN = '/\A[a-z][a-z0-9_]*\z/';

/** CP field limits enforced before transport (F10 validate-reject, never truncate). */
const MTUC_CP_FIELD_MAX_NAME = 65;

/** CP phone column limit. */
const MTUC_CP_FIELD_MAX_PHONE = 45;

/** CP e-mail column limit. */
const MTUC_CP_FIELD_MAX_EMAIL = 128;

/** CP products_name column limit. */
const MTUC_CP_FIELD_MAX_PRODUCTS_NAME = 255;

/** CP order_id column limit. */
const MTUC_CP_FIELD_MAX_ORDER_ID = 13;

/**
 * Whether a decoded JSON value is an object (assoc array), not a JSON list.
 *
 * Operates on the associative form, where `{}` and `[]` are indistinguishable.
 * Transport decoding must not rely on this: use mtuc_cp_is_json_object_value()
 * against the object-preserving decode instead (AUD-WOO-019-REVIEW-05).
 *
 * @param mixed $value Decoded JSON value.
 * @return bool
 */
function mtuc_cp_is_json_object( $value ): bool {
	if ( ! is_array( $value ) ) {
		return false;
	}

	if ( array() === $value ) {
		return true;
	}

	return array_keys( $value ) !== range( 0, count( $value ) - 1 );
}

/**
 * Whether a value decoded with `json_decode( $raw, false )` is a JSON object.
 *
 * `{}` decodes to an empty stdClass and is accepted; `[]` decodes to a PHP
 * array and is rejected. This is the only decode form that can tell them apart.
 *
 * @param mixed $value Decoded JSON value (object form).
 * @return bool
 */
function mtuc_cp_is_json_object_value( $value ): bool {
	return $value instanceof stdClass;
}

/**
 * Convert an object-form decode into the associative form used internally.
 *
 * JSON lists stay PHP lists, so an array that arrived as `[]` can never be
 * mistaken for the empty object after conversion.
 *
 * @param mixed $value Decoded JSON value (object form).
 * @return mixed
 */
function mtuc_cp_object_to_array( $value ) {
	if ( $value instanceof stdClass ) {
		$converted = array();
		foreach ( get_object_vars( $value ) as $key => $item ) {
			$converted[ $key ] = mtuc_cp_object_to_array( $item );
		}

		return $converted;
	}

	if ( is_array( $value ) ) {
		return array_map( 'mtuc_cp_object_to_array', $value );
	}

	return $value;
}

/**
 * Whether a value is a canonical lowercase snake_case machine error code.
 *
 * @param mixed $code Candidate error code.
 * @return bool
 */
function mtuc_cp_is_canonical_error_code( $code ): bool {
	return is_string( $code ) && 1 === preg_match( MTUC_CP_ERROR_CODE_PATTERN, $code );
}

/**
 * Classify a decoded JSON value against the canonical envelope contract.
 *
 * Requires the object-preserving decode (`json_decode( $raw, false )`): the
 * envelope and its `data` must both be real JSON objects, so a JSON list
 * cannot pass as the empty data object (AUD-WOO-019-REVIEW-05).
 *
 * @param mixed $decoded     Decoded JSON body (object form).
 * @param int   $http_status HTTP status code of the response.
 * @return array{kind:string, violations:array<int,string>, envelope:array<string,mixed>}
 *         kind is success|failure|invalid.
 */
function mtuc_classify_cp_envelope( $decoded, int $http_status ): array {
	$violations = array();

	if ( ! mtuc_cp_is_json_object_value( $decoded ) ) {
		return array(
			'kind'       => 'invalid',
			'violations' => array( 'body_not_object' ),
			'envelope'   => array(),
		);
	}

	$fields = get_object_vars( $decoded );

	foreach ( array_keys( $fields ) as $key ) {
		if ( ! in_array( $key, MTUC_CP_ENVELOPE_KEYS, true ) ) {
			$violations[] = 'unexpected_key';
			break;
		}
	}

	foreach ( MTUC_CP_ENVELOPE_KEYS as $key ) {
		if ( ! array_key_exists( $key, $fields ) ) {
			$violations[] = 'missing_' . $key;
		}
	}

	if ( ! empty( $violations ) ) {
		return array(
			'kind'       => 'invalid',
			'violations' => $violations,
			'envelope'   => array(),
		);
	}

	if ( ! is_bool( $fields['success'] ) ) {
		$violations[] = 'success_not_bool';
	}

	if ( ! is_string( $fields['message'] ) ) {
		$violations[] = 'message_not_string';
	}

	if ( ! mtuc_cp_is_json_object_value( $fields['data'] ) ) {
		$violations[] = 'data_not_object';
	}

	if ( true === $fields['success'] ) {
		if ( null !== $fields['error'] ) {
			$violations[] = 'error_not_null_on_success';
		}
		if ( $http_status < 200 || $http_status >= 300 ) {
			$violations[] = 'success_with_non_2xx_status';
		}
	} elseif ( false === $fields['success'] ) {
		if ( ! mtuc_cp_is_canonical_error_code( $fields['error'] ) ) {
			$violations[] = 'error_not_canonical_code';
		}
	}

	if ( ! empty( $violations ) ) {
		return array(
			'kind'       => 'invalid',
			'violations' => $violations,
			'envelope'   => array(),
		);
	}

	return array(
		'kind'       => true === $fields['success'] ? 'success' : 'failure',
		'violations' => array(),
		'envelope'   => array(
			'success' => $fields['success'],
			'error'   => $fields['error'],
			'message' => $fields['message'],
			'data'    => mtuc_cp_object_to_array( $fields['data'] ),
		),
	);
}

/**
 * Decode + validate a raw CP response body into a canonical envelope.
 *
 * Success returns the full validated envelope. Every other outcome returns a
 * WP_Error whose data preserves transport diagnostics (status, raw, response)
 * so ambiguity classification can stay evidence-based.
 *
 * @param string $raw         Raw response body.
 * @param int    $http_status HTTP status code.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_decode_cp_envelope( string $raw, int $http_status ) {
	// Object form: only this decode can distinguish `{}` from `[]` (REVIEW-05).
	$decoded = json_decode( $raw, false );

	if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
		return new WP_Error(
			'mtuc_api_invalid_json',
			__( 'Невалиден JSON отговор от Контролния панел.', 'mtunicredit' ),
			array(
				'status'   => $http_status,
				'raw'      => $raw,
				'response' => null,
			)
		);
	}

	$classified = mtuc_classify_cp_envelope( $decoded, $http_status );

	if ( 'success' === $classified['kind'] ) {
		return $classified['envelope'];
	}

	if ( 'failure' === $classified['kind'] ) {
		$envelope = $classified['envelope'];

		return new WP_Error(
			'mtuc_api_http_error',
			'' !== $envelope['message']
				? $envelope['message']
				: sprintf(
					/* translators: %s: canonical CP error code */
					__( 'КП върна грешка %s.', 'mtunicredit' ),
					$envelope['error']
				),
			array(
				'status'         => $http_status,
				'raw'            => $raw,
				'response'       => mtuc_cp_object_to_array( $decoded ),
				'envelope'       => $envelope,
				'envelope_valid' => true,
				'cp_error'       => $envelope['error'],
			)
		);
	}

	return new WP_Error(
		'mtuc_api_invalid_envelope',
		__( 'КП върна отговор извън каноничния формат.', 'mtunicredit' ),
		array(
			'status'         => $http_status,
			'raw'            => $raw,
			'response'       => mtuc_cp_object_to_array( $decoded ),
			'envelope_valid' => false,
			'violations'     => $classified['violations'],
		)
	);
}

/**
 * Canonical CP error code carried by a decoded failure envelope, if any.
 *
 * @param WP_Error $error Decoded CP error.
 * @return string Empty when the response was not a canonical failure envelope.
 */
function mtuc_cp_error_envelope_code( WP_Error $error ): string {
	$data = $error->get_error_data();
	if ( ! is_array( $data ) || empty( $data['envelope_valid'] ) ) {
		return '';
	}

	return isset( $data['cp_error'] ) && is_string( $data['cp_error'] ) ? $data['cp_error'] : '';
}

/**
 * HTTP status carried by a CP error, when known.
 *
 * @param WP_Error $error Decoded CP error.
 * @return int 0 when unknown.
 */
function mtuc_cp_error_http_status( WP_Error $error ): int {
	$data = $error->get_error_data();

	return is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
}

/**
 * Allowlist of fields accepted in POST /orders (AUD-WOO-019-F08).
 *
 * `status` / `status_id` are deliberately absent: bank status is owned by the
 * durable PATCH lifecycle, never by create.
 *
 * @return array<int, string>
 */
function mtuc_cp_create_payload_allowlist(): array {
	return array(
		'order_id',
		'name',
		'phone',
		'email',
		'address',
		'address2',
		'price',
		'vnoska',
		'gpr',
		'vnoski',
		'parva',
		'products_id',
		'products_name',
		'products_q',
		'type_client',
		'currency',
		'version',
	);
}

/**
 * Drop every non-allowlisted field from a CP create payload, preserving order.
 *
 * @param array<string, mixed> $payload Candidate create payload.
 * @return array<string, mixed>
 */
function mtuc_filter_cp_create_payload( array $payload ): array {
	$filtered = array();

	foreach ( mtuc_cp_create_payload_allowlist() as $field ) {
		if ( array_key_exists( $field, $payload ) ) {
			$filtered[ $field ] = $payload[ $field ];
		}
	}

	return $filtered;
}

/**
 * Whether a decoded value is a strict positive integer (no float/bool/string coercion).
 *
 * @param mixed $value Decoded value.
 * @return bool
 */
function mtuc_cp_is_positive_int( $value ): bool {
	return is_int( $value ) && $value > 0;
}

/**
 * Whether a decoded value is a non-empty string (no scalar coercion).
 *
 * @param mixed $value Decoded value.
 * @return bool
 */
function mtuc_cp_is_nonempty_string( $value ): bool {
	return is_string( $value ) && '' !== trim( $value );
}

/**
 * Validate POST /orders response `data` against the strict create contract.
 *
 * @param array<string, mixed> $envelope        Validated canonical envelope.
 * @param array<string, mixed> $payload         Request payload that was sent.
 * @param string               $expected_unicid Authenticated shop unicid.
 * @return true|WP_Error
 */
function mtuc_validate_cp_create_contract( array $envelope, array $payload, string $expected_unicid ) {
	$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();

	$requested_order_id = isset( $payload['order_id'] ) && is_string( $payload['order_id'] )
		? $payload['order_id']
		: null;

	if ( null === $requested_order_id || '' === $requested_order_id ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'Липсва заявен order_id за проверка на КП идентичност.', 'mtunicredit' )
		);
	}

	$expected_unicid = trim( $expected_unicid );
	if ( '' === $expected_unicid ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'Липсва конфигуриран unicid за проверка на КП идентичност.', 'mtunicredit' )
		);
	}

	foreach ( array( 'id', 'shop_id' ) as $field ) {
		if ( ! array_key_exists( $field, $data ) || ! mtuc_cp_is_positive_int( $data[ $field ] ) ) {
			return new WP_Error(
				'mtuc_cp_unusable_success',
				__( 'КП успешен отговор без валиден числов идентификатор.', 'mtunicredit' ),
				array( 'field' => $field )
			);
		}
	}

	if ( ! array_key_exists( 'created_at', $data ) || ! mtuc_cp_is_nonempty_string( $data['created_at'] ) ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'КП успешен отговор без created_at.', 'mtunicredit' ),
			array( 'field' => 'created_at' )
		);
	}

	foreach ( array( 'order_id', 'unicid' ) as $field ) {
		if ( ! array_key_exists( $field, $data ) || ! is_string( $data[ $field ] ) || '' === $data[ $field ] ) {
			return new WP_Error(
				'mtuc_cp_unusable_success',
				__( 'КП успешен отговор без гарантирано идентичностно поле.', 'mtunicredit' ),
				array( 'field' => $field )
			);
		}
	}

	if ( $data['order_id'] !== $requested_order_id ) {
		return new WP_Error(
			'mtuc_cp_identity_mismatch',
			__( 'КП върна поръчка с различна идентичност от заявената.', 'mtunicredit' ),
			array(
				'requested_order_id' => $requested_order_id,
				'returned_order_id'  => $data['order_id'],
			)
		);
	}

	if ( $data['unicid'] !== $expected_unicid ) {
		return new WP_Error(
			'mtuc_cp_identity_mismatch',
			__( 'КП върна поръчка за друг магазин (unicid).', 'mtunicredit' ),
			array(
				'expected_unicid' => $expected_unicid,
				'returned_unicid' => $data['unicid'],
			)
		);
	}

	return true;
}

/**
 * Validate PATCH /orders/status response `data` against the strict echo contract.
 *
 * @param array<string, mixed> $envelope Validated canonical envelope.
 * @param array<string, mixed> $request  Request body that was sent (order_id/status_id/status).
 * @return true|WP_Error
 */
function mtuc_validate_cp_patch_contract( array $envelope, array $request ) {
	$data = isset( $envelope['data'] ) && is_array( $envelope['data'] ) ? $envelope['data'] : array();

	foreach ( array( 'id', 'shop_id' ) as $field ) {
		if ( ! array_key_exists( $field, $data ) || ! mtuc_cp_is_positive_int( $data[ $field ] ) ) {
			return new WP_Error(
				'mtuc_cp_patch_unusable_success',
				__( 'КП PATCH отговор без валиден числов идентификатор.', 'mtunicredit' ),
				array( 'field' => $field )
			);
		}
	}

	if ( ! array_key_exists( 'updated_at', $data ) || ! mtuc_cp_is_nonempty_string( $data['updated_at'] ) ) {
		return new WP_Error(
			'mtuc_cp_patch_unusable_success',
			__( 'КП PATCH отговор без updated_at.', 'mtunicredit' ),
			array( 'field' => 'updated_at' )
		);
	}

	foreach ( array( 'order_id', 'status_id', 'status' ) as $field ) {
		$expected = isset( $request[ $field ] ) && is_string( $request[ $field ] ) ? $request[ $field ] : null;
		if ( null === $expected ) {
			return new WP_Error(
				'mtuc_cp_patch_unusable_success',
				__( 'Липсва заявено поле за проверка на КП PATCH ехо.', 'mtunicredit' ),
				array( 'field' => $field )
			);
		}

		if ( ! array_key_exists( $field, $data ) || ! is_string( $data[ $field ] ) ) {
			return new WP_Error(
				'mtuc_cp_patch_unusable_success',
				__( 'КП PATCH отговор без гарантирано ехо поле.', 'mtunicredit' ),
				array( 'field' => $field )
			);
		}

		if ( $data[ $field ] !== $expected ) {
			return new WP_Error(
				'mtuc_cp_patch_echo_mismatch',
				__( 'КП PATCH отговор с несъответстващо ехо.', 'mtunicredit' ),
				array(
					'field'    => $field,
					'expected' => $expected,
					'returned' => $data[ $field ],
				)
			);
		}
	}

	return true;
}
