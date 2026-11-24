<?php
/**
 * Motion layer: scroll reveal, page-to-page cross-fade, smooth hover and press.
 *
 * The rules are in assets/motion.css (CSS only, about 3 KB). They are printed inline in
 * <head> instead of linked, so they cost no extra request and apply on the first paint.
 * Everything there is off for visitors who ask for reduced motion, and nothing is hidden
 * until a script runs (see the notes at the top of the file).
 *
 * Switch off with the option db_motion_off = 1.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_motion_css' ) ) {
	/** The motion stylesheet, comments and blank space removed. */
	function db_motion_css() {
		static $css = null;
		if ( null === $css ) {
			$raw = is_readable( __DIR__ . '/assets/motion.css' ) ? (string) file_get_contents( __DIR__ . '/assets/motion.css' ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
			$raw = (string) preg_replace( '#/\*.*?\*/#s', '', $raw );
			$raw = (string) preg_replace( '/\s+/', ' ', $raw );
			$css = trim( (string) preg_replace( '/\s*([{};,>])\s*/', '$1', $raw ) );
		}
		return $css;
	}
}

add_action( 'wp_head', function () {
	if ( is_admin() || is_feed() || '1' === (string) get_option( 'db_motion_off', '0' ) ) {
		return;
	}
	$css = db_motion_css();
	if ( '' !== $css ) {
		echo '<style id="db-motion">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- a file in this plugin.
	}
}, 99 );
