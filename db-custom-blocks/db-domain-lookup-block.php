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

if ( ! function_exists( 'db_lk_registry' ) ) {
	/** @return array<string,mixed> */
	function db_lk_registry( $name ) {
		$r = db_lk_http( 'https://rdap.org/domain/' . rawurlencode( $name ) );
		if ( 404 === $r['code'] ) {
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

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/domain-lookup', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => array( 'd' => array( 'required' => true, 'type' => 'string' ) ),
		'callback'            => function ( WP_REST_Request $req ) {
			$raw = strtolower( trim( (string) $req->get_param( 'd' ) ) );
			$raw = preg_replace( '#^https?://#', '', $raw );
			$raw = preg_replace( '#^www\.#', '', explode( '/', $raw )[0] );
			$name = function_exists( 'db_tools_valid_domain' ) ? db_tools_valid_domain( $raw ) : '';
			$send = static function ( $data, $code = 200 ) {
				$res = new WP_REST_Response( $data, $code );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			};
			if ( '' === $name ) {
				return $send( array( 'error' => 'Enter a domain name such as example.com.' ), 400 );
			}
			$ip = function_exists( 'db_wl_ip' ) ? db_wl_ip() : '';
			if ( function_exists( 'db_wl_allow' ) && ! db_wl_allow( 'lookup|' . $ip, 20, 10 * MINUTE_IN_SECONDS ) ) {
				return $send( array( 'error' => 'Too many lookups. Please wait a few minutes and try again.' ), 429 );
			}
			$key  = 'db_lk_' . md5( $name );
			$data = get_transient( $key );
			if ( ! is_array( $data ) ) {
				$data = array(
					'domain'      => $name,
					'registry'    => db_lk_registry( $name ),
					'dns'         => db_lk_dns( $name ),
					'archive'     => db_lk_archive( $name ),
					'rank'        => db_lk_rank( $name ),
					'comparables' => db_lk_comparables( $name ),
					'estimate'    => db_lk_estimate( $name ),
					'checked'     => gmdate( 'Y-m-d H:i' ) . ' UTC',
				);
				set_transient( $key, $data, 6 * HOUR_IN_SECONDS );
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
		$h   .= '<style>' . db_lk_css() . '</style><div class="dblk" id="dblk">';
		$h   .= '<form class="dblk-form" id="dblk-form" novalidate><label for="dblk-in">Domain name</label><div class="dblk-row"><input id="dblk-in" name="d" type="text" inputmode="url" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="example.com" required><button type="submit">Look up</button></div><p class="dblk-hint">Free. Results come from public sources and are cached for a few hours.</p></form>';
		$h   .= '<div id="dblk-out" class="dblk-out" aria-live="polite"></div>';
		$h   .= '<section class="dblk-src"><h2>Where the information comes from</h2><ul>'
			. '<li><strong>Registry record.</strong> The public RDAP record held by the domain\'s registry: registration and expiry dates, registrar, status and name servers.</li>'
			. '<li><strong>DNS.</strong> Live records from Cloudflare\'s public resolver: website address, mail servers and name servers.</li>'
			. '<li><strong>Web history.</strong> Whether the Internet Archive\'s Wayback Machine has captured the site, and when it last did.</li>'
			. '<li><strong>Popularity.</strong> The name\'s position in the Tranco list of the top one million websites, when it appears.</li>'
			. '<li><strong>Comparable sales.</strong> Recorded sale prices of similar names Domain Brothers has sold, and the asking prices of similar names listed now.</li></ul>'
			. '<p>This is information, not an appraisal or advice. What a name sells for depends on the buyer, the seller and the moment. Looking for a name? <a href="' . $u( '/all-domains/' ) . '">Browse all domains</a>, try the <a href="' . $u( '/find-a-domain/' ) . '">domain finder</a>, see <a href="' . $u( '/sold/' ) . '">what has sold</a> or use the <a href="' . $u( '/payment-terms/' ) . '">payment plan calculator</a>.</p>'
			. '<p><a class="dblk-cta" href="' . $u( '/all-domains/' ) . '">Buy a premium domain now</a></p></section></div>';
		$h   .= '<script data-no-delay>' . db_lk_js( esc_url_raw( rest_url( 'db/v1/domain-lookup' ) ) ) . '</script>';
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
			. '.dblk-hint{margin:8px 0 0;font-size:14px;color:#5a6475}'
			. '.dblk-out{margin:28px 0 0;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.dblk-out:empty{display:none}'
			. '.dblk-card{padding:20px;border:1px solid var(--line);border-radius:16px;background:#fff}.dblk-card.is-wide{grid-column:1/-1}'
			. '.dblk-card h3{margin:0 0 12px;font-size:17px;color:var(--ink)}.dblk-card dl{margin:0;display:grid;grid-template-columns:auto 1fr;gap:8px 16px;font-size:15px}'
			. '.dblk-card dt{color:#5a6475}.dblk-card dd{margin:0;color:var(--ink);font-weight:600;overflow-wrap:anywhere}.dblk-card p{margin:0;font-size:15px;line-height:1.55}'
			. '.dblk-pill{display:inline-block;margin:0 6px 6px 0;padding:3px 10px;border-radius:999px;background:var(--soft);color:#3f5a0a;font-size:13px;font-weight:600}'
			. '.dblk-head{grid-column:1/-1;padding:20px 22px;border-radius:16px;background:var(--navy);color:#fff}.dblk-head strong{display:block;font-size:24px;overflow-wrap:anywhere}.dblk-head span{color:#c7d2e0;font-size:14px}'
			. '.dblk-err{grid-column:1/-1;padding:16px 18px;border-radius:12px;background:#fdeceb;color:#8a1c14}'
			. '.dblk-src{margin:44px 0 0;padding:26px;border-radius:16px;background:var(--panel)}.dblk-src h2{margin:0 0 12px;font-size:22px;color:var(--ink)}.dblk-src ul{margin:0 0 14px;padding-left:20px}.dblk-src li{margin:0 0 8px;line-height:1.55}'
			. '.dblk-src a:not(.dblk-cta){color:var(--navy);font-weight:600;text-decoration:underline}'
			. '@media (max-width:700px){.dblk-out{grid-template-columns:minmax(0,1fr)}.dblk-row{flex-direction:column}.dblk-row button{width:100%}}';
	}
}

if ( ! function_exists( 'db_lk_js' ) ) {
	/** Client script: reads the form, calls the endpoint and builds the cards with textContent only. */
	function db_lk_js( $endpoint ) {
		return '(function(){var f=document.getElementById("dblk-form"),o=document.getElementById("dblk-out");if(!f||!o)return;var EP=' . wp_json_encode( $endpoint ) . ';'
			. 'function el(t,c,x){var e=document.createElement(t);if(c)e.className=c;if(x!==undefined)e.textContent=x;return e}'
			. 'function money(n){return "$"+Math.round(n).toLocaleString("en-US")}'
			. 'function card(title,rows,wide){var c=el("div","dblk-card"+(wide?" is-wide":""));c.appendChild(el("h3","",title));if(typeof rows==="string"){c.appendChild(el("p","",rows));return c}var d=el("dl");rows.forEach(function(r){d.appendChild(el("dt","",r[0]));d.appendChild(el("dd","",r[1]))});c.appendChild(d);return c}'
			. 'function render(j){o.textContent="";var h=el("div","dblk-head");h.appendChild(el("strong","",j.domain));h.appendChild(el("span","","Checked "+j.checked));o.appendChild(h);'
			. 'var r=j.registry;if(r.state==="ok"){var e=r.events||{};var rows=[["Registered",e.registration||"Not shown"],["Expires",e.expiration||"Not shown"],["Last changed",e["last changed"]||e.lastchanged||"Not shown"],["Registrar",r.registrar||"Not shown"],["DNSSEC",r.dnssec===null?"Not shown":(r.dnssec?"Signed":"Not signed")]];var c=card("Registry record",rows);if(r.status&&r.status.length){var p=el("p");p.style.marginTop="12px";r.status.forEach(function(s){p.appendChild(el("span","dblk-pill",s))});c.appendChild(p)}o.appendChild(c)}'
			. 'else if(r.state==="none"){o.appendChild(card("Registry record","No registration record was found. The name looks unregistered, or this extension does not publish one."))}else{o.appendChild(card("Registry record","The registry did not answer. Try again in a moment."))}'
			. 'var d=j.dns||{},has=function(k){return d[k]&&d[k].length};o.appendChild(card("DNS",[["Website address",has("A")||has("AAAA")?(d.A||[]).concat(d.AAAA||[]).join(", "):"None"],["Mail servers",has("MX")?d.MX.join(", "):"None"],["Name servers",has("NS")?d.NS.join(", "):"None"],["SPF record",d.SPF?"Present":"Not found"]]));'
			. 'var a=j.archive;if(a.state==="ok"){var ca=card("Web history","The Wayback Machine has captured this name. Latest capture near "+a.date+".");var l=el("p");l.style.marginTop="10px";var an=el("a","","See the captures");an.href=a.url;an.target="_blank";an.rel="noopener";l.appendChild(an);ca.appendChild(l);o.appendChild(ca)}else{o.appendChild(card("Web history",a.state==="none"?"No captures found in the Wayback Machine.":"The archive did not answer."))}'
			. 'var k=j.rank;o.appendChild(card("Popularity",k.state==="ok"?"Ranked #"+k.rank.toLocaleString("en-US")+" in the Tranco top one million sites ("+k.date+").":(k.state==="none"?"Not in the top one million sites. Most names are not.":"The ranking service did not answer.")));'
			. 'var m=j.comparables.sold;if(m.state==="ok"){o.appendChild(card("Comparable sales at Domain Brothers",[["Basis",m.basis],["Names sold",String(m.count)],["Median price",money(m.median)],["Range",money(m.low)+" to "+money(m.high)]]))}else{o.appendChild(card("Comparable sales at Domain Brothers","Too few similar names have a recorded sale price to show a fair comparison."))}var n=j.comparables.ask;if(n.state==="ok"){o.appendChild(card("Similar names for sale now (asking prices)",[["Basis",n.basis],["Names listed",String(n.count)],["Median asking price",money(n.median)],["Range",money(n.low)+" to "+money(n.high)]]))}var s=j.estimate;if(s&&s.state==="ok"){o.appendChild(card("Computer estimate (not an appraisal)",[["Auction",money(s.auction)],["Marketplace",money(s.marketplace)],["Brokerage",money(s.brokerage)]],true))}'
			. '}'
			. 'f.addEventListener("submit",function(ev){ev.preventDefault();var v=f.d.value.trim();if(!v)return;var b=f.querySelector("button");b.disabled=true;b.textContent="Looking up...";o.textContent="";'
			. 'fetch(EP+"?d="+encodeURIComponent(v),{headers:{Accept:"application/json"}}).then(function(r){return r.json().then(function(j){return{ok:r.ok,j:j}})}).then(function(x){if(!x.ok){var e=el("div","dblk-err",(x.j&&(x.j.error||x.j.message))||"Lookup failed.");o.appendChild(e)}else{render(x.j)}}).catch(function(){o.appendChild(el("div","dblk-err","The lookup could not be completed. Check your connection and try again."))}).then(function(){b.disabled=false;b.textContent="Look up"})});'
			. 'var q=new URLSearchParams(location.search).get("d");if(q){f.d.value=q;f.requestSubmit?f.requestSubmit():f.dispatchEvent(new Event("submit",{cancelable:true}))}'
			. '}());';
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) || 'domain-tools' !== db_sales_request_path() ) {
		return;
	}
	add_filter( 'pre_get_document_title', static function () {
		return 'Free Domain Tools: Lookup, Registry Record, DNS and Comparable Sales | Domain Brothers';
	} );
	add_action( 'wp_head', static function () {
		echo '<meta name="description" content="Look up any domain for free: registration date, registrar, expiry, DNS records, web history, popularity rank and what similar names have sold for.">' . "\n";
	}, 1 );
	db_sales_render( 'Free domain tools', 'Domain tools', db_lk_body() );
}, 11 );
