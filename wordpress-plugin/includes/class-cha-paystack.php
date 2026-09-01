<?php
/**
 * Paystack integration — checkout + verify-token + webhook.
 *
 * Flow:
 *   1. App POSTs {email} to cha/v1/checkout — NO amount from the client.
 *   2. Server mints an opaque purchase token, reads the price from settings,
 *      stores the pending purchase, then — only once that row is confirmed
 *      written — calls Paystack's Initialize Transaction and returns the
 *      payment URL. That order is deliberate: no payment URL may exist for a
 *      reference the verify/webhook paths cannot resolve to a purchase.
 *   3. App polls/redirects to cha/v1/verify-token, which calls Paystack's
 *      Verify Transaction API directly and synchronously.
 *   4. In parallel, Paystack POSTs cha/v1/paystack-webhook on charge.success —
 *      the fallback for a buyer who paid but never returned to /verify-token
 *      (closed the tab, lost connectivity mid-redirect). Both paths converge
 *      on the same verify_and_mark_paid(), and CHA_Purchases::mark_paid()'s
 *      conditional UPDATE means whichever one gets there first wins the
 *      pending → paid transition and sends the one token email.
 *
 * Secrets come from .env only (CHA_Env). The amount is server-authoritative:
 * an intercepted /checkout request cannot change what the buyer is charged.
 * The webhook is authenticated via the X-Paystack-Signature header (HMAC
 * SHA512 of the raw body, keyed with the same secret key) rather than a
 * separate webhook secret — Paystack doesn't issue one.
 *
 * @see https://paystack.com/docs/payments/split-payments/
 * @see https://paystack.com/docs/api/transaction/
 * @see https://paystack.com/docs/payments/webhooks/
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Paystack {

	const API_BASE = 'https://api.paystack.co';

	/**
	 * Hook route registration.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Auth/payment responses must never be cached (see no_cache_auth_routes).
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_cache_auth_routes' ), 10, 3 );
	}

	/**
	 * Force no-cache on the auth/payment endpoints.
	 *
	 * A cached auth response is a security bug: a revoked/expired token must
	 * never keep validating from a stale cache. Sends standard no-store/no-cache
	 * plus the explicit `X-LiteSpeed-Cache-Control: no-cache` directive and
	 * DONOTCACHEPAGE. The content feed (cha/v1/content) is deliberately NOT
	 * listed — it stays cacheable for performance.
	 *
	 * @param mixed           $response Response (WP_REST_Response|WP_HTTP_Response|WP_Error).
	 * @param WP_REST_Server  $server   REST server.
	 * @param WP_REST_Request $request  Request.
	 * @return mixed
	 */
	public static function no_cache_auth_routes( $response, $server, $request ) {
		$route    = $request->get_route();
		$prefixes = array(
			'/' . CHA_REST_NAMESPACE . '/verify-token',
			'/' . CHA_REST_NAMESPACE . '/checkout',
			'/' . CHA_REST_NAMESPACE . '/redeem',
			'/' . CHA_REST_NAMESPACE . '/voucher-status',
			'/' . CHA_REST_NAMESPACE . '/paystack-webhook',
		);

		$match = false;
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $route, $prefix ) ) {
				$match = true;
				break;
			}
		}
		if ( ! $match ) {
			return $response;
		}

		if ( is_object( $response ) && method_exists( $response, 'header' ) ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
			$response->header( 'Expires', '0' );
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}

		return $response;
	}

	/**
	 * Register the payment REST routes.
	 */
	public static function register_routes() {
		register_rest_route(
			CHA_REST_NAMESPACE,
			'/checkout',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'checkout' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
						'validate_callback' => function ( $v ) {
							return is_email( $v );
						},
					),
				),
			)
		);

		register_rest_route(
			CHA_REST_NAMESPACE,
			'/verify-token',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'verify_token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			CHA_REST_NAMESPACE,
			'/paystack-webhook',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook' ),
				// Auth is the HMAC signature check inside webhook(), not a WP capability —
				// Paystack's servers call this directly, with no WP session/cookie.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Paystack secret key from .env.
	 *
	 * @return string
	 */
	protected static function secret_key() {
		return CHA_Env::get( 'PAYSTACK_SECRET_KEY', '' );
	}

	/**
	 * REST: POST cha/v1/checkout.
	 *
	 * Rate-limited per client IP and per normalised email (see
	 * checkout_rate_limited) so a script cannot mint unbounded Paystack
	 * transactions, and the durable `pending` purchase row is written BEFORE
	 * Paystack is called — a buyer must never receive a payment URL for a
	 * purchase that /verify-token and the webhook have nothing to match on.
	 *
	 * @param WP_REST_Request $request Request ({ email }).
	 * @return WP_REST_Response
	 */
	public static function checkout( WP_REST_Request $request ) {
		$email = $request->get_param( 'email' );

		if ( self::checkout_rate_limited( $email ) ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'error'   => 'rate_limited',
					'message' => 'Too many payment attempts. Please wait a few minutes and try again.',
				),
				429
			);
		}

		$secret_key = self::secret_key();
		if ( '' === $secret_key ) {
			error_log( '[CHA Paystack] PAYSTACK_SECRET_KEY not configured in .env.' );
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'Payment service temporarily unavailable. Please try again.',
				),
				200
			);
		}

		// Price is server-authoritative. The app never sends an amount.
		$amount_cents = CHA_Settings::unlock_price_cents();

		$token     = CHA_Tokens::generate();
		$reference = 'CHA-' . $token;

		// Durable purchase state FIRST. Paystack is only asked to create a
		// transaction once the row this reference will be verified against is
		// confirmed to exist — otherwise a failed insert would still hand the
		// buyer a working payment URL and take money that neither
		// /verify-token nor the webhook could ever resolve to a token.
		if ( false === CHA_Purchases::insert_pending( $email, $token, $reference, $amount_cents ) ) {
			error_log( '[CHA Paystack] insert_pending FAILED for ' . $reference . ' — no Paystack transaction created. Buyer was not charged.' );
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'Could not start payment. Please try again in a moment.',
				),
				500
			);
		}

		$response = wp_remote_post(
			self::API_BASE . '/transaction/initialize',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'email'        => $email,
						'amount'       => $amount_cents,
						'currency'     => 'ZAR',
						'reference'    => $reference,
						// Board-approved (Aug 2026): CHA gets 80% of revenue and Paystack's
						// transaction fee is split proportionally between the main account and
						// the CHA subaccount by their revenue share. This requires Paystack's
						// Transaction Split feature (a split_code from a Split Group in the
						// dashboard) rather than a bare `subaccount` code — passing `subaccount`
						// + `bearer_type` directly to /transaction/initialize silently ignores
						// `bearer_type` (it's only recognised on the /split endpoint), so the fee
						// was still being borne 100% by the main account. Fixed 1 Sep 2026.
						'split_code'   => CHA_Env::get( 'PAYSTACK_SPLIT_CODE', '' ),
						'callback_url' => CHA_Env::get( 'CHECKOUT_REDIRECT_URL', CHA_Cors::primary_app_origin() ),
					)
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[CHA Paystack] initialize request failed: ' . $response->get_error_message() );
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'Could not create payment. Please try again.',
				),
				200
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['status'] ) || empty( $body['data']['authorization_url'] ) ) {
			$msg = isset( $body['message'] ) ? $body['message'] : ( 'HTTP ' . $http_code );
			error_log( '[CHA Paystack] initialize failed for ' . $reference . ': ' . $msg );
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'message' => 'Could not create payment. Please try again.',
				),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'ok'          => true,
				'payment_url' => $body['data']['authorization_url'],
			),
			200
		);
	}

	/**
	 * REST: GET cha/v1/verify-token?token=…
	 *
	 * Server-authoritative for ALL types (no secret/hash in the app). Purchase
	 * tokens are valid only when their purchase row is paid — checked against
	 * Paystack's Verify Transaction API synchronously the first time, then
	 * served straight from the DB on every call after (no repeat Paystack
	 * call once a purchase is marked paid). Promo/admin tokens are valid only
	 * when active and unexpired. Rate-limited per IP.
	 *
	 * @param WP_REST_Request $request Request ({ token }).
	 * @return WP_REST_Response
	 */
	public static function verify_token( WP_REST_Request $request ) {
		if ( self::rate_limited() ) {
			return new WP_REST_Response( array( 'valid' => false, 'error' => 'rate_limited' ), 429 );
		}

		$token = (string) $request->get_param( 'token' );
		$r     = self::resolve_token( $token );

		if ( $r['valid'] && in_array( $r['type'], array( 'promo', 'admin' ), true ) ) {
			CHA_Tokens::touch( strtoupper( trim( $token ) ) );
		}

		return new WP_REST_Response( $r, 200 );
	}

	/**
	 * Resolve any unlock token to { valid, type, paid }. Shared by verify-token
	 * and redeem.
	 *
	 * @param string $token Opaque token.
	 * @return array{valid:bool,type:?string,paid:?bool}
	 */
	public static function resolve_token( $token ) {
		$token = strtoupper( trim( (string) $token ) );
		if ( '' === $token ) {
			return array( 'valid' => false, 'type' => null, 'paid' => null );
		}

		// Purchase token → valid only when its purchase row is paid.
		$purchase = CHA_Purchases::find_by_token( $token );
		if ( $purchase ) {
			if ( 'paid' === $purchase->status ) {
				return array( 'valid' => true, 'type' => 'purchase', 'paid' => true );
			}

			$paid = ( 'paid' === self::verify_and_mark_paid( $purchase ) );
			return array( 'valid' => $paid, 'type' => 'purchase', 'paid' => $paid );
		}

		// Promo/admin token → valid only when active and unexpired.
		$res = CHA_Tokens::resolve( $token );
		if ( $res && 'active' === $res['status'] && ! $res['expired'] ) {
			return array( 'valid' => true, 'type' => $res['type'], 'paid' => null );
		}

		return array( 'valid' => false, 'type' => $res ? $res['type'] : null, 'paid' => null );
	}

	/**
	 * A pending purchase: call Paystack's Verify Transaction API and, on a
	 * genuine success for the right amount/currency, mark it paid (idempotent)
	 * and email the token. Public so both the redirect path (resolve_token)
	 * and the webhook/admin re-verify paths can call it directly.
	 *
	 * @param object $purchase Pending purchase row.
	 * @return string One of:
	 *   'paid'        - confirmed paid (just now, or already by a concurrent caller).
	 *   'pending'     - Paystack hasn't recorded a success yet; not an error.
	 *   'mismatch'    - Paystack's amount/currency doesn't match ours (logged loudly).
	 *   'unreachable' - the verify call itself failed (network/timeout).
	 *   'db_error'    - Paystack confirmed success but our DB write failed.
	 *   'no_secret'   - PAYSTACK_SECRET_KEY isn't configured.
	 */
	public static function verify_and_mark_paid( $purchase ) {
		$secret_key = self::secret_key();
		if ( '' === $secret_key ) {
			error_log( '[CHA Paystack] PAYSTACK_SECRET_KEY not configured in .env.' );
			return 'no_secret';
		}

		// 10s, not the 15s used elsewhere: Paystack allows ~30s per webhook
		// attempt for the whole request round-trip, so this call must leave
		// headroom rather than risk pushing the handler past their cutoff.
		$response = wp_remote_get(
			self::API_BASE . '/transaction/verify/' . rawurlencode( $purchase->reference ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $secret_key ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( '[CHA Paystack] verify request failed for ' . $purchase->reference . ': ' . $response->get_error_message() );
			return 'unreachable';
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$data = isset( $body['data'] ) ? $body['data'] : array();

		if ( empty( $body['status'] ) || ! isset( $data['status'] ) || 'success' !== $data['status'] ) {
			// Still pending (or failed/abandoned) on Paystack's side — not an error.
			return 'pending';
		}

		$amount_ok   = isset( $data['amount'] ) && (int) $data['amount'] === (int) $purchase->amount_cents;
		$currency_ok = isset( $data['currency'] ) && 'ZAR' === $data['currency'];

		if ( ! $amount_ok || ! $currency_ok ) {
			error_log(
				'[CHA Paystack] AMOUNT/CURRENCY MISMATCH for ' . $purchase->reference . ' — expected '
				. (int) $purchase->amount_cents . ' ZAR, Paystack reports ' . wp_json_encode( $data )
				. '. Purchase NOT marked paid. Investigate before assuming this is benign.'
			);
			return 'mismatch';
		}

		// mark_paid returns true only on the pending → paid transition it itself
		// won, so a repeat verify call (redirect racing the webhook, or a
		// replayed webhook) never re-sends the email.
		if ( CHA_Purchases::mark_paid( $purchase ) ) {
			$fresh = CHA_Purchases::find_by_token( $purchase->token );
			CHA_Purchases::send_token_email( $fresh ? $fresh : $purchase );
			return 'paid';
		}

		if ( CHA_Purchases::mark_paid_failed_due_to_db_error() ) {
			return 'db_error';
		}

		// Lost the pending → paid race to a concurrent caller — it's paid, just not by us.
		return 'paid';
	}

	/**
	 * REST: POST cha/v1/paystack-webhook. Paystack calls this on charge.success
	 * (and other events we don't act on). This is the fallback path for a buyer
	 * who paid but never returned to /verify-token (closed the tab, lost
	 * connectivity) — the webhook still lands the purchase as paid and sends
	 * the token email.
	 *
	 * Response codes matter here because Paystack retries a non-200 response
	 * (every 3 min x4, then hourly for 72h): 200 means "definitively resolved,
	 * do not retry" (including a resolved amount/currency mismatch — retrying
	 * a bug or an attack won't change the outcome); 500 means "transient,
	 * retry may succeed" (Paystack unreachable, DB write error, or — the one
	 * non-obvious case — our own verify call disagreeing with a charge.success
	 * event and reporting the transaction as still pending, which is a
	 * contradiction worth another look, not a resolution); 401 means the
	 * signature didn't check out.
	 *
	 * @param WP_REST_Request $request Raw webhook POST.
	 * @return WP_REST_Response
	 */
	public static function webhook( WP_REST_Request $request ) {
		$secret_key = self::secret_key();
		$raw_body   = $request->get_body();
		$signature  = $request->get_header( 'x-paystack-signature' );

		if ( '' === $secret_key || empty( $signature ) || ! hash_equals( hash_hmac( 'sha512', $raw_body, $secret_key ), $signature ) ) {
			error_log( '[CHA Paystack] webhook rejected: missing or invalid signature.' );
			return new WP_REST_Response( array( 'ok' => false, 'error' => 'invalid_signature' ), 401 );
		}

		$body  = json_decode( $raw_body, true );
		$event = isset( $body['event'] ) ? (string) $body['event'] : '';

		if ( 'charge.success' !== $event ) {
			// Event type we don't handle — resolved, nothing to retry.
			return new WP_REST_Response( array( 'ok' => true, 'handled' => false, 'event' => $event ), 200 );
		}

		$data      = isset( $body['data'] ) ? $body['data'] : array();
		$reference = isset( $data['reference'] ) ? (string) $data['reference'] : '';

		if ( '' === $reference ) {
			error_log( '[CHA Paystack] webhook charge.success with no reference in payload.' );
			return new WP_REST_Response( array( 'ok' => true, 'handled' => false, 'error' => 'no_reference' ), 200 );
		}

		$purchase = CHA_Purchases::find_by_reference( $reference );
		if ( ! $purchase ) {
			error_log( '[CHA Paystack] webhook: reference not found in purchases table: ' . $reference );
			return new WP_REST_Response( array( 'ok' => true, 'handled' => false, 'error' => 'reference_not_found' ), 200 );
		}

		if ( 'paid' === $purchase->status ) {
			// Idempotent no-op — a prior webhook delivery or the redirect path already resolved this.
			return new WP_REST_Response( array( 'ok' => true, 'handled' => true, 'status' => 'already_paid' ), 200 );
		}

		$result = self::verify_and_mark_paid( $purchase );

		switch ( $result ) {
			case 'paid':
			case 'mismatch':
				// Both are definitively resolved for THIS webhook delivery — retrying
				// won't change a final outcome, even the mismatch one.
				return new WP_REST_Response( array( 'ok' => true, 'handled' => true, 'status' => $result ), 200 );

			case 'pending':
				// Paystack sent us charge.success, but our own verify call just told
				// us the transaction isn't a success — that's a contradiction, not a
				// resolution (most likely Paystack's read-your-write consistency
				// lagging by a few seconds). A retry in 3 minutes will most likely
				// see it as success, so ask for one.
				error_log( '[CHA Paystack] webhook: charge.success event but verify says pending for ' . $purchase->reference . ' — asking Paystack to retry.' );
				return new WP_REST_Response( array( 'ok' => false, 'status' => 'pending' ), 500 );

			case 'unreachable':
			case 'db_error':
			case 'no_secret':
			default:
				return new WP_REST_Response( array( 'ok' => false, 'status' => $result ), 500 );
		}
	}

	/**
	 * Best-effort client IP for rate-limit bucketing.
	 *
	 * clarensheritage.org is proxied through Cloudflare, so REMOTE_ADDR at the
	 * origin is a Cloudflare edge address unless the host restores the visitor
	 * IP — which would collapse every visitor worldwide into a handful of
	 * buckets and make the limits below fire on innocent traffic. CF-Connecting-IP
	 * carries the real client address and is set by Cloudflare on every proxied
	 * request, so prefer it and fall back to REMOTE_ADDR.
	 *
	 * This is defence in depth, not a hard boundary: the header is only
	 * trustworthy while the origin refuses non-Cloudflare traffic (see the
	 * Cloudflare notes in CHA_Security_Audit_Remediation_v1.md — Authenticated
	 * Origin Pulls / origin firewall). The per-email bucket and the WAF rule
	 * are the layers that do not depend on it.
	 *
	 * @return string
	 */
	protected static function client_ip() {
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = trim( (string) wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				return $cf;
			}
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ra = trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			if ( filter_var( $ra, FILTER_VALIDATE_IP ) ) {
				return $ra;
			}
		}
		return 'unknown';
	}

	/**
	 * Fixed-window counter shared by every rate-limited route.
	 *
	 * The transient's TTL is only refreshed while the caller is UNDER the limit,
	 * so a blocked caller's window still expires on schedule rather than being
	 * held open by its own retries.
	 *
	 * @param string $bucket   Short bucket prefix (route + dimension).
	 * @param string $identity Value being counted (IP, email, …).
	 * @param int    $max      Max attempts per window.
	 * @param int    $window   Window in seconds.
	 * @return bool True when this caller is over the limit.
	 */
	protected static function rate_limited_bucket( $bucket, $identity, $max, $window ) {
		$key   = 'cha_rl_' . $bucket . '_' . md5( (string) $identity );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $key, $count + 1, $window );
		return false;
	}

	/**
	 * Simple per-IP rate limit for verify-token (defence in depth — tokens are
	 * already long and random).
	 *
	 * @param int $max    Max attempts per window.
	 * @param int $window Window in seconds.
	 * @return bool True when the caller is over the limit.
	 */
	protected static function rate_limited( $max = 20, $window = 60 ) {
		return self::rate_limited_bucket( 'vt', self::client_ip(), $max, $window );
	}

	/**
	 * Rate limit for /checkout, counted on two dimensions in one 10-minute
	 * window. Both are checked, and both count the attempt, so neither can be
	 * sidestepped by varying the other.
	 *
	 *  - Per email (4): the precise one. A real buyer needs one checkout, plus
	 *    a couple of retries after abandoning the Paystack page. Four is
	 *    comfortably above that and well below anything worth abusing.
	 *  - Per IP (20): a blunt flood cap, deliberately loose. South African
	 *    mobile networks sit behind CGNAT, so one public IP can legitimately
	 *    be dozens of unrelated buyers; a tight per-IP number would lock out
	 *    real customers long before it inconvenienced a script.
	 *
	 * Twenty per ten minutes still bounds a single source to ~120 Paystack
	 * initialisations an hour instead of an unlimited number. A distributed
	 * flood is not something an application-level IP counter can solve — that
	 * is what the Cloudflare WAF rule documented in the remediation report is
	 * for, and it is the layer that should carry that load.
	 *
	 * @param string $email Buyer email as supplied (normalised here).
	 * @return bool True when the caller is over either limit.
	 */
	protected static function checkout_rate_limited( $email ) {
		$window = 10 * MINUTE_IN_SECONDS;

		// Normalised so Foo@Bar.COM and " foo@bar.com " are one identity.
		$email_key = strtolower( trim( (string) $email ) );

		$ip_blocked    = self::rate_limited_bucket( 'co_ip', self::client_ip(), 20, $window );
		$email_blocked = self::rate_limited_bucket( 'co_em', $email_key, 4, $window );

		if ( $ip_blocked || $email_blocked ) {
			error_log(
				'[CHA Paystack] /checkout rate-limited (' .
				( $ip_blocked ? 'ip' : '' ) . ( $ip_blocked && $email_blocked ? '+' : '' ) . ( $email_blocked ? 'email' : '' ) .
				') — no Paystack transaction created.'
			);
			return true;
		}

		return false;
	}
}
