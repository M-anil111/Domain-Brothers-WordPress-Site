<?php
/**
 * DB OKF Block — Open Knowledge Format bundle for AI agents
 *
 * Two sources were reviewed for this: Karpathy's "LLM wiki" pattern
 * (persistent, cross-referenced markdown knowledge base an LLM maintains
 * instead of re-deriving context from raw sources every time), and Google
 * Cloud's Open Knowledge Format spec (June 2026), which formalizes that
 * pattern into a vendor-neutral bundle — a directory of markdown files with
 * YAML frontmatter, one per "concept" (table, dataset, metric, etc.), plus
 * index.md files for navigation and plain markdown links for
 * cross-references. Neither was implemented on this site: no /okf/ path, no
 * llms.txt, nothing machine-readable beyond the per-page schema.org JSON-LD
 * BLOCK 9 already emits (db_aeo_emit() in db-custom-blocks.php — that's
 * still the right tool for per-page SEO markup; this is a separate,
 * complementary catalog for agents that want the whole inventory at once).
 *
 * This implements a minimal OKF bundle over what this site actually has
 * structured data about: the domain-for-sale inventory, categories,
 * services, and core business/FAQ info. Every file is generated live from
 * the same CPT/meta/taxonomy data the rest of the site already renders
 * from — not static files — so the bundle can never drift out of sync with
 * the real listings.
 *
 * Bundle root: /okf/index.md
 *   /okf/domains/index.md        — all current domain listings
 *   /okf/domains/{slug}.md       — one concept per domain
 *   /okf/categories/index.md     — domain categories
 *   /okf/categories/{slug}.md    — one concept per category, with member links
 *   /okf/services/index.md       — agency services offered
 *   /okf/services/{slug}.md      — one concept per service
 *   /okf/business/index.md       — org info, payment plans, FAQs
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	add_rewrite_tag( '%db_okf_path%', '(.+)' );
	add_rewrite_rule( '^okf/(.+)\.md$', 'index.php?db_okf_path=$matches[1]', 'top' );
} );

add_action( 'init', function () {
	// Rewrite rules only take effect after a flush. Rather than requiring a
	// manual visit to Settings -> Permalinks after every deploy, self-heal
	// once per plugin version bump.
	if ( get_option( 'db_okf_rewrite_ver' ) !== DB_BLOCKS_LOADED ) {
		flush_rewrite_rules( false );
		update_option( 'db_okf_rewrite_ver', DB_BLOCKS_LOADED );
	}
}, 20 );

if ( ! function_exists( 'db_okf_yaml_scalar' ) ) {
	function db_okf_yaml_scalar( $value ) {
		$value = trim( (string) $value );
		if ( '' !== $value && preg_match( '/^[A-Za-z0-9 ._\/:-]+$/', $value ) ) {
			return $value;
		}
		return '"' . str_replace( array( '\\', '"', "\n" ), array( '\\\\', '\\"', ' ' ), $value ) . '"';
	}
}

if ( ! function_exists( 'db_okf_frontmatter' ) ) {
	/** $fields is an ordered assoc array; string values or arrays of strings (rendered as a YAML list). */
	function db_okf_frontmatter( $fields ) {
		$lines = array( '---' );
		foreach ( $fields as $key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				if ( empty( $value ) ) {
					continue;
				}
				$lines[] = $key . ':';
				foreach ( $value as $v ) {
					$lines[] = '  - ' . db_okf_yaml_scalar( $v );
				}
			} else {
				$lines[] = $key . ': ' . db_okf_yaml_scalar( $value );
			}
		}
		$lines[] = '---';
		return implode( "\n", $lines ) . "\n\n";
	}
}

if ( ! function_exists( 'db_okf_services' ) ) {
	// Same slug/title map BLOCK 9's schema.org Service markup already uses
	// (db-custom-blocks.php) — kept as one canonical list so both systems
	// describe the same five services identically.
	function db_okf_services() {
		return array(
			'website-design-development' => 'Website Design & Development',
			'digital-marketing'          => 'Digital Marketing',
			'software-development'       => 'Software Development',
			'mobile-app-development'     => 'Mobile App Development',
			'other-services'             => 'Other Services',
		);
	}
}

