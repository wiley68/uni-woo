<?php
/**
 * Shared helper functions for УНИ Кредит.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a CP/legacy flag value is considered enabled.
 *
 * @param mixed $value Raw flag from settings or shop cache.
 * @return bool
 */
function mtuc_is_yes_flag( $value ): bool {
	if ( is_bool( $value ) ) {
		return $value;
	}

	if ( is_numeric( $value ) ) {
		return 1 === (int) $value;
	}

	$value = strtolower( trim( (string) $value ) );

	return in_array( $value, array( 'yes', 'on', '1', 'true' ), true );
}

/**
 * Whether the shop uses leasing Process 2 (WC + CP only, no SmartUCF).
 *
 * @param array<string, mixed> $shop Shop `data` object from CP.
 * @return bool
 */
function mtuc_is_shop_process_2( array $shop ): bool {
	return 1 === (int) ( $shop['uni_proces'] ?? 0 );
}

/**
 * Process 2 follow-up message shown in leasing emails.
 *
 * @return string
 */
function mtuc_get_process2_confirmation_message(): string {
	return __( 'Очаквайте контакт за потвърждаване на направената от Вас заявка.', 'mtunicredit' );
}

/**
 * Strip non-digits from an EGN input value.
 *
 * @param string $value Raw EGN.
 * @return string
 */
function mtuc_sanitize_egn( string $value ): string {
	$digits = preg_replace( '/\D/', '', $value );

	return is_string( $digits ) ? $digits : '';
}

/**
 * Validate Bulgarian EGN: 10 digits, first 8 are a valid YYYYMMDD date.
 *
 * @param string $egn Raw or sanitized EGN.
 * @return bool
 */
function mtuc_validate_bulgarian_egn( string $egn ): bool {
	$egn = mtuc_sanitize_egn( $egn );
	if ( ! preg_match( '/^\d{10}$/', $egn ) ) {
		return false;
	}

	$year  = (int) substr( $egn, 0, 4 );
	$month = (int) substr( $egn, 4, 2 );
	$day   = (int) substr( $egn, 6, 2 );

	return checkdate( $month, $day, $year );
}

/**
 * Validate a customer phone number (primary or secondary).
 *
 * @param string $phone Raw phone input.
 * @return bool
 */
function mtuc_validate_customer_phone( string $phone ): bool {
	$phone = preg_replace( '/[^0-9+() -]/', '', $phone );
	$phone = is_string( $phone ) ? trim( $phone ) : '';

	return '' !== $phone && preg_match( '/^[-0-9+() ]+$/', $phone ) && preg_match( '/\d/', $phone );
}

/**
 * Parse shop notification emails from CP shop cache (`uni_email`).
 *
 * @param array<string, mixed> $shop Shop `data` object from CP.
 * @return array<int, string>
 */
function mtuc_parse_shop_notification_emails( array $shop ): array {
	$raw   = isset( $shop['uni_email'] ) ? (string) $shop['uni_email'] : '';
	$parts = preg_split( '/\s*,\s*/', $raw );
	$parts = is_array( $parts ) ? $parts : array();

	$emails = array();
	foreach ( $parts as $part ) {
		$part = trim( (string) $part );
		if ( '' !== $part && is_email( $part ) ) {
			$emails[] = $part;
		}
	}

	return array_values( array_unique( $emails ) );
}

/**
 * Get shop configuration — uses cache when fresh, otherwise refreshes from CP.
 *
 * Wrapper for module code that needs shop data. The admin "refresh" button
 * should call Mtuc_Shop_Cache::refresh_from_api() directly instead.
 *
 * @param string|null $unicid Store unicid (defaults to settings).
 * @return array<string, mixed>|WP_Error
 */
function mtuc_get_shop_data( $unicid = null ) {
	return Mtuc_Shop_Cache::get_shop_data( $unicid );
}

/**
 * CDN picture URL from shop cache (PC or mobile).
 *
 * @param array<string, mixed> $shop   Shop `data` object.
 * @param bool                 $mobile True for uni_picturem, false for uni_picture.
 * @return string Escaped URL or empty string.
 */
function mtuc_get_shop_picture_url( array $shop, bool $mobile = false ): string {
	$key = $mobile ? 'uni_picturem' : 'uni_picture';

	if ( empty( $shop[ $key ] ) || ! is_string( $shop[ $key ] ) ) {
		return '';
	}

	return esc_url_raw( $shop[ $key ] );
}

/**
 * Local UniCredit logo URL (SVG).
 *
 * @param bool $for_dark_button Use red-background variant for dark button style.
 * @return string
 */
function mtuc_get_uni_logo_url( bool $for_dark_button = false ): string {
	$file = $for_dark_button ? 'uni_logo_red.svg' : 'uni_logo.svg';

	return esc_url( MTUC_PLUGIN_URL . '/images/' . $file );
}

/**
 * Optional heading above calculator buttons from CP shop settings.
 *
 * @param array<string, mixed> $shop Shop `data` object from CP.
 * @return string Empty when uni_zaglavie is not set.
 */
function mtuc_get_shop_calculator_heading( array $shop ): string {
	return isset( $shop['uni_zaglavie'] ) ? trim( (string) $shop['uni_zaglavie'] ) : '';
}

/**
 * Mini UniCredit logo URL for popup buy button badge.
 *
 * @return string
 */
function mtuc_get_uni_mini_logo_url(): string {
	return esc_url( MTUC_PLUGIN_URL . '/images/uni_mini_logo.png' );
}

/**
 * Normalized shop consents from CP cache (sorted by id).
 *
 * @param array<string, mixed> $shop Shop `data` object.
 * @return array<int, array{id:int,name:string,url:string,mandatory:bool,has_checkbox:bool}>
 */
