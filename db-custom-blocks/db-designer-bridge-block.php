<?php
/**
 * Bridge to the designer's "DB All Domains Redesign" plugin
 * (wp-content/plugins/db-all-domains-redesign, by Mindshare Consulting).
 *
 * That plugin restyles /all-domains/, the 4/5/6-character lists, /sold/ and
 * /find-a-domain/. Its files are not edited here; this block only uses the
 * filters it provides and its own assets so the same design reaches:
 *
 *  1. /find-a-domain/ — a virtual route rendered by db-sales-block.php, where
 *     the plugin's is_page() check is always false, so its CSS and JS never
 *     loaded.
 *  2. /3-character-domains/, /numeric-domains-for-sale/ and
 *     /premium-domain-deals/ — the same listing template as the 4/5/6 pages,
 *     which the plugin's slug lists did not include.
 *
 * Nothing runs when the designer's plugin is inactive.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_designer_active' ) ) {
	/**
	 * @return bool Whether the designer's plugin is loaded.
	 */
	function db_designer_active() {
		return defined( 'DBR_ADR_URL' ) && function_exists( 'dbr_adr_asset_version' );
	}
}

if ( ! function_exists( 'db_designer_extra_lists' ) ) {
	/**
	 * Listing pages that get the designer's "browse by length" design, with
	 * the banner illustration and the toolbar heading for each.
	 *
	 * @return array<string,array{img:string,ratio:string,title:string}>
	 */
	function db_designer_extra_lists() {
		return array(
			'3-character-domains'      => array( 'img' => 'hero-domain.webp', 'ratio' => '720 / 480', 'title' => 'Available 3-character domains' ),
			'numeric-domains-for-sale' => array( 'img' => 'hero-domain.webp', 'ratio' => '720 / 480', 'title' => 'Available numeric domains' ),
			'premium-domain-deals'     => array( 'img' => 'hero-domain.webp', 'ratio' => '720 / 480', 'title' => 'Domains on special deals' ),
		);
	}
}

// Shared listing fixes (header-width content, wider guide block) on the extra pages.
add_filter( 'dbr_listing_page_slugs', function ( $slugs ) {
	return array_values( array_unique( array_merge( (array) $slugs, array_keys( db_designer_extra_lists() ) ) ) );
} );

// The "browse by length" stylesheet on the extra pages. The page file named
// here does not exist in the designer's plugin on purpose: the plugin then
// loads only the shared length-pages.css, and the page values come below.
add_filter( 'dbr_length_pages', function ( $pages ) {
	foreach ( array_keys( db_designer_extra_lists() ) as $slug ) {
		if ( ! isset( $pages[ $slug ] ) ) {
			$pages[ $slug ] = 'db-bridge-' . $slug . '.css';
		}
	}
	return $pages;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() || ! db_designer_active() ) {
		return;
	}

	// 2. Page values for the extra listing pages (same custom properties as the
	// designer's four-character.css).
	foreach ( db_designer_extra_lists() as $slug => $page ) {
		if ( ! is_page( $slug ) || ! wp_style_is( 'dbr-length-pages', 'enqueued' ) ) {
			continue;
		}
		$css = 'html body.dbp-listing.dbp-listing{--dbl-hero-img:url("' . esc_url_raw( DBR_ADR_URL . 'assets/img/' . $page['img'] ) . '");'
			. '--dbl-hero-ratio:' . $page['ratio'] . ';--dbl-tools-title:"' . $page['title'] . '"}';
		wp_add_inline_style( 'dbr-length-pages', $css );
		break;
	}

	// 1. Find a domain (virtual route).
	if ( function_exists( 'db_sales_request_path' ) && 'find-a-domain' === db_sales_request_path() ) {
		wp_enqueue_style( 'dbr-find-a-domain', DBR_ADR_URL . 'assets/css/find-a-domain.css', array(), dbr_adr_asset_version( 'assets/css/find-a-domain.css' ) );
		wp_enqueue_script( 'dbr-find-a-domain', DBR_ADR_URL . 'assets/js/find-a-domain.js', array(), dbr_adr_asset_version( 'assets/js/find-a-domain.js' ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}
}, 1000 );

/*
 * 3. The designer's visual language on the rest of the site (this plugin's own
 * markup only): the lime eyebrow pill used on the designer's dark banners, the
 * header-width content area on the other marketplace pages, and the wider
 * guide block. Colours are the designer's: lime #b5e61d on navy #0f1b2d, soft
 * lime #eef8d2.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}
	$css = ':root{--dbr-lime:#b5e61d;--dbr-lime-soft:#eef8d2;--dbr-navy:#0f1b2d}'
		// Lime eyebrow pill on dark banners.
		. 'html body .dbs2-hero .dbs2-eyebrow,html body .dbx-tr-hero .dbp-eyebrow,html body .dbh-dark .dbp-eyebrow,html body .dbsv-form .dbp-eyebrow,html body .dbof-aside .dbp-eyebrow{display:inline-flex!important;align-items:center;margin:0 0 16px!important;padding:8px 16px!important;border-radius:999px!important;background:var(--dbr-lime)!important;color:var(--dbr-navy)!important;font-size:12px!important;font-weight:800!important;line-height:1!important;letter-spacing:.1em!important;text-transform:uppercase!important}'
		. 'html body .dbs2-hero .dbs2-eyebrow::before,html body .dbx-tr-hero .dbp-eyebrow::before,html body .dbh-dark .dbp-eyebrow::before,html body .dbsv-form .dbp-eyebrow::before{display:none!important}'
		// Guide block: 1140px everywhere (the designer's width on the listing pages), padding kept.
		. 'html body .dbx .dbx-wrap{max-width:1140px!important;padding:clamp(26px,5vw,60px)!important}'
		. 'html body.db-ui-active .dbx .dbx-eb{background:var(--dbr-lime-soft)!important;color:#3f5208!important}'
		. 'html body .dbx .dbx-list li::before{background-color:var(--dbr-lime-soft)!important}'
		// Header-width content on the other marketplace pages rendered by db-sales-block.php.
		. 'html body:has(main .dbs2-hero) #page #content.site-content{padding-left:0!important;padding-right:0!important}'
		. 'html body:has(main .dbs2-hero) #page #content.site-content>#primary.content-area{box-sizing:border-box!important;float:none!important;width:100%!important;max-width:1360px!important;margin-left:auto!important;margin-right:auto!important;padding-left:clamp(20px,3vw,40px)!important;padding-right:clamp(20px,3vw,40px)!important}'
		. 'html body:has(main .dbs2-hero) #page #content.site-content>#primary.content-area>#main.site-main{max-width:100%!important;margin-left:0!important;margin-right:0!important;padding-left:0!important;padding-right:0!important}';
	if ( function_exists( 'db_external_style' ) && db_external_style( 'designer-bridge', $css ) ) {
		return;
	}
	wp_register_style( 'db-designer-bridge', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-designer-bridge' );
	wp_add_inline_style( 'db-designer-bridge', $css );
}, 1001 );
