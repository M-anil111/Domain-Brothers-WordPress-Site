<?php
/* === DB services navigation menu ===
 *
 * Adds a "Services" item with a dropdown to the primary navigation bar.
 *
 * HOW IT WORKS:
 *   - Uses the wp_nav_menu_items filter to append the Services <li> to whatever
 *     nav menu renders in the header. Targets all common header theme_location
 *     slugs used by WordPress themes; add yours to DB_SERVICES_MENU_LOCATIONS if
 *     the list below misses it (find the slug via WP admin → Appearance → Menus
 *     → pick a menu → the "Theme locations" section on the left).
 *   - CSS-only dropdown on desktop (hover + :focus-within for keyboard nav).
 *   - On mobile (≤ 900 px) converts to a tap-toggle accordion; small JS in
 *     wp_footer handles the toggle (no jQuery dependency).
 *   - After deploying, visit WP admin → Appearance → Menus and drag "Services"
 *     to your preferred position if you want it somewhere other than last.
 *
 * CONFIG: edit DB_SERVICES_MENU_LOCATIONS below to add or remove theme_location
 * slugs. The current list covers the most common WordPress theme naming conventions.
 *
 * Paste at the end of functions.php. Self-contained.
 */

if ( ! defined( 'DB_SERVICES_MENU_LOCATIONS' ) ) {
	/* Comma-separated list of theme_location slugs to inject into. */
	define( 'DB_SERVICES_MENU_LOCATIONS', 'primary,main-menu,header-menu,main,nav,navigation,top-menu,header-nav,menu-1' );
}

/* All five service pages created by db_service_pages_block. */
function db_services_list() {
	return array(
		'Website Design & Development' => '/website-design-development/',
		'Digital Marketing'            => '/digital-marketing/',
		'Software Development'         => '/software-development/',
		'Mobile App Development'       => '/mobile-app-development/',
		'Other Services'               => '/other-services/',
	);
}

/*
 * Inject the Services <li> (with nested dropdown) into matching nav menus.
 * The filter receives the already-rendered string of <li> items.
 */
add_filter( 'wp_nav_menu_items', function ( $items, $args ) {
	$allowed = array_map( 'trim', explode( ',', DB_SERVICES_MENU_LOCATIONS ) );
	if ( empty( $args->theme_location ) || ! in_array( $args->theme_location, $allowed, true ) ) {
		return $items;
	}
	// Guard against double injection if the filter fires more than once per request.
	if ( false !== strpos( $items, 'db-svc-parent' ) ) {
		return $items;
	}

	$children = '';
	foreach ( db_services_list() as $label => $path ) {
		$children .= '<li class="menu-item db-svc-child"><a href="'
		             . esc_url( home_url( $path ) ) . '">'
		             . esc_html( $label ) . '</a></li>';
	}

	$services_item = '
<li class="menu-item menu-item-has-children db-svc-parent">
	<a href="#" class="db-svc-toggle" aria-haspopup="true" aria-expanded="false">
		Services<span class="db-svc-arrow" aria-hidden="true"></span>
	</a>
	<ul class="sub-menu db-svc-dropdown" role="menu">' . $children . '</ul>
</li>';

	return $items . $services_item;
}, 10, 2 );

