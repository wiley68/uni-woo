<?php
/**
 * SmartUCF endpoint policy / SSRF trust-boundary tests (AUD-003-WOO).
 *
 * Run: php tests/run-endpoint-policy-tests.php
 *
 * @package MTUC
 */

require_once __DIR__ . '/bootstrap.php';

final class Mtuc_Endpoint_Policy_Test_Runner {

	/** @var int */
	private $passed = 0;

	/** @var int */
	private $failed = 0;

	/** @var array<int, string> */
	private $errors = array();

	/** @var int */
	private $sync_calls = 0;

	/** @var int */
	private $http_calls = 0;

	public function run(): int {
		$this->reset_client_hooks();

		$this->test_production_service_accepted();
		$this->test_test_service_accepted();
		$this->test_production_application_accepted();
		$this->test_test_application_accepted();

		$this->test_reject_arbitrary_https_domain();
		$this->test_reject_localhost();
		$this->test_reject_loopback_ip();
		$this->test_reject_link_local_metadata();
		$this->test_reject_private_rfc1918();
		$this->test_reject_http();
		$this->test_reject_file_scheme();
		$this->test_reject_ftp_scheme();
		$this->test_reject_userinfo();
		$this->test_reject_query();
		$this->test_reject_fragment();
		$this->test_reject_unexpected_port();
		$this->test_reject_unexpected_service_path();
		$this->test_reject_lookalike_suffix();
		$this->test_reject_lookalike_prefix();

		$this->test_reject_test_env_production_service();
		$this->test_reject_prod_env_test_service();
		$this->test_reject_test_env_production_application();
		$this->test_reject_prod_env_test_application();

		$this->test_invalid_service_zero_outbound_no_sync_no_http();
		$this->test_invalid_application_no_unsafe_redirect();

		$this->test_session_id_accepted();
		$this->test_session_id_injection_rejected();

		$this->test_followlocation_explicitly_false();

		$this->test_valid_mtls_flow_order();
		$this->test_certificate_metadata_match_unchanged();
		$this->test_certificate_refresh_unchanged();

		$this->test_order_no_passthrough_unchanged();

		$this->reset_client_hooks();

		echo "\nPassed: {$this->passed}; Failed: {$this->failed}\n";
		foreach ( $this->errors as $error ) {
			echo "FAIL: {$error}\n";
		}

		return $this->failed > 0 ? 1 : 0;
	}

