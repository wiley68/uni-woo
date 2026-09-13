<?php
/**
 * AUD-WOO-018 / V4 SmartUCF credential hardening tests (Woo plugin).
 *
 * Run: php8.1 tests/run-smartucf-credential-hardening-tests.php
 *
 * @package MTUC
 */

define( 'MTUC_TEST_USE_REAL_DEBUG_LOG', true );

require_once __DIR__ . '/bootstrap.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-settings.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-debug-log.php';

$mtuc_ch_assert_count = 0;

/**
 * @param bool   $ok  Condition.
 * @param string $msg Failure message.
 * @return void
 */
function mtuc_ch_assert( bool $ok, string $msg ): void {
	global $mtuc_ch_assert_count;
	++$mtuc_ch_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $msg . PHP_EOL );
		exit( 1 );
	}
}

/**
 * Reset option bag, blog identity, and injectable mutation boundary.
 *
 * @return void
 */
function mtuc_ch_reset(): void {
	$GLOBALS['mtuc_test_options'] = array();
	$GLOBALS['mtuc_test_blog_id']  = 1;
	unset(
		$GLOBALS['mtuc_shop_mutation_boundary'],
		$GLOBALS['mtuc_smartucf_test_harness_override'],
		$GLOBALS['mtuc_test_authoritative_wpdb'],
		$GLOBALS['mtuc_test_shop_cache_lock_read']
	);
}

/**
 * In-memory mutation boundary that snapshots options and rolls back on error/throw.
 *
 * Simulates DB ROLLBACK without relying on mtuc_restore_* (compat-only).
 *
 * @return void
 */
function mtuc_ch_install_rollback_boundary(): void {
	$GLOBALS['mtuc_shop_mutation_boundary'] = static function ( $unicid, $callback ) {
		unset( $unicid );
		$prior = $GLOBALS['mtuc_test_options'];
		try {
			$result = call_user_func( $callback );
			if ( is_wp_error( $result ) ) {
				$GLOBALS['mtuc_test_options'] = $prior;
				return $result;
			}
			return $result;
		} catch ( Throwable $e ) {
			$GLOBALS['mtuc_test_options'] = $prior;
			return new WP_Error(
				'mtuc_smartucf_mutation_txn_failed',
				$e->getMessage()
			);
		}
	};
}

/**
 * Minimal non-empty shop payload (credential-free).
 *
 * @param array<string, mixed> $extra Extra fields.
 * @return array<string, mixed>
 */
function mtuc_ch_shop( array $extra = array() ): array {
	return array_merge(
		array(
			'uni_proces' => 1,
			'shop_name'  => 'AUD018-Shop',
		),
		$extra
	);
}

/**
 * Endpoint-capable shop stub for start_session pre-send checks.
 *
 * @return array<string, mixed>
 */
function mtuc_ch_endpoint_shop(): array {
	return array(
		'uni_env'                     => 0,
		'uni_sertificat'              => 0,
		'uni_test_service'            => Mtuc_Smartucf_Endpoint_Policy::SERVICE_TEST,
		'uni_production_service'      => Mtuc_Smartucf_Endpoint_Policy::SERVICE_PRODUCTION,
		'uni_test_application'        => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_TEST,
		'uni_production_application'  => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_PRODUCTION,
	);
}

/**
 * @param string $unicid UNICID binding.
 * @param string $user   Username.
 * @param string $pass   Password.
 * @return array<string, mixed>
 */
