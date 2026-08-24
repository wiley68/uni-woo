<?php
/**
 * Financial integrity helpers (AUD-WOO-001/002/003).
 *
 * Authoritative product pricing, canonical financeable amounts, currency gate.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Expected bank/CP transaction currency from shop `uni_eur` mode.
 *
 * Semantics (display vs transaction):
 * - 0: BGN only (transaction BGN)
 * - 1: BGN primary + EUR dual display (transaction BGN)
 * - 2: EUR primary + BGN dual display (transaction EUR)
 * - 3: EUR only (transaction EUR)
 *
 * Dual-display modes do not change the transaction currency.
 *
 * @param array<string, mixed> $shop Shop `data` from CP.
 * @return string BGN|EUR
 */
function mtuc_get_expected_transaction_currency( array $shop ): string {
	$uni_eur = (int) ( $shop['uni_eur'] ?? 0 );

	return in_array( $uni_eur, array( 2, 3 ), true ) ? 'EUR' : 'BGN';
}

/**
 * Current WooCommerce shop/order currency ISO code.
 *
 * @param string|null $override Optional explicit currency (e.g. order currency).
 * @return string Uppercase ISO or empty.
 */
function mtuc_get_woocommerce_transaction_currency( ?string $override = null ): string {
	if ( null !== $override && '' !== trim( $override ) ) {
		return strtoupper( trim( $override ) );
	}

	if ( function_exists( 'get_woocommerce_currency' ) ) {
		return strtoupper( (string) get_woocommerce_currency() );
	}

	return '';
}

/**
 * Whether WooCommerce currency matches the CP financing transaction currency.
 *
 * @param array<string, mixed> $shop     Shop `data` from CP.
 * @param string|null          $currency Optional Woo currency override.
 * @return bool
 */
function mtuc_is_transaction_currency_compatible( array $shop, ?string $currency = null ): bool {
	$iso = mtuc_get_woocommerce_transaction_currency( $currency );
	if ( ! in_array( $iso, array( 'BGN', 'EUR' ), true ) ) {
		return false;
	}

	return $iso === mtuc_get_expected_transaction_currency( $shop );
}

/**
 * Resolve validated transaction currency for CP/SmartUCF payloads.
 *
 * Returns Woo currency only when compatible with `uni_eur`; otherwise WP_Error.
 * Never converts amounts between BGN and EUR.
 *
 * @param array<string, mixed> $shop     Shop `data` from CP.
 * @param string|null          $currency Optional Woo/order currency.
 * @return string|WP_Error BGN|EUR
 */
function mtuc_resolve_transaction_currency( array $shop, ?string $currency = null ) {
	$iso = mtuc_get_woocommerce_transaction_currency( $currency );

	if ( ! mtuc_is_transaction_currency_compatible( $shop, $iso ) ) {
		return new WP_Error(
			'mtuc_currency_mismatch',
			__( 'Валутата на магазина не съвпада с конфигурацията за финансиране.', 'mtunicredit' )
		);
	}

	return $iso;
}

/**
 * Canonical financeable cart amount: complete payable WooCommerce cart total.
 *
 * Includes products, discounts/coupons, taxes, shipping and fees as represented
 * in WC()->cart->get_total( 'edit' ). Not merchandise-only.
 *
 * @return float
 */
function mtuc_get_canonical_financeable_cart_total(): float {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0.0;
	}

	$cart = WC()->cart;
	if ( method_exists( $cart, 'calculate_totals' ) ) {
		$cart->calculate_totals();
	}

	return round( (float) $cart->get_total( 'edit' ), 2 );
}

/**
 * Canonical financeable amount for an existing order (complete payable total).
 *
 * @param WC_Order $order WooCommerce order.
 * @return float
 */
function mtuc_get_canonical_financeable_order_total( WC_Order $order ): float {
	return round( (float) $order->get_total(), 2 );
}

/**
 * Resolve product/variation for financing and validate parent/variation relationship.
 *
 * @param int $product_id   Catalog/parent product ID from the request.
 * @param int $variation_id Variation ID (0 when not a variation).
 * @return array{product:WC_Product,parent_id:int,variation_id:int}|WP_Error
 */
function mtuc_resolve_financing_product( int $product_id, int $variation_id ) {
	$product_id   = max( 0, $product_id );
	$variation_id = max( 0, $variation_id );

	if ( $variation_id > 0 ) {
		$product = mtuc_get_wc_product_by_id( $variation_id );
		if ( ! $product instanceof WC_Product || ! $product->is_type( 'variation' ) ) {
			return new WP_Error(
				'mtuc_invalid_variation',
				__( 'Невалидна вариация на продукта.', 'mtunicredit' )
			);
		}

		$parent_id = (int) $product->get_parent_id();
		if ( $product_id > 0 && $parent_id !== $product_id ) {
			return new WP_Error(
				'mtuc_variation_parent_mismatch',
				__( 'Вариацията не принадлежи към избрания продукт.', 'mtunicredit' )
			);
		}

		return array(
			'product'      => $product,
			'parent_id'    => $parent_id > 0 ? $parent_id : $product_id,
			'variation_id' => $variation_id,
		);
	}

	if ( $product_id <= 0 ) {
		return new WP_Error(
			'mtuc_invalid_product',
			__( 'Невалиден продукт.', 'mtunicredit' )
		);
	}

	$product = mtuc_get_wc_product_by_id( $product_id );
	if ( ! $product instanceof WC_Product ) {
		return new WP_Error(
			'mtuc_invalid_product',
			__( 'Невалиден продукт.', 'mtunicredit' )
		);
	}

	if ( $product->is_type( 'variation' ) ) {
		return array(
			'product'      => $product,
			'parent_id'    => (int) $product->get_parent_id(),
			'variation_id' => $product->get_id(),
		);
	}

	if ( $product->is_type( 'variable' ) ) {
		return new WP_Error(
			'mtuc_missing_variation',
			__( 'Моля, изберете вариация на продукта.', 'mtunicredit' )
		);
	}

	return array(
		'product'      => $product,
		'parent_id'    => $product_id,
		'variation_id' => 0,
	);
}

