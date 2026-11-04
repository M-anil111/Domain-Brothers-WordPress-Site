<?php
/**
 * Content sections: Domain Statistics on every domain page, and the
 * "for buyers and sellers" and "How it works" sections on the home page.
 *
 * Domain Statistics shows real figures only, large and easy to read:
 *
 *   Views this month   people looking at this page (bots, the team and quick
 *                      repeat refreshes are not counted: see db-hits-block)
 *   Total views        since tracking began
 *   Offers received    offers buyers have made on this domain
 *   Watching           people who saved it to their wishlist
 *
 * A figure that is still zero is left out, and the section is left out when
 * all are zero, so a page never shows a row of zeros.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_stats_offer_count' ) ) {
	/** Offers buyers have made on a domain name (every offer counts, repeats included). */
	function db_stats_offer_count( $name ) {
		global $wpdb;
		if ( ! function_exists( 'db_deal_table' ) ) {
			return 0;
		}
		$dt = db_deal_table();
		$et = db_deal_table( 'events' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$et} e JOIN {$dt} d ON d.id = e.deal_id WHERE e.actor = 'buyer' AND e.action = 'offer' AND d.domain = %s", strtolower( trim( (string) $name ) ) ) );
	}
}

if ( ! function_exists( 'db_stats_figures' ) ) {
	/** @return array<int,array{n:int,label:string}> the non-zero figures, in display order */
	function db_stats_figures( $post ) {
		$hits = function_exists( 'db_hits_for_domain' ) ? db_hits_for_domain( $post->ID ) : null;
		$all  = array(
			array( 'n' => $hits ? (int) $hits->month_h : 0, 'label' => 'Views this month' ),
			array( 'n' => $hits ? (int) $hits->all_h : 0, 'label' => 'Total views' ),
			array( 'n' => db_stats_offer_count( wp_strip_all_tags( $post->post_title ) ), 'label' => 'Offers received' ),
			array( 'n' => function_exists( 'db_wl_count' ) ? db_wl_count( $post->ID ) : 0, 'label' => 'Watching' ),
		);
		return array_values( array_filter( $all, static function ( $f ) {
			return $f['n'] > 0;
		} ) );
	}
}

if ( ! function_exists( 'db_stats_html' ) ) {
	function db_stats_html( $post ) {
		$figs = db_stats_figures( $post );
		if ( ! $figs ) {
			return '';
		}
		$html = '<section class="db-stats" aria-labelledby="db-stats-h"><h2 id="db-stats-h">Domain Statistics</h2><ul class="db-stats-list">';
		foreach ( $figs as $f ) {
			$html .= '<li><strong>' . esc_html( number_format_i18n( $f['n'] ) ) . '</strong><span>' . esc_html( $f['label'] ) . '</span></li>';
		}
		return $html . '</ul></section>';
	}
}

