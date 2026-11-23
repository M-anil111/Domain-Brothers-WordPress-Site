<?php
/**
 * Two more free tools for the /domain-tools/ pages:
 *
 *  - DNS by resolver: asks Cloudflare, Google and AdGuard public DNS (over HTTPS) the same
 *    question, so a visitor can compare answers (propagation) or query one chosen server
 *    ("nslookup"). GET /wp-json/db/v1/domain-resolve?d=name&type=A&resolver=all
 *  - Certificate decoder: reads a pasted PEM certificate and lists its subject, issuer,
 *    dates, names and key. POST /wp-json/db/v1/cert-decode. Nothing is stored, a private key
 *    is refused, and the size is limited.
 *
 * Visitor input is validated as a plain host name and a fixed record type, and only goes into
 * the query string of fixed provider addresses. Each visitor is rate limited.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_rs_clean_data' ) ) {
	/**
	 * One DNS answer as text. TXT data arrives as quoted strings ("v=spf1 " "-all"): the parts are
	 * joined without quotes. Other types keep their data as given, so a CAA answer keeps both
	 * quotes (0 issue "letsencrypt.org"); trimming quotes off the whole string left one (TL-21).
	 */
	function db_rs_clean_data( $data, $type_num ) {
		$data = trim( (string) $data );
		if ( 16 !== (int) $type_num ) {
			return $data;
		}
		if ( preg_match_all( '/"((?:[^"\\\\]|\\\\.)*)"/', $data, $m ) && $m[1] ) {
			return stripslashes( implode( '', $m[1] ) );
		}
		return trim( $data, '"' );
	}
}

if ( ! function_exists( 'db_rs_resolvers' ) ) {
	/** @return array<string,array{label:string,url:string,accept:string}> */
	function db_rs_resolvers() {
		return array(
			'cloudflare' => array( 'label' => 'Cloudflare (1.1.1.1)', 'url' => 'https://cloudflare-dns.com/dns-query', 'accept' => 'application/dns-json' ),
			'google'     => array( 'label' => 'Google (8.8.8.8)', 'url' => 'https://dns.google/resolve', 'accept' => 'application/dns-json' ),
			'adguard'    => array( 'label' => 'AdGuard (94.140.14.14)', 'url' => 'https://dns.adguard-dns.com/resolve', 'accept' => 'application/dns-json' ),
		);
	}
}

if ( ! function_exists( 'db_rs_types' ) ) {
	/** Record type name => DNS type number. */
	function db_rs_types() {
		return array( 'A' => 1, 'AAAA' => 28, 'CNAME' => 5, 'MX' => 15, 'NS' => 2, 'TXT' => 16, 'SOA' => 6, 'CAA' => 257 );
	}
}

if ( ! function_exists( 'db_rs_parse' ) ) {
	/**
	 * Reduces a DNS-over-HTTPS JSON answer to what the page shows.
	 *
	 * @param array|null $json Decoded answer.
	 * @param string     $type Record type asked for.
	 * @return array{ok:bool,status:string,answers:string[],ttl:int|null}
	 */
	function db_rs_parse( $json, $type ) {
		$want = db_rs_types();
		if ( ! is_array( $json ) || ! isset( $want[ $type ] ) ) {
			return array( 'ok' => false, 'status' => 'No answer', 'answers' => array(), 'ttl' => null );
		}
		$names   = array( 0 => 'OK', 1 => 'Format error', 2 => 'Server failure', 3 => 'Name does not exist', 4 => 'Not implemented', 5 => 'Refused' );
		$rcode   = (int) ( isset( $json['Status'] ) ? $json['Status'] : -1 );
		$answers = array();
		$ttl     = null;
		foreach ( (array) ( isset( $json['Answer'] ) ? $json['Answer'] : array() ) as $a ) {
			if ( (int) ( isset( $a['type'] ) ? $a['type'] : 0 ) !== $want[ $type ] || ! isset( $a['data'] ) ) {
				continue; // skips the CNAME chain that comes before the final answer.
			}
			$answers[] = substr( db_rs_clean_data( (string) $a['data'], $want[ $type ] ), 0, 255 );
			$ttl       = null === $ttl ? (int) ( isset( $a['TTL'] ) ? $a['TTL'] : 0 ) : min( $ttl, (int) ( isset( $a['TTL'] ) ? $a['TTL'] : 0 ) );
		}
		// Compared in full and normalised (order, case, trailing dot); cut only for display (TL-5).
		$all = array();
		foreach ( (array) ( isset( $json['Answer'] ) ? $json['Answer'] : array() ) as $a ) {
			if ( (int) ( isset( $a['type'] ) ? $a['type'] : 0 ) === $want[ $type ] && isset( $a['data'] ) ) {
				$all[] = rtrim( strtolower( db_rs_clean_data( (string) $a['data'], $want[ $type ] ) ), '.' );
			}
		}
		sort( $all );
		$answers = array_slice( $answers, 0, 12 );
		return array( 'ok' => 0 === $rcode, 'rcode' => $rcode, 'status' => isset( $names[ $rcode ] ) ? $names[ $rcode ] : 'Error ' . $rcode, 'answers' => $answers, 'all' => array_values( array_unique( $all ) ), 'ttl' => $ttl );
	}
}

