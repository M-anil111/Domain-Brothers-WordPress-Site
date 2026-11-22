<?php
/**
 * Domain tools: a free lookup page at /domain-tools/.
 *
 * Type a domain name and get, from free public sources that need no account:
 *
 *  - Registry record (RDAP via rdap.org): registered, expires, last changed,
 *    registrar, status codes, name servers, DNSSEC. A 404 means the name has no
 *    registration record.
 *  - DNS (Cloudflare DNS-over-HTTPS): website address (A/AAAA), mail servers (MX),
 *    name servers (NS), and whether a text record for SPF exists.
 *  - Web history (Internet Archive availability API): whether, and when, the
 *    Wayback Machine last captured the site.
 *  - Popularity (Tranco list API): the name's rank among the top one million
 *    sites, when it ranks.
 *  - Comparable sales: names Domain Brothers has sold with the same length and
 *    extension (count, median, range), from this site's own sold list.
 *  - Optional AI estimate: if the option db_replicate_token holds a Replicate
 *    API token, the HumbleWorth model (humbleworth/price-predict-v1) adds
 *    auction, marketplace and brokerage estimates. It is off without the token
 *    and is always labelled as a computer estimate, not an appraisal.
 *
 * Requests go through GET /wp-json/db/v1/domain-lookup?d=name. The visitor's input
 * is validated as a plain host name and only ever placed in the query string of
 * fixed provider addresses. Results are cached for six hours per name and each
 * visitor address is limited to twenty lookups per ten minutes.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_lk_http' ) ) {
	/**
	 * GET a JSON document from a fixed provider address.
	 *
	 * @param string $url     Full address (built by this file, never from visitor input alone).
	 * @param array  $headers Extra request headers.
	 * @return array{code:int,json:array|null}
	 */
	function db_lk_http( $url, array $headers = array() ) {
		$res = wp_remote_get( $url, array(
			'timeout'     => 6,
			'redirection' => 4,
			'headers'     => array_merge( array( 'Accept' => 'application/json', 'User-Agent' => 'DomainBrothers-DomainTools/1.0 (+https://www.domainbrothers.com)' ), $headers ),
		) );
		if ( is_wp_error( $res ) ) {
			return array( 'code' => 0, 'json' => null );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'json' => is_array( $json ) ? $json : null );
	}
}

if ( ! function_exists( 'db_lk_rdap_missing' ) ) {
	/** True when a 404 only means that the extension has no RDAP service (so nothing can be said). */
	function db_lk_rdap_missing( $body ) {
		$j = is_array( $body ) ? $body : json_decode( (string) $body, true );
		return is_array( $j ) && false !== stripos( (string) ( $j['title'] ?? '' ) . ' ' . (string) ( $j['description'][0] ?? '' ), 'no rdap service' );
	}
}

if ( ! function_exists( 'db_lk_registry' ) ) {
	/** @return array<string,mixed> */
	function db_lk_registry( $name ) {
		$r = db_lk_http( 'https://rdap.org/domain/' . rawurlencode( $name ) );
		if ( 404 === $r['code'] && ! db_lk_rdap_missing( $r['json'] ) ) {
			return array( 'state' => 'none' );
		}
		if ( 200 !== $r['code'] || ! $r['json'] ) {
			return array( 'state' => 'unavailable' );
		}
		$out = array( 'state' => 'ok', 'events' => array(), 'status' => array(), 'ns' => array(), 'registrar' => '', 'dnssec' => null );
		foreach ( (array) ( $r['json']['events'] ?? array() ) as $e ) {
			if ( ! empty( $e['eventAction'] ) && ! empty( $e['eventDate'] ) ) {
				$out['events'][ sanitize_key( $e['eventAction'] ) ] = substr( (string) $e['eventDate'], 0, 10 );
			}
		}
		foreach ( (array) ( $r['json']['status'] ?? array() ) as $s ) {
			$out['status'][] = sanitize_text_field( (string) $s );
		}
		foreach ( (array) ( $r['json']['nameservers'] ?? array() ) as $n ) {
			if ( ! empty( $n['ldhName'] ) ) {
				$out['ns'][] = strtolower( sanitize_text_field( (string) $n['ldhName'] ) );
			}
		}
		foreach ( (array) ( $r['json']['entities'] ?? array() ) as $ent ) {
			if ( in_array( 'registrar', (array) ( $ent['roles'] ?? array() ), true ) ) {
				foreach ( (array) ( $ent['vcardArray'][1] ?? array() ) as $row ) {
					if ( isset( $row[0], $row[3] ) && 'fn' === $row[0] ) {
						$out['registrar'] = sanitize_text_field( (string) $row[3] );
					}
				}
			}
		}
		if ( isset( $r['json']['secureDNS']['delegationSigned'] ) ) {
			$out['dnssec'] = (bool) $r['json']['secureDNS']['delegationSigned'];
		}
		return $out;
	}
}

