<?php
/**
 * Bank lifecycle technical state (AUD-WOO-004/005/008/009).
 *
 * Separates merchant-controlled Woo order status from bank financing status,
 * CP create ambiguity recovery, and CP status PATCH sync diagnostics.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Order meta: CP create outcome — created|unknown|missing. */
const MTUC_ORDER_META_CP_CREATE_OUTCOME = '_mtuc_cp_create_outcome';

/** Order meta: authoritative durable CP status sync target (JSON). */
const MTUC_ORDER_META_CP_SYNC_TARGET = '_mtuc_cp_status_sync_target';

/** Order meta: intended bank status awaiting successful CP PATCH (derived view). */
const MTUC_ORDER_META_CP_SYNC_PENDING = '_mtuc_cp_status_sync_pending';

/** Order meta: human label for pending sync status. */
const MTUC_ORDER_META_CP_SYNC_LABEL = '_mtuc_cp_status_sync_label';

/** Order meta: sanitized sync failure category. */
const MTUC_ORDER_META_CP_SYNC_ERROR = '_mtuc_cp_status_sync_error';

/** Order meta: sync attempt count. */
const MTUC_ORDER_META_CP_SYNC_ATTEMPTS = '_mtuc_cp_status_sync_attempts';

/** Order meta: last sync attempt unix timestamp. */
const MTUC_ORDER_META_CP_SYNC_LAST_AT = '_mtuc_cp_status_sync_last_at';

/** Max automatic CP status PATCH retries. */
const MTUC_CP_STATUS_SYNC_MAX_ATTEMPTS = 3;

/**
 * Ownership lifetime for one CP sync target mutation.
 *
 * The critical section is local only — reload, decide, write meta, save,
 * verify — so the lease is short. A crashed holder frees the target quickly
 * instead of blocking every later transition (AUD-WOO-019-REVIEW-01).
 */
const MTUC_CP_STATUS_SYNC_LOCK_TTL = 15;

/** Lock stage marker for CP status sync target mutations. */
const MTUC_CP_STATUS_SYNC_LOCK_STAGE = 'cp_sync_target';

/**
 * Whether a CP API error is an ambiguous transport outcome (timeout / 5xx / connection).
 *
 * Does not prove that CP did or did not commit the order.
 *
 * @param WP_Error $error API error.
 * @return bool
 */
function mtuc_is_cp_transport_ambiguous_error( WP_Error $error ): bool {
	$code = $error->get_error_code();

	if ( in_array(
		$code,
		array( 'http_request_failed', 'mtuc_api_invalid_json', 'mtuc_api_invalid_envelope' ),
		true
	) ) {
		return true;
	}

	if ( 'mtuc_api_http_error' !== $code ) {
		return false;
	}

	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

	return $status >= 500 || 0 === $status;
}

/**
 * Canonical CP failure codes that definitively prove POST /orders was rejected
 * before any order row could be committed (AUD-WOO-019-F01).
 *
 * Anything outside this list stays ambiguous: an unrecognised code is not proof.
 *
 * @return array<int, string>
 */
function mtuc_cp_terminal_create_error_codes(): array {
	return array(
		'validation',
		'invalid_payload',
		'invalid_request',
		'unprocessable_entity',
		'order_rejected',
		'shop_not_found',
		'shop_disabled',
	);
}

/**
 * Whether a CP error carries a canonical (strictly validated) failure envelope.
 *
 * @param WP_Error $error API error.
 * @return bool
 */
function mtuc_cp_error_has_canonical_envelope( WP_Error $error ): bool {
	if ( ! function_exists( 'mtuc_cp_error_envelope_code' ) ) {
		return false;
	}

	return '' !== mtuc_cp_error_envelope_code( $error );
}

/**
 * Whether a CP create error proves the create endpoint was unreachable / never ran.
 *
 * Real /api/v11 (wrong version) returns e.g. HTTP 403 Cloudflare HTML or HTTP 404
 * HTML — decoded as invalid_json / invalid_envelope. That proves POST /orders did
 * not commit a CP order, so the outcome is definitive absence (not ambiguity).
 *
 * HTTP 2xx / 5xx / 0 / timeout-class bodies remain ambiguous.
 *
 * @param WP_Error $error API or decode error.
 * @return bool
 */
function mtuc_cp_create_error_proves_unreachable_endpoint( WP_Error $error ): bool {
	$code = $error->get_error_code();
	if ( ! in_array(
		$code,
		array(
			'mtuc_api_invalid_json',
			'mtuc_api_invalid_envelope',
		),
		true
	) ) {
		return false;
	}

	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

	return in_array( $status, array( 403, 404, 405, 410 ), true );
}

/**
 * Whether a CP create result is ambiguous — CP may or may not have committed.
 *
 * Ambiguity is the default: only a canonical failure envelope carrying a
 * terminal semantic rejection code (or a canonical 409 conflict) is proof.
 * Timeouts, post-send transport loss, 401, 429, 5xx, malformed JSON/envelope
 * on 2xx, identity echo mismatch, unknown 4xx and malformed 409 all remain ambiguous.
 *
 * Exception: non-JSON / non-canonical body with HTTP 403/404/405/410 proves the
 * create route never accepted the order (wrong API base, missing route, edge block).
 *
 * @param WP_Error $error API or normalization error.
 * @return bool
 */
