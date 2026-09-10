<?php
/**
 * SmartUCF Process 1 durable send claim and outcome taxonomy (AUD-WOO-012).
 *
 * Distinguishes short-lived smartucf_http execution lease from a durable
 * one-shot send claim for Woo order X. Does not assume remote SmartUCF
 * idempotency on orderNo.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Order meta: SmartUCF start outcome — confirmed|unknown|missing. */
const MTUC_ORDER_META_SMARTUCF_START_OUTCOME = '_mtuc_smartucf_start_outcome';

/** Order meta: SmartUCF session id (also written via MTUC_ORDER_META_PREFIX). */
const MTUC_ORDER_META_SMARTUCF_SESSION_ID = '_mtuc_smartucf_session_id';

/** Claim state: acquired, transport not yet marked. */
const MTUC_SMARTUCF_P1_CLAIM_ARMED = 'armed';

/** Claim state: remote send boundary may have been crossed. */
const MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN = 'sent_unknown';

/** Claim state: confirmed local success. */
const MTUC_SMARTUCF_P1_CLAIM_CONFIRMED = 'confirmed';

/** Claim state: definitive pre-send failure (claim released for possible retry). */
const MTUC_SMARTUCF_P1_CLAIM_DEFINITIVE_FAILED = 'definitive_failed';

/**
 * Option key for the durable Process 1 SmartUCF send claim.
 *
 * @param int $order_id WooCommerce order ID.
 * @return string
 */
function mtuc_smartucf_p1_claim_option_key( int $order_id ): string {
	return 'mtuc_smartucf_p1_claim_' . max( 0, $order_id );
}

/**
 * Encode claim payload.
 *
 * @param array<string, mixed> $claim Claim fields.
 * @return string
 */
function mtuc_encode_smartucf_p1_claim( array $claim ): string {
	$encoded = wp_json_encode( $claim );
	return is_string( $encoded ) ? $encoded : '';
}

/**
 * Decode claim payload.
 *
 * @param string $raw Option value.
 * @return array<string, mixed>|null
 */
function mtuc_decode_smartucf_p1_claim( string $raw ) {
	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return null;
	}

	$state = sanitize_key( (string) ( $decoded['state'] ?? '' ) );
	$order = absint( $decoded['order_id'] ?? 0 );
	if ( $order <= 0 || '' === $state ) {
		return null;
	}

	$decoded['state']    = $state;
	$decoded['order_id'] = $order;
	$decoded['owner']    = (string) ( $decoded['owner'] ?? '' );

	return $decoded;
}

/**
 * Load current Process 1 claim for an order, if any.
 *
 * @param int $order_id Woo order ID.
 * @return array<string, mixed>|null
 */
function mtuc_get_smartucf_p1_claim( int $order_id ) {
	if ( $order_id <= 0 || ! function_exists( 'mtuc_get_option_raw_value' ) ) {
		return null;
	}

	$raw = mtuc_get_option_raw_value( mtuc_smartucf_p1_claim_option_key( $order_id ) );
	if ( null === $raw || '' === $raw ) {
		return null;
	}

	$claim = mtuc_decode_smartucf_p1_claim( $raw );
	if ( null === $claim || (int) $claim['order_id'] !== $order_id ) {
		return null;
	}

	return $claim;
}

/**
 * Persist claim via CAS when an expected raw value is known; otherwise rewrite.
 *
 * @param int                  $order_id Woo order ID.
 * @param array<string, mixed> $claim    Claim payload.
 * @param string|null          $expected_raw Exact current option_value for CAS, or null to force update_option.
 * @return bool
 */
function mtuc_store_smartucf_p1_claim( int $order_id, array $claim, ?string $expected_raw = null ): bool {
	$key     = mtuc_smartucf_p1_claim_option_key( $order_id );
	$encoded = mtuc_encode_smartucf_p1_claim( $claim );
	if ( '' === $encoded ) {
		return false;
	}

	if ( null !== $expected_raw && function_exists( 'mtuc_options_cas_update' ) ) {
		return mtuc_options_cas_update( $key, $expected_raw, $encoded );
	}

	if ( function_exists( 'update_option' ) ) {
		return (bool) update_option( $key, $encoded, 'no' );
	}

	if ( isset( $GLOBALS['mtuc_test_options'] ) && is_array( $GLOBALS['mtuc_test_options'] ) ) {
		$GLOBALS['mtuc_test_options'][ $key ] = $encoded;
		return true;
	}

	return false;
}

