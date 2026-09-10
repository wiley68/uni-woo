<?php
/**
 * Durable Woo order banking process identity (AUD-WOO-014).
 *
 * Once established for order X, Process 1 vs Process 2 is authoritative.
 * Current shop uni_proces may choose only for a genuinely fresh financing order.
 * Existing/incomplete/conflicting orders fail closed — never guess from shop config.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Order meta: canonical banking process — 1 or 2. */
const MTUC_ORDER_META_PROCESS = '_mtuc_process';

/** Order meta: idempotent support note for unresolved process identity. */
const MTUC_ORDER_META_PROCESS_IDENTITY_NOTE = '_mtuc_process_identity_note';

/**
 * Collect durable Process 1 / Process 2 / lifecycle evidence (no side effects).
 *
 * @param WC_Order $order Order instance.
 * @return array{
 *   canonical: int|null,
 *   p1: list<string>,
 *   p2: list<string>,
 *   lifecycle: list<string>
 * }
 */
function mtuc_detect_order_process_evidence( WC_Order $order ): array {
	$prefix = defined( 'MTUC_ORDER_META_PREFIX' ) ? MTUC_ORDER_META_PREFIX : '_mtuc_';
	$p1     = array();
	$p2     = array();
	$life   = array();

	$canonical = (int) $order->get_meta( MTUC_ORDER_META_PROCESS );
	$canonical = ( 1 === $canonical || 2 === $canonical ) ? $canonical : null;

	if ( defined( 'MTUC_ORDER_META_PROCESS2' ) && 1 === (int) $order->get_meta( MTUC_ORDER_META_PROCESS2 ) ) {
		$p2[] = 'legacy_process2_marker';
	}

	$bank = '';
	if ( defined( 'MTUC_ORDER_META_BANK_STATUS' ) ) {
		$bank = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ) );
	}
	if ( '' !== $bank ) {
		$life[] = 'bank_status';
		if ( defined( 'MTUC_BANK_STATUS_SENT_PROCESS1' ) && MTUC_BANK_STATUS_SENT_PROCESS1 === $bank ) {
			$p1[] = 'bank_sent_process1';
		}
		if ( defined( 'MTUC_BANK_STATUS_SENT_PROCESS2' ) && MTUC_BANK_STATUS_SENT_PROCESS2 === $bank ) {
			$p2[] = 'bank_sent_process2';
		}
	}

	$session = '';
	if ( defined( 'MTUC_ORDER_META_SMARTUCF_SESSION_ID' ) ) {
		$session = trim( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_SESSION_ID ) );
	}
	if ( '' === $session ) {
		$session = trim( (string) $order->get_meta( $prefix . 'smartucf_session_id' ) );
	}
	if ( '' !== $session ) {
		$p1[]   = 'smartucf_session';
		$life[] = 'smartucf_session';
	}

	if ( defined( 'MTUC_ORDER_META_SMARTUCF_REDIRECT_URL' ) ) {
		$redirect = trim( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL ) );
		if ( '' !== $redirect ) {
			// Redirect URL alone is lifecycle pollution evidence, not definitive Process 1
			// identity (stale SmartUCF meta must not mint a clean P1 path).
			$life[] = 'smartucf_redirect';
		}
	}

	if ( defined( 'MTUC_ORDER_META_SMARTUCF_START_OUTCOME' ) ) {
		$outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ) );
		if ( in_array( $outcome, array( 'confirmed', 'unknown', 'missing' ), true ) ) {
			$p1[]   = 'smartucf_start_outcome';
			$life[] = 'smartucf_start_outcome';
		}
	}

	if ( function_exists( 'mtuc_get_smartucf_p1_claim' ) && function_exists( 'get_option' ) ) {
		$claim = mtuc_get_smartucf_p1_claim( (int) $order->get_id() );
		if ( is_array( $claim ) && ! empty( $claim['state'] ) ) {
			$p1[]   = 'smartucf_claim';
			$life[] = 'smartucf_claim';
		}
	}

	$cp_id = (int) $order->get_meta( $prefix . 'cp_order_id' );
	if ( $cp_id > 0 ) {
		$life[] = 'cp_order_id';
	}

	if ( defined( 'MTUC_ORDER_META_CP_SHOP_ORDER_ID' ) ) {
		$shop_oid = trim( (string) $order->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID ) );
		if ( '' !== $shop_oid ) {
			$life[] = 'cp_shop_order_id';
		}
	}

	if ( defined( 'MTUC_ORDER_META_CP_CREATE_OUTCOME' ) ) {
		$cp_outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ) );
		if ( '' !== $cp_outcome ) {
			$life[] = 'cp_create_outcome';
		}
	}

	$p1 = array_values( array_unique( $p1 ) );
	$p2 = array_values( array_unique( $p2 ) );
	$life = array_values( array_unique( $life ) );

	return array(
		'canonical' => $canonical,
		'p1'        => $p1,
		'p2'        => $p2,
		'lifecycle' => $life,
	);
}

