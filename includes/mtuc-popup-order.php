<?php
/**
 * Product popup — WooCommerce order creation (step 1).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Payment method ID for popup-created orders. */
const MTUC_PAYMENT_GATEWAY_ID = 'mtunicredit';

/** Order meta: bank submission status key. */
const MTUC_ORDER_META_BANK_STATUS = '_mtuc_bank_status';

/** Order meta: show bank-unavailable notice once on thank-you page. */
const MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE = '_mtuc_bank_unavailable_notice';

/** Order meta: SmartUCF browser redirect URL (checkout thank-you hop). */
const MTUC_ORDER_META_SMARTUCF_REDIRECT_URL = '_mtuc_smartucf_redirect_url';

/** Order meta: checkout thank-you already redirected the customer to the bank. */
const MTUC_ORDER_META_BANK_REDIRECT_DISPATCHED = '_mtuc_bank_redirect_dispatched';

/** Order meta: leasing order emails dispatched manually (pending without status transition). */
const MTUC_ORDER_META_LEASING_NOTIFICATIONS_SENT = '_mtuc_leasing_notifications_sent';

/** Order meta prefix for credit calculation snapshot. */
const MTUC_ORDER_META_PREFIX = '_mtuc_';

/** Bank status: Process 1 — successfully sent to the bank (CP + SmartUCF). */
const MTUC_BANK_STATUS_SENT_PROCESS1 = 'bank_sent_process1';

/** Bank status: Process 2 — successfully sent to the bank (CP only). */
const MTUC_BANK_STATUS_SENT_PROCESS2 = 'bank_sent_process2';

/** Bank status: Process 2 — bank submission failed at CP. */
const MTUC_BANK_STATUS_SEND_FAILED = 'bank_send_failed';

/** Bank status: Process 1 — bank submission failed at CP. */
const MTUC_BANK_STATUS_SEND_FAILED_CP = 'bank_send_failed_cp';

/** Bank status: Process 1 — CP succeeded but SmartUCF failed. */
const MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF = 'bank_send_failed_smartucf';

/** Order meta: Process 2 uni_email shop notification sent. */
const MTUC_ORDER_META_PROCESS2_UNI_EMAIL_SENT = '_mtuc_process2_uni_email_sent';

/** Order meta: order was submitted via Process 2 (no SmartUCF). */
const MTUC_ORDER_META_PROCESS2 = '_mtuc_process2';

/** Order meta: durable financing operation token (AUD-WOO-006). */
const MTUC_ORDER_META_OPERATION_TOKEN = '_mtuc_operation_token';

/** Order meta: deterministic pre-create recovery reference (AUD-WOO-018-F02). */
const MTUC_ORDER_META_CREATION_REF = '_mtuc_creation_ref';

/** Order meta: popup order initialization state (AUD-WOO-018-F02). */
const MTUC_ORDER_META_POPUP_INIT_STATE = '_mtuc_popup_init_state';

/** Popup init: order bound but contents not yet authoritative. */
const MTUC_POPUP_INIT_INITIALIZING = 'initializing';

/** Popup init: order contents fully durable; remote submit allowed. */
const MTUC_POPUP_INIT_COMPLETE = 'complete';

/** Order meta: cart line snapshot for incomplete-order rebuild. */
const MTUC_ORDER_META_CART_INIT_SNAPSHOT = '_mtuc_cart_init_snapshot';

/** Order meta: cart fingerprint at initialization start. */
const MTUC_ORDER_META_CART_INIT_FINGERPRINT = '_mtuc_cart_init_fingerprint';

/** Order meta: immutable Product financing operation snapshot (AUD-WOO-018-F02). */
const MTUC_ORDER_META_PRODUCT_OP_SNAPSHOT = '_mtuc_product_op_snapshot';

/** Order meta: immutable Cart financing operation snapshot (AUD-WOO-018-F02). */
const MTUC_ORDER_META_CART_OP_SNAPSHOT = '_mtuc_cart_op_snapshot';

/** Order meta: persisted external CP shop order_id (AUD-WOO-007). */
const MTUC_ORDER_META_CP_SHOP_ORDER_ID = '_mtuc_cp_shop_order_id';

/** Order meta: cart emptied once after accepted financing. */
const MTUC_ORDER_META_CART_EMPTIED = '_mtuc_cart_emptied';

/** CP external shop order_id maximum length. */
const MTUC_CP_SHOP_ORDER_ID_MAX_LEN = 13;

/**
 * Human-readable bank status labels for module-managed submission outcomes.
 *
 * External statuses pushed later from CP/SmartUCF use bank_status_label meta as-is.
 *
 * @return array<string, string>
 */
function mtuc_get_bank_status_labels(): array {
	return array(
		MTUC_BANK_STATUS_SENT_PROCESS1        => __( 'Изпратен Банка - Процес 1', 'mtunicredit' ),
		MTUC_BANK_STATUS_SENT_PROCESS2        => __( 'Изпратен Банка - Процес 2', 'mtunicredit' ),
		MTUC_BANK_STATUS_SEND_FAILED          => __( 'Неуспешно изпратен Банка', 'mtunicredit' ),
		MTUC_BANK_STATUS_SEND_FAILED_CP       => __( 'Неуспешно изпратен Банка - КП', 'mtunicredit' ),
		MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF => __( 'Неуспешно изпратен Банка - SmartUCF', 'mtunicredit' ),
	);
}

/**
 * Resolve a single bank status label by key.
 *
 * @param string $status_key Bank status key.
 * @return string
 */
function mtuc_get_bank_status_label( string $status_key ): string {
	$labels = mtuc_get_bank_status_labels();

	return $labels[ $status_key ] ?? $status_key;
}

/**
 * CP order status fields aligned with WC bank status meta.
 *
 * @param string $bank_status_key Status key (see MTUC_BANK_STATUS_*).
 * @return array{status: string, status_id: string}
 */
function mtuc_get_cp_order_status_payload( string $bank_status_key, ?string $status_label = null ): array {
	$label = null !== $status_label && '' !== trim( $status_label )
		? trim( $status_label )
		: mtuc_get_bank_status_label( $bank_status_key );

	return array(
		'status'    => $label,
		'status_id' => $bank_status_key,
	);
}

/**
 * Status fields for CP order create (POST /orders).
 *
 * Process 2: final success status on create.
 * Process 1: omit shop-claimed success — CP defaults to cp_sent; Woo patches
 * bank_sent_process1 only after SmartUCF success (AUD-WOO-008).
 *
 * When $order is provided, durable order process identity is authoritative
 * (AUD-WOO-014). Shop config is used only when identity is not yet established.
 *
 * @param array<string, mixed> $shop  Shop `data` object from CP.
 * @param WC_Order|null        $order Optional Woo order for durable identity.
 * @return array{status: string, status_id: string}|null Null when Process 1 (omit fields).
 */
function mtuc_get_cp_order_create_status_payload( array $shop, $order = null ): ?array {
	if ( $order instanceof WC_Order && function_exists( 'mtuc_classify_order_process_identity' ) ) {
		$classified = mtuc_classify_order_process_identity( $order );
		// Never consult shop for existing unknown/conflict orders (AUD-WOO-014 Pass 2).
		if ( in_array( $classified['status'], array( 'unknown', 'conflict' ), true ) ) {
			return null;
		}
		if ( 'clean' === $classified['status'] ) {
			if ( 2 === (int) $classified['process'] ) {
				return mtuc_get_cp_order_status_payload( MTUC_BANK_STATUS_SENT_PROCESS2 );
			}
			return null;
		}
		// fresh: shop may choose below.
	} elseif ( $order instanceof WC_Order && function_exists( 'mtuc_get_order_process_identity' ) ) {
		$identity = mtuc_get_order_process_identity( $order );
		if ( 2 === $identity ) {
			return mtuc_get_cp_order_status_payload( MTUC_BANK_STATUS_SENT_PROCESS2 );
		}
		if ( 1 === $identity ) {
			return null;
		}
	}

	if ( mtuc_is_shop_process_2( $shop ) ) {
		return mtuc_get_cp_order_status_payload( MTUC_BANK_STATUS_SENT_PROCESS2 );
	}

	return null;
}

/**
 * External shop order_id sent to CP (max 13 chars).
 *
 * New orders use persisted Woo internal numeric ID (AUD-WOO-007).
 * Legacy orders without meta fall back to truncated display order number.
 *
 * @param WC_Order $order WooCommerce order.
 * @return string
 */
function mtuc_get_cp_shop_order_id( WC_Order $order ): string {
	$persisted = (string) $order->get_meta( MTUC_ORDER_META_CP_SHOP_ORDER_ID );
	if ( '' !== $persisted ) {
		return $persisted;
	}

	$order_number = (string) $order->get_order_number();
	if ( strlen( $order_number ) > MTUC_CP_SHOP_ORDER_ID_MAX_LEN ) {
		$order_number = substr( $order_number, 0, MTUC_CP_SHOP_ORDER_ID_MAX_LEN );
	}

	return $order_number;
}

/**
 * Find WooCommerce order by CP shop order_id (exact persisted meta, HPOS-safe).
 *
 * @param string $cp_order_id Order identifier sent to CP (max 13 chars).
 * @return WC_Order|null
 */
function mtuc_find_order_by_cp_order_id( string $cp_order_id ): ?WC_Order {
	$cp_order_id = trim( $cp_order_id );
	if ( '' === $cp_order_id || ! function_exists( 'wc_get_order' ) ) {
		return null;
	}

	if ( strlen( $cp_order_id ) > MTUC_CP_SHOP_ORDER_ID_MAX_LEN ) {
		$cp_order_id = substr( $cp_order_id, 0, MTUC_CP_SHOP_ORDER_ID_MAX_LEN );
	}

	if ( ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}

	$by_meta = wc_get_orders(
		array(
			'limit'      => 1,
			'meta_key'   => MTUC_ORDER_META_CP_SHOP_ORDER_ID,
			'meta_value' => $cp_order_id,
			'return'     => 'objects',
		)
	);

	if ( is_array( $by_meta ) ) {
		foreach ( $by_meta as $order ) {
			if ( $order instanceof WC_Order && mtuc_get_cp_shop_order_id( $order ) === $cp_order_id ) {
				return $order;
			}
		}
	}

	if ( ctype_digit( $cp_order_id ) ) {
		$order = wc_get_order( (int) $cp_order_id );
		if ( $order instanceof WC_Order && mtuc_get_cp_shop_order_id( $order ) === $cp_order_id ) {
			return $order;
		}
	}

	$orders = wc_get_orders(
		array(
			'limit'   => 50,
			'search'  => $cp_order_id,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		)
	);

	if ( ! is_array( $orders ) ) {
		return null;
	}

	foreach ( $orders as $order ) {
		if ( $order instanceof WC_Order && mtuc_get_cp_shop_order_id( $order ) === $cp_order_id ) {
			return $order;
		}
	}

	return null;
}

/**
 * Sync CP order status with WooCommerce bank status meta.
 *
 * @param WC_Order $order           WooCommerce order.
 * @param string   $bank_status_key Status key (see MTUC_BANK_STATUS_*).
 * @return array<string, mixed>|WP_Error
 */
function mtuc_sync_cp_order_bank_status( WC_Order $order, string $bank_status_key, ?string $status_label = null ) {
	$cp_status = mtuc_get_cp_order_status_payload( $bank_status_key, $status_label );
	$label     = $cp_status['status'];

	$result = Mtuc_Cp_Api_Client::update_order_status(
		mtuc_get_cp_shop_order_id( $order ),
		$cp_status['status'],
		$cp_status['status_id'],
		$order->get_id()
	);

	if ( is_wp_error( $result ) ) {
		mtuc_mark_cp_status_sync_pending( $order, $bank_status_key, $label, $result );
	} else {
		mtuc_clear_cp_status_sync_pending( $order );
	}

	return $result;
}

/**
 * Register popup order AJAX and admin hooks.
 *
 * @return void
 */
function mtuc_register_popup_order_hooks(): void {
	add_action( 'wp_ajax_mtuc_popup_submit', 'mtuc_ajax_popup_submit' );
	add_action( 'wp_ajax_nopriv_mtuc_popup_submit', 'mtuc_ajax_popup_submit' );
	add_action( 'add_meta_boxes', 'mtuc_register_admin_order_credit_meta_box', 20, 0 );
	add_filter( 'manage_edit-shop_order_columns', 'mtuc_add_orders_list_bank_status_column' );
	add_action( 'manage_shop_order_posts_custom_column', 'mtuc_render_orders_list_bank_status_column', 10, 2 );
	add_filter( 'manage_woocommerce_page_wc-orders_columns', 'mtuc_add_orders_list_bank_status_column' );
	add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'mtuc_render_orders_list_bank_status_column', 10, 2 );
	add_filter( 'woocommerce_thankyou_order_received_text', 'mtuc_filter_thankyou_text_bank_unavailable', 20, 2 );
	add_action( 'woocommerce_order_details_after_order_table', 'mtuc_render_thankyou_process2_credit_section', 10, 1 );
	add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_thankyou_styles' );
	add_action( 'woocommerce_email_after_order_table', 'mtuc_email_after_order_table_credit_details', 15, 4 );
}

/**
 * Styles for bank-unavailable notice on the order-received page.
 *
 * @return void
 */
function mtuc_enqueue_thankyou_styles(): void {
	if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
		return;
	}

	$css_file = MTUC_PLUGIN_DIR . '/css/mtuc-thankyou.css';

	wp_enqueue_style(
		'mtuc-thankyou',
		MTUC_CSS_URI . '/mtuc-thankyou.css',
		array(),
		file_exists( $css_file ) ? (string) filemtime( $css_file ) : MTUC_VERSION
	);
}

/**
 * Whether a WooCommerce order was submitted via Process 2.
 *
 * Uses durable order process identity (AUD-WOO-014). Does not consult live shop config.
 *
 * @param WC_Order $order Order instance.
 * @return bool
 */
function mtuc_is_process2_order( WC_Order $order ): bool {
	if ( function_exists( 'mtuc_order_has_process2_identity' ) ) {
		return mtuc_order_has_process2_identity( $order );
	}

	return 1 === (int) $order->get_meta( MTUC_ORDER_META_PROCESS2 );
}

/**
 * Validate popup step-2 customer payload from POST.
 *
 * @param array<string, mixed> $post      Raw POST.
 * @param bool                 $process2  Whether Process 2 extra fields are required.
 * @return array<string, string>|WP_Error
 */
function mtuc_validate_popup_customer_payload( array $post, bool $process2 = false ) {
	$first_name = isset( $post['first_name'] ) ? sanitize_text_field( wp_unslash( $post['first_name'] ) ) : '';
	$last_name  = isset( $post['last_name'] ) ? sanitize_text_field( wp_unslash( $post['last_name'] ) ) : '';
	$address    = isset( $post['address'] ) ? sanitize_text_field( wp_unslash( $post['address'] ) ) : '';
	$phone      = isset( $post['phone'] ) ? sanitize_text_field( wp_unslash( $post['phone'] ) ) : '';
	$email      = isset( $post['email'] ) ? sanitize_email( wp_unslash( $post['email'] ) ) : '';

	$phone = preg_replace( '/[^0-9+() -]/', '', $phone );
	$phone = is_string( $phone ) ? trim( $phone ) : '';

	if ( '' === $first_name ) {
		return new WP_Error( 'mtuc_missing_first_name', __( 'Полето „Име“ е задължително.', 'mtunicredit' ) );
	}
	if ( '' === $last_name ) {
		return new WP_Error( 'mtuc_missing_last_name', __( 'Полето „Фамилия“ е задължително.', 'mtunicredit' ) );
	}
	if ( '' === $address ) {
		return new WP_Error( 'mtuc_missing_address', __( 'Полето „Адрес“ е задължително.', 'mtunicredit' ) );
	}
	if ( ! mtuc_validate_customer_phone( $phone ) ) {
		return new WP_Error( 'mtuc_invalid_phone', __( 'Въведете валиден телефонен номер.', 'mtunicredit' ) );
	}
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'mtuc_invalid_email', __( 'Въведете валиден e-mail адрес.', 'mtunicredit' ) );
	}

	$customer = array(
		'first_name' => $first_name,
		'last_name'  => $last_name,
		'address'    => $address,
		'phone'      => $phone,
		'email'      => $email,
	);

	if ( $process2 ) {
		$egn    = isset( $post['egn'] ) ? mtuc_sanitize_egn( (string) wp_unslash( $post['egn'] ) ) : '';
		$phone2 = isset( $post['phone2'] ) ? sanitize_text_field( wp_unslash( $post['phone2'] ) ) : '';
		$phone2 = preg_replace( '/[^0-9+() -]/', '', $phone2 );
		$phone2 = is_string( $phone2 ) ? trim( $phone2 ) : '';

		if ( '' === $egn ) {
			return new WP_Error( 'mtuc_missing_egn', __( 'Полето „ЕГН“ е задължително.', 'mtunicredit' ) );
		}
		if ( ! mtuc_validate_bulgarian_egn( $egn ) ) {
			return new WP_Error( 'mtuc_invalid_egn', __( 'Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).', 'mtunicredit' ) );
		}
		if ( ! mtuc_validate_customer_phone( $phone2 ) ) {
			return new WP_Error( 'mtuc_invalid_phone2', __( 'Въведете валиден втори телефонен номер.', 'mtunicredit' ) );
		}

		$customer['egn']     = $egn;
		$customer['phone2']  = $phone2;
	}

	return $customer;
}

/**
 * Validate Process 2-only fields from POST (checkout).
 *
 * @param array<string, mixed> $post Raw POST.
 * @return array{egn:string,phone2:string}|WP_Error
 */
