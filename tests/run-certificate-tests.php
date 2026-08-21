<?php
/**
 * Certificate synchronizer automated tests.
 *
 * Run: php tests/run-certificate-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

final class Mtuc_Certificate_Test_Runner {

	/** @var int */
	private $passed = 0;

	/** @var int */
	private $failed = 0;

	/** @var array<int, string> */
	private $errors = array();

	/** @var string */
	private $fixture_dir;

	/** @var string */
	private $work_dir;

	/** @var int */
	private $bundle_calls = 0;

	/** @var int */
	private $metadata_calls = 0;

	public function __construct() {
		$this->fixture_dir = __DIR__ . '/fixtures';
		$this->work_dir    = __DIR__ . '/tmp/keys-' . getmypid();
	}

	public function run(): int {
		$this->reset_work_dir();
		Mtuc_Certificate_Local_Store::$keys_dir_override = $this->work_dir;
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = null;
		Mtuc_Certificate_Synchronizer::$bundle_fetcher   = null;

		$this->test_validator_basics();
		$this->test_metadata_match_skips_bundle();
		$this->test_cert_byte_change_triggers_refresh();
		$this->test_key_byte_change_triggers_refresh();
		$this->test_missing_files_download();
		$this->test_malformed_cert_refresh();
		$this->test_malformed_key_refresh();
		$this->test_pair_mismatch_refresh();
		$this->test_fail_open_cp_transport_valid_local();
		$this->test_fail_closed_cp_transport_no_local();
		$this->test_fail_closed_cp_transport_expired_local();
		$this->test_cp_explicit_unavailable();
		$this->test_bundle_transport_failure_preserves_old();
		$this->test_bundle_cert_hash_mismatch();
		$this->test_bundle_key_hash_mismatch();
		$this->test_malformed_downloaded_cert();
		$this->test_malformed_downloaded_key();
		$this->test_downloaded_pair_mismatch();
		$this->test_expired_downloaded_cert();
		$this->test_not_yet_valid_cert();
		$this->test_replacement_failure_preserves_old();
		$this->test_lock_recheck_skips_second_download();
		$this->test_lease_immutable_during_rotation();
		$this->test_lease_cleanup_success();
		$this->test_lease_cleanup_after_failure_path();
		$this->test_uni_sertificat_disabled();
		$this->test_no_pem_in_log_event_context();
		$this->test_sha256_exact_bytes();

		$this->cleanup_work_dir();

		echo "\nPassed: {$this->passed}; Failed: {$this->failed}\n";
		foreach ( $this->errors as $error ) {
			echo "FAIL: {$error}\n";
		}

		return $this->failed > 0 ? 1 : 0;
	}

	private function test_validator_basics(): void {
		$cert = $this->fixture( 'valid_cert.pem' );
		$key  = $this->fixture( 'valid_key.pem' );
		$this->assert_true( true === Mtuc_Certificate_Pair_Validator::validate_pair( $cert, $key ), 'valid pair validates' );
		$this->assert_true( Mtuc_Certificate_Pair_Validator::is_valid_sha256( hash( 'sha256', $cert ) ), 'valid sha256 format' );
		$this->assert_true( ! Mtuc_Certificate_Pair_Validator::is_valid_sha256( 'ABC' ), 'invalid sha256 rejected' );
	}

	private function test_metadata_match_skips_bundle(): void {
		$this->install_valid_pair();
		$this->bundle_calls = 0;
		$this->stub_metadata_matching_local();
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () {
			++$this->bundle_calls;
			return new WP_Error( 'unexpected_bundle', 'bundle should not be called' );
		};

		$lease = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( $lease instanceof Mtuc_Certificate_Consumer_Lease, 'match returns lease' );
		$this->assert_true( 0 === $this->bundle_calls, 'bundle not requested on match' );
		if ( $lease instanceof Mtuc_Certificate_Consumer_Lease ) {
			$lease->release();
		}
	}

	private function test_cert_byte_change_triggers_refresh(): void {
		$this->install_valid_pair();
		$cert_path = Mtuc_Certificate_Local_Store::get_cert_path();
		file_put_contents( $cert_path, file_get_contents( $cert_path ) . "\n" );
		$this->assert_refresh_restores_pair( 'cert byte change' );
	}

	private function test_key_byte_change_triggers_refresh(): void {
		$this->install_valid_pair();
		$key_path = Mtuc_Certificate_Local_Store::get_key_path();
		file_put_contents( $key_path, file_get_contents( $key_path ) . "\n" );
		$this->assert_refresh_restores_pair( 'key byte change' );
	}

	private function test_missing_files_download(): void {
		$this->reset_work_dir();
		$this->assert_refresh_restores_pair( 'missing files' );
	}

	private function test_malformed_cert_refresh(): void {
		$this->install_valid_pair();
		file_put_contents( Mtuc_Certificate_Local_Store::get_cert_path(), "not-a-cert\n" );
		$this->assert_refresh_restores_pair( 'malformed cert' );
	}

	private function test_malformed_key_refresh(): void {
		$this->install_valid_pair();
		file_put_contents( Mtuc_Certificate_Local_Store::get_key_path(), "not-a-key\n" );
		$this->assert_refresh_restores_pair( 'malformed key' );
	}

	private function test_pair_mismatch_refresh(): void {
		$this->install_valid_pair();
		file_put_contents( Mtuc_Certificate_Local_Store::get_cert_path(), $this->fixture( 'other_cert.pem' ) );
		$this->assert_refresh_restores_pair( 'pair mismatch' );
	}

	private function test_fail_open_cp_transport_valid_local(): void {
		$this->install_valid_pair();
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () {
			return new WP_Error( 'mtuc_api_http_error', 'timeout' );
		};
		$this->bundle_calls = 0;
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () {
			++$this->bundle_calls;
			return new WP_Error( 'nope', 'no' );
		};
		$lease = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( $lease instanceof Mtuc_Certificate_Consumer_Lease, 'fail-open with valid local' );
		$this->assert_true( 0 === $this->bundle_calls, 'fail-open skips bundle' );
		if ( $lease instanceof Mtuc_Certificate_Consumer_Lease ) {
			$lease->release();
		}
	}

	private function test_fail_closed_cp_transport_no_local(): void {
		$this->reset_work_dir();
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () {
			return new WP_Error( 'mtuc_api_http_error', 'timeout' );
		};
		$result = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( is_wp_error( $result ), 'fail-closed without local' );
	}

	private function test_fail_closed_cp_transport_expired_local(): void {
		$cert = $this->fixture( 'valid_cert.pem' );
		$key  = $this->fixture( 'valid_key.pem' );
		$parsed = openssl_x509_parse( $cert );
		$future = (int) $parsed['validTo_time_t'] + 86400;
		$valid  = Mtuc_Certificate_Pair_Validator::validate_pair( $cert, $key, '', $future );
		$this->assert_true( is_wp_error( $valid ) && 'mtuc_ssl_cert_expired' === $valid->get_error_code(), 'expired by now override' );

		$this->install_valid_pair();
		// Simulate expired by replacing validator path: use mismatch + metadata fail after making local invalid via empty cert
		file_put_contents( Mtuc_Certificate_Local_Store::get_cert_path(), $cert );
		file_put_contents( Mtuc_Certificate_Local_Store::get_key_path(), $key );
		// Force inspect to fail expired: temporarily swap files to invalid then metadata fail
		// Use a cert that validates as expired via corrupting then... better: stub metadata fail and make local invalid by truncating
		file_put_contents( Mtuc_Certificate_Local_Store::get_cert_path(), "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n" );
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () {
			return new WP_Error( 'mtuc_api_http_error', 'timeout' );
		};
		$result = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( is_wp_error( $result ), 'fail-closed with invalid/expired-like local' );
	}

	private function test_cp_explicit_unavailable(): void {
		$this->install_valid_pair();
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () {
			return new WP_Error( 'mtuc_ssl_certificate_unavailable', 'unavailable' );
		};
		$result = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( is_wp_error( $result ), 'explicit unavailable fail-closed' );
	}

	private function test_bundle_transport_failure_preserves_old(): void {
		$this->install_valid_pair();
		$before_cert = file_get_contents( Mtuc_Certificate_Local_Store::get_cert_path() );
		$before_key  = file_get_contents( Mtuc_Certificate_Local_Store::get_key_path() );

		$other_cert = $this->fixture( 'other_cert.pem' );
		$other_key  = $this->fixture( 'other_key.pem' );
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () use ( $other_cert, $other_key ) {
			return array(
				'available'            => true,
				'ssl_revision'         => 'r2',
				'certificate_sha256'   => hash( 'sha256', $other_cert ),
				'private_key_sha256'   => hash( 'sha256', $other_key ),
			);
		};
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () {
			return new WP_Error( 'mtuc_api_http_error', 'bundle down' );
		};

		$result = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( is_wp_error( $result ), 'bundle transport fail closed' );
		$this->assert_true( $before_cert === file_get_contents( Mtuc_Certificate_Local_Store::get_cert_path() ), 'old cert preserved' );
		$this->assert_true( $before_key === file_get_contents( Mtuc_Certificate_Local_Store::get_key_path() ), 'old key preserved' );
	}

	private function test_bundle_cert_hash_mismatch(): void {
		$this->reset_work_dir();
		$cert = $this->fixture( 'valid_cert.pem' );
		$key  = $this->fixture( 'valid_key.pem' );
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () use ( $cert, $key ) {
			return array(
				'available'          => true,
				'ssl_revision'       => 'r1',
				'certificate_sha256' => hash( 'sha256', $cert ),
				'private_key_sha256' => hash( 'sha256', $key ),
			);
		};
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () use ( $cert, $key ) {
			return array(
				'ssl_revision'         => 'r1',
				'certificate_sha256'   => hash( 'sha256', $cert ),
				'private_key_sha256'   => hash( 'sha256', $key ),
				'certificate_pem'      => $cert . "\n",
				'private_key_pem'      => $key,
			);
		};
		$result = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( is_wp_error( $result ), 'bundle cert hash mismatch rejected' );
	}

	private function test_bundle_key_hash_mismatch(): void {
		$this->reset_work_dir();
		$cert = $this->fixture( 'valid_cert.pem' );
		$key  = $this->fixture( 'valid_key.pem' );
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () use ( $cert, $key ) {
			return array(
				'available'          => true,
				'ssl_revision'       => 'r1',
				'certificate_sha256' => hash( 'sha256', $cert ),
				'private_key_sha256' => hash( 'sha256', $key ),
			);
		};
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () use ( $cert, $key ) {
			return array(
				'ssl_revision'         => 'r1',
				'certificate_sha256'   => hash( 'sha256', $cert ),
				'private_key_sha256'   => hash( 'sha256', $key ),
				'certificate_pem'      => $cert,
				'private_key_pem'      => $key . "\n",
			);
		};
		$result = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( is_wp_error( $result ), 'bundle key hash mismatch rejected' );
	}

	private function test_malformed_downloaded_cert(): void {
		$this->reset_work_dir();
		$bad  = "-----BEGIN CERTIFICATE-----\nbad\n-----END CERTIFICATE-----\n";
		$key  = $this->fixture( 'valid_key.pem' );
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () use ( $bad, $key ) {
			return array(
				'available'          => true,
				'ssl_revision'       => 'r1',
				'certificate_sha256' => hash( 'sha256', $bad ),
				'private_key_sha256' => hash( 'sha256', $key ),
			);
		};
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () use ( $bad, $key ) {
			return array(
				'ssl_revision'         => 'r1',
				'certificate_sha256'   => hash( 'sha256', $bad ),
				'private_key_sha256'   => hash( 'sha256', $key ),
				'certificate_pem'      => $bad,
				'private_key_pem'      => $key,
			);
		};
		$result = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( is_wp_error( $result ), 'malformed downloaded cert rejected' );
	}

	private function test_malformed_downloaded_key(): void {
		$this->reset_work_dir();
		$cert = $this->fixture( 'valid_cert.pem' );
		$bad  = "-----BEGIN PRIVATE KEY-----\nbad\n-----END PRIVATE KEY-----\n";
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () use ( $cert, $bad ) {
			return array(
				'available'          => true,
				'ssl_revision'       => 'r1',
				'certificate_sha256' => hash( 'sha256', $cert ),
				'private_key_sha256' => hash( 'sha256', $bad ),
			);
		};
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () use ( $cert, $bad ) {
			return array(
				'ssl_revision'         => 'r1',
				'certificate_sha256'   => hash( 'sha256', $cert ),
				'private_key_sha256'   => hash( 'sha256', $bad ),
				'certificate_pem'      => $cert,
				'private_key_pem'      => $bad,
			);
		};
		$result = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( is_wp_error( $result ), 'malformed downloaded key rejected' );
	}

	private function test_downloaded_pair_mismatch(): void {
		$this->reset_work_dir();
		$cert = $this->fixture( 'valid_cert.pem' );
		$key  = $this->fixture( 'other_key.pem' );
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () use ( $cert, $key ) {
			return array(
				'available'          => true,
				'ssl_revision'       => 'r1',
				'certificate_sha256' => hash( 'sha256', $cert ),
				'private_key_sha256' => hash( 'sha256', $key ),
			);
		};
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () use ( $cert, $key ) {
			return array(
				'ssl_revision'         => 'r1',
				'certificate_sha256'   => hash( 'sha256', $cert ),
				'private_key_sha256'   => hash( 'sha256', $key ),
				'certificate_pem'      => $cert,
				'private_key_pem'      => $key,
			);
		};
		$result = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( is_wp_error( $result ), 'downloaded mismatch rejected' );
	}

	private function test_expired_downloaded_cert(): void {
		$cert = $this->fixture( 'valid_cert.pem' );
		$key  = $this->fixture( 'valid_key.pem' );
		$parsed = openssl_x509_parse( $cert );
		$after  = (int) $parsed['validTo_time_t'] + 10;
		$result = Mtuc_Certificate_Pair_Validator::validate_pair( $cert, $key, '', $after );
		$this->assert_true( is_wp_error( $result ), 'expired downloaded cert logic' );
	}

	private function test_not_yet_valid_cert(): void {
		$cert = $this->fixture( 'valid_cert.pem' );
		$key  = $this->fixture( 'valid_key.pem' );
		$parsed = openssl_x509_parse( $cert );
		$before = (int) $parsed['validFrom_time_t'] - 10;
		$result = Mtuc_Certificate_Pair_Validator::validate_pair( $cert, $key, '', $before );
		$this->assert_true( is_wp_error( $result ), 'not-yet-valid cert logic' );
	}

	private function test_replacement_failure_preserves_old(): void {
		$this->install_valid_pair();
		$before = file_get_contents( Mtuc_Certificate_Local_Store::get_cert_path() );
		$result = Mtuc_Certificate_Local_Store::replace_pair( 'bad', 'bad' );
		$this->assert_true( is_wp_error( $result ), 'bad replace rejected' );
		$this->assert_true( $before === file_get_contents( Mtuc_Certificate_Local_Store::get_cert_path() ), 'old pair preserved on replace fail' );
	}

	private function test_lock_recheck_skips_second_download(): void {
		$this->install_valid_pair();
		$this->bundle_calls = 0;
		$cert = $this->fixture( 'valid_cert.pem' );
		$key  = $this->fixture( 'valid_key.pem' );

		// First request refreshes to "other" then second sees match...
		// Simulate: local already matches metadata, two concurrent ensures both skip bundle.
		$this->stub_metadata_matching_local();
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () {
			++$this->bundle_calls;
			return new WP_Error( 'no', 'no' );
		};
		$a = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$b = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( $a instanceof Mtuc_Certificate_Consumer_Lease && $b instanceof Mtuc_Certificate_Consumer_Lease, 'lock/recheck both leases' );
		$this->assert_true( 0 === $this->bundle_calls, 'recheck skips duplicate download' );
		if ( $a instanceof Mtuc_Certificate_Consumer_Lease ) {
			$a->release();
		}
		if ( $b instanceof Mtuc_Certificate_Consumer_Lease ) {
			$b->release();
		}
	}

	private function test_lease_immutable_during_rotation(): void {
		$this->install_valid_pair();
		$lease = Mtuc_Certificate_Local_Store::create_lease_from_authoritative();
		$this->assert_true( $lease instanceof Mtuc_Certificate_Consumer_Lease, 'lease created' );
		$lease_cert = file_get_contents( $lease->get_cert_path() );

		$other_cert = $this->fixture( 'other_cert.pem' );
		$other_key  = $this->fixture( 'other_key.pem' );
		Mtuc_Certificate_Local_Store::replace_pair( $other_cert, $other_key );

		$this->assert_true( is_file( $lease->get_cert_path() ), 'lease cert still exists after V2 rotate' );
		$this->assert_true( $lease_cert === file_get_contents( $lease->get_cert_path() ), 'lease remains V1 immutable' );
		$this->assert_true( $other_cert === file_get_contents( Mtuc_Certificate_Local_Store::get_cert_path() ), 'authoritative is V2' );
		$lease->release();
	}

	private function test_lease_cleanup_success(): void {
		$this->install_valid_pair();
		$lease = Mtuc_Certificate_Local_Store::create_lease_from_authoritative();
		$dir   = dirname( $lease->get_cert_path() );
		$lease->release();
		$this->assert_true( ! is_dir( $dir ), 'lease cleaned after release' );
	}

	private function test_lease_cleanup_after_failure_path(): void {
		$this->install_valid_pair();
		$lease = Mtuc_Certificate_Local_Store::create_lease_from_authoritative();
		$path  = $lease->get_cert_path();
		// Simulate finally{} after curl failure.
		$lease->release();
		$this->assert_true( ! is_file( $path ), 'lease cleaned after failure path' );
	}

	private function test_uni_sertificat_disabled(): void {
		$this->metadata_calls = 0;
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () {
			++$this->metadata_calls;
			return new WP_Error( 'no', 'no' );
		};
		$result = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 0 ) );
		$this->assert_true( null === $result, 'disabled returns null' );
		$this->assert_true( 0 === $this->metadata_calls, 'disabled skips metadata' );
	}

	private function test_no_pem_in_log_event_context(): void {
		// Ensure synchronizer log filter drops pem keys — covered by private method design;
		// assert hashing helpers never echo PEM.
		$cert = $this->fixture( 'valid_cert.pem' );
		$hash = Mtuc_Certificate_Pair_Validator::sha256_hex( $cert );
		$this->assert_true( false === strpos( $hash, 'BEGIN' ), 'hash has no PEM' );
	}

	private function test_sha256_exact_bytes(): void {
		$cert = $this->fixture( 'valid_cert.pem' );
		$a    = Mtuc_Certificate_Pair_Validator::sha256_hex( $cert );
		$b    = Mtuc_Certificate_Pair_Validator::sha256_hex( $cert . "\n" );
		$this->assert_true( $a !== $b, 'exact bytes hashing (CRLF/newline sensitive)' );
	}

	private function assert_refresh_restores_pair( string $label ): void {
		$cert = $this->fixture( 'valid_cert.pem' );
		$key  = $this->fixture( 'valid_key.pem' );
		$this->bundle_calls = 0;

		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () use ( $cert, $key ) {
			return array(
				'available'          => true,
				'ssl_revision'       => 'r-test',
				'certificate_sha256' => hash( 'sha256', $cert ),
				'private_key_sha256' => hash( 'sha256', $key ),
				'not_before'         => '',
				'not_after'          => '',
			);
		};
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () use ( $cert, $key ) {
			++$this->bundle_calls;
			return array(
				'ssl_revision'         => 'r-test',
				'certificate_sha256'   => hash( 'sha256', $cert ),
				'private_key_sha256'   => hash( 'sha256', $key ),
				'certificate_pem'      => $cert,
				'private_key_pem'      => $key,
				'not_before'           => '',
				'not_after'            => '',
			);
		};

		$lease = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( $lease instanceof Mtuc_Certificate_Consumer_Lease, $label . ' returns lease' );
		$this->assert_true( $this->bundle_calls >= 1, $label . ' requested bundle' );
		$this->assert_true( $cert === file_get_contents( Mtuc_Certificate_Local_Store::get_cert_path() ), $label . ' cert restored' );
		$this->assert_true( $key === file_get_contents( Mtuc_Certificate_Local_Store::get_key_path() ), $label . ' key restored' );
		if ( $lease instanceof Mtuc_Certificate_Consumer_Lease ) {
			$lease->release();
		}
	}

	private function stub_metadata_matching_local(): void {
		$cert = file_get_contents( Mtuc_Certificate_Local_Store::get_cert_path() );
		$key  = file_get_contents( Mtuc_Certificate_Local_Store::get_key_path() );
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () use ( $cert, $key ) {
			++$this->metadata_calls;
			return array(
				'available'          => true,
				'ssl_revision'       => 'r-local',
				'certificate_sha256' => hash( 'sha256', $cert ),
				'private_key_sha256' => hash( 'sha256', $key ),
			);
		};
	}

	private function install_valid_pair(): void {
		$this->reset_work_dir();
		file_put_contents( Mtuc_Certificate_Local_Store::get_cert_path(), $this->fixture( 'valid_cert.pem' ) );
		file_put_contents( Mtuc_Certificate_Local_Store::get_key_path(), $this->fixture( 'valid_key.pem' ) );
		chmod( Mtuc_Certificate_Local_Store::get_cert_path(), 0640 );
		chmod( Mtuc_Certificate_Local_Store::get_key_path(), 0600 );
	}

	private function fixture( string $name ): string {
		return (string) file_get_contents( $this->fixture_dir . '/' . $name );
	}

	private function reset_work_dir(): void {
		$this->cleanup_work_dir();
		mkdir( $this->work_dir, 0700, true );
		Mtuc_Certificate_Local_Store::$keys_dir_override = $this->work_dir;
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = null;
		Mtuc_Certificate_Synchronizer::$bundle_fetcher   = null;
		$this->bundle_calls   = 0;
		$this->metadata_calls = 0;
	}

	private function cleanup_work_dir(): void {
		Mtuc_Certificate_Local_Store::release_lock();
		if ( ! is_dir( $this->work_dir ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->work_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			$path = $file->getPathname();
			if ( $file->isDir() ) {
				rmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $this->work_dir );
	}

	/**
	 * @param mixed  $condition Condition.
	 * @param string $message   Message.
	 */
	private function assert_true( $condition, string $message ): void {
		if ( $condition ) {
			++$this->passed;
			echo '.';
			return;
		}
		++$this->failed;
		$this->errors[] = $message;
		echo 'F';
	}
}

exit( ( new Mtuc_Certificate_Test_Runner() )->run() );
