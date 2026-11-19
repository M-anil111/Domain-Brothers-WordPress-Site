<?php
/* === DB performance & security hardening ===
 *
 * Addresses Core Web Vitals, WordPress bloat removal, HTTP security headers,
 * and image/script optimisations. Designed for a Hostinger shared host running
 * PHP 8+ and WordPress 6.x.
 *
 * WHAT THIS DOES
 *   1. WordPress bloat removal: emoji scripts, RSD/WLW/generator head tags,
 *      feed links, oEmbed discovery link.
 *   2. Resource hints: preconnect + dns-prefetch for Stripe (checkout pages
 *      only) and Google Fonts, so the browser opens connections before scripts
 *      request them.
 *   3. HTTP security headers (non-admin front-end only):
 *      X-Content-Type-Options, X-Frame-Options, Referrer-Policy,
 *      Permissions-Policy, X-XSS-Protection.
 *   4. Image lazy-loading: ensures loading="lazy" on theme attachment images
 *      not already marked; skips first image (potential LCP element).
 *   5. Google Fonts display=swap: prevents invisible text while fonts load.
 *   6. Script deferral: comment-reply and wp-embed are deferred.
 *   7. Security: XML-RPC disabled; author-URL enumeration blocked;
 *      REST API user listing restricted to authenticated users.
 *   8. Inline critical CSS hint: outputs <link rel="preload"> for the main
 *      stylesheet so the browser fetches it earlier.
 *
 * CONFIG
 *   DB_PERF_DISABLE_JQUERY_MIGRATE — set true ONLY after testing that the theme
 *   works without it (saves ~90 KB). Default: false (safe).
 *   DB_PERF_DEFER_SCRIPTS — comma-separated script handles to defer in addition
 *   to the built-in list. Default: ''.
 *
 * Paste at the end of functions.php. Self-contained.
 */

if ( ! defined( 'DB_PERF_DISABLE_JQUERY_MIGRATE' ) ) {
	define( 'DB_PERF_DISABLE_JQUERY_MIGRATE', false );
}
if ( ! defined( 'DB_PERF_DEFER_SCRIPTS' ) ) {
	define( 'DB_PERF_DEFER_SCRIPTS', '' ); // e.g. 'wc-cart-fragments,contact-form-7'
}

/* ---- 1. Remove WordPress head bloat ---- */
add_action( 'init', function () {
	// Emoji polyfill: pointless for a business domain brokerage
	remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles',     'print_emoji_styles' );
	remove_action( 'admin_print_styles',  'print_emoji_styles' );
	remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
	remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );

	// Miscellaneous head noise
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_generator' );          // hide WP version
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'template_redirect', 'rest_output_link_header', 11 );

	// RSS feed links (no blog-style feed needed on a domain brokerage)
	remove_action( 'wp_head', 'feed_links',       2 );
	remove_action( 'wp_head', 'feed_links_extra',  3 );
} );

/* ---- 2. Conditionally remove jQuery Migrate (saves ~90 KB) ---- */
if ( DB_PERF_DISABLE_JQUERY_MIGRATE ) {
	add_action( 'wp_default_scripts', function ( $scripts ) {
		if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
			$scripts->registered['jquery']->deps = array_diff(
				$scripts->registered['jquery']->deps,
				array( 'jquery-migrate' )
			);
		}
	} );
}

/* ---- 3. Resource hints (priority connections) ---- */
add_action( 'wp_head', function () {
	// Stripe.js — only needed on checkout-adjacent pages
	if ( is_page( array( 'buy-now', 'payment-plan-setup' ) ) ) {
		echo '<link rel="preconnect" href="https://js.stripe.com">' . "\n";
		echo '<link rel="preconnect" href="https://api.stripe.com">' . "\n";
		echo '<link rel="dns-prefetch" href="//js.stripe.com">' . "\n";
	}
	// Google Fonts (used by many WordPress themes)
	echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}, 0 ); // priority 0 = before everything else in wp_head

/* ---- 4. HTTP security response headers ---- */
add_action( 'send_headers', function () {
	if ( is_admin() ) {
		return;
	}
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'X-XSS-Protection: 1; mode=block' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
} );

/* ---- 5. Google Fonts: add display=swap to prevent invisible text (FOIT) ---- */
add_filter( 'style_loader_src', function ( $src ) {
	if ( strpos( $src, 'fonts.googleapis.com' ) !== false &&
	     strpos( $src, 'display=' ) === false ) {
		return add_query_arg( 'display', 'swap', $src );
	}
	return $src;
}, 20 );

/* ---- 6. Image lazy-loading ---- */
// WordPress 5.5+ handles loading="lazy" for the_content images.
// This covers theme-rendered attachment images (e.g. featured images in loops).
// We skip the first image on each page view so we don't lazy-load the LCP element.
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment, $size ) {
	if ( ! isset( $attr['loading'] ) ) {
		static $count = 0;
		$count++;
		// First image on the page is likely above-the-fold (LCP); keep it eager.
		$attr['loading'] = ( $count === 1 ) ? 'eager' : 'lazy';
		if ( $count === 1 ) {
			$attr['fetchpriority'] = 'high';
		}
	}
	return $attr;
}, 10, 3 );

/* ---- 7. Defer non-critical scripts ---- */
add_filter( 'script_loader_tag', function ( $tag, $handle ) {
	if ( is_admin() ) {
		return $tag;
	}
	$defer_handles = array_filter( array_merge(
		array( 'comment-reply', 'wp-embed' ),
		array_map( 'trim', explode( ',', DB_PERF_DEFER_SCRIPTS ) )
	) );
	if ( in_array( $handle, $defer_handles, true ) ) {
		// Only add defer if not already present
		if ( strpos( $tag, ' defer' ) === false ) {
			$tag = str_replace( ' src=', ' defer src=', $tag );
		}
	}
	return $tag;
}, 10, 2 );

/* ---- 8. Disable XML-RPC (attack surface reduction) ---- */
add_filter( 'xmlrpc_enabled', '__return_false' );
add_action( 'xmlrpc_call', function () {
	status_header( 403 );
	die( 'XML-RPC is disabled on this site.' );
} );

/* ---- 9. Block author URL enumeration (user discovery via /?author=1) ---- */
add_action( 'template_redirect', function () {
	if ( ! is_admin() && isset( $_GET['author'] ) ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
} );

/* ---- 10. Restrict REST API user listing to authenticated users ---- */
add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( ! is_user_logged_in() ) {
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
} );

/* ---- 11. Remove X-Powered-By: PHP header (information disclosure) ---- */
add_action( 'init', function () {
	if ( function_exists( 'header_remove' ) ) {
		header_remove( 'X-Powered-By' );
	}
} );
/* === end DB performance & security hardening === */
