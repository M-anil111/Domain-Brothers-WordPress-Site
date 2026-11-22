<?php
/** Offline tests for db-site-hygiene-block.php. Run: php tests/hygiene/hygiene-test.php */

define( 'ABSPATH', '/' );
define( 'DAY_IN_SECONDS', 86400 );
function add_action() {}
function add_filter() {}
function esc_url( $u ) { return htmlspecialchars( $u, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
$GLOBALS['opts'] = array();
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
require __DIR__ . '/../../db-custom-blocks/db-site-hygiene-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

// ---- images
$dims = function ( $src ) { return false !== strpos( $src, 'known.jpg' ) ? array( 800, 400 ) : null; };
$html = '<img src="/a.png" alt="logo"><img src="/b.png"><img src="/c.png"><img src="/known.jpg" alt="x"><img src="/d.png" loading="eager" decoding="sync" width="10" height="10" alt="y"><img data-no-lazy src="/e.png">';
$out  = db_hyg_img_fix( $html, $dims, 3 );
preg_match_all( '/<img\b[^>]*>/', $out, $tags );
$t = $tags[0];
check( 'first three images stay eager', false === strpos( $t[0] . $t[1] . $t[2], 'loading=' ) );
check( 'fourth image is lazy', false !== strpos( $t[3], 'loading="lazy"' ) );
check( 'decoding async added where it was missing (not where set or opted out)', 4 === substr_count( $out, 'decoding="async"' ) );
check( 'known image gets its size', false !== strpos( $t[3], 'width="800" height="400"' ) );
check( 'unknown image gets no size', false === strpos( $t[1], 'width=' ) );
check( 'existing attributes are not duplicated', 1 === substr_count( $t[4], 'loading=' ) && 1 === substr_count( $t[4], 'decoding=' ) && 1 === substr_count( $t[4], 'width=' ) && false !== strpos( $t[4], 'loading="eager"' ) );
check( 'data-no-lazy image untouched', '<img data-no-lazy src="/e.png">' === $t[5] );
check( 'every tag is still a closed img tag', 6 === count( $t ) && 6 === count( array_filter( $t, function ( $x ) { return '>' === substr( $x, -1 ) && 1 === substr_count( $x, '<' ); } ) ) );
check( 'self-closing tag keeps its slash', '/>' === substr( db_hyg_img_fix( '<img src="/z.png" />', null, 0 ), -2 ) );
check( 'html without images is unchanged', '<p>hi</p>' === db_hyg_img_fix( '<p>hi</p>' ) );
check( 'idempotent', $out === db_hyg_img_fix( $out, $dims, 3 ) );

// ---- one h1
$two = '<main><h1 class="page-title screen-reader-text">News</h1><h1 class="db-an-h1">Domain News</h1></main>';
$fixed = db_hyg_single_h1( $two );
check( 'screen-reader-only h1 is demoted when a visible h1 exists', 1 === substr_count( $fixed, '<h1' ) && false !== strpos( $fixed, '<p class="page-title screen-reader-text">News</p>' ) );
check( 'the visible h1 is kept', false !== strpos( $fixed, '<h1 class="db-an-h1">Domain News</h1>' ) );
$only_sr = '<h1 class="screen-reader-text">Only</h1><h1 class="screen-reader-text">Two</h1>';
check( 'with no visible h1 nothing is changed', $only_sr === db_hyg_single_h1( $only_sr ) );
check( 'a single h1 is untouched', '<h1>A</h1>' === db_hyg_single_h1( '<h1>A</h1>' ) );
check( 'two visible h1s are left for the page to fix', '<h1>A</h1><h1>B</h1>' === db_hyg_single_h1( '<h1>A</h1><h1>B</h1>' ) );

// ---- llms.txt and security.txt
$llms = db_hyg_llms_txt( 'https://example.test' );
check( 'llms.txt starts with a title', 0 === strpos( $llms, "# Domain Brothers\n" ) );
check( 'llms.txt links use the site address', false !== strpos( $llms, '](https://example.test/all-domains/)' ) );
check( 'llms.txt lists the tools and glossary', false !== strpos( $llms, '/domain-tools/' ) && false !== strpos( $llms, '/domain-glossary/' ) );
$sec = db_hyg_security_txt( 'https://example.test/', 1700000000 );
check( 'security.txt has contact and expiry', false !== strpos( $sec, 'Contact: mailto:' ) && preg_match( '/^Expires: 20\d\d-\d\d-\d\dT\d\d:\d\d:\d\dZ$/m', $sec ) );
check( 'security.txt expiry is a year out', false !== strpos( $sec, 'Expires: 2024-11-' ) );
check( 'security.txt canonical path', false !== strpos( $sec, 'Canonical: https://example.test/.well-known/security.txt' ) );

// ---- 404 page
$body = db_hyg_404_body( 'https://example.test/', '<form></form>' );
check( '404 has one h1 and the search form', 1 === substr_count( $body, '<h1' ) && false !== strpos( $body, '<form></form>' ) );
check( '404 links are absolute and escaped', false !== strpos( $body, 'href="https://example.test/all-domains/"' ) );
check( '404 offers six places to go', 6 === substr_count( $body, '<li>' ) );

// ---- IndexNow
function random_bytes_stub() {}
$k = db_hyg_indexnow_key();
check( 'IndexNow key is 32 hex characters', 1 === preg_match( '/^[a-f0-9]{32}$/', $k ) );
check( 'IndexNow key is stable', $k === db_hyg_indexnow_key() );
$p = db_hyg_indexnow_payload( 'example.test', $k, array( 'https://example.test/a/', 'https://example.test/a/', 'https://evil.test/x/', 'http://example.test/b/', 'not a url' ) );
check( 'payload keeps only https URLs on this host, once each', array( 'https://example.test/a/' ) === $p['urlList'] );
check( 'payload key location is on the host', 'https://example.test/' . $k . '.txt' === $p['keyLocation'] );

// ---- retention
check( 'retention cutoff is 730 days back', '2024-11-' === substr( db_hyg_retention_cutoff( 1795000000 ), 0, 8 ) || '2024-12-' === substr( db_hyg_retention_cutoff( 1795000000 ), 0, 8 ) );

// ---- viewport zoom
check( 'viewport: maximum-scale and user-scalable removed', '<meta name="viewport" content="width=device-width, initial-scale=1">' === db_hyg_viewport_fix( '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">' ) );
check( 'viewport: user-scalable=0 removed', false === stripos( db_hyg_viewport_fix( '<meta name="viewport" content="width=device-width,user-scalable=0">' ), 'user-scalable' ) );
check( 'viewport: a clean tag is untouched', '<meta name="viewport" content="width=device-width,initial-scale=1">' === db_hyg_viewport_fix( '<meta name="viewport" content="width=device-width,initial-scale=1">' ) );
check( 'viewport: non-strings pass through', null === db_hyg_viewport_fix( null ) );

check( 'iframe: GTM noscript frame gets a title', false !== strpos( db_hyg_iframe_titles( '<iframe src="https://www.googletagmanager.com/ns.html?id=X" height="0">' ), 'title="Google Tag Manager"' ) );
check( 'iframe: other untitled frames get a generic title', '<iframe title="Embedded content" src="x">' === db_hyg_iframe_titles( '<iframe src="x">' ) );
check( 'iframe: an existing title is kept', '<iframe title="Map" src="x">' === db_hyg_iframe_titles( '<iframe title="Map" src="x">' ) );
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
