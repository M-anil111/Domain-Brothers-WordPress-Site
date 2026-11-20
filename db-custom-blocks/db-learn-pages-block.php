<?php
/**
 * Two reference pages that earn links and answer common searches:
 *
 *  - /domain-glossary/: plain-language definitions of domain terms, with DefinedTermSet markup.
 *  - /domain-price-index/: median asking prices of the names listed here, by length and by
 *    extension, computed from the live inventory (cached 12 hours). Asking prices, not sale prices.
 *
 * Both are virtual routes (like /payment-terms/). Their title, description and canonical use the
 * route-meta plumbing in db-tool-pages-block.php, and they are added to /domain-tools-sitemap.xml.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_gl_terms' ) ) {
	/** @return array<int,array{0:string,1:string}> term, definition (alphabetical). */
	function db_gl_terms() {
		$t = array(
			array( 'A record', 'A DNS record that points a domain name to an IPv4 address, such as the server that hosts the website. The AAAA record does the same for IPv6.' ),
			array( 'Aftermarket', 'The market for domain names that are already registered, where owners sell to buyers through brokers, marketplaces and auctions rather than registering a new name.' ),
			array( 'Backorder', 'A request to register a domain the moment it becomes available after expiring. Several buyers can place a backorder on the same name, and the outcome depends on the service used.' ),
			array( 'ccTLD', 'A country-code top-level domain: a two-letter extension tied to a country or territory, such as .ca for Canada or .uk for the United Kingdom. Some have local registration rules.' ),
			array( 'Domain broker', 'A professional who finds the owner of a registered domain and negotiates its purchase on a buyer\'s behalf, usually keeping the buyer\'s identity private until a price is agreed.' ),
			array( 'Domain name', 'A human-readable address for a website or email service, such as example.com. It is made of a name and an extension, and is registered through a registrar for a set number of years.' ),
			array( 'Domain parking', 'Pointing a domain you own to a placeholder page instead of a full website. Owners often park names they plan to use later or are holding for sale.' ),
			array( 'Domain privacy', 'A registrar service that replaces the owner\'s personal contact details in the public registration record with the registrar\'s own, to reduce spam and protect identity.' ),
			array( 'Domain transfer', 'Moving a domain from one registrar to another, or from one owner to another. It normally needs the domain unlocked and an authorization code from the current registrar.' ),
			array( 'DNS', 'The domain name system: the global directory that turns a domain name into the addresses and settings computers need, such as the server for a website and the servers for email.' ),
			array( 'DNSSEC', 'A set of extensions to DNS that adds digital signatures to answers, so a resolver can check they have not been forged. It needs support at both the DNS host and the registry.' ),
			array( 'EPP code', 'The authorization code (also called an auth code or transfer code) that proves you control a domain. The new registrar asks for it to accept a transfer. It acts like a password and should be kept private.' ),
			array( 'Escrow', 'A neutral third party that holds the buyer\'s payment until the domain has been delivered, so neither side has to trust the other first.' ),
			array( 'Expiry date', 'The date a domain registration ends unless it is renewed. After it, most registrars allow a limited time to renew before the name is released for anyone to register.' ),
			array( 'gTLD', 'A generic top-level domain: an extension not tied to a country, such as .com, .net, .org or newer ones like .app and .shop.' ),
			array( 'MX record', 'A DNS record that names the servers which accept email for a domain. Each has a priority number, and the lowest number is tried first.' ),
			array( 'Name server', 'A server that holds the DNS records for a domain and answers questions about it. A domain is usually set to use two or more name servers from its DNS host.' ),
			array( 'Payment plan', 'An arrangement to pay for a domain in instalments. At Domain Brothers a plan runs 3, 6, 9 or 12 months, and the domain is transferred when the final payment is made.' ),
			array( 'Premium domain', 'A short, memorable or keyword-rich name that is already registered and priced above a normal registration fee because few names like it exist.' ),
			array( 'RDAP', 'The Registration Data Access Protocol: the modern replacement for WHOIS. It gives registration dates, the registrar and status codes in a structured format.' ),
			array( 'Redemption period', 'A window after a domain has expired in which the previous owner can still recover it, usually for an extra fee, before it is deleted and released.' ),
			array( 'Registrar', 'A company accredited to sell and manage domain registrations, such as the one where you buy and renew a name. Registrars act between owners and the registry.' ),
			array( 'Registrar lock', 'A setting that stops a domain being transferred away without the owner\'s consent. It is usually on by default and must be switched off before a transfer.' ),
			array( 'Registry', 'The organisation that runs a top-level domain and keeps the master list of names under it. Verisign runs .com, and CIRA runs .ca.' ),
			array( 'TLD', 'A top-level domain: the last part of a domain name, such as .com or .ca. It is also called the extension.' ),
			array( 'TXT record', 'A DNS record that holds text. It is used for email security settings such as SPF and DMARC and for proving ownership of a domain to other services.' ),
			array( 'UDRP', 'The Uniform Domain-Name Dispute-Resolution Policy: a process for trademark owners to challenge a domain registered in bad faith. Check trademarks before you buy a name.' ),
			array( 'WHOIS', 'The older public lookup service for domain registration data. Many registries now publish the same information through RDAP, and personal details are often hidden.' ),
		);
		usort( $t, static function ( $a, $b ) {
			return strcasecmp( $a[0], $b[0] );
		} );
		return $t;
	}
}

