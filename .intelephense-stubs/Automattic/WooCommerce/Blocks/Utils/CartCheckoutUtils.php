<?php
/**
 * Intelephense stub — WooCommerce Blocks cart/checkout page detection.
 *
 * @package MTUC
 */

namespace Automattic\WooCommerce\Blocks\Utils;

/**
 * WooCommerce Blocks cart/checkout page detection (WC core).
 */
class CartCheckoutUtils {
	/**
	 * @return bool
	 */
	public static function is_cart_block_default(): bool {
		return false;
	}

	/**
	 * @return bool
	 */
	public static function is_checkout_block_default(): bool {
		return false;
	}
}