function mtuc_validate_process2_fields_from_post( array $post ) {
	$egn    = isset( $post['mtuc_egn'] ) ? mtuc_sanitize_egn( (string) wp_unslash( $post['mtuc_egn'] ) ) : '';
	$phone2 = isset( $post['mtuc_phone2'] ) ? sanitize_text_field( wp_unslash( $post['mtuc_phone2'] ) ) : '';
	$phone2 = preg_replace( '/[^0-9+() -]/', '', $phone2 );
	$phone2 = is_string( $phone2 ) ? trim( $phone2 ) : '';

	if ( '' === $egn ) {
		return new WP_Error( 'mtuc_missing_egn', __( 'Полето „ЕГН“ е задължително.', 'mtunicredit' ) );
	}
	if ( ! mtuc_validate_bulgarian_egn( $egn ) ) {
		return new WP_Error( 'mtuc_invalid_egn', __( 'Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).', 'mtunicredit' ) );
	}
	if ( ! mtuc_validate_customer_phone( $phone2 ) ) {
		return new WP_Error( 'mtuc_invalid_phone2', __( 'Въведете валиден втори телефонен номер.', 'mtunicredit' ) );
	}

	return array(
		'egn'    => $egn,
		'phone2' => $phone2,
	);
}

/**
 * Persist Process 2 customer fields on the order (not sent to CP).
 *
 * @param WC_Order              $order    Order instance.
 * @param array<string, string> $customer Validated customer fields.
 * @return void
 */
function mtuc_save_order_process2_customer_meta( WC_Order $order, array $customer ): void {
	if ( function_exists( 'mtuc_persist_order_process_identity' ) ) {
		mtuc_persist_order_process_identity( $order, 2 );
	} else {
		$order->update_meta_data( MTUC_ORDER_META_PROCESS2, 1 );
	}

	if ( isset( $customer['egn'] ) && '' !== $customer['egn'] ) {
		$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'egn', (string) $customer['egn'] );
	}
	if ( isset( $customer['phone2'] ) && '' !== $customer['phone2'] ) {
		$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'phone2', (string) $customer['phone2'] );
	}
}

/**
 * WooCommerce session customer id for submit locks (guest cart session).
 *
 * @return string
 */
function mtuc_get_wc_session_customer_id(): string {
	if ( ! function_exists( 'WC' ) ) {
		return '';
	}

	$wc = WC();
	if ( ! is_object( $wc ) || ! property_exists( $wc, 'session' ) ) {
		return '';
	}

	$session = $wc->session;
	if ( ! is_object( $session ) || ! method_exists( $session, 'get_customer_id' ) ) {
		return '';
	}

	return (string) $session->get_customer_id();
}

/**
 * Build submit lock key to prevent duplicate submissions.
 *
 * @param int $product_id   Parent product ID.
 * @param int $variation_id Variation ID.
 * @return string
 */
function mtuc_build_popup_submit_lock_key( int $product_id, int $variation_id ): string {
	$session_part = mtuc_get_wc_session_customer_id();

	return md5(
		implode(
			'|',
			array(
				$session_part,
				(string) get_current_user_id(),
				(string) $product_id,
				(string) $variation_id,
			)
		)
	);
}

/**
 * Build submit lock key for cart popup submissions.
 *
 * @return string
 */
function mtuc_build_cart_popup_submit_lock_key(): string {
	$session_part = mtuc_get_wc_session_customer_id();
	$cart_hash    = '';

	if ( function_exists( 'WC' ) ) {
		$wc = WC();
		if ( is_object( $wc ) && $wc->cart instanceof WC_Cart ) {
			$cart_hash = (string) $wc->cart->get_cart_hash();
		}
	}

	return md5(
		implode(
			'|',
			array(
				'cart',
				$session_part,
				(string) get_current_user_id(),
				$cart_hash,
			)
		)
	);
}

/**
 * Acquire atomic owner-aware submit lock (AUD-WOO-018-F01).
 *
 * @param string $lock_key Lock key.
 * @return string|false Owner token on success; false when contended.
 */
function mtuc_acquire_popup_submit_lock( string $lock_key ) {
	$owner = mtuc_claim_submission_lock( $lock_key );
	if ( is_string( $owner ) && '' !== $owner ) {
		mtuc_arm_submission_lock_fence( $lock_key, $owner );
	}

	return $owner;
}

/**
 * Release submit lock only for the owning worker.
 *
 * @param string $lock_key Lock key.
 * @param string $owner    Owner token from mtuc_acquire_popup_submit_lock().
 * @return void
 */
function mtuc_release_popup_submit_lock( string $lock_key, string $owner = '' ): void {
	mtuc_release_submission_lock( $lock_key, $owner );
	mtuc_disarm_submission_lock_fence( $lock_key, $owner );
}

/**
 * Resolve extended billing/shipping address for logged-in customers.
 *
 * @param array<string, string> $customer Popup customer fields.
 * @return array{billing: array<string, string>, shipping: array<string, string>}
 */
function mtuc_resolve_popup_order_addresses( array $customer ): array {
	$billing = array(
		'first_name' => $customer['first_name'],
		'last_name'  => $customer['last_name'],
		'email'      => $customer['email'],
		'phone'      => $customer['phone'],
		'address_1'  => $customer['address'],
		'address_2'  => '',
		'city'       => '',
		'state'      => '',
		'postcode'   => '',
		'country'    => '',
	);

	$shipping = $billing;

	if ( is_user_logged_in() && function_exists( 'wc_get_customer' ) ) {
		$wc_customer = wc_get_customer( get_current_user_id() );
		if ( $wc_customer instanceof WC_Customer ) {
			$billing['country'] = (string) $wc_customer->get_billing_country();

			$shipping['first_name'] = (string) $wc_customer->get_shipping_first_name();
			if ( '' === $shipping['first_name'] ) {
				$shipping['first_name'] = $customer['first_name'];
			}
			$shipping['last_name'] = (string) $wc_customer->get_shipping_last_name();
			if ( '' === $shipping['last_name'] ) {
				$shipping['last_name'] = $customer['last_name'];
			}
			$shipping['address_1'] = (string) $wc_customer->get_shipping_address_1();
			$shipping['address_2'] = (string) $wc_customer->get_shipping_address_2();
			$shipping['city']      = (string) $wc_customer->get_shipping_city();
			$shipping['state']     = (string) $wc_customer->get_shipping_state();
			$shipping['postcode']  = (string) $wc_customer->get_shipping_postcode();
			$shipping['country']   = (string) $wc_customer->get_shipping_country();
			$shipping['email']     = $customer['email'];
			$shipping['phone']     = $customer['phone'];

			if ( '' === $shipping['address_1'] ) {
				$shipping['address_1'] = $customer['address'];
				$shipping['address_2'] = '';
				$shipping['city']      = '';
				$shipping['state']     = '';
				$shipping['postcode']  = '';
			}
			if ( '' === $shipping['country'] ) {
				$shipping['country'] = $billing['country'];
			}
		}
	}

	if ( '' === $billing['country'] && function_exists( 'wc_get_base_location' ) ) {
		$base                = wc_get_base_location();
		$billing['country']  = isset( $base['country'] ) ? (string) $base['country'] : 'BG';
		$shipping['country'] = '' !== $shipping['country'] ? $shipping['country'] : $billing['country'];
	}

	return array(
		'billing'  => $billing,
		'shipping' => $shipping,
	);
}

/**
 * Order grand total including taxes, shipping and fees.
 *
 * Alias of the canonical financeable order amount.
 *
 * @param WC_Order $order WooCommerce order.
 * @return float
 */
function mtuc_get_order_total_inc_tax( WC_Order $order ): float {
	return mtuc_get_canonical_financeable_order_total( $order );
}

/**
 * Line item total including tax.
 *
 * @param WC_Order_Item_Product $item Order line item.
 * @return float
 */
function mtuc_get_order_item_line_total_inc_tax( WC_Order_Item_Product $item ): float {
	return round( (float) $item->get_total() + (float) $item->get_total_tax(), 2 );
}

/**
 * Line item unit price including tax.
 *
 * @param WC_Order_Item_Product $item Order line item.
 * @return float
 */
function mtuc_get_order_item_unit_price_inc_tax( WC_Order_Item_Product $item ): float {
	$quantity = max( 1, (int) $item->get_quantity() );

	return round( mtuc_get_order_item_line_total_inc_tax( $item ) / $quantity, 2 );
}

/**
 * Convert a tax-inclusive line total to ex-tax amount for WC order storage.
 *
 * @param WC_Product    $product            Product instance.
 * @param int           $quantity           Line quantity.
 * @param float         $line_total_inc_tax Line total including tax.
 * @param WC_Order|null $order              Order for taxable location (optional).
 * @return float
 */
function mtuc_get_line_total_excluding_tax( WC_Product $product, int $quantity, float $line_total_inc_tax, ?WC_Order $order = null ): float {
	$line_total_inc_tax = round( max( 0.0, $line_total_inc_tax ), 2 );
	$quantity           = max( 1, $quantity );

	if ( ! $product->is_taxable() || ! class_exists( 'WC_Tax' ) ) {
		return $line_total_inc_tax;
	}

	$tax_rates = array();

	if ( $order instanceof WC_Order ) {
		$tax_location = $order->get_taxable_location();
		if ( is_array( $tax_location ) && ! empty( $tax_location['country'] ) ) {
			$tax_rates = WC_Tax::find_rates(
				array(
					'country'   => (string) $tax_location['country'],
					'state'     => (string) ( $tax_location['state'] ?? '' ),
					'postcode'  => (string) ( $tax_location['postcode'] ?? '' ),
					'city'      => (string) ( $tax_location['city'] ?? '' ),
					'tax_class' => $product->get_tax_class(),
				)
			);
		}
	}

	if ( empty( $tax_rates ) ) {
		$tax_rates = WC_Tax::get_rates( $product->get_tax_class() );
	}

	if ( empty( $tax_rates ) ) {
		if ( function_exists( 'wc_get_price_excluding_tax' ) && function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ) {
			return (float) wc_get_price_excluding_tax(
				$product,
				array(
					'qty'   => $quantity,
					'price' => $line_total_inc_tax / $quantity,
					'order' => $order,
				)
			);
		}

		return $line_total_inc_tax;
	}

	$taxes    = WC_Tax::calc_tax( $line_total_inc_tax, $tax_rates, true );
	$line_ex  = $line_total_inc_tax - array_sum( $taxes );
	$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

	return round( $line_ex, $decimals );
}

/**
 * Set one order line to a tax-inclusive line total (WC stores ex-tax + tax rows).
 *
 * @param WC_Order_Item_Product $item               Order line item.
 * @param WC_Product            $product            Product instance.
 * @param float                 $line_price_inc_tax Expected line total including tax.
 * @param WC_Order|null         $order              Order instance for tax location.
 * @return void
 */
function mtuc_sync_order_item_line_price( WC_Order_Item_Product $item, WC_Product $product, float $line_price_inc_tax, ?WC_Order $order = null ): void {
	$quantity = max( 1, (int) $item->get_quantity() );
	$line_ex  = mtuc_get_line_total_excluding_tax( $product, $quantity, $line_price_inc_tax, $order );

	$item->set_subtotal( $line_ex );
	$item->set_total( $line_ex );
	$item->save();
}

/**
 * Sync all cart lines to their tax-inclusive totals before calculate_totals().
 *
 * @param WC_Order                         $order      WooCommerce order.
 * @param array<int, array<string, mixed>> $cart_lines Cart line entries.
 * @return void
 */
function mtuc_sync_cart_order_line_prices( WC_Order $order, array $cart_lines ): void {
	$items = array_values( $order->get_items( 'line_item' ) );
	$count = min( count( $items ), count( $cart_lines ) );

	for ( $index = 0; $index < $count; $index++ ) {
		$item = $items[ $index ];
		$line = $cart_lines[ $index ];

		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}
		if ( ! isset( $line['product'] ) || ! $line['product'] instanceof WC_Product ) {
			continue;
		}

		$line_total = isset( $line['line_total'] ) ? (float) $line['line_total'] : 0.0;
		if ( $line_total <= 0 ) {
			continue;
		}

		mtuc_sync_order_item_line_price( $item, $line['product'], $line_total, $order );
	}
}

/**
 * Copy coupons, fees and chosen shipping from the current cart onto a popup order.
 *
 * Ensures the Woo order total matches the canonical financeable cart amount.
 *
 * @param WC_Order $order Target order.
 * @return void
 */
function mtuc_apply_current_cart_adjustments_to_order( WC_Order $order ): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	$cart = WC()->cart;

	foreach ( array_keys( $cart->get_coupons() ) as $code ) {
		$code = (string) $code;
		if ( '' === $code ) {
			continue;
		}
		$order->apply_coupon( $code );
	}

	foreach ( $cart->get_fees() as $fee ) {
		if ( ! is_object( $fee ) ) {
			continue;
		}

		$item = new WC_Order_Item_Fee();
		$item->set_name( isset( $fee->name ) ? (string) $fee->name : __( 'Такса', 'mtunicredit' ) );
		$item->set_amount( isset( $fee->amount ) ? (float) $fee->amount : 0.0 );
		$item->set_total( isset( $fee->total ) ? (float) $fee->total : 0.0 );
		$item->set_tax_class( isset( $fee->tax_class ) ? (string) $fee->tax_class : '' );
		$item->set_tax_status( ! empty( $fee->taxable ) ? 'taxable' : 'none' );

		if ( isset( $fee->tax_data ) && is_array( $fee->tax_data ) ) {
			$item->set_taxes(
				array(
					'total'    => $fee->tax_data,
					'subtotal' => $fee->tax_data,
				)
			);
		}

		$order->add_item( $item );
	}

	if ( ! WC()->shipping() ) {
		return;
	}

	$packages = WC()->shipping()->get_packages();
	$chosen   = ( WC()->session ) ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();

	foreach ( $packages as $index => $package ) {
		if ( empty( $chosen[ $index ] ) || empty( $package['rates'][ $chosen[ $index ] ] ) ) {
			continue;
		}

		$rate = $package['rates'][ $chosen[ $index ] ];
		if ( ! is_object( $rate ) ) {
			continue;
		}

		$item = new WC_Order_Item_Shipping();
		$item->set_method_title( method_exists( $rate, 'get_label' ) ? (string) $rate->get_label() : '' );
		$item->set_method_id( method_exists( $rate, 'get_method_id' ) ? (string) $rate->get_method_id() : '' );
		$item->set_instance_id( method_exists( $rate, 'get_instance_id' ) ? (int) $rate->get_instance_id() : 0 );
		$item->set_total( method_exists( $rate, 'get_cost' ) ? (float) $rate->get_cost() : 0.0 );

		if ( method_exists( $rate, 'get_taxes' ) ) {
			$item->set_taxes( array( 'total' => $rate->get_taxes() ) );
		}

		$order->add_item( $item );
	}
}

/**
 * Adjust the first line item total to match the calculator line price (incl. tax).
 *
 * @param WC_Order $order              Order instance.
 * @param float    $line_price_inc_tax Expected line total including tax.
 * @return void
 */
function mtuc_sync_order_line_price( WC_Order $order, float $line_price_inc_tax ): void {
	$items = $order->get_items( 'line_item' );
	if ( empty( $items ) ) {
		return;
	}

	$item = reset( $items );
	if ( ! $item instanceof WC_Order_Item_Product ) {
		return;
	}

	$product = $item->get_product();
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	mtuc_sync_order_item_line_price( $item, $product, $line_price_inc_tax, $order );
}

/**
 * Persist credit calculation snapshot on the order.
 *
 * @param WC_Order             $order      Order instance.
 * @param array<string, mixed> $calculation Calculation payload from mtuc_calculate_popup_credit().
 * @param array<string, mixed> $context    Extra context (product_id, variation_id, quantity).
 * @return void
 */
function mtuc_save_order_credit_meta( WC_Order $order, array $calculation, array $context ): void {
	$meta_map = array(
		'submission_source'   => (string) ( $context['submission_source'] ?? 'product_popup' ),
		'offer_type'          => (string) ( $calculation['popup_offer_type'] ?? '' ),
		'scheme_type'         => (string) ( $calculation['scheme_type'] ?? '' ),
		'scheme_key'          => (string) ( $calculation['scheme_key'] ?? '' ),
		'filter_id'           => (int) ( $calculation['filter_id'] ?? 0 ),
		'months'              => (int) ( $calculation['months'] ?? 0 ),
		'kop_code'            => (string) ( $calculation['kop_code'] ?? '' ),
		'price'               => (float) ( $calculation['price'] ?? 0 ),
		'parva'               => (float) ( $calculation['parva'] ?? 0 ),
		'loan_amount'         => (float) ( $calculation['loan_amount'] ?? 0 ),
		'monthly_installment' => (float) ( $calculation['monthly_installment'] ?? 0 ),
		'total_payable'       => (float) ( $calculation['total_payable'] ?? 0 ),
		'glp'                 => (float) ( $calculation['glp'] ?? 0 ),
		'gpr'                 => (float) ( $calculation['gpr'] ?? 0 ),
		'product_id'          => (int) ( $context['product_id'] ?? 0 ),
		'variation_id'        => (int) ( $context['variation_id'] ?? 0 ),
		'quantity'            => (int) ( $context['quantity'] ?? 1 ),
		'line_count'          => (int) ( $context['line_count'] ?? 0 ),
	);

	foreach ( $meta_map as $key => $value ) {
		$order->update_meta_data( MTUC_ORDER_META_PREFIX . $key, $value );
	}
}

/**
 * Update bank status meta and append an order note.
 *
 * @param WC_Order    $order       Order instance.
 * @param string      $status_key  Status key (see MTUC_BANK_STATUS_*).
 * @param string      $extra_note  Optional detail appended to the note.
 * @param string|null $status_label Optional human-readable label (e.g. from CP).
 * @return void
 */
