<?php
/**
 * AUD-012 signed-request contract and replay tests for Woo module.
 *
 * @package MTUC
 */

require __DIR__ . '/bootstrap.php';

require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-settings.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-module-request-signature-protocol.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-module-request-authenticator.php';

if ( ! class_exists( 'Mtuc_Aud012_Fake_Nonce_Store' ) ) {
	/**
	 * In-memory nonce store for CLI tests.
	 */
	final class Mtuc_Aud012_Fake_Nonce_Store {
		/** @var list<array{unicid:string,nonce_hash:string}> */
		public static $rows = array();

		/**
		 * @param string $unicid Shop UNICID.
		 * @param string $nonce Request nonce.
		 * @param int    $now Current unix timestamp.
		 * @return bool
		 */
		public static function claim_nonce( string $unicid, string $nonce, int $now ): bool {
			unset( $now );

			$nonce_hash = hash( 'sha256', $nonce );
			foreach ( self::$rows as $row ) {
				if ( $row['unicid'] === $unicid && $row['nonce_hash'] === $nonce_hash ) {
					return false;
				}
			}

			self::$rows[] = array(
				'unicid'     => $unicid,
				'nonce_hash' => $nonce_hash,
			);

			return true;
		}
	}
}

class Mtuc_Aud012_Settings extends Mtuc_Settings {
	/** @var array<string, mixed> */
	public static $values = array(
		Mtuc_Settings::OPTION_STATUS     => 1,
		Mtuc_Settings::OPTION_UNICID     => 'TEST-UNICID',
		Mtuc_Settings::OPTION_SECRET_KEY => Mtuc_Module_Request_Signature_Protocol::CONTRACT_SECRET,
	);

	/**
	 * @param string $key Option key.
	 * @return mixed
	 */
	public static function get( $key ) {
		return self::$values[ $key ] ?? null;
	}

