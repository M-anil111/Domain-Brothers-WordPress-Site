<?php
/**
 * Offline tests for the auction rules. Run: php tests/auction/auction-test.php
 */
define( 'DB_AUCTION_TESTING', 1 );
require __DIR__ . '/../../db-custom-blocks/db-auction-lib.php';
$pass = 0; $fail = 0;
function check( $n, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n\n"; } }
$rules = db_auc_rules_default();

// Increments and the minimum bid.
check( 'increment is $5 under $100', 5 === db_auc_increment( 1 ) && 5 === db_auc_increment( 99 ) );
check( 'increment grows with the price', 10 === db_auc_increment( 100 ) && 50 === db_auc_increment( 1000 ) && 100 === db_auc_increment( 5000 ) && 250 === db_auc_increment( 25000 ) && 500 === db_auc_increment( 100000 ) );
check( 'first bid may equal the starting price', 1 === db_auc_min_bid( array( 'start' => 1, 'current' => 0, 'bids' => 0 ) ) );
check( 'next bid is the price plus one increment ($1 then $6, $6 then $11)', 6 === db_auc_min_bid( array( 'start' => 1, 'current' => 1, 'bids' => 1 ) ) && 11 === db_auc_min_bid( array( 'start' => 1, 'current' => 6, 'bids' => 2 ) ) );

// Amounts.
check( 'amounts accept $ and commas', 12500 === db_auc_clean_amount( '$12,500' ) && 12500 === db_auc_clean_amount( '12500.00' ) );
check( 'bad amounts are 0', 0 === db_auc_clean_amount( 'abc' ) && 0 === db_auc_clean_amount( '-5' ) && 0 === db_auc_clean_amount( '0' ) && 0 === db_auc_clean_amount( '1e5' ) );

// Setup.
list( $c, $e ) = db_auc_validate_setup( array( 'start' => '100', 'reserve' => '500', 'bin' => '2000', 'days' => '7', 'show_reserve' => '1' ), $rules );
check( 'valid setup passes', ! $e && 100 === $c['start'] && 500 === $c['reserve'] && 2000 === $c['bin'] && 7 === $c['days'] && $c['show_reserve'] );
list( , $e ) = db_auc_validate_setup( array( 'start' => '500', 'reserve' => '100', 'days' => '7' ), $rules );
check( 'reserve below the start is refused', 1 === count( $e ) );
list( , $e ) = db_auc_validate_setup( array( 'start' => '100', 'reserve' => '500', 'bin' => '500', 'days' => '7' ), $rules );
check( 'Buy It Now must be above the reserve', 1 === count( $e ) );
list( , $e ) = db_auc_validate_setup( array( 'start' => '100', 'days' => '0' ), $rules );
check( 'duration must be one of the choices', 1 === count( $e ) );
list( , $e ) = db_auc_validate_setup( array( 'start' => '100', 'days' => '61' ), $rules );
check( '61 days is refused', 1 === count( $e ) );
list( , $e ) = db_auc_validate_setup( array( 'start' => '0', 'days' => '7' ), $rules );
check( 'a starting price is required', 1 === count( $e ) );
list( $c, $e ) = db_auc_validate_setup( array( 'start' => '100', 'reserve' => '', 'bin' => '', 'days' => '3', 'show_reserve' => '1' ), $rules );
check( 'no reserve means nothing to show', ! $e && 0 === $c['reserve'] && ! $c['show_reserve'] );
list( , $e ) = db_auc_validate_setup( array( 'start' => '100', 'reserve' => 'lots', 'days' => '3' ), $rules );
check( 'a reserve that is not a number is refused', 1 === count( $e ) );

// Proxy bidding.
$s = array( 'start' => 100, 'reserve' => 0, 'current' => 0, 'leader' => '', 'leader_max' => 0, 'bids' => 0 );
$r = db_auc_place_bid( $s, 'a', 50 );
check( 'bid under the start is refused', ! $r['ok'] && false !== strpos( $r['error'], '$100' ) );
$r = db_auc_place_bid( $s, 'a', 500 );
check( 'first bid leads at the starting price, not the maximum', $r['ok'] && $r['leading'] && 100 === $r['state']['current'] && 500 === $r['state']['leader_max'] );
$s = $r['state'];
$r = db_auc_place_bid( $s, 'b', 200 );
check( 'a lower maximum is outbid at once; price is that bid plus one step', $r['ok'] && ! $r['leading'] && 'a' === $r['state']['leader'] && 210 === $r['state']['current'] );
$s = $r['state'];
$r = db_auc_place_bid( $s, 'b', 215 );
check( 'the minimum follows the visible price', ! $r['ok'] && false !== strpos( $r['error'], '$220' ) );
$r = db_auc_place_bid( $s, 'b', 800 );
check( 'a higher maximum takes the lead at the old maximum plus one step', $r['ok'] && $r['leading'] && 'b' === $r['state']['leader'] && 510 === $r['state']['current'] && 'a' === $r['outbid'] );
$s = $r['state'];
$r = db_auc_place_bid( $s, 'c', 800 );
check( 'equal maximum: the earlier bidder keeps the lead at that maximum', $r['ok'] && 'b' === $r['state']['leader'] && 800 === $r['state']['current'] );
$s = $r['state'];
$r = db_auc_place_bid( $s, 'b', 700 );
check( 'the leader cannot lower their maximum', ! $r['ok'] && false !== strpos( $r['error'], 'already the highest' ) );
$r = db_auc_place_bid( $s, 'b', 1000 );
check( 'the leader may raise their maximum without moving the price', $r['ok'] && 800 === $r['state']['current'] && 1000 === $r['state']['leader_max'] );
$s = array( 'start' => 100, 'reserve' => 0, 'current' => 0, 'leader' => '', 'leader_max' => 0, 'bids' => 0 );
$r = db_auc_place_bid( $s, 'a', 1 );
check( 'bid of $0 or less, or over the cap, is refused', ! db_auc_place_bid( $s, 'a', 0 )['ok'] && ! db_auc_place_bid( $s, 'a', DB_AUC_MAX_AMOUNT + 1 )['ok'] );

// Reserve.
$s = array( 'start' => 100, 'reserve' => 1000, 'current' => 0, 'leader' => '', 'leader_max' => 0, 'bids' => 0 );
$r = db_auc_place_bid( $s, 'a', 500 );
check( 'below the reserve the price stays at the start', 100 === $r['state']['current'] && 'Reserve not met' === db_auc_reserve_label( $r['state'] ) );
$s = $r['state'];
$r = db_auc_place_bid( $s, 'b', 1500 );
check( 'a maximum that covers the reserve lifts the price to the reserve', 1000 === $r['state']['current'] && 'b' === $r['state']['leader'] && 'Reserve met' === db_auc_reserve_label( $r['state'] ) );
$s = array( 'start' => 100, 'reserve' => 1000, 'current' => 0, 'leader' => '', 'leader_max' => 0, 'bids' => 0 );
$r = db_auc_place_bid( $s, 'a', 2000 );
check( 'first bid above the reserve opens at the reserve', 1000 === $r['state']['current'] );
$s = array( 'start' => 100, 'reserve' => 1000, 'current' => 100, 'leader' => 'a', 'leader_max' => 500, 'bids' => 1 );
$r = db_auc_place_bid( $s, 'a', 1200 );
check( 'the leader raising past the reserve lifts the price to it', 1000 === $r['state']['current'] );
check( 'reserve shown when the owner chose to', 'Reserve $1,000, not met yet' === db_auc_reserve_label( array( 'reserve' => 1000, 'current' => 100, 'bids' => 1 ), true ) );
check( 'no reserve label', 'No reserve' === db_auc_reserve_label( array( 'reserve' => 0 ) ) );

// Outcome.
check( 'no bids', 'no_bids' === db_auc_outcome( array( 'bids' => 0, 'leader' => '' ) ) );
check( 'reserve not met', 'reserve_not_met' === db_auc_outcome( array( 'bids' => 2, 'leader' => 'a', 'current' => 500, 'reserve' => 1000 ) ) );
check( 'sold when the reserve is met or there is none', 'sold' === db_auc_outcome( array( 'bids' => 2, 'leader' => 'a', 'current' => 1000, 'reserve' => 1000 ) ) && 'sold' === db_auc_outcome( array( 'bids' => 1, 'leader' => 'a', 'current' => 100, 'reserve' => 0 ) ) );

// Buy It Now.
check( 'Buy It Now open while bids are below it', db_auc_bin_open( array( 'bin' => 2000, 'current' => 500, 'leader_max' => 800 ) ) );
check( 'Buy It Now closes once a maximum reaches it', ! db_auc_bin_open( array( 'bin' => 2000, 'current' => 500, 'leader_max' => 2000 ) ) && ! db_auc_bin_open( array( 'bin' => 0 ) ) );

// Ending time.
$end = 1000000;
check( 'an early bid does not move the end', $end === db_auc_extend( $end, $end - 3600, 5, 5 ) );
check( 'a bid in the last 5 minutes moves the end 5 minutes past the bid', $end - 60 + 300 === db_auc_extend( $end, $end - 60, 5, 5 ) );
check( 'the end never moves earlier', $end === db_auc_extend( $end, $end - 299 - 600, 5, 5 ) );

// Display.
$al = db_auc_aliases( array( 'x@e.com', 'y@e.com', 'x@e.com', 'z@e.com' ) );
check( 'bidders are numbered by first bid', 'Bidder 1' === $al['x@e.com'] && 'Bidder 2' === $al['y@e.com'] && 'Bidder 3' === $al['z@e.com'] );
check( 'time left reads naturally', '2 days 4 hours' === db_auc_time_left( 100 + 2 * 86400 + 4 * 3600, 100 ) && '3 hours 12 minutes' === db_auc_time_left( 3 * 3600 + 720, 0 ) && '45 seconds' === db_auc_time_left( 45, 0 ) && 'Ended' === db_auc_time_left( 5, 10 ) && '1 day' === db_auc_time_left( 86400, 0 ) );

// Second chance.
check( 'second chance goes to the next highest maximum', array( 'b', 900 ) === db_auc_next_chance( array( 'a' => 1200, 'b' => 900, 'c' => 400 ), array( 'a' ), 0 ) );
check( 'second chance stops below the reserve', null === db_auc_next_chance( array( 'a' => 1200, 'b' => 900 ), array( 'a' ), 1000 ) );
check( 'no one left', null === db_auc_next_chance( array( 'a' => 1200 ), array( 'a' ), 0 ) );

echo "\n$pass/" . ( $pass + $fail ) . " passed\n"; exit( $fail ? 1 : 0 );
