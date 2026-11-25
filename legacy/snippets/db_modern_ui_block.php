<?php
/* === DB modern UI overhaul ===
 *
 * Global CSS modernisation of the DomainFolio theme and all custom blocks.
 * Applied via wp_head without touching any theme file.
 *
 * DESIGN SYSTEM
 *   - CSS custom properties (variables) define the entire colour and spacing
 *     system so future tweaks need only change a single value.
 *   - Neutral palette: dark navy primary, electric blue accent, warm greys.
 *   - Typography: -apple-system / Inter / system-ui stack; tighter tracking on
 *     headings; generous line-height on body copy.
 *   - Components: domain cards, price badges, buttons, forms (CF7), navigation,
 *     footer, modals/overlays.
 *   - Mobile-first breakpoints: 480 px, 768 px, 1024 px.
 *   - Respects prefers-reduced-motion: all transitions guarded.
 *   - Respects prefers-color-scheme: dark (system-level dark mode support).
 *
 * WHAT IS TARGETED
 *   Domain listing cards — .domain-listing, .domain-card, article (post type domain)
 *   Prices              — .domain-price, .price, [class*="price"]
 *   BIN / offer buttons — .buy-btn, .btn-buy, .offer-btn, .add-to-cart
 *   CF7 forms           — .wpcf7-form inputs, textareas, selects, submit
 *   Navigation          — header nav, .main-navigation, .nav-menu
 *   Footer              — site footer, .site-footer, footer
 *   Page layouts        — .entry-content, .page-content, .site-content
 *   Our own blocks      — .db-plan, .db-news-*, .db-sitemap, .db-hp-*
 *
 * NOTE ON SPECIFICITY
 *   All our selectors use a single extra class (.db-ui-active on <body>) or are
 *   written with enough context to win over theme defaults without using !important
 *   unless absolutely necessary to override inline styles.
 *
 * Paste at the end of functions.php. Self-contained.
 */

/* Mark <body> so our scoped selectors get specificity without !important spam. */
add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'db-ui-active';
	return $classes;
} );

