<?php
/**
 * Tools > Test data (db-test-data-block.php).
 *
 * The address rules always run. The scan and delete run against a real
 * MySQL/MariaDB database when one is configured, and are skipped otherwise:
 *
 *     DB_TEST_USER=... DB_TEST_PASS=... DB_TEST_NAME=dbtest php tests/security/test-data-test.php
 */

define( 'ABSPATH', '/' );
$GLOBALS['_actions'] = array();
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['_actions'][ $h ][] = $cb; }
function wp_list_pluck( $l, $f ) { return array_map( function ( $r ) use ( $f ) { return $r[ $f ]; }, $l ); }
function wp_json_encode( $v ) { return json_encode( $v ); }
function get_current_user_id() { return 1; }
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
require __DIR__ . '/../../db-custom-blocks/db-test-data-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

check( 'example.com is a test address', db_test_email( 'spamtest@example.com' ) );
check( 'qa address on .test', db_test_email( 'qa-buyer+1@shop.test' ) && db_test_email( 'a@b.example' ) && db_test_email( 'x@foo.invalid' ) );
check( 'case and trailing dot', db_test_email( 'QA@EXAMPLE.ORG.' ) );
check( 'real addresses are not', ! db_test_email( 'buyer@gmail.com' ) && ! db_test_email( 'a@example.co.uk' ) && ! db_test_email( 'a@notexample.com' ) && ! db_test_email( 'a@testing.com' ) && ! db_test_email( 'not-an-email' ) );
check( 'test markers', db_test_text( 'Test only - please ignore' ) && db_test_text( 'QA test run' ) && ! db_test_text( 'Contest entry' ) && ! db_test_text( 'Is this domain still available?' ) );