function mtuc_ch_store_pair( string $unicid, string $user, string $pass ): array {
	$record = mtuc_encrypt_smartucf_credential_pair( $user, $pass, $unicid );
	mtuc_ch_assert( is_array( $record ), 'helper encrypt returns record' );
	$stored = mtuc_store_smartucf_credential_record( $record );
	mtuc_ch_assert( true === $stored, 'helper store succeeds' );
	return $record;
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return (string) preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal order stub for taxonomy assertions.
	 */
	class WC_Order {
		/** @var int */
		public $id = 1;
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
		 * @param string $key   Meta key.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
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

if ( ! defined( 'MTUC_ORDER_META_BANK_STATUS' ) ) {
	define( 'MTUC_ORDER_META_BANK_STATUS', '_mtuc_bank_status' );
}
if ( ! defined( 'MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE' ) ) {
	define( 'MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE', '_mtuc_bank_unavailable_notice' );
}
if ( ! defined( 'MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF' ) ) {
	define( 'MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF', 'bank_send_failed_smartucf' );
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-lifecycle.php';

if ( ! function_exists( 'mtuc_fail_order_on_smartucf_error' ) ) {
	/**
	 * @param WC_Order        $order           Order.
	 * @param WP_Error|string $error_or_reason Error.
	 * @param string          $error_code      Code.
	 * @return void
	 */
	function mtuc_fail_order_on_smartucf_error( WC_Order $order, $error_or_reason = '', string $error_code = '' ): void {
		unset( $error_or_reason, $error_code );
		$order->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF );
		$order->save();
	}
}

$unicid = 'AUD018-UNICID-SHOP';
$user_a = 'smartucf_user_alpha';
$pass_a = 'smartucf_pass_Alpha!1';
$user_b = 'smartucf_user_beta';
$pass_b = 'smartucf_pass_Beta!2';

// ---------------------------------------------------------------------------
// 1–7 Classification COMPLETE / ABSENT / INVALID
// ---------------------------------------------------------------------------

mtuc_ch_reset();

mtuc_ch_assert(
	MTUC_SMARTUCF_PAIR_COMPLETE === mtuc_classify_smartucf_credential_pair(
		array(
			'uni_user'     => $user_a,
			'uni_password' => $pass_a,
		)
	),
	'1: COMPLETE both non-empty strings'
);

mtuc_ch_assert(
	MTUC_SMARTUCF_PAIR_ABSENT === mtuc_classify_smartucf_credential_pair(
		array(
			'uni_proces' => 1,
			'shop_name'  => 'x',
		)
	),
	'2: ABSENT when both credential keys omitted'
);

mtuc_ch_assert(
	MTUC_SMARTUCF_PAIR_INVALID === mtuc_classify_smartucf_credential_pair(
		array(
			'uni_user'     => '   ',
			'uni_password' => $pass_a,
		)
	),
	'3: INVALID empty/whitespace user'
);

mtuc_ch_assert(
	MTUC_SMARTUCF_PAIR_INVALID === mtuc_classify_smartucf_credential_pair(
		array(
			'uni_user'     => $user_a,
			'uni_password' => '',
		)
	),
	'4: INVALID empty password'
);

mtuc_ch_assert(
	MTUC_SMARTUCF_PAIR_INVALID === mtuc_classify_smartucf_credential_pair(
		array(
			'uni_user'     => array( 'x' ),
			'uni_password' => $pass_a,
		)
	),
	'5: INVALID non-string user'
);

mtuc_ch_assert(
	MTUC_SMARTUCF_PAIR_INVALID === mtuc_classify_smartucf_credential_pair(
		array(
			'uni_user'     => $user_a,
			'uni_password' => 12345,
		)
	),
	'6: INVALID non-string password'
);

mtuc_ch_assert(
	MTUC_SMARTUCF_PAIR_INVALID === mtuc_classify_smartucf_credential_pair(
		array(
			'uni_user'     => $user_a,
			'uni_password' => $pass_a,
			'UniUser'      => 'alias-only-extra',
		)
	),
	'7a: INVALID mixed canonical + alias'
);

mtuc_ch_assert(
	MTUC_SMARTUCF_PAIR_INVALID === mtuc_classify_smartucf_credential_pair(
		array(
			'uni_user' => $user_a,
		)
	),
	'7b: INVALID one-sided pair'
);

// INVALID mutates neither store nor via prepare (rejects).
mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$prior_invalid = mtuc_get_raw_smartucf_credential_option();
$invalid_prep  = mtuc_prepare_shop_snapshot(
	mtuc_ch_shop(
		array(
			'uni_user' => $user_a,
			// missing uni_password → INVALID
		)
	),
	$unicid
);
mtuc_ch_assert( is_wp_error( $invalid_prep ), '7c: prepare rejects INVALID pair' );
mtuc_ch_assert(
	'mtuc_smartucf_credentials_invalid_pair' === $invalid_prep->get_error_code(),
	'7d: prepare INVALID error code'
);
mtuc_ch_assert(
	$prior_invalid === mtuc_get_raw_smartucf_credential_option(),
	'7e: INVALID prepare mutates neither credential store'
);

// ---------------------------------------------------------------------------
// 8–18 Encrypt / store / decrypt / rotation / tamper / scope / IKM / AAD
// ---------------------------------------------------------------------------

mtuc_ch_reset();
$prepared = mtuc_prepare_shop_snapshot(
	mtuc_ch_shop(
		array(
			'uni_user'     => $user_a,
			'uni_password' => $pass_a,
		)
	),
	$unicid
);
mtuc_ch_assert( is_array( $prepared ), '8: COMPLETE prepare returns snapshot' );
$stored = mtuc_get_raw_smartucf_credential_option();
mtuc_ch_assert( is_array( $stored ), '9: COMPLETE writes dedicated option' );
mtuc_ch_assert(
	MTUC_SMARTUCF_CREDENTIALS_OPTION === 'mtuc_smartucf_credentials',
	'9b: credentials option constant name'
);
mtuc_ch_assert(
	'AES-256-GCM' === MTUC_SMARTUCF_CREDENTIALS_ALGORITHM
		&& MTUC_SMARTUCF_CREDENTIALS_ALGORITHM === (string) ( $stored['algorithm'] ?? '' ),
	'9c: clear algorithm label is AES-256-GCM'
);

$stored_json = (string) wp_json_encode( $stored );
mtuc_ch_assert( false === strpos( $stored_json, $user_a ), '10: no plaintext user in option JSON' );
mtuc_ch_assert( false === strpos( $stored_json, $pass_a ), '11: no plaintext password in option JSON' );
mtuc_ch_assert( ! array_key_exists( 'uni_user', $stored ), '11b: option record has no uni_user key' );
mtuc_ch_assert( ! array_key_exists( 'uni_password', $stored ), '11c: option record has no uni_password key' );

$decrypted = mtuc_decrypt_smartucf_credential_record( $stored, $unicid );
mtuc_ch_assert( is_array( $decrypted ), '12: decrypt succeeds' );
mtuc_ch_assert( $user_a === $decrypted['uni_user'], '13: decrypt user' );
mtuc_ch_assert( $pass_a === $decrypted['uni_password'], '14: decrypt password' );

// Canonical injective length-prefixed AAD (AUD-WOO-018-V3).
$expected_aad = "v1\n"
	. mtuc_smartucf_credentials_aad_field( 'version', '1' ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'algorithm', 'AES-256-GCM' ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'blog_id', '1' ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'unicid', $unicid ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'context', MTUC_SMARTUCF_CREDENTIALS_HKDF_INFO );
$actual_aad = mtuc_smartucf_credentials_canonical_aad(
	MTUC_SMARTUCF_CREDENTIALS_VERSION,
	MTUC_SMARTUCF_CREDENTIALS_ALGORITHM,
	1,
	$unicid
);
mtuc_ch_assert( $expected_aad === $actual_aad, '14b: canonical AAD length-prefixed injective format' );
$aad_from_record = mtuc_smartucf_credentials_aad_from_record( $stored );
mtuc_ch_assert( is_string( $aad_from_record ) && $expected_aad === $aad_from_record, '14c: AAD from record matches canonical' );

// Rotation replaces previous ciphertext.
$prior_rot = mtuc_get_raw_smartucf_credential_option();
$prep_rot  = mtuc_prepare_shop_snapshot(
	mtuc_ch_shop(
		array(
			'uni_user'     => $user_b,
			'uni_password' => $pass_b,
		)
	),
	$unicid
);
mtuc_ch_assert( is_array( $prep_rot ), '15: rotation prepare ok' );
$after_rot = mtuc_get_raw_smartucf_credential_option();
mtuc_ch_assert( is_array( $after_rot ), '15b: rotated option present' );
mtuc_ch_assert( $prior_rot !== $after_rot, '16: rotation replaces ciphertext record' );
$dec_rot = mtuc_decrypt_smartucf_credential_record( $after_rot, $unicid );
mtuc_ch_assert( is_array( $dec_rot ) && $user_b === $dec_rot['uni_user'] && $pass_b === $dec_rot['uni_password'], '16b: rotated pair decrypts' );

// Compat-only restore helper still works (NOT production crash-consistency path).
$before_restore = $after_rot;
$temp_record    = mtuc_encrypt_smartucf_credential_pair( 'temp_u', 'temp_p', $unicid );
mtuc_ch_assert( is_array( $temp_record ), '17a: temp encrypt ok' );
mtuc_store_smartucf_credential_record( $temp_record );
mtuc_ch_assert( $before_restore !== mtuc_get_raw_smartucf_credential_option(), '17b: temp store mutated option' );
mtuc_restore_smartucf_credential_option( $before_restore );
mtuc_ch_assert(
	$before_restore === mtuc_get_raw_smartucf_credential_option(),
	'17: compat restore prior after simulated failure'
);
$cred_src_restore = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-credentials.php' );
mtuc_ch_assert(
	false !== strpos( $cred_src_restore, 'function mtuc_commit_authenticated_shop_snapshot' )
		&& false !== strpos( $cred_src_restore, 'function mtuc_run_shop_credential_cache_mutation' ),
	'17c: commit + mutation boundary present'
);
// Production commit body must not call restore for crash consistency.
if ( preg_match(
	'/function mtuc_commit_authenticated_shop_snapshot\s*\([^)]*\)\s*\{([\s\S]*?)\nfunction /',
	$cred_src_restore,
	$commit_m
) ) {
	mtuc_ch_assert(
		false === strpos( $commit_m[1], 'mtuc_restore_smartucf_credential_option' ),
		'17d: production commit does not call restore'
	);
} else {
	mtuc_ch_assert( false, '17d: could not isolate commit function body' );
}

// Tampered ciphertext / tag fail.
$tamper_ct = $before_restore;
$raw_ct    = base64_decode( (string) $tamper_ct['ciphertext'], true );
mtuc_ch_assert( is_string( $raw_ct ) && '' !== $raw_ct, '18a: ciphertext decodable' );
$raw_ct[0] = chr( ord( $raw_ct[0] ) ^ 0xff );
$tamper_ct['ciphertext'] = base64_encode( $raw_ct );
$bad_ct = mtuc_decrypt_smartucf_credential_record( $tamper_ct, $unicid );
mtuc_ch_assert( is_wp_error( $bad_ct ), '18: tampered ciphertext fails closed' );

$tamper_tag = $before_restore;
$raw_tag    = base64_decode( (string) $tamper_tag['tag'], true );
mtuc_ch_assert( is_string( $raw_tag ) && 16 === strlen( $raw_tag ), '18b: tag decodable' );
$raw_tag[0] = chr( ord( $raw_tag[0] ) ^ 0xff );
$tamper_tag['tag'] = base64_encode( $raw_tag );
$bad_tag = mtuc_decrypt_smartucf_credential_record( $tamper_tag, $unicid );
mtuc_ch_assert( is_wp_error( $bad_tag ), '18c: tampered tag fails closed' );

// Wrong blog_id (clear scope).
$wrong_blog = $before_restore;
$wrong_blog['blog_id'] = 999;
$bad_blog = mtuc_decrypt_smartucf_credential_record( $wrong_blog, $unicid );
mtuc_ch_assert( is_wp_error( $bad_blog ), '18d: wrong blog_id fails' );
mtuc_ch_assert(
	'mtuc_smartucf_credentials_scope_mismatch' === $bad_blog->get_error_code(),
	'18e: wrong blog_id scope_mismatch'
);

// Wrong unicid.
$bad_unicid = mtuc_decrypt_smartucf_credential_record( $before_restore, 'OTHER-UNICID' );
mtuc_ch_assert( is_wp_error( $bad_unicid ), '18f: wrong unicid fails' );
mtuc_ch_assert(
	'mtuc_smartucf_credentials_scope_mismatch' === $bad_unicid->get_error_code(),
	'18g: wrong unicid scope_mismatch'
);

// Wrong IKM.
$bad_ikm = mtuc_decrypt_smartucf_credential_record( $before_restore, $unicid, 'wrong-ikm-material-not-auth-salt' );
mtuc_ch_assert( is_wp_error( $bad_ikm ), '18h: wrong IKM fails' );

// No plaintext fallback: plaintext-shaped option is not accepted.
$plaintext_fallback = array(
	'uni_user'     => $user_a,
	'uni_password' => $pass_a,
);
$no_fallback = mtuc_decrypt_smartucf_credential_record( $plaintext_fallback, $unicid );
mtuc_ch_assert( is_wp_error( $no_fallback ), '18i: no plaintext fallback on decrypt' );

// ---------------------------------------------------------------------------
// 18j–18r AAD relabel attacks (clear field substitution → decrypt MUST fail)
// ---------------------------------------------------------------------------

$aad_base = $before_restore;

// Relabel unicid clear field; pass matching expected so early scope check passes → AAD mismatch.
$relabel_unicid           = $aad_base;
$relabel_unicid['unicid'] = 'RELABELED-UNICID';
$bad_aad_unicid           = mtuc_decrypt_smartucf_credential_record( $relabel_unicid, 'RELABELED-UNICID' );
mtuc_ch_assert( is_wp_error( $bad_aad_unicid ), '18j: unicid clear relabel → decrypt fails (AAD)' );

// Relabel blog_id and align current blog so early check passes → AAD mismatch.
$relabel_blog             = $aad_base;
$relabel_blog['blog_id']  = 42;
$GLOBALS['mtuc_test_blog_id'] = 42;
$bad_aad_blog = mtuc_decrypt_smartucf_credential_record( $relabel_blog, $unicid );
mtuc_ch_assert( is_wp_error( $bad_aad_blog ), '18k: blog_id clear relabel → decrypt fails (AAD)' );
$GLOBALS['mtuc_test_blog_id'] = 1;

// Relabel version clear field.
$relabel_ver            = $aad_base;
$relabel_ver['version'] = 99;
$bad_aad_ver            = mtuc_decrypt_smartucf_credential_record( $relabel_ver, $unicid );
mtuc_ch_assert( is_wp_error( $bad_aad_ver ), '18l: version clear relabel → decrypt fails' );

// Relabel algorithm clear field (OpenSSL method string must not authenticate).
$relabel_alg              = $aad_base;
$relabel_alg['algorithm'] = 'aes-256-gcm';
$bad_aad_alg              = mtuc_decrypt_smartucf_credential_record( $relabel_alg, $unicid );
mtuc_ch_assert( is_wp_error( $bad_aad_alg ), '18m: algorithm clear relabel → decrypt fails' );

$relabel_alg2              = $aad_base;
$relabel_alg2['algorithm'] = 'AES-128-GCM';
$bad_aad_alg2              = mtuc_decrypt_smartucf_credential_record( $relabel_alg2, $unicid );
mtuc_ch_assert( is_wp_error( $bad_aad_alg2 ), '18n: wrong algorithm label → decrypt fails' );

// Combined clear-field copy attack still fails closed.
$relabel_combo              = $aad_base;
$relabel_combo['blog_id']   = 7;
$relabel_combo['unicid']    = 'ATTACK-UNICID';
$relabel_combo['version']   = 2;
$relabel_combo['algorithm'] = 'ChaCha20-Poly1305';
$GLOBALS['mtuc_test_blog_id'] = 7;
$bad_combo = mtuc_decrypt_smartucf_credential_record( $relabel_combo, 'ATTACK-UNICID' );
mtuc_ch_assert( is_wp_error( $bad_combo ), '18o: multi-field clear relabel → decrypt fails' );
$GLOBALS['mtuc_test_blog_id'] = 1;

mtuc_ch_assert(
	false !== strpos( $cred_src_restore, 'openssl_encrypt' )
		&& false !== strpos( $cred_src_restore, '$aad' ),
	'18p: encrypt uses AAD'
);
mtuc_ch_assert(
	false !== strpos( $cred_src_restore, 'openssl_decrypt' )
		&& false !== strpos( $cred_src_restore, 'mtuc_smartucf_credentials_aad_from_record' ),
	'18q: decrypt binds AAD from record'
);
mtuc_ch_assert(
	'aes-256-gcm' === MTUC_SMARTUCF_CREDENTIALS_OPENSSL_METHOD
		&& 'AES-256-GCM' === MTUC_SMARTUCF_CREDENTIALS_ALGORITHM
		&& MTUC_SMARTUCF_CREDENTIALS_OPENSSL_METHOD !== MTUC_SMARTUCF_CREDENTIALS_ALGORITHM,
	'18r: OpenSSL method ≠ clear algorithm label'
);

// ---------------------------------------------------------------------------
// 19–24 Key source: derive_key / HKDF info / source assertions
// ---------------------------------------------------------------------------

$key = mtuc_smartucf_credentials_derive_key();
mtuc_ch_assert( is_string( $key ) && 32 === strlen( $key ), '19: derive_key returns 32-byte key' );

$expected_key = hash_hkdf( 'sha256', (string) wp_salt( 'auth' ), 32, MTUC_SMARTUCF_CREDENTIALS_HKDF_INFO );
mtuc_ch_assert( is_string( $expected_key ) && hash_equals( $expected_key, $key ), '20: derive_key uses wp_salt auth via HKDF' );

mtuc_ch_assert(
	'mt_uni_credit/settings-encryption/v1' === MTUC_SMARTUCF_CREDENTIALS_HKDF_INFO,
	'21: HKDF info constant exact'
);

$cred_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-credentials.php' );
mtuc_ch_assert( false !== strpos( $cred_src, "wp_salt( 'auth' )" ), "22: source contains wp_salt('auth')" );
mtuc_ch_assert(
	false !== strpos( $cred_src, 'mt_uni_credit/settings-encryption/v1' ),
	'23: source contains HKDF info string'
);
mtuc_ch_assert(
	false === strpos( $cred_src, 'OPTION_SECRET_KEY' ),
	'24a: source does not use OPTION_SECRET_KEY as key material'
);
mtuc_ch_assert(
	1 === preg_match( "/\\bwp_salt\\(\\s*'auth'\\s*\\)/", $cred_src ),
	'24b: wp_salt auth is the IKM call site'
);
// Binding uses unicid as AAD-style scope, but IKM comment forbids unicid/token/passphrase as key material.
mtuc_ch_assert(
	false !== strpos( $cred_src, 'never UNICID / module secret / tokens' )
		|| false !== strpos( $cred_src, 'Installation-local input key material' ),
	'24c: source documents IKM is not UNICID/secret/token'
);
mtuc_ch_assert(
	0 === preg_match( '/hash_hkdf\s*\([^)]*unicid/i', $cred_src )
		&& 0 === preg_match( '/hash_hkdf\s*\([^)]*passphrase/i', $cred_src )
		&& 0 === preg_match( '/hash_hkdf\s*\([^)]*OPTION_SECRET/i', $cred_src )
		&& 0 === preg_match( '/hash_hkdf\s*\([^)]*token/i', $cred_src ),
	'24d: HKDF call does not take unicid/token/passphrase/OPTION_SECRET as IKM'
);

// ---------------------------------------------------------------------------
// 25–29 Cache credential-free after prepare; hydration does not write back; strip
// ---------------------------------------------------------------------------

mtuc_ch_reset();
$snap = mtuc_prepare_shop_snapshot(
	mtuc_ch_shop(
		array(
			'uni_user'     => $user_a,
			'uni_password' => $pass_a,
			'nested'       => array(
				'uni_password' => 'nested-leak',
				'UniUser'      => 'nested-alias',
			),
		)
	),
	$unicid
);
mtuc_ch_assert( is_array( $snap ), '25: prepare returns array' );
mtuc_ch_assert( ! array_key_exists( 'uni_user', $snap ), '26: cache snapshot has no uni_user' );
mtuc_ch_assert( ! array_key_exists( 'uni_password', $snap ), '27: cache snapshot has no uni_password' );
$snap_json = (string) wp_json_encode( $snap );
mtuc_ch_assert( false === strpos( $snap_json, $user_a ), '28: credential values absent from snapshot JSON' );
mtuc_ch_assert( false === strpos( $snap_json, $pass_a ), '28b: password absent from snapshot JSON' );
mtuc_ch_assert( false === strpos( $snap_json, 'nested-leak' ), '28c: nested password stripped' );
mtuc_ch_assert( false === strpos( $snap_json, 'nested-alias' ), '28d: nested alias stripped' );

$option_before_hydrate = mtuc_get_raw_smartucf_credential_option();
$hydrated              = mtuc_hydrate_smartucf_shop_credentials( $snap, $unicid );
mtuc_ch_assert( is_array( $hydrated ), '29a: hydrate works on credential-free shop' );
mtuc_ch_assert(
	$option_before_hydrate === mtuc_get_raw_smartucf_credential_option(),
	'29: hydration does not write back to option'
);

$dirty = array(
	'uni_user'     => 'should-strip',
	'uni_password' => 'should-strip-pass',
	'UniUser'      => 'alias',
	'keep'         => 1,
);
$stripped = mtuc_strip_smartucf_credentials_from_snapshot( $dirty );
mtuc_ch_assert( is_array( $stripped ) && 1 === (int) ( $stripped['keep'] ?? 0 ), '29b: defensive strip keeps non-cred' );
mtuc_ch_assert( ! isset( $stripped['uni_user'], $stripped['uni_password'], $stripped['UniUser'] ), '29c: defensive strip removes cred keys' );

// ---------------------------------------------------------------------------
// 30–31 P2 ABSENT (omit both keys, uni_proces=1) preserves encrypted pair
// ---------------------------------------------------------------------------

mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$prior_absent = mtuc_get_raw_smartucf_credential_option();
$absent_snap  = mtuc_prepare_shop_snapshot(
	mtuc_ch_shop(
		array(
			'uni_proces' => 1,
			// intentionally omit uni_user / uni_password
		)
	),
	$unicid
);
mtuc_ch_assert( is_array( $absent_snap ), '30: ABSENT prepare succeeds' );
mtuc_ch_assert(
	$prior_absent === mtuc_get_raw_smartucf_credential_option(),
	'31: ABSENT preserves encrypted pair byte-for-byte'
);

// ---------------------------------------------------------------------------
// 32–36 Hydrate / require_for_send / decrypt fail / scope / start_session guard
// ---------------------------------------------------------------------------

mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$shop_free = mtuc_ch_shop();
$hydrated2 = mtuc_hydrate_smartucf_shop_credentials( $shop_free, $unicid );
mtuc_ch_assert( is_array( $hydrated2 ), '32: hydrate works' );
mtuc_ch_assert( $user_a === $hydrated2['uni_user'] && $pass_a === $hydrated2['uni_password'], '32b: hydrate injects pair' );

mtuc_ch_reset();
$shop_missing = mtuc_ch_shop();
$req_missing  = mtuc_require_smartucf_credentials_for_send( $shop_missing, $unicid );
mtuc_ch_assert( is_wp_error( $req_missing ), '33: missing pair require_for_send fails' );
mtuc_ch_assert(
	'mtuc_smartucf_credentials_unavailable' === $req_missing->get_error_code(),
	'33b: require_for_send unavailable code'
);

mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$broken = mtuc_get_raw_smartucf_credential_option();
$broken['ciphertext'] = base64_encode( 'not-valid-gcm-bytes!!!!' );
update_option( MTUC_SMARTUCF_CREDENTIALS_OPTION, $broken, false );
$shop_dec = mtuc_ch_shop();
$req_dec  = mtuc_require_smartucf_credentials_for_send( $shop_dec, $unicid );
mtuc_ch_assert( is_wp_error( $req_dec ), '34: decrypt fail → require_for_send fails' );

mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$shop_scope = mtuc_ch_shop();
$req_scope  = mtuc_require_smartucf_credentials_for_send( $shop_scope, 'WRONG-SCOPE-UNICID' );
mtuc_ch_assert( is_wp_error( $req_scope ), '35: scope mismatch require_for_send fails' );

$api_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/class-mtuc-smartucf-api-client.php' );
mtuc_ch_assert(
	false !== strpos( $api_src, 'mtuc_smartucf_payload_credentials_are_strings' ),
	'36: start_session source has string-type credential guard'
);
$guard_pos = strpos( $api_src, 'mtuc_smartucf_payload_credentials_are_strings' );
$curl_pos  = strpos( $api_src, 'curl_setopt_array' );
if ( false === $curl_pos ) {
	$curl_pos = strpos( $api_src, 'curl_exec' );
}
mtuc_ch_assert(
	false !== $guard_pos && false !== $curl_pos && $guard_pos < $curl_pos,
	'36b: string-type credential guard appears before transport boundary'
);

// Live empty-credential start_session path (no network): empty user/pass.
$empty_payload = array(
	'user'    => '',
	'pass'    => '',
	'orderNo' => 1,
);
$start_empty = Mtuc_Smartucf_Api_Client::start_session( $empty_payload, mtuc_ch_endpoint_shop() );
mtuc_ch_assert( is_wp_error( $start_empty ), '36c: start_session empty credentials fails' );
mtuc_ch_assert(
	'mtuc_smartucf_credentials_unavailable' === $start_empty->get_error_code(),
	'36d: start_session empty → credentials_unavailable'
);

// ---------------------------------------------------------------------------
// 36e–36n Transport: string-type rejects (int/bool/array/object/null)
// ---------------------------------------------------------------------------

$string_ok = mtuc_smartucf_payload_credentials_are_strings(
	array(
		'user' => $user_a,
		'pass' => $pass_a,
	)
);
mtuc_ch_assert( true === $string_ok, '36e: string credentials accepted' );

$reject_cases = array(
	'int'    => array( 'user' => 12345, 'pass' => $pass_a ),
	'bool'   => array( 'user' => $user_a, 'pass' => true ),
	'array'  => array( 'user' => array( 'x' ), 'pass' => $pass_a ),
	'object' => array( 'user' => $user_a, 'pass' => (object) array( 'p' => 1 ) ),
	'null'   => array( 'user' => null, 'pass' => $pass_a ),
);
foreach ( $reject_cases as $label => $payload ) {
	$check = mtuc_smartucf_payload_credentials_are_strings( $payload );
	mtuc_ch_assert( is_wp_error( $check ), "36f: payload rejects non-string {$label}" );
	mtuc_ch_assert(
		'mtuc_smartucf_credentials_unavailable' === $check->get_error_code(),
		"36g: non-string {$label} → credentials_unavailable"
	);

	$start = Mtuc_Smartucf_Api_Client::start_session(
		array_merge( $payload, array( 'orderNo' => 1 ) ),
		mtuc_ch_endpoint_shop()
	);
	mtuc_ch_assert( is_wp_error( $start ), "36h: start_session rejects non-string {$label}" );
	mtuc_ch_assert(
		'mtuc_smartucf_credentials_unavailable' === $start->get_error_code(),
		"36i: start_session {$label} → credentials_unavailable"
	);
}

// ---------------------------------------------------------------------------
// 37–46 Status taxonomy (presend / remote / ambiguous)
// ---------------------------------------------------------------------------

mtuc_ch_assert(
	mtuc_is_smartucf_presend_error( new WP_Error( 'mtuc_smartucf_encode_failed', 'x' ) ),
	'37: encode_failed is presend'
);
mtuc_ch_assert(
	! mtuc_is_smartucf_ambiguous_error( new WP_Error( 'mtuc_smartucf_encode_failed', 'x' ) ),
	'38: encode_failed is not ambiguous'
);

$order_presend = new WC_Order();
$order_presend->id = 1801;
mtuc_handle_smartucf_start_error( $order_presend, new WP_Error( 'mtuc_smartucf_encode_failed', 'encode' ) );
mtuc_ch_assert(
	MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $order_presend->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'39: encode_failed must NOT set bank_send_failed_smartucf'
);

mtuc_ch_assert(
	mtuc_is_smartucf_definitive_remote_error( new WP_Error( 'mtuc_smartucf_remote_rejected', 'r' ) ),
	'40: remote_rejected is definitive remote'
);
$order_remote = new WC_Order();
$order_remote->id = 1802;
mtuc_handle_smartucf_start_error( $order_remote, new WP_Error( 'mtuc_smartucf_remote_rejected', 'reject' ) );
mtuc_ch_assert(
	MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF === (string) $order_remote->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'41: remote_rejected MUST set bank_send_failed_smartucf'
);

$ambiguous_codes = array(
	'mtuc_smartucf_http_error', // timeout / curl transport
	'mtuc_smartucf_invalid_json',
	'mtuc_smartucf_no_session',
);
foreach ( $ambiguous_codes as $code ) {
	$err = new WP_Error( $code, 'ambiguous' );
	mtuc_ch_assert( mtuc_is_smartucf_ambiguous_error( $err ), "42+: {$code} is ambiguous" );
	mtuc_ch_assert( ! mtuc_is_smartucf_presend_error( $err ), "42+: {$code} is not presend" );
	$o = new WC_Order();
	$o->id = 1900 + crc32( $code ) % 1000;
	mtuc_handle_smartucf_start_error( $o, $err );
	mtuc_ch_assert(
		MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF !== (string) $o->get_meta( MTUC_ORDER_META_BANK_STATUS ),
		"42+: {$code} must not set bank_send_failed_smartucf"
	);
}

// Explicit timeout label maps to http_error taxonomy in this plugin.
$timeout = new WP_Error( 'mtuc_smartucf_http_error', 'Operation timed out' );
mtuc_ch_assert( mtuc_is_smartucf_ambiguous_error( $timeout ), '46: timeout/http_error remains ambiguous' );

// ---------------------------------------------------------------------------
// 47–53 Debug redaction
// ---------------------------------------------------------------------------

$fake_u   = 'cred_harden_user_AUD018';
$fake_p   = 'cred_harden_pass_AUD018!';
$session  = 'sucf-session-visible-AUD018';
$redact_payload = array(
	'user'                => $fake_u,
	'pass'                => $fake_p,
	'uni_user'            => $fake_u,
	'uni_password'        => $fake_p,
	'UniUser'             => $fake_u,
	'UNI_PASSWORD'        => $fake_p,
	'sucfOnlineSessionID' => $session,
	'orderNo'             => '5018',
);
$req_san = Mtuc_Debug_Log::sanitize_request_for_journal( (string) wp_json_encode( $redact_payload ) );
$req_arr = json_decode( $req_san, true );
mtuc_ch_assert( is_array( $req_arr ), '47: request sanitizes to JSON' );
mtuc_ch_assert( '[REDACTED]' === ( $req_arr['user'] ?? null ), '48: user redacted' );
mtuc_ch_assert( '[REDACTED]' === ( $req_arr['pass'] ?? null ), '49: pass redacted' );
mtuc_ch_assert( '[REDACTED]' === ( $req_arr['uni_user'] ?? null ), '50: uni_user redacted' );
mtuc_ch_assert( '[REDACTED]' === ( $req_arr['uni_password'] ?? null ), '51: uni_password redacted' );
mtuc_ch_assert( '[REDACTED]' === ( $req_arr['UniUser'] ?? null ), '52: UniUser redacted' );
mtuc_ch_assert( '[REDACTED]' === ( $req_arr['UNI_PASSWORD'] ?? null ), '52b: UNI_PASSWORD redacted' );
mtuc_ch_assert( $session === ( $req_arr['sucfOnlineSessionID'] ?? null ), '53: sucfOnlineSessionID visible' );
mtuc_ch_assert( false === strpos( $req_san, $fake_u ), '53b: fake user absent from journal JSON' );
mtuc_ch_assert( false === strpos( $req_san, $fake_p ), '53c: fake pass absent from journal JSON' );

$resp_san = Mtuc_Debug_Log::sanitize_response_for_journal(
	(string) wp_json_encode(
		array(
			'sucfOnlineSessionID' => $session,
			'uni_password'        => $fake_p,
			'UNI_PASSWORD'        => $fake_p,
			'status'              => 'OK',
		)
	)
);
$resp_arr = json_decode( $resp_san, true );
mtuc_ch_assert( is_array( $resp_arr ), '53d: response sanitizes' );
mtuc_ch_assert( $session === ( $resp_arr['sucfOnlineSessionID'] ?? null ), '53e: response session visible' );
mtuc_ch_assert( '[REDACTED]' === ( $resp_arr['uni_password'] ?? null ), '53f: response uni_password redacted' );
mtuc_ch_assert( '[REDACTED]' === ( $resp_arr['UNI_PASSWORD'] ?? null ), '53g: response UNI_PASSWORD redacted' );

// ---------------------------------------------------------------------------
// 54–58 Legacy migrate COMPLETE / INVALID / ABSENT / strip
// ---------------------------------------------------------------------------

mtuc_ch_reset();
$legacy_complete = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => $user_a,
		'uni_password' => $pass_a,
		'keep'         => 'yes',
	),
	$unicid
);
mtuc_ch_assert( true === $legacy_complete['rewritten'], '54: COMPLETE legacy migrate rewrites' );
mtuc_ch_assert( ! isset( $legacy_complete['data']['uni_user'], $legacy_complete['data']['uni_password'] ), '54b: COMPLETE strips after migrate' );
$legacy_pair = mtuc_load_smartucf_credential_pair( $unicid );
mtuc_ch_assert( is_array( $legacy_pair ) && $user_a === $legacy_pair['uni_user'], '55: COMPLETE activates encrypted store' );