add_action( 'wp_head', function () {
	?>
<style id="db-modern-ui">

/* ==========================================================================
   0. DESIGN TOKENS — change here to retheme the whole site
   ========================================================================== */
:root {
	/* Brand palette */
	--db-navy:        #060d24;
	--db-navy-mid:    #0b2250;
	--db-blue:        #0a6ed1;
	--db-blue-dark:   #085bb0;
	--db-blue-light:  #e8f1fb;
	--db-green:       #137a3e;
	--db-green-bg:    #e7f6ec;
	--db-red:         #d63638;
	--db-red-bg:      #fef2f2;

	/* Greys */
	--db-gray-50:     #f9fafb;
	--db-gray-100:    #f3f4f6;
	--db-gray-200:    #e5e7eb;
	--db-gray-300:    #d1d5db;
	--db-gray-400:    #9ca3af;
	--db-gray-500:    #6b7280;
	--db-gray-600:    #4b5563;
	--db-gray-700:    #374151;
	--db-gray-800:    #1f2937;
	--db-gray-900:    #111827;

	/* Typography */
	--db-font:        -apple-system, BlinkMacSystemFont, 'Inter', 'SF Pro Text',
	                  'Segoe UI', Roboto, Ubuntu, Cantarell, sans-serif;
	--db-font-mono:   'SF Mono', 'Fira Code', 'Consolas', monospace;
	--db-text:        var(--db-gray-800);
	--db-text-muted:  var(--db-gray-500);
	--db-text-xmuted: var(--db-gray-400);

	/* Spacing scale (4-px base) */
	--db-sp-1:  4px;
	--db-sp-2:  8px;
	--db-sp-3:  12px;
	--db-sp-4:  16px;
	--db-sp-5:  20px;
	--db-sp-6:  24px;
	--db-sp-8:  32px;
	--db-sp-10: 40px;
	--db-sp-12: 48px;
	--db-sp-16: 64px;

	/* Radii */
	--db-r-sm:  6px;
	--db-r-md:  10px;
	--db-r-lg:  16px;
	--db-r-xl:  24px;
	--db-r-pill: 999px;

	/* Shadows */
	--db-shadow-sm:  0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.05);
	--db-shadow-md:  0 4px 6px rgba(0,0,0,.07), 0 10px 20px rgba(0,0,0,.08);
	--db-shadow-lg:  0 10px 15px rgba(0,0,0,.06), 0 20px 40px rgba(0,0,0,.10);
	--db-shadow-xl:  0 20px 25px rgba(0,0,0,.08), 0 40px 60px rgba(0,0,0,.12);

	/* Transitions */
	--db-ease:       cubic-bezier(.4,0,.2,1);
	--db-ease-out:   cubic-bezier(0,0,.2,1);
	--db-dur-fast:   120ms;
	--db-dur-base:   200ms;
	--db-dur-slow:   350ms;

	/* Layout */
	--db-max-content: 1200px;
	--db-max-prose:   700px;
}

/* Dark-mode token overrides */
@media (prefers-color-scheme: dark) {
	:root {
		--db-text:        #e5e7eb;
		--db-text-muted:  #9ca3af;
		--db-text-xmuted: #6b7280;
		--db-gray-50:     #111827;
		--db-gray-100:    #1f2937;
		--db-gray-200:    #374151;
		--db-gray-300:    #4b5563;
		--db-blue-light:  #1e3a5f;
	}
}

/* ==========================================================================
   1. GLOBAL RESET & BASE
   ========================================================================== */
.db-ui-active {
	font-family: var(--db-font);
	color: var(--db-text);
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
	text-rendering: optimizeLegibility;
	background-color: var(--db-gray-50);
}

.db-ui-active *,
.db-ui-active *::before,
.db-ui-active *::after {
	box-sizing: border-box;
}

/* Modern focus ring — WCAG 2.1 AA */
.db-ui-active :focus-visible {
	outline: 2.5px solid var(--db-blue);
	outline-offset: 3px;
	border-radius: var(--db-r-sm);
}

/* Remove focus ring for mouse users */
.db-ui-active :focus:not(:focus-visible) {
	outline: none;
}

/* Respect reduce-motion preference */
@media (prefers-reduced-motion: reduce) {
	.db-ui-active *,
	.db-ui-active *::before,
	.db-ui-active *::after {
		animation-duration: .01ms !important;
		transition-duration: .01ms !important;
	}
}

/* ==========================================================================
   2. TYPOGRAPHY
   ========================================================================== */
.db-ui-active h1,
.db-ui-active h2,
.db-ui-active h3,
.db-ui-active h4,
.db-ui-active h5,
.db-ui-active h6 {
	font-family: var(--db-font);
	color: var(--db-gray-900);
	line-height: 1.2;
	letter-spacing: -0.025em;
	font-weight: 700;
	margin-top: 0;
}

.db-ui-active h1 { font-size: clamp(28px, 5vw, 48px); margin-bottom: var(--db-sp-5); }
.db-ui-active h2 { font-size: clamp(22px, 4vw, 36px); margin-bottom: var(--db-sp-4); }
.db-ui-active h3 { font-size: clamp(18px, 3vw, 26px); margin-bottom: var(--db-sp-3); }
.db-ui-active h4 { font-size: 18px; margin-bottom: var(--db-sp-2); }

.db-ui-active p {
	line-height: 1.65;
	color: var(--db-gray-700);
	margin: 0 0 var(--db-sp-4);
}

.db-ui-active a {
	color: var(--db-blue);
	text-decoration: none;
	transition: color var(--db-dur-fast) var(--db-ease);
}
.db-ui-active a:hover { color: var(--db-blue-dark); text-decoration: underline; }

/* Prose content areas */
.db-ui-active .entry-content,
.db-ui-active .page-content,
.db-ui-active .post-content,
.db-ui-active .single-content {
	max-width: var(--db-max-prose);
	line-height: 1.7;
}

.db-ui-active .entry-content p,
.db-ui-active .page-content p {
	margin-bottom: var(--db-sp-5);
	font-size: 16px;
}

/* ==========================================================================
   3. LAYOUT & CONTAINERS
   ========================================================================== */
.db-ui-active .site-content,
.db-ui-active #content,
.db-ui-active .content-area {
	background: var(--db-gray-50);
}

.db-ui-active .container,
.db-ui-active .site-container,
.db-ui-active .wrapper {
	max-width: var(--db-max-content);
	margin-left: auto;
	margin-right: auto;
	padding-left: var(--db-sp-6);
	padding-right: var(--db-sp-6);
}

/* ==========================================================================
   4. BUTTONS — global modernisation
   ========================================================================== */
.db-ui-active .button,
.db-ui-active .btn,
.db-ui-active button:not(.db-plan-term):not(.db-svc-toggle),
.db-ui-active input[type="submit"],
.db-ui-active .wp-block-button__link,
.db-ui-active .wpcf7-submit {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	gap: var(--db-sp-2);
	padding: 12px 24px;
	background: var(--db-blue);
	color: #fff !important;
	font-family: var(--db-font);
	font-size: 15px;
	font-weight: 600;
	line-height: 1;
	border: none;
	border-radius: var(--db-r-md);
	cursor: pointer;
	text-decoration: none !important;
	letter-spacing: -0.01em;
	transition: background var(--db-dur-fast) var(--db-ease),
	            transform var(--db-dur-fast) var(--db-ease),
	            box-shadow var(--db-dur-fast) var(--db-ease);
	white-space: nowrap;
}

.db-ui-active .button:hover,
.db-ui-active .btn:hover,
.db-ui-active button:not(.db-plan-term):not(.db-svc-toggle):hover,
.db-ui-active input[type="submit"]:hover,
.db-ui-active .wpcf7-submit:hover {
	background: var(--db-blue-dark);
	color: #fff !important;
	transform: translateY(-1px);
	box-shadow: var(--db-shadow-md);
	text-decoration: none !important;
}

/* Secondary / ghost button */
.db-ui-active .button-secondary,
.db-ui-active .btn-secondary,
.db-ui-active .btn-outline {
	background: transparent !important;
	color: var(--db-blue) !important;
	border: 1.5px solid var(--db-blue) !important;
}
.db-ui-active .button-secondary:hover,
.db-ui-active .btn-secondary:hover {
	background: var(--db-blue-light) !important;
}

/* ==========================================================================
   5. DOMAIN LISTING CARDS
   ========================================================================== */
/*
 * DomainFolio theme uses various class patterns for domain cards.
 * We target the most common ones plus the generic article/post type wrapper.
 * All selectors scoped under .db-ui-active for safety.
 */
.db-ui-active .domain-listing,
.db-ui-active .domain-card,
.db-ui-active .listing-item,
.db-ui-active .domain-item,
.db-ui-active .post-type-domain article,
.db-ui-active .type-domain {
	background: #ffffff;
	border: 1.5px solid var(--db-gray-200);
	border-radius: var(--db-r-lg);
	padding: var(--db-sp-6);
	transition: border-color var(--db-dur-base) var(--db-ease),
	            box-shadow var(--db-dur-base) var(--db-ease),
	            transform var(--db-dur-base) var(--db-ease);
	position: relative;
	overflow: hidden;
}

.db-ui-active .domain-listing:hover,
.db-ui-active .domain-card:hover,
.db-ui-active .listing-item:hover,
.db-ui-active .domain-item:hover {
	border-color: var(--db-blue);
	box-shadow: var(--db-shadow-lg);
	transform: translateY(-2px);
}

/* Domain name */
.db-ui-active .domain-name,
.db-ui-active .domain-title,
.db-ui-active .listing-title,
.db-ui-active .domain-card h2,
.db-ui-active .domain-card h3,
.db-ui-active .listing-item h2,
.db-ui-active .listing-item h3 {
	font-size: clamp(16px, 2.5vw, 22px);
	font-weight: 700;
	color: var(--db-gray-900);
	letter-spacing: -0.02em;
	margin: 0 0 var(--db-sp-3);
	word-break: break-all;
}

/* Price badge */
.db-ui-active .domain-price,
.db-ui-active .listing-price,
.db-ui-active .price-tag,
.db-ui-active [class*="price"] {
	display: inline-flex;
	align-items: center;
	background: var(--db-blue-light);
	color: var(--db-blue-dark);
	font-size: 20px;
	font-weight: 800;
	padding: var(--db-sp-2) var(--db-sp-4);
	border-radius: var(--db-r-pill);
	letter-spacing: -0.02em;
	margin-bottom: var(--db-sp-4);
}

/* Category / extension badge */
.db-ui-active .domain-category,
.db-ui-active .domain-ext,
.db-ui-active .listing-category,
.db-ui-active .domain-tld {
	display: inline-block;
	padding: 3px 10px;
	background: var(--db-gray-100);
	color: var(--db-gray-600);
	font-size: 12px;
	font-weight: 600;
	letter-spacing: .04em;
	text-transform: uppercase;
	border-radius: var(--db-r-pill);
	margin-bottom: var(--db-sp-3);
}

/* Card action buttons row */
.db-ui-active .domain-actions,
.db-ui-active .listing-actions,
.db-ui-active .card-actions {
	display: flex;
	gap: var(--db-sp-2);
	flex-wrap: wrap;
	margin-top: var(--db-sp-4);
}

/* BIN (Buy It Now) button */
.db-ui-active .buy-btn,
.db-ui-active .btn-buy,
.db-ui-active .add-to-cart,
.db-ui-active .buy-now-btn,
.db-ui-active a[class*="buy"] {
	background: var(--db-blue) !important;
	color: #fff !important;
	border-radius: var(--db-r-md) !important;
	padding: 10px 20px !important;
	font-weight: 600 !important;
	font-size: 14px !important;
	transition: background var(--db-dur-fast) !important;
	text-decoration: none !important;
}
.db-ui-active .buy-btn:hover,
.db-ui-active .btn-buy:hover,
.db-ui-active a[class*="buy"]:hover {
	background: var(--db-blue-dark) !important;
}

/* Make-offer button */
.db-ui-active .offer-btn,
.db-ui-active .make-offer,
.db-ui-active a[class*="offer"] {
	background: transparent !important;
	color: var(--db-blue) !important;
	border: 1.5px solid var(--db-blue) !important;
	border-radius: var(--db-r-md) !important;
	padding: 9px 20px !important;
	font-weight: 600 !important;
	font-size: 14px !important;
	text-decoration: none !important;
	transition: background var(--db-dur-fast) !important;
}
.db-ui-active .offer-btn:hover,
.db-ui-active a[class*="offer"]:hover {
	background: var(--db-blue-light) !important;
}

/* Domain listing GRID (if theme uses one) */
.db-ui-active .domain-listings-grid,
.db-ui-active .listings-grid,
.db-ui-active .domains-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: var(--db-sp-5);
}

