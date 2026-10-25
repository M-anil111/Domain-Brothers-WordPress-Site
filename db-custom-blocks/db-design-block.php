<?php
/**
 * Site-wide design refresh: the look of the exit-offer popup, everywhere.
 *
 * The popup set the direction: white cards on a pale page, 18px corners and
 * soft shadows, navy headings, one deep green for actions (#2e7d14, 5.4:1 on
 * white), solid navy and outlined buttons with 10px corners, 52px form
 * controls with a green focus ring, small uppercase labels.
 *
 * It is one stylesheet, loaded after the theme and the earlier UI layer
 * (db_ui_css()), that:
 *   1. re-points the existing design tokens (the UI layer's "blue" accent
 *      becomes the popup green, "pill" radii become 10px, dark mode is off
 *      so the whole site is one consistent light design);
 *   2. restyles the shared pieces: page frame, header, hero, buttons,
 *      cards, forms, tables, pagination, footer, and the domain, news,
 *      plan and content pages.
 *
 * Written to the uploads folder as a cacheable file (db_external_style), like
 * the UI layer, and inlined only if uploads is not writable.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_design_css' ) ) {
	function db_design_css() {
		ob_start();
		?>
/* ===== 1. Tokens: the popup's palette ===================================== */
:root, :root:root {
	color-scheme: light;
	--r-navy: #0a1628;
	--r-navy-2: #0f2140;
	--r-green: #2e7d14;
	--r-green-dk: #256510;
	--r-green-lt: #eaf4e5;
	--r-ink: #0a1628;
	--r-text: #374151;
	--r-muted: #4b5563;
	--r-line: #dfe5ee;
	--r-field: #cbd5e1;
	--r-bg: #f6f8fb;
	--r-card: #ffffff;
	--r-r-card: 18px;
	--r-r-ctl: 10px;
	--r-shadow: 0 1px 2px rgba(10,22,40,.05), 0 8px 24px rgba(10,22,40,.07);
	--r-shadow-lg: 0 24px 70px rgba(10,22,40,.25);
	--r-ring: 0 0 0 3px rgba(46,125,20,.25);
	--r-font: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, Inter, 'Helvetica Neue', Arial, sans-serif;
	--r-gutter: clamp(16px, 4vw, 32px);
	--r-max: 1240px;

	/* The UI layer's tokens, re-pointed (its names say "blue"; it is green now). */
	--db-blue: #2e7d14; --db-blue-dk: #256510; --db-blue-lt: #eaf4e5; --db-accent: #2e7d14;
	--db-green: #2e7d14;
	--db-navy: #0a1628; --db-navy-2: #0a1628; --db-navy-3: #0f2140;
	--db-gray-50: #f8fafc; --db-gray-100: #f1f5f9; --db-gray-200: #e2e8f0; --db-gray-300: #cbd5e1;
	--db-gray-500: #64748b; --db-gray-700: #374151; --db-gray-900: #0a1628;
	--db-surface: #ffffff; --db-header-bg: #ffffff;
	--db-focus-ring: rgba(46,125,20,.25);
	--db-font: var(--r-font);
	--db-r-sm: 10px; --db-r-md: 10px; --db-r-lg: 18px; --db-r-pill: 10px;
	--db-shadow-sm: var(--r-shadow); --db-shadow-md: var(--r-shadow); --db-shadow-lg: var(--r-shadow-lg);
	--db-grad-navy: linear-gradient(180deg, #0a1628 0%, #0f2140 100%);
}

/* ===== 2. Page frame and type ============================================= */
html { scroll-behavior: smooth; }
body.db-ui-active {
	background: var(--r-bg) !important; background-image: none !important;
	color: var(--r-text); font-family: var(--r-font); font-size: 16px; line-height: 1.6;
	-webkit-font-smoothing: antialiased; text-rendering: optimizeLegibility;
}
body.db-ui-active :is(h1,h2,h3,h4,h5,h6,p,li,a,td,th,label,input,select,textarea,button,small,strong,em,blockquote,figcaption,dt,dd):not(.fa):not([class^="fa-"]):not([class*=" fa-"]) {
	font-family: var(--r-font) !important;
}
body.db-ui-active :is(h1,h2,h3,h4,h5,h6) { color: var(--r-ink); letter-spacing: -.015em; line-height: 1.2; font-weight: 700; text-transform: none; }
body.db-ui-active h1 { font-size: clamp(28px, 4.6vw, 44px); }
body.db-ui-active h2 { font-size: clamp(22px, 3.2vw, 30px); }
body.db-ui-active h3 { font-size: clamp(18px, 2.4vw, 22px); }
body.db-ui-active :is(p, li) { color: var(--r-text); }
body.db-ui-active a { color: var(--r-green); text-decoration-thickness: 1px; text-underline-offset: 2px; }
body.db-ui-active a:hover { color: var(--r-green-dk); }
body.db-ui-active :focus-visible { outline: 3px solid var(--r-green); outline-offset: 2px; }
body.db-ui-active ::selection { background: #cfe7c6; color: var(--r-ink); }

/* The boxed 1170px "container" on a patterned backdrop, replaced by a clean
   full-width page with a centred content column. */
body.db-ui-active #page.site.container { width: 100%; max-width: none; padding: 0; margin: 0; background: transparent; box-shadow: none; }
body.db-ui-active #content.site-content { max-width: var(--r-max); margin: 0 auto !important; padding: 24px var(--r-gutter) 56px !important; background: transparent !important; background-image: none !important; box-shadow: none; float: none; width: auto; }
/* Plain content pages: one centred reading column instead of a left-floated 900px block. */
body.page:not(.home):not(.check-out) #primary { float: none !important; width: 100% !important; max-width: 920px; margin: 0 auto !important; }
body.db-ui-active #content.site-content > .content-area { padding-left: 0; padding-right: 0; }
body.db-ui-active .wp-block-columns { gap: 24px; }
/* The pale blue gradient painted behind every page's main column, and the
   theme's 60px/138px side indents on <main> and 50px on page text. */
body.db-ui-active :is(#primary:not(.banner_bg), #main, .content-area:not(.banner_bg), .site-main) { background: none !important; background-image: none !important; box-shadow: none; }
body.db-ui-active .site-main:not(.domain-detail) { padding-left: 0 !important; padding-right: 0 !important; margin-left: 0 !important; margin-right: 0 !important; }
body.db-ui-active .entry-content { padding-left: 0 !important; padding-right: 0 !important; }

/* Small uppercase section labels (the popup's "Before you go"). */
body.db-ui-active .db-hp-eyebrow, body.db-ui-active .eyebrow {
	font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: var(--r-green);
}

/* ===== 3. Header ========================================================== */
body.db-ui-active .site-header .domain-search {
	max-width: none !important; width: 100% !important; margin: 0 !important; height: 72px;
	background: #fff !important; -webkit-backdrop-filter: none; backdrop-filter: none;
	border-bottom: 1px solid var(--r-line); box-shadow: 0 1px 0 rgba(10,22,40,.02);
	padding-left: max(var(--r-gutter), calc((100vw - var(--r-max)) / 2 + var(--r-gutter))) !important;
	padding-right: max(78px, var(--r-gutter)) !important;
}
@media (min-width: 992px) {
	body.db-ui-active .site-header .domain-search { padding-right: max(var(--r-gutter), calc((100vw - var(--r-max)) / 2 + var(--r-gutter))) !important; gap: 28px; }
}
body.db-ui-active, body.single-domain.db-ui-active { padding-top: 72px !important; }
body.db-ui-active .site-header .domain-search :is(.custom-logo-link, .custom-logo-link img) { margin: 0 !important; padding: 0; line-height: 0; align-self: center; }
/* The in-flow header is empty once the logo and menu move into the fixed bar. */
body.db-ui-active:not(.single-domain) #masthead.site-header { height: 0; min-height: 0; margin: 0 !important; padding: 0 !important; border: 0 !important; background: transparent !important; box-shadow: none; overflow: visible; }
body.db-ui-active .site-header .domain-search .widget, body.db-ui-active .site-header .domain-search form { margin: 0; height: 44px; align-items: center; }
body.db-ui-active .site-header :is(input[type=search], input[type=text], button, input[type=submit]) { box-sizing: border-box; margin: 0; }
/* The theme nudges the search button up with position:relative; top:-40px on some pages. */
body.db-ui-active .site-header .search-form { display: flex; align-items: center; gap: 8px; height: 44px; }
body.db-ui-active .site-header .search-form > label { flex: 1 1 auto; margin: 0; }
body.db-ui-active .site-header :is(.search-submit, .search-form input) { position: static !important; top: auto !important; }
/* The logo inside the desktop menu is used only where the header has no logo
   of its own (the home page); everywhere else the theme's own logo link is
   moved into the bar. On phones the hero carries the logo. */
body.db-ui-active .db-desktop-logo { display: none; }
@media (min-width: 992px) {
	body.home.db-ui-active .db-desktop-nav { margin-right: auto; }
	body.home.db-ui-active .db-desktop-logo { display: inline-flex; align-items: center; margin-right: 28px; line-height: 0; }
	body.home.db-ui-active .db-desktop-logo img { height: 40px; width: auto; }
	body.home.db-ui-active .db-desktop-nav { display: flex; align-items: center; flex: 1 1 auto; justify-content: flex-start; }
	body.home.db-ui-active .db-hp-nav { display: none; }
	body.db-ui-active:not(.home) .db-desktop-logo { display: none !important; }
}
body.home.db-ui-active .db-hp-inner { padding-top: clamp(56px, 9vw, 96px); }
/* A single domain's sale page hides the search bar, so its header is a plain
   logo + menu bar, fixed like the rest. */
body.single-domain.db-ui-active #masthead.site-header {
	position: fixed; top: 0 !important; left: 0; right: 0; z-index: 400; height: 72px; margin: 0 !important; padding: 0 max(78px, var(--r-gutter)) 0 var(--r-gutter);
	background: #fff !important; border-bottom: 1px solid var(--r-line); display: flex; align-items: center;
}
body.single-domain.db-ui-active #masthead .row { display: flex; flex-wrap: nowrap; align-items: center; width: 100%; margin: 0; }
body.single-domain.db-ui-active #masthead .site-branding { display: flex; align-items: center; gap: 28px; width: 100%; margin: 0; padding: 0; float: none; }
body.single-domain.db-ui-active #masthead .site-branding .custom-logo-link { margin: 0 !important; line-height: 0; }
body.single-domain.db-ui-active #content.site-content { padding: 0 !important; min-height: 0 !important; background: none !important; }
body.single-domain.db-ui-active .db-desktop-logo { display: none !important; }
body.single-domain.db-ui-active #masthead .site-branding .custom-logo-link img { height: 40px; width: auto; }
@media (min-width: 992px) {
	body.single-domain.db-ui-active #masthead.site-header { padding: 0 max(var(--r-gutter), calc((100vw - var(--r-max)) / 2 + var(--r-gutter))); }
	body.single-domain.db-ui-active #masthead .site-branding .db-desktop-nav { margin-left: auto; flex: 0 1 auto; justify-content: flex-end; }
}
body.admin-bar.single-domain.db-ui-active #masthead.site-header { top: 32px; }
body.db-ui-active .site-header .domain-search .custom-logo-link img { height: 40px; }
body.db-ui-active .db-desktop-nav ul { gap: 4px; }
body.db-ui-active .db-desktop-nav a {
	display: inline-flex; align-items: center; min-height: 40px; padding: 0 12px; border-radius: var(--r-r-ctl);
	color: var(--r-ink) !important; font-weight: 600; font-size: 15px;
}
body.db-ui-active .db-desktop-nav a:hover { background: var(--r-green-lt); color: var(--r-green-dk) !important; }
body.db-ui-active .site-header .domain-search form { max-width: 360px; margin: 0; }
body.db-ui-active .site-header :is(input[type=search], input[type=text]) {
	height: 44px; padding: 0 14px 0 40px; border: 2px solid var(--r-field); border-radius: var(--r-r-ctl); background-color: #fff; color: var(--r-ink); font-size: 15px; box-shadow: none;
}
body.db-ui-active .site-header :is(input[type=search], input[type=text]):focus { border-color: var(--r-green); box-shadow: var(--r-ring); outline: 0; }
body.db-ui-active .site-header :is(button, input[type=submit]) {
	height: 44px; padding: 0 18px; border: 0; border-radius: var(--r-r-ctl); background: var(--r-navy); color: #fff; font-weight: 700; font-size: 15px; cursor: pointer;
}
body.db-ui-active .site-header :is(button, input[type=submit]):hover { background: var(--r-navy-2); }
body.db-ui-active .db-mnav-toggle { border-radius: var(--r-r-ctl); background: var(--r-navy); box-shadow: none; }
body.db-ui-active .db-breadcrumbs { font-size: 13px; color: var(--r-muted); margin: 0 0 12px; }
body.db-ui-active .db-breadcrumbs a { color: var(--r-muted); }
body.db-ui-active .db-breadcrumbs [aria-current] { color: var(--r-ink); font-weight: 600; }

/* ===== 4. Buttons ========================================================= */
/* Primary action = green. Buy / "view" = solid navy. Secondary = outlined navy. */
body.db-ui-active :is(.db-hp-cta, .db-hp-ctas a:first-child) {
	background: var(--r-green) !important; color: #fff !important; border: 0; border-radius: var(--r-r-ctl) !important; min-height: 52px; padding: 0 28px;
	font-weight: 700; font-size: 16px; box-shadow: none !important; display: inline-flex; align-items: center; justify-content: center; text-decoration: none !important;
}
body.db-ui-active :is(.db-hp-cta, .db-hp-ctas a:first-child):hover { background: var(--r-green-dk) !important; }
body.db-ui-active .db-hp-ctas a:not(:first-child) {
	background: transparent !important; color: #fff !important; border: 2px solid rgba(255,255,255,.7); border-radius: var(--r-r-ctl) !important; min-height: 52px; padding: 0 24px; display: inline-flex; align-items: center; text-decoration: none !important; font-weight: 700;
}
body.db-ui-active :is(.db-act-buy, .db-dd-btn--primary, .db-plan-cta, .btn-primary, input[type=submit], .wpcf7-submit, .wp-block-button__link, .cta_cstm_btn, .xf__button) {
	background: var(--r-navy) !important; color: #fff !important; border: 0 !important; border-radius: var(--r-r-ctl) !important; min-height: 48px; padding: 0 22px;
	font-weight: 700; font-size: 16px; text-decoration: none !important; box-shadow: none !important; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-transform: none; letter-spacing: 0;
}
body.db-ui-active :is(.db-act-buy, .db-dd-btn--primary, .db-plan-cta, .btn-primary, input[type=submit], .wpcf7-submit, .wp-block-button__link, .cta_cstm_btn, .xf__button):hover { background: var(--r-navy-2) !important; color: #fff !important; transform: none; }
body.db-ui-active :is(.db-plan-cta, .wpcf7-submit, .cta_cstm_btn) { background: var(--r-green) !important; }
body.db-ui-active :is(.db-plan-cta, .wpcf7-submit, .cta_cstm_btn):hover { background: var(--r-green-dk) !important; }
body.db-ui-active :is(.db-act-offer, .db-dd-btn:not(.db-dd-btn--primary)) {
	background: #fff !important; color: var(--r-navy) !important; border: 2px solid var(--r-navy) !important; border-radius: var(--r-r-ctl) !important; min-height: 48px; padding: 0 20px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; text-decoration: none !important; box-shadow: none !important;
}
body.db-ui-active :is(.db-act-offer, .db-dd-btn:not(.db-dd-btn--primary)):hover { background: #f1f5f9 !important; }
body.db-ui-active .badge, body.db-ui-active .badge-offer { border-radius: var(--r-r-ctl); font-weight: 700; letter-spacing: 0; }

/* ===== 5. Home hero ======================================================= */
body.db-ui-active .db-hp-hero { background: linear-gradient(180deg, #0a1628 0%, #0f2140 100%) !important; }
body.db-ui-active .db-hp-hero::before, body.db-ui-active .db-hp-hero::after { opacity: .35; }
body.db-ui-active .db-hp-eyebrow { display: inline-block; padding: 6px 14px; border-radius: 999px; background: rgba(155,227,122,.12) !important; border: 1px solid rgba(155,227,122,.35); color: #b5ec9a !important; }
body.db-ui-active .db-hp-h1 { color: #fff; letter-spacing: -.02em; }
body.db-ui-active .db-hp-sub { color: #cbd5e1; }
body.db-ui-active .db-hp-trust, body.db-ui-active .db-hp-trust * { color: #aab6c8; }

/* ===== 6. Cards =========================================================== */
body.db-ui-active :is(.db-domain-card, .iconBox, .serviceBox, .shadowBox, .db-plan, .db-dd-facts, .custom-sidebar .widget, aside .widget, .db-news-card, article.post .entry-wrapper) {
	background: var(--r-card); border: 1px solid var(--r-line); border-radius: var(--r-r-card); box-shadow: var(--r-shadow);
}
body.db-ui-active .db-domain-card:hover, body.db-ui-active :is(.iconBox, .serviceBox):hover { border-color: #b8c4d4; box-shadow: 0 10px 30px rgba(10,22,40,.10); transform: translateY(-2px); }
body.db-ui-active .db-domain-card__name { color: var(--r-ink) !important; font-weight: 700; font-size: 17px; text-decoration: none; }
body.db-ui-active .db-domain-card__name:hover { color: var(--r-green) !important; }
body.db-ui-active .db-domain-card__price { color: var(--r-ink); font-weight: 800; }
body.db-ui-active .db-domain-card__mo { color: var(--r-green); }
body.db-ui-active .db-domain-card__mono { border-radius: 12px; }
body.db-ui-active .db-domain-grid { gap: 14px; }
body.db-ui-active .iconBox h3, body.db-ui-active .serviceBox h3 { color: var(--r-ink); }
body.db-ui-active .db-icon-badge { border-radius: 14px; }
body.db-ui-active .db-view-all, body.db-ui-active a.view-all { color: var(--r-green); font-weight: 700; }

/* Section tabs / filter chips. */
body.db-ui-active :is(.db-home-tab, .db-browse-tab, .db-tab, .nav-tabs a) { border-radius: var(--r-r-ctl) !important; font-weight: 700; }
body.db-ui-active :is(.is-active, .active).db-home-tab, body.db-ui-active .db-browse-tab.is-active { background: var(--r-navy) !important; color: #fff !important; border-color: var(--r-navy) !important; }

/* ===== 7. Forms =========================================================== */
body.db-ui-active :is(input[type=text], input[type=email], input[type=tel], input[type=number], input[type=search], input[type=url], input[type=password], select, textarea):not(.db-exit-input) {
	min-height: 48px; padding: 10px 14px; border: 2px solid var(--r-field); border-radius: var(--r-r-ctl); background: #fff; color: var(--r-ink); font-size: 16px; box-shadow: none; width: 100%; max-width: 100%;
}
body.db-ui-active textarea { min-height: 120px; }
body.db-ui-active :is(input, select, textarea):not(.db-exit-input):focus { border-color: var(--r-green); box-shadow: var(--r-ring); outline: 0; }
body.db-ui-active label { color: #374151; font-weight: 600; font-size: 14px; }
body.db-ui-active .wpcf7-not-valid-tip { color: #b91c1c; font-size: 14px; }
body.db-ui-active .wpcf7-response-output { border-radius: var(--r-r-ctl); border-width: 2px; }
/* The checkboxes drawn by CSS (see the missing-image fix) keep their size. */
body.db-ui-active :is(input[type=checkbox], input[type=radio]) { width: auto; min-height: 0; }

/* ===== 8. Tables and lists ================================================ */
body.db-ui-active table { border-collapse: collapse; width: 100%; }
body.db-ui-active :is(th) { color: var(--r-muted); font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; }
body.db-ui-active :is(td, th) { border-color: var(--r-line); }
body.db-ui-active .sidebar-table td a { color: var(--r-ink); }
body.db-ui-active .sidebar-table td a:hover { color: var(--r-green); }

/* Pagination. */
body.db-ui-active .page-numbers, body.db-ui-active .pagination a, body.db-ui-active .pagination span {
	display: inline-flex; align-items: center; justify-content: center; min-width: 44px; min-height: 44px; padding: 0 12px; margin: 2px; border: 1px solid var(--r-line); border-radius: var(--r-r-ctl); background: #fff; color: var(--r-ink); font-weight: 600; text-decoration: none;
}
body.db-ui-active .page-numbers.current { background: var(--r-navy); border-color: var(--r-navy); color: #fff; }

/* ===== 9. Domain page ===================================================== */
body.db-ui-active .banner_bg .opacity_bg { background: linear-gradient(90deg, rgba(10,22,40,.92) 0%, rgba(10,22,40,.72) 55%, rgba(10,22,40,.5) 100%) !important; }
body.db-ui-active .banner_bg { color: #fff; }
body.db-ui-active .banner_bg :is(p, li, h1, h2, h3, h4, h5, span, label) { color: #fff; }
body.single-domain.db-ui-active .db-breadcrumbs, body.single-domain.db-ui-active .db-breadcrumbs a, body.single-domain.db-ui-active .db-breadcrumbs [aria-current], body.single-domain.db-ui-active .db-breadcrumbs li { color: #e2e8f0 !important; opacity: 1; }
body.db-ui-active .banner_bg .db-breadcrumbs, body.db-ui-active .banner_bg .db-breadcrumbs a, body.db-ui-active .banner_bg .db-breadcrumbs [aria-current], body.db-ui-active .banner_bg .db-breadcrumbs li { color: #e2e8f0 !important; opacity: 1; }
body.db-ui-active .banner_bg .domain_name { color: #9be37a !important; }
body.db-ui-active .banner_bg :is(h1, h2) { font-weight: 300; letter-spacing: -.01em; }
body.db-ui-active .banner_bg .domain_name { font-weight: 800; }
body.db-ui-active .banner_bg .get_domain_icon p { color: #e2e8f0; font-weight: 600; }
body.db-ui-active .banner_bg .mobile_view .get_domain_icon p { color: var(--r-navy); }
body.db-ui-active a.db-service-learnmore { display: inline-flex; align-items: center; min-height: 44px; }
body.db-ui-active .domain-detail-form .your_Offer p a, body.db-ui-active .your_Offer_bg a { color: #1f5c0d !important; text-decoration: underline; }
body.db-ui-active .banner_bg .domain-detail-form :is(p, li, span, label, div) { color: var(--r-text); }
body.db-ui-active .banner_bg .domain-detail-form > h3 { color: var(--r-green) !important; }
body.db-ui-active .banner_bg .domain-detail-form .inq-btn button, body.db-ui-active .banner_bg .domain-detail-form .inq-btn button span { color: #fff; }
body.db-ui-active .banner_bg .domain-detail-form a { color: var(--r-green); }
body.db-ui-active .banner_bg h1 span, body.db-ui-active .banner_bg :is(.domain-name, .dname) { color: #9be37a; }
body.db-ui-active .domain-detail-form.make_an_offer {
	background: #fff; border: 0; border-radius: var(--r-r-card); box-shadow: var(--r-shadow-lg); overflow: hidden; padding: 0;
}
body.db-ui-active .domain-detail-form > h3 {
	background: #fff !important; color: var(--r-green) !important; margin: 0 !important; padding: 22px 24px 0 !important; font-size: 12px !important; letter-spacing: .12em; text-transform: uppercase; text-align: left;
}
body.db-ui-active .domain-detail-form form { padding: 10px 24px 22px; }
body.db-ui-active .domain-detail-form .your_Offer { padding: 14px 24px; }
body.db-ui-active .domain-detail-form .my_offer { display: block; margin-top: 8px; font-weight: 700; color: var(--r-ink) !important; }
body.db-ui-active .domain-detail-form .amount { display: block; margin-bottom: 8px; font-size: 13px; }
body.db-ui-active .domain-detail-form .frm-field { display: flex; align-items: center; height: 52px; padding: 0 14px; border: 2px solid var(--r-field); border-radius: var(--r-r-ctl); background: #fff; margin-bottom: 14px; }
body.db-ui-active .domain-detail-form .frm-field:focus-within { border-color: var(--r-green); box-shadow: var(--r-ring); }
body.db-ui-active .domain-detail-form .frm-field > i { display: none; }
body.db-ui-active .domain-detail-form .frm-field input { flex: 1; min-width: 0; height: 100%; min-height: 0; padding: 0 !important; border: 0 !important; box-shadow: none !important; background: transparent !important; font-size: 18px; font-weight: 600; }
body.db-ui-active .domain-detail-form .domain-detail-checkbox { display: none; }
body.db-ui-active .domain-detail-form .inq-btn button {
	min-height: 52px; width: 100%; border: 0; border-radius: var(--r-r-ctl) !important; background: var(--r-green) !important; color: #fff !important; font-weight: 700; font-size: 16px; letter-spacing: 0; text-transform: none; box-shadow: none;
}
body.db-ui-active .domain-detail-form .inq-btn button:hover { background: var(--r-green-dk) !important; }
body.db-ui-active .domain-detail-form .inq-btn .small-btn { display: block; font-size: 12px; font-weight: 500; opacity: .9; }
body.db-ui-active .your_Offer_bg { background: var(--r-green-lt); border-radius: 0 0 var(--r-r-card) var(--r-r-card); }
body.db-ui-active .get_domain_icon li { border-radius: var(--r-r-card); }
body.db-ui-active .about_cnt > h2, body.db-ui-active .db-dd > h2, body.db-ui-active .thankyou_right > h2 { color: var(--r-ink) !important; }
body.db-ui-active .db-dd { background: var(--r-card); border: 1px solid var(--r-line); border-radius: var(--r-r-card); padding: clamp(18px, 3vw, 32px); box-shadow: var(--r-shadow); }
body.db-ui-active .db-dd-facts { box-shadow: none; border-radius: 12px; overflow: hidden; }
body.db-ui-active .db-dd-terms { border-radius: 12px; }
body.db-ui-active .custom-sidebar, body.db-ui-active aside#secondary { background: transparent; }

/* ===== 10. News ============================================================ */
body.db-ui-active article.post { border-radius: var(--r-r-card); overflow: hidden; }
body.db-ui-active .db-news-grid { align-items: start; gap: 20px; }
body.db-ui-active .db-news-card { overflow: hidden; }
body.db-ui-active .xf__button, body.db-ui-active a.read-more { min-height: 44px; }

/* ===== 11. Payment-plan page ============================================== */
body.db-ui-active .db-plan { padding: clamp(18px, 3vw, 32px); }
body.db-ui-active .db-plan-term { border: 2px solid var(--r-line); border-radius: 12px; background: #fff; }
body.db-ui-active .db-plan-term:is(.is-active, [aria-selected=true]) { border-color: var(--r-green); background: var(--r-green-lt); box-shadow: var(--r-ring); }
body.db-ui-active .db-plan-summary { background: #f8fafc; border: 1px solid var(--r-line); border-radius: 14px; }
body.db-ui-active .db-plan-domain { color: var(--r-green); }
body.db-ui-active .db-plan-zero { background: var(--r-green-lt); color: var(--r-green-dk); border-radius: 999px; }
/* Leftover theme copy above the plan chooser ("Questions? We're here to help",
   a bullet list of checkout steps from the old multi-step checkout). */
body.db-ui-active .header_billing, body.db-ui-active .billing_info_menu { display: none !important; }

/* ===== 12. Content pages ================================================== */
body.db-ui-active .entry-content { color: var(--r-text); }
body.db-ui-active .entry-content :is(h1, h2, h3, h4) { margin-top: 1.6em; }
body.db-ui-active .page .entry-title, body.db-ui-active .page-title { background: transparent !important; color: var(--r-ink) !important; padding: 0 !important; border: 0; }
body.db-ui-active .wp-block-heading::after, body.db-ui-active .entry-content h2::after { display: none; }
body.db-ui-active .page .entry-wrapper:not(:has(.db-plan)) { background: var(--r-card); border: 1px solid var(--r-line); border-radius: var(--r-r-card); padding: clamp(20px, 4vw, 44px); box-shadow: var(--r-shadow); }

/* ===== 13. Footer ========================================================= */
body.db-ui-active .site-footer { background: var(--r-navy) !important; color: #cbd5e1; border-top: 0; }
body.db-ui-active .site-footer :is(h1, h2, h3, h4, h5) { color: #fff; font-size: 13px; letter-spacing: .1em; text-transform: uppercase; }
body.db-ui-active .site-footer :is(p, li, span) { color: #cbd5e1; }
body.db-ui-active .site-footer a { color: #e2e8f0; text-decoration: none; }
body.db-ui-active .site-footer a:hover { color: #9be37a; text-decoration: underline; }

/* ===== 14. Layout fixes found by the responsive scan ===================== */
/* Bootstrap's .row has -15px side margins and is used here without a padded
   .container around it, so on every domain page it stuck out past the edge. */
body.db-ui-active .banner_bg .container > .row, body.db-ui-active .about_info .row { margin-left: 0; margin-right: 0; }
body.db-ui-active img, body.db-ui-active video, body.db-ui-active iframe { max-width: 100%; }
body.db-ui-active .entry-content :is(table, pre) { display: block; max-width: 100%; overflow-x: auto; }

/* Targets: at least 24px everywhere (WCAG 2.5.8), 44px on touch screens. */
body.db-ui-active .db-domain-card__name { display: block; padding: 4px 0; line-height: 1.4; }
body.db-ui-active .db-view-all { display: inline-flex; align-items: center; min-height: 44px; }
body.db-ui-active :is(.site-footer li a, .sidebar-table td a, .db-breadcrumbs a) { display: inline-block; padding: 3px 0; min-height: 24px; }
body.db-ui-active .domain-detail-form .frm-field .wpcf7-form-control-wrap { display: flex; flex: 1; min-width: 0; height: 100%; }
/* Bootstrap's fixed-width .container (750/970/1170px + padding) overflows narrow tablets. */
body.db-ui-active .container { width: auto !important; max-width: var(--r-max); box-sizing: border-box; }
/* "Present your best offer" sits beside the floated badge and was forced to 100% width, sticking out 20px. */
body.db-ui-active .offer_text { width: auto !important; max-width: none; overflow: hidden; box-sizing: border-box; }
body.db-ui-active .offer_text_info { max-width: 100%; box-sizing: border-box; margin-right: 0; }
/* Pages built from blocks (Contact): <main> is the card, so give it inner padding. */
body.db-ui-active.page:not(.home):not(.check-out) #main.site-main:not(:has(.entry-wrapper)) { padding: clamp(20px, 4vw, 44px) !important; }
/* Floated columns inside page content (Offer): the card must wrap them. */
body.db-ui-active .entry-content { display: flow-root; }
/* News post card: inner padding. */
body.db-ui-active.single-post article.post .entry-wrapper { padding: clamp(20px, 4vw, 44px); }
body.db-ui-active.single-post article.post .entry-content img { max-width: 100%; height: auto; border-radius: 12px; }
/* Contact column: breathing room between icon rows. */
body.db-ui-active main .wp-block-column p { margin: 0 0 16px; }
/* Sidebar chips fill the card. */
body.db-ui-active table.sidebar-table, body.db-ui-active table.sidebar-table tbody { display: block !important; width: 100% !important; border: 0; }
body.db-ui-active table.sidebar-table tr { display: grid !important; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)) !important; gap: 8px; margin-bottom: 8px; }
body.db-ui-active .sidebar-table td { display: block; width: auto; border: 0; padding: 0; }
body.db-ui-active .wpcf7 input[type="submit"] { margin-left: 0; }
body.db-ui-active aside#secondary.sidebar { float: none !important; width: min(920px, 100%) !important; max-width: 100%; margin: 24px auto 0 !important; padding: 0 !important; }
body.db-ui-active aside#secondary .widget, body.db-ui-active aside#secondary > section { margin-bottom: 16px; }
body.db-ui-active .sidebar-table td a { display: flex; align-items: center; width: 100%; }
/* Footer social badges: glyph centred in its circle. */
body.db-ui-active .site-footer .footer-sociallinks a { padding: 0 !important; min-height: 44px; display: inline-flex; align-items: center; justify-content: center; }
body.db-ui-active .site-footer .db-icon-badge { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,.1); color: #fff; }
body.db-ui-active .site-footer .db-icon-badge svg { width: 18px; height: 18px; display: block; }
/* 404: centred, footer kept at the bottom of short pages. */
body.db-ui-active #page { min-height: 100vh; display: flex; flex-direction: column; }
body.db-ui-active #page > #content { flex: 1 0 auto; }
body.db-ui-active .error-404 { text-align: center; padding: clamp(32px, 8vw, 72px) 0; }
body.db-ui-active .error-404 h1, body.db-ui-active .error-404 .btn { margin-left: auto; margin-right: auto; }
body.db-ui-active .error-404 .btn { display: inline-flex; align-items: center; justify-content: center; min-height: 48px; padding: 0 22px; }
body.db-ui-active #page.check-out #main.site-main { background: none !important; box-shadow: none !important; border: 0 !important; padding: 0 !important; max-width: 760px; margin: 0 auto !important; }
body.db-ui-active #page.check-out #primary_info, body.db-ui-active #page.check-out .billing_cnt { float: none !important; width: auto !important; max-width: none !important; }
@media (max-width: 600px) {
	body.db-ui-active .banner_bg .best_offer { display: block; float: none; text-align: center; }
	body.db-ui-active .banner_bg .offer_text { width: 100% !important; margin: 8px 0 0 !important; overflow: visible; text-align: center; }
}
/* Touch targets: at least 44px on touch screens. */
@media (hover: none) and (pointer: coarse) {
	body.db-ui-active :is(.page-numbers, .db-breadcrumbs a, .sidebar-table td a, .site-footer li a) { min-height: 44px; display: inline-flex; align-items: center; }
}

/* ===== 15. Foldables and dual-screen ====================================== */
/* Cover screens of foldables are ~344px wide: tighten gutters and type. */
@media (max-width: 359px) {
	body.db-ui-active { --r-gutter: 14px; }
	body.db-ui-active .db-domain-grid { grid-template-columns: 1fr !important; }
	body.db-ui-active h1 { font-size: 26px; }
}
/* Unfolded inner screens (≈600–900px) get a real two-column grid instead of
   a stretched phone layout. */
@media (min-width: 600px) and (max-width: 991px) {
	body.db-ui-active .db-domain-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
	body.db-ui-active .db-rec-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
/* A hinge down the middle (Surface Duo, spanned windows): keep text and
   controls off the fold by padding the content away from it. */
@media (horizontal-viewport-segments: 2) {
	body.db-ui-active #content.site-content { padding-left: calc(env(viewport-segment-right 0 0, 50vw) + 16px); }
}
/* Folded "flex" posture (a short window): keep the sticky header slim. */
@media (max-height: 460px) and (orientation: landscape) {
	body.db-ui-active { padding-top: 56px !important; }
	body.db-ui-active .site-header .domain-search { height: 56px; }
}
/* Respect the device's safe areas (notches, rounded fold edges). */
body.db-ui-active #content.site-content, body.db-ui-active .site-footer .container { padding-left: max(var(--r-gutter), env(safe-area-inset-left)); padding-right: max(var(--r-gutter), env(safe-area-inset-right)); }
@media (prefers-reduced-motion: reduce) { body.db-ui-active * { scroll-behavior: auto !important; transition: none !important; } }
		<?php
		return (string) ob_get_clean();
	}
}

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}
	if ( ! function_exists( 'db_external_style' ) || ! db_external_style( 'design', db_design_css() ) ) {
		add_action( 'wp_head', function () {
			echo '<style id="db-design">' . db_design_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}, 99 );
	}
}, 100 );

// An empty text widget still renders as an empty white card in the sidebar.
add_filter( 'widget_display_callback', function ( $instance, $widget ) {
	if ( $widget instanceof WP_Widget_Text && is_array( $instance )
		&& '' === trim( wp_strip_all_tags( (string) ( $instance['text'] ?? '' ) ) )
		&& '' === trim( (string) ( $instance['title'] ?? '' ) )
		&& false === stripos( (string) ( $instance['text'] ?? '' ), '<img' ) ) {
		return false;
	}
	return $instance;
}, 10, 2 );
