<?php
/**
 * Social media handle pages: acquire and sell.
 *
 * Replaces the generic service layout on /acquire-social-media-handles/ and
 * /sell-social-media-handles/ with a purpose-built page: a hero with an
 * @handle check that pre-fills the enquiry form, a before/after profile
 * illustration, what the broker does, the process, platforms, questions
 * (with FAQPage data) and the enquiry form.
 *
 * The @handle check is a plain GET form: it reloads the page with ?handle=
 * and ?platform=, and the values are written into the enquiry message on the
 * server (inline scripts on this site wait for the first interaction, and
 * pages with a query string are never served from the page cache).
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_handles_platforms' ) ) {
	/**
	 * Platforms offered in the handle check, in display order.
	 *
	 * @return array<string,array{0:string,1:string}> key => array( label, short description ).
	 */
	function db_handles_platforms() {
		return array(
			'instagram' => array( 'Instagram', 'The name on a visual brand: posts, reels and shop.' ),
			'tiktok'    => array( 'TikTok', 'Short video, where a clean @name is easy to say and share.' ),
			'x'         => array( 'X (Twitter)', 'Real-time updates and customer conversations.' ),
			'youtube'   => array( 'YouTube', 'Channel handles that show in links, search and comments.' ),
			'facebook'  => array( 'Facebook', 'Page usernames used in links and Messenger.' ),
			'linkedin'  => array( 'LinkedIn', 'Company page URLs that buyers and hires recognise.' ),
			'threads'   => array( 'Threads', 'Follows the Instagram username of the account.' ),
			'other'     => array( 'Other platforms', 'Snapchat, Pinterest, Twitch, Telegram and more.' ),
		);
	}
}

if ( ! function_exists( 'db_handles_clean' ) ) {
	/**
	 * Normalises a requested handle: letters, digits, dot and underscore, 1 to 40 characters.
	 *
	 * @param string $raw Raw input.
	 * @return string Empty when nothing usable remains.
	 */
	function db_handles_clean( $raw ) {
		$h = strtolower( trim( (string) $raw ) );
		$h = ltrim( $h, '@' );
		$h = preg_replace( '/[^a-z0-9._]/', '', $h );
		return substr( (string) $h, 0, 40 );
	}
}

