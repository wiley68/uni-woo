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
 * Warn administrators when WooCommerce currency disagrees with CP uni_eur mode.
 *
 * @return void
 */
function mtuc_admin_currency_mismatch_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! function_exists( 'mtuc_get_shop_data' ) || ! function_exists( 'mtuc_is_transaction_currency_compatible' ) ) {
		return;
	}

	if ( ! Mtuc_Settings::is_enabled() ) {
		return;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return;
	}

	if ( mtuc_is_transaction_currency_compatible( $shop ) ) {
		return;
	}

	$wc_currency = mtuc_get_woocommerce_transaction_currency();
	$expected    = mtuc_get_expected_transaction_currency( $shop );

	echo '<div class="notice notice-error"><p><strong>'
		. esc_html__( 'УНИ Кредит:', 'mtunicredit' )
		. '</strong> '
		. esc_html(
			sprintf(
				/* translators: 1: WooCommerce currency, 2: expected UniCredit transaction currency */
				__( 'Валутата на магазина (%1$s) не съвпада с валутата за финансиране в Контролния панел (%2$s). Финансирането е деактивирано, докато конфигурацията бъде коригирана.', 'mtunicredit' ),
				$wc_currency,
				$expected
			)
		)
		. '</p></div>';
}

/**
 * Warn administrators when stale-if-error shop configuration was used recently (AUD-WOO-010).
 *
 * @return void
 */
function mtuc_admin_shop_cache_stale_notice(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! class_exists( 'Mtuc_Shop_Cache' ) ) {
		return;
	}

	$notice = Mtuc_Shop_Cache::get_stale_fallback_notice();
	if ( null === $notice ) {
		return;
	}

	$category = isset( $notice['category'] ) ? sanitize_key( (string) $notice['category'] ) : 'configuration_error';

	echo '<div class="notice notice-warning"><p><strong>'
		. esc_html__( 'УНИ Кредит:', 'mtunicredit' )
		. '</strong> '
		. esc_html(
			sprintf(
				/* translators: %s: normalized failure category */
				__( 'Конфигурацията от КП се обслужва от кеш (stale-if-error) поради временен проблем (%s). Проверете връзката с Контролния панел.', 'mtunicredit' ),
				$category
			)
		)
		. '</p></div>';
}

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
