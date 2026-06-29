<?php
/* === DB fix payment-plan term selection (v3) ===
 *
 * Issue: "monthly payment terms don't show up correctly" + dated/mobile design.
 *
 * This is a clean, SELF-CONTAINED replacement for the older planterm block
 * (db_planterm_block_v2 / marker "DB fix payment-plan term selection"). It does
 * NOT depend on the theme's own radios/markup — it renders its own modern,
 * mobile-responsive plan widget on the payment-plan-setup page, so it can't get
 * out of sync with theme DOM that changed.
 *
 * DEPLOY: in functions.php, DELETE the old block delimited by
 *   /* === DB fix payment-plan term selection === * /  ...  /* === end ... === * /
 * then paste THIS block at the end. (If you can't find the old one, paste this
 * anyway and set DB_PLAN_HIDE_THEME_WIDGET below to hide a duplicate.)
 *
 * Behaviour:
 *   - Reads domain (d) and price (p) from the URL, both base64-encoded, exactly
 *     like the checkout entry documented in HANDOFF.md.
 *   - Zero interest. monthly = total / months, with the FINAL payment absorbing
 *     any rounding remainder so the installments sum to the exact total.
 *   - Terms: 3, 6, 9, 12 months. Live per-term pricing + exact-date schedule.
 *   - First payment today; each later payment one calendar month later.
 *   - Transfer-after-final-payment + interim IP/MX messaging.
 *   - "Proceed" routes to the checkout the existing block expects:
 *       /buy-now/?d=<d>&p=<p>&m=<months>&t=plan
 *
 * CONFIG below: page slug, available terms, currency symbol.
 */

if ( ! defined( 'DB_PLAN_PAGE_SLUG' ) ) {
	define( 'DB_PLAN_PAGE_SLUG', 'payment-plan-setup' );
}
if ( ! defined( 'DB_PLAN_CURRENCY' ) ) {
	define( 'DB_PLAN_CURRENCY', '$' );
}
if ( ! defined( 'DB_PLAN_HIDE_THEME_WIDGET' ) ) {
	define( 'DB_PLAN_HIDE_THEME_WIDGET', false ); // set true if the theme shows its own plan box
}

/* Available terms in months. */
function db_plan_terms() {
	return array( 3, 6, 9, 12 );
}

/* Is this the payment-plan-setup page? (slug match OR the d/p params present). */
function db_is_plan_page() {
	if ( is_page( DB_PLAN_PAGE_SLUG ) ) {
		return true;
	}
	// Fallback: any page that received both checkout params.
	return ( isset( $_GET['d'], $_GET['p'] ) && is_page() );
}

/* Safe base64 decode of a URL param. */
function db_plan_b64( $key ) {
	if ( empty( $_GET[ $key ] ) ) {
		return '';
	}
	$raw = base64_decode( wp_unslash( $_GET[ $key ] ), true );
	return ( false === $raw ) ? '' : $raw;
}

