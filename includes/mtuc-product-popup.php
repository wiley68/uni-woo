<?php
/**
 * Product-page credit popup (two-step flow).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register popup AJAX handlers (must run on admin-ajax requests too).
 *
 * @return void
 */
function mtuc_register_product_popup_ajax_hooks(): void {
	add_action( 'wp_ajax_mtuc_popup_calculate', 'mtuc_ajax_popup_calculate' );
	add_action( 'wp_ajax_nopriv_mtuc_popup_calculate', 'mtuc_ajax_popup_calculate' );
	add_action( 'wp_ajax_mtuc_product_calculator_refresh', 'mtuc_ajax_product_calculator_refresh' );
	add_action( 'wp_ajax_nopriv_mtuc_product_calculator_refresh', 'mtuc_ajax_product_calculator_refresh' );
}

/**
 * Register popup frontend hooks (footer markup).
 *
 * @return void
 */
function mtuc_register_product_popup_hooks(): void {
	add_action( 'wp_footer', 'mtuc_render_credit_popup', 5 );
}

/**
 * Build a stable popup scheme option key (months + schema filter id + scheme type).
 *
 * @param int    $months       Installment count.
 * @param int    $filter_id    Schema filter id (0 for default KOP).
 * @param string $scheme_type  standard|promo.
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
 * All schema popup options for matching filters (one row per filter/month).
 *
 * @param array<string, mixed>             $shop                  Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list            Coefficient rows.
 * @param float                            $price                 Product price including tax.
 * @param int                              $uni_promo_filter      Filter rows where uni_promo equals this value.
 * @param bool                             $require_zero_interest Require interestPercent == 0.
 * @param WC_Product|null                  $product               Product instance.
 * @return array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int,scheme_type:string}>
 */
function mtuc_get_popup_schema_options_for_promo_flag(
	array $shop,
	array $coeff_list,
	float $price,
	int $uni_promo_filter,
	bool $require_zero_interest,
	?WC_Product $product = null
): array {
	if ( null === $product ) {
		$product = mtuc_get_current_wc_product();
	}

	if ( ! $product instanceof WC_Product ) {
		return array();
	}

	$by_schema = $shop['kop']['by_schema'] ?? null;
	if ( ! is_array( $by_schema ) ) {
		return array();
	}

	$filters = $by_schema['filters'] ?? null;
	if ( ! is_array( $filters ) ) {
		return array();
	}

	$scheme_type  = 1 === $uni_promo_filter ? 'promo' : 'standard';
	$product_id   = $product->get_id();
	$category_ids = mtuc_get_product_category_ids( $product );
	$options      = array();

	foreach ( $filters as $filter ) {
		if ( ! is_array( $filter ) ) {
			continue;
		}

		if ( $uni_promo_filter !== (int) ( $filter['uni_promo'] ?? 0 ) ) {
			continue;
		}

		if ( ! mtuc_schema_filter_matches_product( $filter, $product_id, $category_ids, $price ) ) {
			continue;
		}

		$kop_code = isset( $filter['uni_kop'] ) ? trim( (string) $filter['uni_kop'] ) : '';
		if ( '' === $kop_code ) {
			continue;
		}

		$filter_id      = (int) ( $filter['id'] ?? 0 );
		$allowed_months = mtuc_get_schema_filter_allowed_months( $filter, $shop );

		foreach ( $allowed_months as $months ) {
			$coeff_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $months );
			if ( null === $coeff_entry ) {
				continue;
			}

			if ( $require_zero_interest ) {
				$glp = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : -1.0;
				if ( abs( $glp ) > 0.00001 ) {
					continue;
				}
			}

			$options[] = mtuc_build_popup_scheme_option_row(
				$months,
				$filter_id,
				$kop_code,
				isset( $filter['uni_kop_desc'] ) ? trim( (string) $filter['uni_kop_desc'] ) : '',
				$scheme_type
			);
		}
	}

	return mtuc_sort_popup_scheme_options( $options );
}

/**
 * All standard schema popup options for matching filters (one row per filter/month).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $price      Product price including tax.
 * @param WC_Product|null                  $product    Product instance.
 * @return array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int,scheme_type:string}>
 */
function mtuc_get_popup_standard_schema_options(
	array $shop,
	array $coeff_list,
	float $price,
	?WC_Product $product = null
): array {
	return mtuc_get_popup_schema_options_for_promo_flag( $shop, $coeff_list, $price, 0, false, $product );
}

/**
 * All promo schema popup options for matching filters (one row per filter/month).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $price      Product price including tax.
 * @param WC_Product|null                  $product    Product instance.
 * @return array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int,scheme_type:string}>
 */
function mtuc_get_popup_promo_schema_options(
	array $shop,
	array $coeff_list,
	float $price,
	?WC_Product $product = null
): array {
	return mtuc_get_popup_schema_options_for_promo_flag( $shop, $coeff_list, $price, 1, true, $product );
}

/**
 * Promo 0% popup options for default KOP settings (uni_typekop = 0).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $price      Product price including tax.
 * @param WC_Product|null                  $product    Product instance.
 * @return array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int,scheme_type:string}>
 */
