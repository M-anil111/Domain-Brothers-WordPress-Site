<?php
/**
 * Wishlist: visitors save domains and see them again later.
 *
 * Sign-in is passwordless. A visitor gives an email address, we send a
 * six-digit code, and the code opens their wishlist page (/my-wishlist/).
 *
 *   code      6 digits, stored only as a keyed hash, valid 10 minutes, 5 wrong
 *             guesses burn it, one live code per address
 *   session   random 256-bit token in an HttpOnly, Secure, SameSite=Lax cookie;
 *             only its hash is stored; lasts 30 days; Sign out deletes it
 *   limits    5 codes per address and 20 per network address per hour; the
 *             reply never says whether an address is known
 *   writes    must come from this site (Origin / Referer check) and carry the
 *             session cookie
 *
 * Domain pages get an "Add to wishlist" button (db-wishlist.js is inline, no
 * build step). The team sees, per domain, how many people are watching it
 * (Domain Brothers > Wishlists), and that count is the "Watching" figure in
 * Domain Statistics on each domain page.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_WL_TABLE_VERSION' ) ) {
	define( 'DB_WL_TABLE_VERSION', '1' );
}
if ( ! defined( 'DB_WL_COOKIE' ) ) {
	define( 'DB_WL_COOKIE', 'db_wl' );
}
if ( ! defined( 'DB_WL_CODE_TTL' ) ) {
	define( 'DB_WL_CODE_TTL', 600 );
}
if ( ! defined( 'DB_WL_SESSION_TTL' ) ) {
	define( 'DB_WL_SESSION_TTL', 30 * DAY_IN_SECONDS );
}
if ( ! defined( 'DB_WL_MAX_ITEMS' ) ) {
	define( 'DB_WL_MAX_ITEMS', 200 );
}

/* ───────────────────────────── 1. Tables ──────────────────────────────── */

add_action( 'init', function () {
	if ( get_option( 'db_wl_table_version', '' ) !== DB_WL_TABLE_VERSION ) {
		global $wpdb;
		$cs = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$wpdb->prefix}db_wl_items (
			id         INT          NOT NULL AUTO_INCREMENT,
			email      VARCHAR(190) NOT NULL,
			post_id    INT          NOT NULL,
			created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_item (email, post_id),
			KEY idx_post (post_id)
		) {$cs};" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}db_wl_codes (
			email      VARCHAR(190) NOT NULL,
			code_hash  CHAR(64)     NOT NULL,
			attempts   TINYINT      NOT NULL DEFAULT 0,
			expires_at INT          NOT NULL,
			PRIMARY KEY (email)
		) {$cs};" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}db_wl_sessions (
			token_hash CHAR(64)     NOT NULL,
			email      VARCHAR(190) NOT NULL,
			expires_at INT          NOT NULL,
			created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (token_hash),
			KEY idx_email (email),
			KEY idx_exp (expires_at)
		) {$cs};" );
		update_option( 'db_wl_table_version', DB_WL_TABLE_VERSION );
	}
	if ( ! wp_next_scheduled( 'db_wl_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'db_wl_purge' );
	}
} );

add_action( 'db_wl_purge', function () {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}db_wl_codes WHERE expires_at < %d", time() ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}db_wl_sessions WHERE expires_at < %d", time() ) );
	// phpcs:enable
} );

// A deleted domain leaves every wishlist.
add_action( 'deleted_post', function ( $post_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $wpdb->prefix . 'db_wl_items', array( 'post_id' => (int) $post_id ), array( '%d' ) );
} );

/* ───────────────────────────── 2. Helpers ─────────────────────────────── */

if ( ! function_exists( 'db_wl_is_staging' ) ) {
	function db_wl_is_staging() {
		// One definition of "staging" for the whole plugin (see db_is_staging_host in db-safety-block.php).
		return function_exists( 'db_is_staging_host' ) ? db_is_staging_host() : false;
	}
}

if ( ! function_exists( 'db_wl_clean_email' ) ) {
	function db_wl_clean_email( $raw ) {
		$e = strtolower( trim( (string) $raw ) );
		return ( strlen( $e ) <= 190 && is_email( $e ) ) ? $e : '';
	}
}

if ( ! function_exists( 'db_wl_hash' ) ) {
	function db_wl_hash( $value ) {
		return hash_hmac( 'sha256', (string) $value, wp_salt( 'auth' ) );
	}
}

if ( ! function_exists( 'db_wl_ip' ) ) {
	function db_wl_ip() {
		// REMOTE_ADDR only. The server already sets it to the visitor's address for requests that
		// come through Cloudflare; the CF-Connecting-IP header itself can be sent by anyone who
		// reaches the origin directly, which gave a fresh rate-limit quota per request (TL-16).
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}

if ( ! function_exists( 'db_wl_allow' ) ) {
	/** Counts one use of $key; false once it has been used $limit times inside $window seconds. */
	function db_wl_allow( $key, $limit, $window ) {
		// One atomic counter per key with a fixed window (TL-16, AC-13): the old transient was read
		// and then written, so parallel requests all saw the same count, and every hit pushed the
		// window out again.
		global $wpdb;
		$t   = $wpdb->prefix . 'db_rate';
		$now = time();
		$win = max( 1, (int) $window );
		db_wl_rate_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$t} (k, n, ws) VALUES (%s, 1, %d) ON DUPLICATE KEY UPDATE n = IF(ws + %d <= %d, 1, n + 1), ws = IF(ws + %d <= %d, %d, ws)",
			md5( $key ), $now, $win, $now, $win, $now, $now
		) );
		if ( false === $ok ) {
			return true; // the limiter must never lock everyone out when the table is missing
		}
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT n FROM {$t} WHERE k = %s", md5( $key ) ) );
		// phpcs:enable
		return $n <= (int) $limit;
	}
}

