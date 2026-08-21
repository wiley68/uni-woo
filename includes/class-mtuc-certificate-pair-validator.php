<?php
/**
 * X.509 certificate / private-key pair validation and hashing.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates UniCredit client certificate pairs and computes exact PEM SHA-256 digests.
 */
class Mtuc_Certificate_Pair_Validator {

	/**
	 * SHA-256 hex digest of exact PEM bytes (no normalization).
	 *
	 * @param string $pem Raw PEM bytes.
	 * @return string 64 lowercase hex characters.
	 */
	public static function sha256_hex( string $pem ): string {
		return hash( 'sha256', $pem );
	}

	/**
	 * Whether a string is a 64-character lowercase hex SHA-256 digest.
	 *
	 * @param string $hash Candidate hash.
	 * @return bool
	 */
	public static function is_valid_sha256( string $hash ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $hash );
	}

	/**
	 * Extract the first PEM block of a given type from possibly bag-prefixed content.
	 *
	 * @param string $raw  Raw file contents.
	 * @param string $type CERTIFICATE|PRIVATE KEY|ENCRYPTED PRIVATE KEY|RSA PRIVATE KEY.
	 * @return string Empty when not found.
	 */
	public static function extract_pem_block( string $raw, string $type ): string {
		$pattern = '/-----BEGIN ' . preg_quote( $type, '/' ) . '-----.*?-----END '
			. preg_quote( $type, '/' ) . '-----/s';

		if ( ! preg_match( $pattern, $raw, $matches ) ) {
			return '';
		}

		return $matches[0];
	}

	/**
	 * Resolve a parseable certificate PEM from raw bytes.
	 *
	 * @param string $raw Raw certificate file contents.
	 * @return string Empty when unusable.
	 */
	public static function resolve_certificate_pem( string $raw ): string {
		if ( '' === trim( $raw ) ) {
			return '';
		}

		$cert = @openssl_x509_read( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $cert ) {
			return $raw;
		}

		$block = self::extract_pem_block( $raw, 'CERTIFICATE' );
		if ( '' === $block ) {
			return '';
		}

		$cert = @openssl_x509_read( $block ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== $cert ? $block : '';
	}

	/**
	 * Resolve a parseable private-key PEM from raw bytes.
	 *
	 * @param string $raw        Raw key file contents.
	 * @param string $passphrase Optional passphrase (default MTUC_SSL_PASSWD).
	 * @return string Empty when unusable.
	 */
	public static function resolve_private_key_pem( string $raw, string $passphrase = '' ): string {
		if ( '' === trim( $raw ) ) {
			return '';
		}

		if ( '' === $passphrase && defined( 'MTUC_SSL_PASSWD' ) ) {
			$passphrase = (string) MTUC_SSL_PASSWD;
		}

		$key = @openssl_pkey_get_private( $raw, $passphrase ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $key ) {
			return $raw;
		}

		$key = @openssl_pkey_get_private( $raw, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $key ) {
			return $raw;
		}

		foreach ( array( 'PRIVATE KEY', 'ENCRYPTED PRIVATE KEY', 'RSA PRIVATE KEY' ) as $type ) {
			$block = self::extract_pem_block( $raw, $type );
			if ( '' === $block ) {
				continue;
			}

			$key = @openssl_pkey_get_private( $block, $passphrase ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $key ) {
				return $block;
			}

			$key = @openssl_pkey_get_private( $block, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false !== $key ) {
				return $block;
			}
		}

		return '';
	}

	/**
	 * Validate an in-memory certificate/key pair.
	 *
	 * @param string $cert_pem   Certificate PEM (exact or bag-prefixed).
	 * @param string $key_pem    Private key PEM (exact or bag-prefixed).
	 * @param string $passphrase Optional passphrase.
	 * @param int    $now        Unix timestamp for validity window (0 = time()).
	 * @return true|WP_Error
	 */
	public static function validate_pair( string $cert_pem, string $key_pem, string $passphrase = '', int $now = 0 ) {
		if ( '' === trim( $cert_pem ) || '' === trim( $key_pem ) ) {
			return new WP_Error(
				'mtuc_ssl_pair_empty',
				__( 'Локалният SSL сертификат или ключ е празен.', 'mtunicredit' )
			);
		}

		$cert_for_parse = self::resolve_certificate_pem( $cert_pem );
		if ( '' === $cert_for_parse ) {
			return new WP_Error(
				'mtuc_ssl_cert_malformed',
				__( 'Локалният SSL сертификат е невалиден.', 'mtunicredit' )
			);
		}

		$key_for_parse = self::resolve_private_key_pem( $key_pem, $passphrase );
		if ( '' === $key_for_parse ) {
			return new WP_Error(
				'mtuc_ssl_key_malformed',
				__( 'Локалният SSL частен ключ е невалиден.', 'mtunicredit' )
			);
		}

		$cert = openssl_x509_read( $cert_for_parse );
		if ( false === $cert ) {
			return new WP_Error(
				'mtuc_ssl_cert_malformed',
				__( 'Локалният SSL сертификат е невалиден.', 'mtunicredit' )
			);
		}

		if ( '' === $passphrase && defined( 'MTUC_SSL_PASSWD' ) ) {
			$passphrase = (string) MTUC_SSL_PASSWD;
		}

		$key = openssl_pkey_get_private( $key_for_parse, $passphrase );
		if ( false === $key ) {
			$key = openssl_pkey_get_private( $key_for_parse, '' );
		}
		if ( false === $key ) {
			return new WP_Error(
				'mtuc_ssl_key_malformed',
				__( 'Локалният SSL частен ключ е невалиден.', 'mtunicredit' )
			);
		}

		if ( ! openssl_x509_check_private_key( $cert, $key ) ) {
			return new WP_Error(
				'mtuc_ssl_pair_mismatch',
				__( 'SSL сертификатът и частният ключ не си съответстват.', 'mtunicredit' )
			);
		}

		$parsed = openssl_x509_parse( $cert );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error(
				'mtuc_ssl_cert_malformed',
				__( 'Локалният SSL сертификат е невалиден.', 'mtunicredit' )
			);
		}

		$now = $now > 0 ? $now : time();
		$not_before = isset( $parsed['validFrom_time_t'] ) ? (int) $parsed['validFrom_time_t'] : 0;
		$not_after  = isset( $parsed['validTo_time_t'] ) ? (int) $parsed['validTo_time_t'] : 0;

		if ( $not_before > 0 && $now < $not_before ) {
			return new WP_Error(
				'mtuc_ssl_cert_not_yet_valid',
				__( 'SSL сертификатът още не е валиден.', 'mtunicredit' )
			);
		}

		if ( $not_after > 0 && $now > $not_after ) {
			return new WP_Error(
				'mtuc_ssl_cert_expired',
				__( 'SSL сертификатът е изтекъл.', 'mtunicredit' )
			);
		}

		return true;
	}

	/**
	 * Validate certificate/key files on disk.
	 *
	 * @param string $cert_path  Absolute certificate path.
	 * @param string $key_path   Absolute private key path.
	 * @param string $passphrase Optional passphrase.
	 * @param int    $now        Unix timestamp for validity window.
	 * @return true|WP_Error
	 */
	public static function validate_files( string $cert_path, string $key_path, string $passphrase = '', int $now = 0 ) {
		if ( ! is_readable( $cert_path ) || ! is_readable( $key_path ) ) {
			return new WP_Error(
				'mtuc_ssl_files_unreadable',
				__( 'Локалните SSL файлове липсват или не могат да бъдат прочетени.', 'mtunicredit' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cert_pem = file_get_contents( $cert_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$key_pem = file_get_contents( $key_path );

		if ( ! is_string( $cert_pem ) || ! is_string( $key_pem ) ) {
			return new WP_Error(
				'mtuc_ssl_files_unreadable',
				__( 'Локалните SSL файлове липсват или не могат да бъдат прочетени.', 'mtunicredit' )
			);
		}

		return self::validate_pair( $cert_pem, $key_pem, $passphrase, $now );
	}
}
