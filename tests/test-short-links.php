<?php
/**
 * CHA_Short_Links — the permanent QR plaque redirect (/s/{site_id}).
 *
 * Run with:  php tests/test-short-links.php
 *
 * The slug-shift group (10) models a site whose post_name is not its
 * site_id — the importer never rewrites a slug on re-import, and an editor
 * can retitle a page at any time. That is the concrete reason the redirect
 * resolves via the site_id meta rather than by encoding a page URL directly
 * onto a plaque etched for decades — see docs/CHA_Development_Plan_v0.11.md.
 *
 * What this cannot prove: that a real WordPress rewrite rule actually routes
 * a request into parse_request, or what LiteSpeed/a browser do on the wire.
 * That needs the live pass — the deploy checklist's `curl -sI` step.
 *
 * @package cha
 */

require_once __DIR__ . '/bootstrap-shortlink.php';

/* ── 1. Known ID resolves to the site's own permalink ───────────────────── */

t_group( '1. A known, published id resolves to that site\'s permalink' );

t_reset();
t_site( 20, 'supply-store', 'supply-store', 'Supply Store' );

$result = CHA_Short_Links::resolve( 'supply-store' );
t_eq( 'found', $result['status'], 'status is "found"' );
t_eq( 'https://clarensheritage.org/heritage-site/supply-store/', $result['url'], 'redirects to the site\'s own permalink' );

/* ── 2. Case variants ─────────────────────────────────────────────────── */

t_group( '2. SUPPLY-STORE, supply-store and Supply-Store all resolve to the same site' );

t_reset();
t_site( 20, 'supply-store', 'supply-store', 'Supply Store' );

foreach ( array( 'SUPPLY-STORE', 'supply-store', 'Supply-Store', 'sUPPLY-STORE' ) as $variant ) {
	$result = CHA_Short_Links::resolve( $variant, false );
	t_eq( 'found', $result['status'], "\"$variant\" resolves" );
	t_eq( 'supply-store', $result['site_id'], "\"$variant\" normalises to supply-store" );
}

/* ── 3. Trailing slash, against the actual shipped regex ─────────────────── */

t_group( '3. The route pattern accepts a trailing slash and none, and stops at the next segment' );

$pattern = '#' . CHA_Short_Links::REWRITE_PATTERN . '#';

t_ok( 1 === preg_match( $pattern, 's/supply-store', $m ) && 'supply-store' === $m[1], 'no trailing slash' );
t_ok( 1 === preg_match( $pattern, 's/supply-store/', $m ) && 'supply-store' === $m[1], 'trailing slash' );
t_ok( 1 === preg_match( $pattern, 's/SUPPLY-STORE/', $m ) && 'SUPPLY-STORE' === $m[1], 'captures the raw case — normalisation is resolve()\'s job, not the regex\'s' );
t_ok( 0 === preg_match( $pattern, 's/supply-store/extra', $m ), 'a further path segment does not match — this route does not swallow anything past the id' );

/* ── 4. Unknown ID → trail index, never a 404 ────────────────────────────── */

t_group( '4. An unknown id lands on the trail index with a note, never a 404' );

t_reset();
t_trail_page();

$result = CHA_Short_Links::resolve( 'no-such-site' );
t_eq( 'unknown', $result['status'], 'status is "unknown"' );
t_ok( false !== strpos( $result['url'], '/trail/' ), 'redirects into the trail index page' );
t_ok( false !== strpos( $result['url'], 'plaque=no-such-site' ), 'carries which id was scanned' );
t_ok( false !== strpos( $result['url'], 'cha_note=unknown' ), 'carries why, for the on-page note' );

/* ── 5. Unpublished site → trail index, never a 404 ──────────────────────── */

t_group( '5. A pending (unpublished) site also gets the fallback, not a 404' );

t_reset();
t_trail_page();
t_site( 27, 'die-spens', 'die-spens', 'Die Spens', 'pending' );

$result = CHA_Short_Links::resolve( 'die-spens' );
t_eq( 'unpublished', $result['status'], 'status is "unpublished", distinct from "unknown" — the site exists' );
t_ok( false !== strpos( $result['url'], '/trail/' ), 'still redirects into the trail index' );
t_ok( false !== strpos( $result['url'], 'cha_note=unpublished' ), 'the note says unpublished, not unknown' );

/* ── 6. Repointing: the base filter, and a single-site override ─────────── */

t_group( '6. cha_short_link_base repoints every plaque; cha_short_link_target overrides one' );

