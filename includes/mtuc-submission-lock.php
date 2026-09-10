<?php
/**
 * Atomic owner-aware financing submission locks (AUD-WOO-018 F01/F03).
 *
 * Ownership mutations use compare-and-swap against the options row so release and
 * stale takeover cannot race through separate PHP read/compare/delete steps.
 *
 * When $wpdb is available, CAS runs as a single conditional SQL statement on
 * {$wpdb->options}. Isolated tests use the in-memory mtuc_test_options store with
 * the same compare semantics.
 *
 * A fixed lease TTL alone does not authorize remote work after takeover: callers
 * must re-check ownership via mtuc_submission_lock_still_owned() / the request
 * fence before irreversible CP/SmartUCF calls.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** wp_options prefix for submission ownership claims. */
const MTUC_SUBMISSION_LOCK_OPTION_PREFIX = 'mtuc_slock_';

/**
 * Ownership lifetime in seconds (default claim / reclaim threshold for normal stages).
 *
 * Remote exclusivity requires owner-conditional renew immediately before each
 * hard-bounded irreversible stage — see MTUC_SUBMISSION_LOCK_RENEW_* .
 *
 * Stage `create_armed` is intentionally non-reclaimable by TTL (AUD-WOO-018-F01):
 * local Woo create/init has no hard wall-clock bound due to third-party hooks.
 */
const MTUC_SUBMISSION_LOCK_TTL = 300;

/**
 * Per-call renew for a single CP HTTP attempt (TIMEOUT=15 + local overhead).
 *
 * Source: class-mtuc-cp-api-client.php private const TIMEOUT = 15.
 */
const MTUC_SUBMISSION_LOCK_RENEW_HTTP_CP = 30;

/**
 * Per-call renew for a single SmartUCF curl attempt (CURLOPT_TIMEOUT=10 + overhead).
 *
 * Source: class-mtuc-smartucf-api-client.php TIMEOUT = 10.
 */
const MTUC_SUBMISSION_LOCK_RENEW_HTTP_SMARTUCF = 25;

/**
 * Renew covering certificate filesystem lock wait (acquire_lock default 10s + overhead).
 */
const MTUC_SUBMISSION_LOCK_RENEW_FS_LOCK = 20;

/**
 * Lock stage: create/init armed — not automatically reclaimable by expiry.
 */
const MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED = 'create_armed';

/**
 * Lock stage: single CP HTTP attempt in progress.
 */
const MTUC_SUBMISSION_LOCK_STAGE_CP_HTTP = 'cp_http';

/**
 * Lock stage: single SmartUCF HTTP attempt in progress.
 */
const MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP = 'smartucf_http';

/**
 * Lock stage: certificate metadata/bundle CP HTTP.
 */
const MTUC_SUBMISSION_LOCK_STAGE_CERT_HTTP = 'cert_http';

/**
 * @deprecated Use stage renews; kept as alias for create_armed entry renew seconds (metadata only).
 */
const MTUC_SUBMISSION_LOCK_RENEW_CREATE = 90;

/**
 * @deprecated Prefer MTUC_SUBMISSION_LOCK_RENEW_HTTP_CP per attempt.
 */
const MTUC_SUBMISSION_LOCK_RENEW_CP = 30;

/**
 * @deprecated Prefer MTUC_SUBMISSION_LOCK_RENEW_HTTP_SMARTUCF per attempt.
 */
const MTUC_SUBMISSION_LOCK_RENEW_SMARTUCF = 25;

/**
 * Option key for a submission lock scope.
 *
 * @param string $lock_key Scope hash from popup/checkout builders.
 * @return string
 */
function mtuc_submission_lock_option_key( string $lock_key ): string {
	return MTUC_SUBMISSION_LOCK_OPTION_PREFIX . $lock_key;
}

/**
 * Generate a unique owner token for a claim.
 *
 * @return string
 */
function mtuc_generate_submission_lock_owner(): string {
	try {
		return bin2hex( random_bytes( 16 ) );
	} catch ( Exception $e ) {
		return md5( uniqid( (string) mt_rand(), true ) );
	}
}

