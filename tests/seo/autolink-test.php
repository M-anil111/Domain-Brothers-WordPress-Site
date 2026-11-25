<?php
/**
 * Offline test for the contextual internal linker (db-custom-blocks/db-autolink-block.php).
 *
 *     php tests/seo/autolink-test.php
 */
define( 'ABSPATH', __DIR__ );
function add_filter() {}
function add_action() {}
function apply_filters( $h, $v ) { return $v; }
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-autolink-block.php';

$pass = 0; $fail = 0;
function check( $name, $ok ) {
	global $pass, $fail;
	if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; }
}
$map = array(
	array( 'domain (?:name )?brok(?:er|ers|erage)(?: services?)?', '/domain-brokerage-services/' ),
	array( 'EPP codes?', '/epp-guidelines/' ),
	array( 'payment plans?', '/payment-terms/' ),
	array( '(?:4|four)[- ](?:letter|character) domains?', '/4-character-domains/' ),
	array( 'escrow', '/domain-escrow-explained/' ),
	array( 'gTLDs?', '/domain-glossary/#t-gtld' ),
);
$count = function ( $h ) { return preg_match_all( '/class="db-autolink"/', $h ); };

$h = db_autolink_apply( '<p>Hire a domain broker today. A domain broker again.</p>', $map );
check( 'first mention linked once', 1 === $count( $h ) && false !== strpos( $h, '<a href="/domain-brokerage-services/" class="db-autolink">domain broker</a> today' ) );

$h = db_autolink_apply( '<p>Our Domain Brokerage Services team.</p>', $map );
check( 'case kept, longest phrase', false !== strpos( $h, '>Domain Brokerage Services</a> team' ) );

$h = db_autolink_apply( '<h2>Domain broker fees</h2><p>Text.</p>', $map );
check( 'never in a heading', 0 === $count( $h ) );

$h = db_autolink_apply( '<p><a href="/x/">ask a domain broker</a> now</p>', $map );
check( 'never inside an existing link', 0 === $count( $h ) );

$h = db_autolink_apply( '<p>See <a href="https://example.test/payment-terms/">terms</a>. Payment plans start at 3 months.</p>', $map );
check( 'target already linked is skipped', 0 === $count( $h ) );

$h = db_autolink_apply( '<p>A domain broker helps.</p>', $map, '/domain-brokerage-services/' );
check( 'no link to the page itself', 0 === $count( $h ) );

$h = db_autolink_apply( '<div class="db-cta"><p>Talk to a domain broker</p><div><p>x</p></div></div><p>Get your EPP code.</p>', $map );
check( 'plugin box skipped, text after it linked', 1 === $count( $h ) && false !== strpos( $h, '>EPP code</a>' ) && false === strpos( $h, '>domain broker</a>' ) );

$h = db_autolink_apply( '<p>escrows and nonescrow and escrow.</p>', $map );
check( 'whole words only', false !== strpos( $h, 'and <a href="/domain-escrow-explained/" class="db-autolink">escrow</a>.' ) );

$h = db_autolink_apply( '<p><img src="/a/domain-broker.png" alt="domain broker"> plain text</p>', $map );
check( 'attributes are not text', 0 === $count( $h ) );

$h = db_autolink_apply( '<p>domain broker</p><p>EPP code</p><p>payment plan</p><p>4-letter domains</p><p>escrow</p><p>gTLDs</p>', $map, '', 5 );
check( 'at most max links', 5 === $count( $h ) );

$h = db_autolink_apply( '<p>A domain broker can find an EPP code fast.</p>', $map );
check( 'one link per text run (spaced out)', 1 === $count( $h ) );

$h = db_autolink_apply( '<p>New gTLDs launched.</p>', $map, '', 5, function ( $p ) { return 'https://site.test' . $p; } );
check( 'fragment targets and url callback', false !== strpos( $h, 'href="https://site.test/domain-glossary/#t-gtld"' ) );

$h = db_autolink_apply( '<p>Use /domain-broker/ path and domain-broker-tools.</p>', $map );
check( 'not inside paths or hyphenated words', 0 === $count( $h ) );

$h = db_autolink_apply( '<pre>domain broker</pre><code>EPP code</code><button>payment plan</button><p>ok</p>', $map );
check( 'code, pre and buttons skipped', 0 === $count( $h ) );

$src = '<p>Text with <!-- domain broker --> a comment.</p>';
check( 'comments untouched', $src === db_autolink_apply( $src, $map ) );

$h = db_autolink_apply( '<ul class="db-hub"><li><p>domain broker</p></li></ul><p>A <strong>domain broker</strong> here.</p>', $map );
check( 'inline tags allowed, hub skipped', false !== strpos( $h, '<strong><a href="/domain-brokerage-services/" class="db-autolink">domain broker</a></strong>' ) );

$gmap = array( array( 'ccTLDs?', '/domain-glossary/#t-cctld' ), array( 'gTLDs?', '/domain-glossary/#t-gtld' ) );
$h = db_autolink_apply( '<p>New gTLDs launched.</p><p>Many ccTLDs too.</p>', $gmap );
check( 'later rule for a shared page still tried, one link per page', 1 === $count( $h ) && false !== strpos( $h, '#t-gtld' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
