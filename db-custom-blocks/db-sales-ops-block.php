<?php
/**
 * Sales operations: the emails and records behind the sales features.
 *
 *  - Email templates for broker requests, transfer stages and messages, offer
 *    response reminders, and checkout reminders (merged into the registry).
 *  - Broker requests: team alert and buyer acknowledgement.
 *  - Transfer stage changes: buyer email.
 *  - Transfer messages: buyer and team thread (table db_transfer_msgs). Buyers
 *    write only for domains recorded as bought by their signed-in email.
 *  - Offer response timer: alert the team when an open offer has had no reply
 *    for 24 hours (once per offer).
 *  - Checkout reminders: optional consent on step 1, one reminder about two
 *    hours after an unfinished checkout, skipped if the buyer paid, cancelled
 *    or opted out, with a signed one-click stop link.
 *  - Experiment endpoint: records CTA variant views and clicks.
 *
 * @package DomainBrothers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_OPS_TABLE_VERSION' ) ) {
	define( 'DB_OPS_TABLE_VERSION', '1' );
}
if ( ! defined( 'DB_OPS_REMINDER_DELAY' ) ) {
	define( 'DB_OPS_REMINDER_DELAY', 2 * HOUR_IN_SECONDS );
}
if ( ! defined( 'DB_OPS_OFFER_SLA' ) ) {
	define( 'DB_OPS_OFFER_SLA', DAY_IN_SECONDS );
}

/* ----------------------------------------------------------- schema */

add_action( 'init', function () {
	if ( get_option( 'db_ops_table_version', '' ) === DB_OPS_TABLE_VERSION ) {
		return;
	}
	global $wpdb;
	$cs = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$wpdb->prefix}db_transfer_msgs (
		id         BIGINT       NOT NULL AUTO_INCREMENT,
		post_id    BIGINT       NOT NULL,
		author     VARCHAR(8)   NOT NULL,
		email      VARCHAR(190) NOT NULL DEFAULT '',
		body       TEXT         NOT NULL,
		created_at DATETIME     NOT NULL,
		PRIMARY KEY (id),
		KEY idx_post (post_id)
	) {$cs};" );
	dbDelta( "CREATE TABLE {$wpdb->prefix}db_checkout_reminders (
		id         BIGINT       NOT NULL AUTO_INCREMENT,
		pi_id      VARCHAR(64)  NOT NULL,
		email      VARCHAR(190) NOT NULL,
		status     VARCHAR(12)  NOT NULL DEFAULT 'pending',
		created_at DATETIME     NOT NULL,
		sent_at    DATETIME     NULL,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_pi (pi_id),
		KEY idx_status (status, created_at)
	) {$cs};" );
	update_option( 'db_ops_table_version', DB_OPS_TABLE_VERSION, false );
} );

/* --------------------------------------------------- email templates */

