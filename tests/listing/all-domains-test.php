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

$b = 'https://example.test/domains/';
$rows = "<table><tr><td><a href='{$b}sold-com/'>sold.com</a></td><td><a href='{$b}sold-com/'>Make an Offer</a></td></tr>"
	. "<tr><td><a href='{$b}ezshop-ca/'>ezshop.ca</a></td><td><a href='{$b}slykar-com/'>Make an Offer</a></td></tr>"
	. "<tr><td><a href=\"{$b}good-com/\">good.com</a></td><td><a href=\"{$b}good-com/\">Buy</a></td></tr><tr><th>Name</th></tr></table>";
$out = db_ad_clean_rows_html( $rows, array( 'sold-com' ), $b );
check( 'home rows: a sold name\'s row is removed', false === strpos( $out, 'sold-com' ) );
check( 'home rows: an offer button pointing at another listing is repointed to its own row (LS-2)', false === strpos( $out, 'slykar-com' ) && 2 === substr_count( $out, 'ezshop-ca' ) );
check( 'home rows: correct rows and header rows are unchanged', false !== strpos( $out, "<tr><td><a href=\"{$b}good-com/\">good.com</a></td><td><a href=\"{$b}good-com/\">Buy</a></td></tr><tr><th>Name</th></tr>" ) );
check( 'home rows: front-page query keeps its own page size', 8 === db_ad_only_for_sale_vars( array( 'post_type' => 'domain', 'posts_per_page' => 8 ), array( 1, 2 ) )['posts_per_page'] && array( 1, 2 ) === db_ad_only_for_sale_vars( array( 'posts_per_page' => 8 ), array( 1, 2 ) )['post__in'] );

check( 'LS-6: the category heading shows the for-sale count', false !== strpos( db_ad_heading_count_html( '<h1 class="page-title" style="x">Business Names &mdash; <span>98 Domains</span></h1>', 76 ), '<span>76 Domains</span>' ) && false !== strpos( db_ad_heading_count_html( '<h1 class="page-title">X <span>3 Domains</span></h1>', 1 ), '<span>1 Domain</span>' ) );
$side = '<td colspan="6"><a href="https://e.test/?s=.ca&post_type=domain" class="">.Ca</a></td><td colspan="6"><a href="https://e.test/?s=.net&post_type=domain" class="">.Net</a></td><td colspan="6"><a href="https://e.test/3-character-domains/" class="">3 Characters</a></td><td><a href="https://e.test/5-character-domains/">5</a></td>';
$out  = db_ad_sidebar_html( $side, array( '.ca' => 40 ), array( '/3-character-domains/' ), 'https://e.test/all-domains/' );
check( 'LS-8: TLD links go to the filtered list with a count; sold-only extensions and empty lists are blanked', false !== strpos( $out, 'href="https://e.test/all-domains/?tld=.ca">.ca (40)</a>' ) && false === strpos( $out, '.net' ) && false === strpos( $out, '3-character' ) && false !== strpos( $out, '5-character-domains' ) );
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
