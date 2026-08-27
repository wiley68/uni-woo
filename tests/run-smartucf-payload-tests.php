<?php
/**
 * Characterization / golden contract tests for Woo → SmartUCF session payloads (AUD-WOO-016 Step 11).
 *
 * Run: php tests/run-smartucf-payload-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_su_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_su_assert( bool $ok, string $message ): void {
	global $mtuc_su_assert_count;
	++$mtuc_su_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

/**
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $message  Failure message.
 * @return void
 */
function mtuc_su_assert_same( $expected, $actual, string $message ): void {
	mtuc_su_assert( $expected === $actual, $message . ' | expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) );
}

if ( ! defined( 'MTUC_VERSION' ) ) {
	define( 'MTUC_VERSION', '2.0.2' );
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	/**
	 * @return string
	 */
	function get_woocommerce_currency() {
		return $GLOBALS['mtuc_test_wc_currency'] ?? 'BGN';
	}
}

if ( ! function_exists( 'mtuc_is_shop_process_2' ) ) {
	/**
	 * @param array<string, mixed> $shop Shop.
	 * @return bool
	 */
	function mtuc_is_shop_process_2( array $shop ): bool {
		return 1 === (int) ( $shop['uni_proces'] ?? 0 );
	}
}

if ( ! function_exists( 'mtuc_get_catalog_product_id' ) ) {
	/**
	 * @param object $product Product.
	 * @return int
	 */
	function mtuc_get_catalog_product_id( $product ): int {
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = (int) $product->get_parent_id();
			return $parent_id > 0 ? $parent_id : (int) $product->get_id();
		}
		return (int) $product->get_id();
	}
}

if ( ! function_exists( 'mtuc_get_product_variation_id' ) ) {
	/**
	 * @param object $product Product.
	 * @return int
	 */
	function mtuc_get_product_variation_id( $product ): int {
		return $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;
	}
}

if ( ! function_exists( 'mtuc_get_product_category_ids' ) ) {
	/**
	 * @param object|null $product Product.
	 * @return list<int>
	 */
	function mtuc_get_product_category_ids( $product = null ): array {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_category_ids' ) ) {
			return array();
		}
		$ids = $product->get_category_ids();
		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}
}

