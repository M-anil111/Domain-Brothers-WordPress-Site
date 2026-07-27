<?php
/**
 * DB Domain Page Block — restores the theme's own grid CSS + the single
 * domain listing template
 *
 * bootstrap.min.css exists on the server and is fully functional — it was
 * simply never enqueued anywhere except (as of this block, previously) the
 * single-domain template. Every other page's markup still uses Bootstrap's
 * .row / .col-md-* / .col-sm-* grid classes throughout (the homepage
 * header, the checkout flow's billing header, the domain-listing sidebar,
 * more we likely haven't hit yet), and with no grid CSS behind them those
 * classes are plain unstyled <div>s: columns meant to sit side-by-side
 * instead stack full-width, one on top of the other. That is the root
 * cause of a whole class of bugs patched one at a time this session (a
 * duplicate logo on the checkout page, an empty header gap on the
 * homepage, more) — every one of them was this same missing stylesheet.
 * Loading it site-wide fixes the class of bug at the source instead of
 * requiring a new targeted CSS patch every time another instance surfaces.
 *
 * The theme's own style.css is NOT loaded site-wide by this — only for
 * is_singular('domain'), unchanged from before. That file's specific job
 * there (documented below) was verified only for that one template; the
 * open question of whether other templates have the same .desktop_view /
 * .mobile_view duplicate-content pattern is separate from this fix and
 * hasn't been checked, so widening that one stays out of scope here.
 *
 * On the single domain listing template ("Gizmofreak.ca is for sale!", the
 * actual product page a buyer lands on) specifically, style.css also:
 *
 *   - Hides one of each .desktop_view / .mobile_view pair (a legacy
 *     "render both, CSS shows only one per breakpoint" pattern used
 *     throughout this template) — without it, BOTH copies of the
 *     "Get this domain in less than 2 hours" icon row, and both of the
 *     "we take privacy seriously" notices, show stacked on top of each
 *     other, reading as duplicated content.
 *   - The "Make an Offer" button is completely non-functional there. It
 *     only sets data-toggle="modal"/data-target="#first" (Bootstrap's own
 *     modal-trigger convention) and relies on Bootstrap's core JS to
 *     actually show that modal — but no such file was ever migrated to
 *     this theme's local assets (only bootstrap-select.min.js, a dropdown
 *     widget, survived); nothing intercepts the click, so the modal
 *     containing the actual offer form never opens. This is the primary
 *     buy-signal on every single domain listing page, and it silently did
 *     nothing.
 *
 * Since the theme can't be edited directly (Wordfence blocks admin-panel
 * saves, the same obstacle as the other theme-file fixes this session),
 * this enqueues the theme's own existing CSS and a small, dependency-free
 * replacement for just the one Bootstrap 3 behavior actually used here
 * (data-toggle="modal" / data-dismiss="modal").
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}
	// Early (before the "ui" design-system stylesheet, which has no
	// explicit priority and so registers at the default 10): every rule in
	// db_ui_css() needs to keep winning the cascade the same way it already
	// does today, by loading after this rather than by fighting it with
	// !important. Already exists on the server, reachable directly
	// (verified: HTTP 200) — it was simply never enqueued.
	wp_enqueue_style(
		'db-domainfolio-bootstrap',
		get_template_directory_uri() . '/assets/css/bootstrap.min.css',
		array(),
		null
	);
}, 5 );

add_action( 'wp_enqueue_scripts', function () {
	// /our-services/ is built from Gutenberg blocks using .shadowBox card
	// styling and a .cta_box closing panel — both defined in this same
	// style.css, unreachable without it (confirmed live: without this, the
	// whole page was an unstyled wall of text with no card treatment at
	// all). Scoped to this one page rather than site-wide for the same
	// reason bootstrap.min.css above stayed scoped for as long as it did:
	// style.css is a large, opinionated stylesheet, and every other page
	// already looks right without it — no reason to introduce a new
	// variable everywhere at once when only one page actually needs it.
	if ( ! is_singular( 'domain' ) && ! is_page( 'our-services' ) ) {
		return;
	}

	$theme_uri = get_template_directory_uri();

	// style.css depends on and overrides parts of Bootstrap, so it must load
	// after it — declaring the dependency here works whether that handle was
	// registered by the site-wide hook above or (if that hook is ever
	// removed) freshly by this one.
	wp_enqueue_style( 'db-domainfolio-bootstrap', $theme_uri . '/assets/css/bootstrap.min.css', array(), null );
	wp_enqueue_style( 'db-domainfolio-style', $theme_uri . '/assets/css/style.css', array( 'db-domainfolio-bootstrap' ), null );

	if ( ! is_singular( 'domain' ) ) {
		return;
	}

	// jQuery is already loaded by WordPress core / this theme on every page.
	wp_enqueue_script( 'jquery' );
	wp_add_inline_script( 'jquery', db_domain_page_modal_shim_js() );

	// The hero heading (".text_normal h1") is styled by the theme as white
	// text with only a 2px glow — designed to sit on the #primary banner's
	// background-image, which is itself hardcoded to a photo on the
	// PRODUCTION apex domain (a leftover of the same migration that never
	// fully copied /images/ over to this site, per the shim in
	// functions.php). That image happens to be reachable today, but nothing
	// about this page's readability should depend on a photo hosted on a
	// different domain never moving or going down. A solid fallback
	// background-color and a real contrast-drop shadow keep the heading
	// legible whether or not that fetch ever succeeds, without changing
	// how it looks when it does.
	wp_add_inline_style( 'db-domainfolio-style', '
		body.single-domain #primary.banner_bg { background-color: #0a1628; }
		body.single-domain .text_normal h1 { text-shadow: 0 2px 10px rgba(0,0,0,.85), 0 1px 3px rgba(0,0,0,.9); }
	' );
}, 20 );

/* ─── Multiple <h1> tags on every domain page ───────────────────────────── */

