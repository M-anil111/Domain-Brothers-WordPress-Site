<?php
/**
 * DB Breadcrumbs Block — a real trail on every front-end template
 *
 * The theme renders no breadcrumb anywhere (confirmed live across the
 * domain single, category archive, /all-domains/, /news/, single post and
 * generic Page templates). Inserted right after <main id="main"> — the one
 * landmark confirmed present, unchanged, on every one of those templates —
 * rather than a template-specific anchor that would need a different regex
 * per page type.
 *
 * Deliberately skipped on the homepage (own hero, "Home" would be the only
 * crumb) and on transactional/utility pages (checkout, offer, payment,
 * search, thank-you) — a breadcrumb back to a domain's own sale page isn't
 * useful mid-checkout, and the owner has kept those pages minimal on
 * purpose elsewhere in this codebase (see db_safety_template_guards()).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_breadcrumbs_is_utility' ) ) {
	function db_breadcrumbs_is_utility() {
		if ( ! is_page() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		return in_array(
			$post->post_name,
			array( 'checkout', 'payment-plan-setup', 'payment-successful', 'buy-now', 'offer', 'thank-you' ),
			true
		);
	}
}

if ( ! function_exists( 'db_breadcrumbs_trail' ) ) {
	/**
	 * @return array<int, array{label:string, href:string}>|null Null means
	 *         "don't show a trail on this request".
	 */
	function db_breadcrumbs_trail() {
		if ( is_admin() || is_front_page() || is_search() || is_404() || is_feed() || db_breadcrumbs_is_utility() ) {
			return null;
		}

		$home  = array( 'label' => 'Home', 'href' => home_url( '/' ) );
		$trail = array( $home );

		if ( is_singular( 'domain' ) ) {
			$post = get_queried_object();
			$terms = $post instanceof WP_Post ? get_the_terms( $post, 'domain_category' ) : false;
			if ( is_array( $terms ) && ! empty( $terms ) ) {
				$term = $terms[0];
				$trail[] = array( 'label' => $term->name, 'href' => get_term_link( $term ) );
			} else {
				$trail[] = array( 'label' => 'All Domains', 'href' => home_url( '/all-domains/' ) );
			}
			$trail[] = array( 'label' => get_the_title( $post ), 'href' => '' );
			return $trail;
		}

		if ( is_tax( 'domain_category' ) ) {
			$term = get_queried_object();
			$trail[] = array( 'label' => 'All Domains', 'href' => home_url( '/all-domains/' ) );
			$trail[] = array( 'label' => $term instanceof WP_Term ? $term->name : '', 'href' => '' );
			return $trail;
		}

		if ( is_home() ) { // /news/ — see db-news-block.php's docblock on why is_home(), not is_page('news').
			$trail[] = array( 'label' => 'News', 'href' => '' );
			return $trail;
		}

		if ( is_singular( 'post' ) ) {
			$trail[] = array( 'label' => 'News', 'href' => home_url( '/news/' ) );
			$trail[] = array( 'label' => get_the_title(), 'href' => '' );
			return $trail;
		}

		if ( is_category() || is_tag() ) {
			$term    = get_queried_object();
			$trail[] = array( 'label' => 'News', 'href' => home_url( '/news/' ) );
			$trail[] = array( 'label' => $term instanceof WP_Term ? $term->name : '', 'href' => '' );
			return $trail;
		}

		if ( is_page( 'all-domains' ) ) {
			$trail[] = array( 'label' => 'All Domains', 'href' => '' );
			return $trail;
		}

		if ( is_page() ) {
			$page = get_queried_object();
			if ( $page instanceof WP_Post && $page->post_parent ) {
				foreach ( array_reverse( get_post_ancestors( $page ) ) as $ancestor ) {
					$trail[] = array( 'label' => get_the_title( $ancestor ), 'href' => get_permalink( $ancestor ) );
				}
			}
			$trail[] = array( 'label' => get_the_title(), 'href' => '' );
			return $trail;
		}

		return null;
	}
}

if ( ! function_exists( 'db_breadcrumbs_html' ) ) {
	function db_breadcrumbs_html( $trail ) {
		$html = '<nav class="db-breadcrumbs" aria-label="Breadcrumb"><ol>';
		$last = count( $trail ) - 1;
		foreach ( $trail as $i => $crumb ) {
			$html .= '<li>';
			if ( '' !== $crumb['href'] && $i !== $last ) {
				$html .= '<a href="' . esc_url( $crumb['href'] ) . '">' . esc_html( $crumb['label'] ) . '</a>';
			} else {
				$html .= '<span aria-current="page">' . esc_html( $crumb['label'] ) . '</span>';
			}
			$html .= '</li>';
		}
		$html .= '</ol></nav>';
		return $html;
	}
}

if ( ! function_exists( 'db_breadcrumbs_from_rankmath' ) ) {
	/**
	 * When Rank Math breadcrumbs are switched on, the trail comes from Rank Math
	 * (its settings: home label, separator, primary category) for real WordPress
	 * objects; this plugin's own trail stays for its virtual routes and as the
	 * fallback. The markup and the bar stay the same either way.
	 *
	 * @param array $trail This plugin's trail.
	 * @return array
	 */
	function db_breadcrumbs_from_rankmath( array $trail ) {
		if ( ! class_exists( '\\RankMath\\Frontend\\Breadcrumbs' ) || ! class_exists( '\\RankMath\\Helper' ) || ! \RankMath\Helper::get_settings( 'general.breadcrumbs' ) ) {
			return $trail;
		}
		if ( ! ( is_singular() || is_category() || is_tax() || is_home() ) || is_singular( 'domain' ) ) {
			return $trail; // domain listings keep the category-aware trail built here
		}
		$bc = \RankMath\Frontend\Breadcrumbs::get();
		if ( ! $bc || ! is_callable( array( $bc, 'get_crumbs' ) ) ) {
			return $trail;
		}
		$out = array();
		foreach ( (array) $bc->get_crumbs() as $crumb ) {
			if ( ! is_array( $crumb ) || ! isset( $crumb[0] ) || '' === trim( wp_strip_all_tags( (string) $crumb[0] ) ) ) {
				continue;
			}
			$out[] = array( 'label' => wp_strip_all_tags( (string) $crumb[0] ), 'href' => isset( $crumb[1] ) ? (string) $crumb[1] : '' );
		}
		return count( $out ) >= 2 ? $out : $trail;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() ) {
		return;
	}
	$trail = db_breadcrumbs_trail();
	if ( null === $trail ) {
		return;
	}
	$trail = db_breadcrumbs_from_rankmath( $trail );
	ob_start( function ( $html ) use ( $trail ) {
		if ( false === strpos( $html, '<main id="main"' ) || false !== strpos( $html, 'db-breadcrumbs' ) ) {
			return $html;
		}
		// Insert with a callback, not a replacement string: post titles such
		// as "So $1,999 for 83.com anyone?" contain "$1", which preg_replace
		// expanded into the captured <main> tag (a second <main id="main">
		// inside the breadcrumb on 13 posts, found by the full-site crawl).
		$crumbs = db_breadcrumbs_html( $trail );
		return preg_replace_callback( '#<main id="main"[^>]*>#', function ( $m ) use ( $crumbs ) {
			return $m[0] . $crumbs;
		}, $html, 1 );
	} );
}, 19 );
