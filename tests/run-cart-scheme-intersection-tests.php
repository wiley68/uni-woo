<?php
/**
 * Characterization tests for cart scheme intersection (AUD-WOO-016 Step 3).
 *
 * Run: php tests/run-cart-scheme-intersection-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

$mtuc_csi_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_csi_assert( bool $ok, string $message ): void {
	global $mtuc_csi_assert_count;
	++$mtuc_csi_assert_count;
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

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-product-offer-selection.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-product-popup.php';
require_once MTUC_PLUGIN_DIR . '/includes/mtuc-cart-scheme-intersection.php';

// ---------------------------------------------------------------------------
// Match key identity (filter_id excluded)
// ---------------------------------------------------------------------------

mtuc_csi_assert(
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

mtuc_csi_assert(
	'promo|PRO|6' === mtuc_build_cart_scheme_match_key(
		array(
			'scheme_type' => 'promo',
			'kop_code'    => ' PRO ',
			'months'      => 6,
		)
	),
	'match key trims kop and keeps scheme type'
);

mtuc_csi_assert(
	'standard||0' === mtuc_build_cart_scheme_match_key( array() ),
	'match key defaults for empty option'
);

// ---------------------------------------------------------------------------
// Single product — all eligible schemes survive
// ---------------------------------------------------------------------------

$single = mtuc_intersect_cart_scheme_options(
	array(
		array(
			mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 24, 1, 'CAT', '', 'standard' ),
		),
	)
);
mtuc_csi_assert( 2 === count( $single ), 'single product keeps all schemes' );
mtuc_csi_assert( 12 === (int) $single[0]['months'], 'single product sorted by months asc' );
mtuc_csi_assert( 24 === (int) $single[1]['months'], 'single product second month 24' );

// ---------------------------------------------------------------------------
// Two products with common scheme
// ---------------------------------------------------------------------------

$common = mtuc_intersect_cart_scheme_options(
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
mtuc_csi_assert( 1 === count( $common ), 'only shared type/KOP/month survives' );
mtuc_csi_assert( 12 === (int) $common[0]['months'], 'shared month is 12' );
mtuc_csi_assert( 1 === (int) $common[0]['filter_id'], 'first-line filter metadata retained' );

// ---------------------------------------------------------------------------
// Different filter IDs — current behavior intersects
// ---------------------------------------------------------------------------

$diff_filter = mtuc_intersect_cart_scheme_options(
	array(
		array( mtuc_build_popup_scheme_option_row( 12, 31, 'CAT', '', 'standard' ) ),
		array( mtuc_build_popup_scheme_option_row( 12, 32, 'CAT', '', 'standard' ) ),
	)
);
mtuc_csi_assert( 1 === count( $diff_filter ), 'different filter IDs with same KOP/month still intersect' );
mtuc_csi_assert( 31 === (int) $diff_filter[0]['filter_id'], 'first-line filter retained on intersect' );

// ---------------------------------------------------------------------------
// No common / different KOP / different months
// ---------------------------------------------------------------------------

mtuc_csi_assert(
	array() === mtuc_intersect_cart_scheme_options(
		array(
			array( mtuc_build_popup_scheme_option_row( 12, 1, 'AAA', '', 'standard' ) ),
			array( mtuc_build_popup_scheme_option_row( 12, 2, 'BBB', '', 'standard' ) ),
		)
	),
	'different KOP codes do not intersect'
);

mtuc_csi_assert(
	array() === mtuc_intersect_cart_scheme_options(
		array(
			array( mtuc_build_popup_scheme_option_row( 6, 1, 'CAT', '', 'standard' ) ),
			array( mtuc_build_popup_scheme_option_row( 12, 2, 'CAT', '', 'standard' ) ),
		)
	),
	'different months do not intersect'
);

mtuc_csi_assert(
	array() === mtuc_intersect_cart_scheme_options(
		array(
			array(
				mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
				mtuc_build_popup_scheme_option_row( 24, 1, 'CAT', '', 'standard' ),
			),
			array(),
		)
	),
	'empty line options clear intersection'
);

mtuc_csi_assert(
	array() === mtuc_intersect_cart_scheme_options( array() ),
	'empty line sets return empty'
);

// ---------------------------------------------------------------------------
// Standard vs promo — not merged by intersection (separate type identity)
// ---------------------------------------------------------------------------

$std_vs_promo = mtuc_intersect_cart_scheme_options(
	array(
		array( mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ) ),
		array( mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'promo' ) ),
	)
);
mtuc_csi_assert( array() === $std_vs_promo, 'standard and promo with same KOP/month do not intersect' );

// ---------------------------------------------------------------------------
// Duplicate options on a line — first-line duplicates retained (current behavior)
// ---------------------------------------------------------------------------

$dupes = mtuc_intersect_cart_scheme_options(
	array(
		array(
			mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', 'a', 'standard' ),
			mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', 'b', 'standard' ),
			mtuc_build_popup_scheme_option_row( 24, 1, 'CAT', '', 'standard' ),
		),
		array(
			mtuc_build_popup_scheme_option_row( 12, 7, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 24, 7, 'CAT', '', 'standard' ),
		),
	)
);
// Exact intersection keeps every matching row from the running $common (first line),
// so same-key duplicates on line 1 are not collapsed.
mtuc_csi_assert( 3 === count( $dupes ), 'first-line same-key duplicates are retained after intersect' );
$dupe_months = array_map(
	static function ( array $option ): int {
		return (int) $option['months'];
	},
	$dupes
);
mtuc_csi_assert( array( 12, 12, 24 ) === $dupe_months, 'duplicate path preserves months order after sort' );

// ---------------------------------------------------------------------------
// Three or more products — progressive intersection
// ---------------------------------------------------------------------------

$three = mtuc_intersect_cart_scheme_options(
	array(
		array(
			mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 24, 1, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 36, 1, 'CAT', '', 'standard' ),
		),
		array(
			mtuc_build_popup_scheme_option_row( 12, 2, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 24, 2, 'CAT', '', 'standard' ),
		),
		array(
			mtuc_build_popup_scheme_option_row( 12, 3, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 36, 3, 'CAT', '', 'standard' ),
		),
	)
);
mtuc_csi_assert( 1 === count( $three ), 'three-product cart keeps only fully common month' );
mtuc_csi_assert( 12 === (int) $three[0]['months'], 'three-product common month is 12' );

// ---------------------------------------------------------------------------
// Malformed options ignored
// ---------------------------------------------------------------------------

$malformed = mtuc_intersect_cart_scheme_options(
	array(
		array(
			'not-an-array',
			mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
		),
		array(
			null,
			mtuc_build_popup_scheme_option_row( 12, 2, 'CAT', '', 'standard' ),
		),
	)
);
mtuc_csi_assert( 1 === count( $malformed ), 'non-array options are ignored during intersection' );

// ---------------------------------------------------------------------------
// GCD / LCM helpers
// ---------------------------------------------------------------------------

mtuc_csi_assert( 6 === mtuc_gcd_int( 12, 18 ), 'gcd 12,18 = 6' );
mtuc_csi_assert( 1 === mtuc_gcd_int( 0, 0 ), 'gcd 0,0 floors to 1' );
mtuc_csi_assert( 12 === mtuc_lcm_int_list( array( 6, 12 ) ), 'LCM 6,12 = 12' );
mtuc_csi_assert( 24 === mtuc_lcm_int_list( array( 6, 8 ) ), 'LCM 6,8 = 24' );
mtuc_csi_assert( 3 === mtuc_lcm_int_list( array( 0, -3 ) ), 'LCM drops 0; absint(-3) keeps 3' );
mtuc_csi_assert( 0 === mtuc_lcm_int_list( array( 0 ) ), 'LCM only zeros → 0' );
mtuc_csi_assert( 0 === mtuc_lcm_int_list( array() ), 'LCM empty → 0' );

// ---------------------------------------------------------------------------
// LCM expansion branch — does not invent months absent from exact intersection
// ---------------------------------------------------------------------------

$lcm_no_invent = mtuc_intersect_cart_scheme_options(
	array(
		array(
			mtuc_build_popup_scheme_option_row( 6, 1, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
		),
		array(
			mtuc_build_popup_scheme_option_row( 8, 2, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 12, 2, 'CAT', '', 'standard' ),
		),
	)
);
// Exact common is 12. Per-line LCM is lcm(6,12)=12 and lcm(8,12)=24 → overall LCM 24,
// but 24 is not present on both lines, so expansion must not add it.
mtuc_csi_assert( 1 === count( $lcm_no_invent ), 'LCM branch does not invent months missing from a line' );
mtuc_csi_assert( 12 === (int) $lcm_no_invent[0]['months'], 'only exact common month 12 remains' );

$lcm_already_present = mtuc_intersect_cart_scheme_options(
	array(
		array(
			mtuc_build_popup_scheme_option_row( 6, 1, 'CAT', '', 'standard' ),
			mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
		),
		array(
			mtuc_build_popup_scheme_option_row( 12, 2, 'CAT', '', 'standard' ),
		),
	)
);
// Exact common is 12; LCM target is also 12 and already in existing_keys → no duplicate add.
mtuc_csi_assert( 1 === count( $lcm_already_present ), 'LCM skip when target already in exact common' );

// ---------------------------------------------------------------------------
// Checkout unification (AUD-WOO-016 Step 6)
// ---------------------------------------------------------------------------

if ( ! function_exists( 'mtuc_get_shop_coeff_list' ) ) {
	/**
	 * @param array<string, mixed> $shop Shop.
	 * @return array<int, array<string, mixed>>
	 */
	function mtuc_get_shop_coeff_list( array $shop ): array {
		return is_array( $shop['coeff_list'] ?? null ) ? $shop['coeff_list'] : array();
	}
}

