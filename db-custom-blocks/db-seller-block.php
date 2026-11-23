<?php
/**
 * "List your domains with us": seller marketplace, phase 1 (MARKETPLACE_PLAN.md).
 *
 * Owners sign in with the existing email-code account, complete a short seller setup, paste their
 * names, set prices, prove ownership with a DNS record, and submit. A broker reviews a new seller's
 * names (Tools > Seller listings); trusted sellers publish straight away. Approved names become
 * normal `domain` listings, and offers go through Domain Brothers brokers as they do today.
 *
 * Pages:  /sell-your-domains/ (public), /seller/ (signed-in dashboard), /seller-agreement/ (draft).
 * Switch: option `db_seller_on` ('1' or '0'); without it the feature is on for staging only.
 * Rules:  db-seller-lib.php (tested offline in tests/seller/seller-test.php).
 *
 * @package DB_Custom_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/db-seller-lib.php';
if ( is_readable( __DIR__ . '/db-seller-offers-block.php' ) ) {
	require_once __DIR__ . '/db-seller-offers-block.php';
}
if ( is_readable( __DIR__ . '/db-seller-payouts-block.php' ) ) {
	require_once __DIR__ . '/db-seller-payouts-block.php';
}
if ( is_readable( __DIR__ . '/db-seller-lander-block.php' ) ) {
	require_once __DIR__ . '/db-seller-lander-block.php';
}
if ( is_readable( __DIR__ . '/db-seller-app-block.php' ) ) {
	require_once __DIR__ . '/db-seller-app-block.php';
}
if ( is_readable( __DIR__ . '/db-seller-reliability-block.php' ) ) {
	require_once __DIR__ . '/db-seller-reliability-block.php';
}

define( 'DB_SELLER_DB_VERSION', '6' ); // 6: Stripe Connect account ids (connect_test, connect_live)

/* ------------------------------------------------------------------ setup */

if ( ! function_exists( 'db_seller_enabled' ) ) {
	function db_seller_enabled() {
		$opt = get_option( 'db_seller_on', null );
		$on  = null === $opt ? ( function_exists( 'db_is_staging_host' ) && db_is_staging_host() ) : '1' === (string) $opt;
		return (bool) apply_filters( 'db_seller_enabled', $on );
	}
}

if ( ! function_exists( 'db_seller_table' ) ) {
	function db_seller_table( $which ) {
		global $wpdb;
		return $wpdb->prefix . ( 'sellers' === $which ? 'db_sellers' : 'db_seller_listings' );
	}
}

add_action( 'init', function () {
	if ( DB_SELLER_DB_VERSION === get_option( 'db_seller_db_ver' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$cs = $wpdb->get_charset_collate();
	$s  = db_seller_table( 'sellers' );
	$l  = db_seller_table( 'listings' );
	dbDelta( "CREATE TABLE {$s} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email varchar(190) NOT NULL,
		legal_name varchar(120) NOT NULL DEFAULT '',
		company varchar(120) NOT NULL DEFAULT '',
		country char(2) NOT NULL DEFAULT '',
		phone varchar(30) NOT NULL DEFAULT '',
		token char(24) NOT NULL,
		agreement_version varchar(40) NOT NULL DEFAULT '',
		agreed_at datetime DEFAULT NULL,
		trust varchar(10) NOT NULL DEFAULT 'new',
		status varchar(20) NOT NULL DEFAULT 'active',
		strikes int(10) unsigned NOT NULL DEFAULT 0,
		status_note varchar(255) NOT NULL DEFAULT '',
		suspended_at datetime DEFAULT NULL,
		reactivate_note text NULL,
		reactivate_at datetime DEFAULT NULL,
		connect_test varchar(64) NOT NULL DEFAULT '',
		connect_live varchar(64) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email (email)
	) {$cs};" );
	dbDelta( "CREATE TABLE {$l} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		seller_id bigint(20) unsigned NOT NULL,
		domain varchar(253) NOT NULL,
		option_type varchar(12) NOT NULL DEFAULT 'buy_now',
		price int(10) unsigned NOT NULL DEFAULT 0,
		min_offer int(10) unsigned NOT NULL DEFAULT 0,
		verify_status varchar(10) NOT NULL DEFAULT 'pending',
		verify_method varchar(6) NOT NULL DEFAULT '',
		verified_at datetime DEFAULT NULL,
		last_check datetime DEFAULT NULL,
		checks int(10) unsigned NOT NULL DEFAULT 0,
		review_status varchar(10) NOT NULL DEFAULT 'draft',
		review_note varchar(255) NOT NULL DEFAULT '',
		exclusive tinyint(1) NOT NULL DEFAULT 0,
		description text NULL,
		keywords varchar(255) NOT NULL DEFAULT '',
		categories varchar(64) NOT NULL DEFAULT '',
		recheck_fail_at datetime DEFAULT NULL,
		offline_reason varchar(20) NOT NULL DEFAULT '',
		post_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY seller_id (seller_id),
		KEY domain (domain(64)),
		KEY review_status (review_status)
	) {$cs};" );
	update_option( 'db_seller_db_ver', DB_SELLER_DB_VERSION, false );
}, 5 );

/* --------------------------------------------------------------- storage */

if ( ! function_exists( 'db_seller_get' ) ) {
	/** The seller profile for an email, or null. */
	function db_seller_get( $email ) {
		global $wpdb;
		if ( '' === (string) $email ) {
			return null;
		}
		$t = db_seller_table( 'sellers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE email = %s", $email ), ARRAY_A );
		return $row ? $row : null;
	}
}

if ( ! function_exists( 'db_seller_listings' ) ) {
	/** A seller's listings, newest first, without removed ones. */
	function db_seller_listings( $seller_id ) {
		global $wpdb;
		$t = db_seller_table( 'listings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE seller_id = %d AND review_status <> 'removed' ORDER BY id DESC LIMIT 500", (int) $seller_id ), ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['option'] = $r['option_type'];
		}
		return $rows;
	}
}

if ( ! function_exists( 'db_seller_domain_taken' ) ) {
	/**
	 * '' when a name can be added, 'claim' when another account lists it (the
	 * name may be added, and is taken over only once this seller proves
	 * ownership), otherwise the reason it is refused.
	 */
	function db_seller_domain_taken( $name, $seller_id = 0 ) {
		global $wpdb;
		$t    = db_seller_table( 'listings' );
		$name = strtolower( (string) $name );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT seller_id FROM {$t} WHERE domain = %s AND review_status IN ('draft','pending','approved')", $name ), ARRAY_A );
		$mine = false;
		$other = false;
		foreach ( $rows as $r ) {
			if ( (int) $r['seller_id'] === (int) $seller_id ) {
				$mine = true;
			} else {
				$other = true;
			}
		}
		// The site's own inventory: a domain post that is not a seller listing.
		$inv = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = 'db_seller_id' WHERE p.post_type = 'domain' AND p.post_status IN ('publish','pending','private') AND LOWER(p.post_title) = %s AND ( m.meta_value IS NULL OR m.meta_value = '' OR m.meta_value = '0' )", $name ) );
		$sale = 0;
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}db_seller_payouts'" ) ) {
			$sale = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}db_seller_payouts WHERE domain = %s AND state <> 'paid'", $name ) );
		}
		// phpcs:enable
		if ( ! $sale && function_exists( 'db_stripe_domain_unavailable' ) && db_stripe_domain_unavailable( $name ) ) {
			$sale = 1; // sold or on a buyer's payment plan
		}
		return db_seller_taken_reason( $mine, $other, $inv > 0, $sale > 0 );
	}
}

