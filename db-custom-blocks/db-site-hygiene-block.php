<?php
/**
 * Site hygiene: small, safe fixes found in the 2026-11-20 audit (docs/IMPROVEMENT_PLAN.md).
 *
 *  - Images: every <img> after the first three gets loading="lazy"; every <img> gets
 *    decoding="async"; images from this site's own files get width and height (read once
 *    and cached) so the page does not shift while they load. Images already carrying an
 *    attribute are left alone, as is anything marked data-no-lazy.
 *  - The X-Powered-By header (which names the PHP version) is removed.
 *  - /llms.txt describes the site for AI assistants; /.well-known/security.txt says where
 *    to report a security problem.
 *  - A helpful 404 page (search, popular pages) with a proper title and noindex.
 *  - IndexNow: when a page, post or domain is published or updated, Bing and the other
 *    IndexNow search engines are told. Off on staging.
 *  - Form entries older than 24 months are deleted once a day.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ------------------------------------------------------------------ images */

if ( ! function_exists( 'db_hyg_img_fix' ) ) {
	/**
	 * Adds loading, decoding and size attributes to <img> tags.
	 *
	 * @param string        $html     Page HTML.
	 * @param callable|null $dims     fn(string $src): array{0:int,1:int}|null, for tags without width and height.
	 * @param int           $eager    How many leading images stay eager (logo and hero).
	 * @return string
	 */
	function db_hyg_img_fix( $html, $dims = null, $eager = 3 ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
			return $html;
		}
		$i = 0;
		return preg_replace_callback( '/<img\b[^>]*>/i', function ( $m ) use ( &$i, $dims, $eager ) {
			$tag = $m[0];
			++$i;
			if ( false !== stripos( $tag, 'data-no-lazy' ) ) {
				return $tag;
			}
			$add = '';
			if ( $i > $eager && ! preg_match( '/\sloading\s*=/i', $tag ) ) {
				$add .= ' loading="lazy"';
			}
			if ( ! preg_match( '/\sdecoding\s*=/i', $tag ) ) {
				$add .= ' decoding="async"';
			}
			$has_w = (bool) preg_match( '/\swidth\s*=/i', $tag );
			$has_h = (bool) preg_match( '/\sheight\s*=/i', $tag );
			if ( ! $has_w && ! $has_h && is_callable( $dims ) && preg_match( '/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $tag, $s ) ) {
				$d = call_user_func( $dims, html_entity_decode( $s[1] ) );
				if ( is_array( $d ) && $d[0] > 0 && $d[1] > 0 ) {
					$add .= ' width="' . (int) $d[0] . '" height="' . (int) $d[1] . '"';
				}
			}
			if ( '' === $add ) {
				return $tag;
			}
			return preg_replace( '/\s*\/?>$/', $add . ( '/' === substr( rtrim( $tag, '>' ), -1 ) ? ' /' : '' ) . '>', $tag, 1 );
		}, $html );
	}
}

if ( ! function_exists( 'db_hyg_img_dims' ) ) {
	/** Pixel size of an image served from this site's own wp-content folder; cached for 30 days. */
	function db_hyg_img_dims( $src ) {
		$content_url = content_url();
		$path_part   = wp_parse_url( $src, PHP_URL_PATH );
		$host        = wp_parse_url( $src, PHP_URL_HOST );
		$own         = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $path_part || ( $host && $host !== $own ) || false === strpos( $path_part, '/wp-content/' ) || preg_match( '/\.(svg|gif)$/i', $path_part ) ) {
			return null;
		}
		$rel  = substr( $path_part, strpos( $path_part, '/wp-content/' ) + strlen( '/wp-content/' ) );
		$file = WP_CONTENT_DIR . '/' . $rel;
		if ( false !== strpos( $rel, '..' ) || ! is_readable( $file ) ) {
			return null;
		}
		$key = 'db_hyg_dim_' . md5( $rel . '|' . (int) filemtime( $file ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || $info[0] < 1 || $info[1] < 1 ) {
			return null;
		}
		$d = array( (int) $info[0], (int) $info[1] );
		set_transient( $key, $d, 30 * DAY_IN_SECONDS );
		unset( $content_url );
		return $d;
	}
}

