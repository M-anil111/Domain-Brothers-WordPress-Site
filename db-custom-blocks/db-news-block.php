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

if ( ! function_exists( 'db_news_og_image' ) ) {
	/**
	 * These RSS feeds carry no <enclosure>/<media:content>/<img> at all
	 * (checked all three sources' raw XML directly — description is plain
	 * text) so there is no feed-provided image to show. This fetches the
	 * linked article once and reads its own og:image instead. Cached under
	 * the same TTL as the feed itself so a page view doesn't pay for 6 live
	 * fetches every time — only the first visitor after each cache window
	 * does, exactly like the feed fetch it sits next to. An empty string is
	 * itself cached (a real "no image" result), so a source with no
	 * og:image isn't re-checked on every miss either.
	 */
	function db_news_og_image( $article_url ) {
		$key    = 'db_news_img_' . md5( $article_url );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return $cached;
		}
		$image = '';
		// Article links come from third-party feeds: only fetch public https
		// URLs (wp_safe_remote_get refuses private/loopback addresses and
		// re-checks redirects), so a compromised feed can't point this server
		// at internal addresses.
		if ( 'https' !== wp_parse_url( (string) $article_url, PHP_URL_SCHEME ) ) {
			set_transient( $key, '', DB_NEWS_TTL );
			return '';
		}
		$resp  = wp_safe_remote_get( $article_url, array( 'timeout' => 3, 'redirection' => 3 ) );
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$body = wp_remote_retrieve_body( $resp );
			if ( preg_match( '#<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']#i', $body, $m )
				|| preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image["\']#i', $body, $m ) ) {
				$image = $m[1];
			}
		}
		set_transient( $key, $image, DB_NEWS_TTL );
		return $image;
	}
}

if ( ! function_exists( 'db_render_news_cached' ) ) {
	/**
	 * The news block without making a visitor wait for the feeds (bug P-1): the last
	 * rendering is served at once, and a stale one is rebuilt in the background by cron.
	 * Only the very first rendering (nothing stored yet) is built during the request.
	 */
	function db_render_news_cached( $limit = 6 ) {
		$key   = 'db_news_html_' . (int) $limit;
		$saved = get_option( $key );
		if ( is_array( $saved ) && isset( $saved['html'], $saved['ts'] ) ) {
			if ( time() - (int) $saved['ts'] > DB_NEWS_TTL && ! wp_next_scheduled( 'db_news_refresh', array( (int) $limit ) ) ) {
				wp_schedule_single_event( time(), 'db_news_refresh', array( (int) $limit ) );
			}
			// The stored copy may be from yesterday: the date line is always today's.
			return (string) preg_replace_callback( '#<p class="db-an-day">[^<]*</p>#', static function () {
				return '<p class="db-an-day">' . esc_html( date_i18n( 'l, F j' ) ) . '</p>';
			}, (string) $saved['html'], 1 );
		}
		return db_news_refresh( $limit );
	}
}