if ( ! function_exists( 'db_lk_dns' ) ) {
	/** @return array<string,mixed> */
	function db_lk_dns( $name ) {
		$out = array();
		foreach ( array( 'A', 'AAAA', 'MX', 'NS', 'TXT' ) as $type ) {
			$r  = db_lk_http( 'https://cloudflare-dns.com/dns-query?name=' . rawurlencode( $name ) . '&type=' . $type, array( 'Accept' => 'application/dns-json' ) );
			$rows = array();
			foreach ( (array) ( $r['json']['Answer'] ?? array() ) as $a ) {
				$want = array( 'A' => 1, 'AAAA' => 28, 'MX' => 15, 'NS' => 2, 'TXT' => 16 );
				if ( (int) ( $a['type'] ?? 0 ) === $want[ $type ] && isset( $a['data'] ) ) {
					$rows[] = sanitize_text_field( trim( (string) $a['data'], '" ' ) );
				}
			}
			$out[ $type ] = array_slice( $rows, 0, 6 );
			if ( 'A' === $type ) {
				$out['AD'] = ! empty( $r['json']['AD'] );
			}
		}
		$out['SPF'] = (bool) array_filter( $out['TXT'], static function ( $t ) { return 0 === stripos( $t, 'v=spf1' ); } );
		unset( $out['TXT'] );
		return $out;
	}
}

if ( ! function_exists( 'db_lk_archive' ) ) {
	/** @return array<string,mixed> */
	function db_lk_archive( $name ) {
		$r    = db_lk_http( 'https://archive.org/wayback/available?url=' . rawurlencode( $name ) );
		$snap = $r['json']['archived_snapshots']['closest'] ?? null;
		if ( 200 !== $r['code'] ) {
			return array( 'state' => 'unavailable' );
		}
		if ( ! is_array( $snap ) || empty( $snap['timestamp'] ) ) {
			return array( 'state' => 'none' );
		}
		$ts = (string) $snap['timestamp'];
		return array(
			'state' => 'ok',
			'date'  => substr( $ts, 0, 4 ) . '-' . substr( $ts, 4, 2 ) . '-' . substr( $ts, 6, 2 ),
			'url'   => 'https://web.archive.org/web/*/' . rawurlencode( $name ),
		);
	}
}

if ( ! function_exists( 'db_lk_rank' ) ) {
	/** @return array<string,mixed> */
	function db_lk_rank( $name ) {
		$r = db_lk_http( 'https://tranco-list.eu/api/ranks/domain/' . rawurlencode( $name ) );
		if ( 200 !== $r['code'] ) {
			return array( 'state' => 'unavailable' );
		}
		$ranks = (array) ( $r['json']['ranks'] ?? array() );
		return empty( $ranks ) ? array( 'state' => 'none' ) : array( 'state' => 'ok', 'rank' => (int) ( $ranks[0]['rank'] ?? 0 ), 'date' => sanitize_text_field( (string) ( $ranks[0]['date'] ?? '' ) ) );
	}
}

