<?php
/** Offline tests for db-page-actions-block.php. Run: php tests/hygiene/page-actions-test.php */

define( 'ABSPATH', '/' );
require __DIR__ . '/../../db-custom-blocks/db-page-actions-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

// ---- proof lines: only real numbers, only when they mean something
check( 'proof: nothing when every count is small', array() === db_proof_lines( array( 'saves' => 1, 'buyers30' => 1, 'sold_ext' => 2, 'sold90' => 1, 'ext' => '.ca' ) ) );
check( 'proof: empty input gives nothing', array() === db_proof_lines( array() ) );
$l = db_proof_lines( array( 'saves' => 3, 'buyers30' => 2, 'sold_ext' => 31, 'sold90' => 5, 'ext' => '.ca' ) );
check( 'proof: at most three lines', 3 === count( $l ) );
check( 'proof: buyers line comes first and states the real number', '2 buyers have made an offer on this domain in the last 30 days.' === $l[0] );
check( 'proof: saves line states the real number', in_array( '3 people have saved this domain to their wishlist.', $l, true ) );
check( 'proof: extension line needs 3 or more and a valid extension', array() === db_proof_lines( array( 'sold_ext' => 9, 'ext' => 'bad ext' ) ) && 1 === count( db_proof_lines( array( 'sold_ext' => 3, 'ext' => '.com' ) ) ) );
check( 'proof: counts are not rounded or inflated', false !== strpos( implode( ' ', db_proof_lines( array( 'saves' => 7 ) ) ), '7 people' ) );

// ---- the Back and Share bar
$h = db_pa_html( array( 'back_url' => 'https://x.test/domains/a-com/', 'back_label' => 'Back to a.com', 'share_url' => 'https://x.test/domains/a-com/', 'share_title' => 'a.com "for" <sale>' ) );
check( 'bar: has a back link and a share button', false !== strpos( $h, 'class="db-pa-btn db-pa-back" href="https://x.test/domains/a-com/"' ) && false !== strpos( $h, 'class="db-pa-btn db-pa-share"' ) );
check( 'bar: titles and labels are escaped', false === strpos( $h, '<sale>' ) && false !== strpos( $h, '&lt;sale&gt;' ) );
check( 'bar: share confirmation is announced politely', false !== strpos( $h, 'aria-live="polite"' ) );
check( 'bar: empty input gives nothing', '' === db_pa_html( array() ) );
check( 'bar: back only works too', false === strpos( db_pa_html( array( 'back_url' => '/x/' ) ), '<button' ) );
check( 'bar: motion is off under reduced motion', false !== strpos( $h, 'prefers-reduced-motion:reduce' ) );

// ---- the script runs: share falls back to copying, back follows the link when there is no history
$dir  = sys_get_temp_dir() . '/db-pa-' . getmypid();
mkdir( $dir );
file_put_contents( $dir . '/pa.js', db_pa_js() );
$node = trim( (string) shell_exec( 'command -v node' ) );
if ( $node ) {
	check( 'script: valid JavaScript', 0 === (int) shell_exec( 'node --check ' . escapeshellarg( $dir . '/pa.js' ) . ' >/dev/null 2>&1; echo $?' ) );
	$harness = <<<JS
let copied = null, handler = null; const note = { textContent: '' };
global.window = {}; global.location = { href: 'https://x.test/p/', origin: 'https://x.test' };
global.document = { referrer: '', addEventListener: (t, f) => { handler = f; }, body: { appendChild() {} }, createElement: () => ({ select() {}, remove() {} }), execCommand: () => true, title: 'T' };
Object.defineProperty(global, 'navigator', { value: { clipboard: { writeText: u => { copied = u; return Promise.resolve(); } } }, configurable: true });
global.history = { length: 1, back() { global.wentBack = true; } };
global.setTimeout = () => 0;
require(process.argv[2]);
const share = { classList: { contains: c => c === 'db-pa-share' }, getAttribute: a => ({ 'data-url': 'https://x.test/domains/a-com/', 'data-title': 'a.com' }[a]), parentNode: { querySelector: () => note } };
handler({ target: { closest: () => share }, preventDefault() {} });
const back = { classList: { contains: c => c === 'db-pa-back' }, getAttribute: () => null };
let prevented = false; handler({ target: { closest: () => back }, preventDefault() { prevented = true; } });
setTimeout = global.setTimeout;
Promise.resolve().then(() => Promise.resolve()).then(() => { console.log([copied, note.textContent, prevented, !!global.wentBack].join('|')); });
JS;
	file_put_contents( $dir . '/h.js', $harness );
	$res = trim( (string) shell_exec( 'node ' . escapeshellarg( $dir . '/h.js' ) . ' ' . escapeshellarg( $dir . '/pa.js' ) . ' 2>&1' ) );
	$p = explode( '|', $res );
	check( 'script: share copies the page link when the device cannot share', 'https://x.test/domains/a-com/' === ( $p[0] ?? '' ) );
	check( 'script: share says the link was copied', 'Link copied' === ( $p[1] ?? '' ) );
	check( 'script: back follows its link when there is no history', 'false' === ( $p[2] ?? '' ) && 'false' === ( $p[3] ?? '' ) );
} else {
	echo "SKIP node not installed\n";
}
array_map( 'unlink', glob( $dir . '/*' ) );
rmdir( $dir );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
