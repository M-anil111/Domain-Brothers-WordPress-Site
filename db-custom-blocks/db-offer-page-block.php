<?php
/**
 * The offer page: /make-an-offer/?d=name&amt=1000. It replaces the "Make a custom offer" popup.
 *
 * Uses the same Contact Form 7 offer form as before (field names, confirmation email and CRM lead
 * are untouched), on a page of its own. The server fills the domain and the amount, so the form
 * works even if scripts are slow to load. The domain must be a real listing that is for sale.
 *
 * @package DB_Custom_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_op_clean_domain' ) ) {
	/** A plain domain name in lower case, or '' when the text is not one. Pure. */
	function db_op_clean_domain( $raw ) {
		$n = strtolower( trim( (string) $raw ) );
		return preg_match( '/^[a-z0-9][a-z0-9\-]{0,60}(\.[a-z0-9\-]{2,24}){1,2}$/', $n ) ? $n : '';
	}
}

if ( ! function_exists( 'db_op_clean_amount' ) ) {
	/** Whole dollars from text such as "$11,000" (digits only, 1 to 9 digits), or 0. Pure. */
	function db_op_clean_amount( $raw ) {
		$d = preg_replace( '/[^0-9]/', '', (string) preg_replace( '/\.\d+$/', '', trim( (string) $raw ) ) );
		return ( '' !== $d && strlen( $d ) <= 9 ) ? (int) $d : 0;
	}
}

if ( ! function_exists( 'db_op_suggest' ) ) {
	/** A starting offer: 80% of the listed price rounded to the nearest hundred, never under $100; 0 when there is no price. Pure. */
	function db_op_suggest( $price ) {
		$price = (float) $price;
		return $price > 0 ? (int) max( 100, round( $price * 0.8, -2 ) ) : 0;
	}
}

