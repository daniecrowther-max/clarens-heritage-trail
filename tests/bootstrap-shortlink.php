<?php
/**
 * Test harness for CHA_Short_Links — postmeta model + WordPress stubs.
 *
 * Separate from the other bootstraps because each stubs a different slice of
 * WordPress and declaring the same function twice fatals.
 *
 * What this proves: id normalisation, the found/unpublished/unknown branch,
 * the base/target filter chain, the scan counter's exactly-once increment,
 * and that a site resolves via its OWN site_id even when another site holds
 * its slug (the real, live data shape this whole feature exists for — see
 * the fixtures below, copied from the production database dump).
 *
 * What this cannot prove: that a real WordPress rewrite rule actually routes
 * `/s/supply-store` to `parse_request`, that LiteSpeed actually honours
 * DONOTCACHEPAGE, or what a browser receives on the wire. That needs the
 * LocalWP + live curl pass — see docs/CHA_Development_Plan_v0.11.md.
 *
 * @package cha
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'OBJECT', 'OBJECT' );
define( 'CHA_VERSION', 'test' );

// ─── Assertions ──────────────────────────────────────────────────────────
$GLOBALS['cha_tests'] = array( 'pass' => 0, 'fail' => 0 );

function t_group( $name ) {
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

// ─── Fake $wpdb — models the atomic conditional UPDATE, nothing else ─────
class Fake_WPDB {
	public $prefix   = 'wp_';
	public $postmeta = 'wp_postmeta';

	public function prepare( $sql, ...$args ) {
		foreach ( $args as $a ) {
			$replacement = is_int( $a ) ? (string) (int) $a : "'" . str_replace( "'", "\\'", (string) $a ) . "'";
			$sql         = preg_replace( '/%[sd]/', $replacement, $sql, 1 );
		}
		return $sql;
	}

	/**
	 * Models the STATEMENT's effect on the in-memory postmeta store, not a
	 * real row lock — same caveat as the redeem-stock harness. Critically,
	 * it also models that a raw UPDATE can only touch a row that already
	 * EXISTS — it can never create one. A stub that "helpfully" creates the
	 * row on demand would hide the exact bug metadata_exists() seeding
	 * exists to prevent (see class-cha-redeem.php's claim_stock() and the
	 * CHA-fork incident it documents): without the seed, every site's first
	 * scan would silently update zero rows and never start counting.
	 */
	public function query( $sql ) {
		if ( ! preg_match( '/UPDATE\s+\S+\s+SET\s+meta_value\s*=\s*CAST\(meta_value AS UNSIGNED\)\s*\+\s*1/is', $sql ) ) {
			throw new Exception( 'Fake_WPDB::query() got SQL it does not model: ' . $sql );
		}
		if ( ! preg_match( '/post_id\s*=\s*(\d+)/i', $sql, $m_post ) ) {
			throw new Exception( 'No post_id predicate in: ' . $sql );
		}
		if ( ! preg_match( "/meta_key\s*=\s*'([^']+)'/i", $sql, $m_key ) ) {
			throw new Exception( 'No meta_key predicate in: ' . $sql );
		}
		$post_id = (int) $m_post[1];
		$key     = $m_key[1];

		if ( ! isset( $GLOBALS['cha_post_meta'][ $post_id ][ $key ] ) ) {
			return 0; // No such row — a real UPDATE matches nothing and creates nothing.
		}
		$current                                        = (int) $GLOBALS['cha_post_meta'][ $post_id ][ $key ];
		$GLOBALS['cha_post_meta'][ $post_id ][ $key ]   = (string) ( $current + 1 );
		return 1;
	}
}
$GLOBALS['wpdb'] = new Fake_WPDB();

// ─── Posts + postmeta ─────────────────────────────────────────────────────
$GLOBALS['cha_posts']     = array();
$GLOBALS['cha_post_meta'] = array();

/**
 * Models real get_posts(): post_type, post_status ('any' matches all), a
 * meta_key filter — bare (post must simply carry the key, any value,
 * matching real WP when meta_value is omitted — used by plaque_rows() to
 * mean "every site with a Site ID") or meta_key+meta_value as an equality
 * filter (used by find_site() to look up one id) — plus posts_per_page and
 * fields=ids. This is exactly the shape both CHA_Short_Links and the real
 * importer query on, so a query this stub cannot answer is a sign the code
 * under test drifted from the documented lookup pattern, not a stub gap.
 */
