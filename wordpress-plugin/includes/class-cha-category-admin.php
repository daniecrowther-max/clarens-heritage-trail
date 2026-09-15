<?php
/**
 * Admin surface for the heritage category model.
 *
 * Two jobs, both about making the model's state visible rather than inferred:
 *
 *  1. Per-category styling — a colour and a glyph — edited on the Add/Edit
 *     Heritage Category screens WordPress already provides, so a committee
 *     member never leaves a screen she knows. A Colour column on the category
 *     list puts every category side by side, which is where a clash is
 *     actually spotted, and a duplicate colour is reported outright.
 *
 *  2. An Uncategorised count and filter on the Heritage Sites list. The import
 *     report is the weaker half of the uncategorised fix — nobody reads it
 *     twice. This is the half that keeps mentioning it, and it covers drafts,
 *     which never reach the app feed at all.
 *
 * The model itself (palette, assignment, definitions) lives in
 * CHA_Taxonomy — this file is only UI.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Category_Admin {

	const TAXONOMY  = 'heritage_category';
	const QUERY_VAR = 'cha_uncategorised';

	/**
	 * Hook the term fields, the list column and the uncategorised view.
	 */
	public static function init() {
		add_action( self::TAXONOMY . '_add_form_fields', array( __CLASS__, 'render_add_fields' ) );
		add_action( self::TAXONOMY . '_edit_form_fields', array( __CLASS__, 'render_edit_fields' ) );
		add_action( 'created_' . self::TAXONOMY, array( __CLASS__, 'save_fields' ) );
		add_action( 'edited_' . self::TAXONOMY, array( __CLASS__, 'save_fields' ) );

		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( __CLASS__, 'add_colour_column' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( __CLASS__, 'render_colour_column' ), 10, 3 );

		add_action( 'admin_notices', array( __CLASS__, 'duplicate_colour_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'undefined_category_notice' ) );
		add_action( 'admin_notices', array( __CLASS__, 'backup_notice' ) );

		// The rollback has to be reachable by a human under pressure, not just
		// callable from code. Two ways in: a button on the Heritage Categories
		// screen, and WP-CLI for anyone with shell access.
		add_action( 'admin_post_cha_category_backup', array( __CLASS__, 'handle_backup_action' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'cha category-styles', array( __CLASS__, 'cli' ) );
		}

		add_filter( 'views_edit-site', array( __CLASS__, 'add_uncategorised_view' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_uncategorised' ) );
	}

	/* ---- term fields --------------------------------------------------- */

	/**
	 * Fields on the "Add New Heritage Category" form.
	 */
	public static function render_add_fields() {
		?>
		<div class="form-field">
			<label for="cha_cat_icon"><?php esc_html_e( 'Glyph', 'cha' ); ?></label>
			<input type="text" name="cha_cat_icon" id="cha_cat_icon" value="" maxlength="8">
			<p><?php esc_html_e( 'An emoji shown on the site card when there is no photo, for example 🏛️. Leave blank for a default.', 'cha' ); ?></p>
		</div>
		<div class="form-field">
			<label><?php esc_html_e( 'Colour', 'cha' ); ?></label>
			<p><?php esc_html_e( 'A colour is assigned automatically when you save. You can change it afterwards on this category\'s edit screen.', 'cha' ); ?></p>
			<?php echo self::capacity_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in capacity_note(). ?>
		</div>
		<?php
	}

	/**
	 * How much of the palette is left, said where a person meets the limit.
	 *
	 * The brand palette holds seven pairings that clear the accessibility
	 * contrast requirement. Past that two categories must share a colour, and
	 * choosing a new brand colour is the Association's decision, not the plugin's —
	 * so the person adding the eighth category should learn that here rather
	 * than discover it in the app.
	 *
	 * @return string HTML.
	 */
	protected static function capacity_note() {
		$capacity = CHA_Taxonomy::palette_capacity();
		$used     = count( CHA_Taxonomy::category_definitions() );
		$left     = max( 0, $capacity - $used );

		if ( $left > 0 ) {
			return sprintf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: 1: colours remaining, 2: total palette size. */
						_n(
							'%1$d of the %2$d brand colours is still unused. These are the only colours in the CHA palette that meet the accessibility contrast requirement.',
							'%1$d of the %2$d brand colours are still unused. These are the only colours in the CHA palette that meet the accessibility contrast requirement.',
							$left,
							'cha'
						),
						$left,
						$capacity
					)
				)
			);
		}

		return sprintf(
			'<p class="description" style="color:#8a6100"><strong>%s</strong> %s</p>',
			esc_html__( 'All brand colours are in use.', 'cha' ),
			esc_html(
				sprintf(
					/* translators: %d: palette size. */
					__( 'The CHA palette has %d colours that meet the accessibility contrast requirement, so any further category will share one and the two will look identical in the app. Adding an eighth colour is a branding decision for the Association — it has to come from the brand tokens.', 'cha' ),
					$capacity
				)
			)
		);
	}

	/**
	 * Fields on the "Edit Heritage Category" form.
	 *
	 * @param WP_Term $term Term being edited.
	 */
	public static function render_edit_fields( $term ) {
		$colour = sanitize_hex_color( (string) get_term_meta( $term->term_id, CHA_Taxonomy::META_COLOUR, true ) );
		$icon   = (string) get_term_meta( $term->term_id, CHA_Taxonomy::META_ICON, true );

		$definitions = CHA_Taxonomy::category_definitions();
		$effective   = isset( $definitions[ $term->slug ] ) ? $definitions[ $term->slug ] : CHA_Taxonomy::UNCATEGORISED;
		?>
		<tr class="form-field">
			<th scope="row"><label for="cha_cat_icon"><?php esc_html_e( 'Glyph', 'cha' ); ?></label></th>
			<td>
				<input type="text" name="cha_cat_icon" id="cha_cat_icon" value="<?php echo esc_attr( $icon ); ?>" maxlength="8">
				<p class="description"><?php esc_html_e( 'Shown on the site card when there is no photo. Leave blank for a default.', 'cha' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="cha_cat_colour"><?php esc_html_e( 'Colour', 'cha' ); ?></label></th>
			<td>
				<input type="color" name="cha_cat_colour" id="cha_cat_colour" value="<?php echo esc_attr( $effective['colour'] ); ?>">
				<p>
					<label>
						<input type="checkbox" name="cha_cat_colour_auto" value="1"<?php checked( '' === (string) $colour ); ?>>
						<?php esc_html_e( 'Choose a colour for me', 'cha' ); ?>
					</label>
				</p>
				<?php echo self::swatch( $effective ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts in swatch(). ?>
				<p class="description">
					<?php esc_html_e( 'Used for this category\'s badge and the stripe down the side of its site cards. Map pin colour is not affected — pins stay coloured by type (Blue Plaque, voucher partner) because that is wayfinding, not category.', 'cha' ); ?>
				</p>
				<?php echo self::capacity_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in capacity_note(). ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Persist the two fields.
	 *
	 * "Choose a colour for me" clears the stored value and immediately
	 * re-assigns one, so the result is still a fixed colour the admin can see
	 * and later override — not a value that drifts as other terms change.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_fields( $term_id ) {
		if ( current_user_can( 'manage_categories' ) ) {
			self::save_posted_fields( $term_id );
		}

		// Always, even with no current user: a term created by activation or by
		// WP-CLI still needs its colour written down, or the assignment is
		// recomputed on every request and can shift when another term is added.
		CHA_Taxonomy::persist_assigned_colour( $term_id );
	}

	/**
	 * The two fields, when they were actually submitted by a person.
	 *
	 * @param int $term_id Term ID.
	 */
	protected static function save_posted_fields( $term_id ) {
		if ( isset( $_POST['cha_cat_icon'] ) ) {
			$icon = CHA_Taxonomy::sanitize_icon( wp_unslash( $_POST['cha_cat_icon'] ) );
			if ( '' === $icon ) {
				delete_term_meta( $term_id, CHA_Taxonomy::META_ICON );
			} else {
				update_term_meta( $term_id, CHA_Taxonomy::META_ICON, $icon );
			}
		}

		if ( ! empty( $_POST['cha_cat_colour_auto'] ) ) {
			delete_term_meta( $term_id, CHA_Taxonomy::META_COLOUR );
		} elseif ( isset( $_POST['cha_cat_colour'] ) ) {
			$colour = sanitize_hex_color( sanitize_text_field( wp_unslash( $_POST['cha_cat_colour'] ) ) );
			if ( $colour ) {
				update_term_meta( $term_id, CHA_Taxonomy::META_COLOUR, $colour );
			}
		}
	}

	/* ---- list table ---------------------------------------------------- */

	/**
	 * Add a Colour column to the category list.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_colour_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'name' === $key ) {
				$out['cha_colour'] = __( 'Colour', 'cha' );
			}
		}
		return $out;
	}

	/**
	 * Render the swatch.
	 *
	 * @param string $content Existing column content.
	 * @param string $column  Column key.
	 * @param int    $term_id Term ID.
	 * @return string
	 */
	public static function render_colour_column( $content, $column, $term_id ) {
		if ( 'cha_colour' !== $column ) {
			return $content;
		}

		$term = get_term( $term_id, self::TAXONOMY );
		if ( ! $term || is_wp_error( $term ) ) {
			return $content;
		}

		$definitions = CHA_Taxonomy::category_definitions();
		if ( ! isset( $definitions[ $term->slug ] ) ) {
			return $content;
		}

		$duplicates = CHA_Taxonomy::duplicate_colours();
		$clashes    = isset( $duplicates[ strtolower( $definitions[ $term->slug ]['colour'] ) ] );

		return self::swatch( $definitions[ $term->slug ], $clashes );
	}

	/**
	 * One colour swatch, with the glyph and the hex beside it.
	 *
	 * @param array $definition Category definition.
	 * @param bool  $clashes    Whether another category shares this colour.
	 * @return string HTML.
	 */
	protected static function swatch( $definition, $clashes = false ) {
		$html = sprintf(
			'<span style="display:inline-flex;align-items:center;gap:8px">'
				. '<span style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:22px;border-radius:4px;border:1px solid rgba(0,0,0,.2);background:%1$s;color:%2$s;font-size:12px">%3$s</span>'
				. '<code>%4$s</code>'
			. '</span>',
			esc_attr( $definition['colour'] ),
			esc_attr( $definition['text'] ),
			esc_html( $definition['icon'] ),
			esc_html( $definition['colour'] )
		);

		if ( $clashes ) {
			$html .= ' <span style="color:#b91c1c;font-weight:600">&#9888; ' . esc_html__( 'shared with another category', 'cha' ) . '</span>';
		}

		return $html;
	}

	/**
	 * Say so when two categories share a colour.
	 *
	 * Under scarcity assignment this cannot happen below the eighth category —
	 * so when it does fire it means either the palette is exhausted or someone
	 * picked a duplicate by hand, and both are worth saying out loud.
	 */
	public static function duplicate_colour_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . self::TAXONOMY !== $screen->id ) {
			return;
		}

		$duplicates = CHA_Taxonomy::duplicate_colours();
		if ( empty( $duplicates ) ) {
			return;
		}

		$pairs = array();
		foreach ( $duplicates as $names ) {
			$pairs[] = implode( ' + ', $names );
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
			esc_html__( 'Two categories share a colour:', 'cha' ),
			esc_html( implode( '; ', $pairs ) ),
			esc_html__( 'They will look identical in the app. Open one of them and pick a different colour. The built-in palette has seven colours, so this also happens once there are more than seven categories.', 'cha' )
		);
	}

	/**
	 * Say so when a site references a category the feed cannot style.
	 *
	 * This is the same shape of failure the whole change exists to remove: it
	 * renders plausibly (a grey badge with the right name), so nobody learns
	 * the feed is out of step with the taxonomy. Shown on both the category
	 * screen and the Heritage Sites list, because either is where someone
	 * would be looking when it matters.
	 */
	public static function undefined_category_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->id, array( 'edit-' . self::TAXONOMY, 'edit-site' ), true ) ) {
			return;
		}

		$missing = CHA_Taxonomy::undefined_categories();
		if ( empty( $missing ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p><p>%3$s</p></div>',
			esc_html__( 'Categories in use but missing from the app feed:', 'cha' ),
			esc_html( implode( ', ', $missing ) ),
			esc_html__( 'Sites in those categories render unstyled and grey in the app rather than in their own colour. This normally means a plugin or filter is removing them from the feed — the categories themselves are fine.', 'cha' )
		);
	}

	/**
	 * Offer the rollback while a migration backup exists.
	 *
	 * restore_style_meta_backup() is only genuinely a rollback if someone can
	 * reach it without writing code, so it gets a button. The notice stays
	 * until it is used or explicitly discarded — nothing expires it, because an
	 * unattended cleanup would quietly remove the only recovery path.
	 */
	public static function backup_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit-' . self::TAXONOMY !== $screen->id ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$backup = get_option( CHA_Taxonomy::STYLE_BACKUP_OPTION );
		if ( ! is_array( $backup ) || empty( $backup['rows'] ) ) {
			return;
		}

		$count   = count( $backup['rows'] );
		$created = isset( $backup['created'] ) ? $backup['created'] : '';

		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'Category style rollback available.', 'cha' ); ?></strong>
				<?php
				printf(
					/* translators: 1: number of sites, 2: date the backup was taken. */
					esc_html__( 'The upgrade to per-category styling removed the old per-site accent, marker-colour and glyph values from %1$d sites on %2$s. They are still recorded and can be put back.', 'cha' ),
					(int) $count,
					esc_html( $created )
				);
				?>
			</p>
			<p>
				<?php esc_html_e( 'Restoring undoes the styling upgrade\'s data change only — it does not downgrade the plugin. Discard once you are satisfied the new styling is correct; nothing else will ever clear it.', 'cha' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:8px">
				<?php wp_nonce_field( 'cha_category_backup' ); ?>
				<input type="hidden" name="action" value="cha_category_backup">
				<button type="submit" name="cha_backup_action" value="restore" class="button"
					onclick="return confirm('<?php echo esc_js( __( 'Put the old per-site accent, marker-colour and glyph values back on every site? The category styling stays installed, so sites will use their own stored glyph again instead of the category\'s.', 'cha' ) ); ?>');">
					<?php esc_html_e( 'Restore the old values', 'cha' ); ?>
				</button>
				<button type="submit" name="cha_backup_action" value="discard" class="button button-link-delete"
					onclick="return confirm('<?php echo esc_js( __( 'Delete the backup permanently? After this the styling upgrade cannot be rolled back from here.', 'cha' ) ); ?>');">
					<?php esc_html_e( 'Discard the backup', 'cha' ); ?>
				</button>
			</form>
			<p class="description">
				<?php esc_html_e( 'With shell access the same two actions are: wp cha category-styles restore | discard (and status to see what is held).', 'cha' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Restore or discard, from the buttons above.
	 */
	public static function handle_backup_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cha' ) );
		}
		check_admin_referer( 'cha_category_backup' );

		$action = isset( $_POST['cha_backup_action'] ) ? sanitize_key( wp_unslash( $_POST['cha_backup_action'] ) ) : '';
		$notice = '';

		if ( 'restore' === $action ) {
			$notice = 'restored-' . CHA_Taxonomy::restore_style_meta_backup();
		} elseif ( 'discard' === $action ) {
			CHA_Taxonomy::discard_style_meta_backup();
			$notice = 'discarded';
		}

		wp_safe_redirect(
			add_query_arg(
				'cha_backup_notice',
				$notice,
				admin_url( 'edit-tags.php?taxonomy=' . self::TAXONOMY . '&post_type=site' )
			)
		);
		exit;
	}

	/**
	 * `wp cha category-styles <status|restore|discard>`.
	 *
	 * The shell path to the same rollback, for anyone who has SSH on the
	 * hosting account and would rather not click.
	 *
	 * @param array $args Positional arguments.
	 */
	public static function cli( $args ) {
		$sub    = isset( $args[0] ) ? $args[0] : 'status';
		$backup = get_option( CHA_Taxonomy::STYLE_BACKUP_OPTION );
		$rows   = ( is_array( $backup ) && ! empty( $backup['rows'] ) ) ? count( $backup['rows'] ) : 0;

		if ( 'restore' === $sub ) {
			if ( ! $rows ) {
				WP_CLI::error( 'No category style backup to restore.' );
			}
			WP_CLI::success( sprintf( 'Restored the old ac/dot/icon values on %d sites.', CHA_Taxonomy::restore_style_meta_backup() ) );
			return;
		}

		if ( 'discard' === $sub ) {
			CHA_Taxonomy::discard_style_meta_backup();
			WP_CLI::success( 'Category style backup discarded. The styling upgrade can no longer be rolled back.' );
			return;
		}

		if ( ! $rows ) {
			WP_CLI::log( 'No category style backup is held.' );
			return;
		}

		WP_CLI::log( sprintf( 'Backup taken %s, holding the old ac/dot/icon values for %d sites.', isset( $backup['created'] ) ? $backup['created'] : '(unknown date)', $rows ) );
		WP_CLI::log( 'Kept indefinitely; removed only by `wp cha category-styles discard` or the Discard button on Heritage Categories.' );
	}

	/* ---- uncategorised sites ------------------------------------------- */

	/**
	 * Count sites with no heritage_category term.
	 *
	 * @return int
	 */
	public static function uncategorised_count() {
		$ids = get_posts(
			array(
				'post_type'      => 'site',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- a few hundred sites at most.
					array(
						'taxonomy' => self::TAXONOMY,
						'operator' => 'NOT EXISTS',
					),
				),
			)
		);

		return count( $ids );
	}

	/**
	 * Add "Uncategorised (n)" beside All / Published / Draft.
	 *
	 * @param array $views Existing views.
	 * @return array
	 */
	public static function add_uncategorised_view( $views ) {
		$count = self::uncategorised_count();
		if ( 0 === $count ) {
			return $views;
		}

		$current = ! empty( $_GET[ self::QUERY_VAR ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.

		$views['cha_uncategorised'] = sprintf(
			'<a href="%1$s"%2$s style="color:#b91c1c">%3$s <span class="count">(%4$d)</span></a>',
			esc_url( admin_url( 'edit.php?post_type=site&' . self::QUERY_VAR . '=1' ) ),
			$current ? ' class="current"' : '',
			esc_html__( 'Uncategorised', 'cha' ),
			$count
		);

		return $views;
	}

	/**
	 * Apply the filter when that link is followed.
	 *
	 * @param WP_Query $query Current query.
	 */
	public static function filter_uncategorised( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'site' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
			return;
		}

		$query->set(
			'tax_query',
			array(
				array(
					'taxonomy' => self::TAXONOMY,
					'operator' => 'NOT EXISTS',
				),
			)
		);
	}
}
