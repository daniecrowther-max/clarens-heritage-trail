<?php
/**
 * Access tokens for promo/admin unlocks.
 *
 * All three unlock types (purchase/promo/admin) are long, cryptographically
 * random, and OPAQUE to the app — no secret and no hashing ship in the bundle;
 * the server resolves a token by DB lookup, never by decoding it.
 *
 * Purchase tokens live in the purchases table (they carry payment state).
 * Promo and admin tokens — issued and revoked by staff — live here and are
 * fully revocable.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Tokens {

	const TABLE = 'cha_access_tokens';

	// Crockford base32 (no ambiguous I, L, O, U). Token = CHT-XXXX-XXXX-XXXX-XXXX (~80 bits).
	const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/**
	 * @var string Last wp_mail error for a token email, set by
	 * CHA_Purchases::capture_mail_error() via the shared wp_mail_failed
	 * hook when self::email_token() has the mail context claimed.
	 */
	protected static $last_mail_error = '';

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
	 * Create the access-tokens table (called on activation).
	 */
	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			token             VARCHAR(40)     NOT NULL,
			type              VARCHAR(10)     NOT NULL,
			label             VARCHAR(191)    DEFAULT '',
			status            VARCHAR(10)     NOT NULL DEFAULT 'active',
			created_by        BIGINT UNSIGNED DEFAULT 0,
			created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at        DATETIME        DEFAULT NULL,
			revoked_at        DATETIME        DEFAULT NULL,
			last_used_at      DATETIME        DEFAULT NULL,
			recipient_email   VARCHAR(255)    DEFAULT NULL,
			email_status      VARCHAR(20)     NOT NULL DEFAULT 'pending',
			email_attempts    INT             NOT NULL DEFAULT 0,
			email_last_error  TEXT            DEFAULT NULL,
			email_sent_at     DATETIME        DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_token (token),
			KEY idx_type_status (type, status),
			KEY idx_email (recipient_email)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Generate an opaque token: CHT-XXXX-XXXX-XXXX-XXXX.
	 * Uses random_int (CSPRNG) over an unambiguous alphabet.
	 *
	 * @return string
	 */
	public static function generate() {
		$max    = strlen( self::ALPHABET ) - 1;
		$groups = array();
		for ( $g = 0; $g < 4; $g++ ) {
			$s = '';
			for ( $i = 0; $i < 4; $i++ ) {
				$s .= self::ALPHABET[ random_int( 0, $max ) ];
			}
			$groups[] = $s;
		}
		return 'CHT-' . implode( '-', $groups );
	}

	/**
	 * Issue a promo/admin token.
	 *
	 * @param string      $type            'promo' or 'admin'.
	 * @param string      $label           Human note (who/what it is for).
	 * @param string|null $expires_at      'Y-m-d H:i:s' or null (no expiry).
	 * @param string      $recipient_email Optional — stored on the row so the
	 *                                     tokens list can show who it was (or
	 *                                     will be) emailed to. Does not send
	 *                                     anything itself; see email_token().
	 * @return string|WP_Error The token string, or WP_Error.
	 */
	public static function issue( $type, $label = '', $expires_at = null, $recipient_email = '' ) {
		global $wpdb;

		if ( ! in_array( $type, array( 'promo', 'admin' ), true ) ) {
			return new WP_Error( 'cha_token_type', 'Invalid token type.' );
		}

		// Retry on the astronomically unlikely unique-key collision.
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$token  = self::generate();
			$result = $wpdb->insert(
				self::table(),
				array(
					'token'           => $token,
					'type'            => $type,
					'label'           => $label,
					'status'          => 'active',
					'created_by'      => get_current_user_id(),
					'created_at'      => current_time( 'mysql' ),
					'expires_at'      => $expires_at,
					'recipient_email' => '' !== $recipient_email ? $recipient_email : null,
				)
			);
			if ( false !== $result ) {
				return $token;
			}
		}

		return new WP_Error( 'cha_token_insert', 'Could not issue token.' );
	}

	/**
	 * Revoke a token by row id.
	 *
	 * @param int $id Row id.
	 * @return int|false Rows updated, or false on error.
	 */
	public static function revoke( $id ) {
		global $wpdb;
		return $wpdb->update(
			self::table(),
			array(
				'status'     => 'revoked',
				'revoked_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Resolve a promo/admin token to its state.
	 *
	 * @param string $token Opaque token.
	 * @return array|null { id, type, status, expired(bool) } or null if unknown.
	 */
	public static function resolve( $token ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s LIMIT 1', $token )
		);
		if ( ! $row ) {
			return null;
		}

		$expired = ! empty( $row->expires_at ) && strtotime( $row->expires_at ) < time();

		return array(
			'id'      => (int) $row->id,
			'type'    => $row->type,
			'status'  => $row->status,
			'expired' => $expired,
		);
	}

	/**
	 * Stamp last-used time (best effort).
	 *
	 * @param string $token Opaque token.
	 */
	public static function touch( $token ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array( 'last_used_at' => current_time( 'mysql' ) ),
			array( 'token' => $token )
		);
	}

	/**
	 * List tokens for the admin screen.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function all( $limit = 200 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d', $limit )
		);
	}

	/**
	 * Email a just-issued promo/admin token to a recipient, logging the
	 * outcome on the same row (mirrors CHA_Purchases::send_token_email()).
	 *
	 * @param string $token           The token string (as returned by issue()).
	 * @param string $recipient_email Where to send it — caller must validate
	 *                                with is_email() before calling this;
	 *                                this method does not re-validate.
	 * @return bool|WP_Error True/false for wp_mail()'s result, or WP_Error if
	 *                       the token row can't be found.
	 */
	public static function email_token( $token, $recipient_email ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s LIMIT 1', $token )
		);
		if ( ! $row ) {
			return new WP_Error( 'cha_token_not_found', 'Token row not found — cannot log the email attempt.' );
		}

		self::$last_mail_error = '';
		CHA_Purchases::set_mail_context( 'token' );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$subject = 'admin' === $row->type
			? 'Your Clarens Heritage Trail admin access'
			: 'Your Clarens Heritage Trail promo pass';
		$message  = self::email_html( $token, $row->type, $row->expires_at );
		$attempts = (int) $row->email_attempts + 1;

		$sent = wp_mail( $recipient_email, $subject, $message, $headers );

		CHA_Purchases::set_mail_context( '' );

		if ( $sent ) {
			$wpdb->update(
				self::table(),
				array(
					'recipient_email'  => $recipient_email,
					'email_status'     => 'sent',
					'email_attempts'   => $attempts,
					'email_sent_at'    => current_time( 'mysql' ),
					'email_last_error' => null,
				),
				array( 'id' => $row->id )
			);
		} else {
			$err = self::$last_mail_error ? self::$last_mail_error : 'wp_mail returned false';
			error_log( '[CHA] token email FAILED for ' . $recipient_email . ' (' . $token . '): ' . $err );
			$wpdb->update(
				self::table(),
				array(
					'recipient_email'  => $recipient_email,
					'email_status'     => 'failed',
					'email_attempts'   => $attempts,
					'email_last_error' => $err,
				),
				array( 'id' => $row->id )
			);
		}

		return (bool) $sent;
	}

	/**
	 * Receive a wp_mail_failed error routed from CHA_Purchases when the
	 * in-flight send was ours (see CHA_Purchases::$mail_context).
	 *
	 * @param string $message Error message.
	 */
	public static function set_last_mail_error( $message ) {
		self::$last_mail_error = $message;
	}

	/**
	 * Token email body for a promo/admin token — adapted from
	 * CHA_Purchases::email_html() for the non-purchase context (no
	 * "thank you for your payment" copy), plus an expiry line for promo
	 * tokens that have one.
	 *
	 * @param string      $token      Opaque token.
	 * @param string      $type       'promo' or 'admin'.
	 * @param string|null $expires_at 'Y-m-d H:i:s' or null.
	 * @return string HTML.
	 */
	protected static function email_html( $token, $type, $expires_at ) {
		// Same split-host reasoning as CHA_Purchases::email_html(): the
		// token unlock happens in the app, not on the WordPress site.
		$app_url = CHA_Env::get( 'CHECKOUT_REDIRECT_URL', CHA_Cors::primary_app_origin() );
		$safe    = esc_html( $token );
		$link    = esc_url( $app_url );

		$intro = 'admin' === $type
			? 'You have been issued admin access to the Clarens Heritage Trail.'
			: 'You have been issued a promotional Heritage Trail pass.';

		$expiry_html = '';
		if ( 'promo' === $type && $expires_at ) {
			$expiry_date = esc_html( gmdate( 'j F Y', strtotime( $expires_at ) ) );
			$expiry_html = "<p style='font-size:13px;color:#666'>This pass expires on <strong>$expiry_date</strong>.</p>";
		} elseif ( 'admin' === $type ) {
			$expiry_html = "<p style='font-size:13px;color:#666'>This access token does not expire.</p>";
		}

		return "
<div style='font-family:Georgia,serif;max-width:520px;margin:0 auto;padding:20px'>
	<h1 style='font-size:22px;margin:0 0 4px'>Clarens Heritage Trail</h1>
	<p style='color:#666;margin:0 0 16px'>Clarens Heritage Association</p>
	<p>$intro</p>
	<p>Your access token is:</p>
	<div style='border:2px solid #c8a052;border-radius:8px;padding:16px;text-align:center;margin:16px 0'>
		<span style='font-family:monospace;font-size:20px;font-weight:bold;letter-spacing:2px'>$safe</span>
	</div>
	<p style='font-size:13px;color:#666'>Open the app at <a href='$link'>$link</a> and enter this token to unlock all heritage sites and partner vouchers.</p>
	$expiry_html
</div>";
	}
}