/**
 * Delete claim when expected raw still matches (or force-delete in tests).
 *
 * @param int         $order_id     Woo order ID.
 * @param string|null $expected_raw Exact current option_value.
 * @return bool
 */
function mtuc_delete_smartucf_p1_claim( int $order_id, ?string $expected_raw = null ): bool {
	$key = mtuc_smartucf_p1_claim_option_key( $order_id );

	if ( null !== $expected_raw && function_exists( 'mtuc_options_cas_delete' ) ) {
		return mtuc_options_cas_delete( $key, $expected_raw );
	}

	if ( function_exists( 'delete_option' ) ) {
		return (bool) delete_option( $key );
	}

	if ( isset( $GLOBALS['mtuc_test_options'] ) && is_array( $GLOBALS['mtuc_test_options'] ) ) {
		unset( $GLOBALS['mtuc_test_options'][ $key ] );
		return true;
	}

	return false;
}

/**
 * Request-scoped Process 1 claim owner for the current worker.
 *
 * @param int    $order_id Woo order ID.
 * @param string $owner    Claim owner token from successful acquire.
 * @return void
 */
function mtuc_set_smartucf_p1_claim_owner_context( int $order_id, string $owner ): void {
	$GLOBALS['mtuc_smartucf_p1_claim_context'] = array(
		'order_id' => max( 0, $order_id ),
		'owner'    => (string) $owner,
	);
}

/**
 * Clear request-scoped claim owner context.
 *
 * @return void
 */
function mtuc_clear_smartucf_p1_claim_owner_context(): void {
	$GLOBALS['mtuc_smartucf_p1_claim_context'] = null;
}

/**
 * Owner token bound for this request for order X, if any.
 *
 * @param int $order_id Woo order ID.
 * @return string Empty when unbound / mismatched order.
 */
function mtuc_get_smartucf_p1_claim_owner_context( int $order_id ): string {
	$ctx = isset( $GLOBALS['mtuc_smartucf_p1_claim_context'] ) && is_array( $GLOBALS['mtuc_smartucf_p1_claim_context'] )
		? $GLOBALS['mtuc_smartucf_p1_claim_context']
		: null;

	if ( null === $ctx ) {
		return '';
	}

	if ( (int) ( $ctx['order_id'] ?? 0 ) !== $order_id ) {
		return '';
	}

	return (string) ( $ctx['owner'] ?? '' );
}

/**
 * Atomically acquire the durable Process 1 SmartUCF send claim (armed).
 *
 * Uses add_option uniqueness. Only the creating worker owns the claim.
 * Another worker observing an existing armed claim fails closed (no takeover).
 *
 * @param WC_Order $order Order X.
 * @return array{claim: array<string, mixed>, acquired: bool}|WP_Error
 */
