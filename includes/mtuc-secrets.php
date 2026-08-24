<?php
/**
 * Protected secret resolution (AUD-WOO-011).
 *
 * @package MTUC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WordPress constant name for SmartUCF private-key password (define in wp-config.php). */
const MTUC_SECRET_SMARTUCF_KEY_PASSWORD_CONSTANT = 'MTUC_SMARTUCF_KEY_PASSWORD';

/** Environment variable name for SmartUCF private-key password. */
const MTUC_SECRET_SMARTUCF_KEY_PASSWORD_ENV = 'MTUC_SMARTUCF_KEY_PASSWORD';

/**
 * Resolve SmartUCF mTLS private-key password from protected server configuration.
 *
 * Precedence: MTUC_SMARTUCF_KEY_PASSWORD constant → environment variable → legacy MTUC_SSL_PASSWD constant.
 * No hard-coded fallback in plugin source.
 *
 * @return string|null Null when not configured.
 */
function mtuc_get_smartucf_key_password(): ?string {
	if ( defined( MTUC_SECRET_SMARTUCF_KEY_PASSWORD_CONSTANT ) ) {
		$value = constant( MTUC_SECRET_SMARTUCF_KEY_PASSWORD_CONSTANT );
		if ( is_string( $value ) && '' !== $value ) {
			return $value;
		}
	}

	$env = getenv( MTUC_SECRET_SMARTUCF_KEY_PASSWORD_ENV );
	if ( is_string( $env ) && '' !== $env ) {
		return $env;
	}

	if ( defined( 'MTUC_SSL_PASSWD' ) ) {
		$legacy = (string) MTUC_SSL_PASSWD;
		if ( '' !== $legacy ) {
			return $legacy;
		}
	}

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
	echo esc_html__(
		'УниКредит: липсва конфигурирана парола за SmartUCF SSL частния ключ. Дефинирайте MTUC_SMARTUCF_KEY_PASSWORD в wp-config.php или като environment variable.',
		'mtunicredit'
	);
	echo '</p></div>';
}
