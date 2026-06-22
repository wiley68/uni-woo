<?php

/**
 * Intelephense stubs for WordPress and WooCommerce APIs used by УНИ Кредит.
 *
 * This file is not loaded at runtime. It exists only so the IDE can resolve
 * symbols when analyzing the plugin outside of a full WordPress install.
 *
 * @package MTUC
 * @see https://github.com/bmewburn/vscode-intelephense/wiki/Stub-files
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting.FileComment.Missing

// WP/WC functions: this file (includePaths). intelephense.stubs "wordpress" is off
// because its untyped stubs override return types here (e.g. sanitize_text_field).

const ABSPATH           = '/';
const WP_CONTENT_URL    = 'https://example.com/wp-content';
const WP_PLUGIN_URL     = 'https://example.com/wp-content/plugins';
const DB_NAME           = 'wordpress';
const AUTH_SALT         = '';
const SECURE_AUTH_SALT  = '';
const LOGGED_IN_SALT    = '';
const NONCE_SALT        = '';
const HOUR_IN_SECONDS   = 3600;
const MINUTE_IN_SECONDS = 60;
const DAY_IN_SECONDS    = 86400;
const OBJECT            = 'OBJECT';
const ARRAY_A           = 'ARRAY_A';
const ARRAY_N           = 'ARRAY_N';

// --- WordPress globals ------------------------------------------------------

/**
 * @var wpdb $wpdb
 */
global $wpdb;

// --- WordPress core ---------------------------------------------------------

function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {}
function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {}
function remove_action($hook_name, $callback, $priority = 10) {}
function remove_filter($hook_name, $callback, $priority = 10) {}
function do_action($hook_name, ...$args) {}
/**
 * @template T
 * @param T $value
 * @return T
 */
function apply_filters($hook_name, $value, ...$args): mixed
{
	return $value;
}

function __(string $text, string $domain = 'default'): string
{
	return $text;
}
function _e(string $text, string $domain = 'default'): void {}

/**
 * @return array{0: string, 1: string, singular: string, plural: string, domain: string}
 */
function _n_noop(string $singular, string $plural, string $domain = 'default'): array
{
	return array(
		0          => $singular,
		1          => $plural,
		'singular' => $singular,
		'plural'   => $plural,
		'domain'   => $domain,
	);
}

function esc_html(string $text): string
{
	return $text;
}
function esc_html__(string $text, string $domain = 'default'): string
{
	return $text;
}
function esc_html_e(string $text, string $domain = 'default'): void {}

function disabled(mixed $disabled, bool $echo = true): string
{
	unset($disabled, $echo);

	return '';
}

function esc_attr(string $text): string
{
	return $text;
}
function esc_attr__(string $text, string $domain = 'default'): string
{
	return $text;
}
function esc_attr_e(string $text, string $domain = 'default'): void {}
function esc_url(string $url, $protocols = null, string $_context = 'display'): string
{
	return $url;
}
function esc_url_raw(string $url, $protocols = null): string
{
	return $url;
}

/**
 * @param int $component PHP_URL_* constant.
 * @return array<string, mixed>|false|null|string|int
 */
function wp_parse_url( string $url, int $component = -1 ) {
	unset( $url, $component );

	return false;
}

function esc_js(string $text): string
{
	return $text;
}

function plugin_basename($file): string
{
	return '';
}
function plugin_dir_path($file): string
{
	return '';
}
function plugin_dir_url($file)
{
	return '';
}
function plugins_url($path = '', $plugin = '')
{
	return '';
}
function is_plugin_active($plugin) {}
function is_plugin_active_for_network($plugin) {}

/**
 * @template T
 * @param T $default
 * @return T|mixed
 */
function get_option($option, $default = false): mixed
{
	return $default;
}

/**
 * @param string $string
 * @param string $format
 * @return string|false
 */
function get_date_from_gmt($string, $format = 'Y-m-d H:i:s')
{
	unset($string, $format);

	return '';
}