/* Render the widget by appending to the page content. */
add_filter( 'the_content', function ( $content ) {
	if ( is_admin() || ! in_the_loop() || ! is_main_query() || ! db_is_plan_page() ) {
		return $content;
	}

	$domain    = sanitize_text_field( db_plan_b64( 'd' ) );
	$price_raw = db_plan_b64( 'p' );
	// Keep digits and a single decimal point only.
	$price = (float) preg_replace( '/[^0-9.]/', '', $price_raw );

	if ( $price <= 0 ) {
		return $content; // nothing to price; leave the page as-is
	}

	$terms = db_plan_terms();
	$cur   = DB_PLAN_CURRENCY;
	$d_b64 = isset( $_GET['d'] ) ? sanitize_text_field( wp_unslash( $_GET['d'] ) ) : '';
	$p_b64 = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : '';

	ob_start();
	?>
	<div class="db-plan" id="db-plan"
		data-price="<?php echo esc_attr( $price ); ?>"
		data-terms="<?php echo esc_attr( implode( ',', $terms ) ); ?>"
		data-domain="<?php echo esc_attr( $domain ); ?>"
		data-d="<?php echo esc_attr( $d_b64 ); ?>"
		data-p="<?php echo esc_attr( $p_b64 ); ?>"
		data-cur="<?php echo esc_attr( $cur ); ?>">

		<div class="db-plan-head">
			<h2>Choose your payment plan<?php echo $domain ? ' for <span class="db-plan-domain">' . esc_html( $domain ) . '</span>' : ''; ?></h2>
			<p class="db-plan-total">Total price: <strong><?php echo esc_html( $cur . number_format( $price, 2 ) ); ?></strong> &middot; <span class="db-plan-zero">0% interest</span></p>
		</div>

		<div class="db-plan-terms" role="tablist" aria-label="Payment terms"></div>

		<div class="db-plan-summary">
			<div class="db-plan-monthly">
				<span class="db-plan-monthly-label">Monthly payment</span>
				<span class="db-plan-monthly-amount" id="db-plan-monthly">&mdash;</span>
				<span class="db-plan-monthly-sub" id="db-plan-monthly-sub"></span>
			</div>
			<a href="#" class="db-plan-cta" id="db-plan-cta">Continue to secure checkout &rarr;</a>
		</div>

		<div class="db-plan-schedule-wrap">
			<h3>Payment schedule</h3>
			<table class="db-plan-schedule">
				<thead><tr><th>#</th><th>Date</th><th>Amount</th></tr></thead>
				<tbody id="db-plan-schedule-body"></tbody>
			</table>
		</div>

		<div class="db-plan-notes">
			<p><strong>When do I get the domain?</strong> Ownership transfers to you once the <em>final</em> payment clears. Until then the domain is held securely in escrow on your behalf.</p>
			<p><strong>Can I use it in the meantime?</strong> Yes &mdash; during the plan we can point the domain to your hosting (A record / IP) and email (MX records) so you can start using it right away, while ownership transfers at the end.</p>
		</div>
	</div>

	<style>
		.db-plan{max-width:760px;margin:32px auto;padding:0 4px;font-family:inherit;color:#1a1a1a;}
		.db-plan-head h2{font-size:clamp(20px,4vw,28px);line-height:1.25;margin:0 0 8px;font-weight:700;}
		.db-plan-domain{color:#0a6ed1;}
		.db-plan-total{font-size:15px;color:#444;margin:0 0 20px;}
		.db-plan-zero{display:inline-block;background:#e7f6ec;color:#137a3e;font-weight:600;padding:2px 10px;border-radius:999px;font-size:13px;}
		.db-plan-terms{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin:0 0 24px;}
		.db-plan-term{cursor:pointer;border:1.5px solid #e2e2e2;border-radius:12px;padding:16px 12px;text-align:center;background:#fff;transition:border-color .15s,box-shadow .15s,transform .05s;}
		.db-plan-term:hover{border-color:#9cc6f0;}
		.db-plan-term.is-active{border-color:#0a6ed1;box-shadow:0 0 0 3px rgba(10,110,209,.12);}
		.db-plan-term .t-months{display:block;font-size:18px;font-weight:700;}
		.db-plan-term .t-per{display:block;font-size:13px;color:#666;margin-top:4px;}
		.db-plan-summary{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;background:#f7f9fc;border:1px solid #e6ecf5;border-radius:14px;padding:20px 22px;margin:0 0 28px;}
		.db-plan-monthly{display:flex;flex-direction:column;}
		.db-plan-monthly-label{font-size:13px;color:#666;text-transform:uppercase;letter-spacing:.04em;}
		.db-plan-monthly-amount{font-size:clamp(26px,6vw,34px);font-weight:800;line-height:1.1;}
		.db-plan-monthly-sub{font-size:13px;color:#666;margin-top:2px;}
		.db-plan-cta{display:inline-block;background:#0a6ed1;color:#fff;font-weight:600;text-decoration:none;padding:14px 22px;border-radius:10px;white-space:nowrap;transition:background .15s;}
		.db-plan-cta:hover{background:#085bb0;color:#fff;}
		.db-plan-schedule-wrap h3{font-size:17px;margin:0 0 10px;}
		.db-plan-schedule{width:100%;border-collapse:collapse;font-size:14px;}
		.db-plan-schedule th,.db-plan-schedule td{text-align:left;padding:10px 12px;border-bottom:1px solid #eee;}
		.db-plan-schedule th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#888;}
		.db-plan-schedule td:last-child,.db-plan-schedule th:last-child{text-align:right;}
		.db-plan-notes{margin-top:24px;font-size:14px;color:#444;line-height:1.55;}
		.db-plan-notes p{margin:0 0 10px;}
		@media(max-width:560px){
			.db-plan-summary{flex-direction:column;align-items:stretch;}
			.db-plan-cta{text-align:center;}
		}
	</style>

	<script>
	(function(){
		var root = document.getElementById('db-plan');
		if(!root) return;
		var price  = parseFloat(root.getAttribute('data-price')) || 0;
		var terms  = (root.getAttribute('data-terms')||'').split(',').map(function(n){return parseInt(n,10);}).filter(Boolean);
		var cur    = root.getAttribute('data-cur') || '$';
		var d      = root.getAttribute('data-d') || '';
		var p      = root.getAttribute('data-p') || '';
		var active = terms[0];

		function money(n){ return cur + n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }

		// Installments: equal monthly, final payment absorbs the rounding remainder.
		function installments(total, months){
			var monthly = Math.round((total/months)*100)/100;
			var arr = [];
			var sum = 0;
			for(var i=0;i<months;i++){
				if(i === months-1){ arr.push(Math.round((total - sum)*100)/100); }
				else { arr.push(monthly); sum += monthly; }
			}
			return arr;
		}

		function addMonths(date, m){
			var dt = new Date(date.getTime());
			var day = dt.getDate();
			dt.setMonth(dt.getMonth()+m);
			// Guard month overflow (e.g. Jan 31 + 1 month).
			if(dt.getDate() < day){ dt.setDate(0); }
			return dt;
		}

		function fmtDate(dt){
			return dt.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'});
		}

		function renderTerms(){
			var wrap = root.querySelector('.db-plan-terms');
			wrap.innerHTML = '';
			terms.forEach(function(m){
				var per = installments(price,m)[0];
				var b = document.createElement('button');
				b.type='button';
				b.className='db-plan-term'+(m===active?' is-active':'');
				b.setAttribute('role','tab');
				b.setAttribute('aria-selected', m===active?'true':'false');
				b.innerHTML = '<span class="t-months">'+m+' months</span><span class="t-per">'+money(per)+'/mo</span>';
				b.addEventListener('click', function(){ active=m; renderTerms(); renderSummary(); });
				wrap.appendChild(b);
			});
		}

		function renderSummary(){
			var arr = installments(price, active);
			document.getElementById('db-plan-monthly').textContent = money(arr[0]);
			document.getElementById('db-plan-monthly-sub').textContent = active + ' payments, 0% interest';

			var body = document.getElementById('db-plan-schedule-body');
			body.innerHTML = '';
			var today = new Date();
			arr.forEach(function(amt,i){
				var tr = document.createElement('tr');
				var dt = i===0 ? today : addMonths(today, i);
				tr.innerHTML = '<td>'+(i+1)+'</td><td>'+fmtDate(dt)+(i===0?' (today)':'')+'</td><td>'+money(amt)+'</td>';
				body.appendChild(tr);
			});

			var cta = document.getElementById('db-plan-cta');
			var url = '/buy-now/?t=plan&m='+encodeURIComponent(active);
			if(d) url += '&d='+encodeURIComponent(d);
			if(p) url += '&p='+encodeURIComponent(p);
			cta.setAttribute('href', url);
		}

		renderTerms();
		renderSummary();
	})();
	</script>
	<?php
	$widget = ob_get_clean();

	return $content . $widget;
} );

/* Optionally hide a duplicate plan widget the theme might still print. */
add_action( 'wp_head', function () {
	if ( DB_PLAN_HIDE_THEME_WIDGET && db_is_plan_page() ) {
		echo "<style>.theme-payment-plan,.payment-plan-default{display:none!important;}</style>\n";
	}
} );
/* === end DB fix payment-plan term selection (v3) === */
