<?php
/**
 * Payment terms page at /payment-terms/ (the old /payment-plans/ address redirects here).
 *
 * One place for everything about paying: prices and offers, card payments, pay
 * in full, payment plans with a calculator, missed payments, ownership during a
 * plan, the transfer after the final payment, taxes and bank charges,
 * chargebacks and the no-refund rule. The checkout step (/payment-plan-setup/)
 * is a different page: it works on one chosen domain and redirects away without one.
 *
 * Every rate, term length, retry day and grace period is read from the plan
 * functions that charge the cards (db_plan_terms, db_plan_rate, DB_PLAN_*), so
 * the page cannot drift from what is enforced. It is a virtual route, like the
 * Trust center, so it ships with the plugin and needs no page in the database.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_pp_is_route' ) ) {
	/** Whether the request is /payment-terms/. */
	function db_pp_is_route() {
		return function_exists( 'db_sales_request_path' ) && 'payment-terms' === db_sales_request_path();
	}
}

if ( ! function_exists( 'db_pp_money' ) ) {
	/**
	 * @param float $amount Amount in dollars.
	 * @return string e.g. $1,666.67
	 */
	function db_pp_money( $amount ) {
		return ( defined( 'DB_PLAN_CURRENCY' ) ? DB_PLAN_CURRENCY : '$' ) . number_format( (float) $amount, 2 );
	}
}

if ( ! function_exists( 'db_pp_terms' ) ) {
	/** @return int[] Plan lengths in months. */
	function db_pp_terms() {
		return function_exists( 'db_plan_terms' ) ? array_map( 'intval', db_plan_terms() ) : array( 3, 6, 9, 12 );
	}
}

if ( ! function_exists( 'db_pp_rate' ) ) {
	/** Interest rate in percent for a term. */
	function db_pp_rate( $months ) {
		return function_exists( 'db_plan_rate' ) ? (float) db_plan_rate( $months ) : 0.0;
	}
}

if ( ! function_exists( 'db_pp_plan_card' ) ) {
	/**
	 * One plan card for a price, rendered on the server so the default figures
	 * are visible before any script runs.
	 */
	function db_pp_plan_card( $months, $price ) {
		$rate  = db_pp_rate( $months );
		$total = round( (float) $price * ( 100 + $rate ) / 100, 2 );
		$each  = round( $total / max( 1, (int) $months ), 2 );
		$badge = $rate > 0
			? '<span class="dbpp-badge dbpp-badge--rate">' . esc_html( rtrim( rtrim( number_format( $rate, 1 ), '0' ), '.' ) ) . '% interest</span>'
			: '<span class="dbpp-badge">No interest</span>';
		return '<li class="dbpp-plan" data-months="' . (int) $months . '" data-rate="' . esc_attr( (string) $rate ) . '">'
			. '<span class="dbpp-term">' . (int) $months . ' months</span>'
			. '<strong class="dbpp-pay"><span data-each>' . esc_html( db_pp_money( $each ) ) . '</span><small>per month</small></strong>'
			. $badge
			. '<span class="dbpp-total">Total <b data-total>' . esc_html( db_pp_money( $total ) ) . '</b></span></li>';
	}
}

if ( ! function_exists( 'db_pp_list' ) ) {
	/** "3, 6, 9 or 12" from an int list. */
	function db_pp_list( array $n ) {
		$n = array_values( array_map( 'intval', $n ) );
		return count( $n ) > 1 ? implode( ', ', array_slice( $n, 0, -1 ) ) . ' or ' . end( $n ) : (string) ( $n[0] ?? '' );
	}
}