/**
 * Classify order process identity without consulting shop configuration.
 *
 * status:
 * - fresh: no process-discriminating evidence and no prior banking lifecycle
 * - clean: unambiguously Process 1 or 2
 * - unknown: prior financing/banking lifecycle without safe process identity
 * - conflict: incompatible P1 and P2 durable evidence
 *
 * @param WC_Order $order Order instance.
 * @return array{status:string,process:int|null,evidence:array<string,mixed>}
 */
function mtuc_classify_order_process_identity( WC_Order $order ): array {
	$ev       = mtuc_detect_order_process_evidence( $order );
	$has_p1   = ! empty( $ev['p1'] );
	$has_p2   = ! empty( $ev['p2'] );
	$canonical = $ev['canonical'];

	// Explicit conflict rules (not incidental if/else precedence).
	if ( $has_p1 && $has_p2 ) {
		return array(
			'status'   => 'conflict',
			'process'  => null,
			'evidence' => $ev,
		);
	}

	if ( null !== $canonical && $has_p2 && 1 === $canonical ) {
		return array(
			'status'   => 'conflict',
			'process'  => null,
			'evidence' => $ev,
		);
	}

	if ( null !== $canonical && $has_p1 && 2 === $canonical ) {
		return array(
			'status'   => 'conflict',
			'process'  => null,
			'evidence' => $ev,
		);
	}

	if ( $has_p2 ) {
		return array(
			'status'   => 'clean',
			'process'  => 2,
			'evidence' => $ev,
		);
	}

	if ( $has_p1 ) {
		return array(
			'status'   => 'clean',
			'process'  => 1,
			'evidence' => $ev,
		);
	}

	if ( null !== $canonical ) {
		return array(
			'status'   => 'clean',
			'process'  => $canonical,
			'evidence' => $ev,
		);
	}

	if ( ! empty( $ev['lifecycle'] ) ) {
		return array(
			'status'   => 'unknown',
			'process'  => null,
			'evidence' => $ev,
		);
	}

	return array(
		'status'   => 'fresh',
		'process'  => null,
		'evidence' => $ev,
	);
}

/**
 * Read durable process identity when clean (1, 2) or null (fresh/unknown/conflict).
 *
 * @param WC_Order $order Order instance.
 * @return int|null
 */
function mtuc_get_order_process_identity( WC_Order $order ) {
	$classified = mtuc_classify_order_process_identity( $order );
	if ( 'clean' !== $classified['status'] ) {
		return null;
	}

	return (int) $classified['process'];
}

/**
 * Whether the order is a genuinely fresh financing order for shop process selection.
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_order_is_pristine_for_process_selection( WC_Order $order ): bool {
	$classified = mtuc_classify_order_process_identity( $order );
	return 'fresh' === $classified['status'];
}

/**
 * Persist immutable process identity. Refuses switch and refuses writes under conflict.
 *
 * @param WC_Order $order   Order instance.
 * @param int      $process 1 or 2.
 * @return true|WP_Error
 */
