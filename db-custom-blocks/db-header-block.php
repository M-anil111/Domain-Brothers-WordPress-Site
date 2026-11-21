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
				foreach ( $col[1] as $link ) {
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
			. '<form class="db-hd-search" role="search" method="get" action="' . $home . '"><label class="screen-reader-text" for="db-hd-q">Search domains</label>'
			. '<input id="db-hd-q" type="search" name="s" placeholder="Search domains" autocomplete="off"><input type="hidden" name="post_type" value="domain">'
			. '<button type="submit" aria-label="Search"><svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="7" fill="none" stroke="currentColor" stroke-width="2"/><path d="M20 20l-3.600-3.600" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></button></form>'
			. '<a class="db-hd-link" href="' . esc_url( home_url( '/sign-in/' ) ) . '" data-href="' . esc_url( home_url( '/sign-in/' ) ) . '" data-db-acct="signin">Sign in</a>'
			. '<a class="db-hd-cta" href="' . esc_url( home_url( '/register/' ) ) . '" data-db-acct="register">Create account</a>'
			. '<a class="db-hd-link" href="' . esc_url( home_url( '/my-wishlist/' ) ) . '" data-db-acct="signout" hidden>Sign out</a>'
			. '</div></div></header>';
		return $h;
	}
}

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
	.db-hd-search { position: relative; display: flex; align-items: center; margin: 0 6px 0 0; }
	html body .db-hd .db-hd-search input[type=search] { width: 190px; height: 40px; margin: 0; padding: 0 40px 0 16px; border: 1px solid rgba(17,24,39,.14) !important; border-radius: 999px !important; background: rgba(17,24,39,.04) !important; box-shadow: none !important; font-size: 14.5px; color: #111827; transition: width .2s ease, background .2s ease, border-color .2s ease; }
	html body .db-hd .db-hd-search input[type=search]:focus { width: 250px; background: #fff !important; border-color: #111827 !important; outline: 0; }
	html body .db-hd .db-hd-search button { position: absolute; right: 2px; top: 2px; width: 36px; height: 36px; min-height: 0; padding: 0; border: 0 !important; border-radius: 50% !important; background: transparent !important; color: #111827 !important; display: flex; align-items: center; justify-content: center; cursor: pointer; box-shadow: none !important; }
	html body .db-hd .db-hd-link { display: inline-flex; align-items: center; height: 40px; padding: 0 14px; border-radius: 999px; color: #111827 !important; font-size: 15px; font-weight: 600; text-decoration: none !important; white-space: nowrap; box-shadow: none; }
	html body .db-hd .db-hd-link:hover { background: rgba(17,24,39,.06); }
	html body .db-hd .db-hd-cta { display: inline-flex; align-items: center; height: 40px; padding: 0 18px; border-radius: 999px; background: #111827; color: #fff !important; font-size: 15px; font-weight: 600; text-decoration: none !important; white-space: nowrap; transition: background .15s ease; }
	html body .db-hd .db-hd-cta:hover { background: #0b7a75; }
	.db-hd [hidden] { display: none !important; }
	.db-hd a:focus-visible, .db-hd button:focus-visible { outline: 3px solid #0b7a75; outline-offset: 2px; }
}
@media (min-width: 992px) and (max-width: 1280px) {
	html body .db-hd .db-hd-search input[type=search] { width: 40px; padding: 0 0 0 40px; color: transparent; cursor: pointer; }
	html body .db-hd .db-hd-search input[type=search]::placeholder { color: transparent; }
	html body .db-hd .db-hd-search input[type=search]:focus { width: 210px; padding: 0 40px 0 16px; color: #111827; cursor: text; }
	html body .db-hd .db-hd-search input[type=search]:focus::placeholder { color: #6b7280; }
	html body .db-hd .db-hd-top { padding: 0 13px; }
	html body .db-hd .db-hd-top::after { left: 13px; right: 13px; }
	.db-hd-in { gap: 12px; }
	html body .db-hd .db-hd-link { padding: 0 10px; }
	.db-hd-card { flex-basis: 230px; }
}
/* Breadcrumb bar: one place on every page, directly under the header, aligned with the logo. */
.db-bc-bar { background: #f6f7f9; border-bottom: 1px solid #e5e7eb; }
.db-bc-in { max-width: 1360px; margin: 0 auto; padding: 0 clamp(16px, 3vw, 40px); }
html body.db-ui-active .db-bc-bar nav.db-breadcrumbs { margin: 0 !important; padding: 10px 0 !important; max-width: none !important; width: auto !important; background: none !important; border: 0 !important; font-size: 13.5px; }
html body.db-ui-active .db-bc-bar nav.db-breadcrumbs ol { margin: 0 !important; padding: 0 !important; display: flex; flex-wrap: wrap; align-items: center; gap: 2px 0; }
html body.db-ui-active .db-bc-bar nav.db-breadcrumbs a { display: inline-block; padding: 4px 0; }
@media (prefers-reduced-motion: reduce) { .db-hd, .db-hd-mega, .db-hd-top svg, .db-hd-search input { transition: none !important; } }
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
