<?php
/**
 * SmartUCF credential pair — encrypted dedicated store (AUD-WOO-018 / V4).
 *
 * One non-autoloaded option holds one AES-256-GCM record for both credentials.
 * Scope metadata is cryptographically bound via injective length-prefixed AAD.
 * Credential option + shop cache mutate under GET_LOCK + InnoDB transaction on
 * $wpdb. Authoritative credential reads use explicit DB result states and never
 * fall back to the WordPress option object-cache after a live DB ambiguity.
 *
 * Non-DB option-API / static-lock fallbacks are allowed ONLY when the explicit
 * test harness flag MTUC_SMARTUCF_TEST_HARNESS is enabled by test bootstrap.
 * Production with a dead/missing DB connection fails closed (DB_ERROR).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Dedicated encrypted SmartUCF credential option (site/blog scoped). */
const MTUC_SMARTUCF_CREDENTIALS_OPTION = 'mtuc_smartucf_credentials';

/** Historical mistaken plaintext option — uninstall only. */
const MTUC_SMARTUCF_CREDENTIALS_LEGACY_OPTION = 'mtuc_shop_credentials';

/** Cipher record version. */
const MTUC_SMARTUCF_CREDENTIALS_VERSION = 1;

/** HKDF domain-separation context (cross-platform frozen string). */
const MTUC_SMARTUCF_CREDENTIALS_HKDF_INFO = 'mt_uni_credit/settings-encryption/v1';

/** Clear/AAD algorithm label (not the OpenSSL method string). */
const MTUC_SMARTUCF_CREDENTIALS_ALGORITHM = 'AES-256-GCM';

/** OpenSSL cipher method for AES-256-GCM. */
const MTUC_SMARTUCF_CREDENTIALS_OPENSSL_METHOD = 'aes-256-gcm';

/** Pair classification states. */
const MTUC_SMARTUCF_PAIR_COMPLETE = 'complete';
const MTUC_SMARTUCF_PAIR_ABSENT   = 'absent';
const MTUC_SMARTUCF_PAIR_INVALID  = 'invalid';

/** Authoritative dedicated-option read states (never conflate via null). */
const MTUC_SMARTUCF_OPTION_ABSENT          = 'absent';
const MTUC_SMARTUCF_OPTION_PRESENT_VALID   = 'present_valid';
const MTUC_SMARTUCF_OPTION_PRESENT_INVALID = 'present_invalid';
const MTUC_SMARTUCF_OPTION_DB_ERROR        = 'db_error';

/** Cache-row locking read states. */
const MTUC_SMARTUCF_CACHE_LOCK_ABSENT    = 'absent';
const MTUC_SMARTUCF_CACHE_LOCK_PRESENT   = 'present';
const MTUC_SMARTUCF_CACHE_LOCK_DB_ERROR  = 'db_error';
const MTUC_SMARTUCF_CACHE_LOCK_AMBIGUOUS = 'ambiguous';

/** Advisory lock wait (seconds). */
const MTUC_SMARTUCF_MUTATION_LOCK_TIMEOUT = 15;

/**
 * Classify an ingress snapshot's SmartUCF credential pair.
 *
 * @param array<string, mixed> $shop_data Ingress shop snapshot.
 * @return string complete|absent|invalid
 */
function mtuc_classify_smartucf_credential_pair( array $shop_data ): string {
	$user_present = array_key_exists( 'uni_user', $shop_data );
	$pass_present = array_key_exists( 'uni_password', $shop_data );

	if ( mtuc_shop_snapshot_has_credential_aliases( $shop_data ) ) {
		return MTUC_SMARTUCF_PAIR_INVALID;
	}

	if ( ! $user_present && ! $pass_present ) {
		return MTUC_SMARTUCF_PAIR_ABSENT;
	}

	if ( ! $user_present || ! $pass_present ) {
		return MTUC_SMARTUCF_PAIR_INVALID;
	}

	$user = $shop_data['uni_user'];
	$pass = $shop_data['uni_password'];
	if ( ! is_string( $user ) || ! is_string( $pass ) ) {
		return MTUC_SMARTUCF_PAIR_INVALID;
	}

	if ( '' === trim( $user ) || '' === trim( $pass ) ) {
		return MTUC_SMARTUCF_PAIR_INVALID;
	}

	return MTUC_SMARTUCF_PAIR_COMPLETE;
}

/**
 * Whether top-level keys include credential aliases beyond exact canonical names.
 *
 * @param array<string, mixed> $shop_data Snapshot.
 * @return bool
 */
function mtuc_shop_snapshot_has_credential_aliases( array $shop_data ): bool {
	foreach ( array_keys( $shop_data ) as $key ) {
		if ( ! is_string( $key ) ) {
			continue;
		}
		if ( 'uni_user' === $key || 'uni_password' === $key ) {
			continue;
		}
		if ( mtuc_is_smartucf_credential_alias_key( $key ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Whether a key is a SmartUCF credential alias (case/separator insensitive).
 *
 * @param mixed $key Array key.
 * @return bool
 */
function mtuc_is_smartucf_credential_alias_key( $key ): bool {
	if ( ! is_string( $key ) ) {
		return false;
	}

	$normalized = function_exists( 'mtuc_normalize_snapshot_key' )
		? mtuc_normalize_snapshot_key( $key )
		: preg_replace( '/[^a-z0-9]/', '', strtolower( $key ) );

	if ( ! is_string( $normalized ) || '' === $normalized ) {
		return false;
	}

	return in_array(
		$normalized,
		array( 'uniuser', 'unipassword', 'user', 'pass' ),
		true
	);
}

/**
 * Recursively strip SmartUCF credential keys/aliases from a snapshot.
 *
 * @param mixed $value Snapshot node.
 * @return mixed
 */
function mtuc_strip_smartucf_credentials_from_snapshot( $value ) {
	if ( ! is_array( $value ) ) {
		return $value;
	}

	$clean = array();
	foreach ( $value as $key => $item ) {
		if ( is_string( $key ) && mtuc_is_smartucf_credential_alias_key( $key ) ) {
			continue;
		}
		if ( is_string( $key ) && in_array( $key, array( 'uni_user', 'uni_password' ), true ) ) {
			continue;
		}

		$clean[ $key ] = is_array( $item ) ? mtuc_strip_smartucf_credentials_from_snapshot( $item ) : $item;
	}

	return $clean;
}

/**
 * Whether diagnostic redaction must cover this key as a credential alias.
 *
 * @param mixed $key Key.
 * @return bool
 */
function mtuc_is_diagnostic_credential_key( $key ): bool {
	return mtuc_is_smartucf_credential_alias_key( $key )
		|| ( is_string( $key ) && in_array( $key, array( 'uni_user', 'uni_password', 'user', 'pass' ), true ) );
}

/**
 * Current blog/site identity for credential binding.
 *
 * @return int
 */
function mtuc_smartucf_credentials_blog_id(): int {
	if ( function_exists( 'get_current_blog_id' ) ) {
		return (int) get_current_blog_id();
	}

	return 1;
}

/**
 * Length-prefixed AAD field: `{name_len}:{name}{value_len}:{value}`.
 *
 * @param string $name  Field name.
 * @param string $value Field value (may contain newlines / equals / Unicode).
 * @return string
 */
function mtuc_smartucf_credentials_aad_field( string $name, string $value ): string {
	return (string) strlen( $name ) . ':' . $name . (string) strlen( $value ) . ':' . $value;
}

/**
 * Canonical AES-GCM associated authenticated data (injective, fixed field order).
 *
 * Encoding (exact):
 *   v1\n
 *   {len}:version{len}:{version}\n
 *   {len}:algorithm{len}:{algorithm}\n
 *   {len}:blog_id{len}:{blog_id}\n
 *   {len}:unicid{len}:{unicid}\n
 *   {len}:context{len}:{context}
 *
 * Different metadata tuples MUST produce different AAD byte strings even when
 * values contain newlines, equals signs, or field-like substrings.
 *
 * @param int    $version Record version.
 * @param string $algorithm Clear algorithm label.
 * @param int    $blog_id Blog id.
 * @param string $unicid Exact UNICID.
 * @return string
 */
function mtuc_smartucf_credentials_canonical_aad(
	int $version,
	string $algorithm,
	int $blog_id,
	string $unicid
): string {
	return "v1\n"
		. mtuc_smartucf_credentials_aad_field( 'version', (string) $version ) . "\n"
		. mtuc_smartucf_credentials_aad_field( 'algorithm', $algorithm ) . "\n"
		. mtuc_smartucf_credentials_aad_field( 'blog_id', (string) $blog_id ) . "\n"
		. mtuc_smartucf_credentials_aad_field( 'unicid', $unicid ) . "\n"
		. mtuc_smartucf_credentials_aad_field( 'context', MTUC_SMARTUCF_CREDENTIALS_HKDF_INFO );
}

/**
 * Build AAD from a clear credential record's scope fields.
 *
 * @param array<string, mixed> $record Record.
 * @return string|WP_Error
 */
function mtuc_smartucf_credentials_aad_from_record( array $record ) {
	$version   = (int) ( $record['version'] ?? 0 );
	$algorithm = (string) ( $record['algorithm'] ?? '' );
	$blog_id   = (int) ( $record['blog_id'] ?? 0 );
	$unicid    = (string) ( $record['unicid'] ?? '' );

	if ( $version <= 0 || '' === $algorithm || $blog_id <= 0 || '' === $unicid ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'SmartUCF credential записът няма пълен AAD обхват.', 'mtunicredit' )
		);
	}

	return mtuc_smartucf_credentials_canonical_aad( $version, $algorithm, $blog_id, $unicid );
}

/**
 * Installation-local input key material (never UNICID / module secret / tokens).
 *
 * @return string|WP_Error
 */
function mtuc_smartucf_credentials_ikm() {
	if ( ! function_exists( 'wp_salt' ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_crypto_unavailable',
			__( 'Липсва инсталационен ключ за криптиране на SmartUCF credentials.', 'mtunicredit' )
		);
	}

	$ikm = (string) wp_salt( 'auth' );
	if ( '' === $ikm ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_crypto_unavailable',
			__( 'Инсталационният ключ за SmartUCF credentials е празен.', 'mtunicredit' )
		);
	}

	return $ikm;
}

/**
 * Derive the 32-byte AES key via HKDF-SHA-256.
 *
 * @param string|null $ikm_override Test-only IKM override.
 * @return string|WP_Error Binary key.
 */
function mtuc_smartucf_credentials_derive_key( ?string $ikm_override = null ) {
	if ( ! function_exists( 'hash_hkdf' ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_crypto_unavailable',
			__( 'HKDF не е наличен на този PHP runtime.', 'mtunicredit' )
		);
	}

	$ikm = null !== $ikm_override ? $ikm_override : mtuc_smartucf_credentials_ikm();
	if ( is_wp_error( $ikm ) ) {
		return $ikm;
	}

	$key = hash_hkdf( 'sha256', (string) $ikm, 32, MTUC_SMARTUCF_CREDENTIALS_HKDF_INFO );
	if ( ! is_string( $key ) || 32 !== strlen( $key ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_crypto_unavailable',
			__( 'Неуспешна деривация на ключ за SmartUCF credentials.', 'mtunicredit' )
		);
	}

	return $key;
}

/**
 * Whether OpenSSL AES-256-GCM is available.
 *
 * @return bool
 */
function mtuc_smartucf_credentials_crypto_available(): bool {
	if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_decrypt' ) ) {
		return false;
	}
	if ( ! function_exists( 'hash_hkdf' ) ) {
		return false;
	}
	$methods = openssl_get_cipher_methods();
	return is_array( $methods ) && in_array( MTUC_SMARTUCF_CREDENTIALS_OPENSSL_METHOD, $methods, true );
}

