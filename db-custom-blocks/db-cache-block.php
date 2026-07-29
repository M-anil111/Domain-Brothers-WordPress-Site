<?php
/**
 * DB Cache Block — never-cache rules for pages full-page caching must not touch
 *
 * The site had no server-side page cache at all (confirmed live: every HTML
 * response carried "Cache-Control: no-cache, must-revalidate, max-age=0" and
 * no Age/X-Cache header) — every single request re-ran the full WordPress
 * bootstrap, which is almost certainly the real cause of the ~1s TTFB on
 * every page, domain listings included. Hostinger's stack is LiteSpeed
 * (confirmed via the x-litespeed-cache-control response header), so
 * LiteSpeed Cache — the free, official plugin for that server — is the
 * right tool, installed and activated alongside this file.
 *
 * This file is the safety net that has to exist BEFORE full-page caching is
 * ever switched on: several pages render content that depends on the
 * request itself (a chosen domain, a price, an installment plan encoded in
 * ?d=/?p=/?m=/?i=/?rn=, or search results) and must never be cached, or one
 * visitor's checkout could get served to the next visitor. Marks those
 * requests no-cache via LiteSpeed's own documented API
 * (litespeed_control_set_nocache) rather than relying solely on the plugin's
 * admin-UI exclusion list, so the protection ships as auditable code and
 * survives even if a future admin resets the plugin's settings.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_cache_never_cache_slugs' ) ) {
	/**
	 * Page slugs whose content is tied to the specific request rather than
	 * the URL alone — the same set db_safety_template_guards() already
	 * treats as transactional, plus /offer/ (a bare form, but one that
	 * echoes back query-string state via JS) and /thank-you/ (order
	 * confirmation).
	 */
	function db_cache_never_cache_slugs() {
		return array(
			'checkout',
			'payment-plan-setup',
			'payment-successful',
			'installment-detail-admin',
			'buy-now',
			'offer',
			'thank-you',
		);
	}
}

if ( ! function_exists( 'db_cache_should_skip' ) ) {
	function db_cache_should_skip() {
		if ( is_admin() || is_user_logged_in() || is_search() ) {
			return true;
		}
		// Any of these query args mean the response is specific to this
		// one request (a chosen domain + price + installment plan), not
		// reusable for the next visitor of the same URL.
		foreach ( array( 'd', 'p', 'rn', 'm', 'i', 'lis' ) as $key ) {
			if ( isset( $_GET[ $key ] ) ) {
				return true;
			}
		}
		if ( is_page() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post && in_array( $post->post_name, db_cache_never_cache_slugs(), true ) ) {
				return true;
			}
		}
		return false;
	}
}

add_action( 'template_redirect', function () {
	if ( db_cache_should_skip() && function_exists( 'do_action' ) ) {
		do_action( 'litespeed_control_set_nocache', 'db-transactional-page' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // the generic signal most other cache layers/plugins also honor
		}
	}
}, 1 );

if ( ! function_exists( 'db_cache_manual_purge' ) ) {
	/**
	 * LiteSpeed Cache's own "Purge All" is AJAX-only in its admin UI — there
	 * is no plain link/form to trigger it, which makes it unreachable from
	 * anywhere except a real browser click. Deploying a code fix that
	 * changes page markup (e.g. correcting the hardcoded Make-an-Offer form
	 * ID) doesn't itself invalidate already-cached pages, so without this,
	 * visitors would keep getting the stale pre-fix HTML for up to the full
	 * TTL.
	 *
	 * The purge signal LiteSpeed Cache emits for litespeed_purge_all is an
	 * X-LiteSpeed-Purge response header, added by the plugin's own hook
	 * somewhere in the normal send_headers -> ... -> shutdown request
	 * lifecycle. Two earlier versions of this function both failed to
	 * actually purge anything (confirmed via x-litespeed-cache staying
	 * "hit" immediately after): calling wp_die() right after do_action()
	 * short-circuits the request before that header gets attached, and
	 * deferring to a shutdown callback is too late — wp_die() has already
	 * flushed headers to the client by then. Firing this at template_redirect
	 * (same stage db_cache_should_skip() already uses successfully for the
	 * no-cache signal) and letting the request render normally afterward —
	 * no wp_die() — is what actually lets LiteSpeed attach the header.
	 */
	function db_cache_manual_purge() {
		if ( ! isset( $_GET['db_purge_cache'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		do_action( 'litespeed_purge_all' );
	}
}
add_action( 'template_redirect', 'db_cache_manual_purge', 1 );
