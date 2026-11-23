<?php
/**
 * Offline test for db_hp_main_wraps_content() (AX-17): the front page's later sections end up
 * inside <main>. The function is read from db-custom-blocks.php without loading WordPress.
 *
 *     php tests/a11y/home-landmark-test.php
 */

$src = file_get_contents( dirname( __DIR__, 2 ) . '/db-custom-blocks/db-custom-blocks.php' );
if ( ! preg_match( "/if \\( ! function_exists\\( 'db_hp_main_wraps_content' \\) \\) \\{.*?\\n}\\n/s", $src, $m ) ) {
	echo "FAIL function not found\n";
	exit( 1 );
}
eval( '?>' . "<?php\n" . $m[0] );

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

$page = '<body><div id="page"><div id="content" class="site-content"><div id="primary"><main id="main"><section class="dbx">A</section></main>
</div><script>var t = "<div>";</script><section class="db-pulse">B</section><div class="container"><div>C</div></div>
</div></div><footer id="colophon">F</footer></body>';
$out  = db_hp_main_wraps_content( $page );
$main = substr( $out, strpos( $out, '<main' ), strpos( $out, '</main>' ) - strpos( $out, '<main' ) );
ok( false !== strpos( $main, 'db-pulse' ) && false !== strpos( $main, '<div>C</div>' ), 'later sections are inside main' );
ok( false !== strpos( $out, '</main>' . "\n" . '</div></div></div><footer' ), 'main and #primary close just before #content closes' );
ok( substr_count( $out, '<div' ) === substr_count( $page, '<div' ) && substr_count( $out, '</div>' ) === substr_count( $page, '</div>' ), 'no tag is added or lost' );
ok( false !== strpos( $main, 'var t = "<div>";' ), 'a <div> inside a script does not upset the count' );
$done = db_hp_main_wraps_content( $out );
ok( $done === $out, 'running twice changes nothing' );
ok( 'x' === db_hp_main_wraps_content( 'x' ), 'pages without #content are untouched' );
$nomain = '<div id="content"><p>no main</p></div>';
ok( $nomain === db_hp_main_wraps_content( $nomain ), 'pages without main are untouched' );
$broken = '<div id="content"><div id="primary"><main>A</main></div><div>unclosed';
ok( $broken === db_hp_main_wraps_content( $broken ), 'unbalanced markup is untouched' );

echo "\n" . ( $n - $fails ) . "/$n passed\n";
exit( $fails ? 1 : 0 );