function mtuc_get_shop_consents( array $shop ): array {
	$raw = $shop['consents'] ?? null;

	if ( is_string( $raw ) ) {
		$decoded = json_decode( $raw, true );
		$raw       = is_array( $decoded ) ? $decoded : null;
	}

	if ( ! is_array( $raw ) || empty( $raw ) ) {
		return array();
	}

	$consents = array();

	foreach ( $raw as $index => $item ) {
		if ( ! is_array( $item ) ) {
			continue;
		}

		$name = isset( $item['name'] ) ? wp_strip_all_tags( (string) $item['name'] ) : '';
		if ( '' === $name ) {
			continue;
		}

		$id        = isset( $item['id'] ) ? absint( $item['id'] ) : (int) $index + 1;
		$url       = isset( $item['url'] ) ? esc_url_raw( (string) $item['url'] ) : '';
		$mandatory = mtuc_is_yes_flag( $item['mandatory'] ?? 0 );

		$consents[] = array(
			'id'           => $id,
			'name'         => $name,
			'url'          => $url,
			'mandatory'    => $mandatory,
			'has_checkbox' => $mandatory,
		);
	}

	if ( empty( $consents ) ) {
		return array();
	}

	usort(
		$consents,
		static function ( array $a, array $b ): int {
			return $a['id'] <=> $b['id'];
		}
	);

	return $consents;
}

/**
 * Render shop consents markup for popup or checkout.
 *
 * @param array<int, array<string, mixed>> $consents   Normalized consents.
 * @param string                           $id_prefix  Checkbox id prefix.
 * @param string                           $input_name Checkbox name attribute.
 * @return string
 */
function mtuc_render_shop_consents_markup( array $consents, string $id_prefix = 'mtuc-consent', string $input_name = 'mtuc_consent[]' ): string {
	if ( empty( $consents ) ) {
		return '';
	}

	$template = MTUC_PLUGIN_DIR . '/templates/partials/shop-consents.php';
	if ( ! is_readable( $template ) ) {
		return '';
	}

	ob_start();
	include $template;

	return (string) ob_get_clean();
}

/**
 * Mandatory consent ids (checkbox rows) from shop settings.
 *
 * @param array<string, mixed> $shop Shop `data` object.
 * @return int[]
 */
function mtuc_get_mandatory_consent_ids( array $shop ): array {
	$ids = array();

	foreach ( mtuc_get_shop_consents( $shop ) as $consent ) {
		if ( empty( $consent['has_checkbox'] ) ) {
			continue;
		}

		$ids[] = (int) ( $consent['id'] ?? 0 );
	}

	return array_values( array_filter( $ids ) );
}

/**
 * Parse accepted consent ids from POST / blocks payment_data.
 *
 * @param array<string, mixed> $posted Posted request data.
 * @return int[]
 */
function mtuc_parse_accepted_consent_ids_from_post( array $posted ): array {
	if ( ! isset( $posted['mtuc_consent'] ) ) {
		return array();
	}

	$raw = $posted['mtuc_consent'];
	if ( is_string( $raw ) ) {
		$parts = '' === trim( $raw ) ? array() : explode( ',', $raw );
	} elseif ( is_array( $raw ) ) {
		$parts = $raw;
	} else {
		$parts = array( $raw );
	}

	$accepted = array();
	foreach ( $parts as $consent_id ) {
		$consent_id = absint( $consent_id );
		if ( $consent_id > 0 ) {
			$accepted[] = $consent_id;
		}
	}

	return array_values( array_unique( $accepted ) );
}

/**
 * Validate mandatory consents from checkout/popup POST.
 *
 * @param array<string, mixed> $posted Posted request data.
 * @param array<string, mixed> $shop   Shop `data` object.
 * @return true|WP_Error
 */
function mtuc_validate_mandatory_consents_from_post( array $posted, array $shop ) {
	$required = mtuc_get_mandatory_consent_ids( $shop );
	if ( empty( $required ) ) {
		return true;
	}

	$accepted = mtuc_parse_accepted_consent_ids_from_post( $posted );

	foreach ( $required as $consent_id ) {
		if ( ! in_array( $consent_id, $accepted, true ) ) {
			return new WP_Error(
				'mtuc_consents_required',
				__( 'Моля, приемете всички задължителни съгласия.', 'mtunicredit' )
			);
		}
	}

	return true;
}

/**
 * Build reklama context when the floating button should be shown.
 *
 * @param bool $settings_only Skip shop cache lookup (for asset enqueue).
 * @return array<string, mixed>|null
 */
