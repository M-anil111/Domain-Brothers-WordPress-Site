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
	define( 'DB_SELLER_AGREEMENT_VERSION', '2026-11-22-draft' );
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