/**
 * Encrypt a complete credential pair into a structured option record.
 *
 * @param string      $uni_user     Username.
 * @param string      $uni_password Password.
 * @param string      $unicid       Authenticated UNICID (binding, not key material).
 * @param string|null $ikm_override Test-only IKM.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_encrypt_smartucf_credential_pair(
	string $uni_user,
	string $uni_password,
	string $unicid,
	?string $ikm_override = null
) {
	$uni_user     = trim( $uni_user );
	$uni_password = trim( $uni_password );
	$unicid       = trim( $unicid );

	if ( '' === $uni_user || '' === $uni_password || '' === $unicid ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_invalid_pair',
			__( 'SmartUCF credential pair е непълен.', 'mtunicredit' )
		);
	}

	if ( ! mtuc_smartucf_credentials_crypto_available() ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_crypto_unavailable',
			__( 'AES-256-GCM не е наличен за SmartUCF credentials.', 'mtunicredit' )
		);
	}

	$key = mtuc_smartucf_credentials_derive_key( $ikm_override );
	if ( is_wp_error( $key ) ) {
		return $key;
	}

	$blog_id = mtuc_smartucf_credentials_blog_id();
	$aad     = mtuc_smartucf_credentials_canonical_aad(
		MTUC_SMARTUCF_CREDENTIALS_VERSION,
		MTUC_SMARTUCF_CREDENTIALS_ALGORITHM,
		$blog_id,
		$unicid
	);

	$plaintext = wp_json_encode(
		array(
			'uni_user'     => $uni_user,
			'uni_password' => $uni_password,
		),
		JSON_UNESCAPED_UNICODE
	);
	if ( ! is_string( $plaintext ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_encode_failed',
			__( 'Неуспешно кодиране на SmartUCF credential pair.', 'mtunicredit' )
		);
	}

	$nonce  = random_bytes( 12 );
	$tag    = '';
	$cipher = openssl_encrypt(
		$plaintext,
		MTUC_SMARTUCF_CREDENTIALS_OPENSSL_METHOD,
		$key,
		OPENSSL_RAW_DATA,
		$nonce,
		$tag,
		$aad,
		16
	);
	if ( false === $cipher || 16 !== strlen( $tag ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_encrypt_failed',
			__( 'Неуспешно криптиране на SmartUCF credentials.', 'mtunicredit' )
		);
	}

	return array(
		'version'    => MTUC_SMARTUCF_CREDENTIALS_VERSION,
		'algorithm'  => MTUC_SMARTUCF_CREDENTIALS_ALGORITHM,
		'blog_id'    => $blog_id,
		'unicid'     => $unicid,
		'nonce'      => base64_encode( $nonce ),
		'tag'        => base64_encode( $tag ),
		'ciphertext' => base64_encode( $cipher ),
	);
}

/**
 * Decrypt a structured credential record. Fail closed on any mismatch/tamper.
 *
 * @param mixed       $record          Stored record.
 * @param string|null $expected_unicid Optional UNICID scope check.
 * @param string|null $ikm_override    Test-only IKM.
 * @return array{uni_user: string, uni_password: string}|WP_Error
 */
function mtuc_decrypt_smartucf_credential_record(
	$record,
	?string $expected_unicid = null,
	?string $ikm_override = null
) {
	if ( ! is_array( $record ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'Липсва криптиран SmartUCF credential запис.', 'mtunicredit' )
		);
	}

	if ( (int) ( $record['version'] ?? 0 ) !== MTUC_SMARTUCF_CREDENTIALS_VERSION ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'Неподдържана версия на SmartUCF credential запис.', 'mtunicredit' )
		);
	}

	if ( MTUC_SMARTUCF_CREDENTIALS_ALGORITHM !== (string) ( $record['algorithm'] ?? '' ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'Неподдържан алгоритъм за SmartUCF credentials.', 'mtunicredit' )
		);
	}

	$blog_id = (int) ( $record['blog_id'] ?? 0 );
	if ( $blog_id !== mtuc_smartucf_credentials_blog_id() ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_scope_mismatch',
			__( 'SmartUCF credentials са за друг сайт.', 'mtunicredit' )
		);
	}

	$record_unicid = (string) ( $record['unicid'] ?? '' );
	if ( '' === $record_unicid ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'SmartUCF credential записът няма UNICID обхват.', 'mtunicredit' )
		);
	}

	if ( null !== $expected_unicid && '' !== trim( $expected_unicid )
		&& ! hash_equals( $record_unicid, trim( $expected_unicid ) )
	) {
		return new WP_Error(
			'mtuc_smartucf_credentials_scope_mismatch',
			__( 'SmartUCF credentials са за друг магазин (UNICID).', 'mtunicredit' )
		);
	}

	$aad = mtuc_smartucf_credentials_aad_from_record( $record );
	if ( is_wp_error( $aad ) ) {
		return $aad;
	}

	if ( ! mtuc_smartucf_credentials_crypto_available() ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_crypto_unavailable',
			__( 'AES-256-GCM не е наличен за SmartUCF credentials.', 'mtunicredit' )
		);
	}

	$key = mtuc_smartucf_credentials_derive_key( $ikm_override );
	if ( is_wp_error( $key ) ) {
		return $key;
	}

	$nonce = base64_decode( (string) ( $record['nonce'] ?? '' ), true );
	$tag   = base64_decode( (string) ( $record['tag'] ?? '' ), true );
	$ct    = base64_decode( (string) ( $record['ciphertext'] ?? '' ), true );

	if ( ! is_string( $nonce ) || 12 !== strlen( $nonce )
		|| ! is_string( $tag ) || 16 !== strlen( $tag )
		|| ! is_string( $ct ) || '' === $ct
	) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'Повредени SmartUCF credential байтове.', 'mtunicredit' )
		);
	}

	$plaintext = openssl_decrypt(
		$ct,
		MTUC_SMARTUCF_CREDENTIALS_OPENSSL_METHOD,
		$key,
		OPENSSL_RAW_DATA,
		$nonce,
		$tag,
		$aad
	);
	if ( false === $plaintext ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'Неуспешна автентикация/декриптиране на SmartUCF credentials.', 'mtunicredit' )
		);
	}

	$decoded = json_decode( $plaintext, true );
	if ( ! is_array( $decoded )
		|| ! is_string( $decoded['uni_user'] ?? null )
		|| ! is_string( $decoded['uni_password'] ?? null )
	) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'Невалиден plaintext shape на SmartUCF credentials.', 'mtunicredit' )
		);
	}

	$user = trim( $decoded['uni_user'] );
	$pass = trim( $decoded['uni_password'] );
	if ( '' === $user || '' === $pass ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'Декриптираният SmartUCF pair е празен.', 'mtunicredit' )
		);
	}

	return array(
		'uni_user'     => $user,
		'uni_password' => $pass,
	);
}

