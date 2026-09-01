<?php
/**
 * Audit item 4 — voucher stock must be concurrency-safe.
 *
 * The race the fix targets: two redemptions both pass gate_check()'s
 * `usedCount < maxVouchers` read before either writes, and both then redeem.
 * A sequential test cannot show that, so the interleaved schedule is driven
 * explicitly below — gate_check for A, gate_check for B, then both claims —
 * which is exactly the ordering two concurrent PHP workers produce.
 *
 * @package cha-tests
 */

require_once __DIR__ . '/bootstrap-wp-stubs.php';

/* ---- partner post stubs ------------------------------------------------ */

class WP_Post {
	public $ID;
	public $post_type   = 'partner';
	public $post_status = 'publish';
	public $post_title  = 'The Firkin';
	public $post_name   = 'firkin';
	public function __construct( $id ) {
		$this->ID = $id;
	}
}

const PARTNER_ID   = 42;
const PARTNER_SLUG = 'firkin';

function get_post( $id ) {
	return (int) $id === PARTNER_ID ? new WP_Post( PARTNER_ID ) : null;
}

function get_page_by_path( $path, $output = OBJECT, $type = 'post' ) {
	return PARTNER_SLUG === $path ? new WP_Post( PARTNER_ID ) : null;
}

function register_rest_route() {}
function add_action() {}

class CHA_Paystack {
	/** Every token in these tests is a valid paid pass; item 1 covers the rest. */
	public static function resolve_token( $token ) {
		return array( 'valid' => '' !== trim( (string) $token ), 'type' => 'purchase', 'paid' => true );
	}
}

require_once __DIR__ . '/../wordpress-plugin/includes/class-cha-redeem.php';

/**
 * Call a protected CHA_Redeem method.
 *
 * @param string $name Method name.
 * @param array  $args Arguments.
 * @return mixed
 */
function redeem_call( $name, ...$args ) {
	$m = new ReflectionMethod( 'CHA_Redeem', $name );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
}

/**
 * Reset the partner's stock meta and the redemptions table.
 *
 * @param int $max  maxVouchers.
 * @param int $used Starting usedCount, or -1 to leave the meta row absent.
 */
function set_stock( $max, $used = 0 ) {
	global $wpdb;
	$wpdb->meta_rows = array(
		array( 'post_id' => PARTNER_ID, 'meta_key' => 'maxVouchers', 'meta_value' => (string) $max ),
		array( 'post_id' => PARTNER_ID, 'meta_key' => 'condition', 'meta_value' => 'paid' ),
	);
	if ( $used >= 0 ) {
		$wpdb->meta_rows[] = array( 'post_id' => PARTNER_ID, 'meta_key' => 'usedCount', 'meta_value' => (string) $used );
	}
	$wpdb->tables = array();
	cha_log_clear();
}

function used_count() {
	return (int) get_post_meta( PARTNER_ID, 'usedCount', true );
}

function do_redeem( $token ) {
	return CHA_Redeem::redeem( new WP_REST_Request( array( 'partnerId' => PARTNER_SLUG, 'token' => $token ) ) );
}

/* ===================================================================== */
T::group( 'Item 4 — the old read-then-write pattern oversells (control)' );

// Reproduce the pre-fix increment_used_count() exactly, on the same schedule,
// to show the test schedule really does expose the bug.
set_stock( 1 );
$a_gate = redeem_call( 'gate_check', PARTNER_ID );
$b_gate = redeem_call( 'gate_check', PARTNER_ID );
T::ok( null === $a_gate && null === $b_gate, 'both callers pass the advisory gate_check read' );

// increment_used_count() as it was: read, then write. Interleaved the way two
// workers interleave — both reads land before either write.
$a_read = (int) get_post_meta( PARTNER_ID, 'usedCount', true );
$b_read = (int) get_post_meta( PARTNER_ID, 'usedCount', true );
update_post_meta( PARTNER_ID, 'usedCount', $a_read + 1 );
update_post_meta( PARTNER_ID, 'usedCount', $b_read + 1 );
T::is( used_count(), 1, 'OLD code: two visitors redeemed a stock of 1 and usedCount still reads 1 — oversold, and invisibly so' );

/* ===================================================================== */
T::group( 'Item 4 — the atomic claim admits exactly one' );

set_stock( 1 );
redeem_call( 'gate_check', PARTNER_ID );  // A reads: open
redeem_call( 'gate_check', PARTNER_ID );  // B reads: open (same stale view)
$a = redeem_call( 'claim_stock', PARTNER_ID );
$b = redeem_call( 'claim_stock', PARTNER_ID );
T::is( array( $a, $b ), array( true, false ), 'exactly one of two interleaved claims succeeds' );
T::is( used_count(), 1, 'usedCount lands on 1, not 2' );

