<?php
/**
 * DB Page Curtain Block — branded transition overlay
 *
 * On first load: a full-screen navy overlay with the Domain Brothers logo
 * "heartbeats" in, then the curtain wipes up to reveal the page. On internal
 * link clicks: replays a short curtain, then navigates — so moving between
 * pages feels like one continuous branded transition instead of a white
 * flash. Respects prefers-reduced-motion, has a hard timeout fallback, and
 * handles bfcache (back/forward) so it can never get stuck covering the page.
 *
 * Adapted from the supplied "page curtain" effect, branded to Domain
 * Brothers (navy gradient + blue glow + DB logo). Skipped for admins /
 * wp-admin and on the checkout page (never delay a payment).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_CURTAIN_LOGO' ) ) {
	define( 'DB_CURTAIN_LOGO', '' ); // empty => default DB logo
}

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	// Never sit in front of the Stripe checkout.
	if ( is_page( 'buy-now' ) ) {
		return;
	}
	$logo = DB_CURTAIN_LOGO ? DB_CURTAIN_LOGO : home_url( '/wp-content/uploads/2017/03/Domain-Brothers.png' );
	?>
	<style>
	#db-curtain {
		position: fixed; inset: 0; z-index: 2147483000; pointer-events: none;
		background: linear-gradient(160deg, #0a1628 0%, #08173a 55%, #132b52 100%);
		display: flex; align-items: center; justify-content: center;
	}
	#db-curtain.is-revealing { animation: dbCurtainUp .55s cubic-bezier(.65,0,.35,1) .7s forwards; }
	#db-curtain.is-leaving   { display: flex; animation: dbCurtainDown .4s cubic-bezier(.65,0,.35,1) forwards; }
	#db-curtain.is-done      { display: none; }
	#db-curtain .db-curtain-logo {
		width: min(52vw, 280px); height: auto; opacity: 0; transform: scale(.82);
		filter: drop-shadow(0 0 34px rgba(79,156,249,.45));
		animation: dbCurtainBeat .9s cubic-bezier(.3,.6,.35,1) forwards;
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
		var LOGO = <?php echo wp_json_encode( esc_url( $logo ) ); ?>;
		var curtain = document.createElement('div');
		curtain.id = 'db-curtain';
		var img = document.createElement('img');
		img.className = 'db-curtain-logo';
		img.src = LOGO; img.alt = '';
		curtain.appendChild(img);
		document.documentElement.appendChild(curtain);

		function done() { curtain.classList.add('is-done'); }
		function reveal() {
			if (curtain.classList.contains('is-revealing')) { return; }
			requestAnimationFrame(function () { curtain.classList.add('is-revealing'); });
		}
		// Wait for the logo to actually load before wiping the curtain away,
		// so the Domain Brothers logo is always seen (not an empty flash).
		if (img.complete && img.naturalWidth) { reveal(); }
		else {
			img.addEventListener('load', reveal);
			img.addEventListener('error', reveal);
			setTimeout(reveal, 900); // don't wait forever on a slow logo
		}
		curtain.addEventListener('animationend', function (e) {
			if (e.target === curtain && curtain.classList.contains('is-revealing')) { done(); }
		});
		setTimeout(done, 2600); // safety net

		window.addEventListener('pageshow', function (e) {
			if (e.persisted) { curtain.classList.remove('is-leaving'); curtain.classList.add('is-revealing', 'is-done'); }
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
			if (url.href === window.location.href) { return; }
			e.preventDefault();
			curtain.classList.remove('is-revealing', 'is-done');
			curtain.classList.add('is-leaving');
			setTimeout(function () { window.location.href = url.href; }, 450);
		});
	})();
	</script>
	<?php
}, 101 );
