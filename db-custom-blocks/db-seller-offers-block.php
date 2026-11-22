<?php
/**
 * Seller marketplace phase 3: sellers negotiate offers on their own names.
 *
 * Offers on a seller's live names use the site's existing offer engine
 * (db-offers-block.php): the seller's accept, counter and decline are the
 * engine's "seller" moves, so the buyer gets the same emails and an accepted
 * price opens checkout exactly as when the broker answers. What this adds:
 *
 *  - an "Offers on your names" section in /seller/ with each offer's history;
 *  - accept, counter (price and note) and decline from that section;
 *  - offers below the seller's private minimum answered at once with the minimum;
 *  - emails to the seller for new offers and buyer replies;
 *  - broker oversight: the broker is told about every seller move and can act
 *    on any offer from WP Admin > Offers at any time;
 *  - unanswered offers: a reminder after 3 days, closed after 14 days.
 *
 * Buyers' email addresses are never shown to sellers; the broker stays in the
 * middle of every conversation. Rules: db-seller-lib.php (tested offline).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_seller_listing_for_domain' ) ) {
	/** The approved seller listing (with seller email) for a live domain, or null. */
	function db_seller_listing_for_domain( $domain ) {
		global $wpdb;
		$l = db_seller_table( 'listings' );
		$s = db_seller_table( 'sellers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT l.*, s.email AS seller_email, s.status AS seller_status FROM {$l} l JOIN {$s} s ON s.id = l.seller_id WHERE l.domain = %s AND l.review_status = 'approved' ORDER BY l.id DESC LIMIT 1", strtolower( trim( (string) $domain ) ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}

if ( ! function_exists( 'db_seller_deals' ) ) {
	/** Offers on a seller's live names, newest activity first. */
	function db_seller_deals( $seller_id ) {
		global $wpdb;
		if ( ! function_exists( 'db_deal_table' ) ) {
			return array();
		}
		$l = db_seller_table( 'listings' );
		$d = db_deal_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT d.* FROM {$d} d JOIN {$l} l ON l.domain = d.domain AND l.review_status = 'approved' WHERE l.seller_id = %d ORDER BY FIELD(d.state, 'open', 'countered', 'accepted') DESC, d.updated_at DESC LIMIT 100", (int) $seller_id ) );
		return array_map( 'db_deal_expire_if_due', $rows );
	}
}

if ( ! function_exists( 'db_seller_offer_mail' ) ) {
	/** Emails the seller about an offer on one of their names. */
	function db_seller_offer_mail( array $listing, $deal, $headline, $text ) {
		if ( ! is_email( $listing['seller_email'] ?? '' ) || ! function_exists( 'db_seller_mail' ) ) {
			return;
		}
		$url  = add_query_arg( 'offer', (int) $deal->id, home_url( '/seller/' ) ) . '#dbsl-offers';
		$html = '<p>' . esc_html( $text ) . '</p>'
			. '<p><strong>Name:</strong> ' . esc_html( $deal->domain ) . '<br><strong>Current price:</strong> ' . esc_html( db_deal_money( $deal->amount ) ) . '</p>'
			. '<p><a href="' . esc_url( $url ) . '">Open the offer in your seller dashboard</a></p>'
			. '<p>Domain Brothers stays in the middle of every conversation: the buyer never sees your email, and our broker can help at any time.</p>';
		db_seller_mail( $listing['seller_email'], $headline, $html );
	}
}

if ( ! function_exists( 'db_seller_on_buyer_move' ) ) {
	/**
	 * A buyer made an offer (new or repeated), accepted a counter or declined,
	 * on a seller's name: answer below-minimum offers and tell the seller.
	 */
	function db_seller_on_buyer_move( $deal, $action, $message = '' ) {
		$listing = $deal ? db_seller_listing_for_domain( $deal->domain ) : null;
		if ( ! $listing ) {
			return;
		}
		if ( 'offer' === $action && 'open' === $deal->state ) {
			$min = db_seller_auto_counter( $deal->amount, (int) $listing['min_offer'] );
			if ( null !== $min ) {
				$GLOBALS['db_seller_auto'] = true;
				$res = db_deal_apply( $deal, 'seller', 'counter', $min, 'Thank you for your offer. The lowest price the owner accepts for ' . $deal->domain . ' is ' . db_deal_money( $min ) . '.' );
				unset( $GLOBALS['db_seller_auto'] );
				if ( ! is_wp_error( $res ) ) {
					db_seller_offer_mail( $listing, $res, 'Offer on ' . $deal->domain . ' answered with your minimum', 'A buyer offered ' . db_deal_money( $deal->amount ) . ', below your private minimum, so we replied with your minimum of ' . db_deal_money( $min ) . '. You will hear from us if they accept or offer again.' );
					return;
				}
			}
			db_seller_offer_mail( $listing, $deal, 'New offer on ' . $deal->domain . ': ' . db_deal_money( $deal->amount ), 'A buyer offered ' . db_deal_money( $deal->amount ) . ' for ' . $deal->domain . '. Accept it, send a counter price or decline it from your seller dashboard.' . ( '' !== trim( (string) $message ) ? ' Their note: "' . trim( (string) $message ) . '"' : '' ) );
			return;
		}
		if ( 'accept' === $action ) {
			db_seller_offer_mail( $listing, $deal, 'The buyer accepted ' . db_deal_money( $deal->amount ) . ' for ' . $deal->domain, 'The buyer accepted your price of ' . db_deal_money( $deal->amount ) . '. They can now pay; we will tell you when payment arrives and the transfer can start.' );
		} elseif ( 'decline' === $action ) {
			db_seller_offer_mail( $listing, $deal, 'The buyer declined on ' . $deal->domain, 'The buyer declined the price on ' . $deal->domain . '. The name stays listed and open to new offers.' );
		}
	}
}

// New offer from the offer form: the engine creates the deal at priority 10.
add_action( 'db_crm_lead_created', function ( $lead_id, $data ) {
	if ( 'offer_form' !== ( $data['source'] ?? '' ) || ! function_exists( 'db_deal_get_by_lead' ) ) {
		return;
	}
	$deal = db_deal_get_by_lead( $lead_id );
	if ( $deal && 'open' === $deal->state ) {
		db_seller_on_buyer_move( $deal, 'offer', '' );
	}
}, 20, 2 );

// Repeat offers and buyer replies on an existing negotiation.
add_action( 'db_deal_changed', function ( $deal, $actor, $action, $message ) {
	if ( 'buyer' === $actor ) {
		db_seller_on_buyer_move( $deal, $action, $message );
	}
}, 20, 4 );

if ( ! function_exists( 'db_seller_offer_post' ) ) {
	/** Handles the seller's accept / counter / decline. Returns flash messages. */
	function db_seller_offer_post( array $seller, array $p ) {
		$deal = function_exists( 'db_deal_get' ) ? db_deal_get( (int) ( $p['deal'] ?? 0 ) ) : null;
		if ( ! $deal ) {
			return array( array( 'bad', 'That offer was not found.' ) );
		}
		$listing = db_seller_listing_for_domain( $deal->domain );
		if ( ! $listing || (int) $listing['seller_id'] !== (int) $seller['id'] ) {
			return array( array( 'bad', 'That offer is not on one of your names.' ) );
		}
		$deal    = db_deal_expire_if_due( $deal );
		$do      = sanitize_key( (string) ( $p['do'] ?? '' ) );
		$amount  = (string) ( $p['amount'] ?? '' );
		$message = mb_substr( sanitize_textarea_field( (string) ( $p['message'] ?? '' ) ), 0, 1000 );
		$why     = db_seller_offer_check( $do, $amount, $deal->state );
		if ( '' !== $why ) {
			return array( array( 'bad', $why ) );
		}
		$res = db_deal_apply( $deal, 'seller', $do, 'counter' === $do ? db_seller_clean_amount( $amount ) : null, $message );
		if ( is_wp_error( $res ) ) {
			return array( array( 'bad', $res->get_error_message() ) );
		}
		$words = array( 'accept' => 'accepted', 'counter' => 'countered at ' . db_deal_money( $res->amount ), 'decline' => 'declined' );
		if ( function_exists( 'db_plan_notify_admin' ) ) {
			db_plan_notify_admin( 'Seller ' . $words[ $do ] . ' an offer on ' . $deal->domain, array(
				'Domain' => $deal->domain,
				'Seller' => $seller['email'],
				'Move'   => ucfirst( $words[ $do ] ),
				'Note'   => '' !== $message ? $message : '(none)',
				'Open'   => admin_url( 'admin.php?page=db-offers&deal=' . (int) $deal->id ),
			) );
		}
		$tail = array( 'accept' => ' The buyer has been emailed a link to pay.', 'counter' => ' The buyer has been emailed your price.', 'decline' => ' The buyer has been told.' );
		return array( array( 'ok', 'Offer on ' . $deal->domain . ' ' . $words[ $do ] . '.' . $tail[ $do ] ) );
	}
}

if ( ! function_exists( 'db_seller_offers_html' ) ) {
	/** The "Offers on your names" section of the seller dashboard. */
	function db_seller_offers_html( array $seller, $self, $t ) {
		$deals = db_seller_deals( (int) $seller['id'] );
		$h     = '<section class="dbsl-card dbsl-sec" id="dbsl-offers" aria-labelledby="dbsl-off-h"><h2 id="dbsl-off-h">Offers on your names</h2>';
		if ( ! $deals ) {
			return $h . '<p>No offers yet. When a buyer makes an offer on one of your live names, it appears here and we email you. Offers below your private minimum are answered with your minimum automatically.</p></section>';
		}
		$states = db_deal_states();
		$label  = array( 'open' => 'Waiting for you', 'countered' => 'Waiting for the buyer', 'accepted' => 'Agreed: waiting for payment', 'declined' => 'Declined', 'paid' => 'Paid', 'expired' => 'Closed' );
		$tone   = array( 'open' => 'warn', 'countered' => 'info', 'accepted' => 'ok', 'paid' => 'ok', 'declined' => 'off', 'expired' => 'off' );
		$open   = count( array_filter( $deals, static function ( $d ) { return 'open' === $d->state; } ) );
		$h     .= '<p>' . ( $open ? '<strong>' . $open . ' ' . ( 1 === $open ? 'offer needs' : 'offers need' ) . ' your answer.</strong> ' : '' ) . 'Buyers never see your email; Domain Brothers keeps every conversation and our broker can step in to help.</p><div class="dbsl-offers">';
		$focus  = isset( $_GET['offer'] ) ? (int) $_GET['offer'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( $deals as $d ) {
			$st    = (string) $d->state;
			$first = trim( (string) strtok( (string) $d->buyer_name, ' ' ) );
			$h    .= '<details class="dbsl-offer dbsl-offer--' . esc_attr( $tone[ $st ] ?? 'off' ) . '"' . ( ( 'open' === $st || (int) $d->id === $focus ) ? ' open' : '' ) . ' id="dbsl-offer-' . (int) $d->id . '">'
				. '<summary><span class="dbsl-offer-name">' . esc_html( $d->domain ) . '</span><span class="dbsl-offer-amt">' . esc_html( db_deal_money( $d->amount ) ) . '</span><span class="dbsl-pill dbsl-pill--' . esc_attr( $tone[ $st ] ?? 'off' ) . '">' . esc_html( $label[ $st ] ?? ( $states[ $st ] ?? $st ) ) . '</span></summary>';
			$h    .= '<ol class="dbsl-hist">';
			foreach ( db_deal_events( (int) $d->id ) as $e ) {
				$who  = 'buyer' === $e->actor ? ( '' !== $first ? esc_html( $first ) . ' (buyer)' : 'Buyer' ) : ( 'seller' === $e->actor ? 'Owner side' : 'Domain Brothers' );
				$what = array( 'offer' => 'offered', 'counter' => 'countered', 'accept' => 'accepted', 'decline' => 'declined', 'paid' => 'paid', 'expire' => 'closed the offer' );
				$h   .= '<li><span class="dbsl-hist-when">' . esc_html( wp_date( 'M j, g:i a', strtotime( $e->created_at . ' UTC' ) ) ) . '</span> <strong>' . $who . '</strong> ' . esc_html( $what[ $e->action ] ?? $e->action ) . ( null !== $e->amount && in_array( $e->action, array( 'offer', 'counter', 'accept', 'paid' ), true ) ? ' <strong>' . esc_html( db_deal_money( $e->amount ) ) . '</strong>' : '' )
					. ( '' !== trim( (string) $e->message ) ? '<span class="dbsl-hist-msg">' . esc_html( $e->message ) . '</span>' : '' ) . '</li>';
			}
			$h .= '</ol>';
			if ( in_array( $st, array( 'open', 'countered', 'declined', 'expired' ), true ) ) {
				$base = '<input type="hidden" name="db_seller_action" value="offer"><input type="hidden" name="t" value="' . esc_attr( $t ) . '"><input type="hidden" name="deal" value="' . (int) $d->id . '">';
				$h   .= '<div class="dbsl-offer-acts">';
				if ( 'open' === $st ) {
					$h .= '<form method="post" action="' . $self . '">' . $base . '<input type="hidden" name="do" value="accept"><button type="submit" class="dbsl-btn">Accept ' . esc_html( db_deal_money( $d->amount ) ) . '</button></form>';
				}
				$h .= '<form method="post" action="' . $self . '" class="dbsl-counter">' . $base . '<input type="hidden" name="do" value="counter">'
					. '<label for="dbsl-c-' . (int) $d->id . '">Counter price (USD)</label><input type="text" inputmode="numeric" id="dbsl-c-' . (int) $d->id . '" name="amount" placeholder="e.g. 4500" required>'
					. '<label for="dbsl-m-' . (int) $d->id . '">Note to the buyer (optional)</label><textarea id="dbsl-m-' . (int) $d->id . '" name="message" maxlength="1000" rows="2"></textarea>'
					. '<button type="submit" class="dbsl-btn dbsl-btn--ghost">Send counter price</button></form>';
				if ( in_array( $st, array( 'open', 'countered' ), true ) ) {
					$h .= '<form method="post" action="' . $self . '" onsubmit="return confirm(\'Decline this offer? The buyer will be told.\')">' . $base . '<input type="hidden" name="do" value="decline"><button type="submit" class="dbsl-link-danger">Decline</button></form>';
				}
				$h .= '</div>';
			} elseif ( 'accepted' === $st ) {
				$h .= '<p class="dbsl-note">The buyer has a link to pay ' . esc_html( db_deal_money( $d->amount ) ) . ( $d->expires_at ? ' until ' . esc_html( wp_date( 'F j', strtotime( $d->expires_at . ' UTC' ) ) ) : '' ) . '. We will email you when payment arrives.</p>';
			}
			$h .= '</details>';
		}
		return $h . '</div></section>';
	}
}

if ( ! function_exists( 'db_seller_offers_css' ) ) {
	function db_seller_offers_css() {
		return '.dbsl-offers{display:grid;gap:10px}.dbsl-offer{border:1px solid #e4e7ec;border-radius:16px;background:#fff;overflow:hidden}'
			. '.dbsl-offer>summary{display:flex;flex-wrap:wrap;align-items:center;gap:10px 16px;padding:14px 18px;cursor:pointer;list-style:none}.dbsl-offer>summary::-webkit-details-marker{display:none}'
			. '.dbsl-offer>summary::after{content:"";margin-left:auto;width:9px;height:9px;border-right:2px solid #0a1628;border-bottom:2px solid #0a1628;transform:rotate(45deg);transition:transform .2s}.dbsl-offer[open]>summary::after{transform:rotate(225deg)}'
			. '.dbsl-offer--warn{border-color:#f3c46b;box-shadow:0 0 0 3px #fff4dc}.dbsl-offer-name{font-weight:800;font-size:16px;color:#0a1628}.dbsl-offer-amt{font-weight:800;color:#3f6212}'
			. '.dbsl-pill{padding:3px 10px;border-radius:999px;font-size:12.5px;font-weight:800}.dbsl-pill--warn{background:#fff1d6;color:#8a5a00}.dbsl-pill--info{background:#e8f0fe;color:#1d4ed8}.dbsl-pill--ok{background:#e6f6df;color:#2f6b12}.dbsl-pill--off{background:#eef1f4;color:#4b5563}'
			. '.dbsl-hist{margin:0;padding:6px 18px 4px 38px;border-top:1px solid #eef1f4;display:grid;gap:8px;font-size:14.5px;color:#1f2937}.dbsl-hist-when{color:#6b7280;font-size:13px}.dbsl-hist-msg{display:block;margin-top:3px;padding-left:10px;border-left:3px solid #d1d5db;color:#4b5563}'
			. '.dbsl-offer-acts{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;padding:14px 18px 18px;border-top:1px solid #eef1f4;background:#fafbfc}.dbsl-offer-acts form{margin:0}'
			. '.dbsl-counter{display:grid;gap:6px;flex:1 1 280px;max-width:420px}.dbsl-counter label{font-size:13.5px;font-weight:700}.dbsl-counter input,.dbsl-counter textarea{width:100%;box-sizing:border-box;padding:10px 12px;border:1.5px solid #d3d9e1;border-radius:12px;font-size:15px}'
			. 'html body .dbsl button.dbsl-link-danger,html body .dbsl-offer-acts button.dbsl-link-danger{min-height:44px;padding:0 12px;border:0!important;background:transparent!important;box-shadow:none!important;color:#b42318!important;font-weight:700;text-decoration:underline;cursor:pointer}html body .dbsl-offer-acts button.dbsl-link-danger:hover{color:#7a1610!important}.dbsl-note{margin:0;padding:12px 18px 16px;color:#374151;border-top:1px solid #eef1f4}';
	}
}

/* Hourly: remind sellers about offers waiting for them, close abandoned ones. */
add_action( 'db_seller_verify_cron', 'db_seller_offer_sweep', 20 );
if ( ! function_exists( 'db_seller_offer_sweep' ) ) {
	function db_seller_offer_sweep() {
		global $wpdb;
		if ( ! function_exists( 'db_deal_table' ) ) {
			return;
		}
		$l = db_seller_table( 'listings' );
		$d = db_deal_table();
		$e = db_deal_table( 'events' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT d.*, TIMESTAMPDIFF(HOUR, (SELECT MAX(ev.created_at) FROM {$e} ev WHERE ev.deal_id = d.id), NOW()) AS idle_h FROM {$d} d JOIN {$l} l ON l.domain = d.domain AND l.review_status = 'approved' WHERE d.state = 'open' LIMIT 200" );
		$sent = (array) get_option( 'db_seller_offer_reminded', array() );
		foreach ( $rows as $deal ) {
			$listing = db_seller_listing_for_domain( $deal->domain );
			if ( ! $listing ) {
				continue;
			}
			$due = db_seller_offer_due( $deal->state, (int) $deal->idle_h, isset( $sent[ (int) $deal->id ] ) );
			if ( 'remind' === $due ) {
				db_seller_offer_mail( $listing, $deal, 'Reminder: an offer on ' . $deal->domain . ' is waiting for you', 'A buyer offered ' . db_deal_money( $deal->amount ) . ' for ' . $deal->domain . ' and is waiting for your answer. Offers without a reply close after 14 days.' );
				if ( function_exists( 'db_plan_notify_admin' ) ) {
					db_plan_notify_admin( 'Seller has not answered an offer on ' . $deal->domain, array( 'Domain' => $deal->domain, 'Seller' => $listing['seller_email'], 'Offer' => db_deal_money( $deal->amount ), 'Open' => admin_url( 'admin.php?page=db-offers&deal=' . (int) $deal->id ) ) );
				}
				$sent[ (int) $deal->id ] = time();
			} elseif ( 'expire' === $due ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $d, array( 'state' => 'expired', 'expires_at' => null ), array( 'id' => (int) $deal->id ) );
				db_deal_log( (int) $deal->id, 'system', 'expire', $deal->amount, 'The owner did not reply within 14 days.' );
				db_deal_sync_lead( $deal->lead_id, 'expired', null );
				$fresh = db_deal_get( (int) $deal->id );
				if ( $fresh && function_exists( 'db_deal_email_buyer' ) ) {
					db_deal_email_buyer( $fresh, 'declined', 'The owner did not reply within 14 days, so this offer has closed. You are welcome to make a new offer.' );
				}
				db_seller_offer_mail( $listing, $fresh ? $fresh : $deal, 'An offer on ' . $deal->domain . ' closed without a reply', 'The offer of ' . db_deal_money( $deal->amount ) . ' on ' . $deal->domain . ' closed after 14 days without an answer. The buyer can still make a new offer.' );
				unset( $sent[ (int) $deal->id ] );
			}
		}
		update_option( 'db_seller_offer_reminded', array_slice( $sent, -500, null, true ), false );
	}
}
