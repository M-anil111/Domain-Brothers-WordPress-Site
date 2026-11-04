<?php
/**
 * The signed-in account: alerts for followed domains, the buyer's own offers,
 * and a Discover tab (recently listed and hot domains).
 *
 * A visitor signs in with an emailed code (see db-wishlist-block.php) and
 * lands on /my-wishlist/, which now has four tabs:
 *
 *   Wishlist   the domains they saved (the people "following" a domain)
 *   Alerts     what happened to those domains, newest first, with an email switch
 *   My offers  every offer they made, its status, a link to the negotiation,
 *              and a form to make a new offer
 *   Discover   recently listed domains and hot domains
 *
 * Alerts. Everyone who saved a domain is told, once per event, when:
 *   - its price changes, is set, or the domain becomes "open to offers";
 *   - it is sold, taken off the market, or put back on the market;
 *   - someone makes a new offer on it.
 * The new-offer alert is deliberately plain: it never gives the amount or
 * who made the offer, and the person who made it is not told about their own.
 * Alerts are stored first (shown on the Alerts tab straight away), then
 * emailed together in one message per person (wp-cron, about every 15
 * minutes). Every email has a one-click stop link, signed so it only works
 * for that address; the same switch is on the Alerts tab.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_ACCT_TABLE_VERSION' ) ) {
	define( 'DB_ACCT_TABLE_VERSION', '1' );
}
if ( ! defined( 'DB_ACCT_MAX_NOTES' ) ) {
	define( 'DB_ACCT_MAX_NOTES', 60 );
}

/* ───────────────────────────── 1. Tables and schedule ─────────────────── */

add_action( 'init', function () {
	global $wpdb;
	if ( get_option( 'db_acct_table_version', '' ) !== DB_ACCT_TABLE_VERSION ) {
		$cs = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$wpdb->prefix}db_wl_notes (
			id         INT           NOT NULL AUTO_INCREMENT,
			email      VARCHAR(190)  NOT NULL,
			post_id    INT           NOT NULL,
			kind       VARCHAR(12)   NOT NULL,
			message    VARCHAR(255)  NOT NULL,
			created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
			read_at    DATETIME      NULL,
			emailed    TINYINT       NOT NULL DEFAULT 0,
			tries      TINYINT       NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY idx_email (email, id),
			KEY idx_pending (emailed, id),
			KEY idx_post (post_id)
		) {$cs};" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}db_wl_prefs (
			email        VARCHAR(190) NOT NULL,
			email_alerts TINYINT      NOT NULL DEFAULT 1,
			PRIMARY KEY (email)
		) {$cs};" );
		update_option( 'db_acct_table_version', DB_ACCT_TABLE_VERSION );
	}
	if ( ! wp_next_scheduled( 'db_acct_digest' ) ) {
		wp_schedule_event( time() + 300, 'db_quarter_hour', 'db_acct_digest' );
	}
} );

add_filter( 'cron_schedules', function ( $s ) {
	$s['db_quarter_hour'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => 'Every 15 minutes' );
	return $s;
} );

// A deleted domain takes its alerts with it.
add_action( 'deleted_post', function ( $post_id ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $wpdb->prefix . 'db_wl_notes', array( 'post_id' => (int) $post_id ), array( '%d' ) );
} );

// Old alerts are not kept for ever: 120 days.
add_action( 'db_wl_purge', function () {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}db_wl_notes WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - 120 * DAY_IN_SECONDS ) ) );
} );

/* ───────────────────────────── 2. Preferences ─────────────────────────── */

if ( ! function_exists( 'db_acct_emails_on' ) ) {
	function db_acct_emails_on( $email ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT email_alerts FROM {$wpdb->prefix}db_wl_prefs WHERE email = %s", $email ) );
		return null === $v || (int) $v === 1;
	}
}

if ( ! function_exists( 'db_acct_set_emails' ) ) {
	function db_acct_set_emails( $email, $on ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace( $wpdb->prefix . 'db_wl_prefs', array( 'email' => $email, 'email_alerts' => $on ? 1 : 0 ), array( '%s', '%d' ) );
	}
}

if ( ! function_exists( 'db_acct_stop_sig' ) ) {
	function db_acct_stop_sig( $email ) {
		return hash_hmac( 'sha256', 'alerts-off|' . $email, wp_salt( 'auth' ) );
	}
}

if ( ! function_exists( 'db_acct_stop_url' ) ) {
	/** One-click "stop these emails" link for $email; it works only for that address. */
	function db_acct_stop_url( $email ) {
		return add_query_arg( array( 'alerts' => 'off', 'e' => rawurlencode( $email ), 's' => db_acct_stop_sig( $email ) ), home_url( '/my-wishlist/' ) );
	}
}

/* ───────────────────────────── 3. Creating alerts ─────────────────────── */