if ( ! function_exists( 'db_lk_ssl' ) ) {
	/** Certificate presented on port 443: issuer, validity and days left. @return array<string,mixed> */
	function db_lk_ssl( $name ) {
		// Never connect to a name that points inside a private or reserved network.
		$ip = gethostbyname( $name );
		if ( $ip === $name || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return array( 'state' => 'none' );
		}
		$ctx = stream_context_create( array( 'ssl' => array( 'capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $name ) ) );
		$fp  = @stream_socket_client( 'ssl://' . $ip . ':443', $errno, $errstr, 4, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $fp ) {
			return array( 'state' => 'none' );
		}
		$params = stream_context_get_params( $fp );
		fclose( $fp );
		$cert = isset( $params['options']['ssl']['peer_certificate'] ) ? openssl_x509_parse( $params['options']['ssl']['peer_certificate'] ) : false;
		if ( ! is_array( $cert ) ) {
			return array( 'state' => 'none' );
		}
		$to   = (int) ( $cert['validTo_time_t'] ?? 0 );
		$from = (int) ( $cert['validFrom_time_t'] ?? 0 );
		$san  = array();
		foreach ( explode( ',', (string) ( $cert['extensions']['subjectAltName'] ?? '' ) ) as $part ) {
			if ( 0 === strpos( trim( $part ), 'DNS:' ) ) {
				$san[] = sanitize_text_field( substr( trim( $part ), 4 ) );
			}
		}
		$covers = false;
		foreach ( array_merge( $san, array( (string) ( $cert['subject']['CN'] ?? '' ) ) ) as $n ) {
			$n = strtolower( $n );
			if ( $n === $name || ( 0 === strpos( $n, '*.' ) && substr( $name, strpos( $name, '.' ) ) === substr( $n, 1 ) ) ) {
				$covers = true;
			}
		}
		return array(
			'state'  => 'ok',
			'issuer' => sanitize_text_field( (string) ( $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? '' ) ),
			'from'   => $from ? gmdate( 'Y-m-d', $from ) : '',
			'to'     => $to ? gmdate( 'Y-m-d', $to ) : '',
			'days'   => $to ? (int) floor( ( $to - time() ) / DAY_IN_SECONDS ) : null,
			'covers' => $covers,
			'names'  => count( $san ),
		);
	}
}

if ( ! function_exists( 'db_lk_site' ) ) {
	/**
	 * How the website answers: the redirect chain, final status, response headers and security headers.
	 * Each hop is fetched with wp_safe_remote_get, which refuses private and reserved addresses.
	 *
	 * @return array<string,mixed>
	 */
	function db_lk_site( $name ) {
		$ua   = 'DomainBrothers-DomainTools/1.0 (+https://www.domainbrothers.com)';
		$url  = 'https://' . $name . '/';
		$hops = array();
		$res  = null;
		for ( $i = 0; $i < 6; $i++ ) {
			$res = wp_safe_remote_get( $url, array( 'timeout' => 6, 'redirection' => 0, 'limit_response_size' => 20000, 'user-agent' => $ua ) );
			if ( is_wp_error( $res ) && 0 === $i ) {
				$url = 'http://' . $name . '/';
				$res = wp_safe_remote_get( $url, array( 'timeout' => 6, 'redirection' => 0, 'limit_response_size' => 20000, 'user-agent' => $ua ) );
			}
			if ( is_wp_error( $res ) ) {
				break;
			}
			$code   = (int) wp_remote_retrieve_response_code( $res );
			$hops[] = array( 'url' => esc_url_raw( $url ), 'code' => $code );
			$loc    = (string) wp_remote_retrieve_header( $res, 'location' );
			if ( $code >= 300 && $code < 400 && '' !== $loc ) {
				$url = WP_Http::make_absolute_url( $loc, $url );
				continue;
			}
			break;
		}
		if ( ! $hops || ! $res || is_wp_error( $res ) && ! $hops ) {
			return array( 'state' => 'none' );
		}
		$last  = end( $hops );
		$h     = is_wp_error( $res ) ? array() : wp_remote_retrieve_headers( $res );
		$body  = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		$title = '';
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $m ) ) {
			$title = trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) ) );
			$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 120 ) : substr( $title, 0, 120 );
		}
		$sec = array();
		foreach ( array( 'strict-transport-security' => 'Strict-Transport-Security', 'content-security-policy' => 'Content-Security-Policy', 'x-frame-options' => 'X-Frame-Options', 'x-content-type-options' => 'X-Content-Type-Options', 'referrer-policy' => 'Referrer-Policy', 'permissions-policy' => 'Permissions-Policy' ) as $k => $label ) {
			$sec[ $label ] = isset( $h[ $k ] );
		}
		$shown = array();
		foreach ( array( 'content-type', 'server', 'date', 'cache-control', 'content-encoding', 'content-length', 'last-modified', 'etag', 'vary', 'x-powered-by', 'strict-transport-security', 'content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'location' ) as $k ) {
			if ( isset( $h[ $k ] ) ) {
				$v            = is_array( $h[ $k ] ) ? implode( ', ', $h[ $k ] ) : (string) $h[ $k ];
				$shown[ $k ] = sanitize_text_field( function_exists( 'mb_substr' ) ? mb_substr( $v, 0, 180 ) : substr( $v, 0, 180 ) );
			}
		}
		return array(
			'state'   => 'ok',
			'code'    => (int) $last['code'],
			'https'   => 0 === strpos( $last['url'], 'https://' ),
			'final'   => $last['url'],
			'hops'    => $hops,
			'server'  => sanitize_text_field( (string) ( $h['server'] ?? '' ) ),
			'title'   => sanitize_text_field( $title ),
			'secure'  => $sec,
			'headers' => $shown,
		);
	}
}

if ( ! function_exists( 'db_lk_mailauth' ) ) {
	/** DMARC policy from the _dmarc record. @return array<string,mixed> */
	function db_lk_mailauth( $name ) {
		$r = db_lk_http( 'https://cloudflare-dns.com/dns-query?name=' . rawurlencode( '_dmarc.' . $name ) . '&type=TXT', array( 'Accept' => 'application/dns-json' ) );
		foreach ( (array) ( $r['json']['Answer'] ?? array() ) as $a ) {
			$t = trim( (string) ( $a['data'] ?? '' ), '" ' );
			if ( 0 === stripos( $t, 'v=DMARC1' ) ) {
				return array( 'state' => 'ok', 'policy' => preg_match( '/\bp=(none|quarantine|reject)/i', $t, $m ) ? strtolower( $m[1] ) : 'unknown' );
			}
		}
		return array( 'state' => 'none' );
	}
}

if ( ! function_exists( 'db_lk_host' ) ) {
	/** Network that owns the website address, from the public RDAP IP record. @return array<string,mixed> */
	function db_lk_host( array $dns ) {
		$ip = $dns['A'][0] ?? '';
		if ( '' === $ip || ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return array( 'state' => 'none' );
		}
		$r = db_lk_http( 'https://rdap.org/ip/' . rawurlencode( $ip ) );
		if ( 200 !== $r['code'] || ! $r['json'] ) {
			return array( 'state' => 'unavailable', 'ip' => $ip );
		}
		return array( 'state' => 'ok', 'ip' => $ip, 'network' => sanitize_text_field( (string) ( $r['json']['name'] ?? '' ) ), 'country' => sanitize_text_field( (string) ( $r['json']['country'] ?? '' ) ) );
	}
}

