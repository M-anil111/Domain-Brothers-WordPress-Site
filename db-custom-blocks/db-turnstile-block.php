<?php
/**
 * Cloudflare Turnstile on public forms (bot protection without a puzzle).
 *
 * Off until both keys are saved under Domain Brothers > Launch checks. With keys:
 *   - every Contact Form 7 form gets a Turnstile widget, and a submission without a
 *     valid token is treated as spam;
 *   - the sign-in code request (/wishlist/code), the registration code request
 *     (/account/register), the account offer (/account/offer), the broker request on
 *     the finder and the "Ask the owner" enquiry require a valid token. Offer and
 *     deal-buyer pages are guarded by a private token, a honeypot and per-IP limits;
 *     save-search and transfer messages need a signed-in account;
 *   - the secret key is stored encrypted (db_smtp_encrypt) and never shown again.
 *
 * Fail closed: if Cloudflare cannot be reached to verify a token, the form is refused and
 * the visitor is asked to try again, so a bot never gets through while the check is down.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_ts_settings' ) ) {
	function db_ts_settings() {
		$o = get_option( 'db_turnstile', array() );
		return is_array( $o ) ? $o : array();
	}
}

if ( ! function_exists( 'db_ts_test_mode' ) ) {
	/**
	 * Staging only (bug O-3): while option db_turnstile_test_until holds a future time, Turnstile
	 * uses Cloudflare's published always-pass test keys so scripted QA can sign in and submit
	 * offers. It switches itself off after the time given (at most two hours from when it was set),
	 * never applies on production, and applies only to requests that send the cookie db_ts_qa with
	 * the value of the option db_turnstile_test_key (32+ characters).
	 */
	function db_ts_test_mode() {
		$until = (int) get_option( 'db_turnstile_test_until', 0 );
		if ( ! ( $until > time() && $until <= time() + 2 * HOUR_IN_SECONDS && function_exists( 'db_is_staging_host' ) && db_is_staging_host() ) ) {
			return false;
		}
		// Only requests that carry the QA cookie get the test keys. Real visitors keep the real
		// widget even while a test run is on (they used to see "For testing only").
		$key  = (string) get_option( 'db_turnstile_test_key', '' );
		$sent = isset( $_COOKIE['db_ts_qa'] ) ? (string) wp_unslash( $_COOKIE['db_ts_qa'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return strlen( $key ) >= 32 && hash_equals( $key, $sent );
	}
}

if ( ! function_exists( 'db_ts_site_key' ) ) {
	function db_ts_site_key() {
		if ( db_ts_test_mode() ) {
			return '1x00000000000000000000AA'; // Cloudflare test site key: always passes
		}
		return preg_match( '/^[0-9A-Za-z_\-]{10,80}$/', (string) ( db_ts_settings()['site'] ?? '' ) ) ? (string) db_ts_settings()['site'] : '';
	}
}

if ( ! function_exists( 'db_ts_secret' ) ) {
	function db_ts_secret() {
		if ( db_ts_test_mode() ) {
			return '1x0000000000000000000000000000000AA'; // Cloudflare test secret: always passes (public, not a credential)
		}
		return (string) db_smtp_decrypt( db_ts_settings()['secret'] ?? '' );
	}
}

if ( ! function_exists( 'db_ts_enabled' ) ) {
	function db_ts_enabled() {
		return '' !== db_ts_site_key() && '' !== db_ts_secret();
	}
}

if ( ! function_exists( 'db_ts_verify' ) ) {
	/** True when Turnstile is off, or the token is valid. Fails closed when Cloudflare cannot be reached. */
	function db_ts_verify( $token, $ip = '' ) {
		if ( ! db_ts_enabled() ) {
			return true;
		}
		$token = trim( (string) $token );
		if ( '' === $token || strlen( $token ) > 2048 ) {
			return false;
		}
		$res = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
			'timeout' => 10,
			'body'    => array( 'secret' => db_ts_secret(), 'response' => $token, 'remoteip' => (string) $ip ),
		) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return false;
		}
		$j = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $j ) && ! empty( $j['success'] );
	}
}