if ( ! function_exists( 'db_gl_anchor' ) ) {
	function db_gl_anchor( $term ) {
		return 't-' . trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $term ) ), '-' );
	}
}

if ( ! function_exists( 'db_pi_median' ) ) {
	/** Median of a list of numbers. */
	function db_pi_median( array $v ) {
		sort( $v );
		$n = count( $v );
		if ( 0 === $n ) {
			return 0.0;
		}
		$m = (int) floor( $n / 2 );
		return $n % 2 ? (float) $v[ $m ] : ( $v[ $m - 1 ] + $v[ $m ] ) / 2;
	}
}

if ( ! function_exists( 'db_pi_stats' ) ) {
	/**
	 * Median and range of asking prices by name length and by extension. Groups with fewer
	 * than three names are left out so no single price can be read off the table.
	 *
	 * @param array<int,array{len:int,ext:string,price:float}> $rows Listed names.
	 * @return array{total:int,by_len:array<string,array>,by_ext:array<string,array>}
	 */
	function db_pi_stats( array $rows ) {
		$len = array();
		$ext = array();
		foreach ( $rows as $r ) {
			$l            = $r['len'] >= 8 ? '8+' : (string) $r['len'];
			$len[ $l ][]  = (float) $r['price'];
			$e            = in_array( $r['ext'], array( 'com', 'ca' ), true ) ? $r['ext'] : 'other';
			$ext[ $e ][]  = (float) $r['price'];
		}
		$fold = static function ( array $groups ) {
			$out = array();
			foreach ( $groups as $k => $prices ) {
				if ( count( $prices ) >= 3 ) {
					$out[ $k ] = array( 'n' => count( $prices ), 'median' => db_pi_median( $prices ), 'low' => min( $prices ), 'high' => max( $prices ) );
				}
			}
			return $out;
		};
		$by_len = $fold( $len );
		uksort( $by_len, static function ( $a, $b ) {
			return (int) $a <=> (int) $b;
		} );
		return array( 'total' => count( $rows ), 'by_len' => $by_len, 'by_ext' => $fold( $ext ) );
	}
}

