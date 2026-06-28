<?php
/* === DB live news RSS feed ===
 *
 * Issue #52 — Domain News.
 *
 * The /news/ page previously rendered stale 2024 WordPress posts. This block
 * pulls a LIVE domain-industry RSS feed and renders the latest items.
 *
 * HOW TO USE (pick whichever fits how /news/ is built):
 *   1. Shortcode:  put  [db_news]  into the /news/ page content. (Most reliable.)
 *   2. Auto-inject: if the page slug is "news" (see $slug below) and the page
 *      does NOT already contain the shortcode, the latest items are appended to
 *      the page content automatically.
 *
 * CACHING: feeds are cached by WordPress/SimplePie for DB_NEWS_TTL seconds
 * (default 6h) so we don't hammer the source on every page load. Admins can
 * force a refresh by visiting  /?db_news_refresh=1  while logged in.
 *
 * NOTE on the agent proxy / outbound fetches: fetch_feed() uses WP HTTP, which
 * respects the server's outbound rules. On the live Hostinger host this works
 * normally. If a source ever blocks the server's IP, swap it for another in
 * DB_NEWS_FEEDS below.
 *
 * Paste this block at the end of functions.php (after the opening recipe in
 * HANDOFF.md section 6). Self-contained; no other block required.
 */

if ( ! defined( 'DB_NEWS_TTL' ) ) {
	define( 'DB_NEWS_TTL', 6 * HOUR_IN_SECONDS ); // how long to cache the feed
}

/* Sources, in priority order. Domain-industry feeds. Add/remove freely. */
function db_news_feeds() {
	return array(
		'https://domainnamewire.com/feed/',
		'https://domaininvesting.com/feed/',
		'https://www.dnjournal.com/rss/rss.xml',
	);
}

/* Cap how long SimplePie caches the fetched feed (matches DB_NEWS_TTL). */
add_filter( 'wp_feed_cache_transient_lifetime', function ( $seconds ) {
	return DB_NEWS_TTL;
} );

/* Admin-only manual refresh: /?db_news_refresh=1 */
add_action( 'init', function () {
	if ( empty( $_GET['db_news_refresh'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Clear every SimplePie feed transient so the next render re-fetches.
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_feed_%' OR option_name LIKE '_transient_timeout_feed_%'" );
	wp_die( 'DB news cache cleared. <a href="' . esc_url( home_url( '/news/' ) ) . '">View /news/</a>', 'DB News', array( 'response' => 200 ) );
} );

/*
 * Build the news HTML. $limit = number of items to show.
 * Returns a ready-to-print HTML string (or a graceful message on failure).
 */
function db_render_news( $limit = 12 ) {
	if ( ! function_exists( 'fetch_feed' ) ) {
		include_once ABSPATH . WPINC . '/feed.php';
	}

	$items = array();
	foreach ( db_news_feeds() as $url ) {
		$feed = fetch_feed( $url );
		if ( is_wp_error( $feed ) ) {
			continue;
		}
		$source = $feed->get_title();
		$max    = $feed->get_item_quantity( $limit );
		foreach ( $feed->get_items( 0, $max ) as $item ) {
			$ts = $item->get_date( 'U' );
			$items[] = array(
				'title'  => $item->get_title(),
				'link'   => $item->get_permalink(),
				'date'   => $ts ? (int) $ts : 0,
				'source' => $source,
				'excerpt'=> wp_trim_words( wp_strip_all_tags( $item->get_description() ), 32, '&hellip;' ),
			);
		}
		// Once we have a healthy primary feed, stop (others are fallbacks).
		if ( count( $items ) >= $limit ) {
			break;
		}
	}

	if ( empty( $items ) ) {
		return '<p class="db-news-empty">Industry news is temporarily unavailable. Please check back shortly.</p>';
	}

	// Newest first, then trim to the limit.
	usort( $items, function ( $a, $b ) {
		return $b['date'] <=> $a['date'];
	} );
	$items = array_slice( $items, 0, $limit );

	$html  = '<div class="db-news-grid">';
	foreach ( $items as $it ) {
		$date_str = $it['date'] ? date_i18n( get_option( 'date_format' ), $it['date'] ) : '';
		$html .= '<article class="db-news-card">';
		$html .= '<a class="db-news-title" href="' . esc_url( $it['link'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $it['title'] ) . '</a>';
		$html .= '<div class="db-news-meta">';
		if ( $it['source'] ) {
			$html .= '<span class="db-news-source">' . esc_html( $it['source'] ) . '</span>';
		}
		if ( $date_str ) {
			$html .= '<span class="db-news-date">' . esc_html( $date_str ) . '</span>';
		}
		$html .= '</div>';
		if ( $it['excerpt'] ) {
			$html .= '<p class="db-news-excerpt">' . esc_html( $it['excerpt'] ) . '</p>';
		}
		$html .= '<a class="db-news-readmore" href="' . esc_url( $it['link'] ) . '" target="_blank" rel="noopener nofollow">Read more &rarr;</a>';
		$html .= '</article>';
	}
	$html .= '</div>';

	$html .= '<style>
		.db-news-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:24px;margin:24px 0;}
		.db-news-card{border:1px solid #e6e6e6;border-radius:10px;padding:20px;background:#fff;display:flex;flex-direction:column;}
		.db-news-title{font-size:18px;font-weight:600;line-height:1.35;text-decoration:none;color:#111;}
		.db-news-title:hover{text-decoration:underline;}
		.db-news-meta{display:flex;gap:10px;flex-wrap:wrap;font-size:12px;color:#888;margin:8px 0 10px;}
		.db-news-excerpt{font-size:14px;color:#444;line-height:1.5;flex:1;margin:0 0 14px;}
		.db-news-readmore{font-size:14px;font-weight:600;text-decoration:none;color:#0a6ed1;}
		.db-news-empty{padding:24px;color:#666;}
	</style>';

	return $html;
}

/* Shortcode: [db_news limit="12"] */
add_shortcode( 'db_news', function ( $atts ) {
	$atts  = shortcode_atts( array( 'limit' => 12 ), $atts, 'db_news' );
	return db_render_news( (int) $atts['limit'] );
} );

/*
 * Auto-inject on the /news/ page if the shortcode isn't already present.
 * Change $slug if the news page uses a different slug.
 */
add_filter( 'the_content', function ( $content ) {
	$slug = 'news';
	if ( ! is_page( $slug ) || is_admin() ) {
		return $content;
	}
	if ( has_shortcode( $content, 'db_news' ) || false !== strpos( $content, 'db-news-grid' ) ) {
		return $content; // already rendered via shortcode
	}
	return $content . db_render_news( 12 );
} );
/* === end DB live news RSS feed === */
