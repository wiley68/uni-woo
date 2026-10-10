<?php
/**
 * Offline distribution builder; never bootstraps WordPress or changes runtime source.
 *
 * @package MTUC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Standalone CLI tooling uses local filesystem APIs, not WordPress runtime APIs.
// phpcs:disable WordPress.Security.EscapeOutput -- Exceptions are plain CLI diagnostics, not HTML output.

/** Creates and verifies the audited WooCommerce runtime distribution. */
final class Mtuc_Distribution_Package {

	/** Archive plugin directory. */
	public const ROOT = 'mtunicredit/';

	/** ZIP timestamp, independent of source mtimes and the build clock. */
	private const ZIP_TIME = 315532800;

	/** Apache protection for sensitive local build outputs. */
	private const PROTECTION = "# Generated deployment artifacts: deny HTTP access.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";

	/**
	 * Authoritative source root.
	 *
	 * @var string
	 */
	private $source;

	/**
	 * Audited runtime paths.
	 *
	 * @var array<string, mixed>
	 */
	private $manifest;

	/**
	 * Read an audited manifest without consulting Git or recovery copies.
	 *
	 * @param string $source        Working source root.
	 * @param string $manifest_path Manifest location.
	 * @throws RuntimeException If packaging validation or local I/O fails.
	 */
	public function __construct( string $source, string $manifest_path ) {
		$resolved = realpath( $source );
		if ( false === $resolved || ! is_dir( $resolved ) ) {
			throw new RuntimeException( 'Source directory does not exist.' );
		}
		$this->source   = $resolved;
		$this->manifest = json_decode( self::read( $manifest_path ), true );
		if ( ! is_array( $this->manifest ) || empty( $this->manifest['required_files'] )
			|| ! isset( $this->manifest['translation_extensions'] ) ) {
			throw new RuntimeException( 'Invalid distribution manifest.' );
		}
		foreach ( array( 'mtunicredit.php', 'includes/config.php', 'secrets/smartucf-key.php', 'keys/.htaccess', 'keys/index.php' ) as $required ) {
			if ( ! in_array( $required, $this->manifest['required_files'], true ) ) {
				throw new RuntimeException( 'Distribution manifest omits a mandatory runtime/deployment input.' );
			}
		}
	}

	/**
	 * Resolve the sole literal runtime version and require matching WordPress metadata.
	 *
	 * @param string $source Main plugin PHP bytes.
	 * @return string Version safe for the required filename.
	 * @throws RuntimeException If packaging validation or local I/O fails.
	 */
	public static function version( string $source ): string {
		$tokens = array();
		foreach ( token_get_all( $source ) as $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$tokens[] = $token;
			}
		}
		$count   = 0;
		$version = null;
		foreach ( $tokens as $index => $token ) {
			if ( is_array( $token ) && T_CONST === $token[0] && 'MTUC_VERSION' === ( $tokens[ $index + 1 ][1] ?? null ) ) {
				throw new RuntimeException( 'Unexpected alternative MTUC_VERSION declaration.' );
			}
			$previous = $tokens[ $index - 1 ] ?? null;
			if ( ! is_array( $token ) || 'define' !== strtolower( ltrim( $token[1], '\\' ) )
				|| ( is_array( $previous ) && in_array( $previous[1], array( '->', '?->', '::' ), true ) )
				|| '(' !== ( $tokens[ $index + 1 ] ?? null ) ) {
				continue;
			}
			$name = $tokens[ $index + 2 ] ?? null;
			if ( ! is_array( $name ) || T_CONSTANT_ENCAPSED_STRING !== $name[0]
				|| ! in_array( $name[1], array( "'MTUC_VERSION'", '"MTUC_VERSION"' ), true ) ) {
				continue;
			}
			++$count;
			$value = $tokens[ $index + 4 ] ?? null;
			if ( ',' === ( $tokens[ $index + 3 ] ?? null ) && is_array( $value ) && T_CONSTANT_ENCAPSED_STRING === $value[0]
				&& ')' === ( $tokens[ $index + 5 ] ?? null )
				&& preg_match( '/\A([\'"])([0-9]+\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9.-]+)?)\1\z/D', $value[1], $match ) ) {
				$version = $match[2];
			}
		}
		preg_match_all( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', substr( $source, 0, 8192 ), $headers );
		if ( 1 !== $count || null === $version || 1 !== count( $headers[1] ) || $version !== $headers[1][0] ) {
			throw new RuntimeException( 'Plugin version is missing, ambiguous or differs from the WordPress header.' );
		}
		return $version;
	}

