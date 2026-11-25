<?php
/**
 * Seller marketplace phase 4: sales and payouts.
 *
 *  - A sale of a seller's name (Stripe full payment, a payment plan, or a sale
 *    the broker records by hand) creates one payout record: gross, commission
 *    (15%, or 10% for exclusive names, $100 minimum) and the seller's net.
 *  - Plan sales start as "collecting" and add each instalment; when the plan
 *    is paid off the payout waits for the transfer.
 *  - States: collecting > waiting_transfer > waiting_confirm > due > paid,
 *    with "held" possible at any point before paid (released back to where it was).
 *  - Due date: 7 days after the buyer confirms control, or 14 days after the
 *    transfer with no dispute (the hourly job moves those to "due").
 *  - Admin: Tools > Seller payouts (mark transferred, buyer confirmed, hold,
 *    release, mark paid with a reference, record an off-site sale).
 *  - Seller: "Sales and payouts" in /seller/ with a CSV statement.
 *  - Audit log of prices, publication, offers and payouts (Tools > Seller payouts).
 *  - Money is sent outside the site (bank transfer, or Stripe Connect once the
 *    owner enables it); the site records it.
 *
 * Rules: db-seller-lib.php (tested offline).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

define( 'DB_SELLER_PAYOUT_DB_VERSION', '1' );

add_action( 'init', function () {
	if ( DB_SELLER_PAYOUT_DB_VERSION === get_option( 'db_seller_payout_db_ver' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$cs = $wpdb->get_charset_collate();
	dbDelta( "CREATE TABLE {$wpdb->prefix}db_seller_payouts (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		seller_id bigint(20) unsigned NOT NULL,
		listing_id bigint(20) unsigned NOT NULL DEFAULT 0,
		domain varchar(253) NOT NULL,
		kind varchar(8) NOT NULL DEFAULT 'full',
		gross decimal(12,2) NOT NULL DEFAULT 0,
		commission decimal(12,2) NOT NULL DEFAULT 0,
		net decimal(12,2) NOT NULL DEFAULT 0,
		exclusive tinyint(1) NOT NULL DEFAULT 0,
		state varchar(20) NOT NULL DEFAULT 'waiting_transfer',
		held_from varchar(20) NOT NULL DEFAULT '',
		transferred_at datetime DEFAULT NULL,
		confirmed_at datetime DEFAULT NULL,
		due_at datetime DEFAULT NULL,
		paid_at datetime DEFAULT NULL,
		reference varchar(120) NOT NULL DEFAULT '',
		note varchar(255) NOT NULL DEFAULT '',
		pi_ids text NULL,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY seller_id (seller_id),
		KEY domain (domain(64)),
		KEY state (state)
	) {$cs};" );
	dbDelta( "CREATE TABLE {$wpdb->prefix}db_seller_audit (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		seller_id bigint(20) unsigned NOT NULL DEFAULT 0,
		domain varchar(253) NOT NULL DEFAULT '',
		actor varchar(40) NOT NULL DEFAULT '',
		action varchar(40) NOT NULL DEFAULT '',
		detail varchar(255) NOT NULL DEFAULT '',
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		KEY seller_id (seller_id),
		KEY created_at (created_at)
	) {$cs};" );
	update_option( 'db_seller_payout_db_ver', DB_SELLER_PAYOUT_DB_VERSION, false );
}, 6 );

if ( ! function_exists( 'db_seller_audit' ) ) {
	/** One line in the seller audit log. */
	function db_seller_audit( $seller_id, $domain, $action, $detail = '', $actor = '' ) {
		global $wpdb;
		if ( '' === $actor ) {
			$u     = wp_get_current_user();
			$actor = ( $u && $u->ID ) ? 'admin:' . $u->user_login : ( function_exists( 'db_wl_email' ) && '' !== db_wl_email() ? 'seller' : 'system' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $wpdb->prefix . 'db_seller_audit', array(
			'seller_id'  => (int) $seller_id,
			'domain'     => substr( (string) $domain, 0, 253 ),
			'actor'      => substr( (string) $actor, 0, 40 ),
			'action'     => substr( (string) $action, 0, 40 ),
			'detail'     => substr( (string) $detail, 0, 255 ),
			'created_at' => current_time( 'mysql', true ),
		) );
	}
}

