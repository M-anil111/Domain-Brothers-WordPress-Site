<?php
/**
 * Create account: a real page at /register/ (no popup), with the details we need.
 *
 * Step 1  Full name and email (required), phone (optional), a newsletter
 *         opt-in (unticked), and agreement to the Terms of Use, Privacy Policy,
 *         Refund Policy and Payment Terms (required).
 * Step 2  The 6-digit code we email. Sign-in on this site has no password: the
 *         code proves the address is the person's. Nothing is stored as an
 *         account until the code is confirmed.
 * Step 3  Optional questions about what the person is looking for, kept on their
 *         account so the account area can be useful from the first visit.
 *
 * The consent record (policy version, time, a hash of the IP) and the newsletter
 * choice are stored with the account. The newsletter is only a flag here: it is
 * not sent from this plugin, and do_action( 'db_account_registered' ) lets a
 * mailing tool pick the person up. Accounts and newsletter opt-ins are listed
 * under Domain Brothers > Accounts, with a CSV export.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_POLICY_VERSION' ) ) {
	/** Bump when the Terms, Privacy, Refund or Payment terms change materially. */
	define( 'DB_POLICY_VERSION', '2026-11-19' );
}
if ( ! defined( 'DB_ACCT_SCHEMA' ) ) {
	define( 'DB_ACCT_SCHEMA', '1' );
}

/* ------------------------------------------------------------------ storage */

if ( ! function_exists( 'db_acct_table' ) ) {
	function db_acct_table() {
		global $wpdb;
		return $wpdb->prefix . 'db_accounts';
	}
}

add_action( 'init', function () {
	if ( get_option( 'db_accounts_schema' ) === DB_ACCT_SCHEMA ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = db_acct_table();
	dbDelta( "CREATE TABLE {$t} (
		email varchar(190) NOT NULL,
		full_name varchar(120) NOT NULL DEFAULT '',
		phone varchar(40) NOT NULL DEFAULT '',
		newsletter tinyint(1) NOT NULL DEFAULT 0,
		newsletter_at datetime NULL,
		consent_version varchar(20) NOT NULL DEFAULT '',
		consent_at datetime NULL,
		consent_ip_hash char(64) NOT NULL DEFAULT '',
		profile longtext NULL,
		profile_at datetime NULL,
		guide_seen tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (email)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'db_accounts_schema', DB_ACCT_SCHEMA, false );
}, 20 );

if ( ! function_exists( 'db_acct_row' ) ) {
	/** The account row for an address, or null. */
	function db_acct_row( $email ) {
		global $wpdb;
		$t = db_acct_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s", strtolower( (string) $email ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}

if ( ! function_exists( 'db_acct_upsert' ) ) {
	/**
	 * Creates or updates an account row.
	 *
	 * @param string              $email Address (lowercase).
	 * @param array<string,mixed> $data  Columns to set.
	 */
	function db_acct_upsert( $email, array $data ) {
		global $wpdb;
		$email = strtolower( (string) $email );
		$t     = db_acct_table();
		$data['updated_at'] = current_time( 'mysql', true );
		if ( db_acct_row( $email ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $t, $data, array( 'email' => $email ) );
		} else {
			$data['email']      = $email;
			$data['created_at'] = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $t, $data );
		}
	}
}

/* --------------------------------------------------------------- questions */

if ( ! function_exists( 'db_reg_questions' ) ) {
	/**
	 * The profile questions: one definition for the sign-up step, the Profile tab
	 * and the server-side check of what is posted.
	 *
	 * @return array<string,array{label:string,type:string,options:array<string,string>}>
	 */
	function db_reg_questions() {
		return array(
			'intent'    => array( 'label' => 'What brings you here?', 'type' => 'single', 'options' => array(
				'brand'      => 'A name for my business or brand',
				'invest'     => 'Buying names as an investment',
				'sell'       => 'Selling a domain I own',
				'handle'     => 'A social media handle',
				'browsing'   => 'Just looking around',
			) ),
			'budget'    => array( 'label' => 'What budget do you have in mind?', 'type' => 'single', 'options' => array(
				'under-2500' => 'Under $2,500',
				'2500-10000' => '$2,500 to $10,000',
				'10000-plus' => '$10,000 and above',
				'unsure'     => 'Not sure yet',
			) ),
			'interests' => array( 'label' => 'Which kinds of names interest you?', 'type' => 'multi', 'options' => array(
				'short'      => 'Short names (3 to 5 characters)',
				'brandable'  => 'Brandable names',
				'keyword'    => 'Keyword names for an industry',
				'canadian'   => 'Canadian (.ca) names',
				'com'        => '.com names',
				'numeric'    => 'Numbers',
			) ),
			'timeline'  => array( 'label' => 'How soon do you want to buy?', 'type' => 'single', 'options' => array(
				'now'        => 'As soon as I find the right name',
				'soon'       => 'In the next 1 to 3 months',
				'later'      => 'Later, I am researching',
			) ),
			'industry'  => array( 'label' => 'Your industry or idea (optional)', 'type' => 'text', 'options' => array() ),
			'source'    => array( 'label' => 'How did you hear about us?', 'type' => 'single', 'options' => array(
				'search'     => 'A search engine',
				'social'     => 'Social media',
				'referral'   => 'A friend or colleague',
				'other'      => 'Somewhere else',
			) ),
		);
	}
}

if ( ! function_exists( 'db_reg_clean_profile' ) ) {
	/**
	 * Keeps only known questions and known answers.
	 *
	 * @param mixed $in Posted answers.
	 * @return array<string,mixed>
	 */
	function db_reg_clean_profile( $in ) {
		$out = array();
		if ( ! is_array( $in ) ) {
			return $out;
		}
		foreach ( db_reg_questions() as $key => $q ) {
			if ( ! isset( $in[ $key ] ) ) {
				continue;
			}
			if ( 'text' === $q['type'] ) {
				$v = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $in[ $key ] ) ) );
				if ( '' !== $v ) {
					$out[ $key ] = mb_substr( $v, 0, 80 );
				}
			} elseif ( 'multi' === $q['type'] ) {
				$vals = array_values( array_intersect( array_map( 'strval', (array) $in[ $key ] ), array_keys( $q['options'] ) ) );
				if ( $vals ) {
					$out[ $key ] = $vals;
				}
			} elseif ( is_string( $in[ $key ] ) && isset( $q['options'][ $in[ $key ] ] ) ) {
				$out[ $key ] = $in[ $key ];
			}
		}
		return $out;
	}
}

