<?php
/**
 * Signed-out view of /my-wishlist/: a page that explains the wishlist and the
 * account behind it, with Sign in and Create account buttons.
 *
 * Until now a visitor who opened the wishlist from the menu landed straight on
 * a sign-in card, with no word on what the account does. The sign-in and
 * create-account cards are unchanged and open from the buttons here
 * (/my-wishlist/?auth=signin and ?auth=register), and from the header links.
 *
 * Every statement below describes something the account block does: the five
 * account sections (db_acct_tabs), the alert kinds, the one-click stop link and
 * the passwordless 6-digit code.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_wl_intro_active' ) ) {
	/** Whether this request shows the explainer (signed out, no ?auth= card requested). */
	function db_wl_intro_active() {
		if ( is_admin() || ! function_exists( 'db_wl_is_page_request' ) || ! db_wl_is_page_request() ) {
			return false;
		}
		if ( function_exists( 'db_wl_email' ) && '' !== db_wl_email() ) {
			return false;
		}
		return ! isset( $_GET['auth'] ); // phpcs:ignore WordPress.Security.NonceVerification
	}
}

if ( ! function_exists( 'db_wl_intro_icon' ) ) {
	function db_wl_intro_icon( $path ) {
		return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
	}
}

if ( ! function_exists( 'db_wl_intro_html' ) ) {
	/** @return string The explainer section (escaped). */
	function db_wl_intro_html() {
		$signin   = esc_url( add_query_arg( 'auth', 'signin', home_url( '/my-wishlist/' ) ) . '#db-au' );
		$register = esc_url( add_query_arg( 'auth', 'register', home_url( '/my-wishlist/' ) ) . '#db-au' );
		$minutes  = defined( 'DB_WL_CODE_TTL' ) ? max( 1, (int) round( DB_WL_CODE_TTL / 60 ) ) : 10;

		$art = '<svg class="dbwi-art" viewBox="0 0 420 300" role="img" aria-label="A saved domain with a price alert">'
			. '<defs><linearGradient id="dbwi-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#c8f03a"/><stop offset="1" stop-color="#8fd10f"/></linearGradient></defs>'
			. '<rect x="34" y="40" width="300" height="190" rx="26" fill="#14263d" stroke="rgba(255,255,255,.14)" stroke-width="2"/>'
			. '<rect x="60" y="70" width="150" height="16" rx="8" fill="rgba(255,255,255,.22)"/><rect x="60" y="100" width="210" height="34" rx="10" fill="#fff"/>'
			. '<text x="76" y="124" font-family="Inter,Arial,sans-serif" font-size="20" font-weight="800" fill="#0f1b2d">yourname.com</text>'
			. '<rect x="60" y="154" width="96" height="30" rx="15" fill="#c8f03a"/><text x="76" y="174" font-family="Inter,Arial,sans-serif" font-size="14" font-weight="800" fill="#0f1b2d">Saved</text>'
			. '<path d="M300 120c0-7 6-12 12-12 4 0 7 2 9 5 2-3 5-5 9-5 6 0 12 5 12 12 0 11-21 26-21 26s-21-15-21-26z" transform="translate(-30 30) scale(1.2)" fill="url(#dbwi-g)"/>'
			. '<g transform="translate(250 16)"><rect width="160" height="58" rx="29" fill="#fff"/><circle cx="30" cy="29" r="14" fill="#0f1b2d"/>'
			. '<path d="M30 20a6 6 0 0 0-6 6v4l-2 3h16l-2-3v-4a6 6 0 0 0-6-6z" fill="#c8f03a"/><text x="54" y="26" font-family="Inter,Arial,sans-serif" font-size="12" font-weight="800" fill="#0f1b2d">Price alert</text>'
			. '<text x="54" y="44" font-family="Inter,Arial,sans-serif" font-size="12" fill="#4b5563">Price dropped</text></g></svg>';

		$steps = array(
			array( 'Create a free account', 'Enter your email and the 6-digit code we send you. There is no password to remember.' ),
			array( 'Save the names you like', 'Open any domain and choose Add to wishlist. Your list is there on every device you sign in on.' ),
			array( 'We watch them for you', 'Price changes, offers on a name and sales are recorded as alerts, and we can email them to you.' ),
			array( 'Act when you are ready', 'Make an offer or buy. Your offers and transfer stages stay in the same account.' ),
		);
		$features = array(
			array( 'Wishlist', 'Every saved name in one list, with its price, and the same list on your phone, tablet and desktop.', '<path d="M12 20s-7-4.4-8.6-8.8C2.300 8 4.200 5 7.300 5c1.900 0 3.200 1 4.700 2.800C13.500 6 14.800 5 16.700 5c3.100 0 5 3 3.900 6.200C19 15.600 12 20 12 20z"/>' ),
			array( 'Alerts', 'Price changes, sales, offers, status changes and matches for the names you follow, newest first.', '<path d="M6 16V11a6 6 0 1112 0v5l2 2H4z"/><path d="M10 20a2 2 0 004 0"/>' ),
			array( 'My offers', 'Every offer you have made, the reply, and the full conversation, so you can pick it up any time.', '<path d="M4 6h16v11H9l-5 4z"/><path d="M8 10h8M8 13h5"/>' ),
			array( 'Transfers', 'After you buy, each transfer stage from payment to completion, with notes from our team.', '<path d="M4 9h15M15 5l4 4-4 4M20 15H5M9 11l-4 4 4 4"/>' ),
			array( 'Discover', 'Names suggested from the ones you save, so a shortlist grows without a new search.', '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.500-3.500"/>' ),
		);
		$faq = array(
			array( 'Does an account cost anything?', 'No. The account and the wishlist are free.' ),
			array( 'How do I sign in without a password?', 'Enter your email and we send a 6-digit code that works for ' . $minutes . ' minutes. Enter it on the site and you are in.' ),
			array( 'Will you email me about every change?', 'Only about the domains and searches you chose to follow. Every alert email has a one-click stop link, and you can turn all alert emails off from the Alerts section of your account.' ),
			array( 'Do I need an account to ask about a name that is not listed?', 'No. Tell us the domain and a broker replies within one business day.' ),
		);

		$h = '<div class="dbwi">'
			. '<header class="dbwi-hero"><div class="dbwi-copy"><p class="dbwi-eyebrow">My wishlist</p>'
			. '<h1 class="dbwi-title">Save the names you want. We keep watch.</h1>'
			. '<p class="dbwi-lede">Keep a list of domains, hear when a price changes or a name sells, and pick up your offers on any device. A free account, with no password.</p>'
			. '<p class="dbwi-actions"><a class="dbwi-btn dbwi-btn--lime" href="' . $signin . '">Sign in</a><a class="dbwi-btn dbwi-btn--ghost" href="' . $register . '">Create account</a></p></div>'
			. $art . '</header>'
			. '<ul class="dbwi-pills"><li>Free account</li><li>No password to remember</li><li>Stop alert emails in one click</li></ul>';

		$h .= '<section class="dbwi-sec" aria-labelledby="dbwi-how"><h2 id="dbwi-how">How the wishlist works</h2><ol class="dbwi-steps">';
		foreach ( $steps as $i => $s ) {
			$h .= '<li><span class="dbwi-n" aria-hidden="true">' . sprintf( '%02d', $i + 1 ) . '</span><strong>' . esc_html( $s[0] ) . '</strong><span>' . esc_html( $s[1] ) . '</span></li>';
		}
		$h .= '</ol></section>';

		$h .= '<section class="dbwi-sec" aria-labelledby="dbwi-in"><h2 id="dbwi-in">What is in your account</h2><ul class="dbwi-feats">';
		foreach ( $features as $f ) {
			$h .= '<li><span class="dbwi-ico">' . db_wl_intro_icon( $f[2] ) . '</span><strong>' . esc_html( $f[0] ) . '</strong><span>' . esc_html( $f[1] ) . '</span></li>';
		}
		$h .= '</ul></section>';

		$h .= '<section class="dbwi-sec" aria-labelledby="dbwi-faq"><h2 id="dbwi-faq">Questions</h2><div class="dbwi-faq">';
		foreach ( $faq as $q ) {
			$h .= '<details><summary>' . esc_html( $q[0] ) . '</summary><p>' . esc_html( $q[1] ) . '</p></details>';
		}
		$h .= '</div></section>';

		$h .= '<section class="dbwi-cta" aria-label="Start your wishlist"><h2>Ready to start your list?</h2><p class="dbwi-actions"><a class="dbwi-btn dbwi-btn--navy" href="' . $register . '">Create account</a>'
			. '<a class="dbwi-link" href="' . $signin . '">Already have an account? Sign in</a></p></section></div>';
		return $h;
	}
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! db_wl_intro_active() ) {
		return;
	}
	// Designer's tokens: navy #0f1b2d, lime #b5e61d / #c8f03a, soft lime #eef8d2, line #e4e7ec, panel #f1f3f6.
	$css = '.dbwi{--n:#0f1b2d;--l:#b5e61d;--l2:#c8f03a;--ls:#eef8d2;--li:#3f6212;--ln:#e4e7ec;--ink:#0f172a;--tx:#374151;--pn:#f1f3f6;display:grid;gap:clamp(34px,5vw,56px);margin:0 0 8px}'
		. 'html body.db-wishlist-page #page #content.site-content{padding-left:0!important;padding-right:0!important}'
		. 'html body.db-wishlist-page:has(.dbwi) #page #content.site-content>#primary.content-area{box-sizing:border-box!important;float:none!important;width:100%!important;max-width:1360px!important;margin-left:auto!important;margin-right:auto!important;padding-left:clamp(20px,3vw,40px)!important;padding-right:clamp(20px,3vw,40px)!important}'
		. 'html body.db-wishlist-page:has(.dbwi) #main.site-main{max-width:100%!important;margin-left:0!important;margin-right:0!important;padding-left:0!important;padding-right:0!important}'
		. '.dbwi h2{margin:0 0 18px;color:var(--ink);font-size:clamp(26px,3.2vw,38px);font-weight:900;line-height:1.1;letter-spacing:-.025em;text-align:left}'
		. '.dbwi-hero{position:relative;isolation:isolate;overflow:hidden;display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,.9fr);align-items:center;gap:24px;min-height:320px;padding:clamp(28px,4.4vw,52px) clamp(24px,4.4vw,56px);border-radius:24px;color:#fff;box-shadow:0 18px 40px rgba(12,20,36,.16);'
		. 'background-color:#0c1424;background-image:radial-gradient(520px 340px at 82% 45%,rgba(25,179,166,.30) 0%,rgba(25,179,166,0) 70%),radial-gradient(640px 360px at 0% 100%,rgba(37,99,235,.18) 0%,rgba(37,99,235,0) 60%),linear-gradient(rgba(255,255,255,.035) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.035) 1px,transparent 1px);background-size:auto,auto,44px 44px,44px 44px}'
		. '.dbwi-eyebrow{display:inline-flex;align-items:center;margin:0 0 16px;padding:8px 16px;border-radius:999px;background:var(--l);color:var(--n);font-size:12px;font-weight:800;line-height:1;letter-spacing:.1em;text-transform:uppercase}'
		. 'html body .dbwi .dbwi-title{margin:0 0 16px;color:#fff!important;font-size:clamp(32px,4.2vw,52px);font-weight:900;line-height:1.06;letter-spacing:-.025em;text-align:left;text-wrap:balance}'
		. 'html body .dbwi .dbwi-lede{margin:0 0 24px;max-width:46ch;color:rgba(255,255,255,.9)!important;font-size:clamp(16px,1.4vw,18px);line-height:1.55}'
		. '.dbwi-actions{display:flex;flex-wrap:wrap;align-items:center;gap:14px 18px;margin:0}'
		. 'html body a.dbwi-btn{display:inline-flex;align-items:center;justify-content:center;min-height:50px;padding:0 30px;border-radius:999px;font-size:17px;font-weight:800;line-height:1;text-decoration:none;transition:background-color .15s ease,border-color .15s ease}'
		. 'html body a.dbwi-btn--lime{background:var(--l2);color:var(--n)}html body a.dbwi-btn--lime:hover{background:#d6f65f;color:var(--n)}'
		. 'html body a.dbwi-btn--ghost{border:1.5px solid rgba(255,255,255,.45);color:#fff}html body a.dbwi-btn--ghost:hover{border-color:#fff;background:rgba(255,255,255,.08);color:#fff}'
		. 'html body a.dbwi-btn--navy{background:var(--n);color:#fff;box-shadow:0 6px 16px rgba(15,27,45,.18)}html body a.dbwi-btn--navy:hover{background:#1c2c46;color:#fff}'
		. 'html body a.dbwi-btn:focus-visible,html body a.dbwi-link:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(181,230,29,.7)}'
		. '.dbwi-art{width:100%;max-width:440px;height:auto;justify-self:end;filter:drop-shadow(0 22px 34px rgba(0,0,0,.4))}'
		. '.dbwi-pills{display:flex;flex-wrap:wrap;gap:12px 28px;margin:-18px 0 0;padding:16px 26px;border:1px solid var(--ln);border-radius:16px;background:var(--pn);list-style:none}'
		. '.dbwi-pills li{display:flex;align-items:center;gap:10px;margin:0;color:var(--ink);font-size:15.5px;font-weight:700}'
		. '.dbwi-pills li::before{content:"\2713";display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:var(--l);color:var(--n);font-size:13px;font-weight:900}'
		. '.dbwi-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:0;padding:0;list-style:none}'
		. '.dbwi-steps li,.dbwi-feats li{display:flex;flex-direction:column;gap:8px;margin:0;padding:22px 20px;border:1px solid var(--ln);border-radius:16px;background:#fff}'
		. '.dbwi-n{display:inline-flex;align-items:center;justify-content:center;align-self:flex-start;min-width:38px;height:30px;padding:0 8px;border-radius:7px;background:var(--l);color:var(--n);font-size:15px;font-weight:800}'
		. '.dbwi-steps strong,.dbwi-feats strong{color:var(--ink);font-size:18px;line-height:1.3}.dbwi-steps li>span:last-child,.dbwi-feats li>span:last-child{color:var(--tx);font-size:15.5px;line-height:1.55}'
		. '.dbwi-feats{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:16px;margin:0;padding:0;list-style:none}'
		. '.dbwi-ico{display:inline-flex;align-items:center;justify-content:center;width:46px;height:46px;border-radius:12px;background:var(--ls);color:var(--n)}'
		. '.dbwi-faq{display:grid;gap:10px}.dbwi-faq details{border:1px solid var(--ln);border-radius:14px;background:#fff}'
		. '.dbwi-faq summary{display:flex;justify-content:space-between;gap:16px;padding:18px 22px;cursor:pointer;color:var(--ink);font-size:17px;font-weight:800;list-style:none}.dbwi-faq summary::-webkit-details-marker{display:none}'
		. '.dbwi-faq summary::after{content:"+";font-size:22px;line-height:1;color:var(--li)}.dbwi-faq details[open] summary::after{content:"\2212"}'
		. '.dbwi-faq details p{margin:0;padding:0 22px 20px;color:var(--tx);font-size:16.5px;line-height:1.65;max-width:none}'
		. '.dbwi-faq summary:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(181,230,29,.55);border-radius:14px}'
		. '.dbwi-cta{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px 24px;padding:20px 26px 20px 32px;border-radius:20px;background:var(--l2)}'
		. '.dbwi-cta h2{margin:0;font-size:clamp(24px,2.6vw,32px)}'
		. 'html body a.dbwi-link{color:var(--ink);font-size:16px;font-weight:700;text-decoration:underline;text-underline-offset:4px}'
		. '@media(max-width:1100px){.dbwi-feats{grid-template-columns:repeat(3,minmax(0,1fr))}}'
		. '@media(max-width:991px){.dbwi-hero{grid-template-columns:minmax(0,1fr)}.dbwi-art{justify-self:center;max-width:340px}.dbwi-steps{grid-template-columns:repeat(2,minmax(0,1fr))}.dbwi-feats{grid-template-columns:repeat(2,minmax(0,1fr))}}'
		. '@media(max-width:560px){.dbwi-steps,.dbwi-feats{grid-template-columns:minmax(0,1fr)}.dbwi-pills{margin-top:-10px}}';
	if ( function_exists( 'db_external_style' ) && db_external_style( 'wishlist-intro', $css ) ) {
		return;
	}
	wp_register_style( 'db-wishlist-intro', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-wishlist-intro' );
	wp_add_inline_style( 'db-wishlist-intro', $css );
}, 1003 );
