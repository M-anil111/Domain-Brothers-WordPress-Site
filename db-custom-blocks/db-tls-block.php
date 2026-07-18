<?php
/**
 * DB TLS Block — transport security enforcement
 *
 * The SSL certificate itself lives at the host (Hostinger provisions it);
 * this block makes the APPLICATION enforce encrypted transport:
 *
 *  1. 301-redirects any plain-HTTP request to its HTTPS equivalent.
 *  2. Sends HSTS so browsers refuse to ever downgrade (6 months, subdomains).
 *  3. Sends Content-Security-Policy: upgrade-insecure-requests so any
 *     legacy http:// asset reference upgrades automatically instead of
 *     producing mixed-content warnings.
 *
 * HSTS is only sent on HTTPS responses (per spec, it is ignored on HTTP).
 * The redirect skips WP-CLI/cron contexts where no request scheme exists.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

/* ─── 1. Force HTTPS ──────────────────────────────────────────────────────── */

add_action( 'init', function () {
	if ( is_ssl() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}
	// Behind Hostinger's proxy the forwarded header is authoritative.
	if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] ) {
		return;
	}
	if ( empty( $_SERVER['HTTP_HOST'] ) || empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$target = 'https://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
		. sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );

	// Never redirect POSTs blindly (would silently drop the body — e.g. a
	// Stripe webhook that hit http:// by mistake should fail loudly instead).
	if ( 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
		wp_safe_redirect( $target, 301 );
		exit;
	}
}, 1 );

/* ─── 2 + 3. HSTS + upgrade-insecure-requests ─────────────────────────────── */

add_action( 'send_headers', function () {
	if ( ! is_ssl() ) {
		return;
	}
	if ( ! headers_sent() ) {
		header( 'Strict-Transport-Security: max-age=15552000; includeSubDomains' );
		header( 'Content-Security-Policy: upgrade-insecure-requests' );
	}
} );
