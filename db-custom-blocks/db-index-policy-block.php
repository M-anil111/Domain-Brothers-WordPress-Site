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
