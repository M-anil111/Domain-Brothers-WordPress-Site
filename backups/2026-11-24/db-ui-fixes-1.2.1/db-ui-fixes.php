<?php
/**
 * Plugin Name: Domain Brothers UI Fixes
 * Description: Design and responsive UI fixes from the beta audit (DB-11, DB-12, DB-06/DB-14, DB-28, DB-29), the shared layout and the 2026-11-23 layout fixes (layout.css sections 3-4). Self-contained add-on: deactivate to roll back. Does not modify Domain Brothers Custom Blocks or the DomainFolio theme.
 * Version:     1.2.1
 * Author:      Domain Brothers
 * License:     Proprietary
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Text Domain: db-ui-fixes
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DBUIF_VERSION', '1.2.1' );
define( 'DBUIF_FILE', __FILE__ );

require_once __DIR__ . '/includes/html-filters.php';

/**
 * Front-end request that returns a themed HTML document.
 */
function dbuif_is_frontend_html_request() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return false;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}
	if ( is_feed() || is_robots() || is_trackback() || is_embed() ) {
		return false;
	}
	if ( function_exists( 'is_favicon' ) && is_favicon() ) {
		return false;
	}
	return true;
}

/**
 * The All Domains marketplace page and its paginated views (/all-domains/page/N/).
 */
function dbuif_is_all_domains_listing() {
	if ( ! is_page() ) {
		return false;
	}
	$page = get_queried_object();
	return $page instanceof WP_Post && 'all-domains' === $page->post_name;
}

/**
 * Collect the request context once the main query is known, then buffer the
 * document so the pure filters in includes/html-filters.php can run on it.
 */
add_action(
	'template_redirect',
	static function () {
		if ( ! dbuif_is_frontend_html_request() ) {
			return;
		}
		$context = array(
			'domain_archive' => is_post_type_archive( 'domain' ),
			'all_domains'    => dbuif_is_all_domains_listing(),
		);
		ob_start(
			static function ( $html ) use ( $context ) {
				if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<html' ) ) {
					return $html;
				}
				return dbuif_filter_html( $html, $context );
			}
		);
	},
	// -999: directly inside Custom Blocks' page-cache capture buffer
	// (db_pc_capture, template_redirect -1000), so the saved copy it serves
	// to later visitors already contains these fixes, and ahead of the
	// Custom Blocks route handlers (e.g. /buy-now/) that render and exit on
	// template_redirect. Redirect responses have no <html> document and pass
	// through the callback unchanged.
	-999
);

/**
 * Saved pages predate this plugin's changes. Clear Custom Blocks' page cache
 * (which also asks LiteSpeed to purge) when this plugin is activated, updated
 * or deactivated, so every visitor gets the current markup.
 */
function dbuif_purge_page_caches() {
	do_action( 'db_page_cache_purge_all' );
	do_action( 'litespeed_purge_all' );
}

add_action(
	'init',
	static function () {
		if ( get_option( 'dbuif_purged_ver' ) !== DBUIF_VERSION ) {
			update_option( 'dbuif_purged_ver', DBUIF_VERSION, false );
			dbuif_purge_page_caches();
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		delete_option( 'dbuif_purged_ver' );
		dbuif_purge_page_caches();
	}
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		$base = plugin_dir_url( DBUIF_FILE ) . 'assets/';
		$dir  = plugin_dir_path( DBUIF_FILE ) . 'assets/';

		// Load after the Custom Blocks combo stylesheet so equal-specificity rules win.
		wp_enqueue_style(
			'dbuif-ui-fixes',
			$base . 'ui-fixes.css',
			array(),
			DBUIF_VERSION . '.' . (int) @filemtime( $dir . 'ui-fixes.css' )
		);
		// Shared container + section rhythm (loaded last so it wins ties).
		wp_enqueue_style(
			'dbuif-layout',
			$base . 'layout.css',
			array( 'dbuif-ui-fixes' ),
			DBUIF_VERSION . '.' . (int) @filemtime( $dir . 'layout.css' )
		);

		// Template fixes and the shared FAQ component (hand-maintained; loaded after the generated layout.css).
		wp_enqueue_style(
			'dbuif-layout-fixes',
			$base . 'layout-fixes.css',
			array( 'dbuif-layout' ),
			DBUIF_VERSION . '.' . (int) @filemtime( $dir . 'layout-fixes.css' )
		);

		if ( dbuif_is_all_domains_listing() ) {
			wp_enqueue_script(
				'dbuif-listing-state',
				$base . 'listing-state.js',
				array(),
				DBUIF_VERSION . '.' . (int) @filemtime( $dir . 'listing-state.js' ),
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_localize_script(
				'dbuif-listing-state',
				'dbuifListing',
				array(
					'indexUrl' => esc_url_raw( rest_url( 'db/v1/domain-index' ) ),
					'basePath' => wp_make_link_relative( get_permalink( get_queried_object_id() ) ),
				)
			);
		}
	},
	// After Custom Blocks / theme styles are registered.
	100
);
