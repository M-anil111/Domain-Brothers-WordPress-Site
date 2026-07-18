<?php
/**
 * DB SEO Meta Block — per-page titles, descriptions & keywords
 *
 * Context: the site has Yoast installed but it is NOT emitting any
 * front-end meta (no Yoast head block, no generator tag, and — critically —
 * no <meta name="description"> on any page, plus a duplicate canonical from
 * the theme). So this block ACTIVELY outputs SEO tags itself:
 *
 *   - Keyword-optimized <title> via the document-title filters
 *   - A unique <meta name="description"> per page (there were none)
 *   - Dynamic title + description for individual `domain` listings
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

/* ─── Per-page SEO definitions ─────────────────────────────────────────────── */

if ( ! function_exists( 'db_seo_defs' ) ) {
	/**
	 * SEO copy keyed by page slug. Titles ~50-60 chars, descriptions
	 * 140-155 chars, each built around a buy-intent focus keyword.
	 */
	function db_seo_defs() {
		$defs = array(
			'_front' => array(
				'title' => 'Buy Premium Domains — Escrow-Protected | Domain Brothers',
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
				'desc'  => 'Data-driven digital marketing — SEO, PPC and social that grow traffic, leads and revenue. Book a free strategy call with Domain Brothers today.',
				'kw'    => 'digital marketing services',
			),
			'software-development' => array(
				'title' => 'Custom Software Development | Domain Brothers',
				'desc'  => 'Custom software development built around your business — scalable, secure apps from a senior team. Book a free consultation with Domain Brothers today.',
				'kw'    => 'custom software development',
			),
			'mobile-app-development' => array(
				'title' => 'Mobile App Development for iOS & Android | Domain Brothers',
				'desc'  => 'Expert mobile app development for iOS and Android — fast, beautiful, high-performing apps users love. Get a free project quote from Domain Brothers.',
				'kw'    => 'mobile app development',
			),
			'other-services' => array(
				'title' => 'Web Development & Digital Services | Domain Brothers',
				'desc'  => 'Full-stack web development and digital services — from integrations to complex platforms. Reliable, modern builds. Request your free quote today.',
				'kw'    => 'web development services',
			),
			'news' => array(
				'title' => 'Domain Industry News & Market Insights | Domain Brothers',
				'desc'  => 'The latest domain industry news, market trends and premium domain insights from the Domain Brothers marketplace. Stay ahead of the market.',
				'kw'    => 'domain industry news',
			),
		);
		return apply_filters( 'db_seo_defs', $defs );
	}
}

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
			? sprintf( '%s for Sale — %s | Domain Brothers', $name, $price )
			: sprintf( '%s for Sale | Domain Brothers', $name );
		$desc  = $price
			? sprintf( 'Buy %s for %s. A premium domain with escrow-protected transfer and 0%% interest payment plans available. Secure it today at Domain Brothers.', $name, $price )
			: sprintf( 'Buy %s, a premium domain, with an escrow-protected transfer and 0%% interest payment plans available. Secure it today at Domain Brothers.', $name );

		return array( 'title' => $title, 'desc' => $desc, 'kw' => strtolower( $name ) );
	}
}

if ( ! function_exists( 'db_seo_current' ) ) {
	/**
	 * Returns the SEO def for the current request, or null if this block
	 * shouldn't manage it (admin, feeds, or an unmapped page).
	 *
	 * @return array{title:string,desc:string,kw:string}|null
	 */
	function db_seo_current() {
		if ( is_admin() || is_feed() || is_robots() ) {
			return null;
		}
		$defs = db_seo_defs();

		if ( is_front_page() ) {
			return $defs['_front'];
		}
		if ( is_singular( 'domain' ) ) {
			return db_seo_domain_def( get_queried_object() );
		}
		if ( is_page() ) {
			$post = get_queried_object();
			if ( $post && isset( $defs[ $post->post_name ] ) ) {
				return $defs[ $post->post_name ];
			}
		}
		return null;
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
	// <title> at all (it relied on Yoast, which isn't emitting) — so every
	// page shipped with zero title tags, a severe SEO defect. Enabling core
	// title-tag support makes WordPress render exactly one <title>, and the
	// filters above optimize it. This is the canonical fix for a classic
	// theme missing a title, and it can't double-up on DomainFolio (which
	// currently outputs none).
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
}
