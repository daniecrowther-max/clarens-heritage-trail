<?php
/**
 * Heritage Site edit-screen meta box.
 *
 * Surfaces the `site` meta (registered in CHA_Meta) on the Heritage Site editor
 * so the site admin can make one-off corrections without the generic Custom Fields panel.
 * Bulk entry still flows through the interns' spreadsheet importer; this is the
 * hand-edit path — so it must round-trip every field the importer writes.
 *
 * Purely an admin-UI layer: it reads/writes the SAME meta keys the importer and
 * CHA_Rest use, and changes no data model or REST behaviour. The
 * heritage_category taxonomy keeps WordPress's native panel — not duplicated
 * here. `facts` and `story` are ACF-free repeaters (plain JS).
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Site_Meta_Box {

	const NONCE = 'cha_site_meta_nonce';

	/**
	 * Hook the meta box + save handler.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_site', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Register the meta box on the site editor.
	 */
	public static function register() {
		add_meta_box(
			'cha_site_details',
			__( 'Heritage Site details', 'cha' ),
			array( __CLASS__, 'render' ),
			'site',
			'normal',
			'high'
		);
	}

	/**
	 * Number fields → label.
	 *
	 * @return array
	 */
	protected static function number_fields() {
		return array(
			'trailNum' => __( 'Order within the trail', 'cha' ),
			'lat'      => __( 'Latitude', 'cha' ),
			'lng'      => __( 'Longitude', 'cha' ),
			'radius'   => __( 'GPS check-in radius in metres (default 30)', 'cha' ),
		);
	}

	/**
	 * Plain text fields → [ label, description ].
	 *
	 * @return array
	 */
	protected static function text_fields() {
		return array(
			// `trail` is now the heritage_trail taxonomy — handled by WordPress's
			// native "Trails" panel, so it is deliberately not an input here.
			'address' => array( __( 'Street address', 'cha' ), '' ),
			'icon'    => array( __( 'Marker glyph / emoji', 'cha' ), __( 'Optional — a fallback pin glyph.', 'cha' ) ),
			'ac'      => array( __( 'Accent style class', 'cha' ), __( 'Optional override — defaults from the category if left blank.', 'cha' ) ),
			'dot'     => array( __( 'Map-marker colour (hex)', 'cha' ), __( 'Optional override — defaults from the category if left blank.', 'cha' ) ),
		);
	}

	/**
	 * URL fields → [ label, description ].
	 *
	 * @return array
	 */
	protected static function url_fields() {
		return array(
			'map'   => array( __( 'Google Maps link', 'cha' ), '' ),
			'photo' => array( __( 'Photo URL (.webp)', 'cha' ), __( 'Leave blank to derive a fallback image from the site id.', 'cha' ) ),
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Site post.
	 */
	public static function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		$bp   = (bool) get_post_meta( $post->ID, 'bp', true );
		$free = (bool) get_post_meta( $post->ID, 'free', true );

		echo '<table class="form-table"><tbody>';

		// Site ID — the app's stable identity, separate from WordPress's own
		// native Permalink editor (above the title on this screen), which
		// only controls the public URL and no longer affects the feed.
		printf(
			'<tr><th><label for="cha_site_id">%s</label></th><td><input type="text" id="cha_site_id" name="cha_site_id" value="%s" class="regular-text" placeholder="GR-001"><p class="description"><strong>%s</strong></p></td></tr>',
			esc_html__( 'Site ID (spreadsheet code, e.g. GR-001)', 'cha' ),
			esc_attr( get_post_meta( $post->ID, 'site_id', true ) ),
			esc_html__( '⚠ Changing this after a site has real visitor stamps will disconnect their existing progress. This is the app\'s stable identity — it is separate from the Permalink above, which the web editor can edit freely.', 'cha' )
		);

		// Checkboxes.
		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="cha_bp" value="1"%s> %s</label></td></tr>',
			esc_html__( 'Blue Plaque', 'cha' ),
			checked( $bp, true, false ),
			esc_html__( 'Blue Plaque site', 'cha' )
		);
		printf(
			'<tr><th>%s</th><td><label><input type="checkbox" name="cha_free" value="1"%s> %s</label></td></tr>',
			esc_html__( 'Free', 'cha' ),
			checked( $free, true, false ),
			esc_html__( 'Part of the free set — open without the trail pass', 'cha' )
		);

		// Numbers.
		foreach ( self::number_fields() as $key => $label ) {
			printf(
				'<tr><th><label for="cha_%1$s">%2$s</label></th><td><input type="number" step="any" id="cha_%1$s" name="cha_%1$s" value="%3$s" class="regular-text"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( get_post_meta( $post->ID, $key, true ) )
			);
		}

		// Text.
		foreach ( self::text_fields() as $key => $field ) {
			list( $label, $desc ) = $field;
			printf(
				'<tr><th><label for="cha_%1$s">%2$s</label></th><td><input type="text" id="cha_%1$s" name="cha_%1$s" value="%3$s" class="regular-text">%4$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( get_post_meta( $post->ID, $key, true ) ),
				$desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : ''
			);
		}

		// plaqueText (textarea).
		printf(
			'<tr><th><label for="cha_plaqueText">%s</label></th><td><textarea id="cha_plaqueText" name="cha_plaqueText" rows="3" class="large-text">%s</textarea><p class="description">%s</p></td></tr>',
			esc_html__( 'Blue Plaque wording', 'cha' ),
			esc_textarea( get_post_meta( $post->ID, 'plaqueText', true ) ),
			esc_html__( 'Exact plaque text. Captured for future use — the app does not display this yet.', 'cha' )
		);

		// URLs.
		foreach ( self::url_fields() as $key => $field ) {
			list( $label, $desc ) = $field;
			printf(
				'<tr><th><label for="cha_%1$s">%2$s</label></th><td><input type="url" id="cha_%1$s" name="cha_%1$s" value="%3$s" class="regular-text">%4$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( get_post_meta( $post->ID, $key, true ) ),
				$desc ? '<p class="description">' . esc_html( $desc ) . '</p>' : ''
			);
		}

		echo '</tbody></table>';

		self::render_facts( $post );
		self::render_story( $post );
		self::render_assets();
	}

	/**
	 * Facts repeater — rows of { l, v }.
	 *
	 * @param WP_Post $post Site post.
	 */
	protected static function render_facts( $post ) {
		$facts = get_post_meta( $post->ID, 'facts', true );
		if ( ! is_array( $facts ) ) {
			$facts = array();
		}

		echo '<h3>' . esc_html__( 'Quick facts', 'cha' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Label/value rows shown on the site card (e.g. Year → 1820).', 'cha' ) . '</p>';
		echo '<div id="cha-facts" class="cha-repeater">';

		foreach ( $facts as $row ) {
			$l = is_array( $row ) && isset( $row['l'] ) ? $row['l'] : '';
			$v = is_array( $row ) && isset( $row['v'] ) ? $row['v'] : '';
			echo self::fact_row_html( $l, $v );
		}

		echo '</div>';
		echo '<p><button type="button" class="button cha-add" data-target="cha-facts">' . esc_html__( '+ Add fact', 'cha' ) . '</button></p>';

		// Blank-row template (its markup is not submitted).
		echo '<template id="cha-facts-tpl">' . self::fact_row_html( '', '' ) . '</template>';
	}

	/**
	 * One facts row.
	 *
	 * @param string $l Label.
	 * @param string $v Value.
	 * @return string
	 */
	protected static function fact_row_html( $l, $v ) {
		return sprintf(
			'<div class="cha-row">'
			. '<input type="text" name="cha_fact_l[]" value="%1$s" placeholder="%2$s" style="width:30%%">'
			. '<input type="text" name="cha_fact_v[]" value="%3$s" placeholder="%4$s" style="width:50%%">'
			. '<button type="button" class="button cha-remove" aria-label="%5$s">&times;</button>'
			. '</div>',
			esc_attr( $l ),
			esc_attr__( 'Label (e.g. Year)', 'cha' ),
			esc_attr( $v ),
			esc_attr__( 'Value (e.g. 1820)', 'cha' ),
			esc_attr__( 'Remove fact', 'cha' )
		);
	}

	/**
	 * Story repeater — ordered paragraphs.
	 *
	 * @param WP_Post $post Site post.
	 */
	protected static function render_story( $post ) {
		$story = get_post_meta( $post->ID, 'story', true );
		if ( ! is_array( $story ) ) {
			$story = array();
		}

		echo '<h3>' . esc_html__( 'Story paragraphs', 'cha' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'One paragraph per box, in reading order. The first is the card teaser.', 'cha' ) . '</p>';
		echo '<div id="cha-story" class="cha-repeater">';

		foreach ( $story as $para ) {
			echo self::story_row_html( is_string( $para ) ? $para : '' );
		}

		echo '</div>';
		echo '<p><button type="button" class="button cha-add" data-target="cha-story">' . esc_html__( '+ Add paragraph', 'cha' ) . '</button></p>';
		echo '<template id="cha-story-tpl">' . self::story_row_html( '' ) . '</template>';
	}

	/**
	 * One story row (paragraph + reorder/remove controls).
	 *
	 * @param string $text Paragraph text.
	 * @return string
	 */
	protected static function story_row_html( $text ) {
		return sprintf(
			'<div class="cha-row cha-story-row">'
			. '<textarea name="cha_story[]" rows="2" style="width:80%%">%1$s</textarea>'
			. '<span class="cha-story-ctrls">'
			. '<button type="button" class="button cha-up" aria-label="%2$s">&uarr;</button>'
			. '<button type="button" class="button cha-down" aria-label="%3$s">&darr;</button>'
			. '<button type="button" class="button cha-remove" aria-label="%4$s">&times;</button>'
			. '</span></div>',
			esc_textarea( $text ),
			esc_attr__( 'Move up', 'cha' ),
			esc_attr__( 'Move down', 'cha' ),
			esc_attr__( 'Remove paragraph', 'cha' )
		);
	}

	/**
	 * Inline styles + repeater JS (no ACF, no build step).
	 */
	protected static function render_assets() {
		echo <<<'HTML'
<style>
.cha-repeater .cha-row{display:flex;gap:8px;align-items:flex-start;margin-bottom:6px}
.cha-repeater .cha-row input,.cha-repeater .cha-row textarea{margin:0}
.cha-story-ctrls{display:flex;gap:4px;flex-shrink:0}
</style>
<script>
(function(){
  if (window.__chaRepeaterInit) return;
  window.__chaRepeaterInit = true;
  document.addEventListener('click', function(e){
    var t = e.target;
    if (t.classList.contains('cha-add')) {
      var box = document.getElementById(t.getAttribute('data-target'));
      var tpl = document.getElementById(t.getAttribute('data-target') + '-tpl');
      if (box && tpl) box.appendChild(tpl.content.cloneNode(true));
    } else if (t.classList.contains('cha-remove')) {
      var row = t.closest('.cha-row'); if (row) row.remove();
    } else if (t.classList.contains('cha-up')) {
      var r = t.closest('.cha-row'); if (r && r.previousElementSibling) r.parentNode.insertBefore(r, r.previousElementSibling);
    } else if (t.classList.contains('cha-down')) {
      var r2 = t.closest('.cha-row'); if (r2 && r2.nextElementSibling) r2.parentNode.insertBefore(r2.nextElementSibling, r2);
    }
  });
})();
</script>
HTML;
	}

	/**
	 * Persist the meta box fields.
	 *
	 * @param int     $post_id Site post ID.
	 * @param WP_Post $post    Site post.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Site ID — the app's stable identity (see the warning in render()).
		if ( isset( $_POST['cha_site_id'] ) ) {
			update_post_meta( $post_id, 'site_id', sanitize_text_field( wp_unslash( $_POST['cha_site_id'] ) ) );
		}

		// Checkboxes — absent means unchecked.
		update_post_meta( $post_id, 'bp', isset( $_POST['cha_bp'] ) );
		update_post_meta( $post_id, 'free', isset( $_POST['cha_free'] ) );

		// Numbers. radius keeps its 30 default when cleared; the rest store 0
		// (the model's "unset" value, omitted from the feed by add_number).
		foreach ( array_keys( self::number_fields() ) as $key ) {
			if ( ! isset( $_POST[ 'cha_' . $key ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_POST[ 'cha_' . $key ] );
			if ( '' === $raw ) {
				$value = ( 'radius' === $key ) ? 30 : 0;
			} else {
				$value = (float) $raw;
			}
			update_post_meta( $post_id, $key, $value );
		}

		// Text.
		foreach ( array_keys( self::text_fields() ) as $key ) {
			if ( isset( $_POST[ 'cha_' . $key ] ) ) {
				update_post_meta( $post_id, $key, sanitize_text_field( wp_unslash( $_POST[ 'cha_' . $key ] ) ) );
			}
		}

		// plaqueText (multiline).
		if ( isset( $_POST['cha_plaqueText'] ) ) {
			update_post_meta( $post_id, 'plaqueText', sanitize_textarea_field( wp_unslash( $_POST['cha_plaqueText'] ) ) );
		}

		// URLs.
		foreach ( array_keys( self::url_fields() ) as $key ) {
			if ( isset( $_POST[ 'cha_' . $key ] ) ) {
				update_post_meta( $post_id, $key, esc_url_raw( wp_unslash( $_POST[ 'cha_' . $key ] ) ) );
			}
		}

		// Facts repeater — zip parallel label/value arrays; drop fully-empty rows.
		$labels = isset( $_POST['cha_fact_l'] ) ? (array) wp_unslash( $_POST['cha_fact_l'] ) : array();
		$values = isset( $_POST['cha_fact_v'] ) ? (array) wp_unslash( $_POST['cha_fact_v'] ) : array();
		$facts  = array();
		foreach ( $labels as $i => $label ) {
			$l = sanitize_text_field( $label );
			$v = sanitize_text_field( isset( $values[ $i ] ) ? $values[ $i ] : '' );
			if ( '' !== $l || '' !== $v ) {
				$facts[] = array( 'l' => $l, 'v' => $v );
			}
		}
		update_post_meta( $post_id, 'facts', $facts );

		// Story repeater — ordered paragraphs; drop blank boxes.
		$paras = isset( $_POST['cha_story'] ) ? (array) wp_unslash( $_POST['cha_story'] ) : array();
		$story = array();
		foreach ( $paras as $para ) {
			$clean = sanitize_textarea_field( $para );
			if ( '' !== trim( $clean ) ) {
				$story[] = $clean;
			}
		}
		update_post_meta( $post_id, 'story', $story );
		CHA_Meta::sync_excerpt_from_story( $post_id, $story );
	}
}