if ( ! function_exists( 'db_lk_name' ) ) {
	/** Plain facts about the name itself. @return array<string,mixed> */
	function db_lk_name( $name ) {
		list( $label, $ext ) = db_lk_split( $name );
		$core   = str_replace( '-', '', $label );
		$vowels = preg_match_all( '/[aeiouy]/i', $core );
		$run    = preg_match( '/[^aeiouy0-9]{5,}/i', $core );
		return array(
			'label'     => $label,
			'length'    => strlen( $label ),
			'extension' => $ext,
			'hyphens'   => substr_count( $label, '-' ),
			'digits'    => preg_match_all( '/[0-9]/', $label ),
			'idn'       => 0 === strpos( $label, 'xn--' ),
			'readable'  => strlen( $core ) > 0 && $vowels > 0 && ! $run,
			'type'      => ctype_digit( $core ) ? 'numeric' : ( ctype_alpha( $core ) ? 'letters only' : 'letters and numbers' ),
		);
	}
}

if ( ! function_exists( 'db_lk_split' ) ) {
	/** @return array{0:string,1:string} label and extension. */
	function db_lk_split( $name ) {
		$dot = strpos( $name, '.' );
		return array( substr( $name, 0, $dot ), substr( $name, $dot + 1 ) );
	}
}

if ( ! function_exists( 'db_lk_index' ) ) {
	/**
	 * Recorded sale prices and current asking prices, by length and extension.
	 * Legacy sales with no recorded price are left out. Cached 12 hours.
	 *
	 * @return array{sold:array,ask:array}
	 */
	function db_lk_index() {
		$idx = get_transient( 'db_lk_index' );
		if ( is_array( $idx ) && isset( $idx['sold'], $idx['ask'] ) ) {
			return $idx;
		}
		$add = static function ( $title, $price ) {
			$n = strtolower( trim( (string) $title ) );
			if ( $price > 0 && false !== strpos( $n, '.' ) ) {
				list( $l, $x ) = db_lk_split( $n );
				return array( 'len' => strlen( $l ), 'ext' => $x, 'price' => (float) $price );
			}
			return null;
		};
		$idx = array( 'sold' => array(), 'ask' => array() );
		if ( function_exists( 'db_sold_listings' ) && function_exists( 'db_sold_info' ) ) {
			foreach ( db_sold_listings() as $p ) {
				$row = $add( $p->post_title, (float) db_sold_info( $p )['price'] );
				if ( $row ) {
					$idx['sold'][] = $row;
				}
			}
		}
		$q = new WP_Query( array( 'post_type' => 'domain', 'post_status' => 'publish', 'posts_per_page' => 500, 'no_found_rows' => true, 'fields' => 'ids' ) );
		foreach ( $q->posts as $id ) {
			if ( function_exists( 'db_listing_state' ) && 'sold' === db_listing_state( get_post( $id ) ) ) {
				continue;
			}
			$row = $add( get_the_title( $id ), (float) preg_replace( '/[^0-9.]/', '', (string) get_post_meta( $id, 'domain_price', true ) ) );
			if ( $row ) {
				$idx['ask'][] = $row;
			}
		}
		set_transient( 'db_lk_index', $idx, 12 * HOUR_IN_SECONDS );
		return $idx;
	}
}

if ( ! function_exists( 'db_lk_stats' ) ) {
	/**
	 * Median and range of the closest group of at least three rows.
	 *
	 * @param array  $rows Index rows.
	 * @param string $name Domain name being looked up.
	 * @return array<string,mixed>
	 */
	function db_lk_stats( array $rows, $name ) {
		list( $label, $ext ) = db_lk_split( $name );
		$len                 = strlen( $label );
		$sets                = array(
			array( 'same length and extension (' . $len . ' characters, .' . $ext . ')', static function ( $s ) use ( $len, $ext ) { return $s['len'] === $len && $s['ext'] === $ext; } ),
			array( 'same extension (.' . $ext . ')', static function ( $s ) use ( $ext ) { return $s['ext'] === $ext; } ),
			array( 'same length (' . $len . ' characters)', static function ( $s ) use ( $len ) { return $s['len'] === $len; } ),
		);
		foreach ( $sets as $set ) {
			$prices = array_values( array_map( static function ( $s ) { return $s['price']; }, array_filter( $rows, $set[1] ) ) );
			if ( count( $prices ) >= 3 ) {
				sort( $prices );
				$mid = (int) floor( count( $prices ) / 2 );
				return array( 'state' => 'ok', 'basis' => $set[0], 'count' => count( $prices ), 'median' => count( $prices ) % 2 ? $prices[ $mid ] : ( $prices[ $mid - 1 ] + $prices[ $mid ] ) / 2, 'low' => $prices[0], 'high' => end( $prices ) );
			}
		}
		return array( 'state' => 'none' );
	}
}

if ( ! function_exists( 'db_lk_comparables' ) ) {
	/** @return array{sold:array,ask:array} */
	function db_lk_comparables( $name ) {
		$idx = db_lk_index();
		return array( 'sold' => db_lk_stats( $idx['sold'], $name ), 'ask' => db_lk_stats( $idx['ask'], $name ) );
	}
}

