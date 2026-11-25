<?php
/**
 * Structured data clean-up on top of Rank Math's JSON-LD graph.
 *
 * Rank Math owns the <head> (option db_rankmath_owns_head), so the plugin's own Organization,
 * WebPage and BreadcrumbList are suppressed (db_aeo_emit). What Rank Math prints still had gaps
 * found by the bug register (batch 2) and the SEO engineering pass:
 *
 * - SE-17: the Organization had no logo and no contact point.
 * - SE-18: every article's author was a Person called "Domain Brothers" (the brand), with two
 *   different gravatars on one @id.
 * - SE-3: on routes the plugin builds (tools, glossary, seller and offer pages) the WebPage had
 *   an empty url, the @ids were relative ("#webpage") and the BreadcrumbList held only "Home".
 * - Breadcrumb positions were strings, and on listings the middle step pointed at the broken
 *   /domains/ archive instead of the visible trail.
 * - Listing pages had no Organization node, so the Product's seller could not be linked.
 * - SE-4: the theme's team template prints a second, invalid Person ("@type":"person", a
 *   personal email and birth date, production URLs). It is removed from the output; the theme
 *   is not edited.
 *
 * db_schema_fix_graph() is pure and tested offline in tests/seo/schema-test.php.
 *
 * @package db-custom-blocks
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_schema_abs_id' ) ) {
	/** Pure: "#webpage" becomes "<url>#webpage"; absolute ids are returned unchanged. */
	function db_schema_abs_id( $id, $url ) {
		$id = (string) $id;
		return ( '' !== $id && '#' === $id[0] ) ? rtrim( (string) $url, '#' ) . $id : $id;
	}
}

if ( ! function_exists( 'db_schema_types' ) ) {
	/** Pure: a node's @type as a list. */
	function db_schema_types( $node ) {
		return is_array( $node ) && isset( $node['@type'] ) ? (array) $node['@type'] : array();
	}
}

