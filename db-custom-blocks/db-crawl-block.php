<?php
/**
 * Crawl engineering: one URL per page, no crawl traps, deliberate bot rules.
 *
 *   - Uppercase and double-slash paths 301 to their normal form (WordPress served
 *     /About-Us/ as a second copy of /about-us/).
 *   - /page/N/ on the front page and on static pages 301s to the page (it served the
 *     same page under endless addresses: /page/9999/ answered 200).
 *   - Filter and search URLs (?sort=, ?tld=, ?s= ...) send "X-Robots-Tag: noindex, follow".
 *   - Every HTML page that has no canonical gets one, built from the clean URL (tracking
 *     and filter parameters removed).
 *   - robots.txt is written here: search engines and AI answer crawlers allowed, private
 *     and transactional routes and faceted URLs disallowed, known abusive scrapers
 *     blocked; AI-training crawlers can be blocked with the option db_crawl_block_ai_training.
 *
 * The rules are pure functions (tests/seo/crawl-test.php); the hooks only apply them.
 *
 * @package DB_Custom_Blocks
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_crawl_tracking_params' ) ) {
	/** Query parameters that only track a visit: never part of a page's address. */
	function db_crawl_tracking_params() {
		return array( 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'mc_cid', 'mc_eid', 'igshid', 'yclid', 'twclid', '_ga', '_gl', 'ref', 'qa_nc', 'qa' );
	}
}

if ( ! function_exists( 'db_crawl_facet_params' ) ) {
	/** Filter, sort and search parameters: useful to visitors, not pages of their own. */
	function db_crawl_facet_params() {
		return array( 's', 'q', 'sort', 'order', 'orderby', 'tld', 'ext', 'max', 'min', 'len', 'length', 'view', 'filter', 'cat', 'price' );
	}
}

if ( ! function_exists( 'db_crawl_clean_url' ) ) {
	/**
	 * Pure: the canonical form of a URL. Tracking and facet parameters are removed, other
	 * parameters are kept in a stable order, repeated slashes collapse and /page/1/ goes.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	function db_crawl_clean_url( $url ) {
		$p = parse_url( (string) $url );
		if ( empty( $p['host'] ) ) {
			return (string) $url;
		}
		$path = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
		$path = preg_replace( '#/{2,}#', '/', $path );
		$path = preg_replace( '#/page/1/?$#', '/', $path );
		$keep = array();
		if ( ! empty( $p['query'] ) ) {
			parse_str( $p['query'], $q );
			$drop = array_merge( db_crawl_tracking_params(), db_crawl_facet_params() );
			foreach ( (array) $q as $k => $v ) {
				$lk = strtolower( (string) $k );
				if ( in_array( $lk, $drop, true ) || 0 === strpos( $lk, 'utm_' ) ) {
					continue;
				}
				$keep[ $k ] = $v;
			}
			ksort( $keep );
		}
		$scheme = isset( $p['scheme'] ) ? strtolower( $p['scheme'] ) : 'https';
		return $scheme . '://' . strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path . ( $keep ? '?' . http_build_query( $keep ) : '' );
	}
}

if ( ! function_exists( 'db_crawl_canonical_url' ) ) {
	/**
	 * Pure: the canonical address of a request: the clean path, with no query string at all.
	 * No page on this site has indexable content that depends on a query parameter (checkout,
	 * offer and tool-result URLs are noindex), so ?x= variants all point at the page.
	 */
	function db_crawl_canonical_url( $url ) {
		$clean = db_crawl_clean_url( $url );
		$q     = strpos( $clean, '?' );
		return false === $q ? $clean : substr( $clean, 0, $q );
	}
}

if ( ! function_exists( 'db_crawl_normal_path' ) ) {
	/**
	 * Pure: the normal form of a request path, or '' when it is already normal or must not be
	 * touched (WordPress internals, files, encoded characters).
	 *
	 * @param string $path Request path, without the query string.
	 * @return string
	 */
	function db_crawl_normal_path( $path ) {
		$path = (string) $path;
		if ( '' === $path || '/' === $path || false !== strpos( $path, '%' ) ) {
			return '';
		}
		if ( preg_match( '#^/(wp-admin|wp-json|wp-content|wp-includes|wp-login|xmlrpc|feed)(/|\.|$)#i', $path ) || preg_match( '#\.[a-z0-9]{2,5}$#i', $path ) ) {
			return '';
		}
		$norm = strtolower( preg_replace( '#/{2,}#', '/', $path ) );
		return $norm === $path ? '' : $norm;
	}
}