mtuc_ch_reset();
$legacy_invalid = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user' => $user_a,
		'keep'     => 1,
	),
	$unicid
);
mtuc_ch_assert( null === mtuc_get_raw_smartucf_credential_option(), '56: INVALID never activates store' );
mtuc_ch_assert( ! isset( $legacy_invalid['data']['uni_user'] ), '56b: INVALID strips material' );

mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$prior_legacy_absent = mtuc_get_raw_smartucf_credential_option();
$legacy_absent       = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_proces' => 1,
		'keep'       => 'z',
	),
	$unicid
);
mtuc_ch_assert(
	$prior_legacy_absent === mtuc_get_raw_smartucf_credential_option(),
	'57: ABSENT migrate preserves encrypted pair'
);
mtuc_ch_assert( 'z' === ( $legacy_absent['data']['keep'] ?? null ), '57b: ABSENT keeps non-cred fields' );

$strip_check = mtuc_strip_smartucf_credentials_from_snapshot(
	array(
		'uni_user' => 'x',
		'pass'     => 'y',
		'ok'       => true,
	)
);
mtuc_ch_assert( true === ( $strip_check['ok'] ?? false ) && ! isset( $strip_check['uni_user'], $strip_check['pass'] ), '58: strip removes credential keys' );

// ---------------------------------------------------------------------------
// 58A–58C Migration authority (dedicated exists ⇒ never overwrite from legacy)
// ---------------------------------------------------------------------------

