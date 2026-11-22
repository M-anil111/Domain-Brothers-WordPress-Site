<?php
/**
 * Domain auctions (from the owner's "Auction Changes" brief).
 *
 * - Our own names: start an auction from the domain's edit screen (box "Auction") or
 *   Tools > Auctions. Seller names: the seller starts one from the Auctions tab of /seller/.
 * - Buyers bid on the domain page with a signed-in account. They enter their maximum and
 *   the site bids for them (db-auction-lib.php has the rules). Bidders are shown as
 *   "Bidder 1", "Bidder 2"; nobody sees names or emails.
 * - A bid in the last minutes pushes the end back. Buy It Now ends the auction at once.
 * - At the end the highest bidder wins when the reserve is met: an accepted offer (deal)
 *   is created at the winning price and the winner pays through the normal checkout
 *   (/buy-now/?offer=...). Payment, payouts to sellers and transfer emails then work as
 *   for any accepted offer. A winner who does not pay in time loses the name; the next
 *   highest bidder is offered it (second chance).
 * - Below the reserve, the owner may still accept the highest bid for a few days.
 * - While an auction is live, the name cannot be bought at its fixed price.
 *
 * On only when option db_auctions_on is '1', or on staging while the option is unset.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/db-auction-lib.php';

if ( ! defined( 'DB_AUC_DB_VERSION' ) ) {
	define( 'DB_AUC_DB_VERSION', '1' );
}

/* ------------------------------------------------------------ basics */

if ( ! function_exists( 'db_auc_enabled' ) ) {
	function db_auc_enabled() {
		$opt = get_option( 'db_auctions_on', null );
		$on  = null === $opt ? ( function_exists( 'db_is_staging_host' ) && db_is_staging_host() ) : '1' === (string) $opt;
		return (bool) apply_filters( 'db_auctions_enabled', $on );
	}
}

if ( ! function_exists( 'db_auc_rules' ) ) {
	function db_auc_rules() {
		$saved = get_option( 'db_auction_rules', array() );
		return array_merge( db_auc_rules_default(), is_array( $saved ) ? $saved : array() );
	}
}

if ( ! function_exists( 'db_auc_t' ) ) {
	function db_auc_t( $which = 'auctions' ) {
		global $wpdb;
		return $wpdb->prefix . ( 'bids' === $which ? 'db_auction_bids' : ( 'requests' === $which ? 'db_auction_requests' : 'db_auctions' ) );
	}
}

add_action( 'init', function () {
	if ( DB_AUC_DB_VERSION === get_option( 'db_auction_db_ver' ) ) {
		return;
	}
	global $wpdb;
	$c = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . db_auc_t() . " (
		id            INT           NOT NULL AUTO_INCREMENT,
		post_id       BIGINT        NOT NULL DEFAULT 0,
		domain        VARCHAR(255)  NOT NULL DEFAULT '',
		seller_id     INT           NOT NULL DEFAULT 0,
		start_price   INT           NOT NULL DEFAULT 0,
		reserve       INT           NOT NULL DEFAULT 0,
		show_reserve  TINYINT       NOT NULL DEFAULT 0,
		bin_price     INT           NOT NULL DEFAULT 0,
		current_price INT           NOT NULL DEFAULT 0,
		leader_email  VARCHAR(255)  NOT NULL DEFAULT '',
		leader_max    INT           NOT NULL DEFAULT 0,
		bids          INT           NOT NULL DEFAULT 0,
		starts_at     DATETIME      NOT NULL,
		ends_at       DATETIME      NOT NULL,
		state         VARCHAR(20)   NOT NULL DEFAULT 'live',
		deal_id       INT           NOT NULL DEFAULT 0,
		offered       TEXT          NULL,
		note          VARCHAR(255)  NOT NULL DEFAULT '',
		created_by    VARCHAR(255)  NOT NULL DEFAULT '',
		created_at    DATETIME      NOT NULL,
		ended_at      DATETIME      NULL,
		PRIMARY KEY  (id),
		KEY idx_post (post_id),
		KEY idx_state_end (state, ends_at)
	) {$c};" );
	dbDelta( 'CREATE TABLE ' . db_auc_t( 'bids' ) . " (
		id          INT           NOT NULL AUTO_INCREMENT,
		auction_id  INT           NOT NULL,
		email       VARCHAR(255)  NOT NULL,
		max_amount  INT           NOT NULL DEFAULT 0,
		price_after INT           NOT NULL DEFAULT 0,
		kind        VARCHAR(10)   NOT NULL DEFAULT 'bid',
		ip          VARCHAR(45)   NOT NULL DEFAULT '',
		created_at  DATETIME      NOT NULL,
		PRIMARY KEY  (id),
		KEY idx_auction (auction_id),
		KEY idx_email (email(64))
	) {$c};" );
	dbDelta( 'CREATE TABLE ' . db_auc_t( 'requests' ) . " (
		auction_id  INT           NOT NULL,
		email       VARCHAR(255)  NOT NULL,
		created_at  DATETIME      NOT NULL,
		PRIMARY KEY  (auction_id, email(100))
	) {$c};" );
	update_option( 'db_auction_db_ver', DB_AUC_DB_VERSION );
} );

if ( ! function_exists( 'db_auc_get' ) ) {
	function db_auc_get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . db_auc_t() . ' WHERE id = %d', (int) $id ), ARRAY_A );
		return $r ? $r : null;
	}
}

if ( ! function_exists( 'db_auc_for_post' ) ) {
	/** The newest auction of a domain post that is live or just ended, or null. */
	function db_auc_for_post( $post_id, $live_only = false ) {
		global $wpdb;
		$states = $live_only ? "'live'" : "'live','won','reserve_not_met','sold','ended','unpaid'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . db_auc_t() . " WHERE post_id = %d AND state IN ({$states}) ORDER BY id DESC LIMIT 1", (int) $post_id ), ARRAY_A );
		if ( $r && ! $live_only && 'live' !== $r['state'] && $r['ended_at'] && strtotime( $r['ended_at'] . ' UTC' ) < time() - 30 * DAY_IN_SECONDS ) {
			return null; // an old result no longer belongs on the page
		}
		return $r ? $r : null;
	}
}

if ( ! function_exists( 'db_auc_live_for_domain' ) ) {
	function db_auc_live_for_domain( $domain ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . db_auc_t() . " WHERE domain = %s AND state = 'live' ORDER BY id DESC LIMIT 1", strtolower( trim( (string) $domain ) ) ), ARRAY_A );
		return $r ? $r : null;
	}
}

if ( ! function_exists( 'db_auc_state' ) ) {
	/** The row as the pure rules expect it. */
	function db_auc_state( array $a ) {
		return array(
			'start'      => (int) $a['start_price'],
			'reserve'    => (int) $a['reserve'],
			'bin'        => (int) $a['bin_price'],
			'current'    => (int) $a['current_price'],
			'leader'     => (string) $a['leader_email'],
			'leader_max' => (int) $a['leader_max'],
			'bids'       => (int) $a['bids'],
		);
	}
}

if ( ! function_exists( 'db_auc_ts' ) ) {
	function db_auc_ts( $mysql_utc ) {
		return $mysql_utc ? (int) strtotime( $mysql_utc . ' UTC' ) : 0;
	}
}

if ( ! function_exists( 'db_auc_lock' ) ) {
	/** A database lock per auction, so two bids never overwrite each other. */
	function db_auc_lock( $id, $on = true ) {
		global $wpdb;
		$name = 'db_auc_' . (int) $id;
		if ( $on ) {
			return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 8)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return true;
	}
}

if ( ! function_exists( 'db_auc_owner_email' ) ) {
	/** Who answers for the name: the seller for seller names, otherwise the team. */
	function db_auc_owner_email( array $a ) {
		if ( (int) $a['seller_id'] && function_exists( 'db_seller_by_id' ) ) {
			$s = db_seller_by_id( (int) $a['seller_id'] );
			if ( $s && is_email( $s['email'] ) ) {
				return $s['email'];
			}
		}
		return '';
	}
}

if ( ! function_exists( 'db_auc_purge' ) ) {
	function db_auc_purge( array $a ) {
		$url = $a['post_id'] ? get_permalink( (int) $a['post_id'] ) : '';
		if ( $url && function_exists( 'db_pc_purge_url' ) ) {
			db_pc_purge_url( $url );
			db_pc_purge_url( home_url( '/auctions/' ) );
		}
		do_action( 'litespeed_purge_url', $url );
	}
}

if ( ! function_exists( 'db_auc_money' ) ) {
	function db_auc_money( $n ) {
		return '$' . number_format( (int) $n );
	}
}

/* ------------------------------------------------------------ emails */

if ( ! function_exists( 'db_auc_mail' ) ) {
	function db_auc_mail( $to, $subject, $headline, $paras_html, array $details = array(), $cta = '', $url = '' ) {
		if ( ! is_email( $to ) || ! function_exists( 'db_plan_send' ) || ! function_exists( 'db_plan_email_shell' ) ) {
			return;
		}
		$body = '';
		foreach ( (array) $paras_html as $p ) {
			$body .= '<p style="font-size:15px;color:#374151;margin:0 0 12px;">' . $p . '</p>';
		}
		db_plan_send( $to, $subject, db_plan_email_shell( $headline, $body, $details, $cta, $url, 'neutral' ) );
	}
}

if ( ! function_exists( 'db_auc_team' ) ) {
	function db_auc_team( $subject, array $lines ) {
		if ( function_exists( 'db_plan_notify_admin' ) ) {
			db_plan_notify_admin( $subject, $lines );
		}
	}
}

/* ------------------------------------------------------------ actions */

