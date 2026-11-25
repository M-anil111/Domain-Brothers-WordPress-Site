<?php
/** Offline tests for the pure parts of db-connect-block.php. Run: php tests/connect/connect-test.php */

define( 'ABSPATH', '/' );
function add_action() {}
function add_filter() {}
function add_management_page() {}
require __DIR__ . '/../../db-custom-blocks/db-connect-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$acct = function ( $transfers, $req ) {
	return array(
		'configuration' => array( 'recipient' => array( 'capabilities' => array( 'stripe_balance' => array( 'stripe_transfers' => array( 'status' => $transfers ) ) ) ) ),
		'requirements'  => array( 'summary' => array( 'minimum_deadline' => array( 'status' => $req ) ) ),
	);
};
$s = db_connect_status_from( $acct( 'active', 'eventually_due' ) );
check( 'active transfers and nothing currently due: ready and complete', $s['ready'] && $s['complete'] );
$s = db_connect_status_from( $acct( 'pending', 'currently_due' ) );
check( 'currently due: not ready, not complete', ! $s['ready'] && ! $s['complete'] && 'currently_due' === $s['requirements'] );
$s = db_connect_status_from( $acct( 'restricted', 'past_due' ) );
check( 'past due: not complete', ! $s['complete'] );
$s = db_connect_status_from( array() );
check( 'a new account with no fields: not ready, complete (nothing due yet)', ! $s['ready'] && $s['complete'] && '' === $s['transfers'] );

check( 'fee: 15% of $2,500.00 is $375.00', 37500 === db_connect_fee( 250000, 15 ) );
check( 'fee: rounds to the nearest cent', 15 === db_connect_fee( 99, 15 ) );
check( 'fee: never the whole amount (seller always gets at least 1 cent)', 0 === db_connect_fee( 1, 90 ) && 90 === db_connect_fee( 100, 100 ) );
check( 'fee: percent capped at 90', 900 === db_connect_fee( 1000, 250 ) );
check( 'fee: negative inputs give 0', 0 === db_connect_fee( -5, 15 ) && 0 === db_connect_fee( 1000, -3 ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
