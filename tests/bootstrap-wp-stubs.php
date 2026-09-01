<?php
/**
 * Minimal WordPress + $wpdb stubs, enough to exercise the CHA payment and
 * redemption paths in plain PHP CLI.
 *
 * This is NOT a WordPress test suite. It stubs only the handful of functions
 * the classes under test actually call, and models the DB behaviours the
 * security fixes depend on:
 *
 *   - $wpdb->insert() can be made to fail on demand (audit item 3).
 *   - The conditional `UPDATE … WHERE CAST(meta_value AS UNSIGNED) < max`
 *     is evaluated atomically and reports affected rows (audit item 4), the
 *     same contract MySQL gives us under the row lock.
 *   - $wpdb->update() is likewise conditional on its WHERE clause, so a
 *     `WHERE status = 'pending'` update only ever touches a still-pending
 *     row — the same contract webhook-vs-redirect idempotency depends on.
 *
 * @package cha-tests
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'CHA_REST_NAMESPACE', 'cha/v1' );
define( 'OBJECT', 'OBJECT' );
define( 'FILTER_VALIDATE_IP_STUB', true );

/* ---- assertions -------------------------------------------------------- */

class T {
	public static $passed = 0;
	public static $failed = 0;
	public static $group  = '';

	public static function group( $name ) {
		self::$group = $name;
		echo "\n" . $name . "\n" . str_repeat( '-', strlen( $name ) ) . "\n";
	}

	public static function ok( $cond, $what ) {
		if ( $cond ) {
			self::$passed++;
			echo "  PASS  $what\n";
		} else {
			self::$failed++;
			echo "  FAIL  $what\n";
		}
	}

	public static function is( $actual, $expected, $what ) {
		$cond = ( $actual === $expected );
		self::ok( $cond, $what . ( $cond ? '' : ' (got ' . var_export( $actual, true ) . ', want ' . var_export( $expected, true ) . ')' ) );
	}

	public static function summary() {
		echo "\n" . self::$passed . " passed, " . self::$failed . " failed\n";
		return self::$failed === 0 ? 0 : 1;
	}
}

/* ---- fake $wpdb -------------------------------------------------------- */

class Fake_WPDB {
	public $prefix     = 'wp_';
	public $postmeta   = 'wp_postmeta';
	public $last_error = '';

	/** @var array<string, array<int, array>> table => rows */
	public $tables = array();

	/** @var bool Force the next insert() to fail (simulates a DB error). */
	public $fail_insert = false;

	/** @var bool Force the next update() to fail (simulates a DB error). */
	public $fail_update = false;

	/** @var int Auto-increment counter. */
	private $next_id = 1;

	/** @var array<int, array{post_id:int,meta_key:string,meta_value:string}> */
	public $meta_rows = array();

	public function prepare( $sql, ...$args ) {
		// Good enough for the fixed set of placeholders this codebase uses.
		$sql = str_replace( array( '%d', '%f' ), '%s', $sql );
		$esc = array_map(
			function ( $a ) {
				return is_int( $a ) || is_float( $a ) ? $a : "'" . str_replace( "'", "''", (string) $a ) . "'";
			},
			$args
		);
		return vsprintf( str_replace( '%s', '%s', $sql ), $esc );
	}

	public function insert( $table, $data ) {
		if ( $this->fail_insert ) {
			$this->last_error = 'simulated insert failure';
			return false;
		}
		if ( ! isset( $this->tables[ $table ] ) ) {
			$this->tables[ $table ] = array();
		}
		// Emulate the UNIQUE (token, partner_id) key on cha_redemptions.
		if ( false !== strpos( $table, 'cha_redemptions' ) ) {
			foreach ( $this->tables[ $table ] as $row ) {
				if ( $row['token'] === $data['token'] && $row['partner_id'] === $data['partner_id'] ) {
					$this->last_error = 'Duplicate entry';
					return false;
				}
			}
		}
		$data['id']               = $this->next_id++;
		$this->tables[ $table ][] = $data;
		return 1;
	}

	public function delete( $table, $where ) {
		if ( ! isset( $this->tables[ $table ] ) ) {
			return 0;
		}
		$before                 = count( $this->tables[ $table ] );
		$this->tables[ $table ] = array_values(
			array_filter(
				$this->tables[ $table ],
				function ( $row ) use ( $where ) {
					foreach ( $where as $k => $v ) {
						if ( ! isset( $row[ $k ] ) || $row[ $k ] !== $v ) {
							return true; // keep
						}
					}
					return false; // matches every condition → delete
				}
			)
		);
		return $before - count( $this->tables[ $table ] );
	}

	public function get_var( $sql ) {
		// Only used for the redemptions uniqueness pre-check.
		if ( preg_match( "/token = '([^']*)' AND partner_id = '([^']*)'/", $sql, $m ) ) {
			foreach ( $this->tables['wp_cha_redemptions'] ?? array() as $row ) {
				if ( $row['token'] === $m[1] && $row['partner_id'] === $m[2] ) {
					return $row['id'];
				}
			}
		}
		return null;
	}

