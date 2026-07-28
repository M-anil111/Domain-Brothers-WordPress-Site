<?php
/**
 * DB SEO Meta Block — per-page titles, descriptions, canonicals & robots
 *
 * Context: the site has Yoast installed but it is NOT emitting any front-end
 * meta (no Yoast head block, no generator tag, and no
 * <meta name="description"> on any page). So this block ACTIVELY outputs SEO
 * tags itself, and is the single owner of them:
 *
 *   - Keyword-optimized <title> via the document-title filters
 *   - A unique <meta name="description"> on EVERY indexable URL. A hand
 *     written definition where one exists, otherwise one derived from the
 *     page's own excerpt or content, so nothing ever ships without one.
 *   - Dynamic title + description for individual `domain` listings
 *   - noindex on transactional and utility endpoints that must not rank
 *   - A <head> deduper that removes the duplicate <title> and duplicate
 *     <link rel="canonical"> tags the DomainFolio theme hardcodes into some
 *     of its templates
 *
 * It steps aside only for RankMath, which fully owns <head> when active.
 * If Yoast is ever reconfigured to emit, define DB_SEO_ACTIVE as false to
 * disable this block's direct output.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_SEO_ACTIVE' ) ) {
	// Only RankMath fully renders <head> meta; if it's present, stand down.
	define( 'DB_SEO_ACTIVE', ! ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) );
}

if ( ! defined( 'DB_SEO_SUFFIX' ) ) {
	define( 'DB_SEO_SUFFIX', ' | Domain Brothers' );
}

/* ─── Per-page SEO definitions ─────────────────────────────────────────────── */

if ( ! function_exists( 'db_seo_defs' ) ) {
	/**
	 * SEO copy keyed by page slug. Titles ~50-60 chars, descriptions
	 * 140-158 chars, each built around a buy-intent focus keyword.
	 */
	function db_seo_defs() {
		$defs = array(
			'_front' => array(
				'title' => 'Buy Premium Domains, Escrow Protected | Domain Brothers',
				'desc'  => 'Browse premium domains for sale with escrow-protected transfers and 0% interest payment plans. Find the perfect domain for your brand and buy it today.',
				'kw'    => 'buy premium domains',
			),
			'website-design-development' => array(
				'title' => 'Website Design & Development Services | Domain Brothers',
				'desc'  => 'Professional website design and development that turns visitors into customers. Custom, responsive, fast. Get a free quote from Domain Brothers today.',
				'kw'    => 'website design services',
			),
			'digital-marketing' => array(
				'title' => 'Digital Marketing Services That Grow Sales | Domain Brothers',
				'desc'  => 'Data-driven digital marketing, SEO, PPC and social that grow traffic, leads and revenue. Book a free strategy call with Domain Brothers today.',
				'kw'    => 'digital marketing services',
			),
			'software-development' => array(
				'title' => 'Custom Software Development | Domain Brothers',
				'desc'  => 'Custom software development built around your business, scalable and secure apps from a senior team. Book a free consultation with Domain Brothers today.',
				'kw'    => 'custom software development',
			),
			'mobile-app-development' => array(
				'title' => 'Mobile App Development for iOS & Android | Domain Brothers',
				'desc'  => 'Expert mobile app development for iOS and Android, fast, beautiful, high-performing apps users love. Get a free project quote from Domain Brothers.',
				'kw'    => 'mobile app development',
			),
			'other-services' => array(
				'title' => 'Web Development & Digital Services | Domain Brothers',
				'desc'  => 'Full-stack web development and digital services, from integrations to complex platforms. Reliable, modern builds. Request your free quote today.',
				'kw'    => 'web development services',
			),
			'news' => array(
				'title' => 'Domain Industry News & Market Insights | Domain Brothers',
				'desc'  => 'The latest domain industry news, market trends and premium domain insights from the Domain Brothers marketplace. Stay ahead of the market.',
				'kw'    => 'domain industry news',
			),
		);

		$defs = array_merge( $defs, db_seo_page_defs() );

		return apply_filters( 'db_seo_defs', $defs );
	}
}