t_reset();
t_site( 20, 'supply-store', 'supply-store', 'Supply Store' );
t_site( 21, 'president-square', 'president-square', 'President Square' );

update_option( CHA_Short_Links::OPTION_BASE, 'https://trail.clarensheritage.org/#/site' );

$r1 = CHA_Short_Links::resolve( 'supply-store', false );
$r2 = CHA_Short_Links::resolve( 'president-square', false );
t_eq( 'https://trail.clarensheritage.org/#/site/supply-store', $r1['url'], 'supply-store now resolves under the app deep link' );
t_eq( 'https://trail.clarensheritage.org/#/site/president-square', $r2['url'], 'so does president-square — one option change, whole set repointed' );

add_filter(
	'cha_short_link_target',
	static function ( $url, $site_id, $post ) {
		return ( 'president-square' === $site_id ) ? 'https://example.org/president-square-special' : $url;
	},
	10,
	3
);
$r1 = CHA_Short_Links::resolve( 'supply-store', false );
$r2 = CHA_Short_Links::resolve( 'president-square', false );
t_eq( 'https://trail.clarensheritage.org/#/site/supply-store', $r1['url'], 'supply-store is unaffected by the per-site override' );
t_eq( 'https://example.org/president-square-special', $r2['url'], 'president-square is overridden individually, on top of the base' );

/* ── 6a. target_url() — the three base forms, and {id} substitution ─────── */

t_group( '6a. target_url(): empty base, plain append base, and the {id} placeholder base' );

t_reset();
t_site( 20, 'supply-store', 'supply-store', 'Supply Store' );
t_site( 21, 'president-square', 'president-square', 'President Square' );

t_eq(
	'https://clarensheritage.org/heritage-site/supply-store/',
	CHA_Short_Links::resolve( 'supply-store', false )['url'],
	'empty base ("") → the site\'s own permalink, unchanged'
);

update_option( CHA_Short_Links::OPTION_BASE, 'https://x/y' );
t_eq(
	'https://x/y/supply-store',
	CHA_Short_Links::resolve( 'supply-store', false )['url'],
	'a plain base with no {id} → appended to the path, unchanged from before {id} support existed'
);

update_option( CHA_Short_Links::OPTION_BASE, 'https://x/y/' );
t_eq(
	'https://x/y/supply-store',
	CHA_Short_Links::resolve( 'supply-store', false )['url'],
	'a trailing slash on a plain base is not doubled'
);

update_option( CHA_Short_Links::OPTION_BASE, 'https://trail.clarensheritage.org/?site={id}' );
t_eq(
	'https://trail.clarensheritage.org/?site=supply-store',
	CHA_Short_Links::resolve( 'supply-store', false )['url'],
	'{id} in the base is substituted with the site id — the app deep-link form'
);

update_option( CHA_Short_Links::OPTION_BASE, 'https://trail.clarensheritage.org/?utm_source=plaque&site={id}&ref=qr' );
t_eq(
	'https://trail.clarensheritage.org/?utm_source=plaque&site=president-square&ref=qr',
	CHA_Short_Links::resolve( 'president-square', false )['url'],
	'{id} may sit anywhere in the query string, with other params on either side'
);

add_filter(
	'cha_short_link_target',
	static function ( $url, $site_id, $post ) {
		return ( 'president-square' === $site_id ) ? 'https://example.org/president-square-override' : $url;
	},
	10,
	3
);
t_eq(
	'https://example.org/president-square-override',
	CHA_Short_Links::resolve( 'president-square', false )['url'],
	'cha_short_link_target still wins over an {id} base, exactly as it does over a plain-append base'
);
t_eq(
	'https://trail.clarensheritage.org/?utm_source=plaque&site=supply-store&ref=qr',
	CHA_Short_Links::resolve( 'supply-store', false )['url'],
	'supply-store is unaffected by the president-square-only override'
);

/* ── 6b. allow_base_redirect_host() — cross-host base vs wp_safe_redirect() ── */

t_group( '6b. allow_base_redirect_host(): the app-origin base must survive wp_safe_redirect()' );

// Found live, not by any unit test: wp_safe_redirect() (used by dispatch())
// silently rewrites an unrecognised-host target to admin_url(). A base
// pointing at the app's own origin — the whole point of cha_short_link_base
// — is exactly such a target unless its host is allowlisted here.

