<?php
/* === DB homepage redesign ===
 *
 * Injects a modern, Apple-inspired hero section above the front-page content
 * and applies global CSS polish (typography, buttons, focus rings) across the
 * entire site — without touching or replacing the theme's domain-listing area.
 *
 * VISUAL RESULT:
 *   Deep navy gradient hero → eyebrow label → large headline → sub-text →
 *   two pill CTAs → trust-signal bar → then the theme's existing domain listings
 *   and content flow naturally below.
 *
 * CONFIG:
 *   DB_HERO_BROWSE_URL   — "Browse Premium Domains" button destination.
 *                          Default '' → smooth-scrolls to the content below the hero.
 *   DB_HERO_OFFER_URL    — "Make an Offer" button destination. Default: /contact/
 *   DB_HERO_HIDE_EXISTING — set true + fill in the CSS selector comment in the
 *                           <style> block if the theme prints its OWN conflicting
 *                           hero that you want to suppress.
 *   DB_HERO_USE_HOOK     — 'the_content' (default, hero appears inside the main
 *                           content area below the nav) or 'wp_body_open' (hero
 *                           appears at the very top of <body> — use this only if
 *                           the theme's front-page template does not call
 *                           the_content() at all).
 *
 * Paste at the end of functions.php. Self-contained.
 */

if ( ! defined( 'DB_HERO_BROWSE_URL' ) ) {
	define( 'DB_HERO_BROWSE_URL', '' ); // '' = smooth-scroll below hero; or e.g. '/domains/'
}
if ( ! defined( 'DB_HERO_OFFER_URL' ) ) {
	define( 'DB_HERO_OFFER_URL', '/contact/' );
}
if ( ! defined( 'DB_HERO_HIDE_EXISTING' ) ) {
	define( 'DB_HERO_HIDE_EXISTING', false );
}
if ( ! defined( 'DB_HERO_USE_HOOK' ) ) {
	define( 'DB_HERO_USE_HOOK', 'the_content' ); // 'the_content' or 'wp_body_open'
}

/* Build the hero HTML. Called once per request. */
function db_hp_hero_html() {
	$browse_href = DB_HERO_BROWSE_URL ? esc_url( DB_HERO_BROWSE_URL ) : '#db-below-hero';
	$offer_href  = esc_url( home_url( DB_HERO_OFFER_URL ) );

	$trust_items = array(
		'Expert Brokerage',
		'0% Interest Plans',
		'Secure Escrow',
		'24-Hour Response',
	);
	$trust_html = '';
	foreach ( $trust_items as $i => $item ) {
		if ( $i > 0 ) {
			$trust_html .= '<span class="db-hp-tdot" aria-hidden="true"></span>';
		}
		$trust_html .= '<span class="db-hp-titem">' . esc_html( $item ) . '</span>';
	}

	$html  = '<section class="db-hp-hero" aria-label="Domain Brothers">';
	$html .= '<div class="db-hp-inner">';
	$html .= '<p class="db-hp-eyebrow">Premium Domain Brokerage</p>';
	$html .= '<h1 class="db-hp-h1">The Right Domain<br>Changes Everything.</h1>';
	$html .= '<p class="db-hp-sub">We source, negotiate, and transfer premium domain names with expert precision — flexible payment plans, secure escrow, and guidance from a team with 25+ years of combined experience.</p>';
	$html .= '<div class="db-hp-ctas">';
	$html .= '<a href="' . $browse_href . '" class="db-hp-btn-primary db-hp-browse-btn">Browse Premium Domains</a>';
	$html .= '<a href="' . $offer_href . '" class="db-hp-btn-ghost">Make an Offer</a>';
	$html .= '</div>';
	$html .= '<div class="db-hp-trust">' . $trust_html . '</div>';
	$html .= '</div>';
	$html .= '</section>';
	$html .= '<span id="db-below-hero" aria-hidden="true"></span>';

	return $html;
}

/* Track whether the hero has been printed so both hooks don't fire. */
global $db_hero_rendered;
$db_hero_rendered = false;

