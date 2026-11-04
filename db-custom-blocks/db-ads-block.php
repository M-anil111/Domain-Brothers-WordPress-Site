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
		);
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
		$client = isset( $o['client'] ) ? (string) $o['client'] : '';
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

add_action( 'wp_footer', function () {
	if ( empty( $GLOBALS['db_ads_used'] ) ) {
		return;
	}
	$o = db_ads_settings();
	echo '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' . esc_attr( $o['client'] ) . '" crossorigin="anonymous"></script>' . "\n"; // phpcs:ignore WordPress.WP.EnqueuedResources
}, 90 );

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
		echo '<p>Three banner slots show on every domain page. Create three <em>Display ad units</em> in AdSense (responsive), then paste your publisher id and each ad unit id here. Leave the publisher id empty to show no ads on the live site.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="db_ads_save">';
		wp_nonce_field( 'db_ads_save' );
		echo '<table class="form-table" role="presentation"><tr><th><label for="db_ads_client">Publisher id</label></th><td><input type="text" id="db_ads_client" name="client" class="regular-text" placeholder="ca-pub-1234567890123456" value="' . esc_attr( $o['client'] ?? '' ) . '"></td></tr>';
		foreach ( db_ads_slots() as $k => $label ) {
			echo '<tr><th><label for="db_ads_' . esc_attr( $k ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" id="db_ads_' . esc_attr( $k ) . '" name="' . esc_attr( $k ) . '" class="regular-text" placeholder="Ad unit id, digits only" value="' . esc_attr( $o[ $k ] ?? '' ) . '"></td></tr>';
		}
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
	update_option( 'db_ads', $out, false );
	do_action( 'litespeed_purge_all' );
	wp_safe_redirect( admin_url( 'admin.php?page=db-ads&saved=1' ) );
	exit;
} );
