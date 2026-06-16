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
