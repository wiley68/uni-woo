<?php
/**
 * Popup financing idempotency and external CP order identity (AUD-WOO-006/007).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** wp_options prefix for atomic operation reservations. */
const MTUC_FINANCING_OPERATION_OPTION_PREFIX = 'mtuc_fop_';

/** Poll attempts while waiting for concurrent operation winner. */
const MTUC_FINANCING_OPERATION_POLL_ATTEMPTS = 15;

/**
 * Unresolved operation-claim TTL (seconds).
 *
 * Covers only reservations still at wc_order_id=0. Resolved bindings are kept
 * for same-token idempotency and are never expired by this TTL.
 * Value matches submission-lock lifetime so a live create/commit worker is not
 * treated as abandoned while still executing local order work.
 */
const MTUC_FINANCING_OPERATION_CLAIM_TTL = 300;

/**
 * Normalize and validate a client operation token.
 *
 * @param string $token Raw token.
 * @return string|WP_Error
 */
function mtuc_normalize_operation_token( string $token ) {
	$token = strtolower( trim( $token ) );
	if ( '' === $token ) {
		return new WP_Error(
			'mtuc_missing_operation_token',
			__( 'Липсва идентификатор на заявката за финансиране.', 'mtunicredit' )
		);
	}

	if ( preg_match( '/^[a-f0-9]{32,64}$/', $token ) ) {
		return $token;
	}

	if ( preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $token ) ) {
		return $token;
	}

	return new WP_Error(
		'mtuc_invalid_operation_token',
		__( 'Невалиден идентификатор на заявката за финансиране.', 'mtunicredit' )
	);
}

/**
 * Read operation token from popup AJAX POST.
 *
 * @return string|WP_Error
 */
function mtuc_get_submitted_operation_token() {
	$raw = isset( $_POST['operation_token'] )
		? sanitize_text_field( wp_unslash( (string) $_POST['operation_token'] ) )
		: '';

	return mtuc_normalize_operation_token( $raw );
}

/**
 * Build durable scope key for product popup submissions.
 *
 * @param int $product_id   Parent product ID.
 * @param int $variation_id Variation ID.
 * @return string
 */
function mtuc_build_product_operation_scope_key( int $product_id, int $variation_id ): string {
	return hash(
		'sha256',
		implode(
			'|',
			array(
				'product',
				mtuc_get_wc_session_customer_id(),
				(string) get_current_user_id(),
				(string) max( 0, $product_id ),
				(string) max( 0, $variation_id ),
			)
		)
	);
}

/**
 * Build durable scope key for cart popup submissions.
 *
 * @return string
 */
function mtuc_build_cart_operation_scope_key(): string {
	$cart_hash = '';
	if ( function_exists( 'WC' ) ) {
		$wc = WC();
		if ( is_object( $wc ) && $wc->cart instanceof WC_Cart ) {
			$cart_hash = (string) $wc->cart->get_cart_hash();
		}
	}

	return hash(
		'sha256',
		implode(
			'|',
			array(
				'cart',
				mtuc_get_wc_session_customer_id(),
				(string) get_current_user_id(),
				$cart_hash,
			)
		)
	);
}

/**
 * Find Woo order by durable operation token (HPOS-compatible meta query).
 *
 * @param string $token Operation token.
 * @return WC_Order|null
 */
function mtuc_find_order_by_operation_token( string $token ): ?WC_Order {
	$token = strtolower( trim( $token ) );
	if ( '' === $token || ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}

	$orders = wc_get_orders(
		array(
			'limit'      => 1,
			'meta_key'   => MTUC_ORDER_META_OPERATION_TOKEN,
			'meta_value' => $token,
			'return'     => 'objects',
		)
	);

	if ( ! is_array( $orders ) || empty( $orders[0] ) || ! $orders[0] instanceof WC_Order ) {
		return null;
	}

	return $orders[0];
}

/**
 * Option key for atomic operation reservation.
 *
 * @param string $token Operation token.
 * @return string
 */
function mtuc_financing_operation_option_key( string $token ): string {
	return MTUC_FINANCING_OPERATION_OPTION_PREFIX . hash( 'sha256', $token );
}

/**
 * Decode a financing operation reservation from wp_options.
 *
 * @param string $option_key Option key.
 * @return array{token:string,scope:string,wc_order_id:int,created_at:int}|null
 */
function mtuc_read_financing_operation_reservation( string $option_key ): ?array {
	$raw = get_option( $option_key, '' );
	if ( ! is_string( $raw ) || '' === $raw ) {
		return null;
	}

	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return null;
	}

	return array(
		'token'        => isset( $data['token'] ) ? (string) $data['token'] : '',
		'scope'        => isset( $data['scope'] ) ? (string) $data['scope'] : '',
		'wc_order_id'  => isset( $data['wc_order_id'] ) ? (int) $data['wc_order_id'] : 0,
		'created_at'   => isset( $data['created_at'] ) ? (int) $data['created_at'] : 0,
		'creation_ref' => isset( $data['creation_ref'] ) ? (string) $data['creation_ref'] : '',
	);
}

