<?php
/**
 * Offline tests for the bundled UI fixes (html filters). Run: php tests/designer/ui-fixes-test.php
 */
define( 'DBUX_TESTING', 1 );
require __DIR__ . '/../../db-custom-blocks/designer/ui-fixes/includes/html-filters.php';
$pass = 0; $fail = 0;
function check( $n, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n\n"; } }

$h = '<html><body><input type="search" id="search_id_mobile" placeholder="Search"><input id="search_id" type="search"></body></html>';
$o = dbux_filter_html( $h, array() );
check( 'both header search inputs are labelled', 2 === substr_count( $o, 'aria-label="Search domains for sale"' ) );
check( 'labelling is idempotent', 2 === substr_count( dbux_filter_html( $o, array() ), 'aria-label="Search domains for sale"' ) );
check( 'existing aria-label is kept', 1 === substr_count( dbux_filter_html( '<input aria-label="Mine" id="search_id">', array() ), 'aria-label=' ) );

$c = '<div class="co-sum-price"><svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="18"/><text x="14" y="26">$</text></svg><span>$2,500.00</span></div>';
$co = dbux_filter_html( $c, array() );
check( 'checkout seal glyph becomes a check mark', false === strpos( $co, '<text' ) && false !== strpos( $co, '<path d="M14.5 20.5' ) );
check( 'checkout amount text is untouched', false !== strpos( $co, '<span>$2,500.00</span>' ) );
check( 'pages without the price row are unchanged', 'x' === dbux_checkout_price_icon( 'x' ) );

$a = '<html><header class="page-header"><h2 class="page-title">Names — 206 Domains</h2></header></html>';
check( 'archive title becomes the H1', false !== strpos( dbux_filter_html( $a, array( 'domain_archive' => true ) ), '<h1 class="page-title">Names — 206 Domains</h1>' ) );
check( 'never a second H1', 0 === strpos( dbux_archive_heading_h1( '<h1>Own</h1><header class="page-header"><h2 class="page-title">T</h2></header>' ), '<h1>Own</h1>' ) && false !== strpos( dbux_archive_heading_h1( '<h1>Own</h1><header class="page-header"><h2 class="page-title">T</h2></header>' ), '<h2 class="page-title">' ) );
check( 'archive rule off elsewhere', false !== strpos( dbux_filter_html( $a, array() ), '<h2 class="page-title">' ) );

$d = '<p><strong>206 names available.</strong></p>';
check( 'hero count becomes a placeholder for the script', false !== strpos( dbux_filter_html( $d, array( 'all_domains' => true ) ), 'data-dbux-available' ) && false === strpos( dbux_filter_html( $d, array( 'all_domains' => true ) ), '206' ) );
check( 'hero count untouched off the listing page', false !== strpos( dbux_filter_html( $d, array() ), '206 names available.' ) );
echo "\n$pass/" . ( $pass + $fail ) . " passed\n"; exit( $fail ? 1 : 0 );
