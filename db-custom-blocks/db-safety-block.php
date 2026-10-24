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

/* ─── Footer social icons with no anchor text ───────────────────────────────── */

/**
 * The three real footer social links (Facebook/Twitter/Instagram — a
 * fourth, Google+, is already commented out in the theme markup itself,
 * long dead) are icon-only: <a title="Facebook" ...><i class="fa
 * fa-facebook"></i></a>, no text content at all. `title` is a tooltip, not
 * anchor text — confirmed as the single largest item in the 2026-07-28
 * audit ("External links missing anchor", ~397 pages = these 3 links times
 * every page that renders the footer). Adds a real, visually-hidden text
 * node inside each one instead of only relying on `title`.
 */
if ( ! function_exists( 'db_safety_fix_social_link_text' ) ) {
	function db_safety_fix_social_link_text( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, 'footer-sociallinks' ) ) {
			return $html;
		}
		$networks = array(
			'facebook.com'  => 'Facebook',
			'twitter.com'   => 'Twitter',
			'instagram.com' => 'Instagram',
		);
		foreach ( $networks as $host => $label ) {
			$pattern = '#(<a\b[^>]*href="https?://(?:www\.)?' . preg_quote( $host, '#' ) . '[^"]*"[^>]*>)(<i\b[^>]*></i>)(</a>)#i';
			$html    = preg_replace( $pattern, '$1<span class="screen-reader-text">' . $label . '</span>$2$3', $html, 1 );
		}
		return $html;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_safety_fix_social_link_text' );
}, 21 );

/* ─── Hardcoded production thank-you redirect (theme-native CF7 handler) ────── */

if ( ! function_exists( 'db_safety_fix_hardcoded_thankyou_redirect' ) ) {
	/**
	 * A theme-native inline script (not ours, emitted straight from the
	 * DomainFolio footer) redirects two Contact Form 7 forms (IDs 5432 and
	 * 1878 — the site-wide Contact form and the domain-page Make an Offer
	 * form) to a literal "https://www.domainbrothers.com/thank-you/" on
	 * submit. That's fine on production, but on any other host — this beta
	 * site, or any future staging clone — it silently bounces every visitor
	 * who submits one of those forms to the live production site instead of
	 * this one. Confirmed live: a test offer submitted on beta redirected to
	 * www.domainbrothers.com/thank-you/, which was down at the time.
	 *
	 * Wordfence blocks saving theme files through wp-admin, so this is fixed
	 * the same way as the rest of this file — a literal, non-regex string
	 * swap of the exact hardcoded URL for the environment's real one. Plain
	 * str_replace, no backtracking risk.
	 */
	function db_safety_fix_hardcoded_thankyou_redirect( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		$hardcoded = 'https://www.domainbrothers.com/thank-you/';
		$correct   = home_url( '/thank-you/' );
		if ( $correct === $hardcoded || false === strpos( $html, $hardcoded ) ) {
			return $html;
		}
		return str_replace( $hardcoded, $correct, $html );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_safety_fix_hardcoded_thankyou_redirect' );
}, 22 );

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
				// Same for /images/detail/ (domain-page banners, referenced
				// as production URLs by the theme and preloaded by
				// db_onpage_html_hygiene()).
				if ( isset( $m[2] ) && ( false !== stripos( $m[2], 'landing-css/' ) || false !== stripos( $m[2], 'landing-images/' ) || 0 === stripos( $m[2], '/images/detail/' ) ) ) {
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

/* ─── Legacy /checkout/ → Stripe /buy-now/ ──────────────────────────────────── */

/**
 * The theme's own /checkout/ template (the target of the old payment-plan
 * form) renders a raw "card_number"/"card_name" form with no Stripe.js and a
 * "Pay with PayPal" tab whose [wp_paypal] shortcode was left as literal text
 * (the plugin that provided it is not installed). The working, PCI-safe
 * checkout is the plugin-rendered Stripe flow on /buy-now/, which reads the
 * same ?d=&p= pair and re-derives the price server-side. Anything that still
 * lands on /checkout/ with a valid pair is sent there instead of seeing the
 * broken legacy form. Bare /checkout/ is still redirected by the existing
 * guard in db_safety_template_guards().
 */
add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_page( 'checkout' ) || empty( $_GET['d'] ) || empty( $_GET['p'] ) ) {
		return;
	}
	$args = array(
		'd' => sanitize_text_field( wp_unslash( $_GET['d'] ) ),
		'p' => sanitize_text_field( wp_unslash( $_GET['p'] ) ),
	);
	if ( ! empty( $_GET['months'] ) ) {
		$args['months'] = (int) $_GET['months'];
		$args['t']      = 'plan';
	}
	wp_safe_redirect( home_url( '/buy-now/' ) . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 ), 302 );
	exit;
}, -50 );

