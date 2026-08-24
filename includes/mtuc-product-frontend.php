<?php
/**
 * Product-page frontend orchestration (AUD-WOO-016 Step 8).
 *
 * Attaches UniCredit product financing UI to WooCommerce product pages:
 * hooks, asset enqueue, bootstrap localization, and thin AJAX adapters.
 * Offer selection and financing calculation remain in dedicated modules.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register popup AJAX handlers (must run on admin-ajax requests too).
 *
 * @return void
 */
function mtuc_register_product_popup_ajax_hooks(): void {
	add_action( 'wp_ajax_mtuc_popup_calculate', 'mtuc_ajax_popup_calculate' );
	add_action( 'wp_ajax_nopriv_mtuc_popup_calculate', 'mtuc_ajax_popup_calculate' );
	add_action( 'wp_ajax_mtuc_product_calculator_refresh', 'mtuc_ajax_product_calculator_refresh' );
	add_action( 'wp_ajax_nopriv_mtuc_product_calculator_refresh', 'mtuc_ajax_product_calculator_refresh' );
}

/**
 * Register popup frontend hooks (footer markup).
 *
 * @return void
 */
function mtuc_register_product_popup_hooks(): void {
	add_action( 'wp_footer', 'mtuc_render_credit_popup', 5 );
}

/**
 * Whether the product-page calculator shell may load (shop active, no price gate).
 *
 * @return bool
 */
function mtuc_can_render_product_calculator_shell(): bool {
	if ( ! is_product() || is_admin() ) {
		return false;
	}

	if ( ! Mtuc_Settings::is_enabled() ) {
		return false;
	}

	if ( '' === (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID ) ) {
		return false;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return false;
	}

	return mtuc_is_yes_flag( $shop['uni_status'] ?? 0 );
}

/**
 * Build product calculator context when the template should be shown.
 *
 * @return array<string, mixed>|null
 */
function mtuc_get_product_calculator_context(): ?array {
	if ( ! mtuc_can_render_product_calculator_shell() ) {
		return null;
	}

	$unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );

	static $context  = null;
	static $resolved = false;

	if ( $resolved ) {
		return $context;
	}

	$resolved = true;
	$context  = null;

	$shop = mtuc_get_shop_data( $unicid );
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return null;
	}

	if ( ! mtuc_is_transaction_currency_compatible( $shop ) ) {
		return null;
	}

	$offer = null;
	if ( mtuc_is_product_price_in_shop_range( $shop ) ) {
		$offer = mtuc_get_product_calculator_offer( $shop );
	}

	$standard = null;
	$promo    = null;
	if ( is_array( $offer ) ) {
		$standard = $offer['standard'] ?? null;
		$promo    = $offer['promo'] ?? null;
	}

	$is_dark_button = mtuc_is_yes_flag( $shop['uni_type_button'] ?? 0 );

	$button_width  = isset( $shop['uni_button_width'] ) ? absint( $shop['uni_button_width'] ) : 0;
	$button_height = isset( $shop['uni_button_height'] ) ? absint( $shop['uni_button_height'] ) : 0;

	if ( $button_width <= 0 ) {
		$button_width = 290;
	}

	if ( $button_height <= 0 ) {
		$button_height = 56;
	}

	$current_product = mtuc_get_current_wc_product();
	$line_price      = mtuc_get_product_price( $current_product );

	$context = array(
		'product_id'       => $current_product instanceof WC_Product ? mtuc_get_catalog_product_id( $current_product ) : 0,
		'offer'            => $offer,
		'standard'         => $standard,
		'promo'            => $promo,
		'show_installment' => mtuc_is_yes_flag( $shop['uni_vnoska'] ?? 0 ),
		'buttons_in_row'   => 1 === (int) ( $shop['uni_button_row'] ?? 1 ),
		'button_width'     => $button_width,
		'button_height'    => $button_height,
		'is_dark_button'   => $is_dark_button,
		'logo_url'         => mtuc_get_uni_logo_url( $is_dark_button ),
		'gap'              => (int) Mtuc_Settings::get( Mtuc_Settings::OPTION_GAP ),
		'heading'          => mtuc_get_shop_calculator_heading( $shop ),
		'popup'            => mtuc_get_product_popup_context(
			$shop,
			array(
				'standard' => $standard,
				'promo'    => $promo,
			),
			$current_product instanceof WC_Product ? $current_product : null,
			null !== $line_price ? (float) $line_price : null
		),
	);

	return $context;
}

/**
 * Whether the product-page calculator should be rendered.
 *
 * @return bool
 */
function mtuc_should_show_product_calculator(): bool {
	return null !== mtuc_get_product_calculator_context();
}

/**
 * Register WooCommerce hook for the product-page calculator template.
 *
 * @return void
 */