/* -------------------------------------------------------------- validation */

if ( ! function_exists( 'db_reg_clean_name' ) ) {
	/** A person's name, or '' when it is not one. */
	function db_reg_clean_name( $raw ) {
		$n = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $raw ) ) );
		if ( mb_strlen( $n ) < 2 || mb_strlen( $n ) > 120 || ! preg_match( '/\p{L}/u', $n ) || preg_match( '#https?://|www\.|@|\d{4,}#i', $n ) ) {
			return '';
		}
		return $n;
	}
}

if ( ! function_exists( 'db_reg_clean_phone' ) ) {
	/** @return string|false Digits with an optional leading +, '' when empty, false when invalid. */
	function db_reg_clean_phone( $raw ) {
		$p = trim( (string) $raw );
		if ( '' === $p ) {
			return '';
		}
		if ( ! preg_match( '/^[0-9+()\-.\s]{7,25}$/', $p ) ) {
			return false;
		}
		$digits = preg_replace( '/\D/', '', $p );
		if ( strlen( $digits ) < 7 || strlen( $digits ) > 15 ) {
			return false;
		}
		return ( '+' === $p[0] ? '+' : '' ) . $digits;
	}
}

/* -------------------------------------------------------------------- REST */

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
	$ip_hash = static function () {
		return hash( 'sha256', ( function_exists( 'db_wl_ip' ) ? db_wl_ip() : '' ) . '|' . wp_salt( 'auth' ) );
	};

	// Step 1: validate the details, keep them for 30 minutes and email the code.
	register_rest_route( 'db/v1', '/account/register', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) use ( $send, $json ) {
			if ( ! db_wl_same_origin() ) {
				return $send( array( 'ok' => false, 'message' => 'Please use the website.' ), 403 );
			}
			$d = $json( $req );
			// Bots: a filled hidden field, or a form posted in under 2.5 seconds, gets the normal answer and no email.
			$bot = ( isset( $d['website'] ) && '' !== trim( (string) $d['website'] ) ) || ( isset( $d['ms'] ) ? (int) $d['ms'] < 2500 : true );
			if ( function_exists( 'db_ts_verify' ) && ! db_ts_verify( $d['ts'] ?? '', db_wl_ip() ) ) {
				return $send( array( 'ok' => false, 'message' => 'Please complete the check, then try again.' ), 400 );
			}
			if ( ! db_wl_allow( 'reg:' . db_wl_ip(), 12, HOUR_IN_SECONDS ) ) {
				return $send( array( 'ok' => false, 'message' => 'Too many attempts. Please try again in an hour.' ), 429 );
			}
			$name  = db_reg_clean_name( $d['name'] ?? '' );
			$email = db_wl_clean_email( $d['email'] ?? '' );
			$phone = db_reg_clean_phone( $d['phone'] ?? '' );
			$err   = array();
			if ( '' === $name ) {
				$err['name'] = 'Enter your full name.';
			}
			if ( '' === $email ) {
				$err['email'] = 'Enter a valid email address.';
			}
			if ( false === $phone ) {
				$err['phone'] = 'Enter a valid phone number, or leave it empty.';
			}
			if ( empty( $d['agree'] ) ) {
				$err['agree'] = 'Please agree to continue.';
			}
			if ( $err ) {
				return $send( array( 'ok' => false, 'message' => 'Please check the highlighted fields.', 'errors' => $err ), 400 );
			}
			if ( ! $bot ) {
				set_transient( 'db_reg_' . md5( $email ), array(
					'name'       => $name,
					'phone'      => (string) $phone,
					'newsletter' => ! empty( $d['newsletter'] ) ? 1 : 0,
				), 30 * MINUTE_IN_SECONDS );
				db_wl_send_code( $email );
			} else {
				error_log( 'DB spam: blocked a registration (hidden field or too fast).' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return $send( array( 'ok' => true, 'message' => 'We sent a 6-digit code to ' . $email . '. It works for 10 minutes.' ) );
		},
	) );

	// Step 2: the code makes it an account and signs the person in.
	register_rest_route( 'db/v1', '/account/register/verify', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) use ( $send, $json, $ip_hash ) {
			if ( ! db_wl_same_origin() ) {
				return $send( array( 'ok' => false, 'message' => 'Please use the website.' ), 403 );
			}
			$d     = $json( $req );
			$email = db_wl_clean_email( $d['email'] ?? '' );
			if ( '' === $email || ! db_wl_allow( 'regv:' . db_wl_ip(), 30, HOUR_IN_SECONDS ) ) {
				return $send( array( 'ok' => false, 'message' => 'That code did not work. Ask for a new one.' ), 400 );
			}
			if ( ! db_wl_check_code( $email, $d['code'] ?? '' ) ) {
				return $send( array( 'ok' => false, 'message' => 'That code did not work. Check it, or ask for a new one.' ), 400 );
			}
			$pending = get_transient( 'db_reg_' . md5( $email ) );
			$row     = db_acct_row( $email );
			if ( is_array( $pending ) ) {
				$now  = current_time( 'mysql', true );
				$data = array(
					'full_name'       => $pending['name'],
					'phone'           => $pending['phone'],
					'newsletter'      => (int) $pending['newsletter'],
					'newsletter_at'   => $pending['newsletter'] ? $now : null,
					'consent_version' => DB_POLICY_VERSION,
					'consent_at'      => $now,
					'consent_ip_hash' => $ip_hash(),
				);
				db_acct_upsert( $email, $data );
				delete_transient( 'db_reg_' . md5( $email ) );
				$row = db_acct_row( $email );
				/** Fires when someone completes sign-up. A mailing tool can hook this for newsletter opt-ins. */
				do_action( 'db_account_registered', $email, $row );
			} elseif ( ! $row ) {
				db_acct_upsert( $email, array() ); // an address that signed in before accounts had details
				$row = db_acct_row( $email );
			}
			db_wl_start_session( $email );
			$needs_profile = empty( $row['profile_at'] ) && is_array( $pending );
			return $send( array( 'ok' => true, 'next' => $needs_profile ? 'profile' : 'account' ) );
		},
	) );

	// Profile and preferences, signed in only.
	register_rest_route( 'db/v1', '/account/profile', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) use ( $send, $json ) {
			if ( ! db_wl_same_origin() ) {
				return $send( array( 'ok' => false, 'message' => 'Please use the website.' ), 403 );
			}
			$email = db_wl_email();
			if ( '' === $email ) {
				return $send( array( 'ok' => false, 'message' => 'Please sign in again.' ), 401 );
			}
			$d    = $json( $req );
			$now  = current_time( 'mysql', true );
			$data = array();
			$row  = db_acct_row( $email ) ?: array();
			if ( array_key_exists( 'name', $d ) ) {
				$name = db_reg_clean_name( $d['name'] );
				if ( '' === $name ) {
					return $send( array( 'ok' => false, 'message' => 'Enter your full name.' ), 400 );
				}
				$data['full_name'] = $name;
			}
			if ( array_key_exists( 'phone', $d ) ) {
				$phone = db_reg_clean_phone( $d['phone'] );
				if ( false === $phone ) {
					return $send( array( 'ok' => false, 'message' => 'Enter a valid phone number, or leave it empty.' ), 400 );
				}
				$data['phone'] = (string) $phone;
			}
			if ( array_key_exists( 'newsletter', $d ) ) {
				$on = ! empty( $d['newsletter'] ) ? 1 : 0;
				if ( $on !== (int) ( $row['newsletter'] ?? 0 ) ) {
					$data['newsletter']    = $on;
					$data['newsletter_at'] = $now;
				}
			}
			if ( isset( $d['answers'] ) ) {
				$data['profile']    = wp_json_encode( db_reg_clean_profile( $d['answers'] ) );
				$data['profile_at'] = $now;
			}
			if ( $data ) {
				db_acct_upsert( $email, $data );
			}
			return $send( array( 'ok' => true, 'message' => 'Saved.' ) );
		},
	) );

	// The "how it works" panel has been seen.
	register_rest_route( 'db/v1', '/account/guide', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function () use ( $send ) {
			$email = db_wl_email();
			if ( '' === $email || ! db_wl_same_origin() ) {
				return $send( array( 'ok' => false ), 401 );
			}
			db_acct_upsert( $email, array( 'guide_seen' => 1 ) );
			return $send( array( 'ok' => true ) );
		},
	) );
} );

