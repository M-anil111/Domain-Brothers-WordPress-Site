<?php
/**
 * The FAQs page (/faqs/), laid out after the production FAQ page.
 *
 *   Banner      breadcrumb (Home / FAQs), "Clear answers. Confident
 *               decisions." with the lime underline, a question search and
 *               the speech-bubble illustration (assets/faq-hero.webp).
 *   Body        a "Browse by topic" card and a "Need a little guidance?"
 *               card on the left; one section per topic on the right, each
 *               question an accordion (one open at a time, + / − icons).
 *   CTA band    "Still have a question?" with a Contact our team button.
 *
 * The theme's header and footer are kept (get_header() / get_footer() in
 * db-faq-template.php). Every rule in assets/faq.css is scoped to #db-faq so
 * the theme and the other style layers cannot change the page, and the page
 * cannot change the header or footer.
 *
 * The questions (taken word for word from the live FAQs page) live in db_faq_topics() (filterable with db_faq_topics). The
 * same list feeds the FAQPage structured data, so the schema always matches
 * what the page shows.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_faq_is_page' ) ) {
	/** True on the FAQs page (slug "faqs", or "faq" on older installs). */
	function db_faq_is_page() {
		return is_page( array( 'faqs', 'faq' ) );
	}
}

if ( ! function_exists( 'db_faq_topics' ) ) {
	/**
	 * The FAQ content, grouped by topic, in display order.
	 *
	 * Answers may contain simple links and emphasis (run through wp_kses_post
	 * on output; stripped to text for the structured data).
	 *
	 * @return array<int, array{id:string, title:string, intro:string, icon:string, items:array<int, array{q:string, a:string}>}>
	 */
	function db_faq_topics() {
		// Questions and answers as published on www.domainbrothers.com/faqs/,
		// word for word, grouped into the page's six topics.
		$topics = array(
			array(
				'id'    => 'domain-basics',
				'title' => 'Domain basics',
				'intro' => 'Start with the essentials.',
				'icon'  => 'layers',
				'items' => array(
					array(
						'q' => 'What are premium domains?',
						'a' => 'A premium domain name is a domain that has already been registered but is still available for purchase. They are usually more expensive than standard domains due to their perceived value, keyword relevance, and potential for high website traffic.',
					),
					array(
						'q' => 'How does a good domain name help with competition?',
						'a' => 'Owning a strong domain name can give you a competitive edge. It helps you capture traffic that might otherwise go to competitors and ensures that customers find you first, enhancing your online visibility.',
					),
					array(
						'q' => 'Can I transfer my domain name to a different registrar?',
						'a' => 'Yes, domain names can be transferred between registrars. However, there are specific guidelines and timelines to follow, which can vary.',
					),
					array(
						'q' => 'What is DNS propagation?',
						'a' => 'DNS propagation refers to the time it takes for changes to your domain’s DNS settings to update across the Internet. This can take from a few minutes up to 48 hours.',
					),
				),
			),
			array(
				'id'    => 'business-strategy',
				'title' => 'Business strategy',
				'intro' => 'Choosing a name that works for your brand.',
				'icon'  => 'chart',
				'items' => array(
					array(
						'q' => 'I have a good domain name already, why do I need another one?',
						'a' => 'Owning multiple domain names can increase your online reach, help with SEO, and protect your brand identity, among other benefits.',
					),
					array(
						'q' => 'Do I need a new hosting account for the new domain?',
						'a' => 'No, a new hosting account is not required. You can point the new domain to your existing hosting account.',
					),
					array(
						'q' => 'What are parked domains?',
						'a' => 'Parked domains are registered domains not currently associated with a website or service. They are often used to reserve a domain name for future use.',
					),
				),
			),
			array(
				'id'    => 'buying-selling',
				'title' => 'Buying & selling',
				'intro' => 'How purchases, offers and payments work.',
				'icon'  => 'tag',
				'items' => array(
					array(
						'q' => 'How do I list my domains for sale with your company?',
						'a' => 'Visit our SELL page and complete the request form. We’ll review your submission and get back to you shortly.',
					),
					array(
						'q' => 'What sort of payment methods do you accept?',
						'a' => 'We primarily accept payments via Escrow for secure transactions.',
					),
					array(
						'q' => 'How long does the domain transfer take?',
						'a' => 'Generally, domain transfers can take anywhere from one day to a week, depending on several factors.',
					),
				),
			),
			array(
				'id'    => 'legal-security',
				'title' => 'Legal & security',
				'intro' => 'Keeping your purchase and your details safe.',
				'icon'  => 'shield',
				'items' => array(
					array(
						'q' => 'My domain and business names are trademarked, is that sufficient?',
						'a' => 'While trademarks offer some protection, owning variations of your domain can provide an additional safeguard against brand confusion.',
					),
					array(
						'q' => 'What is the UDRP?',
						'a' => 'The UDRP is the Uniform Domain-Name Dispute-Resolution Policy, a mechanism established by ICANN for resolving domain name disputes.',
					),
					array(
						'q' => 'How secure are domain transactions?',
						'a' => 'Transactions are made secure through the use of trusted third-party escrow services, which hold funds until the domain transfer is complete.',
					),
				),
			),
			array(
				'id'    => 'additional-services',
				'title' => 'Additional services',
				'intro' => 'Help beyond the domain itself.',
				'icon'  => 'layers',
				'items' => array(
					array(
						'q' => 'Do you provide services other than domain brokerage?',
						'a' => 'Absolutely, we offer a range of digital services like web design, marketing, and software development, among others.',
					),
					array(
						'q' => 'How do I get a quote for your other services?',
						'a' => 'Send us an email with your requirements, and we’ll provide you with a quote within 24 hours.',
					),
				),
			),
			array(
				'id'    => 'other-questions',
				'title' => 'Other questions',
				'intro' => 'Anything else you may want to know.',
				'icon'  => 'chat',
				'items' => array(
					array(
						'q' => 'What is a ccTLD?',
						'a' => 'A ccTLD (Country Code Top-Level Domain) is a domain extension representing a specific country, like .uk for the United Kingdom.',
					),
					array(
						'q' => 'What is domain privacy?',
						'a' => 'Domain privacy is a service that hides your personal information from public view in the WHOIS database, replacing it with forwarding service details.',
					),
					array(
						'q' => 'What are expired domains?',
						'a' => 'Expired domains are those whose registration has lapsed due to non-renewal. They may go through an auction or backorder process before becoming available again.',
					),
				),
			),
		);

		return (array) apply_filters( 'db_faq_topics', $topics );
	}
}