function mtuc_get_popup_promo_default_options(
	array $shop,
	array $coeff_list,
	float $price,
	?WC_Product $product = null
): array {
	$by_default = $shop['kop']['by_default'] ?? null;
	if ( ! is_array( $by_default ) ) {
		return array();
	}

	$kop_code = isset( $by_default['uni_kop_promo'] ) ? trim( (string) $by_default['uni_kop_promo'] ) : '';
	if ( '' === $kop_code ) {
		return array();
	}

	$kop_desc = isset( $by_default['uni_kop_promo_desc'] ) ? trim( (string) $by_default['uni_kop_promo_desc'] ) : '';
	$options  = array();

	foreach ( mtuc_get_shop_enabled_months( $shop ) as $months ) {
		if ( ! mtuc_is_default_promo_month_allowed( $by_default, $months, $price ) ) {
			continue;
		}

		$coeff_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $months );
		if ( null === $coeff_entry ) {
			continue;
		}

		$glp = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : -1.0;
		if ( abs( $glp ) > 0.00001 ) {
			continue;
		}

		$options[] = mtuc_build_popup_scheme_option_row( $months, 0, $kop_code, $kop_desc, 'promo' );
	}

	return mtuc_sort_popup_scheme_options( $options );
}

/**
 * Promo 0% options eligible for the standard popup select.
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $price      Product price including tax.
 * @param WC_Product|null                  $product    Product instance.
 * @return array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int,scheme_type:string}>
 */
function mtuc_get_popup_promo_options_for_standard_popup(
	array $shop,
	array $coeff_list,
	float $price,
	?WC_Product $product = null
): array {
	$typekop = (int) ( $shop['uni_typekop'] ?? -1 );

	if ( 1 === $typekop ) {
		return mtuc_get_popup_promo_schema_options( $shop, $coeff_list, $price, $product );
	}

	if ( 0 === $typekop ) {
		return mtuc_get_popup_promo_default_options( $shop, $coeff_list, $price, $product );
	}

	return array();
}

/**
 * Standard popup options for default KOP settings (uni_typekop = 0).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $price      Product price including tax.
 * @param WC_Product|null                  $product    Product instance.
 * @return array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int,scheme_type:string}>
 */
function mtuc_get_popup_standard_default_options(
	array $shop,
	array $coeff_list,
	float $price,
	?WC_Product $product = null
): array {
	$options = array();

	foreach ( mtuc_get_shop_enabled_months( $shop ) as $months ) {
		$scheme = mtuc_resolve_popup_scheme( $shop, $coeff_list, $price, $months, 'standard', $product );
		if ( null === $scheme ) {
			continue;
		}

		$options[] = mtuc_build_popup_scheme_option_row(
			$months,
			0,
			(string) ( $scheme['kop_code'] ?? '' ),
			(string) ( $scheme['kop_desc'] ?? '' ),
			'standard'
		);
	}

	return mtuc_sort_popup_scheme_options( $options );
}

/**
 * Popup month choices: shop uni_meseci_* intersect valid KOP/coeff matches.
 *
 * Standard popup includes all standard schemes plus eligible promo 0% schemes.
 * Promo popup keeps one best option per month.
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $price      Product price including tax.
 * @param string                           $offer_type standard|promo.
 * @param WC_Product|null                  $product    Product instance.
 * @return array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int,scheme_type:string}>
 */
function mtuc_get_popup_enabled_months(
	array $shop,
	array $coeff_list,
	float $price,
	string $offer_type,
	?WC_Product $product = null
): array {
	$typekop = (int) ( $shop['uni_typekop'] ?? -1 );

	if ( 'standard' === $offer_type ) {
		if ( 1 === $typekop ) {
			$options = mtuc_get_popup_standard_schema_options( $shop, $coeff_list, $price, $product );
		} elseif ( 0 === $typekop ) {
			$options = mtuc_get_popup_standard_default_options( $shop, $coeff_list, $price, $product );
		} else {
			$options = array();
		}

		return mtuc_sort_popup_scheme_options(
			array_merge(
				$options,
				mtuc_get_popup_promo_options_for_standard_popup( $shop, $coeff_list, $price, $product )
			)
		);
	}

	$shop_months = mtuc_get_shop_enabled_months( $shop );
	$enabled     = array();

	foreach ( $shop_months as $months ) {
		$scheme = mtuc_resolve_popup_scheme( $shop, $coeff_list, $price, $months, 'promo', $product );
		if ( null === $scheme ) {
			continue;
		}

		$filter_id = 0;
		if ( isset( $scheme['filter'] ) && is_array( $scheme['filter'] ) ) {
			$filter_id = (int) ( $scheme['filter']['id'] ?? 0 );
		}

		$enabled[] = mtuc_build_popup_scheme_option_row(
			$months,
			$filter_id,
			(string) ( $scheme['kop_code'] ?? '' ),
			(string) ( $scheme['kop_desc'] ?? '' ),
			'promo'
		);
	}

	return mtuc_sort_popup_scheme_options( $enabled );
}

/**
 * Whether a popup scheme option is in the enabled list.
 *
 * @param array<int, array{key:string,months:int,kop_code:string,desc:string,filter_id:int}> $enabled_options Popup scheme options.
 * @param int                                                                                $months          Installment count.
 * @param int                                                                                $filter_id       Schema filter id (0 for default KOP).
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
 * Currency labels for popup display.
 *
 * @param array<string, mixed> $shop Shop `data` object from CP.
 * @return array{mode:int,primary_sign:string,secondary_sign:string,dual:bool}
 */