/**
 * Whether an unresolved operation reservation may be reclaimed.
 *
 * Resolved reservations (wc_order_id > 0) are never stale for reclaim purposes.
 *
 * @param array{token:string,scope:string,wc_order_id:int,created_at:int} $stored Reservation.
 * @param int                                                              $now    Unix now.
 * @return bool
 */
function mtuc_financing_operation_is_unresolved_stale( array $stored, int $now = 0 ): bool {
	if ( (int) $stored['wc_order_id'] > 0 ) {
		return false;
	}

	if ( $now <= 0 ) {
		$now = time();
	}

	$created_at = (int) $stored['created_at'];
	if ( $created_at <= 0 ) {
		return true;
	}

	return ( $now - $created_at ) >= MTUC_FINANCING_OPERATION_CLAIM_TTL;
}

/**
 * Persist reservation payload.
 *
 * @param string $option_key  Option key.
 * @param string $token       Operation token.
 * @param string $scope_key   Scope key.
 * @param int    $wc_order_id Woo order ID (0 while creating).
 * @param int    $created_at  Original claim time.
 * @return bool
 */
function mtuc_write_financing_operation_reservation(
	string $option_key,
	string $token,
	string $scope_key,
	int $wc_order_id,
	int $created_at,
	string $creation_ref = ''
): bool {
	if ( '' === $creation_ref ) {
		$stored = mtuc_read_financing_operation_reservation( $option_key );
		if ( null !== $stored && '' !== (string) ( $stored['creation_ref'] ?? '' ) ) {
			$creation_ref = (string) $stored['creation_ref'];
		} else {
			$creation_ref = mtuc_financing_creation_ref( $token );
		}
	}

	$encoded = wp_json_encode(
		array(
			'token'        => $token,
			'scope'        => $scope_key,
			'wc_order_id'  => $wc_order_id,
			'created_at'   => $created_at > 0 ? $created_at : time(),
			'creation_ref' => $creation_ref,
		)
	);

	if ( ! is_string( $encoded ) || '' === $encoded ) {
		return false;
	}

	return update_option( $option_key, $encoded, false );
}

/**
 * Attempt to reclaim an abandoned unresolved reservation (AUD-WOO-018-F03).
 *
 * Uses compare-and-set against the inspected option_value so only one reclaimer wins.
 * Does not open a new create window when a Woo order for the token is discoverable.
 *
 * @param string $option_key Option key.
 * @param string $token      Operation token.
 * @param string $scope_key  Scope key.
 * @return bool True when this worker became the new claim owner.
 */
function mtuc_try_reclaim_stale_financing_operation( string $option_key, string $token, string $scope_key ): bool {
	$raw = mtuc_get_option_raw_value( $option_key );
	if ( null === $raw ) {
		return false;
	}

	$stored = json_decode( $raw, true );
	if ( ! is_array( $stored ) ) {
		return false;
	}

	$reservation = array(
		'token'        => isset( $stored['token'] ) ? (string) $stored['token'] : '',
		'scope'        => isset( $stored['scope'] ) ? (string) $stored['scope'] : '',
		'wc_order_id'  => isset( $stored['wc_order_id'] ) ? (int) $stored['wc_order_id'] : 0,
		'created_at'   => isset( $stored['created_at'] ) ? (int) $stored['created_at'] : 0,
		'creation_ref' => isset( $stored['creation_ref'] ) ? (string) $stored['creation_ref'] : '',
	);

	if ( ! mtuc_financing_operation_is_unresolved_stale( $reservation ) ) {
		return false;
	}

	if ( '' !== $reservation['scope'] && $reservation['scope'] !== $scope_key ) {
		return false;
	}

	$existing = mtuc_find_order_for_financing_operation( $token, $reservation['creation_ref'] );
	if ( $existing instanceof WC_Order ) {
		$bound = wp_json_encode(
			array(
				'token'        => $token,
				'scope'        => $scope_key,
				'wc_order_id'  => (int) $existing->get_id(),
				'created_at'   => (int) $reservation['created_at'],
				'creation_ref' => '' !== $reservation['creation_ref']
					? $reservation['creation_ref']
					: mtuc_financing_creation_ref( $token ),
			)
		);
		if ( is_string( $bound ) && '' !== $bound ) {
			mtuc_options_cas_update( $option_key, $raw, $bound );
		}

		return false;
	}

	if ( (int) $reservation['wc_order_id'] > 0 && function_exists( 'wc_get_order' ) ) {
		$by_id = wc_get_order( (int) $reservation['wc_order_id'] );
		if ( $by_id instanceof WC_Order ) {
			return false;
		}
	}

	/*
	 * creation_ref was persisted before wc_create_order: if present, an order may
	 * exist without token meta. Refuse reclaim so we never mint Y beside orphan X.
	 */
	if ( '' !== $reservation['creation_ref'] ) {
		return false;
	}

	$replacement = wp_json_encode(
		array(
			'token'        => $token,
			'scope'        => $scope_key,
			'wc_order_id'  => 0,
			'created_at'   => time(),
			'creation_ref' => '',
		)
	);

	if ( ! is_string( $replacement ) || '' === $replacement ) {
		return false;
	}

	return mtuc_options_cas_update( $option_key, $raw, $replacement );
}