if ( ! function_exists( 'db_okf_domain_summary' ) ) {
	function db_okf_domain_summary( $post ) {
		$price   = get_post_meta( $post->ID, 'domain_price', true );
		$terms   = get_the_terms( $post->ID, 'domain_category' );
		$cat     = ( is_array( $terms ) && ! empty( $terms ) ) ? $terms[0]->name : '';
		return array(
			'name'  => get_the_title( $post ),
			'price' => $price ? preg_replace( '/[^0-9.]/', '', $price ) : '',
			'cat'   => $cat,
		);
	}
}

if ( ! function_exists( 'db_okf_render' ) ) {
	function db_okf_render( $path ) {
		$path = trim( (string) $path, '/' );

		if ( '' === $path || 'index' === $path ) {
			return db_okf_render_root();
		}
		if ( 'domains/index' === $path ) {
			return db_okf_render_domains_index();
		}
		if ( preg_match( '#^domains/([^/]+)$#', $path, $m ) ) {
			return db_okf_render_domain( $m[1] );
		}
		if ( 'categories/index' === $path ) {
			return db_okf_render_categories_index();
		}
		if ( preg_match( '#^categories/([^/]+)$#', $path, $m ) ) {
			return db_okf_render_category( $m[1] );
		}
		if ( 'services/index' === $path ) {
			return db_okf_render_services_index();
		}
		if ( preg_match( '#^services/([^/]+)$#', $path, $m ) ) {
			return db_okf_render_service( $m[1] );
		}
		if ( 'business/index' === $path ) {
			return db_okf_render_business();
		}
		return null;
	}
}

if ( ! function_exists( 'db_okf_render_root' ) ) {
	function db_okf_render_root() {
		$fm = db_okf_frontmatter( array(
			'type'        => 'Knowledge Catalog Index',
			'title'       => 'Domain Brothers — Knowledge Catalog',
			'description' => 'Open Knowledge Format bundle describing the Domain Brothers domain marketplace: current inventory, categories, services, and business info.',
			'resource'    => home_url( '/' ),
			'timestamp'   => current_time( 'c' ),
		) );
		$body = "# Domain Brothers — Knowledge Catalog\n\n"
			. "Domain Brothers is a premium domain name brokerage: acquisition, escrow-protected transfer, and 0%-interest payment plans.\n\n"
			. "## Concepts\n\n"
			. "- [Domains](/okf/domains/index.md) — domains currently for sale\n"
			. "- [Categories](/okf/categories/index.md) — domain categories\n"
			. "- [Services](/okf/services/index.md) — agency services offered\n"
			. "- [Business](/okf/business/index.md) — company info, payment plans, FAQs\n";
		return $fm . $body;
	}
}

if ( ! function_exists( 'db_okf_render_domains_index' ) ) {
	function db_okf_render_domains_index() {
		$q = new WP_Query( array(
			'post_type'      => 'domain',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		) );
		$fm = db_okf_frontmatter( array(
			'type'        => 'Concept Index',
			'title'       => 'Domain Listings',
			'description' => 'All domains currently for sale on Domain Brothers.',
			'resource'    => home_url( '/all-domains/' ),
			'timestamp'   => current_time( 'c' ),
		) );
		$lines = array( "# Domain Listings\n" );
		foreach ( $q->posts as $post ) {
			$s     = db_okf_domain_summary( $post );
			$price = $s['price'] ? ' — $' . number_format( (float) $s['price'], 0 ) : '';
			$lines[] = '- [' . $s['name'] . '](/okf/domains/' . $post->post_name . '.md)' . $price;
		}
		wp_reset_postdata();
		return $fm . implode( "\n", $lines ) . "\n";
	}
}