if ( ! function_exists( 'db_news_refresh' ) ) {
	/** Builds the news block and stores it (empty results keep the previous copy). */
	function db_news_refresh( $limit = 6 ) {
		$html = db_render_news( (int) $limit );
		$key  = 'db_news_html_' . (int) $limit;
		if ( '' !== $html || ! get_option( $key ) ) {
			update_option( $key, array( 'html' => $html, 'ts' => time() ), false );
		}
		if ( '' === $html ) {
			$old = get_option( $key );
			return is_array( $old ) ? (string) $old['html'] : '';
		}
		return $html;
	}
}
add_action( 'db_news_refresh', 'db_news_refresh' );

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

		$card = function ( $it, $class, $eager ) {
			$date_str = $it['date'] ? date_i18n( get_option( 'date_format' ), $it['date'] ) : '';
			$image    = db_news_og_image( $it['link'] );
			$source   = trim( (string) preg_replace( '/\s*\|.*$/', '', (string) $it['source'] ) );
			// One link for the whole card: the publisher's article in a new tab. Its accessible name is
			// "Title, Source (opens in a new tab)" rather than all the card text read in a row (AX-15).
			$label = $it['title'] . ( '' !== $source ? ', ' . $source : '' ) . ' (opens in a new tab)';
			$h     = '<article class="db-an-card ' . esc_attr( $class ) . '"><a class="db-an-hit" href="' . esc_url( $it['link'] ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $label ) . '">';
			$h .= '<span class="db-an-img">' . db_img_placeholder( '' !== $source ? $source : 'Domain news', $it['title'] ) . ( $image ? '<img src="' . esc_url( $image ) . '" alt="" ' . ( $eager ? 'loading="eager"' . ( 1 === $eager ? ' fetchpriority="high"' : '' ) : 'loading="lazy"' ) . ' decoding="async">' : '' ) . '</span>';
			$h .= '<span class="db-an-txt">';
			if ( '' !== $source ) {
				$h .= '<span class="db-an-src">' . esc_html( $source ) . '</span>';
			}
			$h .= '<span class="db-an-title">' . esc_html( $it['title'] ) . '</span>';
			if ( $it['excerpt'] ) {
				$h .= '<span class="db-an-ex">' . esc_html( $it['excerpt'] ) . '</span>';
			}
			if ( $date_str ) {
				$h .= '<span class="db-an-date">' . esc_html( $date_str ) . '</span>';
			}
			return $h . '</span></a></article>';
		};

		$html  = '<div class="db-news-live db-an">';
		$html .= '<header class="db-an-head"><p class="db-an-day">' . esc_html( date_i18n( 'l, F j' ) ) . '</p><h1 class="db-an-h1">Domain News</h1><p class="db-an-sub">The stories moving the domain industry today, from the publications that cover it.</p></header>';
		// One card design for every story on the page.
		$html .= '<h2 class="db-an-sec db-news-live-heading">Latest from the industry</h2><div class="db-an-grid">';
		foreach ( $items as $n => $it ) {
			$html .= $card( $it, 'db-an-tile', $n < 4 ? $n + 1 : 0 );
		}
		$html .= '</div><h2 class="db-an-sec db-an-sec--own">From Domain Brothers</h2></div>';

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
		// Page 2 and later list older posts only: the live feed (the same 8 stories on all 308 pages)
		// and an unnumbered H1 made every archive page a duplicate (SE-13).
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			$html = preg_replace( '#(<h1 class="db-an-h1">)([^<]*)(</h1>)#', '$1$2, page ' . $paged . '$3', $html, 1 );
			return db_news_own_cards( $html );
		}
		$news_html = db_render_news_cached( 8 );
		if ( '' === $news_html ) {
			return $html; // all feeds unreachable this request — leave the archive as-is
		}
		$anchor = '<div class="news-cnt">';
		$pos = strpos( $html, $anchor );
		if ( false === $pos ) {
			return $html;
		}
		return db_news_own_cards( substr_replace( $html, $news_html, $pos, 0 ) );
	} );
}, 20 );

