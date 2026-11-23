<?php
/**
 * The signed-in account area: header, explainer, quick setup and the Profile tab.
 *
 * db_acct_render() (db-account-block.php) calls these helpers when they exist:
 *
 *  - db_acct_head_html()    Title on the left; "Signed in as" and Sign out on the right.
 *  - db_acct_guide_html()   "How your account works": what each section is and how to
 *                           use it, plus a quick-setup checklist worked out from the
 *                           account's real state. Open on the first visit.
 *  - db_acct_intro()        A short "what this is" line for the tab being shown.
 *  - db_acct_profile_html() The Profile tab: details, newsletter and the profile questions.
 *
 * The layout is fluid from a 280px cover screen to a 900px+ unfolded one, and
 * uses the viewport-segments media feature to keep content off the hinge on
 * dual-screen devices.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_acct_ui_icon' ) ) {
	function db_acct_ui_icon( $key ) {
		$p = array(
			'saved'     => '<path d="M12 20s-7-4.4-8.6-8.8C2.300 8 4.200 5 7.300 5c1.900 0 3.200 1 4.700 2.800C13.500 6 14.800 5 16.700 5c3.100 0 5 3 3.900 6.200C19 15.600 12 20 12 20z"/>',
			'alerts'    => '<path d="M6 16V11a6 6 0 1112 0v5l2 2H4z"/><path d="M10 20a2 2 0 004 0"/>',
			'offers'    => '<path d="M4 6h16v11H9l-5 4z"/><path d="M8 10h8M8 13h5"/>',
			'transfers' => '<path d="M4 9h15M15 5l4 4-4 4M20 15H5M9 11l-4 4 4 4"/>',
			'discover'  => '<circle cx="11" cy="11" r="7"/><path d="M20 20l-3.500-3.500"/>',
			'profile'   => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1.500-4 4.500-6 8-6s6.500 2 8 6"/>',
			'check'     => '<path d="M5 12.500l4.500 4.500L19 7.500"/>',
		);
		return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ( $p[ $key ] ?? $p['check'] ) . '</svg>';
	}
}

if ( ! function_exists( 'db_acct_ui_url' ) ) {
	function db_acct_ui_url( $tab ) {
		return 'saved' === $tab ? home_url( '/my-wishlist/' ) : add_query_arg( 'tab', $tab, home_url( '/my-wishlist/' ) );
	}
}

if ( ! function_exists( 'db_acct_head_html' ) ) {
	/**
	 * @param string     $email Signed-in address.
	 * @param array|null $row   Account row.
	 */
	function db_acct_head_html( $email, $row ) {
		$name  = is_array( $row ) ? trim( (string) $row['full_name'] ) : '';
		$first = '' !== $name ? preg_split( '/\s+/', $name )[0] : '';
		return '<header class="db-ac-head"><div class="db-ac-head-t"><h1 class="db-ac-title">' . ( '' !== $first ? 'Hi, ' . esc_html( $first ) : 'My account' ) . '</h1>'
			. '<p class="db-ac-tag">Your wishlist, alerts, offers and transfers in one place.</p></div>'
			. '<div class="db-ac-who"><span class="db-ac-who-l">Signed in as</span> <strong class="db-ac-who-e">' . esc_html( $email ) . '</strong>'
			. '<span class="db-ac-who-a"><a class="db-ac-howto" href="#db-ac-guide">How it works</a><button type="button" class="db-wl-link db-ac-out" id="db-wl-signout">Sign out</button></span></div></header>';
	}
}

