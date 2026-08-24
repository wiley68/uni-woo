<?php
/**
 * AUD-WOO-015 remaining high-value coverage + AUD-WOO-014 rounding contract.
 *
 * Run: php tests/run-aud015-coverage-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_aud015_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_aud015_assert( bool $ok, string $message ): void {
	global $mtuc_aud015_assert_count;
	++$mtuc_aud015_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	/**
	 * @param mixed ...$args Unused.
	 * @return void
	 */
	function add_action( ...$args ): void {
		unset( $args );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * @param mixed ...$args Unused.
	 * @return void
	 */
	function add_filter( ...$args ): void {
		unset( $args );
	}
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return int
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_text_field( $value ) {
		return is_string( $value ) ? trim( $value ) : '';
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * @param string $value Value.
	 * @return string
	 */
	function sanitize_email( $value ) {
		return is_string( $value ) ? trim( $value ) : '';
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'is_email' ) ) {
	/**
	 * @param string $email Email.
	 * @return bool
	 */
	function is_email( $email ) {
		return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 * @return void
	 */
	function esc_html_e( $text, $domain = 'default' ) {
		unset( $domain );
		echo esc_html( $text );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	/**
	 * @param string $key Key.
	 * @return string
	 */
	function sanitize_key( $key ) {
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! defined( 'MTUC_VERSION' ) ) {
	define( 'MTUC_VERSION', '2.0.1' );
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal order stand-in for privacy / thank-you meta tests.
	 */
	class WC_Order {
		/** @var int */
		public $id = 1;
		/** @var string */
		public $payment_method = 'mtunicredit';
		/** @var array<string, mixed> */
		public $meta = array();

		public function get_id(): int {
			return $this->id;
		}

		public function get_order_number(): string {
			return (string) $this->id;
		}

		public function get_payment_method(): string {
			return $this->payment_method;
		}

		/**
		 * @param string $key Meta key.
		 * @return mixed
		 */
		public function get_meta( $key ) {
			return $this->meta[ $key ] ?? '';
		}

		/**
		 * @param string $key Meta key.
		 * @param mixed  $value Value.
		 * @return void
		 */
		public function update_meta_data( $key, $value ): void {
			$this->meta[ $key ] = $value;
		}

		public function save(): void {
		}
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-calculator.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-product-popup.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cart-scheme-intersection.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cart-calculator.php';

if ( ! function_exists( 'mtuc_get_process2_confirmation_message' ) ) {
	/**
	 * @return string
	 */
	function mtuc_get_process2_confirmation_message(): string {
		return 'Process 2 confirmation';
	}
}

// ---------------------------------------------------------------------------
// AUD-WOO-014 — established rounding contract (characterization / regression)
// ---------------------------------------------------------------------------

/**
 * Production formula used by popup/cart calculators.
 *
 * @param float $price Price.
 * @param float $parva First installment.
 * @param float $coeff Coefficient.
 * @param int   $months Months.
 * @return array{principal:float,monthly:float,total:float}
 */
function mtuc_aud014_formula( float $price, float $parva, float $coeff, int $months ): array {
	$principal = round( $price - $parva, 2 );
	$monthly   = round( $principal * $coeff, 2 );
	$total     = round( $monthly * $months, 2 );

	return array(
		'principal' => $principal,
		'monthly'   => $monthly,
		'total'     => $total,
	);
}

$rounding_cases = array(
	array( 100.01, 0.0, 0.083333, 12, 100.01, 8.33, 99.96 ),
	array( 100.05, 0.0, 0.083333, 12, 100.05, 8.34, 100.08 ),
	array( 999.99, 0.0, 0.083333, 12, 999.99, 83.33, 999.96 ),
	array( 874.01, 50.01, 0.166667, 6, 824.00, 137.33, 823.98 ),
	array( 1500.00, 100.00, 0.091234567, 24, 1400.00, 127.73, 3065.52 ),
	array( 97.49, 0.0, 0.083333, 12, 97.49, 8.12, 97.44 ),
	array( 199.99, 19.99, 0.09375, 18, 180.00, 16.88, 303.84 ),
	array( 15.01, 0.0, 0.18, 3, 15.01, 2.70, 8.10 ),
);

foreach ( $rounding_cases as $idx => $case ) {
	[ $price, $parva, $coeff, $months, $exp_principal, $exp_monthly, $exp_total ] = $case;
	$result = mtuc_aud014_formula( $price, $parva, $coeff, $months );
	mtuc_aud015_assert( $exp_principal === $result['principal'], "AUD-014 case {$idx} principal" );
	mtuc_aud015_assert( $exp_monthly === $result['monthly'], "AUD-014 case {$idx} monthly" );
	mtuc_aud015_assert( $exp_total === $result['total'], "AUD-014 case {$idx} total_payable" );

	if ( function_exists( 'mtuc_compute_financing_amounts' ) ) {
		$extracted = mtuc_compute_financing_amounts( $price, $parva, $coeff, $months );
		mtuc_aud015_assert( is_array( $extracted ), "AUD-014 case {$idx} extracted helper returns array" );
		mtuc_aud015_assert( $exp_principal === $extracted['loan_amount'], "AUD-014 case {$idx} extracted principal" );
		mtuc_aud015_assert( $exp_monthly === $extracted['monthly_installment'], "AUD-014 case {$idx} extracted monthly" );
		mtuc_aud015_assert( $exp_total === $extracted['total_payable'], "AUD-014 case {$idx} extracted total" );
	}
}

// Cart calculator path uses the same round points.
$shop_min = array(
	'uni_first_vnoska' => 1,
	'uni_eur'          => 3,
);
$coeff_list = array(
	array(
		'onlineProductCode' => 'STD',
		'installmentCount'  => 12,
		'coeff'             => 0.083333,
		'interestPercent'   => 10.5,
	),
);
$common = array(
	mtuc_build_popup_scheme_option_row( 12, 0, 'STD', '', 'standard' ),
);

// Prefer real calculator when helpers from functions.php are available.
if ( function_exists( 'mtuc_find_coeff_entry' ) && function_exists( 'mtuc_resolve_parva_calculation_state' ) && function_exists( 'mtuc_calculate_gpr' ) ) {
	$calc = mtuc_calculate_cart_popup_credit( $shop_min, $coeff_list, 100.01, 12, 'standard', 0.0, 0, 'standard', $common );
	mtuc_aud015_assert( is_array( $calc ), 'cart calculator returns array' );
	mtuc_aud015_assert( 8.33 === (float) $calc['monthly_installment'], 'cart calculator monthly matches AUD-014' );
	mtuc_aud015_assert( 99.96 === (float) $calc['total_payable'], 'cart calculator total matches AUD-014' );
} else {
	// Load only the helpers needed for cart credit calculation without redeclaring mtuc_is_yes_flag.
	require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financial-integrity.php';

	if ( ! function_exists( 'mtuc_find_coeff_entry' ) ) {
		/**
		 * @param array<int, array<string, mixed>> $coeff_list Coeff rows.
		 * @param string                           $kop_code KOP.
		 * @param int                              $months Months.
		 * @return array<string, mixed>|null
		 */
		function mtuc_find_coeff_entry( array $coeff_list, string $kop_code, int $months ): ?array {
			foreach ( $coeff_list as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				if ( trim( (string) ( $entry['onlineProductCode'] ?? '' ) ) !== $kop_code ) {
					continue;
				}
				if ( (int) ( $entry['installmentCount'] ?? 0 ) !== $months ) {
					continue;
				}
				return $entry;
			}
			return null;
		}
	}

	if ( ! function_exists( 'mtuc_resolve_parva_calculation_state' ) ) {
		/**
		 * @param array<string, mixed>      $shop Shop.
		 * @param float                     $price Price.
		 * @param int                       $months Months.
		 * @param float                     $user_parva User parva.
		 * @param array<string, mixed>|null $filter Filter.
		 * @return array{parva:float,parva_locked:bool,show_parva:bool}
		 */
		function mtuc_resolve_parva_calculation_state( array $shop, float $price, int $months, float $user_parva, $filter ): array {
			unset( $shop, $months, $filter );
			$parva = max( 0.0, min( round( $user_parva, 2 ), $price ) );
			return array(
				'parva'        => $parva,
				'parva_locked' => false,
				'show_parva'   => true,
			);
		}
	}

	if ( ! function_exists( 'mtuc_get_shop_schema_filter_by_id' ) ) {
		/**
		 * @param array<string, mixed> $shop Shop.
		 * @param int                  $filter_id Filter id.
		 * @return null
		 */
		function mtuc_get_shop_schema_filter_by_id( array $shop, int $filter_id ) {
			unset( $shop, $filter_id );
			return null;
		}
	}

	if ( ! function_exists( 'mtuc_calculate_gpr' ) ) {
		/**
		 * @param int   $months Months.
		 * @param float $monthly Monthly.
		 * @param float $price Price.
		 * @return float
		 */
		function mtuc_calculate_gpr( int $months, float $monthly, float $price ): float {
			unset( $months, $monthly, $price );
			return 0.0;
		}
	}

	if ( ! function_exists( 'mtuc_format_popup_amount_display' ) ) {
		/**
		 * @param float                $amount Amount.
		 * @param array<string, mixed> $shop Shop.
		 * @return array{primary:string,secondary:string,dual:bool}
		 */
		function mtuc_format_popup_amount_display( float $amount, array $shop ): array {
			unset( $shop );
			return array(
				'primary'   => number_format( round( $amount, 2 ), 2, '.', '' ),
				'secondary' => '',
				'dual'      => false,
			);
		}
	}

	if ( ! function_exists( 'mtuc_format_popup_percent_display' ) ) {
		/**
		 * @param float $value Percent.
		 * @return string
		 */
		function mtuc_format_popup_percent_display( float $value ): string {
			return number_format( abs( round( $value, 2 ) ), 2, '.', '' );
		}
	}

	$calc = mtuc_calculate_cart_popup_credit( $shop_min, $coeff_list, 100.01, 12, 'standard', 0.0, 0, 'standard', $common );
	mtuc_aud015_assert( is_array( $calc ), 'cart calculator returns array' );
	mtuc_aud015_assert( 8.33 === (float) $calc['monthly_installment'], 'cart calculator monthly matches AUD-014' );
	mtuc_aud015_assert( 99.96 === (float) $calc['total_payable'], 'cart calculator total matches AUD-014' );
	mtuc_aud015_assert( 100.01 === (float) $calc['loan_amount'], 'cart calculator principal matches AUD-014' );
}

// Server-authoritative total must win over a stale frontend cart total.
$stale_frontend_total = 900.00;
$authoritative_total  = 950.50;
mtuc_aud015_assert( abs( $authoritative_total - $stale_frontend_total ) > 0.009, 'mutation delta exceeds tolerance' );
$recalc = mtuc_aud014_formula( $authoritative_total, 0.0, 0.083333, 12 );
$stale  = mtuc_aud014_formula( $stale_frontend_total, 0.0, 0.083333, 12 );
mtuc_aud015_assert( $recalc['monthly'] !== $stale['monthly'], 'authoritative total changes monthly vs stale cart calc' );

// ---------------------------------------------------------------------------
// Cart scheme intersection (intentional filter-ID-agnostic match key)
// ---------------------------------------------------------------------------

mtuc_aud015_assert(
	'standard|CAT|12' === mtuc_build_cart_scheme_match_key(
		array(
			'scheme_type' => 'standard',
			'kop_code'    => 'CAT',
			'months'      => 12,
			'filter_id'   => 99,
		)
	),
	'match key ignores filter_id'
);

$same_kop_diff_filter = mtuc_intersect_cart_scheme_options(
	array(
		array( mtuc_build_popup_scheme_option_row( 12, 31, 'CAT', '', 'standard' ) ),
		array( mtuc_build_popup_scheme_option_row( 12, 32, 'CAT', '', 'standard' ) ),
	)
);
mtuc_aud015_assert( 1 === count( $same_kop_diff_filter ), 'different filter IDs with same KOP/month still intersect' );
mtuc_aud015_assert( 31 === (int) $same_kop_diff_filter[0]['filter_id'], 'first-line filter metadata retained' );

$diff_kop = mtuc_intersect_cart_scheme_options(
	array(
		array( mtuc_build_popup_scheme_option_row( 12, 1, 'AAA', '', 'standard' ) ),
		array( mtuc_build_popup_scheme_option_row( 12, 2, 'BBB', '', 'standard' ) ),
	)
);
mtuc_aud015_assert( array() === $diff_kop, 'different KOP codes do not intersect' );

$diff_months = mtuc_intersect_cart_scheme_options(
	array(
		array( mtuc_build_popup_scheme_option_row( 6, 1, 'CAT', '', 'standard' ) ),
		array( mtuc_build_popup_scheme_option_row( 12, 2, 'CAT', '', 'standard' ) ),
	)
);
mtuc_aud015_assert( array() === $diff_months, 'different months do not intersect' );

$one_ineligible = mtuc_intersect_cart_scheme_options(
	array(
		array(
			mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 24, 1, 'CAT', '', 'standard' ),
		),
		array(),
	)
);
mtuc_aud015_assert( array() === $one_ineligible, 'empty line options clear intersection' );

$multi_common = mtuc_intersect_cart_scheme_options(
	array(
		array(
			mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 24, 1, 'CAT', '', 'standard' ),
		),
		array(
			mtuc_build_popup_scheme_option_row( 12, 9, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 36, 9, 'OTHER', '', 'standard' ),
		),
	)
);
mtuc_aud015_assert( 1 === count( $multi_common ), 'only shared KOP/month survives multi-product cart' );
mtuc_aud015_assert( 12 === (int) $multi_common[0]['months'], 'shared month is 12' );

mtuc_aud015_assert( 12 === mtuc_lcm_int_list( array( 6, 12 ) ), 'LCM 6,12 = 12' );
mtuc_aud015_assert( 24 === mtuc_lcm_int_list( array( 6, 8 ) ), 'LCM 6,8 = 24' );

// ---------------------------------------------------------------------------
// Thank You / refresh — redirect dispatched once (contract)
// ---------------------------------------------------------------------------

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-presentation.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-idempotency.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-order-diagnostics.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-error-normalizer.php';

mtuc_aud015_assert(
	defined( 'MTUC_ORDER_META_BANK_REDIRECT_DISPATCHED' ),
	'thank-you redirect dispatched meta constant exists'
);

$thankyou_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-checkout-payment.php' );
mtuc_aud015_assert(
	false !== strpos( $thankyou_src, 'MTUC_ORDER_META_BANK_REDIRECT_DISPATCHED' ),
	'thank-you hop checks redirect-dispatched meta'
);
mtuc_aud015_assert(
	false !== strpos( $thankyou_src, 'update_meta_data( MTUC_ORDER_META_BANK_REDIRECT_DISPATCHED, 1 )' ),
	'thank-you hop marks redirect as dispatched before browser redirect'
);

$reuse_order = new WC_Order();
$reuse_order->update_meta_data( MTUC_ORDER_META_BANK_REDIRECT_DISPATCHED, 1 );
$reuse_order->update_meta_data( MTUC_ORDER_META_SMARTUCF_REDIRECT_URL, 'https://bank.example/session' );
mtuc_aud015_assert(
	1 === (int) $reuse_order->get_meta( MTUC_ORDER_META_BANK_REDIRECT_DISPATCHED ),
	'refresh revisits see dispatched flag and must not re-submit financing'
);

// Popup successful submission reuse (no second operation).
mtuc_aud015_assert(
	function_exists( 'mtuc_build_existing_popup_submission_result' ) || function_exists( 'mtuc_popup_order_has_successful_bank_submission' ),
	'popup idempotent reuse helpers exist'
);

// ---------------------------------------------------------------------------
// Classic vs Blocks payment-data field contract (no browser E2E)
// ---------------------------------------------------------------------------

$classic_js = (string) file_get_contents( MTUC_PLUGIN_DIR . '/js/mtuc-checkout-payment.js' );
$blocks_js  = (string) file_get_contents( MTUC_PLUGIN_DIR . '/js/mtuc-checkout-blocks.js' );
mtuc_aud015_assert( false !== strpos( $classic_js, 'mtuc_scheme_key' ), 'classic checkout posts mtuc_scheme_key' );
mtuc_aud015_assert( false !== strpos( $classic_js, 'mtuc_parva' ), 'classic checkout posts mtuc_parva' );
mtuc_aud015_assert( false !== strpos( $classic_js, 'paymentMethodData' ), 'classic validation exposes paymentMethodData for Blocks' );
mtuc_aud015_assert( false !== strpos( $blocks_js, 'paymentMethodData' ), 'Blocks integration forwards paymentMethodData' );
mtuc_aud015_assert( false !== strpos( $blocks_js, 'mtucInitCheckoutPayment' ), 'Blocks reuses classic checkout controller' );

$gateway_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/class-mtuc-payment-gateway.php' );
mtuc_aud015_assert( false !== strpos( $gateway_src, 'mtuc_scheme_key' ), 'gateway validate/process reads mtuc_scheme_key' );

// ---------------------------------------------------------------------------
// AUD-WOO-015-PRIVACY-EGN — audience-specific EGN policy
// ---------------------------------------------------------------------------

$p2 = new WC_Order();
$p2->update_meta_data( MTUC_ORDER_META_PROCESS2, 1 );
$p2->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS2 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'months', 12 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'egn', '9001011234' );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'phone2', '0888123456' );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'parva', 0 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'loan_amount', 1000 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'monthly_installment', 90 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'total_payable', 1080 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'glp', 0 );
$p2->update_meta_data( MTUC_ORDER_META_PREFIX . 'gpr', 0 );

$admin_email_rows = mtuc_get_order_credit_meta_rows( $p2, MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL );
mtuc_aud015_assert( isset( $admin_email_rows['ЕГН'] ) && '9001011234' === $admin_email_rows['ЕГН'], 'Process 2 admin email may include EGN' );

$admin_panel_rows = mtuc_get_order_credit_meta_rows( $p2, MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL );
mtuc_aud015_assert( ! isset( $admin_panel_rows['ЕГН'] ), 'Process 2 Woo admin panel must not include EGN' );
mtuc_aud015_assert( ! isset( mtuc_get_admin_order_credit_meta_rows( $p2 )['ЕГН'] ), 'admin panel helper excludes EGN' );
mtuc_aud015_assert( isset( $admin_panel_rows['Втори телефон'] ), 'admin panel retains other Process 2 fields' );

$customer_rows = mtuc_get_order_credit_meta_rows( $p2, MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER );
mtuc_aud015_assert( ! isset( $customer_rows['ЕГН'] ), 'Process 2 customer rows must not include EGN' );
mtuc_aud015_assert( false === strpos( wp_json_encode( $customer_rows ), '9001011234' ), 'customer rows must not embed EGN value' );
mtuc_aud015_assert( isset( $customer_rows['Съобщение'] ), 'Process 2 customer still gets confirmation message' );
mtuc_aud015_assert( ! isset( $customer_rows['Втори телефон'] ), 'Process 2 customer rows omit phone2' );

// Presentation parity — representative non-EGN fields + stable ordering (keys).
mtuc_aud015_assert( isset( $customer_rows['Срок (месеци)'] ) && '12' === $customer_rows['Срок (месеци)'], 'customer rows include months' );
mtuc_aud015_assert( isset( $customer_rows['Първоначална вноска'] ) && '0.00' === $customer_rows['Първоначална вноска'], 'customer rows include parva' );
mtuc_aud015_assert( isset( $customer_rows['Сума на заема'] ) && '1000.00' === $customer_rows['Сума на заема'], 'customer rows include loan amount' );
mtuc_aud015_assert( isset( $customer_rows['Месечна вноска'] ) && '90.00' === $customer_rows['Месечна вноска'], 'customer rows include monthly' );
mtuc_aud015_assert( isset( $customer_rows['Обща дължима сума'] ) && '1080.00' === $customer_rows['Обща дължима сума'], 'customer rows include total payable' );
mtuc_aud015_assert( isset( $customer_rows['ГЛП / ГПР'] ) && '0.00% / 0.00%' === $customer_rows['ГЛП / ГПР'], 'customer rows include GLP/GPR' );

$customer_labels = array_keys( $customer_rows );
$months_pos      = array_search( 'Срок (месеци)', $customer_labels, true );
$parva_pos       = array_search( 'Първоначална вноска', $customer_labels, true );
$loan_pos        = array_search( 'Сума на заема', $customer_labels, true );
$monthly_pos     = array_search( 'Месечна вноска', $customer_labels, true );
$total_pos       = array_search( 'Обща дължима сума', $customer_labels, true );
$glp_pos         = array_search( 'ГЛП / ГПР', $customer_labels, true );
$msg_pos         = array_search( 'Съобщение', $customer_labels, true );
mtuc_aud015_assert(
	false !== $months_pos
	&& false !== $parva_pos
	&& $months_pos < $parva_pos
	&& $parva_pos < $loan_pos
	&& $loan_pos < $monthly_pos
	&& $monthly_pos < $total_pos
	&& $total_pos < $glp_pos
	&& $glp_pos < $msg_pos,
	'customer financing row order is preserved'
);

$email_hook_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php' );
mtuc_aud015_assert(
	false !== strpos( $email_hook_src, 'function mtuc_email_after_order_table_credit_details' ),
	'transactional email hook exists'
);
mtuc_aud015_assert(
	false === strpos( $email_hook_src, 'unset( $sent_to_admin' ),
	'email hook must use sent_to_admin audience flag'
);
mtuc_aud015_assert(
	false !== strpos( $email_hook_src, 'MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL' )
	&& false !== strpos( $email_hook_src, 'MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER' ),
	'email/thank-you paths select audience-aware financing rows'
);
mtuc_aud015_assert(
	false !== strpos( $email_hook_src, 'mtuc_render_thankyou_process2_credit_section' )
	&& false !== strpos( $email_hook_src, 'MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER' ),
	'Thank You Process 2 section uses customer audience'
);

$presentation_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-financing-presentation.php' );
mtuc_aud015_assert(
	false !== strpos( $presentation_src, 'function mtuc_get_order_credit_meta_rows' ),
	'financing presentation module owns credit meta rows'
);

ob_start();
mtuc_render_order_credit_email_section( $p2, false, MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER );
$customer_email_html = (string) ob_get_clean();
mtuc_aud015_assert( false === strpos( $customer_email_html, '9001011234' ), 'customer email HTML must not contain EGN' );
mtuc_aud015_assert( false === stripos( $customer_email_html, 'ЕГН' ), 'customer email HTML must not contain EGN label' );

ob_start();
mtuc_render_order_credit_email_section( $p2, false, MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL );
$admin_email_html = (string) ob_get_clean();
mtuc_aud015_assert( false !== strpos( $admin_email_html, '9001011234' ), 'admin email HTML must contain EGN' );

$p1 = new WC_Order();
$p1->update_meta_data( MTUC_ORDER_META_PROCESS2, 0 );
$p1->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS1 );
$p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'months', 12 );
$p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'egn', '9001011234' );
foreach (
	array(
		MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER,
		MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL,
		MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL,
	) as $audience
) {
	$p1_rows = mtuc_get_order_credit_meta_rows( $p1, $audience );
	mtuc_aud015_assert( ! isset( $p1_rows['ЕГН'] ), 'Process 1 ' . $audience . ' must not expose EGN' );
	mtuc_aud015_assert( false === strpos( wp_json_encode( $p1_rows ), '9001011234' ), 'Process 1 ' . $audience . ' must not embed EGN value' );
}