/* -------------------------------------------------------------------- page */

add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) ) {
		return;
	}
	if ( ! in_array( db_sales_request_path(), array( 'register', 'sign-up', 'signup', 'create-account' ), true ) ) {
		return;
	}
	if ( function_exists( 'db_wl_email' ) && '' !== db_wl_email() ) {
		wp_safe_redirect( home_url( '/my-wishlist/' ), 302 );
		exit;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'db-register' );
	nocache_headers();
	header( 'Cache-Control: private, no-store, max-age=0' );
	header( 'X-Robots-Tag: noindex, follow' );
	status_header( 200 );
	global $wp_query;
	$wp_query->is_404 = false;
	$GLOBALS['db_drawn_page'] = true; // og:url (db-rankmath-block.php)
	get_header();
	echo '<div id="primary" class="content-area"><main id="main" class="site-main db-reg-page">';
	echo '<nav class="db-breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li><li><span aria-current="page">Create account</span></li></ol></nav>';
	echo db_reg_page_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	echo '</main></div>';
	get_footer();
	exit;
}, 6 );

foreach ( array( 'pre_get_document_title', 'rank_math/frontend/title', 'rank_math/opengraph/facebook/og_title', 'rank_math/opengraph/twitter/twitter_title' ) as $db_reg_hook ) {
	add_filter( $db_reg_hook, function ( $title ) {
		return function_exists( 'db_sales_request_path' ) && in_array( db_sales_request_path(), array( 'register', 'sign-up', 'signup', 'create-account' ), true ) ? 'Create your account | Domain Brothers' : $title;
	}, 32 );
}
unset( $db_reg_hook );