/* PRIMARY: inject via the_content on the front page (hero lands below the nav). */
if ( 'the_content' === DB_HERO_USE_HOOK ) {
	add_filter( 'the_content', function ( $content ) {
		global $db_hero_rendered;
		static $done = false;
		if ( $done || $db_hero_rendered || ! is_front_page() || is_admin() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$done               = true;
		$db_hero_rendered   = true;
		return db_hp_hero_html() . $content;
	} );
} else {
	/* FALLBACK: wp_body_open (fires right after <body>; hero appears above the nav).
	 * Use this only when the theme's front-page template does not call the_content(). */
	add_action( 'wp_body_open', function () {
		global $db_hero_rendered;
		if ( ! is_front_page() || is_admin() || $db_hero_rendered ) {
			return;
		}
		$db_hero_rendered = true;
		echo db_hp_hero_html();
	} );
}

/* CSS: global site polish + hero. Output on every page (global rules); hero
 * styles are tightly scoped to .db-hp-* so they don't bleed elsewhere. */
add_action( 'wp_head', function () {
	?>
	<style id="db-hp-styles">

	/* ================================================================
	   GLOBAL POLISH — applies across the whole site
	   ================================================================ */

	/* Modern font stack and antialiasing */
	body {
		font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Segoe UI',
		             Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
		-webkit-font-smoothing: antialiased;
		-moz-osx-font-smoothing: grayscale;
		text-rendering: optimizeLegibility;
	}

	/* Subtle heading tightening for a premium feel */
	h1, h2, h3, h4 { letter-spacing: -0.02em; }

	/* Modern focus rings (accessibility + Apple style) */
	:focus-visible {
		outline: 2px solid #0b6ed4;
		outline-offset: 3px;
		border-radius: 4px;
	}

	/* Button modernisation — targets common WP and theme button classes */
	.wp-block-button__link,
	.btn, .button:not(.db-plan-cta):not(.db-hp-btn-primary):not(.db-hp-btn-ghost),
	input[type="submit"], button[type="submit"] {
		border-radius: 8px !important;
		transition: transform 0.12s ease, box-shadow 0.12s ease !important;
	}
	.wp-block-button__link:hover,
	.btn:hover,
	input[type="submit"]:hover, button[type="submit"]:hover {
		transform: translateY(-1px);
		box-shadow: 0 4px 14px rgba(0,0,0,.14);
	}

	/* ================================================================
	   HERO SECTION
	   ================================================================ */

	.db-hp-hero {
		/* Break out of any narrow content-wrapper to go full-width. */
		position: relative;
		width: 100vw;
		left: 50%;
		right: 50%;
		margin-left: -50vw;
		margin-right: -50vw;
		margin-top: 0;
		margin-bottom: 48px;

		/* Deep navy → rich blue gradient */
		background: linear-gradient(140deg, #060d24 0%, #0b2250 45%, #0e3272 100%);
		overflow: hidden;

		padding: clamp(72px, 12vw, 140px) 24px clamp(64px, 10vw, 120px);
		color: #f5f5f7;
		box-sizing: border-box;
	}

	/* Soft radial glow — adds depth without imagery */
	.db-hp-hero::before {
		content: '';
		position: absolute;
		top: -10%;
		left: 50%;
		width: 90vw;
		height: 90vw;
		max-width: 960px;
		max-height: 960px;
		transform: translateX(-50%);
		background: radial-gradient(ellipse, rgba(59,130,246,0.20) 0%, transparent 68%);
		pointer-events: none;
	}

	/* Subtle dot-grid texture for the "premium craft" feel */
	.db-hp-hero::after {
		content: '';
		position: absolute;
		inset: 0;
		background-image:
			radial-gradient(rgba(255,255,255,.06) 1px, transparent 1px);
		background-size: 32px 32px;
		pointer-events: none;
	}

	.db-hp-inner {
		position: relative;
		z-index: 1;
		max-width: 840px;
		margin: 0 auto;
		text-align: center;
	}

	/* Eyebrow pill */
	.db-hp-eyebrow {
		display: inline-block;
		font-size: 11px;
		font-weight: 700;
		letter-spacing: 0.14em;
		text-transform: uppercase;
		color: #93c5fd;
		margin: 0 0 26px;
		padding: 6px 16px;
		border: 1px solid rgba(147,197,253,0.35);
		border-radius: 999px;
		animation: db-fade-up 0.6s ease both;
	}

	/* Main headline */
	.db-hp-h1 {
		font-size: clamp(38px, 7vw, 78px);
		font-weight: 700;
		line-height: 1.03;
		letter-spacing: -0.04em;
		color: #f5f5f7;
		margin: 0 0 26px;
		animation: db-fade-up 0.6s 0.10s ease both;
	}

	/* Sub-text */
	.db-hp-sub {
		font-size: clamp(16px, 2vw, 20px);
		line-height: 1.58;
		color: rgba(245,245,247,0.70);
		max-width: 640px;
		margin: 0 auto 44px;
		font-weight: 400;
		animation: db-fade-up 0.6s 0.20s ease both;
	}

	/* CTA row */
	.db-hp-ctas {
		display: flex;
		flex-wrap: wrap;
		gap: 14px;
		justify-content: center;
		margin-bottom: 56px;
		animation: db-fade-up 0.6s 0.30s ease both;
	}

	.db-hp-btn-primary {
		display: inline-block;
		padding: 16px 36px;
		background: #f5f5f7;
		color: #0b1430 !important;
		font-size: 16px;
		font-weight: 600;
		text-decoration: none !important;
		border-radius: 999px;
		letter-spacing: -0.01em;
		box-shadow: 0 2px 10px rgba(0,0,0,.22);
		transition: background 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
	}
	.db-hp-btn-primary:hover {
		background: #ffffff;
		color: #060d24 !important;
		transform: scale(1.03);
		box-shadow: 0 8px 28px rgba(0,0,0,.32);
	}

	.db-hp-btn-ghost {
		display: inline-block;
		padding: 16px 36px;
		background: transparent;
		color: #f5f5f7 !important;
		font-size: 16px;
		font-weight: 600;
		text-decoration: none !important;
		border: 1.5px solid rgba(245,245,247,0.45);
		border-radius: 999px;
		letter-spacing: -0.01em;
		transition: border-color 0.15s ease, background 0.15s ease, transform 0.15s ease;
	}
	.db-hp-btn-ghost:hover {
		border-color: rgba(245,245,247,0.85);
		background: rgba(255,255,255,.10);
		color: #ffffff !important;
		transform: scale(1.03);
	}

	/* Trust bar */
	.db-hp-trust {
		display: flex;
		flex-wrap: wrap;
		align-items: center;
		justify-content: center;
		font-size: 13px;
		color: rgba(245,245,247,0.50);
		letter-spacing: 0.025em;
		animation: db-fade-up 0.6s 0.42s ease both;
	}
	.db-hp-titem { padding: 4px 14px; }
	.db-hp-tdot {
		display: inline-block;
		width: 3px; height: 3px;
		border-radius: 50%;
		background: rgba(245,245,247,0.28);
		vertical-align: middle;
	}

	/* Entry animation */
	@keyframes db-fade-up {
		from { opacity: 0; transform: translateY(22px); }
		to   { opacity: 1; transform: translateY(0); }
	}

	/* Mobile adjustments */
	@media (max-width: 580px) {
		.db-hp-ctas {
			flex-direction: column;
			align-items: center;
		}
		.db-hp-btn-primary,
		.db-hp-btn-ghost {
			width: 100%;
			max-width: 320px;
			text-align: center;
		}
		.db-hp-tdot { display: none; }
		.db-hp-trust { gap: 4px; }
		.db-hp-titem { padding: 2px 8px; }
	}

	<?php if ( DB_HERO_HIDE_EXISTING ) : ?>
	/*
	 * Add selectors below to hide the theme's own hero if it conflicts.
	 * Example: .home .hero-section, .home .page-banner { display:none!important; }
	 */
	<?php endif; ?>

	</style>
	<?php
}, 5 ); // priority 5 so theme can still override if needed

/* Smooth-scroll JS: when Browse button uses the default #db-below-hero anchor,
 * animate the scroll instead of jumping. */
add_action( 'wp_footer', function () {
	if ( ! is_front_page() ) {
		return;
	}
	?>
	<script>
	(function () {
		var btn = document.querySelector('.db-hp-browse-btn');
		if (!btn || btn.getAttribute('href') !== '#db-below-hero') return;
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			var target = document.getElementById('db-below-hero');
			if (!target) return;
			var top = target.getBoundingClientRect().top + window.pageYOffset - 20;
			if (window.scrollTo) {
				window.scrollTo({ top: top, behavior: 'smooth' });
			} else {
				window.scrollTop = top;
			}
		});
	})();
	</script>
	<?php
}, 99 );
/* === end DB homepage redesign === */
