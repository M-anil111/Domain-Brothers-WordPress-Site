<?php
/**
 * DB News Block — live industry RSS feed on /news/, and a duplicate-image
 * fix on individual news post pages.
 *
 * The /news/ page (WordPress's own posts index — confirmed live via body
 * class "blog", not a Page, so is_page('news') never matches it) was frozen
 * on ~50 posts imported once around March-April 2024 and never updated
 * since. A live-RSS-feed block for this existed already
 * (db_news_rss_block.php in the repo root, never actually wired into the
 * deployed plugin — its own is_page('news') check meant it could never
 * have fired here even if it had been) but nothing had kept /news/ current
 * since then. This inserts a small "Latest From The Industry" grid, pulled
 * live from real domain-industry RSS feeds and cached for a few hours,
 * right above the existing (still valuable, still real) post archive
 * rather than replacing it outright.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_NEWS_TTL' ) ) {
	define( 'DB_NEWS_TTL', 6 * HOUR_IN_SECONDS );
}

if ( ! function_exists( 'db_news_feeds' ) ) {
	function db_news_feeds() {
		return array(
			'https://domainnamewire.com/feed/',
			'https://domaininvesting.com/feed/',
			'https://www.dnjournal.com/rss/rss.xml',
		);
	}
}

add_filter( 'wp_feed_cache_transient_lifetime', function ( $seconds, $url = '' ) {
	return in_array( $url, db_news_feeds(), true ) ? DB_NEWS_TTL : $seconds;
}, 10, 2 );

/* Admin-only manual refresh: /?db_news_refresh=1 */
add_action( 'init', function () {
	if ( empty( $_GET['db_news_refresh'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'db_news_refresh' ) ) {
		$confirm = wp_nonce_url( add_query_arg( 'db_news_refresh', '1', home_url( '/' ) ), 'db_news_refresh' );
		wp_die( 'Clear the cached news feed? <a href="' . esc_url( $confirm ) . '">Confirm refresh</a>', 'DB News', array( 'response' => 200 ) );
	}
	$cleared = 0;
	foreach ( db_news_feeds() as $feed_url ) {
		$hash = md5( $feed_url );
		delete_transient( 'feed_' . $hash );
		delete_transient( 'feed_mod_' . $hash );
		$cleared++;
	}
	wp_die( 'DB news cache cleared (' . (int) $cleared . ' feed(s)). <a href="' . esc_url( home_url( '/news/' ) ) . '">View /news/</a>', 'DB News', array( 'response' => 200 ) );
} );

if ( ! function_exists( 'db_render_news' ) ) {
	function db_render_news( $limit = 6 ) {
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
			$feed_items = $feed->get_items( 0, $max );
			if ( ! is_array( $feed_items ) ) {
				continue;
			}
			foreach ( $feed_items as $item ) {
				$ts = $item->get_date( 'U' );
				$items[] = array(
					'title'   => $item->get_title(),
					'link'    => $item->get_permalink(),
					'date'    => $ts ? (int) $ts : 0,
					'source'  => $source,
					'excerpt' => wp_trim_words( html_entity_decode( wp_strip_all_tags( $item->get_description() ), ENT_QUOTES, 'UTF-8' ), 28, "\xE2\x80\xA6" ),
				);
			}
			if ( count( $items ) >= $limit ) {
				break;
			}
		}

		if ( empty( $items ) ) {
			return '';
		}

		usort( $items, function ( $a, $b ) { return $b['date'] <=> $a['date']; } );
		$items = array_slice( $items, 0, $limit );

		$html  = '<div class="db-news-live">';
		$html .= '<h2 class="db-news-live-heading">Latest From The Industry</h2>';
		$html .= '<div class="db-news-grid">';
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
		$html .= '</div></div>';

		// Styled via db_ui_css() (the ".db-news-*" rules there) — no inline
		// <style> here, so this doesn't compete with the rest of the design
		// system the way a second, hardcoded stylesheet would.
		return $html;
	}
}

/* Insert the live feed just above the existing post archive on /news/.
   is_home() (not is_page()) is what actually matches this template —
   confirmed live, body class is "blog", there is no page ID at all. */
add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_home() ) {
		return;
	}
	ob_start( function ( $html ) {
		if ( false !== strpos( $html, 'db-news-live' ) ) {
			return $html; // already inserted (defensive, shouldn't recurse)
		}
		$news_html = db_render_news( 6 );
		if ( '' === $news_html ) {
			return $html; // all feeds unreachable this request — leave the archive as-is
		}
		$anchor = '<div class="news-cnt">';
		$pos = strpos( $html, $anchor );
		if ( false === $pos ) {
			return $html;
		}
		return substr_replace( $html, $news_html, $pos, 0 );
	} );
}, 20 );

/* Individual news post pages duplicate the featured image: the theme
   already renders it above the content (<figure class="feature-image">),
   and the same file was also pasted into the post body itself during the
   original bulk import — at a different upload path in every case checked
   live, so it 404s there on top of being a duplicate. Strips only an
   inline image whose filename matches the post's own featured image,
   leaving any other in-content image (a post can legitimately have more
   than one) untouched. */
add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_singular( 'post' ) ) {
		return;
	}
	$thumb_id = get_post_thumbnail_id();
	if ( ! $thumb_id ) {
		return;
	}
	$thumb_file = wp_basename( get_attached_file( $thumb_id ) );
	if ( ! $thumb_file ) {
		return;
	}
	ob_start( function ( $html ) use ( $thumb_file ) {
		return preg_replace_callback(
			'/<figure[^>]*class="[^"]*wp-block-image[^"]*"[^>]*>.*?<\/figure>/s',
			function ( $m ) use ( $thumb_file ) {
				return ( false !== strpos( $m[0], $thumb_file ) ) ? '' : $m[0];
			},
			$html
		);
	} );
}, 21 );
