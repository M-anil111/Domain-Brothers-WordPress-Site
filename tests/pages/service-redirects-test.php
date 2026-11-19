<?php
/** Offline tests for db_service_redirect_target(). Run: php tests/pages/service-redirects-test.php */
define( 'ABSPATH', '/' );
function add_action() {}
function add_filter() {}
function apply_filters( $t, $v ) { return $v; }
require __DIR__ . '/../../db-custom-blocks/db-service-redirects-block.php';
$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }
$m = db_service_redirect_map();
check( 'digital-marketing', 'digital-marketing-services' === db_service_redirect_target( '/digital-marketing/', $m ) );
check( 'no trailing slash, any case', 'custom-software-development-services' === db_service_redirect_target( '/Software-Development', $m ) );
check( 'mobile app', 'mobile-app-development-services' === db_service_redirect_target( '/mobile-app-development/', $m ) );
check( 'other services', 'web-hosting-maintenance-services' === db_service_redirect_target( '/other-services/', $m ) );
check( 'current page never redirects', '' === db_service_redirect_target( '/digital-marketing-services/', $m ) );
check( 'child path not matched', '' === db_service_redirect_target( '/blog/digital-marketing/', $m ) );
check( 'home page not matched', '' === db_service_redirect_target( '/', $m ) );
check( 'subdirectory install', 'digital-marketing-services' === db_service_redirect_target( '/wp/digital-marketing/', $m, '/wp' ) && '' === db_service_redirect_target( '/digital-marketing/', $m, '/wp' ) );
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
