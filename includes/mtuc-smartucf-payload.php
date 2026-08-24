<?php
/**
 * Woo → SmartUCF session payload construction (AUD-WOO-016 Step 11).
 *
 * Given an authoritative Woo order and financing calculation snapshot,
 * builds the sucfOnlineSessionStart request body. Does not perform HTTP,
 * certificate loading, signing, redirect/session orchestration, or lifecycle mutation.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Strip characters that break SmartUCF legacy payloads.
 *
 * @param string $value Raw text.
 * @return string
 */
function mtuc_sanitize_smartucf_text( string $value ): string {
	return str_replace( array( "'", "'", '"' ), '', $value );
}

/**
 * Build SmartUCF delivery address from order or popup customer data.
 *
 * @param WC_Order              $order    WooCommerce order.
 * @param array<string, string> $customer Validated customer fields.
 * @return string
 */
function mtuc_get_smartucf_delivery_address( WC_Order $order, array $customer ): string {
	$parts = array_filter(
		array(
			$order->get_shipping_address_1(),
			$order->get_shipping_address_2(),
			$order->get_shipping_city(),
			$order->get_shipping_postcode(),
		),
		static function ( $part ) {
			return '' !== trim( (string) $part );
		}
	);

	if ( ! empty( $parts ) ) {
		return mtuc_sanitize_smartucf_text( implode( ', ', $parts ) );
	}

	return mtuc_sanitize_smartucf_text( (string) ( $customer['address'] ?? '' ) );
}

/**
 * Build SmartUCF items array from all order line items.
 *
 * @param WC_Order $order WooCommerce order.
 * @return array<int, array<string, mixed>>
 */
function mtuc_build_smartucf_items_from_order( WC_Order $order ): array {
	$items = array();

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}

		$product = $item->get_product();
		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$quantity   = max( 1, (int) $item->get_quantity() );
		$unit_price = mtuc_get_order_item_unit_price_inc_tax( $item );

		$variation_id = mtuc_get_product_variation_id( $product );
		$parent_id    = mtuc_get_catalog_product_id( $product );

		$category_ids     = mtuc_get_product_category_ids( $product );
		$product_category = ! empty( $category_ids ) ? (int) $category_ids[0] : 0;
		$item_code        = $variation_id > 0 ? $variation_id : $parent_id;

		$items[] = array(
			'name'        => mtuc_sanitize_smartucf_text( $product->get_name() ),
			'code'        => $item_code,
			'type'        => $product_category,
			'count'       => $quantity,
			'singlePrice' => $unit_price,
		);
	}

	return $items;
}

/**
 * Assemble shared SmartUCF session-start fields.
 *
 * @param WC_Order              $order       WooCommerce order.
 * @param array<string, string> $customer    Validated customer fields.
 * @param array<string, mixed>  $calculation Server-side calculation snapshot.
 * @param array<string, mixed>  $shop        Shop data.
 * @return array<string, mixed>
 */
function mtuc_assemble_smartucf_session_payload(
	WC_Order $order,
	array $customer,
	array $calculation,
	array $shop
): array {
	return array(
		'user'                  => (string) ( $shop['uni_user'] ?? '' ),
		'pass'                  => (string) ( $shop['uni_password'] ?? '' ),
		'orderNo'               => (string) $order->get_id(),
		'clientFirstName'       => mtuc_sanitize_smartucf_text( (string) ( $customer['first_name'] ?? '' ) ),
		'clientLastName'        => mtuc_sanitize_smartucf_text( (string) ( $customer['last_name'] ?? '' ) ),
		'clientPhone'           => mtuc_sanitize_smartucf_text( (string) ( $customer['phone'] ?? '' ) ),
		'clientEmail'           => mtuc_sanitize_smartucf_text( (string) ( $customer['email'] ?? '' ) ),
		'clientDeliveryAddress' => mtuc_get_smartucf_delivery_address( $order, $customer ),
		'onlineProductCode'     => (string) ( $calculation['kop_code'] ?? '' ),
		'totalPrice'            => mtuc_get_order_total_inc_tax( $order ),
		'initialPayment'        => isset( $calculation['parva'] ) ? (float) $calculation['parva'] : 0.0,
		'installmentCount'      => isset( $calculation['months'] ) ? (int) $calculation['months'] : 0,
		'monthlyPayment'        => isset( $calculation['monthly_installment'] ) ? (float) $calculation['monthly_installment'] : 0.0,
		'items'                 => mtuc_build_smartucf_items_from_order( $order ),
	);
}

/**
 * Build SmartUCF session payload for cart popup / checkout order.
 *
 * @param WC_Order              $order       WooCommerce order.
 * @param array<string, string> $customer    Validated customer fields.
 * @param array<string, mixed>  $calculation Server-side calculation snapshot.
 * @param array<string, mixed>  $shop        Shop data.
 * @return array<string, mixed>
 */
function mtuc_build_cart_smartucf_session_payload(
	WC_Order $order,
	array $customer,
	array $calculation,
	array $shop
): array {
	return mtuc_assemble_smartucf_session_payload( $order, $customer, $calculation, $shop );
}

/**
 * Build SmartUCF sucfOnlineSessionStart payload (product-popup entry point).
 *
 * Product/line arguments are retained for call-site compatibility; items are
 * always derived from the authoritative Woo order lines.
 *
 * @param WC_Order              $order        WooCommerce order.
 * @param array<string, string> $customer     Validated customer fields.
 * @param array<string, mixed>  $calculation  Server-side calculation snapshot.
 * @param WC_Product            $product      Product or variation line item.
 * @param int                   $parent_id    Parent product ID.
 * @param int                   $variation_id Variation ID (0 if none).
 * @param int                   $quantity     Line quantity.
 * @param array<string, mixed>  $shop         Shop `data` object from CP.
 * @return array<string, mixed>
 */
function mtuc_build_smartucf_session_payload(
	WC_Order $order,
	array $customer,
	array $calculation,
	WC_Product $product,
	int $parent_id,
	int $variation_id,
	int $quantity,
	array $shop
): array {
	unset( $product, $parent_id, $variation_id, $quantity );

	return mtuc_assemble_smartucf_session_payload( $order, $customer, $calculation, $shop );
}
