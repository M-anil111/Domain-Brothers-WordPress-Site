<?php
/**
 * DB PWA Block — installable web app + home-screen support
 *
 * Serves a web-app manifest and a lightweight service worker so browsers
 * offer "Add to Home Screen" / "Install app" on mobile and desktop.
 *
 * Endpoints (virtual, no physical files needed):
 *   /?db_manifest=1  → application/manifest+json
 *   /?db_sw=1        → the service worker JS (Service-Worker-Allowed: /)
 *
 * The service worker uses a network-first strategy for pages and
 * stale-while-revalidate for static assets, which also trims repeat-visit
 * requests to the origin (less hosting load).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_PWA_THEME_COLOR' ) ) {
	define( 'DB_PWA_THEME_COLOR', '#0a1628' );
}
if ( ! defined( 'DB_PWA_CACHE_VERSION' ) ) {
	// Bumped v1→v2: the SW 'activate' step purges every cache whose name
	// isn't the current one, so this clears any stale HTML a previous SW
	// cached on visitors' devices.
	define( 'DB_PWA_CACHE_VERSION', 'db-pwa-v2' );
}

/* ─── Manifest + SW endpoints ─────────────────────────────────────────────── */

add_action( 'init', function () {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['db_manifest'] ) ) {
		db_pwa_serve_manifest();
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['db_sw'] ) ) {
		db_pwa_serve_sw();
	}
} );

if ( ! function_exists( 'db_pwa_serve_manifest' ) ) {
	function db_pwa_serve_manifest() {
		$icon = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 512 ) : '';
		$icons = array();
		if ( $icon ) {
			$icons[] = array( 'src' => $icon, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable' );
			$icon192 = get_site_icon_url( 192 );
			if ( $icon192 ) {
				$icons[] = array( 'src' => $icon192, 'sizes' => '192x192', 'type' => 'image/png' );
			}
		}

		$manifest = array(
			'name'             => get_bloginfo( 'name' ),
			'short_name'       => 'DomainBros',
			'description'      => get_bloginfo( 'description' ),
			'start_url'        => home_url( '/?utm_source=pwa' ),
			'scope'            => home_url( '/' ),
			'display'          => 'standalone',
			'background_color' => DB_PWA_THEME_COLOR,
			'theme_color'      => DB_PWA_THEME_COLOR,
			'icons'            => $icons,
		);

		header( 'Content-Type: application/manifest+json; charset=UTF-8' );
		header( 'Cache-Control: public, max-age=86400' );
		echo wp_json_encode( $manifest );
		exit;
	}
}

if ( ! function_exists( 'db_pwa_serve_sw' ) ) {
	function db_pwa_serve_sw() {
		header( 'Content-Type: application/javascript; charset=UTF-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Service-Worker-Allowed: /' );
		// SELF-DESTRUCTING worker. A previous caching SW served stale page
		// HTML to visitors even after they cleared cookies/cache. Any browser
		// that still fetches this script now installs a worker that, on
		// activate, wipes every cache, unregisters itself, and reloads open
		// tabs — leaving no service worker in control so pages load fresh.
		echo <<<JS
'use strict';
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => {
	e.waitUntil((async () => {
		try {
			const keys = await caches.keys();
			await Promise.all(keys.map((k) => caches.delete(k)));
			await self.registration.unregister();
			const clients = await self.clients.matchAll({ type: 'window' });
			clients.forEach((c) => { try { c.navigate(c.url); } catch (e) {} });
		} catch (e) {}
	})());
});
JS;
JS;
		exit;
	}
}

/* ─── Head tags + SW registration ─────────────────────────────────────────── */

add_action( 'wp_head', function () {
	$manifest = esc_url( home_url( '/?db_manifest=1' ) );
	$icon180  = function_exists( 'get_site_icon_url' ) ? get_site_icon_url( 180 ) : '';
	echo '<link rel="manifest" href="' . $manifest . '">' . "\n";
	echo '<meta name="theme-color" content="' . esc_attr( DB_PWA_THEME_COLOR ) . '">' . "\n";
	echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
	echo '<meta name="apple-mobile-web-app-title" content="' . esc_attr( get_bloginfo( 'name' ) ) . '">' . "\n";
	if ( $icon180 ) {
		echo '<link rel="apple-touch-icon" href="' . esc_url( $icon180 ) . '">' . "\n";
	}
}, 2 );

add_action( 'wp_footer', function () {
	// KILL SWITCH: a previously-installed service worker was caching page
	// HTML on visitors' devices and serving stale markup (old domain-card
	// rendering) even after they cleared cookies/cache — a clear doesn't
	// unregister a service worker. Unregister any existing worker and wipe
	// its Cache Storage on every load, so the page is always served fresh
	// straight from the network. (Re-enable a caching SW later once the
	// design has settled.)
	echo "<script>if('serviceWorker' in navigator){navigator.serviceWorker.getRegistrations().then(function(rs){rs.forEach(function(r){r.unregister();});});}if(window.caches&&caches.keys){caches.keys().then(function(ks){ks.forEach(function(k){caches.delete(k);});});}</script>\n";
}, 99 );