if ( ! function_exists( 'db_okf_render_domain' ) ) {
	function db_okf_render_domain( $slug ) {
		$post = get_page_by_path( $slug, OBJECT, 'domain' );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return null;
		}
		$s      = db_okf_domain_summary( $post );
		$url    = get_permalink( $post );
		$excerpt = wp_strip_all_tags( $post->post_content );
		$excerpt = trim( preg_replace( '/\s+/', ' ', $excerpt ) );
		if ( strlen( $excerpt ) > 600 ) {
			$excerpt = substr( $excerpt, 0, 600 ) . '…';
		}
		$fm = db_okf_frontmatter( array(
			'type'        => 'Domain Listing',
			'title'       => $s['name'],
			'description' => 'Premium domain ' . $s['name'] . ( $s['cat'] ? ' in the ' . $s['cat'] . ' category' : '' ) . ', available for purchase or via a 0%-interest payment plan.',
			'resource'    => $url,
			'tags'        => $s['cat'] ? array( $s['cat'] ) : array(),
			'timestamp'   => get_post_modified_time( 'c', true, $post ),
		) );
		$body  = "# " . $s['name'] . "\n\n";
		if ( $s['price'] ) {
			$body .= '**Price:** $' . number_format( (float) $s['price'], 0 ) . " USD\n\n";
		}
		if ( $s['cat'] ) {
			$body .= '**Category:** [' . $s['cat'] . '](/okf/categories/' . sanitize_title( $s['cat'] ) . ".md)\n\n";
		}
		if ( $excerpt ) {
			$body .= $excerpt . "\n\n";
		}
		$body .= '**Listing page:** ' . $url . "\n";
		return $fm . $body;
	}
}

if ( ! function_exists( 'db_okf_render_categories_index' ) ) {
	function db_okf_render_categories_index() {
		$terms = get_terms( array( 'taxonomy' => 'domain_category', 'hide_empty' => false ) );
		$fm    = db_okf_frontmatter( array(
			'type'        => 'Concept Index',
			'title'       => 'Domain Categories',
			'description' => 'Categories domains are grouped into on Domain Brothers.',
			'resource'    => home_url( '/all-domains/' ),
			'timestamp'   => current_time( 'c' ),
		) );
		$lines = array( "# Domain Categories\n" );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$lines[] = '- [' . $term->name . '](/okf/categories/' . $term->slug . '.md) (' . $term->count . ' domains)';
			}
		}
		return $fm . implode( "\n", $lines ) . "\n";
	}
}

if ( ! function_exists( 'db_okf_render_category' ) ) {
	function db_okf_render_category( $slug ) {
		$term = get_term_by( 'slug', $slug, 'domain_category' );
		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}
		$q = new WP_Query( array(
			'post_type'      => 'domain',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'tax_query'      => array( array( 'taxonomy' => 'domain_category', 'field' => 'slug', 'terms' => $slug ) ),
		) );
		$fm = db_okf_frontmatter( array(
			'type'        => 'Domain Category',
			'title'       => $term->name,
			'description' => $term->description ? wp_strip_all_tags( $term->description ) : ( $term->name . ' domains for sale on Domain Brothers.' ),
			'resource'    => get_term_link( $term ),
			'timestamp'   => current_time( 'c' ),
		) );
		$lines = array( '# ' . $term->name . "\n" );
		foreach ( $q->posts as $post ) {
			$s     = db_okf_domain_summary( $post );
			$price = $s['price'] ? ' — $' . number_format( (float) $s['price'], 0 ) : '';
			$lines[] = '- [' . $s['name'] . '](/okf/domains/' . $post->post_name . '.md)' . $price;
		}
		wp_reset_postdata();
		return $fm . implode( "\n", $lines ) . "\n";
	}
}

