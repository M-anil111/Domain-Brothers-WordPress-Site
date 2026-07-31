<?php
/**
 * DB On-Page SEO Block — noscript fallback, image lazy-loading, 404 recovery
 *
 * Live audit of a real domain page (beta.domainbrothers.com/domains/fatality-ca/)
 * against a standard on-page SEO checklist found three genuine gaps —
 * everything else on the list (canonical tags, Open Graph/Twitter cards, a
 * meta keywords tag, BreadcrumbList structured data, rel=next/prev
 * pagination) was already implemented elsewhere in this plugin or the theme.
 * Deliberately NOT implemented from that same checklist: deliberately
 * stuffing extra "hidden text" beyond the legitimate .sr-only accessibility
 * use already on this site (footer social links) risks tripping Google's
 * hidden-text/cloaking guidelines for no real benefit: renaming already-live
 * uploaded image files (breaks every existing reference across hundreds of
 * posts for a minor, unproven ranking signal); content-strategy items
 * (anchor text, "keywordless" writing, content layering) are editorial
 * decisions, not something to patch in code.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

/* ─── 1. Descriptive <noscript> fallback ─────────────────────────────────── */

if ( ! function_exists( 'db_onpage_noscript_html' ) ) {
	function db_onpage_noscript_html() {
		$html  = '<noscript><div class="db-noscript-fallback" style="max-width:960px;margin:16px auto;padding:16px 20px;font-family:sans-serif;line-height:1.5;">';
		$html .= '<p>Domain Brothers is a premium domain name marketplace and brokerage — escrow-protected transfers and 0%-interest payment plans on every listing. Some page features on this site need JavaScript; the links below work without it:</p>';
		$html .= '<ul>';
		$html .= '<li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse All Domains</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/our-services/' ) ) . '">Our Services</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/news/' ) ) . '">News</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Contact Us</a></li>';
		$html .= '</ul></div></noscript>';
		return $html;
	}
}

if ( ! function_exists( 'db_onpage_inject_noscript' ) ) {
	function db_onpage_inject_noscript( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		$pos = stripos( $html, '<body' );
		if ( false === $pos ) {
			return $html;
		}
		$tag_end = strpos( $html, '>', $pos );
		if ( false === $tag_end ) {
			return $html;
		}
		$insert_at = $tag_end + 1;
		return substr( $html, 0, $insert_at ) . db_onpage_noscript_html() . substr( $html, $insert_at );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_onpage_inject_noscript' );
}, 23 );

/* ─── 2. Lazy-load images that aren't already loading-eager ──────────────── */

if ( ! function_exists( 'db_onpage_lazy_load_images' ) ) {
	/**
	 * The custom logo already carries loading="eager" fetchpriority="high"
	 * (WordPress core sets this automatically) — every other <img> on the
	 * site, audited live, had no loading attribute at all: trust-badge
	 * icons, footer logos, news thumbnails. Only adding the attribute where
	 * one isn't already present respects that existing eager/priority
	 * choice instead of fighting it.
	 */
	function db_onpage_lazy_load_images( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}
		return preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) {
			$tag = $m[0];
			if ( false !== stripos( $tag, 'loading=' ) ) {
				return $tag;
			}
			return substr_replace( $tag, ' loading="lazy"', 4, 0 );
		}, $html );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_onpage_lazy_load_images' );
}, 24 );

/* ─── 3. 404 page: search box + way back to real content ─────────────────── */

if ( ! function_exists( 'db_onpage_404_helper_html' ) ) {
	function db_onpage_404_helper_html() {
		$html  = '<div class="db-404-helper">';
		$html .= '<p>Here are a few places to start instead:</p>';
		$html .= '<form role="search" method="get" class="db-404-search" action="' . esc_url( home_url( '/' ) ) . '">';
		$html .= '<label class="screen-reader-text" for="db-404-s">Search</label>';
		$html .= '<input type="search" id="db-404-s" name="s" placeholder="Search domains or pages…" value="">';
		$html .= '<button type="submit">Search</button>';
		$html .= '</form>';
		$html .= '<ul class="db-404-links">';
		$html .= '<li><a href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse All Domains</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/our-services/' ) ) . '">Our Services</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/news/' ) ) . '">News</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Contact Us</a></li>';
		$html .= '</ul></div>';
		$html .= '<style>.db-404-helper{max-width:420px;margin:28px auto 0;text-align:left;}';
		$html .= '.db-404-helper p{color:#5a6b85;font-size:15px;margin-bottom:12px;}';
		$html .= '.db-404-search{display:flex;gap:8px;margin-bottom:18px;}';
		$html .= '.db-404-search input{flex:1;padding:10px 14px;border:1px solid #d7e0ee;border-radius:8px;font-size:15px;}';
		$html .= '.db-404-search button{padding:10px 18px;border:0;border-radius:8px;background:#0a1628;color:#fff;font-size:14px;cursor:pointer;}';
		$html .= '.db-404-links{list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:10px 18px;justify-content:center;}';
		$html .= '.db-404-links a{color:#1857e0;font-size:14px;text-decoration:underline;}</style>';
		return $html;
	}
}

if ( ! function_exists( 'db_onpage_fix_404_page' ) ) {
	function db_onpage_fix_404_page( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		$marker = 'Back to Home</a>';
		$pos    = strpos( $html, $marker );
		if ( false === $pos ) {
			return $html;
		}
		$insert_at = $pos + strlen( $marker );
		return substr( $html, 0, $insert_at ) . db_onpage_404_helper_html() . substr( $html, $insert_at );
	}
}

add_action( 'template_redirect', function () {
	if ( ! is_404() ) {
		return;
	}
	ob_start( 'db_onpage_fix_404_page' );
}, 25 );