if ( ! function_exists( 'db_news_own_cards' ) ) {
	/**
	 * The theme prints the site's own posts as a plain list. Each entry becomes
	 * the same card the feed stories use, in the same grid. Private posts (only
	 * ever visible to a signed-in administrator) are left out.
	 *
	 * @param string $html Page HTML.
	 * @return string
	 */
	function db_news_own_cards( $html ) {
		$n   = 0;
		$out = preg_replace_callback( '#<div class="news-cnt">\s*(<article\b[^>]*>.*?</article>)\s*</div>#s', function ( $m ) use ( &$n ) {
			$a = $m[1];
			if ( preg_match( '#^<article\b[^>]*\bstatus-private\b#', $a ) ) {
				return '';
			}
			if ( ! preg_match( '#<h[1-6][^>]*class="[^"]*entry-title[^"]*"[^>]*>\s*<a[^>]*href="([^"]+)"[^>]*>(.*?)</a>#s', $a, $t ) ) {
				return $m[0];
			}
			$title = trim( wp_strip_all_tags( $t[2] ) );
			$title = (string) preg_replace( '/^Private:\s*/', '', $title );
			$img   = preg_match( '#<img[^>]*\bsrc="([^"]+)"#', $a, $i ) ? $i[1] : '';
			$date  = preg_match( '#<time[^>]*>(.*?)</time>#s', $a, $d ) ? trim( wp_strip_all_tags( $d[1] ) ) : '';
			if ( '' === $date && preg_match( '#((?:January|February|March|April|May|June|July|August|September|October|November|December) \d{1,2}, \d{4})#', wp_strip_all_tags( $a ), $d ) ) {
				$date = $d[1];
			}
			$cat = preg_match( '#rel="category tag"[^>]*>(.*?)</a>#s', $a, $c ) ? trim( wp_strip_all_tags( $c[1] ) ) : 'Domain Brothers';
			++$n;
			return '<article class="db-an-card db-an-tile"><a class="db-an-hit" href="' . esc_url( $t[1] ) . '">'
				. '<span class="db-an-img">' . db_img_placeholder( $cat, $title ) . ( '' !== $img ? '<img src="' . esc_url( $img ) . '" alt="" loading="lazy" decoding="async">' : '' ) . '</span>'
				. '<span class="db-an-txt"><span class="db-an-src">' . esc_html( $cat ) . '</span><span class="db-an-title">' . esc_html( $title ) . '</span>'
				. ( '' !== $date ? '<span class="db-an-date">' . esc_html( $date ) . '</span>' : '' ) . '</span></a></article>';
		}, $html );
		if ( null === $out || 0 === $n ) {
			return $html;
		}
		// Wrap the run of cards in the shared grid.
		$first = strpos( $out, '<article class="db-an-card db-an-tile"><a class="db-an-hit"' );
		$last  = strrpos( $out, '</a></article>' );
		if ( false === $first || false === $last ) {
			return $html;
		}
		$last += strlen( '</a></article>' );
		return substr( $out, 0, $first ) . '<div class="db-an-grid db-an-own">' . substr( $out, $first, $last - $first ) . '</div>' . substr( $out, $last );
	}
}

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

