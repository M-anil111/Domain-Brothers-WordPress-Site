<?php
/** Offline tests for db-offer-page-block.php. Run: php tests/hygiene/offer-page-test.php */

define( 'ABSPATH', '/' );
function add_filter() {}
function add_action() {}
function is_admin() { return false; }
function home_url( $p = '/' ) { return 'https://beta.example.test' . $p; }
function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function do_shortcode( $s ) { return '<div class="wpcf7">FORM</div>'; }
function db_offer_resolved_form_id() { return 1878; }
function db_pa_html( $a ) { return '<div class="db-pa">' . htmlspecialchars( $a['back_label'] ?? '' ) . '</div>'; }
require __DIR__ . '/../../db-custom-blocks/db-offer-page-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

check( 'domain: plain names are accepted and lower-cased', 'lyzux.com' === db_op_clean_domain( ' LyZux.com ' ) && 'shop.co.uk' === db_op_clean_domain( 'shop.co.uk' ) );
check( 'domain: markup, spaces, schemes and paths are refused', '' === db_op_clean_domain( '<script>.com' ) && '' === db_op_clean_domain( 'a b.com' ) && '' === db_op_clean_domain( 'https://a.com/x' ) && '' === db_op_clean_domain( 'nodot' ) && '' === db_op_clean_domain( '' ) );
check( 'domain: very long names are refused', '' === db_op_clean_domain( str_repeat( 'a', 70 ) . '.com' ) );
check( 'amount: $ signs, commas and spaces are removed', 11000 === db_op_clean_amount( '$11,000' ) && 2500 === db_op_clean_amount( ' 2 500 ' ) );
check( 'amount: decimals are dropped, not multiplied', 2500 === db_op_clean_amount( '2500.99' ) );
check( 'amount: junk, empty and huge values give 0', 0 === db_op_clean_amount( 'abc' ) && 0 === db_op_clean_amount( '' ) && 0 === db_op_clean_amount( '99999999999' ) );
check( 'amount: negative signs are not honoured', 5 === db_op_clean_amount( '-5' ) );
check( 'suggest: 80% of the price to the nearest hundred, at least $100', 10000 === db_op_suggest( 12500 ) && 100 === db_op_suggest( 50 ) && 0 === db_op_suggest( 0 ) );
check( 'url: carries the domain and the amount', 'https://beta.example.test/make-an-offer/?d=a.com&amt=500' === db_op_url( 'a.com', 500 ) && false === strpos( db_op_url( 'a.com' ), 'amt' ) );

$bad = db_op_body( array( 'ok' => false ) );
check( 'body: an unknown domain says so and links to the listings', false !== strpos( $bad, 'We could not find that domain' ) && false !== strpos( $bad, '/all-domains/' ) );
$post = new stdClass(); $post->ID = 0;
$ok = db_op_body( array( 'ok' => true, 'name' => 'lyzux.com', 'post' => null, 'price' => 12500.0, 'amount' => 11000, 'url' => 'https://beta.example.test/domains/lyzux-com/' ) );
check( 'body: shows the colored domain name, the list price and the offer', false !== strpos( $ok, '<span class="db-op-name">lyzux.com</span>' ) && false !== strpos( $ok, '$12,500' ) && false !== strpos( $ok, '$11,000' ) );
check( 'body: includes the form, the back bar and the hidden success panel', false !== strpos( $ok, 'class="wpcf7"' ) && false !== strpos( $ok, 'Back to lyzux.com' ) && false !== strpos( $ok, 'id="db-op-done"' ) && false !== strpos( $ok, 'hidden' ) );
check( 'body: the broken icon discs are hidden', false !== strpos( $ok, '.frm-field i,.db-op .wpcf7 .frm-field .fa{display:none!important}' ) );
check( 'body: the domain name is escaped everywhere', false === strpos( db_op_body( array( 'ok' => true, 'name' => '"><b>x</b>', 'post' => null, 'price' => 0.0, 'amount' => 0, 'url' => '/x/' ) ), '<b>x</b>' ) );
check( 'body: no price shows Open to offers and no amount shows Not set', false !== strpos( db_op_body( array( 'ok' => true, 'name' => 'a.com', 'post' => null, 'price' => 0.0, 'amount' => 0, 'url' => '/x/' ) ), 'Open to offers' ) );

$dir = sys_get_temp_dir() . '/db-op-' . getmypid();
mkdir( $dir );
file_put_contents( $dir . '/op.js', db_op_js( 'lyzux.com' ) );
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( $node ) {
	check( 'script: valid JavaScript', 0 === (int) shell_exec( 'node --check ' . escapeshellarg( $dir . '/op.js' ) . ' >/dev/null 2>&1; echo $?' ) );
}
check( 'script: the domain is passed as data, not code', false !== strpos( db_op_js( '";alert(1);//' ), '\\";alert(1);\\/\\/' ) );
array_map( 'unlink', glob( $dir . '/*' ) );
rmdir( $dir );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