function mtuc_get_currency_display_config( array $shop ): array {
	$mode = (int) ( $shop['uni_eur'] ?? 0 );

	switch ( $mode ) {
		case 1:
			return array(
				'mode'           => 1,
				'primary_sign'   => __( 'лв.', 'mtunicredit' ),
				'secondary_sign' => __( 'евро', 'mtunicredit' ),
				'dual'           => true,
			);
		case 2:
			return array(
				'mode'           => 2,
				'primary_sign'   => __( 'евро', 'mtunicredit' ),
				'secondary_sign' => __( 'лв.', 'mtunicredit' ),
				'dual'           => true,
			);
		case 3:
			return array(
				'mode'           => 3,
				'primary_sign'   => __( 'евро', 'mtunicredit' ),
				'secondary_sign' => '',
				'dual'           => false,
			);
		case 0:
		default:
			return array(
				'mode'           => 0,
				'primary_sign'   => __( 'лв.', 'mtunicredit' ),
				'secondary_sign' => '',
				'dual'           => false,
			);
	}
}

/**
 * Format percent value for popup rows (always positive, 2 decimals).
 *
 * @param float $value Percent value.
 * @return string
 */
function mtuc_format_popup_percent_display( float $value ): string {
	return number_format( abs( round( $value, 2 ) ), 2, '.', '' );
}

/**
 * Format amount for popup rows (primary + optional secondary currency).
 *
 * @param float                $amount Amount in calculator currency.
 * @param array<string, mixed> $shop   Shop `data` object from CP.
 * @return array{primary:string,secondary:string,dual:bool}
 */
function mtuc_format_popup_amount_display( float $amount, array $shop ): array {
	$config = mtuc_get_currency_display_config( $shop );
	$rate   = 1.95583;
	$amount = round( abs( $amount ), 2 );

	$primary = number_format( $amount, 2, '.', '' ) . ' ' . $config['primary_sign'];

	if ( ! $config['dual'] ) {
		return array(
			'primary'   => $primary,
			'secondary' => '',
			'dual'      => false,
		);
	}

	if ( 1 === $config['mode'] ) {
		$secondary_amount = round( $amount / $rate, 2 );
	} else {
		$secondary_amount = round( $amount * $rate, 2 );
	}

	return array(
		'primary'   => $primary,
		'secondary' => number_format( $secondary_amount, 2, '.', '' ) . ' ' . $config['secondary_sign'],
		'dual'      => true,
	);
}

/**
 * Join non-empty address parts with comma separator (max 256 chars).
 *
 * @param string[] $parts Address fragments.
 * @return string
 */
function mtuc_join_address_parts( array $parts ): string {
	$parts = array_values(
		array_filter(
			array_map(
				static function ( $part ) {
					return trim( (string) $part );
				},
				$parts
			),
			static function ( $part ) {
				return '' !== $part;
			}
		)
	);

	$formatted = implode( ', ', $parts );
	if ( strlen( $formatted ) > 256 ) {
		$formatted = substr( $formatted, 0, 256 );
	}

	return $formatted;
}

/**
 * Billing address default for popup step 2 (address, city, postal code).
 *
 * @param int $user_id WordPress user ID (0 = current user).
 * @return string
 */
function mtuc_get_popup_billing_address_default( int $user_id = 0 ): string {
	if ( $user_id <= 0 ) {
		$user_id = get_current_user_id();
	}

	if ( $user_id <= 0 ) {
		return '';
	}

	if ( function_exists( 'wc_get_customer' ) ) {
		$customer = wc_get_customer( $user_id );
		if ( $customer instanceof WC_Customer ) {
			$formatted = mtuc_join_address_parts(
				array(
					(string) $customer->get_billing_address_1(),
					(string) $customer->get_billing_city(),
					(string) $customer->get_billing_postcode(),
				)
			);
			if ( '' !== $formatted ) {
				return $formatted;
			}
		}
	}

	return mtuc_join_address_parts(
		array(
			(string) get_user_meta( $user_id, 'billing_address_1', true ),
			(string) get_user_meta( $user_id, 'billing_city', true ),
			(string) get_user_meta( $user_id, 'billing_postcode', true ),
		)
	);
}

/**
 * Shipping address formatted for CP address2 (logged-in customers).
 *
 * @param int $user_id WordPress user ID (0 = current user).
 * @return string Empty when no shipping data is available.
 */
function mtuc_get_popup_shipping_address_for_cp( int $user_id = 0 ): string {
	if ( $user_id <= 0 ) {
		$user_id = get_current_user_id();
	}

	if ( $user_id <= 0 || ! function_exists( 'wc_get_customer' ) ) {
		return '';
	}

	$customer = wc_get_customer( $user_id );
	if ( ! $customer instanceof WC_Customer ) {
		return '';
	}

	return mtuc_join_address_parts(
		array(
			(string) $customer->get_shipping_address_1(),
			(string) $customer->get_shipping_city(),
			(string) $customer->get_shipping_postcode(),
		)
	);
}

/**
 * Default customer fields for popup step 2.
 *
 * @return array<string, string>
 */
