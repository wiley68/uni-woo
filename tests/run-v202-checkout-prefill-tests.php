<?php
/**
 * v2.0.2 Checkout exact-prefill normalization tests.
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_v202_prefill_assert_count = 0;
function mtuc_v202_prefill_assert( bool $ok, string $message ): void {
	global $mtuc_v202_prefill_assert_count;
	++$mtuc_v202_prefill_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ): int { return abs( (int) $value ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ): string { return '/wp-admin/' . ltrim( (string) $path, '/' ); }
}
if ( ! function_exists( 'wp_create_nonce' ) ) {
	function wp_create_nonce( $action ): string { unset( $action ); return 'nonce'; }
}

class Mtuc_V202_Test_Session {
	public $data = array();
	public function get( $key, $default = null ) { return $this->data[ $key ] ?? $default; }
	public function set( $key, $value ): void { $this->data[ $key ] = $value; }
}
class Mtuc_V202_Test_WC {
	public $session;
	public function __construct() { $this->session = new Mtuc_V202_Test_Session(); }
}
$mtuc_v202_wc = new Mtuc_V202_Test_WC();
function WC() {
	global $mtuc_v202_wc;
	return $mtuc_v202_wc;
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-product-offer-selection.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-checkout-payment.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-calculator.php';

$automatic_popup = array(
	'enabled_schemes'   => array(
		mtuc_build_popup_scheme_option_row( 12, 1, 'BASE', '', 'standard' ),
		mtuc_build_popup_scheme_option_row( 24, 2, 'ZERO', '', 'promo' ),
	),
	'default_scheme_key' => 'p:24:2',
);

// Invalid exact identity must not activate or carry any stale prefill fields.
foreach ( array( 'locked', 'disabled', 'editable' ) as $case ) {
	WC()->session->set(
		MTUC_SESSION_CHECKOUT_PREFILL,
		array( 'scheme_key' => 'p:6:99', 'offer_type' => 'promo', 'parva' => 77.77 )
	);
	$popup  = mtuc_apply_checkout_prefill_to_popup( $automatic_popup );
	$config = mtuc_get_checkout_payment_script_config( array( 'cart_total' => 1000.0, 'popup' => $popup ) );
	mtuc_v202_prefill_assert( 'p:24:2' === $config['defaultSchemeKey'], "{$case}: automatic default remains" );
	mtuc_v202_prefill_assert( false === $config['prefillActive'], "{$case}: invalid prefill inactive" );
	mtuc_v202_prefill_assert( '' === $config['prefillParva'], "{$case}: stale parva omitted" );
	mtuc_v202_prefill_assert( 'standard' === $config['offerType'], "{$case}: stale offer type omitted" );
}

// A valid shorter explicit choice still overrides a longer automatic 0% choice.
WC()->session->set(
	MTUC_SESSION_CHECKOUT_PREFILL,
	array( 'scheme_key' => '12:1', 'offer_type' => 'standard', 'parva' => 55.50 )
);
$popup  = mtuc_apply_checkout_prefill_to_popup( $automatic_popup );
$config = mtuc_get_checkout_payment_script_config( array( 'cart_total' => 1000.0, 'popup' => $popup ) );
mtuc_v202_prefill_assert( '12:1' === $config['defaultSchemeKey'], 'valid exact shorter prefill overrides automatic longer 0%' );
mtuc_v202_prefill_assert( true === $config['prefillActive'], 'valid exact prefill active' );
mtuc_v202_prefill_assert( '55.50' === $config['prefillParva'], 'valid exact prefill carries parva' );

// Scheme changes reset the old scheme's value before recalculation. The server
// response then supplies the selected scheme's own locked amount, when any.
$checkout_js = (string) file_get_contents( MTUC_PLUGIN_DIR . '/js/mtuc-checkout-payment.js' );
mtuc_v202_prefill_assert( false !== strpos( $checkout_js, 'const resetFirstInstallmentForSchemeChange' ), 'Checkout owns an explicit scheme-change parva reset' );
mtuc_v202_prefill_assert( false !== strpos( $checkout_js, '$parva.val("0").prop("readonly", automatic);' ), 'automatic to editable resets visible parva to zero and unlocks it' );
mtuc_v202_prefill_assert( false !== strpos( $checkout_js, '$parvaHidden.val("0.00");' ), 'scheme change resets submitted parva to zero' );
mtuc_v202_prefill_assert( false !== strpos( $checkout_js, 'option && option.automatic_first_installment' ), 'locked state comes from normalized scheme metadata' );
mtuc_v202_prefill_assert( false !== strpos( $checkout_js, "resetFirstInstallmentForSchemeChange(\n\t\t\t\t\tconfig" ), 'months change invokes reset before recalculation' );

$shop_parva = array( 'uni_first_vnoska' => 1 );
$locked_a   = mtuc_resolve_parva_calculation_state( $shop_parva, 1000.0, 12, 0.0, array( 'uni_parva' => 1 ) );
$editable_b = mtuc_resolve_parva_calculation_state( $shop_parva, 1000.0, 18, 0.0, array( 'uni_parva' => 0 ) );
$locked_b   = mtuc_resolve_parva_calculation_state( $shop_parva, 1000.0, 6, 0.0, array( 'uni_parva' => 1 ) );
mtuc_v202_prefill_assert( 83.33 === $locked_a['parva'], 'locked scheme A supplies 83.33' );
mtuc_v202_prefill_assert( 0.0 === $editable_b['parva'] && false === $editable_b['parva_locked'], 'locked A to editable B resolves from reset zero' );
mtuc_v202_prefill_assert( 83.33 === $locked_a['parva'] && true === $locked_a['parva_locked'], 'editable scheme to locked A supplies A amount' );
mtuc_v202_prefill_assert( 166.67 === $locked_b['parva'] && true === $locked_b['parva_locked'], 'locked A to locked B supplies B amount' );

fwrite( STDOUT, 'OK v202-checkout-prefill ' . $mtuc_v202_prefill_assert_count . " assertions\n" );