function mtuc_acquire_smartucf_p1_send_claim( WC_Order $order ) {
	$order_id = (int) $order->get_id();
	if ( $order_id <= 0 ) {
		return new WP_Error(
			'mtuc_smartucf_claim_invalid_order',
			__( 'Невалидна поръчка за SmartUCF claim.', 'mtunicredit' )
		);
	}

	$key   = mtuc_smartucf_p1_claim_option_key( $order_id );
	$owner = function_exists( 'wp_generate_password' )
		? wp_generate_password( 20, false, false )
		: bin2hex( random_bytes( 10 ) );
	$now   = time();

	$claim = array(
		'order_id'   => $order_id,
		'state'      => MTUC_SMARTUCF_P1_CLAIM_ARMED,
		'owner'      => $owner,
		'claimed_at' => $now,
		'updated_at' => $now,
		'process'    => 1,
	);

	$encoded = mtuc_encode_smartucf_p1_claim( $claim );
	if ( '' === $encoded ) {
		return new WP_Error(
			'mtuc_smartucf_claim_encode_failed',
			__( 'Неуспешно кодиране на SmartUCF claim.', 'mtunicredit' )
		);
	}

	$created = false;
	if ( function_exists( 'add_option' ) ) {
		$created = (bool) add_option( $key, $encoded, '', 'no' );
	} elseif ( isset( $GLOBALS['mtuc_test_options'] ) && is_array( $GLOBALS['mtuc_test_options'] ) ) {
		if ( ! array_key_exists( $key, $GLOBALS['mtuc_test_options'] ) ) {
			$GLOBALS['mtuc_test_options'][ $key ] = $encoded;
			$created = true;
		}
	}

	if ( $created ) {
		mtuc_set_smartucf_p1_claim_owner_context( $order_id, $owner );
		return array(
			'claim'    => $claim,
			'acquired' => true,
		);
	}

	$existing = mtuc_get_smartucf_p1_claim( $order_id );
	if ( null === $existing ) {
		return new WP_Error(
			'mtuc_smartucf_claim_conflict',
			__( 'SmartUCF claim е в конфликтно състояние.', 'mtunicredit' )
		);
	}

	$state = (string) $existing['state'];

	if ( MTUC_SMARTUCF_P1_CLAIM_ARMED === $state ) {
		// Fail closed: do not continue under another worker's armed ownership.
		return new WP_Error(
			'mtuc_smartucf_claim_owned',
			__( 'SmartUCF claim вече е зает от друг процес.', 'mtunicredit' )
		);
	}

	if ( MTUC_SMARTUCF_P1_CLAIM_CONFIRMED === $state ) {
		return new WP_Error(
			'mtuc_smartucf_claim_confirmed',
			__( 'SmartUCF сесията вече е потвърдена за тази поръчка.', 'mtunicredit' )
		);
	}

	if ( MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $state ) {
		return new WP_Error(
			'mtuc_smartucf_claim_ambiguous',
			__( 'SmartUCF изпращането е неясно; автоматичен повторен старт е забранен.', 'mtunicredit' )
		);
	}

	// definitive_failed left behind — allow a fresh armed claim via CAS replace.
	$raw = function_exists( 'mtuc_get_option_raw_value' )
		? mtuc_get_option_raw_value( $key )
		: null;
	if ( null !== $raw && MTUC_SMARTUCF_P1_CLAIM_DEFINITIVE_FAILED === $state ) {
		if ( mtuc_store_smartucf_p1_claim( $order_id, $claim, $raw ) ) {
			mtuc_set_smartucf_p1_claim_owner_context( $order_id, $owner );
			return array(
				'claim'    => $claim,
				'acquired' => true,
			);
		}
	}

	return new WP_Error(
		'mtuc_smartucf_claim_conflict',
		__( 'SmartUCF claim не може да бъде придобит.', 'mtunicredit' )
	);
}

/**
 * Authorize SmartUCF transport by exact armed→sent_unknown CAS for this worker.
 *
 * Returns true ONLY when this invocation satisfies all of:
 * 1. claim owner == this worker
 * 2. this invocation wins armed → sent_unknown CAS
 * 3. execution ownership is revalidated successfully AFTER the CAS
 *
 * sent_unknown / confirmed / missing / malformed / owner mismatch / CAS loss /
 * pre- or post-CAS fence loss never authorize transport. A successful CAS is
 * never rolled back (fail closed → sent_unknown).
 *
 * @param int $order_id Woo order ID (from payload orderNo).
 * @return bool
 */