/**
 * Whether SmartUCF credential code may use non-DB test fallbacks.
 *
 * true ONLY via explicit test bootstrap signal (MTUC_SMARTUCF_TEST_HARNESS),
 * optionally overridden by $GLOBALS['mtuc_smartucf_test_harness_override'] for
 * focused production-semantics tests. Never inferred from missing $wpdb/dbh,
 * WP_DEBUG, CLI, localhost, or environment name.
 *
 * @return bool
 */
function mtuc_smartucf_is_test_harness_mode(): bool {
	if ( array_key_exists( 'mtuc_smartucf_test_harness_override', $GLOBALS ) ) {
		return (bool) $GLOBALS['mtuc_smartucf_test_harness_override'];
	}

	return defined( 'MTUC_SMARTUCF_TEST_HARNESS' ) && true === MTUC_SMARTUCF_TEST_HARNESS;
}

/**
 * Whether $wpdb has a live database connection (mysqli / query-capable link).
 *
 * @return bool
 */
function mtuc_smartucf_wpdb_has_live_connection(): bool {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return false;
	}

	if ( ! property_exists( $wpdb, 'dbh' ) || ! isset( $wpdb->dbh ) || false === $wpdb->dbh || null === $wpdb->dbh ) {
		return false;
	}

	return ( $wpdb->dbh instanceof mysqli || is_resource( $wpdb->dbh ) )
		|| ( is_object( $wpdb->dbh ) && method_exists( $wpdb->dbh, 'query' ) );
}

/**
 * Whether direct $wpdb->options access is authoritative for credential security.
 *
 * Production requires a live DB connection. A dead/missing dbh is NOT treated as
 * "use get_option()" — callers must fail closed with DB_ERROR.
 *
 * Under explicit test harness mode, `$GLOBALS['mtuc_test_authoritative_wpdb']`
 * may force a fake $wpdb double to be treated as authoritative.
 *
 * @return bool
 */
function mtuc_smartucf_has_authoritative_wpdb(): bool {
	global $wpdb;

	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
		return false;
	}
	if ( ! method_exists( $wpdb, 'get_results' ) && ! method_exists( $wpdb, 'get_var' ) ) {
		return false;
	}

	if ( mtuc_smartucf_is_test_harness_mode() && ! empty( $GLOBALS['mtuc_test_authoritative_wpdb'] ) ) {
		return true;
	}

	return mtuc_smartucf_wpdb_has_live_connection();
}

/**
 * Whether a $wpdb query result indicates SQL failure.
 *
 * @param mixed $result Query result.
 * @return bool
 */
function mtuc_smartucf_wpdb_query_failed( $result ): bool {
	global $wpdb;

	if ( false === $result ) {
		return true;
	}

	return isset( $wpdb ) && is_object( $wpdb )
		&& isset( $wpdb->last_error ) && is_string( $wpdb->last_error ) && '' !== $wpdb->last_error;
}

/**
 * Clear $wpdb->last_error before a security-sensitive query when possible.
 *
 * @return void
 */
function mtuc_smartucf_wpdb_clear_last_error(): void {
	global $wpdb;

	if ( isset( $wpdb ) && is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) {
		$wpdb->last_error = '';
	}
}

/**
 * Acceptable structural shape for a dedicated credential cipher record.
 *
 * Does not decrypt — only gates PRESENT_VALID vs PRESENT_INVALID.
 *
 * @param mixed $record Candidate record.
 * @return bool
 */
function mtuc_smartucf_credential_record_has_acceptable_shape( $record ): bool {
	if ( ! is_array( $record ) ) {
		return false;
	}

	$required = array( 'version', 'algorithm', 'blog_id', 'unicid', 'nonce', 'tag', 'ciphertext' );
	foreach ( $required as $key ) {
		if ( ! array_key_exists( $key, $record ) ) {
			return false;
		}
	}

	if ( (int) $record['version'] <= 0 ) {
		return false;
	}
	if ( ! is_string( $record['algorithm'] ) || '' === $record['algorithm'] ) {
		return false;
	}
	if ( (int) $record['blog_id'] <= 0 ) {
		return false;
	}
	if ( ! is_string( $record['unicid'] ) || '' === $record['unicid'] ) {
		return false;
	}
	if ( ! is_string( $record['nonce'] ) || '' === $record['nonce'] ) {
		return false;
	}
	if ( ! is_string( $record['tag'] ) || '' === $record['tag'] ) {
		return false;
	}
	if ( ! is_string( $record['ciphertext'] ) || '' === $record['ciphertext'] ) {
		return false;
	}

	return true;
}

/**
 * Build a structured authoritative option-read result.
 *
 * @param string               $state      One of MTUC_SMARTUCF_OPTION_*.
 * @param array<string, mixed>|null $value Decoded record when PRESENT_VALID.
 * @param string|null          $error_code Internal error class when applicable.
 * @return array{state: string, value: ?array, error_code: ?string}
 */
function mtuc_smartucf_credential_option_result( string $state, ?array $value = null, ?string $error_code = null ): array {
	return array(
		'state'      => $state,
		'value'      => $value,
		'error_code' => $error_code,
	);
}

/**
 * Test-harness option read via WordPress option API only.
 *
 * MUST NOT be used in production. Callers gate with mtuc_smartucf_is_test_harness_mode().
 *
 * @return array{state: string, value: ?array, error_code: ?string}
 */
function mtuc_smartucf_credential_option_read_via_option_api(): array {
	$raw = get_option( MTUC_SMARTUCF_CREDENTIALS_OPTION, null );
	if ( null === $raw || false === $raw ) {
		return mtuc_smartucf_credential_option_result( MTUC_SMARTUCF_OPTION_ABSENT );
	}
	if ( ! mtuc_smartucf_credential_record_has_acceptable_shape( $raw ) ) {
		return mtuc_smartucf_credential_option_result(
			MTUC_SMARTUCF_OPTION_PRESENT_INVALID,
			is_array( $raw ) ? $raw : null,
			'mtuc_smartucf_credential_record_invalid'
		);
	}

	return mtuc_smartucf_credential_option_result( MTUC_SMARTUCF_OPTION_PRESENT_VALID, $raw );
}

/**
 * Authoritative dedicated credential option read with explicit result states.
 *
 * States:
 * - ABSENT: SELECT succeeded, zero rows
 * - PRESENT_VALID: one row, non-empty, acceptable record shape
 * - PRESENT_INVALID: row exists but empty/malformed/duplicate/unserializable
 * - DB_ERROR: SQL/query failure OR production DB unavailable (NOT absence)
 *
 * Production with dead/missing DB ⇒ DB_ERROR (never get_option / object-cache).
 * Explicit test harness may use the option API when no authoritative $wpdb exists.
 *
 * @param bool $for_update Append FOR UPDATE (must run inside an open transaction).
 * @return array{state: string, value: ?array, error_code: ?string}
 */
