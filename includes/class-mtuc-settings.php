<?php
/**
 * Plugin settings (options API).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings storage and validation.
 */
class Mtuc_Settings {

	/** Option key: module enabled flag (0|1). */
	public const OPTION_STATUS = 'mtuc_status';

	/** Option key: store calculator ID (max 36 chars). */
	public const OPTION_UNICID = 'mtuc_unicid';

	/** Option key: store secret key (max 64 chars). */
	public const OPTION_SECRET_KEY = 'mtuc_secret_key';

	/** Option key: product page hook position. */
	public const OPTION_HOOK = 'mtuc_hook';

	/** Option key: homepage advertisement flag (0|1). */
	public const OPTION_REKLAMA = 'mtuc_reklama';

	/** Option key: direct add-to-cart from calculator (0|1). */
	public const OPTION_CART = 'mtuc_cart';

	/** Option key: debug mode flag (0|1). */
	public const OPTION_DEBUG = 'mtuc_debug';

	/** Option key: margin above button in px (0–200). */
	public const OPTION_GAP = 'mtuc_gap';

	/** Default WooCommerce hook for the product button. */
	public const DEFAULT_HOOK = 'woocommerce_after_add_to_cart_button';

	/**
	 * All option keys managed by the plugin.
	 *
	 * @return string[]
	 */
	public static function get_option_keys(): array {
		return array(
			self::OPTION_STATUS,
			self::OPTION_UNICID,
			self::OPTION_SECRET_KEY,
			self::OPTION_HOOK,
			self::OPTION_REKLAMA,
			self::OPTION_CART,
			self::OPTION_DEBUG,
			self::OPTION_GAP,
		);
	}

	/**
	 * Default values applied on activation (only for keys without existing value).
	 *
	 * @return array<string, int|string>
	 */
	public static function get_defaults(): array {
		return array(
			self::OPTION_STATUS  => 1,
			self::OPTION_HOOK    => self::DEFAULT_HOOK,
			self::OPTION_REKLAMA => 0,
			self::OPTION_CART    => 0,
			self::OPTION_DEBUG   => 0,
			self::OPTION_GAP     => 0,
		);
	}

	/**
	 * Allowed product-page hook positions.
	 *
	 * @return array<string, string>
	 */
	public static function get_hook_choices(): array {
		return array(
			'woocommerce_after_add_to_cart_button'      => __( 'Под бутона Купи', 'mtunicredit' ),
			'woocommerce_before_single_product'         => __( 'В началото', 'mtunicredit' ),
			'woocommerce_before_single_product_summary' => __( 'Преди информацията за продукта', 'mtunicredit' ),
			'woocommerce_single_product_summary'        => __( 'До информацията за продукта', 'mtunicredit' ),
			'woocommerce_before_add_to_cart_button'     => __( 'Над бутона Купи', 'mtunicredit' ),
			'woocommerce_before_add_to_cart_form'       => __( 'Над бутона Купи', 'mtunicredit' ),
			'woocommerce_after_single_product_summary'  => __( 'Над формата за купуване на продукта', 'mtunicredit' ),
			'woocommerce_after_add_to_cart_form'        => __( 'Под формата за купуване на продукта', 'mtunicredit' ),
			'woocommerce_product_meta_start'            => __( 'Над допълнителната информация', 'mtunicredit' ),
			'woocommerce_product_meta_end'              => __( 'Под допълнителната информация', 'mtunicredit' ),
			'woocommerce_share'                         => __( 'До споделената информация', 'mtunicredit' ),
			'woocommerce_after_single_product'          => __( 'В края', 'mtunicredit' ),
		);
	}

	/**
	 * Read a single option with default fallback.
	 *
	 * @param string $key Option key.
	 * @return mixed
	 */
	public static function get( string $key ) {
		$defaults = self::get_defaults();
		$default  = array_key_exists( $key, $defaults ) ? $defaults[ $key ] : '';

		return get_option( $key, $default );
	}

	/**
	 * Read all plugin options for the admin form.
	 *
	 * @return array<string, mixed>
	 */
	public static function get_all(): array {
		$values = array();

		foreach ( self::get_option_keys() as $key ) {
			$values[ $key ] = self::get( $key );
		}

		if ( '' === $values[ self::OPTION_UNICID ] && false === get_option( self::OPTION_UNICID, false ) ) {
			$values[ self::OPTION_UNICID ] = '';
		}

		if ( '' === $values[ self::OPTION_SECRET_KEY ] && false === get_option( self::OPTION_SECRET_KEY, false ) ) {
			$values[ self::OPTION_SECRET_KEY ] = '';
		}

		return $values;
	}

