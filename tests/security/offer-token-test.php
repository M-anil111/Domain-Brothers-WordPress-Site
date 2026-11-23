<?php
/**
 * Offline test: trackers are removed from private offer pages (SC-9).
 *
 *     php tests/security/offer-token-test.php
 */
define( 'ABSPATH', __DIR__ );
$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/db-custom-blocks/db-offers-block.php' );
preg_match( "#if \( ! function_exists\( 'db_offer_strip_trackers' \) \) \{.*?\n\}\n#s", $src, $m );
eval( '?><?php ' . $m[0] ); // phpcs:ignore Squiz.PHP.Eval

$pass = 0; $fail = 0;
function check( $name, $ok ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}
$page = '<head><script async src="https://www.googletagmanager.com/gtag/js?id=G-X"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag("config","G-X");</script>'
	. '<script type="text/db-delay" data-src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js"></script><script src="/wp-content/plugins/db-custom-blocks/x.js"></script></head>'
	. '<body><noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-X" height="0" width="0"></iframe></noscript><ins class="adsbygoogle" data-ad-slot="1"></ins><script>var dealForm=1;</script></body>';
$out = db_offer_strip_trackers( $page );
check( 'GA, GTM, AdSense and their noscript are removed', ! preg_match( '/googletagmanager|gtag\("config|adsbygoogle|googlesyndication/i', $out ) );
check( 'a no-op gtag stub is first in head', false !== strpos( $out, '<head><script data-no-delay>window.dataLayer=[];window.gtag=function(){};</script>' ) );
$own = '<head></head><script>(function(){var C={};var L=window.dataLayer;L&&L.push({event:"x"});gtag("event","offer_view");})();</script><script data-no-delay>(function(){var done=false;function ok(){var C=window.dbConsent;return !C||C.ok();}})();</script>';
check( 'site scripts that only call gtag() or push to dataLayer, and the delay loader, stay', db_offer_strip_trackers( $own ) === '<head><script data-no-delay>window.dataLayer=[];window.gtag=function(){};</script></head>' . substr( $own, 13 ) );
check( 'the site\'s own scripts stay', false !== strpos( $out, '/db-custom-blocks/x.js' ) && false !== strpos( $out, 'var dealForm=1;' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
