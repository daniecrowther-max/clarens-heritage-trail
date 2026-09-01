<?php
/**
 * Site taxonomies:
 *  - `heritage_category` — the app's `cat` field, shared with the website menu.
 *    Fixed, seeded.
 *  - `heritage_trail` — the app's `trail` field (trail grouping). Free-growing:
 *    NOT seeded; created via WordPress's native taxonomy UI.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Taxonomy {

	/**
	 * The four category values, taken verbatim from Clarens's real site data
	 * (clarens-heritage-trail repo, commit a8627de) — NOT the GRHS categories
	 * this plugin was forked with (Blue Plaques/Buildings/Monuments/People).
	 * 22 of Clarens's 31 real sites are the generic 'Heritage Site', which
	 * doesn't force meaningfully into any of GRHS's four terms.
	 *
	 * @var string[]
	 */
	const TERMS = array(
		'Heritage Site',
		'Blue Plaque Site',
		'Cultural Heritage',
		'Natural Heritage',
	);

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
}
