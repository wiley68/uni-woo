<?php
/**
 * Characterization / golden contract tests for Woo → CP order payloads (AUD-WOO-016 Step 10).
 *
 * Run: php tests/run-cp-order-payload-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_cp_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_cp_assert( bool $ok, string $message ): void {
	global $mtuc_cp_assert_count;
	++$mtuc_cp_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

/**
 * Strict array equality (types + keys).
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $message  Failure message.
 * @return void
 */
function mtuc_cp_assert_same( $expected, $actual, string $message ): void {
	mtuc_cp_assert( $expected === $actual, $message . ' | expected=' . var_export( $expected, true ) . ' actual=' . var_export( $actual, true ) );
}

if ( ! defined( 'MTUC_VERSION' ) ) {
	define( 'MTUC_VERSION', '2.0.1' );
}

if ( ! function_exists( 'get_woocommerce_currency' ) ) {
	/**
	 * @return string
	 */
	function get_woocommerce_currency() {
		return $GLOBALS['mtuc_test_wc_currency'] ?? 'BGN';
	}
}

if ( ! function_exists( 'wp_is_mobile' ) ) {
	/**
	 * @return bool
	 */
	function wp_is_mobile() {
		return ! empty( $GLOBALS['mtuc_test_is_mobile'] );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	/**
	 * @return int
	 */
	function get_current_user_id() {
		return (int) ( $GLOBALS['mtuc_test_user_id'] ?? 0 );
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

if ( ! function_exists( 'mtuc_join_address_parts' ) ) {
	/**
	 * @param string[] $parts Parts.
	 * @return string
	 */
	function mtuc_join_address_parts( array $parts ): string {
		$parts = array_values(
			array_filter(
				array_map(
					static function ( $part ) {
						return trim( (string) $part );
					},
					$parts
				),
				static function ( $part ) {
					return '' !== $part;
				}
			)
		);
		$formatted = implode( ', ', $parts );
		if ( strlen( $formatted ) > 256 ) {
			$formatted = substr( $formatted, 0, 256 );
		}
		return $formatted;
	}
}

if ( ! function_exists( 'mtuc_get_popup_shipping_address_for_cp' ) ) {
	/**
	 * @param int $user_id User ID.
	 * @return string
	 */
	function mtuc_get_popup_shipping_address_for_cp( int $user_id = 0 ): string {
		unset( $user_id );
		return (string) ( $GLOBALS['mtuc_test_cp_shipping'] ?? '' );
	}
}

if ( ! function_exists( 'mtuc_get_catalog_product_id' ) ) {
	/**
	 * @param WC_Product $product Product.
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
	 * @param WC_Product $product Product.
	 * @return int
	 */
	function mtuc_get_product_variation_id( $product ): int {
		return $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;
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

		public function get_product() {
			return $this->product;
		}

		public function get_quantity(): int {
			return $this->quantity;
		}
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Test order with totals/items/meta.
	 */
	class WC_Order {
		/** @var int */
		public $id = 0;
		/** @var string */
		public $order_number = '';
		/** @var float */
		public $total = 0.0;
		/** @var string */
		public $currency = 'BGN';
		/** @var array<string, mixed> */
		public $meta = array();
		/** @var list<WC_Order_Item_Product> */
		public $items = array();
		/** @var int */
		public $save_count = 0;
		/** @var string */
		public $bank_status_meta = '';

		public function __construct( int $id = 0 ) {
			$this->id           = $id;
			$this->order_number = (string) $id;
		}

		public function get_id(): int {
			return $this->id;
		}

		public function get_order_number(): string {
			return $this->order_number;
		}

		public function get_total(): float {
			return $this->total;
		}

		public function get_currency(): string {
			return $this->currency;
		}

		/**
		 * @param string $type Item type.
		 * @return list<WC_Order_Item_Product>
		 */
		public function get_items( $type = '' ) {
			unset( $type );
			return $this->items;
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

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financial-integrity.php';

// Minimal stubs so popup-order.php can load without full WP/WC bootstrap.
foreach (
	array(
		'esc_url_raw' => static function ( $url ) {
			return (string) $url;
		},
		'esc_html'    => static function ( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		},
		'esc_url'     => static function ( $url ) {
			return (string) $url;
		},
		'esc_attr'    => static function ( $text ) {
			return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
		},
		'wp_kses_post' => static function ( $text ) {
			return (string) $text;
		},
		'add_action'  => static function () {
			return true;
		},
		'add_filter'  => static function () {
			return true;
		},
		'sanitize_key' => static function ( $key ) {
			$key = strtolower( (string) $key );
			return preg_replace( '/[^a-z0-9_\-]/', '', $key );
		},
		'sanitize_text_field' => static function ( $str ) {
			return trim( strip_tags( (string) $str ) );
		},
		'__' => static function ( $text, $domain = 'default' ) {
			unset( $domain );
			return $text;
		},
	) as $fn => $cb
) {
	if ( ! function_exists( $fn ) ) {
		eval( 'function ' . $fn . '( ...$args ) { $cb = $GLOBALS["mtuc_cp_stub_cbs"][' . var_export( $fn, true ) . ']; return $cb( ...$args ); }' );
		$GLOBALS['mtuc_cp_stub_cbs'][ $fn ] = $cb;
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
if ( is_readable( MTUC_PLUGIN_DIR . '/includes/mtuc-cp-order-payload.php' ) ) {
	require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cp-order-payload.php';
}

$payload_src     = is_readable( MTUC_PLUGIN_DIR . '/includes/mtuc-cp-order-payload.php' )
	? (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-cp-order-payload.php' )
	: '';
$popup_order_src = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php' );
$bootstrap_src   = (string) file_get_contents( MTUC_PLUGIN_DIR . '/mtunicredit.php' );
$smart_src       = (string) file_get_contents( MTUC_PLUGIN_DIR . '/includes/class-mtuc-smartucf-api-client.php' );

/**
 * @return array{order:WC_Order,customer:array<string,string>,calculation:array<string,mixed>,product:WC_Product}
 */
function mtuc_cp_fixture_product_context( int $order_id = 874 ): array {
	$order                  = new WC_Order( $order_id );
	$order->total           = 1234.56; // products+shipping+fee+tax-discount fixture total.
	$order->currency        = 'BGN';
	$order->meta[ MTUC_ORDER_META_CP_SHOP_ORDER_ID ] = (string) $order_id;

	$product       = new WC_Product();
	$product->id   = 42;
	$product->name = 'Тестов продукт_с_долна_черта';

	$customer = array(
		'first_name' => 'Иван',
		'last_name'  => 'Петров',
		'phone'      => '0888123456',
		'email'      => 'ivan@example.com',
		'address'    => 'ул. Тестова 1',
		// Fake EGN for privacy tests — must NOT appear in CP payload.
		'egn'        => '8001010000',
	);

	$calculation = array(
		'monthly_installment' => 85.5,
		'gpr'                 => 12.34,
		'months'              => 12,
		'parva'               => 10.0,
	);

	return array(
		'order'        => $order,
		'customer'     => $customer,
		'calculation'  => $calculation,
		'product'      => $product,
	);
}

$GLOBALS['mtuc_test_is_mobile']   = false;
$GLOBALS['mtuc_test_cp_shipping'] = '';
$GLOBALS['mtuc_test_user_id']     = 0;

$fx = mtuc_cp_fixture_product_context( 874 );
$shop_p1 = array(
	'uni_proces' => 0,
	'uni_eur'    => 0,
);
$shop_p2 = array(
	'uni_proces' => 1,
	'uni_eur'    => 0,
);

// ---------------------------------------------------------------------------
// Golden Process 1 product-popup payload
// ---------------------------------------------------------------------------

$actual_p1 = mtuc_build_cp_order_payload(
	$fx['order'],
	$fx['customer'],
	$fx['calculation'],
	$fx['product'],
	42,
	0,
	2,
	$shop_p1
);

$expected_p1 = array(
	'order_id'      => '874',
	'name'          => 'Иван Петров',
	'phone'         => '0888123456',
	'email'         => 'ivan@example.com',
	'address'       => 'ул. Тестова 1',
	'address2'      => 'ул. Тестова 1',
	'price'         => 1234.56,
	'vnoska'        => 85.5,
	'gpr'           => 12.34,
	'vnoski'        => 12,
	'parva'         => 10.0,
	'products_id'   => '42',
	'products_name' => 'Тестов продукт-с-долна-черта',
	'products_q'    => '2',
	'type_client'   => 1,
	'currency'      => 'BGN',
	'version'       => '2.0.1',
);

mtuc_cp_assert_same( $expected_p1, $actual_p1, 'Process 1 product CP payload golden contract' );
mtuc_cp_assert( ! array_key_exists( 'status', $actual_p1 ), 'P1 omits status' );
mtuc_cp_assert( ! array_key_exists( 'status_id', $actual_p1 ), 'P1 omits status_id' );
mtuc_cp_assert( ! array_key_exists( 'egn', $actual_p1 ), 'P1 must not include EGN' );

// ---------------------------------------------------------------------------
// Golden Process 2 product-popup payload (status fields differ)
// ---------------------------------------------------------------------------

$actual_p2 = mtuc_build_cp_order_payload(
	$fx['order'],
	$fx['customer'],
	$fx['calculation'],
	$fx['product'],
	42,
	0,
	2,
	$shop_p2
);

$expected_p2 = $expected_p1;
$expected_p2['status']    = 'Изпратен Банка - Процес 2';
$expected_p2['status_id'] = 'bank_sent_process2';

mtuc_cp_assert_same( $expected_p2, $actual_p2, 'Process 2 product CP payload golden contract' );
mtuc_cp_assert( ! array_key_exists( 'egn', $actual_p2 ), 'P2 CP payload must not include EGN' );

// ---------------------------------------------------------------------------
// Numeric CP identity + no W/base36
// ---------------------------------------------------------------------------

mtuc_cp_assert( '874' === $actual_p1['order_id'], 'Woo ID 874 → string "874"' );
mtuc_cp_assert( 1 !== preg_match( '/^W/', (string) $actual_p1['order_id'] ), 'no W-prefix generation' );
mtuc_cp_assert( false === strpos( (string) $actual_p1['order_id'], 'OA' ), 'no base36-style OA suffix' );

$legacy = new WC_Order( 875 );
$legacy->total = 10.0;
$legacy->currency = 'BGN';
$legacy->meta[ MTUC_ORDER_META_CP_SHOP_ORDER_ID ] = 'W0000000000OA';
$legacy_payload = mtuc_build_cp_order_payload(
	$legacy,
	$fx['customer'],
	$fx['calculation'],
	$fx['product'],
	42,
	0,
	1,
	$shop_p1
);
mtuc_cp_assert( 'W0000000000OA' === $legacy_payload['order_id'], 'legacy persisted W-format preserved' );

// ---------------------------------------------------------------------------
// Retry identity stability
// ---------------------------------------------------------------------------

$retry = mtuc_build_cp_order_payload(
	$fx['order'],
	$fx['customer'],
	$fx['calculation'],
	$fx['product'],
	42,
	0,
	2,
	$shop_p1
);
mtuc_cp_assert_same( $actual_p1, $retry, 'retry construction is deterministic / same identity' );
mtuc_cp_assert( $actual_p1['order_id'] === $retry['order_id'], 'retry uses same CP order_id' );

// ---------------------------------------------------------------------------
// Canonical full order amount (not subtotal / browser)
// ---------------------------------------------------------------------------

mtuc_cp_assert( 1234.56 === $actual_p1['price'], 'price uses full order total fixture' );

// Inspect payload module source (builders live only in mtuc-cp-order-payload.php).
$builder_fn_src = '' !== $payload_src ? $payload_src : $popup_order_src;
mtuc_cp_assert(
	false === strpos( $builder_fn_src, "\$_POST" )
	&& false === strpos( $builder_fn_src, '$_REQUEST' )
	&& false === strpos( $builder_fn_src, 'line_price' ),
	'builders do not read browser request price fields'
);
mtuc_cp_assert(
	false !== strpos( $builder_fn_src, 'mtuc_get_order_total_inc_tax' ),
	'builders use authoritative order total helper'
);

// ---------------------------------------------------------------------------
// Currency BGN / EUR
// ---------------------------------------------------------------------------

$fx['order']->currency = 'EUR';
$shop_eur = array(
	'uni_proces' => 0,
	'uni_eur'    => 3,
);
$eur_payload = mtuc_build_cp_order_payload(
	$fx['order'],
	$fx['customer'],
	$fx['calculation'],
	$fx['product'],
	42,
	0,
	1,
	$shop_eur
);
mtuc_cp_assert( 'EUR' === $eur_payload['currency'], 'EUR transaction currency preserved' );

$fx['order']->currency = 'BGN';
$mismatch_shop = array(
	'uni_proces' => 0,
	'uni_eur'    => 3, // expects EUR
);
$mismatch = mtuc_build_cp_order_payload(
	$fx['order'],
	$fx['customer'],
	$fx['calculation'],
	$fx['product'],
	42,
	0,
	1,
	$mismatch_shop
);
// Current behavior: on mismatch, get_cp_order_currency falls back to expected bank currency.
mtuc_cp_assert( 'EUR' === $mismatch['currency'], 'mismatch path uses expected bank currency fallback' );

// ---------------------------------------------------------------------------
// Cart / multi-line popup payload (checkout + cart share this builder)
// ---------------------------------------------------------------------------

$cart_order = new WC_Order( 900);
$cart_order->total    = 250.0;
$cart_order->currency = 'BGN';
$cart_order->meta[ MTUC_ORDER_META_CP_SHOP_ORDER_ID ] = '900';

$p_a       = new WC_Product();
$p_a->id   = 10;
$p_a->name = 'A_one';
$item_a            = new WC_Order_Item_Product();
$item_a->product   = $p_a;
$item_a->quantity  = 1;

$p_b       = new WC_Product();
$p_b->id   = 20;
$p_b->name = 'B_two';
$item_b            = new WC_Order_Item_Product();
$item_b->product   = $p_b;
$item_b->quantity  = 3;

$cart_order->items = array( $item_a, $item_b );

$GLOBALS['mtuc_test_cp_shipping'] = 'София, 1000';

$cart_payload = mtuc_build_cp_cart_order_payload(
	$cart_order,
	$fx['customer'],
	$fx['calculation'],
	$shop_p1
);

$expected_cart = array(
	'order_id'      => '900',
	'name'          => 'Иван Петров',
	'phone'         => '0888123456',
	'email'         => 'ivan@example.com',
	'address'       => 'ул. Тестова 1',
	'address2'      => 'София, 1000',
	'price'         => 250.0,
	'vnoska'        => 85.5,
	'gpr'           => 12.34,
	'vnoski'        => 12,
	'parva'         => 10.0,
	'products_id'   => '10_20',
	'products_name' => 'A-one_B-two',
	'products_q'    => '1_3',
	'type_client'   => 1,
	'currency'      => 'BGN',
	'version'       => '2.0.1',
);

mtuc_cp_assert_same( $expected_cart, $cart_payload, 'cart/checkout multi-line CP payload golden contract' );

$cart_p2 = mtuc_build_cp_cart_order_payload(
	$cart_order,
	$fx['customer'],
	$fx['calculation'],
	$shop_p2
);
mtuc_cp_assert( isset( $cart_p2['status_id'] ) && 'bank_sent_process2' === $cart_p2['status_id'], 'cart P2 includes status_id' );
mtuc_cp_assert( ! isset( $cart_payload['status_id'] ), 'cart P1 omits status_id' );

// ---------------------------------------------------------------------------
// Missing optional / empty shipping → address2 mirrors address or '-'
// ---------------------------------------------------------------------------

$GLOBALS['mtuc_test_cp_shipping'] = '';
$guest = mtuc_build_cp_order_payload(
	$fx['order'],
	array(
		'first_name' => 'Гост',
		'last_name'  => '',
		'phone'      => '0888000000',
		'email'      => 'guest@example.com',
		'address'    => 'Адрес 5',
	),
	$fx['calculation'],
	$fx['product'],
	42,
	0,
	1,
	$shop_p1
);
mtuc_cp_assert( 'Гост' === $guest['name'], 'guest name trim without last name' );
mtuc_cp_assert( 'Адрес 5' === $guest['address2'], 'empty shipping copies address into address2' );

$empty_addr_customer = array(
	'first_name' => 'X',
	'last_name'  => 'Y',
	'phone'      => '0888000001',
	'email'      => 'x@example.com',
	'address'    => '',
);
$dash = mtuc_build_cp_order_payload(
	$fx['order'],
	$empty_addr_customer,
	$fx['calculation'],
	$fx['product'],
	42,
	0,
	1,
	$shop_p1
);
mtuc_cp_assert( '-' === $dash['address2'], 'empty address2 becomes hyphen for CP NOT NULL' );

// ---------------------------------------------------------------------------
// Process distinction helper
// ---------------------------------------------------------------------------

mtuc_cp_assert( null === mtuc_get_cp_order_create_status_payload( $shop_p1 ), 'create status null for P1' );
$p2_status = mtuc_get_cp_order_create_status_payload( $shop_p2 );
mtuc_cp_assert( is_array( $p2_status ) && 'bank_sent_process2' === $p2_status['status_id'], 'create status P2' );

// ---------------------------------------------------------------------------
// No SmartUCF / no lifecycle mutation from builders
// ---------------------------------------------------------------------------

$save_before = $fx['order']->save_count;
$bank_before = $fx['order']->bank_status_meta;
mtuc_build_cp_order_payload(
	$fx['order'],
	$fx['customer'],
	$fx['calculation'],
	$fx['product'],
	42,
	0,
	1,
	$shop_p2
);
mtuc_cp_assert( $save_before === $fx['order']->save_count, 'building payload must not save order' );
mtuc_cp_assert( $bank_before === $fx['order']->bank_status_meta, 'building payload must not mutate bank status' );

$builder_module_src = $payload_src;
if ( '' === $builder_module_src ) {
	// Pre-extraction: inspect only the two builder function bodies.
	$builder_module_src = $builder_fn_src;
}
mtuc_cp_assert(
	false === strpos( $builder_module_src, 'Mtuc_Smartucf_Api_Client' )
	&& false === strpos( $builder_module_src, 'mtuc_send_cart_popup_order_to_smartucf' )
	&& false === strpos( $builder_module_src, 'mtuc_build_cart_smartucf_session_payload' )
	&& false === strpos( $builder_module_src, 'start_session' ),
	'CP payload builder source must not call SmartUCF'
);
mtuc_cp_assert(
	false === strpos( $builder_module_src, 'Mtuc_Cp_Api_Client::create_order' )
	&& false === strpos( $builder_module_src, 'mtuc_record_order_bank_status' )
	&& false === strpos( $builder_module_src, 'mtuc_create_cp_order_with_recovery' ),
	'CP payload builder must not transport or mutate lifecycle'
);

// Ownership after extraction (soft until file exists; hard once present).
if ( '' !== $payload_src ) {
	mtuc_cp_assert(
		false !== strpos( $bootstrap_src, '/mtuc-cp-order-payload.php' ),
		'plugin loads mtuc-cp-order-payload.php'
	);
	mtuc_cp_assert(
		false !== strpos( $payload_src, 'function mtuc_build_cp_order_payload' )
		&& false !== strpos( $payload_src, 'function mtuc_build_cp_cart_order_payload' )
		&& false !== strpos( $payload_src, 'function mtuc_assemble_cp_order_payload' ),
		'payload module owns both create builders and shared assemble'
	);
	mtuc_cp_assert(
		false === strpos( $popup_order_src, 'function mtuc_build_cp_order_payload' )
		&& false === strpos( $popup_order_src, 'function mtuc_build_cp_cart_order_payload' ),
		'popup-order no longer defines CP create builders'
	);
	mtuc_cp_assert(
		false !== strpos( $popup_order_src, 'function mtuc_create_cp_order_with_recovery' )
		&& false !== strpos( $popup_order_src, 'function mtuc_send_popup_order_to_cp' )
		&& false !== strpos( $popup_order_src, 'function mtuc_send_cart_popup_order_to_cp' ),
		'transport/lifecycle send helpers remain in popup-order'
	);
}

fwrite( STDOUT, 'OK cp-order-payload ' . $mtuc_cp_assert_count . " assertions\n" );