set_stock( 3 );
$results = array();
for ( $i = 0; $i < 5; $i++ ) {
	redeem_call( 'gate_check', PARTNER_ID );
}
for ( $i = 0; $i < 5; $i++ ) {
	$results[] = redeem_call( 'claim_stock', PARTNER_ID );
}
T::is( count( array_filter( $results ) ), 3, '5 interleaved claims against a stock of 3: exactly 3 succeed' );
T::is( used_count(), 3, 'usedCount never passes maxVouchers' );

// A partner that has never been redeemed has no usedCount row at all. Real
// WordPress serves the registered default ('0') from get_post_meta() even
// with no row present (CHA_Meta::register_partner_meta() registers 'usedCount'
// with default 0) -- the bootstrap stub mirrors that masking on purpose (see
// its note) so this test exercises the same trap that broke every partner's
// first-ever live redemption on 1 Sep 2026 until claim_stock() was fixed to
// use metadata_exists() instead of a get_post_meta() emptiness check.
set_stock( 2, -1 );
T::is( metadata_exists( 'post', PARTNER_ID, 'usedCount' ), false, 'precondition: no usedCount meta row exists in the DB' );
T::is( get_post_meta( PARTNER_ID, 'usedCount', true ), '0', 'precondition: get_post_meta() masks that absence with the registered default -- NOT an empty string (this is what fooled the old code)' );
T::is( redeem_call( 'claim_stock', PARTNER_ID ), true, 'the first-ever redemption is not misread as sold out, despite the default-masked read above' );
T::is( metadata_exists( 'post', PARTNER_ID, 'usedCount' ), true, 'the real row now exists' );
T::is( used_count(), 1, 'the counter starts at 1 after the first claim' );

// Unlimited stock still counts, and never rejects.
set_stock( 0 );
$results = array();
for ( $i = 0; $i < 7; $i++ ) {
	$results[] = redeem_call( 'claim_stock', PARTNER_ID );
}
T::is( count( array_filter( $results ) ), 7, 'maxVouchers=0 (unlimited): every claim succeeds' );
T::is( used_count(), 7, 'maxVouchers=0: the counter is still accurate' );

/* ===================================================================== */
T::group( 'Item 4 — end-to-end /redeem behaviour' );

set_stock( 1 );
$r1 = do_redeem( 'CHT-AAAA-0001' );
T::is( $r1->status, 200, 'first visitor redeems' );
T::is( $r1->data['success'], true, 'first visitor gets success:true' );

$r2 = do_redeem( 'CHT-BBBB-0002' );
T::is( $r2->status, 403, 'second visitor is rejected once stock is gone' );
T::is( $r2->data['code'], 'sold_out', 'the rejection reads sold_out' );

// The loser of a stock race must not be left with a redemptions row, or the
// row would block them from ever redeeming this partner again.
set_stock( 1 );
do_redeem( 'CHT-AAAA-0001' );                       // takes the only unit
$rows_before = count( $GLOBALS['wpdb']->tables['wp_cha_redemptions'] );
$loser       = do_redeem( 'CHT-CCCC-0003' );
T::is( $loser->data['code'], 'sold_out', 'the loser is told sold_out' );
T::is( count( $GLOBALS['wpdb']->tables['wp_cha_redemptions'] ), $rows_before, "the loser's redemptions row is rolled back" );

// Per-token single use still holds on top of the stock cap.
set_stock( 5 );
do_redeem( 'CHT-DDDD-0004' );
$again = do_redeem( 'CHT-DDDD-0004' );
T::is( $again->status, 409, 'the same token cannot redeem the same partner twice' );
T::is( $again->data['code'], 'already_redeemed', 'the repeat reads already_redeemed' );
T::is( used_count(), 1, 'a rejected repeat does not consume stock' );

// An expired window is still rejected before any stock is touched.
global $wpdb;
set_stock( 5 );
$wpdb->meta_rows[] = array( 'post_id' => PARTNER_ID, 'meta_key' => 'dateTo', 'meta_value' => '2020-01-01' );
$expired           = do_redeem( 'CHT-EEEE-0005' );
T::is( $expired->data['code'], 'expired', 'an expired voucher is rejected' );
T::is( used_count(), 0, 'an expired voucher consumes no stock' );

echo "\n";
exit( T::summary() );