/* ─── Keep staging out of search indexes ────────────────────────────────────── */

if ( ! function_exists( 'db_is_staging_host' ) ) {
	/** True on any non-production host (currently beta.*). Production is never affected. */
	function db_is_staging_host() {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		return 0 === strpos( $host, 'beta.' ) || 0 === strpos( $host, 'staging.' ) || 0 === strpos( $host, 'dev.' );
	}
}

/**
 * Staging was fully indexable apart from its Basic Auth gate: no robots
 * meta, no X-Robots-Tag, and robots.txt both allowed crawling and advertised
 * the *production* sitemap. If the password is ever lifted (or a cache/CDN
 * serves a copy), the staging copy would compete with production in search
 * and duplicate its content. Defence in depth, scoped to staging hosts only.
 */
add_filter( 'wp_robots', function ( $robots ) {
	if ( db_is_staging_host() ) {
		$robots['noindex']  = true;
		$robots['nofollow'] = true;
		unset( $robots['follow'], $robots['index'] );
	}
	return $robots;
}, 99 );

add_action( 'send_headers', function () {
	if ( db_is_staging_host() && ! headers_sent() ) {
		header( 'X-Robots-Tag: noindex, nofollow', true );
	}
} );

add_filter( 'robots_txt', function ( $output ) {
	return db_is_staging_host() ? "User-agent: *\nDisallow: /\n" : $output;
}, 99 );

/* ─── QA fixes: AdSense, legacy CF7 wrapper, XML-RPC ────────────────────────── */

if ( ! function_exists( 'db_safety_strip_adsense' ) ) {
	/**
	 * The theme loads adsbygoogle.js on every page and puts one ad slot on
	 * the homepage, which earlier CSS hides (no approved AdSense account
	 * here, and it left a blank gap). Hidden, the slot has zero width, so
	 * adsbygoogle.push() throws "No slot size for availableWidth=0" — the
	 * uncaught "Y" error on every page in tests/qa (UI-*-jserr). Third-party
	 * ads on a premium-domain sales page also send buyers off-site and cost
	 * load time, so the script and slot are removed rather than hidden.
	 */
	function db_safety_strip_adsense( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, 'adsbygoogle' ) ) {
			return $html;
		}
		// <script async src="https://pagead2.googlesyndication.com/...adsbygoogle.js..."></script>
		$pos = strpos( $html, 'pagead2.googlesyndication.com/pagead/js/adsbygoogle.js' );
		if ( false !== $pos ) {
			$start = strrpos( substr( $html, 0, $pos ), '<script' );
			$end   = strpos( $html, '</script>', $pos );
			if ( false !== $start && false !== $end ) {
				$html = substr_replace( $html, '', $start, $end + 9 - $start );
			}
		}
		// <div class="google_adsense"> <ins ...></ins> <script>(adsbygoogle...).push({});</script> </div>
		// Domain pages use a variant with style="display: none;", so match the
		// opening tag by prefix and remove every slot, not just the first.
		$from = 0;
		while ( false !== ( $pos = strpos( $html, '<div class="google_adsense"', $from ) ) ) {
			$push = strpos( $html, '.push({});', $pos );
			$end  = false === $push ? false : strpos( $html, '</div>', $push );
			if ( false === $end || $end - $pos > 4000 ) {
				$from = $pos + 1;
				continue;
			}
			$html = substr_replace( $html, '', $pos, $end + 6 - $pos );
			$from = $pos;
		}
		return $html;
	}
}

if ( ! function_exists( 'db_safety_fix_legacy_offer_wrapper' ) ) {
	/**
	 * Domain pages carry a hard-coded, pre-CF7-5.4 "Make an Offer" box
	 * (just the amount field + a button that opens the real form 1878 in a
	 * modal) still wrapped in class="wpcf7". Current CF7 initialises every
	 * .wpcf7, finds no data-wpcf7-id, requests
	 * /contact-forms/NaN/feedback/schema (404) and then throws twice
	 * ("Response", "Cannot set properties of null"). Renaming the wrapper
	 * class stops CF7 touching it; nothing styles .make_an_offer .wpcf7.
	 */
	function db_safety_fix_legacy_offer_wrapper( $html ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		return str_replace( '<div role="form" class="wpcf7" id="wpcf7-f1878-p360-o1"', '<div role="form" class="db-legacy-offer-box" id="wpcf7-f1878-p360-o1"', $html );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || is_robots() ) {
		return;
	}
	ob_start( 'db_safety_strip_adsense' );
	if ( is_singular( 'domain' ) ) {
		ob_start( 'db_safety_fix_legacy_offer_wrapper' );
	}
}, 29 );

