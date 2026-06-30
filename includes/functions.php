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
 * Category term IDs for a product (includes parent categories in WC).
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

	$product_id   = $product->get_id();
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
			$parva      = round( $price / $months, 2 );
			$calc_price = round( $price - $parva, 2 );
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
 * @param int                  $product_id   Current product ID.
 * @param array<int, int>      $category_ids Product category term IDs.
 * @param float                $price        Product price including tax.
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
 * Resolve parva amount and display flags for popup/cart calculators.
 *
 * Filter-level `uni_parva` overrides shop `uni_first_vnoska` and locks the value.
 *
 * @param array<string, mixed>      $shop       Shop data.
 * @param float                     $price      Product price or cart total.
 * @param int                       $months     Installment count.
 * @param float                     $user_parva User-entered initial payment.
 * @param array<string, mixed>|null $filter     Matching schema filter row.
 * @return array{parva:float,parva_locked:bool,show_parva:bool}
 */
function mtuc_resolve_parva_calculation_state(
	array $shop,
	float $price,
	int $months,
	float $user_parva,
	?array $filter = null
): array {
	$show_parva   = mtuc_is_yes_flag( $shop['uni_first_vnoska'] ?? 0 );
	$parva_locked = false;
	$parva        = 0.0;

	if ( null !== $filter && 1 === (int) ( $filter['uni_parva'] ?? 0 ) && $months > 0 ) {
		$parva        = round( $price / $months, 2 );
		$parva_locked = true;
	} elseif ( $show_parva ) {
		$parva = max( 0.0, min( round( $user_parva, 2 ), $price ) );
	}

	if ( $parva_locked ) {
		$show_parva = true;
	}

	return array(
		'parva'        => $parva,
		'parva_locked' => $parva_locked,
		'show_parva'   => $show_parva,
	);
}

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

/**
 * Pick the button offer with the lowest monthly installment.
 *
 * @param array<int, array<string, mixed>> $offers Resolved button offers.
 * @return array<string, mixed>|null
 */
function mtuc_pick_lowest_monthly_button_offer( array $offers ): ?array {
	if ( empty( $offers ) ) {
		return null;
	}

	$best             = null;
	$best_installment = null;

	foreach ( $offers as $offer ) {
		$installment = (float) ( $offer['monthly_installment'] ?? 0 );
		if ( null === $best_installment || $installment < $best_installment ) {
			$best_installment = $installment;
			$best             = $offer;
		}
	}

	return $best;
}

/**
 * Pick button offer: prefer uni_shema_current when available, else highest month count.
 * When multiple offers tie on those criteria, pick the lowest monthly installment.
 *
 * @param array<int, array<string, mixed>> $candidates Resolved button offers.
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @return array<string, mixed>|null
 */
function mtuc_pick_preferred_button_offer( array $candidates, array $shop ): ?array {
	if ( empty( $candidates ) ) {
		return null;
	}

	$preferred = (int) ( $shop['uni_shema_current'] ?? 0 );

	if ( $preferred > 0 ) {
		$preferred_matches = array();

		foreach ( $candidates as $offer ) {
			if ( $preferred === (int) ( $offer['installment_count'] ?? 0 ) ) {
				$preferred_matches[] = $offer;
			}
		}

		if ( ! empty( $preferred_matches ) ) {
			return mtuc_pick_lowest_monthly_button_offer( $preferred_matches );
		}
	}

	$best_months = 0;

	foreach ( $candidates as $offer ) {
		$months = (int) ( $offer['installment_count'] ?? 0 );
		if ( $months > $best_months ) {
			$best_months = $months;
		}
	}

	$max_month_matches = array();

	foreach ( $candidates as $offer ) {
		if ( $best_months === (int) ( $offer['installment_count'] ?? 0 ) ) {
			$max_month_matches[] = $offer;
		}
	}

	return mtuc_pick_lowest_monthly_button_offer( $max_month_matches );
}

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

