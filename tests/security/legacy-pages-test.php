<?php
/**
 * Offline test: the legacy DomainFolio payment pages never run (bug register batch 2,
 * SC-1 to SC-3), and the page cache never stores or warms them.
 *
 *     php tests/security/legacy-pages-test.php
 */
define( 'ABSPATH', __DIR__ );
function add_action() {}
function add_filter() {}
require __DIR__ . '/../../db-custom-blocks/db-safety-block.php';
define( 'DB_PC_DIR', '/tmp' );
require __DIR__ . '/../../db-custom-blocks/pagecache/serve.php';

$pass = 0; $fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

$g = db_safety_template_guards();
foreach ( array( 'plugnpay', 'paypal-ipn', 'payment-cancel', 'installment-detail-buyer', 'installment-detail-seller' ) as $slug ) {
	check( "$slug answers 410 before its template runs", isset( $g[ $slug ] ) && null === $g[ $slug ]['require'] && 410 === $g[ $slug ]['code'] );
}
check( 'check-domain 301s to the extension checker before its template runs (SE-6)', null === $g['check-domain']['require'] && 301 === $g['check-domain']['code'] && '/domain-tools/domain-extension-checker/' === $g['check-domain']['to'] );
check( 'payment-successful always redirects, whatever the query string', null === $g['payment-successful']['require'] && '/thank-you/' === $g['payment-successful']['to'] );
check( 'the admin instalment page still needs manage_options', 'manage_options' === $g['installment-detail-admin']['cap'] );
check( 'the transfer form page is not blocked', ! isset( $g['domain-transfer'] ) );
foreach ( array( '/payment-successful/', '/plugnpay/', '/paypal-ipn/', '/check-domain/', '/installment-detail-buyer/', '/buy-now/' ) as $path ) {
	check( "page cache skips $path", db_pc_skip_path( $path ) );
}
check( 'page cache still stores normal pages', ! db_pc_skip_path( '/about-us/' ) && ! db_pc_skip_path( '/domain-transfer/' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
