<?php
/**
 * Rank Math bridge.
 *
 * Rank Math is used on this site for what editors see: the focus keyword, the
 * SEO title and description fields and the content score. The front end keeps
 * one owner of the <head> tags, which is this plugin (db-seo-meta-block.php), so
 * installing Rank Math changes nothing a visitor or a crawler receives:
 *
 *   1. Rank Math's own front-end output is switched off.
 *   2. An SEO title or description typed into Rank Math for a page, post or
 *      listing replaces this plugin's built-in copy for that URL.
 *   3. In the editor, Rank Math analyses the page as it is rendered. Most pages
 *      here are built by templates and filters, so the stored post content is a
 *      fraction of what the page shows; without this the score would describe
 *      an empty page.
 *
 * Define DB_RANKMATH_OWNS_HEAD as true (wp-config.php) to hand the <head> to
 * Rank Math instead. This plugin's SEO block then stands down.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Rank Math stays dormant until a rankmath.com account is connected or that step
// is skipped. Focus keywords and scores need no account, so the step is skipped
// here; an account can still be connected from Rank Math > Dashboard later.
// This file loads before Rank Math (plugins load in folder-name order).
if ( ! defined( 'RANK_MATH_REGISTRATION_SKIP' ) ) {
	define( 'RANK_MATH_REGISTRATION_SKIP', true );
}

if ( ! function_exists( 'db_rankmath_present' ) ) {
	/**
	 * True when the Rank Math plugin is loaded.
	 *
	 * @return bool
	 */
	function db_rankmath_present() {
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}
}

if ( ! function_exists( 'db_rankmath_analysis_only' ) ) {
	/**
	 * True when Rank Math is limited to the editor (the default).
	 *
	 * @return bool
	 */
	function db_rankmath_analysis_only() {
		return ! ( defined( 'DB_RANKMATH_OWNS_HEAD' ) && DB_RANKMATH_OWNS_HEAD );
	}
}

/* ------------------------------------------------------------------------
 * Mode B: Rank Math owns the <head>. This plugin's copy becomes the fallback.
 * --------------------------------------------------------------------- */

