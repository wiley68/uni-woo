<?php
/**
 * Bank lifecycle technical state (AUD-WOO-004/005/008/009).
 *
 * Separates merchant-controlled Woo order status from bank financing status,
 * CP create ambiguity recovery, and CP status PATCH sync diagnostics.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Order meta: CP create outcome — created|unknown|missing. */
const MTUC_ORDER_META_CP_CREATE_OUTCOME = '_mtuc_cp_create_outcome';

/** Order meta: intended bank status awaiting successful CP PATCH. */
const MTUC_ORDER_META_CP_SYNC_PENDING = '_mtuc_cp_status_sync_pending';

/** Order meta: human label for pending sync status. */
const MTUC_ORDER_META_CP_SYNC_LABEL = '_mtuc_cp_status_sync_label';

/** Order meta: sanitized sync failure category. */
const MTUC_ORDER_META_CP_SYNC_ERROR = '_mtuc_cp_status_sync_error';

/** Order meta: sync attempt count. */
const MTUC_ORDER_META_CP_SYNC_ATTEMPTS = '_mtuc_cp_status_sync_attempts';

/** Order meta: last sync attempt unix timestamp. */
const MTUC_ORDER_META_CP_SYNC_LAST_AT = '_mtuc_cp_status_sync_last_at';

/** Max automatic CP status PATCH retries. */
const MTUC_CP_STATUS_SYNC_MAX_ATTEMPTS = 3;

/**
 * Whether a CP API error is an ambiguous transport outcome (timeout / 5xx / connection).
 *
 * Does not prove that CP did or did not commit the order.
 *
 * @param WP_Error $error API error.
 * @return bool
 */
function mtuc_is_cp_transport_ambiguous_error( WP_Error $error ): bool {
	$code = $error->get_error_code();

	if ( in_array( $code, array( 'http_request_failed', 'mtuc_api_invalid_json' ), true ) ) {
		return true;
	}

	if ( 'mtuc_api_http_error' !== $code ) {
		return false;
	}

	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

	return $status >= 500 || 0 === $status;
}

/**
 * Whether a CP create error is a definitive idempotency conflict (409).
 *
 * @param WP_Error $error API error.
 * @return bool
 */
function mtuc_is_cp_idempotency_conflict_error( WP_Error $error ): bool {
	if ( 'mtuc_api_http_error' !== $error->get_error_code() ) {
		return false;
	}

	$data   = $error->get_error_data();
	$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;

	return 409 === $status;
}

/**
 * Sanitize a CP sync failure into a short category (no secrets/PII).
 *
 * @param WP_Error $error API error.
 * @return string
 */
function mtuc_sanitize_cp_sync_error_category( WP_Error $error ): string {
	$code = $error->get_error_code();

	if ( 'http_request_failed' === $code ) {
		return 'transport_timeout';
	}

	if ( 'mtuc_api_invalid_json' === $code ) {
		return 'malformed_response';
	}

	if ( 'mtuc_api_http_error' === $code ) {
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		if ( 401 === $status ) {
			return 'auth_401';
		}
		if ( $status >= 500 ) {
			return 'http_5xx';
		}
		if ( $status >= 400 ) {
			return 'http_4xx';
		}
	}

	return 'api_error';
}

/**
 * Persist CP create outcome technical marker.
 *
 * @param WC_Order $order   Order instance.
 * @param string   $outcome created|unknown|missing.
 * @return void
 */
function mtuc_set_cp_create_outcome( WC_Order $order, string $outcome ): void {
	$outcome = sanitize_key( $outcome );
	if ( ! in_array( $outcome, array( 'created', 'unknown', 'missing' ), true ) ) {
		$outcome = 'unknown';
	}

	$order->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, $outcome );
}

/**
 * Clear CP create ambiguity marker after confirmed create.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_clear_cp_create_outcome_unknown( WC_Order $order ): void {
	$order->update_meta_data( MTUC_ORDER_META_CP_CREATE_OUTCOME, 'created' );
}

/**
 * Mark CP status PATCH as pending after a failed sync attempt.
 *
 * @param WC_Order $order           Order instance.
 * @param string   $bank_status_key Intended status_id.
 * @param string   $status_label    Intended label.
 * @param WP_Error $error           Sync error.
 * @return void
 */
function mtuc_mark_cp_status_sync_pending( WC_Order $order, string $bank_status_key, string $status_label, WP_Error $error ): void {
	$attempts = (int) $order->get_meta( MTUC_ORDER_META_CP_SYNC_ATTEMPTS );
	++$attempts;

	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_PENDING, sanitize_key( $bank_status_key ) );
	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_LABEL, $status_label );
	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_ERROR, mtuc_sanitize_cp_sync_error_category( $error ) );
	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_ATTEMPTS, $attempts );
	$order->update_meta_data( MTUC_ORDER_META_CP_SYNC_LAST_AT, time() );

	if ( $attempts <= MTUC_CP_STATUS_SYNC_MAX_ATTEMPTS ) {
		mtuc_schedule_cp_status_sync_retry( $order->get_id() );
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- sanitized diagnostics only.
		error_log(
			'MTUC CP status sync failed (order #' . $order->get_id()
			. ', status=' . sanitize_key( $bank_status_key )
			. ', category=' . mtuc_sanitize_cp_sync_error_category( $error )
			. ', attempt=' . $attempts . ')'
		);
	}
}

