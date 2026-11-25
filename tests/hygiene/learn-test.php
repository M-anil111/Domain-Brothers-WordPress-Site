<?php
/** Offline tests for db-search-log-block.php and db-learn-pages-block.php. Run: php tests/hygiene/learn-test.php */

define( 'ABSPATH', '/' );
function add_action() {}
function add_filter() {}
function esc_url( $u ) { return htmlspecialchars( $u, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); }
function home_url( $p = '/' ) { return 'https://example.test' . $p; }
require __DIR__ . '/../../db-custom-blocks/db-search-log-block.php';
require __DIR__ . '/../../db-custom-blocks/db-learn-pages-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

// ---- search log
check( 'term is lowercased and tidied', 'blue sky' === db_sl_normalise( "  Blue   SKY \n" ) );
check( 'html is stripped', 'hello' === db_sl_normalise( '<b>Hello</b>' ) );
check( 'email-like terms are dropped', '' === db_sl_normalise( 'me@example.com' ) );
check( 'links are dropped', '' === db_sl_normalise( 'https://spam.example' ) && '' === db_sl_normalise( 'www.spam.example' ) );
check( 'one-character terms are dropped', '' === db_sl_normalise( 'a' ) );
check( 'long terms are cut at 60', 60 === strlen( db_sl_normalise( str_repeat( 'x', 100 ) ) ) );
$log = db_sl_add( array(), 'Shoes', 0, 100 );
$log = db_sl_add( $log, 'shoes', 3, 200 );
check( 'repeat searches are counted together', 2 === $log['shoes']['n'] );
check( 'misses count only searches that found nothing', 1 === $log['shoes']['miss'] );
check( 'last searched time is kept', 200 === $log['shoes']['last'] );
check( 'dropped terms leave the log unchanged', $log === db_sl_add( $log, 'a@b.co', 0, 300 ) );
$big = array();
for ( $i = 0; $i < 5; $i++ ) { $big = db_sl_add( $big, 'popular term', 1, 500 ); }
for ( $i = 0; $i < DB_SEARCH_LOG_MAX + 20; $i++ ) { $big = db_sl_add( $big, 'rare term ' . $i, 0, 1000 + $i ); }
check( 'log is capped', count( $big ) <= DB_SEARCH_LOG_MAX );
check( 'the most searched term survives the cap', isset( $big['popular term'] ) && 5 === $big['popular term']['n'] );
$full = array();
for ( $i = 0; $i < DB_SEARCH_LOG_MAX; $i++ ) { $full = db_sl_add( $full, 'old term ' . $i, 1, 1000 ); $full = db_sl_add( $full, 'old term ' . $i, 1, 1000 ); }
$full = db_sl_add( $full, 'brand new term', 1, 1000 + 120 * 86400 );
check( 'AD-8: a new term is kept once every stored term has two older searches', isset( $full['brand new term'] ) );

// ---- glossary
$terms = db_gl_terms();
$names = array_column( $terms, 0 );
check( 'glossary has at least 25 terms', count( $terms ) >= 25 );
check( 'terms are unique', count( $names ) === count( array_unique( array_map( 'strtolower', $names ) ) ) );
check( 'terms are alphabetical', $names === ( function ( $n ) { usort( $n, 'strcasecmp' ); return $n; } )( $names ) );
check( 'every definition is a full sentence of sensible length', 0 === count( array_filter( $terms, function ( $t ) { return strlen( $t[1] ) < 60 || strlen( $t[1] ) > 320 || '.' !== substr( $t[1], -1 ); } ) ) );
$anchors = array_map( 'db_gl_anchor', $names );
check( 'anchors are unique and clean', count( $anchors ) === count( array_unique( $anchors ) ) && 0 === count( array_filter( $anchors, function ( $a ) { return ! preg_match( '/^t-[a-z0-9-]+$/', $a ); } ) ) );
$body = db_gl_body();
check( 'page lists every term once as a definition', count( $terms ) === substr_count( $body, '<dt ' ) );
check( 'page links to the tools and the call to action', false !== strpos( $body, '/domain-tools/' ) && false !== strpos( $body, 'Buy a premium domain now' ) );