if ( ! function_exists( 'db_rankmath_has_field' ) ) {
	/**
	 * True when the current singular page has its own Rank Math value for a field.
	 *
	 * @param string $key rank_math_title or rank_math_description.
	 * @return bool
	 */
	function db_rankmath_has_field( $key ) {
		if ( ! ( is_singular() || is_front_page() ) ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof WP_Post && '' !== trim( (string) get_post_meta( $post->ID, $key, true ) );
	}
}

if ( ! db_rankmath_analysis_only() ) {
	add_filter( 'rank_math/frontend/title', function ( $title ) {
		if ( db_rankmath_has_field( 'rank_math_title' ) || ! function_exists( 'db_seo_resolve' ) ) {
			return $title;
		}
		$def = db_seo_resolve();
		return ( is_array( $def ) && ! empty( $def['title'] ) ) ? $def['title'] : $title;
	}, 20 );

	add_filter( 'rank_math/frontend/description', function ( $description ) {
		if ( db_rankmath_has_field( 'rank_math_description' ) || ! function_exists( 'db_seo_resolve' ) ) {
			return $description;
		}
		$def = db_seo_resolve();
		return ( is_array( $def ) && ! empty( $def['desc'] ) ) ? $def['desc'] : $description;
	}, 20 );

	// Utility and checkout pages, search results, tag archives and old
	// syndicated news stay out of the index, as they do in mode A.
	add_filter( 'rank_math/frontend/robots', function ( $robots ) {
		$utility = ( function_exists( 'db_seo_is_utility' ) && db_seo_is_utility() ) || is_search();
		$thin    = is_tag() || ( is_singular( 'post' ) && function_exists( 'db_index_is_thin_news' ) && db_index_is_thin_news( get_queried_object() ) );
		if ( $utility || $thin ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'follow';
		}
		// A staging copy (beta host, or "Discourage search engines" ticked) is never indexed.
		if ( '0' === (string) get_option( 'blog_public', '1' ) || ( function_exists( 'db_is_staging_host' ) && db_is_staging_host() ) ) {
			$robots['index']  = 'noindex';
			$robots['follow'] = 'nofollow';
		}
		return $robots;
	}, 30 );

	// Same rule for Rank Math's XML sitemap.
	add_filter( 'rank_math/sitemap/entry', function ( $entry, $type, $object ) {
		// Rank Math builds post sitemaps from raw database rows (stdClass), not WP_Post.
		if ( 'post' === $type && is_object( $object ) && isset( $object->ID ) && ! $object instanceof WP_Post ) {
			$object = get_post( (int) $object->ID );
		}
		if ( 'post' === $type && $object instanceof WP_Post ) {
			if ( function_exists( 'db_index_is_thin_news' ) && db_index_is_thin_news( $object ) ) {
				return false;
			}
			if ( 'page' === $object->post_type && function_exists( 'db_seo_utility_slugs' ) && isset( db_seo_utility_slugs()[ $object->post_name ] ) ) {
				return false;
			}
		}
		return $entry;
	}, 20, 3 );
}

/*
 * The same rule inside Rank Math's sitemap queries, so the page count in
 * sitemap_index.xml matches the URLs that remain (the entry filter alone
 * leaves empty, 404 sitemap pages listed in the index).
 */
if ( ! function_exists( 'db_rankmath_sitemap_news_where' ) ) {
	/**
	 * @param string       $where      SQL from earlier filters.
	 * @param string|array $post_types Post type(s) being queried.
	 * @return string
	 */
	function db_rankmath_sitemap_news_where( $where, $post_types ) {
		if ( ! in_array( 'post', (array) $post_types, true ) || ! function_exists( 'db_index_news_days' ) ) {
			return $where;
		}
		$news   = get_category_by_slug( 'domain-news' );
		$guides = get_category_by_slug( 'domain-guides' );
		if ( ! $news ) {
			return $where;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - (int) db_index_news_days() * DAY_IN_SECONDS );
		$in_cat = "SELECT tr.object_id FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'category' AND tt.term_id = %d";
		$sql    = ' AND NOT ( p.post_type = \'post\' AND p.post_date_gmt < %s AND p.ID IN ( ' . $in_cat . ' )';
		$args   = array( $cutoff, (int) $news->term_id );
		if ( $guides ) {
			$sql   .= ' AND p.ID NOT IN ( ' . $in_cat . ' )';
			$args[] = (int) $guides->term_id;
		}
		return $where . $wpdb->prepare( $sql . ' )', $args ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
if ( ! db_rankmath_analysis_only() ) {
	add_filter( 'rank_math/sitemap/get_posts/where', 'db_rankmath_sitemap_news_where', 10, 2 );
	add_filter( 'rank_math/sitemap/post_count/where', 'db_rankmath_sitemap_news_where', 10, 2 );
}

// Rank Math caches sitemap files. When this plugin's version changes, the
// sitemap rules above may have changed too, so the cache is emptied once.
add_action( 'init', function () {
	if ( get_option( 'db_rm_sitemap_flushed', '' ) === DB_BLOCKS_LOADED || ! class_exists( '\RankMath\Sitemap\Cache' ) ) {
		return;
	}
	update_option( 'db_rm_sitemap_flushed', DB_BLOCKS_LOADED, false );
	\RankMath\Sitemap\Cache::invalidate_storage();
}, 99 );

// Administrators switch the mode here; the page cache is emptied so every URL
// is rebuilt with the new owner's tags.
add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/seo-mode', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => function () {
				return rest_ensure_response( array( 'owner' => db_rankmath_analysis_only() ? 'plugin' : 'rankmath', 'rank_math_active' => db_rankmath_present() ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'args'                => array(
				'owner' => array(
					'required'          => true,
					'validate_callback' => function ( $value ) {
						return in_array( $value, array( 'plugin', 'rankmath' ), true );
					},
				),
			),
			'callback'            => function ( WP_REST_Request $request ) {
				update_option( 'db_rankmath_owns_head', 'rankmath' === $request['owner'] ? '1' : '0', true );
				if ( function_exists( 'db_pc_purge_all' ) ) {
					db_pc_purge_all();
				}
				return rest_ensure_response( array( 'owner' => $request['owner'], 'applies' => 'from the next request' ) );
			},
		),
	) );
} );

// 1. No Rank Math tags on the front end while this plugin owns the <head>.
add_filter( 'rank_math/frontend/disable_integration', function ( $disabled ) {
	return db_rankmath_analysis_only() ? true : $disabled;
} );

if ( ! function_exists( 'db_rankmath_text' ) ) {
	/**
	 * A Rank Math field as plain text, with its %variables% expanded.
	 *
	 * @param string       $value Stored field value.
	 * @param WP_Post|null $post  Post the value belongs to.
	 * @return string
	 */
	function db_rankmath_text( $value, $post = null ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		if ( false !== strpos( $value, '%' ) && class_exists( '\\RankMath\\Helper' ) && is_callable( array( '\\RankMath\\Helper', 'replace_vars' ) ) ) {
			$value = (string) \RankMath\Helper::replace_vars( $value, $post );
		}
		return trim( wp_strip_all_tags( $value ) );
	}
}

if ( ! function_exists( 'db_rankmath_overlay' ) ) {
	/**
	 * 2. Let the Rank Math fields of the current page override the built-in copy.
	 *
	 * @param array|null $def SEO definition (title, desc, kw) or null.
	 * @return array|null
	 */
	function db_rankmath_overlay( $def ) {
		if ( ! db_rankmath_analysis_only() || ! ( is_singular() || is_front_page() ) ) {
			return $def;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return $def;
		}
		$title = db_rankmath_text( get_post_meta( $post->ID, 'rank_math_title', true ), $post );
		$desc  = db_rankmath_text( get_post_meta( $post->ID, 'rank_math_description', true ), $post );
		if ( '' === $title && '' === $desc ) {
			return $def;
		}
		if ( ! is_array( $def ) ) {
			$def = array( 'title' => '', 'desc' => '', 'kw' => '' );
		}
		if ( '' !== $title ) {
			$def['title'] = $title;
		}
		if ( '' !== $desc ) {
			$def['desc'] = $desc;
		}
		return $def;
	}
}

if ( ! function_exists( 'db_rankmath_rendered_types' ) ) {
	/**
	 * Post types whose pages are assembled by templates and filters.
	 *
	 * @return string[]
	 */
	function db_rankmath_rendered_types() {
		return (array) apply_filters( 'db_rankmath_rendered_types', array( 'page', 'domain' ) );
	}
}

// 3. Feed the rendered page to the Rank Math analysis in the editor.
add_action( 'rank_math/admin/editor_scripts', function () {
	$post = get_post();
	if ( ! $post instanceof WP_Post || 'publish' !== $post->post_status || ! in_array( $post->post_type, db_rankmath_rendered_types(), true ) ) {
		return;
	}
	$url = get_permalink( $post );
	if ( ! $url ) {
		return;
	}
	$handle = 'db-rankmath-rendered';
	wp_register_script( $handle, false, array( 'wp-hooks' ), DB_BLOCKS_LOADED, true );
	wp_enqueue_script( $handle );
	wp_add_inline_script( $handle, 'window.dbRankMath = ' . wp_json_encode( array( 'url' => add_query_arg( 'db_seo_src', '1', $url ) ) ) . ';' . db_rankmath_editor_js() );
}, 30 );

if ( ! function_exists( 'db_rankmath_editor_js' ) ) {
	/**
	 * Editor script: fetch the public page, keep its main content and hand that
	 * to Rank Math through its documented `rank_math_content` filter.
	 *
	 * @return string
	 */
	function db_rankmath_editor_js() {
		return <<<'JS'
(function () {
	if (!window.wp || !wp.hooks || !window.dbRankMath || !window.fetch) { return; }
	var rendered = '';
	wp.hooks.addFilter('rank_math_content', 'db-custom-blocks/rendered', function (content) {
		return rendered || content;
	}, 99);
	function refresh() {
		if (window.rankMathEditor && typeof rankMathEditor.refresh === 'function') { rankMathEditor.refresh('content'); }
	}
	fetch(dbRankMath.url, { credentials: 'same-origin' }).then(function (r) {
		return r.ok ? r.text() : '';
	}).then(function (html) {
		if (!html) { return; }
		var doc = new DOMParser().parseFromString(html, 'text/html');
		var main = doc.querySelector('main') || doc.querySelector('#content') || doc.body;
		if (!main) { return; }
		main.querySelectorAll('script,style,noscript,template,svg,iframe,form,nav,.db-ad,.screen-reader-text,[hidden],[aria-hidden="true"]').forEach(function (el) { el.remove(); });
		rendered = main.innerHTML.replace(/\s+/g, ' ');
		window.dbRankMath.words = (main.textContent || '').trim().split(/\s+/).length;
		window.dbRankMath.ready = true;
		refresh();
		setTimeout(refresh, 1500);
	}).catch(function () { window.dbRankMath.ready = true; });
}());
JS;
	}
}
