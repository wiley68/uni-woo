<?php
/**
 * Plugin Name:       УНИ Кредит
 * Plugin URI:        https://avalonbg.com
 * Description:       Кредитен калкулатор за УниКредит — WooCommerce модул за покупки на изплащане.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Avalon Ltd
 * Author URI:        https://avalonbg.com
 * Text Domain:       mtunicredit
 * Domain Path:       /languages
 * WC requires at least: 6.0
 * WC tested up to:   10.4.0
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check if WooCommerce is active.
 *
 * @since 1.0.0
 * @return bool True if WooCommerce is active, false otherwise.
 */
function mtuc_is_woocommerce_active() {
	include_once ABSPATH . 'wp-admin/includes/plugin.php';

	return in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true )
		|| is_plugin_active_for_network( 'woocommerce/woocommerce.php' );
}

if ( ! mtuc_is_woocommerce_active() ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="error"><p><strong>' . esc_html__( 'УНИ Кредит:', 'mtunicredit' ) . '</strong> '
				. esc_html__( 'WooCommerce не е активиран. Моля, активирайте го.', 'mtunicredit' )
				. '</p></div>';
		}
	);

	return;
}

/** Plugin constants */
define( 'MTUC_VERSION', '1.0.0' );
define( 'MTUC_PLUGIN_FILE', __FILE__ );
define( 'MTUC_PLUGIN_DIR', untrailingslashit( __DIR__ ) );
define( 'MTUC_PLUGIN_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'MTUC_INCLUDES_DIR', MTUC_PLUGIN_DIR . '/includes' );
define( 'MTUC_CSS_URI', MTUC_PLUGIN_URL . '/css' );
define( 'MTUC_JS_URI', MTUC_PLUGIN_URL . '/js' );

/** Includes */
$mtuc_files = array(
	'/functions.php',
);

foreach ( $mtuc_files as $file ) {
	require_once MTUC_INCLUDES_DIR . $file;
}

add_action( 'before_woocommerce_init', 'mtuc_declare_woocommerce_compatibility' );
add_action( 'plugins_loaded', 'mtuc_plugin_bootstrap', 0 );

/**
 * Declare compatibility with WooCommerce features.
 *
 * @since 1.0.0
 * @return void
 */
function mtuc_declare_woocommerce_compatibility() {
	if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		return;
	}

	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', MTUC_PLUGIN_FILE, true );
	\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', MTUC_PLUGIN_FILE, true );
}

/**
 * Bootstrap the plugin after all plugins are loaded.
 *
 * @since 1.0.0
 * @return void
 */
function mtuc_plugin_bootstrap() {
	load_plugin_textdomain( 'mtunicredit', false, dirname( plugin_basename( MTUC_PLUGIN_FILE ) ) . '/languages' );
}
