<?php
/**
 * The /offer/ page: the Contact Form 7 offer form in a card, with a "what
 * happens next" panel in place of the theme's old contact sidebar (a raw
 * email link and a link back to the home page).
 *
 * The form itself (fields, validation, emails) is unchanged.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_offer_page_aside' ) ) {
	/**
	 * @return string
	 */
	function db_offer_page_aside() {
		$steps = array(
			array( 'chat', 'A broker reads your offer', 'A person, not an automated reply, within one business day.' ),
			array( 'doc', 'You get an answer', 'Accepted, a counter-offer, or the reason the owner will not sell at that level.' ),
			array( 'lock', 'You pay securely', 'By card through Stripe, in full or on a monthly plan of 3 to 12 months.' ),
			array( 'swap', 'The name moves to you', 'We transfer it to your registrar account and stay with you until it resolves.' ),
		);
		$li = '';
		foreach ( $steps as $s ) {
			$icon = function_exists( 'db_svc_icon' ) ? db_svc_icon( $s[0], 20 ) : '';
			$li  .= '<li><span class="dbof-ico">' . $icon . '</span><div><strong>' . esc_html( $s[1] ) . '</strong><span>' . esc_html( $s[2] ) . '</span></div></li>';
		}
		// The empty .db-offer-intro stops the older script in db-custom-blocks.php
		// (section 11) adding its three step cards, which this panel replaces.
		return '<span class="db-offer-intro" hidden></span><aside class="dbof-aside" aria-labelledby="dbof-ah"><h2 id="dbof-ah">What happens next</h2><ol>' . $li . '</ol>'
			. '<p class="dbof-note">Your details are used only to answer this offer. Questions first? <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Contact us</a>.</p></aside>';
	}
}

add_filter( 'the_content', function ( $html ) {
	if ( ! is_page( 'offer' ) || ! in_the_loop() || ! is_main_query() || false !== strpos( $html, 'dbof-aside' ) ) {
		return $html;
	}
	$side = strpos( $html, '<div class="col-lg-3 col-md-6 col-sm-12">' );
	if ( false !== $side ) {
		// The old sidebar is the last element of the page content.
		$html = substr( $html, 0, $side );
	}
	return '<div class="dbof">' . str_replace( '<div class="col-lg-9 col-md-6 col-sm-12">', '<div class="dbof-form">', $html ) . db_offer_page_aside() . '</div>';
}, 50 );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page( 'offer' ) ) {
		return;
	}
	$css = '.dbof{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(0,.85fr);gap:clamp(20px,3vw,40px);align-items:start;margin:8px 0 clamp(32px,5vw,56px)}'
		. '.dbof>.dbof-form,.dbof>div:first-child{min-width:0;max-width:none!important;width:auto!important;flex:none!important;padding:clamp(20px,3vw,32px)!important;border:1px solid var(--m-line,#e5e7eb);border-radius:24px;background:#fff;box-shadow:0 18px 40px -28px rgba(12,20,36,.35)}'
		. '.dbof .sell-domain{margin:0 0 4px}.dbof .sell-domain p{margin:0 0 14px}.dbof .sell-domain br{display:none}'
		. 'html body .dbof .sell-domain label{display:block;margin:0 0 6px;font-size:14.5px!important;font-weight:700;color:var(--m-ink,#111827)!important}'
		. 'html body .dbof input[type=text],html body .dbof input[type=email],html body .dbof input[type=tel],html body .dbof textarea{width:100%!important;min-height:48px;padding:10px 14px!important;border:1.5px solid #c3c9d2!important;border-radius:12px!important;font-size:16px!important;background:#fff!important;color:var(--m-ink,#111827)!important;box-shadow:none!important}'
		. 'html body .dbof input[readonly]{background:#f3f5f7!important;font-weight:700}'
		. 'html body .dbof textarea{min-height:120px;max-height:240px}'
		. 'html body .dbof input:focus,html body .dbof textarea:focus{border-color:var(--m-ink,#111827)!important;box-shadow:0 0 0 3px rgba(11,122,117,.22)!important;outline:0}'
		. '.dbof .col-md-6{max-width:none!important;width:auto!important;flex:none!important;padding:0!important}'
		. 'html body .dbof input[type=submit]{width:100%;min-height:52px;border:0!important;border-radius:999px!important;background:#111827!important;color:#fff!important;font-size:16px!important;font-weight:700;cursor:pointer}'
		. 'html body .dbof input[type=submit]:hover{background:var(--m-accent,#0b7a75)!important}'
		. '.dbof-aside{position:sticky;top:96px;padding:clamp(22px,3vw,30px);border-radius:24px;color:#fff;background:radial-gradient(520px 300px at 0% 0%,rgba(25,179,166,.35),rgba(25,179,166,0) 60%),#0c1424}'
		. 'html body .dbof-aside h2{margin:0 0 18px;font-size:22px!important;line-height:1.2;font-weight:800;color:#fff!important}'
		. '.dbof-aside ol{margin:0 0 18px;padding:0;list-style:none;display:grid;gap:14px}'
		. 'html body .dbof-aside li{display:flex;gap:12px;margin:0}.dbof-aside li div{display:flex;flex-direction:column;gap:2px}'
		. 'html body .dbof-aside strong{font-size:15.5px!important;color:#fff!important}html body .dbof-aside li span:not(.dbof-ico){font-size:14.5px!important;line-height:1.5;color:rgba(255,255,255,.78)!important}'
		. '.dbof-ico{flex:none;display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:12px;background:rgba(255,255,255,.1);color:#34d5c5}'
		. 'html body .dbof-note{margin:0;padding-top:16px;border-top:1px solid rgba(255,255,255,.14);font-size:14px!important;color:rgba(255,255,255,.75)!important}html body .dbof-note a{color:#b6f0ea!important}'
		. '@media (max-width:900px){.dbof{grid-template-columns:minmax(0,1fr)}.dbof-aside{position:static}}';
	if ( function_exists( 'db_external_style' ) && db_external_style( 'offer-page', $css ) ) {
		return;
	}
	wp_register_style( 'db-offer-page', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-offer-page' );
	wp_add_inline_style( 'db-offer-page', $css );
}, 117 );
