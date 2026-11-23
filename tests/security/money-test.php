<?php
/**
 * Offline tests for db_money_parse() in db-crm-block.php (bug register batch 2, AC-10).
 *
 *     php tests/security/money-test.php
 */
$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/db-custom-blocks/db-crm-block.php' );
preg_match( "#if \( ! function_exists\( 'db_money_parse' \) \) \{.*?\n\}\n#s", $src, $m );
eval( '?><?php ' . $m[0] ); // phpcs:ignore Squiz.PHP.Eval

$pass = 0; $fail = 0;
function check( $name, $ok ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}
foreach ( array( '15000' => 15000.0, '$15,000' => 15000.0, '15,000.50' => 15000.5, ' 2500 ' => 2500.0, '1,234,567' => 1234567.0, 'US$900' => 900.0, '750 USD' => 750.0, '99.9' => 99.9 ) as $in => $want ) {
	check( "accepts '$in'", $want === db_money_parse( $in ) );
}
foreach ( array( '15k', '1.500', '2,5', '1,50', '12,34,567', 'abc', '', '-500', '1e5', '15000.505', array( '1' ) ) as $in ) {
	check( 'refuses ' . var_export( $in, true ), null === db_money_parse( $in ) );
}
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