	private function row_matches( $row, $where ) {
		foreach ( $where as $k => $v ) {
			if ( ! isset( $row[ $k ] ) || $row[ $k ] !== $v ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Minimal SELECT * FROM <table> WHERE <col> = '<val>' LIMIT 1 — enough for
	 * find_by_token()/find_by_reference(), the only get_row() calls this
	 * codebase makes.
	 *
	 * @return object|null
	 */
	public function get_row( $sql ) {
		if ( ! preg_match( '/FROM\s+(\S+)\s+WHERE\s+(\w+)\s*=\s*\'([^\']*)\'/i', $sql, $m ) ) {
			throw new Exception( 'Fake_WPDB::get_row got an unexpected statement: ' . $sql );
		}
		list( , $table, $col, $val ) = $m;
		foreach ( $this->tables[ $table ] ?? array() as $row ) {
			if ( isset( $row[ $col ] ) && (string) $row[ $col ] === $val ) {
				return (object) $row;
			}
		}
		return null;
	}

	/**
	 * Conditional UPDATE. Only rows matching every $where key are touched —
	 * this is what makes a `WHERE status = 'pending'` update an atomic
	 * pending→paid transition here the same way MySQL's row lock makes it one
	 * in production: a second call against an already-'paid' row matches
	 * nothing and returns 0, not an error.
	 *
	 * @return int|false Rows affected, or false to simulate a DB error.
	 */
	public function update( $table, $data, $where ) {
		if ( $this->fail_update ) {
			$this->last_error = 'simulated update failure';
			return false;
		}
		if ( ! isset( $this->tables[ $table ] ) ) {
			return 0;
		}
		$affected = 0;
		foreach ( $this->tables[ $table ] as $i => $row ) {
			if ( $this->row_matches( $row, $where ) ) {
				$this->tables[ $table ][ $i ] = array_merge( $row, $data );
				$affected++;
			}
		}
		return $affected;
	}

	/**
	 * The only raw query this codebase issues: the atomic usedCount claim.
	 * Evaluated here the way MySQL evaluates it — predicate and write in one
	 * indivisible step — and returns the affected-row count.
	 */
	public function query( $sql ) {
		if ( ! preg_match( "/UPDATE\s+wp_postmeta/s", $sql ) ) {
			throw new Exception( 'Fake_WPDB::query got an unexpected statement: ' . $sql );
		}
		if ( ! preg_match( "/post_id = '?(\d+)'?/", $sql, $pm ) ) {
			throw new Exception( 'no post_id in: ' . $sql );
		}
		$post_id = (int) $pm[1];

		$capped = preg_match( "/CAST\(meta_value AS UNSIGNED\) < '?(\d+)'?/", $sql, $cm );
		$max    = $capped ? (int) $cm[1] : null;

		$affected = 0;
		foreach ( $this->meta_rows as $i => $row ) {
			if ( $row['post_id'] !== $post_id || 'usedCount' !== $row['meta_key'] ) {
				continue;
			}
			$current = (int) $row['meta_value'];
			if ( null !== $max && $current >= $max ) {
				continue;
			}
			$this->meta_rows[ $i ]['meta_value'] = (string) ( $current + 1 );
			$affected++;
		}
		return $affected;
	}
}

$GLOBALS['wpdb'] = new Fake_WPDB();

/* ---- WP function stubs ------------------------------------------------- */

$GLOBALS['cha_test_transients'] = array();
$GLOBALS['cha_test_now']        = strtotime( '2026-08-26 12:00:00 UTC' );
$GLOBALS['cha_test_http']       = array();  // log of outbound HTTP calls
$GLOBALS['cha_test_http_next']  = null;     // canned response for the next call
// error_log() is a PHP builtin and cannot be stubbed; route it to a file the
// tests can read back instead.
$GLOBALS['cha_test_log_file'] = sys_get_temp_dir() . '/cha-test-error.log';
@unlink( $GLOBALS['cha_test_log_file'] );
ini_set( 'log_errors', '1' );
ini_set( 'error_log', $GLOBALS['cha_test_log_file'] );

/**
 * Everything error_log()'d since the last cha_log_clear().
 *
 * @return string
 */
function cha_log_read() {
	return @file_get_contents( $GLOBALS['cha_test_log_file'] ) ?: '';
}

function cha_log_clear() {
	@unlink( $GLOBALS['cha_test_log_file'] );
}

function get_transient( $key ) {
	$t = $GLOBALS['cha_test_transients'][ $key ] ?? null;
	if ( null === $t ) {
		return false;
	}
	if ( $t['expires'] <= $GLOBALS['cha_test_now'] ) {
		unset( $GLOBALS['cha_test_transients'][ $key ] );
		return false;
	}
	return $t['value'];
}

function set_transient( $key, $value, $ttl ) {
	$GLOBALS['cha_test_transients'][ $key ] = array(
		'value'   => $value,
		'expires' => $GLOBALS['cha_test_now'] + $ttl,
	);
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['cha_test_transients'][ $key ] );
	return true;
}

function current_time( $type = 'mysql' ) {
	return 'timestamp' === $type ? $GLOBALS['cha_test_now'] : gmdate( 'Y-m-d H:i:s', $GLOBALS['cha_test_now'] );
}

function wp_unslash( $v ) {
	return $v;
}

function wp_json_encode( $v ) {
	return json_encode( $v );
}

function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}

function sanitize_text_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}