function update_option($option, $value, $autoload = null) {}
function add_option($option, $value, $deprecated = '', $autoload = 'yes') {}
function delete_option($option) {}
function get_site_option($option, $default = false) {}
function delete_site_option($option) {}

/**
 * @param string $type 'mysql' or other.
 * @param bool   $gmt  Whether to use GMT.
 * @return int|string
 */
function current_time( $type, $gmt = false ) {
	unset( $type, $gmt );

	return '';
}

function determine_locale() {}
function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false) {}
function load_textdomain($domain, $mofile) {}

function register_post_status($post_status, $args = array()) {}
function add_meta_box($id, $title, $callback, $screen = null, $context = 'advanced', $priority = 'default', $callback_args = null) {}
function add_options_page($page_title, $menu_title, $capability, $menu_slug, $callback = '', $position = null) {}

function get_admin_page_title(): string
{
	return '';
}

function submit_button(string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true, array $other_attributes = array()): void {}

function admin_url(string $path = '', string $scheme = 'admin'): string
{
	return '';
}

function home_url(string $path = '', ?string $scheme = null): string
{
	unset($scheme);

	return '';
}

function site_url(string $path = '', ?string $scheme = null): string
{
	unset($scheme);

	return '';
}

function rest_url(string $path = '', string $scheme = 'rest'): string
{
	unset($scheme);

	return '';
}

/**
 * @param array<string, mixed> $args
 */
function register_rest_route(string $route_namespace, string $route, array $args = array(), bool $override = false): bool
{
	unset($route_namespace, $route, $args, $override);

	return true;
}

class WP_REST_Server
{
	public const READABLE   = 'GET';
	public const CREATABLE  = 'POST';
	public const EDITABLE   = 'POST, PUT, PATCH';
	public const DELETABLE  = 'DELETE';
	public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
}

class WP_REST_Request
{
	/** @return mixed */
	public function get_json_params() {}

	/** @return mixed */
	public function get_param(string $key) {}
}

class WP_REST_Response
{
	/** @param mixed $data */
	public function __construct($data = null, int $status = 200) {}
}

function get_site_url($blog_id = null, string $path = '', ?string $scheme = null): string
{
	unset($blog_id, $scheme);

	return '';
}

/**
 * @return string|false Sanitized email, or false if invalid.
 */
function is_email(string $email): string|false
{
	return $email;
}
function add_query_arg(...$args): string
{
	return '';
}

function wp_die($message = '', $title = '', $args = array()) {}
function wp_safe_redirect($location, $status = 302, $x_redirect_by = 'WordPress') {}
function wp_redirect($location, $status = 302, $x_redirect_by = 'WordPress') {}

function status_header(int $code, string $description = ''): void
{
	unset($code, $description);
}

function nocache_headers(): void {}

function wp_head(): void {}

function wp_footer(): void {}

function wp_send_json($response, $status_code = null): never
{
	exit;
}
function wp_send_json_success($value = null, $status_code = null): never
{
	exit;
}
function wp_send_json_error($value = null, $status_code = null): never
{
	exit;
}

function wp_create_nonce(string|int $action = -1): string
{
	unset($action);

	return '';
}
function wp_verify_nonce($nonce, $action = -1) {}
function wp_nonce_field($action = -1, $name = '_wpnonce', $referer = true, $display = true) {}
function check_ajax_referer($action = -1, $query_arg = false, $die = true) {}
function check_admin_referer($action = -1, $query_arg = '_wpnonce') {}

function current_user_can($capability, ...$args) {}

/**
 * @return int User ID, or 0 if not logged in.
 */
function get_current_user_id(): int
{
	return 0;
}

function is_user_logged_in() {}
function is_admin() {}
function is_front_page() {}
function get_queried_object_id(): int
{
	return 0;
}
function wp_is_mobile(): bool
{
	return false;
}
function is_ssl() {}

function wp_doing_ajax(): bool
{
	return false;
}

