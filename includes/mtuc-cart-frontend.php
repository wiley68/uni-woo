<?php
/**
 * Cart frontend orchestration (AUD-WOO-016 Step 9).
 *
 * Attaches UniCredit cart financing UI to WooCommerce cart/checkout:
 * hooks, AJAX adapters, asset enqueue, bootstrap localization, and
 * fragment/Blocks wiring. Scheme intersection, financing math, and
 * authoritative cart context remain in dedicated modules.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build AJAX payload for cart calculator refresh after cart changes.
 *
 * @return array<string, mixed>
 */
function mtuc_build_cart_calculator_refresh_payload(): array {
	$context = mtuc_build_cart_calculator_context();
	if ( null === $context ) {
		return array(
			'visible'       => false,
			'fragmentHtml'  => '',
		);
	}

	$fragment_html = mtuc_get_cart_calculator_fragment_html();
	$popup         = isset( $context['popup'] ) && is_array( $context['popup'] ) ? $context['popup'] : array();
	$standard      = isset( $context['standard'] ) && is_array( $context['standard'] ) ? $context['standard'] : null;
	$promo         = isset( $context['promo'] ) && is_array( $context['promo'] ) ? $context['promo'] : null;

	$standard_payload = null;
	$promo_payload    = null;

	if ( is_array( $standard ) && ! empty( $standard['visible'] ) ) {
		$standard_payload = array(
			'visible'    => true,
			'image_only' => ! empty( $standard['image_only'] ),
			'price_text' => ! empty( $standard['image_only'] ) ? '' : (string) ( $standard['price_text'] ?? '' ),
		);
	}

	if ( is_array( $promo ) && ! empty( $promo['visible'] ) ) {
		$promo_payload = array(
			'visible'    => true,
			'image_only' => ! empty( $promo['image_only'] ),
			'price_text' => ! empty( $promo['image_only'] ) ? '' : (string) ( $promo['price_text'] ?? '' ),
		);
	}

	$has_visible_buttons = null !== $standard_payload || null !== $promo_payload;
	if ( ! $has_visible_buttons ) {
		return array(
			'visible'      => false,
			'cart_total'   => (float) ( $context['cart_total'] ?? 0 ),
			'fragmentHtml' => $fragment_html,
		);
	}

	return array(
		'visible'              => true,
		'cart_total'           => (float) ( $context['cart_total'] ?? 0 ),
		'fragmentHtml'         => $fragment_html,
		'show_installment'     => ! empty( $context['show_installment'] ),
		'standard'             => $standard_payload,
		'promo'                => $promo_payload,
		'enabledMonthsByOffer' => isset( $popup['enabled_months_by_offer'] ) && is_array( $popup['enabled_months_by_offer'] )
			? $popup['enabled_months_by_offer']
			: array(),
		'defaultSchemeByOffer' => isset( $popup['default_scheme_by_offer'] ) && is_array( $popup['default_scheme_by_offer'] )
			? $popup['default_scheme_by_offer']
			: array(),
	);
}
/**
 * Whether the current request should refresh the cart calculator fragment.
 *
 * @return bool
 */
function mtuc_should_refresh_cart_calculator_fragment(): bool {
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return true;
	}

	if ( ! wp_doing_ajax() ) {
		return false;
	}

	$referer = wp_get_referer();
	if ( ! is_string( $referer ) || '' === $referer || ! function_exists( 'wc_get_cart_url' ) ) {
		return false;
	}

	$cart_url = wc_get_cart_url();
	if ( '' === $cart_url ) {
		return false;
	}

	return 0 === strpos( $referer, $cart_url );
}
/**
 * Render cart calculator markup wrapped for WooCommerce fragment replacement.
 *
 * @return string
 */