if ( ! function_exists( 'db_handles_pages' ) ) {
	/**
	 * Page copy for each handle page.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function db_handles_pages() {
		return array(
			'acquire-social-media-handles' => array(
				'mode'     => 'acquire',
				'eyebrow'  => 'Social media handle acquisition',
				'h1'       => 'Secure the @handle your customers expect',
				'lead'     => 'Your brand has the audience, but the name is taken. Our brokers find the holder, handle confidential outreach and negotiate. You approve the terms before anything is paid or moved.',
				'field'    => 'Handle you want',
				'button'   => 'Check this handle',
				'before'   => 'acmeofficial',
				'after'    => 'acme',
				'caption'  => 'Illustration: the same account, followers and posts, under the exact-match name.',
				'value_h'  => 'One name. Fewer wrong turns.',
				'value_p'  => 'An exact-match handle gives the official account a simple, recognisable place in search, campaigns and conversations.',
				'values'   => array(
					array( 'search', 'Easier to find', 'Customers who search for your brand land on the official account, not a lookalike.' ),
					array( 'shield', 'Easier to trust', 'A clean name sets your real profile apart from fan pages, resellers and impersonators.' ),
					array( 'horn', 'Easier to use', 'The same short @name on packaging, ads, podcasts and every platform you run.' ),
				),
				'broker_h' => 'A broker runs the route. You make the decisions.',
				'broker'   => array(
					array( 'users', 'Holder research', 'We identify who holds the name, how it is used and whether the platform permits a change of name to your account.' ),
					array( 'chat', 'Confidential outreach', 'We approach the holder under our own name, so your brand does not push the price up.' ),
					array( 'doc', 'Terms you approve', 'Price, payment and handover steps are written down and sent to you. Nothing moves until you approve.' ),
					array( 'swap', 'Managed handover', 'Where the platform allows it, the name moves to the account you already run, keeping followers and posts.' ),
				),
				'steps_h'  => 'How acquisition works',
				'steps'    => array(
					array( 'Share the target', 'Tell us the @handle, the platform, your current account and the result you need.' ),
					array( 'Agree the route', 'We check the holder and the platform rules, then agree a budget ceiling with you.' ),
					array( 'Approve the terms', 'We negotiate in confidence and bring you written terms to accept or decline.' ),
					array( 'Complete the handover', 'Payment runs through a trusted third-party service and the name moves to your account.' ),
				),
				'faq'      => array(
					array( 'Why does an exact-match handle matter?', 'People search for your brand name. When the exact @name belongs to someone else, they find a workaround such as an "official" or "hq" suffix, or a lookalike account. The exact name makes the real account easier to find, recognise and mention.' ),
					array( 'How much does a handle cost?', 'There is no fixed price. The platform, the name, how the holder uses it and the route the platform allows all affect the terms. We agree a budget ceiling with you before any outreach and never commit you to a price without your written approval.' ),
					array( 'What if the handle I want is active?', 'An active account needs a careful holder review. We assess whether outreach is appropriate and whether the platform permits the change, then bring any proposed terms to you. Some names are not available at any price; we tell you early.' ),
					array( 'Can an inactive handle be claimed for free?', 'Sometimes. A quiet profile is not automatically available, but some platforms release or reassign inactive names under their own rules. We check that route first, because it can cost far less than a negotiated purchase.' ),
					array( 'Is buying a social media handle allowed?', 'Platform rules differ and change. Several platforms restrict selling accounts or usernames. We only use routes the platform permits, such as a name release, a trademark request or a voluntary change of name by the holder, and we explain the route before you agree to anything.' ),
					array( 'Do I keep my followers and posts?', 'Where the platform allows a change of username, the new name moves to the account you already run. Followers, posts and history stay where they are.' ),
				),
			),
			'sell-social-media-handles'    => array(
				'mode'     => 'sell',
				'eyebrow'  => 'Sell a social media handle',
				'h1'       => 'Sell your @handle to a verified buyer',
				'lead'     => 'Short, memorable usernames are scarce. We value your handle, find serious buyers in our network and manage the negotiation. You confirm the terms in writing before any payment or handover.',
				'field'    => 'Handle you own',
				'button'   => 'Get a valuation',
				'before'   => 'yourname',
				'after'    => 'yourname',
				'caption'  => 'Illustration: a verified buyer, written terms and a payment confirmed before the handover.',
				'value_h'  => 'Why sell through a broker',
				'value_p'  => 'A rare handle is worth what a motivated buyer will pay. Reaching that buyer, and closing safely, is the hard part.',
				'values'   => array(
					array( 'chart', 'A realistic price', 'We look at length, word quality, platform, history and comparable sales before we suggest a price.' ),
					array( 'users', 'Serious buyers', 'Brands and founders who already asked us for names like yours, not anonymous inbox offers.' ),
					array( 'lock', 'A safe close', 'Terms in writing and payment confirmed before you change anything on your account.' ),
				),
				'broker_h' => 'We handle the selling. You keep control.',
				'broker'   => array(
					array( 'chart', 'Valuation', 'We assess the handle and agree an asking price and a floor with you.' ),
					array( 'horn', 'Private marketing', 'We present the name to matching buyers in our network, without public listings that attract scammers.' ),
					array( 'chat', 'Negotiation', 'We deal with every buyer message and only bring you real offers.' ),
					array( 'shield', 'Verified close', 'We confirm the buyer and the payment before you release the name.' ),
				),
				'steps_h'  => 'How selling works',
				'steps'    => array(
					array( 'Tell us about the handle', 'The @name, the platform and anything a buyer should know. We reply within one business day.' ),
					array( 'Agree the price', 'We share a valuation and agree an asking price and a floor with you.' ),
					array( 'Meet buyers', 'We present the handle to matching buyers and negotiate on your behalf.' ),
					array( 'Confirm the terms', 'You receive the agreed terms in writing before any payment is made.' ),
					array( 'Get paid and hand over', 'Once payment is confirmed, we guide you through the change of name step by step.' ),
				),
				'faq'      => array(
					array( 'How much is my handle worth?', 'Value depends on length, how common and brandable the word is, the platform, and demand from buyers at the time. Short dictionary words and well-known brand names sell for the most. We give you a realistic range before you decide to sell.' ),
					array( 'Do I need many followers to sell?', 'No. Buyers usually want the name, not the audience. A short or brandable handle can be valuable with very few followers.' ),
					array( 'How do I know the buyer is genuine?', 'We qualify every buyer before we pass on an offer, and payment is confirmed before you change anything on your account. Never hand over a username to a buyer who contacted you directly and asks you to move first.' ),
					array( 'Is selling a social media handle allowed?', 'Platform rules differ and change, and some platforms prohibit selling accounts or usernames. We explain the rules for your platform and only use routes the platform permits, such as releasing the name so the buyer can claim it.' ),
					array( 'How long does a sale take?', 'Some names sell within days; others need weeks to reach the right buyer. We keep you informed at each step and you can stop at any time before you accept terms.' ),
				),
			),
		);
	}
}

if ( ! function_exists( 'db_handles_icon' ) ) {
	/**
	 * @param string $key  Icon key from db_svc_icon().
	 * @param int    $size Pixel size.
	 * @return string SVG markup or an empty string.
	 */
	function db_handles_icon( $key, $size = 22 ) {
		return function_exists( 'db_svc_icon' ) ? db_svc_icon( $key, $size ) : '';
	}
}

