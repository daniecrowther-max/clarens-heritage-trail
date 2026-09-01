<?php
/**
 * Plugin settings that live in the DB (non-secret).
 *
 * Only the unlock PRICE lives here — it is a business setting, not a secret.
 * The Paystack secret key and subaccount code are NEVER stored here; they
 * come from .env (see CHA_Env). The price is read server-side at checkout so the app
 * never sends an amount, and it is exposed on the content feed so user-facing
 * copy is never a hardcoded lie.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Settings {

	const PRICE_OPTION  = 'cha_unlock_price_cents';
	const PRICE_DEFAULT = 9900; // R99.00 — Danie's decision, 19 Aug 2026.
	const PRICE_FLOOR   = 100;  // R1.00 — guard against a fat-fingered R0.

	/**
	 * Register the setting.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Register the price setting under the cha_payments group.
	 */
	public static function register() {
		register_setting(
			'cha_payments',
			self::PRICE_OPTION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( __CLASS__, 'sanitize_price' ),
				'default'           => self::PRICE_DEFAULT,
			)
		);
	}

	/**
	 * Clamp the price to a sane positive integer.
	 *
	 * @param mixed $value Raw input.
	 * @return int Cents.
	 */
	public static function sanitize_price( $value ) {
		$value = (int) $value;
		if ( $value < self::PRICE_FLOOR ) {
			$value = self::PRICE_DEFAULT;
		}
		return $value;
	}

	/**
	 * Current unlock price in cents.
	 *
	 * @return int
	 */
	public static function unlock_price_cents() {
		return (int) get_option( self::PRICE_OPTION, self::PRICE_DEFAULT );
	}
}