/* Dropdown CSS — scoped to .db-svc-* to avoid collision with theme styles. */
add_action( 'wp_head', function () {
	?>
	<style id="db-svc-menu-css">
	/* --- DB Services Navigation Dropdown --- */
	.db-svc-parent { position: relative; list-style: none; }

	/* CSS-only chevron — no icon font or image required. */
	.db-svc-arrow {
		display: inline-block;
		width: 0; height: 0;
		margin-left: 5px;
		vertical-align: middle;
		border-left: 4px solid transparent;
		border-right: 4px solid transparent;
		border-top: 5px solid currentColor;
		transition: transform 0.2s ease;
	}

	.db-svc-dropdown {
		list-style: none !important;
		margin: 0 !important;
		padding: 6px 0 !important;
		position: absolute;
		top: calc(100% + 8px);
		left: 50%;
		transform: translateX(-50%) translateY(6px);
		min-width: 244px;
		background: #ffffff;
		border-radius: 12px;
		box-shadow: 0 4px 6px rgba(0,0,0,.06), 0 14px 32px rgba(0,0,0,.11);
		border: 1px solid rgba(0,0,0,.07);
		opacity: 0;
		visibility: hidden;
		pointer-events: none;
		transition: opacity 0.18s ease, transform 0.18s ease, visibility 0s 0.18s;
		z-index: 99999;
	}

	/* Reveal on hover or keyboard focus-within (for keyboard navigation). */
	.db-svc-parent:hover > .db-svc-dropdown,
	.db-svc-parent:focus-within > .db-svc-dropdown {
		opacity: 1;
		visibility: visible;
		pointer-events: auto;
		transform: translateX(-50%) translateY(0);
		transition-delay: 0s;
	}
	.db-svc-parent:hover > a .db-svc-arrow,
	.db-svc-parent:focus-within > a .db-svc-arrow {
		transform: rotate(180deg);
	}

	.db-svc-child { list-style: none !important; }
	.db-svc-child a {
		display: block !important;
		padding: 10px 20px !important;
		font-size: 14px !important;
		line-height: 1.35 !important;
		color: #1d1d1f !important;
		text-decoration: none !important;
		white-space: nowrap;
		transition: background 0.12s, color 0.12s;
	}
	.db-svc-child a:hover,
	.db-svc-child a:focus {
		background: #eef2ff;
		color: #0b3d91 !important;
		outline: none;
	}

	/* ---- Mobile: collapse to tap accordion ---- */
	@media (max-width: 900px) {
		.db-svc-dropdown {
			position: static !important;
			transform: none !important;
			box-shadow: none !important;
			border: none !important;
			border-radius: 0 !important;
			background: rgba(0,0,0,.04) !important;
			padding: 2px 0 2px 14px !important;
			display: none;
			opacity: 1;
			visibility: visible;
			pointer-events: auto;
			transition: none;
			min-width: 0;
		}
		/* Show only when JS adds .is-open. */
		.db-svc-parent.is-open > .db-svc-dropdown { display: block; }
		.db-svc-parent.is-open > a .db-svc-arrow { transform: rotate(180deg); }
		/* Disable CSS :hover on touch devices. */
		.db-svc-parent:hover > .db-svc-dropdown { display: none; }
		.db-svc-parent.is-open:hover > .db-svc-dropdown { display: block; }
	}
	</style>
	<?php
} );

/* Mobile tap-toggle JS — runs only when viewport ≤ 900 px (desktop uses CSS :hover). */
add_action( 'wp_footer', function () {
	?>
	<script>
	(function () {
		'use strict';
		// Use querySelectorAll so every injected Services item (header, footer,
		// etc.) gets its own independent click handler — querySelector only finds
		// the first match and would leave any secondary toggles non-functional.
		var toggles = document.querySelectorAll('.db-svc-toggle');
		if (!toggles.length) return;

		function isMobile() { return window.innerWidth <= 900; }

		toggles.forEach(function (toggle) {
			var parent = toggle.closest ? toggle.closest('.db-svc-parent') : toggle.parentElement;
			if (!parent) return;

			toggle.addEventListener('click', function (e) {
				if (!isMobile()) return;
				e.preventDefault();
				var open = parent.classList.toggle('is-open');
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			});

			// Close when clicking anywhere outside this dropdown.
			document.addEventListener('click', function (e) {
				if (!isMobile()) return;
				if (parent.classList.contains('is-open') && !parent.contains(e.target)) {
					parent.classList.remove('is-open');
					toggle.setAttribute('aria-expanded', 'false');
				}
			});

			// ESC key closes the dropdown and returns focus to the toggle.
			document.addEventListener('keydown', function (e) {
				if ((e.key === 'Escape' || e.keyCode === 27) && parent.classList.contains('is-open')) {
					parent.classList.remove('is-open');
					toggle.setAttribute('aria-expanded', 'false');
					toggle.focus();
				}
			});
		});
	})();
	</script>
	<?php
}, 99 );
/* === end DB services navigation menu === */
