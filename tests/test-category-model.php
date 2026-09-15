<?php
/**
 * Item 8 — the category vocabulary must live in exactly one place, and two
 * categories must never quietly share a colour.
 *
 * Run with:  php tests/test-category-model.php
 *
 * The defect this replaces was not a crash: three hardcoded copies of the
 * vocabulary drifted apart and everything kept rendering something plausible.
 * So the assertions here are mostly about *visibility* — that a fuzzy match
 * says it was fuzzy, that an unmatched category is an error rather than a
 * shrug, and that a colour clash is detectable rather than merely unlikely.
 *
 * What this cannot prove: that WordPress's real get_terms()/term-meta layer
 * behaves like these stubs. That needs the live pass — see .claude/skills/verify.
 *
 * @package cha
 */

require_once __DIR__ . '/bootstrap-category.php';

/* ── Palette assignment ───────────────────────────────────────────────── */

t_group( 'Colours are assigned by scarcity, so they cannot collide by chance' );

t_reset_terms();
t_add_term( 'Blue Plaque Site' );
t_add_term( 'Heritage Site' );
t_add_term( 'Cultural Heritage' );
t_add_term( 'Natural Heritage' );
t_add_term( 'Memorial' );
t_add_term( 'Cemetery' );

$defs = CHA_Taxonomy::category_definitions();

t_eq( 6, count( $defs ), 'every term gets a definition' );
t_ok( isset( $defs['memorial'], $defs['cemetery'] ), 'including terms the code has never heard of' );

$colours = array();
foreach ( $defs as $slug => $def ) {
	$colours[] = strtolower( $def['colour'] );
}
t_eq( count( $colours ), count( array_unique( $colours ) ), 'all six colours are distinct — a hash into 7 slots would collide 96% of the time here' );
t_eq( array(), CHA_Taxonomy::duplicate_colours(), 'and duplicate_colours() agrees there is no clash' );

t_eq( '#1a4a7a', $defs['blue-plaque-site']['colour'], 'Blue Plaque Site gets the designation blue' );
t_eq( '#FFFFFF', $defs['blue-plaque-site']['text'], 'with white text' );

t_group( 'Plaque blue is reserved by SLUG, so renaming the term keeps it' );

t_reset_terms();
t_add_term( 'Blou Plakette', 'blue-plaque-site' ); // Renamed in a fork; slug intact.
t_add_term( 'Heritage Site' );
$defs = CHA_Taxonomy::category_definitions();
t_eq( '#1a4a7a', $defs['blue-plaque-site']['colour'], 'the renamed term still owns plaque blue' );
t_ok( '#1a4a7a' !== $defs['heritage-site']['colour'], 'and nothing else takes it' );

t_group( 'The assignment is actually written down, not just computed' );

// Found on a real install: colours were only persisted from an admin request,
// so terms created by activation or WP-CLI had none stored. The assignment was
// then recomputed every time — and pinning one category to another's colour
// silently MOVED that other category instead of clashing. "Persisted so nothing
// reshuffles" was not true.
t_reset_terms();
$bp = t_add_term( 'Blue Plaque Site' );
$bu = t_add_term( 'Heritage Site' );
$mo = t_add_term( 'Cultural Heritage' );

t_eq( '', (string) get_term_meta( $bu->term_id, CHA_Taxonomy::META_COLOUR, true ), 'a freshly created term starts with nothing stored' );
CHA_Taxonomy::persist_all_colours();
foreach ( array( $bp, $bu, $mo ) as $term ) {
	t_ok( '' !== (string) get_term_meta( $term->term_id, CHA_Taxonomy::META_COLOUR, true ), $term->name . ' has its colour written down' );
}

$buildings_colour = CHA_Taxonomy::category_definitions()['heritage-site']['colour'];

// Pin Monuments onto Heritage Site' colour by hand.
update_term_meta( $mo->term_id, CHA_Taxonomy::META_COLOUR, $buildings_colour );
$defs = CHA_Taxonomy::category_definitions();

