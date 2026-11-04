<?php
/**
 * Offline test of the EPP code handling in db-sold-block.php: encryption at
 * rest, tamper detection, and which codes are accepted.
 *
 *     php tests/sold/epp-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
function add_action() {}
function add_filter() {}
function add_query_arg( $k, $v = null, $u = '' ) { return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( array( $k => $v ) ); }
function home_url( $p = '/' ) { return 'https://beta.example.test' . $p; }
function wp_salt( $s = 'auth' ) { return 'test-salt-' . $s; }
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-sold-block.php';

$fails = 0;
function ok( $cond, $label ) { global $fails; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fails++; } }

$code = 'A!b#9 x';
$enc  = db_epp_encrypt( 'QA-epp-9X7!k#2' );
ok( is_string( $enc ) && false === strpos( $enc, 'QA-epp' ), 'the code is not stored in clear text' );
ok( 'QA-epp-9X7!k#2' === db_epp_decrypt( $enc ), 'it decrypts to the exact code, symbols included' );
ok( db_epp_encrypt( 'same' ) !== db_epp_encrypt( 'same' ), 'the same code encrypts differently each time (random IV)' );
$raw = base64_decode( $enc ); $raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
ok( false === db_epp_decrypt( base64_encode( $raw ) ), 'a modified ciphertext is rejected, not decrypted to garbage' );
ok( false === db_epp_decrypt( 'not base64 !!' ) && false === db_epp_decrypt( '' ) && false === db_epp_decrypt( base64_encode( 'short' ) ), 'junk input is rejected' );

ok( 'Ab-9!x#zQ' === db_epp_clean_code( '  Ab-9!x#zQ ' ), 'surrounding spaces are trimmed, symbols kept' );
ok( null === db_epp_clean_code( '' ) && null === db_epp_clean_code( '   ' ), 'an empty code is refused' );
ok( null === db_epp_clean_code( 'abc def' ) && null === db_epp_clean_code( "abc\ndef" ) && null === db_epp_clean_code( "ab\x00c" ), 'a code with spaces, new lines or control characters is refused' );
ok( null === db_epp_clean_code( str_repeat( 'a', 121 ) ) && null !== db_epp_clean_code( str_repeat( 'a', 120 ) ), 'at most 120 characters' );
ok( 'x' . 'y' === db_epp_clean_code( 'xy' ), 'a short code is accepted' );

$u = db_epp_url( str_repeat( 'a', 32 ) );
ok( false !== strpos( $u, 'db_epp=' . str_repeat( 'a', 32 ) ), 'the private link carries only the token' );

echo "\n" . ( $fails ? "{$fails} failed" : 'all passed' ) . "\n";
exit( $fails ? 1 : 0 );
