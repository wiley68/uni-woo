<?php
/**
 * Centralized error normalization and customer-safe messages (AUD-WOO-012).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Customer message: generic financing outage. */
const MTUC_CUSTOMER_MSG_FINANCING_UNAVAILABLE = 'mtuc_customer_financing_unavailable';

/** Customer message: configuration temporarily unavailable. */
const MTUC_CUSTOMER_MSG_CONFIGURATION_UNAVAILABLE = 'mtuc_customer_configuration_unavailable';

/** Customer message: request could not be completed. */
const MTUC_CUSTOMER_MSG_REQUEST_FAILED = 'mtuc_customer_request_failed';

/**
 * WP_Error codes whose messages are safe to show directly to customers (business validation).
 *
 * @return list<string>
 */
function mtuc_get_customer_safe_error_codes(): array {
	return array(
		'mtuc_invalid_quantity',
		'mtuc_product_not_purchasable',
		'mtuc_product_out_of_stock',
		'mtuc_sold_individually',
		'mtuc_currency_mismatch',
		'mtuc_transaction_currency_incompatible',
		'mtuc_price_out_of_range',
		'mtuc_cart_empty',
		'mtuc_invalid_scheme',
		'mtuc_invalid_months',
		'mtuc_invalid_offer_type',
		'mtuc_missing_consent',
		'mtuc_invalid_customer',
		'mtuc_invalid_egn',
		'mtuc_invalid_phone',
		'mtuc_invalid_email',
		'mtuc_process2_required_field',
		'mtuc_operation_scope_mismatch',
		'mtuc_operation_contention',
		'mtuc_missing_operation_token',
		'mtuc_invalid_operation_token',
	);
}

/**
 * Normalize a WP_Error or Exception into a diagnostic array (no secrets/PII).
 *
 * @param WP_Error|Throwable|mixed $error Error instance.
 * @param string                   $subsystem cp|smartucf|certificate|configuration|sync|general.
 * @return array{category:string,subsystem:string,retryable:bool,code:string,message_key:string}
 */
function mtuc_normalize_error( $error, string $subsystem = 'general' ): array {
	$code       = 'unexpected_error';
	$wp_code    = '';
	$retryable  = false;
	$category   = 'unexpected_error';

	if ( $error instanceof WP_Error ) {
		$wp_code  = $error->get_error_code();
		$category = mtuc_classify_wp_error_category( $error, $subsystem );
		$retryable = mtuc_is_error_retryable_category( $category );
	} elseif ( $error instanceof Throwable ) {
		$wp_code  = 'exception';
		$category = 'unexpected_error';
	}

	return array(
		'category'    => $category,
		'subsystem'   => sanitize_key( $subsystem ),
		'retryable'   => $retryable,
		'code'        => sanitize_key( $wp_code !== '' ? $wp_code : $category ),
		'message_key' => mtuc_customer_message_key_for_category( $category, $wp_code ),
	);
}

/**
 * Classify WP_Error into normalized category.
 *
 * @param WP_Error $error     Error.
 * @param string   $subsystem Subsystem hint.
 * @return string
 */
function mtuc_classify_wp_error_category( WP_Error $error, string $subsystem ): string {
	$wp_code = $error->get_error_code();

	if ( function_exists( 'mtuc_is_cp_transport_ambiguous_error' ) && mtuc_is_cp_transport_ambiguous_error( $error ) ) {
		return 'cp_timeout';
	}

	if ( 'http_request_failed' === $wp_code ) {
		return 'smartucf' === $subsystem ? 'smartucf_transport' : 'cp_network';
	}

	if ( 'mtuc_api_invalid_json' === $wp_code ) {
		return 'cp_server';
	}

	if ( 'mtuc_api_http_error' === $wp_code ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 401 === $status || 403 === $status ) {
			return 'cp_auth';
		}
		if ( 404 === $status ) {
			return 'cp_auth';
		}
		if ( 422 === $status || 400 === $status ) {
			return 'cp_validation';
		}
		if ( $status >= 500 ) {
			return 'cp_server';
		}
		return 'cp_validation';
	}

	if ( in_array( $wp_code, array( 'mtuc_api_missing_credentials', 'mtuc_api_no_access_token', 'mtuc_api_refresh_failed' ), true ) ) {
		return 'cp_auth';
	}

	if ( 0 === strpos( $wp_code, 'mtuc_cache_' ) ) {
		return 'configuration_error';
	}

	if ( 0 === strpos( $wp_code, 'mtuc_ssl_' ) || 0 === strpos( $wp_code, 'mtuc_smartucf_ssl' ) ) {
		return 'certificate_error';
	}

	if ( 0 === strpos( $wp_code, 'mtuc_smartucf_' ) ) {
		return 'smartucf_transport';
	}

	if ( 'mtuc_cp_sync_max_attempts' === $wp_code || 0 === strpos( $wp_code, 'mtuc_cp_sync' ) ) {
		return 'sync_error';
	}

	if ( in_array( $wp_code, mtuc_get_customer_safe_error_codes(), true ) ) {
		return 'validation';
	}

	return 'unexpected_error';
}

