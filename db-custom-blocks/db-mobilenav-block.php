<?php
/**
 * DB Mobile Nav Block — site-wide hamburger drawer
 *
 * A fixed hamburger button (top-right, mobile only) on EVERY page that
 * morphs into an X and opens a branded navy drawer with a staggered link
 * reveal; locks background scroll while open. Framework-free.
 *
 * Adapted from the supplied "mobile nav" effect, branded to Domain
 * Brothers and wired to the real site links. Because it's site-wide, the
 * homepage hero's own burger is suppressed (DB_HERO_HIDE_BURGER) to avoid
 * two hamburgers on the front page.
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
	$home  = esc_url( home_url( '/' ) );
	$links = array(
		'Home'          => $home,
		'Browse Domains'=> $home,
		'Payment Plans' => esc_url( home_url( '/payment-plan-setup/' ) ),
		'Services'      => esc_url( home_url( '/website-design-development/' ) ),
		'News'          => esc_url( home_url( '/news/' ) ),
		'About'         => esc_url( home_url( '/about-us/' ) ),
		'Contact'       => esc_url( home_url( '/contact/' ) ),
	);
	?>
	<button id="db-mnav-toggle" class="db-mnav-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="db-mnav">
		<span class="db-mnav-bar"></span><span class="db-mnav-bar"></span><span class="db-mnav-bar"></span>
	</button>
	<nav id="db-mnav" class="db-mnav" aria-label="Mobile" aria-hidden="true">
		<div class="db-mnav-inner">
			<ul>
				<?php foreach ( $links as $label => $href ) : ?>
					<li><a href="<?php echo $href; // already escaped ?>"><?php echo esc_html( $label ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</nav>
	<div id="db-mnav-scrim" class="db-mnav-scrim" hidden></div>

	<style>
	.db-mnav-toggle { display: none; }
	@media (max-width: 860px) {
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

		.db-mnav {
			position: fixed; top: 0; right: 0; bottom: 0; z-index: 100000;
			width: min(82vw, 360px); transform: translateX(105%);
			background: linear-gradient(160deg, #0a1628 0%, #08173a 60%, #132b52 100%);
			box-shadow: -22px 0 54px rgba(0,0,0,.4);
			transition: transform .34s cubic-bezier(.5,.05,.2,1);
			overflow-y: auto; -webkit-overflow-scrolling: touch;
		}
		.db-mnav.is-open { transform: translateX(0); }
		.db-mnav-inner { padding: 78px 18px 28px; }
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
		.db-mnav-scrim {
			position: fixed; inset: 0; z-index: 99999; background: rgba(6,14,28,.5);
			opacity: 0; transition: opacity .3s ease; -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px);
		}
		.db-mnav-scrim.is-open { opacity: 1; }
		body.db-mnav-open { overflow: hidden; }
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
		function setOpen(open) {
			t.classList.toggle('is-open', open);
			n.classList.toggle('is-open', open);
			document.body.classList.toggle('db-mnav-open', open);
			t.setAttribute('aria-expanded', open ? 'true' : 'false');
			t.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
			n.setAttribute('aria-hidden', open ? 'false' : 'true');
			if (open) { s.hidden = false; requestAnimationFrame(function () { s.classList.add('is-open'); }); }
			else { s.classList.remove('is-open'); setTimeout(function () { s.hidden = true; }, 300); }
		}
		t.addEventListener('click', function () { setOpen(!t.classList.contains('is-open')); });
		s.addEventListener('click', function () { setOpen(false); });
		n.addEventListener('click', function (e) { if (e.target.closest('a')) { setOpen(false); } });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setOpen(false); } });
	})();
	</script>
	<?php
}, 100 );
