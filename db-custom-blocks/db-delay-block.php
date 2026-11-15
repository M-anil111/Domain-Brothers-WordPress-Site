<?php
/**
 * Speed: start third-party scripts after the page has loaded.
 *
 * Measured on staging (desktop, cold cache), third-party scripts were about
 * 1 MB of the 1.25 MB a page downloads: Google Tag Manager and the tags it
 * loads (gtag, Google Ads, Universal Analytics, Clarity), AdSense and its
 * consent script, HubSpot and AddToAny. They kept the browser's load event
 * at about 3 s.
 *
 * These scripts are now marked type="text/db-delay" in the page and started
 * by a small loader when the visitor first interacts (scroll, tap, key,
 * mouse move) or 2.5 s after the page has loaded, whichever comes first.
 * Ad slots already queue their requests in window.adsbygoogle, and tags
 * already queue events in window.dataLayer, so nothing is lost; analytics and
 * ads simply start a moment later. A visitor who leaves within 2.5 s without
 * touching the page is not counted by analytics.
 *
 * Scripts can opt out with data-no-delay. JSON-LD and the site's own scripts
 * are never touched. Turn the whole feature off on Domain Brothers > Platform.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_delay_hosts' ) ) {
	/** Hosts whose scripts are started late. */
	function db_delay_hosts() {
		return (array) apply_filters( 'db_delay_hosts', array(
			'googletagmanager.com',
			'google-analytics.com',
			'googlesyndication.com',
			'googleadservices.com',
			'doubleclick.net',
			'fundingchoicesmessages.google.com',
			'hs-scripts.com',
			'hs-analytics.net',
			'hsforms.net',
			'hubspot.com',
			'addtoany.com',
			'clarity.ms',
			'connect.facebook.net',
		) );
	}
}

if ( ! function_exists( 'db_delay_enabled' ) ) {
	function db_delay_enabled() {
		$o = get_option( 'db_speed_switches', array() );
		return ! is_array( $o ) || ! isset( $o['delay'] ) || ! empty( $o['delay'] );
	}
}

if ( ! function_exists( 'db_delay_rewrite' ) ) {
	/**
	 * Marks third-party script tags in a page so they start late.
	 * Pure function: tested offline in tests/speed/delay-test.php.
	 */
	function db_delay_rewrite( $html, array $hosts ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<script' ) || false === stripos( $html, '</body>' ) ) {
			return $html;
		}
		$hosts_re = implode( '|', array_map( function ( $h ) {
			return preg_quote( $h, '#' );
		}, $hosts ) );
		$count = 0;
		$out   = preg_replace_callback( '#<script\b([^>]*)>(.*?)</script>#is', function ( $m ) use ( $hosts_re, &$count ) {
			$attrs = $m[1];
			$body  = $m[2];
			if ( preg_match( '#\bdata-no-delay\b#i', $attrs ) ) {
				return $m[0];
			}
			// Only classic scripts: leave JSON-LD, templates, modules and already-marked tags alone.
			if ( preg_match( '#\btype\s*=\s*["\']?([^"\'\s>]+)#i', $attrs, $t ) && ! in_array( strtolower( $t[1] ), array( 'text/javascript', 'application/javascript' ), true ) ) {
				return $m[0];
			}
			if ( preg_match( '#\bsrc\s*=\s*(["\'])([^"\']+)\1#i', $attrs, $s ) ) {
				if ( ! preg_match( '#^(https?:)?//([a-z0-9-]+\.)*(' . $hosts_re . ')[/:?]#i', $s[2] ) ) {
					return $m[0];
				}
				$attrs = preg_replace( '#\s*\btype\s*=\s*(["\'])[^"\']*\1#i', '', $attrs );
				$attrs = preg_replace( '#\bsrc\s*=\s*(["\'])([^"\']+)\1#i', 'data-src=$1$2$1', $attrs, 1 );
				++$count;
				return '<script type="text/db-delay"' . $attrs . '>' . $body . '</script>';
			}
			// Inline loaders of the same services (GTM snippet, gtag config, Clarity, Meta pixel).
			if ( '' !== trim( $body ) && preg_match( '#(' . $hosts_re . ')|\bgtag\s*\(|\bfbq\s*\(|\bclarity\s*\(#i', $body ) && ! preg_match( '#adsbygoogle\s*=\s*window\.adsbygoogle#i', $body ) ) {
				$attrs = preg_replace( '#\s*\btype\s*=\s*(["\'])[^"\']*\1#i', '', $attrs );
				++$count;
				return '<script type="text/db-delay"' . $attrs . '>' . $body . '</script>';
			}
			return $m[0];
		}, $html );
		if ( ! is_string( $out ) || 0 === $count ) {
			return $html;
		}
		$loader = '<script data-no-delay>(function(){var done=false,ev=["pointerdown","keydown","touchstart","scroll","mousemove","wheel"];'
			. 'function go(){if(done)return;done=true;ev.forEach(function(e){removeEventListener(e,go,{passive:true});});'
			. 'document.querySelectorAll(\'script[type="text/db-delay"]\').forEach(function(o){var n=document.createElement("script");'
			. 'for(var i=0;i<o.attributes.length;i++){var a=o.attributes[i];if(a.name==="type"||a.name==="data-src")continue;n.setAttribute(a.name,a.value);}'
			. 'var s=o.getAttribute("data-src");if(s){n.src=s;if(!o.hasAttribute("defer"))n.async=true;}else{n.text=o.text;}o.parentNode.replaceChild(n,o);});}'
			. 'ev.forEach(function(e){addEventListener(e,go,{passive:true});});'
			. 'function later(){setTimeout(go,2500);}if(document.readyState==="complete"){later();}else{addEventListener("load",later);}})();</script>';
		$pos = strripos( $out, '</body>' );
		return substr( $out, 0, $pos ) . $loader . substr( $out, $pos );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() || is_embed() || is_customize_preview() || ! db_delay_enabled() ) {
		return;
	}
	if ( isset( $_GET['db_nodelay'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	ob_start( function ( $html ) {
		return db_delay_rewrite( $html, db_delay_hosts() );
	} );
}, -999 );

// Platform page switch.
add_action( 'db_platform_sections', function () {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:980px"><table class="widefat striped"><tbody><tr><th scope="row" style="width:260px">Third-party scripts</th><td><label><input type="checkbox" name="delay" value="1"' . checked( db_delay_enabled(), true, false ) . '> Start Tag Manager, AdSense, HubSpot, AddToAny and Clarity after the page loads</label><br><small>They start on the first scroll, tap or key press, or 2.5 s after load. Add <code>?db_nodelay=1</code> to a URL to compare.</small></td></tr></tbody></table>';
	wp_nonce_field( 'db_delay_settings' );
	echo '<input type="hidden" name="action" value="db_delay_settings"><p><button class="button">Save</button></p></form>';
}, 20 );

add_action( 'admin_post_db_delay_settings', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_delay_settings' );
	$o          = get_option( 'db_speed_switches', array() );
	$o          = is_array( $o ) ? $o : array();
	$o['delay'] = ! empty( $_POST['delay'] ) ? 1 : 0;
	update_option( 'db_speed_switches', $o, false );
	if ( function_exists( 'db_pc_purge_all' ) ) {
		db_pc_purge_all();
	}
	wp_safe_redirect( admin_url( 'admin.php?page=db-platform' ) );
	exit;
} );
