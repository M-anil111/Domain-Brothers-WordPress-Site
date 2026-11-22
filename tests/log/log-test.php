<?php
/** Offline tests for the pure parts of db-log-block.php. Run: php tests/log/log-test.php */

define( 'ABSPATH', '/var/www/' );
function add_action() {}
function add_filter() {}
require __DIR__ . '/../../db-custom-blocks/db-log-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$r = db_log_redact( 'Mail to jane.doe@example.com failed' );
check( 'email masked', 'Mail to [email] failed' === $r );
check( 'Stripe secret key masked', false === strpos( db_log_redact( 'key sk_test_51AbCdEfGhIjKlMn used' ), 'AbCdEf' ) );
check( 'webhook secret masked', false === strpos( db_log_redact( 'secret whsec_abcdef1234567890' ), 'abcdef1234' ) );
check( 'bearer token masked', 'auth Bearer [redacted]' === db_log_redact( 'auth Bearer abc.def-ghi_jkl123' ) );
check( 'card-like number masked', false === strpos( db_log_redact( 'card 4242 4242 4242 4242 declined' ), '4242 4242' ) );
check( 'password in a query string masked', '/x?password=[redacted]&a=1' === db_log_redact( '/x?password=hunter2&a=1' ) );
check( 'nonce in a query string masked', false === strpos( db_log_redact( '/wp-admin/?_wpnonce=abc123def' ), 'abc123def' ) );
check( 'ordinary text unchanged', 'Undefined index: price in file.php' === db_log_redact( 'Undefined index: price in file.php' ) );

check( 'repeats with different numbers fold together', db_log_fingerprint( 'php', 'warning', 'post 123 not found', 'a.php:1' ) === db_log_fingerprint( 'php', 'warning', 'post 456 not found', 'a.php:1' ) );
check( 'different places stay apart', db_log_fingerprint( 'php', 'warning', 'x', 'a.php:1' ) !== db_log_fingerprint( 'php', 'warning', 'x', 'b.php:1' ) );
check( 'different levels stay apart', db_log_fingerprint( 'php', 'warning', 'x' ) !== db_log_fingerprint( 'php', 'error', 'x' ) );

check( 'E_WARNING is a warning', 'warning' === db_log_level_of( E_WARNING ) );
check( 'E_USER_ERROR is an error', 'error' === db_log_level_of( E_USER_ERROR ) );
check( 'E_DEPRECATED is a notice', 'notice' === db_log_level_of( E_DEPRECATED ) );

$now = strtotime( '2026-11-22 12:00:00 UTC' );
check( 'nothing to archive when the oldest row is recent', array() === db_log_archive_months( '2026-03-04 10:00:00', $now ) );
check( 'months older than 12 months are archived, oldest first', array( '2025-09', '2025-10' ) === db_log_archive_months( '2025-09-14 08:00:00', $now ) );
check( 'the cut-off month itself is kept', ! in_array( '2025-11', db_log_archive_months( '2025-01-01 00:00:00', $now ), true ) );
check( 'a very old start is bounded (at most 240 months)', 240 === count( db_log_archive_months( '1990-01-01 00:00:00', $now ) ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