if ( ! function_exists( 'db_lk_estimate' ) ) {
	/**
	 * Optional computer estimate; only when a Replicate token is saved.
	 *
	 * @return array<string,mixed>|null
	 */
	function db_lk_estimate( $name ) {
		$token = (string) get_option( 'db_replicate_token', '' );
		if ( '' === $token ) {
			return null;
		}
		$res = wp_remote_post( 'https://api.replicate.com/v1/predictions', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Prefer' => 'wait' ),
			'body'    => wp_json_encode( array( 'version' => 'a925db842c707850e4ca7b7e86b217692b0353a9ca05eb028802c4a85db93843', 'input' => array( 'domains' => $name ) ) ),
		) );
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) >= 300 ) {
			error_log( 'DB lookup: estimate provider failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return array( 'state' => 'unavailable' );
		}
		$v = json_decode( (string) wp_remote_retrieve_body( $res ), true )['output']['valuations'][0] ?? null;
		if ( ! is_array( $v ) || ! empty( $v['error'] ) ) {
			return array( 'state' => 'unavailable' );
		}
		return array( 'state' => 'ok', 'auction' => (float) ( $v['auction'] ?? 0 ), 'marketplace' => (float) ( $v['marketplace'] ?? 0 ), 'brokerage' => (float) ( $v['brokerage'] ?? 0 ) );
	}
}

if ( ! function_exists( 'db_lk_parts' ) ) {
	/** Every part a lookup can return. */
	function db_lk_parts() {
		return array( 'registry', 'dns', 'ssl', 'site', 'dmarc', 'host', 'archive', 'rank', 'comparables', 'estimate' );
	}
}

if ( ! function_exists( 'db_lk_collect' ) ) {
	/**
	 * The lookup result for a name, computing only the requested parts that are not cached yet.
	 * Parts that were not requested come back in their empty shape so the page script never breaks.
	 *
	 * @param string   $name  Validated domain name.
	 * @param string[] $parts Requested parts (empty = all).
	 * @return array<string,mixed>
	 */
	function db_lk_collect( $name, array $parts ) {
		$all   = db_lk_parts();
		$parts = $parts ? array_values( array_intersect( $all, $parts ) ) : $all;
		if ( in_array( 'host', $parts, true ) && ! in_array( 'dns', $parts, true ) ) {
			$parts[] = 'dns';
		}
		$key  = 'db_lk3_' . md5( $name );
		$data = get_transient( $key );
		$data = is_array( $data ) ? $data : array( 'domain' => $name, 'done' => array() );
		foreach ( $parts as $part ) {
			if ( in_array( $part, (array) $data['done'], true ) ) {
				continue;
			}
			switch ( $part ) {
				case 'registry':
					$data['registry'] = db_lk_registry( $name );
					break;
				case 'dns':
					$data['dns'] = db_lk_dns( $name );
					break;
				case 'ssl':
					$data['ssl'] = db_lk_ssl( $name );
					break;
				case 'site':
					$data['site'] = db_lk_site( $name );
					break;
				case 'dmarc':
					$data['dmarc'] = db_lk_mailauth( $name );
					break;
				case 'host':
					$data['host'] = db_lk_host( $data['dns'] ?? db_lk_dns( $name ) );
					break;
				case 'archive':
					$data['archive'] = db_lk_archive( $name );
					break;
				case 'rank':
					$data['rank'] = db_lk_rank( $name );
					break;
				case 'comparables':
					$data['comparables'] = db_lk_comparables( $name );
					break;
				case 'estimate':
					$data['estimate'] = db_lk_estimate( $name );
					break;
			}
			$data['done'][] = $part;
		}
		$data['checked'] = gmdate( 'Y-m-d H:i' ) . ' UTC';
		set_transient( $key, $data, 6 * HOUR_IN_SECONDS );
		$empty = array(
			'registry'    => array( 'state' => 'skipped' ),
			'dns'         => array(),
			'ssl'         => array( 'state' => 'skipped' ),
			'site'        => array( 'state' => 'skipped' ),
			'dmarc'       => array( 'state' => 'skipped' ),
			'host'        => array( 'state' => 'skipped' ),
			'archive'     => array( 'state' => 'skipped' ),
			'rank'        => array( 'state' => 'skipped' ),
			'comparables' => array( 'sold' => array( 'state' => 'none' ), 'ask' => array( 'state' => 'none' ) ),
			'estimate'    => null,
		);
		$out         = array_merge( $empty, array_intersect_key( $data, $empty ) );
		$out['domain']  = $name;
		$out['checked'] = $data['checked'];
		$out['name']    = db_lk_name( $name );
		return $out;
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/domain-lookup', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => array( 'd' => array( 'required' => true, 'type' => 'string' ), 'parts' => array( 'required' => false, 'type' => 'string' ) ),
		'callback'            => function ( WP_REST_Request $req ) {
			$raw = mb_strtolower( trim( (string) $req->get_param( 'd' ) ) );
			$raw = preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $raw );        // scheme
			$raw = preg_replace( '#[/?\#].*$#s', '', $raw );                 // path, query, fragment
			$raw = preg_replace( '#:\d+$#', '', $raw );                      // port
			$raw = rtrim( preg_replace( '#^www\.#', '', $raw ), '.' );       // www and trailing dot
			$send = static function ( $data, $code = 200 ) {
				$res = new WP_REST_Response( $data, $code );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			};
			if ( preg_match( '/[^\x20-\x7e]/', $raw ) ) {
				// Names with accents or non-Latin letters are looked up in their punycode (xn--) form.
				$ascii = function_exists( 'idn_to_ascii' ) ? idn_to_ascii( $raw, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 ) : false;
				if ( ! is_string( $ascii ) || '' === $ascii ) {
					return $send( array( 'error' => 'Enter names with accents or non-Latin letters in their xn-- (punycode) form.' ), 400 );
				}
				$raw = $ascii;
			}
			$name = function_exists( 'db_tools_valid_domain' ) ? db_tools_valid_domain( $raw ) : '';
			if ( '' === $name && preg_match( '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+xn--[a-z0-9-]{1,59}$/', $raw ) ) {
				$name = $raw; // punycode extension, e.g. .xn--p1ai
			}
			if ( '' === $name ) {
				return $send( array( 'error' => 'Enter a domain name such as example.com.' ), 400 );
			}
			$want  = array_filter( array_map( 'sanitize_key', explode( ',', (string) $req->get_param( 'parts' ) ) ) );
			$bad   = array_diff( $want, db_lk_parts() );
			if ( $bad ) {
				return $send( array( 'error' => 'Unknown parts: ' . implode( ', ', $bad ) . '. Use any of: ' . implode( ', ', db_lk_parts() ) . '.' ), 400 );
			}
			$ip = function_exists( 'db_wl_ip' ) ? db_wl_ip() : '';
			if ( function_exists( 'db_wl_allow' ) && ! db_wl_allow( 'lookup|' . $ip, 20, 10 * MINUTE_IN_SECONDS ) ) {
				return $send( array( 'error' => 'Too many lookups. Please wait a few minutes and try again.' ), 429 );
			}
			$data  = db_lk_collect( $name, $want );
			return $send( $data );
		},
	) );
} );

