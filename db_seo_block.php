<?php
/* === DB SEO & sitemap ===
 *
 * Addresses:
 *   1. noindex for checkout/utility pages (buy-now, thank-you, payment-plan-setup)
 *      — they should never appear in Google results.
 *   2. [db_sitemap] shortcode — renders a user-friendly HTML sitemap page. Add
 *      the shortcode to an existing /sitemap/ page in WP admin.
 *   3. RankMath XML sitemap enhancements:
 *      - Ensures the 'domain' custom post type is always included.
 *      - Excludes the checkout/utility pages from the XML sitemap index.
 *   4. Admin trigger /?db_seo_setup=1 (logged in as admin) — applies a recommended
 *      set of RankMath options (sitemap module on, domain CPT on, ping enabled)
 *      and clears the sitemap cache.
 *
 * XML SITEMAP URL: /sitemap_index.xml  (served by RankMath, if its sitemap
 * module is ON). If that URL returns 404, go to:
 *   RankMath → Dashboard → toggle the "Sitemap" module ON.
 * Then submit /sitemap_index.xml to Google Search Console.
 *
 * Paste at the end of functions.php. Self-contained.
 */

/* ---- 1. noindex for checkout/utility pages ---- */

/*
 * When RankMath is active: use its own robots filter so only ONE <meta robots>
 * tag appears in the HTML. Emitting a second tag via wp_head causes conflicting
 * directives — crawlers resolve them differently (only Google reliably takes the
 * most restrictive). This filter avoids the conflict entirely.
 */
add_filter( 'rank_math/frontend/robots', function ( $robots ) {
	$noindex_slugs = array( 'buy-now', 'thank-you', 'payment-plan-setup' );
	if ( is_page( $noindex_slugs ) ) {
		$robots['index']  = 'noindex';
		$robots['follow'] = 'nofollow';
	}
	return $robots;
} );

/*
 * Fallback for sites where RankMath is not installed: emit a plain meta tag.
 * Skipped when RankMath is active because the filter above already handles it.
 */
add_action( 'wp_head', function () {
	if ( is_admin() || class_exists( 'RankMath' ) ) {
		return;
	}
	$noindex_slugs = array( 'buy-now', 'thank-you', 'payment-plan-setup' );
	if ( is_page( $noindex_slugs ) ) {
		echo '<meta name="robots" content="noindex, nofollow">' . "\n";
	}
}, 1 );