function mtuc_mark_smartucf_p1_transport_boundary( int $order_id ): bool {
	if ( $order_id <= 0 || ! function_exists( 'mtuc_get_option_raw_value' ) ) {
		return false;
	}

	// Pre-CAS: live submission fence must still be owned (avoids consuming the one-shot claim when already stale).
	if ( function_exists( 'mtuc_require_armed_submission_lock_ownership' ) ) {
		$renew = defined( 'MTUC_SUBMISSION_LOCK_RENEW_HTTP_SMARTUCF' )
			? MTUC_SUBMISSION_LOCK_RENEW_HTTP_SMARTUCF
			: null;
		$stage = defined( 'MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP' )
			? MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP
			: '';
		$owned = mtuc_require_armed_submission_lock_ownership( $renew, $stage );
		if ( is_wp_error( $owned ) ) {
			return false;
		}
	}

	$owner = mtuc_get_smartucf_p1_claim_owner_context( $order_id );
	if ( '' === $owner ) {
		return false;
	}

	$key = mtuc_smartucf_p1_claim_option_key( $order_id );
	$raw = mtuc_get_option_raw_value( $key );
	if ( null === $raw || '' === $raw ) {
		return false;
	}

	$claim = mtuc_decode_smartucf_p1_claim( $raw );
	if ( null === $claim ) {
		return false;
	}

	if ( MTUC_SMARTUCF_P1_CLAIM_ARMED !== (string) $claim['state'] ) {
		return false;
	}

	if ( ! hash_equals( (string) $claim['owner'], $owner ) ) {
		return false;
	}

	$claim['state']      = MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN;
	$claim['updated_at'] = time();
	// Owner token preserved; only this CAS winner may proceed to post-CAS fence check.

	if ( ! mtuc_store_smartucf_p1_claim( $order_id, $claim, $raw ) ) {
		return false;
	}

	/*
	 * Test-only seam: inject ownership loss between successful CAS and post-CAS fence check.
	 * Production never sets this; never rolls claim back to armed.
	 */
	if ( isset( $GLOBALS['mtuc_test_after_smartucf_p1_cas'] ) && is_callable( $GLOBALS['mtuc_test_after_smartucf_p1_cas'] ) ) {
		call_user_func( $GLOBALS['mtuc_test_after_smartucf_p1_cas'], $order_id );
	}

	// Post-CAS: must still own the durable execution fence. Do not roll back sent_unknown.
	if ( function_exists( 'mtuc_require_armed_submission_lock_ownership' ) ) {
		$still = mtuc_require_armed_submission_lock_ownership( null );
		if ( is_wp_error( $still ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Confirm Process 1 claim after durable local success.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_confirm_smartucf_p1_send_claim( WC_Order $order ): void {
	$order_id = (int) $order->get_id();
	$key      = mtuc_smartucf_p1_claim_option_key( $order_id );
	$raw      = function_exists( 'mtuc_get_option_raw_value' ) ? mtuc_get_option_raw_value( $key ) : null;
	$claim    = null !== $raw ? mtuc_decode_smartucf_p1_claim( (string) $raw ) : mtuc_get_smartucf_p1_claim( $order_id );

	if ( null === $claim ) {
		$claim = array(
			'order_id'   => $order_id,
			'state'      => MTUC_SMARTUCF_P1_CLAIM_CONFIRMED,
			'owner'      => 'finalize',
			'claimed_at' => time(),
			'updated_at' => time(),
			'process'    => 1,
		);
		mtuc_store_smartucf_p1_claim( $order_id, $claim, null );
		return;
	}

	$claim['state']      = MTUC_SMARTUCF_P1_CLAIM_CONFIRMED;
	$claim['updated_at'] = time();
	mtuc_store_smartucf_p1_claim( $order_id, $claim, is_string( $raw ) ? $raw : null );
}

/**
 * Release claim after definitive pre-send failure (no remote send possible).
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_release_smartucf_p1_claim_after_presend_failure( WC_Order $order ): void {
	$order_id = (int) $order->get_id();
	$key      = mtuc_smartucf_p1_claim_option_key( $order_id );
	$raw      = function_exists( 'mtuc_get_option_raw_value' ) ? mtuc_get_option_raw_value( $key ) : null;
	if ( null === $raw || '' === $raw ) {
		return;
	}

	$claim = mtuc_decode_smartucf_p1_claim( $raw );
	if ( null === $claim ) {
		return;
	}

	// Only clear when transport boundary was not marked.
	if ( MTUC_SMARTUCF_P1_CLAIM_ARMED !== (string) $claim['state'] ) {
		return;
	}

	mtuc_delete_smartucf_p1_claim( $order_id, $raw );
}

/**
 * Whether an automatic SmartUCF start is prohibited by durable claim/outcome.
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_smartucf_p1_second_start_prohibited( WC_Order $order ): bool {
	$session = trim( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_SESSION_ID ) );
	if ( '' === $session ) {
		$session = trim( (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'smartucf_session_id' ) );
	}
	if ( '' !== $session ) {
		return true;
	}

	$outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ) );
	if ( 'unknown' === $outcome || 'confirmed' === $outcome ) {
		return true;
	}

	$claim = mtuc_get_smartucf_p1_claim( (int) $order->get_id() );
	if ( null === $claim ) {
		return false;
	}

	return in_array(
		(string) $claim['state'],
		array(
			MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN,
			MTUC_SMARTUCF_P1_CLAIM_CONFIRMED,
		),
		true
	);
}

/**
 * Whether a SmartUCF error is definitive PRE-SEND (no request body transmitted).
 *
 * @param WP_Error|string $error_or_code Error or code.
 * @return bool
 */
function mtuc_is_smartucf_presend_error( $error_or_code ): bool {
	$code = $error_or_code instanceof WP_Error
		? $error_or_code->get_error_code()
		: (string) $error_or_code;

	if ( '' === $code ) {
		return false;
	}

	if ( function_exists( 'mtuc_is_ssl_presend_error_code' ) && mtuc_is_ssl_presend_error_code( $code ) ) {
		// invalid_session_id is post-response — exclude from presend.
		if ( 'mtuc_smartucf_invalid_session_id' === $code ) {
			return false;
		}
		return true;
	}

	return in_array(
		$code,
		array(
			'mtuc_ssl_missing_password',
			'mtuc_smartucf_encode_failed',
			'mtuc_smartucf_curl_missing',
			'mtuc_smartucf_curl_init',
			'mtuc_smartucf_claim_invalid_order',
			'mtuc_smartucf_claim_encode_failed',
		),
		true
	);
}

/**
 * Whether a SmartUCF error is an ambiguous post-send / transport outcome.
 *
 * @param WP_Error $error API error.
 * @return bool
 */
function mtuc_is_smartucf_ambiguous_error( WP_Error $error ): bool {
	$code = $error->get_error_code();

	if ( in_array(
		$code,
		array(
			'mtuc_smartucf_http_error',
			'mtuc_smartucf_http_status',
			'mtuc_smartucf_empty_response',
			'mtuc_smartucf_invalid_json',
			'mtuc_smartucf_no_session',
			'mtuc_smartucf_invalid_session_id',
			'mtuc_smartucf_claim_ambiguous',
		),
		true
	) ) {
		return true;
	}

	// Conservative: unknown smartucf_* after boundary is ambiguous unless proven presend.
	if ( 0 === strpos( $code, 'mtuc_smartucf_' ) && ! mtuc_is_smartucf_presend_error( $error ) ) {
		return true;
	}

	return false;
}

/**
 * Persist ambiguous SmartUCF start outcome (no bank_send_failed_smartucf).
 *
 * @param WC_Order        $order           Order instance.
 * @param WP_Error|string $error_or_reason Detail.
 * @return void
 */
function mtuc_record_smartucf_start_outcome_unknown( WC_Order $order, $error_or_reason = '' ): void {
	if ( $error_or_reason instanceof WP_Error && function_exists( 'mtuc_record_order_financing_diagnostic' ) ) {
		mtuc_record_order_financing_diagnostic( $order, $error_or_reason, 'smartucf' );
	} elseif ( is_string( $error_or_reason ) && '' !== trim( $error_or_reason ) && function_exists( 'mtuc_record_order_financing_diagnostic' ) ) {
		mtuc_record_order_financing_diagnostic(
			$order,
			new WP_Error( 'mtuc_smartucf_unknown', $error_or_reason ),
			'smartucf'
		);
	}

	$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'unknown' );
	$order->update_meta_data( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE, 1 );
	$order->add_order_note(
		__( 'Стартът на SmartUCF сесия е технически неясен; автоматичен повторен старт е забранен.', 'mtunicredit' )
	);

	// Do not mutate claim state here — only the armed→sent_unknown CAS may mark the transport boundary.
	$order->save();
}

/**
 * Persist session + redirect, prove durability via fresh reload, then bank_sent_process1.
 *
 * @param WC_Order             $order        Order instance.
 * @param string               $session_id   Validated session ID.
 * @param string               $redirect_url Trusted redirect URL.
 * @param array<string, mixed> $shop         Shop data (unused; reserved).
 * @return true|WP_Error
 */
function mtuc_finalize_smartucf_p1_success( WC_Order $order, string $session_id, string $redirect_url, array $shop = array() ) {
	unset( $shop );

	$session_id   = trim( $session_id );
	$redirect_url = esc_url_raw( trim( $redirect_url ) );

	if ( '' === $session_id || '' === $redirect_url ) {
		return new WP_Error(
			'mtuc_smartucf_incomplete_success',
			__( 'Липсват session или redirect за SmartUCF успех.', 'mtunicredit' )
		);
	}

	$cp_order_id = (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' );
	if ( $cp_order_id <= 0 ) {
		return new WP_Error(
			'mtuc_smartucf_missing_cp',
			__( 'КП успехът липсва преди bank_sent_process1.', 'mtunicredit' )
		);
	}

	$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_SESSION_ID, $session_id );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', $session_id );
	$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, $redirect_url );
	$order->save();

	// Independent durable reload — do not trust the same in-memory instance (AUD-WOO-012-F04).
	$fresh = null;
	if ( function_exists( 'wc_get_order' ) ) {
		$fresh = wc_get_order( $order->get_id() );
	}
	if ( ! $fresh instanceof WC_Order ) {
		return new WP_Error(
			'mtuc_smartucf_persist_incomplete',
			__( 'SmartUCF session/redirect не са трайно записани.', 'mtunicredit' )
		);
	}

	$fresh_session = trim( (string) $fresh->get_meta( MTUC_ORDER_META_SMARTUCF_SESSION_ID ) );
	if ( '' === $fresh_session ) {
		$fresh_session = trim( (string) $fresh->get_meta( MTUC_ORDER_META_PREFIX . 'smartucf_session_id' ) );
	}
	$fresh_redirect = esc_url_raw( trim( (string) $fresh->get_meta( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL ) ) );

	if ( $fresh_session !== $session_id || $fresh_redirect !== $redirect_url ) {
		return new WP_Error(
			'mtuc_smartucf_persist_incomplete',
			__( 'SmartUCF session/redirect не са трайно записани.', 'mtunicredit' )
		);
	}

	if ( function_exists( 'mtuc_record_order_bank_status' ) ) {
		mtuc_record_order_bank_status(
			$order,
			MTUC_BANK_STATUS_SENT_PROCESS1,
			array( 'sync_cp' => true )
		);
	} else {
		$order->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS1 );
		$order->save();
	}

	$fresh_bank = function_exists( 'wc_get_order' ) ? wc_get_order( $order->get_id() ) : null;
	if ( ! $fresh_bank instanceof WC_Order
		|| MTUC_BANK_STATUS_SENT_PROCESS1 !== sanitize_key( (string) $fresh_bank->get_meta( MTUC_ORDER_META_BANK_STATUS ) )
	) {
		return new WP_Error(
			'mtuc_smartucf_bank_status_incomplete',
			__( 'bank_sent_process1 не е трайно записан.', 'mtunicredit' )
		);
	}

	$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
	$order->save();

	if ( function_exists( 'mtuc_clear_order_financing_diagnostic' ) ) {
		mtuc_clear_order_financing_diagnostic( $order );
	}

	// Claim confirmation is best-effort repair; session/bank evidence already suppress resend.
	mtuc_confirm_smartucf_p1_send_claim( $order );

	return true;
}

/**
 * Recover from persisted session without a second SmartUCF start.
 *
 * @param WC_Order             $order Order instance.
 * @param array<string, mixed> $shop  Shop data.
 * @return array{session_id: string, redirect_url: string}|null|WP_Error Null when no session to recover.
 */
function mtuc_try_recover_smartucf_p1_session( WC_Order $order, array $shop ) {
	$session_id = trim( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_SESSION_ID ) );
	if ( '' === $session_id ) {
		$session_id = trim( (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'smartucf_session_id' ) );
	}

	if ( '' === $session_id ) {
		return null;
	}

	$redirect_url = trim( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL ) );
	if ( '' === $redirect_url ) {
		$resolved = class_exists( 'Mtuc_Smartucf_Api_Client', false )
			? Mtuc_Smartucf_Api_Client::get_application_redirect_url( $shop, $session_id )
			: '';
		if ( '' === $resolved ) {
			return new WP_Error(
				'mtuc_smartucf_claim_ambiguous',
				__( 'Има SmartUCF сесия, но redirect не може да бъде възстановен.', 'mtunicredit' )
			);
		}
		$redirect_url = $resolved;
		$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, esc_url_raw( $redirect_url ) );
		$order->save();
	}

	$bank_status = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ) );
	if ( MTUC_BANK_STATUS_SENT_PROCESS1 !== $bank_status ) {
		$finalized = mtuc_finalize_smartucf_p1_success( $order, $session_id, $redirect_url, $shop );
		if ( is_wp_error( $finalized ) ) {
			return $finalized;
		}
	} else {
		$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'confirmed' );
		mtuc_confirm_smartucf_p1_send_claim( $order );
		$order->save();
	}

	return array(
		'session_id'   => $session_id,
		'redirect_url' => (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL ),
	);
}

