<?php
/**
 * Characterization tests for product/button offer selection (AUD-WOO-016 Step 5).
 *
 * Run: php tests/run-product-offer-selection-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_pos_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_pos_assert( bool $ok, string $message ): void {
	global $mtuc_pos_assert_count;
	++$mtuc_pos_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'mtuc_get_shop_coeff_list' ) ) {
	/**
	 * @param array<string, mixed> $shop Shop.
	 * @return array<int, array<string, mixed>>
	 */
	function mtuc_get_shop_coeff_list( array $shop ): array {
		return is_array( $shop['coeff_list'] ?? null ) ? $shop['coeff_list'] : array();
	}
}

if ( ! function_exists( 'mtuc_find_coeff_entry' ) ) {
	/**
	 * @param array<int, array<string, mixed>> $coeff_list Coeff rows.
	 * @param string                           $kop_code   KOP.
	 * @param int                              $months     Months.
	 * @return array<string, mixed>|null
	 */
	function mtuc_find_coeff_entry( array $coeff_list, string $kop_code, int $months ): ?array {
		foreach ( $coeff_list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$code = isset( $entry['onlineProductCode'] ) ? trim( (string) $entry['onlineProductCode'] ) : '';
			$m    = isset( $entry['installmentCount'] ) ? (int) $entry['installmentCount'] : 0;
			if ( $code === $kop_code && $m === $months ) {
				return $entry;
			}
		}

		return null;
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-calculator.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-product-offer-selection.php';

// ---------------------------------------------------------------------------
// Option identity / parse
// ---------------------------------------------------------------------------

mtuc_pos_assert( '12:5' === mtuc_build_popup_scheme_option_key( 12, 5, 'standard' ), 'standard key months:filter' );
mtuc_pos_assert( 'p:12:5' === mtuc_build_popup_scheme_option_key( 12, 5, 'promo' ), 'promo key prefixed' );
mtuc_pos_assert( '12:5' === mtuc_build_popup_scheme_option_key( 12, 5 ), 'default scheme type standard' );

$parsed_std = mtuc_parse_popup_scheme_option_key( '18:9' );
mtuc_pos_assert( 'standard' === $parsed_std['scheme_type'] && 18 === $parsed_std['months'] && 9 === $parsed_std['filter_id'], 'parse standard key' );

$parsed_promo = mtuc_parse_popup_scheme_option_key( 'p:24:3' );
mtuc_pos_assert( 'promo' === $parsed_promo['scheme_type'] && 24 === $parsed_promo['months'] && 3 === $parsed_promo['filter_id'], 'parse promo key' );

$row_a = mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', 'A', 'standard' );
$row_b = mtuc_build_popup_scheme_option_row( 12, 2, 'CAT', 'B', 'standard' );
mtuc_pos_assert( '12:1' === $row_a['key'] && '12:2' === $row_b['key'], 'filter id keeps options distinct' );
mtuc_pos_assert( $row_a['key'] !== mtuc_build_popup_scheme_option_key( 12, 1, 'promo' ), 'standard vs promo keys differ' );

// ---------------------------------------------------------------------------
// Sorting
// ---------------------------------------------------------------------------

$unsorted = array(
	mtuc_build_popup_scheme_option_row( 24, 2, 'B', '', 'standard' ),
	mtuc_build_popup_scheme_option_row( 12, 9, 'A', '', 'promo' ),
	mtuc_build_popup_scheme_option_row( 12, 1, 'A', '', 'standard' ),
	mtuc_build_popup_scheme_option_row( 12, 5, 'A', '', 'standard' ),
);
$sorted = mtuc_sort_popup_scheme_options( $unsorted );
mtuc_pos_assert(
	array( '12:1', '12:5', 'p:12:9', '24:2' ) === array_column( $sorted, 'key' ),
	'sort by months, then standard before promo, then filter_id'
);

// ---------------------------------------------------------------------------
// Default / preferred pick
// ---------------------------------------------------------------------------

$options = mtuc_sort_popup_scheme_options(
	array(
		mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
		mtuc_build_popup_scheme_option_row( 24, 1, 'CAT', '', 'standard' ),
		mtuc_build_popup_scheme_option_row( 36, 2, 'OTH', '', 'standard' ),
	)
);

mtuc_pos_assert(
	'12:1' === mtuc_pick_default_popup_scheme_key( array(), $options, null ),
	'fallback is first sorted option'
);

mtuc_pos_assert(
	'24:1' === mtuc_pick_default_popup_scheme_key( array( 'uni_shema_current' => 24 ), $options, null ),
	'preferred months from shop selected'
);

mtuc_pos_assert(
	'12:1' === mtuc_pick_default_popup_scheme_key(
		array( 'uni_shema_current' => 99 ),
		$options,
		null
	),
	'absent preferred months falls back to first'
);

$button_offer = array(
	'installment_count' => 36,
	'kop_code'          => 'OTH',
);
mtuc_pos_assert(
	'36:2' === mtuc_pick_default_popup_scheme_key( array( 'uni_shema_current' => 12 ), $options, $button_offer ),
	'button offer months+KOP wins over shop preferred'
);

mtuc_pos_assert( '' === mtuc_pick_default_popup_scheme_key( array(), array(), null ), 'empty options → empty key' );

$enabled = array(
	mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
);
mtuc_pos_assert( mtuc_is_popup_scheme_option_enabled( $enabled, 12, 1, 'standard' ), 'enabled match' );
mtuc_pos_assert( ! mtuc_is_popup_scheme_option_enabled( $enabled, 12, 2, 'standard' ), 'enabled miss filter' );
mtuc_pos_assert( ! mtuc_is_popup_scheme_option_enabled( $enabled, 12, 1, 'promo' ), 'enabled miss type' );

$checkout_shop = array(
	'uni_shema_current' => 12,
	'coeff_list'        => array(
		array(
			'onlineProductCode' => 'PRO',
			'installmentCount'  => 12,
			'interestPercent'   => 0.0,
			'coeff'             => 0.083333,
		),
		array(
			'onlineProductCode' => 'PRO',
			'installmentCount'  => 24,
			'interestPercent'   => 0.0,
			'coeff'             => 0.041666,
		),
		array(
			'onlineProductCode' => 'CAT',
			'installmentCount'  => 36,
			'interestPercent'   => 10.0,
			'coeff'             => 0.03,
		),
	),
);
$checkout_options = array(
	mtuc_build_popup_scheme_option_row( 12, 1, 'PRO', '', 'promo' ),
	mtuc_build_popup_scheme_option_row( 24, 1, 'PRO', '', 'promo' ),
	mtuc_build_popup_scheme_option_row( 36, 1, 'CAT', '', 'standard' ),
);
mtuc_pos_assert(
	'p:24:1' === mtuc_pick_default_checkout_scheme_key( $checkout_shop, $checkout_options, null ),
	'checkout prefers longest 0% promo'
);

// ---------------------------------------------------------------------------
// Button preferred pick
// ---------------------------------------------------------------------------

$candidates = array(
	array( 'installment_count' => 12, 'monthly_installment' => 90.0, 'kop_code' => 'A' ),
	array( 'installment_count' => 24, 'monthly_installment' => 50.0, 'kop_code' => 'B' ),
	array( 'installment_count' => 24, 'monthly_installment' => 45.0, 'kop_code' => 'C' ),
	array( 'installment_count' => 36, 'monthly_installment' => 40.0, 'kop_code' => 'D' ),
);

$pref = mtuc_pick_preferred_button_offer( $candidates, array( 'uni_shema_current' => 24 ) );
mtuc_pos_assert( is_array( $pref ) && 'C' === $pref['kop_code'], 'preferred months picks lowest monthly among matches' );

$no_pref = mtuc_pick_preferred_button_offer( $candidates, array() );
mtuc_pos_assert( is_array( $no_pref ) && 'D' === $no_pref['kop_code'], 'no preferred → highest months' );

$lowest = mtuc_pick_lowest_monthly_button_offer( $candidates );
mtuc_pos_assert( is_array( $lowest ) && 40.0 === (float) $lowest['monthly_installment'], 'lowest monthly helper' );

// ---------------------------------------------------------------------------
// Locked parva preview via shared resolver + button offer monthly
// ---------------------------------------------------------------------------

$shop_locked = array(
	'uni_first_vnoska' => 0,
	'uni_eur'          => 0,
);
$filter_locked = array( 'uni_parva' => 1, 'id' => 7 );
$price_cases   = array(
	array( 100.01, 12 ),
	array( 999.99, 6 ),
	array( 874.01, 24 ),
	array( 15.01, 3 ),
);

foreach ( $price_cases as $idx => $case ) {
	[ $price, $months ] = $case;
	$state               = mtuc_resolve_parva_calculation_state( $shop_locked, $price, $months, 0.0, $filter_locked );
	$expected_parva      = round( $price / $months, 2 );
	mtuc_pos_assert( $expected_parva === $state['parva'], "locked parva case {$idx}" );
	mtuc_pos_assert( true === $state['parva_locked'], "locked flag case {$idx}" );

	$principal = round( $price - $state['parva'], 2 );
	$coeff     = 0.083333;
	$offer     = mtuc_build_button_offer(
		'standard',
		'CAT',
		$months,
		$principal,
		array(
			'coeff'           => $coeff,
			'interestPercent' => 10.5,
		),
		$shop_locked
	);
	$popup = mtuc_compute_financing_amounts( $price, $state['parva'], $coeff, $months );
	mtuc_pos_assert( is_array( $offer ) && is_array( $popup ), "parity arrays case {$idx}" );
	mtuc_pos_assert(
		(float) $offer['monthly_installment'] === (float) $popup['monthly_installment'],
		"button/popup monthly parity case {$idx}"
	);
	mtuc_pos_assert(
		(float) $offer['total_amount'] === (float) $popup['loan_amount'],
		"button total_amount is principal (popup loan) case {$idx}"
	);
}

// Zero / non-locked parva preview: full price as principal.
$shop_open = array( 'uni_first_vnoska' => 1, 'uni_eur' => 0 );
$state_zero = mtuc_resolve_parva_calculation_state( $shop_open, 100.01, 12, 0.0, array( 'uni_parva' => 0 ) );
mtuc_pos_assert( 0.0 === $state_zero['parva'] && false === $state_zero['parva_locked'], 'editable zero parva' );

$offer_full = mtuc_build_button_offer(
	'standard',
	'CAT',
	12,
	100.01,
	array( 'coeff' => 0.083333, 'interestPercent' => 10.5 ),
	$shop_open
);
$popup_full = mtuc_compute_financing_amounts( 100.01, 0.0, 0.083333, 12 );
mtuc_pos_assert(
	is_array( $offer_full )
	&& is_array( $popup_full )
	&& (float) $offer_full['monthly_installment'] === (float) $popup_full['monthly_installment'],
	'zero-parva button/popup monthly parity'
);

// Dual-currency text modes preserved (format only).
$text0 = mtuc_format_installment_price_text( 12, 8.33, array( 'uni_eur' => 0 ) );
$text3 = mtuc_format_installment_price_text( 12, 8.33, array( 'uni_eur' => 3 ) );
mtuc_pos_assert( false !== strpos( $text0, '8.33' ) && false !== strpos( $text0, 'лв.' ), 'uni_eur=0 BGN text' );
mtuc_pos_assert( false !== strpos( $text3, '8.33' ) && false !== strpos( $text3, 'евро' ), 'uni_eur=3 EUR text' );

fwrite( STDOUT, 'OK product-offer-selection ' . $mtuc_pos_assert_count . " assertions\n" );
