<?php
/**
 * Protected secret resolution (AUD-WOO-011).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Relative path to deployment SmartUCF key password file (production ZIP only). */
const MTUC_SECRET_SMARTUCF_KEY_FILE = 'secrets/smartucf-key.php';

/** WordPress constant name for SmartUCF private-key password (dev/staging override). */
const MTUC_SECRET_SMARTUCF_KEY_PASSWORD_CONSTANT = 'MTUC_SMARTUCF_KEY_PASSWORD';

/** Environment variable name for SmartUCF private-key password. */
const MTUC_SECRET_SMARTUCF_KEY_PASSWORD_ENV = 'MTUC_SMARTUCF_KEY_PASSWORD';

/** Internal diagnostic: secret not configured from any source. */
const MTUC_SECRET_DIAG_NOT_CONFIGURED = 'smartucf_key_password_not_configured';

/** Internal diagnostic: local secret file exists but payload is invalid. */
const MTUC_SECRET_DIAG_FILE_MALFORMED = 'smartucf_key_password_file_malformed';

/**
 * Merchant-facing admin message when SSL key password is missing.
 *
 * @return string
 */
function mtuc_get_admin_missing_ssl_password_message(): string {
	return __(
		'УниКредит: липсва конфигурация за SSL сертификата. Свържете се с доставчика на модула.',
		'mtunicredit'
	);
}

/**
 * Absolute path to the deployment SmartUCF key password file.
 *
 * Tests may set $GLOBALS['mtuc_test_secret_file_path'] to override.
 *
 * @return string
 */
function mtuc_get_smartucf_key_password_file_path(): string {
	if ( isset( $GLOBALS['mtuc_test_secret_file_path'] ) && is_string( $GLOBALS['mtuc_test_secret_file_path'] ) ) {
		return $GLOBALS['mtuc_test_secret_file_path'];
	}

	return trailingslashit( MTUC_PLUGIN_DIR ) . MTUC_SECRET_SMARTUCF_KEY_FILE;
}

/**
 * Internal diagnostic code for SmartUCF key password resolution (no secrets).
 *
 * @return string Empty when configured; otherwise a stable support code.
 */
function mtuc_get_smartucf_key_password_diagnostic_code(): string {
	if ( mtuc_has_smartucf_key_password() ) {
		return '';
	}

	$last = isset( $GLOBALS['mtuc_smartucf_key_password_diag'] )
		? sanitize_key( (string) $GLOBALS['mtuc_smartucf_key_password_diag'] )
		: '';

	return '' !== $last ? $last : MTUC_SECRET_DIAG_NOT_CONFIGURED;
}

/**
 * Record an internal SmartUCF key-password diagnostic code (never stores the secret).
 *
 * @param string $code Diagnostic code.
 * @return void
 */
function mtuc_set_smartucf_key_password_diagnostic_code( string $code ): void {
	$GLOBALS['mtuc_smartucf_key_password_diag'] = sanitize_key( $code );
}

/**
 * Load SmartUCF key password from deployment secret file.
 *
 * @return string|null Null when file missing, unreadable, or invalid.
 */
function mtuc_load_smartucf_key_password_from_file(): ?string {
	$path = mtuc_get_smartucf_key_password_file_path();

	if ( ! is_readable( $path ) ) {
		return null;
	}

	$loaded = include $path;

	if ( ! is_array( $loaded ) ) {
		mtuc_set_smartucf_key_password_diagnostic_code( MTUC_SECRET_DIAG_FILE_MALFORMED );
		return null;
	}

	if ( ! array_key_exists( 'smartucf_key_password', $loaded ) ) {
		mtuc_set_smartucf_key_password_diagnostic_code( MTUC_SECRET_DIAG_FILE_MALFORMED );
		return null;
	}

	$password = $loaded['smartucf_key_password'];
	if ( ! is_string( $password ) || '' === trim( $password ) ) {
		mtuc_set_smartucf_key_password_diagnostic_code( MTUC_SECRET_DIAG_FILE_MALFORMED );
		return null;
	}

	unset( $GLOBALS['mtuc_smartucf_key_password_diag'] );

	return $password;
}

/**
 * Return a resolved SmartUCF key password and clear unresolved diagnostics.
 *
 * @param string $password Resolved password.
 * @return string
 */
function mtuc_return_smartucf_key_password( string $password ): string {
	unset( $GLOBALS['mtuc_smartucf_key_password_diag'] );

	return $password;
}

/**
 * Resolve SmartUCF mTLS private-key password from protected deployment configuration.
 *
 * Precedence: local secrets/smartucf-key.php → MTUC_SMARTUCF_KEY_PASSWORD constant
 * → environment variable → legacy MTUC_SSL_PASSWD constant.
 *
 * @return string|null Null when not configured.
 */
function mtuc_get_smartucf_key_password(): ?string {
	unset( $GLOBALS['mtuc_smartucf_key_password_diag'] );

	$file_password = mtuc_load_smartucf_key_password_from_file();
	if ( is_string( $file_password ) && '' !== $file_password ) {
		return mtuc_return_smartucf_key_password( $file_password );
	}

	if ( defined( MTUC_SECRET_SMARTUCF_KEY_PASSWORD_CONSTANT ) ) {
		$value = constant( MTUC_SECRET_SMARTUCF_KEY_PASSWORD_CONSTANT );
		if ( is_string( $value ) && '' !== $value ) {
			return mtuc_return_smartucf_key_password( $value );
		}
	}

	$env = getenv( MTUC_SECRET_SMARTUCF_KEY_PASSWORD_ENV );
	if ( is_string( $env ) && '' !== $env ) {
		return mtuc_return_smartucf_key_password( $env );
	}

	if ( defined( 'MTUC_SSL_PASSWD' ) ) {
		$legacy = (string) MTUC_SSL_PASSWD;
		if ( '' !== $legacy ) {
			return mtuc_return_smartucf_key_password( $legacy );
		}
	}

	mtuc_set_smartucf_key_password_diagnostic_code( MTUC_SECRET_DIAG_NOT_CONFIGURED );

	return null;
}

/**
 * Whether SmartUCF key password is configured.
 *
 * @return bool
 */
function mtuc_has_smartucf_key_password(): bool {
	return null !== mtuc_get_smartucf_key_password();
}

/**
 * Admin notice when SSL key password is missing (admin only, once per request).
 *
 * @return void
 */
function mtuc_maybe_admin_notice_missing_ssl_password(): void {
	if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( mtuc_has_smartucf_key_password() ) {
		return;
	}

	static $shown = false;
	if ( $shown ) {
		return;
	}
	$shown = true;

	echo '<div class="notice notice-warning"><p>';
	echo esc_html( mtuc_get_admin_missing_ssl_password_message() );
	echo '</p></div>';
}
