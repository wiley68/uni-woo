<?php
/**
 * AUD-WOO-017 SmartUCF debug journal sanitization tests.
 *
 * Run: php tests/run-smartucf-debug-journal-tests.php
 *
 * @package MTUC
 */

define( 'MTUC_TEST_USE_REAL_DEBUG_LOG', true );

require_once __DIR__ . '/bootstrap.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-settings.php';
require_once MTUC_PLUGIN_DIR . '/includes/class-mtuc-debug-log.php';

$mtuc_dj_assert_count = 0;

/**
 * @param bool   $ok Condition.
 * @param string $message Failure message.
 * @return void
 */
function mtuc_dj_assert( bool $ok, string $message ): void {
	global $mtuc_dj_assert_count;
	++$mtuc_dj_assert_count;
	if ( ! $ok ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

/**
 * @param string $haystack Haystack.
 * @param string $needle   Forbidden substring.
 * @param string $message  Failure message.
 * @return void
 */
function mtuc_dj_assert_absent( string $haystack, string $needle, string $message ): void {
	mtuc_dj_assert( false === strpos( $haystack, $needle ), $message );
}

/**
 * Capture inserts without a real database.
 */
class Mtuc_Dj_Wpdb {
	/** @var string */
	public $prefix = 'wp_';

	/** @var list<array<string, mixed>> */
	public $inserts = array();

	/**
	 * @param string                             $table  Table.
	 * @param array<string, mixed>               $data   Row.
	 * @param array<int, string>|string|null     $format Formats.
	 * @return int
	 */
	public function insert( $table, $data, $format = null ) {
		unset( $format );
		$this->inserts[] = array(
			'table' => (string) $table,
			'data'  => $data,
		);
		return 1;
	}

	/**
	 * @param string $sql SQL.
	 * @return int
	 */
	public function query( $sql ) {
		unset( $sql );
		return 0;
	}

	/**
	 * @param string $query Query with placeholders.
	 * @param mixed  ...$args Args.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		unset( $args );
		return (string) $query;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	/**
	 * @param string   $type Type.
	 * @param int|bool $gmt  GMT.
	 * @return string
	 */
	function current_time( $type, $gmt = 0 ) {
		unset( $type, $gmt );
		return '2026-08-24 12:00:00';
	}
}

/**
 * Option map for Mtuc_Settings::get via get_option.
 *
 * @var array<string, mixed>
 */
$GLOBALS['mtuc_dj_options'] = array(
	Mtuc_Settings::OPTION_DEBUG => 0,
);

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * @param string $key     Option key.
	 * @param mixed  $default Default.
	 * @return mixed
	 */
	function get_option( $key, $default = false ) {
		if ( array_key_exists( $key, $GLOBALS['mtuc_dj_options'] ) ) {
			return $GLOBALS['mtuc_dj_options'][ $key ];
		}
		return $default;
	}
}

// ---------------------------------------------------------------------------
// Fake secrets used only for absence assertions.
// ---------------------------------------------------------------------------

$fake_user    = 'fake_uni_user_AUD017';
$fake_pass    = 'fake_uni_pass_AUD017!';
$fake_fname   = 'FakeFirstAUD017';
$fake_lname   = 'FakeLastAUD017';
$fake_phone   = '+359888000017';
$fake_email   = 'fake.aud017@example.test';
$fake_address = 'ul. Fake 17, Sofia';
$fake_session = 'fake-session-id-AUD017-resume';

// ---------------------------------------------------------------------------
// 1. Valid request redaction (top-level contract keys).
// ---------------------------------------------------------------------------

$request_payload = array(
	'user'                  => $fake_user,
	'pass'                  => $fake_pass,
	'orderNo'               => '1001',
	'clientFirstName'       => $fake_fname,
	'clientLastName'        => $fake_lname,
	'clientPhone'           => $fake_phone,
	'clientEmail'           => $fake_email,
	'clientDeliveryAddress' => $fake_address,
	'onlineProductCode'     => 'KOP1',
	'totalPrice'            => 199.99,
);

$request_json      = wp_json_encode( $request_payload );
$sanitized_req     = Mtuc_Debug_Log::sanitize_request_for_journal( $request_json );
$sanitized_req_arr = json_decode( $sanitized_req, true );

mtuc_dj_assert( is_array( $sanitized_req_arr ), 'valid request sanitizes to JSON object' );
mtuc_dj_assert( '[REDACTED]' === $sanitized_req_arr['user'], 'user redacted' );
mtuc_dj_assert( '[REDACTED]' === $sanitized_req_arr['pass'], 'pass redacted' );
mtuc_dj_assert( '[REDACTED]' === $sanitized_req_arr['clientFirstName'], 'first name redacted' );
mtuc_dj_assert( '[REDACTED]' === $sanitized_req_arr['clientLastName'], 'last name redacted' );
mtuc_dj_assert( '[REDACTED]' === $sanitized_req_arr['clientPhone'], 'phone redacted' );
mtuc_dj_assert( '[REDACTED]' === $sanitized_req_arr['clientEmail'], 'email redacted' );
mtuc_dj_assert( '[REDACTED]' === $sanitized_req_arr['clientDeliveryAddress'], 'address redacted' );
mtuc_dj_assert( '1001' === $sanitized_req_arr['orderNo'], 'orderNo preserved' );
mtuc_dj_assert( 'KOP1' === $sanitized_req_arr['onlineProductCode'], 'product code preserved' );
mtuc_dj_assert_absent( $sanitized_req, $fake_user, 'fake user absent' );
mtuc_dj_assert_absent( $sanitized_req, $fake_pass, 'fake pass absent' );
mtuc_dj_assert_absent( $sanitized_req, $fake_fname, 'fake first name absent' );
mtuc_dj_assert_absent( $sanitized_req, $fake_lname, 'fake last name absent' );
mtuc_dj_assert_absent( $sanitized_req, $fake_phone, 'fake phone absent' );
mtuc_dj_assert_absent( $sanitized_req, $fake_email, 'fake email absent' );
mtuc_dj_assert_absent( $sanitized_req, $fake_address, 'fake address absent' );

// ---------------------------------------------------------------------------
// 2. Nested request redaction.
// ---------------------------------------------------------------------------

$nested_request = wp_json_encode(
	array(
		'orderNo'  => '2002',
		'customer' => array(
			'clientEmail' => $fake_email,
			'clientPhone' => $fake_phone,
			'pass'        => $fake_pass,
		),
	)
);
$nested_sanitized = Mtuc_Debug_Log::sanitize_request_for_journal( $nested_request );
$nested_arr       = json_decode( $nested_sanitized, true );

mtuc_dj_assert( is_array( $nested_arr ), 'nested request is JSON' );
mtuc_dj_assert( '[REDACTED]' === $nested_arr['customer']['clientEmail'], 'nested email redacted' );
mtuc_dj_assert( '[REDACTED]' === $nested_arr['customer']['clientPhone'], 'nested phone redacted' );
mtuc_dj_assert( '[REDACTED]' === $nested_arr['customer']['pass'], 'nested pass redacted' );
mtuc_dj_assert_absent( $nested_sanitized, $fake_email, 'nested fake email absent' );
mtuc_dj_assert_absent( $nested_sanitized, $fake_pass, 'nested fake pass absent' );

// ---------------------------------------------------------------------------
// 3. Malformed request JSON — no raw fallback.
// ---------------------------------------------------------------------------

$malformed     = '{"user":"' . $fake_user . '","pass":"' . $fake_pass . '","clientEmail":"' . $fake_email . '",';
$malformed_out = Mtuc_Debug_Log::sanitize_request_for_journal( $malformed );
$malformed_arr = json_decode( $malformed_out, true );

mtuc_dj_assert( is_array( $malformed_arr ), 'malformed request yields JSON marker object' );
mtuc_dj_assert(
	Mtuc_Debug_Log::UNPARSEABLE_REQUEST_MARKER === ( $malformed_arr['message'] ?? '' ),
	'malformed request uses UNPARSEABLE marker'
);
mtuc_dj_assert( strlen( $malformed ) === (int) ( $malformed_arr['byte_length'] ?? -1 ), 'malformed byte_length' );
mtuc_dj_assert_absent( $malformed_out, $fake_user, 'malformed: user absent' );
mtuc_dj_assert_absent( $malformed_out, $fake_pass, 'malformed: pass absent' );
mtuc_dj_assert_absent( $malformed_out, $fake_email, 'malformed: email absent' );
mtuc_dj_assert( false === strpos( $malformed_out, '{"user"' ), 'malformed: raw fragment absent' );

// ---------------------------------------------------------------------------
// 4. Valid JSON response with PII / session id.
// ---------------------------------------------------------------------------

$response_payload = array(
	'sucfOnlineSessionID' => $fake_session,
	'clientEmail'         => $fake_email,
	'clientPhone'         => $fake_phone,
	'status'              => 'OK',
);
$response_json      = wp_json_encode( $response_payload );
$sanitized_response = Mtuc_Debug_Log::sanitize_response_for_journal( $response_json );
$response_arr       = json_decode( $sanitized_response, true );

mtuc_dj_assert( is_array( $response_arr ), 'response sanitizes to JSON' );
mtuc_dj_assert( '[REDACTED]' === $response_arr['sucfOnlineSessionID'], 'session id redacted' );
mtuc_dj_assert( '[REDACTED]' === $response_arr['clientEmail'], 'response email redacted' );
mtuc_dj_assert( '[REDACTED]' === $response_arr['clientPhone'], 'response phone redacted' );
mtuc_dj_assert( 'OK' === $response_arr['status'], 'safe status preserved' );
mtuc_dj_assert_absent( $sanitized_response, $fake_session, 'fake session absent' );
mtuc_dj_assert_absent( $sanitized_response, $fake_email, 'response fake email absent' );
mtuc_dj_assert_absent( $sanitized_response, $fake_phone, 'response fake phone absent' );

// ---------------------------------------------------------------------------
// 5. Nested response PII.
// ---------------------------------------------------------------------------

$nested_response = wp_json_encode(
	array(
		'result' => array(
			'clientFirstName' => $fake_fname,
			'detail'          => array(
				'pass' => $fake_pass,
			),
		),
		'code'   => 0,
	)
);
$nested_resp_out = Mtuc_Debug_Log::sanitize_response_for_journal( $nested_response );
$nested_resp_arr = json_decode( $nested_resp_out, true );

mtuc_dj_assert( '[REDACTED]' === $nested_resp_arr['result']['clientFirstName'], 'nested response name redacted' );
mtuc_dj_assert( '[REDACTED]' === $nested_resp_arr['result']['detail']['pass'], 'nested response pass redacted' );
mtuc_dj_assert( 0 === $nested_resp_arr['code'], 'nested safe field preserved' );
mtuc_dj_assert_absent( $nested_resp_out, $fake_fname, 'nested response name absent' );
mtuc_dj_assert_absent( $nested_resp_out, $fake_pass, 'nested response pass absent' );

// ---------------------------------------------------------------------------
// 6. Non-JSON response.
// ---------------------------------------------------------------------------

$non_json     = "Error page: password={$fake_pass} email={$fake_email} phone={$fake_phone}";
$non_json_out = Mtuc_Debug_Log::sanitize_response_for_journal( $non_json );
$non_json_arr = json_decode( $non_json_out, true );

mtuc_dj_assert( is_array( $non_json_arr ), 'non-JSON response yields marker object' );
mtuc_dj_assert(
	Mtuc_Debug_Log::NON_JSON_RESPONSE_MARKER === ( $non_json_arr['message'] ?? '' ),
	'non-JSON uses NON_JSON marker'
);
mtuc_dj_assert( strlen( $non_json ) === (int) ( $non_json_arr['byte_length'] ?? -1 ), 'non-JSON byte_length' );
mtuc_dj_assert_absent( $non_json_out, $fake_pass, 'non-JSON: pass absent' );
mtuc_dj_assert_absent( $non_json_out, $fake_email, 'non-JSON: email absent' );
mtuc_dj_assert_absent( $non_json_out, $fake_phone, 'non-JSON: phone absent' );

// ---------------------------------------------------------------------------
// 7. Normal non-sensitive response remains visible.
// ---------------------------------------------------------------------------

$safe_response = wp_json_encode(
	array(
		'httpHint' => 'created',
		'code'     => 200,
		'message'  => 'session_started',
	)
);
$safe_out = Mtuc_Debug_Log::sanitize_response_for_journal( $safe_response );
$safe_arr = json_decode( $safe_out, true );

mtuc_dj_assert( 'created' === $safe_arr['httpHint'], 'safe httpHint kept' );
mtuc_dj_assert( 200 === $safe_arr['code'], 'safe code kept' );
mtuc_dj_assert( 'session_started' === $safe_arr['message'], 'safe message kept' );

// ---------------------------------------------------------------------------
// 8. Business response untouched by sanitization.
// ---------------------------------------------------------------------------

$business_body = "raw-business-body {$fake_email} {$fake_pass}";
$journal_copy  = Mtuc_Debug_Log::sanitize_response_for_journal( $business_body );

mtuc_dj_assert(
	$business_body === "raw-business-body {$fake_email} {$fake_pass}",
	'original business response string unchanged'
);
mtuc_dj_assert_absent( $journal_copy, $fake_email, 'journal copy has no email' );
mtuc_dj_assert( false !== strpos( $business_body, $fake_email ), 'business body still has email' );

$business_req = $request_json;
$journal_req  = Mtuc_Debug_Log::sanitize_request_for_journal( $business_req );
mtuc_dj_assert( $business_req === $request_json, 'original request string unchanged' );
mtuc_dj_assert_absent( $journal_req, $fake_pass, 'journal request has no pass' );
mtuc_dj_assert( false !== strpos( $business_req, $fake_pass ), 'business request still has pass' );

// ---------------------------------------------------------------------------
// 9. Debug disabled — no persistence.
// ---------------------------------------------------------------------------

$GLOBALS['mtuc_dj_options'][ Mtuc_Settings::OPTION_DEBUG ] = 0;
$wpdb = new Mtuc_Dj_Wpdb();
$GLOBALS['wpdb'] = $wpdb;

Mtuc_Debug_Log::log_smartucf_session( $request_json, $response_json, 200, 42 );
mtuc_dj_assert( 0 === count( $wpdb->inserts ), 'debug off: no insert' );

// ---------------------------------------------------------------------------
// 10. Debug enabled — persisted values are sanitized; schema keys unchanged.
// ---------------------------------------------------------------------------

$GLOBALS['mtuc_dj_options'][ Mtuc_Settings::OPTION_DEBUG ] = 1;
$wpdb = new Mtuc_Dj_Wpdb();
$GLOBALS['wpdb'] = $wpdb;

Mtuc_Debug_Log::log_smartucf_session( $request_json, $non_json, 502, 99 );
mtuc_dj_assert( 1 === count( $wpdb->inserts ), 'debug on: one insert' );

$row = $wpdb->inserts[0]['data'];
mtuc_dj_assert( Mtuc_Debug_Log::TYPE_SMARTUCF === $row['log_type'], 'log_type preserved' );
mtuc_dj_assert( 99 === (int) $row['order_id'], 'order_id preserved' );
mtuc_dj_assert( 502 === (int) $row['http_code'], 'http_code preserved' );
mtuc_dj_assert( isset( $row['request_json'], $row['response_json'], $row['created_at'] ), 'schema columns present' );

mtuc_dj_assert_absent( (string) $row['request_json'], $fake_user, 'persisted request: no user' );
mtuc_dj_assert_absent( (string) $row['request_json'], $fake_pass, 'persisted request: no pass' );
mtuc_dj_assert_absent( (string) $row['response_json'], $fake_email, 'persisted response: no email' );
mtuc_dj_assert(
	false !== strpos( (string) $row['response_json'], Mtuc_Debug_Log::NON_JSON_RESPONSE_MARKER ),
	'persisted response has NON_JSON marker'
);

$stored_req = json_decode( (string) $row['request_json'], true );
mtuc_dj_assert( is_array( $stored_req ) && '[REDACTED]' === ( $stored_req['user'] ?? null ), 'persisted request redacted user' );

echo 'OK: SmartUCF debug journal tests passed (' . $mtuc_dj_assert_count . " assertions)\n";
exit( 0 );
