<?php
/**
 * Offline test of the negotiation rules in db-offers-block.php: who may do
 * what, from which state, plus the amount parsing and link shapes.
 *
 *     php tests/offers/deal-transition-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
function add_action() {}
function add_filter() {}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function home_url( $p = '/' ) { return 'https://beta.example.test' . $p; }
function add_query_arg( $k, $v = null, $url = '' ) { $a = is_array( $k ) ? $k : array( $k => $v ); $u = is_array( $k ) ? $v : $url; return $u . ( false === strpos( $u, '?' ) ? '?' : '&' ) . http_build_query( $a ); }
function wp_date( $f, $t ) { return gmdate( $f, $t ); }
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-offers-block.php';

$fails = 0;
function ok( $cond, $label ) { global $fails; echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n"; if ( ! $cond ) { $fails++; } }
function t( $s, $actor, $action, $amt = true ) { return db_deal_transition( $s, $actor, $action, $amt ); }

// The seller.
ok( 'accepted' === t( 'open', 'seller', 'accept' ), 'we can accept the buyer\'s open offer' );
ok( null === t( 'open', 'seller', 'accept', false ), 'we cannot accept an offer that has no price' );
ok( null === t( 'countered', 'seller', 'accept' ), 'we cannot "accept" our own counter-offer' );
ok( 'countered' === t( 'open', 'seller', 'counter' ) && 'countered' === t( 'countered', 'seller', 'counter' ), 'we can counter, and change our price' );
ok( 'countered' === t( 'declined', 'seller', 'counter' ) && 'countered' === t( 'expired', 'seller', 'counter' ), 'we can reopen a declined or expired offer with a new price' );
ok( null === t( 'accepted', 'seller', 'counter' ) && null === t( 'paid', 'seller', 'counter' ), 'we cannot change the price once it is agreed or paid' );
ok( 'declined' === t( 'open', 'seller', 'decline' ) && 'declined' === t( 'countered', 'seller', 'decline' ), 'we can decline an open offer or withdraw our counter' );
ok( null === t( 'accepted', 'seller', 'decline' ) && null === t( 'paid', 'seller', 'decline' ), 'we cannot decline an agreed or paid deal' );

// The buyer.
ok( 'accepted' === t( 'countered', 'buyer', 'accept' ), 'the buyer can accept our counter-offer' );
ok( null === t( 'open', 'buyer', 'accept' ) && null === t( 'declined', 'buyer', 'accept' ) && null === t( 'expired', 'buyer', 'accept' ), 'the buyer cannot accept when no counter-offer is on the table' );
ok( 'declined' === t( 'countered', 'buyer', 'decline' ) && 'declined' === t( 'open', 'buyer', 'decline' ), 'the buyer can decline or withdraw' );
foreach ( array( 'open', 'countered', 'declined', 'expired' ) as $st ) {
	ok( 'open' === t( $st, 'buyer', 'offer' ), "the buyer can make a new offer from '{$st}'" );
}
ok( null === t( 'accepted', 'buyer', 'offer' ) && null === t( 'paid', 'buyer', 'offer' ), 'the buyer cannot reopen the price once agreed or paid' );
ok( null === t( 'paid', 'buyer', 'decline' ) && null === t( 'accepted', 'buyer', 'decline' ), 'a paid or agreed deal cannot be declined by the buyer' );

// Payment.
ok( 'paid' === t( 'accepted', 'system', 'paid' ), 'payment closes an agreed deal' );
ok( null === t( 'declined', 'system', 'paid' ) && null === t( 'expired', 'system', 'paid' ) && null === t( 'paid', 'system', 'paid' ), 'payment cannot close a declined, expired or already paid deal' );
ok( null === t( 'open', 'stranger', 'accept' ) && null === t( 'open', 'seller', 'delete' ), 'unknown actors and actions do nothing' );

// Amounts.
ok( 2500.0 === db_deal_clean_amount( '$2,500' ) && 1500.5 === db_deal_clean_amount( ' 1,500.50 USD ' ), 'prices are read from "$2,500" style text' );
ok( null === db_deal_clean_amount( '' ) && null === db_deal_clean_amount( '0' ) && null === db_deal_clean_amount( 'abc' ), 'empty, zero and non-numeric prices are rejected' );
ok( null === db_deal_clean_amount( '99999999999' ), 'an absurd price is rejected' );
ok( '$2,800' === db_deal_money( 2800 ) && '$2,800.50' === db_deal_money( 2800.5 ), 'money formatting' );

// Links.
$deal = (object) array( 'token' => str_repeat( 'a', 32 ), 'domain' => 'my.cayman.com' );
$pay  = db_deal_pay_url( $deal, 6 );
ok( false !== strpos( $pay, 'offer=' . str_repeat( 'a', 32 ) ) && false !== strpos( $pay, 'months=6' ) && false === strpos( $pay, 'p=' ), 'pay link carries the token and months, never a price' );
ok( false === strpos( db_deal_pay_url( $deal, 7 ), 'months=' ), 'an unsupported number of months is dropped' );

echo "\n" . ( $fails ? "{$fails} failed" : 'all passed' ) . "\n";
exit( $fails ? 1 : 0 );
