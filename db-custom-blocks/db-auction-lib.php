<?php
/**
 * Domain auctions: the pure rules (no WordPress, no database), tested offline in
 * tests/auction/auction-test.php.
 *
 * How an auction works:
 *  - A name gets a starting price, an optional hidden reserve (the lowest price the
 *    owner accepts), an optional Buy It Now price and a length in days.
 *  - Bidders enter the most they are willing to pay ("your maximum"). The site bids
 *    for them, one increment at a time, only as far as needed to stay ahead (proxy
 *    bidding). The visible price is never higher than the second-highest maximum plus
 *    one increment, except that it jumps to the reserve once someone's maximum covers it.
 *  - A bid in the last minutes pushes the end back, so nobody can win by bidding in
 *    the final second.
 *  - At the end, the highest bidder wins when the reserve is met. Below the reserve the
 *    owner may still accept the highest bid for a few days.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || defined( 'DB_AUCTION_TESTING' ) || exit;

if ( ! defined( 'DB_AUC_MAX_AMOUNT' ) ) {
	define( 'DB_AUC_MAX_AMOUNT', 10000000 );
}

if ( ! function_exists( 'db_auc_rules_default' ) ) {
	/** Every limit an admin can change (option db_auction_rules overrides these). */
	function db_auc_rules_default() {
		return array(
			'durations'      => array( 3, 5, 7, 10, 14 ), // days a seller can choose
			'default_days'   => 7,
			'extend_window'  => 5,   // minutes: a bid this close to the end...
			'extend_by'      => 5,   // ...pushes the end to this many minutes after the bid
			'pay_days'       => 3,   // the winner has this long to pay
			'below_reserve'  => 7,   // days the owner may accept the highest bid after a reserve was not met
			'second_chances' => 2,   // offers to the next bidders when a winner does not pay
			'min_start'      => 1,
		);
	}
}

if ( ! function_exists( 'db_auc_increment' ) ) {
	/** The smallest step above $price. Grows with the price so bids stay meaningful. */
	function db_auc_increment( $price ) {
		$price = (float) $price;
		$steps = array(
			array( 100, 5 ),
			array( 1000, 10 ),
			array( 5000, 50 ),
			array( 25000, 100 ),
			array( 100000, 250 ),
		);
		foreach ( $steps as $s ) {
			if ( $price < $s[0] ) {
				return $s[1];
			}
		}
		return 500;
	}
}

if ( ! function_exists( 'db_auc_min_bid' ) ) {
	/**
	 * The lowest maximum a new bidder may enter.
	 *
	 * @param array $a Keys: start, current, bids.
	 */
	function db_auc_min_bid( array $a ) {
		if ( (int) ( $a['bids'] ?? 0 ) < 1 ) {
			return (int) $a['start'];
		}
		return (int) ( $a['current'] + db_auc_increment( $a['current'] ) );
	}
}

if ( ! function_exists( 'db_auc_clean_amount' ) ) {
	/** Whole dollars from "$12,500" or "12500.00"; 0 when not a positive amount. */
	function db_auc_clean_amount( $raw ) {
		$s = preg_replace( '/[\s,$]/', '', (string) $raw );
		if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $s ) ) {
			return 0;
		}
		$n = (int) floor( (float) $s );
		return $n > 0 && $n <= DB_AUC_MAX_AMOUNT ? $n : 0;
	}
}

if ( ! function_exists( 'db_auc_validate_setup' ) ) {
	/**
	 * Checks a new auction's numbers.
	 *
	 * @param array $p     Keys: start, reserve, bin, days (raw input).
	 * @param array $rules From db_auc_rules_default().
	 * @return array{0:array,1:string[]} Clean values and the errors.
	 */
	function db_auc_validate_setup( array $p, array $rules ) {
		$c = array(
			'start'        => db_auc_clean_amount( $p['start'] ?? '' ),
			'reserve'      => '' === trim( (string) ( $p['reserve'] ?? '' ) ) ? 0 : db_auc_clean_amount( $p['reserve'] ),
			'bin'          => '' === trim( (string) ( $p['bin'] ?? '' ) ) ? 0 : db_auc_clean_amount( $p['bin'] ),
			'days'         => (int) ( $p['days'] ?? 0 ),
			'show_reserve' => ! empty( $p['show_reserve'] ),
		);
		$e = array();
		if ( $c['start'] < (int) $rules['min_start'] ) {
			$e[] = 'Enter a starting price of at least $' . (int) $rules['min_start'] . '.';
		}
		if ( '' !== trim( (string) ( $p['reserve'] ?? '' ) ) && ! $c['reserve'] ) {
			$e[] = 'Enter the reserve as a whole dollar amount, or leave it empty for no reserve.';
		} elseif ( $c['reserve'] && $c['reserve'] < $c['start'] ) {
			$e[] = 'The reserve must be at least the starting price.';
		}
		if ( '' !== trim( (string) ( $p['bin'] ?? '' ) ) && ! $c['bin'] ) {
			$e[] = 'Enter Buy It Now as a whole dollar amount, or leave it empty.';
		} elseif ( $c['bin'] && $c['bin'] <= max( $c['start'], $c['reserve'] ) ) {
			$e[] = 'Buy It Now must be higher than the starting price and the reserve.';
		}
		if ( ! in_array( $c['days'], array_map( 'intval', (array) $rules['durations'] ), true ) ) {
			$e[] = 'Choose how many days the auction runs.';
		}
		if ( ! $c['reserve'] ) {
			$c['show_reserve'] = false;
		}
		return array( $c, $e );
	}
}

