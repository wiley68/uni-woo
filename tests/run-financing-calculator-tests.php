<?php
/**
 * Characterization tests for core financing calculator (AUD-WOO-016 Step 1 / AUD-WOO-014).
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

// Locked first installment characterization (same rules as mtuc_resolve_parva_calculation_state).
$locked_parva = round( 1000.0 / 24, 2 );
mtuc_fc_assert( 41.67 === $locked_parva, 'locked parva round(price/months,2)' );
$locked_amounts = mtuc_compute_financing_amounts( 1000.0, $locked_parva, 0.045833, 24 );
mtuc_fc_assert( is_array( $locked_amounts ), 'locked parva amounts ok' );
mtuc_fc_assert( 958.33 === $locked_amounts['loan_amount'], 'locked parva principal' );
mtuc_fc_assert( 43.92 === $locked_amounts['monthly_installment'], 'locked parva monthly' );

// Coefficient precision retained through float multiply before 2dp round.
$precise = mtuc_compute_financing_amounts( 1400.0, 0.0, 0.091234567, 12 );
mtuc_fc_assert( is_array( $precise ), 'precise coeff ok' );
mtuc_fc_assert( 127.73 === $precise['monthly_installment'], 'long coeff rounds monthly to 2dp' );

// Interest display finalize (popup/cart contract).
$rates = mtuc_finalize_financing_interest_rates( 12, 8.33, 100.01, -10.5 );
mtuc_fc_assert( 10.5 === $rates['glp'], 'GLP uses abs + round 2' );
mtuc_fc_assert( is_float( $rates['gpr'] ) || is_int( $rates['gpr'] ), 'GPR is numeric' );

$zero_gpr = mtuc_finalize_financing_interest_rates( 12, 8.333333333, 100.0, 0.0 );
// Near-zero interest schedule may clamp GPR to 0 when <= 0.1.
mtuc_fc_assert( array_key_exists( 'gpr', $zero_gpr ), 'finalize returns gpr key' );

// Stability across repeated calls.
$a = mtuc_compute_financing_amounts( 874.01, 50.01, 0.166667, 6 );
$b = mtuc_compute_financing_amounts( 874.01, 50.01, 0.166667, 6 );
mtuc_fc_assert( $a === $b, 'amounts stable across repeats' );

fwrite( STDOUT, 'OK: ' . $mtuc_fc_assert_count . ' financing calculator assertions passed' . PHP_EOL );
exit( 0 );
