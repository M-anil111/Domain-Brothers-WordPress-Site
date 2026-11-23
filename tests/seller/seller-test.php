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
check( 'a file without the Domain Name column is refused', 1 === count( db_seller_sheet_rows( array( array( 'Notes', 'Price' ), array( 'a.com', '1' ) ) )['errors'] ) );
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

// Phase 2: checks, guidance, exclusive, details.
check( 'ordinary names pass the blocked check', '' === db_seller_blocked( 'brightpath.ca' ) );
check( 'famous brands need a broker', '' !== db_seller_blocked( 'cheap-google-ads.com' ) && '' !== db_seller_blocked( 'paypalhelp.ca' ) );
check( 'abusive terms are refused', 'This name cannot be listed.' === db_seller_blocked( 'pre-teen-pics.com' ) );
check( 'slurs are refused', 'This name cannot be listed.' === db_seller_blocked( 'my-faggot-site.com' ) );
check( 'profanity goes to broker review', false !== strpos( db_seller_blocked( 'fuckyou.ca' ), 'review' ) && false !== strpos( db_seller_blocked( 'free-porn.net' ), 'review' ) );
check( 'innocent names containing short words pass', '' === db_seller_blocked( 'spicekitchen.ca' ) && '' === db_seller_blocked( 'essexhomes.com' ) && '' === db_seller_blocked( 'peacockgrapes.com' ) && '' === db_seller_blocked( 'dickensbooks.ca' ) );
check( 'subdomains are refused', false !== strpos( db_seller_normalise_name( 'shop.example.com' )[1], 'main name' ) && '' !== db_seller_normalise_name( 'a.b.c.d.qa.ca' )[1] );
check( 'two-part endings are accepted', array( 'brand.co.uk', '' ) === db_seller_normalise_name( 'www.brand.co.uk' ) && '' === db_seller_normalise_name( 'shop.com.au' )[1] );
$al = db_seller_sheet_rows( array( array( 'Domain', 'Type', 'Asking Price', 'Min Offer' ), array( 'alias.ca', 'make offer', '2500', '900' ) ) );
check( 'spreadsheet heading aliases are understood', 1 === count( $al['rows'] ) && 'alias.ca' === $al['rows'][0]['domain'] && 'make_offer' === $al['rows'][0]['option'] && '2500' === $al['rows'][0]['price'] && '900' === $al['rows'][0]['min_offer'] );
check( 'SL-14: ordinary words containing a listed term pass', '' === db_seller_blocked( 'torpedo.com' ) && '' === db_seller_blocked( 'pedometer.com' ) && '' === db_seller_blocked( 'pineapple.com' ) && '' === db_seller_blocked( 'advisable.com' ) && '' === db_seller_blocked( 'exuberant.com' ) && '' === db_seller_blocked( 'tuberose.com' ) && '' === db_seller_blocked( 'shiitake.com' ) );
check( 'SL-14: short ambiguous terms go to review, not refusal', false !== strpos( db_seller_blocked( 'pedoshop.com' ), 'review' ) && false !== strpos( db_seller_blocked( 'kkkcards.com' ), 'review' ) );
check( 'SL-14: the brand itself still needs a broker', false !== strpos( db_seller_blocked( 'apple-repair.com' ), 'review' ) && false !== strpos( db_seller_blocked( 'uberclone.com' ), 'review' ) );
check( 'plain words pass', '' === db_seller_blocked( 'bakery.ca' ) && '' === db_seller_blocked( 'torontohomes.com' ) );
check( 'exclusive commission is 10 percent', 1000 === db_seller_fee( 10000, true ) && 1500 === db_seller_fee( 10000 ) && 100 === db_seller_fee( 500, true ) );
$g = db_seller_price_guide( array( array( 'name' => 'a.ca', 'price' => 1000 ), array( 'name' => 'b.ca', 'price' => 3000 ), array( 'name' => 'c.ca', 'price' => 2000 ), array( 'name' => 'd.com', 'price' => 9000 ), array( 'name' => 'e.ca', 'price' => 0 ) ), '.ca' );
check( 'price guide: median, range and count for one extension', array( 'count' => 3, 'median' => 2000, 'low' => 1000, 'high' => 3000 ) === $g );
check( 'price guide needs 3 sales', null === db_seller_price_guide( array( array( 'name' => 'a.io', 'price' => 1 ) ), '.io' ) );
$d = db_seller_clean_details( array( 'description' => "<b>Great</b>   name\r\n\r\n\r\n\r\nfor a bakery", 'keywords' => 'bakery, Bread; bakery, <x>cakes</x>, k1,k2,k3,k4,k5,k6,k7' ) );
check( 'details: tags stripped, blank lines collapsed', "Great name\n\nfor a bakery" === $d['description'] );
check( 'details: tags stripped from keywords, duplicates dropped, capped at 8', 0 === strpos( $d['keywords'], 'bakery, Bread, cakes, k1' ) && 8 === count( explode( ', ', $d['keywords'] ) ) );

