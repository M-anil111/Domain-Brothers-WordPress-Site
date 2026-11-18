<?php
/**
 * Page redesign (v3.80): service pages, listing pages, forms and utility pages.
 *
 * The content of these pages stays in WordPress. This block reshapes it at
 * render time and styles it with assets/pages.css, loaded only on these pages:
 *
 *   Service pages  the first heading and its paragraphs become a hero with two
 *                  actions and an "at a glance" card built from the page's own
 *                  bullet list; FAQs become native <details>; the old button
 *                  box becomes a closing call-to-action band.
 *   Listing pages  3, 4, 5 and 6-character names, numeric names and sold names
 *                  are rendered by this block instead of the theme's tables:
 *                  a title with the count, links between the lists, a search
 *                  box, sorting, cards, and a helpful empty state. The theme
 *                  showed "Nothing Found" for an empty list and listed sold
 *                  names among names for sale.
 *   Forms          the handle request form sits in a card beside three steps
 *                  that say what happens next; the other forms are restyled.
 *   EPP guide      each registrar's steps collapse into a <details> section
 *                  under a hero with links to every registrar.
 *   404, review    restyled as centred cards (CSS only).
 *
 * Every transform is a pure function of the HTML (tests/pages/pages-test.php)
 * and returns the input unchanged when the expected markup is not there.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_pages_service_slugs' ) ) {
	/** Pages laid out as service pages. */
	function db_pages_service_slugs() {
		return (array) apply_filters( 'db_pages_service_slugs', array(
			'domain-appraisal-services', 'domain-management-services', 'domain-brokerage-services',
			'digital-marketing-services', 'comprehensive-digital-solutions', 'web-hosting-maintenance-services',
			'mobile-app-development-services', 'custom-software-development-services',
			'sell-social-media-handles', 'acquire-social-media-handles', 'about-domain-brothers',
		) );
	}
}

if ( ! function_exists( 'db_pages_kind' ) ) {
	/** 'service' or '' for the current request. */
	function db_pages_kind() {
		if ( is_admin() ) {
			return '';
		}
		if ( is_404() ) {
			return 'utility';
		}
		if ( ! is_page() ) {
			return '';
		}
		if ( is_page( db_pages_service_slugs() ) ) {
			return 'service';
		}
		if ( is_page( array_keys( db_pages_listing_sets() ) ) || is_page( 'premium-domain-deals' ) ) {
			return 'listing';
		}
		if ( is_page( 'all-domains' ) ) {
			return 'all';
		}
		if ( is_page( 'epp-guidelines' ) ) {
			return 'epp';
		}
		if ( is_page( array( 'acquire-a-handle-now', 'acquire-premium-domain', 'domain-transfer' ) ) ) {
			return 'form';
		}
		return is_page( 'domains-under-review' ) ? 'utility' : '';
	}
}