if ( ! function_exists( 'db_seo_page_defs' ) ) {
	/**
	 * Copy for the rest of the published pages. Kept in its own function so
	 * the block above stays readable; merged into db_seo_defs().
	 */
	function db_seo_page_defs() {
		return array(
			'all-domains' => array(
				'title' => 'Premium Domains for Sale: Browse 200+ | Domain Brothers',
				'desc'  => 'Browse premium domains for sale across .com, .ca and more, every name backed by escrow-protected transfer and 0% interest payment plans. Start looking today.',
				'kw'    => 'premium domains for sale',
			),
			'sold' => array(
				'title' => 'Sold Premium Domains: Our Track Record | Domain Brothers',
				'desc'  => 'Review the sold premium domains behind 15+ years of deals and $5,000,000 brokered. Proof the escrow process works, so browse what is still available now.',
				'kw'    => 'sold premium domains',
			),
			'about-us' => array(
				'title' => 'Domain Brokerage Experts Since Day One | Domain Brothers',
				'desc'  => 'Meet the domain brokerage experts with 15+ years matching businesses to the right name. Strategy, escrow and transfer help included. Browse our inventory now.',
				'kw'    => 'domain brokerage experts',
			),
			'about-domain-brothers' => array(
				'title' => 'Premium Domain Company Built by Brothers | Domain Brothers',
				'desc'  => 'Kartik and Jay Mehta built a premium domain company on integrity, transparency and 27+ years of combined experience. See the names we have ready for you.',
				'kw'    => 'premium domain company',
			),
			'our-team' => array(
				'title' => 'Domain Brokerage Team: Meet the Experts | Domain Brothers',
				'desc'  => 'Our domain brokerage team handles valuation, negotiation and transfer so you never buy alone. Real specialists, not a ticket queue. Ask us about a name today.',
				'kw'    => 'domain brokerage team',
			),
			'faqs' => array(
				'title' => 'Domain Buying FAQs: Answers for Buyers | Domain Brothers',
				'desc'  => 'Clear domain buying FAQs on premium pricing, escrow, DNS and 0% interest instalments over 3 to 12 months. Get the answers, then pick your domain.',
				'kw'    => 'domain buying faqs',
			),
			'contact-us' => array(
				'title' => 'Contact Domain Brothers: Talk to Sales | Domain Brothers',
				'desc'  => 'Contact Domain Brothers to discuss any listed name, request a payment plan, or get transfer help from a real specialist. Reach our sales team today.',
				'kw'    => 'contact domain brothers',
			),
			'refund-policy' => array(
				'title' => 'Domain Refund Policy: Clear and Fair | Domain Brothers',
				'desc'  => 'Read the domain refund policy covering purchases, instalments and cancelled transfers, written in plain language so you buy with confidence. Review the terms.',
				'kw'    => 'domain refund policy',
			),
			'terms-of-use' => array(
				'title' => 'Terms of Use for Domain Purchases | Domain Brothers',
				'desc'  => 'Our terms of use set out how purchases, escrow transfers and payment plans work on this marketplace, so nothing is hidden. Read them before you buy.',
				'kw'    => 'terms of use',
			),
			'privacy-policy' => array(
				'title' => 'Privacy Policy: How We Protect Data | Domain Brothers',
				'desc'  => 'This privacy policy explains what we collect during a domain enquiry or purchase, how it is stored, and your rights over it. Read it, then shop in confidence.',
				'kw'    => 'privacy policy',
			),
			'site-map' => array(
				'title' => 'Site Map: Every Page and Domain Page | Domain Brothers',
				'desc'  => 'Use this site map to jump straight to domain categories, services, payment plan details and support pages in one click. Find what you need and start browsing.',
				'kw'    => 'site map',
			),
			'acquire-premium-domain' => array(
				'title' => 'Acquire a Premium Domain You Want | Domain Brothers',
				'desc'  => 'Want a name we do not own? We acquire a premium domain on your behalf with no upfront cost and a dedicated negotiator. Tell us which domain you want.',
				'kw'    => 'acquire a premium domain',
			),
			'premium-domain-deals' => array(
				'title' => 'Premium Domain Deals and Price Drops | Domain Brothers',
				'desc'  => 'Catch premium domain deals with reduced pricing, filtered by extension and length, all with escrow transfer and instalments. Grab a bargain before it sells.',
				'kw'    => 'premium domain deals',
			),
			'domains-under-review' => array(
				'title' => 'Domains Under Review: Listing Status | Domain Brothers',
				'desc'  => 'Your domains under review are being checked by our team, and we will confirm approval shortly. Meanwhile, browse the premium names already live on our site.',
				'kw'    => 'domains under review',
			),
			'offer' => array(
				'title' => 'Make an Offer on a Premium Domain | Domain Brothers',
				'desc'  => 'Make an offer on any premium domain in our inventory and a broker replies personally. Escrow protection and 0% interest plans apply. Submit your offer now.',
				'kw'    => 'make an offer',
			),
			'domain-transfer' => array(
				'title' => 'Domain Transfer Help from Real Experts | Domain Brothers',
				'desc'  => 'Get domain transfer help from specialists who move names every week, with EPP handling and escrow protection at every step. Submit your transfer details here.',
				'kw'    => 'domain transfer help',
			),
			'epp-guidelines' => array(
				'title' => 'EPP Code Guidelines for Every Registrar | Domain Brothers',
				'desc'  => 'Follow our EPP code guidelines for GoDaddy, Namecheap, UniRegistry and more to unlock and move a domain without delay. Read the steps for your registrar.',
				'kw'    => 'epp code guidelines',
			),
			'3-character-domains' => array(
				'title' => '3 Character Domains for Sale, Ultra Rare | Domain Brothers',
				'desc'  => 'Shop 3 character domains for sale, the scarcest asset class online, with escrow transfer and instalments available. Claim one before it is gone for good.',
				'kw'    => '3 character domains',
			),
			'4-character-domains' => array(
				'title' => '4 Character Domains for Sale and Ready | Domain Brothers',
				'desc'  => 'Find 4 character domains for sale that stay memorable, type fast and travel well across markets. Escrow protected with 0% plans. Pick yours and buy today.',
				'kw'    => '4 character domains',
			),
			'5-character-domains' => array(
				'title' => '5 Character Domains: Short, Brandable | Domain Brothers',
				'desc'  => 'Discover 5 character domains that balance brevity with real brandability, ideal for startups and apps. Payment plans available, so reserve your name now.',
				'kw'    => '5 character domains',
			),
			'6-character-domains' => array(
				'title' => '6 Character Domains for Brand Builders | Domain Brothers',
				'desc'  => 'Explore 6 character domains that read as real words and still feel premium, priced for growing brands. Escrow transfer included. Secure yours this week.',
				'kw'    => '6 character domains',
			),
			'numeric-domains-for-sale' => array(
				'title' => 'Numeric Domains for Sale: All Digits | Domain Brothers',
				'desc'  => 'Shop numeric domains for sale that cross language barriers and rank well in Asian markets. Escrow protected with 0% instalments. Choose your digits today.',
				'kw'    => 'numeric domains for sale',
			),
			'our-services' => array(
				'title' => 'Domain and Digital Services in One Place | Domain Brothers',
				'desc'  => 'Our domain and digital services cover brokerage, design, marketing and hosting under one roof, from acquisition to launch. See what we can build for you.',
				'kw'    => 'domain and digital services',
			),
			'buy-domains-service' => array(
				'title' => 'Buy Domains with Escrow and Payment Plans | Domain Brothers',
				'desc'  => 'Buy domains from a curated inventory with escrow-protected transfer, 0% interest instalments and strategy advice from 15+ year veterans. Start browsing now.',
				'kw'    => 'buy domains',
			),
			'sell-domains-service' => array(
				'title' => 'Sell Domains with a Portfolio Manager | Domain Brothers',
				'desc'  => 'If you hold unused names, you can sell domains here with a dedicated portfolio manager guiding pricing and paperwork. Ask us how the listing process works.',
				'kw'    => 'sell domains',
			),
			'domain-management-services' => array(
				'title' => 'Domain Management Services, Fully Handled | Domain Brothers',
				'desc'  => 'Renewals, DNS, WHOIS and registrar consolidation are all covered by our domain management services, so nothing lapses. Hand us the admin and save your time.',
				'kw'    => 'domain management services',
			),
			'domain-appraisal-services' => array(
				'title' => 'Domain Appraisal Services You Can Cite | Domain Brothers',
				'desc'  => 'Written domain appraisal services combine comparable sales, search volume and brandability into a defensible figure you can cite. Request a report today.',
				'kw'    => 'domain appraisal services',
			),
			'domain-brokerage-services' => array(
				'title' => 'Domain Brokerage Services for Buyers | Domain Brothers',
				'desc'  => 'Use domain brokerage services from a team that has moved $5,000,000 in names, negotiating hard on your side of the table. Tell us the domain you are chasing.',
				'kw'    => 'domain brokerage services',
			),
			'digital-marketing-services' => array(
				'title' => 'Digital Marketing Services That Convert | Domain Brothers',
				'desc'  => 'Turn a new domain into traffic with digital marketing services spanning SEO, paid search and social, run by a 140-strong team. Book a free call today.',
				'kw'    => 'digital marketing services',
			),
			'custom-software-development-services' => array(
				'title' => 'Custom Software Development for Growth | Domain Brothers',
				'desc'  => 'Scalable custom software development that turns your new domain into a working product, built by senior engineers. Request a free project consultation now.',
				'kw'    => 'custom software development',
			),
			'mobile-app-development-services' => array(
				'title' => 'Mobile App Development, iOS and Android | Domain Brothers',
				'desc'  => 'Native and cross-platform mobile app development for iOS and Android, shipped fast and built to keep users coming back. Get a free quote for your build.',
				'kw'    => 'mobile app development',
			),
			'web-hosting-maintenance-services' => array(
				'title' => 'Web Hosting and Maintenance Plans | Domain Brothers',
				'desc'  => 'Reliable web hosting maintenance plans with backups, uptime monitoring, patching and support, so your new domain never goes dark. Compare plans and start now.',
				'kw'    => 'web hosting maintenance',
			),
			'comprehensive-digital-solutions' => array(
				'title' => 'Digital Solutions Agency for Your Domain | Domain Brothers',
				'desc'  => 'One digital solutions agency for domain, design, build, marketing and hosting, with a single point of contact throughout. Scope your project with us today.',
				'kw'    => 'digital solutions agency',
			),
			'acquire-social-media-handles' => array(
				'title' => 'Buy Social Media Handles, Escrow Backed | Domain Brothers',
				'desc'  => 'Looking to buy social media handles that match your brand? We negotiate and transfer them safely through escrow. Bids start at $5,000, so tell us the handle.',
				'kw'    => 'buy social media handles',
			),
			'sell-social-media-handles' => array(
				'title' => 'Sell Social Media Handles Discreetly | Domain Brothers',
				'desc'  => 'Own a valuable username? You can sell social media handles through our vetted buyer network with confidential, escrow-backed transfers. Ask about the process.',
				'kw'    => 'sell social media handles',
			),
			'acquire-a-handle-now' => array(
				'title' => 'Acquire a Handle Now: Start Your Bid | Domain Brothers',
				'desc'  => 'Ready to acquire a handle now? Send us the platform, username and budget, and a specialist starts negotiating within one business day. Submit your request.',
				'kw'    => 'acquire a handle now',
			),
		);
	}
}