if ( ! function_exists( 'db_crawl_noindex_query' ) ) {
	/**
	 * Pure: true when a request's query string makes it a filter or search view that should
	 * not be indexed (it is still followed, so the names it lists are found).
	 *
	 * @param array $query_keys Keys of $_GET.
	 * @return bool
	 */
	function db_crawl_noindex_query( array $query_keys ) {
		$facets = db_crawl_facet_params();
		foreach ( $query_keys as $k ) {
			if ( in_array( strtolower( (string) $k ), $facets, true ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'db_crawl_robots_txt' ) ) {
	/**
	 * Pure: robots.txt for the production site.
	 *
	 * @param string $sitemap       Sitemap index URL.
	 * @param bool   $block_training Also block AI-training crawlers (answer and search crawlers stay allowed).
	 * @return string
	 */
	function db_crawl_robots_txt( $sitemap, $block_training = false ) {
		$private = array( '/wp-admin/', '/my-wishlist/', '/my-account/', '/account/', '/seller/', '/buy-now/', '/payment-plan-setup/', '/thank-you/', '/make-an-offer/', '/register/', '/sign-in/', '/connect-store/', '/search/' );
		$lines   = array( '# Domain Brothers robots.txt (written by the db-custom-blocks plugin).', '# Search engines and AI answer crawlers are welcome on every public page.', '', 'User-agent: *' );
		foreach ( $private as $p ) {
			$lines[] = 'Disallow: ' . $p;
		}
		$lines[] = 'Allow: /wp-admin/admin-ajax.php';
		// Faceted and tracking URLs: the same pages again under endless parameter combinations.
		foreach ( array( '/?s=', '/*?s=', '/*&s=', '/*?*sort=', '/*?*orderby=', '/*?*tld=', '/*?*max=', '/*?*min=', '/*?*len=', '/*?*qa_nc=', '/*?*replytocom=' ) as $p ) {
			$lines[] = 'Disallow: ' . $p;
		}
		$lines[] = '';
		// Scrapers that ignore crawl rate and send no search traffic.
		foreach ( array( 'Bytespider', 'MJ12bot', 'DotBot', 'BLEXBot', 'DataForSeoBot', 'serpstatbot' ) as $bot ) {
			$lines[] = 'User-agent: ' . $bot;
			$lines[] = 'Disallow: /';
			$lines[] = '';
		}
		if ( $block_training ) {
			// Model-training crawlers only; ChatGPT-User, OAI-SearchBot, PerplexityBot, Claude-SearchBot
			// and Claude-User fetch pages to answer people and stay allowed.
			foreach ( array( 'GPTBot', 'Google-Extended', 'CCBot', 'anthropic-ai', 'ClaudeBot', 'Applebot-Extended', 'meta-externalagent', 'cohere-training-data-crawler' ) as $bot ) {
				$lines[] = 'User-agent: ' . $bot;
				$lines[] = 'Disallow: /';
				$lines[] = '';
			}
		}
		$lines[] = 'Sitemap: ' . $sitemap;
		return implode( "\n", $lines ) . "\n";
	}
}

if ( ! function_exists( 'db_crawl_insert_canonical' ) ) {
	/**
	 * Pure: adds <link rel="canonical"> to a page's head when it has none.
	 *
	 * @param string $html Whole page.
	 * @param string $url  Canonical URL.
	 * @return string
	 */
	function db_crawl_insert_canonical( $html, $url ) {
		if ( ! is_string( $html ) || '' === $url || false === stripos( $html, '</head>' ) ) {
			return $html;
		}
		$head_end = stripos( $html, '</head>' );
		if ( preg_match( '#<link\b[^>]*\brel=["\']?canonical\b#i', substr( $html, 0, $head_end ) ) ) {
			return $html;
		}
		$tag = '<link rel="canonical" href="' . htmlspecialchars( $url, ENT_QUOTES ) . '">' . "\n";
		return substr( $html, 0, $head_end ) . $tag . substr( $html, $head_end );
	}
}

/* ------------------------------------------------------------------ hooks */

if ( ! function_exists( 'db_crawl_is_front_request' ) ) {
	function db_crawl_is_front_request() {
		$m = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return in_array( $m, array( 'GET', 'HEAD' ), true ) && ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && ! is_feed() && ! is_robots();
	}
}

// 1. Normal paths: one address per page.
add_action( 'template_redirect', function () {
	if ( ! db_crawl_is_front_request() || is_404() ) {
		return;
	}
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	$norm = db_crawl_normal_path( $path );
	if ( '' === $norm ) {
		return;
	}
	$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
	wp_safe_redirect( home_url( $norm ) . ( '' !== $query ? '?' . $query : '' ), 301 );
	exit;
}, 0 );

// 2. /page/N/ on the front page and static pages is the same page again: send it home.
add_action( 'template_redirect', function () {
	if ( ! db_crawl_is_front_request() || ! is_page() ) {
		return;
	}
	$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
	if ( $paged < 2 ) {
		return;
	}
	// A page whose own content is split with <!--nextpage--> keeps its sub-pages.
	$post = get_queried_object();
	if ( $post instanceof WP_Post && false !== strpos( (string) $post->post_content, '<!--nextpage-->' ) ) {
		return;
	}
	$to = is_front_page() ? home_url( '/' ) : get_permalink( $post );
	if ( $to ) {
		wp_safe_redirect( $to, 301 );
		exit;
	}
}, 2 );

// 3. Filter and search views: followed, not indexed.
add_action( 'template_redirect', function () {
	if ( ! db_crawl_is_front_request() || headers_sent() ) {
		return;
	}
	if ( is_search() || db_crawl_noindex_query( array_keys( (array) $_GET ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		header( 'X-Robots-Tag: noindex, follow', false );
	}
}, 3 );

// 4. A canonical on every page that has none (virtual pages, staging, theme templates).
add_action( 'template_redirect', function () {
	if ( ! db_crawl_is_front_request() || is_404() || is_search() ) {
		return;
	}
	$scheme = is_ssl() ? 'https' : 'http';
	$host   = isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : (string) wp_parse_url( home_url(), PHP_URL_HOST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$url    = db_crawl_canonical_url( $scheme . '://' . $host . $uri );
	ob_start( static function ( $html ) use ( $url ) {
		return ( is_string( $html ) && false !== stripos( $html, '<html' ) ) ? db_crawl_insert_canonical( $html, $url ) : $html;
	} );
}, 99 );

// 5. robots.txt (the staging rule in db-safety-block.php still replaces it on staging hosts).
add_filter( 'robots_txt', function ( $output ) {
	return db_crawl_robots_txt( home_url( '/sitemap_index.xml' ), '1' === (string) get_option( 'db_crawl_block_ai_training', '0' ) );
}, 50 );
