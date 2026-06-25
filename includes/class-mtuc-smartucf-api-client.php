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
	 * Whether shop runs against SmartUCF test environment.
	 *
	 * @param array<string, mixed> $shop Shop `data` object from CP.
	 * @return bool
	 */
	public static function is_test_environment( array $shop ): bool {
		return 0 === (int) ( $shop['uni_env'] ?? 1 );
	}

	/**
	 * SmartUCF API endpoint for session start.
	 *
	 * @param array<string, mixed> $shop Shop `data` object from CP.
	 * @return string
	 */
	public static function get_service_url( array $shop ): string {
		$base = self::is_test_environment( $shop )
			? (string) ( $shop['uni_test_service'] ?? '' )
			: (string) ( $shop['uni_production_service'] ?? '' );

		return trailingslashit( $base ) . 'sucfOnlineSessionStart';
	}

	/**
	 * Browser redirect URL after successful session start.
	 *
	 * @param array<string, mixed> $shop       Shop `data` object from CP.
	 * @param string               $session_id sucfOnlineSessionID value.
	 * @return string
	 */
	public static function get_application_redirect_url( array $shop, string $session_id ): string {
		$base = self::is_test_environment( $shop )
			? (string) ( $shop['uni_test_application'] ?? '' )
			: (string) ( $shop['uni_production_application'] ?? '' );

		return untrailingslashit( $base ) . '/' . ltrim( $session_id, '/' );
	}

	/**
	 * Absolute path to plugin SSL key file.
	 *
	 * @return string
	 */
	public static function get_ssl_key_path(): string {
		return MTUC_PLUGIN_DIR . '/keys/avalon_private_key.pem';
	}

	/**
	 * Absolute path to plugin SSL certificate file.
	 *
	 * @return string
	 */
	public static function get_ssl_cert_path(): string {
		return MTUC_PLUGIN_DIR . '/keys/avalon_cert.pem';
	}

	/**
	 * Start SmartUCF online session.
	 *
	 * @param array<string, mixed> $payload Session request body.
	 * @param array<string, mixed> $shop    Shop `data` object from CP.
	 * @return array{session_id: string, redirect_url: string}|WP_Error
	 */
	public static function start_session( array $payload, array $shop ) {
		$url = self::get_service_url( $shop );
		if ( '' === $url || 'sucfOnlineSessionStart' === $url ) {
			return new WP_Error(
				'mtuc_smartucf_missing_service',
				__( 'Липсва URL на SmartUCF услугата в настройките на магазина.', 'mtunicredit' )
			);
		}

		$use_certificate = mtuc_is_yes_flag( $shop['uni_sertificat'] ?? 0 );
		if ( $use_certificate ) {
			$key_path  = self::get_ssl_key_path();
			$cert_path = self::get_ssl_cert_path();
			if ( ! is_readable( $key_path ) || ! is_readable( $cert_path ) ) {
				return new WP_Error(
					'mtuc_smartucf_missing_ssl',
					__( 'Липсват SSL ключ или сертификат за SmartUCF.', 'mtunicredit' )
				);
			}
		}

		$body = wp_json_encode( $payload );
		if ( ! is_string( $body ) ) {
			return new WP_Error(
				'mtuc_smartucf_encode_failed',
				__( 'Неуспешно кодиране на заявката към SmartUCF.', 'mtunicredit' )
			);
		}

		if ( ! function_exists( 'curl_init' ) ) {
			return new WP_Error(
				'mtuc_smartucf_curl_missing',
				__( 'PHP разширението cURL не е налично на сървъра.', 'mtunicredit' )
			);
		}

		$curl_options = array(
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => '',
			CURLOPT_MAXREDIRS      => 2,
			CURLOPT_TIMEOUT        => self::TIMEOUT,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => 'POST',
			CURLOPT_POSTFIELDS     => $body,
			CURLOPT_HTTPHEADER     => array(
				'Content-Type: application/json',
				'cache-control: no-cache',
			),
		);

		if ( $use_certificate ) {
			$curl_options[ CURLOPT_SSLKEY ]        = self::get_ssl_key_path();
			$curl_options[ CURLOPT_SSLKEYPASSWD ]  = MTUC_SSL_PASSWD;
			$curl_options[ CURLOPT_SSLCERT ]       = self::get_ssl_cert_path();
			$curl_options[ CURLOPT_SSLCERTPASSWD ] = MTUC_SSL_PASSWD;
			$curl_options[ CURLOPT_SSLVERSION ]    = CURL_SSLVERSION_TLSv1_2;
		}

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

		$wc_order_id = isset( $payload['orderNo'] ) ? (int) $payload['orderNo'] : 0;
		$log_body    = is_string( $response_body ) && '' !== $response_body
			? $response_body
			: wp_json_encode(
				array(
					'curl_error' => $curl_error,
					'http_code'  => $http_code,
				)
			);
		Mtuc_Debug_Log::log_response(
			Mtuc_Debug_Log::TYPE_SMARTUCF,
			is_string( $log_body ) ? $log_body : '{}',
			$http_code,
			$wc_order_id,
			$body
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

		$redirect_url = self::get_application_redirect_url( $shop, $session_id );
		if ( '' === $redirect_url || '/' === $redirect_url ) {
			return new WP_Error(
				'mtuc_smartucf_missing_application',
				__( 'Липсва URL на SmartUCF приложението в настройките на магазина.', 'mtunicredit' )
			);
		}

		return array(
			'session_id'   => $session_id,
			'redirect_url' => $redirect_url,
		);
	}
}
