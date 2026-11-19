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
		$h       = '<h2 class="db-ac-h">Profile</h2><form class="db-ac-form db-ac-profile" id="db-ac-profile" novalidate>'
			. '<div class="db-ac-grid2"><label>Full name<input type="text" name="name" value="' . esc_attr( (string) ( $row['full_name'] ?? '' ) ) . '" maxlength="120" autocomplete="name" placeholder="Your full name"></label>'
			. '<label>Email<input type="email" value="' . esc_attr( $email ) . '" readonly aria-describedby="db-ac-email-note"><span class="db-ac-note" id="db-ac-email-note">This is how you sign in. To change it, contact us.</span></label>'
			. '<label>Phone <span class="db-ac-opt">(optional)</span><input type="tel" name="phone" value="' . esc_attr( (string) ( $row['phone'] ?? '' ) ) . '" maxlength="25" autocomplete="tel" placeholder="+1 555 123 4567"></label></div>'
			. '<label class="db-ac-check"><input type="checkbox" name="newsletter" value="1"' . ( ! empty( $row['newsletter'] ) ? ' checked' : '' ) . '><span>Send me the Domain Brothers newsletter: new premium domains, price drops and short guides. I can unsubscribe at any time.</span></label>'
			. '<h3>What you are looking for</h3>';
		foreach ( db_reg_questions() as $key => $def ) {
			$h .= '<fieldset class="db-ac-q"><legend>' . esc_html( $def['label'] ) . '</legend>';
			if ( 'text' === $def['type'] ) {
				$h .= '<input type="text" name="' . esc_attr( $key ) . '" value="' . esc_attr( (string) ( $profile[ $key ] ?? '' ) ) . '" maxlength="80" autocomplete="off" placeholder="For example: dental clinic, fintech app, online shop">';
			} else {
				$cur = (array) ( $profile[ $key ] ?? array() );
				$h  .= '<div class="db-ac-pills">';
				foreach ( $def['options'] as $val => $label ) {
					$h .= '<label><input type="' . ( 'multi' === $def['type'] ? 'checkbox' : 'radio' ) . '" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '"' . ( in_array( $val, $cur, true ) ? ' checked' : '' ) . '><span>' . esc_html( $label ) . '</span></label>';
				}
				$h .= '</div>';
			}
			$h .= '</fieldset>';
		}
		$h .= '<button type="submit" class="db-wl-primary">Save profile</button><p class="db-ac-msg" id="db-ac-profilemsg" role="status" aria-live="polite"></p></form>'
			. '<div class="db-wl-card db-ac-danger"><h2>Your data</h2><p>Signed up on ' . esc_html( ! empty( $row['created_at'] ) ? wp_date( 'F j, Y', strtotime( $row['created_at'] . ' UTC' ) ) : 'an earlier date' ) . '. You agreed to policy version ' . esc_html( (string) ( $row['consent_version'] ?? 'on file' ) ) . '. Read our <a href="' . esc_url( home_url( '/privacy-policy/' ) ) . '">Privacy Policy</a>.</p>'
			. '<p><a class="db-ac-btn db-ac-btn--ghost" href="' . esc_url( 'mailto:sales@domainbrothers.com?subject=' . rawurlencode( 'Delete my account' ) . '&body=' . rawurlencode( 'Please delete my account and personal data. My sign-in email is ' . $email . '.' ) ) . '">Ask us to delete my account</a></p></div>';
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
			post('account/profile', { name: f.elements.name.value, phone: f.elements.phone.value, newsletter: f.elements.newsletter.checked, answers: a }).then(function (j) {
				btn.disabled = false; m.textContent = j.message || (j.ok ? 'Saved.' : 'Could not save that.'); m.className = 'db-ac-msg ' + (j.ok ? 'is-good' : 'is-bad');
			}).catch(function () { btn.disabled = false; m.textContent = 'Could not reach the server. Try again.'; m.className = 'db-ac-msg is-bad'; });
		});
	}
}());
</script>
	<?php
}, 93 );