if ( ! function_exists( 'db_gl_css' ) ) {
	function db_gl_css() {
		return '.dbgl{max-width:900px;margin:0 auto;padding:0 20px 56px;color:#374151}.dbgl h2{margin:36px 0 12px;font-size:clamp(22px,2.6vw,28px);color:#0f172a}'
			. '.dbgl p{margin:0 0 14px;font-size:17px;line-height:1.65}.dbgl-az{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 28px;padding:0;list-style:none}'
			. '.dbgl-az a{display:inline-block;padding:7px 12px;border:1px solid #e4e7ec;border-radius:999px;background:#fff;color:#0f172a;font-size:14px;font-weight:600;text-decoration:none}.dbgl-az a:hover{border-color:#0f1b2d}'
			. '.dbgl dl{margin:0}.dbgl dt{margin:26px 0 6px;font-size:20px;font-weight:700;color:#0f172a;scroll-margin-top:100px}.dbgl dd{margin:0;font-size:17px;line-height:1.65}'
			. '.dbgl table{width:100%;border-collapse:collapse;margin:0 0 24px;font-size:16px}.dbgl th,.dbgl td{padding:12px 14px;border-bottom:1px solid #e4e7ec;text-align:left}.dbgl th{background:#f1f3f6;color:#0f172a}'
			. '.dbgl .num{font-variant-numeric:tabular-nums}.dbgl-wrap{overflow-x:auto;border:1px solid #e4e7ec;border-radius:12px;margin:0 0 24px}.dbgl-wrap table{margin:0;min-width:480px}'
			. '.dbgl-cta a{display:inline-flex;padding:15px 24px;border-radius:12px;background:#0f1b2d;color:#fff!important;font-weight:700;text-decoration:none}'
			. '.dbgl a.in{color:#0f1b2d;font-weight:600;text-decoration:underline}';
	}
}

if ( ! function_exists( 'db_gl_body' ) ) {
	function db_gl_body() {
		$terms = db_gl_terms();
		$h     = '<p class="db-sales-lead">Plain-language definitions of the words you meet when you buy, sell or manage a domain name.</p>';
		$h    .= '<div class="dbgl"><style>' . db_gl_css() . '</style><ul class="dbgl-az" aria-label="Jump to a term">';
		foreach ( $terms as $t ) {
			$h .= '<li><a href="#' . esc_attr( db_gl_anchor( $t[0] ) ) . '">' . esc_html( $t[0] ) . '</a></li>';
		}
		$h .= '</ul><dl>';
		foreach ( $terms as $t ) {
			$h .= '<dt id="' . esc_attr( db_gl_anchor( $t[0] ) ) . '">' . esc_html( $t[0] ) . '</dt><dd>' . esc_html( $t[1] ) . '</dd>';
		}
		$h .= '</dl><h2>Try it on a real domain</h2><p>The <a class="in" href="' . esc_url( home_url( '/domain-tools/' ) ) . '">free domain tools</a> show a domain\'s registry record, age, expiry, DNS, SSL certificate and email settings. Read how paying for a name works in our <a class="in" href="' . esc_url( home_url( '/payment-terms/' ) ) . '">payment terms</a>.</p>';
		return $h . '<p class="dbgl-cta"><a href="' . esc_url( home_url( '/all-domains/' ) ) . '">Buy a premium domain now</a></p></div>';
	}
}

if ( ! function_exists( 'db_pi_money' ) ) {
	function db_pi_money( $n ) {
		return '$' . number_format( (float) $n, 0, '.', ',' );
	}
}

if ( ! function_exists( 'db_pi_body' ) ) {
	function db_pi_body() {
		$idx   = function_exists( 'db_lk_index' ) ? db_lk_index() : array( 'ask' => array() );
		$stats = db_pi_stats( (array) $idx['ask'] );
		$table = static function ( array $groups, $label, $fmt ) {
			if ( ! $groups ) {
				return '<p>Not enough names listed yet to show this table.</p>';
			}
			$t = '<div class="dbgl-wrap"><table><thead><tr><th>' . esc_html( $label ) . '</th><th class="num">Names</th><th class="num">Median asking price</th><th class="num">Lowest</th><th class="num">Highest</th></tr></thead><tbody>';
			foreach ( $groups as $k => $g ) {
				$t .= '<tr><th scope="row">' . esc_html( $fmt( $k ) ) . '</th><td class="num">' . (int) $g['n'] . '</td><td class="num">' . esc_html( db_pi_money( $g['median'] ) ) . '</td><td class="num">' . esc_html( db_pi_money( $g['low'] ) ) . '</td><td class="num">' . esc_html( db_pi_money( $g['high'] ) ) . '</td></tr>';
			}
			return $t . '</tbody></table></div>';
		};
		$h  = '<p class="db-sales-lead">What the premium domain names listed at Domain Brothers are priced at, by length and by extension. Updated automatically from the live inventory.</p>';
		$h .= '<div class="dbgl"><style>' . db_gl_css() . '</style>';
		$h .= '<h2>By length</h2>' . $table( $stats['by_len'], 'Characters before the dot', static function ( $k ) { return $k . ' characters'; } );
		$h .= '<h2>By extension</h2>' . $table( $stats['by_ext'], 'Extension', static function ( $k ) { return 'other' === $k ? 'All other extensions' : '.' . $k; } );
		$h .= '<h2>How to read this</h2><p>These are the prices we ask today for the ' . (int) $stats['total'] . ' names that are for sale with a listed price, not what names have sold for. Groups with fewer than three names are left out so no single price stands out. Prices change as names sell, so the figures move.</p>';
		$h .= '<p>A shorter name or a .com usually costs more because fewer exist. Use the table as a guide, then see <a class="in" href="' . esc_url( home_url( '/all-domains/' ) ) . '">every name</a>, check how <a class="in" href="' . esc_url( home_url( '/payment-terms/' ) ) . '">payment plans</a> spread the cost, or look up any name with the <a class="in" href="' . esc_url( home_url( '/domain-tools/domain-name-analyzer/' ) ) . '">domain name analyzer</a>.</p>';
		return $h . '<p class="dbgl-cta"><a href="' . esc_url( home_url( '/all-domains/' ) ) . '">Buy a premium domain now</a></p></div>';
	}
}

