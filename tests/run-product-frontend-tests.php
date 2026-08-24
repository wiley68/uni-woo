<?php
/**
 * Characterization tests for product frontend orchestration (AUD-WOO-016 Step 8).
 *
 * Run: php tests/run-product-frontend-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_pf_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_pf_assert( bool $ok, string $message ): void {
	global $mtuc_pf_assert_count;
	++$mtuc_pf_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

$frontend_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-product-frontend.php' );
$functions_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/functions.php' );
$popup_src     = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-product-popup.php' );
$bootstrap_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/mtunicredit.php' );
$calc_js       = (string) file_get_contents( MTUC_PLUGIN_DIR . '/js/mtuc-product-calculator.js' );
$ajax_src      = $frontend_src;

// ---------------------------------------------------------------------------
// Ownership / dependency direction
// ---------------------------------------------------------------------------

mtuc_pf_assert(
	false !== strpos( $bootstrap_src, '/mtuc-product-frontend.php' ),
	'plugin loads mtuc-product-frontend.php'
);
mtuc_pf_assert(
	false !== strpos( $frontend_src, 'function mtuc_register_product_hooks' ),
	'frontend module owns product hook registration'
);
mtuc_pf_assert(
	false === strpos( $functions_src, 'function mtuc_register_product_hooks' )
	&& false === strpos( $functions_src, 'function mtuc_enqueue_product_assets' )
	&& false === strpos( $functions_src, 'function mtuc_get_product_calculator_context' ),
	'functions.php no longer defines product frontend orchestration'
);
mtuc_pf_assert(
	false === strpos( $popup_src, 'function mtuc_register_product_popup_ajax_hooks' )
	&& false === strpos( $popup_src, 'function mtuc_ajax_product_calculator_refresh' )
	&& false === strpos( $popup_src, 'function mtuc_build_product_calculator_refresh_payload' ),
	'product-popup no longer owns refresh AJAX adapters'
);
mtuc_pf_assert(
	false !== strpos( $frontend_src, 'mtuc_get_product_calculator_offer' )
	&& false !== strpos( $frontend_src, 'mtuc_resolve_authoritative_product_financing_line' ),
	'frontend delegates to offer selection / authoritative line helpers'
);
mtuc_pf_assert(
	false === strpos( $frontend_src, 'round( $price / $months' )
	&& false === strpos( $frontend_src, 'function mtuc_compute_financing_amounts' )
	&& false === strpos( $frontend_src, 'function mtuc_resolve_parva_calculation_state' ),
	'frontend does not duplicate calculator/parva formulas'
);

// ---------------------------------------------------------------------------
// Hook registration contract
// ---------------------------------------------------------------------------

mtuc_pf_assert(
	false !== strpos( $frontend_src, "add_action( \$hook, 'mtuc_render_product_calculator', 15 )" ),
	'product calculator render hook priority 15 preserved'
);
mtuc_pf_assert(
	false !== strpos( $frontend_src, "add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_product_assets' )" ),
	'product enqueue hook registered'
);
mtuc_pf_assert(
	false !== strpos( $frontend_src, "add_action( 'wp_footer', 'mtuc_render_credit_popup', 5 )" ),
	'popup footer hook priority 5 preserved'
);
mtuc_pf_assert(
	false !== strpos( $frontend_src, "add_action( 'wp_ajax_mtuc_product_calculator_refresh'" )
	&& false !== strpos( $frontend_src, "add_action( 'wp_ajax_nopriv_mtuc_product_calculator_refresh'" )
	&& false !== strpos( $frontend_src, "add_action( 'wp_ajax_mtuc_popup_calculate'" ),
	'product AJAX actions registered'
);
mtuc_pf_assert(
	false !== strpos( $bootstrap_src, 'mtuc_register_product_popup_ajax_hooks()' )
	&& false !== strpos( $bootstrap_src, 'mtuc_register_product_hooks()' ),
	'bootstrap still calls product registration helpers'
);

// ---------------------------------------------------------------------------
// Enqueue / localization contract
// ---------------------------------------------------------------------------

foreach (
	array(
		"'mtuc-product'",
		"'mtuc-popup'",
		"'mtuc-product-calculator'",
		"'mtuc-product-popup'",
		"'mtucCalculator'",
		"'mtucPopup'",
		"'ajaxUrl'",
		"'nonce'",
		"'productId'",
		"'enabledMonthsByOffer'",
		"'defaultSchemeByOffer'",
		"'currencyDual'",
		"'process2'",
		"'i18n'",
	) as $needle
) {
	mtuc_pf_assert( false !== strpos( $frontend_src, $needle ), 'enqueue/bootstrap contains ' . $needle );
}

mtuc_pf_assert(
	false !== strpos( $frontend_src, "array( 'jquery' )" )
	&& false !== strpos( $frontend_src, "array( 'jquery', 'mtuc-product-calculator' )" )
	&& false !== strpos( $frontend_src, "array( 'mtuc-fonts' )" ),
	'script/style dependencies preserved'
);

// ---------------------------------------------------------------------------
// AJAX adapter + authoritative price (AUD-WOO-001)
// ---------------------------------------------------------------------------

mtuc_pf_assert(
	false !== strpos( $ajax_src, 'mtuc_resolve_authoritative_product_financing_line' ),
	'refresh AJAX uses authoritative product financing line'
);
mtuc_pf_assert(
	false === strpos( $ajax_src, "\$_POST['line_price']" )
	&& false === strpos( $ajax_src, '$_POST["line_price"]' ),
	'refresh AJAX does not trust browser line_price'
);
mtuc_pf_assert(
	false !== strpos( $calc_js, 'line_price:' )
	&& false !== strpos( $calc_js, 'mtuc_product_calculator_refresh' ),
	'JS still posts refresh action (server ignores browser price)'
);
mtuc_pf_assert(
	false !== strpos( $ajax_src, 'variation_id' )
	&& false !== strpos( $ajax_src, 'quantity' )
	&& false !== strpos( $ajax_src, 'product_id' ),
	'refresh AJAX reads product_id / variation_id / quantity'
);
mtuc_pf_assert(
	false !== strpos( $frontend_src, "'variation_id'" )
	&& false !== strpos( $frontend_src, 'mtuc_get_product_variation_id' ),
	'refresh payload exposes variation_id from server product'
);

// ---------------------------------------------------------------------------
// Popup / offer integration remains delegated
// ---------------------------------------------------------------------------

mtuc_pf_assert(
	false !== strpos( $popup_src, 'function mtuc_get_product_popup_context' )
	&& false !== strpos( $popup_src, 'function mtuc_calculate_popup_credit' )
	&& false !== strpos( $popup_src, 'function mtuc_render_credit_popup' ),
	'popup domain/render helpers remain in mtuc-product-popup.php'
);
mtuc_pf_assert(
	false !== strpos( $functions_src, 'function mtuc_get_product_calculator_offer' ),
	'product offer orchestration entry remains in functions.php'
);
mtuc_pf_assert(
	false !== strpos( (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-product-offer-selection.php' ), 'function mtuc_build_button_offer' ),
	'button offer build stays in product-offer-selection'
);

fwrite( STDOUT, 'OK product-frontend ' . $mtuc_pf_assert_count . " assertions\n" );