function mtuc_get_popup_customer_defaults(): array {
	$defaults = array(
		'first_name' => '',
		'last_name'  => '',
		'address'    => '',
		'phone'      => '',
		'email'      => '',
	);

	if ( ! is_user_logged_in() ) {
		return $defaults;
	}

	$user = wp_get_current_user();
	if ( ! $user instanceof WP_User ) {
		return $defaults;
	}

	$defaults['first_name'] = (string) $user->first_name;
	$defaults['last_name']  = (string) $user->last_name;
	$defaults['email']      = (string) $user->user_email;

	if ( function_exists( 'wc_get_customer' ) ) {
		$customer = wc_get_customer( get_current_user_id() );
		if ( $customer instanceof WC_Customer ) {
			if ( '' === $defaults['first_name'] ) {
				$defaults['first_name'] = (string) $customer->get_billing_first_name();
			}
			if ( '' === $defaults['last_name'] ) {
				$defaults['last_name'] = (string) $customer->get_billing_last_name();
			}
			if ( '' === $defaults['email'] ) {
				$defaults['email'] = (string) $customer->get_billing_email();
			}

			$defaults['phone']   = (string) $customer->get_billing_phone();
			$defaults['address'] = mtuc_get_popup_billing_address_default( get_current_user_id() );
		}
	}

	$user_id = get_current_user_id();
	if ( $user_id > 0 ) {
		if ( '' === $defaults['phone'] ) {
			$defaults['phone'] = (string) get_user_meta( $user_id, 'billing_phone', true );
		}
		if ( '' === $defaults['phone'] ) {
			$defaults['phone'] = (string) get_user_meta( $user_id, 'shipping_phone', true );
		}

		if ( '' === $defaults['address'] ) {
			$defaults['address'] = mtuc_get_popup_billing_address_default( $user_id );
		}
	}

	return $defaults;
}

/**
 * Build popup-specific context for template and JS.
 *
 * @param array<string, mixed> $shop    Shop `data` object from CP.
 * @param array<string, mixed> $context Product calculator context.
 * @return array<string, mixed>
 */
function mtuc_get_product_popup_context( array $shop, array $context, ?WC_Product $product = null, ?float $price = null ): array {
	if ( null === $product ) {
		$product = mtuc_get_current_wc_product();
	}

	if ( null === $price && $product instanceof WC_Product ) {
		$price = mtuc_get_product_price( $product );
	}
	$coeff_list  = mtuc_get_shop_coeff_list( $shop );
	$shop_months = mtuc_get_shop_enabled_months( $shop );

	$enabled_by_offer = array(
		'standard' => array(),
		'promo'    => array(),
	);

	if ( $product instanceof WC_Product && null !== $price && $price > 0 ) {
		if ( ! empty( $context['standard']['visible'] ) ) {
			$enabled_by_offer['standard'] = mtuc_get_popup_enabled_months(
				$shop,
				$coeff_list,
				$price,
				'standard',
				$product
			);
		}

		if ( ! empty( $context['promo']['visible'] ) ) {
			$enabled_by_offer['promo'] = mtuc_get_popup_enabled_months(
				$shop,
				$coeff_list,
				$price,
				'promo',
				$product
			);
		}
	}

	$default_by_offer = array(
		'standard' => mtuc_pick_default_popup_scheme_key( $shop, $enabled_by_offer['standard'], $context['standard'] ?? null ),
		'promo'    => mtuc_pick_default_popup_scheme_key( $shop, $enabled_by_offer['promo'], $context['promo'] ?? null ),
	);

	$reklama_url = '';
	if ( ! empty( $shop['reklama_url'] ) && is_string( $shop['reklama_url'] ) ) {
		$reklama_url = esc_url_raw( $shop['reklama_url'] );
	} elseif ( ! empty( $shop['uni_backurl'] ) && is_string( $shop['uni_backurl'] ) ) {
		$reklama_url = esc_url_raw( $shop['uni_backurl'] );
	}

	return array(
		'product_id'              => $product instanceof WC_Product ? $product->get_id() : 0,
		'banner_url'              => mtuc_get_shop_picture_url( $shop, false ),
		'banner_url_mobile'       => mtuc_get_shop_picture_url( $shop, true ),
		'reklama_url'             => $reklama_url,
		'show_first_vnoska'       => mtuc_is_yes_flag( $shop['uni_first_vnoska'] ?? 0 ),
		'shop_months'             => $shop_months,
		'enabled_months_by_offer' => $enabled_by_offer,
		'default_scheme_by_offer' => $default_by_offer,
		'currency'                => mtuc_get_currency_display_config( $shop ),
		'customer'                => mtuc_get_popup_customer_defaults(),
		'has_standard'            => ! empty( $context['standard']['visible'] ),
		'has_promo'               => ! empty( $context['promo']['visible'] ),
		'source'                  => 'product',
		'hide_add_to_cart'        => false,
		'process2'                => mtuc_is_shop_process_2( $shop ),
		'consents'                => mtuc_get_shop_consents( $shop ),
	);
}

/**
 * Whether a month is allowed for default-KOP promo rules.
 *
 * @param array<string, mixed> $by_default Default KOP settings.
 * @param int                  $months     Selected installment count.
 * @param float                $price      Product price including tax.
 * @return bool
 */
