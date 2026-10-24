<?php
/**
 * DB On-Page SEO Block — noscript fallback, image lazy-loading, 404 recovery
 *
 * Live audit of a real domain page (beta.domainbrothers.com/domains/fatality-ca/)
 * against a standard on-page SEO checklist found three genuine gaps —
 * everything else on the list (canonical tags, Open Graph/Twitter cards, a
 * meta keywords tag, BreadcrumbList structured data, rel=next/prev
 * pagination) was already implemented elsewhere in this plugin or the theme.
 * Deliberately NOT implemented from that same checklist: deliberately
 * stuffing extra "hidden text" beyond the legitimate .sr-only accessibility
 * use already on this site (footer social links) risks tripping Google's
 * hidden-text/cloaking guidelines for no real benefit: renaming already-live
 * uploaded image files (breaks every existing reference across hundreds of
 * posts for a minor, unproven ranking signal); content-strategy items
 * (anchor text, "keywordless" writing, content layering) are editorial
 * decisions, not something to patch in code.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

/* ─── 1. Descriptive <noscript> fallback ─────────────────────────────────── */

if ( ! function_exists( 'db_onpage_noscript_html' ) ) {
	function db_onpage_noscript_html() {
		$html  = '<noscript><div class="db-noscript-fallback" style="max-width:960px;margin:16px auto;padding:16px 20px;font-family:sans-serif;line-height:1.5;">';
		$html .= '<p>Domain Brothers is a premium domain name marketplace and brokerage — escrow-protected transfers and flexible payment plans on every listing. Some page features on this site need JavaScript; the links below work without it:</p>';
		$html .= '<ul>';
		$html .= '<li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse All Domains</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/our-services/' ) ) . '">Our Services</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/news/' ) ) . '">News</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Contact Us</a></li>';
		$html .= '</ul></div></noscript>';
		return $html;
	}
}

if ( ! function_exists( 'db_onpage_inject_noscript' ) ) {
	function db_onpage_inject_noscript( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		$pos = stripos( $html, '<body' );
		if ( false === $pos ) {
			return $html;
		}
		$tag_end = strpos( $html, '>', $pos );
		if ( false === $tag_end ) {
			return $html;
		}
		$insert_at = $tag_end + 1;
		return substr( $html, 0, $insert_at ) . db_onpage_noscript_html() . substr( $html, $insert_at );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_onpage_inject_noscript' );
}, 23 );

/* ─── 2. Lazy-load images that aren't already loading-eager ──────────────── */

if ( ! function_exists( 'db_onpage_lazy_load_images' ) ) {
	/**
	 * The custom logo already carries loading="eager" fetchpriority="high"
	 * (WordPress core sets this automatically) — every other <img> on the
	 * site, audited live, had no loading attribute at all: trust-badge
	 * icons, footer logos, news thumbnails. Only adding the attribute where
	 * one isn't already present respects that existing eager/priority
	 * choice instead of fighting it.
	 */
	function db_onpage_lazy_load_images( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}
		return preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) {
			$tag = $m[0];
			if ( false !== stripos( $tag, 'loading=' ) ) {
				return $tag;
			}
			return substr_replace( $tag, ' loading="lazy"', 4, 0 );
		}, $html );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_onpage_lazy_load_images' );
}, 24 );

/* ─── 3. 404 page: search box + way back to real content ─────────────────── */

if ( ! function_exists( 'db_onpage_404_helper_html' ) ) {
	function db_onpage_404_helper_html() {
		$html  = '<div class="db-404-helper">';
		$html .= '<p>Here are a few places to start instead:</p>';
		$html .= '<form role="search" method="get" class="db-404-search" action="' . esc_url( home_url( '/' ) ) . '">';
		$html .= '<label class="screen-reader-text" for="db-404-s">Search</label>';
		$html .= '<input type="search" id="db-404-s" name="s" placeholder="Search domains or pages…" value="">';
		$html .= '<button type="submit">Search</button>';
		$html .= '</form>';
		$html .= '<ul class="db-404-links">';
		$html .= '<li><a href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse All Domains</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/our-services/' ) ) . '">Our Services</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/news/' ) ) . '">News</a></li>';
		$html .= '<li><a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Contact Us</a></li>';
		$html .= '</ul></div>';
		$html .= '<style>.db-404-helper{max-width:420px;margin:28px auto 0;text-align:left;}';
		$html .= '.db-404-helper p{color:#5a6b85;font-size:15px;margin-bottom:12px;}';
		$html .= '.db-404-search{display:flex;gap:8px;margin-bottom:18px;}';
		$html .= '.db-404-search input{flex:1;padding:10px 14px;border:1px solid #d7e0ee;border-radius:8px;font-size:15px;}';
		$html .= '.db-404-search button{padding:10px 18px;border:0;border-radius:8px;background:#0a1628;color:#fff;font-size:14px;cursor:pointer;}';
		$html .= '.db-404-links{list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:10px 18px;justify-content:center;}';
		$html .= '.db-404-links a{color:#1857e0;font-size:14px;text-decoration:underline;}</style>';
		return $html;
	}
}

