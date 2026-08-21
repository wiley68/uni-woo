<?php
/**
 * Ensures the local UniCredit client certificate pair matches Control Panel.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Certificate synchronization before mTLS SmartUCF create-session.
 */
class Mtuc_Certificate_Synchronizer {

	/**
	 * Optional test double for metadata fetch.
	 *
	 * @var callable|null
	 */
	public static $metadata_fetcher = null;

	/**
	 * Optional test double for bundle download.
	 *
	 * @var callable|null
	 */
	public static $bundle_fetcher = null;

	/**
	 * Ensure a current certificate lease when uni_sertificat is enabled.
	 *
	 * @param array<string, mixed> $shop Shop `data` from CP.
	 * @return Mtuc_Certificate_Consumer_Lease|null|WP_Error Null when certificates are disabled.
	 */
	public static function ensure_current( array $shop ) {
		if ( ! mtuc_is_yes_flag( $shop['uni_sertificat'] ?? 0 ) ) {
			return null;
		}

		$metadata = is_callable( self::$metadata_fetcher )
			? call_user_func( self::$metadata_fetcher )
			: Mtuc_Cp_Api_Client::get_ssl_certificate_metadata();

		if ( is_wp_error( $metadata ) ) {
			return self::handle_metadata_error( $metadata );
		}

		if ( empty( $metadata['available'] ) ) {
			self::log_event(
				'ssl_certificate_unavailable',
				array( 'reason' => 'cp_available_false' )
			);

			return new WP_Error(
				'mtuc_ssl_certificate_unavailable',
				__( 'Контролният панел няма наличен SSL сертификат за магазина.', 'mtunicredit' )
			);
		}

		$cert_hash = isset( $metadata['certificate_sha256'] ) ? (string) $metadata['certificate_sha256'] : '';
		$key_hash  = isset( $metadata['private_key_sha256'] ) ? (string) $metadata['private_key_sha256'] : '';

		if ( ! Mtuc_Certificate_Pair_Validator::is_valid_sha256( $cert_hash )
			|| ! Mtuc_Certificate_Pair_Validator::is_valid_sha256( $key_hash )
		) {
			return new WP_Error(
				'mtuc_ssl_metadata_invalid',
				__( 'КП върна невалидни SHA-256 хешове за SSL сертификата.', 'mtunicredit' )
			);
		}

		$lock = Mtuc_Certificate_Local_Store::acquire_lock( 10 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}

		try {
			$local = self::inspect_local_pair();
			if ( $local['valid']
				&& $local['cert_hash'] === $cert_hash
				&& $local['key_hash'] === $key_hash
			) {
				self::log_event(
					'ssl_metadata_match',
					array(
						'revision'    => (string) ( $metadata['ssl_revision'] ?? '' ),
						'cert_prefix' => substr( $cert_hash, 0, 12 ),
						'key_prefix'  => substr( $key_hash, 0, 12 ),
					)
				);

				return Mtuc_Certificate_Local_Store::create_lease( $local['cert_pem'], $local['key_pem'] );
			}

			self::log_event(
				'ssl_refresh_required',
				array(
					'revision'      => (string) ( $metadata['ssl_revision'] ?? '' ),
					'local_valid'   => $local['valid'] ? 1 : 0,
					'cert_mismatch' => ( $local['cert_hash'] !== $cert_hash ) ? 1 : 0,
					'key_mismatch'  => ( $local['key_hash'] !== $key_hash ) ? 1 : 0,
				)
			);

			$bundle = is_callable( self::$bundle_fetcher )
				? call_user_func( self::$bundle_fetcher )
				: Mtuc_Cp_Api_Client::download_ssl_certificate_bundle();
			if ( is_wp_error( $bundle ) ) {
				return $bundle;
			}

			$validated = self::validate_bundle( $bundle, $cert_hash, $key_hash );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$replaced = Mtuc_Certificate_Local_Store::replace_pair(
				$validated['cert_pem'],
				$validated['key_pem']
			);
			if ( is_wp_error( $replaced ) ) {
				return $replaced;
			}

			self::log_event(
				'ssl_refresh_performed',
				array(
					'revision'    => (string) ( $validated['ssl_revision'] ?? '' ),
					'cert_prefix' => substr( $validated['certificate_sha256'], 0, 12 ),
					'key_prefix'  => substr( $validated['private_key_sha256'], 0, 12 ),
					'not_after'   => (string) ( $validated['not_after'] ?? '' ),
				)
			);

			return Mtuc_Certificate_Local_Store::create_lease(
				$validated['cert_pem'],
				$validated['key_pem']
			);
		} finally {
			Mtuc_Certificate_Local_Store::release_lock();
		}
	}

