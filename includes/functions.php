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
	$default_logo = esc_url( MTUC_PLUGIN_URL . '/images/uni_logo.jpg' );
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
 * Whether the product-page calculator should be rendered.
 *
 * @return bool
 */
function mtuc_should_show_product_calculator(): bool {
	if ( ! is_product() || is_admin() ) {
		return false;
	}

	if ( ! Mtuc_Settings::is_enabled() ) {
		return false;
	}

	$unicid = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_UNICID );
	if ( '' === $unicid ) {
		return false;
	}

	$shop = mtuc_get_shop_data( $unicid );
	if ( is_wp_error( $shop ) ) {
		return false;
	}

	return mtuc_is_yes_flag( $shop['uni_status'] ?? 0 );
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

	$hook = (string) Mtuc_Settings::get( Mtuc_Settings::OPTION_HOOK );
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
	if ( ! mtuc_should_show_product_calculator() ) {
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
				<img src="<?php echo esc_url( $context['float_image_url'] ); ?>" alt="<?php esc_attr_e( 'УниКредит покупки на Кредит', 'mtunicredit' ); ?>" />
			</button>
		</div>
		<?php
		return;
	}

	?>
	<div class="mtuc-reklama" id="mtuc-reklama">
		<button type="button" class="mtuc-reklama-float" onclick="mtucReklamaToggle();" aria-controls="mtuc-reklama-panel" aria-expanded="false">
			<img src="<?php echo esc_url( $context['float_image_url'] ); ?>" alt="<?php esc_attr_e( 'УниКредит покупки на Кредит', 'mtunicredit' ); ?>" />
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