if ( ! function_exists( 'db_onpage_fix_404_page' ) ) {
	function db_onpage_fix_404_page( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		$marker = 'Back to Home</a>';
		$pos    = strpos( $html, $marker );
		if ( false === $pos ) {
			return $html;
		}
		$insert_at = $pos + strlen( $marker );
		return substr( $html, 0, $insert_at ) . db_onpage_404_helper_html() . substr( $html, $insert_at );
	}
}

add_action( 'template_redirect', function () {
	if ( ! is_404() ) {
		return;
	}
	ob_start( 'db_onpage_fix_404_page' );
}, 25 );

/* ─── 3b. Link/markup hygiene from the 2026-10-24 production HTTP audit ────── */

if ( ! function_exists( 'db_onpage_in_comment' ) ) {
	/** True when byte offset $pos sits inside an HTML comment. */
	function db_onpage_in_comment( $html, $pos ) {
		$head  = substr( $html, 0, $pos );
		$open  = strrpos( $head, '<!--' );
		$close = strrpos( $head, '-->' );
		return false !== $open && ( false === $close || $close < $open );
	}
}

if ( ! function_exists( 'db_onpage_has_live_h1' ) ) {
	/**
	 * True when the page has an <h1> outside an HTML comment. /sold/ ships
	 * its only heading as "<!--<h1 class="page-title">…-->", which a plain
	 * stripos() counted as present, so the page went out with no <h1>.
	 */
	function db_onpage_has_live_h1( $html ) {
		$offset = 0;
		while ( false !== ( $pos = stripos( $html, '<h1', $offset ) ) ) {
			$next = substr( $html, $pos + 3, 1 );
			if ( ( '>' === $next || ctype_space( $next ) ) && ! db_onpage_in_comment( $html, $pos ) ) {
				return true;
			}
			$offset = $pos + 3;
		}
		return false;
	}
}

