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
 * Базов URL на Контролния панел (без trailing slash).
 *
 * Пример: https://uni.avalonbg.com
 */
if ( ! defined( 'MTUC_CONTROL_PANEL_URL' ) ) {
	define( 'MTUC_CONTROL_PANEL_URL', 'https://uni.avalonbg.com' );
}

/**
 * Базов URL на CP API v1.
 *
 * Съставя се автоматично: {MTUC_CONTROL_PANEL_URL}/api/v1
 */
if ( ! defined( 'MTUC_API_BASE_URL' ) ) {
	define( 'MTUC_API_BASE_URL', untrailingslashit( MTUC_CONTROL_PANEL_URL ) . '/api/v1' );
}

if ( ! defined( 'MTUC_SSL_PASSWD' ) ) {
	define( 'MTUC_SSL_PASSWD', '1234' );
}

/** Minimum installment count for leasing schemes (inclusive). */
if ( ! defined( 'MTUC_SCHEME_MONTH_MIN' ) ) {
	define( 'MTUC_SCHEME_MONTH_MIN', 3 );
}

/** Maximum installment count for leasing schemes (inclusive). */
if ( ! defined( 'MTUC_SCHEME_MONTH_MAX' ) ) {
	define( 'MTUC_SCHEME_MONTH_MAX', 36 );
}