function mtuc_get_reklama_context( bool $settings_only = false ): ?array {
	if ( ! is_front_page() || is_admin() ) {
		return null;
	}

	if ( ! Mtuc_Settings::is_enabled() ) {
		return null;
	}

	if ( 1 !== (int) Mtuc_Settings::get( Mtuc_Settings::OPTION_REKLAMA ) ) {
		return null;
	}

	$unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );
	if ( '' === $unicid ) {
		return null;
	}

	if ( $settings_only ) {
		return array(
			'unicid' => $unicid,
		);
	}

	static $context  = null;
	static $resolved = false;

	if ( $resolved ) {
		return $context;
	}

	$resolved = true;
	$context  = null;

	$shop = mtuc_get_shop_data( $unicid );
	if ( is_wp_error( $shop ) ) {
		return null;
	}

	if ( ! mtuc_is_yes_flag( $shop['uni_status'] ?? 0 ) ) {
		return null;
	}

	if ( ! mtuc_is_yes_flag( $shop['uni_container_status'] ?? 0 ) ) {
		return null;
	}

	$backurl = isset( $shop['uni_backurl'] ) ? esc_url_raw( (string) $shop['uni_backurl'] ) : '';

	$is_mobile    = wp_is_mobile();
	$default_logo = mtuc_get_uni_logo_url();
	$picture_url  = mtuc_get_shop_picture_url( $shop, true );
	$float_image  = $is_mobile ? mtuc_get_shop_picture_url( $shop, true ) : $default_logo;

	if ( '' === $float_image ) {
		$float_image = $default_logo;
	}

	$context = array(
		'backurl'         => $backurl,
		'txt1'            => isset( $shop['uni_container_txt1'] ) ? sanitize_text_field( (string) $shop['uni_container_txt1'] ) : '',
		'txt2'            => isset( $shop['uni_container_txt2'] ) ? sanitize_text_field( (string) $shop['uni_container_txt2'] ) : '',
		'float_image_url' => esc_url( $float_image ),
		'picture_url'     => esc_url( $picture_url ),
		'is_mobile'       => $is_mobile,
	);

	return $context;
}

/**
 * Current WooCommerce product on a single product page.
 *
 * @return WC_Product|null
 */
function mtuc_get_current_wc_product(): ?WC_Product {
	if ( ! function_exists( 'wc_get_product' ) ) {
		return null;
	}

	global $product;

	if ( $product instanceof WC_Product ) {
		return $product;
	}

	$product_id = get_queried_object_id();
	if ( $product_id <= 0 ) {
		return null;
	}

	$loaded = wc_get_product( $product_id );

	return $loaded instanceof WC_Product ? $loaded : null;
}

/**
 * Catalog/filter product ID for CP schema matching (parity with PrestaShop unipayment).
 *
 * Variations always resolve to their parent. Price stays on the variation instance.
 *
 * @param WC_Product $product Product or variation instance.
 * @return int Parent ID for variations, otherwise product ID.
 */
function mtuc_get_catalog_product_id( WC_Product $product ): int {
	if ( $product->is_type( 'variation' ) ) {
		$parent_id = (int) $product->get_parent_id();

		return $parent_id > 0 ? $parent_id : (int) $product->get_id();
	}

	return (int) $product->get_id();
}

/**
 * Variation ID when the product is a variation; otherwise 0.
 *
 * @param WC_Product $product Product or variation instance.
 * @return int
 */
function mtuc_get_product_variation_id( WC_Product $product ): int {
	return $product->is_type( 'variation' ) ? (int) $product->get_id() : 0;
}

/**
 * Category term IDs for a product (includes parent categories in WC).
 *
 * For variations, categories are read from the parent (variations usually have none).
 *
 * @param WC_Product|null $product Product instance (defaults to current product).
 * @return array<int, int>
 */
function mtuc_get_product_category_ids( ?WC_Product $product = null ): array {
	if ( null === $product ) {
		$product = mtuc_get_current_wc_product();
	}

	if ( ! $product instanceof WC_Product ) {
		return array();
	}

	if ( $product->is_type( 'variation' ) ) {
		$parent_id = (int) $product->get_parent_id();
		if ( $parent_id > 0 ) {
			$parent = mtuc_get_wc_product_by_id( $parent_id );
			if ( $parent instanceof WC_Product ) {
				$product = $parent;
			}
		}
	}

	$ids = $product->get_category_ids();
	if ( ! is_array( $ids ) ) {
		return array();
	}

	return array_map( 'intval', $ids );
}

/**
 * Load a WooCommerce product by ID.
 *
 * @param int $product_id Product or variation ID.
 * @return WC_Product|null
 */
function mtuc_get_wc_product_by_id( int $product_id ): ?WC_Product {
	if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
		return null;
	}

	$product = wc_get_product( $product_id );

	return $product instanceof WC_Product ? $product : null;
}

/**
 * Product price including tax.
 *
 * @param WC_Product|null $product Product instance (defaults to current product page).
 * @return float|null
 */
function mtuc_get_product_price( ?WC_Product $product = null ): ?float {
	if ( ! function_exists( 'wc_get_price_including_tax' ) ) {
		return null;
	}

	if ( null === $product ) {
		$product = mtuc_get_current_wc_product();
	}

	if ( ! $product instanceof WC_Product ) {
		return null;
	}

	$price = (float) wc_get_price_including_tax( $product );

	return $price > 0 ? $price : null;
}

/**
 * Current single product price including tax.
 *
 * @return float|null
 */
function mtuc_get_current_product_price(): ?float {
	return mtuc_get_product_price();
}

/**
 * Whether a product price is within CP min/max limits.
 *
 * @param array<string, mixed> $shop  Shop `data` object from CP.
 * @param float|null           $price Product price including tax (defaults to current product).
 * @return bool
 */
function mtuc_is_product_price_in_shop_range( array $shop, ?float $price = null ): bool {
	if ( null === $price ) {
		$price = mtuc_get_current_product_price();
	}

	if ( null === $price ) {
		return false;
	}

	$min = isset( $shop['uni_minstojnost'] ) ? (float) $shop['uni_minstojnost'] : 0.0;
	$max = isset( $shop['uni_maxstojnost'] ) ? (float) $shop['uni_maxstojnost'] : 0.0;

	return $price >= $min && $price <= $max;
}

/**
 * Coefficient list for installment calculations.
 *
 * @param array<string, mixed> $shop Shop `data` object from CP.
 * @return array<int, array<string, mixed>>
 */
