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
		$html  = '<section class="db-aud" aria-labelledby="db-aud-h"><h2 id="db-aud-h" class="screen-reader-text">Who Domain Brothers is for</h2><div class="db-aud-grid">';
		$html .= '<div class="db-aud-col"><h3>Perfect for domain buyers &hellip;</h3>' . $list( $buyers ) . '<a class="db-aud-btn" href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse domains for sale</a></div>';
		$html .= '<div class="db-aud-col"><h3>&hellip; and domain sellers</h3>' . $list( $sellers ) . '<a class="db-aud-btn" href="' . esc_url( home_url( '/contact-us/' ) ) . '">Talk to us about selling</a></div>';
		$html .= '</div></section>';
		$html .= '<section class="db-how" aria-labelledby="db-how-h"><h2 id="db-how-h">How it works</h2><div class="db-how-grid">';
		$html .= '<div class="db-how-step"><span class="db-how-icon">' . $icons[0] . '</span><h3>Find your name</h3><p>Browse premium domains or search by keyword. Every listing shows its price, a monthly payment option, or a button to make an offer.</p></div>';
		$html .= '<div class="db-how-step"><span class="db-how-icon">' . $icons[1] . '</span><h3>Pay your way</h3><p>Pay in full, or spread the cost over 3, 6, 9 or 12 months. Prefer to negotiate? Make an offer and our team will reply personally.</p></div>';
		$html .= '<div class="db-how-step"><span class="db-how-icon">' . $icons[2] . '</span><h3>We handle the transfer</h3><p>Your payment is protected by escrow. When the sale completes we send your transfer code securely and help move the domain to the registrar of your choice.</p></div>';
		$html .= '</div></section>';
		return $html;
	}
}

// Domain Statistics goes in front of the domain details section on a domain page.
add_filter( 'db_dd_html', function ( $html, $post ) {
	return db_stats_html( $post ) . $html;
}, 10, 2 );

// Home page sections are spliced in by the front-page buffer in db-custom-blocks.php (filter db_hp_sections_html).
add_filter( 'db_hp_sections', function ( $html ) {
	return $html . db_hp_sections_html();
} );