function wp_remote_post( $url, $args = array() ) {
	$GLOBALS['cha_test_http'][] = array( 'method' => 'POST', 'url' => $url, 'args' => $args );
	$next                       = $GLOBALS['cha_test_http_next'];
	$GLOBALS['cha_test_http_next'] = null;
	return null === $next
		? array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'status' => true, 'data' => array( 'authorization_url' => 'https://checkout.paystack.test/abc' ) ) ) )
		: $next;
}

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['cha_test_http'][] = array( 'method' => 'GET', 'url' => $url, 'args' => $args );
	$next                       = $GLOBALS['cha_test_http_next'];
	$GLOBALS['cha_test_http_next'] = null;
	return $next ?? array( 'response' => array( 'code' => 200 ), 'body' => '{}' );
}

function wp_remote_retrieve_response_code( $r ) {
	return $r['response']['code'] ?? 0;
}

function wp_remote_retrieve_body( $r ) {
	return $r['body'] ?? '';
}

function wp_cache_delete( $key, $group = '' ) {
	return true;
}

/* ---- mail (webhook idempotency test) ------------------------------------ */

$GLOBALS['cha_test_mail']      = array();  // log of wp_mail() calls
$GLOBALS['cha_test_mail_fail'] = false;    // force the next wp_mail() to fail

function wp_mail( $to, $subject, $message, $headers = array() ) {
	$GLOBALS['cha_test_mail'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
		'headers' => $headers,
	);
	return ! $GLOBALS['cha_test_mail_fail'];
}

function get_bloginfo( $show = '' ) {
	return 'Clarens Heritage Trail';
}

function get_option( $key, $default = false ) {
	return $default;
}

function esc_html( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES );
}

function esc_url( $s ) {
	return (string) $s;
}

/* ---- post meta (backed by Fake_WPDB::$meta_rows) ----------------------- */

function get_post_meta( $post_id, $key, $single = false ) {
	global $wpdb;
	$hits = array();
	foreach ( $wpdb->meta_rows as $row ) {
		if ( $row['post_id'] === (int) $post_id && $row['meta_key'] === $key ) {
			$hits[] = $row['meta_value'];
		}
	}
	if ( $single ) {
		return $hits ? $hits[0] : '';
	}
	return $hits;
}

function add_post_meta( $post_id, $key, $value, $unique = false ) {
	global $wpdb;
	if ( $unique && '' !== (string) get_post_meta( $post_id, $key, true ) ) {
		return false;
	}
	$wpdb->meta_rows[] = array( 'post_id' => (int) $post_id, 'meta_key' => $key, 'meta_value' => (string) $value );
	return true;
}

function update_post_meta( $post_id, $key, $value ) {
	global $wpdb;
	foreach ( $wpdb->meta_rows as $i => $row ) {
		if ( $row['post_id'] === (int) $post_id && $row['meta_key'] === $key ) {
			$wpdb->meta_rows[ $i ]['meta_value'] = (string) $value;
			return true;
		}
	}
	return add_post_meta( $post_id, $key, $value );
}

/* ---- REST + plugin collaborators --------------------------------------- */

class WP_Error {
	private $msg;
	public function __construct( $msg = 'error' ) {
		$this->msg = $msg;
	}
	public function get_error_message() {
		return $this->msg;
	}
}

class WP_REST_Request {
	private $params;
	private $body;
	private $headers;
	public function __construct( $params = array(), $body = '', $headers = array() ) {
		$this->params = $params;
		$this->body   = $body;
		// Case-insensitive lookup, the way the real WP_REST_Request normalises header names.
		$this->headers = array();
		foreach ( $headers as $k => $v ) {
			$this->headers[ strtolower( $k ) ] = $v;
		}
	}
	public function get_param( $k ) {
		return $this->params[ $k ] ?? null;
	}
	public function get_body() {
		return $this->body;
	}
	public function get_header( $name ) {
		return $this->headers[ strtolower( $name ) ] ?? null;
	}
}

class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

class CHA_Env {
	public static $vars = array( 'PAYSTACK_SECRET_KEY' => 'sk_test_stub' );
	public static function get( $k, $default = '' ) {
		return self::$vars[ $k ] ?? $default;
	}
}

class CHA_Settings {
	public static function unlock_price_cents() {
		return 9900;
	}
}

class CHA_Tokens {
	public static $n = 0;
	public static function generate() {
		self::$n++;
		return sprintf( 'CHT-TEST-%04d', self::$n );
	}
	public static function touch( $t ) {}
	public static function resolve( $t ) {
		return null;
	}
}
