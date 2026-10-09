<?php
/**
 * Single-authority Control Panel URL validation and pinned HTTP transport.
 *
 * @package MTUC
 */

namespace MTUC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** All CP destinations are derived exclusively from MTUC_CONTROL_PANEL_URL. */
final class Cp_Destination_Policy {

	/**
	 * Validate an HTTPS origin without consulting a list of CP hostnames.
	 *
	 * @param mixed $value Configured origin.
	 * @return array<string, mixed>|\WP_Error Normalized origin and hostname.
	 */
	public static function validate_base( $value ) {
		if ( ! is_string( $value ) || '' === $value
			|| preg_match( '/[\x00-\x20\x7f\\\\%]/', $value )
			|| false === filter_var( $value, FILTER_VALIDATE_URL )
		) {
			return self::error( 'invalid' );
		}
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] )
			|| 'https' !== strtolower( $parts['scheme'] )
			|| isset( $parts['user'] ) || isset( $parts['pass'] )
			|| array_key_exists( 'query', $parts ) || array_key_exists( 'fragment', $parts )
			|| ( isset( $parts['port'] ) && 443 !== $parts['port'] )
			|| ( isset( $parts['path'] ) && '' !== $parts['path'] && '/' !== $parts['path'] )
		) {
			return self::error( 'invalid' );
		}
		$host = strtolower( $parts['host'] );
		if ( strlen( $host ) > 253 || false !== filter_var( $host, FILTER_VALIDATE_IP )
			|| ! preg_match( '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $host )
			|| preg_match( '/(?:\A|\.)(?:localhost|local|internal|invalid|onion)\z|(?:\A|\.)home\.arpa\z/D', $host )
		) {
			return self::error( 'invalid' );
		}
		$origin = 'https://' . $host . ( isset( $parts['port'] ) ? ':443' : '' );
		return array(
			'origin'   => $origin,
			'host'     => $host,
			'api_base' => $origin . '/api/v1',
		);
	}

	/**
	 * Resolve the canonical value and validate every returned A/AAAA address.
	 *
	 * No DNS result is cached across requests. The pin retains the original URL
	 * hostname for Host, SNI and TLS identity verification.
	 *
	 * @param string $path Relative CP API path.
	 * @return array<string, mixed>|\WP_Error Prepared destination.
	 */
	public static function resolve( string $path ) {
		$base = self::validate_base( defined( 'MTUC_CONTROL_PANEL_URL' ) ? MTUC_CONTROL_PANEL_URL : null );
		if ( is_wp_error( $base ) ) {
			return $base;
		}
		$path = ltrim( $path, '/' );
		if ( ! preg_match( '/\A[a-z0-9]+(?:\/[a-z0-9]+)*\z/D', $path ) ) {
			return self::error( 'invalid' );
		}
		if ( ! function_exists( 'dns_get_record' ) ) {
			return self::error( 'dns' );
		}
		// Absolute DNS name avoids resolver search-domain substitution.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS failure is a closed rejection.
		$records = @dns_get_record( $base['host'] . '.', DNS_A | DNS_AAAA );
		if ( ! is_array( $records ) ) {
			return self::error( 'dns' );
		}
		$addresses = array();
		foreach ( $records as $record ) {
			$type = $record['type'] ?? '';
			if ( 'A' !== $type && 'AAAA' !== $type ) {
				continue;
			}
			$address = 'A' === $type ? ( $record['ip'] ?? '' ) : ( $record['ipv6'] ?? '' );
			if ( ! is_string( $address ) || ! self::is_public_address( $address ) ) {
				return self::error( 'dns' );
			}
			$addresses[] = $address;
		}
		if ( empty( $addresses ) ) {
			return self::error( 'dns' );
		}
		// Prefer IPv4, permitting a checked IPv6 pin when no A answer exists.
		usort(
			$addresses,
			static function ( string $left, string $right ): int {
				return (int) ( false !== strpos( $left, ':' ) ) - (int) ( false !== strpos( $right, ':' ) );
			}
		);
		$base['address'] = $addresses[0];
		$base['url']     = $base['api_base'] . '/' . $path;
		return $base;
	}

	/**
	 * Reject private, reserved, documentation and transition address ranges.
	 *
	 * @param string $address DNS address.
	 * @return bool Whether the address is suitable for direct public egress.
	 */
	public static function is_public_address( string $address ): bool {
		if ( false === filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		if ( false !== strpos( $address, ':' ) ) {
			if ( ! self::in_network( $address, '2000::', 3 ) ) {
				return false;
			}
			$networks = array( '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20' );
		} else {
			$networks = array(
				'0.0.0.0/8',
				'10.0.0.0/8',
				'100.64.0.0/10',
				'127.0.0.0/8',
				'169.254.0.0/16',
				'172.16.0.0/12',
				'192.0.0.0/24',
				'192.0.2.0/24',
				'192.88.99.0/24',
				'192.168.0.0/16',
				'198.18.0.0/15',
				'198.51.100.0/24',
				'203.0.113.0/24',
				'224.0.0.0/3',
			);
		}
		foreach ( $networks as $network ) {
			list( $prefix, $bits ) = explode( '/', $network );
			if ( self::in_network( $address, $prefix, (int) $bits ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Compare binary addresses to an IPv4/IPv6 CIDR prefix.
	 *
	 * @param string $address Address.
	 * @param string $prefix  Network prefix.
	 * @param int    $bits    Prefix length.
	 * @return bool Whether the address lies inside the prefix.
	 */
	private static function in_network( string $address, string $prefix, int $bits ): bool {
		$packed  = inet_pton( $address );
		$network = inet_pton( $prefix );
		if ( false === $packed || false === $network || strlen( $packed ) !== strlen( $network ) ) {
			return false;
		}
		$bytes = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		return substr( $packed, 0, $bytes ) === substr( $network, 0, $bytes )
			&& ( 0 === $rest || ( ord( $packed[ $bytes ] ) & ( 255 << ( 8 - $rest ) ) ) === ( ord( $network[ $bytes ] ) & ( 255 << ( 8 - $rest ) ) ) );
	}

	/**
	 * Send through WordPress using a request-scoped pin and no redirects.
	 *
	 * Proxies and unpinnable transports fail closed. Hooks are always removed.
	 *
	 * @param array<string, mixed> $destination Output of resolve().
	 * @param array<string, mixed> $args        CP HTTP arguments.
	 * @return array<string, mixed>|\WP_Error WordPress HTTP response.
	 */
	public static function send( array $destination, array $args ) {
		if ( ! function_exists( 'curl_init' ) || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return self::error( 'transport' );
		}
		if ( class_exists( '\WP_HTTP_Proxy' ) ) {
			$proxy = new \WP_HTTP_Proxy();
			if ( $proxy->is_enabled() && $proxy->send_through_proxy( $destination['url'] ) ) {
				return self::error( 'transport' );
			}
		}
		$pinned         = false;
		$pin            = static function ( $handle, $request_args, $url ) use ( $destination, &$pinned ): void {
			unset( $request_args );
			if ( $url !== $destination['url'] ) {
				return;
			}
			$address = $destination['address'];
			if ( false !== strpos( $address, ':' ) ) {
				$address = '[' . $address . ']';
			}
			$options = array(
				CURLOPT_URL            => $destination['url'],
				CURLOPT_RESOLVE        => array( $destination['host'] . ':443:' . $address ),
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_MAXREDIRS      => 0,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_PROXY          => '',
			);
			if ( defined( 'CURLOPT_PROTOCOLS' ) ) {
				$options[ CURLOPT_PROTOCOLS ] = CURLPROTO_HTTPS;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array -- Pin the WordPress HTTP handle; do not execute cURL here.
			if ( ! curl_setopt_array( $handle, $options ) ) {
				throw new \RuntimeException( 'CP destination pin failed before HTTP.' );
			}
			$pinned = true;
		};
		$block_unpinned = static function (): void {
			throw new \RuntimeException( 'CP HTTP requires a pinnable cURL transport.' );
		};
		$args['redirection']        = 0;
		$args['reject_unsafe_urls'] = true;
		$args['sslverify']          = true;
		add_action( 'http_api_curl', $pin, PHP_INT_MAX, 3 );
		add_action( 'requests-fsockopen.before_request', $block_unpinned, PHP_INT_MAX );
		try {
			$response = wp_remote_request( $destination['url'], $args );
		} catch ( \RuntimeException $error ) {
			// Once a send boundary was crossed, preserve transport ambiguity.
			$response = $pinned ? new \WP_Error( 'http_request_failed', $error->getMessage() ) : self::error( 'transport' );
		} finally {
			remove_action( 'http_api_curl', $pin, PHP_INT_MAX );
			remove_action( 'requests-fsockopen.before_request', $block_unpinned, PHP_INT_MAX );
		}
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $pinned ? $response : self::error( 'transport' );
	}

	/**
	 * Return a credential-free configuration error, never another hostname.
	 *
	 * @param string $reason invalid, dns or transport.
	 * @return \WP_Error Rejection.
	 */
	private static function error( string $reason ): \WP_Error {
		return new \WP_Error(
			'mtuc_cp_destination_' . $reason,
			__( 'CP destination must be a valid public HTTPS origin with safe DNS and a pinned direct connection.', 'mtunicredit' )
		);
	}
}
