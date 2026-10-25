<?php
/**
 * Front-end speed: load plugin assets only where the plugin is used.
 *
 * Measured on staging before this block, every page (home, news, FAQs, ...)
 * loaded the stylesheets and scripts of Contact Form 7, its multi-step
 * module, Page List and Team Members, although a form exists only on domain
 * pages, Contact and Offer, and neither Page List nor Team Members renders
 * anything. Stylesheets block rendering, and Contact Form 7 adds three
 * synchronous scripts to the head.
 *
 * Rules:
 *   - Contact Form 7 (+ multi-step): domain pages, or a page whose content
 *     holds a form shortcode.
 *   - Page List and Team Members: only when the content holds their shortcode.
 *   - If one of those shortcodes renders anyway (a theme template, a widget,
 *     a builder), the assets are enqueued at that moment, so a form or list
 *     is never left unstyled or inert.
 *   - The theme's bold webfont (230 KB) had no font-display, so text stayed
 *     invisible until it arrived; it now shows in a fallback font first.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_perf_content' ) ) {
	/** Lower-case content of the queried page ('' when there is none). */
	function db_perf_content() {
		$post = is_singular() ? get_queried_object() : null;
		return ( $post instanceof WP_Post ) ? strtolower( (string) $post->post_content ) : '';
	}
}

if ( ! function_exists( 'db_perf_has' ) ) {
	/** True when the content contains any of the (lower-case) needles. */
	function db_perf_has( $content, array $needles ) {
		foreach ( $needles as $n ) {
			if ( false !== strpos( $content, $n ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'db_perf_needs_forms' ) ) {
	function db_perf_needs_forms() {
		if ( is_singular( 'domain' ) ) {
			return true;
		}
		return db_perf_has( db_perf_content(), array( '[contact-form-7', '[cf7', 'wpcf7' ) );
	}
}

if ( ! function_exists( 'db_perf_needs_lists' ) ) {
	function db_perf_needs_lists() {
		return db_perf_has( db_perf_content(), array( '[pagelist', '[page_list', '[subpages', '[siblings', '[tmm', '[team' ) );
	}
}

// Contact Form 7 honours these filters when it enqueues its own files.
add_filter( 'wpcf7_load_js', function ( $load ) {
	return $load && db_perf_needs_forms();
} );
add_filter( 'wpcf7_load_css', function ( $load ) {
	return $load && db_perf_needs_forms();
} );

add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) {
		return;
	}
	// The theme's own scripts use jQuery without declaring it; until now it
	// arrived as a dependency of the multi-step module's script.
	wp_enqueue_script( 'jquery' );
	if ( ! db_perf_needs_forms() ) {
		wp_dequeue_style( 'cf7msm_styles' );
		wp_dequeue_script( 'cf7msm' );
	}
	if ( ! db_perf_needs_lists() ) {
		wp_dequeue_style( 'page-list-style' );
		wp_dequeue_style( 'tmm' );
	}
}, 100 );

// Safety net: a shortcode rendered outside the checks above still gets its assets.
add_filter( 'do_shortcode_tag', function ( $output, $tag ) {
	if ( 'contact-form-7' === $tag || 0 === strpos( $tag, 'cf7' ) ) {
		if ( function_exists( 'wpcf7_enqueue_styles' ) && ! wp_style_is( 'contact-form-7', 'enqueued' ) ) {
			wpcf7_enqueue_styles();
		}
		if ( function_exists( 'wpcf7_enqueue_scripts' ) && ! wp_script_is( 'contact-form-7', 'enqueued' ) ) {
			wpcf7_enqueue_scripts();
		}
		foreach ( array( 'cf7msm_styles' => 'style', 'cf7msm' => 'script' ) as $h => $kind ) {
			if ( wp_script_is( $h, 'registered' ) || wp_style_is( $h, 'registered' ) ) {
				'style' === $kind ? wp_enqueue_style( $h ) : wp_enqueue_script( $h );
			}
		}
	} elseif ( in_array( $tag, array( 'pagelist', 'page_list', 'subpages', 'siblings', 'pagelist_ext' ), true ) ) {
		wp_enqueue_style( 'page-list-style' );
	} elseif ( 0 === strpos( $tag, 'tmm' ) ) {
		wp_enqueue_style( 'tmm' );
	}
	return $output;
}, 10, 2 );

// font-display: swap for the theme's one webfont; the later rule overrides the theme's.
add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	$base = esc_url( get_template_directory_uri() . '/assets/fonts/lato-bold' );
	echo '<style id="db-font-swap">@font-face{font-family:"latobold";src:url("' . $base . '.woff2") format("woff2"),url("' . $base . '.woff") format("woff");font-weight:normal;font-style:normal;font-display:swap}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}, 99 );
