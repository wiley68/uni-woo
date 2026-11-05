<?php
/**
 * Execute real consent/popup/checkout JavaScript with local DOM/AJAX stubs.
 * Run: php tests/run-consent-frontend-tests.php (requires the local Node runtime).
 *
 * @package MTUC
 */
$process = proc_open( array( 'node', __DIR__ . '/consent-frontend-harness.js' ), array( 0 => array( 'pipe', 'r' ), 1 => STDOUT, 2 => STDERR ), $pipes );
if ( ! is_resource( $process ) ) {
	fwrite( STDERR, "FAIL: unable to start Node consent harness\n" );
	exit( 1 );
}
fclose( $pipes[0] );
exit( proc_close( $process ) );