if ( ! function_exists( 'db_faq_icon' ) ) {
	/** Inline SVG for a topic or UI icon. Static markup only. */
	function db_faq_icon( $name ) {
		$a = 'xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"';
		switch ( $name ) {
			case 'layers':
				return '<svg ' . $a . '><path d="M12 3 2.5 8 12 13l9.5-5z"/><path d="m2.5 12 9.5 5 9.5-5"/><path d="m2.5 16 9.5 5 9.5-5"/></svg>';
			case 'chart':
				return '<svg ' . $a . '><path d="M5 20v-5M10 20v-9M15 20v-12M20 20V4"/></svg>';
			case 'tag':
				return '<svg ' . $a . '><path d="M3 12V4.5A1.5 1.5 0 0 1 4.5 3H12l9 9-9 9z"/><circle cx="8" cy="8" r="1.6"/></svg>';
			case 'shield':
				return '<svg ' . $a . '><path d="M12 3 4.5 6v5.5c0 4.6 3.2 8.4 7.5 9.5 4.3-1.1 7.5-4.9 7.5-9.5V6z"/></svg>';
			case 'chat':
				return '<svg ' . $a . '><path d="M12 4c4.97 0 9 3.13 9 7s-4.03 7-9 7c-1.06 0-2.08-.14-3.02-.4L4 19.5l1.4-3.9C4.2 14.4 3 12.8 3 11c0-3.87 4.03-7 9-7z"/></svg>';
			case 'chat-dots':
				return '<svg ' . $a . '><path d="M12 4c4.97 0 9 3.13 9 7s-4.03 7-9 7c-1.06 0-2.08-.14-3.02-.4L4 19.5l1.4-3.9C4.2 14.4 3 12.8 3 11c0-3.87 4.03-7 9-7z"/><circle cx="8.2" cy="11" r=".9" fill="currentColor"/><circle cx="12" cy="11" r=".9" fill="currentColor"/><circle cx="15.8" cy="11" r=".9" fill="currentColor"/></svg>';
			case 'search':
				return '<svg ' . $a . ' stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>';
			case 'arrow':
				return '<svg ' . $a . ' stroke-width="2.2"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
		}
		return '';
	}
}