/**
 * Handle a SmartUCF start_session WP_Error with correct taxonomy.
 *
 * @param WC_Order $order Order instance.
 * @param WP_Error $error start_session error.
 * @return WP_Error Same error (possibly after recording).
 */
function mtuc_handle_smartucf_start_error( WC_Order $order, WP_Error $error ): WP_Error {
	$code = $error->get_error_code();

	if ( 'mtuc_submit_locked' === $code ) {
		return $error;
	}

	$claim = mtuc_get_smartucf_p1_claim( (int) $order->get_id() );
	$state = is_array( $claim ) ? (string) $claim['state'] : '';

	// Boundary / post-CAS denial: if claim already crossed, treat as ambiguous (no definitive fail).
	if ( in_array(
		$code,
		array(
			'mtuc_smartucf_claim_denied',
			'mtuc_smartucf_transport_not_authorized',
		),
		true
	) ) {
		if ( MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $state || MTUC_SMARTUCF_P1_CLAIM_CONFIRMED === $state ) {
			mtuc_record_smartucf_start_outcome_unknown( $order, $error );
		}
		return $error;
	}

	// Transport boundary crossed → always ambiguous (no definitive bank_send_failed_smartucf).
	if ( MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $state || mtuc_is_smartucf_ambiguous_error( $error ) ) {
		if ( MTUC_SMARTUCF_P1_CLAIM_SENT_UNKNOWN === $state || ! mtuc_is_smartucf_presend_error( $error ) ) {
			mtuc_record_smartucf_start_outcome_unknown( $order, $error );
			return $error;
		}
	}

	if ( mtuc_is_smartucf_presend_error( $error ) ) {
		mtuc_release_smartucf_p1_claim_after_presend_failure( $order );
		if ( function_exists( 'mtuc_fail_order_on_smartucf_error' ) ) {
			mtuc_fail_order_on_smartucf_error( $order, $error );
		}
		$order->update_meta_data( MTUC_ORDER_META_SMARTUCF_START_OUTCOME, 'missing' );
		$order->save();
		return $error;
	}

	// Conservative default: ambiguous.
	mtuc_record_smartucf_start_outcome_unknown( $order, $error );
	return $error;
}
