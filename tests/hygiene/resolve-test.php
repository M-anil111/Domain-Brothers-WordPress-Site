<?php
/** Offline tests for db-resolve-tools-block.php. Run: php tests/hygiene/resolve-test.php */

define( 'ABSPATH', '/' );
function add_action() {}
require __DIR__ . '/../../db-custom-blocks/db-resolve-tools-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

// ---- DNS answers
$cf = array( 'Status' => 0, 'Answer' => array( array( 'type' => 1, 'TTL' => 300, 'data' => '93.184.216.34' ), array( 'type' => 1, 'TTL' => 120, 'data' => '93.184.216.35' ) ) );
$p  = db_rs_parse( $cf, 'A' );
check( 'answers are read', array( '93.184.216.34', '93.184.216.35' ) === $p['answers'] && $p['ok'] );
check( 'lowest TTL is reported', 120 === $p['ttl'] );
$chain = array( 'Status' => 0, 'Answer' => array( array( 'type' => 5, 'TTL' => 60, 'data' => 'real.example.' ), array( 'type' => 1, 'TTL' => 30, 'data' => '1.2.3.4' ) ) );
check( 'CNAME hop is skipped when asking for A', array( '1.2.3.4' ) === db_rs_parse( $chain, 'A' )['answers'] );
$txt = db_rs_parse( array( 'Status' => 0, 'Answer' => array( array( 'type' => 16, 'TTL' => 10, 'data' => '"v=spf1 -all"' ) ) ), 'TXT' );
check( 'TXT quotes are trimmed', array( 'v=spf1 -all' ) === $txt['answers'] );
$nx = db_rs_parse( array( 'Status' => 3 ), 'A' );
check( 'NXDOMAIN is reported in words', ! $nx['ok'] && 'Name does not exist' === $nx['status'] );
check( 'garbage input does not fail', ! db_rs_parse( null, 'A' )['ok'] && ! db_rs_parse( array( 'Status' => 0 ), 'BOGUS' )['ok'] );
check( 'a no-record answer is still ok', db_rs_parse( array( 'Status' => 0 ), 'MX' )['ok'] && array() === db_rs_parse( array( 'Status' => 0 ), 'MX' )['answers'] );
check( 'answers are capped at 12', 12 === count( db_rs_parse( array( 'Status' => 0, 'Answer' => array_fill( 0, 30, array( 'type' => 1, 'TTL' => 1, 'data' => '1.1.1.1' ) ) ), 'A' )['answers'] ) );

// ---- agreement between resolvers
$a = array( 'ok' => true, 'status' => 'OK', 'answers' => array( '1.1.1.1', '2.2.2.2' ), 'ttl' => 60 );
$b = array( 'ok' => true, 'status' => 'OK', 'answers' => array( '2.2.2.2', '1.1.1.1' ), 'ttl' => 30 );
$c = array( 'ok' => true, 'status' => 'OK', 'answers' => array( '3.3.3.3' ), 'ttl' => 30 );
$x = array( 'ok' => false, 'status' => 'Did not answer', 'answers' => array(), 'ttl' => null );
check( 'same records in any order agree', db_rs_summary( array( 'a' => $a, 'b' => $b ) )['agree'] );
check( 'different records disagree', ! db_rs_summary( array( 'a' => $a, 'c' => $c ) )['agree'] );
check( 'a failed resolver is ignored', db_rs_summary( array( 'a' => $a, 'b' => $b, 'x' => $x ) )['agree'] );
check( 'one answer means nothing to compare', false !== strpos( db_rs_summary( array( 'a' => $a, 'x' => $x ) )['text'], 'nothing to compare' ) );
check( 'no answers is stated', false !== strpos( db_rs_summary( array( 'x' => $x ) )['text'], 'No resolver' ) );

// ---- certificate decoder
$key = openssl_pkey_new( array( 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ) );
$csr = openssl_csr_new( array( 'commonName' => 'test.example', 'organizationName' => 'Test Org' ), $key, array( 'digest_alg' => 'sha256' ) );
$crt = openssl_csr_sign( $csr, null, $key, 30, array( 'digest_alg' => 'sha256' ) );
openssl_x509_export( $crt, $pem );
$r = db_rs_cert_decode( $pem );
$rows = isset( $r['rows'] ) ? array_column( $r['rows'], 1, 0 ) : array();
check( 'certificate decodes', isset( $r['rows'] ) );
check( 'subject is shown', isset( $rows['Subject'] ) && false !== strpos( $rows['Subject'], 'CN=test.example' ) && false !== strpos( $rows['Subject'], 'O=Test Org' ) );
check( 'self-signed is detected', isset( $rows['Self-signed'] ) && 'Yes' === $rows['Self-signed'] );
check( 'days left are about 30', isset( $rows['Status'] ) && preg_match( '/^(29|30) days left$/', $rows['Status'] ) );
check( 'signature algorithm is shown', isset( $rows['Signature'] ) && false !== stripos( $rows['Signature'], 'sha256' ) );
check( 'empty input is refused', isset( db_rs_cert_decode( '   ' )['error'] ) );
openssl_pkey_export( $key, $private );
check( 'a private key is refused', isset( db_rs_cert_decode( $private )['error'] ) && false !== strpos( db_rs_cert_decode( $private )['error'], 'private key' ) );
check( 'a certificate with a key attached is refused', isset( db_rs_cert_decode( $pem . "\n" . $private )['error'] ) );
check( 'two certificates are refused', isset( db_rs_cert_decode( $pem . "\n" . $pem )['error'] ) );
check( 'garbage between the lines is refused', isset( db_rs_cert_decode( "-----BEGIN CERTIFICATE-----\nnot a cert\n-----END CERTIFICATE-----" )['error'] ) );
check( 'oversize input is refused', isset( db_rs_cert_decode( str_repeat( 'A', 13000 ) )['error'] ) );
$expired = db_rs_cert_rows( array( 'validFrom_time_t' => 1000, 'validTo_time_t' => 2000 ), 2000 + 86400 * 3 );
check( 'expired certificates say so', 'Expired 3 days ago' === array_column( $expired, 1, 0 )['Status'] );
$future = db_rs_cert_rows( array( 'validFrom_time_t' => 5000, 'validTo_time_t' => 9000 ), 1000 );
check( 'not-yet-valid certificates say so', 'Not valid yet' === array_column( $future, 1, 0 )['Status'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