if ( ! function_exists( 'db_auc_create' ) ) {
	/**
	 * Starts an auction for a published domain post.
	 *
	 * @return int|string New auction id, or the reason it could not start.
	 */
	function db_auc_create( $post_id, array $raw, $seller_id = 0, $by = '' ) {
		global $wpdb;
		$post = get_post( (int) $post_id );
		if ( ! $post || 'domain' !== $post->post_type || 'publish' !== $post->post_status ) {
			return 'That name is not live on the site.';
		}
		$domain = strtolower( trim( $post->post_title ) );
		if ( function_exists( 'db_stripe_domain_unavailable' ) && db_stripe_domain_unavailable( $domain ) ) {
			return 'That name is sold, reserved on a payment plan or not for sale.';
		}
		if ( db_auc_for_post( $post->ID, true ) ) {
			return 'That name already has a live auction.';
		}
		$rules           = db_auc_rules();
		list( $c, $err ) = db_auc_validate_setup( $raw, $rules );
		if ( $err ) {
			return implode( ' ', $err );
		}
		$now = time();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( db_auc_t(), array(
			'post_id'       => (int) $post->ID,
			'domain'        => $domain,
			'seller_id'     => (int) $seller_id,
			'start_price'   => $c['start'],
			'reserve'       => $c['reserve'],
			'show_reserve'  => $c['show_reserve'] ? 1 : 0,
			'bin_price'     => $c['bin'],
			'current_price' => 0,
			'starts_at'     => gmdate( 'Y-m-d H:i:s', $now ),
			'ends_at'       => gmdate( 'Y-m-d H:i:s', $now + $c['days'] * DAY_IN_SECONDS ),
			'state'         => 'live',
			'offered'       => '[]',
			'created_by'    => substr( (string) $by, 0, 255 ),
			'created_at'    => gmdate( 'Y-m-d H:i:s', $now ),
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return 'The auction could not be saved. Please try again.';
		}
		wp_schedule_single_event( $now + $c['days'] * DAY_IN_SECONDS + 5, 'db_auc_end_event', array( $id ) );
		if ( $seller_id && function_exists( 'db_seller_audit' ) ) {
			db_seller_audit( (int) $seller_id, $domain, 'auction_start', 'Auction from ' . db_auc_money( $c['start'] ) . ', ' . $c['days'] . ' days' . ( $c['reserve'] ? ', reserve ' . db_auc_money( $c['reserve'] ) : '' ) . ( $c['bin'] ? ', Buy It Now ' . db_auc_money( $c['bin'] ) : '' ) );
		}
		db_auc_purge( db_auc_get( $id ) );
		return $id;
	}
}

if ( ! function_exists( 'db_auc_bid' ) ) {
	/**
	 * Places a bid (the bidder's maximum).
	 *
	 * @return array{ok:bool,msg:string}
	 */
	function db_auc_bid( $id, $email, $max_raw, $agree, $ip = '' ) {
		global $wpdb;
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return array( 'ok' => false, 'msg' => 'Sign in to bid.' );
		}
		if ( ! $agree ) {
			return array( 'ok' => false, 'msg' => 'Tick the box to confirm that a winning bid is a commitment to buy.' );
		}
		$max = db_auc_clean_amount( $max_raw );
		if ( ! $max ) {
			return array( 'ok' => false, 'msg' => 'Enter your maximum bid in whole dollars.' );
		}
		if ( function_exists( 'db_wl_allow' ) && ! db_wl_allow( 'auc-bid:' . $email, 30, 10 * MINUTE_IN_SECONDS ) ) {
			return array( 'ok' => false, 'msg' => 'Too many bids in a short time. Please wait a few minutes.' );
		}
		if ( ! db_auc_lock( $id ) ) {
			return array( 'ok' => false, 'msg' => 'Many people are bidding right now. Please try again.' );
		}
		$a = db_auc_get( $id );
		if ( $a && 'live' === $a['state'] && db_auc_ts( $a['ends_at'] ) <= time() ) {
			db_auc_lock( $id, false );
			db_auc_finalize( $a );
			return array( 'ok' => false, 'msg' => 'This auction has ended.' );
		}
		if ( ! $a || 'live' !== $a['state'] ) {
			db_auc_lock( $id, false );
			return array( 'ok' => false, 'msg' => 'This auction is not taking bids.' );
		}
		if ( '' !== db_auc_owner_email( $a ) && db_auc_owner_email( $a ) === $email ) {
			db_auc_lock( $id, false );
			return array( 'ok' => false, 'msg' => 'You cannot bid on your own name.' );
		}
		$prev = (string) $a['leader_email'];
		$r    = db_auc_place_bid( db_auc_state( $a ), $email, $max );
		if ( ! $r['ok'] ) {
			db_auc_lock( $id, false );
			return array( 'ok' => false, 'msg' => $r['error'] );
		}
		$s     = $r['state'];
		$rules = db_auc_rules();
		$now   = time();
		$ends  = db_auc_extend( db_auc_ts( $a['ends_at'] ), $now, (int) $rules['extend_window'], (int) $rules['extend_by'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( db_auc_t(), array(
			'current_price' => (int) $s['current'],
			'leader_email'  => (string) $s['leader'],
			'leader_max'    => (int) $s['leader_max'],
			'bids'          => (int) $s['bids'],
			'ends_at'       => gmdate( 'Y-m-d H:i:s', $ends ),
		), array( 'id' => (int) $a['id'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( db_auc_t( 'bids' ), array(
			'auction_id'  => (int) $a['id'],
			'email'       => $email,
			'max_amount'  => $max,
			'price_after' => (int) $s['current'],
			'kind'        => 'bid',
			'ip'          => substr( (string) $ip, 0, 45 ),
			'created_at'  => gmdate( 'Y-m-d H:i:s', $now ),
		) );
		db_auc_lock( $id, false );
		if ( $ends !== db_auc_ts( $a['ends_at'] ) ) {
			wp_clear_scheduled_hook( 'db_auc_end_event', array( (int) $a['id'] ) );
			wp_schedule_single_event( $ends + 5, 'db_auc_end_event', array( (int) $a['id'] ) );
		}
		$a    = db_auc_get( $id );
		$url  = get_permalink( (int) $a['post_id'] );
		$name = $a['domain'];
		if ( $r['outbid'] && $r['outbid'] !== $email ) {
			db_auc_mail( $r['outbid'], 'You have been outbid on ' . $name, 'You have been outbid on ' . esc_html( $name ), array( 'Another bidder raised the price to <strong>' . esc_html( db_auc_money( $a['current_price'] ) ) . '</strong>. Bid again to stay in the auction.' ), array( 'Current bid' => db_auc_money( $a['current_price'] ), 'Ends' => wp_date( 'F j, Y g:i a T', db_auc_ts( $a['ends_at'] ) ) ), 'Bid again', $url );
		}
		if ( 1 === (int) $a['bids'] ) {
			$owner = db_auc_owner_email( $a );
			if ( $owner ) {
				db_auc_mail( $owner, 'First bid on ' . $name, 'Your auction has its first bid', array( esc_html( $name ) . ' received its first bid. We email you again when the auction ends.' ), array( 'Current bid' => db_auc_money( $a['current_price'] ) ), 'See the auction', $url );
			}
		}
		if ( $r['leading'] ) {
			$msg = $prev === $email ? 'Your maximum is now ' . db_auc_money( $max ) . '. You are still the highest bidder.' : 'You are the highest bidder at ' . db_auc_money( $a['current_price'] ) . '. We bid for you up to your maximum of ' . db_auc_money( $max ) . ' and email you if someone outbids you.';
		} else {
			$msg = 'Another bidder\'s maximum is higher, so the price rose to ' . db_auc_money( $a['current_price'] ) . '. Enter a higher maximum to take the lead.';
		}
		db_auc_purge( $a );
		return array( 'ok' => (bool) $r['leading'], 'msg' => $msg, 'outbid_now' => ! $r['leading'] );
	}
}

if ( ! function_exists( 'db_auc_make_deal' ) ) {
	/** An accepted offer at the auction price for $email; returns the deal or null. */
	function db_auc_make_deal( array $a, $email, $amount, $why ) {
		global $wpdb;
		if ( ! function_exists( 'db_deal_table' ) ) {
			return null;
		}
		$offered = json_decode( (string) $a['offered'], true );
		$offered = is_array( $offered ) ? $offered : array();
		$rules   = db_auc_rules();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( db_deal_table(), array(
			'lead_id'     => -( 100000000 + (int) $a['id'] * 10 + count( $offered ) ),
			'token'       => bin2hex( random_bytes( 16 ) ),
			'domain'      => $a['domain'],
			'buyer_email' => $email,
			'buyer_name'  => '',
			'state'       => 'accepted',
			'amount'      => (int) $amount,
			'proposer'    => 'seller',
			'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) $rules['pay_days'] ) * DAY_IN_SECONDS ),
		) );
		if ( ! $ok ) {
			return null;
		}
		$deal      = db_deal_get( (int) $wpdb->insert_id );
		$offered[] = $email;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( db_auc_t(), array( 'deal_id' => (int) $deal->id, 'offered' => wp_json_encode( $offered ) ), array( 'id' => (int) $a['id'] ) );
		db_deal_log( (int) $deal->id, 'system', 'accept', (int) $amount, $why );
		do_action( 'db_deal_changed', $deal, 'system', 'accept', $why );
		return $deal;
	}
}

if ( ! function_exists( 'db_auc_tell_winner' ) ) {
	function db_auc_tell_winner( array $a, $deal, $headline ) {
		$rules = db_auc_rules();
		db_auc_mail(
			$deal->buyer_email,
			$headline . ': ' . $a['domain'],
			$headline . ': ' . esc_html( $a['domain'] ),
			array(
				'Pay within ' . (int) $rules['pay_days'] . ' days to secure the name. You can pay in full or, from $' . number_format( (int) ( defined( 'DB_DEAL_MIN_PLAN_AMOUNT' ) ? DB_DEAL_MIN_PLAN_AMOUNT : 600 ) ) . ', over 3 to 12 months.',
				'If payment is not made in time, the name is offered to the next bidder.',
			),
			array( 'Domain' => $a['domain'], 'Price' => db_auc_money( $deal->amount ), 'Pay by' => wp_date( 'F j, Y', db_auc_ts( $deal->expires_at ) ) ),
			'Pay now',
			db_deal_pay_url( $deal )
		);
	}
}

if ( ! function_exists( 'db_auc_finalize' ) ) {
	/** Closes an auction whose time is up. Safe to call more than once. */
	function db_auc_finalize( array $a ) {
		global $wpdb;
		if ( 'live' !== $a['state'] || db_auc_ts( $a['ends_at'] ) > time() || ! db_auc_lock( $a['id'] ) ) {
			return;
		}
		$a = db_auc_get( $a['id'] );
		if ( ! $a || 'live' !== $a['state'] || db_auc_ts( $a['ends_at'] ) > time() ) {
			db_auc_lock( $a ? $a['id'] : 0, false );
			return;
		}
		$out   = db_auc_outcome( db_auc_state( $a ) );
		$state = 'sold' === $out ? 'won' : ( 'reserve_not_met' === $out ? 'reserve_not_met' : 'ended' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( db_auc_t(), array( 'state' => $state, 'ended_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $a['id'] ) );
		db_auc_lock( $a['id'], false );
		$a     = db_auc_get( $a['id'] );
		$url   = get_permalink( (int) $a['post_id'] );
		$owner = db_auc_owner_email( $a );
		$rules = db_auc_rules();
		if ( 'won' === $state ) {
			$deal = db_auc_make_deal( $a, $a['leader_email'], (int) $a['current_price'], 'Auction won at ' . db_auc_money( $a['current_price'] ) . '.' );
			if ( $deal ) {
				db_auc_tell_winner( $a, $deal, 'You won the auction' );
			}
			if ( $owner ) {
				db_auc_mail( $owner, 'Your auction ended: ' . $a['domain'] . ' sold for ' . db_auc_money( $a['current_price'] ), 'Your auction ended with a winner', array( esc_html( $a['domain'] ) . ' sold for <strong>' . esc_html( db_auc_money( $a['current_price'] ) ) . '</strong>. The winner has ' . (int) $rules['pay_days'] . ' days to pay. We email you the transfer steps once payment arrives.' ), array( 'Bids' => (string) (int) $a['bids'] ) );
			}
			db_auc_team( 'Auction won: ' . $a['domain'] . ' at ' . db_auc_money( $a['current_price'] ), array( 'Domain' => $a['domain'], 'Bids' => (string) (int) $a['bids'], 'Seller' => $owner ? $owner : 'Domain Brothers', 'Open' => admin_url( 'tools.php?page=db-auctions' ) ) );
		} elseif ( 'reserve_not_met' === $state ) {
			db_auc_mail( $a['leader_email'], 'Auction ended below the reserve: ' . $a['domain'], 'The auction ended below the reserve', array( 'Your bid of ' . esc_html( db_auc_money( $a['current_price'] ) ) . ' was the highest, but the owner\'s reserve was not met. The owner may still accept your bid within ' . (int) $rules['below_reserve'] . ' days; we email you if they do.' ), array( 'Domain' => $a['domain'] ), 'See the name', $url );
			$accept = $owner ? add_query_arg( 'view', 'auctions', home_url( '/seller/' ) ) : admin_url( 'tools.php?page=db-auctions' );
			if ( $owner ) {
				db_auc_mail( $owner, 'Your auction ended below the reserve: ' . $a['domain'], 'Your auction ended below the reserve', array( 'The highest bid was <strong>' . esc_html( db_auc_money( $a['current_price'] ) ) . '</strong>. You can accept it within ' . (int) $rules['below_reserve'] . ' days from the Auctions tab of your seller page.' ), array(), 'Review the bid', $accept );
			}
			db_auc_team( 'Auction below reserve: ' . $a['domain'], array( 'Domain' => $a['domain'], 'High bid' => db_auc_money( $a['current_price'] ), 'Reserve' => db_auc_money( $a['reserve'] ), 'Open' => $accept ) );
		} else {
			if ( $owner ) {
				db_auc_mail( $owner, 'Your auction ended without bids: ' . $a['domain'], 'Your auction ended without bids', array( esc_html( $a['domain'] ) . ' is back to its normal listing. Buyers can still make offers. You can start a new auction with a lower starting price at any time.' ) );
			}
		}
		db_auc_purge( $a );
	}
}

if ( ! function_exists( 'db_auc_buy_now' ) ) {
	/** @return array{ok:bool,msg:string,url?:string} */
	function db_auc_buy_now( $id, $email ) {
		global $wpdb;
		$email = strtolower( trim( (string) $email ) );
		if ( ! is_email( $email ) ) {
			return array( 'ok' => false, 'msg' => 'Sign in to buy.' );
		}
		if ( ! db_auc_lock( $id ) ) {
			return array( 'ok' => false, 'msg' => 'Please try again.' );
		}
		$a = db_auc_get( $id );
		if ( ! $a || 'live' !== $a['state'] || db_auc_ts( $a['ends_at'] ) <= time() || ! db_auc_bin_open( db_auc_state( $a ) ) ) {
			db_auc_lock( $id, false );
			return array( 'ok' => false, 'msg' => 'Buy It Now is no longer available on this auction.' );
		}
		if ( '' !== db_auc_owner_email( $a ) && db_auc_owner_email( $a ) === $email ) {
			db_auc_lock( $id, false );
			return array( 'ok' => false, 'msg' => 'You cannot buy your own name.' );
		}
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( db_auc_t(), array( 'state' => 'won', 'ended_at' => $now, 'ends_at' => $now, 'leader_email' => $email, 'current_price' => (int) $a['bin_price'], 'note' => 'Buy It Now' ), array( 'id' => (int) $a['id'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( db_auc_t( 'bids' ), array( 'auction_id' => (int) $a['id'], 'email' => $email, 'max_amount' => (int) $a['bin_price'], 'price_after' => (int) $a['bin_price'], 'kind' => 'bin', 'created_at' => $now ) );
		db_auc_lock( $id, false );
		wp_clear_scheduled_hook( 'db_auc_end_event', array( (int) $a['id'] ) );
		$a    = db_auc_get( $id );
		$deal = db_auc_make_deal( $a, $email, (int) $a['bin_price'], 'Bought with Buy It Now at ' . db_auc_money( $a['bin_price'] ) . '.' );
		if ( ! $deal ) {
			return array( 'ok' => false, 'msg' => 'Something stopped the purchase. Please contact us.' );
		}
		db_auc_tell_winner( $a, $deal, 'You bought it with Buy It Now' );
		// Everyone who bid is told the auction ended.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT email FROM ' . db_auc_t( 'bids' ) . " WHERE auction_id = %d AND kind = 'bid' AND email <> %s", (int) $a['id'], $email ) ) as $e ) {
			db_auc_mail( $e, 'Auction ended: ' . $a['domain'], 'This auction has ended', array( esc_html( $a['domain'] ) . ' was bought with Buy It Now. Thanks for bidding. Similar names are listed on Domain Brothers.' ), array(), 'Browse names', home_url( '/all-domains/' ) );
		}
		$owner = db_auc_owner_email( $a );
		if ( $owner ) {
			db_auc_mail( $owner, 'Bought with Buy It Now: ' . $a['domain'], 'Your name was bought with Buy It Now', array( esc_html( $a['domain'] ) . ' was bought for <strong>' . esc_html( db_auc_money( $a['bin_price'] ) ) . '</strong>. We email you the transfer steps once payment arrives.' ) );
		}
		db_auc_team( 'Buy It Now: ' . $a['domain'] . ' at ' . db_auc_money( $a['bin_price'] ), array( 'Domain' => $a['domain'], 'Open' => admin_url( 'tools.php?page=db-auctions' ) ) );
		db_auc_purge( $a );
		return array( 'ok' => true, 'msg' => 'It is yours once paid. Pay within ' . (int) db_auc_rules()['pay_days'] . ' days.', 'url' => db_deal_pay_url( $deal ) );
	}
}

if ( ! function_exists( 'db_auc_accept_below' ) ) {
	/** The owner accepts the highest bid after the reserve was not met. */
	function db_auc_accept_below( array $a ) {
		global $wpdb;
		$rules = db_auc_rules();
		if ( 'reserve_not_met' !== $a['state'] ) {
			return 'Only an auction that ended below its reserve can be accepted.';
		}
		if ( db_auc_ts( $a['ended_at'] ) < time() - (int) $rules['below_reserve'] * DAY_IN_SECONDS ) {
			return 'The time to accept this bid has passed.';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( db_auc_t(), array( 'state' => 'won', 'note' => 'Accepted below reserve' ), array( 'id' => (int) $a['id'] ) );
		$a    = db_auc_get( $a['id'] );
		$deal = db_auc_make_deal( $a, $a['leader_email'], (int) $a['current_price'], 'Owner accepted the highest bid of ' . db_auc_money( $a['current_price'] ) . '.' );
		if ( $deal ) {
			db_auc_tell_winner( $a, $deal, 'The owner accepted your bid' );
		}
		db_auc_purge( $a );
		return '';
	}
}

if ( ! function_exists( 'db_auc_cancel' ) ) {
	function db_auc_cancel( array $a, $why = '' ) {
		global $wpdb;
		if ( 'live' !== $a['state'] ) {
			return 'Only a live auction can be cancelled.';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( db_auc_t(), array( 'state' => 'cancelled', 'ended_at' => current_time( 'mysql', true ), 'note' => substr( (string) $why, 0, 255 ) ), array( 'id' => (int) $a['id'] ) );
		wp_clear_scheduled_hook( 'db_auc_end_event', array( (int) $a['id'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT email FROM ' . db_auc_t( 'bids' ) . ' WHERE auction_id = %d', (int) $a['id'] ) ) as $e ) {
			db_auc_mail( $e, 'Auction cancelled: ' . $a['domain'], 'This auction was cancelled', array( 'The auction for ' . esc_html( $a['domain'] ) . ' was cancelled. Your bid no longer applies. Sorry for the trouble.' ) );
		}
		db_auc_purge( $a );
		return '';
	}
}

if ( ! function_exists( 'db_auc_request_reserve' ) ) {
	function db_auc_request_reserve( array $a, $email ) {
		global $wpdb;
		if ( 'live' !== $a['state'] || ! (int) $a['reserve'] || (int) $a['show_reserve'] ) {
			return 'There is no hidden reserve to ask about.';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO ' . db_auc_t( 'requests' ) . ' (auction_id, email, created_at) VALUES (%d, %s, %s)', (int) $a['id'], strtolower( $email ), current_time( 'mysql', true ) ) );
		if ( ! $ok ) {
			return 'You already asked. The owner has been told.';
		}
		$owner = db_auc_owner_email( $a );
		$lines = array( 'Domain' => $a['domain'], 'Reserve' => db_auc_money( $a['reserve'] ), 'Current bid' => db_auc_money( $a['current_price'] ) );
		if ( $owner ) {
			db_auc_mail( $owner, 'A buyer asked about the reserve on ' . $a['domain'], 'A buyer asked about your reserve', array( 'A signed-in buyer would like to know the reserve on ' . esc_html( $a['domain'] ) . '. Showing the reserve, or lowering it, often brings more bids. You can do either by starting a new auction after this one.' ), $lines );
		}
		db_auc_team( 'Reserve request: ' . $a['domain'], $lines );
		return '';
	}
}

/* ------------------------------------------------- end events and sweep */

add_action( 'db_auc_end_event', function ( $id ) {
	$a = db_auc_get( (int) $id );
	if ( $a ) {
		db_auc_finalize( $a );
	}
} );

add_action( 'init', function () {
	if ( db_auc_enabled() && ! wp_next_scheduled( 'db_auc_sweep' ) ) {
		wp_schedule_event( time() + 600, 'hourly', 'db_auc_sweep' );
	}
} );
add_action( 'db_auc_sweep', 'db_auc_sweep_run' );

if ( ! function_exists( 'db_auc_sweep_run' ) ) {
	/** Ends overdue auctions, and gives the name to the next bidder when a winner did not pay. */
	function db_auc_sweep_run() {
		global $wpdb;
		$out = array( 'ended' => 0, 'second' => 0, 'unpaid' => 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . db_auc_t() . " WHERE state = 'live' AND ends_at <= %s LIMIT 50", current_time( 'mysql', true ) ), ARRAY_A ) as $a ) {
			db_auc_finalize( $a );
			++$out['ended'];
		}
		$rules = db_auc_rules();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( 'SELECT * FROM ' . db_auc_t() . " WHERE state = 'won' AND deal_id > 0 LIMIT 100", ARRAY_A ) as $a ) {
			$deal = function_exists( 'db_deal_get' ) ? db_deal_get( (int) $a['deal_id'] ) : null;
			$deal = $deal && function_exists( 'db_deal_expire_if_due' ) ? db_deal_expire_if_due( $deal ) : $deal;
			if ( ! $deal ) {
				continue;
			}
			if ( 'paid' === $deal->state ) {
				$wpdb->update( db_auc_t(), array( 'state' => 'sold' ), array( 'id' => (int) $a['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				continue;
			}
			if ( 'expired' !== $deal->state ) {
				continue;
			}
			$offered = json_decode( (string) $a['offered'], true );
			$offered = is_array( $offered ) ? $offered : array();
			$next    = null;
			if ( count( $offered ) - 1 < (int) $rules['second_chances'] ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows  = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT email, MAX(max_amount) AS m FROM ' . db_auc_t( 'bids' ) . " WHERE auction_id = %d AND kind = 'bid' GROUP BY email", (int) $a['id'] ), ARRAY_A );
				$maxes = array();
				foreach ( $rows as $r ) {
					$maxes[ $r['email'] ] = (int) $r['m'];
				}
				$next = db_auc_next_chance( $maxes, $offered, (int) $a['reserve'] );
			}
			db_auc_team( 'Auction winner did not pay: ' . $a['domain'], array( 'Domain' => $a['domain'], 'Winner' => $deal->buyer_email, 'Next' => $next ? 'Offered to the next bidder at ' . db_auc_money( $next[1] ) : 'No one left; the name is back to its normal listing' ) );
			if ( $next ) {
				$deal2 = db_auc_make_deal( $a, $next[0], $next[1], 'Second chance: the winner did not pay. Offered at your maximum bid of ' . db_auc_money( $next[1] ) . '.' );
				if ( $deal2 ) {
					db_auc_tell_winner( db_auc_get( $a['id'] ), $deal2, 'The name is now yours to buy' );
					++$out['second'];
				}
			} else {
				$wpdb->update( db_auc_t(), array( 'state' => 'unpaid' ), array( 'id' => (int) $a['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$out['unpaid'];
				db_auc_purge( $a );
			}
		}
		return $out;
	}
}

// A paid auction deal marks the auction sold.
add_action( 'db_deal_changed', function ( $deal, $actor, $action ) {
	global $wpdb;
	if ( 'paid' !== $action || ! $deal ) {
		return;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . db_auc_t() . " WHERE deal_id = %d AND state = 'won'", (int) $deal->id ) );
	if ( $id ) {
		$wpdb->update( db_auc_t(), array( 'state' => 'sold' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		db_auc_purge( db_auc_get( $id ) );
	}
}, 40, 3 );

// While an auction is live, or its winner is paying, the name cannot be bought at its fixed price.
add_filter( 'db_stripe_fixed_price_blocked', function ( $blocked, $domain ) {
	global $wpdb;
	if ( $blocked || ! db_auc_enabled() ) {
		return $blocked;
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . db_auc_t() . " WHERE domain = %s AND state IN ('live','won') LIMIT 1", strtolower( trim( (string) $domain ) ) ) );
}, 10, 2 );

/* -------------------------------------------------------- public view */

if ( ! function_exists( 'db_auc_csrf' ) ) {
	function db_auc_csrf( $email ) {
		return substr( hash_hmac( 'sha256', 'auc|' . strtolower( (string) $email ), wp_salt( 'nonce' ) ), 0, 32 );
	}
}

if ( ! function_exists( 'db_auc_history' ) ) {
	/** Recent bids for display: alias, price after the bid, when. */
	function db_auc_history( array $a, $limit = 10 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows    = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT email, price_after, kind, created_at FROM ' . db_auc_t( 'bids' ) . ' WHERE auction_id = %d ORDER BY id ASC', (int) $a['id'] ), ARRAY_A );
		$aliases = db_auc_aliases( wp_list_pluck( $rows, 'email' ) );
		$out     = array();
		foreach ( array_reverse( $rows ) as $r ) {
			$out[] = array( 'who' => $aliases[ $r['email'] ], 'price' => (int) $r['price_after'], 'kind' => $r['kind'], 'ts' => db_auc_ts( $r['created_at'] ) );
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'db_auc_public' ) ) {
	/** What anyone may see about an auction (and whether $email leads). */
	function db_auc_public( array $a, $email = '' ) {
		$s = db_auc_state( $a );
		return array(
			'id'       => (int) $a['id'],
			'state'    => $a['state'],
			'current'  => (int) $a['current_price'],
			'start'    => (int) $a['start_price'],
			'bids'     => (int) $a['bids'],
			'ends'     => db_auc_ts( $a['ends_at'] ),
			'now'      => time(),
			'min'      => db_auc_min_bid( $s ),
			'reserve'  => db_auc_reserve_label( $s, (bool) $a['show_reserve'] ),
			'bin'      => db_auc_bin_open( $s ) ? (int) $a['bin_price'] : 0,
			'leading'  => '' !== $email && strtolower( $email ) === $a['leader_email'],
			'history'  => db_auc_history( $a ),
		);
	}
}

if ( ! function_exists( 'db_auc_box_html' ) ) {
	/** The auction box at the top of a domain page. */
	function db_auc_box_html( array $a, $email = '' ) {
		$p      = db_auc_public( $a, $email );
		$url    = get_permalink( (int) $a['post_id'] );
		$signin = add_query_arg( array( 'auth' => 'signin', 'next' => rawurlencode( $url ) ), home_url( '/my-wishlist/' ) );
		$live   = 'live' === $a['state'];
		$h      = '<section class="dbauc" id="dbauc" data-auc="' . (int) $a['id'] . '" data-api="' . esc_url( rest_url( 'db/v1/auction/' . (int) $a['id'] ) ) . '" aria-labelledby="dbauc-h">';
		$h     .= '<div class="dbauc-top"><p class="dbauc-eyebrow">' . ( $live ? 'Auction' : 'Auction ended' ) . '</p><h2 id="dbauc-h">' . ( $live ? 'Bid on ' : '' ) . esc_html( $a['domain'] ) . '</h2></div>';
		$h     .= '<dl class="dbauc-stats">';
		$h     .= '<div><dt>' . ( $p['bids'] ? ( $live ? 'Current bid' : 'Winning bid' ) : 'Starting price' ) . '</dt><dd data-k="price">' . esc_html( db_auc_money( $p['bids'] ? $p['current'] : $p['start'] ) ) . '</dd></div>';
		$h     .= '<div><dt>Bids</dt><dd data-k="bids">' . (int) $p['bids'] . '</dd></div>';
		$h     .= '<div><dt>' . ( $live ? 'Time left' : 'Ended' ) . '</dt><dd data-k="left" data-ends="' . (int) $p['ends'] . '">' . esc_html( $live ? db_auc_time_left( $p['ends'], time() ) : wp_date( 'M j, Y', $p['ends'] ) ) . '</dd></div>';
		$h     .= '<div><dt>Reserve</dt><dd data-k="reserve">' . esc_html( $p['reserve'] ) . '</dd></div>';
		$h     .= '</dl>';
		if ( $live ) {
			$h .= '<p class="dbauc-ends">Ends ' . esc_html( wp_date( 'l, F j \a\t g:i a T', $p['ends'] ) ) . '. A bid in the last ' . (int) db_auc_rules()['extend_window'] . ' minutes extends the auction.</p>';
			if ( $p['leading'] ) {
				$h .= '<p class="dbauc-lead" role="status">You are the highest bidder.</p>';
			}
			if ( '' === $email ) {
				$h .= '<p class="dbauc-cta"><a class="dbauc-btn" href="' . esc_url( $signin ) . '">Sign in to bid</a> <span>Free account, one-time email code.</span></p>';
			} else {
				$t  = esc_attr( db_auc_csrf( $email ) );
				$h .= '<form class="dbauc-form" method="post" action="' . esc_url( $url ) . '#dbauc"><input type="hidden" name="db_auc_action" value="bid"><input type="hidden" name="auc" value="' . (int) $a['id'] . '"><input type="hidden" name="t" value="' . $t . '">'
					. '<label for="dbauc-max">Your maximum bid (USD)</label><div class="dbauc-row"><span class="dbauc-cur" aria-hidden="true">$</span><input id="dbauc-max" name="max" type="text" inputmode="numeric" autocomplete="off" required placeholder="' . esc_attr( number_format( $p['min'] ) ) . ' or more" aria-describedby="dbauc-min"><button type="submit" class="dbauc-btn">Place bid</button></div>'
					. '<p class="dbauc-min" id="dbauc-min">Enter <span data-k="min">' . esc_html( db_auc_money( $p['min'] ) ) . '</span> or more. We bid for you only as much as needed to keep you ahead, up to your maximum.</p>'
					. '<label class="dbauc-agree"><input type="checkbox" name="agree" value="1" required> I understand that a winning bid is a commitment to buy this name.</label></form>';
				if ( $p['bin'] ) {
					$h .= '<form class="dbauc-bin" method="post" action="' . esc_url( $url ) . '#dbauc"><input type="hidden" name="db_auc_action" value="bin"><input type="hidden" name="auc" value="' . (int) $a['id'] . '"><input type="hidden" name="t" value="' . $t . '"><button type="submit" class="dbauc-btn dbauc-btn--ghost">Buy it now for ' . esc_html( db_auc_money( $p['bin'] ) ) . '</button> <span>Ends the auction and the name is yours once paid.</span></form>';
				}
				if ( (int) $a['reserve'] && ! (int) $a['show_reserve'] && 'Reserve met' !== $p['reserve'] ) {
					$h .= '<form class="dbauc-ask" method="post" action="' . esc_url( $url ) . '#dbauc"><input type="hidden" name="db_auc_action" value="reserve"><input type="hidden" name="auc" value="' . (int) $a['id'] . '"><input type="hidden" name="t" value="' . $t . '"><button type="submit" class="dbauc-link">Ask the owner about the reserve</button></form>';
				}
			}
		} elseif ( in_array( $a['state'], array( 'won', 'sold' ), true ) ) {
			$h .= '<p class="dbauc-ends">This auction has a winner.' . ( $p['leading'] ? ' That is you: check your email for the payment link, or see My bids in your account.' : '' ) . '</p>';
		} elseif ( 'reserve_not_met' === $a['state'] ) {
			$h .= '<p class="dbauc-ends">The auction ended below the reserve. The owner may still accept the highest bid. You can also make an offer below.</p>';
		} else {
			$h .= '<p class="dbauc-ends">The auction ended without a sale. You can still make an offer below.</p>';
		}
		if ( $p['history'] ) {
			$h .= '<details class="dbauc-hist"><summary>Bid history (' . (int) $p['bids'] . ')</summary><ol data-k="history">';
			foreach ( $p['history'] as $r ) {
				$h .= '<li><span>' . esc_html( $r['who'] ) . ( 'bin' === $r['kind'] ? ' (Buy It Now)' : '' ) . '</span><strong>' . esc_html( db_auc_money( $r['price'] ) ) . '</strong><time datetime="' . esc_attr( gmdate( 'c', $r['ts'] ) ) . '">' . esc_html( human_time_diff( $r['ts'], time() ) ) . ' ago</time></li>';
			}
			$h .= '</ol></details>';
		}
		return $h . '</section>';
	}
}

if ( ! function_exists( 'db_auc_css' ) ) {
	function db_auc_css( $hide_buy = false ) {
		$css = '.dbauc{margin:0 0 24px;padding:clamp(18px,3vw,28px);border:2px solid #0a1628;border-radius:22px;background:#fff;color:#0a1628}'
			. '.dbauc-eyebrow{margin:0 0 4px;font-size:12.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase;color:#4d7c0f}'
			. '.dbauc h2{margin:0 0 14px;font-size:clamp(22px,3vw,30px);font-weight:900;letter-spacing:-.02em;overflow-wrap:anywhere}'
			. '.dbauc-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 12px}.dbauc-stats>div{padding:12px 14px;border-radius:14px;background:#f4f7f5}'
			. '.dbauc-stats dt{font-size:12.5px;font-weight:700;color:#4b5563;margin:0 0 2px}.dbauc-stats dd{margin:0;font-size:20px;font-weight:900;font-variant-numeric:tabular-nums}'
			. '@media(max-width:640px){.dbauc-stats{grid-template-columns:1fr 1fr}}'
			. '.dbauc-ends,.dbauc-min{font-size:14.5px;color:#374151;margin:0 0 12px}.dbauc-lead{margin:0 0 12px;padding:10px 14px;border-radius:12px;background:#ecf7ef;color:#0b5a2b;font-weight:700}'
			. '.dbauc-form label{display:block;font-weight:700;margin:0 0 6px}.dbauc-row{display:flex;gap:8px;align-items:stretch}.dbauc-cur{display:flex;align-items:center;padding:0 12px;border-radius:12px;background:#f4f7f5;font-weight:800}'
			. '.dbauc-row input{flex:1 1 auto;min-width:0;min-height:48px;padding:10px 14px;border:1.5px solid #cfd8d3;border-radius:12px;font:inherit;font-size:17px}'
			. 'html body .dbauc .dbauc-btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 20px;border:0;border-radius:12px;background:#0a1628;color:#fff!important;font-weight:800;font-size:15.5px;text-decoration:none!important;cursor:pointer}'
			. 'html body .dbauc .dbauc-btn--ghost{background:#fff;color:#0a1628!important;border:2px solid #0a1628}'
			. '.dbauc-agree{display:flex!important;gap:8px;align-items:flex-start;font-weight:400!important;font-size:14.5px;margin:6px 0 0}.dbauc-agree input{margin-top:3px;width:18px;height:18px;flex:none}'
			. '.dbauc-bin,.dbauc-ask,.dbauc-cta{margin:14px 0 0;display:flex;flex-wrap:wrap;gap:10px;align-items:center;font-size:14px;color:#4b5563}'
			. 'html body .dbauc .dbauc-link{background:none!important;border:0!important;padding:6px 0!important;color:#0b6b36!important;text-decoration:underline!important;font-weight:600!important;cursor:pointer;min-height:40px;box-shadow:none!important;text-transform:none!important}'
			. '.dbauc-hist{margin:16px 0 0}.dbauc-hist summary{cursor:pointer;font-weight:700;min-height:40px;display:flex;align-items:center}.dbauc-hist ol{margin:6px 0 0;padding:0;list-style:none}.dbauc-hist li{display:grid;grid-template-columns:1fr auto auto;gap:12px;padding:8px 0;border-bottom:1px solid #eef2ef;font-size:14.5px;font-variant-numeric:tabular-nums}.dbauc-hist time{color:#4b5563}'
			. '.dbauc-msg{margin:0 0 12px;padding:12px 16px;border-radius:12px;font-size:15px}.dbauc-msg--ok{background:#ecf7ef;color:#0b5a2b}.dbauc-msg--bad{background:#fef2f2;color:#991b1b}';
		if ( $hide_buy ) {
			// Fixed-price buttons do not apply while bidding is open (the server refuses them too).
			$x    = 'html body.db-auc-on.db-auc-on.db-auc-on ';
			$css .= $x . 'a[href*="/buy-now/?d="][href],' . $x . 'a[href*="/payment-plan-setup/"][href],' . $x . '.db-pr-amount,' . $x . '.db-pr-fine,' . $x . '.db-dd-side-price{display:none!important}';
		}
		return '<style id="dbauc-css">' . $css . '</style>';
	}
}

if ( ! function_exists( 'db_auc_js' ) ) {
	/** Live countdown and price refresh (the page may be served from cache). */
	function db_auc_js() {
		return '<script data-no-delay>(function(){var b=document.getElementById("dbauc");if(!b)return;var api=b.getAttribute("data-api"),left=b.querySelector("[data-k=left]");'
			. 'function fmt(s){if(s<=0)return"Ended";var d=Math.floor(s/86400),h=Math.floor(s%86400/3600),m=Math.floor(s%3600/60),x=s%60;function p(n,w){return n+" "+w+(n===1?"":"s")}if(d)return p(d,"day")+(h?" "+p(h,"hour"):"");if(h)return p(h,"hour")+(m?" "+p(m,"minute"):"");return m?p(m,"minute")+" "+p(x,"second"):p(x,"second")}'
			. 'var skew=0;function tick(){if(!left)return;var e=+left.getAttribute("data-ends");if(!e||b.querySelector(".dbauc-eyebrow").textContent!=="Auction")return;left.textContent=fmt(e-Math.floor(Date.now()/1000)-skew)}'
			. 'function money(n){return"$"+Number(n).toLocaleString("en-US")}'
			. 'function load(){fetch(api,{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json()}).then(function(j){if(!j||!j.id)return;skew=Math.floor(Date.now()/1000)-j.now;var q=function(k){return b.querySelector("[data-k="+k+"]")};'
			. 'if(q("price"))q("price").textContent=money(j.bids?j.current:j.start);if(q("bids"))q("bids").textContent=j.bids;if(q("reserve"))q("reserve").textContent=j.reserve;if(q("min"))q("min").textContent=money(j.min);if(left)left.setAttribute("data-ends",j.ends);'
			. 'var inp=document.getElementById("dbauc-max");if(inp)inp.placeholder=Number(j.min).toLocaleString("en-US")+" or more";if(j.state!=="live"&&left){left.textContent="Ended"}tick()}).catch(function(){})}'
			. 'load();setInterval(tick,1000);setInterval(load,30000);document.addEventListener("visibilitychange",function(){if(!document.hidden)load()})})();</script>';
	}
}

// Domain page: the auction box first, and fixed-price buttons hidden while bidding is open.
add_filter( 'db_dd_html', function ( $html, $post ) {
	if ( ! db_auc_enabled() ) {
		return $html;
	}
	$a = db_auc_for_post( $post->ID );
	if ( ! $a ) {
		return $html;
	}
	if ( 'live' === $a['state'] && db_auc_ts( $a['ends_at'] ) <= time() ) {
		db_auc_finalize( $a );
		$a = db_auc_get( $a['id'] );
	}
	$email = function_exists( 'db_wl_email' ) ? db_wl_email() : '';
	$flash = '';
	if ( '' !== $email ) {
		$f = get_transient( 'db_auc_flash_' . md5( $email ) );
		if ( is_array( $f ) ) {
			delete_transient( 'db_auc_flash_' . md5( $email ) );
			$flash = '<p class="dbauc-msg dbauc-msg--' . ( 'ok' === $f[0] ? 'ok' : 'bad' ) . '" role="status">' . esc_html( $f[1] ) . '</p>';
		}
	}
	$box = db_auc_box_html( $a, $email );
	$at = strpos( $box, '<dl class="dbauc-stats">' );
	if ( '' !== $flash && false !== $at ) {
		$box = substr( $box, 0, $at ) . $flash . substr( $box, $at ); // plain insert: amounts like "$1,500" must not be read as regex backreferences
	}
	return db_auc_css( in_array( $a['state'], array( 'live', 'won' ), true ) ) . $box . ( 'live' === $a['state'] ? db_auc_js() : '' ) . $html;
}, 5, 2 );

// Marks the page while bidding is open or the winner is paying (used to hide fixed-price buttons).
add_filter( 'body_class', function ( $c ) {
	if ( is_singular( 'domain' ) && db_auc_enabled() ) {
		$a = db_auc_for_post( get_queried_object_id() );
		if ( $a && in_array( $a['state'], array( 'live', 'won' ), true ) ) {
			$c[] = 'db-auc-on';
		}
	}
	return $c;
} );

// Bid, Buy It Now and reserve requests posted from the domain page.
add_action( 'template_redirect', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['db_auc_action'] ) || ! is_singular( 'domain' ) || ! db_auc_enabled() ) { // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		return;
	}
	$back  = get_permalink( get_queried_object_id() ) . '#dbauc';
	$email = function_exists( 'db_wl_email' ) ? db_wl_email() : '';
	$p     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- checked with a session token and the request origin below.
	$flash = function ( $tone, $msg ) use ( $email ) {
		if ( '' !== $email ) {
			set_transient( 'db_auc_flash_' . md5( $email ), array( $tone, $msg ), 5 * MINUTE_IN_SECONDS );
		}
	};
	if ( '' === $email ) {
		wp_safe_redirect( add_query_arg( array( 'auth' => 'signin', 'next' => rawurlencode( $back ) ), home_url( '/my-wishlist/' ) ) );
		exit;
	}
	if ( ! hash_equals( db_auc_csrf( $email ), (string) ( $p['t'] ?? '' ) ) || ( function_exists( 'db_wl_same_origin' ) && ! db_wl_same_origin() ) ) {
		$flash( 'bad', 'Your session changed. Please try again.' );
		wp_safe_redirect( $back );
		exit;
	}
	$a = db_auc_get( (int) ( $p['auc'] ?? 0 ) );
	if ( ! $a || (int) $a['post_id'] !== (int) get_queried_object_id() ) {
		$flash( 'bad', 'That auction was not found.' );
		wp_safe_redirect( $back );
		exit;
	}
	$act = sanitize_key( (string) $p['db_auc_action'] );
	if ( 'bid' === $act ) {
		$r = db_auc_bid( (int) $a['id'], $email, (string) ( $p['max'] ?? '' ), ! empty( $p['agree'] ), function_exists( 'db_wl_ip' ) ? db_wl_ip() : '' );
		$flash( $r['ok'] ? 'ok' : 'bad', $r['msg'] );
	} elseif ( 'bin' === $act ) {
		$r = db_auc_buy_now( (int) $a['id'], $email );
		if ( $r['ok'] && ! empty( $r['url'] ) ) {
			wp_safe_redirect( $r['url'] );
			exit;
		}
		$flash( 'bad', $r['msg'] );
	} elseif ( 'reserve' === $act ) {
		$why = db_auc_request_reserve( $a, $email );
		$flash( '' === $why ? 'ok' : 'bad', '' === $why ? 'Done. The owner has been told you would like to know the reserve.' : $why );
	}
	wp_safe_redirect( $back );
	exit;
}, 4 );

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/auction/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$a = db_auc_enabled() ? db_auc_get( (int) $req['id'] ) : null;
			if ( ! $a || 'cancelled' === $a['state'] ) {
				return new WP_REST_Response( array( 'error' => 'Not found.' ), 404 );
			}
			if ( 'live' === $a['state'] && db_auc_ts( $a['ends_at'] ) <= time() ) {
				db_auc_finalize( $a );
				$a = db_auc_get( $a['id'] );
			}
			$res = new WP_REST_Response( db_auc_public( $a, function_exists( 'db_wl_email' ) ? db_wl_email() : '' ) );
			$res->header( 'Cache-Control', 'no-store' );
			return $res;
		},
	) );
} );

/* --------------------------------------------------------- /auctions/ */

add_action( 'template_redirect', function () {
	if ( ! function_exists( 'db_sales_request_path' ) || 'auctions' !== db_sales_request_path() ) {
		return;
	}
	if ( ! db_auc_enabled() ) {
		return; // normal 404
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$live = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . db_auc_t() . " WHERE state = 'live' AND ends_at > %s ORDER BY ends_at ASC LIMIT 60", current_time( 'mysql', true ) ), ARRAY_A );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$done = (array) $wpdb->get_results( 'SELECT * FROM ' . db_auc_t() . " WHERE state IN ('won','sold') ORDER BY ended_at DESC LIMIT 12", ARRAY_A );
	$card = static function ( array $a, $ended ) {
		$s = db_auc_state( $a );
		return '<li class="dbaucl-card"><a href="' . esc_url( get_permalink( (int) $a['post_id'] ) ) . '"><strong class="dbaucl-name">' . esc_html( $a['domain'] ) . '</strong>'
			. '<span class="dbaucl-price">' . esc_html( db_auc_money( $s['bids'] ? $s['current'] : $s['start'] ) ) . '</span>'
			. '<span class="dbaucl-meta">' . ( $ended ? 'Sold at auction' : esc_html( $s['bids'] . ' ' . ( 1 === $s['bids'] ? 'bid' : 'bids' ) . ' · ' . db_auc_time_left( db_auc_ts( $a['ends_at'] ), time() ) . ' left' ) ) . '</span>'
			. ( ! $ended ? '<span class="dbaucl-res">' . esc_html( db_auc_reserve_label( $s, (bool) $a['show_reserve'] ) ) . ( db_auc_bin_open( $s ) ? ' · Buy It Now ' . esc_html( db_auc_money( $s['bin'] ) ) : '' ) . '</span>' : '' )
			. '</a></li>';
	};
	$css  = '<style>.dbaucl{list-style:none;margin:0 0 30px;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px}.dbaucl-card a{display:flex;flex-direction:column;gap:4px;height:100%;padding:18px;border:1.5px solid #dfe5e1;border-radius:18px;background:#fff;color:#0a1628!important;text-decoration:none!important}.dbaucl-card a:hover,.dbaucl-card a:focus-visible{border-color:#0a1628}.dbaucl-name{font-size:18px;font-weight:900;overflow-wrap:anywhere}.dbaucl-price{font-size:22px;font-weight:900;font-variant-numeric:tabular-nums}.dbaucl-meta,.dbaucl-res{font-size:14px;color:#4b5563}.dbaucl-how{max-width:70ch}</style>';
	$body = '<p class="db-sales-lead">Bid on premium domains. Enter the most you would pay and we bid for you, only as much as needed to keep you ahead. A late bid extends the auction, so everyone gets a fair last chance.</p>' . $css;
	$body .= '<h2>Ending soonest</h2>';
	$body .= $live ? '<ul class="dbaucl">' . implode( '', array_map( static function ( $a ) use ( $card ) { return $card( $a, false ); }, $live ) ) . '</ul>' : '<p>No auctions are running right now. Browse <a href="' . esc_url( home_url( '/all-domains/' ) ) . '">all domains</a> or check back soon.</p>';
	if ( $done ) {
		$body .= '<h2>Recently sold at auction</h2><ul class="dbaucl">' . implode( '', array_map( static function ( $a ) use ( $card ) { return $card( $a, true ); }, $done ) ) . '</ul>';
	}
	$body .= '<section class="dbaucl-how"><h2>How our auctions work</h2><ol><li><strong>Sign in</strong> with a free account (a one-time email code, no password).</li><li><strong>Enter your maximum.</strong> We raise your bid automatically, one step at a time, only when someone bids against you.</li><li><strong>Watch the reserve.</strong> Some owners set a lowest price; the page shows when it is met.</li><li><strong>Win and pay.</strong> The highest bidder when the timer ends wins and pays within ' . (int) db_auc_rules()['pay_days'] . ' days, in full or over 3 to 12 months. The transfer is handled by our team.</li></ol></section>';
	db_sales_render( 'Domain auctions', 'Auctions', $body );
}, 12 );

/* ------------------------------------------------- account: My bids */

if ( ! function_exists( 'db_auc_bids_for' ) ) {
	/** The auctions $email bid on, newest first, with their highest maximum. */
	function db_auc_bids_for( $email ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, MAX(b.max_amount) AS my_max FROM ' . db_auc_t() . ' a JOIN ' . db_auc_t( 'bids' ) . ' b ON b.auction_id = a.id WHERE b.email = %s GROUP BY a.id ORDER BY a.id DESC LIMIT 50', strtolower( (string) $email ) ), ARRAY_A );
	}
}

if ( ! function_exists( 'db_auc_account_html' ) ) {
	function db_auc_account_html( $email ) {
		$rows = db_auc_bids_for( $email );
		$h    = '<section class="db-wl-card"><h2>My bids</h2>';
		if ( ! $rows ) {
			return $h . '<p>You have not bid yet. See the <a href="' . esc_url( home_url( '/auctions/' ) ) . '">auctions running now</a>.</p></section>';
		}
		$h .= '<div class="dbsl-scroll"><table class="dbsl-tbl"><thead><tr><th scope="col">Domain</th><th scope="col">Price</th><th scope="col">Your maximum</th><th scope="col">Status</th><th scope="col"><span class="screen-reader-text">Action</span></th></tr></thead><tbody>';
		foreach ( $rows as $a ) {
			$mine   = strtolower( $email ) === $a['leader_email'];
			$action = '<a href="' . esc_url( get_permalink( (int) $a['post_id'] ) ) . '#dbauc">View</a>';
			if ( 'live' === $a['state'] ) {
				$status = $mine ? 'Winning' : 'Outbid';
			} elseif ( 'won' === $a['state'] ) {
				$deal   = function_exists( 'db_deal_get' ) && (int) $a['deal_id'] ? db_deal_get( (int) $a['deal_id'] ) : null;
				$isme   = $deal && strtolower( $deal->buyer_email ) === strtolower( $email );
				$status = $isme ? 'Won: awaiting payment' : 'Ended';
				if ( $isme && 'accepted' === $deal->state ) {
					$action = '<a href="' . esc_url( db_deal_pay_url( $deal ) ) . '"><strong>Pay now</strong></a>';
				}
			} elseif ( 'sold' === $a['state'] ) {
				$status = $mine ? 'Won and paid' : 'Ended';
			} elseif ( 'reserve_not_met' === $a['state'] ) {
				$status = $mine ? 'Highest bid, reserve not met' : 'Ended';
			} elseif ( 'cancelled' === $a['state'] ) {
				$status = 'Cancelled';
			} else {
				$status = 'Ended';
			}
			$h .= '<tr><td class="dbsl-dom">' . esc_html( $a['domain'] ) . '</td><td data-l="Price">' . esc_html( db_auc_money( $a['current_price'] ) ) . '</td><td data-l="Your maximum">' . esc_html( db_auc_money( $a['my_max'] ) ) . '</td><td data-l="Status">' . esc_html( $status ) . '</td><td>' . $action . '</td></tr>';
		}
		return $h . '</tbody></table></div></section>';
	}
}

/* ------------------------------------------------------- seller tab */

if ( ! function_exists( 'db_auc_seller_html' ) ) {
	/** The Auctions tab of /seller/: start one on a live name, follow and accept. */
	function db_auc_seller_html( array $seller ) {
		global $wpdb;
		$self  = esc_url( add_query_arg( 'view', 'auctions', home_url( '/seller/' ) ) );
		$t     = function_exists( 'db_seller_csrf' ) ? esc_attr( db_seller_csrf( $seller['email'] ) ) : '';
		$rules = db_auc_rules();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$mine = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . db_auc_t() . ' WHERE seller_id = %d ORDER BY id DESC LIMIT 50', (int) $seller['id'] ), ARRAY_A );
		$busy = array();
		foreach ( $mine as $a ) {
			if ( in_array( $a['state'], array( 'live', 'won' ), true ) ) {
				$busy[ (int) $a['post_id'] ] = true;
			}
		}
		$live = array_filter( function_exists( 'db_seller_listings' ) ? db_seller_listings( (int) $seller['id'] ) : array(), static function ( $r ) use ( $busy ) {
			return 'approved' === $r['review_status'] && (int) $r['post_id'] && empty( $busy[ (int) $r['post_id'] ] );
		} );
		$h  = '<section class="dbsl-card dbsl-sec"><h2>Run an auction</h2>';
		$h .= '<p>An auction can sell a name faster: buyers compete until the timer ends. Set a starting price, an optional reserve (the lowest price you accept, hidden unless you choose to show it) and an optional Buy It Now price. The winner pays us; you are paid as for any sale, after the usual commission.</p>';
		if ( ! $live ) {
			$h .= '<p class="dbsl-muted">You need a live name without a running auction. Names go live after their ownership record is verified.</p>';
		} else {
			$opts = '';
			foreach ( $live as $r ) {
				$opts .= '<option value="' . (int) $r['id'] . '">' . esc_html( $r['domain'] ) . '</option>';
			}
			$days = '';
			foreach ( (array) $rules['durations'] as $d ) {
				$days .= '<option value="' . (int) $d . '"' . ( (int) $d === (int) $rules['default_days'] ? ' selected' : '' ) . '>' . (int) $d . ' days</option>';
			}
			$h .= '<form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="auction_start"><input type="hidden" name="t" value="' . $t . '">'
				. '<div class="dbsl-two"><div class="dbsl-f"><label for="dbauc-l">Name</label><select id="dbauc-l" name="id" required>' . $opts . '</select></div>'
				. '<div class="dbsl-f"><label for="dbauc-d">Length</label><select id="dbauc-d" name="days">' . $days . '</select></div>'
				. '<div class="dbsl-f"><label for="dbauc-s">Starting price (USD)</label><input id="dbauc-s" name="start" type="text" inputmode="numeric" required placeholder="for example 500"></div>'
				. '<div class="dbsl-f"><label for="dbauc-r">Reserve (optional)</label><input id="dbauc-r" name="reserve" type="text" inputmode="numeric" placeholder="lowest price you accept"></div>'
				. '<div class="dbsl-f"><label for="dbauc-b">Buy It Now (optional)</label><input id="dbauc-b" name="bin" type="text" inputmode="numeric" placeholder="higher than the reserve"></div></div>'
				. '<label class="dbsl-check"><input type="checkbox" name="show_reserve" value="1"> Show my reserve on the auction page</label>'
				. '<p class="dbsl-muted">While the auction runs, the name cannot be bought at its fixed price. A winning bid is binding on you as the owner.</p>'
				. '<div class="dbsl-actions"><button type="submit" class="dbsl-btn">Start auction</button></div></form>';
		}
		$h .= '</section>';
		if ( $mine ) {
			$h .= '<section class="dbsl-card dbsl-sec"><h2>Your auctions</h2><div class="dbsl-scroll"><table class="dbsl-tbl"><thead><tr><th scope="col">Name</th><th scope="col">Price</th><th scope="col">Bids</th><th scope="col">Status</th><th scope="col"><span class="screen-reader-text">Action</span></th></tr></thead><tbody>';
			foreach ( $mine as $a ) {
				$labels = array( 'live' => 'Live, ' . db_auc_time_left( db_auc_ts( $a['ends_at'] ), time() ) . ' left', 'won' => 'Won: awaiting payment', 'sold' => 'Sold', 'reserve_not_met' => 'Ended below reserve', 'ended' => 'Ended without bids', 'unpaid' => 'Winner did not pay', 'cancelled' => 'Cancelled' );
				$act    = '';
				if ( 'reserve_not_met' === $a['state'] && db_auc_ts( $a['ended_at'] ) > time() - (int) $rules['below_reserve'] * DAY_IN_SECONDS ) {
					$act = '<form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="auction_accept"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="auc" value="' . (int) $a['id'] . '"><button type="submit" class="dbsl-btn">Accept ' . esc_html( db_auc_money( $a['current_price'] ) ) . '</button></form>';
				} elseif ( 'live' === $a['state'] && ! (int) $a['bids'] ) {
					$act = '<form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="auction_cancel"><input type="hidden" name="t" value="' . $t . '"><input type="hidden" name="auc" value="' . (int) $a['id'] . '"><button type="submit" class="dbsl-link">Cancel</button></form>';
				}
				$h .= '<tr><td class="dbsl-dom"><a href="' . esc_url( get_permalink( (int) $a['post_id'] ) ) . '#dbauc">' . esc_html( $a['domain'] ) . '</a></td><td data-l="Price">' . esc_html( db_auc_money( (int) $a['bids'] ? $a['current_price'] : $a['start_price'] ) ) . '</td><td data-l="Bids">' . (int) $a['bids'] . '</td><td data-l="Status">' . esc_html( $labels[ $a['state'] ] ?? $a['state'] ) . '</td><td>' . $act . '</td></tr>';
			}
			$h .= '</tbody></table></div></section>';
		}
		return $h;
	}
}

if ( ! function_exists( 'db_auc_seller_post' ) ) {
	/** Seller auction actions; null when $act is not one of them. */
	function db_auc_seller_post( $act, array $seller, array $p ) {
		if ( ! in_array( $act, array( 'auction_start', 'auction_accept', 'auction_cancel' ), true ) ) {
			return null;
		}
		if ( ! db_auc_enabled() ) {
			return array( array( 'bad', 'Auctions are not available right now.' ) );
		}
		if ( 'auction_start' === $act ) {
			$id  = (int) ( $p['id'] ?? 0 );
			$row = null;
			foreach ( db_seller_listings( (int) $seller['id'] ) as $r ) {
				if ( (int) $r['id'] === $id ) {
					$row = $r;
				}
			}
			if ( ! $row || 'approved' !== $row['review_status'] || ! (int) $row['post_id'] ) {
				return array( array( 'bad', 'That name is not live in your list.' ) );
			}
			if ( function_exists( 'db_wl_allow' ) && ! db_wl_allow( 'auc-start:' . (int) $seller['id'], 10, DAY_IN_SECONDS ) ) {
				return array( array( 'bad', 'You can start at most 10 auctions a day.' ) );
			}
			$r = db_auc_create( (int) $row['post_id'], $p, (int) $seller['id'], $seller['email'] );
			return is_int( $r ) ? array( array( 'ok', 'Your auction for ' . $row['domain'] . ' is live.' ) ) : array( array( 'bad', $r ) );
		}
		$a = db_auc_get( (int) ( $p['auc'] ?? 0 ) );
		if ( ! $a || (int) $a['seller_id'] !== (int) $seller['id'] ) {
			return array( array( 'bad', 'That auction is not one of yours.' ) );
		}
		if ( 'auction_accept' === $act ) {
			$why = db_auc_accept_below( $a );
			return '' === $why ? array( array( 'ok', 'Accepted. The bidder has ' . (int) db_auc_rules()['pay_days'] . ' days to pay.' ) ) : array( array( 'bad', $why ) );
		}
		if ( (int) $a['bids'] ) {
			return array( array( 'bad', 'An auction with bids cannot be cancelled. Contact us if something is wrong.' ) );
		}
		$why = db_auc_cancel( $a, 'Cancelled by the seller' );
		return '' === $why ? array( array( 'ok', 'The auction was cancelled.' ) ) : array( array( 'bad', $why ) );
	}
}

/* ------------------------------------------------------------- admin */

add_action( 'add_meta_boxes', function () {
	if ( db_auc_enabled() ) {
		add_meta_box( 'db-auction', 'Auction', 'db_auc_metabox', 'domain', 'side', 'high' );
	}
} );

if ( ! function_exists( 'db_auc_metabox' ) ) {
	function db_auc_metabox( $post ) {
		$a = db_auc_for_post( $post->ID );
		if ( $a && 'live' === $a['state'] ) {
			echo '<p><strong>Live</strong>: ' . esc_html( db_auc_money( (int) $a['bids'] ? $a['current_price'] : $a['start_price'] ) ) . ', ' . (int) $a['bids'] . ' bids, ends ' . esc_html( wp_date( 'M j g:i a', db_auc_ts( $a['ends_at'] ) ) ) . '.</p>';
			echo '<p><a href="' . esc_url( admin_url( 'tools.php?page=db-auctions' ) ) . '">Manage in Tools &gt; Auctions</a></p>';
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			echo '<p>Publish the name first.</p>';
			return;
		}
		echo '<p>Start an auction for this name. Fixed-price checkout is closed while it runs.</p>';
		echo '<p><a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'db-auctions', 'new' => (int) $post->ID ), admin_url( 'tools.php' ) ) ) . '">Set up auction</a></p>';
	}
}

add_action( 'admin_menu', function () {
	add_management_page( 'Auctions', 'Auctions', 'manage_options', 'db-auctions', 'db_auc_admin_page' );
} );

if ( ! function_exists( 'db_auc_admin_page' ) ) {
	function db_auc_admin_page() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rules = db_auc_rules();
		echo '<div class="wrap"><h1>Auctions</h1>';
		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$on = db_auc_enabled();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0 20px">';
		wp_nonce_field( 'db_auc_toggle' );
		echo '<input type="hidden" name="action" value="db_auc_toggle"><input type="hidden" name="on" value="' . ( $on ? '0' : '1' ) . '">';
		echo '<p>Auctions are <strong>' . ( $on ? 'on' : 'off' ) . '</strong>. ' . get_submit_button( $on ? 'Turn auctions off' : 'Turn auctions on', 'secondary', 'submit', false ) . '</p></form>';
		$new = isset( $_GET['new'] ) ? (int) $_GET['new'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$np  = $new ? get_post( $new ) : null;
		echo '<h2>Start an auction (our own names)</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'db_auc_start' );
		echo '<input type="hidden" name="action" value="db_auc_start"><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="dbauc-dom">Domain</label></th><td><input id="dbauc-dom" name="domain" type="text" class="regular-text" required value="' . esc_attr( $np ? strtolower( $np->post_title ) : '' ) . '" placeholder="example.com"></td></tr>';
		echo '<tr><th scope="row"><label for="dbauc-st">Starting price</label></th><td><input id="dbauc-st" name="start" type="number" min="1" step="1" required></td></tr>';
		echo '<tr><th scope="row"><label for="dbauc-rs">Reserve (optional)</label></th><td><input id="dbauc-rs" name="reserve" type="number" min="1" step="1"> <label><input type="checkbox" name="show_reserve" value="1"> Show the reserve</label></td></tr>';
		echo '<tr><th scope="row"><label for="dbauc-bn">Buy It Now (optional)</label></th><td><input id="dbauc-bn" name="bin" type="number" min="1" step="1"></td></tr>';
		echo '<tr><th scope="row"><label for="dbauc-dy">Length</label></th><td><select id="dbauc-dy" name="days">';
		foreach ( (array) $rules['durations'] as $d ) {
			echo '<option value="' . (int) $d . '"' . selected( (int) $d, (int) $rules['default_days'], false ) . '>' . (int) $d . ' days</option>';
		}
		echo '</select></td></tr></table>';
		submit_button( 'Start auction' );
		echo '</form>';

		echo '<h2>All auctions</h2>';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( 'SELECT * FROM ' . db_auc_t() . ' ORDER BY id DESC LIMIT 200', ARRAY_A );
		if ( ! $rows ) {
			echo '<p>No auctions yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Domain</th><th>Owner</th><th>Start / reserve / Buy It Now</th><th>Price</th><th>Bids</th><th>Ends</th><th>Status</th><th>Winner</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $rows as $a ) {
				$owner = db_auc_owner_email( $a );
				$acts  = array();
				if ( 'live' === $a['state'] ) {
					$acts[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=db_auc_admin&do=end&auc=' . (int) $a['id'] ), 'db_auc_admin_' . (int) $a['id'] ) ) . '">End now</a>';
					$acts[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=db_auc_admin&do=cancel&auc=' . (int) $a['id'] ), 'db_auc_admin_' . (int) $a['id'] ) ) . '" onclick="return confirm(\'Cancel this auction and tell every bidder?\')">Cancel</a>';
				}
				if ( 'reserve_not_met' === $a['state'] ) {
					$acts[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=db_auc_admin&do=accept&auc=' . (int) $a['id'] ), 'db_auc_admin_' . (int) $a['id'] ) ) . '">Accept highest bid</a>';
				}
				$winner = in_array( $a['state'], array( 'won', 'sold', 'reserve_not_met' ), true ) ? $a['leader_email'] : '';
				if ( (int) $a['deal_id'] && function_exists( 'db_deal_get' ) ) {
					$d      = db_deal_get( (int) $a['deal_id'] );
					$winner = $d ? $d->buyer_email . ' (' . $d->state . ')' : $winner;
				}
				echo '<tr><td><a href="' . esc_url( get_permalink( (int) $a['post_id'] ) ) . '">' . esc_html( $a['domain'] ) . '</a></td><td>' . esc_html( $owner ? $owner : 'Domain Brothers' ) . '</td><td>' . esc_html( db_auc_money( $a['start_price'] ) . ' / ' . ( (int) $a['reserve'] ? db_auc_money( $a['reserve'] ) . ( (int) $a['show_reserve'] ? ' (shown)' : '' ) : 'none' ) . ' / ' . ( (int) $a['bin_price'] ? db_auc_money( $a['bin_price'] ) : 'none' ) ) . '</td><td>' . esc_html( db_auc_money( $a['current_price'] ) ) . ( (int) $a['leader_max'] ? '<br><small>top maximum ' . esc_html( db_auc_money( $a['leader_max'] ) ) . '</small>' : '' ) . '</td><td>' . (int) $a['bids'] . '</td><td>' . esc_html( wp_date( 'M j, Y g:i a', db_auc_ts( $a['ends_at'] ) ) ) . '</td><td>' . esc_html( $a['state'] . ( $a['note'] ? ' (' . $a['note'] . ')' : '' ) ) . '</td><td>' . esc_html( $winner ) . '</td><td>' . implode( ' | ', $acts ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
		}
		echo '<h2>Rules</h2><p>Lengths: ' . esc_html( implode( ', ', (array) $rules['durations'] ) ) . ' days. A bid in the last ' . (int) $rules['extend_window'] . ' minutes moves the end to ' . (int) $rules['extend_by'] . ' minutes after the bid. Winners pay within ' . (int) $rules['pay_days'] . ' days; then up to ' . (int) $rules['second_chances'] . ' next bidders are offered the name. Owners may accept a below-reserve high bid for ' . (int) $rules['below_reserve'] . ' days. Bid steps: $5 under $100, $10 under $1,000, $50 under $5,000, $100 under $25,000, $250 under $100,000, then $500.</p>';
		echo '</div>';
	}
}

add_action( 'admin_post_db_auc_toggle', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'db_auc_toggle' );
	$on = isset( $_POST['on'] ) && '1' === (string) $_POST['on'];
	update_option( 'db_auctions_on', $on ? '1' : '0' );
	if ( ! $on ) {
		wp_clear_scheduled_hook( 'db_auc_sweep' );
	}
	do_action( 'db_page_cache_purge_all' );
	wp_safe_redirect( add_query_arg( array( 'page' => 'db-auctions', 'msg' => rawurlencode( 'Auctions are now ' . ( $on ? 'on' : 'off' ) . '.' ) ), admin_url( 'tools.php' ) ) );
	exit;
} );

add_action( 'admin_post_db_auc_start', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'db_auc_start' );
	$p      = wp_unslash( $_POST );
	$domain = strtolower( trim( sanitize_text_field( (string) ( $p['domain'] ?? '' ) ) ) );
	$post   = function_exists( 'db_listing_post_for_domain' ) ? db_listing_post_for_domain( $domain ) : null;
	if ( ! $post ) {
		$msg = 'No live domain page was found for ' . $domain . '.';
	} else {
		$seller = (int) get_post_meta( $post->ID, 'db_seller_id', true );
		$r      = db_auc_create( $post->ID, $p, $seller, wp_get_current_user()->user_email );
		$msg    = is_int( $r ) ? 'The auction for ' . $domain . ' is live.' : $r;
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'db-auctions', 'msg' => rawurlencode( $msg ) ), admin_url( 'tools.php' ) ) );
	exit;
} );

add_action( 'admin_post_db_auc_admin', function () {
	$id = isset( $_GET['auc'] ) ? (int) $_GET['auc'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.' );
	}
	check_admin_referer( 'db_auc_admin_' . $id );
	$a  = db_auc_get( $id );
	$do = isset( $_GET['do'] ) ? sanitize_key( wp_unslash( $_GET['do'] ) ) : '';
	$msg = 'Nothing changed.';
	if ( $a && 'end' === $do && 'live' === $a['state'] ) {
		global $wpdb;
		$wpdb->update( db_auc_t(), array( 'ends_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		db_auc_finalize( db_auc_get( $id ) );
		$msg = 'The auction ended: ' . db_auc_get( $id )['state'] . '.';
	} elseif ( $a && 'cancel' === $do ) {
		$why = db_auc_cancel( $a, 'Cancelled by Domain Brothers' );
		$msg = '' === $why ? 'The auction was cancelled and bidders were told.' : $why;
	} elseif ( $a && 'accept' === $do ) {
		$why = db_auc_accept_below( $a );
		$msg = '' === $why ? 'Accepted. The bidder was emailed a payment link.' : $why;
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'db-auctions', 'msg' => rawurlencode( $msg ) ), admin_url( 'tools.php' ) ) );
	exit;
} );
