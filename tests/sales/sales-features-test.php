<?php
/**
 * Offline test for db-sales-block.php: finder ranking and filters, collection
 * rules, funnel recording, "Why this price?" comparables and the trust center.
 *
 *     php tests/sales/sales-features-test.php
 *
 * WordPress is stubbed. db_finder_candidates() and db_dd_facts() are supplied
 * here (their real versions need a full site), so the ranking and filter
 * rules are the code under test, not the listing data.
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DB_SALES_MAX_SEARCHES', 10 );
function add_action() {}
function add_filter() {}
function add_meta_box() {}
function add_submenu_page() {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_title( $s ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $s ) ), '-' ); }
function wp_unslash( $s ) { return $s; }
function get_terms() { return array( (object) array( 'slug' => 'business', 'name' => 'Business' ), (object) array( 'slug' => 'technology', 'name' => 'Technology' ) ); }
function is_wp_error( $x ) { return false; }
function current_user_can() { return false; }
function current_time( $f ) { return '2026-11-08' === $f ? '2026-11-08' : '2026-11-08 10:00:00'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_date( $f, $t ) { return date( $f, $t ); }
function get_transient() { return false; }
function set_transient() {}
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['META'][ $id ][ $key ] ?? ''; }
function get_post( $id ) { return $GLOBALS['POSTS'][ (int) $id ] ?? null; }
function get_permalink( $p ) { return 'https://example.test/domains/' . $p->post_name . '/'; }
function get_edit_post_link( $id ) { return 'https://example.test/wp-admin/post.php?post=' . $id; }
function get_queried_object_id() { return 0; }
function is_singular() { return false; }
function is_admin() { return false; }
function wp_nonce_field() { return ''; }
function checked( $a, $b, $echo = true ) { return $a === $b ? ' checked="checked"' : ''; }
function selected( $a, $b, $echo = true ) { return $a === $b ? ' selected="selected"' : ''; }
function wp_safe_redirect() {}
function wp_get_referer() { return false; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function db_hits_is_bot() { return false; }
function db_wl_email() { return ''; }

class WP_Post { public $ID; public $post_type = 'domain'; public $post_title = ''; public $post_name = ''; }
class WP_Query { public $posts = array(); public function __construct() { $this->posts = db_test_listings(); } }

class FakeWpdb {
	public $prefix = 'wp_';
	public $inserts = array();
	public function insert( $table, $row ) { $this->inserts[] = array( $table, $row ); return 1; }
	public function get_results() { return array(); }
	public function get_var() { return 0; }
	public function prepare( $q ) { return $q; }
	public function get_charset_collate() { return ''; }
}
$GLOBALS['wpdb'] = new FakeWpdb();
$GLOBALS['POSTS'] = array();
$GLOBALS['META']  = array();

/* Listing data: name, price meta, state meta, category. */
function db_test_listings() { return array_values( $GLOBALS['POSTS'] ); }
function db_listing_state( $post ) {
	$post = is_object( $post ) ? $post : get_post( $post );
	$m    = $GLOBALS['META'][ $post->ID ] ?? array();
	if ( ! empty( $m['db_listing_state'] ) ) { return $m['db_listing_state']; }
	$price = (float) ( $m['price'] ?? 0 );
	return $price > 0 ? 'standard' : 'offer';
}
function db_listing_is_sellable( $post ) { return in_array( db_listing_state( $post ), array( 'standard', 'offer' ), true ); }
function db_dd_facts( $post ) {
	$name  = strtolower( $post->post_title );
	$dot   = strpos( $name, '.' );
	$label = false === $dot ? $name : substr( $name, 0, $dot );
	$ext   = false === $dot ? '' : substr( $name, $dot );
	$m     = $GLOBALS['META'][ $post->ID ] ?? array();
	$type  = preg_match( '/^[0-9]+$/', $label ) ? 'numeric' : ( false !== strpos( $label, '-' ) ? 'hyphenated' : ( preg_match( '/[0-9]/', $label ) ? 'letters and numbers' : 'letters only' ) );
	return array( 'name' => $name, 'label' => $label, 'ext' => $ext, 'len' => strlen( $label ), 'type' => $type, 'cats' => $m['cats'] ?? array(), 'price' => (float) ( $m['price'] ?? 0 ) );
}
function db_test_add( $id, $title, array $meta ) {
	$p = new WP_Post();
	$p->ID = $id; $p->post_title = $title; $p->post_name = str_replace( '.', '-', $title );
	$GLOBALS['POSTS'][ $id ] = $p;
	$GLOBALS['META'][ $id ]  = $meta;
}