if ( ! function_exists( 'db_handles_mock' ) ) {
	/**
	 * Before/after profile illustration (HTML and CSS, no images).
	 *
	 * @param array  $p      Page copy.
	 * @param string $handle Requested handle, if any.
	 * @return string
	 */
	function db_handles_mock( array $p, $handle ) {
		$after = '' !== $handle ? $handle : $p['after'];
		if ( 'sell' === $p['mode'] ) {
			$name = '' !== $handle ? $handle : $p['before'];
			$rows = array( array( 'check', 'Buyer verified' ), array( 'doc', 'Terms confirmed in writing' ), array( 'lock', 'Payment confirmed' ) );
			$list = '';
			foreach ( $rows as $r ) {
				$list .= '<li><span>' . db_handles_icon( $r[0], 16 ) . '</span>' . esc_html( $r[1] ) . '</li>';
			}
			return '<figure class="dbh-mock"><div class="dbh-phone"><div class="dbh-prof"><span class="dbh-ava" aria-hidden="true">' . esc_html( strtoupper( substr( $name, 0, 1 ) ) ) . '</span>'
				. '<div><p class="dbh-at">@' . esc_html( $name ) . '</p><p class="dbh-sub">Ready to sell</p></div></div>'
				. '<div class="dbh-offer"><p class="dbh-offer-k">Offer received</p><p class="dbh-offer-v">Accepted terms</p></div>'
				. '<ul class="dbh-checks">' . $list . '</ul></div>'
				. '<figcaption>' . esc_html( $p['caption'] ) . '</figcaption></figure>';
		}
		$stats = '<div class="dbh-stats"><span><b>128</b> posts</span><span><b>125K</b> followers</span><span><b>42</b> following</span></div>';
		return '<figure class="dbh-mock"><div class="dbh-phone">'
			. '<div class="dbh-prof"><span class="dbh-ava" aria-hidden="true">' . esc_html( strtoupper( substr( $after, 0, 1 ) ) ) . '</span><div>'
			. '<p class="dbh-at"><s>@' . esc_html( $p['before'] ) . '</s></p><p class="dbh-at dbh-new">@' . esc_html( $after ) . '</p></div></div>'
			. $stats
			. '<div class="dbh-toast">' . db_handles_icon( 'check', 16 ) . '<span><b>Username updated</b> Same account, same followers</span></div></div>'
			. '<figcaption>' . esc_html( $p['caption'] ) . '</figcaption></figure>';
	}
}