if ( ! function_exists( 'db_wl_rate_table' ) ) {
	/** Creates the counter table once. */
	function db_wl_rate_table() {
		if ( '1' === get_option( 'db_rate_table_v' ) ) {
			return;
		}
		global $wpdb;
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}db_rate (k CHAR(32) NOT NULL PRIMARY KEY, n INT UNSIGNED NOT NULL DEFAULT 0, ws INT UNSIGNED NOT NULL DEFAULT 0, KEY ws (ws)) " . $wpdb->get_charset_collate() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		update_option( 'db_rate_table_v', '1', true );
	}
}

// Old counters are removed daily (rows whose window ended over a day ago).
add_action( 'db_wl_rate_cleanup', static function () {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}db_rate WHERE ws < %d", time() - DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
} );
add_action( 'init', static function () {
	if ( ! wp_next_scheduled( 'db_wl_rate_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'db_wl_rate_cleanup' );
	}
} );

if ( ! function_exists( 'db_wl_same_origin' ) ) {
	/** The request was made by a page of this site. */
	function db_wl_same_origin() {
		$src = isset( $_SERVER['HTTP_ORIGIN'] ) ? (string) $_SERVER['HTTP_ORIGIN'] : ( isset( $_SERVER['HTTP_REFERER'] ) ? (string) $_SERVER['HTTP_REFERER'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' === $src ) {
			return false;
		}
		$host = wp_parse_url( $src, PHP_URL_HOST );
		return $host && 0 === strcasecmp( $host, (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}
}

/* ───────────────────────────── 3. Codes and sessions ──────────────────── */

if ( ! function_exists( 'db_wl_send_code' ) ) {
	/** Issues a code for $email. Returns false when rate limited (the caller still answers the same way). */
	function db_wl_send_code( $email ) {
		global $wpdb;
		if ( ! db_wl_allow( 'email:' . $email, 5, HOUR_IN_SECONDS ) || ! db_wl_allow( 'ip:' . db_wl_ip(), 20, HOUR_IN_SECONDS ) ) {
			return false;
		}
		$code = (string) random_int( 100000, 999999 );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->replace( $wpdb->prefix . 'db_wl_codes', array(
			'email'      => $email,
			'code_hash'  => db_wl_hash( $email . '|' . $code ),
			'attempts'   => 0,
			'expires_at' => time() + DB_WL_CODE_TTL,
		) );
		// phpcs:enable
		if ( db_wl_is_staging() ) {
			set_transient( 'db_wl_dbg_' . md5( $email ), $code, DB_WL_CODE_TTL ); // read back only by the admin-only staging test route
		}
		$subject = 'Your Domain Brothers sign-in code: ' . $code;
		$body    = "Your sign-in code is {$code}\n\nIt works for 10 minutes. Enter it on the Domain Brothers site to open your wishlist.\n\nIf you did not ask for this, you can ignore this email; nobody can sign in without the code.\n\nDomain Brothers\n" . home_url( '/' );
		$sent = function_exists( 'db_mail_send' ) ? db_mail_send( 'signin_code', $email, array( 'code' => $code, 'minutes' => (string) (int) ( DB_WL_CODE_TTL / 60 ) ) ) : null;
		if ( null === $sent ) {
			wp_mail( $email, $subject, $body );
		}
		return true;
	}
}

if ( ! function_exists( 'db_wl_check_code' ) ) {
	function db_wl_check_code( $email, $code ) {
		global $wpdb;
		$code = preg_replace( '/\D/', '', (string) $code );
		if ( 6 !== strlen( $code ) ) {
			return false;
		}
		// Per address, across codes: a new code resets the per-code counter, this one is not (AC-13).
		if ( ! db_wl_allow( 'verify-email:' . strtolower( (string) $email ), 10, HOUR_IN_SECONDS ) ) {
			return false;
		}
		$t = $wpdb->prefix . 'db_wl_codes';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Claim one guess atomically before comparing: parallel guesses each need their own claim,
		// so at most five are ever checked against one code (AC-13).
		$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET attempts = attempts + 1 WHERE email = %s AND attempts < 5 AND expires_at >= %d", $email, time() ) );
		if ( ! $claimed ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE email = %s AND ( attempts >= 5 OR expires_at < %d )", $email, time() ) );
			return false;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s", $email ) );
		if ( ! $row || ! hash_equals( $row->code_hash, db_wl_hash( $email . '|' . $code ) ) ) {
			return false;
		}
		// A code works once: only the request that deletes it signs in.
		if ( ! $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE email = %s AND code_hash = %s", $email, $row->code_hash ) ) ) {
			return false;
		}
		// phpcs:enable
		return true;
	}
}

if ( ! function_exists( 'db_wl_start_session' ) ) {
	function db_wl_start_session( $email ) {
		global $wpdb;
		$token = bin2hex( random_bytes( 32 ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $wpdb->prefix . 'db_wl_sessions', array(
			'token_hash' => db_wl_hash( $token ),
			'email'      => $email,
			'expires_at' => time() + DB_WL_SESSION_TTL,
		) );
		if ( ! headers_sent() ) {
			setcookie( DB_WL_COOKIE, $token, array(
				'expires'  => time() + DB_WL_SESSION_TTL,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			) );
		}
		$_COOKIE[ DB_WL_COOKIE ] = $token;
		if ( ! headers_sent() ) {
			// Readable hint (no secret) so a cached page can tell the browser to load the account menu.
			setcookie( 'db_in', '1', array( 'expires' => time() + DB_WL_SESSION_TTL, 'path' => '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
		}
	}
}

if ( ! function_exists( 'db_wl_email' ) ) {
	/** The signed-in address, or ''. */
	function db_wl_email() {
		global $wpdb;
		static $memo = array();
		$token = isset( $_COOKIE[ DB_WL_COOKIE ] ) ? (string) $_COOKIE[ DB_WL_COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			return '';
		}
		if ( isset( $memo[ $token ] ) ) {
			return $memo[ $token ];
		}
		$t = $wpdb->prefix . 'db_wl_sessions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$email = (string) $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$t} WHERE token_hash = %s AND expires_at > %d", db_wl_hash( $token ), time() ) );
		return $memo[ $token ] = $email;
	}
}

if ( ! function_exists( 'db_wl_end_session' ) ) {
	function db_wl_end_session() {
		global $wpdb;
		$token = isset( $_COOKIE[ DB_WL_COOKIE ] ) ? (string) $_COOKIE[ DB_WL_COOKIE ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->prefix . 'db_wl_sessions', array( 'token_hash' => db_wl_hash( $token ) ) );
		}
		if ( ! headers_sent() ) {
			setcookie( DB_WL_COOKIE, '', array( 'expires' => time() - DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			setcookie( 'db_in', '', array( 'expires' => time() - DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
		}
		unset( $_COOKIE[ DB_WL_COOKIE ] );
	}
}

/* ───────────────────────────── 4. Wishlist data ───────────────────────── */

if ( ! function_exists( 'db_wl_ids' ) ) {
	/** @return int[] domain post ids saved by $email, newest first */
	function db_wl_ids( $email ) {
		global $wpdb;
		$t = $wpdb->prefix . 'db_wl_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$t} WHERE email = %s ORDER BY id DESC", $email ) ) );
	}
}

if ( ! function_exists( 'db_wl_count' ) ) {
	/** How many people have this domain on their wishlist. */
	function db_wl_count( $post_id ) {
		global $wpdb;
		$t = $wpdb->prefix . 'db_wl_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE post_id = %d", (int) $post_id ) );
	}
}

if ( ! function_exists( 'db_wl_toggle' ) ) {
	/** Adds or removes. Returns 'added', 'removed' or a WP_Error. */
	function db_wl_toggle( $email, $post_id ) {
		global $wpdb;
		$post = get_post( (int) $post_id );
		$t    = $wpdb->prefix . 'db_wl_items';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE email = %s AND post_id = %d", $email, (int) $post_id ) );
		if ( $has ) {
			$wpdb->delete( $t, array( 'email' => $email, 'post_id' => (int) $post_id ) );
			return 'removed';
		}
		if ( ! $post || 'domain' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return new WP_Error( 'db_wl_bad', 'That domain cannot be saved.', array( 'status' => 404 ) );
		}
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE email = %s", $email ) ) >= DB_WL_MAX_ITEMS ) {
			return new WP_Error( 'db_wl_full', 'Your wishlist is full.', array( 'status' => 400 ) );
		}
		$wpdb->insert( $t, array( 'email' => $email, 'post_id' => (int) $post_id ) );
		// phpcs:enable
		return 'added';
	}
}

/* ───────────────────────────── 5. REST ────────────────────────────────── */

add_action( 'rest_api_init', function () {
	$send = static function ( $data, $status = 200 ) {
		$res = new WP_REST_Response( $data, $status );
		$res->header( 'Cache-Control', 'private, no-store' );
		return $res;
	};
	$json = static function ( WP_REST_Request $req ) {
		$d = json_decode( (string) $req->get_body(), true );
		return is_array( $d ) ? $d : array();
	};

	register_rest_route( 'db/v1', '/wishlist/state', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function () use ( $send ) {
			$email = db_wl_email();
			return $send( array( 'signedIn' => '' !== $email, 'saved' => '' !== $email ? db_wl_ids( $email ) : array() ) );
		},
	) );

	register_rest_route( 'db/v1', '/wishlist/code', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) use ( $send, $json ) {
			if ( ! db_wl_same_origin() ) {
				return $send( array( 'ok' => false, 'message' => 'Please use the website.' ), 403 );
			}
			$body  = $json( $req );
			$email = db_wl_clean_email( $body['email'] ?? '' );
			// A sign-up that already passed the check at /register/ (its pending record exists) may ask
			// for a new code from the code step, where no fresh check can be solved: at most 3 times in
			// 30 minutes (bug register batch 2, AC-2).
			$resend = '' !== $email && false !== get_transient( 'db_reg_' . md5( $email ) ) && db_wl_allow( 'reg-resend:' . $email, 3, 30 * MINUTE_IN_SECONDS );
			if ( ! $resend && function_exists( 'db_ts_verify' ) && ! db_ts_verify( $body['ts'] ?? '', db_wl_ip() ) ) {
				return $send( array( 'ok' => false, 'message' => 'Please complete the check, then try again.' ), 400 );
			}
			if ( '' === $email ) {
				return $send( array( 'ok' => false, 'message' => 'Enter a valid email address.' ), 400 );
			}
			// The limit is per address, so saying so reveals nothing about whether an account exists (AC-6).
			if ( ! db_wl_send_code( $email ) ) {
				return $send( array( 'ok' => false, 'message' => 'Too many codes were asked for this address in the last hour. Use the newest code you received, or try again in an hour.' ), 429 );
			}
			return $send( array( 'ok' => true, 'message' => 'If that address is right, a 6-digit code is on its way. It works for 10 minutes.' ) );
		},
	) );

	register_rest_route( 'db/v1', '/wishlist/verify', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) use ( $send, $json ) {
			if ( ! db_wl_same_origin() ) {
				return $send( array( 'ok' => false, 'message' => 'Please use the website.' ), 403 );
			}
			$d     = $json( $req );
			$email = db_wl_clean_email( $d['email'] ?? '' );
			if ( '' === $email || ! db_wl_allow( 'verify:' . db_wl_ip(), 30, HOUR_IN_SECONDS ) ) {
				return $send( array( 'ok' => false, 'message' => 'That code did not work. Ask for a new one.' ), 400 );
			}
			if ( ! db_wl_check_code( $email, $d['code'] ?? '' ) ) {
				return $send( array( 'ok' => false, 'message' => 'That code did not work. Check it, or ask for a new one.' ), 400 );
			}
			db_wl_start_session( $email );
			$added = null;
			if ( ! empty( $d['post'] ) ) {
				$ids = db_wl_ids( $email );
				if ( ! in_array( (int) $d['post'], $ids, true ) ) {
					$r     = db_wl_toggle( $email, (int) $d['post'] );
					$added = is_wp_error( $r ) ? null : $r;
				}
			}
			return $send( array( 'ok' => true, 'saved' => db_wl_ids( $email ), 'added' => $added ) );
		},
	) );

	register_rest_route( 'db/v1', '/wishlist/toggle', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) use ( $send, $json ) {
			$email = db_wl_email();
			if ( '' === $email ) {
				return $send( array( 'ok' => false, 'signedIn' => false ), 401 );
			}
			if ( ! db_wl_same_origin() ) {
				return $send( array( 'ok' => false ), 403 );
			}
			$r = db_wl_toggle( $email, (int) ( $json( $req )['post'] ?? 0 ) );
			if ( is_wp_error( $r ) ) {
				return $send( array( 'ok' => false, 'message' => $r->get_error_message() ), (int) ( $r->get_error_data()['status'] ?? 400 ) );
			}
			return $send( array( 'ok' => true, 'result' => $r, 'saved' => db_wl_ids( $email ) ) );
		},
	) );

	register_rest_route( 'db/v1', '/wishlist/logout', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function () use ( $send ) {
			if ( ! db_wl_same_origin() ) {
				return $send( array( 'ok' => false ), 403 );
			}
			db_wl_end_session();
			return $send( array( 'ok' => true ) );
		},
	) );

	// Staging only, administrators only: lets the automated test read the code it asked for, because staging cannot send email.
	register_rest_route( 'db/v1', '/wishlist/test-code', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return db_wl_is_staging() && current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $req ) use ( $send ) {
			$email = db_wl_clean_email( $req->get_param( 'email' ) );
			return $send( array( 'code' => '' !== $email ? (string) get_transient( 'db_wl_dbg_' . md5( $email ) ) : '' ) );
		},
	) );
} );