if ( ! function_exists( 'db_acct_guide_html' ) ) {
	/**
	 * @param string     $email Signed-in address.
	 * @param array|null $row   Account row.
	 * @param int        $saved Saved domains.
	 * @param array      $deals Offers made.
	 */
	function db_acct_guide_html( $email, $row, $saved, $deals ) {
		$seen    = is_array( $row ) && ! empty( $row['guide_seen'] );
		$profile = is_array( $row ) && ! empty( $row['profile_at'] );
		$emails  = function_exists( 'db_acct_emails_on' ) ? (bool) db_acct_emails_on( $email ) : false;
		$cards   = array(
			array( 'saved', 'Wishlist', 'Your private list of names you want to keep an eye on.', 'Open any domain and choose Add to wishlist.' ),
			array( 'alerts', 'Alerts', 'Updates on the names you follow: price changes, sales, offers and status changes.', 'Switch email alerts on or off in this section.' ),
			array( 'offers', 'My offers', 'Every offer you make, our reply and the full conversation.', 'Name your price on any name that is for sale.' ),
			array( 'transfers', 'Transfers', 'After you buy, each stage from payment to completion.', 'Nothing to set up. It fills in when you buy.' ),
			array( 'discover', 'Discover', 'New and popular names, picked from what is listed now.', 'Save any name to follow it.' ),
			array( 'profile', 'Profile', 'Your details, newsletter choice and what you are looking for.', 'Keep it up to date so suggestions fit.' ),
		);
		$steps = array(
			array( $saved > 0, 'Save your first domain', db_acct_ui_url( 'discover' ), 'Find a name' ),
			array( $emails, 'Turn on alert emails', db_acct_ui_url( 'alerts' ), 'Open Alerts' ),
			array( $profile, 'Tell us what you are looking for', db_acct_ui_url( 'profile' ), 'Open Profile' ),
			array( ! empty( $deals ), 'Make your first offer', db_acct_ui_url( 'offers' ), 'Open My offers' ),
		);
		$done = 0;
		foreach ( $steps as $s ) {
			$done += $s[0] ? 1 : 0;
		}
		$h = '<details class="db-ac-guide" id="db-ac-guide"' . ( $seen ? '' : ' open' ) . '><summary><span class="db-ac-guide-t">How your account works</span><span class="db-ac-guide-p">' . (int) $done . ' of ' . count( $steps ) . ' set up</span></summary><div class="db-ac-guide-b">'
			. '<ul class="db-ac-gcards">';
		foreach ( $cards as $c ) {
			$h .= '<li><a href="' . esc_url( db_acct_ui_url( $c[0] ) ) . '"><span class="db-ac-gico">' . db_acct_ui_icon( $c[0] ) . '</span><strong>' . esc_html( $c[1] ) . '</strong><span>' . esc_html( $c[2] ) . '</span><em>' . esc_html( $c[3] ) . '</em></a></li>';
		}
		$h .= '</ul><div class="db-ac-setup"><h2>Quick setup</h2><div class="db-ac-bar" role="progressbar" aria-valuemin="0" aria-valuemax="' . count( $steps ) . '" aria-valuenow="' . (int) $done . '"><span style="width:' . (int) round( 100 * $done / count( $steps ) ) . '%"></span></div><ol>';
		foreach ( $steps as $s ) {
			$h .= '<li class="' . ( $s[0] ? 'is-done' : '' ) . '"><span class="db-ac-tick" aria-hidden="true">' . ( $s[0] ? db_acct_ui_icon( 'check' ) : '' ) . '</span><span class="db-ac-step-l">' . esc_html( $s[1] ) . ( $s[0] ? ' <span class="db-ac-sr">(done)</span>' : '' ) . '</span>'
				. ( $s[0] ? '' : '<a class="db-ac-btn db-ac-btn--ghost" href="' . esc_url( $s[2] ) . '">' . esc_html( $s[3] ) . '</a>' ) . '</li>';
		}
		$h .= '</ol>' . ( $seen ? '' : '<button type="button" class="db-ac-btn" data-ac-guide-done>Got it</button>' ) . '</div></div></details>';
		return $h;
	}
}

if ( ! function_exists( 'db_acct_intro' ) ) {
	/** One short line saying what the shown section is. */
	function db_acct_intro( $tab ) {
		$t = array(
			'saved'     => 'Your wishlist is a private list of domains you want to keep an eye on. We tell you in Alerts, and by email if you choose, when one changes.',
			'alerts'    => 'Alerts are updates about the names you follow: a price change, a sale, a new offer or a status change. Newest first.',
			'offers'    => 'My offers keeps every offer you make and our reply. We answer each one by email within one business day.',
			'transfers' => 'After you buy, each stage of the transfer is shown here, from payment to completion, with notes from our team.',
			'plans'     => 'Each monthly plan with your next payment, what you have paid, what is left and the full schedule. We email a reminder before every payment.',
			'discover'  => 'Discover shows what was listed recently and what other buyers are looking at. Save any name to follow it.',
			'profile'   => 'Your details and what you are looking for. We use your answers to suggest names, and your newsletter choice controls what we email.',
		);
		return isset( $t[ $tab ] ) ? '<p class="db-ac-intro">' . esc_html( $t[ $tab ] ) . '</p>' : '';
	}
}