// Case A: readable dedicated exists + different legacy plaintext → dedicated wins.
mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$prior_auth_a = mtuc_get_raw_smartucf_credential_option();
mtuc_ch_assert( true === mtuc_smartucf_credential_option_exists(), '58A0: option_exists true for readable dedicated' );
$mig_a = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => $user_b,
		'uni_password' => $pass_b,
		'keep'         => 'auth-a',
	),
	$unicid
);
mtuc_ch_assert(
	$prior_auth_a === mtuc_get_raw_smartucf_credential_option(),
	'58A1: Case A dedicated unchanged by legacy plaintext'
);
$pair_a = mtuc_load_smartucf_credential_pair( $unicid );
mtuc_ch_assert(
	is_array( $pair_a ) && $user_a === $pair_a['uni_user'] && $pass_a === $pair_a['uni_password'],
	'58A2: Case A still decrypts original pair (not legacy)'
);
mtuc_ch_assert( ! isset( $mig_a['data']['uni_user'], $mig_a['data']['uni_password'] ), '58A3: Case A runtime sanitized' );
mtuc_ch_assert( empty( $mig_a['fail_closed'] ), '58A4: Case A readable is not fail_closed' );

// Case B: unreadable dedicated + legacy COMPLETE → fail_closed, option unchanged.
mtuc_ch_reset();
$poison = array(
	'version'    => MTUC_SMARTUCF_CREDENTIALS_VERSION,
	'algorithm'  => MTUC_SMARTUCF_CREDENTIALS_ALGORITHM,
	'blog_id'    => 1,
	'unicid'     => $unicid,
	'nonce'      => base64_encode( random_bytes( 12 ) ),
	'tag'        => base64_encode( random_bytes( 16 ) ),
	'ciphertext' => base64_encode( random_bytes( 32 ) ),
);
update_option( MTUC_SMARTUCF_CREDENTIALS_OPTION, $poison, false );
mtuc_ch_assert( true === mtuc_smartucf_credential_option_exists(), '58B0: option_exists true for unreadable dedicated' );
$prior_poison = mtuc_get_raw_smartucf_credential_option();
$mig_b        = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => $user_b,
		'uni_password' => $pass_b,
		'keep'         => 'auth-b',
	),
	$unicid
);
mtuc_ch_assert( ! empty( $mig_b['fail_closed'] ), '58B1: Case B fail_closed' );
mtuc_ch_assert( false === $mig_b['rewritten'], '58B2: Case B rewritten=false' );
mtuc_ch_assert(
	$prior_poison === mtuc_get_raw_smartucf_credential_option(),
	'58B3: Case B option unchanged (no legacy overwrite)'
);
mtuc_ch_assert( ! isset( $mig_b['data']['uni_user'], $mig_b['data']['uni_password'] ), '58B4: Case B runtime sanitized' );
mtuc_ch_assert( 'auth-b' === ( $mig_b['data']['keep'] ?? null ), '58B5: Case B keeps non-cred fields' );

// Case C: no dedicated → COMPLETE legacy migrates.
mtuc_ch_reset();
mtuc_ch_assert( false === mtuc_smartucf_credential_option_exists(), '58C0: option_exists false when absent' );
$mig_c = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => $user_b,
		'uni_password' => $pass_b,
		'keep'         => 'auth-c',
	),
	$unicid
);
mtuc_ch_assert( true === $mig_c['rewritten'], '58C1: Case C migrates when no dedicated' );
$pair_c = mtuc_load_smartucf_credential_pair( $unicid );
mtuc_ch_assert(
	is_array( $pair_c ) && $user_b === $pair_c['uni_user'] && $pass_b === $pair_c['uni_password'],
	'58C2: Case C activates encrypted store from legacy'
);

// Failed migration (encrypt unavailable path simulated via empty unicid classify still COMPLETE
// but commit with empty unicid rejected) — rewritten=false + sanitized runtime.
mtuc_ch_reset();
$mig_fail = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => $user_a,
		'uni_password' => $pass_a,
		'keep'         => 'fail-path',
	),
	'' // empty unicid → encrypt fails → rewritten false
);
mtuc_ch_assert( false === $mig_fail['rewritten'], '58C3: failed migration rewritten=false' );
mtuc_ch_assert( ! isset( $mig_fail['data']['uni_user'], $mig_fail['data']['uni_password'] ), '58C4: failed migration runtime sanitized' );
mtuc_ch_assert( null === mtuc_get_raw_smartucf_credential_option(), '58C5: failed migration leaves option absent' );

// ---------------------------------------------------------------------------
// 59–62 Uninstall / version / activation without credentials
// ---------------------------------------------------------------------------

mtuc_ch_reset();
update_option( MTUC_SMARTUCF_CREDENTIALS_OPTION, array( 'version' => 1 ), false );
update_option( MTUC_SMARTUCF_CREDENTIALS_LEGACY_OPTION, array( 'uni_user' => 'legacy' ), false );
mtuc_uninstall_smartucf_credentials();
mtuc_ch_assert( false === get_option( MTUC_SMARTUCF_CREDENTIALS_OPTION, false ), '59: uninstall removes credentials option' );
mtuc_ch_assert( false === get_option( MTUC_SMARTUCF_CREDENTIALS_LEGACY_OPTION, false ), '60: uninstall removes legacy option' );

$main_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/mtunicredit.php' );
mtuc_ch_assert( false !== strpos( $main_src, 'Version:           2.0.2' ), '61: Version header 2.0.2 in mtunicredit.php' );
mtuc_ch_assert( false !== strpos( $main_src, "define( 'MTUC_VERSION', '2.0.2' )" ), '61b: MTUC_VERSION 2.0.2 defined in mtunicredit.php' );
mtuc_ch_assert(
	false !== strpos( $main_src, 'function mtuc_activate_plugin' )
		&& false === strpos( $main_src, 'mtuc_smartucf_credentials' ),
	'61c: activation source does not reference credentials option'
);