if ( ! function_exists( 'db_rs_summary' ) ) {
	/**
	 * Whether the resolvers agree.
	 *
	 * @param array<string,array{ok:bool,status:string,answers:string[],ttl:int|null}> $results Per-resolver parsed answers.
	 * @return array{agree:bool,text:string}
	 */
	function db_rs_summary( array $results ) {
		$sets = array();
		$nx   = 0;
		$fail = 0;
		$ttl  = null;
		foreach ( $results as $r ) {
			if ( ! empty( $r['ok'] ) ) {
				$a = isset( $r['all'] ) ? (array) $r['all'] : (array) $r['answers'];
				sort( $a );
				$sets[] = implode( '|', $a );
				if ( null !== ( $r['ttl'] ?? null ) ) {
					$ttl = null === $ttl ? (int) $r['ttl'] : max( $ttl, (int) $r['ttl'] );
				}
			} elseif ( 3 === (int) ( $r['rcode'] ?? ( 'Name does not exist' === ( $r['status'] ?? '' ) ? 3 : -1 ) ) ) {
				++$nx;
			} else {
				++$fail;
			}
		}
		$n = count( $results );
		// A resolver saying the name does not exist next to one with records is a real gap (TL-4).
		if ( $sets && $nx ) {
			return array( 'agree' => false, 'text' => 'Some resolvers find this name and others say it does not exist. A new or changed delegation is still spreading; check again after a few minutes.' );
		}
		if ( $nx && $nx === $n ) {
			return array( 'agree' => true, 'text' => 'All resolvers say the name does not exist.' );
		}
		if ( count( $sets ) < 2 ) {
			return array( 'agree' => true, 'text' => count( $sets ) ? 'One resolver answered, so there is nothing to compare.' : 'No resolver returned an answer.' );
		}
		if ( 1 === count( array_unique( $sets ) ) ) {
			return array( 'agree' => true, 'text' => '' === $sets[0] ? 'All resolvers agree: no records of this type.' : 'All resolvers agree. The records have spread.' );
		}
		// Different but non-empty answers everywhere: large sites answer by location, so this is
		// not proof of a change still spreading (TL-5).
		if ( ! in_array( '', $sets, true ) ) {
			return array( 'agree' => false, 'text' => 'The resolvers return different answers. Large sites often answer by location, so this can be normal. If you changed these records recently, compare again after ' . ( null !== $ttl ? 'the time to live (' . $ttl . ' seconds)' : 'a few minutes' ) . '.' );
		}
		return array( 'agree' => false, 'text' => 'The resolvers return different answers. A recent change may still be spreading, or each is holding an older copy until its time to live ends.' );
	}
}