/* ───────────────────────────── 6. Domain page button ──────────────────── */

add_action( 'wp_footer', function () {
	if ( is_admin() || ! is_singular( 'domain' ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || '' !== $post->post_password ) {
		return;
	}
	if ( function_exists( 'db_listing_state' ) && in_array( db_listing_state( $post ), array( 'sold' ), true ) ) {
		return;
	}
	echo db_wl_script( (int) $post->ID, wp_strip_all_tags( $post->post_title ) ); // phpcs:ignore WordPress.Security.EscapeOutput
}, 70 );

// Every other public page gets the same script without a domain, so the
// Sign in / Create account links in the menus open the dialog anywhere.
add_action( 'wp_footer', function () {
	if ( is_admin() || is_singular( 'domain' ) || db_wl_is_page_request() || is_customize_preview() ) {
		return;
	}
	echo db_wl_script( 0, '' ); // phpcs:ignore WordPress.Security.EscapeOutput
}, 70 );

// Friendly addresses: /sign-in/, /login/, /register/, /sign-up/, /account/ open the account page with the dialog.
add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) ) {
		return;
	}
	$map  = array( 'sign-in' => 'signin', 'signin' => 'signin', 'login' => 'signin', 'log-in' => 'signin', 'account' => 'signin', 'my-account' => 'signin' );
	$path = db_sales_request_path();
	if ( isset( $map[ $path ] ) ) {
		wp_safe_redirect( add_query_arg( 'auth', $map[ $path ], home_url( '/my-wishlist/' ) ), 302 );
		exit;
	}
}, 2 );