if ( ! function_exists( 'db_acct_profile_html' ) ) {
	/**
	 * @param string     $email Signed-in address.
	 * @param array|null $row   Account row.
	 */
	function db_acct_profile_html( $email, $row ) {
		$row     = is_array( $row ) ? $row : array();
		$profile = json_decode( (string) ( $row['profile'] ?? '' ), true );
		$profile = is_array( $profile ) ? $profile : array();
		$name    = (string) ( $row['full_name'] ?? '' );
		$since   = ! empty( $row['created_at'] ) ? wp_date( 'F j, Y', strtotime( $row['created_at'] . ' UTC' ) ) : '';
		$consent = trim( (string) ( $row['consent_version'] ?? '' ) );
		$initial = strtoupper( mb_substr( '' !== $name ? $name : $email, 0, 1 ) );
		$h  = '<div class="db-pf"><div class="db-pf-main">';
		$h .= '<form class="db-pf-form" id="db-ac-profile" novalidate>';
		// Details.
		$h .= '<section class="db-pf-card" aria-labelledby="db-pf-det"><div class="db-pf-cardhead"><span class="db-pf-avatar" aria-hidden="true">' . esc_html( $initial ) . '</span><div><h2 id="db-pf-det">Your details</h2><p>Used on offers, receipts and payment plans.</p></div></div>'
			. '<div class="db-pf-grid"><div class="db-pf-field"><label for="db-pf-name">Full name</label><input type="text" id="db-pf-name" name="name" value="' . esc_attr( $name ) . '" maxlength="120" autocomplete="name" placeholder="Your full name"></div>'
			. '<div class="db-pf-field"><label for="db-pf-phone">Phone <span class="db-pf-opt">optional</span></label><input type="tel" id="db-pf-phone" name="phone" value="' . esc_attr( (string) ( $row['phone'] ?? '' ) ) . '" maxlength="25" autocomplete="tel" placeholder="+1 555 123 4567"></div>'
			. '<div class="db-pf-field db-pf-wide"><label for="db-pf-email">Email</label><div class="db-pf-lock"><input type="email" id="db-pf-email" value="' . esc_attr( $email ) . '" readonly aria-describedby="db-pf-email-n"><span class="db-pf-chip">Sign-in email</span></div><p class="db-pf-note" id="db-pf-email-n">This is how you sign in. To change it, <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">contact us</a>.</p></div></div></section>';
		// Newsletter.
		$h .= '<section class="db-pf-card db-pf-row" aria-labelledby="db-pf-nl"><div><h2 id="db-pf-nl">Newsletter</h2><p>New premium domains, price drops and short guides. Unsubscribe at any time.</p></div>'
			. '<label class="db-pf-switch"><input type="checkbox" name="newsletter" value="1"' . ( ! empty( $row['newsletter'] ) ? ' checked' : '' ) . '><span class="db-pf-track" aria-hidden="true"></span><span class="db-pf-sr">Send me the Domain Brothers newsletter</span></label></section>';
		// Preferences.
		$h .= '<section class="db-pf-card" aria-labelledby="db-pf-look"><h2 id="db-pf-look">What you are looking for</h2><p>Your answers shape the names we suggest. Choose as many as fit.</p><div class="db-pf-qs">';
		foreach ( db_reg_questions() as $key => $def ) {
			$h .= '<fieldset class="db-pf-q"><legend>' . esc_html( $def['label'] ) . '</legend>';
			if ( 'text' === $def['type'] ) {
				$h .= '<input type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $profile[ $key ] ?? '' ) ) . '" maxlength="80" autocomplete="off" placeholder="For example: dental clinic, fintech app, online shop" aria-label="' . esc_attr( $def['label'] ) . '">';
			} else {
				$cur = (array) ( $profile[ $key ] ?? array() );
				$h  .= '<div class="db-pf-pills">';
				foreach ( $def['options'] as $val => $label ) {
					$h .= '<label><input type="' . ( 'multi' === $def['type'] ? 'checkbox' : 'radio' ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '"' . ( in_array( $val, $cur, true ) ? ' checked' : '' ) . '><span>' . esc_html( $label ) . '</span></label>';
				}
				$h .= '</div>';
			}
			$h .= '</fieldset>';
		}
		$h .= '</div></section>';
		if ( '' === $consent ) {
			// Accounts made by a code sign-in never accepted the terms (AC-7): ask here.
			$h .= '<p class="db-pf-accept"><label><input type="checkbox" name="accept_terms" value="1"> I accept the <a href="' . esc_url( home_url( '/terms-of-use/' ) ) . '">Terms of Use</a> and <a href="' . esc_url( home_url( '/privacy-policy/' ) ) . '">Privacy Policy</a>.</label></p>';
		}
		$h .= '<div class="db-pf-save"><button type="submit" class="db-pf-btn">Save profile</button><p class="db-ac-msg" id="db-ac-profilemsg" role="status" aria-live="polite"></p></div></form></div>';
		// Side column: data and danger zone.
		$h .= '<aside class="db-pf-side" aria-label="Your data"><section class="db-pf-card"><h2>Your data</h2><dl class="db-pf-dl">'
			. ( '' !== $since ? '<div><dt>Member since</dt><dd>' . esc_html( $since ) . '</dd></div>' : '' )
			. '<div><dt>Policies</dt><dd>' . ( '' !== $consent ? 'Accepted version ' . esc_html( $consent ) : 'Not yet accepted' ) . '</dd></div></dl>'
			. '<p class="db-pf-note">See our <a href="' . esc_url( home_url( '/privacy-policy/' ) ) . '">Privacy Policy</a>.</p>'
			. '<button type="button" class="db-pf-btn db-pf-btn--ghost" id="db-ad-export">Download my data</button></section>'
			. '<section class="db-pf-card db-pf-danger" aria-labelledby="db-pf-del"><h2 id="db-pf-del">Delete account</h2><p>Removes your account, saved domains and sign-in sessions. Purchase and payment-plan records are kept for tax and dispute reasons. Email <a href="mailto:sales@domainbrothers.com">sales@domainbrothers.com</a> to have those reviewed.</p>'
			. '<form id="db-ad-delete" novalidate><label for="db-pf-confirm">Type your sign-in email to confirm</label><input type="email" id="db-pf-confirm" name="confirm" autocomplete="off" placeholder="' . esc_attr( $email ) . '" required>'
			. '<button type="submit" class="db-pf-btn db-pf-btn--danger">Delete my account</button></form><p class="db-ac-msg" id="db-ad-msg" role="status" aria-live="polite"></p></section></aside></div>';
		return $h;
	}
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! function_exists( 'db_wl_is_page_request' ) || ! db_wl_is_page_request() || '' === db_wl_email() ) {
		return;
	}
	// Designer's tokens: navy #0f1b2d, lime #b5e61d, soft lime #eef8d2, line #e4e7ec, panel #f1f3f6.
	$css = 'html body.db-wishlist-page #page #content.site-content{padding-left:0!important;padding-right:0!important}'
		. 'html body.db-wishlist-page:has(.db-ac-head) #page #content.site-content>#primary.content-area{box-sizing:border-box!important;float:none!important;width:100%!important;max-width:1360px!important;margin:0 auto!important;padding-left:clamp(14px,3vw,40px)!important;padding-right:clamp(14px,3vw,40px)!important}'
		. 'html body.db-wishlist-page:has(.db-ac-head) #main.site-main{max-width:100%!important;margin:0!important;padding-left:0!important;padding-right:0!important}'
		. '.db-ac-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:10px 24px;margin:8px 0 18px}'
		. 'html body .db-ac-title{margin:0;color:#0f172a;font-size:clamp(28px,4vw,44px);font-weight:900;line-height:1.08;letter-spacing:-.025em;text-align:left}'
		. '.db-ac-tag{margin:6px 0 0;color:#4b5563;font-size:16px}'
		. '.db-ac-who{display:flex;flex-wrap:wrap;align-items:center;gap:4px 10px;max-width:100%;padding:10px 14px;border:1px solid #e4e7ec;border-radius:16px;background:#f1f3f6;color:#374151;font-size:14.5px}'
		. '.db-ac-who-e{min-width:0;overflow-wrap:anywhere;color:#0f172a}.db-ac-who-a{display:inline-flex;align-items:center;gap:12px;margin-left:auto}'
		. 'html body .db-ac-howto,html body .db-ac-out{padding:0;border:0;background:none;color:#3f6212;font-size:14.5px;font-weight:700;text-decoration:underline;text-underline-offset:3px;cursor:pointer}'
		. '.db-ac-guide{margin:0 0 18px;border:1px solid #e4e7ec;border-radius:20px;background:#fff;overflow:hidden}'
		. '.db-ac-guide summary{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 16px;padding:16px 20px;cursor:pointer;list-style:none}.db-ac-guide summary::-webkit-details-marker{display:none}'
		. '.db-ac-guide-t{color:#0f172a;font-size:18px;font-weight:900}.db-ac-guide-p{padding:5px 12px;border-radius:999px;background:#eef8d2;color:#3f6212;font-size:13px;font-weight:800}'
		. '.db-ac-guide summary:focus-visible{outline:none;box-shadow:inset 0 0 0 3px rgba(181,230,29,.7)}.db-ac-guide:target{box-shadow:0 0 0 3px rgba(181,230,29,.7)}'
		. '.db-ac-guide-b{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:20px;padding:4px 20px 20px}'
		. '.db-ac-gcards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin:0;padding:0;list-style:none}.db-ac-gcards li{margin:0}'
		. 'html body .db-ac-gcards a{display:flex;flex-direction:column;gap:4px;height:100%;padding:14px;border:1px solid #e4e7ec;border-radius:14px;background:#fff;color:#374151!important;text-decoration:none!important}'
		. 'html body .db-ac-gcards a:hover{border-color:#b5e61d;box-shadow:0 0 0 1px #b5e61d}'
		. '.db-ac-gico{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:10px;background:#eef8d2;color:#0f1b2d}'
		. '.db-ac-gcards strong{color:#0f172a;font-size:16px}.db-ac-gcards span:not(.db-ac-gico){font-size:14px;line-height:1.45}.db-ac-gcards em{font-size:13.5px;font-style:normal;font-weight:700;color:#3f6212}'
		. '.db-ac-setup{padding:16px;border-radius:14px;background:#f1f3f6}.db-ac-setup h2{margin:0 0 10px;font-size:17px;font-weight:900;color:#0f172a}'
		. '.db-ac-bar{height:8px;margin:0 0 14px;border-radius:999px;background:#e4e7ec;overflow:hidden}.db-ac-bar span{display:block;height:100%;border-radius:999px;background:#b5e61d}'
		. '.db-ac-setup ol{display:grid;gap:10px;margin:0 0 14px;padding:0;list-style:none}.db-ac-setup li{display:flex;flex-wrap:wrap;align-items:center;gap:8px 10px;margin:0;color:#0f172a;font-size:15px;font-weight:600}'
		. '.db-ac-tick{flex:none;display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border:2px solid #cbd2dc;border-radius:50%;background:#fff;color:#0f1b2d}.db-ac-setup li.is-done .db-ac-tick{border-color:#b5e61d;background:#b5e61d}.db-ac-tick svg{width:14px;height:14px}'
		. '.db-ac-setup li.is-done .db-ac-step-l{color:#6b7280;text-decoration:line-through}.db-ac-sr{position:absolute;left:-9999px}.db-ac-step-l{flex:1 1 140px;min-width:0}'
		. '.db-ac-intro{margin:0 0 18px;padding:12px 16px;border-left:4px solid #b5e61d;border-radius:0 12px 12px 0;background:#f6fbe6;color:#374151;font-size:15.5px;line-height:1.55}'
		// Tabs: one scrollable row on narrow screens.
		. 'html body .db-ac-tabs{display:flex;flex-wrap:nowrap;gap:8px;margin:0 0 18px;padding:2px 2px 8px;overflow-x:auto;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch}'
		. 'html body .db-ac-tabs .db-ac-tab{flex:none;scroll-snap-align:start;white-space:nowrap}'
		// Profile form.
		. '.db-ac-grid2{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin:0 0 14px}'
		. '.db-ac-note{display:block;margin-top:4px;color:#6b7280;font-size:13px;font-weight:500}.db-ac-check{display:flex;align-items:flex-start;gap:12px;margin:0 0 18px;color:#374151;font-size:15px;line-height:1.5}.db-ac-check input{flex:none;width:20px;height:20px;margin:2px 0 0;accent-color:#0f1b2d}'
		. '.db-ac-q{margin:0 0 16px;padding:0;border:0;min-width:0}.db-ac-q legend{margin:0 0 6px;color:#0f172a;font-size:15px;font-weight:700}.db-ac-pills{display:flex;flex-wrap:wrap;gap:8px}.db-ac-pills label{margin:0;cursor:pointer}'
		. '.db-ac-pills input{position:absolute;opacity:0;pointer-events:none}.db-ac-pills span{display:inline-flex;align-items:center;min-height:38px;padding:7px 14px;border:1.5px solid #d3d9e1;border-radius:999px;background:#fff;color:#0f172a;font-size:14.5px;font-weight:600}'
		. '.db-ac-pills input:checked+span{border-color:#b5e61d;background:#b5e61d;color:#0f1b2d;font-weight:800}.db-ac-pills input:focus-visible+span{box-shadow:0 0 0 3px rgba(181,230,29,.7)}'
		. '.db-ac-danger{margin-top:22px}'
		// Profile tab (2026-11 redesign).
		. '.db-pf{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start;margin-top:4px}@media(max-width:1000px){.db-pf{grid-template-columns:minmax(0,1fr)}}'
		. '.db-pf-main,.db-pf-side,.db-pf-form{display:grid;gap:16px;min-width:0}.db-pf-side{position:sticky;top:96px}@media(max-width:1000px){.db-pf-side{position:static}}'
		. 'html body .db-pf-form{max-width:none!important;width:auto!important;margin:0!important;padding:0!important;border:0!important;box-shadow:none!important;background:none!important}'
		. '.db-pf-card{padding:24px;border:1px solid #e4e7ec;border-radius:22px;background:#fff;box-shadow:0 6px 20px rgba(15,23,42,.04)}'
		. 'html body .db-pf-card h2{margin:0 0 4px;font-size:20px;font-weight:900;letter-spacing:-.01em;color:#0f172a}.db-pf-card>p,.db-pf-cardhead p{margin:0 0 16px;color:#4b5563;font-size:15px;line-height:1.5}'
		. '.db-pf-cardhead{display:flex;gap:14px;align-items:center;margin-bottom:4px}.db-pf-cardhead p{margin:2px 0 0}.db-pf-avatar{flex:none;display:inline-flex;align-items:center;justify-content:center;width:52px;height:52px;border-radius:50%;background:#0f1b2d;color:#b5e61d;font-size:22px;font-weight:900}'
		. '.db-pf-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px;margin-top:18px}.db-pf-wide{grid-column:1/-1}@media(max-width:640px){.db-pf-grid{grid-template-columns:minmax(0,1fr)}}'
		. 'html body .db-pf-field label,html body .db-pf-danger label{display:block;margin:0 0 6px;font-size:14.5px;font-weight:700;color:#0f172a}.db-pf-opt{font-weight:500;color:#6b7280;font-size:13px;margin-left:4px}'
		. 'html body .db-pf input[type=text],html body .db-pf input[type=tel],html body .db-pf input[type=email]{display:block;width:100%!important;max-width:none!important;min-height:48px;height:48px;margin:0!important;padding:0 14px!important;border:1.5px solid #d3d9e1!important;border-radius:14px!important;background:#fff;font-size:16px;color:#0f172a;box-sizing:border-box}'
		. 'html body .db-pf input:focus{outline:none;border-color:#0f1b2d!important;box-shadow:0 0 0 4px rgba(181,230,29,.35)!important}'
		. '.db-pf-lock{position:relative}html body .db-pf-lock input{background:#f6f7f9!important;color:#4b5563!important;padding-right:130px!important}.db-pf-chip{position:absolute;right:10px;top:50%;transform:translateY(-50%);padding:4px 10px;border-radius:999px;background:#e4e7ec;color:#374151;font-size:12px;font-weight:700}'
		. '.db-pf-note{margin:6px 0 0;color:#6b7280;font-size:13.5px;line-height:1.5}.db-pf-note a{color:#3f6212;font-weight:700}'
		. '.db-pf-row{display:flex;gap:16px;align-items:center;justify-content:space-between}.db-pf-row p{margin:0!important}'
		. '.db-pf-switch{position:relative;flex:none;display:inline-flex;cursor:pointer}.db-pf-switch input{position:absolute;opacity:0;width:1px;height:1px}.db-pf-track{width:56px;height:32px;border-radius:999px;background:#d3d9e1;position:relative;transition:background .2s}'
		. '.db-pf-track::after{content:"";position:absolute;left:4px;top:4px;width:24px;height:24px;border-radius:50%;background:#fff;box-shadow:0 2px 6px rgba(0,0,0,.2);transition:transform .2s}.db-pf-switch input:checked+.db-pf-track{background:#0f1b2d}.db-pf-switch input:checked+.db-pf-track::after{transform:translateX(24px);background:#b5e61d}'
		. '.db-pf-switch input:focus-visible+.db-pf-track{box-shadow:0 0 0 4px rgba(181,230,29,.5)}.db-pf-sr{position:absolute;left:-9999px}'
		. '.db-pf-qs{display:grid;gap:4px}.db-pf-q{margin:0;padding:16px 0;border:0;border-top:1px solid #eef1f4;min-width:0}.db-pf-q:first-child{border-top:0;padding-top:4px}'
		. 'html body .db-pf-q legend{float:left;width:100%;margin:0 0 10px;padding:0;font-size:15.5px;font-weight:800;color:#0f172a}.db-pf-q legend+*{clear:both}'
		. '.db-pf-pills{display:flex;flex-wrap:wrap;gap:8px}.db-pf-pills label{margin:0;cursor:pointer}.db-pf-pills input{position:absolute;opacity:0;pointer-events:none}'
		. '.db-pf-pills span{display:inline-flex;align-items:center;gap:6px;min-height:40px;padding:0 16px;border:1.5px solid #d3d9e1;border-radius:999px;background:#fff;color:#0f172a;font-size:14.5px;font-weight:600;transition:border-color .15s,background .15s}'
		. '.db-pf-pills label:hover span{border-color:#0f1b2d}.db-pf-pills input:checked+span{border-color:#0f1b2d;background:#0f1b2d;color:#fff}.db-pf-pills input:checked+span::before{content:"";width:8px;height:8px;border-radius:50%;background:#b5e61d}.db-pf-pills input:focus-visible+span{box-shadow:0 0 0 4px rgba(181,230,29,.5)}'
		. '.db-pf-save{position:sticky;bottom:12px;z-index:5;display:flex;flex-wrap:wrap;gap:12px;align-items:center;padding:14px 18px;border:1px solid #e4e7ec;border-radius:18px;background:rgba(255,255,255,.95);box-shadow:0 10px 30px rgba(15,23,42,.10);backdrop-filter:blur(6px)}.db-pf-save .db-ac-msg{margin:0}'
		. 'html body .db-pf-btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 26px;border:0;border-radius:999px;background:#0f1b2d;color:#fff;font-size:16px;font-weight:800;cursor:pointer}html body .db-pf-btn:hover{background:#1e2f47}'
		. 'html body .db-pf-btn--ghost{width:100%;margin-top:14px;background:#fff;color:#0f172a;border:1.5px solid #d3d9e1}html body .db-pf-btn--ghost:hover{background:#f6f7f9}'
		. '.db-pf-dl{display:grid;gap:10px;margin:12px 0 0}.db-pf-dl div{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #eef1f4}.db-pf-dl dt{color:#6b7280;font-size:14px}.db-pf-dl dd{margin:0;color:#0f172a;font-weight:700;font-size:14px;text-align:right}'
		. '.db-pf-danger{border-color:#fecaca;background:#fffafa}html body .db-pf-danger h2{color:#991b1b}.db-pf-danger p{font-size:14px!important}.db-pf-danger p a{color:#991b1b;font-weight:700}'
		. 'html body .db-pf .db-pf-danger button.db-pf-btn--danger{width:100%;margin-top:12px;background:#fff!important;color:#b42318!important;border:1.5px solid #f3a5a5!important}html body .db-pf .db-pf-danger button.db-pf-btn--danger:hover,html body .db-pf .db-pf-danger button.db-pf-btn--danger:focus-visible{background:#b42318!important;color:#fff!important;border-color:#b42318!important}'
		. '@media(prefers-reduced-motion:reduce){.db-pf-track,.db-pf-track::after,.db-pf-pills span{transition:none}}'
		// Wide, medium, small, cover screens.
		. '@media(max-width:1100px){.db-ac-guide-b{grid-template-columns:minmax(0,1fr)}}'
		. '@media(max-width:900px){.db-ac-grid2{grid-template-columns:repeat(2,minmax(0,1fr))}.db-ac-who-a{margin-left:0;width:100%;justify-content:space-between}}'
		. '@media(max-width:600px){.db-ac-grid2{grid-template-columns:minmax(0,1fr)}.db-ac-gcards{grid-template-columns:minmax(0,1fr)}.db-ac-who{width:100%;box-sizing:border-box}.db-ac-guide summary{padding:14px 16px}.db-ac-guide-b{padding:2px 14px 16px}}'
		. '@media(max-width:360px){html body .db-ac-title{font-size:26px}.db-ac-tag{font-size:14.5px}.db-ac-intro{font-size:14.5px}.db-ac-pills span{font-size:13.5px;padding:6px 12px}}'
		// Dual-screen foldables: keep content off the hinge.
		. '@media(horizontal-viewport-segments:2){html body.db-wishlist-page:has(.db-ac-head) #page #content.site-content>#primary.content-area{max-width:env(viewport-segment-width 0 0)!important;margin-left:0!important}.db-ac-guide-b{grid-template-columns:minmax(0,1fr)}}'
		. '@media(prefers-reduced-motion:reduce){.db-ac-bar span{transition:none}}';
	if ( function_exists( 'db_external_style' ) && db_external_style( 'account-ui', $css ) ) {
		return;
	}
	wp_register_style( 'db-account-ui', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-account-ui' );
	wp_add_inline_style( 'db-account-ui', $css );
}, 1005 );

