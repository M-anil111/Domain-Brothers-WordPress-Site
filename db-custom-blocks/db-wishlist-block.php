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
		$ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? (string) $_SERVER['HTTP_CF_CONNECTING_IP'] : ( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return $ip;
	}
}

if ( ! function_exists( 'db_wl_allow' ) ) {
	/** Counts one use of $key; false once it has been used $limit times inside $window seconds. */
	function db_wl_allow( $key, $limit, $window ) {
		$k = 'db_wl_rl_' . md5( $key );
		$n = (int) get_transient( $k );
		if ( $n >= $limit ) {
			return false;
		}
		set_transient( $k, $n + 1, $window );
		return true;
	}
}

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
		$t = $wpdb->prefix . 'db_wl_codes';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s", $email ) );
		if ( ! $row || (int) $row->expires_at < time() ) {
			return false;
		}
		if ( (int) $row->attempts >= 5 ) {
			$wpdb->delete( $t, array( 'email' => $email ) );
			return false;
		}
		if ( ! hash_equals( $row->code_hash, db_wl_hash( $email . '|' . $code ) ) ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET attempts = attempts + 1 WHERE email = %s", $email ) );
			return false;
		}
		$wpdb->delete( $t, array( 'email' => $email ) ); // a code works once
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
			$body = $json( $req );
			if ( function_exists( 'db_ts_verify' ) && ! db_ts_verify( $body['ts'] ?? '', db_wl_ip() ) ) {
				return $send( array( 'ok' => false, 'message' => 'Please complete the check, then try again.' ), 400 );
			}
			$email = db_wl_clean_email( $body['email'] ?? '' );
			if ( '' === $email ) {
				return $send( array( 'ok' => false, 'message' => 'Enter a valid email address.' ), 400 );
			}
			db_wl_send_code( $email ); // the same answer whether or not it was sent
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

if ( ! function_exists( 'db_wl_script' ) ) {
	/** The shared client script: button + sign-in dialog on a domain page, or the sign-in box on the wishlist page. */
	function db_wl_script( $post_id, $title ) {
		$cfg = array(
			'api'   => esc_url_raw( rest_url( 'db/v1/wishlist/' ) ),
			'post'  => (int) $post_id,
			'title' => strtolower( $title ),
			'page'  => esc_url_raw( home_url( '/my-wishlist/' ) ),
		);
		ob_start();
		?>
<script id="db-wishlist-js">
(function () {
	'use strict';
	var C = <?php echo wp_json_encode( $cfg ); ?>;
	var state = { signedIn: false, saved: [] }, dlg, pendingPost = 0;

	function api(path, method, body) {
		return fetch(C.api + path, { method: method, credentials: 'same-origin', headers: body ? { 'Content-Type': 'text/plain' } : {}, body: body ? JSON.stringify(body) : undefined })
			.then(function (r) { return r.json().then(function (j) { j.__status = r.status; return j; }); });
	}
	function el(html) { var d = document.createElement('div'); d.innerHTML = html.trim(); return d.firstChild; }
	function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

	/* ---- dialog ---- */
	function ensureDialog() {
		if (dlg) { return dlg; }
		dlg = el('<dialog class="db-wl-dialog" aria-labelledby="db-wl-title">' +
			'<form method="dialog" class="db-wl-x"><button aria-label="Close">&times;</button></form>' +
			'<h2 id="db-wl-title">Save to your wishlist</h2>' +
			'<p class="db-wl-lead">We email you a 6-digit code. No password needed.</p>' +
			'<form class="db-wl-step" data-step="email"><label>Email address<input type="email" name="email" autocomplete="email" required maxlength="190" placeholder="you@example.com"></label><div class="db-ts"></div><button type="submit" class="db-wl-primary">Email me a code</button></form>' +
			'<form class="db-wl-step" data-step="code" hidden><label>6-digit code<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required placeholder="123456"></label><button type="submit" class="db-wl-primary">Sign in</button><button type="button" class="db-wl-link" data-back>Use a different email</button></form>' +
			'<p class="db-wl-msg" role="status" aria-live="polite"></p></dialog>');
		document.body.appendChild(dlg);
		var emailForm = dlg.querySelector('[data-step=email]'), codeForm = dlg.querySelector('[data-step=code]'), msg = dlg.querySelector('.db-wl-msg'), email = '';
		function say(t, bad) { msg.textContent = t; msg.className = 'db-wl-msg' + (bad ? ' is-bad' : ''); }
		emailForm.addEventListener('submit', function (e) {
			e.preventDefault(); email = emailForm.email.value.trim(); say('Sending…');
			api('code', 'POST', { email: email, ts: window.dbTsToken ? window.dbTsToken(emailForm) : '' }).then(function (j) {
				if (j.ok) { emailForm.hidden = true; codeForm.hidden = false; codeForm.code.focus(); say(j.message); } else { say(j.message || 'Something went wrong.', true); }
			}).catch(function () { say('Could not reach the server. Try again.', true); });
		});
		codeForm.addEventListener('submit', function (e) {
			e.preventDefault(); say('Checking…');
			api('verify', 'POST', { email: email, code: codeForm.code.value, post: pendingPost || 0 }).then(function (j) {
				if (j.ok) { state.signedIn = true; state.saved = j.saved || []; dlg.close(); refresh(); toast(j.added === 'added' ? 'Saved to your wishlist.' : 'You are signed in.'); if (onSignedIn) { onSignedIn(); } }
				else { say(j.message || 'That code did not work.', true); }
			}).catch(function () { say('Could not reach the server. Try again.', true); });
		});
		codeForm.querySelector('[data-back]').addEventListener('click', function () { codeForm.hidden = true; emailForm.hidden = false; emailForm.email.focus(); say(''); });
		dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });
		return dlg;
	}
	var onSignedIn = null;
	function openSignIn(post) {
		pendingPost = post || 0;
		var d = ensureDialog();
		d.querySelector('[data-step=email]').hidden = false; d.querySelector('[data-step=code]').hidden = true; d.querySelector('.db-wl-msg').textContent = '';
		if (d.showModal) { d.showModal(); } else { d.setAttribute('open', ''); }
			if (window.dbTsRender) { window.dbTsRender(d); }
		d.querySelector('input[name=email]').focus();
	}

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
		var box = document.getElementById('db-wl-signin');
		if (box) {
			var b = box.querySelector('button'); if (b) { b.addEventListener('click', function () { onSignedIn = function () { window.location.reload(); }; openSignIn(0); }); }
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

	function start() {
		api('state', 'GET').then(function (j) { state.signedIn = !!j.signedIn; state.saved = j.saved || []; refresh(); }).catch(function () {});
		mountButton(); mountPage();
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
</script>
		<?php
		return (string) ob_get_clean();
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
		echo '<h1 class="page-title">My account</h1><div class="db-wl-card" id="db-wl-signin"><h2>Sign in with your email</h2><p>Save the domains you like and follow them: we tell you when the price changes, a domain sells or someone makes an offer. See recently listed and hot domains, and make and track your offers. We email you a 6-digit code; there is no password to remember.</p><button type="button" class="db-wl-primary">Email me a code</button></div>';
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