if ( ! function_exists( 'db_seller_resolve_claims' ) ) {
	/**
	 * A seller just proved ownership of a name: every other account's row for
	 * it is taken off (its public page comes down) and that account is told.
	 */
	function db_seller_resolve_claims( array $winner ) {
		global $wpdb;
		$t = db_seller_table( 'listings' );
		$s = db_seller_table( 'sellers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT l.*, s.email FROM {$t} l JOIN {$s} s ON s.id = l.seller_id WHERE l.domain = %s", $winner['domain'] ), ARRAY_A );
		$lose  = db_seller_claim_losers( $rows, (int) $winner['seller_id'] );
		foreach ( $rows as $r ) {
			if ( ! in_array( (int) $r['id'], $lose, true ) ) {
				continue;
			}
			$was_live = 'approved' === $r['review_status'];
			db_seller_remove( $r );
			$wpdb->update( $t, array( 'review_note' => 'Ownership proved by another account on ' . gmdate( 'Y-m-d' ) ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( is_email( $r['email'] ) ) {
				db_seller_mail( $r['email'], 'You no longer list ' . $r['domain'] . ' on Domain Brothers', '<p>Another Domain Brothers account has just proved ownership of <strong>' . esc_html( $r['domain'] ) . '</strong> by adding its own DNS record to the name. Because a name can only be listed by its current owner, ' . ( $was_live ? 'your listing has been taken off the site' : 'it has been removed from your seller list' ) . '.</p><p>If you still own this name, this is a mistake: reply to this email or <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">contact us</a> and our broker will look into it.</p>' );
			}
			if ( function_exists( 'db_seller_audit' ) ) {
				db_seller_audit( (int) $r['seller_id'], $r['domain'], 'lost_claim', 'Another account (seller ' . (int) $winner['seller_id'] . ') proved ownership', 'system' );
			}
			if ( function_exists( 'db_plan_notify_admin' ) ) {
				db_plan_notify_admin( 'Ownership changed hands: ' . $r['domain'], array( 'Domain' => $r['domain'], 'Previous seller' => $r['email'], 'New seller id' => (int) $winner['seller_id'], 'Was live' => $was_live ? 'yes (taken down)' : 'no' ) );
			}
		}
	}
}

if ( ! function_exists( 'db_seller_flash' ) ) {
	/** Stores (with $msgs) or reads and clears (without) the messages shown after a form post. */
	function db_seller_flash( $email, $msgs = null ) {
		$k = 'db_seller_flash_' . md5( (string) $email );
		if ( null !== $msgs ) {
			set_transient( $k, $msgs, 5 * MINUTE_IN_SECONDS );
			return $msgs;
		}
		$v = get_transient( $k );
		delete_transient( $k );
		return is_array( $v ) ? $v : array();
	}
}

if ( ! function_exists( 'db_seller_csrf' ) ) {
	function db_seller_csrf( $email ) {
		return substr( hash_hmac( 'sha256', $email . '|seller', wp_salt( 'nonce' ) ), 0, 32 );
	}
}

/* ---------------------------------------------------------- verification */

if ( ! function_exists( 'db_seller_dns_proof' ) ) {
	/**
	 * Which of the seller's records the name carries right now: 'txt', 'cname' or ''.
	 * The filter lets the test suite stand in for DNS; nothing on the site uses it.
	 */
	function db_seller_dns_proof( $name, $token ) {
		$pre = apply_filters( 'db_seller_dns_proof', null, $name, $token );
		if ( null !== $pre ) {
			return (string) $pre;
		}
		$txt = function_exists( 'dns_get_record' ) ? @dns_get_record( $name, DNS_TXT ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_array( $txt ) && db_seller_txt_matches( $txt, $token ) ) {
			return 'txt';
		}
		$cn = function_exists( 'dns_get_record' ) ? @dns_get_record( db_seller_cname_host( $token, $name ), DNS_CNAME ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return ( is_array( $cn ) && db_seller_cname_matches( $cn ) ) ? 'cname' : '';
	}
}

if ( ! function_exists( 'db_seller_check_ownership' ) ) {
	/**
	 * Looks up the TXT and CNAME records for one listing and stores the result.
	 *
	 * @return string verified | pending | failed
	 */
	function db_seller_check_ownership( array $listing, $token ) {
		global $wpdb;
		$name   = $listing['domain'];
		$method = db_seller_dns_proof( $name, $token );
		$now   = current_time( 'mysql', true );
		$age_h = ( time() - strtotime( $listing['created_at'] . ' UTC' ) ) / HOUR_IN_SECONDS;
		$state = '' !== $method ? 'verified' : ( $age_h > DB_SELLER_VERIFY_HOURS ? 'failed' : 'pending' );
		$data  = array( 'verify_status' => $state, 'last_check' => $now, 'checks' => (int) $listing['checks'] + 1, 'updated_at' => $now );
		if ( 'verified' === $state ) {
			$data['verify_method'] = $method;
			$data['verified_at']   = $now;
		}
		$wpdb->update( db_seller_table( 'listings' ), $data, array( 'id' => (int) $listing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 'verified' === $state ) {
			db_seller_resolve_claims( $listing ); // the name now belongs to this account only
		}
		return $state;
	}
}

add_action( 'init', function () {
	if ( db_seller_enabled() && ! wp_next_scheduled( 'db_seller_verify_cron' ) ) {
		wp_schedule_event( time() + 300, 'hourly', 'db_seller_verify_cron' );
	}
	// Payouts, transfer deadlines and offer reminders on names already live keep running when
	// "Sell with us" is switched off, as the admin banner says (SL-13): their own hourly hook,
	// scheduled whenever the seller tables exist.
	if ( '' !== (string) get_option( 'db_seller_db_ver', '' ) && ! wp_next_scheduled( 'db_seller_ops_cron' ) ) {
		wp_schedule_event( time() + 600, 'hourly', 'db_seller_ops_cron' );
	}
} );

add_action( 'db_seller_verify_cron', function () {
	global $wpdb;
	if ( ! db_seller_enabled() ) {
		return;
	}
	$l = db_seller_table( 'listings' );
	$s = db_seller_table( 'sellers' );
	$since = gmdate( 'Y-m-d H:i:s', time() - ( DB_SELLER_VERIFY_HOURS + 2 ) * HOUR_IN_SECONDS );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT l.*, s.token FROM {$l} l JOIN {$s} s ON s.id = l.seller_id WHERE l.review_status = 'draft' AND l.verify_status = 'pending' AND l.created_at > %s AND s.status = 'active' ORDER BY l.last_check IS NULL DESC, l.last_check ASC LIMIT 100", $since ), ARRAY_A );
	foreach ( $rows as $r ) {
		db_seller_check_ownership( $r, $r['token'] );
	}
} );

/* -------------------------------------------- re-check every 120 days */

add_action( 'init', function () {
	if ( db_seller_enabled() && ! wp_next_scheduled( 'db_seller_recheck_cron' ) ) {
		wp_schedule_event( time() + 900, 'daily', 'db_seller_recheck_cron' );
	}
} );

add_action( 'db_seller_recheck_cron', 'db_seller_recheck_run' );
if ( ! function_exists( 'db_seller_recheck_run' ) ) {
	/**
	 * Daily: live names whose proof is 120 days old (or already failing) are
	 * checked again. Passing names are renewed; failing ones get 14 days and
	 * a warning, then come down. Each seller gets one summary email per run.
	 *
	 * @return array counts by action, for the admin screen and tests.
	 */
	function db_seller_recheck_run() {
		global $wpdb;
		if ( ! db_seller_enabled() ) {
			return 0; // no reminders or take-downs while "Sell with us" is off
		}
		$l    = db_seller_table( 'listings' );
		$s    = db_seller_table( 'sellers' );
		$now  = time();
		$cut  = gmdate( 'Y-m-d H:i:s', $now - DB_SELLER_RECHECK_DAYS * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT l.*, s.token, s.email FROM {$l} l JOIN {$s} s ON s.id = l.seller_id WHERE l.review_status = 'approved' AND ( l.recheck_fail_at IS NOT NULL OR l.verified_at IS NULL OR l.verified_at <= %s ) ORDER BY l.id LIMIT 300", $cut ), ARRAY_A );
		$count = array( 'renew' => 0, 'warn' => 0, 'still_failing' => 0, 'take_down' => 0 );
		$mail  = array();
		foreach ( $rows as $r ) {
			$vts    = $r['verified_at'] ? (int) strtotime( $r['verified_at'] . ' UTC' ) : 0;
			$fts    = $r['recheck_fail_at'] ? (int) strtotime( $r['recheck_fail_at'] . ' UTC' ) : 0;
			$method = db_seller_dns_proof( $r['domain'], $r['token'] );
			$act    = db_seller_recheck_action( $vts, $fts, '' !== $method, $now );
			if ( '' === $act ) {
				continue;
			}
			++$count[ $act ];
			$nowsql = gmdate( 'Y-m-d H:i:s', $now );
			if ( 'renew' === $act ) {
				$wpdb->update( $l, array( 'verified_at' => $nowsql, 'verify_method' => $method, 'recheck_fail_at' => null, 'last_check' => $nowsql ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} elseif ( 'warn' === $act ) {
				$wpdb->update( $l, array( 'recheck_fail_at' => $nowsql, 'last_check' => $nowsql ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} elseif ( 'still_failing' === $act ) {
				$wpdb->update( $l, array( 'last_check' => $nowsql ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			} else {
				db_seller_remove( $r );
				$wpdb->update( $l, array( 'review_note' => 'Taken off: ownership record missing for ' . DB_SELLER_RECHECK_GRACE_DAYS . ' days after the 120-day check' ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( function_exists( 'db_seller_audit' ) ) {
				db_seller_audit( (int) $r['seller_id'], $r['domain'], 'recheck_' . $act, 'Ownership re-check: ' . $act, 'system' );
			}
			$mail[ $r['email'] ][ $act ][] = $r['domain'];
		}
		foreach ( $mail as $to => $acts ) {
			if ( ! is_email( $to ) ) {
				continue;
			}
			$txt = db_seller_txt_value( '' );
			$h   = '<p>Every ' . DB_SELLER_RECHECK_DAYS . ' days we check that each name you list still carries your ownership record, so only current owners can sell a name.</p>';
			if ( ! empty( $acts['renew'] ) ) {
				$h .= '<p><strong>Still yours, nothing to do:</strong> ' . esc_html( implode( ', ', $acts['renew'] ) ) . '.</p>';
			}
			$fail = array_merge( $acts['warn'] ?? array(), $acts['still_failing'] ?? array() );
			if ( $fail ) {
				$h .= '<p><strong>Action needed within ' . DB_SELLER_RECHECK_GRACE_DAYS . ' days:</strong> ' . esc_html( implode( ', ', $fail ) ) . '. The ownership record (TXT or CNAME) is missing. Add it back at your registrar; the values are on your <a href="' . esc_url( home_url( '/seller/' ) ) . '">seller page</a>. Names still missing the record after ' . DB_SELLER_RECHECK_GRACE_DAYS . ' days are taken off the site.</p>';
			}
			if ( ! empty( $acts['take_down'] ) ) {
				$h .= '<p><strong>Taken off the site:</strong> ' . esc_html( implode( ', ', $acts['take_down'] ) ) . '. The ownership record was missing for ' . DB_SELLER_RECHECK_GRACE_DAYS . ' days. Add the record and list the name again whenever you like.</p>';
			}
			unset( $txt );
			$subj = $fail ? 'Action needed: check your domain ownership records' : ( ! empty( $acts['take_down'] ) ? 'Names taken off Domain Brothers' : 'Your names passed the 120-day ownership check' );
			db_seller_mail( $to, $subj, $h );
		}
		update_option( 'db_seller_recheck_last', array( 'ts' => $now, 'count' => $count ), false );
		return $count;
	}
}

/* --------------------------------------------------------------- publish */

if ( ! function_exists( 'db_seller_publish' ) ) {
	/**
	 * Creates the public `domain` listing for an approved seller listing.
	 *
	 * @return int|string Post id, or the reason it could not be created.
	 */
	function db_seller_publish( array $listing ) {
		global $wpdb;
		$taken = db_seller_domain_taken_live( $listing['domain'] );
		$reuse = 0;
		if ( $taken ) {
			// A page left by a seller who lost the name is reused (same URL, same history).
			$prev = (int) get_post_meta( $taken, 'db_seller_id', true );
			$prow = (int) get_post_meta( $taken, 'db_seller_listing', true );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pst  = $prow ? (string) $wpdb->get_var( $wpdb->prepare( 'SELECT review_status FROM ' . db_seller_table( 'listings' ) . ' WHERE id = %d', $prow ) ) : '';
			// The same seller relisting a name they removed, marked sold elsewhere or had taken down
			// reuses the page too (SL-4): only a page whose listing is still live blocks a new one.
			if ( ( $prev && $prev !== (int) $listing['seller_id'] && 'removed' === $pst ) || ( $prev === (int) $listing['seller_id'] && in_array( $pst, array( 'removed', 'rejected' ), true ) && 'publish' !== get_post_status( $taken ) ) ) {
				$reuse = (int) $taken;
			} else {
				return 'A listing for this name already exists (post ' . $taken . ').';
			}
		}
		$listing['option'] = $listing['option_type'];
		if ( $reuse ) {
			$post_id = wp_update_post( array( 'ID' => $reuse, 'post_status' => 'publish' ), true );
		} else {
			$post_id = wp_insert_post( array(
				'post_type'   => 'domain',
				'post_status' => 'publish',
				'post_title'  => $listing['domain'],
				'post_name'   => sanitize_title( str_replace( '.', '-', $listing['domain'] ) ),
				'post_author' => 1,
			), true );
		}
		if ( is_wp_error( $post_id ) ) {
			return $post_id->get_error_message();
		}
		foreach ( db_seller_listing_meta( $listing, (int) $listing['seller_id'], current_time( 'Y-m-d' ) ) as $k => $v ) {
			update_post_meta( $post_id, $k, $v );
		}
		update_post_meta( $post_id, 'db_exclusive', ! empty( $listing['exclusive'] ) ? '1' : '' );
		update_post_meta( $post_id, 'db_seller_desc', (string) ( $listing['description'] ?? '' ) );
		update_post_meta( $post_id, 'db_seller_keywords', (string) ( $listing['keywords'] ?? '' ) );
		db_seller_apply_categories( (int) $post_id, (string) ( $listing['categories'] ?? '' ) );
		$now = current_time( 'mysql', true );
		$wpdb->update( db_seller_table( 'listings' ), array( 'review_status' => 'approved', 'post_id' => (int) $post_id, 'review_note' => '', 'updated_at' => $now ), array( 'id' => (int) $listing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		do_action( 'db_page_cache_purge_all' );
		return (int) $post_id;
	}
}

if ( ! function_exists( 'db_seller_domain_taken_live' ) ) {
	/** The id of an existing domain post with this title, or 0. */
	function db_seller_domain_taken_live( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'domain' AND post_status IN ('publish','pending','draft','private') AND LOWER(post_title) = %s LIMIT 1", strtolower( $name ) ) );
	}
}


if ( ! function_exists( 'db_seller_apply_row' ) ) {
	/** Saves a validated price row; for a live listing the public post changes at once. */
	function db_seller_apply_row( array $listing, array $clean ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$data = array( 'option_type' => $clean['option'], 'price' => $clean['price'], 'min_offer' => $clean['min_offer'], 'review_status' => 'approved' === $listing['review_status'] ? 'approved' : 'draft', 'updated_at' => $now );
		if ( isset( $clean['exclusive'] ) ) {
			$data['exclusive'] = $clean['exclusive'] ? 1 : 0;
		}
		$wpdb->update( db_seller_table( 'listings' ), $data, array( 'id' => (int) $listing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 'approved' === $listing['review_status'] && (int) $listing['post_id'] ) {
			$meta = db_seller_listing_meta( array_merge( $listing, $clean ), (int) $listing['seller_id'], current_time( 'Y-m-d' ) );
			foreach ( array( 'domain_price', 'domain_type', '_db_min_offer' ) as $k ) {
				update_post_meta( (int) $listing['post_id'], $k, $meta[ $k ] );
			}
			if ( isset( $clean['exclusive'] ) ) {
				update_post_meta( (int) $listing['post_id'], 'db_exclusive', $clean['exclusive'] ? '1' : '' );
			}
			do_action( 'db_page_cache_purge_all' );
		}
	}
}

if ( ! function_exists( 'db_seller_remove' ) ) {
	/** Removes a listing; a live one is taken off the site. */
	function db_seller_remove( array $listing ) {
		global $wpdb;
		if ( (int) $listing['post_id'] ) {
			wp_update_post( array( 'ID' => (int) $listing['post_id'], 'post_status' => 'draft' ) );
			do_action( 'db_page_cache_purge_all' );
		}
		$wpdb->update( db_seller_table( 'listings' ), array( 'review_status' => 'removed', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $listing['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		db_seller_close_deals_on_removal( (string) ( $listing['domain'] ?? '' ) );
	}
}

if ( ! function_exists( 'db_seller_close_deals_on_removal' ) ) {
	/**
	 * A name taken down (removed, sold elsewhere, lost claim, expiry, failed re-check) leaves no
	 * offer hanging: open and countered offers are declined, and accepted ones, which the buyer can
	 * still pay, go to the broker team (bug register batch 2, SL-6).
	 */
	function db_seller_close_deals_on_removal( $domain ) {
		global $wpdb;
		$domain = strtolower( trim( (string) $domain ) );
		if ( '' === $domain || ! function_exists( 'db_deal_table' ) || ! function_exists( 'db_deal_apply' ) ) {
			return;
		}
		$t     = db_deal_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deals = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE domain = %s AND state IN ('open','countered','accepted')", $domain ) );
		$held  = array();
		foreach ( $deals as $d ) {
			if ( 'accepted' === $d->state ) {
				$held[] = $d;
				continue;
			}
			$GLOBALS['db_seller_auto'] = true;
			db_deal_apply( $d, 'seller', 'decline', null, 'This name is no longer listed for sale.' );
			unset( $GLOBALS['db_seller_auto'] );
		}
		if ( $held && function_exists( 'db_mail_team_to' ) && function_exists( 'db_seller_mail' ) ) {
			$list = '';
			foreach ( $held as $d ) {
				$list .= '<li>' . esc_html( $domain ) . ': accepted at ' . esc_html( function_exists( 'db_deal_money' ) ? db_deal_money( $d->amount ) : (string) $d->amount ) . ' (deal ' . (int) $d->id . ')</li>';
			}
			db_seller_mail( db_mail_team_to(), 'Listing taken down with an accepted offer: ' . $domain, '<p>The seller listing for ' . esc_html( $domain ) . ' was taken down while a buyer holds an accepted offer. The buyer can still pay, and no seller payout record would be created. Contact the buyer and the seller before any payment.</p><ul>' . $list . '</ul>' );
		}
	}
}

if ( ! function_exists( 'db_seller_stats' ) ) {
	/** Wishlist saves and offers for a live listing. */
	function db_seller_stats( array $listing ) {
		global $wpdb;
		$pid    = (int) $listing['post_id'];
		$saves  = $pid && function_exists( 'db_wl_count' ) ? (int) db_wl_count( $pid ) : 0;
		$hits   = $pid && function_exists( 'db_hits_for_domain' ) ? db_hits_for_domain( $pid ) : null;
		$hits   = is_object( $hits ) ? (array) $hits : ( is_array( $hits ) ? $hits : null ); // the hits query returns rows as objects
		$views  = $hits ? (int) ( $hits['all_h'] ?? 0 ) : 0;
		$offers = $hits ? (int) ( $hits['offers'] ?? 0 ) : 0;
		if ( ! $hits && function_exists( 'db_deal_table' ) ) {
			$t = db_deal_table( 'deals' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$offers = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE domain = %s", $listing['domain'] ) );
		}
		return array( $saves, $offers, $views );
	}
}

if ( ! function_exists( 'db_seller_sold_prices' ) ) {
	/** Domain Brothers' sold names with a recorded price, cached for an hour. */
	function db_seller_sold_prices() {
		$c = get_transient( 'db_seller_sold_prices' );
		if ( is_array( $c ) ) {
			return $c;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// The recorded sale price when there is one, otherwise the price the name was listed at when it sold.
		$rows = (array) $wpdb->get_results(
			"SELECT p.post_title AS name, COALESCE( NULLIF( sp.meta_value + 0, 0 ), lp.meta_value + 0 ) AS price
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} sp ON sp.post_id = p.ID AND sp.meta_key = 'db_sold_price'
			LEFT JOIN {$wpdb->postmeta} lp ON lp.post_id = p.ID AND lp.meta_key = 'domain_price'
			LEFT JOIN {$wpdb->postmeta} st ON st.post_id = p.ID AND st.meta_key = 'domain_status'
			LEFT JOIN {$wpdb->postmeta} ls ON ls.post_id = p.ID AND ls.meta_key = 'db_listing_state'
			WHERE p.post_type = 'domain' AND p.post_status = 'publish'
			  AND ( sp.meta_value + 0 > 0 OR LOWER( st.meta_value ) = 'sold' OR ls.meta_value = 'sold' )
			HAVING price > 0 LIMIT 2000",
			ARRAY_A
		);
		set_transient( 'db_seller_sold_prices', $rows, HOUR_IN_SECONDS );
		return $rows;
	}
}

if ( ! function_exists( 'db_seller_read_upload' ) ) {
	/** The uploaded spreadsheet as a table, or an error string. */
	function db_seller_read_upload( $field ) {
		if ( empty( $_FILES[ $field ]['tmp_name'] ) || ! is_uploaded_file( $_FILES[ $field ]['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
			return 'Choose a CSV or XLSX file.';
		}
		$f    = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
		$ext  = strtolower( pathinfo( (string) $f['name'], PATHINFO_EXTENSION ) );
		if ( (int) $f['size'] > 5 * 1024 * 1024 ) {
			return 'The file is larger than 5 MB. Split it into smaller files.';
		}
		if ( 'xlsx' === $ext ) {
			$t = db_seller_xlsx_table( $f['tmp_name'] );
			return is_array( $t ) ? $t : 'That XLSX file could not be read. Save it as CSV and try again.';
		}
		if ( in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			return db_seller_csv_table( (string) file_get_contents( $f['tmp_name'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return 'Use a .csv or .xlsx file.';
	}
}

/* ---------------------------------------------------------------- emails */

if ( ! function_exists( 'db_seller_mail' ) ) {
	function db_seller_mail( $to, $subject, $html ) {
		if ( function_exists( 'db_send_branded_email' ) ) {
			return db_send_branded_email( $to, $subject, $html );
		}
		return wp_mail( $to, $subject, wp_strip_all_tags( $html ) );
	}
}

if ( ! function_exists( 'db_seller_names_html' ) ) {
	function db_seller_names_html( array $names ) {
		return '<ul>' . implode( '', array_map( function ( $n ) { return '<li>' . esc_html( $n ) . '</li>'; }, $names ) ) . '</ul>';
	}
}

/* ------------------------------------------------------------ form posts */

if ( ! function_exists( 'db_seller_handle_post' ) ) {
	/** Handles one dashboard form post and returns the messages to show. */
	function db_seller_handle_post( $email, $seller ) {
		global $wpdb;
		$p   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked below with a session token and the request origin.
		$act = isset( $p['db_seller_action'] ) ? (string) $p['db_seller_action'] : '';
		if ( ! hash_equals( db_seller_csrf( $email ), (string) ( $p['t'] ?? '' ) ) || ! db_wl_same_origin() ) {
			return array( array( 'bad', 'Your session changed. Please try again.' ) );
		}
		$l   = db_seller_table( 'listings' );
		$now = current_time( 'mysql', true );

		if ( 'setup' === $act ) {
			list( $c, $err ) = db_seller_clean_profile( $p );
			if ( $err ) {
				return array_map( function ( $e ) { return array( 'bad', $e ); }, $err );
			}
			$data = array( 'legal_name' => $c['legal_name'], 'company' => $c['company'], 'country' => $c['country'], 'phone' => $c['phone'], 'agreement_version' => DB_SELLER_AGREEMENT_VERSION, 'agreed_at' => $now );
			if ( $seller ) {
				$wpdb->update( db_seller_table( 'sellers' ), $data, array( 'id' => (int) $seller['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return array( array( 'ok', 'Your seller details are saved.' ) );
			}
			$data += array( 'email' => $email, 'token' => bin2hex( random_bytes( 12 ) ), 'trust' => 'new', 'status' => 'active', 'created_at' => $now );
			$wpdb->insert( db_seller_table( 'sellers' ), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$dup = function_exists( 'db_seller_duplicate_of' ) ? db_seller_duplicate_of( $c['phone'], (int) $wpdb->insert_id ) : 0;
			if ( $dup ) {
				// Flag for the broker, do not block: phones are shared, and the broker decides.
				$wpdb->update( db_seller_table( 'sellers' ), array( 'trust' => 'flagged' ), array( 'id' => (int) $wpdb->insert_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( function_exists( 'db_plan_notify_admin' ) ) {
					db_plan_notify_admin( 'New seller matches an offline account', array( 'New seller' => $email, 'Matches seller id' => $dup, 'Review' => admin_url( 'tools.php?page=db-seller-review#dbsl-sellers' ) ) );
				}
			}
			return array( array( 'ok', 'You are set up as a seller. Add your first names below.' ) );
		}

		if ( ! $seller ) {
			return array( array( 'bad', 'Complete the seller setup first.' ) );
		}
		if ( function_exists( 'db_seller_reliability_post' ) ) {
			$rel = db_seller_reliability_post( $act, $seller, $p );
			if ( null !== $rel ) {
				return $rel;
			}
		}
		if ( 'active' !== $seller['status'] ) {
			return array( array( 'bad', 'paused' === $seller['status'] ? 'Your listings are paused. Resume them to make changes.' : 'Your seller account is offline. Send a reactivation request from this page.' ) );
		}
		$sid = (int) $seller['id'];

		if ( 'add' === $act ) {
			if ( ! db_wl_allow( 'seller-add:' . $sid, 10, HOUR_IN_SECONDS ) ) {
				return array( array( 'bad', 'That is a lot of names in one hour. Please wait a little and try again.' ) );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$open = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$l} WHERE seller_id = %d AND review_status IN ('draft','pending')", $sid ) );
			$room = max( 0, 200 - $open );
			$res  = db_seller_parse_names( (string) ( $p['names'] ?? '' ), min( DB_SELLER_MAX_NAMES, $room ) );
			$msgs = array();
			$added = array();
			$rdap_until = microtime( true ) + 15; // registry lookups stop after 15 seconds; later names rely on the DNS check
			foreach ( $res['ok'] as $name ) {
				$why   = db_seller_tld_exists( $name ) ? db_seller_blocked( $name ) : 'There is no such domain ending. Check the spelling.';
				$taken = '' !== $why ? '' : db_seller_domain_taken( $name, $sid );
				$claim = 'claim' === $taken;
				$why   = '' !== $why ? $why : ( $claim ? '' : $taken );
				if ( '' === $why && 'unregistered' === db_seller_rdap_check( $name, $rdap_until ) ) {
					$why = 'This name is not registered, so it cannot be listed. Check the spelling.';
				}
				if ( '' !== $why ) {
					$msgs[] = array( 'warn', $name . ': ' . $why );
					continue;
				}
				// Only a name that is otherwise addable counts toward the daily claim limit.
				if ( $claim && function_exists( 'db_seller_rule' ) && ! db_wl_allow( 'seller-claim:' . $sid, (int) db_seller_rule( 'claims_per_day' ), DAY_IN_SECONDS ) ) {
					$msgs[] = array( 'warn', $name . ': you can start at most ' . (int) db_seller_rule( 'claims_per_day' ) . ' ownership claims a day. Try again tomorrow.' );
					continue;
				}
				if ( $claim ) {
					$msgs[] = array( 'warn', $name . ': another account lists this name. It moves to you only once you prove ownership with your DNS record; the other account is then told.' );
				}
				$wpdb->insert( $l, array( 'seller_id' => $sid, 'domain' => $name, 'created_at' => $now, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$added[] = $name;
			}
			foreach ( $res['bad'] as $b ) {
				$msgs[] = array( 'warn', $b[0] . ': ' . $b[1] );
			}
			if ( $res['over'] ) {
				$msgs[] = array( 'warn', $res['over'] . ' more names were left out. Add them in another batch.' );
			}
			array_unshift( $msgs, $added ? array( 'ok', count( $added ) . ' ' . ( 1 === count( $added ) ? 'name' : 'names' ) . ' added. Set prices and add the DNS record below.' ) : array( 'bad', 'No names were added.' ) );
			return $msgs;
		}

		$own = array();
		foreach ( db_seller_listings( $sid ) as $r ) {
			$own[ (int) $r['id'] ] = $r;
		}

		if ( 'price' === $act ) {
			$rows = isset( $p['row'] ) && is_array( $p['row'] ) ? $p['row'] : array();
			$msgs = array();
			$saved = 0;
			foreach ( $rows as $id => $r ) {
				$id = (int) $id;
				if ( ! isset( $own[ $id ] ) || ! in_array( $own[ $id ]['review_status'], array( 'draft', 'rejected', 'approved' ), true ) || ! is_array( $r ) ) {
					continue;
				}
				list( $c, $err ) = db_seller_validate_row( $r );
				if ( $err ) {
					$msgs[] = array( 'bad', $own[ $id ]['domain'] . ': ' . implode( ' ', $err ) );
					continue;
				}
				$c['exclusive'] = ! empty( $r['exclusive'] );
				if ( $c['option'] === $own[ $id ]['option_type'] && $c['price'] === (int) $own[ $id ]['price'] && $c['min_offer'] === (int) $own[ $id ]['min_offer'] && (int) $c['exclusive'] === (int) $own[ $id ]['exclusive'] && 'rejected' !== $own[ $id ]['review_status'] ) {
					continue;
				}
				if ( db_seller_sale_in_progress( $own[ $id ]['domain'] ) ) {
					$msgs[] = array( 'warn', $own[ $id ]['domain'] . ': a buyer has an accepted offer or a payment plan, so the price and the exclusive option are fixed until the sale ends.' );
					continue;
				}
				db_seller_apply_row( $own[ $id ], $c );
				++$saved;
			}
			array_unshift( $msgs, array( $saved ? 'ok' : 'warn', $saved ? 'Prices saved for ' . $saved . ' ' . ( 1 === $saved ? 'name' : 'names' ) . '.' : 'Nothing to save.' ) );
			return $msgs;
		}

		if ( 'check' === $act ) {
			if ( ! db_wl_allow( 'seller-check:' . $sid, 30, HOUR_IN_SECONDS ) ) {
				return array( array( 'bad', 'Too many checks this hour. We also check automatically every hour.' ) );
			}
			$ok = 0; $wait = 0;
			foreach ( $own as $r ) {
				if ( 'draft' !== $r['review_status'] || 'verified' === $r['verify_status'] ) {
					continue;
				}
				'verified' === db_seller_check_ownership( $r, $seller['token'] ) ? ++$ok : ++$wait;
			}
			if ( ! $ok && ! $wait ) {
				return array( array( 'ok', 'All your names are already verified.' ) );
			}
			return array( $ok ? array( 'ok', $ok . ' verified.' . ( $wait ? ' ' . $wait . ' not found yet: DNS changes can take up to an hour to appear.' : '' ) ) : array( 'warn', 'The record was not found yet. DNS changes can take up to an hour. We keep checking every hour for 3 days.' ) );
		}

		if ( 'submit' === $act ) {
			$ready = array();
			foreach ( $own as $r ) {
				if ( 'draft' === $r['review_status'] && db_seller_ready( $r ) ) {
					$ready[] = $r;
				}
			}
			if ( ! $ready ) {
				return array( array( 'warn', 'Nothing is ready yet. A name needs a valid price and a verified DNS record.' ) );
			}
			$names = wp_list_pluck( $ready, 'domain' );
			if ( 'trusted' === $seller['trust'] ) {
				// Failures are reported to the seller and kept on the row, and the email lists only the
				// names that went live (SL-5).
				$live   = 0;
				$done   = array();
				$failed = array();
				foreach ( $ready as $r ) {
					$res = db_seller_publish( $r );
					if ( is_int( $res ) ) {
						++$live;
						$done[] = $r['domain'];
					} else {
						$failed[] = array( 'bad', $r['domain'] . ': ' . (string) $res );
						$wpdb->update( $l, array( 'review_note' => mb_substr( (string) $res, 0, 250 ), 'updated_at' => $now ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					}
				}
				$names = $done;
				if ( ! $done ) {
					return $failed ? $failed : array( array( 'bad', 'No names could be published.' ) );
				}
				db_seller_mail( $email, 'Your names are live on Domain Brothers', '<p>These names are now listed for sale:</p>' . db_seller_names_html( $names ) . '<p>Our brokers handle every offer and will contact you when a buyer is ready.</p>' );
				return array_merge( array( array( 'ok', $live . ' ' . ( 1 === $live ? 'name is' : 'names are' ) . ' now live.' ) ), $failed );
			}
			foreach ( $ready as $r ) {
				$wpdb->update( $l, array( 'review_status' => 'pending', 'updated_at' => $now ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			db_seller_mail( $email, 'We received your names', '<p>Thanks. These names are in review:</p>' . db_seller_names_html( $names ) . '<p>A broker checks new sellers\' first listings, usually within one business day. We email you as soon as they are live.</p>' );
			db_seller_mail( db_mail_team_to(), 'Seller listings to review: ' . count( $names ), '<p>' . esc_html( $seller['legal_name'] ) . ' (' . esc_html( $email ) . ') submitted:</p>' . db_seller_names_html( $names ) . '<p><a href="' . esc_url( admin_url( 'tools.php?page=db-seller-review' ) ) . '">Open the review queue</a></p>' );
			return array( array( 'ok', count( $names ) . ' ' . ( 1 === count( $names ) ? 'name' : 'names' ) . ' sent for review. We email you when they are live.' ) );
		}

		$ikey = 'db_seller_import_' . $sid;

		if ( 'import_preview' === $act ) {
			if ( ! db_wl_allow( 'seller-import:' . $sid, 20, HOUR_IN_SECONDS ) ) {
				return array( array( 'bad', 'Too many uploads this hour. Please wait a little.' ) );
			}
			$table = db_seller_read_upload( 'sheet' );
			if ( is_string( $table ) ) {
				return array( array( 'bad', $table ) );
			}
			$sr = db_seller_sheet_rows( $table );
			if ( ! $sr['rows'] ) {
				return array_merge( array( array( 'bad', 'No rows could be imported.' ) ), array_map( function ( $e ) { return array( 'warn', $e ); }, array_slice( $sr['errors'], 0, 20 ) ) );
			}
			$existing = array();
			foreach ( $own as $r ) {
				$existing[ $r['domain'] ] = $r['review_status'];
			}
			$mode = isset( $p['mode'] ) && 'sync' === $p['mode'] ? 'sync' : 'import';
			$plan = db_seller_plan_import( $sr['rows'], $existing, $mode );
			$rdap_until = microtime( true ) + 15; // same budget as the paste box; names not looked up rely on the DNS check
			foreach ( $plan['add'] as $i => $a ) {
				$why = db_seller_tld_exists( $a['domain'] ) ? db_seller_blocked( $a['domain'] ) : 'There is no such domain ending.';
				$why = '' !== $why ? $why : db_seller_domain_taken( $a['domain'], $sid );
				$why = 'claim' === $why ? '' : $why; // claims are allowed; ownership proof decides
				if ( '' === $why && 'unregistered' === db_seller_rdap_check( $a['domain'], $rdap_until ) ) {
					$why = 'This name is not registered, so it cannot be listed.';
				}
				if ( '' !== $why ) {
					$plan['skip'][] = array( $a['domain'], $why );
					unset( $plan['add'][ $i ] );
				}
			}
			$plan['add']    = array_values( array_slice( $plan['add'], 0, max( 0, 1000 - count( $own ) ) ) );
			$plan['mode']   = $mode;
			$plan['errors'] = $sr['errors'];
			set_transient( $ikey, $plan, 30 * MINUTE_IN_SECONDS );
			return array( array( 'ok', 'File read. Check the summary below, then confirm.' ) );
		}

		if ( 'import_cancel' === $act ) {
			delete_transient( $ikey );
			return array( array( 'ok', 'Import cancelled. Nothing was changed.' ) );
		}

		if ( 'import_apply' === $act ) {
			$plan = get_transient( $ikey );
			delete_transient( $ikey );
			if ( ! is_array( $plan ) ) {
				return array( array( 'bad', 'That import expired. Upload the file again.' ) );
			}
			$by = array();
			foreach ( $own as $r ) {
				$by[ $r['domain'] ] = $r;
			}
			$n      = array( 0, 0, 0 );
			$capped = 0;
			foreach ( $plan['add'] as $a ) {
				$taken = db_seller_domain_taken( $a['domain'], $sid );
				if ( ! in_array( $taken, array( '', 'claim' ), true ) ) {
					continue;
				}
				// Claims on another account's names share the daily limit with the paste box.
				if ( 'claim' === $taken && function_exists( 'db_seller_rule' ) && ! db_wl_allow( 'seller-claim:' . $sid, (int) db_seller_rule( 'claims_per_day' ), DAY_IN_SECONDS ) ) {
					++$capped;
					continue;
				}
				$wpdb->insert( $l, array( 'seller_id' => $sid, 'domain' => $a['domain'], 'option_type' => $a['option'], 'price' => $a['price'], 'min_offer' => $a['min_offer'], 'created_at' => $now, 'updated_at' => $now ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$n[0];
			}
			foreach ( $plan['update'] as $u ) {
				if ( isset( $by[ $u['domain'] ] ) && 'pending' !== $by[ $u['domain'] ]['review_status'] ) {
					db_seller_apply_row( $by[ $u['domain'] ], $u );
					++$n[1];
				}
			}
			foreach ( $plan['remove'] as $d ) {
				if ( isset( $by[ $d ] ) ) {
					db_seller_remove( $by[ $d ] );
					++$n[2];
				}
			}
			$out = array( array( 'ok', sprintf( 'Import done: %d added, %d updated, %d removed. New names need the DNS record before you submit them.', $n[0], $n[1], $n[2] ) ) );
			if ( $capped ) {
				$out[] = array( 'warn', sprintf( '%d names listed by another account were left out: you can start at most %d ownership claims a day.', $capped, (int) db_seller_rule( 'claims_per_day' ) ) );
			}
			return $out;
		}

		if ( 'bulk' === $act ) {
			$ids  = array_map( 'intval', isset( $p['ids'] ) && is_array( $p['ids'] ) ? $p['ids'] : array() );
			$do   = (string) ( $p['bulk'] ?? '' );
			$pick = array_values( array_filter( array_map( function ( $i ) use ( $own ) { return $own[ $i ] ?? null; }, $ids ) ) );
			if ( ! $pick ) {
				return array( array( 'warn', 'Select at least one name.' ) );
			}
			$done = 0; $msgs = array();
			foreach ( $pick as $r ) {
				if ( 'remove' === $do ) {
					db_seller_remove( $r );
					++$done;
					continue;
				}
				if ( 'pending' === $r['review_status'] ) {
					$msgs[] = array( 'warn', $r['domain'] . ': in review; change it after the review.' );
					continue;
				}
				if ( 'make_offer' === $do ) {
					$c = array( 'option' => 'make_offer', 'price' => (int) $r['price'], 'min_offer' => (int) $r['min_offer'] );
				} elseif ( 'adjust' === $do ) {
					if ( ! (int) $r['price'] ) {
						$msgs[] = array( 'warn', $r['domain'] . ': has no price to adjust.' );
						continue;
					}
					$np = db_seller_adjust_price( (int) $r['price'], (float) ( $p['pct'] ?? 0 ) );
					// The private minimum moves with the price instead of being erased (SL-16).
					$nm = db_seller_scale_min( (int) $r['min_offer'], (int) $r['price'], $np );
					$c  = array( 'option' => $r['option_type'], 'price' => $np, 'min_offer' => $nm );
					if ( (int) $r['min_offer'] && $nm !== (int) $r['min_offer'] ) {
						$msgs[] = array( 'ok', $r['domain'] . ': minimum offer changed to $' . number_format( $nm ) . ' to match the new price.' );
					}
				} else {
					return array( array( 'warn', 'Choose an action.' ) );
				}
				list( $c, $err ) = db_seller_validate_row( $c );
				if ( $err ) {
					$msgs[] = array( 'bad', $r['domain'] . ': ' . implode( ' ', $err ) );
					continue;
				}
				if ( db_seller_sale_in_progress( $r['domain'] ) ) {
					$msgs[] = array( 'warn', $r['domain'] . ': a sale is in progress, so its price is fixed until it ends.' );
					continue;
				}
				db_seller_apply_row( $r, $c );
				++$done;
			}
			array_unshift( $msgs, array( $done ? 'ok' : 'warn', $done . ' ' . ( 1 === $done ? 'name' : 'names' ) . ' updated.' ) );
			return $msgs;
		}

		if ( 'details' === $act ) {
			$id = (int) ( $p['id'] ?? 0 );
			if ( ! isset( $own[ $id ] ) ) {
				return array( array( 'bad', 'That name is not in your list.' ) );
			}
			$d    = db_seller_clean_details( $p );
			$cats = db_seller_clean_categories( $p['cats'] ?? array(), array_keys( db_seller_category_options() ) );
			$wpdb->update( $l, array( 'description' => $d['description'], 'keywords' => $d['keywords'], 'categories' => $cats, 'updated_at' => $now ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 'approved' === $own[ $id ]['review_status'] && (int) $own[ $id ]['post_id'] ) {
				update_post_meta( (int) $own[ $id ]['post_id'], 'db_seller_desc', $d['description'] );
				update_post_meta( (int) $own[ $id ]['post_id'], 'db_seller_keywords', $d['keywords'] );
				db_seller_apply_categories( (int) $own[ $id ]['post_id'], $cats );
				do_action( 'db_page_cache_purge_all' );
			}
			return array( array( 'ok', 'Details saved for ' . $own[ $id ]['domain'] . '.' ) );
		}

		if ( 'pointed' === $act && function_exists( 'db_seller_point_post' ) ) {
			$own = array();
			foreach ( db_seller_listings( $sid ) as $r ) {
				$own[ (int) $r['id'] ] = $r;
			}
			return db_seller_point_post( $seller, $own, $p );
		}
		if ( 'offer' === $act && function_exists( 'db_seller_offer_post' ) ) {
			return db_seller_offer_post( $seller, $p );
		}

		if ( 'remove' === $act ) {
			$id = (int) ( $p['id'] ?? 0 );
			if ( ! isset( $own[ $id ] ) ) {
				return array( array( 'bad', 'That name is not in your list.' ) );
			}
			db_seller_remove( $own[ $id ] );
			return array( array( 'ok', $own[ $id ]['domain'] . ' was removed.' ) );
		}
		return array();
	}
}

if ( ! function_exists( 'db_seller_category_options' ) ) {
	/** The site's domain categories: id => name. */
	function db_seller_category_options() {
		$terms = get_terms( array( 'taxonomy' => 'domain_category', 'hide_empty' => false ) );
		$out   = array();
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[ (int) $t->term_id ] = (string) $t->name;
			}
		}
		asort( $out );
		return $out;
	}
}

if ( ! function_exists( 'db_seller_sale_in_progress' ) ) {
	/**
	 * True while a buyer holds an accepted offer or the name is sold or reserved on a payment plan.
	 * The price and the exclusive (10%) option are then fixed, because the payout reads the
	 * listing's terms when the money arrives (SL-15).
	 */
	function db_seller_sale_in_progress( $domain ) {
		global $wpdb;
		$domain = strtolower( trim( (string) $domain ) );
		if ( '' === $domain ) {
			return false;
		}
		if ( function_exists( 'db_stripe_domain_taken' ) && db_stripe_domain_taken( $domain ) ) {
			return true;
		}
		if ( ! function_exists( 'db_deal_table' ) ) {
			return false;
		}
		$t = db_deal_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE domain = %s AND state = 'accepted'", $domain ) ) > 0;
	}
}

if ( ! function_exists( 'db_seller_apply_categories' ) ) {
	/** Sets the listing's categories; an empty choice leaves the existing ones. */
	function db_seller_apply_categories( $post_id, $cats ) {
		$ids = array_values( array_filter( array_map( 'intval', explode( ',', (string) $cats ) ) ) );
		if ( $post_id ) {
			// Always set, clearing when none are chosen: a reused post kept the previous seller's
			// categories (SL-27).
			wp_set_object_terms( (int) $post_id, $ids, 'domain_category', false );
			delete_transient( 'db_search_hints' );
		}
	}
}

if ( ! function_exists( 'db_seller_category_fieldset' ) ) {
	function db_seller_category_fieldset( $current ) {
		$opts = db_seller_category_options();
		if ( ! $opts ) {
			return '';
		}
		$cur = array_map( 'intval', explode( ',', $current ) );
		$h   = '<fieldset class="dbsl-f dbsl-cats"><legend>Categories (up to 3)</legend><div class="dbsl-catgrid">';
		foreach ( $opts as $id => $name ) {
			$h .= '<label><input type="checkbox" name="cats[]" value="' . (int) $id . '"' . ( in_array( (int) $id, $cur, true ) ? ' checked' : '' ) . '> ' . esc_html( $name ) . '</label>';
		}
		return $h . '</div></fieldset>';
	}
}

if ( ! function_exists( 'db_seller_rdap_check' ) ) {
	/**
	 * Registry (RDAP) lookup for one name, cached for a day. Returns
	 * 'registered', 'unregistered' or 'unknown'. Stops looking up once the
	 * time budget runs out, so a long paste never stalls the page.
	 */
	function db_seller_rdap_check( $name, $until ) {
		$key = 'db_rdap_' . md5( $name );
		$hit = get_transient( $key );
		if ( is_string( $hit ) && '' !== $hit ) {
			return $hit;
		}
		if ( microtime( true ) > $until ) {
			return 'unknown';
		}
		$res  = wp_remote_get( 'https://rdap.org/domain/' . rawurlencode( $name ), array( 'timeout' => 4, 'redirection' => 3, 'headers' => array( 'Accept' => 'application/rdap+json' ) ) );
		$code = is_wp_error( $res ) ? null : (int) wp_remote_retrieve_response_code( $res );
		$out  = db_seller_rdap_verdict( $name, $code );
		if ( 'unknown' !== $out ) {
			set_transient( $key, $out, DAY_IN_SECONDS );
		}
		return $out;
	}
}

if ( ! function_exists( 'db_seller_tld_exists' ) ) {
	/**
	 * The name's ending is a real top-level domain (it has name servers in the root zone).
	 * Answers true when DNS itself is unavailable, so a lookup failure never blocks a seller.
	 */
	function db_seller_tld_exists( $name ) {
		$tld = substr( strrchr( '.' . (string) $name, '.' ), 1 );
		if ( '' === $tld ) {
			return false;
		}
		$key = 'db_tld_' . md5( $tld );
		$hit = get_transient( $key );
		if ( 'y' === $hit || 'n' === $hit ) {
			return 'y' === $hit;
		}
		if ( ! function_exists( 'checkdnsrr' ) || ! checkdnsrr( 'com.', 'NS' ) ) {
			return true;
		}
		$ok = checkdnsrr( $tld . '.', 'NS' );
		set_transient( $key, $ok ? 'y' : 'n', WEEK_IN_SECONDS );
		return $ok;
	}
}

/* ----------------------------------------------------------------- pages */

if ( ! function_exists( 'db_seller_css' ) ) {
	function db_seller_css() {
		return '<style>' . ( function_exists( 'db_seller_offers_css' ) ? db_seller_offers_css() : '' ) . ( function_exists( 'db_seller_payouts_css' ) ? db_seller_payouts_css() : '' ) . '.dbsl{max-width:1100px;margin:0 auto;color:#0a1628}.dbsl *{box-sizing:border-box}'
			. '.dbsl-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;margin:0 0 28px;padding:0;list-style:none}'
			. '.dbsl-card{background:#fff;border:1px solid #dfe5e1;border-radius:20px;padding:22px 22px 20px;box-shadow:0 6px 20px rgba(10,22,40,.05)}'
			. '.dbsl-card h2,.dbsl-card h3{margin:0 0 8px;font-size:20px;font-weight:800;letter-spacing:-.01em;color:#0a1628}.dbsl-card p{margin:0 0 8px;font-size:15.5px;line-height:1.6;color:#374151}'
			. '.dbsl-n{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;margin:0 0 12px;border-radius:50%;background:#0a1628;color:#fff;font-weight:800;font-size:14px}'
			. '.dbsl-sec{margin:0 0 30px}.dbsl-sec>h2{margin:0 0 14px;font-size:clamp(22px,2.6vw,28px);font-weight:800;letter-spacing:-.02em}'
			. '.dbsl-btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 24px;border:0;border-radius:999px;background:#0a1628;color:#fff!important;font:700 16px/1 inherit;text-decoration:none!important;cursor:pointer}'
			. '.dbsl-btn:hover,.dbsl-btn:focus-visible{background:#16324f}.dbsl-btn--ghost{background:#fff;color:#0a1628!important;border:1.5px solid #cfd8d3}.dbsl-btn--ghost:hover{background:#f3f6f4}.dbsl-btn--sm{min-height:40px;padding:0 16px;font-size:14px}'
			. '.dbsl-fee{display:flex;flex-wrap:wrap;gap:12px 28px;margin:0 0 6px;font-size:16px}.dbsl-fee strong{font-size:26px;font-weight:800;display:block}'
			. '.dbsl-msg{margin:0 0 10px;padding:12px 16px;border-radius:14px;font-size:15px;border:1px solid}.dbsl-msg--ok{background:#ecf7ef;border-color:#b7e1c3;color:#0b5a2b}.dbsl-msg--warn{background:#fff8e6;border-color:#f0d58a;color:#6b4a00}.dbsl-msg--bad{background:#fef2f2;border-color:#fca5a5;color:#991b1b}'
			. '.dbsl label{display:block;margin:0 0 6px;font-size:14.5px;font-weight:700;color:#0a1628}.dbsl-f{margin:0 0 16px}'
			. '.dbsl input[type=text],.dbsl input[type=tel],.dbsl select,.dbsl textarea{width:100%;min-height:46px;padding:10px 14px;border:1.5px solid #cfd8d3;border-radius:14px;font:inherit;font-size:16px;color:#0a1628;background:#fff}'
			. '.dbsl textarea{min-height:140px;resize:vertical}.dbsl input:focus,.dbsl select:focus,.dbsl textarea:focus{outline:none;border-color:#0a1628;box-shadow:0 0 0 4px rgba(22,163,74,.2)}'
			. '.dbsl-check{display:flex;gap:10px;align-items:flex-start;font-weight:400!important;font-size:15px!important}.dbsl-check input{margin-top:4px;width:18px;height:18px;accent-color:#0a1628;flex:none}'
			. '.dbsl-2{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}@media(max-width:640px){.dbsl-2{grid-template-columns:1fr}}'
			. '.dbsl-tbl{width:100%;border-collapse:collapse;font-size:15px}.dbsl-tbl th{text-align:left;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#4b5563;padding:8px 8px;border-bottom:1px solid #e5ebe7}'
			. '.dbsl-tbl td{padding:10px 8px;border-bottom:1px solid #eef2ef;vertical-align:middle}.dbsl-tbl td input,.dbsl-tbl td select{min-height:42px;padding:6px 10px;font-size:15px}.dbsl-dom{font-weight:700;overflow-wrap:anywhere}'
			. '.dbsl-chip{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12.5px;font-weight:700;white-space:nowrap}.dbsl-chip--ok{background:#e3f5e8;color:#0b5a2b}.dbsl-chip--warn{background:#fff3cd;color:#6b4a00}.dbsl-chip--bad{background:#fde2e2;color:#991b1b}.dbsl-chip--muted{background:#eef1f4;color:#374151}'
			. '.dbsl-code{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:6px 0 12px}.dbsl-code code{padding:8px 12px;border-radius:10px;background:#f3f6f4;border:1px solid #dfe5e1;font-size:14px;overflow-wrap:anywhere;color:#0a1628}'
			. '.dbsl-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:14px}.dbsl-muted{color:#4b5563;font-size:14px}'
			. '.dbsl-scroll{overflow-x:auto}.dbsl-link{background:none;border:0;padding:6px;color:#b42318;font:inherit;font-size:14px;text-decoration:underline;cursor:pointer;min-height:40px}'
			. '@media(max-width:760px){.dbsl-tbl thead{display:none}.dbsl-tbl tr{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px 0;border-bottom:1px solid #e5ebe7}.dbsl-tbl td{border:0;padding:0}.dbsl-tbl td.dbsl-dom,.dbsl-tbl td.dbsl-wide{grid-column:1/-1}.dbsl-tbl td:first-child{grid-column:1/-1}.dbsl-tbl td[data-l]::before{content:attr(data-l);display:block;font-size:12px;color:#4b5563;margin-bottom:2px}}'
			. '.dbsl-two{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}@media(max-width:860px){.dbsl-two{grid-template-columns:1fr}}.dbsl-ul{margin:0 0 12px 18px;padding:0;font-size:15px;line-height:1.6}.dbsl-preview h3{margin:14px 0 6px;font-size:16px}.dbsl-radios{border:0;padding:0;margin:0 0 14px}.dbsl-radios legend{font-weight:700;font-size:14.5px;margin-bottom:6px}.dbsl-radios .dbsl-check{margin-bottom:6px}'
			. '.dbsl-filters{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px}.dbsl-filters a{display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:0 14px;border:1.5px solid #cfd8d3;border-radius:999px;color:#0a1628!important;text-decoration:none!important;font-weight:600;font-size:14px}.dbsl-filters a span{color:#4b5563;font-weight:700}.dbsl-filters a.is-on{background:#0a1628;border-color:#0a1628;color:#fff!important}.dbsl-filters a.is-on span{color:#d9f99d}'
			. '.dbsl-bulk{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin:0 0 10px}.dbsl-bulk select{max-width:240px}.dbsl input[type=file]{font-size:15px;max-width:100%}.dbsl input[type=checkbox]{width:18px;height:18px;accent-color:#0a1628}'
			. '.dbsl-cats{border:0;padding:0;margin:0 0 14px}.dbsl-cats legend{font-weight:700;margin:0 0 8px}.dbsl-catgrid{display:flex;flex-wrap:wrap;gap:8px}.dbsl-catgrid label{display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:0 14px;border:1.5px solid #d3d9e1;border-radius:999px;font-weight:600;cursor:pointer}.dbsl-catgrid label:has(input:checked){border-color:#0a1628;background:#0a1628;color:#fff}.dbsl-guide{margin:0 0 14px;padding:12px 16px;border-radius:14px;background:#f2f8f0;border:1px solid #d9eccf}.dbsl-guide p{margin:0 0 4px}.dbsl-edit{font-weight:700;color:#0b6b36!important;white-space:nowrap}'
			. 'html body .dbsl button.dbsl-link{display:inline;min-height:40px;padding:6px!important;border:0!important;border-radius:0!important;background:none!important;box-shadow:none!important;color:#b42318!important;font-size:14px!important;font-weight:600!important;text-decoration:underline!important;text-transform:none!important;letter-spacing:0!important}'
			. 'html body .dbsl button.dbsl-btn:disabled{background:#c9d1cd!important;color:#36423d!important;border-color:#c9d1cd!important;cursor:not-allowed!important;box-shadow:none!important}'
			. '</style>';
	}
}

if ( ! function_exists( 'db_seller_landing_body' ) ) {
	function db_seller_landing_body() {
		$cta = home_url( '/seller/' );
		if ( ! function_exists( 'db_lp_css' ) ) {
			return '<p class="db-sales-lead">List your domain names with Domain Brothers. Listing is free, and you pay a commission only when a name sells.</p><p><a href="' . esc_url( $cta ) . '">Create your seller account</a></p>';
		}
		$pct  = DB_SELLER_COMMISSION_PCT;
		$excl = DB_SELLER_EXCLUSIVE_PCT;
		$min  = DB_SELLER_COMMISSION_MIN;
		$h    = '<p class="db-sales-lead">List your domain names with Domain Brothers. Listing is free, you answer offers from your seller dashboard with our brokers watching every deal, and you pay a commission only when a name sells.</p>';
		$h   .= db_lp_css() . '<div class="dblp">';
		$h   .= '<div class="dblp-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin:0 0 40px"><a class="dblp-btn" href="' . esc_url( $cta ) . '">Create your seller account</a><a class="dblp-btn dblp-btn--ghost" href="#dbsl-broker">Large portfolio? Talk to a broker</a></div>';
		$h   .= '<section aria-labelledby="dbsl-why" style="margin:0 0 56px"><h2 id="dbsl-why">Get your domains the attention they need</h2>'
			. db_lp_benefits( array(
				array( 'Brokers behind every sale', 'You answer offers yourself: accept, counter or decline. Our brokers see every offer, step in when a seller does not answer, secure payment before you transfer and see the transfer through.', '<path d="M4 6h16v11H9l-5 4z"/><path d="M8 10h8M8 13h5"/>' ),
				array( 'Marketing for your names', 'Every live name gets its own page in our marketplace, appears in search and category lists on this site, and can be shared from its page.', '<path d="M3 11l15-6v14L3 13z"/><path d="M7 13v5h3"/>' ),
				array( 'Pages built to be found in search', 'Each listing page has its own title, description and structured data, so search engines can index it.', '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4.5-4.5"/>' ),
				array( 'Flexible commissions', $pct . '% when a name sells, or ' . $excl . '% if you list it only with us. $' . $min . ' minimum. Listing is free.', '<path d="M12 3v18M17 7H9.5a3 3 0 000 6h5a3 3 0 010 6H6"/>' ),
			) ) . '</section>';
		$h .= db_lp_steps( 'List for free, pay only when it sells. Five short steps.', array(
			array( 'Your details', 'Create a free account with your email (no password: we send you a 6-digit code) and accept the seller agreement.' ),
			array( 'Add names', 'Paste up to ' . DB_SELLER_MAX_NAMES . ' names, or upload a spreadsheet (CSV or Excel).' ),
			array( 'Set prices', 'Choose Buy Now with a price, or Make Offer with an optional private minimum.' ),
			array( 'Prove ownership', 'Add one DNS record at your registrar; we check it automatically.' ),
			array( 'Submit', 'A broker reviews a new seller\'s first names, usually within one business day. Then they go live.' ),
		), 'dbsl-how' );
		$h .= '<p class="dblp-tip"><strong>Tip:</strong> buyers can check out straight away on a Buy Now price, so priced names are easier to sell.</p>';
		$h .= '<section aria-labelledby="dbsl-fee" style="margin:0 0 56px"><h2 id="dbsl-fee">What it costs</h2><div class="dblp-card"><div class="dbsl-fee" style="display:flex;flex-wrap:wrap;gap:12px 32px;margin:0 0 8px"><div><strong style="display:block;font-size:26px">Free</strong>to list</div><div><strong style="display:block;font-size:26px">' . (int) $pct . '%</strong>when a name sells</div><div><strong style="display:block;font-size:26px">' . (int) $excl . '%</strong>if listed only with us</div><div><strong style="display:block;font-size:26px">$' . (int) $min . '</strong>minimum commission</div></div>'
			. '<p style="margin:0">Example: a name that sells for $5,000 pays you $' . number_format( 5000 - db_seller_commission( 5000 ) ) . '. Nothing is charged if a name does not sell.</p></div></section>';
		$h .= '<section aria-labelledby="dbsl-point" style="margin:0 0 56px"><h2 id="dbsl-point">Show a sales page on the name itself</h2><p class="dblp-lead">Point a live name at us (one A record and one www record, shown in your seller dashboard) and visitors who type it see that it is for sale, with a link to buy. Their visits are counted on your dashboard.</p></section>';
		$h .= '<div class="dblp-two">'
			. db_lp_contact_card( 'For help with selling, contact our team', 'Questions about pricing, transfers or a large portfolio? Talk to a broker.', 'Create your seller account', $cta )
			. '<div class="dblp-card"><h3>What is my domain worth?</h3><p>See median asking prices by length and extension in our price index, read how we value names, or send your list to a broker below.</p><p style="display:flex;flex-wrap:wrap;gap:10px;margin:0"><a class="dblp-btn dblp-btn--ghost" href="' . esc_url( home_url( '/domain-price-index/' ) ) . '">Domain price index</a><a class="dblp-btn dblp-btn--ghost" href="' . esc_url( home_url( '/how-to-value-a-premium-domain-name/' ) ) . '">How we value names</a></p></div>'
			. '</div>';
		$h .= '<div class="dblp-split" id="dbsl-broker"><div><h2>Large portfolio? Talk to a broker</h2><p class="dblp-lead">Send your list (or a spreadsheet with prices) and a broker contacts you, usually within one business day, to agree how we list and sell it.</p></div>'
			. '<div class="dblp-form"><h2>Send your portfolio</h2><p class="dblp-req">Fields marked * are required.</p>' . do_shortcode( '[contact-form-7 id="1906" title="Sell Your Domain"]' ) . '</div></div>';
		$h .= db_lp_faq( 'Questions about selling', array(
			array( 'Who negotiates with buyers?', 'You do, from your seller dashboard: accept, counter or decline each offer. An offer below your minimum is answered automatically. Our brokers see every offer and take over if you do not answer in time.' ),
			array( 'How fast must I answer offers and transfer a sold name?', db_seller_rules_sentence() ),
			array( 'How do I get paid?', 'The buyer pays Domain Brothers first, and payment is confirmed before you transfer the name. You are paid the sale price minus commission 7 days after the buyer confirms control, or 14 days after the transfer if no dispute is raised. Payouts are sent by bank transfer; Stripe payouts for sellers in the United States will follow.' ),
			array( 'What does exclusive mean?', 'You do not list the name anywhere else while it is with us. It gets an "Exclusive" badge on its page and the lower ' . $excl . '% commission.' ),
			array( 'Can I list my names elsewhere too?', 'Yes, unless you choose exclusive. Remove a name at any time from your seller page.' ),
			array( 'Who can sell?', 'Sellers in the United States and Canada, for now. Prices are in US dollars.' ),
			array( 'What if a name sells somewhere else?', 'Choose "I sold it elsewhere" on that name in your seller page and it comes down at once, with no penalty.' ),
		), 'dbsl-faq' );
		$h .= '<div class="dblp-card" style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between;margin-top:40px"><div><h3 style="margin:0 0 4px">Ready to list?</h3><p style="margin:0">It takes a few minutes. Read the <a href="' . esc_url( home_url( '/seller-agreement/' ) ) . '">seller agreement</a> first.</p></div><a class="dblp-btn" href="' . esc_url( $cta ) . '">Create your seller account</a></div>';
		return $h . '</div>';
	}
}

if ( ! function_exists( 'db_seller_transfer_hours' ) ) {
	/** Hours a seller has to start a transfer after a sale (admin-editable rule). */
	function db_seller_transfer_hours() {
		$days = function_exists( 'db_seller_rule' ) ? (float) db_seller_rule( 'transfer_days' ) : 3;
		return (int) round( max( 1, $days ) * 24 );
	}
}

if ( ! function_exists( 'db_seller_rules_sentence' ) ) {
	/** The offer and transfer rules in plain words, from the live (admin-editable) values. */
	function db_seller_rules_sentence() {
		$r = static function ( $k, $d ) {
			return function_exists( 'db_seller_rule' ) ? db_seller_rule( $k ) : $d;
		};
		$remind  = array_map( 'intval', (array) $r( 'remind_days', array( 1, 3, 5 ) ) );
		$strike  = (int) $r( 'strike_days', 7 );
		$strikes = (int) $r( 'suspend_strikes', 2 );
		$window  = (int) $r( 'strike_window', 90 );
		$idle    = (int) $r( 'suspend_idle_days', 10 );
		$last    = array_pop( $remind );
		$days    = $remind ? implode( ', ', $remind ) . ' and ' . $last : (string) $last;
		return 'Answer each offer within ' . $strike . ' days. We remind you after ' . $days . ' days. An offer left unanswered for ' . $strike . ' days counts as a strike: the buyer is told and a broker takes over. ' . $strikes . ' strikes within ' . $window . ' days, or one offer left for ' . $idle . ' days, takes all your names offline until you ask us to reactivate your account. A sold name must be transferred within ' . db_seller_transfer_hours() . ' hours of payment.';
	}
}

if ( ! function_exists( 'db_seller_agreement_body' ) ) {
	function db_seller_agreement_body() {
		$h  = '<p class="db-sales-lead">The terms for listing domain names on Domain Brothers. Version ' . esc_html( DB_SELLER_AGREEMENT_VERSION ) . '.</p>' . db_seller_css() . '<div class="dbsl">';
		$h .= '<div class="dbsl-card">';
		$terms = array(
			'Your names'      => 'You confirm you own each name you list, or are authorised to sell it, that it is free of liens and disputes, and that it does not infringe anyone\'s trademark.',
			'Listing'         => 'Listing is free. You may list a name elsewhere too, unless you choose exclusive for it (the lower commission below). Domain Brothers may decline or remove any listing, including names that break our listing rules.',
			'Prices and offers' => 'You set a Buy Now price or ask for offers, and may set a minimum offer. You answer offers yourself in your seller dashboard: accept, counter or decline. An offer below your minimum is answered automatically with your minimum. Domain Brothers brokers can see every offer and conversation.',
			'Commission'      => 'When a name sells, Domain Brothers keeps ' . DB_SELLER_COMMISSION_PCT . '% of the sale price (' . DB_SELLER_EXCLUSIVE_PCT . '% for a name you list exclusively with us, meaning you do not list it anywhere else while it is with us), with a minimum of $' . DB_SELLER_COMMISSION_MIN . '. Nothing is charged if a name does not sell.',
			'Response times'  => db_seller_rules_sentence(),
			'Sale and transfer' => 'The buyer pays Domain Brothers first. Once payment is confirmed, you start the transfer as instructed within ' . db_seller_transfer_hours() . ' hours, by authorization code or registrar push. If you do not, it counts as a strike, your payout is held and a broker steps in.',
			'Payout'          => 'You are paid the sale price minus commission ' . DB_SELLER_PAY_AFTER_CONFIRM_DAYS . ' days after the buyer confirms control of the name, or ' . DB_SELLER_PAY_AFTER_TRANSFER_DAYS . ' days after transfer if no dispute is raised. Payments may be held while a dispute is open or after a missed transfer deadline.',
			'Ownership checks' => 'You add a DNS record we provide so we can confirm control of each name. Keep the record until the name sells or is removed. Live names are checked again every ' . DB_SELLER_RECHECK_DAYS . ' days; a name that fails has ' . DB_SELLER_RECHECK_GRACE_DAYS . ' days to be fixed before it comes down. If another account proves control of a name, the listing moves to that account. A name that expires comes down.',
			'Data'            => 'We store your name, email, country, phone and listing details to run the service, as described in our privacy policy.',
			'Eligibility'     => 'Selling is open to sellers in the United States and Canada. Prices are in US dollars.',
		);
		foreach ( $terms as $k => $v ) {
			$h .= '<h3>' . esc_html( $k ) . '</h3><p>' . esc_html( $v ) . '</p>';
		}
		return $h . '</div></div>';
	}
}

if ( ! function_exists( 'db_seller_dashboard_body' ) ) {
	function db_seller_dashboard_body( $email, $seller, array $msgs ) {
		$t    = esc_attr( db_seller_csrf( $email ) );
		$self = esc_url( home_url( '/seller/' ) );
		$h    = '<p class="db-sales-lead">Signed in as ' . esc_html( $email ) . '.</p>' . db_seller_css() . '<div class="dbsl">';
		foreach ( $msgs as $m ) {
			$h .= '<p class="dbsl-msg dbsl-msg--' . esc_attr( $m[0] ) . '" role="status">' . esc_html( $m[1] ) . '</p>';
		}
		if ( ! $seller ) {
			$h .= '<section class="dbsl-card dbsl-sec" aria-labelledby="dbsl-setup"><h2 id="dbsl-setup">Seller setup</h2><p>Tell us who is selling. We use these details for payouts and the seller agreement.</p>'
				. '<form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="setup"><input type="hidden" name="t" value="' . $t . '">'
				. '<div class="dbsl-2"><div class="dbsl-f"><label for="dbsl-ln">Full legal name</label><input type="text" id="dbsl-ln" name="legal_name" required maxlength="120" autocomplete="name"></div>'
				. '<div class="dbsl-f"><label for="dbsl-co">Company (optional)</label><input type="text" id="dbsl-co" name="company" maxlength="120" autocomplete="organization"></div>'
				. '<div class="dbsl-f"><label for="dbsl-cy">Country</label><select id="dbsl-cy" name="country" required><option value="">Choose</option><option value="US">United States</option><option value="CA">Canada</option></select></div>'
				. '<div class="dbsl-f"><label for="dbsl-ph">Phone (optional)</label><input type="tel" id="dbsl-ph" name="phone" maxlength="30" autocomplete="tel"></div></div>'
				. '<div class="dbsl-f"><label class="dbsl-check"><input type="checkbox" name="agree" value="1" required> <span>I accept the <a href="' . esc_url( home_url( '/seller-agreement/' ) ) . '" target="_blank" rel="noopener">seller agreement and listing rules</a>.</span></label></div>'
				. '<button type="submit" class="dbsl-btn">Continue</button></form></section>';
			return $h . '</div>';
		}
		if ( 'active' !== $seller['status'] ) {
			return $h . '<p class="dbsl-msg dbsl-msg--bad">Your seller account is paused. <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Contact us</a> for help.</p></div>';
		}
		$rows     = db_seller_listings( (int) $seller['id'] );
		$editable = array_filter( $rows, function ( $r ) { return in_array( $r['review_status'], array( 'draft', 'rejected', 'approved' ), true ); } );
		$unverif  = array_filter( $rows, function ( $r ) { return 'draft' === $r['review_status'] && 'verified' !== $r['verify_status']; } );
		$plan     = get_transient( 'db_seller_import_' . (int) $seller['id'] );

		$h .= '<!--dbsl:import-->';
		// Import preview (waits for confirmation).
		if ( is_array( $plan ) ) {
			$list = function ( $items, $fmt ) { return $items ? '<ul class="dbsl-ul">' . implode( '', array_map( $fmt, array_slice( $items, 0, 25 ) ) ) . ( count( $items ) > 25 ? '<li>and ' . ( count( $items ) - 25 ) . ' more</li>' : '' ) . '</ul>' : ''; };
			$h   .= '<section class="dbsl-card dbsl-sec dbsl-preview" aria-labelledby="dbsl-pv"><h2 id="dbsl-pv">Confirm your import</h2>'
				. '<div class="dbsl-fee"><div><strong>' . count( $plan['add'] ) . '</strong>to add</div><div><strong>' . count( $plan['update'] ) . '</strong>to update</div><div><strong>' . count( $plan['remove'] ) . '</strong>to remove</div><div><strong>' . ( count( $plan['skip'] ) + count( (array) $plan['errors'] ) ) . '</strong>skipped</div></div>';
			if ( $plan['remove'] ) {
				$h .= '<p class="dbsl-msg dbsl-msg--warn">' . ( 'sync' === $plan['mode'] ? 'Sync removes every name that is not in your file. ' : '' ) . 'These names will be removed' . ( array_filter( $rows, function ( $r ) use ( $plan ) { return 'approved' === $r['review_status'] && in_array( $r['domain'], $plan['remove'], true ); } ) ? ', and live ones taken off the site' : '' ) . ':</p>' . $list( $plan['remove'], function ( $d ) { return '<li>' . esc_html( $d ) . '</li>'; } );
			}
			if ( $plan['add'] ) {
				$h .= '<h3>To add</h3>' . $list( $plan['add'], function ( $a ) { return '<li>' . esc_html( $a['domain'] ) . ' &middot; ' . ( 'make_offer' === $a['option'] ? 'Make Offer' : 'Buy Now $' . number_format( $a['price'] ) ) . '</li>'; } );
			}
			if ( $plan['update'] ) {
				$h .= '<h3>To update</h3>' . $list( $plan['update'], function ( $a ) { return '<li>' . esc_html( $a['domain'] ) . ' &middot; ' . ( 'make_offer' === $a['option'] ? 'Make Offer' : 'Buy Now $' . number_format( $a['price'] ) ) . '</li>'; } );
			}
			$skips = array_merge( array_map( function ( $s ) { return $s[0] . ': ' . $s[1]; }, $plan['skip'] ), (array) $plan['errors'] );
			if ( $skips ) {
				$h .= '<h3>Skipped</h3>' . $list( $skips, function ( $s ) { return '<li>' . esc_html( $s ) . '</li>'; } );
			}
			$h .= '<div class="dbsl-actions"><form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="import_apply"><input type="hidden" name="t" value="' . $t . '"><button type="submit" class="dbsl-btn"' . ( $plan['add'] || $plan['update'] || $plan['remove'] ? '' : ' disabled aria-disabled="true"' ) . '>Confirm import</button></form>'
				. '<form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="import_cancel"><input type="hidden" name="t" value="' . $t . '"><button type="submit" class="dbsl-btn dbsl-btn--ghost">Cancel</button></form></div></section>';
		}

		$h .= '<!--dbsl:add-->';
		// Add names: paste, or upload a spreadsheet.
		$h .= '<div class="dbsl-two"><section class="dbsl-card dbsl-sec" aria-labelledby="dbsl-add"><h2 id="dbsl-add">Add names</h2><form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="add"><input type="hidden" name="t" value="' . $t . '">'
			. '<div class="dbsl-f"><label for="dbsl-names">Domain names, up to ' . DB_SELLER_MAX_NAMES . ' at a time</label><textarea id="dbsl-names" name="names" required placeholder="example.com, another.ca" aria-describedby="dbsl-names-h"></textarea><p id="dbsl-names-h" class="dbsl-muted">Separate names with commas, spaces or new lines. Web addresses are fine; we keep just the name.</p></div>'
			. '<button type="submit" class="dbsl-btn">Add names</button></form></section>'
			. '<section class="dbsl-card dbsl-sec" aria-labelledby="dbsl-up"><h2 id="dbsl-up">Upload a spreadsheet</h2><form method="post" action="' . $self . '" enctype="multipart/form-data"><input type="hidden" name="db_seller_action" value="import_preview"><input type="hidden" name="t" value="' . $t . '">'
			. '<div class="dbsl-f"><label for="dbsl-file">CSV or XLSX file, up to ' . DB_SELLER_MAX_ROWS . ' rows</label><input type="file" id="dbsl-file" name="sheet" accept=".csv,.xlsx,text/csv" required aria-describedby="dbsl-file-h"><p id="dbsl-file-h" class="dbsl-muted">Columns: Domain Name, Selling Option, For Sale, Price, Minimum Price, Currency, Action Type. A Sedo export works as it is. <a href="' . esc_url( add_query_arg( 'template', '1', home_url( '/seller/' ) ) ) . '">Download the template</a>.</p></div>'
			. '<fieldset class="dbsl-f dbsl-radios"><legend>Mode</legend><label class="dbsl-check"><input type="radio" name="mode" value="import" checked> <span><strong>Import:</strong> add new names and update the ones in the file</span></label><label class="dbsl-check"><input type="radio" name="mode" value="sync"> <span><strong>Sync:</strong> also remove names that are not in the file</span></label></fieldset>'
			. '<button type="submit" class="dbsl-btn dbsl-btn--ghost">Read the file</button><p class="dbsl-muted" style="margin-top:8px">Nothing changes until you confirm.</p></form></section></div>';

		$h .= '<!--dbsl:prices-->';
		if ( $editable ) {
			// Prices.
			$guide = '';
			$exts  = array();
			foreach ( $editable as $r ) {
				$dp = strrpos( $r['domain'], '.' );
				$exts[ substr( $r['domain'], $dp ) ] = true;
			}
			$sold = db_seller_sold_prices();
			foreach ( array_keys( $exts ) as $x ) {
				$g = db_seller_price_guide( $sold, $x );
				if ( $g ) {
					$guide .= '<li><strong>' . esc_html( $x ) . '</strong>: ' . (int) $g['count'] . ' sold through Domain Brothers, median $' . esc_html( number_format( $g['median'] ) ) . ' (range $' . esc_html( number_format( $g['low'] ) ) . ' to $' . esc_html( number_format( $g['high'] ) ) . ')</li>';
				}
			}
			$h .= '<section class="dbsl-card dbsl-sec" aria-labelledby="dbsl-prices"><h2 id="dbsl-prices">Prices</h2><p>Buy Now needs a price of at least $' . DB_SELLER_MIN_PRICE . '. The minimum offer is not shown on the listing; an offer below it is answered automatically with your minimum, so a buyer learns it only by offering less. Changes to live names apply at once. Commission is ' . DB_SELLER_COMMISSION_PCT . '% (minimum $' . DB_SELLER_COMMISSION_MIN . ') when a name sells, or ' . DB_SELLER_EXCLUSIVE_PCT . '% if you list it only with us.</p>'
				. ( '' !== $guide ? '<div class="dbsl-guide"><p><strong>Price guide</strong> from our own sales:</p><ul class="dbsl-ul">' . $guide . '</ul></div>' : '' )
				. '<form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="price"><input type="hidden" name="t" value="' . $t . '"><div class="dbsl-scroll"><table class="dbsl-tbl"><thead><tr><th scope="col">Name</th><th scope="col">Selling option</th><th scope="col">Price (USD)</th><th scope="col">Minimum offer (USD)</th><th scope="col">Exclusive</th></tr></thead><tbody>';
			foreach ( $editable as $r ) {
				$id = (int) $r['id'];
				$h .= '<tr><td class="dbsl-dom">' . esc_html( $r['domain'] ) . ( 'approved' === $r['review_status'] ? ' <span class="dbsl-chip dbsl-chip--ok">Live</span>' : '' ) . '</td>'
					. '<td data-l="Selling option"><label class="screen-reader-text" for="dbsl-o' . $id . '">Selling option for ' . esc_html( $r['domain'] ) . '</label><select id="dbsl-o' . $id . '" name="row[' . $id . '][option]"><option value="buy_now"' . selected( $r['option_type'], 'buy_now', false ) . '>Buy Now</option><option value="make_offer"' . selected( $r['option_type'], 'make_offer', false ) . '>Make Offer</option></select></td>'
					. '<td data-l="Price"><label class="screen-reader-text" for="dbsl-p' . $id . '">Price for ' . esc_html( $r['domain'] ) . '</label><input type="text" inputmode="numeric" id="dbsl-p' . $id . '" name="row[' . $id . '][price]" value="' . ( (int) $r['price'] ? esc_attr( number_format( (int) $r['price'] ) ) : '' ) . '" placeholder="e.g. 2,500"></td>'
					. '<td data-l="Minimum offer"><label class="screen-reader-text" for="dbsl-m' . $id . '">Minimum offer for ' . esc_html( $r['domain'] ) . '</label><input type="text" inputmode="numeric" id="dbsl-m' . $id . '" name="row[' . $id . '][min_offer]" value="' . ( (int) $r['min_offer'] ? esc_attr( number_format( (int) $r['min_offer'] ) ) : '' ) . '" placeholder="optional"></td>'
					. '<td data-l="Exclusive"><label class="dbsl-check"><input type="checkbox" name="row[' . $id . '][exclusive]" value="1"' . checked( (int) $r['exclusive'], 1, false ) . '> <span>Only with us (' . DB_SELLER_EXCLUSIVE_PCT . '%)</span></label></td></tr>';
			}
			$h .= '</tbody></table></div><p class="dbsl-muted">Exclusive means you do not list the name anywhere else while it is with us. It gets an "Exclusive" badge on its page and a lower commission.</p><div class="dbsl-actions"><button type="submit" class="dbsl-btn">Save prices</button></div></form></section>';
		}

		$h .= '<!--dbsl:verify-->';
		if ( $unverif ) {
			// Ownership.
			$txt   = db_seller_txt_value( $seller['token'] );
			$first = reset( $unverif );
			$h    .= '<section class="dbsl-card dbsl-sec" aria-labelledby="dbsl-own"><h2 id="dbsl-own">Prove you own the names</h2>'
				. '<p>At your registrar, add this <strong>TXT record</strong> to each name (host <code>@</code>, the name itself). It is the same for all your names.</p>'
				. '<div class="dbsl-code"><code id="dbsl-txt">' . esc_html( $txt ) . '</code><button type="button" class="dbsl-btn dbsl-btn--ghost dbsl-btn--sm" data-copy="dbsl-txt">Copy</button></div>'
				. '<p class="dbsl-muted">Or add a CNAME record instead: host <code>' . esc_html( 'db-' . $seller['token'] ) . '</code> pointing to <code>' . esc_html( DB_SELLER_CNAME_TARGET ) . '</code> (for ' . esc_html( $first['domain'] ) . ' the full host is ' . esc_html( db_seller_cname_host( $seller['token'], $first['domain'] ) ) . ').</p>'
				. '<p class="dbsl-muted">' . count( $unverif ) . ' ' . ( 1 === count( $unverif ) ? 'name is' : 'names are' ) . ' waiting. We check every hour for 3 days. Changes usually show within an hour.</p>'
				. '<form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="check"><input type="hidden" name="t" value="' . $t . '"><button type="submit" class="dbsl-btn dbsl-btn--ghost">Check now</button></form></section>';
		}

		$h .= '<!--dbsl:edit-->';
		// Details editor (?edit=ID).
		$edit_id = isset( $_GET['edit'] ) ? (int) $_GET['edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( $rows as $r ) {
			if ( (int) $r['id'] !== $edit_id || 'pending' === $r['review_status'] ) {
				continue;
			}
			$h .= '<section class="dbsl-card dbsl-sec" id="dbsl-edit" aria-labelledby="dbsl-ed-h"><h2 id="dbsl-ed-h">Details for ' . esc_html( $r['domain'] ) . '</h2><p>Shown on the name\'s page' . ( 'approved' === $r['review_status'] ? ' at once' : ' once it is live' ) . '. Say who the name suits; buyers read this.</p>'
				. '<form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="details"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '">'
				. '<div class="dbsl-f"><label for="dbsl-desc">Description (up to 600 characters)</label><textarea id="dbsl-desc" name="description" maxlength="600">' . esc_textarea( (string) $r['description'] ) . '</textarea></div>'
				. '<div class="dbsl-f"><label for="dbsl-kw">Keywords (up to 8, separated by commas)</label><input type="text" id="dbsl-kw" name="keywords" maxlength="255" value="' . esc_attr( (string) $r['keywords'] ) . '" placeholder="bakery, bread, Toronto"></div>'
				. db_seller_category_fieldset( (string) ( $r['categories'] ?? '' ) )
				. '<div class="dbsl-actions"><button type="submit" class="dbsl-btn">Save details</button><a class="dbsl-btn dbsl-btn--ghost" href="' . esc_url( home_url( '/seller/' ) ) . '#dbsl-list">Close</a></div></form>'
				. '<form method="post" action="' . $self . '" class="dbsa-sold" onsubmit="return confirm(\'Take ' . esc_js( $r['domain'] ) . ' off the site because you sold it elsewhere?\')"><input type="hidden" name="db_seller_action" value="sold_elsewhere"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><p class="dbsl-muted">Sold this name somewhere else? Tell us and we take it down at once, with no penalty.</p><button type="submit" class="dbsl-btn dbsl-btn--ghost dbsl-btn--sm">I sold it elsewhere</button></form>'
				. '<form method="post" action="' . $self . '" class="dbsa-remove" onsubmit="return confirm(\'Remove ' . esc_js( $r['domain'] ) . ' from sale? Its page comes off the site.\')"><input type="hidden" name="db_seller_action" value="remove"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="id" value="' . (int) $r['id'] . '"><p class="dbsl-muted">No longer want to sell it? Remove it from sale; you can add it again later.</p><button type="submit" class="dbsl-link">Remove this name</button></form></section>';
		}

		$h .= '<!--dbsl:offers-->';
		if ( function_exists( 'db_seller_offers_html' ) ) {
			$h .= db_seller_offers_html( $seller, $self, $t );
		}
		$h .= '<!--dbsl:payouts-->';
		if ( function_exists( 'db_seller_payouts_html' ) ) {
			$h .= db_seller_payouts_html( $seller );
		}
		$h .= '<!--dbsl:point-->';
		if ( function_exists( 'db_seller_point_html' ) ) {
			$h .= db_seller_point_html( $seller, $rows, $self, $t );
		}

		$h .= '<!--dbsl:names-->';
		// Your names: filters, bulk actions, statistics.
		$filters = array( 'all' => 'All', 'live' => 'Live', 'review' => 'In review', 'action' => 'Needs action' );
		$f       = isset( $_GET['f'] ) && isset( $filters[ $_GET['f'] ] ) ? (string) $_GET['f'] : 'all'; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$match   = function ( $r ) use ( $f ) {
			return 'all' === $f || ( 'live' === $f && 'approved' === $r['review_status'] ) || ( 'review' === $f && 'pending' === $r['review_status'] ) || ( 'action' === $f && in_array( $r['review_status'], array( 'draft', 'rejected' ), true ) );
		};
		$h .= '<section class="dbsl-card dbsl-sec" aria-labelledby="dbsl-list"><h2 id="dbsl-list">Your names</h2>';
		if ( ! $rows ) {
			$h .= '<p>No names yet. Add your first names above.</p>';
		} else {
			$h .= '<nav class="dbsl-filters" aria-label="Filter your names">';
			foreach ( $filters as $k => $label ) {
				$n  = count( array_filter( $rows, function ( $r ) use ( $k ) { return 'all' === $k || ( 'live' === $k && 'approved' === $r['review_status'] ) || ( 'review' === $k && 'pending' === $r['review_status'] ) || ( 'action' === $k && in_array( $r['review_status'], array( 'draft', 'rejected' ), true ) ); } ) );
				$h .= '<a href="' . esc_url( 'all' === $k ? home_url( '/seller/' ) : add_query_arg( 'f', $k, home_url( '/seller/' ) ) ) . '#dbsl-list"' . ( $f === $k ? ' aria-current="page" class="is-on"' : '' ) . '>' . esc_html( $label ) . ' <span>' . $n . '</span></a>';
			}
			$h    .= '</nav>';
			$ready = 0;
			foreach ( $rows as $r ) {
				if ( 'draft' === $r['review_status'] && db_seller_ready( $r ) ) {
					++$ready;
				}
			}
			$shown = array_filter( $rows, $match );
			$h    .= '<form method="post" action="' . $self . '" id="dbsl-bulkf"><input type="hidden" name="db_seller_action" value="bulk"><input type="hidden" name="t" value="' . $t . '">'
				. '<div class="dbsl-bulk"><label for="dbsl-bulk-do" class="screen-reader-text">Action for selected names</label><select id="dbsl-bulk-do" name="bulk"><option value="">With selected&hellip;</option><option value="adjust">Change price by %</option><option value="make_offer">Switch to Make Offer</option><option value="remove">Remove</option></select>'
				. '<label for="dbsl-pct" class="screen-reader-text">Percent change</label><input type="text" inputmode="decimal" id="dbsl-pct" name="pct" placeholder="% e.g. -10" style="max-width:130px">'
				. '<button type="submit" class="dbsl-btn dbsl-btn--ghost dbsl-btn--sm" onclick="var s=document.getElementById(\'dbsl-bulk-do\');return s.value!==\'remove\'||confirm(\'Remove the selected names?\');">Apply</button></div>'
				. '<div class="dbsl-scroll"><table class="dbsl-tbl"><thead><tr><th scope="col"><label class="screen-reader-text" for="dbsl-all">Select all</label><input type="checkbox" id="dbsl-all" data-all></th><th scope="col">Name</th><th scope="col">Option</th><th scope="col">Price</th><th scope="col">Status</th><th scope="col">Views</th><th scope="col">Saves</th><th scope="col">Offers</th><th scope="col"><span class="screen-reader-text">Details</span></th></tr></thead><tbody>';
			foreach ( $shown as $r ) {
				list( $label, $tone ) = db_seller_status( $r );
				$name = esc_html( $r['domain'] );
				$live = 'approved' === $r['review_status'] && (int) $r['post_id'];
				if ( $live ) {
					$name = '<a href="' . esc_url( get_permalink( (int) $r['post_id'] ) ) . '">' . $name . '</a>';
				}
				list( $saves, $offers, $views ) = $live ? db_seller_stats( $r ) : array( null, null, null );
				$note = 'rejected' === $r['review_status'] && '' !== $r['review_note'] ? '<br><span class="dbsl-muted">' . esc_html( $r['review_note'] ) . '</span>' : '';
				$h   .= '<tr><td><label class="screen-reader-text" for="dbsl-c' . (int) $r['id'] . '">Select ' . esc_html( $r['domain'] ) . '</label><input type="checkbox" id="dbsl-c' . (int) $r['id'] . '" name="ids[]" value="' . (int) $r['id'] . '"></td>'
					. '<td class="dbsl-dom">' . $name . '</td><td data-l="Option">' . ( 'make_offer' === $r['option_type'] ? 'Make Offer' : 'Buy Now' ) . '</td><td data-l="Price">' . ( (int) $r['price'] ? '$' . esc_html( number_format( (int) $r['price'] ) ) : '&mdash;' ) . '</td>'
					. '<td data-l="Status" class="dbsl-wide"><span class="dbsl-chip dbsl-chip--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>' . $note . '</td>'
					. '<td data-l="Views">' . ( null === $views ? '&mdash;' : (int) $views ) . '</td><td data-l="Saves">' . ( null === $saves ? '&mdash;' : (int) $saves ) . '</td><td data-l="Offers">' . ( null === $offers ? '&mdash;' : (int) $offers ) . '</td>'
					. '<td>' . ( 'pending' === $r['review_status'] ? '' : '<a class="dbsl-edit" href="' . esc_url( add_query_arg( 'edit', (int) $r['id'], home_url( '/seller/' ) ) ) . '#dbsl-edit" aria-label="Edit details for ' . esc_attr( $r['domain'] ) . '">Details</a>' ) . '</td></tr>';
			}
			if ( ! $shown ) {
				$h .= '<tr><td colspan="9">Nothing here.</td></tr>';
			}
			$h .= '</tbody></table></div></form>';
			$h .= '<form method="post" action="' . $self . '" class="dbsl-actions"><input type="hidden" name="db_seller_action" value="submit"><input type="hidden" name="t" value="' . $t . '"><button type="submit" class="dbsl-btn"' . ( $ready ? '' : ' disabled aria-disabled="true"' ) . '>' . ( 'trusted' === $seller['trust'] ? 'Publish' : 'Submit for review' ) . ( $ready ? ' (' . $ready . ')' : '' ) . '</button><span class="dbsl-muted">' . ( $ready ? 'Names with a valid price and a verified record are sent.' : 'A name is ready once it has a valid price and a verified record.' ) . '</span></form>';
		}
		$h .= '</section>';
		$h .= '<!--dbsl:tail-->';
		$h .= '<p class="dbsl-muted">Questions? <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Contact a broker</a>. <a href="' . esc_url( home_url( '/seller-agreement/' ) ) . '">Seller agreement</a>.</p>';
		$h .= '<script data-no-delay>document.addEventListener("change",function(e){if(e.target.matches&&e.target.matches("[data-all]")){document.querySelectorAll("#dbsl-bulkf input[name=\'ids[]\']").forEach(function(c){c.checked=e.target.checked;});}});document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest("[data-copy]");if(!b){return;}var t=document.getElementById(b.getAttribute("data-copy"));if(t&&navigator.clipboard){navigator.clipboard.writeText(t.textContent).then(function(){b.textContent="Copied";setTimeout(function(){b.textContent="Copy";},2000);});}});</script>';
		return $h . '</div>';
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! db_seller_enabled() || ! function_exists( 'db_sales_render' ) ) {
		return;
	}
	$path = db_sales_request_path();
	if ( 'sell-your-domains' === $path ) {
		db_sales_render( 'Sell your domains with us', 'Sell your domains', db_seller_landing_body() );
	}
	if ( 'seller-agreement' === $path ) {
		db_sales_render( 'Seller agreement', 'Seller agreement', db_seller_agreement_body() );
	}
	if ( 'seller' !== $path ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	$email = function_exists( 'db_wl_email' ) ? db_wl_email() : '';
	if ( '' === $email ) {
		wp_safe_redirect( add_query_arg( array( 'auth' => 'signin', 'next' => rawurlencode( home_url( '/seller/' ) ) ), home_url( '/my-wishlist/' ) ) );
		exit;
	}
	$seller = db_seller_get( $email );
	if ( isset( $_GET['template'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		status_header( 200 );
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="domain-brothers-import-template.csv"' );
		echo db_seller_template_csv(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$act  = isset( $_POST['db_seller_action'] ) ? sanitize_key( wp_unslash( $_POST['db_seller_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$msgs = db_seller_handle_post( $email, $seller );
		db_seller_flash( $email, $msgs );
		// After a successful step, go to the next one; otherwise stay where the seller was.
		$bad  = (bool) array_filter( (array) $msgs, static function ( $m ) { return 'bad' === ( $m[0] ?? '' ); } );
		$next = $bad ? '' : ( function_exists( 'db_seller_step_after' ) ? db_seller_step_after( $act ) : '' );
		$ref  = wp_get_referer();
		$back = ( $ref && 0 === strpos( $ref, home_url( '/seller/' ) ) ) ? remove_query_arg( array( 'edit' ), $ref ) : home_url( '/seller/' );
		if ( 'names' === $next ) {
			$back = add_query_arg( 'view', 'names', home_url( '/seller/' ) );
		} elseif ( '' !== $next ) {
			$back = add_query_arg( 'step', $next, home_url( '/seller/' ) );
		}
		wp_safe_redirect( $back );
		exit;
	}
	db_sales_render( 'Your seller page', 'Seller', function_exists( 'db_seller_app_body' ) ? db_seller_app_body( $email, $seller, db_seller_flash( $email ) ) : db_seller_dashboard_body( $email, $seller, db_seller_flash( $email ) ), true );
}, 11 );

/* ----------------------------------------------------------------- admin */

add_action( 'admin_menu', function () {
	add_management_page( 'Seller listings', 'Seller listings', 'manage_options', 'db-seller-review', 'db_seller_admin_page' );
} );

add_action( 'admin_post_db_seller_rules', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'db_seller_rules' );
	$in  = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$def = db_seller_rules_default();
	$out = array();
	foreach ( $def as $k => $d ) {
		if ( ! isset( $in[ $k ] ) ) {
			continue;
		}
		if ( is_array( $d ) ) {
			$days = array_values( array_unique( array_filter( array_map( 'intval', explode( ',', (string) $in[ $k ] ) ), static function ( $x ) { return $x > 0 && $x < 60; } ) ) );
			sort( $days );
			$out[ $k ] = $days ? array_slice( $days, 0, 5 ) : $d;
		} else {
			$v         = (int) $in[ $k ];
			$out[ $k ] = $v > 0 && $v <= 365 ? $v : $d;
		}
	}
	update_option( 'db_seller_rules', $out, false );
	wp_safe_redirect( add_query_arg( 'msg', rawurlencode( 'Seller rules saved.' ), admin_url( 'tools.php?page=db-seller-review#dbsl-rules' ) ) );
	exit;
} );

add_action( 'admin_post_db_seller_toggle', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'db_seller_toggle' );
	$on = isset( $_POST['on'] ) && '1' === (string) $_POST['on'];
	update_option( 'db_seller_on', $on ? '1' : '0' );
	if ( ! $on ) {
		foreach ( array( 'db_seller_verify_cron', 'db_seller_recheck_cron', 'db_seller_expiry_cron' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
	do_action( 'db_page_cache_purge_all' );
	do_action( 'litespeed_purge_all' );
	if ( function_exists( 'db_seller_audit' ) ) {
		db_seller_audit( 0, '', 'feature_' . ( $on ? 'on' : 'off' ), '"Sell with us" switched ' . ( $on ? 'on' : 'off' ) );
	}
	wp_safe_redirect( add_query_arg( 'msg', rawurlencode( '"Sell with us" is now ' . ( $on ? 'on' : 'off' ) . '.' ), admin_url( 'tools.php?page=db-seller-review' ) ) );
	exit;
} );

/* A shortcut to the switch from the Plugins screen. */
add_filter( 'plugin_action_links_' . plugin_basename( dirname( __FILE__ ) . '/db-custom-blocks.php' ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'tools.php?page=db-seller-review' ) ) . '">Sell with us: ' . ( db_seller_enabled() ? 'on' : 'off' ) . '</a>' );
	return $links;
} );

add_action( 'admin_post_db_seller_admin', function () {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'db_seller_admin' );
	$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
	$l   = db_seller_table( 'listings' );
	$s   = db_seller_table( 'sellers' );
	$msg = '';
	if ( in_array( $do, array( 'approve', 'reject' ), true ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT l.*, s.email, s.legal_name, s.status AS seller_status FROM {$l} l JOIN {$s} s ON s.id = l.seller_id WHERE l.id = %d", $id ), ARRAY_A );
		if ( $row && 'pending' === $row['review_status'] ) {
			if ( 'approve' === $do && 'active' !== (string) $row['seller_status'] ) {
				// A paused or offline seller cannot answer buyers, so their names wait (SL-24).
				$msg = 'Not published: the seller is ' . str_replace( '_', ' ', (string) $row['seller_status'] ) . '. Approve it once the account is active again.';
			} elseif ( 'approve' === $do ) {
				$res = db_seller_publish( $row );
				$msg = is_int( $res ) ? 'Published ' . $row['domain'] . '.' : 'Not published: ' . $res;
				if ( is_int( $res ) ) {
					db_seller_mail( $row['email'], $row['domain'] . ' is live on Domain Brothers', '<p>Your name <strong>' . esc_html( $row['domain'] ) . '</strong> is now listed for sale.</p><p><a href="' . esc_url( get_permalink( $res ) ) . '">View the listing</a></p><p>Our brokers handle every offer and will contact you when a buyer is ready.</p>' );
				}
			} else {
				$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
				$note = '' !== $note ? mb_substr( $note, 0, 250 ) : 'It does not meet our listing rules.';
				$wpdb->update( $l, array( 'review_status' => 'rejected', 'review_note' => $note, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				db_seller_mail( $row['email'], 'About ' . $row['domain'], '<p>We could not list <strong>' . esc_html( $row['domain'] ) . '</strong>.</p><p>Reason: ' . esc_html( $note ) . '</p><p>You can change it on your <a href="' . esc_url( home_url( '/seller/' ) ) . '">seller page</a> and submit it again, or reply to this email.</p>' );
				$msg = 'Rejected ' . $row['domain'] . '.';
			}
		}
	} elseif ( in_array( $do, array( 'suspend', 'unsuspend', 'reactivate', 'reset_strikes' ), true ) && function_exists( 'db_seller_suspend' ) ) {
		$sel = db_seller_by_id( $id );
		if ( $sel ) {
			if ( 'suspend' === $do ) {
				$why = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
				$n   = db_seller_suspend( $sel, '' !== $why ? $why : 'Paused by Domain Brothers.', 'admin' );
				$msg = 'Seller taken offline (' . $n . ' names).';
			} elseif ( 'reset_strikes' === $do ) {
				$wpdb->update( $s, array( 'strikes' => 0 ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}db_seller_audit SET action = 'strike_cleared' WHERE seller_id = %d AND action = 'strike'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				db_seller_audit( $id, '', 'strikes_reset', 'Strikes reset by the broker' );
				$msg = 'Strikes reset.';
			} else {
				$n   = db_seller_reactivate( $sel, ! empty( $_POST['reset'] ) );
				$msg = 'Seller reactivated (' . $n . ' names back online).';
			}
		}
	} elseif ( in_array( $do, array( 'trust', 'untrust', 'suspend', 'unsuspend' ), true ) ) {
		$data = 'trust' === $do ? array( 'trust' => 'trusted' ) : ( 'untrust' === $do ? array( 'trust' => 'new' ) : array( 'status' => 'suspend' === $do ? 'suspended' : 'active' ) );
		$wpdb->update( $s, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$msg = 'Seller updated.';
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'db-seller-review', 'msg' => rawurlencode( $msg ) ), admin_url( 'tools.php' ) ) );
	exit;
} );

if ( ! function_exists( 'db_seller_admin_page' ) ) {
	function db_seller_admin_page() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$l = db_seller_table( 'listings' );
		$s = db_seller_table( 'sellers' );
		$f = function ( $do, $id, $label, $extra = '', $cls = 'button' ) {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:0 4px 4px 0">' . wp_nonce_field( 'db_seller_admin', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="db_seller_admin"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="id" value="' . (int) $id . '">' . $extra . '<button class="' . esc_attr( $cls ) . '">' . esc_html( $label ) . '</button></form>';
		};
		echo '<div class="wrap"><h1>Seller listings</h1>';
		$on = db_seller_enabled();
		echo '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin:14px 0 18px;padding:16px 20px;border:1px solid ' . ( $on ? '#9ad27a' : '#dcdcde' ) . ';border-left-width:6px;border-radius:6px;background:' . ( $on ? '#f3fbee' : '#fff' ) . '">'
			. '<div style="flex:1 1 380px"><p style="margin:0;font-size:15px"><strong>"Sell with us" is ' . ( $on ? 'ON' : 'OFF' ) . '.</strong> ' . ( $on ? 'Visitors can open <a href="' . esc_url( home_url( '/sell-your-domains/' ) ) . '">/sell-your-domains/</a> and list names; the menu shows "Sell your domains".' : 'The seller pages, the menu link and the hourly ownership checks are switched off. Names already live stay listed; offers and payouts on them keep working.' ) . '</p></div>'
			. '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . wp_nonce_field( 'db_seller_toggle', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="db_seller_toggle"><input type="hidden" name="on" value="' . ( $on ? '0' : '1' ) . '">'
			. '<button class="button ' . ( $on ? '' : 'button-primary' ) . ' button-hero">' . ( $on ? 'Turn "Sell with us" off' : 'Turn "Sell with us" on' ) . '</button></form></div>';
		if ( ! empty( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$pending = (array) $wpdb->get_results( "SELECT l.*, s.email, s.legal_name, s.country, s.status AS seller_status FROM {$l} l JOIN {$s} s ON s.id = l.seller_id WHERE l.review_status = 'pending' ORDER BY l.updated_at ASC LIMIT 200", ARRAY_A );
		echo '<h2>Waiting for review (' . count( $pending ) . ')</h2>';
		if ( ! $pending ) {
			echo '<p>Nothing to review.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Seller</th><th>Option</th><th>Price</th><th>Minimum offer</th><th>Verified</th><th>Commission at price</th><th>Action</th></tr></thead><tbody>';
			foreach ( $pending as $r ) {
				echo '<tr><td><strong>' . esc_html( $r['domain'] ) . '</strong></td><td>' . esc_html( $r['legal_name'] ) . '<br>' . esc_html( $r['email'] ) . ' (' . esc_html( $r['country'] ) . ')' . ( 'active' !== ( $r['seller_status'] ?? 'active' ) ? '<br><strong style="color:#b32d2e">Seller ' . esc_html( str_replace( '_', ' ', $r['seller_status'] ) ) . '</strong>' : '' ) . '</td><td>' . ( 'make_offer' === $r['option_type'] ? 'Make Offer' : 'Buy Now' ) . '</td><td>' . ( (int) $r['price'] ? '$' . esc_html( number_format( (int) $r['price'] ) ) : '&mdash;' ) . '</td><td>' . ( (int) $r['min_offer'] ? '$' . esc_html( number_format( (int) $r['min_offer'] ) ) : '&mdash;' ) . '</td>'
					. '<td>' . esc_html( strtoupper( $r['verify_method'] ) . ' ' . $r['verified_at'] ) . '</td><td>' . ( (int) $r['price'] ? '$' . esc_html( number_format( db_seller_fee( (int) $r['price'], ! empty( $r['exclusive'] ) ) ) ) : '&mdash;' ) . '</td><td>'
					. $f( 'approve', $r['id'], 'Approve and publish', '', 'button button-primary' )
					. $f( 'reject', $r['id'], 'Reject', '<input type="text" name="note" placeholder="Reason sent to the seller" style="width:220px"> ' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sellers = (array) $wpdb->get_results( "SELECT s.*, (SELECT COUNT(*) FROM {$l} l WHERE l.seller_id = s.id AND l.review_status = 'approved') AS live, (SELECT COUNT(*) FROM {$l} l WHERE l.seller_id = s.id AND l.review_status IN ('draft','pending')) AS open FROM {$s} s ORDER BY s.id DESC LIMIT 200", ARRAY_A );
		echo '<h2 id="dbsl-sellers">Sellers (' . count( $sellers ) . ')</h2>';
		if ( $sellers ) {
			echo '<table class="widefat striped"><thead><tr><th>Seller</th><th>Live</th><th>Open</th><th>Trust</th><th>Status</th><th>Strikes (window)</th><th>Reactivation request</th><th>Actions</th></tr></thead><tbody>';
			$labels = array( 'active' => 'Active', 'paused' => 'Paused by seller', 'suspended' => 'Offline (by us)', 'suspended_unresp' => 'Offline: not responding', 'closed' => 'Closed' );
			foreach ( $sellers as $r ) {
				$win  = function_exists( 'db_seller_strikes_in_window' ) ? db_seller_strikes_in_window( (int) $r['id'] ) : 0;
				$acts = ( 'trusted' === $r['trust'] ? $f( 'untrust', $r['id'], 'Require review' ) : $f( 'trust', $r['id'], 'Trust' ) );
				if ( 'active' === $r['status'] ) {
					$acts .= $f( 'suspend', $r['id'], 'Take offline', '<input type="text" name="note" placeholder="Reason sent to the seller" style="width:180px"> ' );
				} elseif ( in_array( $r['status'], array( 'suspended', 'suspended_unresp' ), true ) ) {
					$acts .= $f( 'reactivate', $r['id'], 'Reactivate', '', 'button button-primary' ) . $f( 'reactivate', $r['id'], 'Reactivate and reset strikes', '<input type="hidden" name="reset" value="1">' );
				}
				if ( $win ) {
					$acts .= $f( 'reset_strikes', $r['id'], 'Reset strikes' );
				}
				echo '<tr><td>' . esc_html( $r['legal_name'] ) . ( '' !== $r['company'] ? ' (' . esc_html( $r['company'] ) . ')' : '' ) . '<br>' . esc_html( $r['email'] ) . ' &middot; ' . esc_html( $r['country'] ) . '</td>'
					. '<td>' . (int) $r['live'] . '</td><td>' . (int) $r['open'] . '</td><td>' . esc_html( 'flagged' === $r['trust'] ? 'Flagged: matches an offline account' : $r['trust'] ) . '</td>'
					. '<td><strong>' . esc_html( $labels[ $r['status'] ] ?? $r['status'] ) . '</strong>' . ( '' !== (string) ( $r['status_note'] ?? '' ) ? '<br><small>' . esc_html( $r['status_note'] ) . '</small>' : '' ) . '</td>'
					. '<td>' . (int) $win . ' (' . (int) ( $r['strikes'] ?? 0 ) . ' total)</td>'
					. '<td>' . ( ! empty( $r['reactivate_at'] ) ? '<small>' . esc_html( $r['reactivate_at'] ) . '</small><br>' . esc_html( mb_substr( (string) $r['reactivate_note'], 0, 300 ) ) : '&mdash;' ) . '</td>'
					. '<td>' . $acts . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
			}
			echo '</tbody></table>';
		}
		if ( function_exists( 'db_seller_rule' ) ) {
			$rules = array(
				'remind_days'       => array( 'Reminder days for an unanswered offer', 'comma list, e.g. 1,3,5' ),
				'strike_days'       => array( 'Strike after (days unanswered)', 'the buyer is told and the broker takes over' ),
				'suspend_strikes'   => array( 'Strikes that take an account offline', '' ),
				'strike_window'     => array( 'Strike window (days)', '' ),
				'suspend_idle_days' => array( 'Or one offer ignored for (days)', '' ),
				'transfer_days'     => array( 'Days to start a transfer after a sale', '' ),
				'expiry_warn_days'  => array( 'Warn before a name expires (days)', '' ),
				'claims_per_day'    => array( 'Ownership claims per account per day', '' ),
			);
			echo '<h2 id="dbsl-rules">Seller rules</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'db_seller_rules' );
			echo '<input type="hidden" name="action" value="db_seller_rules"><table class="form-table" role="presentation"><tbody>';
			foreach ( $rules as $k => $lab ) {
				$v = db_seller_rule( $k );
				$v = is_array( $v ) ? implode( ',', $v ) : (string) $v;
				echo '<tr><th scope="row"><label for="dbsr-' . esc_attr( $k ) . '">' . esc_html( $lab[0] ) . '</label></th><td><input type="text" id="dbsr-' . esc_attr( $k ) . '" name="rules[' . esc_attr( $k ) . ']" value="' . esc_attr( $v ) . '" class="regular-text">' . ( $lab[1] ? '<p class="description">' . esc_html( $lab[1] ) . '</p>' : '' ) . '</td></tr>';
			}
			echo '</tbody></table><p class="description">Ownership is re-checked every ' . (int) DB_SELLER_RECHECK_DAYS . ' days with ' . (int) DB_SELLER_RECHECK_GRACE_DAYS . ' days to fix a missing record.</p><p><button class="button button-primary">Save rules</button></p></form>';
		}
		echo '</div>';
	}
}

/* ---------------------------------------------------------- public page */

add_filter( 'db_dd_html', function ( $html, $post ) {
	if ( ! $post instanceof WP_Post || ! get_post_meta( $post->ID, 'db_seller_id', true ) ) {
		return $html;
	}
	$desc = (string) get_post_meta( $post->ID, 'db_seller_desc', true );
	$excl = '1' === (string) get_post_meta( $post->ID, 'db_exclusive', true );
	if ( '' === $desc && ! $excl ) {
		return $html;
	}
	$box  = '<section class="db-seller-note" aria-label="About this name" style="max-width:1100px;margin:24px auto 0;padding:20px 22px;border:1px solid #dfe5e1;border-radius:20px;background:#fff">';
	$box .= $excl ? '<p style="margin:0 0 8px"><span style="display:inline-block;padding:5px 12px;border-radius:999px;background:#0a1628;color:#b5e61d;font-size:12px;font-weight:800;letter-spacing:.1em;text-transform:uppercase">Exclusive to Domain Brothers</span></p>' : '';
	$box .= '' !== $desc ? '<h2 style="margin:0 0 6px;font-size:20px">From the owner</h2>' . wpautop( esc_html( $desc ) ) : '';
	return $box . '</section>' . $html;
}, 20, 2 );
