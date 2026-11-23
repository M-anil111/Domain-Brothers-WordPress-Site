<?php
/**
 * Index policy: which URLs search engines are asked to index.
 *
 * Search Console for the live site (export of 2026-11-18) reports 156 indexed
 * pages against about 4,250 not indexed, of which 3,327 are "Discovered,
 * currently not indexed" and 322 "Crawled, currently not indexed". Almost all
 * of those are old syndicated news posts: short rewrites of stories published
 * elsewhere first. They use up the crawl the site is given and return nothing,
 * while the pages that earn impressions (brokerage, short-name lists,
 * acquisition) sit around position 50 to 80.
 *
 * The policy:
 *   - Syndicated news older than DB_NEWS_INDEX_DAYS days is "noindex, follow"
 *     and leaves the XML sitemap. The posts stay online and stay linked.
 *   - Recent news and every guide stay indexable.
 *   - Tag archives are "noindex, follow" (thin lists of the same posts).
 *
 * Filters: db_index_news_days (int), db_index_thin_news (bool, post).
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_NEWS_INDEX_DAYS' ) ) {
	define( 'DB_NEWS_INDEX_DAYS', 60 );
}

if ( ! function_exists( 'db_index_news_days' ) ) {
	function db_index_news_days() {
		return max( 1, (int) apply_filters( 'db_index_news_days', DB_NEWS_INDEX_DAYS ) );
	}
}

if ( ! function_exists( 'db_index_is_thin_news' ) ) {
	/**
	 * True for a syndicated news post past its indexable window.
	 *
	 * @param WP_Post|int|null $post Post.
	 * @return bool
	 */
	function db_index_is_thin_news( $post ) {
		$post = get_post( $post );
		if ( ! $post instanceof WP_Post || 'post' !== $post->post_type ) {
			return false;
		}
		$thin = has_category( 'domain-news', $post ) && ! has_category( 'domain-guides', $post )
			&& strtotime( $post->post_date_gmt . ' UTC' ) < time() - db_index_news_days() * DAY_IN_SECONDS;
		return (bool) apply_filters( 'db_index_thin_news', $thin, $post );
	}
}

add_filter( 'wp_robots', function ( $robots ) {
	if ( is_tag() || ( is_singular( 'post' ) && db_index_is_thin_news( get_queried_object() ) ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'], $robots['nofollow'] );
	}
	return $robots;
}, 25 );

// XML sitemap: guides always, news only while it is recent.
add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $post_type ) {
	if ( 'post' === $post_type ) {
		$args['db_index_fresh'] = 1;
	}
	return $args;
}, 20, 2 );

add_filter( 'posts_where', function ( $where, $query ) {
	if ( ! $query instanceof WP_Query || ! $query->get( 'db_index_fresh' ) ) {
		return $where;
	}
	global $wpdb;
	$guides = get_category_by_slug( 'domain-guides' );
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - db_index_news_days() * DAY_IN_SECONDS );
	$clause = $wpdb->prepare( "{$wpdb->posts}.post_date_gmt >= %s", $cutoff );
	if ( $guides && ! is_wp_error( $guides ) ) {
		$clause .= $wpdb->prepare( " OR {$wpdb->posts}.ID IN ( SELECT object_id FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d )", (int) $guides->term_taxonomy_id );
	}
	return $where . ' AND ( ' . $clause . ' )';
}, 10, 2 );

// Tag archives are not listed in the sitemap either.
add_filter( 'wp_sitemaps_taxonomies', function ( $taxonomies ) {
	unset( $taxonomies['post_tag'] );
	return $taxonomies;
} );

/*
 * Monthly "Top 10 Domains" lists from 2017-2018 (SE-14). Their names link to "javascript:void(0)",
 * the Buy Now cells do nothing, and the 10% discounts and final prices are long gone. The posts stay
 * online as an archive: noindex, follow and out of the sitemap; names still for sale link to their
 * page, the rest say they are no longer listed; a note above the list gives its month.
 */
