<?php
/**
 * Uninstall cleanup.
 *
 * @package MTUC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! defined( 'MTUC_PLUGIN_DIR' ) ) {
	define( 'MTUC_PLUGIN_DIR', untrailingslashit( __DIR__ ) );
}

require_once __DIR__ . '/includes/class-mtuc-settings.php';
require_once __DIR__ . '/includes/class-mtuc-cp-api-client.php';
require_once __DIR__ . '/includes/class-mtuc-shop-cache.php';
require_once __DIR__ . '/includes/class-mtuc-debug-log.php';
require_once __DIR__ . '/includes/class-mtuc-api-nonce-store.php';

Mtuc_Settings::uninstall();
Mtuc_Api_Nonce_Store::drop_table();

require_once __DIR__ . '/includes/mtuc-smartucf-credentials.php';
mtuc_uninstall_smartucf_credentials();
