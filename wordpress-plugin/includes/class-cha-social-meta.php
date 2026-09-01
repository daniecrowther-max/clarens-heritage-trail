<?php
/**
 * Social/search meta tags (og:*, twitter:card, <meta name="description">)
 * for `site` and `partner` singular pages — correct link previews and
 * search snippets without importing external photos into the Media Library
 * (that would reopen the deliberate `photo`/`logo` external-URL decision).
 *
 * No SEO plugin (Yoast/RankMath/AIOSEO/SEOPress) is active on the live site
 * as of this writing (confirmed: no plugin fingerprint/generator tag, no
 * existing og:/description meta tags on a rendered `site` page, and 404s on
 * every common SEO-plugin readme.txt path) — so this prints the tags
 * directly on `wp_head`. If one of those plugins is ever installed, this
 * stands down automatically (seo_plugin_active()) rather than double-
 * printing tags the plugin already owns; it does not hook that plugin's own
 * filters, since building untested integration for a plugin that isn't
 * present would be speculative.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Social_Meta {

	/**
	 * Hook wp_head. Runs directly (not deferred to `init`) — matches the
	 * other classes here whose init() registers its own specific hooks.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 1 );
	}

	/**
	 * Print the tags for a `site`/`partner` singular request. No-ops on
	 * every other request, and on any field that's empty — never prints a
	 * tag pointing at an empty value.
	 */
	public static function output() {
		if ( self::seo_plugin_active() ) {
			return; // Let the SEO plugin own social/search meta entirely.
		}
		if ( ! is_singular( array( 'site', 'partner' ) ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$title       = get_the_title( $post );
		$image       = 'site' === $post->post_type
			? (string) get_post_meta( $post->ID, 'photo', true )
			: (string) get_post_meta( $post->ID, 'logo', true );
		$description = self::description_for( $post );
		$url         = get_permalink( $post );

		if ( '' !== $title ) {
			printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
			printf( '<meta name="twitter:card" content="%s">' . "\n", esc_attr( '' !== $image ? 'summary_large_image' : 'summary' ) );
		}
		if ( '' !== $image ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $image ) );
		}
		if ( '' !== $description ) {
			printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $description ) );
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $description ) );
		}
		if ( is_string( $url ) && '' !== $url ) {
			printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
			printf( '<meta property="og:type" content="website">' . "\n" );
		}
	}

	/**
	 * The post's native excerpt, plain text. For `site`, CHA_Meta keeps this
	 * in step with `story[0]`; `partner` has no auto-sync yet, so this is
	 * empty unless one is set directly — the tag is simply omitted then.
	 *
	 * @param WP_Post $post Site or partner post.
	 * @return string
	 */
	private static function description_for( $post ) {
		$excerpt = get_post_field( 'post_excerpt', $post->ID );
		return is_string( $excerpt ) ? trim( wp_strip_all_tags( $excerpt ) ) : '';
	}

	/**
	 * Detect a known SEO plugin by its well-established constant, so this
	 * class stands down rather than duplicating tags the plugin owns.
	 *
	 * @return bool
	 */
	private static function seo_plugin_active() {
		return defined( 'WPSEO_VERSION' )      // Yoast SEO
			|| defined( 'RANK_MATH_VERSION' )  // Rank Math
			|| defined( 'AIOSEO_VERSION' )     // All in One SEO
			|| defined( 'SEOPRESS_VERSION' );  // SEOPress
	}
}