/**
 * Encode lock payload (deterministic key order for durable CAS compares).
 *
 * @param string $owner      Owner token.
 * @param int    $claimed_at Unix claim time.
 * @param int    $expires_at Unix expiry time.
 * @param string $stage      Optional ownership stage marker.
 * @return string
 */
function mtuc_encode_submission_lock_payload( string $owner, int $claimed_at, int $expires_at, string $stage = '' ): string {
	$payload = array(
		'owner'      => $owner,
		'claimed_at' => $claimed_at,
		'expires_at' => $expires_at,
		'stage'      => $stage,
	);
	$encoded = wp_json_encode( $payload );

	return is_string( $encoded ) ? $encoded : '';
}

/**
 * Decode a submission lock payload string.
 *
 * @param string $raw Raw option value.
 * @return array{owner:string,claimed_at:int,expires_at:int,stage:string}|null
 */
function mtuc_decode_submission_lock_payload( string $raw ): ?array {
	if ( '' === $raw ) {
		return null;
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return null;
	}

	$owner = isset( $data['owner'] ) ? (string) $data['owner'] : '';
	if ( '' === $owner ) {
		return null;
	}

	return array(
		'owner'      => $owner,
		'claimed_at' => isset( $data['claimed_at'] ) ? (int) $data['claimed_at'] : 0,
		'expires_at' => isset( $data['expires_at'] ) ? (int) $data['expires_at'] : 0,
		'stage'      => isset( $data['stage'] ) ? (string) $data['stage'] : '',
	);
}

/**
 * Decode a submission lock option value.
 *
 * @param string $option_key Option key.
 * @return array{owner:string,claimed_at:int,expires_at:int}|null
 */
function mtuc_read_submission_lock( string $option_key ): ?array {
	$raw = mtuc_get_option_raw_value( $option_key );
	if ( null === $raw ) {
		return null;
	}

	return mtuc_decode_submission_lock_payload( $raw );
}

/**
 * Whether a lock payload is past its reclaim threshold.
 *
 * @param array{owner:string,claimed_at:int,expires_at:int,stage?:string} $lock Lock payload.
 * @param int                                                              $now  Unix now.
 * @return bool
 */
function mtuc_submission_lock_is_stale( array $lock, int $now = 0 ): bool {
	if ( $now <= 0 ) {
		$now = time();
	}

	/*
	 * Create/init armed: no automatic TTL reclaim. Local Woo hooks have no hard
	 * wall-clock bound; safety prefers a stuck owner over duplicate order creation.
	 */
	if ( MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED === (string) ( $lock['stage'] ?? '' ) ) {
		return false;
	}

	if ( $lock['expires_at'] > 0 ) {
		return $lock['expires_at'] <= $now;
	}

	if ( $lock['claimed_at'] > 0 ) {
		return ( $now - $lock['claimed_at'] ) >= MTUC_SUBMISSION_LOCK_TTL;
	}

	return true;
}

/**
 * Read the durable option_value string used for CAS compares.
 *
 * @param string $option_name Option name.
 * @return string|null
 */
function mtuc_get_option_raw_value( string $option_name ): ?string {
	if ( isset( $GLOBALS['mtuc_test_options'] ) && is_array( $GLOBALS['mtuc_test_options'] ) ) {
		if ( ! array_key_exists( $option_name, $GLOBALS['mtuc_test_options'] ) ) {
			return null;
		}
		$value = $GLOBALS['mtuc_test_options'][ $option_name ];

		return is_string( $value ) ? $value : null;
	}

	global $wpdb;
	if ( ! ( $wpdb instanceof wpdb ) || '' === (string) $wpdb->options ) {
		$fallback = get_option( $option_name, null );

		return is_string( $fallback ) ? $fallback : null;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$value = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
			$option_name
		)
	);

	return is_string( $value ) ? $value : null;
}

/**
 * Invalidate WP option caches after a direct options-table mutation.
 *
 * @param string $option_name Option name.
 * @return void
 */
function mtuc_invalidate_option_cache( string $option_name ): void {
	if ( function_exists( 'wp_cache_delete' ) ) {
		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}
}

