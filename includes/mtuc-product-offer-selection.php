<?php
/**
 * Pure product/button financing option selection (AUD-WOO-016 Step 5).
 *
 * Given normalized scheme options, price/context and preferences, shapes option
 * identity/rows, sorts, picks defaults, and builds button preview offers.
 * Does not fetch CP config, resolve Woo products, or render HTML.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a stable popup scheme option key (months + schema filter id + scheme type).
 *
 * @param int    $months      Installment count.
 * @param int    $filter_id   Schema filter id (0 for default KOP).
 * @param string $scheme_type standard|promo.
 * @return string
 */
function mtuc_build_popup_scheme_option_key( int $months, int $filter_id = 0, string $scheme_type = 'standard' ): string {
	if ( 'promo' === $scheme_type ) {
		return 'p:' . $months . ':' . $filter_id;
	}

	return $months . ':' . $filter_id;
}

/**
 * Parse popup scheme option key into scheme type, months and filter id.
 *
 * @param string $key Option key from select value.
 * @return array{scheme_type:string,months:int,filter_id:int}
 */
function mtuc_parse_popup_scheme_option_key( string $key ): array {
	if ( 0 === strpos( $key, 'p:' ) ) {
		$parts = explode( ':', substr( $key, 2 ), 2 );

		return array(
			'scheme_type' => 'promo',
			'months'      => isset( $parts[0] ) ? absint( $parts[0] ) : 0,
			'filter_id'   => isset( $parts[1] ) ? absint( $parts[1] ) : 0,
		);
	}

	$parts = explode( ':', $key, 2 );

	return array(
		'scheme_type' => 'standard',
		'months'      => isset( $parts[0] ) ? absint( $parts[0] ) : 0,
		'filter_id'   => isset( $parts[1] ) ? absint( $parts[1] ) : 0,
	);
}

/**
 * Sort popup scheme options by month, type and filter id.
 *
 * @param array<int, array<string, mixed>> $options Popup scheme options.
 * @return array<int, array<string, mixed>>
 */
function mtuc_sort_popup_scheme_options( array $options ): array {
	usort(
		$options,
		static function ( array $a, array $b ): int {
			$a_months = (int) ( $a['months'] ?? 0 );
			$b_months = (int) ( $b['months'] ?? 0 );

			if ( $a_months !== $b_months ) {
				return $a_months <=> $b_months;
			}

			$type_order = array(
				'standard' => 0,
				'promo'    => 1,
			);
			$a_type     = (string) ( $a['scheme_type'] ?? 'standard' );
			$b_type     = (string) ( $b['scheme_type'] ?? 'standard' );

			if ( ( $type_order[ $a_type ] ?? 99 ) !== ( $type_order[ $b_type ] ?? 99 ) ) {
				return ( $type_order[ $a_type ] ?? 99 ) <=> ( $type_order[ $b_type ] ?? 99 );
			}

			return (int) ( $a['filter_id'] ?? 0 ) <=> (int) ( $b['filter_id'] ?? 0 );
		}
	);

	return $options;
}

/**
 * Build one popup scheme option row.
 *
 * @param int    $months      Installment count.
 * @param int    $filter_id   Schema filter id (0 for default KOP).
 * @param string $kop_code    KOP code.
 * @param string $desc        Optional description label.
 * @param string $scheme_type standard|promo.
 * @return array{key:string,months:int,kop_code:string,desc:string,filter_id:int,scheme_type:string}
 */
function mtuc_build_popup_scheme_option_row(
	int $months,
	int $filter_id,
	string $kop_code,
	string $desc,
	string $scheme_type
): array {
	return array(
		'key'         => mtuc_build_popup_scheme_option_key( $months, $filter_id, $scheme_type ),
		'months'      => $months,
		'kop_code'    => $kop_code,
		'desc'        => $desc,
		'filter_id'   => $filter_id,
		'scheme_type' => $scheme_type,
	);
}

/**
 * Whether a popup scheme option is in the enabled list.
 *
 * @param array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int}> $enabled_options Popup scheme options.
 * @param int                                                                                $months          Installment count.
 * @param int                                                                                $filter_id       Schema filter id (0 for default KOP).
 * @param string                                                                             $scheme_type     standard|promo.
 * @return bool
 */
function mtuc_is_popup_scheme_option_enabled(
	array $enabled_options,
	int $months,
	int $filter_id = 0,
	string $scheme_type = 'standard'
): bool {
	$key = mtuc_build_popup_scheme_option_key( $months, $filter_id, $scheme_type );

	foreach ( $enabled_options as $option ) {
		if ( ! is_array( $option ) ) {
			continue;
		}

		if ( (string) ( $option['key'] ?? '' ) === $key ) {
			return true;
		}
	}

	return false;
}