function mtuc_get_shop_coeff_list( array $shop ): array {
	if ( isset( $shop['coeff_list'] ) && is_array( $shop['coeff_list'] ) ) {
		return $shop['coeff_list'];
	}

	return Mtuc_Shop_Cache::get_coeff_list();
}

/**
 * Resolve product calculator buttons and installment calculations.
 *
 * Main entry point for deciding whether/how to show the Standard and Promo buttons.
 *
 * @param array<string, mixed>|null $shop    Shop `data` object from CP (defaults to cached shop).
 * @param WC_Product|null           $product Product instance (defaults to current product page).
 * @param float|null                $price   Line price including tax (defaults to current product price).
 * @return array<string, mixed>|null
 */
function mtuc_get_product_calculator_offer( $shop = null, ?WC_Product $product = null, ?float $price = null ): ?array {
	if ( null === $shop ) {
		$shop = mtuc_get_shop_data();
	}

	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return null;
	}

	if ( null === $product ) {
		$product = mtuc_get_current_wc_product();
	}

	if ( null === $price ) {
		$price = mtuc_get_product_price( $product );
	}

	if ( null === $price ) {
		return null;
	}

	$coeff_list = mtuc_get_shop_coeff_list( $shop );
	$standard   = mtuc_resolve_standard_button_offer( $shop, $coeff_list, $price, $product );
	$promo      = mtuc_resolve_promo_button_offer( $shop, $coeff_list, $price, $product );

	if ( null === $standard && null === $promo ) {
		return null;
	}

	return array(
		'price'    => $price,
		'standard' => $standard,
		'promo'    => $promo,
	);
}

/**
 * Resolve Standard button offer (default or schema KOP).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows from cache.
 * @param float                            $price      Product line price including tax.
 * @param WC_Product|null                  $product    Product instance for schema filters.
 * @return array<string, mixed>|null
 */
function mtuc_resolve_standard_button_offer( array $shop, array $coeff_list, float $price, ?WC_Product $product = null ): ?array {
	$typekop = (int) ( $shop['uni_typekop'] ?? -1 );

	if ( 0 === $typekop ) {
		return mtuc_resolve_standard_default_button_offer( $shop, $coeff_list, $price );
	}

	if ( 1 === $typekop ) {
		return mtuc_resolve_standard_schema_button_offer( $shop, $coeff_list, $price, $product );
	}

	return null;
}

/**
 * Resolve Standard button offer for default KOP settings (uni_typekop = 0).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows from cache.
 * @param float                            $price      Product price including tax.
 * @return array<string, mixed>|null
 */
function mtuc_resolve_standard_default_button_offer( array $shop, array $coeff_list, float $price ): ?array {
	$by_default = $shop['kop']['by_default'] ?? null;
	if ( ! is_array( $by_default ) ) {
		return null;
	}

	$kop_code = isset( $by_default['uni_kop_default'] ) ? trim( (string) $by_default['uni_kop_default'] ) : '';
	if ( '' === $kop_code ) {
		return null;
	}

	$months = (int) ( $shop['uni_shema_current'] ?? 0 );
	if ( $months <= 0 ) {
		return null;
	}

	$coeff_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $months );
	if ( null === $coeff_entry ) {
		return null;
	}

	return mtuc_build_button_offer(
		'standard',
		$kop_code,
		$months,
		$price,
		$coeff_entry,
		$shop
	);
}

/**
 * Resolve Standard button offer for schema KOP settings (uni_typekop = 1).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows from cache.
 * @param float                            $price      Product line price including tax.
 * @param WC_Product|null                  $product    Product instance for schema filters.
 * @return array<string, mixed>|null
 */
function mtuc_resolve_standard_schema_button_offer( array $shop, array $coeff_list, float $price, ?WC_Product $product = null ): ?array {
	return mtuc_resolve_schema_button_offer( $shop, $coeff_list, $price, 'standard', 0, false, $product );
}

/**
 * Resolve Promo button offer for schema KOP settings (uni_typekop = 1, 0% promo).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows from cache.
 * @param float                            $price      Product line price including tax.
 * @param WC_Product|null                  $product    Product instance for schema filters.
 * @return array<string, mixed>|null
 */
function mtuc_resolve_promo_schema_button_offer( array $shop, array $coeff_list, float $price, ?WC_Product $product = null ): ?array {
	return mtuc_resolve_schema_button_offer( $shop, $coeff_list, $price, 'promo', 1, true, $product );
}

/**
 * Resolve a calculator button offer from schema KOP filters.
 *
 * @param array<string, mixed>             $shop                  Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list            Coefficient rows from cache.
 * @param float                            $price                 Product price including tax.
 * @param string                           $button_type           standard|promo.
 * @param int                              $uni_promo_filter      Filter rows where uni_promo equals this value.
 * @param bool                             $require_zero_interest Require interestPercent == 0 on the coeff row.
 * @param WC_Product|null                  $product               Product instance for schema filters.
 * @return array<string, mixed>|null
 */