function mtuc_smartucf_credential_option_read_result( bool $for_update = false ): array {
	global $wpdb;

	if ( ! mtuc_smartucf_has_authoritative_wpdb() ) {
		if ( mtuc_smartucf_is_test_harness_mode() ) {
			return mtuc_smartucf_credential_option_read_via_option_api();
		}

		return mtuc_smartucf_credential_option_result(
			MTUC_SMARTUCF_OPTION_DB_ERROR,
			null,
			'mtuc_smartucf_database_read_failed'
		);
	}

	mtuc_smartucf_wpdb_clear_last_error();

	$sql = $wpdb->prepare(
		"SELECT option_id, option_value FROM {$wpdb->options} WHERE option_name = %s",
		MTUC_SMARTUCF_CREDENTIALS_OPTION
	);
	if ( ! is_string( $sql ) || '' === $sql ) {
		return mtuc_smartucf_credential_option_result(
			MTUC_SMARTUCF_OPTION_DB_ERROR,
			null,
			'mtuc_smartucf_database_read_failed'
		);
	}
	if ( $for_update ) {
		$sql .= ' FOR UPDATE';
	}

	$rows = null;
	if ( method_exists( $wpdb, 'get_results' ) ) {
		$rows = $wpdb->get_results( $sql, defined( 'ARRAY_A' ) ? ARRAY_A : 'ARRAY_A' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	} elseif ( method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
		mtuc_smartucf_wpdb_clear_last_error();
		$count_sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
			MTUC_SMARTUCF_CREDENTIALS_OPTION
		);
		$count_raw = $wpdb->get_var( $count_sql );
		if ( mtuc_smartucf_wpdb_query_failed( $count_raw ) ) {
			return mtuc_smartucf_credential_option_result(
				MTUC_SMARTUCF_OPTION_DB_ERROR,
				null,
				$for_update ? 'mtuc_smartucf_database_lock_read_failed' : 'mtuc_smartucf_database_read_failed'
			);
		}
		$count = (int) $count_raw;
		if ( $count > 1 ) {
			return mtuc_smartucf_credential_option_result(
				MTUC_SMARTUCF_OPTION_PRESENT_INVALID,
				null,
				'mtuc_smartucf_credential_record_invalid'
			);
		}
		if ( 0 === $count ) {
			$rows = array();
		} else {
			$value_sql = $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				MTUC_SMARTUCF_CREDENTIALS_OPTION
			);
			if ( $for_update && is_string( $value_sql ) ) {
				$value_sql .= ' FOR UPDATE';
			}
			mtuc_smartucf_wpdb_clear_last_error();
			$raw_one = $wpdb->get_var( $value_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( mtuc_smartucf_wpdb_query_failed( $raw_one ) ) {
				return mtuc_smartucf_credential_option_result(
					MTUC_SMARTUCF_OPTION_DB_ERROR,
					null,
					$for_update ? 'mtuc_smartucf_database_lock_read_failed' : 'mtuc_smartucf_database_read_failed'
				);
			}
			$rows = array(
				array(
					'option_id'    => 1,
					'option_value' => $raw_one,
				),
			);
		}
	} else {
		return mtuc_smartucf_credential_option_result(
			MTUC_SMARTUCF_OPTION_DB_ERROR,
			null,
			'mtuc_smartucf_database_read_failed'
		);
	}

	$error_code = $for_update ? 'mtuc_smartucf_database_lock_read_failed' : 'mtuc_smartucf_database_read_failed';
	if ( mtuc_smartucf_wpdb_query_failed( $rows ) || ! is_array( $rows ) ) {
		return mtuc_smartucf_credential_option_result( MTUC_SMARTUCF_OPTION_DB_ERROR, null, $error_code );
	}

	$count = count( $rows );
	if ( 0 === $count ) {
		return mtuc_smartucf_credential_option_result( MTUC_SMARTUCF_OPTION_ABSENT );
	}
	if ( $count > 1 ) {
		return mtuc_smartucf_credential_option_result(
			MTUC_SMARTUCF_OPTION_PRESENT_INVALID,
			null,
			'mtuc_smartucf_credential_record_invalid'
		);
	}

	$raw = $rows[0]['option_value'] ?? null;
	if ( ! is_string( $raw ) || '' === $raw ) {
		return mtuc_smartucf_credential_option_result(
			MTUC_SMARTUCF_OPTION_PRESENT_INVALID,
			null,
			'mtuc_smartucf_credential_record_invalid'
		);
	}

	$decoded = maybe_unserialize( $raw );
	if ( ! mtuc_smartucf_credential_record_has_acceptable_shape( $decoded ) ) {
		return mtuc_smartucf_credential_option_result(
			MTUC_SMARTUCF_OPTION_PRESENT_INVALID,
			is_array( $decoded ) ? $decoded : null,
			'mtuc_smartucf_credential_record_invalid'
		);
	}

	return mtuc_smartucf_credential_option_result( MTUC_SMARTUCF_OPTION_PRESENT_VALID, $decoded );
}

/**
 * Read the raw dedicated option when PRESENT_VALID; otherwise null.
 *
 * Security-sensitive callers MUST use mtuc_smartucf_credential_option_read_result()
 * — null here conflates ABSENT / PRESENT_INVALID / DB_ERROR by design for compat.
 *
 * @return array<string, mixed>|null
 */
function mtuc_get_raw_smartucf_credential_option(): ?array {
	$read = mtuc_smartucf_credential_option_read_result( false );
	if ( MTUC_SMARTUCF_OPTION_PRESENT_VALID === $read['state'] ) {
		return $read['value'];
	}

	return null;
}

/**
 * Compatibility boolean — NOT safe for security decisions.
 *
 * true  = physical row PRESENT_VALID or PRESENT_INVALID, or DB_ERROR (fail-closed bias)
 * false = authoritative ABSENT only
 *
 * @return bool
 */
function mtuc_smartucf_credential_option_exists(): bool {
	$read = mtuc_smartucf_credential_option_read_result( false );
	return MTUC_SMARTUCF_OPTION_ABSENT !== $read['state'];
}

/**
 * @deprecated Use mtuc_smartucf_credential_option_read_result().
 *
 * @return array<string, mixed>|null PRESENT_VALID value only; null otherwise (including DB_ERROR).
 */
function mtuc_read_smartucf_credential_option_db(): ?array {
	if ( ! mtuc_smartucf_has_authoritative_wpdb() ) {
		return null;
	}
	$read = mtuc_smartucf_credential_option_read_result( false );
	return MTUC_SMARTUCF_OPTION_PRESENT_VALID === $read['state'] ? $read['value'] : null;
}

/**
 * Persist encrypted credential record via direct $wpdb write (transaction-safe).
 *
 * @param array<string, mixed> $record Cipher record.
 * @return true|WP_Error
 */
function mtuc_persist_smartucf_credential_record_db( array $record ) {
	global $wpdb;

	$serialized = maybe_serialize( $record );
	if ( ! is_string( $serialized ) || '' === $serialized ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_store_failed',
			__( 'Неуспешна сериализация на SmartUCF credentials.', 'mtunicredit' )
		);
	}

	// Explicit test harness without authoritative $wpdb: option API only.
	if ( ! mtuc_smartucf_has_authoritative_wpdb() ) {
		if ( ! mtuc_smartucf_is_test_harness_mode() ) {
			return new WP_Error(
				'mtuc_smartucf_database_read_failed',
				__( 'Няма жива DB връзка за запис на SmartUCF credentials.', 'mtunicredit' )
			);
		}
		$GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] = $record;
		if ( function_exists( 'update_option' ) ) {
			update_option( MTUC_SMARTUCF_CREDENTIALS_OPTION, $record, false );
		}
		return true;
	}

	$locked = mtuc_smartucf_credential_option_read_result( true );
	if ( MTUC_SMARTUCF_OPTION_DB_ERROR === $locked['state'] ) {
		return new WP_Error(
			'mtuc_smartucf_database_lock_read_failed',
			__( 'Неуспешен заключващ прочит на SmartUCF credentials.', 'mtunicredit' )
		);
	}
	if ( MTUC_SMARTUCF_OPTION_PRESENT_INVALID === $locked['state'] ) {
		return new WP_Error(
			'mtuc_smartucf_credential_record_invalid',
			__( 'Съществуващият SmartUCF credential запис е невалиден.', 'mtunicredit' )
		);
	}

	if ( MTUC_SMARTUCF_OPTION_PRESENT_VALID === $locked['state'] ) {
		mtuc_smartucf_wpdb_clear_last_error();
		$result = $wpdb->update(
			$wpdb->options,
			array(
				'option_value' => $serialized,
				'autoload'     => 'no',
			),
			array( 'option_name' => MTUC_SMARTUCF_CREDENTIALS_OPTION ),
			array( '%s', '%s' ),
			array( '%s' )
		);
		if ( false === $result || mtuc_smartucf_wpdb_query_failed( $result ) ) {
			return new WP_Error(
				'mtuc_smartucf_mutation_failed',
				__( 'Неуспешен запис на SmartUCF credentials.', 'mtunicredit' )
			);
		}
	} else {
		// ABSENT — insert only after confirmed zero rows under FOR UPDATE.
		mtuc_smartucf_wpdb_clear_last_error();
		$result = $wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => MTUC_SMARTUCF_CREDENTIALS_OPTION,
				'option_value' => $serialized,
				'autoload'     => 'no',
			),
			array( '%s', '%s', '%s' )
		);
		if ( false === $result || mtuc_smartucf_wpdb_query_failed( $result ) ) {
			return new WP_Error(
				'mtuc_smartucf_mutation_failed',
				__( 'Неуспешен запис на SmartUCF credentials.', 'mtunicredit' )
			);
		}
	}

	return true;
}