/* ==========================================================================
   6. NAVIGATION MODERNISATION
   ========================================================================== */
.db-ui-active header,
.db-ui-active .site-header,
.db-ui-active #masthead {
	background: #ffffff;
	border-bottom: 1px solid var(--db-gray-200);
	position: sticky;
	top: 0;
	z-index: 10000;
	backdrop-filter: blur(12px);
	-webkit-backdrop-filter: blur(12px);
	background: rgba(255,255,255,0.92);
}

.db-ui-active .main-navigation a,
.db-ui-active .nav-menu a,
.db-ui-active .primary-menu a,
.db-ui-active header nav a {
	color: var(--db-gray-700);
	font-size: 14px;
	font-weight: 600;
	letter-spacing: -0.01em;
	text-decoration: none;
	padding: 6px 12px;
	border-radius: var(--db-r-sm);
	transition: color var(--db-dur-fast), background var(--db-dur-fast);
}

.db-ui-active .main-navigation a:hover,
.db-ui-active .nav-menu a:hover,
.db-ui-active header nav a:hover {
	color: var(--db-blue);
	background: var(--db-blue-light);
	text-decoration: none;
}

.db-ui-active .main-navigation .current-menu-item > a,
.db-ui-active .nav-menu .current-menu-item > a {
	color: var(--db-blue);
}