if ( ! function_exists( 'db_acct_name' ) ) {
	function db_acct_name( $post ) {
		return strtolower( wp_strip_all_tags( get_the_title( $post ) ) );
	}
}

if ( ! function_exists( 'db_acct_notify' ) ) {
	/**
	 * Tells everyone following $post_id. The same alert is never stored twice in
	 * a minute (a save that touches two fields still gives one alert).
	 *
	 * @return int how many people were told
	 */
	function db_acct_notify( $post_id, $kind, $message, $exclude_email = '' ) {
		global $wpdb;
		$post = get_post( (int) $post_id );
		if ( ! $post || 'domain' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return 0;
		}
		$items = $wpdb->prefix . 'db_wl_items';
		$notes = $wpdb->prefix . 'db_wl_notes';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$emails = (array) $wpdb->get_col( $wpdb->prepare( "SELECT email FROM {$items} WHERE post_id = %d", (int) $post_id ) );
		$told   = 0;
		$since  = gmdate( 'Y-m-d H:i:s', time() - 60 );
		$msg    = mb_substr( wp_strip_all_tags( (string) $message ), 0, 250 );
		foreach ( $emails as $e ) {
			if ( '' !== $exclude_email && 0 === strcasecmp( $e, $exclude_email ) ) {
				continue;
			}
			$dupe = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notes} WHERE email = %s AND post_id = %d AND kind = %s AND message = %s AND created_at > %s", $e, (int) $post_id, $kind, $msg, $since ) );
			if ( $dupe ) {
				continue;
			}
			$wpdb->insert( $notes, array(
				'email'      => $e,
				'post_id'    => (int) $post_id,
				'kind'       => $kind,
				'message'    => $msg,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			) );
			$told++;
		}
		// phpcs:enable
		if ( $told && ! wp_next_scheduled( 'db_acct_digest_soon' ) ) {
			wp_schedule_single_event( time() + 120, 'db_acct_digest_soon' );
		}
		return $told;
	}
}

if ( ! function_exists( 'db_acct_money' ) ) {
	function db_acct_money( $n ) {
		return '$' . number_format( (float) $n, 0 );
	}
}

/**
 * Price and status changes, caught as the meta is written (the old value is
 * still readable at this point). Runs for both "update" and "add".
 */
if ( ! function_exists( 'db_acct_meta_changed' ) ) {
	function db_acct_meta_changed( $post_id, $key, $new, $old ) {
		if ( ! in_array( $key, array( 'domain_price', 'db_listing_state', 'domain_status' ), true ) ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post || 'domain' !== $post->post_type || 'publish' !== $post->post_status ) {
			return;
		}
		$name = db_acct_name( $post );
		if ( 'domain_price' === $key ) {
			$o = (float) preg_replace( '/[^0-9.]/', '', (string) $old );
			$n = (float) preg_replace( '/[^0-9.]/', '', (string) $new );
			if ( abs( $o - $n ) < 0.005 ) {
				return;
			}
			if ( $n <= 0 ) {
				db_acct_notify( $post_id, 'price', $name . ' is now open to offers (it was ' . db_acct_money( $o ) . ').' );
			} elseif ( $o <= 0 ) {
				db_acct_notify( $post_id, 'price', $name . ' now has a price: ' . db_acct_money( $n ) . '.' );
			} else {
				db_acct_notify( $post_id, 'price', 'The price of ' . $name . ' ' . ( $n < $o ? 'dropped' : 'changed' ) . ' from ' . db_acct_money( $o ) . ' to ' . db_acct_money( $n ) . '.' );
			}
			return;
		}
		$was_off = in_array( (string) $old, array( 'sold', 'not_for_sale' ), true ) || 'sold' === strtolower( (string) $old );
		$is_sold = 'sold' === strtolower( trim( (string) $new ) );
		if ( $is_sold && ! $was_off ) {
			db_acct_notify( $post_id, 'sold', $name . ' has been sold.' );
		} elseif ( 'db_listing_state' === $key && 'not_for_sale' === $new && 'not_for_sale' !== (string) $old ) {
			db_acct_notify( $post_id, 'status', $name . ' is no longer for sale.' );
		} elseif ( $was_off && ! $is_sold && 'not_for_sale' !== $new ) {
			db_acct_notify( $post_id, 'status', $name . ' is back on the market.' );
		}
	}
}

add_action( 'update_post_meta', function ( $meta_id, $post_id, $key, $value ) {
	db_acct_meta_changed( $post_id, $key, $value, get_post_meta( (int) $post_id, $key, true ) );
}, 10, 4 );

add_action( 'add_post_meta', function ( $post_id, $key, $value ) {
	db_acct_meta_changed( $post_id, $key, $value, '' );
}, 10, 3 );

