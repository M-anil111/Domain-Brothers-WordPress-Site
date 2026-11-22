<?php
/**
 * Seller reliability: buyers are never left waiting on an owner who does not reply.
 *
 *  - Offers waiting for a seller: reminders on day 1, 3 and 5 (the broker is
 *    copied from day 3; day 5 is a final notice). On day 7 the seller gets a
 *    strike, the buyer is told the owner has not responded and our broker
 *    takes the offer over (it stays open; the seller can still answer).
 *  - A second strike within 90 days, or one offer ignored for 10 days, takes
 *    the account offline: every live name is unpublished (its page and URL are
 *    kept), open offers go to the broker and their buyers are told. The seller
 *    asks to be reactivated from the seller page; the broker approves it in
 *    WP Admin and every name comes back with one click.
 *  - Sold names: the seller has 3 days to start the transfer (reminders on
 *    day 1 and 2); after that, a strike, the payout is held and the broker steps in.
 *  - Seller tools: pause all listings (holiday, no strike), resume, mark a
 *    name "sold elsewhere" (comes down, no penalty).
 *  - Expiring names: a weekly registry check warns 30 days before expiry and
 *    takes expired names down.
 *  - Abuse: at most 5 ownership claims a day per account; a new seller whose
 *    phone matches a suspended account is flagged for the broker.
 *  - Account deletion: live names come down, open offers go to the broker,
 *    payouts already due are still paid.
 *
 * All limits come from db_seller_rule() (defaults in db_seller_rules_default(),
 * changed in Tools > Seller listings). Rules are tested offline in
 * tests/seller/seller-test.php.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_seller_rule' ) ) {
	function db_seller_rule( $key ) {
		$def = db_seller_rules_default();
		$opt = get_option( 'db_seller_rules', array() );
		$opt = is_array( $opt ) ? $opt : array();
		return array_key_exists( $key, $opt ) ? $opt[ $key ] : ( $def[ $key ] ?? null );
	}
}

if ( ! function_exists( 'db_seller_by_id' ) ) {
	function db_seller_by_id( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . db_seller_table( 'sellers' ) . ' WHERE id = %d', (int) $id ), ARRAY_A );
		return is_array( $r ) ? $r : null;
	}
}

/* -------------------------------------------------- offline and online */