t_eq( $buildings_colour, $defs['heritage-site']['colour'], 'Buildings keeps its colour — it is not quietly moved out of the way' );
t_eq( 1, count( CHA_Taxonomy::duplicate_colours() ), 'and the clash is reported instead of being hidden by a reshuffle' );

t_group( 'Assignment is stable: a new term never restyles the old ones' );

t_reset_terms();
$a = t_add_term( 'Heritage Site' );
$b = t_add_term( 'Cultural Heritage' );
$before = CHA_Taxonomy::category_definitions();

t_add_term( 'Cemetery' );
CHA_Taxonomy::flush_definitions();
$after = CHA_Taxonomy::category_definitions();

t_eq( $before['heritage-site']['colour'], $after['heritage-site']['colour'], 'Buildings keeps its colour when Cemetery is added' );
t_eq( $before['cultural-heritage']['colour'], $after['cultural-heritage']['colour'], 'so does Cultural Heritage' );

t_group( 'A hand-picked colour wins, and a clash is reported' );

t_reset_terms();
$x = t_add_term( 'Heritage Site' );
$y = t_add_term( 'Cultural Heritage' );
update_term_meta( $x->term_id, CHA_Taxonomy::META_COLOUR, '#c8a052' );
$defs = CHA_Taxonomy::category_definitions();
t_eq( '#c8a052', $defs['heritage-site']['colour'], 'the stored colour overrides the assignment' );
t_eq( '#1e2318', $defs['heritage-site']['text'], 'and brings the palette-correct dark text — white on ochre is 2.97:1' );

update_term_meta( $y->term_id, CHA_Taxonomy::META_COLOUR, '#c8a052' );
$dupes = CHA_Taxonomy::duplicate_colours();
t_eq( 1, count( $dupes ), 'two terms on one colour is detected' );
t_eq( 2, count( $dupes['#c8a052'] ), 'and both names are named' );

t_group( 'Every palette pairing clears WCAG AA' );

foreach ( CHA_Taxonomy::PALETTE as $i => $slot ) {
	$ratio = CHA_Taxonomy::contrast( $slot['colour'], $slot['text'] );
	t_ok( $ratio >= 4.5, sprintf( 'slot %d  %s on %s = %.2f:1', $i + 1, $slot['text'], $slot['colour'], $ratio ) );
}
t_ok( CHA_Taxonomy::contrast( '#c8a052', '#FFFFFF' ) < 4.5, 'and the check is real — white on ochre fails it' );

t_group( 'text_on() picks a readable colour for a hand-typed hex' );

t_eq( '#FFFFFF', CHA_Taxonomy::text_on( '#000000' ), 'white on black' );
t_eq( '#1e2318', CHA_Taxonomy::text_on( '#FFFFFF' ), 'charcoal on white' );
t_ok( CHA_Taxonomy::contrast( '#999999', CHA_Taxonomy::text_on( '#999999' ) ) >= 4.5, 'and mid-grey still clears AA' );

t_group( 'Glyphs' );

t_reset_terms();
$m = t_add_term( 'Memorial' );
$defs = CHA_Taxonomy::category_definitions();
t_eq( CHA_Taxonomy::DEFAULT_ICON, $defs['memorial']['icon'], 'a term with no glyph gets the default, never an empty string' );
update_term_meta( $m->term_id, CHA_Taxonomy::META_ICON, '🕯️' );
$defs = CHA_Taxonomy::category_definitions();
t_eq( '🕯️', $defs['memorial']['icon'], 'a chosen glyph is used' );

t_group( 'The seeded Clarens terms start with a glyph, and an edited glyph is never overwritten' );

