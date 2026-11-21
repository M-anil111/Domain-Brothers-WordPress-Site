<?php
/**
 * Pure HTML transforms. No WordPress calls, so they can be unit-tested with
 * the PHP CLI against captured beta pages (see tests/run-filters.php).
 *
 * Every transform is narrowly anchored to markup that exists on beta today
 * and is a no-op when that markup is absent.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DBUX_TESTING' ) ) {
	exit;
}

/**
 * @param string $html    Full HTML document.
 * @param array  $context { domain_archive: bool, all_domains: bool }.
 * @return string
 */
function dbux_filter_html( $html, array $context ) {
	$html = dbux_label_header_search( $html );
	$html = dbux_checkout_price_icon( $html );

	if ( ! empty( $context['domain_archive'] ) ) {
		$html = dbux_archive_heading_h1( $html );
	}
	if ( ! empty( $context['all_domains'] ) ) {
		$html = dbux_all_domains_hero_count( $html );
	}
	return $html;
}

/**
 * DB-28: the theme's two domain-search inputs only carry a placeholder and a
 * generic or unassociated label. Both submit post_type=domain, so name them
 * for what they search.
 */
function dbux_label_header_search( $html ) {
	$label = 'Search domains for sale';
	foreach ( array( 'search_id_mobile', 'search_id' ) as $id ) {
		$html = preg_replace(
			'/<input(?![^>]*\baria-label=)([^>]*\bid="' . $id . '"[^>]*)>/i',
			'<input aria-label="' . $label . '"$1>',
			$html,
			1
		);
	}
	return $html;
}

/**
 * DB-29: the order-summary price row draws a "$" inside its decorative seal
 * icon next to the already formatted "$2,500.00", which reads as "$ $2,500.00".
 * Swap only the glyph for a check mark. The amount text and its server-side
 * formatting are untouched.
 */
function dbux_checkout_price_icon( $html ) {
	if ( false === strpos( $html, 'co-sum-price' ) ) {
		return $html;
	}
	return preg_replace_callback(
		'/(<div class="co-sum-price">\s*<svg\b[^>]*>)(.*?)(<\/svg>)/s',
		static function ( $m ) {
			$check = '<path d="M14.5 20.5l4 4 7.5-8.5" fill="none" stroke="#2f8a17" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>';
			$inner = preg_replace( '/<text\b[^>]*>\s*\$\s*<\/text>/', $check, $m[2], 1 );
			return $m[1] . $inner . $m[3];
		},
		$html,
		1
	);
}

/**
 * DB-12: the domain archive (/domains/ and /domains/page/N/) has no H1; its
 * visible title is an H2. Promote that one heading. Styling is kept by the
 * matching rule in assets/ui-fixes.css.
 */
function dbux_archive_heading_h1( $html ) {
	if ( preg_match( '/<h1[\s>]/i', $html ) ) {
		return $html; // Never create a second H1.
	}
	return preg_replace(
		'/(<header class="page-header">\s*)<h2 class="page-title">(.*?)<\/h2>/s',
		'$1<h1 class="page-title">$2</h1>',
		$html,
		1
	);
}

/**
 * DB-06: the marketplace hero says "206 names available", but 206 is every
 * domain post including sold and unpriced names. Replace the hard number with
 * a placeholder that listing-state.js fills with the count of names actually
 * shown as available across the paginated listing. Without JS the sentence
 * still reads correctly and makes no numeric claim.
 */
function dbux_all_domains_hero_count( $html ) {
	return preg_replace(
		'/<strong>\s*[\d,]+\s+names available\.\s*<\/strong>/',
		'<strong data-dbux-available>All listed names are available now.</strong>',
		$html,
		1
	);
}
