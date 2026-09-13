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

		/*
		 * REVIEW-04: bound the raw body before WordPress reads php://input.
		 * rest_api_loaded() is hooked to parse_request at the default priority
		 * 10, so priority 0 is the last point at which the ceiling can still be
		 * the real one rather than an after-the-fact check.
		 */
		add_action( 'parse_request', 'mtuc_inbound_rest_body_gate', 0, 1 );
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
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_shop_cache_push( WP_REST_Request $request ): WP_REST_Response {
		$body = self::read_bounded_body( $request );
		if ( is_wp_error( $body ) ) {
			return self::error_from_wp_error( $body );
		}

		$params = self::decode_body( $body );

		$auth = self::authenticate_request( $request, $body, $params );
		if ( is_wp_error( $auth ) ) {
			return self::error_from_wp_error( $auth );
		}

		$unicid = (string) $auth;

		$operation = mtuc_validate_inbound_operation( $params, MTUC_INBOUND_OPERATION_SHOP_CACHE );
		if ( is_wp_error( $operation ) ) {
			return self::error_from_wp_error( $operation );
		}

		$result = Mtuc_Shop_Cache::update_from_cp_push( $unicid, isset( $params['data'] ) ? $params['data'] : null );
		if ( is_wp_error( $result ) ) {
			return self::error_from_wp_error( $result );
		}

		return self::success_response(
			__( 'Кешът на shop данни е обновен успешно.', 'mtunicredit' ),
			is_array( $result ) ? $result : array()
		);
	}

	/**
	 * POST /smartucf-debug-log — CP fetches SmartUCF request/response for an order.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_smartucf_debug_log_fetch( WP_REST_Request $request ): WP_REST_Response {
		$body = self::read_bounded_body( $request );
		if ( is_wp_error( $body ) ) {
			return self::error_from_wp_error( $body );
		}

		$params = self::decode_body( $body );

		$auth = self::authenticate_request( $request, $body, $params );
		if ( is_wp_error( $auth ) ) {
			return self::error_from_wp_error( $auth );
		}

		$unicid = (string) $auth;

		$operation = mtuc_validate_inbound_operation( $params, MTUC_INBOUND_OPERATION_SMARTUCF_DEBUG_LOG );
		if ( is_wp_error( $operation ) ) {
			return self::error_from_wp_error( $operation );
		}

		$validated = mtuc_validate_smartucf_debug_log_body( $params );
		if ( is_wp_error( $validated ) ) {
			return self::error_from_wp_error( $validated );
		}

		if ( ! function_exists( 'mtuc_resolve_financing_order' ) ) {
			return self::error_response( 'internal_error', __( 'WooCommerce не е наличен.', 'mtunicredit' ), 500 );
		}

		$order = mtuc_resolve_financing_order( $validated['order_id'], $unicid );
		if ( is_wp_error( $order ) ) {
			return self::error_from_wp_error( $order );
		}

		// F06: P1 identity + SmartUCF lifecycle ownership; all denials are opaque.
		$authorized = mtuc_authorize_smartucf_debug_read( $order );
		if ( is_wp_error( $authorized ) ) {
			return self::error_from_wp_error( $authorized );
		}

		$entry = Mtuc_Debug_Log::get_entry_for_wc_order_id( $order->get_id() );
		if ( null === $entry ) {
			return self::error_from_wp_error( mtuc_financing_order_not_found_error( 'debug_journal_absent' ) );
		}

		return self::success_response(
			__( 'Записът от дебъг журнала е върнат успешно.', 'mtunicredit' ),
			array(
				'order_id'    => mtuc_get_cp_shop_order_id( $order ),
				'wc_order_id' => (string) $order->get_id(),
				'log'         => $entry,
			)
		);
	}

	/** Maximum sanitized length for callback status_id. */
	public const BANK_STATUS_ID_MAX_LEN = 64;

	/** Maximum sanitized length for callback status label. */
	public const BANK_STATUS_LABEL_MAX_LEN = 255;

	/**
	 * Whether a callback field value is an accepted scalar for bank status fields.
	 *
	 * Rejects arrays/objects/resources/bools before string casting (AUD-WOO-015-F05).
	 *
	 * @param mixed $value Raw payload value.
	 * @return bool
	 */
	public static function is_bank_status_field_scalar( $value ): bool {
		if ( is_string( $value ) || is_int( $value ) ) {
			return true;
		}
		if ( is_float( $value ) && is_finite( $value ) ) {
			return true;
		}

		return false;
	}

	/**
	 * POST /order-bank-status — CP pushes bank status update for a shop order.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public static function handle_order_bank_status_push( WP_REST_Request $request ): WP_REST_Response {
		$body = self::read_bounded_body( $request );
		if ( is_wp_error( $body ) ) {
			return self::error_from_wp_error( $body );
		}

		$params = self::decode_body( $body );

		$auth = self::authenticate_request( $request, $body, $params );
		if ( is_wp_error( $auth ) ) {
			return self::error_from_wp_error( $auth );
		}

		$unicid = (string) $auth;

		$operation = mtuc_validate_inbound_operation( $params, MTUC_INBOUND_OPERATION_ORDER_BANK_STATUS );
		if ( is_wp_error( $operation ) ) {
			return self::error_from_wp_error( $operation );
		}

		$validated = mtuc_validate_order_bank_status_body( $params );
		if ( is_wp_error( $validated ) ) {
			return self::error_from_wp_error( $validated );
		}

		if ( ! function_exists( 'mtuc_resolve_financing_order' ) || ! function_exists( 'mtuc_apply_cp_bank_status_push' ) ) {
			return self::error_response( 'internal_error', __( 'WooCommerce не е наличен.', 'mtunicredit' ), 500 );
		}

		$order = mtuc_resolve_financing_order( $validated['order_id'], $unicid );
		if ( is_wp_error( $order ) ) {
			return self::error_from_wp_error( $order );
		}

		/*
		 * REVIEW-07: the validator already decided these bytes are acceptable.
		 * Re-sanitising here would silently store a different status than the
		 * one CP sent, so the validated strings are passed through verbatim.
		 */
		$status_id = $validated['status_id'];
		$status    = $validated['status'];

		$result = mtuc_apply_cp_bank_status_push( $order, $status_id, $status );
		if ( is_wp_error( $result ) ) {
			return self::error_from_wp_error( self::map_bank_status_push_error( $result ) );
		}

		return self::success_response(
			__( 'Банковият статус на поръчката е обновен успешно.', 'mtunicredit' ),
			array(
				'order_id'    => mtuc_get_cp_shop_order_id( $order ),
				'wc_order_id' => (string) $order->get_id(),
				'status'      => '' !== $status ? $status : (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'bank_status_label' ),
				'status_id'   => $status_id,
			)
		);
	}

	/**
	 * Map a bank-status rejection onto the canonical inbound error contract.
	 *
	 * Identity/transition refusals are semantic conflicts (409); everything else
	 * that reached this point is a validation failure (422).
	 *
	 * @param WP_Error $error Rejection from the bank status state machine.
	 * @return WP_Error
	 */
	private static function map_bank_status_push_error( WP_Error $error ): WP_Error {
		$conflict_codes = array(
			'mtuc_callback_process_identity_conflict',
			'mtuc_callback_process_identity_unknown',
			'mtuc_callback_status_transition_forbidden',
			'mtuc_callback_process1_identity_required',
			'mtuc_callback_process2_identity_required',
			'mtuc_callback_cp_failure_process_mismatch',
			'mtuc_callback_smartucf_failure_process_mismatch',
			'mtuc_callback_smartucf_evidence_missing',
			'mtuc_callback_process2_evidence_missing',
			'mtuc_callback_smartucf_failure_evidence_missing',
			'mtuc_callback_cp_failure_evidence_missing',
			'mtuc_not_mtuc_order',
		);

		if ( in_array( $error->get_error_code(), $conflict_codes, true ) ) {
			return new WP_Error(
				$error->get_error_code(),
				$error->get_error_message(),
				array(
					'status' => 409,
					'error'  => 'semantic_conflict',
				)
			);
		}

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array(
				'status' => 422,
				'error'  => 'validation',
			)
		);
	}

	/**
	 * Re-check the 1 MiB ceiling at the handler (defense in depth).
	 *
	 * The real bound is the parse_request gate registered in init(): by the
	 * time a handler runs, WordPress has already read the whole body. This
	 * check still runs before HMAC verification, nonce claiming, JSON parsing
	 * and order lookup, and covers dispatch paths that bypass the gate
	 * (internal rest_do_request calls, tests).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return string|WP_Error
	 */
	private static function read_bounded_body( WP_REST_Request $request ) {
		return mtuc_read_bounded_inbound_body( (string) $request->get_body() );
	}

	/**
	 * Authenticate signed CP request before endpoint business logic.
	 *
	 * @param WP_REST_Request      $request  Incoming request.
	 * @param string               $raw_body Bounded raw request body.
	 * @param array<string, mixed> $params   Decoded request body.
	 * @return string|WP_Error
	 */
	private static function authenticate_request( WP_REST_Request $request, string $raw_body, array $params ) {
		if ( '' === $raw_body ) {
			return new WP_Error(
				'mtuc_invalid_body',
				__( 'Липсва JSON body в заявката.', 'mtunicredit' ),
				array(
					'status' => 400,
					'error'  => 'validation',
				)
			);
		}

		$headers = self::extract_signature_headers( $request );

		return Mtuc_Module_Request_Authenticator::authenticate( $params, $raw_body, $headers );
	}

	/**
	 * Signature headers from a WordPress REST request.
	 *
	 * WP_REST_Request::get_headers() stores keys as `x_unipayment_timestamp`.
	 * Prefer get_header(), which canonicalizes the lookup name.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return array<string, mixed>
	 */
	private static function extract_signature_headers( WP_REST_Request $request ): array {
		if ( method_exists( $request, 'get_header' ) ) {
			return array(
				Mtuc_Module_Request_Signature_Protocol::HEADER_TIMESTAMP => (string) $request->get_header( Mtuc_Module_Request_Signature_Protocol::HEADER_TIMESTAMP ),
				Mtuc_Module_Request_Signature_Protocol::HEADER_NONCE     => (string) $request->get_header( Mtuc_Module_Request_Signature_Protocol::HEADER_NONCE ),
				Mtuc_Module_Request_Signature_Protocol::HEADER_SIGNATURE => (string) $request->get_header( Mtuc_Module_Request_Signature_Protocol::HEADER_SIGNATURE ),
			);
		}

		return method_exists( $request, 'get_headers' ) ? (array) $request->get_headers() : array();
	}

	/**
	 * Decode the bounded raw body into request params.
	 *
	 * @param string $raw_body Bounded raw request body.
	 * @return array<string, mixed>
	 */
	private static function decode_body( string $raw_body ): array {
		$params = json_decode( $raw_body, true );

		return is_array( $params ) ? $params : array();
	}

	/**
	 * Build a canonical inbound success response (F09).
	 *
	 * @param string               $message Human-readable message.
	 * @param array<string, mixed> $data    Response data object.
	 * @return WP_REST_Response
	 */
	private static function success_response( string $message, array $data = array() ): WP_REST_Response {
		return new WP_REST_Response( mtuc_inbound_success_envelope( $message, $data ), 200 );
	}

	/**
	 * Build a canonical inbound failure response (F09).
	 *
	 * @param string               $error   Lowercase snake_case machine error code.
	 * @param string               $message Human-readable message.
	 * @param int                  $status  HTTP status code.
	 * @param array<string, mixed> $data    Response data object.
	 * @return WP_REST_Response
	 */
	private static function error_response( string $error, string $message, int $status, array $data = array() ): WP_REST_Response {
		return new WP_REST_Response( mtuc_inbound_error_envelope( $error, $message, $data ), $status );
	}

	/**
	 * Render a WP_Error as a canonical inbound failure envelope.
	 *
	 * @param WP_Error $error Error object.
	 * @return WP_REST_Response
	 */
	private static function error_from_wp_error( WP_Error $error ): WP_REST_Response {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 401;

		$code = isset( $data['error'] ) && is_string( $data['error'] )
			? $data['error']
			: self::default_error_code_for_status( $status );

		$payload = array();
		if ( isset( $data['violations'] ) && is_array( $data['violations'] ) ) {
			$payload['violations'] = array_values( $data['violations'] );
		}

		return self::error_response( $code, $error->get_error_message(), $status, $payload );
	}

	/**
	 * Canonical machine error code for a bare HTTP status.
	 *
	 * @param int $status HTTP status code.
	 * @return string
	 */
	private static function default_error_code_for_status( int $status ): string {
		$map = array(
			400 => 'validation',
			401 => 'unauthorized',
			403 => 'forbidden',
			404 => 'not_found',
			409 => 'semantic_conflict',
			413 => 'payload_too_large',
			422 => 'validation',
		);

		return $map[ $status ] ?? 'internal_error';
	}
}
