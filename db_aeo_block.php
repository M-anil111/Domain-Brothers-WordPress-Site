<?php
/* === DB AEO & structured data ===
 *
 * Answer Engine Optimisation (AEO) + traditional SEO structured data.
 *
 * AEO targets AI-powered search (ChatGPT, Perplexity, Bing Copilot, Google AI
 * Overviews) by providing machine-readable, authoritative answers.  Traditional
 * SEO benefits include rich results (FAQ dropdowns, sitelinks search box,
 * breadcrumbs) in Google / Bing SERPs.
 *
 * SCHEMAS EMITTED
 *   All pages  — Organization, WebSite (with SearchAction)
 *   Front page — FAQPage (common domain-buyer questions)
 *   Domain CPT — Product (name + pricing offer)
 *   Service pages — Service
 *   Inner pages — BreadcrumbList
 *
 * OPEN GRAPH / SOCIAL TAGS (when RankMath is NOT active)
 *   og:type, og:title, og:description, og:url, og:image, og:site_name
 *   twitter:card, twitter:title, twitter:description
 *
 * CONFIG CONSTANTS (define before pasting, or let the defaults apply)
 *   DB_AEO_ORG_NAME        — legal organisation name. Default: site name.
 *   DB_AEO_CONTACT_EMAIL   — public contact email.   Default: sales@domainbrothers.com
 *   DB_AEO_TWITTER_HANDLE  — @handle without @.       Default: '' (tag omitted)
 *   DB_AEO_OG_IMAGE        — absolute URL to default OG image (1200×630).
 *                            Default: site icon if set, otherwise omitted.
 *   DB_AEO_PRICE_META      — post meta key holding the domain price.
 *                            Default: 'domain_price' (matches DomainFolio theme).
 *
 * Defers to RankMath for OG tags when RankMath is active (no duplicate tags).
 * JSON-LD is always emitted — RankMath's JSON-LD and ours co-exist fine because
 * they use different @types.
 *
 * Paste at the end of functions.php. Self-contained.
 */

if ( ! defined( 'DB_AEO_ORG_NAME' ) )       define( 'DB_AEO_ORG_NAME',       get_bloginfo( 'name' ) );
if ( ! defined( 'DB_AEO_CONTACT_EMAIL' ) )   define( 'DB_AEO_CONTACT_EMAIL',  'sales@domainbrothers.com' );
if ( ! defined( 'DB_AEO_TWITTER_HANDLE' ) )  define( 'DB_AEO_TWITTER_HANDLE', '' );
if ( ! defined( 'DB_AEO_PRICE_META' ) )      define( 'DB_AEO_PRICE_META',     'domain_price' );
if ( ! defined( 'DB_AEO_OG_IMAGE' ) ) {
	$_db_site_icon = get_site_icon_url( 512 );
	define( 'DB_AEO_OG_IMAGE', $_db_site_icon ?: '' );
	unset( $_db_site_icon );
}