function get_posts( $args = array() ) {
	$type          = isset( $args['post_type'] ) ? $args['post_type'] : 'post';
	$status        = isset( $args['post_status'] ) ? $args['post_status'] : 'publish';
	$meta_key      = isset( $args['meta_key'] ) ? $args['meta_key'] : null;
	$has_meta_value = array_key_exists( 'meta_value', $args );
	$meta_value    = $has_meta_value ? $args['meta_value'] : null;
	$limit         = isset( $args['posts_per_page'] ) ? (int) $args['posts_per_page'] : -1;
	$fields        = isset( $args['fields'] ) ? $args['fields'] : 'all';

	$out = array();
	foreach ( $GLOBALS['cha_posts'] as $post ) {
		if ( $post->post_type !== $type ) {
			continue;
		}
		if ( 'any' !== $status && $post->post_status !== $status ) {
			continue;
		}
		if ( null !== $meta_key ) {
			$post_meta = isset( $GLOBALS['cha_post_meta'][ $post->ID ] ) ? $GLOBALS['cha_post_meta'][ $post->ID ] : array();
			if ( ! array_key_exists( $meta_key, $post_meta ) ) {
				continue; // No such meta at all — fails both the bare-key and the equality form.
			}
			if ( $has_meta_value && (string) $post_meta[ $meta_key ] !== (string) $meta_value ) {
				continue;
			}
		}
		$out[] = ( 'ids' === $fields ) ? $post->ID : $post;
		if ( $limit > 0 && count( $out ) >= $limit ) {
			break;
		}
	}
	return $out;
}

function get_post_meta( $post_id, $key = '', $single = false ) {
	$val = isset( $GLOBALS['cha_post_meta'][ $post_id ][ $key ] ) ? $GLOBALS['cha_post_meta'][ $post_id ][ $key ] : null;
	if ( null === $val ) {
		return $single ? '' : array();
	}
	return $single ? $val : array( $val );
}

function metadata_exists( $meta_type, $post_id, $key ) {
	return isset( $GLOBALS['cha_post_meta'][ $post_id ][ $key ] );
}

function add_post_meta( $post_id, $key, $value, $unique = false ) {
	if ( $unique && metadata_exists( 'post', $post_id, $key ) ) {
		return false;
	}
	$GLOBALS['cha_post_meta'][ $post_id ][ $key ] = $value;
	return true;
}

function update_post_meta( $post_id, $key, $value ) {
	$GLOBALS['cha_post_meta'][ $post_id ][ $key ] = $value;
	return true;
}

function get_permalink( $post ) {
	// Mirrors the real `site` CPT rewrite (class-cha-post-types.php):
	// /heritage-site/{post_name}/ — derived from the post's OWN slug, which
	// is the whole point of test #10: resolving via site_id must never leak
	// a DIFFERENT post's slug into the URL.
	return 'https://clarensheritage.org/heritage-site/' . $post->post_name . '/';
}

function get_page_by_path( $slug, $output = 'OBJECT', $type = 'post' ) {
	foreach ( $GLOBALS['cha_posts'] as $post ) {
		if ( $post->post_name === $slug && $post->post_type === $type ) {
			return $post;
		}
	}
	return null;
}

function get_edit_post_link( $post_id ) {
	return 'https://clarensheritage.org/wp-admin/post.php?post=' . (int) $post_id . '&action=edit';
}

// ─── Options ───────────────────────────────────────────────────────────────
$GLOBALS['cha_options'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['cha_options'] ) ? $GLOBALS['cha_options'][ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['cha_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['cha_options'][ $key ] );
	return true;
}

// ─── Filters — a real (if small) registry, so cha_short_link_base/target
// can actually be exercised, not just no-op'd ─────────────────────────────
$GLOBALS['cha_filters'] = array();

function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['cha_filters'][ $tag ][] = array(
		'cb'   => $callback,
		'args' => $accepted_args,
	);
	return true;
}

function apply_filters( $tag, $value, ...$args ) {
	if ( empty( $GLOBALS['cha_filters'][ $tag ] ) ) {
		return $value;
	}
	foreach ( $GLOBALS['cha_filters'][ $tag ] as $filter ) {
		$call_args = array_slice( array_merge( array( $value ), $args ), 0, max( 1, (int) $filter['args'] ) );
		$value     = call_user_func_array( $filter['cb'], $call_args );
	}
	return $value;
}

