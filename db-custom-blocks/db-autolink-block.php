<?php
/**
 * Contextual internal links: the first natural mention of a topic in an article or a content
 * page links to the one page on the site that owns that topic.
 *
 * Why: the 2026-11-25 link audit (docs/INTERNAL_LINKING.md) found that the pages meant to rank
 * for a topic were not the ones the site pointed at. Search Console sent the "domain broker"
 * searches to the home page (positions 63 to 86) while /domain-brokerage-services/ had 22 links
 * from page text and none with that phrase; the glossary had no links at all; domain appraisal
 * had 2, the 3-letter list 8. Google's guidance (Link best practices, updated 2025-12-10):
 * every page you care about needs a link from another page, in context, with descriptive,
 * concise anchor text and no keyword stuffing.
 *
 * Rules, all enforced in db_autolink_apply() and covered by tests/seo/autolink-test.php:
 *  - one link per target page, at most DB_AUTOLINK_MAX links per page;
 *  - the first match in running text only: never inside a heading, an existing link, a button,
 *    code, a figure caption, a table header, a script or style, or a box this plugin draws
 *    (key takeaways, call to action, related reading, link hub, FAQ questions);
 *  - never a link to the page itself, nor to a page this page already links to;
 *  - the anchor is the visitor's own words as written (case kept), whole words only;
 *  - a target is linked only if it answers on this site (checked against published posts and
 *    pages, the tool list and the plugin's own routes).
 *
 * Off with the option db_autolink_off = 1. Filter: db_autolink_map.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_AUTOLINK_MAX' ) ) {
	define( 'DB_AUTOLINK_MAX', 5 );
}

if ( ! function_exists( 'db_autolink_map' ) ) {
	/**
	 * Phrase patterns and the path that owns each topic, most specific first.
	 * Patterns are matched case-insensitively on whole words.
	 *
	 * @return array<int, array{0:string,1:string}> [ regex fragment, path ]
	 */
	function db_autolink_map() {
		$map = array(
			// Buying and broker services (money pages).
			array( 'domain (?:name )?brok(?:er|ers|erage)(?: services?)?', '/domain-brokerage-services/' ),
			array( '(?:premium )?domain acquisition(?: services?)?|acquire a (?:specific|premium) domain', '/acquire-premium-domain/' ),
			array( 'domain appraisal(?: services?)?|appraise (?:a|your) domain', '/domain-appraisal-services/' ),
			array( 'domain buy(?:ing)? service', '/buy-domains-service/' ),
			array( 'domain management(?: services?)?', '/domain-management-services/' ),
			array( '(?:3|three)[- ](?:letter|character) domains?(?: names?)?', '/3-character-domains/' ),
			array( '(?:4|four)[- ](?:letter|character) domains?(?: names?)?', '/4-character-domains/' ),
			array( '(?:5|five)[- ](?:letter|character) domains?(?: names?)?', '/5-character-domains/' ),
			array( '(?:6|six)[- ](?:letter|character) domains?(?: names?)?', '/6-character-domains/' ),
			array( 'numeric domains?(?: names?)?|number domains', '/numeric-domains-for-sale/' ),
			array( 'premium domains? for sale|buy (?:a )?premium domains?|browse (?:our )?premium domains', '/all-domains/' ),
			array( 'domain deals', '/premium-domain-deals/' ),
			array( 'recent domain sales|domains? we (?:have )?sold', '/sold/' ),
			array( 'payment plans?|pay (?:for a domain )?in (?:monthly )?instal?ments', '/payment-terms/' ),
			array( 'sell (?:my|your) domains?(?: names?)?|list (?:your|my) domains? for sale', '/sell-your-domains/' ),
			array( 'social media handles?', '/acquire-social-media-handles/' ),
			// Learn: guides and reference pages.
			array( 'premium domain names?', '/premium-domain-names-complete-guide/' ),
			array( 'value (?:a|your) (?:premium )?domain(?: name)?|domain valuations?', '/how-to-value-a-premium-domain-name/' ),
			array( 'domain escrow|escrow service', '/domain-escrow-explained/' ),
			array( 'lease[- ]to[- ]own|domain leasing', '/domain-leasing-and-lease-to-own/' ),
			array( 'domain parking|parked domains?', '/domain-parking/' ),
			array( 'domain flipping', '/domain-flipping/' ),
			array( 'backorders?|expired domains?', '/domain-backorder-and-expired-domains/' ),
			array( 'UDRP|domain disputes?', '/trademarks-and-domain-disputes-udrp-basics/' ),
			array( 'transfer (?:a|your) domain(?: name)?', '/how-to-transfer-a-domain/' ),
			array( 'EPP codes?|auth(?:orization)? codes?|transfer codes?', '/epp-guidelines/' ),
			array( 'brandable (?:domain )?names?', '/brandable-domain-names/' ),
			array( 'short domain names?', '/short-domain-names/' ),
			array( 'domain extensions?', '/choosing-a-domain-extension-com-ca-io-and-more/' ),
			array( 'domain price index|asking prices', '/domain-price-index/' ),
			array( 'domain glossary', '/domain-glossary/' ),
			// Free tools.
			array( 'WHOIS lookup|RDAP lookup|domain age', '/domain-tools/domain-age-checker/' ),
			array( 'DNS lookup|DNS records', '/domain-tools/dns-lookup/' ),
			array( 'DNS propagation', '/domain-tools/dns-propagation-checker/' ),
			array( 'SSL certificates?', '/domain-tools/ssl-certificate-checker/' ),
			array( 'SPF|DMARC', '/domain-tools/dmarc-spf-checker/' ),
			array( 'DNSSEC', '/domain-tools/dnssec-checker/' ),
			// Terms explained in the glossary (it had no links at all).
			array( 'ccTLDs?|country[- ]code (?:top[- ]level )?domains?', '/domain-glossary/#t-cctld' ),
			array( 'gTLDs?|generic top[- ]level domains?', '/domain-glossary/#t-gtld' ),
			array( 'aftermarket', '/domain-glossary/#t-aftermarket' ),
			array( 'name ?servers?', '/domain-glossary/#t-name-server' ),
		);
		return (array) apply_filters( 'db_autolink_map', $map );
	}
}

