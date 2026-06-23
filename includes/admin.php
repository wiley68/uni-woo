<?php
/**
 * Admin menu registration.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin settings page slug.
 */
define( 'MTUC_ADMIN_PAGE_SLUG', 'mtuc-options' );

/**
 * Registers the plugin settings page under Settings.
 *
 * @since 1.0.0
 * @return void
 */
function mtuc_admin_register_menu() {
	add_options_page(
		__( 'УниКредит покупки на Кредит — настройки на модула', 'mtunicredit' ),
		__( 'УниКредит покупки на Кредит', 'mtunicredit' ),
		'manage_options',
		MTUC_ADMIN_PAGE_SLUG,
		'mtuc_admin_render_settings_page'
	);
}

/**
 * Enqueue admin CSS on plugin settings and WooCommerce order screens.
 *
 * @param string $hook_suffix Current admin page hook.
 * @return void
 */
function mtuc_admin_enqueue_styles( string $hook_suffix ): void {
	$css_file = MTUC_PLUGIN_DIR . '/css/mtuc-admin.css';
	if ( ! file_exists( $css_file ) ) {
		return;
	}

	$version = (string) filemtime( $css_file );
	$screen  = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$load    = false;

	if ( 'settings_page_' . MTUC_ADMIN_PAGE_SLUG === $hook_suffix ) {
		$load = true;
	}

	if ( $screen && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
		$load = true;
	}

	if ( ! $load ) {
		return;
	}

	wp_enqueue_style(
		'mtuc-admin',
		MTUC_PLUGIN_URL . '/css/mtuc-admin.css',
		array(),
		$version
	);
}

/**
 * Renders the admin settings page.
 *
 * @since 1.0.0
 * @return void
 */
function mtuc_admin_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Нямате достатъчно права за достъп до тази страница.', 'mtunicredit' ) );
	}

	require MTUC_INCLUDES_DIR . '/admin-settings-page.php';
}

add_action( 'admin_enqueue_scripts', 'mtuc_admin_enqueue_styles' );
add_action( 'admin_init', 'mtuc_admin_handle_debug_export' );

/**
 * Download debug journal as JSON when requested from settings.
 *
 * @return void
 */
function mtuc_admin_handle_debug_export(): void {
	if ( ! isset( $_GET['mtuc_export_debug'] ) || '1' !== $_GET['mtuc_export_debug'] ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Нямате достатъчно права за достъп до тази страница.', 'mtunicredit' ) );
	}

	check_admin_referer( 'mtuc_export_debug' );

	if ( ! class_exists( 'Mtuc_Debug_Log' ) ) {
		wp_die( esc_html__( 'Журналът за отстраняване на грешки не е наличен.', 'mtunicredit' ) );
	}

	Mtuc_Debug_Log::download_export();
}