function mtuc_is_cp_create_ambiguous_error( WP_Error $error ): bool {
	$code = $error->get_error_code();

	if ( in_array(
		$code,
		array(
			'mtuc_cp_unusable_success',
			'mtuc_cp_identity_mismatch',
			'mtuc_cp_create_outcome_unknown',
		),
		true
	) ) {
		return true;
	}

	if ( mtuc_cp_create_error_proves_unreachable_endpoint( $error ) ) {
		return false;
	}

	if ( mtuc_is_cp_transport_ambiguous_error( $error ) ) {
		return true;
	}

	if ( 'mtuc_api_http_error' !== $code ) {
		return false;
	}

	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

	// Auth loss and throttling after send prove nothing about commit.
	if ( in_array( $status, array( 401, 429 ), true ) ) {
		return true;
	}

	$envelope_code = function_exists( 'mtuc_cp_error_envelope_code' )
		? mtuc_cp_error_envelope_code( $error )
		: '';

	// Non-canonical / undecodable 4xx (including malformed 409) is not proof.
	if ( '' === $envelope_code ) {
		return true;
	}

	if ( 409 === $status ) {
		return false;
	}

	return ! in_array( $envelope_code, mtuc_cp_terminal_create_error_codes(), true );
}

/**
 * Whether a CP create error is a definitive idempotency conflict.
 *
 * Requires HTTP 409 *and* a canonical failure envelope; a malformed 409 body
 * cannot distinguish "already created" from "never reached the handler".
 *
 * @param WP_Error $error API error.
 * @return bool
 */
function mtuc_is_cp_idempotency_conflict_error( WP_Error $error ): bool {
	if ( 'mtuc_api_http_error' !== $error->get_error_code() ) {
		return false;
	}

	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

	if ( 409 !== $status ) {
		return false;
	}

	return mtuc_cp_error_has_canonical_envelope( $error );
}

/**
 * Sanitize a CP sync failure into a short category (no secrets/PII).
 *
 * @param WP_Error $error API error.
 * @return string
 */
function mtuc_sanitize_cp_sync_error_category( WP_Error $error ): string {
	$code = $error->get_error_code();

	if ( 'http_request_failed' === $code ) {
		return 'transport_timeout';
	}

	if ( in_array( $code, array( 'mtuc_api_invalid_json', 'mtuc_api_invalid_envelope', 'mtuc_cp_patch_unusable_success' ), true ) ) {
		return 'malformed_response';
	}

	if ( 'mtuc_cp_patch_echo_mismatch' === $code ) {
		return 'echo_mismatch';
	}

	if ( 'mtuc_api_http_error' === $code ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 401 === $status ) {
			return 'auth_401';
		}
		if ( 429 === $status ) {
			return 'http_429';
		}
		if ( $status >= 500 ) {
			return 'http_5xx';
		}
		if ( $status >= 400 ) {
			return 'http_4xx';
		}
	}

	return 'api_error';
}

/**
 * Canonical CP failure codes that make a status PATCH permanently unachievable.
 *
 * Every other outcome — auth loss, timeout, transport, 429, 5xx, malformed
 * bodies, echo mismatch, unknown codes — leaves the target pending for retry.
 *
 * @return array<int, string>
 */
function mtuc_cp_terminal_sync_error_codes(): array {
	return array(
		'invalid_payload',
		'semantic_conflict',
		'unsupported_status',
		'order_not_found',
	);
}

/**
 * Whether a CP status PATCH failure is terminal for the durable target.
 *
 * @param WP_Error $error PATCH failure.
 * @return bool
 */
function mtuc_is_terminal_cp_sync_error( WP_Error $error ): bool {
	if ( 'mtuc_api_http_error' !== $error->get_error_code() ) {
		return false;
	}

	if ( ! function_exists( 'mtuc_cp_error_envelope_code' ) ) {
		return false;
	}

	$envelope_code = mtuc_cp_error_envelope_code( $error );
	if ( '' === $envelope_code ) {
		return false;
	}

	return in_array( $envelope_code, mtuc_cp_terminal_sync_error_codes(), true );
}

/**
 * Read the durable CP status sync target from order meta.
 *
 * Malformed/noncanonical JSON is treated as absent rather than trusted.
 *
 * @param WC_Order $order Order instance.
 * @return array{state:string,generation:int,status_id:string,status:string,error:string,updated_at:int}|null
 */
