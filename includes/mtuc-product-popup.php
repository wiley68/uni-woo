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
}

/**
 * Register popup frontend hooks (footer markup).
 *
 * @return void
 */
function mtuc_register_product_popup_hooks(): void {
	add_action( 'wp_footer', 'mtuc_render_product_popup', 5 );
}

/**
 * Enabled installment counts from shop settings.
 *
 * @param array<string, mixed> $shop Shop `data` object from CP.
 * @return array<int, int>
 */
function mtuc_get_shop_enabled_months( array $shop ): array {
	$choices = array( 3, 4, 5, 6, 9, 10, 12, 15, 18, 24, 30, 36 );
	$enabled = array();

	foreach ( $choices as $months ) {
		if ( mtuc_is_yes_flag( $shop[ 'uni_meseci_' . $months ] ?? 0 ) ) {
			$enabled[] = $months;
		}
	}

	return $enabled;
}

/**
 * Popup month choices: shop uni_meseci_* intersect valid KOP/coeff matches.
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $price      Product price including tax.
 * @param string                           $offer_type standard|promo.
 * @param WC_Product|null                  $product    Product instance.
 * @return array<int, int>
 */
function mtuc_get_popup_enabled_months(
	array $shop,
	array $coeff_list,
	float $price,
	string $offer_type,
	?WC_Product $product = null
): array {
	$shop_months = mtuc_get_shop_enabled_months( $shop );
	$enabled     = array();

	foreach ( $shop_months as $months ) {
		if ( null !== mtuc_resolve_popup_scheme( $shop, $coeff_list, $price, $months, $offer_type, $product ) ) {
			$enabled[] = $months;
		}
	}

	return $enabled;
}

/**
 * Default installment count for popup select.
 *
 * @param array<string, mixed> $shop           Shop `data` object from CP.
 * @param array<int, int>      $enabled_months Allowed months for the offer.
 * @return int
 */
function mtuc_pick_default_popup_month( array $shop, array $enabled_months ): int {
	$preferred = (int) ( $shop['uni_shema_current'] ?? 0 );

	if ( in_array( $preferred, $enabled_months, true ) ) {
		return $preferred;
	}

	if ( ! empty( $enabled_months ) ) {
		return (int) $enabled_months[0];
	}

	return 0;
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
				'mode'            => 1,
				'primary_sign'    => __( 'лв.', 'mtunicredit' ),
				'secondary_sign'  => __( 'евро', 'mtunicredit' ),
				'dual'            => true,
			);
		case 2:
			return array(
				'mode'            => 2,
				'primary_sign'    => __( 'евро', 'mtunicredit' ),
				'secondary_sign'  => __( 'лв.', 'mtunicredit' ),
				'dual'            => true,
			);
		case 3:
			return array(
				'mode'            => 3,
				'primary_sign'    => __( 'евро', 'mtunicredit' ),
				'secondary_sign'  => '',
				'dual'            => false,
			);
		case 0:
		default:
			return array(
				'mode'            => 0,
				'primary_sign'    => __( 'лв.', 'mtunicredit' ),
				'secondary_sign'  => '',
				'dual'            => false,
			);
	}
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
	$amount = round( $amount, 2 );

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

			$defaults['phone'] = (string) $customer->get_billing_phone();

			$address_parts = array_filter(
				array(
					(string) $customer->get_billing_address_1(),
					(string) $customer->get_billing_address_2(),
					(string) $customer->get_billing_city(),
					(string) $customer->get_billing_postcode(),
				)
			);
			$defaults['address'] = implode( ', ', $address_parts );
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
function mtuc_get_product_popup_context( array $shop, array $context ): array {
	$product     = mtuc_get_current_wc_product();
	$price       = $product instanceof WC_Product ? mtuc_get_product_price( $product ) : null;
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
		'standard' => mtuc_pick_default_popup_month( $shop, $enabled_by_offer['standard'] ),
		'promo'    => mtuc_pick_default_popup_month( $shop, $enabled_by_offer['promo'] ),
	);

	$reklama_url = '';
	if ( ! empty( $shop['reklama_url'] ) && is_string( $shop['reklama_url'] ) ) {
		$reklama_url = esc_url_raw( $shop['reklama_url'] );
	} elseif ( ! empty( $shop['uni_backurl'] ) && is_string( $shop['uni_backurl'] ) ) {
		$reklama_url = esc_url_raw( $shop['uni_backurl'] );
	}

	return array(
		'product_id'             => $product instanceof WC_Product ? $product->get_id() : 0,
		'banner_url'             => mtuc_get_shop_picture_url( $shop, false ),
		'reklama_url'            => $reklama_url,
		'show_first_vnoska'      => mtuc_is_yes_flag( $shop['uni_first_vnoska'] ?? 0 ),
		'shop_months'            => $shop_months,
		'enabled_months_by_offer' => $enabled_by_offer,
		'default_months_by_offer' => $default_by_offer,
		'currency'               => mtuc_get_currency_display_config( $shop ),
		'customer'               => mtuc_get_popup_customer_defaults(),
		'has_standard'           => ! empty( $context['standard']['visible'] ),
		'has_promo'              => ! empty( $context['promo']['visible'] ),
	);
}