	/**
	 * Whether the module is enabled.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return 1 === (int) self::get( self::OPTION_STATUS );
	}

	/**
	 * Set default options on first activation.
	 *
	 * @return void
	 */
	public static function install_defaults(): void {
		foreach ( self::get_defaults() as $key => $value ) {
			if ( false === get_option( $key, false ) ) {
				add_option( $key, $value, '', false );
			}
		}
	}

	/**
	 * Delete all plugin options (uninstall).
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		foreach ( self::get_option_keys() as $option ) {
			delete_option( $option );
			delete_site_option( $option );
		}

		Mtuc_Cp_Api_Client::clear_token();
		Mtuc_Shop_Cache::drop_table();
		delete_option( 'mtuc_db_version' );
		delete_site_option( 'mtuc_db_version' );

		wp_cache_flush();
	}

	/**
	 * Validate and persist settings from admin POST.
	 *
	 * @param array<string, mixed> $post Raw POST data.
	 * @return true|WP_Error
	 */
	public static function save_from_post( array $post ) {
		$unicid = isset( $post[ self::OPTION_UNICID ] )
			? sanitize_text_field( wp_unslash( $post[ self::OPTION_UNICID ] ) )
			: '';

		if ( '' === $unicid ) {
			return new WP_Error(
				'mtuc_unicid_required',
				__( 'Полето „Уникален идентификационен код на магазина Ви“ е задължително.', 'mtunicredit' )
			);
		}

		if ( strlen( $unicid ) > 36 ) {
			return new WP_Error(
				'mtuc_unicid_length',
				__( 'Идентификационният код не може да надвишава 36 символа.', 'mtunicredit' )
			);
		}

		$secret_key = isset( $post[ self::OPTION_SECRET_KEY ] )
			? sanitize_text_field( wp_unslash( $post[ self::OPTION_SECRET_KEY ] ) )
			: '';

		$stored_secret = get_option( self::OPTION_SECRET_KEY, '' );

		if ( '' === $secret_key ) {
			if ( '' === $stored_secret ) {
				return new WP_Error(
					'mtuc_secret_required',
					__( 'Полето „Секретен код на магазина Ви“ е задължително.', 'mtunicredit' )
				);
			}
			$secret_key = $stored_secret;
		} elseif ( strlen( $secret_key ) > 64 ) {
			return new WP_Error(
				'mtuc_secret_length',
				__( 'Секретният код не може да надвишава 64 символа.', 'mtunicredit' )
			);
		}

		$hook = isset( $post[ self::OPTION_HOOK ] )
			? sanitize_text_field( wp_unslash( $post[ self::OPTION_HOOK ] ) )
			: self::DEFAULT_HOOK;

		$hook_choices = self::get_hook_choices();
		if ( ! array_key_exists( $hook, $hook_choices ) ) {
			$hook = self::DEFAULT_HOOK;
		}

		$gap = isset( $post[ self::OPTION_GAP ] ) ? absint( $post[ self::OPTION_GAP ] ) : 0;
		if ( $gap > 200 ) {
			$gap = 200;
		}

		update_option( self::OPTION_STATUS, self::post_flag_to_int( $post, self::OPTION_STATUS ) );
		update_option( self::OPTION_UNICID, $unicid );
		update_option( self::OPTION_SECRET_KEY, $secret_key );
		update_option( self::OPTION_HOOK, $hook );
		update_option( self::OPTION_REKLAMA, self::post_flag_to_int( $post, self::OPTION_REKLAMA ) );
		update_option( self::OPTION_CART, self::post_flag_to_int( $post, self::OPTION_CART ) );
		update_option( self::OPTION_DEBUG, self::post_flag_to_int( $post, self::OPTION_DEBUG ) );
		update_option( self::OPTION_GAP, $gap );

		return true;
	}

	/**
	 * Normalize checkbox POST value to 0 or 1.
	 *
	 * @param array<string, mixed> $post POST data.
	 * @param string               $key  Field name.
	 * @return int
	 */
	private static function post_flag_to_int( array $post, string $key ): int {
		if ( ! isset( $post[ $key ] ) ) {
			return 0;
		}

		return 1 === (int) $post[ $key ] ? 1 : 0;
	}
}