/* Audit the existing seller events. */
add_action( 'db_deal_changed', function ( $deal, $actor, $action ) {
	$l = function_exists( 'db_seller_listing_for_domain' ) ? db_seller_listing_for_domain( $deal->domain ) : null;
	if ( $l ) {
		db_seller_audit( (int) $l['seller_id'], $deal->domain, 'offer_' . $action, $actor . ' ' . $action . ( in_array( $action, array( 'offer', 'counter', 'accept' ), true ) ? ' at ' . db_deal_money( $deal->amount ) : '' ), 'buyer' === $actor ? 'buyer' : '' );
	}
}, 30, 3 );
add_action( 'updated_post_meta', function ( $mid, $post_id, $key, $value ) {
	if ( 'domain_price' !== $key || 'domain' !== get_post_type( $post_id ) ) {
		return;
	}
	$l = function_exists( 'db_seller_listing_for_domain' ) ? db_seller_listing_for_domain( strtolower( get_the_title( $post_id ) ) ) : null;
	if ( $l && (int) $l['post_id'] === (int) $post_id ) {
		db_seller_audit( (int) $l['seller_id'], $l['domain'], 'price', 'Price set to ' . ( (int) $value > 0 ? '$' . number_format( (int) $value ) : 'Make an offer' ) );
	}
}, 10, 4 );
add_action( 'added_post_meta', function ( $mid, $post_id, $key ) {
	if ( 'db_seller_id' === $key ) {
		db_seller_audit( (int) get_post_meta( $post_id, 'db_seller_id', true ), strtolower( get_the_title( $post_id ) ), 'published', 'Listing went live (post ' . (int) $post_id . ')' );
	}
}, 10, 3 );

if ( ! function_exists( 'db_seller_payout_get' ) ) {
	function db_seller_payout_get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}db_seller_payouts WHERE id = %d", (int) $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}

if ( ! function_exists( 'db_seller_payout_open_for' ) ) {
	/** The newest payout for a domain that is not paid yet, or null. */
	function db_seller_payout_open_for( $domain ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}db_seller_payouts WHERE domain = %s AND state <> 'paid' ORDER BY id DESC LIMIT 1", strtolower( (string) $domain ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}

if ( ! function_exists( 'db_seller_payout_create' ) ) {
	/**
	 * Records a sale of a seller's name. Returns the payout id, or 0 when the
	 * domain is not a seller listing.
	 */
	function db_seller_payout_create( $domain, $gross, $kind = 'full', $pi_id = '', $state = 'waiting_transfer' ) {
		global $wpdb;
		$l = db_seller_listing_for_sale( $domain );
		if ( ! $l ) {
			return 0;
		}
		$split = db_seller_payout_split( $gross, ! empty( $l['exclusive'] ) );
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $wpdb->prefix . 'db_seller_payouts', array(
			'seller_id'  => (int) $l['seller_id'],
			'listing_id' => (int) $l['id'],
			'domain'     => strtolower( (string) $domain ),
			'kind'       => 'plan' === $kind ? 'plan' : ( 'manual' === $kind ? 'manual' : 'full' ),
			'gross'      => $split['gross'],
			'commission' => $split['commission'],
			'net'        => $split['net'],
			'exclusive'  => ! empty( $l['exclusive'] ) ? 1 : 0,
			'state'      => $state,
			'pi_ids'     => (string) $pi_id,
			'created_at' => $now,
			'updated_at' => $now,
		) );
		$id = (int) $wpdb->insert_id;
		db_seller_audit( (int) $l['seller_id'], $domain, 'sale', ucfirst( $kind ) . ' sale recorded: gross $' . number_format( $split['gross'], 2 ) . ', net $' . number_format( $split['net'], 2 ), 'system' );
		if ( 'collecting' === $state ) {
			db_seller_payout_mail( $l, 'Sold on a payment plan: ' . $domain, 'A buyer is paying for ' . $domain . ' monthly. Keep the name in your account and do not change it: we tell you when the plan is paid off and the transfer can start. Your payout grows with each payment.' );
		} else {
			db_seller_payout_mail( $l, 'Sold: ' . $domain . '. Next step: transfer', 'Good news: ' . $domain . ' sold for $' . number_format( $split['gross'], 2 ) . '. Your payout after commission is $' . number_format( $split['net'], 2 ) . '. Our broker will email the transfer steps (usually an authorization code or a push to the buyer\'s registrar). Please do not change the name in the meantime.' );
		}
		if ( function_exists( 'db_plan_notify_admin' ) ) {
			db_plan_notify_admin( 'Seller sale recorded: ' . $domain, array( 'Domain' => $domain, 'Seller' => $l['seller_email'], 'Kind' => $kind, 'Gross' => '$' . number_format( $split['gross'], 2 ), 'Net to seller' => '$' . number_format( $split['net'], 2 ), 'Payouts' => admin_url( 'tools.php?page=db-seller-payouts' ) ) );
		}
		return $id;
	}
}

