<?php
/**
 * One page per domain tool: /domain-tools/<slug>/.
 *
 * Each page targets a low-difficulty search phrase found in SE Ranking research
 * (tools/seo-tools-keywords.json) and carries a working tool, an explanation of the
 * result, FAQs with FAQPage markup, links to the related tools and a call to action.
 * The pages are virtual routes (like /payment-terms/), so this file also supplies what
 * Rank Math would for a real page: title, description, canonical, Open Graph tags,
 * WebApplication, FAQPage and BreadcrumbList markup, and a sitemap
 * (/domain-tools-sitemap.xml, listed in robots.txt and in Rank Math's sitemap index).
 *
 * The lookups themselves live in db-domain-lookup-block.php.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_tp_pages' ) ) {
	/** @return array<string,array<string,mixed>> */
	function db_tp_pages() {
		static $pages = null;
		if ( null === $pages ) {
			$file  = __DIR__ . '/db-tool-pages-data.php';
			$pages = is_readable( $file ) ? (array) include $file : array();
		}
		return $pages;
	}
}

if ( ! function_exists( 'db_tp_slug' ) ) {
	/** Slug of the tool page being requested, or ''. */
	function db_tp_slug() {
		if ( is_admin() || ! function_exists( 'db_sales_request_path' ) ) {
			return '';
		}
		$path = db_sales_request_path();
		if ( 0 !== strpos( $path, 'domain-tools/' ) ) {
			return '';
		}
		$slug = substr( $path, strlen( 'domain-tools/' ) );
		return isset( db_tp_pages()[ $slug ] ) ? $slug : '';
	}
}

if ( ! function_exists( 'db_tp_url' ) ) {
	function db_tp_url( $slug = '' ) {
		return home_url( '/domain-tools/' . ( '' === $slug ? '' : $slug . '/' ) );
	}
}

if ( ! function_exists( 'db_tp_css' ) ) {
	function db_tp_css() {
		return '.dbtp{max-width:1000px;margin:0 auto;padding:0 20px 56px;color:#374151}'
			. '.dbtp h2{margin:0 0 12px;font-size:clamp(22px,2.6vw,28px);line-height:1.2;color:#0f172a}'
			. '.dbtp p{margin:0 0 14px;font-size:17px;line-height:1.65}'
			. '.dbtp-back{display:inline-block;margin:0 0 14px;font-size:15px;font-weight:600;color:#0f1b2d;text-decoration:underline}'
			. '.dbtp-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:36px 0;padding:0;list-style:none;counter-reset:s}'
			. '.dbtp-steps li{counter-increment:s;padding:18px;border:1px solid #e4e7ec;border-radius:14px;background:#fff}'
			. '.dbtp-steps li::before{content:counter(s);display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;margin:0 0 10px;border-radius:50%;background:#b5e61d;color:#0f1b2d;font-weight:800}'
			. '.dbtp-steps strong{display:block;margin:0 0 4px;color:#0f172a;font-size:17px}.dbtp-steps span{font-size:15px;line-height:1.55}'
			. '.dbtp-sec{margin:34px 0}.dbtp-faq{margin:40px 0}.dbtp-faq details{border:1px solid #e4e7ec;border-radius:12px;background:#fff;margin:0 0 10px}'
			. '.dbtp-faq summary{cursor:pointer;padding:16px 18px;font-weight:700;color:#0f172a;list-style:none;display:flex;justify-content:space-between;gap:12px}'
			. '.dbtp-faq summary::-webkit-details-marker{display:none}.dbtp-faq summary::after{content:"+";font-size:22px;line-height:1;color:#0f1b2d}.dbtp-faq details[open] summary::after{content:"\2212"}'
			. '.dbtp-faq details p{padding:0 18px 16px;margin:0;font-size:16px}'
			. '.dbtp-rel{margin:40px 0 0;padding:24px;border-radius:16px;background:#f1f3f6}.dbtp-rel ul{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0;padding:0;list-style:none}'
			. '.dbtp-rel a{display:block;height:100%;padding:14px 16px;border:1px solid #e4e7ec;border-radius:12px;background:#fff;color:#0f172a;font-weight:700;text-decoration:none}.dbtp-rel a:hover{border-color:#0f1b2d}'
			. '.dbtp-rel small{display:block;margin-top:4px;font-weight:400;font-size:14px;color:#5a6475}'
			. '.dbtp-cta{margin:28px 0 0}.dbtp-cta a{display:inline-flex;padding:15px 24px;border-radius:12px;background:#0f1b2d;color:#fff!important;font-weight:700;text-decoration:none}'
			. '.dbtp-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:36px 0 0;padding:0;list-style:none}'
			. '.dbtp-grid a{display:block;height:100%;padding:18px;border:1px solid #e4e7ec;border-radius:14px;background:#fff;color:#0f172a;text-decoration:none}.dbtp-grid a:hover{border-color:#0f1b2d;box-shadow:0 10px 24px -16px rgba(15,27,45,.4)}'
			. '.dbtp-grid strong{display:block;font-size:17px;margin:0 0 4px}.dbtp-grid span{font-size:14px;line-height:1.5;color:#5a6475}'
			. '@media (max-width:820px){.dbtp-steps,.dbtp-rel ul,.dbtp-grid{grid-template-columns:minmax(0,1fr)}}';
	}
}

