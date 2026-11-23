<?php
/**
 * Offline tests for the DNS display helper in db-domain-lookup-block.php (bug register batch 2, TL-2).
 *
 *     php tests/domain/lookup-dns-test.php
 */
$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/db-custom-blocks/db-domain-lookup-block.php' );
if ( ! preg_match( "#if \( ! function_exists\( 'db_lk_display_rows' \) \) \{.*?\n\}\n#s", $src, $m ) ) {
	echo "FAIL helper not found\n";
	exit( 1 );
}
eval( '?>' . '<?php ' . $m[0] ); // phpcs:ignore Squiz.PHP.Eval
if ( ! preg_match( "#if \\( ! function_exists\\( 'db_lk_dns_state_of' \\) \\) \\{.*?\n\\}\n#s", $src, $m2 ) ) {
	echo "FAIL dns helper not found\n";
	exit( 1 );
}
eval( '?>' . '<?php ' . $m2[0] ); // phpcs:ignore Squiz.PHP.Eval
preg_match( "#if \\( ! function_exists\\( 'db_lk_ip_public' \\) \\) \\{.*?\n\\}\n#s", $src, $m3 );
eval( '?>' . '<?php ' . $m3[0] ); // phpcs:ignore Squiz.PHP.Eval

$pass = 0; $fail = 0;
function check( $name, $ok ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}

$shown = db_lk_display_rows( range( 1, 12 ), 10 );
check( '10 rows shown plus "and 2 more"', 11 === count( $shown ) && 'and 2 more' === end( $shown ) );
check( 'short answers are unchanged', array( 'a', 'b' ) === db_lk_display_rows( array( 'a', 'b' ), 10 ) );
check( 'SPF is searched before the cut', false !== strpos( $src, "if ( 'TXT' === \$type ) {" ) && strpos( $src, "\$out['SPF'] = (bool) array_filter( \$rows" ) < strpos( $src, "\$out[ \$type ] = db_lk_display_rows( \$rows, 10 );" ) );

check( 'NXDOMAIN means no registration (TL-1)', 'nx' === db_lk_dns_state_of( array( 'Status' => 3 ) ) );
check( 'a delegated name exists', 'exists' === db_lk_dns_state_of( array( 'Status' => 0, 'Answer' => array( array( 'type' => 2 ) ) ) ) );
check( 'SERVFAIL, empty or missing answers are unknown', 'unknown' === db_lk_dns_state_of( array( 'Status' => 2 ) ) && 'unknown' === db_lk_dns_state_of( null ) && 'unknown' === db_lk_dns_state_of( array( 'Status' => 0 ) ) );

$bad = array( '127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.1.1', '0.1.2.3', '::1', 'fe80::1', 'fd00::1', '::ffff:10.0.0.1', '64:ff9b::a00:1' );
$good = array( '8.8.8.8', '104.16.1.1', '2606:4700::1111' );
check( 'SSRF: private, reserved, link-local, CGNAT and mapped addresses are refused (TL-15)', ! array_filter( $bad, 'db_lk_ip_public' ) );
check( 'SSRF: public addresses are allowed', count( array_filter( $good, 'db_lk_ip_public' ) ) === count( $good ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
