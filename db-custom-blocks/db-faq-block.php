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
 * The questions live in db_faq_topics() (filterable with db_faq_topics). The
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
		$email    = defined( 'DB_AEO_CONTACT_EMAIL' ) ? (string) DB_AEO_CONTACT_EMAIL : 'sales@domainbrothers.com';
		$mail     = '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
		$contact  = '<a href="' . esc_url( home_url( '/contact-us/' ) ) . '">contact our team</a>';
		$services = '<a href="' . esc_url( home_url( '/our-services/' ) ) . '">our services</a>';
		$browse   = '<a href="' . esc_url( home_url( '/all-domains/' ) ) . '">browse all domains</a>';
		$grace    = defined( 'DB_PLAN_GRACE_DAYS' ) ? (int) DB_PLAN_GRACE_DAYS : 10;

		$topics = array(
			array(
				'id'    => 'domain-basics',
				'title' => 'Domain basics',
				'intro' => 'Start with the essentials.',
				'icon'  => 'layers',
				'items' => array(
					array(
						'q' => 'What are premium domains?',
						'a' => 'Premium domains are names offered for resale, often valued for their length, memorability or relevance to a business.',
					),
					array(
						'q' => 'Can I transfer my domain to another registrar?',
						'a' => 'Yes. Once the domain is in your name you can move it to any registrar you prefer. The current registrar gives you an authorization (EPP) code and the domain must be unlocked. Most registrars also block a move for 60 days after a new registration or a change of owner.',
					),
					array(
						'q' => 'What is DNS propagation?',
						'a' => 'When you change a domain&rsquo;s DNS records, such as pointing it to a new website host or email provider, the update has to spread to servers around the world. That is DNS propagation. It usually takes a few hours and can take up to 48 hours, so some visitors may see the old site for a short while.',
					),
					array(
						'q' => 'What is a country-code domain extension?',
						'a' => 'A country-code extension (ccTLD) is a two-letter ending tied to a country or territory, such as .ca for Canada or .co.uk for the United Kingdom. Some have rules about who can own them; .ca, for example, requires a Canadian presence.',
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
						'q' => 'Why does a premium domain matter for my business?',
						'a' => 'A short, memorable name is easier for customers to remember, type and trust. It strengthens your brand, helps word of mouth, and keeps visitors from landing on a competitor&rsquo;s site by mistake.',
					),
					array(
						'q' => 'Should I choose a .com or a country-code domain?',
						'a' => 'A .com suits a business that sells internationally or wants the most widely recognized ending. A country-code domain, such as .ca, tells local customers you serve their market. Many businesses own both and point one to the other.',
					),
					array(
						'q' => 'Will a premium domain improve my search rankings?',
						'a' => 'A domain name alone does not guarantee rankings. What a strong name does is make your brand easier to recall and click, which brings more direct visits and returning customers over time.',
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
						'q' => 'How do I list my domain for sale?',
						'a' => 'Send us the domain name and your asking price: ' . $contact . ' or email ' . $mail . '. We will reply with the next steps.',
					),
					array(
						'q' => 'What payment options are available?',
						'a' => 'Pay in full by card (Visa, Mastercard, American Express) or a supported digital wallet through our secure Stripe checkout. You can also spread the cost over 3, 6, 9 or 12 monthly payments: the 3- and 6-month plans are interest-free, the 9-month plan carries 5% interest and the 12-month plan 10%.',
					),
					array(
						'q' => 'How long does a domain transfer take?',
						'a' => 'Most .com transfers complete within 5&ndash;7 days. Country-code domains can complete faster, sometimes within 24 hours.',
					),
					array(
						'q' => 'Can I make an offer on a domain?',
						'a' => 'Yes. Listings that accept offers show a Make an Offer option. We pass your offer on and reply by email. To find a name, ' . $browse . '.',
					),
					array(
						'q' => 'Can I use a domain while I am still paying for it?',
						'a' => 'Yes. During a payment plan we can point the domain&rsquo;s DNS records to your hosting and email so you can start using it straight away. Ownership transfers to you once the final payment clears.',
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
						'q' => 'Is it safe to buy a domain through Domain Brothers?',
						'a' => 'Yes. We use secure escrow and the standard registrar transfer process, and payments are processed by Stripe, which is PCI-DSS compliant.',
					),
					array(
						'q' => 'Who owns the domain during a payment plan?',
						'a' => 'The domain is held securely in escrow on your behalf until the final installment clears, then ownership transfers to you. If a payment stays unpaid ' . $grace . ' days after its due date, the plan ends and installments already paid are not refunded.',
					),
					array(
						'q' => 'Do you store my card details?',
						'a' => 'No. Card details are entered into Stripe&rsquo;s secure payment form and go straight to Stripe. They are never stored on our website.',
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
						'q' => 'Do you offer website design and marketing?',
						'a' => 'Yes. Through our agency partners we offer website design and development, digital marketing, software and mobile app development. See ' . $services . ' for details.',
					),
					array(
						'q' => 'How can I request a quote?',
						'a' => 'Tell us what you need through our contact page or email ' . $mail . '. We will reply with a scope and a quote.',
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
						'q' => 'What happens after I pay?',
						'a' => 'Your payment is processed securely, the domain is held and secured for you, and we send you instructions for the domain transfer by email.',
					),
					array(
						'q' => 'How do I contact Domain Brothers?',
						'a' => 'Email ' . $mail . ' or ' . $contact . ' through the contact page. We are happy to help.',
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