// Categories and registry check.
check( 'categories: allowed ids only, no repeats, max 3', '4,7,9' === db_seller_clean_categories( array( '4', '7', 'x', '99', '4', '9', '12' ), array( 4, 7, 9, 12 ) ) );
check( 'categories: comma list and empty input', '7' === db_seller_clean_categories( '7,,0,-3', array( 7 ) ) && '' === db_seller_clean_categories( array(), array( 1 ) ) );
check( 'rdap: 200 is registered', 'registered' === db_seller_rdap_verdict( 'brightpath.ky', 200 ) );
check( 'rdap: 404 on a reliable registry is unregistered', 'unregistered' === db_seller_rdap_verdict( 'nothere-zz.ca', 404 ) && 'unregistered' === db_seller_rdap_verdict( 'nothere-zz.com', 404 ) );
check( 'rdap: 404 elsewhere, timeouts and rate limits are unknown', 'unknown' === db_seller_rdap_verdict( 'cayman.ky', 404 ) && 'unknown' === db_seller_rdap_verdict( 'a.com', null ) && 'unknown' === db_seller_rdap_verdict( 'a.com', 429 ) );

// Phase 3: negotiations.
check( 'auto-counter: below minimum answers with the minimum', 2000.0 === db_seller_auto_counter( 1500, 2000 ) );
check( 'auto-counter: at or above the minimum does nothing', null === db_seller_auto_counter( 2000, 2000 ) && null === db_seller_auto_counter( 2500, 2000 ) && null === db_seller_auto_counter( 150, 0 ) );
check( 'SL-9: an offer under the lowest price is countered at it even without a private minimum', 100.0 === db_seller_auto_counter( 50, 0 ) );
check( 'SL-9: an offer under $100 cannot be accepted; $100 and up can', '' !== db_seller_offer_check( 'accept', '', 'open', 99.6 ) && '' === db_seller_offer_check( 'accept', '', 'open', 100 ) && '' === db_seller_offer_check( 'accept', '', 'open' ) );
check( 'sweep: remind after 3 days once, close after 14 days', 'remind' === db_seller_offer_due( 'open', 72, false ) && '' === db_seller_offer_due( 'open', 100, true ) && 'expire' === db_seller_offer_due( 'open', 336, true ) && '' === db_seller_offer_due( 'open', 10, false ) );
check( 'sweep: only offers waiting for the seller', '' === db_seller_offer_due( 'countered', 999, false ) && '' === db_seller_offer_due( 'accepted', 999, false ) );
check( 'seller move: accept only when open', '' === db_seller_offer_check( 'accept', '', 'open' ) && '' !== db_seller_offer_check( 'accept', '', 'countered' ) );
check( 'seller move: counter needs at least $100', '' !== db_seller_offer_check( 'counter', '50', 'open' ) && '' !== db_seller_offer_check( 'counter', 'abc', 'open' ) && '' === db_seller_offer_check( 'counter', '$4,500', 'open' ) );
check( 'seller move: no counter on paid or accepted, no decline when closed, unknown moves refused', '' !== db_seller_offer_check( 'counter', '500', 'paid' ) && '' !== db_seller_offer_check( 'decline', '', 'expired' ) && '' !== db_seller_offer_check( 'delete', '', 'open' ) );