if ( ! function_exists( 'db_lk_extensions' ) ) {
	/**
	 * Registered or not, for one name across popular extensions, in parallel.
	 * An RDAP 404 means no registration record; anything else unclear is "unknown".
	 *
	 * @return array<int,array{ext:string,state:string}>
	 */
	function db_lk_extensions( $label ) {
		$exts     = array( 'com', 'net', 'org', 'ca', 'io', 'co', 'ai', 'app', 'dev', 'info' );
		$requests = array();
		foreach ( $exts as $x ) {
			$requests[ $x ] = array( 'url' => 'https://rdap.org/domain/' . rawurlencode( $label . '.' . $x ), 'type' => 'GET', 'headers' => array( 'Accept' => 'application/json' ) );
		}
		$out = array();
		if ( class_exists( '\\WpOrg\\Requests\\Requests' ) ) {
			$res = \WpOrg\Requests\Requests::request_multiple( $requests, array( 'timeout' => 8, 'follow_redirects' => true, 'redirects' => 4 ) );
			foreach ( $exts as $x ) {
				$r     = $res[ $x ] ?? null;
				$code  = ( is_object( $r ) && isset( $r->status_code ) ) ? (int) $r->status_code : 0;
				$out[] = array( 'ext' => $x, 'state' => 200 === $code ? 'taken' : ( 404 === $code && ! db_lk_rdap_missing( is_object( $r ) ? $r->body : '' ) ? 'free' : 'unknown' ) );
			}
			// Some registries drop a connection now and then; try each unclear one once more (at most three).
			$tries = 0;
			foreach ( $out as $k => $row ) {
				if ( 'unknown' === $row['state'] && $tries < 3 ) {
					++$tries;
					$r = db_lk_http( 'https://rdap.org/domain/' . rawurlencode( $label . '.' . $row['ext'] ) );
					if ( 200 === $r['code'] ) {
						$out[ $k ]['state'] = 'taken';
					} elseif ( 404 === $r['code'] && ! db_lk_rdap_missing( $r['json'] ) ) {
						$out[ $k ]['state'] = 'free';
					}
				}
			}
			return $out;
		}
		foreach ( $exts as $x ) {
			$r     = db_lk_http( $requests[ $x ]['url'] );
			$out[] = array( 'ext' => $x, 'state' => 200 === $r['code'] ? 'taken' : ( 404 === $r['code'] && ! db_lk_rdap_missing( $r['json'] ) ? 'free' : 'unknown' ) );
		}
		return $out;
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/domain-extensions', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => array( 'd' => array( 'required' => true, 'type' => 'string' ) ),
		'callback'            => function ( WP_REST_Request $req ) {
			$raw   = strtolower( trim( (string) $req->get_param( 'd' ) ) );
			$label = preg_replace( '#^https?://#', '', $raw );
			$label = explode( '.', explode( '/', preg_replace( '#^www\.#', '', $label ) )[0] )[0];
			$send  = static function ( $data, $code = 200 ) {
				$res = new WP_REST_Response( $data, $code );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			};
			if ( ! preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label ) ) {
				return $send( array( 'error' => 'Enter a name such as example.' ), 400 );
			}
			$ip = function_exists( 'db_wl_ip' ) ? db_wl_ip() : '';
			if ( function_exists( 'db_wl_allow' ) && ! db_wl_allow( 'lookup-ext|' . $ip, 6, 10 * MINUTE_IN_SECONDS ) ) {
				return $send( array( 'error' => 'Too many checks. Please wait a few minutes and try again.' ), 429 );
			}
			$key  = 'db_lk_ext_' . md5( $label );
			$data = get_transient( $key );
			if ( ! is_array( $data ) ) {
				$data = array( 'label' => $label, 'results' => db_lk_extensions( $label ) );
				set_transient( $key, $data, 2 * HOUR_IN_SECONDS );
			}
			return $send( $data );
		},
	) );
} );

