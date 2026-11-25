<?php
/**
 * Cookie consent for visitors in the EEA, UK and Switzerland.
 *
 *  1. Google Consent Mode v2 defaults, first thing in <head>: ad_storage,
 *     ad_user_data, ad_personalization and analytics_storage are "denied" for
 *     those regions (Google applies the region list itself, so cached pages
 *     are fine) and "granted" elsewhere. A stored choice is applied at once.
 *  2. A small banner with "Accept all" and "Reject non-essential", shown to
 *     visitors whose browser time zone is in Europe (the page is cached, so
 *     the decision is made in the browser) and who have not chosen yet.
 *  3. Until such a visitor accepts, the third-party scripts held by the delay
 *     loader (db-delay-block.php: Tag Manager, Analytics, Ads, AdSense,
 *     Clarity, HubSpot, AddToAny, Meta pixel) are not started at all.
 *  4. Any link with data-db-consent-open (the footer "Cookie settings" link)
 *     opens the banner again so the choice can be changed.
 *
 * The choice is kept for 180 days in the visitor's browser (localStorage and a
 * first-party db_consent cookie, which is strictly necessary). Settings:
 * Domain Brothers > Platform. Not covered here: Cloudflare Zaraz (configure its
 * own consent in Cloudflare), and AdSense in the EEA/UK, which also needs a
 * Google-certified consent message (AdSense > Privacy & messaging) before ads
 * are switched on.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_consent_mode' ) ) {
	/** 'region' (default: EEA, UK, Switzerland), 'all' (everyone) or 'off'. */
	function db_consent_mode() {
		$o = get_option( 'db_consent', array() );
		$m = is_array( $o ) && isset( $o['mode'] ) ? (string) $o['mode'] : 'region';
		return in_array( $m, array( 'region', 'all', 'off' ), true ) ? $m : 'region';
	}
}

if ( ! function_exists( 'db_consent_active' ) ) {
	function db_consent_active() {
		return 'off' !== db_consent_mode();
	}
}