	private function test_production_service_accepted(): void {
		$shop = $this->shop_prod();
		$url  = Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop );
		$this->assert_true(
			$url === 'https://online.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
			'production service accepted'
		);
	}

	private function test_test_service_accepted(): void {
		$shop = $this->shop_test();
		$url  = Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop );
		$this->assert_true(
			$url === 'https://onlinetest.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
			'test service accepted'
		);
	}

	private function test_production_application_accepted(): void {
		$shop = $this->shop_prod();
		$url  = Mtuc_Smartucf_Endpoint_Policy::resolve_application_redirect_url( $shop, 'abc-123' );
		$this->assert_true(
			$url === 'https://online.ucfin.bg/sucf-online/Request/Start/abc-123',
			'production application accepted'
		);
	}

	private function test_test_application_accepted(): void {
		$shop = $this->shop_test();
		$url  = Mtuc_Smartucf_Endpoint_Policy::resolve_application_redirect_url( $shop, 'sess_1' );
		$this->assert_true(
			$url === 'https://onlinetest.ucfin.bg/sucf-online/Request/Start/sess_1',
			'test application accepted'
		);
	}

	private function test_reject_arbitrary_https_domain(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://example.com/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'arbitrary HTTPS domain rejected' );
	}

	private function test_reject_localhost(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://localhost/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'localhost rejected' );
	}

	private function test_reject_loopback_ip(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://127.0.0.1/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), '127.0.0.1 rejected' );
	}

	private function test_reject_link_local_metadata(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://169.254.169.254/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), '169.254.169.254 rejected' );
	}

	private function test_reject_private_rfc1918(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://10.0.0.5/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'RFC1918 host rejected' );
	}

	private function test_reject_http(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'http://onlinetest.ucfin.bg/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'HTTP rejected' );
	}

	private function test_reject_file_scheme(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'file:///etc/passwd' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'file:// rejected' );
	}

	private function test_reject_ftp_scheme(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'ftp://onlinetest.ucfin.bg/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'ftp:// rejected' );
	}

	private function test_reject_userinfo(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://user:pass@onlinetest.ucfin.bg/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'userinfo rejected' );
	}

	private function test_reject_query(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://onlinetest.ucfin.bg/suos/api/otp/?x=1' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'query rejected' );
	}

	private function test_reject_fragment(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://onlinetest.ucfin.bg/suos/api/otp/#frag' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'fragment rejected' );
	}

	private function test_reject_unexpected_port(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://onlinetest.ucfin.bg:444/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'unexpected port rejected' );
	}

	private function test_reject_unexpected_service_path(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://onlinetest.ucfin.bg/something-else/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'unexpected service path rejected' );
	}

	private function test_reject_lookalike_suffix(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://onlinetest.ucfin.bg.attacker.com/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'suffix lookalike rejected' );
	}

	private function test_reject_lookalike_prefix(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => 'https://evil-onlinetest.ucfin.bg/suos/api/otp/' )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'prefix lookalike rejected' );
	}

	private function test_reject_test_env_production_service(): void {
		$shop = $this->shop_test(
			array( 'uni_test_service' => Mtuc_Smartucf_Endpoint_Policy::SERVICE_PRODUCTION )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'test env + prod service rejected' );
	}

	private function test_reject_prod_env_test_service(): void {
		$shop = $this->shop_prod(
			array( 'uni_production_service' => Mtuc_Smartucf_Endpoint_Policy::SERVICE_TEST )
		);
		$this->assert_true( is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_session_start_url( $shop ) ), 'prod env + test service rejected' );
	}

	private function test_reject_test_env_production_application(): void {
		$shop = $this->shop_test(
			array( 'uni_test_application' => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_PRODUCTION )
		);
		$this->assert_true(
			is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_application_redirect_url( $shop, 'ok' ) ),
			'test env + prod application rejected'
		);
	}

	private function test_reject_prod_env_test_application(): void {
		$shop = $this->shop_prod(
			array( 'uni_production_application' => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_TEST )
		);
		$this->assert_true(
			is_wp_error( Mtuc_Smartucf_Endpoint_Policy::resolve_application_redirect_url( $shop, 'ok' ) ),
			'prod env + test application rejected'
		);
	}

	private function test_invalid_service_zero_outbound_no_sync_no_http(): void {
		$this->reset_client_hooks();
		$this->sync_calls = 0;
		$this->http_calls = 0;

		Mtuc_Smartucf_Api_Client::$certificate_synchronizer = function () {
			++$this->sync_calls;
			return new WP_Error( 'unexpected_sync', 'sync must not run' );
		};
		Mtuc_Smartucf_Api_Client::$http_transport = function () {
			++$this->http_calls;
			return array(
				'body'       => '{}',
				'curl_error' => '',
				'http_code'  => 200,
			);
		};

		$shop = $this->shop_test(
			array(
				'uni_sertificat'    => 1,
				'uni_test_service'  => 'https://example.com/suos/api/otp/',
			)
		);

		$result = Mtuc_Smartucf_Api_Client::start_session(
			array(
				'orderNo' => '42',
				'user'    => 'secret-user',
				'pass'    => 'secret-pass',
			),
			$shop
		);

		$this->assert_true( is_wp_error( $result ), 'invalid service PRE-SEND error' );
		$this->assert_true( 0 === $this->sync_calls, 'certificate synchronizer NOT called' );
		$this->assert_true( 0 === $this->http_calls, 'SmartUCF HTTP NOT called' );
		$this->reset_client_hooks();
	}

	private function test_invalid_application_no_unsafe_redirect(): void {
		$shop = $this->shop_test(
			array( 'uni_test_application' => 'https://evil.example/phish' )
		);
		$result = Mtuc_Smartucf_Endpoint_Policy::resolve_application_redirect_url( $shop, 'sess' );
		$this->assert_true( is_wp_error( $result ), 'invalid application base rejected' );
		$this->assert_true(
			! Mtuc_Smartucf_Api_Client::is_trusted_redirect_url( 'https://evil.example/phish/sess', $shop ),
			'unsafe redirect not trusted'
		);
	}

	private function test_session_id_accepted(): void {
		$ok = Mtuc_Smartucf_Endpoint_Policy::validate_session_id( 'Ab12._-xyz' );
		$this->assert_true( true === $ok, 'normal session ID accepted' );
	}

	private function test_session_id_injection_rejected(): void {
		$cases = array(
			'http://evil',
			'a//b',
			'a?b=1',
			'a#b',
			'../x',
			'a\\b',
			"a\nb",
			'a/b',
		);
		foreach ( $cases as $case ) {
			$this->assert_true(
				is_wp_error( Mtuc_Smartucf_Endpoint_Policy::validate_session_id( $case ) ),
				'session ID injection rejected: ' . $case
			);
		}
	}

	private function test_followlocation_explicitly_false(): void {
		$opts = Mtuc_Smartucf_Api_Client::build_session_curl_options(
			'https://onlinetest.ucfin.bg/suos/api/otp/sucfOnlineSessionStart',
			'{}',
			null
		);
		$this->assert_true(
			array_key_exists( CURLOPT_FOLLOWLOCATION, $opts ) && false === $opts[ CURLOPT_FOLLOWLOCATION ],
			'FOLLOWLOCATION explicitly false'
		);
	}

	private function test_valid_mtls_flow_order(): void {
		$this->reset_client_hooks();
		$order = array();

		Mtuc_Smartucf_Api_Client::$certificate_synchronizer = function () use ( &$order ) {
			$order[] = 'sync';
			$dir     = sys_get_temp_dir() . '/mtuc-lease-' . getmypid();
			@mkdir( $dir, 0700 );
			$cert = $dir . '/cert.pem';
			$key  = $dir . '/key.pem';
			file_put_contents( $cert, "CERT\n" );
			file_put_contents( $key, "KEY\n" );
			return new Mtuc_Certificate_Consumer_Lease( $dir, $cert, $key );
		};

		Mtuc_Smartucf_Api_Client::$http_transport = function ( array $curl_options ) use ( &$order ) {
			$order[] = 'http';
			$this->assert_true(
				isset( $curl_options[ CURLOPT_URL ] )
					&& 'https://onlinetest.ucfin.bg/suos/api/otp/sucfOnlineSessionStart' === $curl_options[ CURLOPT_URL ],
				'HTTP targets trusted service URL'
			);
			$this->assert_true( isset( $curl_options[ CURLOPT_SSLCERT ] ), 'client certificate attached only after trust' );
			$this->assert_true( isset( $curl_options[ CURLOPT_SSLKEY ] ), 'private key attached only after trust' );
			$this->assert_true( false === $curl_options[ CURLOPT_FOLLOWLOCATION ], 'FOLLOWLOCATION false in live options' );
			return array(
				'body'       => wp_json_encode( array( 'sucfOnlineSessionID' => 'SessionOK1' ) ),
				'curl_error' => '',
				'http_code'  => 200,
			);
		};

		$result = Mtuc_Smartucf_Api_Client::start_session(
			array( 'orderNo' => '99' ),
			$this->shop_test( array( 'uni_sertificat' => 1 ) )
		);

		$this->assert_true( ! is_wp_error( $result ), 'valid mTLS flow succeeds' );
		$this->assert_true( array( 'sync', 'http' ) === $order, 'endpoint policy then sync then HTTP' );
		if ( is_array( $result ) ) {
			$this->assert_true( 'SessionOK1' === $result['session_id'], 'session id preserved' );
			$this->assert_true(
				'https://onlinetest.ucfin.bg/sucf-online/Request/Start/SessionOK1' === $result['redirect_url'],
				'trusted redirect built'
			);
		}
		$this->reset_client_hooks();
	}

	private function test_certificate_metadata_match_unchanged(): void {
		// Regression smoke: synchronizer still skips bundle when hashes match.
		$fixture_dir = __DIR__ . '/fixtures';
		$work        = __DIR__ . '/tmp/ep-keys-' . getmypid();
		@mkdir( $work, 0700, true );
		$cert = (string) file_get_contents( $fixture_dir . '/valid_cert.pem' );
		$key  = (string) file_get_contents( $fixture_dir . '/valid_key.pem' );
		file_put_contents( $work . '/avalon_cert.pem', $cert );
		file_put_contents( $work . '/avalon_private_key.pem', $key );

		Mtuc_Certificate_Local_Store::$keys_dir_override = $work;
		$bundle_calls = 0;
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () use ( $cert, $key ) {
			return array(
				'available'          => true,
				'ssl_revision'       => 'r',
				'certificate_sha256' => hash( 'sha256', $cert ),
				'private_key_sha256' => hash( 'sha256', $key ),
			);
		};
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () use ( &$bundle_calls ) {
			++$bundle_calls;
			return new WP_Error( 'no', 'no' );
		};

		$lease = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( $lease instanceof Mtuc_Certificate_Consumer_Lease, 'metadata match path unchanged' );
		$this->assert_true( 0 === $bundle_calls, 'bundle not requested on match' );
		if ( $lease instanceof Mtuc_Certificate_Consumer_Lease ) {
			$lease->release();
		}

		Mtuc_Certificate_Local_Store::release_lock();
		Mtuc_Certificate_Local_Store::$keys_dir_override = null;
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = null;
		Mtuc_Certificate_Synchronizer::$bundle_fetcher   = null;
		$this->rm_tree( $work );
	}

	private function test_certificate_refresh_unchanged(): void {
		$fixture_dir = __DIR__ . '/fixtures';
		$work        = __DIR__ . '/tmp/ep-keys-refresh-' . getmypid();
		@mkdir( $work, 0700, true );
		$cert = (string) file_get_contents( $fixture_dir . '/valid_cert.pem' );
		$key  = (string) file_get_contents( $fixture_dir . '/valid_key.pem' );
		file_put_contents( $work . '/avalon_cert.pem', "corrupt\n" );
		file_put_contents( $work . '/avalon_private_key.pem', "corrupt\n" );

		Mtuc_Certificate_Local_Store::$keys_dir_override = $work;
		$bundle_calls = 0;
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = function () use ( $cert, $key ) {
			return array(
				'available'          => true,
				'ssl_revision'       => 'r2',
				'certificate_sha256' => hash( 'sha256', $cert ),
				'private_key_sha256' => hash( 'sha256', $key ),
			);
		};
		Mtuc_Certificate_Synchronizer::$bundle_fetcher = function () use ( &$bundle_calls, $cert, $key ) {
			++$bundle_calls;
			return array(
				'ssl_revision'         => 'r2',
				'certificate_sha256'   => hash( 'sha256', $cert ),
				'private_key_sha256'   => hash( 'sha256', $key ),
				'certificate_pem'      => $cert,
				'private_key_pem'      => $key,
			);
		};

		$lease = Mtuc_Certificate_Synchronizer::ensure_current( array( 'uni_sertificat' => 1 ) );
		$this->assert_true( $lease instanceof Mtuc_Certificate_Consumer_Lease, 'certificate refresh path unchanged' );
		$this->assert_true( $bundle_calls >= 1, 'bundle requested on refresh' );
		if ( $lease instanceof Mtuc_Certificate_Consumer_Lease ) {
			$lease->release();
		}

		Mtuc_Certificate_Local_Store::release_lock();
		Mtuc_Certificate_Local_Store::$keys_dir_override = null;
		Mtuc_Certificate_Synchronizer::$metadata_fetcher = null;
		Mtuc_Certificate_Synchronizer::$bundle_fetcher   = null;
		$this->rm_tree( $work );
	}

	private function test_order_no_passthrough_unchanged(): void {
		$this->reset_client_hooks();
		$captured = null;
		Mtuc_Smartucf_Api_Client::$http_transport = function ( array $curl_options ) use ( &$captured ) {
			$captured = isset( $curl_options[ CURLOPT_POSTFIELDS ] ) ? (string) $curl_options[ CURLOPT_POSTFIELDS ] : '';
			return array(
				'body'       => wp_json_encode( array( 'sucfOnlineSessionID' => 'Ord1' ) ),
				'curl_error' => '',
				'http_code'  => 200,
			);
		};

		Mtuc_Smartucf_Api_Client::start_session(
			array( 'orderNo' => '777' ),
			$this->shop_test( array( 'uni_sertificat' => 0 ) )
		);

		$this->assert_true( is_string( $captured ) && false !== strpos( $captured, '"orderNo":"777"' ), 'SmartUCF orderNo unchanged' );
		$this->reset_client_hooks();
	}

	/**
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function shop_test( array $overrides = array() ): array {
		return array_merge(
			array(
				'uni_env'                   => 0,
				'uni_sertificat'            => 0,
				'uni_test_service'          => Mtuc_Smartucf_Endpoint_Policy::SERVICE_TEST,
				'uni_production_service'    => Mtuc_Smartucf_Endpoint_Policy::SERVICE_PRODUCTION,
				'uni_test_application'      => Mtuc_Smartucf_Endpoint_Policy::APPLICATION_TEST,
				'uni_production_application'=> Mtuc_Smartucf_Endpoint_Policy::APPLICATION_PRODUCTION,
			),
			$overrides
		);
	}

	/**
	 * @param array<string, mixed> $overrides Overrides.
	 * @return array<string, mixed>
	 */
	private function shop_prod( array $overrides = array() ): array {
		return $this->shop_test(
			array_merge(
				array( 'uni_env' => 1 ),
				$overrides
			)
		);
	}

	private function reset_client_hooks(): void {
		Mtuc_Smartucf_Api_Client::$certificate_synchronizer = null;
		Mtuc_Smartucf_Api_Client::$http_transport           = null;
		$this->sync_calls = 0;
		$this->http_calls = 0;
	}

	/**
	 * @param string $dir Directory.
	 * @return void
	 */
	private function rm_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
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
		rmdir( $dir );
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

exit( ( new Mtuc_Endpoint_Policy_Test_Runner() )->run() );