function mtuc_update_order_bank_status( WC_Order $order, string $status_key, string $extra_note = '', ?string $status_label = null ): void {
	$label = null !== $status_label && '' !== trim( $status_label )
		? trim( $status_label )
		: mtuc_get_bank_status_label( $status_key );

	$current_key   = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ) );
	$current_label = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'bank_status_label' );

	// Identical duplicate updates are idempotent (no extra order note).
	if ( $current_key === sanitize_key( $status_key ) && $current_label === $label && '' === trim( $extra_note ) ) {
		return;
	}

	$order->update_meta_data( MTUC_ORDER_META_BANK_STATUS, $status_key );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'bank_status_label', $label );

	$note = sprintf(
		/* translators: %s: bank submission status label */
		__( 'Статус към банката: %s', 'mtunicredit' ),
		$label
	);
	if ( '' !== $extra_note ) {
		$note .= ' — ' . $extra_note;
	}

	$order->add_order_note( $note );
}

/**
 * Record a bank status on the order (meta, order note, optional CP sync).
 *
 * Native WooCommerce order status is merchant-controlled and is NOT changed here
 * (AUD-WOO-004). Optional `mark_failed` is accepted for backward compatibility
 * but ignored.
 *
 * @param WC_Order             $order      Order instance.
 * @param string               $status_key Bank status key.
 * @param array<string, mixed> $options    {
 *     @type string $extra_note       Optional detail appended to the order note.
 *     @type string $status_label     Optional human-readable label override.
 *     @type bool   $sync_cp          Whether to PATCH the status in CP.
 *     @type bool   $bank_unavailable Whether to show bank-unavailable thank-you notice.
 * }
 * @return void
 */
function mtuc_record_order_bank_status( WC_Order $order, string $status_key, array $options = array() ): void {
	$defaults = array(
		'extra_note'       => '',
		'status_label'     => null,
		'sync_cp'          => false,
		'bank_unavailable' => false,
	);
	$options  = array_merge( $defaults, $options );

	$label = null !== $options['status_label'] && '' !== trim( (string) $options['status_label'] )
		? trim( (string) $options['status_label'] )
		: mtuc_get_bank_status_label( $status_key );

	$order->update_meta_data( MTUC_ORDER_META_BANK_STATUS, $status_key );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'bank_status_label', $label );

	$note = sprintf(
		/* translators: %s: bank submission status label */
		__( 'Статус към банката: %s', 'mtunicredit' ),
		$label
	);
	if ( '' !== (string) $options['extra_note'] ) {
		$note .= ' — ' . (string) $options['extra_note'];
	}

	if ( ! empty( $options['bank_unavailable'] ) ) {
		$order->update_meta_data( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE, 1 );
	}

	$order->add_order_note( $note );

	if ( ! empty( $options['sync_cp'] ) ) {
		mtuc_sync_cp_order_bank_status( $order, $status_key, $label );
	}

	$order->save();
}

/**
 * Apply bank status pushed from CP to a WooCommerce order.
 *
 * @param WC_Order $order        Order instance.
 * @param string   $status_id    Machine-readable status key from CP.
 * @param string   $status_label Human-readable status label from CP.
 * @return true|WP_Error
 */
function mtuc_apply_cp_bank_status_push( WC_Order $order, string $status_id, string $status_label = '' ) {
	$status_id = sanitize_key( $status_id );
	if ( '' === $status_id ) {
		return new WP_Error(
			'mtuc_missing_status_id',
			__( 'Липсва status_id в заявката.', 'mtunicredit' )
		);
	}

	if ( MTUC_PAYMENT_GATEWAY_ID !== $order->get_payment_method() ) {
		return new WP_Error(
			'mtuc_not_mtuc_order',
			__( 'Поръчката не е с метод на плащане УниКредит.', 'mtunicredit' )
		);
	}

	// AUD-WOO-013-F02: protect SmartUCF start lifecycle markers without local evidence.
	if ( defined( 'MTUC_BANK_STATUS_SENT_PROCESS1' ) && MTUC_BANK_STATUS_SENT_PROCESS1 === $status_id ) {
		$has_evidence = function_exists( 'mtuc_order_has_process1_smartucf_success_evidence' )
			&& mtuc_order_has_process1_smartucf_success_evidence( $order );
		if ( ! $has_evidence ) {
			mtuc_maybe_add_callback_guard_note(
				$order,
				$status_id,
				__( 'КП callback bank_sent_process1 не е приложен: липсват локални SmartUCF success доказателства.', 'mtunicredit' )
			);
			return new WP_Error(
				'mtuc_callback_smartucf_evidence_missing',
				__( 'bank_sent_process1 изисква локални SmartUCF success доказателства.', 'mtunicredit' )
			);
		}
	}

	if ( defined( 'MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF' ) && MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF === $status_id ) {
		$has_definitive = function_exists( 'mtuc_order_has_definitive_smartucf_failure_evidence' )
			&& mtuc_order_has_definitive_smartucf_failure_evidence( $order );
		if ( ! $has_definitive ) {
			mtuc_maybe_add_callback_guard_note(
				$order,
				$status_id,
				__( 'КП callback bank_send_failed_smartucf не е приложен: липсват локални definitive SmartUCF failure доказателства.', 'mtunicredit' )
			);
			return new WP_Error(
				'mtuc_callback_smartucf_failure_evidence_missing',
				__( 'bank_send_failed_smartucf изисква локални definitive SmartUCF failure доказателства.', 'mtunicredit' )
			);
		}
	}

	// AUD-WOO-014-F02: bank_sent_process2 requires durable P2 identity + CP completion evidence.
	if ( defined( 'MTUC_BANK_STATUS_SENT_PROCESS2' ) && MTUC_BANK_STATUS_SENT_PROCESS2 === $status_id ) {
		$has_p2 = function_exists( 'mtuc_order_has_process2_completion_evidence' )
			&& mtuc_order_has_process2_completion_evidence( $order );
		if ( ! $has_p2 ) {
			mtuc_maybe_add_callback_guard_note(
				$order,
				$status_id,
				__( 'КП callback bank_sent_process2 не е приложен: липсват Process 2 идентичност и/или локални CP completion доказателства.', 'mtunicredit' )
			);
			return new WP_Error(
				'mtuc_callback_process2_evidence_missing',
				__( 'bank_sent_process2 изисква Process 2 идентичност и локални CP completion доказателства.', 'mtunicredit' )
			);
		}
	}

	// Unknown authentic SmartUCF/CP statuses are stored as delivered — no invented mapping.
	$label = '' !== trim( $status_label ) ? trim( $status_label ) : $status_id;

	mtuc_update_order_bank_status( $order, $status_id, '', $label );
	$order->save();

	return true;
}

/**
 * Add a protected-callback diagnostic note at most once per status_id (AUD-WOO-013 Pass 2).
 *
 * @param WC_Order $order     Order instance.
 * @param string   $status_id Guarded status key.
 * @param string   $note      Support-oriented note.
 * @return void
 */
function mtuc_maybe_add_callback_guard_note( WC_Order $order, string $status_id, string $note ): void {
	$status_id = sanitize_key( $status_id );
	if ( '' === $status_id || '' === trim( $note ) ) {
		return;
	}

	$meta_key = MTUC_ORDER_META_PREFIX . 'cb_guard_noted_' . $status_id;
	if ( 1 === (int) $order->get_meta( $meta_key ) ) {
		return;
	}

	$order->add_order_note( $note );
	$order->update_meta_data( $meta_key, 1 );
	$order->save();
}

/**
 * Record confirmed CP create failure (order definitely not created / rejected).
 *
 * Does not change native WooCommerce order status (AUD-WOO-004).
 *
 * @param WC_Order             $order  Order instance.
 * @param WP_Error|string      $error_or_reason Failure details or WP_Error.
 * @param array<string, mixed> $shop   Shop data (determines Process 1 vs Process 2 failure label).
 * @return void
 */
function mtuc_fail_order_on_cp_create_error( WC_Order $order, $error_or_reason = '', array $shop = array() ): void {
	$reason = '';
	if ( $error_or_reason instanceof WP_Error ) {
		if ( function_exists( 'mtuc_record_order_financing_diagnostic' ) ) {
			mtuc_record_order_financing_diagnostic( $order, $error_or_reason, 'cp' );
		}
		$reason = $error_or_reason->get_error_message();
	} else {
		$reason = trim( (string) $error_or_reason );
		if ( '' !== $reason && function_exists( 'mtuc_record_order_financing_diagnostic' ) ) {
			mtuc_record_order_financing_diagnostic(
				$order,
				new WP_Error( 'mtuc_cp_create_failed', $reason ),
				'cp'
			);
		}
	}

	if ( '' !== $reason && defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
		error_log( 'MTUC CP create order failed (order #' . $order->get_id() . '): ' . $reason );
	}

	mtuc_set_cp_create_outcome( $order, 'missing' );

	$is_process2 = function_exists( 'mtuc_is_process2_order' )
		? mtuc_is_process2_order( $order )
		: mtuc_is_shop_process_2( $shop );

	$status_key = $is_process2
		? MTUC_BANK_STATUS_SEND_FAILED
		: MTUC_BANK_STATUS_SEND_FAILED_CP;

	mtuc_record_order_bank_status(
		$order,
		$status_key,
		array(
			'bank_unavailable' => true,
		)
	);
}

/**
 * Clear a stale definitive CP-create failure bank status when outcome is unknown.
 *
 * Legacy orders may retain bank_send_failed_cp / bank_send_failed alongside
 * _mtuc_cp_create_outcome=unknown. Those statuses contradict the frozen
 * invariant that unknown != definitive CP-create failure (AUD-WOO-011-F01).
 * Unrelated lifecycle statuses are preserved.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_clear_stale_cp_create_failure_bank_status( WC_Order $order ): void {
	$current = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ) );
	if ( ! in_array(
		$current,
		array(
			MTUC_BANK_STATUS_SEND_FAILED_CP,
			MTUC_BANK_STATUS_SEND_FAILED,
		),
		true
	) ) {
		return;
	}

	$order->delete_meta_data( MTUC_ORDER_META_BANK_STATUS );
	$order->delete_meta_data( MTUC_ORDER_META_PREFIX . 'bank_status_label' );
}

/**
 * Persist ambiguous CP create outcome (timeout / transport / unusable 2xx).
 *
 * Does NOT write bank_send_failed_cp / bank_send_failed — those mean definitive
 * CP rejection. Clears only those stale CP-create failure statuses if present.
 * Ambiguity remains recoverable via same-identity replay (AUD-WOO-011).
 * Thank-you may still show temporary bank unavailability.
 *
 * @param WC_Order             $order  Order instance.
 * @param WP_Error|string      $error_or_reason Optional debug detail.
 * @param array<string, mixed> $shop   Shop data (unused; kept for call-site compatibility).
 * @return void
 */
function mtuc_record_cp_create_outcome_unknown( WC_Order $order, $error_or_reason = '', array $shop = array() ): void {
	unset( $shop );

	$reason = '';
	if ( $error_or_reason instanceof WP_Error ) {
		if ( function_exists( 'mtuc_record_order_financing_diagnostic' ) ) {
			mtuc_record_order_financing_diagnostic( $order, $error_or_reason, 'cp' );
		}
		$reason = $error_or_reason->get_error_message();
	} else {
		$reason = trim( (string) $error_or_reason );
		if ( '' !== $reason && function_exists( 'mtuc_record_order_financing_diagnostic' ) ) {
			mtuc_record_order_financing_diagnostic(
				$order,
				new WP_Error( 'mtuc_cp_create_unknown', $reason ),
				'cp'
			);
		}
	}

	if ( '' !== $reason && defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
		error_log( 'MTUC CP create outcome unknown (order #' . $order->get_id() . '): ' . $reason );
	}

	mtuc_set_cp_create_outcome( $order, 'unknown' );
	mtuc_clear_stale_cp_create_failure_bank_status( $order );

	// UX: temporary bank unavailability without claiming definitive CP failure.
	$order->update_meta_data( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE, 1 );
	$order->add_order_note(
		__( 'Създаването в КП е технически неясно; не се твърди, че поръчката липсва в КП. Възможно е повторно изпращане със същата идентичност.', 'mtunicredit' )
	);
	$order->save();
}

/**
 * Mark bank financing failure when SmartUCF session start did not succeed.
 *
 * Certificate synchronization failures are PRE-SEND (no bank HTTP) and use the
 * generic bank_send_failed status rather than SmartUCF-specific failure.
 * Does not change native WooCommerce order status (AUD-WOO-004).
 *
 * @param WC_Order       $order      Order instance.
 * @param WP_Error|string $error_or_reason Optional failure details.
 * @param string         $error_code Optional WP_Error code from start_session.
 * @return void
 */
function mtuc_fail_order_on_smartucf_error( WC_Order $order, $error_or_reason = '', string $error_code = '' ): void {
	$reason = '';
	$subsystem = 'smartucf';

	if ( $error_or_reason instanceof WP_Error ) {
		$error_code = $error_or_reason->get_error_code();
		// Ambiguous transport outcomes must not write bank_send_failed_smartucf (AUD-WOO-012-F02).
		if ( function_exists( 'mtuc_is_smartucf_ambiguous_error' ) && mtuc_is_smartucf_ambiguous_error( $error_or_reason ) ) {
			if ( function_exists( 'mtuc_record_smartucf_start_outcome_unknown' ) ) {
				mtuc_record_smartucf_start_outcome_unknown( $order, $error_or_reason );
			}
			return;
		}
		if ( mtuc_is_ssl_presend_error_code( $error_code ) ) {
			$subsystem = 'certificate';
		}
		if ( function_exists( 'mtuc_record_order_financing_diagnostic' ) ) {
			mtuc_record_order_financing_diagnostic( $order, $error_or_reason, $subsystem );
		}
		$reason = $error_or_reason->get_error_message();
	} else {
		$reason = trim( (string) $error_or_reason );
		if ( mtuc_is_ssl_presend_error_code( $error_code ) ) {
			$subsystem = 'certificate';
		}
		if ( '' !== $reason && function_exists( 'mtuc_record_order_financing_diagnostic' ) ) {
			mtuc_record_order_financing_diagnostic(
				$order,
				new WP_Error( $error_code !== '' ? $error_code : 'mtuc_smartucf_failed', $reason ),
				$subsystem
			);
		}
	}

	if ( '' !== $reason && defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- debug only.
		error_log( 'MTUC SmartUCF session failed (order #' . $order->get_id() . '): ' . $reason );
	}

	$status_key = mtuc_is_ssl_presend_error_code( $error_code )
		? MTUC_BANK_STATUS_SEND_FAILED
		: MTUC_BANK_STATUS_SEND_FAILED_SMARTUCF;

	mtuc_record_order_bank_status(
		$order,
		$status_key,
		array(
			'bank_unavailable' => true,
			'sync_cp'          => true,
		)
	);
}

/**
 * Whether a start_session error code is PRE-SEND (no bank HTTP).
 *
 * Includes certificate synchronization failures and untrusted endpoint policy rejects.
 *
 * @param string $error_code WP_Error code.
 * @return bool
 */
function mtuc_is_ssl_presend_error_code( string $error_code ): bool {
	if ( '' === $error_code ) {
		return false;
	}

	$presend_codes = array(
		'mtuc_ssl_certificate_unavailable',
		'mtuc_ssl_sync_failed',
		'mtuc_ssl_metadata_invalid',
		'mtuc_ssl_bundle_invalid',
		'mtuc_ssl_bundle_hash_mismatch',
		'mtuc_ssl_bundle_cert_hash_mismatch',
		'mtuc_ssl_bundle_key_hash_mismatch',
		'mtuc_ssl_lock_failed',
		'mtuc_ssl_lock_timeout',
		'mtuc_ssl_replace_failed',
		'mtuc_ssl_write_failed',
		'mtuc_ssl_lease_failed',
		'mtuc_ssl_files_unreadable',
		'mtuc_ssl_pair_empty',
		'mtuc_ssl_cert_malformed',
		'mtuc_ssl_key_malformed',
		'mtuc_ssl_pair_mismatch',
		'mtuc_ssl_cert_expired',
		'mtuc_ssl_cert_not_yet_valid',
		'mtuc_ssl_payload_invalid',
		'mtuc_smartucf_missing_ssl',
		'mtuc_smartucf_untrusted_service',
		'mtuc_smartucf_untrusted_application',
		'mtuc_smartucf_untrusted_url',
		// mtuc_smartucf_invalid_session_id is POST-response — ambiguous (AUD-WOO-012).
	);

	return in_array( $error_code, $presend_codes, true );
}

/**
 * Thank-you URL for a popup-created order (order-received endpoint).
 *
 * @param WC_Order $order Order instance.
 * @return string
 */
function mtuc_get_popup_order_thankyou_url( WC_Order $order ): string {
	$url = $order->get_checkout_order_received_url();
	return is_string( $url ) && '' !== $url ? $url : wc_get_checkout_url();
}

/**
 * Build customer payload from WooCommerce order billing fields.
 *
 * @param WC_Order $order Order instance.
 * @return array<string, string>
 */
function mtuc_get_customer_from_order( WC_Order $order ): array {
	$address = mtuc_join_address_parts(
		array_filter(
			array(
				(string) $order->get_billing_address_1(),
				(string) $order->get_billing_address_2(),
				trim( (string) $order->get_billing_postcode() . ' ' . (string) $order->get_billing_city() ),
			)
		)
	);

	if ( '' === $address ) {
		$address = (string) $order->get_billing_address_1();
	}

	return array(
		'first_name' => (string) $order->get_billing_first_name(),
		'last_name'  => (string) $order->get_billing_last_name(),
		'address'    => $address,
		'phone'      => (string) $order->get_billing_phone(),
		'email'      => (string) $order->get_billing_email(),
	);
}

/**
 * Parse checkout/gateway scheme fields from POST payload.
 *
 * @param array<string, mixed> $posted Posted field values.
 * @return array{scheme_key:string,offer_type:string,parva:float,months:int,filter_id:int,scheme_type:string}
 */
