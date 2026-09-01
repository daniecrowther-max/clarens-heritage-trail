<?php
/**
 * Meta fields for `site` and `partner`, registered exactly per
 * GR_Content_Model_Field_List_v1.md. Field names match the app's content
 * feed keys verbatim — do not rename without updating the contract doc.
 *
 * Not meta by design:
 * - `id`   → the `site_id` meta when set, else the post slug (see
 *           register_site_meta()); `name` → post title (both CPTs)
 * - `wpId` → the partner's native post ID
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Meta {

	/**
	 * Register all meta. Hooked to `init`.
	 */
	public static function register() {
		self::register_site_meta();
		self::register_partner_meta();
	}

	/**
	 * One-time migration: back-fill `site_id` from each site's CURRENT
	 * post_name, for any post where site_id is still empty. This preserves
	 * every existing site's feed `id` value exactly at the moment it starts
	 * being read from meta (CHA_Rest::site_record()) instead of live
	 * post_name — so decoupling the two never changes an id, even momentarily.
	 *
	 * Idempotent: only touches posts where site_id is empty, so re-running
	 * (activation, or the guarded admin_init hook) is a no-op once done. Safe
	 * to delete this method + its wiring once confirmed run in production.
	 *
	 * @return int Number of posts back-filled.
	 */
	public static function migrate_site_id_from_slug() {
		$post_ids = get_posts(
			array(
				'post_type'      => 'site',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$migrated = 0;
		foreach ( $post_ids as $post_id ) {
			$current = (string) get_post_meta( $post_id, 'site_id', true );
			if ( '' !== $current ) {
				continue; // Already migrated (or explicitly set) — leave it.
			}
			$slug = get_post_field( 'post_name', $post_id );
			if ( '' !== $slug ) {
				update_post_meta( $post_id, 'site_id', $slug );
				++$migrated;
			}
		}
		return $migrated;
	}

	/**
	 * Re-entrancy guard for sync_excerpt_from_story()'s wp_update_post()
	 * call, which fires save_post_site again.
	 *
	 * @var bool
	 */
	private static $syncing_excerpt = false;

	/**
	 * Keep the post's native post_excerpt in step with `story[0]` — purely
	 * additive, no field-list contract change. This is what feeds a social/
	 * search meta description (CHA_Social_Meta, and any 3rd-party SEO
	 * plugin's normal excerpt fallback) without introducing a second,
	 * divergent "description" field. Called from both the site meta box's
	 * save() and the importer's row processing, so it must be idempotent and
	 * re-entrancy-safe: wp_update_post() re-fires save_post_site, which would
	 * otherwise recurse back into the site meta box's own save().
	 *
	 * @param int   $post_id Site post ID.
	 * @param array $story   The story paragraphs array as just saved.
	 */
	public static function sync_excerpt_from_story( $post_id, $story ) {
		if ( self::$syncing_excerpt ) {
			return;
		}

		$first = ( is_array( $story ) && isset( $story[0] ) && is_string( $story[0] ) ) ? trim( $story[0] ) : '';
		if ( '' === $first ) {
			return;
		}

		$excerpt = self::truncate_for_excerpt( $first );
		if ( $excerpt === get_post_field( 'post_excerpt', $post_id ) ) {
			return; // Already in sync — no write, no re-entrancy risk.
		}

		self::$syncing_excerpt = true;
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_excerpt' => $excerpt,
			)
		);
		self::$syncing_excerpt = false;
	}

	/**
	 * Trim to a clean meta-description length without cutting mid-word.
	 *
	 * @param string $text  Source text.
	 * @param int    $limit Character cap.
	 * @return string
	 */
	private static function truncate_for_excerpt( $text, $limit = 160 ) {
		$text = sanitize_text_field( $text );
		$len  = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $len <= $limit ) {
			return $text;
		}

		$cut   = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $limit ) : substr( $text, 0, $limit );
		$space = strrpos( $cut, ' ' );
		if ( false !== $space ) {
			$cut = substr( $cut, 0, $space );
		}
		return rtrim( $cut ) . '…';
	}

	/**
	 * Heritage Site meta.
	 */
	private static function register_site_meta() {
		// Stable feed identity — separate from post_name (the URL slug), so
		// the web editor can freely change permalinks without disconnecting a
		// site's GPS stamps, requiredSite gating, or partner siteId links.
		// CHA_Rest::site_record() falls back to post_name when this is empty.
		self::string( 'site', 'site_id', __( 'Stable Site ID (e.g. GR-001) — the app\'s real identity, independent of the WordPress permalink. Changing this after a site has visitor stamps will disconnect their progress.', 'cha' ) );

		// Booleans.
		self::boolean( 'site', 'bp', __( 'One of the 20 Blue Plaque sites (subset flag, independent of category)', 'cha' ) );
		self::boolean( 'site', 'free', __( 'Part of the free set — open without the trail pass', 'cha' ) );

		// Numbers.
		self::number( 'site', 'trailNum', __( 'Order within the trail', 'cha' ) );
		self::number( 'site', 'lat', __( 'Latitude — needed for map + GPS check-in', 'cha' ) );
		self::number( 'site', 'lng', __( 'Longitude', 'cha' ) );
		self::number( 'site', 'radius', __( 'GPS check-in tolerance in metres', 'cha' ), 30 );

		// Strings. (`trail` is now the heritage_trail taxonomy, not meta.)
		self::string( 'site', 'address', __( 'Street address', 'cha' ) );
		self::string( 'site', 'icon', __( 'Marker/placeholder glyph when no photo', 'cha' ) );
		self::string( 'site', 'ac', __( 'Accent style class — can default from category', 'cha' ) );
		self::string( 'site', 'dot', __( 'Map-marker colour (hex) — can default from category', 'cha' ) );
		self::string( 'site', 'plaqueText', __( 'Exact wording on the Blue Plaque — captured by the interns; not read by the app yet', 'cha' ) );

		// URLs.
		self::url( 'site', 'map', __( 'Google Maps link', 'cha' ) );
		self::url( 'site', 'photo', __( 'External image URL (.webp); app derives a fallback from id if absent', 'cha' ) );

		// `facts` — repeater of { l, v } label/value rows.
		register_post_meta(
			'site',
			'facts',
			array(
				'type'              => 'array',
				'description'       => __( 'Label/value quick-facts rows', 'cha' ),
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_facts' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'l' => array( 'type' => 'string' ),
								'v' => array( 'type' => 'string' ),
							),
						),
					),
				),
			)
		);

		// `story` — body paragraphs, one string per paragraph.
		register_post_meta(
			'site',
			'story',
			array(
				'type'              => 'array',
				'description'       => __( 'Body paragraphs (one string per paragraph)', 'cha' ),
				'single'            => true,
				'default'           => array(),
				'sanitize_callback' => array( __CLASS__, 'sanitize_story' ),
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
			)
		);
	}

	/**
	 * Partner / Voucher meta.
	 */
	private static function register_partner_meta() {
		self::string( 'partner', 'type', __( 'Category (Retail, Restaurant, …)', 'cha' ) );
		self::url( 'partner', 'logo', __( 'External logo image URL (.webp)', 'cha' ) );
		self::string( 'partner', 'address', __( 'Street address', 'cha' ) );
		self::number( 'partner', 'lat', __( 'Latitude for the partner map pin', 'cha' ) );
		self::number( 'partner', 'lng', __( 'Longitude for the partner map pin', 'cha' ) );
		self::string( 'partner', 'offer', __( 'Short offer, shown as a badge', 'cha' ) );
		self::string( 'partner', 'offerLabel', __( 'Headline (e.g. "10% Discount")', 'cha' ) );
		self::string( 'partner', 'offerSub', __( 'Detail / conditions line', 'cha' ) );
		self::string( 'partner', 'siteId', __( 'Optional link to a Heritage Site id (slug)', 'cha' ) );
		self::string( 'partner', 'condition', __( 'Unlock rule (paid = needs the trail pass)', 'cha' ), 'paid' );
		self::string( 'partner', 'requiredSite', __( 'Optional — a specific site that gates the voucher', 'cha' ) );
		self::string( 'partner', 'voucherKey', __( 'On-device redemption key (cht_ prefix, derived)', 'cha' ) );

		// Date window + stock cap.
		self::string( 'partner', 'dateFrom', __( 'Voucher opens on this date (YYYY-MM-DD); blank = no restriction', 'cha' ) );
		self::string( 'partner', 'dateTo', __( 'Voucher expires after this date (YYYY-MM-DD); blank = no restriction', 'cha' ) );
		self::number( 'partner', 'maxVouchers', __( 'Max redemptions across all visitors; 0 = unlimited', 'cha' ), 0 );
		// Server-incremented only — never exposed in the admin meta box.
		self::number( 'partner', 'usedCount', __( 'Redemptions so far (server-managed, counted against maxVouchers)', 'cha' ), 0 );
	}

	/* ---- registration helpers ------------------------------------- */

	private static function boolean( $post_type, $key, $description ) {
		register_post_meta(
			$post_type,
			$key,
			array(
				'type'              => 'boolean',
				'description'       => $description,
				'single'            => true,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
				'show_in_rest'      => true,
			)
		);
	}

	private static function number( $post_type, $key, $description, $default = 0 ) {
		register_post_meta(
			$post_type,
			$key,
			array(
				'type'              => 'number',
				'description'       => $description,
				'single'            => true,
				'default'           => $default,
				'sanitize_callback' => array( __CLASS__, 'sanitize_number' ),
				'show_in_rest'      => true,
			)
		);
	}

	private static function string( $post_type, $key, $description, $default = '' ) {
		register_post_meta(
			$post_type,
			$key,
			array(
				'type'              => 'string',
				'description'       => $description,
				'single'            => true,
				'default'           => $default,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
			)
		);
	}

	private static function url( $post_type, $key, $description ) {
		register_post_meta(
			$post_type,
			$key,
			array(
				'type'              => 'string',
				'description'       => $description,
				'single'            => true,
				'default'           => '',
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => true,
			)
		);
	}

	/* ---- sanitizers ------------------------------------------------ */

	/**
	 * Cast a numeric meta value to a float.
	 *
	 * Registered as a callback (not bare `floatval`) because WordPress's
	 * `sanitize_meta()` invokes sanitize callbacks with four arguments
	 * ( value, key, object_type, subtype ). `floatval` is an internal PHP
	 * function that accepts exactly one argument, so passing it the extra
	 * three throws an ArgumentCountError under PHP 8+. This wrapper takes the
	 * value and ignores the rest.
	 *
	 * @param mixed $value Raw meta value.
	 * @return float
	 */
	public static function sanitize_number( $value ) {
		return (float) $value;
	}

	/**
	 * Keep only well-formed { l, v } rows.
	 *
	 * @param mixed $value Raw meta value.
	 * @return array
	 */
	public static function sanitize_facts( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$clean = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$clean[] = array(
				'l' => sanitize_text_field( $row['l'] ?? '' ),
				'v' => sanitize_text_field( $row['v'] ?? '' ),
			);
		}
		return $clean;
	}

	/**
	 * Story paragraphs: array of plain strings.
	 *
	 * @param mixed $value Raw meta value.
	 * @return array
	 */
	public static function sanitize_story( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_map( 'sanitize_text_field', array_filter( $value, 'is_string' ) ) );
	}
}