if ( ! class_exists( 'WC_Product', false ) ) {
	/**
	 * Test product.
	 */
	class WC_Product {
		/** @var int */
		public $id = 0;
		/** @var int */
		public $parent_id = 0;
		/** @var string */
		public $type = 'simple';
		/** @var string */
		public $name = '';
		/** @var list<int> */
		public $category_ids = array();

		public function get_id(): int {
			return $this->id;
		}

		public function get_parent_id(): int {
			return $this->parent_id;
		}

		public function get_name(): string {
			return $this->name;
		}

		/**
		 * @param string $type Type.
		 * @return bool
		 */
		public function is_type( string $type ): bool {
			return $this->type === $type;
		}

		/**
		 * @return list<int>
		 */
		public function get_category_ids(): array {
			return $this->category_ids;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product', false ) ) {
	/**
	 * Test line item.
	 */
	class WC_Order_Item_Product {
		/** @var WC_Product|null */
		public $product = null;
		/** @var int */
		public $quantity = 1;
		/** @var float */
		public $total = 0.0;
		/** @var float */
		public $total_tax = 0.0;

		public function get_product() {
			return $this->product;
		}

		public function get_quantity(): int {
			return $this->quantity;
		}

		public function get_total(): float {
			return $this->total;
		}

		public function get_total_tax(): float {
			return $this->total_tax;
		}
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Test order.
	 */
	class WC_Order {
		/** @var int */
		public $id = 0;
		/** @var float */
		public $total = 0.0;
		/** @var string */
		public $currency = 'BGN';
		/** @var string */
		public $status = 'pending';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var list<WC_Order_Item_Product> */
		public $items = array();
		/** @var string */
		public $shipping_address_1 = '';
		/** @var string */
		public $shipping_address_2 = '';
		/** @var string */
		public $shipping_city = '';
		/** @var string */
		public $shipping_postcode = '';
		/** @var int */
		public $save_count = 0;
		/** @var string */
		public $bank_status_meta = '';

		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_total(): float {
			return $this->total;
		}

		public function get_currency(): string {
			return $this->currency;
		}

		public function get_status(): string {
			return $this->status;
		}

		/**
		 * @param string $type Item type.
		 * @return list<WC_Order_Item_Product>
		 */
		public function get_items( $type = '' ) {
			unset( $type );
			return $this->items;
		}

		public function get_shipping_address_1(): string {
			return $this->shipping_address_1;
		}

		public function get_shipping_address_2(): string {
			return $this->shipping_address_2;
		}

		public function get_shipping_city(): string {
			return $this->shipping_city;
		}

		public function get_shipping_postcode(): string {
			return $this->shipping_postcode;
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
			if ( '_mtuc_bank_status' === $key ) {
				$this->bank_status_meta = (string) $value;
			}
		}

		public function save(): void {
			++$this->save_count;
		}
	}
}

foreach (
	array(
		'esc_url_raw'         => static function ( $url ) {
			return (string) $url;
		},
		'esc_html'            => static function ( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		},
		'esc_url'             => static function ( $url ) {
			return (string) $url;
		},
		'esc_attr'            => static function ( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		},
		'wp_kses_post'        => static function ( $text ) {
			return (string) $text;
		},
		'add_action'          => static function () {
			return true;
		},
		'add_filter'          => static function () {
			return true;
		},
		'sanitize_key'        => static function ( $key ) {
			$key = strtolower( (string) $key );
			return preg_replace( '/[^a-z0-9_\-]/', '', $key );
		},
		'sanitize_text_field' => static function ( $str ) {
			return trim( strip_tags( (string) $str ) );
		},
	) as $fn => $cb
) {
	if ( ! function_exists( $fn ) ) {
		$GLOBALS['mtuc_su_stub_cbs'][ $fn ] = $cb;
		// phpcs:ignore Squiz.PHP.Eval.Discouraged -- test-only stubs.
		eval( 'function ' . $fn . '( ...$args ) { $cb = $GLOBALS["mtuc_su_stub_cbs"][' . var_export( $fn, true ) . ']; return $cb( ...$args ); }' );
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financial-integrity.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
if ( is_readable( MTUC_PLUGIN_DIR . '/includes/mtuc-cp-order-payload.php' ) ) {
	require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cp-order-payload.php';
}
if ( is_readable( MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-payload.php' ) ) {
	require_once MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-payload.php';
}

$payload_src     = is_readable( MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-payload.php' )
	? (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-smartucf-payload.php' )
	: '';
$popup_order_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php' );
$bootstrap_src   = (string) file_get_contents( MTUC_PLUGIN_DIR . '/mtunicredit.php' );
$client_src      = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/class-mtuc-smartucf-api-client.php' );
$secrets_src     = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-secrets.php' );

$product       = new WC_Product();
$product->id   = 42;
$product->name = "Тест \"продукт\" с 'кавички'";
$product->category_ids = array( 7, 9 );

$item           = new WC_Order_Item_Product();
$item->product  = $product;
$item->quantity = 2;
$item->total    = 200.0;
$item->total_tax = 40.0; // unit inc tax = 120.00

$order                        = new WC_Order( 874 );
$order->total                 = 1234.56;
$order->currency              = 'BGN';
$order->items                 = array( $item );
$order->shipping_address_1    = 'ул. Доставка 1';
$order->shipping_city         = 'София';
$order->shipping_postcode     = '1000';

$customer = array(
	'first_name' => 'Иван',
	'last_name'  => 'Петров',
	'phone'      => '0888123456',
	'email'      => 'ivan@example.com',
	'address'    => 'ул. Тестова 1',
	'egn'        => '8001010000', // must NOT appear in SmartUCF payload
	'phone2'     => '0888000000',
);

$calculation = array(
	'kop_code'            => 'POS COM 50',
	'parva'               => 10.0,
	'months'              => 12,
	'monthly_installment' => 85.5,
	'gpr'                 => 12.34,
	'glp'                 => 9.87,
);

$shop = array(
	'uni_user'     => 'shop-user',
	'uni_password' => 'shop-pass',
	'uni_proces'   => 0,
	'uni_eur'      => 0,
);

$expected_p1 = array(
	'user'                  => 'shop-user',
	'pass'                  => 'shop-pass',
	'orderNo'               => '874',
	'clientFirstName'       => 'Иван',
	'clientLastName'        => 'Петров',
	'clientPhone'           => '0888123456',
	'clientEmail'           => 'ivan@example.com',
	'clientDeliveryAddress' => 'ул. Доставка 1, София, 1000',
	'onlineProductCode'     => 'POS COM 50',
	'totalPrice'            => 1234.56,
	'initialPayment'        => 10.0,
	'installmentCount'      => 12,
	'monthlyPayment'        => 85.5,
	'items'                 => array(
		array(
			'name'        => 'Тест продукт с кавички',
			'code'        => 42,
			'type'        => 7,
			'count'       => 2,
			'singlePrice' => 120.0,
		),
	),
);

// ---------------------------------------------------------------------------
// Golden Process 1 product-popup entry point
// ---------------------------------------------------------------------------

$actual_product = mtuc_build_smartucf_session_payload(
	$order,
	$customer,
	$calculation,
	$product,
	42,
	0,
	2,
	$shop
);

mtuc_su_assert_same( $expected_p1, $actual_product, 'Process 1 product SmartUCF golden contract' );

// ---------------------------------------------------------------------------
// Cart/checkout entry point (same semantic contract; items from order)
// ---------------------------------------------------------------------------

$actual_cart = mtuc_build_cart_smartucf_session_payload( $order, $customer, $calculation, $shop );
mtuc_su_assert_same( $expected_p1, $actual_cart, 'cart/checkout SmartUCF payload matches product entry contract' );

// ---------------------------------------------------------------------------
// Multi-line cart products
// ---------------------------------------------------------------------------

$p2       = new WC_Product();
$p2->id   = 99;
$p2->name = 'Line_B';
$p2->category_ids = array( 3 );
$item2            = new WC_Order_Item_Product();
$item2->product   = $p2;
$item2->quantity  = 1;
$item2->total     = 50.0;
$item2->total_tax = 0.0;

$multi         = new WC_Order( 900 );
$multi->total  = 250.0;
$multi->items  = array( $item, $item2 );
$multi_payload = mtuc_build_cart_smartucf_session_payload( $multi, $customer, $calculation, $shop );
mtuc_su_assert( 2 === count( $multi_payload['items'] ), 'multi-line items count' );
mtuc_su_assert( 250.0 === $multi_payload['totalPrice'], 'multi-line uses full order total' );
mtuc_su_assert( 99 === $multi_payload['items'][1]['code'], 'second line product code' );
mtuc_su_assert( 'Line_B' === $multi_payload['items'][1]['name'], 'underscore preserved in SmartUCF item name' );

// ---------------------------------------------------------------------------
// Financial contract: amount / months / parva / monthly (no GPR/GLP in payload)
// ---------------------------------------------------------------------------

mtuc_su_assert( 1234.56 === $actual_product['totalPrice'], 'canonical full order total' );
mtuc_su_assert( 10.0 === $actual_product['initialPayment'], 'parva from calculation snapshot' );
mtuc_su_assert( 12 === $actual_product['installmentCount'], 'months from calculation' );
mtuc_su_assert( 85.5 === $actual_product['monthlyPayment'], 'monthly from calculation' );
mtuc_su_assert( ! array_key_exists( 'gpr', $actual_product ), 'GPR not in SmartUCF session payload' );
mtuc_su_assert( ! array_key_exists( 'glp', $actual_product ), 'GLP not in SmartUCF session payload' );
mtuc_su_assert( ! array_key_exists( 'currency', $actual_product ), 'currency field absent from SmartUCF session payload' );

// ---------------------------------------------------------------------------
// Guest / missing shipping → customer address fallback
// ---------------------------------------------------------------------------

$guest_order       = new WC_Order( 55 );
$guest_order->total = 99.0;
$guest_order->items = array( $item );
$guest_customer    = array(
	'first_name' => 'Гост',
	'last_name'  => '',
	'phone'      => '0888000001',
	'email'      => 'guest@example.com',
	'address'    => 'Адрес 5',
);
$guest_payload = mtuc_build_cart_smartucf_session_payload(
	$guest_order,
	$guest_customer,
	array(
		'kop_code'            => 'STD',
		'parva'               => 0,
		'months'              => 6,
		'monthly_installment' => 16.5,
	),
	$shop
);
mtuc_su_assert( 'Гост' === $guest_payload['clientFirstName'], 'guest first name' );
mtuc_su_assert( '' === $guest_payload['clientLastName'], 'empty last name preserved' );
mtuc_su_assert( 'Адрес 5' === $guest_payload['clientDeliveryAddress'], 'fallback to customer address' );
mtuc_su_assert( 0.0 === $guest_payload['initialPayment'], 'missing parva becomes 0.0 float' );

// ---------------------------------------------------------------------------
// EGN policy
// ---------------------------------------------------------------------------

mtuc_su_assert( ! array_key_exists( 'egn', $actual_product ), 'EGN not in SmartUCF payload' );
mtuc_su_assert( ! array_key_exists( 'clientEgn', $actual_product ), 'no clientEgn field' );
mtuc_su_assert( ! array_key_exists( 'phone2', $actual_product ), 'phone2 not in SmartUCF payload' );
mtuc_su_assert( false === strpos( wp_json_encode( $actual_product ), '8001010000' ), 'fake EGN not serialized in payload' );

// ---------------------------------------------------------------------------
// Retry determinism
// ---------------------------------------------------------------------------

$retry = mtuc_build_cart_smartucf_session_payload( $order, $customer, $calculation, $shop );
mtuc_su_assert_same( $actual_cart, $retry, 'retry construction is deterministic' );

// ---------------------------------------------------------------------------
// No lifecycle / transport / certificate in builder
// ---------------------------------------------------------------------------

$save_before = $order->save_count;
$status_before = $order->status;
$bank_before = $order->bank_status_meta;
mtuc_build_cart_smartucf_session_payload( $order, $customer, $calculation, $shop );
mtuc_su_assert( $save_before === $order->save_count, 'builder must not save order' );
mtuc_su_assert( $status_before === $order->status, 'builder must not mutate native Woo status' );
mtuc_su_assert( $bank_before === $order->bank_status_meta, 'builder must not mutate bank status' );

$builder_src = '' !== $payload_src ? $payload_src : $popup_order_src;
// When still in popup-order pre-extraction, inspect only builder-related function names via source scan of payload after move.
if ( '' !== $payload_src ) {
	mtuc_su_assert(
		false === strpos( $payload_src, 'Mtuc_Smartucf_Api_Client' )
		&& false === strpos( $payload_src, 'start_session' )
		&& false === strpos( $payload_src, 'curl_' )
		&& false === strpos( $payload_src, 'wp_remote_' ),
		'payload module must not invoke SmartUCF transport'
	);
	mtuc_su_assert(
		false === strpos( $payload_src, 'mtuc_get_smartucf_key_password' )
		&& false === strpos( $payload_src, 'Mtuc_Certificate' )
		&& false === strpos( $payload_src, 'secrets/' )
		&& false === strpos( $payload_src, 'openssl_' ),
		'payload module must not access certificates/secrets'
	);
	mtuc_su_assert(
		false === strpos( $payload_src, 'mtuc_record_order_bank_status' )
		&& false === strpos( $payload_src, 'mtuc_fail_order_on_smartucf' )
		&& false === strpos( $payload_src, 'update_meta_data' ),
		'payload module must not mutate lifecycle'
	);
	mtuc_su_assert(
		false !== strpos( $bootstrap_src, '/mtuc-smartucf-payload.php' ),
		'plugin loads mtuc-smartucf-payload.php'
	);
	mtuc_su_assert(
		false !== strpos( $payload_src, 'function mtuc_build_smartucf_session_payload' )
		&& false !== strpos( $payload_src, 'function mtuc_build_cart_smartucf_session_payload' ),
		'payload module owns both SmartUCF builders'
	);
	mtuc_su_assert(
		false === strpos( $popup_order_src, 'function mtuc_build_smartucf_session_payload' )
		&& false === strpos( $popup_order_src, 'function mtuc_build_cart_smartucf_session_payload' )
		&& false === strpos( $popup_order_src, 'function mtuc_build_smartucf_items_from_order' ),
		'popup-order no longer defines SmartUCF payload builders'
	);
	mtuc_su_assert(
		false !== strpos( $popup_order_src, 'function mtuc_send_popup_order_to_smartucf' )
		&& false !== strpos( $popup_order_src, 'function mtuc_send_cart_popup_order_to_smartucf' ),
		'send/transport wrappers remain in popup-order'
	);
} else {
	mtuc_su_assert(
		false !== strpos( $popup_order_src, 'function mtuc_build_smartucf_session_payload' ),
		'pre-extraction builders still in popup-order'
	);
}

mtuc_su_assert(
	false !== strpos( $client_src, 'function start_session' ) || false !== strpos( $client_src, 'public static function start_session' ),
	'transport remains in SmartUCF API client'
);
mtuc_su_assert(
	false !== strpos( $secrets_src, 'function mtuc_get_smartucf_key_password' ),
	'secret resolution remains in mtuc-secrets.php'
);

// Process 2 does not get a SmartUCF builder variant — same payload shape if called; sequencing stays outside.
if ( '' !== $payload_src ) {
	mtuc_su_assert(
		false === strpos( $payload_src, 'bank_sent_process1' )
		&& false === strpos( $payload_src, 'MTUC_BANK_STATUS' ),
		'payload construction does not encode bank_sent_process1'
	);
} else {
	mtuc_su_assert(
		! array_key_exists( 'status', $actual_product )
		&& ! array_key_exists( 'bank_status', $actual_product ),
		'payload values do not claim bank status fields'
	);
}

fwrite( STDOUT, 'OK smartucf-payload ' . $mtuc_su_assert_count . " assertions\n" );