/* Search bar in header */
.db-ui-active header .search-form input[type="search"],
.db-ui-active .site-search input {
	border: 1.5px solid var(--db-gray-200);
	border-radius: var(--db-r-pill);
	padding: 8px 16px;
	font-size: 14px;
	background: var(--db-gray-50);
	transition: border-color var(--db-dur-fast);
	outline: none;
}
.db-ui-active header .search-form input[type="search"]:focus,
.db-ui-active .site-search input:focus {
	border-color: var(--db-blue);
	background: #fff;
}

/* ==========================================================================
   7. FORMS — Contact Form 7 modernisation
   ========================================================================== */
.db-ui-active .wpcf7-form,
.db-ui-active .contact-form,
.db-ui-active form {
	/* Base: nothing — we style individual inputs */
}

.db-ui-active .wpcf7-form input[type="text"],
.db-ui-active .wpcf7-form input[type="email"],
.db-ui-active .wpcf7-form input[type="tel"],
.db-ui-active .wpcf7-form input[type="url"],
.db-ui-active .wpcf7-form input[type="number"],
.db-ui-active .wpcf7-form textarea,
.db-ui-active .wpcf7-form select,
.db-ui-active .contact-form input,
.db-ui-active .contact-form textarea {
	width: 100%;
	padding: 12px 16px;
	border: 1.5px solid var(--db-gray-200);
	border-radius: var(--db-r-md);
	font-family: var(--db-font);
	font-size: 15px;
	color: var(--db-gray-800);
	background: #fff;
	transition: border-color var(--db-dur-fast), box-shadow var(--db-dur-fast);
	appearance: none;
	-webkit-appearance: none;
	outline: none;
}

