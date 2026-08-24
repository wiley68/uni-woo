<?php
/**
 * Audience-aware financing presentation rows (AUD-WOO-016 Step 4).
 *
 * Given a Woo order and an explicit presentation audience, shapes which
 * financing fields may be shown (labels/values/order). Does not send email,
 * mutate orders, or change bank lifecycle state.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Financing rows for customer emails / Thank You / order details (no EGN). */
const MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER = 'customer';

/** Financing rows for Woo admin order panel (no EGN; may include diagnostics). */
const MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL = 'admin_panel';

/**
 * Financing rows for Process 2 merchant emails used in the manual bank workflow (EGN allowed).
 */
const MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL = 'admin_email';

/**
 * Whether the audience may include Process 2 EGN in rendered financing rows.
 *
 * Only Process 2 administrator/merchant email retains EGN for the manual bank process.
 *
 * @param string $audience Audience constant.
 * @return bool
 */
function mtuc_credit_rows_audience_includes_egn( string $audience ): bool {
	return MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL === $audience;
}

/**
 * Credit meta rows for a financing presentation audience (label => value).
 *
 * @param WC_Order $order    Order instance.
 * @param string   $audience One of MTUC_CREDIT_ROWS_AUDIENCE_*.
 * @return array<string, string>
 */
function mtuc_get_order_credit_meta_rows( WC_Order $order, string $audience ): array {
	$allowed = array(
		MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER,
		MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL,
		MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL,
	);
	if ( ! in_array( $audience, $allowed, true ) ) {
		$audience = MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER;
	}

	$status_text = mtuc_get_order_bank_status_display( $order );
	$cp_order_id = (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' );
	$months      = (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'months' );
	$outcome     = function_exists( 'mtuc_get_cp_create_outcome_admin_label' )
		? mtuc_get_cp_create_outcome_admin_label( $order )
		: '';
	$sync_label  = function_exists( 'mtuc_get_cp_status_sync_admin_label' )
		? mtuc_get_cp_status_sync_admin_label( $order )
		: '';

	if (
		'' === $status_text
		&& $cp_order_id <= 0
		&& $months <= 0
		&& '' === $outcome
		&& '' === $sync_label
	) {
		return array();
	}

	$rows = array();

	if ( '' !== $status_text ) {
		$rows[ __( 'Статус към банката', 'mtunicredit' ) ] = $status_text;
	}

	if ( $cp_order_id > 0 ) {
		$rows[ __( 'КП поръчка (ID)', 'mtunicredit' ) ] = (string) $cp_order_id;
	}

	$cp_shop_order_id = mtuc_get_cp_shop_order_id( $order );
	if ( '' !== $cp_shop_order_id ) {
		$rows[ __( 'КП shop order_id', 'mtunicredit' ) ] = $cp_shop_order_id;
	}

	if ( '' !== $outcome ) {
		$rows[ __( 'КП създаване', 'mtunicredit' ) ] = $outcome;
	}

	if ( '' !== $sync_label ) {
		$rows[ __( 'Синхронизация към КП', 'mtunicredit' ) ] = $sync_label;
	}

	if ( $months > 0 ) {
		$rows[ __( 'Срок (месеци)', 'mtunicredit' ) ] = (string) $months;
	}

	$kop_code = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'kop_code' );
	if ( '' !== $kop_code ) {
		$rows[ __( 'КОП', 'mtunicredit' ) ] = $kop_code;
	}

	$parva   = (float) $order->get_meta( MTUC_ORDER_META_PREFIX . 'parva' );
	$loan    = (float) $order->get_meta( MTUC_ORDER_META_PREFIX . 'loan_amount' );
	$monthly = (float) $order->get_meta( MTUC_ORDER_META_PREFIX . 'monthly_installment' );
	$total   = (float) $order->get_meta( MTUC_ORDER_META_PREFIX . 'total_payable' );
	$glp     = (float) $order->get_meta( MTUC_ORDER_META_PREFIX . 'glp' );
	$gpr     = (float) $order->get_meta( MTUC_ORDER_META_PREFIX . 'gpr' );

	if ( $months > 0 || '' !== $status_text ) {
		$rows[ __( 'Първоначална вноска', 'mtunicredit' ) ] = number_format( $parva, 2, '.', '' );
		$rows[ __( 'Сума на заема', 'mtunicredit' ) ]       = number_format( $loan, 2, '.', '' );
		$rows[ __( 'Месечна вноска', 'mtunicredit' ) ]      = number_format( $monthly, 2, '.', '' );
		$rows[ __( 'Обща дължима сума', 'mtunicredit' ) ]   = number_format( $total, 2, '.', '' );
		$rows[ __( 'ГЛП / ГПР', 'mtunicredit' ) ]           = number_format( $glp, 2, '.', '' ) . '% / ' . number_format( $gpr, 2, '.', '' ) . '%';
	}

	if ( mtuc_is_process2_order( $order ) ) {
		if ( mtuc_credit_rows_audience_includes_egn( $audience ) ) {
			$egn = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'egn' );
			if ( '' !== $egn ) {
				$rows[ __( 'ЕГН', 'mtunicredit' ) ] = $egn;
			}
		}

		$phone2 = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'phone2' );
		if ( '' !== $phone2 && MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER !== $audience ) {
			$rows[ __( 'Втори телефон', 'mtunicredit' ) ] = $phone2;
		}

		$rows[ __( 'Съобщение', 'mtunicredit' ) ] = mtuc_get_process2_confirmation_message();
	}

	if (
		MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL === $audience
		&& function_exists( 'mtuc_get_order_diagnostic_admin_rows' )
	) {
		$rows = array_merge( $rows, mtuc_get_order_diagnostic_admin_rows( $order ) );
	}

	return $rows;
}

/**
 * Credit meta rows for the Woo admin order financing panel (EGN excluded).
 *
 * @param WC_Order $order Order instance.
 * @return array<string, string>
 */
function mtuc_get_admin_order_credit_meta_rows( WC_Order $order ): array {
	return mtuc_get_order_credit_meta_rows( $order, MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL );
}
