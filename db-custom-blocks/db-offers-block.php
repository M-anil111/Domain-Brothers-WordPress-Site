<?php
/**
 * Custom offers: the negotiation between a buyer and the Domain Brothers team.
 *
 * A custom offer starts when a visitor submits the "Make an offer" form (a CRM
 * lead with an amount). From then on a "deal" tracks it:
 *
 *   open       the buyer's offer is waiting for the team;
 *   countered  the team named a price, waiting for the buyer;
 *   accepted   a price is agreed (by either side); the buyer can pay;
 *   declined   either side said no;
 *   paid       the buyer paid (set by the Stripe webhook);
 *   expired    a counter-offer or an accepted price was not used in time.
 *
 * Either side can keep going: the team can accept, counter or decline; the
 * buyer can accept a counter-offer, decline it, or make a new offer, as often
 * as they like, until one price is accepted and paid.
 *
 * The buyer never sees the admin: every email links to a private page on the
 * domain's own URL (…/domains/name/?c=<token>). The token is 32 random hex
 * characters and the only thing that identifies the deal. Prices are read from
 * the deal on the server, so a link can be neither forged nor edited.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_DEALS_TABLE_VERSION' ) ) {
	define( 'DB_DEALS_TABLE_VERSION', '1' );
}
if ( ! defined( 'DB_DEAL_COUNTER_DAYS' ) ) {
	define( 'DB_DEAL_COUNTER_DAYS', 7 ); // how long a counter-offer or an accepted price stays open
}
if ( ! defined( 'DB_DEAL_MAX_AMOUNT' ) ) {
	define( 'DB_DEAL_MAX_AMOUNT', 10000000 );
}
if ( ! defined( 'DB_DEAL_MIN_PLAN_AMOUNT' ) ) {
	define( 'DB_DEAL_MIN_PLAN_AMOUNT', 600 ); // same floor as the "or $x/mo" prices elsewhere
}

/* ───────────────────────────── 1. Tables ──────────────────────────────── */

if ( ! function_exists( 'db_deal_table' ) ) {
	function db_deal_table( $which = 'deals' ) {
		global $wpdb;
		return $wpdb->prefix . ( 'events' === $which ? 'db_deal_events' : 'db_deals' );
	}
}

add_action( 'init', function () {
	if ( get_option( 'db_deals_table_version', '' ) === DB_DEALS_TABLE_VERSION ) {
		return;
	}
	global $wpdb;
	$charset = $wpdb->get_charset_collate();
	$deals   = db_deal_table();
	$events  = db_deal_table( 'events' );
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$deals} (
		id          INT           NOT NULL AUTO_INCREMENT,
		lead_id     INT           NOT NULL DEFAULT 0,
		token       CHAR(32)      NOT NULL,
		domain      VARCHAR(255)  NOT NULL DEFAULT '',
		buyer_email VARCHAR(255)  NOT NULL DEFAULT '',
		buyer_name  VARCHAR(255)  NOT NULL DEFAULT '',
		state       VARCHAR(12)   NOT NULL DEFAULT 'open',
		amount      DECIMAL(12,2) NOT NULL DEFAULT 0,
		proposer    VARCHAR(8)    NOT NULL DEFAULT 'buyer',
		expires_at  DATETIME      NULL,
		created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_token (token),
		UNIQUE KEY uniq_lead (lead_id),
		KEY idx_state (state)
	) {$charset};" );
	dbDelta( "CREATE TABLE {$events} (
		id         INT           NOT NULL AUTO_INCREMENT,
		deal_id    INT           NOT NULL,
		actor      VARCHAR(8)    NOT NULL DEFAULT 'buyer',
		action     VARCHAR(12)   NOT NULL DEFAULT 'offer',
		amount     DECIMAL(12,2) NULL,
		message    TEXT          NULL,
		created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY idx_deal (deal_id)
	) {$charset};" );
	update_option( 'db_deals_table_version', DB_DEALS_TABLE_VERSION );
} );

/* ───────────────────────────── 2. Data access ─────────────────────────── */

if ( ! function_exists( 'db_deal_states' ) ) {
	function db_deal_states() {
		return array(
			'open'      => 'Waiting for us',
			'countered' => 'Waiting for the buyer',
			'accepted'  => 'Agreed: awaiting payment',
			'declined'  => 'Declined',
			'paid'      => 'Paid',
			'expired'   => 'Expired',
		);
	}
}

if ( ! function_exists( 'db_deal_get' ) ) {
	function db_deal_get( $id ) {
		global $wpdb;
		$t = db_deal_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) );
	}
}

if ( ! function_exists( 'db_deal_get_by_token' ) ) {
	function db_deal_get_by_token( $token ) {
		global $wpdb;
		$token = strtolower( (string) $token );
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $token ) ) {
			return null;
		}
		$t = db_deal_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token = %s", $token ) );
		return $deal ? db_deal_expire_if_due( $deal ) : null;
	}
}

if ( ! function_exists( 'db_deal_get_by_lead' ) ) {
	function db_deal_get_by_lead( $lead_id ) {
		global $wpdb;
		$t = db_deal_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE lead_id = %d", (int) $lead_id ) );
	}
}

if ( ! function_exists( 'db_deal_events' ) ) {
	function db_deal_events( $deal_id ) {
		global $wpdb;
		$t = db_deal_table( 'events' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE deal_id = %d ORDER BY id ASC", (int) $deal_id ) );
	}
}

if ( ! function_exists( 'db_deal_log' ) ) {
	function db_deal_log( $deal_id, $actor, $action, $amount = null, $message = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( db_deal_table( 'events' ), array(
			'deal_id' => (int) $deal_id,
			'actor'   => $actor,
			'action'  => $action,
			'amount'  => null === $amount ? null : round( (float) $amount, 2 ),
			'message' => sanitize_textarea_field( (string) $message ),
		) );
	}
}

if ( ! function_exists( 'db_deal_clean_amount' ) ) {
	/** A positive price within bounds, or null. */
	function db_deal_clean_amount( $raw ) {
		$clean = preg_replace( '/[^0-9.]/', '', (string) $raw );
		if ( '' === $clean || ! is_numeric( $clean ) ) {
			return null;
		}
		$v = round( (float) $clean, 2 );
		return ( $v > 0 && $v <= DB_DEAL_MAX_AMOUNT ) ? $v : null;
	}
}

if ( ! function_exists( 'db_deal_ensure_for_lead' ) ) {
	/** The deal for a lead, created (as the buyer's open offer) if it has none. */
	function db_deal_ensure_for_lead( $lead_id ) {
		global $wpdb;
		$deal = db_deal_get_by_lead( $lead_id );
		if ( $deal ) {
			return db_deal_expire_if_due( $deal );
		}
		$lt = $wpdb->prefix . 'db_leads';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$lead = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$lt} WHERE id = %d", (int) $lead_id ) );
		if ( ! $lead ) {
			return null;
		}
		// A repeat offer that was merged into an earlier negotiation opens that one.
		$joined = db_deal_find_active( $lead->email, $lead->domain );
		if ( $joined ) {
			return $joined;
		}
		$amount = (float) $lead->offer_amount;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->insert( db_deal_table(), array(
			'lead_id'     => (int) $lead->id,
			'token'       => bin2hex( random_bytes( 16 ) ),
			'domain'      => strtolower( trim( $lead->domain ) ),
			'buyer_email' => $lead->email,
			'buyer_name'  => $lead->name,
			'state'       => 'open',
			'amount'      => $amount > 0 ? $amount : 0,
			'proposer'    => 'buyer',
		) );
		if ( ! $ok ) {
			return db_deal_get_by_lead( $lead_id );
		}
		$deal = db_deal_get( $wpdb->insert_id );
		db_deal_log( $deal->id, 'buyer', 'offer', $amount > 0 ? $amount : null, (string) $lead->message );
		return $deal;
	}
}

