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
	define( 'DB_SELLER_AGREEMENT_VERSION', '2026-11-22' );
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
		return array( $s, '' );
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
		$head = array_map( function ( $h ) { return strtolower( preg_replace( '/[^a-z]/i', '', (string) $h ) ); }, array_shift( $table ) );
		$col  = array_flip( $head );
		if ( ! isset( $col['domainname'] ) ) {
			$out['errors'][] = 'The first row must contain the column "Domain Name". Download the template to see the layout.';
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
		$terms  = array( 'childporn', 'kiddieporn', 'lolita', 'pedo', 'preteen' );
		if ( function_exists( 'apply_filters' ) ) {
			$brands = (array) apply_filters( 'db_seller_blocked_brands', $brands );
			$terms  = (array) apply_filters( 'db_seller_blocked_terms', $terms );
		}
		foreach ( $terms as $t ) {
			if ( '' !== $t && false !== strpos( $flat, $t ) ) {
				return 'This name cannot be listed.';
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