/**
 * Invalidate WP object cache for the credential option after COMMIT.
 *
 * Cache invalidation failures are logged only — DB commit remains authoritative.
 *
 * @return void
 */
function mtuc_invalidate_smartucf_credential_option_cache(): void {
	if ( function_exists( 'wp_cache_delete' ) ) {
		$ok1 = wp_cache_delete( MTUC_SMARTUCF_CREDENTIALS_OPTION, 'options' );
		$ok2 = wp_cache_delete( 'alloptions', 'options' );
		$ok3 = wp_cache_delete( 'notoptions', 'options' );
		if ( ( false === $ok1 || false === $ok2 || false === $ok3 )
			&& defined( 'WP_DEBUG' ) && WP_DEBUG
		) {
			mtuc_smartucf_mutation_log( 'option cache invalidation anomalous after COMMIT' );
		}
	}
}

/**
 * Persist encrypted credential record (compat wrapper → transactional DB helper).
 *
 * Prefer mtuc_commit_authenticated_shop_snapshot() for production mutations.
 *
 * @param array<string, mixed> $record Cipher record.
 * @return true|WP_Error
 */
function mtuc_store_smartucf_credential_record( array $record ) {
	$stored = mtuc_persist_smartucf_credential_record_db( $record );
	if ( is_wp_error( $stored ) ) {
		return $stored;
	}
	$GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] = $record;
	if ( function_exists( 'update_option' ) ) {
		update_option( MTUC_SMARTUCF_CREDENTIALS_OPTION, $record, false );
	}
	mtuc_invalidate_smartucf_credential_option_cache();
	return true;
}

/**
 * Compatibility helper — NOT part of production consistency design (AUD-WOO-018-V2).
 *
 * @param array<string, mixed>|null $prior Prior record.
 * @return void
 */
function mtuc_restore_smartucf_credential_option( ?array $prior ): void {
	if ( null === $prior ) {
		delete_option( MTUC_SMARTUCF_CREDENTIALS_OPTION );
		unset( $GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] );
		return;
	}

	mtuc_store_smartucf_credential_record( $prior );
}

/**
 * Serialization identity for credential+cache mutations.
 *
 * @param string $unicid UNICID.
 * @return string
 */
function mtuc_smartucf_mutation_lock_name( string $unicid ): string {
	$hash = substr( hash( 'sha256', trim( $unicid ) ), 0, 24 );
	return 'mtuc_sucf_cred_' . mtuc_smartucf_credentials_blog_id() . '_' . $hash;
}

/**
 * Log mutation-boundary anomalies without secrets.
 *
 * @param string $message Message.
 * @return void
 */
function mtuc_smartucf_mutation_log( string $message ): void {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'MTUC SmartUCF credential mutation: ' . $message );
	}
}

/**
 * Authoritative ROLLBACK helper.
 *
 * @return true|WP_Error true when rollback confirmed; WP_Error when transaction state is uncertain.
 */
function mtuc_smartucf_transaction_rollback() {
	global $wpdb;

	mtuc_smartucf_wpdb_clear_last_error();
	$result = $wpdb->query( 'ROLLBACK' );
	if ( mtuc_smartucf_wpdb_query_failed( $result ) ) {
		mtuc_smartucf_mutation_log( 'ROLLBACK failed — transaction state uncertain' );
		return new WP_Error(
			'mtuc_smartucf_transaction_rollback_failed',
			__( 'SmartUCF credential транзакцията не може да бъде върната надеждно.', 'mtunicredit' )
		);
	}

	return true;
}

/**
 * Build cache-lock read result.
 *
 * @param string               $state      Cache lock state.
 * @param array<string, mixed>|null $shop_data Decoded shop_data when PRESENT.
 * @param string|null          $error_code Error class.
 * @return array{state: string, shop_data: ?array, error_code: ?string}
 */
function mtuc_smartucf_cache_lock_result( string $state, ?array $shop_data = null, ?string $error_code = null ): array {
	return array(
		'state'      => $state,
		'shop_data'  => $shop_data,
		'error_code' => $error_code,
	);
}

/**
 * Re-read the shop cache row under the open mutation transaction (FOR UPDATE).
 *
 * Durable migration/cleanup MUST use this in-boundary row, never a pre-lock snapshot.
 *
 * @param string                    $unicid           Store UNICID.
 * @param array<string, mixed>|null $harness_fallback Unit-harness decoded snapshot when no cache DB.
 * @return array{state: string, shop_data: ?array, error_code: ?string}
 */
function mtuc_read_shop_cache_snapshot_for_update( string $unicid, ?array $harness_fallback = null ): array {
	if ( isset( $GLOBALS['mtuc_test_shop_cache_lock_read'] ) && is_callable( $GLOBALS['mtuc_test_shop_cache_lock_read'] )
		&& mtuc_smartucf_is_test_harness_mode()
	) {
		$result = call_user_func( $GLOBALS['mtuc_test_shop_cache_lock_read'], $unicid );
		if ( is_array( $result ) && isset( $result['state'] ) ) {
			return $result;
		}
		return mtuc_smartucf_cache_lock_result(
			MTUC_SMARTUCF_CACHE_LOCK_DB_ERROR,
			null,
			'mtuc_smartucf_database_lock_read_failed'
		);
	}

	if ( class_exists( 'Mtuc_Shop_Cache', false ) && method_exists( 'Mtuc_Shop_Cache', 'read_snapshot_for_update' ) ) {
		return Mtuc_Shop_Cache::read_snapshot_for_update( $unicid );
	}

	// Explicit test harness without shop-cache table: treat provided fallback as the locked row.
	if ( mtuc_smartucf_is_test_harness_mode() && is_array( $harness_fallback ) ) {
		return mtuc_smartucf_cache_lock_result( MTUC_SMARTUCF_CACHE_LOCK_PRESENT, $harness_fallback );
	}

	if ( ! mtuc_smartucf_is_test_harness_mode() ) {
		return mtuc_smartucf_cache_lock_result(
			MTUC_SMARTUCF_CACHE_LOCK_DB_ERROR,
			null,
			'mtuc_smartucf_database_lock_read_failed'
		);
	}

	return mtuc_smartucf_cache_lock_result( MTUC_SMARTUCF_CACHE_LOCK_ABSENT );
}

/**
 * Run a callback under GET_LOCK + START TRANSACTION on the same $wpdb connection.
 *
 * Post-COMMIT RELEASE_LOCK failures are logged only (do not fail the mutation).
 * ROLLBACK / COMMIT results are always checked. ROLLBACK failure surfaces as a
 * distinct uncertain-transaction error (does not claim prior state preserved).
 *
 * @param string   $unicid   Shop UNICID (serialization identity).
 * @param callable $callback function(): mixed Throws WP_Error via return or Exception.
 * @return mixed|WP_Error
 */
