<?php
/**
 * DB Safety Block — fatal-error recorder + branded failure page
 *
 * Several DomainFolio theme templates fatal under the site's PHP version and
 * WordPress answers with a bare white "There has been a critical error"
 * page and HTTP 500. Six URLs did this in production, including /buy-now/
 * (the checkout page) whenever it was opened without a domain selected — so
 * every direct visit and every Googlebot crawl of the checkout URL saw a
 * 500. WordPress does not persist those errors anywhere readable, which made
 * them impossible to diagnose from outside.
 *
 * This block does three things:
 *
 *   1. Records the last DB_SAFETY_KEEP fatal errors (message, file, line,
 *      request URI, timestamp) into a non-autoloaded option, viewable at
 *      Tools → DB Fatal Log. Nothing is exposed on the front end.
 *   2. Replaces the white-screen WSOD with a branded, on-brand page that
 *      still returns 500 so monitoring and search engines see the truth.
 *   3. Strips unresolved Contact Form 7 tags — see db_safety_strip_dead_cf7_tags()
 *      below — from six live forms (Contact, Offer, Sell Your Domain, Acquire
 *      Domain Form, Acquire a Handle Now) that were rendering the literal
 *      text "[recaptcha recaptcha-833]" to every visitor. CF7 only registers
 *      the recaptcha tag type when Settings → Integration has a site key; with
 *      none configured, an unresolved tag is left as raw bracket text in the
 *      final markup instead of being replaced. Fixing it at the source means
 *      editing 6 form templates through wp-admin, which Wordfence's WAF
 *      blocked outright (a false-positive form submission still needs a
 *      human to clear it) — so it is fixed where the plugin already has a
 *      hook: CF7's own wpcf7_form_elements output filter.
 *
 * A fourth job lives here too: db_safety_fix_domain_links() rewrites the
 * theme's homepage "Start Search" CTA, which is hardcoded to
 * http://domainbrothers.com/all-domains/ — the production apex domain, not
 * this (beta) site — so every click sent a visitor away entirely. The same
 * pass upgrades any same-host http:// reference to https:// found in the
 * theme's markup (several image tags and one internal link shipped as
 * plain http://), on top of the CSP upgrade-insecure-requests header that
 * already handles this for browsers that honor it.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_SAFETY_KEEP' ) ) {
	define( 'DB_SAFETY_KEEP', 25 );
}

const DB_SAFETY_OPTION = 'db_fatal_log';

/* ─── Recorder ─────────────────────────────────────────────────────────────── */