if ( ! function_exists( 'db_pp_sections' ) ) {
	/**
	 * The page sections as id => array( title, html ). Every figure comes from the
	 * plan functions and constants that charge the cards.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	function db_pp_sections() {
		$terms   = db_pp_terms();
		$mail    = defined( 'DB_PLAN_SUPPORT_EMAIL' ) ? (string) DB_PLAN_SUPPORT_EMAIL : 'sales@domainbrothers.com';
		$mail_a  = '<a href="mailto:' . esc_attr( $mail ) . '">' . esc_html( $mail ) . '</a>';
		$retries = defined( 'DB_PLAN_RETRY_DAYS' ) ? array_map( 'intval', (array) DB_PLAN_RETRY_DAYS ) : array();
		$grace   = defined( 'DB_PLAN_GRACE_DAYS' ) ? (int) DB_PLAN_GRACE_DAYS : 0;
		$remind  = defined( 'DB_PLAN_REMIND_DAYS' ) ? (int) DB_PLAN_REMIND_DAYS : 0;
		$free    = array();
		$paid    = array();
		foreach ( $terms as $m ) {
			if ( db_pp_rate( $m ) > 0 ) {
				$paid[ $m ] = db_pp_rate( $m );
			} else {
				$free[] = $m;
			}
		}
		$fmt = function ( $r ) {
			return rtrim( rtrim( number_format( (float) $r, 1 ), '0' ), '.' );
		};
		$rows = '';
		foreach ( $terms as $m ) {
			$r     = db_pp_rate( $m );
			$rows .= '<tr><th scope="row">' . (int) $m . ' months</th><td>' . ( $r > 0 ? esc_html( $fmt( $r ) ) . '%' : 'None' ) . '</td><td>' . esc_html( 100 + $r ) . '% of the listed price</td><td>' . esc_html( db_pp_money( round( 5000 * ( 100 + $r ) / 100 / $m, 2 ) ) ) . '</td></tr>';
		}
		$retry_txt = $retries ? 'on days ' . implode( ', ', $retries ) . ' after the first decline' : 'automatically';

		$s = array();

		$s['glance'] = array( 'At a glance', '<ul class="dbpp-facts">'
			. '<li><strong>US dollars</strong><span>Every price and payment is in USD, by card.</span></li>'
			. '<li><strong>Stripe handles the card</strong><span>We never see or store your card number.</span></li>'
			. '<li><strong>' . esc_html( db_pp_list( $terms ) ) . ' month plans</strong><span>' . ( $free ? 'No interest up to ' . (int) max( $free ) . ' months. ' : '' ) . ( $paid ? esc_html( implode( ' and ', array_map( function ( $m, $r ) use ( $fmt ) { return $fmt( $r ) . '% on ' . (int) $m . ' months'; }, array_keys( $paid ), $paid ) ) ) . '.' : '' ) . '</span></li>'
			. '<li><strong>Ownership comes last</strong><span>The domain transfers to you after the final payment clears.</span></li>'
			. '<li><strong>All sales are final</strong><span>Payments, including every instalment, are not refundable.</span></li>'
			. '<li><strong>Questions first</strong><span>Ask before you pay: ' . $mail_a . '.</span></li></ul>' );

		$s['prices'] = array( 'Prices, offers and currency', '<p>The price on a listing is the price of the domain name in US dollars. Some names show "Open to offers" instead of a price. For those, an offer becomes the price only when we accept it in writing, and an accepted offer is a commitment to buy at that price.</p>'
			. '<p>The price and schedule shown when you accept a plan apply for the whole plan. Prices on the site can change for later purchases; a change never alters a plan you have already accepted.</p>'
			. '<p>You are responsible for any taxes or duties that apply where you live, and for any currency conversion or card charges your bank adds when it converts dollars. We do not control those charges and they are not refundable by us.</p>' );

		$s['card'] = array( 'Paying by card', '<p>Card payments are taken by Stripe, a third-party payment provider. You enter your card details into Stripe\'s payment form. Domain Brothers does not receive or store the card number, and the secure form works the same for a single payment and for a plan.</p>'
			. '<ul class="dbpp-list"><li>Use a card that is yours or that you are authorised to use, and give accurate billing details.</li>'
			. '<li>Your bank may ask you to confirm a payment (for example with a code or in your banking app). A payment that is not confirmed is not completed.</li>'
			. '<li>Each payment is confirmed by email with a receipt link. Receipt links are signed and expire.</li></ul>' );

		$s['full'] = array( 'Paying in full', '<p>You pay the listed price once. When the payment is confirmed we begin the transfer of the domain to you. The domain is yours to move once the transfer completes; see "After the final payment" below.</p>' );

		$s['plans'] = array( 'Payment plans', '<p>A payment plan spreads the price over ' . esc_html( db_pp_list( $terms ) ) . ' equal monthly instalments. The first instalment is taken when you accept the plan. Each later instalment is charged automatically to the card on file, one month after the previous one. You see the full schedule and these terms before you accept, and your acceptance is recorded with the plan.</p>'
			. '<div class="dbpp-table-wrap"><table class="dbpp-table"><thead><tr><th scope="col">Plan length</th><th scope="col">Interest</th><th scope="col">Total you pay</th><th scope="col">Each month on a $5,000 name</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
			. '<p class="dbpp-small">Interest is a fixed charge added to the price when the plan is set up. It is not a daily or compounding rate, and it does not grow if you are late. The calculator above shows the figures for any price.</p>' );

		$s['missed'] = array( 'If a payment is declined', '<p>We email you before an instalment is due' . ( $remind ? ' (about ' . (int) $remind . ' days ahead)' : '' ) . ' and again if a payment is declined. A declined payment is retried automatically' . ( $retries ? ' ' . count( $retries ) . ' more times, ' . esc_html( $retry_txt ) : '' ) . '. Every decline email carries a secure link, valid for 21 days, to Stripe\'s page where you can update the card.</p>'
			. '<p>If the instalment is still unpaid ' . ( $grace ? (int) $grace . ' days' : 'after the grace period' ) . ' after the first decline, <strong>the plan ends automatically</strong>. The domain returns to our inventory and may be sold to someone else. Payments already made are non-refundable and are not credited toward any other purchase.</p>' );

		$s['ownership'] = array( 'Who owns the domain during a plan', '<p>The domain stays registered and held by Domain Brothers until your final payment clears, and ownership transfers to you only then. We keep it registered and locked during the plan so nobody can move it. During the plan you can point the name\'s nameservers at your own hosting, but you are using the name, not owning it, and you must use it lawfully and in line with our <a href="' . esc_url( home_url( '/acceptable-use-policy/' ) ) . '">Acceptable Use Policy</a>.</p>' );

		$s['after'] = array( 'After the final payment', '<p>Once the last payment clears we start the transfer to the registrar account you choose. We give you a private link to the authorisation (EPP) code, never the code itself in an email, and your account shows each transfer stage from payment to completion. Registries differ, and many transfers finish within a few days. Read the <a href="' . esc_url( home_url( '/epp-guidelines/' ) ) . '">transfer guidelines</a> before you start.</p>'
			. '<p>From the day the domain is in your registrar account, renewals and registrar fees are yours to pay at that registrar. Keep the contact email current and turn on the registrar lock and two-factor sign-in.</p>' );

		$s['changes'] = array( 'Changing, pausing or ending a plan', '<p>A plan\'s length and schedule cannot be changed online, and there is no online option to pause or end a plan or to pay it off early. If you want to ask, email ' . $mail_a . ' before the next payment is due. Whether to agree is our decision, and an agreement is only valid if we confirm it in writing. Stopping payments without agreement ends the plan under "If a payment is declined".</p>' );

		$s['disputes'] = array( 'Chargebacks and disputes', '<p>If you think a payment is wrong, contact us first at ' . $mail_a . '. We will look into it quickly. If you start a chargeback or payment dispute for a purchase you made and received, we will contest it with the records of your order, your recorded acceptance of these terms and the transfer history. We may suspend or cancel the domain and any related service while a dispute is open, and we may recover dispute fees and costs to the extent the law allows.</p>' );

		$s['refunds'] = array( 'No refunds', '<p>All sales are final. Every payment, including each instalment and any interest, is non-refundable, whether you pay in full or on a plan, and whether or not you later use, keep or renew the domain. Our <a href="' . esc_url( home_url( '/refund-policy/' ) ) . '">Refund Policy</a> sets this out in full.</p>'
			. '<p>Two things are not refunds of a sale. If we take a payment in error, for example the same instalment twice, we reverse the extra charge. If we decline an order or cannot deliver the domain before it is transferred to you, the payment for that order is returned to the card it came from.</p>' );

		$s['checks'] = array( 'Order checks', '<p>We may verify an order, ask for more information, or decline it, for example when a payment fails a security check or the details do not match. We do this to protect buyers and sellers from fraud, and we explain the reason when we can.</p>' );

		$s['contact'] = array( 'Questions and changes to these terms', '<p>Email ' . $mail_a . ' with any question about paying. We may update these terms; an update applies to purchases made after it is published, and a plan keeps the terms you accepted when it began. These terms work together with our <a href="' . esc_url( home_url( '/terms-of-use/' ) ) . '">Terms of Use</a>.</p>' );

		return $s;
	}
}

if ( ! function_exists( 'db_pp_body' ) ) {
	/** The page body (the first .db-sales-lead paragraph becomes the hero text). */
	function db_pp_body() {
		$default = 5000;
		$terms   = db_pp_terms();
		$free    = array();
		foreach ( $terms as $m ) {
			if ( db_pp_rate( $m ) <= 0 ) {
				$free[] = $m;
			}
		}
		$free_max = $free ? max( $free ) : 0;
		$browse   = esc_url( home_url( '/all-domains/' ) );
		$lead     = 'Everything about paying for a domain: prices and offers, paying by card, payment plans over ' . esc_html( db_pp_list( $terms ) ) . ' months'
			. ( $free_max ? ' (no interest up to ' . (int) $free_max . ' months)' : '' ) . ', missed payments, ownership and the transfer, and our no-refund rule.';

		$h = '<p class="db-sales-lead">' . $lead . '</p><div class="dbpp">';

		// The calculator is the main task of the page, so it comes first.
		$h .= '<section class="dbpp-calc" aria-labelledby="dbpp-calc-h"><div class="dbpp-calc-head"><h2 id="dbpp-calc-h">See what a plan costs</h2>'
			. '<p>Enter a domain price to compare every plan. The figures update as you type.</p></div>'
			. '<label class="dbpp-field" for="dbpp-price"><span>Domain price (USD)</span><span class="dbpp-input"><i aria-hidden="true">$</i>'
			. '<input id="dbpp-price" type="number" inputmode="numeric" min="100" max="1000000" step="50" value="' . (int) $default . '" aria-describedby="dbpp-note"></span></label>'
			. '<ol class="dbpp-plans" aria-live="polite">';
		foreach ( $terms as $m ) {
			$h .= db_pp_plan_card( $m, $default );
		}
		$h .= '</ol><p class="dbpp-note" id="dbpp-note">Shown for a ' . esc_html( db_pp_money( $default ) ) . ' domain until you change the price. The total is the listed price plus the plan\'s interest. Payments are in US dollars by card.</p></section>';

		// Contents + sections.
		$secs = db_pp_sections();
		$toc  = '';
		$body = '';
		foreach ( $secs as $id => $sec ) {
			$toc  .= '<li><a href="#pt-' . esc_attr( $id ) . '">' . esc_html( $sec[0] ) . '</a></li>';
			$body .= '<section class="dbpp-sec" id="pt-' . esc_attr( $id ) . '" aria-labelledby="pt-' . esc_attr( $id ) . '-h"><h2 id="pt-' . esc_attr( $id ) . '-h">' . esc_html( $sec[0] ) . '</h2>' . $sec[1] . '</section>';
		}
		$h .= '<div class="dbpp-layout"><nav class="dbpp-toc" aria-label="On this page"><details open><summary>On this page</summary><ol>' . $toc . '</ol></details></nav><div class="dbpp-main">' . $body . '</div></div>';

		// Next step.
		$h .= '<section class="dbpp-cta" aria-label="Browse domains"><h2>Ready to choose a name?</h2><a class="dbpp-cta-btn" href="' . $browse . '">Buy a premium domain now</a></section></div>';
		return $h;
	}
}

