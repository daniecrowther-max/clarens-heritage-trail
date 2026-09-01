<?php
/**
 * REST: GET cha/v1/content → { "sites": […], "partners": […] }.
 *
 * The public, read-only feed the app merges by `id`. Field names match
 * GR_Content_Model_Field_List_v1.md verbatim. Optional fields are omitted
 * when empty to keep the payload light at 220+ sites; the app derives its
 * own fallbacks (photo from id, ac/dot from cat).
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Rest {

	const CACHE_KEY = 'cha_content_feed';
	const CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Hook route registration and cache invalidation.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );

		// Any change to either CPT invalidates the cached feed.
		foreach ( array( 'save_post_site', 'save_post_partner', 'deleted_post', 'trashed_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_cache' ) );
		}

		// save_post only fires for an actual post save — the wp-admin editor.
		// Meta and terms written directly (update_post_meta/wp_set_object_terms,
		// as the importer, the one-time migrations and any WP-CLI/REST tooling
		// do) never fire it, which left the feed serving a stale copy for up to
		// CACHE_TTL after a bulk data change. Watch the meta and term writes too.
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_cache_for_meta' ), 10, 2 );
		}
		add_action( 'set_object_terms', array( __CLASS__, 'flush_cache_for_object' ) );

		// A price change must show in the feed (and thus app copy) immediately.
		add_action( 'update_option_' . CHA_Settings::PRICE_OPTION, array( __CLASS__, 'flush_cache' ) );
	}

	/**
	 * Register cha/v1/content.
	 */
	public static function register_routes() {
		register_rest_route(
			CHA_REST_NAMESPACE,
			'/content',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_content' ),
				'permission_callback' => '__return_true', // Public, read-only.
			)
		);
	}

	/**
	 * Drop the cached feed.
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Meta-write hook signature is ( $meta_id, $object_id, … ) — the post is
	 * the SECOND argument, so this cannot be wired to flush_cache() directly.
	 *
	 * @param int $meta_id   Ignored.
	 * @param int $object_id Post the meta belongs to.
	 */
	public static function flush_cache_for_meta( $meta_id, $object_id ) {
		self::flush_cache_for_object( $object_id );
	}

	/**
	 * Flush only when the touched post is actually in the feed — a meta or term
	 * write on an unrelated post type (pages, media, another plugin's CPT) must
	 * not keep dropping a cache it cannot affect.
	 *
	 * @param int $object_id Post ID.
	 */
	public static function flush_cache_for_object( $object_id ) {
		if ( in_array( get_post_type( $object_id ), array( 'site', 'partner' ), true ) ) {
			self::flush_cache();
		}
	}

	/**
	 * Build (or serve cached) feed.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_content() {
		$feed = get_transient( self::CACHE_KEY );

		if ( false === $feed ) {
			$feed = array(
				'sites'    => self::build_sites(),
				'partners' => self::build_partners(),
				'config'   => self::build_config(),
			);
			set_transient( self::CACHE_KEY, $feed, self::CACHE_TTL );
		}

		return rest_ensure_response( $feed );
	}

	/* ---- sites ------------------------------------------------------ */

	/**
	 * All published Heritage Sites as feed records.
	 *
	 * @return array[]
	 */
	private static function build_sites() {
		$posts = get_posts(
			array(
				'post_type'      => 'site',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => array(
					'menu_order' => 'ASC',
					'title'      => 'ASC',
				),
			)
		);

		$sites = array();
		foreach ( $posts as $post ) {
			$record = self::site_record( $post );
			if ( null !== $record ) {
				$sites[] = $record;
			}
		}
		return $sites;
	}

	/**
	 * One site feed record, or null when the merge guard would skip it
	 * (missing id or name).
	 *
	 * @param WP_Post $post Site post.
	 * @return array|null
	 */
	private static function site_record( $post ) {
		// The feed's stable identity: the `site_id` meta when set (decoupled
		// from WordPress's own SEO-editable permalink), else the post's own
		// slug — preserves current behaviour for any site created without an
		// explicit site_id (e.g. a manually-created test post).
		$site_id = (string) get_post_meta( $post->ID, 'site_id', true );
		$id      = '' !== $site_id ? $site_id : $post->post_name;
		$name    = $post->post_title;
		if ( '' === $id || '' === $name ) {
			return null;
		}

		$record = array(
			'id'   => $id,
			'name' => $name,
		);

		// `cat` — first heritage_category term name.
		$terms = get_the_terms( $post, 'heritage_category' );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$record['cat'] = $terms[0]->name;
		}

		// `trail` — first heritage_trail term SLUG. Unlike `cat` (which the app
		// prints verbatim as a badge, so the human-readable name is what it
		// wants), `trail` is a grouping key the app matches against
		// CONFIG.trailGroups. The slug is the stable half of a term: renaming
		// "Clarens Town" in wp-admin must not silently drop 19 sites out of
		// their group in the live app.
		$trail_terms = get_the_terms( $post, 'heritage_trail' );
		if ( is_array( $trail_terms ) && ! empty( $trail_terms ) ) {
			$record['trail'] = $trail_terms[0]->slug;
		}

		// Booleans and radius are always present; the app relies on them.
		$record['bp']     = (bool) get_post_meta( $post->ID, 'bp', true );
		$record['free']   = (bool) get_post_meta( $post->ID, 'free', true );
		$record['radius'] = (float) get_post_meta( $post->ID, 'radius', true );

		self::add_number( $record, $post->ID, 'trailNum' );
		self::add_number( $record, $post->ID, 'lat' );
		self::add_number( $record, $post->ID, 'lng' );

		foreach ( array( 'address', 'ac', 'dot', 'photo' ) as $key ) {
			self::add_string( $record, $post->ID, $key );
		}

		// `icon` is typed into wp-admin as an HTML numeric character reference
		// (e.g. "&#127968;") so editors can enter an emoji into a plain text
		// field. Decode it here into the actual Unicode character so the
		// app's escapeHtml() — applied consistently to every WP feed value
		// since the Aug 2026 security audit — treats it like any other safe
		// text value instead of escaping the "&" and showing the raw entity
		// code on screen (e.g. literal "&#127968;" instead of the emoji).
		$icon = (string) get_post_meta( $post->ID, 'icon', true );
		if ( '' !== $icon ) {
			$record['icon'] = html_entity_decode( $icon, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		self::add_map( $record, $post->ID );

		// Photo credit/source — provenance meta (see class-cha-importer.php),
		// stored under a leading underscore so it stays out of the generic
		// add_string() loop and WP's own meta REST protocol, but still worth
		// surfacing as a small caption under the photo in the app.
		$photo_credit = (string) get_post_meta( $post->ID, '_cha_photo_credit', true );
		if ( '' !== $photo_credit ) {
			$record['photoCredit'] = $photo_credit;
		}

		foreach ( array( 'facts', 'story' ) as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_array( $value ) && ! empty( $value ) ) {
				$record[ $key ] = array_values( $value );
			}
		}

		return $record;
	}

	/* ---- config ------------------------------------------------------ */

	/**
	 * App runtime config carried on the feed. The unlock price lives here so
	 * user-facing copy is never a hardcoded lie — the app reads it and formats.
	 *
	 * @return array
	 */
	private static function build_config() {
		return array(
			'unlockPriceCents' => CHA_Settings::unlock_price_cents(),
			'currency'         => 'ZAR',
		);
	}

	/* ---- partners ---------------------------------------------------- */

	/**
	 * All published Partners as feed records.
	 *
	 * @return array[]
	 */
	private static function build_partners() {
		$posts = get_posts(
			array(
				'post_type'      => 'partner',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$partners = array();
		foreach ( $posts as $post ) {
			$record = self::partner_record( $post );
			if ( null !== $record ) {
				$partners[] = $record;
			}
		}
		return $partners;
	}

	/**
	 * One partner feed record, or null when missing id or name.
	 *
	 * @param WP_Post $post Partner post.
	 * @return array|null
	 */
	private static function partner_record( $post ) {
		$id   = $post->post_name;
		$name = $post->post_title;
		if ( '' === $id || '' === $name ) {
			return null;
		}

		$record = array(
			'id'   => $id,
			'wpId' => $post->ID,
			'name' => $name,
		);

		self::add_number( $record, $post->ID, 'lat' );
		self::add_number( $record, $post->ID, 'lng' );

		foreach ( array( 'type', 'logo', 'address', 'offer', 'offerLabel', 'offerSub', 'siteId', 'requiredSite', 'voucherKey', 'dateFrom', 'dateTo' ) as $key ) {
			self::add_string( $record, $post->ID, $key );
		}

		// Stock cap — omitted when unlimited (0). Lets the app show "sold out"
		// copy; the authoritative sold-out state comes from voucher-status.
		self::add_number( $record, $post->ID, 'maxVouchers' );

		// Unlock rule is always present; defaults to `paid` via meta default.
		$condition           = (string) get_post_meta( $post->ID, 'condition', true );
		$record['condition'] = '' !== $condition ? $condition : 'paid';

		return $record;
	}

	/* ---- record helpers ---------------------------------------------- */

	/**
	 * Add a string meta value to the record when non-empty.
	 *
	 * @param array  $record  Feed record (by reference).
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key (feed field name).
	 */
	private static function add_string( &$record, $post_id, $key ) {
		$value = (string) get_post_meta( $post_id, $key, true );
		if ( '' !== $value ) {
			$record[ $key ] = $value;
		}
	}

	/**
	 * Add the Google Maps link. A manually-entered `map` meta value (typed
	 * into wp-admin — class-cha-site-meta-box.php) always wins, e.g. for a
	 * specific parking/entrance pin rather than the building's raw GPS
	 * point. Otherwise derive a "view this pin" link from lat/lng, since
	 * the field was never wired into the spreadsheet importer (no maps-link
	 * column in class-cha-importer.php's COLUMNS map) and so is empty for
	 * every imported site — omitting `map` here made the app's "Open in
	 * Google Maps" button permanently disabled. Zero lat/lng means "unset",
	 * same convention as add_number(); `map` is omitted (not a broken link)
	 * when coordinates are missing.
	 *
	 * @param array $record  Feed record (by reference).
	 * @param int   $post_id Post ID.
	 */
	private static function add_map( &$record, $post_id ) {
		$manual = (string) get_post_meta( $post_id, 'map', true );
		if ( '' !== $manual ) {
			$record['map'] = $manual;
			return;
		}

		$lat = (float) get_post_meta( $post_id, 'lat', true );
		$lng = (float) get_post_meta( $post_id, 'lng', true );
		if ( 0.0 !== $lat && 0.0 !== $lng ) {
			$record['map'] = sprintf( 'https://www.google.com/maps?q=%.6f,%.6f', $lat, $lng );
		}
	}

	/**
	 * Add a numeric meta value to the record when set and non-zero.
	 * Zero means "unset" for every numeric field in this model — GR sits
	 * at (-32, 24), and trailNum ordering starts at 1.
	 *
	 * @param array  $record  Feed record (by reference).
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key (feed field name).
	 */
	private static function add_number( &$record, $post_id, $key ) {
		$value = (float) get_post_meta( $post_id, $key, true );
		if ( 0.0 !== $value ) {
			$record[ $key ] = $value;
		}
	}
}