/* ─── Transactional / utility endpoints that must never rank ───────────────── */

if ( ! function_exists( 'db_seo_utility_slugs' ) ) {
	/**
	 * Slug => <title> for pages that need a sane title but must carry
	 * noindex: checkout steps, payment callbacks, internal tooling and the
	 * theme's legacy sitemap endpoints (several of which emit no <title> and
	 * no readable content at all).
	 */
	function db_seo_utility_slugs() {
		return array(
			'buy-now'                   => 'Secure Checkout' . DB_SEO_SUFFIX,
			'checkout'                  => 'Checkout' . DB_SEO_SUFFIX,
			'offer'                     => 'Make an Offer' . DB_SEO_SUFFIX,
			'thank-you'                 => 'Thank You' . DB_SEO_SUFFIX,
			'payment-successful'        => 'Payment Successful' . DB_SEO_SUFFIX,
			'payment-cancel'            => 'Payment Cancelled' . DB_SEO_SUFFIX,
			'confirm-subscription'      => 'Confirm Subscription' . DB_SEO_SUFFIX,
			'paypal-ipn'                => 'Payment Notification' . DB_SEO_SUFFIX,
			'plugnpay'                  => 'Payment Gateway' . DB_SEO_SUFFIX,
			'installment-detail-admin'  => 'Installment Detail' . DB_SEO_SUFFIX,
			'installment-detail-buyer'  => 'Installment Detail' . DB_SEO_SUFFIX,
			'installment-detail-seller' => 'Installment Detail' . DB_SEO_SUFFIX,
			'news-sitemap'              => 'News Sitemap' . DB_SEO_SUFFIX,
			'xmlsitemap'                => 'XML Sitemap' . DB_SEO_SUFFIX,
			'all-domains-sitemap'       => 'Domain Sitemap' . DB_SEO_SUFFIX,
			'all-categories-sitemap'    => 'Category Sitemap' . DB_SEO_SUFFIX,
			'domains-under-review'      => 'Domains Under Review' . DB_SEO_SUFFIX,
			'payment-plan-setup'        => 'Set Up Your Payment Plan' . DB_SEO_SUFFIX,
			// The theme's availability checker renders a 4-byte "Done" body
			// and nothing else — there is no page here to rank.
			'check-domain'              => 'Check a Domain' . DB_SEO_SUFFIX,
		);
	}
}