if ( ! function_exists( 'db_schema_fix_graph' ) ) {
	/**
	 * Pure: repairs Rank Math's json_ld array (node key => node).
	 *
	 * @param array $data Rank Math's nodes.
	 * @param array $ctx  home, url (this page), name (brand), logo, email, about (author page),
	 *                    trail (list of [label, href]; '' href = this page), is_listing (bool).
	 * @return array
	 */
	function db_schema_fix_graph( array $data, array $ctx ) {
		$home   = trailingslashit( (string) $ctx['home'] );
		$url    = (string) $ctx['url'];
		$name   = (string) $ctx['name'];
		$org_id = $home . '#organization';

		// Relative @ids and empty WebPage urls (routes that are not WordPress objects).
		$fix_ids = static function ( $v ) use ( &$fix_ids, $url ) {
			if ( ! is_array( $v ) ) {
				return $v;
			}
			foreach ( $v as $k => $x ) {
				$v[ $k ] = ( '@id' === $k && is_string( $x ) ) ? db_schema_abs_id( $x, $url ) : $fix_ids( $x );
			}
			return $v;
		};
		$data = $fix_ids( $data );

		$has_org      = false;
		$brand_person = array();
		foreach ( $data as $key => $node ) {
			$types = db_schema_types( $node );
			if ( in_array( 'Organization', $types, true ) && isset( $node['@id'] ) && $org_id === $node['@id'] ) {
				$has_org = true;
				if ( empty( $node['logo'] ) && ! empty( $ctx['logo'] ) ) {
					$node['logo'] = array( '@type' => 'ImageObject', '@id' => $home . '#logo', 'url' => (string) $ctx['logo'], 'caption' => $name );
				}
				if ( empty( $node['contactPoint'] ) && ! empty( $ctx['email'] ) ) {
					$node['contactPoint'] = array( array( '@type' => 'ContactPoint', 'contactType' => 'sales', 'email' => (string) $ctx['email'], 'availableLanguage' => 'English' ) );
				}
				if ( empty( $node['email'] ) && ! empty( $ctx['email'] ) ) {
					$node['email'] = (string) $ctx['email'];
				}
				$data[ $key ] = $node;
			}
			if ( in_array( 'WebPage', $types, true ) || in_array( 'CollectionPage', $types, true ) ) {
				if ( empty( $node['url'] ) ) {
					$data[ $key ]['url'] = $url;
				}
			}
			// A Person that is really the brand (SE-18).
			if ( array( 'Person' ) === $types && isset( $node['name'] ) && 0 === strcasecmp( trim( (string) $node['name'] ), $name ) ) {
				if ( isset( $node['@id'] ) ) {
					$brand_person[ $node['@id'] ] = true;
				}
				unset( $data[ $key ] );
			}
		}

		if ( ! $has_org && ! empty( $ctx['is_listing'] ) ) {
			$org = array( '@type' => 'Organization', '@id' => $org_id, 'name' => $name, 'url' => $home );
			if ( ! empty( $ctx['logo'] ) ) {
				$org['logo'] = array( '@type' => 'ImageObject', '@id' => $home . '#logo', 'url' => (string) $ctx['logo'], 'caption' => $name );
			}
			if ( ! empty( $ctx['email'] ) ) {
				$org['contactPoint'] = array( array( '@type' => 'ContactPoint', 'contactType' => 'sales', 'email' => (string) $ctx['email'], 'availableLanguage' => 'English' ) );
			}
			$data = array( 'publisher' => $org ) + $data;
		}

		// Authors that pointed at the brand Person now point at the Organization.
		$org_ref = array( '@type' => 'Organization', '@id' => $org_id, 'name' => $name, 'url' => ! empty( $ctx['about'] ) ? (string) $ctx['about'] : $home );
		foreach ( $data as $key => $node ) {
			if ( ! is_array( $node ) || ! isset( $node['author'] ) || ! is_array( $node['author'] ) ) {
				continue;
			}
			$a  = $node['author'];
			$id = isset( $a['@id'] ) ? (string) $a['@id'] : '';
			if ( isset( $brand_person[ $id ] ) || ( isset( $a['name'] ) && 0 === strcasecmp( trim( (string) $a['name'] ), $name ) ) ) {
				$data[ $key ]['author'] = $org_ref;
			}
		}

		// Breadcrumbs: the same trail visitors see, positions as numbers. A Rank Math trail with
		// only "Home" is dropped (the plugin prints the right one on its own routes).
		$trail = isset( $ctx['trail'] ) && is_array( $ctx['trail'] ) ? $ctx['trail'] : array();
		foreach ( $data as $key => $node ) {
			if ( ! in_array( 'BreadcrumbList', db_schema_types( $node ), true ) ) {
				continue;
			}
			if ( count( $trail ) >= 2 ) {
				$items = array();
				foreach ( array_values( $trail ) as $i => $crumb ) {
					$href    = '' !== (string) $crumb[1] ? (string) $crumb[1] : $url;
					$items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'item' => array( '@id' => $href, 'name' => (string) $crumb[0] ) );
				}
				$data[ $key ]['itemListElement'] = $items;
				continue;
			}
			$items = isset( $node['itemListElement'] ) && is_array( $node['itemListElement'] ) ? array_values( $node['itemListElement'] ) : array();
			if ( count( $items ) < 2 ) {
				$bid = isset( $node['@id'] ) ? $node['@id'] : '';
				unset( $data[ $key ] );
				foreach ( $data as $k2 => $n2 ) {
					if ( is_array( $n2 ) && isset( $n2['breadcrumb']['@id'] ) && $n2['breadcrumb']['@id'] === $bid ) {
						unset( $data[ $k2 ]['breadcrumb'] );
					}
				}
				continue;
			}
			foreach ( $items as $i => $it ) {
				$items[ $i ]['position'] = $i + 1;
			}
			$data[ $key ]['itemListElement'] = $items;
		}
		return $data;
	}
}

if ( ! function_exists( 'db_schema_strip_theme_person' ) ) {
	/** Pure: removes JSON-LD blocks whose @type is the invalid lowercase "person" (SE-4). */
	function db_schema_strip_theme_person( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, '"person"' ) ) {
			return $html;
		}
		return (string) preg_replace_callback( '#<script\b[^>]*application/ld\+json[^>]*>(.*?)</script>\s*#is', static function ( $m ) {
			return preg_match( '#"@type"\s*:\s*"person"#', $m[1] ) ? '' : $m[0];
		}, $html );
	}
}

