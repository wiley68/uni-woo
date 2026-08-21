<?php
/**
 * Local filesystem store for UniCredit client certificates.
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative local cert/key paths, locking, atomic replacement, and leases.
 */
class Mtuc_Certificate_Local_Store {

	/** Relative plugin subdirectory for certificates. */
	public const KEYS_SUBDIR = 'keys';

	/** Authoritative certificate filename. */
	public const CERT_FILENAME = 'avalon_cert.pem';

	/** Authoritative private key filename. */
	public const KEY_FILENAME = 'avalon_private_key.pem';

	/** Sync lock filename. */
	public const LOCK_FILENAME = '.mtuc-ssl-sync.lock';

	/**
	 * Optional override of keys directory (tests only).
	 *
	 * @var string|null
	 */
	public static $keys_dir_override = null;

	/** @var resource|null Held flock handle. */
	private static $lock_handle = null;

	/**
	 * Absolute keys directory.
	 *
	 * @return string
	 */
	public static function get_keys_dir(): string {
		if ( is_string( self::$keys_dir_override ) && '' !== self::$keys_dir_override ) {
			return rtrim( self::$keys_dir_override, '/\\' );
		}

		return trailingslashit( MTUC_PLUGIN_DIR ) . self::KEYS_SUBDIR;
	}

	/**
	 * Absolute authoritative certificate path.
	 *
	 * @return string
	 */
	public static function get_cert_path(): string {
		return trailingslashit( self::get_keys_dir() ) . self::CERT_FILENAME;
	}

	/**
	 * Absolute authoritative private key path.
	 *
	 * @return string
	 */
	public static function get_key_path(): string {
		return trailingslashit( self::get_keys_dir() ) . self::KEY_FILENAME;
	}

	/**
	 * Absolute sync lock path.
	 *
	 * @return string
	 */
	public static function get_lock_path(): string {
		return trailingslashit( self::get_keys_dir() ) . self::LOCK_FILENAME;
	}