function mtuc_run_shop_credential_cache_mutation( string $unicid, callable $callback ) {
	if ( mtuc_smartucf_is_test_harness_mode()
		&& isset( $GLOBALS['mtuc_shop_mutation_boundary'] )
		&& is_callable( $GLOBALS['mtuc_shop_mutation_boundary'] )
	) {
		return call_user_func( $GLOBALS['mtuc_shop_mutation_boundary'], $unicid, $callback );
	}

	global $wpdb;

	$use_db = isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'query' );

	if ( ! $use_db ) {
		if ( ! mtuc_smartucf_is_test_harness_mode() ) {
			return new WP_Error(
				'mtuc_smartucf_mutation_failed',
				__( 'Няма жива DB връзка за SmartUCF credential mutation.', 'mtunicredit' )
			);
		}
		// Explicit test harness: exclusive static mutex (not a production path).
		static $locks = array();
		$key          = mtuc_smartucf_mutation_lock_name( $unicid );
		if ( ! empty( $locks[ $key ] ) ) {
			return new WP_Error(
				'mtuc_smartucf_mutation_lock_failed',
				__( 'Неуспешно заключване за SmartUCF credential mutation.', 'mtunicredit' )
			);
		}
		$locks[ $key ] = true;
		try {
			return $callback();
		} finally {
			unset( $locks[ $key ] );
		}
	}

	$lock_name = mtuc_smartucf_mutation_lock_name( $unicid );
	mtuc_smartucf_wpdb_clear_last_error();
	$locked    = $wpdb->get_var(
		$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, MTUC_SMARTUCF_MUTATION_LOCK_TIMEOUT )
	);

	/*
	 * Real MySQL returns 1 (acquired), 0 (timeout), or NULL (error).
	 * Process-local static lock is allowed ONLY under explicit test harness mode.
	 * Production with unavailable advisory lock / dead dbh fails closed.
	 */
	$has_live_db = mtuc_smartucf_wpdb_has_live_connection();

	if ( 1 !== (int) $locked ) {
		if ( mtuc_smartucf_is_test_harness_mode()
			&& ! $has_live_db
			&& ( null === $locked || false === $locked || '' === $locked )
		) {
			static $fallback_locks = array();
			$key = mtuc_smartucf_mutation_lock_name( $unicid );
			if ( ! empty( $fallback_locks[ $key ] ) ) {
				return new WP_Error(
					'mtuc_smartucf_mutation_lock_failed',
					__( 'Неуспешно заключване за SmartUCF credential mutation.', 'mtunicredit' )
				);
			}
			$fallback_locks[ $key ] = true;
			try {
				return $callback();
			} finally {
				unset( $fallback_locks[ $key ] );
			}
		}

		return new WP_Error(
			'mtuc_smartucf_mutation_lock_failed',
			__( 'Неуспешно заключване за SmartUCF credential mutation.', 'mtunicredit' )
		);
	}

	$committed = false;
	try {
		mtuc_smartucf_wpdb_clear_last_error();
		$started = $wpdb->query( 'START TRANSACTION' );
		if ( mtuc_smartucf_wpdb_query_failed( $started ) ) {
			return new WP_Error(
				'mtuc_smartucf_mutation_failed',
				__( 'Неуспешен START TRANSACTION за SmartUCF credentials.', 'mtunicredit' )
			);
		}

		try {
			$result = $callback();
			if ( is_wp_error( $result ) ) {
				$rolled = mtuc_smartucf_transaction_rollback();
				if ( is_wp_error( $rolled ) ) {
					return $rolled;
				}
				return $result;
			}

			mtuc_smartucf_wpdb_clear_last_error();
			$commit = $wpdb->query( 'COMMIT' );
			if ( mtuc_smartucf_wpdb_query_failed( $commit ) ) {
				$rolled = mtuc_smartucf_transaction_rollback();
				if ( is_wp_error( $rolled ) ) {
					return $rolled;
				}
				return new WP_Error(
					'mtuc_smartucf_mutation_failed',
					__( 'Неуспешен COMMIT за SmartUCF credentials.', 'mtunicredit' )
				);
			}
			$committed = true;
			mtuc_invalidate_smartucf_credential_option_cache();

			return $result;
		} catch ( Throwable $e ) {
			$rolled = mtuc_smartucf_transaction_rollback();
			mtuc_smartucf_mutation_log( 'mutation threw: ' . $e->getMessage() );
			if ( is_wp_error( $rolled ) ) {
				return $rolled;
			}
			return new WP_Error(
				'mtuc_smartucf_mutation_failed',
				__( 'SmartUCF credential mutation се провали.', 'mtunicredit' )
			);
		}
	} finally {
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		if ( 1 !== (int) $released ) {
			mtuc_smartucf_mutation_log(
				'RELEASE_LOCK anomalous'
				. ( $committed ? ' after successful COMMIT' : '' )
				. ' result=' . var_export( $released, true )
			);
		}
	}
}

/**
 * Sanitize a snapshot for general cache persistence.
 *
 * @param array<string, mixed> $data Raw or partial snapshot.
 * @return array<string, mixed>
 */
function mtuc_sanitize_shop_snapshot_for_cache( array $data ): array {
	$stripped = mtuc_strip_smartucf_credentials_from_snapshot( $data );
	if ( function_exists( 'mtuc_strip_shop_snapshot_secrets' ) ) {
		$stripped = mtuc_strip_shop_snapshot_secrets( $stripped );
	}
	if ( ! is_array( $stripped ) ) {
		$stripped = array();
	}
	if ( function_exists( 'mtuc_normalize_shop_snapshot_public_fields' ) ) {
		$stripped = mtuc_normalize_shop_snapshot_public_fields( $stripped );
	}

	return $stripped;
}

/**
 * Atomic COMPLETE/ABSENT commit of credentials + sanitized cache.
 *
 * @param string               $unicid       Authenticated UNICID.
 * @param array<string, mixed> $data         Ingress snapshot.
 * @param callable|null        $cache_writer function(string $unicid, array $snapshot): true|WP_Error|bool
 * @return array<string, mixed>|WP_Error Sanitized snapshot on success.
 */
function mtuc_commit_authenticated_shop_snapshot( string $unicid, array $data, $cache_writer = null ) {
	$unicid = trim( $unicid );
	if ( '' === $unicid ) {
		return new WP_Error(
			'mtuc_cache_no_unicid',
			__( 'Липсва unicid за обновяване на данни от банката.', 'mtunicredit' )
		);
	}

	$state = mtuc_classify_smartucf_credential_pair( $data );
	if ( MTUC_SMARTUCF_PAIR_INVALID === $state ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_invalid_pair',
			__( 'Невалиден SmartUCF credential pair в shop snapshot.', 'mtunicredit' ),
			array(
				'status' => 422,
				'error'  => 'incomplete_credential_pair',
			)
		);
	}

	$encrypted = null;
	if ( MTUC_SMARTUCF_PAIR_COMPLETE === $state ) {
		$encrypted = mtuc_encrypt_smartucf_credential_pair(
			(string) $data['uni_user'],
			(string) $data['uni_password'],
			$unicid
		);
		if ( is_wp_error( $encrypted ) ) {
			return $encrypted;
		}
	}

	$sanitized = mtuc_sanitize_shop_snapshot_for_cache( $data );

	return mtuc_run_shop_credential_cache_mutation(
		$unicid,
		function () use ( $unicid, $state, $encrypted, $sanitized, $cache_writer ) {
			/*
			 * Re-read authoritative credential state inside the boundary so a
			 * stale ABSENT never "preserves" a generation decided before the lock.
			 */
			if ( MTUC_SMARTUCF_PAIR_COMPLETE === $state ) {
				$stored = mtuc_persist_smartucf_credential_record_db( $encrypted );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
				// Keep request-local option mirror coherent for tests / get_option.
				$GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] = $encrypted;
			}

			if ( null !== $cache_writer ) {
				$written = call_user_func( $cache_writer, $unicid, $sanitized );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
				if ( false === $written ) {
					return new WP_Error(
						'mtuc_cache_save_failed',
						__( 'Кешът не може да бъде записан.', 'mtunicredit' )
					);
				}
			}

			return $sanitized;
		}
	);
}

/**
 * Legacy ingest wrapper used by prepare — delegates to transactional commit.
 *
 * @param mixed  $data   Ingress shop data.
 * @param string $unicid Authenticated UNICID.
 * @return array{state: string, snapshot: array<string, mixed>, rotated: bool, prior: ?array}|WP_Error
 */
function mtuc_ingest_shop_snapshot_credentials( $data, string $unicid ) {
	if ( ! is_array( $data ) ) {
		return new WP_Error(
			'mtuc_shop_snapshot_invalid',
			__( 'КП върна невалиден shop snapshot.', 'mtunicredit' ),
			array(
				'status'     => 422,
				'error'      => 'shop_snapshot_invalid',
				'violations' => array( 'data_not_object' ),
			)
		);
	}

	$state    = mtuc_classify_smartucf_credential_pair( $data );
	$snapshot = mtuc_commit_authenticated_shop_snapshot( $unicid, $data, null );
	if ( is_wp_error( $snapshot ) ) {
		return $snapshot;
	}

	return array(
		'state'    => $state,
		'snapshot' => $snapshot,
		'rotated'  => MTUC_SMARTUCF_PAIR_COMPLETE === $state,
		'prior'    => null,
	);
}

