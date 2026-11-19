<?php
/**
 * Plugin Name:       DB All Domains Redesign
 * Description:       Front-end redesign of the All Domains marketplace page (banner, search &amp; filters, Grid View, List View). Presentation only: reuses the existing filters, sorting, view toggle, Buy Now and Make an Offer flows without changing them.
 * Version:           1.0.0
 * Requires at least: 6.3
 * Requires PHP:      7.4
 * Author:            Mindshare Consulting Inc.
 * License:           GPL-2.0-or-later
 * Text Domain:       db-all-domains-redesign
 *
 * @package DB_All_Domains_Redesign
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DBR_ADR_VERSION', '1.0.0' );
define( 'DBR_ADR_FILE', __FILE__ );
define( 'DBR_ADR_DIR', plugin_dir_path( __FILE__ ) );
define( 'DBR_ADR_URL', plugin_dir_url( __FILE__ ) );

/**
 * Whether the current request is the All Domains page.
 *
 * Matches the page slug "all-domains" (page ID 22 on beta). Override with the
 * `dbr_all_domains_is_target` filter if the slug or ID changes.
 *
 * @return bool
 */
function dbr_adr_is_target_page() {
	$is_target = ! is_admin() && is_page( 'all-domains' );

	/**
	 * Filters whether the All Domains redesign assets load on this request.
	 *
	 * @param bool $is_target Default decision.
	 */
	return (bool) apply_filters( 'dbr_all_domains_is_target', $is_target );
}

/**
 * Whether the current request is one of the domain listing pages that share
 * the .dbx content block.
 *
 * Override the slug list with the `dbr_listing_page_slugs` filter.
 *
 * @return bool
 */
function dbr_adr_is_listing_page() {
	if ( is_admin() ) {
		return false;
	}

	$slugs = (array) apply_filters(
		'dbr_listing_page_slugs',
		array(
			'all-domains',
			'4-character-domains',
			'5-character-domains',
			'6-character-domains',
			'sold',
		)
	);

	return is_page( $slugs );
}

/**
 * Cache-busting version for an asset: file modification time, falling back to
 * the plugin version when the file cannot be stat'ed.
 *
 * @param string $relative_path Path relative to the plugin directory.
 * @return string
 */
function dbr_adr_asset_version( $relative_path ) {
	$path  = DBR_ADR_DIR . ltrim( $relative_path, '/' );
	$mtime = is_readable( $path ) ? filemtime( $path ) : false;

	return $mtime ? DBR_ADR_VERSION . '.' . $mtime : DBR_ADR_VERSION;
}

/**
 * Enqueue the redesign stylesheet and the presentation script.
 *
 * Runs late (priority 999) so the stylesheet is printed after the theme and
 * db-blocks styles it overrides.
 */
function dbr_adr_enqueue_assets() {
	if ( dbr_adr_is_listing_page() ) {
		wp_enqueue_style(
			'dbr-listing-pages',
			DBR_ADR_URL . 'assets/css/listing-pages.css',
			array(),
			dbr_adr_asset_version( 'assets/css/listing-pages.css' )
		);
	}

	/**
	 * Filters the "browse by length" pages that receive the length-pages.css
	 * redesign, as slug => page stylesheet (relative to assets/css/). The page
	 * stylesheet supplies the banner image and the toolbar heading.
	 *
	 * @param array<string,string> $pages Slug => stylesheet file.
	 */
	$length_pages = (array) apply_filters(
		'dbr_length_pages',
		array(
			'4-character-domains' => 'four-character.css',
			'5-character-domains' => 'five-character.css',
			'6-character-domains' => 'six-character.css',
			'sold'                => 'sold.css',
		)
	);

	if ( ! is_admin() ) {
		foreach ( $length_pages as $slug => $page_css ) {
			if ( ! is_page( $slug ) ) {
				continue;
			}

			wp_enqueue_style(
				'dbr-length-pages',
				DBR_ADR_URL . 'assets/css/length-pages.css',
				array( 'dbr-listing-pages' ),
				dbr_adr_asset_version( 'assets/css/length-pages.css' )
			);

			$page_css = sanitize_file_name( (string) $page_css );
			if ( '' !== $page_css && is_readable( DBR_ADR_DIR . 'assets/css/' . $page_css ) ) {
				wp_enqueue_style(
					'dbr-length-page-' . sanitize_key( $slug ),
					DBR_ADR_URL . 'assets/css/' . $page_css,
					array( 'dbr-length-pages' ),
					dbr_adr_asset_version( 'assets/css/' . $page_css )
				);
			}
			break;
		}
	}

	/**
	 * Filters the slug of the Find a Domain page that receives the
	 * find-a-domain.css / find-a-domain.js redesign.
	 *
	 * @param string $slug Page slug.
	 */
	$finder_slug = (string) apply_filters( 'dbr_find_a_domain_page_slug', 'find-a-domain' );
	if ( ! is_admin() && is_page( $finder_slug ) ) {
		wp_enqueue_style(
			'dbr-find-a-domain',
			DBR_ADR_URL . 'assets/css/find-a-domain.css',
			array(),
			dbr_adr_asset_version( 'assets/css/find-a-domain.css' )
		);

		wp_enqueue_script(
			'dbr-find-a-domain',
			DBR_ADR_URL . 'assets/js/find-a-domain.js',
			array(),
			dbr_adr_asset_version( 'assets/js/find-a-domain.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	if ( ! dbr_adr_is_target_page() ) {
		return;
	}

	wp_enqueue_style(
		'dbr-all-domains',
		DBR_ADR_URL . 'assets/css/all-domains.css',
		array(),
		dbr_adr_asset_version( 'assets/css/all-domains.css' )
	);

	wp_enqueue_script(
		'dbr-all-domains',
		DBR_ADR_URL . 'assets/js/all-domains.js',
		array(),
		dbr_adr_asset_version( 'assets/js/all-domains.js' ),
		array(
			'in_footer' => true,
			'strategy'  => 'defer',
		)
	);
}
add_action( 'wp_enqueue_scripts', 'dbr_adr_enqueue_assets', 999 );

/**
 * Preload the banner illustration so it does not pop in after first paint.
 */
function dbr_adr_preload_hero() {
	if ( ! dbr_adr_is_target_page() ) {
		return;
	}

	printf(
		'<link rel="preload" as="image" type="image/webp" href="%s" fetchpriority="high">' . "\n",
		esc_url( DBR_ADR_URL . 'assets/img/hero-domain.webp' )
	);
}
add_action( 'wp_head', 'dbr_adr_preload_hero', 2 );