function mtuc_resolve_schema_button_offer(
	array $shop,
	array $coeff_list,
	float $price,
	string $button_type,
	int $uni_promo_filter,
	bool $require_zero_interest = false,
	?WC_Product $product = null
): ?array {
	if ( null === $product ) {
		$product = mtuc_get_current_wc_product();
	}

	if ( ! $product instanceof WC_Product ) {
		return null;
	}

	$by_schema = $shop['kop']['by_schema'] ?? null;
	if ( ! is_array( $by_schema ) ) {
		return null;
	}

	$filters = $by_schema['filters'] ?? null;
	if ( ! is_array( $filters ) ) {
		return null;
	}

	$product_id   = mtuc_get_catalog_product_id( $product );
	$category_ids = mtuc_get_product_category_ids( $product );
	$candidates   = array();
	$preferred    = (int) ( $shop['uni_shema_current'] ?? 0 );

	foreach ( $filters as $filter ) {
		if ( ! is_array( $filter ) ) {
			continue;
		}

		if ( $uni_promo_filter !== (int) ( $filter['uni_promo'] ?? 0 ) ) {
			continue;
		}

		if ( ! mtuc_schema_filter_matches_product( $filter, $product_id, $category_ids, $price ) ) {
			continue;
		}

		$kop_code = isset( $filter['uni_kop'] ) ? trim( (string) $filter['uni_kop'] ) : '';
		if ( '' === $kop_code ) {
			continue;
		}

		$allowed_months = mtuc_get_schema_filter_allowed_months( $filter, $shop );
		if ( empty( $allowed_months ) ) {
			continue;
		}

		$coeff_entry = mtuc_find_coeff_for_allowed_months( $coeff_list, $kop_code, $allowed_months, $preferred );
		if ( null === $coeff_entry ) {
			continue;
		}

		if ( $require_zero_interest ) {
			$glp = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : -1.0;
			if ( abs( $glp ) > 0.00001 ) {
				continue;
			}
		}

		$months = isset( $coeff_entry['installmentCount'] ) ? (int) $coeff_entry['installmentCount'] : 0;
		if ( $months <= 0 ) {
			continue;
		}

		$calc_price = $price;
		if ( 1 === (int) ( $filter['uni_parva'] ?? 0 ) ) {
			$parva_state = mtuc_resolve_parva_calculation_state( $shop, $price, $months, 0.0, $filter );
			$calc_price  = round( $price - (float) $parva_state['parva'], 2 );
			if ( $calc_price <= 0 ) {
				continue;
			}
		}

		$offer = mtuc_build_button_offer(
			$button_type,
			$kop_code,
			$months,
			$calc_price,
			$coeff_entry,
			$shop
		);

		if ( null !== $offer ) {
			$candidates[] = $offer;
		}
	}

	return mtuc_pick_preferred_button_offer( $candidates, $shop );
}

/**
 * Whether a schema filter row matches the current product and price.
 *
 * @param array<string, mixed> $filter       Schema filter row from CP.
 * @param int                  $product_id   Catalog product ID (parent for variations).
 * @param array<int, int>      $category_ids Product category term IDs (from parent for variations).
 * @param float                $price        Product/variation price including tax.
 * @return bool
 */
function mtuc_schema_filter_matches_product( array $filter, int $product_id, array $category_ids, float $price ): bool {
	$has_category = mtuc_schema_filter_field_has_value( $filter['category_id'] ?? null );
	$has_product  = mtuc_schema_filter_field_has_value( $filter['product_id'] ?? null );

	if ( $has_category && $has_product ) {
		return false;
	}

	if ( $has_category ) {
		$filter_category_id = (int) $filter['category_id'];
		$category_match     = false;

		foreach ( $category_ids as $category_id ) {
			if ( $filter_category_id === (int) $category_id ) {
				$category_match = true;
				break;
			}
		}

		if ( ! $category_match ) {
			return false;
		}
	}

	if ( $has_product && (int) $filter['product_id'] !== $product_id ) {
		return false;
	}

	if ( mtuc_schema_filter_field_has_value( $filter['uni_price_from'] ?? null ) ) {
		if ( $price < (float) $filter['uni_price_from'] ) {
			return false;
		}
	}

	if ( mtuc_schema_filter_field_has_value( $filter['uni_price_to'] ?? null ) ) {
		if ( $price > (float) $filter['uni_price_to'] ) {
			return false;
		}
	}

	return mtuc_schema_filter_dates_match( $filter );
}

/**
 * Lookup a schema filter row by CP filter id.
 *
 * @param array<string, mixed> $shop      Shop `data` object from CP.
 * @param int                  $filter_id Schema filter id.
 * @return array<string, mixed>|null
 */
function mtuc_get_shop_schema_filter_by_id( array $shop, int $filter_id ): ?array {
	if ( $filter_id <= 0 ) {
		return null;
	}

	$by_schema = $shop['kop']['by_schema'] ?? null;
	if ( ! is_array( $by_schema ) ) {
		return null;
	}

	$filters = $by_schema['filters'] ?? null;
	if ( ! is_array( $filters ) ) {
		return null;
	}

	foreach ( $filters as $filter ) {
		if ( is_array( $filter ) && $filter_id === (int) ( $filter['id'] ?? 0 ) ) {
			return $filter;
		}
	}

	return null;
}

/**
 * First-installment / parva state resolution lives in includes/mtuc-financing-calculator.php (AUD-WOO-016 Step 2).
 */

/**
 * Whether a schema filter value is set (non-null, non-empty).
 *
 * @param mixed $value Filter field value.
 * @return bool
 */
function mtuc_schema_filter_field_has_value( $value ): bool {
	if ( null === $value ) {
		return false;
	}

	return '' !== trim( (string) $value );
}

/**
 * Whether the current date falls within a schema filter date range.
 *
 * @param array<string, mixed> $filter Schema filter row from CP.
 * @return bool
 */
function mtuc_schema_filter_dates_match( array $filter ): bool {
	$today = current_time( 'Y-m-d' );

	if ( mtuc_schema_filter_field_has_value( $filter['uni_date_from'] ?? null ) ) {
		$date_from = substr( trim( (string) $filter['uni_date_from'] ), 0, 10 );
		if ( $today < $date_from ) {
			return false;
		}
	}

	if ( mtuc_schema_filter_field_has_value( $filter['uni_date_to'] ?? null ) ) {
		$date_to = substr( trim( (string) $filter['uni_date_to'] ), 0, 10 );
		if ( $today > $date_to ) {
			return false;
		}
	}

	return true;
}