if ( ! function_exists( 'db_hp_sections_html' ) ) {
	/** Home page: who it is for, and how a purchase works. Built from strings (no output buffering). */
	function db_hp_sections_html() {
		$check = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M7.5 12.5l3 3 6-7"/></svg>';
		$list  = static function ( $items ) use ( $check ) {
			$h = '<ul class="db-aud-list">';
			foreach ( $items as $i ) {
				$h .= '<li>' . $check . '<span>' . $i . '</span></li>';
			}
			return $h . '</ul>';
		};
		$buyers  = array(
			'Founders and startups looking for a brand-worthy web address',
			'Businesses launching a new product or service online',
			'Brand owners protecting the names around their brand',
			'Investors adding premium domains to a portfolio',
			'Marketers who need a short, memorable name for a campaign',
		);
		$sellers = array(
			'Owners with an unused domain they would like to turn into money',
			'Portfolio holders who want a broker to market premium names',
			'Sellers who want negotiated offers, not a fixed auction',
			'Anyone who wants escrow-protected, well-handled transfers',
			'Teams launching a new domain extension',
		);
		$icons = array(
			'<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><circle cx="21" cy="21" r="11"/><path d="M29.500 29.500L40 40"/><path d="M16 21h10M21 16v10"/></svg>',
			'<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><rect x="6" y="12" width="36" height="24" rx="4"/><path d="M6 20h36M14 29h8"/></svg>',
			'<svg viewBox="0 0 48 48" aria-hidden="true" focusable="false"><path d="M24 5l15 6v11c0 9.500-6.200 17.600-15 21-8.800-3.400-15-11.500-15-21V11l15-6z"/><path d="M17 24l5 5 9-10"/></svg>',
		);
		$badge_buy  = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="10.500" cy="10.500" r="6.500"/><path d="M15.500 15.500L21 21"/></svg>';
		$badge_sell = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M3 12.500V4h8.500L21 13.500 13.500 21 3 12.500z"/><circle cx="7.500" cy="8.500" r="1.200"/></svg>';
		$html  = '<section class="db-aud" aria-labelledby="db-aud-h">';
		$html .= '<header class="db-aud-head"><p class="db-aud-eyebrow">Buy or sell with confidence</p><h2 id="db-aud-h">Whether you are buying or selling, we make it simple</h2><p class="db-aud-sub">Premium domains, personal service and escrow-protected transfers, from the first search to the final handover.</p></header>';
		$html .= '<div class="db-aud-grid">';
		$html .= '<div class="db-aud-col db-aud-col--buy"><span class="db-aud-badge">' . $badge_buy . '</span><h3>Perfect for domain buyers</h3><p class="db-aud-lede">Find a name that makes your brand easy to remember.</p>' . $list( $buyers ) . '<a class="db-aud-btn" href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse domains for sale</a></div>';
		$html .= '<div class="db-aud-col db-aud-col--sell"><span class="db-aud-badge">' . $badge_sell . '</span><h3>Perfect for domain sellers</h3><p class="db-aud-lede">Turn a name you are not using into money.</p>' . $list( $sellers ) . '<a class="db-aud-btn db-aud-btn--ghost" href="' . esc_url( home_url( '/contact-us/' ) ) . '">Talk to us about selling</a></div>';
		$html .= '</div></section>';
		$html .= '<section class="db-how" aria-labelledby="db-how-h"><h2 id="db-how-h">How it works</h2><div class="db-how-grid">';
		$html .= '<div class="db-how-step"><span class="db-how-icon">' . $icons[0] . '</span><h3>Find your name</h3><p>Browse premium domains or search by keyword. Every listing shows its price, a monthly payment option, or a button to make an offer.</p></div>';
		$html .= '<div class="db-how-step"><span class="db-how-icon">' . $icons[1] . '</span><h3>Pay your way</h3><p>Pay in full, or spread the cost over 3, 6, 9 or 12 months. Prefer to negotiate? Make an offer and our team will reply personally.</p></div>';
		$html .= '<div class="db-how-step"><span class="db-how-icon">' . $icons[2] . '</span><h3>We handle the transfer</h3><p>Your payment is protected by escrow. When the sale completes we send your transfer code securely and help move the domain to the registrar of your choice.</p></div>';
		$html .= '</div></section>';
		return $html;
	}
}

if ( ! function_exists( 'db_develop_banner_html' ) ) {
	/** "Want to develop this domain?": the build services, with a call to action. Strings only. */
	function db_develop_banner_html( $post ) {
		$name  = strtolower( wp_strip_all_tags( $post->post_title ) );
		$quote = add_query_arg( array( 'domain' => $name, 'topic' => 'develop' ), home_url( '/contact-us/' ) );
		$icons = array(
			'logo'    => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3l2.600 5.400 5.900.8-4.300 4.100 1 5.900L12 16.400 6.800 19.200l1-5.900L3.500 9.200l5.900-.8L12 3z"/></svg>',
			'social'  => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M4 5h16v11H9l-5 4V5z"/><path d="M8 9.500h8M8 12.500h5"/></svg>',
			'website' => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="3" y="4" width="18" height="14" rx="2.500"/><path d="M3 9h18M8 21h8M12 18v3"/></svg>',
			'app'     => '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><rect x="7" y="2.500" width="10" height="19" rx="2.500"/><path d="M11 18.500h2"/></svg>',
		);
		$cards = array(
			array( 'logo', 'Get a logo designed', 'A mark and brand look that fit the name.', home_url( '/other-services/' ) ),
			array( 'social', 'Social media marketing', 'Launch campaigns that find your first customers.', home_url( '/digital-marketing/' ) ),
			array( 'website', 'Website design and build', 'A fast, modern site, live on ' . $name . '.', home_url( '/website-design-development/' ) ),
			array( 'app', 'Mobile apps', 'iPhone and Android apps for your idea.', home_url( '/mobile-app-development/' ) ),
		);
		$html  = '<section class="db-dev" aria-labelledby="db-dev-h"><div class="db-dev-inner">';
		$html .= '<div class="db-dev-copy"><p class="db-dev-eyebrow">Domain Brothers Studio</p><h2 id="db-dev-h">Want to develop this domain?</h2><p class="db-dev-sub">Buy <strong>' . esc_html( $name ) . '</strong> and build on it with one team: logo, social media, website and mobile apps, from first sketch to launch day.</p>';
		$html .= '<div class="db-dev-cta"><a class="db-dev-btn" href="' . esc_url( $quote ) . '">Get a free quote</a><a class="db-dev-link" href="' . esc_url( home_url( '/our-services/' ) ) . '">See all services <span aria-hidden="true">&rsaquo;</span></a></div></div>';
		$html .= '<ul class="db-dev-grid">';
		foreach ( $cards as $c ) {
			$html .= '<li><a href="' . esc_url( $c[3] ) . '"><span class="db-dev-ico">' . $icons[ $c[0] ] . '</span><strong>' . esc_html( $c[1] ) . '</strong><span class="db-dev-txt">' . esc_html( $c[2] ) . '</span><span class="db-dev-more" aria-hidden="true">Learn more &rsaquo;</span></a></li>';
		}
		return $html . '</ul></div></section>';
	}
}

