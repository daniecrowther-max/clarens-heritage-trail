<?php
/**
 * Admin screen: Trail Payments.
 *
 * One screen for the project lead: issue/revoke promo & admin tokens, view
 * purchases and re-send the token email, set the unlock price, and see
 * Paystack configuration status. Secrets are NEVER shown or stored here — the
 * status panel only reports whether each .env value is present.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Admin {

	const SLUG = 'cha-payments';

	/**
	 * Hook menu + action handling.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
	}

	/**
	 * Add the screen under the Partners menu.
	 */
	public static function menu() {
		add_submenu_page(
			'edit.php?post_type=partner',
			__( 'Trail Payments', 'cha' ),
			__( 'Trail Payments', 'cha' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Page URL.
	 *
	 * @return string
	 */
	protected static function page_url() {
		return admin_url( 'edit.php?post_type=partner&page=' . self::SLUG );
	}

	/**
	 * Queue an admin notice across the post-redirect.
	 *
	 * @param string $type success|error.
	 * @param string $msg  Message.
	 */
	protected static function notice( $type, $msg ) {
		$key   = 'cha_admin_notices_' . get_current_user_id();
		$queue = get_transient( $key );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		$queue[] = array( 'type' => $type, 'msg' => $msg );
		set_transient( $key, $queue, 60 );
	}

	/**
	 * Handle token issue/revoke and email resend (POST, nonce-guarded, PRG).
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['cha_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$action = sanitize_text_field( wp_unslash( $_POST['cha_action'] ) );

		if ( 'issue_token' === $action && check_admin_referer( 'cha_issue_token' ) ) {
			$type  = sanitize_text_field( wp_unslash( isset( $_POST['token_type'] ) ? $_POST['token_type'] : '' ) );
			$label = sanitize_text_field( wp_unslash( isset( $_POST['token_label'] ) ? $_POST['token_label'] : '' ) );
			$days  = isset( $_POST['token_expiry_days'] ) ? (int) $_POST['token_expiry_days'] : 0;

			$should_email = ! empty( $_POST['email_token'] );
			$email_raw    = sanitize_text_field( wp_unslash( isset( $_POST['recipient_email'] ) ? $_POST['recipient_email'] : '' ) );

			// Validate up front so a bad address is reported against the
			// SAME notice as the issue result, not a separate silent skip.
			$email_error = '';
			if ( $should_email && ( '' === $email_raw || ! is_email( $email_raw ) ) ) {
				$email_error = '' === $email_raw
					? 'no recipient email was given.'
					: 'recipient email "' . $email_raw . '" is not valid.';
			}

			$expires = null;
			if ( 'promo' === $type && $days > 0 ) {
				$expires = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
			}

			// Store the address on the row even when we're not about to email it
			// (e.g. the address was invalid) — none is passed in that case so
			// the row doesn't carry a bogus/unsent address.
			$result = CHA_Tokens::issue( $type, $label, $expires, ( $should_email && '' === $email_error ) ? $email_raw : '' );

			if ( is_wp_error( $result ) ) {
				self::notice( 'error', $result->get_error_message() );
			} elseif ( $should_email && '' !== $email_error ) {
				self::notice( 'error', 'Token issued: ' . $result . ' — but not emailed: ' . $email_error );
			} elseif ( $should_email ) {
				$sent = CHA_Tokens::email_token( $result, $email_raw );
				if ( is_wp_error( $sent ) ) {
					self::notice( 'error', 'Token issued: ' . $result . ' — but not emailed: ' . $sent->get_error_message() );
				} elseif ( $sent ) {
					self::notice( 'success', 'Token issued and emailed to ' . $email_raw . ': ' . $result );
				} else {
					self::notice( 'error', 'Token issued: ' . $result . ', but the email failed — check the Sent/Email status column below for the reason.' );
				}
			} else {
				self::notice( 'success', 'Token issued: ' . $result );
			}
		} elseif ( 'revoke_token' === $action && check_admin_referer( 'cha_revoke_token' ) ) {
			$id = isset( $_POST['token_id'] ) ? (int) $_POST['token_id'] : 0;
			CHA_Tokens::revoke( $id );
			self::notice( 'success', 'Token revoked.' );
		} elseif ( 'resend_email' === $action && check_admin_referer( 'cha_resend_email' ) ) {
			global $wpdb;
			$id  = isset( $_POST['purchase_id'] ) ? (int) $_POST['purchase_id'] : 0;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CHA_Purchases::table() . ' WHERE id = %d', $id ) );
			if ( $row ) {
				$ok = CHA_Purchases::send_token_email( $row );
				self::notice( $ok ? 'success' : 'error', $ok ? 'Token email re-sent.' : 'Email failed — check the status column and server log.' );
			}
		} elseif ( 'reverify_purchase' === $action && check_admin_referer( 'cha_reverify_purchase' ) ) {
			global $wpdb;
			$id  = isset( $_POST['purchase_id'] ) ? (int) $_POST['purchase_id'] : 0;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CHA_Purchases::table() . ' WHERE id = %d', $id ) );

			if ( ! $row ) {
				self::notice( 'error', 'Purchase not found.' );
			} elseif ( 'paid' === $row->status ) {
				self::notice( 'success', 'Already paid — nothing to do.' );
			} else {
				$result = CHA_Paystack::verify_and_mark_paid( $row );
				self::notice( ( 'paid' === $result ) ? 'success' : 'error', self::reverify_message( $result ) );
			}
		} else {
			return;
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Flash notices.
		$key   = 'cha_admin_notices_' . get_current_user_id();
		$queue = get_transient( $key );
		if ( is_array( $queue ) ) {
			delete_transient( $key );
			foreach ( $queue as $n ) {
				printf(
					'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
					'error' === $n['type'] ? 'error' : 'success',
					esc_html( $n['msg'] )
				);
			}
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Trail Payments', 'cha' ) . '</h1>';

		self::render_pricing();
		self::render_paystack_status();
		self::render_issue_token();
		self::render_tokens_table();
		self::render_purchases_table();

		echo '</div>';
	}

	/**
	 * Pricing form.
	 */
	protected static function render_pricing() {
		$cents = CHA_Settings::unlock_price_cents();
		echo '<h2>' . esc_html__( 'Unlock price', 'cha' ) . '</h2>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'cha_payments' );
		echo '<p><label>' . esc_html__( 'Price in cents (ZAR): ', 'cha' ) . '</label>';
		printf(
			'<input type="number" name="%s" value="%d" min="100" step="1"> ',
			esc_attr( CHA_Settings::PRICE_OPTION ),
			(int) $cents
		);
		echo '<code>= R' . esc_html( number_format( $cents / 100, 2 ) ) . '</code></p>';
		echo '<p class="description">' . esc_html__( 'The server charges this amount at checkout. The app never sends an amount; each purchase stores what was actually paid.', 'cha' ) . '</p>';
		submit_button( __( 'Save price', 'cha' ) );
		echo '</form><hr>';
	}

	/**
	 * Paystack status — presence only, never secret values. Purchases are
	 * confirmed both synchronously (the redirect back from checkout calls
	 * Paystack's Verify Transaction API directly) and via the
	 * cha/v1/paystack-webhook endpoint, which catches a buyer who paid but
	 * never returned to the app.
	 */
	protected static function render_paystack_status() {
		$secret_key = CHA_Env::get( 'PAYSTACK_SECRET_KEY', '' );
		$mode       = ( 0 === strpos( $secret_key, 'sk_live_' ) ) ? 'live' : ( '' === $secret_key ? '(unset)' : 'test' );
		$check      = function ( $present ) {
			return $present
				? '<span style="color:#065f46">&#10003; configured</span>'
				: '<span style="color:#b91c1c">&#10007; missing</span>';
		};

		echo '<h2>' . esc_html__( 'Paystack status', 'cha' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		printf( '<tr><td>Mode</td><td><code>%s</code></td></tr>', esc_html( $mode ) );
		printf( '<tr><td>Secret key</td><td>%s</td></tr>', wp_kses_post( $check( CHA_Env::has( 'PAYSTACK_SECRET_KEY' ) ) ) );
		printf( '<tr><td>Split code</td><td>%s</td></tr>', wp_kses_post( $check( CHA_Env::has( 'PAYSTACK_SPLIT_CODE' ) ) ) );
		printf( '<tr><td>Checkout redirect URL</td><td>%s</td></tr>', wp_kses_post( $check( CHA_Env::has( 'CHECKOUT_REDIRECT_URL' ) ) ) );
		printf( '<tr><td>Webhook URL</td><td><code>%s</code></td></tr>', esc_html( rest_url( CHA_REST_NAMESPACE . '/paystack-webhook' ) ) );
		echo '</tbody></table>';
		echo '<p class="description">Secrets live in <code>.env</code> only — this panel reports presence, never values. Paste the webhook URL above into the Paystack dashboard (Settings &rarr; API Keys &amp; Webhooks). It is authenticated via the X-Paystack-Signature header, using the same secret key — no separate webhook secret to configure.</p><hr>';
	}

	/**
	 * Issue-token form.
	 */
	protected static function render_issue_token() {
		echo '<h2>' . esc_html__( 'Issue a token', 'cha' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( self::page_url() ) . '">';
		wp_nonce_field( 'cha_issue_token' );
		echo '<input type="hidden" name="cha_action" value="issue_token">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>' . esc_html__( 'Type', 'cha' ) . '</th><td><select name="token_type"><option value="promo">Promo (optional expiry)</option><option value="admin">Admin (no expiry)</option></select></td></tr>';
		echo '<tr><th>' . esc_html__( 'Label', 'cha' ) . '</th><td><input type="text" name="token_label" class="regular-text" placeholder="e.g. Tourism office / press"></td></tr>';
		echo '<tr><th>' . esc_html__( 'Promo expiry (days)', 'cha' ) . '</th><td><input type="number" name="token_expiry_days" value="90" min="0" style="max-width:100px"> <span class="description">0 or blank = no expiry. Ignored for admin.</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Recipient email', 'cha' ) . '</th><td><input type="email" name="recipient_email" class="regular-text" placeholder="name@example.com"> <span class="description">' . esc_html__( 'Required only if emailing below.', 'cha' ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Email this token', 'cha' ) . '</th><td><label><input type="checkbox" name="email_token" value="1"> ' . esc_html__( 'Email the token to the recipient address above', 'cha' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Issue token', 'cha' ) );
		echo '</form><hr>';
	}

	/**
	 * Tokens list with revoke.
	 */
	protected static function render_tokens_table() {
		$tokens = CHA_Tokens::all();
		echo '<h2>' . esc_html__( 'Promo / admin tokens', 'cha' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Token</th><th>Type</th><th>Label</th><th>Status</th><th>Created</th><th>Expires</th><th>Last used</th><th>Sent to</th><th></th></tr></thead><tbody>';

		if ( empty( $tokens ) ) {
			echo '<tr><td colspan="9" style="text-align:center;color:#777">No tokens issued yet.</td></tr>';
		} else {
			foreach ( $tokens as $t ) {
				$is_active = ( 'active' === $t->status );
				echo '<tr>';
				echo '<td><code>' . esc_html( $t->token ) . '</code></td>';
				echo '<td>' . esc_html( $t->type ) . '</td>';
				echo '<td>' . esc_html( $t->label ) . '</td>';
				printf(
					'<td>%s</td>',
					$is_active
						? '<span style="color:#065f46">active</span>'
						: '<span style="color:#b91c1c">' . esc_html( $t->status ) . '</span>'
				);
				echo '<td>' . esc_html( $t->created_at ) . '</td>';
				echo '<td>' . esc_html( $t->expires_at ? $t->expires_at : '—' ) . '</td>';
				echo '<td>' . esc_html( $t->last_used_at ? $t->last_used_at : '—' ) . '</td>';
				echo '<td>' . self::email_status_cell( $t ) . '</td>';
				echo '<td>';
				if ( $is_active ) {
					echo '<form method="post" action="' . esc_url( self::page_url() ) . '" onsubmit="return confirm(\'Revoke this token?\');">';
					wp_nonce_field( 'cha_revoke_token' );
					echo '<input type="hidden" name="cha_action" value="revoke_token">';
					printf( '<input type="hidden" name="token_id" value="%d">', (int) $t->id );
					submit_button( __( 'Revoke', 'cha' ), 'delete small', 'submit', false );
					echo '</form>';
				}
				echo '</td></tr>';
			}
		}
		echo '</tbody></table><hr>';
	}

	/**
	 * "Sent to" cell for the tokens table — the address plus a coloured
	 * status, with the failure reason on hover so the site admin/committee can see
	 * what happened without opening a sent-mail folder.
	 *
	 * @param object $t Token row.
	 * @return string Escaped HTML (safe to echo directly).
	 */
	protected static function email_status_cell( $t ) {
		if ( empty( $t->recipient_email ) ) {
			return '<span style="color:#777">—</span>';
		}

		$addr = esc_html( $t->recipient_email );
		if ( 'sent' === $t->email_status ) {
			return $addr . '<br><span style="color:#065f46">&#10003; sent ' . esc_html( $t->email_sent_at ) . '</span>';
		}
		if ( 'failed' === $t->email_status ) {
			$title = $t->email_last_error ? esc_attr( $t->email_last_error ) : '';
			return $addr . '<br><span style="color:#b91c1c" title="' . $title . '">&#10007; failed</span>' .
				( $title ? ' <span class="description" title="' . $title . '">(?)</span>' : '' );
		}
		return $addr . '<br><span style="color:#777">pending</span>';
	}

	/**
	 * Human-readable notice text for a CHA_Paystack::verify_and_mark_paid()
	 * result, for the admin re-verify action.
	 *
	 * @param string $result One of the verify_and_mark_paid() status strings.
	 * @return string
	 */
	protected static function reverify_message( $result ) {
		switch ( $result ) {
			case 'pending':
				return 'Paystack has not recorded a successful payment for this reference yet.';
			case 'mismatch':
				return 'Amount/currency mismatch against Paystack\'s record — NOT marked paid. See the server log.';
			case 'unreachable':
				return 'Could not reach Paystack to verify — try again shortly.';
			case 'db_error':
				return 'Paystack confirmed the payment, but the database write failed — see the server log. Safe to retry.';
			case 'no_secret':
				return 'PAYSTACK_SECRET_KEY is not configured in .env.';
			default:
				return 'Marked paid.';
		}
	}

	/**
	 * Purchases list with resend.
	 */
	protected static function render_purchases_table() {
		$purchases = CHA_Purchases::all();
		echo '<h2>' . esc_html__( 'Purchases', 'cha' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Email</th><th>Token</th><th>Amount</th><th>Status</th><th>Email</th><th></th></tr></thead><tbody>';

		if ( empty( $purchases ) ) {
			echo '<tr><td colspan="7" style="text-align:center;color:#777">No purchases yet.</td></tr>';
		} else {
			foreach ( $purchases as $p ) {
				echo '<tr>';
				echo '<td>' . esc_html( $p->created_at ) . '</td>';
				echo '<td>' . esc_html( $p->email ) . '</td>';
				echo '<td><code>' . esc_html( $p->token ) . '</code></td>';
				echo '<td>R' . esc_html( number_format( $p->amount_cents / 100, 2 ) ) . '</td>';
				echo '<td>' . esc_html( $p->status ) . '</td>';
				$email_cell = esc_html( $p->email_status );
				if ( 'failed' === $p->email_status && $p->email_last_error ) {
					$email_cell .= ' <span class="description" title="' . esc_attr( $p->email_last_error ) . '">(?)</span>';
				}
				echo '<td>' . $email_cell . '</td>';
				echo '<td>';
				if ( 'paid' === $p->status ) {
					echo '<form method="post" action="' . esc_url( self::page_url() ) . '">';
					wp_nonce_field( 'cha_resend_email' );
					echo '<input type="hidden" name="cha_action" value="resend_email">';
					printf( '<input type="hidden" name="purchase_id" value="%d">', (int) $p->id );
					submit_button( __( 'Resend token', 'cha' ), 'small', 'submit', false );
					echo '</form>';
				} else {
					echo '<form method="post" action="' . esc_url( self::page_url() ) . '">';
					wp_nonce_field( 'cha_reverify_purchase' );
					echo '<input type="hidden" name="cha_action" value="reverify_purchase">';
					printf( '<input type="hidden" name="purchase_id" value="%d">', (int) $p->id );
					submit_button( __( 'Re-verify', 'cha' ), 'small', 'submit', false );
					echo '</form>';
				}
				echo '</td></tr>';
			}
		}
		echo '</tbody></table>';
	}
}
