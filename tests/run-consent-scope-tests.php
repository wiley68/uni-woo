<?php
/**
 * UNI-CONSENT-001: real normalization, calculations and submission boundaries.
 * Run: php tests/run-consent-scope-tests.php (no WordPress/database/network).
 *
 * @package MTUC
 */
define( 'ABSPATH', __DIR__ . '/../' );
define( 'MTUC_PLUGIN_DIR', realpath( __DIR__ . '/..' ) );
define( 'MTUC_PLUGIN_URL', 'https://shop.example/plugin' );
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class Mtuc_Consent_Stop extends RuntimeException {}
function __( $v, $domain = '' ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function wp_strip_all_tags( $v ) { return strip_tags( $v ); }
function esc_url_raw( $v ) { return $v; }
function esc_url( $v ) { return $v; }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $v ) { return esc_attr( $v ); }
function esc_attr_e( $v, $domain = '' ) { echo esc_attr( $v ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_text_field( $v ) { return trim( (string) $v ); }
function sanitize_key( $v ) { return strtolower( (string) $v ); }
function sanitize_email( $v ) { return $v; }
function wp_unslash( $v ) { return $v; }
function is_email( $v ) { return false !== filter_var( $v, FILTER_VALIDATE_EMAIL ); }
function current_time( $v ) { return '2026-11-05'; }
function is_user_logged_in() { return false; }
function get_current_user_id() { return 0; }
function check_ajax_referer( ...$args ) {}
function get_woocommerce_currency() { return 'EUR'; }
function mtuc_is_eur_transaction_currency() { return true; }
function mtuc_require_eur_transaction_currency( ...$args ) { return 'EUR'; }
function mtuc_get_canonical_financeable_cart_total() { return 600.0; }
function mtuc_get_canonical_financeable_order_total( $order ) { return 600.0; }
function mtuc_resolve_authoritative_product_financing_line( ...$args ) {
	return array( 'product' => new WC_Product(), 'parent_id' => 10, 'variation_id' => 0, 'quantity' => 1, 'line_total' => 600.0 );
}
function mtuc_claim_submission_lock( $key ) { return 'test-owner'; }
function mtuc_arm_submission_lock_fence( ...$args ) {}
function mtuc_disarm_submission_lock_fence( ...$args ) {}
function mtuc_release_submission_lock( ...$args ) { ++$GLOBALS['released']; }
function mtuc_get_submitted_operation_token() { return 'test-operation'; }
function mtuc_build_product_operation_scope_key( ...$args ) { return 'test-scope'; }
function mtuc_build_cart_operation_scope_key() { return 'test-scope'; }
function mtuc_financing_creation_ref( $token ) { return 'test-ref'; }
function mtuc_find_order_for_financing_operation( ...$args ) { return $GLOBALS['existing']; }
function mtuc_operation_token_matches_order_scope( ...$args ) { return true; }
function mtuc_resolve_popup_financing_order( $token, $scope, $creator ) {
	if ( isset( $GLOBALS['resolved_order'] ) ) return array( 'order' => $GLOBALS['resolved_order'], 'created' => false );
	if ( ! empty( $GLOBALS['test_initializer'] ) ) return $creator( null, $GLOBALS['existing'], array() );
	// A valid preflight reached the creation boundary; no real order is created.
	throw new Mtuc_Consent_Stop( 'creation-boundary' );
}
function mtuc_send_customer_safe_json_error( $error, ...$args ) { throw new Mtuc_Consent_Stop( $error->get_error_code() ); }
function wp_send_json_error( $data, ...$args ) { throw new Mtuc_Consent_Stop( 'unexpected-json-error: ' . $data['message'] ); }
function wp_send_json_success( $data ) { $GLOBALS['ajax_result'] = $data; throw new Mtuc_Consent_Stop( 'ajax-success' ); }
function mtuc_add_customer_safe_notice( $error, ...$args ) { $GLOBALS['notice'] = $error->get_error_code(); }
function wc_add_notice( ...$args ) { $GLOBALS['notice'] = 'wc-notice'; }
function wc_get_order( $id ) { return new WC_Order(); }
class WC_Payment_Gateway {}
class WC_Product {
	public function get_id() { return 10; }
	public function get_parent_id() { return 0; }
	public function is_type( $type ) { return 'simple' === $type; }
	public function get_category_ids() { return array(); }
	public function exists() { return true; }
	public function is_purchasable() { return true; }
	public function is_in_stock() { return true; }
}
class WC_Cart {
	public function get_cart() { return array( 'line' => array( 'data' => new WC_Product(), 'quantity' => 1, 'line_total' => 600, 'line_tax' => 0 ) ); }
	public function get_cart_hash() { return 'cart'; }
}
function WC() { return (object) array( 'cart' => new WC_Cart(), 'session' => null ); }
class WC_Order {
	public $meta = array();
	public function get_meta( $key ) { return $this->meta[ $key ] ?? ''; }
	public function get_id() { return 1; }
	public function get_currency() { return 'EUR'; }
	public function get_items( ...$args ) { throw new Mtuc_Consent_Stop( 'checkout-passed' ); }
	public function __call( $method, $args ) {
		$values = array( 'get_billing_first_name' => 'Test', 'get_billing_last_name' => 'User', 'get_billing_address_1' => 'Address', 'get_billing_phone' => '+359888123456', 'get_billing_email' => 'test@example.com' );
		return $values[ $method ] ?? '';
	}
}
class Mtuc_Settings {
	public static function is_enabled() { return true; }
}
class Mtuc_Shop_Cache {
	const PURPOSE_SUBMISSION = 'submission';
	public static function get_shop_data( ...$args ) { return $GLOBALS['shop']; }
}
require_once MTUC_PLUGIN_DIR . '/includes/config.php';
require_once MTUC_PLUGIN_DIR . '/includes/functions.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-product-offer-selection.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-calculator.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-product-popup.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cart-scheme-intersection.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cart-calculator.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-payment-gateway.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-shop-snapshot.php';
$count = 0;
function consent_assert( $ok, $message ) {
	++$GLOBALS['count'];
	if ( ! $ok ) { fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL ); exit( 1 ); }
}
function consent_error( $result, $code ) { return $result instanceof WP_Error && $code === $result->get_error_code(); }
function consent_capture( callable $callback ) {
	try { return $callback(); } catch ( Mtuc_Consent_Stop $stop ) { return $stop->getMessage(); }
}
$consents = array();
foreach ( array( 'all', 'zero_interest', 'nonzero_interest' ) as $i => $scope ) {
	foreach ( array( 0, 1 ) as $mandatory ) {
		$consents[] = array( 'id' => 1 + $i * 2 + $mandatory, 'name' => $scope, 'mandatory' => $mandatory, 'interest_scope' => $scope );
	}
}
$shop = array(
	'uni_typekop' => 0, 'uni_proces' => 0, 'uni_minstojnost' => 1, 'uni_maxstojnost' => 10000,
	'uni_meseci_6' => 1, 'uni_meseci_12' => 1, 'uni_shema_current' => 6,
	'kop' => array( 'by_default' => array( 'uni_kop_default' => 'BASE', 'uni_kop_promo' => 'ZERO', 'uni_shema_promo' => 6 ) ),
	'coeff_list' => array(
		array( 'onlineProductCode' => 'BASE', 'installmentCount' => 6, 'coeff' => 0.2, 'interestPercent' => 20 ),
		array( 'onlineProductCode' => 'BASE', 'installmentCount' => 12, 'coeff' => 1 / 12, 'interestPercent' => 0 ),
		array( 'onlineProductCode' => 'ZERO', 'installmentCount' => 6, 'coeff' => 1 / 6, 'interestPercent' => 0 ),
	),
	'consents' => $consents,
);
$GLOBALS['existing'] = null;
$GLOBALS['released'] = 0;
foreach ( array( array( 0, 'zero' ), array( '0.00', 'zero' ), array( 0.000009999, 'zero' ), array( 0.00001, 'zero' ), array( -0.00001, 'zero' ), array( -0.000009999, 'zero' ), array( 0.000010001, 'nonzero' ), array( -0.000010001, 'nonzero' ), array( 0.0001, 'nonzero' ), array( 20, 'nonzero' ), array( '-20', 'nonzero' ) ) as $case ) {
	consent_assert( $case[1] === mtuc_get_consent_interest_state( array( 'interestPercent' => $case[0] ) ), 'raw epsilon classification ' . var_export( $case[0], true ) );
}
foreach ( array( null, '', 'invalid', '0,0', true, false, array(), NAN, INF, -INF, 'NaN', 'INF', '1e999' ) as $value ) {
	consent_assert( 'unknown' === mtuc_get_consent_interest_state( array( 'interestPercent' => $value ) ), 'invalid raw interest is unknown' );
}
consent_assert( 'unknown' === mtuc_get_consent_interest_state( null ), 'missing entry unknown' );
consent_assert( 'unknown' === mtuc_get_consent_interest_state( array() ), 'missing field unknown' );
consent_assert( mtuc_is_zero_interest_coeff_entry( array( 'interestPercent' => 'invalid' ) ), 'legacy offer selection remains unchanged' );
$normalized = mtuc_get_shop_consents( $shop );
consent_assert( array_column( $consents, 'interest_scope' ) === array_column( $normalized, 'interest_scope' ), 'valid scopes preserved' );
consent_assert( array_column( $normalized, 'mandatory' ) === array_column( $normalized, 'has_checkbox' ), 'mandatory independent of scope' );
$json_shop = $shop;
$json_shop['consents'] = json_encode( $consents );
consent_assert( $normalized === mtuc_get_shop_consents( $json_shop ), 'legacy JSON list supported' );
foreach ( array( null, '', 'invalid', true, array() ) as $scope ) {
	consent_assert( 'all' === mtuc_normalize_consent_interest_scope( $scope ), 'missing/invalid scope falls back to all' );
}
$prepared = mtuc_normalize_shop_snapshot_public_fields( mtuc_strip_shop_snapshot_secrets( $shop ) );
consent_assert( $consents === $prepared['consents'], 'snapshot preparation preserves scope data' );
$nonzero = mtuc_calculate_popup_credit( $shop, $shop['coeff_list'], 600, 6, 'standard', 0, new WC_Product() );
$zero = mtuc_calculate_popup_credit( $shop, $shop['coeff_list'], 600, 12, 'standard', 0, new WC_Product() );
consent_assert( ! is_wp_error( $zero ) && ! is_wp_error( $nonzero ), 'real default KOP calculations resolve' );
foreach ( array( array( $zero, array( 1, 2, 3, 4 ), array( 2, 4 ) ), array( $nonzero, array( 1, 2, 5, 6 ), array( 2, 6 ) ) ) as $case ) {
	$context = mtuc_get_calculation_consent_context( $shop, $case[0] );
	consent_assert( $context['valid'] && $case[1] === $context['applicable_ids'] && $case[2] === $context['required_ids'], 'scope and mandatory intersection' );
	foreach ( array( $case[2], implode( ',', $case[2] ) ) as $accepted ) {
		consent_assert( true === mtuc_validate_mandatory_consents_from_post( array( 'mtuc_consent' => $accepted ), $shop, $case[0] ), 'popup/Classic array and Blocks CSV accepted IDs' );
	}
	consent_assert( consent_error( mtuc_validate_mandatory_consents_from_post( array( 'mtuc_consent' => array( 1, 2 ), 'interest' => 0, 'required_ids' => array() ), $shop, $case[0] ), 'mtuc_consents_required' ), 'client flags cannot bypass applicable mandatory IDs' );
}
$promo_shop = $shop;
$promo_shop['kop']['by_default']['uni_promo_meseci_znak'] = 'eq';
$promo_shop['kop']['by_default']['uni_promo_meseci'] = '6';
$promo_calc = mtuc_calculate_popup_credit( $promo_shop, $promo_shop['coeff_list'], 600, 6, 'promo', 0, new WC_Product(), 0, 'promo' );
consent_assert( ! is_wp_error( $promo_calc ) && 'zero' === mtuc_get_calculation_consent_context( $promo_shop, $promo_calc )['interest_state'], 'default zero promo resolves from its real coefficient' );
$tiny_shop = $shop;
$tiny_shop['coeff_list'][0]['interestPercent'] = 0.0001;
$tiny_calc = mtuc_calculate_popup_credit( $tiny_shop, $tiny_shop['coeff_list'], 600, 6, 'standard', 0, new WC_Product() );
consent_assert( 0.0 === $tiny_calc['glp'] && 'nonzero' === mtuc_get_calculation_consent_context( $tiny_shop, $tiny_calc )['interest_state'], 'rounded display zero never determines consent applicability' );
$invalid = $shop;
foreach ( array( null, 'bad', true, NAN, INF ) as $value ) {
	$invalid['coeff_list'][0]['interestPercent'] = $value;
	consent_assert( consent_error( mtuc_validate_mandatory_consents_from_post( array( 'mtuc_consent' => array( 2, 4, 6 ) ), $invalid, $nonzero ), 'mtuc_consents_unknown_interest' ), 'unknown blocks scoped submission' );
}
foreach ( array( 0, -1, INF, 'bad' ) as $coeff ) {
	$invalid = $shop;
	$invalid['coeff_list'][0]['coeff'] = $coeff;
	consent_assert( ! mtuc_get_calculation_consent_context( $invalid, $nonzero )['valid'], 'invalid coefficient cannot classify consent context' );
}
$invalid = $shop;
$invalid['coeff_list'] = array();
consent_assert( ! mtuc_get_calculation_consent_context( $invalid, $nonzero )['valid'], 'missing coefficient fails closed' );
$old = $shop;
$old['consents'] = array( array( 'id' => 9, 'name' => 'Legacy', 'mandatory' => 1 ) );
consent_assert( true === mtuc_validate_mandatory_consents_from_post( array( 'mtuc_consent' => array( 9 ) ), $old ), 'old snapshot without scope remains supported' );
$old['consents'][0]['interest_scope'] = 'invalid';
consent_assert( true === mtuc_validate_mandatory_consents_from_post( array( 'mtuc_consent' => array( 9 ) ), $old ), 'unknown scope remains mandatory all' );
$old['consents'] = array();
consent_assert( true === mtuc_validate_mandatory_consents_from_post( array(), $old ), 'empty list supported' );
$updated = $shop;
$updated['consents'][] = array( 'id' => 99, 'name' => 'New', 'mandatory' => 1 );
$error = mtuc_validate_mandatory_consents_from_post( array( 'mtuc_consent' => array( 2, 6 ) ), $updated, $nonzero );
consent_assert( consent_error( $error, 'mtuc_consents_required' ) && false !== strpos( $error->get_error_message(), 'обновете' ), 'new mandatory consent requires page refresh' );
// Same KOP, different term; plus actual schema/nonzero-promo selection.
$schema = $shop;
$schema['uni_typekop'] = 1;
$schema['kop']['by_schema']['filters'] = array( array( 'id' => 7, 'uni_promo' => 0, 'uni_kop' => 'OFFER', 'uni_meseci' => '6_12' ) );
$schema['coeff_list'][] = array( 'onlineProductCode' => 'OFFER', 'installmentCount' => 6, 'coeff' => 0.2, 'interestPercent' => 15 );
$schema['coeff_list'][] = array( 'onlineProductCode' => 'OFFER', 'installmentCount' => 12, 'coeff' => 1 / 12, 'interestPercent' => 0 );
foreach ( array( 6 => 'nonzero', 12 => 'zero' ) as $months => $state ) {
	$calc = mtuc_calculate_popup_credit( $schema, $schema['coeff_list'], 600, $months, 'standard', 0, new WC_Product(), 7 );
	consent_assert( ! is_wp_error( $calc ) && $state === mtuc_get_calculation_consent_context( $schema, $calc )['interest_state'], 'schema KOP and term determine real interest' );
}
consent_assert( 'nonzero_promo' === mtuc_classify_popup_scheme_presentation( $schema, $schema['coeff_list'], 'OFFER', 6, 0 ), 'nonzero promo uses standard scheme type' );
$markup = mtuc_render_shop_consents_markup( $normalized );
consent_assert( 6 === substr_count( $markup, 'data-mtuc-consent-row=' ) && 3 === substr_count( $markup, 'type="checkbox"' ), 'partial renders mandatory and informational scopes' );
consent_assert( 6 === substr_count( $markup, ' hidden>' ) && 3 === substr_count( $markup, 'disabled' ), 'initial markup cannot submit stale consents' );
// Real handlers: invalid requests must stop before the order-creation boundary.
$customer = array( 'first_name' => 'Test', 'last_name' => 'User', 'address' => 'Address', 'phone' => '+359888123456', 'email' => 'test@example.com' );
foreach ( array( 'product', 'cart' ) as $source ) {
	$_POST = $customer + array( 'source' => $source, 'product_id' => 10, 'quantity' => 1, 'scheme_key' => '6:0', 'mtuc_consent' => array( 2, 4 ), 'interest' => 0 );
	consent_assert( 'mtuc_consents_required' === consent_capture( 'mtuc_ajax_popup_submit' ), $source . ' refuses nonzero submission with zero consent IDs' );
	$_POST['mtuc_consent'] = array( 2, 6 );
	consent_assert( 'creation-boundary' === consent_capture( 'mtuc_ajax_popup_submit' ), $source . ' valid IDs reach creation boundary' );
	$order = new WC_Order();
	$snapshot = 'cart' === $source ? mtuc_build_cart_operation_snapshot( $customer, $zero, array( array( 'product_id' => 10, 'quantity' => 1, 'line_total' => 600 ) ), array(), 600 ) : mtuc_build_product_operation_snapshot( $customer, $zero, 10, 0, 1, 600 );
	$order->meta[ 'cart' === $source ? MTUC_ORDER_META_CART_OP_SNAPSHOT : MTUC_ORDER_META_PRODUCT_OP_SNAPSHOT ] = json_encode( $snapshot );
	$GLOBALS['existing'] = $order;
	consent_assert( 'mtuc_consents_required' === consent_capture( 'mtuc_ajax_popup_submit' ), $source . ' retry validates saved zero scheme instead of POST nonzero scheme' );
	$_POST['mtuc_consent'] = array( 2, 4 );
	consent_assert( 'creation-boundary' === consent_capture( 'mtuc_ajax_popup_submit' ), $source . ' retry accepts saved scheme consents without requiring POST scheme consents' );
	$GLOBALS['existing'] = null;
}
// A concurrently recovered order can change the effective scheme after preflight.
foreach ( array( 'product', 'cart' ) as $source ) {
	$order = new WC_Order();
	$snapshot = 'cart' === $source ? mtuc_build_cart_operation_snapshot( $customer, $nonzero, array( array( 'product_id' => 10, 'quantity' => 1, 'line_total' => 600 ) ), array(), 600 ) : mtuc_build_product_operation_snapshot( $customer, $nonzero, 10, 0, 1, 600 );
	$order->meta[ 'cart' === $source ? MTUC_ORDER_META_CART_OP_SNAPSHOT : MTUC_ORDER_META_PRODUCT_OP_SNAPSHOT ] = json_encode( $snapshot );
	$GLOBALS['resolved_order'] = $order;
	$_POST = $customer + array( 'source' => $source, 'product_id' => 10, 'quantity' => 1, 'scheme_key' => '12:0', 'mtuc_consent' => array( 2, 4 ) );
	consent_assert( 'mtuc_consents_required' === consent_capture( 'mtuc_ajax_popup_submit' ), $source . ' post-resolution snapshot cannot bypass final validation' );
	unset( $GLOBALS['resolved_order'] );
}
$gateway = ( new ReflectionClass( 'Mtuc_Payment_Gateway' ) )->newInstanceWithoutConstructor();
foreach ( array( array( 2, 6 ), '2,6' ) as $accepted ) {
	$_POST = array( 'mtuc_scheme_key' => '6:0', 'mtuc_consent' => $accepted );
	consent_assert( $gateway->validate_fields(), 'Classic/Blocks gateway validates selected scheme IDs' );
	$_POST['mtuc_consent'] = array( 2, 4 );
	consent_assert( ! $gateway->validate_fields() && 'mtuc_consents_required' === $GLOBALS['notice'], 'gateway rejects inapplicable IDs' );
}
$_POST = array( 'mtuc_scheme_key' => '6:0', 'mtuc_consent' => array( 2, 4 ) );
consent_assert( 'fail' === $gateway->process_payment( 1 )['result'] && 'mtuc_consents_required' === $GLOBALS['notice'], 'process_payment independently revalidates' );
$_POST['mtuc_consent'] = array( 2, 6 );
consent_assert( 'checkout-passed' === consent_capture( static function () use ( $gateway ) { return $gateway->process_payment( 1 ); } ), 'process_payment passes accepted IDs to final boundary' );
consent_assert( $GLOBALS['released'] >= 5, 'invalid submissions release owner locks' );
$_POST = array( 'source' => 'product', 'product_id' => 10, 'quantity' => 1, 'scheme_key' => '12:0' );
consent_assert( 'ajax-success' === consent_capture( 'mtuc_ajax_popup_calculate' ), 'calculation AJAX succeeds' );
consent_assert( array( 2, 4 ) === $GLOBALS['ajax_result']['consent_context']['required_ids'], 'AJAX adds raw-coefficient consent context' );
foreach ( array( 'cart', 'checkout' ) as $source ) {
	$_POST = array( 'source' => $source, 'scheme_key' => '12:0' );
	consent_assert( 'ajax-success' === consent_capture( 'mtuc_ajax_popup_calculate' ), $source . ' AJAX succeeds' );
	consent_assert( array( 2, 4 ) === $GLOBALS['ajax_result']['consent_context']['required_ids'], $source . ' AJAX shares consent context' );
}
consent_assert( 120.0 === $nonzero['monthly_installment'] && 720.0 === $nonzero['total_payable'] && 50.0 === $zero['monthly_installment'], 'financial amounts unchanged' );
echo 'OK consent-scope ' . $count . ' assertions' . PHP_EOL;