function mtuc_is_default_promo_month_allowed( array $by_default, int $months, float $price ): bool {
	$promo_price = isset( $by_default['uni_promo_price'] ) ? (float) $by_default['uni_promo_price'] : 0.0;
	if ( $promo_price > 0 && $price < $promo_price ) {
		return false;
	}

	$meseci_znak = isset( $by_default['uni_promo_meseci_znak'] ) ? strtolower( trim( (string) $by_default['uni_promo_meseci_znak'] ) ) : '';
	$meseci_raw  = isset( $by_default['uni_promo_meseci'] ) ? trim( (string) $by_default['uni_promo_meseci'] ) : '';
	if ( '' === $meseci_znak || '' === $meseci_raw ) {
		return false;
	}

	if ( 'eq' === $meseci_znak ) {
		$allowed = mtuc_parse_underscore_ints( str_replace( ',', '_', $meseci_raw ) );
		return in_array( $months, $allowed, true );
	}

	if ( 'greateq' === $meseci_znak ) {
		$min_months = (int) $meseci_raw;
		if ( $min_months <= 0 ) {
			$parts      = explode( '_', str_replace( ',', '_', $meseci_raw ) );
			$min_months = isset( $parts[0] ) ? (int) trim( $parts[0] ) : 0;
		}

		return $min_months > 0 && $months >= $min_months;
	}

	return false;
}

/**
 * Find all schema filter rows for a specific installment count.
 *
 * @param array<string, mixed>             $shop                  Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list            Coefficient rows.
 * @param float                            $price                 Product price including tax.
 * @param int                              $months                Selected installment count.
 * @param int                              $uni_promo_filter      Filter rows where uni_promo equals this value.
 * @param bool                             $require_zero_interest Require interestPercent == 0.
 * @return array<int, array{filter:array<string,mixed>,coeff_entry:array<string,mixed>,kop_code:string,kop_desc:string}>
 */
function mtuc_find_all_schema_filters_for_month(
	array $shop,
	array $coeff_list,
	float $price,
	int $months,
	int $uni_promo_filter,
	bool $require_zero_interest = false,
	?WC_Product $product = null
): array {
	if ( null === $product ) {
		$product = mtuc_get_current_wc_product();
	}

	if ( ! $product instanceof WC_Product ) {
		return array();
	}

	$by_schema = $shop['kop']['by_schema'] ?? null;
	if ( ! is_array( $by_schema ) ) {
		return array();
	}

	$filters = $by_schema['filters'] ?? null;
	if ( ! is_array( $filters ) ) {
		return array();
	}

	$product_id   = $product->get_id();
	$category_ids = mtuc_get_product_category_ids( $product );
	$matches      = array();

	foreach ( $filters as $filter ) {
		if ( ! is_array( $filter ) ) {
			continue;
		}

		if ( $uni_promo_filter !== (int) ( $filter['uni_promo'] ?? 0 ) ) {
			continue;
		}

		if ( ! mtuc_schema_filter_matches_product( $filter, $product_id, $category_ids, $price ) ) {
			continue;
		}

		$allowed_months = mtuc_get_schema_filter_allowed_months( $filter, $shop );
		if ( ! in_array( $months, $allowed_months, true ) ) {
			continue;
		}

		$kop_code = isset( $filter['uni_kop'] ) ? trim( (string) $filter['uni_kop'] ) : '';
		if ( '' === $kop_code ) {
			continue;
		}

		$coeff_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $months );
		if ( null === $coeff_entry ) {
			continue;
		}

		if ( $require_zero_interest ) {
			$glp = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : -1.0;
			if ( abs( $glp ) > 0.00001 ) {
				continue;
			}
		}

		$matches[] = array(
			'filter'      => $filter,
			'coeff_entry' => $coeff_entry,
			'kop_code'    => $kop_code,
			'kop_desc'    => isset( $filter['uni_kop_desc'] ) ? trim( (string) $filter['uni_kop_desc'] ) : '',
		);
	}

	return $matches;
}

/**
 * Find a schema filter row for a specific installment count.
 *
 * @param array<string, mixed>             $shop                  Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list            Coefficient rows.
 * @param float                            $price                 Product price including tax.
 * @param int                              $months                Selected installment count.
 * @param int                              $uni_promo_filter      Filter rows where uni_promo equals this value.
 * @param bool                             $require_zero_interest Require interestPercent == 0.
 * @return array<string, mixed>|null
 */
function mtuc_find_schema_filter_for_month(
	array $shop,
	array $coeff_list,
	float $price,
	int $months,
	int $uni_promo_filter,
	bool $require_zero_interest = false,
	?WC_Product $product = null
): ?array {
	$matches    = mtuc_find_all_schema_filters_for_month(
		$shop,
		$coeff_list,
		$price,
		$months,
		$uni_promo_filter,
		$require_zero_interest,
		$product
	);
	$best       = null;
	$best_score = -1;

	foreach ( $matches as $match ) {
		$filter = $match['filter'];
		$score  = 1 === (int) ( $filter['uni_parva'] ?? 0 ) ? 2 : 1;
		if ( $score > $best_score ) {
			$best_score = $score;
			$best       = $match;
		}
	}

	return $best;
}

/**
 * Find a specific schema filter row by id for an installment count.
 *
 * @param array<string, mixed>             $shop                  Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list            Coefficient rows.
 * @param float                            $price                 Product price including tax.
 * @param int                              $months                Selected installment count.
 * @param int                              $filter_id             Schema filter id.
 * @param int                              $uni_promo_filter      Filter rows where uni_promo equals this value.
 * @param bool                             $require_zero_interest Require interestPercent == 0.
 * @return array<string, mixed>|null
 */
