<?php
/**
 * DB Domain Page Block — restores the single domain listing template
 *
 * The domain single-page template ("Gizmofreak.ca is for sale!", the actual
 * product page a buyer lands on) never received the theme's own CSS: no
 * <link> for the theme's assets/css/style.css or assets/css/bootstrap.min.css
 * is enqueued for is_singular('domain'), even though both files exist on
 * the server and every other content-heavy page style rule is defined only
 * in them. The effect, verified against the live HTML:
 *
 *   - The page renders with zero theme styling — plain black-on-white text,
 *     no card/spacing treatment, clashing hard with every other page.
 *   - style.css is what hides one of each .desktop_view / .mobile_view pair
 *     (a legacy "render both, CSS shows only one per breakpoint" pattern
 *     used throughout this template) — without it, BOTH copies of the
 *     "Get this domain in less than 2 hours" icon row, and both of the
 *     "we take privacy seriously" notices, show stacked on top of each
 *     other, reading as duplicated content.
 *   - The "Make an Offer" button is completely non-functional. It only
 *     sets data-toggle="modal"/data-target="#first" (Bootstrap's own
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
 * (data-toggle="modal" / data-dismiss="modal"), scoped to domain singular
 * pages only so it can't affect anything else on the site.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( 'domain' ) ) {
		return;
	}

	$theme_uri = get_template_directory_uri();

	// The theme's own stylesheets. Both already exist on the server and are
	// reachable directly (verified: HTTP 200) — they were simply never
	// enqueued for this one template. Bootstrap first so style.css, which
	// depends on and overrides parts of it, wins the cascade.
	wp_enqueue_style( 'db-domainfolio-bootstrap', $theme_uri . '/assets/css/bootstrap.min.css', array(), null );
	wp_enqueue_style( 'db-domainfolio-style', $theme_uri . '/assets/css/style.css', array( 'db-domainfolio-bootstrap' ), null );

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