/* ─── Keep utility endpoints out of the XML sitemap ────────────────────────── */

/**
 * WordPress core's sitemap lists every published page, which meant Google
 * was being handed 19 URLs that are checkout steps, payment-gateway
 * callbacks, internal installment tooling, legacy theme sitemaps, or simply
 * broken: /plugnpay/ returns a zero-byte body and /check-domain/ returns the
 * four bytes "Done". Submitting those alongside the real content dilutes
 * crawl budget and invites thin-content penalties. They are noindexed above;
 * this stops them being advertised in the first place.
 */
if ( ! function_exists( 'db_seo_utility_page_ids' ) ) {
	/**
	 * Post IDs for the utility slugs.
	 *
	 * WP_Query has post_name__in but no post_name__not_in — passing one is
	 * silently ignored, so the exclusion has to be done by ID.
	 *
	 * @return int[]
	 */
	function db_seo_utility_page_ids() {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		$slugs = array_keys( db_seo_utility_slugs() );
		$ids   = $slugs ? get_posts( array(
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'post_name__in'    => $slugs,
			'numberposts'      => count( $slugs ),
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'suppress_filters' => false,
		) ) : array();
		$ids = array_map( 'intval', (array) $ids );
		return $ids;
	}
}

add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $post_type ) {
	if ( 'page' !== $post_type ) {
		return $args;
	}
	$ids = db_seo_utility_page_ids();
	if ( ! $ids ) {
		return $args;
	}
	$existing = isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array();
	$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $ids ) ) );
	return $args;
}, 10, 2 );