function mtuc_read_cp_status_sync_target( WC_Order $order ): ?array {
	$raw = $order->get_meta( MTUC_ORDER_META_CP_SYNC_TARGET );
	if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
		return null;
	}

	$decoded = json_decode( $raw, true );
	if ( ! is_array( $decoded ) ) {
		return null;
	}

	$state = isset( $decoded['state'] ) && is_string( $decoded['state'] ) ? $decoded['state'] : '';
	if ( ! in_array( $state, array( 'not_needed', 'pending', 'confirmed', 'terminal_failed' ), true ) ) {
		return null;
	}

	$generation = isset( $decoded['generation'] ) ? (int) $decoded['generation'] : 0;
	if ( $generation < 1 ) {
		return null;
	}

	$status_id = isset( $decoded['status_id'] ) && is_string( $decoded['status_id'] )
		? sanitize_key( $decoded['status_id'] )
		: '';
	if ( '' === $status_id ) {
		return null;
	}

	return array(
		'state'      => $state,
		'generation' => $generation,
		'status_id'  => $status_id,
		'status'     => isset( $decoded['status'] ) && is_string( $decoded['status'] ) ? $decoded['status'] : '',
		'error'      => isset( $decoded['error'] ) && is_string( $decoded['error'] ) ? $decoded['error'] : '',
		'updated_at' => isset( $decoded['updated_at'] ) ? (int) $decoded['updated_at'] : 0,
	);
}

/**
 * Encode a durable CP status sync target record.
 *
 * @param array<string, mixed> $target Target record.
 * @return string
 */
function mtuc_encode_cp_status_sync_target( array $target ): string {
	return (string) wp_json_encode(
		array(
			'state'      => (string) $target['state'],
			'generation' => (int) $target['generation'],
			'status_id'  => (string) $target['status_id'],
			'status'     => (string) $target['status'],
			'error'      => (string) ( $target['error'] ?? '' ),
			'updated_at' => (int) ( $target['updated_at'] ?? time() ),
		)
	);
}

/**
 * Save an order and report whether the write is actually durable.
 *
 * WC_Order::save() normally returns the order ID. A storage layer that returns
 * false, or throws a WP_Error back, means nothing was committed — the caller
 * must not then claim the fact it tried to persist (AUD-WOO-019-REVIEW-02).
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_save_order_durably( WC_Order $order ): bool {
	$saved = $order->save();

	if ( false === $saved || is_wp_error( $saved ) ) {
		return false;
	}

	return true;
}

/**
 * Persist the durable CP status sync target (authoritative record).
 *
 * @param WC_Order             $order  Order instance.
 * @param array<string, mixed> $target Target record.
 * @return bool True when the order save is durable.
 */
function mtuc_write_cp_status_sync_target( WC_Order $order, array $target ): bool {
	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_TARGET, mtuc_encode_cp_status_sync_target( $target ) );

	return mtuc_save_order_durably( $order );
}

/**
 * Copy the authoritative target record onto another in-memory instance.
 *
 * The mutation runs against a freshly reloaded order. Without this mirror, a
 * later save() on the caller's older instance would write the pre-lock value
 * back over the record that was just committed.
 *
 * @param WC_Order $destination In-memory instance held by the caller.
 * @param WC_Order $source      Instance the mutation was applied to.
 * @return void
 */
function mtuc_mirror_cp_status_sync_target( WC_Order $destination, WC_Order $source ): void {
	if ( $destination === $source ) {
		return;
	}

	$raw = $source->get_meta( MTUC_ORDER_META_CP_SYNC_TARGET );

	if ( is_string( $raw ) && '' !== $raw ) {
		$destination->update_meta_data( MTUC_ORDER_META_CP_SYNC_TARGET, $raw );

		return;
	}

	$destination->delete_meta_data( MTUC_ORDER_META_CP_SYNC_TARGET );
}

/**
 * Re-read an order from storage so compare/update races see committed state.
 *
 * Falls back to the in-memory instance when WooCommerce is unavailable (tests).
 *
 * @param WC_Order $order Order instance.
 * @return WC_Order
 */
function mtuc_reload_order_for_sync_target( WC_Order $order ): WC_Order {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return $order;
	}

	$reloaded = wc_get_order( $order->get_id() );

	return $reloaded instanceof WC_Order ? $reloaded : $order;
}

/**
 * Lock scope key guarding CP sync target mutations for one order.
 *
 * Reuses the submission-lock option namespace with a dedicated `cpsync_`
 * scope, so the durable row is `mtuc_slock_cpsync_{order_id}` and can never
 * collide with a financing submission claim.
 *
 * @param int $order_id WooCommerce order ID.
 * @return string
 */
function mtuc_cp_status_sync_lock_key( int $order_id ): string {
	return 'cpsync_' . max( 0, $order_id );
}

/**
 * Durable option key backing the CP sync target lock.
 *
 * @param int $order_id WooCommerce order ID.
 * @return string
 */
function mtuc_cp_status_sync_lock_option_key( int $order_id ): string {
	return mtuc_submission_lock_option_key( mtuc_cp_status_sync_lock_key( $order_id ) );
}

/**
 * Canonical contention failure for CP sync target mutations.
 *
 * @return WP_Error
 */
function mtuc_cp_status_sync_lock_contention_error(): WP_Error {
	return new WP_Error(
		'mtuc_cp_sync_lock_contention',
		__( 'Целевият банков статус към КП се променя от друг процес.', 'mtunicredit' )
	);
}

