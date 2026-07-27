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

/* ─── One-time repair: dead/mixed-content images inside old imported posts ──
 *
 * The 2026-07 site audit flagged 11 posts with mixed content (http:// images
 * on an https:// page), 39 with 4XX images and 8 with 3XX images — all from
 * posts imported years ago. Three distinct root causes, confirmed live:
 *
 *   1. Six team-member photos referenced in "sell-social-media-handles" (and
 *      possibly elsewhere) point at /wp-content/uploads/2023/12/{name}.jpg,
 *      which 404s — the real files sit one folder over, at .../2024/04/. A
 *      copy/paste from a different post at import time, most likely.
 *   2. Third-party hotlinked images (domaingang.com, namepros.com, and a very
 *      old ftjcfx.com affiliate tracking pixel) served over bare http://,
 *      tripping the browser's mixed-content warning on this https:// site,
 *      even though every one still loads fine over https.
 *   3. A handful of local uploads that are just gone (an SVG saved with its
 *      MIME type accidentally appended to the filename, a GoDaddy earnings
 *      graphic, a theme icon reference) — no correct URL to redirect them to,
 *      so the only honest fix is to remove that one broken <img> rather than
 *      leave a broken-image icon in a customer-facing article.
 *
 * This is a one-time content repair, not a per-request patch — it rewrites
 * the affected posts' stored content directly (like the /?db_fix_services_page=1
 * and /?db_make_service_pages=1 triggers above it in this codebase), so
 * there's nothing left running on every page load afterward. Admin-only,
 * nonce-gated, dry-run by default so the exact diff can be reviewed before
 * anything is saved: /?db_fix_post_images=1 (dry run) then
 * /?db_fix_post_images=1&commit=1 (after confirming the dry-run report).
 */
if ( ! function_exists( 'db_fix_post_images_known_moves' ) ) {
	/**
	 * Filename => correct date folder, for images confirmed live to have
	 * moved. Matches both the full-size and the "-150x150" thumbnail variant.
	 */
	function db_fix_post_images_known_moves() {
		return array(
			'kartikmehta.jpg'  => '2024/04',
			'jaymehta.jpg'     => '2024/04',
			'ashishkalal.jpg'  => '2024/04',
			'harshraval.jpg'   => '2024/04',
			'mayank.jpg'       => '2024/04',
			'sujitshukla.jpg'  => '2024/04',
		);
	}
}