if ( ! function_exists( 'db_rs_query' ) ) {
	/** Asks one resolver; returns the parsed answer plus the time taken. */
	function db_rs_query( $key, $name, $type ) {
		$all = db_rs_resolvers();
		if ( ! isset( $all[ $key ] ) ) {
			return array( 'ok' => false, 'status' => 'Unknown resolver', 'answers' => array(), 'ttl' => null, 'ms' => 0 );
		}
		$t0  = microtime( true );
		$res = wp_remote_get( $all[ $key ]['url'] . '?name=' . rawurlencode( $name ) . '&type=' . rawurlencode( $type ), array( 'timeout' => 5, 'headers' => array( 'Accept' => $all[ $key ]['accept'], 'User-Agent' => 'DomainBrothers-DomainTools/1.0 (+https://www.domainbrothers.com)' ) ) );
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array( 'ok' => false, 'status' => 'Did not answer', 'answers' => array(), 'ttl' => null, 'ms' => $ms );
		}
		return db_rs_parse( json_decode( (string) wp_remote_retrieve_body( $res ), true ), $type ) + array( 'ms' => $ms );
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/domain-resolve', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => array(
			'd'        => array( 'required' => true, 'type' => 'string' ),
			'type'     => array( 'required' => false, 'type' => 'string' ),
			'resolver' => array( 'required' => false, 'type' => 'string' ),
		),
		'callback'            => function ( WP_REST_Request $req ) {
			$send = static function ( $data, $code = 200 ) {
				$res = new WP_REST_Response( $data, $code );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			};
			// The same input rule as the domain lookup: trailing dots and accented names work (TL-22).
			$in   = function_exists( 'db_lk_input_name' ) ? db_lk_input_name( (string) $req->get_param( 'd' ) ) : array( 'name' => '', 'error' => 'Enter a domain name such as example.com.' );
			$name = $in['name'];
			$type = strtoupper( (string) ( $req->get_param( 'type' ) ? $req->get_param( 'type' ) : 'A' ) );
			if ( '' === $name ) {
				return $send( array( 'error' => $in['error'] ), 400 );
			}
			if ( ! isset( db_rs_types()[ $type ] ) ) {
				return $send( array( 'error' => 'Choose a record type from the list.' ), 400 );
			}
			$pick = strtolower( (string) ( $req->get_param( 'resolver' ) ? $req->get_param( 'resolver' ) : 'all' ) );
			$all  = db_rs_resolvers();
			$keys = 'all' === $pick ? array_keys( $all ) : ( isset( $all[ $pick ] ) ? array( $pick ) : array() );
			if ( ! $keys ) {
				return $send( array( 'error' => 'Choose a resolver from the list.' ), 400 );
			}
			$ip = function_exists( 'db_wl_ip' ) ? db_wl_ip() : '';
			if ( function_exists( 'db_wl_allow' ) && ! db_wl_allow( 'resolve|' . $ip, 30, 10 * MINUTE_IN_SECONDS ) ) {
				return $send( array( 'error' => 'Too many lookups. Please wait a few minutes and try again.' ), 429 );
			}
			$ck   = 'db_rs_' . md5( $name . '|' . $type . '|' . implode( ',', $keys ) );
			$data = get_transient( $ck );
			if ( ! is_array( $data ) ) {
				$out = array();
				foreach ( $keys as $k ) {
					$out[ $k ] = db_rs_query( $k, $name, $type ) + array( 'label' => $all[ $k ]['label'] );
				}
				$data = array( 'domain' => $name, 'type' => $type, 'results' => $out, 'summary' => db_rs_summary( $out ), 'checked' => gmdate( 'Y-m-d H:i:s' ) . ' UTC' );
				set_transient( $ck, $data, 60 ); // short, because the point is to watch change.
			}
			return $send( $data );
		},
	) );

	register_rest_route( 'db/v1', '/cert-decode', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$send = static function ( $data, $code = 200 ) {
				$res = new WP_REST_Response( $data, $code );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			};
			$ip = function_exists( 'db_wl_ip' ) ? db_wl_ip() : '';
			if ( function_exists( 'db_wl_allow' ) && ! db_wl_allow( 'cert|' . $ip, 20, 10 * MINUTE_IN_SECONDS ) ) {
				return $send( array( 'error' => 'Too many requests. Please wait a few minutes and try again.' ), 429 );
			}
			$body = $req->get_json_params();
			$pem  = is_array( $body ) && isset( $body['pem'] ) ? (string) $body['pem'] : '';
			$r    = db_rs_cert_decode( $pem );
			return isset( $r['error'] ) ? $send( $r, 400 ) : $send( $r );
		},
	) );
} );

