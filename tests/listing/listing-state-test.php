<?php
/**
 * Offline test for db-listing-state-block.php: which of the four states
 * (standard / offer / not_for_sale / sold) a domain listing is in.
 *
 *     php tests/listing/listing-state-test.php
 */

define( 'ABSPATH', __DIR__ );
function add_action() {}
function add_filter() {}
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
class WP_Post { public $ID; public $post_type = 'domain'; public $post_title = ''; public $post_status = 'publish'; public $post_password = ''; }
$GLOBALS['POSTS'] = array();
$GLOBALS['META']  = array();
$GLOBALS['TAKEN'] = array();
function get_post( $p = 0 ) { return is_object( $p ) ? $p : ( $GLOBALS['POSTS'][ (int) $p ] ?? null ); }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['META'][ $id ][ $key ] ?? ''; }
function db_stripe_domain_taken( $domain ) { return in_array( $domain, $GLOBALS['TAKEN'], true ); }

require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-listing-state-block.php';

$fails = 0;
function ok( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fails++; }
}
function listing( $id, $title, array $meta = array() ) {
	$p = new WP_Post(); $p->ID = $id; $p->post_title = $title;
	$GLOBALS['POSTS'][ $id ] = $p; $GLOBALS['META'][ $id ] = $meta;
	return $p;
}

ok( 'standard' === db_listing_state( listing( 1, 'Aethereum.ca', array( 'domain_price' => '$10,000' ) ) ), 'a price makes it a fixed-price listing' );
ok( 'offer' === db_listing_state( listing( 2, 'mycayman.com', array( 'domain_price' => '' ) ) ), 'no price means custom offers' );
ok( 'offer' === db_listing_state( listing( 3, 'zero.com', array( 'domain_price' => '0' ) ) ), 'a price of 0 means custom offers' );
ok( 'sold' === db_listing_state( listing( 4, 'old.com', array( 'domain_status' => 'Sold', 'domain_price' => '500' ) ) ), 'legacy domain_status "sold" wins over a price' );
ok( 'not_for_sale' === db_listing_state( listing( 5, 'keep.ca', array( 'db_listing_state' => 'not_for_sale', 'domain_price' => '900' ) ) ), 'admin override: not for sale, even with a price' );
ok( 'sold' === db_listing_state( listing( 6, 'gone.ca', array( 'db_listing_state' => 'sold' ) ) ), 'admin override: sold' );
ok( 'standard' === db_listing_state( listing( 7, 'auto.ca', array( 'db_listing_state' => 'auto', 'domain_price' => '700' ) ) ), '"auto" falls back to the price rule' );
ok( 'standard' === db_listing_state( listing( 8, 'junk.ca', array( 'db_listing_state' => '<script>', 'domain_price' => '700' ) ) ), 'an unknown override value is ignored' );
$GLOBALS['TAKEN'][] = 'bought.ca';
ok( 'sold' === db_listing_state( listing( 9, 'Bought.ca', array( 'domain_price' => '1200' ) ) ), 'a completed sale or active payment plan means sold' );
ok( 'not_for_sale' === db_listing_state( listing( 10, 'both.ca', array( 'db_listing_state' => 'not_for_sale' ) ) ) && ! db_listing_is_sellable( 10 ), 'not-for-sale is not sellable' );
ok( db_listing_is_sellable( 1 ) && db_listing_is_sellable( 2 ) && ! db_listing_is_sellable( 4 ), 'standard and offer are sellable, sold is not' );

$other = new WP_Post(); $other->ID = 11; $other->post_type = 'post';
ok( 'offer' === db_listing_state( $other ), 'a non-domain post is never sold or not-for-sale' );
ok( 4 === count( db_listing_states() ) && isset( db_listing_states()['not_for_sale'], db_listing_states()['sold'] ), 'four states are defined' );
listing( 12, 'x.com', array( 'domain_price' => ' USD 1,500.00 ' ) );
ok( 1500.0 === db_listing_price( 12 ), 'prices with symbols and separators are read correctly' );

echo "\n" . ( $fails ? "{$fails} failed" : 'all passed' ) . "\n";
exit( $fails ? 1 : 0 );
