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

/** Order meta prefix for credit calculation snapshot. */
const MTUC_ORDER_META_PREFIX = '_mtuc_';

/** Bank status: WooCommerce order created (step 1) — synced with CP as wc_created. */
const MTUC_BANK_STATUS_WC_CREATED = 'wc_created';

/** Bank status: order created in CP (step 2) — synced with CP as cp_sent. */
const MTUC_BANK_STATUS_CP_SENT = 'cp_sent';

/** Bank status: order sent to SmartUCF (step 3) — synced with CP as smartucf_sent. */
const MTUC_BANK_STATUS_SMARTUCF_SENT = 'smartucf_sent';

/**
 * Human-readable bank status labels (WC admin and CP API must stay aligned).
 *
 * @return array<string, string>
 */
function mtuc_get_bank_status_labels(): array {
	return array(
		MTUC_BANK_STATUS_WC_CREATED      => __( 'Създаден в магазина', 'mtunicredit' ),
		MTUC_BANK_STATUS_CP_SENT         => __( 'Създаден в КП Банка', 'mtunicredit' ),
		MTUC_BANK_STATUS_SMARTUCF_SENT   => __( 'Създаден в SmartUCF', 'mtunicredit' ),
	);
}

/**
 * CP order status fields aligned with WC bank status meta.
 *
 * @param string $bank_status_key Status key (see MTUC_BANK_STATUS_*).
 * @return array{status: string, status_id: string}
 */
function mtuc_get_cp_order_status_payload( string $bank_status_key ): array {
	$labels = mtuc_get_bank_status_labels();

	return array(
		'status'    => $labels[ $bank_status_key ] ?? $bank_status_key,
		'status_id' => $bank_status_key,
	);
}

/**
 * Shop order_id sent to CP (max 13 chars).
 *
 * @param WC_Order $order WooCommerce order.
 * @return string
 */
function mtuc_get_cp_shop_order_id( WC_Order $order ): string {
	$order_number = (string) $order->get_order_number();
	if ( strlen( $order_number ) > 13 ) {
		$order_number = substr( $order_number, 0, 13 );
	}

	return $order_number;
}

/**
 * Sync CP order status with WooCommerce bank status meta.
 *
 * @param WC_Order $order           WooCommerce order.
 * @param string   $bank_status_key Status key (see MTUC_BANK_STATUS_*).
 * @return array<string, mixed>|WP_Error
 */
