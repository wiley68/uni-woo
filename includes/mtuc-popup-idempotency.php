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
		'token'       => isset( $data['token'] ) ? (string) $data['token'] : '',
		'scope'       => isset( $data['scope'] ) ? (string) $data['scope'] : '',
		'wc_order_id' => isset( $data['wc_order_id'] ) ? (int) $data['wc_order_id'] : 0,
		'created_at'  => isset( $data['created_at'] ) ? (int) $data['created_at'] : 0,
	);
}

/**
 * Begin or join a durable financing operation (atomic reservation + reuse).
 *
 * @param string $token     Normalized operation token.
 * @param string $scope_key Session/customer scope key.
 * @return array{claimed:bool,option_key:string,order:?WC_Order}|WP_Error
 */
function mtuc_begin_financing_operation( string $token, string $scope_key ) {
	$existing = mtuc_find_order_by_operation_token( $token );
	if ( $existing instanceof WC_Order ) {
		if ( ! mtuc_operation_token_matches_order_scope( $existing, $scope_key ) ) {
			return new WP_Error(
				'mtuc_operation_scope_mismatch',
				__( 'Заявката не съответства на текущата сесия.', 'mtunicredit' )
			);
		}

		return array(
			'claimed'    => false,
			'option_key' => mtuc_financing_operation_option_key( $token ),
			'order'      => $existing,
		);
	}

	$option_key  = mtuc_financing_operation_option_key( $token );
	$reservation = wp_json_encode(
		array(
			'token'       => $token,
			'scope'       => $scope_key,
			'wc_order_id' => 0,
			'created_at'  => time(),
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

	for ( $attempt = 0; $attempt < MTUC_FINANCING_OPERATION_POLL_ATTEMPTS; ++$attempt ) {
		$existing = mtuc_find_order_by_operation_token( $token );
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
 * @param string   $option_key Reservation option key.
 * @param WC_Order $order      Order instance.
 * @param string   $token      Operation token.
 * @param string   $scope_key  Scope key.
 * @return true|WP_Error
 */
function mtuc_commit_financing_operation( string $option_key, WC_Order $order, string $token, string $scope_key ) {
	$order->update_meta_data( MTUC_ORDER_META_OPERATION_TOKEN, $token );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'operation_scope', $scope_key );
	$cp_id = mtuc_assign_cp_shop_order_id( $order );
	if ( is_wp_error( $cp_id ) ) {
		return $cp_id;
	}
	$order->save();

	update_option(
		$option_key,
		wp_json_encode(
			array(
				'token'       => $token,
				'scope'       => $scope_key,
				'wc_order_id' => $order->get_id(),
				'created_at'  => time(),
			)
		),
		false
	);

	return true;
}

/**
 * Release an unused atomic reservation when order creation failed.
 *
 * @param string $option_key Reservation option key.
 * @return void
 */
function mtuc_release_financing_operation_claim( string $option_key ): void {
	delete_option( $option_key );
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
 * @param callable $create_order    Callable returning WC_Order|WP_Error.
 * @return array{order: WC_Order, created: bool, option_key: string}|WP_Error
 */
function mtuc_resolve_popup_financing_order( string $operation_token, string $scope_key, callable $create_order ) {
	$operation = mtuc_begin_financing_operation( $operation_token, $scope_key );
	if ( is_wp_error( $operation ) ) {
		return $operation;
	}

	if ( $operation['order'] instanceof WC_Order ) {
		return array(
			'order'      => $operation['order'],
			'created'    => false,
			'option_key' => $operation['option_key'],
		);
	}

	$order = $create_order();
	if ( is_wp_error( $order ) ) {
		mtuc_release_financing_operation_claim( $operation['option_key'] );
		return $order;
	}

	if ( ! $order instanceof WC_Order ) {
		mtuc_release_financing_operation_claim( $operation['option_key'] );
		return new WP_Error(
			'mtuc_order_create_failed',
			__( 'Поръчката не може да бъде създадена.', 'mtunicredit' )
		);
	}

	$committed = mtuc_commit_financing_operation( $operation['option_key'], $order, $operation_token, $scope_key );
	if ( is_wp_error( $committed ) ) {
		mtuc_release_financing_operation_claim( $operation['option_key'] );
		return $committed;
	}

	return array(
		'order'      => $order,
		'created'    => true,
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