add_action( 'wp_footer', function () {
	if ( ! function_exists( 'db_wl_is_page_request' ) || ! db_wl_is_page_request() || '' === db_wl_email() ) {
		return;
	}
	$api = esc_url_raw( rest_url( 'db/v1/' ) );
	?>
<script data-no-delay>
(function () {
	var API = <?php echo wp_json_encode( $api ); ?>;
	function post(path, body) {
		return fetch(API + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(body || {}) })
			.then(function (r) { return r.json(); });
	}
	var done = document.querySelector('[data-ac-guide-done]');
	if (done) {
		done.addEventListener('click', function () {
			post('account/guide', {}); var g = document.getElementById('db-ac-guide'); if (g) { g.open = false; } done.remove();
		});
	}
	var f = document.getElementById('db-ac-profile'), m = document.getElementById('db-ac-profilemsg');
	if (f) {
		f.addEventListener('submit', function (e) {
			e.preventDefault();
			var a = {};
			['intent', 'budget', 'timeline', 'source'].forEach(function (k) { var c = f.querySelector('input[name=' + k + ']:checked'); if (c) { a[k] = c.value; } });
			a.interests = [].map.call(f.querySelectorAll('input[name=interests]:checked'), function (i) { return i.value; });
			a.industry = f.elements.industry ? f.elements.industry.value : '';
			var btn = f.querySelector('button[type=submit]'); btn.disabled = true; m.textContent = 'Saving…'; m.className = 'db-ac-msg';
			post('account/profile', { name: f.elements.name.value, phone: f.elements.phone.value, newsletter: f.elements.newsletter.checked, answers: a, accept_terms: !!(f.elements.accept_terms && f.elements.accept_terms.checked) }).then(function (j) {
				btn.disabled = false; m.textContent = j.message || (j.ok ? 'Saved.' : 'Could not save that.'); m.className = 'db-ac-msg ' + (j.ok ? 'is-good' : 'is-bad');
			}).catch(function () { btn.disabled = false; m.textContent = 'Could not reach the server. Try again.'; m.className = 'db-ac-msg is-bad'; });
		});
	}
}());
</script>
	<?php
}, 93 );

