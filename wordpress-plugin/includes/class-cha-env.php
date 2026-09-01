<?php
/**
 * .env loader + secret accessor.
 *
 * Secrets (Paystack keys, SMTP creds) live ONLY in a .env file
 * (preferably above the web root) or in wp-config.php constants — never in the
 * database, never exposed via REST. Precedence: .env → wp-config constant →
 * real environment → default.
 *
 * @package cha
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CHA_Env {

	/** @var bool */
	protected static $loaded = false;

	/** @var array<string,string> */
	protected static $vars = array();

	/**
	 * Parse the .env file once (idempotent).
	 */
	public static function boot() {
		if ( self::$loaded ) {
			return;
		}
		self::$loaded = true;

		$path = self::locate_env_file();
		if ( $path && is_readable( $path ) ) {
			self::parse( $path );
		}
	}

	/**
	 * Resolve the .env path. Order:
	 *   1. CHA_ENV_PATH constant (wp-config) — explicit override.
	 *   2. One level above the web root (preferred on shared hosting).
	 *   3. Plugin directory (discouraged; guard with .htaccess if used).
	 *
	 * @return string|null
	 */
	protected static function locate_env_file() {
		if ( defined( 'CHA_ENV_PATH' ) && CHA_ENV_PATH ) {
			return CHA_ENV_PATH;
		}

		$above = dirname( untrailingslashit( ABSPATH ) ) . '/.env';
		if ( is_readable( $above ) ) {
			return $above;
		}

		$in_plugin = CHA_PLUGIN_DIR . '.env';
		if ( is_readable( $in_plugin ) ) {
			return $in_plugin;
		}

		return null;
	}

	/**
	 * Parse KEY=VALUE lines into the static store.
	 *
	 * @param string $path Absolute path to the .env file.
	 */
	protected static function parse( $path ) {
		$lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! is_array( $lines ) ) {
			return;
		}

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$eq = strpos( $line, '=' );
			if ( false === $eq ) {
				continue;
			}
			$key = trim( substr( $line, 0, $eq ) );
			$val = trim( substr( $line, $eq + 1 ) );

			// Strip one layer of matching surrounding quotes.
			if ( strlen( $val ) >= 2 ) {
				$first = $val[0];
				$last  = substr( $val, -1 );
				if ( ( '"' === $first && '"' === $last ) || ( "'" === $first && "'" === $last ) ) {
					$val = substr( $val, 1, -1 );
				}
			}

			if ( '' !== $key ) {
				self::$vars[ $key ] = $val;
			}
		}
	}

	/**
	 * Get a secret/config value.
	 *
	 * @param string $key     Variable name (e.g. PAYSTACK_SECRET_KEY).
	 * @param string $default Returned when unset.
	 * @return string
	 */
	public static function get( $key, $default = '' ) {
		self::boot();

		if ( isset( self::$vars[ $key ] ) ) {
			return self::$vars[ $key ];
		}
		if ( defined( $key ) ) {
			return (string) constant( $key );
		}
		$env = getenv( $key );
		if ( false !== $env ) {
			return $env;
		}
		return $default;
	}

	/**
	 * True when a non-empty value is configured.
	 *
	 * @param string $key Variable name.
	 * @return bool
	 */
	public static function has( $key ) {
		return '' !== (string) self::get( $key, '' );
	}
}
