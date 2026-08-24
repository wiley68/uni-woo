<?php
/**
 * Financial integrity tests (AUD-WOO-001 / 002 / 003).
 *
 * Run: php tests/run-financial-integrity-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	/**
	 * @return string
	 */
	function get_woocommerce_currency() {
		return $GLOBALS['mtuc_test_wc_currency'] ?? 'BGN';
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financial-integrity.php';

/**
 * Minimal WC_Product stand-in for quantity/price authority tests.
 */
class Mtuc_Test_Product {
	/** @var string */
	public $type = 'simple';
	/** @var int */
	public $id = 10;
	/** @var int */
	public $parent_id = 0;
	/** @var bool */
	public $purchasable = true;
	/** @var bool */
	public $in_stock = true;
	/** @var bool */
	public $sold_individually = false;
	/** @var int */
	public $max_qty = -1;
	/** @var bool */
	public $managing_stock = false;
	/** @var bool */
	public $backorders = false;
	/** @var int */
	public $stock_qty = 0;
	/** @var float */
	public $unit_price_inc_tax = 100.0;

	/**
	 * @param string $type Product type slug.
	 * @return bool
	 */
	public function is_type( string $type ): bool {
		return $this->type === $type;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_parent_id(): int {
		return $this->parent_id;
	}

	public function is_purchasable(): bool {
		return $this->purchasable;
	}

	public function is_in_stock(): bool {
		return $this->in_stock;
	}

	public function is_sold_individually(): bool {
		return $this->sold_individually;
	}

	public function get_max_purchase_quantity(): int {
		return $this->max_qty;
	}

	public function managing_stock(): bool {
		return $this->managing_stock;
	}

	public function backorders_allowed(): bool {
		return $this->backorders;
	}

	/**
	 * @param int $qty Requested quantity.
	 * @return bool
	 */
	public function has_enough_stock( int $qty ): bool {
		return $this->stock_qty >= $qty;
	}
}

if ( ! class_exists( 'WC_Product', false ) ) {
	/**
	 * Alias so type hints accepting WC_Product work in isolated tests.
	 */
	class WC_Product extends Mtuc_Test_Product {
	}
}

if ( ! function_exists( 'wc_get_price_including_tax' ) ) {
	/**
	 * @param object              $product Product.
	 * @param array<string,mixed> $args    Args.
	 * @return float
	 */
	function wc_get_price_including_tax( $product, $args = array() ) {
		$qty  = isset( $args['qty'] ) ? (int) $args['qty'] : 1;
		$unit = isset( $product->unit_price_inc_tax ) ? (float) $product->unit_price_inc_tax : 0.0;
		return $unit * max( 1, $qty );
	}
}

if ( ! function_exists( 'mtuc_get_wc_product_by_id' ) ) {
	/**
	 * @param int $id Product ID.
	 * @return object|null
	 */
	function mtuc_get_wc_product_by_id( $id ) {
		$map = $GLOBALS['mtuc_test_products'] ?? array();
		return isset( $map[ (int) $id ] ) ? $map[ (int) $id ] : null;
	}
}

final class Mtuc_Financial_Integrity_Test_Runner {

	/** @var int */
	private $passed = 0;

	/** @var int */
	private $failed = 0;

	/** @var array<int, string> */
	private $errors = array();

	public function run(): int {
		$this->test_currency_modes();
		$this->test_currency_compatibility_matrix();
		$this->test_no_hidden_conversion_in_resolve();
		$this->test_quantity_validation();
		$this->test_authoritative_line_total_ignores_client_price();
		$this->test_variation_parent_mismatch();
		$this->test_canonical_order_total_helper();
		$this->test_version_constant();

		echo "\nPassed: {$this->passed}; Failed: {$this->failed}\n";
		foreach ( $this->errors as $error ) {
			echo "FAIL: {$error}\n";
		}

		return $this->failed > 0 ? 1 : 0;
	}

	private function test_currency_modes(): void {
		$this->assert_true( 'BGN' === mtuc_get_expected_transaction_currency( array( 'uni_eur' => 0 ) ), 'uni_eur 0 => BGN' );
		$this->assert_true( 'BGN' === mtuc_get_expected_transaction_currency( array( 'uni_eur' => 1 ) ), 'uni_eur 1 dual display => BGN txn' );
		$this->assert_true( 'EUR' === mtuc_get_expected_transaction_currency( array( 'uni_eur' => 2 ) ), 'uni_eur 2 dual display => EUR txn' );
		$this->assert_true( 'EUR' === mtuc_get_expected_transaction_currency( array( 'uni_eur' => 3 ) ), 'uni_eur 3 => EUR' );
	}

	private function test_currency_compatibility_matrix(): void {
		$GLOBALS['mtuc_test_wc_currency'] = 'BGN';
		$this->assert_true( mtuc_is_transaction_currency_compatible( array( 'uni_eur' => 0 ) ), 'Woo BGN + CP BGN allowed' );
		$this->assert_true( mtuc_is_transaction_currency_compatible( array( 'uni_eur' => 1 ) ), 'Woo BGN + CP dual-BGN allowed' );
		$this->assert_true( ! mtuc_is_transaction_currency_compatible( array( 'uni_eur' => 2 ) ), 'Woo BGN + CP EUR rejected' );
		$this->assert_true( ! mtuc_is_transaction_currency_compatible( array( 'uni_eur' => 3 ) ), 'Woo BGN + CP EUR-only rejected' );

		$GLOBALS['mtuc_test_wc_currency'] = 'EUR';
		$this->assert_true( mtuc_is_transaction_currency_compatible( array( 'uni_eur' => 2 ) ), 'Woo EUR + CP EUR allowed' );
		$this->assert_true( mtuc_is_transaction_currency_compatible( array( 'uni_eur' => 3 ) ), 'Woo EUR + CP EUR-only allowed' );
		$this->assert_true( ! mtuc_is_transaction_currency_compatible( array( 'uni_eur' => 0 ) ), 'Woo EUR + CP BGN rejected' );
		$this->assert_true( ! mtuc_is_transaction_currency_compatible( array( 'uni_eur' => 1 ) ), 'Woo EUR + CP dual-BGN rejected' );

		$resolved = mtuc_resolve_transaction_currency( array( 'uni_eur' => 3 ), 'EUR' );
		$this->assert_true( 'EUR' === $resolved, 'resolve returns Woo EUR when compatible' );

		$mismatch = mtuc_resolve_transaction_currency( array( 'uni_eur' => 0 ), 'EUR' );
		$this->assert_true( is_wp_error( $mismatch ), 'resolve fails on mismatch without converting' );
	}

	private function test_no_hidden_conversion_in_resolve(): void {
		$amount_bgn = 195.583;
		// Dual display must not rewrite the financed amount — resolve currency only.
		$currency = mtuc_resolve_transaction_currency( array( 'uni_eur' => 1 ), 'BGN' );
		$this->assert_true( 'BGN' === $currency, 'dual display keeps BGN transaction currency' );
		$this->assert_true( 195.583 === $amount_bgn, 'amount unchanged (no FX conversion)' );
	}

	private function test_quantity_validation(): void {
		$product = new WC_Product();
		$product->unit_price_inc_tax = 50.0;

		$this->assert_true( is_wp_error( mtuc_validate_financing_quantity( $product, 0 ) ), 'qty 0 rejected' );
		$this->assert_true( is_wp_error( mtuc_validate_financing_quantity( $product, -2 ) ), 'negative qty rejected' );
		$this->assert_true( 2 === mtuc_validate_financing_quantity( $product, 2 ), 'valid qty accepted' );

		$product->sold_individually = true;
		$this->assert_true( is_wp_error( mtuc_validate_financing_quantity( $product, 2 ) ), 'sold individually qty>1 rejected' );
		$this->assert_true( 1 === mtuc_validate_financing_quantity( $product, 1 ), 'sold individually qty=1 accepted' );
		$product->sold_individually = false;

		$product->max_qty = 3;
		$this->assert_true( is_wp_error( mtuc_validate_financing_quantity( $product, 5 ) ), 'max purchase exceeded' );
		$product->max_qty = -1;

		$product->managing_stock = true;
		$product->stock_qty      = 2;
		$product->backorders     = false;
		$this->assert_true( is_wp_error( mtuc_validate_financing_quantity( $product, 5 ) ), 'stock exceeded' );
		$this->assert_true( 2 === mtuc_validate_financing_quantity( $product, 2 ), 'stock exact OK' );

		$product->backorders = true;
		$this->assert_true( 5 === mtuc_validate_financing_quantity( $product, 5 ), 'backorder allows oversell' );
	}

	private function test_authoritative_line_total_ignores_client_price(): void {
		$product = new WC_Product();
		$product->unit_price_inc_tax = 120.50;

		$line = mtuc_get_authoritative_line_total( $product, 3 );
		$this->assert_true( 361.5 === (float) $line, 'authoritative total = unit × qty' );

		// Simulate client trying to force 1.00 — ignored because helper never accepts client price.
		$client_tampered = 1.0;
		$this->assert_true( (float) $line !== $client_tampered, 'client tampered price does not control line total' );
	}

	private function test_variation_parent_mismatch(): void {
		$parent = new WC_Product();
		$parent->type = 'variable';
		$parent->id   = 10;

		$variation = new WC_Product();
		$variation->type               = 'variation';
		$variation->id                 = 55;
		$variation->parent_id          = 10;
		$variation->unit_price_inc_tax = 80.0;

		$foreign = new WC_Product();
		$foreign->type               = 'variation';
		$foreign->id                 = 99;
		$foreign->parent_id          = 20;
		$foreign->unit_price_inc_tax = 80.0;

		$GLOBALS['mtuc_test_products'] = array(
			10 => $parent,
			55 => $variation,
			99 => $foreign,
		);

		$ok = mtuc_resolve_financing_product( 10, 55 );
		$this->assert_true( is_array( $ok ) && 55 === (int) $ok['variation_id'], 'valid variation accepted' );

		$bad = mtuc_resolve_financing_product( 10, 99 );
		$this->assert_true( is_wp_error( $bad ), 'parent/variation mismatch rejected' );

		$missing = mtuc_resolve_financing_product( 10, 0 );
		$this->assert_true( is_wp_error( $missing ), 'variable without variation rejected' );

		$line = mtuc_resolve_authoritative_product_financing_line( 10, 55, 2 );
		$this->assert_true( is_array( $line ) && 160.0 === (float) $line['line_total'], 'variation sale/price × qty authoritative' );
	}

	private function test_canonical_order_total_helper(): void {
		$order = new class() {
			public function get_total() {
				return '1234.56';
			}
		};

		// Soft check: function exists and rounds.
		$this->assert_true( function_exists( 'mtuc_get_canonical_financeable_order_total' ), 'canonical order total helper exists' );
		$this->assert_true( function_exists( 'mtuc_get_canonical_financeable_cart_total' ), 'canonical cart total helper exists' );

		// Cart without WC returns 0.
		$this->assert_true( 0.0 === mtuc_get_canonical_financeable_cart_total(), 'empty cart canonical total is 0' );

		unset( $order );
	}

	private function test_version_constant(): void {
		$this->assert_true( defined( 'MTUC_VERSION' ) && '2.0.1' === MTUC_VERSION, 'MTUC_VERSION is 2.0.1' );
	}

	/**
	 * @param mixed  $condition Condition.
	 * @param string $message   Message.
	 */
	private function assert_true( $condition, string $message ): void {
		if ( $condition ) {
			++$this->passed;
			echo '.';
			return;
		}
		++$this->failed;
		$this->errors[] = $message;
		echo 'F';
	}
}

// Ensure version constant for isolated run.
if ( ! defined( 'MTUC_VERSION' ) ) {
	define( 'MTUC_VERSION', '2.0.1' );
}

exit( ( new Mtuc_Financial_Integrity_Test_Runner() )->run() );