if ( ! function_exists( 'db_wl_script' ) ) {
	/** The shared client script: button + sign-in dialog on a domain page, or the sign-in box on the wishlist page. */
	function db_wl_script( $post_id, $title ) {
		$cfg = array(
			'api'   => esc_url_raw( rest_url( 'db/v1/wishlist/' ) ),
			'post'  => (int) $post_id,
			'title' => strtolower( $title ),
			'page'  => esc_url_raw( home_url( '/my-wishlist/' ) ),
			'home'  => esc_url_raw( home_url( '/' ) ),
		);
		ob_start();
		?>
<script id="db-wishlist-js">
(function () {
	'use strict';
	var C = <?php echo wp_json_encode( $cfg ); ?>;
	var state = { signedIn: false, saved: [] };

	function api(path, method, body) {
		return fetch(C.api + path, { method: method, credentials: 'same-origin', headers: body ? { 'Content-Type': 'text/plain' } : {}, body: body ? JSON.stringify(body) : undefined })
			.then(function (r) { return r.json().then(function (j) { j.__status = r.status; return j; }); });
	}
	function el(html) { var d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstChild; }
	function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

	/* ---- sign in is a page, not a popup ---- */
	var onSignedIn = null;
	function signInUrl(post, mode) {
		if (mode === 'register') { return C.home + 'register/'; }
		var q = '?auth=signin';
		if (post) { q += '&add=' + encodeURIComponent(post) + '&name=' + encodeURIComponent(C.title || '') + '&next=' + encodeURIComponent(location.href.split('#')[0]); }
		return C.page + q;
	}
	function openSignIn(post, mode) { window.location.href = signInUrl(post, mode); }

	/* ---- toast ---- */
	function toast(t) {
		var n = el('<div class="db-wl-toast" role="status"></div>'); n.innerHTML = esc(t) + ' <a href="' + C.page + '">View my wishlist</a>';
		document.body.appendChild(n); setTimeout(function () { n.classList.add('is-in'); }, 20); setTimeout(function () { n.remove(); }, 6000);
	}

	/* ---- domain page button ---- */
	var btn;
	function refresh() {
		if (!btn) { return; }
		var on = state.saved.indexOf(C.post) !== -1;
		btn.setAttribute('aria-pressed', on ? 'true' : 'false');
		btn.querySelector('span').textContent = on ? 'Saved to wishlist' : 'Add to wishlist';
		btn.classList.toggle('is-on', on);
	}
	function mountButton() {
		if (!C.post) { return; }
		var host = document.querySelector('.domain-detail-form.make_an_offer') || document.querySelector('.db-pr-pay');
		if (!host) { return; }
		btn = el('<button type="button" class="db-wl-btn" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5s-7.5-4.6-9.2-9.3C1.6 7.700 3.600 4.500 7 4.500c2 0 3.400 1 5 3 1.600-2 3-3 5-3 3.400 0 5.400 3.200 4.200 6.700-1.700 4.700-9.200 9.300-9.200 9.300z"/></svg><span>Add to wishlist</span></button>');
		var wrap = el('<div class="db-wl-wrap"></div>'); wrap.appendChild(btn);
		wrap.appendChild(el('<p class="db-wl-note">Save it and come back any time. <a href="' + C.page + '">My wishlist</a></p>'));
		host.appendChild(wrap);
		btn.addEventListener('click', function () {
			if (!state.signedIn) { onSignedIn = null; openSignIn(C.post); return; }
			btn.disabled = true;
			api('toggle', 'POST', { post: C.post }).then(function (j) {
				btn.disabled = false;
				if (j.__status === 401) { state.signedIn = false; openSignIn(C.post); return; }
				if (j.ok) { state.saved = j.saved; refresh(); toast(j.result === 'added' ? 'Saved to your wishlist.' : 'Removed from your wishlist.'); }
				else { toast(j.message || 'Could not save that.'); }
			}).catch(function () { btn.disabled = false; });
		});
		refresh();
	}

	/* ---- wishlist page ---- */
	function mountPage() {
		var box = document.getElementById('db-au');
		var qs = new URLSearchParams(location.search), addId = parseInt(qs.get('add') || '0', 10) || 0, nextUrl = qs.get('next') || '';
		if (nextUrl.indexOf(C.home) !== 0) { nextUrl = ''; }
		if (box) {
			if (addId) {
				var note = document.createElement('p'); note.className = 'db-au-note'; note.setAttribute('role', 'status');
				note.textContent = 'Sign in to save ' + (qs.get('name') || 'this domain') + ' to your wishlist. New here? The same code creates your account.';
				var first = box.querySelector('form'); if (first) { first.parentNode.insertBefore(note, first); }
			}
			var ef = box.querySelector('[data-step=email]'), cf = box.querySelector('[data-step=code]'), m = box.querySelector('.db-au-msg'), mail = '';
			var tell = function (t, bad) { m.textContent = t; m.className = 'db-au-msg' + (bad ? ' is-bad' : ''); };
			if (qs.get('plan') === '1' && !addId) {
				var pn = document.createElement('p'); pn.className = 'db-au-note'; pn.setAttribute('role', 'status');
				pn.textContent = 'Payment plans need a free account, so you can see your schedule and next payment in My account. Enter your email for a sign-in code; new here, the same code creates your account. You go straight back to checkout.';
				var f0 = box.querySelector('form'); if (f0) { f0.parentNode.insertBefore(pn, f0); }
			}
			if (window.dbTsRender) { window.dbTsRender(box); }
			ef.addEventListener('submit', function (e) {
				e.preventDefault(); mail = ef.email.value.trim(); tell('Sending your code…');
				api('code', 'POST', { email: mail, ts: window.dbTsToken ? window.dbTsToken(ef) : '' }).then(function (j) {
					if (j.ok) { ef.hidden = true; cf.hidden = false; cf.code.focus(); tell(j.message); } else { tell(j.message || 'Something went wrong. Try again.', true); }
				}).catch(function () { tell('Could not reach the server. Try again.', true); });
			});
			cf.addEventListener('submit', function (e) {
				e.preventDefault(); tell('Checking…');
				api('verify', 'POST', { email: mail, code: cf.code.value, post: addId }).then(function (j) {
					if (j.ok) { tell(addId ? 'You are signed in and the domain is saved.' : 'You are signed in.'); window.location.href = nextUrl || C.page; } else { tell(j.message || 'That code did not work.', true); }
				}).catch(function () { tell('Could not reach the server. Try again.', true); });
			});
			cf.querySelector('[data-back]').addEventListener('click', function () { cf.hidden = true; ef.hidden = false; ef.email.focus(); tell(''); });
		}
		var out = document.getElementById('db-wl-signout');
		if (out) { out.addEventListener('click', function () { api('logout', 'POST', {}).then(function () { window.location.reload(); }); }); }
		document.querySelectorAll('[data-wl-remove]').forEach(function (b) {
			b.addEventListener('click', function () {
				b.disabled = true;
				api('toggle', 'POST', { post: parseInt(b.getAttribute('data-wl-remove'), 10) }).then(function (j) {
					if (j.ok) { var row = b.closest('[data-wl-row]'); if (row) { row.remove(); } var n = document.querySelectorAll('[data-wl-row]').length; var h = document.getElementById('db-wl-count'); if (h) { h.textContent = n; } if (!n) { window.location.reload(); } }
					else { b.disabled = false; }
				});
			});
		});
	}

	/* ---- account links in the menus (Sign in / Create account / My account / Sign out) ---- */
	function paintAccount() {
		document.querySelectorAll('[data-db-acct]').forEach(function (a) {
			var kind = a.getAttribute('data-db-acct'), li = a.closest('li') || a;
			if (kind === 'signin') { a.textContent = state.signedIn ? 'My account' : 'Sign in'; a.href = state.signedIn ? C.page : a.getAttribute('data-href'); }
			if (kind === 'register') { li.hidden = state.signedIn; }
			if (kind === 'signout') { li.hidden = !state.signedIn; }
		});
		document.documentElement.classList.toggle('db-signed-in', state.signedIn);
		if (state.signedIn && window.dbHdAcctLoad) { window.dbHdAcctLoad(); }
	}
	function goAccount() { if (location.pathname.indexOf('/my-wishlist') === 0) { window.location.reload(); } else { window.location.href = C.page; } }
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('[data-db-acct]');
		if (!a) { return; }
		var kind = a.getAttribute('data-db-acct');
		if (kind === 'signout') {
			e.preventDefault();
			api('logout', 'POST', {}).then(function () { window.location.href = C.home; });
			return;
		}
		if (state.signedIn) { return; }
		// Sign in and Create account are pages (/sign-in/, /register/), not a popup: let the link go.
		if (kind === 'signin' || kind === 'register') { return; }
		e.preventDefault();
		var toggle = document.getElementById('db-mnav-toggle');
		if (toggle && toggle.getAttribute('aria-expanded') === 'true') { toggle.click(); }
		onSignedIn = goAccount;
		openSignIn(0, kind === 'register' ? 'register' : 'signin');
	});
	window.dbAccount = { open: function (mode) { onSignedIn = goAccount; openSignIn(0, mode === 'register' ? 'register' : 'signin'); }, signedIn: function () { return state.signedIn; } };

	function start() {
		api('state', 'GET').then(function (j) {
			state.signedIn = !!j.signedIn; state.saved = j.saved || []; refresh(); paintAccount();
			var m = /[?&]auth=(signin|register)\b/.exec(location.search);
			if (m && !state.signedIn && !document.getElementById('db-au') && m[1] === 'signin') { onSignedIn = goAccount; openSignIn(0, m[1]); }
		}).catch(function () {});
		mountButton(); mountPage();
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
</script>
		<?php
		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'db_wl_auth_html' ) ) {
	/**
	 * The signed-out account page: a centred sign-in card, or the create-account
	 * card with the reasons to have an account beside it. Sign-in here has no
	 * password: the visitor enters an email, then the 6-digit code we send.
	 *
	 * @param string $mode 'signin' or 'register'.
	 * @return string
	 */
	function db_wl_auth_html( $mode ) {
		$register = 'register' === $mode;
		$signin   = esc_url( home_url( '/sign-in/' ) );
		$signup   = esc_url( home_url( '/register/' ) );
		$icon     = function ( $path ) {
			return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
		};
		$mail = $icon( '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>' );
		$key  = $icon( '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>' );

		$email_field = '<label class="db-au-label" for="db-au-email">' . ( $register ? 'Email address <span aria-hidden="true">*</span>' : 'Your email' ) . '</label>'
			. '<div class="db-au-field"><span class="db-au-ico">' . $mail . '</span><input id="db-au-email" type="email" name="email" autocomplete="email" required maxlength="190" placeholder="you@example.com"></div>';
		$terms = $register
			? '<label class="db-au-check"><input type="checkbox" name="terms" required> <span>I agree to the <a href="' . esc_url( home_url( '/terms-of-use/' ) ) . '">terms of use</a> and the <a href="' . esc_url( home_url( '/privacy-policy/' ) ) . '">privacy policy</a>. <span aria-hidden="true">*</span></span></label>'
			: '';
		$forms = '<form class="db-au-step" data-step="email">' . $email_field . $terms . '<div class="db-ts"></div>'
			. '<button type="submit" class="db-au-btn">' . ( $register ? 'Create account' : 'Sign in' ) . '</button>'
			. '<p class="db-au-hint">No password to remember. We email you a 6-digit code.</p></form>'
			. '<form class="db-au-step" data-step="code" hidden><label class="db-au-label" for="db-au-code">Your 6-digit code</label>'
			. '<div class="db-au-field"><span class="db-au-ico">' . $key . '</span><input id="db-au-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required placeholder="123456"></div>'
			. '<button type="submit" class="db-au-btn">' . ( $register ? 'Confirm and create account' : 'Confirm and sign in' ) . '</button>'
			. '<button type="button" class="db-au-link" data-back>Use a different email</button></form>'
			. '<p class="db-au-msg" role="status" aria-live="polite"></p>';

		if ( ! $register ) {
			$chips = '';
			foreach ( array( 'Wishlist' => '#0b7a75', 'Price alerts' => '#d97706', 'Offers' => '#7c3aed', 'Payment plans' => '#2563eb' ) as $label => $color ) {
				$chips .= '<li><span style="background:' . esc_attr( $color ) . '"></span>' . esc_html( $label ) . '</li>';
			}
			return '<section id="db-au" class="db-au db-au--signin" aria-labelledby="db-au-h">'
				. '<span class="db-au-blob db-au-blob--a" aria-hidden="true"></span><span class="db-au-blob db-au-blob--b" aria-hidden="true"></span>'
				. '<h1 id="db-au-h" class="db-au-title">Good to see you again</h1>'
				. '<div class="db-au-card">' . $forms
				. '<p class="db-au-links"><a href="' . $signup . '">Don&rsquo;t have an account?</a><a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Trouble signing in?</a></p></div>'
				. '<ul class="db-au-chips" aria-label="What your account holds">' . $chips . '</ul>'
				. ( function_exists( 'db_seller_enabled' ) && db_seller_enabled() ? '<div class="db-au-box db-au-box--sell"><h2>Own domains you no longer use?</h2><p>List them on Domain Brothers for free. Our brokers handle every buyer, and you pay a commission only when a name sells.</p><a class="db-au-btn2" href="' . esc_url( home_url( '/sell-your-domains/' ) ) . '">Sell your domains</a></div>' : '' ) . '</section>';
		}

		$props = '';
		$items = array(
			array( 'Price and status alerts', 'Follow a name and hear when its price changes, when someone makes an offer or when it sells.', '<path d="M6 16V11a6 6 0 1112 0v5l2 2H4z"/><path d="M10 20a2 2 0 004 0"/>' ),
			array( 'Your offers in one place', 'Make an offer, see the reply and pick up the conversation from any device.', '<path d="M4 6h16v11H9l-5 4z"/><path d="M8 10h8M8 13h5"/>' ),
			array( 'A wishlist that follows you', 'Save the names you like once. They are there on your phone, tablet and desktop.', '<path d="M12 20s-7-4.4-8.6-8.8C2.300 8 4.200 5 7.300 5c1.900 0 3.200 1 4.700 2.800C13.500 6 14.800 5 16.700 5c3.100 0 5 3 3.900 6.200C19 15.600 12 20 12 20z"/>' ),
		);
		foreach ( $items as $it ) {
			$props .= '<li><span class="db-au-pico">' . $icon( $it[2] ) . '</span><p><strong>' . esc_html( $it[0] ) . '</strong> ' . esc_html( $it[1] ) . '</p></li>';
		}
		return '<section id="db-au" class="db-au db-au--register" aria-labelledby="db-au-h"><div class="db-au-grid">'
			. '<div class="db-au-card"><h1 id="db-au-h" class="db-au-title">Create account</h1><p class="db-au-sub">Get started with a free account.</p><p class="db-au-req"><span aria-hidden="true">*</span> indicates a required field.</p>'
			. $forms . '<p class="db-au-alt">Already have an account? <a href="' . $signin . '">Sign in</a>.</p></div>'
			. '<div class="db-au-side"><h2>Never lose track of a name you want.</h2><p>An account keeps every domain you are watching, and every offer you have made, in one place.</p><ul class="db-au-props">' . $props . '</ul>'
			. '<div class="db-au-box"><h2>Want a name that is not listed?</h2><p>You do not need an account to ask. Tell us the domain and a broker replies within one business day.</p><a class="db-au-btn2" href="' . esc_url( home_url( '/acquire-premium-domain/' ) ) . '">Acquire a domain</a></div>' . ( function_exists( 'db_seller_enabled' ) && db_seller_enabled() ? '<div class="db-au-box db-au-box--sell"><h2>Own domains you no longer use?</h2><p>List them on Domain Brothers for free. Our brokers handle every buyer, and you pay a commission only when a name sells.</p><a class="db-au-btn2" href="' . esc_url( home_url( '/sell-your-domains/' ) ) . '">Sell your domains</a></div>' : '' ) . '</div>'
			. '</div></section>';
	}
}

/* ───────────────────────────── 7. The wishlist page ───────────────────── */

if ( ! function_exists( 'db_wl_is_page_request' ) ) {
	function db_wl_is_page_request() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = untrailingslashit( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		return untrailingslashit( (string) wp_parse_url( home_url( '/my-wishlist/' ), PHP_URL_PATH ) ) === $path;
	}
}

add_filter( 'body_class', function ( $c ) {
	if ( db_wl_is_page_request() ) {
		$c[] = 'db-wishlist-page';
	}
	return $c;
} );

if ( ! function_exists( 'db_wl_row_html' ) ) {
	function db_wl_row_html( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'domain' !== $post->post_type || 'publish' !== $post->post_status ) {
			return '';
		}
		$state = function_exists( 'db_listing_state' ) ? db_listing_state( $post ) : 'offer';
		$price = function_exists( 'db_dd_price' ) ? db_dd_price( $post->ID ) : 0.0;
		$label = array(
			'standard'     => $price > 0 ? '$' . number_format( $price, 0 ) : 'Open to offers',
			'offer'        => 'Open to offers',
			'sold'         => 'Sold',
			'not_for_sale' => 'Not for sale',
		)[ $state ] ?? 'Open to offers';
		$cta = array( 'standard' => 'Buy now', 'offer' => 'Make an offer', 'sold' => 'View', 'not_for_sale' => 'View' )[ $state ] ?? 'View';
		return '<li class="db-wl-row" data-wl-row><a class="db-wl-name" href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( strtolower( wp_strip_all_tags( $post->post_title ) ) ) . '</a>'
			. '<span class="db-wl-price' . ( in_array( $state, array( 'sold', 'not_for_sale' ), true ) ? ' is-off' : '' ) . '">' . esc_html( $label ) . '</span>'
			. '<a class="db-wl-view' . ( 'offer' === $state ? ' is-offer' : '' ) . '" href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( $cta ) . '</a>'
			. '<button type="button" class="db-wl-remove" data-wl-remove="' . (int) $post->ID . '" aria-label="Remove ' . esc_attr( strtolower( wp_strip_all_tags( $post->post_title ) ) ) . ' from my wishlist">Remove</button></li>';
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! db_wl_is_page_request() ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'db-wishlist' );
	nocache_headers();
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'X-Robots-Tag: noindex, nofollow' );
	status_header( 200 );
	global $wp_query;
	$wp_query->is_404 = false;

	$GLOBALS['db_drawn_page'] = true; // og:url (db-rankmath-block.php)
	$email = db_wl_email();
	$stop  = function_exists( 'db_acct_handle_stop' ) ? db_acct_handle_stop() : '';
	get_header();
	echo '<div id="primary" class="content-area"><main id="main" class="site-main db-wl-page">';
	echo '<nav class="db-breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li><li><span aria-current="page">My account</span></li></ol></nav>';
	if ( 'ok' === $stop ) {
		echo '<div class="db-wl-card db-ac-notice" role="status"><h2>Alert emails are off</h2><p>We will not email you about domains you follow. You can still see every alert in your account, and turn emails back on from the Alerts tab.</p></div>';
	} elseif ( 'bad' === $stop ) {
		echo '<div class="db-wl-card db-ac-notice" role="alert"><h2>That link did not work</h2><p>Sign in and use the switch on the Alerts tab to stop these emails.</p></div>';
	}
	if ( '' === $email ) {
		if ( isset( $_GET['auth'] ) && 'register' === sanitize_key( wp_unslash( $_GET['auth'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( home_url( '/register/' ), 302 );
			exit;
		}
		if ( function_exists( 'db_wl_intro_active' ) && db_wl_intro_active() ) {
			// No ?auth= card requested: explain the wishlist first (db-wishlist-intro-block.php).
			echo db_wl_intro_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		} else {
			$auth_mode = isset( $_GET['auth'] ) && 'register' === sanitize_key( wp_unslash( $_GET['auth'] ) ) ? 'register' : 'signin'; // phpcs:ignore WordPress.Security.NonceVerification
			echo db_wl_auth_html( $auth_mode ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		}
	} else {
		$ids  = db_wl_ids( $email );
		$rows = '';
		foreach ( $ids as $id ) {
			$rows .= db_wl_row_html( $id );
		}
		$count = (int) substr_count( $rows, 'data-wl-row' );
		if ( function_exists( 'db_acct_render' ) ) {
			echo db_acct_render( $email, $rows, $count ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		} else {
			echo '<h1 class="page-title">My wishlist <span>(<span id="db-wl-count">' . (int) $count . '</span>)</span></h1>';
			echo '<p class="db-wl-who">Signed in as <strong>' . esc_html( $email ) . '</strong> <button type="button" class="db-wl-link" id="db-wl-signout">Sign out</button></p>';
			echo '' === $rows ? '<div class="db-wl-card"><h2>Nothing saved yet</h2><p>Open any domain and choose <strong>Add to wishlist</strong>.</p></div>' : '<ul class="db-wl-list">' . $rows . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
	echo '</main></div>';
	if ( '' !== $email && function_exists( 'db_acct_script' ) ) {
		$t = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'saved'; // phpcs:ignore WordPress.Security.NonceVerification
		echo db_acct_script( $t ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	echo db_wl_script( 0, '' ); // phpcs:ignore WordPress.Security.EscapeOutput
	get_footer();
	exit;
}, 5 );

add_filter( 'wp_robots', function ( $r ) {
	if ( db_wl_is_page_request() ) {
		$r['noindex'] = true;
		$r['nofollow'] = true;
	}
	return $r;
} );

/* ───────────────────────────── 8. Admin: who is watching what ─────────── */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Wishlists', 'Wishlists', 'manage_options', 'db-wishlists', 'db_wl_admin_page' );
}, 24 );

if ( ! function_exists( 'db_wl_admin_rows' ) ) {
	function db_wl_admin_rows() {
		global $wpdb;
		$t = $wpdb->prefix . 'db_wl_items';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( "SELECT i.post_id, p.post_title, COUNT(*) AS watchers, MAX(i.created_at) AS last_at, GROUP_CONCAT(i.email ORDER BY i.id DESC SEPARATOR ', ') AS emails FROM {$t} i JOIN {$wpdb->posts} p ON p.ID = i.post_id GROUP BY i.post_id, p.post_title ORDER BY watchers DESC, last_at DESC LIMIT 500" );
	}
}

if ( ! function_exists( 'db_wl_admin_page' ) ) {
	function db_wl_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$rows = db_wl_admin_rows();
		echo '<div class="wrap"><h1>Wishlists</h1><p>Domains visitors have saved, most watched first. A visitor signs in with an emailed code, so every address here was confirmed. <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=db_wl_export' ), 'db_wl_export' ) ) . '">Export CSV</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>Domain</th><th>Watching</th><th>Last added</th><th>People</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="4">Nothing saved yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td><strong><a href="' . esc_url( get_edit_post_link( $r->post_id ) ) . '">' . esc_html( strtolower( $r->post_title ) ) . '</a></strong></td><td>' . (int) $r->watchers . '</td><td>' . esc_html( wp_date( 'M j, Y g:i a', strtotime( $r->last_at . ' UTC' ) ) ) . '</td><td>' . esc_html( $r->emails ) . '</td></tr>';
		}
		echo '</tbody></table>';
		do_action( 'db_wl_admin_after' );
		echo '</div>';
	}
}