/**
 * Load and decrypt the dedicated pair for the given UNICID.
 *
 * @param string|null $unicid Expected UNICID (defaults to settings).
 * @return array{uni_user: string, uni_password: string}|WP_Error
 */
function mtuc_load_smartucf_credential_pair( ?string $unicid = null ) {
	if ( null === $unicid || '' === trim( $unicid ) ) {
		$unicid = class_exists( 'Mtuc_Settings', false )
			? (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID )
			: '';
	}
	$unicid = trim( (string) $unicid );

	$read = mtuc_smartucf_credential_option_read_result( false );
	if ( MTUC_SMARTUCF_OPTION_DB_ERROR === $read['state'] ) {
		return new WP_Error(
			'mtuc_smartucf_database_read_failed',
			__( 'Неуспешен прочит на локални SmartUCF credentials.', 'mtunicredit' )
		);
	}
	if ( MTUC_SMARTUCF_OPTION_PRESENT_INVALID === $read['state'] ) {
		return new WP_Error(
			'mtuc_smartucf_credential_record_invalid',
			__( 'Локалният SmartUCF credential запис е невалиден.', 'mtunicredit' )
		);
	}
	if ( MTUC_SMARTUCF_OPTION_ABSENT === $read['state'] || ! is_array( $read['value'] ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'Липсват локални SmartUCF credentials.', 'mtunicredit' )
		);
	}

	return mtuc_decrypt_smartucf_credential_record( $read['value'], '' !== $unicid ? $unicid : null );
}

/**
 * Inject decrypted credentials into a request-local shop array. Never persists.
 *
 * @param array<string, mixed> $shop   Credential-free shop snapshot.
 * @param string|null          $unicid Expected UNICID.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_hydrate_smartucf_shop_credentials( array $shop, ?string $unicid = null ) {
	$shop = mtuc_strip_smartucf_credentials_from_snapshot( $shop );
	$pair = mtuc_load_smartucf_credential_pair( $unicid );
	if ( is_wp_error( $pair ) ) {
		return $pair;
	}

	$shop['uni_user']     = $pair['uni_user'];
	$shop['uni_password'] = $pair['uni_password'];

	return $shop;
}

/**
 * Fail-closed pre-send credential gate (before claim / network).
 *
 * @param array<string, mixed> $shop   Shop context (hydrated in place on success).
 * @param string|null          $unicid UNICID.
 * @return true|WP_Error
 */
function mtuc_require_smartucf_credentials_for_send( array &$shop, ?string $unicid = null ) {
	$hydrated = mtuc_hydrate_smartucf_shop_credentials( $shop, $unicid );
	if ( is_wp_error( $hydrated ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'SmartUCF credentials не са налични за изпращане.', 'mtunicredit' ),
			array(
				'status' => 503,
				'cause'  => $hydrated->get_error_code(),
			)
		);
	}

	$user = $hydrated['uni_user'] ?? null;
	$pass = $hydrated['uni_password'] ?? null;
	if ( ! is_string( $user ) || ! is_string( $pass ) || '' === trim( $user ) || '' === trim( $pass ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'SmartUCF credentials не са налични за изпращане.', 'mtunicredit' )
		);
	}

	$shop = $hydrated;
	return true;
}

/**
 * Lazy migration of legacy plaintext credentials still sitting in shop_data.
 *
 * Dedicated record is always authoritative when present (even if unreadable).
 * Durable migration/cleanup decisions use only in-transaction re-reads under the
 * shared advisory lock — never a pre-lock decoded snapshot.
 *
 * PRESENT_INVALID dedicated behavior: fail closed; never activate plaintext;
 * never reconstruct the credential record from legacy. Cache may be sanitized
 * under lock from the CURRENT locked cache row only.
 *
 * @param array<string, mixed> $data   Decoded shop_data (pre-lock view; not durable authority).
 * @param string               $unicid Cache row UNICID.
 * @return array{data: array<string, mixed>, rewritten: bool, fail_closed?: bool}
 */
