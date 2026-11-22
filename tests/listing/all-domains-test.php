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
$ls = end( $d['meta_query'] );
check( 'names the plugin marks sold or not for sale are left out', 'db_listing_state' === $ls[0]['key'] && array( 'sold', 'not_for_sale' ) === $ls[0]['value'] && 'NOT IN' === $ls[0]['compare'] && 'NOT EXISTS' === $ls[1]['compare'] );
check( 'without the index no post__in is set', ! isset( $d['post__in'] ) );
$ix = db_ad_fix_query_vars( $default, array( 5, '7', 5 ) );
check( 'with the index the list is limited to the names for sale', array( 5, 7 ) === $ix['post__in'] );
$ex = db_ad_fix_query_vars( array_merge( $default, array( 'post__in' => array( 7, 9 ) ) ), array( 5, 7 ) );
check( 'an existing post__in is narrowed to names for sale', array( 7 ) === $ex['post__in'] );
$none = db_ad_fix_query_vars( array_merge( $default, array( 'post__in' => array( 9 ) ) ), array( 5 ) );
check( 'no overlap gives an empty list, not every post', array( 0 ) === $none['post__in'] );

$p = db_ad_fix_query_vars( $price );
$keys = array_map( static function ( $c ) { return $c['key'] ?? 'group'; }, $p['meta_query'] );
check( 'price view drops the Domain_owner condition (seller names count)', ! in_array( 'Domain_owner', $keys, true ) );
check( 'price view keeps its price and status conditions, adds no second status rule, adds the listing-state rule', array( 'domain_price', 'domain_status', 'group' ) === $keys && 'db_listing_state' === end( $p['meta_query'] )[0]['key'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
