<?php
/**
 * Purchase records + token email (with delivery logging).
 *
 * A checkout inserts a `pending` row; a successful Paystack Verify Transaction
 * call (see CHA_Paystack::verify_and_mark_paid) flips it to `paid` and
 * dispatches the token email. The email is the buyer's recovery path, so every
 * send is logged (status/attempts/last error) rather than fire-and-forget, and
 * can be re-sent from the admin screen.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Purchases {

	const TABLE = 'cha_purchases';

	/** @var string Last wp_mail error, captured via the wp_mail_failed hook. */
	protected static $last_mail_error = '';

	/**
	 * @var bool Whether the most recent mark_paid() call returned false
	 * because of a DB error, as opposed to losing the pending→paid race to
	 * another concurrent caller (webhook vs redirect verify). Both cases
	 * return false from mark_paid() itself; callers that need to tell them
	 * apart (e.g. to pick a webhook response code) check this flag right
	 * after calling mark_paid().
	 */
	protected static $last_mark_paid_db_error = false;

	/**
	 * @var string Which sender currently has a wp_mail() call in flight —
	 * '' (this class) or 'token' (CHA_Tokens). wp_mail_failed fires for
	 * every mail sent site-wide, and both this class and CHA_Tokens send
	 * synchronously (set the context, call wp_mail(), read the result,
	 * clear the context) — so a single hook registration can route the
	 * failure to whichever class actually initiated the send, without a
	 * second add_action( 'wp_mail_failed', … ) anywhere else in the plugin.
	 */
	protected static $mail_context = '';

	/**
	 * Hook mail-failure capture.
	 */
	public static function init() {
		add_action( 'wp_mail_failed', array( __CLASS__, 'capture_mail_error' ) );
	}

	/**
	 * Declare which class is about to call wp_mail(), so capture_mail_error()
	 * routes a failure to the right place. Call with 'token' immediately
	 * before CHA_Tokens sends, and reset to '' immediately after.
	 *
	 * @param string $context '' (purchases, the default) or 'token'.
	 */
	public static function set_mail_context( $context ) {
		self::$mail_context = $context;
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
	 * Create the purchases table (called on activation).
	 */
	public static function create_table() {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email            VARCHAR(255)    NOT NULL,
			token            VARCHAR(40)     NOT NULL,
			reference        VARCHAR(100)    DEFAULT '',
			amount_cents     INT             NOT NULL DEFAULT 0,
			status           VARCHAR(20)     NOT NULL DEFAULT 'pending',
			created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			paid_at          DATETIME        DEFAULT NULL,
			email_status     VARCHAR(20)     NOT NULL DEFAULT 'pending',
			email_attempts   INT             NOT NULL DEFAULT 0,
			email_last_error TEXT            DEFAULT NULL,
			email_sent_at    DATETIME        DEFAULT NULL,
			PRIMARY KEY (id),
			KEY idx_token (token),
			KEY idx_email (email),
			KEY idx_status (status),
			KEY idx_reference (reference)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Insert a pending purchase.
	 *
	 * @param string $email        Buyer email.
	 * @param string $token        Opaque purchase token.
	 * @param string $reference    Paystack transaction reference ('CHA-' . token).
	 * @param int    $amount_cents Amount charged (snapshot of the price at purchase time).
	 * @return int|false Insert result.
	 */
	public static function insert_pending( $email, $token, $reference, $amount_cents ) {
		global $wpdb;
		return $wpdb->insert(
			self::table(),
			array(
				'email'        => $email,
				'token'        => $token,
				'reference'    => $reference,
				'amount_cents' => (int) $amount_cents,
				'status'       => 'pending',
				'created_at'   => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * @param string $token Opaque token.
	 * @return object|null
	 */
	public static function find_by_token( $token ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token = %s LIMIT 1', $token )
		);
	}

	/**
	 * @param string $reference Paystack transaction reference ('CHA-' . token).
	 * @return object|null
	 */
	public static function find_by_reference( $reference ) {
		global $wpdb;
		return $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE reference = %s LIMIT 1', $reference )
		);
	}

	/**
	 * Mark a purchase paid (idempotent — returns false if it was already paid).
	 * The UPDATE is conditioned on status = 'pending' so two concurrent callers
	 * (webhook + redirect verify racing each other) can't both "win" the
	 * transition and both send the token email — only the row whose UPDATE
	 * actually matches a pending row gets true back.
	 *
	 * @param object $purchase Purchase row.
	 * @return bool True if this call transitioned pending → paid.
	 */
	public static function mark_paid( $purchase ) {
		global $wpdb;
		self::$last_mark_paid_db_error = false;

		if ( 'paid' === $purchase->status ) {
			return false;
		}

		$updated = $wpdb->update(
			self::table(),
			array(
				'status'  => 'paid',
				'paid_at' => current_time( 'mysql' ),
			),
			array( 'id' => $purchase->id, 'status' => 'pending' )
		);

		if ( false === $updated ) {
			self::$last_mark_paid_db_error = true;
			error_log( '[CHA] mark_paid DB error for purchase #' . $purchase->id . ' (' . $purchase->reference . '): ' . $wpdb->last_error );
			return false;
		}

		if ( 0 === $updated ) {
			error_log( '[CHA] mark_paid no-op for purchase #' . $purchase->id . ' (' . $purchase->reference . ') — already transitioned by another process.' );
		}

		return $updated > 0;
	}

	/**
	 * Whether the most recent mark_paid() call returned false because of a DB
	 * error rather than losing the pending→paid race. Check immediately after
	 * calling mark_paid() when the caller needs to distinguish the two.
	 *
	 * @return bool
	 */
	public static function mark_paid_failed_due_to_db_error() {
		return self::$last_mark_paid_db_error;
	}

	/**
	 * Send (or re-send) the token email, logging the outcome to the row.
	 *
	 * @param object $purchase Purchase row.
	 * @return bool Whether wp_mail reported success.
	 */
	public static function send_token_email( $purchase ) {
		global $wpdb;

		self::$last_mail_error = '';

		$from_name = CHA_Env::get( 'SMTP_FROM_NAME', get_bloginfo( 'name' ) );
		$from_addr = CHA_Env::get( 'SMTP_FROM', get_option( 'admin_email' ) );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_addr ),
		);

		$subject  = 'Your Clarens Heritage Trail Pass';
		$message  = self::email_html( $purchase->token );
		$attempts = (int) $purchase->email_attempts + 1;

		$sent = wp_mail( $purchase->email, $subject, $message, $headers );

		if ( $sent ) {
			$wpdb->update(
				self::table(),
				array(
					'email_status'     => 'sent',
					'email_attempts'   => $attempts,
					'email_sent_at'    => current_time( 'mysql' ),
					'email_last_error' => null,
				),
				array( 'id' => $purchase->id )
			);
		} else {
			$err = self::$last_mail_error ? self::$last_mail_error : 'wp_mail returned false';
			error_log( '[CHA] token email FAILED for ' . $purchase->email . ' (' . $purchase->token . '): ' . $err );
			$wpdb->update(
				self::table(),
				array(
					'email_status'     => 'failed',
					'email_attempts'   => $attempts,
					'email_last_error' => $err,
				),
				array( 'id' => $purchase->id )
			);
		}

		return (bool) $sent;
	}

	/**
	 * Capture the last wp_mail error for richer logging. Routed by
	 * self::$mail_context to whichever class actually initiated the send.
	 *
	 * @param WP_Error $wp_error Error from the wp_mail_failed hook.
	 */
	public static function capture_mail_error( $wp_error ) {
		if ( ! is_wp_error( $wp_error ) ) {
			return;
		}
		if ( 'token' === self::$mail_context ) {
			CHA_Tokens::set_last_mail_error( $wp_error->get_error_message() );
		} else {
			self::$last_mail_error = $wp_error->get_error_message();
		}
	}

	/**
	 * Token email body. Branding is intentionally neutral until step 8.
	 *
	 * @param string $token Opaque token.
	 * @return string HTML.
	 */
	protected static function email_html( $token ) {
		// Same split-host reasoning as the checkout redirect (class-cha-paystack.php):
		// the token unlock happens in the app, not on the WordPress site, so the
		// fallback must be the app origin, not home_url().
		$app_url = CHA_Env::get( 'CHECKOUT_REDIRECT_URL', CHA_Cors::primary_app_origin() );
		$safe    = esc_html( $token );
		$link    = esc_url( $app_url );

		return "
<div style='font-family:Georgia,serif;max-width:520px;margin:0 auto;padding:20px'>
	<h1 style='font-size:22px;margin:0 0 4px'>Clarens Heritage Trail</h1>
	<p style='color:#666;margin:0 0 16px'>Your trail pass is ready</p>
	<p>Thank you for purchasing the Clarens Heritage Trail Pass.</p>
	<p>Your unlock token is:</p>
	<div style='border:2px solid #c8a052;border-radius:8px;padding:16px;text-align:center;margin:16px 0'>
		<span style='font-family:monospace;font-size:20px;font-weight:bold;letter-spacing:2px'>$safe</span>
	</div>
	<p style='font-size:13px;color:#666'>Open the app at <a href='$link'>$link</a> and enter this token to unlock all heritage sites and partner vouchers.</p>
	<p style='font-size:13px;color:#666'>This token does not expire. Keep it safe — you can use it on any device.</p>
</div>";
	}

	/**
	 * List purchases for the admin screen.
	 *
	 * @param int $limit Max rows.
	 * @return array
	 */
	public static function all( $limit = 100 ) {
		global $wpdb;
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY created_at DESC LIMIT %d', $limit )
		);
	}
}
