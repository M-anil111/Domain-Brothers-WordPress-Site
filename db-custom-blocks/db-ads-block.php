<?php
/**
 * Google AdSense banner slots.
 *
 * Three placements on a domain page: after the ways to buy ("after_offers"),
 * after the domain's details and pricing ("after_details") and after "How
 * buying works" ("after_steps"). Set the publisher id and the three ad unit
 * ids under Domain Brothers > Ads. Until a publisher id is saved nothing is
 * shown to visitors; on the staging site an empty, labelled slot shows where
 * each banner will go. The AdSense script loads once per page, only when a
 * slot is on the page, and only on public domain pages (never the wishlist
 * or account pages, checkout or admin).
 *
 * Reminder: ads that personalise need a consent banner for visitors in the
 * EEA, UK and Switzerland (Google's rules); set that up before enabling ads
 * on production.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_ads_slots' ) ) {
	function db_ads_slots() {
		return array(
			'after_offers'  => 'After the three ways to buy',
			'after_details' => 'After details and pricing',
			'after_steps'   => 'After How buying works',
			'home_mid'      => 'Home page, after "Perfect for buyers and sellers" and How it works',
			'list_bottom'   => 'Domain lists and categories, below the domains',
			'content_end'   => 'Pages, news and services, after the content',
			'sold_page'     => 'Sold and not-for-sale domain pages, before the footer',
		);
	}
}

if ( ! defined( 'DB_ADSENSE_CLIENT' ) ) {
	// The site's AdSense publisher id (not a secret: it is printed in every page's source).
	define( 'DB_ADSENSE_CLIENT', 'ca-pub-1077085934387393' );
}

if ( ! function_exists( 'db_ads_client' ) ) {
	/** The publisher id: the one saved under Domain Brothers > Ads, else the built-in one. */
	function db_ads_client() {
		$o = get_option( 'db_ads', array() );
		$c = is_array( $o ) && ! empty( $o['client'] ) ? (string) $o['client'] : (string) DB_ADSENSE_CLIENT;
		return preg_match( '/^ca-pub-\d{10,20}$/', $c ) ? $c : '';
	}
}

if ( ! function_exists( 'db_ads_extra_lines' ) ) {
	/** Other ads.txt lines to publish: what is saved under Domain Brothers > Ads, else the one the live site already had. */
	function db_ads_extra_lines() {
		$o = get_option( 'db_ads', array() );
		return is_array( $o ) && isset( $o['extra'] ) ? (string) $o['extra'] : 'google.com, pub-7194359990997088, DIRECT, f08c47fec0942fa0';
	}
}

if ( ! function_exists( 'db_ads_settings' ) ) {
	function db_ads_settings() {
		$o = get_option( 'db_ads', array() );
		return is_array( $o ) ? $o : array();
	}
}

if ( ! function_exists( 'db_ad_html' ) ) {
	/** One banner slot (responsive display ad). Empty string when ads are not set up on a live site. */
	function db_ad_html( $key ) {
		if ( is_admin() || ! array_key_exists( $key, db_ads_slots() ) ) {
			return '';
		}
		$o      = db_ads_settings();
		$client = db_ads_client();
		$slot   = isset( $o[ $key ] ) ? (string) $o[ $key ] : '';
		$ready  = (bool) preg_match( '/^ca-pub-\d{10,20}$/', $client ) && (bool) preg_match( '/^\d{6,20}$/', $slot );
		$GLOBALS['db_ads_used'] = $ready ? true : ( $GLOBALS['db_ads_used'] ?? false );
		if ( ! $ready ) {
			if ( function_exists( 'db_wl_is_staging' ) && db_wl_is_staging() ) {
				return '<aside class="db-ad db-ad--empty" aria-label="Advertisement slot"><span>Advertisement slot: ' . esc_html( db_ads_slots()[ $key ] ) . '. Add your AdSense ids under Domain Brothers &gt; Ads.</span></aside>';
			}
			return '';
		}
		return '<aside class="db-ad" aria-label="Advertisement"><span class="db-ad-label">Advertisement</span><ins class="adsbygoogle" style="display:block" data-ad-client="' . esc_attr( $client ) . '" data-ad-slot="' . esc_attr( $slot ) . '" data-ad-format="horizontal" data-full-width-responsive="true"></ins><script>(adsbygoogle=window.adsbygoogle||[]).push({});</script></aside>';
	}
}