db_test_add( 1, 'Aether.ca', array( 'price' => 4000, 'cats' => array( 'Business' ) ) );
db_test_add( 2, 'Brightly.ca', array( 'price' => 0, 'cats' => array( 'Business' ) ) );
db_test_add( 3, 'Nova.com', array( 'price' => 9000, 'cats' => array( 'Technology' ) ) );
db_test_add( 4, 'Zephyrmedia.com', array( 'price' => 2000, 'cats' => array( 'Business' ) ) );
db_test_add( 5, 'Lumen.com', array( 'price' => 0, 'cats' => array( 'Technology' ) ) );
db_test_add( 6, 'Ca-tools.ca', array( 'price' => 1500, 'cats' => array( 'Business' ) ) );
db_test_add( 7, 'Sold1.com', array( 'db_listing_state' => 'sold', 'price' => 500 ) );
db_test_add( 8, 'Plainshop.ca', array( 'price' => 3000, 'cats' => array( 'Business' ) ) );
db_test_add( 9, 'Quartz.com', array( 'price' => 2500, 'cats' => array( 'Business' ) ) );

require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-sales-block.php';

$fails = 0;
function ok( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fails++; }
}
function names( array $rows ) { return array_map( function ( $r ) { return $r['facts']['name']; }, $rows ); }

/* Criteria parsing */
$c = db_finder_criteria_from_array( array( 'industry' => 'business', 'budget' => '99999', 'tone' => '<script>', 'extra' => 'x' ) );
ok( 'business' === $c['industry'], 'a known industry is kept' );
ok( 'any' === $c['budget'], 'an unknown budget falls back to any' );
ok( 'any' === $c['tone'], 'an unsafe tone falls back to any' );
ok( ! isset( $c['extra'] ), 'unknown keys are dropped' );

/* Industry and budget filters */
$rows = db_finder_match( db_finder_criteria_from_array( array( 'industry' => 'business' ) ), 50 );
ok( ! in_array( 'nova.com', names( $rows ), true ), 'industry filter excludes other categories' );
ok( ! in_array( 'sold1.com', names( $rows ), true ), 'sold names never appear' );

$rows = db_finder_match( db_finder_criteria_from_array( array( 'budget' => '5000' ) ), 50 );
$n    = names( $rows );
ok( in_array( 'aether.ca', $n, true ), 'a fixed price within budget is kept' );
ok( ! in_array( 'nova.com', $n, true ), 'a fixed price above budget is dropped' );
ok( in_array( 'brightly.ca', $n, true ), 'open-to-offers names stay in when a budget is set' );

$brightly = null;
foreach ( db_finder_match( db_finder_criteria_from_array( array( 'budget' => '5000' ) ), 50 ) as $r ) {
	if ( 'brightly.ca' === $r['facts']['name'] ) { $brightly = $r; }
}
ok( $brightly && in_array( 'Open to offers, so you can negotiate toward your budget', $brightly['reasons'], true ), 'open-to-offers names say so' );

/* Every score point has a reason; ranking favours a geography match */
$rows = db_finder_match( db_finder_criteria_from_array( array( 'geography' => 'canada' ) ), 50 );
ok( count( $rows ) && '.ca' === $rows[0]['facts']['ext'], 'canada geography puts .ca names first' );
ok( count( array_filter( $rows, function ( $r ) { return $r['score'] > 0 && empty( $r['reasons'] ); } ) ) === 0, 'every scored name has at least one reason' );