function mtuc_parse_checkout_scheme_post( array $posted ): array {
	$scheme_key  = isset( $posted['scheme_key'] ) ? sanitize_text_field( (string) $posted['scheme_key'] ) : '';
	$filter_id   = isset( $posted['filter_id'] ) ? absint( $posted['filter_id'] ) : 0;
	$months      = isset( $posted['months'] ) ? absint( $posted['months'] ) : 0;
	$scheme_type = isset( $posted['scheme_type'] ) ? sanitize_key( (string) $posted['scheme_type'] ) : 'standard';
	$offer_type  = isset( $posted['offer_type'] ) ? sanitize_key( (string) $posted['offer_type'] ) : 'standard';
	$parva_raw   = isset( $posted['parva'] ) ? $posted['parva'] : '0';
	$parva       = is_numeric( $parva_raw ) ? (float) $parva_raw : 0.0;

	if ( '' !== $scheme_key ) {
		$parsed      = mtuc_parse_popup_scheme_option_key( $scheme_key );
		$months      = (int) $parsed['months'];
		$filter_id   = (int) $parsed['filter_id'];
		$scheme_type = (string) $parsed['scheme_type'];
	}

	if ( ! in_array( $offer_type, array( 'standard', 'promo' ), true ) ) {
		$offer_type = 'standard';
	}

	return array(
		'scheme_key'  => $scheme_key,
		'offer_type'  => $offer_type,
		'parva'       => $parva,
		'months'      => $months,
		'filter_id'   => $filter_id,
		'scheme_type' => $scheme_type,
	);
}

/**
 * Submit an existing order to CP and SmartUCF.
 *
 * @param WC_Order              $order       WooCommerce order.
 * @param array<string, string> $customer    Validated customer fields.
 * @param array<string, mixed>  $calculation Server-side calculation snapshot.
 * @param array<string, mixed>  $shop        Shop data.
 * @return array{redirect_url: string, cp_order_id?: int, bank_unavailable?: bool}|WP_Error
 */
function mtuc_complete_order_bank_submission(
	WC_Order $order,
	array $customer,
	array $calculation,
	array $shop
) {
	$process_id = function_exists( 'mtuc_resolve_order_process_for_banking' )
		? mtuc_resolve_order_process_for_banking( $order, $shop )
		: ( mtuc_is_shop_process_2( $shop ) ? 2 : 1 );
	if ( is_wp_error( $process_id ) ) {
		return $process_id;
	}
	$order->save();

	$process2 = ( 2 === (int) $process_id );

	if ( function_exists( 'mtuc_popup_order_has_successful_bank_submission' )
		&& mtuc_popup_order_has_successful_bank_submission( $order, $process2 )
	) {
		$existing = mtuc_build_existing_popup_submission_result( $order, $shop, $process2 );
		if ( ! is_wp_error( $existing ) ) {
			return $existing;
		}
	}

	if ( function_exists( 'mtuc_order_financing_is_terminal_failure' )
		&& mtuc_order_financing_is_terminal_failure( $order )
	) {
		return array(
			'bank_unavailable' => true,
			'redirect_url'     => mtuc_get_popup_order_thankyou_url( $order ),
		);
	}

	$cp_order_id = (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' );
	$outcome     = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ) );

	if ( $cp_order_id <= 0 || 'unknown' === $outcome ) {
		$cp_result = mtuc_send_cart_popup_order_to_cp( $order, $customer, $calculation, $shop );
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
		$order->save();
		if ( function_exists( 'mtuc_clear_order_financing_diagnostic' ) ) {
			mtuc_clear_order_financing_diagnostic( $order );
		}

		return array(
			'redirect_url' => mtuc_get_popup_order_thankyou_url( $order ),
			'cp_order_id'  => $cp_order_id,
			'process2'     => true,
		);
	}

	if ( function_exists( 'mtuc_popup_order_needs_smartucf_submission' )
		&& ! mtuc_popup_order_needs_smartucf_submission( $order )
	) {
		$existing = mtuc_build_existing_popup_submission_result( $order, $shop, false );
		if ( ! is_wp_error( $existing ) ) {
			return $existing;
		}
	}

	$smartucf_result = mtuc_send_cart_popup_order_to_smartucf( $order, $customer, $calculation, $shop );
	if ( is_wp_error( $smartucf_result ) ) {
		if ( 'mtuc_submit_locked' === $smartucf_result->get_error_code() ) {
			return $smartucf_result;
		}

		return array(
			'bank_unavailable' => true,
			'redirect_url'     => mtuc_get_popup_order_thankyou_url( $order ),
		);
	}

	// Session + redirect + bank_sent_process1 are finalized inside the send helper (AUD-WOO-012-F04).
	if ( MTUC_BANK_STATUS_SENT_PROCESS1 !== sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS ) ) ) {
		if ( function_exists( 'mtuc_finalize_smartucf_p1_success' ) ) {
			$finalized = mtuc_finalize_smartucf_p1_success(
				$order,
				(string) ( $smartucf_result['session_id'] ?? '' ),
				(string) ( $smartucf_result['redirect_url'] ?? '' ),
				$shop
			);
			if ( is_wp_error( $finalized ) ) {
				return array(
					'bank_unavailable' => true,
					'redirect_url'     => mtuc_get_popup_order_thankyou_url( $order ),
				);
			}
		}
	}

	return array(
		'redirect_url' => (string) ( $smartucf_result['redirect_url'] ?? mtuc_get_popup_order_thankyou_url( $order ) ),
		'cp_order_id'  => $cp_order_id,
	);
}

/**
 * Lock key for checkout payment processing.
 *
 * @param int $order_id WooCommerce order ID.
 * @return string
 */
function mtuc_build_checkout_payment_lock_key( int $order_id ): string {
	return hash( 'sha256', 'mtuc_checkout_payment|' . (string) max( 0, $order_id ) );
}

/**
 * Process checkout payment for an existing WooCommerce order.
 *
 * @param WC_Order             $order  WooCommerce order.
 * @param array<string, mixed> $posted Gateway POST fields.
 * @return array{redirect_url: string, bank_unavailable?: bool}|WP_Error
 */
