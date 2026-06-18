<?php
/**
 * REST API endpoints for CP → shop communication.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incoming webhooks from the UniCredit Control Panel.
 */
class Mtuc_Rest_Api {

	/** REST namespace. */
	public const NAMESPACE = 'mtunicredit/v1';

	/** Route for CP-initiated shop cache updates. */
	public const ROUTE_SHOP_CACHE = '/shop-cache';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Public URL for CP to push shop cache updates.
	 *
	 * @return string
	 */
	public static function get_shop_cache_url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE_SHOP_CACHE );
	}

	/**
	 * Register REST routes.
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_SHOP_CACHE,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_shop_cache_push' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST /shop-cache — CP pushes fresh shop `data` to update local cache.
	 *
	 * Expected JSON body:
	 * {
	 *   "unicid": "<store unicid>",
	 *   "secret": "<store secret>",
	 *   "data": { ... same object as GET /shop `data` ... }
	 * }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_shop_cache_push( WP_REST_Request $request ): WP_REST_Response {
		if ( ! Mtuc_Settings::is_enabled() ) {
			return self::error_response(
				__( 'Модулът е изключен.', 'mtunicredit' ),
				403
			);
		}

		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$unicid = isset( $params['unicid'] ) ? sanitize_text_field( (string) $params['unicid'] ) : '';
		$secret = isset( $params['secret'] ) ? sanitize_text_field( (string) $params['secret'] ) : '';

		$auth = self::verify_credentials( $unicid, $secret );
		if ( is_wp_error( $auth ) ) {
			return self::error_response( $auth->get_error_message(), 401 );
		}

		$data = self::extract_shop_data( $params );
		if ( is_wp_error( $data ) ) {
			return self::error_response( $data->get_error_message(), 400 );
		}

		if ( isset( $data['unicid'] ) && (string) $data['unicid'] !== $unicid ) {
			return self::error_response(
				__( 'unicid в данните не съвпада с подадения идентификатор.', 'mtunicredit' ),
				400
			);
		}

		$result = Mtuc_Shop_Cache::update_from_cp_push( $unicid, $data );
		if ( is_wp_error( $result ) ) {
			return self::error_response( $result->get_error_message(), 400 );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Кешът на shop данни е обновен успешно.', 'mtunicredit' ),
				'data'    => $result,
			),
			200
		);
	}

	/**
	 * Validate unicid + secret against module settings.
	 *
	 * @param string $unicid Request unicid.
	 * @param string $secret Request secret.
	 * @return true|WP_Error
	 */
	private static function verify_credentials( string $unicid, string $secret ) {
		$stored_unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );
		$stored_secret = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_SECRET_KEY );

		if ( '' === $stored_unicid || '' === $stored_secret ) {
			return new WP_Error(
				'mtuc_not_configured',
				__( 'Модулът не е конфигуриран с unicid и секретен код.', 'mtunicredit' )
			);
		}

		if ( '' === $unicid || '' === $secret ) {
			return new WP_Error(
				'mtuc_missing_credentials',
				__( 'Липсват unicid или secret в заявката.', 'mtunicredit' )
			);
		}

		if ( ! hash_equals( $stored_unicid, $unicid ) || ! hash_equals( $stored_secret, $secret ) ) {
			return new WP_Error(
				'mtuc_invalid_credentials',
				__( 'Невалидни unicid или secret.', 'mtunicredit' )
			);
		}

		return true;
	}

	/**
	 * Extract shop `data` object from request payload.
	 *
	 * @param array<string, mixed> $params Request JSON.
	 * @return array<string, mixed>|WP_Error
	 */
	private static function extract_shop_data( array $params ) {
		if ( isset( $params['data'] ) && is_array( $params['data'] ) && ! empty( $params['data'] ) ) {
			return $params['data'];
		}

		return new WP_Error(
			'mtuc_invalid_shop_payload',
			__( 'Липсва или е невалидно полето data в заявката.', 'mtunicredit' )
		);
	}

	/**
	 * Build a JSON error response in CP style.
	 *
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @return WP_REST_Response
	 */
	private static function error_response( string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $message,
			),
			$status
		);
	}
}
