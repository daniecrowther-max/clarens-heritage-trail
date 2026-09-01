<?php
/**
 * Webhook idempotency — the item flagged as still open in
 * docs/CHA_Security_Audit_Remediation_v1.md after the 26 Aug 2026 audit.
 *
 * Covers: signature verification, the already-paid short-circuit (a replayed
 * or duplicate webhook delivery), the atomicity of the pending→paid
 * transition when a webhook races the /verify-token redirect path (so the
 * token email is never sent twice), a DB error asking Paystack to retry
 * rather than silently losing the payment, and an amount/currency mismatch
 * resolving without paying or emailing.
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

function reset_state() {
	global $wpdb;
	$wpdb->tables                  = array();
	$wpdb->fail_insert             = false;
	$wpdb->fail_update             = false;
	$GLOBALS['cha_test_http']      = array();
	$GLOBALS['cha_test_http_next'] = null;
	$GLOBALS['cha_test_mail']      = array();
	$GLOBALS['cha_test_mail_fail'] = false;
	cha_log_clear();
}

/**
 * Insert a pending purchase row directly (bypassing /checkout — that path is
 * covered by test-checkout.php, this file only exercises what happens to a
 * purchase once it exists).
 */
function seed_pending( $token, $email = 'buyer@example.test', $amount = 9900 ) {
	global $wpdb;
	$reference = 'CHA-' . $token;
	$wpdb->insert(
		CHA_Purchases::table(),
		array(
			'email'          => $email,
			'token'          => $token,
			'reference'      => $reference,
			'amount_cents'   => $amount,
			'status'         => 'pending',
			'created_at'     => current_time( 'mysql' ),
			'email_status'   => 'pending',
			'email_attempts' => 0,
		)
	);
	return $reference;
}

function purchase_status( $reference ) {
	$p = CHA_Purchases::find_by_reference( $reference );
	return $p ? $p->status : null;
}

function paystack_verify_success( $amount = 9900, $currency = 'ZAR' ) {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode(
			array(
				'status' => true,
				'data'   => array( 'status' => 'success', 'amount' => $amount, 'currency' => $currency ),
			)
		),
	);
}

/**
 * A correctly HMAC-signed webhook POST, the way Paystack actually sends one.
 */
function signed_request( $event, $reference, $secret = 'sk_test_stub' ) {
	$body = json_encode( array( 'event' => $event, 'data' => array( 'reference' => $reference ) ) );
	$sig  = hash_hmac( 'sha512', $body, $secret );
	return new WP_REST_Request( array(), $body, array( 'x-paystack-signature' => $sig ) );
}

function unsigned_request( $event, $reference ) {
	$body = json_encode( array( 'event' => $event, 'data' => array( 'reference' => $reference ) ) );
	return new WP_REST_Request( array(), $body, array( 'x-paystack-signature' => 'not-a-real-signature' ) );
}

/* ===================================================================== */
T::group( 'Webhook — signature and event routing' );

reset_state();
$res = CHA_Paystack::webhook( unsigned_request( 'charge.success', 'CHA-CHT-TEST-0001' ) );
T::is( $res->status, 401, 'a bad signature is rejected with 401' );
T::is( $res->data['error'], 'invalid_signature', 'the rejection reads invalid_signature' );

reset_state();
$res = CHA_Paystack::webhook( signed_request( 'transfer.success', 'CHA-CHT-TEST-0001' ) );
T::is( $res->status, 200, 'a correctly signed event we do not act on is still 200 (nothing to retry)' );
T::is( $res->data['handled'], false, 'transfer.success is reported as not handled' );

reset_state();
$res = CHA_Paystack::webhook( signed_request( 'charge.success', 'CHA-DOES-NOT-EXIST' ) );
T::is( $res->status, 200, 'an unknown reference is 200 — Paystack should not retry a reference we will never recognise' );
T::is( $res->data['error'], 'reference_not_found', 'the reason reads reference_not_found' );

/* ===================================================================== */
T::group( 'Webhook — first delivery marks paid and sends exactly one email' );

reset_state();
$ref                            = seed_pending( 'CHT-TEST-0001' );
$GLOBALS['cha_test_http_next']  = paystack_verify_success( 9900 );
$res                             = CHA_Paystack::webhook( signed_request( 'charge.success', $ref ) );
T::is( $res->status, 200, 'first delivery returns 200' );
T::is( $res->data['status'], 'paid', 'first delivery reports paid' );
T::is( purchase_status( $ref ), 'paid', 'the purchase row is now paid' );
T::is( count( $GLOBALS['cha_test_mail'] ), 1, 'exactly one email was sent' );
T::is( $GLOBALS['cha_test_mail'][0]['to'], 'buyer@example.test', 'the email went to the buyer' );
T::is( count( $GLOBALS['cha_test_http'] ), 1, 'exactly one Paystack verify call was made' );

