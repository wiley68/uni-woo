<?php
/**
 * Immutable request-scoped certificate lease for one SmartUCF cURL call.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds private temp copies of cert/key for a single SmartUCF HTTP operation.
 */
class Mtuc_Certificate_Consumer_Lease {

	/** @var string Temporary directory (0700). */
	private $dir = '';

	/** @var string Temporary certificate path (0600). */
	private $cert_path = '';

	/** @var string Temporary private key path (0600). */
	private $key_path = '';

	/** @var bool Whether release() already ran. */
	private $released = false;

	/**
	 * @param string $dir       Temp directory path.
	 * @param string $cert_path Temp certificate file path.
	 * @param string $key_path  Temp private key file path.
	 */
	public function __construct( string $dir, string $cert_path, string $key_path ) {
		$this->dir       = $dir;
		$this->cert_path = $cert_path;
		$this->key_path  = $key_path;
	}

	/**
	 * Absolute path to leased certificate PEM.
	 *
	 * @return string
	 */
	public function get_cert_path(): string {
		return $this->cert_path;
	}

	/**
	 * Absolute path to leased private key PEM.
	 *
	 * @return string
	 */
	public function get_key_path(): string {
		return $this->key_path;
	}

	/**
	 * Delete lease files and directory.
	 *
	 * @return void
	 */
	public function release(): void {
		if ( $this->released ) {
			return;
		}

		$this->released = true;

		if ( '' !== $this->cert_path && is_file( $this->cert_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $this->cert_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( '' !== $this->key_path && is_file( $this->key_path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $this->key_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( '' !== $this->dir && is_dir( $this->dir ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $this->dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$this->dir       = '';
		$this->cert_path = '';
		$this->key_path  = '';
	}

	/**
	 * Safety-net cleanup.
	 */
	public function __destruct() {
		$this->release();
	}
}
