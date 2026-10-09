<?php
/**
 * Distribution regression tests with isolated source fixtures and test-only secrets.
 *
 * Run: composer test:package
 *
 * @package MTUC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Offline tests use isolated filesystem fixtures, never WordPress runtime storage.

require_once dirname( __DIR__ ) . '/tools/class-mtuc-distribution-package.php';

/** Exercises real package creation, parity checks and malformed archive rejection. */
final class Mtuc_Packaging_Test_Runner {

	/**
	 * Successful assertions.
	 *
	 * @var int
	 */
	private $passed = 0;

	/**
	 * Private test source directory.
	 *
	 * @var string
	 */
	private $fixture;

	/**
	 * Fixture builder.
	 *
	 * @var Mtuc_Distribution_Package
	 */
	private $package;

	/**
	 * Most recent build facts.
	 *
	 * @var array<string, mixed>
	 */
	private $facts;

	/**
	 * Copy only runtime files; never copy the real deployment secret into tests.
	 *
	 * @return int Exit status.
	 */
	public function run(): int {
		$this->fixture = sys_get_temp_dir() . '/mtuc-packaging-test-' . bin2hex( random_bytes( 12 ) );
		mkdir( $this->fixture, 0700 );
		try {
			$root     = dirname( __DIR__ );
			$manifest = json_decode( file_get_contents( $root . '/tools/package-manifest.json' ), true );
			foreach ( $manifest['required_files'] as $relative ) {
				if ( 'secrets/smartucf-key.php' !== $relative ) {
					$this->put( $relative, file_get_contents( $root . '/' . $relative ) );
				}
			}
			$this->put( 'secrets/smartucf-key.php', "<?php\nreturn array('smartucf_key_password' => 'test-only-packaging-password');\n" );
			$this->put( 'composer.json', file_get_contents( $root . '/composer.json' ) );
			foreach ( array( 'package.php', 'class-mtuc-distribution-package.php', 'package-manifest.json' ) as $tool ) {
				$this->put( 'tools/' . $tool, file_get_contents( $root . '/tools/' . $tool ) );
			}
			$this->package = new Mtuc_Distribution_Package( $this->fixture, $root . '/tools/package-manifest.json' );
			foreach ( array(
				'.git/config',
				'.github/workflows/build.yml',
				'.vscode/settings.json',
				'tests/fixtures/key.pem',
				'vendor/autoload.php',
				'includes/debug.log',
				'includes/old.zip',
				'docs/audit.md',
				'recovery/private.php',
				'dist/previous.zip',
				'keys/old.pem',
				'keys/leases/lease-stale/key.pem',
				'secrets/unrelated.php',
				'languages/backup.po~',
				'js/local.tmp',
			) as $relative ) {
				$this->put( $relative, 'excluded development/runtime cache fixture' );
			}
			$before      = $this->source_fingerprint();
			$this->facts = $this->package->build();
			$this->check( $before === $this->source_fingerprint(), 'Build preserves all source bytes and mtimes.' );
			$this->check( is_file( $this->facts['path'] ), 'Build succeeds.' );
			$version = Mtuc_Distribution_Package::version( file_get_contents( $this->fixture . '/mtunicredit.php' ) );
			$this->check( basename( $this->facts['path'] ) === 'CC_Woocommerce_UNI_v.' . $version . '.zip', 'Filename derived from canonical runtime version.' );
			$this->check( $this->facts['version'] === $version, 'Build version equals runtime version.' );
			$this->check( count( $manifest['required_files'] ) === $this->facts['files'], 'Every manifest file included.' );
			$this->check( $this->facts === $this->package->verify( $this->facts['path'] ), 'Generated package verifier succeeds.' );
			$zip = new ZipArchive();
			$zip->open( $this->facts['path'] );
			$keys = array();
			foreach ( $manifest['required_files'] as $relative ) {
				$this->check( $zip->getFromName( 'mtunicredit/' . $relative ) === file_get_contents( $this->fixture . '/' . $relative ), 'Source parity: ' . $relative );
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$name = $zip->getNameIndex( $index );
				if ( 0 === strpos( $name, 'mtunicredit/keys/' ) ) {
					$keys[] = $name;
				}
				$this->check( 0 === strpos( $name, 'mtunicredit/' ) && 0 !== strpos( $name, 'mtunicredit/mtunicredit/' ), 'Exact single plugin root.' );
				$this->check( ! preg_match( '~(?:^|/)(?:\.git|\.github|\.vscode|tests|dist|docs|tools|vendor|recovery)(?:/|$)~', $name ), 'Development content excluded.' );
				$this->check( ! preg_match( '/\.(?:pem|key|crt|cer)\z/i', $name ) && 0 !== strpos( $name, 'mtunicredit/keys/leases/' ), 'Certificates, private keys and leases excluded.' );
			}
			$this->check( array( 'mtunicredit/keys/.htaccess', 'mtunicredit/keys/index.php' ) === $keys, 'keys/ contains exactly its two preserved protection files.' );
			$zip->close();
			$this->check( false !== strpos( file_get_contents( $this->fixture . '/dist/.htaccess' ), 'Require all denied' ), 'Distribution protection generated.' );
			$this->check( 0600 === ( fileperms( $this->facts['path'] ) & 0777 ), 'Sensitive ZIP is private.' );
			$hash = $this->facts['sha256'];
			// Change fixture mtimes, timezone and umask; production source is untouched.
			touch( $this->fixture . '/includes/config.php', 1700000000 );
			$timezone = date_default_timezone_get();
			$mask     = umask( 0022 );
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Exercise CLI reproducibility across timezones.
			date_default_timezone_set( 'Pacific/Auckland' );
			try {
				$repeat = $this->package->build();
			} finally {
				// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Restore test process state.
				date_default_timezone_set( $timezone );
				umask( $mask );
			}
			$this->check( $hash === $repeat['sha256'], 'Repeated build is byte-identical despite fixture mtime/timezone/umask changes.' );
			$this->test_cli();
			$this->test_rejections();
			$this->test_versions();
			$this->test_local_artifacts();
			fwrite( STDOUT, 'OK: ' . $this->passed . " packaging assertions passed\n" );
			return 0;
		} catch ( Throwable $error ) {
			fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
			return 1;
		} finally {
			Mtuc_Distribution_Package::remove_tree( $this->fixture );
		}
	}

