<?php
/**
 * Pure cart scheme intersection helpers (AUD-WOO-016 Step 3).
 *
 * Given normalized financing scheme options per cart line, computes the common
 * set (scheme type + KOP + months). Does not fetch CP data, load Woo cart,
 * or perform remote/order side effects.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stable match key for cart scheme intersection (KOP + months + scheme type).
 *
 * Filter ID is intentionally excluded from identity.
 *
 * @param array<string, mixed> $option Popup scheme option row.
 * @return string
 */
function mtuc_build_cart_scheme_match_key( array $option ): string {
	$scheme_type = (string) ( $option['scheme_type'] ?? 'standard' );
	$kop_code    = isset( $option['kop_code'] ) ? trim( (string) $option['kop_code'] ) : '';
	$months      = (int) ( $option['months'] ?? 0 );

	return $scheme_type . '|' . $kop_code . '|' . $months;
}

/**
 * Greatest common divisor (for LCM helper).
 *
 * @param int $a Positive integer.
 * @param int $b Positive integer.
 * @return int
 */
function mtuc_gcd_int( int $a, int $b ): int {
	$a = abs( $a );
	$b = abs( $b );

	while ( 0 !== $b ) {
		$temp = $b;
		$b    = $a % $b;
		$a    = $temp;
	}

	return max( 1, $a );
}

/**
 * Least common multiple of positive integers.
 *
 * @param array<int, int> $values Month counts or other positive ints.
 * @return int
 */
function mtuc_lcm_int_list( array $values ): int {
	$values = array_values(
		array_filter(
			array_map( 'absint', $values ),
			static function ( int $value ): bool {
				return $value > 0;
			}
		)
	);

	if ( empty( $values ) ) {
		return 0;
	}

	$result = $values[0];
	$count  = count( $values );

	for ( $i = 1; $i < $count; $i++ ) {
		$gcd    = mtuc_gcd_int( $result, $values[ $i ] );
		$result = (int) ( ( $result / $gcd ) * $values[ $i ] );
	}

	return $result;
}

/**
 * Intersect scheme options across all cart lines (common KOP + months).
 *
 * When multiple month sets exist per KOP across lines, also keeps LCM month if it is
 * valid for every line on that KOP.
 *
 * @param array<int, array<int, array<string, mixed>>> $line_option_sets Options per cart line.
 * @return array<int, array<string, mixed>>
 */
function mtuc_intersect_cart_scheme_options( array $line_option_sets ): array {
	if ( empty( $line_option_sets ) ) {
		return array();
	}

	$line_option_sets = array_values( $line_option_sets );

	$common = $line_option_sets[0];
	foreach ( $line_option_sets as $line_set ) {
		$line_keys = array();
		foreach ( $line_set as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$line_keys[ mtuc_build_cart_scheme_match_key( $option ) ] = $option;
		}

		$filtered = array();
		foreach ( $common as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			$key = mtuc_build_cart_scheme_match_key( $option );
			if ( isset( $line_keys[ $key ] ) ) {
				$filtered[] = $option;
			}
		}

		$common = $filtered;
		if ( empty( $common ) ) {
			return array();
		}
	}

	// LCM expansion: for each shared KOP code, if LCM of line-specific months is valid on all lines, include it.
	$kop_groups = array();
	foreach ( $line_option_sets as $line_set ) {
		foreach ( $line_set as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}

			$kop = trim( (string) ( $option['kop_code'] ?? '' ) );
			if ( '' === $kop ) {
				continue;
			}

			$scheme_type = (string) ( $option['scheme_type'] ?? 'standard' );
			$group_key   = $scheme_type . '|' . $kop;

			if ( ! isset( $kop_groups[ $group_key ] ) ) {
				$kop_groups[ $group_key ] = array(
					'scheme_type' => $scheme_type,
					'kop_code'    => $kop,
					'line_months' => array(),
				);
			}

			$months = (int) ( $option['months'] ?? 0 );
			if ( $months > 0 ) {
				$kop_groups[ $group_key ]['line_months'][] = $months;
			}
		}
	}

	$existing_keys = array();
	foreach ( $common as $option ) {
		$existing_keys[ mtuc_build_cart_scheme_match_key( $option ) ] = true;
	}

	foreach ( $kop_groups as $group ) {
		$line_month_sets = array();
		foreach ( $line_option_sets as $line_set ) {
			$months_for_kop = array();
			foreach ( $line_set as $option ) {
				if ( ! is_array( $option ) ) {
					continue;
				}
				if ( (string) ( $option['scheme_type'] ?? '' ) !== $group['scheme_type'] ) {
					continue;
				}
				if ( trim( (string) ( $option['kop_code'] ?? '' ) ) !== $group['kop_code'] ) {
					continue;
				}
				$months_for_kop[] = (int) ( $option['months'] ?? 0 );
			}

			if ( empty( $months_for_kop ) ) {
				continue 2;
			}

			$line_month_sets[] = $months_for_kop;
		}

		if ( count( $line_month_sets ) !== count( $line_option_sets ) ) {
			continue;
		}

		$lcm_months = array();
		foreach ( $line_month_sets as $months_for_line ) {
			$lcm_months[] = mtuc_lcm_int_list( $months_for_line );
		}

		$target_month = mtuc_lcm_int_list( $lcm_months );
		if ( $target_month <= 0 ) {
			continue;
		}

		$match_key = $group['scheme_type'] . '|' . $group['kop_code'] . '|' . $target_month;
		if ( isset( $existing_keys[ $match_key ] ) ) {
			continue;
		}

		$valid_on_all_lines = true;
		$template_option    = null;

		foreach ( $line_option_sets as $line_set ) {
			$line_has_month = false;
			foreach ( $line_set as $option ) {
				if ( ! is_array( $option ) ) {
					continue;
				}
				if ( (string) ( $option['scheme_type'] ?? '' ) !== $group['scheme_type'] ) {
					continue;
				}
				if ( trim( (string) ( $option['kop_code'] ?? '' ) ) !== $group['kop_code'] ) {
					continue;
				}
				if ( (int) ( $option['months'] ?? 0 ) !== $target_month ) {
					continue;
				}

				$line_has_month  = true;
				$template_option = $option;
				break;
			}

			if ( ! $line_has_month ) {
				$valid_on_all_lines = false;
				break;
			}
		}

		if ( ! $valid_on_all_lines || ! is_array( $template_option ) ) {
			continue;
		}

		$new_option = mtuc_build_popup_scheme_option_row(
			$target_month,
			(int) ( $template_option['filter_id'] ?? 0 ),
			(string) ( $template_option['kop_code'] ?? '' ),
			(string) ( $template_option['desc'] ?? '' ),
			(string) ( $template_option['scheme_type'] ?? 'standard' )
		);

		$common[] = $new_option;
		$existing_keys[ mtuc_build_cart_scheme_match_key( $new_option ) ] = true;
	}

	return mtuc_sort_popup_scheme_options( $common );
}
