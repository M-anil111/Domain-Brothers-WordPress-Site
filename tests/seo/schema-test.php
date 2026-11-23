<?php
/**
 * Offline tests for db-schema-block.php (repairs to Rank Math's JSON-LD graph).
 *
 *     php tests/seo/schema-test.php
 */
define( 'ABSPATH', __DIR__ );
function add_action() {}
function add_filter() {}
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
require __DIR__ . '/../../db-custom-blocks/db-schema-block.php';

$pass = 0; $fail = 0;
function check( $name, $ok ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}

$ctx = array(
	'home' => 'https://example.test/', 'url' => 'https://example.test/domain-tools/dns-lookup/', 'name' => 'Domain Brothers',
	'logo' => 'https://example.test/logo.png', 'email' => 'sales@example.test', 'about' => 'https://example.test/about-us/',
	'trail' => array(), 'is_listing' => false,
);

// Virtual route as Rank Math prints it (SE-3).
$rm = array(
	'publisher'      => array( '@type' => 'Organization', '@id' => 'https://example.test/#organization', 'name' => 'Domain Brothers', 'url' => 'https://example.test/' ),
	'WebPage'        => array( '@type' => 'WebPage', '@id' => '#webpage', 'url' => '', 'breadcrumb' => array( '@id' => '#breadcrumb' ) ),
	'BreadcrumbList' => array( '@type' => 'BreadcrumbList', '@id' => '#breadcrumb', 'itemListElement' => array( array( '@type' => 'ListItem', 'position' => '1', 'item' => array( '@id' => 'https://example.test', 'name' => 'Home' ) ) ) ),
);
$out = db_schema_fix_graph( $rm, $ctx );
check( 'relative @id made absolute', 'https://example.test/domain-tools/dns-lookup/#webpage' === $out['WebPage']['@id'] );
check( 'empty WebPage url filled', 'https://example.test/domain-tools/dns-lookup/' === $out['WebPage']['url'] );
check( 'one-item breadcrumb dropped and its reference removed', ! isset( $out['BreadcrumbList'] ) && ! isset( $out['WebPage']['breadcrumb'] ) );
check( 'organization gets logo and contact point (SE-17)', 'https://example.test/logo.png' === $out['publisher']['logo']['url'] && 'sales@example.test' === $out['publisher']['contactPoint'][0]['email'] );

$keep = $rm; $keep['publisher']['logo'] = array( '@type' => 'ImageObject', 'url' => 'https://example.test/own.png' );
check( 'an existing logo is kept', 'https://example.test/own.png' === db_schema_fix_graph( $keep, $ctx )['publisher']['logo']['url'] );

// Trail from the visible breadcrumb.
$ctx2          = $ctx;
$ctx2['trail'] = array( array( 'Home', 'https://example.test/' ), array( 'Domain tools', 'https://example.test/domain-tools/' ), array( 'DNS Lookup', '' ) );
$out2          = db_schema_fix_graph( $rm, $ctx2 );
$items         = $out2['BreadcrumbList']['itemListElement'];
check( 'breadcrumb rebuilt from the visible trail', 3 === count( $items ) && 'Domain tools' === $items[1]['item']['name'] );
check( 'positions are numbers and the last step is this page', 3 === $items[2]['position'] && 'https://example.test/domain-tools/dns-lookup/' === $items[2]['item']['@id'] );

// Article author (SE-18).
$art = array(
	'publisher' => $rm['publisher'],
	'ProfilePage' => array( '@type' => 'Person', '@id' => 'https://example.test/about-us/', 'name' => 'Domain Brothers' ),
	'richSnippet' => array( '@type' => 'BlogPosting', 'author' => array( '@id' => 'https://example.test/about-us/', 'name' => 'Domain Brothers' ) ),
);
$out3 = db_schema_fix_graph( $art, $ctx );
check( 'brand Person removed', ! isset( $out3['ProfilePage'] ) );
check( 'author is the Organization', 'Organization' === $out3['richSnippet']['author']['@type'] && 'https://example.test/#organization' === $out3['richSnippet']['author']['@id'] );
$real = $art; $real['ProfilePage']['name'] = 'Jay Example'; $real['richSnippet']['author']['name'] = 'Jay Example';
$out4 = db_schema_fix_graph( $real, $ctx );
check( 'a real person author is kept', isset( $out4['ProfilePage'] ) && ! isset( $out4['richSnippet']['author']['@type'] ) );

// Listing page: Rank Math prints only a breadcrumb.
$ctx5 = $ctx; $ctx5['is_listing'] = true; $ctx5['url'] = 'https://example.test/domains/x-ca/';
$ctx5['trail'] = array( array( 'Home', 'https://example.test/' ), array( 'Tech', 'https://example.test/domain-category/tech/' ), array( 'x.ca', '' ) );
$lp = array( 'BreadcrumbList' => array( '@type' => 'BreadcrumbList', '@id' => 'https://example.test/domains/x-ca/#breadcrumb', 'itemListElement' => array(
	array( '@type' => 'ListItem', 'position' => '1', 'item' => array( '@id' => 'https://example.test', 'name' => 'Home' ) ),
	array( '@type' => 'ListItem', 'position' => '2', 'item' => array( '@id' => 'https://example.test/domains/', 'name' => 'Domains' ) ),
	array( '@type' => 'ListItem', 'position' => '3', 'item' => array( '@id' => 'https://example.test/domains/x-ca/', 'name' => 'x.ca' ) ),
) ) );
$out5 = db_schema_fix_graph( $lp, $ctx5 );
check( 'listing gets an Organization node for the Product seller', isset( $out5['publisher'] ) && 'https://example.test/#organization' === $out5['publisher']['@id'] );
check( 'listing breadcrumb no longer points at the /domains/ archive', false === strpos( json_encode( $out5['BreadcrumbList'] ), '\/domains\/"' ) && 'Tech' === $out5['BreadcrumbList']['itemListElement'][1]['item']['name'] );
check( 'no Organization is added on other pages', ! isset( db_schema_fix_graph( array( 'BreadcrumbList' => $lp['BreadcrumbList'] ), $ctx )['publisher'] ) );

// Theme Person block (SE-4).
$html = '<head><script type="application/ld+json">{"@context":"https://schema.org","@type":"person","name":"X","email":"x@example.test"}</script>' . "\n" . '<script type="application/ld+json">{"@type":"Person","name":"X"}</script></head>';
$clean = db_schema_strip_theme_person( $html );
check( 'invalid lowercase person block removed', false === strpos( $clean, '"person"' ) );
check( 'the valid Person block stays', false !== strpos( $clean, '"@type":"Person"' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