/**
 * Deterministic creation reference for one logical operation token.
 *
 * @param string $token Operation token.
 * @return string
 */
function mtuc_financing_creation_ref( string $token ): string {
	return hash( 'sha256', 'mtuc_cr|' . strtolower( trim( $token ) ) );
}

/**
 * created_via marker written in the same wc_create_order() save as the order row.
 *
 * @param string $creation_ref Creation reference.
 * @return string
 */
function mtuc_financing_created_via_marker( string $creation_ref ): string {
	return 'mtuc:' . substr( $creation_ref, 0, 24 );
}

/**
 * Find a Woo order for an operation via token meta or creation_ref / created_via marker.
 *
 * @param string $token        Operation token.
 * @param string $creation_ref Optional creation reference.
 * @return WC_Order|null
 */
function mtuc_find_order_for_financing_operation( string $token, string $creation_ref = '' ): ?WC_Order {
	$by_token = mtuc_find_order_by_operation_token( $token );
	if ( $by_token instanceof WC_Order ) {
		return $by_token;
	}

	if ( '' === $creation_ref ) {
		$creation_ref = mtuc_financing_creation_ref( $token );
	}

	$by_ref = mtuc_find_order_by_creation_ref( $creation_ref );
	if ( $by_ref instanceof WC_Order ) {
		return $by_ref;
	}

	return mtuc_find_order_by_created_via_marker( $creation_ref );
}

/**
 * Find order by durable creation_ref meta.
 *
 * @param string $creation_ref Creation reference.
 * @return WC_Order|null
 */
function mtuc_find_order_by_creation_ref( string $creation_ref ): ?WC_Order {
	$creation_ref = strtolower( trim( $creation_ref ) );
	if ( '' === $creation_ref || ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}

	$orders = wc_get_orders(
		array(
			'limit'      => 1,
			'meta_key'   => MTUC_ORDER_META_CREATION_REF,
			'meta_value' => $creation_ref,
			'return'     => 'objects',
		)
	);

	if ( ! is_array( $orders ) || empty( $orders[0] ) || ! $orders[0] instanceof WC_Order ) {
		return null;
	}

	return $orders[0];
}

/**
 * Find order by created_via bind marker (same-row identity during create).
 *
 * @param string $creation_ref Creation reference.
 * @return WC_Order|null
 */
function mtuc_find_order_by_created_via_marker( string $creation_ref ): ?WC_Order {
	if ( '' === $creation_ref || ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}

	$marker = mtuc_financing_created_via_marker( $creation_ref );
	$orders = wc_get_orders(
		array(
			'limit'       => 1,
			'created_via' => $marker,
			'return'      => 'objects',
		)
	);

	if ( is_array( $orders ) && ! empty( $orders[0] ) && $orders[0] instanceof WC_Order
		&& (string) $orders[0]->get_created_via() === $marker
	) {
		return $orders[0];
	}

	/*
	 * Some WC/query stubs ignore created_via. Fall back to a short recent scan and
	 * require an exact created_via match so unrelated orders are never claimed.
	 */
	$recent = wc_get_orders(
		array(
			'limit'   => 30,
			'orderby' => 'ID',
			'order'   => 'DESC',
			'return'  => 'objects',
		)
	);
	if ( ! is_array( $recent ) ) {
		return null;
	}

	foreach ( $recent as $order ) {
		if ( $order instanceof WC_Order && (string) $order->get_created_via() === $marker ) {
			return $order;
		}
	}

	return null;
}

/**
 * Persist creation_ref on the reservation before wc_create_order() (fail-closed).
 *
 * @param string $option_key   Reservation option key.
 * @param string $token        Operation token.
 * @param string $scope_key    Scope key.
 * @param string $creation_ref Creation reference.
 * @return true|WP_Error
 */