	/**
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 1 === (int) ( self::$values[ Mtuc_Settings::OPTION_STATUS ] ?? 0 );
	}
}

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_aud012_assert( bool $ok, string $message ): void {
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

/**
 * @param string $secret Shared secret.
 * @param string $raw_body Raw JSON body.
 * @param string $timestamp Timestamp header.
 * @param string $nonce Nonce header.
 * @return array<string, string>
 */
function mtuc_aud012_signed_headers( string $secret, string $raw_body, string $timestamp, string $nonce ): array {
	return array(
		Mtuc_Module_Request_Signature_Protocol::HEADER_TIMESTAMP  => $timestamp,
		Mtuc_Module_Request_Signature_Protocol::HEADER_NONCE      => $nonce,
		Mtuc_Module_Request_Signature_Protocol::HEADER_SIGNATURE  => Mtuc_Module_Request_Signature_Protocol::compute_signature(
			$secret,
			$timestamp,
			$nonce,
			$raw_body
		),
	);
}

/**
 * @param array<string, mixed> $payload Payload.
 * @param string               $raw_body Raw body.
 * @param array<string, mixed> $headers Headers.
 * @return string|WP_Error
 */
function mtuc_aud012_authenticate( array $payload, string $raw_body, array $headers ) {
	if ( ! Mtuc_Aud012_Settings::is_enabled() ) {
		return new WP_Error( 'mtuc_module_disabled', 'disabled', array( 'status' => 403 ) );
	}

	$stored_unicid = (string) Mtuc_Aud012_Settings::get( Mtuc_Aud012_Settings::OPTION_UNICID );
	$stored_secret = (string) Mtuc_Aud012_Settings::get( Mtuc_Aud012_Settings::OPTION_SECRET_KEY );
	$unicid        = isset( $payload['unicid'] ) ? (string) $payload['unicid'] : '';

	if ( '' === $unicid || ! hash_equals( $stored_unicid, $unicid ) ) {
		return new WP_Error(
			'mtuc_invalid_module_request',
			Mtuc_Module_Request_Signature_Protocol::AUTH_FAILURE_MESSAGE,
			array( 'status' => 401 )
		);
	}

	$verification = Mtuc_Module_Request_Authenticator::verify_signature( $stored_secret, $raw_body, $headers );
	if ( is_wp_error( $verification ) ) {
		return $verification;
	}

	$nonce = null;
	foreach ( $headers as $header_name => $header_value ) {
		if ( is_string( $header_name ) && strcasecmp( $header_name, Mtuc_Module_Request_Signature_Protocol::HEADER_NONCE ) === 0 ) {
			$nonce = is_array( $header_value ) ? (string) ( $header_value[0] ?? '' ) : (string) $header_value;
			break;
		}
	}

	if ( null === $nonce || ! Mtuc_Aud012_Fake_Nonce_Store::claim_nonce( $unicid, $nonce, (int) Mtuc_Module_Request_Signature_Protocol::CONTRACT_TIMESTAMP ) ) {
		return new WP_Error(
			'mtuc_invalid_module_request',
			Mtuc_Module_Request_Signature_Protocol::AUTH_FAILURE_MESSAGE,
			array( 'status' => 401 )
		);
	}

	return $unicid;
}

Mtuc_Module_Request_Authenticator::set_fixed_now( (int) Mtuc_Module_Request_Signature_Protocol::CONTRACT_TIMESTAMP );

mtuc_aud012_assert(
	Mtuc_Module_Request_Signature_Protocol::compute_signature(
		Mtuc_Module_Request_Signature_Protocol::CONTRACT_SECRET,
		Mtuc_Module_Request_Signature_Protocol::CONTRACT_TIMESTAMP,
		Mtuc_Module_Request_Signature_Protocol::CONTRACT_NONCE,
		Mtuc_Module_Request_Signature_Protocol::CONTRACT_RAW_BODY
	) === Mtuc_Module_Request_Signature_Protocol::CONTRACT_SIGNATURE,
	'shared contract vector mismatch'
);

$raw_body = Mtuc_Module_Request_Signature_Protocol::CONTRACT_RAW_BODY;
$payload  = json_decode( $raw_body, true );
mtuc_aud012_assert( is_array( $payload ), 'contract payload decode failed' );

$headers = mtuc_aud012_signed_headers(
	Mtuc_Module_Request_Signature_Protocol::CONTRACT_SECRET,
	$raw_body,
	Mtuc_Module_Request_Signature_Protocol::CONTRACT_TIMESTAMP,
	Mtuc_Module_Request_Signature_Protocol::CONTRACT_NONCE
);

$result = mtuc_aud012_authenticate( $payload, $raw_body, $headers );
mtuc_aud012_assert( ! is_wp_error( $result ) && 'TEST-UNICID' === $result, 'valid signed request rejected' );

$replay = mtuc_aud012_authenticate( $payload, $raw_body, $headers );
mtuc_aud012_assert(
	is_wp_error( $replay ) && 401 === (int) ( $replay->get_error_data()['status'] ?? 0 ),
	'exact replay accepted'
);

$new_nonce = str_repeat( 'b', 64 );
$new_headers = mtuc_aud012_signed_headers(
	Mtuc_Module_Request_Signature_Protocol::CONTRACT_SECRET,
	$raw_body,
	Mtuc_Module_Request_Signature_Protocol::CONTRACT_TIMESTAMP,
	$new_nonce
);
$again = mtuc_aud012_authenticate( $payload, $raw_body, $new_headers );
mtuc_aud012_assert( ! is_wp_error( $again ), 'same body with new nonce rejected' );

$legacy = mtuc_aud012_authenticate(
	array(
		'unicid' => 'TEST-UNICID',
		'secret' => Mtuc_Module_Request_Signature_Protocol::CONTRACT_SECRET,
	),
	'{"unicid":"TEST-UNICID","secret":"' . Mtuc_Module_Request_Signature_Protocol::CONTRACT_SECRET . '"}',
	array()
);
mtuc_aud012_assert(
	is_wp_error( $legacy ) && 401 === (int) ( $legacy->get_error_data()['status'] ?? 0 ),
	'legacy unsigned request accepted'
);

fwrite( STDOUT, "OK (AUD-012 Woo module request signature and replay protection)\n" );