t_reset();
update_option( CHA_Short_Links::OPTION_BASE, '' );
t_eq( array( 'existing.example' ), CHA_Short_Links::allow_base_redirect_host( array( 'existing.example' ) ), 'an empty base adds nothing, and leaves prior entries alone' );

update_option( CHA_Short_Links::OPTION_BASE, 'https://trail.clarensheritage.org/?site={id}' );
t_eq( array( 'trail.clarensheritage.org' ), CHA_Short_Links::allow_base_redirect_host( array() ), 'the {id}-form base\'s host is allowlisted' );

update_option( CHA_Short_Links::OPTION_BASE, 'https://x/y' );
t_eq( array( 'x' ), CHA_Short_Links::allow_base_redirect_host( array() ), 'a plain append-form base\'s host is allowlisted too' );

update_option( CHA_Short_Links::OPTION_BASE, 'https://trail.clarensheritage.org/?site={id}' );
t_eq( array( 'existing.example', 'trail.clarensheritage.org' ), CHA_Short_Links::allow_base_redirect_host( array( 'existing.example' ) ), 'appends to, rather than replaces, whatever hosts WordPress core/other plugins already allow' );

/* ── 6c. The allowlisting is scoped to dispatch()'s own redirect, not global ── */

t_group( '6c. allow_base_redirect_host() is not left registered — a redirect outside dispatch() is still refused' );

// init() must NOT hand wp_safe_redirect() a standing allowance for the base's
// host — that would leave ANY OTHER wp_safe_redirect() call during the same
// request (core, or an unrelated plugin) able to redirect there too, for the
// rest of that request. dispatch() is the only place this is meant to apply,
// bracketing the one wp_safe_redirect() call it makes — see the comments on
// both. dispatch() itself calls exit() and is untested-by-design (like
// CHA_Voucher_Metrics::export_csv()), so this exercises the exact same
// add_filter()/remove_filter() bracket dispatch() uses, around a plain
// apply_filters() call standing in for "some other wp_safe_redirect()
// elsewhere in the same request."

t_reset();
update_option( CHA_Short_Links::OPTION_BASE, 'https://trail.clarensheritage.org/?site={id}' );

CHA_Short_Links::init();
t_eq(
	array(),
	apply_filters( 'allowed_redirect_hosts', array() ),
	'init() alone does not register the allowance — a request that never reaches dispatch() never gets it'
);

$cb = array( 'CHA_Short_Links', 'allow_base_redirect_host' );
add_filter( 'allowed_redirect_hosts', $cb );
t_eq(
	array( 'trail.clarensheritage.org' ),
	apply_filters( 'allowed_redirect_hosts', array() ),
	'inside the bracket (what dispatch() does around its own wp_safe_redirect() call), the host is allowed'
);
remove_filter( 'allowed_redirect_hosts', $cb );
t_eq(
	array(),
	apply_filters( 'allowed_redirect_hosts', array() ),
	'after the bracket closes, a subsequent unrelated wp_safe_redirect() in the same request is refused again'
);

/* ── 7. The scan counter — exactly once per hit, never on a miss ────────── */

t_group( '7. The counter increments exactly once per hit, and not at all on a miss' );

t_reset();
t_trail_page();
t_site( 20, 'supply-store', 'supply-store', 'Supply Store' );

t_eq( null, t_scan_count( 20 ), 'no row before the first scan' );

CHA_Short_Links::resolve( 'supply-store' );
t_eq( 1, t_scan_count( 20 ), 'exactly one after one scan' );

CHA_Short_Links::resolve( 'SUPPLY-STORE' ); // Same site, different case.
t_eq( 2, t_scan_count( 20 ), 'exactly two after a second scan, case notwithstanding' );

CHA_Short_Links::resolve( 'gr-999' ); // A miss.
t_eq( 2, t_scan_count( 20 ), 'a miss on a different id never touches supply-store\'s counter' );

$misses = get_option( CHA_Short_Links::OPTION_MISSES, array() );
t_eq( 1, isset( $misses['gr-999'] ) ? $misses['gr-999'] : 0, 'the miss itself is recorded once' );

CHA_Short_Links::resolve( 'supply-store', false ); // Explicit no-record.
t_eq( 2, t_scan_count( 20 ), 'resolve( …, false ) never increments — the admin screen\'s preview path' );

/* ── 8. 302, never 301 ────────────────────────────────────────────────── */

t_group( '8. The redirect status is 302, not 301' );

