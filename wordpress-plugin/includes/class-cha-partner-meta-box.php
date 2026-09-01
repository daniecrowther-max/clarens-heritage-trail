<?php
/**
 * Partner / Voucher edit-screen meta box.
 *
 * Surfaces the partner meta (registered in CHA_Meta) on the `partner` CPT
 * editor so staff can configure vouchers directly. Includes the Available Dates
 * window (dateFrom/dateTo) and the Maximum Vouchers stock cap. `usedCount` is
 * shown read-only — it is server-incremented on redemption and must never be
 * hand-edited (there is no input for it, and save() never writes it).
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Partner_Meta_Box {

	const NONCE = 'cha_partner_meta_nonce';

	/**
	 * Hook the meta box + save handler.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_partner', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Register the meta box on the partner editor.
	 */
	public static function register() {
		add_meta_box(
			'cha_partner_voucher',
			__( 'Voucher details', 'cha' ),
			array( __CLASS__, 'render' ),
			'partner',
			'normal',
			'high'
		);
	}

	/**
	 * Plain text/url/number fields → label + input type.
	 *
	 * @return array[] key => [ label, type ]
	 */
	protected static function fields() {
		return array(
			'type'         => array( __( 'Business type', 'cha' ), 'select' ),
			'logo'         => array( __( 'Logo image URL (.webp)', 'cha' ), 'url' ),
			'address'      => array( __( 'Address', 'cha' ), 'text' ),
			'lat'          => array( __( 'Latitude', 'cha' ), 'number' ),
			'lng'          => array( __( 'Longitude', 'cha' ), 'number' ),
			'offer'        => array( __( 'Offer badge (e.g. 10%)', 'cha' ), 'text' ),
			'offerLabel'   => array( __( 'Offer headline (e.g. 10% Discount)', 'cha' ), 'text' ),
			'offerSub'     => array( __( 'Offer detail / conditions line', 'cha' ), 'text' ),
			'siteId'       => array( __( 'Linked heritage site id (slug, optional)', 'cha' ), 'text' ),
			'condition'    => array( __( 'Access condition', 'cha' ), 'select' ),
			'requiredSite' => array( __( 'Required site to unlock (slug, optional)', 'cha' ), 'text' ),
		);
	}

	/**
	 * Business-type dropdown options (Clarens's list). Value === label; the
	 * chosen string is stored verbatim in the `type` meta.
	 *
	 * @return string[]
	 */
	protected static function type_options() {
		return array(
			'Restaurant',
			'Café & Bakery',
			'Pub & Bar',
			'Accommodation',
			'Art Gallery',
			'Craft Shop',
			'Activities & Adventures',
			'Health & Wellness',
			'Retail',
			'Other',
		);
	}

	/**
	 * Access-condition dropdown options, keyed value => label so more can be
	 * added later without a rewrite. Only `paid` is live — redeem() checks
	 * condition === 'paid' and nothing else (stamps deliberately out of scope).
	 *
	 * @return array<string,string>
	 */
	protected static function condition_options() {
		return array(
			'paid' => __( 'Trail pass required (default)', 'cha' ),
		);
	}

	/**
	 * Render a <select> row for `type` or `condition`. A saved value that is
	 * not among the options is appended as a selected option so existing/
	 * non-standard data is preserved on the next save, never silently dropped.
	 *
	 * @param int    $post_id Partner post ID.
	 * @param string $key     Meta key ('type' or 'condition').
	 * @param string $label   Field label.
	 */
	protected static function render_select_row( $post_id, $key, $label ) {
		$current = (string) get_post_meta( $post_id, $key, true );

		if ( 'type' === $key ) {
			$options     = array();
			foreach ( self::type_options() as $opt ) {
				$options[ $opt ] = $opt;
			}
			$placeholder = __( '— Select —', 'cha' );
		} else { // condition
			if ( '' === $current ) {
				$current = 'paid'; // meta default.
			}
			$options     = self::condition_options();
			$placeholder = '';
		}

		echo '<tr><th><label for="cha_' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<select id="cha_' . esc_attr( $key ) . '" name="cha_' . esc_attr( $key ) . '">';

		if ( '' !== $placeholder ) {
			echo '<option value=""' . selected( $current, '', false ) . '>' . esc_html( $placeholder ) . '</option>';
		}

		$known = false;
		foreach ( $options as $value => $opt_label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $current, (string) $value, false ) . '>' . esc_html( $opt_label ) . '</option>';
			if ( (string) $value === $current ) {
				$known = true;
			}
		}

		if ( '' !== $current && ! $known ) {
			echo '<option value="' . esc_attr( $current ) . '" selected>' . esc_html( $current ) . ' ' . esc_html__( '(existing — kept)', 'cha' ) . '</option>';
		}

		echo '</select></td></tr>';
	}

	/**
	 * Render the meta box.
	 *
	 * @param WP_Post $post Partner post.
	 */
	public static function render( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );

		echo '<table class="form-table"><tbody>';

		foreach ( self::fields() as $key => $field ) {
			list( $label, $type ) = $field;
			if ( 'select' === $type ) {
				self::render_select_row( $post->ID, $key, $label );
				continue;
			}
			$value = get_post_meta( $post->ID, $key, true );
			$step  = 'number' === $type ? ' step="any"' : '';
			printf(
				'<tr><th><label for="cha_%1$s">%2$s</label></th><td><input type="%3$s"%4$s id="cha_%1$s" name="cha_%1$s" value="%5$s" class="regular-text"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $type ),
				$step, // safe literal
				esc_attr( $value )
			);
		}

		// Available Dates (from / to).
		$date_from = get_post_meta( $post->ID, 'dateFrom', true );
		$date_to   = get_post_meta( $post->ID, 'dateTo', true );
		printf(
			'<tr><th><label>%s</label></th><td>'
			. '<label>%s <input type="date" name="cha_dateFrom" value="%s"></label> &nbsp; '
			. '<label>%s <input type="date" name="cha_dateTo" value="%s"></label>'
			. '<p class="description">%s</p></td></tr>',
			esc_html__( 'Available dates', 'cha' ),
			esc_html__( 'From', 'cha' ),
			esc_attr( $date_from ),
			esc_html__( 'To', 'cha' ),
			esc_attr( $date_to ),
			esc_html__( 'Leave a field blank for no restriction on that side.', 'cha' )
		);

		// Maximum Vouchers (stock cap) + read-only used count.
		$max_vouchers = (int) get_post_meta( $post->ID, 'maxVouchers', true );
		$used_count   = (int) get_post_meta( $post->ID, 'usedCount', true );
		printf(
			'<tr><th><label for="cha_maxVouchers">%s</label></th><td>'
			. '<input type="number" min="0" step="1" id="cha_maxVouchers" name="cha_maxVouchers" value="%d" style="max-width:120px">'
			. '<p class="description">%s</p>'
			. '<p class="description">%s <strong>%d</strong> %s</p></td></tr>',
			esc_html__( 'Maximum vouchers', 'cha' ),
			$max_vouchers,
			esc_html__( '0 = unlimited redemptions across all visitors.', 'cha' ),
			esc_html__( 'Redeemed so far:', 'cha' ),
			$used_count,
			esc_html__( '(server-managed — not editable here).', 'cha' )
		);

		echo '</tbody></table>';
	}

	/**
	 * Persist the meta box fields.
	 *
	 * @param int     $post_id Partner post ID.
	 * @param WP_Post $post    Partner post.
	 */
	public static function save( $post_id, $post ) {
		// Nonce + autosave + capability guards.
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Text / url / number fields.
		foreach ( self::fields() as $key => $field ) {
			$type  = $field[1];
			$field_name = 'cha_' . $key;
			if ( ! isset( $_POST[ $field_name ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_POST[ $field_name ] );
			if ( 'url' === $type ) {
				$value = esc_url_raw( $raw );
			} elseif ( 'number' === $type ) {
				$value = ( '' === $raw ) ? '' : (float) $raw;
			} else {
				$value = sanitize_text_field( $raw );
			}
			update_post_meta( $post_id, $key, $value );
		}

		// Dates — accept blank or strict YYYY-MM-DD, else store blank.
		foreach ( array( 'dateFrom', 'dateTo' ) as $date_key ) {
			$field_name = 'cha_' . $date_key;
			if ( ! isset( $_POST[ $field_name ] ) ) {
				continue;
			}
			$raw   = sanitize_text_field( wp_unslash( $_POST[ $field_name ] ) );
			$value = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
			update_post_meta( $post_id, $date_key, $value );
		}

		// Stock cap — non-negative integer. usedCount is NEVER written here.
		if ( isset( $_POST['cha_maxVouchers'] ) ) {
			$max = (int) wp_unslash( $_POST['cha_maxVouchers'] );
			if ( $max < 0 ) {
				$max = 0;
			}
			update_post_meta( $post_id, 'maxVouchers', $max );
		}
	}
}
