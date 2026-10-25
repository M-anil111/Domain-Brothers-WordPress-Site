<?php
/**
 * Offline test for <title> generation in db-seo-meta-block.php:
 * db_seo_post_title() shortening and escaping in pre_get_document_title.
 *
 *     php tests/seo/title-test.php
 */

define( 'ABSPATH', __DIR__ );
$GLOBALS['FILTERS'] = array();
function add_filter( $hook, $cb ) { $GLOBALS['FILTERS'][ $hook ][] = $cb; }
function add_action( $hook, $cb ) { $GLOBALS['FILTERS'][ $hook ][] = $cb; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function strip_shortcodes( $s ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false ); }
// Stand-in for the request's SEO definition; the test sets $GLOBALS['DEF'].
function db_seo_current() { return $GLOBALS['DEF']; }
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-seo-meta-block.php';

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

$b = DB_SEO_SUFFIX;
ok( 'Short title here' . $b === db_seo_post_title( 'Short title here', $b ), 'short title keeps the brand suffix' );
$t1 = db_seo_post_title( "Domain Shane's Daily List of Domains at Auction for Sunday March 11th", $b );
$t2 = db_seo_post_title( "Domain Shane's Daily List of Domains at Auction for Monday March 12th", $b );
ok( $t1 !== $t2 && mb_strlen( $t1 ) <= 65 && false !== strpos( $t1, 'March 11th' ), 'series titles stay distinct and within 65 characters' );
$g = db_seo_post_title( 'Sedo released their weekly domain name sales and gcp.com led the way', $b );
ok( false !== strpos( $g, 'gcp.com' ), 'domain name in the middle is kept as the tail' );
$long = str_repeat( 'x', 80 );
ok( mb_strlen( db_seo_post_title( $long, $b ) ) === 62 && 0 === strpos( db_seo_post_title( $long, $b ), 'xxxx' ), 'single long word is cut, not reduced to "…"' );
$d = db_seo_post_title( 'Why Domain Investors Should Stop Settling For Less And Demand More From Registrars Today', $b );
ok( false === strpos( $d, 'Dem…' ) && false === strpos( $d, ' Dem' . "\n" ), 'words ending in "…and more" letters are not chopped' );
ok( false === strpos( db_seo_post_title( 'Everyone in the domain industry is starting to command more for short names now', $b ), 'Comm' . "\u{2026}" ), '"command more" is not treated as "& more" filler' );
ok( 'Domain Brothers' === db_seo_post_title( '', $b ), 'empty title falls back to the site name' );

// Escaping in the document-title filter.
$cb = $GLOBALS['FILTERS']['pre_get_document_title'][0] ?? null;
ok( is_callable( $cb ), 'pre_get_document_title filter registered' );
$GLOBALS['DEF'] = array( 'title' => db_seo_post_title( 'Hi &lt;/title&gt;&lt;script&gt;alert(1)&lt;/script&gt;', $b ) );
$out = $cb( '' );
ok( false === strpos( $out, '<script>' ) && false === strpos( $out, '</title>' ) && false !== strpos( $out, '&lt;script&gt;' ), 'encoded markup in a post title is escaped in <title>' );
$GLOBALS['DEF'] = array( 'title' => 'Terms &amp; Conditions | Domain Brothers' );
ok( 'Terms &amp; Conditions | Domain Brothers' === $cb( '' ), 'existing entities are not double-encoded' );

echo "\n" . ( $n - $fails ) . "/$n passed\n";
exit( $fails ? 1 : 0 );