if ( ! function_exists( 'db_faq_html' ) ) {
	/** The page body. Strings only (no output buffering). */
	function db_faq_html() {
		$topics  = db_faq_topics();
		$contact = esc_url( home_url( '/contact-us/' ) );
		$hero    = plugins_url( 'assets/faq-hero.webp', __FILE__ );
		$hero_sm = plugins_url( 'assets/faq-hero-768.webp', __FILE__ );
		$alt     = 'Navy speech bubble with a lime green question mark, a frosted chat bubble with three dots, and a lime .com tile';

		$h  = '<div id="db-faq" class="db-faq">';

		// ---- Banner ----
		$h .= '<section class="dbf-hero" aria-labelledby="dbf-title"><div class="dbf-wrap dbf-hero-in">';
		$h .= '<div class="dbf-hero-text">';
		$h .= '<nav class="db-breadcrumbs dbf-crumbs" aria-label="Breadcrumb"><ol>';
		$h .= '<li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li>';
		$h .= '<li><span aria-current="page">FAQs</span></li>';
		$h .= '</ol></nav>';
		$h .= '<h1 id="dbf-title" class="dbf-title">Clear answers.<br>Confident decisions.</h1>';
		$h .= '<svg class="dbf-swash" viewBox="0 0 124 10" aria-hidden="true" focusable="false"><path d="M2 7.5C30 3 72 1.8 122 4.6" fill="none" stroke="currentColor" stroke-width="3.6" stroke-linecap="round"/></svg>';
		$h .= '<p class="dbf-lead">Everything you need to know about buying, selling and managing domains.</p>';
		$h .= '<form class="dbf-search" role="search" action="#dbf-topics" novalidate>';
		$h .= '<label class="dbf-sr" for="dbf-q">Search the FAQs</label>';
		$h .= '<span class="dbf-search-ico">' . db_faq_icon( 'search' ) . '</span>';
		$h .= '<input id="dbf-q" class="dbf-search-input" type="search" name="faq" placeholder="Search your question..." autocomplete="off" aria-describedby="dbf-status">';
		$h .= '<button type="submit" class="dbf-search-btn">Search</button>';
		$h .= '</form>';
		$h .= '</div>';
		$h .= '<div class="dbf-hero-art">';
		$h .= '<img src="' . esc_url( $hero ) . '" srcset="' . esc_url( $hero_sm ) . ' 768w, ' . esc_url( $hero ) . ' 1536w" sizes="(max-width: 860px) 80vw, 520px" width="1536" height="1024" alt="' . esc_attr( $alt ) . '" fetchpriority="high" decoding="async">';
		$h .= '</div>';
		$h .= '</div></section>';

		// ---- Body ----
		$h .= '<div class="dbf-wrap dbf-body">';

		$h .= '<aside class="dbf-side">';
		$h .= '<nav class="dbf-topics" id="dbf-topics" aria-labelledby="dbf-topics-h"><h2 id="dbf-topics-h" class="dbf-topics-h">Browse by topic</h2><ul>';
		foreach ( $topics as $i => $t ) {
			$h .= '<li><a class="dbf-topic' . ( 0 === $i ? ' is-active' : '' ) . '" href="#' . esc_attr( $t['id'] ) . '"' . ( 0 === $i ? ' aria-current="true"' : '' ) . '>'
				. '<span class="dbf-topic-ico">' . db_faq_icon( $t['icon'] ) . '</span><span>' . esc_html( $t['title'] ) . '</span></a></li>';
		}
		$h .= '</ul></nav>';
		$h .= '<div class="dbf-help dbf-help-side">';
		$h .= '<span class="dbf-help-ico">' . db_faq_icon( 'chat-dots' ) . '</span>';
		$h .= '<h2 class="dbf-help-h">Need a little guidance?</h2>';
		$h .= '<p>Can&rsquo;t find what you&rsquo;re looking for? Our team is happy to help.</p>';
		$h .= '<a class="dbf-btn" href="' . $contact . '">Contact our team ' . db_faq_icon( 'arrow' ) . '</a>';
		$h .= '</div>';
		$h .= '</aside>';

		$h .= '<div class="dbf-main">';
		$h .= '<p id="dbf-status" class="dbf-status" role="status" aria-live="polite"></p>';
		$first = true;
		foreach ( $topics as $t ) {
			$h .= '<section class="dbf-sec" id="' . esc_attr( $t['id'] ) . '" aria-labelledby="' . esc_attr( $t['id'] ) . '-h">';
			$h .= '<h2 class="dbf-sec-h" id="' . esc_attr( $t['id'] ) . '-h">' . esc_html( $t['title'] ) . '</h2>';
			if ( '' !== $t['intro'] ) {
				$h .= '<p class="dbf-sec-intro">' . esc_html( $t['intro'] ) . '</p>';
			}
			$h .= '<div class="dbf-list">';
			foreach ( $t['items'] as $j => $item ) {
				$id   = $t['id'] . '-' . ( $j + 1 );
				$open = $first;
				$first = false;
				$h .= '<div class="dbf-item' . ( $open ? ' is-open' : '' ) . '">';
				$h .= '<h3 class="dbf-q"><button type="button" class="dbf-q-btn" id="' . esc_attr( $id ) . '-q" aria-expanded="' . ( $open ? 'true' : 'false' ) . '" aria-controls="' . esc_attr( $id ) . '-a">'
					. '<span class="dbf-q-text">' . esc_html( $item['q'] ) . '</span><span class="dbf-q-ico" aria-hidden="true"></span></button></h3>';
				$h .= '<div class="dbf-a" id="' . esc_attr( $id ) . '-a" role="region" aria-labelledby="' . esc_attr( $id ) . '-q"' . ( $open ? '' : ' hidden' ) . '>'
					. '<div class="dbf-a-in"><p>' . wp_kses_post( $item['a'] ) . '</p></div></div>';
				$h .= '</div>';
			}
			$h .= '</div></section>';
		}
		$h .= '<div class="dbf-empty" hidden><p>No questions match your search. Try other words, or <a href="' . $contact . '">contact our team</a>.</p></div>';
		$h .= '</div>';

		$h .= '</div>';

		// ---- CTA band ----
		$h .= '<div class="dbf-wrap"><section class="dbf-cta" aria-labelledby="dbf-cta-h">';
		$h .= '<span class="dbf-cta-ico">' . db_faq_icon( 'chat-dots' ) . '</span>';
		$h .= '<div class="dbf-cta-text"><h2 id="dbf-cta-h">Still have a question?</h2><p>Tell us what you need. We&rsquo;re here to help.</p></div>';
		$h .= '<a class="dbf-btn dbf-cta-btn" href="' . $contact . '">Contact our team ' . db_faq_icon( 'arrow' ) . '</a>';
		$h .= '</section></div>';

		$h .= '</div>';
		return $h;
	}
}

