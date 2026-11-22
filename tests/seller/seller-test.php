<?php
/**
 * Offline tests for the seller marketplace rules. Run: php tests/seller/seller-test.php
 */
define( 'DB_SELLER_TESTING', 1 );
require __DIR__ . '/../../db-custom-blocks/db-seller-lib.php';
$pass = 0; $fail = 0;
function check( $n, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n\n"; } }

// Names.
check( 'URL pasted becomes the bare name', array( 'example.com', '' ) === db_seller_normalise_name( 'https://www.Example.COM/path?x=1' ) );
check( 'port and trailing dot are removed', array( 'example.ca', '' ) === db_seller_normalise_name( 'example.ca:8080' ) && array( 'example.ca', '' ) === db_seller_normalise_name( 'example.ca.' ) );
check( 'punycode is accepted', '' === db_seller_normalise_name( 'xn--bcher-kva.de' )[1] );
check( 'Unicode gets a clear reason', false !== strpos( db_seller_normalise_name( 'bücher.de' )[1], 'punycode' ) );
check( 'bad syntax is refused', '' !== db_seller_normalise_name( '-bad-.com' )[1] && '' !== db_seller_normalise_name( 'nodot' )[1] && '' !== db_seller_normalise_name( 'a_b.com' )[1] );
$p = db_seller_parse_names( "alpha.com, beta.ca\nALPHA.com;  gamma.io  not_a_name\n\nhttps://delta.net/x" );
check( 'paste splits on commas, spaces, semicolons and lines', array( 'alpha.com', 'beta.ca', 'gamma.io', 'delta.net' ) === $p['ok'] );
check( 'duplicates are reported once', array( 'alpha.com' ) === $p['dupes'] );
check( 'invalid entries are reported with a reason', 1 === count( $p['bad'] ) && 'not_a_name' === $p['bad'][0][0] );
$many = db_seller_parse_names( implode( ' ', array_map( function ( $i ) { return "n{$i}.com"; }, range( 1, 60 ) ) ) );
check( 'paste is capped at 50 names', 50 === count( $many['ok'] ) && 10 === $many['over'] );

// Amounts and rows.
check( 'amounts accept $ and commas', 12500 === db_seller_clean_amount( '$12,500' ) && 12500 === db_seller_clean_amount( '12500.00' ) && 0 === db_seller_clean_amount( 'abc' ) && 0 === db_seller_clean_amount( '-5' ) );
list( $r, $e ) = db_seller_validate_row( array( 'option' => 'buy_now', 'price' => '5000', 'min_offer' => '3000' ) );
check( 'valid Buy Now row', ! $e && 5000 === $r['price'] && 3000 === $r['min_offer'] );
list( , $e ) = db_seller_validate_row( array( 'option' => 'buy_now', 'price' => '50' ) );
check( 'Buy Now under $100 is refused', 1 === count( $e ) );
list( , $e ) = db_seller_validate_row( array( 'option' => 'buy_now', 'price' => '5000', 'min_offer' => '5000' ) );
check( 'minimum offer must be below the price', 1 === count( $e ) );
list( $r, $e ) = db_seller_validate_row( array( 'option' => 'make_offer', 'price' => '', 'min_offer' => '2000' ) );
check( 'Make Offer needs no price', ! $e && 'make_offer' === $r['option'] && 0 === $r['price'] );
list( $r, ) = db_seller_validate_row( array( 'option' => 'something' ) );
check( 'unknown option falls back to Buy Now', 'buy_now' === $r['option'] );
list( , $e ) = db_seller_validate_row( array( 'option' => 'buy_now', 'price' => '20000000' ) );
check( 'prices over $10M go to a broker', 1 === count( $e ) );

// Commission (owner decision 1).
check( '15 percent commission', 1500 === db_seller_commission( 10000 ) );
check( '$100 minimum commission', 100 === db_seller_commission( 400 ) );
check( 'commission never exceeds the price', 50 === db_seller_commission( 50 ) );

// Verification.
$t = 'a1b2c3d4e5f6a1b2c3d4e5f6';
check( 'TXT value', 'domainbrothers-verify=' . $t === db_seller_txt_value( $t ) );
check( 'CNAME host', 'db-' . $t . '.example.com' === db_seller_cname_host( $t, 'Example.com' ) );
check( 'TXT found among other records', db_seller_txt_matches( array( array( 'txt' => 'v=spf1 -all' ), array( 'txt' => 'domainbrothers-verify=' . $t ) ), $t ) );
check( 'TXT split into strings is joined', db_seller_txt_matches( array( array( 'txt' => 'x', 'entries' => array( 'domainbrothers-verify=', $t ) ) ), $t ) );
check( 'another token does not match', ! db_seller_txt_matches( array( array( 'txt' => 'domainbrothers-verify=ffff' ) ), $t ) );
check( 'empty token never matches', ! db_seller_txt_matches( array( array( 'txt' => 'domainbrothers-verify=' ) ), '' ) );
check( 'CNAME matches with a trailing dot', db_seller_cname_matches( array( array( 'target' => 'Verify.DomainBrothers.com.' ) ) ) );
check( 'CNAME elsewhere does not match', ! db_seller_cname_matches( array( array( 'target' => 'evil.example' ) ) ) );

// Readiness and status.
check( 'ready needs price and verification', db_seller_ready( array( 'option' => 'buy_now', 'price' => 900, 'verify_status' => 'verified' ) ) && ! db_seller_ready( array( 'option' => 'buy_now', 'price' => 900, 'verify_status' => 'pending' ) ) && ! db_seller_ready( array( 'option' => 'buy_now', 'price' => 0, 'verify_status' => 'verified' ) ) );
check( 'status labels', 'Live' === db_seller_status( array( 'review_status' => 'approved' ) )[0] && 'In review' === db_seller_status( array( 'review_status' => 'pending' ) )[0] && 'Ownership not found' === db_seller_status( array( 'review_status' => 'draft', 'verify_status' => 'failed' ) )[0] );

// Listing meta.
$m = db_seller_listing_meta( array( 'id' => 7, 'domain' => 'Brightpath.ca', 'option' => 'buy_now', 'price' => 4500, 'min_offer' => 3000 ), 3, '2026-11-22' );
check( 'Buy Now meta matches the site shape', '4500' === $m['domain_price'] && 'offer_buy_now' === $m['domain_type'] && 'sale' === $m['domain_status'] && 'Brightpath' === $m['domain_keyword'] && 'https://brightpath.ca' === $m['domain_url'] );
check( 'minimum offer is private meta', '3000' === $m['_db_min_offer'] && ! isset( $m['db_min_offer'] ) );
$m = db_seller_listing_meta( array( 'id' => 8, 'domain' => 'x.io', 'option' => 'make_offer', 'price' => 9000, 'min_offer' => 0 ), 3, '2026-11-22' );
check( 'Make Offer meta stores price 0 like other offer-only listings (never empty)', '0' === $m['domain_price'] && 'offer' === $m['domain_type'] );

// Profile.
list( $c, $e ) = db_seller_clean_profile( array( 'legal_name' => '  Pat   <b>Lee</b> ', 'country' => 'ca', 'phone' => '+1 (416) 555-0100 ext', 'agree' => '1' ) );
check( 'profile is cleaned', ! $e && 'Pat Lee' === $c['legal_name'] && 'CA' === $c['country'] && '+1 (416) 555-0100' === trim( $c['phone'] ) );
list( , $e ) = db_seller_clean_profile( array( 'legal_name' => 'Pat Lee', 'country' => 'GB', 'agree' => '1' ) );
check( 'countries outside US and Canada are refused', 1 === count( $e ) );
list( , $e ) = db_seller_clean_profile( array( 'legal_name' => 'P', 'country' => 'US' ) );
check( 'name and agreement are required', 2 === count( $e ) );

// Phase 2: bulk import.
$csv = "\xEF\xBB\xBFDomain Name,Selling Option,For Sale,Price,Minimum Price,Currency,Action Type\r\nalpha.com,BUY_NOW,yes,2500,2000,USD,\r\nbeta.ca,MAKE_OFFER,,,500,,\r\nold.com,,,,,,DELETE\r\nbad_name,BUY_NOW,yes,900,,USD,\r\neuro.com,BUY_NOW,yes,900,,EUR,\r\n\r\n";
$tb = db_seller_csv_table( $csv );
check( 'CSV with BOM and blank lines is read', 6 === count( $tb ) && 'Domain Name' === $tb[0][0] );
check( 'semicolon CSV is read', 'x.com' === db_seller_csv_table( "Domain Name;Price\nx.com;100" )[1][0] );
$sr = db_seller_sheet_rows( $tb );
check( 'rows in the Sedo layout', 3 === count( $sr['rows'] ) && 'buy_now' === $sr['rows'][0]['option'] && 'make_offer' === $sr['rows'][1]['option'] && $sr['rows'][2]['delete'] );
check( 'bad names and non-USD rows are reported with the row number', 2 === count( $sr['errors'] ) && false !== strpos( implode( ' ', $sr['errors'] ), 'Row 5' ) && false !== strpos( implode( ' ', $sr['errors'] ), 'EUR' ) );
check( 'a file without the Domain Name column is refused', 1 === count( db_seller_sheet_rows( array( array( 'Name', 'Price' ), array( 'a.com', '1' ) ) )['errors'] ) );
check( 'For Sale "no" means remove', ! db_seller_sheet_rows( array( array( 'Domain Name', 'For Sale' ), array( 'a.com', 'no' ) ) )['rows'][0]['for_sale'] );
$plan = db_seller_plan_import( $sr['rows'], array( 'old.com' => 'approved', 'alpha.com' => 'draft', 'kept.com' => 'approved', 'busy.com' => 'pending' ), 'import' );
check( 'import adds, updates and removes', 1 === count( $plan['add'] ) && 'beta.ca' === $plan['add'][0]['domain'] && 'alpha.com' === $plan['update'][0]['domain'] && array( 'old.com' ) === $plan['remove'] );
check( 'import leaves names missing from the file alone', ! in_array( 'kept.com', $plan['remove'], true ) );
$plan = db_seller_plan_import( $sr['rows'], array( 'old.com' => 'approved', 'kept.com' => 'approved' ), 'sync' );
check( 'sync also removes names missing from the file', in_array( 'kept.com', $plan['remove'], true ) && in_array( 'old.com', $plan['remove'], true ) );
$plan = db_seller_plan_import( array( array( 'domain' => 'busy.com', 'option' => 'buy_now', 'price' => '900', 'min_offer' => '', 'for_sale' => true, 'delete' => false ), array( 'domain' => 'busy.com', 'option' => 'buy_now', 'price' => '900', 'min_offer' => '', 'for_sale' => true, 'delete' => false ), array( 'domain' => 'cheap.com', 'option' => 'buy_now', 'price' => '10', 'min_offer' => '', 'for_sale' => true, 'delete' => false ) ), array( 'busy.com' => 'pending' ) );
check( 'names in review, duplicates and invalid prices are skipped with reasons', 3 === count( $plan['skip'] ) && ! $plan['add'] && ! $plan['update'] );
check( 'template has the seven columns', 0 === strpos( db_seller_template_csv(), 'Domain Name,Selling Option,For Sale,Price,Minimum Price,Currency,Action Type' ) && 4 === count( db_seller_sheet_rows( db_seller_csv_table( db_seller_template_csv() ) )['rows'] ) + 1 );
check( 'price adjustment by percent', 1100 === db_seller_adjust_price( 1000, 10 ) && 900 === db_seller_adjust_price( 1000, -10 ) && 100 === db_seller_adjust_price( 150, -90 ) && 6000 === db_seller_adjust_price( 1000, 9999 ) );
if ( class_exists( 'ZipArchive' ) ) {
	$f = tempnam( sys_get_temp_dir(), 'x' ) . '.xlsx';
	$z = new ZipArchive(); $z->open( $f, ZipArchive::CREATE );
	$z->addFromString( 'xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Domain Name</t></si><si><t>Price</t></si><si><t>gamma.io</t></si></sst>' );
	$z->addFromString( 'xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>4200</v></c></row></sheetData></worksheet>' );
	$z->close();
	$x = db_seller_xlsx_table( $f ); unlink( $f );
	check( 'XLSX first sheet is read (shared strings and numbers)', is_array( $x ) && 'gamma.io' === $x[1][0] && '4200' === $x[1][1] );
} else { echo "SKIP xlsx (no ZipArchive)\n"; }

echo "\n$pass/" . ( $pass + $fail ) . " passed\n"; exit( $fail ? 1 : 0 );
