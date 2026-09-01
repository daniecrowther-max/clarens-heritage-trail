<?php
/**
 * One-off migration (brief §5.2): copy every `status = 'paid'` row from the
 * OLD plugin's wp_cha_trail_purchases table into the NEW plugin's
 * wp_cha_purchases table, so existing payers' tokens keep working after the
 * cutover — even once the old plugin/tables are destroyed (§6).
 *
 * Old table (cha-trail-admin, includes/stitch.php):
 *   id, email, token, payment_id, stitch_ref, amount, status, created_at, paid_at
 *
 * New table (this plugin, class-cha-purchases.php):
 *   id, email, token, reference, amount_cents, status, created_at, paid_at,
 *   email_status, email_attempts, email_last_error, email_sent_at
 *
 * Idempotent: skips any old token that already exists in the new table, so
 * it is safe to run more than once (e.g. to pick up payers who complete a
 * pending Stitch payment between two runs, right up until the old plugin is
 * retired in §6).
 *
 * DOES NOT run automatically. Both the new plugin (cha-heritage-trail) and
 * the old plugin (cha-trail-admin) must be active at the same time on the
 * live site for this to find both tables — run it BEFORE §6 deactivates or
 * removes the old plugin.
 *
 * How to run it (either works):
 *
 *   1. WP-CLI (preferred):
 *        wp eval-file scripts/migrate-existing-payers.php
 *
 *   2. No WP-CLI on the host: temporarily drop this file's body into a
 *      one-off mu-plugin (wp-content/mu-plugins/zzz-migrate-payers.php),
 *      load any admin page once to trigger it, then DELETE the mu-plugin
 *      file immediately after (it is not idempotent-safe to leave running
 *      on every page load — it's a one-shot script, not a hook).
 *
 * After running, §5.2's acceptance test: pick one token from the printed
 * "migrated" list and confirm
 *   GET /wp-json/cha/v1/verify-token?token=<that token>
 * returns { "valid": true, "type": "purchase", "paid": true } BEFORE
 * proceeding to §6.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "This script must run inside WordPress (wp eval-file, or a loaded mu-plugin).\n" );
	exit( 1 );
}

global $wpdb;

$old_table = $wpdb->prefix . 'cha_trail_purchases';
$new_table = $wpdb->prefix . 'cha_purchases';

$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) );
$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) );

if ( ! $old_exists ) {
	echo "OLD table $old_table not found. Nothing to migrate (already removed, or wrong DB?).\n";
	exit( 0 );
}
if ( ! $new_exists ) {
	echo "NEW table $new_table not found. Activate the cha-heritage-trail plugin first (its activation hook creates this table).\n";
	exit( 1 );
}

$paid_rows = $wpdb->get_results( "SELECT * FROM $old_table WHERE status = 'paid' ORDER BY id ASC" );

echo 'Found ' . count( $paid_rows ) . " paid rows in $old_table.\n";

$migrated = array();
$skipped  = array();

foreach ( $paid_rows as $row ) {
	$existing = $wpdb->get_var(
		$wpdb->prepare( "SELECT id FROM $new_table WHERE token = %s LIMIT 1", $row->token )
	);
	if ( $existing ) {
		$skipped[] = $row->token;
		continue;
	}

	$reference = ! empty( $row->stitch_ref ) ? $row->stitch_ref : ( 'CHA-' . $row->token );

	$wpdb->insert(
		$new_table,
		array(
			'email'          => $row->email,
			'token'          => $row->token,
			'reference'      => $reference,
			'amount_cents'   => (int) $row->amount,
			'status'         => 'paid',
			'created_at'     => $row->created_at,
			'paid_at'        => $row->paid_at ? $row->paid_at : $row->created_at,
			// Already-delivered historical purchases — do not re-trigger the
			// token email on next verify-token (mark_paid() only fires the
			// email on a pending → paid transition; these insert as already
			// 'paid', so no transition happens and no email is (re)sent).
			'email_status'   => 'sent',
			'email_attempts' => 1,
			'email_sent_at'  => $row->paid_at ? $row->paid_at : $row->created_at,
		)
	);
	$migrated[] = $row->token;
}

echo 'Migrated: ' . count( $migrated ) . "\n";
foreach ( $migrated as $t ) {
	echo "  + $t\n";
}
echo 'Already present (skipped): ' . count( $skipped ) . "\n";
foreach ( $skipped as $t ) {
	echo "  = $t\n";
}

if ( ! empty( $migrated ) ) {
	echo "\nVerification: pick one of the '+' tokens above and confirm before touching §6:\n";
	echo '  GET /wp-json/cha/v1/verify-token?token=' . $migrated[0] . "\n";
	echo "  Expect: { \"valid\": true, \"type\": \"purchase\", \"paid\": true }\n";
} elseif ( empty( $skipped ) ) {
	echo "\nNo paid rows found in the old table — nothing to verify.\n";
} else {
	echo "\nAll paid rows were already migrated in a previous run.\n";
}
