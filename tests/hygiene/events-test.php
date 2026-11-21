<?php
/** Offline tests for db-events-block.php. Run: php tests/hygiene/events-test.php (needs node for the script run) */

define( 'ABSPATH', '/' );
function add_action() {}
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
require __DIR__ . '/../../db-custom-blocks/db-events-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$c = db_ev_config( array( 'type' => 'domain', 'name' => "Blue<script>sky.COM", 'price' => '5000.4', 'ext' => '.com', 'len' => 7, 'state' => 'weird' ) );
check( 'name is cleaned and lowercased', 'bluescriptsky.com' === $c['name'] );
check( 'price is an integer', 5000 === $c['price'] );
check( 'unknown state falls back to offer', 'offer' === $c['state'] );
check( 'unknown type becomes other with no extras', array( 'type' => 'other' ) === db_ev_config( array( 'type' => 'x', 'name' => 'a.com' ) ) );
check( 'tool slug is cleaned', 'dns-lookup' === db_ev_config( array( 'type' => 'tool', 'tool' => 'DNS-Lookup/../' ) )['tool'] );
check( 'negative price clamps to zero', 0 === db_ev_config( array( 'type' => 'domain', 'price' => -5 ) )['price'] );

$js = db_ev_script( $c );
check( 'script cannot close its own tag', false === stripos( $js, '</script' ) );
$dir = sys_get_temp_dir() . '/db-ev-' . getmypid();
mkdir( $dir );
$file = $dir . '/ev.js';
file_put_contents( $file, $js );
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( $node ) {
	check( 'script is valid JavaScript', 0 === (int) shell_exec( 'node --check ' . escapeshellarg( $file ) . ' >/dev/null 2>&1; echo $?' ) );
	$harness = <<<JS
const L = []; global.window = { dataLayer: L }; const handlers = {}; global.location = { pathname: '/offer/' }; const store = {}; global.sessionStorage = { getItem: k => store[k] || null, setItem: (k, v) => { store[k] = v; } };
global.document = { addEventListener: (t, f) => { handlers[t] = f; } };
require(process.argv[2]);
const out = [];
out.push(L.map(e => e.event).join(','));
const mk = (href, text) => ({ target: { closest: () => ({ getAttribute: () => href, textContent: text }) } });
handlers.click(mk('https://x.test/buy-now/?d=1', 'Buy now'));
handlers.click(mk('https://x.test/payment-plan-setup/?d=1', 'Plan'));
handlers.click(mk('#', 'Make an offer'));
handlers.click(mk('/about/', 'About'));
handlers.wpcf7mailsent();
handlers.submit({ target: { matches: s => s.indexOf('form[role=search]') > -1, querySelector: () => ({ value: 'secret@example.com' }) } });
out.push(L.map(e => e.event + (e.method ? ':' + e.method : '')).join(','));
out.push(JSON.stringify(L).indexOf('secret') === -1 ? 'no-leak' : 'LEAK');
console.log(out.join('|'));
JS;
	file_put_contents( $dir . '/h.js', $harness );
	$res = trim( (string) shell_exec( 'node ' . escapeshellarg( $dir . '/h.js' ) . ' ' . escapeshellarg( $file ) . ' 2>&1' ) );
	$parts = explode( '|', $res );
	check( 'view_item fires on load', 'view_item' === ( $parts[0] ?? '' ) );
	check( 'clicks and lead map to the right events', 'view_item,begin_checkout:pay_in_full,begin_checkout:payment_plan,offer_click,generate_lead,offer_sent,search' === ( $parts[1] ?? '' ) );
	check( 'search pushes length only, never the text', 'no-leak' === ( $parts[2] ?? '' ) );
	file_put_contents( $dir . '/other.js', db_ev_script( db_ev_config( array( 'type' => 'other' ) ) ) );
	$paid = <<<JS
const L = []; global.window = { dataLayer: L }; global.location = { pathname: process.argv[3] }; const store = {};
global.sessionStorage = { getItem: k => store[k] || null, setItem: (k, v) => { store[k] = v; } };
global.document = { addEventListener: () => {} };
const f = process.argv[2];
require(f); delete require.cache[require.resolve(f)]; require(f);
console.log(L.map(e => e.event).join(','));
JS;
	file_put_contents( $dir . '/p.js', $paid );
	$run = function ( $path ) use ( $dir ) { return trim( (string) shell_exec( 'node ' . escapeshellarg( $dir . '/p.js' ) . ' ' . escapeshellarg( $dir . '/other.js' ) . ' ' . escapeshellarg( $path ) . ' 2>&1' ) ); };
	check( 'purchase_complete fires once per session on the success page', 'purchase_complete' === $run( '/payment-successful/' ) );
	$offer_harness = <<<JS
const f = process.argv[2]; const out = [];
for (const path of ['/make-an-offer/', '/offer/', '/offer-fake/', '/']) {
  const L = []; let mail = null;
  global.window = { dataLayer: L }; global.location = { pathname: path };
  Object.defineProperty(global, 'sessionStorage', { value: { getItem: () => null, setItem() {} }, configurable: true });
  global.document = { addEventListener: (t, fn) => { if (t === 'wpcf7mailsent') { mail = fn; } } };
  delete require.cache[require.resolve(f)]; require(f);
  if (mail) { mail(); }
  out.push(path + ':' + L.map(e => e.event).join(','));
}
console.log(out.join('|'));
JS;
	file_put_contents( $dir . '/o.js', $offer_harness );
	$offers = trim( (string) shell_exec( 'node ' . escapeshellarg( $dir . '/o.js' ) . ' ' . escapeshellarg( $dir . '/other.js' ) . ' 2>&1' ) );
	check( 'offer_sent fires after the form is sent on /offer/ and /make-an-offer/, not elsewhere', '/make-an-offer/:generate_lead,offer_sent|/offer/:generate_lead,offer_sent|/offer-fake/:generate_lead|/:generate_lead' === $offers );
	check( 'purchase_complete does not fire on other pages', '' === $run( '/payment-successful-fake-page/' ) && '' === $run( '/' ) );
} else {
	echo "SKIP node not installed\n";
}
array_map( 'unlink', glob( $dir . '/*' ) );
rmdir( $dir );
echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