function mtuc_persist_financing_creation_intent(
	string $option_key,
	string $token,
	string $scope_key,
	string $creation_ref
) {
	if ( '' === $creation_ref ) {
		return new WP_Error(
			'mtuc_creation_intent_failed',
			__( 'Вътрешна грешка при резервиране на създаването.', 'mtunicredit' )
		);
	}

	$created_at  = time();
	$wc_order_id = 0;
	$raw_before  = mtuc_get_option_raw_value( $option_key );
	$stored      = null !== $raw_before ? json_decode( $raw_before, true ) : null;
	if ( is_array( $stored ) ) {
		if ( isset( $stored['created_at'] ) && (int) $stored['created_at'] > 0 ) {
			$created_at = (int) $stored['created_at'];
		}
		$wc_order_id = isset( $stored['wc_order_id'] ) ? (int) $stored['wc_order_id'] : 0;
	}

	$encoded = wp_json_encode(
		array(
			'token'        => $token,
			'scope'        => $scope_key,
			'wc_order_id'  => $wc_order_id,
			'created_at'   => $created_at,
			'creation_ref' => $creation_ref,
		)
	);
	if ( ! is_string( $encoded ) || '' === $encoded ) {
		return new WP_Error(
			'mtuc_creation_intent_failed',
			__( 'Вътрешна грешка при резервиране на създаването.', 'mtunicredit' )
		);
	}

	$wrote = false;
	if ( null !== $raw_before && '' !== $raw_before ) {
		$wrote = mtuc_options_cas_update( $option_key, $raw_before, $encoded );
		if ( ! $wrote ) {
			/*
			 * Concurrent replacement: re-read; if our creation_ref is already present
			 * for this token, treat as durable success.
			 */
			$verify_early = mtuc_read_financing_operation_reservation( $option_key );
			if ( null !== $verify_early
				&& hash_equals( (string) ( $verify_early['creation_ref'] ?? '' ), $creation_ref )
				&& hash_equals( (string) ( $verify_early['token'] ?? '' ), $token )
			) {
				$wrote = true;
			}
		}
	} else {
		$wrote = (bool) add_option( $option_key, $encoded, '', 'no' );
		if ( ! $wrote ) {
			update_option( $option_key, $encoded, false );
			$wrote = true;
		}
	}

	if ( ! $wrote ) {
		return new WP_Error(
			'mtuc_creation_intent_failed',
			__( 'Вътрешна грешка при резервиране на създаването.', 'mtunicredit' )
		);
	}

	$verify = mtuc_read_financing_operation_reservation( $option_key );
	if ( null === $verify
		|| ! hash_equals( (string) ( $verify['creation_ref'] ?? '' ), $creation_ref )
		|| ! hash_equals( (string) ( $verify['token'] ?? '' ), $token )
	) {
		return new WP_Error(
			'mtuc_creation_intent_failed',
			__( 'Вътрешна грешка при резервиране на създаването.', 'mtunicredit' )
		);
	}

	return true;
}

/**
 * Persist minimal operation ownership on a Woo order as soon as ID X exists.
 *
 * @param string   $option_key   Reservation option key.
 * @param WC_Order $order        Order instance.
 * @param string   $token        Operation token.
 * @param string   $scope_key    Scope key.
 * @param string   $creation_ref Creation reference.
 * @return void
 */
function mtuc_early_bind_financing_operation_order(
	string $option_key,
	WC_Order $order,
	string $token,
	string $scope_key,
	string $creation_ref = ''
): void {
	if ( '' === $creation_ref ) {
		$creation_ref = mtuc_financing_creation_ref( $token );
	}

	$order->update_meta_data( MTUC_ORDER_META_OPERATION_TOKEN, $token );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'operation_scope', $scope_key );
	$order->update_meta_data( MTUC_ORDER_META_CREATION_REF, $creation_ref );
	if ( MTUC_POPUP_INIT_COMPLETE !== (string) $order->get_meta( MTUC_ORDER_META_POPUP_INIT_STATE ) ) {
		$order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
	}
	$order->save();

	$created_at = time();
	$stored     = mtuc_read_financing_operation_reservation( $option_key );
	if ( null !== $stored && (int) $stored['created_at'] > 0 ) {
		$created_at = (int) $stored['created_at'];
	}

	$encoded = wp_json_encode(
		array(
			'token'        => $token,
			'scope'        => $scope_key,
			'wc_order_id'  => (int) $order->get_id(),
			'created_at'   => $created_at,
			'creation_ref' => $creation_ref,
		)
	);
	if ( is_string( $encoded ) && '' !== $encoded ) {
		update_option( $option_key, $encoded, false );
	}
}

/**
 * Whether a financing order still needs popup initialization.
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_financing_order_needs_popup_initialization( WC_Order $order ): bool {
	$state = (string) $order->get_meta( MTUC_ORDER_META_POPUP_INIT_STATE );
	if ( MTUC_POPUP_INIT_COMPLETE === $state ) {
		return false;
	}

	if ( MTUC_POPUP_INIT_INITIALIZING === $state ) {
		return true;
	}

	// Legacy orders without explicit state: incomplete only when bound but empty.
	$token = (string) $order->get_meta( MTUC_ORDER_META_OPERATION_TOKEN );
	$ref   = (string) $order->get_meta( MTUC_ORDER_META_CREATION_REF );
	if ( '' === $token && '' === $ref ) {
		return false;
	}

	if ( method_exists( $order, 'get_items' ) && count( $order->get_items( 'line_item' ) ) > 0 ) {
		return false;
	}

	return true;
}

/**
 * Mark popup order initialization complete (must be last durable init write).
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_mark_popup_order_init_complete( WC_Order $order ): void {
	$order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_COMPLETE );
	$order->save();
}

/**
 * Whether remote CP/SmartUCF requires popup init completion for this order.
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_order_requires_popup_init_complete_for_remote( WC_Order $order ): bool {
	$source = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'submission_source' );
	if ( in_array( $source, array( 'product_popup', 'cart_popup' ), true ) ) {
		return true;
	}

	$via = (string) $order->get_created_via();
	if ( in_array( $via, array( 'mtuc_product_popup', 'mtuc_cart_popup' ), true ) ) {
		return true;
	}

	if ( 0 === strpos( $via, 'mtuc:' ) ) {
		return true;
	}

	return '' !== (string) $order->get_meta( MTUC_ORDER_META_CREATION_REF );
}

/**
 * Gate remote bank calls on popup initialization completeness.
 *
 * @param WC_Order $order Order instance.
 * @return true|WP_Error
 */
