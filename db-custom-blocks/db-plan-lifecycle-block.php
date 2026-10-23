<?php
/**
 * DB Plan Lifecycle Block — monthly reminders, failed-payment recovery, default
 *
 * A payment plan is a lease-to-own: the customer pays in installments, the
 * domain stays ours (held in escrow) and is transferred ONLY after the final
 * installment clears. Industry practice (GoDaddy Lease to Own, Atom, Dan):
 * a declined installment starts a short grace period with repeated warnings;
 * if it is not cured the plan is terminated, the domain goes back to the
 * seller and payments already made are non-refundable. This block implements
 * that, with every customer-facing consequence stated in the emails (and, in
 * db_plan_terms_html(), at the plan chooser and checkout so it is agreed to
 * before the first payment).
 *
 * State, stored in the existing per-domain option db_stripe_plan_<md5>:
 *   status           active | past_due | defaulted | completed
 *   next_installment int     installment to bill next
 *   next_charge_ts   int     when it falls due
 *   reminded_for     int     last installment a "payment coming" reminder went out for
 *   past_due_since   int     ts of the first failure of the current missed payment
 *   notice_stage     int     0..4, dunning notices already sent for this miss
 *   last_attempt_ts  int     last time we asked Stripe to charge (de-bounces cron)
 *
 * Timeline for one missed payment (day 0 = first decline). Tunable via the
 * constants below:
 *   day 0  decline  -> "payment failed" notice          (sent by the webhook)
 *   day 3  retry    -> second notice
 *   day 6  retry    -> urgent notice
 *   day 9  retry    -> final notice (24h left)
 *   day 10          -> plan terminated, forfeiture notice, team alerted
 *
 * Not legal advice: have counsel confirm the forfeiture wording and that it
 * is incorporated in the Terms of Use / checkout agreement for the jurisdiction(s)
 * you sell into.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_PLAN_GRACE_DAYS' ) )    define( 'DB_PLAN_GRACE_DAYS', 10 );
if ( ! defined( 'DB_PLAN_REMIND_DAYS' ) )   define( 'DB_PLAN_REMIND_DAYS', 3 );
if ( ! defined( 'DB_PLAN_SUPPORT_EMAIL' ) ) define( 'DB_PLAN_SUPPORT_EMAIL', 'sales@domainbrothers.com' );

/* ─── Plan record helpers ───────────────────────────────────────────────────── */

if ( ! function_exists( 'db_plan_get' ) ) {
	function db_plan_get( $domain ) {
		return (array) get_option( 'db_stripe_plan_' . md5( $domain ), array() );
	}
}
if ( ! function_exists( 'db_plan_save' ) ) {
	function db_plan_save( $domain, array $plan ) {
		update_option( 'db_stripe_plan_' . md5( $domain ), $plan, false );
	}
}
if ( ! function_exists( 'db_plan_paid_so_far' ) ) {
	function db_plan_paid_so_far( array $plan ) {
		$sum = 0.0;
		foreach ( (array) ( $plan['history'] ?? array() ) as $h ) {
			$sum += (float) ( $h['amount_usd'] ?? 0 );
		}
		return $sum;
	}
}
if ( ! function_exists( 'db_plan_money' ) ) {
	function db_plan_money( $n ) {
		return '$' . number_format( (float) $n, 2 );
	}
}

/* ─── Customer-facing terms (single source of wording) ──────────────────────── */

if ( ! function_exists( 'db_plan_terms_html' ) ) {
	/**
	 * The consequences, in plain words. Shown at the plan chooser and at
	 * checkout, and repeated in the plan-start email, so the customer has
	 * seen them before and after the first payment.
	 */
	function db_plan_terms_html() {
		$g = (int) DB_PLAN_GRACE_DAYS;
		return '<strong>Payment plan terms.</strong> The domain stays with Domain Brothers, held in escrow, until your <em>final</em> payment clears; ownership transfers to you only then. '
			. 'If a payment fails we will email you and retry for up to ' . $g . ' days. '
			. 'If it is still unpaid after ' . $g . ' days the plan is terminated and the domain returns to our inventory. '
			. '<strong>Payments already made are non-refundable and are not credited toward any other purchase.</strong>';
	}
}

/* ─── Email building blocks ─────────────────────────────────────────────────── */