function mtuc_persist_order_process_identity( WC_Order $order, int $process ) {
	if ( 1 !== $process && 2 !== $process ) {
		return new WP_Error(
			'mtuc_invalid_process_identity',
			__( 'Невалидна банкова процес идентичност.', 'mtunicredit' )
		);
	}

	$classified = mtuc_classify_order_process_identity( $order );
	if ( 'conflict' === $classified['status'] ) {
		return mtuc_fail_closed_process_identity(
			$order,
			'mtuc_process_identity_conflict',
			__( 'Банковата процес идентичност на поръчката е противоречива и изисква ръчна проверка.', 'mtunicredit' )
		);
	}

	$existing = ( 'clean' === $classified['status'] ) ? (int) $classified['process'] : null;
	if ( null !== $existing && $existing !== $process ) {
		return new WP_Error(
			'mtuc_process_identity_conflict',
			__( 'Банковата процес идентичност на поръчката е фиксирана и не може да бъде сменена.', 'mtunicredit' )
		);
	}

	$order->update_meta_data( MTUC_ORDER_META_PROCESS, $process );

	if ( 2 === $process && defined( 'MTUC_ORDER_META_PROCESS2' ) ) {
		$order->update_meta_data( MTUC_ORDER_META_PROCESS2, 1 );
	}

	return true;
}

/**
 * Ensure durable process identity: infer/canonicalize legacy, or select from shop only when fresh.
 *
 * @param WC_Order             $order Order instance.
 * @param array<string, mixed> $shop  Current shop data (advisory for fresh orders only).
 * @return int|WP_Error Process 1 or 2.
 */
function mtuc_ensure_order_process_identity( WC_Order $order, array $shop ) {
	$classified = mtuc_classify_order_process_identity( $order );

	if ( 'conflict' === $classified['status'] ) {
		return mtuc_fail_closed_process_identity(
			$order,
			'mtuc_process_identity_conflict',
			__( 'Банковата процес идентичност на поръчката е противоречива. Автоматичното банково продължение е спряно. Свържете се с поддръжката и не подавайте отново заявка за финансиране.', 'mtunicredit' )
		);
	}

	if ( 'unknown' === $classified['status'] ) {
		return mtuc_fail_closed_process_identity(
			$order,
			'mtuc_process_identity_unknown',
			__( 'Банковата процес идентичност на поръчката не може да бъде установена безопасно. Автоматичното банково продължение е спряно. Свържете се с поддръжката и не подавайте отново заявка за финансиране.', 'mtunicredit' )
		);
	}

	if ( 'clean' === $classified['status'] ) {
		$process   = (int) $classified['process'];
		$persisted = mtuc_persist_order_process_identity( $order, $process );
		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}
		return $process;
	}

	// Fresh only: current shop may choose.
	$process = ( function_exists( 'mtuc_is_shop_process_2' ) && mtuc_is_shop_process_2( $shop ) ) ? 2 : 1;
	$result  = mtuc_persist_order_process_identity( $order, $process );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $process;
}

/**
 * Authoritative process for banking paths.
 *
 * @param WC_Order             $order Order instance.
 * @param array<string, mixed> $shop  Shop data for brand-new (fresh) identity only.
 * @return int|WP_Error
 */
function mtuc_resolve_order_process_for_banking( WC_Order $order, array $shop ) {
	return mtuc_ensure_order_process_identity( $order, $shop );
}

/**
 * Fail closed for unresolved/conflicting process identity (local consistency, not bank failure).
 *
 * @param WC_Order $order   Order instance.
 * @param string   $code    Error code.
 * @param string   $message Customer/support-safe message.
 * @return WP_Error
 */