/**
 * Acquire exclusive authority over an order's CP sync target.
 *
 * Claiming is a single compare-and-set against the durable options row, so two
 * concurrent workers cannot both believe they own the transition. An expired
 * lease is reclaimable, but only by replacing the exact bytes that were read.
 *
 * @param int $order_id WooCommerce order ID.
 * @return string|WP_Error Owner token, or a contention/unavailability error.
 */
function mtuc_acquire_cp_status_sync_mutation( int $order_id ) {
	if ( $order_id <= 0 ) {
		return new WP_Error(
			'mtuc_cp_sync_lock_invalid_order',
			__( 'Липсва валидна поръчка за заключване на КП статус целта.', 'mtunicredit' )
		);
	}

	if ( ! function_exists( 'mtuc_generate_submission_lock_owner' ) || ! function_exists( 'mtuc_options_cas_update' ) ) {
		return new WP_Error(
			'mtuc_cp_sync_lock_unavailable',
			__( 'Механизмът за заключване на КП статус целта не е наличен.', 'mtunicredit' )
		);
	}

	$option_key = mtuc_cp_status_sync_lock_option_key( $order_id );
	$owner      = mtuc_generate_submission_lock_owner();
	$now        = time();
	$payload    = mtuc_encode_submission_lock_payload(
		$owner,
		$now,
		$now + MTUC_CP_STATUS_SYNC_LOCK_TTL,
		MTUC_CP_STATUS_SYNC_LOCK_STAGE
	);

	if ( '' === $payload ) {
		return new WP_Error(
			'mtuc_cp_sync_lock_unavailable',
			__( 'Механизмът за заключване на КП статус целта не е наличен.', 'mtunicredit' )
		);
	}

	if ( add_option( $option_key, $payload, '', 'no' ) ) {
		return $owner;
	}

	$raw = mtuc_get_option_raw_value( $option_key );
	if ( null === $raw ) {
		return add_option( $option_key, $payload, '', 'no' ) ? $owner : mtuc_cp_status_sync_lock_contention_error();
	}

	$existing = mtuc_decode_submission_lock_payload( $raw );

	/*
	 * A malformed row owns nothing, but it is still replaced conditionally: if
	 * a real owner wrote in between, the CAS misses and this caller backs off.
	 */
	if ( null !== $existing && ! mtuc_submission_lock_is_stale( $existing, $now ) ) {
		return mtuc_cp_status_sync_lock_contention_error();
	}

	if ( mtuc_options_cas_update( $option_key, $raw, $payload ) ) {
		return $owner;
	}

	return mtuc_cp_status_sync_lock_contention_error();
}

/**
 * Release CP sync target authority, but only when this caller still holds it.
 *
 * Compare-and-delete on the exact bytes that were inspected: an owner whose
 * lease already expired and was taken over deletes nothing, so it cannot open
 * the replacement owner's critical section.
 *
 * @param int    $order_id    WooCommerce order ID.
 * @param string $owner_token Token from mtuc_acquire_cp_status_sync_mutation().
 * @return bool True when this owner released its own claim.
 */
function mtuc_release_cp_status_sync_mutation( int $order_id, string $owner_token ): bool {
	if ( $order_id <= 0 || '' === $owner_token || ! function_exists( 'mtuc_options_cas_delete' ) ) {
		return false;
	}

	$option_key = mtuc_cp_status_sync_lock_option_key( $order_id );
	$raw        = mtuc_get_option_raw_value( $option_key );
	if ( null === $raw ) {
		return false;
	}

	$existing = mtuc_decode_submission_lock_payload( $raw );
	if ( null === $existing || ! hash_equals( $existing['owner'], $owner_token ) ) {
		return false;
	}

	return mtuc_options_cas_delete( $option_key, $raw );
}

/**
 * Run a CP sync target transition under exclusive durable authority.
 *
 * Inside the lock the sequence is always the same: reload the order from
 * storage, read the persisted target, decide, write, save, verify. Callers
 * never see a decision taken against a stale in-memory copy.
 *
 * @param WC_Order $order         Order instance held by the caller.
 * @param callable $mutation      fn( WC_Order $fresh, ?array $persisted ): mixed.
 * @param callable $on_contention fn( WP_Error $error ): mixed — fail-closed result.
 * @return mixed
 */
function mtuc_run_cp_status_sync_mutation( WC_Order $order, callable $mutation, callable $on_contention ) {
	$order_id = (int) $order->get_id();
	$owner    = mtuc_acquire_cp_status_sync_mutation( $order_id );

	if ( is_wp_error( $owner ) ) {
		return $on_contention( $owner );
	}

	try {
		$fresh  = mtuc_reload_order_for_sync_target( $order );
		$result = $mutation( $fresh, mtuc_read_cp_status_sync_target( $fresh ) );
		mtuc_mirror_cp_status_sync_target( $order, $fresh );
	} finally {
		mtuc_release_cp_status_sync_mutation( $order_id, (string) $owner );
	}

	return $result;
}