if ( ! function_exists( 'mtuc_find_coeff_entry' ) ) {
	/**
	 * @param array<int, array<string, mixed>> $coeff_list Coeff rows.
	 * @param string                           $kop_code   KOP.
	 * @param int                              $months     Months.
	 * @return array<string, mixed>|null
	 */
	function mtuc_find_coeff_entry( array $coeff_list, string $kop_code, int $months ): ?array {
		foreach ( $coeff_list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$code = isset( $entry['onlineProductCode'] ) ? trim( (string) $entry['onlineProductCode'] ) : '';
			$m    = isset( $entry['installmentCount'] ) ? (int) $entry['installmentCount'] : 0;
			if ( $code === $kop_code && $m === $months ) {
				return $entry;
			}
		}

		return null;
	}
}

$std_only = array(
	mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
	mtuc_build_popup_scheme_option_row( 24, 1, 'CAT', '', 'standard' ),
);
$unified_std = mtuc_build_checkout_unified_scheme_options( $std_only, array() );
mtuc_csi_assert( array( '12:1', '24:1' ) === array_column( $unified_std, 'key' ), 'standard-only unification keeps standard set ordered' );

$promo_only = array(
	mtuc_build_popup_scheme_option_row( 12, 2, 'PRO', '', 'promo' ),
	mtuc_build_popup_scheme_option_row( 18, 2, 'PRO', '', 'promo' ),
);
$unified_promo = mtuc_build_checkout_unified_scheme_options( array(), $promo_only );
mtuc_csi_assert( array( 'p:12:2', 'p:18:2' ) === array_column( $unified_promo, 'key' ), 'promo-only unification keeps promo set' );

