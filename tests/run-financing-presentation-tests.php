<?php
/**
 * Characterization tests for audience financing presentation (AUD-WOO-016 Step 4).
 *
 * Run: php tests/run-financing-presentation-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_fp_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_fp_assert( bool $ok, string $message ): void {
	global $mtuc_fp_assert_count;
	++$mtuc_fp_assert_count;
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

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * @param string $text Text.
	 * @return string
	 */
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal order stand-in.
	 */
	class WC_Order {
		/** @var int */
		public $id = 42;
		/** @var array<string, mixed> */
		public $meta = array();

		public function get_id(): int {
			return $this->id;
		}

		public function get_order_number(): string {
			return (string) $this->id;
		}

		public function get_payment_method(): string {
			return 'mtunicredit';
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
	}
}

if ( ! function_exists( 'mtuc_get_process2_confirmation_message' ) ) {
	/**
	 * @return string
	 */
	function mtuc_get_process2_confirmation_message(): string {
		return 'Process 2 confirmation';
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-popup-order.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-financing-presentation.php';

$fake_egn = '9001011234';

/**
 * @param WC_Order $order Order.
 * @return void
 */
function mtuc_fp_seed_process2_order( WC_Order $order ): void {
	global $fake_egn;
	$order->update_meta_data( MTUC_ORDER_META_PROCESS2, 1 );
	$order->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS2 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'months', 12 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'kop_code', 'CAT' );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'egn', $fake_egn );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'phone2', '0888123456' );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'parva', 50.5 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'loan_amount', 949.5 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'monthly_installment', 90.12 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'total_payable', 1081.44 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'glp', 1.25 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'gpr', 2.5 );
	$order->update_meta_data( MTUC_ORDER_META_PREFIX . 'cp_order_id', 777 );
}

// ---------------------------------------------------------------------------
// Audience helpers
// ---------------------------------------------------------------------------

mtuc_fp_assert( ! mtuc_credit_rows_audience_includes_egn( MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER ), 'customer excludes EGN' );
mtuc_fp_assert( ! mtuc_credit_rows_audience_includes_egn( MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL ), 'admin panel excludes EGN' );
mtuc_fp_assert( mtuc_credit_rows_audience_includes_egn( MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL ), 'admin email allows EGN' );

// ---------------------------------------------------------------------------
// Process 2 — audience matrix
// ---------------------------------------------------------------------------

$p2 = new WC_Order();
mtuc_fp_seed_process2_order( $p2 );

$admin_email = mtuc_get_order_credit_meta_rows( $p2, MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL );
mtuc_fp_assert( isset( $admin_email['ЕГН'] ) && $fake_egn === $admin_email['ЕГН'], 'P2 admin email presents EGN' );
mtuc_fp_assert( isset( $admin_email['Втори телефон'] ) && '0888123456' === $admin_email['Втори телефон'], 'P2 admin email presents phone2' );

$admin_panel = mtuc_get_order_credit_meta_rows( $p2, MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL );
mtuc_fp_assert( ! isset( $admin_panel['ЕГН'] ), 'P2 admin panel hides EGN' );
mtuc_fp_assert( false === strpos( wp_json_encode( $admin_panel ), $fake_egn ), 'P2 admin panel does not embed EGN' );
mtuc_fp_assert( isset( $admin_panel['Втори телефон'] ), 'P2 admin panel keeps phone2' );
mtuc_fp_assert( ! isset( mtuc_get_admin_order_credit_meta_rows( $p2 )['ЕГН'] ), 'admin helper matches ADMIN_PANEL' );

$customer = mtuc_get_order_credit_meta_rows( $p2, MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER );
mtuc_fp_assert( ! isset( $customer['ЕГН'] ), 'P2 customer hides EGN (Thank You / customer email)' );
mtuc_fp_assert( ! isset( $customer['Втори телефон'] ), 'P2 customer hides phone2' );
mtuc_fp_assert( false === strpos( wp_json_encode( $customer ), $fake_egn ), 'P2 customer does not embed EGN' );
mtuc_fp_assert( isset( $customer['Съобщение'] ), 'P2 customer keeps confirmation message' );

// ---------------------------------------------------------------------------
// Presentation parity — non-EGN fields + order
// ---------------------------------------------------------------------------