function mtuc_register_product_hooks(): void {
	if ( is_admin() ) {
		return;
	}

	$hook  = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_HOOK );
	$hooks = Mtuc_Settings::get_hook_choices();

	if ( ! array_key_exists( $hook, $hooks ) ) {
		$hook = Mtuc_Settings::DEFAULT_HOOK;
	}

	add_action( $hook, 'mtuc_render_product_calculator', 15 );
	add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_product_assets' );

	mtuc_register_product_popup_hooks();
}

/**
 * Enqueue product calculator CSS/JS on single product pages when enabled.
 *
 * @return void
 */
function mtuc_enqueue_product_assets(): void {
	if ( ! mtuc_should_show_product_calculator() ) {
		return;
	}

	$context = mtuc_get_product_calculator_context();
	if ( null === $context ) {
		return;
	}

	$css_file        = MTUC_PLUGIN_DIR . '/css/mtuc-product.css';
	$popup_css       = MTUC_PLUGIN_DIR . '/css/mtuc-popup.css';
	$calculator_js   = MTUC_PLUGIN_DIR . '/js/mtuc-product-calculator.js';
	$popup_js        = MTUC_PLUGIN_DIR . '/js/mtuc-product-popup.js';
	$current_product = mtuc_get_current_wc_product();
	$product_id      = $current_product instanceof WC_Product
		? mtuc_get_catalog_product_id( $current_product )
		: (int) ( $context['product_id'] ?? 0 );

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
		'mtuc-product-calculator',
		MTUC_JS_URI . '/mtuc-product-calculator.js',
		array( 'jquery' ),
		file_exists( $calculator_js ) ? (string) filemtime( $calculator_js ) : MTUC_VERSION,
		true
	);

	wp_enqueue_script(
		'mtuc-product-popup',
		MTUC_JS_URI . '/mtuc-product-popup.js',
		array( 'jquery', 'mtuc-product-calculator' ),
		file_exists( $popup_js ) ? (string) filemtime( $popup_js ) : MTUC_VERSION,
		true
	);

	$popup_context = isset( $context['popup'] ) && is_array( $context['popup'] ) ? $context['popup'] : array();

	wp_localize_script(
		'mtuc-product-calculator',
		'mtucCalculator',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'mtuc_popup' ),
			'productId' => $product_id,
		)
	);

	wp_localize_script(
		'mtuc-product-popup',
		'mtucPopup',
		array(
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'mtuc_popup' ),
			'source'               => 'product',
			'productId'            => $product_id,
			'enabledMonthsByOffer' => isset( $popup_context['enabled_months_by_offer'] ) && is_array( $popup_context['enabled_months_by_offer'] )
				? $popup_context['enabled_months_by_offer']
				: array(),
			'defaultSchemeByOffer' => isset( $popup_context['default_scheme_by_offer'] ) && is_array( $popup_context['default_scheme_by_offer'] )
				? $popup_context['default_scheme_by_offer']
				: array(),
			'currencyDual'         => ! empty( $popup_context['currency']['dual'] ),
			'hideAddToCart'        => ! empty( $popup_context['hide_add_to_cart'] ),
			'process2'             => ! empty( $popup_context['process2'] ),
			'payBtn'               => Mtuc_Settings::get_paybtn_mode(),
			'checkoutUrl'          => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'customer'             => isset( $popup_context['customer'] ) && is_array( $popup_context['customer'] )
				? array(
					'first_name' => (string) ( $popup_context['customer']['first_name'] ?? '' ),
					'last_name'  => (string) ( $popup_context['customer']['last_name'] ?? '' ),
					'address'    => (string) ( $popup_context['customer']['address'] ?? '' ),
					'phone'      => (string) ( $popup_context['customer']['phone'] ?? '' ),
					'email'      => (string) ( $popup_context['customer']['email'] ?? '' ),
				)
				: mtuc_get_popup_customer_defaults(),
			'i18n'                 => array(
				'calcError'      => __( 'Неуспешно изчисление. Моля, опитайте отново.', 'mtunicredit' ),
				'addToCartError' => __( 'Не може да се добави в количката. Проверете опциите на продукта.', 'mtunicredit' ),
				'buyLabel'       => __( 'Купи', 'mtunicredit' ),
				'schemeRequired' => __( 'Моля, изберете схема за погасяване.', 'mtunicredit' ),
				'submitPending'  => __( 'Изпращането на заявката ще бъде добавено на следващ етап.', 'mtunicredit' ),
				'monthsLabel'    => __( '%d месеца', 'mtunicredit' ),
				'noMonths'       => __( 'Няма налични срокове за този продукт.', 'mtunicredit' ),
				'fieldRequired'  => __( 'Полето е задължително.', 'mtunicredit' ),
				'phoneInvalid'   => __( 'Въведете валиден телефонен номер.', 'mtunicredit' ),
				'emailInvalid'   => __( 'Въведете валиден e-mail адрес.', 'mtunicredit' ),
				'egnInvalid'     => __( 'Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).', 'mtunicredit' ),
				'submitError'    => __( 'Заявката не може да бъде изпратена. Моля, опитайте отново.', 'mtunicredit' ),
				'submitNoCalc'   => __( 'Липсват данни за изчисление. Моля, върнете се и изберете схема отново.', 'mtunicredit' ),
				'submitting'     => __( 'Изпращане...', 'mtunicredit' ),
				'processing'     => __( 'Обработване на заявката. Моля, изчакайте...', 'mtunicredit' ),
			),
		)
	);
}

