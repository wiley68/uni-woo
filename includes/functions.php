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
 * Transient key for a CDN reklama manifest URL.
 *
 * @param string $manifest_url Manifest JSON URL from shop data.
 * @return string
 */
function mtuc_reklama_manifest_transient_key( string $manifest_url ): string {
	return 'mtuc_rkl_mf_' . md5( $manifest_url );
}

/**
 * Invalidate cached CDN manifest (e.g. after shop cache refresh).
 *
 * @param string $manifest_url Manifest JSON URL from shop data.
 * @return void
 */
function mtuc_clear_reklama_manifest_cache( string $manifest_url ): void {
	$manifest_url = esc_url_raw( $manifest_url );
	if ( '' === $manifest_url ) {
		return;
	}

	delete_transient( mtuc_reklama_manifest_transient_key( $manifest_url ) );
}

/**
 * Fetch and cache CDN manifest for reklama assets.
 *
 * @param string $manifest_url Manifest JSON URL from shop data.
 * @return array<string, mixed>|null
 */
function mtuc_get_reklama_manifest( string $manifest_url ): ?array {
	$manifest_url = esc_url_raw( $manifest_url );
	if ( '' === $manifest_url ) {
		return null;
	}

	$cache_key = mtuc_reklama_manifest_transient_key( $manifest_url );
	$cached    = get_transient( $cache_key );
	if ( is_array( $cached ) && ! empty( $cached['assets'] ) && is_array( $cached['assets'] ) ) {
		return $cached;
	}

	$response = wp_remote_get(
		$manifest_url,
		array(
			'timeout'     => 10,
			'redirection' => 3,
			'headers'     => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'mtunicredit/' . MTUC_VERSION . '; ' . home_url(),
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return null;
	}

	if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $data ) || empty( $data['assets'] ) || ! is_array( $data['assets'] ) ) {
		return null;
	}

	set_transient( $cache_key, $data, Mtuc_Shop_Cache::TTL );

	return $data;
}

/**
 * Resolve popup image URL from shop data / CDN manifest.
 *
 * @param array<string, mixed> $shop Shop `data` object.
 * @return string Escaped URL or empty string.
 */
function mtuc_resolve_reklama_picture_url( array $shop ): string {
	$reklama_id = isset( $shop['uni_container_reklama'] ) ? (int) $shop['uni_container_reklama'] : 0;
	if ( $reklama_id <= 0 ) {
		return '';
	}

	if ( ! empty( $shop['reklama_picture_url'] ) ) {
		return esc_url_raw( (string) $shop['reklama_picture_url'] );
	}

	$embedded = mtuc_get_reklama_asset_from_embedded_manifest( $shop, $reklama_id );
	if ( '' !== $embedded ) {
		return $embedded;
	}

	$built = mtuc_build_reklama_picture_url( $shop, $reklama_id );
	if ( '' !== $built ) {
		return $built;
	}

	return mtuc_get_reklama_picture_url_from_manifest( $shop, $reklama_id );
}

/**
 * Read asset URL from embedded manifest object in shop data (if CP provides it).
 *
 * @param array<string, mixed> $shop       Shop `data` object.
 * @param int                  $reklama_id Container reklama id.
 * @return string
 */
function mtuc_get_reklama_asset_from_embedded_manifest( array $shop, int $reklama_id ): string {
	if ( empty( $shop['reklama_manifest'] ) || ! is_array( $shop['reklama_manifest'] ) ) {
		return '';
	}

	$asset_key = 'unim' . $reklama_id;
	$assets    = $shop['reklama_manifest']['assets'] ?? null;
	if ( ! is_array( $assets ) || empty( $assets[ $asset_key ] ) || ! is_string( $assets[ $asset_key ] ) ) {
		return '';
	}

	return esc_url_raw( $assets[ $asset_key ] );
}

/**
 * Build CDN asset URL from manifest version and reklama id.
 *
 * @param array<string, mixed> $shop       Shop `data` object.
 * @param int                  $reklama_id Container reklama id.
 * @return string
 */
function mtuc_build_reklama_picture_url( array $shop, int $reklama_id ): string {
	$date = mtuc_get_reklama_assets_date( $shop );
	if ( '' === $date ) {
		return '';
	}

	$base = mtuc_get_reklama_cdn_assets_base( $shop );
	if ( '' === $base ) {
		return '';
	}

	$url = trailingslashit( $base ) . 'unim' . $reklama_id . '.' . $date . '.png';

	/**
	 * Filter built CDN picture URL before use.
	 *
	 * @param string               $url        Built asset URL.
	 * @param array<string, mixed> $shop       Shop data.
	 * @param int                  $reklama_id Reklama id.
	 */
	return esc_url_raw( (string) apply_filters( 'mtuc_reklama_picture_url', $url, $shop, $reklama_id ) );
}

/**
 * Asset date suffix (YYYYMMDD) for CDN filenames.
 *
 * @param array<string, mixed> $shop Shop `data` object.
 * @return string
 */