if ( ! function_exists( 'db_tp_hub_grid' ) ) {
	/** The list of tools, shown on the /domain-tools/ page. */
	function db_tp_hub_grid( $html ) {
		$h = '<div class="dbtp"><style>' . db_tp_css() . '</style><h2>All domain tools</h2><ul class="dbtp-grid">';
		foreach ( db_tp_pages() as $slug => $p ) {
			$h .= '<li><a href="' . esc_url( db_tp_url( $slug ) ) . '"><strong>' . esc_html( $p['name'] ) . '</strong><span>' . esc_html( $p['lead'] ) . '</span></a></li>';
		}
		return $html . $h . '</ul></div>';
	}
}
add_filter( 'db_lk_hub_tools', 'db_tp_hub_grid' );

if ( ! function_exists( 'db_tp_body' ) ) {
	/** Page body for one tool. */
	function db_tp_body( $slug ) {
		$p = db_tp_pages()[ $slug ];
		$h = '<p class="db-sales-lead">' . esc_html( $p['lead'] ) . '</p>';
		$h .= db_lk_widget( array( 'show' => $p['show'], 'parts' => $p['parts'], 'autoext' => ! empty( $p['autoext'] ), 'button' => $p['button'] ), isset( $p['label'] ) ? $p['label'] : 'Domain name', isset( $p['hint'] ) ? $p['hint'] : 'example.com' );
		$h .= '<div class="dbtp"><style>' . db_tp_css() . '</style>';
		$h .= '<ol class="dbtp-steps">';
		foreach ( $p['steps'] as $s ) {
			$h .= '<li><strong>' . esc_html( $s[0] ) . '</strong><span>' . esc_html( $s[1] ) . '</span></li>';
		}
		$h .= '</ol>';
		foreach ( $p['sections'] as $sec ) {
			$h .= '<section class="dbtp-sec"><h2>' . esc_html( $sec[0] ) . '</h2>';
			foreach ( $sec[1] as $para ) {
				$h .= '<p>' . esc_html( $para ) . '</p>';
			}
			$h .= '</section>';
		}
		$h .= '<section class="dbtp-faq"><h2>Frequently asked questions</h2>';
		foreach ( $p['faqs'] as $qa ) {
			$h .= '<details><summary>' . esc_html( $qa[0] ) . '</summary><p>' . esc_html( $qa[1] ) . '</p></details>';
		}
		$h .= '</section>';
		$h .= '<section class="dbtp-rel"><h2>More free domain tools</h2><ul>';
		$all = db_tp_pages();
		foreach ( $p['related'] as $r ) {
			if ( isset( $all[ $r ] ) ) {
				$h .= '<li><a href="' . esc_url( db_tp_url( $r ) ) . '">' . esc_html( $all[ $r ]['name'] ) . '<small>' . esc_html( $all[ $r ]['title'] ) . '</small></a></li>';
			}
		}
		$h .= '<li><a href="' . esc_url( db_tp_url() ) . '">All domain tools<small>Every free lookup in one place</small></a></li></ul>';
		$h .= '<p class="dbtp-cta"><a href="' . esc_url( home_url( '/all-domains/' ) ) . '">Buy a premium domain now</a></p></section></div>';
		return $h;
	}
}