if ( ! function_exists( 'db_seller_take_offline' ) ) {
	/** Unpublishes every live name of a seller, remembering why so they can come back. */
	function db_seller_take_offline( $seller_id, $reason ) {
		global $wpdb;
		$l = db_seller_table( 'listings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$l} WHERE seller_id = %d AND review_status = 'approved'", (int) $seller_id ), ARRAY_A );
		$n    = 0;
		foreach ( $rows as $r ) {
			if ( (int) $r['post_id'] && 'publish' === get_post_status( (int) $r['post_id'] ) ) {
				wp_update_post( array( 'ID' => (int) $r['post_id'], 'post_status' => 'draft' ) );
				++$n;
			}
			$wpdb->update( $l, array( 'offline_reason' => substr( (string) $reason, 0, 20 ) ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		do_action( 'db_page_cache_purge_all' );
		return $n;
	}
}

if ( ! function_exists( 'db_seller_bring_online' ) ) {
	function db_seller_bring_online( $seller_id ) {
		global $wpdb;
		$l = db_seller_table( 'listings' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$l} WHERE seller_id = %d AND review_status = 'approved' AND offline_reason <> ''", (int) $seller_id ), ARRAY_A );
		foreach ( $rows as $r ) {
			if ( (int) $r['post_id'] ) {
				wp_update_post( array( 'ID' => (int) $r['post_id'], 'post_status' => 'publish' ) );
			}
			$wpdb->update( $l, array( 'offline_reason' => '' ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		do_action( 'db_page_cache_purge_all' );
		return count( $rows );
	}
}

if ( ! function_exists( 'db_seller_open_deals' ) ) {
	function db_seller_open_deals( $seller_id ) {
		if ( ! function_exists( 'db_seller_deals' ) ) {
			return array();
		}
		return array_values( array_filter( db_seller_deals( (int) $seller_id ), static function ( $d ) { return in_array( $d->state, array( 'open', 'countered' ), true ); } ) );
	}
}

if ( ! function_exists( 'db_seller_tell_buyer_broker' ) ) {
	/** Tells a buyer the owner is not available and our broker is taking the offer over. */
	function db_seller_tell_buyer_broker( $deal, $why ) {
		if ( ! $deal || ! is_email( $deal->buyer_email ) || ! function_exists( 'db_plan_send' ) || ! function_exists( 'db_plan_email_shell' ) ) {
			return;
		}
		global $wpdb;
		// One handover per deal: once a broker has it, the buyer is not emailed again.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . db_deal_table( 'events' ) . " WHERE deal_id = %d AND actor = 'system' AND action = 'note' AND message LIKE %s", (int) $deal->id, 'Broker took over%' ) ) ) {
			return;
		}
		$texts = array(
			'unresponsive' => 'The owner of <strong>' . esc_html( $deal->domain ) . '</strong> has not responded to your offer yet.',
			'paused'       => 'The owner of <strong>' . esc_html( $deal->domain ) . '</strong> is away at the moment.',
			'suspended'    => 'The owner of <strong>' . esc_html( $deal->domain ) . '</strong> is not available at the moment.',
			'deleted'      => 'The owner of <strong>' . esc_html( $deal->domain ) . '</strong> has closed their account.',
		);
		$html = db_plan_email_shell( 'An update on your offer for ' . $deal->domain, '<p style="font-size:15px;color:#374151;margin:0 0 12px;">' . ( $texts[ $why ] ?? $texts['unresponsive'] ) . ' One of our brokers is now handling your offer and will contact you within one business day. If this name does not work out, we will suggest similar names that are available now.</p>', array( 'Domain' => $deal->domain, 'Your offer' => db_deal_money( $deal->amount ) ), 'Browse similar names', home_url( '/all-domains/' ), 'neutral' );
		db_plan_send( $deal->buyer_email, 'An update on your offer for ' . $deal->domain, $html );
		db_deal_log( (int) $deal->id, 'system', 'note', null, 'Broker took over: owner ' . $why . '.' );
	}
}

/* ------------------------------------------------- strikes and suspension */

if ( ! function_exists( 'db_seller_strikes_in_window' ) ) {
	function db_seller_strikes_in_window( $seller_id ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - (int) db_seller_rule( 'strike_window' ) * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}db_seller_audit WHERE seller_id = %d AND action = 'strike' AND created_at >= %s", (int) $seller_id, $since ) );
	}
}

if ( ! function_exists( 'db_seller_add_strike' ) ) {
	function db_seller_add_strike( array $seller, $why, $domain = '' ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . db_seller_table( 'sellers' ) . ' SET strikes = strikes + 1 WHERE id = %d', (int) $seller['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( function_exists( 'db_seller_audit' ) ) {
			db_seller_audit( (int) $seller['id'], $domain, 'strike', $why, 'system' );
		}
		return db_seller_strikes_in_window( (int) $seller['id'] );
	}
}

if ( ! function_exists( 'db_seller_suspend' ) ) {
	/** Takes a seller account and all its names offline until the broker reactivates it. */
	function db_seller_suspend( array $seller, $reason, $by = 'system' ) {
		global $wpdb;
		$status = 'admin' === $by ? 'suspended' : 'suspended_unresp';
		$wpdb->update( db_seller_table( 'sellers' ), array( 'status' => $status, 'suspended_at' => current_time( 'mysql', true ), 'status_note' => substr( (string) $reason, 0, 255 ) ), array( 'id' => (int) $seller['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n = db_seller_take_offline( (int) $seller['id'], 'suspended' );
		foreach ( db_seller_open_deals( (int) $seller['id'] ) as $d ) {
			db_seller_tell_buyer_broker( $d, 'suspended' );
		}
		if ( function_exists( 'db_seller_audit' ) ) {
			db_seller_audit( (int) $seller['id'], '', 'suspended', $reason . ' (' . $n . ' names offline)', 'admin' === $by ? '' : 'system' );
		}
		if ( is_email( $seller['email'] ) ) {
			db_seller_mail( $seller['email'], 'Your Domain Brothers listings are offline', '<p>Your seller account and its ' . $n . ' live ' . ( 1 === $n ? 'name have' : 'names have' ) . ' been taken offline.</p><p>Reason: ' . esc_html( $reason ) . '</p><p>Buyers rely on owners answering offers, so we pause accounts that stop responding. Your names, prices and history are kept. To turn your account back on, <a href="' . esc_url( home_url( '/seller/' ) ) . '">open your seller page</a> and send a reactivation request; our broker reviews it within one business day.</p>' );
		}
		if ( function_exists( 'db_plan_notify_admin' ) ) {
			db_plan_notify_admin( 'Seller account taken offline: ' . $seller['email'], array( 'Seller' => $seller['email'], 'Reason' => $reason, 'Names offline' => $n, 'Review' => admin_url( 'tools.php?page=db-seller-review#dbsl-sellers' ) ) );
		}
		return $n;
	}
}

if ( ! function_exists( 'db_seller_reactivate' ) ) {
	function db_seller_reactivate( array $seller, $reset_strikes = false ) {
		global $wpdb;
		$data = array( 'status' => 'active', 'suspended_at' => null, 'status_note' => '', 'reactivate_note' => '', 'reactivate_at' => null );
		if ( $reset_strikes ) {
			$data['strikes'] = 0;
		}
		$wpdb->update( db_seller_table( 'sellers' ), $data, array( 'id' => (int) $seller['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n = db_seller_bring_online( (int) $seller['id'] );
		if ( $reset_strikes && function_exists( 'db_seller_audit' ) ) {
			// Strikes before this moment no longer count toward the window.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}db_seller_audit SET action = 'strike_cleared' WHERE seller_id = %d AND action = 'strike'", (int) $seller['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( function_exists( 'db_seller_audit' ) ) {
			db_seller_audit( (int) $seller['id'], '', 'reactivated', $n . ' names back online' . ( $reset_strikes ? ', strikes reset' : '' ) );
		}
		if ( is_email( $seller['email'] ) ) {
			db_seller_mail( $seller['email'], 'Your Domain Brothers listings are back online', '<p>Your seller account is active again and ' . $n . ' ' . ( 1 === $n ? 'name is' : 'names are' ) . ' back on the site. Please answer offers within 24 hours: we remind you on day 1, 3 and 5.</p><p><a href="' . esc_url( home_url( '/seller/' ) ) . '">Open your seller page</a></p>' );
		}
		return $n;
	}
}

/* ------------------------------------------------ the hourly offer sweep */

remove_action( 'db_seller_verify_cron', 'db_seller_offer_sweep', 20 ); // replaced by the schedule below
add_action( 'db_seller_verify_cron', 'db_seller_reliability_sweep', 21 );

if ( ! function_exists( 'db_seller_reliability_sweep' ) ) {
	/**
	 * Hourly. Offers waiting for a seller get reminders, then a strike and a
	 * broker takeover; repeated misses take the account offline. Sold names
	 * waiting for a transfer get the same treatment. Returns counts (tests).
	 */
	function db_seller_reliability_sweep() {
		global $wpdb;
		$out = array( 'reminders' => 0, 'strikes' => 0, 'suspended' => 0, 'transfer' => 0 );
		if ( ! function_exists( 'db_deal_table' ) || ( function_exists( 'db_seller_enabled' ) && ! db_seller_enabled() ) ) {
			return $out;
		}
		$l     = db_seller_table( 'listings' );
		$s     = db_seller_table( 'sellers' );
		$d     = db_deal_table();
		$e     = db_deal_table( 'events' );
		$days  = (array) db_seller_rule( 'remind_days' );
		$state = (array) get_option( 'db_seller_nudges', array() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT d.*, l.seller_id, s.status AS seller_status, TIMESTAMPDIFF(MINUTE, (SELECT MAX(ev.created_at) FROM {$e} ev WHERE ev.deal_id = d.id AND ev.actor <> 'system'), NOW()) / 60 AS idle_h FROM {$d} d JOIN {$l} l ON l.domain = d.domain AND l.review_status = 'approved' JOIN {$s} s ON s.id = l.seller_id WHERE d.state = 'open' LIMIT 300" );
		$worst = array();
		foreach ( $rows as $deal ) {
			$id  = (int) $deal->id;
			$st  = $state[ $id ] ?? array( 'n' => 0, 'strike' => 0, 'away' => 0 );
			$idle = (float) $deal->idle_h;
			if ( 'active' !== $deal->seller_status ) {
				if ( ! $st['away'] ) { // paused or offline: the broker handles it, the buyer hears once
					db_seller_tell_buyer_broker( $deal, 'paused' === $deal->seller_status ? 'paused' : 'suspended' );
					$st['away'] = 1;
				}
				$state[ $id ] = $st;
				continue;
			}
			$listing = db_seller_listing_for_domain( $deal->domain );
			$stage   = db_seller_nudge_stage( $idle, $days );
			if ( $stage > (int) $st['n'] && ! $st['strike'] && $listing ) {
				$final = $stage >= count( $days );
				$subj  = $final ? 'Final notice: answer the offer on ' . $deal->domain . ' within 48 hours' : 'Reminder: an offer on ' . $deal->domain . ' is waiting for you';
				$text  = 'A buyer offered ' . db_deal_money( $deal->amount ) . ' for ' . $deal->domain . ' ' . (int) floor( $idle / 24 ) . ' days ago and is waiting for your answer. Accept, counter or decline it from your seller page.'
					. ( $final ? ' If there is no answer within 48 hours the buyer is told you have not responded, our broker takes the offer over and your account gets a strike. Two strikes in ' . (int) db_seller_rule( 'strike_window' ) . ' days take all your listings offline.' : '' );
				db_seller_offer_mail( $listing, $deal, $subj, $text );
				if ( $stage >= 2 && function_exists( 'db_plan_notify_admin' ) ) {
					db_plan_notify_admin( 'Seller slow to answer an offer on ' . $deal->domain, array( 'Domain' => $deal->domain, 'Seller' => $listing['seller_email'], 'Waiting' => (int) floor( $idle / 24 ) . ' days', 'Reminder' => $stage . ' of ' . count( $days ), 'Open' => admin_url( 'admin.php?page=db-offers&deal=' . $id ) ) );
				}
				$st['n'] = $stage;
				++$out['reminders'];
			}
			if ( ! $st['strike'] && db_seller_strike_due( $idle, (int) db_seller_rule( 'strike_days' ) ) ) {
				$seller = db_seller_by_id( (int) $deal->seller_id );
				if ( $seller ) {
					$count = db_seller_add_strike( $seller, 'Offer on ' . $deal->domain . ' unanswered for ' . (int) floor( $idle / 24 ) . ' days', $deal->domain );
					db_seller_tell_buyer_broker( $deal, 'unresponsive' );
					if ( $listing ) {
						db_seller_offer_mail( $listing, $deal, 'Strike: the offer on ' . $deal->domain . ' was not answered', 'You did not answer the offer of ' . db_deal_money( $deal->amount ) . ' on ' . $deal->domain . ' within ' . (int) db_seller_rule( 'strike_days' ) . ' days. The buyer has been told and our broker is handling the offer. This is strike ' . $count . ' of ' . (int) db_seller_rule( 'suspend_strikes' ) . ' in ' . (int) db_seller_rule( 'strike_window' ) . ' days; at ' . (int) db_seller_rule( 'suspend_strikes' ) . ' your listings go offline.' );
					}
					if ( function_exists( 'db_plan_notify_admin' ) ) {
						db_plan_notify_admin( 'Broker takeover: offer on ' . $deal->domain, array( 'Domain' => $deal->domain, 'Seller' => $seller['email'], 'Buyer offer' => db_deal_money( $deal->amount ), 'Strikes in window' => $count, 'Open' => admin_url( 'admin.php?page=db-offers&deal=' . $id ) ) );
					}
					$st['strike'] = 1;
					++$out['strikes'];
				}
			}
			$state[ $id ] = $st;
			$worst[ (int) $deal->seller_id ] = max( $worst[ (int) $deal->seller_id ] ?? 0, $idle );
		}
		foreach ( $worst as $sid => $max_idle ) {
			$seller = db_seller_by_id( $sid );
			if ( $seller && 'active' === $seller['status'] && db_seller_should_suspend( db_seller_strikes_in_window( $sid ), $max_idle, (int) db_seller_rule( 'suspend_strikes' ), (int) db_seller_rule( 'suspend_idle_days' ) ) ) {
				db_seller_suspend( $seller, 'Offers on your names were not answered (' . db_seller_strikes_in_window( $sid ) . ' strikes in ' . (int) db_seller_rule( 'strike_window' ) . ' days, longest wait ' . (int) floor( $max_idle / 24 ) . ' days).' );
				++$out['suspended'];
			}
		}
		update_option( 'db_seller_nudges', array_slice( $state, -1000, null, true ), false );
		$out['transfer'] = db_seller_transfer_sweep();
		return $out;
	}
}

if ( ! function_exists( 'db_seller_transfer_sweep' ) ) {
	/** Sold names waiting for the seller to start the transfer. */
	function db_seller_transfer_sweep() {
		global $wpdb;
		if ( ! function_exists( 'db_seller_payout_apply' ) ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_results( "SELECT p.*, TIMESTAMPDIFF(MINUTE, p.updated_at, UTC_TIMESTAMP()) / 60 AS wait_h FROM {$wpdb->prefix}db_seller_payouts p WHERE p.state = 'waiting_transfer' LIMIT 200", ARRAY_A );
		$sent = (array) get_option( 'db_seller_transfer_nudges', array() );
		$n    = 0;
		foreach ( $rows as $p ) {
			$stage = db_seller_transfer_stage( (float) $p['wait_h'], (int) db_seller_rule( 'transfer_days' ) );
			$done  = $sent[ (int) $p['id'] ] ?? '';
			if ( '' === $stage || $stage === $done || ( 'remind1' === $stage && 'remind2' === $done ) ) {
				continue;
			}
			$seller = db_seller_by_id( (int) $p['seller_id'] );
			if ( ! $seller ) {
				continue;
			}
			if ( 'strike' === $stage ) {
				db_seller_add_strike( $seller, 'Transfer of ' . $p['domain'] . ' not started within ' . (int) db_seller_rule( 'transfer_days' ) . ' days', $p['domain'] );
				db_seller_payout_apply( $p, 'hold', 'Transfer not started within ' . (int) db_seller_rule( 'transfer_days' ) . ' days', 'system' );
				if ( function_exists( 'db_plan_notify_admin' ) ) {
					db_plan_notify_admin( 'Transfer overdue: ' . $p['domain'], array( 'Domain' => $p['domain'], 'Seller' => $seller['email'], 'Payout' => 'held', 'Open' => admin_url( 'tools.php?page=db-seller-payouts' ) ) );
				}
			} elseif ( is_email( $seller['email'] ) ) {
				db_seller_mail( $seller['email'], ( 'remind2' === $stage ? 'Last reminder' : 'Reminder' ) . ': please transfer ' . $p['domain'], '<p>The buyer of <strong>' . esc_html( $p['domain'] ) . '</strong> is waiting for the transfer. Please start it now using the steps our broker sent (authorization code, or a push to the buyer\'s registrar).</p><p>If the transfer has not started within ' . (int) db_seller_rule( 'transfer_days' ) . ' days of the sale, your payout is held and your account gets a strike.</p>' );
			}
			$sent[ (int) $p['id'] ] = $stage;
			++$n;
		}
		update_option( 'db_seller_transfer_nudges', array_slice( $sent, -500, null, true ), false );
		return $n;
	}
}

/* ------------------------------------------------------ expiring names */

add_action( 'init', function () {
	if ( function_exists( 'db_seller_enabled' ) && db_seller_enabled() && ! wp_next_scheduled( 'db_seller_expiry_cron' ) ) {
		wp_schedule_event( time() + 1800, 'daily', 'db_seller_expiry_cron' );
	}
} );
add_action( 'db_seller_expiry_cron', 'db_seller_expiry_run' );

if ( ! function_exists( 'db_seller_expiry_run' ) ) {
	/** Live names expiring soon get a warning; expired names come down. Each name is looked up at most weekly. */
	function db_seller_expiry_run( $limit = 40 ) {
		global $wpdb;
		if ( function_exists( 'db_seller_enabled' ) && ! db_seller_enabled() ) {
			return array( 'warn' => 0, 'expired' => 0, 'looked_up' => 0 );
		}
		$l    = db_seller_table( 'listings' );
		$s    = db_seller_table( 'sellers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT l.*, s.email FROM {$l} l JOIN {$s} s ON s.id = l.seller_id WHERE l.review_status = 'approved' ORDER BY l.id LIMIT 500", ARRAY_A );
		$out  = array( 'warn' => 0, 'expired' => 0, 'looked_up' => 0 );
		$warned = (array) get_option( 'db_seller_expiry_warned', array() );
		foreach ( $rows as $r ) {
			$key = 'db_seller_exp_' . md5( $r['domain'] );
			$exp = get_transient( $key );
			if ( false === $exp ) {
				if ( $out['looked_up'] >= $limit ) {
					continue;
				}
				$pre = apply_filters( 'db_seller_expiry_lookup', null, $r['domain'] ); // tests only
				if ( null !== $pre ) {
					$exp = (int) $pre;
				} else {
					$res = wp_remote_get( 'https://rdap.org/domain/' . rawurlencode( $r['domain'] ), array( 'timeout' => 5, 'redirection' => 3 ) );
					$exp = is_wp_error( $res ) ? 0 : db_seller_rdap_expiry( wp_remote_retrieve_body( $res ) );
				}
				set_transient( $key, (int) $exp, WEEK_IN_SECONDS );
				++$out['looked_up'];
			}
			$act = db_seller_expiry_action( (int) $exp, time(), (int) db_seller_rule( 'expiry_warn_days' ) );
			if ( 'warn' === $act && empty( $warned[ (int) $r['id'] ] ) ) {
				db_seller_mail( $r['email'], $r['domain'] . ' expires on ' . wp_date( 'F j, Y', (int) $exp ), '<p>Your name <strong>' . esc_html( $r['domain'] ) . '</strong> is registered until ' . esc_html( wp_date( 'F j, Y', (int) $exp ) ) . '. Renew it at your registrar so it stays listed and can be sold; expired names are taken off the site.</p>' );
				$warned[ (int) $r['id'] ] = time();
				++$out['warn'];
			} elseif ( 'expired' === $act ) {
				db_seller_remove( $r );
				$wpdb->update( $l, array( 'review_note' => 'Taken off: the registration expired on ' . gmdate( 'Y-m-d', (int) $exp ) ), array( 'id' => (int) $r['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				db_seller_mail( $r['email'], $r['domain'] . ' was taken off Domain Brothers', '<p>The registration of <strong>' . esc_html( $r['domain'] ) . '</strong> has expired, so the name was taken off the site. Renew it and list it again whenever you like.</p>' );
				if ( function_exists( 'db_seller_audit' ) ) {
					db_seller_audit( (int) $r['seller_id'], $r['domain'], 'expired', 'Registration expired; taken off', 'system' );
				}
				++$out['expired'];
			}
		}
		update_option( 'db_seller_expiry_warned', array_slice( $warned, -2000, null, true ), false );
		return $out;
	}
}

/* ---------------------------------------------- seller-side controls */

if ( ! function_exists( 'db_seller_reliability_post' ) ) {
	/**
	 * Seller actions that also work while an account is offline: pause, resume,
	 * sold elsewhere, reactivation request. Returns messages, or null when the
	 * action is not one of these.
	 */
	function db_seller_reliability_post( $act, array $seller, array $p ) {
		global $wpdb;
		$l = db_seller_table( 'listings' );
		if ( 'pause' === $act && 'active' === $seller['status'] ) {
			$wpdb->update( db_seller_table( 'sellers' ), array( 'status' => 'paused', 'status_note' => 'Paused by the seller' ), array( 'id' => (int) $seller['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n = db_seller_take_offline( (int) $seller['id'], 'paused' );
			foreach ( db_seller_open_deals( (int) $seller['id'] ) as $d ) {
				db_seller_tell_buyer_broker( $d, 'paused' );
			}
			db_seller_audit( (int) $seller['id'], '', 'paused', $n . ' names offline', 'seller' );
			return array( array( 'ok', 'Your listings are paused. ' . $n . ' ' . ( 1 === $n ? 'name is' : 'names are' ) . ' offline until you resume. Our broker handles any open offers meanwhile.' ) );
		}
		if ( 'resume' === $act && 'paused' === $seller['status'] ) {
			$wpdb->update( db_seller_table( 'sellers' ), array( 'status' => 'active', 'status_note' => '' ), array( 'id' => (int) $seller['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n = db_seller_bring_online( (int) $seller['id'] );
			db_seller_audit( (int) $seller['id'], '', 'resumed', $n . ' names back online', 'seller' );
			return array( array( 'ok', 'Welcome back. ' . $n . ' ' . ( 1 === $n ? 'name is' : 'names are' ) . ' online again.' ) );
		}
		if ( 'reactivate_request' === $act && in_array( $seller['status'], array( 'suspended', 'suspended_unresp' ), true ) ) {
			$note = mb_substr( sanitize_textarea_field( (string) ( $p['note'] ?? '' ) ), 0, 1000 );
			if ( '' === trim( $note ) ) {
				return array( array( 'bad', 'Tell us briefly what happened and how you will answer offers from now on.' ) );
			}
			$wpdb->update( db_seller_table( 'sellers' ), array( 'reactivate_note' => $note, 'reactivate_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $seller['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			db_seller_audit( (int) $seller['id'], '', 'reactivate_request', mb_substr( $note, 0, 200 ), 'seller' );
			if ( function_exists( 'db_plan_notify_admin' ) ) {
				db_plan_notify_admin( 'Reactivation request: ' . $seller['email'], array( 'Seller' => $seller['email'], 'Message' => $note, 'Review' => admin_url( 'tools.php?page=db-seller-review#dbsl-sellers' ) ) );
			}
			return array( array( 'ok', 'Thanks. Our broker will review your request within one business day and email you.' ) );
		}
		if ( 'sold_elsewhere' === $act ) {
			$id  = (int) ( $p['id'] ?? 0 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$l} WHERE id = %d AND seller_id = %d", $id, (int) $seller['id'] ), ARRAY_A );
			if ( ! $row || 'removed' === $row['review_status'] ) {
				return array( array( 'bad', 'That name is not in your list.' ) );
			}
			db_seller_remove( $row );
			$wpdb->update( $l, array( 'review_note' => 'Sold elsewhere (reported by the seller)' ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( db_seller_open_deals( (int) $seller['id'] ) as $d ) {
				if ( $d->domain === $row['domain'] && function_exists( 'db_deal_apply' ) ) {
					db_deal_apply( $d, 'seller', 'decline', null, 'The owner has sold this name elsewhere.' );
				}
			}
			db_seller_audit( (int) $seller['id'], $row['domain'], 'sold_elsewhere', 'Reported by the seller', 'seller' );
			return array( array( 'ok', $row['domain'] . ' was taken off the site. Thanks for letting us know.' ) );
		}
		return null;
	}
}

if ( ! function_exists( 'db_seller_status_screen' ) ) {
	/** What a paused or offline seller sees instead of the dashboard. */
	function db_seller_status_screen( $email, array $seller, array $msgs ) {
		$t    = esc_attr( db_seller_csrf( $email ) );
		$self = esc_url( home_url( '/seller/' ) );
		$h    = '<div class="dbsa"><div class="dbsl">';
		foreach ( $msgs as $m ) {
			$h .= '<p class="dbsl-msg dbsl-msg--' . esc_attr( $m[0] ) . '" role="status">' . esc_html( $m[1] ) . '</p>';
		}
		if ( 'paused' === $seller['status'] ) {
			return $h . '<section class="dbsl-card dbsl-sec"><h2>Your listings are paused</h2><p>Your names are offline and buyers cannot make new offers. Our broker handles any open offers while you are away. Nothing else changes: prices, history and payouts are kept.</p>'
				. '<form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="resume"><input type="hidden" name="t" value="' . $t . '"><button type="submit" class="dbsl-btn">Resume my listings</button></form></section></div></div>';
		}
		$h .= '<section class="dbsl-card dbsl-sec dbsa-off"><h2>Your seller account is offline</h2><p>' . ( '' !== (string) $seller['status_note'] ? esc_html( $seller['status_note'] ) : 'Our team has paused your account.' ) . '</p>'
			. '<p>Your names are hidden from buyers and open offers are being handled by our broker. Your names, prices and history are kept, and payouts already due will still be paid.</p>';
		if ( ! empty( $seller['reactivate_at'] ) ) {
			$h .= '<p class="dbsl-msg dbsl-msg--ok">We received your reactivation request on ' . esc_html( wp_date( 'F j, Y', strtotime( $seller['reactivate_at'] . ' UTC' ) ) ) . '. Our broker will email you.</p>';
		}
		$h .= '<form method="post" action="' . $self . '"><input type="hidden" name="db_seller_action" value="reactivate_request"><input type="hidden" name="t" value="' . $t . '">'
			. '<div class="dbsl-f"><label for="dbsa-note">Ask to turn your account back on</label><textarea id="dbsa-note" name="note" rows="4" maxlength="1000" required placeholder="What happened, and how you will answer offers within 24 hours from now on."></textarea></div>'
			. '<button type="submit" class="dbsl-btn">Send reactivation request</button></form></section></div></div>';
		return $h;
	}
}

if ( ! function_exists( 'db_seller_account_html' ) ) {
	/** The Account tab: details, reliability record, pause. */
	function db_seller_account_html( array $seller ) {
		$email   = (string) $seller['email'];
		$t       = esc_attr( db_seller_csrf( $email ) );
		$self    = esc_url( home_url( '/seller/' ) );
		$strikes = db_seller_strikes_in_window( (int) $seller['id'] );
		$h  = '<section class="dbsl-card dbsl-sec"><h2>Your account</h2><dl class="dbsa-dl"><div><dt>Name</dt><dd>' . esc_html( $seller['legal_name'] ) . '</dd></div><div><dt>Email</dt><dd>' . esc_html( $email ) . '</dd></div><div><dt>Country</dt><dd>' . esc_html( $seller['country'] ) . '</dd></div><div><dt>Agreement</dt><dd>Version ' . esc_html( (string) $seller['agreement_version'] ) . '</dd></div></dl></section>';
		$h .= '<section class="dbsl-card dbsl-sec"><h2>Response record</h2><p>Strikes in the last ' . (int) db_seller_rule( 'strike_window' ) . ' days: <strong>' . $strikes . ' of ' . (int) db_seller_rule( 'suspend_strikes' ) . '</strong>. A strike is given when an offer goes unanswered for ' . (int) db_seller_rule( 'strike_days' ) . ' days or a sold name is not transferred within ' . (int) db_seller_rule( 'transfer_days' ) . ' days. Reaching ' . (int) db_seller_rule( 'suspend_strikes' ) . ' takes your listings offline until you ask us to turn them back on.</p></section>';
		$h .= '<section class="dbsl-card dbsl-sec"><h2>Going away?</h2><p>Pause all your listings while you cannot answer offers. Nothing is lost and there is no strike; resume with one click.</p><form method="post" action="' . $self . '" onsubmit="return confirm(\'Pause all your listings?\')"><input type="hidden" name="db_seller_action" value="pause"><input type="hidden" name="t" value="' . $t . '"><button type="submit" class="dbsl-btn dbsl-btn--ghost">Pause my listings</button></form></section>';
		return $h . '<style>.dbsa-dl{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:0}.dbsa-dl div{padding:12px 14px;border-radius:12px;background:#f6f7f9}.dbsa-dl dt{font-size:12.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#5b6472}.dbsa-dl dd{margin:2px 0 0;font-weight:700}@media(max-width:640px){.dbsa-dl{grid-template-columns:1fr}}</style>';
	}
}

/* ---------------------------------------------- account deletion, duplicates */

add_action( 'db_account_before_delete', function ( $email ) {
	$seller = function_exists( 'db_seller_get' ) ? db_seller_get( $email ) : null;
	if ( ! $seller ) {
		return;
	}
	global $wpdb;
	$l = db_seller_table( 'listings' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$l} WHERE seller_id = %d AND review_status IN ('draft','pending','approved')", (int) $seller['id'] ), ARRAY_A ) as $r ) {
		db_seller_remove( $r );
	}
	foreach ( db_seller_open_deals( (int) $seller['id'] ) as $d ) {
		db_seller_tell_buyer_broker( $d, 'deleted' );
	}
	$wpdb->update( db_seller_table( 'sellers' ), array( 'status' => 'closed', 'status_note' => 'Account deleted by the seller' ), array( 'id' => (int) $seller['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	db_seller_audit( (int) $seller['id'], '', 'account_deleted', 'Listings removed; payouts due are still paid', 'seller' );
	if ( function_exists( 'db_plan_notify_admin' ) ) {
		db_plan_notify_admin( 'Seller deleted their account', array( 'Seller id' => (int) $seller['id'], 'Note' => 'Listings removed; check Tools > Seller payouts for anything still due.' ) );
	}
} );

if ( ! function_exists( 'db_seller_duplicate_of' ) ) {
	/** A suspended or closed seller with the same phone number (digits only), or 0. */
	function db_seller_duplicate_of( $phone, $except_id = 0 ) {
		global $wpdb;
		$digits = preg_replace( '/\D/', '', (string) $phone );
		if ( strlen( $digits ) < 7 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, phone FROM ' . db_seller_table( 'sellers' ) . " WHERE status IN ('suspended','suspended_unresp','closed') AND id <> %d", (int) $except_id ), ARRAY_A );
		foreach ( $rows as $r ) {
			if ( preg_replace( '/\D/', '', (string) $r['phone'] ) === $digits ) {
				return (int) $r['id'];
			}
		}
		return 0;
	}
}