/**
 * Whether an installment count is within the supported scheme range.
 *
 * @param int $months Installment count.
 * @return bool
 */
function mtuc_is_valid_scheme_month( int $months ): bool {
	return $months >= MTUC_SCHEME_MONTH_MIN && $months <= MTUC_SCHEME_MONTH_MAX;
}

/**
 * Parse underscore-separated positive integers (e.g. schema uni_meseci).
 *
 * @param string $raw Raw value from CP.
 * @return array<int, int>
 */
function mtuc_parse_underscore_ints( string $raw ): array {
	$values = array();

	foreach ( explode( '_', $raw ) as $part ) {
		$value = (int) trim( $part );
		if ( mtuc_is_valid_scheme_month( $value ) ) {
			$values[] = $value;
		}
	}

	return array_values( array_unique( $values ) );
}

/**
 * Enabled installment counts from shop settings.
 *
 * Shop flags: uni_meseci_{N} for each N in [MTUC_SCHEME_MONTH_MIN, MTUC_SCHEME_MONTH_MAX].
 *
 * @param array<string, mixed> $shop Shop `data` object from CP.
 * @return array<int, int>
 */
function mtuc_get_shop_enabled_months( array $shop ): array {
	$enabled = array();

	for ( $months = MTUC_SCHEME_MONTH_MIN; $months <= MTUC_SCHEME_MONTH_MAX; $months++ ) {
		if ( mtuc_is_yes_flag( $shop[ 'uni_meseci_' . $months ] ?? 0 ) ) {
			$enabled[] = $months;
		}
	}

	return $enabled;
}

/**
 * Allowed installment months for a schema filter row.
 *
 * Empty/null uni_meseci means all shop-enabled months (catch-all filter).
 *
 * @param array<string, mixed> $filter Schema filter row from CP.
 * @param array<string, mixed> $shop   Shop `data` object from CP.
 * @return array<int, int>
 */
function mtuc_get_schema_filter_allowed_months( array $filter, array $shop ): array {
	$shop_months = mtuc_get_shop_enabled_months( $shop );

	if ( ! mtuc_schema_filter_field_has_value( $filter['uni_meseci'] ?? null ) ) {
		return $shop_months;
	}

	$filter_months = mtuc_parse_underscore_ints( (string) $filter['uni_meseci'] );

	return array_values( array_intersect( $shop_months, $filter_months ) );
}

// Button offer pick/build helpers live in includes/mtuc-product-offer-selection.php (AUD-WOO-016 Step 5).

/**
 * Find coeff_list row for allowed months, preferring a specific installment count.
 *
 * @param array<int, array<string, mixed>> $coeff_list      Coefficient rows.
 * @param string                           $kop_code        onlineProductCode.
 * @param array<int, int>                  $allowed_months  Allowed installment counts.
 * @param int                              $preferred_month Preferred installment count from CP.
 * @return array<string, mixed>|null
 */
function mtuc_find_coeff_for_allowed_months(
	array $coeff_list,
	string $kop_code,
	array $allowed_months,
	int $preferred_month = 0
): ?array {
	if ( $preferred_month > 0 && in_array( $preferred_month, $allowed_months, true ) ) {
		$preferred_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $preferred_month );
		if ( null !== $preferred_entry ) {
			return $preferred_entry;
		}
	}

	return mtuc_find_best_coeff_for_months( $coeff_list, $kop_code, $allowed_months );
}

/**
 * Find the best coeff_list row for a KOP code and allowed months (highest installment count).
 *
 * @param array<int, array<string, mixed>> $coeff_list     Coefficient rows.
 * @param string                           $kop_code       onlineProductCode.
 * @param array<int, int>                  $allowed_months Allowed installment counts.
 * @return array<string, mixed>|null
 */
function mtuc_find_best_coeff_for_months( array $coeff_list, string $kop_code, array $allowed_months ): ?array {
	$best        = null;
	$best_months = 0;

	foreach ( $coeff_list as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$entry_code  = isset( $entry['onlineProductCode'] ) ? trim( (string) $entry['onlineProductCode'] ) : '';
		$entry_month = isset( $entry['installmentCount'] ) ? (int) $entry['installmentCount'] : 0;

		if ( $entry_code !== $kop_code || ! mtuc_is_valid_scheme_month( $entry_month ) || ! in_array( $entry_month, $allowed_months, true ) ) {
			continue;
		}

		if ( $entry_month > $best_months ) {
			$best_months = $entry_month;
			$best        = $entry;
		}
	}

	return $best;
}

/**
 * Resolve Promo button offer (default or schema KOP).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows from cache.
 * @param float                            $price      Product line price including tax.
 * @param WC_Product|null                  $product    Product instance for schema filters.
 * @return array<string, mixed>|null
 */
function mtuc_resolve_promo_button_offer( array $shop, array $coeff_list, float $price, ?WC_Product $product = null ): ?array {
	$typekop = (int) ( $shop['uni_typekop'] ?? -1 );

	if ( 0 === $typekop ) {
		return mtuc_resolve_promo_default_button_offer( $shop, $coeff_list, $price );
	}

	if ( 1 === $typekop ) {
		return mtuc_resolve_promo_schema_button_offer( $shop, $coeff_list, $price, $product );
	}

	return null;
}

/**
 * Resolve Promo button offer for default KOP settings (uni_typekop = 0, 0% promo).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows from cache.
 * @param float                            $price      Product price including tax.
 * @return array<string, mixed>|null
 */
