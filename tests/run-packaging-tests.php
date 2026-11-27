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
			$this->check( basename( $this->facts['path'] ) === 'woo_uni_' . $version . '.zip', 'Filename derived from canonical runtime version.' );
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
			$content_hash = $this->zip_metadata( $this->facts['path'] )['content_hash'];
			// Change fixture mtimes, timezone and umask; production source is untouched.
			touch( $this->fixture . '/includes/config.php', 1700000000 );
			$timezone = date_default_timezone_get();
			$mask     = umask( 0022 );
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Exercise CLI packaging across timezones.
			date_default_timezone_set( 'Pacific/Auckland' );
			try {
				$repeat = $this->package->build();
			} finally {
				// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Restore test process state.
				date_default_timezone_set( $timezone );
				umask( $mask );
			}
			$this->check( $content_hash === $this->zip_metadata( $repeat['path'] )['content_hash'], 'Repeated build changes only ZIP timestamps despite fixture mtime/timezone/umask changes.' );
			$this->facts = $repeat;
			$this->test_timestamps();
			$this->test_timezones();
			$this->test_installer_cleanup();
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

	/**
	 * Read small fixture ZIPs independently of ZipArchive; check headers, bytes and CRCs.
	 *
	 * @param string $path Fixture archive.
	 * @return array Timestamp and hash with only local/central DOS timestamps zeroed.
	 */
	private function zip_metadata( string $path ): array {
		$bytes      = file_get_contents( $path );
		$normalized = $bytes;
		$end        = strrpos( $bytes, "PK\x05\x06" );
		$this->check( false !== $end, 'Independent ZIP end record exists.' );
		$directory = unpack( 'vdisk/vstart/ventries_disk/ventries/Vsize/Voffset/vcomment', substr( $bytes, $end + 4, 18 ) );
		$snapshot  = $this->package->snapshot();
		$expected  = array_map( static function ( $name ) { return 'mtunicredit/' . $name; }, array_keys( $snapshot ) );
		$this->check( 0 === $directory['disk'] && 0 === $directory['start']
			&& count( $expected ) === $directory['entries'] && $directory['entries'] === $directory['entries_disk']
			&& $directory['offset'] + $directory['size'] === $end
			&& $end + 22 + $directory['comment'] === strlen( $bytes ), 'Independent ZIP directory and exact inventory count.' );
		$position = $directory['offset'];
		$common   = null;
		foreach ( $expected as $name ) {
			$central = unpack( 'Vsignature/vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal', substr( $bytes, $position, 46 ) );
			$this->check( 0x02014b50 === $central['signature'] && $name === substr( $bytes, $position + 46, $central['name'] ), 'Independent exact sorted archive entry.' );
			$local = unpack( 'Vsignature/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vsize/vname/vextra', substr( $bytes, $central['local'], 30 ) );
			$this->check( 0x04034b50 === $local['signature'] && $name === substr( $bytes, $central['local'] + 30, $local['name'] )
				&& $local['time'] === $central['time'] && $local['date'] === $central['date'], 'Local and central raw DOS timestamps match.' );
			$fields = array(
				1980 + ( $central['date'] >> 9 ), ( $central['date'] >> 5 ) & 15, $central['date'] & 31,
				$central['time'] >> 11, ( $central['time'] >> 5 ) & 63, ( $central['time'] & 31 ) * 2,
			);
			$stamp = gmmktime( $fields[3], $fields[4], $fields[5], $fields[1], $fields[2], $fields[0] );
			$this->check( gmdate( 'Y-m-d H:i:s', $stamp ) === vsprintf( '%04d-%02d-%02d %02d:%02d:%02d', $fields ), 'DOS fields decode exactly as UTC.' );
			$common = $common ?? $stamp;
			$this->check( $stamp === $common && 0 === $stamp % 2, 'Every entry shares one DOS-precision timestamp.' );
			$this->check( 8 === $central['method'] && $local['method'] === $central['method'] && 0 === ( $central['flags'] & 1 ), 'Fixture entries retain unencrypted DEFLATE compression.' );
			$data = gzinflate( substr( $bytes, $central['local'] + 30 + $local['name'] + $local['extra'], $central['compressed'] ) );
			$this->check( false !== $data && strlen( $data ) === $central['size']
				&& sprintf( '%08x', crc32( $data ) ) === sprintf( '%08x', $central['crc'] )
				&& $local['crc'] === $central['crc'], 'Independent decompression and local/central CRC integrity.' );
			$relative = substr( $name, strlen( 'mtunicredit/' ) );
			$this->check( $data === file_get_contents( $snapshot[ $relative ]['path'] ), 'Independent complete source-to-archive byte parity.' );
			$mode = 'secrets/smartucf-key.php' === $relative ? 0100600 : 0100644;
			$this->check( 3 === ( $central['made'] >> 8 ) && $central['external'] === ( $mode << 16 ), 'Normalized Unix entry permissions preserved.' );
			$normalized = substr_replace( $normalized, "\0\0\0\0", $position + 12, 4 );
			$normalized = substr_replace( $normalized, "\0\0\0\0", $central['local'] + 10, 4 );
			$position  += 46 + $central['name'] + $central['extra'] + $central['comment'];
		}
		$this->check( $position === $end, 'Independent parser consumed the exact central directory.' );
		return array( 'timestamp' => $common, 'content_hash' => hash( 'sha256', $normalized ) );
	}

	/** Build a fixture and require its raw UTC timestamp to fall inside the measured interval. */
	private function timed_build(): array {
		$start = time();
		$facts = $this->package->build();
		$end   = time();
		$meta  = $this->zip_metadata( $facts['path'] );
		$this->check( $meta['timestamp'] >= $start - $start % 2 && $meta['timestamp'] <= $end - $end % 2, 'Current UTC timestamp falls within the measured build interval.' );
		$this->check( $facts === $this->package->verify( $facts['path'] ), 'Timed build passes the production verifier.' );
		return array( $facts, $meta['timestamp'] );
	}

	/**
	 * Synchronize with the next DOS interval without an unbounded wall-clock wait.
	 *
	 * @param int $previous Previous archive timestamp.
	 */
	private function wait_for_interval( int $previous ): void {
		$deadline = hrtime( true ) + 5000000000;
		do {
			$now = time();
			if ( $now - $now % 2 > $previous ) {
				return;
			}
			usleep( 20000 );
		} while ( hrtime( true ) < $deadline );
		throw new RuntimeException( 'Clock did not advance to the next DOS interval within five seconds.' );
	}

	/**
	 * Run an isolated fixture tool, capturing its output.
	 *
	 * @param array $command Argument vector.
	 * @return string Standard output.
	 */
	private function command( array $command ): string {
		$pipes       = array();
		$environment = null;
		if ( 'unzip' === $command[0] ) {
			$environment = getenv();
			unset( $environment['UNZIP'], $environment['UNZIPOPT'] );
		}
		$child = proc_open( $command, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $environment );
		$this->check( is_resource( $child ), 'Fixture subprocess starts.' );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->check( 0 === proc_close( $child ), 'Fixture subprocess succeeds: ' . $command[0] );
		return $output;
	}

	/** Prove current timestamps, source-mtime independence, successive bytes and extracted/placed mtimes. */
	private function test_timestamps(): void {
		$manifest = json_decode( file_get_contents( $this->fixture . '/tools/package-manifest.json' ), true );
		foreach ( $manifest['required_files'] as $index => $relative ) {
			touch( $this->fixture . '/' . $relative, 0 === $index % 2 ? 946684800 : 1893456000 );
		}
		$before = $this->source_fingerprint();
		list( $first, $first_time ) = $this->timed_build();
		$this->check( $before === $this->source_fingerprint(), 'Historical/future source mtimes remain unchanged and do not control ZIP timestamps.' );
		$this->put( 'evidence/first/' . basename( $first['path'] ), file_get_contents( $first['path'] ) );
		$first_path = $this->fixture . '/evidence/first/' . basename( $first['path'] );
		$relative   = 'includes/functions.php';
		$original   = file_get_contents( $this->fixture . '/' . $relative );
		$changed    = $original . "\n// Synthetic timestamp regression.\n";
		$process_tz = getenv( 'TZ' );
		try {
			$this->wait_for_interval( $first_time );
			$this->put( $relative, $changed );
			list( $second, $second_time ) = $this->timed_build();
			$this->check( $second_time > $first_time && $first['sha256'] !== $second['sha256'], 'Changed PHP across distinct DOS intervals has a newer ZIP timestamp and different archive bytes.' );
			putenv( 'TZ=UTC' );
			foreach ( array( $first_path, $second['path'] ) as $index => $path ) {
				$this->command( array( 'unzip', '-tqq', $path ) );
				$destination = $this->fixture . '/evidence/extracted-' . $index;
				$this->command( array( 'unzip', '-qq', $path, '-d', $destination ) );
				$php = $destination . '/mtunicredit/' . $relative;
				clearstatcache( true, $php );
				$this->check( file_get_contents( $php ) === ( 0 === $index ? $original : $changed ), 'Independent extraction preserves the expected changed PHP bytes.' );
				$this->check( filemtime( $php ) === ( 0 === $index ? $first_time : $second_time ), 'UTC extraction preserves each distinct ZIP timestamp without touching files.' );
			}
			$placed = $this->fixture . '/evidence/placed.php';
			rename( $php, $placed );
			$this->check( filemtime( $placed ) === $second_time && file_get_contents( $placed ) === $changed, 'Rename placement preserves extracted PHP bytes and mtime.' );
			$this->wait_for_interval( $second_time );
			$start  = time();
			$copied = $this->fixture . '/evidence/copied.php';
			copy( $placed, $copied );
			clearstatcache( true, $copied );
			$this->check( file_get_contents( $copied ) === $changed && filemtime( $copied ) > $second_time
				&& filemtime( $copied ) >= $start && filemtime( $copied ) <= time(), 'Ordinary copy preserves PHP bytes but sets a new placement mtime.' );
		} finally {
			putenv( false === $process_tz ? 'TZ' : 'TZ=' . $process_tz );
			$this->put( $relative, $original );
		}
		$this->facts = $this->package->build();
	}

	/** Exercise all caller TZ states and fail timestamp assignment inside the real ZIP-writing flow. */
	private function test_timezones(): void {
		// Fault injection changes only the ZipArchive construction in a private builder copy.
		$source = file_get_contents( $this->fixture . '/tools/class-mtuc-distribution-package.php' );
		$source = str_replace( 'new ZipArchive()', 'new Mtuc_Failing_ZipArchive()', $source, $replacements );
		$this->check( 2 === $replacements, 'Fault injection targets only the two ZipArchive constructions.' );
		$this->put( 'tools/failing-package.php', $source );
		$this->put( 'tools/fail-timestamp.php', <<<'PHP'
<?php
class Mtuc_Failing_ZipArchive extends ZipArchive {
	public static $assignment_utc = false;
	public static $close_utc = false;
	public function setMtimeName( string $name, int $timestamp, int $flags = 0 ): bool {
		self::$assignment_utc = 'UTC' === getenv('TZ') && 'UTC' === date_default_timezone_get();
		return false;
	}
	public function close(): bool {
		self::$close_utc = 'UTC' === getenv('TZ') && 'UTC' === date_default_timezone_get();
		return parent::close();
	}
}
require __DIR__ . '/failing-package.php';
date_default_timezone_set('Pacific/Auckland');
$before = array(getenv('TZ'), date_default_timezone_get());
$failed = false;
try {
	(new Mtuc_Distribution_Package(dirname(__DIR__), __DIR__ . '/package-manifest.json'))->build();
} catch (RuntimeException $error) {
	$failed = false !== strpos($error->getMessage(), 'Cannot write distribution entry:');
}
echo json_encode(array(
	'failed' => $failed,
	'assignment_utc' => Mtuc_Failing_ZipArchive::$assignment_utc,
	'close_utc' => Mtuc_Failing_ZipArchive::$close_utc,
	'before' => $before,
	'after' => array(getenv('TZ'), date_default_timezone_get()),
));
PHP
		);
		$process_tz = getenv( 'TZ' );
		$php_tz     = date_default_timezone_get();
		try {
			date_default_timezone_set( 'Pacific/Auckland' );
			foreach ( array( false, '', 'UTC', 'Europe/Sofia', 'America/New_York' ) as $caller_tz ) {
				putenv( false === $caller_tz ? 'TZ' : 'TZ=' . $caller_tz );
				list( $facts ) = $this->timed_build();
				$this->facts = $facts;
				$this->check( getenv( 'TZ' ) === $caller_tz && 'Pacific/Auckland' === date_default_timezone_get(), 'Successful build restores the exact process and PHP timezone states.' );
				$stages = glob( sys_get_temp_dir() . '/mtuc-package-*' );
				$failure = json_decode( $this->command( array( PHP_BINARY, $this->fixture . '/tools/fail-timestamp.php' ) ), true );
				$this->check( $failure['failed'] && $failure['assignment_utc'] && $failure['close_utc'], 'Timestamp assignment fails inside UTC and ZIP closing remains inside UTC.' );
				$this->check( $failure['before'] === array( $caller_tz, 'Pacific/Auckland' ) && $failure['after'] === $failure['before'], 'In-ZIP failure restores exact timezone states, including unset versus empty TZ.' );
				$this->check( $facts['sha256'] === hash_file( 'sha256', $facts['path'] ) && $stages === glob( sys_get_temp_dir() . '/mtuc-package-*' ), 'In-ZIP failure preserves exact previous installer bytes and cleans staging.' );
				$config = file_get_contents( $this->fixture . '/includes/config.php' );
				$deny   = file_get_contents( $this->fixture . '/dist/.htaccess' );
				foreach ( array( 'input', 'publication' ) as $phase ) {
					try {
						if ( 'input' === $phase ) {
							unlink( $this->fixture . '/includes/config.php' );
						} else {
							$this->put( 'dist/.htaccess', 'synthetic publication failure' );
						}
						$this->reject( function (): void { $this->package->build(); }, 'Failure before/after ZIP operation is rejected: ' . $phase );
						$this->check( getenv( 'TZ' ) === $caller_tz && 'Pacific/Auckland' === date_default_timezone_get(), 'Input/publication failure preserves caller timezone states.' );
						$this->check( $facts['sha256'] === hash_file( 'sha256', $facts['path'] ) && $stages === glob( sys_get_temp_dir() . '/mtuc-package-*' ), 'Input/publication failure preserves exact installer bytes and cleans staging.' );
					} finally {
						$this->put( 'includes/config.php', $config );
						$this->put( 'dist/.htaccess', $deny );
					}
				}
			}
		} finally {
			putenv( false === $process_tz ? 'TZ' : 'TZ=' . $process_tz );
			date_default_timezone_set( $php_tz );
		}
	}

	/** Clean only previous regular installer files after success; preserve all outputs on build/verify failure. */
	private function test_installer_cleanup(): void {
		$previous = array( 'woo_uni_0.0.1.zip', 'woo_uni_1.2.3-beta.1.zip', 'woo_uni_1.2.3+build.7.zip' );
		$unrelated = array(
			'other.zip',
			'woo_uni_latest.zip',
			'woo_uni_1.2.zip',
			'woo_uni_1.2.3.4.zip',
			'woo_uni_1.2.3.zip.bak',
			'woo_uni_1.2.3.zip.sha256',
			'woo_uni_1.2.3-backup.zip.tmp',
			'prefix_woo_uni_1.2.3.zip',
			"woo_uni_1.2.3.zip\n",
		);
		foreach ( array_merge( $previous, $unrelated ) as $name ) {
			$this->put( 'dist/' . $name, 'preserved artifact fixture' );
		}
		$this->put( 'outside/woo_uni_0.0.2.zip', 'outside installer fixture' );
		$this->put( 'dist/woo_uni_0.0.3.zip/nested.zip', 'directory fixture' );
		$this->put( 'dist/backups/woo_uni_0.0.4.zip', 'nested backup fixture' );
		symlink( $this->fixture . '/outside/woo_uni_0.0.2.zip', $this->fixture . '/dist/woo_uni_0.0.2.zip' );
		symlink( $this->fixture . '/outside/missing.zip', $this->fixture . '/dist/woo_uni_0.0.5.zip' );
		symlink( $this->fixture . '/outside', $this->fixture . '/dist/woo_uni_0.0.6.zip' );
		$content_hash = $this->zip_metadata( $this->facts['path'] )['content_hash'];
		$built = $this->package->build();
		$this->check( $content_hash === $this->zip_metadata( $built['path'] )['content_hash'], 'Cleanup preserves every archive byte except build timestamps.' );
		$this->check( $built === $this->package->verify( $built['path'] ), 'New installer remains present and valid after cleanup.' );
		foreach ( $previous as $name ) {
			$this->check( ! file_exists( $this->fixture . '/dist/' . $name ), 'Previous generated installer removed: ' . $name );
		}
		foreach ( $unrelated as $name ) {
			$this->check( 'preserved artifact fixture' === file_get_contents( $this->fixture . '/dist/' . $name ), 'Unrelated artifact preserved: ' . trim( $name ) );
		}
		foreach ( array( '0.0.2', '0.0.5', '0.0.6' ) as $version ) {
			$this->check( is_link( $this->fixture . '/dist/woo_uni_' . $version . '.zip' ), 'Installer symlink preserved without following it.' );
		}
		$this->check( 'outside installer fixture' === file_get_contents( $this->fixture . '/outside/woo_uni_0.0.2.zip' ), 'Outside installer untouched.' );
		$this->check( 'directory fixture' === file_get_contents( $this->fixture . '/dist/woo_uni_0.0.3.zip/nested.zip' ), 'Matching directory and contents preserved.' );
		$this->check( 'nested backup fixture' === file_get_contents( $this->fixture . '/dist/backups/woo_uni_0.0.4.zip' ), 'Nested backup installer untouched.' );
		$repeat = $this->package->build();
		$this->check( $content_hash === $this->zip_metadata( $repeat['path'] )['content_hash'], 'Repeated cleanup is idempotent apart from build timestamps.' );
		$this->check( $repeat === $this->package->verify( $repeat['path'] ), 'Repeated cleanup leaves a verified installer.' );
		$built       = $repeat;
		$this->facts = $repeat;

		$this->put( 'dist/' . $previous[0], 'previous installer preserved on failure' );
		$config = file_get_contents( $this->fixture . '/includes/config.php' );
		$deny = file_get_contents( $this->fixture . '/dist/.htaccess' );
		try {
			unlink( $this->fixture . '/includes/config.php' );
			$this->reject( function (): void { $this->package->build(); }, 'Build failure skips installer cleanup.' );
			$this->check( 'previous installer preserved on failure' === file_get_contents( $this->fixture . '/dist/' . $previous[0] ), 'Previous version survives failed generation.' );
			$this->check( $built['sha256'] === hash_file( 'sha256', $built['path'] ), 'Current installer survives failed generation.' );
			$this->put( 'includes/config.php', $config . "\n// verification failure fixture\n" );
			$this->reject( function () use ( $built ): void { $this->package->verify( $built['path'] ); }, 'Verification failure never triggers cleanup.' );
			$this->check( 'previous installer preserved on failure' === file_get_contents( $this->fixture . '/dist/' . $previous[0] ), 'Previous version survives failed verification.' );
			$this->check( $built['sha256'] === hash_file( 'sha256', $built['path'] ), 'Current installer survives failed verification.' );
			$this->put( 'includes/config.php', $config );
			$this->put( 'dist/.htaccess', 'custom protection' );
			$this->reject( function (): void { $this->package->build(); }, 'Publication failure after ZIP verification skips cleanup.' );
			$this->check( 'previous installer preserved on failure' === file_get_contents( $this->fixture . '/dist/' . $previous[0] ), 'Previous version survives failed publication.' );
			$this->check( $built['sha256'] === hash_file( 'sha256', $built['path'] ), 'Current installer survives failed publication.' );
		} finally {
			$this->put( 'includes/config.php', $config );
			$this->put( 'dist/.htaccess', $deny );
		}
		rename( $this->fixture . '/dist', $this->fixture . '/saved-dist' );
		symlink( $this->fixture . '/outside', $this->fixture . '/dist' );
		try {
			$this->reject( function (): void { $this->package->build(); }, 'Symlinked dist directory rejected before publication and cleanup.' );
			$this->check( 'outside installer fixture' === file_get_contents( $this->fixture . '/outside/woo_uni_0.0.2.zip' ), 'Symlinked dist does not delete outside installer.' );
			$this->check( 'previous installer preserved on failure' === file_get_contents( $this->fixture . '/saved-dist/' . $previous[0] ), 'Symlinked dist leaves existing installers untouched.' );
		} finally {
			unlink( $this->fixture . '/dist' );
			rename( $this->fixture . '/saved-dist', $this->fixture . '/dist' );
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
			$this->facts = $this->package->verify( $this->facts['path'] );
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
		$wrong = $bad_dir . '/woo_uni_999.0.0.zip';
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
		$this->check( 'woo_uni_9.8.7.zip' === basename( $facts['path'] ), 'Changed runtime version drives filename.' );
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