/* ===================================================================== */
T::group( 'Webhook — a replayed delivery after paid is a no-op (idempotent)' );

// Deliberately continuing the SAME session state above — Paystack retries a
// webhook up to 4x/3min then hourly for 72h; this is that replay.
$res2 = CHA_Paystack::webhook( signed_request( 'charge.success', $ref ) );
T::is( $res2->status, 200, 'the replay is still 200' );
T::is( $res2->data['status'], 'already_paid', 'the replay reports already_paid, not paid again' );
T::is( count( $GLOBALS['cha_test_mail'] ), 1, 'still exactly one email total — the replay did not send a second' );
T::is( count( $GLOBALS['cha_test_http'] ), 1, 'still exactly one Paystack verify call total — the replay never called Paystack again' );

/* ===================================================================== */
T::group( 'mark_paid() — exactly one of two racing callers wins the pending→paid transition' );

reset_state();
$ref = seed_pending( 'CHT-TEST-0002' );
$a   = CHA_Purchases::find_by_reference( $ref ); // both "callers" read before either writes
$b   = CHA_Purchases::find_by_reference( $ref );
T::is( $a->status, 'pending', 'caller A reads pending' );
T::is( $b->status, 'pending', 'caller B reads the same pending row' );
T::is( CHA_Purchases::mark_paid( $a ), true, 'caller A wins the transition' );
T::is( CHA_Purchases::mark_paid( $b ), false, 'caller B loses — the row already moved' );
T::is( purchase_status( $ref ), 'paid', 'the row lands on paid exactly once' );

/* ===================================================================== */
T::group( 'Webhook — arriving after the redirect path already marked paid' );

reset_state();
$ref = seed_pending( 'CHT-TEST-0003' );
$p   = CHA_Purchases::find_by_reference( $ref );
T::ok( CHA_Purchases::mark_paid( $p ), 'the redirect path (/verify-token) wins first, outside the webhook entirely' );
$res = CHA_Paystack::webhook( signed_request( 'charge.success', $ref ) );
T::is( $res->data['status'], 'already_paid', 'the webhook, arriving second, is a no-op' );
T::is( count( $GLOBALS['cha_test_mail'] ), 0, 'the webhook never sends its own email — the row was already resolved' );
T::is( count( $GLOBALS['cha_test_http'] ), 0, 'the webhook never even calls Paystack — the already-paid short-circuit happens before verify_and_mark_paid' );

/* ===================================================================== */
T::group( 'Webhook — a DB error while marking paid asks Paystack to retry, not silently drops it' );

reset_state();
$ref                            = seed_pending( 'CHT-TEST-0004' );
$GLOBALS['wpdb']->fail_update   = true;
$GLOBALS['cha_test_http_next']  = paystack_verify_success( 9900 );
$res                             = CHA_Paystack::webhook( signed_request( 'charge.success', $ref ) );
T::is( $res->status, 500, 'a DB error returns 500 so Paystack retries' );
T::is( $res->data['status'], 'db_error', 'the reason reads db_error' );
T::is( count( $GLOBALS['cha_test_mail'] ), 0, 'no email was sent on a failed write' );
T::is( purchase_status( $ref ), 'pending', 'the row is untouched, still pending, ready for the retry' );

/* ===================================================================== */
T::group( 'Webhook — an amount/currency mismatch is resolved, not paid, and not retried' );

reset_state();
$ref                            = seed_pending( 'CHT-TEST-0005', 'buyer@example.test', 9900 );
$GLOBALS['cha_test_http_next']  = paystack_verify_success( 5000, 'ZAR' ); // wrong amount
$res                             = CHA_Paystack::webhook( signed_request( 'charge.success', $ref ) );
T::is( $res->status, 200, 'a mismatch is 200 — resolved, not asking for a retry' );
T::is( $res->data['status'], 'mismatch', 'the reason reads mismatch' );
T::is( purchase_status( $ref ), 'pending', 'the row is left pending, not paid, on a mismatch' );
T::is( count( $GLOBALS['cha_test_mail'] ), 0, 'no email was sent for a mismatched amount' );

$GLOBALS['cha_test_http_next'] = paystack_verify_success( 5000, 'ZAR' ); // still wrong on the retry
$res2                           = CHA_Paystack::webhook( signed_request( 'charge.success', $ref ) );
T::is( $res2->data['status'], 'mismatch', 'a repeat delivery re-verifies and reports the same mismatch again — status never flips to paid, so nothing is short-circuited or double-resolved' );

echo "\n";
exit( T::summary() );
