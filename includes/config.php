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

/**
 * Optional CDN asset date suffix (YYYYMMDD) when CP does not yet send reklama_manifest_version.
 * Prefer reklama_manifest_version / reklama_picture_url from shop API instead.
 */
if ( ! defined( 'MTUC_REKLAMA_ASSETS_DATE' ) ) {
	define( 'MTUC_REKLAMA_ASSETS_DATE', '20260612' );
}
