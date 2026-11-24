<?php
/**
 * Offline test for db_strip_webfont_css(): page content stops loading Figtree from Google Fonts.
 *
 *     php tests/hygiene/webfont-strip-test.php
 */

$src = file_get_contents( dirname( __DIR__, 2 ) . '/db-custom-blocks/db-custom-blocks.php' );
if ( ! preg_match( "/if \\( ! function_exists\\( 'db_strip_webfont_css' \\) \\) \\{.*?\\n}\\n/s", $src, $m ) ) {
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

$in  = "<style>\n@import url('https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap');\nbody.page-id-1880{overflow-x:clip}\n#dba{font-family:'Figtree',ui-sans-serif,system-ui,sans-serif}\n.x{font-family:\"Figtree\", Arial}\n</style><p>Figtree Lane</p>";
$out = db_strip_webfont_css( $in );
ok( false === strpos( $out, 'fonts.googleapis.com' ), 'the Google Fonts import is removed' );
ok( false !== strpos( $out, 'font-family:ui-sans-serif,system-ui,sans-serif' ) && false !== strpos( $out, 'font-family:Arial' ), 'Figtree is dropped from font stacks, the rest of each stack stays' );
ok( false !== strpos( $out, 'body.page-id-1880{overflow-x:clip}' ), 'other CSS is untouched' );
ok( false !== strpos( $out, '<p>Figtree Lane</p>' ), 'the word in text (not a quoted font name) is untouched' );
$plain = '<p>No fonts here</p>';
ok( $plain === db_strip_webfont_css( $plain ), 'content without Figtree is returned unchanged' );

echo "\n" . ( $n - $fails ) . "/$n passed\n";
exit( $fails ? 1 : 0 );
