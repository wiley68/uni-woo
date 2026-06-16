<?php
/**
 * HTTP client for the UniCredit Control Panel API v1.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticated requests to CP (login, refresh, logout, shop).
 */
class Mtuc_Cp_Api_Client {

	/** Option key: Bearer access token. */
	public const OPTION_ACCESS_TOKEN = 'mtuc_api_access_token';

	/** Option key: Unix timestamp when the token expires. */
	public const OPTION_TOKEN_EXPIRES = 'mtuc_api_token_expires_at';

	/**
	 * Default HTTP timeout in seconds.
	 *
	 * @var int
	 */
	private const TIMEOUT = 15;

	/**
	 * Fetch shop configuration from CP (GET /shop).
	 *
	 * @return array<string, mixed>|WP_Error Decoded JSON body.
	 */
	public static function fetch_shop() {
		$token = self::ensure_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$response = self::request( 'GET', 'shop', null, $token );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
			self::clear_token();
			$token = self::ensure_access_token();
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$response = self::request( 'GET', 'shop', null, $token );
		}

		return self::decode_response( $response );
	}

	/**
	 * Revoke token at CP and clear local credentials.
	 *
	 * @return void
	 */
	public static function logout() {
		$token = get_option( self::OPTION_ACCESS_TOKEN, '' );
		if ( is_string( $token ) && '' !== $token ) {
			self::request( 'POST', 'auth/logout', null, $token );
		}
		self::clear_token();
	}

	/**
	 * Delete stored API token options.
	 *
	 * @return void
	 */
	public static function clear_token() {
		delete_option( self::OPTION_ACCESS_TOKEN );
		delete_option( self::OPTION_TOKEN_EXPIRES );
	}

	/**
	 * Return a valid Bearer token (login or refresh).
	 *
	 * @return string|WP_Error
	 */
	public static function ensure_access_token() {
		$token   = get_option( self::OPTION_ACCESS_TOKEN, '' );
		$expires = (int) get_option( self::OPTION_TOKEN_EXPIRES, 0 );

		if ( is_string( $token ) && '' !== $token && $expires > ( time() + 60 ) ) {
			return $token;
		}

		$refreshed = self::refresh_token();
		if ( ! is_wp_error( $refreshed ) ) {
			return $refreshed;
		}

		return self::login();
	}

	/**
	 * POST /auth/login
	 *
	 * @return string|WP_Error Access token.
	 */
	public static function login() {
		$unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );
		$secret = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_SECRET_KEY );

		if ( '' === $unicid || '' === $secret ) {
			return new WP_Error(
				'mtuc_api_missing_credentials',
				__( 'Липсват unicid или секретен ключ в настройките на модула.', 'mtunicredit' )
			);
		}

		$body = array(
			'unicid' => $unicid,
			'name'   => untrailingslashit( home_url() ),
			'secret' => $secret,
		);

		$response = self::request( 'POST', 'auth/login', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = self::decode_response( $response );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return self::store_token_from_payload( $decoded );
	}

	/**
	 * POST /auth/refresh
	 *
	 * @return string|WP_Error Access token.
	 */
	public static function refresh_token() {
		$token = get_option( self::OPTION_ACCESS_TOKEN, '' );
		if ( ! is_string( $token ) || '' === $token ) {
			return new WP_Error( 'mtuc_api_no_token', __( 'Няма запазен API токен за обновяване.', 'mtunicredit' ) );
		}

		$response = self::request( 'POST', 'auth/refresh', null, $token );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'mtuc_api_refresh_failed', __( 'API токенът не може да бъде обновен.', 'mtunicredit' ) );
		}

		$decoded = self::decode_response( $response );
		if ( is_wp_error( $decoded ) ) {
			return $decoded;
		}

		return self::store_token_from_payload( $decoded );
	}

	/**
	 * Persist token from login/refresh response.
	 *
	 * @param array<string, mixed> $payload API JSON.
	 * @return string|WP_Error
	 */
	private static function store_token_from_payload( array $payload ) {
		if ( empty( $payload['access_token'] ) || ! is_string( $payload['access_token'] ) ) {
			$message = isset( $payload['message'] ) && is_string( $payload['message'] )
				? $payload['message']
				: __( 'КП не върна access token.', 'mtunicredit' );

			return new WP_Error( 'mtuc_api_no_access_token', $message );
		}

		$expires_in = isset( $payload['expires_in'] ) ? (int) $payload['expires_in'] : DAY_IN_SECONDS;
		if ( $expires_in < 60 ) {
			$expires_in = DAY_IN_SECONDS;
		}

		update_option( self::OPTION_ACCESS_TOKEN, $payload['access_token'], false );
		update_option( self::OPTION_TOKEN_EXPIRES, time() + $expires_in, false );

		return $payload['access_token'];
	}

	/**
	 * Build full API URL.
	 *
	 * @param string $path Relative path (e.g. auth/login).
	 * @return string
	 */
	private static function api_url( string $path ): string {
		return trailingslashit( MTUC_API_BASE_URL ) . ltrim( $path, '/' );
	}

	/**
	 * Execute wp_remote_request against CP.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    API path.
	 * @param array<string, mixed>|null $body    JSON body for POST.
	 * @param string|null               $token   Bearer token.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function request( string $method, string $path, $body = null, $token = null ) {
		$headers = array(
			'Accept' => 'application/json',
		);

		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}

		if ( is_string( $token ) && '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => self::TIMEOUT,
			'redirection' => 3,
			'headers'     => $headers,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$url      = self::api_url( $path );
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			self::log_debug( 'HTTP error: ' . $response->get_error_message() . ' | ' . $url );
			return $response;
		}

		return $response;
	}

	/**
	 * Decode JSON API response.
	 *
	 * @param array<string, mixed> $response wp_remote_request response.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function decode_response( array $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'mtuc_api_invalid_json',
				__( 'Невалиден JSON отговор от Контролния панел.', 'mtunicredit' )
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			$message = '';
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				$message = $data['message'];
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$message = $data['error'];
			} else {
				$message = sprintf(
					/* translators: %d: HTTP status code */
					__( 'КП върна HTTP грешка %d.', 'mtunicredit' ),
					$code
				);
			}

			self::log_debug( 'API HTTP ' . $code . ': ' . $raw );

			return new WP_Error( 'mtuc_api_http_error', $message, array( 'status' => $code ) );
		}

		return $data;
	}

	/**
	 * Debug log when mtuc_debug is enabled.
	 *
	 * @param string $message Log line.
	 * @return void
	 */
	private static function log_debug( string $message ): void {
		if ( 1 !== (int) Mtuc_Settings::get( Mtuc_Settings::OPTION_DEBUG ) ) {
			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[mtunicredit] ' . $message );
		}
	}
}