// Phase 4: payouts.
check( 'payout split: 15% commission, 10% when exclusive', array( 'gross' => 10000.0, 'commission' => 1500.0, 'net' => 8500.0 ) === db_seller_payout_split( 10000 ) && array( 'gross' => 10000.0, 'commission' => 1000.0, 'net' => 9000.0 ) === db_seller_payout_split( 10000, true ) );
check( 'payout split: $100 minimum commission, never more than the sale', 400.0 === db_seller_payout_split( 500 )['net'] && 0.0 === db_seller_payout_split( 80 )['net'] );
$t = 1800000000;
check( 'due date: 7 days after confirmation', $t + 7 * 86400 === db_seller_payout_due_ts( 0, $t ) );
check( 'due date: 14 days after transfer without confirmation', $t + 14 * 86400 === db_seller_payout_due_ts( $t, 0 ) );
check( 'due date: the earlier of the two', $t + 9 * 86400 === db_seller_payout_due_ts( $t, $t + 2 * 86400 ) && 0 === db_seller_payout_due_ts( 0, 0 ) );
check( 'payout flow: plan done, transferred, confirmed', 'waiting_transfer' === db_seller_payout_move( 'collecting', 'plan_done' ) && 'waiting_confirm' === db_seller_payout_move( 'waiting_transfer', 'transferred' ) && 'due' === db_seller_payout_move( 'waiting_confirm', 'confirmed' ) );
check( 'payout flow: pay only when due date reached', 'paid' === db_seller_payout_move( 'due', 'pay', $t, $t - 1 ) && null === db_seller_payout_move( 'due', 'pay', $t, $t + 1 ) && null === db_seller_payout_move( 'waiting_confirm', 'pay', $t, $t - 1 ) );
check( 'payout flow: hold and release back to the same step', 'held' === db_seller_payout_move( 'due', 'hold' ) && 'due' === db_seller_payout_move( 'held', 'release', 0, 0, 'due' ) && null === db_seller_payout_move( 'paid', 'hold' ) && null === db_seller_payout_move( 'held', 'release', 0, 0, 'paid' ) );
check( 'payout flow: no skipping steps', null === db_seller_payout_move( 'waiting_transfer', 'confirmed' ) && null === db_seller_payout_move( 'collecting', 'transferred' ) );
$rows = db_seller_statement_rows( array( array( 'domain' => '=cmd.ca', 'created' => $t, 'gross' => 1000, 'commission' => 150, 'net' => 850, 'state' => 'paid', 'due_ts' => $t, 'paid_ts' => $t, 'reference' => '+ref1' ) ) );
check( 'statement: header, money with 2 decimals, status words', 'Domain' === $rows[0][0] && '850.00' === $rows[1][4] && 'Paid to you' === $rows[1][5] && 2 === count( $rows ) );
check( 'statement: formula-looking text neutralised', "'=cmd.ca" === $rows[1][0] && "'+ref1" === $rows[1][8] );

// Phase 5: pointed names.
check( 'pointed name: www, port, case and trailing dot removed', 'brightpath.ca' === db_seller_pointed_name( 'WWW.BrightPath.ca:443.', 'beta.domainbrothers.com' ) || 'brightpath.ca' === db_seller_pointed_name( 'WWW.BrightPath.ca.', 'beta.domainbrothers.com' ) );
check( 'pointed name: the site, its root and subdomains are not pointed names', '' === db_seller_pointed_name( 'beta.domainbrothers.com', 'beta.domainbrothers.com' ) && '' === db_seller_pointed_name( 'www.domainbrothers.com', 'beta.domainbrothers.com' ) && '' === db_seller_pointed_name( 'cdn.domainbrothers.com', 'www.domainbrothers.com' ) );
check( 'pointed name: IPs, preview hosts without a TLD and junk are ignored', '' === db_seller_pointed_name( '72.60.128.77', 'beta.domainbrothers.com' ) && '' === db_seller_pointed_name( 'localhost', 'x.com' ) && '' === db_seller_pointed_name( 'bad host', 'x.com' ) );
check( 'pointed name: a port on a normal host', 'bakery.ca' === db_seller_pointed_name( 'bakery.ca:8080', 'beta.domainbrothers.com' ) );

// One owner per name; 120-day re-check.
check( 'claim: other sellers\' open rows lose, own and removed rows untouched', array( 1, 3 ) === db_seller_claim_losers( array( array( 'id' => 1, 'seller_id' => 7, 'review_status' => 'approved' ), array( 'id' => 2, 'seller_id' => 9, 'review_status' => 'draft' ), array( 'id' => 3, 'seller_id' => 8, 'review_status' => 'draft' ), array( 'id' => 4, 'seller_id' => 7, 'review_status' => 'removed' ) ), 9 ) );
check( 'taken: own row, inventory and sales refuse; another account is a claim', 'Already in your list.' === db_seller_taken_reason( true, true, false, false ) && 'Already listed on Domain Brothers.' === db_seller_taken_reason( false, false, true, false ) && '' !== db_seller_taken_reason( false, true, false, true ) && 'claim' === db_seller_taken_reason( false, true, false, false ) && '' === db_seller_taken_reason( false, false, false, false ) );
$n = 1800000000; $d = 86400;
check( 'recheck: nothing due before 120 days', '' === db_seller_recheck_action( $n - 119 * $d, 0, false, $n ) );
check( 'recheck: due and passing renews', 'renew' === db_seller_recheck_action( $n - 121 * $d, 0, true, $n ) );
check( 'recheck: due and failing warns first, then waits 14 days, then takes down', 'warn' === db_seller_recheck_action( $n - 121 * $d, 0, false, $n ) && 'still_failing' === db_seller_recheck_action( $n - 125 * $d, $n - 5 * $d, false, $n ) && 'take_down' === db_seller_recheck_action( $n - 140 * $d, $n - 14 * $d, false, $n ) );
check( 'recheck: a failing name that is fixed renews', 'renew' === db_seller_recheck_action( $n - 125 * $d, $n - 3 * $d, true, $n ) );