	/**
	 * Handle CP metadata transport/auth failures (fail-open vs fail-closed).
	 *
	 * @param WP_Error $error Metadata fetch error.
	 * @return Mtuc_Certificate_Consumer_Lease|WP_Error
	 */
	private static function handle_metadata_error( WP_Error $error ) {
		$error_code = $error->get_error_code();
		if ( 'mtuc_ssl_certificate_unavailable' === $error_code
			|| 'ssl_certificate_unavailable' === $error_code
		) {
			self::log_event(
				'ssl_certificate_unavailable',
				array( 'reason' => 'cp_explicit' )
			);

			return new WP_Error(
				'mtuc_ssl_certificate_unavailable',
				__( 'Контролният панел няма наличен SSL сертификат за магазина.', 'mtunicredit' )
			);
		}

		$local = self::inspect_local_pair();
		if ( $local['valid'] ) {
			self::log_event(
				'ssl_fail_open_cp_unavailable',
				array(
					'error_code'  => $error_code,
					'cert_prefix' => substr( (string) $local['cert_hash'], 0, 12 ),
				)
			);

			return Mtuc_Certificate_Local_Store::create_lease( $local['cert_pem'], $local['key_pem'] );
		}

		self::log_event(
			'ssl_fail_closed_cp_unavailable',
			array(
				'error_code'  => $error_code,
				'local_valid' => 0,
			)
		);

		return new WP_Error(
			'mtuc_ssl_sync_failed',
			__( 'SSL сертификатът не може да бъде синхронизиран и локалната двойка е невалидна.', 'mtunicredit' )
		);
	}

	/**
	 * Inspect local authoritative pair without logging PEM contents.
	 *
	 * @return array{valid:bool,cert_pem:string,key_pem:string,cert_hash:string,key_hash:string,error:?string}
	 */
	private static function inspect_local_pair(): array {
		$empty = array(
			'valid'     => false,
			'cert_pem'  => '',
			'key_pem'   => '',
			'cert_hash' => '',
			'key_hash'  => '',
			'error'     => null,
		);

		$pair = Mtuc_Certificate_Local_Store::read_pair();
		if ( is_wp_error( $pair ) ) {
			$empty['error'] = $pair->get_error_code();
			return $empty;
		}

		$valid = Mtuc_Certificate_Pair_Validator::validate_pair( $pair['cert_pem'], $pair['key_pem'] );
		if ( is_wp_error( $valid ) ) {
			return array(
				'valid'     => false,
				'cert_pem'  => $pair['cert_pem'],
				'key_pem'   => $pair['key_pem'],
				'cert_hash' => Mtuc_Certificate_Pair_Validator::sha256_hex( $pair['cert_pem'] ),
				'key_hash'  => Mtuc_Certificate_Pair_Validator::sha256_hex( $pair['key_pem'] ),
				'error'     => $valid->get_error_code(),
			);
		}

		return array(
			'valid'     => true,
			'cert_pem'  => $pair['cert_pem'],
			'key_pem'   => $pair['key_pem'],
			'cert_hash' => Mtuc_Certificate_Pair_Validator::sha256_hex( $pair['cert_pem'] ),
			'key_hash'  => Mtuc_Certificate_Pair_Validator::sha256_hex( $pair['key_pem'] ),
			'error'     => null,
		);
	}

