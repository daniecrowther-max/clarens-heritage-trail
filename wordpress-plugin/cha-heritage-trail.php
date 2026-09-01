<?php
/**
 * Plugin Name:       CHA Heritage Trail
 * Plugin URI:        https://github.com/daniecrowther-max/whitelabel-heritage-trail
 * Description:       Content model for the Clarens Heritage Trail — Heritage Site and Partner/Voucher post types, taxonomy and meta fields. Feeds the PWA app via the cha/v1 REST namespace.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Clarens Heritage Association
 * License:           GPL-2.0-or-later
 * Text Domain:       cha
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CHA_VERSION', '0.1.0' );
define( 'CHA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CHA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ── CHA identifiers — single source of truth ──────────────────────────────
// GR-specific plugin identifiers live here. NOTE: the 'cha' text domain must
// stay a string literal at each __()/_e() call — WordPress i18n tooling cannot
// extract a constant — so it is intentionally NOT defined here.
define( 'CHA_REST_NAMESPACE', 'cha/v1' ); // REST base: /wp-json/cha/v1/…

require_once CHA_PLUGIN_DIR . 'includes/class-cha-post-types.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-taxonomy.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-meta.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-site-meta-box.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-partner-meta-box.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-rest.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-cors.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-shortcodes.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-social-meta.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-xlsx-reader.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-importer.php';

// Payments (step 5). Secrets load from .env only — never the DB, never the bundle.
require_once CHA_PLUGIN_DIR . 'includes/class-cha-env.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-settings.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-tokens.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-purchases.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-paystack.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-redeem.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-admin.php';
require_once CHA_PLUGIN_DIR . 'includes/class-cha-voucher-metrics.php';

add_action( 'init', array( 'CHA_Post_Types', 'register' ) );
add_action( 'init', array( 'CHA_Taxonomy', 'register' ) );
add_action( 'init', array( 'CHA_Meta', 'register' ) );
add_action( 'init', array( 'CHA_Shortcodes', 'register' ) );
CHA_Site_Meta_Box::init();    // Heritage Site details editor on the site CPT.
CHA_Partner_Meta_Box::init(); // Voucher-details editor on the partner CPT.
CHA_Rest::init();
CHA_Cors::init(); // Allow the app subdomain to call cha/v1 cross-origin.
CHA_Importer::init();
CHA_Social_Meta::init(); // og:*/twitter:card/description on site+partner singulars.

CHA_Env::boot();
CHA_Settings::init();
CHA_Purchases::init();
CHA_Paystack::init(); // checkout (Initialize Transaction) + verify-token (Verify Transaction).
CHA_Redeem::init();  // redeem + voucher-status (opaque-token path).
CHA_Admin::init();   // Trail Payments admin screen (tokens, purchases, price).
CHA_Voucher_Metrics::init(); // Voucher redemption metrics + CSV export.
// NOTE: the /app changes are the remaining step-5 slice.

/**
 * One-shot legacy `trail` meta → heritage_trail taxonomy migration. Runs once
 * after deploy even when the plugin is updated by file-replace (no reactivation
 * fires the activation hook). Guarded by an option so it never repeats. Safe to
 * delete this block + the migration method once it has run in production.
 */
function cha_maybe_migrate_trail() {
	if ( get_option( 'cha_trail_migrated' ) ) {
		return;
	}
	CHA_Taxonomy::migrate_trail_meta_to_taxonomy();
	update_option( 'cha_trail_migrated', 1 );
}
add_action( 'admin_init', 'cha_maybe_migrate_trail' );

/**
 * One-shot site_id back-fill (see CHA_Meta::migrate_site_id_from_slug()).
 * Runs once after deploy even by file-replace (no reactivation). Guarded by
 * an option so it never repeats. Safe to delete this block + the migration
 * method once it has run in production.
 */
function cha_maybe_migrate_site_id() {
	if ( get_option( 'cha_site_id_migrated' ) ) {
		return;
	}
	CHA_Meta::migrate_site_id_from_slug();
	update_option( 'cha_site_id_migrated', 1 );
}
add_action( 'admin_init', 'cha_maybe_migrate_site_id' );

/**
 * One-shot trail assignment: every site gets its heritage_trail term and its
 * `trailNum` walking position (see CHA_Taxonomy::migrate_trail_assignments()).
 * Runs once after deploy even by file-replace (no reactivation). Guarded by an
 * option so it never repeats. Safe to delete this block + the migration method
 * once it has run in production.
 *
 * Ordered after cha_maybe_migrate_site_id() — the assignment matches sites on
 * `site_id`, so the back-fill must have populated it first.
 */
function cha_maybe_migrate_trail_assignments() {
	if ( get_option( 'cha_trail_assignments_migrated' ) ) {
		return;
	}
	CHA_Taxonomy::migrate_trail_assignments();
	update_option( 'cha_trail_assignments_migrated', 1 );
}
add_action( 'admin_init', 'cha_maybe_migrate_trail_assignments' );

/**
 * One-shot cha_access_tokens schema upgrade — adds the recipient_email/
 * email_status/email_attempts/email_last_error/email_sent_at columns used by
 * the "email token to recipient" feature. dbDelta() only ADDs what's missing,
 * so re-running create_table() against an existing table is safe. Runs once
 * after deploy even by file-replace (no reactivation). Guarded by an option
 * so it never repeats. Safe to delete this block once it has run in production.
 */
function cha_maybe_migrate_token_email_columns() {
	if ( get_option( 'cha_token_email_columns_migrated' ) ) {
		return;
	}
	CHA_Tokens::create_table();
	update_option( 'cha_token_email_columns_migrated', 1 );
}
add_action( 'admin_init', 'cha_maybe_migrate_token_email_columns' );

/**
 * Activation: register everything, seed the taxonomy terms, create the payment
 * tables, flush rewrites.
 */
function cha_activate() {
	CHA_Post_Types::register();
	CHA_Taxonomy::register();
	CHA_Taxonomy::seed_terms();
	CHA_Taxonomy::migrate_trail_meta_to_taxonomy();
	CHA_Meta::migrate_site_id_from_slug();
	CHA_Taxonomy::migrate_trail_assignments();
	CHA_Tokens::create_table();
	CHA_Purchases::create_table();
	CHA_Redeem::create_table();
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'cha_activate' );

/**
 * Deactivation: flush rewrites so CPT permalinks don't linger.
 */
function cha_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'cha_deactivate' );