/**
 * Clear CP status sync pending markers after successful PATCH.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_clear_cp_status_sync_pending( WC_Order $order ): void {
	$order->delete_meta_data( MTUC_ORDER_META_CP_SYNC_PENDING );
	$order->delete_meta_data( MTUC_ORDER_META_CP_SYNC_LABEL );
	$order->delete_meta_data( MTUC_ORDER_META_CP_SYNC_ERROR );
	$order->delete_meta_data( MTUC_ORDER_META_CP_SYNC_ATTEMPTS );
	$order->delete_meta_data( MTUC_ORDER_META_CP_SYNC_LAST_AT );
}

/**
 * Schedule a bounded single-event retry for CP status PATCH.
 *
 * @param int $order_id WooCommerce order ID.
 * @return void
 */
function mtuc_schedule_cp_status_sync_retry( int $order_id ): void {
	$order_id = max( 0, $order_id );
	if ( $order_id <= 0 || ! function_exists( 'wp_schedule_single_event' ) ) {
		return;
	}

	$hook = 'mtuc_retry_cp_status_sync';
	$args = array( $order_id );

	if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( $hook, $args ) ) {
		return;
	}

	wp_schedule_single_event( time() + 120, $hook, $args );
}

/**
 * Cron/admin retry handler for pending CP status PATCH.
 *
 * @param int $order_id WooCommerce order ID.
 * @return true|WP_Error
 */
function mtuc_retry_cp_status_sync_for_order( int $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'mtuc_wc_missing', __( 'WooCommerce не е наличен.', 'mtunicredit' ) );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'mtuc_order_missing', __( 'Поръчката не е намерена.', 'mtunicredit' ) );
	}

	$pending = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_SYNC_PENDING ) );
	if ( '' === $pending ) {
		return true;
	}

	$attempts = (int) $order->get_meta( MTUC_ORDER_META_CP_SYNC_ATTEMPTS );
	if ( $attempts >= MTUC_CP_STATUS_SYNC_MAX_ATTEMPTS ) {
		return new WP_Error(
			'mtuc_cp_sync_max_attempts',
			__( 'Достигнат е лимитът за опити за синхронизация със КП.', 'mtunicredit' )
		);
	}

	$label  = (string) $order->get_meta( MTUC_ORDER_META_CP_SYNC_LABEL );
	$result = mtuc_sync_cp_order_bank_status( $order, $pending, '' !== $label ? $label : null );
	$order->save();

	return $result;
}

/**
 * Register lifecycle hooks (cron retry).
 *
 * @return void
 */
function mtuc_register_bank_lifecycle_hooks(): void {
	add_action( 'mtuc_retry_cp_status_sync', 'mtuc_retry_cp_status_sync_for_order', 10, 1 );
	add_action( 'admin_post_mtuc_retry_cp_status_sync', 'mtuc_admin_handle_retry_cp_status_sync' );
}

/**
 * Admin-post handler: manual CP status sync retry.
 *
 * @return void
 */
function mtuc_admin_handle_retry_cp_status_sync(): void {
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'Нямате достатъчно права.', 'mtunicredit' ) );
	}

	$order_id = isset( $_GET['order_id'] ) ? absint( wp_unslash( $_GET['order_id'] ) ) : 0;
	check_admin_referer( 'mtuc_retry_cp_status_sync_' . $order_id );

	mtuc_retry_cp_status_sync_for_order( $order_id );

	$redirect = wp_get_referer();
	if ( ! is_string( $redirect ) || '' === $redirect ) {
		$redirect = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
	}

	wp_safe_redirect( $redirect );
	exit;
}

/**
 * Human-readable CP create outcome for admin UI.
 *
 * @param WC_Order $order Order instance.
 * @return string Empty when none / created.
 */
function mtuc_get_cp_create_outcome_admin_label( WC_Order $order ): string {
	$outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ) );

	if ( 'unknown' === $outcome ) {
		return __( 'Създаването в КП е с неясен резултат (възможен timeout). Не се твърди, че поръчката липсва в КП.', 'mtunicredit' );
	}

	if ( 'missing' === $outcome ) {
		return __( 'Поръчката не е създадена в КП (потвърден отказ/грешка).', 'mtunicredit' );
	}

	return '';
}

/**
 * Human-readable CP status sync pending state for admin UI.
 *
 * @param WC_Order $order Order instance.
 * @return string Empty when not pending.
 */
function mtuc_get_cp_status_sync_admin_label( WC_Order $order ): string {
	$pending = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_SYNC_PENDING ) );
	if ( '' === $pending ) {
		return '';
	}

	$error    = (string) $order->get_meta( MTUC_ORDER_META_CP_SYNC_ERROR );
	$attempts = (int) $order->get_meta( MTUC_ORDER_META_CP_SYNC_ATTEMPTS );

	return sprintf(
		/* translators: 1: status key, 2: error category, 3: attempt count */
		__( 'Чакаща синхронизация към КП: %1$s (грешка: %2$s, опити: %3$d).', 'mtunicredit' ),
		$pending,
		'' !== $error ? $error : 'unknown',
		$attempts
	);
}