t_reset_terms();
foreach ( CHA_Taxonomy::TERMS as $name ) {
	t_add_term( $name );
}
t_eq( 4, CHA_Taxonomy::seed_glyphs(), 'all four seeded terms receive their starting glyph' );
$defs = CHA_Taxonomy::category_definitions();
t_eq( '🔵', $defs['blue-plaque-site']['icon'], 'Blue Plaque Site starts as the blue disc' );
t_eq( '🌿', $defs['natural-heritage']['icon'], 'Natural Heritage starts as the leaf' );
t_eq( 0, CHA_Taxonomy::seed_glyphs(), 'running it again writes nothing — idempotent' );
$nat = get_term_by( 'slug', 'natural-heritage' );
update_term_meta( $nat->term_id, CHA_Taxonomy::META_ICON, '🏔️' );
CHA_Taxonomy::flush_definitions();
t_eq( 0, CHA_Taxonomy::seed_glyphs(), 'a glyph edited in the admin is left alone' );
t_eq( '🏔️', CHA_Taxonomy::category_definitions()['natural-heritage']['icon'], 'and is what the feed carries' );
t_ok( count( CHA_Taxonomy::SEED_ICONS ) === count( CHA_Taxonomy::TERMS ), 'every seeded term has a seed glyph — the two lists cannot drift' );

t_group( 'The deprecated style filter still works for a fork that uses it' );

t_reset_terms();
$leg = t_add_term( 'Heritage Site' );
$GLOBALS['cha_filter_styles'] = array( 'Heritage Site' => array( 'dot' => '#123456', 'icon' => '🧱' ) );
CHA_Taxonomy::flush_definitions();
$defs = CHA_Taxonomy::category_definitions();
t_eq( '#123456', $defs['heritage-site']['colour'], 'a fork\'s old dot colour is honoured rather than silently dropped' );
t_eq( '🧱', $defs['heritage-site']['icon'], 'and its glyph' );

update_term_meta( $leg->term_id, CHA_Taxonomy::META_COLOUR, '#4E5530' );
$defs = CHA_Taxonomy::category_definitions();
t_eq( '#4E5530', $defs['heritage-site']['colour'], 'but term meta wins over the deprecated filter' );
$GLOBALS['cha_filter_styles'] = array();
CHA_Taxonomy::flush_definitions();

t_group( 'Categories a site uses but the feed cannot style are reported' );

t_reset_terms();
t_add_term( 'Heritage Site' );
t_add_term( 'Cultural Heritage' );
t_eq( array(), CHA_Taxonomy::undefined_categories(), 'nothing is missing in the normal case' );

$GLOBALS['cha_drop_definition'] = 'cultural-heritage';
CHA_Taxonomy::flush_definitions();
$missing = CHA_Taxonomy::undefined_categories();
t_eq( array( 'Cultural Heritage' ), $missing, 'a category a filter removed from the feed is named, not left to look plausible' );
$GLOBALS['cha_drop_definition'] = '';
CHA_Taxonomy::flush_definitions();

t_group( 'The rollback backup is discardable, and only discardable' );

t_reset_sites();
t_add_site( 40, array( 'ac' => 'heritage-site', 'dot' => '#8d6e63', 'icon' => '🏛️' ) );
CHA_Taxonomy::migrate_style_meta();
t_ok( is_array( get_option( CHA_Taxonomy::STYLE_BACKUP_OPTION ) ), 'the backup is there after migrating' );
t_ok( CHA_Taxonomy::discard_style_meta_backup(), 'discard removes it' );
t_eq( false, get_option( CHA_Taxonomy::STYLE_BACKUP_OPTION ), 'and it is gone' );
t_eq( 0, CHA_Taxonomy::restore_style_meta_backup(), 'restoring after a discard is a no-op rather than an error' );

t_group( 'Migration hands each category the glyph its own sites were using' );

// Clearing a category-derived glyph without this would leave the site
// showing the generic default, because a category nobody has edited has no
// glyph of its own. The adoption only ever looks at LEGACY_ICONS (the old
// importer's per-category glyphs) — Clarens's hand-picked per-site icons are
// stored as HTML entities and never match, so they are neither tallied nor
// cleared.
t_reset_terms();
t_reset_sites();
$b = t_add_term( 'Heritage Site' );
$m = t_add_term( 'Cultural Heritage' );

foreach ( array( 1, 2, 3 ) as $id ) {
	t_add_site( $id, array( 'icon' => '🏛️', 'ac' => 'heritage-site' ) );
	t_set_site_term( $id, $b );
}
// A Heritage Site carrying the Cultural Heritage glyph (re-categorised after import).
t_add_site( 4, array( 'icon' => '🎭', 'ac' => 'cultural-heritage' ) );
t_set_site_term( 4, $b );
foreach ( array( 5, 6 ) as $id ) {
	t_add_site( $id, array( 'icon' => '🎭', 'ac' => 'cultural-heritage' ) );
	t_set_site_term( $id, $m );
}

