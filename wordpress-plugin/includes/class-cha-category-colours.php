<?php
/**
 * Heritage category colours (and each site's photo) as CSS custom properties.
 *
 * Folded in from the standalone `cha-category-colours.php` must-use plugin
 * that was live on the site outside version control — the only such code left
 * after the category model shipped. Behaviour is unchanged from that file;
 * this is a straight port to a plugin class, with one deliberate improvement:
 * text-on-colour contrast now delegates to CHA_Taxonomy::text_on() — the
 * mu-plugin duplicated a frozen 7-pair snapshot of that table specifically so
 * it would keep working if the main plugin were ever deactivated, a concern
 * that no longer applies once this lives inside the plugin itself. Carrying
 * two copies of the same table forward would only have let them drift.
 *
 * Publishes each heritage category's colour, matching text colour and icon to
 * the front end as CSS custom properties, so the website can style categories
 * with the same values the trail app uses. Self-updating: add, rename or
 * recolour a category under Heritage Sites → Heritage Categories and this
 * follows, with no code change.
 *
 * ---------------------------------------------------------------------------
 * WHAT THE STYLESHEET GETS
 *
 * 1. A contextual variable that resolves to whichever category you are inside
 *    — a loop item, a category archive, or a single site page:
 *
 *        selector {
 *            background-color: var(--cha-cat-colour, #8C8C8C);
 *            color:            var(--cha-cat-text,   #14110D);
 *        }
 *
 *    No per-item markup is needed: WordPress already puts a
 *    heritage_category-<slug> class on every site record it renders, and this
 *    hangs the variables on that class.
 *
 * 2. Every category addressed by name, for when you want one specific
 *    category's colour regardless of context:
 *
 *        --cha-cat-colour-heritage-site      --cha-cat-text-heritage-site
 *        --cha-cat-colour-blue-plaque-site   --cha-cat-text-blue-plaque-site
 *        --cha-cat-colour-cultural-heritage  --cha-cat-text-cultural-heritage
 *        --cha-cat-colour-natural-heritage   --cha-cat-text-natural-heritage
 *
 * 3. The category icon, ready for a pseudo-element:
 *
 *        selector::before { content: var(--cha-cat-icon); }
 *
 * 4. The site's photograph, as a ready-to-use background image. Heritage site
 *    photos are NOT WordPress featured images — the post type has no
 *    thumbnail support at all — they are external .webp files on the
 *    Association's photo storage, held in the site's `photo` field:
 *
 *        selector {
 *            background-image: var(--cha-photo, none);
 *            background-size: cover;
 *            background-position: center;
 *        }
 *
 *    Sites without a photograph simply have no variable, so the `none`
 *    fallback keeps the card clean. For a plain <img> instead of a
 *    background, the plugin's own [cha_photo] shortcode already does that,
 *    in loops and on single pages alike.
 *
 * Always give a rule a fallback — var(--cha-cat-colour, #8C8C8C) — so nothing
 * disappears if a category is ever without a colour.
 * ---------------------------------------------------------------------------
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Category_Colours {

	/** Post ids of every `site` record rendered on the current page, for the loop-photo pass. */
	protected static $rendered_sites = array();

	/**
	 * Hook every entry point.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'print_css_vars' ), 20 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
		add_filter( 'post_class', array( __CLASS__, 'note_rendered_site' ), 10, 3 );
		add_action( 'wp_head', array( __CLASS__, 'print_single_photo_var' ), 21 );
		add_action( 'wp_footer', array( __CLASS__, 'print_loop_photo_vars' ), 20 );
		add_shortcode( 'cha_cat_colour', array( __CLASS__, 'colour_shortcode' ) );
	}

	/**
	 * Print the category style values into the page head as custom properties.
	 *
	 * Read fresh on every page load, so a recolour in the admin is live at once
	 * — nothing to rebuild, republish or clear.
	 */
	public static function print_css_vars() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'heritage_category',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}

		$root       = array();
		$contextual = array();

		foreach ( $terms as $term ) {
			$slug = sanitize_html_class( $term->slug );
			if ( '' === $slug ) {
				continue;
			}

			$colour = sanitize_hex_color( (string) get_term_meta( $term->term_id, CHA_Taxonomy::META_COLOUR, true ) );

			// The icon is dropped into a CSS string literal, so strip anything that
			// could close the quotes or break out of the declaration.
			$icon = (string) get_term_meta( $term->term_id, CHA_Taxonomy::META_ICON, true );
			$icon = str_replace( array( '"', '\\', "\n", "\r", '<', '>' ), '', $icon );
			$icon = trim( $icon );

			if ( ! $colour && '' === $icon ) {
				continue;
			}

			$declarations = '';

			if ( $colour ) {
				$text = CHA_Taxonomy::text_on( $colour );

				$root[] = sprintf( '--cha-cat-colour-%s:%s;', $slug, $colour );
				$root[] = sprintf( '--cha-cat-text-%s:%s;', $slug, $text );

				$declarations .= sprintf( '--cha-cat-colour:%s;--cha-cat-text:%s;', $colour, $text );
			}

			if ( '' !== $icon ) {
				$root[]        = sprintf( '--cha-cat-icon-%s:"%s";', $slug, $icon );
				$declarations .= sprintf( '--cha-cat-icon:"%s";', $icon );
			}

			/*
			 * Three places the same category counts as "the current one":
			 *
			 *   .heritage_category-<slug>                 every site record WordPress
			 *                                             renders (loop card, single
			 *                                             article) — core post_class()
			 *                                             adds this for any public
			 *                                             taxonomy, so no markup of
			 *                                             ours is needed.
			 *   body.tax-heritage_category.term-<slug>    the category archive page.
			 *                                             Both classes are required:
			 *                                             term-<slug> alone is shared
			 *                                             with every other taxonomy
			 *                                             and could collide with a
			 *                                             trail of the same name.
			 *   body.heritage_category-<slug>             a single site page — added
			 *                                             by body_class() below so page
			 *                                             chrome outside the article
			 *                                             is in scope too.
			 *
			 * A site carrying two categories inherits whichever rule is printed last.
			 * The app takes the first term, so keep one category per site if the two
			 * must agree.
			 */
			$contextual[] = sprintf(
				'.heritage_category-%1$s,body.tax-heritage_category.term-%1$s,body.heritage_category-%1$s{%2$s}',
				$slug,
				$declarations
			);
		}

		if ( empty( $root ) ) {
			return;
		}

		printf(
			'<style id="cha-category-colours">:root{%s}%s</style>' . "\n",
			implode( '', $root ),   // phpcs:ignore WordPress.Security.EscapeOutput -- sanitised hex/class values only.
			implode( '', $contextual ) // phpcs:ignore WordPress.Security.EscapeOutput -- sanitised hex/class values only.
		);
	}

	/**
	 * On a single heritage site page, put the category class on <body> as well.
	 *
	 * WordPress only puts it on the article itself, so a hero section, header
	 * or sidebar rendered outside the article would otherwise sit outside the
	 * variable's scope and fall back to nothing.
	 *
	 * @param string[] $classes Body classes.
	 * @return string[]
	 */
	public static function body_class( $classes ) {
		if ( ! is_singular( 'site' ) ) {
			return $classes;
		}

		$terms = get_the_terms( get_queried_object_id(), 'heritage_category' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return $classes;
		}

		foreach ( $terms as $term ) {
			$classes[] = 'heritage_category-' . sanitize_html_class( $term->slug );
		}

		return $classes;
	}

	/* -------------------------------------------------------------------
	 * The site photograph as --cha-photo.
	 *
	 * A photo is per-site, not per-category, so it cannot be published as one
	 * static rule per class. Instead we note which site records actually
	 * render on this page — post_class() fires once for each — and print a
	 * rule for exactly those, keyed by the post-<ID> class WordPress always
	 * adds. That keeps the stylesheet a few lines long instead of one rule
	 * per site in the archive.
	 * ------------------------------------------------------------------- */

	/**
	 * Remember every site record rendered on this page. Adds no classes.
	 *
	 * @param string[] $classes   Post classes.
	 * @param string[] $css_class Extra classes passed to post_class().
	 * @param int      $post_id   Post ID.
	 * @return string[] Unchanged.
	 */
	public static function note_rendered_site( $classes, $css_class, $post_id ) {
		if ( 'site' === get_post_type( $post_id ) ) {
			self::$rendered_sites[ (int) $post_id ] = true;
		}
		return $classes;
	}

	/**
	 * The `photo` field as a CSS url() value, or '' when the site has none.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	protected static function photo_css_url( $post_id ) {
		$photo = get_post_meta( $post_id, 'photo', true );
		if ( ! is_string( $photo ) || '' === trim( $photo ) ) {
			return '';
		}

		$photo = esc_url( trim( $photo ) );
		if ( '' === $photo ) {
			return '';
		}

		// Belt and braces: nothing that could close the url() or the declaration.
		$photo = str_replace( array( '"', "'", '(', ')', ' ' ), '', $photo );

		return sprintf( 'url("%s")', $photo );
	}

	/**
	 * On a single site page the photo is known before the page is painted, so
	 * publish it in the head, on <body>, where page chrome can reach it too.
	 */
	public static function print_single_photo_var() {
		if ( ! is_singular( 'site' ) ) {
			return;
		}

		$photo = self::photo_css_url( get_queried_object_id() );
		if ( '' === $photo ) {
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput -- URL escaped in the helper.
		echo '<style id="cha-site-photo">body{--cha-photo:' . $photo . ';}</style>' . "\n";
	}

	/**
	 * For loops and archives, publish one rule per site record that actually
	 * rendered. Printed in the footer because the query has to have run first.
	 */
	public static function print_loop_photo_vars() {
		if ( empty( self::$rendered_sites ) ) {
			return;
		}

		$css = '';
		foreach ( array_keys( self::$rendered_sites ) as $post_id ) {
			$photo = self::photo_css_url( $post_id );
			if ( '' !== $photo ) {
				$css .= sprintf( '.post-%d{--cha-photo:%s;}', (int) $post_id, $photo );
			}
		}

		if ( '' !== $css ) {
			// phpcs:ignore WordPress.Security.EscapeOutput -- URLs escaped in the helper, IDs cast to int.
			echo '<style id="cha-site-photos">' . $css . '</style>' . "\n";
		}
	}

	/**
	 * [cha_cat_colour] — the raw hex value, for the rare spot that cannot take
	 * a CSS variable (an SVG fill attribute, say). Prefer the variables above.
	 *
	 *   [cha_cat_colour]               -> #1a4a7a
	 *   [cha_cat_colour type="text"]   -> #FFFFFF
	 *   [cha_cat_colour slug="natural-heritage"] -> that category, wherever you are
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Hex colour.
	 */
	public static function colour_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'type' => 'colour',
				'slug' => '',
			),
			$atts,
			'cha_cat_colour'
		);

		$term = null;

		if ( '' !== $atts['slug'] ) {
			$found = get_term_by( 'slug', sanitize_title( $atts['slug'] ), 'heritage_category' );
			$term  = $found instanceof WP_Term ? $found : null;
		} elseif ( is_singular( 'site' ) || in_the_loop() ) {
			$terms = get_the_terms( get_the_ID(), 'heritage_category' );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				$term = $terms[0];
			}
		} elseif ( is_tax( 'heritage_category' ) ) {
			$queried = get_queried_object();
			$term    = $queried instanceof WP_Term ? $queried : null;
		}

		$colour = $term ? sanitize_hex_color( (string) get_term_meta( $term->term_id, CHA_Taxonomy::META_COLOUR, true ) ) : '';
		$colour = $colour ? $colour : '#8C8C8C'; // the plugin's own neutral fallback.

		return 'text' === $atts['type'] ? CHA_Taxonomy::text_on( $colour ) : $colour;
	}
}