if ( ! function_exists( 'db_sales_mail_registry' ) ) {
	/** Templates for the sales features. Merged into db_mail_registry(). */
	function db_sales_mail_registry() {
		return array(
			'sales_broker_team'     => array(
				'group' => 'Team notices', 'label' => 'Broker call request (to the team)', 'to' => 'team', 'critical' => false,
				'when'  => 'A visitor asks for a broker to call.',
				'vars'  => array(
					'domain' => array( 'Domain, or "Domain finder request"', 'brandable.com' ),
					'name' => array( 'Their name', 'Alex Buyer' ), 'email' => array( 'Their email', 'buyer@example.com' ),
					'phone' => array( 'Their phone', '555-0100' ), 'note_html' => array( 'Their note', '<p>Looking for a clinic name.</p>' ),
					'criteria' => array( 'Finder answers', 'Business · Canada · Short' ),
				),
				'subject' => 'Broker call request: {{domain}}', 'eyebrow' => 'Broker request', 'heading' => 'New broker call request',
				'body'    => '{{note_html}}<p style="font-size:14px">Finder answers: {{criteria}}</p>',
				'details' => "Domain or request|{{domain}}\nName|{{name}}\nEmail|{{email}}\nPhone|{{phone}}", 'cta' => '', 'cta_var' => '', 'tone' => 'neutral',
			),
			'sales_broker_ack'      => array(
				'group' => 'Account', 'label' => 'Broker call request received (to the buyer)', 'to' => 'customer', 'critical' => false,
				'when'  => 'Straight after a visitor asks for a broker call.',
				'vars'  => array( 'name' => array( 'Their name', 'Alex' ), 'domain' => array( 'Domain or request', 'brandable.com' ) ),
				'subject' => 'We received your call request', 'eyebrow' => 'Broker request', 'heading' => 'Thanks, {{name}}',
				'body'    => '<p>A broker will reply to you by email, or by phone if you gave a number. There is nothing else to do.</p><p style="font-size:14px">Request: {{domain}}</p>',
				'details' => '', 'cta' => '', 'cta_var' => '', 'tone' => 'good',
			),
			'transfer_stage'        => array(
				'group' => 'Payments', 'label' => 'Transfer stage changed (to the buyer)', 'to' => 'customer', 'critical' => false,
				'when'  => 'Our team moves a purchased domain to its next transfer stage.',
				'vars'  => array(
					'domain' => array( 'The domain', 'brandable.com' ), 'stage' => array( 'The new stage', 'Transfer code sent' ),
					'note_html' => array( 'Note from our team', '<p>Check your registrar inbox for the code.</p>' ),
					'account_url' => array( 'Link to your transfers', home_url( '/my-wishlist/?tab=transfers' ) ),
				),
				'subject' => 'Update on {{domain}}: {{stage}}', 'eyebrow' => 'Transfer', 'heading' => '{{stage}}',
				'body'    => '{{note_html}}<p style="font-size:14px">You can follow every step in your account.</p>',
				'details' => "Domain|{{domain}}\nStage|{{stage}}", 'cta' => 'See my transfers', 'cta_var' => 'account_url', 'tone' => 'neutral',
			),
			'transfer_message_team' => array(
				'group' => 'Team notices', 'label' => 'Buyer message about a transfer (to the team)', 'to' => 'team', 'critical' => false,
				'when'  => 'A buyer writes in their Transfers tab.',
				'vars'  => array(
					'domain' => array( 'The domain', 'brandable.com' ), 'buyer' => array( 'Buyer email', 'buyer@example.com' ),
					'message_html' => array( 'Their message', '<p>When will the code arrive?</p>' ),
					'edit_url' => array( 'Link to the domain', admin_url( 'post.php' ) ),
				),
				'subject' => 'New message about {{domain}}', 'eyebrow' => 'Transfers', 'heading' => 'New message from a buyer',
				'body'    => '{{message_html}}', 'details' => "Domain|{{domain}}\nBuyer|{{buyer}}", 'cta' => 'Open the domain', 'cta_var' => 'edit_url', 'tone' => 'neutral',
			),
			'transfer_message_buyer' => array(
				'group' => 'Payments', 'label' => 'Team message about a transfer (to the buyer)', 'to' => 'customer', 'critical' => false,
				'when'  => 'Our team replies in a buyer\'s transfer thread.',
				'vars'  => array(
					'domain' => array( 'The domain', 'brandable.com' ), 'message_html' => array( 'Our reply', '<p>The code is on its way.</p>' ),
					'account_url' => array( 'Link to your transfers', home_url( '/my-wishlist/?tab=transfers' ) ),
				),
				'subject' => 'New message from Domain Brothers about {{domain}}', 'eyebrow' => 'Transfers', 'heading' => 'Message from our team',
				'body'    => '{{message_html}}', 'details' => "Domain|{{domain}}", 'cta' => 'Open my transfers', 'cta_var' => 'account_url', 'tone' => 'neutral',
			),
			'offer_sla_team'        => array(
				'group' => 'Team notices', 'label' => 'Offer waiting for a reply (to the team)', 'to' => 'team', 'critical' => false,
				'when'  => 'An offer has had no reply for 24 hours.',
				'vars'  => array(
					'domain' => array( 'The domain', 'brandable.com' ), 'hours' => array( 'Hours waiting', '26' ),
					'buyer' => array( 'Buyer email', 'buyer@example.com' ), 'price' => array( 'Offer amount', '$1,500' ),
					'admin_url' => array( 'Link to the offer', admin_url( 'admin.php?page=db-offers' ) ),
				),
				'subject' => 'No reply yet: offer on {{domain}}, waiting {{hours}} hours', 'eyebrow' => 'Offers', 'heading' => 'An offer is waiting for a reply',
				'body'    => '<p>This offer has had no response for {{hours}} hours. Please reply or decline it.</p>',
				'details' => "Domain|{{domain}}\nBuyer|{{buyer}}\nOffer|{{price}}", 'cta' => 'Open the offer', 'cta_var' => 'admin_url', 'tone' => 'warn',
			),
			'checkout_reminder'     => array(
				'group' => 'Payments', 'label' => 'Unfinished checkout reminder (to the buyer)', 'to' => 'customer', 'critical' => false,
				'when'  => 'Two hours after a buyer started a purchase, did not finish, and asked for one reminder.',
				'vars'  => array(
					'domain' => array( 'The domain', 'brandable.com' ), 'amount' => array( 'Amount', '$2,500.00' ),
					'checkout_url' => array( 'Link to continue', home_url( '/buy-now/' ) ),
					'stop_url' => array( 'Do-not-remind link', home_url( '/' ) ),
				),
				'subject' => 'Still interested in {{domain}}?', 'eyebrow' => 'Your checkout', 'heading' => 'You started buying {{domain}}',
				'body'    => '<p>You started a purchase of <strong>{{domain}}</strong> for {{amount}} but did not finish. You can pick up where you left off.</p><p style="font-size:13px;color:#6b7280">This is the only reminder we will send about this checkout. <a href="{{stop_url}}">Do not send reminders about this</a>.</p>',
				'details' => "Domain|{{domain}}\nAmount|{{amount}}", 'cta' => 'Finish my purchase', 'cta_var' => 'checkout_url', 'tone' => 'neutral',
			),
		);
	}
}

