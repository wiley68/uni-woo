<?php
/**
 * Strict inbound order_id → WooCommerce order resolution (AUD-WOO-019-F05).
 *
 * A signed Control Panel callback proves "some CP shop spoke to us", never
 * "this order belongs to that shop". Resolution therefore requires, together:
 *
 *   1. a canonical decimal order_id,
 *   2. the current site identity,
 *   3. the authenticated UNICID,
 *   4. UniPayment financing ownership on the order,
 *   5. exactly one durable metadata match (cardinality 1).
 *
 * Any other outcome — absent, ambiguous, foreign, legacy-without-ownership —
 * fails closed behind one opaque rejection that discloses nothing.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Order meta: immutable UNICID that owns this financing order. */
const MTUC_ORDER_META_FINANCING_UNICID = '_mtuc_financing_unicid';

/** Order meta: immutable site identity that minted this financing order. */
const MTUC_ORDER_META_FINANCING_SITE = '_mtuc_financing_site';

/** Canonical inbound order_id: positive decimal, no leading zeros, max 13 digits. */
const MTUC_FINANCING_ORDER_ID_PATTERN = '/\A[1-9][0-9]{0,12}\z/';

/**
 * Whether a raw inbound value is a canonical financing order_id.
 *
 * Strings only: no int/float/bool coercion, no whitespace tolerance, no
 * truncation. The pattern itself bounds the length at 13 digits.
 *
 * @param mixed $value Raw inbound value.
 * @return bool
 */
function mtuc_is_canonical_financing_order_id( $value ): bool {
	return is_string( $value ) && 1 === preg_match( MTUC_FINANCING_ORDER_ID_PATTERN, $value );
}

/**
 * Stable identity of this shop installation for ownership binding.
 *
 * @return string
 */
function mtuc_get_financing_site_identity(): string {
	if ( ! function_exists( 'home_url' ) ) {
		return '';
	}

	return untrailingslashit( (string) home_url() );
}

/**
 * Currently configured shop UNICID.
 *
 * @return string
 */
function mtuc_get_configured_financing_unicid(): string {
	if ( ! class_exists( 'Mtuc_Settings' ) ) {
		return '';
	}

	return trim( (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID ) );
}

/**
 * Opaque inbound rejection — identical for absent, ambiguous and foreign orders.
 *
 * @param string $reason Internal reason (never disclosed to the caller).
 * @return WP_Error
 */
function mtuc_financing_order_not_found_error( string $reason = '' ): WP_Error {
	return new WP_Error(
		'mtuc_financing_order_not_found',
		__( 'Поръчката не е намерена в магазина.', 'mtunicredit' ),
		array(
			'status'          => 404,
			'internal_reason' => sanitize_key( $reason ),
		)
	);
}

/**
 * Persist immutable financing ownership on a freshly assigned order.
 *
 * Ownership is written once. A later attempt to rebind the order to a different
 * UNICID or site is a conflict, not an update.
 *
 * @param WC_Order    $order  Order instance.
 * @param string|null $unicid Owning UNICID (defaults to configured settings).
 * @return true|WP_Error
 */
function mtuc_persist_financing_order_ownership( WC_Order $order, ?string $unicid = null ) {
	$unicid = null === $unicid ? mtuc_get_configured_financing_unicid() : trim( $unicid );
	$site   = mtuc_get_financing_site_identity();

	if ( '' === $unicid || '' === $site ) {
		return new WP_Error(
			'mtuc_financing_ownership_unavailable',
			__( 'Липсва идентичност на магазина за записване на собственост върху поръчката.', 'mtunicredit' )
		);
	}

	$existing_unicid = (string) $order->get_meta( MTUC_ORDER_META_FINANCING_UNICID );
	$existing_site   = (string) $order->get_meta( MTUC_ORDER_META_FINANCING_SITE );

	if ( '' !== $existing_unicid && $existing_unicid !== $unicid ) {
		return new WP_Error(
			'mtuc_financing_ownership_conflict',
			__( 'Поръчката вече е обвързана с друг магазин (unicid).', 'mtunicredit' )
		);
	}

	if ( '' !== $existing_site && $existing_site !== $site ) {
		return new WP_Error(
			'mtuc_financing_ownership_conflict',
			__( 'Поръчката вече е обвързана с друга инсталация.', 'mtunicredit' )
		);
	}

	if ( '' === $existing_unicid ) {
		$order->update_meta_data( MTUC_ORDER_META_FINANCING_UNICID, $unicid );
	}

	if ( '' === $existing_site ) {
		$order->update_meta_data( MTUC_ORDER_META_FINANCING_SITE, $site );
	}

	return true;
}

