<?php
/**
 * WordPress REST API stubs for IDE (not loaded at runtime).
 *
 * @package MTUC
 */

/**
 * @param string $path
 * @param string $scheme
 * @return string
 */
function rest_url( $path = '', $scheme = 'rest' ): string {
	unset( $scheme );

	return '';
}

/**
 * @param string               $route_namespace
 * @param string               $route
 * @param array<string, mixed> $args
 * @param bool                 $override
 * @return bool
 */
function register_rest_route( $route_namespace, $route, $args = array(), $override = false ): bool {
	unset( $route_namespace, $route, $args, $override );

	return true;
}

class WP_REST_Server {
	public const READABLE   = 'GET';
	public const CREATABLE  = 'POST';
	public const EDITABLE   = 'POST, PUT, PATCH';
	public const DELETABLE  = 'DELETE';
	public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
}

class WP_REST_Request {
	/** @return mixed */
	public function get_json_params() {}

	/** @return mixed */
	public function get_param( $key ) {}

	/**
	 * Raw request body.
	 *
	 * @return string
	 */
	public function get_body(): string {
		return '';
	}

	/**
	 * Request headers (WordPress stores keys as `x_unipayment_timestamp`).
	 *
	 * @return array<string, array<int, string>>
	 */
	public function get_headers(): array {
		return array();
	}

	/**
	 * Single header value. Lookup is canonicalized (`X-UniPayment-Timestamp` works).
	 *
	 * @param string $key Header name.
	 * @return string|null
	 */
	public function get_header( $key ) {
		unset( $key );

		return null;
	}
}

class WP_REST_Response {
	/**
	 * @param mixed $data
	 * @param int   $status
	 */
	public function __construct( $data = null, $status = 200 ) {}
}