/**
 * @template T
 * @param T $value
 * @return T
 */
function wp_unslash($value): mixed
{
	return $value;
}

function sanitize_text_field(string $str): string
{
	return $str;
}

/**
 * @param string $key
 * @return string
 */
function sanitize_key( $key ): string
{
	return (string) $key;
}

function sanitize_email($email) {}
function absint($maybeint) {}

function wp_enqueue_script($handle, $src = '', $deps = array(), $ver = false, $in_footer = false) {}
function wp_enqueue_style($handle, $src = '', $deps = array(), $ver = false, $media = 'all') {}
function wp_register_script($handle, $src, $deps = array(), $ver = false, $in_footer = false) {}
function wp_register_style($handle, $src, $deps = array(), $ver = false, $media = 'all') {}
function wp_localize_script($handle, $object_name, $l10n) {}

/**
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_post($url, $args = array()): array|WP_Error
{
	return array('body' => '', 'response' => array('code' => 200));
}

/**
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_get($url, $args = array()): array|WP_Error
{
	return array('body' => '', 'response' => array('code' => 200));
}

/**
 * @return array<string, mixed>|WP_Error
 */
function wp_remote_request($url, $args = array()): array|WP_Error
{
	return array('body' => '', 'response' => array('code' => 200));
}

function wp_remote_retrieve_body(array|WP_Error $response): string
{
	unset($response);

	return '';
}

function wp_remote_retrieve_response_code(array|WP_Error $response): int
{
	unset($response);

	return 200;
}

function is_wp_error(mixed $thing): bool
{
	unset($thing);

	return false;
}

function wp_json_encode($value, int $flags = 0, int $depth = 512): string
{
	return '{}';
}
function wp_parse_str($string, &$array) {}
function wp_cache_flush() {}
function wp_cache_delete($key, $group = '') {}
function wp_cache_set($key, $data, $group = '', $expire = 0) {}
function wp_salt(string $scheme = 'auth'): string
{
	unset($scheme);

	return '';
}

function get_post_meta(int $post_id, string $key = '', bool $single = false): mixed
{
	unset($post_id, $key);

	return $single ? '' : array();
}

function update_post_meta(int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = ''): int|false
{
	unset($post_id, $meta_key, $meta_value, $prev_value);

	return 0;
}

function delete_post_meta(int $post_id, string $meta_key, mixed $meta_value = ''): bool
{
	unset($post_id, $meta_key, $meta_value);

	return false;
}

/**
 * @param array<string, mixed>|null $args
 * @return array<int, WP_Post>
 */
function get_posts($args = null): array
{
	unset($args);

	return array();
}

/**
 * @return mixed|false
 */
function get_transient( string $transient ): mixed
{
	unset( $transient );

	return false;
}

/**
 * @param mixed $value
 * @return bool
 */
function set_transient( string $transient, $value, int $expiration = 0 ): bool
{
	unset( $transient, $value, $expiration );

	return false;
}

/**
 * @return bool
 */
function delete_transient( string $transient ): bool
{
	unset( $transient );

	return false;
}

function get_current_screen(): ?WP_Screen
{
	return new WP_Screen();
}
function dbDelta($queries = '', $execute = true) {}
function untrailingslashit(string $string): string
{
	return $string;
}
function trailingslashit(string $string): string
{
	return $string;
}

/**
 * @return WP_User
 */
function wp_get_current_user(): WP_User
{
	return new WP_User();
}
function wp_kses(string $content, array $allowed_html, array $allowed_protocols = array()): string
{
	unset($allowed_html, $allowed_protocols);

	return $content;
}

function wp_kses_post(string $content): string
{
	return $content;
}

function wpautop(string $text, bool $br = true): string
{
	unset($br);

	return $text;
}

function wptexturize(string $text, bool $reset = false): string
{
	unset($reset);

	return $text;
}

function get_header($name = null, $args = array()) {}
function get_footer($name = null, $args = array()) {}