function mtuc_process_checkout_order_payment( WC_Order $order, array $posted ) {
	$scheme = mtuc_parse_checkout_scheme_post( $posted );
	if ( '' === $scheme['scheme_key'] || $scheme['months'] <= 0 ) {
		return new WP_Error(
			'mtuc_missing_scheme',
			__( 'Моля, изберете схема за погасяване.', 'mtunicredit' )
		);
	}

	$lock_key   = mtuc_build_checkout_payment_lock_key( $order->get_id() );
	$lock_owner = mtuc_acquire_popup_submit_lock( $lock_key );
	if ( ! $lock_owner ) {
		return new WP_Error(
			'mtuc_submit_locked',
			__( 'Заявката вече се обработва. Моля, изчакайте.', 'mtunicredit' )
		);
	}

	$customer = mtuc_validate_popup_customer_payload( mtuc_get_customer_from_order( $order ) );
	if ( is_wp_error( $customer ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		return $customer;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		return $shop;
	}

	if ( function_exists( 'mtuc_resolve_order_process_for_banking' ) ) {
		$process_id = mtuc_resolve_order_process_for_banking( $order, $shop );
		if ( is_wp_error( $process_id ) ) {
			mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
			return $process_id;
		}
		$order->save();
	}

	$currency = mtuc_resolve_transaction_currency( $shop, $order->get_currency() );
	if ( is_wp_error( $currency ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		return $currency;
	}

	$cart_state = mtuc_resolve_cart_scheme_state();
	if ( is_wp_error( $cart_state ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		return $cart_state;
	}

	if ( mtuc_is_process2_order( $order ) ) {
		$egn = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'egn' );
		$phone2 = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'phone2' );
		if ( '' === $egn || ! mtuc_validate_bulgarian_egn( $egn ) || ! mtuc_validate_customer_phone( $phone2 ) ) {
			mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
			return new WP_Error(
				'mtuc_missing_process2_fields',
				__( 'Моля, попълнете валидни ЕГН и втори телефон.', 'mtunicredit' )
			);
		}
	}

	$cart_total = mtuc_get_canonical_financeable_order_total( $order );
	if ( $cart_total <= 0 || ! mtuc_is_product_price_in_shop_range( $shop, $cart_total ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		return new WP_Error(
			'mtuc_order_price',
			__( 'Сумата на поръчката е извън допустимия диапазон.', 'mtunicredit' )
		);
	}

	$common = mtuc_resolve_checkout_scheme_common( $cart_state );

	if ( empty( $common ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		return new WP_Error(
			'mtuc_no_common_scheme',
			__( 'Няма обща схема за всички продукти в поръчката.', 'mtunicredit' )
		);
	}

	$coeff_list  = mtuc_get_shop_coeff_list( $shop );
	$calculation = mtuc_calculate_cart_popup_credit(
		$shop,
		$coeff_list,
		$cart_total,
		$scheme['months'],
		'standard',
		$scheme['parva'],
		$scheme['filter_id'],
		$scheme['scheme_type'],
		$common
	);

	if ( is_wp_error( $calculation ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		return $calculation;
	}

	$line_count = count( $order->get_items( 'line_item' ) );

	mtuc_save_order_credit_meta(
		$order,
		$calculation,
		array(
			'submission_source' => 'checkout',
			'line_count'        => $line_count,
		)
	);

	$order->set_created_via( 'mtuc_checkout' );
	$order->set_payment_method( MTUC_PAYMENT_GATEWAY_ID );
	$order->set_payment_method_title( mtuc_get_payment_gateway_title() );
	$order->save();

	$result = mtuc_complete_order_bank_submission( $order, $customer, $calculation, $shop );
	mtuc_release_popup_submit_lock( $lock_key, $lock_owner );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( empty( $result['bank_unavailable'] ) ) {
		mtuc_apply_payment_gateway_to_order( $order );
	}

	return $result;
}

/**
 * AJAX response: redirect customer to thank-you with bank-unavailable notice.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_send_popup_bank_unavailable_response( WC_Order $order ): void {
	wp_send_json_success(
		array(
			'redirect_url'     => mtuc_get_popup_order_thankyou_url( $order ),
			'bank_unavailable' => true,
			'order_id'         => $order->get_id(),
			'order_number'     => $order->get_order_number(),
		)
	);
}

/**
 * Customize thank-you text when the bank could not process the popup order.
 *
 * @param string         $text  Default thank-you text.
 * @param WC_Order|false $order Order instance.
 * @return string
 */
function mtuc_filter_thankyou_text_bank_unavailable( string $text, $order ): string {
	if ( ! $order instanceof WC_Order ) {
		return $text;
	}

	if ( MTUC_PAYMENT_GATEWAY_ID !== $order->get_payment_method() ) {
		return $text;
	}

	$unresolved_ambiguity = function_exists( 'mtuc_order_has_unresolved_smartucf_ambiguity' )
		&& mtuc_order_has_unresolved_smartucf_ambiguity( $order );

	$has_notice = 1 === (int) $order->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE );

	if ( ! $unresolved_ambiguity && ! $has_notice ) {
		return $text;
	}

	$intro = sprintf(
		/* translators: %s: order number */
		__( 'Благодарим Ви. Поръчка №%s е регистрирана в магазина.', 'mtunicredit' ),
		$order->get_order_number()
	);

	if ( $unresolved_ambiguity ) {
		// Persistent ambiguity UX (AUD-WOO-013-F01): do not consume the notice on render.
		if ( ! $has_notice ) {
			$order->update_meta_data( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE, 1 );
			$order->save();
		}

		$bank_notice = __(
			'Резултатът от заявката за финансиране в момента е неясен. Моля не подавайте отново заявка за финансиране. Поръчката е записана. Свържете се с магазина/поддръжката за съдействие.',
			'mtunicredit'
		);

		return sprintf(
			'%s<br><span class="mtuc-thankyou-bank-unavailable mtuc-thankyou-smartucf-ambiguous">%s</span>',
			esc_html( $intro ),
			esc_html( $bank_notice )
		);
	}

	// Definitive bank-unavailable path: one-shot notice (unchanged semantics).
	$order->delete_meta_data( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE );
	$order->save();

	$bank_notice = __( 'В момента Банката не може да обработи Вашата заявка. Моля опитайте по-късно.', 'mtunicredit' );

	return sprintf(
		'%s<br><span class="mtuc-thankyou-bank-unavailable">%s</span>',
		esc_html( $intro ),
		esc_html( $bank_notice )
	);
}


/**
 * Remove plugin-owned order content items for deterministic popup rebuild.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_clear_popup_order_commerce_items( WC_Order $order ): void {
	if ( ! method_exists( $order, 'get_items' ) || ! method_exists( $order, 'remove_item' ) ) {
		return;
	}

	$types = array( 'line_item', 'fee', 'shipping', 'coupon', 'tax' );
	foreach ( $types as $type ) {
		foreach ( array_keys( $order->get_items( $type ) ) as $item_id ) {
			$order->remove_item( (int) $item_id );
		}
	}
}

/**
 * Build a durable cart fingerprint from authoritative cart line entries.
 *
 * @param array<int, array<string,mixed>> $cart_lines Cart lines.
 * @return string
 */
function mtuc_cart_lines_fingerprint( array $cart_lines ): string {
	$parts = array();
	foreach ( $cart_lines as $line ) {
		$product = isset( $line['product'] ) && $line['product'] instanceof WC_Product ? $line['product'] : null;
		$parts[] = implode(
			':',
			array(
				(string) (int) ( $line['product_id'] ?? ( $product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : 0 ) ),
				(string) (int) ( $line['variation_id'] ?? ( $product && $product->is_type( 'variation' ) ? $product->get_id() : 0 ) ),
				(string) (int) ( $line['quantity'] ?? 1 ),
				(string) round( (float) ( $line['line_total'] ?? 0 ), 4 ),
			)
		);
	}

	return hash( 'sha256', implode( '|', $parts ) );
}

/**
 * Canonical currency for operation snapshots.
 *
 * @return string
 */
function mtuc_operation_snapshot_currency(): string {
	if ( function_exists( 'get_woocommerce_currency' ) ) {
		$currency = (string) get_woocommerce_currency();
		if ( '' !== $currency ) {
			return $currency;
		}
	}

	return 'BGN';
}

/**
 * Capture live cart coupons/fees/shipping into a serializable adjustments block.
 *
 * Coupon entries store immutable discount monetary effects (not live rule refs).
 *
 * @return array{coupons:list<array<string,mixed>>,fees:list<array<string,mixed>>,shipping:list<array<string,mixed>>}
 */
function mtuc_capture_cart_adjustments_for_snapshot(): array {
	$out = array(
		'coupons'  => array(),
		'fees'     => array(),
		'shipping' => array(),
	);

	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $out;
	}

	$cart = WC()->cart;
	foreach ( $cart->get_coupons() as $code => $_coupon ) {
		$code = (string) $code;
		if ( '' === $code ) {
			continue;
		}
		$discount     = 0.0;
		$discount_tax = 0.0;
		/*
		 * WooCommerce WC_Cart::get_coupon_discount_amount( $code, $ex_tax = true ):
		 * coupon_discount_totals are ex-tax; when $ex_tax is false the tax amount is
		 * ADDED. WC_Order_Item_Coupon::set_discount / set_discount_tax expect the
		 * non-overlapping pair (ex-tax discount + discount_tax).
		 */
		if ( method_exists( $cart, 'get_coupon_discount_amount' ) ) {
			$discount = (float) $cart->get_coupon_discount_amount( $code, true );
		}
		if ( method_exists( $cart, 'get_coupon_discount_tax_amount' ) ) {
			$discount_tax = (float) $cart->get_coupon_discount_tax_amount( $code );
		}
		$out['coupons'][] = array(
			'code'         => $code,
			'discount'     => round( $discount, 4 ),
			'discount_tax' => round( $discount_tax, 4 ),
		);
	}

	foreach ( $cart->get_fees() as $fee ) {
		if ( ! is_object( $fee ) ) {
			continue;
		}
		$out['fees'][] = array(
			'name'       => isset( $fee->name ) ? (string) $fee->name : __( 'Такса', 'mtunicredit' ),
			'amount'     => isset( $fee->amount ) ? (float) $fee->amount : 0.0,
			'total'      => isset( $fee->total ) ? (float) $fee->total : 0.0,
			'tax_class'  => isset( $fee->tax_class ) ? (string) $fee->tax_class : '',
			'tax_status' => ! empty( $fee->taxable ) ? 'taxable' : 'none',
			'tax_data'   => ( isset( $fee->tax_data ) && is_array( $fee->tax_data ) ) ? $fee->tax_data : array(),
		);
	}

	if ( WC()->shipping() ) {
		$packages = WC()->shipping()->get_packages();
		$chosen   = ( WC()->session ) ? (array) WC()->session->get( 'chosen_shipping_methods', array() ) : array();
		foreach ( $packages as $index => $package ) {
			if ( empty( $chosen[ $index ] ) || empty( $package['rates'][ $chosen[ $index ] ] ) ) {
				continue;
			}
			$rate = $package['rates'][ $chosen[ $index ] ];
			if ( ! is_object( $rate ) ) {
				continue;
			}
			$out['shipping'][] = array(
				'method_title' => method_exists( $rate, 'get_label' ) ? (string) $rate->get_label() : '',
				'method_id'    => method_exists( $rate, 'get_method_id' ) ? (string) $rate->get_method_id() : '',
				'instance_id'  => method_exists( $rate, 'get_instance_id' ) ? (int) $rate->get_instance_id() : 0,
				'total'        => method_exists( $rate, 'get_cost' ) ? (float) $rate->get_cost() : 0.0,
				'taxes'        => method_exists( $rate, 'get_taxes' ) ? (array) $rate->get_taxes() : array(),
			);
		}
	}

	return $out;
}

/**
 * Recursively canonicalize a value for deterministic fingerprint hashing.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function mtuc_canonicalize_for_fingerprint( $value ) {
	if ( ! is_array( $value ) ) {
		if ( is_float( $value ) ) {
			return round( $value, 4 );
		}
		return $value;
	}

	$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
	if ( $is_list ) {
		$out = array();
		foreach ( $value as $item ) {
			$out[] = mtuc_canonicalize_for_fingerprint( $item );
		}
		return $out;
	}

	ksort( $value );
	$out = array();
	foreach ( $value as $key => $item ) {
		$out[ (string) $key ] = mtuc_canonicalize_for_fingerprint( $item );
	}

	return $out;
}

/**
 * Fingerprint a complete cart operation snapshot (not line items alone).
 *
 * @param array<string, mixed> $snapshot Operation snapshot.
 * @return string
 */
function mtuc_cart_operation_snapshot_fingerprint( array $snapshot ): string {
	$canonical = $snapshot;
	unset( $canonical['fingerprint'] );
	$canonical = mtuc_canonicalize_for_fingerprint( $canonical );
	$encoded   = wp_json_encode( $canonical );
	if ( ! is_string( $encoded ) ) {
		$encoded = '';
	}

	return hash( 'sha256', $encoded );
}

/**
 * Whether two financing money amounts match (2dp Woo-style).
 *
 * @param float $left  Left amount.
 * @param float $right Right amount.
 * @return bool
 */
function mtuc_financing_amounts_equal( float $left, float $right ): bool {
	return abs( round( $left, 2 ) - round( $right, 2 ) ) < 0.005;
}

/**
 * Build immutable Product operation snapshot from server-validated values.
 *
 * @param array<string, string> $customer     Customer fields.
 * @param array<string, mixed>  $calculation  Calculation.
 * @param int                   $parent_id    Product ID.
 * @param int                   $variation_id Variation ID.
 * @param int                   $quantity     Quantity.
 * @param float                 $line_price   Authoritative line total incl. tax.
 * @return array<string, mixed>
 */
function mtuc_build_product_operation_snapshot(
	array $customer,
	array $calculation,
	int $parent_id,
	int $variation_id,
	int $quantity,
	float $line_price
): array {
	$qty = max( 1, $quantity );

	return array(
		'version'           => 1,
		'source'            => 'product_popup',
		'product_id'        => $parent_id,
		'variation_id'      => $variation_id,
		'quantity'          => $qty,
		'line_total'        => round( $line_price, 4 ),
		'unit_price'        => round( $line_price / $qty, 4 ),
		'currency'          => mtuc_operation_snapshot_currency(),
		'customer'          => $customer,
		'calculation'       => $calculation,
		'submission_source' => 'product_popup',
	);
}

/**
 * Persist Product operation snapshot (first-write wins).
 *
 * @param WC_Order             $order    Order.
 * @param array<string, mixed> $snapshot Snapshot.
 * @return true|WP_Error
 */
function mtuc_persist_product_operation_snapshot( WC_Order $order, array $snapshot ) {
	$existing = mtuc_read_product_operation_snapshot( $order );
	if ( null !== $existing ) {
		return true;
	}

	$encoded = wp_json_encode( $snapshot );
	if ( ! is_string( $encoded ) || '' === $encoded ) {
		return new WP_Error(
			'mtuc_operation_snapshot_failed',
			__( 'Вътрешна грешка при запазване на операцията.', 'mtunicredit' )
		);
	}

	$order->update_meta_data( MTUC_ORDER_META_PRODUCT_OP_SNAPSHOT, $encoded );
	$order->save();

	$verify = mtuc_read_product_operation_snapshot( $order );
	if ( null === $verify ) {
		return new WP_Error(
			'mtuc_operation_snapshot_failed',
			__( 'Вътрешна грешка при запазване на операцията.', 'mtunicredit' )
		);
	}

	return true;
}

/**
 * Read Product operation snapshot.
 *
 * @param WC_Order $order Order.
 * @return array<string, mixed>|null
 */
function mtuc_read_product_operation_snapshot( WC_Order $order ): ?array {
	$raw = (string) $order->get_meta( MTUC_ORDER_META_PRODUCT_OP_SNAPSHOT );
	if ( '' === $raw ) {
		return null;
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return null;
	}
	if ( (int) ( $data['product_id'] ?? 0 ) <= 0 || (int) ( $data['quantity'] ?? 0 ) <= 0 ) {
		return null;
	}
	if ( ! isset( $data['calculation'] ) || ! is_array( $data['calculation'] ) ) {
		return null;
	}
	if ( ! isset( $data['customer'] ) || ! is_array( $data['customer'] ) ) {
		return null;
	}

	return $data;
}

/**
 * Build immutable Cart operation snapshot.
 *
 * @param array<string, string>           $customer     Customer.
 * @param array<string, mixed>            $calculation  Calculation.
 * @param array<int, array<string,mixed>> $cart_lines   Lines.
 * @param array<string, mixed>|null       $adjustments  Optional adjustments (captured if null).
 * @param float|null                      $expected_total Optional expected order total.
 * @return array<string, mixed>
 */
function mtuc_build_cart_operation_snapshot(
	array $customer,
	array $calculation,
	array $cart_lines,
	$adjustments = null,
	$expected_total = null
): array {
	$lines = array();
	foreach ( $cart_lines as $line ) {
		$product = isset( $line['product'] ) && $line['product'] instanceof WC_Product ? $line['product'] : null;
		$lines[] = array(
			'product_id'   => (int) ( $line['product_id'] ?? $line['parent_id'] ?? ( $product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : 0 ) ),
			'variation_id' => (int) ( $line['variation_id'] ?? ( $product && $product->is_type( 'variation' ) ? $product->get_id() : 0 ) ),
			'quantity'     => max( 1, (int) ( $line['quantity'] ?? 1 ) ),
			'line_total'   => (float) ( $line['line_total'] ?? 0 ),
		);
	}

	if ( null === $adjustments ) {
		$adjustments = mtuc_capture_cart_adjustments_for_snapshot();
	}

	$snapshot = array(
		'version'           => 1,
		'source'            => 'cart_popup',
		'lines'             => $lines,
		'adjustments'       => $adjustments,
		'currency'          => mtuc_operation_snapshot_currency(),
		'expected_total'    => round(
			null !== $expected_total
				? (float) $expected_total
				: (float) ( $calculation['price'] ?? 0 ),
			2
		),
		'customer'          => $customer,
		'calculation'       => $calculation,
		'submission_source' => 'cart_popup',
	);
	$snapshot['fingerprint'] = mtuc_cart_operation_snapshot_fingerprint( $snapshot );

	return $snapshot;
}

/**
 * Persist Cart operation snapshot (first-write wins). Also keeps legacy line snapshot keys.
 *
 * @param WC_Order             $order    Order.
 * @param array<string, mixed> $snapshot Snapshot.
 * @return true|WP_Error
 */
function mtuc_persist_cart_operation_snapshot( WC_Order $order, array $snapshot ) {
	$existing = mtuc_read_cart_operation_snapshot( $order );
	if ( null !== $existing ) {
		return true;
	}

	if ( empty( $snapshot['fingerprint'] ) ) {
		$snapshot['fingerprint'] = mtuc_cart_operation_snapshot_fingerprint( $snapshot );
	}

	$encoded = wp_json_encode( $snapshot );
	if ( ! is_string( $encoded ) || '' === $encoded ) {
		return new WP_Error(
			'mtuc_operation_snapshot_failed',
			__( 'Вътрешна грешка при запазване на операцията.', 'mtunicredit' )
		);
	}

	$order->update_meta_data( MTUC_ORDER_META_CART_OP_SNAPSHOT, $encoded );
	$order->update_meta_data( MTUC_ORDER_META_CART_INIT_FINGERPRINT, (string) $snapshot['fingerprint'] );

	// Legacy line-only snapshot kept for older recovery readers / diagnostics.
	$line_snap = isset( $snapshot['lines'] ) && is_array( $snapshot['lines'] ) ? $snapshot['lines'] : array();
	$line_enc  = wp_json_encode( $line_snap );
	if ( is_string( $line_enc ) ) {
		$order->update_meta_data( MTUC_ORDER_META_CART_INIT_SNAPSHOT, $line_enc );
	}

	$order->save();

	$verify = mtuc_read_cart_operation_snapshot( $order );
	if ( null === $verify ) {
		return new WP_Error(
			'mtuc_operation_snapshot_failed',
			__( 'Вътрешна грешка при запазване на операцията.', 'mtunicredit' )
		);
	}

	return true;
}

/**
 * Read Cart operation snapshot.
 *
 * @param WC_Order $order Order.
 * @return array<string, mixed>|null
 */
function mtuc_read_cart_operation_snapshot( WC_Order $order ): ?array {
	$raw = (string) $order->get_meta( MTUC_ORDER_META_CART_OP_SNAPSHOT );
	if ( '' === $raw ) {
		return null;
	}
	$data = json_decode( $raw, true );
	if ( ! is_array( $data ) ) {
		return null;
	}
	if ( empty( $data['lines'] ) || ! is_array( $data['lines'] ) ) {
		return null;
	}
	if ( ! isset( $data['calculation'] ) || ! is_array( $data['calculation'] ) ) {
		return null;
	}
	if ( ! isset( $data['customer'] ) || ! is_array( $data['customer'] ) ) {
		return null;
	}

	$stored_fp = isset( $data['fingerprint'] ) ? (string) $data['fingerprint'] : '';
	if ( '' === $stored_fp ) {
		return null;
	}
	$computed = mtuc_cart_operation_snapshot_fingerprint( $data );
	if ( ! hash_equals( $stored_fp, $computed ) ) {
		return null;
	}

	return $data;
}

/**
 * Persist cart line snapshot used for incomplete-order rebuild (legacy helper).
 *
 * @param WC_Order                        $order      Order instance.
 * @param array<int, array<string,mixed>> $cart_lines Cart lines.
 * @return void
 */
function mtuc_persist_cart_init_snapshot( WC_Order $order, array $cart_lines ): void {
	$snap = array();
	foreach ( $cart_lines as $line ) {
		$product = isset( $line['product'] ) && $line['product'] instanceof WC_Product ? $line['product'] : null;
		$snap[]  = array(
			'product_id'   => (int) ( $line['product_id'] ?? ( $product ? ( $product->get_parent_id() ? $product->get_parent_id() : $product->get_id() ) : 0 ) ),
			'variation_id' => (int) ( $line['variation_id'] ?? ( $product && $product->is_type( 'variation' ) ? $product->get_id() : 0 ) ),
			'quantity'     => max( 1, (int) ( $line['quantity'] ?? 1 ) ),
			'line_total'   => (float) ( $line['line_total'] ?? 0 ),
		);
	}

	$encoded = wp_json_encode( $snap );
	if ( is_string( $encoded ) ) {
		$order->update_meta_data( MTUC_ORDER_META_CART_INIT_SNAPSHOT, $encoded );
	}
	$order->update_meta_data( MTUC_ORDER_META_CART_INIT_FINGERPRINT, mtuc_cart_lines_fingerprint( $cart_lines ) );
}

/**
 * Apply serializable adjustments onto an order (no live cart).
 *
 * @param WC_Order             $order       Order.
 * @param array<string, mixed> $adjustments Adjustments block.
 * @return void
 */
function mtuc_apply_snapshot_adjustments_to_order( WC_Order $order, array $adjustments ): void {
	$coupons = isset( $adjustments['coupons'] ) && is_array( $adjustments['coupons'] )
		? $adjustments['coupons']
		: array();
	foreach ( $coupons as $coupon_row ) {
		/*
		 * Immutable monetary coupon effects only — never re-run apply_coupon() against
		 * live mutable coupon definitions (AUD-WOO-018 Pass 5).
		 */
		if ( is_string( $coupon_row ) ) {
			// Legacy code-only rows cannot be reconstructed safely.
			continue;
		}
		if ( ! is_array( $coupon_row ) || ! class_exists( 'WC_Order_Item_Coupon' ) ) {
			continue;
		}
		$code = isset( $coupon_row['code'] ) ? (string) $coupon_row['code'] : '';
		if ( '' === $code ) {
			continue;
		}
		$item = new WC_Order_Item_Coupon();
		$item->set_code( $code );
		$item->set_discount( isset( $coupon_row['discount'] ) ? (float) $coupon_row['discount'] : 0.0 );
		$item->set_discount_tax( isset( $coupon_row['discount_tax'] ) ? (float) $coupon_row['discount_tax'] : 0.0 );
		$order->add_item( $item );
	}

	$fees = isset( $adjustments['fees'] ) && is_array( $adjustments['fees'] )
		? $adjustments['fees']
		: array();
	foreach ( $fees as $fee ) {
		if ( ! is_array( $fee ) || ! class_exists( 'WC_Order_Item_Fee' ) ) {
			continue;
		}
		$item = new WC_Order_Item_Fee();
		$item->set_name( isset( $fee['name'] ) ? (string) $fee['name'] : __( 'Такса', 'mtunicredit' ) );
		$item->set_amount( isset( $fee['amount'] ) ? (float) $fee['amount'] : 0.0 );
		$item->set_total( isset( $fee['total'] ) ? (float) $fee['total'] : 0.0 );
		$item->set_tax_class( isset( $fee['tax_class'] ) ? (string) $fee['tax_class'] : '' );
		$item->set_tax_status( isset( $fee['tax_status'] ) ? (string) $fee['tax_status'] : 'none' );
		if ( ! empty( $fee['tax_data'] ) && is_array( $fee['tax_data'] ) ) {
			$item->set_taxes(
				array(
					'total'    => $fee['tax_data'],
					'subtotal' => $fee['tax_data'],
				)
			);
		}
		$order->add_item( $item );
	}

	$shipping = isset( $adjustments['shipping'] ) && is_array( $adjustments['shipping'] )
		? $adjustments['shipping']
		: array();
	foreach ( $shipping as $ship ) {
		if ( ! is_array( $ship ) || ! class_exists( 'WC_Order_Item_Shipping' ) ) {
			continue;
		}
		$item = new WC_Order_Item_Shipping();
		$item->set_method_title( isset( $ship['method_title'] ) ? (string) $ship['method_title'] : '' );
		$item->set_method_id( isset( $ship['method_id'] ) ? (string) $ship['method_id'] : '' );
		$item->set_instance_id( isset( $ship['instance_id'] ) ? (int) $ship['instance_id'] : 0 );
		$item->set_total( isset( $ship['total'] ) ? (float) $ship['total'] : 0.0 );
		if ( ! empty( $ship['taxes'] ) && is_array( $ship['taxes'] ) ) {
			$item->set_taxes( array( 'total' => $ship['taxes'] ) );
		}
		$order->add_item( $item );
	}
}

/**
 * Resolve WC_Product for a snapshot line.
 *
 * @param int $product_id   Parent/product ID.
 * @param int $variation_id Variation ID.
 * @return WC_Product|WP_Error
 */
function mtuc_resolve_snapshot_product( int $product_id, int $variation_id ) {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error(
			'mtuc_operation_incomplete',
			__( 'Непълната заявка не може да бъде възстановена безопасно.', 'mtunicredit' )
		);
	}

	$pid = $variation_id > 0 ? $variation_id : $product_id;
	$product = wc_get_product( $pid );
	if ( ! $product instanceof WC_Product ) {
		return new WP_Error(
			'mtuc_operation_incomplete',
			__( 'Непълната заявка не може да бъде възстановена безопасно.', 'mtunicredit' )
		);
	}

	return $product;
}

/**
 * Create a pending WooCommerce order from popup submission.
 *
 * @param array<string, string> $customer     Validated customer fields.
 * @param array<string, mixed>  $calculation  Server-side calculation snapshot.
 * @param WC_Product            $product      Product or variation to add.
 * @param int                   $parent_id    Parent product ID.
 * @param int                   $variation_id Variation ID (0 if none).
 * @param int                   $quantity     Line quantity.
 * @param float                 $line_price   Line total including tax.
 * @param callable|null         $on_created   Optional early-bind callback after ID exists.
 * @param WC_Order|null         $existing     Optional incomplete order to resume.
 * @param array<string, string> $bind_context Creation ref / via marker.
 * @return WC_Order|WP_Error
 */
function mtuc_create_popup_pending_order(
	array $customer,
	array $calculation,
	WC_Product $product,
	int $parent_id,
	int $variation_id,
	int $quantity,
	float $line_price,
	$on_created = null,
	$existing = null,
	array $bind_context = array()
) {
	if ( ! function_exists( 'wc_create_order' ) ) {
		return new WP_Error( 'mtuc_wc_missing', __( 'WooCommerce не е наличен.', 'mtunicredit' ) );
	}

	if ( function_exists( 'mtuc_require_armed_submission_lock_ownership' ) ) {
		$owned = mtuc_require_armed_submission_lock_ownership(
			MTUC_SUBMISSION_LOCK_RENEW_CREATE,
			defined( 'MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED' ) ? MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED : ''
		);
		if ( is_wp_error( $owned ) ) {
			return $owned;
		}
	}

	$creation_ref = isset( $bind_context['creation_ref'] ) ? (string) $bind_context['creation_ref'] : '';
	$via_marker   = isset( $bind_context['created_via_marker'] ) ? (string) $bind_context['created_via_marker'] : '';

	if ( $existing instanceof WC_Order ) {
		$order    = $existing;
		$snapshot = mtuc_read_product_operation_snapshot( $order );
		if ( null === $snapshot ) {
			return new WP_Error(
				'mtuc_operation_incomplete',
				__( 'Непълната заявка не може да бъде възстановена безопасно.', 'mtunicredit' )
			);
		}
		$customer     = is_array( $snapshot['customer'] ) ? $snapshot['customer'] : $customer;
		$calculation  = is_array( $snapshot['calculation'] ) ? $snapshot['calculation'] : $calculation;
		$parent_id    = (int) ( $snapshot['product_id'] ?? $parent_id );
		$variation_id = (int) ( $snapshot['variation_id'] ?? $variation_id );
		$quantity     = max( 1, (int) ( $snapshot['quantity'] ?? $quantity ) );
		$line_price   = (float) ( $snapshot['line_total'] ?? $line_price );
		$resolved     = mtuc_resolve_snapshot_product( $parent_id, $variation_id );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		$product = $resolved;
	} else {
		$create_args = array();
		if ( is_user_logged_in() ) {
			$create_args['customer_id'] = get_current_user_id();
		}
		if ( '' !== $via_marker ) {
			$create_args['created_via'] = $via_marker;
		}
		if ( '' !== $creation_ref ) {
			// Same-row searchable identity alongside created_via (wc_create_order supports cart_hash).
			$create_args['cart_hash'] = substr( $creation_ref, 0, 32 );
		}

		$pre_save_hook = null;
		if ( '' !== $creation_ref && function_exists( 'add_action' ) ) {
			$pre_save_hook = static function ( $order_obj ) use ( $creation_ref, $via_marker ) {
				if ( ! $order_obj instanceof WC_Order ) {
					return;
				}
				if ( '' !== (string) $order_obj->get_meta( MTUC_ORDER_META_CREATION_REF ) ) {
					return;
				}
				$via = (string) $order_obj->get_created_via();
				if ( '' !== $via_marker && $via !== $via_marker ) {
					return;
				}
				$order_obj->update_meta_data( MTUC_ORDER_META_CREATION_REF, $creation_ref );
				$order_obj->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
			};
			add_action( 'woocommerce_before_order_object_save', $pre_save_hook, 5, 1 );
		}

		$order = wc_create_order( $create_args );

		if ( null !== $pre_save_hook && function_exists( 'remove_action' ) ) {
			remove_action( 'woocommerce_before_order_object_save', $pre_save_hook, 5 );
		}

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mtuc_order_create_failed', __( 'Поръчката не може да бъде създадена.', 'mtunicredit' ) );
		}

		if ( is_callable( $on_created ) ) {
			$on_created( $order );
		}

		$snapshot = mtuc_build_product_operation_snapshot(
			$customer,
			$calculation,
			$parent_id,
			$variation_id,
			$quantity,
			$line_price
		);
		$persisted = mtuc_persist_product_operation_snapshot( $order, $snapshot );
		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}
	}

	$order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
	mtuc_clear_popup_order_commerce_items( $order );

	$addresses = mtuc_resolve_popup_order_addresses( $customer );
	$order->set_address( $addresses['billing'], 'billing' );
	$order->set_address( $addresses['shipping'], 'shipping' );

	$added = $order->add_product( $product, $quantity );
	if ( ! $added ) {
		$order->save();
		return new WP_Error( 'mtuc_order_product_failed', __( 'Продуктът не може да бъде добавен към поръчката.', 'mtunicredit' ) );
	}

	mtuc_sync_order_line_price( $order, $line_price );
	$order->calculate_totals();

	$order->set_created_via( 'mtuc_product_popup' );

	mtuc_save_order_credit_meta(
		$order,
		$calculation,
		array(
			'product_id'   => $parent_id,
			'variation_id' => $variation_id,
			'quantity'     => $quantity,
		)
	);

	$order->set_payment_method( MTUC_PAYMENT_GATEWAY_ID );
	$order->set_payment_method_title( mtuc_get_payment_gateway_title() );
	$order->save();
	mtuc_mark_popup_order_init_complete( $order );

	return $order;
}

