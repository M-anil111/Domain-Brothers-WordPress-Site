<?php
/**
 * Recommended domains for a domain page.
 *
 * Replaces the old "Similar domains" list, which drew six random names from
 * the same category on every page load (so cached pages showed arbitrary
 * names, and nothing about the pick was related to the name being viewed).
 *
 * Each other published listing is scored against the one being viewed:
 *   - shared categories ("Featured" and "Latest" are labels, not topics);
 *   - similar name: the longest run of letters the two names share
 *     (fatality.ca / finality.ca), and shared owner keywords;
 *   - same extension, similar length, a price in the same band;
 *   - a small bonus for names that can be bought straight away.
 * Ties break on a hash of both ids, so the order is stable for a page (cache
 * friendly) but differs from page to page.
 *
 * Reads the cached index built by db_domain_index() (db-domain-filter-block.php),
 * so a page costs no extra database queries.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_rec_label' ) ) {
	/** The part of a domain before the first dot. */
	function db_rec_label( $name ) {
		$dot = strpos( $name, '.' );
		return false === $dot ? $name : substr( $name, 0, $dot );
	}
}

if ( ! function_exists( 'db_rec_tld' ) ) {
	function db_rec_tld( $name ) {
		$dot = strpos( $name, '.' );
		return false === $dot ? '' : substr( $name, $dot );
	}
}

if ( ! function_exists( 'db_rec_common_len' ) ) {
	/** Length of the longest substring two short strings share. */
	function db_rec_common_len( $a, $b ) {
		$la = strlen( $a );
		$lb = strlen( $b );
		if ( ! $la || ! $lb || $la > 40 || $lb > 40 ) {
			return 0;
		}
		$best = 0;
		$prev = array_fill( 0, $lb + 1, 0 );
		for ( $i = 1; $i <= $la; $i++ ) {
			$cur = array( 0 );
			for ( $j = 1; $j <= $lb; $j++ ) {
				if ( $a[ $i - 1 ] === $b[ $j - 1 ] ) {
					$cur[ $j ] = $prev[ $j - 1 ] + 1;
					if ( $cur[ $j ] > $best ) {
						$best = $cur[ $j ];
					}
				} else {
					$cur[ $j ] = 0;
				}
			}
			$prev = $cur;
		}
		return $best;
	}
}

