<?php
/**
 * Characterization tests for cart frontend orchestration (AUD-WOO-016 Step 9).
 *
 * Run: php tests/run-cart-frontend-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_cf_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_cf_assert( bool $ok, string $message ): void {
	global $mtuc_cf_assert_count;
	++$mtuc_cf_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

$frontend_src   = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-cart-frontend.php' );
$calculator_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-cart-calculator.php' );
$scheme_src     = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-cart-scheme-intersection.php' );
$offer_src      = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-product-offer-selection.php' );
$calc_core_src  = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-financing-calculator.php' );
$bootstrap_src  = (string) file_get_contents( MTUC_PLUGIN_DIR . '/mtunicredit.php' );
$cart_js        = (string) file_get_contents( MTUC_PLUGIN_DIR . '/js/mtuc-cart-calculator.js' );
$blocks_js      = (string) file_get_contents( MTUC_PLUGIN_DIR . '/js/mtuc-cart-blocks.js' );
$product_fe_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-product-frontend.php' );
$email_src      = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-financing-email.php' );

// ---------------------------------------------------------------------------
// Ownership / architectural chain
// ---------------------------------------------------------------------------

mtuc_cf_assert(
	false !== strpos( $bootstrap_src, '/mtuc-cart-frontend.php' ),
	'plugin loads mtuc-cart-frontend.php'
);
mtuc_cf_assert(
	strpos( $bootstrap_src, '/mtuc-cart-calculator.php' ) < strpos( $bootstrap_src, '/mtuc-cart-frontend.php' ),
	'cart-calculator loads before cart-frontend'
);
mtuc_cf_assert(
	false !== strpos( $frontend_src, 'function mtuc_register_cart_hooks' ),
	'frontend module owns cart hook registration'
);
mtuc_cf_assert(
	false === strpos( $calculator_src, 'function mtuc_register_cart_hooks' )
	&& false === strpos( $calculator_src, 'function mtuc_enqueue_cart_assets' )
	&& false === strpos( $calculator_src, 'function mtuc_ajax_cart_calculator_refresh' )
	&& false === strpos( $calculator_src, 'function mtuc_get_cart_calculator_context' ),
	'cart-calculator no longer defines frontend orchestration'
);
mtuc_cf_assert(
	false !== strpos( $bootstrap_src, 'mtuc_register_cart_hooks()' ),
	'bootstrap still calls mtuc_register_cart_hooks()'
);
mtuc_cf_assert(
	false === strpos( $product_fe_src, 'function mtuc_register_cart_hooks' )
	&& false === strpos( $email_src, 'function mtuc_register_cart_hooks' ),
	'product frontend and email modules untouched for cart registration'
);

mtuc_cf_assert(
	false !== strpos( $frontend_src, 'mtuc_build_cart_calculator_context' )
	&& false !== strpos( $calculator_src, 'function mtuc_build_cart_calculator_context' ),
	'frontend delegates to cart calculator context builder'
);
mtuc_cf_assert(
	false !== strpos( $calculator_src, 'mtuc_intersect_cart_scheme_options' )
	&& false !== strpos( $scheme_src, 'function mtuc_intersect_cart_scheme_options' )
	&& false === strpos( $frontend_src, 'function mtuc_intersect_cart_scheme_options' ),
	'intersection stays in scheme module; frontend does not reimplement'
);
mtuc_cf_assert(
	false !== strpos( $scheme_src, 'function mtuc_build_checkout_unified_scheme_options' )
	&& false === strpos( $frontend_src, 'function mtuc_build_checkout_unified_scheme_options' ),
	'checkout unification stays outside frontend'
);
mtuc_cf_assert(
	false !== strpos( $calculator_src, 'mtuc_pick_default_popup_scheme_key' )
	|| false !== strpos( $calculator_src, 'mtuc_pick_default_checkout_scheme_key' ),
	'cart domain consumes existing default picker'
);
mtuc_cf_assert(
	false !== strpos( $offer_src, 'function mtuc_pick_default_checkout_scheme_key' )
	&& false === strpos( $frontend_src, 'function mtuc_pick_default_checkout_scheme_key' )
	&& false === strpos( $frontend_src, 'function mtuc_pick_default_popup_scheme_key' ),
	'frontend does not duplicate default-selection'
);
mtuc_cf_assert(
	false === strpos( $frontend_src, 'function mtuc_compute_financing_amounts' )
	&& false === strpos( $frontend_src, 'function mtuc_resolve_parva_calculation_state' )
	&& false === strpos( $frontend_src, 'round( $price / $months' )
	&& false !== strpos( $calc_core_src, 'function mtuc_compute_financing_amounts' ),
	'frontend does not duplicate financing formulas'
);
mtuc_cf_assert(
	false !== strpos( $calculator_src, 'mtuc_get_canonical_financeable_cart_total' )
	&& false === strpos( $frontend_src, 'function mtuc_get_canonical_financeable_cart_total' ),
	'canonical cart total remains outside frontend'
);

// ---------------------------------------------------------------------------
// Hook registration contract
// ---------------------------------------------------------------------------

mtuc_cf_assert(
	false !== strpos( $frontend_src, "add_action( 'wp_ajax_mtuc_cart_calculator_refresh', 'mtuc_ajax_cart_calculator_refresh' )" )
	&& false !== strpos( $frontend_src, "add_action( 'wp_ajax_nopriv_mtuc_cart_calculator_refresh', 'mtuc_ajax_cart_calculator_refresh' )" ),
	'cart calculator refresh AJAX actions preserved'
);
mtuc_cf_assert(
	false !== strpos( $frontend_src, "add_action( 'wp_ajax_mtuc_cart_split_notify', 'mtuc_ajax_cart_split_notify' )" )
	&& false !== strpos( $frontend_src, "add_action( 'wp_ajax_nopriv_mtuc_cart_split_notify', 'mtuc_ajax_cart_split_notify' )" ),
	'cart split notify AJAX actions preserved'
);
mtuc_cf_assert(
	false !== strpos( $frontend_src, "add_action( 'wp_ajax_mtuc_cart_blocks_refresh', 'mtuc_ajax_cart_blocks_refresh' )" )
	&& false !== strpos( $frontend_src, "add_action( 'wp_ajax_nopriv_mtuc_cart_blocks_refresh', 'mtuc_ajax_cart_blocks_refresh' )" ),
	'cart blocks refresh AJAX actions preserved'
);
mtuc_cf_assert(
	false !== strpos( $frontend_src, "'woocommerce_proceed_to_checkout'" )
	&& false !== strpos( $frontend_src, "mtuc_render_cart_calculator" )
	&& false !== preg_match( "/woocommerce_proceed_to_checkout[\s\S]{0,200}?,\s*5\s*\)/", $frontend_src ),
	'proceed-to-checkout render hook priority 5 preserved'
);
mtuc_cf_assert(
	false !== strpos( $frontend_src, "add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_cart_assets' )" ),
	'cart enqueue hook registered'
);
mtuc_cf_assert(
	false !== strpos( $frontend_src, "'woocommerce_add_to_cart_fragments'" )
	&& false !== strpos( $frontend_src, 'mtuc_append_cart_calculator_fragment' ),
	'cart fragments filter wiring preserved'
);

// ---------------------------------------------------------------------------
// Enqueue / localization contract
// ---------------------------------------------------------------------------

foreach (
	array(
		"'mtuc-cart-calculator'",
		"'mtuc-product-popup'",
		"'mtuc-cart-blocks'",
		"'mtuc-product'",
		"'mtuc-popup'",
		"'mtucCartCalculator'",
		"'mtucPopup'",
		"'mtucCartBlocks'",
		"'ajaxUrl'",
		"'nonce'",
		"'cartTotal'",
		"'enabledMonthsByOffer'",
		"'defaultSchemeByOffer'",
		"'currencyDual'",
		"'process2'",
		"'hideAddToCart'",
		"'source'",
		"'i18n'",
		"'fragmentHtml'",
		"'blocks'",
	) as $needle
) {
	mtuc_cf_assert( false !== strpos( $frontend_src, $needle ), 'enqueue/bootstrap contains ' . $needle );
}

mtuc_cf_assert(
	false !== strpos( $frontend_src, "array( 'jquery' )" )
	&& false !== strpos( $frontend_src, "array( 'jquery', 'mtuc-cart-calculator' )" )
	&& false !== strpos( $frontend_src, "'wc-blocks-data'" )
	&& false !== strpos( $frontend_src, "'wp-data'" )
	&& false !== strpos( $frontend_src, "array( 'mtuc-fonts' )" ),
	'script/style dependencies preserved'
);
mtuc_cf_assert(
	false !== strpos( $frontend_src, "wp_create_nonce( 'mtuc_popup' )" )
	&& false !== strpos( $frontend_src, "check_ajax_referer( 'mtuc_popup', 'security' )" ),
	'nonce exposure and AJAX validation use mtuc_popup'
);

// ---------------------------------------------------------------------------
// Bootstrap / refresh payload contract
// ---------------------------------------------------------------------------

foreach (
	array(
		"'visible'",
		"'cart_total'",
		"'fragmentHtml'",
		"'show_installment'",
		"'standard'",
		"'promo'",
		"'enabledMonthsByOffer'",
		"'defaultSchemeByOffer'",
		"'image_only'",
		"'price_text'",
	) as $needle
) {
	mtuc_cf_assert(
		false !== strpos( $frontend_src, $needle ),
		'refresh/bootstrap payload contains ' . $needle
	);
}

// ---------------------------------------------------------------------------
// Server-authoritative amount (AUD-WOO-002 via adapter path)
// ---------------------------------------------------------------------------

mtuc_cf_assert(
	false !== strpos( $frontend_src, 'mtuc_build_cart_calculator_refresh_payload' )
	&& false !== strpos( $frontend_src, 'mtuc_build_cart_calculator_context' ),
	'AJAX refresh rebuilds payload from server context'
);
mtuc_cf_assert(
	false === strpos( $frontend_src, "\$_POST['cart_total']" )
	&& false === strpos( $frontend_src, '$_POST["cart_total"]' )
	&& false === strpos( $frontend_src, "\$_REQUEST['cart_total']" )
	&& false === strpos( $frontend_src, "\$_POST['cartTotal']" ),
	'AJAX adapters do not trust browser cart_total'
);
mtuc_cf_assert(
	false !== strpos( $calculator_src, 'mtuc_get_canonical_financeable_cart_total()' )
	&& false !== strpos( $frontend_src, "'cart_total'           => (float) ( \$context['cart_total']" ),
	'payload cart_total comes from server-built context'
);
mtuc_cf_assert(
	false !== strpos( $cart_js, 'mtuc_cart_calculator_refresh' )
	&& false !== strpos( $cart_js, 'data.cart_total' ),
	'JS consumes server cart_total from refresh response'
);
mtuc_cf_assert(
	false !== strpos( $blocks_js, 'mtuc_cart_blocks_refresh' ),
	'Blocks JS uses blocks refresh action'
);

// ---------------------------------------------------------------------------
// Cart mutation → recomputed context (structural)
// ---------------------------------------------------------------------------

mtuc_cf_assert(
	false !== strpos( $frontend_src, 'function mtuc_ajax_cart_calculator_refresh' )
	&& false !== strpos( $frontend_src, 'mtuc_build_cart_calculator_refresh_payload()' )
	&& false !== strpos( $cart_js, 'updated_cart_totals' )
	&& false !== strpos( $cart_js, 'wc_fragments_refreshed' ),
	'mutation events trigger server-side refresh adapters'
);
mtuc_cf_assert(
	false !== strpos( $frontend_src, 'woocommerce_add_to_cart_fragments' )
	&& false !== strpos( $frontend_src, 'mtuc_get_cart_calculator_fragment_html' ),
	'fragment path re-renders from current server context'
);

// ---------------------------------------------------------------------------
// Classic / Blocks continue to share normalized scheme source
// ---------------------------------------------------------------------------

mtuc_cf_assert(
	false !== strpos( $frontend_src, 'function mtuc_is_blocks_cart' )
	&& false !== strpos( $frontend_src, 'function mtuc_enqueue_cart_blocks_script' )
	&& false !== strpos( $frontend_src, 'function mtuc_ajax_cart_blocks_refresh' ),
	'Blocks wiring lives in cart frontend module'
);
mtuc_cf_assert(
	false !== strpos( $frontend_src, 'mtuc_build_cart_calculator_refresh_payload' )
	&& substr_count( $frontend_src, 'mtuc_build_cart_calculator_refresh_payload' ) >= 2,
	'classic and blocks refresh both use shared refresh payload builder'
);
mtuc_cf_assert(
	false !== strpos( $calculator_src, 'mtuc_build_checkout_unified_scheme_options' )
	|| false !== strpos( (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/class-mtuc-payment-gateway.php' ), 'mtuc_resolve_checkout_scheme_common' ),
	'checkout/gateway still consume unified scheme helpers'
);

// ---------------------------------------------------------------------------
// Domain remains in calculator
// ---------------------------------------------------------------------------

foreach (
	array(
		'function mtuc_build_cart_calculator_context',
		'function mtuc_can_render_cart_calculator_shell',
		'function mtuc_get_cart_popup_context',
		'function mtuc_resolve_cart_scheme_state',
		'function mtuc_calculate_cart_popup_credit',
		'function mtuc_is_cart_split_required',
		'function mtuc_send_cart_split_required_notification',
		'function mtuc_get_cart_line_entries',
		'function mtuc_get_cart_line_scheme_options',
	) as $needle
) {
	mtuc_cf_assert(
		false !== strpos( $calculator_src, $needle ),
		'calculator retains ' . $needle
	);
}

mtuc_cf_assert(
	false !== strpos( $frontend_src, 'function mtuc_ajax_cart_split_notify' )
	&& false === strpos( $frontend_src, 'function mtuc_send_cart_split_required_notification' ),
	'only thin split-notify AJAX adapter moved; domain send stays in calculator'
);

fwrite( STDOUT, 'OK cart-frontend ' . $mtuc_cf_assert_count . " assertions\n" );