// A new lead from the offer form becomes an open deal.
if ( ! function_exists( 'db_deal_find_active' ) ) {
	/** The newest negotiation for this buyer and domain that can still take a new offer. */
	function db_deal_find_active( $email, $domain ) {
		global $wpdb;
		$t = db_deal_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE LOWER(buyer_email) = %s AND domain = %s AND state IN ('open','countered','declined','expired') ORDER BY id DESC LIMIT 1", strtolower( trim( (string) $email ) ), strtolower( trim( (string) $domain ) ) ) );
		return $deal ? db_deal_expire_if_due( $deal ) : null;
	}
}

// A new lead from the offer form becomes an open deal. When the same buyer
// has already made an offer on this domain and it is still being negotiated,
// the new offer is added to that negotiation (every offer is kept in its
// history) instead of starting a second one.
add_action( 'db_crm_lead_created', function ( $lead_id, $data ) {
	if ( 'offer_form' !== ( $data['source'] ?? '' ) ) {
		return;
	}
	$amount = (float) db_crm_clean_offer_amount( $data['offer_amount'] ?? '' );
	if ( $amount <= 0 ) {
		return;
	}
	global $wpdb;
	$lt = $wpdb->prefix . 'db_leads';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$lead = $wpdb->get_row( $wpdb->prepare( "SELECT email, domain, message FROM {$lt} WHERE id = %d", (int) $lead_id ) );
	$prev = $lead ? db_deal_find_active( $lead->email, $lead->domain ) : null;
	if ( $prev && (int) $prev->lead_id !== (int) $lead_id ) {
		$res = db_deal_apply( $prev, 'buyer', 'offer', $amount, (string) $lead->message );
		if ( ! is_wp_error( $res ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $lt, array( 'status' => 'negotiating' ), array( 'id' => (int) $lead_id ) );
			return;
		}
	}
	db_deal_ensure_for_lead( $lead_id );
}, 10, 2 );

/* ───────────────────────────── 3. State machine ───────────────────────── */

if ( ! function_exists( 'db_deal_expire_if_due' ) ) {
	function db_deal_expire_if_due( $deal ) {
		if ( $deal && in_array( $deal->state, array( 'countered', 'accepted' ), true ) && $deal->expires_at && strtotime( $deal->expires_at . ' UTC' ) < time() ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( db_deal_table(), array( 'state' => 'expired' ), array( 'id' => $deal->id ) );
			db_deal_log( $deal->id, 'system', 'expire', $deal->amount, '' );
			db_deal_sync_lead( $deal->lead_id, 'expired', null );
			$deal = db_deal_get( $deal->id );
		}
		return $deal;
	}
}

if ( ! function_exists( 'db_deal_sync_lead' ) ) {
	/** Keeps the CRM lead's status and offer amount in step with its deal. */
	function db_deal_sync_lead( $lead_id, $state, $amount ) {
		global $wpdb;
		if ( ! $lead_id ) {
			return;
		}
		$map = array( 'open' => 'negotiating', 'countered' => 'negotiating', 'accepted' => 'negotiating', 'paid' => 'won', 'declined' => 'lost', 'expired' => 'lost' );
		$set = array( 'status' => $map[ $state ] ?? 'negotiating' );
		if ( null !== $amount && 'open' === $state ) {
			$set['offer_amount'] = round( (float) $amount, 2 );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'db_leads', $set, array( 'id' => (int) $lead_id ) );
	}
}

if ( ! function_exists( 'db_deal_transition' ) ) {
	/**
	 * The rules of the negotiation, as a pure function: the state a move leads
	 * to, or null when the move is not allowed from $state.
	 *
	 * @param string $state      open | countered | accepted | declined | paid | expired
	 * @param string $actor      buyer | seller | system
	 * @param string $action     offer | counter | accept | decline | paid
	 * @param bool   $has_amount whether the deal already holds a price
	 */
	function db_deal_transition( $state, $actor, $action, $has_amount = true ) {
		$can_restart = in_array( $state, array( 'open', 'countered', 'declined', 'expired' ), true );
		if ( 'seller' === $actor ) {
			if ( 'counter' === $action && $can_restart ) {
				return 'countered';
			}
			if ( 'accept' === $action && 'open' === $state && $has_amount ) {
				return 'accepted';
			}
			if ( 'decline' === $action && in_array( $state, array( 'open', 'countered' ), true ) ) {
				return 'declined';
			}
		} elseif ( 'buyer' === $actor ) {
			if ( 'accept' === $action && 'countered' === $state ) {
				return 'accepted';
			}
			if ( 'decline' === $action && in_array( $state, array( 'open', 'countered' ), true ) ) {
				return 'declined';
			}
			if ( 'offer' === $action && $can_restart ) {
				return 'open';
			}
		} elseif ( 'system' === $actor && 'paid' === $action && in_array( $state, array( 'accepted', 'countered', 'open' ), true ) ) {
			return 'paid';
		}
		return null;
	}
}

if ( ! function_exists( 'db_deal_apply' ) ) {
	/**
	 * Applies one move to a deal. Returns the updated deal, or a WP_Error that
	 * says why the move is not allowed from the deal's current state.
	 *
	 * @param object $deal
	 * @param string $actor   buyer | seller | system
	 * @param string $action  offer | counter | accept | decline | paid
	 */
	function db_deal_apply( $deal, $actor, $action, $amount = null, $message = '', $days = DB_DEAL_COUNTER_DAYS ) {
		global $wpdb;
		$deal = db_deal_expire_if_due( $deal );
		$new  = db_deal_transition( $deal->state, $actor, $action, (float) $deal->amount > 0 );
		$set  = array();

		if ( null === $new ) {
			return new WP_Error( 'db_deal_move', 'That is not possible at this stage of the offer.' );
		}

		if ( in_array( $action, array( 'offer', 'counter' ), true ) ) {
			$amt = db_deal_clean_amount( $amount );
			if ( null === $amt ) {
				return new WP_Error( 'db_deal_amount', 'Enter a price greater than zero.' );
			}
			$set['amount']   = $amt;
			$set['proposer'] = 'buyer' === $actor ? 'buyer' : 'seller';
			$amount          = $amt;
		} else {
			$amount = (float) $deal->amount;
		}
		$set['state'] = $new;
		if ( in_array( $new, array( 'countered', 'accepted' ), true ) ) {
			$set['expires_at'] = gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) $days ) * DAY_IN_SECONDS );
		} else {
			$set['expires_at'] = null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( db_deal_table(), $set, array( 'id' => (int) $deal->id ) );
		db_deal_log( $deal->id, $actor, $action, $amount, $message );
		db_deal_sync_lead( $deal->lead_id, $new, 'open' === $new ? $amount : null );
		$updated = db_deal_get( $deal->id );
		do_action( 'db_deal_changed', $updated, $actor, $action, (string) $message );
		return $updated;
	}
}

/* ───────────────────────────── 4. Links & emails ──────────────────────── */

if ( ! function_exists( 'db_deal_money' ) ) {
	function db_deal_money( $n ) {
		return '$' . number_format( (float) $n, ( floor( (float) $n ) === (float) $n ) ? 0 : 2 );
	}
}

if ( ! function_exists( 'db_deal_url' ) ) {
	/** The buyer's private page for this deal. */
	function db_deal_url( $deal ) {
		$post = function_exists( 'db_listing_post_for_domain' ) ? db_listing_post_for_domain( $deal->domain ) : null;
		$base = $post ? get_permalink( $post ) : home_url( '/domains/' . sanitize_title( str_replace( '.', '-', $deal->domain ) ) . '/' );
		return add_query_arg( 'c', $deal->token, $base );
	}
}

if ( ! function_exists( 'db_deal_pay_url' ) ) {
	function db_deal_pay_url( $deal, $months = 0 ) {
		$args = array( 'offer' => $deal->token );
		if ( in_array( (int) $months, array( 3, 6, 9, 12 ), true ) ) {
			$args['months'] = (int) $months;
		}
		return add_query_arg( $args, home_url( '/buy-now/' ) );
	}
}