if ( ! function_exists( 'db_onpage_html_hygiene' ) ) {
	/**
	 * Fixes the audit's site-wide markup failures in one pass:
	 *  - target="_blank" links without rel="noopener" (footer social icons on
	 *    every page, outbound news-source links, domain "visit" links);
	 *  - http:// links/images to hosts verified to serve valid HTTPS
	 *    (own domain, domaining.com, domaingang.com, thedomains.com,
	 *    domainnamewire.com, dnqueen.net, morganlinton.com, dnjournal.com,
	 *    domainingtips.com, circleid.com, tldinvestors.com, blog.101domain.com,
	 *    domainsherpa.com, dotweekly.com; each checked 2026-10-24). Hosts without working TLS (domainpulse.com
	 *    expired cert, dropgrabs.com, parked .ca names) are left alone,
	 *    since rewriting them would break the link;
	 *  - the theme repeating id="browse_by_domain" on every sidebar table;
	 *  - /payment-cancel/'s template, which builds asset URLs as
	 *    home_url() . 'wp-content/…' ("…domainbrothers.comwp-content", so
	 *    the page rendered unstyled) and hard-codes <title>DomainBrothers</title>.
	 */
	function db_onpage_html_hygiene( $html ) {
		// Pasted content on /epp-guidelines/ uses curly quotes (rel=”nofollow”),
		// which browsers read as part of the value, not as a quoted attribute.
		if ( false !== strpos( $html, '=”' ) ) {
			$html = preg_replace( '/(<a\b[^>]*\s(?:rel|target))=”([^”"<>]*)”/u', '$1="$2"', $html );
		}
		if ( false !== stripos( $html, 'target=' ) ) {
			$html = preg_replace_callback( '/<a\b[^>]*\btarget\s*=\s*["\']_blank["\'][^>]*>/i', function ( $m ) {
				$tag = $m[0];
				if ( preg_match( '/\srel\s*=\s*(["\'])([^"\']*)\1/i', $tag, $r ) ) {
					if ( false !== stripos( $r[2], 'noopener' ) ) {
						return $tag;
					}
					return str_replace( $r[0], ' rel=' . $r[1] . trim( $r[2] . ' noopener' ) . $r[1], $tag );
				}
				return substr_replace( $tag, ' rel="noopener"', 2, 0 );
			}, $html );
		}

		if ( false !== stripos( $html, 'http://' ) ) {
			$home  = preg_quote( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '#' );
			$hosts = '(?:www\.)?(?:domainbrothers\.com|domaining\.com|domaingang\.com|thedomains\.com|domainnamewire\.com|dnqueen\.net|morganlinton\.com|dnjournal\.com|domainingtips\.com|circleid\.com|tldinvestors\.com|blog\.101domain\.com|domainsherpa\.com|dotweekly\.com)|' . $home;
			$html  = preg_replace( '#(\s(?:href|src)\s*=\s*["\'])http://(' . $hosts . ')(?=[/"\'?:])#i', '$1https://$2', $html );
		}

		// Internal links without a trailing slash ("/domain-category/business",
		// "/privacy-policy" in the theme's footer and sidebar on every page)
		// each cost a 301; domain links with capitals ("/domains/skiClub-ca/")
		// point at a non-canonical URL. Rewrite both to the real permalink.
		$host = preg_quote( (string) wp_parse_url( home_url(), PHP_URL_HOST ), '#' );
		$html = preg_replace_callback( '~(\shref=["\'])(https://' . $host . ')?(/[A-Za-z0-9/_-]*[A-Za-z0-9_-])(["\'#?])~', function ( $m ) {
			if ( '' === $m[2] && 0 === strpos( $m[3], '//' ) ) {
				return $m[0];
			}
			$path = $m[3];
			if ( 0 === stripos( $path, '/domains/' ) ) {
				$path = strtolower( $path );
			}
			if ( preg_match( '#^/(?:wp-|cdn-cgi/|okf/)#', $path ) ) {
				return $m[0];
			}
			return $m[1] . $m[2] . $path . '/' . $m[4];
		}, $html );
		$html = preg_replace_callback( '#(\shref=["\'](?:https://' . $host . ')?/domains/)([^"\'/]*[A-Z][^"\'/]*)#', function ( $m ) {
			return $m[1] . strtolower( $m[2] );
		}, $html );

		$first = strpos( $html, 'id="browse_by_domain"' );
		if ( false !== $first ) {
			$n      = 1;
			$offset = $first + 21;
			while ( false !== ( $pos = strpos( $html, 'id="browse_by_domain"', $offset ) ) ) {
				++$n;
				$html   = substr_replace( $html, 'id="browse_by_domain-' . $n . '"', $pos, 21 );
				$offset = $pos + 21;
			}
		}

		if ( false !== strpos( $html, '.comwp-content/' ) ) {
			$html = str_replace( '.comwp-content/', '.com/wp-content/', $html );
		}
		if ( ! empty( $GLOBALS['db_onpage_ctx']['payment_cancel'] ) && function_exists( 'db_seo_utility_slugs' ) ) {
			$slugs = db_seo_utility_slugs();
			$html  = str_replace( '<title>DomainBrothers</title>', '<title>' . esc_html( $slugs['payment-cancel'] ) . '</title>', $html );
		}
		return $html;
	}
}

/*
 * Old news posts hotlink third-party images over http://. The site's CSP
 * (upgrade-insecure-requests) loads them over https, and several source
 * hosts no longer serve them at all (domainpulse.com: 403/expired cert,
 * dropgrabs.com: offline), so readers saw broken-image boxes. Hide any
 * image that fails to load; registered in <head> so it catches errors that
 * fire before DOMContentLoaded.
 */