if ( ! function_exists( 'db_autolink_apply' ) ) {
	/**
	 * Adds contextual links to an HTML fragment. Pure: no WordPress calls.
	 *
	 * @param string   $html      Content HTML.
	 * @param array    $map       [ regex fragment, href ] pairs, most specific first.
	 * @param string   $self_path Path of the page being shown (never linked).
	 * @param int      $max       Most links added.
	 * @param callable $url       Turns a path into the href written (default: unchanged).
	 * @return string
	 */
	function db_autolink_apply( $html, array $map, $self_path = '', $max = DB_AUTOLINK_MAX, $url = null ) {
		if ( '' === trim( (string) $html ) || $max < 1 || ! $map ) {
			return (string) $html;
		}
		$url  = is_callable( $url ) ? $url : static function ( $p ) {
			return $p;
		};
		$norm = static function ( $p ) {
			$p = strtolower( (string) preg_replace( '#^https?://[^/]+#i', '', (string) $p ) );
			$p = (string) preg_replace( '/[?#].*$/', '', $p );
			return '/' . trim( $p, '/' ) . ( '' === trim( $p, '/' ) ? '' : '/' );
		};
		$self = $norm( $self_path );
		// Targets the page already links to are left alone (one link per target is enough).
		$have = array();
		if ( preg_match_all( '/<a\s[^>]*href\s*=\s*["\']([^"\']+)["\']/i', $html, $m ) ) {
			foreach ( $m[1] as $h ) {
				$have[ $norm( $h ) ] = true;
			}
		}
		$todo = array();
		foreach ( $map as $row ) {
			if ( ! is_array( $row ) || count( $row ) < 2 ) {
				continue;
			}
			$target = $norm( $row[1] );
			$key    = strtolower( (string) $row[1] ); // glossary rules share a page, differ by #term
			if ( $target === $self || isset( $have[ $target ] ) || isset( $todo[ $key ] ) ) {
				continue;
			}
			$todo[ $key ] = array( '/(?<![\w\/.-])(' . $row[0] . ')(?![\w-])/iu', $row[1], $target );
		}
		if ( ! $todo ) {
			return (string) $html;
		}
		// Elements whose text is never linked. Plugin boxes are matched by class.
		$skip_tags = '/^(a|h[1-6]|button|code|pre|script|style|textarea|select|option|label|figcaption|th|summary|svg|noscript|title|nav)$/i';
		$skip_cls  = '/\b(db-takeaways|db-cta|db-related|db-hub|db-toc|db-faq|wp-block-buttons|wp-block-button|db-pa|db-rec|dbtp-back)\b/';
		$parts     = preg_split( '/(<!--.*?-->|<[^>]+>)/s', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$stack     = array(); // open elements that block linking, by tag name
		$added     = 0;
		$out       = '';
		$void      = '/^(area|base|br|col|embed|hr|img|input|link|meta|source|track|wbr)$/i';
		foreach ( $parts as $part ) {
			if ( '' === $part ) {
				continue;
			}
			if ( '<' === $part[0] ) {
				$out .= $part;
				if ( 0 === strpos( $part, '<!--' ) ) {
					continue;
				}
				if ( preg_match( '#^</\s*([a-z0-9]+)#i', $part, $t ) ) {
					$name = strtolower( $t[1] );
					for ( $i = count( $stack ) - 1; $i >= 0; $i-- ) {
						if ( $stack[ $i ] === $name ) {
							array_splice( $stack, $i );
							break;
						}
					}
				} elseif ( preg_match( '#^<\s*([a-z0-9]+)#i', $part, $t ) ) {
					$name = strtolower( $t[1] );
					if ( preg_match( $void, $name ) || '/>' === substr( $part, -2 ) ) {
						continue;
					}
					$blocks = preg_match( $skip_tags, $name ) || preg_match( '/\bclass\s*=\s*["\'][^"\']*' . substr( $skip_cls, 1, -1 ) . '/i', $part );
					if ( $blocks ) {
						$stack[] = $name;
					} elseif ( $stack ) {
						// A plain element inside a blocked one: track it so the closing tags pair up.
						$stack[] = $name;
					}
				}
				continue;
			}
			if ( $stack || $added >= $max || '' === trim( $part ) ) {
				$out .= $part;
				continue;
			}
			foreach ( $todo as $rule ) {
				if ( $added >= $max ) {
					break;
				}
				if ( ! preg_match( $rule[0], $part, $mm, PREG_OFFSET_CAPTURE ) ) {
					continue;
				}
				$pos  = $mm[1][1];
				$text = $mm[1][0];
				$href = htmlspecialchars( (string) $url( $rule[1] ), ENT_QUOTES, 'UTF-8' );
				$part = substr( $part, 0, $pos ) . '<a href="' . $href . '" class="db-autolink">' . $text . '</a>' . substr( $part, $pos + strlen( $text ) );
				foreach ( $todo as $k => $r ) {
					if ( $r[2] === $rule[2] ) {
						unset( $todo[ $k ] ); // one link per target page
					}
				}
				++$added;
				break; // one link per text run keeps links spaced out
			}
			$out .= $part;
		}
		return (string) $out;
	}
}

if ( ! function_exists( 'db_autolink_live_map' ) ) {
	/**
	 * The map with targets that do not answer on this site removed. Cached for 12 hours;
	 * the cache key changes when the map changes.
	 *
	 * @return array<int, array{0:string,1:string}>
	 */
	function db_autolink_live_map() {
		static $live = null;
		if ( null !== $live ) {
			return $live;
		}
		$map = db_autolink_map();
		$key = 'db_autolink_ok_' . md5( wp_json_encode( $map ) . ( function_exists( 'db_seller_enabled' ) && db_seller_enabled() ? 's' : '' ) );
		$ok  = get_transient( $key );
		if ( ! is_array( $ok ) ) {
			$ok     = array();
			$routes = array( '/all-domains/', '/domain-glossary/', '/domain-price-index/', '/payment-terms/', '/domain-tools/', '/find-a-domain/', '/collections/', '/trust-center/' );
			if ( function_exists( 'db_tp_pages' ) ) {
				foreach ( array_keys( db_tp_pages() ) as $slug ) {
					$routes[] = '/domain-tools/' . $slug . '/';
				}
			}
			if ( function_exists( 'db_seller_enabled' ) && db_seller_enabled() ) {
				$routes[] = '/sell-your-domains/';
			}
			foreach ( $map as $row ) {
				$path = (string) preg_replace( '/#.*$/', '', $row[1] );
				if ( isset( $ok[ $path ] ) ) {
					continue;
				}
				$slug = trim( $path, '/' );
				$good = in_array( $path, $routes, true );
				if ( ! $good && '' !== $slug && false === strpos( $slug, '/' ) ) {
					$page = get_page_by_path( $slug, OBJECT, array( 'page', 'post' ) );
					$good = $page instanceof WP_Post && 'publish' === $page->post_status && '' === $page->post_password;
				}
				$ok[ $path ] = $good;
			}
			set_transient( $key, $ok, 12 * HOUR_IN_SECONDS );
		}
		$live = array_values( array_filter( $map, static function ( $row ) use ( $ok ) {
			return ! empty( $ok[ (string) preg_replace( '/#.*$/', '', $row[1] ) ] );
		} ) );
		return $live;
	}
}

// Articles (guides and news) and content pages. Not the home page or the transactional pages,
// which have their own flow; not listings, whose text is the seller's.
add_filter( 'the_content', function ( $html ) {
	// Home is handled on the finished page below (its template bypasses the_content).
	if ( is_admin() || is_feed() || is_front_page() || ! in_the_loop() || ! is_main_query() || ! is_singular( array( 'post', 'page' ) ) ) {
		return $html;
	}
	if ( '1' === (string) get_option( 'db_autolink_off', '0' ) ) {
		return $html;
	}
	$skip = array( 'all-domains', 'my-wishlist', 'checkout', 'buy-now', 'make-an-offer', 'payment-plan-setup', 'thank-you', 'sign-in', 'register', 'seller', 'sold', 'site-map', 'privacy-policy', 'terms-of-use', 'refund-policy', 'cookie-policy', 'acceptable-use-policy', 'trademark-and-copyright-notices', 'seller-agreement', 'contact-us' );
	if ( is_page( $skip ) ) {
		return $html;
	}
	$self = (string) wp_parse_url( (string) get_permalink(), PHP_URL_PATH );
	return db_autolink_apply( (string) $html, db_autolink_live_map(), $self, DB_AUTOLINK_MAX, static function ( $p ) {
		return home_url( $p );
	} );
}, 35 );

if ( ! function_exists( 'db_autolink_front_page' ) ) {
	/**
	 * Home: the DomainFolio front page template prints its text with echo get_the_content(),
	 * which skips the_content filters, so the broker link is added to the finished page, inside
	 * the template's own text block (.pageNewContent) only. Search Console sent "domain broker"
	 * searches to home (positions 63 to 86) instead of the brokerage page; linking home's own
	 * "domain brokerage" wording to that page tells Google which page owns the topic.
	 *
	 * @param string $page Whole page HTML.
	 * @return string
	 */
	function db_autolink_front_page( $page ) {
		if ( ! is_string( $page ) ) {
			return $page;
		}
		$start = strpos( $page, 'pageNewContent' );
		if ( false === $start ) {
			return $page;
		}
		$start = strpos( $page, '>', $start );
		$end   = false !== $start ? strpos( $page, '</main>', $start ) : false;
		if ( false === $start || false === $end ) {
			return $page;
		}
		++$start;
		$only = array( '/domain-brokerage-services/', '/acquire-premium-domain/' );
		$map  = array_values( array_filter( db_autolink_live_map(), static function ( $row ) use ( $only ) {
			return in_array( $row[1], $only, true );
		} ) );
		$part = db_autolink_apply( substr( $page, $start, $end - $start ), $map, '/', 2, static function ( $p ) {
			return home_url( $p );
		} );
		return substr( $page, 0, $start ) . $part . substr( $page, $end );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || ! is_front_page() || '1' === (string) get_option( 'db_autolink_off', '0' ) ) {
		return;
	}
	ob_start( 'db_autolink_front_page' );
}, 22 );