function mtuc_assert_popup_order_ready_for_remote( WC_Order $order ) {
	if ( ! mtuc_order_requires_popup_init_complete_for_remote( $order ) ) {
		return true;
	}

	if ( MTUC_POPUP_INIT_COMPLETE === (string) $order->get_meta( MTUC_ORDER_META_POPUP_INIT_STATE ) ) {
		return true;
	}

	return new WP_Error(
		'mtuc_order_init_incomplete',
		__( 'Поръчката още не е напълно инициализирана. Моля, опитайте отново.', 'mtunicredit' )
	);
}

/**
 * Begin or join a durable financing operation (atomic reservation + reuse).
 *
 * States (AUD-WOO-018):
 * - active unresolved claim (wc_order_id=0, within TTL)
 * - resolved to a Woo order (wc_order_id>0 or token meta)
 * - stale unresolved claim (reclaimable after TTL)
 *
 * @param string $token     Normalized operation token.
 * @param string $scope_key Session/customer scope key.
 * @return array{claimed:bool,option_key:string,order:?WC_Order}|WP_Error
 */
function mtuc_begin_financing_operation( string $token, string $scope_key ) {
	$creation_ref = mtuc_financing_creation_ref( $token );
	$existing     = mtuc_find_order_for_financing_operation( $token, $creation_ref );
	if ( $existing instanceof WC_Order ) {
		if ( ! mtuc_operation_token_matches_order_scope( $existing, $scope_key ) ) {
			return new WP_Error(
				'mtuc_operation_scope_mismatch',
				__( 'Заявката не съответства на текущата сесия.', 'mtunicredit' )
			);
		}

		$option_key = mtuc_financing_operation_option_key( $token );
		mtuc_early_bind_financing_operation_order( $option_key, $existing, $token, $scope_key, $creation_ref );

		return array(
			'claimed'    => false,
			'option_key' => $option_key,
			'order'      => $existing,
		);
	}

	$option_key  = mtuc_financing_operation_option_key( $token );
	$reservation = wp_json_encode(
		array(
			'token'        => $token,
			'scope'        => $scope_key,
			'wc_order_id'  => 0,
			'created_at'   => time(),
			'creation_ref' => '',
		)
	);

	if ( false === $reservation ) {
		return new WP_Error(
			'mtuc_operation_encode_failed',
			__( 'Вътрешна грешка при резервиране на заявката.', 'mtunicredit' )
		);
	}

	if ( add_option( $option_key, $reservation, '', 'no' ) ) {
		return array(
			'claimed'    => true,
			'option_key' => $option_key,
			'order'      => null,
		);
	}

	if ( mtuc_try_reclaim_stale_financing_operation( $option_key, $token, $scope_key ) ) {
		return array(
			'claimed'    => true,
			'option_key' => $option_key,
			'order'      => null,
		);
	}

	for ( $attempt = 0; $attempt < MTUC_FINANCING_OPERATION_POLL_ATTEMPTS; ++$attempt ) {
		$existing = mtuc_find_order_for_financing_operation( $token, $creation_ref );
		if ( $existing instanceof WC_Order ) {
			if ( ! mtuc_operation_token_matches_order_scope( $existing, $scope_key ) ) {
				return new WP_Error(
					'mtuc_operation_scope_mismatch',
					__( 'Заявката не съответства на текущата сесия.', 'mtunicredit' )
				);
			}

			return array(
				'claimed'    => false,
				'option_key' => $option_key,
				'order'      => $existing,
			);
		}

		$stored = mtuc_read_financing_operation_reservation( $option_key );
		if ( null !== $stored ) {
			if ( '' !== $stored['scope'] && $stored['scope'] !== $scope_key ) {
				return new WP_Error(
					'mtuc_operation_scope_mismatch',
					__( 'Заявката не съответства на текущата сесия.', 'mtunicredit' )
				);
			}

			if ( $stored['wc_order_id'] > 0 && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $stored['wc_order_id'] );
				if ( $order instanceof WC_Order ) {
					return array(
						'claimed'    => false,
						'option_key' => $option_key,
						'order'      => $order,
					);
				}
			}

			if ( '' !== (string) ( $stored['creation_ref'] ?? '' ) ) {
				$by_ref = mtuc_find_order_for_financing_operation( $token, (string) $stored['creation_ref'] );
				if ( $by_ref instanceof WC_Order ) {
					return array(
						'claimed'    => false,
						'option_key' => $option_key,
						'order'      => $by_ref,
					);
				}
			}

			if ( mtuc_try_reclaim_stale_financing_operation( $option_key, $token, $scope_key ) ) {
				return array(
					'claimed'    => true,
					'option_key' => $option_key,
					'order'      => null,
				);
			}
		}

		usleep( 100000 );
	}

	return new WP_Error(
		'mtuc_operation_contention',
		__( 'Заявката вече се обработва. Моля, изчакайте.', 'mtunicredit' )
	);
}

/**
 * Whether an operation token's stored scope matches the current submission scope.
 *
 * @param WC_Order $order     Order instance.
 * @param string   $scope_key Current scope key.
 * @return bool
 */
