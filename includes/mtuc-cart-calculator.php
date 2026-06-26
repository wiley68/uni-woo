<?php
/**
 * Cart-page leasing calculator (multi-line KOP intersection).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cart total including tax (contents only, no shipping).
 *
 * @return float
 */
function mtuc_get_cart_contents_total_inc_tax(): float {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0.0;
	}

	return round( (float) WC()->cart->get_cart_contents_total() + (float) WC()->cart->get_cart_contents_tax(), 2 );
}

/**
 * Normalized cart line entries for scheme resolution and order creation.
 *
 * @return array<int, array{product:WC_Product,parent_id:int,variation_id:int,quantity:int,line_total:float,cart_key:string}>
 */
function mtuc_get_cart_line_entries(): array {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return array();
	}

	$entries = array();

	foreach ( WC()->cart->get_cart() as $cart_key => $cart_item ) {
		if ( ! is_array( $cart_item ) ) {
			continue;
		}

		$product = isset( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product
			? $cart_item['data']
			: null;

		if ( ! $product instanceof WC_Product || ! $product->exists() ) {
			continue;
		}

		if ( ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			continue;
		}

		$quantity   = max( 1, (int) ( $cart_item['quantity'] ?? 1 ) );
		$line_total = isset( $cart_item['line_total'] ) && isset( $cart_item['line_tax'] )
			? round( (float) $cart_item['line_total'] + (float) $cart_item['line_tax'], 2 )
			: round( (float) wc_get_price_including_tax( $product, array( 'qty' => $quantity ) ), 2 );

		$variation_id = $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;
		$parent_id    = $variation_id > 0 ? (int) $product->get_parent_id() : (int) $product->get_id();

		$entries[] = array(
			'product'      => $product,
			'parent_id'    => $parent_id,
			'variation_id' => $variation_id,
			'quantity'     => $quantity,
			'line_total'   => $line_total,
			'cart_key'     => (string) $cart_key,
		);
	}

	return $entries;
}

/**
 * Stable match key for cart scheme intersection (KOP + months + scheme type).
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
 * All popup scheme options for one cart line (uses cart total for filter price).
 *
 * @param array<string, mixed>             $shop       Shop data.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $cart_total Cart total including tax.
 * @param WC_Product                       $product    Line product.
 * @param string                           $offer_type standard|promo.
 * @return array<int, array<string, mixed>>
 */