if ( ! function_exists( 'db_tp_route_meta' ) ) {
	/**
	 * Title, description and address for the tools hub or a tool page; null elsewhere.
	 *
	 * @return array{0:string,1:string,2:string}|null
	 */
	function db_tp_route_meta() {
		if ( is_admin() || ! function_exists( 'db_sales_request_path' ) ) {
			return null;
		}
		if ( 'domain-tools' === db_sales_request_path() ) {
			return array( 'Free Domain Tools: Lookup, SSL, DNS, Email Security and Extensions | Domain Brothers', 'Free domain tools: registry record, domain age and expiry, DNS, SSL certificate, HTTP and security headers, DMARC and SPF, IP address and web history. No sign-up.', db_tp_url() );
		}
		$slug = db_tp_slug();
		if ( '' === $slug ) {
			return null;
		}
		$p = db_tp_pages()[ $slug ];
		return array( $p['title'] . ' | Domain Brothers', $p['meta'], db_tp_url( $slug ) );
	}
}

// Rank Math reads these routes as 404s (title, robots, Open Graph); each value is set back here.
add_filter( 'pre_get_document_title', static function ( $t ) { $m = db_tp_route_meta(); return $m ? $m[0] : $t; }, 40 );
add_filter( 'rank_math/frontend/title', static function ( $t ) { $m = db_tp_route_meta(); return $m ? $m[0] : $t; }, 40 );
add_filter( 'rank_math/frontend/description', static function ( $t ) { $m = db_tp_route_meta(); return $m ? $m[1] : $t; }, 40 );
// Rank Math prints no canonical on these routes, so the page prints its own (below) and Rank Math is told to print none.
add_filter( 'rank_math/frontend/canonical', static function ( $t ) { return db_tp_route_meta() ? false : $t; }, 40 );
add_filter( 'rank_math/opengraph/facebook/og_title', static function ( $t ) { $m = db_tp_route_meta(); return $m ? $m[0] : $t; }, 40 );
add_filter( 'rank_math/opengraph/facebook/og_description', static function ( $t ) { $m = db_tp_route_meta(); return $m ? $m[1] : $t; }, 40 );
add_filter( 'rank_math/opengraph/facebook/og_url', static function ( $t ) { $m = db_tp_route_meta(); return $m ? $m[2] : $t; }, 40 );
add_filter( 'rank_math/opengraph/twitter/twitter_title', static function ( $t ) { $m = db_tp_route_meta(); return $m ? $m[0] : $t; }, 40 );
add_filter( 'rank_math/opengraph/twitter/twitter_description', static function ( $t ) { $m = db_tp_route_meta(); return $m ? $m[1] : $t; }, 40 );
// Indexable on production. Runs before the staging rule (priority 30 in db-rankmath-block.php), which still wins on beta.
add_filter( 'rank_math/frontend/robots', static function ( $robots ) {
	if ( db_tp_route_meta() && is_array( $robots ) ) {
		$robots['index']  = 'index';
		$robots['follow'] = 'follow';
	}
	return $robots;
}, 25 );