if ( ! function_exists( 'db_rs_cert_rows' ) ) {
	/**
	 * Display rows for a parsed certificate (openssl_x509_parse output).
	 *
	 * @param array<string,mixed> $c Parsed certificate.
	 * @param int                 $now Unix time.
	 * @return array<int,array{0:string,1:string}>
	 */
	function db_rs_cert_rows( array $c, $now ) {
		$dn = static function ( $a ) {
			$parts = array();
			foreach ( array( 'CN' => 'CN', 'O' => 'O', 'OU' => 'OU', 'L' => 'L', 'ST' => 'ST', 'C' => 'C' ) as $k => $label ) {
				if ( ! empty( $a[ $k ] ) ) {
					$parts[] = $label . '=' . ( is_array( $a[ $k ] ) ? implode( '/', $a[ $k ] ) : $a[ $k ] );
				}
			}
			return $parts ? implode( ', ', $parts ) : 'Not shown';
		};
		$from = (int) ( isset( $c['validFrom_time_t'] ) ? $c['validFrom_time_t'] : 0 );
		$to   = (int) ( isset( $c['validTo_time_t'] ) ? $c['validTo_time_t'] : 0 );
		$san  = array();
		foreach ( explode( ',', (string) ( isset( $c['extensions']['subjectAltName'] ) ? $c['extensions']['subjectAltName'] : '' ) ) as $p ) {
			$p = trim( $p );
			if ( 0 === strpos( $p, 'DNS:' ) ) {
				$san[] = substr( $p, 4 );
			}
		}
		$days = $to ? (int) floor( ( $to - $now ) / 86400 ) : null;
		$rows = array(
			array( 'Subject', $dn( isset( $c['subject'] ) ? (array) $c['subject'] : array() ) ),
			array( 'Issuer', $dn( isset( $c['issuer'] ) ? (array) $c['issuer'] : array() ) ),
			array( 'Valid from', $from ? gmdate( 'Y-m-d H:i', $from ) . ' UTC' : 'Not shown' ),
			array( 'Valid until', $to ? gmdate( 'Y-m-d H:i', $to ) . ' UTC' : 'Not shown' ),
			array( 'Status', null === $days ? 'Unknown' : ( $now < $from ? 'Not valid yet' : ( $days < 0 ? 'Expired ' . abs( $days ) . ' days ago' : $days . ' days left' ) ) ),
			array( 'Names covered', $san ? implode( ', ', array_slice( $san, 0, 25 ) ) . ( count( $san ) > 25 ? ' and ' . ( count( $san ) - 25 ) . ' more' : '' ) : 'None listed (the subject name only)' ),
			array( 'Signature', isset( $c['signatureTypeSN'] ) ? (string) $c['signatureTypeSN'] : 'Not shown' ),
			array( 'Serial number', isset( $c['serialNumberHex'] ) ? (string) $c['serialNumberHex'] : 'Not shown' ),
			array( 'Self-signed', ( isset( $c['subject'], $c['issuer'] ) && $c['subject'] == $c['issuer'] ) ? 'Yes' : 'No' ), // phpcs:ignore WordPress.PHP.StrictComparisons
		);
		return $rows;
	}
}

if ( ! function_exists( 'db_rs_cert_decode' ) ) {
	/**
	 * Decodes a pasted certificate. Refuses private keys and anything that is not one certificate.
	 *
	 * @param string $pem Pasted text.
	 * @return array<string,mixed> rows, or error.
	 */
	function db_rs_cert_decode( $pem ) {
		$pem = trim( str_replace( "\r", '', $pem ) );
		if ( '' === $pem ) {
			return array( 'error' => 'Paste a certificate that starts with -----BEGIN CERTIFICATE-----.' );
		}
		if ( strlen( $pem ) > 12000 ) {
			return array( 'error' => 'That is too long for one certificate.' );
		}
		if ( preg_match( '/PRIVATE KEY/', $pem ) ) {
			return array( 'error' => 'That text contains a private key. Never paste a private key into a web page. Remove it and paste only the certificate.' );
		}
		if ( 1 !== preg_match_all( '/-----BEGIN CERTIFICATE-----/', $pem ) ) {
			return array( 'error' => 'Paste exactly one certificate, starting with -----BEGIN CERTIFICATE-----.' );
		}
		$parsed = @openssl_x509_parse( $pem ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $parsed ) ) {
			return array( 'error' => 'That does not look like a valid certificate. Check that you copied the whole block, including the BEGIN and END lines.' );
		}
		return array( 'rows' => db_rs_cert_rows( $parsed, time() ) );
	}
}