t_eq( 302, CHA_Short_Links::REDIRECT_STATUS, 'a 301 is cached by browsers/scanners for years — exactly the failure this design avoids' );

/* ── 9. No-store signalling ──────────────────────────────────────────────── */

t_group( '9. A redirect is sent with no-cache signalling — a changed target must apply on the very next scan' );

t_reset();
CHA_Short_Links::send_nocache_headers();
t_ok( true === $GLOBALS['cha_nocache_headers_called'], 'nocache_headers() was called — sends the standard no-store/no-cache/must-revalidate trio' );
t_ok( defined( 'DONOTCACHEPAGE' ) && true === DONOTCACHEPAGE, 'DONOTCACHEPAGE is defined — the cross-plugin (LiteSpeed included) cache-bypass convention already used by CHA_Paystack' );

/* ── 10. The slug-shift case — the reason this design exists ────────────── */

t_group( '10. A site resolves to its OWN page via site_id, even when its slug is another site\'s site_id' );

// Two sites whose post_name has drifted from their site_id (a retitled page,
// or a slug the importer never rewrote on re-import) and now carry each
// other's id-shaped slug. A page-URL-on-the-plaque approach would have baked
// exactly this confusion in permanently; resolving via the site_id meta never
// looks at post_name at all.
t_reset();
t_site( 25, 'ou-slaghuis', 'old-library', 'Ou Slaghuis' );
t_site( 26, 'old-library', 'ou-slaghuis', 'Old Library' );

$r25 = CHA_Short_Links::resolve( 'ou-slaghuis', false );
$r26 = CHA_Short_Links::resolve( 'old-library', false );
t_eq( 'https://clarensheritage.org/heritage-site/old-library/', $r25['url'], 'ou-slaghuis lands on ITS OWN post\'s page — whatever slug that post currently carries' );
t_eq( 'https://clarensheritage.org/heritage-site/ou-slaghuis/', $r26['url'], 'old-library likewise resolves via its own site_id, not the slug that happens to spell its name' );
t_ok( $r25['url'] !== $r26['url'], 'and the two are not confused with each other' );

/* ── 11. The POPIA guarantee — a count and a date, nothing else ─────────── */

t_group( '11. The scan record is a count and a date — no IP, no user agent, no cookie' );

t_reset();
t_site( 20, 'supply-store', 'supply-store', 'Supply Store' );
CHA_Short_Links::resolve( 'supply-store' );

$stored_keys = array_keys( $GLOBALS['cha_post_meta'][20] );
sort( $stored_keys );
t_eq( array( CHA_Short_Links::META_COUNT, CHA_Short_Links::META_LAST, 'site_id' ), $stored_keys, 'exactly these three keys exist on the post — nothing else was written' );
t_ok( (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $GLOBALS['cha_post_meta'][20][ CHA_Short_Links::META_LAST ] ), 'the last-scanned value is a bare Y-m-d date, not a full timestamp' );

/* ── Bonus: the CSV builder — Deliverable 2 ──────────────────────────────── */

t_group( 'Bonus — the manufacturing CSV: site_id, site name, short URL' );

t_reset();
t_site( 20, 'supply-store', 'supply-store', 'Supply Store' );
t_site( 27, 'die-spens', 'die-spens', 'Die Spens, Clarens', 'pending' );

$rows = CHA_Short_Links::plaque_rows();
t_eq( 2, count( $rows ), 'both sites are included — a pending site is not silently dropped from the manufacturing run' );

$handle = fopen( 'php://memory', 'w+' );
CHA_Short_Links::write_csv_rows( $handle, $rows );
rewind( $handle );
$csv = stream_get_contents( $handle );
fclose( $handle );

// PHP 8.3's fputcsv() quotes any field containing a space (RFC 4180 style,
// not just fields containing the delimiter) — the same behaviour the
// existing CHA_Voucher_Metrics CSV export already relies on, so this is
// what a real spreadsheet import expects, not a stray artefact.
t_ok( false !== strpos( $csv, '"Site ID","Site Name","Short URL"' ), 'header row matches the three specified columns exactly' );
t_ok( false !== strpos( $csv, 'supply-store,"Supply Store",https://clarensheritage.org/s/supply-store' ), 'supply-store row is present with its short URL' );
t_ok( false !== strpos( $csv, 'die-spens,"Die Spens, Clarens",https://clarensheritage.org/s/die-spens' ), 'the pending die-spens is in the handover file too — its unpublished state is an admin-screen flag, not an omission here' );

t_summary();
