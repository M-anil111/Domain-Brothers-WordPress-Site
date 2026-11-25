<?php
/**
 * Offline test: links to merged or old service addresses are rewritten to the current page
 * (db-custom-blocks/db-service-redirects-block.php).
 *
 *     php tests/seo/service-redirect-links-test.php
 */
define( 'ABSPATH', __DIR__ );
function add_action() {}
function add_filter() {}
function apply_filters( $h, $v ) { return $v; }
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-service-redirects-block.php';

$pass = 0; $fail = 0;
function check( $name, $ok ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}
$map = array( 'buy-domains-service' => 'acquire-premium-domain', 'other-services' => 'web-hosting-maintenance-services' );
$r   = function ( $h ) use ( $map ) { return db_service_redirect_rewrite_links( $h, $map, 'https://site.test' ); };

check( 'absolute link rewritten', '<a href="https://site.test/acquire-premium-domain/">x</a>' === $r( '<a href="https://site.test/buy-domains-service/">x</a>' ) );
check( 'relative link without slash', '<a href="/acquire-premium-domain/">x</a>' === $r( '<a href="/buy-domains-service">x</a>' ) );
check( 'query string kept', "<a href='/web-hosting-maintenance-services/?a=1'>x</a>" === $r( "<a href='/other-services/?a=1'>x</a>" ) );
check( 'fragment kept', '<a href="/acquire-premium-domain/#faq">x</a>' === $r( '<a href="/buy-domains-service/#faq">x</a>' ) );
check( 'longer slug untouched', '<a href="/buy-domains-service-old/">x</a>' === $r( '<a href="/buy-domains-service-old/">x</a>' ) );
check( 'other site untouched', '<a href="https://other.test/buy-domains-service/">x</a>' === $r( '<a href="https://other.test/buy-domains-service/">x</a>' ) );
check( 'deeper path untouched', '<a href="/buy-domains-service/page/2/">x</a>' === $r( '<a href="/buy-domains-service/page/2/">x</a>' ) );
check( 'text mentions untouched', 'see /buy-domains-service/ here' === $r( 'see /buy-domains-service/ here' ) );
check( 'redirect map has the merge', 'acquire-premium-domain' === ( db_service_redirect_map()['buy-domains-service'] ?? '' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
