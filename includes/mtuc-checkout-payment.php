<?php
/**
 * Checkout payment gateway hooks and assets.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize WooCommerce order status (strip optional wc- prefix).
 *
 * @param string $status Raw status from gateway settings.
 * @return string
 */
function mtuc_normalize_wc_order_status( string $status ): string {
	$status = sanitize_key( $status );

	if ( 0 === strpos( $status, 'wc-' ) ) {
		$status = substr( $status, 3 );
	}

	return $status;
}

/**
 * Payment gateway settings stored by WooCommerce.
 *
 * @return array<string, mixed>
 */
function mtuc_get_payment_gateway_settings(): array {
	$option_key = 'woocommerce_' . MTUC_PAYMENT_GATEWAY_ID . '_settings';
	$settings   = get_option( $option_key, array() );

	return is_array( $settings ) ? $settings : array();
}

/**
 * Order status configured for the mtunicredit payment method.
 *
 * @return string Status slug without wc- prefix (default: pending).
 */
function mtuc_get_payment_gateway_order_status(): string {
	$settings = mtuc_get_payment_gateway_settings();
	$status   = isset( $settings['order_status'] )
		? mtuc_normalize_wc_order_status( (string) $settings['order_status'] )
		: 'pending';

	if ( '' === $status ) {
		return 'pending';
	}

	if ( function_exists( 'wc_get_order_statuses' ) ) {
		$valid_statuses = wc_get_order_statuses();
		$prefixed       = 'wc-' . $status;

		if ( ! isset( $valid_statuses[ $prefixed ] ) && ! isset( $valid_statuses[ $status ] ) ) {
			return 'pending';
		}
	}

	return $status;
}

/**
 * Checkout title configured for the mtunicredit payment method.
 *
 * @return string
 */
function mtuc_get_payment_gateway_title(): string {
	$settings = mtuc_get_payment_gateway_settings();
	$title    = isset( $settings['title'] ) ? trim( (string) $settings['title'] ) : '';

	if ( '' === $title ) {
		return __( 'УниКредит покупки на Кредит', 'mtunicredit' );
	}

	return $title;
}

/**
 * Mark order as paid via mtunicredit and apply configured WC status.
 *
 * @param WC_Order $order       WooCommerce order.
 * @param string   $status_note Optional order note for status change.
 * @return void
 */
function mtuc_apply_payment_gateway_to_order( WC_Order $order, string $status_note = '' ): void {
	$order->set_payment_method( MTUC_PAYMENT_GATEWAY_ID );
	$order->set_payment_method_title( mtuc_get_payment_gateway_title() );
	$order->set_status( mtuc_get_payment_gateway_order_status(), $status_note, true );
}

/**
 * Load classic payment gateway class after WooCommerce is available.
 *
 * @return bool
 */
function mtuc_load_payment_gateway_class(): bool {
	static $loaded = false;

	if ( $loaded ) {
		return class_exists( 'Mtuc_Payment_Gateway', false );
	}

	if ( ! class_exists( 'WC_Payment_Gateway', false ) ) {
		return false;
	}

	require_once MTUC_INCLUDES_DIR . '/class-mtuc-payment-gateway.php';
	$loaded = true;

	return class_exists( 'Mtuc_Payment_Gateway', false );
}

/**
 * Load blocks payment gateway integration when WooCommerce Blocks is available.
 *
 * @return bool
 */
function mtuc_load_payment_gateway_blocks_class(): bool {
	static $loaded = false;

	if ( $loaded ) {
		return class_exists( 'Mtuc_Payment_Gateway_Blocks', false );
	}

	if ( ! mtuc_load_payment_gateway_class() ) {
		return false;
	}

	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType', false ) ) {
		return false;
	}

	require_once MTUC_INCLUDES_DIR . '/class-mtuc-payment-gateway-blocks.php';
	$loaded = true;

	return class_exists( 'Mtuc_Payment_Gateway_Blocks', false );
}

/**
 * Register WooCommerce payment gateway class.
 *
 * @param array<int, string> $gateways Gateway class names.
 * @return array<int, string>
 */