mtuc_ch_reset();
Mtuc_Settings::install_defaults();
mtuc_ch_assert(
	false === get_option( MTUC_SMARTUCF_CREDENTIALS_OPTION, false ),
	'62: activation/defaults leave credentials option absent'
);
$defaults_keys = array_keys( Mtuc_Settings::get_defaults() );
mtuc_ch_assert(
	! in_array( MTUC_SMARTUCF_CREDENTIALS_OPTION, $defaults_keys, true ),
	'62b: settings defaults do not require credentials option'
);

// ---------------------------------------------------------------------------
// 63–72 Mutation boundary: crash consistency without restore
// ---------------------------------------------------------------------------

// Fail before callback (nothing mutated).
mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$prior_boundary = mtuc_get_raw_smartucf_credential_option();
$GLOBALS['mtuc_shop_mutation_boundary'] = static function ( $u, $callback ) {
	unset( $u, $callback );
	return new WP_Error( 'mtuc_smartucf_mutation_txn_failed', 'simulated lock/txn failure before mutation' );
};
$fail_before = mtuc_commit_authenticated_shop_snapshot(
	$unicid,
	mtuc_ch_shop(
		array(
			'uni_user'     => $user_b,
			'uni_password' => $pass_b,
		)
	),
	null
);
mtuc_ch_assert( is_wp_error( $fail_before ), '63: boundary fail-before returns error' );
mtuc_ch_assert(
	$prior_boundary === mtuc_get_raw_smartucf_credential_option(),
	'64: fail-before leaves prior credentials intact'
);
unset( $GLOBALS['mtuc_shop_mutation_boundary'] );

// Credential write path failure mid-mutation → rolled back via injected boundary.
mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$prior_mid = mtuc_get_raw_smartucf_credential_option();
$GLOBALS['mtuc_shop_mutation_boundary'] = static function ( $u, $callback ) {
	unset( $u );
	$snap = $GLOBALS['mtuc_test_options'];
	try {
		// Run real callback (persists new credentials), then fail before successful commit.
		$result = call_user_func( $callback );
		if ( is_wp_error( $result ) ) {
			$GLOBALS['mtuc_test_options'] = $snap;
			return $result;
		}
		// Simulate crash after credential persist / before commit.
		$GLOBALS['mtuc_test_options'] = $snap;
		return new WP_Error( 'mtuc_smartucf_mutation_txn_failed', 'simulated mid-mutation abort' );
	} catch ( Throwable $e ) {
		$GLOBALS['mtuc_test_options'] = $snap;
		return new WP_Error( 'mtuc_smartucf_mutation_txn_failed', $e->getMessage() );
	}
};
$fail_mid = mtuc_commit_authenticated_shop_snapshot(
	$unicid,
	mtuc_ch_shop(
		array(
			'uni_user'     => $user_b,
			'uni_password' => $pass_b,
		)
	),
	static function () {
		return true;
	}
);
mtuc_ch_assert( is_wp_error( $fail_mid ), '65: mid-mutation abort returns error' );
mtuc_ch_assert(
	$prior_mid === mtuc_get_raw_smartucf_credential_option(),
	'66: mid-mutation abort restores prior state (no restore_* API)'
);
$still_a = mtuc_decrypt_smartucf_credential_record( mtuc_get_raw_smartucf_credential_option(), $unicid );
mtuc_ch_assert(
	is_array( $still_a ) && $user_a === $still_a['uni_user'],
	'66b: rolled-back pair still decrypts original'
);
unset( $GLOBALS['mtuc_shop_mutation_boundary'] );

// Cache writer failure → no credential stored (rollback harness).
mtuc_ch_reset();
mtuc_ch_install_rollback_boundary();
$cache_fail = mtuc_commit_authenticated_shop_snapshot(
	$unicid,
	mtuc_ch_shop(
		array(
			'uni_user'     => $user_a,
			'uni_password' => $pass_a,
		)
	),
	static function () {
		return new WP_Error( 'mtuc_cache_save_failed', 'cache writer boom' );
	}
);
mtuc_ch_assert( is_wp_error( $cache_fail ), '67: cache writer failure returns error' );
mtuc_ch_assert(
	'mtuc_cache_save_failed' === $cache_fail->get_error_code(),
	'67b: cache writer error code preserved'
);
mtuc_ch_assert(
	null === mtuc_get_raw_smartucf_credential_option(),
	'68: cache writer failure → no credential stored (rolled back)'
);
unset( $GLOBALS['mtuc_shop_mutation_boundary'] );

// Cache writer failure with prior pair → prior preserved.
mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$prior_cache = mtuc_get_raw_smartucf_credential_option();
mtuc_ch_install_rollback_boundary();
$cache_fail_prior = mtuc_commit_authenticated_shop_snapshot(
	$unicid,
	mtuc_ch_shop(
		array(
			'uni_user'     => $user_b,
			'uni_password' => $pass_b,
		)
	),
	static function () {
		return false;
	}
);
mtuc_ch_assert( is_wp_error( $cache_fail_prior ), '69: cache writer false → error' );
mtuc_ch_assert(
	$prior_cache === mtuc_get_raw_smartucf_credential_option(),
	'70: cache failure rolls back; prior credentials preserved'
);
unset( $GLOBALS['mtuc_shop_mutation_boundary'] );

// Exception thrown inside mutation → rolled back.
mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$prior_throw = mtuc_get_raw_smartucf_credential_option();
mtuc_ch_install_rollback_boundary();
$throw_fail = mtuc_commit_authenticated_shop_snapshot(
	$unicid,
	mtuc_ch_shop(
		array(
			'uni_user'     => $user_b,
			'uni_password' => $pass_b,
		)
	),
	static function () {
		throw new RuntimeException( 'cache writer threw' );
	}
);
mtuc_ch_assert( is_wp_error( $throw_fail ), '71: thrown cache writer → WP_Error' );
mtuc_ch_assert(
	$prior_throw === mtuc_get_raw_smartucf_credential_option(),
	'72: thrown mutation rolls back prior credentials'
);
unset( $GLOBALS['mtuc_shop_mutation_boundary'] );

// ---------------------------------------------------------------------------
// 73–76 COMPLETE then ABSENT + unreadable dedicated fail_closed (extra section)
// ---------------------------------------------------------------------------

mtuc_ch_reset();
$complete_then = mtuc_commit_authenticated_shop_snapshot(
	$unicid,
	mtuc_ch_shop(
		array(
			'uni_user'     => $user_a,
			'uni_password' => $pass_a,
		)
	),
	null
);
mtuc_ch_assert( is_array( $complete_then ), '73: COMPLETE commit ok' );
$latest = mtuc_get_raw_smartucf_credential_option();
mtuc_ch_assert( is_array( $latest ), '73b: COMPLETE stored credentials' );

$absent_then = mtuc_commit_authenticated_shop_snapshot(
	$unicid,
	mtuc_ch_shop(
		array(
			'uni_proces' => 1,
		)
	),
	null
);
mtuc_ch_assert( is_array( $absent_then ), '74: ABSENT after COMPLETE ok' );
mtuc_ch_assert(
	$latest === mtuc_get_raw_smartucf_credential_option(),
	'75: COMPLETE then ABSENT preserves latest credentials'
);
$latest_pair = mtuc_decrypt_smartucf_credential_record( mtuc_get_raw_smartucf_credential_option(), $unicid );
mtuc_ch_assert(
	is_array( $latest_pair ) && $user_a === $latest_pair['uni_user'] && $pass_a === $latest_pair['uni_password'],
	'75b: preserved pair still decrypts'
);

// Unreadable dedicated + legacy plaintext → fail_closed (reaffirm short section).
mtuc_ch_reset();
$unreadable = array(
	'version'    => MTUC_SMARTUCF_CREDENTIALS_VERSION,
	'algorithm'  => MTUC_SMARTUCF_CREDENTIALS_ALGORITHM,
	'blog_id'    => 1,
	'unicid'     => $unicid,
	'nonce'      => base64_encode( str_repeat( "\1", 12 ) ),
	'tag'        => base64_encode( str_repeat( "\2", 16 ) ),
	'ciphertext' => base64_encode( str_repeat( "\3", 24 ) ),
);
update_option( MTUC_SMARTUCF_CREDENTIALS_OPTION, $unreadable, false );
$prior_unreadable = mtuc_get_raw_smartucf_credential_option();
$fc = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => $user_b,
		'uni_password' => $pass_b,
		'shop_name'    => 'should-keep',
	),
	$unicid
);
mtuc_ch_assert( ! empty( $fc['fail_closed'] ), '76: unreadable+legacy → fail_closed' );
mtuc_ch_assert( false === $fc['rewritten'], '76b: fail_closed rewritten=false' );
mtuc_ch_assert( $prior_unreadable === mtuc_get_raw_smartucf_credential_option(), '76c: option unchanged' );
mtuc_ch_assert( ! isset( $fc['data']['uni_user'], $fc['data']['uni_password'] ), '76d: runtime sanitized' );
mtuc_ch_assert( 'should-keep' === ( $fc['data']['shop_name'] ?? null ), '76e: non-cred field preserved' );

// ---------------------------------------------------------------------------
// AUD-WOO-018-V3 — authoritative reads, AAD injectivity, migration races, txn
// ---------------------------------------------------------------------------

/**
 * Controllable authoritative $wpdb double for option/transaction tests.
 */
class Mtuc_Ch_Auth_Wpdb {
	/** @var string */
	public $options = 'wp_options';
	/** @var string */
	public $prefix = 'wp_';
	/** @var string */
	public $last_error = '';
	/** @var object */
	public $dbh;
	/** @var array<int, array{option_id:int, option_value:string}> */
	public $rows = array();
	/** @var string */
	public $select_mode = 'ok';
	/** @var string */
	public $commit_mode = 'ok';
	/** @var string */
	public $rollback_mode = 'ok';
	/** @var string */
	public $write_mode = 'ok';
	/** @var array<string, mixed> */
	public $cache_bag = array();

	public function __construct() {
		$this->dbh = new class {
			/** @return bool */
			public function query() {
				return true;
			}
		};
	}

	/**
	 * @param string $query SQL.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[sdf]/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
		}
		return $query;
	}

	/**
	 * @param string $query SQL.
	 * @param string $output Output type.
	 * @return array<int, array<string, mixed>>|false
	 */
	public function get_results( $query, $output = 'ARRAY_A' ) {
		unset( $output );
		if ( 'error' === $this->select_mode ) {
			$this->last_error = 'simulated SELECT failure';
			return false;
		}
		$this->last_error = '';
		if ( false !== strpos( (string) $query, 'wp_options' )
			&& false !== strpos( (string) $query, MTUC_SMARTUCF_CREDENTIALS_OPTION )
		) {
			return $this->rows;
		}
		return array();
	}