/**
 * Admit (or reuse) the durable CP status sync target for a bank status.
 *
 * Admission is the single authority for "a PATCH is owed to CP". It happens
 * before the PATCH and before any local success fact is written, so a crash
 * mid-flight leaves a retryable record instead of a silent divergence.
 *
 * @param WC_Order    $order     Order instance.
 * @param string      $status_id Target bank status key.
 * @param string|null $label     Optional human label sent with the PATCH.
 * @return array<string, mixed>|WP_Error Admitted/reused target.
 */
function mtuc_admit_cp_status_sync_target( WC_Order $order, string $status_id, ?string $label = null ) {
	$status_id = sanitize_key( $status_id );
	if ( '' === $status_id ) {
		return new WP_Error(
			'mtuc_cp_sync_invalid_target',
			__( 'Липсва целеви банков статус за синхронизация с КП.', 'mtunicredit' )
		);
	}

	$label = ( null !== $label && '' !== trim( $label ) )
		? trim( $label )
		: mtuc_get_bank_status_label( $status_id );

	return mtuc_run_cp_status_sync_mutation(
		$order,
		/**
		 * @param WC_Order                  $fresh    Reloaded order.
		 * @param array<string, mixed>|null $existing Persisted target.
		 * @return array<string, mixed>|WP_Error
		 */
		static function ( WC_Order $fresh, ?array $existing ) use ( $status_id, $label ) {
			if ( null !== $existing
				&& $existing['status_id'] === $status_id
				&& in_array( $existing['state'], array( 'pending', 'confirmed', 'terminal_failed' ), true )
			) {
				// Same target: pending retries, confirmed/terminal reported as-is.
				return $existing;
			}

			if ( null !== $existing
				&& $existing['status_id'] !== $status_id
				&& 'pending' === $existing['state']
			) {
				return new WP_Error(
					'mtuc_cp_sync_semantic_conflict',
					__( 'Друг банков статус вече чака потвърждение от КП; конфликтна цел не се допуска.', 'mtunicredit' ),
					array(
						'existing_status_id'  => $existing['status_id'],
						'requested_status_id' => $status_id,
					)
				);
			}

			$generation = null !== $existing ? $existing['generation'] + 1 : 1;

			$target = array(
				'state'      => 'pending',
				'generation' => $generation,
				'status_id'  => $status_id,
				'status'     => $label,
				'error'      => '',
				'updated_at' => time(),
			);

			if ( ! mtuc_write_cp_status_sync_target( $fresh, $target ) ) {
				return new WP_Error(
					'mtuc_cp_sync_target_not_durable',
					__( 'Целевият банков статус към КП не е трайно записан.', 'mtunicredit' )
				);
			}

			// Verify against storage: the record we read back is the authority.
			$committed = mtuc_read_cp_status_sync_target( mtuc_reload_order_for_sync_target( $fresh ) );

			if ( null === $committed
				|| $committed['generation'] !== $generation
				|| $committed['status_id'] !== $status_id
			) {
				return new WP_Error(
					'mtuc_cp_sync_target_not_durable',
					__( 'Целевият банков статус към КП не е трайно записан.', 'mtunicredit' ),
					array( 'committed' => $committed )
				);
			}

			return $committed;
		},
		/**
		 * @param WP_Error $error Contention failure.
		 * @return WP_Error
		 */
		static function ( WP_Error $error ) {
			return $error;
		}
	);
}

/**
 * Confirm a durable sync target after a validated PATCH echo.
 *
 * A stale success (older generation, or a different status) must never confirm
 * a newer intent — the newer target stays pending for its own PATCH.
 *
 * @param WC_Order $order      Order instance.
 * @param int      $generation Generation the PATCH was issued for.
 * @param string   $status_id  Status the PATCH was issued for.
 * @return bool True when the target moved to confirmed.
 */
function mtuc_confirm_cp_status_sync_target( WC_Order $order, int $generation, string $status_id ): bool {
	$status_id = sanitize_key( $status_id );

	return (bool) mtuc_run_cp_status_sync_mutation(
		$order,
		/**
		 * @param WC_Order                  $fresh   Reloaded order.
		 * @param array<string, mixed>|null $current Persisted target.
		 * @return bool
		 */
		static function ( WC_Order $fresh, ?array $current ) use ( $generation, $status_id ) {
			if ( null === $current ) {
				return false;
			}

			// A stale success can neither confirm nor disturb a newer intent.
			if ( $current['generation'] !== $generation || $current['status_id'] !== $status_id ) {
				return false;
			}

			if ( 'confirmed' === $current['state'] ) {
				return true;
			}

			$confirmed = mtuc_write_cp_status_sync_target(
				$fresh,
				array(
					'state'      => 'confirmed',
					'generation' => $current['generation'],
					'status_id'  => $current['status_id'],
					'status'     => $current['status'],
					'error'      => '',
					'updated_at' => time(),
				)
			);

			if ( ! $confirmed ) {
				return false;
			}

			$verified = mtuc_read_cp_status_sync_target( mtuc_reload_order_for_sync_target( $fresh ) );

			return null !== $verified
				&& 'confirmed' === $verified['state']
				&& $verified['generation'] === $generation
				&& $verified['status_id'] === $status_id;
		},
		/**
		 * @param WP_Error $error Contention failure.
		 * @return bool
		 */
		static function ( WP_Error $error ) {
			unset( $error );

			// Fail closed: no confirmation is claimed when authority is not held.
			return false;
		}
	);
}

