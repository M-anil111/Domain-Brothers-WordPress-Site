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
		foreach ( array( 'd', 'p', 'rn', 'm', 'i', 'lis', 'db_update_card' ) as $key ) {
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
		// litespeed_purge_all alone was confirmed unreliable — the header it
		// produces (x-litespeed-purge: public,78a_) did get sent, but pages
		// cached before the deploy kept serving "hit" on the very next
		// request regardless. Setting LiteSpeed's documented raw purge
		// header directly (X-LiteSpeed-Purge: *) is the same mechanism the
		// server-level API uses and doesn't depend on the plugin's own hook
		// producing the right tag.
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Purge: *' );
		}
	}
}
add_action( 'template_redirect', 'db_cache_manual_purge', 1 );

if ( ! function_exists( 'db_db_health_report' ) ) {
	/**
	 * Read-only database health report for admins: /?db_db_report=1
	 * Table sizes, autoloaded-option weight and the biggest autoloaded
	 * options, plus the usual bloat (revisions, auto-drafts, trash, expired
	 * transients, orphaned postmeta). No writes. Used for the QA database
	 * review; safe to keep because it requires manage_options.
	 */
	function db_db_health_report() {
		if ( empty( $_GET['db_db_report'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$r = array();
		$r['tables'] = $wpdb->get_results( $wpdb->prepare(
			'SELECT table_name AS t, ROUND((data_length+index_length)/1048576,2) AS mb, table_rows AS rows_est, engine FROM information_schema.tables WHERE table_schema = %s ORDER BY (data_length+index_length) DESC LIMIT 25',
			DB_NAME
		), ARRAY_A );
		$r['autoload_kb']  = (int) $wpdb->get_var( "SELECT ROUND(SUM(LENGTH(option_value))/1024) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')" );
		$r['autoload_top'] = $wpdb->get_results( "SELECT option_name AS n, ROUND(LENGTH(option_value)/1024,1) AS kb FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') ORDER BY LENGTH(option_value) DESC LIMIT 15", ARRAY_A );
		$r['options_total'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" );
		$r['transients']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'" );
		$r['expired_transients'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%%' AND option_value < %d", time() ) );
		$r['posts_by_type_status'] = $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) c FROM {$wpdb->posts} GROUP BY post_type, post_status ORDER BY c DESC LIMIT 30", ARRAY_A );
		$r['orphan_postmeta'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );
		$r['postmeta_rows'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta}" );
		$r['domain_price_meta_index'] = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->postmeta}", ARRAY_A );
		$r['mysql'] = $wpdb->db_server_info();
		$r['prefix'] = $wpdb->prefix;
		$r['all_tables'] = $wpdb->get_col( $wpdb->prepare( 'SELECT table_name FROM information_schema.tables WHERE table_schema = %s ORDER BY table_name', DB_NAME ) );
		$r['wpforms_forms'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wpforms' AND post_status = 'publish'" );
		$r['wpforms_shortcode_uses'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE '%[wpforms%'" );
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		echo wp_json_encode( $r, JSON_PRETTY_PRINT );
		exit;
	}
}
add_action( 'init', 'db_db_health_report', 99 );

if ( ! function_exists( 'db_db_maintenance' ) ) {
	/**
	 * Safe, reversible database clean-up for admins: /?db_db_maint=1 (dry run)
	 * or /?db_db_maint=1&commit=1. Only touches things WordPress regenerates
	 * or that belong to plugins no longer installed — no posts, no leads, no
	 * tables dropped (those need a backup and a business decision first):
	 *   - expired transients (delete_expired_transients())
	 *   - feed-cache transients stored without an expiry (refetched on use)
	 *   - autoload switched off for options of uninstalled plugins
	 *     (webinarignition_*, wp_automatic_*): ~200 KB was loaded on every
	 *     single request for code that never runs.
	 */
	function db_db_maintenance() {
		if ( empty( $_GET['db_db_maint'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$commit = ! empty( $_GET['commit'] );
		$out    = array( 'commit' => $commit );
		$out['expired_transients'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_%%' AND option_value < %d", time() ) );
		$feeds = $wpdb->get_col( "SELECT o.option_name FROM {$wpdb->options} o LEFT JOIN {$wpdb->options} t ON t.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 12)) WHERE o.option_name LIKE '\\_transient\\_feed\\_%' AND t.option_id IS NULL" );
		$out['feed_transients_without_expiry'] = count( $feeds );
		$orphans = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto') AND (option_name LIKE 'webinarignition\\_%' OR option_name LIKE 'wp\\_automatic\\_%' OR option_name LIKE 'wpautomatic\\_%')", ARRAY_A );
		$out['autoload_off_options'] = count( $orphans );
		$out['autoload_off_kb']      = round( array_sum( wp_list_pluck( $orphans, 'bytes' ) ) / 1024, 1 );
		if ( $commit ) {
			delete_expired_transients( true );
			foreach ( $feeds as $f ) {
				delete_transient( substr( $f, 11 ) );
			}
			foreach ( $orphans as $o ) {
				wp_set_option_autoload( $o['option_name'], false );
			}
			$out['autoload_kb_after'] = (int) $wpdb->get_var( "SELECT ROUND(SUM(LENGTH(option_value))/1024) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto-on','auto')" );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT );
		exit;
	}
}
add_action( 'init', 'db_db_maintenance', 99 );

/*
 * Admin-only request profiler: append ?db_perf=1 to any front-end URL while
 * logged in as an admin to get an HTML comment with the query count, total
 * time, and the slowest queries (timed as the gap to the next query, so it
 * includes the PHP that consumed the result — good enough to spot hot spots
 * without SAVEQUERIES in wp-config). Off unless explicitly requested.
 */
add_action( 'plugins_loaded', function () {
	if ( empty( $_GET['db_perf'] ) ) {
		return;
	}
	$GLOBALS['db_perf_q'] = array();
	$GLOBALS['db_perf_last'] = null;
	add_filter( 'query', function ( $sql ) {
		$now = microtime( true );
		if ( null !== $GLOBALS['db_perf_last'] ) {
			$GLOBALS['db_perf_q'][] = array( $now - $GLOBALS['db_perf_last'][0], $GLOBALS['db_perf_last'][1] );
		}
		$GLOBALS['db_perf_last'] = array( $now, $sql );
		return $sql;
	} );
	add_action( 'shutdown', function () {
		if ( ! current_user_can( 'manage_options' ) || is_admin() || wp_doing_ajax() ) {
			return;
		}
		global $wpdb;
		$q = $GLOBALS['db_perf_q'];
		usort( $q, function ( $a, $b ) { return $b[0] <=> $a[0]; } );
		echo "\n<!-- db_perf: total " . round( timer_stop( 0, 4 ) * 1000 ) . "ms, queries " . (int) $wpdb->num_queries . ", peak mem " . round( memory_get_peak_usage() / 1048576, 1 ) . "MB\n";
		foreach ( array_slice( $q, 0, 12 ) as $row ) {
			echo '  ' . round( $row[0] * 1000, 1 ) . 'ms  ' . str_replace( array( '--', "\n" ), array( '- -', ' ' ), substr( preg_replace( '/\s+/', ' ', $row[1] ), 0, 260 ) ) . "\n";
		}
		echo "-->\n";
	}, 9999 );
}, 0 );