class WP_Error
{
	public function __construct( $code = '', $message = '', $data = '' ) {}

	public function get_error_code(): string
	{
		return '';
	}

	public function get_error_message( $code = '' ): string
	{
		unset( $code );

		return '';
	}

	public function get_error_data( $code = '' ): mixed
	{
		unset( $code );

		return null;
	}
}

class WP_Post
{
	/** @var int */
	public $ID;
}

class WP_User
{
	/** @var int */
	public $ID;

	/** @var string */
	public $first_name = '';

	/** @var string */
	public $last_name = '';

	/** @var string */
	public $user_email = '';
}

class WP_Screen
{
	public string $id = '';
	public string $base = '';
	public string $post_type = '';
	public string $parent_base = '';
	public string $parent_file = '';
	public string $taxonomy = '';
	public string $action = '';
}

class wpdb
{
	public string $prefix = '';
	public int $insert_id = 0;
	public string $last_error = '';

	public function prepare(string $query, mixed ...$args): string|false
	{
		unset($query, $args);

		return false;
	}

	/**
	 * @param array<string, mixed>        $data
	 * @param array<int, string>|string|null $format
	 */
	public function insert(string $table, array $data, array|string|null $format = null): int|false
	{
		unset($table, $data, $format);

		return false;
	}

	/**
	 * @param array<string, mixed>        $data
	 * @param array<string, mixed>        $where
	 * @param array<int, string>|string|null $format
	 * @param array<int, string>|string|null $where_format
	 */
	public function update(string $table, array $data, array $where, array|string|null $format = null, array|string|null $where_format = null): int|false
	{
		unset($table, $data, $where, $format, $where_format);

		return false;
	}

	/**
	 * @param array<string, mixed>        $where
	 * @param array<int, string>|string|null $where_format
	 */
	public function delete(string $table, array $where, array|string|null $where_format = null): int|false
	{
		unset($table, $where, $where_format);

		return false;
	}
	/** @return array<int, object>|null */
	public function get_results(?string $query = null, string $output = 'OBJECT')
	{
		unset($query, $output);

		return null;
	}
	/** @return object|null */
	public function get_row(?string $query = null, string $output = 'OBJECT', int $y = 0)
	{
		unset($query, $output, $y);

		return null;
	}
	/** @return string|null */
	public function get_var(?string $query = null, int $x = 0, int $y = 0)
	{
		unset($query, $x, $y);

		return null;
	}
	public function get_charset_collate(): string
	{
		return '';
	}

	public function query(?string $query = null): int|bool
	{
		unset($query);

		return false;
	}
}

// --- WooCommerce ------------------------------------------------------------

function wc_get_order($order = false): ?WC_Order
{
	unset($order);

	return null;
}

/**
 * @param array<string, mixed> $args
 * @return WC_Order|WP_Error
 */
function wc_create_order($args = array())
{
	unset($args);

	return new WC_Order();
}

function wc_create_refund($args = array())
{
	unset($args);

	return null;
}
/** @return array<int, object> */
function wc_get_order_notes($args = array()): array
{
	unset($args);

	return array();
}
function wc_get_page_screen_id(string $page): string
{
	unset($page);

	return '';
}
function wc_get_page_permalink(string $page): string
{
	unset($page);

	return '';
}
function wc_get_endpoint_url(string $endpoint, $value = '', $permalink = ''): string
{
	unset($endpoint, $value, $permalink);

	return '';
}
function wc_get_checkout_url(): string
{
	return '';
}
function wc_get_cart_url(): string
{
	return '';
}
function wc_get_account_endpoint_url(string $endpoint): string
{
	unset($endpoint);

	return '';
}
function wc_add_notice($message, $notice_type = 'notice', $data = array()) {}
function wc_reduce_stock_levels($order_id) {}
function wc_get_template($template_name, $args = array(), $template_path = '', $default_path = '') {}
function wc_format_datetime(?DateTimeInterface $datetime, string $format = ''): string
{
	unset($datetime, $format);

	return '';
}
class WC_Container
{
	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get(string $id)
	{
		unset($id);

		return new \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController();
	}
}

