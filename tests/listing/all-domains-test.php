<?php
/** Offline tests for the All Domains query fix in db-domain-filter-block.php. Run: php tests/listing/all-domains-test.php */

define( 'ABSPATH', '/' );
define( 'HOUR_IN_SECONDS', 3600 );
function add_action() {}
function add_filter() {}
require __DIR__ . '/../../db-custom-blocks/db-domain-filter-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$default = array( 'post_status' => 'publish', 'post_type' => 'domain', 'paged' => 2, 'orderby' => 'domain-type', 'order' => 'ASC', 'posts_per_page' => 100 );
$price   = array( 'post_type' => 'domain', 'paged' => 1, 'orderby' => 'domain_price', 'meta_query' => array(
	array( 'key' => 'domain_price', 'compare' => 'BETWEEN', 'value' => array( 0, 2500 ), 'type' => 'numeric' ),
	array( 'key' => 'domain_status', 'value' => 'sale' ),
	array( 'key' => 'Domain_owner', 'value' => 'Jay Mehta' ),
) );
$widget  = array( 'post_type' => 'domain', 'posts_per_page' => 6 );

check( 'the theme default list is recognised', db_ad_is_theme_query( $default ) );
check( 'the theme price view is recognised', db_ad_is_theme_query( $price ) );
check( 'a small widget query is left alone', ! db_ad_is_theme_query( $widget ) );

$d = db_ad_fix_query_vars( $default );
check( 'default list loads every name (1000), first page only', 1000 === $d['posts_per_page'] && 1 === $d['paged'] );
check( 'default list leaves out sold names', 'sold' === $d['meta_query'][0][0]['value'] && '!=' === $d['meta_query'][0][0]['compare'] && 'NOT EXISTS' === $d['meta_query'][0][1]['compare'] );
check( 'default list keeps its order', 'domain-type' === $d['orderby'] && 'ASC' === $d['order'] );

$p = db_ad_fix_query_vars( $price );
$keys = array_map( static function ( $c ) { return $c['key'] ?? 'group'; }, $p['meta_query'] );
check( 'price view drops the Domain_owner condition (seller names count)', ! in_array( 'Domain_owner', $keys, true ) );
check( 'price view keeps its price and status conditions, adds no second status rule', array( 'domain_price', 'domain_status' ) === $keys );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
