<?php
/**
 * One URL per service: the older service pages redirect (301) to the current
 * ones that the header menu links, so the two no longer compete in search.
 *
 *   /digital-marketing/       -> /digital-marketing-services/
 *   /software-development/    -> /custom-software-development-services/
 *   /mobile-app-development/  -> /mobile-app-development-services/
 *   /other-services/          -> /web-hosting-maintenance-services/
 *
 * A redirect applies only while its target page is published, so nothing
 * points at a missing page. The old pages are also left out of the XML
 * sitemaps (WordPress and Rank Math). The old pages themselves are not
 * deleted; unpublish them in WP Admin once the redirects are confirmed.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_service_redirect_map' ) ) {
	/** Old slug => current slug. */
	function db_service_redirect_map() {
		return (array) apply_filters( 'db_service_redirect_map', array(
			'digital-marketing'      => 'digital-marketing-services',
			'software-development'   => 'custom-software-development-services',
			'mobile-app-development' => 'mobile-app-development-services',
			'other-services'         => 'web-hosting-maintenance-services',
			// Merged 2026-11-25: three pages competed for "get a name someone else owns".
			'buy-domains-service'    => 'acquire-premium-domain',
		) );
	}
}

if ( ! function_exists( 'db_service_redirect_target' ) ) {
	/**
	 * The current slug for a request path, or ''. Pure: tested offline.
	 *
	 * @param string $path      Request path, e.g. "/digital-marketing/".
	 * @param array  $map       Old slug => new slug.
	 * @param string $home_path Path of the site root ("" or "/sub").
	 */
	function db_service_redirect_target( $path, array $map, $home_path = '' ) {
		$path = (string) $path;
		$home = rtrim( (string) $home_path, '/' );
		if ( '' !== $home ) {
			if ( 0 !== strpos( $path, $home . '/' ) ) {
				return '';
			}
			$path = substr( $path, strlen( $home ) );
		}
		$slug = strtolower( trim( $path, '/' ) );
		return isset( $map[ $slug ] ) ? (string) $map[ $slug ] : '';
	}
}

if ( ! function_exists( 'db_service_redirect_live_target' ) ) {
	/** Published target page for an old slug, or null. */
	function db_service_redirect_live_target( $new_slug ) {
		$p = get_page_by_path( $new_slug );
		return ( $p && 'publish' === $p->post_status && '' === (string) $p->post_password ) ? $p : null;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_preview() || ! in_array( $_SERVER['REQUEST_METHOD'] ?? 'GET', array( 'GET', 'HEAD' ), true ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return;
	}
	$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$new  = db_service_redirect_target( rawurldecode( $path ), db_service_redirect_map(), (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
	if ( '' === $new ) {
		return;
	}
	$target = db_service_redirect_live_target( $new );
	if ( ! $target ) {
		return;
	}
	$url   = get_permalink( $target );
	$query = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_QUERY ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( '' !== $query ) {
		$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
	}
	wp_safe_redirect( $url, 301, 'Domain Brothers' );
	exit;
}, 0 );

if ( ! function_exists( 'db_service_redirect_old_ids' ) ) {
	/** IDs of old service pages whose redirect is in force (worked out once per request). */
	function db_service_redirect_old_ids() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		$ids = array();
		foreach ( db_service_redirect_map() as $old => $new ) {
			$p = get_page_by_path( $old );
			if ( $p && db_service_redirect_live_target( $new ) ) {
				$ids[] = (int) $p->ID;
			}
		}
		return $ids;
	}
}

add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $post_type ) {
	if ( 'page' === $post_type ) {
		$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), db_service_redirect_old_ids() );
	}
	return $args;
}, 10, 2 );

add_filter( 'rank_math/sitemap/entry', function ( $url, $type, $post ) {
	if ( 'post' === $type && is_object( $post ) && isset( $post->ID ) && in_array( (int) $post->ID, db_service_redirect_old_ids(), true ) ) {
		return false;
	}
	return $url;
}, 10, 3 );

if ( ! function_exists( 'db_service_redirect_rewrite_links' ) ) {
	/**
	 * Rewrites links to an old address so they point at the current page directly, not through
	 * the 301 (Google: link to the final URL). Pure: tested offline.
	 *
	 * @param string $html Page HTML.
	 * @param array  $map  Old slug => new slug (only live targets).
	 * @param string $home Site root URL without trailing slash, e.g. "https://example.com".
	 * @return string
	 */
	function db_service_redirect_rewrite_links( $html, array $map, $home ) {
		if ( ! is_string( $html ) || ! $map ) {
			return $html;
		}
		$host = preg_quote( (string) preg_replace( '#^https?://#i', '', rtrim( (string) $home, '/' ) ), '#' );
		$alts = implode( '|', array_map( static function ( $s ) {
			return preg_quote( (string) $s, '#' );
		}, array_keys( $map ) ) );
		$re   = '#(\shref\s*=\s*["\'])((?:https?://' . $host . ')?/)(' . $alts . ')/?(?=["\'?\#])#i';
		$out  = preg_replace_callback( $re, static function ( $m ) use ( $map ) {
			$new = $map[ strtolower( $m[3] ) ] ?? '';
			return '' === $new ? $m[0] : $m[1] . $m[2] . $new . '/';
		}, $html );
		return is_string( $out ) ? $out : $html;
	}
}

// Front end: every link to an old service address goes straight to the current page.
add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	$live = array();
	foreach ( db_service_redirect_map() as $old => $new ) {
		if ( db_service_redirect_live_target( $new ) ) {
			$live[ strtolower( (string) $old ) ] = (string) $new;
		}
	}
	if ( ! $live ) {
		return;
	}
	$home = untrailingslashit( home_url() );
	ob_start( static function ( $html ) use ( $live, $home ) {
		return db_service_redirect_rewrite_links( $html, $live, $home );
	} );
}, 21 );
