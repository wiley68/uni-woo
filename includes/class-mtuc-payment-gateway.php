<?php
/**
 * WooCommerce payment gateway for UniCredit leasing checkout.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * УниКредит покупки на Кредит — checkout payment method.
 */
class Mtuc_Payment_Gateway extends WC_Payment_Gateway {

	/**
	 * WooCommerce order status after bank submission (gateway setting).
	 *
	 * @var string
	 */
	public $order_status = 'on-hold';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = MTUC_PAYMENT_GATEWAY_ID;
		$this->method_title       = __( 'УниКредит покупки на Кредит', 'mtunicredit' );
		$this->method_description = __( 'Дава възможност на Вашите клиенти да закупуват стока на изплащане с УниКредит.', 'mtunicredit' );
		$this->has_fields         = true;
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title        = $this->get_option( 'title', __( 'УниКредит покупки на Кредит', 'mtunicredit' ) );
		$this->description  = $this->get_option( 'description', __( 'Плащате стоката с УниКредит покупки на Кредит', 'mtunicredit' ) );
		$this->enabled      = $this->get_option( 'enabled', 'yes' );
		$this->order_status = $this->get_option( 'order_status', 'on-hold' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Admin settings fields.
	 *
	 * @return void
	 */
	public function init_form_fields(): void {
		$this->form_fields = array(
			'enabled'      => array(
				'title'   => __( 'Разреши/Забрани', 'mtunicredit' ),
				'type'    => 'checkbox',
				'label'   => __( 'Разреши УниКредит покупки на Кредит', 'mtunicredit' ),
				'default' => 'yes',
			),
			'title'        => array(
				'title'       => __( 'Заглавие', 'mtunicredit' ),
				'type'        => 'text',
				'description' => __( 'Показва се при избор на метод на плащане.', 'mtunicredit' ),
				'default'     => __( 'УниКредит покупки на Кредит', 'mtunicredit' ),
				'desc_tip'    => true,
			),
			'description'  => array(
				'title'       => __( 'Описание', 'mtunicredit' ),
				'type'        => 'textarea',
				'description' => __( 'Кратко описание под заглавието на метода.', 'mtunicredit' ),
				'default'     => __( 'Плащате стоката с УниКредит покупки на Кредит', 'mtunicredit' ),
				'desc_tip'    => true,
			),
			'order_status' => array(
				'title'       => __( 'Състояние на поръчката', 'mtunicredit' ),
				'type'        => 'select',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Препоръчително: „На изчакване“ — WooCommerce изпраща имейли при преход от „Чакащо плащане“. При „Чакащо плащане“ без промяна имейлите не се изпращат.', 'mtunicredit' ),
				'default'     => 'on-hold',
				'desc_tip'    => true,
				'options'     => wc_get_order_statuses(),
			),
		);
	}

	/**
	 * Whether the gateway can be used for the current cart.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! Mtuc_Settings::is_enabled() ) {
			return false;
		}

		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( ! parent::is_available() ) {
			return false;
		}

		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
		if ( ! in_array( $currency, array( 'BGN', 'EUR' ), true ) ) {
			return false;
		}

		if ( ! function_exists( 'mtuc_build_cart_calculator_context' ) ) {
			return false;
		}

		$context = mtuc_build_cart_calculator_context();
		if ( null === $context ) {
			return false;
		}

		$common_standard = isset( $context['common_standard'] ) && is_array( $context['common_standard'] )
			? $context['common_standard']
			: array();
		$common_promo    = isset( $context['common_promo'] ) && is_array( $context['common_promo'] )
			? $context['common_promo']
			: array();

		return ! empty( $common_standard ) || ! empty( $common_promo );
	}

	/**
	 * Render scheme selection fields on checkout.
	 *
	 * @return void
	 */
	public function payment_fields(): void {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in template.
		echo mtuc_render_checkout_payment_fields_html();
	}

	/**
	 * Validate scheme fields before order placement.
	 *
	 * @return bool
	 */
	public function validate_fields(): bool {
		$posted = mtuc_parse_checkout_scheme_post(
			array(
				'scheme_key' => isset( $_POST['mtuc_scheme_key'] ) ? wp_unslash( $_POST['mtuc_scheme_key'] ) : '',
				'offer_type' => isset( $_POST['mtuc_offer_type'] ) ? wp_unslash( $_POST['mtuc_offer_type'] ) : 'standard',
				'parva'      => isset( $_POST['mtuc_parva'] ) ? wp_unslash( $_POST['mtuc_parva'] ) : '0',
			)
		);

		if ( '' === $posted['scheme_key'] || $posted['months'] <= 0 ) {
			wc_add_notice( __( 'Моля, изберете схема за погасяване.', 'mtunicredit' ), 'error' );
			return false;
		}

		$cart_state = mtuc_resolve_cart_scheme_state();
		if ( is_wp_error( $cart_state ) ) {
			wc_add_notice( $cart_state->get_error_message(), 'error' );
			return false;
		}

		$shop = mtuc_get_shop_data();
		if ( is_wp_error( $shop ) ) {
			wc_add_notice( $shop->get_error_message(), 'error' );
			return false;
		}

		$common = mtuc_resolve_checkout_scheme_common( $cart_state );

		$coeff_list  = mtuc_get_shop_coeff_list( $shop );
		$calculation = mtuc_calculate_cart_popup_credit(
			$shop,
			$coeff_list,
			(float) ( $cart_state['cart_total'] ?? 0 ),
			$posted['months'],
			'standard',
			$posted['parva'],
			$posted['filter_id'],
			$posted['scheme_type'],
			$common
		);

		if ( is_wp_error( $calculation ) ) {
			wc_add_notice( $calculation->get_error_message(), 'error' );
			return false;
		}

		if ( mtuc_is_shop_process_2( $shop ) ) {
			$validated = mtuc_validate_process2_fields_from_post( $_POST );
			if ( is_wp_error( $validated ) ) {
				wc_add_notice( $validated->get_error_message(), 'error' );
				return false;
			}
		}

		return true;
	}

	/**
	 * Process checkout payment: CP + SmartUCF.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array<string, string>
	 */
	public function process_payment( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			wc_add_notice( __( 'Поръчката не може да бъде намерена.', 'mtunicredit' ), 'error' );

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		$posted = array(
			'scheme_key' => isset( $_POST['mtuc_scheme_key'] ) ? wp_unslash( $_POST['mtuc_scheme_key'] ) : '',
			'offer_type' => isset( $_POST['mtuc_offer_type'] ) ? wp_unslash( $_POST['mtuc_offer_type'] ) : 'standard',
			'parva'      => isset( $_POST['mtuc_parva'] ) ? wp_unslash( $_POST['mtuc_parva'] ) : '0',
		);

		$result = mtuc_process_checkout_order_payment( $order, $posted );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );

			return array(
				'result'   => 'fail',
				'redirect' => '',
			);
		}

		mtuc_empty_checkout_cart_after_order();

		$thankyou_url = mtuc_get_checkout_order_thankyou_url( $order );

		if ( ! empty( $result['bank_unavailable'] ) ) {
			return array(
				'result'   => 'success',
				'redirect' => $thankyou_url,
			);
		}

		return array(
			'result'   => 'success',
			'redirect' => $thankyou_url,
		);
	}
}