if ( ! function_exists( 'db_index_is_top10_archive' ) ) {
	function db_index_is_top10_archive( $post ) {
		$post = get_post( $post );
		return $post instanceof WP_Post && 'post' === $post->post_type
			&& (bool) preg_match( '/^top-10-(domains-of-[a-z]+(-\d+)?|premium-domains)$/', (string) $post->post_name );
	}
}

if ( ! function_exists( 'db_index_top10_content' ) ) {
	/**
	 * Rewrites one archived Top-10 list. Pure: $live maps a domain name to its live URL or ''.
	 *
	 * @param string   $html  Post content.
	 * @param callable $live  fn( string $name ): string.
	 * @param string   $month For example "July 2018".
	 */
	function db_index_top10_content( $html, $live, $month ) {
		$html = preg_replace_callback( '#<tr\b[^>]*>(.*?)</tr>#is', function ( $m ) use ( $live ) {
			$row = $m[0];
			if ( ! preg_match( '#<a\b[^>]*href=["\']\s*javascript:[^"\']*["\'][^>]*>(.*?)</a>#is', $row, $a ) ) {
				return $row;
			}
			$name = trim( wp_strip_all_tags( $a[1] ) );
			$url  = '' !== $name ? (string) call_user_func( $live, $name ) : '';
			$row  = str_replace( $a[0], '' !== $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>' : esc_html( $name ), $row );
			return preg_replace( '#<span class="buy-now-dmn">\s*Buy Now\s*</span>#i', '' !== $url ? '<a class="buy-now-dmn" href="' . esc_url( $url ) . '">See today&rsquo;s price</a>' : '<span class="buy-now-dmn is-gone">No longer listed</span>', $row );
		}, (string) $html );
		// Any other dead javascript: link keeps its text only.
		$html = preg_replace( '#<a\b[^>]*href=["\']\s*javascript:[^"\']*["\'][^>]*>(.*?)</a>#is', '$1', $html );
		$note = '<p class="db-archive-note"><strong>From our archive' . ( '' !== $month ? ' (' . esc_html( $month ) . ')' : '' ) . '.</strong> The discounts and prices below were offered at the time and no longer apply. '
			. 'See <a href="' . esc_url( home_url( '/all-domains/' ) ) . '">every domain for sale today</a>.</p>';
		return $note . $html;
	}
}

add_filter( 'the_content', function ( $content ) {
	if ( is_admin() || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() || ! db_index_is_top10_archive( get_the_ID() ) ) {
		return $content;
	}
	$live = function ( $name ) {
		$ids = get_posts( array( 'post_type' => 'domain', 'post_status' => 'publish', 'title' => $name, 'posts_per_page' => 1, 'fields' => 'ids', 'no_found_rows' => true ) );
		return $ids ? (string) get_permalink( (int) $ids[0] ) : '';
	};
	$month = (string) preg_replace( '/^Top 10 Domains of\s*/i', '', get_the_title() );
	return db_index_top10_content( $content, $live, preg_match( '/\d{4}$/', $month ) ? $month : '' );
}, 9 );

add_filter( 'wp_robots', function ( $robots ) {
	if ( is_singular( 'post' ) && db_index_is_top10_archive( get_queried_object() ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'], $robots['nofollow'] );
	}
	return $robots;
}, 26 );

// Rank Math head and sitemap. Priority 25: the staging rule (30) still wins on beta.
add_filter( 'rank_math/frontend/robots', static function ( $robots ) {
	if ( is_array( $robots ) && is_singular( 'post' ) && db_index_is_top10_archive( get_queried_object() ) ) {
		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';
	}
	return $robots;
}, 25 );

add_filter( 'rank_math/sitemap/entry', function ( $url, $type, $post ) {
	return ( 'post' === $type && is_object( $post ) && isset( $post->ID ) && db_index_is_top10_archive( $post->ID ) ) ? false : $url;
}, 10, 3 );