// A new offer (never the amount, never who).
add_action( 'db_crm_lead_created', function ( $lead_id, $data ) {
	if ( empty( $data['domain'] ) || empty( $data['offer_amount'] ) || ! function_exists( 'db_listing_post_for_domain' ) ) {
		return;
	}
	$post = db_listing_post_for_domain( (string) $data['domain'] );
	if ( $post ) {
		db_acct_notify( $post->ID, 'offer', 'A new offer was made on ' . db_acct_name( $post ) . '.', (string) ( $data['email'] ?? '' ) );
	}
}, 20, 2 );

/* ───────────────────────────── 4. Emailing alerts ─────────────────────── */

if ( ! function_exists( 'db_acct_send_pending' ) ) {
	/** One email per person for everything not yet emailed. Returns the number of emails sent. */
	function db_acct_send_pending() {
		global $wpdb;
		$notes = $wpdb->prefix . 'db_wl_notes';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$notes} WHERE emailed = 0 ORDER BY id ASC LIMIT 500" );
		$by   = array();
		foreach ( $rows as $r ) {
			$by[ $r->email ][] = $r;
		}
		$sent = 0;
		foreach ( $by as $email => $list ) {
			$ids = implode( ',', array_map( 'intval', wp_list_pluck( $list, 'id' ) ) );
			if ( ! db_acct_emails_on( $email ) ) {
				$wpdb->query( "UPDATE {$notes} SET emailed = 2 WHERE id IN ({$ids})" );
				continue;
			}
			$lines = array();
			foreach ( $list as $n ) {
				$p       = get_post( (int) $n->post_id );
				$lines[] = '- ' . $n->message . ( $p ? ' ' . get_permalink( $p ) : '' );
			}
			$count   = count( $list );
			$subject = 1 === $count ? 'Domain Brothers: ' . $list[0]->message : 'Domain Brothers: ' . $count . ' updates on domains you follow';
			$body    = "News on domains you saved to your wishlist:\n\n" . implode( "\n", $lines ) . "\n\nSee all your alerts: " . add_query_arg( 'tab', 'alerts', home_url( '/my-wishlist/' ) ) . "\n\nYou get this email because you saved these domains at Domain Brothers.\nStop these emails: " . db_acct_stop_url( $email ) . "\n\nDomain Brothers\n" . home_url( '/' );
			if ( function_exists( 'db_wl_is_staging' ) && db_wl_is_staging() ) {
				set_transient( 'db_acct_dbg_' . md5( $email ), $subject . "\n" . $body, HOUR_IN_SECONDS ); // read only by the admin-only staging test route
			}
			$ok = (bool) wp_mail( $email, $subject, $body );
			if ( $ok || ( function_exists( 'db_wl_is_staging' ) && db_wl_is_staging() ) ) {
				$wpdb->query( "UPDATE {$notes} SET emailed = 1 WHERE id IN ({$ids})" );
				$sent++;
			} else {
				// Try again next time, three times at most.
				$wpdb->query( "UPDATE {$notes} SET tries = tries + 1, emailed = IF(tries >= 3, 2, 0) WHERE id IN ({$ids})" );
			}
		}
		// phpcs:enable
		return $sent;
	}
}

add_action( 'db_acct_digest', 'db_acct_send_pending' );
add_action( 'db_acct_digest_soon', 'db_acct_send_pending' );

/* ───────────────────────────── 5. Account data ────────────────────────── */

if ( ! function_exists( 'db_acct_notes' ) ) {
	function db_acct_notes( $email ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}db_wl_notes WHERE email = %s ORDER BY id DESC LIMIT %d", $email, DB_ACCT_MAX_NOTES ) );
	}
}

if ( ! function_exists( 'db_acct_unread' ) ) {
	function db_acct_unread( $email ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}db_wl_notes WHERE email = %s AND read_at IS NULL", $email ) );
	}
}

if ( ! function_exists( 'db_acct_deals' ) ) {
	/** The offers this person made (newest activity first). */
	function db_acct_deals( $email ) {
		global $wpdb;
		if ( ! function_exists( 'db_deal_table' ) ) {
			return array();
		}
		$t = db_deal_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE LOWER(buyer_email) = %s ORDER BY updated_at DESC, id DESC LIMIT 100", strtolower( $email ) ) );
	}
}

if ( ! function_exists( 'db_acct_sellable' ) ) {
	/** Published domains that can still be bought, with state. */
	function db_acct_sellable( $post ) {
		return $post instanceof WP_Post && 'domain' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password
			&& ( ! function_exists( 'db_listing_state' ) || in_array( db_listing_state( $post ), array( 'standard', 'offer' ), true ) );
	}
}

