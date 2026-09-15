<?php
/**
 * Category-model test harness — no PHPUnit, no WordPress install.
 *
 * Run with:  php tests/test-category-model.php
 *
 * A fourth bootstrap, because the three existing ones each stub a different
 * slice of WordPress and declaring the same functions twice fatals. This one
 * stubs the taxonomy/term-meta layer and an in-memory post-meta store, then
 * loads the REAL CHA_Taxonomy and CHA_Importer — so the palette assignment,
 * the category matcher and the migration are all exercised as shipped.
 *
 * @package cha
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'CHA_VERSION', 'test' );

// ─── Assertions ──────────────────────────────────────────────────────────
$GLOBALS['cha_tests'] = array( 'pass' => 0, 'fail' => 0, 'group' => '' );

function t_group( $name ) {
	$GLOBALS['cha_tests']['group'] = $name;
	echo "\n\033[1m" . $name . "\033[0m\n";
}

function t_ok( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['cha_tests']['pass']++;
		echo "  \033[32m✓\033[0m " . $label . "\n";
	} else {
		$GLOBALS['cha_tests']['fail']++;
		echo "  \033[31m✗ " . $label . "\033[0m\n";
	}
}

function t_eq( $expected, $actual, $label ) {
	$ok = ( $expected === $actual );
	t_ok( $ok, $label );
	if ( ! $ok ) {
		echo "      expected: " . var_export( $expected, true ) . "\n";
		echo "      actual:   " . var_export( $actual, true ) . "\n";
	}
}

function t_summary() {
	$p = $GLOBALS['cha_tests']['pass'];
	$f = $GLOBALS['cha_tests']['fail'];
	echo "\n" . ( $f ? "\033[31m" : "\033[32m" ) . $p . " passed, " . $f . " failed\033[0m\n";
	exit( $f ? 1 : 0 );
}

// ─── WordPress stubs ─────────────────────────────────────────────────────
class WP_Error {
	protected $code;
	protected $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function __( $text, $domain = null ) {
	return $text;
}
function _n( $single, $plural, $number, $domain = null ) {
	return 1 === (int) $number ? $single : $plural;
}
function esc_html( $t ) {
	return htmlspecialchars( (string) $t, ENT_QUOTES );
}
function add_action() {}
function add_filter() {}
function register_taxonomy() {}
function register_term_meta() {}
/**
 * Enough of the filter system for the two cases that need it: the deprecated
 * cha_category_styles shim, and dropping a definition to prove
 * undefined_categories() notices.
 */
function apply_filters( $tag, $value ) {
	if ( 'cha_category_styles' === $tag && ! empty( $GLOBALS['cha_filter_styles'] ) ) {
		return $GLOBALS['cha_filter_styles'];
	}
	if ( 'cha_category_definitions' === $tag && ! empty( $GLOBALS['cha_drop_definition'] ) ) {
		unset( $value[ $GLOBALS['cha_drop_definition'] ] );
	}
	return $value;
}

$GLOBALS['cha_filter_styles']   = array();
$GLOBALS['cha_drop_definition'] = '';
function sanitize_text_field( $value ) {
	return trim( (string) $value );
}
function current_time( $type ) {
	return gmdate( 'Y-m-d H:i:s' );
}

/** WordPress accepts #rgb and #rrggbb, and returns null for anything else. */
function sanitize_hex_color( $color ) {
	$color = (string) $color;
	return preg_match( '/^#([A-Fa-f0-9]{3}){1,2}$/', $color ) ? $color : null;
}

function sanitize_title( $title ) {
	$title = strtolower( (string) $title );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
	return trim( $title, '-' );
}

// ─── In-memory terms ─────────────────────────────────────────────────────
$GLOBALS['cha_terms']     = array();
$GLOBALS['cha_term_meta'] = array();
$GLOBALS['cha_next_term']  = 1;

/**
 * Create a heritage_category term. Returns the term object.
 */
function t_add_term( $name, $slug = null ) {
	$id = $GLOBALS['cha_next_term']++;
	$term = (object) array(
		'term_id'  => $id,
		'name'     => $name,
		'slug'     => null === $slug ? sanitize_title( $name ) : $slug,
		'taxonomy' => 'heritage_category',
	);
	$GLOBALS['cha_terms'][ $id ] = $term;
	return $term;
}

/** Wipe terms, term meta and the definitions memo between groups. */
function t_reset_terms() {
	$GLOBALS['cha_terms']     = array();
	$GLOBALS['cha_term_meta'] = array();
	$GLOBALS['cha_next_term']  = 1;
	CHA_Taxonomy::flush_definitions();
}

