<?php
/**
 * Offline test for db-onpage-seo-block.php markup fixes: new-tab rel,
 * https upgrades, duplicate ids, commented-out headings, /payment-cancel/
 * asset URLs, and imported-news cleanup (trackers, affiliate links, ids).
 *
 *     php tests/seo/html-hygiene-test.php
 */

define( 'ABSPATH', __DIR__ );
function add_action() {}
function add_filter() {}
function home_url() { return 'https://beta.domainbrothers.com'; }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s ); }
function db_seo_utility_slugs() { return array( 'payment-cancel' => 'Payment Cancelled | Domain Brothers' ); }
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-onpage-seo-block.php';

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

// Page-level hygiene.
$GLOBALS['db_onpage_ctx'] = array( 'singular' => true, 'title' => 'Sold', 'payment_cancel' => true );
$out = db_onpage_html_hygiene(
	'<title>DomainBrothers</title><link href="https://beta.domainbrothers.comwp-content/a.css">'
	. '<a href="https://x.example" target="_blank">x</a>'
	. '<a target=\'_blank\' rel="nofollow" href="http://www.domaining.com/h">y</a>'
	. '<a href="http://holi.ca" target="_blank">z</a>'
	. '<a rel=”nofollow” href="https://sso.godaddy.com/" target="_blank">g</a>'
	. '<div id="browse_by_domain"></div><div id="browse_by_domain"></div><div id="browse_by_domain"></div>'
);
ok( false !== strpos( $out, '<a rel="noopener" href="https://x.example" target="_blank">' ), 'target=_blank link gains rel="noopener"' );
ok( false !== strpos( $out, 'rel="nofollow noopener" href="https://www.domaining.com/h"' ), 'existing rel kept + noopener appended; allowlisted host upgraded to https' );
ok( false !== strpos( $out, 'href="http://holi.ca"' ), 'host without working TLS is not rewritten' );
ok( false !== strpos( $out, 'rel="nofollow noopener" href="https://sso.godaddy.com/"' ), 'curly-quoted rel is normalised' );
ok( 1 === substr_count( $out, 'id="browse_by_domain"' ) && false !== strpos( $out, 'id="browse_by_domain-3"' ), 'repeated id numbered -2, -3' );
ok( false !== strpos( $out, '.com/wp-content/a.css' ) && false !== strpos( $out, '<title>Payment Cancelled | Domain Brothers</title>' ), '/payment-cancel/ asset URL and title repaired' );

// Headings inside comments.
ok( ! db_onpage_has_live_h1( '<!--<h1 class="page-title">x</h1>--><h2>y</h2>' ), 'commented-out <h1> does not count' );
ok( db_onpage_has_live_h1( '<!-- c --><h1>ok</h1>' ), 'live <h1> after a comment counts' );
$sold = '<body><main id="main"><!--<h2 class="page-title">Sold Names &mdash; <span>78</span></h2>--><h2 class="page-title">Sold Names</h2></main></body>';
$out  = db_onpage_fix_h1_and_alt( $sold );
ok( false !== strpos( $out, '<h1 class="page-title">Sold Names</h1>' ) && false !== strpos( $out, '<!--<h2 class="page-title">' ), 'visible heading promoted, commented copy untouched' );
$GLOBALS['db_onpage_ctx']['title'] = 'Payment Notification';
$out = db_onpage_fix_h1_and_alt( '<body class="x"><p>no main here</p></body>' );
ok( false !== strpos( $out, '<body class="x"><h1 class="screen-reader-text">Payment Notification</h1>' ), 'page without <main> gets its <h1> after <body>' );

// Imported news content.
$out = db_onpage_clean_imported_content(
	'<p id="main">x</p><span id="yui_3_16_0_1">y</span><h2 id="pricing">keep</h2>'
	. '<a href="http://www.kqzyfj.com/click-1" onmouseover="window.status=\'http://www.godaddy.com\';return true;" onmouseout="window.status=\' \';return true;">deal</a>'
	. '<img src="http://www.ftjcfx.com/image-1" width="1" height="1" border="0"/>'
	. '<iframe src="//ws-na.amazon-adsystem.com/widgets/q?x"></iframe>'
	. '<img src="http://techdigg.com/a.jpg">'
	. '<a rel="noopener" href="https://amzn.to/abc">b</a>'
);
ok( false === strpos( $out, 'ftjcfx' ) && false === strpos( $out, 'amazon-adsystem' ), 'affiliate tracking pixel and widget removed' );
ok( false !== strpos( $out, '<a rel="sponsored nofollow" href="http://www.kqzyfj.com/click-1">' ), 'affiliate link marked sponsored, status-bar spoofing removed' );
ok( false !== strpos( $out, 'rel="noopener sponsored nofollow" href="https://amzn.to/abc"' ), 'existing rel kept on Amazon link' );
ok( false !== strpos( $out, '<img src="https://techdigg.com/a.jpg">' ), 'http image upgraded to https' );
ok( false === strpos( $out, 'id="main"' ) && false === strpos( $out, 'yui_' ) && false !== strpos( $out, 'id="pricing"' ), 'clashing/junk ids stripped, real anchors kept' );

echo "\n" . ( $n - $fails ) . "/$n passed\n";
exit( $fails ? 1 : 0 );
