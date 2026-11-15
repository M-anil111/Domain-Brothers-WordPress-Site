<?php
/**
 * Offline tests: third-party script delay and page-cache request rules.
 * Run: php tests/speed/speed-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/db-speed-test-' . getmypid() );
function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return $d; }
function add_action() {}
function esc_url( $u ) { return $u; }
require __DIR__ . '/../../db-custom-blocks/db-delay-block.php';
require __DIR__ . '/../../db-custom-blocks/pagecache/serve.php';

$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$h = db_delay_hosts();
$page = '<html><head>'
	. '<script>(function(w,d,s,l,i){j.src="https://www.googletagmanager.com/gtm.js?id="+i;})(window,document,"script","dataLayer","GTM-X");</script>'
	. '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1" crossorigin="anonymous"></script>'
	. '<script type="text/javascript" src="https://example.org/wp-includes/js/jquery/jquery.min.js" id="jquery-core-js"></script>'
	. '<script type="application/ld+json">{"@context":"https://schema.org","url":"https://www.googletagmanager.com/"}</script>'
	. '<script type="text/javascript" id="hs-script-loader" async defer src="//js.hs-scripts.com/1.js"></script>'
	. '</head><body><ins class="adsbygoogle"></ins><script>(adsbygoogle = window.adsbygoogle || []).push({});</script>'
	. '<script data-no-delay src="https://www.googletagmanager.com/keep.js"></script>'
	. '<script async src="https://static.addtoany.com/menu/page.js"></script>'
	. '<script>var x = "googletagmanager.com.evil.example";</script>'
	. '<script src="https://evil-googletagmanager.com.example.net/x.js"></script>'
	. '</body></html>';
$out = db_delay_rewrite( $page, $h );
check( 'GTM inline snippet delayed', false !== strpos( $out, '<script type="text/db-delay">(function(w,d,s,l,i)' ) );
check( 'AdSense external delayed with data-src', false !== strpos( $out, 'data-src="https://pagead2.googlesyndication.com' ) && false === strpos( $out, ' src="https://pagead2' ) );
check( 'crossorigin kept on delayed tag', (bool) preg_match( '#data-src="https://pagead2[^>]*crossorigin="anonymous"#', $out ) );
check( 'jQuery untouched', false !== strpos( $out, '<script type="text/javascript" src="https://example.org/wp-includes/js/jquery/jquery.min.js"' ) );
check( 'JSON-LD untouched', false !== strpos( $out, '<script type="application/ld+json">' ) );
check( 'HubSpot protocol-relative delayed, type replaced', false !== strpos( $out, '<script type="text/db-delay" id="hs-script-loader" async defer data-src="//js.hs-scripts.com/1.js">' ) );
check( 'adsbygoogle push queue left to run', false !== strpos( $out, '<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>' ) );
check( 'data-no-delay respected', false !== strpos( $out, '<script data-no-delay src="https://www.googletagmanager.com/keep.js">' ) );
check( 'AddToAny delayed', false !== strpos( $out, 'data-src="https://static.addtoany.com/menu/page.js"' ) );
check( 'look-alike host not delayed', false !== strpos( $out, '<script src="https://evil-googletagmanager.com.example.net/x.js">' ) );
check( 'loader added once before </body>', 1 === substr_count( $out, 'text/db-delay\\"]' ) + substr_count( $out, "script[type=\"text/db-delay\"]" ) && false !== strpos( $out, '})();</script></body>' ) );
check( 'page without third-party scripts unchanged', '<html><body><script>var a=1;</script></body></html>' === db_delay_rewrite( '<html><body><script>var a=1;</script></body></html>', $h ) );
check( 'fragment without </body> unchanged', '<script async src="https://www.googletagmanager.com/gtm.js"></script>' === db_delay_rewrite( '<script async src="https://www.googletagmanager.com/gtm.js"></script>', $h ) );

// Page-cache request rules. db_pc_request() memoises, so each case runs in a child process.
function req_case( array $server, array $cookie = array() ) {
	$code = 'define("DB_PC_TEST",1);define("ABSPATH","/");define("WP_CONTENT_DIR","/tmp/x");require ' . var_export( realpath( __DIR__ . '/../../db-custom-blocks/pagecache/serve.php' ), true ) . ';'
		. '$_SERVER=' . var_export( $server, true ) . ';$_COOKIE=' . var_export( $cookie, true ) . ';$r=db_pc_request();echo json_encode($r);';
	return json_decode( shell_exec( 'php -r ' . escapeshellarg( $code ) ), true );
}
$base = array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'beta.example.com', 'REQUEST_URI' => '/about-us/', 'HTTPS' => 'on' );
$a = req_case( $base );
check( 'plain GET is cacheable', is_array( $a ) && 'x' === $a['variant'] );
check( 'POST not cacheable', false === req_case( array( 'REQUEST_METHOD' => 'POST' ) + $base ) );
check( 'query string not cacheable', false === req_case( array( 'REQUEST_URI' => '/all-domains/?ext=ca' ) + $base ) );
$t = req_case( array( 'REQUEST_URI' => '/about-us/?utm_source=x&gclid=1' ) + $base );
check( 'tracking parameters share the plain page key', is_array( $t ) && $t['file'] === $a['file'] );
check( 'signed-in cookie not cacheable', false === req_case( $base, array( 'wordpress_logged_in_abc' => '1' ) ) );
check( 'account cookie not cacheable', false === req_case( $base, array( 'db_wl' => 'tok' ) ) );
check( 'checkout path not cacheable', false === req_case( array( 'REQUEST_URI' => '/checkout/?d=x' ) + $base ) && false === req_case( array( 'REQUEST_URI' => '/checkout/' ) + $base ) );
check( 'wp-admin not cacheable', false === req_case( array( 'REQUEST_URI' => '/wp-admin/' ) + $base ) );
check( 'feed not cacheable', false === req_case( array( 'REQUEST_URI' => '/news/feed/' ) + $base ) );
check( 'sitemap xml not cacheable', false === req_case( array( 'REQUEST_URI' => '/sitemap_index.xml' ) + $base ) );
$auth1 = req_case( array( 'HTTP_AUTHORIZATION' => 'Basic dXNlcjpwYXNz' ) + $base );
$auth2 = req_case( array( 'HTTP_AUTHORIZATION' => 'Basic d3Jvbmc6d3Jvbmc=' ) + $base );
check( 'different credentials never share a saved page', $auth1['file'] !== $auth2['file'] && $auth1['file'] !== $a['file'] );
check( 'PHP_AUTH_USER form maps to the same key as the header', req_case( array( 'PHP_AUTH_USER' => 'user', 'PHP_AUTH_PW' => 'pass' ) + $base )['file'] === $auth1['file'] );
$d1 = req_case( array( 'REQUEST_URI' => '/domains/fatality-ca/' ) + $base, array( 'db_exp_cta' => 'B' ) );
check( 'domain page keyed by experiment variant', 'B' === $d1['variant'] && false === $d1['assign'] && false !== strpos( $d1['file'], '/B-none.php' ) );
$d2 = req_case( array( 'REQUEST_URI' => '/domains/fatality-ca/' ) + $base );
check( 'first visit to a domain page is assigned a variant', in_array( $d2['variant'], array( 'A', 'B' ), true ) && true === $d2['assign'] );
check( 'path traversal not cacheable', false === req_case( array( 'REQUEST_URI' => '/a/../wp-config.php' ) + $base ) );
check( 'bad host not cacheable', false === req_case( array( 'HTTP_HOST' => 'bad host"' ) + $base ) );

echo "\n$pass/" . ( $pass + $fail ) . " passed\n";
exit( $fail ? 1 : 0 );
