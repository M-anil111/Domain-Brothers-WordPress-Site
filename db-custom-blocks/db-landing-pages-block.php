<?php
/**
 * Acquire page copy (/acquire-premium-domain/) and the shared pieces the Acquire and Sell pages
 * use: benefits, steps, contact box, FAQs with FAQPage structured data.
 *
 * Copy rules (owner review, 2026-11-22): only claims the business can back. No "$5,000,000+",
 * no "#1", no ranking promises, no "24x7". Facts used here come from the page's own FAQ answers
 * (no fee unless acquired, 15% of the agreed price on success, the owner and Domain Brothers are
 * paid separately) and from live counts.
 *
 * The page's WordPress content is replaced by db_acquire_body() at render time (the_content,
 * priority 20, after wpautop), so the copy lives in this file and in version control. The guide
 * that follows the content (focus/domain-services.php) is untouched.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_lp_phone' ) ) {
	/** The sales phone number shown on these pages (option db_contact_phone), as [display, tel]. */
	function db_lp_phone() {
		$display = trim( (string) get_option( 'db_contact_phone', '+1 512-621-8822' ) );
		return array( $display, 'tel:' . preg_replace( '/[^0-9+]/', '', $display ) );
	}
}

if ( ! function_exists( 'db_lp_counts' ) ) {
	/** Live counts for proof lines: names for sale and names sold. Cached for 6 hours. */
	function db_lp_counts() {
		$c = get_transient( 'db_lp_counts' );
		if ( is_array( $c ) ) {
			return $c;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$all  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'domain' AND post_status = 'publish'" );
		// Sold by either flag: the theme's domain_status or the plugin's db_listing_state (SE-16).
		$sold = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND ( ( m.meta_key = 'domain_status' AND m.meta_value = 'sold' ) OR ( m.meta_key = 'db_listing_state' AND m.meta_value = 'sold' ) ) WHERE p.post_type = 'domain' AND p.post_status = 'publish'" );
		// phpcs:enable
		$c = array( 'sale' => max( 0, $all - $sold ), 'sold' => $sold );
		set_transient( 'db_lp_counts', $c, 6 * HOUR_IN_SECONDS );
		return $c;
	}
}

if ( ! function_exists( 'db_lp_css' ) ) {
	function db_lp_css() {
		return '<style>.dblp{max-width:1320px;margin:0 auto;color:#0a1628}.dblp *{box-sizing:border-box}'
			. '.dblp-split{display:grid;grid-template-columns:minmax(0,6fr) minmax(0,5fr);gap:32px;align-items:start;margin:0 0 56px}@media(max-width:900px){.dblp-split{grid-template-columns:1fr}}'
			. 'html body .dblp h2{margin:0 0 12px;font-size:clamp(24px,2.8vw,32px);font-weight:800;letter-spacing:-.02em;color:#0a1628}.dblp-lead{margin:0 0 22px;font-size:17px;line-height:1.6;color:#374151;max-width:62ch}'
			. '.dblp-benefits{display:grid;gap:14px;margin:0;padding:0;list-style:none}.dblp-benefits li{display:flex;gap:14px;align-items:flex-start;padding:16px 18px;border:1px solid #dfe5e1;border-radius:16px;background:#fff}'
			. '.dblp-ico{flex:none;display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:12px;background:#f2fbe0;color:#0a1628}.dblp-ico svg{width:22px;height:22px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}'
			. '.dblp-benefits strong{display:block;font-size:16.5px;margin:0 0 2px}.dblp-benefits p{margin:0;color:#4b5563;font-size:15px;line-height:1.55}'
			. '.dblp-form{padding:26px;border-radius:20px;background:#0a1628;color:#fff;box-shadow:0 18px 40px rgba(10,22,40,.18)}html body .dblp-form h2{color:#fff;font-size:24px}.dblp-form p.dblp-req{margin:0 0 14px;color:rgba(255,255,255,.75);font-size:14px}'
			. '.dblp-form label{display:block;margin:0 0 6px;color:#fff;font-weight:700;font-size:14.5px}.dblp-form input[type=text],.dblp-form input[type=email],.dblp-form input[type=tel],.dblp-form select,.dblp-form textarea{width:100%;min-height:46px;margin:0 0 14px;padding:10px 14px;border:1.5px solid #2b3a52;border-radius:12px;background:#fff;color:#0a1628;font:inherit;font-size:16px}'
			. '.dblp-form textarea{min-height:110px}.dblp-form input[type=submit],.dblp-form button[type=submit]{width:100%;min-height:50px;border:0;border-radius:999px;background:#b5e61d;color:#0a1628;font-weight:800;font-size:16px;cursor:pointer}'
			. '.dblp-form .dblp-note{margin:-6px 0 14px;color:rgba(255,255,255,.78);font-size:13.5px}.dblp-form input[type=file]{margin:0 0 14px;color:#fff}.dblp-form .wpcf7-not-valid-tip{color:#fecaca}.dblp-form .wpcf7-response-output{margin:12px 0 0!important;border-radius:12px;color:#fff}.dblp-form .sell-domain{margin:0}.dblp-form br{display:none}'
			// Dark form: generic CF7 label colours (.db-ui-active .wpcf7-form label, body.dbp main .wpcf7 label) outranked
			// the white labels, leaving them at 1.02:1 contrast (bug register batch 2, AX-1).
			. 'html body .dblp-form.dblp-form.dblp-form .wpcf7-form label,html body .dblp-form.dblp-form.dblp-form label{color:#fff}html body .dblp-form.dblp-form.dblp-form p.dblp-req,html body .dblp-form.dblp-form.dblp-form .dblp-note{color:rgba(255,255,255,.85)}'
			. '.dblp-steps{counter-reset:s;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:0 0 56px;padding:0;list-style:none}.dblp-steps li{position:relative;padding:20px;border:1px solid #dfe5e1;border-radius:16px;background:#fff}'
			. '.dblp-steps li::before{counter-increment:s;content:counter(s);display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;margin:0 0 10px;border-radius:50%;background:#0a1628;color:#fff;font-weight:800}'
			. '.dblp-steps strong{display:block;font-size:17px;margin:0 0 4px}.dblp-steps p{margin:0;color:#4b5563;font-size:15px;line-height:1.55}.dblp-tip{margin:-40px 0 56px;color:#4b5563;font-size:15px}'
			. '.dblp-two{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:0 0 56px}@media(max-width:760px){.dblp-two{grid-template-columns:1fr}}.dblp-card{padding:24px;border:1px solid #dfe5e1;border-radius:18px;background:#fff}'
			. 'html body .dblp-card h3{margin:0 0 8px;font-size:20px;font-weight:800;color:#0a1628}.dblp-card p{margin:0 0 14px;color:#374151;line-height:1.6}.dblp-contact{display:grid;gap:10px;margin:0 0 16px;padding:0;list-style:none}.dblp-contact a{font-weight:700;color:#0a1628}.dblp-contact span{display:block;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#4b5563}'
			. '.dblp-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 22px;border-radius:999px;background:#0a1628;color:#fff!important;font-weight:700;text-decoration:none!important}.dblp-btn--ghost{background:#fff;color:#0a1628!important;border:1.5px solid #cfd8d3}'
			. '.dblp-proof{margin:0 0 56px;padding:18px 22px;border-radius:16px;background:#f2fbe0;font-weight:600}'
			. '.dblp-faq details{border-top:1px solid #e4e7ec;padding:14px 0}.dblp-faq details:last-child{border-bottom:1px solid #e4e7ec}.dblp-faq summary{cursor:pointer;font-weight:700;font-size:17px;list-style:none;display:flex;justify-content:space-between;gap:12px}'
			. '.dblp-faq summary::after{content:"+";font-weight:800}.dblp-faq details[open] summary::after{content:"\2212"}.dblp-faq p{margin:10px 0 0;color:#374151;line-height:1.65;max-width:75ch}</style>';
	}
}

if ( ! function_exists( 'db_lp_icon' ) ) {
	function db_lp_icon( $d ) {
		return '<span class="dblp-ico" aria-hidden="true"><svg viewBox="0 0 24 24">' . $d . '</svg></span>';
	}
}

if ( ! function_exists( 'db_lp_benefits' ) ) {
	/** @param array $items [ [title, text, svg path], ... ] */
	function db_lp_benefits( array $items ) {
		$h = '<ul class="dblp-benefits">';
		foreach ( $items as $i ) {
			$h .= '<li>' . db_lp_icon( $i[2] ) . '<div><strong>' . esc_html( $i[0] ) . '</strong><p>' . esc_html( $i[1] ) . '</p></div></li>';
		}
		return $h . '</ul>';
	}
}

if ( ! function_exists( 'db_lp_steps' ) ) {
	function db_lp_steps( $title, array $steps, $id ) {
		$h = '<section aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2><ol class="dblp-steps">';
		foreach ( $steps as $s ) {
			$h .= '<li><strong>' . esc_html( $s[0] ) . '</strong><p>' . esc_html( $s[1] ) . '</p></li>';
		}
		return $h . '</ol></section>';
	}
}

if ( ! function_exists( 'db_lp_contact_card' ) ) {
	function db_lp_contact_card( $title, $text, $cta_label, $cta_href ) {
		$ph = db_lp_phone();
		return '<div class="dblp-card"><h3>' . esc_html( $title ) . '</h3><p>' . esc_html( $text ) . '</p><ul class="dblp-contact">'
			. '<li><span>Call</span><a href="' . esc_attr( $ph[1] ) . '">' . esc_html( $ph[0] ) . '</a></li>'
			. '<li><span>Email</span><a href="mailto:sales@domainbrothers.com">sales@domainbrothers.com</a></li></ul>'
			. '<a class="dblp-btn" href="' . esc_url( $cta_href ) . '">' . esc_html( $cta_label ) . '</a></div>';
	}
}

if ( ! function_exists( 'db_lp_faq' ) ) {
	/** Accordion FAQs (no script needed) plus FAQPage structured data with the same text. */
	function db_lp_faq( $title, array $items, $id ) {
		$h  = '<section class="dblp-faq" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2>';
		$ld = array();
		foreach ( $items as $q ) {
			$h   .= '<details><summary>' . esc_html( $q[0] ) . '</summary><p>' . esc_html( $q[1] ) . '</p></details>';
			$ld[] = array( '@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $q[1] ) );
		}
		$h .= '</section>';
		$h .= '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $ld ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG ) . '</script>';
		return $h;
	}
}

if ( ! function_exists( 'db_acquire_faqs' ) ) {
	function db_acquire_faqs() {
		return array(
			array( 'Is my domain guaranteed once I fill in the form?', 'No. We negotiate with the owner using your offer. If both sides agree on a price, the domain is yours. If the owner will not sell, you pay nothing.' ),
			array( 'Is this a backorder service?', 'No. Use this service for a name someone already owns. A backorder tries to catch a name when it expires.' ),
			array( 'Is there a fee if you do not get the domain?', 'No. You pay nothing if we do not acquire the domain.' ),
			array( 'What is your fee?', '15% of the agreed price, charged only when the acquisition completes.' ),
			array( 'How do I pay for the domain?', 'You pay the domain owner and Domain Brothers separately: the agreed price to the owner, and our 15% fee to us. Your broker sends exact instructions before anything is paid.' ),
			array( 'Will the owner know who I am?', 'Not unless you want them to. Your broker makes the approach, so your name and company stay private during the negotiation.' ),
			array( 'Why use Domain Brothers?', 'There is no upfront fee, our brokers have industry contacts, one account manager negotiates for you from start to finish, and we help with the transfer and setup.' ),
		);
	}
}

if ( ! function_exists( 'db_acquire_body' ) ) {
	/** The Acquire page content (the hero and the guide around it come from elsewhere). */
	function db_acquire_body() {
		$counts = db_lp_counts();
		$h      = db_lp_css() . '<div class="dblp">';
		$h     .= '<div class="dblp-split"><div><h2>We approach the owner for you</h2><p class="dblp-lead">Tell us the name you want. A broker finds the owner, keeps your identity private and negotiates within your budget. You pay nothing unless we get the name.</p>'
			. db_lp_benefits( array(
				array( 'No upfront cost', 'You pay nothing unless we acquire the name. On success, our fee is 15% of the agreed price.', '<circle cx="12" cy="12" r="9"/><path d="M8 12.5l3 3 5-6"/>' ),
				array( 'One dedicated broker', 'One account manager handles your request from first contact to transfer.', '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-7 8-7s8 2.6 8 7"/>' ),
				array( 'Negotiation on your behalf', 'Your broker negotiates with the owner to get the name within your budget. Your name and company stay private.', '<path d="M4 6h16v11H9l-5 4z"/><path d="M8 10h8M8 13h5"/>' ),
				array( 'Transfer and setup help', 'When the owner agrees, we guide the payment and the transfer, and help you set the name up.', '<path d="M7 7h11l-3-3M17 17H6l3 3"/>' ),
			) ) . '</div>'
			. '<div class="dblp-form" id="acquire-form"><h2>Discuss a domain acquisition</h2><p class="dblp-req">Fields marked * are required. Asking is free.</p>' . do_shortcode( '[contact-form-7 id="5432" title="Acquire Domain Form"]' ) . '</div></div>';
		$h .= db_lp_steps( 'How it works', array(
			array( 'Tell us the name', 'Fill in the form with the domain and your budget. It is free.' ),
			array( 'Discuss options', 'Your account manager calls or emails to agree a price range and a plan.' ),
			array( 'Negotiate with the owner', 'We contact the owner confidentially and negotiate on your behalf.' ),
			array( 'Transfer and support', 'Once the owner agrees, we guide the payment and the transfer, and help with setup.' ),
		), 'dblp-how' );
		if ( $counts['sale'] > 0 ) {
			$h .= '<p class="dblp-proof">Prefer a name that is ready today? ' . (int) $counts['sale'] . ' premium names are for sale now, with payment plans. <a href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse them</a>.</p>';
		}
		$h .= '<div class="dblp-two">'
			. db_lp_contact_card( 'Prefer to talk first?', 'Speak to a broker about the name you want before you send anything.', 'Get started: it is free', '#acquire-form' )
			. '<div class="dblp-card"><h3>What is the best domain for my business?</h3><p>Tell us about your business in the form and a broker suggests names, or browse ideas with our free domain finder.</p><a class="dblp-btn dblp-btn--ghost" href="' . esc_url( home_url( '/find-a-domain/' ) ) . '">Open the domain finder</a></div>'
			. '</div>';
		$h .= db_lp_faq( 'Questions about acquiring a domain', db_acquire_faqs(), 'dblp-faq-h' );
		return $h . '</div>';
	}
}

// Replace the old page content (after wpautop and shortcodes, so the markup stays as written).
add_filter( 'the_content', function ( $content ) {
	if ( is_admin() || ! is_page( 'acquire-premium-domain' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	return db_acquire_body();
}, 20 );