/**
 * Create a pending WooCommerce order from all cart lines.
 *
 * @param array<string, string>           $customer     Validated customer fields.
 * @param array<string, mixed>            $calculation  Server-side calculation snapshot.
 * @param array<int, array<string,mixed>> $cart_lines   Cart line entries from mtuc_get_cart_line_entries().
 * @param callable|null                   $on_created   Optional early-bind callback after ID exists.
 * @param WC_Order|null                   $existing     Optional incomplete order to resume.
 * @param array<string, string>           $bind_context Creation ref / via marker.
 * @return WC_Order|WP_Error
 */
function mtuc_create_cart_popup_pending_order(
	array $customer,
	array $calculation,
	array $cart_lines,
	$on_created = null,
	$existing = null,
	array $bind_context = array()
) {
	if ( ! function_exists( 'wc_create_order' ) ) {
		return new WP_Error( 'mtuc_wc_missing', __( 'WooCommerce не е наличен.', 'mtunicredit' ) );
	}

	if ( function_exists( 'mtuc_require_armed_submission_lock_ownership' ) ) {
		$owned = mtuc_require_armed_submission_lock_ownership(
			MTUC_SUBMISSION_LOCK_RENEW_CREATE,
			defined( 'MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED' ) ? MTUC_SUBMISSION_LOCK_STAGE_CREATE_ARMED : ''
		);
		if ( is_wp_error( $owned ) ) {
			return $owned;
		}
	}

	if ( empty( $cart_lines ) && ! ( $existing instanceof WC_Order ) ) {
		return new WP_Error( 'mtuc_cart_empty', __( 'Количката е празна.', 'mtunicredit' ) );
	}

	$creation_ref = isset( $bind_context['creation_ref'] ) ? (string) $bind_context['creation_ref'] : '';
	$via_marker   = isset( $bind_context['created_via_marker'] ) ? (string) $bind_context['created_via_marker'] : '';
	$adjustments  = null;

	if ( $existing instanceof WC_Order ) {
		$order    = $existing;
		$snapshot = mtuc_read_cart_operation_snapshot( $order );
		if ( null === $snapshot ) {
			return new WP_Error(
				'mtuc_operation_incomplete',
				__( 'Непълната заявка не може да бъде възстановена безопасно.', 'mtunicredit' )
			);
		}
		$customer    = is_array( $snapshot['customer'] ) ? $snapshot['customer'] : $customer;
		$calculation = is_array( $snapshot['calculation'] ) ? $snapshot['calculation'] : $calculation;
		$adjustments = isset( $snapshot['adjustments'] ) && is_array( $snapshot['adjustments'] )
			? $snapshot['adjustments']
			: array(
				'coupons'  => array(),
				'fees'     => array(),
				'shipping' => array(),
			);
		$cart_lines = array();
		foreach ( (array) $snapshot['lines'] as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$resolved = mtuc_resolve_snapshot_product(
				(int) ( $row['product_id'] ?? 0 ),
				(int) ( $row['variation_id'] ?? 0 )
			);
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$cart_lines[] = array(
				'product'      => $resolved,
				'product_id'   => (int) ( $row['product_id'] ?? 0 ),
				'variation_id' => (int) ( $row['variation_id'] ?? 0 ),
				'quantity'     => max( 1, (int) ( $row['quantity'] ?? 1 ) ),
				'line_total'   => (float) ( $row['line_total'] ?? 0 ),
			);
		}
		if ( empty( $cart_lines ) ) {
			return new WP_Error(
				'mtuc_operation_incomplete',
				__( 'Непълната заявка не може да бъде възстановена безопасно.', 'mtunicredit' )
			);
		}
	} else {
		$create_args = array();
		if ( is_user_logged_in() ) {
			$create_args['customer_id'] = get_current_user_id();
		}
		if ( '' !== $via_marker ) {
			$create_args['created_via'] = $via_marker;
		}
		if ( '' !== $creation_ref ) {
			$create_args['cart_hash'] = substr( $creation_ref, 0, 32 );
		}

		$pre_save_hook = null;
		if ( '' !== $creation_ref && function_exists( 'add_action' ) ) {
			$pre_save_hook = static function ( $order_obj ) use ( $creation_ref, $via_marker ) {
				if ( ! $order_obj instanceof WC_Order ) {
					return;
				}
				if ( '' !== (string) $order_obj->get_meta( MTUC_ORDER_META_CREATION_REF ) ) {
					return;
				}
				$via = (string) $order_obj->get_created_via();
				if ( '' !== $via_marker && $via !== $via_marker ) {
					return;
				}
				$order_obj->update_meta_data( MTUC_ORDER_META_CREATION_REF, $creation_ref );
				$order_obj->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );
			};
			add_action( 'woocommerce_before_order_object_save', $pre_save_hook, 5, 1 );
		}

		$order = wc_create_order( $create_args );

		if ( null !== $pre_save_hook && function_exists( 'remove_action' ) ) {
			remove_action( 'woocommerce_before_order_object_save', $pre_save_hook, 5 );
		}

		if ( is_wp_error( $order ) ) {
			return $order;
		}

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'mtuc_order_create_failed', __( 'Поръчката не може да бъде създадена.', 'mtunicredit' ) );
		}

		if ( is_callable( $on_created ) ) {
			$on_created( $order );
		}

		$adjustments = mtuc_capture_cart_adjustments_for_snapshot();
		$snapshot    = mtuc_build_cart_operation_snapshot(
			$customer,
			$calculation,
			$cart_lines,
			$adjustments,
			(float) ( $calculation['price'] ?? 0 )
		);
		$persisted = mtuc_persist_cart_operation_snapshot( $order, $snapshot );
		if ( is_wp_error( $persisted ) ) {
			return $persisted;
		}
	}

	$order->update_meta_data( MTUC_ORDER_META_POPUP_INIT_STATE, MTUC_POPUP_INIT_INITIALIZING );

	mtuc_clear_popup_order_commerce_items( $order );

	$addresses = mtuc_resolve_popup_order_addresses( $customer );
	$order->set_address( $addresses['billing'], 'billing' );
	$order->set_address( $addresses['shipping'], 'shipping' );

	foreach ( $cart_lines as $line ) {
		if ( ! isset( $line['product'] ) || ! $line['product'] instanceof WC_Product ) {
			continue;
		}
		$quantity = max( 1, (int) ( $line['quantity'] ?? 1 ) );
		$added    = $order->add_product( $line['product'], $quantity );
		if ( ! $added ) {
			$order->save();
			return new WP_Error( 'mtuc_order_product_failed', __( 'Продуктът не може да бъде добавен към поръчката.', 'mtunicredit' ) );
		}
	}

	mtuc_sync_cart_order_line_prices( $order, $cart_lines );
	if ( is_array( $adjustments ) ) {
		foreach ( (array) ( $adjustments['coupons'] ?? array() ) as $coupon_row ) {
			if ( is_string( $coupon_row )
				|| ! is_array( $coupon_row )
				|| ! array_key_exists( 'discount', $coupon_row )
			) {
				$order->save();
				return new WP_Error(
					'mtuc_operation_incomplete',
					__( 'Непълната заявка не може да бъде възстановена безопасно.', 'mtunicredit' )
				);
			}
		}
		mtuc_apply_snapshot_adjustments_to_order( $order, $adjustments );
	} else {
		// Should not happen for new creates; fail closed rather than live-cart rebuild.
		return new WP_Error(
			'mtuc_operation_incomplete',
			__( 'Непълната заявка не може да бъде възстановена безопасно.', 'mtunicredit' )
		);
	}
	$order->calculate_totals();

	$expected_total = null;
	$snap_for_total = mtuc_read_cart_operation_snapshot( $order );
	if ( null !== $snap_for_total && isset( $snap_for_total['expected_total'] ) ) {
		$expected_total = (float) $snap_for_total['expected_total'];
	} elseif ( is_array( $adjustments ) && isset( $calculation['price'] ) ) {
		$expected_total = round( (float) $calculation['price'], 2 );
	}
	if ( null !== $expected_total ) {
		$actual_total = mtuc_get_canonical_financeable_order_total( $order );
		if ( ! mtuc_financing_amounts_equal( $actual_total, $expected_total ) ) {
			$order->save();
			return new WP_Error(
				'mtuc_operation_total_mismatch',
				__( 'Възстановената сума не съвпада с оригиналната заявка.', 'mtunicredit' )
			);
		}
	}

	$order->set_created_via( 'mtuc_cart_popup' );

	mtuc_save_order_credit_meta(
		$order,
		$calculation,
		array(
			'submission_source' => 'cart_popup',
			'line_count'        => count( $cart_lines ),
		)
	);

	$order->set_payment_method( MTUC_PAYMENT_GATEWAY_ID );
	$order->set_payment_method_title( mtuc_get_payment_gateway_title() );
	$order->save();
	mtuc_mark_popup_order_init_complete( $order );

	return $order;
}

/**
 * Normalize a CP response identity scalar (order_id / unicid) for comparison.
 *
 * Accepts string/int (and numeric string) forms used by CP JSON without treating
 * "123" and 123 as different identities. Non-scalar / empty → null (unusable).
 *
 * @param mixed $value Raw response or request value.
 * @return string|null Normalized non-empty string, or null if unusable.
 */
function mtuc_normalize_cp_identity_scalar( $value ) {
	if ( is_int( $value ) || is_float( $value ) ) {
		$normalized = (string) $value;
	} elseif ( is_string( $value ) ) {
		$normalized = trim( $value );
	} else {
		return null;
	}

	return '' !== $normalized ? $normalized : null;
}

/**
 * Validate CP create success identity against the request payload (AUD-WOO-011-F03).
 *
 * CP create/replay success guarantees data.order_id and data.unicid. Missing or
 * empty values are unusable success (ambiguous). Present-but-wrong values are
 * identity mismatch. data.shop_id is CP-internal and is not compared to Woo.
 *
 * @param array<string, mixed> $response Decoded CP create response.
 * @param array<string, mixed> $payload  Request payload sent to CP.
 * @return true|WP_Error
 */
function mtuc_validate_cp_create_response_identity( array $response, array $payload ) {
	$data = isset( $response['data'] ) && is_array( $response['data'] )
		? $response['data']
		: array();

	$requested_order_id = mtuc_normalize_cp_identity_scalar( $payload['order_id'] ?? null );
	if ( null === $requested_order_id ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'Липсва заявен order_id за проверка на КП идентичност.', 'mtunicredit' )
		);
	}

	if ( ! array_key_exists( 'order_id', $data ) ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'КП успешен отговор без гарантираното поле order_id.', 'mtunicredit' ),
			array(
				'response' => $response,
			)
		);
	}

	$returned_order_id = mtuc_normalize_cp_identity_scalar( $data['order_id'] );
	if ( null === $returned_order_id ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'КП върна празен или невалиден order_id.', 'mtunicredit' ),
			array(
				'response' => $response,
			)
		);
	}

	if ( $returned_order_id !== $requested_order_id ) {
		return new WP_Error(
			'mtuc_cp_identity_mismatch',
			__( 'КП върна поръчка с различна идентичност от заявената.', 'mtunicredit' ),
			array(
				'requested_order_id' => $requested_order_id,
				'returned_order_id'  => $returned_order_id,
			)
		);
	}

	$expected_unicid = '';
	if ( class_exists( 'Mtuc_Settings', false ) ) {
		$expected_unicid = trim( (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID ) );
	}
	if ( '' === $expected_unicid ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'Липсва конфигуриран unicid за проверка на КП идентичност.', 'mtunicredit' )
		);
	}

	if ( ! array_key_exists( 'unicid', $data ) ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'КП успешен отговор без гарантираното поле unicid.', 'mtunicredit' ),
			array(
				'response' => $response,
			)
		);
	}

	$returned_unicid = mtuc_normalize_cp_identity_scalar( $data['unicid'] );
	if ( null === $returned_unicid ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'КП върна празен или невалиден unicid.', 'mtunicredit' ),
			array(
				'response' => $response,
			)
		);
	}

	if ( $returned_unicid !== $expected_unicid ) {
		return new WP_Error(
			'mtuc_cp_identity_mismatch',
			__( 'КП върна поръчка за друг магазин (unicid).', 'mtunicredit' ),
			array(
				'expected_unicid' => $expected_unicid,
				'returned_unicid' => $returned_unicid,
			)
		);
	}

	return true;
}

