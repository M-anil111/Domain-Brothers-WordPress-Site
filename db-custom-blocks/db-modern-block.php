<?php
/**
 * Modern white marketplace look, loaded last.
 *
 * The stylesheet lives in assets/modern.css so it can be read and edited as
 * CSS. It is written to the uploads folder as a cacheable file named by its
 * content hash (like the other style layers), and inlined only when uploads
 * is not writable.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_modern_css' ) ) {
	function db_modern_css() {
		$file = __DIR__ . '/assets/modern.css';
		$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		return $css;
	}
}

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}
	$css = db_modern_css();
	if ( '' === $css ) {
		return;
	}
	if ( ! function_exists( 'db_external_style' ) || ! db_external_style( 'modern', $css ) ) {
		add_action( 'wp_head', function () use ( $css ) {
			echo '<style id="db-modern">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}, 120 );
	}
}, 110 );

// List or grid for domain listings. List is the default; the choice is remembered in this browser.
add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	?>
<script id="db-view-init">
(function () {
	var v = 'list';
	try { var s = localStorage.getItem('db_view'); if (s === 'grid' || s === 'list') { v = s; } } catch (e) {}
	document.documentElement.classList.add('db-view-' + v);
})();
</script>
	<?php
}, 1 );

add_action( 'wp_footer', function () {
	if ( is_admin() || ! ( is_front_page() || is_page( 'all-domains' ) || is_post_type_archive( 'domain' ) || is_tax( 'domain_category' ) ) ) {
		return;
	}
	?>
<script id="db-view-toggle">
(function () {
	'use strict';
	var root = document.documentElement;
	function current() { return root.classList.contains('db-view-grid') ? 'grid' : 'list'; }
	function set(v) {
		root.classList.remove('db-view-list', 'db-view-grid');
		root.classList.add('db-view-' + v);
		try { localStorage.setItem('db_view', v); } catch (e) {}
		document.querySelectorAll('.db-viewtoggle button').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.view === v ? 'true' : 'false'); });
	}
	function make() {
		var bar = document.createElement('div');
		bar.className = 'db-viewbar';
		bar.innerHTML = '<span class="db-viewbar__label" id="db-view-label">View</span>' +
			'<div class="db-viewtoggle" role="group" aria-labelledby="db-view-label">' +
			'<button type="button" data-view="list" aria-pressed="' + (current() === 'list') + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>List</button>' +
			'<button type="button" data-view="grid" aria-pressed="' + (current() === 'grid') + '"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="4" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="4" width="6.5" height="6.5" rx="1"/><rect x="4" y="13.5" width="6.5" height="6.5" rx="1"/><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1"/></svg>Grid</button></div>';
		bar.addEventListener('click', function (e) { var b = e.target.closest('button[data-view]'); if (b) { set(b.dataset.view); } });
		return bar;
	}
	function ensure() {
		var grid = document.querySelector('.db-domain-grid');
		if (!grid || (grid.previousElementSibling && grid.previousElementSibling.classList.contains('db-viewbar'))) { return; }
		document.querySelectorAll('.db-viewbar').forEach(function (n) { n.remove(); });
		grid.parentNode.insertBefore(make(), grid);
	}
	ensure();
	var tries = 0, t = setInterval(function () { ensure(); if (++tries > 40) { clearInterval(t); } }, 250);
	new MutationObserver(ensure).observe(document.body, { childList: true, subtree: true });
})();
</script>
	<?php
}, 90 );
