<?php
/**
 * Signed Control Panel → module request authentication.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies signed CP module callbacks and claims nonces atomically.
 */
final class Mtuc_Module_Request_Authenticator {

	/** @var int|null Fixed clock for tests. */
	private static $fixed_now = null;

	/**
	 * Override current time in tests.
	 *
	 * @param int|null $timestamp Unix timestamp or null to reset.
	 * @return void
	 */
	public static function set_fixed_now( ?int $timestamp ): void {
		self::$fixed_now = $timestamp;
	}

	/**
	 * Authenticate a signed module request.
	 *
	 * @param array<string, mixed> $payload Decoded JSON payload.
	 * @param string               $raw_body Exact raw JSON request body.
	 * @param array<string, mixed> $headers Request headers.
	 * @return string|WP_Error Authenticated UNICID or error.
	 */
	public static function authenticate( array $payload, string $raw_body, array $headers ) {
		if ( ! Mtuc_Settings::is_enabled() ) {
			return new WP_Error(
				'mtuc_module_disabled',
				__( 'Модулът е изключен.', 'mtunicredit' ),
				array( 'status' => 403 )
			);
		}

		$stored_unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );
		$stored_secret = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_SECRET_KEY );

		if ( '' === $stored_unicid || '' === $stored_secret ) {
			return self::auth_failure();
		}

		$unicid = isset( $payload['unicid'] ) ? sanitize_text_field( (string) $payload['unicid'] ) : '';
		if ( '' === $unicid || ! hash_equals( $stored_unicid, $unicid ) ) {
			return self::auth_failure();
		}

		$verification = self::verify_signature( $stored_secret, $raw_body, $headers );
		if ( is_wp_error( $verification ) ) {
			return $verification;
		}

		$nonce = self::header_value( $headers, Mtuc_Module_Request_Signature_Protocol::HEADER_NONCE );
		if ( null === $nonce || ! self::is_valid_nonce_format( $nonce ) ) {
			return self::auth_failure();
		}

		if ( ! Mtuc_Api_Nonce_Store::claim_nonce( $unicid, $nonce, self::now() ) ) {
			if ( function_exists( 'error_log' ) ) {
				error_log( 'MTUC module API replay detected.' );
			}

			return self::auth_failure();
		}

		return $unicid;
	}

	/**
	 * Verify signature headers without claiming nonce.
	 *
	 * @param string               $secret Shared shop secret.
	 * @param string               $raw_body Exact raw JSON request body.
	 * @param array<string, mixed> $headers Request headers.
	 * @return true|WP_Error
	 */
	public static function verify_signature( string $secret, string $raw_body, array $headers ) {
		$timestamp = self::header_value( $headers, Mtuc_Module_Request_Signature_Protocol::HEADER_TIMESTAMP );
		$nonce     = self::header_value( $headers, Mtuc_Module_Request_Signature_Protocol::HEADER_NONCE );
		$signature = self::header_value( $headers, Mtuc_Module_Request_Signature_Protocol::HEADER_SIGNATURE );

		if ( null === $timestamp || null === $nonce || null === $signature ) {
			return self::auth_failure();
		}

		if ( ! self::is_valid_timestamp( $timestamp ) ) {
			return self::auth_failure();
		}

		if ( ! self::is_valid_nonce_format( $nonce ) ) {
			return self::auth_failure();
		}

		$expected = Mtuc_Module_Request_Signature_Protocol::compute_signature( $secret, $timestamp, $nonce, $raw_body );
		if ( ! hash_equals( $expected, $signature ) ) {
			return self::auth_failure();
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $headers Request headers.
	 */
	private static function header_value( array $headers, string $name ): ?string {
		$wanted = self::canonicalize_header_name( $name );

		foreach ( $headers as $header_name => $header_value ) {
			if ( ! is_string( $header_name ) ) {
				continue;
			}

			if ( self::canonicalize_header_name( $header_name ) !== $wanted ) {
				continue;
			}

			return is_array( $header_value ) ? (string) ( $header_value[0] ?? '' ) : (string) $header_value;
		}

		return null;
	}

	/**
	 * Match WordPress REST header keys (`X-UniPayment-Timestamp` → `x_unipayment_timestamp`).
	 */
	private static function canonicalize_header_name( string $name ): string {
		return strtolower( str_replace( '-', '_', $name ) );
	}

	private static function is_valid_timestamp( string $timestamp ): bool {
		if ( ! ctype_digit( $timestamp ) ) {
			return false;
		}

		$request_timestamp = (int) $timestamp;

		return abs( self::now() - $request_timestamp ) <= Mtuc_Module_Request_Signature_Protocol::TIMESTAMP_TOLERANCE_SECONDS;
	}

	private static function is_valid_nonce_format( string $nonce ): bool {
		return 1 === preg_match(
			'/\A[0-9a-fA-F]{' . Mtuc_Module_Request_Signature_Protocol::NONCE_HEX_LENGTH . '}\z/',
			$nonce
		);
	}

	private static function now(): int {
		return null === self::$fixed_now ? time() : (int) self::$fixed_now;
	}

	/**
	 * @return WP_Error
	 */
	private static function auth_failure() {
		return new WP_Error(
			'mtuc_invalid_module_request',
			Mtuc_Module_Request_Signature_Protocol::AUTH_FAILURE_MESSAGE,
			array( 'status' => 401 )
		);
	}
}
