<?php
/**
 * Offline test for db_index_top10_content() and db_index_is_top10_archive() (SE-14).
 *
 *     php tests/seo/top10-archive-test.php
 */

define( 'ABSPATH', __DIR__ );
function add_filter() {}
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function home_url( $p = '/' ) { return 'https://example.test' . $p; }
function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
class WP_Post { public $post_type = 'post'; public $post_name = ''; }
function get_post( $p ) { return $p; }
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-index-policy-block.php';

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

$html = '<table><tr><th>Domain Name</th><th>Buy Now</th></tr>'
	. '<tr><td><a title="Depending.ca" href="JavaScript:void(0);">Depending.ca</a></td><td>10%</td><td>$3,000</td><td><span class="buy-now-dmn">Buy Now</span></td></tr>'
	. '<tr><td><a title="Live.ca" href="javascript:void(0)">Live.ca</a></td><td><span class="buy-now-dmn">Buy Now</span></td></tr>'
	. '<tr class="top-10-sold"><td>Lavishhomes.ca</td><td><span class="sold-dmn">SOLD</span></td></tr></table>'
	. '<p><a href="JavaScript:void(0);">stray</a></p>';
$live = function ( $n ) { return 'Live.ca' === $n ? 'https://example.test/domains/live-ca/' : ''; };
$out  = db_index_top10_content( $html, $live, 'July 2018' );
ok( false === stripos( $out, 'javascript:' ), 'no javascript: link is left' );
ok( false !== strpos( $out, '<td>Depending.ca</td>' ) && false !== strpos( $out, 'No longer listed' ), 'a name no longer listed is plain text, marked as gone' );
ok( false !== strpos( $out, '<a href="https://example.test/domains/live-ca/">Live.ca</a>' ) && false !== strpos( $out, 'See today&rsquo;s price' ), 'a live name links to its page' );
ok( 2 === substr_count( $out, 'buy-now-dmn' ) && false === strpos( $out, '"buy-now-dmn">Buy Now' ), 'every dead Buy Now is replaced' );
ok( false !== strpos( $out, 'SOLD' ) && false !== strpos( $out, '<p>stray</p>' ), 'sold rows and stray links keep their text' );
ok( 0 === strpos( $out, '<p class="db-archive-note"><strong>From our archive (July 2018).</strong>' ) && false !== strpos( $out, '/all-domains/' ), 'archive note with the month comes first' );

$p = new WP_Post();
foreach ( array( 'top-10-domains-of-may' => true, 'top-10-domains-of-may-1' => true, 'top-10-premium-domains' => true, 'top-10-domain-name-trending-keywords-in-2016-trump-research-pokemon-bot' => false, 'top-10-domains-of-may-guide' => false ) as $slug => $want ) {
	$p->post_name = $slug;
	ok( $want === db_index_is_top10_archive( $p ), "archive match for $slug" );
}

echo "\n" . ( $n - $fails ) . "/$n passed\n";
exit( $fails ? 1 : 0 );
