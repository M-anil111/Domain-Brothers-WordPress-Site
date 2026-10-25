<?php
/**
 * DB Page Curtain Block — branded transition overlay
 *
 * Moving between pages replays a short navy curtain with the Domain Brothers
 * logo, so internal navigation feels like one continuous branded transition
 * instead of a white flash.
 *
 * IMPORTANT — the curtain deliberately does NOT play on a cold entry (the
 * first page of a visit, which is the only load Lighthouse measures). It is
 * injected at wp_footer, i.e. after the browser has already painted the
 * hero, so on a cold load it covered content the visitor was looking at and
 * held the viewport at 0% visual completeness for over a second. That is
 * measured directly by Speed Index and was costing PageSpeed points for an
 * effect nobody had asked for on a first impression. A sessionStorage flag
 * set at click time means the curtain only ever appears where it was
 * actually wanted: between pages.
 *
 * Respects prefers-reduced-motion, has hard timeout fallbacks, and handles
 * bfcache (back/forward) so it can never get stuck covering the page.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_CURTAIN_LOGO' ) ) {
	define( 'DB_CURTAIN_LOGO', '' ); // empty => default DB logo
}

add_action( 'wp_footer', function () {
	if ( is_admin() || is_customize_preview() ) {
		return;
	}
	// Never sit in front of a payment or its confirmation. The Stripe
	// checkout renders its own document and never calls wp_footer, but
	// /thank-you/ is a normal WordPress page that buyers are redirected to
	// straight from a successful charge — the one moment on this site where
	// a delay reads as "did my payment work?".
	if ( is_page( array( 'buy-now', 'checkout', 'thank-you', 'thankyou', 'payment-successful' ) ) ) {
		return;
	}
	$logo = DB_CURTAIN_LOGO ? DB_CURTAIN_LOGO : ( function_exists( 'db_brand_logo_url' ) ? db_brand_logo_url() : home_url( '/wp-content/uploads/2017/03/Domain-Brothers.png' ) );
	?>
	<style>
	#db-curtain {
		position: fixed; inset: 0; z-index: 2147483000; pointer-events: none;
		background: linear-gradient(160deg, #0a1628 0%, #08173a 55%, #132b52 100%);
		display: flex; align-items: center; justify-content: center;
	}
	#db-curtain.is-revealing { animation: dbCurtainUp .5s cubic-bezier(.65,0,.35,1) .15s forwards; }
	#db-curtain.is-leaving   { display: flex; animation: dbCurtainDown .34s cubic-bezier(.65,0,.35,1) forwards; }
	#db-curtain.is-done      { display: none; }
	#db-curtain .db-curtain-logo {
		width: min(52vw, 280px); height: auto; opacity: 0; transform: scale(.82);
		filter: drop-shadow(0 0 34px rgba(79,156,249,.45));
		animation: dbCurtainBeat .55s cubic-bezier(.3,.6,.35,1) forwards;
	}
	#db-curtain.is-leaving .db-curtain-logo { animation: dbCurtainLogoOut .28s ease-out forwards; }
	@keyframes dbCurtainBeat {
		0%   { opacity: 0; transform: scale(.82); }
		55%  { opacity: 1; transform: scale(1.06); }
		75%  { transform: scale(.97); }
		100% { opacity: 1; transform: scale(1); }
	}
	@keyframes dbCurtainLogoOut { to { opacity: 0; transform: scale(.9); } }
	@keyframes dbCurtainUp   { to { transform: translateY(-100%); } }
	@keyframes dbCurtainDown { from { transform: translateY(-100%); } to { transform: translateY(0); } }
	@media (prefers-reduced-motion: reduce) {
		#db-curtain, #db-curtain .db-curtain-logo { animation: none !important; display: none !important; }
	}
	</style>
	<script>
	(function () {
		'use strict';
		if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
		var LOGO = <?php echo wp_json_encode( esc_url_raw( $logo ) ); ?>;
		var FLAG = 'dbCurtainNav';
		var curtain = null, img = null, navTimer = null, watchdog = null;

		function store(op, val) {
			// sessionStorage throws in some privacy modes; the curtain is
			// decoration, so degrade to "never play" rather than break links.
			try {
				if (op === 'get') { return window.sessionStorage.getItem(FLAG); }
				if (op === 'set') { window.sessionStorage.setItem(FLAG, '1'); }
				if (op === 'del') { window.sessionStorage.removeItem(FLAG); }
			} catch (e) {}
			return null;
		}

		function build() {
			if (curtain) { return curtain; }
			curtain = document.createElement('div');
			curtain.id = 'db-curtain';
			img = document.createElement('img');
			img.className = 'db-curtain-logo';
			img.src = LOGO; img.alt = '';
			curtain.appendChild(img);
			document.documentElement.appendChild(curtain);
			curtain.addEventListener('animationend', function (e) {
				if (e.target === curtain && curtain.classList.contains('is-revealing')) { done(); }
			});
			return curtain;
		}
		function done() { if (curtain) { curtain.classList.add('is-done'); } }
		function reveal() {
			if (!curtain || curtain.classList.contains('is-revealing')) { return; }
			requestAnimationFrame(function () { curtain.classList.add('is-revealing'); });
		}

		// Arriving from an internal click: play the reveal. A cold entry
		// builds nothing at all, so there is no overlay and no paint cost.
		if (store('get') === '1') {
			store('del');
			build();
			if (img.complete && img.naturalWidth) { reveal(); }
			else {
				img.addEventListener('load', reveal);
				img.addEventListener('error', reveal);
				setTimeout(reveal, 350); // don't wait on a slow logo
			}
			setTimeout(done, 1600); // safety net
		}

		window.addEventListener('pageshow', function (e) {
			if (e.persisted && curtain) {
				clearTimeout(navTimer); clearTimeout(watchdog);
				curtain.classList.remove('is-leaving');
				curtain.classList.add('is-revealing', 'is-done');
			}
		});

		document.addEventListener('click', function (e) {
			if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) { return; }
			var a = e.target.closest && e.target.closest('a[href]');
			if (!a) { return; }
			if (a.target && a.target !== '_self') { return; }
			if (a.hasAttribute('download')) { return; }
			var href = a.getAttribute('href');
			if (!href || href.charAt(0) === '#') { return; }
			if (/^(tel:|mailto:|javascript:)/i.test(href)) { return; }
			var url;
			try { url = new URL(href, window.location.href); } catch (err) { return; }
			if (url.origin !== window.location.origin) { return; }
			// Compare everything EXCEPT the hash. A link like /faqs/#pricing
			// clicked while already on /faqs/ has a different href but does
			// not navigate — it only moves the fragment. Treating it as a
			// navigation dropped the curtain over a page that then never
			// reloaded, leaving the viewport opaque for the rest of the visit.
			if (url.pathname === window.location.pathname && url.search === window.location.search) { return; }

			e.preventDefault();
			store('set');
			build();
			curtain.classList.remove('is-revealing', 'is-done');
			curtain.classList.add('is-leaving');
			navTimer = setTimeout(function () { window.location.href = url.href; }, 380);
			// Watchdog: if the navigation never happens — a cancelled
			// beforeunload prompt, a Content-Disposition: attachment
			// response, a 204, or another handler that took over the click —
			// nothing else would ever lift the curtain.
			watchdog = setTimeout(function () {
				store('del');
				curtain.classList.remove('is-leaving');
				curtain.classList.add('is-done');
			}, 4000);
		});
	})();
	</script>
	<?php
}, 101 );