if ( ! function_exists( 'db_fix_post_images_find_http_urls' ) ) {
	/**
	 * Every distinct http:// image URL referenced across a batch of post
	 * content strings — a pure string scan, no network I/O.
	 */
	function db_fix_post_images_find_http_urls( array $contents ) {
		$urls = array();
		foreach ( $contents as $content ) {
			if ( ! is_string( $content ) || ! preg_match_all( '#(?:src|srcset)="(http://[^"]+)"#i', $content, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $raw ) {
				// srcset can hold several "url widthw" entries.
				foreach ( preg_split( '/\s*,\s*/', $raw ) as $entry ) {
					$url = strtok( trim( $entry ), ' ' );
					if ( $url && 0 === stripos( $url, 'http://' ) ) {
						$urls[ $url ] = true;
					}
				}
			}
		}
		return array_keys( $urls );
	}
}

if ( ! function_exists( 'db_fix_post_images_https_map' ) ) {
	/**
	 * Checks each unique http:// URL exactly once (short timeout — this is a
	 * reachability probe, not a real page load) and returns url => bool,
	 * true meaning the https:// counterpart is safe to switch to.
	 *
	 * Deliberately checked ONCE per unique URL for the whole run rather than
	 * once per post that references it — with the same image often hotlinked
	 * across dozens of old syndicated posts, per-post checking multiplied a
	 * handful of real URLs into a live-request count large enough to run the
	 * whole trigger past PHP's execution time limit (confirmed: an earlier
	 * version of this hung for 280+ seconds on this exact site).
	 */
	function db_fix_post_images_https_map( array $urls ) {
		$map = array();
		// Hard cap so a surprise long tail of distinct hotlinked hosts can't
		// run this past PHP's execution time limit — 40 URLs at a 4s timeout
		// each is at most ~160s. Any URL past the cap is simply left as
		// bare http:// for a follow-up run rather than checked.
		$urls = array_slice( $urls, 0, 40 );
		foreach ( $urls as $url ) {
			$https      = 'https://' . substr( $url, 7 );
			$resp       = wp_remote_head( $https, array( 'timeout' => 4 ) );
			$map[ $url ] = ! is_wp_error( $resp ) && wp_remote_retrieve_response_code( $resp ) < 400;
		}
		return $map;
	}
}

if ( ! function_exists( 'db_fix_post_images_uploads_index' ) ) {
	/**
	 * lowercased-basename => real relative path, for every file under
	 * uploads/YYYY/MM/. Built once per run (a single two-level glob) instead
	 * of once per broken image reference — with 802 posts referencing dead
	 * images in one pass, a glob() per reference was both slow and, worse,
	 * case-sensitive: several "dead" images turned out to exist under a
	 * different case (post content says "GoDaddy-reports-Q4...jpg", the
	 * actual file on disk is "godaddy-reports-q4...jpg" — WordPress
	 * lower-cases on upload; whatever generated the original import HTML
	 * didn't match that). A case-sensitive glob() reported these as
	 * unfixable and would have deleted the image from the post outright.
	 */
	function db_fix_post_images_uploads_index() {
		$uploads = wp_get_upload_dir();
		$index   = array();
		$seen    = array();
		foreach ( (array) glob( $uploads['basedir'] . '/*/*/*' ) as $path ) {
			$key = strtolower( wp_basename( $path ) );
			// A basename that exists more than once anywhere in uploads/ is
			// ambiguous — picking either one risks matching a post to a
			// completely unrelated image with a common name (e.g. a generic
			// "logo.png" reused across years). Only ever resolve unique
			// basenames automatically; leave collisions alone.
			if ( isset( $seen[ $key ] ) ) {
				unset( $index[ $key ] );
				continue;
			}
			$seen[ $key ] = true;
			// Stored as the URL-style path post content actually uses
			// ("/wp-content/uploads/2024/04/file.jpg"), not basedir-relative
			// ("/2024/04/file.jpg") — the two content-rewrite call sites
			// below match/replace full "/wp-content/uploads/..." strings, so
			// a basedir-relative value here silently dropped that prefix
			// when substituted back in (confirmed live: it shipped a broken
			// "https://host/2024/04/file.jpg" URL missing the segment
			// entirely — caught and fixed before any further posts ran the
			// buggy version, but 807 already had; see the repair pass this
			// same file's docblock points to).
			$cut            = strpos( $path, '/wp-content/uploads' );
			$index[ $key ] = false !== $cut ? substr( $path, $cut ) : $path;
		}
		return $index;
	}
}

if ( ! function_exists( 'db_fix_post_images_in_content' ) ) {
	/**
	 * Applies all three fixes to one post's content and returns
	 * array( $new_content, $changes ) — $changes is a list of human-readable
	 * strings describing what changed, empty if nothing did. $https_map is
	 * the pre-computed url => bool result of db_fix_post_images_https_map();
	 * $uploads_index is the pre-computed result of
	 * db_fix_post_images_uploads_index().
	 */
	function db_fix_post_images_in_content( $content, array $https_map = array(), array $uploads_index = array() ) {
		$changes = array();
		if ( ! is_string( $content ) || '' === $content ) {
			return array( $content, $changes );
		}

		// 0) Self-repair for a bug in an earlier version of this exact
		// function: it once built $uploads_index without the
		// "/wp-content/uploads" prefix, then substituted that bare value
		// straight into src="..." — shipping "https://host/2024/04/x.jpg"
		// (missing the whole /wp-content/uploads/ segment) to 807 posts
		// before this was caught. srcset attributes on the same <img> were
		// untouched, so the fix is unambiguous: an img src whose path starts
		// directly with a 4-digit year is always this corruption, never a
		// legitimate URL on this site (no date-based permalinks exist here).
		$repaired = preg_replace( '#(src=")(https?://[^/"]+)?(/\d{4}/\d{2}/[^"]+\.(?:jpg|jpeg|png|gif|svg|webp))"#i', '$1$2/wp-content/uploads$3"', $content, -1, $repair_count );
		if ( $repair_count > 0 ) {
			$changes[] = "repaired {$repair_count} src attribute(s) missing /wp-content/uploads/ (see 2026-07-27 fix note)";
			$content   = $repaired;
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

		// 2) Bare http:// images — upgrade to https:// wherever the batch
		// probe above confirmed the https version actually loads.
		foreach ( $https_map as $url => $ok ) {
			if ( $ok && false !== strpos( $content, $url ) ) {
				$https     = 'https://' . substr( $url, 7 );
				$content   = str_replace( $url, $https, $content );
				$changes[] = "upgraded {$url} to https";
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
				// $path is URL-style ("/wp-content/uploads/2023/12/x.jpg");
				// $uploads['basedir'] is a filesystem path that ALREADY ends
				// in ".../wp-content/uploads" — concatenating them verbatim
				// double-counts that segment and file_exists() never finds
				// anything real, even when the file is fine. Strip the
				// duplicated prefix from $path first.
				$local = $uploads['basedir'] . preg_replace( '#^/wp-content/uploads#', '', $path );
				if ( file_exists( $local ) ) {
					continue; // exists after all — an earlier fix in this same pass may have moved it.
				}
				$basename = wp_basename( $path );
				$new_path = isset( $uploads_index[ strtolower( $basename ) ] ) ? $uploads_index[ strtolower( $basename ) ] : '';
				if ( $new_path && $new_path !== $path ) {
					$content   = str_replace( $path, $new_path, $content );
					$changes[] = "relocated {$basename} to {$new_path}";
				} elseif ( $new_path ) {
					continue; // already correct — case-insensitive match found itself.
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

	$commit    = ! empty( $_GET['commit'] );
	// Not just blog posts: the same wrong-upload-date-folder bug that hits
	// old news posts also turned up on at least one static Page
	// (sell-social-media-handles, a services page with an embedded team
	// photo grid) — confirmed live, so both post types are scanned.
	$posts     = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) );
	$contents  = array();
	foreach ( $posts as $post_id ) {
		$contents[ $post_id ] = get_post_field( 'post_content', $post_id );
	}
	// One reachability probe per unique external URL for the whole run
	// (see db_fix_post_images_https_map() docblock for why this matters).
	$https_map     = db_fix_post_images_https_map( db_fix_post_images_find_http_urls( $contents ) );
	$uploads_index = db_fix_post_images_uploads_index();

	$report = array();
	foreach ( $posts as $post_id ) {
		list( $new, $changes ) = db_fix_post_images_in_content( $contents[ $post_id ], $https_map, $uploads_index );
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

/*
 * Category archives (the guides blog at /category/domain-guides/ and the news
 * categories) use the same cards and grid as /news/, under a masthead with the
 * category name and description.
 */
add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_category() ) {
		return;
	}
	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return;
	}
	ob_start( function ( $html ) use ( $term ) {
		$guides = 'domain-guides' === $term->slug;
		$title  = $guides ? 'Domain Guides' : $term->name;
		$desc   = trim( wp_strip_all_tags( term_description( $term ) ) );
		if ( '' === $desc ) {
			$desc = $guides
				? 'Practical guides to buying, valuing, transferring and marketing premium domain names, written by the Domain Brothers team.'
				: sprintf( 'Posts filed under %s on the Domain Brothers blog.', $term->name ); // no false "collected daily" (CR-7)
		}
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		$head  = '<header class="db-an-head db-an-head--cat"><p class="db-an-day">' . ( 'domain-news' === $term->slug ? 'Domain news' : 'The Domain Brothers blog' ) . '</p><h1 class="db-an-h1">' . esc_html( $title ) . ( $paged > 1 ? ' <span class="db-an-page">Page ' . (int) $paged . '</span>' : '' ) . '</h1><p class="db-an-sub">' . esc_html( $desc ) . '</p></header>';
		$out   = preg_replace( '#<header class="page-header">.*?</header>#s', '', $html, 1 );
		$out   = null === $out ? $html : $out;
		$out   = db_news_own_cards( $out );
		$pos   = strpos( $out, '<div class="db-an-grid db-an-own">' );
		if ( false === $pos ) {
			return $html;
		}
		$end = strpos( $out, '</div>', strrpos( $out, '</a></article>' ) );
		if ( false === $end ) {
			return $html;
		}
		$out = substr_replace( $out, '</div></div>', $end, strlen( '</div>' ) );
		return substr_replace( $out, '<div class="db-an db-an--cat">' . $head, $pos, 0 );
	} );
}, 20 );

// A fuller first page for category archives: 12 cards (three rows of four).
add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() && $q->is_main_query() && $q->is_category() ) {
		$q->set( 'posts_per_page', 12 );
	}
} );