/** The "Domain status" panel beside the details: price, how to get it, and why it is safe. Strings only. */
if ( ! function_exists( 'db_dd_side_html' ) ) {
	function db_dd_side_html( $post ) {
		$state = function_exists( 'db_listing_state' ) ? db_listing_state( $post ) : 'offer';
		$name  = strtolower( wp_strip_all_tags( $post->post_title ) );
		$price = function_exists( 'db_dd_price' ) ? db_dd_price( $post->ID ) : 0.0;
		$label = array( 'standard' => 'For sale', 'offer' => 'Open to offers', 'sold' => 'Sold', 'not_for_sale' => 'Not for sale' )[ $state ] ?? 'For sale';
		$h     = '<aside class="db-dd-side" aria-label="Domain status"><p class="db-dd-side-k">Domain status</p><p class="db-dd-side-pill db-dd-side-pill--' . esc_attr( $state ) . '"><span aria-hidden="true"></span>' . esc_html( $label ) . '</p>';
		if ( 'standard' === $state && $price > 0 ) {
			$urls = function_exists( 'db_dd_checkout_urls' ) ? db_dd_checkout_urls( $name, $price ) : array( 'buy' => home_url( '/buy-now/' ), 'plan' => home_url( '/payment-plan-setup/' ) );
			$h   .= '<p class="db-dd-side-price">$' . esc_html( number_format( $price, 0 ) ) . '</p>';
			if ( function_exists( 'db_dd_monthly' ) && $price >= ( defined( 'DB_DEAL_MIN_PLAN_AMOUNT' ) ? DB_DEAL_MIN_PLAN_AMOUNT : 600 ) ) {
				$h .= '<p class="db-dd-side-sub">or from ' . esc_html( db_dd_monthly( $price ) ) . '/month</p>';
			}
			$h .= '<a class="db-dd-side-btn" href="' . esc_url( $urls['buy'] ) . '">Buy now</a>';
			if ( function_exists( 'db_dd_monthly' ) && $price >= ( defined( 'DB_DEAL_MIN_PLAN_AMOUNT' ) ? DB_DEAL_MIN_PLAN_AMOUNT : 600 ) ) {
				$h .= '<a class="db-dd-side-btn db-dd-side-btn--ghost" href="' . esc_url( $urls['plan'] ) . '">Start a payment plan</a>';
			}
		} elseif ( 'offer' === $state ) {
			$h .= '<p class="db-dd-side-price db-dd-side-price--text">Make an offer</p><p class="db-dd-side-sub">Name your price. We reply personally.</p><a class="db-dd-side-btn" href="#buy_now">Make an offer</a>';
		} else {
			$h .= '<p class="db-dd-side-sub">This domain is not available right now.</p><a class="db-dd-side-btn db-dd-side-btn--ghost" href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse other domains</a>';
		}
		$h .= '<ul class="db-dd-side-list"><li>Escrow-protected payment</li><li>We handle the transfer</li><li>Pay in full or over time</li></ul></aside>';
		return $h;
	}
}

// Domain Statistics goes in front of the domain details section on a domain page; the "develop this domain" banner sits before the recommendations.
// The status panel goes into the details grid; banner ads go after the details and after "How buying works".
add_filter( 'db_dd_html', function ( $html, $post ) {
	$ad2    = function_exists( 'db_ad_html' ) ? db_ad_html( 'after_details' ) : '';
	$ad3    = function_exists( 'db_ad_html' ) ? db_ad_html( 'after_steps' ) : '';
	$banner = $ad3 . db_develop_banner_html( $post );
	$marker = '<h3>Recommended domains</h3>';
	$html   = false !== strpos( $html, $marker ) ? str_replace( $marker, $banner . $marker, $html ) : $html . $banner;
	$how    = '<h3>How buying works</h3>';
	if ( false !== strpos( $html, $how ) ) {
		$html = str_replace( $how, $ad2 . $how, $html );
	}
	$open = '<section class="db-dd" aria-label="Domain details">';
	if ( false !== strpos( $html, $open ) ) {
		$html = str_replace( $open, $open . db_dd_side_html( $post ), $html );
	}
	return db_stats_html( $post ) . $html;
}, 10, 2 );

// Home page sections are spliced in by the front-page buffer in db-custom-blocks.php (filter db_hp_sections_html).
add_filter( 'db_hp_sections', function ( $html ) {
	return $html . db_hp_sections_html();
} );