function wc_get_container(): WC_Container
{
	return new WC_Container();
}
function wc_get_woocommerce_currency(): string
{
	return '';
}

function wc_get_woocommerce_currency_symbol(string $currency = ''): string
{
	return '';
}

function get_woocommerce_currency(): string
{
	return wc_get_woocommerce_currency();
}

function get_woocommerce_currency_symbol(string $currency = ''): string
{
	return wc_get_woocommerce_currency_symbol($currency);
}

function is_checkout() {}
function is_account_page() {}
function is_wc_endpoint_url($endpoint = '') {}
function is_product() {}

/**
 * @param mixed $product
 * @param array<string, mixed> $args
 */
function wc_get_price_including_tax($product, $args = array()): float
{
	unset($product, $args);

	return 0.0;
}

function wc_prices_include_tax(): bool
{
	return false;
}

/**
 * @param mixed $product
 * @param array<string, mixed> $args
 */
function wc_get_price_excluding_tax($product, $args = array()): float
{
	unset($product, $args);

	return 0.0;
}

/**
 * @param mixed $the_product
 * @param array<string, mixed> $deprecated
 */
function wc_get_product($the_product = false, $deprecated = array()): ?WC_Product
{
	unset($the_product, $deprecated);

	return null;
}

class WC_Product
{
	public function get_id(): int
	{
		return 0;
	}

	public function get_parent_id(): int
	{
		return 0;
	}

	public function get_price(): string
	{
		return '';
	}

	/** @return array<int, int> */
	public function get_category_ids(): array
	{
		return array();
	}

	public function is_type(string $type): bool
	{
		unset($type);

		return false;
	}

	public function is_purchasable(): bool
	{
		return true;
	}

	public function is_in_stock(): bool
	{
		return true;
	}
}

/**
 * @param int|false $customer_id
 * @return WC_Customer|false
 */
function wc_get_customer($customer_id = false)
{
	unset($customer_id);

	return false;
}

class WC_Customer
{
	public function get_billing_first_name(): string
	{
		return '';
	}

	public function get_billing_last_name(): string
	{
		return '';
	}

	public function get_billing_email(): string
	{
		return '';
	}

	public function get_billing_phone(): string
	{
		return '';
	}

	public function get_billing_address_1(): string
	{
		return '';
	}

	public function get_billing_address_2(): string
	{
		return '';
	}

	public function get_billing_city(): string
	{
		return '';
	}

	public function get_billing_state(): string
	{
		return '';
	}

	public function get_billing_postcode(): string
	{
		return '';
	}

	public function get_billing_country(): string
	{
		return '';
	}

	public function get_shipping_first_name(): string
	{
		return '';
	}

	public function get_shipping_last_name(): string
	{
		return '';
	}

	public function get_shipping_address_1(): string
	{
		return '';
	}

	public function get_shipping_address_2(): string
	{
		return '';
	}

	public function get_shipping_city(): string
	{
		return '';
	}

	public function get_shipping_state(): string
	{
		return '';
	}

	public function get_shipping_postcode(): string
	{
		return '';
	}

	public function get_shipping_country(): string
	{
		return '';
	}
}

class WC_Cart
{
	public float $total = 0.0;

	public function is_empty(): bool
	{
		return true;
	}

	public function empty_cart($clear_persistent_cart = true) {}

	/** @return array<string, mixed> */
	public function get_cart(): array
	{
		return array();
	}

	public function add_to_cart(...$args) {}
}

class WC_Payment_Gateways
{
	/**
	 * @return array<string, WC_Payment_Gateway|Borica_Woo_Payment_Gateway>
	 */
	public function payment_gateways(): array
	{
		return array();
	}

	/**
	 * @return array<string, WC_Payment_Gateway>
	 */
	public function get_available_payment_gateways(): array
	{
		return array();
	}
}