/**
 * Conditionally replace an option value (compare-and-set).
 *
 * When expected_value === new_value the intended write is a no-op. MySQL/InnoDB may
 * report 0 changed rows for such UPDATEs; treat that as success only after a durable
 * raw read proves stored still equals expected (owner not replaced / row not deleted).
 *
 * @param string $option_name    Option name.
 * @param string $expected_value Exact current option_value.
 * @param string $new_value      Replacement option_value.
 * @return bool True when the CAS succeeds (row updated, or proven no-op match).
 */
function mtuc_options_cas_update( string $option_name, string $expected_value, string $new_value ): bool {
	if ( isset( $GLOBALS['mtuc_test_options'] ) && is_array( $GLOBALS['mtuc_test_options'] ) ) {
		if ( ! empty( $GLOBALS['mtuc_test_force_cas_update_fail'] ) ) {
			return false;
		}
		if ( ! array_key_exists( $option_name, $GLOBALS['mtuc_test_options'] ) ) {
			return false;
		}
		if ( (string) $GLOBALS['mtuc_test_options'][ $option_name ] !== $expected_value ) {
			return false;
		}
		if ( $expected_value === $new_value ) {
			// Proven no-op: row exists and still matches; do not rewrite.
			return true;
		}
		$GLOBALS['mtuc_test_options'][ $option_name ] = $new_value;

		return true;
	}

	global $wpdb;
	if ( ! ( $wpdb instanceof wpdb ) || '' === (string) $wpdb->options ) {
		return false;
	}

	/*
	 * Identical write: skip UPDATE (avoids false 0-changed-row failure) and prove the
	 * durable row still matches expected. No cache mutation — value did not change.
	 */
	if ( $expected_value === $new_value ) {
		$stored = mtuc_get_option_raw_value( $option_name );

		return is_string( $stored ) && $stored === $expected_value;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$new_value,
			$option_name,
			$expected_value
		)
	);

	if ( 1 === (int) $updated ) {
		mtuc_invalidate_option_cache( $option_name );

		return true;
	}

	return false;
}

/**
 * Conditionally delete an option row (compare-and-delete).
 *
 * @param string $option_name    Option name.
 * @param string $expected_value Exact current option_value that must still match.
 * @return bool True when exactly one row was deleted.
 */
function mtuc_options_cas_delete( string $option_name, string $expected_value ): bool {
	if ( isset( $GLOBALS['mtuc_test_options'] ) && is_array( $GLOBALS['mtuc_test_options'] ) ) {
		if ( ! array_key_exists( $option_name, $GLOBALS['mtuc_test_options'] ) ) {
			return false;
		}
		if ( (string) $GLOBALS['mtuc_test_options'][ $option_name ] !== $expected_value ) {
			return false;
		}
		unset( $GLOBALS['mtuc_test_options'][ $option_name ] );

		return true;
	}

	global $wpdb;
	if ( ! ( $wpdb instanceof wpdb ) || '' === (string) $wpdb->options ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			$option_name,
			$expected_value
		)
	);

	if ( 1 === (int) $deleted ) {
		mtuc_invalidate_option_cache( $option_name );

		return true;
	}

	return false;
}

/**
 * Fresh durable ownership check.
 *
 * @param string $lock_key Scope key.
 * @param string $owner    Owner token.
 * @return bool
 */
function mtuc_submission_lock_still_owned( string $lock_key, string $owner ): bool {
	if ( '' === $owner ) {
		return false;
	}

	$raw = mtuc_get_option_raw_value( mtuc_submission_lock_option_key( $lock_key ) );
	if ( null === $raw ) {
		return false;
	}

	$lock = mtuc_decode_submission_lock_payload( $raw );
	if ( null === $lock ) {
		return false;
	}

	if ( ! hash_equals( $lock['owner'], $owner ) ) {
		return false;
	}

	if ( mtuc_submission_lock_is_stale( $lock ) ) {
		return false;
	}

	return true;
}

/**
 * Arm request-scoped fence for irreversible remote boundaries.
 *
 * @param string $lock_key Scope key.
 * @param string $owner    Owner token.
 * @return void
 */