$mixed = mtuc_build_checkout_unified_scheme_options( $std_only, $promo_only );
mtuc_csi_assert(
	array( '12:1', 'p:12:2', 'p:18:2', '24:1' ) === array_column( $mixed, 'key' ),
	'standard+promo unified and sorted by months/type'
);

// Same KOP/month across standard vs promo — distinct match keys, both kept.
$same_kop_month = mtuc_build_checkout_unified_scheme_options(
	array( mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', 'std', 'standard' ) ),
	array( mtuc_build_popup_scheme_option_row( 12, 9, 'CAT', 'promo', 'promo' ) )
);
mtuc_csi_assert( 2 === count( $same_kop_month ), 'same KOP/month standard+promo both survive (type in match key)' );
mtuc_csi_assert(
	array( '12:1', 'p:12:9' ) === array_column( $same_kop_month, 'key' ),
	'same KOP/month keeps distinct option keys'
);

// Duplicate promo match key against already-seen standard is skipped.
$dup_promo_key = mtuc_build_checkout_unified_scheme_options(
	array( mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'promo' ) ),
	array(
		mtuc_build_popup_scheme_option_row( 12, 99, 'CAT', 'dup', 'promo' ),
		mtuc_build_popup_scheme_option_row( 24, 2, 'CAT', '', 'promo' ),
	)
);
// First list is treated as "standard common" content but typed promo — match key promo|CAT|12
// already seen, so duplicate promo|CAT|12 from second list is skipped.
mtuc_csi_assert( 2 === count( $dup_promo_key ), 'promo duplicate match key skipped during unify' );
mtuc_csi_assert( array( 'p:12:1', 'p:24:2' ) === array_column( $dup_promo_key, 'key' ), 'first-seen promo key retained' );