	/**
	 * Read authoritative pair bytes.
	 *
	 * @return array{cert_pem:string,key_pem:string}|WP_Error
	 */
	public static function read_pair() {
		$cert_path = self::get_cert_path();
		$key_path  = self::get_key_path();

		if ( ! is_readable( $cert_path ) || ! is_readable( $key_path ) ) {
			return new WP_Error(
				'mtuc_ssl_files_unreadable',
				__( 'Локалните SSL файлове липсват или не могат да бъдат прочетени.', 'mtunicredit' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cert_pem = file_get_contents( $cert_path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$key_pem = file_get_contents( $key_path );

		if ( ! is_string( $cert_pem ) || ! is_string( $key_pem ) || '' === $cert_pem || '' === $key_pem ) {
			return new WP_Error(
				'mtuc_ssl_pair_empty',
				__( 'Локалният SSL сертификат или ключ е празен.', 'mtunicredit' )
			);
		}

		return array(
			'cert_pem' => $cert_pem,
			'key_pem'  => $key_pem,
		);
	}

	/**
	 * Acquire exclusive sync lock with bounded wait.
	 *
	 * @param int $timeout_seconds Max seconds to wait.
	 * @return true|WP_Error
	 */
	public static function acquire_lock( int $timeout_seconds = 10 ) {
		if ( null !== self::$lock_handle ) {
			return true;
		}

		$dir = self::get_keys_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'mtuc_ssl_lock_failed',
				__( 'Неуспешно създаване на директорията за SSL ключове.', 'mtunicredit' )
			);
		}

		$lock_path = self::get_lock_path();
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$handle = fopen( $lock_path, 'c+' );
		if ( false === $handle ) {
			return new WP_Error(
				'mtuc_ssl_lock_failed',
				__( 'Неуспешно заключване за обновяване на SSL сертификата.', 'mtunicredit' )
			);
		}

		$deadline = microtime( true ) + max( 1, $timeout_seconds );
		$locked   = false;

		while ( microtime( true ) < $deadline ) {
			if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
				$locked = true;
				break;
			}
			usleep( 100000 );
		}

		if ( ! $locked ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
			return new WP_Error(
				'mtuc_ssl_lock_timeout',
				__( 'Изчакването за обновяване на SSL сертификата изтече.', 'mtunicredit' )
			);
		}

		self::$lock_handle = $handle;
		@chmod( $lock_path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		return true;
	}

	/**
	 * Release sync lock.
	 *
	 * @return void
	 */
	public static function release_lock(): void {
		if ( null === self::$lock_handle ) {
			return;
		}

		flock( self::$lock_handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( self::$lock_handle );
		self::$lock_handle = null;
	}

	/**
	 * Atomically replace the authoritative pair after staging + validation.
	 *
	 * On failure the previous complete pair remains untouched.
	 *
	 * @param string $cert_pem Certificate PEM bytes.
	 * @param string $key_pem  Private key PEM bytes.
	 * @return true|WP_Error
	 */
	public static function replace_pair( string $cert_pem, string $key_pem ) {
		$valid = Mtuc_Certificate_Pair_Validator::validate_pair( $cert_pem, $key_pem );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$dir = self::get_keys_dir();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'mtuc_ssl_replace_failed',
				__( 'Неуспешно създаване на директорията за SSL ключове.', 'mtunicredit' )
			);
		}

		$cert_path = self::get_cert_path();
		$key_path  = self::get_key_path();
		$stamp     = (string) microtime( true );
		$stage_cert = $cert_path . '.staging.' . $stamp;
		$stage_key  = $key_path . '.staging.' . $stamp;
		$backup_cert = $cert_path . '.bak.' . $stamp;
		$backup_key  = $key_path . '.bak.' . $stamp;

		$wrote_cert = self::write_private_file( $stage_cert, $cert_pem, 0640 );
		if ( is_wp_error( $wrote_cert ) ) {
			self::cleanup_files( array( $stage_cert, $stage_key ) );
			return $wrote_cert;
		}

		$wrote_key = self::write_private_file( $stage_key, $key_pem, 0600 );
		if ( is_wp_error( $wrote_key ) ) {
			self::cleanup_files( array( $stage_cert, $stage_key ) );
			return $wrote_key;
		}

		$stage_valid = Mtuc_Certificate_Pair_Validator::validate_files( $stage_cert, $stage_key );
		if ( is_wp_error( $stage_valid ) ) {
			self::cleanup_files( array( $stage_cert, $stage_key ) );
			return $stage_valid;
		}

		$had_cert = is_file( $cert_path );
		$had_key  = is_file( $key_path );

		if ( $had_cert && ! @rename( $cert_path, $backup_cert ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::cleanup_files( array( $stage_cert, $stage_key ) );
			return new WP_Error(
				'mtuc_ssl_replace_failed',
				__( 'Неуспешен backup на текущия SSL сертификат.', 'mtunicredit' )
			);
		}
		if ( $had_key && ! @rename( $key_path, $backup_key ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $had_cert && is_file( $backup_cert ) ) {
				@rename( $backup_cert, $cert_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			self::cleanup_files( array( $stage_cert, $stage_key, $backup_key ) );
			return new WP_Error(
				'mtuc_ssl_replace_failed',
				__( 'Неуспешен backup на текущия SSL ключ.', 'mtunicredit' )
			);
		}

		if ( ! @rename( $stage_cert, $cert_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::restore_backups( $had_cert, $backup_cert, $cert_path, $had_key, $backup_key, $key_path );
			self::cleanup_files( array( $stage_cert, $stage_key ) );
			return new WP_Error(
				'mtuc_ssl_replace_failed',
				__( 'Неуспешно записване на новия SSL сертификат.', 'mtunicredit' )
			);
		}

		if ( ! @rename( $stage_key, $key_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			self::restore_backups( $had_cert, $backup_cert, $cert_path, $had_key, $backup_key, $key_path );
			self::cleanup_files( array( $stage_key ) );
			return new WP_Error(
				'mtuc_ssl_replace_failed',
				__( 'Неуспешно записване на новия SSL ключ.', 'mtunicredit' )
			);
		}

		@chmod( $cert_path, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		@chmod( $key_path, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$final = Mtuc_Certificate_Pair_Validator::validate_files( $cert_path, $key_path );
		if ( is_wp_error( $final ) ) {
			self::restore_backups( $had_cert, $backup_cert, $cert_path, $had_key, $backup_key, $key_path );
			return $final;
		}

		self::cleanup_files( array( $backup_cert, $backup_key, $stage_cert, $stage_key ) );

		return true;
	}

	/**
	 * Create an immutable consumer lease from PEM bytes.
	 *
	 * @param string $cert_pem Certificate PEM.
	 * @param string $key_pem  Private key PEM.
	 * @return Mtuc_Certificate_Consumer_Lease|WP_Error
	 */
	public static function create_lease( string $cert_pem, string $key_pem ) {
		$valid = Mtuc_Certificate_Pair_Validator::validate_pair( $cert_pem, $key_pem );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$base = trailingslashit( self::get_keys_dir() ) . 'leases';
		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			return new WP_Error(
				'mtuc_ssl_lease_failed',
				__( 'Неуспешно създаване на временна SSL директория.', 'mtunicredit' )
			);
		}
		@chmod( $base, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$dir = $base . '/lease-' . wp_generate_password( 12, false, false ) . '-' . (string) getmypid();
		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error(
				'mtuc_ssl_lease_failed',
				__( 'Неуспешно създаване на временна SSL директория.', 'mtunicredit' )
			);
		}
		@chmod( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$cert_path = $dir . '/cert.pem';
		$key_path  = $dir . '/key.pem';

		$wrote_cert = self::write_private_file( $cert_path, $cert_pem, 0600 );
		if ( is_wp_error( $wrote_cert ) ) {
			self::cleanup_files( array( $cert_path, $key_path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $wrote_cert;
		}

		$wrote_key = self::write_private_file( $key_path, $key_pem, 0600 );
		if ( is_wp_error( $wrote_key ) ) {
			self::cleanup_files( array( $cert_path, $key_path ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $wrote_key;
		}

		return new Mtuc_Certificate_Consumer_Lease( $dir, $cert_path, $key_path );
	}

	/**
	 * Create lease from current authoritative files.
	 *
	 * @return Mtuc_Certificate_Consumer_Lease|WP_Error
	 */
	public static function create_lease_from_authoritative() {
		$pair = self::read_pair();
		if ( is_wp_error( $pair ) ) {
			return $pair;
		}

		$valid = Mtuc_Certificate_Pair_Validator::validate_pair( $pair['cert_pem'], $pair['key_pem'] );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return self::create_lease( $pair['cert_pem'], $pair['key_pem'] );
	}

	/**
	 * Write file with restrictive permissions.
	 *
	 * @param string $path Absolute path.
	 * @param string $contents File contents.
	 * @param int    $mode     chmod mode.
	 * @return true|WP_Error
	 */
	private static function write_private_file( string $path, string $contents, int $mode ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$result = file_put_contents( $path, $contents, LOCK_EX );
		if ( false === $result ) {
			return new WP_Error(
				'mtuc_ssl_write_failed',
				__( 'Неуспешен запис на SSL файл.', 'mtunicredit' )
			);
		}

		@chmod( $path, $mode ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		return true;
	}

	/**
	 * Restore backup files after failed promote.
	 *
	 * @param bool   $had_cert     Whether cert backup exists.
	 * @param string $backup_cert  Backup cert path.
	 * @param string $cert_path    Authoritative cert path.
	 * @param bool   $had_key      Whether key backup exists.
	 * @param string $backup_key   Backup key path.
	 * @param string $key_path     Authoritative key path.
	 * @return void
	 */
	private static function restore_backups(
		bool $had_cert,
		string $backup_cert,
		string $cert_path,
		bool $had_key,
		string $backup_key,
		string $key_path
	): void {
		if ( is_file( $cert_path ) ) {
			@unlink( $cert_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( is_file( $key_path ) ) {
			@unlink( $key_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( $had_cert && is_file( $backup_cert ) ) {
			@rename( $backup_cert, $cert_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( $had_key && is_file( $backup_key ) ) {
			@rename( $backup_key, $key_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * Unlink temporary files if present.
	 *
	 * @param array<int, string> $paths Paths to remove.
	 * @return void
	 */
	private static function cleanup_files( array $paths ): void {
		foreach ( $paths as $path ) {
			if ( is_string( $path ) && '' !== $path && is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			}
		}
	}
}