function mtuc_get_cart_line_scheme_options(
	array $shop,
	array $coeff_list,
	float $cart_total,
	WC_Product $product,
	string $offer_type
): array {
	if ( 'promo' === $offer_type ) {
		$typekop = (int) ( $shop['uni_typekop'] ?? -1 );

		if ( 1 === $typekop ) {
			return mtuc_get_popup_promo_schema_options( $shop, $coeff_list, $cart_total, $product );
		}

		if ( 0 === $typekop ) {
			return mtuc_get_popup_promo_default_options( $shop, $coeff_list, $cart_total, $product );
		}

		return array();
	}

	$typekop = (int) ( $shop['uni_typekop'] ?? -1 );

	if ( 1 === $typekop ) {
		$options = mtuc_get_popup_standard_schema_options( $shop, $coeff_list, $cart_total, $product );

		return mtuc_sort_popup_scheme_options(
			array_merge(
				$options,
				mtuc_get_popup_promo_options_for_standard_popup( $shop, $coeff_list, $cart_total, $product )
			)
		);
	}

	if ( 0 === $typekop ) {
		return mtuc_get_popup_standard_default_options( $shop, $coeff_list, $cart_total, $product );
	}

	return array();
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

/**
 * Unified checkout scheme list (standard-button popup: common standard + extra promo).
 *
 * @param array<int, array<string, mixed>> $common_standard Common standard schemes.
 * @param array<int, array<string, mixed>> $common_promo    Common promo schemes.
 * @return array<int, array<string, mixed>>
 */
function mtuc_build_checkout_unified_scheme_options( array $common_standard, array $common_promo ): array {
	$schemes = $common_standard;
	$seen    = array();

	foreach ( $schemes as $option ) {
		if ( ! is_array( $option ) ) {
			continue;
		}

		$seen[ mtuc_build_cart_scheme_match_key( $option ) ] = true;
	}

	foreach ( $common_promo as $option ) {
		if ( ! is_array( $option ) ) {
			continue;
		}

		$key = mtuc_build_cart_scheme_match_key( $option );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		$schemes[]    = $option;
		$seen[ $key ] = true;
	}

	return mtuc_sort_popup_scheme_options( $schemes );
}

/**
 * Resolve unified checkout schemes from cart state.
 *
 * @param array<string, mixed> $cart_state Cart scheme state.
 * @return array<int, array<string, mixed>>
 */
function mtuc_resolve_checkout_scheme_common( array $cart_state ): array {
	return mtuc_build_checkout_unified_scheme_options(
		(array) ( $cart_state['common_standard'] ?? array() ),
		(array) ( $cart_state['common_promo'] ?? array() )
	);
}

/**
 * Resolve cart button offer from common scheme options.
 *
 * @param array<string, mixed>             $shop           Shop data.
 * @param array<int, array<string, mixed>> $coeff_list     Coefficient rows.
 * @param float                            $cart_total     Cart total including tax.
 * @param array<int, array<string, mixed>> $common_options Common scheme options for offer type.
 * @param string                           $offer_type     standard|promo.
 * @return array<string, mixed>|null
 */
function mtuc_build_cart_button_offer_from_options(
	array $shop,
	array $coeff_list,
	float $cart_total,
	array $common_options,
	string $offer_type
): ?array {
	if ( empty( $common_options ) ) {
		return null;
	}

	$candidates = array();

	foreach ( $common_options as $option ) {
		if ( ! is_array( $option ) ) {
			continue;
		}

		$kop_code = trim( (string) ( $option['kop_code'] ?? '' ) );
		$months   = (int) ( $option['months'] ?? 0 );
		if ( '' === $kop_code || $months <= 0 ) {
			continue;
		}

		$coeff_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $months );
		if ( null === $coeff_entry ) {
			continue;
		}

		if ( 'promo' === $offer_type ) {
			$glp = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : -1.0;
			if ( abs( $glp ) > 0.00001 ) {
				continue;
			}
		}

		$offer = mtuc_build_button_offer(
			$offer_type,
			$kop_code,
			$months,
			$cart_total,
			$coeff_entry,
			$shop
		);

		if ( null !== $offer ) {
			$candidates[] = $offer;
		}
	}

	return mtuc_pick_preferred_button_offer( $candidates, $shop );
}

/**
 * Whether any cart line has at least one scheme option for an offer type.
 *
 * @param array<string, mixed>                  $shop       Shop data.
 * @param array<int, array<string, mixed>>      $coeff_list Coefficient rows.
 * @param float                                 $cart_total Cart total.
 * @param array<int, array{product:WC_Product}> $lines Cart lines.
 * @param string                                $offer_type standard|promo.
 * @return bool
 */
