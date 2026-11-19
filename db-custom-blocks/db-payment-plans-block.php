<?php
/**
 * Payment plans page at /payment-plans/.
 *
 * The menus used to send "Payment plans" to the FAQs, and the other link
 * (/payment-plan-setup/) is the checkout step for one chosen domain, which
 * redirects to All Domains when opened without a domain. This page explains
 * the plans in one place: a calculator, how a plan works, the terms in full and
 * the questions people ask first.
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
	/** Whether the request is /payment-plans/. */
	function db_pp_is_route() {
		return function_exists( 'db_sales_request_path' ) && 'payment-plans' === db_sales_request_path();
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

if ( ! function_exists( 'db_pp_body' ) ) {
	/** The page body (the first .db-sales-lead paragraph becomes the hero text). */
	function db_pp_body() {
		$default = 5000;
		$terms   = db_pp_terms();
		$free    = array();
		$paid    = array();
		foreach ( $terms as $m ) {
			if ( db_pp_rate( $m ) > 0 ) {
				$paid[] = $m;
			} else {
				$free[] = $m;
			}
		}
		$free_max = $free ? max( $free ) : 0;
		$browse   = esc_url( home_url( '/all-domains/' ) );

		$lead = 'Spread the cost of a premium domain over ' . esc_html( implode( ', ', array_slice( $terms, 0, -1 ) ) . ' or ' . end( $terms ) ) . ' months.'
			. ( $free_max ? ' Plans of up to ' . (int) $free_max . ' months carry no interest.' : '' )
			. ' Every domain with a listed price can be bought on a plan.';

		$h  = '<p class="db-sales-lead">' . $lead . '</p><div class="dbpp">';

		// 1. Calculator: the main task of the page, so it comes first.
		$h .= '<section class="dbpp-calc" aria-labelledby="dbpp-calc-h"><div class="dbpp-calc-head"><h2 id="dbpp-calc-h">See what a plan costs</h2>'
			. '<p>Enter a domain price to compare every plan. The figures update as you type.</p></div>'
			. '<label class="dbpp-field" for="dbpp-price"><span>Domain price (USD)</span><span class="dbpp-input"><i aria-hidden="true">$</i>'
			. '<input id="dbpp-price" type="number" inputmode="numeric" min="100" max="1000000" step="50" value="' . (int) $default . '" aria-describedby="dbpp-note"></span></label>'
			. '<ol class="dbpp-plans" aria-live="polite">';
		foreach ( $terms as $m ) {
			$h .= db_pp_plan_card( $m, $default );
		}
		$h .= '</ol><p class="dbpp-note" id="dbpp-note">Shown for a ' . esc_html( db_pp_money( $default ) ) . ' domain until you change the price. The total is the listed price plus the plan\'s interest. Payments are in US dollars by card.</p></section>';

		// 2. How a plan works.
		$steps = array(
			array( 'Pick a name', 'Any domain with a listed price can be bought on a plan. Open it and choose the payment plan option.' ),
			array( 'Choose the length', 'Pick ' . implode( ', ', array_slice( $terms, 0, -1 ) ) . ' or ' . end( $terms ) . ' months. You see the full schedule and the plan terms before you accept.' ),
			array( 'Pay the first instalment', 'The first payment is taken by card through Stripe. Your acceptance of the terms is recorded with the plan.' ),
			array( 'Pay monthly, then it is yours', 'Each later payment is charged automatically to the card on file. The domain transfers to you after the final payment.' ),
		);
		$h .= '<section class="dbpp-sec" aria-labelledby="dbpp-how-h"><h2 id="dbpp-how-h">How a payment plan works</h2><ol class="dbpp-steps">';
		foreach ( $steps as $i => $s ) {
			$h .= '<li><span class="dbpp-n" aria-hidden="true">' . sprintf( '%02d', $i + 1 ) . '</span><strong>' . esc_html( $s[0] ) . '</strong><span>' . esc_html( $s[1] ) . '</span></li>';
		}
		$h .= '</ol></section>';

		// 3. Plan or pay in full.
		$h .= '<section class="dbpp-sec" aria-labelledby="dbpp-cmp-h"><h2 id="dbpp-cmp-h">Pay in full or pay over time</h2><div class="dbpp-table-wrap"><table class="dbpp-table"><thead><tr><th scope="col"></th><th scope="col">Pay in full</th><th scope="col">Payment plan</th></tr></thead><tbody>'
			. '<tr><th scope="row">Total cost</th><td>The listed price</td><td>The listed price' . ( $paid ? ', plus interest on plans of ' . esc_html( implode( ' and ', array_map( 'intval', $paid ) ) ) . ' months' : '' ) . '</td></tr>'
			. '<tr><th scope="row">Cash needed today</th><td>The full price</td><td>The first instalment</td></tr>'
			. '<tr><th scope="row">Ownership</th><td>Transfers once payment clears</td><td>Transfers after the final payment</td></tr>'
			. '<tr><th scope="row">Best for</th><td>A clear budget or an urgent transfer</td><td>Spreading the cost of a larger price</td></tr>'
			. '</tbody></table></div></section>';

		// 4. Terms in full, from the same function the checkout and emails use.
		$terms_html = function_exists( 'db_plan_terms_html' ) ? db_plan_terms_html() : '';
		if ( '' !== $terms_html ) {
			$h .= '<section class="dbpp-sec dbpp-terms" aria-labelledby="dbpp-terms-h"><h2 id="dbpp-terms-h">The terms, in full</h2><p>' . wp_kses( $terms_html, array( 'strong' => array() ) ) . '</p>'
				. '<p class="dbpp-small">The same wording is shown before you accept a plan and repeated in the email we send when it starts.</p></section>';
		}

		// 5. Questions.
		$retries = defined( 'DB_PLAN_RETRY_DAYS' ) ? count( (array) DB_PLAN_RETRY_DAYS ) : 0;
		$grace   = defined( 'DB_PLAN_GRACE_DAYS' ) ? (int) DB_PLAN_GRACE_DAYS : 0;
		$rate_ln = array();
		foreach ( $terms as $m ) {
			$r         = db_pp_rate( $m );
			$rate_ln[] = (int) $m . ' months: ' . ( $r > 0 ? rtrim( rtrim( number_format( $r, 1 ), '0' ), '.' ) . '% interest' : 'no interest' );
		}
		$faq = array(
			array( 'Which domains can I buy on a plan?', 'Any domain that shows a price. Names marked "Open to offers" have no listed price yet: send an offer and a broker replies within one business day.' ),
			array( 'How much does a longer plan cost?', implode( '; ', $rate_ln ) . '. The calculator above shows the monthly payment and total for any price.' ),
			array( 'Who owns the domain during the plan?', 'The domain stays with Domain Brothers until your final payment clears. Ownership transfers to you only then.' ),
			array( 'What happens if a payment is declined?', $retries && $grace
				? 'We email you and retry automatically ' . $retries . ' more times. If the payment is still unpaid ' . $grace . ' days after the first decline, the plan ends, the domain returns to our inventory, and payments already made are not refunded.'
				: 'We email you and retry automatically. If the payment stays unpaid, the plan ends and the domain returns to our inventory.' ),
			array( 'How do I pay?', 'By card, through Stripe. Domain Brothers does not receive or store your card number.' ),
		);
		$h .= '<section class="dbpp-sec" aria-labelledby="dbpp-faq-h"><h2 id="dbpp-faq-h">Questions people ask first</h2><div class="dbpp-faq">';
		foreach ( $faq as $q ) {
			$h .= '<details><summary>' . esc_html( $q[0] ) . '</summary><p>' . esc_html( $q[1] ) . '</p></details>';
		}
		$h .= '</div><p class="dbpp-small">More detail in <a href="' . esc_url( home_url( '/premium-domain-payment-plans-and-financing/' ) ) . '">Domain financing explained</a> and our <a href="' . esc_url( home_url( '/faqs/' ) ) . '">FAQs</a>.</p></section>';

		// 6. Next step.
		$h .= '<section class="dbpp-cta" aria-label="Browse domains"><h2>Ready to choose a name?</h2><a class="dbpp-cta-btn" href="' . $browse . '">Browse all domains</a></section></div>';
		return $h;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! db_pp_is_route() || ! function_exists( 'db_sales_render' ) ) {
		return;
	}
	db_sales_render( 'Payment plans', 'Payment plans', db_pp_body() );
}, 10 );

if ( ! function_exists( 'db_pp_meta' ) ) {
	/** @return array{0:string,1:string} Title and description. */
	function db_pp_meta() {
		return array(
			'Domain Payment Plans: Pay Over 3 to 12 Months | Domain Brothers',
			'Pay for a premium domain in 3, 6, 9 or 12 monthly instalments. No interest up to 6 months. See the cost at any price, how a plan works and the terms in full.',
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
		. 'html body a.dbpp-cta-btn{display:inline-flex;align-items:center;gap:10px;min-height:52px;padding:0 32px;border-radius:999px;background:var(--n);color:#fff;font-size:17px;font-weight:800;text-decoration:none;box-shadow:0 6px 16px rgba(15,27,45,.18)}'
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
}());
</script>
	<?php
}, 99 );