// Standard-list duplicates are retained (unification does not collapse them).
$std_dupes = mtuc_build_checkout_unified_scheme_options(
	array(
		mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', 'a', 'standard' ),
		mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', 'b', 'standard' ),
	),
	array()
);
mtuc_csi_assert( 2 === count( $std_dupes ), 'standard duplicates retained through unification' );

mtuc_csi_assert(
	array() === mtuc_build_checkout_unified_scheme_options( array(), array() ),
	'both empty → empty unified'
);

mtuc_csi_assert(
	array() === mtuc_resolve_checkout_scheme_common( array() ),
	'resolve from empty cart state → empty'
);

$resolved = mtuc_resolve_checkout_scheme_common(
	array(
		'common_standard' => $std_only,
		'common_promo'    => $promo_only,
	)
);
mtuc_csi_assert( array_column( $mixed, 'key' ) === array_column( $resolved, 'key' ), 'resolve_checkout_scheme_common matches builder' );

// Three-product intersection feeding checkout unification (standard/promo separately).
$std_sets = array(
	array(
		mtuc_build_popup_scheme_option_row( 12, 1, 'CAT', '', 'standard' ),
		mtuc_build_popup_scheme_option_row( 24, 1, 'CAT', '', 'standard' ),
	),
	array(
		mtuc_build_popup_scheme_option_row( 12, 2, 'CAT', '', 'standard' ),
		mtuc_build_popup_scheme_option_row( 36, 2, 'CAT', '', 'standard' ),
	),
	array(
		mtuc_build_popup_scheme_option_row( 12, 3, 'CAT', '', 'standard' ),
	),
);
$promo_sets = array(
	array( mtuc_build_popup_scheme_option_row( 12, 1, 'PRO', '', 'promo' ) ),
	array( mtuc_build_popup_scheme_option_row( 12, 2, 'PRO', '', 'promo' ) ),
	array( mtuc_build_popup_scheme_option_row( 12, 3, 'PRO', '', 'promo' ) ),
);
$common_std_3   = mtuc_intersect_cart_scheme_options( $std_sets );
$common_promo_3 = mtuc_intersect_cart_scheme_options( $promo_sets );
$unified_3      = mtuc_build_checkout_unified_scheme_options( $common_std_3, $common_promo_3 );
mtuc_csi_assert( 1 === count( $common_std_3 ) && 12 === (int) $common_std_3[0]['months'], '3-product standard common is 12' );
mtuc_csi_assert( 1 === count( $common_promo_3 ), '3-product promo common is 12' );
mtuc_csi_assert(
	array( '12:1', 'p:12:1' ) === array_column( $unified_3, 'key' ),
	'3-product intersection feeds checkout unify with std+promo'
);

// Default selection integrates with unified option set (product-offer selection module).
$default_shop = array(
	'uni_shema_current' => 12,
	'coeff_list'        => array(
		array(
			'onlineProductCode' => 'PRO',
			'installmentCount'  => 12,
			'interestPercent'   => 0.0,
			'coeff'             => 0.083333,
		),
	),
);
$default_key = mtuc_pick_default_checkout_scheme_key( $default_shop, $unified_3, null );
mtuc_csi_assert( 'p:12:1' === $default_key, 'checkout default picker prefers longest 0% promo from unified set' );

fwrite( STDOUT, 'OK cart-scheme-intersection ' . $mtuc_csi_assert_count . " assertions\n" );