	/** Verify canonical CLI dispatch without depending on installed Composer dev tools. */
	private function test_cli(): void {
		foreach ( array( array( 'build' ), array( 'verify', $this->facts['path'] ) ) as $args ) {
			$pipes = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Test the CLI entrypoint in an isolated PHP process.
			$child = proc_open(
				array_merge( array( PHP_BINARY, $this->fixture . '/tools/package.php' ), $args ),
				array(
					0 => array( 'pipe', 'r' ),
					1 => array( 'pipe', 'w' ),
					2 => array( 'pipe', 'w' ),
				),
				$pipes
			);
			fclose( $pipes[0] );
			$output = stream_get_contents( $pipes[1] );
			stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			$this->check( 0 === proc_close( $child ) && false !== strpos( $output, 'Verified package:' ), 'CLI ' . $args[0] . ' succeeds.' );
			$this->check( false === strpos( $output, 'test-only-packaging-password' ), 'CLI output does not expose secret contents.' );
		}
	}

	/** Reject malformed layouts, unexpected entries, drift and missing required inputs. */
	private function test_rejections(): void {
		$original = file_get_contents( $this->facts['path'] );
		$bad_dir  = $this->fixture . '/malformed';
		mkdir( $bad_dir, 0700 );
		$bad = $bad_dir . '/' . basename( $this->facts['path'] );
		foreach ( array( 'missing', 'config', 'parity', 'version', 'flat', 'nested', 'git', 'tests', 'development', 'dist', 'zip', 'symlink', 'traversal' ) as $case ) {
			file_put_contents( $bad, $original );
			$zip = new ZipArchive();
			$zip->open( $bad );
			if ( 'missing' === $case ) {
				$zip->deleteName( 'mtunicredit/includes/config.php' );
			} elseif ( 'config' === $case ) {
				$zip->addFromString( 'mtunicredit/includes/config.php', '<?php // altered deployment config' );
			} elseif ( 'parity' === $case ) {
				$zip->addFromString( 'mtunicredit/includes/config.php', str_replace( 'MTUC_CONTROL_PANEL_URL', 'MTUC_CONTROL_PANEL_URX', file_get_contents( $this->fixture . '/includes/config.php' ) ) );
			} elseif ( 'development' === $case ) {
				$zip->renameName( 'mtunicredit/css/mtuc-popup.css', 'mtunicredit/tests/test.php' );
			} elseif ( 'version' === $case ) {
				$zip->addFromString( 'mtunicredit/mtunicredit.php', str_replace( "'MTUC_VERSION',", "'DIFFERENT_VERSION',", file_get_contents( $this->fixture . '/mtunicredit.php' ) ) );
			} elseif ( 'flat' === $case || 'nested' === $case || 'traversal' === $case ) {
				$target = 'flat' === $case ? 'mtunicredit.php' : ( 'nested' === $case ? 'mtunicredit/mtunicredit/mtunicredit.php' : 'mtunicredit/../mtunicredit.php' );
				$zip->renameName( 'mtunicredit/mtunicredit.php', $target );
			} elseif ( 'symlink' === $case ) {
				$zip->setExternalAttributesName( 'mtunicredit/includes/config.php', ZipArchive::OPSYS_UNIX, 0120777 << 16 );
			} else {
				$names = array(
					'git'   => 'mtunicredit/.git/config',
					'tests' => 'mtunicredit/tests/fixture.php',
					'dist'  => 'mtunicredit/dist/output.zip',
					'zip'   => 'mtunicredit/previous.zip',
				);
				$zip->addFromString( $names[ $case ], 'unexpected' );
			}
			$zip->close();
			$this->reject(
				function () use ( $bad ): void {
					$this->package->verify( $bad );
				},
				'Archive rejection: ' . $case
			);
		}
		foreach ( array(
			'keys/avalon_private_key.pem',
			'keys/avalon_certificate.pem',
			'keys/avalon_cert.pem',
			'keys/downloaded.key',
			'keys/downloaded.crt',
			'keys/downloaded.cer',
			'keys/leases/lease-id/key.pem',
			'keys/ssl-sync.lock',
			'keys/cp-bundle.zip',
			'keys/certificate.staging.tmp',
		) as $runtime ) {
			file_put_contents( $bad, $original );
			$zip = new ZipArchive();
			$zip->open( $bad );
			$zip->addFromString( 'mtunicredit/' . $runtime, 'forbidden runtime fixture' );
			$zip->close();
			$this->reject(
				function () use ( $bad ): void {
					$this->package->verify( $bad );
				},
				'Runtime certificate/lease archive rejected: ' . $runtime
			);
		}
		file_put_contents( $bad, 'not a zip' );
		$this->reject(
			function () use ( $bad ): void {
				$this->package->verify( $bad );
			},
			'Malformed ZIP rejected.'
		);
		$wrong = $bad_dir . '/CC_Woocommerce_UNI_v.999.0.0.zip';
		file_put_contents( $wrong, $original );
		$this->reject(
			function () use ( $wrong ): void {
				$this->package->verify( $wrong );
			},
			'Filename/version mismatch rejected.'
		);
		$config = file_get_contents( $this->fixture . '/includes/config.php' );
		$this->put( 'includes/config.php', $config . "\n// source drift\n" );
		$this->reject(
			function (): void {
				$this->package->verify( $this->facts['path'] );
			},
			'Current source config drift rejected.'
		);
		$this->put( 'includes/config.php', $config );
		foreach ( array( 'includes/class-mtuc-cp-destination-policy.php', 'js/mtuc-product-popup.js', 'keys/.htaccess', 'secrets/smartucf-key.php' ) as $relative ) {
			$bytes = file_get_contents( $this->fixture . '/' . $relative );
			unlink( $this->fixture . '/' . $relative );
			$this->reject(
				function (): void {
					$this->package->build();
				},
				'Missing required input rejected: ' . $relative
			);
			$this->put( $relative, $bytes );
		}
		$stages = glob( sys_get_temp_dir() . '/mtuc-package-*' );
		$deny   = file_get_contents( $this->fixture . '/dist/.htaccess' );
		$this->put( 'dist/.htaccess', 'existing custom protection' );
		$this->reject(
			function (): void {
				$this->package->build();
			},
			'Existing protection is not overwritten.'
		);
		$this->check( glob( sys_get_temp_dir() . '/mtuc-package-*' ) === $stages, 'Failed build cleans private staging tree.' );
		$this->check( hash_file( 'sha256', $this->facts['path'] ) === $this->facts['sha256'], 'Failed build preserves previous verified artifact.' );
		$this->put( 'dist/.htaccess', $deny );
		$composer                                   = file_get_contents( $this->fixture . '/composer.json' );
		$data                                       = json_decode( $composer, true );
		$data['require']['example/runtime-library'] = '^1.0';
		$this->put( 'composer.json', json_encode( $data ) );
		$this->reject(
			function (): void {
				$this->package->build();
			},
			'Unaudited runtime dependencies rejected.'
		);
		$this->put( 'composer.json', $composer );
	}