add_action( 'wp_head', function () {
	echo "<script>document.addEventListener('error',function(e){var t=e.target;if(t&&t.tagName==='IMG'&&t.closest&&t.closest('.site-main, main, article')){t.style.display='none';var c=t.closest('.wp-caption');if(c){c.style.display='none';}}},true);</script>\n";
}, 2 );

/* ─── 4. Pages with no <h1>, and <img> tags with no alt attribute ─────────── */

if ( ! function_exists( 'db_onpage_fix_h1_and_alt' ) ) {
	/**
	 * Crawl of ~170 sampled pages found two static pages (/domain-transfer/,
	 * /4-character-domains/) whose templates render no <h1> at all, and
	 * theme images (security/ICANN badges, contact icons) with no alt
	 * attribute. db_seo_promote_archive_h1() only covers archives, so this
	 * handles the remaining singular pages: promote a .page-title heading if
	 * the template has one, otherwise add a screen-reader-only <h1> carrying
	 * the real page title (a11y text, not extra keywords). Plain string
	 * work only — no backtracking-prone patterns on page-sized input.
	 */
	function db_onpage_fix_h1_and_alt( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		// Not the front page: its hero <h1> is added by a later output buffer,
		// so at this point the page looked h1-less and "Featured Top Domains"
		// was promoted too — two <h1>s on the homepage (tests/qa SEO-home-h1).
		$html = db_onpage_html_hygiene( $html );
		// Context captured at template_redirect: templates such as /sold/
		// call query_posts(), so is_singular() is false by flush time.
		$ctx = isset( $GLOBALS['db_onpage_ctx'] ) ? $GLOBALS['db_onpage_ctx'] : array( 'singular' => false, 'title' => '' );
		if ( $ctx['singular'] && ! db_onpage_has_live_h1( $html ) ) {
			$done = false;
			// Skip commented-out copies: /sold/ keeps its old heading as
			// "<!--<h2 class="page-title">…-->" just above the live one.
			$offset = 0;
			while ( ! $done && preg_match( '#<h2((?:\s+[a-z-]+="[^"]*")*\s+class="[^"]*\bpage-title\b[^"]*"(?:\s+[a-z-]+="[^"]*")*)>([^<]*(?:<(?!/h2)[^<]*)*)</h2>#i', $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
				if ( db_onpage_in_comment( $html, $m[0][1] ) ) {
					$offset = $m[0][1] + strlen( $m[0][0] );
					continue;
				}
				$html = substr_replace( $html, '<h1' . $m[1][0] . '>' . $m[2][0] . '</h1>', $m[0][1], strlen( $m[0][0] ) );
				$done = true;
			}
			if ( ! $done ) {
				// Templates with no <main> (e.g. /paypal-ipn/) get it straight
				// after <body>.
				$pos = stripos( $html, '<main' );
				if ( false === $pos ) {
					$pos = stripos( $html, '<body' );
				}
				$end = false === $pos ? false : strpos( $html, '>', $pos );
				if ( false !== $end ) {
					$h1   = '<h1 class="screen-reader-text">' . esc_html( $ctx['title'] ) . '</h1>';
					$html = substr_replace( $html, $h1, $end + 1, 0 );
				}
			}
		}
		if ( false !== stripos( $html, '<img' ) ) {
			$html = preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) {
				$tag = $m[0];
				if ( preg_match( '/\salt\s*=/i', $tag ) ) {
					return $tag;
				}
				$alt = '';
				if ( preg_match( '/\ssrc="([^"]+)"/i', $tag, $s ) ) {
					$base = pathinfo( wp_parse_url( $s[1], PHP_URL_PATH ) ?: '', PATHINFO_FILENAME );
					$alt  = trim( preg_replace( '/[-_]+/', ' ', preg_replace( '/-?\d+x\d+$/', '', $base ) ) );
				}
				return substr_replace( $tag, ' alt="' . esc_attr( $alt ) . '"', 4, 0 );
			}, $html );
		}
		return $html;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	// Not the front page: its hero <h1> is added by a later output buffer.
	$GLOBALS['db_onpage_ctx'] = array(
		'singular'       => is_singular() && ! is_front_page(),
		'title'          => is_singular() ? get_the_title( get_queried_object_id() ) : '',
		'payment_cancel' => is_page( 'payment-cancel' ),
	);
	ob_start( 'db_onpage_fix_h1_and_alt' );
}, 26 );