class WC_Session
{
	public function get_customer_id(): string
	{
		return '';
	}
}

class WooCommerce
{
	/** @var WC_Cart|null */
	public $cart;

	/** @var WC_Session|null */
	public $session;

	public function payment_gateways(): WC_Payment_Gateways
	{
		return new WC_Payment_Gateways();
	}
}

function WC(): WooCommerce
{
	return new WooCommerce();
}

class WC_Meta_Data
{
	public string $key = '';

	public mixed $value = null;

	/** @return array<string, mixed> */
	public function get_data(): array
	{
		return array();
	}

	public function get_id(): int
	{
		return 0;
	}

	public function get_key(): string
	{
		return $this->key;
	}

	public function get_value(): mixed
	{
		return $this->value;
	}
}

class WC_Order_Item_Product
{
	public function get_product_id(): int
	{
		return 0;
	}

	public function get_quantity(): int
	{
		return 0;
	}

	public function get_total(): string
	{
		return '0';
	}

	public function get_product(): ?WC_Product
	{
		return null;
	}

	public function set_subtotal(float|string $subtotal): void
	{
		unset($subtotal);
	}

	public function set_total(float|string $total): void
	{
		unset($total);
	}

	public function save(): int
	{
		return 0;
	}

	public function get_meta(string $key = '', bool $single = true)
	{
		unset($key, $single);

		return null;
	}

	public function add_meta_data(string $key, mixed $value, bool $unique = false): void
	{
		unset($key, $value, $unique);
	}
}

class WC_DateTime extends DateTime {}

class WC_Order
{
	public function get_id(): int
	{
		return 0;
	}

	public function get_order_key(): string
	{
		return '';
	}

	public function get_status(string $context = 'view'): string
	{
		unset($context);

		return '';
	}

	public function get_total(): string
	{
		return '0';
	}

	public function get_currency(): string
	{
		return '';
	}

	public function get_date_created(): ?WC_DateTime
	{
		return new WC_DateTime();
	}

	public function get_payment_method(): string
	{
		return '';
	}

	public function get_payment_method_title(): string
	{
		return '';
	}

	public function set_payment_method_title(string $title): void
	{
		unset($title);
	}

	public function set_transaction_id(string $transaction_id): void
	{
		unset($transaction_id);
	}

	public function get_customer_id(): int
	{
		return 0;
	}

	public function get_user_id(): int
	{
		return 0;
	}

	public function needs_payment(): bool
	{
		return false;
	}

	/**
	 * @param array<string, string> $address
	 */
	public function set_address(array $address, string $type = 'billing'): void
	{
		unset($address, $type);
	}

	/**
	 * @param WC_Product $product
	 * @param int $quantity
	 * @param array<string, mixed> $args
	 */
	public function add_product($product, int $quantity = 1, array $args = array()): int|false
	{
		unset($product, $quantity, $args);

		return 0;
	}

	public function calculate_totals(bool $and_taxes = true): void
	{
		unset($and_taxes);
	}

	public function set_payment_method(string $payment_method = ''): void
	{
		unset($payment_method);
	}

	public function set_created_via(string $created_via = ''): void
	{
		unset($created_via);
	}

	public function set_status(string $new_status, string $note = '', bool $manual_update = false): void
	{
		unset($new_status, $note, $manual_update);
	}

	public function delete(bool $force_delete = false): bool
	{
		unset($force_delete);

		return true;
	}

	public function save(): int
	{
		return 0;
	}

	public function get_billing_first_name(): string
	{
		return '';
	}

	public function get_billing_last_name(): string
	{
		return '';
	}

	public function get_billing_company(): string
	{
		return '';
	}

	public function get_billing_address_1(): string
	{
		return '';
	}

	public function get_billing_address_2(): string
	{
		return '';
	}

	public function get_billing_city(): string
	{
		return '';
	}

	public function get_billing_state(): string
	{
		return '';
	}