if ( ! function_exists( 'db_rs_widget' ) ) {
	/**
	 * Form, result area and script for the resolver and certificate tools.
	 *
	 * @param string $mode    'resolve' or 'cert'.
	 * @param array  $cfg     Keys: resolver (default 'all'), pickresolver (bool), button.
	 * @return string
	 */
	function db_rs_widget( $mode, array $cfg = array() ) {
		$cfg += array( 'resolver' => 'all', 'pickresolver' => false, 'button' => 'cert' === $mode ? 'Decode certificate' : 'Look up' );
		$cfg['mode']    = $mode;
		$cfg['ep']      = esc_url_raw( rest_url( 'db/v1/' . ( 'cert' === $mode ? 'cert-decode' : 'domain-resolve' ) ) );
		$cfg['resolvers'] = array();
		foreach ( db_rs_resolvers() as $k => $r ) {
			$cfg['resolvers'][ $k ] = $r['label'];
		}
		$cfg['types'] = array_keys( db_rs_types() );
		$file = __DIR__ . '/assets/domain-resolve.js';
		$js   = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$h    = '<style>' . db_lk_css() . '.dblk textarea{width:100%;min-height:200px;padding:14px;border:1px solid #cfd5de;border-radius:12px;font:14px/1.45 ui-monospace,SFMono-Regular,Menlo,monospace;background:#fff;color:#0f172a}.dblk select{padding:15px 14px;border:1px solid #cfd5de;border-radius:12px;font-size:16px;background:#fff;color:#0f172a}.dblk-opts{display:flex;flex-wrap:wrap;gap:10px;margin:10px 0 0}</style><div class="dblk" id="dbrs"><form class="dblk-form" id="dbrs-form" novalidate>';
		if ( 'cert' === $mode ) {
			$h .= '<label for="dbrs-in">Certificate (PEM)</label><textarea id="dbrs-in" name="pem" spellcheck="false" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----" required></textarea><div class="dblk-opts"><button type="submit" class="dbrs-go">' . esc_html( $cfg['button'] ) . '</button></div><p class="dblk-hint">The certificate is decoded and discarded. Never paste a private key.</p>';
		} else {
			$h .= '<label for="dbrs-in">Domain name</label><div class="dblk-row"><input id="dbrs-in" name="d" type="text" inputmode="url" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="example.com" required><button type="submit" class="dbrs-go">' . esc_html( $cfg['button'] ) . '</button></div><div class="dblk-opts"><label class="dblk-hint" for="dbrs-type">Record type</label><select id="dbrs-type" name="type">';
			foreach ( array_keys( db_rs_types() ) as $t ) {
				$h .= '<option value="' . esc_attr( $t ) . '">' . esc_html( $t ) . '</option>';
			}
			$h .= '</select>';
			if ( ! empty( $cfg['pickresolver'] ) ) {
				$h .= '<label class="dblk-hint" for="dbrs-resolver">DNS server</label><select id="dbrs-resolver" name="resolver"><option value="all">Compare all</option>';
				foreach ( db_rs_resolvers() as $k => $r ) {
					$h .= '<option value="' . esc_attr( $k ) . '"' . ( $cfg['resolver'] === $k ? ' selected' : '' ) . '>' . esc_html( $r['label'] ) . '</option>';
				}
				$h .= '</select>';
			}
			$h .= '</div><p class="dblk-hint">Free. Answers are cached for one minute so you can watch a change spread.</p>';
		}
		$h .= '</form><div id="dbrs-out" class="dblk-out" aria-live="polite"></div></div>';
		return $h . '<script data-no-delay>window.DBRS=' . wp_json_encode( $cfg ) . ';' . $js . '</script>';
	}
}