/**
 * Whether an order carries ownership matching this site and the given UNICID.
 *
 * Legacy orders without ownership meta fail closed: absence is not consent.
 *
 * @param WC_Order $order  Order instance.
 * @param string   $unicid Authenticated UNICID.
 * @return bool
 */
function mtuc_order_has_financing_ownership( WC_Order $order, string $unicid ): bool {
	$owner_unicid = (string) $order->get_meta( MTUC_ORDER_META_FINANCING_UNICID );
	$owner_site   = (string) $order->get_meta( MTUC_ORDER_META_FINANCING_SITE );

	if ( '' === $owner_unicid || '' === $owner_site ) {
		return false;
	}

	return hash_equals( $owner_unicid, $unicid )
		&& hash_equals( $owner_site, mtuc_get_financing_site_identity() );
}

/**
 * Every order carrying the exact durable CP order_id metadata value.
 *
 * Deliberately unbounded (no `limit => 1`): cardinality is evidence, and a
 * truncated query would silently hide a duplicate-binding integrity failure.
 *
 * @param string $cp_order_id Canonical CP order_id.
 * @return array<int, WC_Order>
 */
function mtuc_query_orders_by_cp_order_id( string $cp_order_id ): array {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return array();
	}

	$found = wc_get_orders(
		array(
			'limit'      => -1,
			'meta_key'   => MTUC_ORDER_META_CP_SHOP_ORDER_ID,
			'meta_value' => $cp_order_id,
			'return'     => 'objects',
		)
	);

	if ( ! is_array( $found ) ) {
		return array();
	}

	$matches = array();
	foreach ( $found as $order ) {
		if ( ! $order instanceof WC_Order ) {
			continue;
		}

		// Exact durable value only — never a prefix, truncation or display number.
		if ( (string) $order->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID ) !== $cp_order_id ) {
			continue;
		}

		$matches[] = $order;
	}

	return $matches;
}

/**
 * Resolve an authenticated inbound order_id to an owned financing order.
 *
 * @param mixed       $raw_order_id Raw inbound order_id (must already be a string).
 * @param string|null $unicid       Authenticated UNICID (defaults to configured settings).
 * @return WC_Order|WP_Error
 */
function mtuc_resolve_financing_order( $raw_order_id, ?string $unicid = null ) {
	if ( ! mtuc_is_canonical_financing_order_id( $raw_order_id ) ) {
		return mtuc_financing_order_not_found_error( 'order_id_noncanonical' );
	}

	$configured = mtuc_get_configured_financing_unicid();
	$unicid     = null === $unicid ? $configured : trim( $unicid );

	if ( '' === $unicid || '' === $configured || ! hash_equals( $configured, $unicid ) ) {
		return mtuc_financing_order_not_found_error( 'unicid_not_authenticated' );
	}

	if ( '' === mtuc_get_financing_site_identity() ) {
		return mtuc_financing_order_not_found_error( 'site_identity_unavailable' );
	}

	$matches = mtuc_query_orders_by_cp_order_id( (string) $raw_order_id );
	$count   = count( $matches );

	if ( 0 === $count ) {
		return mtuc_financing_order_not_found_error( 'absent' );
	}

	if ( $count > 1 ) {
		// Duplicate durable bindings are an integrity failure, never a "pick one".
		return mtuc_financing_order_not_found_error( 'ambiguous_cardinality' );
	}

	$order = $matches[0];

	if ( ! defined( 'MTUC_PAYMENT_GATEWAY_ID' )
		|| MTUC_PAYMENT_GATEWAY_ID !== $order->get_payment_method()
	) {
		return mtuc_financing_order_not_found_error( 'not_unipayment_financing' );
	}

	if ( ! mtuc_order_has_financing_ownership( $order, $unicid ) ) {
		return mtuc_financing_order_not_found_error( 'ownership_missing_or_foreign' );
	}

	return $order;
}