	/**
	 * @param string $query SQL.
	 * @return mixed
	 */
	public function get_var( $query ) {
		$query = (string) $query;
		if ( false !== strpos( $query, 'GET_LOCK' ) ) {
			return 1;
		}
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) {
			return 1;
		}
		if ( false !== strpos( $query, 'COUNT(*)' ) ) {
			if ( 'error' === $this->select_mode ) {
				$this->last_error = 'simulated COUNT failure';
				return false;
			}
			return count( $this->rows );
		}
		if ( false !== strpos( $query, 'option_value' ) ) {
			if ( 'error' === $this->select_mode ) {
				$this->last_error = 'simulated get_var failure';
				return false;
			}
			return isset( $this->rows[0]['option_value'] ) ? $this->rows[0]['option_value'] : null;
		}
		return null;
	}

	/**
	 * @param string $query SQL.
	 * @return bool|int
	 */
	public function query( $query ) {
		$query = (string) $query;
		if ( 'START TRANSACTION' === $query ) {
			$this->last_error = '';
			return 0;
		}
		if ( 'COMMIT' === $query ) {
			if ( 'fail' === $this->commit_mode ) {
				$this->last_error = 'simulated COMMIT failure';
				return false;
			}
			$this->last_error = '';
			return 0;
		}
		if ( 'ROLLBACK' === $query ) {
			if ( 'fail' === $this->rollback_mode ) {
				$this->last_error = 'simulated ROLLBACK failure';
				return false;
			}
			$this->last_error = '';
			return 0;
		}
		return 0;
	}

	/**
	 * @param string               $table Table.
	 * @param array<string, mixed> $data Data.
	 * @param array<int, string>|null $format Format.
	 * @return int|false
	 */
	public function insert( $table, $data, $format = null ) {
		unset( $table, $format );
		if ( 'fail' === $this->write_mode ) {
			$this->last_error = 'simulated INSERT failure';
			return false;
		}
		$this->last_error = '';
		$value = (string) ( $data['option_value'] ?? '' );
		$this->rows = array(
			array(
				'option_id'    => 1,
				'option_value' => $value,
			),
		);
		$decoded = maybe_unserialize( $value );
		if ( is_array( $decoded ) ) {
			$GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] = $decoded;
		}
		return 1;
	}

	/**
	 * @param string               $table Table.
	 * @param array<string, mixed> $data Data.
	 * @param array<string, mixed> $where Where.
	 * @param array<int, string>|null $format Format.
	 * @param array<int, string>|null $where_format Where format.
	 * @return int|false
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		unset( $table, $where, $format, $where_format );
		if ( 'fail' === $this->write_mode ) {
			$this->last_error = 'simulated UPDATE failure';
			return false;
		}
		$this->last_error = '';
		$value = (string) ( $data['option_value'] ?? '' );
		$this->rows = array(
			array(
				'option_id'    => 1,
				'option_value' => $value,
			),
		);
		$decoded = maybe_unserialize( $value );
		if ( is_array( $decoded ) ) {
			$GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] = $decoded;
		}
		return 1;
	}
}

/**
 * @param Mtuc_Ch_Auth_Wpdb $db Fake DB.
 * @return void
 */
function mtuc_ch_install_auth_wpdb( Mtuc_Ch_Auth_Wpdb $db ): void {
	$GLOBALS['wpdb'] = $db;
	$GLOBALS['mtuc_test_authoritative_wpdb'] = true;
}

/**
 * @return void
 */
function mtuc_ch_clear_auth_wpdb(): void {
	unset(
		$GLOBALS['wpdb'],
		$GLOBALS['mtuc_test_authoritative_wpdb'],
		$GLOBALS['mtuc_test_shop_cache_lock_read'],
		$GLOBALS['mtuc_smartucf_test_harness_override']
	);
	$GLOBALS['mtuc_test_object_cache'] = array();
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
	/**
	 * @param mixed  $key Key.
	 * @param string $group Group.
	 * @return bool
	 */
	function wp_cache_delete( $key, $group = '' ) {
		unset( $GLOBALS['mtuc_test_object_cache'][ $group . ':' . $key ] );
		return true;
	}
}

if ( ! function_exists( 'wp_cache_set' ) ) {
	/**
	 * @param mixed  $key Key.
	 * @param mixed  $data Data.
	 * @param string $group Group.
	 * @return bool
	 */
	function wp_cache_set( $key, $data, $group = '' ) {
		$GLOBALS['mtuc_test_object_cache'][ $group . ':' . $key ] = $data;
		return true;
	}
}

if ( ! function_exists( 'wp_cache_get' ) ) {
	/**
	 * @param mixed  $key Key.
	 * @param string $group Group.
	 * @return mixed
	 */
	function wp_cache_get( $key, $group = '' ) {
		$k = $group . ':' . $key;
		return array_key_exists( $k, $GLOBALS['mtuc_test_object_cache'] )
			? $GLOBALS['mtuc_test_object_cache'][ $k ]
			: false;
	}
}

// V3-1 AAD injectivity vs naive delimiter collision.
mtuc_ch_reset();
$ctx = MTUC_SMARTUCF_CREDENTIALS_HKDF_INFO;
$naive = static function ( $version, $algorithm, $blog_id, $unicid_v, $context_v ) {
	return 'version=' . $version . "\n"
		. 'algorithm=' . $algorithm . "\n"
		. 'blog_id=' . $blog_id . "\n"
		. 'unicid=' . $unicid_v . "\n"
		. 'context=' . $context_v;
};
$u1 = 'x';
$c1 = 'y' . "\n" . 'context=' . $ctx;
$u2 = 'x' . "\n" . 'context=y';
$c2 = $ctx;
mtuc_ch_assert(
	$naive( 1, 'AES-256-GCM', 1, $u1, $c1 ) === $naive( 1, 'AES-256-GCM', 1, $u2, $c2 ),
	'V3-1a: naive delimiter AAD collides for crafted unicid/context'
);
$aad1 = "v1\n"
	. mtuc_smartucf_credentials_aad_field( 'version', '1' ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'algorithm', 'AES-256-GCM' ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'blog_id', '1' ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'unicid', $u1 ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'context', $c1 );
$aad2 = "v1\n"
	. mtuc_smartucf_credentials_aad_field( 'version', '1' ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'algorithm', 'AES-256-GCM' ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'blog_id', '1' ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'unicid', $u2 ) . "\n"
	. mtuc_smartucf_credentials_aad_field( 'context', $c2 );
// Production AAD binds fixed HKDF context; prove length-prefix separates unicid values with delimiters.
$prod1 = mtuc_smartucf_credentials_canonical_aad( 1, 'AES-256-GCM', 1, $u1 );
$prod2 = mtuc_smartucf_credentials_canonical_aad( 1, 'AES-256-GCM', 1, $u2 );
mtuc_ch_assert( $aad1 !== $aad2, 'V3-1b: length-prefixed fields distinguish colliding naive tuples' );
mtuc_ch_assert( $prod1 !== $prod2, 'V3-1c: production AAD differs for delimiter-bearing unicids' );
$uni_eq = mtuc_smartucf_credentials_canonical_aad( 1, 'AES-256-GCM', 1, "shop=1\nblog_id=9" );
$uni_nl = mtuc_smartucf_credentials_canonical_aad( 1, "AES-256-GCM\nblog_id=9", 1, 'shop=1' );
mtuc_ch_assert( $uni_eq !== $uni_nl, 'V3-1d: equals/newline in values do not collide across fields' );
$uni_uc = mtuc_smartucf_credentials_canonical_aad( 1, 'AES-256-GCM', 1, "УНИ-магазин\x00" );
$uni_uc2 = mtuc_smartucf_credentials_canonical_aad( 1, 'AES-256-GCM', 1, 'УНИ-магазин' );
mtuc_ch_assert( $uni_uc !== $uni_uc2, 'V3-1e: Unicode/null boundary values stay distinct' );

// V3-2 Authoritative DB present / stale cache absent.
mtuc_ch_reset();
mtuc_ch_clear_auth_wpdb();
$db = new Mtuc_Ch_Auth_Wpdb();
$rec = mtuc_encrypt_smartucf_credential_pair( $user_a, $pass_a, $unicid );
mtuc_ch_assert( is_array( $rec ), 'V3-2 helper encrypt' );
$db->rows = array(
	array(
		'option_id'    => 10,
		'option_value' => maybe_serialize( $rec ),
	),
);
mtuc_ch_install_auth_wpdb( $db );
unset( $GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] );
wp_cache_set( 'notoptions', array( MTUC_SMARTUCF_CREDENTIALS_OPTION => true ), 'options' );
$read_present = mtuc_smartucf_credential_option_read_result( false );
mtuc_ch_assert( MTUC_SMARTUCF_OPTION_PRESENT_VALID === $read_present['state'], 'V3-2: DB present wins over absent option bag/notoptions' );
mtuc_ch_assert( is_array( $read_present['value'] ), 'V3-2b: PRESENT_VALID value set' );

// V3-3 DB absent / stale cache present.
$db->rows = array();
$GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] = $rec;
wp_cache_set( MTUC_SMARTUCF_CREDENTIALS_OPTION, $rec, 'options' );
$read_absent = mtuc_smartucf_credential_option_read_result( false );
mtuc_ch_assert( MTUC_SMARTUCF_OPTION_ABSENT === $read_absent['state'], 'V3-3: DB absent wins over stale option cache' );
mtuc_ch_assert( null === mtuc_get_raw_smartucf_credential_option(), 'V3-3b: get_raw null on authoritative ABSENT' );

// V3-4 DB error / cache valid → fail closed, no cache fallback.
$db->select_mode = 'error';
$read_err = mtuc_smartucf_credential_option_read_result( false );
mtuc_ch_assert( MTUC_SMARTUCF_OPTION_DB_ERROR === $read_err['state'], 'V3-4: SELECT failure → DB_ERROR' );
mtuc_ch_assert( 'mtuc_smartucf_database_read_failed' === $read_err['error_code'], 'V3-4b: database_read_failed code' );
$load_err = mtuc_load_smartucf_credential_pair( $unicid );
mtuc_ch_assert( is_wp_error( $load_err ) && 'mtuc_smartucf_database_read_failed' === $load_err->get_error_code(), 'V3-4c: load fails closed on DB_ERROR' );

// V3-5 DB invalid / cache valid.
$db->select_mode = 'ok';
$db->rows = array(
	array(
		'option_id'    => 1,
		'option_value' => '',
	),
);
$read_inv = mtuc_smartucf_credential_option_read_result( false );
mtuc_ch_assert( MTUC_SMARTUCF_OPTION_PRESENT_INVALID === $read_inv['state'], 'V3-5: empty option_value → PRESENT_INVALID' );
$load_inv = mtuc_load_smartucf_credential_pair( $unicid );
mtuc_ch_assert( is_wp_error( $load_inv ) && 'mtuc_smartucf_credential_record_invalid' === $load_inv->get_error_code(), 'V3-5b: invalid ≠ absent' );

// V3-6 Duplicate rows → PRESENT_INVALID.
$db->rows = array(
	array( 'option_id' => 1, 'option_value' => maybe_serialize( $rec ) ),
	array( 'option_id' => 2, 'option_value' => maybe_serialize( $rec ) ),
);
$read_dup = mtuc_smartucf_credential_option_read_result( false );
mtuc_ch_assert( MTUC_SMARTUCF_OPTION_PRESENT_INVALID === $read_dup['state'], 'V3-6: duplicate rows → PRESENT_INVALID' );