function mtuc_find_schema_filter_by_id_for_month(
	array $shop,
	array $coeff_list,
	float $price,
	int $months,
	int $filter_id,
	int $uni_promo_filter,
	bool $require_zero_interest = false,
	?WC_Product $product = null
): ?array {
	foreach (
		mtuc_find_all_schema_filters_for_month(
			$shop,
			$coeff_list,
			$price,
			$months,
			$uni_promo_filter,
			$require_zero_interest,
			$product
		) as $match
	) {
		if ( $filter_id === (int) ( $match['filter']['id'] ?? 0 ) ) {
			return $match;
		}
	}

	return null;
}

/**
 * Resolve KOP/coeff row for popup calculation at a specific month count.
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $price      Product price including tax.
 * @param int                              $months     Selected installment count.
 * @param string                           $offer_type standard|promo.
 * @param int                              $filter_id  Schema filter id (0 = best match).
 * @return array<string, mixed>|null
 */
function mtuc_resolve_popup_scheme(
	array $shop,
	array $coeff_list,
	float $price,
	int $months,
	string $offer_type,
	?WC_Product $product = null,
	int $filter_id = 0
): ?array {
	$uni_promo_filter      = ( 'promo' === $offer_type ) ? 1 : 0;
	$require_zero_interest = ( 'promo' === $offer_type );
	$typekop               = (int) ( $shop['uni_typekop'] ?? -1 );

	if ( 1 === $typekop ) {
		if ( $filter_id > 0 ) {
			return mtuc_find_schema_filter_by_id_for_month(
				$shop,
				$coeff_list,
				$price,
				$months,
				$filter_id,
				$uni_promo_filter,
				$require_zero_interest,
				$product
			);
		}

		return mtuc_find_schema_filter_for_month(
			$shop,
			$coeff_list,
			$price,
			$months,
			$uni_promo_filter,
			$require_zero_interest,
			$product
		);
	}

	if ( 0 !== $typekop ) {
		return null;
	}

	$by_default = $shop['kop']['by_default'] ?? null;
	if ( ! is_array( $by_default ) ) {
		return null;
	}

	if ( 'promo' === $offer_type ) {
		if ( ! mtuc_is_default_promo_month_allowed( $by_default, $months, $price ) ) {
			return null;
		}
		$kop_code = isset( $by_default['uni_kop_promo'] ) ? trim( (string) $by_default['uni_kop_promo'] ) : '';
		$kop_desc = isset( $by_default['uni_kop_promo_desc'] ) ? trim( (string) $by_default['uni_kop_promo_desc'] ) : '';
	} else {
		$kop_code = isset( $by_default['uni_kop_default'] ) ? trim( (string) $by_default['uni_kop_default'] ) : '';
		$kop_desc = isset( $by_default['uni_kop_default_desc'] ) ? trim( (string) $by_default['uni_kop_default_desc'] ) : '';
	}

	if ( '' === $kop_code ) {
		return null;
	}

	$coeff_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $months );
	if ( null === $coeff_entry ) {
		return null;
	}

	if ( $require_zero_interest ) {
		$glp = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : -1.0;
		if ( abs( $glp ) > 0.00001 ) {
			return null;
		}
	}

	return array(
		'filter'      => null,
		'coeff_entry' => $coeff_entry,
		'kop_code'    => $kop_code,
		'kop_desc'    => $kop_desc,
	);
}

/**
 * Build AJAX payload for product calculator refresh after price/qty changes.
 *
 * @param WC_Product                $product    Product or variation instance.
 * @param float                     $line_price Total line price including tax.
 * @param array<string, mixed>|null $shop       Shop `data` object from CP.
 * @return array<string, mixed>
 */
function mtuc_build_product_calculator_refresh_payload( WC_Product $product, float $line_price, ?array $shop = null ): array {
	if ( null === $shop ) {
		$shop = mtuc_get_shop_data();
	}

	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return array(
			'visible' => false,
		);
	}

	if ( ! Mtuc_Settings::is_enabled() || ! mtuc_is_yes_flag( $shop['uni_status'] ?? 0 ) ) {
		return array(
			'visible' => false,
		);
	}

	$line_price = round( max( 0.0, $line_price ), 2 );
	if ( $line_price <= 0 || ! mtuc_is_product_price_in_shop_range( $shop, $line_price ) ) {
		return array(
			'visible' => false,
		);
	}

	$offer = mtuc_get_product_calculator_offer( $shop, $product, $line_price );
	if ( null === $offer ) {
		return array(
			'visible' => false,
		);
	}

	$standard = $offer['standard'] ?? null;
	$promo    = $offer['promo'] ?? null;
	$popup    = mtuc_get_product_popup_context(
		$shop,
		array(
			'standard' => $standard,
			'promo'    => $promo,
		),
		$product,
		$line_price
	);

	$parent_id    = (int) $product->get_parent_id();
	$payload_id   = $parent_id > 0 ? $parent_id : $product->get_id();
	$variation_id = $parent_id > 0 ? $product->get_id() : 0;

	return array(
		'visible'              => true,
		'line_price'           => $line_price,
		'product_id'           => $payload_id,
		'variation_id'         => $variation_id,
		'standard'             => is_array( $standard ) && ! empty( $standard['visible'] )
			? array(
				'visible'    => true,
				'price_text' => (string) ( $standard['price_text'] ?? '' ),
			)
			: null,
		'promo'                => is_array( $promo ) && ! empty( $promo['visible'] )
			? array(
				'visible'    => true,
				'price_text' => (string) ( $promo['price_text'] ?? '' ),
			)
			: null,
		'enabledMonthsByOffer' => isset( $popup['enabled_months_by_offer'] ) && is_array( $popup['enabled_months_by_offer'] )
			? $popup['enabled_months_by_offer']
			: array(),
		'defaultSchemeByOffer' => isset( $popup['default_scheme_by_offer'] ) && is_array( $popup['default_scheme_by_offer'] )
			? $popup['default_scheme_by_offer']
			: array(),
	);
}