function mtuc_operation_token_matches_order_scope( WC_Order $order, string $scope_key ): bool {
	$stored_scope = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'operation_scope' );
	if ( '' === $stored_scope ) {
		return true;
	}

	return hash_equals( $stored_scope, $scope_key );
}

/**
 * Persist operation token and external CP identity on the Woo order.
 *
 * Token/scope and reservation wc_order_id are saved before CP identity assignment
 * so a handled commit failure cannot orphan the order from the operation token
 * (AUD-WOO-018-F02).
 *
 * @param string   $option_key Reservation option key.
 * @param WC_Order $order      Order instance.
 * @param string   $token      Operation token.
 * @param string   $scope_key  Scope key.
 * @return true|WP_Error
 */
function mtuc_commit_financing_operation( string $option_key, WC_Order $order, string $token, string $scope_key ) {
	$order->update_meta_data( MTUC_ORDER_META_OPERATION_TOKEN, $token );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'operation_scope', $scope_key );
	$order->save();

	$created_at = time();
	$stored     = mtuc_read_financing_operation_reservation( $option_key );
	if ( null !== $stored && (int) $stored['created_at'] > 0 ) {
		$created_at = (int) $stored['created_at'];
	}

	mtuc_write_financing_operation_reservation(
		$option_key,
		$token,
		$scope_key,
		(int) $order->get_id(),
		$created_at
	);

	$cp_id = mtuc_assign_cp_shop_order_id( $order );
	if ( is_wp_error( $cp_id ) ) {
		return $cp_id;
	}
	$order->save();

	return true;
}

/**
 * Release an unused atomic reservation when order creation failed.
 *
 * Must not be used after a Woo order has already been bound to the operation.
 * Must not delete a reservation that already carries a non-empty creation_ref
 * (post-create-attempt outcome may be ambiguous — AUD-WOO-018 Pass 5).
 *
 * @param string $option_key Reservation option key.
 * @return void
 */
function mtuc_release_financing_operation_claim( string $option_key ): void {
	$raw = mtuc_get_option_raw_value( $option_key );
	if ( null === $raw ) {
		return;
	}

	$stored = json_decode( $raw, true );
	if ( ! is_array( $stored ) ) {
		return;
	}

	if ( isset( $stored['wc_order_id'] ) && (int) $stored['wc_order_id'] > 0 ) {
		return;
	}

	/*
	 * creation_ref armed ⇒ create may have partially persisted X. Absence cannot be
	 * proven from a WP_Error alone; retain reservation to block automatic Y.
	 */
	if ( '' !== (string) ( $stored['creation_ref'] ?? '' ) ) {
		return;
	}

	mtuc_options_cas_delete( $option_key, $raw );
}

/**
 * Generate and persist CP shop order_id for new financing orders (max 13 decimal digits).
 *
 * New orders use the Woo internal numeric order ID. Existing persisted values (including
 * legacy W-prefixed identifiers from an earlier remediation) are returned unchanged.
 *
 * @param WC_Order $order Order instance.
 * @return string|WP_Error Decimal CP order_id, persisted meta value, or validation error.
 */
function mtuc_assign_cp_shop_order_id( WC_Order $order ) {
	$existing = (string) $order->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID );
	if ( '' !== $existing ) {
		return $existing;
	}

	$internal_id = (int) $order->get_id();
	if ( $internal_id <= 0 ) {
		return new WP_Error(
			'mtuc_cp_shop_order_id_invalid',
			sprintf(
				/* translators: %d: WooCommerce internal order ID */
				__( 'Невалиден Woo internal order ID (%d) за CP order_id.', 'mtunicredit' ),
				$internal_id
			),
			array(
				'wc_order_id' => $internal_id,
			)
		);
	}

	$cp_id = (string) $internal_id;
	if ( strlen( $cp_id ) > MTUC_CP_SHOP_ORDER_ID_MAX_LEN ) {
		return new WP_Error(
			'mtuc_cp_shop_order_id_too_long',
			sprintf(
				/* translators: 1: WooCommerce internal order ID, 2: CP maximum length */
				__( 'Woo internal order ID %1$s надхвърля CP лимита от %2$d символа.', 'mtunicredit' ),
				$cp_id,
				MTUC_CP_SHOP_ORDER_ID_MAX_LEN
			),
			array(
				'wc_order_id' => $internal_id,
				'cp_order_id' => $cp_id,
				'max_len'     => MTUC_CP_SHOP_ORDER_ID_MAX_LEN,
			)
		);
	}

	$order->update_meta_data( MTUC_ORDER_META_CP_SHOP_ORDER_ID, $cp_id );

	return $cp_id;
}

/**
 * Resolve or create a popup financing order for a durable operation token.
 *
 * @param string   $operation_token Normalized operation token.
 * @param string   $scope_key       Session scope key.
 * @param callable $create_order    Callable( ?callable $early_bind, ?WC_Order $existing ): WC_Order|WP_Error.
 * @return array{order: WC_Order, created: bool, option_key: string}|WP_Error
 */