/**
 * Default popup scheme option key for select.
 *
 * @param array<string, mixed>                                                               $shop            Shop `data` object from CP.
 * @param array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int}> $enabled_options Allowed scheme options for the offer.
 * @param array<string, mixed>|null                                                          $button_offer    Calculator button offer for this type.
 * @return string
 */
function mtuc_pick_default_popup_scheme_key( array $shop, array $enabled_options, ?array $button_offer = null ): string {
	if ( empty( $enabled_options ) ) {
		return '';
	}

	if ( is_array( $button_offer ) ) {
		$btn_months = (int) ( $button_offer['installment_count'] ?? 0 );
		$btn_kop    = isset( $button_offer['kop_code'] ) ? trim( (string) $button_offer['kop_code'] ) : '';

		if ( $btn_months > 0 && '' !== $btn_kop ) {
			foreach ( $enabled_options as $option ) {
				if ( ! is_array( $option ) ) {
					continue;
				}

				if ( $btn_months === (int) ( $option['months'] ?? 0 ) && $btn_kop === (string) ( $option['kop_code'] ?? '' ) ) {
					return (string) ( $option['key'] ?? mtuc_build_popup_scheme_option_key( $btn_months, (int) ( $option['filter_id'] ?? 0 ) ) );
				}
			}
		}
	}

	$preferred = (int) ( $shop['uni_shema_current'] ?? 0 );
	if ( $preferred > 0 ) {
		foreach ( $enabled_options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			if ( $preferred === (int) ( $option['months'] ?? 0 ) ) {
				return (string) ( $option['key'] ?? mtuc_build_popup_scheme_option_key( $preferred, (int) ( $option['filter_id'] ?? 0 ) ) );
			}
		}
	}

	$first = $enabled_options[0];

	return (string) ( $first['key'] ?? mtuc_build_popup_scheme_option_key( (int) ( $first['months'] ?? 0 ), (int) ( $first['filter_id'] ?? 0 ) ) );
}

/**
 * Whether a coeff row is a zero-interest (0%) scheme.
 *
 * @param array<string, mixed>|null $coeff_entry Coefficient row.
 * @return bool
 */
function mtuc_is_zero_interest_coeff_entry( ?array $coeff_entry ): bool {
	if ( null === $coeff_entry ) {
		return false;
	}

	$glp = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : -1.0;

	return abs( $glp ) <= 0.00001;
}

/**
 * Default scheme key for checkout: prefer longest 0% promo, else existing popup fallback.
 *
 * Prefill from product popup („Купи“) overrides this later via mtuc_apply_checkout_prefill_to_popup().
 *
 * @param array<string, mixed>                                                                              $shop            Shop `data` object from CP.
 * @param array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int,scheme_type?:string}> $enabled_options Unified checkout schemes.
 * @param array<string, mixed>|null                                                                         $button_offer    Optional button offer for fallback.
 * @return string
 */
function mtuc_pick_default_checkout_scheme_key( array $shop, array $enabled_options, ?array $button_offer = null ): string {
	if ( empty( $enabled_options ) ) {
		return '';
	}

	$coeff_list  = mtuc_get_shop_coeff_list( $shop );
	$best_key    = '';
	$best_months = -1;

	foreach ( $enabled_options as $option ) {
		if ( ! is_array( $option ) ) {
			continue;
		}

		if ( 'promo' !== (string) ( $option['scheme_type'] ?? '' ) ) {
			continue;
		}

		$months   = (int) ( $option['months'] ?? 0 );
		$kop_code = trim( (string) ( $option['kop_code'] ?? '' ) );
		if ( $months <= 0 || '' === $kop_code ) {
			continue;
		}

		$coeff_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $months );
		if ( ! mtuc_is_zero_interest_coeff_entry( $coeff_entry ) ) {
			continue;
		}

		if ( $months > $best_months ) {
			$best_months = $months;
			$best_key    = (string) ( $option['key'] ?? mtuc_build_popup_scheme_option_key( $months, (int) ( $option['filter_id'] ?? 0 ), 'promo' ) );
		}
	}

	if ( '' !== $best_key ) {
		return $best_key;
	}

	return mtuc_pick_default_popup_scheme_key( $shop, $enabled_options, $button_offer );
}

/**
 * Pick the button offer with the lowest monthly installment.
 *
 * @param array<int, array<string, mixed>> $offers Resolved button offers.
 * @return array<string, mixed>|null
 */
function mtuc_pick_lowest_monthly_button_offer( array $offers ): ?array {
	if ( empty( $offers ) ) {
		return null;
	}

	$best             = null;
	$best_installment = null;

	foreach ( $offers as $offer ) {
		$installment = (float) ( $offer['monthly_installment'] ?? 0 );
		if ( null === $best_installment || $installment < $best_installment ) {
			$best_installment = $installment;
			$best             = $offer;
		}
	}

	return $best;
}

