<?php
/**
 * Regression tests for cart button eligibility versus popup scheme availability.
 *
 * Run: php tests/run-cart-button-eligibility-tests.php
 *
 * @package MTUC
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'MTUC_PLUGIN_DIR', realpath( __DIR__ . '/..' ) );
define( 'MTUC_PLUGIN_URL', 'https://shop.example/plugins/mtunicredit' );

// Minimal WordPress/WooCommerce environment; use real financing and filter logic.
function __( $text, $domain = 'default' ) {
	return $text;
}
function absint( $value ): int {
	return abs( (int) $value );
}
function is_wp_error( $value ): bool {
	return false;
}
function current_time( $format ): string {
	return '2026-10-29';
}
function is_user_logged_in(): bool {
	return false;
}
function get_woocommerce_currency(): string {
	return 'EUR';
}
function esc_url( $value ): string {
	return (string) $value;
}
function esc_attr( $value ): string {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}
function esc_html_e( $text, $domain ): void {
	echo esc_attr( $text );
}
function esc_attr_e( $text, $domain ): void {
	echo esc_attr( $text );
}
function WC() {
	return $GLOBALS['mtuc_cbe_wc'];
}

class WC_Product {
	private $id;
	public function __construct( int $id ) {
		$this->id = $id;
	}
	public function get_id(): int {
		return $this->id;
	}
	public function is_type( string $type ): bool {
		return 'simple' === $type;
	}
	public function get_category_ids(): array {
		return array();
	}
	public function exists(): bool {
		return true;
	}
	public function is_purchasable(): bool {
		return true;
	}
	public function is_in_stock(): bool {
		return true;
	}
}

class Mtuc_CBE_Cart {
	public $products = array();
	public function get_cart(): array {
		return array_map(
			static function ( WC_Product $product ): array {
				return array( 'data' => $product, 'quantity' => 1, 'line_total' => 600.0, 'line_tax' => 0.0 );
			},
			$this->products
		);
	}
	public function get_total( $context ): float {
		return 600.0 * count( $this->products );
	}
}

class Mtuc_Settings {
	const OPTION_UNICID = 'unicid';
	const OPTION_GAP    = 'gap';
	public static function is_enabled(): bool {
		return true;
	}
	public static function get( $key ) {
		return self::OPTION_UNICID === $key ? 'test-shop' : 0;
	}
}

class Mtuc_Shop_Cache {
	public static $shop = array();
	public static function get_shop_data( $unicid = null ): array {
		return self::$shop;
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/config.php';
require_once MTUC_PLUGIN_DIR . '/includes/functions.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-calculator.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financial-integrity.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-product-offer-selection.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-product-popup.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cart-scheme-intersection.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cart-calculator.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cart-frontend.php';

$mtuc_cbe_assert_count = 0;
function mtuc_cbe_assert( bool $ok, string $message ): void {
	global $mtuc_cbe_assert_count;
	++$mtuc_cbe_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

$product = new WC_Product( 101 );
$cart    = new Mtuc_CBE_Cart();
$cart->products = array( $product );
$GLOBALS['mtuc_cbe_wc'] = (object) array( 'cart' => $cart );
$promo_filters = array(
	array( 'id' => 1, 'uni_promo' => 1, 'uni_kop' => 'ZERO6', 'uni_meseci' => '6' ),
	array( 'id' => 2, 'uni_promo' => 1, 'uni_kop' => 'ZERO20', 'uni_meseci' => '20' ),
);
$standard_filter = array( 'id' => 3, 'uni_promo' => 0, 'uni_kop' => 'BASE', 'uni_meseci' => '12' );
$shop = array(
	'uni_status'        => 1,
	'uni_typekop'       => 1,
	'uni_minstojnost'   => 100,
	'uni_maxstojnost'   => 10000,
	'uni_shema_current' => 20,
	'uni_meseci_6'      => 1,
	'uni_meseci_12'     => 1,
	'uni_meseci_20'     => 1,
	'coeff_list'       => array(
		array( 'onlineProductCode' => 'ZERO6', 'installmentCount' => 6, 'interestPercent' => 0.0, 'coeff' => 1 / 6 ),
		array( 'onlineProductCode' => 'ZERO20', 'installmentCount' => 20, 'interestPercent' => 0.0, 'coeff' => 0.05 ),
		array( 'onlineProductCode' => 'BASE', 'installmentCount' => 12, 'interestPercent' => 20.0, 'coeff' => 0.1 ),
	),
	'kop'              => array( 'by_schema' => array( 'filters' => $promo_filters ) ),
);
Mtuc_Shop_Cache::$shop = $shop;

// Both promos remain selectable; only the promo button represents them.
$product_offer = mtuc_get_product_calculator_offer( $shop, $product, 600.0 );
$context       = mtuc_build_cart_calculator_context();
mtuc_cbe_assert( null === $product_offer['standard'] && null === $context['standard'], 'promo-only cart and product both hide standard button' );
mtuc_cbe_assert( 20 === $context['promo']['installment_count'] && 30.0 === $context['promo']['monthly_installment'], 'promo-only cart represents 0% for 20 months' );
mtuc_cbe_assert( $product_offer['promo']['kop_code'] === $context['promo']['kop_code'], 'cart promo matches product promo' );
mtuc_cbe_assert( array( 'p:6:1', 'p:20:2' ) === array_column( $context['popup']['enabled_months_by_offer']['promo'], 'key' ), 'promo popup keeps both 6 and 20 month schemes' );
mtuc_cbe_assert( ! mtuc_cart_has_any_line_scheme_options( $shop, $shop['coeff_list'], 600.0, $context['lines'], 'standard' ), 'promo rows do not trigger standard image-only fallback' );
$state = mtuc_resolve_cart_scheme_state();
mtuc_cbe_assert( null === $state['standard'] && 20 === $state['promo']['installment_count'], 'AJAX scheme resolution follows button eligibility' );
mtuc_cbe_assert( 2 === count( mtuc_resolve_checkout_scheme_common( $state ) ), 'checkout retains both promo schemes' );
$refresh = mtuc_build_cart_calculator_refresh_payload();
mtuc_cbe_assert( null === $refresh['standard'] && true === $refresh['promo']['visible'], 'classic and Blocks refresh payload exposes only promo button' );
mtuc_cbe_assert( 1 === preg_match( '/data-mtuc-offer="standard"[^>]*style="display:none;"/s', $refresh['fragmentHtml'] ), 'cart fragment hides standard button' );

// Adding a standard match enables its button without allowing a promo to represent it.
Mtuc_Shop_Cache::$shop['kop']['by_schema']['filters'][] = $standard_filter;
$context = mtuc_build_cart_calculator_context();
mtuc_cbe_assert( 'BASE' === $context['standard']['kop_code'] && 60.0 === $context['standard']['monthly_installment'], 'mixed cart standard button uses standard KOP and installment' );
mtuc_cbe_assert( 'ZERO20' === $context['promo']['kop_code'], 'mixed cart keeps promo button' );
mtuc_cbe_assert( 3 === count( $context['popup']['enabled_months_by_offer']['standard'] ), 'standard popup still includes standard and promo choices' );

Mtuc_Shop_Cache::$shop['kop']['by_schema']['filters'] = array( $standard_filter );
$context = mtuc_build_cart_calculator_context();
mtuc_cbe_assert( 'BASE' === $context['standard']['kop_code'] && null === $context['promo'], 'standard-only cart shows only standard button' );

// A configured standard filter that misses the product must not enable its button.
$missed_standard = array_merge( $standard_filter, array( 'product_id' => 999 ) );
Mtuc_Shop_Cache::$shop['kop']['by_schema']['filters'] = array_merge( $promo_filters, array( $missed_standard ) );
$context = mtuc_build_cart_calculator_context();
mtuc_cbe_assert( null === $context['standard'] && ! empty( $context['promo']['visible'] ), 'unmatched standard filter leaves only promo visible' );

// Multiple lines with common promos keep the same eligibility as a single line.
$cart->products[] = new WC_Product( 102 );
$context = mtuc_build_cart_calculator_context();
mtuc_cbe_assert( null === $context['standard'] && 60.0 === $context['promo']['monthly_installment'], 'multi-line promo-only cart has only promo button at combined total' );

// Disjoint promos still allow the existing promo image-only fallback, never standard.
$split_filters = $promo_filters;
$split_filters[0]['product_id'] = 101;
$split_filters[1]['product_id'] = 102;
Mtuc_Shop_Cache::$shop['kop']['by_schema']['filters'] = $split_filters;
$context = mtuc_build_cart_calculator_context();
mtuc_cbe_assert( null === $context['standard'] && ! empty( $context['promo']['image_only'] ), 'disjoint promo schemes show only promo image-only fallback' );

// Existing standard image-only behavior is retained for disjoint standard schemes.
Mtuc_Shop_Cache::$shop['kop']['by_schema']['filters'] = array_merge(
	$promo_filters,
	array( array_merge( $standard_filter, array( 'product_id' => 101 ) ) )
);
$context = mtuc_build_cart_calculator_context();
mtuc_cbe_assert( ! empty( $context['standard']['image_only'] ) && null === $context['promo'], 'disjoint standard schemes retain standard image-only fallback' );

Mtuc_Shop_Cache::$shop['kop']['by_schema']['filters'] = array();
$context = mtuc_build_cart_calculator_context();
mtuc_cbe_assert( null === $context['standard'] && null === $context['promo'], 'no matching schemes hide both buttons' );

fwrite( STDOUT, 'OK cart-button-eligibility ' . $mtuc_cbe_assert_count . " assertions\n" );