function mtuc_arm_submission_lock_fence( string $lock_key, string $owner ): void {
	$GLOBALS['mtuc_submission_lock_fence'] = array(
		'lock_key' => $lock_key,
		'owner'    => $owner,
	);
}

/**
 * Clear request fence when it still matches this owner.
 *
 * @param string $lock_key Scope key.
 * @param string $owner    Owner token.
 * @return void
 */
function mtuc_disarm_submission_lock_fence( string $lock_key, string $owner ): void {
	$fence = isset( $GLOBALS['mtuc_submission_lock_fence'] ) && is_array( $GLOBALS['mtuc_submission_lock_fence'] )
		? $GLOBALS['mtuc_submission_lock_fence']
		: null;

	if ( null === $fence ) {
		return;
	}

	if ( (string) ( $fence['lock_key'] ?? '' ) !== $lock_key ) {
		return;
	}

	if ( ! hash_equals( (string) ( $fence['owner'] ?? '' ), $owner ) ) {
		return;
	}

	$GLOBALS['mtuc_submission_lock_fence'] = null;
}

/**
 * Require current durable ownership when a submission fence is armed.
 *
 * When $renew_seconds is provided, perform owner-conditional CAS renew first so
 * the lease covers the upcoming bounded critical section. A replaced/expired
 * owner fails renew and must not continue.
 *
 * Paths without an armed fence (out-of-band admin/cron) are unchanged.
 *
 * @param int|null $renew_seconds Optional lease extension for the next section.
 * @param string   $stage         Optional stage marker written on renew.
 * @return true|WP_Error
 */
function mtuc_require_armed_submission_lock_ownership( $renew_seconds = null, string $stage = '' ) {
	$fence = isset( $GLOBALS['mtuc_submission_lock_fence'] ) && is_array( $GLOBALS['mtuc_submission_lock_fence'] )
		? $GLOBALS['mtuc_submission_lock_fence']
		: null;

	if ( null === $fence ) {
		return true;
	}

	$lock_key = isset( $fence['lock_key'] ) ? (string) $fence['lock_key'] : '';
	$owner    = isset( $fence['owner'] ) ? (string) $fence['owner'] : '';

	if ( '' === $lock_key || '' === $owner ) {
		return new WP_Error(
			'mtuc_submit_locked',
			__( 'Заявката вече се обработва. Моля, изчакайте.', 'mtunicredit' )
		);
	}

	if ( null !== $renew_seconds ) {
		if ( ! mtuc_renew_submission_lock( $lock_key, $owner, (int) $renew_seconds, $stage ) ) {
			return new WP_Error(
				'mtuc_submit_locked',
				__( 'Заявката вече се обработва. Моля, изчакайте.', 'mtunicredit' )
			);
		}

		return true;
	}

	if ( ! mtuc_submission_lock_still_owned( $lock_key, $owner ) ) {
		return new WP_Error(
			'mtuc_submit_locked',
			__( 'Заявката вече се обработва. Моля, изчакайте.', 'mtunicredit' )
		);
	}

	return true;
}

/**
 * Fence-aware renew before a hard-bounded irreversible HTTP/FS stage.
 *
 * @param int    $renew_seconds Lease extension covering the next call.
 * @param string $stage         Stage marker (cp_http / smartucf_http / cert_http).
 * @return true|WP_Error
 */
function mtuc_submission_lock_renew_before_stage( int $renew_seconds, string $stage ) {
	return mtuc_require_armed_submission_lock_ownership( $renew_seconds, $stage );
}

/**
 * Owner-conditional lease renew (compare-and-set on the inspected lock row).
 *
 * @param string $lock_key       Scope key.
 * @param string $owner          Owner token.
 * @param int    $extend_seconds Seconds from now for the new expires_at.
 * @param string $stage          Optional stage marker for the renewed claim.
 * @return bool True when this owner successfully extended the lease.
 */