if ( ! function_exists( 'db_acct_recent' ) ) {
	/** @return WP_Post[] */
	function db_acct_recent( $limit = 12 ) {
		$q = new WP_Query( array(
			'post_type'              => 'domain',
			'post_status'            => 'publish',
			'posts_per_page'         => 40,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'has_password'           => false,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		) );
		$out = array();
		foreach ( $q->posts as $p ) {
			if ( db_acct_sellable( $p ) ) {
				$out[] = $p;
			}
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'db_acct_hot' ) ) {
	/**
	 * Hot domains: views in the last 7 days, plus 5 for each person watching and
	 * 10 for each offer received. Real numbers only; a domain with none scores 0
	 * and is not hot.
	 *
	 * @return array<int,array{post:WP_Post,views:int,watching:int,offers:int,score:int}>
	 */
	function db_acct_hot( $limit = 12 ) {
		global $wpdb;
		$cached = get_transient( 'db_acct_hot_v1' );
		if ( is_array( $cached ) ) {
			$ids = $cached;
		} else {
			$dt = $wpdb->prefix . 'db_hits';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT post_id, SUM(hits) AS h FROM {$dt} WHERE day >= %s GROUP BY post_id ORDER BY h DESC LIMIT 60", gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ) ) );
			$ids  = array();
			foreach ( $rows as $r ) {
				$ids[ (int) $r->post_id ] = (int) $r->h;
			}
			// Domains people are watching are candidates even when views are quiet.
			$it = $wpdb->prefix . 'db_wl_items';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_col( "SELECT post_id FROM {$it} GROUP BY post_id ORDER BY COUNT(*) DESC LIMIT 30" ) as $pid ) {
				$ids[ (int) $pid ] = $ids[ (int) $pid ] ?? 0;
			}
			set_transient( 'db_acct_hot_v1', $ids, 10 * MINUTE_IN_SECONDS );
		}
		$out = array();
		foreach ( $ids as $pid => $views ) {
			$p = get_post( $pid );
			if ( ! db_acct_sellable( $p ) ) {
				continue;
			}
			$w     = function_exists( 'db_wl_count' ) ? db_wl_count( $pid ) : 0;
			$o     = function_exists( 'db_stats_offer_count' ) ? db_stats_offer_count( wp_strip_all_tags( $p->post_title ) ) : 0;
			$score = (int) $views + 5 * $w + 10 * $o;
			if ( $score > 0 ) {
				$out[] = array( 'post' => $p, 'views' => (int) $views, 'watching' => $w, 'offers' => $o, 'score' => $score );
			}
		}
		usort( $out, static function ( $a, $b ) {
			return $b['score'] <=> $a['score'];
		} );
		return array_slice( $out, 0, $limit );
	}
}

/* ───────────────────────────── 6. REST ────────────────────────────────── */

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
	// Every account write needs the session cookie and a request from this site.
	$guard = static function () use ( $send ) {
		$email = db_wl_email();
		if ( '' === $email ) {
			return array( null, $send( array( 'ok' => false, 'signedIn' => false, 'message' => 'Sign in first.' ), 401 ) );
		}
		if ( ! db_wl_same_origin() ) {
			return array( null, $send( array( 'ok' => false, 'message' => 'Please use the website.' ), 403 ) );
		}
		return array( $email, null );
	};

	register_rest_route( 'db/v1', '/account/prefs', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) use ( $send, $json, $guard ) {
			list( $email, $err ) = $guard();
			if ( $err ) {
				return $err;
			}
			db_acct_set_emails( $email, ! empty( $json( $req )['emails'] ) );
			return $send( array( 'ok' => true, 'emails' => db_acct_emails_on( $email ) ) );
		},
	) );

	register_rest_route( 'db/v1', '/account/read', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function () use ( $send, $guard ) {
			list( $email, $err ) = $guard();
			if ( $err ) {
				return $err;
			}
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}db_wl_notes SET read_at = %s WHERE email = %s AND read_at IS NULL", gmdate( 'Y-m-d H:i:s' ), $email ) );
			return $send( array( 'ok' => true ) );
		},
	) );

	register_rest_route( 'db/v1', '/account/offer', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) use ( $send, $json, $guard ) {
			list( $email, $err ) = $guard();
			if ( $err ) {
				return $err;
			}
			if ( ! db_wl_allow( 'acct-offer:' . $email, 10, HOUR_IN_SECONDS ) || ! db_wl_allow( 'acct-offer-ip:' . db_wl_ip(), 30, HOUR_IN_SECONDS ) ) {
				return $send( array( 'ok' => false, 'message' => 'You have made a lot of offers in the last hour. Please try again later.' ), 429 );
			}
			$d      = $json( $req );
			$domain = strtolower( trim( preg_replace( '/^https?:\/\/(www\.)?/i', '', (string) ( $d['domain'] ?? '' ) ), " \t\n\r\0\x0B/" ) );
			$amount = (float) preg_replace( '/[^0-9.]/', '', (string) ( $d['amount'] ?? '' ) );
			$msg    = mb_substr( sanitize_textarea_field( (string) ( $d['message'] ?? '' ) ), 0, 1000 );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*(\.[a-z0-9-]+)+$/', $domain ) ) {
				return $send( array( 'ok' => false, 'message' => 'Enter the domain name, for example example.com.' ), 400 );
			}
			if ( $amount < 1 || $amount > 100000000 ) {
				return $send( array( 'ok' => false, 'message' => 'Enter your offer in US dollars, for example 1500.' ), 400 );
			}
			$post = function_exists( 'db_listing_post_for_domain' ) ? db_listing_post_for_domain( $domain ) : null;
			if ( ! $post || ! db_acct_sellable( $post ) ) {
				return $send( array( 'ok' => false, 'message' => 'That domain is not available for offers.' ), 404 );
			}
			$lead = db_crm_create_lead( array(
				'domain'       => $domain,
				'name'         => ucfirst( (string) strstr( $email, '@', true ) ),
				'email'        => $email,
				'offer_amount' => $amount,
				'message'      => $msg,
				'source'       => 'offer_form',
			) );
			if ( is_wp_error( $lead ) ) {
				return $send( array( 'ok' => false, 'message' => $lead->get_error_message() ), 400 );
			}
			$deal = function_exists( 'db_deal_find_active' ) ? db_deal_find_active( $email, $domain ) : null;
			return $send( array(
				'ok'      => true,
				'message' => 'Offer sent. We will reply by email.',
				'deal'    => $deal && function_exists( 'db_deal_url' ) ? db_deal_url( $deal ) : '',
			) );
		},
	) );

	// Staging only, administrators only: runs the email batch and shows the email, because staging cannot send mail.
	register_rest_route( 'db/v1', '/account/test-mail', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return function_exists( 'db_wl_is_staging' ) && db_wl_is_staging() && current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $req ) use ( $send ) {
			$email = db_wl_clean_email( $req->get_param( 'email' ) );
			if ( '' === $email ) {
				return $send( array( 'mail' => '' ) );
			}
			$sent = $req->get_param( 'run' ) ? db_acct_send_pending() : 0;
			return $send( array( 'sent' => $sent, 'mail' => (string) get_transient( 'db_acct_dbg_' . md5( $email ) ) ) );
		},
	) );
} );