/**
 * Normalize a CP create HTTP success body into a validated response or ambiguous error.
 *
 * Decoded HTTP 2xx without a usable positive data.id is ambiguous (CP may have
 * committed). Identity mismatches are also ambiguous/fail-safe (AUD-WOO-011-F02/F03).
 *
 * @param array<string, mixed>|WP_Error $response Decoded client response.
 * @param array<string, mixed>          $payload  Request payload.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_normalize_cp_create_response( $response, array $payload ) {
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	if ( ! is_array( $response ) ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'КП върна неразпознаваем успешен отговор.', 'mtunicredit' )
		);
	}

	$cp_order_id = 0;
	if ( isset( $response['data']['id'] ) ) {
		$cp_order_id = (int) $response['data']['id'];
	}

	if ( $cp_order_id <= 0 ) {
		return new WP_Error(
			'mtuc_cp_unusable_success',
			__( 'КП не върна валиден идентификатор на поръчката.', 'mtunicredit' ),
			array(
				'response' => $response,
			)
		);
	}

	$identity = mtuc_validate_cp_create_response_identity( $response, $payload );
	if ( is_wp_error( $identity ) ) {
		return $identity;
	}

	return $response;
}

/**
 * Create CP order with idempotent retry on ambiguous transport/success outcomes (AUD-WOO-005/011).
 *
 * @param WC_Order             $order   WooCommerce order.
 * @param array<string, mixed> $payload CP create payload (stable order_id).
 * @param array<string, mixed> $shop    Shop data.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_create_cp_order_with_recovery( WC_Order $order, array $payload, array $shop ) {
	$existing_cp_id = (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' );
	$outcome        = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_CREATE_OUTCOME ) );

	if ( $existing_cp_id > 0 && 'unknown' !== $outcome ) {
		return array(
			'data' => array(
				'id' => $existing_cp_id,
			),
		);
	}

	// AUD-WOO-014: durable process identity before irreversible CP create.
	if ( function_exists( 'mtuc_resolve_order_process_for_banking' ) ) {
		$process_id = mtuc_resolve_order_process_for_banking( $order, $shop );
		if ( is_wp_error( $process_id ) ) {
			return $process_id;
		}
	}

	if ( function_exists( 'mtuc_assert_popup_order_ready_for_remote' ) ) {
		$ready = mtuc_assert_popup_order_ready_for_remote( $order );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
	}

	$owned = function_exists( 'mtuc_require_armed_submission_lock_ownership' )
		? mtuc_require_armed_submission_lock_ownership(
			MTUC_SUBMISSION_LOCK_RENEW_HTTP_CP,
			MTUC_SUBMISSION_LOCK_STAGE_CP_HTTP
		)
		: true;
	if ( is_wp_error( $owned ) ) {
		return $owned;
	}

	$response = mtuc_normalize_cp_create_response(
		Mtuc_Cp_Api_Client::create_order( $payload, $order->get_id() ),
		$payload
	);

	$is_ambiguous = is_wp_error( $response )
		&& (
			( function_exists( 'mtuc_is_cp_create_ambiguous_error' ) && mtuc_is_cp_create_ambiguous_error( $response ) )
			|| ( ! function_exists( 'mtuc_is_cp_create_ambiguous_error' ) && mtuc_is_cp_transport_ambiguous_error( $response ) )
		);

	if ( $is_ambiguous ) {
		$owned = function_exists( 'mtuc_require_armed_submission_lock_ownership' )
			? mtuc_require_armed_submission_lock_ownership(
				MTUC_SUBMISSION_LOCK_RENEW_HTTP_CP,
				MTUC_SUBMISSION_LOCK_STAGE_CP_HTTP
			)
			: true;
		if ( is_wp_error( $owned ) ) {
			return $owned;
		}
		// Same shop_id + order_id — CP idempotent replay; do not mint a new identity.
		$response = mtuc_normalize_cp_create_response(
			Mtuc_Cp_Api_Client::create_order( $payload, $order->get_id() ),
			$payload
		);
	}

	if ( is_wp_error( $response ) ) {
		$still_ambiguous = ( function_exists( 'mtuc_is_cp_create_ambiguous_error' ) && mtuc_is_cp_create_ambiguous_error( $response ) )
			|| ( ! function_exists( 'mtuc_is_cp_create_ambiguous_error' ) && mtuc_is_cp_transport_ambiguous_error( $response ) );

		if ( $still_ambiguous ) {
			mtuc_record_cp_create_outcome_unknown( $order, $response, $shop );
			return $response;
		}

		mtuc_fail_order_on_cp_create_error( $order, $response, $shop );
		return $response;
	}

	$cp_order_id = (int) $response['data']['id'];

	mtuc_clear_cp_create_outcome_unknown( $order );
	if ( function_exists( 'mtuc_clear_order_financing_diagnostic' ) ) {
		mtuc_clear_order_financing_diagnostic( $order );
	}
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', $cp_order_id );

	if ( mtuc_is_process2_order( $order ) ) {
		mtuc_record_order_bank_status( $order, MTUC_BANK_STATUS_SENT_PROCESS2 );
	}

	$order->save();

	return $response;
}

/**
 * Send cart popup order to Control Panel.
 *
 * @param WC_Order              $order       WooCommerce order.
 * @param array<string, string> $customer    Validated customer fields.
 * @param array<string, mixed>  $calculation Server-side calculation snapshot.
 * @param array<string, mixed>  $shop        Shop data.
 * @return array<string, mixed>|WP_Error
 */
function mtuc_send_cart_popup_order_to_cp(
	WC_Order $order,
	array $customer,
	array $calculation,
	array $shop
) {
	$payload = mtuc_build_cp_cart_order_payload( $order, $customer, $calculation, $shop );

	return mtuc_create_cp_order_with_recovery( $order, $payload, $shop );
}

/**
 * Send cart popup order to SmartUCF.
 *
 * @param WC_Order              $order       WooCommerce order.
 * @param array<string, string> $customer    Validated customer fields.
 * @param array<string, mixed>  $calculation Server-side calculation snapshot.
 * @param array<string, mixed>  $shop        Shop data.
 * @return array{session_id: string, redirect_url: string}|WP_Error
 */
function mtuc_send_cart_popup_order_to_smartucf(
	WC_Order $order,
	array $customer,
	array $calculation,
	array $shop
) {
	if ( function_exists( 'mtuc_classify_order_process_identity' ) ) {
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
	}

	if ( mtuc_is_process2_order( $order ) ) {
		return new WP_Error(
			'mtuc_process2_no_smartucf',
			__( 'Process 2 поръчките не се изпращат към SmartUCF.', 'mtunicredit' )
		);
	}

	if ( function_exists( 'mtuc_try_recover_smartucf_p1_session' ) ) {
		$recovered = mtuc_try_recover_smartucf_p1_session( $order, $shop );
		if ( is_array( $recovered ) ) {
			return $recovered;
		}
		if ( is_wp_error( $recovered ) ) {
			return $recovered;
		}
	}

	if ( function_exists( 'mtuc_smartucf_p1_second_start_prohibited' )
		&& mtuc_smartucf_p1_second_start_prohibited( $order )
	) {
		$error = new WP_Error(
			'mtuc_smartucf_claim_ambiguous',
			__( 'SmartUCF изпращането е неясно; автоматичен повторен старт е забранен.', 'mtunicredit' )
		);
		if ( function_exists( 'mtuc_record_smartucf_start_outcome_unknown' ) ) {
			$outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ) );
			if ( 'unknown' !== $outcome ) {
				mtuc_record_smartucf_start_outcome_unknown( $order, $error );
			}
		}
		return $error;
	}

	$payload = mtuc_build_cart_smartucf_session_payload( $order, $customer, $calculation, $shop );

	if ( function_exists( 'mtuc_assert_popup_order_ready_for_remote' ) ) {
		$ready = mtuc_assert_popup_order_ready_for_remote( $order );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
	}

	if ( function_exists( 'mtuc_acquire_smartucf_p1_send_claim' ) ) {
		$claimed = mtuc_acquire_smartucf_p1_send_claim( $order );
		if ( is_wp_error( $claimed ) ) {
			if ( 'mtuc_smartucf_claim_ambiguous' === $claimed->get_error_code()
				&& function_exists( 'mtuc_record_smartucf_start_outcome_unknown' )
			) {
				mtuc_record_smartucf_start_outcome_unknown( $order, $claimed );
			}
			return $claimed;
		}
		if ( is_array( $claimed ) && isset( $claimed['claim']['owner'] )
			&& function_exists( 'mtuc_set_smartucf_p1_claim_owner_context' )
		) {
			mtuc_set_smartucf_p1_claim_owner_context( (int) $order->get_id(), (string) $claimed['claim']['owner'] );
		}
	}

	$owned = function_exists( 'mtuc_require_armed_submission_lock_ownership' )
		? mtuc_require_armed_submission_lock_ownership(
			MTUC_SUBMISSION_LOCK_RENEW_HTTP_SMARTUCF,
			MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP
		)
		: true;
	if ( is_wp_error( $owned ) ) {
		return $owned;
	}

	$result = Mtuc_Smartucf_Api_Client::start_session( $payload, $shop );

	if ( is_wp_error( $result ) ) {
		if ( function_exists( 'mtuc_handle_smartucf_start_error' ) ) {
			return mtuc_handle_smartucf_start_error( $order, $result );
		}
		mtuc_fail_order_on_smartucf_error( $order, $result );
		return $result;
	}

	if ( function_exists( 'mtuc_finalize_smartucf_p1_success' ) ) {
		$finalized = mtuc_finalize_smartucf_p1_success(
			$order,
			(string) $result['session_id'],
			(string) $result['redirect_url'],
			$shop
		);
		if ( is_wp_error( $finalized ) ) {
			if ( function_exists( 'mtuc_record_smartucf_start_outcome_unknown' ) ) {
				mtuc_record_smartucf_start_outcome_unknown( $order, $finalized );
			}
			return $finalized;
		}
	} else {
		$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', $result['session_id'] );
		$order->save();
	}

	return $result;
}

/**
 * Process cart popup AJAX submit.
 *
 * @param array<string, string> $customer Validated customer fields.
 * @return void
 */
