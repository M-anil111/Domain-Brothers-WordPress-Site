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
$ques = '<div class="ques_title">' . "\n" . '<h1>Questions? Email today</h1></div><main><h1 class="db-plan-h1">Choose your plan</h1></main>';
$qfix = db_hyg_single_h1( $ques );
check( 'the theme ques_title h1 becomes a paragraph when the page has its own h1', 1 === substr_count( $qfix, '<h1' ) && false !== strpos( $qfix, '<p class="db-ques-h">Questions? Email today</p>' ) );
$qonly = '<div class="ques_title"><h1>Questions?</h1></div><h2>Other</h2>';
check( 'a ques_title h1 that is the only h1 is kept', $qonly === db_hyg_single_h1( $qonly ) );

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
$hl = db_hyg_heading_levels( '<header><h4>x</h4></header><main><h1>A</h1><h2>B</h2><h4>K</h4><h4>J</h4><h3>More</h3><h2>C</h2><h3>D</h3></main>' );
check( 'headings: a skipped level gets aria-level, siblings match', false !== strpos( $hl, '<h4 aria-level="3">K</h4><h4 aria-level="3">J</h4><h3>More</h3>' ) );
check( 'headings: correct levels and headings outside main are untouched', false !== strpos( $hl, '<header><h4>x</h4></header>' ) && false !== strpos( $hl, '<h2>C</h2><h3>D</h3>' ) && false === strpos( $hl, '<h1 aria' ) );
check( 'headings: h1 then h3 becomes level 2', false !== strpos( db_hyg_heading_levels( '<main><h1>O</h1><h3>e</h3><h3>w</h3><h2>H</h2></main>' ), '<h3 aria-level="2">e</h3><h3 aria-level="2">w</h3><h2>H</h2>' ) );
check( 'headings: no main, no change', '<h1>a</h1><h4>b</h4>' === db_hyg_heading_levels( '<h1>a</h1><h4>b</h4>' ) );
// ---- duplicate Google tags (O-1): snippets as printed by the theme, Site Kit and Rank Math
$theme_gtm = "<script type=\"text/db-delay\">(function(w, d, s, l, i) { j.src ='https://www.googletagmanager.com/gtm.js?id=' + i + dl; })(window, document, 'script', 'dataLayer', 'GTM-W7B64D4');</script>\n";
$sk_gtag   = "<script type=\"text/db-delay\" id=\"google_gtagjs-js\" data-src=\"https://www.googletagmanager.com/gtag/js?id=G-56DWCMLT8V\" async></script>\n";
$sk_after  = "<script id=\"google_gtagjs-js-after\">\nwindow.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}\ngtag(\"js\", new Date());\ngtag(\"config\", \"G-56DWCMLT8V\");\n window._googlesitekit = {};\n</script>\n";
$rm_gtag   = "<script async id=\"google_gtagjs\" data-src=\"https://www.googletagmanager.com/gtag/js?id=G-56DWCMLT8V\"></script>\n";
$rm_inline = "<script id=\"google_gtagjs-inline\">\nwindow.dataLayer = window.dataLayer || [];function gtag(){dataLayer.push(arguments);}gtag('js', new Date());gtag('config', 'G-56DWCMLT8V', {} );\n</script>\n";
$sk_gtm    = "<script>\n( function( w, d, s, l, i ) { j.src = 'https://www.googletagmanager.com/gtm.js?id=' + i + dl; } )( window, document, 'script', 'dataLayer', 'GTM-W7B64D4' );\n</script>\n";
$ns        = '<noscript><iframe title="Google Tag Manager" src="https://www.googletagmanager.com/ns.html?id=GTM-W7B64D4" height="0" width="0" style="display:none"></iframe></noscript>';
$other     = "<script>var x = 1;</script>\n";
$page      = "<html><head>$theme_gtm$sk_gtag$sk_after$rm_gtag$rm_inline$sk_gtm$other</head><body>$ns<p>x</p>$ns</body></html>";
$d         = db_hyg_dedupe_tags( $page );
check( 'tags: GTM container loads once', 1 === substr_count( $d, "gtm.js?id=" ) && false !== strpos( $d, $theme_gtm ) );
check( 'tags: gtag.js for the GA4 id loads once (Site Kit copy kept)', 1 === substr_count( $d, 'gtag/js?id=G-56DWCMLT8V' ) && false !== strpos( $d, $sk_gtag ) );
check( 'tags: GA4 configured once (Site Kit copy with its event helper kept)', 1 === preg_match_all( "/gtag\\(\\s*['\"]config/", $d ) && false !== strpos( $d, '_googlesitekit' ) );
check( 'tags: GTM noscript frame once', 1 === substr_count( $d, 'ns.html?id=GTM-W7B64D4' ) );
check( 'tags: unrelated scripts untouched', false !== strpos( $d, $other ) && false !== strpos( $d, '<p>x</p>' ) );
check( 'tags: a second, different GA4 id is kept', 2 === substr_count( db_hyg_dedupe_tags( $sk_gtag . str_replace( 'G-56DWCMLT8V', 'G-OTHER123', $sk_gtag ) ), 'gtag/js?id=' ) );
check( 'tags: a page without Google tags is returned as is', '<html><p>a</p></html>' === db_hyg_dedupe_tags( '<html><p>a</p></html>' ) );
check( 'tags: running twice changes nothing more', db_hyg_dedupe_tags( $d ) === $d );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