function mtuc_get_cart_calculator_fragment_html(): string {
	$context = mtuc_build_cart_calculator_context();
	if ( null === $context ) {
		return '';
	}

	$template = MTUC_PLUGIN_DIR . '/templates/cart-calculator.php';
	if ( ! is_readable( $template ) ) {
		return '';
	}

	ob_start();

	$mtuc_standard = isset( $context['standard'] ) && is_array( $context['standard'] ) ? $context['standard'] : null;
	$mtuc_promo    = isset( $context['promo'] ) && is_array( $context['promo'] ) ? $context['promo'] : null;
	$mtuc_visible  = ( null !== $mtuc_standard && ! empty( $mtuc_standard['visible'] ) )
		|| ( null !== $mtuc_promo && ! empty( $mtuc_promo['visible'] ) );
	$mtuc_fragment_style = $mtuc_visible ? '' : ' style="display:none;"';

	echo '<div class="mtuc-cart-calculator-fragment"' . $mtuc_fragment_style . '>';
	include $template;
	echo '</div>';

	return (string) ob_get_clean();
}
/**
 * Keep cart calculator in sync when WooCommerce refreshes cart fragments.
 *
 * @param array<string, string> $fragments Cart fragments.
 * @return array<string, string>
 */
function mtuc_append_cart_calculator_fragment( array $fragments ): array {
	if ( ! mtuc_should_refresh_cart_calculator_fragment() ) {
		return $fragments;
	}

	$fragments['div.mtuc-cart-calculator-fragment'] = mtuc_get_cart_calculator_fragment_html();

	return $fragments;
}
/**
 * AJAX: refresh cart calculator buttons and popup scheme options.
 *
 * @return void
 */
function mtuc_ajax_cart_calculator_refresh(): void {
	check_ajax_referer( 'mtuc_popup', 'security' );

	if ( ! Mtuc_Settings::is_enabled() ) {
		wp_send_json_error(
			array( 'message' => __( 'Модулът не е активен.', 'mtunicredit' ) ),
			403
		);
	}

	wp_send_json_success( mtuc_build_cart_calculator_refresh_payload() );
}
/**
 * AJAX: notify shop recipients that the cart cannot be purchased on leasing as a whole.
 *
 * @return void
 */
function mtuc_ajax_cart_split_notify(): void {
	check_ajax_referer( 'mtuc_popup', 'security' );

	if ( ! Mtuc_Settings::is_enabled() ) {
		wp_send_json_error(
			array( 'message' => __( 'Модулът не е активен.', 'mtunicredit' ) ),
			403
		);
	}

	if ( ! mtuc_is_cart_split_required() ) {
		wp_send_json_success(
			array(
				'sent'    => false,
				'skipped' => true,
			)
		);
	}

	$sent = mtuc_send_cart_split_required_notification();

	wp_send_json_success(
		array(
			'sent' => $sent,
		)
	);
}
/**
 * Build cart calculator context.
 *
 * @return array<string, mixed>|null
 */
function mtuc_get_cart_calculator_context(): ?array {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() || is_admin() ) {
		return null;
	}

	static $context  = null;
	static $resolved = false;

	if ( $resolved ) {
		return $context;
	}

	$resolved = true;
	$context  = mtuc_build_cart_calculator_context();

	return $context;
}
/**
 * Register cart calculator frontend hooks.
 *
 * @return void
 */
function mtuc_register_cart_hooks(): void {
	add_action( 'wp_ajax_mtuc_cart_calculator_refresh', 'mtuc_ajax_cart_calculator_refresh' );
	add_action( 'wp_ajax_nopriv_mtuc_cart_calculator_refresh', 'mtuc_ajax_cart_calculator_refresh' );
	add_action( 'wp_ajax_mtuc_cart_split_notify', 'mtuc_ajax_cart_split_notify' );
	add_action( 'wp_ajax_nopriv_mtuc_cart_split_notify', 'mtuc_ajax_cart_split_notify' );
	add_action( 'wp_ajax_mtuc_cart_blocks_refresh', 'mtuc_ajax_cart_blocks_refresh' );
	add_action( 'wp_ajax_nopriv_mtuc_cart_blocks_refresh', 'mtuc_ajax_cart_blocks_refresh' );

	if ( is_admin() ) {
		return;
	}

	add_action(
		'woocommerce_proceed_to_checkout',
		static function (): void {
			if ( mtuc_is_blocks_cart() ) {
				return;
			}

			mtuc_render_cart_calculator();
		},
		5
	);
	add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_cart_assets' );
	add_filter(
		'woocommerce_add_to_cart_fragments',
		static function ( array $fragments ): array {
			if ( mtuc_is_blocks_cart() ) {
				return $fragments;
			}

			return mtuc_append_cart_calculator_fragment( $fragments );
		}
	);
}
/**
 * Whether the cart page uses the WooCommerce Cart block.
 *
 * @return bool
 */