if ( ! function_exists( 'db_hyg_viewport_fix' ) ) {
	/**
	 * Lets visitors pinch-zoom: removes maximum-scale and user-scalable=no from the viewport meta
	 * (axe "meta-viewport", critical). Pages the on-page SEO filter never sees, such as the virtual
	 * tool and register pages, are covered here.
	 */
	function db_hyg_viewport_fix( $html ) {
		if ( ! is_string( $html ) || ( false === stripos( $html, 'user-scalable' ) && false === stripos( $html, 'maximum-scale' ) ) ) {
			return $html;
		}
		return preg_replace_callback( '#<meta\s+name=["\']viewport["\']\s+content=["\']([^"\']*)["\']#i', function ( $m ) {
			$c = preg_replace( '/\s*,?\s*(?:maximum-scale\s*=\s*[\d.]+|user-scalable\s*=\s*(?:no|0))/i', '', $m[1] );
			return str_replace( $m[1], trim( $c, ' ,' ), $m[0] );
		}, $html );
	}
}

if ( ! function_exists( 'db_hyg_single_h1' ) ) {
	/**
	 * Keeps one h1 per page. When the page has a visible h1, any screen-reader-only h1 (the theme
	 * prints one on the news page) becomes a plain paragraph with the same classes.
	 */
	function db_hyg_single_h1( $html ) {
		if ( ! is_string( $html ) || substr_count( strtolower( $html ), '<h1' ) < 2 ) {
			return $html;
		}
		if ( ! preg_match( '/<h1\b(?![^>]*screen-reader-text)[^>]*>/i', $html ) ) {
			return $html;
		}
		return preg_replace_callback( '#<h1\b([^>]*screen-reader-text[^>]*)>(.*?)</h1>#is', static function ( $m ) {
			return '<p' . $m[1] . '>' . $m[2] . '</p>';
		}, $html );
	}
}

if ( ! function_exists( 'db_hyg_heading_levels' ) ) {
	/**
	 * Headings inside <main> that skip a level (an h4 straight after an h2) are given the
	 * level they should have with aria-level, so the outline reads correctly without
	 * changing how the page looks (bug A-5). Headings of the same original level that
	 * follow each other keep the same corrected level.
	 */
	function db_hyg_heading_levels( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		$a = stripos( $html, '<main' );
		$b = false === $a ? false : strripos( $html, '</main>' );
		if ( false === $a || false === $b ) {
			return $html;
		}
		$last_orig = 0;
		$last_eff  = 0;
		$main      = preg_replace_callback( '#<h([1-6])\b([^>]*)>#i', static function ( $m ) use ( &$last_orig, &$last_eff ) {
			$lv = (int) $m[1];
			if ( preg_match( '/\saria-level\s*=/i', $m[2] ) ) {
				return $m[0];
			}
			if ( 0 === $last_eff ) {
				$eff = $lv;
			} elseif ( $lv === $last_orig ) {
				$eff = $last_eff;
			} else {
				$eff = min( $lv, $last_eff + 1 );
			}
			$last_orig = $lv;
			$last_eff  = $eff;
			return $eff === $lv ? $m[0] : '<h' . $lv . ' aria-level="' . $eff . '"' . $m[2] . '>';
		}, substr( $html, $a, $b - $a ) );
		return substr( $html, 0, $a ) . $main . substr( $html, $b );
	}
}

if ( ! function_exists( 'db_hyg_iframe_titles' ) ) {
	/**
	 * Every iframe gets a title for screen readers (bug A-6). Third-party snippets such as
	 * Google Tag Manager's noscript frame print none.
	 */
	function db_hyg_iframe_titles( $html ) {
		if ( false === stripos( (string) $html, '<iframe' ) ) {
			return $html;
		}
		return preg_replace_callback( '#<iframe\b([^>]*)>#i', static function ( $m ) {
			if ( preg_match( '/\stitle\s*=/i', $m[1] ) ) {
				return $m[0];
			}
			$t = false !== stripos( $m[1], 'googletagmanager.com' ) ? 'Google Tag Manager' : 'Embedded content';
			return '<iframe title="' . $t . '"' . $m[1] . '>';
		}, $html );
	}
}