/* ---- Helper: emit a JSON-LD <script> block ---- */
function db_aeo_emit( $graph_node ) {
	$json = wp_json_encode( array_merge( array( '@context' => 'https://schema.org' ), $graph_node ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
	echo '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
}

/* ---- Helper: current page canonical URL ---- */
function db_aeo_canonical() {
	global $wp;
	return esc_url( home_url( add_query_arg( array(), $wp->request ) ) );
}

/* ---- Main hook: output all JSON-LD on wp_head ---- */
add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}

	$site_url  = esc_url( home_url( '/' ) );
	$site_name = DB_AEO_ORG_NAME;
	$logo_url  = DB_AEO_OG_IMAGE;

	/* 1. Organization — appears on every page */
	$org = array(
		'@type'        => 'Organization',
		'name'         => $site_name,
		'url'          => $site_url,
		'description'  => 'Premium domain name brokerage offering expert acquisition, flexible payment plans, and secure escrow services.',
		'contactPoint' => array(
			'@type'       => 'ContactPoint',
			'contactType' => 'sales',
			'email'       => DB_AEO_CONTACT_EMAIL,
			'availableLanguage' => 'English',
		),
	);
	if ( $logo_url ) {
		$org['logo'] = array(
			'@type' => 'ImageObject',
			'url'   => $logo_url,
		);
	}
	db_aeo_emit( $org );

	/* 2. WebSite + SearchAction — enables sitelinks search box in Google */
	db_aeo_emit( array(
		'@type' => 'WebSite',
		'name'  => $site_name,
		'url'   => $site_url,
		'potentialAction' => array(
			'@type'       => 'SearchAction',
			'target'      => array(
				'@type'       => 'EntryPoint',
				'urlTemplate' => $site_url . '?s={search_term_string}',
			),
			'query-input' => 'required name=search_term_string',
		),
	) );

	/* 3. FAQPage — front page and any page with slug "faq" */
	if ( is_front_page() || is_page( 'faq' ) ) {
		db_aeo_emit( array(
			'@type'            => 'FAQPage',
			'mainEntity'       => array(
				array(
					'@type'          => 'Question',
					'name'           => 'What is domain brokerage?',
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => 'Domain brokerage is a service where an expert negotiates and acquires a premium domain name on your behalf. The broker handles outreach to the current owner, price negotiation, secure transfer, and escrow — so you get the domain safely without the hassle of tracking down an owner yourself.',
					),
				),
				array(
					'@type'          => 'Question',
					'name'           => 'How do payment plans work?',
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => 'Domain Brothers offers 0% interest instalment plans over 3, 6, 9, or 12 months. The total price is divided into equal monthly payments — the final payment absorbs any rounding difference so the instalments always sum to the exact agreed price. The first payment is made today; each subsequent payment falls on the same calendar date each month.',
					),
				),
				array(
					'@type'          => 'Question',
					'name'           => 'Can I use a domain while I am still paying for it?',
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => 'Yes. During the payment plan we can point the domain\'s DNS records (A record for your hosting IP and MX records for your email) to your servers so you can start using the domain immediately. Legal ownership transfers to you once the final payment clears.',
					),
				),
				array(
					'@type'          => 'Question',
					'name'           => 'How long does a domain transfer take?',
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => 'Most .com domain transfers complete within 5–7 days. The process involves: the current registrar releasing the domain (1–2 days), ICANN\'s 5-day transfer period, and the gaining registrar confirming the transfer. Country-code domains (e.g. .co.uk) can complete faster — sometimes within 24 hours.',
					),
				),
				array(
					'@type'          => 'Question',
					'name'           => 'Is it safe to buy a domain through a broker?',
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => 'Yes. Domain Brothers uses secure escrow services and established registrar transfer protocols. For one-time purchases the payment is processed through Stripe with full PCI-DSS compliance. For payment plans, the domain is held securely in escrow until the final payment, and DNS access is provided immediately so you can start using the domain.',
					),
				),
				array(
					'@type'          => 'Question',
					'name'           => 'What is a premium domain name?',
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => 'A premium domain name is a short, memorable, and often generic name — like "loans.com" or "cars.com" — that was registered years ago and carries significant brand equity, direct search traffic, and SEO authority. Premium domains are sold on the aftermarket (i.e. they already have an owner) and typically cost more than a new registration.',
					),
				),
				array(
					'@type'          => 'Question',
					'name'           => 'Do you offer escrow services?',
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => 'Yes. All domain transactions include secure escrow handling. For payment-plan purchases, the domain is held securely on your behalf between the first payment and the final payment, ensuring neither party can walk away once the agreement begins.',
					),
				),
				array(
					'@type'          => 'Question',
					'name'           => 'What payment methods do you accept?',
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => 'We accept all major credit and debit cards (Visa, Mastercard, American Express) as well as Apple Pay and Google Pay through our Stripe-powered secure checkout. Payment plans are charged automatically to the same card on the same date each month.',
					),
				),
			),
		) );
	}

	/* 4. Product schema — individual domain listing pages */
	if ( is_singular( 'domain' ) ) {
		global $post;
		$domain_name  = get_the_title( $post );
		$domain_price = get_post_meta( $post->ID, DB_AEO_PRICE_META, true );
		$domain_url   = get_permalink( $post );

		$product = array(
			'@type'       => 'Product',
			'name'        => $domain_name,
			'description' => 'Premium domain name ' . esc_html( $domain_name ) . ' available for purchase or via flexible monthly payment plan.',
			'url'         => esc_url( $domain_url ),
			'brand'       => array(
				'@type' => 'Brand',
				'name'  => $site_name,
			),
		);

		if ( $domain_price && is_numeric( preg_replace( '/[^0-9.]/', '', $domain_price ) ) ) {
			$price = (float) preg_replace( '/[^0-9.]/', '', $domain_price );
			$product['offers'] = array(
				'@type'           => 'Offer',
				'price'           => number_format( $price, 2, '.', '' ),
				'priceCurrency'   => 'USD',
				'availability'    => 'https://schema.org/InStock',
				'url'             => esc_url( $domain_url ),
				'seller'          => array(
					'@type' => 'Organization',
					'name'  => $site_name,
				),
			);
		}

		db_aeo_emit( $product );
	}

	/* 5. Service schema — service sub-pages */
	$service_slugs = array(
		'website-design-development' => 'Website Design & Development',
		'digital-marketing'          => 'Digital Marketing',
		'software-development'       => 'Software Development',
		'mobile-app-development'     => 'Mobile App Development',
		'other-services'             => 'Other Services',
	);
	if ( is_page( array_keys( $service_slugs ) ) ) {
		global $post;
		$slug         = $post->post_name;
		$service_name = isset( $service_slugs[ $slug ] ) ? $service_slugs[ $slug ] : get_the_title();
		db_aeo_emit( array(
			'@type'       => 'Service',
			'name'        => $service_name,
			'provider'    => array( '@type' => 'Organization', 'name' => $site_name, 'url' => $site_url ),
			'url'         => esc_url( get_permalink() ),
			'areaServed'  => 'Worldwide',
			'description' => get_the_excerpt(),
		) );
	}

	/* 6. BreadcrumbList — all non-front-page pages */
	if ( ! is_front_page() && ( is_page() || is_singular() || is_category() ) ) {
		$items = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => 'Home',
				'item'     => $site_url,
			),
		);

		if ( is_singular( 'domain' ) ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => 2,
				'name'     => 'Domain Listings',
				'item'     => esc_url( home_url( '/domains/' ) ),
			);
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => 3,
				'name'     => get_the_title(),
				'item'     => esc_url( get_permalink() ),
			);
		} elseif ( is_page() ) {
			global $post;
			if ( $post->post_parent ) {
				$parent = get_post( $post->post_parent );
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => get_the_title( $parent ),
					'item'     => esc_url( get_permalink( $parent ) ),
				);
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => 3,
					'name'     => get_the_title(),
					'item'     => esc_url( get_permalink() ),
				);
			} else {
				$items[] = array(
					'@type'    => 'ListItem',
					'position' => 2,
					'name'     => get_the_title(),
					'item'     => esc_url( get_permalink() ),
				);
			}
		}

		if ( count( $items ) > 1 ) {
			db_aeo_emit( array(
				'@type'           => 'BreadcrumbList',
				'itemListElement' => $items,
			) );
		}
	}

}, 5 ); // priority 5 — before most theme output, after wp_head init

