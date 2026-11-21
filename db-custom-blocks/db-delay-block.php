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
 * mouse move), the way WP Rocket's "delay JavaScript" works. There is no
 * timer by default (filter db_delay_fallback_ms sets one, in ms). Cloudflare
 * Zaraz already sends the Google Analytics page view on load, so page views
 * are still counted for visitors who never interact. Measured with
 * Lighthouse, any timer (2.5 s, 5 s, 15 s) put Tag Manager, ads and Clarity
 * inside the page-load window: Zaraz leaves a request open, so Lighthouse
 * keeps recording for 45 s, and the tags added about 1 s of main-thread
 * blocking on a mid-range phone.
 * Ad slots already queue their requests in window.adsbygoogle, and tags
 * already queue events in window.dataLayer, so nothing is lost; analytics and
 * ads simply start a moment later. Tag Manager tags, ads, HubSpot and Clarity
 * run only for visitors who interact with the page.
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
	function db_delay_rewrite( $html, array $hosts, $immediate = false ) {
		if ( ! is_string( $html ) || ( false === stripos( $html, '<script' ) && false === stripos( $html, '<iframe' ) ) || false === stripos( $html, '</body>' ) ) {
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
		if ( ! is_string( $out ) ) {
			return $html;
		}
		// Third-party iframes (Facebook like box, YouTube, Maps) load only when scrolled near.
		$out = preg_replace_callback( '#<iframe\b([^>]*)>#i', function ( $m ) {
			if ( preg_match( '#\bloading\s*=#i', $m[1] ) && ! preg_match( '#\btitle\s*=#i', $m[1] ) && preg_match( '#youtube|vimeo#i', $m[1] ) ) {
				return '<iframe title="Embedded video"' . $m[1] . '>';
			}
			if ( preg_match( '#\bloading\s*=#i', $m[1] ) || ! preg_match( '#\bsrc\s*=\s*["\'](https?:)?//([a-z0-9-]+\.)*(facebook\.com|youtube\.com|youtube-nocookie\.com|google\.com|vimeo\.com)[/:?]#i', $m[1] ) ) {
				return $m[0];
			}
			// An embed without a title is announced as just "frame" (Lighthouse frame-title).
			$title = preg_match( '#\btitle\s*=#i', $m[1] ) ? '' : ' title="' . ( preg_match( '#youtube|vimeo#i', $m[1] ) ? 'Embedded video' : 'Embedded content' ) . '"';
			return '<iframe loading="lazy"' . $title . $m[1] . '>';
		}, $out );
		if ( 0 === $count ) {
			return $out;
		}
		$loader = '<script data-no-delay>(function(){var done=false,ev=["pointerdown","keydown","touchstart","scroll","mousemove","wheel"];'
			// Consent (db-consent-block.php): while a visitor who must be asked has not accepted, nothing starts.
			. 'function ok(){var C=window.dbConsent;return !C||C.ok();}'
			. 'function go(){if(done||!ok())return;done=true;ev.forEach(function(e){removeEventListener(e,go,{passive:true});});'
			. 'document.querySelectorAll(\'script[type="text/db-delay"]\').forEach(function(o){var n=document.createElement("script");'
			. 'for(var i=0;i<o.attributes.length;i++){var a=o.attributes[i];if(a.name==="type"||a.name==="data-src")continue;n.setAttribute(a.name,a.value);}'
			. 'var s=o.getAttribute("data-src");if(s){n.src=s;if(!o.hasAttribute("defer"))n.async=true;}else{n.text=o.text;}o.parentNode.replaceChild(n,o);});}'
			. 'ev.forEach(function(e){addEventListener(e,go,{passive:true});});'
			. 'document.addEventListener("db-consent",function(e){if(e.detail&&e.detail.choice==="all")go();});'
			. ( $immediate ? 'go();' : '' )
			. 'function later(){var ms=' . (int) apply_filters( 'db_delay_fallback_ms', 0 ) . ';if(ms>0)setTimeout(go,ms);}if(document.readyState==="complete"){later();}else{addEventListener("load",later);}})();</script>';
		$pos = strripos( $out, '</body>' );
		return substr( $out, 0, $pos ) . $loader . substr( $out, $pos );
	}
}