if ( ! function_exists( 'db_deal_email_buyer' ) ) {
	function db_deal_email_buyer( $deal, $kind, $message = '' ) {
		if ( ! function_exists( 'db_plan_email_shell' ) || ! function_exists( 'db_plan_send' ) || ! is_email( $deal->buyer_email ) ) {
			return;
		}
		$d       = esc_html( $deal->domain );
		$money   = db_deal_money( $deal->amount );
		$note    = '' !== trim( $message ) ? '<p style="font-size:15px;color:#374151;margin:0 0 12px;border-left:3px solid #d1d5db;padding-left:12px;">' . nl2br( esc_html( $message ) ) . '</p>' : '';
		$expires = $deal->expires_at ? wp_date( 'F j, Y', strtotime( $deal->expires_at . ' UTC' ) ) : '';
		$url     = db_deal_url( $deal );
		if ( function_exists( 'db_mail_send' ) ) {
			$map = array( 'countered' => 'offer_countered', 'accepted' => 'offer_accepted', 'declined' => 'offer_declined' );
			if ( isset( $map[ $kind ] ) ) {
				$r = db_mail_send( $map[ $kind ], $deal->buyer_email, array(
					'domain'    => $deal->domain,
					'price'     => $money,
					'expires'   => $expires,
					'note_html' => '' !== trim( $message ) ? '<p>' . nl2br( esc_html( $message ) ) . '</p>' : '',
					'offer_url' => $url,
				), DB_PLAN_SUPPORT_EMAIL );
				if ( null !== $r ) {
					return;
				}
			}
		}
		if ( 'countered' === $kind ) {
			$html = db_plan_email_shell( 'We have a price for ' . $deal->domain, '<p style="font-size:15px;color:#374151;margin:0 0 12px;">Thank you for your offer on <strong>' . $d . '</strong>. After talking with the owner, we can offer it to you at <strong>' . esc_html( $money ) . '</strong>.</p>' . $note, array_filter( array( 'Domain' => $deal->domain, 'Our price' => $money, 'Open until' => $expires ) ), 'See the offer', $url, 'neutral' );
			db_plan_send( $deal->buyer_email, 'Our price for ' . $deal->domain . ': ' . $money, $html );
		} elseif ( 'accepted' === $kind ) {
			$html = db_plan_email_shell( 'Your offer on ' . $deal->domain . ' is accepted', '<p style="font-size:15px;color:#374151;margin:0 0 12px;">We accepted <strong>' . esc_html( $money ) . '</strong> for <strong>' . $d . '</strong>. You can pay in full or monthly, and the domain is transferred to you once payment is complete.</p>' . $note, array_filter( array( 'Domain' => $deal->domain, 'Agreed price' => $money, 'Pay by' => $expires ) ), 'Pay now', $url, 'good' );
			db_plan_send( $deal->buyer_email, 'Your offer on ' . $deal->domain . ' is accepted', $html );
		} elseif ( 'declined' === $kind ) {
			$html = db_plan_email_shell( 'About your offer on ' . $deal->domain, '<p style="font-size:15px;color:#374151;margin:0 0 12px;">We could not agree a price on <strong>' . $d . '</strong> this time. You are welcome to make another offer, or look at similar domains.</p>' . $note, array(), 'Make another offer', $url, 'neutral' );
			db_plan_send( $deal->buyer_email, 'About your offer on ' . $deal->domain, $html );
		}
	}
}

if ( ! function_exists( 'db_deal_email_admin' ) ) {
	function db_deal_email_admin( $deal, $what, $message = '' ) {
		if ( ! function_exists( 'db_plan_notify_admin' ) ) {
			return;
		}
		if ( function_exists( 'db_mail_send' ) ) {
			$st = db_deal_states()[ $deal->state ] ?? $deal->state;
			$r  = db_mail_send( 'admin_offer', db_mail_team_to(), array(
				'what'         => $what,
				'domain'       => $deal->domain,
				'buyer'        => $deal->buyer_email,
				'price'        => db_deal_money( $deal->amount ),
				'state'        => $st,
				'message_html' => '' !== trim( $message ) ? '<p>' . nl2br( esc_html( $message ) ) . '</p>' : '',
				'respond_url'  => admin_url( 'admin.php?page=db-offers&deal=' . (int) $deal->id ),
				'subject_line' => 'Offer: ' . $deal->domain . ' (' . $st . ')',
			) );
			if ( null !== $r ) {
				return;
			}
		}
		$lines = array(
			$what,
			'',
			'Domain : ' . $deal->domain,
			'Buyer  : ' . $deal->buyer_email,
			'Price  : ' . db_deal_money( $deal->amount ),
			'State  : ' . ( db_deal_states()[ $deal->state ] ?? $deal->state ),
		);
		if ( '' !== trim( $message ) ) {
			$lines[] = '';
			$lines[] = 'Message: ' . $message;
		}
		$lines[] = '';
		$lines[] = 'Respond: ' . admin_url( 'admin.php?page=db-offers&deal=' . (int) $deal->id );
		db_plan_notify_admin( 'Offer: ' . $deal->domain . ' (' . ( db_deal_states()[ $deal->state ] ?? $deal->state ) . ')', $lines );
	}
}

// Emails follow the state changes, in one place.
add_action( 'db_deal_changed', function ( $deal, $actor, $action, $message ) {
	if ( 'seller' === $actor ) {
		if ( 'counter' === $action ) {
			db_deal_email_buyer( $deal, 'countered', $message );
		} elseif ( 'accept' === $action ) {
			db_deal_email_buyer( $deal, 'accepted', $message );
		} elseif ( 'decline' === $action ) {
			db_deal_email_buyer( $deal, 'declined', $message );
		}
	} elseif ( 'buyer' === $actor ) {
		$what = array( 'offer' => 'The buyer made a new offer.', 'accept' => 'The buyer accepted your price and can now pay.', 'decline' => 'The buyer declined.' );
		db_deal_email_admin( $deal, $what[ $action ] ?? 'The buyer replied.', $message );
	}
}, 10, 4 );

// The confirmation email links to the buyer's own offer page.
add_filter( 'db_offer_confirmation_link', function ( $link, $email, $domain ) {
	global $wpdb;
	$t = db_deal_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$deal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE buyer_email = %s AND domain = %s AND created_at > %s ORDER BY id DESC LIMIT 1", sanitize_email( (string) $email ), strtolower( trim( (string) $domain ) ), gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
	return $deal ? db_deal_url( $deal ) : $link;
}, 10, 3 );

// The confirmation email links to the buyer's own offer page.
add_filter( 'db_offer_confirmation_link', function ( $link, $email, $domain ) {
	global $wpdb;
	$t = db_deal_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$deal = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE buyer_email = %s AND domain = %s AND created_at > %s ORDER BY id DESC LIMIT 1", sanitize_email( (string) $email ), strtolower( trim( (string) $domain ) ), gmdate( 'Y-m-d H:i:s', time() - 600 ) ) );
	return $deal ? db_deal_url( $deal ) : $link;
}, 10, 3 );

/* ───────────────────────── 5. Checkout and payment ────────────────────── */

if ( ! function_exists( 'db_deal_checkout_context' ) ) {
	/**
	 * What /buy-now/?offer=<token> may charge: the domain and agreed price from
	 * the deal itself. Null when the link is not payable (wrong state, expired,
	 * or the name was sold or withdrawn in the meantime).
	 *
	 * @return array{domain:string,price:float,deal:object}|null
	 */
	function db_deal_checkout_context( $token ) {
		$deal = db_deal_get_by_token( $token );
		if ( ! $deal || 'accepted' !== $deal->state || (float) $deal->amount <= 0 ) {
			return null;
		}
		if ( function_exists( 'db_stripe_domain_unavailable' ) && db_stripe_domain_unavailable( $deal->domain ) ) {
			return null;
		}
		return array( 'domain' => strtolower( trim( $deal->domain ) ), 'price' => (float) $deal->amount, 'deal' => $deal );
	}
}