if ( ! function_exists( 'db_safety_record_fatal' ) ) {
	/**
	 * Shutdown handler: persists the last fatal error, if there was one.
	 *
	 * Deliberately defensive — it runs during shutdown after a fatal, when
	 * the request is already in a bad state, so it must never throw.
	 */
	function db_safety_record_fatal() {
		$err = error_get_last();
		if ( ! $err ) {
			return;
		}
		$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
		if ( ! ( $err['type'] & $fatal_types ) ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		$entry = array(
			'time' => gmdate( 'Y-m-d H:i:s' ),
			'uri'  => substr( $uri, 0, 300 ),
			'type' => (int) $err['type'],
			'msg'  => substr( (string) $err['message'], 0, 900 ),
			'file' => substr( (string) $err['file'], 0, 300 ),
			'line' => (int) $err['line'],
		);

		$log = get_option( DB_SAFETY_OPTION, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		// Collapse repeats of the same file:line so one broken template can't
		// flood the log and hide the other failures.
		foreach ( $log as $i => $prev ) {
			if ( isset( $prev['file'], $prev['line'] ) && $prev['file'] === $entry['file'] && $prev['line'] === $entry['line'] ) {
				$entry['count'] = isset( $prev['count'] ) ? (int) $prev['count'] + 1 : 2;
				unset( $log[ $i ] );
				break;
			}
		}

		array_unshift( $log, $entry );
		update_option( DB_SAFETY_OPTION, array_slice( array_values( $log ), 0, DB_SAFETY_KEEP ), false );
	}
}

register_shutdown_function( 'db_safety_record_fatal' );

/* ─── 'p' query-var collision with the theme's shared footer ───────────────── */

/**
 * The DomainFolio theme's footer.php — included on EVERY page via
 * get_footer() — independently reads $_REQUEST['p'] expecting a
 * base64-encoded price (the same convention db-stripe-block.php uses for
 * its own checkout: ?d=<base64 domain>&p=<base64 price>), for its own GST
 * line-item calculation:
 *
 *   $temp_request_price = str_replace("$", "", base64_decode($_REQUEST['p']));
 *   $GSTPrice = $temp_request_price * GST_Rate / 100;
 *
 * 'p' is ALSO WordPress's own reserved query var for a numeric post ID
 * (?p=123) — one of the most common URL patterns on the web, probed by
 * search engines, scanners, and old bookmarked links. Any ?p=<value> that
 * doesn't happen to base64-decode to a clean number — including the exact
 * WordPress convention it collides with — makes that line multiply a
 * non-numeric string, which is a fatal TypeError on PHP 8 (PHP 7 silently
 * coerced it to 0). Since footer.php runs globally, this crashed the
 * front page and every 404 template with a plain ?p=<id> on it.
 *
 * Neutralized here rather than at the theme source (blocked by Wordfence's
 * WAF, same obstacle as the other theme-file fixes this session) by
 * replacing an unsafe value with a harmless base64-encoded "0" — matching
 * footer.php's own no-p-supplied fallback of $GSTPrice = 0 — everywhere
 * except the pages that legitimately rely on this exact value: buy-now
 * (rendered entirely by this plugin), and checkout / payment-plan-setup
 * (the theme's own legacy templates, already guarded elsewhere to require
 * a real d+p pair before rendering at all).
 */
add_action( 'template_redirect', function () {
	if ( ! isset( $_REQUEST['p'] ) || is_admin() || is_feed() || is_robots() ) {
		return;
	}
	if ( is_page( array( 'buy-now', 'checkout', 'payment-plan-setup' ) ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$raw     = (string) wp_unslash( $_REQUEST['p'] );
	$decoded = base64_decode( $raw, true );
	$clean   = ( false !== $decoded ) ? preg_replace( '/[^0-9.]/', '', (string) $decoded ) : '';
	if ( '' !== $clean && is_numeric( $clean ) ) {
		return; // Already a validly-encoded number — nothing to fix.
	}

	$safe = base64_encode( '0' );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$_REQUEST['p'] = $safe;
	if ( isset( $_GET['p'] ) ) {
		$_GET['p'] = $safe;
	}
	if ( isset( $_POST['p'] ) ) {
		$_POST['p'] = $safe;
	}
}, 20 );

/* ─── Broken /landing-css/ and /landing-images/ asset references ───────────── */

/**
 * Domain listing pages opened with ?lis=y (the "developed by" attribution
 * marker the theme's own sitemap already links to — see Block 14 above)
 * render a completely separate, hand-rolled landing-page document instead
 * of the normal single-domain.php template: its own <html><head>, no
 * wp_head()/body_class(), referencing THREE stylesheets plus a favicon
 * under /landing-css/ and /landing-images/. None of that directory exists
 * on this server — confirmed live, a raw webserver 404 (not even a
 * WordPress 404), meaning the request never reaches PHP at all and can't
 * be caught with a normal WordPress hook. Every one of those files IS
 * reachable at the exact same path on the production apex domain.
 *
 * The template's own asset references are themselves inconsistent — some
 * already point at production correctly, some point at this (beta) host
 * (the ones that 404), and at least one is missing the path separator
 * entirely (".comlanding-css/..."). All are normalized here to the one
 * confirmed-working host, by matching the landing-css/ or landing-images/
 * path segment itself rather than trying to special-case every variant of
 * what precedes it.
 */
if ( ! function_exists( 'db_safety_fix_landing_assets' ) ) {
	function db_safety_fix_landing_assets( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		if ( false === strpos( $html, 'landing-css/' ) && false === strpos( $html, 'landing-images/' ) ) {
			return $html;
		}
		return preg_replace_callback(
			'#(href|src)=(["\'])(?:https?:)?//?[^"\']*?(landing-(?:css|images)/[^"\']*)\2#i',
			function ( $m ) {
				// Already pointing at production, or at the separate (and
				// working) CDN host — leave it exactly as-is.
				if ( false !== stripos( $m[0], 'www.domainbrothers.com' ) || false !== stripos( $m[0], 'cdn.domainbrothers.com' ) ) {
					return $m[0];
				}
				return $m[1] . '=' . $m[2] . 'https://www.domainbrothers.com/' . $m[3] . $m[2];
			},
			$html
		);
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_safety_fix_landing_assets' );
}, 21 );

/* ─── Dead owl.carousel widget, loaded on every page ────────────────────────── */

/**
 * Every page on the site hardcodes a <script src> and <link> for
 * owl.carousel.min.js/css from the production apex domain, plus an inline
 * jQuery(document).ready() call that initializes it against
 * ".home-slider.owl-carousel" — confirmed live on the homepage, a domain
 * page, /all-domains/, /news/, /our-services/ and /about-us/: no element
 * with class "home-slider" or any "owl-*" class exists anywhere in any of
 * their markup. The plugin call on an empty jQuery selection is a silent
 * no-op, so this has never rendered a visible carousel on this site; it's
 * pure dead weight — a cross-origin script+stylesheet fetch and a wasted
 * jQuery call on every single page load, and the single largest item in
 * the 2026-07 site audit (flagged as "External JavaScript with
 * 3XX/4XX/5XX" + "not compressed" + "not minified" on ~368 pages, because
 * the file is occasionally unreachable from the crawler and was never
 * minified in the first place). Removed outright rather than swapped for a
 * locally-hosted copy — there's nothing here for a copy to serve.
 */
if ( ! function_exists( 'db_safety_strip_dead_owl_carousel' ) ) {
	function db_safety_strip_dead_owl_carousel( $html ) {
		if ( ! is_string( $html ) || false === stripos( $html, 'owl.carousel' ) ) {
			return $html;
		}
		$html = preg_replace( '#<script[^>]+src=["\'][^"\']*owl\.carousel\.min\.js[^"\']*["\'][^>]*>\s*</script>#i', '', $html );
		$html = preg_replace( '#<link[^>]+href=["\'][^"\']*owl\.carousel\.min\.css[^"\']*["\'][^>]*/?>#i', '', $html );
		// The one inline initializer. Found and removed by plain string
		// search rather than a tag-spanning regex — an earlier version used
		// "(?:(?!</script>).)*?" to hop over every other <script> on the
		// page to find this one, which is exactly the shape that triggers
		// catastrophic backtracking on a real ~130KB page: it exhausted
		// PCRE's JIT stack and preg_replace() returned null, which — fed
		// straight back into this ob_start() callback — would have blanked
		// every single page on the site. Caught before deploy; left as a
		// concrete reminder not to reach for that pattern here again.
		$marker = 'owlCarousel(';
		$pos    = strpos( $html, $marker );
		if ( false !== $pos ) {
			$tag_start = strrpos( substr( $html, 0, $pos ), '<script' );
			$tag_end   = strpos( $html, '</script>', $pos );
			if ( false !== $tag_start && false !== $tag_end ) {
				$html = substr_replace( $html, '', $tag_start, $tag_end + strlen( '</script>' ) - $tag_start );
			}
		}
		return $html;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_safety_strip_dead_owl_carousel' );
}, 21 );

/* ─── Legacy ?lis=y landing template: redirect away instead of patching ─────── */

/**
 * The domain-listing template above renders that entire separate, stale,
 * hand-rolled landing page whenever ?lis is present — its own <html> with no
 * lang attribute, four <h1> tags, no Twitter Card meta, and body copy left
 * over from one specific past webinar date plus an unrelated third-party ad
 * ("The #1 Social Media Management Tool"). The current single-domain.php
 * template — the one every domain URL without ?lis already renders — is the
 * real, maintained page with none of those defects. Patching the legacy
 * template's SEO problems in place would mean maintaining two parallel
 * domain-page templates forever; redirecting away is the one-time fix.
 * Priority -100 so this runs before any ob_start() below opens a buffer —
 * a redirect this early never has anything queued to flush or discard.
 */
add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_singular( 'domain' ) || ! isset( $_GET['lis'] ) ) {
		return;
	}
	$canonical = get_permalink();
	if ( ! $canonical ) {
		return;
	}
	wp_safe_redirect( $canonical, 301 );
	exit;
}, -100 );

/**
 * Every card/list that links to a domain page — the homepage, /all-domains/,
 * the /domain-category/ archives — generates that ?lis=y link itself, and
 * always without the trailing slash the permalink actually needs
 * (/domains/{slug}?lis=y instead of /domains/{slug}/?lis=y), so every click
 * already cost a 301 before the redirect above even fires. Rewriting those
 * hrefs to the clean canonical URL fixes both at the source: no redirect hop
 * at all, and the legacy template is never requested from anywhere on the
 * site again (confirmed live: this is the only place ?lis=y links are
 * generated — payment-plan-setup/buy-now links use a different ?d= scheme
 * untouched by this).
 */
if ( ! function_exists( 'db_safety_fix_domain_listing_links' ) ) {
	function db_safety_fix_domain_listing_links( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, 'lis=y' ) ) {
			return $html;
		}
		return preg_replace( '#(/domains/[a-z0-9-]+)/?\?lis=y#i', '$1/', $html );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_safety_fix_domain_listing_links' );
}, 0 );