function mtuc_resolve_popup_financing_order( string $operation_token, string $scope_key, callable $create_order ) {
	$operation = mtuc_begin_financing_operation( $operation_token, $scope_key );
	if ( is_wp_error( $operation ) ) {
		return $operation;
	}

	if ( $operation['order'] instanceof WC_Order
		&& ! mtuc_financing_order_needs_popup_initialization( $operation['order'] )
	) {
		return array(
			'order'      => $operation['order'],
			'created'    => false,
			'option_key' => $operation['option_key'],
		);
	}

	$existing     = ( $operation['order'] instanceof WC_Order ) ? $operation['order'] : null;
	$creation_ref = mtuc_financing_creation_ref( $operation_token );
	$intent       = mtuc_persist_financing_creation_intent(
		$operation['option_key'],
		$operation_token,
		$scope_key,
		$creation_ref
	);
	if ( is_wp_error( $intent ) ) {
		return $intent;
	}

	/*
	 * Creation intent is armed: mark ownership non-reclaimable by TTL for the
	 * local Woo create/init micro-window (AUD-WOO-018-F01 approach A).
	 */
	if ( function_exists( 'mtuc_require_armed_submission_lock_ownership' )
		&& defined( 'MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED' )
	) {
		$armed = mtuc_require_armed_submission_lock_ownership(
			defined( 'MTUC_SUBMISSION_LOCK_RENEW_CREATE' ) ? MTUC_SUBMISSION_LOCK_RENEW_CREATE : 90,
			MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED
		);
		if ( is_wp_error( $armed ) ) {
			return $armed;
		}
	}

	$binder = static function ( WC_Order $order ) use ( $operation, $operation_token, $scope_key, $creation_ref ) {
		mtuc_early_bind_financing_operation_order(
			$operation['option_key'],
			$order,
			$operation_token,
			$scope_key,
			$creation_ref
		);
	};

	$order = $create_order(
		$binder,
		$existing,
		array(
			'creation_ref'       => $creation_ref,
			'created_via_marker' => mtuc_financing_created_via_marker( $creation_ref ),
		)
	);
	if ( is_wp_error( $order ) ) {
		/*
		 * Post-create-attempt (creation_ref + create_armed already set): WP_Error does
		 * not prove X is absent. Retain reservation and create_armed safety; no Y.
		 */
		return $order;
	}

	if ( ! $order instanceof WC_Order ) {
		/*
		 * Same ambiguity policy as WP_Error after armed creation intent.
		 */
		return new WP_Error(
			'mtuc_order_create_failed',
			__( 'Поръчката не може да бъде създадена.', 'mtunicredit' )
		);
	}

	if ( '' === (string) $order->get_meta( MTUC_ORDER_META_OPERATION_TOKEN ) ) {
		mtuc_early_bind_financing_operation_order(
			$operation['option_key'],
			$order,
			$operation_token,
			$scope_key,
			$creation_ref
		);
	}

	$committed = mtuc_commit_financing_operation( $operation['option_key'], $order, $operation_token, $scope_key );
	if ( is_wp_error( $committed ) ) {
		return $committed;
	}

	return array(
		'order'      => $order,
		'created'    => null === $existing,
		'option_key' => $operation['option_key'],
	);
}

