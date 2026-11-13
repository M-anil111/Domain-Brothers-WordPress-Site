<?php
/**
 * The FAQs page (/faqs/), built to the approved design.
 *
 * Layout, top to bottom: a light hero with the breadcrumb, the "Clear answers.
 * Confident decisions." heading, a question search and the 3D illustration;
 * a two-column body with a "Browse by topic" card and a "Need a little
 * guidance?" card on the left and the questions, grouped by topic, as an
 * accordion on the right; and a lime "Still have a question?" band. The theme's
 * header and footer are printed by get_header() / get_footer() and are not
 * touched.
 *
 * The questions live in db_faq_topics() so the page, the search and the
 * FAQPage structured data always show the same text. Filter `db_faq_topics`
 * to change them without editing this file.
 *
 * Loaded from db-custom-blocks.php. Template: db-faq-template.php.
 * Styles: assets/faq.css. Behaviour: assets/faq.js.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_faq_is_page' ) ) {
	/** True on the FAQs page (slug "faqs"; "faq" kept for an old slug). */
	function db_faq_is_page() {
		return ! is_admin() && is_page( array( 'faqs', 'faq' ) );
	}
}

if ( ! function_exists( 'db_faq_topics' ) ) {
	/**
	 * Topics and their questions, in page order.
	 *
	 * Each answer may contain simple inline HTML (links, <strong>); it is run
	 * through wp_kses_post() when printed and stripped of tags for the schema.
	 *
	 * @return array<int, array{id:string, label:string, icon:string, intro:string, items:array<int, array{id:string, q:string, a:string}>}>
	 */
	function db_faq_topics() {
		$contact = esc_url( home_url( '/contact-us/' ) );
		$topics  = array(
			array(
				'id'    => 'domain-basics',
				'label' => 'Domain basics',
				'icon'  => 'layers',
				'intro' => 'Start with the essentials.',
				'items' => array(
					array( 'id' => 'premium-domains', 'q' => 'What are premium domains?', 'a' => 'Premium domains are names offered for resale, often valued for their length, memorability or relevance to a business.' ),
					array( 'id' => 'transfer-registrar', 'q' => 'Can I transfer my domain to another registrar?', 'a' => 'Yes, domain names can be transferred between registrars. However, there are specific guidelines and timelines to follow, which can vary.' ),
					array( 'id' => 'dns-propagation', 'q' => 'What is DNS propagation?', 'a' => 'DNS propagation is the time it takes for changes to your domain&rsquo;s DNS settings to update across the internet. This can take from a few minutes up to 48 hours.' ),
					array( 'id' => 'country-code', 'q' => 'What is a country-code domain extension?', 'a' => 'A country-code top-level domain (ccTLD) is a domain extension that represents a specific country, like .uk for the United Kingdom or .ca for Canada.' ),
				),
			),
			array(
				'id'    => 'business-strategy',
				'label' => 'Business strategy',
				'icon'  => 'bars',
				'intro' => '',
				'items' => array(
					array( 'id' => 'competition', 'q' => 'How does a good domain name help with competition?', 'a' => 'Owning a strong domain name can give you a competitive edge. It helps you capture traffic that might otherwise go to competitors and makes sure customers find you first.' ),
					array( 'id' => 'another-domain', 'q' => 'I already have a good domain name. Why do I need another one?', 'a' => 'Owning more than one domain name can increase your online reach, help with SEO and protect your brand identity, among other benefits.' ),
					array( 'id' => 'hosting', 'q' => 'Do I need a new hosting account for the new domain?', 'a' => 'No. A new hosting account is not required. You can point the new domain to your existing hosting account.' ),
					array( 'id' => 'parked-domains', 'q' => 'What are parked domains?', 'a' => 'Parked domains are registered domains that are not currently connected to a website or service. They are often used to reserve a name for future use.' ),
				),
			),
			array(
				'id'    => 'buying-selling',
				'label' => 'Buying &amp; selling',
				'icon'  => 'tag',
				'intro' => '',
				'items' => array(
					array( 'id' => 'list-domain', 'q' => 'How do I list my domain for sale?', 'a' => 'Send us the domain name through our <a href="' . $contact . '">contact page</a>. We&rsquo;ll review your submission and get back to you shortly.' ),
					array( 'id' => 'payment-options', 'q' => 'What payment options are available?', 'a' => 'We take payments by card through Stripe, a secure third-party payment provider.' ),
					array( 'id' => 'transfer-time', 'q' => 'How long does a domain transfer take?', 'a' => 'Generally, a domain transfer takes anywhere from one day to a week, depending on the registrar and the domain extension.' ),
				),
			),
			array(
				'id'    => 'legal-security',
				'label' => 'Legal &amp; security',
				'icon'  => 'shield',
				'intro' => '',
				'items' => array(
					array( 'id' => 'trademark', 'q' => 'My domain and business names are trademarked. Is that enough?', 'a' => 'Trademarks offer some protection, but owning variations of your domain gives you an extra safeguard against brand confusion.' ),
					array( 'id' => 'udrp', 'q' => 'What is the UDRP?', 'a' => 'The UDRP is the Uniform Domain-Name Dispute-Resolution Policy, a process set up by ICANN for resolving domain name disputes.' ),
					array( 'id' => 'secure-transactions', 'q' => 'How secure are domain transactions?', 'a' => 'We confirm the payment and transfer steps with you in writing before any transfer begins.' ),
				),
			),
			array(
				'id'    => 'additional-services',
				'label' => 'Additional services',
				'icon'  => 'layers',
				'intro' => '',
				'items' => array(
					array( 'id' => 'design-marketing', 'q' => 'Do you offer website design and marketing?', 'a' => 'Yes. Besides domain brokerage we offer web design, digital marketing and software development. See <a href="' . esc_url( home_url( '/our-services/' ) ) . '">our services</a>.' ),
					array( 'id' => 'request-quote', 'q' => 'How can I request a quote?', 'a' => 'Email your requirements to <a href="mailto:sales@domainbrothers.com">sales@domainbrothers.com</a> and we&rsquo;ll send you a quote within 24 hours.' ),
				),
			),
			array(
				'id'    => 'other-questions',
				'label' => 'Other questions',
				'icon'  => 'chat',
				'intro' => '',
				'items' => array(
					array( 'id' => 'domain-privacy', 'q' => 'What is domain privacy?', 'a' => 'Domain privacy is a service that hides your personal information from public view in the WHOIS database and shows forwarding details instead.' ),
					array( 'id' => 'expired-domains', 'q' => 'What are expired domains?', 'a' => 'Expired domains are names whose registration has lapsed because it was not renewed. They may go through an auction or backorder process before they become available again.' ),
				),
			),
		);
		return (array) apply_filters( 'db_faq_topics', $topics );
	}
}