if ( ! function_exists( 'db_rec_topics' ) ) {
	/** Category names of an index row, without the "Featured"/"Latest" labels. */
	function db_rec_topics( array $row ) {
		$out = array();
		foreach ( (array) ( $row['c'] ?? array() ) as $c ) {
			$name = (string) ( $c[0] ?? '' );
			if ( '' !== $name && ! in_array( strtolower( $name ), array( 'featured', 'latest', 'others', 'other' ), true ) ) {
				$out[ strtolower( $name ) ] = $name;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'db_rec_score' ) ) {
	/**
	 * @param array $a The domain being viewed (index row).
	 * @param array $b A candidate (index row).
	 * @return array{score:int,reason:string}
	 */
	function db_rec_score( array $a, array $b ) {
		$score  = 0;
		$reason = '';

		$ta     = db_rec_topics( $a );
		$tb     = db_rec_topics( $b );
		$shared = array_intersect_key( $tb, $ta );
		if ( $shared ) {
			$score += min( 3, count( $shared ) ) * 3;
			$reason = 'Also in ' . reset( $shared );
		}

		$la = db_rec_label( $a['n'] );
		$lb = db_rec_label( $b['n'] );
		$cl = db_rec_common_len( $la, $lb );
		if ( $cl >= 5 ) {
			$score += 5;
			$reason = 'Similar name';
		} elseif ( $cl === 4 ) {
			$score += 2;
			if ( '' === $reason ) {
				$reason = 'Similar name';
			}
		}

		// Shared owner keywords ("tech, startup, app").
		$ka = array_filter( array_map( 'trim', preg_split( '/[,;|]+/', (string) ( $a['k'] ?? '' ) ) ) );
		$kb = array_filter( array_map( 'trim', preg_split( '/[,;|]+/', (string) ( $b['k'] ?? '' ) ) ) );
		if ( $ka && $kb && array_intersect( $ka, $kb ) ) {
			$score += 2;
		}

		if ( db_rec_tld( $a['n'] ) === db_rec_tld( $b['n'] ) ) {
			$score += 2;
		}
		if ( abs( strlen( $la ) - strlen( $lb ) ) <= 2 ) {
			$score += 1;
		}
		if ( $a['p'] > 0 && $b['p'] > 0 ) {
			$ratio = $b['p'] / $a['p'];
			if ( $ratio >= 0.5 && $ratio <= 2 ) {
				$score += 1;
			}
		}
		if ( $b['p'] > 0 ) {
			$score += 1;
		}
		if ( '' === $reason ) {
			$reason = db_rec_tld( $a['n'] ) === db_rec_tld( $b['n'] ) ? 'Same extension' : 'You may also like';
		}
		return array( 'score' => $score, 'reason' => $reason );
	}
}

if ( ! function_exists( 'db_rec_for' ) ) {
	/**
	 * Best $limit other listings for $post_id, best first.
	 *
	 * @return array<int, array<string,mixed>> Index rows plus 'reason' and 'score'.
	 */
	function db_rec_for( $post_id, $limit = 6 ) {
		if ( ! function_exists( 'db_domain_index' ) ) {
			return array();
		}
		$rows = db_domain_index();
		$me   = null;
		foreach ( $rows as $r ) {
			if ( (int) ( $r['id'] ?? 0 ) === (int) $post_id ) {
				$me = $r;
				break;
			}
		}
		if ( ! $me ) {
			return array();
		}
		$scored = array();
		foreach ( $rows as $r ) {
			if ( (int) $r['id'] === (int) $post_id ) {
				continue;
			}
			$s              = db_rec_score( $me, $r );
			$r['score']     = $s['score'];
			$r['reason']    = $s['reason'];
			$r['_tie']      = crc32( $post_id . '-' . $r['id'] );
			$scored[]       = $r;
		}
		usort( $scored, function ( $x, $y ) {
			return ( $y['score'] <=> $x['score'] ) ?: ( $x['_tie'] <=> $y['_tie'] );
		} );
		return array_slice( $scored, 0, max( 1, (int) $limit ) );
	}
}

if ( ! function_exists( 'db_rec_cards_html' ) ) {
	/**
	 * Recommendation cards. $class lets the popup reuse the same markup.
	 */
	function db_rec_cards_html( array $recs, $class = 'db-rec-grid' ) {
		if ( ! $recs ) {
			return '';
		}
		$out = '<ul class="' . esc_attr( $class ) . '">';
		foreach ( $recs as $r ) {
			$price = (float) $r['p'];
			$out  .= '<li class="db-rec-card"><a class="db-rec-link" href="' . esc_url( $r['u'] ) . '">'
				. '<span class="db-rec-name">' . esc_html( $r['n'] ) . '</span>'
				. '<span class="db-rec-why">' . esc_html( $r['reason'] ) . '</span>'
				. '<span class="db-rec-price">' . ( $price > 0 ? '$' . esc_html( number_format( $price, 0 ) ) : 'Make an offer' ) . '</span>'
				. ( $price >= 600 && function_exists( 'db_dd_monthly' ) ? '<span class="db-rec-mo">or ' . esc_html( db_dd_monthly( $price ) ) . '/mo</span>' : '' )
				. '</a></li>';
		}
		return $out . '</ul>';
	}
}

if ( ! function_exists( 'db_rec_css' ) ) {
	function db_rec_css() {
		return '.db-rec-grid{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,190px),1fr));gap:12px}'
			. '.db-rec-card{margin:0;padding:0;list-style:none}'
			. '.db-rec-link{display:grid;gap:2px;height:100%;padding:14px 16px;border:1px solid #dfe5ee;border-radius:12px;background:#fff;color:#0a1628!important;text-decoration:none!important;transition:border-color .15s,box-shadow .15s,transform .15s}'
			. '.db-rec-link:hover,.db-rec-link:focus-visible{border-color:#2e7d14;box-shadow:0 6px 18px rgba(10,22,40,.10);transform:translateY(-2px)}'
			. '.db-rec-link:focus-visible{outline:3px solid #2e7d14;outline-offset:2px}'
			. '.db-rec-name{font-weight:700;font-size:16px;overflow-wrap:anywhere}'
			. '.db-rec-why{font-size:12px;color:#4b5563;letter-spacing:.02em}'
			. '.db-rec-price{font-weight:700;font-size:15px;margin-top:6px}'
			. '.db-rec-mo{font-size:12px;color:#137a3e;font-weight:600}'
			. '@media (prefers-reduced-motion:reduce){.db-rec-link{transition:none}.db-rec-link:hover{transform:none}}';
	}
}