/* ---- Page wiring --------------------------------------------------------- */

add_filter( 'template_include', function ( $template ) {
	$file = __DIR__ . '/db-faq-template.php';
	if ( db_faq_is_page() && ! post_password_required() && is_readable( $file ) ) {
		return $file;
	}
	return $template;
}, 99 );

add_filter( 'body_class', function ( $classes ) {
	if ( db_faq_is_page() ) {
		$classes[] = 'db-faq-page';
	}
	return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() || ! db_faq_is_page() ) {
		return;
	}
	wp_enqueue_style( 'db-faq-font', 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	$file = __DIR__ . '/assets/faq.css';
	$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
	if ( '' === $css ) {
		return;
	}
	if ( ! function_exists( 'db_external_style' ) || ! db_external_style( 'faq', $css ) ) {
		add_action( 'wp_head', function () use ( $css ) {
			echo '<style id="db-faq-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}, 130 );
	}
	$js = __DIR__ . '/assets/faq.js';
	if ( is_readable( $js ) ) {
		wp_enqueue_script( 'db-faq', plugins_url( 'assets/faq.js', __FILE__ ), array(), (string) filemtime( $js ), true );
	}
}, 130 );

// The hero illustration is the page's largest image: fetch it early.
add_action( 'wp_head', function () {
	if ( is_admin() || ! db_faq_is_page() ) {
		return;
	}
	echo '<link rel="preload" as="image" href="' . esc_url( plugins_url( 'assets/faq-hero.webp', __FILE__ ) ) . '" imagesrcset="' . esc_url( plugins_url( 'assets/faq-hero-768.webp', __FILE__ ) ) . ' 768w, ' . esc_url( plugins_url( 'assets/faq-hero.webp', __FILE__ ) ) . ' 1536w" imagesizes="(max-width: 860px) 80vw, 520px" fetchpriority="high">' . "\n";
}, 2 );

if ( ! function_exists( 'db_faq_schema' ) ) {
	/** FAQPage structured data built from exactly the questions on the page. */
	function db_faq_schema() {
		$entities = array();
		foreach ( db_faq_topics() as $t ) {
			foreach ( $t['items'] as $item ) {
				$entities[] = array(
					'@type'          => 'Question',
					'name'           => wp_strip_all_tags( html_entity_decode( $item['q'], ENT_QUOTES, 'UTF-8' ) ),
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( $item['a'], ENT_QUOTES, 'UTF-8' ) ) ) ),
					),
				);
			}
		}
		return array( '@type' => 'FAQPage', 'mainEntity' => $entities );
	}
}