if ( ! function_exists( 'db_reg_page_html' ) ) {
	/** The three-step sign-up. */
	function db_reg_page_html() {
		$cfg = array(
			'api'     => esc_url_raw( rest_url( 'db/v1/' ) ),
			'account' => esc_url_raw( home_url( '/my-wishlist/' ) ),
			'signin'  => esc_url_raw( add_query_arg( 'auth', 'signin', home_url( '/my-wishlist/' ) ) . '#db-au' ),
		);
		$link = function ( $path, $label ) {
			return '<a href="' . esc_url( home_url( $path ) ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
		};
		$check = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12.5l4.500 4.500L19 7.500"/></svg>';

		$q = '';
		foreach ( db_reg_questions() as $key => $def ) {
			$q .= '<fieldset class="db-reg-q"><legend>' . esc_html( $def['label'] ) . '</legend>';
			if ( 'text' === $def['type'] ) {
				$q .= '<input type="text" name="' . esc_attr( $key ) . '" maxlength="80" autocomplete="off" placeholder="For example: dental clinic, fintech app, online shop">';
			} else {
				$q .= '<div class="db-reg-pills">';
				foreach ( $def['options'] as $val => $label ) {
					$q .= '<label><input type="' . ( 'multi' === $def['type'] ? 'checkbox' : 'radio' ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '"><span>' . esc_html( $label ) . '</span></label>';
				}
				$q .= '</div>';
			}
			$q .= '</fieldset>';
		}

		$h  = '<div class="db-reg" id="db-reg" data-cfg="' . esc_attr( wp_json_encode( $cfg ) ) . '"><div class="db-reg-grid">';
		$h .= '<section class="db-reg-card" aria-labelledby="db-reg-h">';
		$h .= '<ol class="db-reg-steps" aria-label="Progress"><li class="is-on" data-dot="details"><span>1</span> Your details</li><li data-dot="code"><span>2</span> Confirm email</li><li data-dot="profile"><span>3</span> About you</li></ol>';

		// Step 1.
		$h .= '<form class="db-reg-step" data-step="details" novalidate><h1 id="db-reg-h" class="db-reg-title">Create your account</h1>'
			. '<p class="db-reg-sub">Free. Save names, follow prices and keep your offers in one place. No password to remember.</p>'
			. '<div class="db-reg-field"><label for="db-reg-name">Full name <span class="db-req" aria-hidden="true">*</span></label><input id="db-reg-name" name="name" type="text" autocomplete="name" required maxlength="120" aria-describedby="db-reg-name-e"><p class="db-reg-err" id="db-reg-name-e" role="alert"></p></div>'
			. '<div class="db-reg-field"><label for="db-reg-email">Email address <span class="db-req" aria-hidden="true">*</span></label><input id="db-reg-email" name="email" type="email" autocomplete="email" required maxlength="190" placeholder="you@example.com" aria-describedby="db-reg-email-e"><p class="db-reg-err" id="db-reg-email-e" role="alert"></p></div>'
			. '<div class="db-reg-field"><label for="db-reg-phone">Phone <span class="db-opt">(optional)</span></label><input id="db-reg-phone" name="phone" type="tel" autocomplete="tel" maxlength="25" placeholder="+1 555 123 4567" aria-describedby="db-reg-phone-e"><p class="db-reg-err" id="db-reg-phone-e" role="alert"></p></div>'
			. '<div class="db-reg-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
			. '<label class="db-reg-check"><input type="checkbox" name="newsletter" value="1"><span>Send me the Domain Brothers newsletter: new premium domains, price drops and short guides. I can unsubscribe at any time.</span></label>'
			. '<label class="db-reg-check"><input type="checkbox" name="agree" value="1" required aria-describedby="db-reg-agree-e"><span>I have read and agree to the ' . $link( '/terms-of-use/', 'Terms of Use' ) . ', ' . $link( '/privacy-policy/', 'Privacy Policy' ) . ', ' . $link( '/refund-policy/', 'Refund Policy (all sales are final)' ) . ' and ' . $link( '/payment-terms/', 'Payment Terms' ) . '. <span class="db-req" aria-hidden="true">*</span></span></label><p class="db-reg-err" id="db-reg-agree-e" role="alert"></p>'
			. '<div class="db-ts"></div><button type="submit" class="db-reg-btn">Send my code</button><p class="db-reg-msg" role="status" aria-live="polite"></p>'
			. '<p class="db-reg-alt">Already have an account? <a href="' . esc_url( $cfg['signin'] ) . '">Sign in</a></p></form>';

		// Step 2.
		$h .= '<form class="db-reg-step" data-step="code" hidden novalidate><h2 class="db-reg-title">Check your email</h2>'
			. '<p class="db-reg-sub">We sent a 6-digit code to <strong data-email></strong>. Enter it to finish. It works for 10 minutes.</p>'
			. '<div class="db-reg-field"><label for="db-reg-code">Your 6-digit code</label><input id="db-reg-code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required placeholder="123456"></div>'
			. '<button type="submit" class="db-reg-btn">Confirm and create account</button><p class="db-reg-msg" role="status" aria-live="polite"></p>'
			. '<p class="db-reg-alt"><button type="button" class="db-reg-link" data-resend>Send a new code</button> <span aria-hidden="true">&middot;</span> <button type="button" class="db-reg-link" data-back>Use a different email</button></p></form>';

		// Step 3.
		$h .= '<form class="db-reg-step" data-step="profile" hidden novalidate><h2 class="db-reg-title">Your account is ready</h2>'
			. '<p class="db-reg-sub">A few optional questions help us show you names that fit. You can change any answer later in your Profile.</p>' . $q
			. '<button type="submit" class="db-reg-btn">Save and go to my account</button><p class="db-reg-msg" role="status" aria-live="polite"></p>'
			. '<p class="db-reg-alt"><button type="button" class="db-reg-link" data-skip>Skip for now</button></p></form>';
		$h .= '</section>';

		// Benefits.
		$h .= '<aside class="db-reg-side" aria-label="What you get"><h2>What your account does</h2><ul>'
			. '<li><span>' . $check . '</span><p><strong>A wishlist that follows you.</strong> Save names once and see them on every device.</p></li>'
			. '<li><span>' . $check . '</span><p><strong>Price and status alerts.</strong> Hear when a saved name changes price, gets an offer or sells.</p></li>'
			. '<li><span>' . $check . '</span><p><strong>Offers in one place.</strong> Make an offer, read the reply and keep the whole conversation.</p></li>'
			. '<li><span>' . $check . '</span><p><strong>Transfers you can follow.</strong> After you buy, see each stage from payment to completion.</p></li></ul>'
			. '<p class="db-reg-note">We email you only about names you follow. Every alert email has a one-click stop link.</p></aside>';
		$h .= '</div></div>';
		return $h;
	}
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'db_sales_request_path' ) || ! in_array( db_sales_request_path(), array( 'register', 'sign-up', 'signup', 'create-account' ), true ) ) {
		return;
	}
	// Designer's tokens: navy #0f1b2d, lime #b5e61d, soft lime #eef8d2, line #e4e7ec, panel #f1f3f6.
	$css = '.db-reg{--n:#0f1b2d;--l:#b5e61d;--ls:#eef8d2;--li:#3f6212;--ln:#e4e7ec;--ink:#0f172a;--tx:#374151;--pn:#f1f3f6;--bad:#b42318}'
		. 'html body.db-ui-active .db-reg-page #primary,html body .db-reg-page{max-width:none}'
		. 'html body:has(.db-reg) #page #content.site-content{padding-left:0!important;padding-right:0!important}'
		. 'html body:has(.db-reg) #page #content.site-content>#primary.content-area{box-sizing:border-box!important;float:none!important;width:100%!important;max-width:1360px!important;margin:0 auto!important;padding-left:clamp(16px,3vw,40px)!important;padding-right:clamp(16px,3vw,40px)!important}'
		. 'html body:has(.db-reg) #main.site-main{max-width:100%!important;margin:0!important;padding:0!important}'
		. '.db-reg-grid{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);gap:clamp(20px,3vw,40px);align-items:start;margin:8px 0 40px}'
		. '.db-reg-card{padding:clamp(22px,3.6vw,40px);border:1px solid var(--ln);border-radius:24px;background:#fff;box-shadow:0 1px 2px rgba(16,24,40,.04),0 12px 30px rgba(16,24,40,.06)}'
		. '.db-reg-steps{display:flex;flex-wrap:wrap;gap:8px 18px;margin:0 0 22px;padding:0;list-style:none;color:#6b7280;font-size:14px;font-weight:700}'
		. '.db-reg-steps li{display:flex;align-items:center;gap:8px;margin:0}.db-reg-steps span{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--pn);color:var(--tx);font-size:13px}'
		. '.db-reg-steps li.is-on{color:var(--ink)}.db-reg-steps li.is-on span{background:var(--l);color:var(--n)}.db-reg-steps li.is-done span{background:var(--n);color:#fff}'
		. 'html body .db-reg-title{margin:0 0 8px;color:var(--ink);font-size:clamp(28px,3.4vw,40px);font-weight:900;line-height:1.1;letter-spacing:-.025em;text-align:left}'
		. '.db-reg-sub{margin:0 0 22px;color:var(--tx);font-size:17px;line-height:1.55}'
		. '.db-reg-field{margin:0 0 16px}.db-reg-field label,.db-reg-q legend{display:block;margin:0 0 6px;color:var(--ink);font-size:15px;font-weight:700}'
		. 'html body .db-reg input[type=text],html body .db-reg input[type=email],html body .db-reg input[type=tel]{box-sizing:border-box;width:100%;height:50px;margin:0;padding:0 16px;border:1.5px solid #d3d9e1;border-radius:12px;background:#fff;color:var(--ink);font-size:16px}'
		. 'html body .db-reg input[type=text]:focus,html body .db-reg input[type=email]:focus,html body .db-reg input[type=tel]:focus{outline:none;border-color:var(--n);box-shadow:0 0 0 3px rgba(181,230,29,.55)}'
		. '.db-req{color:var(--bad)}.db-opt{color:#6b7280;font-weight:500}'
		. '.db-reg-err{min-height:0;margin:6px 0 0;color:var(--bad);font-size:14px}.db-reg-err:empty{display:none}.db-reg-field.has-err input{border-color:var(--bad)}'
		. '.db-reg-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}'
		. '.db-reg-check{display:flex;align-items:flex-start;gap:12px;margin:0 0 14px;color:var(--tx);font-size:15px;line-height:1.5;cursor:pointer}'
		. 'html body .db-reg-check input{flex:none;width:20px;height:20px;margin:2px 0 0;accent-color:var(--n)}.db-reg-check a{color:var(--li);font-weight:700;text-decoration:underline;text-underline-offset:3px}'
		. 'html body button.db-reg-btn{display:inline-flex;align-items:center;justify-content:center;min-height:52px;margin:8px 0 0;padding:0 32px;border:0;border-radius:999px;background:var(--n);color:#fff;font-size:17px;font-weight:800;cursor:pointer;box-shadow:0 6px 16px rgba(15,27,45,.18)}'
		. 'html body button.db-reg-btn:hover{background:#1c2c46}html body button.db-reg-btn:disabled{opacity:.6;cursor:wait}html body button.db-reg-btn:focus-visible,html body .db-reg-link:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(181,230,29,.7)}'
		. '.db-reg-msg{min-height:22px;margin:12px 0 0;font-size:15px;font-weight:600}.db-reg-msg.is-bad{color:var(--bad)}.db-reg-msg.is-good{color:var(--li)}'
		. '.db-reg-alt{margin:16px 0 0;color:var(--tx);font-size:15px}.db-reg-alt a,html body button.db-reg-link{padding:0;border:0;background:none;color:var(--li);font-size:15px;font-weight:700;text-decoration:underline;text-underline-offset:3px;cursor:pointer}'
		. '.db-reg-q{margin:0 0 18px;padding:0;border:0;min-width:0}.db-reg-pills{display:flex;flex-wrap:wrap;gap:8px}'
		. '.db-reg-pills label{margin:0;cursor:pointer}.db-reg-pills input{position:absolute;opacity:0;pointer-events:none}'
		. '.db-reg-pills span{display:inline-flex;align-items:center;min-height:40px;padding:8px 16px;border:1.5px solid #d3d9e1;border-radius:999px;background:#fff;color:var(--ink);font-size:14.5px;font-weight:600;line-height:1.2}'
		. '.db-reg-pills input:checked+span{border-color:var(--l);background:var(--l);color:var(--n);font-weight:800}.db-reg-pills input:focus-visible+span{box-shadow:0 0 0 3px rgba(181,230,29,.7)}'
		. '.db-reg-side{padding:clamp(22px,3.2vw,34px);border-radius:24px;color:#fff;background:radial-gradient(520px 340px at 90% 0%,rgba(25,179,166,.32),rgba(25,179,166,0) 70%),#0c1424}'
		. 'html body .db-reg-side h2{margin:0 0 18px;color:#fff;font-size:clamp(22px,2.4vw,28px);font-weight:900;letter-spacing:-.02em;text-align:left}'
		. '.db-reg-side ul{display:grid;gap:16px;margin:0 0 20px;padding:0;list-style:none}.db-reg-side li{display:flex;gap:14px;margin:0}'
		. '.db-reg-side li span{flex:none;display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:50%;background:var(--l);color:var(--n)}'
		. 'html body .db-reg-side p{margin:0;color:rgba(255,255,255,.86);font-size:15.5px;line-height:1.55}.db-reg-side strong{color:#fff}html body .db-reg-side p.db-reg-note{padding-top:16px;border-top:1px solid rgba(255,255,255,.16);font-size:14px;color:rgba(255,255,255,.72)}'
		. '@media(max-width:991px){.db-reg-grid{grid-template-columns:minmax(0,1fr)}.db-reg-side{order:2}}'
		. '@media(max-width:380px){.db-reg-card{padding:20px 16px}.db-reg-steps{font-size:13px}html body button.db-reg-btn{width:100%}}';
	if ( function_exists( 'db_external_style' ) && db_external_style( 'register', $css ) ) {
		return;
	}
	wp_register_style( 'db-register', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-register' );
	wp_add_inline_style( 'db-register', $css );
}, 1004 );