if ( ! function_exists( 'db_hyg_dedupe_tags' ) ) {
	/**
	 * Google tags print once (bug O-1). The theme header hard-codes the GTM container that
	 * Site Kit also prints, and Rank Math's "install code" configures the GA4 property Site
	 * Kit already configures, so every page view counted twice. The first copy of each
	 * (GTM container, gtag.js loader, gtag config per id, GTM noscript frame) is kept and
	 * later copies are removed. A script that also carries anything new is kept.
	 */
	function db_hyg_dedupe_tags( $html ) {
		$html = (string) $html;
		if ( false === stripos( $html, 'googletagmanager.com' ) && false === stripos( $html, 'gtag(' ) ) {
			return $html;
		}
		$seen = array();
		$keep = static function ( array $keys ) use ( &$seen ) {
			$new = false;
			foreach ( $keys as $k ) {
				if ( empty( $seen[ $k ] ) ) {
					$new = true;
				}
			}
			foreach ( $keys as $k ) {
				$seen[ $k ] = true;
			}
			return $new;
		};
		$out = preg_replace_callback( '#<script\b[^>]*>.*?</script>[ \t]*\n?#is', static function ( $m ) use ( $keep ) {
			$tag = $m[0];
			if ( false === stripos( $tag, 'googletagmanager.com' ) && false === stripos( $tag, 'gtag(' ) ) {
				return $tag;
			}
			$keys = array();
			if ( preg_match( '#googletagmanager\.com/gtag/js\?id=([A-Za-z0-9-]+)#', $tag, $k ) ) {
				$keys[] = 'gtagjs:' . strtoupper( $k[1] );
			}
			if ( preg_match_all( '#gtag\(\s*[\'"]config[\'"]\s*,\s*[\'"]([A-Za-z0-9-]+)[\'"]#', $tag, $k ) ) {
				foreach ( $k[1] as $id ) {
					$keys[] = 'config:' . strtoupper( $id );
				}
			}
			if ( false !== stripos( $tag, 'gtm.js' ) && preg_match( '#[\'"=](GTM-[A-Za-z0-9]+)\b#', $tag, $k ) ) {
				$keys[] = 'gtm:' . strtoupper( $k[1] );
			}
			if ( ! $keys ) {
				return $tag;
			}
			return $keep( $keys ) ? $tag : '';
		}, $html );
		if ( null === $out ) {
			return $html; // PCRE limit hit: leave the page as it was.
		}
		$out2 = preg_replace_callback( '#<noscript>\s*<iframe\b[^>]*googletagmanager\.com/ns\.html\?id=(GTM-[A-Za-z0-9]+)[^>]*>\s*</iframe>\s*</noscript>#i', static function ( $m ) use ( $keep ) {
			return $keep( array( 'gtmns:' . strtoupper( $m[1] ) ) ) ? $m[0] : '';
		}, $out );
		return null === $out2 ? $out : $out2;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_robots() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	ob_start( function ( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<html' ) ) {
			return $html;
		}
		return db_hyg_img_fix( db_hyg_iframe_titles( db_hyg_dedupe_tags( db_hyg_heading_levels( db_hyg_single_h1( db_hyg_viewport_fix( $html ) ) ) ) ), 'db_hyg_img_dims' );
	} );
}, 3 ); // Early on purpose: virtual pages that render and exit before priority 8 would skip it.

/* ----------------------------------------------------------------- headers */

add_action( 'send_headers', function () {
	if ( ! headers_sent() ) {
		header_remove( 'X-Powered-By' );
	}
}, 99 );

/* ------------------------------------------------------ llms.txt, security.txt */

