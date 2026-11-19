<?php
/**
 * UNI-WOO-ADS-001: CP controls homepage advertising; legacy options are ignored.
 *
 * Run: php tests/run-advertisement-tests.php (no WordPress/database/network).
 *
 * @package MTUC
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'MTUC_PLUGIN_DIR', realpath( __DIR__ . '/..' ) );
define( 'MTUC_PLUGIN_URL', 'https://shop.example/plugins/mtunicredit' );
define( 'MTUC_CSS_URI', MTUC_PLUGIN_URL . '/css' );
define( 'MTUC_ADMIN_PAGE_SLUG', 'mtunicredit' );

// Standalone in-memory stubs, as in the existing frontend regression suites.
$GLOBALS['mtuc_ads_options'] = array();
$GLOBALS['mtuc_ads_styles']  = array();
$GLOBALS['mtuc_ads_scripts'] = array();
$GLOBALS['mtuc_ads_front']   = true;
$GLOBALS['mtuc_ads_admin']   = false;
$GLOBALS['mtuc_ads_mobile']  = false;

class WP_Error {}
function is_wp_error( $value ): bool { return $value instanceof WP_Error; }
function __( $text, $domain = '' ) { return $text; }
function absint( $value ): int { return abs( (int) $value ); }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function esc_attr( $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ): string { return esc_attr( $value ); }
function esc_url( $value ): string { return (string) $value; }
function esc_url_raw( $value ): string { return (string) $value; }
function esc_js( $value ): string { return addslashes( (string) $value ); }
function esc_attr__( $value, $domain = '' ): string { return esc_attr( $value ); }
function esc_attr_e( $value, $domain = '' ): void { echo esc_attr( $value ); }
function esc_html_e( $value, $domain = '' ): void { echo esc_html( $value ); }
function is_front_page(): bool { return $GLOBALS['mtuc_ads_front']; }
function is_admin(): bool { return $GLOBALS['mtuc_ads_admin']; }
function wp_is_mobile(): bool { return $GLOBALS['mtuc_ads_mobile']; }
function admin_url( $path ): string { return 'https://shop.example/wp-admin/' . $path; }
function get_admin_page_title(): string { return 'UniCredit'; }
function wp_nonce_field( ...$args ): void {}
function wp_nonce_url( $url, ...$args ): string { return $url; }
function submit_button( ...$args ): void {}
function checked( $checked, $current = true ): void { if ( (string) $checked === (string) $current ) { echo 'checked="checked"'; } }
function selected( $selected, $current = true ): void { if ( (string) $selected === (string) $current ) { echo 'selected="selected"'; } }
function get_option( $key, $default = false ) { return $GLOBALS['mtuc_ads_options'][ $key ] ?? $default; }
function update_option( $key, $value ): bool { $GLOBALS['mtuc_ads_options'][ $key ] = $value; return true; }
function add_option( $key, $value, ...$args ): bool {
	if ( array_key_exists( $key, $GLOBALS['mtuc_ads_options'] ) ) { return false; }
	return update_option( $key, $value );
}
function wp_enqueue_style( $handle, ...$args ): void { $GLOBALS['mtuc_ads_styles'][ $handle ] = $args; }
function wp_enqueue_script( $handle, ...$args ): void { $GLOBALS['mtuc_ads_scripts'][ $handle ] = $args; }

class Mtuc_Shop_Cache {
	public static $shop = array();
	public static $reads = 0;
	public static $purges = 0;
	public static function get_shop_data( $unicid = null ) { ++self::$reads; return self::$shop; }
	public static function get_cache_meta( $unicid ) { return null; }
	public static function purge_all(): void { ++self::$purges; }
}

require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-settings.php';
require_once MTUC_PLUGIN_DIR . '/includes/functions.php';

$mtuc_ads_assert_count = 0;
function mtuc_ads_assert( bool $ok, string $message ): void {
	++$GLOBALS['mtuc_ads_assert_count'];
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

// Each case runs in a fresh process because the real context is request-memoized.
$cases = array();
foreach ( array( 0, 1 ) as $local ) {
	foreach ( array( 0, 1 ) as $cp ) {
		foreach ( array( false, true ) as $mobile ) {
			$cases[ "local-$local-cp-$cp-" . ( $mobile ? 'mobile' : 'desktop' ) ] = array(
				'local' => $local, 'cp' => $cp, 'mobile' => $mobile, 'visible' => 1 === $cp,
			);
		}
	}
}
$cases['no-legacy-option']     = array( 'local' => null );
$cases['cp-yes-flag']          = array( 'cp' => 'yes' );
$cases['cp-failed']            = array( 'shop' => new WP_Error(), 'visible' => false );
$cases['cp-empty']             = array( 'shop' => array(), 'visible' => false );
$cases['cp-missing-container'] = array( 'shop' => array( 'uni_status' => 1 ), 'visible' => false );
$cases['cp-shop-disabled']     = array( 'status' => 0, 'visible' => false );
$cases['cp-missing-status']    = array( 'shop' => array( 'uni_container_status' => 1 ), 'visible' => false );
$cases['other-page']           = array( 'front' => false, 'visible' => false );
$cases['admin']                = array( 'admin' => true, 'visible' => false );
$cases['module-disabled']      = array( 'enabled' => 0, 'visible' => false );
$cases['missing-unicid']       = array( 'unicid' => '', 'visible' => false );
$cases['mobile-logo-fallback'] = array( 'mobile' => true, 'picture' => '' );

$case_id = $argv[1] ?? '';
if ( '' !== $case_id ) {
	mtuc_ads_assert( isset( $cases[ $case_id ] ), 'known test case' );
	$case = array_merge(
		array( 'local' => 0, 'cp' => 1, 'status' => 1, 'enabled' => 1, 'unicid' => 'test-shop', 'front' => true, 'admin' => false, 'mobile' => false, 'picture' => 'https://cdn.example/banner.png', 'visible' => true ),
		$cases[ $case_id ]
	);
	$GLOBALS['mtuc_ads_options'] = array( 'mtuc_status' => $case['enabled'], 'mtuc_unicid' => $case['unicid'] );
	if ( null !== $case['local'] ) {
		$GLOBALS['mtuc_ads_options']['mtuc_reklama'] = $case['local'];
	}
	$GLOBALS['mtuc_ads_front']  = $case['front'];
	$GLOBALS['mtuc_ads_admin']  = $case['admin'];
	$GLOBALS['mtuc_ads_mobile'] = $case['mobile'];
	Mtuc_Shop_Cache::$shop = $case['shop'] ?? array(
		'uni_status'           => $case['status'],
		'uni_container_status' => $case['cp'],
		'uni_backurl'          => 'https://bank.example/info',
		'uni_container_txt1'   => 'CP title',
		'uni_container_txt2'   => 'CP description',
		'uni_picturem'         => $case['picture'],
	);

	$eligible = $case['front'] && ! $case['admin'] && 1 === $case['enabled'] && '' !== $case['unicid'];
	mtuc_enqueue_reklama_assets();
	mtuc_ads_assert( 0 === Mtuc_Shop_Cache::$reads, 'asset enqueue still skips CP/cache reads' );
	mtuc_ads_assert( isset( $GLOBALS['mtuc_ads_styles']['mtuc-reklama'] ) === $eligible, 'CSS follows existing page/module/ID gates' );
	mtuc_ads_assert( isset( $GLOBALS['mtuc_ads_scripts']['mtuc-reklama'] ) === $eligible, 'JS follows existing page/module/ID gates' );

	$context = mtuc_get_reklama_context();
	mtuc_ads_assert( ( null !== $context ) === $case['visible'], 'visibility follows CP and existing gates, independent of the local option' );
	if ( $case['visible'] ) {
		mtuc_ads_assert( 'CP title' === $context['txt1'] && 'CP description' === $context['txt2'], 'CP banner text preserved' );
		mtuc_ads_assert( 'https://bank.example/info' === $context['backurl'], 'CP banner link preserved' );
		mtuc_ads_assert( $case['picture'] === $context['picture_url'], 'CP image preserved' );
		$expected_image = $case['mobile'] && '' !== $case['picture'] ? $case['picture'] : mtuc_get_uni_logo_url();
		mtuc_ads_assert( $expected_image === $context['float_image_url'], 'desktop/mobile image selection and fallback preserved' );
	}

	ob_start();
	mtuc_render_reklama_button();
	$html = ob_get_clean();
	mtuc_ads_assert( ( false !== strpos( $html, 'id="mtuc-reklama"' ) ) === $case['visible'], 'actual rendered banner follows visibility' );
	if ( ! $case['visible'] ) {
		mtuc_ads_assert( '' === $html, 'hidden banner emits no markup' );
	} elseif ( $case['mobile'] ) {
		mtuc_ads_assert( false !== strpos( $html, 'mtucReklamaOpenUrl' ) && false === strpos( $html, 'role="dialog"' ), 'mobile link behavior preserved' );
	} else {
		mtuc_ads_assert( false !== strpos( $html, 'role="dialog"' ) && false !== strpos( $html, 'CP title' ), 'desktop popup rendering preserved' );
	}
	mtuc_ads_assert( ( $eligible ? 1 : 0 ) === Mtuc_Shop_Cache::$reads, 'render reuses the request context without repeated cache reads' );
	fwrite( STDOUT, 'OK advertisement ' . $case_id . ' ' . $mtuc_ads_assert_count . " assertions\n" );
	exit( 0 );
}

// Exercise the real settings class and admin template with legacy data present.
foreach ( array( 0, 1 ) as $legacy ) {
	$GLOBALS['mtuc_ads_options'] = array( 'mtuc_unicid' => 'test-shop', 'mtuc_secret_key' => 'fixture-secret', 'mtuc_reklama' => $legacy );
	Mtuc_Settings::install_defaults();
	mtuc_ads_assert( $legacy === get_option( 'mtuc_reklama' ), 'activation preserves the saved legacy value' );
	mtuc_ads_assert( ! array_key_exists( 'mtuc_reklama', Mtuc_Settings::get_all() ), 'legacy option is no longer managed by the settings form' );
	ob_start();
	include MTUC_PLUGIN_DIR . '/includes/admin-settings-page.php';
	$html = ob_get_clean();
	mtuc_ads_assert( false === strpos( $html, 'mtuc_reklama' ) && false === strpos( $html, 'Визуализиране на реклама' ), 'actual admin form has no advertisement checkbox or label' );
	foreach ( array( 'mtuc_status', 'mtuc_unicid', 'mtuc_secret_key', 'mtuc_hook', 'mtuc_debug', 'mtuc_gap', 'mtuc_paybtn' ) as $key ) {
		mtuc_ads_assert( false !== strpos( $html, 'name="' . $key . '"' ), 'unrelated form field preserved: ' . $key );
	}

	$post = array(
		'mtuc_status' => 1, 'mtuc_unicid' => 'test-shop', 'mtuc_secret_key' => '',
		'mtuc_hook' => 'woocommerce_before_single_product', 'mtuc_debug' => 1,
		'mtuc_gap' => 24, 'mtuc_paybtn' => 'buy',
	);
	foreach ( array( null, 0, 1 ) as $submitted_legacy ) {
		$submitted = $post;
		if ( null !== $submitted_legacy ) {
			$submitted['mtuc_reklama'] = $submitted_legacy;
		}
		mtuc_ads_assert( true === Mtuc_Settings::save_from_post( $submitted ), 'other settings still save successfully' );
		mtuc_ads_assert( $legacy === get_option( 'mtuc_reklama' ), 'absent or forged legacy POST cannot modify stored legacy data' );
	}
	foreach ( $post as $key => $value ) {
		mtuc_ads_assert( ( 'mtuc_secret_key' === $key ? 'fixture-secret' : $value ) === get_option( $key ), 'unrelated setting persistence preserved: ' . $key );
	}
	mtuc_ads_assert( 0 === Mtuc_Shop_Cache::$purges, 'saving unchanged credentials does not purge the cache' );
}
$GLOBALS['mtuc_ads_options'] = array();
Mtuc_Settings::install_defaults();
mtuc_ads_assert( ! array_key_exists( 'mtuc_reklama', $GLOBALS['mtuc_ads_options'] ), 'new installations do not create the removed option' );

foreach ( $cases as $name => $case ) {
	$process = proc_open(
		array( PHP_BINARY, __FILE__, $name ),
		array( 0 => array( 'pipe', 'r' ), 1 => STDOUT, 2 => STDERR ),
		$pipes
	);
	mtuc_ads_assert( is_resource( $process ), 'start isolated case: ' . $name );
	fclose( $pipes[0] );
	mtuc_ads_assert( 0 === proc_close( $process ), 'isolated case passes: ' . $name );
}
fwrite( STDOUT, 'OK advertisement settings/runner ' . $mtuc_ads_assert_count . ' assertions; ' . count( $cases ) . " isolated frontend cases\n" );
