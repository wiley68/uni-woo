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
 * @return string Status slug without wc- prefix (default: on-hold).
 */
function mtuc_get_payment_gateway_order_status(): string {
	$settings = mtuc_get_payment_gateway_settings();
	$status   = isset( $settings['order_status'] )
		? mtuc_normalize_wc_order_status( (string) $settings['order_status'] )
		: 'on-hold';

	if ( '' === $status ) {
		return 'on-hold';
	}

	if ( function_exists( 'wc_get_order_statuses' ) ) {
		$valid_statuses = wc_get_order_statuses();
		$prefixed       = 'wc-' . $status;

		if ( ! isset( $valid_statuses[ $prefixed ] ) && ! isset( $valid_statuses[ $status ] ) ) {
			return 'on-hold';
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
 * Default order note when leasing order is submitted to the bank.
 *
 * @return string
 */
function mtuc_get_payment_gateway_status_note(): string {
	return __( 'Поръчка за лизинг УниКредит — изпратена към банката.', 'mtunicredit' );
}

/**
 * WooCommerce transactional email handler, when available.
 *
 * @return WC_Emails|null
 */
function mtuc_get_wc_mailer(): ?WC_Emails {
	if ( ! function_exists( 'WC' ) ) {
		return null;
	}

	$woocommerce = WC();

	return $woocommerce->mailer();
}

/**
 * Send admin/customer emails for a leasing order when WC did not fire a status transition.
 *
 * WooCommerce sends transactional emails on status changes (e.g. pending → on-hold).
 * If the order stays on pending, no emails are sent unless we trigger them explicitly.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_send_leasing_order_notifications_once( WC_Order $order ): void {
	if ( (int) $order->get_meta( MTUC_ORDER_META_LEASING_NOTIFICATIONS_SENT ) ) {
		return;
	}

	if ( MTUC_PAYMENT_GATEWAY_ID !== $order->get_payment_method() ) {
		return;
	}

	$mailer = mtuc_get_wc_mailer();
	if ( ! $mailer ) {
		return;
	}

	$emails   = $mailer->get_emails();
	$order_id = $order->get_id();

	if ( ! empty( $emails['WC_Email_New_Order'] ) ) {
		$emails['WC_Email_New_Order']->trigger( $order_id, $order );
	}

	$customer_email_map = array(
		'processing' => 'WC_Email_Customer_Processing_Order',
		'on-hold'    => 'WC_Email_Customer_On_Hold_Order',
		'completed'  => 'WC_Email_Customer_Completed_Order',
	);

	$status = $order->get_status();
	if ( isset( $customer_email_map[ $status ], $emails[ $customer_email_map[ $status ] ] ) ) {
		$emails[ $customer_email_map[ $status ] ]->trigger( $order_id, $order );
	}

	$order->update_meta_data( MTUC_ORDER_META_LEASING_NOTIFICATIONS_SENT, 1 );
	$order->save();

	mtuc_send_process2_uni_email_notifications( $order );
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

	$target_status = mtuc_get_payment_gateway_order_status();
	$note          = '' !== $status_note ? $status_note : mtuc_get_payment_gateway_status_note();

	if ( $order->get_status() !== $target_status ) {
		$order->update_status( $target_status, $note );
		return;
	}

	$order->save();
	mtuc_send_leasing_order_notifications_once( $order );

	if ( '' !== $note ) {
		$order->add_order_note( $note );
		$order->save();
	}
}

/**
 * Whether the order was placed via WooCommerce checkout (not product/cart popup).
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_is_checkout_gateway_order( WC_Order $order ): bool {
	$source = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'submission_source' );

	return 'checkout' === $source;
}

/**
 * Clear cart after a successful checkout placement (same as core BACS/COD gateways).
 *
 * @return void
 */
function mtuc_empty_checkout_cart_after_order(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	WC()->cart->empty_cart();
}

/**
 * Thank-you URL used after checkout payment (before bank hop).
 *
 * @param WC_Order $order Order instance.
 * @return string
 */
function mtuc_get_checkout_order_thankyou_url( WC_Order $order ): string {
	return mtuc_get_popup_order_thankyou_url( $order );
}

/**
 * One-time server redirect from order-received to SmartUCF (checkout only).
 *
 * Keeps the standard WooCommerce checkout completion lifecycle (emails, session)
 * while avoiding duplicate bank submissions on thank-you refresh.
 *
 * @return void
 */
function mtuc_checkout_maybe_redirect_to_bank_on_thankyou(): void {
	if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
		return;
	}

	global $wp;

	$order_id = (int) ( $wp->query_vars['order-received'] ?? 0 );
	if ( $order_id <= 0 ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	if ( MTUC_PAYMENT_GATEWAY_ID !== $order->get_payment_method() || ! mtuc_is_checkout_gateway_order( $order ) ) {
		return;
	}

	$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	if ( '' === $order_key || ! hash_equals( $order->get_order_key(), $order_key ) ) {
		return;
	}

	if ( (int) $order->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE ) ) {
		return;
	}

	if ( (int) $order->get_meta( MTUC_ORDER_META_BANK_REDIRECT_DISPATCHED ) ) {
		return;
	}

	$redirect_url = (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL );
	if ( '' === $redirect_url ) {
		return;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) ) {
		return;
	}

	if ( ! Mtuc_Smartucf_Api_Client::is_trusted_redirect_url( $redirect_url, $shop ) ) {
		return;
	}

	$order->update_meta_data( MTUC_ORDER_META_BANK_REDIRECT_DISPATCHED, 1 );
	$order->save();

	Mtuc_Smartucf_Api_Client::redirect_browser( $redirect_url, $shop );
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
		'calcError'        => __( 'Неуспешно изчисление. Моля, опитайте отново.', 'mtunicredit' ),
		'monthsLabel'      => __( '%d месеца', 'mtunicredit' ),
		'noMonths'         => __( 'Няма налични срокове за тази поръчка.', 'mtunicredit' ),
		'schemeRequired'   => __( 'Моля, изберете схема за погасяване.', 'mtunicredit' ),
		'consentsRequired' => __( 'Моля, приемете всички задължителни съгласия.', 'mtunicredit' ),
		'consentsTooltip'  => __( 'Моля, първо приемете общите условия, за да продължите с поръчката.', 'mtunicredit' ),
		'offerStandard'    => __( 'Стандарт', 'mtunicredit' ),
		'offerPromo'       => __( 'Промо', 'mtunicredit' ),
		'fieldRequired'    => __( 'Полето е задължително.', 'mtunicredit' ),
		'egnRequired'      => __( 'Полето „ЕГН“ е задължително.', 'mtunicredit' ),
		'egnInvalid'       => __( 'Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).', 'mtunicredit' ),
		'phoneInvalid'     => __( 'Въведете валиден втори телефонен номер.', 'mtunicredit' ),
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

	$enabled_schemes = isset( $popup_context['enabled_schemes'] ) && is_array( $popup_context['enabled_schemes'] )
		? $popup_context['enabled_schemes']
		: array();

	return array(
		'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
		'nonce'            => wp_create_nonce( 'mtuc_popup' ),
		'source'           => 'checkout',
		'offerType'        => 'standard',
		'cartTotal'        => (float) ( $context['cart_total'] ?? 0 ),
		'process2'         => ! empty( $popup_context['process2'] ),
		'enabledSchemes'   => array_values( $enabled_schemes ),
		'defaultSchemeKey' => isset( $popup_context['default_scheme_key'] ) ? (string) $popup_context['default_scheme_key'] : '',
		'currencyDual'     => ! empty( $popup_context['currency']['dual'] ),
		'showFirstVnoska'  => ! empty( $popup_context['show_first_vnoska'] ),
		'i18n'             => mtuc_get_calculator_i18n_strings(),
	);
}

/**
 * Human-readable label for a checkout scheme select option.
 *
 * @param array<string, mixed> $option Scheme option row.
 * @return string
 */
function mtuc_format_checkout_scheme_option_label( array $option ): string {
	$months = max( 0, (int) ( $option['months'] ?? 0 ) );
	$desc   = trim( (string) ( $option['desc'] ?? '' ) );
	$label  = sprintf(
		/* translators: %d: number of months */
		__( '%d месеца', 'mtunicredit' ),
		$months
	);

	if ( '' !== $desc ) {
		$label .= ' - ' . $desc;
	}

	return $label . "\xc2\xa0\xc2\xa0\xc2\xa0";
}

/**
 * Build inline checkout config for data-mtuc-config attribute.
 *
 * @param array<string, mixed> $popup Checkout popup context.
 * @return string JSON attribute value.
 */
function mtuc_build_checkout_payment_fields_data_config( array $popup ): string {
	$enabled_schemes = isset( $popup['enabled_schemes'] ) && is_array( $popup['enabled_schemes'] )
		? array_values( $popup['enabled_schemes'] )
		: array();

	$config = array(
		'enabledSchemes'   => $enabled_schemes,
		'defaultSchemeKey' => isset( $popup['default_scheme_key'] ) ? (string) $popup['default_scheme_key'] : '',
		'offerType'        => 'standard',
	);

	return (string) wp_json_encode( $config );
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
	add_action( 'wp_ajax_mtuc_checkout_blocks_refresh', 'mtuc_ajax_checkout_blocks_refresh' );
	add_action( 'wp_ajax_nopriv_mtuc_checkout_blocks_refresh', 'mtuc_ajax_checkout_blocks_refresh' );

	if ( is_admin() ) {
		return;
	}

	add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_checkout_payment_assets', 100 );
	add_action( 'template_redirect', 'mtuc_checkout_maybe_redirect_to_bank_on_thankyou', 5 );
	add_action( 'woocommerce_checkout_process', 'mtuc_checkout_validate_process2_fields' );
	add_action( 'woocommerce_checkout_create_order', 'mtuc_checkout_save_process2_order_meta', 10, 2 );
}

/**
 * Validate Process 2 fields during classic checkout.
 *
 * @return void
 */
function mtuc_checkout_validate_process2_fields(): void {
	if ( ! isset( $_POST['payment_method'] ) || MTUC_PAYMENT_GATEWAY_ID !== sanitize_text_field( wp_unslash( (string) $_POST['payment_method'] ) ) ) {
		return;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) || ! mtuc_is_shop_process_2( $shop ) ) {
		return;
	}

	$validated = mtuc_validate_process2_fields_from_post( $_POST );
	if ( is_wp_error( $validated ) ) {
		wc_add_notice( $validated->get_error_message(), 'error' );
	}
}

/**
 * Persist Process 2 fields on the WooCommerce order during checkout.
 *
 * @param WC_Order             $order Order instance.
 * @param array<string, mixed> $data  Checkout posted data.
 * @return void
 */
function mtuc_checkout_save_process2_order_meta( $order, array $data ): void {
	unset( $data );

	if ( ! $order instanceof WC_Order ) {
		return;
	}

	if ( ! isset( $_POST['payment_method'] ) || MTUC_PAYMENT_GATEWAY_ID !== sanitize_text_field( wp_unslash( (string) $_POST['payment_method'] ) ) ) {
		return;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) || ! mtuc_is_shop_process_2( $shop ) ) {
		return;
	}

	$validated = mtuc_validate_process2_fields_from_post( $_POST );
	if ( is_wp_error( $validated ) ) {
		return;
	}

	mtuc_save_order_process2_customer_meta( $order, $validated );
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
