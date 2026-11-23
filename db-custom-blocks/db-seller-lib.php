<?php
/**
 * Seller marketplace, phase 1: the rules, as plain functions with no WordPress calls, so they are
 * tested offline (tests/seller/seller-test.php). The pages, storage and admin screens are in
 * db-seller-block.php. Plan: MARKETPLACE_PLAN.md (owner decisions of 2026-11-18).
 *
 * @package DB_Custom_Blocks
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'DB_SELLER_TESTING' ) ) {
	exit;
}

if ( ! defined( 'DB_SELLER_MAX_NAMES' ) ) {
	define( 'DB_SELLER_MAX_NAMES', 50 );          // Names accepted in one paste.
	define( 'DB_SELLER_MIN_PRICE', 100 );         // Lowest Buy Now price, USD.
	define( 'DB_SELLER_MAX_PRICE', 10000000 );    // Highest price, USD.
	define( 'DB_SELLER_COMMISSION_PCT', 15 );     // Owner decision 1.
	define( 'DB_SELLER_COMMISSION_MIN', 100 );    // Owner decision 1.
	define( 'DB_SELLER_VERIFY_HOURS', 72 );       // Automatic DNS checks stop after this.
	define( 'DB_SELLER_CNAME_TARGET', 'verify.domainbrothers.com' );
	define( 'DB_SELLER_AGREEMENT_VERSION', '2026-11-22.2' ); // 2.: sellers answer offers, response times, 72-hour transfer, re-checks
	define( 'DB_SELLER_COUNTRIES', 'US,CA' );     // Owner decision 3: US and Canada sellers at launch.
}

if ( ! function_exists( 'db_seller_normalise_name' ) ) {
	/**
	 * One typed or pasted entry to a bare lowercase domain name, or a reason it cannot be one.
	 *
	 * @return array{0:string,1:string} [name, reason]; name is '' when reason is set.
	 */
	function db_seller_normalise_name( $raw ) {
		$s = strtolower( trim( (string) $raw ) );
		$s = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $s );   // scheme
		$s = preg_replace( '#[/?\#].*$#', '', $s );               // path, query, fragment
		$s = preg_replace( '#:\d+$#', '', $s );                   // port
		$s = preg_replace( '#^www\.#', '', $s );
		$s = rtrim( $s, '.' );
		if ( '' === $s ) {
			return array( '', 'empty' );
		}
		if ( preg_match( '/[^\x20-\x7e]/', $s ) ) {
			return array( '', 'Use the xn-- (punycode) form for names with accents or non-Latin letters.' );
		}
		if ( strlen( $s ) > 253 || ! preg_match( '/^([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,24}|xn--[a-z0-9-]{1,59})$/', $s ) ) {
			return array( '', 'Not a valid domain name.' );
		}
		if ( ! db_seller_is_main_name( $s ) ) {
			return array( '', 'List the main name only (for example example.com, not shop.example.com).' );
		}
		return array( $s, '' );
	}
}

if ( ! function_exists( 'db_seller_is_main_name' ) ) {
	/** A name someone can register: two labels, or three under a two-part ending such as co.uk. */
	function db_seller_is_main_name( $name ) {
		$labels = explode( '.', (string) $name );
		$n      = count( $labels );
		if ( 2 === $n ) {
			return true;
		}
		if ( 3 !== $n ) {
			return false;
		}
		$second = array( 'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'com.au', 'net.au', 'org.au', 'id.au', 'co.nz', 'net.nz', 'org.nz', 'co.za', 'com.br', 'com.mx', 'co.in', 'net.in', 'org.in', 'co.jp', 'com.cn', 'com.sg', 'com.hk', 'co.kr', 'com.tr', 'com.ar', 'co.il', 'com.my', 'com.ph', 'co.id', 'com.co', 'qc.ca', 'on.ca', 'bc.ca', 'ab.ca' );
		return in_array( $labels[1] . '.' . $labels[2], $second, true );
	}
}

if ( ! function_exists( 'db_seller_parse_names' ) ) {
	/**
	 * A pasted block (commas, spaces, semicolons or new lines) to unique valid names.
	 *
	 * @return array{ok:string[],bad:array<int,array{0:string,1:string}>,dupes:string[],over:int}
	 */
	function db_seller_parse_names( $raw, $max = DB_SELLER_MAX_NAMES ) {
		$out   = array( 'ok' => array(), 'bad' => array(), 'dupes' => array(), 'over' => 0 );
		$parts = preg_split( '/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $parts as $p ) {
			list( $name, $why ) = db_seller_normalise_name( $p );
			if ( '' !== $why ) {
				if ( 'empty' !== $why ) {
					$out['bad'][] = array( mb_substr( $p, 0, 80 ), $why );
				}
				continue;
			}
			if ( in_array( $name, $out['ok'], true ) ) {
				$out['dupes'][] = $name;
				continue;
			}
			if ( count( $out['ok'] ) >= $max ) {
				++$out['over'];
				continue;
			}
			$out['ok'][] = $name;
		}
		return $out;
	}
}

if ( ! function_exists( 'db_seller_clean_amount' ) ) {
	/** "$12,500" or "12500.00" to whole dollars; 0 when empty or not a number. */
	function db_seller_clean_amount( $raw ) {
		$s = preg_replace( '/[\s$,]|usd/i', '', (string) $raw );
		if ( '' === $s || ! preg_match( '/^\d+(\.\d{1,2})?$/', $s ) ) {
			return 0;
		}
		return (int) round( (float) $s );
	}
}

if ( ! function_exists( 'db_seller_validate_row' ) ) {
	/**
	 * One pricing row.
	 *
	 * @param array $r option (buy_now|make_offer), price, min_offer (raw strings allowed).
	 * @return array{0:array{option:string,price:int,min_offer:int},1:string[]} [clean row, errors]
	 */
	function db_seller_validate_row( array $r ) {
		$opt   = ( isset( $r['option'] ) && 'make_offer' === $r['option'] ) ? 'make_offer' : 'buy_now';
		$price = db_seller_clean_amount( isset( $r['price'] ) ? $r['price'] : '' );
		$min   = db_seller_clean_amount( isset( $r['min_offer'] ) ? $r['min_offer'] : '' );
		$err   = array();
		if ( 'buy_now' === $opt && $price < DB_SELLER_MIN_PRICE ) {
			$err[] = 'Buy Now needs a price of at least $' . number_format( DB_SELLER_MIN_PRICE ) . '.';
		}
		if ( $price > DB_SELLER_MAX_PRICE || $min > DB_SELLER_MAX_PRICE ) {
			$err[] = 'Prices above $' . number_format( DB_SELLER_MAX_PRICE ) . ' need a broker. Contact us.';
		}
		if ( $min > 0 && $price > 0 && $min >= $price ) {
			$err[] = 'The minimum offer must be below the price.';
		}
		return array( array( 'option' => $opt, 'price' => $price, 'min_offer' => $min ), $err );
	}
}

if ( ! function_exists( 'db_seller_commission' ) ) {
	/** Commission in dollars: 15% with a $100 minimum, never more than the sale price. */
	function db_seller_commission( $price ) {
		$price = max( 0, (int) $price );
		$fee   = max( DB_SELLER_COMMISSION_MIN, (int) round( $price * DB_SELLER_COMMISSION_PCT / 100 ) );
		return min( $price, $fee );
	}
}

if ( ! function_exists( 'db_seller_txt_value' ) ) {
	/** The TXT record a seller adds at the root of the name. */
	function db_seller_txt_value( $token ) {
		return 'domainbrothers-verify=' . preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $token ) );
	}
}

