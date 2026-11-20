<?php
/**
 * Analytics events for Google Tag Manager / GA4.
 *
 * Pushes plain objects to window.dataLayer; nothing is sent from this plugin.
 * Consent Mode (db-consent-block.php) decides what GTM/GA4 may do with them.
 *
 * Events: view_item (domain page), begin_checkout (buy or payment plan link),
 * offer_click (make-an-offer control), search (site search form),
 * generate_lead (Contact Form 7 sent), tool_use (domain tool form submitted).
 * No names, emails or form values are ever included; only the domain being viewed.
 *
 * @package DB_Custom_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_ev_config' ) ) {
	/**
	 * Sanitised page context handed to the script. Pure.
	 *
	 * @param array $ctx type (domain|tool|other), name, price, ext, len, state, tool.
	 * @return array
	 */
	function db_ev_config( array $ctx ) {
		$type = in_array( $ctx['type'] ?? '', array( 'domain', 'tool' ), true ) ? $ctx['type'] : 'other';
		$cfg  = array( 'type' => $type );
		if ( 'domain' === $type ) {
			$cfg['name']  = substr( strtolower( preg_replace( '/[^a-z0-9.\-]/i', '', (string) ( $ctx['name'] ?? '' ) ) ), 0, 80 );
			$cfg['price'] = max( 0, (int) round( (float) ( $ctx['price'] ?? 0 ) ) );
			$cfg['ext']   = substr( preg_replace( '/[^a-z.]/i', '', (string) ( $ctx['ext'] ?? '' ) ), 0, 12 );
			$cfg['len']   = max( 0, (int) ( $ctx['len'] ?? 0 ) );
			$cfg['state'] = in_array( $ctx['state'] ?? '', array( 'standard', 'offer', 'not_for_sale', 'sold' ), true ) ? $ctx['state'] : 'offer';
		}
		if ( 'tool' === $type ) {
			$cfg['tool'] = substr( preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) ( $ctx['tool'] ?? '' ) ) ), 0, 60 );
		}
		return $cfg;
	}
}

if ( ! function_exists( 'db_ev_script' ) ) {
	/** The inline script for a context. Pure: tested offline. */
	function db_ev_script( array $cfg ) {
		$json = wp_json_encode( $cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		return '(function(){var C=' . $json . ';var L=window.dataLayer=window.dataLayer||[];'
			. 'function p(o){try{L.push(o);}catch(e){}}'
			. 'function item(){return {item_name:C.name,item_category:C.ext,price:C.price,currency:"USD",name_length:C.len,listing_state:C.state};}'
			. 'if(C.type==="domain"){p({event:"view_item",items:[item()]});}'
			. 'document.addEventListener("click",function(e){var t=e.target&&e.target.closest?e.target.closest("a,button"):null;if(!t){return;}'
			. 'var h=t.getAttribute("href")||"";var x=(t.textContent||"").replace(/\s+/g," ").trim();'
			. 'if(h.indexOf("/buy-now/")>-1||h.indexOf("/payment-plan-setup/")>-1){p({event:"begin_checkout",method:h.indexOf("/buy-now/")>-1?"pay_in_full":"payment_plan",items:C.type==="domain"?[item()]:undefined});return;}'
			. 'if(C.type==="domain"&&/make an offer|submit offer|send offer/i.test(x)){p({event:"offer_click",items:[item()]});}'
			. '},true);'
			. 'document.addEventListener("submit",function(e){var f=e.target;if(!f||!f.matches){return;}'
			. 'if(f.matches("form[role=search],form.search-form,form.dbsl-form")){var q=f.querySelector("input[type=search],input[name=s]");p({event:"search",search_term_length:q?String(q.value||"").length:0});return;}'
			. 'if(C.type==="tool"&&f.matches("form.dblk-form")){p({event:"tool_use",tool:C.tool});}'
			. '},true);'
			. 'document.addEventListener("wpcf7mailsent",function(){p({event:"generate_lead",lead_type:"contact_form"});},false);'
			. '})();';
	}
}

add_action( 'wp_footer', function () {
	if ( is_admin() || is_feed() || is_embed() || is_404() ) {
		return;
	}
	$ctx = array( 'type' => 'other' );
	if ( is_singular( 'domain' ) && function_exists( 'db_dd_facts' ) ) {
		$post = get_post();
		$f    = $post ? db_dd_facts( $post ) : array();
		$ctx  = array(
			'type' => 'domain', 'name' => $f['name'] ?? '', 'price' => $f['price'] ?? 0, 'ext' => $f['ext'] ?? '',
			'len' => $f['len'] ?? 0, 'state' => $post && function_exists( 'db_listing_state' ) ? db_listing_state( $post ) : 'offer',
		);
	} elseif ( function_exists( 'db_sales_request_path' ) ) {
		$path = (string) db_sales_request_path();
		if ( 0 === strpos( $path, 'domain-tools/' ) ) {
			$ctx = array( 'type' => 'tool', 'tool' => substr( $path, strlen( 'domain-tools/' ) ) );
		}
	}
	echo '<script data-no-delay>' . db_ev_script( db_ev_config( $ctx ) ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}, 99 );
