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
 * Current single product price including tax.
 *
 * @return float|null
 */
function mtuc_get_current_product_price(): ?float {
	if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_price_including_tax' ) ) {
		return null;
	}

	global $product;

	if ( ! $product instanceof WC_Product ) {
		$product_id = get_queried_object_id();
		if ( $product_id <= 0 ) {
			return null;
		}

		$product = wc_get_product( $product_id );
	}

	if ( ! $product instanceof WC_Product ) {
		return null;
	}

	$price = (float) wc_get_price_including_tax( $product );

	return $price > 0 ? $price : null;
}

/**
 * Whether the current product price is within CP min/max limits.
 *
 * @param array<string, mixed> $shop Shop `data` object from CP.
 * @return bool
 */
function mtuc_is_product_price_in_shop_range( array $shop ): bool {
	$price = mtuc_get_current_product_price();
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
 * Only the default-KOP / Standard-button path is implemented for now.
 *
 * @param array<string, mixed>|null $shop Shop `data` object from CP (defaults to cached shop).
 * @return array<string, mixed>|null
 */
function mtuc_get_product_calculator_offer( $shop = null ): ?array {
	if ( null === $shop ) {
		$shop = mtuc_get_shop_data();
	}

	if ( is_wp_error( $shop ) || ! is_array( $shop ) ) {
		return null;
	}

	$price = mtuc_get_current_product_price();
	if ( null === $price ) {
		return null;
	}

	$coeff_list = mtuc_get_shop_coeff_list( $shop );
	$standard   = mtuc_resolve_standard_button_offer( $shop, $coeff_list, $price );
	$promo      = null;

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
 * Resolve Standard button offer for default KOP settings (uni_typekop = 0).
 *
 * @param array<string, mixed>             $shop       Shop `data` object from CP.
 * @param array<int, array<string, mixed>> $coeff_list Coefficient rows from cache.
 * @param float                            $price      Product price including tax.
 * @return array<string, mixed>|null
 */
function mtuc_resolve_standard_button_offer( array $shop, array $coeff_list, float $price ): ?array {
	if ( 0 !== (int) ( $shop['uni_typekop'] ?? -1 ) ) {
		return null;
	}

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
 * Build product calculator context when the template should be shown.
 *
 * @return array<string, mixed>|null
 */
function mtuc_get_product_calculator_context(): ?array {
	if ( ! is_product() || is_admin() ) {
		return null;
	}

	if ( ! Mtuc_Settings::is_enabled() ) {
		return null;
	}

	$unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );
	if ( '' === $unicid ) {
		return null;
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

	if ( ! mtuc_is_product_price_in_shop_range( $shop ) ) {
		return null;
	}

	$offer = mtuc_get_product_calculator_offer( $shop );
	if ( null === $offer ) {
		return null;
	}

	$is_dark_button = mtuc_is_yes_flag( $shop['uni_type_button'] ?? 0 );

	$context = array(
		'offer'          => $offer,
		'standard'       => $offer['standard'],
		'promo'          => $offer['promo'],
		'is_dark_button' => $is_dark_button,
		'logo_url'       => mtuc_get_uni_logo_url( $is_dark_button ),
		'gap'            => (int) Mtuc_Settings::get( Mtuc_Settings::OPTION_GAP ),
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

	$css_file = MTUC_PLUGIN_DIR . '/css/mtuc-product.css';

	mtuc_enqueue_fonts();

	wp_enqueue_style(
		'mtuc-product',
		MTUC_CSS_URI . '/mtuc-product.css',
		array( 'mtuc-fonts' ),
		file_exists( $css_file ) ? (string) filemtime( $css_file ) : MTUC_VERSION
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