// V3-7 Locking SELECT failure fails closed (no insert).
$db->rows = array();
$db->select_mode = 'error';
$db->write_mode = 'ok';
$lock_fail = mtuc_persist_smartucf_credential_record_db( $rec );
mtuc_ch_assert( is_wp_error( $lock_fail ), 'V3-7: locking SELECT failure → error' );
mtuc_ch_assert( 'mtuc_smartucf_database_lock_read_failed' === $lock_fail->get_error_code(), 'V3-7b: lock read failed code' );
mtuc_ch_assert( array() === $db->rows, 'V3-7c: no insert after lock SELECT failure' );
$db->select_mode = 'ok';

// V3-8 write failure + rollback success.
$db->rows = array();
$db->write_mode = 'fail';
$db->rollback_mode = 'ok';
$mut_fail = mtuc_run_shop_credential_cache_mutation(
	$unicid,
	static function () use ( $rec ) {
		return mtuc_persist_smartucf_credential_record_db( $rec );
	}
);
mtuc_ch_assert( is_wp_error( $mut_fail ), 'V3-8: write failure returns error' );
mtuc_ch_assert( 'mtuc_smartucf_mutation_failed' === $mut_fail->get_error_code(), 'V3-8b: mutation_failed after successful rollback' );

// V3-9 write failure + rollback failure → uncertain.
$db->write_mode = 'fail';
$db->rollback_mode = 'fail';
$mut_uncert = mtuc_run_shop_credential_cache_mutation(
	$unicid,
	static function () use ( $rec ) {
		return mtuc_persist_smartucf_credential_record_db( $rec );
	}
);
mtuc_ch_assert( is_wp_error( $mut_uncert ), 'V3-9: rollback failure surfaced' );
mtuc_ch_assert(
	'mtuc_smartucf_transaction_rollback_failed' === $mut_uncert->get_error_code(),
	'V3-9b: transaction_rollback_failed (no preservation claim)'
);
$db->rollback_mode = 'ok';
$db->write_mode = 'ok';

// V3-10 COMMIT failure + rollback success.
$db->rows = array();
$db->commit_mode = 'fail';
$commit_fail = mtuc_run_shop_credential_cache_mutation(
	$unicid,
	static function () {
		return true;
	}
);
mtuc_ch_assert( is_wp_error( $commit_fail ), 'V3-10: COMMIT failure → error' );
mtuc_ch_assert( 'mtuc_smartucf_mutation_failed' === $commit_fail->get_error_code(), 'V3-10b: mutation_failed after COMMIT+rollback' );

// V3-11 COMMIT failure + rollback failure.
$db->commit_mode = 'fail';
$db->rollback_mode = 'fail';
$commit_uncert = mtuc_run_shop_credential_cache_mutation(
	$unicid,
	static function () {
		return true;
	}
);
mtuc_ch_assert(
	is_wp_error( $commit_uncert )
		&& 'mtuc_smartucf_transaction_rollback_failed' === $commit_uncert->get_error_code(),
	'V3-11: COMMIT+ROLLBACK failure → uncertain transaction'
);
$db->commit_mode = 'ok';
$db->rollback_mode = 'ok';
mtuc_ch_clear_auth_wpdb();

// V3-12 Migration re-reads cache B — must not write stale A credentials.
mtuc_ch_reset();
$GLOBALS['mtuc_test_live_cache'] = array(
	$unicid => array(
		'uni_user'     => $user_b,
		'uni_password' => $pass_b,
		'shop_name'    => 'cache-B',
	),
);
$GLOBALS['mtuc_test_shop_cache_lock_read'] = static function ( $u ) {
	return mtuc_smartucf_cache_lock_result(
		MTUC_SMARTUCF_CACHE_LOCK_PRESENT,
		$GLOBALS['mtuc_test_live_cache'][ $u ] ?? array()
	);
};
$mig_race = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => $user_a,
		'uni_password' => $pass_a,
		'shop_name'    => 'cache-A',
	),
	$unicid
);
mtuc_ch_assert( true === $mig_race['rewritten'], 'V3-12: migration commits after lock re-read' );
$mig_pair = mtuc_load_smartucf_credential_pair( $unicid );
mtuc_ch_assert( is_array( $mig_pair ) && $user_b === $mig_pair['uni_user'], 'V3-12b: migrated pair is B not stale A' );
mtuc_ch_assert( $pass_b === $mig_pair['uni_password'], 'V3-12c: migrated password is B' );
unset( $GLOBALS['mtuc_test_shop_cache_lock_read'], $GLOBALS['mtuc_test_live_cache'] );

// V3-13 Concurrent COMPLETE push wins — migration must not migrate stale legacy.
mtuc_ch_reset();
$pushed = mtuc_encrypt_smartucf_credential_pair( $user_b, $pass_b, $unicid );
mtuc_ch_assert( is_array( $pushed ), 'V3-13 encrypt push' );
$seen_lock = false;
$GLOBALS['mtuc_shop_mutation_boundary'] = static function ( $u, $callback ) use ( &$seen_lock, $pushed ) {
	unset( $u );
	// Simulate COMPLETE push acquiring lock first.
	if ( ! $seen_lock ) {
		$seen_lock = true;
		$GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] = $pushed;
	}
	return call_user_func( $callback );
};
$GLOBALS['mtuc_test_shop_cache_lock_read'] = static function () {
	return mtuc_smartucf_cache_lock_result(
		MTUC_SMARTUCF_CACHE_LOCK_PRESENT,
		array(
			'uni_user'     => 'legacy_a',
			'uni_password' => 'legacy_a_pass',
			'shop_name'    => 'legacy',
		)
	);
};
$mig_push = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => 'legacy_a',
		'uni_password' => 'legacy_a_pass',
	),
	$unicid
);
mtuc_ch_assert( ! isset( $mig_push['data']['uni_user'] ), 'V3-13: runtime sanitized' );
$after_push = mtuc_decrypt_smartucf_credential_record( mtuc_get_raw_smartucf_credential_option(), $unicid );
mtuc_ch_assert( is_array( $after_push ) && $user_b === $after_push['uni_user'], 'V3-13b: dedicated COMPLETE push remains authoritative' );
unset( $GLOBALS['mtuc_shop_mutation_boundary'], $GLOBALS['mtuc_test_shop_cache_lock_read'] );

// V3-14 Cleanup sanitizes CURRENT cache row, not pre-lock A.
mtuc_ch_reset();
mtuc_ch_store_pair( $unicid, $user_a, $pass_a );
$written_cleanup = null;
$GLOBALS['mtuc_test_live_cache'] = array(
	$unicid => array(
		'uni_user'     => 'stale-should-not-matter',
		'uni_password' => 'stale-pass',
		'shop_name'    => 'NEW-ROW',
		'marker'       => 'current',
	),
);
$GLOBALS['mtuc_test_shop_cache_lock_read'] = static function ( $u ) {
	return mtuc_smartucf_cache_lock_result(
		MTUC_SMARTUCF_CACHE_LOCK_PRESENT,
		$GLOBALS['mtuc_test_live_cache'][ $u ]
	);
};
// Provide a writer via temporary class alias by injecting boundary + capturing sanitize path:
$GLOBALS['mtuc_shop_mutation_boundary'] = static function ( $u, $callback ) use ( &$written_cleanup ) {
	unset( $u );
	// Monkey-patch writer through lock-read only — call persist via custom wrapper installed below.
	return call_user_func( $callback );
};
// Install a one-off writer by defining persist on a stub if Shop_Cache absent — use mutation that invokes writer.
// Force writer by creating a minimal stub class if needed.
if ( ! class_exists( 'Mtuc_Shop_Cache', false ) ) {
	/**
	 * Minimal shop-cache stub for cleanup capture.
	 */
	class Mtuc_Shop_Cache {
		/**
		 * @param string               $unicid UNICID.
		 * @param array<string, mixed> $data Snapshot.
		 * @return true
		 */
		public static function persist_sanitized_snapshot( string $unicid, array $data ) {
			$GLOBALS['mtuc_v3_cleanup_written'] = $data;
			unset( $unicid );
			return true;
		}
	}
}
$GLOBALS['mtuc_v3_cleanup_written'] = null;
$cleanup = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => 'PRELOCK-A',
		'uni_password' => 'PRELOCK-A-PASS',
		'shop_name'    => 'PRELOCK-A',
	),
	$unicid
);
mtuc_ch_assert( is_array( $cleanup['data'] ), 'V3-14: cleanup returns runtime data' );
mtuc_ch_assert(
	is_array( $GLOBALS['mtuc_v3_cleanup_written'] )
		&& 'NEW-ROW' === ( $GLOBALS['mtuc_v3_cleanup_written']['shop_name'] ?? null )
		&& 'current' === ( $GLOBALS['mtuc_v3_cleanup_written']['marker'] ?? null )
		&& ! isset( $GLOBALS['mtuc_v3_cleanup_written']['uni_user'] ),
	'V3-14b: cleanup persisted sanitized CURRENT row, not pre-lock A'
);
$still_a = mtuc_load_smartucf_credential_pair( $unicid );
mtuc_ch_assert( is_array( $still_a ) && $user_a === $still_a['uni_user'], 'V3-14c: dedicated pair unchanged' );
unset(
	$GLOBALS['mtuc_shop_mutation_boundary'],
	$GLOBALS['mtuc_test_shop_cache_lock_read'],
	$GLOBALS['mtuc_test_live_cache'],
	$GLOBALS['mtuc_v3_cleanup_written']
);

// V3-15 Migration DB_ERROR aborts without mutation.
mtuc_ch_reset();
$db = new Mtuc_Ch_Auth_Wpdb();
$db->select_mode = 'error';
mtuc_ch_install_auth_wpdb( $db );
$mig_db_err = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => $user_a,
		'uni_password' => $pass_a,
	),
	$unicid
);
mtuc_ch_assert( ! empty( $mig_db_err['fail_closed'] ), 'V3-15: DB_ERROR migration fail_closed' );
mtuc_ch_assert( false === $mig_db_err['rewritten'], 'V3-15b: no durable rewrite' );
mtuc_ch_assert( array() === $db->rows, 'V3-15c: no credential rows written' );
mtuc_ch_clear_auth_wpdb();

// V3-16 Source-inspection markers.
$cred_src_v3 = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-credentials.php' );
mtuc_ch_assert( false !== strpos( $cred_src_v3, 'function mtuc_smartucf_credential_option_read_result' ), 'V3-16: explicit option read result' );
mtuc_ch_assert( false !== strpos( $cred_src_v3, 'function mtuc_smartucf_transaction_rollback' ), 'V3-16b: rollback helper' );
mtuc_ch_assert( false !== strpos( $cred_src_v3, 'mtuc_smartucf_credentials_aad_field' ), 'V3-16c: injective AAD field helper' );
mtuc_ch_assert( false !== strpos( $cred_src_v3, 'mtuc_read_shop_cache_snapshot_for_update' ), 'V3-16d: in-boundary cache re-read' );
mtuc_ch_assert( false === strpos( $cred_src_v3, "return 'version=' ." ), 'V3-16e: naive newline AAD removed' );

// ---------------------------------------------------------------------------
// AUD-WOO-018-V4 — production dead-DB fail-closed vs explicit test harness
// ---------------------------------------------------------------------------

/**
 * Production-shaped $wpdb with dead/missing dbh (must not authorize credentials).
 */