if ( ! function_exists( 'db_tp_head' ) ) {
	/** Structured data for a tool page, and the head tags when Rank Math is not providing them. */
	function db_tp_head( $slug ) {
		$p     = db_tp_pages()[ $slug ];
		$url   = db_tp_url( $slug );
		$title = $p['title'] . ' | Domain Brothers';
		$faq   = array();
		foreach ( $p['faqs'] as $qa ) {
			$faq[] = array( '@type' => 'Question', 'name' => $qa[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $qa[1] ) );
		}
		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => array(
				array( '@type' => 'WebApplication', 'name' => $p['name'], 'url' => $url, 'description' => $p['meta'], 'applicationCategory' => 'UtilitiesApplication', 'operatingSystem' => 'Any', 'offers' => array( '@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'USD' ), 'publisher' => array( '@type' => 'Organization', 'name' => 'Domain Brothers', 'url' => home_url( '/' ) ) ),
				array( '@type' => 'FAQPage', 'mainEntity' => $faq ),
				array( '@type' => 'BreadcrumbList', 'itemListElement' => array(
					array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url( '/' ) ),
					array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Domain tools', 'item' => db_tp_url() ),
					array( '@type' => 'ListItem', 'position' => 3, 'name' => $p['name'], 'item' => $url ),
				) ),
			),
		);
		add_action( 'wp_head', static function () use ( $p, $url, $title, $graph ) {
			echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
			if ( ! ( defined( 'DB_RANKMATH_OWNS_HEAD' ) && DB_RANKMATH_OWNS_HEAD ) ) {
				echo '<meta name="description" content="' . esc_attr( $p['meta'] ) . '">' . "\n";
				echo '<meta property="og:type" content="website"><meta property="og:site_name" content="Domain Brothers"><meta property="og:title" content="' . esc_attr( $title ) . '"><meta property="og:description" content="' . esc_attr( $p['meta'] ) . '"><meta property="og:url" content="' . esc_url( $url ) . '">' . "\n";
			}
			echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}, 1 );
	}
}

add_action( 'template_redirect', function () {
	$slug = db_tp_slug();
	if ( '' === $slug ) {
		return;
	}
	db_tp_head( $slug );
	$p = db_tp_pages()[ $slug ];
	db_sales_render( $p['name'], $p['name'], '<p><a class="dbtp-back" href="' . esc_url( db_tp_url() ) . '">&larr; All domain tools</a></p>' . db_tp_body( $slug ) );
}, 11 );

/* ----------------------------------------------------------------- sitemap */

if ( ! function_exists( 'db_tp_sitemap_xml' ) ) {
	/** @return string XML listing the tools hub and every tool page. */
	function db_tp_sitemap_xml() {
		$lastmod = gmdate( 'c', (int) filemtime( __DIR__ . '/db-tool-pages-data.php' ) );
		$xml     = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		$urls    = array( db_tp_url() );
		foreach ( array_keys( db_tp_pages() ) as $slug ) {
			$urls[] = db_tp_url( $slug );
		}
		foreach ( $urls as $u ) {
			$xml .= '<url><loc>' . esc_url( $u ) . '</loc><lastmod>' . esc_html( $lastmod ) . '</lastmod></url>' . "\n";
		}
		return $xml . '</urlset>' . "\n";
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) || 'domain-tools-sitemap.xml' !== db_sales_request_path() ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: application/xml; charset=UTF-8' );
	header( 'X-Robots-Tag: noindex' );
	echo db_tp_sitemap_xml(); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}, 2 );

add_filter( 'robots_txt', function ( $output ) {
	return $output . "\nSitemap: " . home_url( '/domain-tools-sitemap.xml' ) . "\n";
} );

// Rank Math's sitemap index: add this sitemap alongside its own.
add_filter( 'rank_math/sitemap/index', function ( $xml ) {
	return $xml . '<sitemap><loc>' . esc_url( home_url( '/domain-tools-sitemap.xml' ) ) . '</loc><lastmod>' . esc_html( gmdate( 'c', (int) filemtime( __DIR__ . '/db-tool-pages-data.php' ) ) ) . '</lastmod></sitemap>';
} );
