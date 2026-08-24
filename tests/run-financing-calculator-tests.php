<?php
/**
 * Characterization tests for core financing calculator (AUD-WOO-016 Steps 1–2 / AUD-WOO-014).
 *
 * Run: php tests/run-financing-calculator-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_fc_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_fc_assert( bool $ok, string $message ): void {
	global $mtuc_fc_assert_count;
	++$mtuc_fc_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-calculator.php';

// ---------------------------------------------------------------------------
// AUD-WOO-014 rounding contract via extracted helper
// ---------------------------------------------------------------------------

$cases = array(
	array( 100.01, 0.0, 0.083333, 12, 100.01, 8.33, 99.96 ),
	array( 100.05, 0.0, 0.083333, 12, 100.05, 8.34, 100.08 ),
	array( 999.99, 0.0, 0.083333, 12, 999.99, 83.33, 999.96 ),
	array( 874.01, 50.01, 0.166667, 6, 824.00, 137.33, 823.98 ),
	array( 1500.00, 100.00, 0.091234567, 24, 1400.00, 127.73, 3065.52 ),
	array( 97.49, 0.0, 0.083333, 12, 97.49, 8.12, 97.44 ),
	array( 199.99, 19.99, 0.09375, 18, 180.00, 16.88, 303.84 ),
	array( 15.01, 0.0, 0.18, 3, 15.01, 2.70, 8.10 ),
	array( 1234.56, 0.0, 0.0416666666667, 36, 1234.56, 51.44, 1851.84 ),
);

foreach ( $cases as $idx => $case ) {
	[ $price, $parva, $coeff, $months, $exp_loan, $exp_monthly, $exp_total ] = $case;
	$result = mtuc_compute_financing_amounts( $price, $parva, $coeff, $months );
	mtuc_fc_assert( is_array( $result ), "case {$idx} returns array" );
	mtuc_fc_assert( $exp_loan === $result['loan_amount'], "case {$idx} principal" );
	mtuc_fc_assert( $exp_monthly === $result['monthly_installment'], "case {$idx} monthly" );
	mtuc_fc_assert( $exp_total === $result['total_payable'], "case {$idx} total" );
}

$invalid = mtuc_compute_financing_amounts( 100.0, 100.0, 0.1, 12, 'mtuc_popup_invalid_loan' );
mtuc_fc_assert( is_wp_error( $invalid ), 'zero principal fails' );
mtuc_fc_assert( 'mtuc_popup_invalid_loan' === $invalid->get_error_code(), 'error code preserved for popup' );

$invalid_cart = mtuc_compute_financing_amounts( 50.0, 60.0, 0.1, 12, 'mtuc_cart_invalid_loan' );
mtuc_fc_assert( is_wp_error( $invalid_cart ), 'negative principal fails' );
mtuc_fc_assert( 'mtuc_cart_invalid_loan' === $invalid_cart->get_error_code(), 'error code preserved for cart' );

// Coefficient precision retained through float multiply before 2dp round.
$precise = mtuc_compute_financing_amounts( 1400.0, 0.0, 0.091234567, 12 );
mtuc_fc_assert( is_array( $precise ), 'precise coeff ok' );
mtuc_fc_assert( 127.73 === $precise['monthly_installment'], 'long coeff rounds monthly to 2dp' );

// Interest display finalize (popup/cart contract).
$rates = mtuc_finalize_financing_interest_rates( 12, 8.33, 100.01, -10.5 );
mtuc_fc_assert( 10.5 === $rates['glp'], 'GLP uses abs + round 2' );
mtuc_fc_assert( is_float( $rates['gpr'] ) || is_int( $rates['gpr'] ), 'GPR is numeric' );

$zero_gpr = mtuc_finalize_financing_interest_rates( 12, 8.333333333, 100.0, 0.0 );
mtuc_fc_assert( array_key_exists( 'gpr', $zero_gpr ), 'finalize returns gpr key' );

$a = mtuc_compute_financing_amounts( 874.01, 50.01, 0.166667, 6 );
$b = mtuc_compute_financing_amounts( 874.01, 50.01, 0.166667, 6 );
mtuc_fc_assert( $a === $b, 'amounts stable across repeats' );

// ---------------------------------------------------------------------------
// AUD-WOO-016 Step 2 — first-installment / parva state resolution
// ---------------------------------------------------------------------------

$shop_hidden = array( 'uni_first_vnoska' => 0 );
$shop_shown  = array( 'uni_first_vnoska' => 1 );
$filter_lock = array( 'uni_parva' => 1 );
$filter_free = array( 'uni_parva' => 0 );

$zero = mtuc_resolve_parva_calculation_state( $shop_hidden, 1000.0, 12, 0.0, null );
mtuc_fc_assert( 0.0 === $zero['parva'], 'hidden field: zero parva' );
mtuc_fc_assert( false === $zero['parva_locked'], 'hidden field: not locked' );
mtuc_fc_assert( false === $zero['show_parva'], 'hidden field: not shown' );

$ignored_user = mtuc_resolve_parva_calculation_state( $shop_hidden, 1000.0, 12, 250.0, null );
mtuc_fc_assert( 0.0 === $ignored_user['parva'], 'hidden field ignores user parva' );

$valid_user = mtuc_resolve_parva_calculation_state( $shop_shown, 1000.0, 12, 100.0, null );
mtuc_fc_assert( 100.0 === $valid_user['parva'], 'shown field accepts valid user parva' );
mtuc_fc_assert( false === $valid_user['parva_locked'], 'shown field editable' );
mtuc_fc_assert( true === $valid_user['show_parva'], 'shown field visible' );

$clamp_high = mtuc_resolve_parva_calculation_state( $shop_shown, 1000.0, 12, 1500.0, null );
mtuc_fc_assert( 1000.0 === $clamp_high['parva'], 'user parva clamped to price' );

$clamp_neg = mtuc_resolve_parva_calculation_state( $shop_shown, 1000.0, 12, -25.0, null );
mtuc_fc_assert( 0.0 === $clamp_neg['parva'], 'negative user parva clamped to 0' );

$round_user = mtuc_resolve_parva_calculation_state( $shop_shown, 100.05, 12, 10.019, null );
mtuc_fc_assert( 10.02 === $round_user['parva'], 'user parva rounded to 2dp before clamp' );

$locked = mtuc_resolve_parva_calculation_state( $shop_hidden, 1000.0, 24, 9999.0, $filter_lock );
mtuc_fc_assert( 41.67 === $locked['parva'], 'locked parva = round(price/months,2)' );
mtuc_fc_assert( true === $locked['parva_locked'], 'filter uni_parva locks' );
mtuc_fc_assert( true === $locked['show_parva'], 'locked forces show_parva even if shop hid it' );

$locked_ignores_user = mtuc_resolve_parva_calculation_state( $shop_shown, 1000.0, 24, 10.0, $filter_lock );
mtuc_fc_assert( 41.67 === $locked_ignores_user['parva'], 'locked ignores user-entered parva' );

$unlocked_filter = mtuc_resolve_parva_calculation_state( $shop_shown, 500.0, 12, 50.0, $filter_free );
mtuc_fc_assert( 50.0 === $unlocked_filter['parva'], 'uni_parva=0 uses user path when shown' );
mtuc_fc_assert( false === $unlocked_filter['parva_locked'], 'uni_parva=0 not locked' );

$cents = mtuc_resolve_parva_calculation_state( $shop_shown, 199.99, 18, 19.99, null );
mtuc_fc_assert( 19.99 === $cents['parva'], 'decimal-cent price accepts matching user parva' );

$qty_price = 120.50 * 3;
$qty_state = mtuc_resolve_parva_calculation_state( $shop_shown, $qty_price, 12, 50.0, null );
mtuc_fc_assert( 50.0 === $qty_state['parva'], 'quantity-expanded line total accepted as price input' );

$locked_zero_months = mtuc_resolve_parva_calculation_state( $shop_shown, 1000.0, 0, 100.0, $filter_lock );
mtuc_fc_assert( 100.0 === $locked_zero_months['parva'], 'months<=0 skips lock branch' );
mtuc_fc_assert( false === $locked_zero_months['parva_locked'], 'months<=0 cannot lock' );

// Integration: resolver → compute_financing_amounts (popup/cart contract).
$pipeline_shop   = $shop_shown;
$pipeline_price  = 1000.0;
$pipeline_months = 24;
$pipeline_coeff  = 0.045833;
$pipeline_state  = mtuc_resolve_parva_calculation_state( $pipeline_shop, $pipeline_price, $pipeline_months, 9999.0, $filter_lock );
$pipeline_amounts = mtuc_compute_financing_amounts(
	$pipeline_price,
	$pipeline_state['parva'],
	$pipeline_coeff,
	$pipeline_months,
	'mtuc_popup_invalid_loan'
);
mtuc_fc_assert( is_array( $pipeline_amounts ), 'pipeline amounts ok' );
mtuc_fc_assert( 41.67 === $pipeline_state['parva'], 'pipeline locked parva' );
mtuc_fc_assert( 958.33 === $pipeline_amounts['loan_amount'], 'pipeline principal' );
mtuc_fc_assert( 43.92 === $pipeline_amounts['monthly_installment'], 'pipeline monthly' );
mtuc_fc_assert( 1054.08 === $pipeline_amounts['total_payable'], 'pipeline total' );

$user_pipeline = mtuc_resolve_parva_calculation_state( $shop_shown, 874.01, 6, 50.01, null );
$user_amounts  = mtuc_compute_financing_amounts( 874.01, $user_pipeline['parva'], 0.166667, 6 );
mtuc_fc_assert( is_array( $user_amounts ), 'user pipeline amounts ok' );
mtuc_fc_assert( 50.01 === $user_pipeline['parva'], 'user pipeline parva' );
mtuc_fc_assert( 824.0 === $user_amounts['loan_amount'], 'user pipeline principal' );
mtuc_fc_assert( 137.33 === $user_amounts['monthly_installment'], 'user pipeline monthly' );
mtuc_fc_assert( 823.98 === $user_amounts['total_payable'], 'user pipeline total' );

fwrite( STDOUT, 'OK: ' . $mtuc_fc_assert_count . ' financing calculator assertions passed' . PHP_EOL );
exit( 0 );
