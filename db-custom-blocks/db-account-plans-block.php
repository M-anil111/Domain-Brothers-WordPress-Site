<?php
/**
 * Payment plans in the customer account.
 *
 *  - A payment plan needs a free account: /buy-now/?months=N sends a signed-out
 *    visitor to sign in (the same email code creates an account) and back.
 *    Paying in full stays open to guests.
 *  - The signed-in email is stored on the PaymentIntent (metadata account_email)
 *    and copied onto the plan, so the plan belongs to the account even if a
 *    different receipt email is typed at checkout.
 *  - My account > Payment plans shows each plan: next payment (date, amount),
 *    paid so far, remaining, progress, the full schedule, card update, and a
 *    clear warning when a payment was declined.
 *
 * Plans themselves live in db-stripe-block.php / db-plan-lifecycle-block.php
 * (one option per domain); this file only reads them.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

/* A plan checkout needs an account. */
add_action( 'template_redirect', function () {
	if ( ! function_exists( 'db_wl_email' ) || ! function_exists( 'db_sales_request_path' ) ) {
		return;
	}
	if ( 'buy-now' !== trim( (string) db_sales_request_path(), '/' ) ) {
		return;
	}
	$months = isset( $_GET['months'] ) && ctype_digit( (string) wp_unslash( $_GET['months'] ) ) ? (int) $_GET['months'] : 0; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
	if ( ! in_array( $months, array( 3, 6, 9, 12 ), true ) || '' !== db_wl_email() ) {
		return;
	}
	// A listing under the plan floor gets no plan: skip sign-in, and the checkout sends the
	// buyer to pay in full (CK-15). Agreed deals (?offer=) keep the sign-in step.
	if ( empty( $_GET['offer'] ) && function_exists( 'db_plan_offered' ) && function_exists( 'db_stripe_lookup_domain_price' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$name  = base64_decode( (string) wp_unslash( $_GET['d'] ?? '' ), true ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$price = is_string( $name ) && preg_match( '/^[a-z0-9.-]{3,253}$/i', $name ) ? db_stripe_lookup_domain_price( $name ) : null;
		if ( null !== $price && ! db_plan_offered( $price ) ) {
			return;
		}
	}
	$back = home_url( add_query_arg( array() ) ); // the full checkout URL, query included
	nocache_headers();
	wp_safe_redirect( add_query_arg( array( 'auth' => 'signin', 'plan' => 1, 'next' => rawurlencode( $back ) ), home_url( '/my-wishlist/' ) ) );
	exit;
}, 5 );

/* The account email travels with the payment. */
add_filter( 'db_stripe_pi_metadata', function ( $meta ) {
	$email = function_exists( 'db_wl_email' ) ? db_wl_email() : '';
	if ( '' !== $email ) {
		$meta['account_email'] = $email;
	}
	return $meta;
} );

/* And lands on the plan record when a plan payment is recorded. */
add_action( 'db_order_event', function ( $kind, $data ) {
	if ( 'plan_installment' !== $kind || ! function_exists( 'db_plan_get' ) ) {
		return;
	}
	$acct = sanitize_email( (string) ( $data['meta']['account_email'] ?? '' ) );
	if ( '' === $acct || empty( $data['domain'] ) ) {
		return;
	}
	$plan = db_plan_get( (string) $data['domain'] );
	if ( $plan && ( $plan['account_email'] ?? '' ) !== $acct ) {
		$plan['account_email'] = strtolower( $acct );
		db_plan_save( (string) $data['domain'], $plan );
	}
}, 20, 2 );

if ( ! function_exists( 'db_acct_plans_for' ) ) {
	/**
	 * Every payment plan of this account (current Stripe mode), newest first.
	 * Matches the account email stored on the plan, or the receipt email.
	 *
	 * @return array<int,array> plan records
	 */
	function db_acct_plans_for( $email ) {
		static $memo = array();
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email || ! function_exists( 'db_stripe_mode' ) ) {
			return array();
		}
		if ( isset( $memo[ $email ] ) ) {
			return $memo[ $email ];
		}
		global $wpdb;
		$prefix = ( 'test' === db_stripe_mode() ? 'db_stripe_test_' : 'db_stripe_' ) . 'plan_';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s LIMIT 2000", $wpdb->esc_like( $prefix ) . '%', '%' . $wpdb->esc_like( '_archive_' ) . '%' ) );
		$out  = array();
		foreach ( $rows as $raw ) {
			$plan = maybe_unserialize( $raw );
			if ( ! is_array( $plan ) || empty( $plan['domain'] ) || empty( $plan['total_months'] ) ) {
				continue;
			}
			$mine = in_array( $email, array( strtolower( (string) ( $plan['account_email'] ?? '' ) ), strtolower( (string) ( $plan['customer_email'] ?? '' ) ) ), true );
			if ( $mine ) {
				$out[] = $plan;
			}
		}
		usort( $out, static function ( $a, $b ) {
			return (int) ( $b['history'][0]['ts'] ?? 0 ) <=> (int) ( $a['history'][0]['ts'] ?? 0 );
		} );
		return $memo[ $email ] = $out;
	}
}

if ( ! function_exists( 'db_acct_plan_summary' ) ) {
	/**
	 * The figures the account shows for one plan.
	 *
	 * @return array{status:string,total:float,paid:float,remaining:float,count_paid:int,months:int,next_due:int,next_amount:float,pct:int}
	 */
	function db_acct_plan_summary( array $plan ) {
		$rows   = function_exists( 'db_plan_schedule' ) ? db_plan_schedule( $plan ) : array();
		$charge = (int) ( $plan['next_charge_ts'] ?? 0 );
		foreach ( $rows as $i => $r ) {
			if ( $charge > 0 && in_array( $r['state'], array( 'next', 'overdue' ), true ) ) {
				$rows[ $i ]['due'] = $charge; // the date the charge is actually set for
			}
		}
		$total  = 0.0;
		$paid   = 0.0;
		$count  = 0;
		$next   = null;
		foreach ( $rows as $r ) {
			$total += (float) $r['amount'];
			if ( 'paid' === $r['state'] ) {
				$paid += (float) $r['amount'];
				$count++;
			} elseif ( null === $next ) {
				$next = $r;
			}
		}
		$status = (string) ( $plan['status'] ?? 'active' );
		if ( $rows && $count >= count( $rows ) ) {
			$status = 'completed';
		}
		$due = $next ? (int) ( $plan['next_charge_ts'] ?? 0 ) : 0;
		return array(
			'status'      => $status,
			'total'       => round( $total, 2 ),
			'paid'        => round( $paid, 2 ),
			'remaining'   => round( max( 0, $total - $paid ), 2 ),
			'count_paid'  => $count,
			'months'      => (int) ( $plan['total_months'] ?? count( $rows ) ),
			'next_due'    => $due > 0 ? $due : ( $next ? (int) $next['due'] : 0 ),
			'next_amount' => $next ? (float) $next['amount'] : 0.0,
			'pct'         => $total > 0 ? (int) round( 100 * $paid / $total ) : 0,
			'rows'        => $rows,
		);
	}
}

if ( ! function_exists( 'db_acct_plans_html' ) ) {
	/** The Payment plans tab. */
	function db_acct_plans_html( $email ) {
		$plans = db_acct_plans_for( $email );
		$h     = '<h2 class="db-ac-h">Payment plans</h2>';
		if ( ! $plans ) {
			return $h . '<div class="db-wl-card"><h2>No payment plans yet</h2><p>When you buy a domain on a monthly plan, its schedule, next payment and progress appear here. Plans are available on every priced domain: 3 months at 0%, 6 months at 5%, 9 months at 8% and 12 months at 10%.</p><p><a class="db-wl-primary" href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse domains</a></p></div>';
		}
		$label = array( 'active' => 'On track', 'past_due' => 'Payment declined', 'completed' => 'Paid off', 'defaulted' => 'Cancelled', 'cancelled' => 'Cancelled' );
		$tone  = array( 'active' => 'ok', 'past_due' => 'bad', 'completed' => 'done', 'defaulted' => 'off', 'cancelled' => 'off' );
		$h    .= '<div class="db-pl">';
		foreach ( $plans as $plan ) {
			$s    = db_acct_plan_summary( $plan );
			$st   = $s['status'];
			$card = function_exists( 'db_plan_update_card_url' ) && in_array( $st, array( 'active', 'past_due' ), true ) ? db_plan_update_card_url( $plan['domain'], $plan ) : '';
			$h   .= '<article class="db-pl-card db-pl--' . esc_attr( $tone[ $st ] ?? 'off' ) . '" aria-labelledby="db-pl-' . esc_attr( md5( $plan['domain'] ) ) . '">'
				. '<header class="db-pl-head"><div><h3 id="db-pl-' . esc_attr( md5( $plan['domain'] ) ) . '">' . esc_html( $plan['domain'] ) . '</h3><p>' . (int) $s['months'] . '-month plan · ' . esc_html( db_plan_money( $s['total'] ) ) . ' in total</p></div>'
				. '<span class="db-pl-pill">' . esc_html( $label[ $st ] ?? ucfirst( $st ) ) . '</span></header>';
			if ( 'past_due' === $st ) {
				$h .= '<p class="db-pl-alert" role="alert"><strong>Your last payment was declined.</strong> We retry automatically; update your card to keep the plan and the domain.' . ( $card ? ' <a href="' . esc_url( $card ) . '">Update card</a>' : '' ) . '</p>';
			}
			$h .= '<div class="db-pl-stats">';
			if ( 'completed' !== $st && $s['next_due'] ) {
				$h .= '<div class="db-pl-next"><span>Next payment</span><strong>' . esc_html( db_plan_money( $s['next_amount'] ) ) . '</strong><em>' . esc_html( wp_date( 'l, F j, Y', $s['next_due'] ) ) . '</em></div>';
			} else {
				$h .= '<div class="db-pl-next"><span>Status</span><strong>' . ( 'completed' === $st ? 'Paid in full' : esc_html( $label[ $st ] ?? $st ) ) . '</strong>' . ( 'completed' === $st ? '<em>The domain is yours; see Transfers.</em>' : '' ) . '</div>';
			}
			$h .= '<div><span>Paid so far</span><strong>' . esc_html( db_plan_money( $s['paid'] ) ) . '</strong><em>' . (int) $s['count_paid'] . ' of ' . (int) $s['months'] . ' payments</em></div>'
				. '<div><span>Remaining</span><strong>' . esc_html( db_plan_money( $s['remaining'] ) ) . '</strong><em>' . max( 0, (int) $s['months'] - (int) $s['count_paid'] ) . ' to go</em></div></div>'
				. '<div class="db-pl-bar" role="progressbar" aria-label="Plan paid" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . (int) $s['pct'] . '"><span style="width:' . (int) $s['pct'] . '%"></span></div>'
				. '<details class="db-pl-sched"><summary>Payment schedule</summary><div class="db-pl-tablewrap"><table><thead><tr><th scope="col">Payment</th><th scope="col">Date</th><th scope="col" class="db-pl-r">Amount</th><th scope="col">Status</th></tr></thead><tbody>';
			$words = array( 'paid' => 'Paid', 'next' => 'Next', 'overdue' => 'Declined, retrying', 'scheduled' => 'Scheduled' );
			foreach ( $s['rows'] as $r ) {
				$when = wp_date( 'M j, Y', 'paid' === $r['state'] && $r['paid_ts'] ? (int) $r['paid_ts'] : (int) $r['due'] );
				$h   .= '<tr class="is-' . esc_attr( $r['state'] ) . '"><td>' . (int) $r['n'] . ' of ' . count( $s['rows'] ) . '</td><td>' . esc_html( $when ) . '</td><td class="db-pl-r">' . esc_html( db_plan_money( $r['amount'] ) ) . '</td><td><span class="db-pl-st">' . esc_html( $words[ $r['state'] ] ?? $r['state'] ) . '</span></td></tr>';
			}
			$h .= '</tbody></table></div></details>';
			$h .= '<div class="db-pl-foot">'
				. ( $card ? '<a class="db-pl-btn" href="' . esc_url( $card ) . '">Update payment card</a>' : '' )
				. '<a class="db-pl-link" href="' . esc_url( home_url( '/payment-terms/' ) ) . '">Payment terms</a>'
				. '<a class="db-pl-link" href="' . esc_url( home_url( '/contact-us/' ) ) . '">Questions about this plan</a></div>';
			$h .= '</article>';
		}
		return $h . '</div>' . db_acct_plans_css();
	}
}

if ( ! function_exists( 'db_acct_plans_css' ) ) {
	function db_acct_plans_css() {
		return '<style>.db-pl{display:grid;gap:18px;margin-top:8px}.db-pl-card{padding:22px 24px;border:1px solid #e4e7ec;border-radius:22px;background:#fff;box-shadow:0 6px 20px rgba(15,23,42,.04)}'
			. '.db-pl--bad{border-color:#f3a5a5;box-shadow:0 0 0 4px #fff1f0}.db-pl-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap}'
			. 'html body .db-pl-head h3{margin:0;font-size:22px;font-weight:900;color:#0f172a;letter-spacing:-.01em;overflow-wrap:anywhere}.db-pl-head p{margin:2px 0 0;color:#4b5563;font-size:14.5px}'
			. '.db-pl-pill{padding:5px 12px;border-radius:999px;font-size:13px;font-weight:800;background:#e6f6df;color:#2f6b12}.db-pl--bad .db-pl-pill{background:#ffe4e6;color:#9f1239}.db-pl--done .db-pl-pill{background:#0f1b2d;color:#b5e61d}.db-pl--off .db-pl-pill{background:#eef1f4;color:#4b5563}'
			. '.db-pl-alert{margin:14px 0 0;padding:12px 14px;border-radius:14px;background:#fff1f0;color:#7a1610;font-size:14.5px}.db-pl-alert a{color:#7a1610;font-weight:800}'
			. '.db-pl-stats{display:grid;grid-template-columns:1.3fr 1fr 1fr;gap:12px;margin:18px 0 12px}@media(max-width:700px){.db-pl-stats{grid-template-columns:1fr 1fr}.db-pl-next{grid-column:1/-1}}'
			. '.db-pl-stats>div{display:grid;gap:2px;padding:14px 16px;border-radius:16px;background:#f6f7f9}.db-pl-stats span{font-size:12.5px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#5b6472}.db-pl-stats strong{font-size:22px;font-weight:900;color:#0f172a}.db-pl-stats em{font-style:normal;font-size:13.5px;color:#4b5563}'
			. '.db-pl-stats .db-pl-next{background:#0f1b2d}.db-pl-stats .db-pl-next span{color:#b5e61d}.db-pl-stats .db-pl-next strong{color:#fff;font-size:26px}.db-pl-stats .db-pl-next em{color:rgba(255,255,255,.85)}'
			. '.db-pl-bar{height:10px;border-radius:999px;background:#eef1f4;overflow:hidden}.db-pl-bar span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#65a30d,#b5e61d)}'
			. '.db-pl-sched{margin-top:14px;border-top:1px solid #eef1f4;padding-top:10px}.db-pl-sched summary{cursor:pointer;font-weight:800;color:#0f172a;padding:6px 0}.db-pl-tablewrap{overflow-x:auto}'
			. '.db-pl-sched table{width:100%;border-collapse:collapse;margin-top:6px;font-size:14.5px;font-variant-numeric:tabular-nums}.db-pl-sched th{padding:8px 10px;text-align:left;font-size:12.5px;color:#5b6472;border-bottom:1px solid #e4e7ec}.db-pl-sched td{padding:9px 10px;border-bottom:1px solid #f1f3f5}.db-pl-r{text-align:right!important}'
			. '.db-pl-sched tr.is-next td{font-weight:800;background:#f6fbe9}.db-pl-sched tr.is-overdue td{font-weight:800;background:#fff1f0;color:#7a1610}.db-pl-sched tr.is-paid .db-pl-st{color:#2f6b12;font-weight:700}'
			. '.db-pl-foot{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;margin-top:14px}html body .db-pl-btn{display:inline-flex;align-items:center;min-height:44px;padding:0 18px;border-radius:999px;background:#0f1b2d;color:#fff!important;font-weight:800;text-decoration:none!important}html body .db-pl-link{color:#3f6212;font-weight:700}</style>';
	}
}