if ( ! function_exists( 'db_ops_send' ) ) {
	/** Sends a registry email. Logs failures instead of breaking the caller. */
	function db_ops_send( $key, $to, array $vars, $reply_to = '' ) {
		if ( ! is_email( $to ) ) {
			return false;
		}
		$r = db_mail_send( $key, $to, $vars, $reply_to );
		if ( null === $r ) {
			error_log( 'DB sales: mail template missing: ' . $key ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return (bool) $r;
	}
}

/* ------------------------------------------------------ brokers */

if ( ! function_exists( 'db_ops_broker_notify' ) ) {
	function db_ops_broker_notify( $email, $name, $phone, $domain, $note, $criteria ) {
		db_ops_send( 'sales_broker_team', db_mail_team_to(), array(
			'domain' => $domain, 'name' => $name, 'email' => $email, 'phone' => $phone,
			'note_html' => '<p>' . nl2br( esc_html( $note ) ) . '</p>', 'criteria' => $criteria,
		), $email );
		db_ops_send( 'sales_broker_ack', $email, array( 'name' => $name ? $name : 'there', 'domain' => $domain ) );
	}
}

/* ------------------------------------------------ transfer stages */

if ( ! function_exists( 'db_ops_stage_changed' ) ) {
	/** Emails the buyer when a purchased domain moves to a new stage. */
	function db_ops_stage_changed( $post_id, $old, $new, $note ) {
		if ( '' === $new || $new === $old || ! function_exists( 'db_transfer_stages' ) ) {
			return;
		}
		$buyer = (string) get_post_meta( $post_id, 'db_sold_buyer', true );
		if ( ! is_email( $buyer ) ) {
			return;
		}
		$stages = db_transfer_stages();
		db_ops_send( 'transfer_stage', $buyer, array(
			'domain' => get_the_title( $post_id ),
			'stage' => $stages[ $new ] ?? $new,
			'note_html' => '' !== trim( (string) $note ) ? '<p>' . nl2br( esc_html( $note ) ) . '</p>' : '<p>We will email you when the next step is done.</p>',
			'account_url' => home_url( '/my-wishlist/?tab=transfers' ),
		) );
	}
}

/* ----------------------------------------------- transfer messages */

if ( ! function_exists( 'db_ops_messages' ) ) {
	function db_ops_messages( $post_id ) {
		global $wpdb;
		$t = $wpdb->prefix . 'db_transfer_msgs';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE post_id = %d ORDER BY id ASC LIMIT 200", (int) $post_id ) );
	}
}

if ( ! function_exists( 'db_ops_add_message' ) ) {
	function db_ops_add_message( $post_id, $author, $email, $body ) {
		global $wpdb;
		$body = trim( sanitize_textarea_field( (string) $body ) );
		if ( '' === $body || mb_strlen( $body ) > 2000 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->insert( $wpdb->prefix . 'db_transfer_msgs', array(
			'post_id' => (int) $post_id, 'author' => 'team' === $author ? 'team' : 'buyer', 'email' => (string) $email,
			'body' => $body, 'created_at' => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%s', '%s' ) );
	}
}

if ( ! function_exists( 'db_ops_thread_html' ) ) {
	/** The thread for a buyer's account, with the form to write. */
	function db_ops_thread_html( $post_id, $email ) {
		$h = '<div class="db-sales-thread"><h3>Messages about this transfer</h3>';
		$msgs = db_ops_messages( $post_id );
		if ( ! $msgs ) {
			$h .= '<p>No messages yet.</p>';
		}
		foreach ( $msgs as $m ) {
			$who = 'team' === $m->author ? 'Domain Brothers' : 'You';
			$h  .= '<div class="db-sales-msg db-sales-msg--' . esc_attr( $m->author ) . '"><p class="db-sales-msg-meta">' . esc_html( $who ) . ' · ' . esc_html( wp_date( 'M j, Y g:i a', strtotime( $m->created_at . ' UTC' ) ) ) . '</p><p>' . nl2br( esc_html( $m->body ) ) . '</p></div>';
		}
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="db-sales-form">' . db_sales_nonce( 'db_ops_msg' );
		$h .= '<input type="hidden" name="action" value="db_ops_transfer_msg"><input type="hidden" name="post_id" value="' . (int) $post_id . '">';
		$h .= '<label>Write to our team<textarea name="body" rows="3" maxlength="2000" required></textarea></label>';
		$h .= '<button type="submit" class="db-sales-btn">Send message</button></form></div>';
		return $h;
	}
}

add_action( 'admin_post_nopriv_db_ops_transfer_msg', 'db_ops_handle_buyer_message' );
add_action( 'admin_post_db_ops_transfer_msg', 'db_ops_handle_buyer_message' );
if ( ! function_exists( 'db_ops_handle_buyer_message' ) ) {
	function db_ops_handle_buyer_message() {
		check_admin_referer( 'db_ops_msg', '_db_nonce' );
		$email = function_exists( 'db_wl_email' ) ? db_wl_email() : '';
		$back  = home_url( '/my-wishlist/?tab=transfers' );
		if ( '' === $email ) {
			wp_safe_redirect( add_query_arg( 'msg', 'signin', $back ) );
			exit;
		}
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$owned   = $post_id && 'domain' === get_post_type( $post_id ) && strtolower( (string) get_post_meta( $post_id, 'db_sold_buyer', true ) ) === strtolower( $email );
		if ( ! $owned ) {
			wp_safe_redirect( add_query_arg( 'msg', 'denied', $back ) );
			exit;
		}
		$key   = 'db_ops_msg_' . md5( strtolower( $email ) );
		$count = (int) get_transient( $key );
		if ( $count >= 10 ) {
			wp_safe_redirect( add_query_arg( 'msg', 'busy', $back ) );
			exit;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		$body = isset( $_POST['body'] ) ? wp_unslash( $_POST['body'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! db_ops_add_message( $post_id, 'buyer', $email, $body ) ) {
			wp_safe_redirect( add_query_arg( 'msg', 'empty', $back ) );
			exit;
		}
		$clean = trim( sanitize_textarea_field( (string) $body ) );
		db_ops_send( 'transfer_message_team', db_mail_team_to(), array(
			'domain' => get_the_title( $post_id ), 'buyer' => $email,
			'message_html' => '<p>' . nl2br( esc_html( $clean ) ) . '</p>', 'edit_url' => admin_url( 'post.php?post=' . $post_id . '&action=edit' ),
		), $email );
		wp_safe_redirect( add_query_arg( 'msg', 'sent', $back ) );
		exit;
	}
}

if ( ! function_exists( 'db_ops_team_reply' ) ) {
	/** Called from the domain's save: a team reply in the metabox box is recorded and emailed to the buyer. */
	function db_ops_team_reply( $post_id ) {
		if ( ! isset( $_POST['db_ops_reply_body'] ) || ! current_user_can( 'edit_post', $post_id ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$body  = wp_unslash( $_POST['db_ops_reply_body'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$buyer = (string) get_post_meta( $post_id, 'db_sold_buyer', true );
		if ( '' === trim( (string) $body ) || ! is_email( $buyer ) ) {
			return;
		}
		if ( ! db_ops_add_message( $post_id, 'team', $buyer, $body ) ) {
			return;
		}
		$clean = trim( sanitize_textarea_field( (string) $body ) );
		db_ops_send( 'transfer_message_buyer', $buyer, array(
			'domain' => get_the_title( $post_id ), 'message_html' => '<p>' . nl2br( esc_html( $clean ) ) . '</p>',
			'account_url' => home_url( '/my-wishlist/?tab=transfers' ),
		) );
	}
}

if ( ! function_exists( 'db_ops_team_thread_html' ) ) {
	/** Metabox: thread plus the reply box (sent with the normal Update button). */
	function db_ops_team_thread_html( $post ) {
		$buyer = (string) get_post_meta( $post->ID, 'db_sold_buyer', true );
		if ( ! is_email( $buyer ) ) {
			echo '<p class="description">Set the buyer email on the sold record to enable the transfer thread.</p>';
			return;
		}
		echo '<hr><p><strong>Transfer thread with ' . esc_html( $buyer ) . '</strong></p>';
		foreach ( db_ops_messages( $post->ID ) as $m ) {
			echo '<p><em>' . esc_html( 'team' === $m->author ? 'Team' : 'Buyer' ) . ', ' . esc_html( $m->created_at ) . ':</em><br>' . nl2br( esc_html( $m->body ) ) . '</p>';
		}
		echo '<label for="db_ops_reply_body"><strong>Reply to the buyer</strong> (emailed when you press Update)</label>';
		echo '<textarea id="db_ops_reply_body" name="db_ops_reply_body" rows="3" style="width:100%" maxlength="2000"></textarea>';
	}
}

/* ------------------------------------------------- offer response timer */

if ( ! function_exists( 'db_ops_offer_sla_check' ) ) {
	function db_ops_offer_sla_check() {
		global $wpdb;
		$t    = db_deal_table();
		$cut  = gmdate( 'Y-m-d H:i:s', time() - DB_OPS_OFFER_SLA );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, domain, buyer_email, amount, updated_at FROM {$t} WHERE state = 'open' AND updated_at < %s LIMIT 50", $cut ) );
		$sent = (array) get_option( 'db_ops_sla_sent', array() );
		foreach ( $rows as $d ) {
			if ( in_array( (int) $d->id, $sent, true ) ) {
				continue;
			}
			$hours = (int) floor( ( time() - strtotime( $d->updated_at . ' UTC' ) ) / HOUR_IN_SECONDS );
			db_ops_send( 'offer_sla_team', db_mail_team_to(), array(
				'domain' => $d->domain, 'hours' => (string) $hours, 'buyer' => $d->buyer_email,
				'price' => '$' . number_format( (float) $d->amount, 2 ), 'admin_url' => admin_url( 'admin.php?page=db-offers' ),
			) );
			$sent[] = (int) $d->id;
		}
		update_option( 'db_ops_sla_sent', array_slice( array_values( array_unique( $sent ) ), -1000 ), false );
	}
}

/* ---------------------------------------------- checkout reminders */

if ( ! function_exists( 'db_ops_reminder_stop_sig' ) ) {
	function db_ops_reminder_stop_sig( $pi_id, $email ) {
		return hash_hmac( 'sha256', 'rem|' . $pi_id . '|' . strtolower( $email ), wp_salt( 'auth' ) );
	}
}

if ( ! function_exists( 'db_ops_reminder_opt_in' ) ) {
	/** Called from the checkout when the buyer's email is saved: records consent for one reminder. */
	function db_ops_reminder_opt_in( $pi_id, $email ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$wpdb->prefix}db_checkout_reminders (pi_id, email, status, created_at) VALUES (%s, %s, 'pending', %s) ON DUPLICATE KEY UPDATE email = VALUES(email)",
			$pi_id, $email, current_time( 'mysql' )
		) );
	}
}

if ( ! function_exists( 'db_ops_reminders_run' ) ) {
	function db_ops_reminders_run() {
		global $wpdb;
		$t    = $wpdb->prefix . 'db_checkout_reminders';
		$cut  = gmdate( 'Y-m-d H:i:s', time() - DB_OPS_REMINDER_DELAY );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status = 'pending' AND created_at < %s ORDER BY id ASC LIMIT 20", $cut ) );
		$keys = function_exists( 'db_stripe_keys' ) ? db_stripe_keys() : array();
		foreach ( $rows as $r ) {
			$status = 'skipped';
			if ( ! empty( $keys['sec'] ) ) {
				$res = wp_remote_get( 'https://api.stripe.com/v1/payment_intents/' . rawurlencode( $r->pi_id ), array(
					'headers' => array( 'Authorization' => 'Bearer ' . $keys['sec'] ), 'timeout' => 15,
				) );
				$pi  = is_wp_error( $res ) ? null : json_decode( wp_remote_retrieve_body( $res ), true );
				if ( ! is_array( $pi ) || empty( $pi['status'] ) ) {
					continue; // Try again next run.
				}
				$pi_status = (string) $pi['status'];
				$domain    = (string) ( $pi['metadata']['domain'] ?? '' );
				$amount    = (float) ( $pi['amount'] ?? 0 ) / 100;
				if ( 'succeeded' === $pi_status ) {
					$status = 'paid';
				} elseif ( in_array( $pi_status, array( 'canceled', 'processing' ), true ) ) {
					$status = 'skipped';
				} elseif ( '' !== $domain && function_exists( 'db_listing_post_for_domain' ) ) {
					$post = db_listing_post_for_domain( $domain );
					if ( $post && db_listing_is_sellable( $post ) ) {
						$checkout = home_url( '/buy-now/?d=' . rawurlencode( $domain ) );
						$stop     = add_query_arg( array( 'db_ops_stop' => rawurlencode( $r->pi_id ), 'e' => rawurlencode( $r->email ), 'k' => db_ops_reminder_stop_sig( $r->pi_id, $r->email ) ), home_url( '/' ) );
						$sent     = db_ops_send( 'checkout_reminder', $r->email, array(
							'domain' => $domain, 'amount' => '$' . number_format( $amount, 2 ), 'checkout_url' => $checkout, 'stop_url' => $stop,
						) );
						$status   = $sent ? 'sent' : 'failed';
					}
				}
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->update( $t, array( 'status' => $status, 'sent_at' => current_time( 'mysql' ) ), array( 'id' => (int) $r->id ), array( '%s', '%s' ), array( '%d' ) );
		}
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! isset( $_GET['db_ops_stop'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$pi    = sanitize_text_field( wp_unslash( $_GET['db_ops_stop'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$email = sanitize_email( wp_unslash( $_GET['e'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$k     = sanitize_text_field( wp_unslash( $_GET['k'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$ok    = is_email( $email ) && hash_equals( db_ops_reminder_stop_sig( $pi, $email ), $k );
	if ( $ok ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}db_checkout_reminders SET status = 'stopped' WHERE pi_id = %s AND email = %s AND status = 'pending'", $pi, $email ) );
	}
	if ( function_exists( 'db_sales_render' ) ) {
		db_sales_render( $ok ? 'Reminders stopped' : 'That link did not work', 'Reminders',
			$ok ? '<p class="db-sales-lead">We will not send a reminder about that checkout.</p>' : '<p class="db-sales-lead">The link is not valid. If you still get reminders, reply to our last email and we will stop them.</p>', true );
	}
}, 5 );

/* ------------------------------------------------- experiment events */

add_action( 'rest_api_init', static function () {
	register_rest_route( 'db/v1', '/funnel', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => function ( WP_REST_Request $r ) {
			$ev = sanitize_text_field( (string) $r->get_param( 'ev' ) ); // Exact names only: sanitize_key would lowercase them.
			if ( ! in_array( $ev, array( 'exp_view_A', 'exp_view_B', 'exp_click_A', 'exp_click_B' ), true ) ) {
				return new WP_REST_Response( array( 'error' => 'unknown event' ), 400 );
			}
			db_funnel_track( $ev, 0 );
			return new WP_REST_Response( array( 'ok' => true ), 200 );
		},
		'permission_callback' => '__return_true',
	) );
} );

if ( ! function_exists( 'db_exp_variant' ) ) {
	/** Buy-button copy for the CTA experiment. The variant is kept in a cookie for 30 days. */
	function db_exp_variant() {
		if ( isset( $_COOKIE['db_exp_cta'] ) && in_array( $_COOKIE['db_exp_cta'], array( 'A', 'B' ), true ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return (string) $_COOKIE['db_exp_cta']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return 'A';
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_singular( 'domain' ) ) {
		return;
	}
	if ( ! isset( $_COOKIE['db_exp_cta'] ) ) {
		$v = ( 0 === random_int( 0, 1 ) ) ? 'A' : 'B';
		setcookie( 'db_exp_cta', $v, array( 'expires' => time() + 30 * DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
		$_COOKIE['db_exp_cta'] = $v;
	}
	db_funnel_track( 'exp_view_' . db_exp_variant(), 0 );
}, 5 );

if ( ! function_exists( 'db_exp_cta_label' ) ) {
	function db_exp_cta_label() {
		return 'B' === db_exp_variant() ? 'Buy this domain' : 'Buy now';
	}
}

add_action( 'db_sales_cron', 'db_ops_offer_sla_check' );
add_action( 'db_sales_cron', 'db_ops_reminders_run' );

add_action( 'wp_footer', function () {
	if ( is_admin() || ! is_singular( 'domain' ) ) {
		return;
	}
	echo '<script>document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[data-exp]");if(!a)return;var v=a.getAttribute("data-exp");if(v!=="A"&&v!=="B")return;try{fetch(' . wp_json_encode( rest_url( 'db/v1/funnel' ) ) . ',{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({ev:"exp_click_"+v}),keepalive:true});}catch(err){}});</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
} );