function mtuc_renew_submission_lock( string $lock_key, string $owner, int $extend_seconds = 0, string $stage = '' ): bool {
	if ( '' === $owner ) {
		return false;
	}

	if ( $extend_seconds <= 0 ) {
		$extend_seconds = MTUC_SUBMISSION_LOCK_TTL;
	}

	$option_key = mtuc_submission_lock_option_key( $lock_key );
	$raw        = mtuc_get_option_raw_value( $option_key );
	if ( null === $raw ) {
		return false;
	}

	$existing = mtuc_decode_submission_lock_payload( $raw );
	if ( null === $existing || ! hash_equals( $existing['owner'], $owner ) ) {
		return false;
	}

	$now     = time();
	$claimed = $existing['claimed_at'] > 0 ? $existing['claimed_at'] : $now;
	/*
	 * Empty stage preserves the existing marker so renew-before-HTTP does not
	 * accidentally clear create_armed and reopen TTL reclaim.
	 */
	if ( '' === $stage ) {
		$stage = (string) ( $existing['stage'] ?? '' );
	}
	/*
	 * create_armed uses a far expires_at for diagnostics only; reclaim ignores TTL
	 * while stage remains create_armed.
	 */
	if ( MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED === $stage ) {
		$extend_seconds = max( $extend_seconds, 86400 );
	}
	$payload = mtuc_encode_submission_lock_payload( $owner, $claimed, $now + $extend_seconds, $stage );
	if ( '' === $payload ) {
		return false;
	}

	return mtuc_options_cas_update( $option_key, $raw, $payload );
}

/**
 * Atomically claim submission ownership for a scope.
 *
 * @param string $lock_key Scope key.
 * @return string|false Owner token on success; false when another owner holds a non-stale claim.
 */
function mtuc_claim_submission_lock( string $lock_key ) {
	$option_key = mtuc_submission_lock_option_key( $lock_key );
	$owner      = mtuc_generate_submission_lock_owner();
	$now        = time();
	$payload    = mtuc_encode_submission_lock_payload( $owner, $now, $now + MTUC_SUBMISSION_LOCK_TTL );

	if ( '' === $payload ) {
		return false;
	}

	if ( add_option( $option_key, $payload, '', 'no' ) ) {
		return $owner;
	}

	$raw = mtuc_get_option_raw_value( $option_key );
	if ( null === $raw ) {
		if ( add_option( $option_key, $payload, '', 'no' ) ) {
			return $owner;
		}

		return false;
	}

	$existing = mtuc_decode_submission_lock_payload( $raw );
	if ( null === $existing ) {
		return false;
	}

	if ( ! mtuc_submission_lock_is_stale( $existing, $now ) ) {
		return false;
	}

	/*
	 * Stale takeover: single conditional UPDATE replacing the inspected row value.
	 * Concurrent reclaimers race on the same expected value; only one UPDATE wins.
	 */
	if ( mtuc_options_cas_update( $option_key, $raw, $payload ) ) {
		return $owner;
	}

	return false;
}

/**
 * Release a claim only when the caller still owns the durable row.
 *
 * @param string $lock_key Scope key.
 * @param string $owner    Owner token from mtuc_claim_submission_lock().
 * @return bool True when this owner released the claim.
 */
function mtuc_release_submission_lock( string $lock_key, string $owner ): bool {
	if ( '' === $owner ) {
		return false;
	}

	$option_key = mtuc_submission_lock_option_key( $lock_key );
	$raw        = mtuc_get_option_raw_value( $option_key );
	if ( null === $raw ) {
		return false;
	}

	$existing = mtuc_decode_submission_lock_payload( $raw );
	if ( null === $existing || ! hash_equals( $existing['owner'], $owner ) ) {
		return false;
	}

	/*
	 * create_armed after creation_ref: do not drop the durable exclusivity marker on
	 * ambiguous post-create failures (AUD-WOO-018 Pass 5).
	 */
	if ( MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED === (string) ( $existing['stage'] ?? '' ) ) {
		return false;
	}

	/*
	 * Compare-and-delete on the exact inspected option_value. If another worker
	 * already replaced the row, DELETE matches zero rows and B's lock remains.
	 */
	return mtuc_options_cas_delete( $option_key, $raw );
}