CHA_Taxonomy::migrate_style_meta();
$defs = CHA_Taxonomy::category_definitions();

t_eq( '🏛️', $defs['heritage-site']['icon'], 'Heritage Site adopts the glyph the majority of its sites had' );
t_eq( '🎭', $defs['cultural-heritage']['icon'], 'Cultural Heritage adopts its own' );
t_eq( '', get_post_meta( 4, 'icon', true ), 'and the odd site out loses its contradictory glyph' );
t_ok( '🏛️' === $defs['heritage-site']['icon'], 'so that site now shows its category glyph instead of the wrong one' );

$backup = get_option( CHA_Taxonomy::STYLE_BACKUP_OPTION );
t_eq( 2, count( $backup['adopted'] ), 'the adopted glyphs are recorded as part of the change' );

CHA_Taxonomy::restore_style_meta_backup();
$defs = CHA_Taxonomy::category_definitions();
t_eq( CHA_Taxonomy::DEFAULT_ICON, $defs['heritage-site']['icon'], 'and a rollback removes them again — nothing of the change is left behind' );
t_eq( '🎭', get_post_meta( 4, 'icon', true ), 'while the site glyphs come back' );

t_group( 'A category glyph chosen by hand is never overwritten' );

t_reset_terms();
t_reset_sites();
$b2 = t_add_term( 'Heritage Site' );
update_term_meta( $b2->term_id, CHA_Taxonomy::META_ICON, '⛪' );
t_add_site( 10, array( 'icon' => '🏛️' ) );
t_set_site_term( 10, $b2 );
CHA_Taxonomy::migrate_style_meta();
t_eq( '⛪', CHA_Taxonomy::category_definitions()['heritage-site']['icon'], 'the deliberate choice survives the migration' );

t_group( 'Palette capacity is a number the admin screen can quote' );

t_eq( 7, CHA_Taxonomy::palette_capacity(), 'seven pairings clear WCAG AA' );

t_group( 'Past seven categories the palette is exhausted — loudly, not silently' );

t_reset_terms();
foreach ( array( 'Blue Plaque Site', 'Heritage Site', 'Cultural Heritage', 'Natural Heritage', 'Memorial', 'Cemetery', 'Bridges', 'Gardens' ) as $name ) {
	t_add_term( $name );
}
$defs  = CHA_Taxonomy::category_definitions();
$dupes = CHA_Taxonomy::duplicate_colours();

t_eq( 8, count( $defs ), 'all eight terms still get a definition' );
t_eq( 7, count( array_unique( array_map( 'strtolower', array_column( $defs, 'colour' ) ) ) ), 'using the seven available colours' );
t_eq( 1, count( $dupes ), 'the unavoidable reuse is reported, so the admin sees it rather than discovering it in the app' );

/* ── The importer's matcher ───────────────────────────────────────────── */

t_group( 'match_category(): matches the terms that actually exist' );

t_reset_terms();
t_add_term( 'Blue Plaque Site' );
t_add_term( 'Heritage Site' );
t_add_term( 'Cultural Heritage' );
t_add_term( 'Natural Heritage' );
t_add_term( 'Memorial' );
t_add_term( 'Cemetery' );

$m = t_match( 'Heritage Site' );
t_eq( 'exact', $m['how'], 'an exact name is exact' );
t_eq( 'Heritage Site', $m['name'], 'and resolves the right term' );

t_eq( 'exact', t_match( 'heritage-site' )['how'], 'case is ignored' );
t_eq( 'exact', t_match( '  Heritage Site  ' )['how'], 'so is surrounding whitespace' );
t_eq( 'exact', t_match( 'blue-plaque-site' )['how'], 'a slug matches too' );