// Tie the PaymentIntent to the deal so the webhook knows which offer was paid.
add_filter( 'db_stripe_pi_metadata', function ( $meta ) {
	if ( ! empty( $GLOBALS['db_deal_checkout'] ) ) {
		$deal            = $GLOBALS['db_deal_checkout'];
		$meta['lead_id'] = (string) (int) $deal->lead_id;
		$meta['deal_id'] = (string) (int) $deal->id;
	}
	return $meta;
} );

// A paid offer closes the deal and its lead.
add_action( 'db_order_event', function ( $event, array $d ) {
	$meta = (array) ( $d['meta'] ?? array() );
	if ( empty( $meta['deal_id'] ) ) {
		return;
	}
	if ( 'plan_installment' === $event && 1 !== (int) ( $d['installment'] ?? 1 ) ) {
		return;
	}
	$deal = db_deal_get( (int) $meta['deal_id'] );
	if ( $deal && 'paid' !== $deal->state ) {
		db_deal_apply( $deal, 'system', 'paid', null, 'Paid through checkout.' );
	}
}, 20, 2 );

/* ───────────────────── 6. The buyer's private page ────────────────────── */

if ( ! function_exists( 'db_deal_current' ) ) {
	/** The deal named by ?c= on a domain page, when it belongs to this domain. */
	function db_deal_current() {
		static $res = false;
		if ( false !== $res ) {
			return $res;
		}
		$res = null;
		if ( ! is_singular( 'domain' ) || empty( $_GET['c'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return $res;
		}
		$post = get_queried_object();
		$deal = db_deal_get_by_token( sanitize_text_field( wp_unslash( $_GET['c'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $post instanceof WP_Post && $deal && 0 === strcasecmp( $deal->domain, trim( wp_strip_all_tags( $post->post_title ) ) ) ) {
			$res = $deal;
		}
		return $res;
	}
}

add_filter( 'template_include', function ( $template ) {
	$file = __DIR__ . '/db-offer-template.php';
	if ( db_deal_current() && is_readable( $file ) ) {
		$post = get_queried_object();
		// A sold or not-for-sale name keeps its own page (the listing template runs after this filter).
		if ( ! ( $post instanceof WP_Post && function_exists( 'db_listing_is_sellable' ) && ! db_listing_is_sellable( $post ) ) ) {
			return $file;
		}
	}
	return $template;
}, 97 );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! db_deal_current() ) {
		return;
	}
	$css = ( function_exists( 'db_listing_css' ) ? db_listing_css() : '' ) . db_deal_css();
	if ( ! function_exists( 'db_external_style' ) || ! db_external_style( 'offer', $css ) ) {
		add_action( 'wp_head', function () use ( $css ) {
			echo '<style id="db-offer-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}, 100 );
	}
}, 110 );

if ( ! function_exists( 'db_deal_css' ) ) {
	function db_deal_css() {
		return '.db-of-price{font-size:clamp(34px,8vw,56px);font-weight:800;color:#0a1628;line-height:1.1;margin:6px 0 4px}'
			. '.db-of-sub{color:#4b5563;margin:0 0 6px}.db-of-msg{max-width:560px;margin:14px auto 0!important;padding:12px 16px;border-left:4px solid #2e7d14;background:#f6f8fb;text-align:left;border-radius:0 10px 10px 0}'
			. '.db-un--offer .db-un-eyebrow{background:#eaf5e3;color:#1f5c0d}.db-un--offer .db-un-name{text-decoration:none;color:#0a1628}'
			. '.db-of-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;max-width:640px;margin:16px auto 0}'
			. '.db-of-plan{display:block;padding:14px 12px;border:2px solid #cbd5e1;border-radius:12px;text-decoration:none!important;color:#0a1628!important;text-align:center}.db-of-plan:hover,.db-of-plan:focus-visible{border-color:#2e7d14;background:#f1f8ee;outline:none}'
			. '.db-of-plan b{display:block;font-size:18px}.db-of-plan span{display:block;font-size:13px;color:#4b5563}'
			. '.db-of-box{max-width:520px;margin:26px auto 0;padding-top:20px;border-top:1px solid #e5eaf1;text-align:left}.db-of-box h2{font-size:18px;margin:0 0 10px;color:#0a1628}'
			. '.db-of-links{display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-top:16px}.db-un .db-of-links .db-of-btn-quiet{background:none!important;border:0!important;box-shadow:none!important;color:#4b5563!important;text-decoration:underline;cursor:pointer;font:inherit;font-weight:400;text-transform:none!important;letter-spacing:0;width:auto;min-height:44px;padding:0 12px}'
			. '.db-of-hist{max-width:520px;margin:26px auto 0;text-align:left}.db-of-hist h2{font-size:16px;margin:0 0 8px;color:#0a1628}.db-of-hist ol{list-style:none;margin:0;padding:0}'
			. '.db-of-hist li{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #eef2f7;font-size:14px;color:#374151}.db-of-hist li span:last-child{color:#6b7280;white-space:nowrap}';
	}
}

if ( ! function_exists( 'db_deal_buyer_html' ) ) {
	function db_deal_buyer_html( $deal, WP_Post $post ) {
		$name    = strtolower( trim( wp_strip_all_tags( $post->post_title ) ) );
		$money   = db_deal_money( $deal->amount );
		$state   = $deal->state;
		$action  = esc_url( admin_url( 'admin-post.php' ) );
		$done    = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err     = isset( $_GET['err'] ) ? sanitize_key( wp_unslash( $_GET['err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$events  = db_deal_events( $deal->id );
		$last_s  = '';
		for ( $i = count( $events ) - 1; $i >= 0; $i-- ) {
			if ( 'seller' === $events[ $i ]->actor && '' !== trim( (string) $events[ $i ]->message ) ) {
				$last_s = $events[ $i ]->message;
				break;
			}
		}
		$expires = $deal->expires_at ? wp_date( 'F j, Y', strtotime( $deal->expires_at . ' UTC' ) ) : '';
		$hidden  = '<input type="hidden" name="action" value="db_deal_buyer"><input type="hidden" name="token" value="' . esc_attr( $deal->token ) . '"><input type="hidden" name="t" value="' . time() . '">';
		$hp      = '<div class="db-un-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
		$newform = static function ( $title, $btn ) use ( $action, $hidden, $hp ) {
			return '<form class="db-un-form db-of-box" method="post" action="' . $action . '"><h2>' . esc_html( $title ) . '</h2>' . $hidden . $hp
				. '<input type="hidden" name="do" value="offer"><label for="db-of-amt">Your offer (USD)</label><input type="text" inputmode="decimal" id="db-of-amt" name="amount" required placeholder="e.g. 2500" autocomplete="off">'
				. '<label for="db-of-note">Message (optional)</label><textarea id="db-of-note" name="message" rows="2" maxlength="600"></textarea><button type="submit">' . esc_html( $btn ) . '</button></form>';
		};

		$h  = '<section class="db-un db-un--offer" aria-labelledby="db-of-h"><div class="db-un-card">';
		$h .= '<span class="db-un-eyebrow">Your offer</span><h1 class="db-un-name" id="db-of-h">' . esc_html( $name ) . '</h1>';
		if ( 'ok' === $done ) {
			$h .= '<p class="db-un-note db-un-note--ok" role="status">Thanks. We have recorded that.</p>';
		} elseif ( $err ) {
			$h .= '<p class="db-un-note db-un-note--err" role="alert">' . ( 'amount' === $err ? 'Please enter a price greater than zero.' : ( 'wait' === $err ? 'Please wait a little before trying again.' : 'That is not possible at this stage of the offer.' ) ) . '</p>';
		}

		if ( 'open' === $state ) {
			$h .= '<p class="db-un-lead">We have your offer and will come back to you by email.</p><div class="db-of-price">' . esc_html( $money ) . '</div><p class="db-of-sub">your offer</p>';
			$h .= $newform( 'Change your offer', 'Send new offer' );
			$h .= '<form method="post" action="' . $action . '" class="db-of-links">' . $hidden . '<input type="hidden" name="do" value="decline"><button type="submit" class="db-of-btn-quiet">Withdraw my offer</button></form>';
		} elseif ( 'countered' === $state ) {
			$h .= '<p class="db-un-lead">Our price for this domain:</p><div class="db-of-price">' . esc_html( $money ) . '</div>' . ( $expires ? '<p class="db-of-sub">Open until ' . esc_html( $expires ) . '</p>' : '' );
			if ( '' !== trim( $last_s ) ) {
				$h .= '<p class="db-of-msg">' . nl2br( esc_html( $last_s ) ) . '</p>';
			}
			$h .= '<form method="post" action="' . $action . '" class="db-un-actions">' . $hidden . '<input type="hidden" name="do" value="accept"><button type="submit" class="db-un-btn db-un-btn--primary">Accept ' . esc_html( $money ) . '</button></form>';
			$h .= $newform( 'Or make a different offer', 'Send my offer' );
			$h .= '<form method="post" action="' . $action . '" class="db-of-links">' . $hidden . '<input type="hidden" name="do" value="decline"><button type="submit" class="db-of-btn-quiet">No thanks</button></form>';
		} elseif ( 'accepted' === $state ) {
			$h .= '<p class="db-un-lead">Agreed. The domain is yours at:</p><div class="db-of-price">' . esc_html( $money ) . '</div>' . ( $expires ? '<p class="db-of-sub">Please pay by ' . esc_html( $expires ) . '</p>' : '' );
			$h .= '<div class="db-un-actions"><a class="db-un-btn db-un-btn--primary" href="' . esc_url( db_deal_pay_url( $deal ) ) . '">Pay ' . esc_html( $money ) . ' now</a></div>';
			if ( (float) $deal->amount >= DB_DEAL_MIN_PLAN_AMOUNT && function_exists( 'db_stripe_installment_cents' ) ) {
				$h .= '<div class="db-of-box" style="text-align:center"><h2>Or pay monthly</h2><div class="db-of-grid">';
				foreach ( array( 3, 6, 9, 12 ) as $m ) {
					$first = db_stripe_installment_cents( (float) $deal->amount, $m, 1 ) / 100;
					$h    .= '<a class="db-of-plan" href="' . esc_url( db_deal_pay_url( $deal, $m ) ) . '"><b>' . (int) $m . ' months</b><span>' . esc_html( '$' . number_format( $first, 2 ) ) . '/month</span><span>' . ( in_array( $m, array( 3, 6 ), true ) ? '0% interest' : ( 9 === $m ? '5% interest' : '10% interest' ) ) . '</span></a>';
				}
				$h .= '</div></div>';
			}
			$h .= '<p class="db-of-sub" style="margin-top:16px">Your domain is held in escrow and transferred when payment is complete.</p>';
		} elseif ( 'paid' === $state ) {
			$h .= '<p class="db-un-lead">Paid. Thank you. Your domain transfer is under way, and we will email you the next steps.</p>';
		} else { // declined | expired
			$h .= '<p class="db-un-lead">' . ( 'expired' === $state ? 'This offer has expired.' : 'This offer was not accepted.' ) . ' You can make a new offer any time.</p>';
			$h .= $newform( 'Make a new offer', 'Send offer' );
		}

		if ( count( $events ) > 1 ) {
			$h .= '<div class="db-of-hist"><h2>History</h2><ol>';
			$words = array( 'offer' => 'offered', 'counter' => 'countered', 'accept' => 'accepted', 'decline' => 'declined', 'paid' => 'paid', 'expire' => 'expired' );
			foreach ( $events as $e ) {
				$who = array( 'buyer' => 'You', 'seller' => 'Domain Brothers', 'system' => 'Update' )[ $e->actor ] ?? $e->actor;
				$h  .= '<li><span>' . esc_html( $who . ' ' . ( $words[ $e->action ] ?? $e->action ) . ( null !== $e->amount && in_array( $e->action, array( 'offer', 'counter' ), true ) ? ' ' . db_deal_money( $e->amount ) : '' ) ) . '</span><span>' . esc_html( wp_date( 'M j', strtotime( $e->created_at . ' UTC' ) ) ) . '</span></li>';
			}
			$h .= '</ol></div>';
		}
		$h .= '</div></section>';
		return $h;
	}
}

foreach ( array( 'admin_post_nopriv_db_deal_buyer', 'admin_post_db_deal_buyer' ) as $hook ) {
	add_action( $hook, function () {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the private token in the form is the credential.
		$deal = db_deal_get_by_token( isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '' );
		if ( ! $deal ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$back = db_deal_url( $deal );
		$go   = static function ( $arg, $val ) use ( $back ) {
			wp_safe_redirect( add_query_arg( $arg, $val, $back ) );
			exit;
		};
		if ( ! empty( $_POST['website'] ) ) {
			$go( 'done', 'ok' );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'db_deal_rl_' . md5( $ip . $deal->token );
		$n   = (int) get_transient( $key );
		if ( $n >= 20 ) {
			$go( 'err', 'wait' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$amount  = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 600 ) : substr( $message, 0, 600 );
		if ( ! in_array( $do, array( 'accept', 'decline', 'offer' ), true ) ) {
			$go( 'err', 'move' );
		}
		$res = db_deal_apply( $deal, 'buyer', $do, $amount, $message );
		if ( is_wp_error( $res ) ) {
			$go( 'err', 'db_deal_amount' === $res->get_error_code() ? 'amount' : 'move' );
		}
		// Accepting a counter-offer goes straight to the payment choices.
		$go( 'done', 'ok' );
		// phpcs:enable
	} );
}

/* ───────────────────────────── 7. Admin ───────────────────────────────── */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Offers', 'Offers', 'manage_options', 'db-offers', 'db_deal_admin_page' );
}, 21 );

if ( ! function_exists( 'db_deal_tabs' ) ) {
	function db_deal_tabs( $current ) {
		$base = admin_url( 'admin.php?page=db-offers' );
		return '<h2 class="nav-tab-wrapper"><a class="nav-tab' . ( 'deals' === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '">Negotiations</a><a class="nav-tab' . ( 'all' === $current ? ' nav-tab-active' : '' ) . '" href="' . esc_url( add_query_arg( 'tab', 'all', $base ) ) . '">All offers received</a></h2>';
	}
}

if ( ! function_exists( 'db_deal_all_offers' ) ) {
	/**
	 * Every offer a buyer has ever made, newest first: one row per offer, so a
	 * buyer who offers three times appears three times. Includes older leads
	 * from before negotiations were tracked.
	 *
	 * @return array<int,array{ts:int,domain:string,email:string,name:string,amount:float,message:string,deal:int,state:string}>
	 */
	function db_deal_all_offers( $search = '' ) {
		global $wpdb;
		$et = db_deal_table( 'events' );
		$dt = db_deal_table();
		$lt = $wpdb->prefix . 'db_leads';
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ev = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT e.created_at, e.amount, e.message, d.id AS deal, d.domain, d.buyer_email, d.buyer_name, d.state, d.lead_id FROM {$et} e JOIN {$dt} d ON d.id = e.deal_id WHERE e.actor = 'buyer' AND e.action = 'offer' AND (d.domain LIKE %s OR d.buyer_email LIKE %s OR d.buyer_name LIKE %s) ORDER BY e.id DESC LIMIT 2000",
			$like, $like, $like
		) );
		$legacy = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT l.id, l.created_at, l.offer_amount, l.message, l.domain, l.email, l.name FROM {$lt} l LEFT JOIN {$dt} d ON d.lead_id = l.id LEFT JOIN {$dt} d2 ON LOWER(d2.buyer_email) = LOWER(l.email) AND d2.domain = LOWER(l.domain) AND d2.created_at <= l.created_at WHERE d.id IS NULL AND d2.id IS NULL AND l.offer_amount > 0 AND l.source = 'offer_form' AND (l.domain LIKE %s OR l.email LIKE %s OR l.name LIKE %s) ORDER BY l.id DESC LIMIT 2000",
			$like, $like, $like
		) );
		// phpcs:enable
		$out = array();
		foreach ( $ev as $r ) {
			$out[] = array( 'ts' => (int) strtotime( $r->created_at . ' UTC' ), 'domain' => strtolower( $r->domain ), 'email' => $r->buyer_email, 'name' => $r->buyer_name, 'amount' => (float) $r->amount, 'message' => (string) $r->message, 'deal' => (int) $r->deal, 'state' => $r->state );
		}
		foreach ( $legacy as $r ) {
			$out[] = array( 'ts' => (int) strtotime( $r->created_at . ' UTC' ), 'domain' => strtolower( $r->domain ), 'email' => $r->email, 'name' => $r->name, 'amount' => (float) $r->offer_amount, 'message' => (string) $r->message, 'deal' => 0, 'state' => 'earlier' );
		}
		usort( $out, static function ( $a, $b ) {
			return $b['ts'] <=> $a['ts'];
		} );
		return $out;
	}
}

if ( ! function_exists( 'db_deal_all_offers_page' ) ) {
	function db_deal_all_offers_page() {
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows = db_deal_all_offers( $q );
		$sum  = 0.0;
		$doms = array();
		foreach ( $rows as $r ) {
			$sum += $r['amount'];
			$doms[ $r['domain'] ] = true;
		}
		echo '<div class="wrap"><h1>Custom offers</h1>' . db_deal_tabs( 'all' ) . '<p>Every offer ever received through the "Make an offer" form. A buyer who offers more than once appears once per offer.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		printf( '<p><strong>%d</strong> offers on <strong>%d</strong> domains, totalling %s.</p>', count( $rows ), count( $doms ), esc_html( db_deal_money( $sum ) ) );
		echo '<form method="get" style="margin:10px 0"><input type="hidden" name="page" value="db-offers"><input type="hidden" name="tab" value="all"><input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Domain or buyer"> <button class="button">Search</button> <a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'db_offers_export', 'q' => $q ), admin_url( 'admin-post.php' ) ), 'db_offers_export' ) ) . '">Export CSV</a></form>';
		echo '<table class="widefat striped"><thead><tr><th>Received</th><th>Domain</th><th>Buyer</th><th>Offer</th><th>Message</th><th>Now</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">No offers yet.</td></tr>';
		}
		$states = db_deal_states();
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( wp_date( 'M j, Y g:i a', $r['ts'] ) ) . '</td><td><strong>' . esc_html( $r['domain'] ) . '</strong></td><td>' . esc_html( trim( $r['name'] . ' ' . $r['email'] ) ) . '</td><td>' . esc_html( db_deal_money( $r['amount'] ) ) . '</td><td>' . esc_html( wp_trim_words( $r['message'], 14 ) ) . '</td><td>' . esc_html( 'earlier' === $r['state'] ? 'Earlier lead' : ( $states[ $r['state'] ] ?? $r['state'] ) ) . '</td><td>' . ( $r['deal'] ? '<a href="' . esc_url( admin_url( 'admin.php?page=db-offers&deal=' . $r['deal'] ) ) . '">Open</a>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table></div>';
	}
}

add_action( 'admin_post_db_offers_export', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_offers_export' ) ) {
		wp_die( 'Not allowed.' );
	}
	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="db-offers-received-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Received (UTC)', 'Domain', 'Buyer name', 'Buyer email', 'Offer (USD)', 'Message', 'Status now' ) );
	$safe = static function ( $v ) {
		$v = (string) $v;
		return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
	};
	foreach ( db_deal_all_offers( $q ) as $r ) {
		fputcsv( $out, array( gmdate( 'Y-m-d H:i', $r['ts'] ), $safe( $r['domain'] ), $safe( $r['name'] ), $safe( $r['email'] ), number_format( $r['amount'], 2, '.', '' ), $safe( $r['message'] ), $r['state'] ) );
	}
	fclose( $out );
	exit;
} );

if ( ! function_exists( 'db_deal_admin_page' ) ) {
	function db_deal_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		global $wpdb;
		// Opening from a lead: make sure it has a deal.
		if ( isset( $_GET['lead'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$d = db_deal_ensure_for_lead( (int) $_GET['lead'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $d ) {
				echo '<script>location.replace(' . wp_json_encode( admin_url( 'admin.php?page=db-offers&deal=' . (int) $d->id ) ) . ')</script>';
				return;
			}
		}
		if ( isset( $_GET['deal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$deal = db_deal_get( (int) $_GET['deal'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( $deal ) {
				db_deal_admin_detail( db_deal_expire_if_due( $deal ) );
				return;
			}
		}
		if ( isset( $_GET['tab'] ) && 'all' === $_GET['tab'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			db_deal_all_offers_page();
			return;
		}
		$t = db_deal_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$t} ORDER BY FIELD(state,'open','accepted','countered','expired','declined','paid'), updated_at DESC LIMIT 300" );
		echo '<div class="wrap"><h1>Custom offers</h1>' . db_deal_tabs( 'deals' ) . '<p>Offers from the "Make an offer" form. Open one to accept it, send a different price, or decline.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<style>.dbo-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;background:#eef2f7;color:#334155}.dbo-pill.open{background:#fff4e0;color:#8a5a00}.dbo-pill.countered{background:#e0f0ff;color:#0a5aa8}.dbo-pill.accepted,.dbo-pill.paid{background:#e7f6ec;color:#137a3e}.dbo-pill.declined,.dbo-pill.expired{background:#fee2e2;color:#991b1b}</style>';
		echo '<table class="widefat striped"><thead><tr><th>Updated</th><th>Domain</th><th>Buyer</th><th>Price</th><th>Last move</th><th>Status</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="7">No offers yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			printf(
				'<tr><td>%s</td><td><strong>%s</strong></td><td>%s</td><td>%s</td><td>%s</td><td><span class="dbo-pill %s">%s</span></td><td><a class="button button-primary" href="%s">%s</a></td></tr>',
				esc_html( wp_date( 'M j, g:i a', strtotime( $r->updated_at . ' UTC' ) ) ),
				esc_html( $r->domain ),
				esc_html( $r->buyer_email ),
				esc_html( (float) $r->amount > 0 ? db_deal_money( $r->amount ) : '-' ),
				'seller' === $r->proposer ? 'We proposed' : 'Buyer proposed',
				esc_attr( $r->state ),
				esc_html( db_deal_states()[ $r->state ] ?? $r->state ),
				esc_url( admin_url( 'admin.php?page=db-offers&deal=' . (int) $r->id ) ),
				in_array( $r->state, array( 'open', 'declined', 'expired' ), true ) ? 'Respond' : 'View'
			);
		}
		echo '</tbody></table></div>';
	}
}

if ( ! function_exists( 'db_deal_similar_sold' ) ) {
	/**
	 * Sold domains that resemble $name, best match first. Similar = same
	 * extension, a name of about the same length, and shared categories.
	 *
	 * @return array<int,array{name:string,price:float,date:int,via:string,score:int}>
	 */
	function db_deal_similar_sold( $name, $post = null, $limit = 8 ) {
		if ( ! function_exists( 'db_sold_rows' ) ) {
			return array();
		}
		$name = strtolower( trim( (string) $name ) );
		$dot  = strrpos( $name, '.' );
		$tld  = false === $dot ? '' : substr( $name, $dot );
		$len  = false === $dot ? strlen( $name ) : $dot;
		$cats = array();
		if ( $post ) {
			$cats = array_map( 'intval', (array) wp_get_object_terms( $post->ID, 'domain_category', array( 'fields' => 'ids' ) ) );
		}
		$out = array();
		foreach ( db_sold_rows( '' ) as $r ) {
			if ( $r['name'] === $name || $r['info']['price'] <= 0 ) {
				continue;
			}
			$d     = strrpos( $r['name'], '.' );
			$rtld  = false === $d ? '' : substr( $r['name'], $d );
			$rlen  = false === $d ? strlen( $r['name'] ) : $d;
			$score = 0;
			$score += ( '' !== $tld && $rtld === $tld ) ? 2 : 0;
			$diff   = abs( $rlen - $len );
			$score += $diff <= 2 ? 2 : ( $diff <= 4 ? 1 : 0 );
			if ( $cats && ! empty( $r['post'] ) ) {
				$theirs = array_map( 'intval', (array) wp_get_object_terms( $r['post']->ID, 'domain_category', array( 'fields' => 'ids' ) ) );
				$score += array_intersect( $cats, $theirs ) ? 2 : 0;
			}
			if ( $score >= 3 ) {
				$out[] = array( 'name' => $r['name'], 'price' => (float) $r['info']['price'], 'date' => (int) $r['info']['date'], 'via' => (string) $r['info']['via'], 'score' => $score );
			}
		}
		usort( $out, static function ( $a, $b ) {
			return ( $b['score'] <=> $a['score'] ) ?: ( $b['date'] <=> $a['date'] );
		} );
		return array_slice( $out, 0, (int) $limit );
	}
}

if ( ! function_exists( 'db_deal_intel_html' ) ) {
	/** What the team should know before answering: traffic, offer history, comparable sales. */
	function db_deal_intel_html( $deal ) {
		global $wpdb;
		$name = strtolower( $deal->domain );
		$post = function_exists( 'db_listing_post_for_domain' ) ? db_listing_post_for_domain( $name ) : null;
		$h    = ( $post && function_exists( 'db_hits_for_domain' ) ) ? db_hits_for_domain( $post->ID ) : null;
		$html = '<div class="dbo-card dbo-intel"><h2>About ' . esc_html( $name ) . '</h2>';

		// Traffic
		$html .= '<h3>Visits <span class="dbs-sub">(hits / unique visitors)</span></h3>';
		if ( $h ) {
			$cells = array( 'Today' => 'today', 'Last 7 days' => 'd7', 'This month' => 'month', 'This year' => 'year', 'All time' => 'all' );
			$html .= '<table class="widefat striped"><thead><tr>';
			foreach ( array_keys( $cells ) as $label ) {
				$html .= '<th>' . esc_html( $label ) . '</th>';
			}
			$html .= '</tr></thead><tbody><tr>';
			foreach ( $cells as $k ) {
				$html .= '<td>' . db_hits_cell( $h->{$k . '_h'}, $h->{$k . '_u'} ) . '</td>';
			}
			$html .= '</tr></tbody></table><p><a href="' . esc_url( admin_url( 'admin.php?page=db-hits&q=' . rawurlencode( $name ) ) ) . '">Pick a date range</a></p>';
		} else {
			$html .= '<p class="dbs-sub">No visit data for this domain yet (it may not be published).</p>';
		}

		// Offers on this domain, from every buyer
		$dt = db_deal_table();
		$et = db_deal_table( 'events' );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ev = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT e.created_at, e.actor, e.action, e.amount, d.id AS deal, d.buyer_email, d.buyer_name FROM {$et} e JOIN {$dt} d ON d.id = e.deal_id WHERE d.domain = %s AND e.action IN ('offer','counter','accept','decline','paid') ORDER BY e.id DESC LIMIT 40",
			$name
		) );
		// phpcs:enable
		$buyer_amts = array();
		$buyers     = array();
		foreach ( $ev as $e ) {
			if ( 'buyer' === $e->actor && 'offer' === $e->action && null !== $e->amount ) {
				$buyer_amts[]                    = (float) $e->amount;
				$buyers[ strtolower( $e->buyer_email ) ] = true;
			}
		}
		$html .= '<h3>Offers on this domain</h3>';
		if ( $buyer_amts ) {
			$html .= sprintf( '<p><strong>%d</strong> offer%s from <strong>%d</strong> buyer%s. Highest %s, lowest %s, average %s.</p>', count( $buyer_amts ), 1 === count( $buyer_amts ) ? '' : 's', count( $buyers ), 1 === count( $buyers ) ? '' : 's', esc_html( db_deal_money( max( $buyer_amts ) ) ), esc_html( db_deal_money( min( $buyer_amts ) ) ), esc_html( db_deal_money( array_sum( $buyer_amts ) / count( $buyer_amts ) ) ) );
			$labels = array( 'offer' => 'Buyer offered', 'counter' => 'We proposed', 'accept' => 'Accepted', 'decline' => 'Declined', 'paid' => 'Paid' );
			$html  .= '<table class="widefat striped"><thead><tr><th>When</th><th>Buyer</th><th>What happened</th><th>Amount</th></tr></thead><tbody>';
			foreach ( $ev as $e ) {
				$who   = ( 'seller' === $e->actor ? 'Us' : ( 'system' === $e->actor ? 'Payment' : ( $e->buyer_name ?: $e->buyer_email ) ) );
				$what  = $labels[ $e->action ] ?? $e->action;
				if ( in_array( $e->action, array( 'accept', 'decline' ), true ) ) {
					$what = ( 'seller' === $e->actor ? 'We ' : 'Buyer ' ) . strtolower( $what );
				}
				$html .= '<tr><td>' . esc_html( wp_date( 'M j, Y', strtotime( $e->created_at . ' UTC' ) ) ) . '</td><td>' . esc_html( $e->buyer_email ) . ( (int) $e->deal === (int) $deal->id ? ' <span class="dbs-sub">(this offer)</span>' : '' ) . '</td><td>' . esc_html( $what ) . '</td><td>' . ( null !== $e->amount ? esc_html( db_deal_money( $e->amount ) ) : '-' ) . '</td></tr>';
			}
			$html .= '</tbody></table>';
		} else {
			$html .= '<p class="dbs-sub">No other offers recorded.</p>';
		}

		// Comparable sales
		$sim   = db_deal_similar_sold( $name, $post );
		$html .= '<h3>Similar domains sold</h3>';
		if ( $sim ) {
			$prices = array_column( $sim, 'price' );
			$html  .= sprintf( '<p>%d similar sale%s: average %s, from %s to %s.</p>', count( $sim ), 1 === count( $sim ) ? '' : 's', esc_html( db_deal_money( array_sum( $prices ) / count( $prices ) ) ), esc_html( db_deal_money( min( $prices ) ) ), esc_html( db_deal_money( max( $prices ) ) ) );
			$html  .= '<table class="widefat striped"><thead><tr><th>Domain</th><th>Sold for</th><th>Date</th><th>How</th></tr></thead><tbody>';
			foreach ( $sim as $r ) {
				$html .= '<tr><td>' . esc_html( $r['name'] ) . '</td><td>' . esc_html( db_deal_money( $r['price'] ) ) . '</td><td>' . ( $r['date'] ? esc_html( wp_date( 'M j, Y', $r['date'] ) ) : '-' ) . '</td><td>' . esc_html( function_exists( 'db_sold_via_label' ) ? db_sold_via_label( $r['via'] ) : $r['via'] ) . '</td></tr>';
			}
			$html .= '</tbody></table><p class="dbs-sub">Similar means the same extension, a name of about the same length, or the same category. Only sales with a recorded price are shown; see Sold domains to add earlier prices.</p>';
		} else {
			$html .= '<p class="dbs-sub">No similar sold domains with a recorded price yet.</p>';
		}
		return $html . '</div>';
	}
}

if ( ! function_exists( 'db_deal_admin_detail' ) ) {
	function db_deal_admin_detail( $deal ) {
		$act    = admin_url( 'admin-post.php' );
		$events = db_deal_events( $deal->id );
		$msg    = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$can    = array(
			'accept'  => 'open' === $deal->state && (float) $deal->amount > 0,
			'counter' => in_array( $deal->state, array( 'open', 'countered', 'declined', 'expired' ), true ),
			'decline' => in_array( $deal->state, array( 'open', 'countered' ), true ),
		);
		echo '<div class="wrap"><h1>Offer for ' . esc_html( $deal->domain ) . '</h1><p><a href="' . esc_url( admin_url( 'admin.php?page=db-offers' ) ) . '">&larr; All offers</a></p>';
		if ( $msg ) {
			echo '<div class="notice notice-' . ( 0 === strpos( $msg, 'Done' ) ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<style>.dbo-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:14px 0;max-width:760px}.dbo-card h2{margin-top:0}.dbo-big{font-size:28px;font-weight:700}.dbo-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;background:#eef2f7}.dbo-intel h3{margin:18px 0 6px}.dbo-intel table{margin-bottom:6px}.dbh-u,.dbs-sub{color:#646970}.dbs-sub{font-size:12px}</style>';
		echo '<div class="dbo-card"><div class="dbo-big">' . esc_html( (float) $deal->amount > 0 ? db_deal_money( $deal->amount ) : 'No amount yet' ) . '</div>';
		echo '<p><span class="dbo-pill">' . esc_html( db_deal_states()[ $deal->state ] ?? $deal->state ) . '</span> &nbsp; ' . ( 'seller' === $deal->proposer ? 'Our price' : "Buyer's offer" ) . '</p>';
		echo '<p>Buyer: <a href="mailto:' . esc_attr( $deal->buyer_email ) . '">' . esc_html( trim( $deal->buyer_name . ' ' . $deal->buyer_email ) ) . '</a><br>Buyer\'s page: <a href="' . esc_url( db_deal_url( $deal ) ) . '" target="_blank" rel="noopener">open</a> (private link, sent to them by email)</p></div>';

		if ( $can['accept'] ) {
			echo '<div class="dbo-card"><h2>Accept this offer</h2><p>Agrees ' . esc_html( db_deal_money( $deal->amount ) . ' for ' . $deal->domain ) . '. The buyer is emailed a link to pay in full or monthly.</p>';
			echo '<form method="post" action="' . esc_url( $act ) . '">' . wp_nonce_field( 'db_deal_admin', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="db_deal_admin"><input type="hidden" name="deal" value="' . (int) $deal->id . '"><input type="hidden" name="do" value="accept"><p><label>Message to the buyer (optional)<br><textarea name="message" rows="2" class="large-text"></textarea></label></p><button class="button button-primary">Accept ' . esc_html( db_deal_money( $deal->amount ) ) . '</button></form></div>';
		}
		if ( $can['counter'] ) {
			echo '<div class="dbo-card"><h2>' . ( 'countered' === $deal->state ? 'Change our price' : 'Send a different price' ) . '</h2><form method="post" action="' . esc_url( $act ) . '">' . wp_nonce_field( 'db_deal_admin', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="db_deal_admin"><input type="hidden" name="deal" value="' . (int) $deal->id . '"><input type="hidden" name="do" value="counter">';
			echo '<p><label>Our price (USD)<br><input type="text" name="amount" inputmode="decimal" required class="regular-text" placeholder="e.g. 3500"></label></p><p><label>Open for (days)<br><input type="number" name="days" value="' . (int) DB_DEAL_COUNTER_DAYS . '" min="1" max="60" class="small-text"></label></p><p><label>Message to the buyer (optional)<br><textarea name="message" rows="2" class="large-text"></textarea></label></p><button class="button button-primary">Send price to buyer</button></form></div>';
		}
		if ( $can['decline'] ) {
			echo '<div class="dbo-card"><h2>Decline</h2><form method="post" action="' . esc_url( $act ) . '" onsubmit="return confirm(\'Decline this offer and tell the buyer?\')">' . wp_nonce_field( 'db_deal_admin', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="db_deal_admin"><input type="hidden" name="deal" value="' . (int) $deal->id . '"><input type="hidden" name="do" value="decline"><p><label>Message to the buyer (optional)<br><textarea name="message" rows="2" class="large-text"></textarea></label></p><button class="button">Decline offer</button></form></div>';
		}
		if ( ! array_filter( $can ) ) {
			echo '<div class="dbo-card"><p>' . ( 'accepted' === $deal->state ? 'A price is agreed. Waiting for the buyer to pay.' : ( 'paid' === $deal->state ? 'Paid. See Domain Brothers > Sales.' : 'Nothing to do right now.' ) ) . '</p></div>';
		}

		echo db_deal_intel_html( $deal ); // phpcs:ignore WordPress.Security.EscapeOutput

		echo '<div class="dbo-card"><h2>History</h2><table class="widefat striped"><tbody>';
		foreach ( $events as $e ) {
			echo '<tr><td>' . esc_html( wp_date( 'M j, g:i a', strtotime( $e->created_at . ' UTC' ) ) ) . '</td><td>' . esc_html( ucfirst( $e->actor ) . ' ' . $e->action . ( null !== $e->amount ? ' ' . db_deal_money( $e->amount ) : '' ) ) . '</td><td>' . esc_html( (string) $e->message ) . '</td></tr>';
		}
		echo '</tbody></table></div></div>';
	}
}

add_action( 'admin_post_db_deal_admin', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_deal_admin' ) ) {
		wp_die( 'Not allowed.' );
	}
	$deal = db_deal_get( isset( $_POST['deal'] ) ? (int) $_POST['deal'] : 0 );
	$to   = static function ( $id, $text ) {
		wp_safe_redirect( add_query_arg( 'msg', rawurlencode( $text ), admin_url( 'admin.php?page=db-offers&deal=' . (int) $id ) ) );
		exit;
	};
	if ( ! $deal ) {
		wp_safe_redirect( admin_url( 'admin.php?page=db-offers' ) );
		exit;
	}
	$do      = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$amount  = isset( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '';
	$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
	$days    = isset( $_POST['days'] ) ? max( 1, min( 60, (int) $_POST['days'] ) ) : DB_DEAL_COUNTER_DAYS;
	if ( ! in_array( $do, array( 'accept', 'counter', 'decline' ), true ) ) {
		$to( $deal->id, 'Unknown action.' );
	}
	$res = db_deal_apply( $deal, 'seller', $do, $amount, $message, $days );
	if ( is_wp_error( $res ) ) {
		$to( $deal->id, $res->get_error_message() );
	}
	$to( $deal->id, 'Done. The buyer has been emailed.' );
} );

/* ───────────────────── 8. Email failures are visible ──────────────────── */

// Offers are recorded whether or not email works; if it does not, say so in the admin.
add_action( 'wp_mail_failed', function ( $err ) {
	$list   = (array) get_option( 'db_mail_failures', array() );
	$list[] = array( 'ts' => time(), 'subject' => function_exists( 'mb_substr' ) ? mb_substr( (string) ( $err->get_error_data()['subject'] ?? '' ), 0, 80 ) : '', 'error' => substr( wp_strip_all_tags( $err->get_error_message() ), 0, 200 ) );
	update_option( 'db_mail_failures', array_slice( $list, -20 ), false );
} );

add_action( 'wp_mail_succeeded', function () {
	update_option( 'db_mail_ok_ts', time(), false );
} );

add_action( 'admin_notices', function () {
	$screen = get_current_screen();
	if ( ! $screen || false === strpos( (string) $screen->id, 'db-' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Only failures after the last successful send count, so the warning clears once email works again.
	$since  = (int) get_option( 'db_mail_ok_ts', 0 );
	$recent = array_filter( (array) get_option( 'db_mail_failures', array() ), static function ( $f ) use ( $since ) {
		return ( $f['ts'] ?? 0 ) > max( $since, time() - DAY_IN_SECONDS );
	} );
	if ( $recent ) {
		$last = end( $recent );
		echo '<div class="notice notice-error"><p><strong>Email is failing.</strong> ' . count( $recent ) . ' message(s) could not be sent in the last 24 hours (latest: ' . esc_html( $last['error'] ) . '). Offers and sales are still recorded, but you and your customers are not being emailed. Check the site\'s SMTP settings.</p></div>';
	}
} );