function mtuc_get_reklama_assets_date( array $shop ): string {
	$version = '';
	if ( ! empty( $shop['reklama_manifest_version'] ) ) {
		$version = (string) $shop['reklama_manifest_version'];
	} elseif ( ! empty( $shop['reklama_assets_version'] ) ) {
		$version = (string) $shop['reklama_assets_version'];
	} elseif ( ! empty( $shop['reklama_manifest'] ) && is_array( $shop['reklama_manifest'] ) && ! empty( $shop['reklama_manifest']['version'] ) ) {
		$version = (string) $shop['reklama_manifest']['version'];
	} elseif ( defined( 'MTUC_REKLAMA_ASSETS_DATE' ) && '' !== MTUC_REKLAMA_ASSETS_DATE ) {
		$version = (string) MTUC_REKLAMA_ASSETS_DATE;
	}

	$date = mtuc_reklama_version_to_asset_date( $version );

	/**
	 * Filter CDN asset date suffix (YYYYMMDD).
	 *
	 * @param string               $date YYYYMMDD or empty.
	 * @param array<string, mixed> $shop Shop data.
	 */
	return (string) apply_filters( 'mtuc_reklama_assets_date', $date, $shop );
}

/**
 * Convert manifest version to YYYYMMDD asset suffix.
 *
 * @param string $version Manifest or date version string.
 * @return string
 */
function mtuc_reklama_version_to_asset_date( string $version ): string {
	$version = trim( $version );
	if ( '' === $version ) {
		return '';
	}

	if ( preg_match( '/(\d{4})-(\d{2})-(\d{2})/', $version, $matches ) ) {
		return $matches[1] . $matches[2] . $matches[3];
	}

	if ( preg_match( '/^\d{8}$/', $version ) ) {
		return $version;
	}

	return '';
}

/**
 * CDN /assets/ base URL derived from reklama_manifest_url host.
 *
 * @param array<string, mixed> $shop Shop `data` object.
 * @return string
 */
function mtuc_get_reklama_cdn_assets_base( array $shop ): string {
	$manifest_url = isset( $shop['reklama_manifest_url'] ) ? esc_url_raw( (string) $shop['reklama_manifest_url'] ) : '';
	if ( '' === $manifest_url ) {
		return '';
	}

	$parsed = wp_parse_url( $manifest_url );
	if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
		return '';
	}

	return $parsed['scheme'] . '://' . $parsed['host'] . '/assets';
}

/**
 * Resolve popup image URL via server-side manifest fetch (fallback).
 *
 * @param array<string, mixed> $shop       Shop `data` object.
 * @param int                  $reklama_id Container reklama id.
 * @return string Escaped URL or empty string.
 */
function mtuc_get_reklama_picture_url_from_manifest( array $shop, int $reklama_id ): string {
	$manifest_url = isset( $shop['reklama_manifest_url'] ) ? esc_url_raw( (string) $shop['reklama_manifest_url'] ) : '';
	if ( '' === $manifest_url ) {
		return '';
	}

	$manifest = mtuc_get_reklama_manifest( $manifest_url );
	if ( null === $manifest ) {
		return '';
	}

	$asset_key = 'unim' . $reklama_id;
	if ( empty( $manifest['assets'][ $asset_key ] ) || ! is_string( $manifest['assets'][ $asset_key ] ) ) {
		return '';
	}

	return esc_url_raw( $manifest['assets'][ $asset_key ] );
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
	if ( '' === $backurl ) {
		return null;
	}

	$manifest_url = isset( $shop['reklama_manifest_url'] ) ? esc_url_raw( (string) $shop['reklama_manifest_url'] ) : '';
	$reklama_id   = isset( $shop['uni_container_reklama'] ) ? (int) $shop['uni_container_reklama'] : 0;

	$context = array(
		'backurl'     => $backurl,
		'txt1'        => isset( $shop['uni_container_txt1'] ) ? sanitize_text_field( (string) $shop['uni_container_txt1'] ) : '',
		'txt2'        => isset( $shop['uni_container_txt2'] ) ? sanitize_text_field( (string) $shop['uni_container_txt2'] ) : '',
		'logo_url'    => esc_url( MTUC_PLUGIN_URL . '/images/uni_logo.jpg' ),
		'picture_url' => esc_url( mtuc_resolve_reklama_picture_url( $shop ) ),
		'is_mobile'   => wp_is_mobile(),
	);

	return $context;
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

	wp_enqueue_style(
		'mtuc-reklama',
		MTUC_PLUGIN_URL . '/css/mtuc-reklama.css',
		array(),
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
			<button type="button" class="mtuc-reklama-float" onclick="mtucReklamaOpenUrl('<?php echo esc_js( $backurl ); ?>');">
				<img src="<?php echo esc_url( $context['logo_url'] ); ?>" alt="<?php esc_attr_e( 'УниКредит покупки на Кредит', 'mtunicredit' ); ?>" />
			</button>
		</div>
		<?php
		return;
	}

	?>
	<div class="mtuc-reklama" id="mtuc-reklama">
		<button type="button" class="mtuc-reklama-float" onclick="mtucReklamaToggle();" aria-controls="mtuc-reklama-panel" aria-expanded="false">
			<img src="<?php echo esc_url( $context['logo_url'] ); ?>" alt="<?php esc_attr_e( 'УниКредит покупки на Кредит', 'mtunicredit' ); ?>" />
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
					<a href="<?php echo esc_url( $backurl ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'ИНФОРМАЦИЯ ЗА ОНЛАЙН ПАЗАРУВАНЕ НА КРЕДИТ!', 'mtunicredit' ); ?>
					</a>
				</div>
			</div>
		</div>
	</div>
	<?php
}
