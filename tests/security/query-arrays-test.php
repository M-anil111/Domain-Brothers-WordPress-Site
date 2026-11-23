<?php
/**
 * Offline test: every $_GET/$_REQUEST key the plugin reads is protected from array values (SC-8).
 *
 *     php tests/security/query-arrays-test.php
 */
$dir = dirname( __DIR__, 2 ) . '/db-custom-blocks';
$src = (string) file_get_contents( $dir . '/db-custom-blocks.php' );
preg_match( "#function db_drop_array_query_values.*?\n\t}\n#s", $src, $m );
eval( 'function db_drop_array_query_values' . substr( $m[0], strlen( 'function db_drop_array_query_values' ) ) ); // phpcs:ignore Squiz.PHP.Eval
preg_match( "#function db_scalar_query_keys\(\) \{\s*return (array\(.*?\));#s", $src, $k );
$keys = eval( 'return ' . $k[1] . ';' ); // phpcs:ignore Squiz.PHP.Eval

$pass = 0; $fail = 0;
function check( $name, $ok ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}

$used = array();
foreach ( array_merge( glob( $dir . '/*.php' ), glob( $dir . '/designer/*.php' ), glob( $dir . '/pagecache/*.php' ) ) as $f ) {
	preg_match_all( "#\\\$_(?:GET|REQUEST)\[\s*'([a-z_0-9-]+)'\s*\]#", (string) file_get_contents( $f ), $mm );
	$used = array_merge( $used, $mm[1] );
}
$missing = array_diff( array_unique( $used ), $keys );
check( 'every query key the plugin reads is guarded' . ( $missing ? ' (missing: ' . implode( ', ', $missing ) . ')' : '' ), ! $missing );
$q = db_drop_array_query_values( array( 'd' => array( 'x' ), 't' => '1', 'other' => array( 1 ) ), array( 'd', 't' ) );
check( 'array values are dropped, scalars and other keys kept', ! isset( $q['d'] ) && '1' === $q['t'] && array( 1 ) === $q['other'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
