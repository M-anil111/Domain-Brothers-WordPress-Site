<?php
/** Offline tests for the similar-names scorer in db-domain-tools-block.php. Run: php tests/hygiene/similar-test.php */

function wp_list_pluck( array $list, $field ) { return array_map( function ( $i ) use ( $field ) { return $i[ $field ]; }, $list ); }

$src   = file_get_contents( __DIR__ . '/../../db-custom-blocks/db-domain-tools-block.php' );
$start = strpos( $src, "if ( ! function_exists( 'db_sim_score' ) )" );
$end   = strpos( $src, "if ( ! function_exists( 'db_sim_html' ) )" );
if ( false === $start || false === $end || $end <= $start ) {
	echo "FAIL could not locate scorer code\n";
	exit( 1 );
}
eval( substr( $src, $start, $end - $start ) );

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }
function d( $name, $price, $cats = array() ) {
	$dot = strpos( $name, '.' );
	return array( 'name' => $name, 'label' => substr( $name, 0, $dot ), 'ext' => substr( $name, $dot ), 'len' => $dot, 'price' => $price, 'cats' => $cats );
}

$cur = d( 'bluesky.com', 5000, array( 'Tech' ) );
check( 'same name scores -1', -1.0 === db_sim_score( $cur, d( 'BLUESKY.com', 5000 ) ) );
check( 'same extension beats different', db_sim_score( $cur, d( 'redsky.com', 5000 ) ) > db_sim_score( $cur, d( 'redsky.ca', 5000 ) ) );
check( 'closer price scores higher', db_sim_score( $cur, d( 'redsky.com', 5200 ) ) > db_sim_score( $cur, d( 'redsky.com', 90000 ) ) );
check( 'shared category adds score', db_sim_score( $cur, d( 'redsky.com', 5000, array( 'tech' ) ) ) > db_sim_score( $cur, d( 'redsky.com', 5000 ) ) );
check( 'zero price does not divide by zero', is_float( db_sim_score( $cur, d( 'redsky.com', 0 ) ) ) );

$c = array( d( 'a1.com', 5000 ), d( 'bluesky.com', 5000 ), d( 'zz.xyz', 1 ), d( 'greensky.com', 5100, array( 'Tech' ) ), d( 'graysky.com', 5100, array( 'Tech' ) ), d( 'pinksky.com', 4900 ) );
$p = db_sim_pick( $cur, $c, 3 );
check( 'pick returns at most n', 3 === count( $p ) );
check( 'pick excludes the current name', ! in_array( 'bluesky.com', wp_list_pluck( $p, 'name' ), true ) );
check( 'category matches rank first', 'graysky.com' === $p[0]['name'] && 'greensky.com' === $p[1]['name'] );
check( 'weak matches are dropped', array() === db_sim_pick( $cur, array( d( 'zz.xyz', 1 ) ), 6 ) );
check( 'output is stable across input order', wp_list_pluck( db_sim_pick( $cur, array_reverse( $c ), 3 ), 'name' ) === wp_list_pluck( $p, 'name' ) );
check( 'n of zero returns nothing', array() === db_sim_pick( $cur, $c, 0 ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
