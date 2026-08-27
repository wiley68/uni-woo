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
 * Cart merchandise contents total including tax (no shipping/fees).
 *
 * Prefer mtuc_get_canonical_financeable_cart_total() for financing eligibility,
 * installment calculation, snapshots, CP and SmartUCF amounts.
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

		$variation_id = mtuc_get_product_variation_id( $product );
		$parent_id    = mtuc_get_catalog_product_id( $product );

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

// Pure match-key / LCM / intersection helpers live in mtuc-cart-scheme-intersection.php.

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

// Checkout unification (standard + promo composition) lives in mtuc-cart-scheme-intersection.php.

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

		// Conflicting contributing filter policies are unsupported for the
		// representative preview; never let first cart-line order decide parva.
		if ( array_key_exists( 'parva_policy_consistent', $option ) && empty( $option['parva_policy_consistent'] ) ) {
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

		$filter      = (int) ( $option['filter_id'] ?? 0 ) > 0
			? mtuc_get_shop_schema_filter_by_id( $shop, (int) $option['filter_id'] )
			: null;
		$parva_state = mtuc_resolve_parva_calculation_state( $shop, $cart_total, $months, 0.0, $filter );
		$calc_price  = round( $cart_total - (float) $parva_state['parva'], 2 );
		if ( $calc_price <= 0 ) {
			continue;
		}

		$offer = mtuc_build_button_offer(
			$offer_type,
			$kop_code,
			$months,
			$calc_price,
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
 * Whether the cart calculator shell may load (shop active, cart has lines, no price gate).
 *
 * @return bool
 */
function mtuc_can_render_cart_calculator_shell(): bool {
	if ( ! Mtuc_Settings::is_enabled() ) {
		return false;
	}

	if ( '' === (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID ) ) {
		return false;
	}

	if ( empty( mtuc_get_cart_line_entries() ) ) {
		return false;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return false;
	}

	return mtuc_is_yes_flag( $shop['uni_status'] ?? 0 );
}

/**
 * Build cart calculator context from the current cart (no page guard).
 *
 * @return array<string, mixed>|null
 */
function mtuc_build_cart_calculator_context(): ?array {
	if ( ! mtuc_can_render_cart_calculator_shell() ) {
		return null;
	}

	$lines = mtuc_get_cart_line_entries();
	$shop  = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return null;
	}

	if ( ! mtuc_is_transaction_currency_compatible( $shop ) ) {
		return null;
	}

	$cart_total       = mtuc_get_canonical_financeable_cart_total();
	$coeff_list       = mtuc_get_shop_coeff_list( $shop );
	$standard         = null;
	$promo            = null;
	$common_standard  = array();
	$common_promo     = array();
	$has_any_standard = false;
	$has_any_promo    = false;

	if ( $cart_total > 0 && mtuc_is_product_price_in_shop_range( $shop, $cart_total ) ) {
		$standard_line_sets = array();
		$promo_line_sets    = array();

		foreach ( $lines as $line ) {
			$standard_line_sets[] = mtuc_get_cart_line_scheme_options( $shop, $coeff_list, $cart_total, $line['product'], 'standard' );
			$promo_line_sets[]    = mtuc_get_cart_line_scheme_options( $shop, $coeff_list, $cart_total, $line['product'], 'promo' );
		}

		$common_standard  = mtuc_intersect_cart_scheme_options( $standard_line_sets );
		$common_promo     = mtuc_intersect_cart_scheme_options( $promo_line_sets );
		$has_any_standard = mtuc_cart_has_any_line_scheme_options( $shop, $coeff_list, $cart_total, $lines, 'standard' );
		$has_any_promo    = mtuc_cart_has_any_line_scheme_options( $shop, $coeff_list, $cart_total, $lines, 'promo' );

		if ( $has_any_standard || $has_any_promo ) {
			$standard_offer = mtuc_build_cart_button_offer_from_options( $shop, $coeff_list, $cart_total, $common_standard, 'standard' );
			$promo_offer    = mtuc_build_cart_button_offer_from_options( $shop, $coeff_list, $cart_total, $common_promo, 'promo' );

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

			if ( is_array( $standard ) && ! empty( $standard['image_only'] ) ) {
				$promo = null;
			}
		}
	}

	$is_dark_button = mtuc_is_yes_flag( $shop['uni_type_button'] ?? 0 );
	$button_width   = isset( $shop['uni_button_width'] ) ? absint( $shop['uni_button_width'] ) : 0;
	$button_height  = isset( $shop['uni_button_height'] ) ? absint( $shop['uni_button_height'] ) : 0;

	if ( $button_width <= 0 ) {
		$button_width = 290;
	}
	if ( $button_height <= 0 ) {
		$button_height = 56;
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
		'heading'          => mtuc_get_shop_calculator_heading( $shop ),
		'popup'            => mtuc_get_cart_popup_context(
			$shop,
			array(
				'standard'        => $standard,
				'promo'           => $promo,
				'common_standard' => $common_standard,
				'common_promo'    => $common_promo,
			),
			$cart_total
		),
	);
}


/**
 * Whether the current cart cannot be purchased on leasing as a whole.
 *
 * @return bool
 */
function mtuc_is_cart_split_required(): bool {
	$context = mtuc_build_cart_calculator_context();
	if ( null === $context ) {
		return false;
	}

	$standard = isset( $context['standard'] ) && is_array( $context['standard'] ) ? $context['standard'] : null;

	return null !== $standard && ! empty( $standard['image_only'] );
}

/**
 * Stable fingerprint for cart split notification deduplication.
 *
 * @param array<int, array<string, mixed>> $lines      Cart line entries.
 * @param float                            $cart_total Cart total.
 * @return string
 */
function mtuc_get_cart_split_notification_fingerprint( array $lines, float $cart_total ): string {
	$payload = array(
		'cart_total' => round( $cart_total, 2 ),
		'lines'      => array(),
	);

	foreach ( $lines as $line ) {
		if ( ! is_array( $line ) ) {
			continue;
		}

		$payload['lines'][] = array(
			'parent_id'    => (int) ( $line['parent_id'] ?? 0 ),
			'variation_id' => (int) ( $line['variation_id'] ?? 0 ),
			'quantity'     => (int) ( $line['quantity'] ?? 0 ),
			'line_total'   => round( (float) ( $line['line_total'] ?? 0 ), 2 ),
		);
	}

	return md5( (string) wp_json_encode( $payload ) );
}

/**
 * Build HTML body for cart split required shop notification.
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string,mixed>>  $lines      Cart line entries.
 * @param float                            $cart_total Cart total.
 * @return string
 */
function mtuc_build_cart_split_notification_body( array $shop, array $lines, float $cart_total ): string {
	$message = __( 'Не може да закупите цялата количка на изплащане. Моля, разделете поръчката си ако желаете да я закупите на изплащане.', 'mtunicredit' );

	$shop_rows = array(
		__( 'Магазин', 'mtunicredit' ) => isset( $shop['name'] ) ? (string) $shop['name'] : '',
		__( 'Тип', 'mtunicredit' )     => isset( $shop['type'] ) ? (string) $shop['type'] : '',
	);

	$shop_html = '';
	foreach ( $shop_rows as $label => $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			continue;
		}

		$shop_html .= '<tr><th style="text-align:left;padding:4px 12px 4px 0;vertical-align:top;">'
			. esc_html( (string) $label )
			. '</th><td style="padding:4px 0;">'
			. esc_html( $value )
			. '</td></tr>';
	}

	$cart_rows = '';
	foreach ( $lines as $line ) {
		if ( ! is_array( $line ) || ! isset( $line['product'] ) || ! $line['product'] instanceof WC_Product ) {
			continue;
		}

		$product  = $line['product'];
		$name     = $product->get_name();
		$sku      = $product->get_sku();
		$quantity = max( 1, (int) ( $line['quantity'] ?? 1 ) );
		$total    = round( (float) ( $line['line_total'] ?? 0 ), 2 );
		$display  = mtuc_format_popup_amount_display( $total, $shop );
		$price    = $display['primary'];
		if ( ! empty( $display['dual'] ) && ! empty( $display['secondary'] ) ) {
			$price .= ' / ' . $display['secondary'];
		}

		$label = $name;
		if ( '' !== $sku ) {
			$label .= ' (SKU: ' . $sku . ')';
		}

		$cart_rows .= '<tr>'
			. '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;">' . esc_html( $label ) . '</td>'
			. '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:center;">' . esc_html( (string) $quantity ) . '</td>'
			. '<td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:right;">' . esc_html( $price ) . '</td>'
			. '</tr>';
	}

	$cart_total_display = mtuc_format_popup_amount_display( $cart_total, $shop );
	$cart_total_text    = $cart_total_display['primary'];
	if ( ! empty( $cart_total_display['dual'] ) && ! empty( $cart_total_display['secondary'] ) ) {
		$cart_total_text .= ' / ' . $cart_total_display['secondary'];
	}

	$html  = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#111;">';
	$html .= '<p style="margin:0 0 16px;">' . esc_html( $message ) . '</p>';
	$html .= '<h2 style="margin:0 0 8px;font-size:16px;">' . esc_html__( 'Информация за магазина', 'mtunicredit' ) . '</h2>';
	$html .= '<table style="border-collapse:collapse;margin:0 0 20px;">' . $shop_html . '</table>';
	$html .= '<h2 style="margin:0 0 8px;font-size:16px;">' . esc_html__( 'Съдържание на количката', 'mtunicredit' ) . '</h2>';
	$html .= '<table style="border-collapse:collapse;width:100%;max-width:640px;">';
	$html .= '<thead><tr>'
		. '<th style="text-align:left;padding:6px 8px;border-bottom:2px solid #ccc;">' . esc_html__( 'Продукт', 'mtunicredit' ) . '</th>'
		. '<th style="text-align:center;padding:6px 8px;border-bottom:2px solid #ccc;">' . esc_html__( 'Кол.', 'mtunicredit' ) . '</th>'
		. '<th style="text-align:right;padding:6px 8px;border-bottom:2px solid #ccc;">' . esc_html__( 'Сума', 'mtunicredit' ) . '</th>'
		. '</tr></thead><tbody>' . $cart_rows . '</tbody>';
	$html .= '<tfoot><tr>'
		. '<td colspan="2" style="padding:8px 8px 0;text-align:right;font-weight:bold;">' . esc_html__( 'Общо:', 'mtunicredit' ) . '</td>'
		. '<td style="padding:8px 8px 0;text-align:right;font-weight:bold;">' . esc_html( $cart_total_text ) . '</td>'
		. '</tr></tfoot></table>';
	$html .= '</div>';

	return $html;
}