if ( ! function_exists( 'db_seo_is_utility' ) ) {
	/**
	 * True when the current request is a transactional/utility endpoint.
	 */
	function db_seo_is_utility() {
		if ( ! is_page() ) {
			return false;
		}
		$post = get_queried_object();
		return $post instanceof WP_Post && isset( db_seo_utility_slugs()[ $post->post_name ] );
	}
}

/* ─── Domain listings ──────────────────────────────────────────────────────── */

if ( ! function_exists( 'db_seo_domain_def' ) ) {
	/**
	 * Builds title + description for a single domain listing from its price.
	 *
	 * @return array{title:string,desc:string,kw:string}|null
	 */
	function db_seo_domain_def( $post ) {
		if ( ! $post ) {
			return null;
		}
		$name  = $post->post_title;
		$raw   = (string) get_post_meta( $post->ID, 'domain_price', true );
		$clean = preg_replace( '/[^0-9.]/', '', $raw );
		$price = is_numeric( $clean ) ? '$' . number_format( (float) $clean, 0 ) : '';

		$title = $price
			? sprintf( '%s for Sale, %s%s', $name, $price, DB_SEO_SUFFIX )
			: sprintf( '%s for Sale%s', $name, DB_SEO_SUFFIX );
		$desc  = $price
			? sprintf( 'Buy %s for %s. A premium domain with escrow-protected transfer and 0%% interest payment plans available. Secure it today at Domain Brothers.', $name, $price )
			: sprintf( 'Buy %s, a premium domain, with an escrow-protected transfer and 0%% interest payment plans available. Secure it today at Domain Brothers.', $name );

		return array( 'title' => $title, 'desc' => $desc, 'kw' => strtolower( $name ) );
	}
}

/* ─── Fallback description ─────────────────────────────────────────────────── */

if ( ! function_exists( 'db_seo_trim_desc' ) ) {
	/**
	 * Normalizes arbitrary text into a clean meta description: tags and
	 * shortcodes stripped, whitespace collapsed, cut on a word boundary.
	 *
	 * @param string $text  Raw text.
	 * @param int    $limit Maximum characters.
	 */
	function db_seo_trim_desc( $text, $limit = 155 ) {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $text ), true );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( '' === $text ) {
			return '';
		}
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $limit - 1 );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > 60 ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut, " ,.;:-" ) . '…';
	}
}