if ( ! function_exists( 'db_schema_context' ) ) {
	/** The live values for db_schema_fix_graph(). */
	function db_schema_context() {
		$trail = array();
		if ( function_exists( 'db_breadcrumbs_trail' ) ) {
			foreach ( (array) db_breadcrumbs_trail() as $c ) {
				if ( is_array( $c ) && isset( $c['label'] ) ) {
					$href    = isset( $c['href'] ) && ! is_wp_error( $c['href'] ) ? (string) $c['href'] : '';
					$trail[] = array( wp_strip_all_tags( (string) $c['label'] ), $href );
				}
			}
		}
		$logo = function_exists( 'db_aeo_og_image' ) ? db_aeo_og_image() : '';
		$att  = (int) get_option( 'db_schema_logo_attachment' );
		if ( $att && wp_get_attachment_url( $att ) ) {
			$logo = wp_get_attachment_url( $att );
		}
		return array(
			'home'       => home_url( '/' ),
			'url'        => function_exists( 'db_crawl_canonical_url' ) ? db_crawl_canonical_url( home_url( add_query_arg( array() ) ) ) : home_url( add_query_arg( array() ) ),
			'name'       => defined( 'DB_AEO_ORG_NAME' ) ? DB_AEO_ORG_NAME : get_bloginfo( 'name' ),
			'logo'       => $logo,
			'email'      => defined( 'DB_AEO_CONTACT_EMAIL' ) ? DB_AEO_CONTACT_EMAIL : '',
			'about'      => home_url( '/about-us/' ),
			'trail'      => $trail,
			'is_listing' => is_singular( 'domain' ),
		);
	}
}

add_filter( 'rank_math/json_ld', function ( $data ) {
	if ( ! is_array( $data ) || is_admin() ) {
		return $data;
	}
	return db_schema_fix_graph( $data, db_schema_context() );
}, 999 );

// Product on listings: the seller is the site's Organization node, by @id.
add_filter( 'db_aeo_product', function ( $product ) {
	if ( is_array( $product ) && isset( $product['offers']['seller'] ) ) {
		$product['offers']['seller'] = array( '@type' => 'Organization', '@id' => home_url( '/#organization' ), 'name' => defined( 'DB_AEO_ORG_NAME' ) ? DB_AEO_ORG_NAME : get_bloginfo( 'name' ) );
	}
	return $product;
} );

add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() || ! is_page() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	ob_start( 'db_schema_strip_theme_person' );
}, 97 );

if ( ! function_exists( 'db_schema_missing_theme_image' ) ) {
	/** True for a URL inside the active theme whose file does not exist (a 404 when shared). */
	function db_schema_missing_theme_image( $url ) {
		$base = trailingslashit( get_template_directory_uri() );
		$url  = (string) $url;
		if ( 0 !== strpos( preg_replace( '#^https?:#', '', $url ), preg_replace( '#^https?:#', '', $base ) ) ) {
			return false;
		}
		$rel = substr( preg_replace( '#^https?:#', '', strtok( $url, '?' ) ), strlen( preg_replace( '#^https?:#', '', $base ) ) );
		return '' !== $rel && ! file_exists( trailingslashit( get_template_directory() ) . $rel );
	}
}

if ( ! function_exists( 'db_schema_default_image' ) ) {
	/** The site's default share image. */
	function db_schema_default_image() {
		$att = (int) get_option( 'db_og_default_attachment' );
		$url = $att ? (string) wp_get_attachment_url( $att ) : '';
		return '' !== $url ? $url : ( function_exists( 'db_aeo_og_image' ) ? db_aeo_og_image() : '' );
	}
}

// og:image, twitter:image and the schema's ImageObject on /acquire-social-media-handles/ pointed to
// a theme image that no longer exists (HTTP 404). Missing theme images fall back to the default
// share image (bug register batch 2, SE-9).
foreach ( array( 'rank_math/opengraph/facebook/image', 'rank_math/opengraph/twitter/image' ) as $db_hook ) {
	add_filter( $db_hook, static function ( $url ) {
		return db_schema_missing_theme_image( $url ) && '' !== db_schema_default_image() ? db_schema_default_image() : $url;
	} );
}
unset( $db_hook );
add_filter( 'rank_math/json_ld', static function ( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	$fix = static function ( $v ) use ( &$fix ) {
		if ( ! is_array( $v ) ) {
			return ( is_string( $v ) && db_schema_missing_theme_image( $v ) && '' !== db_schema_default_image() ) ? db_schema_default_image() : $v;
		}
		foreach ( $v as $k => $x ) {
			if ( in_array( $k, array( 'url', 'contentUrl', 'image', '@id' ), true ) || is_array( $x ) ) {
				$v[ $k ] = $fix( $x );
			}
		}
		return $v;
	};
	return $fix( $data );
}, 1000 );