	/**
	 * Validate a downloaded SSL bundle against expected metadata hashes.
	 *
	 * @param array<string, mixed> $bundle            Bundle data from CP.
	 * @param string               $expected_cert_hash Metadata certificate_sha256.
	 * @param string               $expected_key_hash  Metadata private_key_sha256.
	 * @return array{cert_pem:string,key_pem:string,ssl_revision:string,certificate_sha256:string,private_key_sha256:string,not_before:string,not_after:string}|WP_Error
	 */
	private static function validate_bundle( array $bundle, string $expected_cert_hash, string $expected_key_hash ) {
		$cert_pem = isset( $bundle['certificate_pem'] ) ? (string) $bundle['certificate_pem'] : '';
		$key_pem  = isset( $bundle['private_key_pem'] ) ? (string) $bundle['private_key_pem'] : '';

		if ( '' === $cert_pem || '' === $key_pem ) {
			return new WP_Error(
				'mtuc_ssl_bundle_invalid',
				__( 'КП върна непълен SSL bundle.', 'mtunicredit' )
			);
		}

		$bundle_cert_hash = isset( $bundle['certificate_sha256'] ) ? (string) $bundle['certificate_sha256'] : '';
		$bundle_key_hash  = isset( $bundle['private_key_sha256'] ) ? (string) $bundle['private_key_sha256'] : '';

		if ( ! Mtuc_Certificate_Pair_Validator::is_valid_sha256( $bundle_cert_hash )
			|| ! Mtuc_Certificate_Pair_Validator::is_valid_sha256( $bundle_key_hash )
		) {
			return new WP_Error(
				'mtuc_ssl_bundle_invalid',
				__( 'КП върна невалидни SHA-256 хешове в SSL bundle.', 'mtunicredit' )
			);
		}

		if ( $bundle_cert_hash !== $expected_cert_hash || $bundle_key_hash !== $expected_key_hash ) {
			return new WP_Error(
				'mtuc_ssl_bundle_hash_mismatch',
				__( 'SHA-256 хешовете в SSL bundle не съвпадат с метаданните.', 'mtunicredit' )
			);
		}

		$actual_cert_hash = Mtuc_Certificate_Pair_Validator::sha256_hex( $cert_pem );
		$actual_key_hash  = Mtuc_Certificate_Pair_Validator::sha256_hex( $key_pem );

		if ( $actual_cert_hash !== $bundle_cert_hash ) {
			return new WP_Error(
				'mtuc_ssl_bundle_cert_hash_mismatch',
				__( 'SHA-256 на изтегления сертификат не съвпада.', 'mtunicredit' )
			);
		}

		if ( $actual_key_hash !== $bundle_key_hash ) {
			return new WP_Error(
				'mtuc_ssl_bundle_key_hash_mismatch',
				__( 'SHA-256 на изтегления частен ключ не съвпада.', 'mtunicredit' )
			);
		}

		$valid = Mtuc_Certificate_Pair_Validator::validate_pair( $cert_pem, $key_pem );
		if ( is_wp_error( $valid ) ) {
			return new WP_Error(
				'mtuc_ssl_bundle_invalid',
				$valid->get_error_message(),
				array( 'cause' => $valid->get_error_code() )
			);
		}

		return array(
			'cert_pem'             => $cert_pem,
			'key_pem'              => $key_pem,
			'ssl_revision'         => isset( $bundle['ssl_revision'] ) ? (string) $bundle['ssl_revision'] : '',
			'certificate_sha256'   => $bundle_cert_hash,
			'private_key_sha256'   => $bundle_key_hash,
			'not_before'           => isset( $bundle['not_before'] ) ? (string) $bundle['not_before'] : '',
			'not_after'            => isset( $bundle['not_after'] ) ? (string) $bundle['not_after'] : '',
		);
	}

	/**
	 * Sanitized certificate sync diagnostics (never logs PEM).
	 *
	 * @param string               $event Event name.
	 * @param array<string, mixed> $context Safe context fields.
	 * @return void
	 */
	private static function log_event( string $event, array $context = array() ): void {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			return;
		}

		$safe = array();
		foreach ( $context as $key => $value ) {
			$key = (string) $key;
			if ( false !== stripos( $key, 'pem' )
				|| false !== stripos( $key, 'token' )
				|| false !== stripos( $key, 'pass' )
				|| false !== stripos( $key, 'secret' )
			) {
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				$safe[ $key ] = $value;
			}
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'MTUC SSL sync: ' . $event . ' ' . wp_json_encode( $safe ) );
	}
}
