<?php
/**
 * WooCommerce Blocks integration for mtunicredit payment method.
 *
 * @package MTUC
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers mtunicredit for Cart/Checkout blocks.
 */
final class Mtuc_Payment_Gateway_Blocks extends AbstractPaymentMethodType {

	/**
	 * Classic gateway instance.
	 *
	 * @var Mtuc_Payment_Gateway|null
	 */
	private $gateway = null;

	/**
	 * Payment method name (must match MTUC_PAYMENT_GATEWAY_ID).
	 *
	 * @var string
	 */
	protected $name = MTUC_PAYMENT_GATEWAY_ID;

	/**
	 * Load gateway settings and instance.
	 *
	 * @return void
	 */
	public function initialize(): void {
		$this->settings = get_option( 'woocommerce_' . MTUC_PAYMENT_GATEWAY_ID . '_settings', array() );
		$this->gateway  = new Mtuc_Payment_Gateway();
	}

	/**
	 * Whether scripts should load for this payment method.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( ! $this->gateway instanceof Mtuc_Payment_Gateway ) {
			return false;
		}

		return $this->gateway->is_available();
	}

	/**
	 * Frontend script handles for blocks checkout.
	 *
	 * @return array<int, string>
	 */
	public function get_payment_method_script_handles(): array {
		mtuc_enqueue_checkout_payment_styles();

		$payment_js = MTUC_PLUGIN_DIR . '/js/mtuc-checkout-payment.js';
		$blocks_js  = MTUC_PLUGIN_DIR . '/js/mtuc-checkout-blocks.js';

		wp_register_script(
			'mtuc-checkout-payment',
			MTUC_JS_URI . '/mtuc-checkout-payment.js',
			array( 'jquery' ),
			file_exists( $payment_js ) ? (string) filemtime( $payment_js ) : MTUC_VERSION,
			true
		);

		wp_register_script(
			'mtuc-checkout-blocks',
			MTUC_JS_URI . '/mtuc-checkout-blocks.js',
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-html-entities',
				'wp-i18n',
				'jquery',
				'mtuc-checkout-payment',
			),
			file_exists( $blocks_js ) ? (string) filemtime( $blocks_js ) : MTUC_VERSION,
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'mtuc-checkout-blocks', 'mtunicredit' );
		}

		return array( 'mtuc-checkout-blocks' );
	}

	/**
	 * Data exposed client-side as wcSettings mtunicredit_data.
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		if ( ! $this->gateway instanceof Mtuc_Payment_Gateway ) {
			return array();
		}

		$context = mtuc_get_checkout_payment_context();

		return array(
			'title'       => (string) $this->gateway->title,
			'description' => (string) $this->gateway->description,
			'supports'    => $this->gateway->supports,
			'isAvailable' => $this->gateway->is_available(),
			'fieldsHtml'  => mtuc_render_checkout_payment_fields_html( $context ),
			'checkout'    => mtuc_get_checkout_payment_script_config( $context ),
		);
	}
}
