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
		if ( is_admin() || ! is_page() ) {
			return '';
		}
		return is_page( db_pages_service_slugs() ) ? 'service' : '';
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