// Guided flow.
$dr = function ( $o ) { return array_merge( array( 'review_status' => 'draft', 'verify_status' => 'pending', 'option' => 'buy_now', 'option_type' => 'buy_now', 'price' => 0, 'min_offer' => 0 ), $o ); };
check( 'flow: no names starts at add', 'add' === db_seller_next_step( array() ) );
check( 'flow: an import preview stays on add', 'add' === db_seller_next_step( array( $dr( array() ) ), true ) );
check( 'flow: unpriced draft goes to prices', 'prices' === db_seller_next_step( array( $dr( array() ) ) ) );
check( 'flow: priced but unverified goes to verify', 'verify' === db_seller_next_step( array( $dr( array( 'price' => 500 ) ) ) ) );
check( 'flow: priced and verified goes to submit', 'submit' === db_seller_next_step( array( $dr( array( 'price' => 500, 'verify_status' => 'verified' ) ) ) ) );
check( 'flow: everything live opens the dashboard', 'dashboard' === db_seller_next_step( array( $dr( array( 'review_status' => 'approved', 'price' => 500 ) ) ) ) );
check( 'flow: only removed names starts at add again', 'add' === db_seller_next_step( array( $dr( array( 'review_status' => 'removed' ) ) ) ) );
check( 'flow: after each action', 'prices' === db_seller_step_after( 'add' ) && 'verify' === db_seller_step_after( 'price' ) && 'names' === db_seller_step_after( 'submit' ) && '' === db_seller_step_after( 'check' ) );
// Reliability.
$rd = db_seller_rules_default();
check( 'nudges: none before day 1, then 1, 2, 3', 0 === db_seller_nudge_stage( 23, $rd['remind_days'] ) && 1 === db_seller_nudge_stage( 24, $rd['remind_days'] ) && 2 === db_seller_nudge_stage( 72, $rd['remind_days'] ) && 3 === db_seller_nudge_stage( 130, $rd['remind_days'] ) );
check( 'strike on day 7, not before', ! db_seller_strike_due( 167, 7 ) && db_seller_strike_due( 168, 7 ) );
check( 'suspend: second strike or 10 days ignored', ! db_seller_should_suspend( 1, 200, 2, 10 ) && db_seller_should_suspend( 2, 10, 2, 10 ) && db_seller_should_suspend( 1, 240, 2, 10 ) );
check( 'transfer: reminders day 1 and 2, strike day 3', '' === db_seller_transfer_stage( 10, 3 ) && 'remind1' === db_seller_transfer_stage( 30, 3 ) && 'remind2' === db_seller_transfer_stage( 50, 3 ) && 'strike' === db_seller_transfer_stage( 72, 3 ) );
check( 'rdap expiry parsed from events', 1798761600 === db_seller_rdap_expiry( '{"events":[{"eventAction":"registration","eventDate":"2020-01-01T00:00:00Z"},{"eventAction":"expiration","eventDate":"2027-01-01T00:00:00Z"}]}' ) && 0 === db_seller_rdap_expiry( 'not json' ) );
check( 'expiry: warn within 30 days, down when expired, nothing when unknown', 'warn' === db_seller_expiry_action( $n + 10 * $d, $n, 30 ) && 'expired' === db_seller_expiry_action( $n - 1, $n, 30 ) && '' === db_seller_expiry_action( $n + 40 * $d, $n, 30 ) && '' === db_seller_expiry_action( 0, $n, 30 ) );

check( 'a completed sale shows as Sold, not Live (SL-3)', 'Sold' === db_seller_status( array( 'review_status' => 'sold' ) )[0] );

echo "\n$pass/" . ( $pass + $fail ) . " passed\n"; exit( $fail ? 1 : 0 );