/* ───────────────────────────── 7. The account page ────────────────────── */

if ( ! function_exists( 'db_acct_tabs' ) ) {
	function db_acct_tabs() {
		return array(
			'saved'    => 'Wishlist',
			'alerts'   => 'Alerts',
			'offers'   => 'My offers',
			'discover' => 'Discover',
		);
	}
}

if ( ! function_exists( 'db_acct_price_label' ) ) {
	function db_acct_price_label( $post ) {
		$state = function_exists( 'db_listing_state' ) ? db_listing_state( $post ) : 'offer';
		$price = function_exists( 'db_dd_price' ) ? db_dd_price( $post->ID ) : 0.0;
		return 'standard' === $state && $price > 0 ? db_acct_money( $price ) : 'Open to offers';
	}
}

if ( ! function_exists( 'db_acct_card_html' ) ) {
	/** A domain card for Discover. */
	function db_acct_card_html( $post, $saved_ids, $chips = array(), $badge = '' ) {
		$name = db_acct_name( $post );
		$on   = in_array( (int) $post->ID, $saved_ids, true );
		$h    = '<li class="db-ac-card"><div class="db-ac-card-top">';
		if ( '' !== $badge ) {
			$h .= '<span class="db-ac-badge db-ac-badge--' . esc_attr( sanitize_html_class( strtolower( $badge ) ) ) . '">' . esc_html( $badge ) . '</span>';
		}
		$h .= '<a class="db-ac-name" href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( $name ) . '</a><span class="db-ac-price">' . esc_html( db_acct_price_label( $post ) ) . '</span></div>';
		if ( $chips ) {
			$h .= '<p class="db-ac-chips">' . implode( '', array_map( static function ( $c ) {
				return '<span>' . esc_html( $c ) . '</span>';
			}, $chips ) ) . '</p>';
		}
		$h .= '<div class="db-ac-actions"><a class="db-ac-btn" href="' . esc_url( get_permalink( $post ) ) . '">View</a>'
			. '<a class="db-ac-btn db-ac-btn--ghost" href="' . esc_url( add_query_arg( array( 'tab' => 'offers', 'domain' => $name ), home_url( '/my-wishlist/' ) ) . '#db-ac-offer' ) . '">Make an offer</a>'
			. '<button type="button" class="db-ac-save' . ( $on ? ' is-on' : '' ) . '" data-ac-save="' . (int) $post->ID . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '">' . ( $on ? 'Saved' : 'Save' ) . '</button></div></li>';
		return $h;
	}
}

if ( ! function_exists( 'db_acct_time_ago' ) ) {
	function db_acct_time_ago( $mysql_utc ) {
		$ts = strtotime( $mysql_utc . ' UTC' );
		return $ts ? human_time_diff( $ts, time() ) . ' ago' : '';
	}
}