/**
 * Pick button offer: prefer uni_shema_current when available, else highest month count.
 * When multiple offers tie on those criteria, pick the lowest monthly installment.
 *
 * @param array<int, array<string, mixed>> $candidates Resolved button offers.
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @return array<string, mixed>|null
 */
function mtuc_pick_preferred_button_offer( array $candidates, array $shop ): ?array {
	if ( empty( $candidates ) ) {
		return null;
	}

	$preferred = (int) ( $shop['uni_shema_current'] ?? 0 );

	if ( $preferred > 0 ) {
		$preferred_matches = array();

		foreach ( $candidates as $offer ) {
			if ( $preferred === (int) ( $offer['installment_count'] ?? 0 ) ) {
				$preferred_matches[] = $offer;
			}
		}

		if ( ! empty( $preferred_matches ) ) {
			return mtuc_pick_lowest_monthly_button_offer( $preferred_matches );
		}
	}

	$best_months = 0;

	foreach ( $candidates as $offer ) {
		$months = (int) ( $offer['installment_count'] ?? 0 );
		if ( $months > $best_months ) {
			$best_months = $months;
		}
	}

	$max_month_matches = array();

	foreach ( $candidates as $offer ) {
		if ( $best_months === (int) ( $offer['installment_count'] ?? 0 ) ) {
			$max_month_matches[] = $offer;
		}
	}

	return mtuc_pick_lowest_monthly_button_offer( $max_month_matches );
}

/**
 * Build a product/cart button financing offer from a coefficient row.
 *
 * `$price` is the financed principal base (full price, or price after locked parva).
 *
 * @param string               $type        standard|promo.
 * @param string               $kop_code    Resolved KOP code.
 * @param int                  $months      Installment count.
 * @param float                $price       Product price including tax (or principal after parva).
 * @param array<string, mixed> $coeff_entry Matching coeff_list row.
 * @param array<string, mixed> $shop        Shop `data` object from CP.
 * @return array<string, mixed>|null
 */
function mtuc_build_button_offer( string $type, string $kop_code, int $months, float $price, array $coeff_entry, array $shop ): ?array {
	$kimb = isset( $coeff_entry['coeff'] ) ? (float) $coeff_entry['coeff'] : 0.0;
	if ( $kimb <= 0 ) {
		return null;
	}

	$glp                 = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : 0.0;
	$monthly_installment = round( $price * $kimb, 2 );
	$gpr                 = mtuc_calculate_gpr( $months, $monthly_installment, $price );

	return array(
		'type'                => $type,
		'visible'             => true,
		'kop_code'            => $kop_code,
		'installment_count'   => $months,
		'monthly_installment' => $monthly_installment,
		'glp'                 => round( $glp, 2 ),
		'gpr'                 => round( $gpr, 2 ),
		'total_amount'        => round( $price, 2 ),
		'kimb'                => $kimb,
		'price_text'          => mtuc_format_installment_price_text( $months, $monthly_installment, $shop ),
	);
}

/**
 * Format button subtitle: "{months} x {amount primary} ({amount secondary})".
 *
 * @param int                  $months              Installment count.
 * @param float                $monthly_installment Monthly installment amount.
 * @param array<string, mixed> $shop                Shop `data` object from CP.
 * @return string
 */
function mtuc_format_installment_price_text( int $months, float $monthly_installment, array $shop ): string {
	$uni_eur = (int) ( $shop['uni_eur'] ?? 0 );
	$rate    = 1.95583;

	switch ( $uni_eur ) {
		case 1:
			$primary_amount   = $monthly_installment;
			$secondary_amount = round( $monthly_installment / $rate, 2 );
			$primary_sign     = __( 'лева', 'mtunicredit' );
			$secondary_sign   = __( 'евро', 'mtunicredit' );
			break;
		case 2:
			$primary_amount   = $monthly_installment;
			$secondary_amount = round( $monthly_installment * $rate, 2 );
			$primary_sign     = __( 'евро', 'mtunicredit' );
			$secondary_sign   = __( 'лева', 'mtunicredit' );
			break;
		case 3:
			return sprintf(
				/* translators: 1: installment count, 2: monthly amount */
				__( '%1$d x %2$s евро', 'mtunicredit' ),
				$months,
				number_format( $monthly_installment, 2, '.', '' )
			);
		case 0:
		default:
			return sprintf(
				/* translators: 1: installment count, 2: monthly amount */
				__( '%1$d x %2$s лв.', 'mtunicredit' ),
				$months,
				number_format( $monthly_installment, 2, '.', '' )
			);
	}

	return sprintf(
		/* translators: 1: installment count, 2: primary amount, 3: primary currency, 4: secondary amount, 5: secondary currency */
		__( '%1$d x %2$s %3$s (%4$s %5$s)', 'mtunicredit' ),
		$months,
		number_format( $primary_amount, 2, '.', '' ),
		$primary_sign,
		number_format( $secondary_amount, 2, '.', '' ),
		$secondary_sign
	);
}