function mtuc_fail_closed_process_identity( WC_Order $order, string $code, string $message ) {
	$error = new WP_Error( $code, $message );

	if ( function_exists( 'mtuc_record_order_financing_diagnostic' ) ) {
		mtuc_record_order_financing_diagnostic( $order, $error, 'configuration' );
	}

	$note = sprintf(
		/* translators: 1: Woo order ID, 2: error code */
		__( 'MTUC process identity unresolved (order #%1$s, %2$s): automatic banking continuation prohibited; manual/support review required.', 'mtunicredit' ),
		(string) $order->get_id(),
		$code
	);
	mtuc_maybe_add_process_identity_guard_note( $order, $code, $note );

	return $error;
}

/**
 * Add at most one process-identity support note per unresolved code.
 *
 * @param WC_Order $order Order instance.
 * @param string   $code  Error code.
 * @param string   $note  Note text (no sensitive fields).
 * @return void
 */
function mtuc_maybe_add_process_identity_guard_note( WC_Order $order, string $code, string $note ): void {
	$code = sanitize_key( $code );
	if ( '' === $code || '' === trim( $note ) ) {
		return;
	}

	$marker = (string) $order->get_meta( MTUC_ORDER_META_PROCESS_IDENTITY_NOTE );
	if ( $code === $marker ) {
		return;
	}

	$order->update_meta_data( MTUC_ORDER_META_PROCESS_IDENTITY_NOTE, $code );
	if ( method_exists( $order, 'add_order_note' ) ) {
		$order->add_order_note( $note );
	}
}

/**
 * Persist process identity for a new financing commit before CP shop-order lifecycle evidence.
 *
 * Call only while the order is still eligible for fresh shop selection, or already clean.
 * Must run before mtuc_assign_cp_shop_order_id() (AUD-WOO-014 Pass 3).
 *
 * @param WC_Order $order Order instance.
 * @return int|WP_Error Process 1 or 2.
 */
function mtuc_initialize_order_process_before_financing_commit( WC_Order $order ) {
	if ( ! function_exists( 'mtuc_get_shop_data' ) ) {
		return mtuc_fail_closed_process_identity(
			$order,
			'mtuc_process_identity_unknown',
			__( 'Банковата процес идентичност не може да бъде установена: липсват данни за магазина.', 'mtunicredit' )
		);
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return mtuc_fail_closed_process_identity(
			$order,
			'mtuc_process_identity_unknown',
			__( 'Банковата процес идентичност не може да бъде установена: липсват данни за магазина.', 'mtunicredit' )
		);
	}

	$process = mtuc_ensure_order_process_identity( $order, $shop );
	if ( is_wp_error( $process ) ) {
		return $process;
	}

	$order->save();

	$persisted = (int) $order->get_meta( MTUC_ORDER_META_PROCESS );
	if ( $persisted !== (int) $process ) {
		return mtuc_fail_closed_process_identity(
			$order,
			'mtuc_process_identity_unknown',
			__( 'Банковата процес идентичност не беше записана устойчиво преди банковата операция.', 'mtunicredit' )
		);
	}

	return (int) $process;
}

/**
 * Whether the order is durably Process 2 (clean identity only).
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_order_has_process2_identity( WC_Order $order ): bool {
	return 2 === mtuc_get_order_process_identity( $order );
}

/**
 * Whether local evidence proves Process 2 CP completion for callback confirmation.
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_order_has_process2_completion_evidence( WC_Order $order ): bool {
	if ( ! mtuc_order_has_process2_identity( $order ) ) {
		return false;
	}

	$prefix = defined( 'MTUC_ORDER_META_PREFIX' ) ? MTUC_ORDER_META_PREFIX : '_mtuc_';
	$cp_id  = (int) $order->get_meta( $prefix . 'cp_order_id' );
	if ( $cp_id <= 0 ) {
		return false;
	}

	$outcome = '';
	if ( defined( 'MTUC_ORDER_META_CP_CREATE_OUTCOME' ) ) {
		$outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ) );
	}

	// Legacy-compatible: positive CP id with empty outcome counts as completion evidence.
	// Explicit "unknown" remains ambiguous and is rejected.
	if ( '' !== $outcome && 'created' !== $outcome ) {
		return false;
	}

	return true;
}