function get_terms( $args = array() ) {
	// Insertion order == term_id ascending, which is what both call sites want.
	return array_values( $GLOBALS['cha_terms'] );
}

function get_term( $term_id, $taxonomy = '' ) {
	return isset( $GLOBALS['cha_terms'][ $term_id ] ) ? $GLOBALS['cha_terms'][ $term_id ] : null;
}

function get_term_by( $field, $value, $taxonomy = '' ) {
	foreach ( $GLOBALS['cha_terms'] as $t ) {
		if ( 'slug' === $field && $t->slug === $value ) {
			return $t;
		}
		if ( 'name' === $field && $t->name === $value ) {
			return $t;
		}
	}
	return false;
}

function term_exists( $term, $taxonomy = '' ) {
	foreach ( $GLOBALS['cha_terms'] as $t ) {
		if ( $t->slug === $term || $t->name === $term ) {
			return array( 'term_id' => $t->term_id );
		}
	}
	return null;
}

function get_term_meta( $term_id, $key = '', $single = false ) {
	if ( isset( $GLOBALS['cha_term_meta'][ $term_id ][ $key ] ) ) {
		return $GLOBALS['cha_term_meta'][ $term_id ][ $key ];
	}
	return $single ? '' : array();
}

function update_term_meta( $term_id, $key, $value ) {
	$GLOBALS['cha_term_meta'][ $term_id ][ $key ] = $value;
	CHA_Taxonomy::flush_definitions();
	return true;
}

function delete_term_meta( $term_id, $key ) {
	unset( $GLOBALS['cha_term_meta'][ $term_id ][ $key ] );
	CHA_Taxonomy::flush_definitions();
	return true;
}

// ─── In-memory posts ─────────────────────────────────────────────────────
$GLOBALS['cha_post_meta'] = array();
$GLOBALS['cha_post_ids']  = array();
$GLOBALS['cha_terms_set'] = array();

function t_add_site( $post_id, $meta = array() ) {
	$GLOBALS['cha_post_ids'][]            = $post_id;
	$GLOBALS['cha_post_meta'][ $post_id ] = $meta;
}

function t_reset_sites() {
	$GLOBALS['cha_post_meta'] = array();
	$GLOBALS['cha_post_ids']  = array();
	$GLOBALS['cha_terms_set'] = array();
	$GLOBALS['cha_post_terms'] = array();
	$GLOBALS['cha_options']   = array();
}

function get_posts( $args = array() ) {
	return $GLOBALS['cha_post_ids'];
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	if ( isset( $GLOBALS['cha_post_meta'][ $post_id ][ $key ] ) ) {
		return $GLOBALS['cha_post_meta'][ $post_id ][ $key ];
	}
	return $single ? '' : array();
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['cha_post_meta'][ $post_id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $post_id, $key ) {
	unset( $GLOBALS['cha_post_meta'][ $post_id ][ $key ] );
	return true;
}

$GLOBALS['cha_post_terms'] = array();

/** Assign a term to a seeded site, so the glyph-adoption pass has something to read. */
function t_set_site_term( $post_id, $term ) {
	$GLOBALS['cha_post_terms'][ $post_id ] = $term;
}

function get_the_terms( $post_id, $taxonomy = '' ) {
	$id = is_object( $post_id ) ? $post_id->ID : $post_id;
	return isset( $GLOBALS['cha_post_terms'][ $id ] ) ? array( $GLOBALS['cha_post_terms'][ $id ] ) : false;
}

function wp_set_object_terms( $post_id, $terms, $taxonomy, $append = false ) {
	$GLOBALS['cha_terms_set'][ $post_id ] = $terms;
	return $terms;
}

// ─── Options ─────────────────────────────────────────────────────────────
$GLOBALS['cha_options'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['cha_options'] ) ? $GLOBALS['cha_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['cha_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	$existed = array_key_exists( $key, $GLOBALS['cha_options'] );
	unset( $GLOBALS['cha_options'][ $key ] );
	return $existed;
}

// ─── Reflection helper ───────────────────────────────────────────────────
/**
 * CHA_Importer::match_category() is private — it is the importer's own
 * business — but it is the whole point of the change, so the test drives the
 * real one rather than a copy.
 *
 * @param string $value Spreadsheet cell.
 * @return array|null
 */
function t_match( $value ) {
	$m = new ReflectionMethod( 'CHA_Importer', 'match_category' );
	$m->setAccessible( true );
	return $m->invoke( null, $value );
}

// ─── Code under test ─────────────────────────────────────────────────────
require_once __DIR__ . '/../wordpress-plugin/includes/class-cha-taxonomy.php';
require_once __DIR__ . '/../wordpress-plugin/includes/class-cha-importer.php';