add_filter( 'body_class', function ( $classes ) {
	$kind = db_pages_kind();
	if ( '' !== $kind ) {
		$classes[] = 'dbp';
		$classes[] = 'dbp-' . $kind;
	}
	return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( '' === db_pages_kind() ) {
		return;
	}
	$file = __DIR__ . '/assets/pages.css';
	$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( '' === $css ) {
		return;
	}
	if ( ! function_exists( 'db_external_style' ) || ! db_external_style( 'pages', $css ) ) {
		add_action( 'wp_head', function () use ( $css ) {
			echo '<style id="db-pages">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}, 121 );
	}
}, 111 );

/* ------------------------------------------------------------ transforms */

if ( ! function_exists( 'db_pages_faq_details' ) ) {
	/** Accordion-plugin items (always open, inline styles) become native <details>. */
	function db_pages_faq_details( $html ) {
		if ( false === strpos( $html, 'aagb__accordion_container' ) ) {
			return $html;
		}
		$out = preg_replace_callback(
			'#<div class="wp-block-aab-accordion-item aagb__accordion_container"[^>]*>.*?<h4 class="aagb__accordion_title"[^>]*>(.*?)</h4>.*?<div class="aagb__accordion_body[^"]*"[^>]*>(.*?)</div>\s*</div>#s',
			function ( $m ) {
				$q = trim( wp_strip_all_tags( $m[1] ) );
				if ( '' === $q ) {
					return $m[0];
				}
				return '<details class="dbp-faq"><summary>' . esc_html( $q ) . '</summary><div class="dbp-faq-a">' . trim( $m[2] ) . '</div></details>';
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}
}

if ( ! function_exists( 'db_pages_glance' ) ) {
	/** Up to five short labels from the first bullet list of the page. */
	function db_pages_glance( $html ) {
		if ( ! preg_match( '#<ul\b[^>]*>(.*?)</ul>#s', $html, $ul ) || ! preg_match_all( '#<li\b[^>]*>(.*?)</li>#s', $ul[1], $li ) ) {
			return array();
		}
		$out = array();
		foreach ( $li[1] as $item ) {
			if ( preg_match( '#<strong>(.*?)</strong>#s', $item, $s ) ) {
				$label = $s[1];
			} else {
				$parts = preg_split( '#<br\s*/?>#i', $item );
				$label = $parts[0];
			}
			$label = trim( rtrim( trim( wp_strip_all_tags( $label ) ), ':' ) );
			if ( '' !== $label && strlen( $label ) <= 48 ) {
				$out[] = $label;
			}
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return count( $out ) >= 2 ? $out : array();
	}
}

if ( ! function_exists( 'db_pages_service_layout' ) ) {
	/**
	 * Builds the service-page layout from the page content.
	 *
	 * @param string $html    Rendered page content.
	 * @param string $title   Page title.
	 * @param string $contact Contact page URL.
	 * @param string $browse  Domain listing URL.
	 */
	function db_pages_service_layout( $html, $title, $contact, $browse ) {
		$body = db_pages_faq_details( $html );
		$body = preg_replace( '#<div class="cta_box">.*?</div>#s', '', $body );
		// "<strong>Label</strong>: text" and "<strong>Label:</strong>text": the label sits on its own line in a card.
		$body = preg_replace( '#<strong>([^<]*?):?</strong>\s*:?\s*#', '<strong>$1</strong> ', $body );
		// Old wrapper around the whole page on some pages.
		$wrapped = (bool) preg_match( '#^\s*<div class="aboutUsPage">#', $body );
		if ( $wrapped ) {
			$body = preg_replace( '#^\s*<div class="aboutUsPage">#', '', $body, 1 );
			$body = preg_replace( '#</div>\s*$#', '', $body, 1 );
		}
		// Hero copy: the first heading (the tagline) and the paragraphs up to the next heading or box.
		$tagline = '';
		$lead    = '';
		if ( preg_match( '#^\s*<h2\b[^>]*>(.*?)</h2>((?:\s*<p\b[^>]*>.*?</p>)*)#s', $body, $m ) ) {
			$tagline = trim( wp_strip_all_tags( $m[1] ) );
			$lead    = trim( $m[2] );
			$body    = substr( $body, strlen( $m[0] ) );
		} elseif ( preg_match( '#^((?:\s*<p\b[^>]*>.*?</p>){1,2})#s', $body, $m ) ) {
			$lead = trim( $m[1] );
			$body = substr( $body, strlen( $m[0] ) );
		}
		$glance = db_pages_glance( $body );
		$hero   = '<section class="dbp-hero"><div class="dbp-hero-copy"><p class="dbp-eyebrow">Domain Brothers</p>'
			. '<h1 class="dbp-title">' . esc_html( $title ) . '</h1>'
			. ( '' !== $tagline ? '<p class="dbp-tagline">' . esc_html( $tagline ) . '</p>' : '' )
			. ( '' !== $lead ? '<div class="dbp-lead">' . $lead . '</div>' : '' )
			. '<p class="dbp-actions"><a class="dbp-btn dbp-btn--primary" href="' . esc_url( $contact ) . '">Talk to us</a><a class="dbp-btn" href="' . esc_url( $browse ) . '">Browse domains</a></p></div>';
		if ( $glance ) {
			$hero .= '<aside class="dbp-glance" aria-label="At a glance"><p class="dbp-glance-h">At a glance</p><ul>';
			foreach ( $glance as $g ) {
				$hero .= '<li>' . esc_html( $g ) . '</li>';
			}
			$hero .= '</ul></aside>';
		}
		$hero .= '</section>';
		$cta = '<section class="dbp-cta"><div><h2>Ready to get started?</h2><p>Tell us what you need. A person from our team replies within one business day.</p></div>'
			. '<p class="dbp-actions"><a class="dbp-btn dbp-btn--light" href="' . esc_url( $contact ) . '">Contact us</a><a class="dbp-btn dbp-btn--ghost" href="' . esc_url( $browse ) . '">Browse domains</a></p></section>';
		return $hero . '<div class="dbp-body">' . $body . '</div>' . $cta;
	}
}

add_filter( 'the_content', function ( $html ) {
	if ( 'service' !== db_pages_kind() || ! in_the_loop() || ! is_main_query() ) {
		return $html;
	}
	return db_pages_service_layout( $html, get_the_title(), home_url( '/contact-us/' ), home_url( '/all-domains/' ) );
}, 20 );

/* ------------------------------------------------------------- listings */

if ( ! function_exists( 'db_pages_listing_sets' ) ) {
	/** slug => title, short label for the links, intro, and the rule a name must meet. */
	function db_pages_listing_sets() {
		return array(
			'3-character-domains'      => array( 'title' => '3-character domains for sale', 'chip' => '3 characters', 'intro' => 'Three characters before the dot: the shortest names we list.', 'len' => 3 ),
			'4-character-domains'      => array( 'title' => '4-character domains for sale', 'chip' => '4 characters', 'intro' => 'Four characters before the dot. Short, easy to type and easy to remember.', 'len' => 4 ),
			'5-character-domains'      => array( 'title' => '5-character domains for sale', 'chip' => '5 characters', 'intro' => 'Five characters before the dot. Short enough for a brand, long enough for a real word.', 'len' => 5 ),
			'6-character-domains'      => array( 'title' => '6-character domains for sale', 'chip' => '6 characters', 'intro' => 'Six characters before the dot.', 'len' => 6 ),
			'numeric-domains-for-sale' => array( 'title' => 'Numeric domains for sale', 'chip' => 'Numeric', 'intro' => 'Names made only of digits.', 'numeric' => true ),
			'sold'                     => array( 'title' => 'Sold domains', 'chip' => 'Sold', 'intro' => 'Names that found a new owner through Domain Brothers.', 'sold' => true ),
		);
	}
}

if ( ! function_exists( 'db_pages_listing_match' ) ) {
	/** True when a domain name belongs to a for-sale list (label = the part before the first dot). */
	function db_pages_listing_match( array $set, $name ) {
		$name  = strtolower( (string) $name );
		$dot   = strpos( $name, '.' );
		$label = false === $dot ? $name : substr( $name, 0, $dot );
		if ( '' === $label ) {
			return false;
		}
		if ( ! empty( $set['numeric'] ) ) {
			return (bool) preg_match( '/^[0-9]+$/', $label );
		}
		return isset( $set['len'] ) && strlen( $label ) === (int) $set['len'];
	}
}

if ( ! function_exists( 'db_pages_listing_chips' ) ) {
	/** Links between the domain lists; the current one is marked. */
	function db_pages_listing_chips( $current, array $links ) {
		$h = '<nav class="dbl-chips" aria-label="Domain lists"><a href="' . esc_url( $links['all'] ) . '">All domains</a>';
		$counts = db_pages_listing_counts();
		foreach ( db_pages_listing_sets() as $k => $v ) {
			// A list with nothing in it is not offered; it comes back by itself
			// as soon as a matching name is listed.
			if ( $k !== $current && isset( $counts[ $k ] ) && 0 === $counts[ $k ] ) {
				continue;
			}
			$h .= '<a href="' . esc_url( $links[ $k ] ) . '"' . ( $k === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $v['chip'] ) . '</a>';
		}
		if ( isset( $links['deals'] ) ) {
			$h .= '<a href="' . esc_url( $links['deals'] ) . '"' . ( 'deals' === $current ? ' aria-current="page"' : '' ) . '>Deals</a>';
		}
		return $h . '</nav>';
	}
}

if ( ! function_exists( 'db_pages_listing_counts' ) ) {
	/**
	 * How many names each list holds right now (slug => count). Cached for
	 * fifteen minutes and dropped whenever a listing is saved or removed.
	 *
	 * @return array<string,int>
	 */
	function db_pages_listing_counts() {
		static $counts = null;
		if ( null !== $counts ) {
			return $counts;
		}
		$cached = get_transient( 'db_pages_set_counts' );
		if ( is_array( $cached ) ) {
			$counts = $cached;
			return $counts;
		}
		$counts = array();
		$posts  = function_exists( 'db_finder_candidates' ) ? (array) db_finder_candidates() : null;
		foreach ( db_pages_listing_sets() as $slug => $set ) {
			if ( ! empty( $set['sold'] ) ) {
				if ( function_exists( 'db_sold_listings' ) ) {
					$counts[ $slug ] = count( array_filter( (array) db_sold_listings(), function ( $p ) {
						return 'publish' === $p->post_status;
					} ) );
				}
				continue;
			}
			if ( null === $posts ) {
				continue; // unknown: keep the list visible
			}
			$n = 0;
			foreach ( $posts as $p ) {
				if ( db_pages_listing_match( $set, $p->post_title ) ) {
					++$n;
				}
			}
			$counts[ $slug ] = $n;
		}
		set_transient( 'db_pages_set_counts', $counts, 15 * MINUTE_IN_SECONDS );
		return $counts;
	}
}

foreach ( array( 'save_post_domain', 'deleted_post', 'trashed_post', 'untrashed_post' ) as $db_pages_hook ) {
	add_action( $db_pages_hook, function () {
		delete_transient( 'db_pages_set_counts' );
	} );
}
unset( $db_pages_hook );

if ( ! function_exists( 'db_pages_listing_links' ) ) {
	function db_pages_listing_links() {
		$links = array( 'all' => home_url( '/all-domains/' ), 'finder' => home_url( '/find-a-domain/' ), 'acquire' => home_url( '/acquire-premium-domain/' ), 'deals' => home_url( '/premium-domain-deals/' ) );
		foreach ( db_pages_listing_sets() as $k => $v ) {
			$links[ $k ] = home_url( '/' . $k . '/' );
		}
		return $links;
	}
}

if ( ! function_exists( 'db_pages_deals_layout' ) ) {
	/**
	 * The theme's deals page: its title becomes the shared list header, and an
	 * empty result ("No record(s)") becomes a helpful empty state instead of an
	 * empty table with filters.
	 */
	function db_pages_deals_layout( $html, array $links ) {
		if ( ! preg_match( '#<h1 class="page-title deals-title">.*?</h1>#s', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$empty = (bool) preg_match( '#No record\(s\)#i', $html );
		$head  = '<section class="dbl-head"><p class="dbp-eyebrow">Limited offers</p><h1 class="dbl-title">Premium domains on special deals</h1>'
			. '<p class="dbl-intro">Names we have reduced for a limited time.' . ( $empty ? ' <strong>No deals are running right now.</strong>' : '' ) . '</p>' . db_pages_listing_chips( 'deals', $links ) . '</section>';
		if ( $empty ) {
			$head .= '<section class="dbl-empty"><h2>No deals at the moment</h2><p>Deals come and go. Every listed name can still be bought in full or on a payment plan, and many are open to offers.</p>'
				. '<p class="dbp-actions"><a class="dbp-btn dbp-btn--primary" href="' . esc_url( $links['all'] ) . '">Browse all domains</a><a class="dbp-btn" href="' . esc_url( $links['finder'] ) . '">Use the domain finder</a></p></section>';
			$html = preg_replace( '#<main\b([^>]*)class="#', '<main$1class="dbl-deals-empty ', $html, 1 );
			$m[0][1] += strlen( 'dbl-deals-empty ' );
		}
		return substr_replace( $html, $head, $m[0][1], strlen( $m[0][0] ) );
	}
}

if ( ! function_exists( 'db_pages_listing_html' ) ) {
	/**
	 * The listing page body.
	 *
	 * @param string $slug  Current list.
	 * @param array  $rows  Each: name, url, price (0 = make an offer), monthly (0 = none), cat.
	 * @param array  $links slug => url for every list, plus 'all' and 'finder'.
	 */
	function db_pages_listing_html( $slug, array $rows, array $links ) {
		$sets = db_pages_listing_sets();
		$set  = $sets[ $slug ];
		$sold = ! empty( $set['sold'] );
		$n    = count( $rows );
		$h    = '<section class="dbl-head"><p class="dbp-eyebrow">' . ( $sold ? 'Track record' : 'Browse by type' ) . '</p><h1 class="dbl-title">' . esc_html( $set['title'] ) . '</h1>'
			. '<p class="dbl-intro">' . esc_html( $set['intro'] ) . ' <strong>' . (int) $n . ' ' . ( 1 === $n ? 'name' : 'names' ) . ( $sold ? ' sold' : ' available' ) . '.</strong></p>';
		$h   .= db_pages_listing_chips( $slug, $links ) . '</section>';
		if ( ! $rows ) {
			return $h . '<section class="dbl-empty"><h2>No names in this list right now</h2><p>Our inventory changes every week. These are good places to look next.</p>'
				. '<p class="dbp-actions"><a class="dbp-btn dbp-btn--primary" href="' . esc_url( $links['all'] ) . '">Browse all domains</a><a class="dbp-btn" href="' . esc_url( $links['finder'] ) . '">Use the domain finder</a><a class="dbp-btn" href="' . esc_url( $links['acquire'] ) . '">Ask us to find a name</a></p></section>';
		}
		$h .= '<div class="dbl-tools"><label class="dbl-search"><span class="screen-reader-text">Search this list</span><input type="search" id="dbl-q" placeholder="Search this list" autocomplete="off"></label>';
		if ( ! $sold ) {
			$h .= '<label class="dbl-sort"><span>Sort</span><select id="dbl-sort"><option value="name">Name (A to Z)</option><option value="low">Price: low to high</option><option value="high">Price: high to low</option></select></label>';
		}
		$h .= '<p class="dbl-count" id="dbl-count" aria-live="polite"></p></div><ul class="dbl-grid" id="dbl-grid">';
		foreach ( $rows as $r ) {
			$name  = (string) $r['name'];
			$dot   = strpos( $name, '.' );
			$label = false === $dot ? $name : substr( $name, 0, $dot );
			$ext   = false === $dot ? '' : substr( $name, $dot );
			$price = (float) $r['price'];
			$h    .= '<li class="dbl-card' . ( $sold ? ' dbl-card--sold' : '' ) . '" data-name="' . esc_attr( $name ) . '" data-price="' . esc_attr( (string) (int) round( $price ) ) . '">'
				. '<a class="dbl-link" href="' . esc_url( $r['url'] ) . '"><span class="dbl-name">' . esc_html( $label ) . '<span class="dbl-ext">' . esc_html( $ext ) . '</span></span>'
				. ( '' !== (string) $r['cat'] ? '<span class="dbl-cat">' . esc_html( $r['cat'] ) . '</span>' : '' );
			if ( $sold ) {
				$h .= '<span class="dbl-foot"><span class="dbl-badge">Sold</span></span>';
			} elseif ( $price > 0 ) {
				$h .= '<span class="dbl-foot"><span class="dbl-price">$' . esc_html( number_format( $price, 0 ) ) . ( $r['monthly'] > 0 ? '<small>or from $' . esc_html( number_format( (float) $r['monthly'], 0 ) ) . '/mo</small>' : '' ) . '</span><span class="dbl-go">Buy now</span></span>';
			} else {
				$h .= '<span class="dbl-foot"><span class="dbl-price dbl-price--offer">Open to offers</span><span class="dbl-go dbl-go--offer">Make an offer</span></span>';
			}
			$h .= '</a></li>';
		}
		$h .= '</ul><p class="dbl-none" id="dbl-none" hidden>No name in this list matches your search.</p>';
		$h .= '<script>(function(){var g=document.getElementById("dbl-grid");if(!g)return;var q=document.getElementById("dbl-q"),s=document.getElementById("dbl-sort"),c=document.getElementById("dbl-count"),none=document.getElementById("dbl-none"),cards=[].slice.call(g.children);'
			. 'function apply(){var t=(q.value||"").trim().toLowerCase(),n=0;cards.forEach(function(el){var ok=!t||el.getAttribute("data-name").indexOf(t)>-1;el.hidden=!ok;if(ok)n++;});none.hidden=n>0;c.textContent=t?n+" of "+cards.length+" shown":"";}'
			. 'function sort(){var v=s?s.value:"name";cards.sort(function(a,b){if(v==="name")return a.getAttribute("data-name").localeCompare(b.getAttribute("data-name"));var x=+a.getAttribute("data-price"),y=+b.getAttribute("data-price");if(!x&&y)return 1;if(x&&!y)return -1;return v==="low"?x-y:y-x;});cards.forEach(function(el){g.appendChild(el);});}'
			. 'q.addEventListener("input",apply);if(s)s.addEventListener("change",sort);})();</script>';
		return $h;
	}
}

if ( ! function_exists( 'db_pages_listing_rows' ) ) {
	/** Rows for one list, from the live inventory. */
	function db_pages_listing_rows( $slug ) {
		$sets = db_pages_listing_sets();
		$set  = $sets[ $slug ];
		$rows = array();
		if ( ! empty( $set['sold'] ) ) {
			$posts = function_exists( 'db_sold_listings' ) ? db_sold_listings() : array();
			foreach ( $posts as $p ) {
				if ( 'publish' !== $p->post_status ) {
					continue; // only names whose page is public
				}
				$rows[] = array( 'name' => strtolower( $p->post_title ), 'url' => get_permalink( $p ), 'price' => 0, 'monthly' => 0, 'cat' => '' );
			}
			return $rows;
		}
		$posts = function_exists( 'db_finder_candidates' ) ? db_finder_candidates() : array();
		foreach ( $posts as $p ) {
			if ( ! db_pages_listing_match( $set, $p->post_title ) ) {
				continue;
			}
			$price   = function_exists( 'db_listing_price' ) && function_exists( 'db_listing_state' ) && 'standard' === db_listing_state( $p ) ? db_listing_price( $p->ID ) : 0.0;
			$monthly = 0;
			if ( $price > 0 && function_exists( 'db_tools_pay_rows' ) ) {
				foreach ( (array) db_tools_pay_rows( $price ) as $row ) {
					$each = isset( $row['monthly'] ) ? (float) $row['monthly'] : 0;
					if ( ! empty( $row['months'] ) && $each > 0 && ( 0 === $monthly || $each < $monthly ) ) {
						$monthly = $each;
					}
				}
			}
			$terms = get_the_terms( $p->ID, 'domain_category' );
			$cats  = is_array( $terms ) ? array_values( array_diff( wp_list_pluck( $terms, 'name' ), array( 'Featured', 'Latest' ) ) ) : array();
			$rows[] = array( 'name' => strtolower( $p->post_title ), 'url' => get_permalink( $p ), 'price' => $price, 'monthly' => $monthly, 'cat' => $cats ? $cats[0] : '' );
		}
		return $rows;
	}
}

add_action( 'template_redirect', function () {
	if ( 'listing' !== db_pages_kind() ) {
		return;
	}
	if ( is_page( 'premium-domain-deals' ) ) {
		ob_start( function ( $html ) {
			return db_pages_deals_layout( $html, db_pages_listing_links() );
		} );
		return;
	}
	$slug  = (string) get_post_field( 'post_name', get_queried_object_id() );
	$sets  = db_pages_listing_sets();
	if ( ! isset( $sets[ $slug ] ) ) {
		return;
	}
	$links = db_pages_listing_links();
	$body = db_pages_listing_html( $slug, db_pages_listing_rows( $slug ), $links );
	get_header();
	echo '<div id="primary" class="content-area"><main id="main" class="site-main dbl-page">';
	echo '<nav class="db-breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li><li><a href="' . esc_url( $links['all'] ) . '">Domains</a></li><li><span aria-current="page">' . esc_html( $sets[ $slug ]['chip'] ) . '</span></li></ol></nav>';
	echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	echo '</main></div>';
	get_footer();
	exit;
}, 27 );

/* ------------------------------------------------------------ EPP guide */

if ( ! function_exists( 'db_pages_epp_layout' ) ) {
	/** Each "N. How to obtain ..." block becomes a collapsible section; the first is open. */
	function db_pages_epp_layout( $html, $title, $transfer_url ) {
		if ( ! preg_match( '#^(.*?<div class="epp-hlp">)(.*)(</div>\s*)$#s', $html, $w ) ) {
			return $html;
		}
		$parts = preg_split( '#<div class="qr_title">\s*<h4[^>]*>(.*?)</h4>\s*</div>#s', $w[2], -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( count( $parts ) < 3 ) {
			return $html;
		}
		$chips = '';
		$items = '';
		for ( $i = 1; $i < count( $parts ); $i += 2 ) {
			$n     = ( $i + 1 ) / 2;
			$head  = $parts[ $i ];
			$logo  = preg_match( '#<img[^>]*>#', $head, $im ) ? $im[0] : '';
			$text  = trim( preg_replace( '/^\d+\.\s*/', '', html_entity_decode( wp_strip_all_tags( $head ), ENT_QUOTES, 'UTF-8' ) ) );
			$short = false !== stripos( $text, 'general' ) ? 'Any registrar' : ( preg_match( '/from\s+(.+?)\??$/i', $text, $sm ) && strlen( $sm[1] ) <= 24 ? $sm[1] : 'Step ' . $n );
			$id    = 'epp-' . $n;
			$chips .= '<a href="#' . $id . '">' . esc_html( $short ) . '</a>';
			$items .= '<details class="dbp-faq dbe-item" id="' . $id . '"' . ( 1 === $n ? ' open' : '' ) . '><summary><span class="dbe-n">' . (int) $n . '</span><span class="dbe-q">' . esc_html( $text ) . '</span>' . ( $logo ? '<span class="dbe-logo">' . $logo . '</span>' : '' ) . '</summary><div class="dbp-faq-a">' . $parts[ $i + 1 ] . '</div></details>';
		}
		$hero = '<section class="dbp-hero dbe-hero"><div class="dbp-hero-copy"><p class="dbp-eyebrow">Transfers</p><h1 class="dbp-title">' . esc_html( $title ) . '</h1>'
			. '<p class="dbp-tagline">How to get the EPP (authorization) code from your registrar</p>'
			. '<div class="dbp-lead"><p>The EPP code proves you control a domain and lets it move to a new owner or registrar. Pick your registrar for the exact steps.</p></div>'
			. '<nav class="dbl-chips" aria-label="Registrars">' . $chips . '</nav>'
			. '<p class="dbp-actions"><a class="dbp-btn dbp-btn--primary" href="' . esc_url( $transfer_url ) . '">Read the transfer guide</a></p></div></section>';
		return $hero . '<div class="dbp-body dbe-body">' . $parts[0] . $items . '</div>';
	}
}

/* ---------------------------------------------------------------- forms */

if ( ! function_exists( 'db_pages_form_layout' ) ) {
	/** A form alone on a page gets a hero, three "what happens next" steps and a card. */
	function db_pages_form_layout( $html, $title, $lead, array $steps ) {
		if ( false === strpos( $html, 'wpcf7' ) ) {
			return $html;
		}
		$side = '<ol class="dbf-steps">';
		foreach ( $steps as $s ) {
			$side .= '<li><strong>' . esc_html( $s[0] ) . '</strong><span>' . esc_html( $s[1] ) . '</span></li>';
		}
		$side .= '</ol>';
		return '<section class="dbf-wrap"><div class="dbf-side"><p class="dbp-eyebrow">Domain Brothers</p><h1 class="dbp-title">' . esc_html( $title ) . '</h1><p class="dbf-lead">' . esc_html( $lead ) . '</p>' . $side . '</div><div class="dbf-card">' . $html . '</div></section>';
	}
}

add_filter( 'the_content', function ( $html ) {
	$kind = db_pages_kind();
	if ( ! in_array( $kind, array( 'epp', 'form' ), true ) || ! in_the_loop() || ! is_main_query() ) {
		return $html;
	}
	if ( 'epp' === $kind ) {
		return db_pages_epp_layout( $html, get_the_title(), home_url( '/domain-transfers-epp-codes-and-dns-explained/' ) );
	}
	if ( is_page( 'acquire-a-handle-now' ) ) {
		return db_pages_form_layout( $html, get_the_title(), 'Tell us the handle you want and your budget. We check who holds it and what it would take to get it.', array(
			array( 'You send the request', 'The handle, the platform and your budget.' ),
			array( 'We research it', 'Who holds the handle and whether it can be acquired.' ),
			array( 'You get an answer', 'A reply by email within one business day, with the next step.' ),
		) );
	}
	return $html;
}, 20 );

/* ---------------------------------------------------------- all domains */

if ( ! function_exists( 'db_pages_all_layout' ) ) {
	/** "Names — 206 Domains" becomes the shared list header with the count and the links between lists. */
	function db_pages_all_layout( $html, array $links ) {
		if ( ! preg_match( '#<h([12]) class="page-title all_domains">.*?<span>\s*(\d+)[^<]*</span>\s*</h\1>#s', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$n    = (int) $m[2][0];
		$head = '<section class="dbl-head"><p class="dbp-eyebrow">Marketplace</p><h1 class="dbl-title">Premium domains for sale</h1>'
			. '<p class="dbl-intro">Buy in full, pay monthly or make an offer. <strong>' . $n . ' ' . ( 1 === $n ? 'name' : 'names' ) . ' available.</strong></p>'
			. str_replace( '<a href="' . esc_url( $links['all'] ) . '">All domains</a>', '<a href="' . esc_url( $links['all'] ) . '" aria-current="page">All domains</a>', db_pages_listing_chips( '', $links ) ) . '</section>';
		return substr_replace( $html, $head, $m[0][1], strlen( $m[0][0] ) );
	}
}

add_action( 'template_redirect', function () {
	if ( 'all' !== db_pages_kind() ) {
		return;
	}
	ob_start( function ( $html ) {
		return db_pages_all_layout( $html, db_pages_listing_links() );
	} );
}, 27 );

/* ------------------------------------------------ blog post and news speed */

// The featured image of a post is the largest thing on screen: load it first.
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment ) {
	if ( is_admin() || ! is_singular( 'post' ) || (int) $attachment->ID !== (int) get_post_thumbnail_id( get_queried_object_id() ) ) {
		return $attr;
	}
	$attr['fetchpriority'] = 'high';
	$attr['loading']       = 'eager';
	return $attr;
}, 20, 2 );

add_filter( 'post_thumbnail_html', function ( $html ) {
	if ( is_admin() || ! is_singular( 'post' ) || ! in_the_loop() || false !== strpos( $html, 'fetchpriority' ) ) {
		return $html;
	}
	return preg_replace( '#<img\b#', '<img fetchpriority="high" loading="eager"', preg_replace( '#\sloading="lazy"#', '', $html ), 1 );
}, 20 );

add_action( 'wp_head', function () {
	if ( is_admin() || ! is_singular( 'post' ) ) {
		return;
	}
	$id = (int) get_post_thumbnail_id( get_queried_object_id() );
	if ( $id ) {
		$src    = wp_get_attachment_image_url( $id, 'post-thumbnail' );
		$srcset = wp_get_attachment_image_srcset( $id, 'post-thumbnail' );
		$sizes  = wp_get_attachment_image_sizes( $id, 'post-thumbnail' );
		if ( $src ) {
			echo '<link rel="preload" as="image" href="' . esc_url( $src ) . '"' . ( $srcset ? ' imagesrcset="' . esc_attr( $srcset ) . '" imagesizes="' . esc_attr( $sizes ? $sizes : '100vw' ) . '"' : '' ) . ' fetchpriority="high">' . "\n";
		}
	}
	// Tag links under a post sit on the dark footer strip (3.5:1 in the link teal).
	echo '<style id="db-post-tags">.single-post .entry-footer .tags-links a,.single-post .entry-footer .cat-links a{color:#8fe3dc!important}</style>' . "\n";
}, 6 );