/**
 * Whether a month is allowed for default-KOP promo rules.
 *
 * @param array<string, mixed> $by_default Default KOP settings.
 * @param int                    $months     Selected installment count.
 * @param float                  $price      Product price including tax.
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
	if ( null === $product ) {
		$product = mtuc_get_current_wc_product();
	}

	if ( ! $product instanceof WC_Product ) {
		return null;
	}

	$by_schema = $shop['kop']['by_schema'] ?? null;
	if ( ! is_array( $by_schema ) ) {
		return null;
	}

	$filters = $by_schema['filters'] ?? null;
	if ( ! is_array( $filters ) ) {
		return null;
	}

	$product_id   = $product->get_id();
	$category_ids = mtuc_get_product_category_ids( $product );
	$best         = null;
	$best_score   = -1;

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

		$allowed_months = mtuc_parse_underscore_ints( isset( $filter['uni_meseci'] ) ? (string) $filter['uni_meseci'] : '' );
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

		$score = 1 === (int) ( $filter['uni_parva'] ?? 0 ) ? 2 : 1;
		if ( $score > $best_score ) {
			$best_score = $score;
			$best       = array(
				'filter'      => $filter,
				'coeff_entry' => $coeff_entry,
				'kop_code'    => $kop_code,
			);
		}
	}

	return $best;
}

/**
 * Resolve KOP/coeff row for popup calculation at a specific month count.
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $price      Product price including tax.
 * @param int                              $months     Selected installment count.
 * @param string                           $offer_type standard|promo.
 * @return array<string, mixed>|null
 */
function mtuc_resolve_popup_scheme(
	array $shop,
	array $coeff_list,
	float $price,
	int $months,
	string $offer_type,
	?WC_Product $product = null
): ?array {
	$uni_promo_filter      = ( 'promo' === $offer_type ) ? 1 : 0;
	$require_zero_interest = ( 'promo' === $offer_type );
	$typekop               = (int) ( $shop['uni_typekop'] ?? -1 );

	if ( 1 === $typekop ) {
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
	} else {
		$kop_code = isset( $by_default['uni_kop_default'] ) ? trim( (string) $by_default['uni_kop_default'] ) : '';
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
	);
}

/**
 * Calculate popup credit values for the selected scheme.
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param float                            $price      Product price including tax.
 * @param int                              $months     Selected installment count.
 * @param string                           $offer_type standard|promo.
 * @param float                            $parva      User-entered initial payment.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_calculate_popup_credit(
	array $shop,
	array $coeff_list,
	float $price,
	int $months,
	string $offer_type,
	float $parva = 0.0,
	?WC_Product $product = null
) {
	$enabled_months = mtuc_get_popup_enabled_months( $shop, $coeff_list, $price, $offer_type, $product );
	if ( ! in_array( $months, $enabled_months, true ) ) {
		return new WP_Error( 'mtuc_popup_invalid_months', __( 'Избраният срок не е наличен.', 'mtunicredit' ) );
	}

	$scheme = mtuc_resolve_popup_scheme( $shop, $coeff_list, $price, $months, $offer_type, $product );
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

	return array(
		'months'              => $months,
		'offer_type'          => $offer_type,
		'kop_code'            => (string) $scheme['kop_code'],
		'price'               => round( $price, 2 ),
		'parva'               => $parva,
		'parva_locked'        => $parva_locked,
		'show_parva'          => $show_parva,
		'loan_amount'         => $loan_amount,
		'monthly_installment' => $monthly_installment,
		'total_payable'       => $total_payable,
		'glp'                 => round( $glp, 2 ),
		'gpr'                 => $gpr,
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

	$months = isset( $_POST['months'] ) ? absint( wp_unslash( $_POST['months'] ) ) : 0;
	$type   = isset( $_POST['offer_type'] ) ? sanitize_key( wp_unslash( $_POST['offer_type'] ) ) : 'standard';

	if ( ! in_array( $type, array( 'standard', 'promo' ), true ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Невалиден тип оферта.', 'mtunicredit' ) ),
			400
		);
	}

	$parva_raw = isset( $_POST['parva'] ) ? wp_unslash( $_POST['parva'] ) : '0';
	$parva     = is_numeric( $parva_raw ) ? (float) $parva_raw : 0.0;

	$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
	$product    = mtuc_get_wc_product_by_id( $product_id );
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

	$price = mtuc_get_product_price( $product );
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
	$result     = mtuc_calculate_popup_credit( $shop, $coeff_list, $price, $months, $type, $parva, $product );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error(
			array( 'message' => $result->get_error_message() ),
			400
		);
	}

	wp_send_json_success( $result );
}

/**
 * Render popup markup in footer on product pages.
 *
 * @return void
 */
function mtuc_render_product_popup(): void {
	$context = mtuc_get_product_calculator_context();
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

	include $template;
}