if ( ! function_exists( 'db_consent_regions' ) ) {
	/** ISO country codes where consent is asked first: EEA, UK, Switzerland. */
	function db_consent_regions() {
		return (array) apply_filters( 'db_consent_regions', array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH' ) );
	}
}

if ( ! function_exists( 'db_consent_skip' ) ) {
	/** Requests that never get the banner or the defaults. */
	function db_consent_skip() {
		return is_admin() || is_feed() || is_embed() || ( function_exists( 'is_robots' ) && is_robots() ) || ! db_consent_active();
	}
}

if ( ! function_exists( 'db_consent_head_js' ) ) {
	/** The <head> script: Consent Mode defaults and window.dbConsent. Pure: tested offline. */
	function db_consent_head_js( $mode, array $regions ) {
		$denied  = "{ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied',analytics_storage:'denied',wait_for_update:500";
		$granted = "{ad_storage:'granted',ad_user_data:'granted',ad_personalization:'granted',analytics_storage:'granted'}";
		$js  = 'window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}';
		if ( 'all' === $mode ) {
			$js .= "gtag('consent','default'," . $denied . '});';
		} else {
			$js .= "gtag('consent','default'," . $denied . ',region:' . wp_json_encode( array_values( $regions ) ) . '});';
			$js .= "gtag('consent','default'," . $granted . ');';
		}
		$js .= "gtag('set','ads_data_redaction',true);";
		// European time zones stand in for "in the EEA/UK/CH" (the page is cached; no server-side geo).
		$js .= '(function(){var tz="";try{tz=Intl.DateTimeFormat().resolvedOptions().timeZone||"";}catch(e){}'
			. 'var eu=' . ( 'all' === $mode ? 'true' : '/^(Europe\/|Atlantic\/(Reykjavik|Canary|Madeira|Azores|Faroe)|Arctic\/Longyearbyen)/.test(tz)' ) . ';'
			. 'var c="";try{c=localStorage.getItem("db_consent")||"";}catch(e){}'
			. 'if(!c){var m=document.cookie.match(/(?:^|; )db_consent=(all|essential)/);if(m){c=m[1];}}'
			. 'if(c!=="all"&&c!=="essential"){c="";}'
			. 'if(c){gtag("consent","update",c==="all"?' . $granted . ':{ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",analytics_storage:"denied"});}'
			. 'window.dbConsent={region:eu,choice:c,ok:function(){return !this.region||this.choice==="all";}};})();';
		return $js;
	}
}

add_action( 'wp_head', function () {
	if ( db_consent_skip() ) {
		return;
	}
	// data-cfasync="false": Cloudflare Rocket Loader must not delay this; data-no-delay: nor must the delay loader.
	echo '<script data-cfasync="false" data-no-delay>' . db_consent_head_js( db_consent_mode(), db_consent_regions() ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}, 0 );

add_action( 'wp_footer', function () {
	if ( db_consent_skip() ) {
		return;
	}
	$policy = get_page_by_path( 'cookie-policy' );
	$link   = ( $policy && 'publish' === $policy->post_status ) ? ' <a href="' . esc_url( get_permalink( $policy ) ) . '">Cookie Policy</a>' : '';
	?>
<div id="db-consent" class="db-consent" role="region" aria-label="Cookie choices" hidden>
	<p class="db-consent-text">We use cookies to make this site work and, with your permission, to measure visits and improve our marketing.<?php echo $link; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?></p>
	<div class="db-consent-btns">
		<button type="button" class="db-consent-btn db-consent-btn--ghost" data-db-consent="essential">Reject non-essential</button>
		<button type="button" class="db-consent-btn" data-db-consent="all">Accept all</button>
	</div>
</div>
<style>
#db-consent{position:fixed;left:16px;right:16px;bottom:16px;z-index:100000;max-width:760px;margin:0 auto;display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px;padding:16px 18px;background:#fff;color:#1f2937;border:1px solid #d1d5db;border-radius:12px;box-shadow:0 10px 30px rgba(17,24,39,.18);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
#db-consent[hidden]{display:none}
#db-consent .db-consent-text{flex:1 1 320px;margin:0;color:#1f2937}
#db-consent .db-consent-text a{color:#111827;font-weight:600;text-decoration:underline}
#db-consent .db-consent-btns{display:flex;gap:10px;flex-wrap:wrap}
#db-consent .db-consent-btn{min-height:44px;padding:10px 18px;border-radius:8px;border:1.5px solid #111827;background:#111827;color:#fff;font:inherit;font-weight:700;cursor:pointer}
#db-consent .db-consent-btn--ghost{background:#fff;color:#111827}
#db-consent .db-consent-btn:focus-visible{outline:3px solid #2563eb;outline-offset:2px}
@media (max-width:520px){#db-consent{left:8px;right:8px;bottom:8px}#db-consent .db-consent-btns{width:100%}#db-consent .db-consent-btn{flex:1 1 0}}
</style>
<script data-cfasync="false" data-no-delay>
(function () {
	var box = document.getElementById('db-consent');
	if (!box || !window.dbConsent) { return; }
	var C = window.dbConsent;
	function show() { box.hidden = false; }
	function save(v) {
		C.choice = v;
		try { localStorage.setItem('db_consent', v); } catch (e) {}
		document.cookie = 'db_consent=' + v + ';path=/;max-age=' + (180 * 24 * 3600) + ';SameSite=Lax' + (location.protocol === 'https:' ? ';Secure' : '');
		var g = v === 'all' ? 'granted' : 'denied';
		if (typeof window.gtag === 'function') { window.gtag('consent', 'update', { ad_storage: g, ad_user_data: g, ad_personalization: g, analytics_storage: g }); }
		box.hidden = true;
		try { document.dispatchEvent(new CustomEvent('db-consent', { detail: { choice: v } })); } catch (e) {}
	}
	box.addEventListener('click', function (e) {
		var b = e.target.closest ? e.target.closest('[data-db-consent]') : null;
		if (b) { save(b.getAttribute('data-db-consent')); }
	});
	document.addEventListener('click', function (e) {
		var a = e.target.closest ? e.target.closest('[data-db-consent-open]') : null;
		if (a) { e.preventDefault(); show(); var f = box.querySelector('[data-db-consent="all"]'); if (f) { f.focus(); } }
	});
	if (C.region && !C.choice) { show(); }
})();
</script>
	<?php
}, 5 );

// Platform page setting.
add_action( 'db_platform_sections', function () {
	$m = db_consent_mode();
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:980px"><table class="widefat striped"><tbody><tr><th scope="row" style="width:260px">Cookie consent</th><td>';
	foreach ( array( 'region' => 'Ask visitors in the EEA, UK and Switzerland (recommended)', 'all' => 'Ask every visitor', 'off' => 'Off (no banner, no consent defaults)' ) as $v => $label ) {
		echo '<label style="display:block"><input type="radio" name="mode" value="' . esc_attr( $v ) . '"' . checked( $m, $v, false ) . '> ' . esc_html( $label ) . '</label>';
	}
	echo '<small>Until a visitor in those regions accepts, Tag Manager, Analytics, Ads, AdSense, Clarity, HubSpot, AddToAny and the Meta pixel do not start, and Google tags run in Consent Mode with everything denied. Cloudflare Zaraz has its own consent setting in Cloudflare. Before showing ads in the EEA or UK, also turn on a Google-certified consent message under AdSense &gt; Privacy &amp; messaging.</small></td></tr></tbody></table>';
	wp_nonce_field( 'db_consent_settings' );
	echo '<input type="hidden" name="action" value="db_consent_settings"><p><button class="button">Save</button></p></form>';
}, 21 );

add_action( 'admin_post_db_consent_settings', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_consent_settings' );
	$m = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'region';
	update_option( 'db_consent', array( 'mode' => in_array( $m, array( 'region', 'all', 'off' ), true ) ? $m : 'region' ), false );
	if ( function_exists( 'db_pc_purge_all' ) ) {
		db_pc_purge_all();
	}
	wp_safe_redirect( admin_url( 'admin.php?page=db-platform' ) );
	exit;
} );