if ( ! function_exists( 'db_learn_pages' ) ) {
	/** @return array<string,array{0:string,1:string,2:string,3:string}> path => name, SEO title, description, body function. */
	function db_learn_pages() {
		return array(
			'domain-glossary'    => array( 'Domain glossary', 'Domain Name Glossary: Terms Explained | Domain Brothers', 'Plain-language definitions of domain terms: registrar, registry, TLD, EPP code, DNS, RDAP, escrow, domain transfer, premium domain and more.', 'db_gl_body' ),
			'domain-price-index' => array( 'Domain price index', 'Domain Price Index: Median Asking Prices | Domain Brothers', 'Median asking prices of premium domains by length and extension, computed from the live Domain Brothers inventory.', 'db_pi_body' ),
		);
	}
}

add_filter( 'db_tp_route_meta_extra', static function ( $m, $path ) {
	$pages = db_learn_pages();
	return isset( $pages[ $path ] ) ? array( $pages[ $path ][1], $pages[ $path ][2], home_url( '/' . $path . '/' ) ) : $m;
}, 10, 2 );

add_filter( 'db_tp_sitemap_urls', static function ( $urls ) {
	foreach ( array_keys( db_learn_pages() ) as $path ) {
		$urls[] = home_url( '/' . $path . '/' );
	}
	return $urls;
} );

add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) ) {
		return;
	}
	$path  = db_sales_request_path();
	$pages = db_learn_pages();
	if ( ! isset( $pages[ $path ] ) ) {
		return;
	}
	$url   = home_url( '/' . $path . '/' );
	$graph = array( '@context' => 'https://schema.org', '@graph' => array(
		array( '@type' => 'BreadcrumbList', 'itemListElement' => array(
			array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url( '/' ) ),
			array( '@type' => 'ListItem', 'position' => 2, 'name' => $pages[ $path ][0], 'item' => $url ),
		) ),
	) );
	if ( 'domain-glossary' === $path ) {
		$set = array();
		foreach ( db_gl_terms() as $t ) {
			$set[] = array( '@type' => 'DefinedTerm', 'name' => $t[0], 'description' => $t[1], 'url' => $url . '#' . db_gl_anchor( $t[0] ) );
		}
		$graph['@graph'][] = array( '@type' => 'DefinedTermSet', 'name' => 'Domain glossary', 'url' => $url, 'hasDefinedTerm' => $set );
	} else {
		$graph['@graph'][] = array( '@type' => 'Dataset', 'name' => 'Domain Brothers domain price index', 'description' => $pages[ $path ][2], 'url' => $url, 'creator' => array( '@type' => 'Organization', 'name' => 'Domain Brothers' ), 'dateModified' => gmdate( 'Y-m-d' ) );
	}
	add_action( 'wp_head', static function () use ( $url, $graph ) {
		echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
		echo '<script type="application/ld+json">' . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}, 1 );
	db_sales_render( $pages[ $path ][0], $pages[ $path ][0], call_user_func( $pages[ $path ][3] ) );
}, 11 );