/**
 * Send shop notification when the cart cannot be purchased on leasing as a whole.
 *
 * @return bool True when at least one recipient was mailed successfully.
 */
function mtuc_send_cart_split_required_notification(): bool {
	if ( ! mtuc_is_cart_split_required() ) {
		return false;
	}

	$lines = mtuc_get_cart_line_entries();
	if ( empty( $lines ) ) {
		return false;
	}

	$cart_total = mtuc_get_canonical_financeable_cart_total();
	$shop       = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return false;
	}

	$recipients = mtuc_parse_shop_notification_emails( $shop );
	if ( empty( $recipients ) ) {
		return false;
	}

	$fingerprint = mtuc_get_cart_split_notification_fingerprint( $lines, $cart_total );
	$session_key = 'mtuc_cart_split_notified';

	if ( function_exists( 'WC' ) && WC()->session ) {
		$sent_for = (string) WC()->session->get( $session_key, '' );
		if ( $sent_for === $fingerprint ) {
			return false;
		}
	}

	$to      = array_shift( $recipients );
	$cc      = $recipients;
	$subject = sprintf(
		/* translators: %s: site name */
		__( '%s — не може цялата количка на изплащане', 'mtunicredit' ),
		wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
	);
	$body    = mtuc_build_cart_split_notification_body( $shop, $lines, $cart_total );

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	if ( ! empty( $cc ) ) {
		$headers[] = 'Cc: ' . implode( ', ', $cc );
	}

	if ( function_exists( 'wc_mail' ) ) {
		$sent = wc_mail( $to, $subject, $body, $headers );
	} else {
		$sent = wp_mail( $to, $subject, $body, $headers );
	}

	if ( $sent && function_exists( 'WC' ) && WC()->session ) {
		WC()->session->set( $session_key, $fingerprint );
	}

	return (bool) $sent;
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
		'process2'                => mtuc_is_shop_process_2( $shop ),
		'consents'                => mtuc_get_shop_consents( $shop ),
	);

	if ( 'checkout' === $source ) {
		$unified_schemes = mtuc_build_checkout_unified_scheme_options( $common_standard, $common_promo );

		$popup['enabled_schemes']    = $unified_schemes;
		$popup['default_scheme_key'] = mtuc_pick_default_checkout_scheme_key(
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

	if ( ! mtuc_is_transaction_currency_compatible( $shop ) ) {
		return new WP_Error(
			'mtuc_currency_mismatch',
			__( 'Валутата на магазина не съвпада с конфигурацията за финансиране.', 'mtunicredit' )
		);
	}

	$cart_total = mtuc_get_canonical_financeable_cart_total();
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

	$kimb = isset( $coeff_entry['coeff'] ) ? (float) $coeff_entry['coeff'] : 0.0;
	if ( $kimb <= 0 ) {
		return new WP_Error( 'mtuc_cart_invalid_coeff', __( 'Липсва валиден коефициент за изчисление.', 'mtunicredit' ) );
	}

	$filter      = $filter_id > 0 ? mtuc_get_shop_schema_filter_by_id( $shop, $filter_id ) : null;
	$parva_state = mtuc_resolve_parva_calculation_state( $shop, $cart_total, $months, $parva, $filter );
	$parva       = $parva_state['parva'];
	$parva_locked = $parva_state['parva_locked'];
	$show_parva  = $parva_state['show_parva'];

	$amounts = mtuc_compute_financing_amounts( $cart_total, $parva, $kimb, $months, 'mtuc_cart_invalid_loan' );
	if ( is_wp_error( $amounts ) ) {
		return $amounts;
	}

	$loan_amount         = $amounts['loan_amount'];
	$monthly_installment = $amounts['monthly_installment'];
	$total_payable       = $amounts['total_payable'];
	$glp_raw             = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : 0.0;
	$rates               = mtuc_finalize_financing_interest_rates( $months, $monthly_installment, $loan_amount, $glp_raw );
	$glp                 = $rates['glp'];
	$gpr                 = $rates['gpr'];

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