/**
 * Build button calculation payload from a coeff_list row.
 *
 * @param string               $type        Button type: standard|promo.
 * @param string               $kop_code    Resolved KOP code.
 * @param int                  $months      Installment count.
 * @param float                $price       Product price including tax.
 * @param array<string, mixed> $coeff_entry Matching coeff_list row.
 * @param array<string, mixed> $shop        Shop `data` object from CP.
 * @return array<string, mixed>|null
 */
function mtuc_build_button_offer( string $type, string $kop_code, int $months, float $price, array $coeff_entry, array $shop ): ?array {
	$kimb = isset( $coeff_entry['coeff'] ) ? (float) $coeff_entry['coeff'] : 0.0;
	if ( $kimb <= 0 ) {
		return null;
	}

	$glp                 = isset( $coeff_entry['interestPercent'] ) ? (float) $coeff_entry['interestPercent'] : 0.0;
	$monthly_installment = round( $price * $kimb, 2 );
	$gpr                 = mtuc_calculate_gpr( $months, $monthly_installment, $price );

	return array(
		'type'                => $type,
		'visible'             => true,
		'kop_code'            => $kop_code,
		'installment_count'   => $months,
		'monthly_installment' => $monthly_installment,
		'glp'                 => round( $glp, 2 ),
		'gpr'                 => round( $gpr, 2 ),
		'total_amount'        => round( $price, 2 ),
		'kimb'                => $kimb,
		'price_text'          => mtuc_format_installment_price_text( $months, $monthly_installment, $shop ),
	);
}

/**
 * Format button subtitle: "{months} x {amount primary} ({amount secondary})".
 *
 * @param int                  $months              Installment count.
 * @param float                $monthly_installment Monthly installment amount.
 * @param array<string, mixed> $shop                Shop `data` object from CP.
 * @return string
 */
function mtuc_format_installment_price_text( int $months, float $monthly_installment, array $shop ): string {
	$uni_eur = (int) ( $shop['uni_eur'] ?? 0 );
	$rate    = 1.95583;

	switch ( $uni_eur ) {
		case 1:
			$primary_amount   = $monthly_installment;
			$secondary_amount = round( $monthly_installment / $rate, 2 );
			$primary_sign     = __( 'лева', 'mtunicredit' );
			$secondary_sign   = __( 'евро', 'mtunicredit' );
			break;
		case 2:
			$primary_amount   = $monthly_installment;
			$secondary_amount = round( $monthly_installment * $rate, 2 );
			$primary_sign     = __( 'евро', 'mtunicredit' );
			$secondary_sign   = __( 'лева', 'mtunicredit' );
			break;
		case 3:
			return sprintf(
				/* translators: 1: installment count, 2: monthly amount */
				__( '%1$d x %2$s евро', 'mtunicredit' ),
				$months,
				number_format( $monthly_installment, 2, '.', '' )
			);
		case 0:
		default:
			return sprintf(
				/* translators: 1: installment count, 2: monthly amount */
				__( '%1$d x %2$s лв.', 'mtunicredit' ),
				$months,
				number_format( $monthly_installment, 2, '.', '' )
			);
	}

	return sprintf(
		/* translators: 1: installment count, 2: primary amount, 3: primary currency, 4: secondary amount, 5: secondary currency */
		__( '%1$d x %2$s %3$s (%4$s %5$s)', 'mtunicredit' ),
		$months,
		number_format( $primary_amount, 2, '.', '' ),
		$primary_sign,
		number_format( $secondary_amount, 2, '.', '' ),
		$secondary_sign
	);
}

/**
 * Calculate GPR from installment schedule (legacy UniCredit formula).
 *
 * @param int   $months              Installment count.
 * @param float $monthly_installment Monthly installment amount.
 * @param float $price               Product price including tax.
 * @return float
 */
function mtuc_calculate_gpr( int $months, float $monthly_installment, float $price ): float {
	if ( $months <= 0 || $price <= 0 || $monthly_installment <= 0 ) {
		return 0.0;
	}

	$period_rate = mtuc_financial_rate( $months, -1 * $monthly_installment, $price );
	$gprm        = ( $period_rate * $months ) / ( $months / 12 );

	return abs( ( pow( ( 1 + $gprm / 12 ), 12 ) - 1 ) * 100 );
}

