<?php
/**
 * Voucher redemption.
 *
 * GR's partner model is simpler than Clarens': there is no server-side stock
 * cap or date window (see CHA_Meta). A voucher is gated by `condition`
 * (default `paid` = needs a valid unlock token) and, optionally, `requiredSite`.
 *
 * DESIGN NOTES (flagged for confirmation):
 *  - `condition` is enforced SERVER-side: when it is `paid`, the request must
 *    carry a token that resolves valid (purchase-paid / active promo / admin).
 *  - Single-use is enforced SERVER-side per (token, partner) via a small
 *    redemptions log — stronger than Clarens' per-device localStorage flag,
 *    which a user can clear. Free vouchers (no token) fall back to the app's
 *    per-device flag.
 *  - `requiredSite` (must have visited a given site) is CLIENT-enforced: the
 *    server has no view of on-device GPS/visited state, so the app gates it.
 *    Echoed back so the app can enforce/telemeter consistently.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Redeem {

	const TABLE = 'cha_redemptions';

	/**
	 * Hook route registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create the redemptions table (called on activation).
	 */
	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token       VARCHAR(40)     NOT NULL,
			partner_id  VARCHAR(191)    NOT NULL,
			redeemed_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_token_partner (token, partner_id),
			KEY idx_partner (partner_id)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Register redeem + voucher-status routes.
	 */
	public static function register_routes() {
		register_rest_route(
			CHA_REST_NAMESPACE,
			'/redeem',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'redeem' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'partnerId' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'token'     => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			CHA_REST_NAMESPACE,
			'/voucher-status/(?P<partnerId>[a-zA-Z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'voucher_status' ),
				'permission_callback' => '__return_true',
			)
		);

		// Batch sibling of the single-partner route: one call returns per-partner
		// redemption state for a token across ALL partners (no partnerId in path).
		register_rest_route(
			CHA_REST_NAMESPACE,
			'/voucher-status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'voucher_status_all' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Resolve a partner post from the app's partner id (slug, numeric id, or
	 * "partner-<wpId>").
	 *
	 * @param string $partner_id App partner id.
	 * @return WP_Post|null
	 */
	protected static function find_partner( $partner_id ) {
		$post = null;

		if ( preg_match( '/^partner-(\d+)$/', $partner_id, $m ) ) {
			$post = get_post( (int) $m[1] );
		} elseif ( ctype_digit( $partner_id ) ) {
			$post = get_post( (int) $partner_id );
		} else {
			$post = get_page_by_path( $partner_id, OBJECT, 'partner' );
		}

		if ( $post && 'partner' === $post->post_type && 'publish' === $post->post_status ) {
			return $post;
		}
		return null;
	}

	/**
	 * Evaluate the date-window + stock gates for a partner.
	 *
	 * Shared by redeem() (to reject) and the voucher-status routes (to report
	 * availability without a redeem attempt). The `dateTo` boundary is inclusive
	 * of the whole day, mirroring the app's client-side check.
	 *
	 * @param int $post_id Partner post ID.
	 * @return array|null  null when open; otherwise { code, message, status }.
	 */
	protected static function gate_check( $post_id ) {
		$now = current_time( 'timestamp' );

		$from = (string) get_post_meta( $post_id, 'dateFrom', true );
		if ( '' !== $from ) {
			$from_ts = strtotime( $from );
			if ( $from_ts && $now < $from_ts ) {
				return array( 'code' => 'not_yet_available', 'message' => 'This voucher is not yet available.', 'status' => 403 );
			}
		}

		$to = (string) get_post_meta( $post_id, 'dateTo', true );
		if ( '' !== $to ) {
			$to_ts = strtotime( $to . ' 23:59:59' );
			if ( $to_ts && $now > $to_ts ) {
				return array( 'code' => 'expired', 'message' => 'This voucher has expired.', 'status' => 403 );
			}
		}

		$max = (int) get_post_meta( $post_id, 'maxVouchers', true );
		if ( $max > 0 ) {
			$used = (int) get_post_meta( $post_id, 'usedCount', true );
			if ( $used >= $max ) {
				return array( 'code' => 'sold_out', 'message' => 'This voucher is sold out.', 'status' => 403 );
			}
		}

		return null;
	}

	/**
	 * Atomically claim one unit of a partner's voucher stock.
	 *
	 * gate_check()'s `usedCount >= maxVouchers` test is advisory only: it is a
	 * read, and two concurrent redemptions can both pass it before either
	 * writes. The authoritative claim is this single conditional UPDATE — the
	 * `< maxVouchers` predicate is evaluated by MySQL under the row lock the
	 * UPDATE itself takes, so exactly one of two racing callers can see a row
	 * that still qualifies. A caller that gets 0 affected rows lost the race
	 * and must be told the voucher is sold out.
	 *
	 * Raw SQL rather than update_post_meta() for the same reason: the read
	 * -modify-write inside update_post_meta() is precisely the pattern that
	 * cannot be made safe from PHP.
	 *
	 * Unlimited partners (maxVouchers 0) still increment through the same
	 * atomic path so the counter stays accurate under concurrency — there is
	 * simply no cap predicate to satisfy.
	 *
	 * @param int $post_id Partner post ID.
	 * @return bool True when a unit was claimed; false when stock is exhausted
	 *              or the write failed.
	 */
	protected static function claim_stock( $post_id ) {
		global $wpdb;

		$max = (int) get_post_meta( $post_id, 'maxVouchers', true );

		// A raw UPDATE can only touch a row that exists, and usedCount is
		// registered with a default rather than written on save — so a partner
		// that has never been redeemed has no postmeta row at all. Create it
		// before claiming, or the first-ever redemption would read as sold out.
		//
		// add_post_meta( …, true ) is itself a check-then-insert, so two
		// simultaneous first-ever redemptions can leave two usedCount rows.
		// That cannot oversell — the UPDATE below matches every usedCount row
		// for the post and its `< max` predicate applies to each — it only
		// makes the displayed counter for that one partner briefly odd.
		// NOTE (1 Sep 2026 fix): usedCount is registered via register_post_meta()
		// with a default of 0 (see CHA_Meta::register_partner_meta()) so that
		// the REST/admin reads never see an empty value. That default masking
		// is exactly what breaks the emptiness check this replaced: real
		// WordPress returns the registered default ('0') from get_post_meta()
		// even when NO row exists yet, so `'' === get_post_meta(...)` was never
		// true on a partner's first-ever redemption -- add_post_meta() never
		// ran, the row was never created, and the raw UPDATE below then found
		// nothing to update (0 rows affected), so claim_stock() reported
		// sold-out for every partner's first redemption regardless of
		// maxVouchers. metadata_exists() checks the database directly, not
		// through the default-value filter, so it correctly distinguishes "no
		// row yet" from "row explicitly set to 0".
		if ( ! metadata_exists( 'post', $post_id, 'usedCount' ) ) {
			add_post_meta( $post_id, 'usedCount', '0', true );
		}

		if ( $max > 0 ) {
			$sql = $wpdb->prepare(
				"UPDATE {$wpdb->postmeta}
				    SET meta_value = CAST(meta_value AS UNSIGNED) + 1
				  WHERE post_id = %d
				    AND meta_key = 'usedCount'
				    AND CAST(meta_value AS UNSIGNED) < %d",
				$post_id,
				$max
			);
		} else {
			$sql = $wpdb->prepare(
				"UPDATE {$wpdb->postmeta}
				    SET meta_value = CAST(meta_value AS UNSIGNED) + 1
				  WHERE post_id = %d
				    AND meta_key = 'usedCount'",
				$post_id
			);
		}

		$updated = $wpdb->query( $sql );

		// The UPDATE bypassed the meta API, so the cached value for this post
		// is now stale — every later get_post_meta() in this request (and in
		// any persistent object cache) would read the pre-increment number.
		wp_cache_delete( $post_id, 'post_meta' );

		if ( false === $updated ) {
			error_log( '[CHA] claim_stock DB error for partner #' . $post_id . ': ' . $wpdb->last_error );
			return false;
		}

		return $updated > 0;
	}

	/**
	 * REST: POST cha/v1/redeem.
	 *
	 * @param WP_REST_Request $request Request ({ partnerId, token }).
	 * @return WP_REST_Response
	 */
	public static function redeem( WP_REST_Request $request ) {
		$partner_id = $request->get_param( 'partnerId' );
		$token      = strtoupper( trim( (string) $request->get_param( 'token' ) ) );

		$partner = self::find_partner( $partner_id );
		if ( ! $partner ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'partner_not_found',
					'message' => 'Partner not found.',
				),
				404
			);
		}

		$condition     = (string) get_post_meta( $partner->ID, 'condition', true );
		$condition     = '' !== $condition ? $condition : 'paid';
		$required_site = (string) get_post_meta( $partner->ID, 'requiredSite', true );

		// Enforce `condition`. `paid` requires a valid unlock token.
		if ( 'paid' === $condition ) {
			$resolved = CHA_Paystack::resolve_token( $token );
			if ( ! $resolved['valid'] ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'invalid_token',
						'message' => 'A valid trail pass is required to redeem this voucher.',
					),
					401
				);
			}
		}

		// Date-window + stock gates (after token validation, before the per-token
		// uniqueness check). 403 not_yet_available / expired / sold_out.
		$gate = self::gate_check( $partner->ID );
		if ( null !== $gate ) {
			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => $gate['code'],
					'message' => $gate['message'],
				),
				$gate['status']
			);
		}

		// Server-side single-use (per token, per partner). Free vouchers with no
		// token rely on the app's per-device flag.
		global $wpdb;
		if ( '' !== $token ) {
			$already = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::table() . ' WHERE token = %s AND partner_id = %s LIMIT 1',
					$token,
					$partner_id
				)
			);
			if ( $already ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'already_redeemed',
						'message' => 'This voucher has already been redeemed.',
					),
					409
				);
			}

			$inserted = $wpdb->insert(
				self::table(),
				array(
					'token'       => $token,
					'partner_id'  => $partner_id,
					'redeemed_at' => current_time( 'mysql' ),
				)
			);
			// Guard the race: the UNIQUE key rejects a concurrent double-redeem.
			if ( false === $inserted ) {
				return new WP_REST_Response(
					array(
						'success' => false,
						'code'    => 'already_redeemed',
						'message' => 'This voucher has already been redeemed.',
					),
					409
				);
			}
		}

		// Claim a unit of stock — the authoritative sold-out check (gate_check's
		// was advisory). Coexists with the per-token redemptions row above:
		// both protections apply, they guard different things (one visitor
		// twice vs. two visitors past the cap).
		if ( ! self::claim_stock( $partner->ID ) ) {
			// Lost the race, or the counter write failed. Either way this
			// visitor is not getting the voucher, so the redemptions row
			// written a moment ago must not stand — leaving it would burn
			// their one shot at this partner and block a legitimate retry
			// once stock is restocked.
			if ( '' !== $token ) {
				$wpdb->delete(
					self::table(),
					array( 'token' => $token, 'partner_id' => $partner_id )
				);
			}

			return new WP_REST_Response(
				array(
					'success' => false,
					'code'    => 'sold_out',
					'message' => 'This voucher is sold out.',
				),
				403
			);
		}

		return new WP_REST_Response(
			array(
				'success'      => true,
				'code'         => 'redeemed',
				'message'      => 'Voucher redeemed successfully.',
				'partner_name' => $partner->post_title,
				'offer_label'  => (string) get_post_meta( $partner->ID, 'offerLabel', true ),
				'offer'        => (string) get_post_meta( $partner->ID, 'offer', true ),
				'requiredSite' => $required_site, // client also enforces this.
			),
			200
		);
	}

	/**
	 * REST: GET cha/v1/voucher-status/{partnerId}.
	 *
	 * GR has no stock/date gating, so availability is a function of the partner
	 * existing plus its condition; per-user redemption is reported when a token
	 * is supplied.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function voucher_status( WP_REST_Request $request ) {
		$partner_id = $request->get_param( 'partnerId' );
		$partner    = self::find_partner( $partner_id );

		if ( ! $partner ) {
			return new WP_REST_Response( array( 'available' => false ), 404 );
		}

		$condition = (string) get_post_meta( $partner->ID, 'condition', true );
		$condition = '' !== $condition ? $condition : 'paid';

		$redeemed = false;
		$token    = strtoupper( trim( (string) $request->get_param( 'token' ) ) );
		if ( '' !== $token ) {
			global $wpdb;
			$redeemed = (bool) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM ' . self::table() . ' WHERE token = %s AND partner_id = %s LIMIT 1',
					$token,
					$partner_id
				)
			);
		}

		$gate      = self::gate_check( $partner->ID );
		$available = ! $redeemed && null === $gate;

		return new WP_REST_Response(
			array(
				'available'    => $available,
				'condition'    => $condition,
				'requiredSite' => (string) get_post_meta( $partner->ID, 'requiredSite', true ),
				'redeemed'     => $redeemed,
				'reason'       => $gate ? $gate['code'] : ( $redeemed ? 'redeemed' : '' ),
			),
			200
		);
	}

	/**
	 * REST: GET cha/v1/voucher-status?token=…
	 *
	 * Batch counterpart to voucher_status(): returns per-partner redemption
	 * state for the supplied token across EVERY published partner in a single
	 * request, so the app doesn't fire one call per partner. Uses the same
	 * cha_redemptions lookup, keyed by the app partner id (the post slug, which
	 * is what the feed emits as `id` and what redeem() stores as partner_id).
	 *
	 * Shape: { "<partnerId>": { "redeemed": bool }, … }. With no/blank token
	 * every partner reports redeemed:false (the server can't attribute a
	 * redemption without a token).
	 *
	 * @param WP_REST_Request $request Request ({ token }).
	 * @return WP_REST_Response
	 */
	public static function voucher_status_all( WP_REST_Request $request ) {
		global $wpdb;

		$token = strtoupper( trim( (string) $request->get_param( 'token' ) ) );

		// Partner ids this token has already redeemed — one query, not N.
		$redeemed = array();
		if ( '' !== $token ) {
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT partner_id FROM ' . self::table() . ' WHERE token = %s',
					$token
				)
			);
			foreach ( (array) $rows as $pid ) {
				$redeemed[ $pid ] = true;
			}
		}

		// Every published partner, so we can evaluate each one's date/stock gate.
		$partners = get_posts(
			array(
				'post_type'      => 'partner',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);

		$status = array();
		foreach ( $partners as $p ) {
			$slug        = $p->post_name;
			$is_redeemed = isset( $redeemed[ $slug ] );
			$gate        = self::gate_check( $p->ID );
			$status[ $slug ] = array(
				'redeemed'  => $is_redeemed,
				'available' => ! $is_redeemed && null === $gate,
				'reason'    => $gate ? $gate['code'] : ( $is_redeemed ? 'redeemed' : '' ),
			);
		}

		// Cast to object so an empty partner set serialises as {} not [].
		return new WP_REST_Response( (object) $status, 200 );
	}
}
