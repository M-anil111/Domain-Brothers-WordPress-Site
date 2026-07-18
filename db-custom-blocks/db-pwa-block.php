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
		header( 'Cache-Control: public, max-age=3600' );
		// Lets a query-string SW control the whole origin scope.
		header( 'Service-Worker-Allowed: /' );
		$version = DB_PWA_CACHE_VERSION;
		// Never cache admin, checkout, or API traffic — payments and
		// dashboards must always hit the network.
		echo <<<JS
'use strict';
const CACHE = '{$version}';
const NEVER_CACHE = ['/wp-admin', '/wp-login', '/buy-now', '/wp-json', 'wc-ajax', 'admin-ajax'];

self.addEventListener('install', (e) => { self.skipWaiting(); });
self.addEventListener('activate', (e) => {
	e.waitUntil(
		caches.keys().then((keys) =>
			Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
		).then(() => self.clients.claim())
	);
});

self.addEventListener('fetch', (e) => {
	const req = e.request;
	if (req.method !== 'GET') { return; }
	const url = new URL(req.url);
	if (url.origin !== self.location.origin) { return; }
	if (NEVER_CACHE.some((p) => url.pathname.indexOf(p) !== -1 || url.search.indexOf(p) !== -1)) { return; }

	const isAsset = /\.(css|js|png|jpe?g|gif|svg|webp|woff2?)$/.test(url.pathname);

	if (isAsset) {
		// Stale-while-revalidate: serve cached instantly, refresh in background.
		e.respondWith(
			caches.open(CACHE).then(async (cache) => {
				const cached = await cache.match(req);
				const network = fetch(req).then((res) => {
					if (res && res.status === 200) { cache.put(req, res.clone()); }
					return res;
				}).catch(() => cached);
				return cached || network;
			})
		);
	} else {
		// Pages: NEVER cache HTML. The page markup carries inline
		// enhancer JS that changes with each release; caching it made
		// visitors run stale scripts (e.g. old domain-card rendering).
		// Always go to network; only fall back to cache when fully offline.
		e.respondWith(fetch(req).catch(() => caches.match(req)));
	}
});
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
	$sw = wp_json_encode( home_url( '/?db_sw=1' ) );
	echo "<script>if('serviceWorker' in navigator){window.addEventListener('load',function(){navigator.serviceWorker.register({$sw}).catch(function(){});});}</script>\n";
}, 99 );