if ( ! function_exists( 'db_seo_fallback_def' ) ) {
	/**
	 * Builds a title + description for any request that has no hand-written
	 * definition, so no indexable URL ever ships without a description.
	 *
	 * @return array{title:string,desc:string,kw:string}|null
	 */
	function db_seo_fallback_def() {
		$brand = DB_SEO_SUFFIX;

		if ( is_singular() ) {
			$post = get_queried_object();
			if ( ! $post instanceof WP_Post ) {
				return null;
			}
			$desc = has_excerpt( $post ) ? get_the_excerpt( $post ) : $post->post_content;
			$desc = db_seo_trim_desc( $desc );
			if ( '' === $desc ) {
				$desc = sprintf(
					'%s at Domain Brothers, a premium domain marketplace offering escrow-protected transfers and 0%% interest payment plans on every listing.',
					$post->post_title
				);
			}
			return array(
				'title' => db_seo_trim_desc( $post->post_title, 60 - mb_strlen( $brand ) ) . $brand,
				'desc'  => $desc,
				'kw'    => '',
			);
		}

		if ( is_search() ) {
			$q = get_search_query();
			return array(
				'title' => sprintf( 'Search results for %s%s', $q, $brand ),
				'desc'  => sprintf( 'Premium domains matching %s. Browse the Domain Brothers marketplace and buy with escrow-protected transfer and 0%% interest payment plans.', $q ),
				'kw'    => '',
			);
		}

		if ( is_tax( array( 'domain_category', 'domain_tag' ) ) || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( ! $term instanceof WP_Term ) {
				return null;
			}
			// is_category()/is_tag() are WordPress's own post taxonomies
			// (e.g. /category/domain-news/, a blog-post archive) — a
			// completely different thing from domain_category/domain_tag
			// (the marketplace's per-domain taxonomy). The shared branch
			// below used to describe both the same way ("{term} Domains
			// for Sale"), which on a blog category produced a nonsensical
			// title like "Domain News Domains for Sale". Confirmed live.
			$is_blog_term = is_category() || is_tag();
			$desc         = db_seo_trim_desc( $term->description );
			if ( '' === $desc ) {
				$desc = $is_blog_term
					? sprintf( 'Posts filed under %s on the Domain Brothers blog — domain industry news and market insights.', $term->name )
					: sprintf(
						'Browse %s domains for sale at Domain Brothers. Every listing includes an escrow-protected transfer and 0%% interest payment plans. Find yours today.',
						$term->name
					);
			}
			$title = $is_blog_term
				? db_seo_trim_desc( $term->name . ' Archives', 60 - mb_strlen( $brand ) ) . $brand
				: db_seo_trim_desc( $term->name . ' Domains for Sale', 60 - mb_strlen( $brand ) ) . $brand;
			// Same bug as /news/ (fixed in db_seo_resolve() above, but that
			// fix only covers is_home() — every OTHER paginated archive,
			// including this one, still fell through to here with an
			// identical title/description on every page): confirmed live,
			// /domain-category/business/page/2/ through /4/ and
			// /category/domain-news/page/2/ through /300/ all carried the
			// exact same title and description as page 1. 19 pages flagged
			// as duplicate titles, 19 as duplicate descriptions.
			$paged = max( 1, (int) get_query_var( 'paged' ) );
			if ( $paged > 1 ) {
				$title = str_replace( DB_SEO_SUFFIX, ' – Page ' . $paged . DB_SEO_SUFFIX, $title );
				$desc  = rtrim( $desc, '. ' ) . '. Page ' . $paged . ' of the listing.';
			}
			return array(
				'title' => $title,
				'desc'  => $desc,
				'kw'    => $is_blog_term ? strtolower( $term->name ) : strtolower( $term->name ) . ' domains',
			);
		}

		if ( is_post_type_archive( 'domain' ) || is_home() ) {
			return array(
				'title' => 'Premium Domains for Sale' . $brand,
				'desc'  => 'Browse every premium domain for sale at Domain Brothers, each with an escrow-protected transfer and 0% interest payment plans. Find your domain today.',
				'kw'    => 'premium domains for sale',
			);
		}

		return null;
	}
}

/* ─── Current-request resolution ───────────────────────────────────────────── */

if ( ! function_exists( 'db_seo_current' ) ) {
	/**
	 * Returns the SEO def for the current request, or null if this block
	 * shouldn't manage it (admin, feeds, 404s).
	 *
	 * @return array{title:string,desc:string,kw:string}|null
	 */
	function db_seo_current() {
		static $cache = null;
		static $done  = false;
		if ( $done ) {
			return $cache;
		}
		$done  = true;
		$cache = db_seo_resolve();
		return $cache;
	}
}

if ( ! function_exists( 'db_seo_resolve' ) ) {
	function db_seo_resolve() {
		if ( is_admin() || is_feed() || is_robots() || is_404() ) {
			return null;
		}

		if ( is_front_page() ) {
			$defs = db_seo_defs();
			return $defs['_front'];
		}
		if ( is_singular( 'domain' ) ) {
			return db_seo_domain_def( get_queried_object() );
		}
		if ( is_page() ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$defs = db_seo_defs();
				if ( isset( $defs[ $post->post_name ] ) ) {
					return $defs[ $post->post_name ];
				}
				$utility = db_seo_utility_slugs();
				if ( isset( $utility[ $post->post_name ] ) ) {
					// Utility pages get a clean title but carry noindex, so a
					// marketing description would never be shown anyway.
					return array( 'title' => $utility[ $post->post_name ], 'desc' => '', 'kw' => '' );
				}
			}
		}

		// /news/ is WordPress's own posts page (confirmed live: body class
		// "blog", no page ID — is_home(), not is_page('news')), so without
		// this branch it fell all the way through to db_seo_fallback_def()'s
		// generic "is_post_type_archive('domain') || is_home()" case below —
		// the domain-marketplace title/description on the news archive, and
		// (worse) the exact same title+description on every one of its 300+
		// paginated pages. $defs['news'] already has the right copy; it just
		// never got consulted here. Flagged in the 2026-07 audit as 39
		// duplicate titles / 39 duplicate descriptions.
		if ( is_home() ) {
			$defs  = db_seo_defs();
			$def   = isset( $defs['news'] ) ? $defs['news'] : array( 'title' => 'News' . DB_SEO_SUFFIX, 'desc' => '', 'kw' => '' );
			$paged = max( 1, (int) get_query_var( 'paged' ) );
			if ( $paged > 1 ) {
				$def['title'] = str_replace( DB_SEO_SUFFIX, ' – Page ' . $paged . DB_SEO_SUFFIX, $def['title'] );
				$def['desc']  = rtrim( $def['desc'], '. ' ) . '. Page ' . $paged . ' of the latest listings.';
			}
			return $def;
		}
		return db_seo_fallback_def();
	}
}