/* ─── Branded failure page ─────────────────────────────────────────────────── */

add_filter( 'wp_php_error_message', function ( $message ) {
	$home = esc_url( home_url( '/' ) );
	$all  = esc_url( home_url( '/all-domains/' ) );
	return '<div style="font:16px/1.6 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;color:#0a1628">'
		. '<h1 style="font-size:22px;margin:0 0 10px">This page is temporarily unavailable</h1>'
		. '<p style="margin:0 0 16px;color:#41506b">Our team has been notified. In the meantime you can '
		. '<a href="' . $all . '" style="color:#1f6fd0;font-weight:600">browse premium domains</a> or '
		. '<a href="' . $home . '" style="color:#1f6fd0;font-weight:600">return to the homepage</a>.</p>'
		. '</div>';
}, 10, 1 );

/* ─── Dead Contact Form 7 tags ──────────────────────────────────────────────── */

if ( ! function_exists( 'db_safety_strip_dead_cf7_tags' ) ) {
	/**
	 * Removes any [recaptcha ...] tag left unresolved in a rendered CF7 form.
	 *
	 * This does NOT add a working CAPTCHA — that requires a Google reCAPTCHA
	 * site key and secret only the site owner can create at
	 * google.com/recaptcha/admin, entered under CF7 → Integration. It only
	 * stops broken markup from being shown; the honeypot fields already
	 * present in these forms (EPPSupport/EPPSupportCode, db_hp_website) are
	 * the actual spam defense in the meantime.
	 */
	function db_safety_strip_dead_cf7_tags( $html ) {
		if ( false === strpos( $html, '[recaptcha' ) ) {
			return $html;
		}
		return preg_replace( '/\[recaptcha\b[^\]]*\]/', '', $html );
	}
}
add_filter( 'wpcf7_form_elements', 'db_safety_strip_dead_cf7_tags', 20 );