if ( ! function_exists( 'db_acct_render' ) ) {
	/** The signed-in page body: heading, tabs and the chosen tab. Strings only. */
	function db_acct_render( $email, $saved_html, $saved_count ) {
		$tabs = db_acct_tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'saved'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'saved';
		}
		$unread = db_acct_unread( $email );
		$deals  = db_acct_deals( $email );
		$h      = '<h1 class="page-title">My account</h1>';
		$h     .= '<p class="db-wl-who">Signed in as <strong>' . esc_html( $email ) . '</strong> <button type="button" class="db-wl-link" id="db-wl-signout">Sign out</button></p>';
		$h     .= '<nav class="db-ac-tabs" aria-label="Account sections">';
		foreach ( $tabs as $key => $label ) {
			$extra = '';
			if ( 'saved' === $key ) {
				$extra = ' <span class="db-ac-n">' . (int) $saved_count . '</span>';
			} elseif ( 'alerts' === $key && $unread ) {
				$extra = ' <span class="db-ac-n db-ac-n--new" aria-label="' . (int) $unread . ' unread">' . (int) $unread . '</span>';
			} elseif ( 'offers' === $key && $deals ) {
				$extra = ' <span class="db-ac-n">' . count( $deals ) . '</span>';
			}
			$url = 'saved' === $key ? home_url( '/my-wishlist/' ) : add_query_arg( 'tab', $key, home_url( '/my-wishlist/' ) );
			$h  .= '<a href="' . esc_url( $url ) . '" class="db-ac-tab' . ( $tab === $key ? ' is-on' : '' ) . '"' . ( $tab === $key ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . $extra . '</a>';
		}
		$h .= '</nav>';

		if ( 'saved' === $tab ) {
			$h .= '<h2 class="db-ac-h">Saved domains <span class="db-ac-sub">(<span id="db-wl-count">' . (int) $saved_count . '</span>)</span></h2>';
			$h .= '<p class="db-ac-lead">You are following these. We tell you in Alerts, and by email, when a price changes, a domain sells or someone makes an offer.</p>';
			if ( '' === $saved_html ) {
				$h .= '<div class="db-wl-card"><h2>Nothing saved yet</h2><p>Open any domain and choose <strong>Add to wishlist</strong>, or pick from <a href="' . esc_url( add_query_arg( 'tab', 'discover', home_url( '/my-wishlist/' ) ) ) . '">Discover</a>. It will appear here.</p><p><a class="db-wl-primary" href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse domains</a></p></div>';
			} else {
				$h .= '<ul class="db-wl-list">' . $saved_html . '</ul>';
			}
		} elseif ( 'alerts' === $tab ) {
			$on    = db_acct_emails_on( $email );
			$notes = db_acct_notes( $email );
			$h    .= '<h2 class="db-ac-h">Alerts</h2><label class="db-ac-switch"><input type="checkbox" id="db-ac-emails"' . ( $on ? ' checked' : '' ) . '> <span>Email me these alerts</span></label><p class="db-ac-msg" id="db-ac-prefmsg" role="status" aria-live="polite"></p>';
			if ( ! $notes ) {
				$h .= '<div class="db-wl-card"><h2>No alerts yet</h2><p>When a domain on your wishlist changes price, sells or gets a new offer, it shows up here.</p></div>';
			} else {
				$icons = array( 'price' => 'Price', 'sold' => 'Sold', 'offer' => 'Offer', 'status' => 'Status' );
				$h    .= '<ol class="db-ac-feed">';
				foreach ( $notes as $n ) {
					$p  = get_post( (int) $n->post_id );
					$h .= '<li class="db-ac-note db-ac-note--' . esc_attr( sanitize_html_class( $n->kind ) ) . ( null === $n->read_at ? ' is-new' : '' ) . '"><span class="db-ac-kind">' . esc_html( $icons[ $n->kind ] ?? 'Update' ) . '</span><span class="db-ac-text">' . esc_html( $n->message ) . ( $p ? ' <a href="' . esc_url( get_permalink( $p ) ) . '">View</a>' : '' ) . '</span><time datetime="' . esc_attr( gmdate( 'c', (int) strtotime( $n->created_at . ' UTC' ) ) ) . '">' . esc_html( db_acct_time_ago( $n->created_at ) ) . '</time></li>';
				}
				$h .= '</ol>';
			}
		} elseif ( 'offers' === $tab ) {
			$states = function_exists( 'db_deal_states' ) ? db_deal_states() : array();
			$h     .= '<h2 class="db-ac-h">My offers</h2>';
			if ( ! $deals ) {
				$h .= '<div class="db-wl-card"><h2>No offers yet</h2><p>Make an offer below. We reply by email, and the negotiation is kept here.</p></div>';
			} else {
				$h .= '<ul class="db-ac-deals">';
				foreach ( $deals as $d ) {
					$label = $states[ $d->state ] ?? $d->state;
					$tone  = in_array( $d->state, array( 'paid', 'accepted' ), true ) ? 'good' : ( in_array( $d->state, array( 'declined', 'expired' ), true ) ? 'off' : 'wait' );
					$h    .= '<li class="db-ac-deal"><span class="db-ac-name">' . esc_html( strtolower( $d->domain ) ) . '</span><span class="db-ac-amt">' . ( (float) $d->amount > 0 ? esc_html( db_acct_money( $d->amount ) ) : 'No amount yet' ) . '</span><span class="db-ac-state db-ac-state--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span><time datetime="' . esc_attr( gmdate( 'c', (int) strtotime( $d->updated_at . ' UTC' ) ) ) . '">' . esc_html( db_acct_time_ago( $d->updated_at ) ) . '</time><a class="db-ac-btn db-ac-btn--ghost" href="' . esc_url( db_deal_url( $d ) ) . '">Open</a></li>';
				}
				$h .= '</ul>';
			}
			$pre = isset( $_GET['domain'] ) ? strtolower( sanitize_text_field( wp_unslash( $_GET['domain'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$h  .= '<form class="db-ac-form" id="db-ac-offer" novalidate><h3>Make an offer</h3><p class="db-ac-lead">Name your price for any domain that is for sale. We reply by email and keep the conversation in My offers.</p>'
				. '<label>Domain<input type="text" name="domain" list="db-ac-domains" required autocomplete="off" placeholder="example.com" value="' . esc_attr( $pre ) . '"></label>'
				. '<datalist id="db-ac-domains">';
			foreach ( db_wl_ids( $email ) as $pid ) {
				$p = get_post( $pid );
				if ( db_acct_sellable( $p ) ) {
					$h .= '<option value="' . esc_attr( db_acct_name( $p ) ) . '">';
				}
			}
			$h .= '</datalist><label>Your offer (USD)<input type="text" name="amount" inputmode="numeric" required autocomplete="off" placeholder="1500" maxlength="12"></label>'
				. '<label>Message <span class="db-ac-opt">(optional)</span><textarea name="message" rows="3" maxlength="1000" placeholder="Tell us how you plan to use the name."></textarea></label>'
				. '<button type="submit" class="db-wl-primary">Send offer</button><p class="db-ac-msg" id="db-ac-offermsg" role="status" aria-live="polite"></p></form>';
		} else {
			$ids    = db_wl_ids( $email );
			$recent = db_acct_recent( 12 );
			$hot    = db_acct_hot( 12 );
			$h     .= '<h2 class="db-ac-h">Recently listed</h2><p class="db-ac-lead">The newest domains added to the marketplace.</p>';
			if ( ! $recent ) {
				$h .= '<div class="db-wl-card"><p>Nothing new right now.</p></div>';
			} else {
				$h .= '<ul class="db-ac-cards">';
				foreach ( $recent as $p ) {
					$h .= db_acct_card_html( $p, $ids, array( 'Listed ' . human_time_diff( get_post_time( 'U', true, $p ), time() ) . ' ago' ), 'New' );
				}
				$h .= '</ul>';
			}
			$h .= '<h2 class="db-ac-h">Hot domains</h2><p class="db-ac-lead">Most looked at this week, counting views, people watching and offers received.</p>';
			if ( ! $hot ) {
				$h .= '<div class="db-wl-card"><p>No domains are trending yet. Check back soon.</p></div>';
			} else {
				$h .= '<ul class="db-ac-cards">';
				foreach ( $hot as $x ) {
					$chips = array();
					if ( $x['views'] > 0 ) {
						$chips[] = number_format_i18n( $x['views'] ) . ' views this week';
					}
					if ( $x['watching'] > 0 ) {
						$chips[] = $x['watching'] . ' watching';
					}
					if ( $x['offers'] > 0 ) {
						$chips[] = $x['offers'] . ( 1 === $x['offers'] ? ' offer' : ' offers' );
					}
					$h .= db_acct_card_html( $x['post'], $ids, $chips, 'Hot' );
				}
				$h .= '</ul>';
			}
		}
		return $h;
	}
}

if ( ! function_exists( 'db_acct_script' ) ) {
	/** Client script for the tabs (save buttons, email switch, offer form, marking alerts read). */
	function db_acct_script( $tab ) {
		$cfg = array(
			'api'   => esc_url_raw( rest_url( 'db/v1/' ) ),
			'tab'   => $tab,
			'offers' => esc_url_raw( add_query_arg( 'tab', 'offers', home_url( '/my-wishlist/' ) ) ),
		);
		ob_start();
		?>
<script id="db-account-js">
(function () {
	'use strict';
	var C = <?php echo wp_json_encode( $cfg ); ?>;
	function post(path, body) {
		return fetch(C.api + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(body || {}) })
			.then(function (r) { return r.json().then(function (j) { j.__status = r.status; return j; }); });
	}
	function say(id, t, bad) { var m = document.getElementById(id); if (m) { m.textContent = t; m.className = 'db-ac-msg' + (bad ? ' is-bad' : ' is-good'); } }
	function start() {
		if (C.tab === 'alerts') { post('account/read'); }
		var sw = document.getElementById('db-ac-emails');
		if (sw) {
			sw.addEventListener('change', function () {
				post('account/prefs', { emails: sw.checked }).then(function (j) {
					say('db-ac-prefmsg', j.ok ? (j.emails ? 'We will email you these alerts.' : 'Email alerts are off. You will still see them here.') : (j.message || 'Could not save that.'), !j.ok);
				});
			});
		}
		document.querySelectorAll('[data-ac-save]').forEach(function (b) {
			b.addEventListener('click', function () {
				b.disabled = true;
				post('wishlist/toggle', { post: parseInt(b.getAttribute('data-ac-save'), 10) }).then(function (j) {
					b.disabled = false;
					if (j.ok) { var on = j.result === 'added'; b.classList.toggle('is-on', on); b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.textContent = on ? 'Saved' : 'Save'; }
				}).catch(function () { b.disabled = false; });
			});
		});
		var f = document.getElementById('db-ac-offer');
		if (f) {
			f.addEventListener('submit', function (e) {
				e.preventDefault();
				var btn = f.querySelector('button[type=submit]'); btn.disabled = true; say('db-ac-offermsg', 'Sending…');
				post('account/offer', { domain: f.domain.value, amount: f.amount.value, message: f.message.value }).then(function (j) {
					btn.disabled = false;
					if (j.ok) { say('db-ac-offermsg', j.message); setTimeout(function () { window.location.href = C.offers + '&sent=1'; }, 900); }
					else { say('db-ac-offermsg', j.message || 'Could not send that offer.', true); }
				}).catch(function () { btn.disabled = false; say('db-ac-offermsg', 'Could not reach the server. Try again.', true); });
			});
		}
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
</script>
		<?php
		return (string) ob_get_clean();
	}
}

/* One-click stop link in every alert email: works signed in or not, only for the address it was made for. */
if ( ! function_exists( 'db_acct_handle_stop' ) ) {
	/** @return string '' (nothing asked), 'ok' or 'bad' */
	function db_acct_handle_stop() {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( ! isset( $_GET['alerts'], $_GET['e'], $_GET['s'] ) || 'off' !== $_GET['alerts'] ) {
			return '';
		}
		$email = db_wl_clean_email( rawurldecode( (string) wp_unslash( $_GET['e'] ) ) );
		$sig   = (string) wp_unslash( $_GET['s'] );
		// phpcs:enable
		if ( '' === $email || ! hash_equals( db_acct_stop_sig( $email ), $sig ) ) {
			return 'bad';
		}
		db_acct_set_emails( $email, false );
		return 'ok';
	}
}

/* ───────────────────────────── 8. Admin: alerts sent ──────────────────── */

add_action( 'db_wl_admin_after', function () {
	global $wpdb;
	$notes = $wpdb->prefix . 'db_wl_notes';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows  = (array) $wpdb->get_results( "SELECT n.*, p.post_title FROM {$notes} n LEFT JOIN {$wpdb->posts} p ON p.ID = n.post_id ORDER BY n.id DESC LIMIT 50" );
	$queue = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$notes} WHERE emailed = 0" );
	// phpcs:enable
	echo '<h2 style="margin-top:28px">Alerts to followers</h2><p>Price changes, sales and new offers on saved domains. Followers see them in their account straight away and get one email (every 15 minutes at most). Waiting to be emailed: <strong>' . (int) $queue . '</strong>.</p>';
	echo '<table class="widefat striped"><thead><tr><th>When</th><th>Domain</th><th>Kind</th><th>Alert</th><th>Sent to</th><th>Email</th></tr></thead><tbody>';
	if ( ! $rows ) {
		echo '<tr><td colspan="6">No alerts yet.</td></tr>';
	}
	$status = array( 0 => 'queued', 1 => 'sent', 2 => 'not sent' );
	foreach ( $rows as $r ) {
		echo '<tr><td>' . esc_html( wp_date( 'M j, g:i a', strtotime( $r->created_at . ' UTC' ) ) ) . '</td><td>' . esc_html( strtolower( (string) $r->post_title ) ) . '</td><td>' . esc_html( $r->kind ) . '</td><td>' . esc_html( $r->message ) . '</td><td>' . esc_html( $r->email ) . '</td><td>' . esc_html( $status[ (int) $r->emailed ] ?? '' ) . '</td></tr>';
	}
	echo '</tbody></table>';
} );