	public function get_billing_postcode(): string
	{
		return '';
	}

	public function get_billing_country(): string
	{
		return '';
	}

	public function get_billing_email(): string
	{
		return '';
	}

	public function get_billing_phone(): string
	{
		return '';
	}

	public function get_shipping_first_name(): string
	{
		return '';
	}

	public function get_shipping_last_name(): string
	{
		return '';
	}

	public function get_shipping_address_1(): string
	{
		return '';
	}

	public function get_shipping_address_2(): string
	{
		return '';
	}

	public function get_shipping_city(): string
	{
		return '';
	}

	public function get_shipping_state(): string
	{
		return '';
	}

	public function get_shipping_postcode(): string
	{
		return '';
	}

	public function get_shipping_country(): string
	{
		return '';
	}

	public function get_formatted_billing_address(): string
	{
		return '';
	}

	public function get_formatted_shipping_address(): string
	{
		return '';
	}

	public function get_formatted_order_total(): string
	{
		return '';
	}

	public function get_order_number(): string
	{
		return '';
	}

	public function get_order_total(): string
	{
		return '0';
	}

	public function get_cancel_order_url(): string
	{
		return '';
	}

	public function get_checkout_payment_url(bool $on_checkout = false): string
	{
		unset($on_checkout);

		return '';
	}

	/** @return WC_Order_Item_Product[] */
	public function get_items($types = 'line_item'): array
	{
		unset($types);

		return array();
	}

	public function get_meta(string $key = '', bool $single = true)
	{
		unset($key, $single);

		return null;
	}

	public function update_meta_data(string $key, mixed $value, int $meta_id = 0): void
	{
		unset($key, $value, $meta_id);
	}

	public function save_meta_data(): void {}

	public function add_order_note(string $note, bool $is_customer_note = false, bool $added_by_user = false): int
	{
		unset($note, $is_customer_note, $added_by_user);

		return 0;
	}

	public function update_status(string $new_status, string $note = '', bool $manual = false): void
	{
		unset($new_status, $note, $manual);
	}

	public function payment_complete(string $transaction_id = ''): void
	{
		unset($transaction_id);
	}

	public function has_status(string|array $status): bool
	{
		unset($status);

		return false;
	}
}

class WC_Payment_Gateway
{
	public $id = '';
	public $method_title = '';
	public $method_description = '';
	public $icon = '';
	public $has_fields = false;
	public $title = '';
	public $description = '';
	public $enabled = 'yes';
	public $order_button_text = '';

	/** Maximum transaction amount; 0 means no limit. */
	public int $max_amount = 0;

	public function init_form_fields() {}
	public function init_settings() {}
	public function get_option($key, $empty_value = null) {}
	public function process_admin_options() {}
	public function is_available() {}

	/** @return array<string, mixed> */
	public function process_payment($order_id) {}

	public function get_return_url($order = null) {}

	public function get_description(): string
	{
		return '';
	}

	protected function get_order_total(): float
	{
		return 0.0;
	}
}

function borica_asset_version(string $relative_path): string
{
	return '';
}
function borica_sanitize_m_info_address(string $value): string
{
	return '';
}
function borica_truncate_for_json_string(string $value, int $max_json_len, int $json_flags = JSON_UNESCAPED_UNICODE): string
{
	return '';
}
function borica_json_value_wire_length(string $value, int $json_flags = JSON_UNESCAPED_UNICODE): int
{
	return 0;
}

class Borica_Woo_Payment_Gateway extends WC_Payment_Gateway
{
	/**
	 * @return array<string, string|int|float>
	 */
	public function build_pay_args_with_recurring(WC_Order $order): array
	{
		unset($order);

		return array();
	}
}

/**
 * MTUC plugin bootstrap helpers.
 */
function mtuc_register_product_popup_ajax_hooks(): void {}
function mtuc_register_product_hooks(): void {}
function mtuc_register_reklama_hooks(): void {}