add_action( 'wp_footer', function () {
	if ( ! function_exists( 'db_sales_request_path' ) || ! in_array( db_sales_request_path(), array( 'register', 'sign-up', 'signup', 'create-account' ), true ) ) {
		return;
	}
	?>
<script data-no-delay>
(function () {
	var root = document.getElementById('db-reg');
	if (!root) { return; }
	var C = JSON.parse(root.getAttribute('data-cfg'));
	var t0 = performance.now(), email = '';
	var steps = {}; [].forEach.call(root.querySelectorAll('.db-reg-step'), function (f) { steps[f.getAttribute('data-step')] = f; });
	function post(path, body) {
		return fetch(C.api + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(body || {}) })
			.then(function (r) { return r.json().then(function (j) { j.__status = r.status; return j; }); });
	}
	function show(name) {
		var order = ['details', 'code', 'profile'];
		Object.keys(steps).forEach(function (k) { steps[k].hidden = k !== name; });
		[].forEach.call(root.querySelectorAll('[data-dot]'), function (d) {
			var i = order.indexOf(d.getAttribute('data-dot')), c = order.indexOf(name);
			d.classList.toggle('is-on', i === c); d.classList.toggle('is-done', i < c);
		});
		var f = steps[name].querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio])'); if (f) { f.focus(); }
		root.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}
	function say(form, t, bad) { var m = form.querySelector('.db-reg-msg'); if (m) { m.textContent = t || ''; m.className = 'db-reg-msg' + (t ? (bad ? ' is-bad' : ' is-good') : ''); } }
	function fieldErrors(form, errs) {
		[].forEach.call(form.querySelectorAll('.db-reg-field'), function (f) { f.classList.remove('has-err'); });
		['name', 'email', 'phone', 'agree'].forEach(function (k) {
			var e = document.getElementById('db-reg-' + k + '-e'); if (e) { e.textContent = (errs && errs[k]) || ''; }
			var i = form.elements[k]; if (i && errs && errs[k] && i.closest('.db-reg-field')) { i.closest('.db-reg-field').classList.add('has-err'); }
		});
	}
	var f1 = steps.details, f2 = steps.code, f3 = steps.profile;
	f1.addEventListener('submit', function (e) {
		e.preventDefault(); fieldErrors(f1, null); say(f1, '');
		var btn = f1.querySelector('button[type=submit]'); btn.disabled = true; say(f1, 'Sending your code…');
		email = f1.elements.email.value.trim();
		post('account/register', { name: f1.elements.name.value, email: email, phone: f1.elements.phone.value, newsletter: f1.elements.newsletter.checked, agree: f1.elements.agree.checked, website: f1.elements.website.value, ms: Math.round(performance.now() - t0), ts: window.dbTsToken ? window.dbTsToken(f1) : '' })
			.then(function (j) {
				btn.disabled = false;
				if (j.ok) { say(f1, ''); f2.querySelector('[data-email]').textContent = email; show('code'); }
				else { fieldErrors(f1, j.errors); say(f1, j.message || 'Could not send the code.', true); }
			}).catch(function () { btn.disabled = false; say(f1, 'Could not reach the server. Try again.', true); });
	});
	f2.addEventListener('submit', function (e) {
		e.preventDefault(); var btn = f2.querySelector('button[type=submit]'); btn.disabled = true; say(f2, 'Checking…');
		post('account/register/verify', { email: email, code: f2.elements.code.value }).then(function (j) {
			btn.disabled = false;
			if (!j.ok) { say(f2, j.message || 'That code did not work.', true); return; }
			if (j.next === 'profile') { show('profile'); } else { window.location.href = C.account; }
		}).catch(function () { btn.disabled = false; say(f2, 'Could not reach the server. Try again.', true); });
	});
	f2.querySelector('[data-back]').addEventListener('click', function () { show('details'); });
	f2.querySelector('[data-resend]').addEventListener('click', function () {
		say(f2, 'Sending a new code…'); post('wishlist/code', { email: email, ts: window.dbTsToken ? window.dbTsToken(f1) : '' }).then(function (j) { say(f2, j.message || 'Sent.', !j.ok); });
	});
	f3.addEventListener('submit', function (e) {
		e.preventDefault(); var btn = f3.querySelector('button[type=submit]'); btn.disabled = true; say(f3, 'Saving…');
		var a = {};
		['intent', 'budget', 'timeline', 'source'].forEach(function (k) { var c = f3.querySelector('input[name=' + k + ']:checked'); if (c) { a[k] = c.value; } });
		a.interests = [].map.call(f3.querySelectorAll('input[name=interests]:checked'), function (i) { return i.value; });
		a.industry = f3.elements.industry.value;
		post('account/profile', { answers: a }).then(function (j) {
			if (j.ok) { say(f3, 'Saved. Taking you to your account…'); window.location.href = C.account; }
			else { btn.disabled = false; say(f3, j.message || 'Could not save that.', true); }
		}).catch(function () { btn.disabled = false; say(f3, 'Could not reach the server. Try again.', true); });
	});
	f3.querySelector('[data-skip]').addEventListener('click', function () { window.location.href = C.account; });
}());
</script>
	<?php
}, 92 );