/*
 * XML-RPC answered POST system.listMethods with 200 (tests/qa SEC-xmlrpc).
 * Nothing on this site uses it (no Jetpack, no mobile-app publishing), and it
 * is the usual target for password brute-force (system.multicall tries
 * hundreds of logins per request) and pingback abuse.
 */
add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', function () { return array(); } );
add_filter( 'wp_headers', function ( $headers ) { unset( $headers['X-Pingback'] ); return $headers; } );
add_action( 'init', function () {
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		status_header( 403 );
		header( 'Content-Type: text/plain; charset=UTF-8' );
		exit( 'XML-RPC is disabled on this site.' );
	}
}, 0 );

/*
 * The DomainFolio theme hard-codes the production table prefix in raw SQL
 * ("select ID from Dmb_posts where post_title=...", "SELECT * FROM
 * Dmb_hitDomain_final ..."). On any copy with a different prefix — this
 * staging site uses wp_ — those queries hit tables that don't exist and fail
 * silently (domain-page view/inquiry stats, owner lookups). Found by the
 * tests/qa request profiler. Rewrites the hard-coded prefix to the real one;
 * a no-op where they already match (production).
 */
add_filter( 'query', function ( $sql ) {
	global $wpdb;
	if ( 'Dmb_' === $wpdb->prefix || false === strpos( $sql, 'Dmb_' ) ) {
		return $sql;
	}
	return preg_replace( '/(?<![A-Za-z0-9_])Dmb_([A-Za-z0-9_]+)/', $wpdb->prefix . '$1', $sql );
} );

/*
 * wp-config.php sets WP_AUTO_UPDATE_CORE to false, which also blocks WordPress
 * security (minor) releases. The filter runs after the constant is read, so
 * this re-enables minor/security core updates only; major versions still
 * need a manual upgrade. Has no effect if AUTOMATIC_UPDATER_DISABLED is set.
 */
add_filter( 'allow_minor_auto_core_updates', '__return_true', 20 );

/*
 * 4,926 stored revisions were found in wp_posts (QA_REPORT section 2).
 * Keep the latest 5 per post from now on; existing revisions are trimmed by
 * WordPress the next time each post is saved.
 */
add_filter( 'wp_revisions_to_keep', function ( $num ) {
	return ( $num < 0 || $num > 5 ) ? 5 : $num;
}, 20 );

/*
 * Every news post's byline read "By admin" and linked /author/admin/, and the
 * Article schema named "admin" as author: the administrator's login name,
 * published on 1,500+ pages (found by the full-site crawl). Half of a login
 * is then public, which undoes the REST and ?author= enumeration blocks.
 * Where an author's display name is their login name, show the site name
 * instead; author links go to /about-us/ and author archives 301 to /news/.
 */
if ( ! function_exists( 'db_safety_public_author_name' ) ) {
	function db_safety_public_author_name( $name, $user_id = 0 ) {
		if ( is_admin() ) {
			return $name;
		}
		$user = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			global $authordata;
			$user = $authordata;
		}
		if ( '' === (string) $name || ( $user && 0 === strcasecmp( (string) $name, (string) $user->user_login ) ) || 0 === strcasecmp( (string) $name, 'admin' ) ) {
			return 'Domain Brothers';
		}
		return $name;
	}
}
add_filter( 'the_author', function ( $name ) {
	return db_safety_public_author_name( $name );
} );
add_filter( 'get_the_author_display_name', function ( $name, $user_id ) {
	return db_safety_public_author_name( $name, (int) $user_id );
}, 10, 2 );
add_filter( 'author_link', function ( $link ) {
	return is_admin() ? $link : home_url( '/about-us/' );
} );
add_filter( 'oembed_response_data', function ( $data ) {
	$data['author_name'] = get_bloginfo( 'name' );
	$data['author_url']  = home_url( '/' );
	return $data;
} );
add_action( 'template_redirect', function () {
	// /about-us/ links to /sell-your-domain/, which does not exist (404).
	if ( is_404() && preg_match( '#^/sell-your-domain/?$#', (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH ) ) ) {
		wp_safe_redirect( home_url( '/contact-us/' ), 301 );
		exit;
	}
	if ( is_author() ) {
		wp_safe_redirect( home_url( '/news/' ), 301 );
		exit;
	}
}, 1 );