	/** Check version ambiguity and a changed canonical version without a hardcoded filename. */
	private function test_versions(): void {
		$main    = file_get_contents( $this->fixture . '/mtunicredit.php' );
		$current = $this->facts['version'];
		$comment = $main . "\n// define('MTUC_VERSION', '9.8.7');\n";
		$this->check( Mtuc_Distribution_Package::version( $comment ) === $current, 'Commented version definitions do not affect runtime resolution.' );
		$string = str_replace( "'MTUC_VERSION',", "'MISSING_VERSION',", $main ) . "\n\$sample = \"define('MTUC_VERSION', '$current');\";\n";
		$this->reject(
			static function () use ( $string ): void {
				Mtuc_Distribution_Package::version( $string );
			},
			'A quoted declaration cannot supply the runtime version.'
		);
		foreach ( array(
			str_replace( "'MTUC_VERSION',", "'MISSING_VERSION',", $main ),
			$main . "\ndefine('MTUC_VERSION', '9.8.7');\n",
			str_replace( "'MTUC_VERSION', '" . $current . "'", "'MTUC_VERSION', getenv('VERSION')", $main ),
			preg_replace( '/(\*\s*Version:\s*)\S+/', '${1}9.8.7', $main ),
		) as $bad ) {
			$this->reject(
				static function () use ( $bad ): void {
					Mtuc_Distribution_Package::version( $bad );
				},
				'Missing/nonliteral/duplicate/header-mismatched version rejected.'
			);
		}
		$next = str_replace( "'MTUC_VERSION', '" . $current . "'", "'MTUC_VERSION', '9.8.7'", $main );
		$next = preg_replace( '/(\*\s*Version:\s*)\S+/', '${1}9.8.7', $next );
		$this->put( 'mtunicredit.php', $next );
		$facts = $this->package->build();
		$this->check( 'CC_Woocommerce_UNI_v.9.8.7.zip' === basename( $facts['path'] ), 'Changed runtime version drives filename.' );
		$this->check( '9.8.7' === $this->package->verify( $facts['path'] )['version'], 'Packaged source and filename versions agree.' );
		$this->put( 'mtunicredit.php', $main );
	}