/**
 * Whether a normalized category is retryable.
 *
 * @param string $category Category slug.
 * @return bool
 */
function mtuc_is_error_retryable_category( string $category ): bool {
	return in_array(
		$category,
		array(
			'cp_timeout',
			'cp_network',
			'cp_server',
			'smartucf_timeout',
			'smartucf_transport',
			'sync_error',
			'configuration_error',
		),
		true
	);
}

/**
 * Map category to customer message key.
 *
 * @param string $category Normalized category.
 * @param string $wp_code  Original WP_Error code.
 * @return string
 */
function mtuc_customer_message_key_for_category( string $category, string $wp_code = '' ): string {
	if ( in_array( $wp_code, mtuc_get_customer_safe_error_codes(), true ) ) {
		return 'validation';
	}

	if ( 'configuration_error' === $category ) {
		return MTUC_CUSTOMER_MSG_CONFIGURATION_UNAVAILABLE;
	}

	if ( in_array( $category, array( 'validation', 'cp_validation' ), true ) && '' !== $wp_code ) {
		return 'validation';
	}

	if ( in_array( $category, array( 'cp_auth', 'certificate_error' ), true ) ) {
		return MTUC_CUSTOMER_MSG_FINANCING_UNAVAILABLE;
	}

	return MTUC_CUSTOMER_MSG_REQUEST_FAILED;
}

/**
 * Customer-safe localized message for an error.
 *
 * @param WP_Error|Throwable|mixed $error Error.
 * @param string                   $subsystem Subsystem hint.
 * @return string
 */
function mtuc_customer_message_from_error( $error, string $subsystem = 'general' ): string {
	if ( $error instanceof WP_Error && in_array( $error->get_error_code(), mtuc_get_customer_safe_error_codes(), true ) ) {
		return $error->get_error_message();
	}

	$normalized = mtuc_normalize_error( $error, $subsystem );
	$key        = $normalized['message_key'];

	if ( 'validation' === $key && $error instanceof WP_Error ) {
		return $error->get_error_message();
	}

	switch ( $key ) {
		case MTUC_CUSTOMER_MSG_CONFIGURATION_UNAVAILABLE:
			return __( 'Конфигурацията за финансиране временно не е налична. Моля, опитайте по-късно.', 'mtunicredit' );
		case MTUC_CUSTOMER_MSG_FINANCING_UNAVAILABLE:
			return __( 'Финансирането временно не е налично. Моля, опитайте по-късно.', 'mtunicredit' );
		case MTUC_CUSTOMER_MSG_REQUEST_FAILED:
		default:
			return __( 'Заявката не може да бъде завършена. Моля, опитайте отново.', 'mtunicredit' );
	}
}

/**
 * Send a customer-safe JSON error response.
 *
 * @param WP_Error $error  Error.
 * @param int      $status HTTP status.
 * @param string   $subsystem Subsystem for normalization.
 * @return never
 */
function mtuc_send_customer_safe_json_error( WP_Error $error, int $status = 400, string $subsystem = 'general' ): void {
	wp_send_json_error(
		array(
			'message' => mtuc_customer_message_from_error( $error, $subsystem ),
		),
		$status
	);
}

/**
 * Add a customer-safe WooCommerce notice from an error.
 *
 * @param WP_Error $error Error.
 * @param string   $subsystem Subsystem hint.
 * @return void
 */
function mtuc_add_customer_safe_notice( WP_Error $error, string $subsystem = 'general' ): void {
	if ( ! function_exists( 'wc_add_notice' ) ) {
		return;
	}

	wc_add_notice( mtuc_customer_message_from_error( $error, $subsystem ), 'error' );
}