if ( ! function_exists( 'db_hyg_llms_txt' ) ) {
	/**
	 * Plain-text site summary for AI assistants (llmstxt.org format).
	 *
	 * @param string $home Home URL with a trailing slash.
	 * @return string
	 */
	function db_hyg_llms_txt( $home ) {
		$home  = rtrim( $home, '/' ) . '/';
		$pages = array(
			'Domains' => array(
				array( 'All domains', 'all-domains/', 'Every name for sale, with prices and payment plans' ),
				array( 'Sold domains', 'sold/', 'Names that found a new owner' ),
				array( 'Domain finder', 'find-a-domain/', 'Describe a business and get matching names' ),
				array( 'Domain collections', 'collections/', 'Names grouped by industry and use' ),
			),
			'Buying and paying' => array(
				array( 'Payment terms', 'payment-terms/', 'Card payments, payment plans over 3 to 12 months, ownership and transfer' ),
				array( 'Refund policy', 'refund-policy/', 'All sales are final' ),
				array( 'FAQs', 'faqs/', 'Buying, selling, plans and transfers' ),
			),
			'Services' => array(
				array( 'Domain brokerage', 'domain-brokerage-services/', 'We approach the owner of a name and negotiate for you' ),
				array( 'Domain management', 'domain-management-services/', 'Renewals, DNS and security' ),
				array( 'Domain transfer', 'domain-transfer/', 'Moving a name between registrars' ),
			),
			'Free tools' => array(
				array( 'Domain tools', 'domain-tools/', 'Registry record, age, expiry, DNS, SSL, headers, email security and more' ),
				array( 'Domain glossary', 'domain-glossary/', 'Plain-language definitions of domain terms' ),
				array( 'Domain price index', 'domain-price-index/', 'Median asking prices by length and extension' ),
			),
		);
		$out  = "# Domain Brothers\n\n";
		$out .= "> Domain Brothers is a premium domain name marketplace and brokerage. Buyers can pay in full or over 3 to 12 months. All sales are final. Contact: sales@domainbrothers.com\n\n";
		foreach ( $pages as $section => $links ) {
			$out .= '## ' . $section . "\n";
			foreach ( $links as $l ) {
				$out .= '- [' . $l[0] . '](' . $home . $l[1] . '): ' . $l[2] . "\n";
			}
			$out .= "\n";
		}
		return $out;
	}
}

if ( ! function_exists( 'db_hyg_security_txt' ) ) {
	/**
	 * RFC 9116 security contact file.
	 *
	 * @param string $home Home URL.
	 * @param int    $now  Current Unix time.
	 * @return string
	 */
	function db_hyg_security_txt( $home, $now ) {
		$home = rtrim( $home, '/' );
		return "Contact: mailto:sales@domainbrothers.com\n"
			. 'Expires: ' . gmdate( 'Y-m-d\TH:i:s\Z', $now + 365 * 86400 ) . "\n"
			. "Preferred-Languages: en\n"
			. 'Canonical: ' . $home . "/.well-known/security.txt\n";
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) ) {
		return;
	}
	$path = db_sales_request_path();
	if ( 'llms.txt' !== $path && '.well-known/security.txt' !== $path && 'security.txt' !== $path ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	header( 'Cache-Control: public, max-age=86400' );
	echo 'llms.txt' === $path ? db_hyg_llms_txt( home_url( '/' ) ) : db_hyg_security_txt( home_url( '/' ), time() ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}, 2 );

/* ----------------------------------------------- favicon.ico, apple-touch-icon */

if ( ! function_exists( 'db_hyg_icon_source' ) ) {
	/** Local path of the site icon (WordPress site icon, else the theme's uploaded favicon), or ''. */
	function db_hyg_icon_source() {
		$id = (int) get_option( 'site_icon' );
		if ( $id ) {
			$f = get_attached_file( $id );
			if ( $f && is_readable( $f ) ) {
				return $f;
			}
		}
		$up = wp_upload_dir();
		$f  = trailingslashit( $up['basedir'] ) . '2017/03/favicon.png';
		return is_readable( $f ) ? $f : '';
	}
}

