<?php
/**
 * Order-level financing diagnostics (AUD-WOO-013).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Order meta: current unresolved financing diagnostic (JSON). */
const MTUC_ORDER_META_FINANCING_DIAGNOSTIC = '_mtuc_financing_diagnostic';

/** Order meta: bounded diagnostic history (JSON array, max entries). */
const MTUC_ORDER_META_FINANCING_DIAGNOSTIC_HISTORY = '_mtuc_financing_diagnostic_history';

/** Max stored diagnostic history entries per order. */
const MTUC_ORDER_DIAGNOSTIC_HISTORY_MAX = 5;

/**
 * Record a sanitized financing diagnostic on an order.
 *
 * @param WC_Order               $order     Order instance.
 * @param WP_Error|Throwable|mixed $error     Error source.
 * @param string                 $subsystem cp|smartucf|certificate|configuration|sync|general.
 * @param int                    $wc_order_id Optional correlation (defaults to order id).
 * @return void
 */
function mtuc_record_order_financing_diagnostic( WC_Order $order, $error, string $subsystem = 'general', int $wc_order_id = 0 ): void {
	if ( ! function_exists( 'mtuc_normalize_error' ) ) {
		return;
	}

	$normalized = mtuc_normalize_error( $error, $subsystem );
	$payload    = array(
		'category'  => $normalized['category'],
		'subsystem' => $normalized['subsystem'],
		'retryable' => $normalized['retryable'] ? 1 : 0,
		'code'      => $normalized['code'],
		'ts'        => time(),
	);

	if ( $wc_order_id <= 0 ) {
		$wc_order_id = $order->get_id();
	}
	if ( $wc_order_id > 0 ) {
		$payload['correlation_id'] = (string) $wc_order_id;
	}

	$order->update_meta_data( MTUC_ORDER_META_FINANCING_DIAGNOSTIC, wp_json_encode( $payload ) );
	mtuc_append_order_diagnostic_history( $order, $payload );
	$order->save();
}

/**
 * Clear current unresolved diagnostic after successful recovery.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_clear_order_financing_diagnostic( WC_Order $order ): void {
	$order->delete_meta_data( MTUC_ORDER_META_FINANCING_DIAGNOSTIC );
	$order->save();
}

/**
 * Append diagnostic to bounded history.
 *
 * @param WC_Order              $order   Order instance.
 * @param array<string, mixed>  $payload Diagnostic payload.
 * @return void
 */
function mtuc_append_order_diagnostic_history( WC_Order $order, array $payload ): void {
	$raw     = (string) $order->get_meta( MTUC_ORDER_META_FINANCING_DIAGNOSTIC_HISTORY );
	$history = json_decode( $raw, true );
	if ( ! is_array( $history ) ) {
		$history = array();
	}

	$history[] = $payload;
	if ( count( $history ) > MTUC_ORDER_DIAGNOSTIC_HISTORY_MAX ) {
		$history = array_slice( $history, -MTUC_ORDER_DIAGNOSTIC_HISTORY_MAX );
	}

	$order->update_meta_data( MTUC_ORDER_META_FINANCING_DIAGNOSTIC_HISTORY, wp_json_encode( $history ) );
}

/**
 * Decode current order diagnostic.
 *
 * @param WC_Order $order Order instance.
 * @return array<string, mixed>|null
 */
function mtuc_get_order_financing_diagnostic( WC_Order $order ): ?array {
	$raw = (string) $order->get_meta( MTUC_ORDER_META_FINANCING_DIAGNOSTIC );
	if ( '' === $raw ) {
		return null;
	}

	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : null;
}

/**
 * Admin label for diagnostic category.
 *
 * @param string $category Category slug.
 * @return string
 */
function mtuc_get_diagnostic_category_admin_label( string $category ): string {
	$labels = array(
		'cp_timeout'           => __( 'КП — timeout/мрежа', 'mtunicredit' ),
		'cp_network'           => __( 'КП — мрежова грешка', 'mtunicredit' ),
		'cp_auth'              => __( 'КП — автентикация', 'mtunicredit' ),
		'cp_validation'        => __( 'КП — валидация', 'mtunicredit' ),
		'cp_server'            => __( 'КП — сървърна грешка', 'mtunicredit' ),
		'smartucf_transport'   => __( 'SmartUCF — транспорт', 'mtunicredit' ),
		'smartucf_timeout'     => __( 'SmartUCF — timeout', 'mtunicredit' ),
		'certificate_error'    => __( 'SSL сертификат', 'mtunicredit' ),
		'configuration_error'  => __( 'Конфигурация', 'mtunicredit' ),
		'sync_error'           => __( 'Синхронизация', 'mtunicredit' ),
		'validation'           => __( 'Валидация', 'mtunicredit' ),
		'unexpected_error'     => __( 'Неочаквана грешка', 'mtunicredit' ),
	);

	return $labels[ $category ] ?? $category;
}

/**
 * Admin rows for order financing diagnostic.
 *
 * @param WC_Order $order Order instance.
 * @return array<string, string>
 */
function mtuc_get_order_diagnostic_admin_rows( WC_Order $order ): array {
	$diag = mtuc_get_order_financing_diagnostic( $order );
	if ( null === $diag ) {
		return array();
	}

	$rows = array();

	$category = isset( $diag['category'] ) ? (string) $diag['category'] : '';
	if ( '' !== $category ) {
		$rows[ __( 'Последна грешка (категория)', 'mtunicredit' ) ] = mtuc_get_diagnostic_category_admin_label( $category );
	}

	if ( ! empty( $diag['subsystem'] ) ) {
		$rows[ __( 'Подсистема', 'mtunicredit' ) ] = (string) $diag['subsystem'];
	}

	if ( ! empty( $diag['ts'] ) ) {
		$rows[ __( 'Час на грешката', 'mtunicredit' ) ] = wp_date( 'Y-m-d H:i:s', (int) $diag['ts'] );
	}

	if ( isset( $diag['retryable'] ) ) {
		$rows[ __( 'Повторение възможно', 'mtunicredit' ) ] = (int) $diag['retryable'] ? __( 'Да', 'mtunicredit' ) : __( 'Не', 'mtunicredit' );
	}

	if ( ! empty( $diag['correlation_id'] ) ) {
		$rows[ __( 'Корелация', 'mtunicredit' ) ] = (string) $diag['correlation_id'];
	}

	return $rows;
}
