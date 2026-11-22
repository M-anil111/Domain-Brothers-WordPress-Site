<?php
/**
 * Site header (desktop, 992px and up).
 *
 * Three zones in one slim bar: the logo on the left, the primary links with
 * mega-menu panels in the centre, and search plus the account actions on the
 * right. Below 992px the existing drawer and its toggle stay in charge.
 *
 * The panels are plain links in the page source (crawlable, usable without
 * JavaScript through :hover and :focus-within). The script only adds what CSS
 * cannot: aria-expanded, Escape to close, and tap-to-open on touch screens.
 *
 * The "Domains" panel is dynamic: a list (3 characters, numeric, sold, deals)
 * is offered only while it holds at least one name.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_header_panels' ) ) {
	/**
	 * The primary links and their panels.
	 *
	 * @return array
	 */
	function db_header_panels() {
		$u      = function ( $path ) {
			return home_url( $path );
		};
		$browse = array( array( 'All domains', $u( '/all-domains/' ), 'Every name we have for sale' ) );
		if ( function_exists( 'db_pages_listing_sets' ) && function_exists( 'db_pages_listing_counts' ) ) {
			$counts = db_pages_listing_counts();
			$labels = array(
				'3-character-domains'      => array( '3-character domains', 'The shortest names we list' ),
				'4-character-domains'      => array( '4-character domains', 'Short and easy to remember' ),
				'5-character-domains'      => array( '5-character domains', 'Room for a real word' ),
				'6-character-domains'      => array( '6-character domains', 'Brandable and still short' ),
				'numeric-domains-for-sale' => array( 'Numeric domains', 'Names made only of digits' ),
				'sold'                     => array( 'Sold domains', 'Names that found a new owner' ),
			);
			foreach ( $labels as $slug => $label ) {
				if ( isset( $counts[ $slug ] ) && $counts[ $slug ] > 0 ) {
					$browse[] = array( $label[0], $u( '/' . $slug . '/' ), $label[1] );
				}
			}
		}
		if ( '1' === (string) get_option( 'db_pages_deals_state', '' ) ) {
			$browse[] = array( 'Deals', $u( '/premium-domain-deals/' ), 'Reduced for a limited time' );
		}
		return array(
			array(
				'label' => 'Domains',
				'href'  => $u( '/all-domains/' ),
				'cols'  => array(
					array( 'Browse', $browse ),
					array(
						'Find and follow',
						array(
							array( 'Domain finder', $u( '/find-a-domain/' ), 'Describe the business, get matching names' ),
							array( 'Collections', $u( '/collections/' ), 'Names grouped by industry and use' ),
							array( 'My wishlist', $u( '/my-wishlist/' ), 'Saved names and price alerts' ),
							array( 'Payment terms', $u( '/payment-terms/' ), 'Card payments, plans and ownership' ),
						),
					),
				),
				'card'  => array( 'Want a name that is not listed?', 'Tell us the domain. A broker finds the owner and negotiates for you.', 'Acquire a domain', $u( '/acquire-premium-domain/' ) ),
			),
			array(
				'label' => 'Services',
				'href'  => $u( '/our-services/' ),
				'cols'  => array(
					array(
						'Domain services',
						array(
							( function_exists( 'db_seller_enabled' ) && db_seller_enabled() ) ? array( 'Sell your domains', $u( '/sell-your-domains/' ), 'List names free, pay only when they sell' ) : null,
							array( 'Domain brokerage', $u( '/domain-brokerage-services/' ), 'We negotiate the name you want' ),
							array( 'Domain management', $u( '/domain-management-services/' ), 'Renewals, DNS and security handled' ),
							array( 'Domain transfer', $u( '/domain-transfer/' ), 'Move a name between registrars' ),
						),
					),
					array(
						'Social handles',
						array(
							array( 'Acquire a handle', $u( '/acquire-social-media-handles/' ), 'Usernames to match your brand' ),
							array( 'Sell a handle', $u( '/sell-social-media-handles/' ), 'Find a buyer for a username' ),
						),
					),
					array(
						'Digital services',
						array(
							array( 'Website design', $u( '/website-design-development/' ), 'Sites built to convert' ),
							array( 'Digital marketing', $u( '/digital-marketing-services/' ), 'Search, ads and content' ),
							array( 'Software development', $u( '/custom-software-development-services/' ), 'Custom tools for your business' ),
							array( 'Mobile apps', $u( '/mobile-app-development-services/' ), 'iOS and Android' ),
							array( 'Hosting and maintenance', $u( '/web-hosting-maintenance-services/' ), 'Fast, patched and backed up' ),
						),
					),
				),
				'card'  => array( 'Not sure which service fits?', 'Tell us the goal. You get a straight answer within one business day.', 'Talk to a broker', $u( '/contact-us/' ) ),
			),
			array(
				'label' => 'About',
				'href'  => $u( '/about-us/' ),
				'cols'  => array(
					array(
						'Company',
						array(
							array( 'About us', $u( '/about-us/' ), 'Who we are and how we work' ),
							array( 'Our team', $u( '/our-team/' ), 'The brokers behind every deal' ),
							array( 'Trust center', $u( '/trust-center/' ), 'How payments and transfers are protected' ),
							array( 'Contact us', $u( '/contact-us/' ), 'A reply within one business day' ),
						),
					),
					array(
						'Learn',
						array(
							array( 'FAQs', $u( '/faqs/' ), 'Buying, selling, plans and transfers' ),
							array( 'Domain news', $u( '/news/' ), 'What is moving in the domain industry' ),
							array( 'Blog', $u( '/category/domain-guides/' ), 'Guides for buyers and sellers' ),
							array( 'EPP code guide', $u( '/epp-guidelines/' ), 'Get the transfer code at any registrar' ),
							array( 'Domain tools', $u( '/domain-tools/' ), 'Free lookup: registry, DNS and comparable sales' ),
						),
					),
					array(
						'Policies',
						array(
							array( 'Refund policy', $u( '/refund-policy/' ), 'When and how refunds apply' ),
							array( 'Privacy policy', $u( '/privacy-policy/' ), 'What we collect and why' ),
							array( 'Terms of use', $u( '/terms-of-use/' ), 'The rules for using this site' ),
							array( 'Cookie policy', $u( '/cookie-policy/' ), 'The cookies we use and how to control them' ),
							array( 'Acceptable use', $u( '/acceptable-use-policy/' ), 'What is not allowed on the site' ),
						),
					),
				),
				'card'  => array( 'Talk to a person', 'Questions about a name, a price or a transfer? Ask a broker directly.', 'Contact us', $u( '/contact-us/' ) ),
			),
		);
	}
}

if ( ! function_exists( 'db_header_icon' ) ) {
	/**
	 * A small line icon chosen from the link's label.
	 *
	 * @param string $label Link label.
	 * @return string SVG markup.
	 */
	function db_header_icon( $label ) {
		$paths = array(
			'globe'   => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.200 3 14.800 0 18M12 3c-3 3.200-3 14.800 0 18"/>',
			'hash'    => '<path d="M9 4L7 20M17 4l-2 16M4 9h17M3 15h17"/>',
			'tag'     => '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.500" cy="8.500" r="1.200"/>',
			'check'   => '<circle cx="12" cy="12" r="9"/><path d="M8 12.500l2.800 2.800L16 9.500"/>',
			'search'  => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.600-3.600"/>',
			'grid'    => '<rect x="4" y="4" width="7" height="7" rx="1.500"/><rect x="13" y="4" width="7" height="7" rx="1.500"/><rect x="4" y="13" width="7" height="7" rx="1.500"/><rect x="13" y="13" width="7" height="7" rx="1.500"/>',
			'heart'   => '<path d="M12 20s-7-4.400-8.600-8.800C2.300 8 4.200 5 7.300 5c1.900 0 3.200 1 4.700 2.800C13.500 6 14.800 5 16.700 5c3.100 0 5 3 3.900 6.200C19 15.600 12 20 12 20z"/>',
			'card'    => '<rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18M7 15h4"/>',
			'chat'    => '<path d="M4 6h16v11H9l-5 4z"/><path d="M8 10h8M8 13h5"/>',
			'chart'   => '<path d="M4 20V10M10 20V4M16 20v-7M21 20H3"/>',
			'shield'  => '<path d="M12 3l8 3v6c0 5-3.400 8-8 9.500C7.400 20 4 17 4 12V6z"/><path d="M9 12l2.200 2.200L15 10"/>',
			'swap'    => '<path d="M4 9h15M15 5l4 4-4 4M20 15H5M9 11l-4 4 4 4"/>',
			'at'      => '<circle cx="12" cy="12" r="4"/><path d="M16 12v1.500a2.500 2.500 0 005 0V12a9 9 0 10-3.500 7.100"/>',
			'monitor' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>',
			'horn'    => '<path d="M4 10v4h3l8 5V5L7 10z"/><path d="M18 9a4 4 0 010 6"/>',
			'code'    => '<path d="M8 8l-4 4 4 4M16 8l4 4-4 4M13.500 5l-3 14"/>',
			'phone'   => '<rect x="7" y="3" width="10" height="18" rx="2"/><path d="M11 18h2"/>',
			'server'  => '<rect x="4" y="4" width="16" height="6" rx="1.500"/><rect x="4" y="14" width="16" height="6" rx="1.500"/><path d="M8 7h.01M8 17h.01"/>',
			'users'   => '<circle cx="9" cy="9" r="3.500"/><path d="M2.500 20c.600-3.600 3.200-5.500 6.500-5.500s5.900 1.900 6.500 5.500M16 5.700a3.500 3.500 0 010 6.600M18 14.800c2 .700 3.200 2.500 3.500 5.200"/>',
			'mail'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
			'info'    => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7.500h.01"/>',
			'help'    => '<circle cx="12" cy="12" r="9"/><path d="M9.500 9.500a2.500 2.500 0 114 2c-.900.700-1.500 1.200-1.500 2.500M12 17.500h.01"/>',
			'news'    => '<path d="M4 5h13v14H6a2 2 0 01-2-2zM17 9h3v8a2 2 0 01-2 2"/><path d="M7.500 9h6M7.500 12.500h6M7.500 16h3"/>',
			'book'    => '<path d="M4 5.500C6.500 4.500 9.500 4.500 12 6c2.500-1.500 5.500-1.500 8-.500V19c-2.500-1-5.500-1-8 .500-2.500-1.500-5.500-1.500-8-.500z"/><path d="M12 6v13.500"/>',
			'key'     => '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>',
			'doc'     => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
		);
		$map   = array(
			'all domains' => 'globe', 'character' => 'hash', 'numeric' => 'hash', 'sold' => 'check', 'deals' => 'tag', 'finder' => 'search', 'collections' => 'grid',
			'wishlist' => 'heart', 'payment' => 'card', 'brokerage' => 'chat', 'appraisal' => 'chart', 'management' => 'shield', 'sell a domain' => 'tag', 'transfer' => 'swap',
			'handle' => 'at', 'website' => 'monitor', 'marketing' => 'horn', 'software' => 'code', 'mobile' => 'phone', 'hosting' => 'server', 'team' => 'users',
			'contact' => 'mail', 'about' => 'info', 'trust' => 'shield', 'faq' => 'help', 'news' => 'news', 'blog' => 'book', 'epp' => 'key', 'policy' => 'doc', 'terms' => 'doc',
		);
		$key   = 'globe';
		$label = strtolower( $label );
		foreach ( $map as $needle => $icon ) {
			if ( false !== strpos( $label, $needle ) ) {
				$key = $icon;
				break;
			}
		}
		return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.700" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[ $key ] . '</svg>';
	}
}