if ( ! function_exists( 'db_domain_page_fix_multiple_h1' ) ) {
	/**
	 * This template renders three real <h1> tags on every domain page — "You
	 * are looking to purchase X" (.main-title, the actual page title), "The
	 * domain name X is for sale!" (.domain-detail), and "About X"
	 * (.about_cnt) — a multiple-H1 defect flagged on 383 pages in the 2026-07
	 * site audit. Only the first is a real page title; the other two are
	 * section headings mistagged as h1. Keeps the first <h1> untouched and
	 * downgrades every later one on the page to <h2>; matching CSS in
	 * db_ui_css() (".domain-detail h2", ".about_cnt h2") keeps them pixel-
	 * identical to how they rendered as <h1>.
	 */
	function db_domain_page_fix_multiple_h1( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, '<h1' ) ) {
			return $html;
		}
		$seen = 0;
		return preg_replace_callback(
			'#<(h1)\b([^>]*)>(.*?)</h1>#is',
			function ( $m ) use ( &$seen ) {
				$seen++;
				if ( 1 === $seen ) {
					return $m[0];
				}
				return '<h2' . $m[2] . '>' . $m[3] . '</h2>';
			},
			$html
		);
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_singular( 'domain' ) ) {
		return;
	}
	ob_start( 'db_domain_page_fix_multiple_h1' );
}, 20 );

if ( ! function_exists( 'db_domain_page_modal_shim_js' ) ) {
	/**
	 * Minimal, dependency-free replacement for the one Bootstrap 3 modal
	 * behavior this template's "Make an Offer" button needs
	 * (data-toggle="modal" / data-dismiss="modal"), matching the exact
	 * class names (.modal.in, .modal-backdrop.in, body.modal-open) that
	 * bootstrap.min.css already defines transitions for — so the show/hide
	 * animation looks identical to what real Bootstrap JS would produce,
	 * without pulling in the whole library for a single trigger.
	 */
	function db_domain_page_modal_shim_js() {
		return <<<JS
jQuery(function (\$) {
	'use strict';
	function closeModal(\$modal) {
		\$modal.removeClass('in');
		var \$backdrop = \$modal.data('db-backdrop');
		if (\$backdrop) { \$backdrop.removeClass('in'); }
		setTimeout(function () {
			\$modal.css('display', 'none').removeAttr('aria-hidden');
			if (\$backdrop) { \$backdrop.remove(); }
			\$('body').removeClass('modal-open');
		}, 300);
	}
	\$(document).on('click', '[data-toggle="modal"]', function (e) {
		e.preventDefault();
		var target = \$(this).data('target') || \$(this).attr('href');
		var \$modal = target ? \$(target) : \$();
		if (!\$modal.length) { return; }
		var \$backdrop = \$('<div class="modal-backdrop fade"></div>').appendTo('body');
		\$('body').addClass('modal-open');
		\$modal.css('display', 'block').data('db-backdrop', \$backdrop);
		// rAF so the browser paints display:block before adding .in, or the
		// CSS transition these classes drive never actually animates.
		requestAnimationFrame(function () {
			requestAnimationFrame(function () { \$backdrop.addClass('in'); \$modal.addClass('in'); });
		});
	});
	\$(document).on('click', '[data-dismiss="modal"]', function (e) {
		e.preventDefault();
		closeModal(\$(this).closest('.modal'));
	});
	\$(document).on('click', '.modal', function (e) {
		if (e.target === this) { closeModal(\$(this)); }
	});
	\$(document).on('keydown', function (e) {
		if (e.key === 'Escape') { \$('.modal.in').each(function () { closeModal(\$(this)); }); }
	});
});
JS;
	}
}
