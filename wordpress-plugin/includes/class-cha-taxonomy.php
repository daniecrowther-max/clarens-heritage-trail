<?php
/**
 * Site taxonomies, and the category model.
 *
 *  - `heritage_category` — the app's `cat` field, shared with the website menu.
 *    Seeded with four starting terms, but NOT a closed vocabulary: the terms
 *    themselves are the single source of truth, and adding one in the admin is
 *    the only step needed for it to import and render.
 *  - `heritage_trail` — the app's `trail` field (trail grouping). Free-growing:
 *    NOT seeded; created via WordPress's native taxonomy UI.
 *
 * Each category carries its own styling as term meta — a colour and a glyph —
 * so the app can render a category it has never seen before. This replaces the
 * per-site `ac`/`dot` style meta the importer used to write from a hardcoded
 * per-category map (and the app's matching hardcoded badge strings), which
 * could only ever drift apart.
 *
 * Colour assignment is scarcity-based and PERSISTED, never hashed. Hashing a
 * slug into seven slots collides 65% of the time at four terms and 96% at six —
 * two categories, identical colour, no warning, which is precisely the silent
 * degradation this model exists to remove. Taking the lowest unused slot and
 * writing it to term meta makes a collision impossible below the eighth
 * category, and means adding or deleting a term never restyles the others.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Taxonomy {

	/**
	 * Starting terms, seeded on activation — Clarens's four real categories
	 * (22 of the 31 original sites are the generic 'Heritage Site'). NOT a
	 * closed list: the editor adds more through Heritage Sites → Heritage
	 * Categories, and everything downstream follows the terms rather than
	 * this array. This is the only place a category name appears in code.
	 *
	 * @var string[]
	 */
	const TERMS = array(
		'Heritage Site',
		'Blue Plaque Site',
		'Cultural Heritage',
		'Natural Heritage',
	);

	/** Term meta: the category's badge/accent colour, and its glyph. */
	const META_COLOUR = 'cha_cat_colour';
	const META_ICON   = 'cha_cat_icon';

	/** Slug that owns the Blue Plaque designation blue. */
	const PLAQUE_SLUG = 'blue-plaque-site';

	/**
	 * Glyphs the seeded terms start with, keyed by slug. Written once, only
	 * where a term has no glyph of its own (see seed_glyphs()), so an edit in
	 * the admin is never overwritten. These are the same four glyphs the
	 * importer's old per-category fallback wrote, so nothing changes visibly.
	 *
	 * @var array<string,string>
	 */
	const SEED_ICONS = array(
		'heritage-site'     => '🏛️',
		'blue-plaque-site'  => '🔵',
		'cultural-heritage' => '🎭',
		'natural-heritage'  => '🌿',
	);

	/**
	 * The category palette: background + text pairs, all from the Clarens
	 * brand tokens in app/index.html, all verified at or above WCAG AA 4.5:1
	 * for the badge's own text colour (see tests/test-category-model.php).
	 *
	 * The first slot is reserved for the Blue Plaque Site term and matched BY
	 * SLUG, not by name — a fork that renames the term must not silently lose
	 * the designation blue, which refers to the physical plaque rather than to
	 * anything decorative.
	 *
	 * --cha-red is deliberately absent: it is the error/attention colour.
	 * Ochre and stone carry charcoal text because white on them fails
	 * (2.44:1 and 1.55:1).
	 *
	 * @var array[]
	 */
	const PALETTE = array(
		array( 'colour' => '#1a4a7a', 'text' => '#FFFFFF' ), // plaque blue      9.11:1
		array( 'colour' => '#4E5530', 'text' => '#FFFFFF' ), // olive (green)    7.88:1
		array( 'colour' => '#c8a052', 'text' => '#1e2318' ), // ochre (gold)     6.58:1
		array( 'colour' => '#606e42', 'text' => '#FFFFFF' ), // olive mid        5.52:1
		array( 'colour' => '#8a5c2e', 'text' => '#FFFFFF' ), // cultural brown   5.75:1
		array( 'colour' => '#2e3a1f', 'text' => '#FFFFFF' ), // olive dark      12.06:1
		array( 'colour' => '#c8d4b8', 'text' => '#1e2318' ), // stone           10.36:1
	);

	/** Rendering for a site whose category is missing or unknown. */
	const UNCATEGORISED = array(
		'name'   => 'Uncategorised',
		'colour' => '#8C8C8C',
		'text'   => '#FFFFFF',
		'icon'   => '❓',
	);

	/** Default glyph for a term that has not been given one. */
	const DEFAULT_ICON = '🏷️';

	/**
	 * Per-request memo for category_definitions(). A class property rather than
	 * a static local so term edits and the test harness can actually clear it.
	 *
	 * @var array|null
	 */
	protected static $definitions_memo = null;

	/**
	 * Register both site taxonomies. Hooked to `init`.
	 */
	public static function register() {
		register_taxonomy(
			'heritage_category',
			array( 'site' ),
			array(
				'labels'       => array(
					'name'          => __( 'Heritage Categories', 'cha' ),
					'singular_name' => __( 'Heritage Category', 'cha' ),
					'search_items'  => __( 'Search Heritage Categories', 'cha' ),
					'all_items'     => __( 'All Heritage Categories', 'cha' ),
					'edit_item'     => __( 'Edit Heritage Category', 'cha' ),
					'add_new_item'  => __( 'Add New Heritage Category', 'cha' ),
				),
				'public'       => true,
				'hierarchical' => true,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'heritage-category' ),
			)
		);

		// Trail grouping — flat (trails aren't nested) and NOT seeded; the
		// content editor creates/reuses trails through WordPress's native
		// Add-New-Trail box.
		register_taxonomy(
			'heritage_trail',
			array( 'site' ),
			array(
				'labels'       => array(
					'name'          => __( 'Trails', 'cha' ),
					'singular_name' => __( 'Trail', 'cha' ),
					'search_items'  => __( 'Search Trails', 'cha' ),
					'all_items'     => __( 'All Trails', 'cha' ),
					'edit_item'     => __( 'Edit Trail', 'cha' ),
					'add_new_item'  => __( 'Add New Trail', 'cha' ),
				),
				'public'       => true,
				'hierarchical' => false,
				'show_in_rest' => true,
				'rewrite'      => array( 'slug' => 'heritage-trail' ),
			)
		);
	}

	/**
	 * Seed the four frozen terms. Runs on activation; wp_insert_term is a
	 * no-op (returns WP_Error) for terms that already exist, so re-activation
	 * never duplicates.
	 */
	public static function seed_terms() {
		foreach ( self::TERMS as $term ) {
			if ( ! term_exists( $term, 'heritage_category' ) ) {
				wp_insert_term( $term, 'heritage_category' );
			}
		}
		self::seed_glyphs();
		self::persist_all_colours();
	}

	/**
	 * Give each seeded term its starting glyph, only where it has none.
	 * Idempotent, and never overwrites a glyph set in the admin.
	 *
	 * @return int Terms written.
	 */
	public static function seed_glyphs() {
		$written = 0;
		foreach ( self::SEED_ICONS as $slug => $glyph ) {
			$term = get_term_by( 'slug', $slug, 'heritage_category' );
			if ( ! $term || is_wp_error( $term ) ) {
				continue;
			}
			if ( '' !== (string) get_term_meta( $term->term_id, self::META_ICON, true ) ) {
				continue;
			}
			update_term_meta( $term->term_id, self::META_ICON, $glyph );
			++$written;
		}
		if ( $written ) {
			self::flush_definitions();
		}
		return $written;
	}

	/**
	 * Write down the colour of every category that has not got one stored.
	 *
	 * The assignment is only stable if it is persisted. Without this a term
	 * seeded at activation keeps recomputing its slot, so adding or recolouring
	 * another category can silently move it — the exact reshuffle the scarcity
	 * design exists to prevent. Cheap: a handful of terms, each written once.
	 */
	public static function persist_all_colours() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'heritage_category',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}
		foreach ( $terms as $term ) {
			self::persist_assigned_colour( $term->term_id );
		}
	}

	/**
	 * One-time migration: convert any legacy `trail` postmeta into
	 * heritage_trail term assignments, then delete the meta so nothing is lost
	 * when `trail` stops being a meta field. Idempotent — it only touches posts
	 * that still carry the meta — so it is safe to run on every activation and
	 * once via the guarded admin_init hook. Remove after it has run in prod.
	 *
	 * wp_set_object_terms accepts a term NAME for a non-hierarchical taxonomy
	 * and creates the term if it does not exist yet.
	 *
	 * @return int Number of posts given a trail term.
	 */
	public static function migrate_trail_meta_to_taxonomy() {
		$post_ids = get_posts(
			array(
				'post_type'      => 'site',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => 'trail', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time migration.
			)
		);

		$migrated = 0;
		foreach ( $post_ids as $post_id ) {
			$value = get_post_meta( $post_id, 'trail', true );
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' !== $value ) {
				wp_set_object_terms( $post_id, $value, 'heritage_trail', false );
				++$migrated;
			}
			delete_post_meta( $post_id, 'trail' );
		}
		return $migrated;
	}

	/**
	 * The Clarens trail layout: trail slug → site_id list, in walking order.
	 * Taken from the original Clarens app (clarens-heritage-trail repo, commit
	 * a8627de), and independently corroborated by each site's `_cha_notes`
	 * meta, which the importer stamped with e.g. "trail=town #13".
	 *
	 * The array order here IS the walking order — position in the list becomes
	 * the site's trailNum (1-based), so there is no second list of numbers to
	 * keep in step with this one.
	 *
	 * @var array<string, string[]>
	 */
	const TRAIL_LAYOUT = array(
		'clarens-town'      => array(
			'supply-store',
			'firkin',
			'president-square',
			'die-spens',
			'old-library',
			'ou-slaghuis',
			'clementines',
			'railway-building',
			'bibliophile',
			'frost-house',
			'fischer-house',
			'posthouse',
			'ng-kerk',
			'pastorie',
			'ou-kliphuis',
			'kruger-gedenksaal',
			'methodist-church',
			'primary-school',
			'leliehoek',
		),
		'swartland'         => array(
			'sutherlands-cottage',
			'berg-429',
			'berg-cottage',
			'blacksmith-cottage',
			'ou-werf',
			'short-street-438',
			'maluti-lodge',
		),
		'clarens-surrounds' => array(
			'titanic-rock',
			'schaapplaats',
			'surrender-hill',
			'basotho-village',
			'dinosaur-centre',
		),
	);

	/**
	 * One-time migration: give every site its heritage_trail term and its
	 * `trailNum` walking position, per TRAIL_LAYOUT.
	 *
	 * Also deletes any `trailnum` (all-lowercase) meta row. WordPress's own
	 * sanitize_key() lowercases a meta key, so tooling that passes keys through
	 * it — the MCP bridge, some REST/CLI paths — writes `trailnum` on a post
	 * that has no `trailNum` row yet. MySQL's case-insensitive collation makes
	 * that invisible in a direct query, but get_post_meta() reads WordPress's
	 * PHP meta cache, which is keyed by the literal stored string — so the
	 * feed's get_post_meta( $id, 'trailNum' ) silently returns ''. Dropping the
	 * bad row before writing the good one keeps exactly one key per post.
	 *
	 * Idempotent: it sets the same values every run, so it is safe on every
	 * activation and once via the guarded admin_init hook.
	 *
	 * @return int Number of sites assigned.
	 */
	public static function migrate_trail_assignments() {
		// site_id → post ID, for every site (site_id is the feed's identity;
		// it is not the post slug, so the layout cannot be matched on slug).
		$post_ids = get_posts(
			array(
				'post_type'      => 'site',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$by_site_id = array();
		foreach ( $post_ids as $post_id ) {
			$site_id = (string) get_post_meta( $post_id, 'site_id', true );
			if ( '' === $site_id ) {
				$site_id = (string) get_post_field( 'post_name', $post_id );
			}
			if ( '' !== $site_id ) {
				$by_site_id[ $site_id ] = $post_id;
			}
		}

		$assigned = 0;
		foreach ( self::TRAIL_LAYOUT as $trail_slug => $site_ids ) {
			$term = get_term_by( 'slug', $trail_slug, 'heritage_trail' );
			if ( ! $term ) {
				continue; // Trail not created on this install — skip, don't invent one.
			}
			foreach ( $site_ids as $index => $site_id ) {
				if ( ! isset( $by_site_id[ $site_id ] ) ) {
					continue; // Site absent on this install (e.g. a white-label fork).
				}
				$post_id = $by_site_id[ $site_id ];
				wp_set_object_terms( $post_id, (int) $term->term_id, 'heritage_trail', false );
				delete_post_meta( $post_id, 'trailnum' );
				update_post_meta( $post_id, 'trailNum', $index + 1 );
				++$assigned;
			}
		}
		return $assigned;
	}

	/* ---- category model ------------------------------------------------ */

	/**
	 * Register the per-term style meta so WordPress sanitises it for us.
	 * Hooked to `init` alongside register().
	 */
	public static function register_term_meta() {
		register_term_meta(
			'heritage_category',
			self::META_COLOUR,
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_hex_color',
				'show_in_rest'      => false,
			)
		);
		register_term_meta(
			'heritage_category',
			self::META_ICON,
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_icon' ),
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * A glyph is a short bit of text, not markup.
	 *
	 * @param mixed $value Raw input.
	 * @return string
	 */
	public static function sanitize_icon( $value ) {
		$value = sanitize_text_field( (string) $value );
		return mb_substr( $value, 0, 8 );
	}

	/**
	 * Every category, keyed by SLUG.
	 *
	 * Slug rather than name because names are editorial and get renamed, while
	 * a slug is stable — so anything that persists a category reference
	 * (analytics, a saved filter, a future QR deep link) has a durable key.
	 *
	 * @return array<string,array> slug => [ name, colour, text, icon ].
	 */
	public static function category_definitions() {
		// Memoised per request: the feed resolves a glyph per site, so this is
		// called once per record.
		if ( null !== self::$definitions_memo ) {
			return self::$definitions_memo;
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'heritage_category',
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			self::$definitions_memo = array();
			return self::$definitions_memo;
		}

		// Pass 1: which palette colours are already spoken for.
		$taken  = array();
		$stored = array();
		foreach ( $terms as $term ) {
			$colour = sanitize_hex_color( (string) get_term_meta( $term->term_id, self::META_COLOUR, true ) );
			if ( $colour ) {
				$stored[ $term->term_id ] = $colour;
				$taken[]                  = strtolower( $colour );
			}
		}

		/**
		 * Per-category ac/dot/icon, keyed by term NAME.
		 *
		 * @deprecated 0.2.0 Superseded by the term meta edited on the Add/Edit
		 *             Heritage Category screen, which a non-developer can reach.
		 *             Still honoured so an existing override does not silently
		 *             stop working. Scheduled for removal in a later release —
		 *             move any use of it to term meta before then.
		 *
		 * @param array $styles Term name => [ ac, dot, icon ]. Empty by default.
		 */
		$legacy = (array) apply_filters( 'cha_category_styles', array() );

		// Pass 2: give anything unstyled the lowest palette slot nobody is
		// using. Deterministic in term_id order, so it does not shuffle.
		$definitions = array();
		foreach ( $terms as $term ) {
			if ( isset( $stored[ $term->term_id ] ) ) {
				$colour = $stored[ $term->term_id ];
			} else {
				$colour  = self::next_free_colour( $term->slug, $taken );
				$taken[] = strtolower( $colour );
			}

			$icon = (string) get_term_meta( $term->term_id, self::META_ICON, true );

			// Back-compat: a fork that styled categories through the old filter
			// keeps working until it migrates to term meta. Term meta wins.
			if ( isset( $legacy[ $term->name ] ) ) {
				if ( '' === $icon && ! empty( $legacy[ $term->name ]['icon'] ) ) {
					$icon = (string) $legacy[ $term->name ]['icon'];
				}
				if ( ! isset( $stored[ $term->term_id ] ) && ! empty( $legacy[ $term->name ]['dot'] ) ) {
					$legacy_colour = sanitize_hex_color( $legacy[ $term->name ]['dot'] );
					if ( $legacy_colour ) {
						$colour = $legacy_colour;
					}
				}
			}

			$definitions[ $term->slug ] = array(
				'name'   => $term->name,
				'colour' => $colour,
				'text'   => self::text_on( $colour ),
				'icon'   => '' !== $icon ? $icon : self::DEFAULT_ICON,
			);
		}

		/**
		 * Filter the category definitions sent to the app.
		 *
		 * @param array $definitions slug => [ name, colour, text, icon ].
		 */
		self::$definitions_memo = apply_filters( 'cha_category_definitions', $definitions );
		return self::$definitions_memo;
	}

	/**
	 * Drop the per-request memo. Called after any term change, and by the tests.
	 */
	public static function flush_definitions() {
		self::$definitions_memo = null;
	}

	/**
	 * Lowest palette slot not already in use.
	 *
	 * The Blue Plaques slug always gets the designation blue if it is still
	 * free — reserved by slug, not by name, so renaming the term in a fork does
	 * not silently lose it.
	 *
	 * Past the seventh category the palette is exhausted and a colour must be
	 * reused. That is a real ceiling, not a silent one: duplicate_colours()
	 * surfaces it on the category screen.
	 *
	 * @param string   $slug  Term slug.
	 * @param string[] $taken Lower-cased colours already assigned.
	 * @return string Hex colour.
	 */
	protected static function next_free_colour( $slug, $taken ) {
		$plaque = self::PALETTE[0]['colour'];
		if ( self::PLAQUE_SLUG === $slug && ! in_array( strtolower( $plaque ), $taken, true ) ) {
			return $plaque;
		}

		foreach ( self::PALETTE as $i => $slot ) {
			// Slot 0 stays reserved for Blue Plaques while that term could
			// still claim it.
			if ( 0 === $i && self::PLAQUE_SLUG !== $slug && term_exists( self::PLAQUE_SLUG, 'heritage_category' ) ) {
				continue;
			}
			if ( ! in_array( strtolower( $slot['colour'] ), $taken, true ) ) {
				return $slot['colour'];
			}
		}

		// Exhausted: reuse the least-used slot rather than always the first.
		$counts = array();
		foreach ( self::PALETTE as $slot ) {
			$counts[ $slot['colour'] ] = 0;
		}
		foreach ( $taken as $colour ) {
			foreach ( $counts as $palette_colour => $n ) {
				if ( strtolower( $palette_colour ) === $colour ) {
					$counts[ $palette_colour ]++;
				}
			}
		}
		asort( $counts );
		return (string) key( $counts );
	}

	/**
	 * Readable text colour for a background — white or charcoal, whichever has
	 * the better contrast. An admin can type any hex into the colour field, so
	 * this cannot assume the palette.
	 *
	 * @param string $background Hex colour.
	 * @return string Hex colour.
	 */
	public static function text_on( $background ) {
		foreach ( self::PALETTE as $slot ) {
			if ( strtolower( $slot['colour'] ) === strtolower( (string) $background ) ) {
				return $slot['text'];
			}
		}

		$dark = '#1e2318';
		return self::contrast( $background, '#FFFFFF' ) >= self::contrast( $background, $dark ) ? '#FFFFFF' : $dark;
	}

	/**
	 * WCAG relative luminance.
	 *
	 * @param string $hex Hex colour.
	 * @return float
	 */
	protected static function luminance( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return 0.0;
		}

		$channels = array();
		foreach ( array( 0, 2, 4 ) as $offset ) {
			$c          = hexdec( substr( $hex, $offset, 2 ) ) / 255;
			$channels[] = ( $c <= 0.03928 ) ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
		}

		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/**
	 * WCAG contrast ratio between two colours.
	 *
	 * @param string $a Hex colour.
	 * @param string $b Hex colour.
	 * @return float
	 */
	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		$hi = max( $la, $lb );
		$lo = min( $la, $lb );
		return ( $hi + 0.05 ) / ( $lo + 0.05 );
	}

	/**
	 * How many categories can have a distinct colour.
	 *
	 * A hard ceiling, not a soft one: these are the only pairings in the CHA
	 * brand palette that clear WCAG AA. Going past it is a branding decision
	 * for the Association — new colours would have to come from the brand
	 * tokens — rather than something the plugin can solve.
	 *
	 * @return int
	 */
	public static function palette_capacity() {
		return count( self::PALETTE );
	}

	/**
	 * Categories that a site references but which have no definition.
	 *
	 * Normally impossible — definitions are built from the terms themselves —
	 * so a non-empty result means a filter has removed one, and every site
	 * using it renders unstyled in the app. Reported rather than left to be
	 * noticed, which is the whole failure mode this model replaced.
	 *
	 * @return string[] Term names.
	 */
	public static function undefined_categories() {
		$definitions = self::category_definitions();
		$terms       = get_terms(
			array(
				'taxonomy'   => 'heritage_category',
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$missing = array();
		foreach ( $terms as $term ) {
			if ( ! isset( $definitions[ $term->slug ] ) ) {
				$missing[] = $term->name;
			}
		}

		return $missing;
	}

	/**
	 * Colours used by more than one category.
	 *
	 * Impossible below the eighth term under scarcity assignment, but an admin
	 * can always pick a duplicate by hand — and past seven the palette forces
	 * one. Either way it is reported rather than left to be noticed by accident.
	 *
	 * @return array<string,string[]> colour => term names.
	 */
	public static function duplicate_colours() {
		$seen = array();
		foreach ( self::category_definitions() as $definition ) {
			$key            = strtolower( $definition['colour'] );
			$seen[ $key ][] = $definition['name'];
		}

		return array_filter(
			$seen,
			function ( $names ) {
				return count( $names ) > 1;
			}
		);
	}

	/**
	 * Persist the auto-assigned colour for a term that has none, so the
	 * assignment is stable and visible in the admin field.
	 *
	 * @param int $term_id Term ID.
	 */
	public static function persist_assigned_colour( $term_id ) {
		$term = get_term( $term_id, 'heritage_category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return;
		}
		if ( sanitize_hex_color( (string) get_term_meta( $term_id, self::META_COLOUR, true ) ) ) {
			return;
		}

		$definitions = self::category_definitions();
		if ( isset( $definitions[ $term->slug ]['colour'] ) ) {
			update_term_meta( $term_id, self::META_COLOUR, $definitions[ $term->slug ]['colour'] );
			self::flush_definitions();
		}
	}

	/* ---- migration off the per-site style meta ------------------------- */

	/**
	 * Option holding the pre-migration values, so a rollback is a rollback.
	 *
	 * Lifetime: kept indefinitely, and NOT autoloaded. Nothing expires it and
	 * no upgrade clears it — it is removed only when an admin presses Discard
	 * on the Heritage Categories screen, or runs
	 * `wp cha category-styles discard`. That is deliberate: an unattended
	 * cleanup would quietly remove the only rollback path.
	 */
	const STYLE_BACKUP_OPTION = 'cha_category_style_backup';

	/**
	 * The glyphs the old importer wrote per CATEGORY. Only these are cleared;
	 * anything else was typed by hand (or came from the importer's curated
	 * per-site table, which stores HTML entities such as "&#127968;" rather
	 * than raw emoji, so it can never match here) and is a deliberate per-site
	 * override that survives.
	 *
	 * @var string[]
	 */
	const LEGACY_ICONS = array( '🔵', '🏛️', '🎭', '🌿' );

	/**
	 * Retire the denormalised per-site style meta.
	 *
	 * `ac` and `dot` go entirely — `dot` was never read by the app and `ac`
	 * is now derived from the category. `icon` is cleared ONLY where it still
	 * holds one of the old importer's per-category defaults, so every
	 * hand-picked glyph (all 31 original Clarens sites) survives untouched
	 * while a category-derived one is corrected by inheriting from its
	 * category.
	 *
	 * Every deleted value is recorded first. The plugin ships as a manual zip
	 * upload, so without this a bad deploy would be unrecoverable data loss on
	 * a live client site.
	 *
	 * @return int Number of posts touched.
	 */
	public static function migrate_style_meta() {
		$post_ids = get_posts(
			array(
				'post_type'      => 'site',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		// Before clearing anything, let each category adopt the glyph its own
		// sites were actually using. Without this the migration is a visible
		// regression: every site loses its glyph and falls back to the generic
		// default, because a category that has never been edited has no glyph
		// of its own. Majority wins, which also quietly corrects the odd site
		// whose stored glyph contradicted its category.
		$adopted = self::adopt_category_glyphs( $post_ids );

		$backup  = array();
		$touched = 0;

		foreach ( $post_ids as $post_id ) {
			$ac   = (string) get_post_meta( $post_id, 'ac', true );
			$dot  = (string) get_post_meta( $post_id, 'dot', true );
			$icon = (string) get_post_meta( $post_id, 'icon', true );

			$clear_icon = in_array( $icon, self::LEGACY_ICONS, true );

			if ( '' === $ac && '' === $dot && ! $clear_icon ) {
				continue;
			}

			$backup[ $post_id ] = array(
				'ac'   => $ac,
				'dot'  => $dot,
				'icon' => $clear_icon ? $icon : null, // null = left alone.
			);

			if ( '' !== $ac ) {
				delete_post_meta( $post_id, 'ac' );
			}
			if ( '' !== $dot ) {
				delete_post_meta( $post_id, 'dot' );
			}
			if ( $clear_icon ) {
				delete_post_meta( $post_id, 'icon' );
			}

			++$touched;
		}

		if ( $backup ) {
			update_option(
				self::STYLE_BACKUP_OPTION,
				array(
					'created' => current_time( 'mysql' ),
					'version' => defined( 'CHA_VERSION' ) ? CHA_VERSION : '',
					'rows'    => $backup,
					// Term meta this migration ADDED, so a rollback can remove
					// it again rather than leaving half the change behind.
					'adopted' => $adopted,
				),
				false
			);
		}

		return $touched;
	}

	/**
	 * Delete the backup. The only thing that ever removes it — see the
	 * STYLE_BACKUP_OPTION docblock for why nothing expires it automatically.
	 *
	 * @return bool
	 */
	public static function discard_style_meta_backup() {
		return delete_option( self::STYLE_BACKUP_OPTION );
	}

	/**
	 * Give each category the glyph the majority of its sites already carried.
	 *
	 * Only fills a category that has no glyph of its own, so a deliberate
	 * choice is never overwritten. Uses the sites' own data rather than a
	 * hardcoded map, which is the whole point of the model — nothing here
	 * knows what "Cultural Heritage" is supposed to look like.
	 *
	 * @param int[] $post_ids Site post IDs.
	 * @return array<int,string> term_id => glyph adopted.
	 */
	protected static function adopt_category_glyphs( $post_ids ) {
		$tally = array();

		foreach ( $post_ids as $post_id ) {
			$icon = (string) get_post_meta( $post_id, 'icon', true );
			if ( '' === $icon || ! in_array( $icon, self::LEGACY_ICONS, true ) ) {
				continue;
			}

			$terms = get_the_terms( $post_id, 'heritage_category' );
			if ( ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}

			$term_id = (int) $terms[0]->term_id;
			if ( ! isset( $tally[ $term_id ] ) ) {
				$tally[ $term_id ] = array();
			}
			if ( ! isset( $tally[ $term_id ][ $icon ] ) ) {
				$tally[ $term_id ][ $icon ] = 0;
			}
			$tally[ $term_id ][ $icon ]++;
		}

		$adopted = array();
		foreach ( $tally as $term_id => $counts ) {
			if ( '' !== (string) get_term_meta( $term_id, self::META_ICON, true ) ) {
				continue;
			}
			arsort( $counts );
			$glyph = (string) key( $counts );
			update_term_meta( $term_id, self::META_ICON, $glyph );
			$adopted[ $term_id ] = $glyph;
		}

		if ( $adopted ) {
			self::flush_definitions();
		}

		return $adopted;
	}

	/**
	 * Put back everything migrate_style_meta() removed.
	 *
	 * Not wired to any UI on purpose — it is a recovery lever, called
	 * deliberately (WP-CLI `wp eval`, or a one-off snippet) if a deploy has to
	 * be rolled back. The backup option is left in place afterwards.
	 *
	 * @return int Number of posts restored.
	 */
	public static function restore_style_meta_backup() {
		$backup = get_option( self::STYLE_BACKUP_OPTION );
		if ( ! is_array( $backup ) || empty( $backup['rows'] ) ) {
			return 0;
		}

		// Remove the category glyphs the migration adopted, so a rollback
		// leaves nothing of the change behind.
		if ( ! empty( $backup['adopted'] ) ) {
			foreach ( $backup['adopted'] as $term_id => $glyph ) {
				if ( (string) get_term_meta( $term_id, self::META_ICON, true ) === (string) $glyph ) {
					delete_term_meta( $term_id, self::META_ICON );
				}
			}
			self::flush_definitions();
		}

		$restored = 0;
		foreach ( $backup['rows'] as $post_id => $values ) {
			if ( ! empty( $values['ac'] ) ) {
				update_post_meta( (int) $post_id, 'ac', $values['ac'] );
			}
			if ( ! empty( $values['dot'] ) ) {
				update_post_meta( (int) $post_id, 'dot', $values['dot'] );
			}
			if ( ! empty( $values['icon'] ) ) {
				update_post_meta( (int) $post_id, 'icon', $values['icon'] );
			}
			++$restored;
		}

		return $restored;
	}
}