// The page was first published at /payment-plans/.
add_action( 'template_redirect', function () {
	if ( ! is_admin() && function_exists( 'db_sales_request_path' ) && 'payment-plans' === db_sales_request_path() ) {
		wp_safe_redirect( home_url( '/payment-terms/' ), 301 );
		exit;
	}
}, 9 );

add_action( 'template_redirect', function () {
	if ( is_admin() || ! db_pp_is_route() || ! function_exists( 'db_sales_render' ) ) {
		return;
	}
	db_sales_render( 'Payment terms', 'Payment terms', db_pp_body() );
}, 10 );

if ( ! function_exists( 'db_pp_meta' ) ) {
	/** @return array{0:string,1:string} Title and description. */
	function db_pp_meta() {
		return array(
			'Payment Terms: Card Payments, Plans and Ownership | Domain Brothers',
			'Everything about paying for a domain: prices and offers, card payments, payment plans over 3 to 12 months with a calculator, missed payments, ownership and transfer, and our no-refund rule.',
		);
	}
}
add_filter( 'pre_get_document_title', function ( $title ) {
	return db_pp_is_route() ? db_pp_meta()[0] : $title;
}, 31 );
add_filter( 'rank_math/frontend/title', function ( $title ) {
	return db_pp_is_route() ? db_pp_meta()[0] : $title;
}, 31 );
add_filter( 'rank_math/frontend/description', function ( $desc ) {
	return db_pp_is_route() ? db_pp_meta()[1] : $desc;
}, 31 );
add_filter( 'db_aeo_description', function ( $desc ) {
	return db_pp_is_route() ? db_pp_meta()[1] : $desc;
}, 31 );
add_action( 'wp_head', function () {
	if ( db_pp_is_route() && ! ( defined( 'DB_RANKMATH_OWNS_HEAD' ) && DB_RANKMATH_OWNS_HEAD ) ) {
		echo '<meta name="description" content="' . esc_attr( db_pp_meta()[1] ) . '">' . "\n";
	}
}, 2 );

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() || ! db_pp_is_route() ) {
		return;
	}
	// Designer's tokens: navy #0f1b2d, lime #b5e61d, soft lime #eef8d2, line #e4e7ec, ink #0f172a, text #374151.
	$hero = ( defined( 'DBR_ADR_URL' ) ) ? 'url("' . esc_url_raw( DBR_ADR_URL . 'assets/img/hero-domain.webp' ) . '")' : 'none';
	$css  = '.dbpp{--n:#0f1b2d;--l:#b5e61d;--ls:#eef8d2;--li:#3f6212;--ln:#e4e7ec;--ink:#0f172a;--tx:#374151;--pn:#f1f3f6;display:grid;gap:clamp(34px,5vw,56px);margin:0 0 8px}'
		. '.dbpp h2{margin:0 0 18px;color:var(--ink);font-size:clamp(26px,3.2vw,38px);font-weight:900;line-height:1.1;letter-spacing:-.025em;text-align:left}'
		. '.dbpp p{max-width:none}'
		// Hero art (designer asset), only when the designer plugin is active.
		. 'html body header.dbs2-hero{position:relative;isolation:isolate;overflow:hidden;min-height:300px}'
		. 'html body header.dbs2-hero::after{content:"";position:absolute;z-index:-1;top:50%;right:4%;width:min(40%,420px);aspect-ratio:3/2;background:' . $hero . ' center/contain no-repeat;transform:translateY(-50%);filter:drop-shadow(0 22px 34px rgba(0,0,0,.4));pointer-events:none}'
		. '@media(min-width:768px){html body header.dbs2-hero>*{max-width:56%}}@media(max-width:767px){html body header.dbs2-hero::after{display:none}}'
		// Layout: contents list on the left, sections on the right.
		. 'html body:has(.dbpp),html body:has(.dbpp) #page,html body:has(.dbpp) #content{overflow-x:clip!important;overflow-y:visible!important}'
		. '.dbpp-layout{display:grid;grid-template-columns:260px minmax(0,1fr);gap:clamp(24px,4vw,52px);align-items:start}'
		. '.dbpp-toc{position:sticky;top:96px;max-height:calc(100vh - 120px);overflow:auto;padding:18px 18px 14px;border:1px solid var(--ln);border-radius:18px;background:#fff}'
		. '.dbpp-toc summary{cursor:pointer;list-style:none;margin:0 0 10px;color:#4b5563;font-size:13px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}.dbpp-toc summary::-webkit-details-marker{display:none}'
		. '.dbpp-toc ol{margin:0;padding:0;list-style:none;display:grid;gap:2px}.dbpp-toc li{margin:0}'
		. 'html body .dbpp-toc a{display:block;padding:7px 10px;border-radius:10px;color:var(--tx)!important;font-size:14.5px;line-height:1.4;text-decoration:none!important}'
		. 'html body .dbpp-toc a:hover,html body .dbpp-toc a.is-on{background:var(--ls);color:var(--ink)!important}'
		. '.dbpp-main{display:grid;gap:clamp(30px,4vw,44px);min-width:0}.dbpp-sec{scroll-margin-top:96px}'
		. 'html body .dbpp-sec p{margin:0 0 14px;color:var(--tx);font-size:17px;line-height:1.7}html body .dbpp-sec p.dbpp-small{font-size:14.5px;color:#4b5563}'
		. 'html body .dbpp-sec a{color:var(--li);font-weight:700;text-decoration:underline;text-underline-offset:3px}'
		. '.dbpp-sec strong{color:var(--ink)}'
		. '.dbpp-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:0;padding:0;list-style:none}'
		. '.dbpp-facts li{display:flex;flex-direction:column;gap:6px;margin:0;padding:18px;border:1px solid var(--ln);border-top:5px solid var(--l);border-radius:14px;background:#fff}'
		. '.dbpp-facts strong{font-size:17px;line-height:1.3}.dbpp-facts span{color:var(--tx);font-size:15px;line-height:1.55}'
		. '.dbpp-list{display:grid;gap:10px;margin:0 0 14px;padding:0;list-style:none}.dbpp-list li{position:relative;margin:0;padding-left:30px;color:var(--tx);font-size:17px;line-height:1.65}'
		. '.dbpp-list li::before{content:"\2713";position:absolute;left:0;top:.2em;display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:50%;background:var(--l);color:var(--n);font-size:11px;font-weight:900}'
		. '@media(max-width:1100px){.dbpp-layout{grid-template-columns:minmax(0,1fr)}.dbpp-toc{position:sticky;top:64px;z-index:20;max-height:none;padding:10px 14px;box-shadow:0 8px 22px -14px rgba(12,20,36,.45)}.dbpp-toc details:not([open]) ol{display:none}.dbpp-toc ol{max-height:50vh;overflow:auto}.dbpp-facts{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '@media(max-width:560px){.dbpp-facts{grid-template-columns:minmax(0,1fr)}}'
		// Calculator.
		. '.dbpp-calc{padding:clamp(22px,3.4vw,36px);border:1px solid var(--ln);border-radius:24px;background:var(--pn)}'
		. '.dbpp-calc-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:16px 28px;margin:0 0 22px}'
		. '.dbpp-calc-head h2{margin:0 0 6px}.dbpp-calc-head p{margin:0;color:var(--tx);font-size:17px;line-height:1.5}'
		. '.dbpp-field{display:grid;gap:8px;width:min(100%,340px);color:var(--ink);font-size:14px;font-weight:700}'
		. '.dbpp-input{display:flex;align-items:center;gap:0;box-sizing:border-box;height:52px;width:100%;padding:0 8px 0 20px;border:1.5px solid #d3d9e1;border-radius:999px;background:#fff}'
		. '.dbpp-input:focus-within{border-color:var(--n);box-shadow:0 0 0 3px rgba(181,230,29,.55)}.dbpp-input i{font-style:normal;font-weight:800;color:var(--ink)}'
		. 'html body .dbpp .dbpp-input input{flex:1;width:auto!important;min-width:0;height:46px!important;margin:0!important;padding:0 12px 0 8px!important;border:0!important;border-radius:0!important;background:transparent!important;box-shadow:none!important;outline:none;font-size:18px;font-weight:800;color:var(--ink)}'
		. '.dbpp-plans{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:0 0 16px;padding:0;list-style:none}'
		. '.dbpp-plan{display:flex;flex-direction:column;align-items:flex-start;gap:10px;margin:0;padding:22px 20px;border:1px solid var(--ln);border-top:5px solid var(--l);border-radius:16px;background:#fff;box-shadow:0 1px 2px rgba(16,24,40,.04),0 8px 22px rgba(16,24,40,.06)}'
		. '.dbpp-term{color:var(--ink);font-size:17px;font-weight:800}'
		. '.dbpp-pay{display:flex;flex-direction:column;color:var(--ink);font-size:clamp(26px,2.6vw,34px);font-weight:900;line-height:1.05;letter-spacing:-.02em}.dbpp-pay small{margin-top:4px;color:var(--tx);font-size:14px;font-weight:600;letter-spacing:0}'
		. '.dbpp-badge{display:inline-flex;padding:5px 12px;border-radius:999px;background:var(--ls);color:var(--li);font-size:12.5px;font-weight:800}.dbpp-badge--rate{background:#eceef2;color:var(--tx)}'
		. '.dbpp-total{margin-top:auto;color:var(--tx);font-size:14.5px}.dbpp-total b{color:var(--ink)}'
		. '.dbpp-note,.dbpp-small{margin:0;color:#4b5563;font-size:14.5px;line-height:1.55}.dbpp-small a{color:var(--li);font-weight:700}'
		// Steps (designer lime number badges).
		. '.dbpp-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:0;padding:0;list-style:none}'
		. '.dbpp-steps li{display:flex;flex-direction:column;gap:8px;margin:0;padding:22px 20px;border:1px solid var(--ln);border-radius:16px;background:#fff}'
		. '.dbpp-n{display:inline-flex;align-items:center;justify-content:center;align-self:flex-start;min-width:38px;height:30px;padding:0 8px;border-radius:7px;background:var(--l);color:var(--n);font-size:15px;font-weight:800}'
		. '.dbpp-steps strong{color:var(--ink);font-size:18px;line-height:1.3}.dbpp-steps li>span:last-child{color:var(--tx);font-size:15.5px;line-height:1.55}'
		// Table.
		. '.dbpp-table-wrap{overflow-x:auto;border:1px solid var(--ln);border-radius:16px}.dbpp-table{width:100%;min-width:560px;margin:0;border-collapse:collapse;background:#fff}'
		. '.dbpp-table th,.dbpp-table td{padding:16px 20px;border-bottom:1px solid var(--ln);text-align:left;font-size:16px;line-height:1.45;color:var(--tx)}.dbpp-table tr:last-child>*{border-bottom:0}'
		. '.dbpp-table thead th{background:var(--n);color:#fff;font-weight:800}.dbpp-table tbody th{color:var(--ink);font-weight:800;background:var(--pn);width:24%}'
		// Terms.
		. '.dbpp-terms{padding:clamp(22px,3.4vw,36px);border-radius:24px;background:var(--n);color:#fff}'
		. 'html body .dbpp .dbpp-terms h2{color:#fff!important}'
		. 'html body .dbpp .dbpp-terms p{margin:0 0 12px;color:rgba(255,255,255,.9)!important;font-size:17px;line-height:1.7}html body .dbpp .dbpp-terms strong{color:#c8f03a!important}html body .dbpp .dbpp-terms p.dbpp-small{color:rgba(255,255,255,.72)!important}'
		// FAQ.
		. '.dbpp-faq{display:grid;gap:10px;margin:0 0 16px}.dbpp-faq details{border:1px solid var(--ln);border-radius:14px;background:#fff}'
		. '.dbpp-faq summary{cursor:pointer;padding:18px 22px;color:var(--ink);font-size:17px;font-weight:800;list-style:none;display:flex;justify-content:space-between;gap:16px}.dbpp-faq summary::-webkit-details-marker{display:none}'
		. '.dbpp-faq summary::after{content:"+";font-size:22px;line-height:1;color:var(--li)}.dbpp-faq details[open] summary::after{content:"\2212"}'
		. '.dbpp-faq details p{margin:0;padding:0 22px 20px;color:var(--tx);font-size:16.5px;line-height:1.65}'
		. '.dbpp-faq summary:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(181,230,29,.55);border-radius:14px}'
		// Lime call to action (designer).
		. '.dbpp-cta{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px 24px;padding:20px 26px 20px 32px;border-radius:20px;background:#c8f03a}'
		. '.dbpp-cta h2{margin:0;font-size:clamp(24px,2.6vw,32px)}'
		. 'html body a.dbpp-cta-btn{display:inline-flex;align-items:center;gap:10px;min-height:52px;padding:0 32px;border-radius:999px;background:var(--n);color:#fff!important;font-size:17px;font-weight:800;text-decoration:none;box-shadow:0 6px 16px rgba(15,27,45,.18)}'
		. 'html body a.dbpp-cta-btn::after{content:"\2192";font-size:20px;font-weight:400}html body a.dbpp-cta-btn:hover{background:#1c2c46;color:#fff}'
		. 'html body a.dbpp-cta-btn:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(15,27,45,.35)}'
		. '@media(max-width:991px){.dbpp-plans,.dbpp-steps{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '@media(max-width:560px){.dbpp-plans,.dbpp-steps{grid-template-columns:minmax(0,1fr)}.dbpp-calc-head{align-items:stretch}}';
	if ( function_exists( 'db_external_style' ) && db_external_style( 'payment-plans', $css ) ) {
		return;
	}
	wp_register_style( 'db-payment-plans', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-payment-plans' );
	wp_add_inline_style( 'db-payment-plans', $css );
}, 1002 );

