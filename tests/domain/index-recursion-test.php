<?php
/**
 * Offline test: building the domain index must not recurse through the for-sale query hooks.
 * On the home page with a cold cache, db_domain_index() ran get_posts(), whose pre_get_posts hook
 * called db_domain_index() again, until PHP ran out of memory (1 GB fatals, 23-24 Nov 2026).
 *
 *     php tests/domain/index-recursion-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DB_DOMAIN_INDEX_KEY', 'db_test_index' );
$GLOBALS['hooks'] = array();
function add_action( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $a = 1 ) { $GLOBALS['hooks'][ $h ][] = $cb; }
function is_admin() { return false; }
function did_action( $h ) { return 1; }
function is_front_page() { return true; }
function is_page( $p = '' ) { return false; }
$GLOBALS['transients'] = array();
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
function get_the_title( $id ) { return 'name' . $id . '.com'; }
function get_post_meta( $id, $k, $s ) { return 'domain_price' === $k ? '1000' : ''; }
function get_the_terms( $id, $t ) { return array(); }
function get_permalink( $id ) { return 'https://example.test/domains/name' . $id . '-com/'; }
function get_post_type( $id ) { return 'domain'; }
class WP_Query {
	public $query_vars;
	public function __construct( $qv = array() ) { $this->query_vars = $qv; }
	public function get( $k ) { return $this->query_vars[ $k ] ?? ''; }
	public function set( $k, $v ) { $this->query_vars[ $k ] = $v; }
	public function is_main_query() { return false; }
	public function is_search() { return false; }
}
$GLOBALS['depth'] = 0;
$GLOBALS['max_depth'] = 0;
// get_posts runs pre_get_posts like WordPress does, then returns ids (filtered by post__in).
function get_posts( $args ) {
	$GLOBALS['depth']++;
	$GLOBALS['max_depth'] = max( $GLOBALS['max_depth'], $GLOBALS['depth'] );
	if ( $GLOBALS['depth'] > 20 ) {
		echo "FAIL recursion: get_posts nested more than 20 deep\n";
		exit( 1 );
	}
	$q = new WP_Query( $args + array( 'post_type' => 'post' ) );
	foreach ( $GLOBALS['hooks']['pre_get_posts'] ?? array() as $cb ) {
		$cb( $q );
	}
	$ids = array( 1, 2, 3 );
	if ( ! empty( $q->query_vars['post__in'] ) ) {
		$ids = array_values( array_intersect( $ids, $q->query_vars['post__in'] ) );
	}
	$GLOBALS['depth']--;
	return $ids;
}
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-domain-filter-block.php';

$fails = 0;
$n     = 0;
function ok( $cond, $msg ) {
	global $fails, $n;
	$n++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

$idx = db_domain_index();
ok( 1 === $GLOBALS['max_depth'], 'building the index with a cold cache runs its query once, without nesting (depth ' . $GLOBALS['max_depth'] . ')' );
ok( 3 === count( $idx ) && 'name1.com' === $idx[0]['n'], 'the index holds every name its own query returned (not filtered by itself)' );
ok( false === db_domain_index_building(), 'the building flag is cleared afterwards' );
ok( is_array( get_transient( DB_DOMAIN_INDEX_KEY ) ), 'the result is cached' );

// A home-page list query after the build is limited to the names in the index.
$GLOBALS['max_depth'] = 0;
$ids = get_posts( array( 'post_type' => 'domain', 'posts_per_page' => 8 ) );
ok( array( 1, 2, 3 ) === $ids && 1 === $GLOBALS['max_depth'], 'a home list query uses the cached index without rebuilding it' );
ok( null === db_domain_index_ids( new WP_Query( array( 'db_index_build' => true ) ) ), 'the index query itself is never restricted' );

echo "\n" . ( $n - $fails ) . "/$n passed\n";
exit( $fails ? 1 : 0 );