function mtuc_register_payment_gateway( array $gateways ): array {
	if ( ! mtuc_load_payment_gateway_class() ) {
		return $gateways;
	}

	$gateways[] = 'Mtuc_Payment_Gateway';

	return $gateways;
}

/**
 * Build checkout payment context (cart schemes for gateway fields).
 *
 * @return array<string, mixed>|null
 */
function mtuc_get_checkout_payment_context(): ?array {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return null;
	}

	$base = mtuc_build_cart_calculator_context();
	if ( null === $base ) {
		return null;
	}

	$shop       = mtuc_get_shop_data();
	$cart_total = (float) ( $base['cart_total'] ?? 0 );

	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return null;
	}

	$popup = mtuc_get_cart_popup_context(
		$shop,
		$base,
		$cart_total,
		'checkout'
	);

	$base['popup']  = $popup;
	$base['source'] = 'checkout';

	return $base;
}

/**
 * Shared i18n strings for popup/cart/checkout calculators.
 *
 * @return array<string, string>
 */
function mtuc_get_calculator_i18n_strings(): array {
	return array(
		'calcError'      => __( 'Неуспешно изчисление. Моля, опитайте отново.', 'mtunicredit' ),
		'monthsLabel'    => __( '%d месеца', 'mtunicredit' ),
		'noMonths'       => __( 'Няма налични срокове за тази поръчка.', 'mtunicredit' ),
		'schemeRequired' => __( 'Моля, изберете схема за погасяване.', 'mtunicredit' ),
		'offerStandard'  => __( 'Стандарт', 'mtunicredit' ),
		'offerPromo'     => __( 'Промо', 'mtunicredit' ),
	);
}

/**
 * Whether the current checkout page uses the WooCommerce Checkout block.
 *
 * @return bool
 */
function mtuc_is_blocks_checkout(): bool {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return false;
	}

	if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
		return false;
	}

	$checkout_id = (int) wc_get_page_id( 'checkout' );
	if ( $checkout_id <= 0 ) {
		return false;
	}

	$post = get_post( $checkout_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	return has_block( 'woocommerce/checkout', $post );
}

/**
 * Render checkout scheme fields markup from the shared template.
 *
 * @param array<string, mixed>|null $context Checkout payment context.
 * @return string
 */
function mtuc_render_checkout_payment_fields_html( ?array $context = null ): string {
	if ( null === $context ) {
		$context = mtuc_get_checkout_payment_context();
	}

	if ( null === $context ) {
		return '<p class="mtuc-checkout-payment__notice">' . esc_html__( 'Лизингът не е наличен за текущата поръчка.', 'mtunicredit' ) . '</p>';
	}

	$template = MTUC_PLUGIN_DIR . '/templates/checkout-payment-fields.php';
	if ( ! is_readable( $template ) ) {
		return '';
	}

	ob_start();
	include $template;

	return (string) ob_get_clean();
}

/**
 * Client-side checkout calculator config (classic + blocks).
 *
 * @param array<string, mixed>|null $context Checkout payment context.
 * @return array<string, mixed>
 */
function mtuc_get_checkout_payment_script_config( ?array $context = null ): array {
	if ( null === $context ) {
		$context = mtuc_get_checkout_payment_context();
	}

	$popup_context = ( null !== $context && isset( $context['popup'] ) && is_array( $context['popup'] ) )
		? $context['popup']
		: array();

	return array(
		'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
		'nonce'                => wp_create_nonce( 'mtuc_popup' ),
		'source'               => 'checkout',
		'cartTotal'            => (float) ( $context['cart_total'] ?? 0 ),
		'hasStandard'          => ! empty( $popup_context['has_standard'] ),
		'hasPromo'             => ! empty( $popup_context['has_promo'] ),
		'enabledMonthsByOffer' => isset( $popup_context['enabled_months_by_offer'] ) && is_array( $popup_context['enabled_months_by_offer'] )
			? $popup_context['enabled_months_by_offer']
			: array(),
		'defaultSchemeByOffer' => isset( $popup_context['default_scheme_by_offer'] ) && is_array( $popup_context['default_scheme_by_offer'] )
			? $popup_context['default_scheme_by_offer']
			: array(),
		'currencyDual'         => ! empty( $popup_context['currency']['dual'] ),
		'showFirstVnoska'      => ! empty( $popup_context['show_first_vnoska'] ),
		'i18n'                 => mtuc_get_calculator_i18n_strings(),
	);
}

