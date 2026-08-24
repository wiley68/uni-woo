<?php
/**
 * Woo → Bank/CP order create payload construction (AUD-WOO-016 Step 10).
 *
 * Given an authoritative Woo order and already-resolved financing context,
 * builds the POST /orders request body expected by Control Panel.
 * Does not perform HTTP, lifecycle mutation, SmartUCF, email, or stock/cart side effects.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve CP address fields: popup billing → address, shipping → address2.
 *
 * @param WC_Order              $order    Order with billing/shipping set (unused, kept for signature).
 * @param array<string, string> $customer Validated popup customer fields.
 * @return array{address: string, address2: string}
 */
function mtuc_resolve_cp_order_addresses( WC_Order $order, array $customer ): array {
	unset( $order );

	$address  = mtuc_join_address_parts( array( (string) $customer['address'] ) );
	$address2 = mtuc_get_popup_shipping_address_for_cp();

	if ( '' === $address2 ) {
		$address2 = $address;
	}

	// КП DB: address2 е NOT NULL; Laravel конвертира празен string в null.
	if ( '' === $address2 ) {
		$address2 = '-';
	}

	return array(
		'address'  => $address,
		'address2' => $address2,
	);
}

/**
 * Sanitize product name for CP products_name (underscore is the multi-item delimiter).
 *
 * @param string $name Product name.
 * @return string
 */
function mtuc_sanitize_cp_product_name( string $name ): string {
	return str_replace( '_', '-', $name );
}

/**
 * Join CP multi-value product fields with underscore delimiter.
 *
 * @param array<int, int|string> $values Parallel values per order line.
 * @return string
 */
function mtuc_join_cp_product_values( array $values ): string {
	if ( empty( $values ) ) {
		return '';
	}

	if ( 1 === count( $values ) ) {
		return (string) $values[0];
	}

	return implode( '_', array_map( 'strval', $values ) );
}

/**
 * Build CP products_id, products_name and products_q from parallel line arrays.
 *
 * @param array<int, int>    $product_ids   Product or variation IDs per line.
 * @param array<int, string> $product_names Product names per line.
 * @param array<int, int>    $quantities    Quantities per line.
 * @return array{products_id: string, products_name: string, products_q: string}
 */
function mtuc_build_cp_products_fields( array $product_ids, array $product_names, array $quantities ): array {
	$names = array_map( 'mtuc_sanitize_cp_product_name', $product_names );

	$products_name = mtuc_join_cp_product_values( $names );
	if ( strlen( $products_name ) > 255 ) {
		$products_name = substr( $products_name, 0, 252 ) . '...';
	}

	return array(
		'products_id'   => mtuc_join_cp_product_values( $product_ids ),
		'products_name' => $products_name,
		'products_q'    => mtuc_join_cp_product_values( $quantities ),
	);
}

/**
 * Resolve CP order currency code — must match Woo transaction currency.
 *
 * Requires compatibility between WooCommerce currency and shop `uni_eur` mode.
 * Dual-display modes (1/2) do not change the submitted transaction currency.
 *
 * @param array<string, mixed> $shop        Shop `data` object from CP.
 * @param string|null          $wc_currency Optional Woo/order currency override.
 * @return string BGN|EUR
 */
function mtuc_get_cp_order_currency( array $shop, ?string $wc_currency = null ): string {
	$resolved = mtuc_resolve_transaction_currency( $shop, $wc_currency );
	if ( is_wp_error( $resolved ) ) {
		// Callers must gate availability; fall back to expected bank currency for typing only.
		return mtuc_get_expected_transaction_currency( $shop );
	}

	return $resolved;
}

/**
 * CP type_client: 0 = mobile, 1 = desktop/PC.
 *
 * @return int 0|1
 */
function mtuc_get_cp_type_client(): int {
	if ( function_exists( 'wp_is_mobile' ) && wp_is_mobile() ) {
		return 0;
	}

	return 1;
}

/**
 * Plugin version for CP orders API (vc[11]).
 *
 * @return string|null
 */
function mtuc_get_cp_order_version(): ?string {
	if ( ! defined( 'MTUC_VERSION' ) ) {
		return null;
	}

	$version = trim( (string) MTUC_VERSION );

	return '' !== $version ? $version : null;
}

