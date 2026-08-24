<?php
/**
 * Minimal WordPress stubs for certificate unit tests (no WP bootstrap).
 *
 * @package MTUC
 */

define( 'ABSPATH', __DIR__ . '/../' );
define( 'MTUC_PLUGIN_DIR', realpath( __DIR__ . '/..' ) );
putenv( 'MTUC_SMARTUCF_KEY_PASSWORD=1234' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', false );

require_once MTUC_PLUGIN_DIR . '/includes/mtuc-secrets.php';

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal WP_Error stub.
	 */
	class WP_Error {
		/** @var string */
		private $code;
		/** @var string */
		private $message;
		/** @var mixed */
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = (string) $code;
			$this->message = (string) $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message( $code = '' ) {
			return $this->message;
		}

		public function get_error_data( $code = '' ) {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	/**
	 * @param mixed $thing Value.
	 * @return bool
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text Text.
	 * @param string $domain Domain.
	 * @return string
	 */
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * @param string $value Path.
	 * @return string
	 */
	function trailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	/**
	 * @param string $value Path.
	 * @return string
	 */
	function untrailingslashit( $value ) {
		return rtrim( (string) $value, '/\\' );
	}
}

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	/**
	 * @param string $target Directory.
	 * @return bool
	 */
	function wp_mkdir_p( $target ) {
		return is_dir( $target ) || mkdir( $target, 0755, true );
	}
}

if ( ! function_exists( 'wp_generate_password' ) ) {
	/**
	 * @param int  $length Length.
	 * @param bool $special Special chars.
	 * @param bool $extra Extra special.
	 * @return string
	 */
	function wp_generate_password( $length = 12, $special = true, $extra = false ) {
		return substr( bin2hex( random_bytes( 16 ) ), 0, max( 1, (int) $length ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'mtuc_is_yes_flag' ) ) {
	/**
	 * @param mixed $value Flag.
	 * @return bool
	 */
	function mtuc_is_yes_flag( $value ): bool {
		return 1 === (int) $value || '1' === (string) $value || true === $value || 'yes' === $value;
	}
}

require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-certificate-pair-validator.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-certificate-consumer-lease.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-certificate-local-store.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-certificate-synchronizer.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-smartucf-endpoint-policy.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-smartucf-api-client.php';

if ( ! class_exists( 'Mtuc_Debug_Log', false ) ) {
	/**
	 * No-op debug journal stub for unit tests.
	 */
	class Mtuc_Debug_Log {
		/**
		 * @param string $request_body Request.
		 * @param string $response_body Response.
		 * @param int    $http_code HTTP code.
		 * @param int    $wc_order_id Order ID.
		 * @return void
		 */
		public static function log_smartucf_session( string $request_body, string $response_body, int $http_code = 0, int $wc_order_id = 0 ): void {
		}
	}
}
