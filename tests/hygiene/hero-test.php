<?php
/** Offline tests for db-hero-showcase-block.php. Run: php tests/hygiene/hero-test.php */

define( 'ABSPATH', '/' );
function add_filter() {}
require __DIR__ . '/../../db-custom-blocks/db-hero-showcase-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }
function row( $n, $p ) { return array( 'n' => $n, 'p' => $p, 'u' => 'https://example.test/domains/' . str_replace( '.', '-', $n ) . '/' ); }

$rows = array(
	row( 'longerbrandname.com', 4000 ), row( 'fluxo.com', 8500 ), row( 'zeta.com', 5200 ), row( 'beta.com', 9000 ),
	row( 'curbside.ca', 12000 ), row( 'skiclub.ca', 10000 ), row( 'wow.ky', 1500 ), row( 'cheap.io', 100 ), row( 'huge.co', 90000 ), row( 'bad name.com', 5000 ),
);
$p = db_hh_pick( $rows, 3 );
check( 'one name per extension', 3 === count( $p ) && 3 === count( array_unique( array_column( $p, 'ext' ) ) ) );
check( 'shortest label wins in an extension; ties go to the price nearest $5,000', 'beta.com' !== $p[0]['n'] && 'zeta.com' === $p[0]['n'] );
check( 'extensions with more names come first', '.com' === $p[0]['ext'] && '.ca' === $p[1]['ext'] );
check( 'too cheap, too dear and malformed names are skipped', ! in_array( '.io', array_column( $p, 'ext' ), true ) && ! in_array( '.co', array_column( $p, 'ext' ), true ) && ! in_array( 'bad name.com', array_column( $p, 'n' ), true ) );
check( 'rows without a URL are skipped', array() === db_hh_pick( array( array( 'n' => 'a.com', 'p' => 1000, 'u' => '' ) ), 3 ) );
check( 'output is stable across input order', array_column( db_hh_pick( array_reverse( $rows ), 3 ), 'n' ) === array_column( $p, 'n' ) );
check( 'n of zero returns nothing', array() === db_hh_pick( $rows, 0 ) );

$h = db_hh_html( $p, function ( $price ) { return '$' . number_format( round( $price / 12 ) ); } );
check( 'markup: region, three cards, price and monthly figure', false !== strpos( $h, 'role="region"' ) && 3 === substr_count( $h, 'class="db-hh-card"' ) && false !== strpos( $h, '$5,200' ) && false !== strpos( $h, 'or from $433/mo' ) );
check( 'markup: name and extension are split for styling', false !== strpos( $h, '<span class="db-hh-name">zeta<em>.com</em></span>' ) );
check( 'markup: hidden when fewer than two names', '' === db_hh_html( array( $p[0] ) ) && '' === db_hh_html( array() ) );
check( 'markup: no monthly line without a monthly function', false === strpos( db_hh_html( $p ), 'or from' ) );
check( 'markup: motion is off under reduced motion', false !== strpos( $h, 'prefers-reduced-motion:reduce' ) && false !== strpos( $h, 'background-size:100% 100%' ) );
check( 'markup: names are escaped', false === strpos( db_hh_html( array( array( 'n' => 'x.com', 'label' => '<i>', 'ext' => '.com', 'p' => 1000.0, 'u' => 'https://e.test/"' ), array( 'n' => 'y.com', 'label' => 'y', 'ext' => '.ca', 'p' => 1000.0, 'u' => 'https://e.test/' ) ) ), '<i>' ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
