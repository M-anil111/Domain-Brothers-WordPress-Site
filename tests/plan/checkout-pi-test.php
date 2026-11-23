<?php
/**
 * Offline test: one checkout PaymentIntent per visitor and listing (bug register batch 2).
 *
 *     php tests/plan/checkout-pi-test.php
 */
define( 'ABSPATH', __DIR__ );
function add_action() {}
function add_filter() {}
function wp_json_encode( $v ) { return json_encode( $v ); }
require __DIR__ . '/../../db-custom-blocks/db-stripe-block.php';

$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$a = db_stripe_pi_cache_key( 'test', 'v1', 'Ster.ca', 0, 10125.0 );
check( 'the same visitor, name, term and price reuse one PaymentIntent', $a === db_stripe_pi_cache_key( 'test', 'v1', 'ster.ca', 0, 10125.0 ) );
check( 'another visitor gets their own', $a !== db_stripe_pi_cache_key( 'test', 'v2', 'ster.ca', 0, 10125.0 ) );
check( 'a plan term gets its own', $a !== db_stripe_pi_cache_key( 'test', 'v1', 'ster.ca', 6, 10125.0 ) );
check( 'a price change gets its own', $a !== db_stripe_pi_cache_key( 'test', 'v1', 'ster.ca', 0, 10126.0 ) );
check( 'live and test mode never share', $a !== db_stripe_pi_cache_key( 'live', 'v1', 'ster.ca', 0, 10125.0 ) );
check( 'an accepted offer (deal metadata) never reuses a plain checkout', $a !== db_stripe_pi_cache_key( 'test', 'v1', 'ster.ca', 0, 10125.0, array( 'deal_id' => '7' ) ) );
check( 'metadata order does not matter', db_stripe_pi_cache_key( 'test', 'v1', 'ster.ca', 0, 1.0, array( 'a' => 1, 'b' => 2 ) ) === db_stripe_pi_cache_key( 'test', 'v1', 'ster.ca', 0, 1.0, array( 'b' => 2, 'a' => 1 ) ) );
foreach ( array( 'requires_payment_method', 'requires_confirmation', 'requires_action' ) as $st ) {
	check( "$st can still be paid, so it is reused", db_stripe_pi_open_status( $st ) );
}
foreach ( array( 'succeeded', 'processing', 'canceled', '' ) as $st ) {
	check( "'$st' is never reused", ! db_stripe_pi_open_status( $st ) );
}
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
