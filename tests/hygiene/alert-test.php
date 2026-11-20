<?php
/** Offline tests for db-alerts-block.php. Run: php tests/hygiene/alert-test.php */

define( 'ABSPATH', '/' );
function add_action() {}
require __DIR__ . '/../../db-custom-blocks/db-alerts-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$t0  = strtotime( '2026-11-20 10:00:00 UTC' );
$log = array(
	array( 'time' => '2026-11-20 12:00:00', 'uri' => '/pay/', 'msg' => "Uncaught Error:\n  boom", 'file' => '/home/u/public_html/wp-content/plugins/db-custom-blocks/x.php', 'line' => 42, 'count' => 3 ),
	array( 'time' => '2026-11-20 09:00:00', 'uri' => '/old/', 'msg' => 'old problem', 'file' => '/a/b.php', 'line' => 1 ),
);
check( 'nothing new means no alert', null === db_alert_body( $log, strtotime( '2026-11-20 13:00:00 UTC' ), 'beta.example.test' ) );
check( 'empty log means no alert', null === db_alert_body( array(), 0, 'h' ) );
$m = db_alert_body( $log, $t0, 'beta.example.test' );
check( 'only newer entries are included', null !== $m && false !== strpos( $m['body'], '/pay/' ) && false === strpos( $m['body'], '/old/' ) );
check( 'subject names the host and the count', '[beta.example.test] 1 new PHP fatal error' === $m['subject'] );
check( 'file shows as a base name, not a server path', false !== strpos( $m['body'], '(x.php:42' ) && false === strpos( $m['body'], '/home/u/' ) );
check( 'message is one line', false === strpos( explode( "\n", $m['body'] )[2], "\n" ) && false !== strpos( $m['body'], 'Uncaught Error: boom' ) );
check( 'repeat count is shown', false !== strpos( $m['body'], 'seen 3 times' ) );
check( 'newest time is returned for the next run', strtotime( '2026-11-20 12:00:00 UTC' ) === $m['newest'] );
$all = db_alert_body( $log, 0, 'h' );
check( 'two new entries use the plural', false !== strpos( $all['subject'], '2 new PHP fatal errors' ) );
$many = array();
for ( $i = 0; $i < 8; $i++ ) { $many[] = array( 'time' => '2026-11-20 12:0' . $i . ':00', 'msg' => 'm' . $i, 'file' => 'f.php', 'line' => $i, 'uri' => '/' ); }
check( 'more than five are summarised', false !== strpos( db_alert_body( $many, 0, 'h' )['body'], '... and 3 more.' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