.db-ui-active .wpcf7-form input:focus,
.db-ui-active .wpcf7-form textarea:focus,
.db-ui-active .wpcf7-form select:focus {
	border-color: var(--db-blue);
	box-shadow: 0 0 0 3px rgba(10,110,209,.12);
}

.db-ui-active .wpcf7-form input::placeholder,
.db-ui-active .wpcf7-form textarea::placeholder {
	color: var(--db-gray-400);
}

.db-ui-active .wpcf7-form textarea {
	min-height: 140px;
	resize: vertical;
}

.db-ui-active .wpcf7-form label {
	display: block;
	font-size: 13px;
	font-weight: 600;
	color: var(--db-gray-600);
	text-transform: uppercase;
	letter-spacing: .04em;
	margin-bottom: var(--db-sp-2);
}

.db-ui-active .wpcf7-form .wpcf7-form-control-wrap {
	margin-bottom: var(--db-sp-5);
	display: block;
}

/* CF7 submit button */
.db-ui-active .wpcf7-submit {
	background: var(--db-blue) !important;
	color: #fff !important;
	width: 100%;
	padding: 14px 24px;
	font-size: 16px;
	font-weight: 700;
	border-radius: var(--db-r-md);
	border: none;
	cursor: pointer;
	transition: background var(--db-dur-fast), transform var(--db-dur-fast);
}
.db-ui-active .wpcf7-submit:hover {
	background: var(--db-blue-dark) !important;
	transform: translateY(-1px);
}
.db-ui-active .wpcf7-submit:disabled {
	opacity: .6;
	cursor: not-allowed;
	transform: none;
}

/* CF7 validation messages */
.db-ui-active .wpcf7-not-valid-tip {
	color: var(--db-red);
	font-size: 12px;
	margin-top: 4px;
	display: block;
}
.db-ui-active .wpcf7-response-output {
	padding: 12px 16px;
	border-radius: var(--db-r-md);
	font-size: 14px;
	margin-top: var(--db-sp-4);
	border: none !important;
}
.db-ui-active .wpcf7-mail-sent-ok {
	background: var(--db-green-bg);
	color: var(--db-green);
}
.db-ui-active .wpcf7-mail-sent-ng,
.db-ui-active .wpcf7-validation-errors {
	background: var(--db-red-bg);
	color: var(--db-red);
}