/*
 * Navigation labels, fixed in the page output.
 *
 *  - Signed in: the header shows "My account" and Sign out straight away. The
 *    script that does this waits for the first interaction on this site, so a
 *    signed-in visitor saw "Sign in" and "Create account" until they moved.
 *    Signed-in pages are never served from the page cache, so this is safe.
 *  - The theme's footer links "Payment Plans" to the FAQs. It now goes to the
 *    Payment terms page under that name (the theme file is not edited).
 */
add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	$signed_in = function_exists( 'db_wl_email' ) && '' !== db_wl_email();
	ob_start( function ( $html ) use ( $signed_in ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( false !== strpos( $html, '>Payment Plans</a>' ) ) {
			$out  = preg_replace( '#<a href="[^"]*/faqs/?"([^>]*)>Payment Plans</a>#', '<a href="' . esc_url( home_url( '/payment-terms/' ) ) . '"$1>Payment Terms</a>', $html );
			$html = is_string( $out ) ? $out : $html;
		}
		if ( $signed_in && false !== strpos( $html, 'data-db-acct=' ) ) {
			$html = preg_replace( '#<a([^>]*?)href="[^"]*/sign-in/?"([^>]*?)data-db-acct="signin">Sign in</a>#', '<a$1href="' . esc_url( home_url( '/my-wishlist/' ) ) . '"$2data-db-acct="signin">My account</a>', $html ) ?: $html;
			$html = preg_replace( '#(<a[^>]*?data-db-acct="register")([^>]*>)#', '$1 hidden$2', $html ) ?: $html;
			$html = preg_replace( '#(data-db-acct="signout")\s+hidden#', '$1', $html ) ?: $html;
			$html = preg_replace( '#<li([^>]*?)\s+hidden(>\s*<a[^>]*data-db-acct="(?:signout)")#', '<li$1$2', $html ) ?: $html;
			$html = preg_replace( '#<li([^>]*?)(>\s*<a[^>]*data-db-acct="register")#', '<li$1 hidden$2', $html ) ?: $html;
		}
		return $html;
	} );
}, 3 );