/* ---- 2. HTML sitemap shortcode [db_sitemap] ---- */
add_shortcode( 'db_sitemap', function ( $atts ) {
	$atts = shortcode_atts(
		array( 'exclude_slugs' => 'buy-now,thank-you,payment-plan-setup,sitemap' ),
		$atts,
		'db_sitemap'
	);
	$excluded = array_map( 'trim', explode( ',', $atts['exclude_slugs'] ) );

	ob_start();
	?>
	<div class="db-sitemap">

	<?php
	/* Pages */
	$pages = get_pages( array(
		'post_status'  => 'publish',
		'sort_column'  => 'menu_order',
		'sort_order'   => 'ASC',
		'hierarchical' => false,
	) );
	if ( $pages ) {
		echo '<h2 class="db-sitemap-h2">Pages</h2><ul class="db-sitemap-list">';
		foreach ( $pages as $page ) {
			if ( in_array( $page->post_name, $excluded, true ) ) {
				continue;
			}
			echo '<li><a href="' . esc_url( get_permalink( $page ) ) . '">'
			   . esc_html( get_the_title( $page ) ) . '</a></li>';
		}
		echo '</ul>';
	}

	/* Domain listings (custom post type) */
	$domains = new WP_Query( array(
		'post_type'      => 'domain',
		'post_status'    => 'publish',
		'posts_per_page' => 300,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	) );
	if ( $domains->have_posts() ) {
		echo '<h2 class="db-sitemap-h2">Domain Listings</h2><ul class="db-sitemap-list db-sitemap-domains">';
		while ( $domains->have_posts() ) {
			$domains->the_post();
			echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
		}
		wp_reset_postdata();
		echo '</ul>';
	}

	/* Blog posts */
	$posts = new WP_Query( array(
		'post_type'      => 'post',
		'post_status'    => 'publish',
		'posts_per_page' => 50,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );
	if ( $posts->have_posts() ) {
		echo '<h2 class="db-sitemap-h2">News &amp; Articles</h2><ul class="db-sitemap-list">';
		while ( $posts->have_posts() ) {
			$posts->the_post();
			echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a>'
			   . '<span class="db-sitemap-date">' . esc_html( get_the_date() ) . '</span></li>';
		}
		wp_reset_postdata();
		echo '</ul>';
	}
	?>

	<style>
		.db-sitemap { max-width: 820px; margin: 0 auto; }
		.db-sitemap-h2 { font-size: 20px; font-weight: 700; margin: 36px 0 12px; border-bottom: 2px solid #e6e6e6; padding-bottom: 8px; }
		.db-sitemap-list { list-style: none; padding: 0; margin: 0; display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 6px 24px; }
		.db-sitemap-list li { padding: 4px 0; }
		.db-sitemap-list a { color: #0b3d91; text-decoration: none; font-size: 15px; }
		.db-sitemap-list a:hover { text-decoration: underline; }
		.db-sitemap-date { font-size: 12px; color: #888; margin-left: 8px; }
	</style>
	</div>
	<?php
	return ob_get_clean();
} );

/* ---- 3. RankMath XML sitemap: ensure 'domain' CPT is included ---- */

/*
 * Hook into RankMath's per-post-type query so 'domain' posts always appear
 * in the XML sitemap regardless of whether the UI checkbox was saved.
 * This filter is stable across RankMath versions (it's part of the public API).
 */
add_filter( 'rank_math/sitemap/entry/query_vars', function ( $vars, $post_type ) {
	if ( 'domain' === $post_type ) {
		$vars['post_type'] = 'domain';
		$vars['post_status'] = 'publish';
	}
	return $vars;
}, 10, 2 );

/*
 * Exclude checkout/utility URLs from the sitemap index via RankMath's URL
 * exclude filter. This prevents buy-now, thank-you, and payment-plan-setup
 * from appearing in the XML sitemap even if noindex isn't recognised by all
 * sitemap implementations.
 */
add_filter( 'rank_math/sitemap/exclude_empty_terms', '__return_true' );

// $url may be an array (with 'loc' key) or a string depending on RankMath version.
// Guard both shapes. The noindex meta tag above is the primary exclusion mechanism;
// this filter is belt-and-suspenders to also remove them from the XML source.
add_filter( 'rank_math/sitemap/entry', function ( $xml, $url ) {
	$blocked = array( '/buy-now/', '/thank-you/', '/payment-plan-setup/' );
	$loc = is_array( $url ) ? ( $url['loc'] ?? '' ) : ( is_string( $url ) ? $url : '' );
	foreach ( $blocked as $slug ) {
		if ( '' !== $loc && false !== strpos( $loc, $slug ) ) {
			return ''; // empty string suppresses this <url> entry from the XML
		}
	}
	return $xml;
}, 10, 2 );

/* ---- 4. Admin trigger: apply recommended RankMath settings ---- */
add_action( 'init', function () {
	if ( empty( $_GET['db_seo_setup'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// CSRF protection.
	if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'db_seo_setup' ) ) {
		$confirm = wp_nonce_url( add_query_arg( 'db_seo_setup', '1', home_url( '/' ) ), 'db_seo_setup' );
		wp_die(
			'Apply recommended RankMath SEO settings? <a href="' . esc_url( $confirm ) . '">Confirm</a>',
			'DB SEO Setup',
			array( 'response' => 200 )
		);
	}

	$log = array();

	/* Enable the sitemap module in RankMath. */
	$modules = get_option( 'rank_math_modules', array() );
	if ( is_array( $modules ) ) {
		if ( ! in_array( 'sitemap', $modules, true ) ) {
			$modules[] = 'sitemap';
			update_option( 'rank_math_modules', array_values( $modules ) );
			$log[] = '✓ Sitemap module: ENABLED (was off).';
		} else {
			$log[] = '✓ Sitemap module: already enabled.';
		}
	} else {
		$log[] = '⚠ rank_math_modules is not an array — enable the Sitemap module manually in RankMath → Dashboard.';
	}

	/* Merge sitemap settings (domain CPT, images, ping). */
	$sitemap_opts = get_option( 'rank_math_sitemap_options', array() );
	if ( ! is_array( $sitemap_opts ) ) {
		$sitemap_opts = array();
	}
	$sitemap_opts = array_merge( $sitemap_opts, array(
		'pt_domain_sitemap'   => 'on',
		'pt_post_sitemap'     => 'on',
		'pt_page_sitemap'     => 'on',
		'include_images'      => '1',
		'ping_search_engines' => '1',
	) );
	update_option( 'rank_math_sitemap_options', $sitemap_opts );
	$log[] = '✓ Sitemap options: domain CPT included, images included, search-engine ping enabled.';

	/* Clear RankMath's sitemap cache so the new settings take effect immediately. */
	$cache_cleared = false;
	if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) ) {
		\RankMath\Sitemap\Cache::invalidate_storage();
		$cache_cleared = true;
	} elseif ( function_exists( 'rank_math_invalidate_sitemap' ) ) {
		rank_math_invalidate_sitemap();
		$cache_cleared = true;
	}
	if ( ! $cache_cleared ) {
		// Fallback: delete sitemap transients directly.
		global $wpdb;
		$deleted = $wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_rank_math_sitemap_%'
			    OR option_name LIKE '_transient_timeout_rank_math_sitemap_%'"
		);
		$log[] = '✓ Sitemap cache: ' . (int) $deleted . ' transient(s) cleared.';
	} else {
		$log[] = '✓ Sitemap cache: cleared via RankMath API.';
	}

	$sitemap_url = esc_url( home_url( '/sitemap_index.xml' ) );
	$body  = '<h2 style="font-family:sans-serif">DB SEO Setup — Done</h2>';
	$body .= '<ul style="font-family:sans-serif;line-height:1.8">';
	foreach ( $log as $line ) {
		$body .= '<li>' . esc_html( $line ) . '</li>';
	}
	$body .= '</ul>';
	$body .= '<p style="font-family:sans-serif"><strong>XML Sitemap:</strong> <a href="' . $sitemap_url . '">' . $sitemap_url . '</a></p>';
	$body .= '<p style="font-family:sans-serif">Submit the XML sitemap URL to <a href="https://search.google.com/search-console/sitemaps" target="_blank" rel="noopener">Google Search Console → Sitemaps</a>.</p>';
	$body .= '<p style="font-family:sans-serif"><a href="' . esc_url( admin_url( 'admin.php?page=rank-math-options-sitemap' ) ) . '">Open RankMath Sitemap settings →</a></p>';
	$body .= '<p style="font-family:sans-serif"><a href="' . esc_url( home_url( '/' ) ) . '">← Back to site</a></p>';

	wp_die( $body, 'DB SEO Setup', array( 'response' => 200 ) );
} );
/* === end DB SEO & sitemap === */
