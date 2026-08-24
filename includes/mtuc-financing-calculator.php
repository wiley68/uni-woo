<?php
/**
 * Core financing calculation helpers (AUD-WOO-016 Step 1).
 *
 * Pure installment math shared by product popup and cart calculators.
 * Does not perform remote calls, order mutation, or status transitions.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compute financed principal, monthly installment and total payable.
 *
 * Contract (AUD-WOO-014):
 * principal = round(price - first_installment, 2)
 * monthly   = round(principal * coefficient, 2)
 * total     = round(monthly * months, 2)
 *
 * @param float  $price                 Financeable price / cart total.
 * @param float  $first_installment     Resolved first installment (parva).
 * @param float  $coefficient           Bank coefficient (kimb).
 * @param int    $months                Installment count.
 * @param string $invalid_loan_error_code WP_Error code when principal is not positive.
 * @return array{loan_amount:float,monthly_installment:float,total_payable:float}|WP_Error
 */
function mtuc_compute_financing_amounts(
	float $price,
	float $first_installment,
	float $coefficient,
	int $months,
	string $invalid_loan_error_code = 'mtuc_invalid_loan'
) {
	$loan_amount = round( $price - $first_installment, 2 );
	if ( $loan_amount <= 0 ) {
		return new WP_Error(
			$invalid_loan_error_code,
			__( 'Общата сума на заема трябва да е положителна.', 'mtunicredit' )
		);
	}

	$monthly_installment = round( $loan_amount * $coefficient, 2 );
	$total_payable       = round( $monthly_installment * $months, 2 );

	return array(
		'loan_amount'         => $loan_amount,
		'monthly_installment' => $monthly_installment,
		'total_payable'       => $total_payable,
	);
}

/**
 * Normalize GLP/GPR for popup and cart calculation payloads.
 *
 * @param int   $months              Installment count.
 * @param float $monthly_installment Monthly installment.
 * @param float $loan_amount         Financed principal.
 * @param float $interest_percent    Raw interestPercent from coeff_list.
 * @return array{glp:float,gpr:float}
 */
function mtuc_finalize_financing_interest_rates(
	int $months,
	float $monthly_installment,
	float $loan_amount,
	float $interest_percent
): array {
	$gpr = mtuc_calculate_gpr( $months, $monthly_installment, $loan_amount );
	$gpr = $gpr <= 0.1 ? 0.0 : round( $gpr, 2 );

	return array(
		'glp' => round( abs( $interest_percent ), 2 ),
		'gpr' => $gpr,
	);
}

/**
 * Calculate GPR from installment schedule (legacy UniCredit formula).
 *
 * @param int   $months              Installment count.
 * @param float $monthly_installment Monthly installment amount.
 * @param float $price               Financed principal / price.
 * @return float
 */
function mtuc_calculate_gpr( int $months, float $monthly_installment, float $price ): float {
	if ( $months <= 0 || $price <= 0 || $monthly_installment <= 0 ) {
		return 0.0;
	}

	$period_rate = mtuc_financial_rate( $months, -1 * $monthly_installment, $price );
	$gprm        = ( $period_rate * $months ) / ( $months / 12 );

	return abs( ( pow( ( 1 + $gprm / 12 ), 12 ) - 1 ) * 100 );
}

/**
 * Financial rate helper (ported from legacy UNI_RATE).
 *
 * @param float $periods       Number of periods.
 * @param float $payment       Payment per period.
 * @param float $present_value Present value.
 * @return float
 */
function mtuc_financial_rate( float $periods, float $payment, float $present_value ): float {
	$rate = 0.1;
	$type = 0.0;
	$fv   = 0.0;

	if ( abs( $rate ) < 1.0e-8 ) {
		$y = $present_value * ( 1 + $periods * $rate ) + $payment * ( 1 + $rate * $type ) * $periods + $fv;
	} else {
		$f = exp( $periods * log( 1 + $rate ) );
		$y = $present_value * $f + $payment * ( 1 / $rate + $type ) * ( $f - 1 ) + $fv;
	}

	$y0 = $present_value + $payment * $periods + $fv;
	$y1 = $y;
	$i  = 0.0;
	$x0 = 0.0;
	$x1 = $rate;

	while ( ( abs( $y0 - $y1 ) > 1.0e-8 ) && ( $i < 128 ) ) {
		$rate = ( $y1 * $x0 - $y0 * $x1 ) / ( $y1 - $y0 );
		$x0   = $x1;
		$x1   = $rate;

		if ( abs( $rate ) < 1.0e-8 ) {
			$y = $present_value * ( 1 + $periods * $rate ) + $payment * ( 1 + $rate * $type ) * $periods + $fv;
		} else {
			$f = exp( $periods * log( 1 + $rate ) );
			$y = $present_value * $f + $payment * ( 1 / $rate + $type ) * ( $f - 1 ) + $fv;
		}

		$y0 = $y1;
		$y1 = $y;
		++$i;
	}

	return $rate;
}