if ( ! function_exists( 'db_seller_cname_host' ) ) {
	/** The CNAME host a seller can add instead of the TXT record. */
	function db_seller_cname_host( $token, $domain ) {
		return 'db-' . preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $token ) ) . '.' . strtolower( (string) $domain );
	}
}

if ( ! function_exists( 'db_seller_txt_matches' ) ) {
	/**
	 * True when one TXT record holds the seller's value. $records is the shape dns_get_record()
	 * returns: each item has 'txt' and, for split strings, 'entries'.
	 */
	function db_seller_txt_matches( array $records, $token ) {
		$want = db_seller_txt_value( $token );
		if ( 'domainbrothers-verify=' === $want ) {
			return false;
		}
		foreach ( $records as $rec ) {
			$parts = array();
			if ( isset( $rec['entries'] ) && is_array( $rec['entries'] ) ) {
				$parts[] = implode( '', $rec['entries'] );
			}
			if ( isset( $rec['txt'] ) ) {
				$parts[] = (string) $rec['txt'];
			}
			foreach ( $parts as $txt ) {
				if ( trim( $txt, " \t\"" ) === $want ) {
					return true;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists( 'db_seller_cname_matches' ) ) {
	/** True when a CNAME record points at the verification host. */
	function db_seller_cname_matches( array $records, $target = DB_SELLER_CNAME_TARGET ) {
		foreach ( $records as $rec ) {
			if ( isset( $rec['target'] ) && rtrim( strtolower( (string) $rec['target'] ), '.' ) === strtolower( $target ) ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'db_seller_ready' ) ) {
	/** A listing can go to review once it is priced correctly and ownership is verified. */
	function db_seller_ready( array $row ) {
		list( , $err ) = db_seller_validate_row( $row );
		return ! $err && 'verified' === ( isset( $row['verify_status'] ) ? $row['verify_status'] : '' );
	}
}

if ( ! function_exists( 'db_seller_status' ) ) {
	/**
	 * What the seller sees for one listing.
	 *
	 * @return array{0:string,1:string} [label, tone] tone: muted|warn|ok|bad
	 */
	function db_seller_status( array $row ) {
		$review = isset( $row['review_status'] ) ? $row['review_status'] : 'draft';
		$verify = isset( $row['verify_status'] ) ? $row['verify_status'] : 'pending';
		if ( 'sold' === $review ) {
			return array( 'Sold', 'ok' );
		}
		if ( 'approved' === $review ) {
			return array( 'Live', 'ok' );
		}
		if ( 'rejected' === $review ) {
			return array( 'Not accepted', 'bad' );
		}
		if ( 'pending' === $review ) {
			return array( 'In review', 'warn' );
		}
		if ( 'failed' === $verify ) {
			return array( 'Ownership not found', 'bad' );
		}
		if ( 'verified' === $verify ) {
			return array( 'Verified, ready to submit', 'ok' );
		}
		return array( 'Waiting for ownership check', 'muted' );
	}
}

if ( ! function_exists( 'db_seller_listing_meta' ) ) {
	/**
	 * The `domain` post meta for an approved seller listing, in the shape the site already uses
	 * (domain_type offer / offer_buy_now, domain_status sale). The minimum offer is private
	 * (underscore key) so it never appears on the public page.
	 */
	function db_seller_listing_meta( array $row, $seller_id, $today ) {
		$buy  = 'buy_now' === $row['option'] && (int) $row['price'] > 0;
		$name = strtolower( (string) $row['domain'] );
		$dot  = strpos( $name, '.' );
		$kw   = false === $dot ? $name : substr( $name, 0, $dot );
		return array(
			'domain_price'      => $buy ? (string) (int) $row['price'] : '0', // '0', never '': the theme footer multiplies this value and '' is a fatal error on PHP 8.
			'domain_type'       => $buy ? 'offer_buy_now' : 'offer',
			'domain_status'     => 'sale',
			'domain_keyword'    => ucfirst( $kw ),
			'domain_url'        => 'https://' . $name,
			'domain_date'       => (string) $today,
			'db_seller_id'      => (string) (int) $seller_id,
			'db_seller_listing' => (string) (int) ( isset( $row['id'] ) ? $row['id'] : 0 ),
			'_db_min_offer'     => (string) (int) ( isset( $row['min_offer'] ) ? $row['min_offer'] : 0 ),
		);
	}
}

if ( ! function_exists( 'db_seller_clean_profile' ) ) {
	/**
	 * The seller setup form.
	 *
	 * @return array{0:array,1:string[]} [clean, errors]
	 */
	function db_seller_clean_profile( array $p ) {
		$clean = array(
			'legal_name' => trim( mb_substr( preg_replace( '/\s+/', ' ', strip_tags( (string) ( $p['legal_name'] ?? '' ) ) ), 0, 120 ) ),
			'company'    => trim( mb_substr( preg_replace( '/\s+/', ' ', strip_tags( (string) ( $p['company'] ?? '' ) ) ), 0, 120 ) ),
			'country'    => strtoupper( trim( (string) ( $p['country'] ?? '' ) ) ),
			'phone'      => trim( mb_substr( preg_replace( '/[^0-9+()\-. ]/', '', (string) ( $p['phone'] ?? '' ) ), 0, 30 ) ),
			'agree'      => ! empty( $p['agree'] ),
		);
		$err = array();
		if ( mb_strlen( $clean['legal_name'] ) < 2 ) {
			$err[] = 'Enter your full legal name.';
		}
		if ( ! in_array( $clean['country'], explode( ',', DB_SELLER_COUNTRIES ), true ) ) {
			$err[] = 'Selling is open to sellers in the United States and Canada for now.';
		}
		if ( ! $clean['agree'] ) {
			$err[] = 'Accept the seller agreement and listing rules to continue.';
		}
		return array( $clean, $err );
	}
}

/* ------------------------------------------------------------------ phase 2: bulk import */

if ( ! defined( 'DB_SELLER_MAX_ROWS' ) ) {
	define( 'DB_SELLER_MAX_ROWS', 1000 ); // Rows read from one spreadsheet.
}

if ( ! function_exists( 'db_seller_sheet_rows' ) ) {
	/**
	 * A table (first row = headers) to import rows in the Sedo layout: Domain Name, Selling Option,
	 * For Sale, Price, Minimum Price, Currency, Action Type. Headers are matched case-insensitively.
	 *
	 * @param array<int,array<int,string>> $table
	 * @return array{rows:array<int,array>,errors:string[]}
	 */
	function db_seller_sheet_rows( array $table ) {
		$out = array( 'rows' => array(), 'errors' => array() );
		if ( ! $table ) {
			$out['errors'][] = 'The file is empty.';
			return $out;
		}
		// Common headings from other marketplaces and hand-made sheets map to the template's columns.
		$alias = array( 'domain' => 'domainname', 'domains' => 'domainname', 'name' => 'domainname', 'url' => 'domainname', 'website' => 'domainname', 'option' => 'sellingoption', 'type' => 'sellingoption', 'listingtype' => 'sellingoption', 'askingprice' => 'price', 'buynow' => 'price', 'buynowprice' => 'price', 'bin' => 'price', 'minoffer' => 'minimumprice', 'minimumoffer' => 'minimumprice', 'minimum' => 'minimumprice', 'floor' => 'minimumprice', 'floorprice' => 'minimumprice' );
		$head  = array_map( function ( $h ) use ( $alias ) { $k = strtolower( preg_replace( '/[^a-z]/i', '', (string) $h ) ); return $alias[ $k ] ?? $k; }, array_shift( $table ) );
		$col   = array();
		foreach ( $head as $i => $k ) {
			if ( ! isset( $col[ $k ] ) ) {
				$col[ $k ] = $i; // the first column with a heading wins
			}
		}
		if ( ! isset( $col['domainname'] ) ) {
			$out['errors'][] = 'The first row must contain a "Domain Name" column. Download the template to see the layout.';
			return $out;
		}
		$get = function ( $r, $k ) use ( $col ) { return isset( $col[ $k ], $r[ $col[ $k ] ] ) ? trim( (string) $r[ $col[ $k ] ] ) : ''; };
		$line = 1;
		foreach ( $table as $r ) {
			++$line;
			if ( count( $out['rows'] ) >= DB_SELLER_MAX_ROWS ) {
				$out['errors'][] = 'Only the first ' . DB_SELLER_MAX_ROWS . ' rows were read.';
				break;
			}
			$raw = $get( $r, 'domainname' );
			if ( '' === $raw ) {
				continue;
			}
			list( $name, $why ) = db_seller_normalise_name( $raw );
			if ( '' !== $why ) {
				$out['errors'][] = 'Row ' . $line . ' (' . mb_substr( $raw, 0, 60 ) . '): ' . ( 'empty' === $why ? 'no name.' : $why );
				continue;
			}
			$opt    = strtoupper( str_replace( array( ' ', '-' ), '_', $get( $r, 'sellingoption' ) ) );
			$sale   = strtolower( $get( $r, 'forsale' ) );
			$cur    = strtoupper( $get( $r, 'currency' ) );
			$action = strtoupper( $get( $r, 'actiontype' ) );
			if ( '' !== $cur && 'USD' !== $cur ) {
				$out['errors'][] = 'Row ' . $line . ' (' . $name . '): prices must be in USD; ' . $cur . ' is not supported yet.';
				continue;
			}
			$out['rows'][] = array(
				'line'      => $line,
				'domain'    => $name,
				'option'    => 'MAKE_OFFER' === $opt ? 'make_offer' : 'buy_now',
				'price'     => $get( $r, 'price' ),
				'min_offer' => $get( $r, 'minimumprice' ),
				'for_sale'  => ! in_array( $sale, array( 'no', 'n', 'false', '0' ), true ),
				'delete'    => 'DELETE' === $action,
			);
		}
		return $out;
	}
}

if ( ! function_exists( 'db_seller_csv_table' ) ) {
	/** CSV text (comma, semicolon or tab separated; UTF-8 with or without BOM) to a table. */
	function db_seller_csv_table( $text ) {
		$text  = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $text );
		$lines = preg_split( '/\r\n|\r|\n/', $text );
		$first = (string) ( $lines[0] ?? '' );
		$sep   = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ( substr_count( $first, "\t" ) > substr_count( $first, ',' ) ? "\t" : ',' );
		$table = array();
		foreach ( $lines as $l ) {
			if ( '' === trim( $l ) ) {
				continue;
			}
			$table[] = str_getcsv( $l, $sep, '"', '\\' );
		}
		return $table;
	}
}

if ( ! function_exists( 'db_seller_xlsx_table' ) ) {
	/** The first sheet of an XLSX file to a table, or null when it cannot be read. */
	function db_seller_xlsx_table( $path ) {
		if ( ! class_exists( 'ZipArchive' ) || ! function_exists( 'simplexml_load_string' ) ) {
			return null;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return null;
		}
		$shared = array();
		$ss     = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss ) {
			$x = @simplexml_load_string( $ss, 'SimpleXMLElement', LIBXML_NONET ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $x ) {
				foreach ( $x->si as $si ) {
					$t = '';
					if ( isset( $si->t ) ) {
						$t = (string) $si->t;
					} else {
						foreach ( $si->r as $run ) {
							$t .= (string) $run->t;
						}
					}
					$shared[] = $t;
				}
			}
		}
		$sheet = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
		$zip->close();
		if ( false === $sheet ) {
			return null;
		}
		$x = @simplexml_load_string( $sheet, 'SimpleXMLElement', LIBXML_NONET ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $x || ! isset( $x->sheetData ) ) {
			return null;
		}
		$table = array();
		foreach ( $x->sheetData->row as $row ) {
			$cells = array();
			foreach ( $row->c as $c ) {
				$ref = (string) $c['r'];
				$letters = preg_replace( '/\d+/', '', $ref );
				$idx = 0;
				foreach ( str_split( $letters ) as $ch ) {
					$idx = $idx * 26 + ( ord( $ch ) - 64 );
				}
				$type = (string) $c['t'];
				$val  = 'inlineStr' === $type ? (string) $c->is->t : (string) $c->v;
				if ( 's' === $type ) {
					$val = $shared[ (int) $val ] ?? '';
				}
				$cells[ max( 0, $idx - 1 ) ] = $val;
			}
			if ( $cells ) {
				$max  = max( array_keys( $cells ) );
				$line = array();
				for ( $i = 0; $i <= $max; $i++ ) {
					$line[] = $cells[ $i ] ?? '';
				}
				$table[] = $line;
			}
		}
		return $table;
	}
}

if ( ! function_exists( 'db_seller_plan_import' ) ) {
	/**
	 * What an import will do, before anything is written.
	 *
	 * @param array  $rows     From db_seller_sheet_rows().
	 * @param array  $existing This seller's listings keyed by domain: review_status.
	 * @param string $mode     import (add and update) | sync (also remove names missing from the file).
	 * @return array{add:array,update:array,remove:array,skip:array}
	 */
	function db_seller_plan_import( array $rows, array $existing, $mode = 'import' ) {
		$plan = array( 'add' => array(), 'update' => array(), 'remove' => array(), 'skip' => array() );
		$seen = array();
		foreach ( $rows as $r ) {
			$d = $r['domain'];
			if ( isset( $seen[ $d ] ) ) {
				$plan['skip'][] = array( $d, 'Listed twice in the file; the first row was used.' );
				continue;
			}
			$seen[ $d ] = true;
			$has = isset( $existing[ $d ] );
			if ( $r['delete'] || ! $r['for_sale'] ) {
				$has ? $plan['remove'][] = $d : $plan['skip'][] = array( $d, 'Marked for removal but not in your list.' );
				continue;
			}
			list( $clean, $err ) = db_seller_validate_row( $r );
			if ( $err ) {
				$plan['skip'][] = array( $d, implode( ' ', $err ) );
				continue;
			}
			$clean['domain'] = $d;
			if ( $has ) {
				if ( 'pending' === $existing[ $d ] ) {
					$plan['skip'][] = array( $d, 'In review; change it after the review.' );
				} else {
					$plan['update'][] = $clean;
				}
			} else {
				$plan['add'][] = $clean;
			}
		}
		if ( 'sync' === $mode ) {
			foreach ( $existing as $d => $status ) {
				if ( ! isset( $seen[ $d ] ) && ! in_array( $d, $plan['remove'], true ) ) {
					$plan['remove'][] = $d;
				}
			}
		}
		return $plan;
	}
}

if ( ! function_exists( 'db_seller_template_csv' ) ) {
	/** The download template, in the Sedo column layout. */
	function db_seller_template_csv() {
		return "Domain Name,Selling Option,For Sale,Price,Minimum Price,Currency,Action Type\r\n"
			. "example.com,BUY_NOW,yes,2500,2000,USD,\r\n"
			. "example.ca,MAKE_OFFER,yes,,1000,USD,\r\n"
			. "oldname.com,,,,,,DELETE\r\n";
	}
}

if ( ! function_exists( 'db_seller_adjust_price' ) ) {
	/** A price changed by a percentage (-90 to +500), rounded to whole dollars, never below $100. */
	function db_seller_adjust_price( $price, $pct ) {
		$pct = max( -90, min( 500, (float) $pct ) );
		return max( DB_SELLER_MIN_PRICE, (int) round( (int) $price * ( 100 + $pct ) / 100 ) );
	}
}

if ( ! function_exists( 'db_seller_scale_min' ) ) {
	/**
	 * The private minimum after a price change: scaled by the same ratio, kept below the new price
	 * and above zero, so the auto-counter keeps working (SL-16). No minimum stays no minimum.
	 */
	function db_seller_scale_min( $min, $old_price, $new_price ) {
		$min = (int) $min; $old_price = (int) $old_price; $new_price = (int) $new_price;
		if ( $min <= 0 || $old_price <= 0 || $new_price <= 1 ) {
			return 0;
		}
		return max( 1, min( $new_price - 1, (int) round( $min * $new_price / $old_price ) ) );
	}
}

/* ------------------------------------------------------------------ phase 2: checks, guidance, exclusive */

if ( ! defined( 'DB_SELLER_EXCLUSIVE_PCT' ) ) {
	define( 'DB_SELLER_EXCLUSIVE_PCT', 10 ); // Owner decision 8.
}

if ( ! function_exists( 'db_seller_blocked' ) ) {
	/**
	 * '' when a name may be listed by a seller, otherwise the reason. Famous brands need a broker
	 * (trademark risk); a few abusive terms are refused outright. The lists can be extended with the
	 * filters db_seller_blocked_brands and db_seller_blocked_terms.
	 */
	function db_seller_blocked( $name ) {
		$label  = strtolower( (string) $name );
		$dot    = strpos( $label, '.' );
		$label  = false === $dot ? $label : substr( $label, 0, $dot );
		$flat   = str_replace( '-', '', $label );
		$brands = array( 'google', 'facebook', 'instagram', 'whatsapp', 'youtube', 'amazon', 'apple', 'iphone', 'microsoft', 'windows', 'netflix', 'paypal', 'tiktok', 'twitter', 'linkedin', 'snapchat', 'spotify', 'uber', 'airbnb', 'tesla', 'nike', 'adidas', 'cocacola', 'pepsi', 'disney', 'samsung', 'visa', 'mastercard', 'walmart', 'chatgpt', 'openai', 'anthropic' );
		// Refused outright: unambiguous abuse material and slurs (long enough not to occur inside
		// ordinary words).
		$terms  = array( 'childporn', 'kiddieporn', 'nigger', 'faggot', 'heilhitler', 'whitepower', 'killjews', 'killgays' );
		// Broker review: profanity, adult words and short terms that also occur inside innocent
		// names (torpedo, pedometer), so a person decides instead of a refusal (SL-14).
		$review = array( 'fuck', 'cunt', 'shit', 'bitch', 'whore', 'slut', 'porn', 'xxx', 'nude', 'pussy', 'nazi', 'pedo', 'lolita', 'preteen', 'nigga', 'wetback', 'raghead', 'kkk' );
		// Ordinary words that contain a listed term are masked first (SL-14).
		$allow  = array( 'torpedo', 'pedometer', 'pedology', 'pineapple', 'grapple', 'snapple', 'scrapple', 'appleton', 'applejack', 'crabapple', 'advisable', 'revisable', 'visage', 'envisage', 'vise', 'exuberant', 'protuberant', 'tuberose', 'tuber', 'shiitake', 'shitake', 'teapot', 'scunthorpe', 'cocktail', 'sussex', 'essex', 'therapist', 'grapes', 'nikel' );
		if ( function_exists( 'apply_filters' ) ) {
			$allow = (array) apply_filters( 'db_seller_blocked_allow', $allow );
		}
		usort( $allow, static function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
		$flat = str_replace( array_filter( array_map( 'strval', $allow ) ), '_', $flat );
		if ( function_exists( 'apply_filters' ) ) {
			$brands = (array) apply_filters( 'db_seller_blocked_brands', $brands );
			$terms  = (array) apply_filters( 'db_seller_blocked_terms', $terms );
			$review = (array) apply_filters( 'db_seller_review_terms', $review );
		}
		foreach ( $terms as $t ) {
			if ( '' !== $t && false !== strpos( $flat, $t ) ) {
				return 'This name cannot be listed.';
			}
		}
		// A word about minors together with a sexual or picture word is refused outright.
		if ( preg_match( '/preteen|lolita|pedo|underage|child|kiddie|teen|minor/', $flat ) && preg_match( '/porn|sex|nude|naked|xxx|pics|pix|nsfw|cam/', $flat ) ) {
			return 'This name cannot be listed.';
		}
		foreach ( $review as $t ) {
			if ( '' !== $t && false !== strpos( $flat, $t ) ) {
				return 'Names with adult or offensive words need a broker\'s review. Contact us to list it.';
			}
		}
		foreach ( $brands as $b ) {
			if ( '' !== $b && false !== strpos( $flat, $b ) ) {
				return 'Names with a famous brand need a broker\'s review. Contact us to list it.';
			}
		}
		return '';
	}
}

if ( ! function_exists( 'db_seller_fee' ) ) {
	/** Commission for a sale: 15% (10% when exclusive), $100 minimum, never more than the price. */
	function db_seller_fee( $price, $exclusive = false ) {
		$price = max( 0, (int) $price );
		$pct   = $exclusive ? DB_SELLER_EXCLUSIVE_PCT : DB_SELLER_COMMISSION_PCT;
		return min( $price, max( DB_SELLER_COMMISSION_MIN, (int) round( $price * $pct / 100 ) ) );
	}
}

if ( ! function_exists( 'db_seller_price_guide' ) ) {
	/**
	 * Price guidance for one extension from Domain Brothers' own sales.
	 *
	 * @param array<int,array{name:string,price:float}> $sold Sold names with a recorded price.
	 * @return array{count:int,median:int,low:int,high:int}|null Null below 3 recorded sales.
	 */
	function db_seller_price_guide( array $sold, $ext ) {
		$ext = strtolower( (string) $ext );
		$p   = array();
		foreach ( $sold as $s ) {
			$n   = strtolower( (string) ( $s['name'] ?? '' ) );
			$dot = strrpos( $n, '.' );
			if ( false !== $dot && substr( $n, $dot ) === $ext && (float) ( $s['price'] ?? 0 ) > 0 ) {
				$p[] = (float) $s['price'];
			}
		}
		if ( count( $p ) < 3 ) {
			return null;
		}
		sort( $p );
		$c   = count( $p );
		$mid = 0 === $c % 2 ? ( $p[ $c / 2 - 1 ] + $p[ $c / 2 ] ) / 2 : $p[ ( $c - 1 ) / 2 ];
		return array( 'count' => $c, 'median' => (int) round( $mid ), 'low' => (int) round( $p[0] ), 'high' => (int) round( $p[ $c - 1 ] ) );
	}
}

if ( ! function_exists( 'db_seller_clean_details' ) ) {
	/** The listing details form: description (600 chars, plain text) and up to 8 keywords. */
	function db_seller_clean_details( array $p ) {
		$desc = trim( preg_replace( '/[ \t]+/', ' ', strip_tags( (string) ( $p['description'] ?? '' ) ) ) );
		$desc = mb_substr( preg_replace( "/\n{3,}/", "\n\n", str_replace( "\r", '', $desc ) ), 0, 600 );
		$kw   = array();
		foreach ( preg_split( '/[,;\n]+/', strip_tags( (string) ( $p['keywords'] ?? '' ) ) ) as $k ) {
			$k = trim( mb_substr( preg_replace( '/[^\p{L}\p{N} \-]/u', '', $k ), 0, 30 ) );
			if ( '' !== $k && ! in_array( mb_strtolower( $k ), array_map( 'mb_strtolower', $kw ), true ) ) {
				$kw[] = $k;
			}
		}
		return array( 'description' => $desc, 'keywords' => implode( ', ', array_slice( $kw, 0, 8 ) ) );
	}
}

if ( ! function_exists( 'db_seller_clean_categories' ) ) {
	/**
	 * Category ids chosen for a listing: whole numbers from the allowed set,
	 * no repeats, at most 3, as a comma-separated string.
	 *
	 * @param mixed $raw     Submitted value (array or comma list).
	 * @param int[] $allowed Ids of the site's domain categories.
	 */
	function db_seller_clean_categories( $raw, array $allowed ) {
		$list    = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$allowed = array_map( 'intval', $allowed );
		$out     = array();
		foreach ( $list as $v ) {
			$v = (int) $v;
			if ( $v > 0 && in_array( $v, $allowed, true ) && ! in_array( $v, $out, true ) ) {
				$out[] = $v;
			}
			if ( count( $out ) >= 3 ) {
				break;
			}
		}
		return implode( ',', $out );
	}
}

if ( ! function_exists( 'db_seller_rdap_tlds' ) ) {
	/** Extensions whose registries answer RDAP reliably (a 404 there means the name is not registered). */
	function db_seller_rdap_tlds() {
		return array( 'com', 'net', 'org', 'ca', 'app', 'dev', 'info', 'biz', 'io', 'co', 'xyz', 'online', 'store', 'tech', 'site' );
	}
}

if ( ! function_exists( 'db_seller_rdap_verdict' ) ) {
	/**
	 * Reads an RDAP answer for a name: 'registered', 'unregistered' or 'unknown'.
	 * Only a 404 from a registry on the reliable list counts as unregistered;
	 * anything else (timeouts, rate limits, other extensions) is 'unknown' and
	 * the DNS ownership check decides later.
	 *
	 * @param string   $name Normalised domain name.
	 * @param int|null $code HTTP status, or null when the lookup failed.
	 */
	function db_seller_rdap_verdict( $name, $code ) {
		$tld = strtolower( (string) substr( (string) strrchr( (string) $name, '.' ), 1 ) );
		if ( 200 === $code ) {
			return 'registered';
		}
		if ( 404 === $code && in_array( $tld, db_seller_rdap_tlds(), true ) ) {
			return 'unregistered';
		}
		return 'unknown';
	}
}

/* Phase 3: seller negotiations. */

if ( ! defined( 'DB_SELLER_REMIND_HOURS' ) ) {
	define( 'DB_SELLER_REMIND_HOURS', 72 );   // an unanswered offer reminds the seller (and the broker) after 3 days
}
if ( ! defined( 'DB_SELLER_EXPIRE_HOURS' ) ) {
	define( 'DB_SELLER_EXPIRE_HOURS', 336 );  // and closes after 14 days without a reply
}

if ( ! function_exists( 'db_seller_auto_counter' ) ) {
	/**
	 * The automatic answer to an offer below the seller's private minimum: the
	 * minimum, or null when the offer is at or above it (or there is no minimum).
	 */
	function db_seller_auto_counter( $amount, $min_offer ) {
		$amount    = (float) $amount;
		$min_offer = (int) $min_offer;
		// Every offer below the lowest price is answered automatically, private minimum or not (SL-9).
		$floor = max( $min_offer, (int) DB_SELLER_MIN_PRICE );
		return ( $amount > 0 && $amount < $floor ) ? (float) $floor : null;
	}
}

if ( ! function_exists( 'db_seller_offer_due' ) ) {
	/**
	 * What the hourly job does with an offer waiting for the seller:
	 * 'expire', 'remind' or ''.
	 *
	 * @param string $state    Deal state.
	 * @param int    $idle_h   Hours since the last move on the deal.
	 * @param bool   $reminded Whether the reminder was already sent.
	 */
	function db_seller_offer_due( $state, $idle_h, $reminded ) {
		if ( 'open' !== $state ) {
			return '';
		}
		$idle_h = (int) $idle_h;
		if ( $idle_h >= DB_SELLER_EXPIRE_HOURS ) {
			return 'expire';
		}
		if ( $idle_h >= DB_SELLER_REMIND_HOURS && ! $reminded ) {
			return 'remind';
		}
		return '';
	}
}

if ( ! function_exists( 'db_seller_offer_check' ) ) {
	/**
	 * Checks a seller's move on an offer before it reaches the deal engine.
	 * Returns '' when it may go ahead, otherwise the reason.
	 *
	 * @param string $do     accept | counter | decline
	 * @param mixed  $amount Counter price (counter only).
	 * @param string $state  Deal state.
	 */
	function db_seller_offer_check( $do, $amount, $state, $offer_amount = null ) {
		if ( ! in_array( $do, array( 'accept', 'counter', 'decline' ), true ) ) {
			return 'Choose accept, counter or decline.';
		}
		if ( 'accept' === $do && 'open' !== $state ) {
			return 'Only an offer waiting for you can be accepted.';
		}
		// An offer below the lowest price leaves the seller nothing after the minimum commission
		// ($99.60 paid $0.00 out; SL-9): counter instead.
		if ( 'accept' === $do && null !== $offer_amount && (float) $offer_amount < DB_SELLER_MIN_PRICE ) {
			return 'Offers under $' . DB_SELLER_MIN_PRICE . ' cannot be accepted: after the minimum commission you would receive nothing. Send a counter price instead.';
		}
		if ( 'decline' === $do && ! in_array( $state, array( 'open', 'countered' ), true ) ) {
			return 'This offer is already closed.';
		}
		if ( 'counter' === $do ) {
			if ( ! in_array( $state, array( 'open', 'countered', 'declined', 'expired' ), true ) ) {
				return 'This offer can no longer be countered.';
			}
			$v = db_seller_clean_amount( $amount );
			if ( null === $v || $v < DB_SELLER_MIN_PRICE ) {
				return 'Enter a counter price of at least $' . DB_SELLER_MIN_PRICE . '.';
			}
		}
		return '';
	}
}

/* Phase 4: sales and payouts. */

if ( ! defined( 'DB_SELLER_PAY_AFTER_CONFIRM_DAYS' ) ) {
	define( 'DB_SELLER_PAY_AFTER_CONFIRM_DAYS', 7 );   // payout due 7 days after the buyer confirms control
}
if ( ! defined( 'DB_SELLER_PAY_AFTER_TRANSFER_DAYS' ) ) {
	define( 'DB_SELLER_PAY_AFTER_TRANSFER_DAYS', 14 ); // or 14 days after the transfer when nobody disputes it
}

if ( ! function_exists( 'db_seller_payout_states' ) ) {
	function db_seller_payout_states() {
		return array(
			'collecting'       => 'Buyer paying monthly',
			'waiting_transfer' => 'Waiting for you to transfer the name',
			'waiting_confirm'  => 'Waiting for the buyer to confirm',
			'due'              => 'Payout scheduled',
			'paid'             => 'Paid to you',
			'held'             => 'On hold',
		);
	}
}

if ( ! function_exists( 'db_seller_payout_split' ) ) {
	/** Gross sale split into commission and the seller's net, in whole cents. */
	function db_seller_payout_split( $gross, $exclusive = false ) {
		$gross = round( max( 0, (float) $gross ), 2 );
		$fee   = (float) db_seller_fee( (int) round( $gross ), (bool) $exclusive );
		$fee   = min( $gross, $fee );
		return array( 'gross' => $gross, 'commission' => round( $fee, 2 ), 'net' => round( $gross - $fee, 2 ) );
	}
}

if ( ! function_exists( 'db_seller_payout_due_ts' ) ) {
	/**
	 * When the payout becomes due: 7 days after the buyer confirmed control, or
	 * 14 days after the transfer if the buyer never confirmed or disputed; the
	 * earlier of the two. 0 when neither has happened.
	 */
	function db_seller_payout_due_ts( $transferred_ts, $confirmed_ts ) {
		$c = (int) $confirmed_ts > 0 ? (int) $confirmed_ts + DB_SELLER_PAY_AFTER_CONFIRM_DAYS * 86400 : 0;
		$t = (int) $transferred_ts > 0 ? (int) $transferred_ts + DB_SELLER_PAY_AFTER_TRANSFER_DAYS * 86400 : 0;
		if ( $c && $t ) {
			return min( $c, $t );
		}
		return max( $c, $t );
	}
}

if ( ! function_exists( 'db_seller_payout_move' ) ) {
	/**
	 * The payout state machine, as a pure function: the new state for an
	 * action, or null when the action is not allowed from $state.
	 *
	 * @param string $state   Current state.
	 * @param string $action  plan_done | transferred | confirmed | pay | hold | release
	 * @param int    $now     Unix time.
	 * @param int    $due_ts  When the payout is due (pay only).
	 * @param string $held_from State before the hold (release only).
	 */
	function db_seller_payout_move( $state, $action, $now = 0, $due_ts = 0, $held_from = '' ) {
		switch ( $action ) {
			case 'plan_done':
				return 'collecting' === $state ? 'waiting_transfer' : null;
			case 'transferred':
				return 'waiting_transfer' === $state ? 'waiting_confirm' : null;
			case 'confirmed':
				return 'waiting_confirm' === $state ? 'due' : null;
			case 'pay':
				return ( 'due' === $state && (int) $due_ts > 0 && (int) $now >= (int) $due_ts ) ? 'paid' : null;
			case 'hold':
				return in_array( $state, array( 'collecting', 'waiting_transfer', 'waiting_confirm', 'due' ), true ) ? 'held' : null;
			case 'release':
				return ( 'held' === $state && in_array( $held_from, array( 'collecting', 'waiting_transfer', 'waiting_confirm', 'due' ), true ) ) ? $held_from : null;
		}
		return null;
	}
}

if ( ! function_exists( 'db_seller_statement_rows' ) ) {
	/**
	 * Rows of the seller's CSV statement (header first). Text cells that could
	 * be read as spreadsheet formulas are prefixed with an apostrophe.
	 *
	 * @param array $payouts Each with domain, created, gross, commission, net, state, due_ts, paid_ts, reference.
	 */
	function db_seller_statement_rows( array $payouts ) {
		$safe  = static function ( $v ) {
			$v = (string) $v;
			return ( '' !== $v && false !== strpos( '=+-@', $v[0] ) ) ? "'" . $v : $v;
		};
		$names = db_seller_payout_states();
		$rows  = array( array( 'Domain', 'Sale date', 'Gross (USD)', 'Commission (USD)', 'Net to you (USD)', 'Status', 'Due date', 'Paid date', 'Reference' ) );
		foreach ( $payouts as $p ) {
			$rows[] = array(
				$safe( $p['domain'] ?? '' ),
				! empty( $p['created'] ) ? gmdate( 'Y-m-d', (int) $p['created'] ) : '',
				number_format( (float) ( $p['gross'] ?? 0 ), 2, '.', '' ),
				number_format( (float) ( $p['commission'] ?? 0 ), 2, '.', '' ),
				number_format( (float) ( $p['net'] ?? 0 ), 2, '.', '' ),
				$names[ $p['state'] ?? '' ] ?? (string) ( $p['state'] ?? '' ),
				! empty( $p['due_ts'] ) ? gmdate( 'Y-m-d', (int) $p['due_ts'] ) : '',
				! empty( $p['paid_ts'] ) ? gmdate( 'Y-m-d', (int) $p['paid_ts'] ) : '',
				$safe( $p['reference'] ?? '' ),
			);
		}
		return $rows;
	}
}

/* Phase 5: sales pages for pointed names. */

if ( ! function_exists( 'db_seller_pointed_name' ) ) {
	/**
	 * The domain a request was made for when it is not the site's own host:
	 * lowercase, without "www." and port. '' for the site itself, its
	 * subdomains, IP addresses or anything that is not a domain name.
	 *
	 * @param string $host      HTTP Host header.
	 * @param string $site_host The site's own host (from home_url).
	 */
	function db_seller_pointed_name( $host, $site_host ) {
		$host = strtolower( trim( (string) $host ) );
		$host = rtrim( $host, '.' );
		$host = rtrim( (string) preg_replace( '/:\d+$/', '', $host ), '.' );
		$site = strtolower( preg_replace( '/^www\./', '', (string) $site_host ) );
		$root = implode( '.', array_slice( explode( '.', $site ), -2 ) ); // e.g. domainbrothers.com from beta.domainbrothers.com
		if ( '' === $host || filter_var( $host, FILTER_VALIDATE_IP ) || ! preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $host ) ) {
			return '';
		}
		$name = preg_replace( '/^www\./', '', $host );
		if ( $name === $site || $name === $root || substr( $name, -strlen( '.' . $root ) ) === '.' . $root ) {
			return '';
		}
		return $name;
	}
}

/* One owner per name: claims between accounts. */

if ( ! function_exists( 'db_seller_claim_losers' ) ) {
	/**
	 * When a seller proves ownership of a name, every other seller's open row
	 * for that name loses it. Returns the ids of those rows.
	 *
	 * @param array $rows       Rows for the name: id, seller_id, review_status.
	 * @param int   $winner_sid The seller who just verified.
	 */
	function db_seller_claim_losers( array $rows, $winner_sid ) {
		$out = array();
		foreach ( $rows as $r ) {
			if ( (int) $r['seller_id'] !== (int) $winner_sid && in_array( (string) $r['review_status'], array( 'draft', 'pending', 'approved', 'rejected' ), true ) ) {
				$out[] = (int) $r['id'];
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'db_seller_taken_reason' ) ) {
	/**
	 * Whether a seller may add a name, as a pure rule. Returns '' (free),
	 * 'claim' (another account lists it: allowed, needs fresh proof), or the reason it is refused.
	 *
	 * @param bool $mine      This seller already has an open row for the name.
	 * @param bool $other     Another seller has an open row for the name.
	 * @param bool $inventory The name is one of Domain Brothers' own listings.
	 * @param bool $in_sale   A sale of the name is in progress.
	 */
	function db_seller_taken_reason( $mine, $other, $inventory, $in_sale ) {
		if ( $mine ) {
			return 'Already in your list.';
		}
		if ( $inventory ) {
			return 'Already listed on Domain Brothers.';
		}
		if ( $in_sale ) {
			return 'This name is being sold right now. Contact us if you own it.';
		}
		return $other ? 'claim' : '';
	}
}

/* Ownership re-check every 120 days. */

if ( ! defined( 'DB_SELLER_RECHECK_DAYS' ) ) {
	define( 'DB_SELLER_RECHECK_DAYS', 120 ); // live names prove ownership again every 120 days
}
if ( ! defined( 'DB_SELLER_RECHECK_GRACE_DAYS' ) ) {
	define( 'DB_SELLER_RECHECK_GRACE_DAYS', 14 ); // a failing name has 14 days to be fixed before it comes down
}

if ( ! function_exists( 'db_seller_recheck_action' ) ) {
	/**
	 * What the daily re-check does with one live name.
	 *
	 * @param int  $verified_ts When ownership was last proved.
	 * @param int  $fail_ts     When the re-check first failed (0 = not failing).
	 * @param bool $passes      Whether the DNS record is there now (only read when a check is due).
	 * @param int  $now         Unix time.
	 * @return string '' (nothing due) | renew | warn | still_failing | take_down
	 */
	function db_seller_recheck_action( $verified_ts, $fail_ts, $passes, $now ) {
		$due = (int) $fail_ts > 0 || ( (int) $now - (int) $verified_ts ) >= DB_SELLER_RECHECK_DAYS * 86400;
		if ( ! $due ) {
			return '';
		}
		if ( $passes ) {
			return 'renew';
		}
		if ( (int) $fail_ts <= 0 ) {
			return 'warn';
		}
		return ( (int) $now - (int) $fail_ts ) >= DB_SELLER_RECHECK_GRACE_DAYS * 86400 ? 'take_down' : 'still_failing';
	}
}

/* Guided seller flow. */

if ( ! function_exists( 'db_seller_next_step' ) ) {
	/**
	 * The step a seller should see next: add | prices | verify | submit | dashboard.
	 *
	 * @param array $rows       The seller's listings (review_status, verify_status, option, price, min_offer).
	 * @param bool  $has_import A spreadsheet preview is waiting for confirmation.
	 */
	function db_seller_next_step( array $rows, $has_import = false ) {
		if ( $has_import ) {
			return 'add';
		}
		$drafts = array_filter( $rows, static function ( $r ) { return in_array( $r['review_status'] ?? '', array( 'draft', 'rejected' ), true ); } );
		if ( ! $rows || ( ! $drafts && ! array_filter( $rows, static function ( $r ) { return in_array( $r['review_status'] ?? '', array( 'pending', 'approved' ), true ); } ) ) ) {
			return 'add';
		}
		if ( ! $drafts ) {
			return 'dashboard';
		}
		foreach ( $drafts as $r ) {
			list( , $err ) = db_seller_validate_row( $r );
			if ( $err ) {
				return 'prices';
			}
		}
		foreach ( $drafts as $r ) {
			if ( 'verified' !== ( $r['verify_status'] ?? '' ) ) {
				return 'verify';
			}
		}
		return 'submit';
	}
}

if ( ! function_exists( 'db_seller_step_after' ) ) {
	/** Where a successful form post sends the seller: the step that follows the action. */
	function db_seller_step_after( $action ) {
		$map = array( 'setup' => 'add', 'add' => 'prices', 'import_apply' => 'prices', 'price' => 'verify', 'submit' => 'names' );
		return $map[ (string) $action ] ?? '';
	}
}

/* Seller reliability: answering offers, transfers, expiring names. */

if ( ! function_exists( 'db_seller_rules_default' ) ) {
	/** Defaults for the rules the owner can change in WP Admin. */
	function db_seller_rules_default() {
		return array(
			'remind_days'       => array( 1, 3, 5 ), // reminders to answer an offer
			'strike_days'       => 7,               // unanswered this long: a strike, the buyer is told, the broker takes over
			'suspend_strikes'   => 2,               // strikes within the window that take the account offline
			'strike_window'     => 90,              // days
			'suspend_idle_days' => 10,              // or one offer ignored this long
			'transfer_days'     => 3,               // days to start a transfer after a sale
			'expiry_warn_days'  => 30,              // warn before a name expires
			'claims_per_day'    => 5,               // ownership claims one account may start per day
		);
	}
}

if ( ! function_exists( 'db_seller_nudge_stage' ) ) {
	/** How many reminders an offer this old should have had (0 = none yet). */
	function db_seller_nudge_stage( $idle_h, array $days ) {
		$n = 0;
		foreach ( array_values( $days ) as $i => $d ) {
			if ( (float) $idle_h >= (float) $d * 24 ) {
				$n = $i + 1;
			}
		}
		return $n;
	}
}

if ( ! function_exists( 'db_seller_strike_due' ) ) {
	function db_seller_strike_due( $idle_h, $strike_days ) {
		return (float) $idle_h >= (float) $strike_days * 24;
	}
}

if ( ! function_exists( 'db_seller_should_suspend' ) ) {
	/** True when the account goes offline: too many strikes in the window, or one offer ignored too long. */
	function db_seller_should_suspend( $strikes_in_window, $max_idle_h, $suspend_strikes, $suspend_idle_days ) {
		return (int) $strikes_in_window >= (int) $suspend_strikes || (float) $max_idle_h >= (float) $suspend_idle_days * 24;
	}
}

if ( ! function_exists( 'db_seller_transfer_stage' ) ) {
	/**
	 * A sold name waiting for the seller to transfer it: '' | remind1 | remind2 | strike.
	 * Reminders on day 1 and 2, a strike once the deadline passes.
	 */
	function db_seller_transfer_stage( $hours_waiting, $transfer_days ) {
		$h = (float) $hours_waiting;
		if ( $h >= (float) $transfer_days * 24 ) {
			return 'strike';
		}
		if ( $h >= 48 ) {
			return 'remind2';
		}
		return $h >= 24 ? 'remind1' : '';
	}
}

if ( ! function_exists( 'db_seller_rdap_expiry' ) ) {
	/** The expiry time in an RDAP domain answer (events[].eventAction "expiration"), or 0. */
	function db_seller_rdap_expiry( $json ) {
		$d = is_array( $json ) ? $json : json_decode( (string) $json, true );
		if ( ! is_array( $d ) || empty( $d['events'] ) || ! is_array( $d['events'] ) ) {
			return 0;
		}
		foreach ( $d['events'] as $e ) {
			if ( isset( $e['eventAction'], $e['eventDate'] ) && 'expiration' === strtolower( (string) $e['eventAction'] ) ) {
				$t = strtotime( (string) $e['eventDate'] );
				return $t ? (int) $t : 0;
			}
		}
		return 0;
	}
}

if ( ! function_exists( 'db_seller_expiry_action' ) ) {
	/** '' | warn (expires within the warning window) | expired. Unknown expiry (0) does nothing. */
	function db_seller_expiry_action( $expiry_ts, $now, $warn_days ) {
		if ( (int) $expiry_ts <= 0 ) {
			return '';
		}
		if ( (int) $expiry_ts <= (int) $now ) {
			return 'expired';
		}
		return ( (int) $expiry_ts - (int) $now ) <= (int) $warn_days * 86400 ? 'warn' : '';
	}
}