if ( ! function_exists( 'db_css_combine' ) ) {
	/**
	 * Fewer render-blocking requests, same cascade.
	 *
	 * - Runs of this plugin's stylesheets (uploads/db-blocks/*.css) that are
	 *   already next to each other in the page are served as one file, in the
	 *   same order. A run broken by another stylesheet is left split, so no rule
	 *   moves relative to the theme's CSS.
	 * - LiteSpeed Cache's empty litespeed-dummy.css link is dropped.
	 *
	 * $read( $basename ) returns a member file's CSS or null; $write( $basename,
	 * $css ) stores the combined file and returns its URL or ''. Pure apart from
	 * those callbacks: tested offline in tests/speed/speed-test.php.
	 */
	function db_css_combine( $html, $base_url, callable $read, callable $write ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<link' ) ) {
			return $html;
		}
		$html = preg_replace( '#<link\b[^>]*litespeed-dummy\.css[^>]*>\s*#i', '', $html );
		$q    = preg_quote( rtrim( $base_url, '/' ), '#' );
		$one  = '<link\b[^>]*rel=[\'"]stylesheet[\'"][^>]*href=[\'"]' . $q . '/([a-z0-9-]+-[0-9a-f]{10}\.css)[\'"][^>]*>';
		return preg_replace_callback( '#(?:' . $one . '\s*){2,}#i', function ( $m ) use ( $one, $read, $write ) {
			preg_match_all( '#' . $one . '#i', $m[0], $parts );
			$names = $parts[1];
			$css   = '';
			foreach ( $names as $n ) {
				$c = $read( $n );
				if ( null === $c ) {
					return $m[0];
				}
				$css .= '/* ' . $n . " */\n" . $c . "\n";
			}
			$url = $write( 'combo-' . substr( md5( implode( '|', $names ) . '|' . md5( $css ) ), 0, 10 ) . '.css', $css );
			if ( '' === $url ) {
				return $m[0];
			}
			preg_match( '#\s*$#', $m[0], $tail );
			return "<link rel='stylesheet' id='db-combo-css' href='" . esc_url( $url ) . "' media='all' />" . $tail[0];
		}, $html );
	}
}

if ( ! function_exists( 'db_css_combine_page' ) ) {
	/** db_css_combine() wired to the uploads/db-blocks directory. */
	function db_css_combine_page( $html ) {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return $html;
		}
		$dir = $up['basedir'] . '/db-blocks';
		$url = $up['baseurl'] . '/db-blocks';
		return db_css_combine(
			$html,
			$url,
			function ( $name ) use ( $dir ) {
				$f = $dir . '/' . basename( $name );
				$c = is_readable( $f ) ? @file_get_contents( $f ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				return false === $c ? null : $c;
			},
			function ( $name, $css ) use ( $dir, $url ) {
				$f = $dir . '/' . basename( $name );
				if ( ! file_exists( $f ) ) {
					$tmp = $f . '.' . wp_generate_password( 6, false ) . '.tmp';
					if ( false === @file_put_contents( $tmp, $css ) || ! @rename( $tmp, $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
						@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
						return '';
					}
					// Combined files from older versions: removed after 30 days (cached pages may still link them until then).
					foreach ( (array) glob( $dir . '/combo-*.css' ) as $old ) {
						if ( $old !== $f && filemtime( $old ) < time() - 30 * DAY_IN_SECONDS ) {
							@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
						}
					}
				}
				return $url . '/' . basename( $name );
			}
		);
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() || is_embed() || is_customize_preview() ) {
		return;
	}
	ob_start( 'db_css_combine_page' );
}, -998 );

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() || is_embed() || is_customize_preview() ) {
		return;
	}
	$consent = function_exists( 'db_consent_active' ) && db_consent_active();
	$delay   = db_delay_enabled() && ! isset( $_GET['db_nodelay'] ); // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $delay && ! $consent ) {
		return;
	}
	// With delay off but cookie consent on, the scripts are still held back, then start at once (or on "Accept all").
	ob_start( function ( $html ) use ( $delay ) {
		return db_delay_rewrite( $html, db_delay_hosts(), ! $delay );
	} );
}, -999 );

// Platform page switch.
add_action( 'db_platform_sections', function () {
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:980px"><table class="widefat striped"><tbody><tr><th scope="row" style="width:260px">Third-party scripts</th><td><label><input type="checkbox" name="delay" value="1"' . checked( db_delay_enabled(), true, false ) . '> Start Tag Manager, AdSense, HubSpot, AddToAny and Clarity after the page loads</label><br><small>They start on the visitor&rsquo;s first scroll, tap, key press or mouse move. Cloudflare Zaraz still records the Google Analytics page view on load. Add <code>?db_nodelay=1</code> to a URL to compare.</small></td></tr></tbody></table>';
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
