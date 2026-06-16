<?php
/**
 * Uninstall cleanup.
 *
 * @package MTUC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-mtuc-settings.php';

Mtuc_Settings::uninstall();