/* ==========================================================================
   8. DOMAIN SINGLE PAGE
   ========================================================================== */
.db-ui-active .single-domain .entry-header,
.db-ui-active .domain-single-header {
	background: linear-gradient(135deg, var(--db-navy) 0%, var(--db-navy-mid) 100%);
	color: #fff;
	padding: var(--db-sp-12) var(--db-sp-6);
	border-radius: var(--db-r-xl);
	margin-bottom: var(--db-sp-8);
	text-align: center;
}

.db-ui-active .single-domain .entry-title,
.db-ui-active .domain-single-header h1 {
	color: #fff;
	font-size: clamp(32px, 6vw, 56px);
	letter-spacing: -0.04em;
	margin-bottom: var(--db-sp-4);
}

/* ==========================================================================
   9. PAGINATION
   ========================================================================== */
.db-ui-active .pagination,
.db-ui-active .page-numbers,
.db-ui-active .nav-links {
	display: flex;
	gap: var(--db-sp-2);
	justify-content: center;
	margin: var(--db-sp-10) 0;
	flex-wrap: wrap;
}

.db-ui-active .page-numbers a,
.db-ui-active .page-numbers span {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	min-width: 40px;
	height: 40px;
	padding: 0 12px;
	border: 1.5px solid var(--db-gray-200);
	border-radius: var(--db-r-md);
	font-size: 14px;
	font-weight: 600;
	color: var(--db-gray-700);
	text-decoration: none;
	transition: all var(--db-dur-fast);
}
.db-ui-active .page-numbers a:hover {
	border-color: var(--db-blue);
	color: var(--db-blue);
	background: var(--db-blue-light);
}
.db-ui-active .page-numbers .current {
	background: var(--db-blue);
	border-color: var(--db-blue);
	color: #fff;
}

/* ==========================================================================
   10. FOOTER
   ========================================================================== */
.db-ui-active footer,
.db-ui-active .site-footer,
.db-ui-active #colophon {
	background: var(--db-navy);
	color: rgba(255,255,255,0.75);
	padding: var(--db-sp-12) var(--db-sp-6) var(--db-sp-8);
	margin-top: var(--db-sp-16);
}

.db-ui-active footer a,
.db-ui-active .site-footer a {
	color: rgba(255,255,255,0.75);
	text-decoration: none;
	transition: color var(--db-dur-fast);
}
.db-ui-active footer a:hover,
.db-ui-active .site-footer a:hover {
	color: #fff;
	text-decoration: none;
}

.db-ui-active .footer-widget h2,
.db-ui-active .footer-widget h3,
.db-ui-active .widget-title {
	color: #fff;
	font-size: 14px;
	text-transform: uppercase;
	letter-spacing: .08em;
	margin-bottom: var(--db-sp-4);
}

.db-ui-active .site-info,
.db-ui-active .footer-credits {
	text-align: center;
	font-size: 13px;
	color: rgba(255,255,255,0.4);
	padding-top: var(--db-sp-6);
	margin-top: var(--db-sp-6);
	border-top: 1px solid rgba(255,255,255,0.1);
}

/* ==========================================================================
   11. RESPONSIVE IMAGES
   ========================================================================== */
.db-ui-active img {
	max-width: 100%;
	height: auto;
	display: block;
}

.db-ui-active .wp-post-image,
.db-ui-active .attachment-thumbnail {
	border-radius: var(--db-r-md);
}

/* ==========================================================================
   12. TABLES
   ========================================================================== */
.db-ui-active table:not(.db-plan-schedule):not(.form-table) {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
	background: #fff;
	border-radius: var(--db-r-md);
	overflow: hidden;
	box-shadow: var(--db-shadow-sm);
}

.db-ui-active table:not(.db-plan-schedule) th,
.db-ui-active table:not(.db-plan-schedule) td {
	padding: 12px 16px;
	text-align: left;
	border-bottom: 1px solid var(--db-gray-100);
}

