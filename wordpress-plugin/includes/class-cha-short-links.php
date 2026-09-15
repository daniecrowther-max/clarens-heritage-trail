<?php
/**
 * Permanent QR short links: /s/{site_id} → the site's public page.
 *
 * The blue plaques are physical and etched for decades; the URL printed on
 * them must never change even though everything behind it — page slugs, the
 * app's structure, even the CMS — is free to. Resolving via the `site_id`
 * meta (the importer's stable key, see class-cha-meta.php) rather than the
 * post's own slug is what makes that possible: a handful of live sites
 * already carry each other's slugs (the importer deliberately never rewrites
 * one), so a plaque etched with a page URL would point at the wrong site the
 * day that gets tidied up. A plaque etched with /s/supply-store does not.
 *
 * Three rules the whole design answers to:
 * - 302, never 301 — a 301 is cached by browsers and scanner apps for years,
 *   which defeats the entire point of an indirection layer.
 * - A plaque never dead-ends. Unknown and unpublished ids both land on the
 *   trail index with a friendly note, never a 404.
 * - The redirect target is configurable in one place (a filterable base),
 *   so the whole set can be repointed — website page today, app deep link
 *   later — without a code change or a zip re-upload.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Short_Links {

	/**
	 * The route pattern, as one source of truth for register_rewrite() and
	 * the test suite's regex assertions — so the trailing-slash/no-trailing-
	 * slash behaviour is tested against the exact pattern that ships, not a
	 * hand-copied lookalike that could silently drift from it.
	 */
	const REWRITE_PATTERN = '^s/([^/]+)/?$';

	/** Rewrite query var carrying the raw (un-normalised) id from the URL. */
	const QUERY_VAR = 'cha_short_link';

	/** Option holding the configurable redirect base. Empty = each site's own page. */
	const OPTION_BASE = 'cha_short_link_base';

	/** Option holding unrecognised-id hit counts, capped — see record_miss(). */
	const OPTION_MISSES = 'cha_short_link_misses';

	/** Distinct unrecognised ids remembered before we stop growing the option. */
	const MISS_CAP = 50;

	/** Post meta: scan count (int) and last-scanned date (Y-m-d). Nothing else. */
	const META_COUNT = '_cha_scan_count';
	const META_LAST  = '_cha_scan_last';

	/** Guards the one-shot re-flush after a file-replace deploy — see the main plugin file. */
	const REWRITE_FLUSH_OPTION = 'cha_short_links_rewrite_flushed';

	/** Never 301. Etched into a constant so "302, not 301" is one line to audit. */
	const REDIRECT_STATUS = 302;

	const ADMIN_SLUG = 'cha-short-links';

	/**
	 * Hook the route, the admin screen and WP-CLI. Hooked from the plugin
	 * bootstrap alongside the other components.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		// parse_request, not template_redirect: it fires before WordPress runs
		// a main query that would otherwise resolve to a 404, and before
		// redirect_canonical. A QR scan on a slow connection should cost one
		// redirect, not a wasted query.
		add_action( 'parse_request', array( __CLASS__, 'on_parse_request' ) );

		// Any theme that calls wp_body_open() gets the fallback note. A plaque
		// must not dead-end silently — this is the one sentence that tells a
		// visitor why they landed on the trail index instead of a specific site.
		add_action( 'wp_body_open', array( __CLASS__, 'render_fallback_note' ) );

		// dispatch() uses wp_safe_redirect(), which silently rewrites the
		// target to admin_url() for any host it doesn't recognise as this
		// site's own — exactly what an admin-set base pointing at the app's
		// separate origin (trail.clarensheritage.org) is. That allowance
		// is added and removed immediately around the one wp_safe_redirect()
		// call it exists for (see dispatch()) — deliberately NOT registered
		// here in init(), which runs on every request: a filter added here
		// would leave the base's host allowed for any OTHER wp_safe_redirect()
		// call (core or another plugin) for the rest of that same request,
		// which is a broader allowance than this plugin has any business
		// granting.

		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_csv_export' ) );
		add_action( 'admin_notices', array( __CLASS__, 'rewrite_notice' ) );
		add_action( 'admin_post_cha_short_links_flush', array( __CLASS__, 'handle_flush_action' ) );

		// The shell path for anyone with SSH on the hosting account.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'cha short-links', array( __CLASS__, 'cli' ) );
		}
	}

	/* =====================================================================
	 * The route
	 * ===================================================================== */

	/**
	 * `/s/{id}` or `/s/{id}/` → `index.php?cha_short_link={id}`.
	 *
	 * Hooked to `init` so it re-registers on every request; only takes
	 * effect for real once WordPress's rewrite rules are flushed (activation,
	 * the guarded one-shot in the main plugin file, or the admin notice's
	 * manual "Fix now").
	 */
	public static function register_rewrite() {
		add_rewrite_rule( self::REWRITE_PATTERN, 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
	}

	/**
	 * Let WordPress accept our query var from the rewrite.
	 *
	 * @param string[] $vars Registered query vars.
	 * @return string[]
	 */
	public static function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Dispatch on `parse_request`, once WP has parsed the request into
	 * query vars but before it runs the main query.
	 *
	 * @param WP $wp The main WP request object.
	 */
	public static function on_parse_request( $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		self::dispatch( $wp->query_vars[ self::QUERY_VAR ] );
	}

	/**
	 * Resolve raw URL input to a redirect. This is the entire decision made
	 * for every hit — deliberately free of headers/exit/output so it can be
	 * exercised directly by the test suite. `dispatch()` below is the thin
	 * production wrapper around it.
	 *
	 * @param string $raw    The un-normalised id from the URL (any case, any
	 *                       whitespace the rewrite happened to capture).
	 * @param bool   $record Whether to record the hit (scan counter / miss
	 *                       log). Pass false to resolve without side effects,
	 *                       e.g. for the admin screen's preview column.
	 * @return array{status: string, site_id: string, url: string, post: WP_Post|null}
	 *               status is one of 'found' | 'unpublished' | 'unknown'.
	 */
	public static function resolve( $raw, $record = true ) {
		// sanitize_title() lowercases and matches exactly how the importer
		// derives site_id from the spreadsheet's Site ID column in the first
		// place (class-cha-importer.php) — so SUPPLY-STORE, supply-store and
		// Supply-Store all normalise to the same key the meta was written under.
		$site_id = sanitize_title( trim( (string) $raw ) );

		if ( '' === $site_id ) {
			if ( $record ) {
				self::record_miss( $site_id );
			}
			return array(
				'status'  => 'unknown',
				'site_id' => $site_id,
				'post'    => null,
				'url'     => self::fallback_url( $site_id, 'unknown' ),
			);
		}

		$post = self::find_site( $site_id );

		if ( null === $post ) {
			if ( $record ) {
				self::record_miss( $site_id );
			}
			return array(
				'status'  => 'unknown',
				'site_id' => $site_id,
				'post'    => null,
				'url'     => self::fallback_url( $site_id, 'unknown' ),
			);
		}

		if ( 'publish' !== $post->post_status ) {
			return array(
				'status'  => 'unpublished',
				'site_id' => $site_id,
				'post'    => $post,
				'url'     => self::fallback_url( $site_id, 'unpublished' ),
			);
		}

		if ( $record ) {
			self::record_scan( $post->ID );
		}

		return array(
			'status'  => 'found',
			'site_id' => $site_id,
			'post'    => $post,
			'url'     => self::target_url( $post, $site_id ),
		);
	}

	/**
	 * Find the site carrying this Site ID, any status — so a pending/draft
	 * site is a distinguishable "unpublished" outcome rather than "unknown".
	 *
	 * @param string $site_id Normalised id.
	 * @return WP_Post|null
	 */
	protected static function find_site( $site_id ) {
		$posts = get_posts(
			array(
				'post_type'      => 'site',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => 'site_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small dataset, matches the existing importer lookup pattern.
				'meta_value'     => $site_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		return $posts ? $posts[0] : null;
	}

	/* =====================================================================
	 * Target resolution — the one place a repoint happens
	 * ===================================================================== */

	/**
	 * Where a published site's plaque redirects to.
	 *
	 * Empty base (the default) means "the site's own WordPress page" — today's
	 * behaviour, unchanged. Setting `cha_short_link_base` repoints every
	 * plaque at once (e.g. to the app's deep-link host) with no code change
	 * and no zip upload, which matters because this plugin has no CI/CD.
	 * `cha_short_link_target` still wins for a single-site override.
	 *
	 * A base containing the literal token `{id}` has the (urlencoded) site id
	 * substituted in place — the only way to express a query-string target
	 * such as `https://app.example/?site={id}` (the app's deep link route),
	 * since a plain path-append cannot put anything after a `?`. A base
	 * without `{id}` keeps the original append-to-path behaviour exactly, so
	 * every base already in use (including the empty default) is unaffected.
	 *
	 * @param WP_Post $post    The published site.
	 * @param string  $site_id Normalised id.
	 * @return string
	 */
	public static function target_url( $post, $site_id ) {
		$base = apply_filters( 'cha_short_link_base', get_option( self::OPTION_BASE, '' ), $site_id, $post );
		$base = is_string( $base ) ? trim( $base ) : '';

		if ( '' === $base ) {
			$url = get_permalink( $post );
		} elseif ( false !== strpos( $base, '{id}' ) ) {
			$url = str_replace( '{id}', rawurlencode( $site_id ), $base );
		} else {
			$url = untrailingslashit( $base ) . '/' . $site_id;
		}

		return apply_filters( 'cha_short_link_target', $url, $site_id, $post );
	}

	/**
	 * Let dispatch()'s wp_safe_redirect() actually redirect to the configured
	 * base when it's a different host from this WordPress site — e.g. the
	 * app's own origin. Only the base option's host is allowlisted; a
	 * `cha_short_link_target` override pointing at some other third host is
	 * that filter's own responsibility to also allow, the same way any other
	 * plugin's wp_safe_redirect() target would be.
	 *
	 * @param string[] $hosts Already-allowed hosts.
	 * @return string[]
	 */
	public static function allow_base_redirect_host( $hosts ) {
		$base = get_option( self::OPTION_BASE, '' );
		if ( ! is_string( $base ) || '' === trim( $base ) ) {
			return $hosts;
		}

		$host = wp_parse_url( trim( $base ), PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) {
			$hosts[] = $host;
		}

		return $hosts;
	}

	/**
	 * Where an unknown or unpublished id lands. Never a 404 — a plaque must
	 * not dead-end. Resolved by slug, not a hardcoded page id, so a rebuilt
	 * page still works; falls back to the site root if even that is gone.
	 *
	 * @param string $site_id Normalised id (may be '').
	 * @param string $reason  'unknown' | 'unpublished'.
	 * @return string
	 */
	public static function fallback_url( $site_id, $reason ) {
		$page = get_page_by_path( 'trail', OBJECT, 'page' );
		$base = $page ? get_permalink( $page ) : home_url( '/' );

		$url = add_query_arg(
			array(
				'plaque'    => $site_id,
				'cha_note' => $reason,
			),
			$base
		);

		return apply_filters( 'cha_short_link_fallback', $url, $site_id, $reason );
	}

	/**
	 * The trail-index note for an unknown/unpublished plaque scan — the
	 * `?plaque=…&cha_note=…` pair set by fallback_url(). Fails closed: an
	 * unrecognised reason value prints nothing rather than echo an arbitrary
	 * query string back at the visitor.
	 */
	public static function render_fallback_note() {
		if ( ! isset( $_GET['cha_note'], $_GET['plaque'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, informational query args set by our own redirect, not a form submission.
			return;
		}

		$reason = sanitize_key( wp_unslash( $_GET['cha_note'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$plaque = sanitize_text_field( wp_unslash( $_GET['plaque'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( '' === $plaque ) {
			return;
		}

		if ( 'unpublished' === $reason ) {
			$message = sprintf(
				/* translators: %s: the Site ID printed on the plaque. */
				__( 'The site for plaque %s isn\'t published yet. Here is the full Heritage Trail in the meantime.', 'cha' ),
				strtoupper( $plaque )
			);
		} elseif ( 'unknown' === $reason ) {
			$message = sprintf(
				/* translators: %s: the Site ID printed on the plaque. */
				__( 'We couldn\'t find a site for plaque %s. Here is the full Heritage Trail instead.', 'cha' ),
				strtoupper( $plaque )
			);
		} else {
			return; // Unrecognised reason — say nothing rather than guess.
		}

		printf( '<div class="cha-plaque-note"><p>%s</p></div>', esc_html( $message ) );
	}

	/* =====================================================================
	 * Scan counter — POPIA-clean: a count and a date, nothing else
	 * ===================================================================== */

	/**
	 * Increment one site's scan count and stamp today's date. No IP, no user
	 * agent, no cookie, no time-of-day — a count and a date is all the
	 * Association needs and all POPIA wants us holding.
	 *
	 * The count is a single conditional UPDATE (same idiom as
	 * CHA_Redeem::claim_stock()), not a read-then-write pair — a coach party
	 * scanning the same plaque within the same second must not undercount.
	 * metadata_exists() seeds the row first for exactly the reason documented
	 * there: usedCount-style meta with a registered default would otherwise
	 * make the UPDATE match zero rows on a site's very first scan.
	 *
	 * @param int $post_id Site post ID.
	 */
	protected static function record_scan( $post_id ) {
		global $wpdb;

		if ( ! metadata_exists( 'post', $post_id, self::META_COUNT ) ) {
			add_post_meta( $post_id, self::META_COUNT, '0', true );
		}

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta}
				    SET meta_value = CAST(meta_value AS UNSIGNED) + 1
				  WHERE post_id = %d
				    AND meta_key = %s",
				$post_id,
				self::META_COUNT
			)
		);

		update_post_meta( $post_id, self::META_LAST, current_time( 'Y-m-d' ) );
	}

	/**
	 * Remember an id that matched no site — a mis-etched plaque is exactly
	 * worth knowing about. Capped at MISS_CAP distinct keys so a crawler
	 * hammering random paths cannot grow this option without bound; once
	 * capped, only ids already being tracked keep counting.
	 *
	 * @param string $site_id Normalised id (may be '').
	 */
	protected static function record_miss( $site_id ) {
		$misses = get_option( self::OPTION_MISSES, array() );
		if ( ! is_array( $misses ) ) {
			$misses = array();
		}

		if ( ! isset( $misses[ $site_id ] ) && count( $misses ) >= self::MISS_CAP ) {
			return;
		}

		$misses[ $site_id ] = ( isset( $misses[ $site_id ] ) ? (int) $misses[ $site_id ] : 0 ) + 1;
		update_option( self::OPTION_MISSES, $misses, false ); // not autoloaded — this is diagnostics, not a hot path.
	}

	/* =====================================================================
	 * Dispatch — the thin, untested-by-design wrapper (see
	 * CHA_Voucher_Metrics::export_csv() for the same split: logic is
	 * tested, the exit()-terminated wrapper around it is verified live).
	 * ===================================================================== */

	/**
	 * Send the redirect and stop execution.
	 *
	 * @param string $raw Raw id from the URL.
	 */
	public static function dispatch( $raw ) {
		$result = self::resolve( $raw );

		self::send_nocache_headers();
		// Scoped to this single call — see the comment in init() for why
		// this is not a standing filter registration.
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_base_redirect_host' ) );
		wp_safe_redirect( $result['url'], self::REDIRECT_STATUS );
		remove_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_base_redirect_host' ) );
		exit;
	}

	/**
	 * A changed target must take effect on the very next scan, not whenever
	 * a cache entry happens to expire — that is the whole reason this is a
	 * 302 rather than a 301. `nocache_headers()` sends the standard
	 * no-store/no-cache/must-revalidate trio; DONOTCACHEPAGE and the
	 * dedicated LiteSpeed action are the two conventions LiteSpeed Cache
	 * itself documents for a plugin to force a page to bypass its cache
	 * (LiteSpeed Cache is active on production — see .env/host notes).
	 * Deliberately no raw header() call here: everything below is a
	 * WordPress-pluggable function or a safe no-op action, which is what
	 * keeps this method callable directly from the test suite.
	 */
	public static function send_nocache_headers() {
		nocache_headers();
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		do_action( 'litespeed_control_set_nocache', 'CHA short link' );
	}

	/* =====================================================================
	 * Shared data — one source for the admin screen, the CSV, and WP-CLI
	 * ===================================================================== */

	/**
	 * Every site carrying a Site ID, with its resolved short URL, current
	 * target, and scan stats. Every site is included regardless of post
	 * status — a pending site's plaque still needs manufacturing, its
	 * unpublished state is just flagged rather than silently dropped.
	 *
	 * @return array[] Rows: site_id, name, short_url, target, published, scans, last.
	 */
	public static function plaque_rows() {
		$posts = get_posts(
			array(
				'post_type'      => 'site',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => 'site_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small dataset (dozens of sites).
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
			)
		);

		$rows = array();
		foreach ( $posts as $post ) {
			$site_id = (string) get_post_meta( $post->ID, 'site_id', true );
			if ( '' === $site_id ) {
				continue;
			}

			$published = ( 'publish' === $post->post_status );

			$rows[] = array(
				'post_id'   => $post->ID,
				'site_id'   => $site_id,
				'name'      => $post->post_title,
				'short_url' => home_url( '/s/' . $site_id ),
				'target'    => $published ? self::target_url( $post, $site_id ) : self::fallback_url( $site_id, 'unpublished' ),
				'published' => $published,
				'scans'     => (int) get_post_meta( $post->ID, self::META_COUNT, true ),
				'last'      => (string) get_post_meta( $post->ID, self::META_LAST, true ),
			);
		}
		return $rows;
	}

	/**
	 * Write the manufacturing CSV — site_id, site name, short URL — to an
	 * open stream. Shared by the admin download and `wp cha short-links csv`
	 * so there is exactly one definition of what the handover file contains.
	 *
	 * @param resource $handle Open, writable stream.
	 * @param array[]  $rows   From plaque_rows().
	 */
	public static function write_csv_rows( $handle, $rows ) {
		fputcsv( $handle, array( 'Site ID', 'Site Name', 'Short URL' ) );
		foreach ( $rows as $row ) {
			fputcsv( $handle, array( $row['site_id'], $row['name'], $row['short_url'] ) );
		}
	}

	/* =====================================================================
	 * Admin screen — Heritage Sites → Plaque Links (read-only)
	 * ===================================================================== */

	/**
	 * Add the screen under the Heritage Sites menu, same placement pattern
	 * as CHA_Voucher_Metrics under Partners.
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=site',
			__( 'Plaque Links', 'cha' ),
			__( 'Plaque Links', 'cha' ),
			'manage_options',
			self::ADMIN_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Page URL.
	 *
	 * @return string
	 */
	protected static function page_url() {
		return admin_url( 'edit.php?post_type=site&page=' . self::ADMIN_SLUG );
	}

	/**
	 * Render the read-only Plaque Links screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$rows = self::plaque_rows();
		$base = get_option( self::OPTION_BASE, '' );
		$misses = get_option( self::OPTION_MISSES, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Plaque Links', 'cha' ); ?></h1>

			<div style="margin-bottom: 20px;">
				<p>
					<strong><?php esc_html_e( 'Redirect base:', 'cha' ); ?></strong>
					<?php
					if ( '' !== $base ) {
						echo esc_html( $base );
					} else {
						esc_html_e( '(none — every plaque resolves to its own website page)', 'cha' );
					}
					?>
				</p>
				<p class="description">
					<?php esc_html_e( 'Set with WP-CLI: wp cha short-links base <url> (and --unset to clear it). Repoints every plaque at once — no code change, no zip upload. Include {id} in the url to build a query string (e.g. https://app.example.com/?site={id}) instead of the default path-append.', 'cha' ); ?>
				</p>
				<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'export_csv', '_wpnonce' => wp_create_nonce( 'cha_export_short_links_csv' ) ), self::page_url() ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Download CSV', 'cha' ); ?>
				</a>
			</div>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Site ID', 'cha' ); ?></th>
						<th><?php esc_html_e( 'Name', 'cha' ); ?></th>
						<th><?php esc_html_e( 'Short URL', 'cha' ); ?></th>
						<th><?php esc_html_e( 'Currently resolves to', 'cha' ); ?></th>
						<th style="text-align: center;"><?php esc_html_e( 'Scans', 'cha' ); ?></th>
						<th><?php esc_html_e( 'Last scanned', 'cha' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="6" style="text-align: center; padding: 20px;">
								<?php esc_html_e( 'No sites carry a Site ID yet.', 'cha' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $row['post_id'] ) ); ?>">
										<?php echo esc_html( $row['site_id'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><code><?php echo esc_html( $row['short_url'] ); ?></code></td>
								<td>
									<?php if ( ! $row['published'] ) : ?>
										<span style="color:#a00;"><?php esc_html_e( 'Not published — trail index fallback', 'cha' ); ?></span>
									<?php else : ?>
										<?php echo esc_html( $row['target'] ); ?>
									<?php endif; ?>
								</td>
								<td style="text-align: center;"><?php echo esc_html( $row['scans'] ); ?></td>
								<td><?php echo esc_html( '' !== $row['last'] ? $row['last'] : '—' ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( ! empty( $misses ) && is_array( $misses ) ) : ?>
				<h2><?php esc_html_e( 'Unrecognised scans', 'cha' ); ?></h2>
				<p class="description"><?php esc_html_e( 'IDs that were scanned but match no site — most likely a mis-etched plaque.', 'cha' ); ?></p>
				<table class="widefat striped" style="max-width: 400px;">
					<thead><tr><th><?php esc_html_e( 'Scanned as', 'cha' ); ?></th><th><?php esc_html_e( 'Count', 'cha' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $misses as $miss_id => $count ) : ?>
							<tr><td><?php echo esc_html( '' !== $miss_id ? $miss_id : '(empty)' ); ?></td><td><?php echo esc_html( $count ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/* =====================================================================
	 * CSV export — admin download
	 * ===================================================================== */

	/**
	 * Handle the CSV export request.
	 */
	public static function handle_csv_export() {
		if ( ! isset( $_GET['page'] ) || self::ADMIN_SLUG !== $_GET['page'] ) {
			return;
		}
		if ( ! isset( $_GET['action'] ) || 'export_csv' !== $_GET['action'] ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}
		check_admin_referer( 'cha_export_short_links_csv' );

		self::export_csv();
	}

	/**
	 * Stream the CSV: site_id, site name, short URL — the plaque-manufacturing
	 * handover file. Thin by design; write_csv_rows() carries the logic.
	 */
	protected static function export_csv() {
		$rows = self::plaque_rows();

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="cha-plaque-short-links-' . gmdate( 'Y-m-d' ) . '.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // BOM for Excel UTF-8.
		self::write_csv_rows( $output, $rows );
		fclose( $output );
		exit;
	}

	/* =====================================================================
	 * Rewrite health check — this plugin is normally deployed by replacing
	 * files directly, which never fires the activation hook, so the route
	 * can silently go missing after an upload. A guarded one-shot flush
	 * lives in the main plugin file (cha_maybe_flush_short_link_rewrites);
	 * this is the ongoing check for a later regression after that has
	 * already run once.
	 * ===================================================================== */

	/**
	 * Whether the rewrite rule is actually registered right now.
	 *
	 * @return bool
	 */
	public static function rewrite_rule_present() {
		$rules = get_option( 'rewrite_rules' );
		if ( ! is_array( $rules ) ) {
			return false;
		}
		foreach ( $rules as $target ) {
			if ( false !== strpos( (string) $target, self::QUERY_VAR ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Admin notice with a one-click fix when the route has gone missing.
	 */
	public static function rewrite_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( self::rewrite_rule_present() ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Plaque short links (/s/…) are not registered.', 'cha' ); ?></strong>
				<?php esc_html_e( 'This plugin is usually deployed by replacing files directly, which never re-runs the step that registers this route. Every QR-coded plaque depends on it.', 'cha' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cha_short_links_flush' ); ?>
				<input type="hidden" name="action" value="cha_short_links_flush">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Fix now', 'cha' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Re-flush from the notice's button.
	 */
	public static function handle_flush_action() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'cha' ) );
		}
		check_admin_referer( 'cha_short_links_flush' );

		flush_rewrite_rules();

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/* =====================================================================
	 * WP-CLI — `wp cha short-links <status|base|csv>`
	 * ===================================================================== */

	/**
	 * `wp cha short-links status|base <url>|base --unset|csv [--file=<path>]`.
	 *
	 * No admin field sets the base deliberately — repointing every plaque at
	 * once is a decision worth a deliberate command, not a form field an
	 * editor could brush against.
	 *
	 * `<url>` may contain the literal placeholder `{id}`, substituted with the
	 * (urlencoded) site id — the only way to point at a query-string target
	 * such as the app's deep link (`https://app.example.com/?site={id}`).
	 * Without `{id}`, `<url>` is append-to-path, unchanged from before.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public static function cli( $args, $assoc_args = array() ) {
		$sub = isset( $args[0] ) ? $args[0] : 'status';

		if ( 'base' === $sub ) {
			if ( isset( $assoc_args['unset'] ) ) {
				delete_option( self::OPTION_BASE );
				WP_CLI::success( 'Short-link base cleared — plaques resolve to each site\'s own page again.' );
				return;
			}
			$url = isset( $args[1] ) ? trim( $args[1] ) : '';
			if ( '' === $url ) {
				WP_CLI::error( 'Usage: wp cha short-links base <url> (may include {id} as a placeholder for the site id, e.g. https://app.example.com/?site={id})   |   wp cha short-links base --unset' );
				return;
			}
			update_option( self::OPTION_BASE, $url, false );
			WP_CLI::success( sprintf( 'Short-link base set to %s — every plaque now resolves under it.', $url ) );
			return;
		}

		if ( 'csv' === $sub ) {
			$rows = self::plaque_rows();
			$file = isset( $assoc_args['file'] ) ? $assoc_args['file'] : '';

			$handle = ( '' !== $file ) ? fopen( $file, 'w' ) : STDOUT;
			if ( ! $handle ) {
				WP_CLI::error( 'Could not open ' . $file . ' for writing.' );
				return;
			}
			self::write_csv_rows( $handle, $rows );
			if ( '' !== $file ) {
				fclose( $handle );
				WP_CLI::success( sprintf( 'Wrote %d plaque(s) to %s', count( $rows ), $file ) );
			}
			return;
		}

		// status (default)
		$rows = self::plaque_rows();
		$base = get_option( self::OPTION_BASE, '' );

		WP_CLI::log( sprintf( 'Short-link base: %s', '' !== $base ? $base : '(none — each plaque resolves to its own page)' ) );
		WP_CLI::log( sprintf( '%d site(s) carry a Site ID.', count( $rows ) ) );

		$unpublished = array_filter(
			$rows,
			static function ( $row ) {
				return ! $row['published'];
			}
		);
		if ( $unpublished ) {
			WP_CLI::warning(
				sprintf(
					'%d not published — their plaques will show the trail-index fallback: %s',
					count( $unpublished ),
					implode( ', ', array_column( $unpublished, 'site_id' ) )
				)
			);
		}

		$misses = get_option( self::OPTION_MISSES, array() );
		if ( ! empty( $misses ) && is_array( $misses ) ) {
			WP_CLI::warning( sprintf( '%d distinct unrecognised id(s) scanned — see Heritage Sites → Plaque Links.', count( $misses ) ) );
		}
	}
}