if ( ! function_exists( 'db_handles_render' ) ) {
	/**
	 * Full page body for a handle page.
	 *
	 * @param string $slug Page slug.
	 * @return string Empty when the slug is not a handle page.
	 */
	function db_handles_render( $slug ) {
		$pages = db_handles_pages();
		if ( ! isset( $pages[ $slug ] ) ) {
			return '';
		}
		$p         = $pages[ $slug ];
		$platforms = db_handles_platforms();
		// phpcs:disable WordPress.Security.NonceVerification -- read-only pre-fill of a public form.
		$handle   = isset( $_GET['handle'] ) ? db_handles_clean( wp_unslash( $_GET['handle'] ) ) : '';
		$platform = isset( $_GET['platform'] ) ? sanitize_key( wp_unslash( $_GET['platform'] ) ) : '';
		// phpcs:enable
		if ( ! isset( $platforms[ $platform ] ) ) {
			$platform = '';
		}
		$self = get_permalink();

		// Hero.
		$opts = '';
		foreach ( $platforms as $k => $pl ) {
			$opts .= '<option value="' . esc_attr( $k ) . '"' . selected( $platform, $k, false ) . '>' . esc_html( $pl[0] ) . '</option>';
		}
		$check = '<form class="dbh-check" method="get" action="' . esc_url( $self ) . '#get-started" role="search" aria-label="' . esc_attr( $p['button'] ) . '">'
			. '<label class="dbh-field"><span class="screen-reader-text">' . esc_html( $p['field'] ) . '</span><span class="dbh-atsign" aria-hidden="true">@</span>'
			. '<input type="text" name="handle" value="' . esc_attr( $handle ) . '" placeholder="' . esc_attr( strtolower( $p['field'] ) ) . '" maxlength="40" autocomplete="off" autocapitalize="none" spellcheck="false" required></label>'
			. '<label class="dbh-plat"><span class="screen-reader-text">Platform</span><select name="platform">' . $opts . '</select></label>'
			. '<button type="submit">' . esc_html( $p['button'] ) . '</button></form>';
		$hero = '<section class="dbh-hero"><div class="dbh-hero-copy"><p class="dbp-eyebrow">' . esc_html( $p['eyebrow'] ) . '</p>'
			. '<h1 class="dbh-h1">' . esc_html( $p['h1'] ) . '</h1><p class="dbh-lead">' . esc_html( $p['lead'] ) . '</p>' . $check
			. '<p class="dbh-assure">' . db_handles_icon( 'lock', 16 ) . '<span>Confidential. A broker replies within one business day.</span></p></div>'
			. db_handles_mock( $p, $handle ) . '</section>';

		// Platform strip.
		$strip = '<ul class="dbh-strip" aria-label="Platforms we work with">';
		foreach ( $platforms as $k => $pl ) {
			if ( 'other' !== $k ) {
				$strip .= '<li>' . esc_html( $pl[0] ) . '</li>';
			}
		}
		$strip .= '<li>and more</li></ul>';

		// Value cards.
		$vals = '';
		foreach ( $p['values'] as $v ) {
			$vals .= '<li><span class="dbh-ico">' . db_handles_icon( $v[0], 22 ) . '</span><h3>' . esc_html( $v[1] ) . '</h3><p>' . esc_html( $v[2] ) . '</p></li>';
		}
		$value = '<section class="dbh-sec" aria-labelledby="dbh-vh"><div class="dbh-head"><h2 id="dbh-vh">' . esc_html( $p['value_h'] ) . '</h2><p>' . esc_html( $p['value_p'] ) . '</p></div><ul class="dbh-cards">' . $vals . '</ul></section>';

		// What the broker does.
		$brk = '';
		foreach ( $p['broker'] as $b ) {
			$brk .= '<li><span class="dbh-ico">' . db_handles_icon( $b[0], 22 ) . '</span><div><h3>' . esc_html( $b[1] ) . '</h3><p>' . esc_html( $b[2] ) . '</p></div></li>';
		}
		$broker = '<section class="dbh-sec dbh-dark" aria-labelledby="dbh-bh"><div class="dbh-head"><p class="dbp-eyebrow">What we handle</p><h2 id="dbh-bh">' . esc_html( $p['broker_h'] ) . '</h2></div><ul class="dbh-broker">' . $brk . '</ul></section>';

		// Process.
		$st = '';
		foreach ( $p['steps'] as $i => $s ) {
			$st .= '<li><span class="dbh-num" aria-hidden="true">' . ( $i + 1 ) . '</span><h3>' . esc_html( $s[0] ) . '</h3><p>' . esc_html( $s[1] ) . '</p></li>';
		}
		$steps = '<section class="dbh-sec" aria-labelledby="dbh-sh"><div class="dbh-head"><p class="dbp-eyebrow">Process</p><h2 id="dbh-sh">' . esc_html( $p['steps_h'] ) . '</h2></div><ol class="dbh-steps">' . $st . '</ol></section>';

		// Platforms grid.
		$pg = '';
		foreach ( $platforms as $k => $pl ) {
			$pg .= '<li><span class="dbh-mono" aria-hidden="true">' . esc_html( 'other' === $k ? '+' : strtoupper( substr( $pl[0], 0, 1 ) ) ) . '</span><div><h3>' . esc_html( $pl[0] ) . '</h3><p>' . esc_html( $pl[1] ) . '</p></div></li>';
		}
		$plats = '<section class="dbh-sec" aria-labelledby="dbh-ph"><div class="dbh-head"><p class="dbp-eyebrow">Platforms</p><h2 id="dbh-ph">' . ( 'sell' === $p['mode'] ? 'Handles we can sell' : 'Handles we can help you secure' ) . '</h2><p>Each platform has its own rules for usernames. We review the route separately for every platform you name.</p></div><ul class="dbh-plats">' . $pg . '</ul></section>';

		// Questions.
		$fq     = '';
		$schema = array();
		foreach ( $p['faq'] as $f ) {
			$fq      .= '<details><summary>' . esc_html( $f[0] ) . '</summary><p>' . esc_html( $f[1] ) . '</p></details>';
			$schema[] = array( '@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f[1] ) );
		}
		$faq = '<section class="dbh-sec" aria-labelledby="dbh-qh"><div class="dbh-head"><p class="dbp-eyebrow">Questions</p><h2 id="dbh-qh">Frequently asked questions</h2></div><div class="dbh-faq">' . $fq . '</div></section>'
			. '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $schema ) ) . '</script>';

		// Enquiry form, pre-filled from the handle check.
		$form = function_exists( 'db_svc_form_section' ) ? db_svc_form_section( 'Social media handles' ) : '';
		if ( '' !== $form && '' !== $handle ) {
			$msg  = ( 'sell' === $p['mode'] ? 'I would like to sell @' : 'I would like to acquire @' ) . $handle . ( '' !== $platform && 'other' !== $platform ? ' on ' . $platforms[ $platform ][0] : '' ) . '.';
			$form = preg_replace( '#(<textarea\b[^>]*name="contact-msg"[^>]*>)\s*</textarea>#', '$1' . str_replace( '$', '\\$', esc_textarea( $msg ) ) . '</textarea>', $form, 1 );
		}

		$note = '<p class="dbh-note">Platform rules for usernames change often. We only use routes the platform permits and cannot guarantee that a particular name can be transferred.</p>';

		return '<div class="dbp dbh dbh--' . esc_attr( $p['mode'] ) . '">' . $hero . $strip . $value . $broker . $steps . $plats . $faq . $form . $note . '</div>';
	}
}

add_filter( 'the_content', function ( $html ) {
	if ( ! is_page( array_keys( db_handles_pages() ) ) || ! in_the_loop() || ! is_main_query() ) {
		return $html;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return $html;
	}
	$out = db_handles_render( $post->post_name );
	return '' === $out ? $html : $out;
}, 40 );

// The page title is in the hero.
add_filter( 'body_class', function ( $classes ) {
	if ( is_page( array_keys( db_handles_pages() ) ) ) {
		$classes[] = 'db-handles-page';
	}
	return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page( array_keys( db_handles_pages() ) ) ) {
		return;
	}
	$css = '.db-handles-page .entry-header,.db-handles-page .page-header,.db-handles-page h1.entry-title,.db-handles-page h1.page-title{display:none!important}'
		. '.dbh{max-width:1180px;margin:0 auto;padding:0 var(--m-gutter,20px)}'
		. '.dbh-hero{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);gap:clamp(28px,5vw,64px);align-items:center;padding:clamp(28px,5vw,64px) 0 clamp(24px,4vw,40px)}'
		. 'html body .dbh-hero-copy .dbh-h1{margin-top:0!important}html body .dbh-h1{margin:0 0 18px;font-size:clamp(36px,5.4vw,62px)!important;line-height:1.02;letter-spacing:-.035em;font-weight:800;color:var(--m-ink,#111827)!important;text-wrap:balance}'
		. 'html body .dbh-lead{margin:0 0 26px;max-width:56ch;font-size:clamp(17px,1.6vw,19px)!important;line-height:1.6;color:var(--m-text,#374151)!important}'
		. '.dbh-check{display:flex;flex-wrap:wrap;gap:8px;padding:8px;border:1.5px solid #d6dbe2;border-radius:20px;background:#fff;box-shadow:0 12px 32px -18px rgba(12,20,36,.35);max-width:620px}'
		. '.dbh-field{flex:1 1 170px;display:flex;align-items:center;gap:4px;padding:0 6px 0 12px;margin:0}'
		. '.dbh-atsign{font-size:20px;font-weight:800;color:var(--m-accent,#0b7a75)}'
		. 'html body .dbh-check input[type=text]{flex:1;min-width:0;height:50px;border:0!important;box-shadow:none!important;background:transparent!important;font-size:18px!important;font-weight:600;color:var(--m-ink,#111827)!important;padding:0 4px!important;outline:0}'
		. '.dbh-plat{flex:0 1 140px;margin:0}html body .dbh-check select{width:100%;height:50px;border:0!important;border-radius:14px!important;background:#f1f4f6!important;padding:0 12px!important;font-size:15.5px!important;font-weight:600;color:var(--m-ink,#111827)!important}'
		. 'html body .dbh-check button{flex:0 0 auto;height:50px;padding:0 22px;border:0;border-radius:14px;background:#111827;color:#fff;font-size:16px;font-weight:700;cursor:pointer}'
		. 'html body .dbh-check button:hover{background:var(--m-accent,#0b7a75)}.dbh-check:focus-within{border-color:var(--m-accent,#0b7a75)}'
		. 'html body .dbh-check input:focus-visible,html body .dbh-check select:focus-visible,html body .dbh-check button:focus-visible{outline:3px solid rgba(11,122,117,.45);outline-offset:2px}'
		. 'html body .dbh-assure{display:flex;align-items:center;gap:8px;margin:14px 0 0;font-size:14.5px!important;color:#5b6472!important}.dbh-assure svg{color:var(--m-accent,#0b7a75)}'
		. '.dbh-mock{margin:0;display:flex;flex-direction:column;align-items:center;gap:12px}'
		. '.dbh-phone{width:100%;max-width:360px;padding:24px;border-radius:32px;background:radial-gradient(420px 260px at 100% 0%,rgba(25,179,166,.32),rgba(25,179,166,0) 60%),#0c1424;color:#fff;box-shadow:0 30px 60px -30px rgba(12,20,36,.6)}'
		. '.dbh-prof{display:flex;align-items:center;gap:14px}.dbh-ava{flex:none;display:inline-flex;align-items:center;justify-content:center;width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#34d5c5,#0b7a75);font-size:28px;font-weight:800;color:#fff}'
		. 'html body .dbh-at{margin:0;font-size:15px!important;color:rgba(255,255,255,.55)!important;line-height:1.4}html body .dbh-at s{text-decoration-thickness:2px}html body .dbh-new,html body .dbh--sell .dbh-at{font-size:22px!important;font-weight:800;color:#fff!important}'
		. 'html body .dbh-sub{margin:2px 0 0;font-size:14px!important;color:#b6f0ea!important}'
		. '.dbh-stats{display:flex;justify-content:space-between;margin:20px 0;padding:14px 0;border-top:1px solid rgba(255,255,255,.12);border-bottom:1px solid rgba(255,255,255,.12);font-size:13.5px;color:rgba(255,255,255,.7)}.dbh-stats b{display:block;font-size:18px;color:#fff}'
		. '.dbh-toast{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:16px;background:#fff;color:#111827;font-size:13.5px;line-height:1.35}.dbh-toast svg{flex:none;color:#0b7a75}.dbh-toast b{display:block;font-size:14.5px}'
		. '.dbh-offer{margin:20px 0 14px;padding:14px 16px;border-radius:16px;background:rgba(255,255,255,.08)}html body .dbh-offer-k{margin:0;font-size:13px!important;color:rgba(255,255,255,.65)!important}html body .dbh-offer-v{margin:2px 0 0;font-size:20px!important;font-weight:800;color:#fff!important}'
		. '.dbh-checks{margin:0;padding:0;list-style:none;display:grid;gap:8px}html body .dbh-checks li{display:flex;align-items:center;gap:10px;margin:0;padding:10px 12px;border-radius:12px;background:#fff;color:#111827!important;font-size:14px!important;font-weight:600}.dbh-checks span{display:inline-flex;color:#0b7a75}'
		. 'html body .dbh-mock figcaption{max-width:340px;font-size:13px!important;line-height:1.45;color:#6b7280!important;text-align:center}'
		. '.dbh-strip{display:flex;flex-wrap:wrap;justify-content:center;gap:10px 28px;margin:0 0 clamp(36px,6vw,72px);padding:18px 0;border-top:1px solid var(--m-line,#e5e7eb);border-bottom:1px solid var(--m-line,#e5e7eb);list-style:none}'
		. 'html body .dbh-strip li{margin:0;font-size:15px!important;font-weight:700;color:#5b6472!important;letter-spacing:.01em}'
		. '.dbh-sec{margin:0 0 clamp(48px,7vw,96px)}.dbh-head{max-width:720px;margin:0 0 clamp(22px,3vw,36px)}'
		. 'html body .dbh-head h2{margin:0 0 10px;font-size:clamp(28px,3.6vw,42px)!important;line-height:1.08;letter-spacing:-.03em;font-weight:800;color:var(--m-ink,#111827)!important;text-wrap:balance}'
		. 'html body .dbh-head p:not(.dbp-eyebrow){margin:0;font-size:17px!important;line-height:1.6;color:var(--m-text,#374151)!important}'
		. 'html body .dbh h3{margin:0 0 6px!important;font-size:18px!important;line-height:1.25;font-weight:800;letter-spacing:-.01em;color:var(--m-ink,#111827)!important}'
		. 'html body .dbh li p,html body .dbh-faq p{margin:0;font-size:15.5px!important;line-height:1.6;color:var(--m-text,#374151)!important}'
		. '.dbh-ico{flex:none;display:inline-flex;align-items:center;justify-content:center;width:46px;height:46px;border-radius:14px;background:#e8f4f3;color:var(--m-accent,#0b7a75)}'
		. '.dbh-cards,.dbh-broker,.dbh-steps,.dbh-plats{margin:0;padding:0;list-style:none;display:grid;gap:16px}html body .dbh-cards>li,html body .dbh-broker>li,html body .dbh-steps>li,html body .dbh-plats>li{margin:0!important}'
		. '.dbh-cards{grid-template-columns:repeat(3,minmax(0,1fr))}html body .dbh-cards li{margin:0;padding:26px;border:1px solid var(--m-line,#e5e7eb);border-radius:22px;background:#fff}.dbh-cards .dbh-ico{margin:0 0 18px}'
		. '.dbh-dark{padding:clamp(28px,5vw,56px);border-radius:32px;background:radial-gradient(900px 420px at 0% 0%,rgba(25,179,166,.30),rgba(25,179,166,0) 60%),#0c1424}'
		. 'html body .dbh-dark .dbh-head h2{color:#fff!important}html body .dbh-dark .dbp-eyebrow{color:#b6f0ea!important}'
		. '.dbh-broker{grid-template-columns:repeat(2,minmax(0,1fr))}html body .dbh-broker li{display:flex;gap:16px;margin:0;padding:22px;border-radius:20px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1)}'
		. 'html body .dbh .dbh-broker li h3{color:#fff!important}html body .dbh .dbh-broker li p{color:rgba(255,255,255,.8)!important}.dbh-broker .dbh-ico{background:rgba(255,255,255,.1);color:#34d5c5}'
		. '.dbh-steps{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}html body .dbh-steps li{position:relative;margin:0;padding:24px;border-radius:22px;background:#f5f7f8}'
		. '.dbh-num{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;margin:0 0 16px;border-radius:50%;background:#111827;color:#fff;font-weight:800;font-size:15px}'
		. '.dbh-plats{grid-template-columns:repeat(auto-fill,minmax(250px,1fr))}html body .dbh-plats li{display:flex;gap:14px;margin:0;padding:18px;border:1px solid var(--m-line,#e5e7eb);border-radius:18px;background:#fff}'
		. '.dbh-mono{flex:none;display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border-radius:12px;background:#111827;color:#fff;font-weight:800;font-size:17px}'
		. 'html body .dbh-plats h3{font-size:16.5px!important;margin:0 0 4px}html body .dbh-plats p{font-size:14.5px!important}'
		. '.dbh-faq{max-width:860px;border-top:1px solid var(--m-line,#e5e7eb)}.dbh-faq details{border-bottom:1px solid var(--m-line,#e5e7eb)}'
		. 'html body .dbh-faq summary{display:flex;justify-content:space-between;gap:16px;padding:20px 2px;cursor:pointer;list-style:none;font-size:17.5px!important;font-weight:700;color:var(--m-ink,#111827)}'
		. '.dbh-faq summary::-webkit-details-marker{display:none}.dbh-faq summary::after{content:"+";flex:none;font-size:22px;line-height:1;color:var(--m-accent,#0b7a75)}.dbh-faq details[open] summary::after{content:"\2212"}'
		. 'html body .dbh-faq details p{padding:0 2px 20px;max-width:70ch}'
		. 'html body .dbh-note{margin:24px 0 clamp(32px,5vw,56px);font-size:13.5px!important;line-height:1.55;color:#6b7280!important;max-width:80ch}'
		. '.dbh .dbsv-form{margin-top:0}'
		. '@media (max-width:900px){.dbh-hero{grid-template-columns:minmax(0,1fr)}.dbh-cards,.dbh-broker{grid-template-columns:minmax(0,1fr)}}'
		. '@media (max-width:480px){html body .dbh-check button,.dbh-plat{flex:1 1 100%}html body .dbh-broker li{flex-direction:column;gap:12px}.dbh-dark{padding:24px 18px;border-radius:24px}.dbh-phone{padding:18px}.dbh-stats{font-size:12.5px}}';
	if ( function_exists( 'db_external_style' ) && db_external_style( 'handles', $css ) ) {
		return;
	}
	wp_register_style( 'db-handles', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-handles' );
	wp_add_inline_style( 'db-handles', $css );
}, 116 );