t_eq( 'normalised', t_match( 'Blue Plaque Sites' )['how'], 'a pluralised form is a normalised match' );
t_eq( 'Blue Plaque Site', t_match( 'Blue Plaque Sites' )['name'], 'and lands on the singular term' );
t_eq( 'Memorial', t_match( 'Memorials' )['name'], 'plural of a singular term works the other way too' );

t_group( 'match_category(): a guess must announce itself' );

$m = t_match( 'Natural Heritage Reserve' );
t_eq( 'fuzzy', $m['how'], 'a partial match is reported as fuzzy, never silently accepted' );
t_eq( 'Natural Heritage', $m['name'], 'and the longest matching term wins' );

$m = t_match( 'Heritage' );
t_eq( 'fuzzy', $m['how'], 'the bare word "Heritage" — inside every Clarens term — is a guess, and says so' );

t_ok( null === t_match( 'Bridge' ), 'a value matching nothing returns no match at all' );
t_ok( null === t_match( '' ), 'an empty cell matches nothing' );

t_group( 'match_category(): a new term needs no code change' );

t_add_term( 'Bridges' );
CHA_Taxonomy::flush_definitions();
$m = t_match( 'Bridge' );
t_eq( 'normalised', $m['how'], 'adding the term in the admin is all it takes' );
t_eq( 'Bridges', $m['name'], 'and it now resolves' );

/* ── Migration ────────────────────────────────────────────────────────── */

t_group( 'Migration retires the stale per-site style meta' );

t_reset_sites();
// Two sites styled by the OLD importer's per-category fallback (raw emoji),
// one Clarens site carrying its curated per-site glyph as an HTML entity
// exactly as the importer writes it, one hand-typed emoji, one with nothing.
t_add_site( 25, array( 'ac' => 'ac-olive', 'dot' => '#4E5530', 'icon' => '🏛️' ) );
t_add_site( 28, array( 'ac' => 'ac-gold', 'dot' => '#c8a052', 'icon' => '🎭' ) );
t_add_site( 29, array( 'ac' => 'ac-olive', 'dot' => '#4E5530', 'icon' => '&#127968;' ) );
t_add_site( 30, array( 'ac' => 'ac-olive', 'dot' => '#4E5530', 'icon' => '⛪' ) );
t_add_site( 31, array() );

$touched = CHA_Taxonomy::migrate_style_meta();

t_eq( 4, $touched, 'only the sites carrying legacy meta are touched' );
t_eq( '', get_post_meta( 25, 'ac', true ), 'ac is gone' );
t_eq( '', get_post_meta( 25, 'dot', true ), 'dot is gone' );
t_eq( '', get_post_meta( 25, 'icon', true ), 'the stale category-derived glyph is gone, so the site inherits its category glyph' );
t_eq( '&#127968;', get_post_meta( 29, 'icon', true ), 'a curated Clarens per-site glyph (HTML entity) is NOT a default and survives untouched' );
t_eq( '', get_post_meta( 29, 'ac', true ), 'while its ac still goes — colour is the category\'s now' );
t_eq( '⛪', get_post_meta( 30, 'icon', true ), 'a hand-typed glyph is NOT a default and survives as a real override' );
t_eq( '', get_post_meta( 31, 'ac', true ), 'a site with nothing stored is left alone' );

t_group( 'Migration is reversible — the plugin ships as a manual zip upload' );

$backup = get_option( CHA_Taxonomy::STYLE_BACKUP_OPTION );
t_ok( is_array( $backup ) && ! empty( $backup['rows'] ), 'every deleted value was recorded before deletion' );
t_ok( isset( $backup['created'] ), 'with a timestamp' );
t_eq( 4, count( $backup['rows'] ), 'one row per touched site' );
t_eq( 'ac-olive', $backup['rows'][25]['ac'], 'holding the exact prior value' );

$restored = CHA_Taxonomy::restore_style_meta_backup();
t_eq( 4, $restored, 'restore puts them all back' );
t_eq( 'ac-olive', get_post_meta( 25, 'ac', true ), 'ac is back' );
t_eq( '#4E5530', get_post_meta( 25, 'dot', true ), 'dot is back' );
t_eq( '🏛️', get_post_meta( 25, 'icon', true ), 'and so is the glyph it held' );

t_summary();
