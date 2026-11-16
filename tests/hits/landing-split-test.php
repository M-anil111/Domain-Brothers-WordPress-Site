<?php
/**
 * Domain hits: total hits split into landing page and details page hits.
 *
 * Runs the real db-hits-block.php against a real MySQL/MariaDB database (the
 * queries use MySQL syntax), with WordPress replaced by small stubs. Skips,
 * without failing, when no database is available.
 *
 *     DB_TEST_HOST=127.0.0.1 DB_TEST_NAME=dbtest DB_TEST_USER=... DB_TEST_PASS=... \
 *       php tests/hits/landing-split-test.php
 *
 * Covers: table creation with the landing column, the upgrade of an existing
 * version-2 table (column added, old hits kept), recording details and
 * landing views (directly and through the REST beacon endpoint), the repeat
 * window, per-domain totals, sorting by landing/details, the summary tiles,
 * the admin table columns, the CSV header, and the beacon script.
 */

$host = getenv( 'DB_TEST_HOST' ) ?: '127.0.0.1';
$name = getenv( 'DB_TEST_NAME' ) ?: 'dbtest';
$user = getenv( 'DB_TEST_USER' ) ?: '';
$pass = getenv( 'DB_TEST_PASS' ) ?: '';
mysqli_report( MYSQLI_REPORT_OFF );
$link = '' !== $user ? @mysqli_connect( $host, $user, $pass, $name ) : false;
if ( ! $link ) {
	echo "SKIP no test database (set DB_TEST_USER / DB_TEST_PASS / DB_TEST_NAME)\n";
	exit( 0 );
}

/* ---- Minimal WordPress ------------------------------------------------- */
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
$tmp = sys_get_temp_dir() . '/dbhits-' . getmypid() . '/';
@mkdir( $tmp . 'wp-admin/includes', 0777, true );
// Like the real dbDelta, an existing table is left alone here (no error); the
// block itself must then add the landing column to a version-2 table.
file_put_contents( $tmp . 'wp-admin/includes/upgrade.php', '<?php function dbDelta( $sql ) { global $wpdb; $sql = preg_replace( "/^CREATE TABLE/", "CREATE TABLE IF NOT EXISTS", trim( $sql ) ); $wpdb->query( $sql ); }' );
define( 'ABSPATH', $tmp );

class Test_WPDB {
	public $prefix = 'wpt_';
	public $posts  = 'wpt_posts';
	public $link;
	public function __construct( $link ) { $this->link = $link; }
	public function get_charset_collate() { return 'DEFAULT CHARSET=utf8mb4'; }
	public function esc_like( $t ) { return addcslashes( $t, '_%\\' ); }
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		$i = 0;
		return preg_replace_callback( '/%[dsf]/', function ( $m ) use ( &$i, $args ) {
			$v = $args[ $i++ ];
			if ( '%d' === $m[0] ) { return (string) (int) $v; }
			if ( '%f' === $m[0] ) { return (string) (float) $v; }
			return "'" . mysqli_real_escape_string( $this->link, (string) $v ) . "'";
		}, $sql );
	}
	public function query( $sql ) {
		$r = mysqli_query( $this->link, $sql );
		if ( false === $r ) { throw new RuntimeException( mysqli_error( $this->link ) . "\n" . $sql ); }
		return true === $r ? mysqli_affected_rows( $this->link ) : $r;
	}
	public function get_var( $sql ) { $r = $this->query( $sql ); $row = mysqli_fetch_row( $r ); return $row ? $row[0] : null; }
	public function get_results( $sql ) { $r = $this->query( $sql ); $o = array(); while ( $x = mysqli_fetch_object( $r ) ) { $o[] = $x; } return $o; }
	public function delete( $t, $where ) { $k = key( $where ); return $this->query( "DELETE FROM {$t} WHERE {$k} = " . (int) current( $where ) ); }
}
$wpdb = new Test_WPDB( $link );