/* ------------------------------------------------------------------- admin */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Accounts', 'Accounts', 'manage_options', 'db-accounts', 'db_acct_admin_page' );
}, 27 );

add_action( 'admin_post_db_accounts_csv', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_accounts_csv' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	global $wpdb;
	$t = db_acct_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY created_at DESC", ARRAY_A );
	$only = ! empty( $_GET['newsletter'] ); // phpcs:ignore WordPress.Security.NonceVerification
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="accounts' . ( $only ? '-newsletter' : '' ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fputcsv( $out, array( 'email', 'full_name', 'phone', 'newsletter', 'newsletter_at', 'consent_version', 'consent_at', 'intent', 'budget', 'interests', 'timeline', 'industry', 'source', 'created_at' ) );
	foreach ( $rows as $r ) {
		if ( $only && empty( $r['newsletter'] ) ) {
			continue;
		}
		$p = json_decode( (string) $r['profile'], true );
		$p = is_array( $p ) ? $p : array();
		// A leading = + - @ would run as a formula in a spreadsheet.
		$safe = static function ( $v ) {
			$v = (string) $v;
			return '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ? "'" . $v : $v;
		};
		fputcsv( $out, array( $r['email'], $safe( $r['full_name'] ), $safe( $r['phone'] ), $r['newsletter'] ? 'yes' : 'no', $r['newsletter_at'], $r['consent_version'], $r['consent_at'],
			$safe( $p['intent'] ?? '' ), $safe( $p['budget'] ?? '' ), $safe( implode( '|', (array) ( $p['interests'] ?? array() ) ) ), $safe( $p['timeline'] ?? '' ), $safe( $p['industry'] ?? '' ), $safe( $p['source'] ?? '' ), $r['created_at'] ) );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
} );

