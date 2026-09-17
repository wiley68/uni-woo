<?php
/**
 * Focused tests: cache satrudnik_email from CP shop snapshot (no mail).
 *
 * Run: php8.1 tests/run-satrudnik-email-cache-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_se_assert_count = 0;

/**
 * @param bool   $ok  Condition.
 * @param string $msg Failure message.
 * @return void
 */
function mtuc_se_assert( bool $ok, string $msg ): void {
	global $mtuc_se_assert_count;
	++$mtuc_se_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $msg . PHP_EOL );
		exit( 1 );
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return bool
	 */
	function is_email( $email ) {
		return is_string( $email ) && false !== strpos( $email, '@' ) && false !== strpos( $email, '.' );
	}
}

$unicid = 'SHOP-SATRUDNIK-UNICID';

/**
 * @param array<string, mixed> $extra Extra shop fields.
 * @return array<string, mixed>
 */
function mtuc_se_shop( array $extra = array() ): array {
	return array_merge(
		array(
			'uni_proces'   => 1,
			'uni_zaglavie' => 'Магазин',
			'uni_email'    => 'owner-fallback@example.com',
			'uni_eur'      => 1,
		),
		$extra
	);
}

/**
 * Capture persist path used by both pull refresh and CP push.
 *
 * @param array<string, mixed> $data Ingress snapshot.
 * @return array{prepared: array<string, mixed>|WP_Error, cached: ?array}
 */
function mtuc_se_prepare_and_capture( array $data ): array {
	$cached = null;
	$prepared = mtuc_prepare_shop_snapshot(
		$data,
		'SHOP-SATRUDNIK-UNICID',
		static function ( $unicid, $snapshot ) use ( &$cached ) {
			unset( $unicid );
			$cached = $snapshot;
			return true;
		}
	);

	return array(
		'prepared' => $prepared,
		'cached'   => $cached,
	);
}

// ---------------------------------------------------------------------------
// A. Pull snapshot with email
// ---------------------------------------------------------------------------

$a = mtuc_se_prepare_and_capture(
	mtuc_se_shop(
		array(
			'satrudnik_email' => 'employee@example.com',
		)
	)
);
mtuc_se_assert( is_array( $a['prepared'] ), 'A: prepare succeeds with satrudnik_email' );
mtuc_se_assert( is_array( $a['cached'] ), 'A: cache writer received snapshot' );
mtuc_se_assert( 'employee@example.com' === ( $a['cached']['satrudnik_email'] ?? null ), 'A: cached satrudnik_email' );
mtuc_se_assert(
	'employee@example.com' === mtuc_get_shop_satrudnik_email( $a['cached'] ),
	'A: reader returns cached email'
);

// ---------------------------------------------------------------------------
// B. Pull snapshot with null
// ---------------------------------------------------------------------------

$b = mtuc_se_prepare_and_capture(
	mtuc_se_shop(
		array(
			'satrudnik_email' => null,
		)
	)
);
mtuc_se_assert( is_array( $b['prepared'] ), 'B: prepare succeeds with null satrudnik_email' );
mtuc_se_assert( array_key_exists( 'satrudnik_email', $b['cached'] ), 'B: key present after null ingress' );
mtuc_se_assert( null === $b['cached']['satrudnik_email'], 'B: cached value is null' );
mtuc_se_assert( null === mtuc_get_shop_satrudnik_email( $b['cached'] ), 'B: reader returns null' );

// ---------------------------------------------------------------------------
// C. Missing field — backward compatibility
// ---------------------------------------------------------------------------

$c = mtuc_se_prepare_and_capture( mtuc_se_shop() );
mtuc_se_assert( is_array( $c['prepared'] ), 'C: old payload without satrudnik_email still prepares' );
mtuc_se_assert( ! is_wp_error( $c['prepared'] ), 'C: missing field is not a snapshot failure' );
mtuc_se_assert( array_key_exists( 'satrudnik_email', $c['cached'] ), 'C: normalized key present' );
mtuc_se_assert( null === $c['cached']['satrudnik_email'], 'C: missing → null' );

// ---------------------------------------------------------------------------
// D. Push snapshot (same prepare/persist path as update_from_cp_push)
// ---------------------------------------------------------------------------

$d = mtuc_se_prepare_and_capture(
	mtuc_se_shop(
		array(
			'satrudnik_email' => '  push.employee@example.com  ',
		)
	)
);
mtuc_se_assert( is_array( $d['cached'] ), 'D: push path captures snapshot' );
mtuc_se_assert( 'push.employee@example.com' === $d['cached']['satrudnik_email'], 'D: trimmed email cached' );

// Empty / invalid → null without failing snapshot.
$d_empty = mtuc_se_prepare_and_capture( mtuc_se_shop( array( 'satrudnik_email' => '   ' ) ) );
mtuc_se_assert( array_key_exists( 'satrudnik_email', $d_empty['cached'] ), 'D2: empty string keeps key' );
mtuc_se_assert( null === $d_empty['cached']['satrudnik_email'], 'D2b: empty string → null' );
$d_bad = mtuc_se_prepare_and_capture( mtuc_se_shop( array( 'satrudnik_email' => 'not-an-email' ) ) );
mtuc_se_assert( is_array( $d_bad['prepared'] ), 'D3: invalid email does not invalidate snapshot' );
mtuc_se_assert( null === $d_bad['cached']['satrudnik_email'], 'D4: invalid email → null' );

// ---------------------------------------------------------------------------
// E. Existing cache regression + no uni_email fallback
// ---------------------------------------------------------------------------

$e = mtuc_se_prepare_and_capture(
	mtuc_se_shop(
		array(
			'satrudnik_email' => null,
			'uni_email'       => 'owner-fallback@example.com',
			'nested'          => array( 'uni_kop' => 'CAT' ),
		)
	)
);
mtuc_se_assert( 1 === ( $e['cached']['uni_proces'] ?? null ), 'E: uni_proces preserved' );
mtuc_se_assert( 'Магазин' === ( $e['cached']['uni_zaglavie'] ?? null ), 'E: uni_zaglavie preserved' );
mtuc_se_assert( 1 === ( $e['cached']['uni_eur'] ?? null ), 'E: uni_eur preserved' );
mtuc_se_assert( 'owner-fallback@example.com' === ( $e['cached']['uni_email'] ?? null ), 'E: uni_email untouched' );
mtuc_se_assert( 'CAT' === ( $e['cached']['nested']['uni_kop'] ?? null ), 'E: nested business field preserved' );
mtuc_se_assert( null === $e['cached']['satrudnik_email'], 'E: null satrudnik_email does not fall back to uni_email' );
mtuc_se_assert(
	null === mtuc_get_shop_satrudnik_email( $e['cached'] ),
	'E: reader never returns uni_email'
);
mtuc_se_assert(
	! mtuc_is_shop_snapshot_secret_key( 'satrudnik_email' ),
	'E: satrudnik_email is not treated as secret key'
);

fwrite( STDOUT, 'OK: ' . $mtuc_se_assert_count . " satrudnik_email cache assertions passed\n" );
exit( 0 );