	/** Exclude unreadable runtime data; preserve translations; reject invalid secrets and source symlinks. */
	private function test_local_artifacts(): void {
		$secret = file_get_contents( $this->fixture . '/secrets/smartucf-key.php' );
		foreach ( array( '<?php return array();', "<?php return array('smartucf_key_password'=>'');", '<?php syntax error', '<?php exit(0);' ) as $invalid ) {
			$this->put( 'secrets/smartucf-key.php', $invalid );
			$this->reject(
				function (): void {
					$this->package->build();
				},
				'Invalid production secret rejected without an environment fallback.'
			);
		}
		$this->put( 'secrets/smartucf-key.php', $secret );
		$this->put( 'keys/avalon_cert.pem', 'test certificate bytes' );
		$this->put( 'keys/avalon_private_key.pem', 'test private-key bytes' );
		$this->put( 'keys/leases/lease-id/key.pem', 'test lease bytes' );
		$this->put( 'languages/mtunicredit-bg_BG.mo', 'test compiled translation bytes' );
		$before = $this->source_fingerprint();
		// Runtime inputs are deliberately unreadable, proving the builder never needs access.
		chmod( $this->fixture . '/keys/avalon_private_key.pem', 0000 );
		chmod( $this->fixture . '/keys/leases', 0000 );
		try {
			$local = $this->package->build();
		} finally {
			chmod( $this->fixture . '/keys/avalon_private_key.pem', 0600 );
			chmod( $this->fixture . '/keys/leases', 0700 );
		}
		$this->check( $before === $this->source_fingerprint(), 'Runtime certificate/lease bytes and mtimes remain untouched.' );
		$this->check( $local['files'] === $this->facts['files'] + 1, 'Only the translation is added; runtime data excluded.' );
		$zip = new ZipArchive();
		$zip->open( $local['path'] );
		foreach ( array( 'keys/avalon_cert.pem', 'keys/avalon_private_key.pem', 'keys/leases/lease-id/key.pem' ) as $relative ) {
			$this->check( false === $zip->locateName( 'mtunicredit/' . $relative ), 'Runtime input excluded: ' . $relative );
		}
		$this->check( $zip->getFromName( 'mtunicredit/languages/mtunicredit-bg_BG.mo' ) === file_get_contents( $this->fixture . '/languages/mtunicredit-bg_BG.mo' ), 'Translation parity.' );
		$zip->close();
		$manifest                     = json_decode( file_get_contents( $this->fixture . '/tools/package-manifest.json' ), true );
		$manifest['required_files'][] = 'keys/avalon_private_key.pem';
		$this->put( 'tools/unsafe-manifest.json', json_encode( $manifest ) );
		$this->reject(
			function (): void {
				$unsafe = new Mtuc_Distribution_Package( $this->fixture, $this->fixture . '/tools/unsafe-manifest.json' );
				$unsafe->build();
			},
			'Manifest cannot opt runtime certificate material back into the distribution.'
		);
		$config = file_get_contents( $this->fixture . '/includes/config.php' );
		unlink( $this->fixture . '/includes/config.php' );
		symlink( $this->fixture . '/mtunicredit.php', $this->fixture . '/includes/config.php' );
		$this->reject(
			function (): void {
				$this->package->build();
			},
			'Source symlink rejected.'
		);
		unlink( $this->fixture . '/includes/config.php' );
		$this->put( 'includes/config.php', $config );
	}