function mtuc_maybe_migrate_legacy_shop_credentials( array $data, string $unicid ): array {
	$runtime_clean = mtuc_sanitize_shop_snapshot_for_cache( $data );

	$pre = mtuc_smartucf_credential_option_read_result( false );

	// Case C — dedicated DB_ERROR: abort; no durable mutation.
	if ( MTUC_SMARTUCF_OPTION_DB_ERROR === $pre['state'] ) {
		mtuc_smartucf_mutation_log( 'dedicated credential DB_ERROR; migration aborted (fail closed)' );
		return array(
			'data'        => $runtime_clean,
			'rewritten'   => false,
			'fail_closed' => true,
		);
	}

	$writer = null;
	if ( class_exists( 'Mtuc_Shop_Cache', false ) && method_exists( 'Mtuc_Shop_Cache', 'persist_sanitized_snapshot' ) ) {
		$writer = array( 'Mtuc_Shop_Cache', 'persist_sanitized_snapshot' );
	}

	// Case B — PRESENT_INVALID: fail closed; optional cache sanitize only.
	if ( MTUC_SMARTUCF_OPTION_PRESENT_INVALID === $pre['state'] ) {
		mtuc_smartucf_mutation_log( 'dedicated credential PRESENT_INVALID; legacy ignored (fail closed)' );
		$rewritten = false;
		if ( mtuc_shop_snapshot_contains_credential_material( $data ) && null !== $writer ) {
			$committed = mtuc_run_shop_credential_cache_mutation(
				$unicid,
				static function () use ( $unicid, $writer, $data ) {
					$cred = mtuc_smartucf_credential_option_read_result( true );
					if ( MTUC_SMARTUCF_OPTION_DB_ERROR === $cred['state'] ) {
						return new WP_Error(
							'mtuc_smartucf_database_lock_read_failed',
							__( 'Неуспешен заключващ прочит на SmartUCF credentials.', 'mtunicredit' )
						);
					}
					if ( MTUC_SMARTUCF_OPTION_PRESENT_INVALID !== $cred['state']
						&& MTUC_SMARTUCF_OPTION_PRESENT_VALID !== $cred['state']
					) {
						// Raced to ABSENT — do not migrate from legacy here.
						return true;
					}
					$cache = mtuc_read_shop_cache_snapshot_for_update( $unicid, $data );
					if ( MTUC_SMARTUCF_CACHE_LOCK_DB_ERROR === $cache['state']
						|| MTUC_SMARTUCF_CACHE_LOCK_AMBIGUOUS === $cache['state']
					) {
						return new WP_Error(
							'mtuc_smartucf_database_lock_read_failed',
							__( 'Неуспешен заключващ прочит на shop cache.', 'mtunicredit' )
						);
					}
					if ( MTUC_SMARTUCF_CACHE_LOCK_ABSENT === $cache['state'] || ! is_array( $cache['shop_data'] ) ) {
						return true;
					}
					$sanitized = mtuc_sanitize_shop_snapshot_for_cache( $cache['shop_data'] );
					$written   = call_user_func( $writer, $unicid, $sanitized );
					if ( is_wp_error( $written ) || false === $written ) {
						return is_wp_error( $written )
							? $written
							: new WP_Error( 'mtuc_cache_save_failed', 'cache' );
					}
					return true;
				}
			);
			$rewritten = ! is_wp_error( $committed );
		}

		return array(
			'data'        => $runtime_clean,
			'rewritten'   => $rewritten,
			'fail_closed' => true,
		);
	}

	// Case A — PRESENT_VALID: dedicated authoritative; sanitize CURRENT locked cache only.
	if ( MTUC_SMARTUCF_OPTION_PRESENT_VALID === $pre['state'] ) {
		$existing = $pre['value'];
		$readable = is_array( $existing )
			? mtuc_decrypt_smartucf_credential_record( $existing, $unicid )
			: new WP_Error( 'mtuc_smartucf_credentials_unavailable', 'missing' );

		if ( is_wp_error( $readable ) ) {
			mtuc_smartucf_mutation_log( 'dedicated credential record unreadable; legacy ignored (fail closed)' );
			return array(
				'data'        => $runtime_clean,
				'rewritten'   => false,
				'fail_closed' => true,
			);
		}

		if ( mtuc_shop_snapshot_contains_credential_material( $data ) ) {
			$committed = mtuc_run_shop_credential_cache_mutation(
				$unicid,
				static function () use ( $unicid, $writer, $data ) {
					$cred = mtuc_smartucf_credential_option_read_result( true );
					if ( MTUC_SMARTUCF_OPTION_DB_ERROR === $cred['state'] ) {
						return new WP_Error(
							'mtuc_smartucf_database_lock_read_failed',
							__( 'Неуспешен заключващ прочит на SmartUCF credentials.', 'mtunicredit' )
						);
					}
					if ( MTUC_SMARTUCF_OPTION_PRESENT_VALID !== $cred['state'] ) {
						// Authority changed under lock — abort cleanup mutation.
						return true;
					}
					$cache = mtuc_read_shop_cache_snapshot_for_update( $unicid, $data );
					if ( MTUC_SMARTUCF_CACHE_LOCK_DB_ERROR === $cache['state']
						|| MTUC_SMARTUCF_CACHE_LOCK_AMBIGUOUS === $cache['state']
					) {
						return new WP_Error(
							'mtuc_smartucf_database_lock_read_failed',
							__( 'Неуспешен заключващ прочит на shop cache.', 'mtunicredit' )
						);
					}
					if ( MTUC_SMARTUCF_CACHE_LOCK_ABSENT === $cache['state'] || ! is_array( $cache['shop_data'] ) ) {
						return true;
					}
					$sanitized = mtuc_sanitize_shop_snapshot_for_cache( $cache['shop_data'] );
					if ( null === $writer ) {
						return true;
					}
					$written = call_user_func( $writer, $unicid, $sanitized );
					if ( is_wp_error( $written ) || false === $written ) {
						return is_wp_error( $written )
							? $written
							: new WP_Error( 'mtuc_cache_save_failed', 'cache' );
					}
					return true;
				}
			);
			return array(
				'data'      => $runtime_clean,
				'rewritten' => ! is_wp_error( $committed ),
			);
		}

		return array(
			'data'      => $runtime_clean,
			'rewritten' => false,
		);
	}

	// Case D — dedicated ABSENT: migrate/cleanup from CURRENT locked cache row only.
	$pre_state = mtuc_classify_smartucf_credential_pair( $data );
	$needs_boundary = MTUC_SMARTUCF_PAIR_COMPLETE === $pre_state
		|| MTUC_SMARTUCF_PAIR_INVALID === $pre_state
		|| mtuc_shop_snapshot_contains_credential_material( $data );

	if ( ! $needs_boundary ) {
		return array(
			'data'      => $runtime_clean,
			'rewritten' => false,
		);
	}

	$committed = mtuc_run_shop_credential_cache_mutation(
		$unicid,
		static function () use ( $unicid, $writer, $data ) {
			$cred = mtuc_smartucf_credential_option_read_result( true );
			if ( MTUC_SMARTUCF_OPTION_DB_ERROR === $cred['state'] ) {
				return new WP_Error(
					'mtuc_smartucf_database_lock_read_failed',
					__( 'Неуспешен заключващ прочит на SmartUCF credentials.', 'mtunicredit' )
				);
			}
			if ( MTUC_SMARTUCF_OPTION_PRESENT_INVALID === $cred['state'] ) {
				return new WP_Error(
					'mtuc_smartucf_credential_record_invalid',
					__( 'Съществуващият SmartUCF credential запис е невалиден.', 'mtunicredit' )
				);
			}

			$cache = mtuc_read_shop_cache_snapshot_for_update( $unicid, $data );
			if ( MTUC_SMARTUCF_CACHE_LOCK_DB_ERROR === $cache['state']
				|| MTUC_SMARTUCF_CACHE_LOCK_AMBIGUOUS === $cache['state']
			) {
				return new WP_Error(
					'mtuc_smartucf_database_lock_read_failed',
					__( 'Неуспешен заключващ прочит на shop cache.', 'mtunicredit' )
				);
			}

			$current = ( MTUC_SMARTUCF_CACHE_LOCK_PRESENT === $cache['state'] && is_array( $cache['shop_data'] ) )
				? $cache['shop_data']
				: array();
			$sanitized = mtuc_sanitize_shop_snapshot_for_cache( $current );
			$state     = mtuc_classify_smartucf_credential_pair( $current );

			// Concurrent COMPLETE pushed a dedicated record while we waited.
			if ( MTUC_SMARTUCF_OPTION_PRESENT_VALID === $cred['state'] ) {
				if ( null !== $writer && mtuc_shop_snapshot_contains_credential_material( $current ) ) {
					$written = call_user_func( $writer, $unicid, $sanitized );
					if ( is_wp_error( $written ) || false === $written ) {
						return is_wp_error( $written )
							? $written
							: new WP_Error( 'mtuc_cache_save_failed', __( 'Кешът не може да бъде записан.', 'mtunicredit' ) );
					}
				}
				return true;
			}

			if ( MTUC_SMARTUCF_PAIR_COMPLETE === $state ) {
				$encrypted = mtuc_encrypt_smartucf_credential_pair(
					(string) $current['uni_user'],
					(string) $current['uni_password'],
					$unicid
				);
				if ( is_wp_error( $encrypted ) ) {
					return $encrypted;
				}
				$stored = mtuc_persist_smartucf_credential_record_db( $encrypted );
				if ( is_wp_error( $stored ) ) {
					return $stored;
				}
				$GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] = $encrypted;
			}

			if ( null !== $writer
				&& ( MTUC_SMARTUCF_PAIR_COMPLETE === $state
					|| MTUC_SMARTUCF_PAIR_INVALID === $state
					|| mtuc_shop_snapshot_contains_credential_material( $current ) )
			) {
				$written = call_user_func( $writer, $unicid, $sanitized );
				if ( is_wp_error( $written ) ) {
					return $written;
				}
				if ( false === $written ) {
					return new WP_Error( 'mtuc_cache_save_failed', __( 'Кешът не може да бъде записан.', 'mtunicredit' ) );
				}
			}

			return true;
		}
	);

	if ( is_wp_error( $committed ) ) {
		// ROLLBACK left durable legacy row unchanged when rollback succeeded;
		// rollback failure is a distinct error — still never return plaintext.
		return array(
			'data'      => $runtime_clean,
			'rewritten' => false,
		);
	}

	return array(
		'data'      => $runtime_clean,
		'rewritten' => true,
	);
}

/**
 * Whether a snapshot tree still carries credential material.
 *
 * @param mixed $value Node.
 * @return bool
 */
function mtuc_shop_snapshot_contains_credential_material( $value ): bool {
	if ( ! is_array( $value ) ) {
		return false;
	}

	foreach ( $value as $key => $item ) {
		if ( is_string( $key ) && mtuc_is_smartucf_credential_alias_key( $key ) ) {
			return true;
		}
		if ( is_array( $item ) && mtuc_shop_snapshot_contains_credential_material( $item ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Uninstall cleanup for credential options (current site/blog only).
 *
 * @return void
 */
function mtuc_uninstall_smartucf_credentials(): void {
	delete_option( MTUC_SMARTUCF_CREDENTIALS_OPTION );
	delete_option( MTUC_SMARTUCF_CREDENTIALS_LEGACY_OPTION );
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	/**
	 * @param mixed $data Data.
	 * @return mixed
	 */
	function maybe_serialize( $data ) {
		if ( is_array( $data ) || is_object( $data ) ) {
			return serialize( $data );
		}
		return $data;
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	/**
	 * @param mixed $data Data.
	 * @return mixed
	 */
	function maybe_unserialize( $data ) {
		if ( ! is_string( $data ) ) {
			return $data;
		}
		$un = @unserialize( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		return false === $un && 'b:0;' !== $data ? $data : $un;
	}
}

/**
 * Validate payload SmartUCF credentials without casting non-strings.
 *
 * @param array<string, mixed> $payload Session payload.
 * @return true|WP_Error
 */
function mtuc_smartucf_payload_credentials_are_strings( array $payload ) {
	if ( ! array_key_exists( 'user', $payload ) || ! array_key_exists( 'pass', $payload ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'SmartUCF credentials не са налични за изпращане.', 'mtunicredit' )
		);
	}

	$user = $payload['user'];
	$pass = $payload['pass'];
	if ( ! is_string( $user ) || ! is_string( $pass ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'SmartUCF credentials не са налични за изпращане.', 'mtunicredit' )
		);
	}

	if ( '' === trim( $user ) || '' === trim( $pass ) ) {
		return new WP_Error(
			'mtuc_smartucf_credentials_unavailable',
			__( 'SmartUCF credentials не са налични за изпращане.', 'mtunicredit' )
		);
	}

	return true;
}