if ( ! function_exists( 'db_header_html' ) ) {
	/**
	 * Header markup.
	 *
	 * @return string
	 */
	function db_header_html() {
		$home    = esc_url( home_url( '/' ) );
		$logo    = function_exists( 'db_brand_logo_url' ) ? db_brand_logo_url() : home_url( '/wp-content/uploads/2017/03/Domain-Brothers.png' );
		$chevron = '<svg viewBox="0 0 12 12" width="10" height="10" aria-hidden="true"><path d="M2 4.200l4 4 4-4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
		$h       = '<header id="db-hd" class="db-hd"><div class="db-hd-in">'
			. '<a class="db-hd-logo" href="' . $home . '" aria-label="Domain Brothers home"><img src="' . esc_url( $logo ) . '" alt="Domain Brothers" width="200" height="54" decoding="async" fetchpriority="high"></a>'
			. '<nav class="db-hd-nav" aria-label="Primary"><ul>';
		foreach ( db_header_panels() as $i => $panel ) {
			$id = 'db-hd-p' . ( $i + 1 );
			$h .= '<li class="db-hd-item"><a class="db-hd-top" href="' . esc_url( $panel['href'] ) . '" aria-expanded="false" aria-controls="' . $id . '">' . esc_html( $panel['label'] ) . $chevron . '</a>'
				. '<div class="db-hd-mega" id="' . $id . '"><div class="db-hd-mega-in">';
			foreach ( $panel['cols'] as $col ) {
				$h .= '<div class="db-hd-col"><p class="db-hd-h">' . esc_html( $col[0] ) . '</p><ul>';
				foreach ( array_filter( $col[1] ) as $link ) {
					$out = 0 !== strpos( $link[1], home_url( '/' ) );
					$h  .= '<li><a href="' . esc_url( $link[1] ) . '"' . ( $out ? ' target="_blank" rel="noopener"' : '' ) . '><i class="db-hd-ic">' . db_header_icon( $link[0] ) . '</i><span class="db-hd-tx"><strong>' . esc_html( $link[0] ) . '</strong><span>' . esc_html( $link[2] ) . '</span></span></a></li>';
				}
				$h .= '</ul></div>';
			}
			$card = $panel['card'];
			$h   .= '<div class="db-hd-card"><p class="db-hd-card-t">' . esc_html( $card[0] ) . '</p><p>' . esc_html( $card[1] ) . '</p><a class="db-hd-card-b" href="' . esc_url( $card[3] ) . '">' . esc_html( $card[2] ) . ' <span aria-hidden="true">&rarr;</span></a></div>'
				. '</div></div></li>';
		}
		$h .= '</ul></nav><div class="db-hd-right">'
			. '<form class="db-hd-search" role="search" method="get" action="' . $home . '" data-hints="' . esc_url( add_query_arg( 'ver', DB_BLOCKS_LOADED, rest_url( 'db/v1/search-hints' ) ) ) . '" data-index="' . esc_url( add_query_arg( 'ver', DB_BLOCKS_LOADED, rest_url( 'db/v1/domain-index' ) ) ) . '">'
			. '<label class="screen-reader-text" for="db-hd-q">Search domains</label>'
			. '<span class="db-hd-sicon" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="M20 20l-3.600-3.600" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg></span>'
			. '<input id="db-hd-q" type="search" name="s" placeholder="Search domains" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="db-hd-sp" enterkeyhint="search">'
			. '<input type="hidden" name="post_type" value="domain">'
			. '<kbd class="db-hd-kbd" aria-hidden="true">/</kbd>'
			. '<button type="submit" class="db-hd-sgo" aria-label="Search">Search</button>'
			. '<div class="db-hd-sp" id="db-hd-sp" hidden><div class="db-hd-sp-in" data-sp-body><p class="db-hd-sp-load">Loading popular names…</p></div>'
			. '<p class="db-hd-sp-foot"><span><kbd>↑</kbd><kbd>↓</kbd> to move, <kbd>Enter</kbd> to open</span><a href="' . esc_url( home_url( '/find-a-domain/' ) ) . '">Not sure? Try the domain finder <span aria-hidden="true">&rarr;</span></a></p></div>'
			. '</form>'
			. db_header_account_html()
			. '</div></div></header>';
		return $h;
	}
}

if ( ! function_exists( 'db_acct_menu_links' ) ) {
	/**
	 * The account sections, shared by the header dropdown and the mobile drawer.
	 *
	 * @return array<int,array{0:string,1:string,2:string}> label, url, icon key
	 */
	function db_acct_menu_links() {
		$page  = home_url( '/my-wishlist/' );
		$links = array(
			array( 'Wishlist', $page, 'heart' ),
			array( 'Alerts', add_query_arg( 'tab', 'alerts', $page ), 'bell' ),
			array( 'My offers', add_query_arg( 'tab', 'offers', $page ), 'chat' ),
			( function_exists( 'db_acct_plans_for' ) && function_exists( 'db_wl_email' ) && db_acct_plans_for( db_wl_email() ) ) ? array( 'Payment plans', add_query_arg( 'tab', 'plans', $page ), 'card' ) : null,
			array( 'Transfers', add_query_arg( 'tab', 'transfers', $page ), 'swap' ),
			array( 'Discover', add_query_arg( 'tab', 'discover', $page ), 'spark' ),
			array( 'Profile', add_query_arg( 'tab', 'profile', $page ), 'user' ),
		);
		$links = array_values( array_filter( $links ) );
		if ( function_exists( 'db_seller_enabled' ) && db_seller_enabled() ) {
			$links[] = array( 'Seller dashboard', home_url( '/seller/' ), 'tag' );
		}
		return $links;
	}
}

if ( ! function_exists( 'db_acct_signout_url' ) ) {
	/** A sign-out link that works before any script has loaded. */
	function db_acct_signout_url() {
		return add_query_arg( 'db_signout', wp_create_nonce( 'db_signout' ), home_url( '/' ) );
	}
}

add_action( 'template_redirect', function () {
	if ( ! isset( $_GET['db_signout'] ) || ! function_exists( 'db_wl_end_session' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['db_signout'] ) ), 'db_signout' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		db_wl_end_session();
	}
	nocache_headers();
	wp_safe_redirect( home_url( '/' ) );
	exit;
}, 1 );

if ( ! function_exists( 'db_acct_icon' ) ) {
	function db_acct_icon( $key ) {
		$p = array(
			'heart' => '<path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.600-7 10-7 10z"/>',
			'bell'  => '<path d="M6 16V11a6 6 0 1 1 12 0v5l2 2H4z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
			'chat'  => '<path d="M4 5h16v11H9l-5 4z"/>',
			'swap'  => '<path d="M4 8h14l-3-3M20 16H6l3 3"/>',
			'spark' => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M6 6l2.500 2.500M15.500 15.500L18 18M6 18l2.500-2.500M15.500 8.500L18 6"/>',
			'user'  => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
			'tag'   => '<path d="M3 12V3h9l9 9-9 9z"/><circle cx="7.500" cy="7.500" r="1.500"/>',
			'card'  => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
			'out'   => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11"/>',
		);
		return '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.900" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $p[ $key ] ?? '' ) . '</svg>';
	}
}

