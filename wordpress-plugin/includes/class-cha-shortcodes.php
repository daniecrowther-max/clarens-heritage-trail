<?php
/**
 * Shortcodes for `site` fields that don't work as plain Elementor Custom
 * Field tags: the array-shaped `facts`/`story`, and `photo` (needs an <img>
 * wrapper, not a bare URL). The web editor embeds these via Elementor's
 * Shortcode widget inside a Theme Builder single-post loop, but all three
 * are plain WordPress shortcodes with no Elementor dependency.
 *
 * None take attributes — all three always read the current post
 * (get_the_ID()), so they work naturally wherever the loop places them.
 *
 * [cha_photo] has no derived fallback when `photo` is empty. The app's own
 * fallback (getImg(), app/index.html:761) looks up a DOM element
 * `#img-{id}` that only the Clarens photo-preloader ever created — and that
 * preloader was intentionally removed for GR (app/index.html:306-307:
 * "GR site photos are external .webp URLs ... not bundled assets"), so
 * getImg() is dead code that always returns '' in the GR app today; the
 * app's real empty-photo behaviour is an icon placeholder (`s.icon`), not a
 * derived image URL. There's nothing to mirror server-side, so this
 * shortcode outputs nothing when photo is empty — same safe default as
 * facts/story.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Shortcodes {

	/**
	 * Register the shortcodes + the CSS that styles [cha_facts]. Hooked to
	 * `init`.
	 */
	public static function register() {
		add_shortcode( 'cha_facts', array( __CLASS__, 'render_facts' ) );
		add_shortcode( 'cha_story', array( __CLASS__, 'render_story' ) );
		add_shortcode( 'cha_photo', array( __CLASS__, 'render_photo' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Frontend CSS for the [cha_facts] "Quick Facts" block. Registered
	 * unconditionally (not gated to `is_singular('site')`) since Elementor
	 * Theme Builder templates don't reliably report that, and the stylesheet
	 * is small and entirely scoped under .cha-facts-block so it's harmless
	 * on pages that don't use the shortcode.
	 */
	public static function enqueue_assets() {
		wp_enqueue_style(
			'cha-shortcodes',
			CHA_PLUGIN_URL . 'assets/css/cha-shortcodes.css',
			array(),
			CHA_VERSION
		);
	}

	/**
	 * [cha_facts] — the current post's `facts` meta ({l, v} rows) as a
	 * styled "Quick Facts" block (see assets/css/cha-shortcodes.css).
	 * Outputs nothing when there are no facts (no empty wrapper).
	 *
	 * @return string
	 */
	public static function render_facts() {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$facts = get_post_meta( $post_id, 'facts', true );
		if ( ! is_array( $facts ) || empty( $facts ) ) {
			return '';
		}

		$rows = '';
		foreach ( $facts as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['l'] ) ? (string) $row['l'] : '';
			$value = isset( $row['v'] ) ? (string) $row['v'] : '';
			if ( '' === $label && '' === $value ) {
				continue;
			}
			$rows .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}

		if ( '' === $rows ) {
			return '';
		}

		return '<div class="cha-facts-block">'
			. '<h3 class="cha-facts-block-title">' . esc_html__( 'Quick Facts', 'cha' ) . '</h3>'
			. '<dl class="cha-facts">' . $rows . '</dl>'
			. '</div>';
	}

	/**
	 * [cha_story] — the current post's `story` meta (paragraph strings) as
	 * one <p> per paragraph. Outputs nothing when there is no story (no empty
	 * wrapper).
	 *
	 * @return string
	 */
	public static function render_story() {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$story = get_post_meta( $post_id, 'story', true );
		if ( ! is_array( $story ) || empty( $story ) ) {
			return '';
		}

		$paragraphs = '';
		foreach ( $story as $para ) {
			if ( ! is_string( $para ) || '' === trim( $para ) ) {
				continue;
			}
			$paragraphs .= '<p>' . esc_html( $para ) . '</p>';
		}

		if ( '' === $paragraphs ) {
			return '';
		}

		return '<div class="cha-story">' . $paragraphs . '</div>';
	}

	/**
	 * [cha_photo] — the current post's `photo` meta as a plain <img>.
	 * Outputs nothing when there is no photo — no derived fallback (see the
	 * class doc comment for why).
	 *
	 * @return string
	 */
	public static function render_photo() {
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return '';
		}

		$photo = get_post_meta( $post_id, 'photo', true );
		if ( ! is_string( $photo ) || '' === trim( $photo ) ) {
			return '';
		}

		return '<img src="' . esc_url( $photo ) . '" alt="' . esc_attr( get_the_title( $post_id ) ) . '" class="cha-photo">';
	}
}