if ( ! function_exists( 'db_op_ctx' ) ) {
	/**
	 * Who the offer is for, from the request. null when this is not the offer page; otherwise
	 * array( 'ok' => bool, 'name', 'post', 'price', 'amount', 'url' ).
	 */
	function db_op_ctx() {
		static $ctx = false;
		if ( false !== $ctx ) {
			return $ctx;
		}
		if ( is_admin() || ! function_exists( 'db_sales_request_path' ) || 'make-an-offer' !== db_sales_request_path() ) {
			return $ctx = null;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		$name   = db_op_clean_domain( isset( $_GET['d'] ) ? wp_unslash( $_GET['d'] ) : '' );
		$amount = db_op_clean_amount( isset( $_GET['amt'] ) ? wp_unslash( $_GET['amt'] ) : '' );
		// phpcs:enable
		$post  = ( '' !== $name && function_exists( 'db_listing_post_for_domain' ) ) ? db_listing_post_for_domain( $name ) : null;
		$state = $post && function_exists( 'db_listing_state' ) ? db_listing_state( $post ) : '';
		$ok    = $post && in_array( $state, array( 'standard', 'offer' ), true );
		$price = $ok && function_exists( 'db_listing_price' ) ? (float) db_listing_price( $post->ID ) : 0.0;
		return $ctx = array(
			'ok'     => (bool) $ok,
			'name'   => $name,
			'post'   => $ok ? $post : null,
			'price'  => $price,
			'amount' => $amount > 0 ? $amount : db_op_suggest( $price ),
			'url'    => $ok ? get_permalink( $post ) : home_url( '/all-domains/' ),
		);
	}
}

if ( ! function_exists( 'db_op_url' ) ) {
	/** Address of the offer page for a domain, with the amount when one is known. */
	function db_op_url( $name, $amount = 0 ) {
		$args = array( 'd' => (string) $name );
		if ( (int) $amount > 0 ) {
			$args['amt'] = (int) $amount;
		}
		return add_query_arg( $args, home_url( '/make-an-offer/' ) );
	}
}

// The server fills the two fields the form needs, so it never depends on a script running first.
add_filter( 'wpcf7_form_tag', static function ( $tag ) {
	$ctx = db_op_ctx();
	if ( ! $ctx || empty( $ctx['ok'] ) || empty( $tag['name'] ) ) {
		return $tag;
	}
	if ( 'hide_domainname' === $tag['name'] ) {
		$tag['values']     = array( $ctx['name'] );
		$tag['raw_values'] = array( $ctx['name'] );
	} elseif ( 'your-offer' === $tag['name'] && $ctx['amount'] > 0 ) {
		$tag['values']     = array( (string) $ctx['amount'] );
		$tag['raw_values'] = array( (string) $ctx['amount'] );
		// With a placeholder option Contact Form 7 shows the first value as grey placeholder text
		// and submits an empty field. Drop it so the amount is a real value.
		$tag['options'] = array_values( array_filter( (array) $tag['options'], static function ( $o ) {
			return ! in_array( preg_replace( '/[:\s].*$/', '', (string) $o ), array( 'placeholder', 'watermark' ), true );
		} ) );
	}
	return $tag;
}, 20 );

add_filter( 'db_tp_route_meta_extra', static function ( $m, $path ) {
	return 'make-an-offer' === $path ? array( 'Make an Offer | Domain Brothers', 'Send an offer for a premium domain. Our team negotiates for you and replies personally.', home_url( '/make-an-offer/' ) ) : $m;
}, 10, 2 );
// The tools filter marks every extra route indexable; this one is a form and stays out of search.
add_filter( 'rank_math/frontend/robots', static function ( $robots ) {
	if ( db_op_ctx() && is_array( $robots ) ) {
		$robots['index']  = 'noindex';
		$robots['follow'] = 'follow';
	}
	return $robots;
}, 27 );

if ( ! function_exists( 'db_op_css' ) ) {
	function db_op_css() {
		return '<style>.db-op{max-width:1040px;margin:0 auto;padding:6px 20px 56px;color:#1f2937}'
			. '.db-op-head{margin:4px 0 22px}.db-op-kicker{margin:0 0 6px;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#4b5563}'
			. 'html body .db-op .db-op-title{margin:0 0 8px;font-size:clamp(28px,4.4vw,46px);line-height:1.1;letter-spacing:-.02em;color:#0a1628!important;text-align:left!important;overflow-wrap:anywhere}'
			. '.db-op-name{background:linear-gradient(90deg,#0b7a75,#1d4f9c);-webkit-background-clip:text;background-clip:text;color:transparent}'
			. 'html body .db-op .db-op-sub{margin:0;font-size:18px;color:#4b5563;text-align:left!important}'
			. '.db-op-grid{display:grid;gap:22px;grid-template-columns:minmax(0,1fr)}@media(min-width:900px){.db-op-grid{grid-template-columns:minmax(0,1.15fr) minmax(0,.85fr);align-items:start}}'
			. '.db-op-card{background:#fff;border:1px solid #dfe5e1;border-radius:22px;padding:26px;box-shadow:0 8px 28px rgba(10,22,40,.07)}'
			. 'html body .db-op .db-op-card h3{margin:0 0 14px;font-size:20px;color:#0a1628!important;text-align:left!important}'
			. '.db-op-sum{margin:0 0 20px;display:grid;gap:10px}.db-op-sum div{display:flex;justify-content:space-between;gap:14px;padding:12px 14px;border-radius:14px;background:#f1f5f2}.db-op-sum dt{color:#4b5563}.db-op-sum dd{margin:0;font-weight:800;color:#0a1628;font-variant-numeric:tabular-nums}'
			. '.db-op-steps{margin:0 0 20px;padding:0;list-style:none;display:grid;gap:12px;counter-reset:s}.db-op-steps li{position:relative;padding-left:40px;line-height:1.5;counter-increment:s}'
			. '.db-op-steps li:before{content:counter(s);position:absolute;left:0;top:0;width:28px;height:28px;border-radius:50%;background:#0a1628;color:#b5e61d;font-weight:800;display:grid;place-items:center;font-size:14px}'
			/* the form: no icon discs over the fields (the icon font never loads), roomy inputs, a clear button */
			. '.db-op .wpcf7 .frm-field{position:relative;margin:0 0 14px}.db-op .wpcf7 .frm-field i,.db-op .wpcf7 .frm-field .fa{display:none!important}'
			. '.db-op .wpcf7 input[type=text],.db-op .wpcf7 input[type=email],.db-op .wpcf7 input[type=tel]{width:100%!important;min-height:54px;padding:0 16px!important;border:1.5px solid #cfd6d2!important;border-radius:14px!important;font-size:17px;background:#fff!important;color:#0a1628;box-shadow:none!important;box-sizing:border-box}'
			. '.db-op .wpcf7 input:focus{outline:3px solid rgba(11,122,117,.3)!important;border-color:#0a1628!important}'
			. '.db-op .wpcf7 .frm-usd-offer{display:block!important}.db-op .db-op-lbl{display:block;margin:0 0 6px;font-weight:700;color:#0a1628}'
			. '.db-op .wpcf7 .hide-domain-div{display:none!important}.db-op .wpcf7 .required-note{margin:0 0 12px;font-size:13px;color:#4b5563}.db-op .wpcf7 .required-note span{color:#b91c1c}'
			. '.db-op .wpcf7 .domain-detail-captcha{margin:6px 0 14px}.db-op .wpcf7 .inq-btn{margin:6px 0 0}'
			. '.db-op .wpcf7 input[type=submit]{width:100%;min-height:56px;border:0;border-radius:999px;background:#0a1628;color:#fff!important;font-size:17px;font-weight:700;cursor:pointer}.db-op .wpcf7 input[type=submit]:hover{background:#1b2d49}'
			. '.db-op .wpcf7 .domain-detail-checkbox{margin:14px 0 0;font-size:13px;color:#4b5563}.db-op .wpcf7 .wpcf7-not-valid-tip{color:#b91c1c;font-size:14px;margin-top:4px}'
			. '.db-op-done{margin-top:22px;border-color:#9bd5ae;background:#f1fbf3}.db-op-done[hidden]{display:none}.db-op.is-done .db-op-grid{display:none}'
			. '.db-op-links{display:flex;flex-wrap:wrap;gap:10px;margin:16px 0 0}.db-op-links a{display:inline-flex;align-items:center;min-height:46px;padding:0 20px;border-radius:999px;border:1.5px solid #0a1628;color:#0a1628!important;font-weight:700;text-decoration:none!important}'
			. '.db-op-links a:first-child{background:#0a1628;color:#fff!important}</style>';
	}
}

if ( ! function_exists( 'db_op_js' ) ) {
	function db_op_js( $name, $suggest_label = '' ) {
		return '(function(){var D=' . wp_json_encode( (string) $name ) . ';var root=document.querySelector(".db-op");if(!root){return;}'
			. 'function fill(){var h=document.getElementById("hide_domainname");if(h&&!h.value){h.value=D;}}'
			. 'function lbl(){var f=document.getElementById("offer_popup");if(f&&!document.querySelector(".db-op-lbl")){f.removeAttribute("readonly");f.setAttribute("inputmode","numeric");f.setAttribute("autocomplete","off");var l=document.createElement("label");l.className="db-op-lbl";l.setAttribute("for","offer_popup");l.textContent="Your offer (USD)";var w=f.closest(".frm-field");if(w){w.parentNode.insertBefore(l,w);}}'
			. 'root.querySelectorAll(".wpcf7 input[type=text],.wpcf7 input[type=email],.wpcf7 input[type=tel]").forEach(function(i){if(!i.getAttribute("aria-label")&&i.getAttribute("placeholder")){i.setAttribute("aria-label",i.getAttribute("placeholder").replace(/\s*\*$/,""));}});}'
			. 'function money(v){v=String(v||"").replace(/[^0-9]/g,"");return v?"$"+Number(v).toLocaleString("en-US"):"";}'
			. 'function bind(){var f=document.getElementById("offer_popup"),o=document.getElementById("db-op-amt");if(f&&o&&!f.getAttribute("data-b")){f.setAttribute("data-b","1");f.addEventListener("input",function(){f.value=f.value.replace(/[^0-9]/g,"");o.textContent=money(f.value)||"Not set";});}}'
			. 'function init(){fill();lbl();bind();}'
			. 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}else{init();}window.addEventListener("load",init);'
			. 'document.addEventListener("wpcf7mailsent",function(){root.classList.add("is-done");var d=document.getElementById("db-op-done");if(d){d.hidden=false;d.focus();}try{window.scrollTo({top:Math.max(0,root.getBoundingClientRect().top+window.scrollY-90),behavior:"smooth"});}catch(e){}},false);'
			. '})();';
	}
}

