<?php
/**
 * Module-owned SmartUCF endpoint trust policy (SSRF / redirect boundary).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates Control Panel SmartUCF URLs against a fixed UniCredit allowlist.
 *
 * CP shop fields remain configuration input; they do not expand the trust boundary.
 */
class Mtuc_Smartucf_Endpoint_Policy {

	/** Production SmartUCF service base (trailing slash). */
	public const SERVICE_PRODUCTION = 'https://online.ucfin.bg/suos/api/otp/';

	/** Test SmartUCF service base (trailing slash). */
	public const SERVICE_TEST = 'https://onlinetest.ucfin.bg/suos/api/otp/';

	/** Production SmartUCF application base (no trailing slash). */
	public const APPLICATION_PRODUCTION = 'https://online.ucfin.bg/sucf-online/Request/Start';

	/** Test SmartUCF application base (no trailing slash). */
	public const APPLICATION_TEST = 'https://onlinetest.ucfin.bg/sucf-online/Request/Start';

	/** Session start path segment appended to the trusted service base. */
	public const SESSION_START_SEGMENT = 'sucfOnlineSessionStart';

	/** Production hostname. */
	private const HOST_PRODUCTION = 'online.ucfin.bg';

	/** Test hostname. */
	private const HOST_TEST = 'onlinetest.ucfin.bg';

	/** Expected service path without trailing slash. */
	private const SERVICE_PATH = '/suos/api/otp';

	/** Expected application path without trailing slash. */
	private const APPLICATION_PATH = '/sucf-online/Request/Start';

	/**
	 * Whether shop is configured for SmartUCF test environment.
	 *
	 * uni_env: 0 = test, non-zero (default 1) = production.
	 *
	 * @param array<string, mixed> $shop Shop `data` from CP.
	 * @return bool
	 */
	public static function is_test_environment( array $shop ): bool {
		return 0 === (int) ( $shop['uni_env'] ?? 1 );
	}

	/**
	 * Module-owned trusted service base for the shop environment.
	 *
	 * @param array<string, mixed> $shop Shop data.
	 * @return string
	 */
	public static function expected_service_base( array $shop ): string {
		return self::is_test_environment( $shop ) ? self::SERVICE_TEST : self::SERVICE_PRODUCTION;
	}

	/**
	 * Module-owned trusted application base for the shop environment.
	 *
	 * @param array<string, mixed> $shop Shop data.
	 * @return string
	 */
	public static function expected_application_base( array $shop ): string {
		return self::is_test_environment( $shop ) ? self::APPLICATION_TEST : self::APPLICATION_PRODUCTION;
	}

	/**
	 * Resolve the authoritative SmartUCF create-session URL for this shop.
	 *
	 * Reads the CP-configured service URL for the selected environment and accepts
	 * it only when it exactly matches the module trust contract.
	 *
	 * @param array<string, mixed> $shop Shop data.
	 * @return string|WP_Error Absolute HTTPS URL ending with sucfOnlineSessionStart.
	 */
	public static function resolve_session_start_url( array $shop ) {
		$configured = self::is_test_environment( $shop )
			? (string) ( $shop['uni_test_service'] ?? '' )
			: (string) ( $shop['uni_production_service'] ?? '' );

		$validated = self::validate_service_base( $configured, $shop );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		return $validated . self::SESSION_START_SEGMENT;
	}

	/**
	 * Build a trusted browser redirect URL from a SmartUCF session ID.
	 *
	 * @param array<string, mixed> $shop       Shop data.
	 * @param string               $session_id Session ID from SmartUCF.
	 * @return string|WP_Error
	 */
	public static function resolve_application_redirect_url( array $shop, string $session_id ) {
		$session_id = trim( $session_id );
		$session_ok = self::validate_session_id( $session_id );
		if ( is_wp_error( $session_ok ) ) {
			return $session_ok;
		}

		$configured = self::is_test_environment( $shop )
			? (string) ( $shop['uni_test_application'] ?? '' )
			: (string) ( $shop['uni_production_application'] ?? '' );

		$base = self::validate_application_base( $configured, $shop );
		if ( is_wp_error( $base ) ) {
			return $base;
		}

		return $base . '/' . $session_id;
	}