add_action( 'wp_footer', function () {
	if ( ! db_pp_is_route() ) {
		return;
	}
	// Recalculates the four cards from the typed price. The cards already show
	// the figures for the default price, so nothing depends on this running early.
	?>
<script data-no-delay>
(function () {
	var input = document.getElementById('dbpp-price');
	var cards = document.querySelectorAll('.dbpp-plan');
	if (!input || !cards.length) { return; }
	var fmt = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', minimumFractionDigits: 2, maximumFractionDigits: 2 });
	function update() {
		var price = parseFloat(input.value);
		var ok = isFinite(price) && price >= 100 && price <= 1000000;
		input.setAttribute('aria-invalid', ok ? 'false' : 'true');
		if (!ok) { return; }
		cards.forEach(function (card) {
			var months = parseInt(card.getAttribute('data-months'), 10);
			var rate = parseFloat(card.getAttribute('data-rate')) || 0;
			var total = Math.round(price * (100 + rate)) / 100;
			card.querySelector('[data-total]').textContent = fmt.format(total);
			card.querySelector('[data-each]').textContent = fmt.format(Math.round(total / months * 100) / 100);
		});
	}
	input.addEventListener('input', update);
	input.addEventListener('change', update);
	if ('IntersectionObserver' in window) {
		var links = {}; [].forEach.call(document.querySelectorAll('.dbpp-toc a'), function (a) { links[a.getAttribute('href').slice(1)] = a; });
		var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting && links[e.target.id]) { [].forEach.call(document.querySelectorAll('.dbpp-toc a.is-on'), function (x) { x.classList.remove('is-on'); }); links[e.target.id].classList.add('is-on'); } }); }, { rootMargin: '-15% 0px -75% 0px' });
		[].forEach.call(document.querySelectorAll('.dbpp-sec[id]'), function (s) { io.observe(s); });
	}
}());
</script>
	<?php
}, 99 );