if ( ! function_exists( 'db_lk_body' ) ) {
	/** The page body: lookup form, result area and an explanation of each source. */
	function db_lk_body() {
		$u    = static function ( $p ) { return esc_url( home_url( $p ) ); };
		$h    = '<p class="db-sales-lead">Look up any domain name with free public data: who registered it and when, where it points, whether the web remembers it, and what similar names have sold for.</p>';
		$h   .= db_lk_widget();
		$h   .= (string) apply_filters( 'db_lk_hub_tools', '' );
		$h   .= '<div class="dblk">';
		$h   .= '<section class="dblk-src"><h2>Where the information comes from</h2><ul>'
			. '<li><strong>Registry record.</strong> The public RDAP record held by the domain\'s registry: registration and expiry dates, registrar, status and name servers.</li>'
			. '<li><strong>DNS.</strong> Live records from Cloudflare\'s public resolver: website address, mail servers and name servers.</li>'
			. '<li><strong>Web history.</strong> Whether the Internet Archive\'s Wayback Machine has captured the site, and when it last did.</li>'
			. '<li><strong>SSL certificate.</strong> The certificate the site presents on port 443: issuer, validity and whether it covers the name.</li>'
			. '<li><strong>Website response.</strong> How the site answers a request: status, redirects, server and security headers.</li>'
			. '<li><strong>Email security and hosting.</strong> SPF and DMARC records, and the network that owns the site\'s address.</li>'
			. '<li><strong>Other extensions.</strong> Whether a name has a registration record under popular extensions (RDAP).</li>'
			. '<li><strong>Popularity.</strong> The name\'s position in the Tranco list of the top one million websites, when it appears.</li>'
			. '<li><strong>Comparable sales.</strong> Recorded sale prices of similar names Domain Brothers has sold, and the asking prices of similar names listed now.</li></ul>'
			. '<p>This is information, not an appraisal or advice. What a name sells for depends on the buyer, the seller and the moment. Looking for a name? <a href="' . $u( '/all-domains/' ) . '">Browse all domains</a>, try the <a href="' . $u( '/find-a-domain/' ) . '">domain finder</a>, see <a href="' . $u( '/sold/' ) . '">what has sold</a> or use the <a href="' . $u( '/payment-terms/' ) . '">payment plan calculator</a>.</p>'
			. '<p><a class="dblk-cta" href="' . $u( '/all-domains/' ) . '">Buy a premium domain now</a></p></section></div>';
		return $h;
	}
}

if ( ! function_exists( 'db_lk_css' ) ) {
	function db_lk_css() {
		return '.dblk{--navy:#0f1b2d;--lime:#b5e61d;--soft:#eef8d2;--line:#e4e7ec;--panel:#f1f3f6;--ink:#0f172a;--text:#374151;max-width:1000px;margin:0 auto;padding:8px 20px 56px;color:var(--text)}'
			. '.dblk *{box-sizing:border-box}.dblk label{display:block;margin:0 0 8px;font-weight:700;color:var(--ink)}'
			. '.dblk-row{display:flex;gap:10px}.dblk-row input{flex:1;min-width:0;padding:15px 16px;border:1px solid #cfd5de;border-radius:12px;font-size:18px;background:#fff;color:var(--ink)}'
			. '.dblk-row input:focus{outline:3px solid rgba(181,230,29,.55);border-color:var(--navy)}'
			. '.dblk-row button,html body .dblk .dblk-cta{display:inline-flex;align-items:center;justify-content:center;padding:15px 24px;border:0;border-radius:12px;background:var(--navy);color:#fff!important;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none}'
			. '.dblk-row button:hover,html body .dblk .dblk-cta:hover{background:#1b2d49}.dblk-row button[disabled]{opacity:.6;cursor:wait}'
			. '.dblk-hint{margin:8px 0 0;font-size:14px;color:#5a6475}.dblk-card .dblk-sec{margin-top:12px;padding:11px 18px;border:1px solid var(--navy);border-radius:10px;background:#fff;color:var(--navy);font-size:15px;font-weight:700;cursor:pointer}.dblk-card .dblk-sec:hover{background:var(--soft)}.dblk-pill.dblk-taken{background:#eceff3;color:#4b5563}.dblk-pill.dblk-unknown{background:#fff4d6;color:#7a5a00}'
			. '.dblk-out{margin:28px 0 0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.dblk-out:empty{display:none}'
			. '.dblk-card{padding:20px;border:1px solid var(--line);border-radius:16px;background:#fff}.dblk-card.is-wide{grid-column:1/-1}'
			. '.dblk-card h3{margin:0 0 12px;font-size:17px;color:var(--ink)}.dblk-card dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:8px 16px;font-size:15px}'
			. '.dblk-card dt{color:#5a6475}.dblk-card dd{margin:0;color:var(--ink);font-weight:600;overflow-wrap:anywhere}.dblk-card p{margin:0;font-size:15px;line-height:1.55}'
			. '.dblk-pill{display:inline-block;margin:0 6px 6px 0;padding:3px 10px;border-radius:999px;background:var(--soft);color:#3f5a0a;font-size:13px;font-weight:600}'
			. '.dblk-head{grid-column:1/-1;padding:20px 22px;border-radius:16px;background:var(--navy);color:#fff}.dblk-head strong{display:block;font-size:24px;overflow-wrap:anywhere}.dblk-head span{color:#c7d2e0;font-size:14px}'
			. '.dblk-err{grid-column:1/-1;padding:16px 18px;border-radius:12px;background:#fdeceb;color:#8a1c14}'
			. '.dblk-src{margin:44px 0 0;padding:26px;border-radius:16px;background:var(--panel)}.dblk-src h2{margin:0 0 12px;font-size:22px;color:var(--ink)}.dblk-src ul{margin:0 0 14px;padding-left:20px}.dblk-src li{margin:0 0 8px;line-height:1.55}'
			. '.dblk-src a:not(.dblk-cta){color:var(--navy);font-weight:600;text-decoration:underline}'
			. '.dblk-try{margin:14px 0 0;display:flex;flex-wrap:wrap;align-items:center;gap:8px;font-size:14px;color:#5a6475}.dblk-try a{display:inline-flex;align-items:center;min-height:44px;padding:0 14px;border:1px solid #cfd5de;border-radius:999px;background:#fff;color:var(--navy);font-weight:600;text-decoration:none;transition:background .15s,border-color .15s}.dblk-try a:hover,.dblk-try a:focus-visible{background:var(--soft);border-color:var(--navy)}'
			. '@media (max-width:700px){.dblk-out{grid-template-columns:minmax(0,1fr)}.dblk-row{flex-direction:column}.dblk-row button{width:100%}}';
	}
}