/**
 * Record a PATCH failure against a durable sync target.
 *
 * @param WC_Order $order      Order instance.
 * @param int      $generation Generation the PATCH was issued for.
 * @param string   $status_id  Status the PATCH was issued for.
 * @param WP_Error $error      PATCH failure.
 * @return string Resulting state, or empty string when the failure was stale.
 */
function mtuc_fail_cp_status_sync_target( WC_Order $order, int $generation, string $status_id, WP_Error $error ): string {
	$status_id = sanitize_key( $status_id );

	return (string) mtuc_run_cp_status_sync_mutation(
		$order,
		/**
		 * @param WC_Order                  $fresh   Reloaded order.
		 * @param array<string, mixed>|null $current Persisted target.
		 * @return string
		 */
		static function ( WC_Order $fresh, ?array $current ) use ( $generation, $status_id, $error ) {
			if ( null === $current ) {
				return '';
			}

			// A stale failure must not fail a newer generation.
			if ( $current['generation'] !== $generation || $current['status_id'] !== $status_id ) {
				return '';
			}

			if ( 'confirmed' === $current['state'] ) {
				return 'confirmed';
			}

			$state = mtuc_is_terminal_cp_sync_error( $error ) ? 'terminal_failed' : 'pending';

			$written = mtuc_write_cp_status_sync_target(
				$fresh,
				array(
					'state'      => $state,
					'generation' => $current['generation'],
					'status_id'  => $current['status_id'],
					'status'     => $current['status'],
					'error'      => mtuc_sanitize_cp_sync_error_category( $error ),
					'updated_at' => time(),
				)
			);

			if ( ! $written ) {
				return '';
			}

			$verified = mtuc_read_cp_status_sync_target( mtuc_reload_order_for_sync_target( $fresh ) );

			return null !== $verified && $verified['generation'] === $generation ? $verified['state'] : '';
		},
		/**
		 * @param WP_Error $contention Contention failure.
		 * @return string
		 */
		static function ( WP_Error $contention ) {
			unset( $contention );

			// Fail closed: leave the durable target exactly as it was found.
			return '';
		}
	);
}

/**
 * Durable sync target state for admin/diagnostic reads.
 *
 * @param WC_Order $order Order instance.
 * @return string not_needed when no target has ever been admitted.
 */
function mtuc_get_cp_status_sync_state( WC_Order $order ): string {
	$target = mtuc_read_cp_status_sync_target( $order );

	return null === $target ? 'not_needed' : $target['state'];
}

/** Order meta: allowlisted CP create payload frozen before the first POST. */
const MTUC_ORDER_META_CP_CREATE_PAYLOAD = '_mtuc_cp_create_payload';

/** Order meta: sha256 fingerprint of the frozen CP create payload. */
const MTUC_ORDER_META_CP_CREATE_FINGERPRINT = '_mtuc_cp_create_fingerprint';

/** Order meta: unix time of the first POST /orders attempt. */
const MTUC_ORDER_META_CP_CREATE_ATTEMPT_AT = '_mtuc_cp_create_attempt_at';

/**
 * Canonical, stable representation of a CP create payload.
 *
 * Only allowlisted fields survive — ЕГН, second phone and every other
 * non-transport field are absent by construction — and keys are sorted so the
 * same logical request always produces the same bytes (AUD-WOO-019-REVIEW-03).
 *
 * @param array<string, mixed> $payload Proposed create payload.
 * @return array<string, mixed>
 */
function mtuc_canonicalize_cp_create_payload( array $payload ): array {
	$canonical = function_exists( 'mtuc_filter_cp_create_payload' )
		? mtuc_filter_cp_create_payload( $payload )
		: $payload;

	ksort( $canonical );

	return $canonical;
}

/**
 * Fingerprint identifying exactly which create request was sent.
 *
 * @param array<string, mixed> $payload Proposed create payload.
 * @return string Lowercase sha256 hex digest.
 */
function mtuc_cp_create_payload_fingerprint( array $payload ): string {
	return hash( 'sha256', (string) wp_json_encode( mtuc_canonicalize_cp_create_payload( $payload ) ) );
}

/**
 * Read the frozen create attempt evidence from an order.
 *
 * @param WC_Order $order Order instance.
 * @return array{fingerprint:string,payload:array<string,mixed>,attempt_at:int}|null
 */
function mtuc_read_cp_create_attempt_evidence( WC_Order $order ): ?array {
	$fingerprint = trim( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_FINGERPRINT ) );
	if ( '' === $fingerprint ) {
		return null;
	}

	$decoded = json_decode( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_PAYLOAD ), true );

	return array(
		'fingerprint' => $fingerprint,
		'payload'     => is_array( $decoded ) ? $decoded : array(),
		'attempt_at'  => (int) $order->get_meta( MTUC_ORDER_META_CP_CREATE_ATTEMPT_AT ),
	);
}