/**
 * AJAX: refresh calculator buttons and popup scheme options for a new line price.
 *
 * @return void
 */
function mtuc_ajax_product_calculator_refresh(): void {
	check_ajax_referer( 'mtuc_popup', 'security' );

	if ( ! Mtuc_Settings::is_enabled() ) {
		wp_send_json_error(
			array( 'message' => __( 'Модулът не е активен.', 'mtunicredit' ) ),
			403
		);
	}

	$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
	$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
	$line_price   = isset( $_POST['line_price'] ) ? (float) wp_unslash( $_POST['line_price'] ) : 0.0;

	$load_id = $variation_id > 0 ? $variation_id : $product_id;
	$product = mtuc_get_wc_product_by_id( $load_id );
	if ( ! $product instanceof WC_Product ) {
		wp_send_json_error(
			array( 'message' => __( 'Невалиден продукт.', 'mtunicredit' ) ),
			400
		);
	}

	wp_send_json_success(
		mtuc_build_product_calculator_refresh_payload( $product, $line_price )
	);
}

/**
 * Calculate popup credit values for the selected scheme.
 *
 * @param array<string, mixed>             $shop             Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list       Coefficient rows.
 * @param float                            $price            Product price including tax.
 * @param int                              $months           Selected installment count.
 * @param string                           $popup_offer_type Popup context: standard|promo.
 * @param float                            $parva            User-entered initial payment.
 * @param WC_Product|null                  $product          Product instance.
 * @param int                              $filter_id        Schema filter id (0 for default KOP / promo best match).
 * @param string                           $scheme_type      Selected scheme: standard|promo.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_calculate_popup_credit(
	array $shop,
	array $coeff_list,
	float $price,
	int $months,
	string $popup_offer_type,
	float $parva = 0.0,
	?WC_Product $product = null,
	int $filter_id = 0,
	string $scheme_type = 'standard'
) {
	$enabled_options = mtuc_get_popup_enabled_months( $shop, $coeff_list, $price, $popup_offer_type, $product );
	if ( ! mtuc_is_popup_scheme_option_enabled( $enabled_options, $months, $filter_id, $scheme_type ) ) {
		return new WP_Error( 'mtuc_popup_invalid_months', __( 'Избраният срок не е наличен.', 'mtunicredit' ) );
	}

	$scheme = mtuc_resolve_popup_scheme( $shop, $coeff_list, $price, $months, $scheme_type, $product, $filter_id );
	if ( null === $scheme ) {
		return new WP_Error( 'mtuc_popup_no_scheme', __( 'Няма налична схема за избраните параметри.', 'mtunicredit' ) );
	}

	$coeff_entry = $scheme['coeff_entry'];
	$filter      = isset( $scheme['filter'] ) && is_array( $scheme['filter'] ) ? $scheme['filter'] : null;
	$kimb        = isset( $coeff_entry['coeff'] ) ? (float) $coeff_entry['coeff'] : 0.0;

	if ( $kimb <= 0 ) {
		return new WP_Error( 'mtuc_popup_invalid_coeff', __( 'Липсва валиден коефициент за изчисление.', 'mtunicredit' ) );
	}

	$show_parva   = mtuc_is_yes_flag( $shop['uni_first_vnoska'] ?? 0 );
	$parva_locked = false;

	if ( null !== $filter && 1 === (int) ( $filter['uni_parva'] ?? 0 ) ) {
		$parva        = round( $price / $months, 2 );
		$parva_locked = true;
	} elseif ( ! $show_parva ) {
		$parva = 0.0;
	} else {
		$parva = max( 0.0, min( round( $parva, 2 ), $price ) );
	}

	$loan_amount = round( $price - $parva, 2 );
	if ( $loan_amount <= 0 ) {
		return new WP_Error( 'mtuc_popup_invalid_loan', __( 'Общата сума на заема трябва да е положителна.', 'mtunicredit' ) );
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
		'kop_code'            => (string) $scheme['kop_code'],
		'price'               => round( $price, 2 ),
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
		'price_display'       => mtuc_format_popup_amount_display( $price, $shop ),
		'parva_display'       => mtuc_format_popup_amount_display( $parva, $shop ),
		'loan_display'        => mtuc_format_popup_amount_display( $loan_amount, $shop ),
		'monthly_display'     => mtuc_format_popup_amount_display( $monthly_installment, $shop ),
		'total_display'       => mtuc_format_popup_amount_display( $total_payable, $shop ),
	);
}

/**
 * AJAX: recalculate popup values.
 *
 * @return void
 */