if ( ! function_exists( 'db_hyg_png_to_ico' ) ) {
	/** An .ico file holding one PNG image (valid in every current browser). */
	function db_hyg_png_to_ico( $png ) {
		$size = @getimagesizefromstring( $png ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $size || IMAGETYPE_PNG !== $size[2] ) {
			return '';
		}
		$w = $size[0] >= 256 ? 0 : (int) $size[0];
		$h = $size[1] >= 256 ? 0 : (int) $size[1];
		return pack( 'vvv', 0, 1, 1 ) . pack( 'CCCCvvVV', $w, $h, 0, 0, 1, 32, strlen( $png ), 22 ) . $png;
	}
}

// Browsers and crawlers ask for /favicon.ico and /apple-touch-icon.png directly. The web server
// answers those paths itself (WordPress never sees them), so real files are written once.
add_action( 'admin_init', function () {
	if ( ! current_user_can( 'manage_options' ) || get_option( 'db_hyg_icons_done' ) === DB_BLOCKS_LOADED ) {
		return;
	}
	update_option( 'db_hyg_icons_done', DB_BLOCKS_LOADED, false );
	$src = db_hyg_icon_source();
	if ( '' === $src || ! wp_is_writable( ABSPATH ) ) {
		return;
	}
	$png = (string) file_get_contents( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( ! file_exists( ABSPATH . 'apple-touch-icon.png' ) && '' !== $png ) {
		file_put_contents( ABSPATH . 'apple-touch-icon.png', $png ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	$ico = db_hyg_png_to_ico( $png );
	if ( ! file_exists( ABSPATH . 'favicon.ico' ) && '' !== $ico ) {
		file_put_contents( ABSPATH . 'favicon.ico', $ico ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
} );

/* ---------------------------------------------------------------- 404 page */

if ( ! function_exists( 'db_hyg_404_links' ) ) {
	/** @return array<int,array{0:string,1:string,2:string}> label, path, note */
	function db_hyg_404_links() {
		return array(
			array( 'All domains', '/all-domains/', 'Browse every name for sale' ),
			array( 'Domain finder', '/find-a-domain/', 'Describe your business, get matching names' ),
			array( 'Sold domains', '/sold/', 'See what has already found an owner' ),
			array( 'Domain tools', '/domain-tools/', 'Free lookups for any domain' ),
			array( 'FAQs', '/faqs/', 'Answers about buying and paying' ),
			array( 'Contact us', '/contact-us/', 'A person replies within one business day' ),
		);
	}
}

if ( ! function_exists( 'db_hyg_404_body' ) ) {
	/**
	 * @param string $home   Home URL.
	 * @param string $search Search form HTML.
	 * @return string
	 */
	function db_hyg_404_body( $home, $search ) {
		$h  = '<div class="db-404" style="max-width:860px;margin:0 auto;padding:48px 20px 72px;color:#374151">';
		$h .= '<p style="margin:0 0 8px;font-weight:700;color:#4f6f0f;letter-spacing:.06em;text-transform:uppercase;font-size:13px">Error 404</p>';
		$h .= '<h1 style="margin:0 0 12px;font-size:clamp(30px,5vw,44px);line-height:1.1;color:#0f172a">We could not find that page</h1>';
		$h .= '<p style="margin:0 0 24px;font-size:18px;line-height:1.6">The address may have changed or the name may have sold. Search for a domain, or pick a place to start.</p>';
		$h .= '<div style="margin:0 0 32px">' . $search . '</div><ul style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;margin:0;padding:0;list-style:none">';
		foreach ( db_hyg_404_links() as $l ) {
			$h .= '<li><a href="' . esc_url( rtrim( $home, '/' ) . $l[1] ) . '" style="display:block;height:100%;padding:16px;border:1px solid #e4e7ec;border-radius:12px;background:#fff;color:#0f172a;text-decoration:none"><strong style="display:block;margin:0 0 4px">' . esc_html( $l[0] ) . '</strong><span style="font-size:14px;color:#5a6475">' . esc_html( $l[2] ) . '</span></a></li>';
		}
		return $h . '</ul></div>';
	}
}

add_filter( 'pre_get_document_title', static function ( $t ) {
	return ( ! is_admin() && is_404() ) ? 'Page not found | Domain Brothers' : $t;
}, 50 );
add_filter( 'rank_math/frontend/title', static function ( $t ) {
	return ( ! is_admin() && is_404() ) ? 'Page not found | Domain Brothers' : $t;
}, 50 );

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_404() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	status_header( 404 );
	nocache_headers();
	header( 'X-Robots-Tag: noindex, follow' );
	get_header();
	echo '<div id="primary" class="content-area"><main id="main" class="site-main">' . db_hyg_404_body( home_url( '/' ), get_search_form( array( 'echo' => false ) ) ) . '</main></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
	get_footer();
	exit;
}, 99 );

/* ---------------------------------------------------------------- IndexNow */

if ( ! function_exists( 'db_hyg_indexnow_key' ) ) {
	/** A stable random key, created on first use. */
	function db_hyg_indexnow_key() {
		$key = (string) get_option( 'db_indexnow_key', '' );
		if ( ! preg_match( '/^[a-f0-9]{32}$/', $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			update_option( 'db_indexnow_key', $key, false );
		}
		return $key;
	}
}

if ( ! function_exists( 'db_hyg_indexnow_payload' ) ) {
	/**
	 * Request body for api.indexnow.org.
	 *
	 * @param string   $host Site host.
	 * @param string   $key  IndexNow key.
	 * @param string[] $urls Absolute URLs on that host.
	 * @return array<string,mixed>
	 */
	function db_hyg_indexnow_payload( $host, $key, array $urls ) {
		$urls = array_values( array_filter( array_unique( $urls ), static function ( $u ) use ( $host ) {
			return is_string( $u ) && 0 === strpos( $u, 'https://' . $host . '/' );
		} ) );
		return array( 'host' => $host, 'key' => $key, 'keyLocation' => 'https://' . $host . '/' . $key . '.txt', 'urlList' => array_slice( $urls, 0, 100 ) );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) ) {
		return;
	}
	$path = db_sales_request_path();
	if ( ! preg_match( '/^([a-f0-9]{32})\.txt$/', $path, $m ) || ! hash_equals( db_hyg_indexnow_key(), $m[1] ) ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo $m[1]; // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
}, 2 );

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'publish' !== $new || ! $post instanceof WP_Post || ! in_array( $post->post_type, array( 'page', 'post', 'domain' ), true ) ) {
		return;
	}
	if ( ( function_exists( 'db_is_staging_host' ) && db_is_staging_host() ) || '0' === (string) get_option( 'blog_public', '1' ) ) {
		return;
	}
	$url = get_permalink( $post );
	$tk  = 'db_indexnow_' . md5( (string) $url );
	if ( ! $url || get_transient( $tk ) ) {
		return; // pinged in the last ten minutes.
	}
	set_transient( $tk, 1, 10 * MINUTE_IN_SECONDS );
	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	wp_remote_post( 'https://api.indexnow.org/indexnow', array(
		'timeout'  => 3,
		'blocking' => false,
		'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
		'body'     => wp_json_encode( db_hyg_indexnow_payload( $host, db_hyg_indexnow_key(), array( $url ) ) ),
	) );
}, 10, 3 );