if ( ! function_exists( 'db_auc_place_bid' ) ) {
	/**
	 * Applies one bid (a bidder's maximum) to the auction's state with proxy bidding.
	 *
	 * @param array  $s      Keys: start, reserve, current, leader, leader_max, bids.
	 * @param string $bidder Bidder key (email).
	 * @param int    $max    The bidder's maximum.
	 * @return array{ok:bool,error:string,state:array,outbid:string,leading:bool}
	 *         outbid: the previous leader who lost the lead ('' when none).
	 */
	function db_auc_place_bid( array $s, $bidder, $max ) {
		$s   += array( 'reserve' => 0, 'current' => 0, 'leader' => '', 'leader_max' => 0, 'bids' => 0 );
		$max  = (int) $max;
		$res  = array( 'ok' => false, 'error' => '', 'state' => $s, 'outbid' => '', 'leading' => false );
		$res_ = (int) $s['reserve'];
		$mine = '' !== $s['leader'] && $s['leader'] === $bidder;

		if ( $max <= 0 || $max > DB_AUC_MAX_AMOUNT ) {
			$res['error'] = 'Enter your maximum bid in whole dollars.';
			return $res;
		}
		if ( $mine ) {
			// The leader raises their own maximum; the visible price only moves to meet the reserve.
			if ( $max <= (int) $s['leader_max'] ) {
				$res['error'] = 'You are already the highest bidder. To raise your maximum, enter more than $' . number_format( (int) $s['leader_max'] ) . '.';
				return $res;
			}
			$s['leader_max'] = $max;
			if ( $res_ && $s['current'] < $res_ && $max >= $res_ ) {
				$s['current'] = $res_;
			}
			$res['ok']      = true;
			$res['leading'] = true;
			$res['state']   = $s;
			return $res;
		}
		$min = db_auc_min_bid( array( 'start' => $s['start'], 'current' => $s['current'], 'bids' => $s['bids'] ) );
		if ( $max < $min ) {
			$res['error'] = 'Your maximum must be at least $' . number_format( $min ) . '.';
			return $res;
		}
		$s['bids'] = (int) $s['bids'] + 1;
		if ( '' === $s['leader'] ) {
			$s['leader']     = $bidder;
			$s['leader_max'] = $max;
			$s['current']    = (int) $s['start'];
			if ( $res_ && $max >= $res_ ) {
				$s['current'] = max( (int) $s['start'], $res_ );
			}
			$res['leading'] = true;
		} elseif ( $max > (int) $s['leader_max'] ) {
			$prev            = $s['leader'];
			$s['current']    = min( $max, (int) $s['leader_max'] + db_auc_increment( $s['leader_max'] ) );
			$s['leader']     = $bidder;
			$s['leader_max'] = $max;
			if ( $res_ && $max >= $res_ && $s['current'] < $res_ ) {
				$s['current'] = $res_;
			}
			$res['outbid']  = $prev;
			$res['leading'] = true;
		} else {
			// The leader's maximum covers this bid: the price rises, the leader stays ahead.
			// Equal maximums: the earlier bid wins.
			$s['current'] = min( (int) $s['leader_max'], $max + db_auc_increment( $max ) );
			if ( $res_ && (int) $s['leader_max'] >= $res_ && $s['current'] < $res_ ) {
				$s['current'] = $res_;
			}
		}
		$res['ok']    = true;
		$res['state'] = $s;
		return $res;
	}
}