// The AdSense code goes in the <head> of every public page (this is also what Google checks to verify site ownership).
add_action( 'wp_head', function () {
	$client = db_ads_client();
	if ( '' === $client || ! db_ads_page_allowed() ) {
		return;
	}
	$GLOBALS['db_ads_head'] = true;
	echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . esc_attr( $client ) . '" crossorigin="anonymous"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources
}, 2 );

// Pages kept out of the head script that still carry a slot load it with the slot.
add_action( 'wp_footer', function () {
	if ( empty( $GLOBALS['db_ads_used'] ) || ! empty( $GLOBALS['db_ads_head'] ) ) {
		return;
	}
	echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . esc_attr( db_ads_client() ) . '" crossorigin="anonymous"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources
}, 90 );

// /ads.txt, which AdSense asks for: lists Google as the authorised seller. A real ads.txt file on the server wins.
add_action( 'init', function () {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	if ( '/ads.txt' !== $path || '' === db_ads_client() ) {
		return;
	}
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Cache-Control: public, max-age=3600' );
	echo 'google.com, ' . str_replace( 'ca-pub-', 'pub-', db_ads_client() ) . ", DIRECT, f08c47fec0942fa0\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	// Lines that were already in the live ads.txt (other publisher accounts) stay, so no ad income is cut off.
	foreach ( preg_split( '/\r\n|\r|\n/', db_ads_extra_lines() ) as $line ) {
		$line = trim( $line );
		if ( '' !== $line && preg_match( '/^[a-z0-9.\-]+,\s*[A-Za-z0-9\-_]+,\s*(DIRECT|RESELLER)(,\s*[a-f0-9]+)?$/i', $line ) ) {
			echo $line . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- matched against a strict ads.txt line pattern.
		}
	}
	exit;
}, 1 );

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Ads', 'Ads', 'manage_options', 'db-ads', 'db_ads_admin_page' );
}, 26 );

if ( ! function_exists( 'db_ads_admin_page' ) ) {
	function db_ads_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$o = db_ads_settings();
		echo '<div class="wrap"><h1>Ads (Google AdSense)</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>Saved.</p></div>';
		}
		echo '<p>Banner slots show on domain pages, the home page, domain lists, news and other content pages (never on checkout, thank-you, account or admin pages). Create <em>Display ad units</em> in AdSense (one can be reused for several slots), then paste your publisher id and the ad unit ids here. A slot left empty shows nothing. Leave the publisher id empty to show no ads on the live site.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="db_ads_save">';
		wp_nonce_field( 'db_ads_save' );
		echo '<table class="form-table" role="presentation"><tr><th><label for="db_ads_client">Publisher id</label></th><td><input type="text" id="db_ads_client" name="client" class="regular-text" placeholder="ca-pub-1234567890123456" value="' . esc_attr( db_ads_client() ) . '"></td></tr>';
		foreach ( db_ads_slots() as $k => $label ) {
			echo '<tr><th><label for="db_ads_' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" id="db_ads_' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" class="regular-text" placeholder="Ad unit id, digits only" value="' . esc_attr( $o[ $k ] ?? '' ) . '"></td></tr>';
		}
		echo '<tr><th><label for="db_ads_extra">Other ads.txt lines</label></th><td><textarea id="db_ads_extra" name="extra" rows="4" class="large-text code">' . esc_textarea( db_ads_extra_lines() ) . '</textarea><p class="description">Published in /ads.txt after your own line. Keep the lines of any other ad account you still use.</p></td></tr>';
		echo '</table><p class="description">Ads that personalise need a consent banner for visitors in the EEA, UK and Switzerland. Set that up before turning ads on.</p>';
		submit_button( 'Save' );
		echo '</form></div>';
	}
}