mtuc_fp_assert( isset( $customer['Статус към банката'] ), 'status row present' );
mtuc_fp_assert( isset( $customer['КП поръчка (ID)'] ) && '777' === $customer['КП поръчка (ID)'], 'CP order id present' );
mtuc_fp_assert( isset( $customer['КП shop order_id'] ) && '42' === $customer['КП shop order_id'], 'CP shop order_id present' );
mtuc_fp_assert( isset( $customer['Срок (месеци)'] ) && '12' === $customer['Срок (месеци)'], 'months present' );
mtuc_fp_assert( isset( $customer['КОП'] ) && 'CAT' === $customer['КОП'], 'KOP present' );
mtuc_fp_assert( '50.50' === $customer['Първоначална вноска'], 'parva formatted' );
mtuc_fp_assert( '949.50' === $customer['Сума на заема'], 'loan formatted' );
mtuc_fp_assert( '90.12' === $customer['Месечна вноска'], 'monthly formatted' );
mtuc_fp_assert( '1081.44' === $customer['Обща дължима сума'], 'total formatted' );
mtuc_fp_assert( '1.25% / 2.50%' === $customer['ГЛП / ГПР'], 'GLP/GPR formatted' );

$labels = array_keys( $customer );
mtuc_fp_assert(
	array_search( 'Срок (месеци)', $labels, true ) < array_search( 'КОП', $labels, true )
	&& array_search( 'КОП', $labels, true ) < array_search( 'Първоначална вноска', $labels, true )
	&& array_search( 'Първоначална вноска', $labels, true ) < array_search( 'Сума на заема', $labels, true )
	&& array_search( 'Месечна вноска', $labels, true ) < array_search( 'Обща дължима сума', $labels, true )
	&& array_search( 'ГЛП / ГПР', $labels, true ) < array_search( 'Съобщение', $labels, true ),
	'customer row ordering preserved'
);

$admin_email_labels = array_keys( $admin_email );
$egn_pos            = array_search( 'ЕГН', $admin_email_labels, true );
$phone_pos          = array_search( 'Втори телефон', $admin_email_labels, true );
$msg_pos            = array_search( 'Съобщение', $admin_email_labels, true );
mtuc_fp_assert(
	false !== $egn_pos
	&& false !== $phone_pos
	&& $egn_pos < $phone_pos
	&& $phone_pos < $msg_pos,
	'admin email Process 2 field order: EGN → phone2 → message'
);

// ---------------------------------------------------------------------------
// Process 1 — EGN absent for all audiences
// ---------------------------------------------------------------------------

$p1 = new WC_Order();
$p1->update_meta_data( MTUC_ORDER_META_PROCESS2, 0 );
$p1->update_meta_data( MTUC_ORDER_META_BANK_STATUS, MTUC_BANK_STATUS_SENT_PROCESS1 );
$p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'months', 12 );
$p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'egn', $fake_egn );
$p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'loan_amount', 500 );
$p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'monthly_installment', 45 );
$p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'total_payable', 540 );
$p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'parva', 0 );
$p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'glp', 0 );
$p1->update_meta_data( MTUC_ORDER_META_PREFIX . 'gpr', 0 );

foreach (
	array(
		MTUC_CREDIT_ROWS_AUDIENCE_CUSTOMER,
		MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_PANEL,
		MTUC_CREDIT_ROWS_AUDIENCE_ADMIN_EMAIL,
	) as $audience
) {
	$rows = mtuc_get_order_credit_meta_rows( $p1, $audience );
	mtuc_fp_assert( ! isset( $rows['ЕГН'] ), 'P1 ' . $audience . ' hides EGN' );
	mtuc_fp_assert( false === strpos( wp_json_encode( $rows ), $fake_egn ), 'P1 ' . $audience . ' does not embed EGN' );
	mtuc_fp_assert( ! isset( $rows['Съобщение'] ), 'P1 ' . $audience . ' has no Process 2 message' );
}

// ---------------------------------------------------------------------------
// Invalid audience falls back to customer (no EGN)
// ---------------------------------------------------------------------------

$fallback = mtuc_get_order_credit_meta_rows( $p2, 'unknown_audience' );
mtuc_fp_assert( ! isset( $fallback['ЕГН'] ), 'invalid audience falls back to customer privacy' );
mtuc_fp_assert( ! isset( $fallback['Втори телефон'] ), 'invalid audience omits phone2 like customer' );

fwrite( STDOUT, 'OK financing-presentation ' . $mtuc_fp_assert_count . " assertions\n" );