/* ------------------------------------------------- form entry retention */

if ( ! function_exists( 'db_hyg_retention_cutoff' ) ) {
	/** Date before which form entries are deleted (24 months before $now). */
	function db_hyg_retention_cutoff( $now ) {
		return gmdate( 'Y-m-d H:i:s', $now - 730 * DAY_IN_SECONDS );
	}
}

add_action( 'db_hyg_purge_entries', function () {
	global $wpdb;
	if ( ! function_exists( 'db_form_entries_table' ) ) {
		return;
	}
	$t = db_form_entries_table();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s", db_hyg_retention_cutoff( time() ) ) );
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'db_hyg_purge_entries' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'db_hyg_purge_entries' );
	}
} );

/* -------------------------------------------- tap targets and small text */

// Phone tap targets of at least 32px and no visible text under 12px (bugs A-3, A-4).
// Inline links inside sentences are left alone (WCAG 2.5.8 exempts them).
add_action( 'wp_head', function () {
	$x = 'html body ';
	echo '<style id="db-tap-css">'
		. $x . '#db-legal-links a{display:inline-flex;align-items:center;min-height:36px;padding:0 4px}'
		. $x . '.db-breadcrumbs a{display:inline-flex;align-items:center;justify-content:center;min-width:32px;min-height:32px}'
		. $x . 'a.db-domain-card__name,' . $x . '.db-domain-card__name a{display:inline-flex;align-items:center;min-height:32px}'
		. $x . '.db-tools-sim a,' . $x . 'a.db-dev-link,' . $x . '.dbr-fd-reset,' . $x . 'a.db-ty-chip,' . $x . '.db-ty-chip{display:inline-flex;align-items:center;min-height:36px}'
		. $x . '.db-own-card a{display:inline-flex;align-items:center;min-height:36px}'
		. $x . '.site-footer li>a,' . $x . 'footer li>a{display:inline-block;min-width:32px}'
		. $x . '.db-pay-opt small,' . $x . '.db-ty-also-label,' . $x . '.db-pr-price small,' . $x . '.db-pr-btn small,' . $x . '.db-hd-sp-chips small{font-size:12.5px}'
		. '</style>' . "\n";
}, 40 );