/**
 * Render product-page calculator template when conditions are met.
 *
 * @return void
 */
function mtuc_render_product_calculator(): void {
	$context = mtuc_get_product_calculator_context();
	if ( null === $context ) {
		return;
	}

	$template = MTUC_PLUGIN_DIR . '/templates/product-calculator.php';
	if ( ! is_readable( $template ) ) {
		return;
	}

	include $template;
}

/**
 * Build AJAX payload for product calculator refresh after price/qty changes.
 *
 * @param WC_Product                $product    Product or variation instance.
 * @param float                     $line_price Total line price including tax.
 * @param array<string, mixed>|null $shop       Shop `data` object from CP.
 * @return array<string, mixed>
 */
function mtuc_build_product_calculator_refresh_payload( WC_Product $product, float $line_price, ?array $shop = null ): array {
	if ( null === $shop ) {
		$shop = mtuc_get_shop_data();
	}

	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return array(
			'visible' => false,
		);
	}

	if ( ! Mtuc_Settings::is_enabled() || ! mtuc_is_yes_flag( $shop['uni_status'] ?? 0 ) ) {
		return array(
			'visible' => false,
		);
	}

	$line_price = round( max( 0.0, $line_price ), 2 );
	if ( $line_price <= 0 || ! mtuc_is_product_price_in_shop_range( $shop, $line_price ) ) {
		return array(
			'visible' => false,
		);
	}

	$offer = mtuc_get_product_calculator_offer( $shop, $product, $line_price );
	if ( null === $offer ) {
		return array(
			'visible' => false,
		);
	}

	$standard = $offer['standard'] ?? null;
	$promo    = $offer['promo'] ?? null;
	$popup    = mtuc_get_product_popup_context(
		$shop,
		array(
			'standard' => $standard,
			'promo'    => $promo,
		),
		$product,
		$line_price
	);

	$parent_id    = mtuc_get_catalog_product_id( $product );
	$variation_id = mtuc_get_product_variation_id( $product );

	return array(
		'visible'              => true,
		'line_price'           => $line_price,
		'product_id'           => $parent_id,
		'variation_id'         => $variation_id,
		'standard'             => is_array( $standard ) && ! empty( $standard['visible'] )
			? array(
				'visible'    => true,
				'price_text' => (string) ( $standard['price_text'] ?? '' ),
			)
			: null,
		'promo'                => is_array( $promo ) && ! empty( $promo['visible'] )
			? array(
				'visible'    => true,
				'price_text' => (string) ( $promo['price_text'] ?? '' ),
			)
			: null,
		'enabledMonthsByOffer' => isset( $popup['enabled_months_by_offer'] ) && is_array( $popup['enabled_months_by_offer'] )
			? $popup['enabled_months_by_offer']
			: array(),
		'defaultSchemeByOffer' => isset( $popup['default_scheme_by_offer'] ) && is_array( $popup['default_scheme_by_offer'] )
			? $popup['default_scheme_by_offer']
			: array(),
	);
}

/**
 * AJAX: refresh calculator buttons and popup scheme options for a new line price.
 *
 * Browser-supplied prices are ignored; financing uses authoritative product/variation line totals.
 *
 * @return void
 */
function mtuc_ajax_product_calculator_refresh(): void {
	check_ajax_referer( 'mtuc_popup', 'security' );

	if ( ! Mtuc_Settings::is_enabled() ) {
		wp_send_json_error(
			array( 'message' => __( 'Модулът не е активен.', 'mtunicredit' ) ),
			403
		);
	}

	$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
	$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
	$quantity     = isset( $_POST['quantity'] ) ? (int) wp_unslash( $_POST['quantity'] ) : 1;
	if ( $quantity <= 0 ) {
		$quantity = 1;
	}

	$line = mtuc_resolve_authoritative_product_financing_line( $product_id, $variation_id, $quantity );
	if ( is_wp_error( $line ) ) {
		mtuc_send_customer_safe_json_error( $line, 400, 'general' );
	}

	wp_send_json_success(
		mtuc_build_product_calculator_refresh_payload( $line['product'], (float) $line['line_total'] )
	);
}