if ( ! function_exists( 'db_lk_js' ) ) {
	/**
	 * Client script: config first, then assets/domain-lookup.js (cards are built with textContent only).
	 *
	 * @param array<string,mixed> $cfg Keys: show (card titles; empty = all), parts (lookups to run), autoext, button.
	 * @return string
	 */
	function db_lk_js( array $cfg = array() ) {
		$cfg += array( 'show' => array(), 'parts' => array(), 'autoext' => false, 'button' => 'Look up' );
		$cfg['ep']  = esc_url_raw( rest_url( 'db/v1/domain-lookup' ) );
		$cfg['ext'] = esc_url_raw( rest_url( 'db/v1/domain-extensions' ) );
		$file       = __DIR__ . '/assets/domain-lookup.js';
		$js         = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return 'window.DBLK=' . wp_json_encode( $cfg ) . ';' . $js;
	}
}

if ( ! function_exists( 'db_lk_chips_html' ) ) {
	/**
	 * "Try an example" links under the lookup box. Each is a plain link: the page script runs a
	 * lookup for ?d=name, so no extra JavaScript is needed. Names that are not plain domains are skipped. Pure.
	 *
	 * @param string[] $names Domain names.
	 */
	function db_lk_chips_html( array $names ) {
		$links = '';
		foreach ( $names as $n ) {
			$n = strtolower( trim( (string) $n ) );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9\-]{0,60}(\.[a-z0-9\-]{2,24}){1,2}$/', $n ) ) {
				continue;
			}
			$links .= '<a href="?d=' . rawurlencode( $n ) . '">' . htmlspecialchars( $n, ENT_QUOTES ) . '</a>';
		}
		return '' === $links ? '' : '<p class="dblk-try"><span>Try an example:</span>' . $links . '</p>';
	}
}

if ( ! function_exists( 'db_lk_widget' ) ) {
	/**
	 * The lookup form, result area and script.
	 *
	 * @param array<string,mixed> $cfg    See db_lk_js().
	 * @param string              $label  Field label.
	 * @param string              $hint   Placeholder text.
	 */
	function db_lk_widget( array $cfg = array(), $label = 'Domain name', $hint = 'example.com' ) {
		$cfg += array( 'button' => 'Look up' );
		return '<style>' . db_lk_css() . '</style><div class="dblk" id="dblk">'
			. '<form class="dblk-form" id="dblk-form" novalidate><label for="dblk-in">' . esc_html( $label ) . '</label><div class="dblk-row"><input id="dblk-in" name="d" type="text" inputmode="url" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="' . esc_attr( $hint ) . '" required><button type="submit">' . esc_html( $cfg['button'] ) . '</button></div><p class="dblk-hint">Free. Results come from public sources and are cached for a few hours.</p>' . db_lk_chips_html( (array) ( $cfg['chips'] ?? array( 'google.com', 'wikipedia.org', 'domainbrothers.com' ) ) ) . '</form>'
			. '<div id="dblk-out" class="dblk-out" aria-live="polite"></div></div>'
			. '<script data-no-delay>' . db_lk_js( $cfg ) . '</script>';
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) || 'domain-tools' !== db_sales_request_path() ) {
		return;
	}
	// Title, description and Open Graph come from db_tp_route_meta() (db-tool-pages-block.php).
	add_action( 'wp_head', static function () {
		echo '<link rel="canonical" href="' . esc_url( home_url( '/domain-tools/' ) ) . '">' . "\n";
	}, 1 );
	db_sales_render( 'Free domain tools', 'Domain tools', db_lk_body() );
}, 11 );