.db-ui-active table:not(.db-plan-schedule) th {
	font-size: 12px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .04em;
	color: var(--db-gray-500);
	background: var(--db-gray-50);
}

.db-ui-active table:not(.db-plan-schedule) tr:hover td {
	background: var(--db-gray-50);
}

/* ==========================================================================
   13. SEARCH PAGE
   ========================================================================== */
.db-ui-active .search-form {
	display: flex;
	gap: var(--db-sp-2);
	max-width: 600px;
	margin: 0 auto var(--db-sp-8);
}

.db-ui-active .search-field {
	flex: 1;
	padding: 14px 20px;
	border: 2px solid var(--db-gray-200);
	border-radius: var(--db-r-pill);
	font-size: 16px;
	outline: none;
	transition: border-color var(--db-dur-fast);
}
.db-ui-active .search-field:focus {
	border-color: var(--db-blue);
}

.db-ui-active .search-submit {
	padding: 14px 28px;
	border-radius: var(--db-r-pill);
}

/* ==========================================================================
   14. NOTIFICATION / ALERT BARS
   ========================================================================== */
.db-ui-active .notice,
.db-ui-active .alert,
.db-ui-active .message {
	padding: 14px 18px;
	border-radius: var(--db-r-md);
	font-size: 14px;
	margin-bottom: var(--db-sp-4);
	border-left: 4px solid currentColor;
}

.db-ui-active .notice-success,
.db-ui-active .alert-success { background: var(--db-green-bg);  color: var(--db-green); }
.db-ui-active .notice-error,
.db-ui-active .alert-error   { background: var(--db-red-bg);    color: var(--db-red); }
.db-ui-active .notice-info,
.db-ui-active .alert-info    { background: var(--db-blue-light); color: var(--db-blue-dark); }

/* ==========================================================================
   15. DOMAIN CATEGORY FILTER (pill tabs)
   ========================================================================== */
.db-ui-active .domain-categories,
.db-ui-active .listing-filters,
.db-ui-active .category-filter {
	display: flex;
	flex-wrap: wrap;
	gap: var(--db-sp-2);
	margin-bottom: var(--db-sp-6);
}

.db-ui-active .domain-categories a,
.db-ui-active .listing-filters a,
.db-ui-active .category-filter a {
	display: inline-block;
	padding: 6px 16px;
	background: var(--db-gray-100);
	color: var(--db-gray-600);
	border-radius: var(--db-r-pill);
	font-size: 13px;
	font-weight: 600;
	text-decoration: none;
	transition: all var(--db-dur-fast);
}

.db-ui-active .domain-categories a:hover,
.db-ui-active .listing-filters a:hover,
.db-ui-active .domain-categories .current,
.db-ui-active .listing-filters .active {
	background: var(--db-blue);
	color: #fff;
}

/* ==========================================================================
   16. MOBILE REFINEMENTS
   ========================================================================== */
@media (max-width: 768px) {
	.db-ui-active h1 { font-size: 28px; }
	.db-ui-active h2 { font-size: 22px; }
	.db-ui-active h3 { font-size: 18px; }

	.db-ui-active .domain-listings-grid,
	.db-ui-active .listings-grid,
	.db-ui-active .domains-grid {
		grid-template-columns: 1fr;
	}

	.db-ui-active .domain-actions,
	.db-ui-active .listing-actions {
		flex-direction: column;
	}

	.db-ui-active .domain-actions a,
	.db-ui-active .listing-actions a {
		text-align: center;
	}

	.db-ui-active header,
	.db-ui-active .site-header {
		position: relative; /* Avoid sticky on small screens where space is tight */
	}
}

@media (max-width: 480px) {
	.db-ui-active .container,
	.db-ui-active .wrapper {
		padding-left: var(--db-sp-4);
		padding-right: var(--db-sp-4);
	}

	.db-ui-active .wpcf7-submit {
		font-size: 15px;
		padding: 14px;
	}
}

/* ==========================================================================
   17. OUR BLOCK POLISH (overrides that complement the block's own CSS)
   ========================================================================== */