if ( ! function_exists( 'db_auc_extend' ) ) {
	/**
	 * The end time after a bid at $now: a bid in the last $window_min minutes moves the end
	 * to $by_min minutes after the bid. Never earlier than the current end.
	 */
	function db_auc_extend( $ends, $now, $window_min, $by_min ) {
		$ends = (int) $ends;
		if ( $ends - (int) $now <= (int) $window_min * 60 ) {
			return max( $ends, (int) $now + (int) $by_min * 60 );
		}
		return $ends;
	}
}

if ( ! function_exists( 'db_auc_outcome' ) ) {
	/** 'no_bids', 'reserve_not_met' or 'sold' once the auction has ended. */
	function db_auc_outcome( array $s ) {
		if ( (int) ( $s['bids'] ?? 0 ) < 1 || '' === (string) ( $s['leader'] ?? '' ) ) {
			return 'no_bids';
		}
		$reserve = (int) ( $s['reserve'] ?? 0 );
		return $reserve && (int) $s['current'] < $reserve ? 'reserve_not_met' : 'sold';
	}
}

if ( ! function_exists( 'db_auc_reserve_label' ) ) {
	/** What buyers see about the reserve. */
	function db_auc_reserve_label( array $s, $show = false ) {
		$reserve = (int) ( $s['reserve'] ?? 0 );
		if ( ! $reserve ) {
			return 'No reserve';
		}
		if ( (int) ( $s['current'] ?? 0 ) >= $reserve && (int) ( $s['bids'] ?? 0 ) > 0 ) {
			return 'Reserve met';
		}
		return $show ? 'Reserve $' . number_format( $reserve ) . ', not met yet' : 'Reserve not met';
	}
}

if ( ! function_exists( 'db_auc_bin_open' ) ) {
	/** Buy It Now is offered while the price is below it and the auction is live. */
	function db_auc_bin_open( array $s ) {
		$bin = (int) ( $s['bin'] ?? 0 );
		return $bin > 0 && (int) ( $s['current'] ?? 0 ) < $bin && ( (int) ( $s['leader_max'] ?? 0 ) < $bin );
	}
}

if ( ! function_exists( 'db_auc_aliases' ) ) {
	/**
	 * Public names for bidders in the order they first bid: "Bidder 1", "Bidder 2"...
	 *
	 * @param string[] $emails Bidder emails, oldest bid first (repeats allowed).
	 * @return array<string,string> email => alias
	 */
	function db_auc_aliases( array $emails ) {
		$out = array();
		foreach ( $emails as $e ) {
			if ( ! isset( $out[ $e ] ) ) {
				$out[ $e ] = 'Bidder ' . ( count( $out ) + 1 );
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'db_auc_time_left' ) ) {
	/** "2 days 4 hours", "3 hours 12 minutes", "45 seconds", or "Ended". */
	function db_auc_time_left( $ends, $now ) {
		$d = (int) $ends - (int) $now;
		if ( $d <= 0 ) {
			return 'Ended';
		}
		$days = intdiv( $d, 86400 );
		$hrs  = intdiv( $d % 86400, 3600 );
		$min  = intdiv( $d % 3600, 60 );
		$plural = static function ( $n, $w ) {
			return $n . ' ' . $w . ( 1 === $n ? '' : 's' );
		};
		if ( $days ) {
			return $plural( $days, 'day' ) . ( $hrs ? ' ' . $plural( $hrs, 'hour' ) : '' );
		}
		if ( $hrs ) {
			return $plural( $hrs, 'hour' ) . ( $min ? ' ' . $plural( $min, 'minute' ) : '' );
		}
		return $min ? $plural( $min, 'minute' ) : $plural( $d, 'second' );
	}
}

if ( ! function_exists( 'db_auc_next_chance' ) ) {
	/**
	 * The next bidder to offer the name to after a winner did not pay: the highest maximum
	 * among bidders not yet offered, at their own maximum, when it meets the reserve.
	 *
	 * @param array<string,int> $maxes   email => highest maximum.
	 * @param string[]          $skip    Emails already offered.
	 * @param int               $reserve Reserve (0 = none).
	 * @return array{0:string,1:int}|null
	 */
	function db_auc_next_chance( array $maxes, array $skip, $reserve ) {
		arsort( $maxes );
		foreach ( $maxes as $email => $max ) {
			if ( in_array( $email, $skip, true ) ) {
				continue;
			}
			if ( (int) $reserve && (int) $max < (int) $reserve ) {
				return null;
			}
			return array( (string) $email, (int) $max );
		}
		return null;
	}
}