if ( ! function_exists( 'db_acct_admin_page' ) ) {
	function db_acct_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		global $wpdb;
		$t = db_acct_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		$news  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE newsletter = 1" );
		$rows  = (array) $wpdb->get_results( "SELECT email, full_name, phone, newsletter, consent_version, created_at FROM {$t} ORDER BY created_at DESC LIMIT 200", ARRAY_A );
		// phpcs:enable
		echo '<div class="wrap"><h1>Accounts</h1><p>' . (int) $total . ' accounts, ' . (int) $news . ' opted in to the newsletter. Each row keeps the policy version agreed to at sign-up.</p><p>';
		echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=db_accounts_csv' ), 'db_accounts_csv' ) ) . '">Export all (CSV)</a> ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=db_accounts_csv&newsletter=1' ), 'db_accounts_csv' ) ) . '">Export newsletter opt-ins (CSV)</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>Email</th><th>Name</th><th>Phone</th><th>Newsletter</th><th>Agreed to</th><th>Created</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">No accounts yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r['email'] ) . '</td><td>' . esc_html( $r['full_name'] ) . '</td><td>' . esc_html( $r['phone'] ) . '</td><td>' . ( $r['newsletter'] ? 'Yes' : 'No' ) . '</td><td>' . esc_html( $r['consent_version'] ) . '</td><td>' . esc_html( $r['created_at'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}
