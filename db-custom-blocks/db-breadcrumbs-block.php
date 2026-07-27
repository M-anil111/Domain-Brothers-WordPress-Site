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

		if ( is_page( 'all-domains' ) ) {
			$trail[] = array( 'label' => 'All Domains', 'href' => '' );
			return $trail;
		}

		if ( is_page() ) {
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

add_action( 'template_redirect', function () {
	if ( is_admin() ) {
		return;
	}
	$trail = db_breadcrumbs_trail();
	if ( null === $trail ) {
		return;
	}
	ob_start( function ( $html ) use ( $trail ) {
		if ( false === strpos( $html, '<main id="main"' ) || false !== strpos( $html, 'db-breadcrumbs' ) ) {
			return $html;
		}
		return preg_replace( '#(<main id="main"[^>]*>)#', '$1' . db_breadcrumbs_html( $trail ), $html, 1 );
	} );
}, 19 );