if ( ! function_exists( 'db_plan_update_card_url' ) ) {
	/** Signed link that opens Stripe's hosted page for updating the card on file. */
	function db_plan_update_card_url( $domain ) {
		$sig = hash_hmac( 'sha256', 'card|' . strtolower( $domain ), wp_salt( 'auth' ) );
		return add_query_arg(
			array( 'db_update_card' => 1, 'd' => rawurlencode( $domain ), 't' => $sig ),
			home_url( '/' )
		);
	}
}

if ( ! function_exists( 'db_plan_email_shell' ) ) {
	function db_plan_email_shell( $headline, $paras_html, $details = array(), $cta_label = '', $cta_url = '', $tone = 'neutral', $deadline_note = false ) {
		$colors = array( 'neutral' => '#111827', 'warn' => '#b45309', 'danger' => '#b91c1c', 'good' => '#137a3e' );
		$c      = isset( $colors[ $tone ] ) ? $colors[ $tone ] : $colors['neutral'];
		$body   = '<p style="font-size:20px;font-weight:700;color:' . $c . ';margin:0 0 12px;">' . esc_html( $headline ) . '</p>' . $paras_html;
		if ( $details ) {
			$body .= '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:18px 0;">';
			foreach ( $details as $label => $val ) {
				$body .= '<tr><td style="padding:8px 14px;font-size:14px;color:#6b7280;border-bottom:1px solid #f3f4f6;width:42%;">' . esc_html( $label ) . '</td>'
					. '<td style="padding:8px 14px;font-size:14px;color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6;text-align:right;">' . esc_html( $val ) . '</td></tr>';
			}
			$body .= '</table>';
		}
		if ( $cta_label && $cta_url ) {
			$body .= '<p style="margin:22px 0;"><a href="' . esc_url( $cta_url ) . '" style="display:inline-block;background:#08173A;color:#ffffff;text-decoration:none;padding:13px 26px;border-radius:8px;font-weight:600;font-size:15px;">' . esc_html( $cta_label ) . '</a></p>';
		}
		$body .= '<p style="font-size:13px;color:#6b7280;">Questions or need more time? Reply to this email or write to <a href="mailto:' . esc_attr( DB_PLAN_SUPPORT_EMAIL ) . '" style="color:#0a6ed1;">' . esc_html( DB_PLAN_SUPPORT_EMAIL ) . '</a> &mdash;' . ( $deadline_note ? ' please do that <em>before</em> the deadline, not after.' : ' we are happy to help.' ) . '</p>';
		return $body;
	}
}