$user = getenv( 'DB_TEST_USER' ) ?: '';
mysqli_report( MYSQLI_REPORT_OFF );
$link = '' !== $user ? @mysqli_connect( getenv( 'DB_TEST_HOST' ) ?: '127.0.0.1', $user, getenv( 'DB_TEST_PASS' ) ?: '', getenv( 'DB_TEST_NAME' ) ?: 'dbtest' ) : false;
if ( ! $link ) {
	echo "SKIP database part (set DB_TEST_USER / DB_TEST_PASS / DB_TEST_NAME)\n";
} else {
	class Test_WPDB {
		public $prefix = 'tdt_';
		public $link;
		public function __construct( $l ) { $this->link = $l; }
		public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
		public function prepare( $sql, ...$a ) { $i = 0; return preg_replace_callback( '/%[dsf]/', function () use ( &$i, $a ) { return "'" . mysqli_real_escape_string( $this->link, (string) $a[ $i++ ] ) . "'"; }, $sql ); }
		public function query( $sql ) { $r = mysqli_query( $this->link, $sql ); if ( false === $r ) { throw new RuntimeException( mysqli_error( $this->link ) . "\n" . $sql ); } return true === $r ? mysqli_affected_rows( $this->link ) : $r; }
		public function get_var( $sql ) { $r = $this->query( $sql ); $row = mysqli_fetch_row( $r ); return $row ? $row[0] : null; }
		public function get_results( $sql, $o = null ) { $r = $this->query( $sql ); $out = array(); while ( $x = mysqli_fetch_assoc( $r ) ) { $out[] = $x; } return $out; }
		public function delete( $t, $where, $f = null ) { $w = array(); foreach ( $where as $k => $v ) { $w[] = $k . " = '" . mysqli_real_escape_string( $this->link, (string) $v ) . "'"; } return $this->query( "DELETE FROM {$t} WHERE " . implode( ' AND ', $w ) ); }
	}
	$GLOBALS['wpdb'] = $wpdb = new Test_WPDB( $link );
	$tables = array( 'db_transfer_in', 'db_form_entries', 'db_leads', 'db_accounts', 'db_wl_items', 'db_wl_notes', 'db_wl_prefs', 'db_wl_codes', 'db_wl_sessions' );
	foreach ( $tables as $t ) { $wpdb->query( "DROP TABLE IF EXISTS tdt_{$t}" ); }
	$wpdb->query( "CREATE TABLE tdt_db_transfer_in (id INT AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(255), order_no VARCHAR(100), email VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)" );
	$wpdb->query( "CREATE TABLE tdt_db_form_entries (id BIGINT AUTO_INCREMENT PRIMARY KEY, form_title VARCHAR(200), page_url VARCHAR(255), email VARCHAR(255), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)" );
	$wpdb->query( "CREATE TABLE tdt_db_leads (id INT AUTO_INCREMENT PRIMARY KEY, domain VARCHAR(255), name VARCHAR(255), email VARCHAR(255), message TEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)" );
	$wpdb->query( "CREATE TABLE tdt_db_accounts (email VARCHAR(190) PRIMARY KEY, full_name VARCHAR(120), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)" );
	foreach ( array( 'db_wl_items', 'db_wl_notes', 'db_wl_prefs', 'db_wl_codes', 'db_wl_sessions' ) as $t ) { $wpdb->query( "CREATE TABLE tdt_{$t} (email VARCHAR(190), v INT)" ); }

	$wpdb->query( "INSERT INTO tdt_db_transfer_in (domain, order_no, email) VALUES ('first.com','A1','owner@realmail.com'),('real.com','A2','buyer@gmail.com'),('qa.com','A3','qa-1@example.com')" );
	$wpdb->query( "INSERT INTO tdt_db_form_entries (form_title, page_url, email) VALUES ('Contact','/','first@realmail.com'),('Contact','/','person@gmail.com'),('Contact','/','x@site.test')" );
	$wpdb->query( "INSERT INTO tdt_db_leads (domain, name, email, message) VALUES ('a.com','Real Buyer','buyer@gmail.com','Is it available?'),('b.com','Spam Test','spamtest@example.com','Test only - please ignore'),('c.com','Marked','m@gmail.com','Test only - please ignore')" );
	$wpdb->query( "INSERT INTO tdt_db_accounts (email, full_name) VALUES ('buyer@gmail.com','Real'),('qa-acct@example.test','QA')" );
	foreach ( array( 'db_wl_items', 'db_wl_notes', 'db_wl_prefs', 'db_wl_codes', 'db_wl_sessions' ) as $t ) { $wpdb->query( "INSERT INTO tdt_{$t} VALUES ('buyer@gmail.com',1),('qa-acct@example.test',2)" ); }

	$f = db_test_data_find();
	check( 'transfers: #1 and the test address listed, real one not', array( '1', '3' ) === wp_list_pluck( $f['transfer']['rows'], 'key' ) );
	check( 'form entries: #1 and the test address', array( '1', '3' ) === wp_list_pluck( $f['forms']['rows'], 'key' ) );
	check( 'leads: spamtest and the "test only" enquiry', array( '2', '3' ) === wp_list_pluck( $f['leads']['rows'], 'key' ) );
	check( 'accounts: only the QA account', array( 'qa-acct@example.test' ) === wp_list_pluck( $f['accounts']['rows'], 'key' ) );

	$done = db_test_data_delete( array( 'transfer' => array( '3', '2' ), 'leads' => array( '2' ), 'accounts' => array( 'qa-acct@example.test', 'buyer@gmail.com' ) ) );
	check( 'only listed rows deleted (real ones refused)', array( 'transfer' => 1, 'leads' => 1, 'accounts' => 1 ) === $done );
	check( 'real transfer kept, #1 kept until ticked', 2 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM tdt_db_transfer_in' ) );
	check( 'real account kept', 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM tdt_db_accounts WHERE email='buyer@gmail.com'" ) );
	$side = 0;
	foreach ( array( 'db_wl_items', 'db_wl_notes', 'db_wl_prefs', 'db_wl_codes', 'db_wl_sessions' ) as $t ) { $side += (int) $wpdb->get_var( "SELECT COUNT(*) FROM tdt_{$t} WHERE email='qa-acct@example.test'" ) + 10 * (1 - (int) $wpdb->get_var( "SELECT COUNT(*) FROM tdt_{$t} WHERE email='buyer@gmail.com'" ) ); }
	check( 'QA account side rows gone, real ones kept', 0 === $side );
	check( 'unticked groups untouched', 3 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM tdt_db_form_entries' ) );
	foreach ( $tables as $t ) { $wpdb->query( "DROP TABLE IF EXISTS tdt_{$t}" ); }
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
