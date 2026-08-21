<?php
/**
 * SmartUCF session API client (sucfOnlineSessionStart).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP client for UniCredit SmartUCF online session.
 */
class Mtuc_Smartucf_Api_Client {

	/** @var int Request timeout in seconds. */
	private const TIMEOUT = 10;

	/**
	 * Optional test double replacing certificate synchronization.
	 *
	 * @var callable|null
	 */
	public static $certificate_synchronizer = null;

	/**
	 * Optional test double replacing the outbound HTTP transport.
	 *
	 * Callable signature: function( array $curl_options ): array{body:string,curl_error:string,http_code:int}
	 *
	 * @var callable|null
	 */
	public static $http_transport = null;

	/**
	 * Whether shop runs against SmartUCF test environment.
	 *
	 * @param array<string, mixed> $shop Shop `data` object from CP.
	 * @return bool
	 */
	public static function is_test_environment( array $shop ): bool {
		return Mtuc_Smartucf_Endpoint_Policy::is_test_environment( $shop );
	}

	/**
	 * SmartUCF API endpoint for session start (module trust policy).
	 *
	 * Returns empty string when the CP-configured URL is not trusted.
	 *
	 * @param array<string, mixed> $shop Shop `data` object from CP.
	 * @return string
	 */
	public static function get_service_url( array $shop ): string {
		$resolved = Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop );
		return is_wp_error( $resolved ) ? '' : $resolved;
	}

	/**
	 * Browser redirect URL after successful session start (module trust policy).
	 *
	 * Returns empty string when application base or session ID is not trusted.
	 *
	 * @param array<string, mixed> $shop       Shop `data` object from CP.
	 * @param string               $session_id sucfOnlineSessionID value.
	 * @return string
	 */
	public static function get_application_redirect_url( array $shop, string $session_id ): string {
		$resolved = Mtuc_Smartucf_Endpoint_Policy::resolve_application_redirect_url( $shop, $session_id );
		return is_wp_error( $resolved ) ? '' : $resolved;
	}

	/**
	 * Module-owned SmartUCF application base URLs allowed for browser redirect.
	 *
	 * @param array<string, mixed> $shop Shop `data` object from CP.
	 * @return array<int, string>
	 */
	public static function get_application_redirect_bases( array $shop ): array {
		return array( Mtuc_Smartucf_Endpoint_Policy::expected_application_base( $shop ) );
	}

	/**
	 * Whether a redirect URL was issued by our SmartUCF integration.
	 *
	 * @param string               $redirect_url Candidate browser URL.
	 * @param array<string, mixed> $shop         Shop `data` object from CP.
	 * @return bool
	 */
	public static function is_trusted_redirect_url( string $redirect_url, array $shop ): bool {
		return Mtuc_Smartucf_Endpoint_Policy::is_trusted_redirect_url( $redirect_url, $shop );
	}

	/**
	 * Redirect the browser to SmartUCF after validating the target host.
	 *
	 * wp_safe_redirect() only allows same-site hosts; SmartUCF is external.
	 *
	 * @param string               $redirect_url Candidate browser URL.
	 * @param array<string, mixed> $shop         Shop `data` object from CP.
	 * @return bool True when redirect was sent.
	 */
	public static function redirect_browser( string $redirect_url, array $shop ): bool {
		if ( ! self::is_trusted_redirect_url( $redirect_url, $shop ) ) {
			return false;
		}

		nocache_headers();
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external bank URL validated above.
		wp_redirect( $redirect_url );
		exit;
	}

	/**
	 * Absolute path to plugin SSL key file (authoritative store).
	 *
	 * @return string
	 */
	public static function get_ssl_key_path(): string {
		return Mtuc_Certificate_Local_Store::get_key_path();
	}

	/**
	 * Absolute path to plugin SSL certificate file (authoritative store).
	 *
	 * @return string
	 */
	public static function get_ssl_cert_path(): string {
		return Mtuc_Certificate_Local_Store::get_cert_path();
	}

	/**
	 * Build cURL options for SmartUCF session start (FOLLOWLOCATION disabled).
	 *
	 * @param string                               $url   Trusted service URL.
	 * @param string                               $body  JSON body.
	 * @param Mtuc_Certificate_Consumer_Lease|null $lease Optional mTLS lease.
	 * @return array<int, mixed>
	 */
	public static function build_session_curl_options( string $url, string $body, $lease = null ): array {
		$curl_options = array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_MAXREDIRS      => 0,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'cache-control: no-cache',
			),
		);

		if ( $lease instanceof Mtuc_Certificate_Consumer_Lease ) {
			$curl_options[ CURLOPT_SSLKEY ]        = $lease->get_key_path();
			$curl_options[ CURLOPT_SSLKEYPASSWD ]  = MTUC_SSL_PASSWD;
			$curl_options[ CURLOPT_SSLCERT ]       = $lease->get_cert_path();
			$curl_options[ CURLOPT_SSLCERTPASSWD ] = MTUC_SSL_PASSWD;
			$curl_options[ CURLOPT_SSLVERSION ]    = CURL_SSLVERSION_TLSv1_2;
		}

		return $curl_options;
	}

	/**
	 * Start SmartUCF online session.
	 *
	 * Order: trusted endpoint resolution → certificate sync/lease (if enabled) → cURL → trusted redirect.
	 * Untrusted endpoints are PRE-SEND (no sync, no lease, no HTTP).
	 *
	 * @param array<string, mixed> $payload Session request body.
	 * @param array<string, mixed> $shop    Shop `data` object from CP.
	 * @return array{session_id: string, redirect_url: string}|WP_Error
	 */
	public static function start_session( array $payload, array $shop ) {
		$url = Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop );
		if ( is_wp_error( $url ) ) {
			return $url;
		}

		$use_certificate = mtuc_is_yes_flag( $shop['uni_sertificat'] ?? 0 );
		$lease           = null;

		if ( $use_certificate ) {
			$lease = is_callable( self::$certificate_synchronizer )
				? call_user_func( self::$certificate_synchronizer, $shop )
				: Mtuc_Certificate_Synchronizer::ensure_current( $shop );
			if ( is_wp_error( $lease ) ) {
				return $lease;
			}
			if ( ! $lease instanceof Mtuc_Certificate_Consumer_Lease ) {
				return new WP_Error(
					'mtuc_smartucf_missing_ssl',
					__( 'Липсват SSL ключ или сертификат за SmartUCF.', 'mtunicredit' )
				);
			}
		}

		try {
			$body = wp_json_encode( $payload );
			if ( ! is_string( $body ) ) {
				return new WP_Error(
					'mtuc_smartucf_encode_failed',
					__( 'Неуспешно кодиране на заявката към SmartUCF.', 'mtunicredit' )
				);
			}

			if ( ! function_exists( 'curl_init' ) && ! is_callable( self::$http_transport ) ) {
				return new WP_Error(
					'mtuc_smartucf_curl_missing',
					__( 'PHP разширението cURL не е налично на сървъра.', 'mtunicredit' )
				);
			}

			$curl_options = self::build_session_curl_options( $url, $body, $lease );

			if ( is_callable( self::$http_transport ) ) {
				$transport = call_user_func( self::$http_transport, $curl_options );
				$response_body = isset( $transport['body'] ) ? (string) $transport['body'] : '';
				$curl_error    = isset( $transport['curl_error'] ) ? (string) $transport['curl_error'] : '';
				$http_code     = isset( $transport['http_code'] ) ? (int) $transport['http_code'] : 0;
			} else {
				$handle = curl_init();
				if ( false === $handle ) {
					return new WP_Error(
						'mtuc_smartucf_curl_init',
						__( 'Неуспешна инициализация на връзката към SmartUCF.', 'mtunicredit' )
					);
				}

				curl_setopt_array( $handle, $curl_options );
				$response_body = curl_exec( $handle );
				$curl_error    = curl_error( $handle );
				$http_code     = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
				curl_close( $handle );
			}

			$wc_order_id = isset( $payload['orderNo'] ) ? (int) $payload['orderNo'] : 0;
			$log_body    = is_string( $response_body ) && '' !== $response_body
				? $response_body
				: wp_json_encode(
					array(
						'curl_error' => $curl_error,
						'http_code'  => $http_code,
					)
				);
			Mtuc_Debug_Log::log_smartucf_session(
				$body,
				is_string( $log_body ) ? $log_body : '{}',
				$http_code,
				$wc_order_id
			);

			if ( '' !== $curl_error ) {
				return new WP_Error(
					'mtuc_smartucf_http_error',
					sprintf(
						/* translators: %s: curl error message */
						__( 'Грешка при връзка със SmartUCF: %s', 'mtunicredit' ),
						$curl_error
					)
				);
			}

			if ( ! is_string( $response_body ) || '' === $response_body ) {
				return new WP_Error(
					'mtuc_smartucf_empty_response',
					__( 'SmartUCF върна празен отговор.', 'mtunicredit' )
				);
			}

			$decoded = json_decode( $response_body );
			if ( ! is_object( $decoded ) ) {
				return new WP_Error(
					'mtuc_smartucf_invalid_json',
					__( 'Невалиден отговор от SmartUCF.', 'mtunicredit' )
				);
			}

			$session_id = isset( $decoded->sucfOnlineSessionID ) ? trim( (string) $decoded->sucfOnlineSessionID ) : '';
			if ( '' === $session_id ) {
				return new WP_Error(
					'mtuc_smartucf_no_session',
					__( 'SmartUCF не върна идентификатор на сесия.', 'mtunicredit' )
				);
			}

			$redirect_url = Mtuc_Smartucf_Endpoint_Policy::resolve_application_redirect_url( $shop, $session_id );
			if ( is_wp_error( $redirect_url ) ) {
				return $redirect_url;
			}

			return array(
				'session_id'   => $session_id,
				'redirect_url' => $redirect_url,
			);
		} finally {
			if ( $lease instanceof Mtuc_Certificate_Consumer_Lease ) {
				$lease->release();
			}
		}
	}
}