// Client: the script loads only when enabled. Widgets are rendered explicitly so that forms
// added later (the sign-in dialog) can be rendered too.
add_action( 'wp_footer', function () {
	if ( is_admin() || ! db_ts_enabled() ) {
		return;
	}
	echo '<script>window.DB_TS_SITE=' . wp_json_encode( db_ts_site_key() ) . ';window.dbTsNeed=function(){if(window.dbTsAsked){return;}window.dbTsAsked=true;var s=document.createElement("script");s.src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=dbTsLoad&render=explicit";s.async=true;document.head.appendChild(s);};window.dbTsRender=function(root){if(!window.DB_TS_SITE){return;}if(!window.turnstile){if((root||document).querySelector(".db-ts")){window.dbTsNeed();}return;}(root||document).querySelectorAll(".db-ts:not([data-ts-id])").forEach(function(el){el.setAttribute("data-ts-id",String(window.turnstile.render(el,{sitekey:window.DB_TS_SITE})));});};window.dbTsReset=function(root){if(!window.turnstile){return;}var r=root||document;var els=r.matches&&r.matches(".db-ts[data-ts-id]")?[r]:r.querySelectorAll(".db-ts[data-ts-id]");[].forEach.call(els,function(el){try{window.turnstile.reset(el.getAttribute("data-ts-id"));}catch(e){}});};window.dbTsToken=function(root){var i=root&&root.querySelector("input[name=\'cf-turnstile-response\']");var v=i?i.value:"";if(v&&root){setTimeout(function(){window.dbTsReset(root);},1500);}return v;};document.addEventListener("wpcf7submit",function(e){window.dbTsReset(e.target);});window.dbTsLoad=function(){window.dbTsRender(document);};'
			// Speed: the Turnstile script (about 100 KB and a long task on phones) used to load on every
			// page. It now loads only when a form with a check is near the viewport or gets focus.
			. '(function(){function watch(){var els=document.querySelectorAll(".db-ts");if(!els.length){return;}if(!("IntersectionObserver" in window)){window.dbTsNeed();return;}var io=new IntersectionObserver(function(en){en.forEach(function(e){if(e.isIntersecting){io.disconnect();window.dbTsNeed();}});},{rootMargin:"600px 0px"});[].forEach.call(els,function(el){io.observe(el);});document.addEventListener("focusin",function(e){var f=e.target&&e.target.closest&&e.target.closest("form");if(f&&f.querySelector(".db-ts")){window.dbTsNeed();}});}if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",watch);}else{watch();}})();</script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources, WordPress.Security.EscapeOutput
}, 85 );

// Contact Form 7: a widget at the end of every form, and a missing or failed check counts as spam.
add_filter( 'wpcf7_form_elements', function ( $html ) {
	if ( ! db_ts_enabled() ) {
		return $html;
	}
	// Before the submit button, so visitors see the check before they press Send (batch 2, AC-5).
	$widget = '<div class="db-ts" style="margin:12px 0"></div>';
	$out    = preg_replace( '/(<p>\s*)?<input\b[^>]*type="submit"/i', $widget . '$0', $html, 1, $n );
	return ( $n && is_string( $out ) ) ? $out : $html . $widget;
} );