/* ─── Output ───────────────────────────────────────────────────────────────── */

if ( DB_SEO_ACTIVE ) {

	add_filter( 'pre_get_document_title', function ( $title ) {
		$def = db_seo_current();
		return ( $def && ! empty( $def['title'] ) ) ? $def['title'] : $title;
	}, 20 );

	// Fallback for themes that assemble the title from parts rather than
	// honoring pre_get_document_title.
	add_filter( 'document_title_parts', function ( $parts ) {
		$def = db_seo_current();
		if ( $def && ! empty( $def['title'] ) ) {
			$parts = array( 'title' => $def['title'] );
		}
		return $parts;
	}, 20 );

	// The DomainFolio theme declares no title-tag support and printed NO
	// <title> at all on most templates (it relied on Yoast, which isn't
	// emitting) — so those pages shipped with zero title tags, a severe SEO
	// defect. Enabling core title-tag support makes WordPress render exactly
	// one <title>, and the filters above optimize it. A handful of the
	// theme's payment templates DO hardcode their own <title>; the head
	// deduper below strips those so the optimized one is the only survivor.
	add_action( 'after_setup_theme', function () {
		add_theme_support( 'title-tag' );
	}, 99 );

	add_action( 'wp_head', function () {
		if ( is_admin() || is_feed() || is_robots() ) {
			return;
		}
		$def = db_seo_current();
		if ( ! $def || empty( $def['desc'] ) ) {
			return;
		}
		echo "\n" . '<meta name="description" content="' . esc_attr( $def['desc'] ) . '">' . "\n";
		if ( ! empty( $def['kw'] ) ) {
			echo '<meta name="keywords" content="' . esc_attr( $def['kw'] ) . '">' . "\n";
		}
	}, 1 );

	// Keep the Open Graph / Twitter description (emitted by the AEO block)
	// identical to the meta description, instead of it falling back to the
	// site tagline on every non-singular URL.
	add_filter( 'db_aeo_description', function ( $description ) {
		$def = db_seo_current();
		return ( $def && ! empty( $def['desc'] ) ) ? $def['desc'] : $description;
	}, 20 );

	// Checkout steps, payment callbacks and the theme's legacy sitemap
	// endpoints were all indexable; several rendered no title and no
	// readable content, which is exactly the thin content Google penalizes.
	add_filter( 'wp_robots', function ( $robots ) {
		if ( db_seo_is_utility() || is_search() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = false;
			unset( $robots['index'] );
		}
		return $robots;
	}, 20 );
}

/* ─── <head> deduper ───────────────────────────────────────────────────────── */

if ( DB_SEO_ACTIVE ) {
	/**
	 * The theme hardcodes a <title> into some payment templates and a
	 * <link rel="canonical"> into the domain-listing template, on top of the
	 * ones WordPress core emits. That shipped two <title> tags and up to
	 * three canonicals on the same page. Neither can be unhooked (they are
	 * literal markup in the template files), so the finished document is
	 * filtered instead.
	 *
	 * Registered at priority 0 so this is the OUTERMOST buffer: the hero
	 * injector opens its own buffer at priority 1 and flushes into this one,
	 * meaning the callback here always sees the complete final document.
	 */
	add_action( 'template_redirect', function () {
		if ( is_admin() || is_feed() || is_robots() ) {
			return;
		}
		ob_start( 'db_seo_dedupe_head' );
	}, 0 );
}

if ( ! function_exists( 'db_seo_strip_dupes' ) ) {
	/**
	 * Removes every match of $pattern from $html except one, by byte offset.
	 *
	 * Offsets matter here: the theme's hardcoded canonical is byte-identical
	 * to the one the AEO block emits, so a str_replace of "the duplicate"
	 * would silently delete both and leave the page with no canonical at all.
	 *
	 * @param string $html    Markup to filter.
	 * @param string $pattern Regex matching the tag.
	 * @param string $keep    'first' or 'last' — which occurrence survives.
	 */
	function db_seo_strip_dupes( $html, $pattern, $keep = 'last' ) {
		if ( ! preg_match_all( $pattern, $html, $m, PREG_OFFSET_CAPTURE ) ) {
			return $html;
		}
		$hits = $m[0];
		if ( count( $hits ) < 2 ) {
			return $html;
		}
		if ( 'first' === $keep ) {
			array_shift( $hits );
		} else {
			array_pop( $hits );
		}
		// Splice from the end so earlier offsets stay valid.
		foreach ( array_reverse( $hits ) as $hit ) {
			$html = substr_replace( $html, '', (int) $hit[1], strlen( $hit[0] ) );
		}
		return $html;
	}
}