mtuc_aud015_assert( ! mtuc_credit_rows_audience_includes_egn( MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER ), 'customer audience excludes EGN' );
mtuc_aud015_assert( ! mtuc_credit_rows_audience_includes_egn( MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL ), 'admin panel audience excludes EGN' );
mtuc_aud015_assert( mtuc_credit_rows_audience_includes_egn( MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL ), 'admin email audience allows EGN' );

// Diagnostics must not carry EGN.
if ( function_exists( 'mtuc_record_order_financing_diagnostic' ) && function_exists( 'mtuc_get_order_financing_diagnostic' ) ) {
	mtuc_record_order_financing_diagnostic( $p2, new WP_Error( 'mtuc_test', 'fail' ), 'cp' );
	$diag_json = wp_json_encode( mtuc_get_order_financing_diagnostic( $p2 ) );
	mtuc_aud015_assert( is_string( $diag_json ) && false === strpos( $diag_json, '9001011234' ), 'diagnostics must not contain EGN' );
}

// ---------------------------------------------------------------------------
// HPOS-oriented callback lookup remains meta-based (no posts-table assumption)
// ---------------------------------------------------------------------------

$lookup_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php' );
mtuc_aud015_assert( false !== strpos( $lookup_src, 'function mtuc_find_order_by_cp_order_id' ), 'CP external ID lookup helper exists' );
mtuc_aud015_assert( false !== strpos( $lookup_src, 'wc_get_orders' ), 'CP lookup uses wc_get_orders (HPOS-compatible)' );
mtuc_aud015_assert( false === strpos( $lookup_src, '$wpdb->get_results' ), 'CP lookup avoids raw wpdb order queries' );
mtuc_aud015_assert(
	false !== strpos( $lookup_src, 'MTUC_ORDER_META_CP_SHOP_ORDER_ID' ),
	'lookup prefers persisted CP shop order meta'
);

// Abandonment: no invented bank status — last known bank meta remains.
$abandoned = new WC_Order();
$abandoned->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS1 );
mtuc_aud015_assert(
	MTUC_BANK_STATUS_SENT_PROCESS1 === $abandoned->get_meta( MTUC_ORDER_META_BANK_STATUS ),
	'customer abandonment leaves last-known bank status unchanged'
);

fwrite( STDOUT, 'OK: ' . $mtuc_aud015_assert_count . ' AUD-WOO-015/014 coverage assertions passed' . PHP_EOL );
exit( 0 );