/**
 * Financial rate helper (ported from legacy UNI_RATE).
 *
 * @param float $periods Number of periods.
 * @param float $payment Payment per period.
 * @param float $present_value Present value.
 * @return float
 */
function mtuc_financial_rate( float $periods, float $payment, float $present_value ): float {
	$rate = 0.1;
	$type = 0.0;
	$fv   = 0.0;

	if ( abs( $rate ) < 1.0e-8 ) {
		$y = $present_value * ( 1 + $periods * $rate ) + $payment * ( 1 + $rate * $type ) * $periods + $fv;
	} else {
		$f = exp( $periods * log( 1 + $rate ) );
		$y = $present_value * $f + $payment * ( 1 / $rate + $type ) * ( $f - 1 ) + $fv;
	}

	$y0 = $present_value + $payment * $periods + $fv;
	$y1 = $y;
	$i  = 0.0;
	$x0 = 0.0;
	$x1 = $rate;

	while ( ( abs( $y0 - $y1 ) > 1.0e-8 ) && ( $i < 128 ) ) {
		$rate = ( $y1 * $x0 - $y0 * $x1 ) / ( $y1 - $y0 );
		$x0   = $x1;
		$x1   = $rate;

		if ( abs( $rate ) < 1.0e-8 ) {
			$y = $present_value * ( 1 + $periods * $rate ) + $payment * ( 1 + $rate * $type ) * $periods + $fv;
		} else {
			$f = exp( $periods * log( 1 + $rate ) );
			$y = $present_value * $f + $payment * ( 1 / $rate + $type ) * ( $f - 1 ) + $fv;
		}

		$y0 = $y1;
		$y1 = $y;
		++$i;
	}

	return $rate;
}

/**
 * Whether the product-page calculator shell may load (shop active, no price gate).
 *
 * @return bool
 */
function mtuc_can_render_product_calculator_shell(): bool {
	if ( ! is_product() || is_admin() ) {
		return false;
	}

	if ( ! Mtuc_Settings::is_enabled() ) {
		return false;
	}

	if ( '' === (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID ) ) {
		return false;
	}

	$shop = mtuc_get_shop_data();
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return false;
	}

	return mtuc_is_yes_flag( $shop['uni_status'] ?? 0 );
}

/**
 * Build product calculator context when the template should be shown.
 *
 * @return array<string, mixed>|null
 */
function mtuc_get_product_calculator_context(): ?array {
	if ( ! mtuc_can_render_product_calculator_shell() ) {
		return null;
	}

	$unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );

	static $context  = null;
	static $resolved = false;

	if ( $resolved ) {
		return $context;
	}

	$resolved = true;
	$context  = null;

	$shop = mtuc_get_shop_data( $unicid );
	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return null;
	}

	$offer = null;
	if ( mtuc_is_product_price_in_shop_range( $shop ) ) {
		$offer = mtuc_get_product_calculator_offer( $shop );
	}

	$standard = null;
	$promo    = null;
	if ( is_array( $offer ) ) {
		$standard = $offer['standard'] ?? null;
		$promo    = $offer['promo'] ?? null;
	}

	$is_dark_button = mtuc_is_yes_flag( $shop['uni_type_button'] ?? 0 );

	$button_width  = isset( $shop['uni_button_width'] ) ? absint( $shop['uni_button_width'] ) : 0;
	$button_height = isset( $shop['uni_button_height'] ) ? absint( $shop['uni_button_height'] ) : 0;

	if ( $button_width <= 0 ) {
		$button_width = 290;
	}

	if ( $button_height <= 0 ) {
		$button_height = 56;
	}

	$current_product = mtuc_get_current_wc_product();
	$line_price      = mtuc_get_product_price( $current_product );

	$context = array(
		'product_id'       => $current_product instanceof WC_Product ? $current_product->get_id() : 0,
		'offer'            => $offer,
		'standard'         => $standard,
		'promo'            => $promo,
		'show_installment' => mtuc_is_yes_flag( $shop['uni_vnoska'] ?? 0 ),
		'buttons_in_row'   => 1 === (int) ( $shop['uni_button_row'] ?? 1 ),
		'button_width'     => $button_width,
		'button_height'    => $button_height,
		'is_dark_button'   => $is_dark_button,
		'logo_url'         => mtuc_get_uni_logo_url( $is_dark_button ),
		'gap'              => (int) Mtuc_Settings::get( Mtuc_Settings::OPTION_GAP ),
		'heading'          => mtuc_get_shop_calculator_heading( $shop ),
		'popup'            => mtuc_get_product_popup_context(
			$shop,
			array(
				'standard' => $standard,
				'promo'    => $promo,
			),
			$current_product instanceof WC_Product ? $current_product : null,
			null !== $line_price ? (float) $line_price : null
		),
	);

	return $context;
}

