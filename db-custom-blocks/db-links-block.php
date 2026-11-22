<?php
/**
 * Internal linking network: one "Explore Domain Brothers" block that links the main buying,
 * selling and learning pages to each other. It is added under blog posts (db-article-block.php)
 * and under ordinary content pages, and leaves out the page the visitor is on.
 *
 * Every target was checked to answer 200 on 2026-11-22. "Sell your domains" appears only while
 * selling is switched on (db_seller_enabled()).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_link_hub_items' ) ) {
	/**
	 * The linked pages: path, label, one line on what the visitor gets there.
	 *
	 * @return array<int, array{0:string,1:string,2:string}>
	 */
	function db_link_hub_items() {
		$items = array(
			array( '/all-domains/', 'Browse premium domains', 'Every name for sale, with clear prices and filters.' ),
			array( '/payment-terms/', 'Payment plans', 'Pay over 3 to 12 months on any listed name.' ),
			array( '/acquire-premium-domain/', 'Acquire a specific domain', 'A broker approaches the owner for you. No fee unless we get it.' ),
			array( '/sell-your-domains/', 'Sell your domains', 'List free; pay a commission only when a name sells.' ),
			array( '/find-a-domain/', 'Find a domain', 'Ideas for your business from the names we sell.' ),
			array( '/how-to-value-a-premium-domain-name/', 'What is my domain worth?', 'How premium names are valued, step by step.' ),
			array( '/domain-price-index/', 'Domain price index', 'Median asking prices by length and extension.' ),
			array( '/domain-tools/', 'Free domain tools', 'WHOIS, DNS, SSL and availability lookups.' ),
			array( '/category/domain-guides/', 'Domain guides', 'Buying, selling and transfer guides from our brokers.' ),
			array( '/faqs/', 'FAQs', 'Payments, transfers, offers and selling, answered.' ),
		);
		if ( ! function_exists( 'db_seller_enabled' ) || ! db_seller_enabled() ) {
			$items = array_values( array_filter( $items, static function ( $i ) {
				return '/sell-your-domains/' !== $i[0];
			} ) );
		}
		return $items;
	}
}

if ( ! function_exists( 'db_link_hub_html' ) ) {
	/**
	 * The block, without the link to the current path.
	 *
	 * @param string $current_path Path of the page being viewed, e.g. '/faqs/'.
	 * @param int    $max          Most links shown.
	 */
	function db_link_hub_html( $current_path = '', $max = 8 ) {
		$current = '/' . trim( (string) $current_path, '/' ) . '/';
		$li      = '';
		$n       = 0;
		foreach ( db_link_hub_items() as $i ) {
			if ( $i[0] === $current || $n >= $max ) {
				continue;
			}
			$li .= '<li><a href="' . esc_url( home_url( $i[0] ) ) . '"><strong>' . esc_html( $i[1] ) . '</strong><span>' . esc_html( $i[2] ) . '</span></a></li>';
			++$n;
		}
		if ( '' === $li ) {
			return '';
		}
		return '<section class="db-hub" aria-labelledby="db-hub-h"><h2 id="db-hub-h">Explore Domain Brothers</h2><ul>' . $li . '</ul></section>'
			. '<style>.db-hub{max-width:1320px;margin:48px auto 0;padding:0}.db-hub h2{margin:0 0 16px;font-size:clamp(22px,2.4vw,28px);font-weight:800;letter-spacing:-.02em;color:#0a1628}'
			. '.db-hub ul{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px;margin:0;padding:0;list-style:none}'
			. '.db-hub a{display:flex;flex-direction:column;gap:4px;height:100%;padding:16px 18px;border:1px solid #dfe5e1;border-radius:16px;background:#fff;color:#0a1628!important;text-decoration:none!important;transition:border-color .15s,box-shadow .15s}'
			. '.db-hub a:hover,.db-hub a:focus-visible{border-color:#0a1628;box-shadow:0 8px 22px rgba(10,22,40,.08)}.db-hub strong{font-size:16px;font-weight:800}.db-hub span{font-size:14px;line-height:1.5;color:#4b5563}'
			. '@media(prefers-reduced-motion:reduce){.db-hub a{transition:none}}</style>';
	}
}

if ( ! function_exists( 'db_link_hub_path' ) ) {
	/** Path of the current request, relative to the site root. */
	function db_link_hub_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		return '' !== $home && 0 === strpos( $path, $home ) ? substr( $path, strlen( $home ) ) : $path;
	}
}

// Ordinary content pages (services, about, FAQs, guides pages) get the block under their content.
// Not on the home page, account, checkout, offer, seller or listing pages, which have their own flow.
add_filter( 'the_content', function ( $html ) {
	if ( is_admin() || ! is_page() || is_front_page() || ! in_the_loop() || ! is_main_query() ) {
		return $html;
	}
	$skip = array( 'all-domains', 'my-wishlist', 'checkout', 'buy-now', 'make-an-offer', 'payment-plan-setup', 'thank-you', 'sign-in', 'register', 'seller', 'sold' );
	if ( is_page( $skip ) || false !== strpos( (string) $html, 'class="db-hub"' ) ) {
		return $html;
	}
	return $html . db_link_hub_html( db_link_hub_path() );
}, 40 );