add_filter( 'wpcf7_spam', function ( $spam ) {
	if ( $spam || ! db_ts_enabled() ) {
		return $spam;
	}
	$token = isset( $_POST['cf-turnstile-response'] ) ? wp_unslash( $_POST['cf-turnstile-response'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
	$ok    = db_ts_verify( $token, function_exists( 'db_wl_ip' ) ? db_wl_ip() : '' );
	if ( ! $ok ) {
		// Recorded in Contact Form 7's spam log, shown under Domain Brothers > Form log.
		$sub = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
		if ( $sub && method_exists( $sub, 'add_spam_log' ) ) {
			$sub->add_spam_log( array(
				'agent'  => 'db_turnstile',
				'reason' => '' === trim( (string) $token ) ? 'no Turnstile token (the widget did not load or was not completed; check the site key allows this hostname)' : 'Cloudflare rejected the Turnstile token',
			) );
		}
	}
	return ! $ok;
} );

// A failed check is not a server error: tell the visitor what to do (batch 2, AC-5).
add_filter( 'wpcf7_display_message', function ( $message, $status ) {
	if ( 'spam' === $status && db_ts_enabled() ) {
		return 'Please complete the security check above the Send button, then send again.';
	}
	return $message;
}, 10, 2 );

/** Admin: the two keys, and turning the check off again. */
if ( ! function_exists( 'db_ts_admin_html' ) ) {
	function db_ts_admin_html() {
		$on = db_ts_enabled();
		$h  = '<h2 id="turnstile">Bot protection (Cloudflare Turnstile)</h2>';
		$h .= '<p>' . ( $on ? '<span style="color:#1a7f37;font-weight:600">&#10003; On.</span> Contact forms, sign-in codes and offers are checked.' : '<span style="color:#b32d2e;font-weight:600">&#9888; Off.</span> Forms are not protected yet. Create a Turnstile widget in the Cloudflare dashboard (Turnstile &gt; Add widget), then paste its keys here.' ) . '</p>';
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		$h .= '<input type="hidden" name="action" value="db_turnstile_save">' . wp_nonce_field( 'db_turnstile_save', '_wpnonce', true, false );
		$h .= '<table class="form-table" role="presentation"><tr><th><label for="db_ts_site">Site key</label></th><td><input type="text" id="db_ts_site" name="site" class="regular-text" value="' . esc_attr( db_ts_site_key() ) . '" autocomplete="off"></td></tr>';
		$h .= '<tr><th><label for="db_ts_secret">Secret key</label></th><td><input type="password" id="db_ts_secret" name="secret" class="regular-text" autocomplete="new-password" placeholder="' . esc_attr( $on ? 'Saved (encrypted): leave blank to keep' : '' ) . '"><p class="description">Stored encrypted; never shown again.</p></td></tr></table>';
		$h .= '<p><button class="button button-primary" name="do" value="save">Save keys</button> <button class="button" name="do" value="off" onclick="return confirm(\'Turn bot protection off?\')">Turn off</button></p></form>';
		return $h;
	}
}

add_action( 'admin_post_db_turnstile_save', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_turnstile_save' ) ) {
		wp_die( 'Not allowed.' );
	}
	$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : 'save';
	if ( 'off' === $do ) {
		update_option( 'db_turnstile', array(), false );
	} else {
		$old    = db_ts_settings();
		$site   = isset( $_POST['site'] ) ? sanitize_text_field( wp_unslash( $_POST['site'] ) ) : '';
		$secret = isset( $_POST['secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret'] ) ) ) : '';
		$keep   = '' === $secret ? (string) ( $old['secret'] ?? '' ) : db_smtp_encrypt( $secret );
		update_option( 'db_turnstile', array( 'site' => $site, 'secret' => '' === $keep ? '' : $keep ), false );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=db-launch&saved=turnstile#turnstile' ) );
	exit;
} );

/*
 * A request carrying the QA cookie may be drawn with Cloudflare's test keys, so it must never be
 * stored by our page cache or LiteSpeed and later served to visitors. pagecache/serve.php also
 * skips requests with this cookie.
 */
add_action( 'send_headers', function () {
	if ( is_admin() || headers_sent() || empty( $_COOKIE['db_ts_qa'] ) ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	do_action( 'litespeed_control_set_nocache', 'db turnstile qa' );
	header( 'X-LiteSpeed-Cache-Control: no-cache, private' );
	header( 'Cache-Control: private, no-store, max-age=0' );
}, 2 );