/* ---------------------------------------- content security policy (report only) */

/*
 * Bug S-1: the only policy was upgrade-insecure-requests. This sends a full policy in
 * report-only mode: browsers block nothing, they report what the policy would block to
 * /wp-json/db/v1/csp-report. Tools > CSP reports lists those (deduplicated). When the list
 * shows only noise, switch to enforcing by setting option db_csp_enforce to 1.
 */
if ( ! function_exists( 'db_csp_policy' ) ) {
	function db_csp_policy() {
		$g = 'https://*.google-analytics.com https://*.analytics.google.com https://*.googletagmanager.com https://www.googletagmanager.com';
		$p = array(
			"default-src 'self'",
			"script-src 'self' 'unsafe-inline' 'unsafe-eval' {$g} https://*.googlesyndication.com https://*.doubleclick.net https://js.stripe.com https://challenges.cloudflare.com https://*.hs-scripts.com https://*.hs-analytics.net https://*.hs-banner.com https://*.hsforms.net https://*.hubspot.com https://static.addtoany.com https://connect.facebook.net https://*.clarity.ms https://static.cloudflareinsights.com https://fundingchoicesmessages.google.com https://*.adtrafficquality.google",
			"style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
			"font-src 'self' data: https://fonts.gstatic.com",
			"img-src 'self' data: blob: https:",
			"connect-src 'self' {$g} https://api.stripe.com https://*.stripe.com https://challenges.cloudflare.com https://*.hubspot.com https://*.hubapi.com https://*.hs-banner.com https://*.clarity.ms https://*.googlesyndication.com https://*.doubleclick.net https://www.facebook.com https://www.google.com https://fundingchoicesmessages.google.com https://*.adtrafficquality.google https://cloudflareinsights.com",
			"frame-src 'self' https://js.stripe.com https://hooks.stripe.com https://*.stripe.com https://challenges.cloudflare.com https://www.facebook.com https://www.googletagmanager.com https://*.googlesyndication.com https://*.doubleclick.net https://www.youtube.com https://www.youtube-nocookie.com https://www.google.com https://static.addtoany.com https://*.adtrafficquality.google https://fundingchoicesmessages.google.com",
			"frame-ancestors 'self'",
			"base-uri 'self'",
			"form-action 'self' https://checkout.stripe.com",
			"object-src 'none'",
			'report-uri ' . esc_url_raw( rest_url( 'db/v1/csp-report' ) ),
		);
		return implode( '; ', (array) apply_filters( 'db_csp_directives', $p ) );
	}
}