/**
 * Whether the product-page calculator should be rendered.
 *
 * @return bool
 */
function mtuc_should_show_product_calculator(): bool {
	return null !== mtuc_get_product_calculator_context();
}

/**
 * Register WooCommerce hook for the product-page calculator template.
 *
 * @return void
 */
function mtuc_register_product_hooks(): void {
	if ( is_admin() ) {
		return;
	}

	$hook  = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_HOOK );
	$hooks = Mtuc_Settings::get_hook_choices();

	if ( ! array_key_exists( $hook, $hooks ) ) {
		$hook = Mtuc_Settings::DEFAULT_HOOK;
	}

	add_action( $hook, 'mtuc_render_product_calculator', 15 );
	add_action( 'wp_enqueue_scripts', 'mtuc_enqueue_product_assets' );

	mtuc_register_product_popup_hooks();
}

/**
 * Enqueue product calculator CSS on single product pages when enabled.
 *
 * @return void
 */
function mtuc_enqueue_product_assets(): void {
	if ( ! mtuc_should_show_product_calculator() ) {
		return;
	}

	$context = mtuc_get_product_calculator_context();
	if ( null === $context ) {
		return;
	}

	$css_file        = MTUC_PLUGIN_DIR . '/css/mtuc-product.css';
	$popup_css       = MTUC_PLUGIN_DIR . '/css/mtuc-popup.css';
	$calculator_js   = MTUC_PLUGIN_DIR . '/js/mtuc-product-calculator.js';
	$popup_js        = MTUC_PLUGIN_DIR . '/js/mtuc-product-popup.js';
	$current_product = mtuc_get_current_wc_product();
	$product_id      = $current_product instanceof WC_Product ? $current_product->get_id() : (int) ( $context['product_id'] ?? 0 );

	mtuc_enqueue_fonts();

	wp_enqueue_style(
		'mtuc-product',
		MTUC_CSS_URI . '/mtuc-product.css',
		array( 'mtuc-fonts' ),
		file_exists( $css_file ) ? (string) filemtime( $css_file ) : MTUC_VERSION
	);

	wp_enqueue_style(
		'mtuc-popup',
		MTUC_CSS_URI . '/mtuc-popup.css',
		array( 'mtuc-product' ),
		file_exists( $popup_css ) ? (string) filemtime( $popup_css ) : MTUC_VERSION
	);

	wp_enqueue_script(
		'mtuc-product-calculator',
		MTUC_JS_URI . '/mtuc-product-calculator.js',
		array( 'jquery' ),
		file_exists( $calculator_js ) ? (string) filemtime( $calculator_js ) : MTUC_VERSION,
		true
	);

	wp_enqueue_script(
		'mtuc-product-popup',
		MTUC_JS_URI . '/mtuc-product-popup.js',
		array( 'jquery', 'mtuc-product-calculator' ),
		file_exists( $popup_js ) ? (string) filemtime( $popup_js ) : MTUC_VERSION,
		true
	);

	$popup_context = isset( $context['popup'] ) && is_array( $context['popup'] ) ? $context['popup'] : array();

	wp_localize_script(
		'mtuc-product-calculator',
		'mtucCalculator',
		array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'nonce'     => wp_create_nonce( 'mtuc_popup' ),
			'productId' => $product_id,
		)
	);

	wp_localize_script(
		'mtuc-product-popup',
		'mtucPopup',
		array(
			'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
			'nonce'                => wp_create_nonce( 'mtuc_popup' ),
			'source'               => 'product',
			'productId'            => $product_id,
			'enabledMonthsByOffer' => isset( $popup_context['enabled_months_by_offer'] ) && is_array( $popup_context['enabled_months_by_offer'] )
				? $popup_context['enabled_months_by_offer']
				: array(),
			'defaultSchemeByOffer' => isset( $popup_context['default_scheme_by_offer'] ) && is_array( $popup_context['default_scheme_by_offer'] )
				? $popup_context['default_scheme_by_offer']
				: array(),
			'currencyDual'         => ! empty( $popup_context['currency']['dual'] ),
			'hideAddToCart'        => ! empty( $popup_context['hide_add_to_cart'] ),
			'process2'             => ! empty( $popup_context['process2'] ),
			'payBtn'               => Mtuc_Settings::get_paybtn_mode(),
			'checkoutUrl'          => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'customer'             => isset( $popup_context['customer'] ) && is_array( $popup_context['customer'] )
				? array(
					'first_name' => (string) ( $popup_context['customer']['first_name'] ?? '' ),
					'last_name'  => (string) ( $popup_context['customer']['last_name'] ?? '' ),
					'address'    => (string) ( $popup_context['customer']['address'] ?? '' ),
					'phone'      => (string) ( $popup_context['customer']['phone'] ?? '' ),
					'email'      => (string) ( $popup_context['customer']['email'] ?? '' ),
				)
				: mtuc_get_popup_customer_defaults(),
			'i18n'                 => array(
				'calcError'      => __( 'Неуспешно изчисление. Моля, опитайте отново.', 'mtunicredit' ),
				'addToCartError' => __( 'Не може да се добави в количката. Проверете опциите на продукта.', 'mtunicredit' ),
				'buyLabel'       => __( 'Купи', 'mtunicredit' ),
				'schemeRequired' => __( 'Моля, изберете схема за погасяване.', 'mtunicredit' ),
				'submitPending'  => __( 'Изпращането на заявката ще бъде добавено на следващ етап.', 'mtunicredit' ),
				'monthsLabel'    => __( '%d месеца', 'mtunicredit' ),
				'noMonths'       => __( 'Няма налични срокове за този продукт.', 'mtunicredit' ),
				'fieldRequired'  => __( 'Полето е задължително.', 'mtunicredit' ),
				'phoneInvalid'   => __( 'Въведете валиден телефонен номер.', 'mtunicredit' ),
				'emailInvalid'   => __( 'Въведете валиден e-mail адрес.', 'mtunicredit' ),
				'egnInvalid'     => __( 'Въведете валидно ЕГН (10 цифри, първите 8 — дата YYYYMMDD).', 'mtunicredit' ),
				'submitError'    => __( 'Заявката не може да бъде изпратена. Моля, опитайте отново.', 'mtunicredit' ),
				'submitNoCalc'   => __( 'Липсват данни за изчисление. Моля, върнете се и изберете схема отново.', 'mtunicredit' ),
				'submitting'     => __( 'Изпращане...', 'mtunicredit' ),
				'processing'     => __( 'Обработване на заявката. Моля, изчакайте...', 'mtunicredit' ),
			),
		)
	);
}

/**
 * Render product-page calculator template when conditions are met.
 *
 * @return void
 */
function mtuc_render_product_calculator(): void {
	$context = mtuc_get_product_calculator_context();
	if ( null === $context ) {
		return;
	}

	$template = MTUC_PLUGIN_DIR . '/templates/product-calculator.php';
	if ( ! is_readable( $template ) ) {
		return;
	}

	include $template;
}

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