/**
 * Assemble shared CP StoreOrderRequest fields (product/cart entry points supply products_*).
 *
 * @param WC_Order                                              $order       WooCommerce order.
 * @param array<string, string>                                 $customer    Validated customer fields.
 * @param array<string, mixed>                                  $calculation Server-side calculation snapshot.
 * @param array<string, mixed>                                  $shop        Shop data.
 * @param array{products_id: string, products_name: string, products_q: string} $cp_products Products fields.
 * @return array<string, mixed>
 */
function mtuc_assemble_cp_order_payload(
	WC_Order $order,
	array $customer,
	array $calculation,
	array $shop,
	array $cp_products
): array {
	$order_number = mtuc_get_cp_shop_order_id( $order );

	$full_name = trim( $customer['first_name'] . ' ' . $customer['last_name'] );
	if ( strlen( $full_name ) > 65 ) {
		$full_name = substr( $full_name, 0, 65 );
	}

	$phone = (string) $customer['phone'];
	if ( strlen( $phone ) > 45 ) {
		$phone = substr( $phone, 0, 45 );
	}

	$email = (string) $customer['email'];
	if ( strlen( $email ) > 128 ) {
		$email = substr( $email, 0, 128 );
	}

	$cp_addresses = mtuc_resolve_cp_order_addresses( $order, $customer );
	$cp_status    = mtuc_get_cp_order_create_status_payload( $shop );

	$payload = array(
		'order_id'      => $order_number,
		'name'          => $full_name,
		'phone'         => $phone,
		'email'         => $email,
		'address'       => $cp_addresses['address'],
		'address2'      => $cp_addresses['address2'],
		'price'         => mtuc_get_order_total_inc_tax( $order ),
		'vnoska'        => round( (float) ( $calculation['monthly_installment'] ?? 0 ), 2 ),
		'gpr'           => round( (float) ( $calculation['gpr'] ?? 0 ), 2 ),
		'vnoski'        => (int) ( $calculation['months'] ?? 0 ),
		'parva'         => round( (float) ( $calculation['parva'] ?? 0 ), 2 ),
		'products_id'   => $cp_products['products_id'],
		'products_name' => $cp_products['products_name'],
		'products_q'    => $cp_products['products_q'],
		'type_client'   => mtuc_get_cp_type_client(),
		'currency'      => mtuc_get_cp_order_currency( $shop, $order->get_currency() ),
		'version'       => mtuc_get_cp_order_version(),
	);

	// Process 1: omit status until SmartUCF success (AUD-WOO-008). Process 2: include bank_sent_process2.
	if ( null !== $cp_status ) {
		$payload['status']    = $cp_status['status'];
		$payload['status_id'] = $cp_status['status_id'];
	}

	return $payload;
}

/**
 * Build CP order payload for cart popup / checkout (multiple products summary).
 *
 * @param WC_Order              $order       WooCommerce order.
 * @param array<string, string> $customer    Validated customer fields.
 * @param array<string, mixed>  $calculation Server-side calculation snapshot.
 * @param array<string, mixed>  $shop        Shop data.
 * @return array<string, mixed>
 */
function mtuc_build_cp_cart_order_payload(
	WC_Order $order,
	array $customer,
	array $calculation,
	array $shop
): array {
	$product_ids        = array();
	$product_names      = array();
	$product_quantities = array();

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}

		$product = $item->get_product();
		if ( ! $product instanceof WC_Product ) {
			continue;
		}

		$variation_id         = mtuc_get_product_variation_id( $product );
		$parent_id            = mtuc_get_catalog_product_id( $product );
		$product_ids[]        = $variation_id > 0 ? $variation_id : $parent_id;
		$product_names[]      = $product->get_name();
		$product_quantities[] = max( 1, (int) $item->get_quantity() );
	}

	$cp_products = mtuc_build_cp_products_fields( $product_ids, $product_names, $product_quantities );

	return mtuc_assemble_cp_order_payload( $order, $customer, $calculation, $shop, $cp_products );
}

/**
 * Build CP StoreOrderRequest payload from product-popup order data.
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
function mtuc_build_cp_order_payload(
	WC_Order $order,
	array $customer,
	array $calculation,
	WC_Product $product,
	int $parent_id,
	int $variation_id,
	int $quantity,
	array $shop
): array {
	$product_id_for_cp = $variation_id > 0 ? $variation_id : $parent_id;
	$cp_products       = mtuc_build_cp_products_fields(
		array( $product_id_for_cp ),
		array( $product->get_name() ),
		array( max( 1, $quantity ) )
	);

	return mtuc_assemble_cp_order_payload( $order, $customer, $calculation, $shop, $cp_products );
}
