<?php
/**
 * Offline test of the APCu object-cache drop-in, with an in-memory APCu stand-in.
 * Run: php tests/speed/object-cache-test.php
 */
define( 'ABSPATH', '/srv/site/' );
define( 'DB_NAME', 'testdb' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/db-oc-test-' . getmypid() );
$table_prefix = 'wp_';
$blog_id      = 1;
$GLOBALS['apcu_on'] = true;
$GLOBALS['apcu']    = array();
function is_multisite() { return false; }
function apcu_enabled() { return $GLOBALS['apcu_on']; }
function apcu_fetch( $k, &$ok = null ) {
	if ( is_array( $k ) ) { $o = array(); foreach ( $k as $x ) { if ( array_key_exists( $x, $GLOBALS['apcu'] ) ) { $o[ $x ] = unserialize( $GLOBALS['apcu'][ $x ] ); } } return $o; }
	$ok = array_key_exists( $k, $GLOBALS['apcu'] );
	return $ok ? unserialize( $GLOBALS['apcu'][ $k ] ) : false;
}
function apcu_store( $k, $v, $ttl = 0 ) { $GLOBALS['apcu'][ $k ] = serialize( $v ); return true; }
function apcu_add( $k, $v, $ttl = 0 ) { if ( array_key_exists( $k, $GLOBALS['apcu'] ) ) { return false; } return apcu_store( $k, $v ); }
function apcu_delete( $k ) { $had = array_key_exists( $k, $GLOBALS['apcu'] ); unset( $GLOBALS['apcu'][ $k ] ); return $had; }
function apcu_inc( $k, $step = 1 ) { if ( ! array_key_exists( $k, $GLOBALS['apcu'] ) ) { return false; } $v = (int) unserialize( $GLOBALS['apcu'][ $k ] ) + $step; apcu_store( $k, $v ); return $v; }
function wp_suspend_cache_addition() { return false; }
require __DIR__ . '/../../db-custom-blocks/pagecache/object-cache.php';

$pass = 0; $fail = 0;
function check( $n, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n\n"; } }
function fresh() { wp_cache_init(); wp_cache_add_non_persistent_groups( array( 'counts' ) ); }

fresh();
check( 'set/get round trip', wp_cache_set( 'a', 'one', 'g' ) && 'one' === wp_cache_get( 'a', 'g' ) );
fresh(); // a new request
$found = null;
check( 'value persists to the next request', 'one' === wp_cache_get( 'a', 'g', false, $found ) && true === $found );
check( 'missing key reports not found', false === wp_cache_get( 'zz', 'g', false, $found ) && false === $found );
check( 'false value is found', wp_cache_set( 'f', false, 'g' ) && false === wp_cache_get( 'f', 'g', false, $found ) && true === $found );
check( 'add refuses an existing key', false === wp_cache_add( 'a', 'two', 'g' ) && 'one' === wp_cache_get( 'a', 'g' ) );
check( 'replace refuses a missing key', false === wp_cache_replace( 'nope', 1, 'g' ) );
$o = new stdClass(); $o->v = 1; wp_cache_set( 'obj', $o, 'g' ); $o->v = 2;
check( 'objects are copied on write', 1 === wp_cache_get( 'obj', 'g' )->v );
$r = wp_cache_get( 'obj', 'g' ); $r->v = 9;
check( 'objects are copied on read', 1 === wp_cache_get( 'obj', 'g' )->v );
check( 'delete removes from both layers', wp_cache_delete( 'a', 'g' ) && false === wp_cache_get( 'a', 'g' ) );
fresh();
check( 'deleted value stays gone next request', false === wp_cache_get( 'a', 'g' ) );
wp_cache_set( 'n', 5, 'g' );
check( 'incr/decr', 7 === wp_cache_incr( 'n', 2, 'g' ) && 6 === wp_cache_decr( 'n', 1, 'g' ) && 0 === wp_cache_decr( 'n', 50, 'g' ) );
wp_cache_set( 'c', 1, 'counts' ); fresh();
check( 'non-persistent group is not shared', false === wp_cache_get( 'c', 'counts' ) );
wp_cache_set( 'k1', 'v1', 'm' ); wp_cache_set( 'k2', 'v2', 'm' ); fresh();
check( 'get_multiple reads from shared memory', array( 'k1' => 'v1', 'k2' => 'v2', 'k3' => false ) === wp_cache_get_multiple( array( 'k1', 'k2', 'k3' ), 'm' ) );
wp_cache_set( 'x', 1, 'grp' ); wp_cache_set( 'y', 2, 'other' );
wp_cache_flush_group( 'grp' ); fresh();
check( 'flush_group clears only that group', false === wp_cache_get( 'x', 'grp' ) && 2 === wp_cache_get( 'y', 'other' ) );
wp_cache_flush(); fresh();
check( 'flush clears everything', false === wp_cache_get( 'y', 'other' ) );
check( 'supports reports flush_group', wp_cache_supports( 'flush_group' ) && ! wp_cache_supports( 'nonsense' ) );
check( 'empty key refused', false === wp_cache_set( '', 1, 'g' ) );
check( 'default group used for empty group', wp_cache_set( 'dk', 3 ) && 3 === wp_cache_get( 'dk', 'default' ) );
// A request without APCu (WP-CLI) that changes data flags a flush for the next web request.
wp_cache_set( 'stale', 'old', 'g' );
$GLOBALS['apcu_on'] = false; fresh();
check( 'without APCu, values are per-request only', false === wp_cache_get( 'stale', 'g' ) );
wp_cache_set( 'stale', 'new', 'g' ); wp_cache_close();
check( 'change without APCu touches the flag file', file_exists( WP_CONTENT_DIR . '/cache/db-object-cache.flag' ) );
$GLOBALS['apcu_on'] = true; touch( WP_CONTENT_DIR . '/cache/db-object-cache.flag', time() + 5 ); fresh();
check( 'next web request flushes after the flag', false === wp_cache_get( 'stale', 'g' ) );
fresh();
check( 'flag handled only once', wp_cache_set( 'after', 1, 'g' ) && ( fresh() || true ) && 1 === wp_cache_get( 'after', 'g' ) );
@unlink( WP_CONTENT_DIR . '/cache/db-object-cache.flag' ); @rmdir( WP_CONTENT_DIR . '/cache' ); @rmdir( WP_CONTENT_DIR );
echo "\n$pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