add_action( 'admin_post_db_wl_export', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_wl_export' ) ) {
		wp_die( 'Not allowed.' );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="db-wishlists-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Domain', 'Watching', 'Last added (UTC)', 'Emails' ) );
	foreach ( db_wl_admin_rows() as $r ) {
		$name = strtolower( $r->post_title );
		fputcsv( $out, array( ( '' !== $name && false !== strpos( '=+-@', $name[0] ) ) ? "'" . $name : $name, (int) $r->watchers, $r->last_at, $r->emails ) );
	}
	fclose( $out );
	exit;
} );

/* The wishlist is per-person: keep it out of the page cache and out of the sitemap-style crawls. */
add_filter( 'rest_post_dispatch', function ( $res, $server, $req ) {
	if ( 0 === strpos( (string) $req->get_route(), '/db/v1/wishlist/' ) && $res instanceof WP_REST_Response ) {
		$res->header( 'Cache-Control', 'private, no-store' );
	}
	return $res;
}, 10, 3 );

/*
 * The account page is rendered by this plugin on top of a 404 query, so the
 * theme and Rank Math titled it "Page Not Found" (bug RS-2). Give it a real
 * title and description for whoever is looking: signed in or not.
 */
if ( ! function_exists( 'db_wl_page_title' ) ) {
	function db_wl_page_title() {
		$signed = function_exists( 'db_wl_email' ) && '' !== db_wl_email();
		return ( $signed ? 'My account' : 'Sign in or create an account' ) . ' | Domain Brothers';
	}
}
foreach ( array( 'pre_get_document_title', 'rank_math/frontend/title', 'rank_math/opengraph/facebook/og_title' ) as $db_wl_hook ) {
	add_filter( $db_wl_hook, function ( $t ) {
		return db_wl_is_page_request() ? db_wl_page_title() : $t;
	}, 999 );
}
foreach ( array( 'rank_math/frontend/description', 'rank_math/opengraph/facebook/og_description' ) as $db_wl_hook ) {
	add_filter( $db_wl_hook, function ( $d ) {
		return db_wl_is_page_request() ? 'Your Domain Brothers account: saved domains, price alerts, offers, payment plans and transfers in one place. Sign in with a one-time email code.' : $d;
	}, 999 );
}
unset( $db_wl_hook );
add_filter( 'rank_math/frontend/robots', function ( $r ) {
	if ( db_wl_is_page_request() ) {
		$r['index'] = 'noindex';
	}
	return $r;
}, 999 );
