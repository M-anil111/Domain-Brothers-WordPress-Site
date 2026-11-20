<?php
/** Offline tests for the pure parts of db-account-data-block.php. Run: php tests/hygiene/account-data-test.php */

define( 'ABSPATH', '/' );
function add_action() {}
require __DIR__ . '/../../db-custom-blocks/db-account-data-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$row = array( 'full_name' => 'Sam Example', 'phone' => '+15550100', 'newsletter' => 1, 'consent_ip_hash' => str_repeat( 'a', 64 ), 'profile' => '{"intent":"buy"}', 'created_at' => '2026-01-01 00:00:00' );
$out = db_ad_export_array( 'sam@example.test', $row, array( 'a.com', 'b.ca' ), array( array( 'domain' => 'a.com', 'kind' => 'standard', 'price' => '1000.00', 'paid' => '250', 'plan_months' => '6', 'status' => 'paid', 'created_at' => '2026-02-02', 'buyer_email' => 'sam@example.test', 'pi_id' => 'pi_secret' ) ) );
$json = json_encode( $out );
check( 'export has the account fields', 'Sam Example' === $out['account']['full_name'] && true === $out['account']['newsletter'] );
check( 'profile answers decoded', 'buy' === $out['account']['profile_answers']['intent'] );
check( 'saved domains listed', array( 'a.com', 'b.ca' ) === $out['saved_domains'] );
check( 'order numbers are typed', 1000.0 === $out['orders'][0]['price'] && 6 === $out['orders'][0]['plan_months'] );
check( 'ip hash is not exported', false === strpos( $json, 'aaaaaaaa' ) );
check( 'payment ids are not exported', false === strpos( $json, 'pi_secret' ) );
check( 'empty row still exports cleanly', is_object( db_ad_export_array( 'x@example.test', array(), array(), array() )['account']['profile_answers'] ) );
check( 'confirm matches ignoring case and spaces', db_ad_confirm_ok( 'sam@example.test', '  SAM@Example.test ' ) );
check( 'confirm rejects other text', ! db_ad_confirm_ok( 'sam@example.test', 'sam' ) && ! db_ad_confirm_ok( 'sam@example.test', '' ) );
check( 'confirm rejects an empty signed-in address', ! db_ad_confirm_ok( '', '' ) );
$t = db_ad_delete_targets( 'wp_' );
check( 'delete covers account, items, codes, sessions', 4 === count( $t ) && isset( $t['wp_db_accounts'], $t['wp_db_wl_items'], $t['wp_db_wl_codes'], $t['wp_db_wl_sessions'] ) );
check( 'delete never targets orders', ! array_filter( array_keys( $t ), function ( $k ) { return false !== strpos( $k, 'order' ); } ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