/* Plan widget card refinement */
.db-ui-active .db-plan {
	background: #fff;
	border-radius: var(--db-r-xl);
	padding: var(--db-sp-8);
	box-shadow: var(--db-shadow-md);
	border: 1px solid var(--db-gray-100);
}

/* News cards refinement */
.db-ui-active .db-news-card {
	background: #fff;
	border: 1.5px solid var(--db-gray-200) !important;
	border-radius: var(--db-r-lg) !important;
	box-shadow: none;
	transition: box-shadow var(--db-dur-base), border-color var(--db-dur-base), transform var(--db-dur-base);
}
.db-ui-active .db-news-card:hover {
	box-shadow: var(--db-shadow-md) !important;
	border-color: var(--db-blue) !important;
	transform: translateY(-2px);
}

/* Plan term buttons — keep their own style but add a micro-lift */
.db-ui-active .db-plan-term:hover {
	transform: translateY(-1px);
}

/* ==========================================================================
   18. UTILITIES
   ========================================================================== */
.db-ui-active .text-center { text-align: center; }
.db-ui-active .text-muted  { color: var(--db-text-muted); }
.db-ui-active .visually-hidden {
	position: absolute;
	width: 1px; height: 1px;
	padding: 0; margin: -1px;
	overflow: hidden;
	clip: rect(0,0,0,0);
	white-space: nowrap;
	border: 0;
}

</style>
	<?php
}, 6 ); // priority 6 — just after AEO block (5), before theme's own wp_head

/* Breadcrumb HTML (visual, in page content) — only when RankMath breadcrumbs are off */
add_action( 'wp_head', function () {
	/* Inject breadcrumb CSS once */
	?>
<style id="db-breadcrumb-css">
.db-breadcrumb{display:flex;flex-wrap:wrap;align-items:center;gap:6px;font-size:13px;color:var(--db-text-muted);margin:0 0 var(--db-sp-4);padding:var(--db-sp-3) 0;list-style:none;}
.db-breadcrumb a{color:var(--db-text-muted);text-decoration:none;}
.db-breadcrumb a:hover{color:var(--db-blue);}
.db-breadcrumb-sep{color:var(--db-gray-300);font-size:11px;}
.db-breadcrumb li:last-child{color:var(--db-gray-600);font-weight:600;}
</style>
	<?php
}, 7 );

add_filter( 'the_content', function ( $content ) {
	if ( is_front_page() || is_admin() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( class_exists( 'RankMath' ) ) {
		return $content; // RankMath renders its own breadcrumb
	}

	$crumbs   = array();
	$crumbs[] = '<li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li>';
	$crumbs[] = '<li><span class="db-breadcrumb-sep">›</span></li>';

	if ( is_singular( 'domain' ) ) {
		$crumbs[] = '<li><a href="' . esc_url( home_url( '/domains/' ) ) . '">Domains</a></li>';
		$crumbs[] = '<li><span class="db-breadcrumb-sep">›</span></li>';
		$crumbs[] = '<li>' . esc_html( get_the_title() ) . '</li>';
	} elseif ( is_page() ) {
		global $post;
		if ( $post->post_parent ) {
			$crumbs[] = '<li><a href="' . esc_url( get_permalink( $post->post_parent ) ) . '">' . esc_html( get_the_title( $post->post_parent ) ) . '</a></li>';
			$crumbs[] = '<li><span class="db-breadcrumb-sep">›</span></li>';
		}
		$crumbs[] = '<li>' . esc_html( get_the_title() ) . '</li>';
	} elseif ( is_singular( 'post' ) ) {
		$crumbs[] = '<li><a href="' . esc_url( home_url( '/news/' ) ) . '">News</a></li>';
		$crumbs[] = '<li><span class="db-breadcrumb-sep">›</span></li>';
		$crumbs[] = '<li>' . esc_html( get_the_title() ) . '</li>';
	} else {
		return $content;
	}

	$breadcrumb = '<nav aria-label="Breadcrumb"><ol class="db-breadcrumb">' . implode( '', $crumbs ) . '</ol></nav>';
	return $breadcrumb . $content;
}, 5 );
/* === end DB modern UI overhaul === */