if ( ! function_exists( 'db_header_account_html' ) ) {
	/**
	 * Signed out: Sign in and Create account. Signed in: one "My account" button
	 * with a dropdown of the account sections and Sign out at the bottom.
	 * Signed-in pages bypass the page cache, so the state is read here.
	 */
	function db_header_account_html() {
		$email = function_exists( 'db_wl_email' ) ? db_wl_email() : '';
		if ( '' === $email ) {
			return '<a class="db-hd-link" href="' . esc_url( home_url( '/sign-in/' ) ) . '" data-href="' . esc_url( home_url( '/sign-in/' ) ) . '" data-db-acct="signin">Sign in</a>'
				. '<a class="db-hd-cta" href="' . esc_url( home_url( '/register/' ) ) . '" data-db-acct="register">Create account</a>';
		}
		$row     = function_exists( 'db_acct_row' ) ? db_acct_row( $email ) : null;
		$name    = trim( (string) ( $row['full_name'] ?? '' ) );
		$first   = '' !== $name ? strtok( $name, ' ' ) : '';
		$initial = strtoupper( mb_substr( '' !== $name ? $name : $email, 0, 1 ) );
		$unread  = 0;
		global $wpdb;
		if ( function_exists( 'db_acct_time_ago' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$unread = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}db_wl_notes WHERE email = %s AND read_at IS NULL", $email ) );
		}
		$h  = '<div class="db-hd-acct">'
			. '<button type="button" class="db-hd-acct-btn" aria-expanded="false" aria-controls="db-hd-acct-menu"><span class="db-hd-av" aria-hidden="true">' . esc_html( $initial ) . '</span><span class="db-hd-acct-l">' . ( '' !== $first ? esc_html( $first ) : 'My account' ) . '</span>'
			. ( $unread ? '<span class="db-hd-dot"><span class="screen-reader-text">, ' . (int) $unread . ' new alerts</span></span>' : '' )
			. '<svg class="db-hd-chev" viewBox="0 0 12 12" width="12" height="12" aria-hidden="true"><path d="M2.500 4.500 6 8l3.500-3.500" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round"/></svg></button>'
			. '<div class="db-hd-acct-menu" id="db-hd-acct-menu">'
			. '<div class="db-hd-acct-who"><span class="db-hd-av db-hd-av--lg" aria-hidden="true">' . esc_html( $initial ) . '</span><span><strong>' . ( '' !== $name ? esc_html( $name ) : 'Signed in' ) . '</strong><span>' . esc_html( $email ) . '</span></span></div>'
			. '<a class="db-hd-acct-main" href="' . esc_url( home_url( '/my-wishlist/' ) ) . '">My account overview <span aria-hidden="true">&rarr;</span></a><ul>';
		foreach ( db_acct_menu_links() as $l ) {
			$badge = ( 'Alerts' === $l[0] && $unread ) ? '<span class="db-hd-badge">' . (int) $unread . ' new</span>' : '';
			$h    .= '<li><a href="' . esc_url( $l[1] ) . '"><i>' . db_acct_icon( $l[2] ) . '</i><span>' . esc_html( $l[0] ) . '</span>' . $badge . '</a></li>';
		}
		$h .= '</ul><a class="db-hd-acct-out" href="' . esc_url( db_acct_signout_url() ) . '"><i>' . db_acct_icon( 'out' ) . '</i><span>Sign out</span></a></div></div>';
		return $h;
	}
}

if ( ! function_exists( 'db_header_search_hints' ) ) {
	/**
	 * What the search panel shows before anything is typed: the most viewed
	 * available names this week, the newest listings and the main categories.
	 * Cached for an hour; available names only (from the domain index).
	 */
	function db_header_search_hints() {
		$c = get_transient( 'db_search_hints' );
		if ( is_array( $c ) ) {
			return $c;
		}
		$index = function_exists( 'db_domain_index' ) ? (array) db_domain_index() : array();
		$by_id = array();
		foreach ( $index as $r ) {
			$by_id[ (int) $r['id'] ] = $r;
		}
		$pick = static function ( $r ) {
			return array( 'n' => (string) $r['n'], 'u' => (string) $r['u'], 'p' => (int) ( $r['p'] ?? 0 ) );
		};
		$popular = array();
		if ( function_exists( 'db_hits_query' ) && $by_id ) {
			$res = db_hits_query( '', 'd7', 'desc', 40, 0 );
			foreach ( (array) $res['rows'] as $row ) {
				$id = (int) $row->ID;
				if ( isset( $by_id[ $id ] ) && (int) $row->d7_h > 0 ) {
					$popular[] = $pick( $by_id[ $id ] );
				}
				if ( count( $popular ) >= 6 ) {
					break;
				}
			}
		}
		if ( count( $popular ) < 6 ) {
			$priced = array_filter( $index, static function ( $r ) { return (int) ( $r['p'] ?? 0 ) > 0; } );
			usort( $priced, static function ( $a, $b ) { return (int) $b['p'] <=> (int) $a['p']; } );
			foreach ( $priced as $r ) {
				if ( count( $popular ) >= 6 ) {
					break;
				}
				if ( ! in_array( $r['n'], array_column( $popular, 'n' ), true ) ) {
					$popular[] = $pick( $r );
				}
			}
		}
		$ids = array_keys( $by_id );
		rsort( $ids );
		$newest = array();
		foreach ( array_slice( $ids, 0, 12 ) as $id ) {
			if ( ! in_array( $by_id[ $id ]['n'], array_column( $popular, 'n' ), true ) ) {
				$newest[] = $pick( $by_id[ $id ] );
			}
			if ( count( $newest ) >= 4 ) {
				break;
			}
		}
		$cats = array();
		foreach ( $index as $r ) {
			foreach ( (array) ( $r['c'] ?? array() ) as $cat ) {
				$k = (string) $cat[0];
				if ( ! isset( $cats[ $k ] ) ) {
					$cats[ $k ] = array( 'n' => $k, 'u' => (string) $cat[1], 'c' => 0 );
				}
				$cats[ $k ]['c']++;
			}
		}
		uasort( $cats, static function ( $a, $b ) { return $b['c'] <=> $a['c']; } );
		$out = array(
			'popular' => $popular,
			'newest'  => $newest,
			'cats'    => array_values( array_slice( $cats, 0, 8 ) ),
			'total'   => count( $index ),
			'all'     => home_url( '/all-domains/' ),
		);
		if ( db_on_canonical_host() ) {
			set_transient( 'db_search_hints', $out, HOUR_IN_SECONDS );
		}
		return $out;
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/account-menu', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$signed = function_exists( 'db_wl_email' ) && '' !== db_wl_email();
			$res    = new WP_REST_Response( array( 'signedIn' => $signed, 'html' => $signed ? db_header_account_html() : '' ), 200 );
			$res->header( 'Cache-Control', 'private, no-store, max-age=0' );
			return $res;
		},
	) );
	register_rest_route( 'db/v1', '/search-hints', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$res = new WP_REST_Response( db_header_search_hints(), 200 );
			$res->header( 'Cache-Control', 'public, max-age=600' );
			return $res;
		},
	) );
} );
add_action( 'updated_post_meta', function ( $mid, $pid, $key ) {
	if ( in_array( $key, array( 'domain_price', 'domain_status', 'db_listing_state' ), true ) ) {
		delete_transient( 'db_search_hints' );
	}
}, 10, 3 );
add_action( 'save_post_domain', function () {
	delete_transient( 'db_search_hints' );
} );


if ( ! function_exists( 'db_header_print' ) ) {
	function db_header_print() {
		static $done = false;
		if ( $done || is_admin() || is_customize_preview() ) {
			return;
		}
		$done = true;
		echo db_header_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}
}
add_action( 'wp_body_open', 'db_header_print', 1 );
add_action( 'wp_footer', 'db_header_print', 1 ); // themes that never call wp_body_open()

add_action( 'wp_footer', function () {
	if ( is_admin() || is_customize_preview() ) {
		return;
	}
	?>
<script>
(function () {
	var hd = document.getElementById('db-hd');
	if (!hd) { return; }
	if (hd.parentNode !== document.body || hd !== document.body.firstElementChild) { document.body.insertBefore(hd, document.body.firstChild); }
	var items = Array.prototype.slice.call(hd.querySelectorAll('.db-hd-item'));
	function set(item, open) {
		item.classList.toggle('is-open', open);
		var a = item.querySelector('.db-hd-top'); if (a) { a.setAttribute('aria-expanded', open ? 'true' : 'false'); }
	}
	function closeAll(except) { items.forEach(function (it) { if (it !== except) { set(it, false); } }); }
	items.forEach(function (item) {
		var top = item.querySelector('.db-hd-top'), timer;
		item.addEventListener('mouseenter', function () { clearTimeout(timer); closeAll(item); set(item, true); });
		item.addEventListener('mouseleave', function () { timer = setTimeout(function () { set(item, false); }, 140); });
		item.addEventListener('focusin', function () { closeAll(item); set(item, true); });
		item.addEventListener('focusout', function (e) { if (!item.contains(e.relatedTarget)) { set(item, false); } });
		// Touch: the first tap opens the panel, the second follows the link.
		top.addEventListener('touchend', function (e) { if (!item.classList.contains('is-open')) { e.preventDefault(); closeAll(item); set(item, true); } });
	});
	document.addEventListener('keydown', function (e) {
		if (e.key !== 'Escape') { return; }
		items.forEach(function (it) { if (it.classList.contains('is-open')) { set(it, false); var a = it.querySelector('.db-hd-top'); if (a && it.contains(document.activeElement)) { a.blur(); } } });
	});
	document.addEventListener('touchstart', function (e) { if (!hd.contains(e.target)) { closeAll(null); } }, { passive: true });
	var onScroll = function () { hd.classList.toggle('is-stuck', window.scrollY > 8); };
	onScroll(); window.addEventListener('scroll', onScroll, { passive: true });
}());
</script>
	<?php
}, 80 );