if ( ! function_exists( 'db_seo_dedupe_head' ) ) {
	function db_seo_dedupe_head( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		// Only touch real HTML documents — never JSON, XML or a redirect body.
		if ( false === stripos( $html, '</head>' ) ) {
			return $html;
		}
		$head_end = stripos( $html, '</head>' );
		$head     = substr( $html, 0, $head_end );
		$rest     = substr( $html, $head_end );

		// <title>: keep the LAST one. WordPress core's title-tag output comes
		// from wp_head, after any title the template hardcoded above it, and
		// core's is the one our filters optimize.
		$head = db_seo_strip_dupes( $head, '#<title\b[^>]*>.*?</title>#is', 'last' );

		// canonical: keep the FIRST one. Every emitter on this site resolves
		// to the same URL, so first-wins is safe and avoids depending on
		// which plugin happened to run last.
		$head = db_seo_strip_dupes( $head, '#<link\b[^>]*\brel=(?:["\'])?canonical(?:["\'])?[^>]*>#i', 'first' );

		return $head . $rest;
	}
}

/* ─── Heading-structure fixes flagged in the 2026-07 site audit ─────────────── */

if ( ! function_exists( 'db_seo_fix_news_pagination_h1' ) ) {
	/**
	 * The theme's own archive header renders <h1 class="page-title
	 * screen-reader-text">News</h1> — identical text on every one of the
	 * /news/ archive's 300+ paginated pages (confirmed live). Screen-reader-
	 * only doesn't mean SEO-invisible: crawlers still read it, and the audit
	 * flagged 38 duplicate H1s from this exact pattern. Appends the page
	 * number so each paginated page has a distinct H1, matching the
	 * pagination-aware title/description added in db_seo_resolve() above.
	 */
	function db_seo_fix_news_pagination_h1( $html ) {
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged < 2 || ! is_string( $html ) ) {
			return $html;
		}
		return preg_replace(
			'#(<h1\b[^>]*\bclass="[^"]*\bpage-title\b[^"]*"[^>]*>)(.*?)(</h1>)#is',
			'$1$2 – Page ' . $paged . '$3',
			$html,
			1
		);
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_home() || max( 1, (int) get_query_var( 'paged' ) ) < 2 ) {
		return;
	}
	ob_start( 'db_seo_fix_news_pagination_h1' );
}, 20 );

if ( ! function_exists( 'db_seo_promote_archive_h1' ) ) {
	/**
	 * The domain-category taxonomy archives and /all-domains/ render their
	 * only page heading as <h2 class="page-title">, with no <h1> anywhere on
	 * the page at all (confirmed live on both templates) — the "H1 tag
	 * missing" defect the audit found on 144 pages. ".page-title" is styled
	 * purely by class in the theme's CSS (never combined with the "h2"
	 * element in a compound selector for these pages), so promoting the tag
	 * itself to <h1> is a visual no-op.
	 */
	function db_seo_promote_archive_h1( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, 'page-title' ) ) {
			return $html;
		}
		$html = preg_replace(
			'#<h2((?:\s+[a-z-]+="[^"]*")*\s+class="[^"]*\bpage-title\b[^"]*"(?:\s+[a-z-]+="[^"]*")*)>(.*?)</h2>#is',
			'<h1$1>$2</h1>',
			$html,
			1
		);
		// Same identical-across-pagination problem the title/description
		// fix above addresses, on the H1 itself: page 2+ of a category
		// archive repeats page 1's exact heading text. Flagged as
		// "Duplicate H1" (25 pages) in the 2026-07-28 audit.
		$paged = max( 1, (int) get_query_var( 'paged' ) );
		if ( $paged > 1 ) {
			$html = preg_replace(
				'#(<h1\b[^>]*\bclass="[^"]*\bpage-title\b[^"]*"[^>]*>)(.*?)(</h1>)#is',
				'$1$2 – Page ' . $paged . '$3',
				$html,
				1
			);
		}
		return $html;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ( ! is_tax( 'domain_category' ) && ! is_category() && ! is_page( 'all-domains' ) ) ) {
		return;
	}
	ob_start( 'db_seo_promote_archive_h1' );
}, 20 );