function mtuc_ajax_popup_calculate(): void {
	check_ajax_referer( 'mtuc_popup', 'security' );

	if ( ! Mtuc_Settings::is_enabled() ) {
		wp_send_json_error(
			array( 'message' => __( 'Модулът не е активен.', 'mtunicredit' ) ),
			403
		);
	}

	$scheme_key  = isset( $_POST['scheme_key'] ) ? sanitize_text_field( wp_unslash( $_POST['scheme_key'] ) ) : '';
	$filter_id   = isset( $_POST['filter_id'] ) ? absint( wp_unslash( $_POST['filter_id'] ) ) : 0;
	$months      = isset( $_POST['months'] ) ? absint( wp_unslash( $_POST['months'] ) ) : 0;
	$scheme_type = isset( $_POST['scheme_type'] ) ? sanitize_key( wp_unslash( $_POST['scheme_type'] ) ) : 'standard';

	if ( '' !== $scheme_key ) {
		$parsed      = mtuc_parse_popup_scheme_option_key( $scheme_key );
		$months      = (int) $parsed['months'];
		$filter_id   = (int) $parsed['filter_id'];
		$scheme_type = (string) $parsed['scheme_type'];
	}

	$type = isset( $_POST['offer_type'] ) ? sanitize_key( wp_unslash( $_POST['offer_type'] ) ) : 'standard';

	if ( ! in_array( $type, array( 'standard', 'promo' ), true ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Невалиден тип оферта.', 'mtunicredit' ) ),
			400
		);
	}

	if ( ! in_array( $scheme_type, array( 'standard', 'promo' ), true ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Невалидна схема.', 'mtunicredit' ) ),
			400
		);
	}

	$parva_raw = isset( $_POST['parva'] ) ? wp_unslash( $_POST['parva'] ) : '0';
	$parva     = is_numeric( $parva_raw ) ? (float) $parva_raw : 0.0;

	$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'product';

	if ( in_array( $source, array( 'cart', 'checkout' ), true ) ) {
		$cart_state = mtuc_resolve_cart_scheme_state();
		if ( is_wp_error( $cart_state ) ) {
			wp_send_json_error(
				array( 'message' => $cart_state->get_error_message() ),
				400
			);
		}

		$shop       = mtuc_get_shop_data();
		$coeff_list = mtuc_get_shop_coeff_list( $shop );

		if ( 'checkout' === $source ) {
			$common = mtuc_resolve_checkout_scheme_common( $cart_state );
			$type   = 'standard';
		} else {
			$common = 'promo' === $type
				? (array) ( $cart_state['common_promo'] ?? array() )
				: (array) ( $cart_state['common_standard'] ?? array() );
		}

		$result = mtuc_calculate_cart_popup_credit(
			$shop,
			$coeff_list,
			(float) ( $cart_state['cart_total'] ?? 0 ),
			$months,
			$type,
			$parva,
			$filter_id,
			$scheme_type,
			$common
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array( 'message' => $result->get_error_message() ),
				400
			);
		}

		wp_send_json_success( $result );
	}

	$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
	$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
	$line_price   = isset( $_POST['line_price'] ) ? (float) wp_unslash( $_POST['line_price'] ) : 0.0;

	$load_id = $variation_id > 0 ? $variation_id : $product_id;
	$product = mtuc_get_wc_product_by_id( $load_id );
	if ( ! $product instanceof WC_Product ) {
		wp_send_json_error(
			array( 'message' => __( 'Невалиден продукт.', 'mtunicredit' ) ),
			400
		);
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) ) {
		wp_send_json_error(
			array( 'message' => $shop->get_error_message() ),
			500
		);
	}

	$price = $line_price > 0 ? round( $line_price, 2 ) : mtuc_get_product_price( $product );
	if ( null === $price ) {
		wp_send_json_error(
			array( 'message' => __( 'Не може да се определи цената на продукта.', 'mtunicredit' ) ),
			400
		);
	}

	if ( ! mtuc_is_product_price_in_shop_range( $shop, $price ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Цената на продукта е извън допустимия диапазон.', 'mtunicredit' ) ),
			400
		);
	}

	$coeff_list = mtuc_get_shop_coeff_list( $shop );
	$result     = mtuc_calculate_popup_credit( $shop, $coeff_list, $price, $months, $type, $parva, $product, $filter_id, $scheme_type );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array( 'message' => $result->get_error_message() ),
			400
		);
	}

	wp_send_json_success( $result );
}

/**
 * Render popup markup in footer on product or cart pages.
 *
 * @return void
 */
function mtuc_render_credit_popup(): void {
	$context = mtuc_get_product_calculator_context();
	$source  = 'product';

	if ( null === $context ) {
		$context = mtuc_get_cart_calculator_context();
		$source  = 'cart';
	}

	if ( null === $context ) {
		return;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return;
	}

	$template = MTUC_PLUGIN_DIR . '/templates/product-popup.php';
	if ( ! is_readable( $template ) ) {
		return;
	}

	if ( ! isset( $context['popup'] ) || ! is_array( $context['popup'] ) ) {
		$context['popup'] = array();
	}

	$context['popup']['source'] = $source;
	if ( 'cart' === $source ) {
		$context['popup']['hide_add_to_cart'] = true;
	}

	include $template;
}

/**
 * @deprecated 1.1.0 Use mtuc_render_credit_popup().
 * @return void
 */
function mtuc_render_product_popup(): void {
	mtuc_render_credit_popup();
}