$GLOBALS['_actions'] = array(); $GLOBALS['_opts'] = array(); $GLOBALS['_tr'] = array(); $GLOBALS['_routes'] = array();
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['_actions'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['_actions'][ $h ][] = $cb; }
function do_action( $h, ...$a ) { foreach ( $GLOBALS['_actions'][ $h ] ?? array() as $cb ) { $cb( ...$a ); } }
function get_option( $k, $d = false ) { return $GLOBALS['_opts'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['_opts'][ $k ] = $v; return true; }
function wp_next_scheduled( $h ) { return true; }
function wp_schedule_event() {}
function get_transient( $k ) { return $GLOBALS['_tr'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['_tr'][ $k ] = $v; }
function wp_date( $f, $t = null ) { return gmdate( $f, $t ?? time() ); }
function wp_salt( $s ) { return 'test-salt'; }
function get_post_type( $id ) { return 'domain'; }
function get_post( $id ) { global $wpdb; $r = $wpdb->get_results( 'SELECT * FROM wpt_posts WHERE ID = ' . (int) $id ); return $r ? $r[0] : null; }
function esc_sql( $v ) { global $wpdb; return mysqli_real_escape_string( $wpdb->link, (string) $v ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return esc_html( $s ); }
function esc_url( $s ) { return esc_html( $s ); }
function esc_url_raw( $s ) { return (string) $s; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function rest_url( $p ) { return 'https://example.test/wp-json/' . $p; }
function register_rest_route( $ns, $route, $args ) { $GLOBALS['_routes'][ $ns . $route ] = $args; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function current_user_can( $c ) { return 'manage_options' === $c; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function add_query_arg( $args, $url ) { return $url . '&' . http_build_query( $args ); }
function wp_nonce_url( $u ) { return $u; }
function get_edit_post_link( $id ) { return 'https://example.test/wp-admin/post.php?post=' . (int) $id; }
function paginate_links( $a ) { return ''; }
function wp_die( $m ) { throw new RuntimeException( $m ); }
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_key( $s ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $s ) ); }
function wp_unslash( $s ) { return $s; }
function is_singular( $t = '' ) { return true; }
function get_queried_object() { return $GLOBALS['_qo']; }
class WP_Post { public $ID; public $post_password = ''; public function __construct( $id ) { $this->ID = $id; } }
class WP_REST_Request { private $b; public function __construct( $b ) { $this->b = $b; } public function get_body() { return $this->b; } }
class WP_REST_Response { public $status; public function __construct( $d, $s ) { $this->status = $s; } public function header( $k, $v ) {} }

/* ---- Fixtures --------------------------------------------------------- */
foreach ( array( 'wpt_posts', 'wpt_db_hits', 'wpt_db_hit_visitors', 'wpt_db_hit_vdays' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
}
$wpdb->query( "CREATE TABLE wpt_posts (ID INT PRIMARY KEY, post_title VARCHAR(200), post_type VARCHAR(20), post_status VARCHAR(20), post_password VARCHAR(20) DEFAULT '')" );
$wpdb->query( "INSERT INTO wpt_posts VALUES (1,'Alpha.ca','domain','publish',''),(2,'beta.com','domain','publish',''),(3,'gamma.io','domain','publish',''),(4,'draft.ca','domain','draft','')" );
// Beta today: a version-2 daily table (no landing column) with an older day of hits.
$wpdb->query( "CREATE TABLE wpt_db_hits (post_id INT NOT NULL, day DATE NOT NULL, hits INT NOT NULL DEFAULT 0, uniques INT NOT NULL DEFAULT 0, PRIMARY KEY (post_id, day), KEY idx_day (day))" );
$wpdb->query( "INSERT INTO wpt_db_hits VALUES (3,'2026-01-15',7,2)" );
update_option( 'db_hits_table_version', '2' );

require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-hits-block.php';
do_action( 'init' );
do_action( 'rest_api_init' );

$pass = 0; $fail = 0;
function ok( $cond, $label ) { global $pass, $fail; if ( $cond ) { $pass++; } else { $fail++; echo "FAIL $label\n"; } }

/* ---- 1. Table ---------------------------------------------------------- */
$cols = array_map( function ( $r ) { return $r->Field; }, $wpdb->get_results( 'SHOW COLUMNS FROM wpt_db_hits' ) );
ok( in_array( 'landing', $cols, true ), 'daily table has a landing column' );
ok( '3' === get_option( 'db_hits_table_version' ), 'table version is 3' );
$old = $wpdb->get_results( "SELECT hits, landing FROM wpt_db_hits WHERE post_id = 3 AND day = '2026-01-15'" );
ok( $old && 7 === (int) $old[0]->hits && 0 === (int) $old[0]->landing, 'upgrade from version 2 keeps existing hits (landing 0)' );

/* ---- 2. Recording ------------------------------------------------------ */
// alpha: 3 details views (3 visitors) + 2 landing views (2 visitors)
foreach ( array( 'v1', 'v2', 'v3' ) as $v ) { db_hits_record( 1, $v, false ); }
foreach ( array( 'v4', 'v5' ) as $v ) { db_hits_record( 1, $v, true ); }
ok( 'repeat' === db_hits_record( 1, 'v4', true ), 'same visitor within 10 seconds is a repeat' );
// beta: 1 landing view; gamma: 4 details views
db_hits_record( 2, 'v6', true );
foreach ( array( 'v7', 'v8', 'v9', 'v10' ) as $v ) { db_hits_record( 3, $v ); }

// Through the REST beacon endpoint, as the browser sends it.
$cb = $GLOBALS['_routes']['db/v1/hit']['callback'];
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/130 Safari/537.36';
$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
$res = $cb( new WP_REST_Request( json_encode( array( 'p' => 2, 'l' => 1 ) ) ) );
ok( 204 === $res->status, 'beacon answers 204' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.8';
$cb( new WP_REST_Request( json_encode( array( 'p' => 2 ) ) ) );
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$cb( new WP_REST_Request( json_encode( array( 'p' => 4, 'l' => 1 ) ) ) ); // draft: ignored
$_SERVER['HTTP_USER_AGENT'] = 'Googlebot/2.1 (+http://www.google.com/bot.html)';
$cb( new WP_REST_Request( json_encode( array( 'p' => 2, 'l' => 1 ) ) ) ); // bot: ignored

/* ---- 3. Per-domain figures --------------------------------------------- */
$a = db_hits_for_domain( 1 );
ok( 5 === (int) $a->all_h && 2 === (int) $a->all_l, 'alpha: total 5, landing 2 (got ' . $a->all_h . '/' . $a->all_l . ')' );
ok( 3 === (int) $a->all_h - (int) $a->all_l, 'alpha: details 3' );
ok( 5 === (int) $a->all_u, 'alpha: 5 unique visitors' );
ok( 2 === (int) $a->today_l && 5 === (int) $a->today_h, 'alpha: today split' );
$b = db_hits_for_domain( 2 );
ok( 3 === (int) $b->all_h && 2 === (int) $b->all_l, 'beta: total 3, landing 2 via direct + beacon (got ' . $b->all_h . '/' . $b->all_l . ')' );
$g = db_hits_for_domain( 3 );
ok( 11 === (int) $g->all_h && 0 === (int) $g->all_l, 'gamma: total 11 (7 before the upgrade + 4), landing 0' );
ok( null === db_hits_for_domain( 4 ), 'draft domain is not listed' );
$today = gmdate( 'Y-m-d' );
$r = db_hits_query( '', 'all', 'desc', 10, 0, $today, $today );
$ra = array_values( array_filter( $r['rows'], function ( $x ) { return 1 === (int) $x->ID; } ) )[0];
ok( 2 === (int) $ra->range_l && 5 === (int) $ra->range_h, 'date range carries the landing split' );

/* ---- 4. Sorting -------------------------------------------------------- */
$ids = function ( $res ) { return array_map( function ( $x ) { return (int) $x->ID; }, $res['rows'] ); };
ok( array( 1, 2, 3 ) === $ids( db_hits_query( '', 'landing', 'desc', 10, 0 ) ), 'sort by landing: alpha(2), beta(2), gamma(0)' );
ok( array( 3, 1, 2 ) === $ids( db_hits_query( '', 'details', 'desc', 10, 0 ) ), 'sort by details: gamma(11), alpha(3), beta(1)' );
ok( array( 3, 1, 2 ) === $ids( db_hits_query( '', 'all', 'desc', 10, 0 ) ), 'sort by total: gamma(11), alpha(5), beta(3)' );

/* ---- 5. Summary -------------------------------------------------------- */
$s = db_hits_summary();
ok( 19 === $s['total'] && 4 === $s['landing'], 'summary: 19 total, 4 landing (got ' . $s['total'] . '/' . $s['landing'] . ')' );

/* ---- 6. Admin screen --------------------------------------------------- */
$_GET = array();
ob_start(); db_hits_page(); $html = ob_get_clean();
foreach ( array( 'Total hits', 'Landing page hits', 'Details page hits' ) as $h ) {
	ok( substr_count( $html, '>' . $h ) >= 2, "admin shows a '$h' tile and column" );
}
ok( (bool) preg_match( '#alpha\.ca</a></strong></td><td class="dbh-total"><strong>5</strong>.*?<td class="dbh-land">2</td><td class="dbh-det">3</td>#s', $html ), 'alpha row reads total 5, landing 2, details 3' );
ok( (bool) preg_match( '#beta\.com</a></strong></td><td class="dbh-total"><strong>3</strong>.*?<td class="dbh-land">2</td><td class="dbh-det">1</td>#s', $html ), 'beta row reads total 3, landing 2, details 1' );

/* ---- 7. Beacon script -------------------------------------------------- */
$GLOBALS['_qo'] = new WP_Post( 1 );
ob_start(); foreach ( $GLOBALS['_actions']['wp_footer'] as $f ) { $f(); } $js = ob_get_clean();
ok( false !== strpos( $js, "location.hash === '#db-landing'" ), 'beacon reads the #db-landing marker' );
ok( false !== strpos( $js, 'l: 1' ), 'beacon sends l:1 for landing views' );
ok( false !== strpos( $js, 'history.replaceState' ), 'beacon removes the marker from the address' );
$jsfile = $tmp . 'beacon.js';
file_put_contents( $jsfile, preg_replace( '#</?script[^>]*>#', '', $js ) );
exec( 'node --check ' . escapeshellarg( $jsfile ) . ' 2>&1', $o, $code );
ok( 0 === $code, 'beacon script is valid JavaScript' );

/* ---- 8. Redirect ------------------------------------------------------- */
$safety = file_get_contents( dirname( __DIR__, 2 ) . '/db-custom-blocks/db-safety-block.php' );
ok( false !== strpos( $safety, "wp_safe_redirect( \$canonical . '#db-landing', 301 );" ), '?lis=y redirect carries the #db-landing marker' );

foreach ( array( 'wpt_posts', 'wpt_db_hits', 'wpt_db_hit_visitors', 'wpt_db_hit_vdays' ) as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
}
echo $fail ? "\n$fail failed, $pass passed\n" : "ALL PASSED ($pass checks)\n";
exit( $fail ? 1 : 0 );