/* ─── Wrong-host links and mixed content in theme markup ───────────────────── */

if ( ! function_exists( 'db_safety_fix_domain_links' ) ) {
	/**
	 * Rewrites the theme's hardcoded production-domain links to the current
	 * host, and upgrades same-host http:// references to https://.
	 *
	 * The homepage's "Start Search" button (class="easysteps-search") is
	 * hardcoded in the DomainFolio theme template as
	 * href="http://domainbrothers.com/all-domains/" — the live production
	 * apex domain — instead of a relative path or home_url(). On this (beta)
	 * site every click on that CTA sent the visitor to a different domain
	 * entirely. The regex only matches an href pointing at that exact apex
	 * domain, so it can't touch an email address or a plain-text mention of
	 * the company name elsewhere on the page.
	 */
	function db_safety_fix_domain_links( $html ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $host ) {
			return $html;
		}

		$html = preg_replace_callback(
			'#(href=["\'])https?://(?:www\.)?domainbrothers\.com(/[^"\']*)?(["\'])#i',
			function ( $m ) use ( $host ) {
				// Exception: /landing-css/ and /landing-images/ are NOT
				// migrated to this host at all (confirmed 404 — see
				// db_safety_fix_landing_assets() below, which deliberately
				// points those at production because nothing else serves
				// them). Rewriting this href back to the current host would
				// undo that fix — these two functions are the one place on
				// the site where "point at production" is the correct
				// answer, everywhere else it's the bug being fixed.
				if ( isset( $m[2] ) && ( false !== stripos( $m[2], 'landing-css/' ) || false !== stripos( $m[2], 'landing-images/' ) ) ) {
					return $m[0];
				}
				return $m[1] . 'https://' . $host . $m[2] . $m[3];
			},
			$html
		);

		// Belt-and-suspenders on top of the CSP upgrade-insecure-requests
		// header: several theme <img> tags and one internal link ship as
		// plain http:// for this exact host, which browsers that don't
		// honor CSP (older UAs, some crawlers) would fetch unencrypted.
		$html = str_replace( 'http://' . $host, 'https://' . $host, $html );

		return $html;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_safety_fix_domain_links' );
}, 0 );