function mtuc_is_blocks_cart(): bool {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
		return false;
	}

	if ( class_exists( '\Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils' ) ) {
		return \Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::is_cart_block_default();
	}

	if ( ! function_exists( 'has_block' ) || ! function_exists( 'wc_get_page_id' ) ) {
		return false;
	}

	$cart_page_id = (int) wc_get_page_id( 'cart' );
	if ( $cart_page_id <= 0 ) {
		return false;
	}

	$post = get_post( $cart_page_id );
	if ( ! $post instanceof WP_Post ) {
		return false;
	}

	return has_block( 'woocommerce/cart', $post );
}
/**
 * AJAX: refresh cart calculator markup for Cart block pages.
 *
 * @return void
 */
function mtuc_ajax_cart_blocks_refresh(): void {
	check_ajax_referer( 'mtuc_popup', 'security' );

	$payload                  = mtuc_build_cart_calculator_refresh_payload();
	$payload['fragmentHtml'] = mtuc_get_cart_calculator_fragment_html();

	wp_send_json_success( $payload );
}
/**
 * Client config for Cart block injection script.
 *
 * @param array<string, mixed>|null $context Cart calculator context.
 * @return array<string, mixed>
 */
function mtuc_get_cart_blocks_script_config( ?array $context = null ): array {
	if ( null === $context ) {
		$context = mtuc_build_cart_calculator_context();
	}

	return array(
		'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
		'nonce'        => wp_create_nonce( 'mtuc_popup' ),
		'blocks'       => true,
		'fragmentHtml' => mtuc_get_cart_calculator_fragment_html(),
		'cartTotal'    => is_array( $context ) ? (float) ( $context['cart_total'] ?? 0 ) : 0.0,
	);
}
/**
 * Enqueue Cart block injection script.
 *
 * @param array<string, mixed> $context Cart calculator context.
 * @return void
 */
function mtuc_enqueue_cart_blocks_script( array $context ): void {
	$blocks_js = MTUC_PLUGIN_DIR . '/js/mtuc-cart-blocks.js';

	wp_enqueue_script(
		'mtuc-cart-blocks',
		MTUC_JS_URI . '/mtuc-cart-blocks.js',
		array(
			'jquery',
			'mtuc-cart-calculator',
			'mtuc-product-popup',
			'wc-blocks-data',
			'wp-data',
		),
		file_exists( $blocks_js ) ? (string) filemtime( $blocks_js ) : MTUC_VERSION,
		true
	);

	wp_localize_script(
		'mtuc-cart-blocks',
		'mtucCartBlocks',
		mtuc_get_cart_blocks_script_config( $context )
	);
}
/**
 * Shared cart calculator + popup assets (classic and blocks cart).
 *
 * @param array<string, mixed> $context Cart calculator context.
 * @return void
 */