function mtuc_resolve_promo_default_button_offer( array $shop, array $coeff_list, float $price ): ?array {
	$by_default = $shop['kop']['by_default'] ?? null;
	if ( ! is_array( $by_default ) ) {
		return null;
	}

	$kop_code = isset( $by_default['uni_kop_promo'] ) ? trim( (string) $by_default['uni_kop_promo'] ) : '';
	if ( '' === $kop_code ) {
		return null;
	}

	$promo_price = isset( $by_default['uni_promo_price'] ) ? (float) $by_default['uni_promo_price'] : 0.0;
	if ( $promo_price > 0 && $price < $promo_price ) {
		return null;
	}

	$meseci_znak = isset( $by_default['uni_promo_meseci_znak'] ) ? strtolower( trim( (string) $by_default['uni_promo_meseci_znak'] ) ) : '';
	$meseci_raw  = isset( $by_default['uni_promo_meseci'] ) ? trim( (string) $by_default['uni_promo_meseci'] ) : '';
	if ( '' === $meseci_znak || '' === $meseci_raw ) {
		return null;
	}

	$coeff_entry = mtuc_find_best_promo_coeff_entry(
		$coeff_list,
		$kop_code,
		$meseci_znak,
		$meseci_raw,
		(int) ( $shop['uni_shema_current'] ?? 0 )
	);
	if ( null === $coeff_entry ) {
		return null;
	}

	$glp = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : -1.0;
	if ( abs( $glp ) > 0.00001 ) {
		return null;
	}

	$months = isset( $coeff_entry['installmentCount'] ) ? (int) $coeff_entry['installmentCount'] : 0;
	if ( $months <= 0 ) {
		return null;
	}

	return mtuc_build_button_offer(
		'promo',
		$kop_code,
		$months,
		$price,
		$coeff_entry,
		$shop
	);
}

/**
 * Find the best promo coeff_list row (highest installment count among matches).
 *
 * @param array<int, array<string, mixed>> $coeff_list  Coefficient rows.
 * @param string                           $kop_code    onlineProductCode.
 * @param string                           $meseci_znak eq|greateq.
 * @param string                           $meseci_raw  Month filter from CP.
 * @param int                              $preferred_month Preferred installment count from CP.
 * @return array<string, mixed>|null
 */
function mtuc_find_best_promo_coeff_entry(
	array $coeff_list,
	string $kop_code,
	string $meseci_znak,
	string $meseci_raw,
	int $preferred_month = 0
): ?array {
	$best        = null;
	$best_months = 0;

	if ( 'eq' === $meseci_znak ) {
		$allowed_months = mtuc_parse_underscore_ints( str_replace( ',', '_', $meseci_raw ) );

		if ( empty( $allowed_months ) ) {
			return null;
		}

		if ( $preferred_month > 0 && in_array( $preferred_month, $allowed_months, true ) ) {
			$preferred_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $preferred_month );
			if ( null !== $preferred_entry ) {
				return $preferred_entry;
			}
		}

		foreach ( $coeff_list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry_code  = isset( $entry['onlineProductCode'] ) ? trim( (string) $entry['onlineProductCode'] ) : '';
			$entry_month = isset( $entry['installmentCount'] ) ? (int) $entry['installmentCount'] : 0;

			if ( $entry_code !== $kop_code || ! mtuc_is_valid_scheme_month( $entry_month ) || ! in_array( $entry_month, $allowed_months, true ) ) {
				continue;
			}

			if ( $entry_month > $best_months ) {
				$best_months = $entry_month;
				$best        = $entry;
			}
		}

		return $best;
	}

	if ( 'greateq' === $meseci_znak ) {
		$min_months = (int) $meseci_raw;
		if ( $min_months <= 0 ) {
			$parts      = explode( '_', $meseci_raw );
			$min_months = isset( $parts[0] ) ? (int) trim( $parts[0] ) : 0;
		}

		if ( ! mtuc_is_valid_scheme_month( $min_months ) ) {
			return null;
		}

		if ( $preferred_month >= $min_months && mtuc_is_valid_scheme_month( $preferred_month ) ) {
			$preferred_entry = mtuc_find_coeff_entry( $coeff_list, $kop_code, $preferred_month );
			if ( null !== $preferred_entry ) {
				return $preferred_entry;
			}
		}

		foreach ( $coeff_list as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$entry_code  = isset( $entry['onlineProductCode'] ) ? trim( (string) $entry['onlineProductCode'] ) : '';
			$entry_month = isset( $entry['installmentCount'] ) ? (int) $entry['installmentCount'] : 0;

			if ( $entry_code !== $kop_code || $entry_month < $min_months || ! mtuc_is_valid_scheme_month( $entry_month ) ) {
				continue;
			}

			if ( $entry_month > $best_months ) {
				$best_months = $entry_month;
				$best        = $entry;
			}
		}

		return $best;
	}

	return null;
}

/**
 * Find coeff_list row by online product code and installment count.
 *
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows.
 * @param string                           $kop_code   onlineProductCode.
 * @param int                              $months     installmentCount.
 * @return array<string, mixed>|null
 */
function mtuc_find_coeff_entry( array $coeff_list, string $kop_code, int $months ): ?array {
	foreach ( $coeff_list as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$entry_code  = isset( $entry['onlineProductCode'] ) ? trim( (string) $entry['onlineProductCode'] ) : '';
		$entry_month = isset( $entry['installmentCount'] ) ? (int) $entry['installmentCount'] : 0;

		if ( $entry_code === $kop_code && $entry_month === $months ) {
			return $entry;
		}
	}

	return null;
}