if ( ! function_exists( 'db_fix_post_images_in_content' ) ) {
	/**
	 * Applies all three fixes to one post's content and returns
	 * array( $new_content, $changes ) — $changes is a list of human-readable
	 * strings describing what changed, empty if nothing did.
	 */
	function db_fix_post_images_in_content( $content ) {
		$changes = array();
		if ( ! is_string( $content ) || '' === $content ) {
			return array( $content, $changes );
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );

		// 1) Known moved files — safe, no live check needed, checked above.
		foreach ( db_fix_post_images_known_moves() as $file => $correct_dir ) {
			$pattern = '#/wp-content/uploads/\d{4}/\d{2}/(' . preg_quote( pathinfo( $file, PATHINFO_FILENAME ), '#' ) . '(?:-\d+x\d+)?\.' . preg_quote( pathinfo( $file, PATHINFO_EXTENSION ), '#' ) . ')#i';
			$new     = preg_replace( $pattern, '/wp-content/uploads/' . $correct_dir . '/$1', $content, -1, $count );
			if ( $count > 0 ) {
				$changes[] = "moved {$file} reference(s) to /{$correct_dir}/ ({$count}x)";
				$content   = $new;
			}
		}

		// 2) Bare http:// images — upgrade to https:// only after confirming
		// the https version actually loads; leave alone otherwise.
		if ( preg_match_all( '#(?:src|srcset)="(http://[^"]+)"#i', $content, $m ) ) {
			$checked = array();
			foreach ( array_unique( $m[1] ) as $raw ) {
				// srcset can hold several "url widthw" entries.
				foreach ( preg_split( '/\s*,\s*/', $raw ) as $entry ) {
					$url = strtok( trim( $entry ), ' ' );
					if ( ! $url || 0 !== stripos( $url, 'http://' ) || isset( $checked[ $url ] ) ) {
						continue;
					}
					$checked[ $url ] = true;
					$https            = 'https://' . substr( $url, 7 );
					$resp             = wp_remote_head( $https, array( 'timeout' => 8 ) );
					if ( ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) < 400 ) {
						$content   = str_replace( $url, $https, $content );
						$changes[] = "upgraded {$url} to https";
					}
				}
			}
		}

		// 3) Genuinely dead local uploads — try to find the file elsewhere
		// under uploads/ by basename; if that fails, remove the <img> (and
		// its wrapping <figure>/<picture>, if any) so nothing broken ships.
		if ( $host && preg_match_all( '#<(figure|picture)[^>]*>(?:(?!</\1>).)*?<img[^>]*src="https?://' . preg_quote( $host, '#' ) . '(/wp-content/uploads/[^"]+)"[^>]*>(?:(?!</\1>).)*?</\1>|<img[^>]*src="https?://' . preg_quote( $host, '#' ) . '(/wp-content/uploads/[^"]+)"[^>]*/?>#is', $content, $m2, PREG_OFFSET_CAPTURE ) ) {
			$uploads  = wp_get_upload_dir();
			$replaced = array();
			foreach ( $m2[0] as $i => $whole ) {
				$path = ! empty( $m2[2][ $i ][0] ) ? $m2[2][ $i ][0] : $m2[3][ $i ][0];
				if ( '' === $path || isset( $replaced[ $whole[0] ] ) ) {
					continue;
				}
				$local = $uploads['basedir'] . $path;
				if ( file_exists( $local ) ) {
					continue; // exists after all — an earlier fix in this same pass may have moved it.
				}
				$basename = wp_basename( $path );
				$found    = glob( $uploads['basedir'] . '/*/*/' . $basename );
				if ( ! empty( $found ) && 1 === count( $found ) ) {
					$new_path = str_replace( $uploads['basedir'], '', $found[0] );
					$content  = str_replace( $path, $new_path, $content );
					$changes[] = "relocated {$basename} to {$new_path}";
				} else {
					$content   = str_replace( $whole[0], '', $content );
					$changes[] = "removed dead image reference: {$basename}";
				}
				$replaced[ $whole[0] ] = true;
			}
		}

		return array( $content, $changes );
	}
}

add_action( 'init', function () {
	if ( empty( $_GET['db_fix_post_images'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'db_fix_post_images' ) ) {
		$dry_url     = wp_nonce_url( add_query_arg( 'db_fix_post_images', '1', home_url( '/' ) ), 'db_fix_post_images' );
		$commit_url  = wp_nonce_url( add_query_arg( array( 'db_fix_post_images' => '1', 'commit' => '1' ), home_url( '/' ) ), 'db_fix_post_images' );
		wp_die( 'Repair dead/mixed-content images in post content? <a href="' . esc_url( $dry_url ) . '">Dry run (no changes saved)</a>', 'DB Post Image Repair', array( 'response' => 200 ) );
	}

	$commit = ! empty( $_GET['commit'] );
	$posts  = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
	$report = array();
	foreach ( $posts as $post_id ) {
		$content            = get_post_field( 'post_content', $post_id );
		list( $new, $changes ) = db_fix_post_images_in_content( $content );
		if ( empty( $changes ) ) {
			continue;
		}
		$report[] = '#' . $post_id . ' ' . esc_html( get_the_title( $post_id ) ) . '<br>&nbsp;&nbsp;' . implode( '<br>&nbsp;&nbsp;', array_map( 'esc_html', $changes ) );
		if ( $commit ) {
			wp_update_post( array( 'ID' => $post_id, 'post_content' => $new ) );
		}
	}

	if ( empty( $report ) ) {
		wp_die( 'No dead or mixed-content images found in any published post.', 'DB Post Image Repair', array( 'response' => 200 ) );
	}
	$mode  = $commit ? 'COMMITTED' : 'DRY RUN — nothing saved';
	$next  = $commit ? '' : '<p><a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'db_fix_post_images' => '1', 'commit' => '1' ), home_url( '/' ) ), 'db_fix_post_images' ) ) . '">Commit these changes</a></p>';
	wp_die( '<p><strong>' . $mode . '</strong> — ' . count( $report ) . ' post(s) affected:</p><p>' . implode( '</p><p>', $report ) . '</p>' . $next, 'DB Post Image Repair', array( 'response' => 200 ) );
} );
