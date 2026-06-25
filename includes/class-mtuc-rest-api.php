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

	/** Route for CP to fetch SmartUCF debug log by order number. */
	public const ROUTE_SMARTUCF_DEBUG_LOG = '/smartucf-debug-log';

	/** Route for CP-initiated bank status updates on shop orders. */
	public const ROUTE_ORDER_BANK_STATUS = '/order-bank-status';

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
	 * Public URL for CP to fetch SmartUCF debug log for an order.
	 *
	 * @return string
	 */
	public static function get_smartucf_debug_log_url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE_SMARTUCF_DEBUG_LOG );
	}

	/**
	 * Public URL for CP to push bank status updates to shop orders.
	 *
	 * @return string
	 */
	public static function get_order_bank_status_url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE_ORDER_BANK_STATUS );
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

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_SMARTUCF_DEBUG_LOG,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_smartucf_debug_log_fetch' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			self::ROUTE_ORDER_BANK_STATUS,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle_order_bank_status_push' ),
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
	 * POST /smartucf-debug-log — CP fetches SmartUCF request/response for an order.
	 *
	 * Expected JSON body:
	 * {
	 *   "unicid": "<store unicid>",
	 *   "secret": "<store secret>",
	 *   "order_id": "<shop order number sent to CP>"
	 * }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_smartucf_debug_log_fetch( WP_REST_Request $request ): WP_REST_Response {
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

		$unicid   = isset( $params['unicid'] ) ? sanitize_text_field( (string) $params['unicid'] ) : '';
		$secret   = isset( $params['secret'] ) ? sanitize_text_field( (string) $params['secret'] ) : '';
		$order_id = isset( $params['order_id'] ) ? sanitize_text_field( (string) $params['order_id'] ) : '';

		$auth = self::verify_credentials( $unicid, $secret );
		if ( is_wp_error( $auth ) ) {
			return self::error_response( $auth->get_error_message(), 401 );
		}

		if ( '' === $order_id ) {
			return self::error_response(
				__( 'Липсва order_id в заявката.', 'mtunicredit' ),
				400
			);
		}

		if ( ! function_exists( 'mtuc_find_order_by_cp_order_id' ) ) {
			return self::error_response(
				__( 'WooCommerce не е наличен.', 'mtunicredit' ),
				500
			);
		}

		$order = mtuc_find_order_by_cp_order_id( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return self::error_response(
				__( 'Поръчката не е намерена в магазина.', 'mtunicredit' ),
				404
			);
		}

		$entry = Mtuc_Debug_Log::get_entry_for_wc_order_id( $order->get_id() );
		if ( null === $entry ) {
			return self::error_response(
				__( 'Няма запис в дебъг журнала за тази поръчка.', 'mtunicredit' ),
				404
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'data'    => array(
					'order_id'    => mtuc_get_cp_shop_order_id( $order ),
					'wc_order_id' => $order->get_id(),
					'log'         => $entry,
				),
			),
			200
		);
	}

	/**
	 * POST /order-bank-status — CP pushes bank status update for a shop order.
	 *
	 * Expected JSON body:
	 * {
	 *   "unicid": "<store unicid>",
	 *   "secret": "<store secret>",
	 *   "order_id": "<shop order number sent to CP>",
	 *   "status": "<human-readable status label>",
	 *   "status_id": "<machine-readable status key>"
	 * }
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_order_bank_status_push( WP_REST_Request $request ): WP_REST_Response {
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

		$unicid    = isset( $params['unicid'] ) ? sanitize_text_field( (string) $params['unicid'] ) : '';
		$secret    = isset( $params['secret'] ) ? sanitize_text_field( (string) $params['secret'] ) : '';
		$order_id  = isset( $params['order_id'] ) ? sanitize_text_field( (string) $params['order_id'] ) : '';
		$status    = isset( $params['status'] ) ? sanitize_text_field( (string) $params['status'] ) : '';
		$status_id = isset( $params['status_id'] ) ? sanitize_key( (string) $params['status_id'] ) : '';

		$auth = self::verify_credentials( $unicid, $secret );
		if ( is_wp_error( $auth ) ) {
			return self::error_response( $auth->get_error_message(), 401 );
		}

		if ( '' === $order_id ) {
			return self::error_response(
				__( 'Липсва order_id в заявката.', 'mtunicredit' ),
				400
			);
		}

		if ( '' === $status_id ) {
			return self::error_response(
				__( 'Липсва status_id в заявката.', 'mtunicredit' ),
				400
			);
		}

		if ( ! function_exists( 'mtuc_find_order_by_cp_order_id' ) || ! function_exists( 'mtuc_apply_cp_bank_status_push' ) ) {
			return self::error_response(
				__( 'WooCommerce не е наличен.', 'mtunicredit' ),
				500
			);
		}

		$order = mtuc_find_order_by_cp_order_id( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return self::error_response(
				__( 'Поръчката не е намерена в магазина.', 'mtunicredit' ),
				404
			);
		}

		$result = mtuc_apply_cp_bank_status_push( $order, $status_id, $status );
		if ( is_wp_error( $result ) ) {
			return self::error_response( $result->get_error_message(), 400 );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Банковият статус на поръчката е обновен успешно.', 'mtunicredit' ),
				'data'    => array(
					'order_id'    => mtuc_get_cp_shop_order_id( $order ),
					'wc_order_id' => $order->get_id(),
					'status'      => '' !== $status ? $status : (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'bank_status_label' ),
					'status_id'   => $status_id,
				),
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