add_action( 'admin_post_db_ads_save', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_ads_save' ) ) {
		wp_die( 'Not allowed.' );
	}
	$out    = array();
	$client = isset( $_POST['client'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['client'] ) ) ) : '';
	$out['client'] = preg_match( '/^ca-pub-\d{10,20}$/', $client ) ? $client : '';
	foreach ( array_keys( db_ads_slots() ) as $k ) {
		$v         = isset( $_POST[ $k ] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST[ $k ] ) ) : '';
		$out[ $k ] = $v;
	}
	$out['extra'] = isset( $_POST['extra'] ) ? sanitize_textarea_field( wp_unslash( $_POST['extra'] ) ) : '';
	update_option( 'db_ads', $out, false );
	do_action( 'litespeed_purge_all' );
	wp_safe_redirect( admin_url( 'admin.php?page=db-ads&saved=1' ) );
	exit;
} );

/* ───────────────────────────── Placements outside the domain page ─────── */

if ( ! function_exists( 'db_ads_page_allowed' ) ) {
	/** Never beside a payment, a sign-in, a thank-you or a legal page. */
	function db_ads_page_allowed() {
		if ( is_admin() || is_404() || is_search() || is_feed() || is_embed() || ( function_exists( 'db_wl_is_page_request' ) && db_wl_is_page_request() ) ) {
			return false;
		}
		if ( is_page() ) {
			$p = get_queried_object();
			$bad = array( 'buy-now', 'checkout', 'payment-plan-setup', 'thank-you', 'terms-of-use', 'privacy-policy', 'cookie-policy', 'my-wishlist', 'epp', 'transfer-code', 'faqs', 'faq' );
			if ( $p instanceof WP_Post && in_array( $p->post_name, $bad, true ) ) {
				return false;
			}
		}
		return true;
	}
}

// Home page: after the buyers/sellers and How it works sections.
add_filter( 'db_hp_sections', function ( $html ) {
	return $html . db_ad_html( 'home_mid' );
}, 20 );

// Pages, news posts and services: after the content, once per page.
add_filter( 'the_content', function ( $content ) {
	static $done = false;
	if ( $done || ! in_the_loop() || ! is_main_query() || ! ( is_singular( array( 'page', 'post' ) ) ) || is_front_page() || ! db_ads_page_allowed() ) {
		return $content;
	}
	$ad = db_ad_html( 'content_end' );
	if ( '' === $ad ) {
		return $content;
	}
	$done = true;
	$GLOBALS['db_ads_placed'] = true;
	return $content . $ad;
}, 99 );

// Domain lists and category pages: below the list.
add_action( 'loop_end', function ( $q ) {
	static $done = false;
	if ( $done || ! $q->is_main_query() || ! ( is_post_type_archive( 'domain' ) || is_tax( 'domain_category' ) ) || ! db_ads_page_allowed() ) {
		return;
	}
	$done = true;
	$GLOBALS['db_ads_placed'] = true;
	echo db_ad_html( 'list_bottom' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
} );

// Sold and not-for-sale pages (their own template) print the slot just before the footer.
add_action( 'get_footer', function () {
	if ( is_singular( 'domain' ) && function_exists( 'db_listing_state' ) && in_array( db_listing_state( get_queried_object() ), array( 'sold', 'not_for_sale' ), true ) ) {
		echo db_ad_html( 'sold_page' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	}
} );

// Pages built by the theme's own templates (the all-domains list, the news index) never call the_content: place the slot before the footer.
add_action( 'get_footer', function () {
	if ( ! empty( $GLOBALS['db_ads_placed'] ) || is_front_page() || is_singular( 'domain' ) || ! ( is_page() || is_home() || is_archive() ) || ! db_ads_page_allowed() ) {
		return;
	}
	$GLOBALS['db_ads_placed'] = true;
	echo db_ad_html( is_page() ? 'list_bottom' : 'content_end' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
}, 20 );