class Mtuc_Ch_Dead_Wpdb {
	/** @var string */
	public $options = 'wp_options';
	/** @var string */
	public $prefix = 'wp_';
	/** @var string */
	public $last_error = '';
	/** @var mixed */
	public $dbh = null;
	/** @var int */
	public $query_count = 0;
	/** @var int */
	public $write_count = 0;

	/**
	 * @param string $query SQL.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		unset( $args );
		return (string) $query;
	}

	/**
	 * @param string $query SQL.
	 * @param mixed  $output Output.
	 * @return false
	 */
	public function get_results( $query, $output = null ) {
		unset( $query, $output );
		++$this->query_count;
		$this->last_error = 'no connection';
		return false;
	}

	/**
	 * @param string $query SQL.
	 * @return null
	 */
	public function get_var( $query ) {
		unset( $query );
		++$this->query_count;
		return null;
	}

	/**
	 * @param string $query SQL.
	 * @return false
	 */
	public function query( $query ) {
		unset( $query );
		++$this->query_count;
		$this->last_error = 'no connection';
		return false;
	}

	/**
	 * @param string               $table Table.
	 * @param array<string, mixed> $data Data.
	 * @param mixed                $format Format.
	 * @return false
	 */
	public function insert( $table, $data, $format = null ) {
		unset( $table, $data, $format );
		++$this->write_count;
		return false;
	}

	/**
	 * @param string               $table Table.
	 * @param array<string, mixed> $data Data.
	 * @param array<string, mixed> $where Where.
	 * @param mixed                $format Format.
	 * @param mixed                $where_format Where format.
	 * @return false
	 */
	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		unset( $table, $data, $where, $format, $where_format );
		++$this->write_count;
		return false;
	}
}

mtuc_ch_assert(
	defined( 'MTUC_SMARTUCF_TEST_HARNESS' ) && true === MTUC_SMARTUCF_TEST_HARNESS,
	'V4-0: test bootstrap defines MTUC_SMARTUCF_TEST_HARNESS'
);
mtuc_ch_assert( true === mtuc_smartucf_is_test_harness_mode(), 'V4-0b: harness mode ON by default in suite' );

// Test mode ON — option-API fallback still works without live dbh.
mtuc_ch_reset();
unset( $GLOBALS['wpdb'] );
$harness_rec = mtuc_encrypt_smartucf_credential_pair( $user_a, $pass_a, $unicid );
mtuc_ch_assert( is_array( $harness_rec ), 'V4-ON encrypt' );
$stored_on = mtuc_store_smartucf_credential_record( $harness_rec );
mtuc_ch_assert( true === $stored_on, 'V4-ON: harness store via option API succeeds' );
$read_on = mtuc_smartucf_credential_option_read_result( false );
mtuc_ch_assert( MTUC_SMARTUCF_OPTION_PRESENT_VALID === $read_on['state'], 'V4-ON: harness read PRESENT_VALID' );

// Production semantics: dead dbh + stale option cache must NOT authorize.
mtuc_ch_reset();
$dead = new Mtuc_Ch_Dead_Wpdb();
$dead->dbh = null;
$GLOBALS['wpdb'] = $dead;
$GLOBALS['mtuc_smartucf_test_harness_override'] = false;
$stale = mtuc_encrypt_smartucf_credential_pair( $user_a, $pass_a, $unicid );
mtuc_ch_assert( is_array( $stale ), 'V4-A encrypt stale' );
$GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] = $stale;
wp_cache_set( MTUC_SMARTUCF_CREDENTIALS_OPTION, $stale, 'options' );
mtuc_ch_assert( false === mtuc_smartucf_is_test_harness_mode(), 'V4-A0: production override active' );
$read_dead = mtuc_smartucf_credential_option_read_result( false );
mtuc_ch_assert( MTUC_SMARTUCF_OPTION_DB_ERROR === $read_dead['state'], 'V4-A: dead dbh → DB_ERROR (ignore stale option cache)' );
mtuc_ch_assert( 'mtuc_smartucf_database_read_failed' === $read_dead['error_code'], 'V4-A2: database_read_failed' );
mtuc_ch_assert( null === mtuc_get_raw_smartucf_credential_option(), 'V4-A3: get_raw null on DB_ERROR' );
$hyd_dead = mtuc_hydrate_smartucf_shop_credentials( mtuc_ch_shop(), $unicid );
mtuc_ch_assert( is_wp_error( $hyd_dead ), 'V4-A4: no hydration from stale cache' );

// V4-B stale notoptions cannot hide as ABSENT.
wp_cache_set( 'notoptions', array( MTUC_SMARTUCF_CREDENTIALS_OPTION => true ), 'options' );
unset( $GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] );
$read_not = mtuc_smartucf_credential_option_read_result( false );
mtuc_ch_assert( MTUC_SMARTUCF_OPTION_DB_ERROR === $read_not['state'], 'V4-B: dead dbh + notoptions → DB_ERROR not ABSENT' );

// V4-C mutation fails; no update_option / cache write / static lock success path.
$options_before = $GLOBALS['mtuc_test_options'];
$mut_dead = mtuc_commit_authenticated_shop_snapshot(
	$unicid,
	mtuc_ch_shop(
		array(
			'uni_user'     => $user_b,
			'uni_password' => $pass_b,
		)
	),
	static function () {
		$GLOBALS['mtuc_v4_cache_written'] = true;
		return true;
	}
);
mtuc_ch_assert( is_wp_error( $mut_dead ), 'V4-C: COMPLETE mutation fails on dead DB' );
mtuc_ch_assert( empty( $GLOBALS['mtuc_v4_cache_written'] ), 'V4-C2: cache writer not invoked' );
mtuc_ch_assert( $options_before === $GLOBALS['mtuc_test_options'], 'V4-C3: no update_option / option bag mutation' );
mtuc_ch_assert( 0 === $dead->write_count, 'V4-C4: no DB write attempts via insert/update success path' );

// V4-D migration during DB outage — sanitized, no durable migrate.
$GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ] = $stale;
$mig_dead = mtuc_maybe_migrate_legacy_shop_credentials(
	array(
		'uni_user'     => $user_b,
		'uni_password' => $pass_b,
		'shop_name'    => 'legacy-keep',
	),
	$unicid
);
mtuc_ch_assert( ! empty( $mig_dead['fail_closed'] ), 'V4-D: migration fail_closed on DB outage' );
mtuc_ch_assert( false === $mig_dead['rewritten'], 'V4-D2: no durable rewrite' );
mtuc_ch_assert( ! isset( $mig_dead['data']['uni_user'], $mig_dead['data']['uni_password'] ), 'V4-D3: no plaintext returned' );
mtuc_ch_assert( 'legacy-keep' === ( $mig_dead['data']['shop_name'] ?? null ), 'V4-D4: non-cred field preserved' );
mtuc_ch_assert( $stale === $GLOBALS['mtuc_test_options'][ MTUC_SMARTUCF_CREDENTIALS_OPTION ], 'V4-D5: durable option bag unchanged' );

// V4-E P1 fail-before-claim during DB outage with stale valid credentials.
$shop_p1 = mtuc_ch_shop();
$req_p1  = mtuc_require_smartucf_credentials_for_send( $shop_p1, $unicid );
mtuc_ch_assert( is_wp_error( $req_p1 ), 'V4-E: P1 require fails on DB outage' );
mtuc_ch_assert(
	'mtuc_smartucf_credentials_unavailable' === $req_p1->get_error_code(),
	'V4-E2: local/pre-send credentials_unavailable'
);
$start_p1 = Mtuc_Smartucf_Api_Client::start_session(
	array(
		'user' => $user_a,
		'pass' => $pass_a,
	),
	mtuc_ch_endpoint_shop()
);
// start_session with explicit payload still hits string guard / transport — use empty shop hydration path:
// Credential authority for P1 is require_* above. Confirm no bank_send_failed taxonomy from require.
$req_data = $req_p1->get_error_data();
mtuc_ch_assert(
	is_array( $req_data ) && isset( $req_data['cause'] ) && 'mtuc_smartucf_database_read_failed' === $req_data['cause'],
	'V4-E3: cause is database_read_failed (not bank failure)'
);
unset( $start_p1 );

// V4-F explicit OFF vs ON for missing dbh.
$GLOBALS['mtuc_smartucf_test_harness_override'] = false;
$dead2 = new Mtuc_Ch_Dead_Wpdb();
$dead2->dbh = false;
$GLOBALS['wpdb'] = $dead2;
$off_read = mtuc_smartucf_credential_option_read_result( false );
mtuc_ch_assert( MTUC_SMARTUCF_OPTION_DB_ERROR === $off_read['state'], 'V4-F: test mode OFF + missing dbh → DB_ERROR' );
$off_persist = mtuc_persist_smartucf_credential_record_db( $stale );
mtuc_ch_assert( is_wp_error( $off_persist ), 'V4-F2: production persist fails without live DB' );
$off_mut = mtuc_run_shop_credential_cache_mutation(
	$unicid,
	static function () {
		$GLOBALS['mtuc_v4_static_lock_ran'] = true;
		return true;
	}
);
mtuc_ch_assert( is_wp_error( $off_mut ), 'V4-F3: production mutation fails without advisory lock' );
mtuc_ch_assert( empty( $GLOBALS['mtuc_v4_static_lock_ran'] ), 'V4-F4: static lock path not used in production' );

$GLOBALS['mtuc_smartucf_test_harness_override'] = true;
unset( $GLOBALS['wpdb'], $GLOBALS['mtuc_test_authoritative_wpdb'] );
$on_read = mtuc_smartucf_credential_option_read_result( false );
// option bag may be empty here → ABSENT is fine under harness
mtuc_ch_assert(
	in_array(
		$on_read['state'],
		array( MTUC_SMARTUCF_OPTION_ABSENT, MTUC_SMARTUCF_OPTION_PRESENT_VALID, MTUC_SMARTUCF_OPTION_PRESENT_INVALID ),
		true
	),
	'V4-F5: test mode ON may use option-API states (not forced DB_ERROR)'
);
$on_mut = mtuc_run_shop_credential_cache_mutation(
	$unicid,
	static function () {
		return 'harness-ok';
	}
);
mtuc_ch_assert( 'harness-ok' === $on_mut, 'V4-F6: test mode ON allows non-DB mutation harness' );

// V4-G shipping code must not define the harness flag.
$plugin_boot = (string) file_get_contents( MTUC_PLUGIN_DIR . '/mtunicredit.php' );
mtuc_ch_assert(
	false === strpos( $plugin_boot, 'MTUC_SMARTUCF_TEST_HARNESS' ),
	'V4-G: production plugin bootstrap does not define test harness'
);
$cred_src_v4 = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-credentials.php' );
mtuc_ch_assert(
	false !== strpos( $cred_src_v4, 'function mtuc_smartucf_is_test_harness_mode' ),
	'V4-G2: explicit harness helper present'
);
mtuc_ch_assert(
	false === strpos( $cred_src_v4, "define( 'MTUC_SMARTUCF_TEST_HARNESS'" ),
	'V4-G3: credentials module does not define harness constant'
);

mtuc_ch_reset();
mtuc_ch_clear_auth_wpdb();

fwrite( STDOUT, 'OK: ' . $mtuc_ch_assert_count . " assertions\n" );
exit( 0 );