/* ─── 5. Imported news content: trackers, affiliate links, pasted ids ─────── */

if ( ! function_exists( 'db_onpage_clean_imported_content' ) ) {
	/**
	 * Most news posts were imported from other blogs' RSS feeds (2017–2023)
	 * and carry that source's markup. A full crawl of staging found:
	 *  - Commission Junction and Amazon affiliate tracking pixels/widgets
	 *    (ftjcfx.com, kqzyfj.com, tkqlhce.com, amazon-adsystem.com, …) and
	 *    a Business Wire tracking pixel: third-party trackers on our pages.
	 *    Removed;
	 *  - links through affiliate redirectors: kept, but marked
	 *    rel="sponsored nofollow" as Google requires for paid links;
	 *  - images over http:// (110+ posts). The CSP already upgrades them,
	 *    so this only makes the markup say what the browser does;
	 *  - pasted ids that clash with the theme (id="main", id="content") or
	 *    repeat inside a post (Yahoo Mail "yui_…" / "m_…" ids). Stripped.
	 */
	function db_onpage_clean_imported_content( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}
		$trackers = '(?:[a-z0-9-]+\.)*(?:ftjcfx\.com|kqzyfj\.com|tkqlhce\.com|jdoqocy\.com|dpbolvw\.net|anrdoezrs\.net|lduhtrp\.net|tqlkg\.com|awltovhc\.com|yceml\.net|emjcd\.com|amazon-adsystem\.com|cts\.businesswire\.com)';
		$content  = preg_replace( '#<(?:img|iframe)\b[^>]*\ssrc=["\'](?:https?:)?//' . $trackers . '[/"\'][^>]*>(?:\s*</iframe>)?#i', '', $content );
		$content  = preg_replace( '#<script\b[^>]*\ssrc=["\'](?:https?:)?//' . $trackers . '[/"\'][^>]*>\s*</script>#i', '', $content );

		$content = preg_replace_callback( '#<a\b[^>]*\shref=["\']https?://(?:[a-z0-9-]+\.)*(?:kqzyfj\.com|tkqlhce\.com|jdoqocy\.com|dpbolvw\.net|anrdoezrs\.net|amzn\.to|amazon\.com/[^"\']*tag=)[^>]*>#i', function ( $m ) {
			$tag = $m[0];
			if ( preg_match( '/\srel=(["\'])([^"\']*)\1/i', $tag, $r ) ) {
				$rel = trim( $r[2] . ( false === stripos( $r[2], 'sponsored' ) ? ' sponsored' : '' ) . ( false === stripos( $r[2], 'nofollow' ) ? ' nofollow' : '' ) );
				return str_replace( $r[0], ' rel=' . $r[1] . $rel . $r[1], $tag );
			}
			return substr_replace( $tag, ' rel="sponsored nofollow"', 2, 0 );
		}, $content );

		// CJ link code fakes the browser status bar (onmouseover="window.status=
		// 'http://www.godaddy.com'") so the affiliate redirect is hidden.
		if ( false !== stripos( $content, 'window.status' ) ) {
			$content = preg_replace( '#\son(?:mouseover|mouseout)\s*=\s*"[^"]*window\.status[^"]*"#i', '', $content );
		}
		$content = preg_replace( '#(<img\b[^>]*\ssrc=["\'])http://#i', '$1https://', $content );

		$content = preg_replace( '#\sid=["\'](?:main|content|primary|secondary|page|masthead|colophon|yui_[^"\']*|m_\d[^"\']*)["\']#i', '', $content );
		// Any other id repeated inside one post (e.g. two id="article-text"
		// from a pasted source page): keep the first, drop the repeats.
		$seen    = array();
		$content = preg_replace_callback( '#\sid=(["\'])([^"\']+)\1#', function ( $m ) use ( &$seen ) {
			if ( isset( $seen[ $m[2] ] ) ) {
				return '';
			}
			$seen[ $m[2] ] = true;
			return $m[0];
		}, $content );
		return $content;
	}
}

add_filter( 'the_content', function ( $content ) {
	if ( is_admin() || is_feed() ) {
		return $content;
	}
	return db_onpage_clean_imported_content( $content );
}, 30 );