// Button offer build / dual-currency price text live in includes/mtuc-product-offer-selection.php (AUD-WOO-016 Step 5).

/**
 * GPR / financial-rate implementations live in includes/mtuc-financing-calculator.php (AUD-WOO-016).
 */

// Product-page hook/enqueue/context orchestration lives in includes/mtuc-product-frontend.php (AUD-WOO-016 Step 8).

/**
 * Register frontend hooks for the homepage reklama button.
 *
 * @return void
 */
function mtuc_register_reklama_hooks(): void {
	if ( is_admin() ) {
		return;
	}

	add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_reklama_assets' );
	add_action( 'wp_footer', 'mtuc_render_reklama_button', 5 );
}

/**
 * Enqueue shared MTUC fonts (Roboto Condensed).
 *
 * Safe to call multiple times; registers the style handle only once.
 *
 * @return void
 */
function mtuc_enqueue_fonts(): void {
	static $enqueued = false;

	if ( $enqueued ) {
		return;
	}

	$enqueued = true;

	$css_file = MTUC_PLUGIN_DIR . '/css/mtuc-fonts.css';

	wp_enqueue_style(
		'mtuc-fonts',
		MTUC_CSS_URI . '/mtuc-fonts.css',
		array(),
		file_exists( $css_file ) ? (string) filemtime( $css_file ) : MTUC_VERSION
	);
}

/**
 * Enqueue reklama CSS/JS on the shop homepage when enabled.
 *
 * @return void
 */
function mtuc_enqueue_reklama_assets(): void {
	$context = mtuc_get_reklama_context( true );
	if ( null === $context ) {
		return;
	}

	$css_file = MTUC_PLUGIN_DIR . '/css/mtuc-reklama.css';
	$js_file  = MTUC_PLUGIN_DIR . '/js/mtuc-reklama.js';

	mtuc_enqueue_fonts();

	wp_enqueue_style(
		'mtuc-reklama',
		MTUC_CSS_URI . '/mtuc-reklama.css',
		array( 'mtuc-fonts' ),
		file_exists( $css_file ) ? (string) filemtime( $css_file ) : MTUC_VERSION
	);

	wp_enqueue_script(
		'mtuc-reklama',
		MTUC_PLUGIN_URL . '/js/mtuc-reklama.js',
		array(),
		file_exists( $js_file ) ? (string) filemtime( $js_file ) : MTUC_VERSION,
		true
	);
}

/**
 * Render floating reklama button and popup on the shop homepage.
 *
 * Uses wp_footer instead of loop_start so markup is output once and does not
 * depend on the theme running the main query loop.
 *
 * @return void
 */
function mtuc_render_reklama_button(): void {
	$context = mtuc_get_reklama_context();
	if ( null === $context ) {
		return;
	}

	$backurl = $context['backurl'];
	$txt1    = $context['txt1'];
	$txt2    = $context['txt2'];

	if ( $context['is_mobile'] ) {
		?>
		<div class="mtuc-reklama" id="mtuc-reklama">
			<button
				type="button"
				class="mtuc-reklama-float"
				<?php if ( '' !== $backurl ) : ?>
					onclick="mtucReklamaOpenUrl('<?php echo esc_js( $backurl ); ?>');"
				<?php endif; ?>
			>
				<span class="mtuc-reklama-float__logo">
					<img src="<?php echo esc_url( $context['float_image_url'] ); ?>" alt="<?php esc_attr_e( 'УниКредит покупки на Кредит', 'mtunicredit' ); ?>" />
				</span>
			</button>
		</div>
		<?php
		return;
	}

	?>
	<div class="mtuc-reklama" id="mtuc-reklama">
		<button type="button" class="mtuc-reklama-float" onclick="mtucReklamaToggle();" aria-controls="mtuc-reklama-panel" aria-expanded="false">
			<span class="mtuc-reklama-float__logo">
				<img src="<?php echo esc_url( $context['float_image_url'] ); ?>" alt="<?php esc_attr_e( 'УниКредит покупки на Кредит', 'mtunicredit' ); ?>" />
			</span>
		</button>

		<div id="mtuc-reklama-panel" class="mtuc-reklama-panel" role="dialog" aria-label="<?php esc_attr_e( 'Информация за онлайн пазаруване на кредит', 'mtunicredit' ); ?>">
			<div class="mtuc-reklama-panel-arrow" aria-hidden="true"></div>
			<div class="mtuc-reklama-panel-body">
				<div style="padding-bottom:5px;"></div>
				<img
					class="mtuc-reklama-panel-picture"
					alt=""
					<?php if ( '' !== $context['picture_url'] ) : ?>
						src="<?php echo esc_url( $context['picture_url'] ); ?>"
					<?php endif; ?>
				/>
				<?php if ( '' !== $txt1 ) : ?>
					<div class="mtuc-reklama-panel-title"><?php echo esc_html( $txt1 ); ?></div>
				<?php endif; ?>
				<?php if ( '' !== $txt2 ) : ?>
					<p><?php echo esc_html( $txt2 ); ?></p>
				<?php endif; ?>
				<div class="mtuc-reklama-panel-link">
					<?php if ( '' !== $backurl ) : ?>
						<a href="<?php echo esc_url( $backurl ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'ИНФОРМАЦИЯ ЗА ОНЛАЙН ПАЗАРУВАНЕ НА КРЕДИТ!', 'mtunicredit' ); ?>
						</a>
					<?php else : ?>
						<?php esc_html_e( 'ИНФОРМАЦИЯ ЗА ОНЛАЙН ПАЗАРУВАНЕ НА КРЕДИТ!', 'mtunicredit' ); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>
	<?php
}
