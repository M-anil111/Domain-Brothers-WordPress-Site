<?php
/** Offline tests for the similar-names scorer in db-domain-tools-block.php. Run: php tests/hygiene/similar-test.php */

function wp_list_pluck( array $list, $field ) { return array_map( function ( $i ) use ( $field ) { return $i[ $field ]; }, $list ); }

$src   = file_get_contents( __DIR__ . '/../../db-custom-blocks/db-domain-tools-block.php' );
$start = strpos( $src, "if ( ! function_exists( 'db_sim_score' ) )" );
$end   = strpos( $src, "if ( ! function_exists( 'db_sim_html' ) )" );
$rs    = strpos( $src, "if ( ! function_exists( 'db_rsold_pick' ) )" );
$re    = strpos( $src, "if ( ! function_exists( 'db_rsold_html' ) )" );
if ( false === $start || false === $end || $end <= $start ) {
	echo "FAIL could not locate scorer code\n";
	exit( 1 );
}
eval( substr( $src, $start, $end - $start ) );
if ( false === $rs || $re <= $rs ) {
	echo "FAIL could not locate sold-strip code\n";
	exit( 1 );
}
eval( substr( $src, $rs, $re - $rs ) );

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

$rows = array(
	array( 'id' => 1, 'name' => 'old.com', 'at' => 100 ),
	array( 'id' => 2, 'name' => 'new.com', 'at' => 300 ),
	array( 'id' => 3, 'name' => 'NEW.com', 'at' => 200 ),
	array( 'id' => 4, 'name' => 'undated.com', 'at' => 0 ),
	array( 'id' => 5, 'name' => 'undated2.com', 'at' => 0 ),
);
$r = db_rsold_pick( $rows, 4 );
check( 'sold strip: newest sale first', 'new.com' === $r[0]['name'] );
check( 'sold strip: duplicate names collapse', 4 === count( $r ) && 1 === count( array_filter( wp_list_pluck( $r, 'name' ), function ( $n ) { return 'new.com' === strtolower( $n ); } ) ) );
check( 'sold strip: undated sort by id desc after dated', 'undated2.com' === $r[2]['name'] || 'old.com' === $r[1]['name'] );
check( 'sold strip: limit respected', 2 === count( db_rsold_pick( $rows, 2 ) ) && array() === db_rsold_pick( $rows, 0 ) );
check( 'sold strip: blank names skipped', array() === db_rsold_pick( array( array( 'id' => 1, 'name' => ' ', 'at' => 1 ) ), 3 ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
