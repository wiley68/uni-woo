<?php
/**
 * v2.0.2 pre-change characterization tests.
 *
 * Run: php tests/run-v202-characterization-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_v202_assert_count = 0;

function mtuc_v202_assert( bool $ok, string $message ): void {
	global $mtuc_v202_assert_count;
	++$mtuc_v202_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'mtuc_get_shop_coeff_list' ) ) {
	function mtuc_get_shop_coeff_list( array $shop ): array {
		return is_array( $shop['coeff_list'] ?? null ) ? $shop['coeff_list'] : array();
	}
}

if ( ! function_exists( 'mtuc_find_coeff_entry' ) ) {
	function mtuc_find_coeff_entry( array $coeff_list, string $kop_code, int $months ): ?array {
		foreach ( $coeff_list as $entry ) {
			if ( $kop_code === trim( (string) ( $entry['onlineProductCode'] ?? '' ) ) && $months === (int) ( $entry['installmentCount'] ?? 0 ) ) {
				return $entry;
			}
		}
		return null;
	}
}

if ( ! function_exists( 'mtuc_get_shop_schema_filter_by_id' ) ) {
	function mtuc_get_shop_schema_filter_by_id( array $shop, int $filter_id ): ?array {
		foreach ( (array) ( $shop['kop']['by_schema']['filters'] ?? array() ) as $filter ) {
			if ( is_array( $filter ) && $filter_id === (int) ( $filter['id'] ?? 0 ) ) {
				return $filter;
			}
		}
		return null;
	}
}
if ( ! function_exists( 'mtuc_format_popup_percent_display' ) ) {
	function mtuc_format_popup_percent_display( float $value ): string {
		return number_format( abs( $value ), 2, '.', '' );
	}
}
if ( ! function_exists( 'mtuc_format_popup_amount_display' ) ) {
	function mtuc_format_popup_amount_display( float $value, array $shop ): array {
		unset( $shop );
		return array( 'primary' => number_format( $value, 2, '.', '' ), 'secondary' => '', 'dual' => false );
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-calculator.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-product-offer-selection.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cart-calculator.php';

// Stable exact identity: type + months + filter id, with KOP checked by defaults.
$identity_options = array(
	mtuc_build_popup_scheme_option_row( 6, 3, 'ZERO', '', 'promo' ),
	mtuc_build_popup_scheme_option_row( 12, 1, 'PROMO', '', 'standard' ),
);
mtuc_v202_assert( mtuc_is_popup_scheme_option_enabled( $identity_options, 6, 3, 'promo' ), 'exact scheme identity is enabled' );
mtuc_v202_assert( ! mtuc_is_popup_scheme_option_enabled( $identity_options, 6, 4, 'promo' ), 'different filter id is invalid' );
mtuc_v202_assert( ! mtuc_is_popup_scheme_option_enabled( $identity_options, 6, 3, 'standard' ), 'different scheme type is invalid' );

// Existing Product representative semantics: preferred month, then lowest monthly.
$representative = mtuc_pick_preferred_button_offer(
	array(
		array( 'installment_count' => 12, 'monthly_installment' => 93.33, 'kop_code' => 'BASE' ),
		array( 'installment_count' => 12, 'monthly_installment' => 85.55, 'kop_code' => 'PROMO' ),
		array( 'installment_count' => 18, 'monthly_installment' => 70.00, 'kop_code' => 'LONG' ),
	),
	array( 'uni_shema_current' => 12 )
);
mtuc_v202_assert( is_array( $representative ) && 'PROMO' === $representative['kop_code'], 'Product representative picks lowest monthly at CP preferred month' );

// Existing popup default: exact button months+KOP wins over CP month fallback.
$default_key = mtuc_pick_default_popup_scheme_key(
	array( 'uni_shema_current' => 6 ),
	$identity_options,
	array( 'installment_count' => 12, 'kop_code' => 'PROMO' )
);
mtuc_v202_assert( '12:1' === $default_key, 'popup default follows exact representative months+KOP' );

mtuc_v202_assert( mtuc_is_zero_interest_coeff_entry( array( 'interestPercent' => 0.000001 ) ), 'zero-interest epsilon accepts near zero' );
mtuc_v202_assert( ! mtuc_is_zero_interest_coeff_entry( array( 'interestPercent' => 0.01 ) ), 'zero-interest epsilon rejects non-zero' );

// Existing Checkout automatic behavior prefers the longest eligible 0% option.
$checkout_shop = array(
	'coeff_list' => array(
		array( 'onlineProductCode' => 'ZERO', 'installmentCount' => 6, 'interestPercent' => 0.0 ),
		array( 'onlineProductCode' => 'ZERO', 'installmentCount' => 24, 'interestPercent' => 0.0 ),
	),
);
$checkout_options = array(
	mtuc_build_popup_scheme_option_row( 6, 3, 'ZERO', '', 'promo' ),
	mtuc_build_popup_scheme_option_row( 24, 4, 'ZERO', '', 'promo' ),
);
mtuc_v202_assert( 'p:24:4' === mtuc_pick_default_checkout_scheme_key( $checkout_shop, $checkout_options ), 'Checkout prefers longest 0% option' );

// Confirmed Cart parity fixture: representative and popup use the locked parva.
$shop = array(
	'uni_first_vnoska' => 0,
	'uni_eur'          => 3,
	'kop'              => array(
		'by_schema' => array(
			'filters' => array(
				array( 'id' => 1, 'uni_parva' => 1 ),
				array( 'id' => 2, 'uni_parva' => 0 ),
			),
		),
	),
);
$coeff       = array( 'onlineProductCode' => 'PROMO', 'installmentCount' => 12, 'coeff' => 0.09333, 'interestPercent' => 21.45 );
$base_coeff  = array( 'onlineProductCode' => 'BASE', 'installmentCount' => 12, 'coeff' => 0.097487, 'interestPercent' => 30.0 );
$option      = mtuc_build_popup_scheme_option_row( 12, 1, 'PROMO', '', 'standard' );
$base_option = mtuc_build_popup_scheme_option_row( 12, 2, 'BASE', '', 'standard' );
$button      = mtuc_build_cart_button_offer_from_options( $shop, array( $coeff, $base_coeff ), 1000.00, array( $base_option, $option ), 'standard' );
$popup       = mtuc_calculate_cart_popup_credit( $shop, array( $coeff, $base_coeff ), 1000.00, 12, 'standard', 0.0, 1, 'standard', array( $base_option, $option ) );
mtuc_v202_assert( is_array( $button ) && 'PROMO' === $button['kop_code'] && 85.55 === $button['monthly_installment'], 'Cart standard button selects locked-parva promo candidate' );
mtuc_v202_assert( is_array( $popup ) && 85.55 === $popup['monthly_installment'], 'popup locked-parva result is 85.55' );
mtuc_v202_assert( $button['monthly_installment'] === $popup['monthly_installment'], 'Cart button and popup monthly amounts match' );
mtuc_v202_assert( '12:1' === $popup['scheme_key'] && 'PROMO' === $popup['kop_code'], 'Cart popup uses the same exact promo identity' );

// Intersection contract remains type + KOP + months; filter id is metadata.
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cart-scheme-intersection.php';
$common = mtuc_intersect_cart_scheme_options(
	array(
		array( mtuc_build_popup_scheme_option_row( 12, 10, 'SAME', '', 'standard' ) ),
		array( mtuc_build_popup_scheme_option_row( 12, 20, 'SAME', '', 'standard' ) ),
	)
);
mtuc_v202_assert( 1 === count( $common ) && 10 === $common[0]['filter_id'], 'Cart intersection ignores filter id and retains metadata' );

// Conflicting cross-line parva is retained as eligible but unsupported for preview.
$conflict_a = mtuc_build_popup_scheme_option_row( 12, 10, 'SAME', '', 'standard', array( 'automatic_first_installment' => true ) );
$conflict_b = mtuc_build_popup_scheme_option_row( 12, 20, 'SAME', '', 'standard', array( 'automatic_first_installment' => false ) );
$conflict_1 = mtuc_intersect_cart_scheme_options( array( array( $conflict_a ), array( $conflict_b ) ) );
$conflict_2 = mtuc_intersect_cart_scheme_options( array( array( $conflict_b ), array( $conflict_a ) ) );
mtuc_v202_assert( empty( $conflict_1[0]['parva_policy_consistent'] ) && empty( $conflict_2[0]['parva_policy_consistent'] ), 'conflicting parva is detected in either line order' );
$same_coeff = array( 'onlineProductCode' => 'SAME', 'installmentCount' => 12, 'coeff' => 0.09333, 'interestPercent' => 21.45 );
mtuc_v202_assert( null === mtuc_build_cart_button_offer_from_options( $shop, array( $same_coeff ), 1000.0, $conflict_1, 'standard' ), 'ambiguous parva does not build a representative candidate' );
mtuc_v202_assert( null === mtuc_build_cart_button_offer_from_options( $shop, array( $same_coeff ), 1000.0, $conflict_2, 'standard' ), 'ambiguous reverse order gives the same result' );

// Invalid-prefill normalization is server-authoritative; script config does not reread raw session.
$checkout_source = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-checkout-payment.php' );
mtuc_v202_assert( false !== strpos( $checkout_source, 'mtuc_is_popup_scheme_option_enabled' ), 'server validates exact Checkout prefill' );
mtuc_v202_assert( 1 === substr_count( $checkout_source, '$prefill = mtuc_get_checkout_prefill_session();' ), 'only validation path reads raw prefill session' );

fwrite( STDOUT, 'OK v202-characterization ' . $mtuc_v202_assert_count . " assertions\n" );