/**
 * Complete product-popup bank submission for an existing order (idempotent).
 *
 * @param WC_Order              $order        Order instance.
 * @param array<string, string> $customer     Customer fields.
 * @param array<string, mixed>  $calculation  Calculation snapshot.
 * @param WC_Product            $product      Product line.
 * @param int                   $parent_id    Parent product ID.
 * @param int                   $variation_id Variation ID.
 * @param int                   $quantity     Quantity.
 * @param array<string, mixed>  $shop         Shop data.
 * @param bool                  $process2     Process 2 flag.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_complete_product_popup_bank_submission(
	WC_Order $order,
	array $customer,
	array $calculation,
	WC_Product $product,
	int $parent_id,
	int $variation_id,
	int $quantity,
	array $shop,
	bool $process2
) {
	if ( mtuc_popup_order_has_successful_bank_submission( $order, $process2 ) ) {
		$existing = mtuc_build_existing_popup_submission_result( $order, $shop, $process2 );
		if ( ! is_wp_error( $existing ) ) {
			return $existing;
		}
	}

	if ( mtuc_order_financing_is_terminal_failure( $order ) ) {
		return array(
			'bank_unavailable' => true,
			'redirect_url'     => mtuc_get_popup_order_thankyou_url( $order ),
		);
	}

	$cp_order_id = (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' );
	$outcome     = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ) );

	if ( $cp_order_id <= 0 || 'unknown' === $outcome ) {
		$cp_result = mtuc_send_popup_order_to_cp(
			$order,
			$customer,
			$calculation,
			$product,
			$parent_id,
			$variation_id,
			$quantity,
			$shop
		);

		if ( is_wp_error( $cp_result ) ) {
			if ( 'mtuc_submit_locked' === $cp_result->get_error_code() ) {
				return $cp_result;
			}

			return array(
				'bank_unavailable' => true,
				'redirect_url'     => mtuc_get_popup_order_thankyou_url( $order ),
			);
		}

		$cp_order_id = (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' );
	}

	if ( $process2 ) {
		return array(
			'redirect_url' => mtuc_get_popup_order_thankyou_url( $order ),
			'cp_order_id'  => $cp_order_id,
			'process2'     => true,
		);
	}

	if ( ! mtuc_popup_order_needs_smartucf_submission( $order ) ) {
		$existing = mtuc_build_existing_popup_submission_result( $order, $shop, false );
		if ( ! is_wp_error( $existing ) ) {
			return $existing;
		}
	}

	$smartucf_result = mtuc_send_popup_order_to_smartucf(
		$order,
		$customer,
		$calculation,
		$product,
		$parent_id,
		$variation_id,
		$quantity,
		$shop
	);

	if ( is_wp_error( $smartucf_result ) ) {
		if ( 'mtuc_submit_locked' === $smartucf_result->get_error_code() ) {
			return $smartucf_result;
		}

		return array(
			'bank_unavailable' => true,
			'redirect_url'     => mtuc_get_popup_order_thankyou_url( $order ),
		);
	}

	mtuc_record_order_bank_status(
		$order,
		MTUC_BANK_STATUS_SENT_PROCESS1,
		array( 'sync_cp' => true )
	);
	$order->update_meta_data(
		MTUC_ORDER_META_SMARTUCF_REDIRECT_URL,
		esc_url_raw( (string) $smartucf_result['redirect_url'] )
	);
	$order->save();

	return array(
		'redirect_url' => $smartucf_result['redirect_url'],
		'cp_order_id'  => $cp_order_id,
	);
}

/**
 * Whether financing submission reached a terminal confirmed failure state.
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_order_financing_is_terminal_failure( WC_Order $order ): bool {
	$outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ) );
	if ( 'unknown' === $outcome ) {
		return false;
	}

	$bank_status = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ) );
	$terminal    = array(
		MTUC_BANK_STATUS_SEND_FAILED,
		MTUC_BANK_STATUS_SEND_FAILED_CP,
		MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF,
	);

	return in_array( $bank_status, $terminal, true );
}

/**
 * Whether popup order already completed a successful bank submission.
 *
 * @param WC_Order $order     Order instance.
 * @param bool     $process2  Whether shop uses Process 2.
 * @return bool
 */
function mtuc_popup_order_has_successful_bank_submission( WC_Order $order, bool $process2 ): bool {
	$bank_status = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ) );

	if ( $process2 ) {
		return MTUC_BANK_STATUS_SENT_PROCESS2 === $bank_status;
	}

	return MTUC_BANK_STATUS_SENT_PROCESS1 === $bank_status;
}

/**
 * Whether SmartUCF submission should run for an existing Process 1 order.
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_popup_order_needs_smartucf_submission( WC_Order $order ): bool {
	if ( mtuc_is_process2_order( $order ) ) {
		return false;
	}

	if ( mtuc_popup_order_has_successful_bank_submission( $order, false ) ) {
		return false;
	}

	if ( mtuc_order_financing_is_terminal_failure( $order ) ) {
		return false;
	}

	return (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ) > 0;
}

/**
 * Empty customer cart once after accepted cart-popup financing.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_maybe_empty_cart_for_financing_order( WC_Order $order ): void {
	if ( (int) $order->get_meta( MTUC_ORDER_META_CART_EMPTIED ) ) {
		return;
	}

	$source = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'submission_source' );
	if ( 'cart_popup' !== $source ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	WC()->cart->empty_cart();
	$order->update_meta_data( MTUC_ORDER_META_CART_EMPTIED, 1 );
	$order->save();
}

/**
 * Apply gateway status and cart cleanup once for accepted popup financing.
 *
 * Stock reduction follows WooCommerce native on-hold transition (at most once).
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_accept_popup_financing_order( WC_Order $order ): void {
	mtuc_apply_payment_gateway_to_order( $order );
	mtuc_maybe_empty_cart_for_financing_order( $order );
}

/**
 * Build AJAX success payload for an existing popup financing order (idempotent reuse).
 *
 * @param WC_Order             $order  Order instance.
 * @param array<string, mixed> $shop   Shop data.
 * @param bool                 $process2 Process 2 flag.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_build_existing_popup_submission_result( WC_Order $order, array $shop, bool $process2 ) {
	if ( mtuc_order_financing_is_terminal_failure( $order ) ) {
		return array(
			'bank_unavailable' => true,
			'redirect_url'     => mtuc_get_popup_order_thankyou_url( $order ),
		);
	}

	if ( mtuc_popup_order_has_successful_bank_submission( $order, $process2 ) ) {
		if ( $process2 ) {
			return array(
				'redirect_url' => mtuc_get_popup_order_thankyou_url( $order ),
				'cp_order_id'  => (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ),
				'process2'     => true,
			);
		}

		$redirect = (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL );
		if ( '' === $redirect ) {
			$redirect = mtuc_get_popup_order_thankyou_url( $order );
		}

		return array(
			'redirect_url' => $redirect,
			'cp_order_id'  => (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ),
		);
	}

	return new WP_Error(
		'mtuc_existing_order_incomplete',
		__( 'Съществуваща заявка изисква продължаване на обработката.', 'mtunicredit' )
	);
}