/**
 * Freeze the create payload as durable evidence before the first POST /orders.
 *
 * The evidence answers "what did this shop actually send to CP" after a
 * timeout, which is the only question a manual reconciliation can act on. It
 * is written once and never rewritten: a later call carrying a different
 * payload is refused rather than allowed to rewrite history and re-POST.
 *
 * @param WC_Order             $order   Order instance.
 * @param array<string, mixed> $payload Proposed create payload.
 * @return true|WP_Error
 */
function mtuc_freeze_cp_create_attempt( WC_Order $order, array $payload ) {
	$fingerprint = mtuc_cp_create_payload_fingerprint( $payload );
	$existing    = mtuc_read_cp_create_attempt_evidence( $order );

	if ( null !== $existing ) {
		if ( ! hash_equals( $existing['fingerprint'], $fingerprint ) ) {
			return new WP_Error(
				'mtuc_cp_create_payload_mismatch',
				__( 'Вече е записана друга заявка за създаване в КП; изпращането е спряно.', 'mtunicredit' ),
				array(
					'recorded_fingerprint' => $existing['fingerprint'],
					'proposed_fingerprint' => $fingerprint,
				)
			);
		}

		// Identical request: the existing evidence already describes it.
		return true;
	}

	$order->update_meta_data(
		MTUC_ORDER_META_CP_CREATE_PAYLOAD,
		(string) wp_json_encode( mtuc_canonicalize_cp_create_payload( $payload ) )
	);
	$order->update_meta_data( MTUC_ORDER_META_CP_CREATE_FINGERPRINT, $fingerprint );
	$order->update_meta_data( MTUC_ORDER_META_CP_CREATE_ATTEMPT_AT, time() );

	$not_durable = new WP_Error(
		'mtuc_cp_create_evidence_not_durable',
		__( 'Заявката към КП не може да бъде записана преди изпращане.', 'mtunicredit' )
	);

	if ( ! mtuc_save_order_durably( $order ) ) {
		return $not_durable;
	}

	$committed = mtuc_read_cp_create_attempt_evidence( mtuc_reload_order_for_sync_target( $order ) );
	if ( null === $committed || ! hash_equals( $committed['fingerprint'], $fingerprint ) ) {
		return $not_durable;
	}

	return true;
}

/**
 * Persist CP create outcome technical marker.
 *
 * @param WC_Order $order   Order instance.
 * @param string   $outcome created|unknown|missing.
 * @return void
 */
function mtuc_set_cp_create_outcome( WC_Order $order, string $outcome ): void {
	$outcome = sanitize_key( $outcome );
	if ( ! in_array( $outcome, array( 'created', 'unknown', 'missing' ), true ) ) {
		$outcome = 'unknown';
	}

	$order->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, $outcome );
}

/**
 * Clear CP create ambiguity marker after confirmed create.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_clear_cp_create_outcome_unknown( WC_Order $order ): void {
	$order->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'created' );
}

/**
 * Mark CP status PATCH as pending after a failed sync attempt.
 *
 * @param WC_Order $order           Order instance.
 * @param string   $bank_status_key Intended status_id.
 * @param string   $status_label    Intended label.
 * @param WP_Error $error           Sync error.
 * @return void
 */
function mtuc_mark_cp_status_sync_pending( WC_Order $order, string $bank_status_key, string $status_label, WP_Error $error ): void {
	$attempts = (int) $order->get_meta( MTUC_ORDER_META_CP_SYNC_ATTEMPTS );
	++$attempts;

	if ( function_exists( 'mtuc_record_order_financing_diagnostic' ) ) {
		mtuc_record_order_financing_diagnostic( $order, $error, 'sync' );
	}

	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_PENDING, sanitize_key( $bank_status_key ) );
	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_LABEL, $status_label );
	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_ERROR, mtuc_sanitize_cp_sync_error_category( $error ) );
	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_ATTEMPTS, $attempts );
	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_LAST_AT, time() );

	if ( $attempts <= MTUC_CP_STATUS_SYNC_MAX_ATTEMPTS ) {
		mtuc_schedule_cp_status_sync_retry( $order->get_id() );
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- sanitized diagnostics only.
		error_log(
			'MTUC CP status sync failed (order #' . $order->get_id()
			. ', status=' . sanitize_key( $bank_status_key )
			. ', category=' . mtuc_sanitize_cp_sync_error_category( $error )
			. ', attempt=' . $attempts . ')'
		);
	}
}