/**
 * Validate a requested financing quantity with WooCommerce purchase rules.
 *
 * Rejects invalid quantities instead of silently normalizing them.
 *
 * @param WC_Product $product  Product or variation.
 * @param int        $quantity Requested quantity.
 * @return int|WP_Error Validated positive quantity.
 */
function mtuc_validate_financing_quantity( WC_Product $product, int $quantity ) {
	if ( $quantity <= 0 ) {
		return new WP_Error(
			'mtuc_invalid_quantity',
			__( 'Невалидно количество.', 'mtunicredit' )
		);
	}

	if ( ! $product->is_purchasable() ) {
		return new WP_Error(
			'mtuc_not_purchasable',
			__( 'Продуктът не може да бъде закупен.', 'mtunicredit' )
		);
	}

	if ( ! $product->is_in_stock() ) {
		return new WP_Error(
			'mtuc_out_of_stock',
			__( 'Продуктът не е наличен.', 'mtunicredit' )
		);
	}

	if ( $product->is_sold_individually() && $quantity > 1 ) {
		return new WP_Error(
			'mtuc_sold_individually',
			__( 'Този продукт може да бъде закупен само в количество 1.', 'mtunicredit' )
		);
	}

	$max = $product->get_max_purchase_quantity();
	if ( -1 !== (int) $max && $quantity > (int) $max ) {
		return new WP_Error(
			'mtuc_quantity_exceeds_max',
			__( 'Заявеното количество надвишава наличното.', 'mtunicredit' )
		);
	}

	if ( $product->managing_stock() && ! $product->backorders_allowed() && ! $product->has_enough_stock( $quantity ) ) {
		return new WP_Error(
			'mtuc_insufficient_stock',
			__( 'Заявеното количество надвишава наличното.', 'mtunicredit' )
		);
	}

	return $quantity;
}

/**
 * Authoritative tax-inclusive line total: Woo product price × validated quantity.
 *
 * Never trusts a browser-submitted line_price.
 *
 * @param WC_Product $product  Product or variation.
 * @param int        $quantity Validated quantity.
 * @return float|WP_Error
 */
function mtuc_get_authoritative_line_total( WC_Product $product, int $quantity ) {
	if ( $quantity <= 0 || ! function_exists( 'wc_get_price_including_tax' ) ) {
		return new WP_Error(
			'mtuc_price_unavailable',
			__( 'Не може да се определи цената на продукта.', 'mtunicredit' )
		);
	}

	$line_total = (float) wc_get_price_including_tax(
		$product,
		array( 'qty' => $quantity )
	);
	$line_total = round( $line_total, 2 );

	if ( $line_total <= 0 ) {
		return new WP_Error(
			'mtuc_price_unavailable',
			__( 'Не може да се определи цената на продукта.', 'mtunicredit' )
		);
	}

	return $line_total;
}

/**
 * Resolve authoritative product-popup financing line (product, qty, total).
 *
 * Client line_price is ignored for amount authority.
 *
 * @param int $product_id   Requested product ID.
 * @param int $variation_id Requested variation ID.
 * @param int $quantity     Requested quantity.
 * @return array{product:WC_Product,parent_id:int,variation_id:int,quantity:int,line_total:float,unit_price:float}|WP_Error
 */
function mtuc_resolve_authoritative_product_financing_line( int $product_id, int $variation_id, int $quantity ) {
	$resolved = mtuc_resolve_financing_product( $product_id, $variation_id );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	/** @var WC_Product $product */
	$product = $resolved['product'];

	$validated_qty = mtuc_validate_financing_quantity( $product, $quantity );
	if ( is_wp_error( $validated_qty ) ) {
		return $validated_qty;
	}

	$line_total = mtuc_get_authoritative_line_total( $product, (int) $validated_qty );
	if ( is_wp_error( $line_total ) ) {
		return $line_total;
	}

	$unit_price = round( (float) $line_total / (int) $validated_qty, 2 );

	return array(
		'product'      => $product,
		'parent_id'    => (int) $resolved['parent_id'],
		'variation_id' => (int) $resolved['variation_id'],
		'quantity'     => (int) $validated_qty,
		'line_total'   => (float) $line_total,
		'unit_price'   => $unit_price,
	);
}