function mtuc_enqueue_cart_calculator_assets( array $context ): void {
	$css_file      = MTUC_PLUGIN_DIR . '/css/mtuc-product.css';
	$popup_css     = MTUC_PLUGIN_DIR . '/css/mtuc-popup.css';
	$cart_js       = MTUC_PLUGIN_DIR . '/js/mtuc-cart-calculator.js';
	$popup_js      = MTUC_PLUGIN_DIR . '/js/mtuc-product-popup.js';
	$popup_context = isset( $context['popup'] ) && is_array( $context['popup'] ) ? $context['popup'] : array();

	mtuc_enqueue_fonts();

	wp_enqueue_style(
		'mtuc-product',
		MTUC_CSS_URI . '/mtuc-product.css',
		array( 'mtuc-fonts' ),
		file_exists( $css_file ) ? (string) filemtime( $css_file ) : MTUC_VERSION
	);

	wp_enqueue_style(
		'mtuc-popup',
		MTUC_CSS_URI . '/mtuc-popup.css',
		array( 'mtuc-product' ),
		file_exists( $popup_css ) ? (string) filemtime( $popup_css ) : MTUC_VERSION
	);

	wp_enqueue_script(
		'mtuc-cart-calculator',
		MTUC_JS_URI . '/mtuc-cart-calculator.js',
		array( 'jquery' ),
		file_exists( $cart_js ) ? (string) filemtime( $cart_js ) : MTUC_VERSION,
		true
	);

	wp_enqueue_script(
		'mtuc-product-popup',
		MTUC_JS_URI . '/mtuc-product-popup.js',
		array( 'jquery', 'mtuc-cart-calculator' ),
		file_exists( $popup_js ) ? (string) filemtime( $popup_js ) : MTUC_VERSION,
		true
	);

	wp_localize_script(
		'mtuc-cart-calculator',
		'mtucCartCalculator',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'mtuc_popup' ),
			'cartTotal' => (float) ( $context['cart_total'] ?? 0 ),
			'i18n'      => array(
				'buyLabel' => __( 'Купи на изплащане', 'mtunicredit' ),
			),
		)
	);

	wp_localize_script(
		'mtuc-product-popup',
		'mtucPopup',
		array(
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'mtuc_popup' ),
			'source'               => 'cart',
			'productId'            => 0,
			'cartTotal'            => (float) ( $context['cart_total'] ?? 0 ),
			'hideAddToCart'        => true,
			'process2'             => ! empty( $popup_context['process2'] ),
			'enabledMonthsByOffer' => isset( $popup_context['enabled_months_by_offer'] ) && is_array( $popup_context['enabled_months_by_offer'] )
				? $popup_context['enabled_months_by_offer']
				: array(),
			'defaultSchemeByOffer' => isset( $popup_context['default_scheme_by_offer'] ) && is_array( $popup_context['default_scheme_by_offer'] )
				? $popup_context['default_scheme_by_offer']
				: array(),
			'currencyDual'         => ! empty( $popup_context['currency']['dual'] ),
			'customer'             => isset( $popup_context['customer'] ) && is_array( $popup_context['customer'] )
				? $popup_context['customer']
				: mtuc_get_popup_customer_defaults(),
			'i18n'                 => array(
				'calcError'         => __( 'Неуспешно изчисление. Моля, опитайте отново.', 'mtunicredit' ),
				'submitPending'     => __( 'Изпращането на заявката ще бъде добавено на следващ етап.', 'mtunicredit' ),
				'monthsLabel'       => __( '%d месеца', 'mtunicredit' ),
				'noMonths'          => __( 'Няма налични срокове за тази количка.', 'mtunicredit' ),
				'fieldRequired'     => __( 'Полето е задължително.', 'mtunicredit' ),
				'phoneInvalid'      => __( 'Въведете валиден телефонен номер.', 'mtunicredit' ),
				'emailInvalid'      => __( 'Въведете валиден e-mail адрес.', 'mtunicredit' ),
				'egnInvalid'        => __( 'Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).', 'mtunicredit' ),
				'submitError'       => __( 'Заявката не може да бъде изпратена. Моля, опитайте отново.', 'mtunicredit' ),
				'submitNoCalc'      => __( 'Липсват данни за изчисление. Моля, върнете се и изберете схема отново.', 'mtunicredit' ),
				'submitting'        => __( 'Изпращане...', 'mtunicredit' ),
				'processing'        => __( 'Обработване на заявката. Моля, изчакайте...', 'mtunicredit' ),
				'cartSplitRequired' => __( 'Не може да закупите цялата количка на изплащане. Моля, разделете поръчката си ако желаете да я закупите на изплащане.', 'mtunicredit' ),
			),
		)
	);
}
/**
 * Enqueue cart calculator assets.
 *
 * @return void
 */
function mtuc_enqueue_cart_assets(): void {
	$context = mtuc_get_cart_calculator_context();
	if ( null === $context ) {
		return;
	}

	mtuc_enqueue_cart_calculator_assets( $context );

	if ( mtuc_is_blocks_cart() ) {
		mtuc_enqueue_cart_blocks_script( $context );
	}
}
/**
 * Render cart calculator above proceed-to-checkout button.
 *
 * @return void
 */
function mtuc_render_cart_calculator(): void {
	$html = mtuc_get_cart_calculator_fragment_html();
	if ( '' === $html ) {
		return;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in template.
	echo $html;
}