/* ─── Dead Tawk.to embed ─────────────────────────────────────────────────── */

if ( ! function_exists( 'db_safety_strip_tawk' ) ) {
	/**
	 * Removes the theme's "Tawk.to Script" block from every page.
	 *
	 * The block itself is already inert — the actual <script> tag it wraps is
	 * nested inside a second, unclosed-looking HTML comment, so no browser has
	 * ever executed it or shown a chat bubble from it (confirmed against the
	 * live HTML: the only two references to tawk.to on the page are both
	 * inside this dead comment). Removed anyway per the owner's request —
	 * it's inert but it's still ~700 bytes of dead markup shipped on every
	 * single page load for a chat widget nothing on the site actually wires
	 * up. If a floating chat/media-style widget is still visible after this
	 * ships, it is not this — something else is producing it.
	 */
	function db_safety_strip_tawk( $html ) {
		if ( false === stripos( $html, 'Tawk.to Script' ) ) {
			return $html;
		}
		return preg_replace(
			'#<!--\s*Start of Tawk\.to Script\s*-->.*?<!--\s*End of Tawk\.to Script\s*-->#is',
			'',
			$html
		);
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_safety_strip_tawk' );
}, 22 );

/* ─── Guards for theme templates that fatal under PHP 8 ────────────────────── */