add_action( 'send_headers', function () {
	if ( is_admin() || headers_sent() ) {
		return;
	}
	$enforce = '1' === (string) get_option( 'db_csp_enforce', '0' );
	header( ( $enforce ? 'Content-Security-Policy: ' : 'Content-Security-Policy-Report-Only: ' ) . db_csp_policy() );
}, 100 );

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/csp-report', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$ip = function_exists( 'db_wl_ip' ) ? db_wl_ip() : '';
			if ( function_exists( 'db_wl_allow' ) && ! db_wl_allow( 'csp|' . $ip, 60, 10 * MINUTE_IN_SECONDS ) ) {
				return new WP_REST_Response( null, 204 );
			}
			$body = json_decode( (string) $req->get_body(), true );
			$r    = is_array( $body ) ? ( $body['csp-report'] ?? ( $body[0]['body'] ?? $body ) ) : array();
			if ( ! is_array( $r ) ) {
				return new WP_REST_Response( null, 204 );
			}
			$dir     = sanitize_text_field( (string) ( $r['effective-directive'] ?? $r['effectiveDirective'] ?? $r['violated-directive'] ?? '' ) );
			$blocked = (string) ( $r['blocked-uri'] ?? $r['blockedURL'] ?? '' );
			$host    = wp_parse_url( $blocked, PHP_URL_HOST );
			$what    = $host ? $host : substr( sanitize_text_field( $blocked ), 0, 40 );
			$page    = esc_url_raw( (string) ( $r['document-uri'] ?? $r['documentURL'] ?? '' ) );
			if ( '' === $dir || '' === $what ) {
				return new WP_REST_Response( null, 204 );
			}
			$all = get_option( 'db_csp_reports', array() );
			$all = is_array( $all ) ? $all : array();
			$key = substr( $dir . ' ' . $what, 0, 120 );
			if ( ! isset( $all[ $key ] ) && count( $all ) >= 200 ) {
				return new WP_REST_Response( null, 204 );
			}
			$n           = (int) ( $all[ $key ]['n'] ?? 0 ) + 1;
			$all[ $key ] = array( 'n' => $n, 'last' => time(), 'page' => substr( $page, 0, 200 ) );
			update_option( 'db_csp_reports', $all, false );
			return new WP_REST_Response( null, 204 );
		},
	) );
} );

add_action( 'admin_menu', function () {
	add_management_page( 'CSP reports', 'CSP reports', 'manage_options', 'db-csp-reports', function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['db_csp_clear'] ) && check_admin_referer( 'db_csp_clear' ) ) {
			delete_option( 'db_csp_reports' );
		}
		$all = get_option( 'db_csp_reports', array() );
		$all = is_array( $all ) ? $all : array();
		uasort( $all, static function ( $a, $b ) { return $b['n'] <=> $a['n']; } );
		echo '<div class="wrap"><h1>Content security policy reports</h1><p>The policy runs in <strong>' . ( '1' === (string) get_option( 'db_csp_enforce', '0' ) ? 'enforcing' : 'report-only' ) . '</strong> mode. Each row is something the policy would block. Add genuine services to <code>db_csp_policy()</code>; once only noise remains, set the option <code>db_csp_enforce</code> to 1.</p>';
		if ( ! $all ) {
			echo '<p>No reports yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Directive and source</th><th>Count</th><th>Last seen</th><th>Example page</th></tr></thead><tbody>';
			foreach ( $all as $k => $v ) {
				echo '<tr><td><code>' . esc_html( $k ) . '</code></td><td>' . (int) $v['n'] . '</td><td>' . esc_html( wp_date( 'M j, Y g:i a', (int) $v['last'] ) ) . '</td><td>' . esc_html( $v['page'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" style="margin-top:14px">';
		wp_nonce_field( 'db_csp_clear' );
		echo '<input type="hidden" name="db_csp_clear" value="1">';
		submit_button( 'Clear the list', 'secondary', 'submit', false );
		echo '</form></div>';
	} );
} );