/* Tone: short prefers 8 characters or fewer */
$rows = db_finder_match( db_finder_criteria_from_array( array( 'tone' => 'short' ) ), 50 );
foreach ( $rows as $r ) {
	if ( $r['score'] > 0 ) { ok( $r['facts']['len'] <= 8, 'short tone only scores names of 8 characters or fewer: ' . $r['facts']['name'] ); break; }
}

/* Collections: fixed extension and length filters */
$c = db_finder_criteria_from_array( array( 'audience' => 'businesses' ) );
$c['ext'] = '.ca'; $c['maxlen'] = 0;
$rows = db_finder_match( $c, 50 );
ok( array_reduce( $rows, function ( $carry, $r ) { return $carry && '.ca' === $r['facts']['ext']; }, true ), 'ext filter keeps only .ca names' );
$c['maxlen'] = 8;
$rows = db_finder_match( $c, 50 );
ok( array_reduce( $rows, function ( $carry, $r ) { return $carry && $r['facts']['len'] <= 8; }, true ), 'maxlen filter drops longer names' );

/* Collections publish only with three or more matches */
ok( null === db_sales_collection_rows( 'short-com-names' ) || count( db_sales_collection_rows( 'short-com-names' )[1] ) >= 3, 'a collection with fewer than three names is not published' );
ok( null === db_sales_collection_rows( 'no-such-collection' ), 'an unknown collection is not published' );

/* Funnel: records allowed events only, and ignores unknown ones */
$GLOBALS['wpdb']->inserts = array();
ok( db_funnel_track( 'finder_start' ) === true, 'a known funnel step is recorded' );
ok( db_funnel_track( 'drop_table' ) === false, 'an unknown funnel step is rejected' );
ok( count( $GLOBALS['wpdb']->inserts ) === 1 && 'finder_start' === $GLOBALS['wpdb']->inserts[0][1]['ev'], 'exactly one row is written for one event' );
ok( ! isset( $GLOBALS['wpdb']->inserts[0][1]['ip'] ) && ! isset( $GLOBALS['wpdb']->inserts[0][1]['email'] ), 'funnel rows carry no IP or email' );

/* Valuation: comparables use the same extension and length within two characters */
function db_sold_listings() { return array( $GLOBALS['POSTS'][7], $GLOBALS['POSTS'][10] ); }
db_test_add( 10, 'Sold2.com', array( 'db_sold_price' => '1200' ) );
$GLOBALS['META'][7]['db_sold_price']  = '1200';
$GLOBALS['META'][7]['db_sold_at']     = strtotime( '2026-03-01' );
$GLOBALS['META'][10]['db_sold_price'] = '1200';
$GLOBALS['META'][10]['db_sold_at']    = strtotime( '2026-04-01' );
ok( count( db_sales_comparables( db_dd_facts( $GLOBALS['POSTS'][1] ), 1 ) ) === 0, 'no comparables for a .ca name when only .com names sold' );
$comps = db_sales_comparables( db_dd_facts( $GLOBALS['POSTS'][3] ), 3 );
ok( count( $comps ) === 2 && 'sold2.com' === $comps[0]['name'], 'comparables are the same extension, newest first' );
ok( count( db_sales_comparables( db_dd_facts( $GLOBALS['POSTS'][7] ), 7 ) ) === 1, 'a sold name is not its own comparable' );

/* Trust center and transfer stages */
$trust = db_trust_center_body();
ok( false === stripos( $trust, 'escrow' ), 'trust center makes no escrow claim' );
ok( false !== strpos( $trust, 'Stripe' ), 'trust center names the card processor' );
$stages = array_keys( db_transfer_stages() );
ok( array( 'paid', 'registrar_lock', 'epp_sent', 'transfer_approved', 'dns_configured', 'completed' ) === $stages, 'transfer stages run in order from payment to completion' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
