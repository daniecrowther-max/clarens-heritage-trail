<?php
/**
 * Importer — reads the interns' capture spreadsheet (their template,
 * their columns, unmodified) and creates/updates `site` posts keyed by
 * Site ID. Idempotent: re-import updates, never duplicates.
 *
 * Human-supplied fields are only the content itself; everything else is
 * auto-filled (radius default, ac/dot/icon from category, photo URL from
 * the filename + base URL).
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Importer {

	const SHEET     = 'Site Content';
	const PAGE_SLUG = 'cha-import';

	/**
	 * Needle (normalized, matched by "contains") → internal key.
	 * Header-driven so the interns reordering columns never breaks the map.
	 * Order matters: "photo credit" must match before a bare "sources".
	 *
	 * @var array
	 */
	const COLUMNS = array(
		'site id'                    => 'id',
		'site name'                  => 'name',
		'category'                   => 'cat',
		'year'                       => 'year',
		'street address'             => 'address',
		'gps latitude'               => 'lat',
		'gps longitude'              => 'lng',
		'short summary'              => 'summary',
		'full history'               => 'history',
		'key facts'                  => 'facts',
		'blue plaque text'           => 'plaque',
		'primary photo filename'     => 'photo',
		'additional photo filenames' => 'photos_extra',
		'photo credit'               => 'photo_credit',
		'sources'                    => 'sources',
		'captured by'                => 'captured_by',
		'free/paid'                  => 'free',
		'notes'                      => 'notes',
	);

	/**
	 * Hook the admin page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
	}

	/**
	 * Import screen under the Heritage Sites menu.
	 */
	public static function add_page() {
		add_submenu_page(
			'edit.php?post_type=site',
			__( 'Import Sites', 'cha' ),
			__( 'Import', 'cha' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the form and, on POST, run the import and show the report.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to import sites.', 'cha' ) );
		}

		$report = null;
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && check_admin_referer( 'cha_import' ) ) {
			if ( isset( $_POST['cha_photo_base'] ) ) {
				update_option( 'cha_photo_base_url', esc_url_raw( wp_unslash( $_POST['cha_photo_base'] ) ) );
			}
			$report = self::handle_upload( ! empty( $_POST['cha_draft'] ) );
		}

		$photo_base = get_option( 'cha_photo_base_url', '' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Heritage Sites', 'cha' ); ?></h1>
			<p><?php esc_html_e( 'Upload the capture spreadsheet (.xlsx, "Site Content" tab — or a .csv export of it). Rows are matched by Site ID: existing sites are updated, new ones created. Re-importing is safe.', 'cha' ); ?></p>

			<?php if ( is_wp_error( $report ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $report->get_error_message() ); ?></p></div>
			<?php elseif ( is_array( $report ) ) : ?>
				<?php self::render_report( $report ); ?>
			<?php endif; ?>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'cha_import' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cha_file"><?php esc_html_e( 'Spreadsheet', 'cha' ); ?></label></th>
						<td><input type="file" name="cha_file" id="cha_file" accept=".xlsx,.csv" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cha_photo_base"><?php esc_html_e( 'Photo base URL', 'cha' ); ?></label></th>
						<td>
							<input type="url" name="cha_photo_base" id="cha_photo_base" class="regular-text" value="<?php echo esc_attr( $photo_base ); ?>" placeholder="https://example.com/photos/" />
							<p class="description"><?php esc_html_e( 'Where the optimised .webp photos are hosted. Filenames from the sheet are appended with the extension swapped to .webp. Leave blank to skip photo URLs (the app derives a fallback from the Site ID).', 'cha' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'cha' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="cha_draft" value="1" />
								<?php esc_html_e( 'Import new sites as drafts (kept out of the app feed until published)', 'cha' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Import', 'cha' ) ); ?>
			</form>
		</div>
		<?php
	}

	/* ---- upload + parse ---------------------------------------------- */

	/**
	 * Validate the upload, parse rows, run the import.
	 *
	 * @param bool $as_draft Create new posts as drafts.
	 * @return array|WP_Error Report.
	 */
	private static function handle_upload( $as_draft ) {
		if ( empty( $_FILES['cha_file'] ) || UPLOAD_ERR_OK !== $_FILES['cha_file']['error'] ) {
			return new WP_Error( 'cha_upload', __( 'No file uploaded, or the upload failed.', 'cha' ) );
		}

		$name = sanitize_file_name( $_FILES['cha_file']['name'] );
		$tmp  = $_FILES['cha_file']['tmp_name'];
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );

		if ( 'xlsx' === $ext ) {
			$rows = CHA_Xlsx_Reader::read( $tmp, self::SHEET );
		} elseif ( 'csv' === $ext ) {
			$rows = self::read_csv( $tmp );
		} else {
			return new WP_Error( 'cha_upload', __( 'Please upload a .xlsx or .csv file.', 'cha' ) );
		}

		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		return self::import_rows( $rows, $as_draft ? 'draft' : 'publish' );
	}

	/**
	 * A .csv export of the Site Content tab.
	 *
	 * @param string $path File path.
	 * @return array[]|WP_Error
	 */
	private static function read_csv( $path ) {
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'cha_upload', __( 'Could not read the .csv file.', 'cha' ) );
		}
		$rows  = array();
		$first = true;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( $first ) {
				// Strip a UTF-8 BOM from the first cell.
				$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
				$first  = false;
			}
			$rows[] = array_map( 'trim', array_map( 'strval', $row ) );
		}
		fclose( $handle );
		return $rows;
	}

	/* ---- import ------------------------------------------------------ */

	/**
	 * Import all data rows.
	 *
	 * @param array[] $rows       Sheet rows.
	 * @param string  $new_status Post status for newly created sites.
	 * @return array|WP_Error Report.
	 */
	private static function import_rows( $rows, $new_status ) {
		$map = null;
		$report = array(
			'created' => 0,
			'updated' => 0,
			'skipped' => 0,
			'rows'    => array(),
		);

		foreach ( $rows as $i => $row ) {
			// Locate the header row (the one containing "Site ID").
			if ( null === $map ) {
				$candidate = self::map_columns( $row );
				if ( null !== $candidate ) {
					$map = $candidate;
				}
				continue;
			}

			$data = array();
			foreach ( $map as $col => $key ) {
				$data[ $key ] = isset( $row[ $col ] ) ? trim( (string) $row[ $col ] ) : '';
			}

			$result = self::import_row( $data, $new_status );
			if ( null === $result ) {
				continue; // Entirely blank row.
			}

			$result['row'] = $i + 1; // 1-based, matches the spreadsheet.
			$report[ $result['action'] ]++;
			$report['rows'][] = $result;
		}

		if ( null === $map ) {
			return new WP_Error( 'cha_no_header', __( 'Could not find the header row (looked for a "Site ID" column). Is this the capture template?', 'cha' ) );
		}

		return $report;
	}

	/**
	 * Match a row's cells against the known headers.
	 *
	 * @param array $row Candidate header row.
	 * @return array|null Column index → internal key, or null if not the header row.
	 */
	private static function map_columns( $row ) {
		$map      = array();
		$map_keys = array();
		foreach ( $row as $col => $cell ) {
			$header = self::normalize( $cell );
			if ( '' === $header ) {
				continue;
			}
			foreach ( self::COLUMNS as $needle => $key ) {
				if ( ! isset( $map_keys[ $key ] ) && false !== strpos( $header, $needle ) ) {
					$map[ $col ]      = $key;
					$map_keys[ $key ] = true;
					break;
				}
			}
		}
		return isset( $map_keys['id'] ) ? $map : null;
	}

	/**
	 * Lower-case, strip the ★ marker, collapse whitespace.
	 *
	 * @param string $text Header cell.
	 * @return string
	 */
	private static function normalize( $text ) {
		$text = str_replace( '★', '', (string) $text );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Import one data row.
	 *
	 * @param array  $data       Internal key → cell value.
	 * @param string $new_status Post status for new sites.
	 * @return array|null Row result, or null for a blank row.
	 */
	private static function import_row( $data, $new_status ) {
		$id_raw = $data['id'];
		$name   = $data['name'];

		if ( '' === $id_raw && '' === $name ) {
			return null;
		}

		// The worked example row ships in the template — never import it.
		if ( false !== stripos( $id_raw, 'example' ) || false !== stripos( $name, 'example' ) || 0 === stripos( $id_raw, 'GR-000' ) ) {
			return array(
				'action'   => 'skipped',
				'id'       => $id_raw,
				'warnings' => array( __( 'Example row.', 'cha' ) ),
				'errors'   => array(),
			);
		}

		if ( '' === $id_raw || '' === $name ) {
			return array(
				'action'   => 'skipped',
				'id'       => '' !== $id_raw ? $id_raw : $name,
				'warnings' => array( __( 'Missing Site ID or Site Name.', 'cha' ) ),
				'errors'   => array(),
			);
		}

		$warnings = array();
		$errors   = array();
		// Normalised the same way post_name has always been derived from the
		// Site ID cell, so a re-import matches/produces the exact same feed
		// `id` a site had before site_id existed (see CHA_Meta::string()
		// registration + the migrate_site_id_from_slug() backfill).
		$site_id = sanitize_title( $id_raw );

		// Idempotency: same Site ID → same post, matched by the `site_id`
		// meta (NOT post_name/slug — the permalink is the web editor's to edit
		// freely). Update, never duplicate.
		$existing = get_posts(
			array(
				'post_type'      => 'site',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => 'site_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small dataset (hundreds of sites), matches the existing importer meta-lookup pattern.
				'meta_value'     => $site_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( $existing ) {
			$post_id = $existing[0]->ID;
			// Title only — post_name (the permalink slug) is never touched by
			// the importer, so an edited permalink survives re-imports.
			wp_update_post(
				array(
					'ID'         => $post_id,
					'post_title' => $name,
				)
			);
			$action = 'updated';
		} else {
			// No post_name here — WordPress derives its own slug from
			// post_title as normal. site_id (the app's real identity) is set
			// just below, once the post exists.
			$post_id = wp_insert_post(
				array(
					'post_type'   => 'site',
					'post_title'  => $name,
					'post_status' => $new_status,
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return array(
					'action'   => 'skipped',
					'id'       => $id_raw,
					'warnings' => array( $post_id->get_error_message() ),
					'errors'   => array(),
				);
			}
			update_post_meta( $post_id, 'site_id', $site_id );
			$action = 'created';
		}

		// Category → taxonomy term (+ the bp subset flag).
		$match = self::match_category( $data['cat'] );
		if ( null !== $match ) {
			wp_set_object_terms( $post_id, array( (int) $match['term_id'] ), 'heritage_category', false );

			// A substring hit is a guess, not a match, and saying so is the
			// point: silent substring matching is how a vocabulary drift stays
			// hidden. "Heritage" alone would otherwise land on whichever
			// heritage-named term sorts first without a word.
			if ( 'fuzzy' === $match['how'] ) {
				$errors[] = sprintf(
					/* translators: 1: the spreadsheet value, 2: the term it was matched to */
					__( 'Category "%1$s" only partly matched — imported as "%2$s". Check this is right.', 'cha' ),
					$data['cat'],
					$match['name']
				);
			}
		} elseif ( '' !== $data['cat'] ) {
			$errors[] = sprintf(
				/* translators: %s: the unrecognised category value */
				__( 'No category matches "%s" — this site imported UNCATEGORISED. Add the category under Heritage Categories, then re-import.', 'cha' ),
				$data['cat']
			);
		} else {
			$errors[] = __( 'Category is empty — this site imported UNCATEGORISED.', 'cha' );
		}

		// bp marks the physical Blue Plaque / QR subset. It is deliberately still
		// settable on its own (the meta box checkbox); the category is a
		// convenience, not the rule.
		$is_plaque_term = null !== $match && CHA_Taxonomy::PLAQUE_SLUG === $match['slug'];
		update_post_meta( $post_id, 'bp', $is_plaque_term || '' !== $data['plaque'] );

		// GPS.
		foreach ( array( 'lat', 'lng' ) as $coord ) {
			if ( is_numeric( $data[ $coord ] ) ) {
				update_post_meta( $post_id, $coord, (float) $data[ $coord ] );
			} else {
				/* translators: 1: lat/lng, 2: the cell value */
				$warnings[] = sprintf( __( 'Bad or missing %1$s ("%2$s") — site cannot be mapped or stamped until fixed.', 'cha' ), $coord, $data[ $coord ] );
			}
		}

		// Content fields.
		update_post_meta( $post_id, 'address', $data['address'] );
		update_post_meta( $post_id, 'facts', self::build_facts( $data['year'], $data['facts'] ) );
		$story = self::build_story( $data['summary'], $data['history'] );
		update_post_meta( $post_id, 'story', $story );
		CHA_Meta::sync_excerpt_from_story( $post_id, $story );
		update_post_meta( $post_id, 'plaqueText', $data['plaque'] );

		// Office Use — Free/Paid: only written when the Society filled it in.
		if ( '' !== $data['free'] ) {
			update_post_meta( $post_id, 'free', false !== stripos( $data['free'], 'free' ) );
		}

		// Photo URL from the filename + base URL (extension → .webp).
		$photo = self::photo_url( $data['photo'] );
		if ( '' !== $photo ) {
			update_post_meta( $post_id, 'photo', $photo );
		} elseif ( '' === $data['photo'] ) {
			$warnings[] = __( 'No primary photo filename — the app will derive a placeholder from the Site ID.', 'cha' );
		}

		// icon: the curated per-site glyph where we have one, written only when
		// empty so a manual tweak survives re-imports. A site without one shows
		// its category's glyph (resolved at feed-build time — nothing is written).
		// ac/dot are no longer written: colour comes from the category.
		// radius flows from the meta default (30).
		self::fill_icon_default( $post_id, $site_id );

		// Provenance — kept on the post, never sent to the app feed.
		foreach ( array(
			'photos_extra' => '_cha_photos_extra',
			'photo_credit' => '_cha_photo_credit',
			'sources'      => '_cha_sources',
			'captured_by'  => '_cha_captured_by',
			'notes'        => '_cha_notes',
		) as $key => $meta_key ) {
			if ( '' !== $data[ $key ] ) {
				update_post_meta( $post_id, $meta_key, sanitize_textarea_field( $data[ $key ] ) );
			}
		}

		if ( '' === $data['sources'] ) {
			$warnings[] = __( 'No sources/references recorded.', 'cha' );
		}

		return array(
			'action'   => $action,
			'id'       => $id_raw,
			'warnings' => $warnings,
			'errors'   => $errors,
		);
	}

	/* ---- field builders ---------------------------------------------- */

	/**
	 * Spreadsheet category cell → a REGISTERED heritage_category term.
	 *
	 * Matches the terms that actually exist rather than a hardcoded list, so
	 * adding a category in the admin is the only step needed for it to import.
	 * Three passes, narrowest first:
	 *
	 *   1. exact      — the cell equals the term name or slug, ignoring case,
	 *                   punctuation and spacing.
	 *   2. normalised — singular/plural differences only ("Blue Plaque Sites"
	 *                   → "Blue Plaque Site").
	 *   3. fuzzy      — the cell contains the term name, or vice versa.
	 *
	 * Fuzzy is LAST and is reported to the admin. Permissive silent substring
	 * matching is exactly how a vocabulary drift stays hidden. Longer terms are
	 * tried first within that pass so a value cannot be captured by a shorter
	 * term that happens to appear inside it ("Heritage" is inside all four
	 * Clarens terms).
	 *
	 * @param string $value Cell value.
	 * @return array|null [ term_id, name, slug, how ], or null for no match.
	 */
	private static function match_category( $value ) {
		$needle = self::normalize( $value );
		if ( '' === $needle ) {
			return null;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'heritage_category',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return null;
		}

		$found = static function ( $term, $how ) {
			return array(
				'term_id' => (int) $term->term_id,
				'name'    => $term->name,
				'slug'    => $term->slug,
				'how'     => $how,
			);
		};

		$flat = static function ( $text ) {
			return preg_replace( '/[^a-z0-9]/', '', self::normalize( $text ) );
		};

		/**
		 * An explicitly configured needle → term-name map.
		 *
		 * @deprecated 0.2.0 The registered terms are now the vocabulary, so
		 *             this is only needed for a mapping the three matching
		 *             passes below cannot express. Honoured so an existing
		 *             override does not silently stop working.
		 *
		 * @param array $map Needle => term name. Empty by default.
		 */
		$legacy = apply_filters( 'cha_import_category_map', array() );
		foreach ( (array) $legacy as $legacy_needle => $term_name ) {
			if ( '' === (string) $legacy_needle || false === strpos( $needle, self::normalize( $legacy_needle ) ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				if ( self::normalize( $term->name ) === self::normalize( $term_name ) ) {
					return $found( $term, 'configured' );
				}
			}
		}

		// 1. Exact, on name or slug.
		foreach ( $terms as $term ) {
			if ( self::normalize( $term->name ) === $needle || self::normalize( $term->slug ) === $needle ) {
				return $found( $term, 'exact' );
			}
		}

		// 2. Normalised — punctuation stripped, and a trailing plural 's'
		//    treated as noise in either direction.
		$bare   = $flat( $value );
		$plural = array( $bare, rtrim( $bare, 's' ), $bare . 's' );
		foreach ( $terms as $term ) {
			$term_bare = $flat( $term->name );
			if ( in_array( $term_bare, $plural, true ) || rtrim( $term_bare, 's' ) === rtrim( $bare, 's' ) ) {
				return $found( $term, 'normalised' );
			}
		}

		// 3. Fuzzy, longest term name first.
		$sorted = $terms;
		usort(
			$sorted,
			static function ( $a, $b ) {
				return strlen( $b->name ) - strlen( $a->name );
			}
		);
		foreach ( $sorted as $term ) {
			$term_needle = self::normalize( $term->name );
			if ( '' !== $term_needle
				&& ( false !== strpos( $needle, $term_needle ) || false !== strpos( $term_needle, $needle ) ) ) {
				return $found( $term, 'fuzzy' );
			}
		}

		return null;
	}

	/**
	 * Year/Date + the "label: value; label: value" Key Facts cell → repeater.
	 *
	 * @param string $year  Year / Date cell.
	 * @param string $facts Key Facts cell.
	 * @return array
	 */
	private static function build_facts( $year, $facts ) {
		$rows = array();
		if ( '' !== $year ) {
			$rows[] = array(
				'l' => __( 'Year', 'cha' ),
				'v' => $year,
			);
		}
		foreach ( array_filter( array_map( 'trim', explode( ';', $facts ) ) ) as $pair ) {
			$parts  = explode( ':', $pair, 2 );
			$rows[] = array(
				'l' => count( $parts ) === 2 ? trim( $parts[0] ) : '',
				'v' => trim( end( $parts ) ),
			);
		}
		return $rows;
	}

	/**
	 * Short Summary (the card teaser, per the locked seam: story[0]) +
	 * Full History paragraphs.
	 *
	 * @param string $summary Short Summary cell.
	 * @param string $history Full History cell.
	 * @return string[]
	 */
	private static function build_story( $summary, $history ) {
		$paragraphs = array();
		if ( '' !== $summary ) {
			$paragraphs[] = $summary;
		}
		foreach ( preg_split( '/\R+/u', $history ) as $para ) {
			$para = trim( $para );
			if ( '' !== $para ) {
				$paragraphs[] = $para;
			}
		}
		return $paragraphs;
	}

	/**
	 * Photo base URL + sheet filename, extension swapped to .webp
	 * (per the external photo pipeline).
	 *
	 * @param string $filename Primary Photo Filename cell.
	 * @return string URL, or '' when not derivable.
	 */
	private static function photo_url( $filename ) {
		$base = apply_filters( 'cha_import_photo_base', get_option( 'cha_photo_base_url', '' ) );
		if ( '' === $base || '' === $filename ) {
			return '';
		}
		$webp = preg_replace( '/\.[a-z0-9]+$/i', '.webp', $filename );
		return trailingslashit( $base ) . rawurlencode( $webp );
	}

	/**
	 * Curated per-site glyph — written only when empty, so a manual tweak
	 * survives re-imports.
	 *
	 * In Clarens's real data the glyph is never derived from category — every
	 * one of the 31 original sites has its own hand-picked icon, uncorrelated
	 * with its `cat` (two 'Heritage Site' sites can be a house and a school).
	 * So the per-site table below (taken verbatim from clarens-heritage-trail
	 * repo commit a8627de — the same source scripts/migrate-site-content.js
	 * used) is the one thing still written per site. A site that is not in it
	 * gets nothing written: the feed resolves its category's glyph instead
	 * (CHA_Taxonomy::category_definitions()), so a restyle never needs a
	 * re-import. The old per-site `ac`/`dot` colour meta is gone — colour is
	 * the category's.
	 *
	 * @param int    $post_id Site post ID.
	 * @param string $site_id Site ID (matches the `id` used by the app).
	 */
	private static function fill_icon_default( $post_id, $site_id ) {
		$per_site = apply_filters( 'cha_site_icons', self::site_icon_defaults() );
		if ( ! isset( $per_site[ $site_id ] ) ) {
			return;
		}
		if ( '' === (string) get_post_meta( $post_id, 'icon', true ) ) {
			update_post_meta( $post_id, 'icon', $per_site[ $site_id ] );
		}
	}

	/**
	 * Per-site glyphs (HTML numeric character references, decoded by the
	 * feed), taken verbatim from Clarens's real site data.
	 *
	 * @return array<string,string> site_id => icon.
	 */
	private static function site_icon_defaults() {
		return array(
			'supply-store' => '&#127978;',
			'firkin' => '&#127866;',
			'president-square' => '&#127963;',
			'die-spens' => '&#127860;',
			'old-library' => '&#128218;',
			'ou-slaghuis' => '&#127830;',
			'clementines' => '&#127869;',
			'railway-building' => '&#128649;',
			'bibliophile' => '&#128218;',
			'frost-house' => '&#127968;',
			'fischer-house' => '&#127981;',
			'posthouse' => '&#9993;',
			'ng-kerk' => '&#9962;',
			'pastorie' => '&#127968;',
			'ou-kliphuis' => '&#129704;',
			'kruger-gedenksaal' => '&#127968;',
			'methodist-church' => '&#9962;',
			'primary-school' => '&#127979;',
			'leliehoek' => '&#127968;',
			'sutherlands-cottage' => '&#127968;',
			'berg-429' => '&#127968;',
			'berg-cottage' => '&#127968;',
			'blacksmith-cottage' => '&#128296;',
			'ou-werf' => '&#127968;',
			'short-street-438' => '&#127968;',
			'maluti-lodge' => '&#127960;',
			'titanic-rock' => '&#129704;',
			'schaapplaats' => '&#127775;',
			'surrender-hill' => '&#127988;',
			'basotho-village' => '&#127968;',
			'dinosaur-centre' => '&#129430;',
		);
	}

	/* ---- report ------------------------------------------------------ */

	/**
	 * Post-import summary table.
	 *
	 * @param array $report Import report.
	 */
	private static function render_report( $report ) {
		?>
		<div class="notice notice-success">
			<p>
				<strong><?php esc_html_e( 'Import complete.', 'cha' ); ?></strong>
				<?php
				printf(
					/* translators: 1-3: counts */
					esc_html__( 'Created %1$d, updated %2$d, skipped %3$d.', 'cha' ),
					(int) $report['created'],
					(int) $report['updated'],
					(int) $report['skipped']
				);
				?>
			</p>
			<?php
			$error_rows = 0;
			foreach ( $report['rows'] as $row ) {
				if ( ! empty( $row['errors'] ) ) {
					++$error_rows;
				}
			}
			if ( $error_rows ) :
				?>
				<p style="color:#b91c1c;font-weight:600">
					<?php
					printf(
						/* translators: %d: number of rows with an error */
						esc_html(
							_n(
								'%d row needs attention — see the red notes below. Uncategorised sites are also listed on the Heritage Sites screen.',
								'%d rows need attention — see the red notes below. Uncategorised sites are also listed on the Heritage Sites screen.',
								$error_rows,
								'cha'
							)
						),
						(int) $error_rows
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<table class="widefat striped" style="max-width:900px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Row', 'cha' ); ?></th>
					<th><?php esc_html_e( 'Site ID', 'cha' ); ?></th>
					<th><?php esc_html_e( 'Action', 'cha' ); ?></th>
					<th><?php esc_html_e( 'Notes', 'cha' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $report['rows'] as $row ) : ?>
					<tr>
						<td><?php echo (int) $row['row']; ?></td>
						<td><?php echo esc_html( $row['id'] ); ?></td>
						<td><?php echo esc_html( $row['action'] ); ?></td>
						<td>
							<?php if ( ! empty( $row['errors'] ) ) : ?>
								<div style="color:#b91c1c;font-weight:600">
									&#10007; <?php echo esc_html( implode( ' ', $row['errors'] ) ); ?>
								</div>
							<?php endif; ?>
							<?php echo esc_html( implode( ' ', $row['warnings'] ) ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
