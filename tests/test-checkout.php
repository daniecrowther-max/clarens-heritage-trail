<?php
/**
 * Audit items 2 and 3 — /checkout rate limiting, and the purchase row that
 * must exist before Paystack is ever called.
 *
 * @package cha-tests
 */

require_once __DIR__ . '/bootstrap-wp-stubs.php';
require_once __DIR__ . '/../wordpress-plugin/includes/class-cha-purchases.php';
require_once __DIR__ . '/../wordpress-plugin/includes/class-cha-paystack.php';

class CHA_Cors {
	public static function primary_app_origin() {
		return 'https://trail.clarensheritage.test';
	}
}

/**
 * Run one /checkout, from a given client IP.
 *
 * @param string $email Buyer email.
 * @param string $ip    Client IP the request appears to come from.
 * @return WP_REST_Response
 */
function checkout_as( $email, $ip = '41.0.0.1' ) {
	$_SERVER['REMOTE_ADDR'] = $ip;
	unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	return CHA_Paystack::checkout( new WP_REST_Request( array( 'email' => $email ) ) );
}

function reset_state() {
	global $wpdb;
	$GLOBALS['cha_test_transients'] = array();
	$GLOBALS['cha_test_http']       = array();
	$wpdb->tables                   = array();
	$wpdb->fail_insert              = false;
	cha_log_clear();
}

/* ===================================================================== */
T::group( 'Item 3 — payment cannot be initiated without a purchase row' );

reset_state();
$res = checkout_as( 'buyer@example.test' );
T::is( $res->status, 200, 'happy path returns 200' );
T::ok( ! empty( $res->data['payment_url'] ), 'happy path returns a payment_url' );
T::is( count( $GLOBALS['wpdb']->tables['wp_cha_purchases'] ?? array() ), 1, 'happy path wrote exactly one pending row' );
T::is( count( $GLOBALS['cha_test_http'] ), 1, 'happy path called Paystack once' );

// The row must be written BEFORE the Paystack call, not after: assert on order.
reset_state();
$GLOBALS['wpdb']->fail_insert = true;
$res                          = checkout_as( 'buyer2@example.test' );
T::is( $res->status, 500, 'a failed insert_pending returns HTTP 500' );
T::ok( empty( $res->data['payment_url'] ), 'a failed insert_pending returns NO payment_url' );
T::is( $res->data['ok'], false, 'a failed insert_pending reports ok:false' );
T::is( count( $GLOBALS['cha_test_http'] ), 0, 'a failed insert_pending never reaches Paystack' );
T::ok( false !== strpos( cha_log_read(), 'insert_pending FAILED' ), 'the failure is logged for follow-up' );

// A Paystack failure after the row exists must not hand out a payment URL —
// and must leave the pending row in place, so a charge that somehow lands
// still has something for the webhook to resolve against.
reset_state();
$GLOBALS['cha_test_http_next'] = new WP_Error( 'http_request_failed' );
$res                           = checkout_as( 'buyer3@example.test' );
T::ok( empty( $res->data['payment_url'] ), 'a Paystack failure returns no payment_url' );
T::is( count( $GLOBALS['wpdb']->tables['wp_cha_purchases'] ?? array() ), 1, 'the pending row survives a Paystack failure (webhook can still resolve it)' );

// Paystack answering 200 with no authorization_url is also a failure.
reset_state();
$GLOBALS['cha_test_http_next'] = array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => false, 'message' => 'Invalid key' ) ) );
$res                           = checkout_as( 'buyer4@example.test' );
T::ok( empty( $res->data['payment_url'] ), 'a Paystack error body returns no payment_url' );

/* ===================================================================== */
T::group( 'Item 2 — /checkout rate limiting' );

// Per-email: 4 attempts per 10 minutes, whatever the source IP.
reset_state();
$statuses = array();
for ( $i = 0; $i < 6; $i++ ) {
	$statuses[] = checkout_as( 'repeat@example.test', '41.0.0.' . ( $i + 1 ) )->status;
}
T::is( $statuses, array( 200, 200, 200, 200, 429, 429 ), 'same email from 6 different IPs: blocked after 4' );

// …and the block really does stop the Paystack call, not just the response.
T::is( count( $GLOBALS['cha_test_http'] ), 4, 'only the 4 allowed attempts reached Paystack' );

// Per-IP: 20 attempts per 10 minutes, whatever the email.
reset_state();
$blocked_at = null;
for ( $i = 0; $i < 25; $i++ ) {
	$r = checkout_as( 'flood' . $i . '@example.test', '196.1.2.3' );
	if ( 429 === $r->status && null === $blocked_at ) {
		$blocked_at = $i;
	}
}
T::is( $blocked_at, 20, 'same IP with 25 distinct emails: blocked from the 21st attempt' );
T::is( count( $GLOBALS['cha_test_http'] ), 20, 'only the 20 allowed attempts reached Paystack' );

// The window expires; a blocked caller's own retries do not hold it open.
reset_state();
for ( $i = 0; $i < 6; $i++ ) {
	checkout_as( 'window@example.test' );
}
$GLOBALS['cha_test_now'] += 10 * 60 + 1;
T::is( checkout_as( 'window@example.test' )->status, 200, 'the caller is allowed again once the 10-minute window passes' );
$GLOBALS['cha_test_now'] -= 10 * 60 + 1;

// Normalisation: casing and surrounding whitespace are one identity.
reset_state();
$statuses = array();
foreach ( array( 'Case@Example.test', 'case@example.test', '  CASE@EXAMPLE.TEST  ', 'cAsE@eXaMpLe.TeSt', 'case@example.test' ) as $variant ) {
	$statuses[] = checkout_as( $variant, '41.9.9.' . count( $statuses ) )->status;
}
T::is( $statuses, array( 200, 200, 200, 200, 429 ), 'case/whitespace variants of one email share a bucket' );

// Cloudflare: the real visitor IP is used, not the edge address, so one CF
// edge does not bucket the whole world together.
reset_state();
$_SERVER['REMOTE_ADDR']            = '172.67.190.102'; // a Cloudflare edge
$statuses                          = array();
for ( $i = 0; $i < 22; $i++ ) {
	$_SERVER['HTTP_CF_CONNECTING_IP'] = '105.4.' . $i . '.7'; // distinct real visitors
	$statuses[]                        = CHA_Paystack::checkout( new WP_REST_Request( array( 'email' => 'v' . $i . '@example.test' ) ) )->status;
}
T::is( count( array_filter( $statuses, function ( $s ) { return 429 === $s; } ) ), 0, '22 distinct visitors behind one Cloudflare edge are all served' );

echo "\n";
exit( T::summary() );