// ---- price index
check( 'median of odd list', 3.0 === db_pi_median( array( 5, 1, 3 ) ) );
check( 'median of even list', 2.5 === db_pi_median( array( 4, 1, 2, 3 ) ) );
$rows = array();
foreach ( array( 1000, 2000, 3000 ) as $p ) { $rows[] = array( 'len' => 4, 'ext' => 'com', 'price' => $p ); }
foreach ( array( 500, 700 ) as $p ) { $rows[] = array( 'len' => 5, 'ext' => 'ca', 'price' => $p ); }
foreach ( array( 100, 200, 300, 400 ) as $p ) { $rows[] = array( 'len' => 9, 'ext' => 'io', 'price' => $p ); }
$s = db_pi_stats( $rows );
check( 'groups under three names are left out', ! isset( $s['by_len']['5'] ) && ! isset( $s['by_ext']['ca'] ) );
check( 'median, low and high per group', 2000.0 === $s['by_len']['4']['median'] && 1000.0 === $s['by_len']['4']['low'] && 3000.0 === $s['by_len']['4']['high'] );
check( 'eight and longer are one group', 4 === $s['by_len']['8+']['n'] );
check( 'other extensions are grouped', 4 === $s['by_ext']['other']['n'] && 3 === $s['by_ext']['com']['n'] );
check( 'total counts every row', 9 === $s['total'] );
check( 'length groups come out in order', array( '4', '8+' ) === array_map( 'strval', array_keys( $s['by_len'] ) ) );
check( 'empty inventory still renders a page', false !== strpos( db_pi_body(), 'Not enough names' ) );
check( 'both learn pages are registered', isset( db_learn_pages()['domain-glossary'], db_learn_pages()['domain-price-index'] ) );

// ---- market pulse
$ask = array();
foreach ( array( 1200, 2400, 3000, 4800, 6000, 9000, 9500, 12000, 20000, 30000 ) as $pr ) { $ask[] = array( 'len' => 6, 'ext' => 'com', 'price' => $pr ); }
$pd = db_pulse_compute( $ask, 7 );
check( 'pulse: counts, median and lowest', 10 === $pd['count'] && 7500.0 === $pd['median'] && 1200.0 === $pd['low'] && 7 === $pd['sold'] );
check( 'pulse: bands add up to the count', 10 === array_sum( array_column( $pd['bands'], 1 ) ) && array( 2, 2, 3, 2, 1 ) === array_column( $pd['bands'], 1 ) );
check( 'pulse: too few names gives nothing', array() === db_pulse_compute( array( array( 'price' => 100 ), array( 'price' => 200 ) ), 0 ) && '' === db_pulse_html( array() ) );
check( 'pulse: zero and junk prices are ignored', 10 === db_pulse_compute( array_merge( $ask, array( array( 'price' => 0 ), array( 'price' => 'x' ) ) ), 0 )['count'] );
$ph = db_pulse_html( $pd, '/domain-price-index/' );
check( 'pulse: markup has heading, four tiles and five bars', false !== strpos( $ph, 'id="db-pulse-h"' ) && 4 === substr_count( $ph, 'class="db-pulse-tile"' ) && 5 === substr_count( $ph, 'class="db-pulse-fill"' ) );
check( 'pulse: sold tile hidden under three sales', 3 === substr_count( db_pulse_html( db_pulse_compute( $ask, 2 ) ), 'class="db-pulse-tile"' ) );
check( 'pulse: motion is switched off under reduced motion', false !== strpos( $ph, 'prefers-reduced-motion:reduce' ) );
check( 'pulse: bar widths are percentages of the largest band', false !== strpos( $ph, 'width:100%' ) && false !== strpos( $ph, 'width:33%' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