add_action( 'wp_footer', function () {
	if ( is_admin() || is_customize_preview() ) {
		return;
	}
	$home = esc_url( home_url( '/' ) );
	echo '<button type="button" class="db-ms-btn" aria-label="Search domains" aria-expanded="false" aria-controls="db-ms"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2.200"/><path d="M20 20l-3.600-3.600" fill="none" stroke="currentColor" stroke-width="2.200" stroke-linecap="round"/></svg></button>'
		. '<div class="db-ms" id="db-ms" role="dialog" aria-modal="true" aria-label="Search domains" hidden><div class="db-ms-in">'
		. '<form class="db-ms-form" role="search" method="get" action="' . $home . '" data-hints="' . esc_url( add_query_arg( 'ver', DB_BLOCKS_LOADED, rest_url( 'db/v1/search-hints' ) ) ) . '" data-index="' . esc_url( add_query_arg( 'ver', DB_BLOCKS_LOADED, rest_url( 'db/v1/domain-index' ) ) ) . '">'
		. '<div class="db-ms-bar"><label class="screen-reader-text" for="db-ms-q">Search domains</label><input id="db-ms-q" type="search" name="s" placeholder="Search premium domains" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="db-ms-sp" enterkeyhint="search"><input type="hidden" name="post_type" value="domain">'
		. '<button type="button" class="db-ms-close">Cancel</button></div>'
		. '<div class="db-hd-sp" id="db-ms-sp"><div class="db-hd-sp-in" data-sp-body><p class="db-hd-sp-load">Loading popular names…</p></div></div>'
		. '</form></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
}, 79 );

add_action( 'wp_footer', function () {
	if ( is_admin() || is_customize_preview() ) {
		return;
	}
	?>
<script data-no-delay>
(function () {
	/* Account dropdown: click or keyboard toggles, Escape and outside clicks close. */
	var wire = function (ac) {
		var btn = ac.querySelector('.db-hd-acct-btn'), menu = ac.querySelector('.db-hd-acct-menu'), hoverT;
		var setA = function (open) { ac.classList.toggle('is-open', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false'); };
		var hoverAt = 0;
		btn.addEventListener('click', function () { if (Date.now() - hoverAt < 600) { setA(true); return; } setA(!ac.classList.contains('is-open')); });
		ac.addEventListener('mouseenter', function () { if (window.matchMedia('(hover: hover)').matches) { clearTimeout(hoverT); if (!ac.classList.contains('is-open')) { hoverAt = Date.now(); } setA(true); } });
		ac.addEventListener('mouseleave', function () { if (window.matchMedia('(hover: hover)').matches) { hoverT = setTimeout(function () { setA(false); }, 160); } });
		ac.addEventListener('focusout', function (e) { if (!ac.contains(e.relatedTarget)) { setA(false); } });
		document.addEventListener('click', function (e) { if (!ac.contains(e.target)) { setA(false); } });
		ac.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && ac.classList.contains('is-open')) { setA(false); btn.focus(); }
			if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && ac.classList.contains('is-open')) {
				var links = [].slice.call(menu.querySelectorAll('a')), i = links.indexOf(document.activeElement);
				e.preventDefault(); i = e.key === 'ArrowDown' ? (i + 1) % links.length : (i <= 0 ? links.length - 1 : i - 1); links[i].focus();
			}
		});
	};
	var ac0 = document.querySelector('.db-hd-acct');
	if (ac0) { wire(ac0); }
	/* A cached signed-out page reached a signed-in visitor: swap in the account menu. */
	var loading = false;
	window.dbHdAcctLoad = function () {
		if (loading || document.querySelector('.db-hd-acct')) { return; }
		var right = document.querySelector('.db-hd-right'), si = right && right.querySelector('[data-db-acct=signin]');
		if (!si) { return; }
		loading = true;
		fetch('<?php echo esc_url_raw( rest_url( 'db/v1/account-menu' ) ); ?>', { credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) {
			if (!j || !j.signedIn || !j.html || document.querySelector('.db-hd-acct')) { return; }
			var reg = right.querySelector('[data-db-acct=register]');
			var tmp = document.createElement('div'); tmp.innerHTML = j.html;
			var el = tmp.firstElementChild; si.parentNode.replaceChild(el, si); if (reg) { reg.remove(); }
			wire(el);
		}).catch(function () {});
	};
	if (/(?:^|;\s*)db_in=1/.test(document.cookie)) { window.dbHdAcctLoad(); }

	/* Search panel: popular and new names on focus, live matches as you type. */
	var hints = null, index = null, uid = 0;
	var initSearch = function (f, sheet) {
	var q = f.querySelector('input[type=search]'), panel = f.querySelector('.db-hd-sp'), body = panel.querySelector('[data-sp-body]');
	var active = -1, timer, pre = sheet ? 'db-ms-o' : 'db-hd-o';
	var money = function (p) { return p > 0 ? '$' + Number(p).toLocaleString('en-US') : 'Make an offer'; };
	var esc = function (t) { return String(t).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
	var get = function (url) { return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); };
	var loadHints = function () { return hints ? Promise.resolve(hints) : get(f.getAttribute('data-hints')).then(function (j) { hints = j || {}; return hints; }).catch(function () { hints = {}; return hints; }); };
	var loadIndex = function () { return index ? Promise.resolve(index) : get(f.getAttribute('data-index')).then(function (j) { index = (j || []).map(function (r) { return { n: String(r.n || ''), u: r.u, p: +r.p || 0, k: String(r.k || '').toLowerCase(), c: (r.c || []).map(function (c) { return String(c[0]).toLowerCase(); }).join(' ') }; }); return index; }).catch(function () { index = []; return index; }); };
	var items = function () { return [].slice.call(body.querySelectorAll('a')); };
	var setActive = function (i) {
		var list = items(); list.forEach(function (a) { a.classList.remove('is-active'); a.removeAttribute('aria-selected'); });
		active = list.length ? (i + list.length) % list.length : -1;
		if (active >= 0) { list[active].classList.add('is-active'); list[active].setAttribute('aria-selected', 'true'); list[active].scrollIntoView({ block: 'nearest' }); q.setAttribute('aria-activedescendant', list[active].id); }
		else { q.removeAttribute('aria-activedescendant'); }
	};
	var link = function (r, cls) { uid++; return '<li role="none"><a id="' + pre + uid + '" role="option" href="' + esc(r.u) + '"' + (cls ? ' class="' + cls + '"' : '') + '>'; };
	var renderHints = function (h) {
		var html = '';
		if (h.popular && h.popular.length) {
			html += '<section><h3><span class="db-hd-live" aria-hidden="true"></span>Popular this week</h3><ul class="db-hd-sp-grid" role="listbox" aria-label="Popular domains">';
			h.popular.forEach(function (r) { html += link(r) + '<b>' + esc(r.n) + '</b><span>' + money(r.p) + '</span></a></li>'; });
			html += '</ul></section>';
		}
		if (h.newest && h.newest.length) {
			html += '<section><h3>Just listed</h3><ul class="db-hd-sp-list" role="listbox" aria-label="New domains">';
			h.newest.forEach(function (r) { html += link(r) + '<b>' + esc(r.n) + '</b><span>' + money(r.p) + '</span></a></li>'; });
			html += '</ul></section>';
		}
		if (h.cats && h.cats.length) {
			html += '<section><h3>Browse by category</h3><ul class="db-hd-sp-chips" role="listbox" aria-label="Categories">';
			h.cats.forEach(function (c) { html += link(c) + esc(c.n) + ' <small>' + c.c + '</small></a></li>'; });
			html += '</ul></section>';
		}
		if (h.all) { html += '<a class="db-hd-sp-all" href="' + esc(h.all) + '">See all ' + (h.total || '') + ' available domains</a>'; }
		body.innerHTML = html || '<p class="db-hd-sp-load">Type a word to search our domains.</p>';
		active = -1;
	};
	var mark = function (name, term) {
		var i = name.toLowerCase().indexOf(term);
		return i < 0 ? esc(name) : esc(name.slice(0, i)) + '<mark>' + esc(name.slice(i, i + term.length)) + '</mark>' + esc(name.slice(i + term.length));
	};
	var renderResults = function (term) {
		var t = term.toLowerCase().replace(/^https?:\/\//, '').replace(/^www\./, '').trim();
		var bare = t.replace(/\.[a-z.]*$/, '');
		var scored = [];
		index.forEach(function (r) {
			var n = r.n.toLowerCase(), s = -1;
			if (n === t) { s = 0; } else if (n.indexOf(t) === 0) { s = 1; } else if (n.indexOf(t) > 0) { s = 2; } else if (bare && r.k.indexOf(bare) >= 0) { s = 3; } else if (r.c.indexOf(t) >= 0) { s = 4; }
			if (s >= 0) { scored.push([s, n.length, r]); }
		});
		scored.sort(function (a, b) { return a[0] - b[0] || a[1] - b[1]; });
		var top = scored.slice(0, 8), html = '';
		if (top.length) {
			html += '<section><h3><span class="db-hd-live" aria-hidden="true"></span>' + scored.length + ' match' + (scored.length === 1 ? '' : 'es') + ' for “' + esc(term) + '”</h3><ul class="db-hd-sp-list" role="listbox" aria-label="Matching domains">';
			top.forEach(function (x) { html += link(x[2]) + '<b>' + mark(x[2].n, t) + '</b><span>' + money(x[2].p) + '</span></a></li>'; });
			html += '</ul></section>';
			if (scored.length > top.length) { html += '<a class="db-hd-sp-all" href="' + esc(f.action + '?s=' + encodeURIComponent(term) + '&post_type=domain') + '">See all ' + scored.length + ' results</a>'; }
		} else {
			html += '<p class="db-hd-sp-empty">No listed name matches “' + esc(term) + '” yet. Try a shorter word, <a href="' + esc((hints && hints.all) || '/all-domains/') + '">browse every domain</a>, or ask us to find one with the <a href="/find-a-domain/">domain finder</a>.</p>';
			if (hints && hints.popular && hints.popular.length) {
				html += '<section><h3>Popular right now</h3><ul class="db-hd-sp-grid" role="listbox" aria-label="Popular domains">';
				hints.popular.slice(0, 4).forEach(function (r) { html += link(r) + '<b>' + esc(r.n) + '</b><span>' + money(r.p) + '</span></a></li>'; });
				html += '</ul></section>';
			}
		}
		body.innerHTML = html;
		active = -1;
	};
	var update = function () {
		var term = q.value.trim();
		if (term.length < 2) { loadHints().then(function (h) { if (q.value.trim().length < 2) { renderHints(h); } }); return; }
		Promise.all([loadIndex(), loadHints()]).then(function () { if (q.value.trim() === term) { renderResults(term); } });
	};
	var open = function () { if (!panel.hidden && body.children.length && !body.querySelector('.db-hd-sp-load')) { return; } panel.hidden = false; f.classList.add('is-open'); q.setAttribute('aria-expanded', 'true'); update(); loadIndex(); };
	var close = function () { if (sheet) { return; } panel.hidden = true; f.classList.remove('is-open'); q.setAttribute('aria-expanded', 'false'); q.removeAttribute('aria-activedescendant'); active = -1; };
	q.addEventListener('focus', open);
	q.addEventListener('click', open);
	q.addEventListener('input', function () { open(); clearTimeout(timer); timer = setTimeout(update, 90); });
	q.addEventListener('keydown', function (e) {
		if (e.key === 'ArrowDown') { e.preventDefault(); open(); setActive(active + 1); }
		else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
		else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); items()[active].click(); }
		else if (e.key === 'Escape' && !sheet) { close(); q.blur(); }
	});
	if (!sheet) {
		f.addEventListener('focusout', function (e) { if (!f.contains(e.relatedTarget)) { setTimeout(function () { if (!f.contains(document.activeElement)) { close(); } }, 120); } });
		document.addEventListener('mousedown', function (e) { if (!f.contains(e.target)) { close(); } });
		document.addEventListener('keydown', function (e) {
			var tag = (document.activeElement && document.activeElement.tagName) || '';
			if (e.key === '/' && !/INPUT|TEXTAREA|SELECT/.test(tag) && !(document.activeElement && document.activeElement.isContentEditable)) { e.preventDefault(); q.focus(); }
		});
	}
	f.addEventListener('submit', function (e) { if (!q.value.trim()) { e.preventDefault(); q.focus(); } });
	return { open: open, q: q };
	};
	var fd = document.querySelector('.db-hd .db-hd-search');
	if (fd) { initSearch(fd, false); }
	/* Phone: a search button next to the menu opens a full-screen search sheet. */
	var ms = document.getElementById('db-ms'), mb = document.querySelector('.db-ms-btn');
	if (ms && mb) {
		var api = initSearch(ms.querySelector('form'), true), lastFocus = null;
		var setSheet = function (on) {
			ms.hidden = !on; mb.setAttribute('aria-expanded', on ? 'true' : 'false');
			document.documentElement.classList.toggle('db-ms-on', on);
			if (on) { lastFocus = document.activeElement; api.open(); setTimeout(function () { api.q.focus(); }, 30); } else if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
		};
		mb.addEventListener('click', function () { setSheet(ms.hidden); });
		ms.querySelector('.db-ms-close').addEventListener('click', function () { setSheet(false); });
		document.addEventListener('keydown', function (e) {
			if (ms.hidden) { return; }
			if (e.key === 'Escape') { setSheet(false); return; }
			if (e.key === 'Tab') {
				var f2 = [].slice.call(ms.querySelectorAll('a[href],button,input,[tabindex]:not([tabindex="-1"])')).filter(function (x) { return x.offsetParent !== null; });
				if (!f2.length) { return; }
				if (e.shiftKey && document.activeElement === f2[0]) { e.preventDefault(); f2[f2.length - 1].focus(); }
				else if (!e.shiftKey && document.activeElement === f2[f2.length - 1]) { e.preventDefault(); f2[0].focus(); }
			}
		});
	}

}());
</script>
	<?php
}, 81 );