if ( ! function_exists( 'db_seller_payout_mail' ) ) {
	function db_seller_payout_mail( array $listing, $subject, $text ) {
		if ( ! is_email( $listing['seller_email'] ?? '' ) || ! function_exists( 'db_seller_mail' ) ) {
			return;
		}
		$url = home_url( '/seller/' ) . '#dbsl-payouts';
		db_seller_mail( $listing['seller_email'], $subject, '<p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( $url ) . '">See your sales and payouts</a></p>' );
	}
}

if ( ! function_exists( 'db_seller_listing_for_sale' ) ) {
	/** The seller listing behind a sale: live, or already marked sold (later plan payments). */
	function db_seller_listing_for_sale( $domain ) {
		global $wpdb;
		$l = db_seller_table( 'listings' );
		$s = db_seller_table( 'sellers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT l.*, s.email AS seller_email, s.status AS seller_status FROM {$l} l JOIN {$s} s ON s.id = l.seller_id WHERE l.domain = %s AND l.review_status IN ('approved','sold') ORDER BY FIELD(l.review_status,'approved','sold'), l.id DESC LIMIT 1", strtolower( trim( (string) $domain ) ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}
}

if ( ! function_exists( 'db_seller_listing_mark_sold' ) ) {
	/**
	 * Closes a seller listing once its sale is complete. 'sold' is final: the ownership re-check,
	 * expiry warnings, claims, offers, pricing and pointing all work on 'approved' listings only,
	 * so the seller is no longer emailed about (or able to act on) a name the buyer now owns
	 * (bug register batch 2, SL-3).
	 */
	function db_seller_listing_mark_sold( $domain ) {
		global $wpdb;
		$row = function_exists( 'db_seller_listing_for_domain' ) ? db_seller_listing_for_domain( $domain ) : null;
		if ( ! $row ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( db_seller_table( 'listings' ), array( 'review_status' => 'sold', 'offline_reason' => '', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row['id'] ) );
		if ( function_exists( 'db_seller_audit' ) ) {
			db_seller_audit( (int) $row['seller_id'], strtolower( (string) $domain ), 'sold', 'Sale complete: the listing is closed.', 'system' );
		}
		return true;
	}
}

/* Sales from Stripe: full payments and payment plans. */
add_action( 'db_order_event', function ( $kind, $data ) {
	global $wpdb;
	$domain = strtolower( (string) ( $data['domain'] ?? '' ) );
	if ( '' === $domain || ! db_seller_listing_for_sale( $domain ) ) {
		return;
	}
	$pi   = (string) ( $data['pi_id'] ?? '' );
	$open = db_seller_payout_open_for( $domain );
	if ( '' !== $pi && $open && false !== strpos( (string) $open['pi_ids'], $pi ) ) {
		return; // this payment is already counted (webhook retry)
	}
	if ( 'full' === $kind ) {
		db_seller_payout_create( $domain, (float) ( $data['amount_usd'] ?? 0 ), 'full', $pi );
		db_seller_listing_mark_sold( $domain );
		return;
	}
	if ( 'plan_installment' !== $kind ) {
		return;
	}
	$n      = (int) ( $data['installment'] ?? 0 );
	$months = (int) ( $data['total_months'] ?? 0 );
	$amount = (float) ( $data['amount_usd'] ?? 0 );
	if ( ! $open || 'plan' !== $open['kind'] ) {
		$id   = db_seller_payout_create( $domain, $amount, 'plan', $pi, 'collecting' );
		$open = db_seller_payout_get( $id );
	} else {
		$l     = db_seller_listing_for_sale( $domain );
		$split = db_seller_payout_split( (float) $open['gross'] + $amount, ! empty( $open['exclusive'] ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'db_seller_payouts', array( 'gross' => $split['gross'], 'commission' => $split['commission'], 'net' => $split['net'], 'pi_ids' => trim( $open['pi_ids'] . ',' . $pi, ',' ), 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $open['id'] ) );
		db_seller_audit( (int) $open['seller_id'], $domain, 'plan_payment', 'Instalment ' . $n . ' of ' . $months . ': $' . number_format( $amount, 2 ), 'system' );
		$open = db_seller_payout_get( (int) $open['id'] );
		unset( $l );
	}
	if ( $open && $months > 0 && $n >= $months && 'collecting' === $open['state'] ) {
		db_seller_payout_apply( $open, 'plan_done', '', 'system' );
		db_seller_listing_mark_sold( $domain ); // the seller holds the name until the plan is paid
	}
}, 30, 2 );

if ( ! function_exists( 'db_seller_payout_apply' ) ) {
	/**
	 * Moves a payout. Returns the updated row or a message string on refusal.
	 */
	function db_seller_payout_apply( array $p, $action, $reference = '', $actor = '' ) {
		global $wpdb;
		$now    = time();
		$due_ts = $p['due_at'] ? (int) strtotime( $p['due_at'] . ' UTC' ) : 0;
		$new    = db_seller_payout_move( $p['state'], $action, $now, $due_ts, $p['held_from'] );
		if ( null === $new ) {
			if ( 'pay' === $action && 'due' === $p['state'] && $due_ts > $now ) {
				return 'This payout is not due until ' . wp_date( 'F j, Y', $due_ts ) . '.';
			}
			return 'That step is not possible for a payout that is "' . ( db_seller_payout_states()[ $p['state'] ] ?? $p['state'] ) . '".';
		}
		$set = array( 'state' => $new, 'updated_at' => current_time( 'mysql', true ) );
		$utc = static function ( $ts ) {
			return gmdate( 'Y-m-d H:i:s', (int) $ts );
		};
		if ( 'transferred' === $action ) {
			$set['transferred_at'] = $utc( $now );
			$set['due_at']         = $utc( db_seller_payout_due_ts( $now, 0 ) );
		} elseif ( 'confirmed' === $action ) {
			$tr                  = $p['transferred_at'] ? (int) strtotime( $p['transferred_at'] . ' UTC' ) : $now;
			$set['confirmed_at'] = $utc( $now );
			$set['due_at']       = $utc( db_seller_payout_due_ts( $tr, $now ) );
		} elseif ( 'pay' === $action ) {
			$set['paid_at']   = $utc( $now );
			$set['reference'] = substr( sanitize_text_field( (string) $reference ), 0, 120 );
		} elseif ( 'hold' === $action ) {
			$set['held_from'] = $p['state'];
			$set['note']      = substr( sanitize_text_field( (string) $reference ), 0, 255 );
		} elseif ( 'release' === $action ) {
			$set['held_from'] = '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'db_seller_payouts', $set, array( 'id' => (int) $p['id'] ) );
		$row   = db_seller_payout_get( (int) $p['id'] );
		$words = array( 'plan_done' => 'Plan paid off', 'transferred' => 'Marked transferred', 'confirmed' => 'Buyer confirmed control', 'pay' => 'Paid' . ( '' !== $reference ? ' (ref ' . $reference . ')' : '' ), 'hold' => 'Put on hold' . ( '' !== $reference ? ': ' . $reference : '' ), 'release' => 'Hold released' );
		db_seller_audit( (int) $p['seller_id'], $p['domain'], 'payout_' . $action, $words[ $action ] ?? $action, $actor );
		$l = function_exists( 'db_seller_listing_for_domain' ) ? db_seller_listing_for_domain( $p['domain'] ) : null;
		if ( ! $l ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$l = $wpdb->get_row( $wpdb->prepare( "SELECT s.email AS seller_email FROM " . db_seller_table( 'sellers' ) . " s WHERE s.id = %d", (int) $p['seller_id'] ), ARRAY_A );
		}
		if ( is_array( $l ) ) {
			$net = '$' . number_format( (float) $row['net'], 2 );
			if ( 'plan_done' === $action ) {
				db_seller_payout_mail( $l, 'Plan paid off: time to transfer ' . $p['domain'], 'The buyer has finished paying for ' . $p['domain'] . '. Our broker will email the transfer steps. Your payout of ' . $net . ' follows the transfer.' );
			} elseif ( 'confirmed' === $action || 'transferred' === $action ) {
				db_seller_payout_mail( $l, 'Payout scheduled for ' . $p['domain'], 'Your payout of ' . $net . ' for ' . $p['domain'] . ' is due on ' . wp_date( 'F j, Y', (int) strtotime( $row['due_at'] . ' UTC' ) ) . ( 'transferred' === $action ? ' at the latest (sooner if the buyer confirms control).' : '.' ) );
			} elseif ( 'pay' === $action ) {
				db_seller_payout_mail( $l, 'Payout sent: ' . $net . ' for ' . $p['domain'], 'We sent your payout of ' . $net . ' for ' . $p['domain'] . '.' . ( '' !== $reference ? ' Reference: ' . $reference . '.' : '' ) . ' Thank you for selling with Domain Brothers.' );
			} elseif ( 'hold' === $action ) {
				db_seller_payout_mail( $l, 'Payout on hold for ' . $p['domain'], 'Your payout for ' . $p['domain'] . ' is on hold while we look into something' . ( '' !== $reference ? ': ' . $reference : '' ) . '. Our broker will contact you.' );
			}
		}
		return $row;
	}
}

/* Hourly: transfers with no dispute after 14 days become due (already scheduled by due_at); nothing else moves on its own. */
add_action( 'db_seller_ops_cron', function () {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}db_seller_payouts WHERE state = 'waiting_confirm' AND due_at IS NOT NULL AND due_at <= %s LIMIT 100", current_time( 'mysql', true ) ), ARRAY_A );
	foreach ( $rows as $p ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $wpdb->prefix . 'db_seller_payouts', array( 'state' => 'due', 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $p['id'] ) );
		db_seller_audit( (int) $p['seller_id'], $p['domain'], 'payout_due', '14 days after transfer without a dispute', 'system' );
		if ( function_exists( 'db_plan_notify_admin' ) ) {
			db_plan_notify_admin( 'Seller payout due: ' . $p['domain'], array( 'Domain' => $p['domain'], 'Net' => '$' . number_format( (float) $p['net'], 2 ), 'Open' => admin_url( 'tools.php?page=db-seller-payouts' ) ) );
		}
	}
}, 30 );

if ( ! function_exists( 'db_seller_payouts_for' ) ) {
	function db_seller_payouts_for( $seller_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}db_seller_payouts WHERE seller_id = %d ORDER BY id DESC LIMIT 500", (int) $seller_id ), ARRAY_A );
	}
}

if ( ! function_exists( 'db_seller_payouts_html' ) ) {
	/** The seller's "Sales and payouts" section. */
	function db_seller_payouts_html( array $seller ) {
		$rows   = db_seller_payouts_for( (int) $seller['id'] );
		$states = db_seller_payout_states();
		$h      = '<section class="dbsl-card dbsl-sec" id="dbsl-payouts" aria-labelledby="dbsl-pay-h"><h2 id="dbsl-pay-h">Sales and payouts</h2>';
		if ( ! $rows ) {
			return $h . '<p>No sales yet. When one of your names sells, the sale, our commission and your payout appear here, with each step from transfer to payment. You are paid 7 days after the buyer confirms control of the name (14 days after the transfer at the latest).</p></section>';
		}
		$paid = 0.0;
		$due  = 0.0;
		foreach ( $rows as $r ) {
			if ( 'paid' === $r['state'] ) {
				$paid += (float) $r['net'];
			} elseif ( 'held' !== $r['state'] ) {
				$due += (float) $r['net'];
			}
		}
		$h .= '<div class="dbsl-paysum"><div><span>Paid to you</span><strong>$' . esc_html( number_format( $paid, 2 ) ) . '</strong></div><div><span>On the way</span><strong>$' . esc_html( number_format( $due, 2 ) ) . '</strong></div><div><span>Sales</span><strong>' . count( $rows ) . '</strong></div></div>'
			. '<div class="dbsl-scroll"><table class="dbsl-tbl"><thead><tr><th scope="col">Name</th><th scope="col">Sold</th><th scope="col">Gross</th><th scope="col">Commission</th><th scope="col">Your payout</th><th scope="col">Status</th><th scope="col">Due / paid</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$when = 'paid' === $r['state'] && $r['paid_at'] ? 'Paid ' . wp_date( 'M j, Y', strtotime( $r['paid_at'] . ' UTC' ) ) : ( $r['due_at'] ? 'Due ' . wp_date( 'M j, Y', strtotime( $r['due_at'] . ' UTC' ) ) : '&mdash;' );
			$h   .= '<tr><td data-l="Name"><strong>' . esc_html( $r['domain'] ) . '</strong>' . ( 'plan' === $r['kind'] ? ' <span class="dbsl-tag">Plan</span>' : '' ) . ( $r['exclusive'] ? ' <span class="dbsl-tag">Exclusive</span>' : '' ) . '</td>'
				. '<td data-l="Sold">' . esc_html( wp_date( 'M j, Y', strtotime( $r['created_at'] . ' UTC' ) ) ) . '</td>'
				. '<td data-l="Gross">$' . esc_html( number_format( (float) $r['gross'], 2 ) ) . '</td><td data-l="Commission">$' . esc_html( number_format( (float) $r['commission'], 2 ) ) . '</td>'
				. '<td data-l="Your payout"><strong>$' . esc_html( number_format( (float) $r['net'], 2 ) ) . '</strong></td>'
				. '<td data-l="Status"><span class="dbsl-pill dbsl-pill--' . ( 'paid' === $r['state'] ? 'ok' : ( 'held' === $r['state'] ? 'warn' : 'info' ) ) . '">' . esc_html( $states[ $r['state'] ] ?? $r['state'] ) . '</span></td>'
				. '<td data-l="Due / paid">' . ( '&mdash;' === $when ? $when : esc_html( $when ) ) . ( '' !== $r['reference'] ? '<br><small>Ref ' . esc_html( $r['reference'] ) . '</small>' : '' ) . '</td></tr>';
		}
		$h .= '</tbody></table></div><p class="dbsl-actions"><a class="dbsl-btn dbsl-btn--ghost" href="' . esc_url( add_query_arg( 'statement', 1, home_url( '/seller/' ) ) ) . '">Download statement (CSV)</a></p>'
			. '<p class="dbsl-note">Payouts are sent by bank transfer to the details you give our broker. You are paid 7 days after the buyer confirms control of the name, or 14 days after the transfer if they do not reply.</p></section>';
		return $h;
	}
}

/* CSV statement download for the signed-in seller. */
add_action( 'template_redirect', function () {
	if ( empty( $_GET['statement'] ) || ! function_exists( 'db_sales_request_path' ) || 'seller' !== db_sales_request_path() ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$email  = function_exists( 'db_wl_email' ) ? db_wl_email() : '';
	$seller = '' !== $email && function_exists( 'db_seller_get' ) ? db_seller_get( $email ) : null;
	if ( ! $seller ) {
		return;
	}
	$list = array();
	foreach ( db_seller_payouts_for( (int) $seller['id'] ) as $r ) {
		$list[] = array(
			'domain'     => $r['domain'],
			'created'    => strtotime( $r['created_at'] . ' UTC' ),
			'gross'      => $r['gross'],
			'commission' => $r['commission'],
			'net'        => $r['net'],
			'state'      => $r['state'],
			'due_ts'     => $r['due_at'] ? strtotime( $r['due_at'] . ' UTC' ) : 0,
			'paid_ts'    => $r['paid_at'] ? strtotime( $r['paid_at'] . ' UTC' ) : 0,
			'reference'  => $r['reference'],
		);
	}
	status_header( 200 );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="domain-brothers-statement-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	foreach ( db_seller_statement_rows( $list ) as $row ) {
		fputcsv( $out, $row );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
}, 9 );

/* Admin: Tools > Seller payouts. */
add_action( 'admin_menu', function () {
	add_management_page( 'Seller payouts', 'Seller payouts', 'manage_options', 'db-seller-payouts', 'db_seller_payouts_admin' );
} );

add_action( 'admin_post_db_seller_payout', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_seller_payout' ) ) {
		wp_die( 'Not allowed.' );
	}
	$back = admin_url( 'tools.php?page=db-seller-payouts' );
	$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	$ref  = isset( $_POST['ref'] ) ? sanitize_text_field( wp_unslash( $_POST['ref'] ) ) : '';
	if ( 'record' === $do ) {
		$domain = isset( $_POST['domain'] ) ? strtolower( sanitize_text_field( wp_unslash( $_POST['domain'] ) ) ) : '';
		$gross  = isset( $_POST['gross'] ) ? (float) preg_replace( '/[^0-9.]/', '', wp_unslash( $_POST['gross'] ) ) : 0;
		$id     = $gross > 0 ? db_seller_payout_create( $domain, $gross, 'manual' ) : 0;
		wp_safe_redirect( add_query_arg( 'msg', rawurlencode( $id ? 'Sale recorded for ' . $domain . '.' : 'Not recorded: the domain must be a live seller listing and the amount above zero.' ), $back ) );
		exit;
	}
	$p   = db_seller_payout_get( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0 );
	$res = $p ? db_seller_payout_apply( $p, $do, $ref ) : 'Payout not found.';
	wp_safe_redirect( add_query_arg( 'msg', rawurlencode( is_array( $res ) ? 'Done: ' . $p['domain'] . ' is now "' . db_seller_payout_states()[ $res['state'] ] . '".' : $res ), $back ) );
	exit;
} );

if ( ! function_exists( 'db_seller_payouts_admin' ) ) {
	function db_seller_payouts_admin() {
		global $wpdb;
		$states = db_seller_payout_states();
		$rows   = (array) $wpdb->get_results( "SELECT p.*, s.email FROM {$wpdb->prefix}db_seller_payouts p LEFT JOIN " . db_seller_table( 'sellers' ) . " s ON s.id = p.seller_id ORDER BY FIELD(p.state,'due','waiting_confirm','waiting_transfer','held','collecting','paid'), p.id DESC LIMIT 300", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$audit  = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}db_seller_audit ORDER BY id DESC LIMIT 100", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$form   = static function ( $id, $do, $label, $field = '' ) {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin:2px">' . wp_nonce_field( 'db_seller_payout', '_wpnonce', true, false )
				. '<input type="hidden" name="action" value="db_seller_payout"><input type="hidden" name="id" value="' . (int) $id . '"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">'
				. ( $field ? '<input type="text" name="ref" placeholder="' . esc_attr( $field ) . '" style="width:150px"> ' : '' )
				. '<button class="button' . ( 'pay' === $do ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
		};
		echo '<div class="wrap"><h1>Seller payouts</h1>';
		if ( ! empty( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<p>Each sale of a seller\'s name. Send the money by bank transfer (or Stripe Connect when enabled), then mark it paid with the reference. Payouts are due 7 days after the buyer confirms control, or 14 days after the transfer.</p>';
		echo '<table class="widefat striped"><thead><tr><th>Domain</th><th>Seller</th><th>Kind</th><th>Gross</th><th>Commission</th><th>Net</th><th>State</th><th>Due</th><th>Actions</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="9">No seller sales yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$acts = '';
			switch ( $r['state'] ) {
				case 'waiting_transfer':
					$acts .= $form( $r['id'], 'transferred', 'Mark transferred' );
					break;
				case 'waiting_confirm':
					$acts .= $form( $r['id'], 'confirmed', 'Buyer confirmed control' );
					break;
				case 'due':
					$acts .= $form( $r['id'], 'pay', 'Mark paid', 'Payment reference' );
					break;
				case 'held':
					$acts .= $form( $r['id'], 'release', 'Release hold' );
					break;
			}
			if ( in_array( $r['state'], array( 'collecting', 'waiting_transfer', 'waiting_confirm', 'due' ), true ) ) {
				$acts .= $form( $r['id'], 'hold', 'Hold', 'Reason' );
			}
			echo '<tr><td><strong>' . esc_html( $r['domain'] ) . '</strong></td><td>' . esc_html( (string) $r['email'] ) . '</td><td>' . esc_html( $r['kind'] ) . ( $r['exclusive'] ? ' (exclusive)' : '' ) . '</td><td>$' . esc_html( number_format( (float) $r['gross'], 2 ) ) . '</td><td>$' . esc_html( number_format( (float) $r['commission'], 2 ) ) . '</td><td><strong>$' . esc_html( number_format( (float) $r['net'], 2 ) ) . '</strong></td><td>' . esc_html( $states[ $r['state'] ] ?? $r['state'] ) . ( $r['reference'] ? '<br><small>Ref ' . esc_html( $r['reference'] ) . '</small>' : '' ) . ( 'held' === $r['state'] && $r['note'] ? '<br><small>' . esc_html( $r['note'] ) . '</small>' : '' ) . '</td><td>' . ( $r['due_at'] ? esc_html( wp_date( 'M j, Y', strtotime( $r['due_at'] . ' UTC' ) ) ) : '&mdash;' ) . '</td><td>' . $acts . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- forms built from escaped parts.
		}
		echo '</tbody></table>';
		echo '<h2>Record a sale made off the site</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'db_seller_payout' );
		echo '<input type="hidden" name="action" value="db_seller_payout"><input type="hidden" name="do" value="record"><input type="text" name="domain" placeholder="seller domain, e.g. example.ca" required> <input type="text" name="gross" placeholder="sale price USD" required> <button class="button">Record sale</button></form>';
		echo '<h2>Audit log (latest 100)</h2><table class="widefat striped"><thead><tr><th>When (UTC)</th><th>Domain</th><th>Who</th><th>What</th><th>Detail</th></tr></thead><tbody>';
		foreach ( $audit as $a ) {
			echo '<tr><td>' . esc_html( $a['created_at'] ) . '</td><td>' . esc_html( $a['domain'] ) . '</td><td>' . esc_html( $a['actor'] ) . '</td><td>' . esc_html( $a['action'] ) . '</td><td>' . esc_html( $a['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

if ( ! function_exists( 'db_seller_payouts_css' ) ) {
	function db_seller_payouts_css() {
		return '.dbsl-paysum{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:12px 0 16px}@media(max-width:640px){.dbsl-paysum{grid-template-columns:1fr 1fr}}'
			. '.dbsl-paysum div{display:grid;gap:2px;padding:14px 16px;border-radius:16px;background:#f6f7f9}.dbsl-paysum div:first-child{background:#0a1628}.dbsl-paysum span{font-size:12.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#5b6472}.dbsl-paysum div:first-child span{color:#b5e61d}'
			. '.dbsl-paysum strong{font-size:22px;font-weight:900;color:#0a1628}.dbsl-paysum div:first-child strong{color:#fff}.dbsl-tag{display:inline-block;margin-left:4px;padding:1px 8px;border-radius:999px;background:#eef1f4;color:#374151;font-size:12px;font-weight:700}';
	}
}