/**
 * Clear CP status sync pending markers after successful PATCH.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_clear_cp_status_sync_pending( WC_Order $order ): void {
	$order->delete_meta_data( MTUC_ORDER_META_CP_SYNC_PENDING );
	$order->delete_meta_data( MTUC_ORDER_META_CP_SYNC_LABEL );
	$order->delete_meta_data( MTUC_ORDER_META_CP_SYNC_ERROR );
	$order->delete_meta_data( MTUC_ORDER_META_CP_SYNC_ATTEMPTS );
	$order->delete_meta_data( MTUC_ORDER_META_CP_SYNC_LAST_AT );

	if ( function_exists( 'mtuc_clear_order_financing_diagnostic' ) ) {
		mtuc_clear_order_financing_diagnostic( $order );
	}
}

/**
 * Schedule a bounded single-event retry for CP status PATCH.
 *
 * @param int $order_id WooCommerce order ID.
 * @return void
 */
function mtuc_schedule_cp_status_sync_retry( int $order_id ): void {
	$order_id = max( 0, $order_id );
	if ( $order_id <= 0 || ! function_exists( 'wp_schedule_single_event' ) ) {
		return;
	}

	$hook = 'mtuc_retry_cp_status_sync';
	$args = array( $order_id );

	if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( $hook, $args ) ) {
		return;
	}

	wp_schedule_single_event( time() + 120, $hook, $args );
}

/**
 * Cron/admin retry handler for pending CP status PATCH.
 *
 * @param int $order_id WooCommerce order ID.
 * @return true|WP_Error
 */
function mtuc_retry_cp_status_sync_for_order( int $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'mtuc_wc_missing', __( 'WooCommerce не е наличен.', 'mtunicredit' ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'mtuc_order_missing', __( 'Поръчката не е намерена.', 'mtunicredit' ) );
	}

	// Retry is driven by the authoritative durable target (F03).
	$target = mtuc_read_cp_status_sync_target( $order );
	if ( null === $target || 'pending' !== $target['state'] ) {
		return true;
	}

	$attempts = (int) $order->get_meta( MTUC_ORDER_META_CP_SYNC_ATTEMPTS );
	if ( $attempts >= MTUC_CP_STATUS_SYNC_MAX_ATTEMPTS ) {
		return new WP_Error(
			'mtuc_cp_sync_max_attempts',
			__( 'Достигнат е лимитът за опити за синхронизация със КП.', 'mtunicredit' )
		);
	}

	$result = mtuc_sync_cp_order_bank_status(
		$order,
		$target['status_id'],
		'' !== $target['status'] ? $target['status'] : null
	);
	$order->save();

	return $result;
}

/**
 * Register lifecycle hooks (cron retry).
 *
 * @return void
 */
function mtuc_register_bank_lifecycle_hooks(): void {
	add_action( 'mtuc_retry_cp_status_sync', 'mtuc_retry_cp_status_sync_for_order', 10, 1 );
	add_action( 'admin_post_mtuc_retry_cp_status_sync', 'mtuc_admin_handle_retry_cp_status_sync' );
}

/**
 * Admin-post handler: manual CP status sync retry.
 *
 * @return void
 */
function mtuc_admin_handle_retry_cp_status_sync(): void {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'Нямате достатъчно права.', 'mtunicredit' ) );
	}

	$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
	check_admin_referer( 'mtuc_retry_cp_status_sync_' . $order_id );

	mtuc_retry_cp_status_sync_for_order( $order_id );

	$redirect = wp_get_referer();
	if ( ! is_string( $redirect ) || '' === $redirect ) {
		$redirect = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Human-readable CP create outcome for admin UI.
 *
 * @param WC_Order $order Order instance.
 * @return string Empty when none / created.
 */
function mtuc_get_cp_create_outcome_admin_label( WC_Order $order ): string {
	$outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ) );

	if ( 'unknown' === $outcome ) {
		return __( 'Създаването в КП е с неясен резултат (възможен timeout). Не се твърди, че поръчката липсва в КП.', 'mtunicredit' );
	}

	if ( 'missing' === $outcome ) {
		return __( 'Поръчката не е създадена в КП (потвърден отказ/грешка).', 'mtunicredit' );
	}

	return '';
}

/**
 * Human-readable CP status sync pending state for admin UI.
 *
 * @param WC_Order $order Order instance.
 * @return string Empty when not pending.
 */
function mtuc_get_cp_status_sync_admin_label( WC_Order $order ): string {
	$target = mtuc_read_cp_status_sync_target( $order );

	if ( null !== $target && 'terminal_failed' === $target['state'] ) {
		return sprintf(
			/* translators: 1: status key, 2: error category */
			__( 'Синхронизацията към КП е окончателно отказана: %1$s (грешка: %2$s).', 'mtunicredit' ),
			$target['status_id'],
			'' !== $target['error'] ? $target['error'] : 'unknown'
		);
	}

	if ( null === $target || 'pending' !== $target['state'] ) {
		return '';
	}

	$attempts = (int) $order->get_meta( MTUC_ORDER_META_CP_SYNC_ATTEMPTS );

	return sprintf(
		/* translators: 1: status key, 2: error category, 3: attempt count */
		__( 'Чакаща синхронизация към КП: %1$s (грешка: %2$s, опити: %3$d).', 'mtunicredit' ),
		$target['status_id'],
		'' !== $target['error'] ? $target['error'] : 'unknown',
		$attempts
	);
}