/**
 * Enqueue shared checkout payment styles.
 *
 * @return void
 */
function mtuc_enqueue_checkout_payment_styles(): void {
	$popup_css    = MTUC_PLUGIN_DIR . '/css/mtuc-popup.css';
	$checkout_css = MTUC_PLUGIN_DIR . '/css/mtuc-checkout.css';

	mtuc_enqueue_fonts();

	wp_enqueue_style(
		'mtuc-popup',
		MTUC_CSS_URI . '/mtuc-popup.css',
		array( 'mtuc-fonts' ),
		file_exists( $popup_css ) ? (string) filemtime( $popup_css ) : MTUC_VERSION
	);

	wp_enqueue_style(
		'mtuc-checkout',
		MTUC_CSS_URI . '/mtuc-checkout.css',
		array( 'mtuc-popup' ),
		file_exists( $checkout_css ) ? (string) filemtime( $checkout_css ) : MTUC_VERSION
	);
}

/**
 * AJAX: refresh blocks checkout fields after cart changes.
 *
 * @return void
 */
function mtuc_ajax_checkout_blocks_refresh(): void {
	check_ajax_referer( 'mtuc_popup', 'security' );

	$context = mtuc_get_checkout_payment_context();
	if ( null === $context ) {
		wp_send_json_error(
			array(
				'message' => __( 'Лизингът не е наличен за текущата поръчка.', 'mtunicredit' ),
			)
		);
	}

	wp_send_json_success(
		array(
			'fieldsHtml' => mtuc_render_checkout_payment_fields_html( $context ),
			'checkout'   => mtuc_get_checkout_payment_script_config( $context ),
		)
	);
}

/**
 * Register checkout payment hooks.
 *
 * @return void
 */
function mtuc_register_checkout_payment_hooks(): void {
	add_filter( 'woocommerce_payment_gateways', 'mtuc_register_payment_gateway' );
	add_action( 'woocommerce_blocks_loaded', 'mtuc_register_blocks_payment_method' );

	if ( is_admin() ) {
		return;
	}

	add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_checkout_payment_assets' );
	add_action( 'wp_ajax_mtuc_checkout_blocks_refresh', 'mtuc_ajax_checkout_blocks_refresh' );
	add_action( 'wp_ajax_nopriv_mtuc_checkout_blocks_refresh', 'mtuc_ajax_checkout_blocks_refresh' );
}

/**
 * Register mtunicredit with WooCommerce Blocks payment registry.
 *
 * @return void
 */
function mtuc_register_blocks_payment_method(): void {
	if ( ! class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType', false ) ) {
		return;
	}

	if ( ! mtuc_load_payment_gateway_blocks_class() ) {
		return;
	}

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		static function ( $payment_method_registry ) {
			if ( ! is_object( $payment_method_registry ) || ! method_exists( $payment_method_registry, 'register' ) ) {
				return;
			}

			$payment_method_registry->register( new Mtuc_Payment_Gateway_Blocks() );
		}
	);
}

/**
 * Enqueue checkout payment assets.
 *
 * @return void
 */
function mtuc_enqueue_checkout_payment_assets(): void {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}

	if ( mtuc_is_blocks_checkout() ) {
		return;
	}

	$context = mtuc_get_checkout_payment_context();
	if ( null === $context ) {
		return;
	}

	$checkout_js = MTUC_PLUGIN_DIR . '/js/mtuc-checkout-payment.js';

	mtuc_enqueue_checkout_payment_styles();

	wp_enqueue_script(
		'mtuc-checkout-payment',
		MTUC_JS_URI . '/mtuc-checkout-payment.js',
		array( 'jquery' ),
		file_exists( $checkout_js ) ? (string) filemtime( $checkout_js ) : MTUC_VERSION,
		true
	);

	wp_localize_script(
		'mtuc-checkout-payment',
		'mtucCheckout',
		mtuc_get_checkout_payment_script_config( $context )
	);
}