function mtuc_sync_cp_order_bank_status( WC_Order $order, string $bank_status_key ) {
	$cp_status = mtuc_get_cp_order_status_payload( $bank_status_key );

	return Mtuc_Cp_Api_Client::update_order_status(
		mtuc_get_cp_shop_order_id( $order ),
		$cp_status['status'],
		$cp_status['status_id'],
		$order->get_id()
	);
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
	add_filter( 'woocommerce_thankyou_order_received_text', 'mtuc_filter_thankyou_text_bank_unavailable', 20, 2 );
	add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_thankyou_styles' );
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
 * Validate popup step-2 customer payload from POST.
 *
 * @param array<string, mixed> $post Raw POST.
 * @return array<string, string>|WP_Error
 */
function mtuc_validate_popup_customer_payload( array $post ) {
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
	if ( '' === $phone || ! preg_match( '/^[-0-9+() ]+$/', $phone ) || ! preg_match( '/\d/', $phone ) ) {
		return new WP_Error( 'mtuc_invalid_phone', __( 'Въведете валиден телефонен номер.', 'mtunicredit' ) );
	}
	if ( '' === $email || ! is_email( $email ) ) {
		return new WP_Error( 'mtuc_invalid_email', __( 'Въведете валиден e-mail адрес.', 'mtunicredit' ) );
	}

	return array(
		'first_name' => $first_name,
		'last_name'  => $last_name,
		'address'    => $address,
		'phone'      => $phone,
		'email'      => $email,
	);
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
 * Try to acquire a short-lived submit lock.
 *
 * @param string $lock_key Lock key.
 * @return bool
 */
function mtuc_acquire_popup_submit_lock( string $lock_key ): bool {
	$transient = 'mtuc_submit_lock_' . $lock_key;
	if ( get_transient( $transient ) ) {
		return false;
	}

	set_transient( $transient, 1, 45 );

	return true;
}

/**
 * Release submit lock.
 *
 * @param string $lock_key Lock key.
 * @return void
 */
function mtuc_release_popup_submit_lock( string $lock_key ): void {
	delete_transient( 'mtuc_submit_lock_' . $lock_key );
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
 * Resolve CP address fields: popup billing → address, shipping → address2.
 *
 * @param WC_Order              $order    Order with billing/shipping set (unused, kept for signature).
 * @param array<string, string> $customer Validated popup customer fields.
 * @return array{address: string, address2: string}
 */
function mtuc_resolve_cp_order_addresses( WC_Order $order, array $customer ): array {
	unset( $order );

	$address = mtuc_join_address_parts( array( (string) $customer['address'] ) );
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

	$quantity = max( 1, (int) $item->get_quantity() );
	$unit_inc = $line_price_inc_tax / $quantity;

	if ( wc_prices_include_tax() ) {
		$line_subtotal = $line_price_inc_tax;
		$line_total    = $line_price_inc_tax;
	} else {
		$line_subtotal = (float) wc_get_price_excluding_tax(
			$product,
			array(
				'qty'   => $quantity,
				'price' => $unit_inc,
			)
		);
		$line_total = $line_subtotal;
	}

	$item->set_subtotal( $line_subtotal );
	$item->set_total( $line_total );
	$item->save();
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
		'submission_source' => 'product_popup',
		'offer_type'        => (string) ( $calculation['popup_offer_type'] ?? '' ),
		'scheme_type'       => (string) ( $calculation['scheme_type'] ?? '' ),
		'scheme_key'        => (string) ( $calculation['scheme_key'] ?? '' ),
		'filter_id'         => (int) ( $calculation['filter_id'] ?? 0 ),
		'months'            => (int) ( $calculation['months'] ?? 0 ),
		'kop_code'          => (string) ( $calculation['kop_code'] ?? '' ),
		'price'             => (float) ( $calculation['price'] ?? 0 ),
		'parva'             => (float) ( $calculation['parva'] ?? 0 ),
		'loan_amount'       => (float) ( $calculation['loan_amount'] ?? 0 ),
		'monthly_installment' => (float) ( $calculation['monthly_installment'] ?? 0 ),
		'total_payable'     => (float) ( $calculation['total_payable'] ?? 0 ),
		'glp'               => (float) ( $calculation['glp'] ?? 0 ),
		'gpr'               => (float) ( $calculation['gpr'] ?? 0 ),
		'product_id'        => (int) ( $context['product_id'] ?? 0 ),
		'variation_id'      => (int) ( $context['variation_id'] ?? 0 ),
		'quantity'          => (int) ( $context['quantity'] ?? 1 ),
	);

	foreach ( $meta_map as $key => $value ) {
		$order->update_meta_data( MTUC_ORDER_META_PREFIX . $key, $value );
	}
}

/**
 * Update bank status meta and append an order note.
 *
 * @param WC_Order $order       Order instance.
 * @param string   $status_key  Status key (see MTUC_BANK_STATUS_*).
 * @param string   $extra_note  Optional detail appended to the note.
 * @return void
 */
function mtuc_update_order_bank_status( WC_Order $order, string $status_key, string $extra_note = '' ): void {
	$labels = mtuc_get_bank_status_labels();
	$label  = $labels[ $status_key ] ?? $status_key;

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
 * Mark popup order after bank step failure; redirect customer to thank-you page.
 *
 * @param WC_Order $order Order instance.
 * @return void
 */
function mtuc_handle_popup_order_bank_unavailable( WC_Order $order ): void {
	$order->add_order_note(
		__( 'Заявката не беше изпратена успешно към банката.', 'mtunicredit' )
	);
	$order->update_meta_data( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE, 1 );
	$order->save();
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

	if ( 1 !== (int) $order->get_meta( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE ) ) {
		return $text;
	}

	$order->delete_meta_data( MTUC_ORDER_META_BANK_UNAVAILABLE_NOTICE );
	$order->save();

	$intro = sprintf(
		/* translators: %s: order number */
		__( 'Благодарим Ви. Поръчка №%s е регистрирана в магазина.', 'mtunicredit' ),
		$order->get_order_number()
	);

	$bank_notice = __( 'В момента Банката не може да обработи Вашата заявка. Моля опитайте по-късно.', 'mtunicredit' );

	return sprintf(
		'%s<br><span class="mtuc-thankyou-bank-unavailable">%s</span>',
		esc_html( $intro ),
		esc_html( $bank_notice )
	);
}

/**
 * Create a pending WooCommerce order from popup submission.
 *
 * @param array<string, string> $customer   Validated customer fields.
 * @param array<string, mixed>  $calculation Server-side calculation snapshot.
 * @param WC_Product            $product     Product or variation to add.
 * @param int                   $parent_id   Parent product ID.
 * @param int                   $variation_id Variation ID (0 if none).
 * @param int                   $quantity    Line quantity.
 * @param float                 $line_price  Line total including tax.
 * @return WC_Order|WP_Error
 */
function mtuc_create_popup_pending_order(
	array $customer,
	array $calculation,
	WC_Product $product,
	int $parent_id,
	int $variation_id,
	int $quantity,
	float $line_price
) {
	if ( ! function_exists( 'wc_create_order' ) ) {
		return new WP_Error( 'mtuc_wc_missing', __( 'WooCommerce не е наличен.', 'mtunicredit' ) );
	}

	$create_args = array();
	if ( is_user_logged_in() ) {
		$create_args['customer_id'] = get_current_user_id();
	}

	$order = wc_create_order( $create_args );
	if ( is_wp_error( $order ) ) {
		return $order;
	}

	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'mtuc_order_create_failed', __( 'Поръчката не може да бъде създадена.', 'mtunicredit' ) );
	}

	$addresses = mtuc_resolve_popup_order_addresses( $customer );
	$order->set_address( $addresses['billing'], 'billing' );
	$order->set_address( $addresses['shipping'], 'shipping' );

	$added = $order->add_product( $product, $quantity );
	if ( ! $added ) {
		$order->delete( true );
		return new WP_Error( 'mtuc_order_product_failed', __( 'Продуктът не може да бъде добавен към поръчката.', 'mtunicredit' ) );
	}

	mtuc_sync_order_line_price( $order, $line_price );
	$order->calculate_totals( false );

	$order->set_payment_method( MTUC_PAYMENT_GATEWAY_ID );
	$order->set_payment_method_title( __( 'УниКредит покупки на Кредит', 'mtunicredit' ) );
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

	mtuc_update_order_bank_status( $order, MTUC_BANK_STATUS_WC_CREATED );

	$order->set_status( 'pending', '', true );

	$order->save();

	return $order;
}

/**
 * Resolve CP order currency code from shop settings.
 *
 * @param array<string, mixed> $shop Shop `data` object from CP.
 * @return string BGN|EUR
 */
function mtuc_get_cp_order_currency( array $shop ): string {
	$uni_eur = (int) ( $shop['uni_eur'] ?? 0 );

	if ( in_array( $uni_eur, array( 2, 3 ), true ) ) {
		return 'EUR';
	}

	return 'BGN';
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
 * Build CP StoreOrderRequest payload from popup order data.
 *
 * @param WC_Order              $order       WooCommerce order.
 * @param array<string, string> $customer    Validated customer fields.
 * @param array<string, mixed>  $calculation Server-side calculation snapshot.
 * @param WC_Product            $product     Product or variation line item.
 * @param int                   $parent_id   Parent product ID.
 * @param int                   $variation_id Variation ID (0 if none).
 * @param int                   $quantity    Line quantity.
 * @param array<string, mixed>  $shop        Shop `data` object from CP.
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
	$cp_status      = mtuc_get_cp_order_status_payload( MTUC_BANK_STATUS_CP_SENT );

	$product_id_for_cp = $variation_id > 0 ? $variation_id : $parent_id;
	$product_name      = $product->get_name();
	if ( strlen( $product_name ) > 255 ) {
		$product_name = substr( $product_name, 0, 255 );
	}

	return array(
		'order_id'      => $order_number,
		'name'          => $full_name,
		'phone'         => $phone,
		'email'         => $email,
		'address'       => $cp_addresses['address'],
		'address2'      => $cp_addresses['address2'],
		'price'         => round( (float) ( $calculation['price'] ?? 0 ), 2 ),
		'vnoska'        => round( (float) ( $calculation['monthly_installment'] ?? 0 ), 2 ),
		'gpr'           => round( (float) ( $calculation['gpr'] ?? 0 ), 2 ),
		'vnoski'        => (int) ( $calculation['months'] ?? 0 ),
		'parva'         => round( (float) ( $calculation['parva'] ?? 0 ), 2 ),
		'status'        => $cp_status['status'],
		'status_id'     => $cp_status['status_id'],
		'products_id'   => (string) $product_id_for_cp,
		'products_name' => $product_name,
		'products_q'    => (string) max( 1, $quantity ),
		'type_client'   => mtuc_get_cp_type_client(),
		'currency'      => mtuc_get_cp_order_currency( $shop ),
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
	$payload  = mtuc_build_cp_order_payload(
		$order,
		$customer,
		$calculation,
		$product,
		$parent_id,
		$variation_id,
		$quantity,
		$shop
	);
	$response = Mtuc_Cp_Api_Client::create_order( $payload, $order->get_id() );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$cp_order_id = 0;
	if ( isset( $response['data']['id'] ) ) {
		$cp_order_id = (int) $response['data']['id'];
	}

	if ( $cp_order_id > 0 ) {
		$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', $cp_order_id );
	}

	mtuc_update_order_bank_status( $order, MTUC_BANK_STATUS_CP_SENT );

	$order->save();

	return $response;
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
 * Build SmartUCF line items array for popup order.
 *
 * @param WC_Product           $product      Product or variation line item.
 * @param int                  $parent_id    Parent product ID.
 * @param int                  $variation_id Variation ID (0 if none).
 * @param int                  $quantity     Line quantity.
 * @param array<string, mixed> $calculation  Server-side calculation snapshot.
 * @return array<int, array<string, mixed>>
 */
function mtuc_build_smartucf_items(
	WC_Product $product,
	int $parent_id,
	int $variation_id,
	int $quantity,
	array $calculation
): array {
	$quantity   = max( 1, $quantity );
	$line_total = isset( $calculation['price'] ) ? (float) $calculation['price'] : 0.0;
	$unit_price = round( $line_total / $quantity, 2 );

	$category_product = $product;
	if ( $variation_id > 0 ) {
		$parent = mtuc_get_wc_product_by_id( $parent_id );
		if ( $parent instanceof WC_Product ) {
			$category_product = $parent;
		}
	}

	$category_ids     = mtuc_get_product_category_ids( $category_product );
	$product_category = ! empty( $category_ids ) ? (int) $category_ids[0] : 0;
	$item_code        = $variation_id > 0 ? $variation_id : $parent_id;

	return array(
		array(
			'name'        => mtuc_sanitize_smartucf_text( $product->get_name() ),
			'code'        => $item_code,
			'type'        => $product_category,
			'count'       => $quantity,
			'singlePrice' => $unit_price,
		),
	);
}

/**
 * Build SmartUCF sucfOnlineSessionStart payload.
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
		'totalPrice'            => isset( $calculation['price'] ) ? (float) $calculation['price'] : 0.0,
		'initialPayment'        => isset( $calculation['parva'] ) ? (float) $calculation['parva'] : 0.0,
		'installmentCount'      => isset( $calculation['months'] ) ? (int) $calculation['months'] : 0,
		'monthlyPayment'        => isset( $calculation['monthly_installment'] ) ? (float) $calculation['monthly_installment'] : 0.0,
		'items'                 => mtuc_build_smartucf_items( $product, $parent_id, $variation_id, $quantity, $calculation ),
	);
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

	$result = Mtuc_Smartucf_Api_Client::start_session( $payload, $shop );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'smartucf_session_id', $result['session_id'] );
	$order->save();

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

	$customer = mtuc_validate_popup_customer_payload( $_POST );
	if ( is_wp_error( $customer ) ) {
		wp_send_json_error( array( 'message' => $customer->get_error_message() ), 400 );
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
	$line_price   = isset( $_POST['line_price'] ) ? (float) wp_unslash( $_POST['line_price'] ) : 0.0;
	$quantity     = isset( $_POST['quantity'] ) ? absint( wp_unslash( $_POST['quantity'] ) ) : 1;
	$quantity     = max( 1, $quantity );

	$parent_id = $product_id;
	$load_id   = $variation_id > 0 ? $variation_id : $product_id;
	$product   = mtuc_get_wc_product_by_id( $load_id );

	if ( ! $product instanceof WC_Product ) {
		wp_send_json_error( array( 'message' => __( 'Невалиден продукт.', 'mtunicredit' ) ), 400 );
	}

	if ( $variation_id <= 0 && $product->is_type( 'variation' ) ) {
		$variation_id = $product->get_id();
		$parent_id    = (int) $product->get_parent_id();
	}

	if ( $variation_id <= 0 ) {
		$parent_product = mtuc_get_wc_product_by_id( $parent_id );
		if ( $parent_product instanceof WC_Product && $parent_product->is_type( 'variable' ) ) {
			wp_send_json_error( array( 'message' => __( 'Моля, изберете вариация на продукта.', 'mtunicredit' ) ), 400 );
		}
	}

	if ( ! $product->is_purchasable() ) {
		wp_send_json_error( array( 'message' => __( 'Продуктът не може да бъде закупен.', 'mtunicredit' ) ), 400 );
	}

	if ( ! $product->is_in_stock() ) {
		wp_send_json_error( array( 'message' => __( 'Продуктът не е наличен.', 'mtunicredit' ) ), 400 );
	}

	$lock_key = mtuc_build_popup_submit_lock_key( $parent_id, $variation_id );
	if ( ! mtuc_acquire_popup_submit_lock( $lock_key ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Заявката вече се обработва. Моля, изчакайте.', 'mtunicredit' ) ),
			429
		);
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) ) {
		mtuc_release_popup_submit_lock( $lock_key );
		wp_send_json_error( array( 'message' => $shop->get_error_message() ), 500 );
	}

	$price = $line_price > 0 ? round( $line_price, 2 ) : mtuc_get_product_price( $product );
	if ( null === $price ) {
		mtuc_release_popup_submit_lock( $lock_key );
		wp_send_json_error( array( 'message' => __( 'Не може да се определи цената на продукта.', 'mtunicredit' ) ), 400 );
	}

	if ( ! mtuc_is_product_price_in_shop_range( $shop, $price ) ) {
		mtuc_release_popup_submit_lock( $lock_key );
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
		mtuc_release_popup_submit_lock( $lock_key );
		wp_send_json_error( array( 'message' => $calculation->get_error_message() ), 400 );
	}

	$order = mtuc_create_popup_pending_order(
		$customer,
		$calculation,
		$product,
		$parent_id,
		$variation_id,
		$quantity,
		$price
	);

	if ( is_wp_error( $order ) ) {
		mtuc_release_popup_submit_lock( $lock_key );
		wp_send_json_error( array( 'message' => $order->get_error_message() ), 500 );
	}

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
		mtuc_release_popup_submit_lock( $lock_key );
		mtuc_handle_popup_order_bank_unavailable( $order );
		mtuc_send_popup_bank_unavailable_response( $order );
	}

	$cp_order_id = (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'cp_order_id' );

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
		mtuc_release_popup_submit_lock( $lock_key );
		mtuc_handle_popup_order_bank_unavailable( $order );
		mtuc_send_popup_bank_unavailable_response( $order );
	}

	$cp_status_result = mtuc_sync_cp_order_bank_status( $order, MTUC_BANK_STATUS_SMARTUCF_SENT );
	if ( is_wp_error( $cp_status_result ) ) {
		mtuc_release_popup_submit_lock( $lock_key );
		mtuc_handle_popup_order_bank_unavailable( $order );
		mtuc_send_popup_bank_unavailable_response( $order );
	}

	mtuc_update_order_bank_status( $order, MTUC_BANK_STATUS_SMARTUCF_SENT );
	$order->save();

	mtuc_release_popup_submit_lock( $lock_key );

	wp_send_json_success(
		array(
			'order_id'     => $order->get_id(),
			'order_number' => $order->get_order_number(),
			'cp_order_id'  => $cp_order_id,
			'bank_status'  => MTUC_BANK_STATUS_SMARTUCF_SENT,
			'redirect_url' => $smartucf_result['redirect_url'],
			'message'      => __( 'Пренасочване към UniCredit за довършване на заявката.', 'mtunicredit' ),
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
 * Credit meta rows for admin order screen (label => value).
 *
 * @param WC_Order $order Order instance.
 * @return array<string, string>
 */
function mtuc_get_admin_order_credit_meta_rows( WC_Order $order ): array {
	$bank_status = (string) $order->get_meta( MTUC_ORDER_META_BANK_STATUS );
	if ( '' === $bank_status ) {
		return array();
	}

	$labels      = mtuc_get_bank_status_labels();
	$status_text = $labels[ $bank_status ] ?? (string) $order->get_meta( MTUC_ORDER_META_PREFIX . 'bank_status_label' );

	$rows = array(
		__( 'Статус към банката', 'mtunicredit' ) => $status_text,
	);

	$months = (int) $order->get_meta( MTUC_ORDER_META_PREFIX . 'months' );
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

	$rows[ __( 'Първоначална вноска', 'mtunicredit' ) ]   = number_format( $parva, 2, '.', '' );
	$rows[ __( 'Сума на заема', 'mtunicredit' ) ]          = number_format( $loan, 2, '.', '' );
	$rows[ __( 'Месечна вноска', 'mtunicredit' ) ]         = number_format( $monthly, 2, '.', '' );
	$rows[ __( 'Обща дължима сума', 'mtunicredit' ) ]      = number_format( $total, 2, '.', '' );
	$rows[ __( 'ГЛП / ГПР', 'mtunicredit' ) ]              = number_format( $glp, 2, '.', '' ) . '% / ' . number_format( $gpr, 2, '.', '' ) . '%';

	return $rows;
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
}
