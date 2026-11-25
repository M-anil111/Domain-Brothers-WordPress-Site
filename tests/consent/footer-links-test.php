<?php
/** Offline tests for the footer legal row (db-footer-links-block.php). Run: php tests/consent/footer-links-test.php */

define( 'ABSPATH', '/' );
function add_action() {}
function apply_filters( $t, $v ) { return $v; }
function esc_url( $u ) { return htmlspecialchars( $u, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
require __DIR__ . '/../../db-custom-blocks/db-footer-links-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$links = array(
	array( 'Privacy Policy', 'https://example.test/privacy-policy/' ),
	array( 'Cookie Policy', 'https://example.test/cookie-policy/' ),
	array( 'Trademark & Copyright', 'https://example.test/trademark-and-copyright-notices/' ),
);
$page = '<html><body><header><a href="/privacy-policy/">Privacy</a></header><main></main>'
	. '<footer class="site"><a href="https://example.test/privacy-policy">Privacy policy</a></footer></body></html>';
$out = db_footer_links_inject( $page, $links, true );

check( 'row added inside the footer', false !== strpos( $out, '<nav id="db-legal-links"' ) && strpos( $out, 'db-legal-links' ) < strpos( $out, '</footer>' ) && strpos( $out, 'db-legal-links' ) > strpos( $out, '<footer' ) );
check( 'policy already in the footer not repeated', 1 === substr_count( $out, 'privacy-policy' ) - 1 );
check( 'missing policies added', false !== strpos( $out, 'href="https://example.test/cookie-policy/"' ) && false !== strpos( $out, 'Trademark &amp; Copyright' ) );
check( 'Cookie settings link reopens the banner', false !== strpos( $out, 'data-db-consent-open' ) );
check( 'no Cookie settings when consent is off', false === strpos( db_footer_links_inject( $page, $links, false ), 'data-db-consent-open' ) );
check( 'added once only', $out === db_footer_links_inject( $out, $links, true ) );
check( 'page without a footer unchanged', '<html><body>x</body></html>' === db_footer_links_inject( '<html><body>x</body></html>', $links, true ) );
check( 'nothing to add: unchanged', $page === db_footer_links_inject( $page, array( $links[0] ), false ) );
$two = '<footer><a href="/cookie-policy/">c</a></footer><div></div><footer id="last"></footer>';
check( 'goes into the last footer, dedupes against that footer only', false !== strpos( db_footer_links_inject( $two, $links, false ), '<footer id="last"><nav id="db-legal-links"' ) && false !== strpos( db_footer_links_inject( $two, $links, false ), 'example.test/cookie-policy/' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