if ( ! function_exists( 'db_faq_icon' ) ) {
	/** Inline line icons (24px grid, currentColor). Decorative: aria-hidden. */
	function db_faq_icon( $name, $size = 24 ) {
		$paths = array(
			'layers' => '<path d="M12 3 3 7.5l9 4.5 9-4.5L12 3Z"/><path d="m3 12 9 4.5 9-4.5"/><path d="m3 16.5 9 4.5 9-4.5"/>',
			'bars'   => '<path d="M5 20v-4"/><path d="M10 20v-8"/><path d="M15 20V8"/><path d="M20 20V4"/>',
			'tag'    => '<path d="M3.5 12.6V5a1.5 1.5 0 0 1 1.5-1.5h7.6a1.5 1.5 0 0 1 1.06.44l6.9 6.9a1.5 1.5 0 0 1 0 2.12l-7.6 7.6a1.5 1.5 0 0 1-2.12 0l-6.9-6.9a1.5 1.5 0 0 1-.44-1.06Z"/><circle cx="8.5" cy="8.5" r="1.6"/>',
			'shield' => '<path d="M12 3 4.5 6v5.5c0 4.6 3.1 8.4 7.5 9.5 4.4-1.1 7.5-4.9 7.5-9.5V6L12 3Z"/>',
			'chat'   => '<path d="M12 4c4.97 0 9 3.36 9 7.5S16.97 19 12 19c-1.1 0-2.15-.16-3.12-.46L4 20.5l1.5-3.9C4.56 15.2 3 13.45 3 11.5 3 7.36 7.03 4 12 4Z"/>',
			'dots'   => '<path d="M12 4c4.97 0 9 3.36 9 7.5S16.97 19 12 19c-1.1 0-2.15-.16-3.12-.46L4 20.5l1.5-3.9C4.56 15.2 3 13.45 3 11.5 3 7.36 7.03 4 12 4Z"/><circle cx="8" cy="11.5" r=".9" fill="currentColor"/><circle cx="12" cy="11.5" r=".9" fill="currentColor"/><circle cx="16" cy="11.5" r=".9" fill="currentColor"/>',
			'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
			'arrow'  => '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		$size = (int) $size;
		return '<svg class="db-faq-svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}
}

if ( ! function_exists( 'db_faq_html' ) ) {
	/** The page body (hero, topics, questions, call to action). Returns a string. */
	function db_faq_html() {
		$topics  = db_faq_topics();
		$contact = home_url( '/contact-us/' );
		$assets  = plugin_dir_url( __FILE__ ) . 'assets/';
		$alt     = '3D illustration of a navy speech bubble with a lime question mark, a frosted chat bubble with three dots and a lime .com tile';

		$crumbs = '';
		if ( function_exists( 'db_breadcrumbs_trail' ) && function_exists( 'db_breadcrumbs_html' ) ) {
			$trail = db_breadcrumbs_trail();
			if ( is_array( $trail ) ) {
				$crumbs = db_breadcrumbs_html( $trail );
			}
		}
		if ( '' === $crumbs ) {
			$crumbs = '<nav class="db-breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li><li><span aria-current="page">FAQs</span></li></ol></nav>';
		}

		$h  = '<div class="db-faq">';

		// Hero.
		$h .= '<section class="db-faq-hero" aria-labelledby="db-faq-title"><div class="db-faq-wrap db-faq-hero-in">';
		$h .= '<div class="db-faq-hero-copy">' . $crumbs;
		$h .= '<h1 id="db-faq-title" class="db-faq-h1">Clear answers.<br> Confident decisions.</h1>';
		$h .= '<svg class="db-faq-swoosh" viewBox="0 0 170 14" width="170" height="14" aria-hidden="true" focusable="false"><path d="M3 10.5C42 4.2 104 2.4 167 5.6" fill="none" stroke="currentColor" stroke-width="5" stroke-linecap="round"/></svg>';
		$h .= '<p class="db-faq-lead">Everything you need to know about buying, selling and managing domains.</p>';
		$h .= '<form class="db-faq-search" role="search" action="#db-faq-topics-start" data-db-faq-search data-db-keep-search>';
		$h .= '<label class="db-faq-vh" for="db-faq-q">Search the FAQs</label>';
		$h .= '<span class="db-faq-search-ico">' . db_faq_icon( 'search', 22 ) . '</span>';
		$h .= '<input id="db-faq-q" class="db-faq-search-input" type="search" name="faq" placeholder="Search your question&hellip;" autocomplete="off" enterkeyhint="search" aria-describedby="db-faq-status">';
		$h .= '<button class="db-faq-search-btn" type="submit">Search</button>';
		$h .= '</form>';
		$h .= '</div>';
		$h .= '<div class="db-faq-hero-art"><img class="db-faq-hero-img" src="' . esc_url( $assets . 'faq-hero.webp' ) . '" srcset="' . esc_url( $assets . 'faq-hero-560.webp' ) . ' 560w, ' . esc_url( $assets . 'faq-hero.webp' ) . ' 1000w" sizes="(max-width: 600px) 82vw, (max-width: 991px) 440px, 520px" width="1000" height="833" alt="' . esc_attr( $alt ) . '" fetchpriority="high" decoding="async"></div>';
		$h .= '</div></section>';

		// Body.
		$h .= '<div class="db-faq-wrap db-faq-body" id="db-faq-topics-start">';
		$h .= '<aside class="db-faq-aside" aria-label="FAQ topics and help">';
		$h .= '<nav class="db-faq-card db-faq-topics" aria-labelledby="db-faq-topics-h"><h2 class="db-faq-topics-h" id="db-faq-topics-h">Browse by topic</h2><ul>';
		foreach ( $topics as $i => $t ) {
			$h .= '<li><a class="db-faq-topic' . ( 0 === $i ? ' is-active' : '' ) . '" href="#' . esc_attr( $t['id'] ) . '" data-db-faq-topic="' . esc_attr( $t['id'] ) . '"' . ( 0 === $i ? ' aria-current="true"' : '' ) . '>'
				. '<span class="db-faq-topic-ico">' . db_faq_icon( $t['icon'] ) . '</span><span>' . wp_kses( $t['label'], array() ) . '</span></a></li>';
		}
		$h .= '</ul></nav>';
		$h .= '<div class="db-faq-guide"><span class="db-faq-guide-ico">' . db_faq_icon( 'dots', 50 ) . '</span>';
		$h .= '<h2 class="db-faq-guide-h">Need a little guidance?</h2>';
		$h .= '<p>Can&rsquo;t find what you&rsquo;re looking for? Our team is happy to help.</p>';
		$h .= '<a class="db-faq-btn" href="' . esc_url( $contact ) . '">Contact our team ' . db_faq_icon( 'arrow', 18 ) . '</a></div>';
		$h .= '</aside>';

		$h .= '<div class="db-faq-main">';
		$h .= '<p id="db-faq-status" class="db-faq-status" role="status" aria-live="polite"></p>';
		$first = true;
		foreach ( $topics as $t ) {
			$tid = esc_attr( $t['id'] );
			$h  .= '<section class="db-faq-sec" id="' . $tid . '" aria-labelledby="' . $tid . '-h" data-db-faq-sec>';
			$h  .= '<h2 class="db-faq-h2" id="' . $tid . '-h">' . wp_kses( $t['label'], array() ) . '</h2>';
			if ( '' !== $t['intro'] ) {
				$h .= '<p class="db-faq-intro">' . esc_html( $t['intro'] ) . '</p>';
			}
			$h .= '<div class="db-faq-list">';
			foreach ( $t['items'] as $it ) {
				$qid  = 'faq-' . sanitize_title( $it['id'] );
				$open = $first;
				$h   .= '<div class="db-faq-item' . ( $open ? ' is-open' : '' ) . '" data-db-faq-item>';
				$h   .= '<h3 class="db-faq-q"><button type="button" id="' . esc_attr( $qid ) . '-q" aria-expanded="' . ( $open ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $qid ) . '">'
					. '<span class="db-faq-q-text">' . esc_html( $it['q'] ) . '</span><span class="db-faq-pm" aria-hidden="true"></span></button></h3>';
				$h   .= '<div class="db-faq-a" id="' . esc_attr( $qid ) . '" role="region" aria-labelledby="' . esc_attr( $qid ) . '-q"' . ( $open ? '' : ' hidden' ) . '><div class="db-faq-a-in"><p>' . wp_kses_post( $it['a'] ) . '</p></div></div>';
				$h   .= '</div>';
				$first = false;
			}
			$h .= '</div></section>';
		}
		$h .= '<div class="db-faq-empty" hidden data-db-faq-empty><p><strong>No questions match your search.</strong> Try another word, or <a href="' . esc_url( $contact ) . '">ask our team</a>.</p></div>';
		$h .= '</div>';
		$h .= '</div>';

		// Call to action.
		$h .= '<div class="db-faq-wrap"><section class="db-faq-cta" aria-labelledby="db-faq-cta-h">';
		$h .= '<span class="db-faq-cta-ico">' . db_faq_icon( 'dots', 56 ) . '</span>';
		$h .= '<div class="db-faq-cta-copy"><h2 class="db-faq-cta-h" id="db-faq-cta-h">Still have a question?</h2><p>Tell us what you need. We&rsquo;re here to help.</p></div>';
		$h .= '<a class="db-faq-btn db-faq-cta-btn" href="' . esc_url( $contact ) . '">Contact our team ' . db_faq_icon( 'arrow', 18 ) . '</a>';
		$h .= '</section></div>';

		$h .= '</div>';
		return $h;
	}
}

if ( ! function_exists( 'db_faq_schema' ) ) {
	/** FAQPage structured data built from the same questions the page shows. */
	function db_faq_schema() {
		$main = array();
		foreach ( db_faq_topics() as $t ) {
			foreach ( $t['items'] as $it ) {
				$main[] = array(
					'@type'          => 'Question',
					'name'           => wp_strip_all_tags( html_entity_decode( $it['q'], ENT_QUOTES, 'UTF-8' ) ),
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => wp_strip_all_tags( html_entity_decode( $it['a'], ENT_QUOTES, 'UTF-8' ) ),
					),
				);
			}
		}
		return array( '@type' => 'FAQPage', 'mainEntity' => $main );
	}
}

add_filter( 'template_include', function ( $template ) {
	if ( ! db_faq_is_page() || post_password_required() ) {
		return $template;
	}
	$file = __DIR__ . '/db-faq-template.php';
	return is_readable( $file ) ? $file : $template;
}, 99 );

add_filter( 'body_class', function ( $classes ) {
	if ( db_faq_is_page() ) {
		$classes[] = 'db-faq-page';
	}
	return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! db_faq_is_page() ) {
		return;
	}
	wp_enqueue_style( 'db-faq-font', 'https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap', array(), null );

	$file = __DIR__ . '/assets/faq.css';
	$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	if ( '' !== $css && ( ! function_exists( 'db_external_style' ) || ! db_external_style( 'faq', $css ) ) ) {
		add_action( 'wp_head', function () use ( $css ) {
			echo '<style id="db-faq-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- our own stylesheet.
		}, 130 );
	}

	$js = __DIR__ . '/assets/faq.js';
	if ( is_readable( $js ) ) {
		wp_enqueue_script( 'db-faq', plugin_dir_url( __FILE__ ) . 'assets/faq.js', array(), (string) filemtime( $js ), array( 'in_footer' => true, 'strategy' => 'defer' ) );
	}
}, 120 );

// Hero image: start the download with the HTML.
add_action( 'wp_head', function () {
	if ( ! db_faq_is_page() ) {
		return;
	}
	$assets = plugin_dir_url( __FILE__ ) . 'assets/';
	echo '<link rel="preload" as="image" href="' . esc_url( $assets . 'faq-hero.webp' ) . '" imagesrcset="' . esc_url( $assets . 'faq-hero-560.webp' ) . ' 560w, ' . esc_url( $assets . 'faq-hero.webp' ) . ' 1000w" imagesizes="(max-width: 600px) 82vw, (max-width: 991px) 440px, 520px" fetchpriority="high">' . "\n";
	// Without JavaScript every answer stays readable.
	echo '<noscript><style>.db-faq .db-faq-a.db-faq-a[hidden]{display:block!important}.db-faq .db-faq-pm.db-faq-pm{display:none!important}</style></noscript>' . "\n";
}, 2 );