	/**
	 * Whether a candidate redirect URL targets the module-owned application allowlist.
	 *
	 * @param string               $redirect_url Candidate URL.
	 * @param array<string, mixed> $shop         Shop data (environment consistency).
	 * @return bool
	 */
	public static function is_trusted_redirect_url( string $redirect_url, array $shop ): bool {
		$redirect_url = trim( $redirect_url );
		if ( '' === $redirect_url ) {
			return false;
		}

		$parts = self::parse_https_url( $redirect_url );
		if ( is_wp_error( $parts ) ) {
			return false;
		}

		$expected_host = self::is_test_environment( $shop ) ? self::HOST_TEST : self::HOST_PRODUCTION;
		if ( 0 !== strcasecmp( $parts['host'], $expected_host ) ) {
			return false;
		}

		$path      = self::normalize_path( $parts['path'] );
		$base_path = self::APPLICATION_PATH;
		if ( $path !== $base_path && 0 !== strpos( $path, $base_path . '/' ) ) {
			return false;
		}

		if ( $path === $base_path ) {
			return false;
		}

		$session_id = substr( $path, strlen( $base_path ) + 1 );
		if ( is_wp_error( self::validate_session_id( $session_id ) ) ) {
			return false;
		}

		// Reject extra path segments beyond the session ID.
		if ( false !== strpos( $session_id, '/' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Validate a CP-configured SmartUCF service base URL.
	 *
	 * @param string               $url  Candidate base URL.
	 * @param array<string, mixed> $shop Shop data for environment consistency.
	 * @return string|WP_Error Normalized base with trailing slash.
	 */
	public static function validate_service_base( string $url, array $shop ) {
		$parts = self::parse_https_url( $url );
		if ( is_wp_error( $parts ) ) {
			self::log_reject( 'service', $url, $parts->get_error_code(), $shop );
			return $parts;
		}

		$expected_host = self::is_test_environment( $shop ) ? self::HOST_TEST : self::HOST_PRODUCTION;
		if ( 0 !== strcasecmp( $parts['host'], $expected_host ) ) {
			$error = new WP_Error(
				'mtuc_smartucf_untrusted_service',
				__( 'URL на SmartUCF услугата не съвпада с доверената среда на магазина.', 'mtunicredit' )
			);
			self::log_reject( 'service', $url, $error->get_error_code(), $shop );
			return $error;
		}

		$path = self::normalize_path( $parts['path'] );
		if ( self::SERVICE_PATH !== $path ) {
			$error = new WP_Error(
				'mtuc_smartucf_untrusted_service',
				__( 'Пътят на SmartUCF услугата не е разрешен.', 'mtunicredit' )
			);
			self::log_reject( 'service', $url, $error->get_error_code(), $shop );
			return $error;
		}

		return 'https://' . strtolower( $parts['host'] ) . self::SERVICE_PATH . '/';
	}

	/**
	 * Validate a CP-configured SmartUCF application base URL.
	 *
	 * @param string               $url  Candidate base URL.
	 * @param array<string, mixed> $shop Shop data for environment consistency.
	 * @return string|WP_Error Normalized base without trailing slash.
	 */
	public static function validate_application_base( string $url, array $shop ) {
		$parts = self::parse_https_url( $url );
		if ( is_wp_error( $parts ) ) {
			self::log_reject( 'application', $url, $parts->get_error_code(), $shop );
			return $parts;
		}

		$expected_host = self::is_test_environment( $shop ) ? self::HOST_TEST : self::HOST_PRODUCTION;
		if ( 0 !== strcasecmp( $parts['host'], $expected_host ) ) {
			$error = new WP_Error(
				'mtuc_smartucf_untrusted_application',
				__( 'URL на SmartUCF приложението не съвпада с доверената среда на магазина.', 'mtunicredit' )
			);
			self::log_reject( 'application', $url, $error->get_error_code(), $shop );
			return $error;
		}

		$path = self::normalize_path( $parts['path'] );
		if ( self::APPLICATION_PATH !== $path ) {
			$error = new WP_Error(
				'mtuc_smartucf_untrusted_application',
				__( 'Пътят на SmartUCF приложението не е разрешен.', 'mtunicredit' )
			);
			self::log_reject( 'application', $url, $error->get_error_code(), $shop );
			return $error;
		}

		return 'https://' . strtolower( $parts['host'] ) . self::APPLICATION_PATH;
	}

	/**
	 * Reject session IDs that could inject path/query/fragment into the redirect URL.
	 *
	 * @param string $session_id Candidate session ID.
	 * @return true|WP_Error
	 */
	public static function validate_session_id( string $session_id ) {
		$session_id = trim( $session_id );
		if ( '' === $session_id ) {
			return new WP_Error(
				'mtuc_smartucf_invalid_session_id',
				__( 'Невалиден идентификатор на SmartUCF сесия.', 'mtunicredit' )
			);
		}

		// Narrow allowlist: printable token without URL/path metacharacters.
		if ( 1 !== preg_match( '/^[A-Za-z0-9._-]{1,256}$/', $session_id ) ) {
			return new WP_Error(
				'mtuc_smartucf_invalid_session_id',
				__( 'Невалиден идентификатор на SmartUCF сесия.', 'mtunicredit' )
			);
		}

		if ( false !== strpos( $session_id, '..' ) ) {
			return new WP_Error(
				'mtuc_smartucf_invalid_session_id',
				__( 'Невалиден идентификатор на SmartUCF сесия.', 'mtunicredit' )
			);
		}

		return true;
	}

	/**
	 * Parse and enforce HTTPS URL shape constraints shared by service/application bases.
	 *
	 * @param string $url Candidate URL.
	 * @return array{scheme:string,host:string,path:string}|WP_Error
	 */
	private static function parse_https_url( string $url ) {
		$url = trim( $url );
		if ( '' === $url ) {
			return new WP_Error(
				'mtuc_smartucf_untrusted_url',
				__( 'Липсва URL на SmartUCF.', 'mtunicredit' )
			);
		}

		// Reject credentials before parse_url hides them inconsistently across PHP versions.
		if ( preg_match( '#^[a-z][a-z0-9+.-]*://[^/]*@#i', $url ) ) {
			return new WP_Error(
				'mtuc_smartucf_untrusted_url',
				__( 'SmartUCF URL с потребителски данни не е разрешен.', 'mtunicredit' )
			);
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return new WP_Error(
				'mtuc_smartucf_untrusted_url',
				__( 'Невалиден SmartUCF URL.', 'mtunicredit' )
			);
		}

		if ( 0 !== strcasecmp( (string) $parts['scheme'], 'https' ) ) {
			return new WP_Error(
				'mtuc_smartucf_untrusted_url',
				__( 'SmartUCF URL трябва да използва HTTPS.', 'mtunicredit' )
			);
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return new WP_Error(
				'mtuc_smartucf_untrusted_url',
				__( 'SmartUCF URL с потребителски данни не е разрешен.', 'mtunicredit' )
			);
		}

		if ( array_key_exists( 'query', $parts ) && null !== $parts['query'] && '' !== (string) $parts['query'] ) {
			return new WP_Error(
				'mtuc_smartucf_untrusted_url',
				__( 'SmartUCF URL не трябва да съдържа query параметри.', 'mtunicredit' )
			);
		}

		if ( array_key_exists( 'fragment', $parts ) && null !== $parts['fragment'] && '' !== (string) $parts['fragment'] ) {
			return new WP_Error(
				'mtuc_smartucf_untrusted_url',
				__( 'SmartUCF URL не трябва да съдържа fragment.', 'mtunicredit' )
			);
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		if ( $port > 0 && 443 !== $port ) {
			return new WP_Error(
				'mtuc_smartucf_untrusted_url',
				__( 'SmartUCF URL използва неразрешен порт.', 'mtunicredit' )
			);
		}

		$host = (string) $parts['host'];
		if ( '' === $host || false !== strpos( $host, '%' ) ) {
			return new WP_Error(
				'mtuc_smartucf_untrusted_url',
				__( 'Невалиден SmartUCF хост.', 'mtunicredit' )
			);
		}

		return array(
			'scheme' => 'https',
			'host'   => $host,
			'path'   => isset( $parts['path'] ) ? (string) $parts['path'] : '',
		);
	}

	/**
	 * Normalize path for exact comparison (collapse trailing slash; ensure leading slash).
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	private static function normalize_path( string $path ): string {
		$path = trim( $path );
		if ( '' === $path ) {
			return '/';
		}

		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		return $path;
	}

	/**
	 * Sanitized rejection diagnostics (no credentials / PEM).
	 *
	 * @param string               $kind  service|application.
	 * @param string               $url   Rejected URL.
	 * @param string               $reason Error code.
	 * @param array<string, mixed> $shop  Shop data.
	 * @return void
	 */
	private static function log_reject( string $kind, string $url, string $reason, array $shop ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			return;
		}

		$parts  = wp_parse_url( $url );
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? (string) $parts['host'] : '';
		$path   = is_array( $parts ) && isset( $parts['path'] ) ? (string) $parts['path'] : '';
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? (string) $parts['scheme'] : '';

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			'MTUC SmartUCF endpoint reject: ' . wp_json_encode(
				array(
					'kind'        => $kind,
					'reason'      => $reason,
					'env'         => self::is_test_environment( $shop ) ? 'test' : 'production',
					'scheme'      => $scheme,
					'host'        => $host,
					'path'        => $path,
				)
			)
		);
	}
}
