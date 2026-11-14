<?php
/**
 * Offline test for db-domain-tools-block.php: ways-to-pay maths, compare input
 * cleaning, price-drop percent, price-change recording rules, RDAP parsing.
 *
 *     php tests/domain/domain-tools-test.php
 */
define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
function add_action() {}
function add_filter() {}
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function current_time() { return '2026-11-14 10:00:00'; }
function db_plan_terms() { return array( 3, 6, 9, 12 ); }
function db_plan_rate( $m ) { $r = array( 3 => 0, 6 => 0, 9 => 5, 12 => 10 ); return (float) ( $r[ (int) $m ] ?? 0 ); }
function db_plan_total_cents( $p, $m ) { return (int) round( (float) $p * ( 100 + db_plan_rate( $m ) ) ); }
function db_stripe_installment_cents( $p, $m, $n ) { $t = db_plan_total_cents( $p, $m ); $mc = (int) round( $t / $m ); return $n < $m ? $mc : $t - $mc * ( $m - 1 ); }
class WP_Post { public $ID; public $post_type = 'domain'; public $post_status = 'publish'; public $post_title = ''; }
$GLOBALS['POSTS'] = array();
function get_post( $id ) { return $GLOBALS['POSTS'][ (int) $id ] ?? null; }
class FakeWpdb { public $prefix = 'wp_'; public $rows = array(); public function insert( $t, $r ) { $this->rows[] = $r; return 1; } }
$GLOBALS['wpdb'] = new FakeWpdb();

require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-domain-tools-block.php';

$fails = 0;
function ok( $c, $l ) { global $fails; echo ( $c ? 'PASS ' : 'FAIL ' ) . $l . "\n"; if ( ! $c ) { $fails++; } }

/* Ways to pay */
$rows = db_tools_pay_rows( 2500 );
ok( 5 === count( $rows ) && 0 === $rows[0]['months'], 'pay in full plus four plan terms' );
ok( abs( $rows[1]['total'] - 2500 ) < 0.001 && 0.0 === $rows[1]['extra'], 'the 3-month plan costs nothing extra' );
ok( abs( $rows[3]['total'] - 2625 ) < 0.001 && abs( $rows[3]['extra'] - 125 ) < 0.001, 'the 9-month plan adds 5% ($125 on $2,500)' );
ok( abs( $rows[4]['total'] - 2750 ) < 0.001 && abs( $rows[4]['monthly'] - 229.17 ) < 0.001, 'the 12-month plan adds 10% and charges $229.17 a month' );
ok( array() === db_tools_pay_rows( 0 ), 'no table without a price' );
foreach ( $rows as $r ) {
	if ( $r['months'] ) {
		$sum = 0;
		for ( $i = 1; $i <= $r['months']; $i++ ) { $sum += db_stripe_installment_cents( 2500, $r['months'], $i ); }
		ok( abs( $sum / 100 - $r['total'] ) < 0.001, $r['months'] . ' payments add up to the stated total' );
	}
}

/* Compare input */
ok( array( 'a.com', 'b.ca' ) === db_tools_compare_names( 'A.com, b.ca ,a.com' ), 'names are lower-cased, trimmed and de-duplicated' );
ok( 4 === count( db_tools_compare_names( 'a.com,b.com,c.com,d.com,e.com' ) ), 'at most four names' );
ok( array() === db_tools_compare_names( '<script>,../etc/passwd,a b.com,-x.com,nodot' ), 'invalid names are dropped' );
ok( '' === db_tools_valid_domain( 'a.com/evil' ) && '' === db_tools_valid_domain( "a.com\nx" ), 'a path or a newline is never a domain' );

/* Price drop */
ok( 20 === db_tools_drop_percent( 2500, 2000 ), 'a drop from 2,500 to 2,000 is 20%' );
ok( 0 === db_tools_drop_percent( 2000, 2500 ), 'a rise is not a drop' );
ok( 0 === db_tools_drop_percent( 0, 2000 ) && 0 === db_tools_drop_percent( 2000, 0 ), 'no percent when either side has no price' );
ok( 33 === db_tools_drop_percent( 3000, 2000 ), 'the percent is rounded down, never up' );

/* Price recording rules */
$p = new WP_Post(); $p->ID = 1; $GLOBALS['POSTS'][1] = $p;
$d = new WP_Post(); $d->ID = 2; $d->post_status = 'draft'; $GLOBALS['POSTS'][2] = $d;
$x = new WP_Post(); $x->ID = 3; $x->post_type = 'post'; $GLOBALS['POSTS'][3] = $x;
ok( true === db_tools_record_price( 1, 'domain_price', '$2,000', '2500' ), 'a price change on a published listing is recorded' );
ok( 2500.0 === $GLOBALS['wpdb']->rows[0]['old_price'] && 2000.0 === $GLOBALS['wpdb']->rows[0]['new_price'], 'old and new prices are stored as numbers' );
ok( false === db_tools_record_price( 1, 'domain_price', '2000.00', '$2,000' ), 'the same price written differently is not a change' );
ok( false === db_tools_record_price( 2, 'domain_price', '10', '20' ), 'a draft is not recorded' );
ok( false === db_tools_record_price( 3, 'domain_price', '10', '20' ), 'another post type is not recorded' );
ok( false === db_tools_record_price( 1, 'other_key', '10', '20' ), 'another meta key is not recorded' );

/* RDAP parsing */
$rdap = json_encode( array(
	'events' => array( array( 'eventAction' => 'registration', 'eventDate' => '2009-03-14T00:00:00Z' ), array( 'eventAction' => 'expiration', 'eventDate' => '2027-03-14T00:00:00Z' ) ),
	'entities' => array( array( 'roles' => array( 'registrar' ), 'vcardArray' => array( 'vcard', array( array( 'version', array(), 'text', '4.0' ), array( 'fn', array(), 'text', 'Example <b>Registrar</b>, Inc.' ) ) ) ) ),
) );
$r = db_tools_rdap_parse( $rdap );
ok( 2009 === $r['year'], 'the registration year is read' );
ok( 'Example Registrar, Inc.' === $r['registrar'], 'the registrar name is read with markup stripped' );
ok( array( 'year' => 0, 'registrar' => '' ) === db_tools_rdap_parse( 'not json' ), 'a broken record gives nothing' );
$bad = db_tools_rdap_parse( json_encode( array( 'events' => array( array( 'eventAction' => 'registration', 'eventDate' => '2999-01-01T00:00:00Z' ) ) ) ) );
ok( 0 === $bad['year'], 'a registration year in the future is ignored' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
