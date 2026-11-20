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

add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || is_robots() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	ob_start( function ( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<html' ) ) {
			return $html;
		}
		return db_hyg_img_fix( $html, 'db_hyg_img_dims' );
	} );
}, 8 );

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