function mtuc_cart_has_any_line_scheme_options(
	array $shop,
	array $coeff_list,
	float $cart_total,
	array $lines,
	string $offer_type
): bool {
	foreach ( $lines as $line ) {
		if ( ! isset( $line['product'] ) || ! $line['product'] instanceof WC_Product ) {
			continue;
		}

		$options = mtuc_get_cart_line_scheme_options( $shop, $coeff_list, $cart_total, $line['product'], $offer_type );
		if ( ! empty( $options ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Build cart calculator context from the current cart (no page guard).
 *
 * @return array<string, mixed>|null
 */
function mtuc_build_cart_calculator_context(): ?array {
	if ( ! Mtuc_Settings::is_enabled() ) {
		return null;
	}

	if ( '' === (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID ) ) {
		return null;
	}

	$lines = mtuc_get_cart_line_entries();
	if ( empty( $lines ) ) {
		return null;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return null;
	}

	if ( ! mtuc_is_yes_flag( $shop['uni_status'] ?? 0 ) ) {
		return null;
	}

	$cart_total = mtuc_get_cart_contents_total_inc_tax();
	if ( $cart_total <= 0 || ! mtuc_is_product_price_in_shop_range( $shop, $cart_total ) ) {
		return null;
	}

	$coeff_list = mtuc_get_shop_coeff_list( $shop );

	$standard_line_sets = array();
	$promo_line_sets    = array();

	foreach ( $lines as $line ) {
		$standard_line_sets[] = mtuc_get_cart_line_scheme_options( $shop, $coeff_list, $cart_total, $line['product'], 'standard' );
		$promo_line_sets[]    = mtuc_get_cart_line_scheme_options( $shop, $coeff_list, $cart_total, $line['product'], 'promo' );
	}

	$common_standard = mtuc_intersect_cart_scheme_options( $standard_line_sets );
	$common_promo    = mtuc_intersect_cart_scheme_options( $promo_line_sets );

	$has_any_standard = mtuc_cart_has_any_line_scheme_options( $shop, $coeff_list, $cart_total, $lines, 'standard' );
	$has_any_promo    = mtuc_cart_has_any_line_scheme_options( $shop, $coeff_list, $cart_total, $lines, 'promo' );

	if ( ! $has_any_standard && ! $has_any_promo ) {
		return null;
	}

	$standard_offer = mtuc_build_cart_button_offer_from_options( $shop, $coeff_list, $cart_total, $common_standard, 'standard' );
	$promo_offer    = mtuc_build_cart_button_offer_from_options( $shop, $coeff_list, $cart_total, $common_promo, 'promo' );

	$is_dark_button = mtuc_is_yes_flag( $shop['uni_type_button'] ?? 0 );
	$button_width   = isset( $shop['uni_button_width'] ) ? absint( $shop['uni_button_width'] ) : 0;
	$button_height  = isset( $shop['uni_button_height'] ) ? absint( $shop['uni_button_height'] ) : 0;

	if ( $button_width <= 0 ) {
		$button_width = 290;
	}
	if ( $button_height <= 0 ) {
		$button_height = 56;
	}

	$standard = null !== $standard_offer
		? array_merge(
			$standard_offer,
			array(
				'visible'    => true,
				'image_only' => false,
			)
		)
		: ( $has_any_standard
			? array(
				'type'       => 'standard',
				'visible'    => true,
				'image_only' => true,
			)
			: null );

	$promo = null !== $promo_offer
		? array_merge(
			$promo_offer,
			array(
				'visible'    => true,
				'image_only' => false,
			)
		)
		: ( $has_any_promo
			? array(
				'type'       => 'promo',
				'visible'    => true,
				'image_only' => true,
			)
			: null );

	// No common cart scheme: one image-only standard button is enough (same alert on both).
	if ( is_array( $standard ) && ! empty( $standard['image_only'] ) ) {
		$promo = null;
	}

	return array(
		'source'           => 'cart',
		'cart_total'       => $cart_total,
		'lines'            => $lines,
		'common_standard'  => $common_standard,
		'common_promo'     => $common_promo,
		'standard'         => $standard,
		'promo'            => $promo,
		'show_installment' => mtuc_is_yes_flag( $shop['uni_vnoska'] ?? 0 ),
		'buttons_in_row'   => 1 === (int) ( $shop['uni_button_row'] ?? 1 ),
		'button_width'     => $button_width,
		'button_height'    => $button_height,
		'is_dark_button'   => $is_dark_button,
		'logo_url'         => mtuc_get_uni_logo_url( $is_dark_button ),
		'gap'              => (int) Mtuc_Settings::get( Mtuc_Settings::OPTION_GAP ),
		'popup'            => mtuc_get_cart_popup_context(
			$shop,
			array(
				'standard'        => $standard_offer,
				'promo'           => $promo_offer,
				'common_standard' => $common_standard,
				'common_promo'    => $common_promo,
			),
			$cart_total
		),
	);
}

/**
 * Build AJAX payload for cart calculator refresh after cart changes.
 *
 * @return array<string, mixed>
 */
function mtuc_build_cart_calculator_refresh_payload(): array {
	$context = mtuc_build_cart_calculator_context();
	if ( null === $context ) {
		return array(
			'visible' => false,
		);
	}

	$popup    = isset( $context['popup'] ) && is_array( $context['popup'] ) ? $context['popup'] : array();
	$standard = isset( $context['standard'] ) && is_array( $context['standard'] ) ? $context['standard'] : null;
	$promo    = isset( $context['promo'] ) && is_array( $context['promo'] ) ? $context['promo'] : null;

	$standard_payload = null;
	$promo_payload    = null;

	if ( is_array( $standard ) && ! empty( $standard['visible'] ) ) {
		$standard_payload = array(
			'visible'    => true,
			'image_only' => ! empty( $standard['image_only'] ),
			'price_text' => ! empty( $standard['image_only'] ) ? '' : (string) ( $standard['price_text'] ?? '' ),
		);
	}

	if ( is_array( $promo ) && ! empty( $promo['visible'] ) ) {
		$promo_payload = array(
			'visible'    => true,
			'image_only' => ! empty( $promo['image_only'] ),
			'price_text' => ! empty( $promo['image_only'] ) ? '' : (string) ( $promo['price_text'] ?? '' ),
		);
	}

	return array(
		'visible'              => true,
		'cart_total'           => (float) ( $context['cart_total'] ?? 0 ),
		'show_installment'     => ! empty( $context['show_installment'] ),
		'standard'             => $standard_payload,
		'promo'                => $promo_payload,
		'enabledMonthsByOffer' => isset( $popup['enabled_months_by_offer'] ) && is_array( $popup['enabled_months_by_offer'] )
			? $popup['enabled_months_by_offer']
			: array(),
		'defaultSchemeByOffer' => isset( $popup['default_scheme_by_offer'] ) && is_array( $popup['default_scheme_by_offer'] )
			? $popup['default_scheme_by_offer']
			: array(),
	);
}

/**
 * Whether the current request should refresh the cart calculator fragment.
 *
 * @return bool
 */
function mtuc_should_refresh_cart_calculator_fragment(): bool {
	if ( function_exists( 'is_cart' ) && is_cart() ) {
		return true;
	}

	if ( ! wp_doing_ajax() ) {
		return false;
	}

	$referer = wp_get_referer();
	if ( ! is_string( $referer ) || '' === $referer || ! function_exists( 'wc_get_cart_url' ) ) {
		return false;
	}

	$cart_url = wc_get_cart_url();
	if ( '' === $cart_url ) {
		return false;
	}

	return 0 === strpos( $referer, $cart_url );
}

/**
 * Render cart calculator markup wrapped for WooCommerce fragment replacement.
 *
 * @return string
 */
function mtuc_get_cart_calculator_fragment_html(): string {
	$context = mtuc_build_cart_calculator_context();
	if ( null === $context ) {
		return '';
	}

	$template = MTUC_PLUGIN_DIR . '/templates/cart-calculator.php';
	if ( ! is_readable( $template ) ) {
		return '';
	}

	ob_start();
	echo '<div class="mtuc-cart-calculator-fragment">';
	include $template;
	echo '</div>';

	return (string) ob_get_clean();
}

/**
 * Keep cart calculator in sync when WooCommerce refreshes cart fragments.
 *
 * @param array<string, string> $fragments Cart fragments.
 * @return array<string, string>
 */
function mtuc_append_cart_calculator_fragment( array $fragments ): array {
	if ( ! mtuc_should_refresh_cart_calculator_fragment() ) {
		return $fragments;
	}

	$fragments['div.mtuc-cart-calculator-fragment'] = mtuc_get_cart_calculator_fragment_html();

	return $fragments;
}

/**
 * AJAX: refresh cart calculator buttons and popup scheme options.
 *
 * @return void
 */
function mtuc_ajax_cart_calculator_refresh(): void {
	check_ajax_referer( 'mtuc_popup', 'security' );

	if ( ! Mtuc_Settings::is_enabled() ) {
		wp_send_json_error(
			array( 'message' => __( 'Модулът не е активен.', 'mtunicredit' ) ),
			403
		);
	}

	wp_send_json_success( mtuc_build_cart_calculator_refresh_payload() );
}

/**
 * Build cart calculator context.
 *
 * @return array<string, mixed>|null
 */
function mtuc_get_cart_calculator_context(): ?array {
	if ( ! function_exists( 'is_cart' ) || ! is_cart() || is_admin() ) {
		return null;
	}

	static $context  = null;
	static $resolved = false;

	if ( $resolved ) {
		return $context;
	}

	$resolved = true;
	$context  = mtuc_build_cart_calculator_context();

	return $context;
}

/**
 * Popup context for cart page.
 *
 * @param array<string, mixed> $shop       Shop data.
 * @param array<string, mixed> $context    Cart calculator partial context.
 * @param float                $cart_total Cart total including tax.
 * @param string               $source     product|cart|checkout.
 * @return array<string, mixed>
 */
function mtuc_get_cart_popup_context( array $shop, array $context, float $cart_total, string $source = 'cart' ): array {
	$common_standard = isset( $context['common_standard'] ) && is_array( $context['common_standard'] )
		? $context['common_standard']
		: array();
	$common_promo    = isset( $context['common_promo'] ) && is_array( $context['common_promo'] )
		? $context['common_promo']
		: array();

	$enabled_by_offer = array(
		'standard' => $common_standard,
		'promo'    => $common_promo,
	);

	$default_by_offer = array(
		'standard' => mtuc_pick_default_popup_scheme_key( $shop, $common_standard, $context['standard'] ?? null ),
		'promo'    => mtuc_pick_default_popup_scheme_key( $shop, $common_promo, $context['promo'] ?? null ),
	);

	$reklama_url = '';
	if ( ! empty( $shop['reklama_url'] ) && is_string( $shop['reklama_url'] ) ) {
		$reklama_url = esc_url_raw( $shop['reklama_url'] );
	} elseif ( ! empty( $shop['uni_backurl'] ) && is_string( $shop['uni_backurl'] ) ) {
		$reklama_url = esc_url_raw( $shop['uni_backurl'] );
	}

	$popup = array(
		'source'                  => $source,
		'cart_total'              => $cart_total,
		'product_id'              => 0,
		'banner_url'              => mtuc_get_shop_picture_url( $shop, false ),
		'banner_url_mobile'       => mtuc_get_shop_picture_url( $shop, true ),
		'reklama_url'             => $reklama_url,
		'show_first_vnoska'       => mtuc_is_yes_flag( $shop['uni_first_vnoska'] ?? 0 ),
		'shop_months'             => mtuc_get_shop_enabled_months( $shop ),
		'enabled_months_by_offer' => $enabled_by_offer,
		'default_scheme_by_offer' => $default_by_offer,
		'currency'                => mtuc_get_currency_display_config( $shop ),
		'customer'                => mtuc_get_popup_customer_defaults(),
		'has_standard'            => ! empty( $common_standard ),
		'has_promo'               => ! empty( $common_promo ),
		'hide_add_to_cart'        => true,
	);

	if ( 'checkout' === $source ) {
		$unified_schemes = mtuc_build_checkout_unified_scheme_options( $common_standard, $common_promo );

		$popup['enabled_schemes']    = $unified_schemes;
		$popup['default_scheme_key'] = mtuc_pick_default_popup_scheme_key(
			$shop,
			$unified_schemes,
			$context['standard'] ?? null
		);
		$popup['has_schemes']        = ! empty( $unified_schemes );
	}

	return $popup;
}

/**
 * Recompute common cart schemes from the current cart (for AJAX).
 *
 * @return array{lines:array<int,array<string,mixed>>,cart_total:float,common_standard:array,common_promo:array,standard:array|null,promo:array|null,popup:array<string,mixed>}|WP_Error
 */
function mtuc_resolve_cart_scheme_state() {
	$lines = mtuc_get_cart_line_entries();
	if ( empty( $lines ) ) {
		return new WP_Error( 'mtuc_cart_empty', __( 'Количката е празна.', 'mtunicredit' ) );
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) ) {
		return $shop;
	}

	$cart_total = mtuc_get_cart_contents_total_inc_tax();
	if ( $cart_total <= 0 || ! mtuc_is_product_price_in_shop_range( $shop, $cart_total ) ) {
		return new WP_Error( 'mtuc_cart_price', __( 'Сумата на количката е извън допустимия диапазон.', 'mtunicredit' ) );
	}

	$coeff_list = mtuc_get_shop_coeff_list( $shop );

	$standard_line_sets = array();
	$promo_line_sets    = array();

	foreach ( $lines as $line ) {
		$standard_line_sets[] = mtuc_get_cart_line_scheme_options( $shop, $coeff_list, $cart_total, $line['product'], 'standard' );
		$promo_line_sets[]    = mtuc_get_cart_line_scheme_options( $shop, $coeff_list, $cart_total, $line['product'], 'promo' );
	}

	$common_standard = mtuc_intersect_cart_scheme_options( $standard_line_sets );
	$common_promo    = mtuc_intersect_cart_scheme_options( $promo_line_sets );

	$standard_offer = mtuc_build_cart_button_offer_from_options( $shop, $coeff_list, $cart_total, $common_standard, 'standard' );
	$promo_offer    = mtuc_build_cart_button_offer_from_options( $shop, $coeff_list, $cart_total, $common_promo, 'promo' );

	$popup = mtuc_get_cart_popup_context(
		$shop,
		array(
			'standard'        => $standard_offer,
			'promo'           => $promo_offer,
			'common_standard' => $common_standard,
			'common_promo'    => $common_promo,
		),
		$cart_total
	);

	return array(
		'lines'           => $lines,
		'cart_total'      => $cart_total,
		'common_standard' => $common_standard,
		'common_promo'    => $common_promo,
		'standard'        => $standard_offer,
		'promo'           => $promo_offer,
		'popup'           => $popup,
	);
}

/**
 * Calculate popup credit for cart (validates against common schemes).
 *
 * @param array<string, mixed>             $shop           Shop data.
 * @param array<int, array<string, mixed>> $coeff_list     Coefficient rows.
 * @param float                            $cart_total     Cart total.
 * @param int                              $months         Installment count.
 * @param string                           $popup_offer_type standard|promo.
 * @param float                            $parva          Initial payment.
 * @param int                              $filter_id      Schema filter id.
 * @param string                           $scheme_type    standard|promo.
 * @param array<int, array<string, mixed>> $common_options Allowed common schemes.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_calculate_cart_popup_credit(
	array $shop,
	array $coeff_list,
	float $cart_total,
	int $months,
	string $popup_offer_type,
	float $parva,
	int $filter_id,
	string $scheme_type,
	array $common_options
) {
	if ( ! mtuc_is_popup_scheme_option_enabled( $common_options, $months, $filter_id, $scheme_type ) ) {
		return new WP_Error( 'mtuc_cart_invalid_scheme', __( 'Избраната схема не е налична за цялата количка.', 'mtunicredit' ) );
	}

	$kop_code = '';
	foreach ( $common_options as $option ) {
		if ( ! is_array( $option ) ) {
			continue;
		}
		if ( (int) ( $option['months'] ?? 0 ) !== $months ) {
			continue;
		}
		if ( (string) ( $option['scheme_type'] ?? '' ) !== $scheme_type ) {
			continue;
		}
		if ( (int) ( $option['filter_id'] ?? 0 ) !== $filter_id ) {
			continue;
		}

		$kop_code = trim( (string) ( $option['kop_code'] ?? '' ) );
		break;
	}

	if ( '' === $kop_code ) {
		foreach ( $common_options as $option ) {
			if ( ! is_array( $option ) ) {
				continue;
			}
			if ( mtuc_build_popup_scheme_option_key( $months, $filter_id, $scheme_type ) !== (string) ( $option['key'] ?? '' ) ) {
				continue;
			}
			$kop_code = trim( (string) ( $option['kop_code'] ?? '' ) );
			break;
		}
	}

	if ( '' === $kop_code ) {
		return new WP_Error( 'mtuc_cart_no_kop', __( 'Няма налична схема за избраните параметри.', 'mtunicredit' ) );
	}

	$coeff_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $months );
	if ( null === $coeff_entry ) {
		return new WP_Error( 'mtuc_cart_invalid_coeff', __( 'Липсва валиден коефициент за изчисление.', 'mtunicredit' ) );
	}

	$scheme = array(
		'filter'      => null,
		'coeff_entry' => $coeff_entry,
		'kop_code'    => $kop_code,
		'kop_desc'    => '',
	);

	$kimb = isset( $coeff_entry['coeff'] ) ? (float) $coeff_entry['coeff'] : 0.0;
	if ( $kimb <= 0 ) {
		return new WP_Error( 'mtuc_cart_invalid_coeff', __( 'Липсва валиден коефициент за изчисление.', 'mtunicredit' ) );
	}

	$show_parva   = mtuc_is_yes_flag( $shop['uni_first_vnoska'] ?? 0 );
	$parva_locked = false;

	if ( ! $show_parva ) {
		$parva = 0.0;
	} else {
		$parva = max( 0.0, min( round( $parva, 2 ), $cart_total ) );
	}

	$loan_amount = round( $cart_total - $parva, 2 );
	if ( $loan_amount <= 0 ) {
		return new WP_Error( 'mtuc_cart_invalid_loan', __( 'Общата сума на заема трябва да е положителна.', 'mtunicredit' ) );
	}

	$monthly_installment = round( $loan_amount * $kimb, 2 );
	$total_payable       = round( $monthly_installment * $months, 2 );
	$glp                 = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : 0.0;
	$gpr                 = mtuc_calculate_gpr( $months, $monthly_installment, $loan_amount );
	$gpr                 = $gpr <= 0.1 ? 0.0 : round( $gpr, 2 );
	$glp                 = round( abs( $glp ), 2 );

	return array(
		'months'              => $months,
		'offer_type'          => $scheme_type,
		'popup_offer_type'    => $popup_offer_type,
		'filter_id'           => $filter_id,
		'scheme_key'          => mtuc_build_popup_scheme_option_key( $months, $filter_id, $scheme_type ),
		'scheme_type'         => $scheme_type,
		'kop_code'            => $kop_code,
		'price'               => round( $cart_total, 2 ),
		'parva'               => $parva,
		'parva_locked'        => $parva_locked,
		'show_parva'          => $show_parva,
		'loan_amount'         => $loan_amount,
		'monthly_installment' => $monthly_installment,
		'total_payable'       => $total_payable,
		'glp'                 => $glp,
		'gpr'                 => $gpr,
		'glp_display'         => mtuc_format_popup_percent_display( $glp ),
		'gpr_display'         => mtuc_format_popup_percent_display( $gpr ),
		'price_display'       => mtuc_format_popup_amount_display( $cart_total, $shop ),
		'parva_display'       => mtuc_format_popup_amount_display( $parva, $shop ),
		'loan_display'        => mtuc_format_popup_amount_display( $loan_amount, $shop ),
		'monthly_display'     => mtuc_format_popup_amount_display( $monthly_installment, $shop ),
		'total_display'       => mtuc_format_popup_amount_display( $total_payable, $shop ),
	);
}

/**
 * Register cart calculator frontend hooks.
 *
 * @return void
 */
function mtuc_register_cart_hooks(): void {
	add_action( 'wp_ajax_mtuc_cart_calculator_refresh', 'mtuc_ajax_cart_calculator_refresh' );
	add_action( 'wp_ajax_nopriv_mtuc_cart_calculator_refresh', 'mtuc_ajax_cart_calculator_refresh' );

	if ( is_admin() ) {
		return;
	}

	add_action( 'woocommerce_proceed_to_checkout', 'mtuc_render_cart_calculator', 5 );
	add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_cart_assets' );
	add_filter( 'woocommerce_add_to_cart_fragments', 'mtuc_append_cart_calculator_fragment' );
}

/**
 * Enqueue cart calculator assets.
 *
 * @return void
 */
function mtuc_enqueue_cart_assets(): void {
	$context = mtuc_get_cart_calculator_context();
	if ( null === $context ) {
		return;
	}

	$css_file      = MTUC_PLUGIN_DIR . '/css/mtuc-product.css';
	$popup_css     = MTUC_PLUGIN_DIR . '/css/mtuc-popup.css';
	$cart_js       = MTUC_PLUGIN_DIR . '/js/mtuc-cart-calculator.js';
	$popup_js      = MTUC_PLUGIN_DIR . '/js/mtuc-product-popup.js';
	$popup_context = isset( $context['popup'] ) && is_array( $context['popup'] ) ? $context['popup'] : array();

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
		'mtuc-cart-calculator',
		MTUC_JS_URI . '/mtuc-cart-calculator.js',
		array( 'jquery' ),
		file_exists( $cart_js ) ? (string) filemtime( $cart_js ) : MTUC_VERSION,
		true
	);

	wp_enqueue_script(
		'mtuc-product-popup',
		MTUC_JS_URI . '/mtuc-product-popup.js',
		array( 'jquery', 'mtuc-cart-calculator' ),
		file_exists( $popup_js ) ? (string) filemtime( $popup_js ) : MTUC_VERSION,
		true
	);

	wp_localize_script(
		'mtuc-cart-calculator',
		'mtucCartCalculator',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'mtuc_popup' ),
			'cartTotal' => (float) ( $context['cart_total'] ?? 0 ),
			'i18n'      => array(
				'buyLabel' => __( 'Купи на изплащане', 'mtunicredit' ),
			),
		)
	);

	wp_localize_script(
		'mtuc-product-popup',
		'mtucPopup',
		array(
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'mtuc_popup' ),
			'source'               => 'cart',
			'productId'            => 0,
			'cartTotal'            => (float) ( $context['cart_total'] ?? 0 ),
			'hideAddToCart'        => true,
			'enabledMonthsByOffer' => isset( $popup_context['enabled_months_by_offer'] ) && is_array( $popup_context['enabled_months_by_offer'] )
				? $popup_context['enabled_months_by_offer']
				: array(),
			'defaultSchemeByOffer' => isset( $popup_context['default_scheme_by_offer'] ) && is_array( $popup_context['default_scheme_by_offer'] )
				? $popup_context['default_scheme_by_offer']
				: array(),
			'currencyDual'         => ! empty( $popup_context['currency']['dual'] ),
			'customer'             => isset( $popup_context['customer'] ) && is_array( $popup_context['customer'] )
				? $popup_context['customer']
				: mtuc_get_popup_customer_defaults(),
			'i18n'                 => array(
				'calcError'         => __( 'Неуспешно изчисление. Моля, опитайте отново.', 'mtunicredit' ),
				'submitPending'     => __( 'Изпращането на заявката ще бъде добавено на следващ етап.', 'mtunicredit' ),
				'monthsLabel'       => __( '%d месеца', 'mtunicredit' ),
				'noMonths'          => __( 'Няма налични срокове за тази количка.', 'mtunicredit' ),
				'fieldRequired'     => __( 'Полето е задължително.', 'mtunicredit' ),
				'phoneInvalid'      => __( 'Въведете валиден телефонен номер.', 'mtunicredit' ),
				'emailInvalid'      => __( 'Въведете валиден e-mail адрес.', 'mtunicredit' ),
				'submitError'       => __( 'Заявката не може да бъде изпратена. Моля, опитайте отново.', 'mtunicredit' ),
				'submitNoCalc'      => __( 'Липсват данни за изчисление. Моля, върнете се и изберете схема отново.', 'mtunicredit' ),
				'submitting'        => __( 'Изпращане...', 'mtunicredit' ),
				'processing'        => __( 'Обработване на заявката. Моля, изчакайте...', 'mtunicredit' ),
				'cartSplitRequired' => __( 'Не може да закупите цялата количка на изплащане. Моля, разделете поръчката си ако желаете да я закупите на изплащане.', 'mtunicredit' ),
			),
		)
	);
}

/**
 * Render cart calculator above proceed-to-checkout button.
 *
 * @return void
 */
function mtuc_render_cart_calculator(): void {
	$html = mtuc_get_cart_calculator_fragment_html();
	if ( '' === $html ) {
		return;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in template.
	echo $html;
}