if ( ! function_exists( 'db_plan_send' ) ) {
	function db_plan_send( $to, $subject, $body_html ) {
		if ( ! $to ) { return false; }
		if ( function_exists( 'db_send_branded_email' ) ) {
			return db_send_branded_email( $to, $subject, $body_html, DB_PLAN_SUPPORT_EMAIL );
		}
		return wp_mail( $to, $subject, $body_html, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}

if ( ! function_exists( 'db_plan_notify_admin' ) ) {
	function db_plan_notify_admin( $subject, array $lines ) {
		$to   = (string) get_option( 'admin_email' );
		$body = '<p>' . nl2br( esc_html( implode( "\n", $lines ) ) ) . '</p>';
		if ( function_exists( 'db_send_branded_email' ) ) {
			db_send_branded_email( $to, $subject, $body );
		} else {
			wp_mail( $to, $subject, implode( "\n", $lines ) );
		}
	}
}

/* ─── The messages ──────────────────────────────────────────────────────────── */

if ( ! function_exists( 'db_plan_message' ) ) {
	/**
	 * Returns array( subject, html ) for a lifecycle message.
	 *
	 * @param string $key reminder | failed_1 | failed_2 | failed_3 | failed_4 | recovered | defaulted
	 * @param array  $plan Plan record.
	 * @param array  $x    Extra context: amount, due_ts, grace_end_ts, days_left.
	 */
	function db_plan_message( $key, array $plan, array $x = array() ) {
		$domain   = (string) ( $plan['domain'] ?? '' );
		$months   = (int) ( $plan['total_months'] ?? 0 );
		$n        = (int) ( $plan['next_installment'] ?? 1 );
		$price    = (float) ( $plan['price_usd'] ?? 0 );
		$amount   = isset( $x['amount'] ) ? (float) $x['amount'] : db_stripe_installment_cents( $price, max( 1, $months ), max( 1, $n ) ) / 100;
		$paid     = db_plan_paid_so_far( $plan );
		$total    = db_plan_total_cents( $price, max( 1, $months ) ) / 100;
		$remain   = max( 0, $total - $paid );
		$d        = $domain;
		$fmt      = function ( $ts ) { return wp_date( 'F j, Y', (int) $ts ); };
		$grace    = (int) DB_PLAN_GRACE_DAYS;
		$grace_end = isset( $x['grace_end_ts'] ) ? $fmt( $x['grace_end_ts'] ) : '';
		$card_url = db_plan_update_card_url( $domain );
		$inst     = sprintf( 'Payment %d of %d', $n, $months );

		$base = array(
			'Domain'  => $d,
			$inst     => db_plan_money( $amount ),
		);

		switch ( $key ) {

			case 'reminder':
				$due = isset( $x['due_ts'] ) ? $fmt( $x['due_ts'] ) : '';
				return array(
					sprintf( 'Payment %d of %d for %s will be charged on %s', $n, $months, $d, $due ),
					db_plan_email_shell(
						'Your next payment is coming up',
						'<p>This is a courtesy reminder. We will charge your card on file <strong>' . esc_html( db_plan_money( $amount ) ) . '</strong> on <strong>' . esc_html( $due ) . '</strong> for installment ' . (int) $n . ' of ' . (int) $months . ' on <strong>' . esc_html( $d ) . '</strong>.</p>'
						. '<p>Nothing to do if your card is current and has funds available. If your card has changed or expired, update it now so the payment does not fail.</p>',
						$base + array( 'Paid so far' => db_plan_money( $paid ), 'Remaining after this payment' => db_plan_money( max( 0, $remain - $amount ) ) ),
						'Update my card',
						$card_url
					),
				);

			case 'failed_1':
				return array(
					sprintf( 'Action needed: your payment for %s did not go through', $d ),
					db_plan_email_shell(
						'Your payment did not go through',
						'<p>We tried to charge your card for installment ' . (int) $n . ' of ' . (int) $months . ' on <strong>' . esc_html( $d ) . '</strong> and the card was declined. This usually means an expired card, a changed card number, or insufficient funds.</p>'
						. '<p><strong>What happens next:</strong> we will try again automatically over the next ' . (int) $grace . ' days. To avoid losing the domain, update your card or make sure funds are available <strong>by ' . esc_html( $grace_end ) . '</strong>.</p>'
						. '<p>If the payment is not received by then, your plan will be terminated, the domain will be returned to our inventory, and <strong>the ' . esc_html( db_plan_money( $paid ) ) . ' you have paid so far will not be refunded</strong>.</p>',
						$base + array( 'Fix it by' => $grace_end ),
						'Update my card now',
						$card_url,
						'neutral',
						true
					),
				);

			case 'failed_2':
				return array(
					sprintf( 'Still unpaid: %s — %d days left to keep your domain', $d, (int) $x['days_left'] ),
					db_plan_email_shell(
						'Your payment is still outstanding',
						'<p>We retried your payment for <strong>' . esc_html( $d ) . '</strong> and it was declined again. You have <strong>' . (int) $x['days_left'] . ' days left</strong> (until ' . esc_html( $grace_end ) . ').</p>'
						. '<p>If it is not paid by then the plan ends automatically. You would lose the domain and the ' . esc_html( db_plan_money( $paid ) ) . ' already paid, and the plan cannot be reinstated.</p>',
						$base + array( 'Deadline' => $grace_end ),
						'Update my card now',
						$card_url,
						'warn',
						true
					),
				);

			case 'failed_3':
				return array(
					sprintf( 'URGENT: %s will be released in %d days', $d, (int) $x['days_left'] ),
					db_plan_email_shell(
						'Urgent — your domain is at risk',
						'<p>Your payment for <strong>' . esc_html( $d ) . '</strong> is now ' . (int) ( $grace - (int) $x['days_left'] ) . ' days overdue. <strong>Only ' . (int) $x['days_left'] . ' days remain</strong> before the plan is terminated on ' . esc_html( $grace_end ) . '.</p>'
						. '<p>If that happens: the domain goes back on the market (someone else may buy it), your ' . esc_html( db_plan_money( $paid ) ) . ' in previous payments is forfeited, and you would have to buy the domain again at the then-current price.</p>'
						. '<p>If you cannot pay in time, contact us <em>today</em> &mdash; we can only discuss options before the deadline passes.</p>',
						$base + array( 'Terminated on' => $grace_end ),
						'Update my card now',
						$card_url,
						'danger',
						true
					),
				);

			case 'failed_4':
				return array(
					sprintf( 'FINAL NOTICE: %s will be released within 24 hours', $d ),
					db_plan_email_shell(
						'Final notice — 24 hours left',
						'<p>This is the last notice before your payment plan for <strong>' . esc_html( $d ) . '</strong> is terminated. If we have not received ' . esc_html( db_plan_money( $amount ) ) . ' by <strong>' . esc_html( $grace_end ) . '</strong>:</p>'
						. '<ul style="padding-left:20px;"><li>the plan ends and cannot be reinstated;</li><li>the domain is returned to our inventory and can be sold to someone else;</li><li>the <strong>' . esc_html( db_plan_money( $paid ) ) . '</strong> you have paid is non-refundable and is not credited to any future purchase.</li></ul>',
						$base + array( 'Terminated on' => $grace_end ),
						'Pay now — update my card',
						$card_url,
						'danger',
						true
					),
				);

			case 'recovered':
				return array(
					sprintf( 'Thank you — your plan for %s is back on track', $d ),
					db_plan_email_shell(
						'Payment received — you are back on track',
						'<p>We received your payment of <strong>' . esc_html( db_plan_money( $amount ) ) . '</strong> for <strong>' . esc_html( $d ) . '</strong>. Your plan is active again and no further action is needed.</p>'
						. '<p>Your next installment will be charged one month from today.</p>',
						array( 'Domain' => $d, 'Paid so far' => db_plan_money( $paid ), 'Payments remaining' => (string) max( 0, $months - (int) ( $plan['paid_installments'] ?? 0 ) ) ),
						'',
						'',
						'good'
					),
				);

			case 'defaulted':
				return array(
					sprintf( 'Your payment plan for %s has been terminated', $d ),
					db_plan_email_shell(
						'Your payment plan has been terminated',
						'<p>We were unable to collect installment ' . (int) $n . ' of ' . (int) $months . ' for <strong>' . esc_html( $d ) . '</strong> within the ' . (int) $grace . '-day grace period, despite the reminders we sent. As set out in the plan terms you accepted, the plan has ended.</p>'
						. '<ul style="padding-left:20px;"><li>The domain has been returned to Domain Brothers\' inventory and any access we had arranged for you will be removed. It was never transferred to you.</li>'
						. '<li>The <strong>' . esc_html( db_plan_money( $paid ) ) . '</strong> paid to date is non-refundable and is not credited to any other purchase.</li>'
						. '<li>No further payments will be charged on this plan.</li></ul>'
						. '<p>If you still want the domain, contact us &mdash; if it is still available we can discuss a new purchase at the current price. We are sorry it ended this way.</p>',
						array( 'Domain' => $d, 'Total paid (non-refundable)' => db_plan_money( $paid ), 'Terminated on' => wp_date( 'F j, Y' ) ),
						'',
						'',
						'danger'
					),
				);
		}
		return array( '', '' );
	}
}

/* ─── State transitions ─────────────────────────────────────────────────────── */

if ( ! function_exists( 'db_plan_on_installment_failed' ) ) {
	/** Called from the payment_intent.payment_failed webhook for plan installments. */
	function db_plan_on_installment_failed( $domain ) {
		$plan = db_plan_get( $domain );
		if ( empty( $plan ) || ! in_array( $plan['status'] ?? '', array( 'active', 'past_due' ), true ) ) {
			return;
		}
		if ( 'past_due' === $plan['status'] ) {
			// A retry inside the grace period also declined. The scheduled
			// notices (day 3/6/9) already tell the customer; don't pile on.
			return;
		}
		$plan['status']         = 'past_due';
		$plan['past_due_since'] = time();
		$plan['notice_stage']   = 1;
		db_plan_save( $domain, $plan );

		list( $subj, $html ) = db_plan_message( 'failed_1', $plan, array( 'grace_end_ts' => $plan['past_due_since'] + DB_PLAN_GRACE_DAYS * DAY_IN_SECONDS ) );
		db_plan_send( (string) ( $plan['customer_email'] ?? '' ), $subj, $html );
		db_plan_notify_admin( 'PLAN PAYMENT FAILED: ' . $domain, array(
			'Installment ' . (int) $plan['next_installment'] . ' of ' . (int) $plan['total_months'] . ' declined.',
			'Domain   : ' . $domain,
			'Customer : ' . ( $plan['customer_email'] ?? 'Unknown' ),
			'Paid so far: ' . db_plan_money( db_plan_paid_so_far( $plan ) ),
			'Grace period: ' . DB_PLAN_GRACE_DAYS . ' days (retries on day 3, 6, 9; termination day ' . DB_PLAN_GRACE_DAYS . ').',
		) );
	}
}

if ( ! function_exists( 'db_plan_on_recovered' ) ) {
	/** Called from the success webhook when a past-due plan's installment finally clears. */
	function db_plan_on_recovered( $domain, $amount_usd ) {
		// Only called by the success webhook when the plan WAS past due; by
		// then that path has already saved status=active, so no status guard
		// here (one used to make this return early: no recovery email, and
		// past_due_since never cleared — caught by tests/plan).
		$plan = db_plan_get( $domain );
		$plan['status']         = 'active';
		$plan['past_due_since'] = 0;
		$plan['notice_stage']   = 0;
		db_plan_save( $domain, $plan );
		list( $subj, $html ) = db_plan_message( 'recovered', $plan, array( 'amount' => $amount_usd ) );
		db_plan_send( (string) ( $plan['customer_email'] ?? '' ), $subj, $html );
		db_plan_notify_admin( 'PLAN RECOVERED: ' . $domain, array( 'Past-due installment paid.', 'Domain: ' . $domain ) );
	}
}

if ( ! function_exists( 'db_plan_default' ) ) {
	function db_plan_default( array $plan ) {
		$domain           = (string) $plan['domain'];
		$plan['status']   = 'defaulted';
		$plan['defaulted_ts'] = time();
		db_plan_save( $domain, $plan );
		db_stripe_plan_index_remove( $domain );

		list( $subj, $html ) = db_plan_message( 'defaulted', $plan );
		db_plan_send( (string) ( $plan['customer_email'] ?? '' ), $subj, $html );
		db_plan_notify_admin( 'PLAN TERMINATED — ACTION REQUIRED: ' . $domain, array(
			'Plan terminated for non-payment after ' . DB_PLAN_GRACE_DAYS . '-day grace period.',
			'Domain   : ' . $domain,
			'Customer : ' . ( $plan['customer_email'] ?? 'Unknown' ),
			'Forfeited: ' . db_plan_money( db_plan_paid_so_far( $plan ) ) . ' (non-refundable per plan terms)',
			'',
			'TO DO: (1) remove any DNS/hosting pointed at the buyer, (2) confirm the domain is still in our registrar account, (3) relist it.',
		) );
		do_action( 'db_plan_defaulted', $domain, $plan );
	}
}

/* ─── Daily cron: reminders, retries, notices, termination ──────────────────── */

if ( ! function_exists( 'db_plan_run_daily' ) ) {
	function db_plan_run_daily() {
		$keys = db_stripe_keys();
		if ( empty( $keys['sec'] ) ) {
			return;
		}
		$now = time();
		foreach ( (array) get_option( 'db_stripe_active_plans', array() ) as $plan_key ) {
			$plan   = (array) get_option( 'db_stripe_plan_' . $plan_key, array() );
			$status = (string) ( $plan['status'] ?? '' );
			if ( empty( $plan ) || ! in_array( $status, array( 'active', 'past_due' ), true ) ) {
				continue;
			}
			$domain = (string) $plan['domain'];

			if ( 'active' === $status ) {
				$due = (int) ( $plan['next_charge_ts'] ?? 0 );
				$n   = (int) ( $plan['next_installment'] ?? 0 );
				// Reminder DB_PLAN_REMIND_DAYS before, once per installment.
				if ( $due > $now && $due - $now <= DB_PLAN_REMIND_DAYS * DAY_IN_SECONDS && (int) ( $plan['reminded_for'] ?? 0 ) < $n ) {
					list( $subj, $html ) = db_plan_message( 'reminder', $plan, array( 'due_ts' => $due ) );
					db_plan_send( (string) ( $plan['customer_email'] ?? '' ), $subj, $html );
					$plan['reminded_for'] = $n;
					db_plan_save( $domain, $plan );
				}
				// Due: charge (de-bounced so a slow webhook can't cause a double charge).
				if ( $due <= $now && $now - (int) ( $plan['last_attempt_ts'] ?? 0 ) > 20 * HOUR_IN_SECONDS ) {
					db_stripe_charge_next_installment( $plan );
				}
				continue;
			}

			// past_due: walk the dunning schedule.
			$since = (int) ( $plan['past_due_since'] ?? 0 );
			if ( $since <= 0 ) {
				continue;
			}
			$day       = (int) floor( ( $now - $since ) / DAY_IN_SECONDS );
			$grace_end = $since + DB_PLAN_GRACE_DAYS * DAY_IN_SECONDS;
			$stage     = (int) ( $plan['notice_stage'] ?? 1 );

			if ( $day >= DB_PLAN_GRACE_DAYS ) {
				db_plan_default( $plan );
				continue;
			}

			$schedule = array( 3 => 'failed_2', 6 => 'failed_3', 9 => 'failed_4' ); // day => message
			$step     = 1;
			foreach ( $schedule as $d_mark => $msg_key ) {
				$step++;
				if ( $day >= $d_mark && $stage < $step ) {
					// Retry the charge first; if the card works the success
					// webhook flips the plan back to active and sends "recovered".
					if ( $now - (int) ( $plan['last_attempt_ts'] ?? 0 ) > 20 * HOUR_IN_SECONDS ) {
						db_stripe_charge_next_installment( $plan );
						$plan = db_plan_get( $domain );
					}
					list( $subj, $html ) = db_plan_message( $msg_key, $plan, array( 'grace_end_ts' => $grace_end, 'days_left' => max( 1, DB_PLAN_GRACE_DAYS - $day ) ) );
					db_plan_send( (string) ( $plan['customer_email'] ?? '' ), $subj, $html );
					$plan['notice_stage'] = $step;
					db_plan_save( $domain, $plan );
					break; // one notice per run
				}
			}
		}
	}
}

add_action( 'db_stripe_charge_due_installments', 'db_plan_run_daily' );

/* ─── "Update my card": signed link -> Stripe Billing Portal ────────────────── */

add_action( 'template_redirect', function () {
	if ( empty( $_GET['db_update_card'] ) || empty( $_GET['d'] ) || empty( $_GET['t'] ) ) {
		return;
	}
	nocache_headers();
	$domain = sanitize_text_field( rawurldecode( wp_unslash( $_GET['d'] ) ) );
	$sig    = sanitize_text_field( wp_unslash( $_GET['t'] ) );
	$want   = hash_hmac( 'sha256', 'card|' . strtolower( $domain ), wp_salt( 'auth' ) );
	$plan   = hash_equals( $want, $sig ) ? db_plan_get( $domain ) : array();
	$fail   = function () {
		wp_die(
			'We could not open the card update page automatically. Please email <a href="mailto:' . esc_attr( DB_PLAN_SUPPORT_EMAIL ) . '">' . esc_html( DB_PLAN_SUPPORT_EMAIL ) . '</a> and we will help right away.',
			'Update payment method',
			array( 'response' => 200 )
		);
	};
	if ( empty( $plan['customer_id'] ) || ! in_array( $plan['status'] ?? '', array( 'active', 'past_due' ), true ) ) {
		$fail();
	}
	$keys     = db_stripe_keys();
	$response = wp_remote_post( 'https://api.stripe.com/v1/billing_portal/sessions', array(
		'headers' => array( 'Authorization' => 'Bearer ' . $keys['sec'], 'Content-Type' => 'application/x-www-form-urlencoded' ),
		'body'    => array( 'customer' => $plan['customer_id'], 'return_url' => home_url( '/' ) ),
		'timeout' => 20,
	) );
	$data = is_wp_error( $response ) ? array() : json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['url'] ) ) {
		error_log( 'DB plan: billing portal session failed for ' . $domain . ' — ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response ) ) );
		$fail();
	}
	wp_redirect( $data['url'] ); // external Stripe URL, signed link already verified
	exit;
}, 1 );
