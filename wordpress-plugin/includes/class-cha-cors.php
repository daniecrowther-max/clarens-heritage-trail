<?php
/**
 * CORS for the cha/v1 REST namespace.
 *
 * The PWA lives on its own host (trail.clarensheritage.org, Cloudflare Workers
 * Static Assets) while the REST API stays on the WordPress domain — a
 * cross-origin pair. Without an explicit Access-Control-Allow-Origin the
 * browser discards every response and the app renders nothing.
 *
 * Scope is deliberately narrow:
 *   - Only routes under CHA_REST_NAMESPACE get headers. The rest of the WP
 *     REST API (wp/v2, users, other plugins) is untouched.
 *   - There is no webhook route: /verify-token calls Paystack's Verify
 *     Transaction API directly and synchronously, so every cha/v1 route that
 *     exists is browser-facing and needs a CORS decision.
 *   - Access-Control-Allow-Credentials is NOT sent. The app authenticates with
 *     an opaque token in the query string or POST body, never a cookie, so the
 *     browser must not attach WP's auth cookies to these requests. Omitting the
 *     header means a hostile page still cannot ride a logged-in admin's session.
 *
 * We must actively DISPLACE WordPress core's own CORS headers on our routes.
 * Core's rest_send_cors_headers() (rest_pre_serve_request, priority 10) echoes
 * back whatever Origin the caller sent — any origin at all — together with
 * `Access-Control-Allow-Credentials: true`. That is deliberate upstream (the
 * REST API leans on nonce checks rather than origin checks), but it means an
 * allowlist that only *adds* headers denies nothing: core has already said yes
 * to everyone. Verified against a real WP install — a request to verify-token
 * carrying `Origin: https://evil.example` came back with that origin echoed and
 * credentials allowed.
 *
 * So on cha/v1 routes we header_remove() core's three CORS headers and then
 * emit our own. header_remove() rather than remove_filter( 'rest_pre_serve_request',
 * 'rest_send_cors_headers' ) because it does not depend on core keeping that
 * function name or priority: if upstream renames it, a remove_filter() would
 * silently no-op and quietly restore the reflect-anything behaviour, whereas
 * stripping the headers by name keeps working. Nothing is flushed at this point
 * in the request, so removal is safe. Routes outside our namespace keep core's
 * default behaviour untouched.
 *
 * Two header strategies, because caching differs per route:
 *
 *   1. cha/v1/content — public, identical for every caller, and deliberately
 *      left cacheable (see CHA_Paystack::no_cache_auth_routes). It gets a FIXED
 *      Access-Control-Allow-Origin naming the app origin, never an echo of the
 *      request's Origin. A cache in front of it (LiteSpeed here) keys on URL and
 *      does not reliably honour `Vary: Origin`, so an echoed header would let
 *      the first caller's origin get stored and replayed to everyone else —
 *      breaking the app whenever a non-app request warmed the cache first. A
 *      constant header is the same bytes for every requester, so it survives
 *      any cache. Same-origin callers ignore ACAO entirely, so naming the app
 *      origin here costs the WordPress site nothing.
 *
 *   2. verify-token / checkout / redeem / voucher-status — already forced
 *      no-store by CHA_Paystack, so echoing is cache-safe. These match the
 *      request Origin against an allowlist and echo it back on a hit, which
 *      lets extra origins (staging, a *.pages.dev preview build) work without
 *      touching the feed's fixed header.
 *
 * Preflight: WP core answers OPTIONS itself via rest_handle_options_request()
 * on rest_pre_dispatch, and rest_pre_serve_request still fires for that
 * response — so hooking the one filter covers both the preflight and the real
 * request.
 *
 * Configure origins without editing this file via CHA_APP_ORIGIN in .env or
 * wp-config.php — comma-separated; the first entry is the app origin used for
 * the content feed's fixed header.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Cors {

	/**
	 * Production origin of the PWA. Overridable via CHA_APP_ORIGIN.
	 */
	const DEFAULT_APP_ORIGIN = 'https://trail.clarensheritage.org';

	/**
	 * Routes that are safe to echo an origin on, because they are never cached.
	 * Kept in step with CHA_Paystack::no_cache_auth_routes().
	 *
	 * @return string[]
	 */
	protected static function echo_routes() {
		return array(
			'/' . CHA_REST_NAMESPACE . '/verify-token',
			'/' . CHA_REST_NAMESPACE . '/checkout',
			'/' . CHA_REST_NAMESPACE . '/redeem',
			'/' . CHA_REST_NAMESPACE . '/voucher-status',
		);
	}

	/**
	 * Hook the header filter.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'hook_headers' ), 15 );
	}

	/**
	 * Attach the CORS filter to the REST serve path.
	 */
	public static function hook_headers() {
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'send_cors_headers' ), 10, 4 );
	}

	/**
	 * Configured app origins, in order. First entry wins for the content feed.
	 *
	 * @return string[] Normalized origins.
	 */
	protected static function app_origins() {
		$configured = '';
		if ( class_exists( 'CHA_Env' ) ) {
			$configured = CHA_Env::get( 'CHA_APP_ORIGIN', '' );
		}

		$origins = array();
		if ( '' !== $configured ) {
			foreach ( explode( ',', $configured ) as $origin ) {
				$origin = self::normalize( $origin );
				if ( '' !== $origin ) {
					$origins[] = $origin;
				}
			}
		}
		if ( empty( $origins ) ) {
			$origins[] = self::normalize( self::DEFAULT_APP_ORIGIN );
		}

		return $origins;
	}

	/**
	 * The single origin named in the content feed's fixed ACAO header.
	 *
	 * @return string
	 */
	public static function primary_app_origin() {
		$origins = self::app_origins();

		/**
		 * Filter the origin advertised on the public content feed.
		 *
		 * @param string $origin Normalized scheme://host[:port].
		 */
		return apply_filters( 'cha_primary_app_origin', $origins[0] );
	}

	/**
	 * Origins allowed to call the token/payment routes.
	 *
	 * Includes the site's own origin so a same-origin caller keeps working if
	 * the app is ever served from both hosts during a cutover.
	 *
	 * @return string[] Normalized origins.
	 */
	public static function allowed_origins() {
		$origins = self::app_origins();

		$home = wp_parse_url( home_url() );
		if ( ! empty( $home['scheme'] ) && ! empty( $home['host'] ) ) {
			$site_origin = $home['scheme'] . '://' . $home['host'];
			if ( ! empty( $home['port'] ) ) {
				$site_origin .= ':' . $home['port'];
			}
			$site_origin = self::normalize( $site_origin );
			if ( '' !== $site_origin ) {
				$origins[] = $site_origin;
			}
		}

		$origins = array_values( array_unique( $origins ) );

		/**
		 * Filter the cha/v1 CORS allowlist.
		 *
		 * @param string[] $origins Normalized scheme://host[:port] strings.
		 */
		return apply_filters( 'cha_allowed_origins', $origins );
	}

	/**
	 * Normalize an origin for comparison: trim, lower-case, drop trailing slash.
	 *
	 * Origins are compared byte-for-byte by the browser, so a stray slash or a
	 * capitalised host in config would silently fail to match.
	 *
	 * @param string $origin Raw origin.
	 * @return string Normalized origin, or '' if unusable.
	 */
	protected static function normalize( $origin ) {
		$origin = strtolower( trim( (string) $origin ) );
		$origin = untrailingslashit( $origin );
		if ( 0 !== strpos( $origin, 'http://' ) && 0 !== strpos( $origin, 'https://' ) ) {
			return '';
		}
		return $origin;
	}

	/**
	 * Does $route start with $prefix (exact segment or with a sub-path)?
	 *
	 * @param string $route  Route path.
	 * @param string $prefix Route prefix.
	 * @return bool
	 */
	protected static function route_matches( $route, $prefix ) {
		return $route === $prefix || 0 === strpos( $route, $prefix . '/' );
	}

	/**
	 * Send CORS headers on cha/v1 routes.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool Unchanged $served — this filter only emits headers.
	 */
	public static function send_cors_headers( $served, $result, $request, $server ) {
		if ( ! ( $request instanceof WP_REST_Request ) ) {
			return $served;
		}

		$route = $request->get_route();

		// The public feed: fixed header, cache-safe, no Vary.
		if ( self::route_matches( $route, '/' . CHA_REST_NAMESPACE . '/content' ) ) {
			self::displace_core_headers();
			self::emit( self::primary_app_origin() );
			return $served;
		}

		// Token/payment routes: echo an allowlisted origin. Safe to Vary here —
		// these responses are already no-store.
		foreach ( self::echo_routes() as $prefix ) {
			if ( ! self::route_matches( $route, $prefix ) ) {
				continue;
			}

			self::displace_core_headers();
			header( 'Vary: Origin', false );

			$origin = self::normalize( get_http_origin() );
			if ( '' !== $origin && in_array( $origin, self::allowed_origins(), true ) ) {
				self::emit( $origin );
			}
			return $served;
		}

		return $served;
	}

	/**
	 * Strip core's reflect-any-origin CORS headers so our allowlist is the only
	 * thing granting access on this route. See the class docblock.
	 */
	protected static function displace_core_headers() {
		header_remove( 'Access-Control-Allow-Origin' );
		header_remove( 'Access-Control-Allow-Methods' );
		header_remove( 'Access-Control-Allow-Credentials' );
	}

	/**
	 * Emit the CORS header set for one permitted origin.
	 *
	 * @param string $origin Normalized origin.
	 */
	protected static function emit( $origin ) {
		if ( '' === $origin ) {
			return;
		}
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Content-Type' );
		header( 'Access-Control-Max-Age: 600' );
	}
}