if ( ! function_exists( 'db_op_body' ) ) {
	/** The page body for a resolved context (see db_op_ctx()). */
	function db_op_body( array $ctx ) {
		$e = function ( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); };
		$h = db_op_css();
		if ( empty( $ctx['ok'] ) ) {
			return $h . '<div class="db-op"><div class="db-op-card"><h3>We could not find that domain</h3><p>The link may be incomplete, or the domain may no longer be for sale.</p><div class="db-op-links"><a href="' . $e( home_url( '/all-domains/' ) ) . '">Browse all domains</a></div></div></div>';
		}
		$name   = $ctx['name'];
		$amount = (int) $ctx['amount'];
		$money  = function ( $n ) { return '$' . number_format( (float) $n ); };
		$form   = '';
		$fid    = function_exists( 'db_offer_resolved_form_id' ) ? (int) db_offer_resolved_form_id() : 0;
		if ( $fid > 0 ) {
			$form = do_shortcode( '[contact-form-7 id="' . $fid . '"]' );
		}
		if ( '' === $form ) {
			$form = '<p>The offer form is not available right now. Please email <a href="mailto:sales@domainbrothers.com">sales@domainbrothers.com</a>.</p>';
		}
		$h .= '<div class="db-op">' . ( function_exists( 'db_pa_html' ) ? db_pa_html( array( 'back_url' => $ctx['url'], 'back_label' => 'Back to ' . $name, 'share_url' => $ctx['url'], 'share_title' => $name . ' is for sale at Domain Brothers' ) ) : '' );
		$h .= '<header class="db-op-head"><p class="db-op-kicker">Your offer</p><h2 class="db-op-title">Offer on <span class="db-op-name">' . $e( $name ) . '</span></h2><p class="db-op-sub">Tell us what you would pay. Our team negotiates for you and replies personally.</p></header>';
		$h .= '<div class="db-op-grid"><section class="db-op-card" aria-labelledby="db-op-f"><h3 id="db-op-f">Your details</h3>' . $form . '</section>';
		$h .= '<aside class="db-op-card" aria-labelledby="db-op-s"><h3 id="db-op-s">Your offer</h3><dl class="db-op-sum">'
			. ( $ctx['price'] > 0 ? '<div><dt>Listed price</dt><dd>' . $e( $money( $ctx['price'] ) ) . '</dd></div>' : '<div><dt>Listed price</dt><dd>Open to offers</dd></div>' )
			. '<div><dt>Your offer</dt><dd id="db-op-amt">' . ( $amount > 0 ? $e( $money( $amount ) ) : 'Not set' ) . '</dd></div></dl>'
			. '<h3>What happens next</h3><ol class="db-op-steps"><li>You get a confirmation email as soon as you send the offer.</li><li>Our team negotiates for you and replies personally, usually within one business day.</li><li>When you agree a price, you receive a secure link to pay in full or on a plan.</li></ol>'
			. ( function_exists( 'db_proof_html' ) ? db_proof_html( $ctx['post'] ? $ctx['post']->ID : 0, $name ) : '' ) . '</aside></div>';
		$h .= '<section id="db-op-done" class="db-op-card db-op-done" tabindex="-1" hidden aria-live="polite"><h3>Offer received</h3><p>Thank you. We have your offer for <strong>' . $e( $name ) . '</strong> and sent a confirmation to your email. Our team will reply within one business day.</p><div class="db-op-links"><a href="' . $e( $ctx['url'] ) . '">Back to ' . $e( $name ) . '</a><a href="' . $e( home_url( '/all-domains/' ) ) . '">Browse more domains</a></div></section>';
		return $h . '</div><script data-no-delay>' . db_op_js( $name ) . '</script>';
	}
}

add_action( 'template_redirect', function () {
	$ctx = db_op_ctx();
	if ( ! $ctx || ! function_exists( 'db_sales_render' ) ) {
		return;
	}
	db_sales_render( 'Make an offer', 'Make an offer', db_op_body( $ctx ), true );
}, 11 );

// The theme's /offer/ page carried a locked, empty "Domain Name (required)" field (a theme script
// sets it to "N/A"), so offers arrived without a name (bug register batch 2, CK-4). Offers live on
// /make-an-offer/, which checks the name against the listings; /offer/ forwards there.
add_action( 'template_redirect', static function () {
	if ( is_admin() || ! function_exists( 'is_page' ) || ! is_page( 'offer' ) ) {
		return;
	}
	$args = array();
	foreach ( array( 'd', 'amt' ) as $k ) {
		if ( isset( $_GET[ $k ] ) && is_string( $_GET[ $k ] ) && '' !== $_GET[ $k ] ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
	}
	wp_safe_redirect( add_query_arg( $args, home_url( '/make-an-offer/' ) ), 301 );
	exit;
}, 4 );
