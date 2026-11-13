<?php
/**
 * Cloudflare Turnstile on public forms (bot protection without a puzzle).
 *
 * Off until both keys are saved under Domain Brothers > Launch checks. With keys:
 *   - every Contact Form 7 form gets a Turnstile widget, and a submission without a
 *     valid token is treated as spam;
 *   - the sign-in code request (/wishlist/code) and the account offer (/account/offer)
 *     require a valid token;
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

if ( ! function_exists( 'db_ts_site_key' ) ) {
	function db_ts_site_key() {
		return preg_match( '/^[0-9A-Za-z_\-]{10,80}$/', (string) ( db_ts_settings()['site'] ?? '' ) ) ? (string) db_ts_settings()['site'] : '';
	}
}

if ( ! function_exists( 'db_ts_secret' ) ) {
	function db_ts_secret() {
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
	echo '<script>window.DB_TS_SITE=' . wp_json_encode( db_ts_site_key() ) . ';window.dbTsRender=function(root){if(!window.turnstile||!window.DB_TS_SITE){return;}(root||document).querySelectorAll(".db-ts:not([data-ts-id])").forEach(function(el){el.setAttribute("data-ts-id",String(window.turnstile.render(el,{sitekey:window.DB_TS_SITE})));});};window.dbTsToken=function(root){var i=root&&root.querySelector("input[name=\'cf-turnstile-response\']");return i?i.value:"";};window.dbTsLoad=function(){window.dbTsRender(document);};</script>' . "\n" .
		'<script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=dbTsLoad&amp;render=explicit" async defer></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources, WordPress.Security.EscapeOutput
}, 85 );

// Contact Form 7: a widget at the end of every form, and a missing or failed check counts as spam.
add_filter( 'wpcf7_form_elements', function ( $html ) {
	if ( ! db_ts_enabled() ) {
		return $html;
	}
	return $html . '<div class="db-ts" style="margin:12px 0"></div>';
} );

add_filter( 'wpcf7_spam', function ( $spam ) {
	if ( $spam || ! db_ts_enabled() ) {
		return $spam;
	}
	$token = isset( $_POST['cf-turnstile-response'] ) ? wp_unslash( $_POST['cf-turnstile-response'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
	return ! db_ts_verify( $token, function_exists( 'db_wl_ip' ) ? db_wl_ip() : '' );
} );

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
