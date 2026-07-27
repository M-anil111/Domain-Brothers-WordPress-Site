<?php
/**
 * DB Nav Block — site-wide hamburger drawer
 *
 * A fixed hamburger button (top-right) on EVERY page that morphs into an X
 * and opens a branded navy drawer with a staggered link reveal; locks
 * background scroll while open. Framework-free.
 *
 * This is the site's ONLY navigation. The DomainFolio theme registers no WP
 * nav menu and renders no header menu of its own (there is not a single
 * menu-item in the markup of any page), and the homepage hero's own burger
 * is suppressed via DB_HERO_HIDE_BURGER to avoid two hamburgers. So the
 * drawer must be reachable at every viewport width — when it was scoped to
 * a max-width: 860px media query, every page above 860px shipped with no
 * navigation at all.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_HERO_HIDE_BURGER' ) ) {
	define( 'DB_HERO_HIDE_BURGER', true );
}

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	// The Customizer preview iframe runs wp_footer; a fixed overlay button
	// on top of the live preview just gets in the way of theme editing.
	if ( is_customize_preview() ) {
		return;
	}
	$home  = esc_url( home_url( '/' ) );
	$links = array(
		'Home'          => $home,
		'Browse Domains'=> esc_url( home_url( '/all-domains/' ) ),
		// Not /payment-plan-setup/: that page is the checkout step for a
		// domain already chosen, and renders a $0.00 order without one. The
		// FAQ is where the plans are actually explained.
		'Payment Plans' => esc_url( home_url( '/faqs/' ) ),
		'Services'      => esc_url( home_url( '/our-services/' ) ),
		'News'          => esc_url( home_url( '/news/' ) ),
		'About'         => esc_url( home_url( '/about-us/' ) ),
		// /contact/ 301s to /contact-us/; link the destination directly so
		// every menu click doesn't pay for a redirect hop.
		'Contact'       => esc_url( home_url( '/contact-us/' ) ),
	);
	// The 5 dedicated service pages (see db_service_page_defs() in
	// db-custom-blocks.php — each already has its own real content and SEO
	// meta) had no path into this drawer at all: an earlier attempt at
	// this lived in a wp_nav_menu_items filter targeting theme locations
	// like "primary"/"main-menu", but this theme calls wp_nav_menu()
	// nowhere — confirmed by this very file's own docblock above ("not a
	// single menu-item in the markup of any page") — so that filter could
	// never have fired. This drawer is the site's ONLY real navigation,
	// so the submenu goes here instead, as an expandable list nested
	// under "Services" rather than a hover dropdown (nothing here is
	// hovered — the whole drawer is a touch/click surface).
	$service_pages = array(
		'Website Design & Development' => esc_url( home_url( '/website-design-development/' ) ),
		'Digital Marketing'            => esc_url( home_url( '/digital-marketing/' ) ),
		'Software Development'         => esc_url( home_url( '/software-development/' ) ),
		'Mobile App Development'       => esc_url( home_url( '/mobile-app-development/' ) ),
		'Other Services'               => esc_url( home_url( '/other-services/' ) ),
	);
	$logo = function_exists( 'db_brand_logo_url' ) ? db_brand_logo_url() : home_url( '/wp-content/uploads/2017/03/Domain-Brothers.png' );
	?>
	<button id="db-mnav-toggle" class="db-mnav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="db-mnav">
		<span class="db-mnav-bar"></span><span class="db-mnav-bar"></span><span class="db-mnav-bar"></span>
	</button>
	<nav id="db-mnav" class="db-mnav" aria-label="Primary" aria-hidden="true" inert>
		<div class="db-mnav-inner">
			<a class="db-mnav-logo" href="<?php echo $home; // escaped ?>" aria-label="Domain Brothers home">
				<img src="<?php echo esc_url( $logo ); ?>" alt="Domain Brothers" width="200" height="64" decoding="async">
			</a>
			<ul>
				<?php foreach ( $links as $label => $href ) : ?>
					<?php if ( 'Services' === $label ) : ?>
						<li class="db-mnav-has-sub">
							<div class="db-mnav-row">
								<a href="<?php echo $href; // already escaped ?>"><?php echo esc_html( $label ); ?></a>
								<button type="button" class="db-mnav-subtoggle" aria-expanded="false" aria-controls="db-mnav-sub-services" aria-label="Show services submenu">
									<span class="db-mnav-chevron" aria-hidden="true"></span>
								</button>
							</div>
							<ul id="db-mnav-sub-services" class="db-mnav-sub" hidden>
								<?php foreach ( $service_pages as $sub_label => $sub_href ) : ?>
									<li><a href="<?php echo $sub_href; // already escaped ?>"><?php echo esc_html( $sub_label ); ?></a></li>
								<?php endforeach; ?>
							</ul>
						</li>
					<?php else : ?>
						<li><a href="<?php echo $href; // already escaped ?>"><?php echo esc_html( $label ); ?></a></li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
		</div>
	</nav>
	<div id="db-mnav-scrim" class="db-mnav-scrim" hidden></div>

	<style>
	/* The drawer sits off-screen via a translateX on a position:fixed box,
	   not a negative offset — some browsers still count that post-transform
	   box in the page's scrollable area, adding ~30px of real horizontal
	   scroll on every page even though the drawer is invisible. That let a
	   sideways swipe/scroll on mobile reveal blank space past the right
	   edge, which is the opposite of a clean, non-janky feel. */
	html, body { overflow-x: hidden; }
	.db-mnav-toggle {
		position: fixed; top: 12px; right: 12px; z-index: 100001;
		width: 46px; height: 46px; padding: 12px 11px; border: 0; cursor: pointer;
		display: flex; flex-direction: column; justify-content: space-between;
		background: rgba(10,22,40,.92); border-radius: 12px;
		box-shadow: 0 4px 14px rgba(8,23,58,.28); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px);
	}
	.db-mnav-bar {
		display: block; width: 100%; height: 2.5px; border-radius: 2px; background: #fff;
		transition: transform .38s cubic-bezier(.6,.05,.28,.98), opacity .22s ease;
	}
	.db-mnav-toggle.is-open { background: rgba(10,22,40,1); }
	.db-mnav-toggle.is-open .db-mnav-bar:nth-child(1) { transform: translateY(9px) rotate(45deg); }
	.db-mnav-toggle.is-open .db-mnav-bar:nth-child(2) { opacity: 0; }
	.db-mnav-toggle.is-open .db-mnav-bar:nth-child(3) { transform: translateY(-9px) rotate(-45deg); }
	.db-mnav-toggle:focus-visible { outline: 3px solid #7ab6fb; outline-offset: 3px; }

	.db-mnav {
		position: fixed; top: 0; right: 0; bottom: 0; z-index: 100000;
		width: min(82vw, 360px); transform: translateX(105%);
		background: linear-gradient(160deg, #0a1628 0%, #08173a 60%, #132b52 100%);
		box-shadow: -22px 0 54px rgba(0,0,0,.4);
		/* visibility, not just transform: an off-screen drawer that is still
		   `visible` keeps all eight links in the tab order while the <nav>
		   carries aria-hidden="true", which is the axe `aria-hidden-focus`
		   violation Lighthouse flags. Delayed on close so the slide-out is
		   still seen. */
		visibility: hidden;
		transition: transform .34s cubic-bezier(.5,.05,.2,1), visibility 0s linear .34s;
		overflow-y: auto; -webkit-overflow-scrolling: touch;
	}
	.db-mnav.is-open { transform: translateX(0); visibility: visible; transition: transform .34s cubic-bezier(.5,.05,.2,1), visibility 0s; }
	.db-mnav-inner { padding: 26px 18px 28px; }
	.db-mnav-logo { display: block; margin: 0 6px 18px; padding-bottom: 18px; border-bottom: 1px solid rgba(157,199,251,.15); }
	.db-mnav-logo img { height: 46px; width: auto; max-width: 200px; display: block; }
	.db-mnav ul { list-style: none; margin: 0; padding: 0; }
	.db-mnav li {
		opacity: 0; transform: translateX(14px);
		transition: opacity .28s ease, transform .28s ease;
		border-bottom: 1px solid rgba(157,199,251,.12);
	}
	.db-mnav.is-open li { opacity: 1; transform: translateX(0); }
	.db-mnav.is-open li:nth-child(1) { transition-delay: .05s; }
	.db-mnav.is-open li:nth-child(2) { transition-delay: .09s; }
	.db-mnav.is-open li:nth-child(3) { transition-delay: .13s; }
	.db-mnav.is-open li:nth-child(4) { transition-delay: .17s; }
	.db-mnav.is-open li:nth-child(5) { transition-delay: .21s; }
	.db-mnav.is-open li:nth-child(6) { transition-delay: .25s; }
	.db-mnav.is-open li:nth-child(7) { transition-delay: .29s; }
	.db-mnav a {
		display: block; padding: 16px 12px; color: #e9f0fb; text-decoration: none;
		font-size: 18px; font-weight: 600; letter-spacing: -0.01em;
		transition: color .16s ease, padding-left .16s ease;
	}
	.db-mnav a:hover, .db-mnav a:focus { color: #7ab6fb; padding-left: 18px; }
	.db-mnav a:focus-visible { outline: 3px solid #7ab6fb; outline-offset: -3px; }
	.db-mnav-has-sub { border-bottom: 1px solid rgba(157,199,251,.12); }
	.db-mnav-row { display: flex; align-items: stretch; }
	.db-mnav-row a { flex: 1 1 auto; }
	.db-mnav-subtoggle {
		flex: 0 0 auto; width: 52px; border: 0; background: none; cursor: pointer;
		display: flex; align-items: center; justify-content: center;
	}
	.db-mnav-chevron {
		width: 9px; height: 9px; border-right: 2px solid #9dc7fb; border-bottom: 2px solid #9dc7fb;
		transform: rotate(45deg); transition: transform .22s ease;
	}
	.db-mnav-subtoggle[aria-expanded="true"] .db-mnav-chevron { transform: rotate(225deg); }
	.db-mnav-subtoggle:focus-visible { outline: 3px solid #7ab6fb; outline-offset: -3px; }
	.db-mnav-sub { list-style: none; margin: 0; padding: 0 0 6px; background: rgba(0,0,0,.16); }
	.db-mnav-sub li { border-bottom: none; opacity: 1 !important; transform: none !important; }
	.db-mnav-sub a { padding: 12px 12px 12px 30px; font-size: 15px; font-weight: 500; }
	.db-mnav-scrim {
		/* Below #wpadminbar (99999) so a logged-in admin's bar stays usable. */
		position: fixed; inset: 0; z-index: 99998; background: rgba(6,14,28,.5);
		opacity: 0; transition: opacity .3s ease; -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
	}
	.db-mnav-scrim.is-open { opacity: 1; }
	/* iOS Safari propagates the viewport scroll to <html>; overflow:hidden on
	   body alone leaves the page behind the drawer scrolling and rubber-banding. */
	html.db-mnav-open, body.db-mnav-open { overflow: hidden; }

	/* WordPress pins #wpadminbar to the viewport below 782px and reserves
	   space with html{margin-top}, which does not move position:fixed
	   elements — so the toggle landed on top of the admin bar's own menu. */
	.admin-bar .db-mnav-toggle { top: 58px; }
	.admin-bar .db-mnav { top: 46px; }
	@media screen and (min-width: 783px) {
		.admin-bar .db-mnav-toggle { top: 44px; }
		.admin-bar .db-mnav { top: 32px; }
	}

	@media (prefers-reduced-motion: reduce) {
		.db-mnav, .db-mnav li, .db-mnav-bar, .db-mnav-scrim { transition: none !important; }
	}
	</style>

	<script>
	(function () {
		'use strict';
		var t = document.getElementById('db-mnav-toggle');
		var n = document.getElementById('db-mnav');
		var s = document.getElementById('db-mnav-scrim');
		if (!t || !n || !s) { return; }
		var root = document.documentElement;
		var lastFocus = null;

		function focusables() {
			// offsetParent is null for a collapsed (hidden-attribute) submenu's
			// links — excluding them keeps Tab-trapping honest about what's
			// actually reachable, instead of cycling focus onto a link that
			// isn't visible yet.
			var all = n.querySelectorAll('a[href], button:not([disabled])');
			return Array.prototype.filter.call(all, function (el) { return el.offsetParent !== null; });
		}
		function setOpen(open) {
			if (open === t.classList.contains('is-open')) { return; }
			t.classList.toggle('is-open', open);
			n.classList.toggle('is-open', open);
			document.body.classList.toggle('db-mnav-open', open);
			root.classList.toggle('db-mnav-open', open);
			t.setAttribute('aria-expanded', open ? 'true' : 'false');
			t.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
			n.setAttribute('aria-hidden', open ? 'false' : 'true');
			// inert keeps the closed drawer out of the tab order even in
			// browsers that still paint it during the slide-out transition.
			if (open) { n.removeAttribute('inert'); } else { n.setAttribute('inert', ''); }
			if (open) {
				lastFocus = document.activeElement;
				s.hidden = false;
				requestAnimationFrame(function () {
					s.classList.add('is-open');
					var f = focusables();
					if (f.length) { f[0].focus(); }
				});
			} else {
				s.classList.remove('is-open');
				setTimeout(function () { s.hidden = true; }, 300);
				// Return focus to the toggle rather than stranding it on a
				// link that is now inert.
				if (lastFocus && document.contains(lastFocus)) { lastFocus.focus(); }
				else { t.focus(); }
				lastFocus = null;
			}
		}
		t.addEventListener('click', function () { setOpen(!t.classList.contains('is-open')); });
		s.addEventListener('click', function () { setOpen(false); });
		n.addEventListener('click', function (e) { if (e.target.closest('a')) { setOpen(false); } });

		var subToggle = document.querySelector('.db-mnav-subtoggle');
		if (subToggle) {
			var sub = document.getElementById(subToggle.getAttribute('aria-controls'));
			subToggle.addEventListener('click', function (e) {
				e.stopPropagation(); // don't let the "any link click closes the drawer" handler above see this
				var open = subToggle.getAttribute('aria-expanded') !== 'true';
				subToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
				if (sub) { sub.hidden = !open; }
			});
		}
		document.addEventListener('keydown', function (e) {
			if (!t.classList.contains('is-open')) { return; }
			if (e.key === 'Escape') { setOpen(false); return; }
			if (e.key !== 'Tab') { return; }
			// Trap Tab inside the open drawer; without it, tabbing past the
			// last link walks into the page content behind the opaque panel.
			var f = focusables();
			if (!f.length) { return; }
			var first = f[0], last = f[f.length - 1];
			if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
			else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
		});
	})();
	</script>
	<?php
}, 100 );