	/**
	 * Construct a sorted snapshot from explicit runtime paths and supported local artifacts.
	 *
	 * @return array<string, array<string, mixed>> Sizes, checksums and source paths; never printed.
	 * @throws RuntimeException If packaging validation or local I/O fails.
	 */
	public function snapshot(): array {
		$composer = json_decode( self::read( $this->source . '/composer.json' ), true );
		if ( ! is_array( $composer ) || ! isset( $composer['require'] ) || ! is_array( $composer['require'] ) ) {
			throw new RuntimeException( 'Cannot audit production Composer dependencies.' );
		}
		foreach ( array_keys( $composer['require'] ) as $dependency ) {
			if ( 'php' !== $dependency && 0 !== strpos( $dependency, 'ext-' ) && 0 !== strpos( $dependency, 'lib-' ) ) {
				throw new RuntimeException( 'Runtime Composer dependencies changed; update the distribution manifest and production install workflow.' );
			}
		}

		// keys/ is runtime data. Never enumerate or read certificates, locks or leases.
		$paths = $this->manifest['required_files'];
		foreach ( new DirectoryIterator( $this->source . '/languages' ) as $file ) {
			if ( $file->isDot() || $file->isDir() ) {
				continue;
			}
			foreach ( $this->manifest['translation_extensions'] as $extension ) {
				if ( preg_match( '/\A[a-zA-Z0-9_.@-]+\.' . preg_quote( $extension, '/' ) . '\z/D', $file->getFilename() ) ) {
					$paths[] = 'languages/' . $file->getFilename();
					break;
				}
			}
		}
		$paths = array_values( array_unique( $paths ) );
		sort( $paths, SORT_STRING );
		$result = array();
		foreach ( $paths as $relative ) {
			if ( ! is_string( $relative ) || ! preg_match( '/\A[a-zA-Z0-9_.@-]+(?:\/[a-zA-Z0-9_.@-]+)*\z/D', $relative )
				|| in_array( '..', explode( '/', $relative ), true ) ) {
				throw new RuntimeException( 'Unsafe manifest path.' );
			}
			if ( 0 === strpos( $relative, 'keys/' ) && ! in_array( $relative, array( 'keys/.htaccess', 'keys/index.php' ), true ) ) {
				throw new RuntimeException( 'Runtime certificate/lease material is forbidden in the distribution manifest.' );
			}
			$path   = $this->source;
			$pieces = explode( '/', $relative );
			foreach ( $pieces as $piece ) {
				$path .= '/' . $piece;
				if ( is_link( $path ) ) {
					throw new RuntimeException( 'Symlinks are not allowed in deployment inputs: ' . $relative );
				}
			}
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				throw new RuntimeException( 'Missing or unreadable required runtime/deployment file: ' . $relative );
			}
			$bytes = self::read( $path );
			if ( '' === $bytes ) {
				throw new RuntimeException( 'Empty required runtime/deployment file: ' . $relative );
			}
			$result[ $relative ] = array(
				'path'   => $path,
				'size'   => strlen( $bytes ),
				'sha256' => hash( 'sha256', $bytes ),
			);
		}
		self::version( self::read( $this->source . '/mtunicredit.php' ) );
		$this->validate_secret();
		return $result;
	}

	/**
	 * Validate the existing runtime secret return shape in an isolated PHP process.
	 *
	 * Capture and discard all child output, including PHP errors and secret-file output.
	 * No environment fallback substitutes for the production file.
	 *
	 * @return void
	 * @throws RuntimeException If packaging validation or local I/O fails.
	 */
	private function validate_secret(): void {
		$script = 'ini_set("display_errors", "0"); ob_start(); define("ABSPATH", $argv[2] . "/");'
			. 'try { $v = include $argv[1]; $ok = is_array($v) && isset($v["smartucf_key_password"])'
			. ' && is_string($v["smartucf_key_password"]) && trim($v["smartucf_key_password"]) !== ""; }'
			. 'catch (Throwable $e) { $ok = false; } ob_end_clean(); if ($ok) { fwrite(STDOUT, "valid"); } exit($ok ? 0 : 1);';
		$pipes  = array();
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- CLI-only isolated secret validation; capture all output.
		$child = proc_open(
			array( PHP_BINARY, '-r', $script, '--', $this->source . '/secrets/smartucf-key.php', $this->source ),
			array(
				0 => array( 'pipe', 'r' ),
				1 => array( 'pipe', 'w' ),
				2 => array( 'file', '/dev/null', 'a' ),
			),
			$pipes
		);
		if ( ! is_resource( $child ) ) {
			throw new RuntimeException( 'Cannot validate deployment secret.' );
		}
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		fclose( $pipes[1] );
		$exit = proc_close( $child );
		if ( 0 !== $exit || 'valid' !== $output ) {
			throw new RuntimeException( 'Invalid deployment file: secrets/smartucf-key.php must return a nonempty smartucf_key_password string.' );
		}
	}

	/**
	 * Stage, verify and atomically publish deterministic bytes under source dist/.
	 *
	 * @return array<string, mixed> Nonsecret artifact facts.
	 * @throws RuntimeException If packaging validation or local I/O fails.
	 */
	public function build(): array {
		self::require_zip();
		$snapshot = $this->snapshot();
		$version  = self::version( self::read( $snapshot['mtunicredit.php']['path'] ) );
		$filename = self::filename( $version );
		$stage    = sys_get_temp_dir() . '/mtuc-package-' . bin2hex( random_bytes( 12 ) );
		self::directory( $stage, 0700 );
		$timezone = date_default_timezone_get();
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- CLI ZIP metadata uses UTC; restored in finally.
		date_default_timezone_set( 'UTC' );
		try {
			foreach ( $snapshot as $relative => $entry ) {
				$target = $stage . '/' . self::ROOT . $relative;
				self::directory( dirname( $target ), 0700 );
				self::write( $target, self::read( $entry['path'] ), 0600 );
				if ( hash_file( 'sha256', $target ) !== $entry['sha256'] ) {
					throw new RuntimeException( 'Source changed while staging: ' . $relative );
				}
			}
			$archive = $stage . '/' . $filename;
			$zip     = new ZipArchive();
			if ( true !== $zip->open( $archive, ZipArchive::CREATE | ZipArchive::EXCL ) ) {
				throw new RuntimeException( 'Cannot create distribution archive.' );
			}
			try {
				foreach ( array_keys( $snapshot ) as $relative ) {
					$name = self::ROOT . $relative;
					$mode = 'secrets/smartucf-key.php' === $relative ? 0100600 : 0100644;
					if ( ! $zip->addFile( $stage . '/' . $name, $name )
						|| ! $zip->setMtimeName( $name, self::ZIP_TIME )
						|| ! $zip->setCompressionName( $name, ZipArchive::CM_DEFLATE, 9 )
						|| ! $zip->setExternalAttributesName( $name, ZipArchive::OPSYS_UNIX, $mode << 16 ) ) {
						throw new RuntimeException( 'Cannot write distribution entry: ' . $relative );
					}
				}
			} finally {
				if ( ! $zip->close() ) {
					throw new RuntimeException( 'Cannot finalize distribution archive.' );
				}
			}
			$facts = $this->verify( $archive, $snapshot );
			$dist  = $this->source . '/dist';
			if ( is_link( $dist ) ) {
				throw new RuntimeException( 'Distribution directory must not be a symlink.' );
			}
			self::directory( $dist, 0700 );
			if ( file_exists( $dist . '/.htaccess' ) || is_link( $dist . '/.htaccess' ) ) {
				if ( is_link( $dist . '/.htaccess' ) || self::read( $dist . '/.htaccess' ) !== self::PROTECTION ) {
					throw new RuntimeException( 'Existing dist/.htaccess differs from generated deny-access protection; review it before building.' );
				}
			} else {
				self::write( $dist . '/.htaccess', self::PROTECTION, 0600 );
			}
			$pending = $dist . '/.package-' . bin2hex( random_bytes( 12 ) ) . '.tmp';
			try {
				self::write( $pending, self::read( $archive ), 0600 );
				if ( hash_file( 'sha256', $pending ) !== $facts['sha256'] ) {
					throw new RuntimeException( 'Archive changed during publication.' );
				}
				// Recheck the working source immediately before publishing, including local artifacts.
				if ( $this->snapshot() !== $snapshot ) {
					throw new RuntimeException( 'Source changed during packaging; retry from a stable working tree.' );
				}
				if ( ! rename( $pending, $dist . '/' . $filename ) ) {
					throw new RuntimeException( 'Cannot publish distribution archive.' );
				}
			} finally {
				if ( is_file( $pending ) ) {
					unlink( $pending );
				}
			}
			$facts['path'] = $dist . '/' . $filename;
			return $facts;
		} finally {
			// phpcs:ignore WordPress.DateTime.RestrictedFunctions.timezone_change_date_default_timezone_set -- Restore the CLI caller timezone.
			date_default_timezone_set( $timezone );
			self::remove_tree( $stage );
		}
	}

	/**
	 * Reject noncanonical archives and compare every runtime file with current source.
	 *
	 * @param string     $archive  ZIP location.
	 * @param array|null $snapshot Internal stable snapshot, or current source when omitted.
	 * @return array<string, mixed> Nonsecret validation facts.
	 * @throws RuntimeException If packaging validation or local I/O fails.
	 */
	public function verify( string $archive, ?array $snapshot = null ): array {
		self::require_zip();
		$snapshot = $snapshot ?? $this->snapshot();
		$version  = self::version( self::read( $snapshot['mtunicredit.php']['path'] ) );
		if ( basename( $archive ) !== self::filename( $version ) || is_link( $archive ) ) {
			throw new RuntimeException( 'Archive filename does not match the current runtime version, or is a symlink.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive, ZipArchive::CHECKCONS ) ) {
			throw new RuntimeException( 'Malformed or unreadable distribution ZIP.' );
		}
		try {
			// Reject runtime data even before checking counts or source parity.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native ZipArchive property.
			for ( $index = 0; $index < $zip->numFiles; ++$index ) {
				$name = $zip->getNameIndex( $index );
				if ( 0 === strpos( $name, self::ROOT . 'keys/' ) && ! in_array( $name, array( self::ROOT . 'keys/.htaccess', self::ROOT . 'keys/index.php' ), true ) ) {
					throw new RuntimeException( 'Distribution ZIP contains forbidden certificate/lease runtime material.' );
				}
			}
			$expected = array_map(
				static function ( string $name ): string {
					return self::ROOT . $name;
				},
				array_keys( $snapshot )
			);
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive exposes this native property.
			if ( count( $expected ) !== $zip->numFiles ) {
				throw new RuntimeException( 'Archive has missing or unexpected runtime entries.' );
			}
			foreach ( $expected as $index => $name ) {
				$stat = $zip->statIndex( $index );
				if ( ! is_array( $stat ) || $stat['name'] !== $name ) {
					throw new RuntimeException( 'Archive root, manifest, entry order or uniqueness is invalid.' );
				}
				$relative = substr( $name, strlen( self::ROOT ) );
				$entry    = $snapshot[ $relative ];
				$opsys    = 0;
				$attr     = 0;
				if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attr )
					|| ZipArchive::OPSYS_UNIX !== $opsys || 0100000 !== ( ( $attr >> 16 ) & 0170000 )
					|| $stat['size'] !== $entry['size'] ) {
					throw new RuntimeException( 'Archive entry type or size mismatch: ' . $relative );
				}
				$bytes = $zip->getFromIndex( $index );
				if ( false === $bytes || hash( 'sha256', $bytes ) !== $entry['sha256'] ) {
					throw new RuntimeException( 'Archive/source parity mismatch: ' . $relative );
				}
				if ( 'mtunicredit.php' === $relative && self::version( $bytes ) !== $version ) {
					throw new RuntimeException( 'Packaged runtime version mismatch.' );
				}
			}
		} finally {
			$zip->close();
		}
		return array(
			'path'    => $archive,
			'version' => $version,
			'files'   => count( $snapshot ),
			'bytes'   => filesize( $archive ),
			'sha256'  => hash_file( 'sha256', $archive ),
		);
	}

	/**
	 * Mandatory distribution filename.
	 *
	 * @param string $version Validated runtime version.
	 * @return string ZIP basename.
	 */
	public static function filename( string $version ): string {
		return 'woo_uni_' . $version . '.zip';
	}

	/**
	 * Check deterministic ZIP support before copying deployment inputs.
	 *
	 * @throws RuntimeException If deterministic ZIP support is unavailable.
	 */
	private static function require_zip(): void {
		if ( ! class_exists( 'ZipArchive' ) || ! method_exists( 'ZipArchive', 'setMtimeName' ) ) {
			throw new RuntimeException( 'Packaging requires PHP 7.4+ with ext-zip and setMtimeName support.' );
		}
	}

	/**
	 * Read bytes without including potentially sensitive OS error output.
	 *
	 * @param string $path File location.
	 * @return string Unmodified contents.
	 * @throws RuntimeException If packaging validation or local I/O fails.
	 */
	private static function read( string $path ): string {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Report a content-free error below.
		$bytes = @file_get_contents( $path );
		if ( false === $bytes ) {
			throw new RuntimeException( 'Cannot read a required packaging input.' );
		}
		return $bytes;
	}

	/**
	 * Create a private local directory.
	 *
	 * @param string $path Directory.
	 * @param int    $mode Permissions.
	 * @throws RuntimeException If packaging validation or local I/O fails.
	 */
	private static function directory( string $path, int $mode ): void {
		if ( ! is_dir( $path ) && ! mkdir( $path, $mode, true ) ) {
			throw new RuntimeException( 'Cannot create a packaging directory.' );
		}
	}

	/**
	 * Write complete bytes with fixed local permissions.
	 *
	 * @param string $path  Target.
	 * @param string $bytes Data.
	 * @param int    $mode  Permissions.
	 * @throws RuntimeException If packaging validation or local I/O fails.
	 */
	private static function write( string $path, string $bytes, int $mode ): void {
		if ( file_put_contents( $path, $bytes ) !== strlen( $bytes ) || ! chmod( $path, $mode ) ) {
			throw new RuntimeException( 'Cannot write a private packaging file.' );
		}
	}

	/**
	 * Remove only a builder-owned private staging tree; do not follow symlinks.
	 *
	 * @param string $path Temporary directory.
	 */
	public static function remove_tree( string $path ): void {
		if ( is_link( $path ) || ! is_dir( $path ) ) {
			unlink( $path );
			return;
		}
		foreach ( new DirectoryIterator( $path ) as $entry ) {
			if ( ! $entry->isDot() ) {
				self::remove_tree( $entry->getPathname() );
			}
		}
		rmdir( $path );
	}
}
