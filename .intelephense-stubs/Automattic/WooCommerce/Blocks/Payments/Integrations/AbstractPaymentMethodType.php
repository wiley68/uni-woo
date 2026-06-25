<?php
/**
 * Intelephense stub — not loaded at runtime.
 *
 * @package MTUC
 */

namespace Automattic\WooCommerce\Blocks\Payments\Integrations;

abstract class AbstractPaymentMethodType {

	/** @var string */
	protected $name = '';

	/** @var array<string, mixed> */
	protected $settings = array();

	abstract public function initialize();

	abstract public function is_active();

	/** @return array<int, string> */
	abstract public function get_payment_method_script_handles();

	/** @return array<string, mixed> */
	abstract public function get_payment_method_data();
}
