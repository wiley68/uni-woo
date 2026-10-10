<?php
/**
 * Canonical installation ZIP command and standalone verifier.
 *
 * @package MTUC
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- Plain CLI diagnostics use STDOUT/STDERR, without a WordPress filesystem.

if ( 'cli' !== PHP_SAPI ) {
	exit;
}

require_once __DIR__ . '/class-mtuc-distribution-package.php';

try {
	$package = new Mtuc_Distribution_Package( dirname( __DIR__ ), __DIR__ . '/package-manifest.json' );
	$command = $argv[1] ?? 'build';
	if ( 'build' === $command && 2 === $argc ) {
		$result = $package->build();
	} elseif ( 'verify' === $command && 3 === $argc ) {
		$result = $package->verify( $argv[2] );
	} else {
		throw new RuntimeException( 'Usage: composer package OR composer package:verify -- dist/woo_uni_<VERSION>.zip' );
	}
	fwrite( STDOUT, "Verified package: {$result['path']}\nVersion: {$result['version']}\nFiles: {$result['files']}\nBytes: {$result['bytes']}\nSHA-256: {$result['sha256']}\nDeployment config and all runtime files match current source exactly.\n" );
} catch ( Throwable $error ) {
	fwrite( STDERR, 'Packaging failed: ' . $error->getMessage() . "\n" );
	exit( 1 );
}