add_action( 'wp_enqueue_scripts', function () {
	$css = <<<'CSS'
.db-hd { display: none; }
@media (min-width: 992px) {
	body.db-ui-active #masthead, body.db-ui-active #db-desktop-nav, body.db-ui-active .db-mnav-toggle { display: none !important; }
	.db-hd { display: block; position: fixed; top: 0; left: 0; right: 0; z-index: 100000; height: 72px; background: rgba(255,255,255,.82); -webkit-backdrop-filter: saturate(180%) blur(18px); backdrop-filter: saturate(180%) blur(18px); border-bottom: 1px solid transparent; transition: border-color .2s ease, background .2s ease; }
	.db-hd.is-stuck, .db-hd:has(.is-open) { background: rgba(255,255,255,.96); border-bottom-color: rgba(17,24,39,.1); }
	body.admin-bar .db-hd { top: 32px; }
	.db-hd-in { display: grid; grid-template-columns: minmax(210px, 1fr) auto minmax(0, 1fr); align-items: center; gap: 24px; height: 100%; max-width: 1360px; margin: 0 auto; padding: 0 clamp(20px, 3vw, 40px); }
	html body .db-hd .db-hd-logo { display: inline-flex !important; align-items: center; justify-self: start; line-height: 0; border-radius: 8px; visibility: visible !important; opacity: 1 !important; }
	html body .db-hd .db-hd-logo img { display: block !important; width: 200px !important; min-width: 200px; height: 54px !important; max-width: none !important; object-fit: contain; }
	.db-hd-nav > ul { display: flex; align-items: center; gap: 4px; margin: 0; padding: 0; list-style: none; }
	.db-hd-item { position: static; margin: 0; }
	html body .db-hd .db-hd-top { display: inline-flex; align-items: center; gap: 7px; height: 72px; padding: 0 18px; color: #111827 !important; font-size: 15px; font-weight: 600; letter-spacing: -.005em; text-decoration: none !important; box-shadow: none !important; background: transparent !important; border-radius: 0; opacity: .82; transition: opacity .15s ease; }
	html body .db-hd .db-hd-top:hover, html body .db-hd .db-hd-item.is-open .db-hd-top { opacity: 1; color: #111827 !important; background: transparent !important; }
	.db-hd-top svg { transition: transform .2s ease; opacity: .6; }
	.db-hd-item.is-open .db-hd-top svg, .db-hd-item:focus-within .db-hd-top svg { transform: rotate(180deg); }
	.db-hd-mega { position: absolute; top: 100%; left: 0; right: 0; visibility: hidden; opacity: 0; transform: translateY(-6px); background: #fff !important; border-bottom: 1px solid rgba(17,24,39,.1); box-shadow: 0 40px 60px -30px rgba(17,24,39,.35); transition: opacity .18s ease, transform .18s ease, visibility 0s linear .18s; }
	.db-hd-item.is-open .db-hd-mega, .db-hd-item:focus-within .db-hd-mega, .db-hd-item:hover .db-hd-mega { visibility: visible; opacity: 1; transform: none; transition-delay: 0s; }
	html body .db-hd .db-hd-mega-in { background: #fff !important; display: flex; gap: clamp(24px, 4vw, 56px); max-width: 1360px; margin: 0 auto; padding: 34px clamp(20px, 3vw, 40px) 40px; }
	.db-hd-col { flex: 1 1 0; min-width: 0; }
	html body .db-hd .db-hd-h { margin: 0 0 14px; font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase; color: #5b6472; }
	.db-hd-col ul { margin: 0; padding: 0; list-style: none; display: grid; gap: 2px; }
	.db-hd-col li { margin: 0; }
	html body .db-hd .db-hd-col a { display: flex; align-items: center; gap: 14px; margin: 0 -12px; padding: 9px 12px; border-radius: 14px; text-decoration: none !important; box-shadow: none; transition: background .12s ease; }
	html body .db-hd .db-hd-col a:hover, html body .db-hd .db-hd-col a:focus-visible { background: #f1f5f4; }
	html body .db-hd .db-hd-col strong { display: block; font-size: 16px; font-weight: 700; letter-spacing: -.01em; color: #111827; }
	html body .db-hd .db-hd-tx { display: block; min-width: 0; }
	html body .db-hd .db-hd-tx span { display: block; margin-top: 1px; font-size: 13.5px; line-height: 1.4; color: #5b6472; font-weight: 400; }
	html body .db-hd .db-hd-ic { flex: none; display: flex; align-items: center; justify-content: center; width: 42px; height: 42px; border-radius: 12px; background: #eef6f5; color: #0b7a75; font-style: normal; transition: background .15s ease, color .15s ease, transform .15s ease; }
	html body .db-hd .db-hd-col a:hover .db-hd-ic, html body .db-hd .db-hd-col a:focus-visible .db-hd-ic { background: #0b7a75; color: #fff; transform: scale(1.04); }
	.db-hd::after { content: ""; position: fixed; left: 0; right: 0; top: 0; height: 100vh; z-index: -1; pointer-events: none; background: rgba(12,20,36,.34); -webkit-backdrop-filter: blur(3px); backdrop-filter: blur(3px); opacity: 0; transition: opacity .2s ease; }
	.db-hd:has(.db-hd-item.is-open)::after, .db-hd:has(.db-hd-item:hover)::after, .db-hd:has(.db-hd-item:focus-within)::after { opacity: 1; }
	.db-hd-in { position: relative; background: inherit; }
	.db-hd-col + .db-hd-col { padding-left: clamp(20px, 3vw, 44px); border-left: 1px solid rgba(17,24,39,.08); }
	.db-hd-card { flex: 0 0 280px; align-self: flex-start; padding: 24px; border-radius: 20px; color: #fff; background: radial-gradient(420px 240px at 100% 0%, rgba(25,179,166,.5) 0%, rgba(25,179,166,0) 65%), #0c1424; }
	html body .db-hd .db-hd-card-t { margin: 0 0 8px; font-size: 19px; line-height: 1.25; font-weight: 800; letter-spacing: -.02em; color: #fff; }
	html body .db-hd .db-hd-card p { margin: 0 0 16px; font-size: 14.5px; line-height: 1.55; color: rgba(255,255,255,.82); }
	html body .db-hd .db-hd-card-b { display: inline-flex; align-items: center; gap: 8px; min-height: 40px; padding: 0 16px; border-radius: 999px; background: #fff; color: #0c1424 !important; font-size: 14.5px; font-weight: 700; text-decoration: none !important; }
	html body .db-hd .db-hd-top { position: relative; }
	html body .db-hd .db-hd-top::after { content: ""; position: absolute; left: 18px; right: 18px; bottom: 16px; height: 2px; border-radius: 2px; background: #0b7a75; transform: scaleX(0); transform-origin: left; transition: transform .2s ease; }
	html body .db-hd .db-hd-top:hover::after, html body .db-hd .db-hd-item.is-open .db-hd-top::after, html body .db-hd .db-hd-item:focus-within .db-hd-top::after { transform: scaleX(1); }
	.db-hd-right { display: flex; align-items: center; justify-content: flex-end; gap: 6px; min-width: 0; }
	/* Search: pill with icon, Search button, and a panel of popular names and live results. */
	.db-hd-search { position: relative; display: flex; align-items: center; margin: 0 8px 0 0; }
	html body .db-hd .db-hd-search input[type=search] { width: 268px; height: 44px; margin: 0; padding: 0 90px 0 40px; border: 1.5px solid #dfe3ea !important; border-radius: 999px !important; background: #f4f6f9 !important; box-shadow: none !important; font-size: 15px; color: #0f172a; transition: width .25s ease, background .2s ease, border-color .2s ease, box-shadow .2s ease; }
	html body .db-hd .db-hd-search input[type=search]::placeholder { color: #667085; }
	html body .db-hd .db-hd-search input[type=search]::-webkit-search-cancel-button { display: none; }
	html body .db-hd .db-hd-search.is-open input[type=search], html body .db-hd .db-hd-search input[type=search]:focus { width: 340px; background: #fff !important; border-color: #0f1b2d !important; box-shadow: 0 0 0 4px rgba(181,230,29,.35) !important; outline: 0; }
	.db-hd-sicon { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #0f1b2d; display: flex; pointer-events: none; }
	html body .db-hd .db-hd-sgo { position: absolute; right: 4px; top: 4px; height: 36px; min-height: 0; padding: 0 16px; border: 0 !important; border-radius: 999px !important; background: #0f1b2d !important; color: #fff !important; font-size: 14px; font-weight: 700; letter-spacing: .01em; cursor: pointer; box-shadow: none !important; transition: background .15s ease, color .15s ease; }
	html body .db-hd .db-hd-sgo:hover { background: #b5e61d !important; color: #0f1b2d !important; }
	.db-hd-kbd { position: absolute; right: 86px; top: 50%; transform: translateY(-50%); min-width: 22px; height: 22px; padding: 0 6px; border: 1px solid #d0d5dd; border-radius: 6px; background: #fff; color: #667085; font: 600 12px/20px ui-monospace, monospace; text-align: center; }
	.db-hd-search.is-open .db-hd-kbd, .db-hd-search:focus-within .db-hd-kbd { display: none; }
	.db-hd-sp { position: absolute; top: calc(100% + 12px); right: -120px; width: min(620px, calc(100vw - 40px)); max-height: calc(100vh - 110px); overflow: auto; padding: 0; border: 1px solid #e4e7ec; border-radius: 22px; background: #fff; box-shadow: 0 30px 70px -20px rgba(15,23,42,.35); z-index: 5; }
	.db-hd-sp-in { padding: 20px 22px 8px; }
	html body .db-hd .db-hd-sp h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-size: 12px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: #5b6472; }
	.db-hd-sp h3 .db-hd-live { width: 8px; height: 8px; border-radius: 50%; background: #65a30d; box-shadow: 0 0 0 0 rgba(101,163,13,.6); animation: dbhdpulse 1.8s infinite; }
	@keyframes dbhdpulse { 70% { box-shadow: 0 0 0 8px rgba(101,163,13,0); } 100% { box-shadow: 0 0 0 0 rgba(101,163,13,0); } }
	.db-hd-sp section + section { margin-top: 18px; }
	.db-hd-sp-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin: 0; padding: 0; list-style: none; }
	.db-hd-sp-list { display: grid; gap: 2px; margin: 0; padding: 0; list-style: none; }
	.db-hd-sp li { margin: 0; }
	html body .db-hd .db-hd-sp-grid a { display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; border: 1px solid #e4e7ec; border-radius: 14px; background: #fff; text-decoration: none !important; transition: border-color .15s ease, transform .15s ease, box-shadow .15s ease; }
	html body .db-hd .db-hd-sp-grid a:hover, html body .db-hd .db-hd-sp-grid a.is-active { border-color: #0f1b2d; transform: translateY(-1px); box-shadow: 0 8px 20px -10px rgba(15,23,42,.35); }
	html body .db-hd .db-hd-sp-grid b { font-size: 15.5px; font-weight: 800; color: #0f172a; letter-spacing: -.01em; overflow-wrap: anywhere; }
	html body .db-hd .db-hd-sp-grid span { font-size: 13px; color: #3f6212; font-weight: 700; }
	html body .db-hd .db-hd-sp-list a { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border-radius: 12px; text-decoration: none !important; color: #0f172a; }
	html body .db-hd .db-hd-sp-list a:hover, html body .db-hd .db-hd-sp-list a.is-active { background: #f1f6e4; }
	html body .db-hd .db-hd-sp-list b { font-size: 15.5px; font-weight: 700; color: #0f172a; overflow-wrap: anywhere; }
	html body .db-hd .db-hd-sp-list b mark { background: #e3f5b0; color: inherit; border-radius: 3px; padding: 0 1px; }
	html body .db-hd .db-hd-sp-list span { flex: none; font-size: 14px; font-weight: 700; color: #3f6212; }
	.db-hd-sp-chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; padding: 0; list-style: none; }
	html body .db-hd .db-hd-sp-chips a { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 14px; border: 1.5px solid #dfe3ea; border-radius: 999px; color: #0f172a; font-size: 14px; font-weight: 600; text-decoration: none !important; }
	html body .db-hd .db-hd-sp-chips a small { color: #667085; font-size: 12px; font-weight: 600; }
	html body .db-hd .db-hd-sp-chips a:hover, html body .db-hd .db-hd-sp-chips a.is-active { border-color: #0f1b2d; background: #0f1b2d; color: #fff; }
	html body .db-hd .db-hd-sp-chips a:hover small, html body .db-hd .db-hd-sp-chips a.is-active small { color: #b5e61d; }
	html body .db-hd .db-hd-sp-empty { margin: 4px 0 8px; padding: 14px; border-radius: 14px; background: #f6f7f9; color: #344054; font-size: 14.5px; line-height: 1.5; }
	html body .db-hd .db-hd-sp-empty a { color: #3f6212; font-weight: 700; }
	html body .db-hd .db-hd-sp-all { display: inline-flex; margin: 8px 0 4px; color: #0f172a; font-size: 14.5px; font-weight: 700; text-decoration: underline !important; text-underline-offset: 3px; }
	html body .db-hd .db-hd-sp-load { margin: 0 0 12px; color: #667085; font-size: 14px; }
	html body .db-hd .db-hd-sp-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin: 10px 0 0; padding: 12px 22px; border-top: 1px solid #eef1f4; background: #fafbfc; border-radius: 0 0 22px 22px; font-size: 13px; color: #667085; }
	html body .db-hd .db-hd-sp-foot a { color: #3f6212; font-weight: 700; text-decoration: none !important; }
	.db-hd-sp-foot kbd { display: inline-block; min-width: 20px; margin: 0 2px; padding: 0 5px; border: 1px solid #d0d5dd; border-radius: 5px; background: #fff; font: 600 11px/18px ui-monospace, monospace; text-align: center; color: #344054; }
	/* Account dropdown. */
	.db-hd-acct { position: relative; }
	html body .db-hd .db-hd-acct-btn { display: inline-flex; align-items: center; gap: 8px; height: 44px; min-height: 0; padding: 0 12px 0 4px; border: 1.5px solid #dfe3ea !important; border-radius: 999px !important; background: #fff !important; color: #0f172a !important; font-size: 15px; font-weight: 700; cursor: pointer; box-shadow: none !important; position: relative; }
	html body .db-hd .db-hd-acct-btn:hover, html body .db-hd .db-hd-acct.is-open .db-hd-acct-btn { border-color: #0f1b2d !important; }
	html body .db-hd .db-hd-av { display: inline-flex; align-items: center; justify-content: center; width: 34px; height: 34px; border-radius: 50%; background: #0f1b2d; color: #b5e61d; font-size: 15px; font-weight: 900; }
	html body .db-hd .db-hd-av--lg { width: 44px; height: 44px; font-size: 18px; flex: none; }
	.db-hd-acct-l { max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	.db-hd-chev { transition: transform .2s ease; }
	.db-hd-acct.is-open .db-hd-chev { transform: rotate(180deg); }
	.db-hd-dot { position: absolute; left: 30px; top: 5px; width: 10px; height: 10px; border-radius: 50%; background: #e11d48; border: 2px solid #fff; }
	.db-hd-acct-menu { position: absolute; right: 0; top: calc(100% + 10px); width: 300px; padding: 8px; border: 1px solid #e4e7ec; border-radius: 20px; background: #fff; box-shadow: 0 30px 60px -20px rgba(15,23,42,.35); visibility: hidden; opacity: 0; transform: translateY(-6px); transition: opacity .16s ease, transform .16s ease, visibility 0s linear .16s; }
	.db-hd-acct.is-open .db-hd-acct-menu { visibility: visible; opacity: 1; transform: none; transition-delay: 0s; }
	.db-hd-acct-who { display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 14px; background: #f6f8f2; }
	.db-hd-acct-who > span:last-child { min-width: 0; display: grid; }
	html body .db-hd .db-hd-acct-who strong { font-size: 15px; font-weight: 800; color: #0f172a; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	html body .db-hd .db-hd-acct-who span span { font-size: 13px; color: #5b6472; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
	html body .db-hd .db-hd-acct-main { display: flex; justify-content: space-between; margin: 8px 4px 4px; padding: 10px 10px; border-radius: 12px; background: #0f1b2d; color: #fff !important; font-size: 14.5px; font-weight: 700; text-decoration: none !important; }
	html body .db-hd .db-hd-acct-main:hover { background: #1e2f47; }
	.db-hd-acct-menu ul { margin: 4px 0; padding: 0; list-style: none; display: grid; }
	.db-hd-acct-menu li { margin: 0; }
	html body .db-hd .db-hd-acct-menu ul a, html body .db-hd .db-hd-acct-out { display: flex; align-items: center; gap: 12px; padding: 9px 10px; border-radius: 12px; color: #0f172a !important; font-size: 15px; font-weight: 600; text-decoration: none !important; }
	html body .db-hd .db-hd-acct-menu i { display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 10px; background: #f1f5f4; color: #0f1b2d; font-style: normal; }
	html body .db-hd .db-hd-acct-menu ul a:hover, html body .db-hd .db-hd-acct-menu ul a:focus-visible { background: #f1f6e4; }
	html body .db-hd .db-hd-acct-menu ul a:hover i { background: #b5e61d; }
	html body .db-hd .db-hd-badge { margin-left: auto; padding: 2px 8px; border-radius: 999px; background: #ffe4e6; color: #be123c; font-size: 12px; font-weight: 800; }
	html body .db-hd .db-hd-acct-out { margin-top: 4px; border-top: 1px solid #eef1f4; border-radius: 0 0 12px 12px; padding-top: 12px; color: #b42318 !important; }
	html body .db-hd .db-hd-acct-out i { background: #fff1f0; color: #b42318; }
	html body .db-hd .db-hd-acct-out:hover { background: #fff5f4; }
	html body .db-hd .db-hd-link { display: inline-flex; align-items: center; height: 40px; padding: 0 14px; border-radius: 999px; color: #111827 !important; font-size: 15px; font-weight: 600; text-decoration: none !important; white-space: nowrap; box-shadow: none; }
	html body .db-hd .db-hd-link:hover { background: rgba(17,24,39,.06); }
	html body .db-hd .db-hd-cta { display: inline-flex; align-items: center; height: 40px; padding: 0 18px; border-radius: 999px; background: #111827; color: #fff !important; font-size: 15px; font-weight: 600; text-decoration: none !important; white-space: nowrap; transition: background .15s ease; }
	html body .db-hd .db-hd-cta:hover { background: #0b7a75; }
	.db-hd [hidden] { display: none !important; }
	.db-hd a:focus-visible, .db-hd button:focus-visible { outline: 3px solid #0b7a75; outline-offset: 2px; }
}
@media (min-width: 992px) and (max-width: 1280px) {
	html body .db-hd .db-hd-search input[type=search] { width: 170px; padding-right: 16px; }
	html body .db-hd .db-hd-sgo, .db-hd-kbd { display: none; }
	html body .db-hd .db-hd-search.is-open input[type=search], html body .db-hd .db-hd-search input[type=search]:focus { width: 260px; }
	.db-hd-sp { right: -60px; }
	.db-hd-acct-l { display: none; }
	html body .db-hd .db-hd-top { padding: 0 13px; }
	html body .db-hd .db-hd-top::after { left: 13px; right: 13px; }
	.db-hd-in { gap: 12px; }
	html body .db-hd .db-hd-link { padding: 0 10px; }
	.db-hd-card { flex-basis: 230px; }
}
@media (max-width: 991px) {
	.db-ms-btn { position: fixed; top: 12px; right: 66px; z-index: 100001; width: 46px; height: 46px; padding: 0; border: 0; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #b5e61d; color: #0a1628; box-shadow: 0 4px 14px rgba(8,23,58,.22); cursor: pointer; }
	.admin-bar .db-ms-btn { top: 58px; }
	.db-ms-btn:focus-visible, .db-ms-close:focus-visible { outline: 3px solid #0a1628; outline-offset: 2px; }
	html.db-ms-on, html.db-ms-on body { overflow: hidden; }
	.db-ms { position: fixed; inset: 0; z-index: 100002; background: #fff; overflow-y: auto; -webkit-overflow-scrolling: touch; }
	.db-ms[hidden] { display: none !important; }
	.db-ms-in { max-width: 680px; margin: 0 auto; padding: 14px 16px 40px; }
	.db-ms-bar { position: sticky; top: 0; z-index: 2; display: flex; gap: 10px; align-items: center; padding: 6px 0 12px; background: #fff; }
	html body .db-ms input[type=search] { flex: 1; min-width: 0; height: 50px; margin: 0; padding: 0 16px; border: 2px solid #0a1628 !important; border-radius: 999px !important; background: #fff !important; font-size: 17px; color: #0f172a; box-shadow: 0 0 0 4px rgba(181,230,29,.35) !important; }
	html body .db-ms input[type=search]::-webkit-search-cancel-button { display: none; }
	html body .db-ms .db-ms-close { flex: none; height: 44px; min-height: 0; padding: 0 6px; border: 0 !important; background: transparent !important; box-shadow: none !important; color: #0a1628 !important; font-size: 16px; font-weight: 700; cursor: pointer; }
	.db-ms .db-hd-sp { position: static; width: auto; max-height: none; overflow: visible; border: 0; border-radius: 0; box-shadow: none; }
	.db-ms .db-hd-sp-in { padding: 4px 0 8px; }
	.db-ms .db-hd-sp-grid { grid-template-columns: minmax(0, 1fr); }
	.db-ms .db-hd-sp-in { padding: 20px 22px 8px; }
	html body .db-ms .db-hd-sp h3 { display: flex; align-items: center; gap: 8px; margin: 0 0 10px; font-size: 12px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase; color: #5b6472; }
	.db-ms .db-hd-sp h3 .db-hd-live { width: 8px; height: 8px; border-radius: 50%; background: #65a30d; box-shadow: 0 0 0 0 rgba(101,163,13,.6); animation: dbhdpulse 1.8s infinite; }
	.db-ms .db-hd-sp section + section { margin-top: 18px; }
	.db-ms .db-hd-sp-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; margin: 0; padding: 0; list-style: none; }
	.db-ms .db-hd-sp-list { display: grid; gap: 2px; margin: 0; padding: 0; list-style: none; }
	.db-ms .db-hd-sp li { margin: 0; }
	html body .db-ms .db-hd-sp-grid a { display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; border: 1px solid #e4e7ec; border-radius: 14px; background: #fff; text-decoration: none !important; transition: border-color .15s ease, transform .15s ease, box-shadow .15s ease; }
	html body .db-ms .db-hd-sp-grid a:hover, html body .db-ms .db-hd-sp-grid a.is-active { border-color: #0f1b2d; transform: translateY(-1px); box-shadow: 0 8px 20px -10px rgba(15,23,42,.35); }
	html body .db-ms .db-hd-sp-grid b { font-size: 15.5px; font-weight: 800; color: #0f172a; letter-spacing: -.01em; overflow-wrap: anywhere; }
	html body .db-ms .db-hd-sp-grid span { font-size: 13px; color: #3f6212; font-weight: 700; }
	html body .db-ms .db-hd-sp-list a { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 12px; border-radius: 12px; text-decoration: none !important; color: #0f172a; }
	html body .db-ms .db-hd-sp-list a:hover, html body .db-ms .db-hd-sp-list a.is-active { background: #f1f6e4; }
	html body .db-ms .db-hd-sp-list b { font-size: 15.5px; font-weight: 700; color: #0f172a; overflow-wrap: anywhere; }
	html body .db-ms .db-hd-sp-list b mark { background: #e3f5b0; color: inherit; border-radius: 3px; padding: 0 1px; }
	html body .db-ms .db-hd-sp-list span { flex: none; font-size: 14px; font-weight: 700; color: #3f6212; }
	.db-ms .db-hd-sp-chips { display: flex; flex-wrap: wrap; gap: 8px; margin: 0; padding: 0; list-style: none; }
	html body .db-ms .db-hd-sp-chips a { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 14px; border: 1.5px solid #dfe3ea; border-radius: 999px; color: #0f172a; font-size: 14px; font-weight: 600; text-decoration: none !important; }
	html body .db-ms .db-hd-sp-chips a small { color: #667085; font-size: 12px; font-weight: 600; }
	html body .db-ms .db-hd-sp-chips a:hover, html body .db-ms .db-hd-sp-chips a.is-active { border-color: #0f1b2d; background: #0f1b2d; color: #fff; }
	html body .db-ms .db-hd-sp-chips a:hover small, html body .db-ms .db-hd-sp-chips a.is-active small { color: #b5e61d; }
	html body .db-ms .db-hd-sp-empty { margin: 4px 0 8px; padding: 14px; border-radius: 14px; background: #f6f7f9; color: #344054; font-size: 14.5px; line-height: 1.5; }
	html body .db-ms .db-hd-sp-empty a { color: #3f6212; font-weight: 700; }
	html body .db-ms .db-hd-sp-all { display: inline-flex; margin: 8px 0 4px; color: #0f172a; font-size: 14.5px; font-weight: 700; text-decoration: underline !important; text-underline-offset: 3px; }
	html body .db-ms .db-hd-sp-load { margin: 0 0 12px; color: #667085; font-size: 14px; }
	html body .db-ms .db-hd-sp-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 8px; margin: 10px 0 0; padding: 12px 22px; border-top: 1px solid #eef1f4; background: #fafbfc; border-radius: 0 0 22px 22px; font-size: 13px; color: #667085; }
	html body .db-ms .db-hd-sp-foot a { color: #3f6212; font-weight: 700; text-decoration: none !important; }
	.db-ms .db-hd-sp-foot kbd { display: inline-block; min-width: 20px; margin: 0 2px; padding: 0 5px; border: 1px solid #d0d5dd; border-radius: 5px; background: #fff; font: 600 11px/18px ui-monospace, monospace; text-align: center; color: #344054; }
	.db-ms .db-hd-sp { right: -60px; }
}
@media (min-width: 992px) { .db-ms-btn, .db-ms { display: none !important; } }
/* Breadcrumb bar: one place on every page, directly under the header, aligned with the logo. */
.db-bc-bar { background: #f6f7f9; border-bottom: 1px solid #e5e7eb; }
.db-bc-in { max-width: 1360px; margin: 0 auto; padding: 0 clamp(16px, 3vw, 40px); }
html body.db-ui-active .db-bc-bar nav.db-breadcrumbs { margin: 0 !important; padding: 10px 0 !important; max-width: none !important; width: auto !important; background: none !important; border: 0 !important; font-size: 13.5px; }
html body.db-ui-active .db-bc-bar nav.db-breadcrumbs ol { margin: 0 !important; padding: 0 !important; display: flex; flex-wrap: wrap; align-items: center; gap: 2px 0; }
html body.db-ui-active .db-bc-bar nav.db-breadcrumbs a { display: inline-block; padding: 4px 0; }
@media (prefers-reduced-motion: reduce) { .db-hd, .db-hd-mega, .db-hd-top svg, .db-hd-search input, .db-hd-acct-menu, .db-hd-chev { transition: none !important; } .db-hd-sp h3 .db-hd-live { animation: none; } }
CSS;
	if ( function_exists( 'db_external_style' ) && db_external_style( 'header', $css ) ) {
		return;
	}
	wp_register_style( 'db-header', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-header' );
	wp_add_inline_style( 'db-header', $css );
}, 113 );

/*
 * Contact form: the "I'm interested in" list also offers each service, so an
 * enquiry reaches the right person without a follow-up question. The options
 * are added to the form tag itself, which means Contact Form 7 validates them
 * like the ones typed into the form, and they appear in the notification email.
 */
if ( ! function_exists( 'db_contact_interest_options' ) ) {
	function db_contact_interest_options() {
		return (array) apply_filters( 'db_contact_interest_options', array(
			'Domain brokerage',
			'Domain management',
			'Domain transfer',
			'Social media handles',
			'Website design and development',
			'Digital marketing',
			'Software development',
			'Mobile app development',
			'Web hosting and maintenance',
		) );
	}
}
add_filter( 'wpcf7_form_tag', function ( $tag ) {
	if ( ! is_array( $tag ) || empty( $tag['name'] ) || 'contact-interest' !== $tag['name'] || empty( $tag['basetype'] ) || 'select' !== $tag['basetype'] ) {
		return $tag;
	}
	foreach ( array( 'values', 'raw_values', 'labels' ) as $key ) {
		$list = isset( $tag[ $key ] ) ? array_values( (array) $tag[ $key ] ) : array();
		$add  = array_values( array_diff( db_contact_interest_options(), $list ) );
		if ( ! $add ) {
			continue;
		}
		// Keep "Other" as the last choice.
		$other = array_search( 'Other', $list, true );
		if ( false === $other ) {
			$list = array_merge( $list, $add );
		} else {
			array_splice( $list, $other, 0, $add );
		}
		$tag[ $key ] = $list;
	}
	return $tag;
}, 20 );


/*
 * Breadcrumbs sit in one bar under the header on every page, instead of
 * wherever a template happened to print them (above a hero on one page, inside
 * the content column on another).
 */
if ( ! function_exists( 'db_header_breadcrumb_bar' ) ) {
	function db_header_breadcrumb_bar( $html ) {
		if ( false !== strpos( $html, 'class="db-bc-bar"' ) ) {
			return $html;
		}
		$main = strpos( $html, '<main' );
		if ( ! preg_match( '#<nav\b[^>]*class="[^"]*\bdb-breadcrumbs\b[^"]*"[^>]*>.*?</nav>#s', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			// Hand-built pages print their own trail (any <nav aria-label="Breadcrumb">).
			if ( false === $main || ! preg_match( '#<nav\b([^>]*)aria-label="Breadcrumb"([^>]*)>.*?</nav>#s', $html, $m, PREG_OFFSET_CAPTURE, $main ) ) {
				return $html;
			}
			$fixed   = preg_replace( '#^<nav\b(?![^>]*\bclass=)#', '<nav class="db-breadcrumbs"', $m[0][0], 1 );
			$fixed   = preg_replace( '#^(<nav\b[^>]*\bclass=")#', '$1db-breadcrumbs ', $fixed, 1 );
			$m[0][0] = $m[0][0];
			$html    = substr_replace( $html, $fixed, $m[0][1], strlen( $m[0][0] ) );
			$m[0][0] = $fixed;
		}
		$page = strpos( $html, '<div id="content"' );
		if ( false === $page || $m[0][1] < $page ) {
			return $html;
		}
		$html = substr_replace( $html, '', $m[0][1], strlen( $m[0][0] ) );
		return substr_replace( $html, '<div class="db-bc-bar"><div class="db-bc-in">' . $m[0][0] . '</div></div>', $page, 0 );
	}
}
add_action( 'template_redirect', function () {
	if ( ! is_admin() && ! is_feed() && ! is_customize_preview() ) {
		ob_start( 'db_header_breadcrumb_bar' );
	}
}, 1 );

/*
 * The theme's sidebar ends with a hard-coded 2017 badge image showing Visa,
 * Mastercard, Escrow.com and PayPal with an ICANN logo over it. The site takes
 * card payments through Stripe and uses neither escrow nor PayPal, so the badge
 * is replaced with an accurate one.
 */
if ( ! function_exists( 'db_header_pay_badge' ) ) {
	/**
	 * @param string $html Page HTML.
	 * @return string
	 */
	function db_header_pay_badge( $html ) {
		if ( false === strpos( $html, 'security-logo.png' ) ) {
			return $html;
		}
		$lock  = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>';
		$badge = '<section class="widget dbpay" aria-label="Secure checkout"><p class="dbpay-h">' . $lock . 'Secure checkout</p>'
			. '<p class="dbpay-t">Card payments are processed by Stripe, in full or on a monthly plan.</p>'
			. '<ul class="dbpay-cards"><li>Visa</li><li>Mastercard</li><li>Other major cards</li></ul></section>'
			. '<style>.dbpay{margin:18px 0 8px;padding:16px 18px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;text-align:left}'
			. '.dbpay-h{display:flex;align-items:center;gap:8px;margin:0 0 6px!important;font-size:15px!important;font-weight:800;color:#111827!important}.dbpay-h svg{color:#0b7a75}'
			. '.dbpay-t{margin:0 0 10px!important;font-size:13.5px!important;line-height:1.5;color:#4b5563!important}'
			. '.dbpay-cards{display:flex;flex-wrap:wrap;gap:6px;margin:0;padding:0;list-style:none}.dbpay-cards li{margin:0;padding:4px 10px;border-radius:999px;background:#f1f4f6;font-size:12.5px;font-weight:700;color:#374151;background-image:none!important}</style>';
		$out = preg_replace( '#<section id="text-3" class="widget widget_text[^"]*"[^>]*>.*?security-logo\.png.*?</section>#s', str_replace( '$', '\\$', $badge ), $html, 1 );
		return null === $out ? $html : $out;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() || is_feed() ) {
		return;
	}
	ob_start( 'db_header_pay_badge' );
}, 2 );
