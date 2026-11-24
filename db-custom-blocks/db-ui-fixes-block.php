<?php
/**
 * The designer's UI fixes (originally the standalone add-on "Domain Brothers UI Fixes" 1.1.0), bundled so
 * the site runs from one plugin. Audit items DB-06, DB-11, DB-12, DB-14, DB-28, DB-29 and the shared layout
 * (1320px container, 15px gutters, 80/60/45px section padding). Source files: designer/ui-fixes/.
 *
 * Standalone add-on active: this block does nothing, so the two never run together. To switch over,
 * deactivate the add-on; this copy then applies the same fixes with no other change.
 * Identifiers use the prefix dbux so they cannot clash with the add-on's dbuif prefix.
 *
 * @package DB_Custom_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DBUX_VERSION', '1.1.0' );

require_once __DIR__ . '/designer/ui-fixes/includes/html-filters.php';

if ( ! function_exists( 'dbux_standalone_active' ) ) {
	/** True when the designer's separate add-on is running (it defines DBUIF_FILE when loaded). */
	function dbux_standalone_active() {
		return defined( 'DBUIF_FILE' );
	}
}

if ( ! function_exists( 'dbux_is_frontend_html_request' ) ) {
	/** A front-end request that returns a themed HTML document. */
	function dbux_is_frontend_html_request() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || is_embed() ) {
			return false;
		}
		return ! ( function_exists( 'is_favicon' ) && is_favicon() );
	}
}

if ( ! function_exists( 'dbux_is_all_domains_listing' ) ) {
	/** The All Domains page and its paginated views. */
	function dbux_is_all_domains_listing() {
		if ( ! is_page() ) {
			return false;
		}
		$page = get_queried_object();
		return $page instanceof WP_Post && 'all-domains' === $page->post_name;
	}
}

add_action( 'template_redirect', static function () {
	if ( dbux_standalone_active() || ! dbux_is_frontend_html_request() ) {
		return;
	}
	$context = array(
		'domain_archive' => is_post_type_archive( 'domain' ),
		'all_domains'    => dbux_is_all_domains_listing(),
	);
	ob_start( static function ( $html ) use ( $context ) {
		if ( ! is_string( $html ) || '' === $html || false === stripos( $html, '<html' ) ) {
			return $html;
		}
		return dbux_filter_html( $html, $context );
	} );
}, -999 ); // Directly inside the page-cache capture buffer (-1000), so saved copies carry the fixes.

add_action( 'wp_enqueue_scripts', static function () {
	if ( dbux_standalone_active() ) {
		return;
	}
	$base = plugins_url( 'designer/ui-fixes/assets/', __FILE__ );
	$dir  = __DIR__ . '/designer/ui-fixes/assets/';
	wp_enqueue_style( 'dbux-ui-fixes', $base . 'ui-fixes.css', array(), DBUX_VERSION . '.' . (int) @filemtime( $dir . 'ui-fixes.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	wp_enqueue_style( 'dbux-layout', $base . 'layout.css', array( 'dbux-ui-fixes' ), DBUX_VERSION . '.' . (int) @filemtime( $dir . 'layout.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	// Template fixes and the shared FAQ component (designer's add-on 1.2.1, 2026-11-23), after layout.css.
	wp_enqueue_style( 'dbux-layout-fixes', $base . 'layout-fixes.css', array( 'dbux-layout' ), DBUX_VERSION . '.' . (int) @filemtime( $dir . 'layout-fixes.css' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( dbux_is_all_domains_listing() ) {
		wp_enqueue_script( 'dbux-listing-state', $base . 'listing-state.js', array(), DBUX_VERSION . '.' . (int) @filemtime( $dir . 'listing-state.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		wp_localize_script( 'dbux-listing-state', 'dbuxListing', array(
			'indexUrl' => esc_url_raw( add_query_arg( 'ver', DB_BLOCKS_LOADED, rest_url( 'db/v1/domain-index' ) ) ),
			'basePath' => wp_make_link_relative( get_permalink( get_queried_object_id() ) ),
			// Names for sale, so page 1 does not download itself again to count them (LS-14).
			'total'    => function_exists( 'db_domain_index' ) ? count( (array) db_domain_index() ) : 0,
		) );
	}
}, 100 );