	/**
	 * Assert an expected rejection without surfacing potentially sensitive exception text.
	 *
	 * @param callable $callback Operation.
	 * @param string   $label    Assertion.
	 */
	private function reject( callable $callback, string $label ): void {
		$rejected = false;
		try {
			$callback();
		} catch ( RuntimeException $error ) {
			$rejected = true;
		}
		$this->check( $rejected, $label );
	}

	/**
	 * Record a successful assertion or stop at the failing test.
	 *
	 * @param bool   $condition Result.
	 * @param string $label     Assertion.
	 * @throws RuntimeException If an assertion fails.
	 */
	private function check( bool $condition, string $label ): void {
		if ( ! $condition ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain CLI test diagnostic, not HTML output.
			throw new RuntimeException( $label );
		}
		++$this->passed;
	}

	/**
	 * Write an isolated test input.
	 *
	 * @param string $relative Relative fixture path.
	 * @param string $bytes    Test bytes.
	 */
	private function put( string $relative, string $bytes ): void {
		$path = $this->fixture . '/' . $relative;
		if ( ! is_dir( dirname( $path ) ) ) {
			mkdir( dirname( $path ), 0700, true );
		}
		file_put_contents( $path, $bytes );
	}

	/**
	 * Capture fixture source bytes and mtimes, excluding output artifacts.
	 *
	 * @return array<string, array> Sorted fingerprints.
	 */
	private function source_fingerprint(): array {
		$result = array();
		$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->fixture, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			$relative = substr( $file->getPathname(), strlen( $this->fixture ) + 1 );
			if ( $file->isFile() && 0 !== strpos( $relative, 'dist/' ) ) {
				$result[ $relative ] = array( hash_file( 'sha256', $file->getPathname() ), $file->getMTime() );
			}
		}
		ksort( $result );
		return $result;
	}
}

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Exit receives an integer process status.
exit( ( new Mtuc_Packaging_Test_Runner() )->run() );
