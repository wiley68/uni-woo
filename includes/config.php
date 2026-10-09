<?php
/**
 * Конфигурация по среда (dev / test / prod).
 *
 * Единственият файл, който променяш при сглобяване на модула за дадена среда.
 * Всички останали части на плъгина четат стойностите оттук чрез константите по-долу.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Единствен адрес на Контролния панел: HTTPS origin, без path/query/fragment.
 *
 * Пример: https://uni.avalonbg.com
 */
if ( ! defined( 'MTUC_CONTROL_PANEL_URL' ) ) {
	define( 'MTUC_CONTROL_PANEL_URL', 'https://uni.avalonbg.com' );
}

// CP API base is derived after validation by MTUC\Cp_Destination_Policy.
// There is deliberately no independently configurable API-base constant.

/**
 * SmartUCF mTLS private-key password — not in Git source.
 *
 * Production ZIP (prepared by module vendor): before creating the ZIP, add
 * secrets/smartucf-key.php containing the SmartUCF private-key password.
 * Dev/staging overrides: MTUC_SMARTUCF_KEY_PASSWORD in wp-config.php or environment.
 * Legacy external MTUC_SSL_PASSWD constant is still supported when defined outside the plugin.
 */

/** Minimum installment count for leasing schemes (inclusive). */
if ( ! defined( 'MTUC_SCHEME_MONTH_MIN' ) ) {
	define( 'MTUC_SCHEME_MONTH_MIN', 3 );
}

/** Maximum installment count for leasing schemes (inclusive). */
if ( ! defined( 'MTUC_SCHEME_MONTH_MAX' ) ) {
	define( 'MTUC_SCHEME_MONTH_MAX', 36 );
}