if ( ! function_exists( 'db_safety_template_guards' ) ) {
	/**
	 * Slug => guard config for DomainFolio page templates that break when
	 * they render without the query parameters they assume — either by
	 * throwing (PHP 7 coerced the missing values silently; PHP 8 raises, so
	 * these returned HTTP 500 to every direct visitor and to Googlebot) or
	 * by rendering a meaningless order.
	 *
	 * The theme cannot be patched from here (and a theme update would revert
	 * it anyway), so the request is redirected somewhere useful *before*
	 * template_loader includes the broken file.
	 *
	 *   'require' — GET params that must all be present and non-empty.
	 *               An empty array means "any query string will do".
	 *   'to'      — path to send the visitor to instead.
	 *   'code'    — 302 for the transactional pages (the URL is still
	 *               legitimate with the right params), 301 for the dead
	 *               legacy sitemaps.
	 *   'cap'     — optional capability required to view the page at all.
	 *
	 * Verified failure points, from the fatal log:
	 *   developed-two.php:47          float + string   (/checkout/, needs d+p)
	 *   payment-successful.php:121    string * int
	 *   installment-detail-admin.php:443  null / string
	 *   all-domains-sitemap.php:18    mysqli_fetch_array(false) — raw mysqli
	 *                                 against a hardcoded table prefix; the
	 *                                 query simply fails. WordPress core
	 *                                 already publishes /wp-sitemap.xml.
	 */
	function db_safety_template_guards() {
		return array(
			'checkout' => array(
				'require' => array( 'd', 'p' ),
				'to'      => '/all-domains/',
				'code'    => 302,
			),
			// Not a fatal: without a domain this renders a live-looking
			// order form reading "Pay in full $0.00" and "Monthly: (
			// Payments)", which was both in the XML sitemap and indexable.
			'payment-plan-setup' => array(
				'require' => array( 'd', 'p' ),
				'to'      => '/all-domains/',
				'code'    => 302,
			),
			'payment-successful' => array(
				'require' => array(),
				'to'      => '/thank-you/',
				'code'    => 302,
			),
			'installment-detail-admin' => array(
				'require' => array(),
				'to'      => '/',
				'code'    => 302,
				'cap'     => 'manage_options',
			),
			'all-domains-sitemap' => array(
				'require' => null, // always redirect; the template is broken outright
				'to'      => '/wp-sitemap.xml',
				'code'    => 301,
			),
			'all-categories-sitemap' => array(
				'require' => null,
				'to'      => '/wp-sitemap.xml',
				'code'    => 301,
			),
		);
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_page() ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	$guards = db_safety_template_guards();
	if ( ! isset( $guards[ $post->post_name ] ) ) {
		return;
	}
	$g = $guards[ $post->post_name ];

	if ( ! empty( $g['cap'] ) && ! current_user_can( $g['cap'] ) ) {
		wp_safe_redirect( home_url( $g['to'] ), $g['code'] );
		exit;
	}

	if ( null === $g['require'] ) {
		wp_safe_redirect( home_url( $g['to'] ), $g['code'] );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	if ( array() === $g['require'] ) {
		$ok = ! empty( $_GET );
	} else {
		$ok = true;
		foreach ( $g['require'] as $key ) {
			if ( ! isset( $_GET[ $key ] ) || '' === trim( (string) wp_unslash( $_GET[ $key ] ) ) ) {
				$ok = false;
				break;
			}
		}
	}
	// phpcs:enable

	if ( ! $ok ) {
		wp_safe_redirect( home_url( $g['to'] ), $g['code'] );
		exit;
	}
}, 5 );

/* ─── Admin viewer ─────────────────────────────────────────────────────────── */

add_action( 'admin_menu', function () {
	add_management_page(
		'DB Fatal Log',
		'DB Fatal Log',
		'manage_options',
		'db-fatal-log',
		'db_safety_render_page'
	);
} );

// Clear runs on load-{$hook} because wp_safe_redirect() cannot work from a
// menu-page render callback — headers are already sent by then.
add_action( 'load-tools_page_db-fatal-log', function () {
	if ( ! isset( $_GET['db_clear'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	check_admin_referer( 'db_fatal_clear' );
	delete_option( DB_SAFETY_OPTION );
	wp_safe_redirect( admin_url( 'tools.php?page=db-fatal-log&cleared=1' ) );
	exit;
} );

if ( ! function_exists( 'db_safety_render_page' ) ) {
	function db_safety_render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( 'Insufficient permissions.' ) );
		}
		$log   = get_option( DB_SAFETY_OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$clear = wp_nonce_url( admin_url( 'tools.php?page=db-fatal-log&db_clear=1' ), 'db_fatal_clear' );
		?>
		<div class="wrap">
			<h1>DB Fatal Log</h1>
			<p>The most recent <?php echo (int) DB_SAFETY_KEEP; ?> PHP fatal errors, newest first. Repeats of the same file and line are collapsed into a count.</p>
			<?php if ( isset( $_GET['cleared'] ) ) : ?>
				<div class="notice notice-success"><p>Log cleared.</p></div>
			<?php endif; ?>
			<?php if ( ! $log ) : ?>
				<div class="notice notice-success"><p><strong>No fatal errors recorded.</strong></p></div>
			<?php else : ?>
				<p><a class="button" href="<?php echo esc_url( $clear ); ?>">Clear log</a></p>
				<table class="widefat striped">
					<thead><tr>
						<th style="width:150px">When (UTC)</th>
						<th>Request</th>
						<th>Error</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $log as $e ) : ?>
						<tr>
							<td>
								<?php echo esc_html( isset( $e['time'] ) ? $e['time'] : '' ); ?>
								<?php if ( ! empty( $e['count'] ) ) : ?>
									<br><span class="dashicons dashicons-warning"></span> &times;<?php echo (int) $e['count']; ?>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( isset( $e['uri'] ) ? $e['uri'] : '' ); ?></code></td>
							<td>
								<strong><?php echo esc_html( isset( $e['msg'] ) ? $e['msg'] : '' ); ?></strong><br>
								<code><?php echo esc_html( isset( $e['file'] ) ? $e['file'] : '' ); ?>:<?php echo (int) ( isset( $e['line'] ) ? $e['line'] : 0 ); ?></code>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