/* ---- Open Graph + Twitter Card meta tags ---- */
// Only emitted when RankMath is NOT active (to avoid duplicate og: tags).
add_action( 'wp_head', function () {
	if ( is_admin() || class_exists( 'RankMath' ) ) {
		return; // RankMath handles OG tags itself
	}

	$title       = wp_get_document_title();
	$description = get_bloginfo( 'description' );
	$url         = db_aeo_canonical();
	$image       = DB_AEO_OG_IMAGE;
	$type        = 'website';
	$site_name   = DB_AEO_ORG_NAME;

	// Per-page overrides
	if ( is_singular() ) {
		$type = is_singular( 'domain' ) ? 'product' : 'article';
		if ( has_post_thumbnail() ) {
			$img   = wp_get_attachment_image_src( get_post_thumbnail_id(), 'large' );
			$image = $img ? $img[0] : $image;
		}
		$description = has_excerpt() ? get_the_excerpt() : $description;
		$url         = esc_url( get_permalink() );
	}

	echo '<meta property="og:type"        content="' . esc_attr( $type )        . '">' . "\n";
	echo '<meta property="og:site_name"   content="' . esc_attr( $site_name )   . '">' . "\n";
	echo '<meta property="og:title"       content="' . esc_attr( $title )       . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	echo '<meta property="og:url"         content="' . esc_url( $url )          . '">' . "\n";
	if ( $image ) {
		echo '<meta property="og:image"   content="' . esc_url( $image )        . '">' . "\n";
	}

	// Twitter Card
	echo '<meta name="twitter:card"        content="summary_large_image">'       . "\n";
	echo '<meta name="twitter:title"       content="' . esc_attr( $title )       . '">' . "\n";
	echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
	if ( $image ) {
		echo '<meta name="twitter:image"  content="' . esc_url( $image )         . '">' . "\n";
	}
	if ( DB_AEO_TWITTER_HANDLE ) {
		echo '<meta name="twitter:site"   content="@' . esc_attr( DB_AEO_TWITTER_HANDLE ) . '">' . "\n";
	}

	// Canonical (only when RankMath is not active)
	echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";

}, 5 );
/* === end DB AEO & structured data === */
