<?php
/**
 * Offline tests for db-crawl-block.php (canonical URLs, crawl traps, robots.txt).
 *
 *     php tests/seo/crawl-test.php
 */
define( 'ABSPATH', __DIR__ );
function add_action() {}
function add_filter() {}
require __DIR__ . '/../../db-custom-blocks/db-crawl-block.php';

$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$c = 'db_crawl_clean_url';
check( 'tracking parameters are removed', 'https://example.test/about-us/' === $c( 'https://example.test/about-us/?utm_source=x&utm_medium=y&gclid=1&fbclid=2' ) );
check( 'filter and search parameters are removed', 'https://example.test/all-domains/' === $c( 'https://example.test/all-domains/?tld=.ca&sort=pa&max=2500' ) );
check( 'other parameters stay, in a stable order', 'https://example.test/buy-now/?d=a&p=b' === $c( 'https://example.test/buy-now/?p=b&d=a' ) );
check( 'double slashes collapse and /page/1/ goes', 'https://example.test/news/' === $c( 'https://EXAMPLE.test//news/page/1/' ) );
check( 'real pages of an archive keep their number', 'https://example.test/news/page/2/' === $c( 'https://example.test/news/page/2/?utm_campaign=z' ) );

check( 'the canonical has no query string at all', 'https://example.test/about-us/' === db_crawl_canonical_url( 'https://example.test/about-us/?x=1&utm_source=y' ) && 'https://example.test/news/page/2/' === db_crawl_canonical_url( 'https://example.test/news/page/2/?qa=1' ) );

$n = 'db_crawl_normal_path';
check( 'uppercase path is normalised', '/about-us/' === $n( '/About-Us/' ) );
check( 'double slashes are normalised', '/news/' === $n( '//news/' ) );
check( 'a normal path is left alone', '' === $n( '/about-us/' ) && '' === $n( '/' ) );
check( 'WordPress internals and files are never touched', '' === $n( '/wp-admin/Options.php' ) && '' === $n( '/wp-json/DB/v1' ) && '' === $n( '/Logo.PNG' ) );
check( 'encoded paths are never touched', '' === $n( '/caf%C3%A9/' ) );

check( 'search and filters are noindex', db_crawl_noindex_query( array( 's' ) ) && db_crawl_noindex_query( array( 'tld', 'x' ) ) && db_crawl_noindex_query( array( 'SORT' ) ) );
check( 'tracking alone keeps the page indexable', ! db_crawl_noindex_query( array( 'utm_source', 'gclid' ) ) && ! db_crawl_noindex_query( array() ) );

$r = db_crawl_robots_txt( 'https://example.test/sitemap_index.xml' );
check( 'robots: sitemap listed', false !== strpos( $r, "Sitemap: https://example.test/sitemap_index.xml\n" ) );
check( 'robots: private and checkout routes disallowed', false !== strpos( $r, 'Disallow: /buy-now/' ) && false !== strpos( $r, 'Disallow: /my-account/' ) && false !== strpos( $r, 'Allow: /wp-admin/admin-ajax.php' ) );
check( 'robots: faceted URLs disallowed', false !== strpos( $r, 'Disallow: /*?*sort=' ) && false !== strpos( $r, 'Disallow: /*?s=' ) );
check( 'robots: public listings are never disallowed', false === strpos( $r, "Disallow: /all-domains/\n" ) && false === strpos( $r, "Disallow: /domains/\n" ) && false === strpos( $r, "Disallow: /\n\nUser-agent: Googlebot" ) );
check( 'robots: abusive scrapers blocked', false !== strpos( $r, "User-agent: Bytespider\nDisallow: /" ) );
check( 'robots: AI answer crawlers are not blocked by default', false === strpos( $r, 'GPTBot' ) && false === strpos( $r, 'ChatGPT-User' ) && false === strpos( $r, 'PerplexityBot' ) );
$rt = db_crawl_robots_txt( 'https://example.test/s.xml', true );
check( 'robots: training crawlers blocked only when chosen, answer crawlers still allowed', false !== strpos( $rt, "User-agent: GPTBot\nDisallow: /" ) && false === strpos( $rt, 'OAI-SearchBot' ) && false === strpos( $rt, 'ChatGPT-User' ) );

$page = "<html><head><title>x</title></head><body></body></html>";
check( 'canonical added when missing', false !== strpos( db_crawl_insert_canonical( $page, 'https://example.test/a/' ), '<link rel="canonical" href="https://example.test/a/">' ) );
$has = "<html><head><link rel='canonical' href='https://example.test/b/'></head><body></body></html>";
check( 'an existing canonical is kept', $has === db_crawl_insert_canonical( $has, 'https://example.test/a/' ) );
check( 'a canonical-looking link in the body does not count', false !== strpos( db_crawl_insert_canonical( '<html><head></head><body><link rel="canonical" href="x"></body></html>', 'https://e.test/' ), '<head><link rel="canonical" href="https://e.test/">' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