function mtuc_ajax_popup_submit_cart( array $customer ): void {
	$scheme_key  = isset( $_POST['scheme_key'] ) ? sanitize_text_field( wp_unslash( $_POST['scheme_key'] ) ) : '';
	$filter_id   = isset( $_POST['filter_id'] ) ? absint( wp_unslash( $_POST['filter_id'] ) ) : 0;
	$months      = isset( $_POST['months'] ) ? absint( wp_unslash( $_POST['months'] ) ) : 0;
	$scheme_type = isset( $_POST['scheme_type'] ) ? sanitize_key( wp_unslash( $_POST['scheme_type'] ) ) : 'standard';

	if ( '' !== $scheme_key ) {
		$parsed      = mtuc_parse_popup_scheme_option_key( $scheme_key );
		$months      = (int) $parsed['months'];
		$filter_id   = (int) $parsed['filter_id'];
		$scheme_type = (string) $parsed['scheme_type'];
	}

	$offer_type = isset( $_POST['offer_type'] ) ? sanitize_key( wp_unslash( $_POST['offer_type'] ) ) : 'standard';
	if ( ! in_array( $offer_type, array( 'standard', 'promo' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Невалиден тип оферта.', 'mtunicredit' ) ), 400 );
	}

	$parva_raw = isset( $_POST['parva'] ) ? wp_unslash( $_POST['parva'] ) : '0';
	$parva     = is_numeric( $parva_raw ) ? (float) $parva_raw : 0.0;

	$lock_key   = mtuc_build_cart_popup_submit_lock_key();
	$lock_owner = mtuc_acquire_popup_submit_lock( $lock_key );
	if ( ! $lock_owner ) {
		wp_send_json_error(
			array( 'message' => __( 'Заявката вече се обработва. Моля, изчакайте.', 'mtunicredit' ) ),
			429
		);
	}

	$cart_state = mtuc_resolve_cart_scheme_state();
	if ( is_wp_error( $cart_state ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_customer_safe_json_error( $cart_state, 400, 'general' );
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_customer_safe_json_error( $shop, 500, 'configuration' );
	}

	$cart_total = (float) ( $cart_state['cart_total'] ?? 0 );
	$common     = 'promo' === $offer_type
		? (array) ( $cart_state['common_promo'] ?? array() )
		: (array) ( $cart_state['common_standard'] ?? array() );

	if ( empty( $common ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		wp_send_json_error(
			array( 'message' => __( 'Няма обща схема за всички продукти в количката.', 'mtunicredit' ) ),
			400
		);
	}

	$coeff_list  = mtuc_get_shop_coeff_list( $shop );
	$calculation = mtuc_calculate_cart_popup_credit(
		$shop,
		$coeff_list,
		$cart_total,
		$months,
		$offer_type,
		$parva,
		$filter_id,
		$scheme_type,
		$common
	);

	if ( is_wp_error( $calculation ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_customer_safe_json_error( $calculation, 400, 'general' );
	}

	$cart_lines = isset( $cart_state['lines'] ) && is_array( $cart_state['lines'] )
		? $cart_state['lines']
		: mtuc_get_cart_line_entries();

	$operation_token = mtuc_get_submitted_operation_token();
	if ( is_wp_error( $operation_token ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_customer_safe_json_error( $operation_token, 400, 'general' );
	}

	$scope_key = mtuc_build_cart_operation_scope_key();
	$resolved  = mtuc_resolve_popup_financing_order(
		$operation_token,
		$scope_key,
		static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( $customer, $calculation, $cart_lines ) {
			return mtuc_create_cart_popup_pending_order( $customer, $calculation, $cart_lines, $early_bind, $existing, $bind_context );
		}
	);

	if ( is_wp_error( $resolved ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		$status = 'mtuc_operation_contention' === $resolved->get_error_code() ? 429 : 500;
		mtuc_send_customer_safe_json_error( $resolved, $status, 'general' );
	}

	$order = $resolved['order'];

	$cart_snap = mtuc_read_cart_operation_snapshot( $order );
	if ( null !== $cart_snap ) {
		$customer    = is_array( $cart_snap['customer'] ) ? $cart_snap['customer'] : $customer;
		$calculation = is_array( $cart_snap['calculation'] ) ? $cart_snap['calculation'] : $calculation;
		$cart_lines  = isset( $cart_snap['lines'] ) && is_array( $cart_snap['lines'] )
			? $cart_snap['lines']
			: $cart_lines;
	} else {
		// Recalculate from authoritative order total so snapshot/CP/SmartUCF stay aligned
		// only when no immutable operation snapshot exists yet (should be rare).
		$order_total = mtuc_get_canonical_financeable_order_total( $order );
		if ( abs( $order_total - $cart_total ) > 0.009 ) {
			$calculation = mtuc_calculate_cart_popup_credit(
				$shop,
				$coeff_list,
				$order_total,
				$months,
				$offer_type,
				$parva,
				$filter_id,
				$scheme_type,
				$common
			);
			if ( is_wp_error( $calculation ) ) {
				$order->delete( true );
				mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
				mtuc_send_customer_safe_json_error( $calculation, 400, 'general' );
			}
			mtuc_save_order_credit_meta(
				$order,
				$calculation,
				array(
					'submission_source' => 'cart_popup',
					'line_count'        => count( $cart_lines ),
				)
			);
			$order->save();
		}
	}

	if ( function_exists( 'mtuc_resolve_order_process_for_banking' ) ) {
		$process_id = mtuc_resolve_order_process_for_banking( $order, $shop );
		if ( is_wp_error( $process_id ) ) {
			mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
			mtuc_send_customer_safe_json_error( $process_id, 500, 'general' );
		}
		if ( 2 === (int) $process_id ) {
			mtuc_save_order_process2_customer_meta( $order, $customer );
		}
		$order->save();
	} elseif ( mtuc_is_shop_process_2( $shop ) ) {
		mtuc_save_order_process2_customer_meta( $order, $customer );
	}

	$submission = mtuc_complete_order_bank_submission( $order, $customer, $calculation, $shop );
	if ( is_wp_error( $submission ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_customer_safe_json_error( $submission, 500, 'cp' );
	}

	if ( empty( $submission['bank_unavailable'] ) ) {
		mtuc_accept_popup_financing_order( $order );
	}

	mtuc_release_popup_submit_lock( $lock_key, $lock_owner );

	if ( ! empty( $submission['bank_unavailable'] ) ) {
		mtuc_send_popup_bank_unavailable_response( $order );
	}

	$is_process2 = ! empty( $submission['process2'] );

	wp_send_json_success(
		array(
			'order_id'     => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'cp_order_id'  => (int) ( $submission['cp_order_id'] ?? 0 ),
			'bank_status'  => $is_process2 ? MTUC_BANK_STATUS_SENT_PROCESS2 : MTUC_BANK_STATUS_SENT_PROCESS1,
			'redirect_url' => $submission['redirect_url'],
			'message'      => $is_process2
				? mtuc_get_process2_confirmation_message()
				: __( 'Пренасочване към UniCredit за довършване на заявката.', 'mtunicredit' ),
		)
	);
}

/**
 * Send popup WooCommerce order to Control Panel (step 2).
 *
 * @param WC_Order              $order       WooCommerce order.
 * @param array<string, string> $customer    Validated customer fields.
 * @param array<string, mixed>  $calculation Server-side calculation snapshot.
 * @param WC_Product            $product     Product or variation line item.
 * @param int                   $parent_id   Parent product ID.
 * @param int                   $variation_id Variation ID (0 if none).
 * @param int                   $quantity    Line quantity.
 * @param array<string, mixed>  $shop        Shop `data` object from CP.
 * @return array<string, mixed>|WP_Error CP response data on success.
 */
function mtuc_send_popup_order_to_cp(
	WC_Order $order,
	array $customer,
	array $calculation,
	WC_Product $product,
	int $parent_id,
	int $variation_id,
	int $quantity,
	array $shop
) {
	$payload = mtuc_build_cp_order_payload(
		$order,
		$customer,
		$calculation,
		$product,
		$parent_id,
		$variation_id,
		$quantity,
		$shop
	);

	return mtuc_create_cp_order_with_recovery( $order, $payload, $shop );
}

/**
 * Send popup WooCommerce order to SmartUCF (step 3).
 *
 * @param WC_Order              $order        WooCommerce order.
 * @param array<string, string> $customer     Validated customer fields.
 * @param array<string, mixed>  $calculation  Server-side calculation snapshot.
 * @param WC_Product            $product      Product or variation line item.
 * @param int                   $parent_id    Parent product ID.
 * @param int                   $variation_id Variation ID (0 if none).
 * @param int                   $quantity     Line quantity.
 * @param array<string, mixed>  $shop         Shop `data` object from CP.
 * @return array{session_id: string, redirect_url: string}|WP_Error
 */
function mtuc_send_popup_order_to_smartucf(
	WC_Order $order,
	array $customer,
	array $calculation,
	WC_Product $product,
	int $parent_id,
	int $variation_id,
	int $quantity,
	array $shop
) {
	if ( function_exists( 'mtuc_classify_order_process_identity' ) ) {
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
	}

	if ( mtuc_is_process2_order( $order ) ) {
		return new WP_Error(
			'mtuc_process2_no_smartucf',
			__( 'Process 2 поръчките не се изпращат към SmartUCF.', 'mtunicredit' )
		);
	}

	if ( function_exists( 'mtuc_try_recover_smartucf_p1_session' ) ) {
		$recovered = mtuc_try_recover_smartucf_p1_session( $order, $shop );
		if ( is_array( $recovered ) ) {
			return $recovered;
		}
		if ( is_wp_error( $recovered ) ) {
			return $recovered;
		}
	}

	if ( function_exists( 'mtuc_smartucf_p1_second_start_prohibited' )
		&& mtuc_smartucf_p1_second_start_prohibited( $order )
	) {
		$error = new WP_Error(
			'mtuc_smartucf_claim_ambiguous',
			__( 'SmartUCF изпращането е неясно; автоматичен повторен старт е забранен.', 'mtunicredit' )
		);
		if ( function_exists( 'mtuc_record_smartucf_start_outcome_unknown' ) ) {
			$outcome = sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_SMARTUCF_START_OUTCOME ) );
			if ( 'unknown' !== $outcome ) {
				mtuc_record_smartucf_start_outcome_unknown( $order, $error );
			}
		}
		return $error;
	}

	$payload = mtuc_build_smartucf_session_payload(
		$order,
		$customer,
		$calculation,
		$product,
		$parent_id,
		$variation_id,
		$quantity,
		$shop
	);

	if ( function_exists( 'mtuc_assert_popup_order_ready_for_remote' ) ) {
		$ready = mtuc_assert_popup_order_ready_for_remote( $order );
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}
	}

	if ( function_exists( 'mtuc_acquire_smartucf_p1_send_claim' ) ) {
		$claimed = mtuc_acquire_smartucf_p1_send_claim( $order );
		if ( is_wp_error( $claimed ) ) {
			if ( 'mtuc_smartucf_claim_ambiguous' === $claimed->get_error_code()
				&& function_exists( 'mtuc_record_smartucf_start_outcome_unknown' )
			) {
				mtuc_record_smartucf_start_outcome_unknown( $order, $claimed );
			}
			return $claimed;
		}
		if ( is_array( $claimed ) && isset( $claimed['claim']['owner'] )
			&& function_exists( 'mtuc_set_smartucf_p1_claim_owner_context' )
		) {
			mtuc_set_smartucf_p1_claim_owner_context( (int) $order->get_id(), (string) $claimed['claim']['owner'] );
		}
	}

	$owned = function_exists( 'mtuc_require_armed_submission_lock_ownership' )
		? mtuc_require_armed_submission_lock_ownership(
			MTUC_SUBMISSION_LOCK_RENEW_HTTP_SMARTUCF,
			MTUC_SUBMISSION_LOCK_STAGE_SMARTUCF_HTTP
		)
		: true;
	if ( is_wp_error( $owned ) ) {
		return $owned;
	}

	$result = Mtuc_Smartucf_Api_Client::start_session( $payload, $shop );
	if ( is_wp_error( $result ) ) {
		if ( function_exists( 'mtuc_handle_smartucf_start_error' ) ) {
			return mtuc_handle_smartucf_start_error( $order, $result );
		}
		mtuc_fail_order_on_smartucf_error( $order, $result );
		return $result;
	}

	if ( function_exists( 'mtuc_finalize_smartucf_p1_success' ) ) {
		$finalized = mtuc_finalize_smartucf_p1_success(
			$order,
			(string) $result['session_id'],
			(string) $result['redirect_url'],
			$shop
		);
		if ( is_wp_error( $finalized ) ) {
			if ( function_exists( 'mtuc_record_smartucf_start_outcome_unknown' ) ) {
				mtuc_record_smartucf_start_outcome_unknown( $order, $finalized );
			}
			return $finalized;
		}
	} else {
		$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', $result['session_id'] );
		$order->save();
	}

	return $result;
}

/**
 * AJAX: create pending order (step 1).
 *
 * @return void
 */
function mtuc_ajax_popup_submit(): void {
	check_ajax_referer( 'mtuc_popup', 'security' );

	if ( ! Mtuc_Settings::is_enabled() ) {
		wp_send_json_error(
			array( 'message' => __( 'Модулът не е активен.', 'mtunicredit' ) ),
			403
		);
	}

	$shop = mtuc_get_shop_data();
	$process2 = ! is_wp_error( $shop ) && is_array( $shop ) && mtuc_is_shop_process_2( $shop );

	$customer = mtuc_validate_popup_customer_payload( $_POST, $process2 );
	if ( is_wp_error( $customer ) ) {
		mtuc_send_customer_safe_json_error( $customer, 400, 'general' );
	}

	$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'product';
	if ( 'cart' === $source ) {
		mtuc_ajax_popup_submit_cart( $customer );
	}

	$scheme_key  = isset( $_POST['scheme_key'] ) ? sanitize_text_field( wp_unslash( $_POST['scheme_key'] ) ) : '';
	$filter_id   = isset( $_POST['filter_id'] ) ? absint( wp_unslash( $_POST['filter_id'] ) ) : 0;
	$months      = isset( $_POST['months'] ) ? absint( wp_unslash( $_POST['months'] ) ) : 0;
	$scheme_type = isset( $_POST['scheme_type'] ) ? sanitize_key( wp_unslash( $_POST['scheme_type'] ) ) : 'standard';

	if ( '' !== $scheme_key ) {
		$parsed      = mtuc_parse_popup_scheme_option_key( $scheme_key );
		$months      = (int) $parsed['months'];
		$filter_id   = (int) $parsed['filter_id'];
		$scheme_type = (string) $parsed['scheme_type'];
	}

	$offer_type = isset( $_POST['offer_type'] ) ? sanitize_key( wp_unslash( $_POST['offer_type'] ) ) : 'standard';
	if ( ! in_array( $offer_type, array( 'standard', 'promo' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Невалиден тип оферта.', 'mtunicredit' ) ), 400 );
	}
	if ( ! in_array( $scheme_type, array( 'standard', 'promo' ), true ) ) {
		wp_send_json_error( array( 'message' => __( 'Невалидна схема.', 'mtunicredit' ) ), 400 );
	}

	$parva_raw = isset( $_POST['parva'] ) ? wp_unslash( $_POST['parva'] ) : '0';
	$parva     = is_numeric( $parva_raw ) ? (float) $parva_raw : 0.0;

	$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
	$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;
	$quantity     = isset( $_POST['quantity'] ) ? (int) wp_unslash( $_POST['quantity'] ) : 0;
	// Client line_price is informational only and must never control financing amounts.

	$line = mtuc_resolve_authoritative_product_financing_line( $product_id, $variation_id, $quantity );
	if ( is_wp_error( $line ) ) {
		mtuc_send_customer_safe_json_error( $line, 400, 'general' );
	}

	$product      = $line['product'];
	$parent_id    = (int) $line['parent_id'];
	$variation_id = (int) $line['variation_id'];
	$quantity     = (int) $line['quantity'];
	$price        = (float) $line['line_total'];

	$lock_key   = mtuc_build_popup_submit_lock_key( $parent_id, $variation_id );
	$lock_owner = mtuc_acquire_popup_submit_lock( $lock_key );
	if ( ! $lock_owner ) {
		wp_send_json_error(
			array( 'message' => __( 'Заявката вече се обработва. Моля, изчакайте.', 'mtunicredit' ) ),
			429
		);
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_customer_safe_json_error( $shop, 500, 'configuration' );
	}

	$currency = mtuc_resolve_transaction_currency( $shop );
	if ( is_wp_error( $currency ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_customer_safe_json_error( $currency, 400, 'general' );
	}

	if ( ! mtuc_is_product_price_in_shop_range( $shop, $price ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		wp_send_json_error( array( 'message' => __( 'Цената на продукта е извън допустимия диапазон.', 'mtunicredit' ) ), 400 );
	}

	$coeff_list  = mtuc_get_shop_coeff_list( $shop );
	$calculation = mtuc_calculate_popup_credit(
		$shop,
		$coeff_list,
		$price,
		$months,
		$offer_type,
		$parva,
		$product,
		$filter_id,
		$scheme_type
	);

	if ( is_wp_error( $calculation ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_customer_safe_json_error( $calculation, 400, 'general' );
	}

	$operation_token = mtuc_get_submitted_operation_token();
	if ( is_wp_error( $operation_token ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_customer_safe_json_error( $operation_token, 400, 'general' );
	}

	$scope_key = mtuc_build_product_operation_scope_key( $parent_id, $variation_id );
	$resolved  = mtuc_resolve_popup_financing_order(
		$operation_token,
		$scope_key,
		static function ( $early_bind = null, $existing = null, $bind_context = array() ) use ( $customer, $calculation, $product, $parent_id, $variation_id, $quantity, $price ) {
			return mtuc_create_popup_pending_order(
				$customer,
				$calculation,
				$product,
				$parent_id,
				$variation_id,
				$quantity,
				$price,
				$early_bind,
				$existing,
				$bind_context
			);
		}
	);

	if ( is_wp_error( $resolved ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		$status = 'mtuc_operation_contention' === $resolved->get_error_code() ? 429 : 500;
		mtuc_send_customer_safe_json_error( $resolved, $status, 'general' );
	}

	$order = $resolved['order'];

	$product_snap = mtuc_read_product_operation_snapshot( $order );
	if ( null !== $product_snap ) {
		$customer     = is_array( $product_snap['customer'] ) ? $product_snap['customer'] : $customer;
		$calculation  = is_array( $product_snap['calculation'] ) ? $product_snap['calculation'] : $calculation;
		$parent_id    = (int) ( $product_snap['product_id'] ?? $parent_id );
		$variation_id = (int) ( $product_snap['variation_id'] ?? $variation_id );
		$quantity     = max( 1, (int) ( $product_snap['quantity'] ?? $quantity ) );
		$resolved_p   = mtuc_resolve_snapshot_product( $parent_id, $variation_id );
		if ( ! is_wp_error( $resolved_p ) ) {
			$product = $resolved_p;
		}
	}

	if ( function_exists( 'mtuc_resolve_order_process_for_banking' ) ) {
		$process_id = mtuc_resolve_order_process_for_banking( $order, is_array( $shop ) ? $shop : array() );
		if ( is_wp_error( $process_id ) ) {
			mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
			mtuc_send_customer_safe_json_error( $process_id, 500, 'general' );
		}
		$process2 = ( 2 === (int) $process_id );
	}

	if ( $process2 ) {
		mtuc_save_order_process2_customer_meta( $order, $customer );
	}
	$order->save();

	$submission = mtuc_complete_product_popup_bank_submission(
		$order,
		$customer,
		$calculation,
		$product,
		$parent_id,
		$variation_id,
		$quantity,
		$shop,
		$process2
	);

	if ( is_wp_error( $submission ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_customer_safe_json_error( $submission, 500, 'cp' );
	}

	if ( ! empty( $submission['bank_unavailable'] ) ) {
		mtuc_release_popup_submit_lock( $lock_key, $lock_owner );
		mtuc_send_popup_bank_unavailable_response( $order );
	}

	mtuc_accept_popup_financing_order( $order );
	mtuc_release_popup_submit_lock( $lock_key, $lock_owner );

	$cp_order_id   = (int) ( $submission['cp_order_id'] ?? $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' ) );
	$is_process2   = ! empty( $submission['process2'] );
	$bank_status   = $is_process2 ? MTUC_BANK_STATUS_SENT_PROCESS2 : MTUC_BANK_STATUS_SENT_PROCESS1;
	$success_message = $is_process2
		? mtuc_get_process2_confirmation_message()
		: __( 'Пренасочване към UniCredit за довършване на заявката.', 'mtunicredit' );

	wp_send_json_success(
		array(
			'order_id'     => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'cp_order_id'  => $cp_order_id,
			'bank_status'  => $bank_status,
			'redirect_url' => (string) $submission['redirect_url'],
			'message'      => $success_message,
		)
	);
}

/**
 * Register WooCommerce order meta box for popup credit data.
 *
 * @return void
 */
function mtuc_register_admin_order_credit_meta_box(): void {
	$screen_ids = array( 'shop_order' );

	if ( function_exists( 'wc_get_page_screen_id' ) ) {
		$hpos_screen = wc_get_page_screen_id( 'shop-order' );
		if ( is_string( $hpos_screen ) && '' !== $hpos_screen ) {
			$screen_ids[] = $hpos_screen;
		}
	}

	foreach ( array_unique( $screen_ids ) as $screen_id ) {
		add_meta_box(
			'mtuc-order-credit-meta',
			__( 'УниКредит — кредитна заявка', 'mtunicredit' ),
			'mtuc_render_admin_order_credit_meta_box',
			$screen_id,
			'side',
			'high'
		);
	}
}

/**
 * Resolve WC_Order from admin meta box context (legacy post or HPOS).
 *
 * @param mixed $post_or_order Post, order, or null.
 * @return WC_Order|null
 */
function mtuc_resolve_admin_screen_order( $post_or_order = null ): ?WC_Order {
	if ( $post_or_order instanceof WC_Order ) {
		return $post_or_order;
	}

	if ( $post_or_order instanceof WP_Post && 'shop_order' === $post_or_order->post_type ) {
		$order = wc_get_order( $post_or_order->ID );
		return $order instanceof WC_Order ? $order : null;
	}

	if ( isset( $_GET['id'] ) ) {
		$order = wc_get_order( absint( wp_unslash( $_GET['id'] ) ) );
		return $order instanceof WC_Order ? $order : null;
	}

	return null;
}

/**
 * Human-readable bank status for an order (admin list and meta box).
 *
 * @param WC_Order $order Order instance.
 * @return string Empty when the order has no UniCredit bank status meta.
 */
function mtuc_get_order_bank_status_display( WC_Order $order ): string {
	$bank_status = (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS );
	if ( '' === $bank_status ) {
		return '';
	}

	$labels = mtuc_get_bank_status_labels();
	if ( isset( $labels[ $bank_status ] ) ) {
		return $labels[ $bank_status ];
	}

	$stored_label = (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'bank_status_label' );
	if ( '' !== $stored_label ) {
		return $stored_label;
	}

	return $bank_status;
}

/**
 * Add UniCredit bank status column to WooCommerce orders list.
 *
 * @param array<string, string> $columns Existing columns.
 * @return array<string, string>
 */
function mtuc_add_orders_list_bank_status_column( array $columns ): array {
	$new_columns = array();

	foreach ( $columns as $key => $label ) {
		$new_columns[ $key ] = $label;

		if ( 'order_status' === $key ) {
			$new_columns['mtuc_bank_status'] = __( 'UniCredit статус', 'mtunicredit' );
		}
	}

	if ( ! isset( $new_columns['mtuc_bank_status'] ) ) {
		$new_columns['mtuc_bank_status'] = __( 'UniCredit статус', 'mtunicredit' );
	}

	return $new_columns;
}

/**
 * Render UniCredit bank status column in WooCommerce orders list.
 *
 * @param string       $column            Column key.
 * @param int|WC_Order $order_or_post_id  Post ID (legacy) or order (HPOS).
 * @return void
 */
function mtuc_render_orders_list_bank_status_column( string $column, $order_or_post_id ): void {
	if ( 'mtuc_bank_status' !== $column ) {
		return;
	}

	if ( $order_or_post_id instanceof WC_Order ) {
		$order = $order_or_post_id;
	} else {
		$order = wc_get_order( (int) $order_or_post_id );
	}

	if ( ! $order instanceof WC_Order ) {
		echo '&mdash;';
		return;
	}

	$status_text = mtuc_get_order_bank_status_display( $order );
	echo '' !== $status_text ? esc_html( $status_text ) : '&mdash;';
}

/**
 * Render credit meta box on WooCommerce order edit screen.
 *
 * @param mixed $post_or_order Post, order, or screen-specific object.
 * @return void
 */
function mtuc_render_admin_order_credit_meta_box( $post_or_order ): void {
	$order = mtuc_resolve_admin_screen_order( $post_or_order );
	if ( ! $order instanceof WC_Order ) {
		return;
	}

	$rows = mtuc_get_admin_order_credit_meta_rows( $order );
	if ( empty( $rows ) ) {
		echo '<p class="description">' . esc_html__( 'Няма записани кредитни данни за тази поръчка.', 'mtunicredit' ) . '</p>';
		return;
	}

	echo '<table class="widefat striped mtuc-order-credit-table">';
	echo '<tbody>';

	foreach ( $rows as $label => $value ) {
		echo '<tr>';
		echo '<th scope="row">' . esc_html( $label ) . '</th>';
		echo '<td>' . esc_html( $value ) . '</td>';
		echo '</tr>';
	}

	echo '</tbody>';
	echo '</table>';

	$pending = defined( 'MTUC_ORDER_META_CP_SYNC_PENDING' )
		? sanitize_key( (string) $order->get_meta( MTUC_ORDER_META_CP_SYNC_PENDING ) )
		: '';
	if ( '' !== $pending && current_user_can( 'edit_shop_orders' ) ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=mtuc_retry_cp_status_sync&order_id=' . $order->get_id() ),
			'mtuc_retry_cp_status_sync_' . $order->get_id()
		);
		echo '<p style="margin-top:8px;"><a class="button button-secondary" href="' . esc_url( $url ) . '">';
		echo esc_html__( 'Повтори синхронизация към КП', 'mtunicredit' );
		echo '</a></p>';
	}
}

/**
 * Render UniCredit leasing details table on the thank-you page (Process 2).
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_render_thankyou_process2_credit_section( WC_Order $order ): void {
	if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
		return;
	}

	if ( ! mtuc_is_process2_order( $order ) || ! mtuc_should_show_order_credit_in_email( $order ) ) {
		return;
	}

	$rows = mtuc_get_order_credit_meta_rows( $order, MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER );
	if ( empty( $rows ) ) {
		return;
	}

	?>
	<section class="woocommerce-order-details mtuc-thankyou-credit-details">
		<h2 class="woocommerce-order-details__title"><?php esc_html_e( 'УниКредит лизинг', 'mtunicredit' ); ?></h2>
		<table class="woocommerce-table woocommerce-table--order-details shop_table mtuc-thankyou-credit-details__table">
			<tbody>
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( (string) $label ); ?></th>
					<td><?php echo esc_html( (string) $value ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php
}

// Process 2 uni_email dispatch lives in includes/mtuc-financing-email.php (AUD-WOO-016 Step 7).