if ( ! function_exists( 'db_okf_render_services_index' ) ) {
	function db_okf_render_services_index() {
		$fm = db_okf_frontmatter( array(
			'type'        => 'Concept Index',
			'title'       => 'Services',
			'description' => 'Agency services Domain Brothers offers alongside domain brokerage.',
			'resource'    => home_url( '/our-services/' ),
			'timestamp'   => current_time( 'c' ),
		) );
		$lines = array( "# Services\n" );
		foreach ( db_okf_services() as $slug => $name ) {
			$lines[] = '- [' . $name . '](/okf/services/' . $slug . '.md)';
		}
		return $fm . implode( "\n", $lines ) . "\n";
	}
}

if ( ! function_exists( 'db_okf_render_service' ) ) {
	function db_okf_render_service( $slug ) {
		$services = db_okf_services();
		if ( ! isset( $services[ $slug ] ) ) {
			return null;
		}
		$page = get_page_by_path( $slug );
		$url  = $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
		$desc = $page ? wp_strip_all_tags( $page->post_excerpt ? $page->post_excerpt : $page->post_content ) : '';
		$desc = trim( preg_replace( '/\s+/', ' ', $desc ) );
		if ( strlen( $desc ) > 400 ) {
			$desc = substr( $desc, 0, 400 ) . '…';
		}
		$fm = db_okf_frontmatter( array(
			'type'        => 'Service',
			'title'       => $services[ $slug ],
			'description' => $desc ? $desc : ( $services[ $slug ] . ' service offered by Domain Brothers.' ),
			'resource'    => $url,
			'timestamp'   => current_time( 'c' ),
		) );
		$body = '# ' . $services[ $slug ] . "\n\n" . ( $desc ? $desc . "\n\n" : '' ) . '**Page:** ' . $url . "\n";
		return $fm . $body;
	}
}

if ( ! function_exists( 'db_okf_render_business' ) ) {
	function db_okf_render_business() {
		$fm = db_okf_frontmatter( array(
			'type'        => 'Business Info',
			'title'       => 'Domain Brothers — Business Info',
			'description' => 'Company info, payment plans, and frequently asked questions for Domain Brothers.',
			'resource'    => home_url( '/' ),
			'timestamp'   => current_time( 'c' ),
		) );
		$body  = "# Domain Brothers — Business Info\n\n";
		$body .= "Domain Brothers is a premium domain name brokerage offering expert acquisition, flexible payment plans, and secure escrow services.\n\n";
		$body .= "**Contact:** sales@domainbrothers.com\n\n";
		$body .= "## Payment Plans\n\nDomain Brothers offers 0% interest instalment plans over 3, 6, 9, or 12 months. The total price is divided into equal monthly payments. During the payment plan, the domain's DNS can be pointed to the buyer's servers so it can be used immediately; legal ownership transfers once the final payment clears.\n\n";
		$body .= "## Domain Transfers\n\nMost .com domain transfers complete within 5–7 days. Country-code domains can complete faster, sometimes within 24 hours. Transfers use secure escrow and established registrar transfer protocols.\n\n";
		$body .= "## Payment Methods\n\nVisa, Mastercard, American Express, Apple Pay, and Google Pay, processed via Stripe (PCI-DSS compliant).\n\n";
		$body .= "## Related Concepts\n\n- [Domains](/okf/domains/index.md)\n- [Categories](/okf/categories/index.md)\n- [Services](/okf/services/index.md)\n";
		return $fm . $body;
	}
}

add_action( 'template_redirect', function () {
	$path = get_query_var( 'db_okf_path' );
	if ( null === $path || '' === $path ) {
		return;
	}
	$markdown = db_okf_render( $path );
	if ( null === $markdown ) {
		return; // fall through to WordPress's normal 404 handling
	}
	status_header( 200 );
	header( 'Content-Type: text/markdown; charset=UTF-8' );
	header( 'Cache-Control: public, max-age=3600' );
	echo $markdown;
	exit;
}, 0 );

add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	echo '<link rel="knowledge-catalog" type="text/markdown" href="' . esc_url( home_url( '/okf/index.md' ) ) . '">' . "\n";
}, 5 );