/** Matches add_filter()'s callback identity, ignoring priority (not tracked above). */
function remove_filter( $tag, $callback, $priority = 10 ) {
	if ( empty( $GLOBALS['cha_filters'][ $tag ] ) ) {
		return false;
	}
	foreach ( $GLOBALS['cha_filters'][ $tag ] as $i => $filter ) {
		if ( $filter['cb'] === $callback ) {
			unset( $GLOBALS['cha_filters'][ $tag ][ $i ] );
			return true;
		}
	}
	return false;
}

function add_action() {}
function do_action() {}

// ─── Misc WordPress helpers ─────────────────────────────────────────────
function __( $text, $domain = null ) {
	return $text;
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function esc_html_e( $text, $domain = null ) {
	echo $text; // phpcs:ignore -- test stub, not real output escaping.
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return $url;
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function sanitize_title( $title ) {
	$title = strtolower( (string) $title );
	$title = preg_replace( '/[^a-z0-9]+/', '-', $title );
	return trim( $title, '-' );
}

function trailingslashit( $string ) {
	return rtrim( $string, '/\\' ) . '/';
}

function untrailingslashit( $string ) {
	return rtrim( $string, '/\\' );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( $path = '' ) {
	return 'https://clarensheritage.org' . $path;
}

function admin_url( $path = '' ) {
	return 'https://clarensheritage.org/wp-admin/' . $path;
}

function add_query_arg( $args, $url ) {
	$sep = ( false === strpos( $url, '?' ) ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}

function wp_create_nonce( $action = -1 ) {
	return 'test-nonce';
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	return '';
}

function current_user_can( $cap ) {
	return true;
}

function current_time( $type, $gmt = 0 ) {
	if ( 'mysql' === $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
	if ( 'timestamp' === $type ) {
		return time();
	}
	return gmdate( $type ); // e.g. 'Y-m-d' — real current_time() supports a PHP date() format since WP 5.3.
}

function nocache_headers() {
	$GLOBALS['cha_nocache_headers_called'] = true;
}

function wp_safe_redirect( $url, $status = 302 ) {
	$GLOBALS['cha_redirect'] = array(
		'url'    => $url,
		'status' => $status,
	);
	return true;
}

// ─── Code under test ─────────────────────────────────────────────────────
$cha_short_links_file = getenv( 'CHA_SHORT_LINKS_PHP' );
if ( ! $cha_short_links_file ) {
	$cha_short_links_file = __DIR__ . '/../wordpress-plugin/includes/class-cha-short-links.php';
}
require_once $cha_short_links_file;

// ─── Helpers ─────────────────────────────────────────────────────────────

/** Reset all mutable state between cases. */
function t_reset() {
	$GLOBALS['wpdb']                        = new Fake_WPDB();
	$GLOBALS['cha_posts']                  = array();
	$GLOBALS['cha_post_meta']              = array();
	$GLOBALS['cha_options']                = array();
	$GLOBALS['cha_filters']                = array();
	$GLOBALS['cha_nocache_headers_called'] = false;
	$GLOBALS['cha_redirect']               = null;
}

/**
 * Seed a `site` post with its site_id meta, mirroring the real importer's
 * separation of the two (class-cha-meta.php / class-cha-importer.php).
 */
function t_site( $post_id, $site_id, $slug, $title, $status = 'publish' ) {
	$GLOBALS['cha_posts'][ $post_id ] = (object) array(
		'ID'          => $post_id,
		'post_name'   => $slug,
		'post_title'  => $title,
		'post_type'   => 'site',
		'post_status' => $status,
	);
	if ( '' !== $site_id ) {
		$GLOBALS['cha_post_meta'][ $post_id ]['site_id'] = $site_id;
	}
}

/** Seed the fallback landing page. */
function t_trail_page( $post_id = 11, $slug = 'trail', $title = 'Heritage Trail' ) {
	$GLOBALS['cha_posts'][ $post_id ] = (object) array(
		'ID'          => $post_id,
		'post_name'   => $slug,
		'post_title'  => $title,
		'post_type'   => 'page',
		'post_status' => 'publish',
	);
}

/** Current scan-count meta as the store holds it (int, or null if no row). */
function t_scan_count( $post_id ) {
	return isset( $GLOBALS['cha_post_meta'][ $post_id ][ CHA_Short_Links::META_COUNT ] )
		? (int) $GLOBALS['cha_post_meta'][ $post_id ][ CHA_Short_Links::META_COUNT ]
		: null;
}
