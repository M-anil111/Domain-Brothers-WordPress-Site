<?php
/**
 * Domain tools: five buyer-facing functions on real data.
 *
 *  1. Compare: /compare-domains/?d=a.com,b.ca shows up to four listings side
 *     by side (price, lowest monthly payment, length, extension, type,
 *     category, status). A "Compare" button on each domain page collects names
 *     in the browser.
 *  2. Price history: every price change of a published listing is recorded
 *     (table db_price_history) and listed on the domain page, with a "price
 *     reduced" note when the latest change was a drop. Nothing is back-filled.
 *  3. Recently viewed: the browser remembers the last eight domains opened
 *     (names and links only, never prices) and lists them on domain pages.
 *  4. Ways to pay: pay in full beside each plan term, with the monthly amount,
 *     interest, total, and the extra cost over paying in full, from the same
 *     functions the checkout charges with.
 *  5. Registry record: the year the name was first registered and its
 *     registrar, read from the public RDAP record, cached for 30 days, fetched
 *     in the background so a slow registry never slows a page.
 *
 * @package DomainBrothers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_TOOLS_TABLE_VERSION' ) ) {
	define( 'DB_TOOLS_TABLE_VERSION', '1' );
}
if ( ! defined( 'DB_TOOLS_COMPARE_MAX' ) ) {
	define( 'DB_TOOLS_COMPARE_MAX', 4 );
}

add_action( 'init', function () {
	if ( get_option( 'db_tools_table_version', '' ) === DB_TOOLS_TABLE_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$wpdb->prefix}db_price_history (
		id         BIGINT        NOT NULL AUTO_INCREMENT,
		post_id    BIGINT        NOT NULL,
		old_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
		new_price  DECIMAL(12,2) NOT NULL DEFAULT 0,
		changed_at DATETIME      NOT NULL,
		PRIMARY KEY (id),
		KEY idx_post (post_id, id)
	) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'db_tools_table_version', DB_TOOLS_TABLE_VERSION, false );
} );

/* ------------------------------------------------------ 2. price history */

if ( ! function_exists( 'db_tools_price_number' ) ) {
	function db_tools_price_number( $raw ) {
		$clean = preg_replace( '/[^0-9.]/', '', (string) $raw );
		return is_numeric( $clean ) ? (float) $clean : 0.0;
	}
}

if ( ! function_exists( 'db_tools_record_price' ) ) {
	/** Records a price change of a published listing. Returns true when a row was written. */
	function db_tools_record_price( $post_id, $key, $new, $old ) {
		if ( 'domain_price' !== $key ) {
			return false;
		}
		$post = get_post( (int) $post_id );
		if ( ! $post || 'domain' !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}
		$o = db_tools_price_number( $old );
		$n = db_tools_price_number( $new );
		if ( abs( $o - $n ) < 0.005 ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->insert( $wpdb->prefix . 'db_price_history', array(
			'post_id' => (int) $post_id, 'old_price' => $o, 'new_price' => $n, 'changed_at' => current_time( 'mysql', true ),
		), array( '%d', '%f', '%f', '%s' ) );
	}
}

add_filter( 'update_post_metadata', function ( $check, $post_id, $key, $value ) {
	if ( 'domain_price' === $key ) {
		db_tools_record_price( $post_id, $key, $value, get_post_meta( (int) $post_id, $key, true ) );
	}
	return $check;
}, 9, 4 );
add_filter( 'add_post_metadata', function ( $check, $post_id, $key, $value ) {
	if ( 'domain_price' === $key ) {
		db_tools_record_price( $post_id, $key, $value, '' );
	}
	return $check;
}, 9, 4 );

if ( ! function_exists( 'db_tools_price_rows' ) ) {
	function db_tools_price_rows( $post_id, $limit = 6 ) {
		global $wpdb;
		$t = $wpdb->prefix . 'db_price_history';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT old_price, new_price, changed_at FROM {$t} WHERE post_id = %d ORDER BY id DESC LIMIT %d", (int) $post_id, (int) $limit ) );
	}
}

if ( ! function_exists( 'db_tools_drop_percent' ) ) {
	/** Whole-percent drop from $old to $new, or 0 when it is not a drop between two real prices. */
	function db_tools_drop_percent( $old, $new ) {
		$old = (float) $old;
		$new = (float) $new;
		if ( $old <= 0 || $new <= 0 || $new >= $old ) {
			return 0;
		}
		return (int) floor( 100 * ( $old - $new ) / $old );
	}
}

if ( ! function_exists( 'db_ph_points' ) ) {
	/**
	 * Chronological [timestamp, price] points from history rows (newest first, as db_tools_price_rows
	 * returns them). The first point is the oldest "from" price, held for a week before the first
	 * change; the last repeats the current price at $now. Rows without a real price are skipped. Pure.
	 *
	 * @param array<int, object|array> $rows
	 * @return array<int, array{0:int, 1:float}>
	 */
	function db_ph_points( array $rows, $now ) {
		$rows = array_reverse( $rows );
		$pts  = array();
		foreach ( $rows as $i => $r ) {
			$r   = (array) $r;
			$at  = (int) strtotime( (string) $r['changed_at'] . ' UTC' );
			$old = (float) $r['old_price'];
			$new = (float) $r['new_price'];
			if ( 0 === $i && $old > 0 ) {
				$pts[] = array( $at - 7 * DAY_IN_SECONDS, $old );
			}
			if ( $new > 0 ) {
				$pts[] = array( $at, $new );
			}
		}
		if ( $pts ) {
			$last = end( $pts );
			if ( (int) $now > $last[0] ) {
				$pts[] = array( (int) $now, $last[1] );
			}
		}
		return $pts;
	}
}

if ( ! function_exists( 'db_ph_svg' ) ) {
	/**
	 * Step-line chart of a price over time as inline SVG. Empty unless the price really moved. Pure.
	 *
	 * @param array<int, array{0:int, 1:float}> $pts From db_ph_points().
	 */
	function db_ph_svg( array $pts ) {
		if ( count( $pts ) < 2 ) {
			return '';
		}
		$prices = array_column( $pts, 1 );
		$min    = min( $prices );
		$max    = max( $prices );
		if ( $max <= $min ) {
			return '';
		}
		$t0 = $pts[0][0];
		$t1 = $pts[ count( $pts ) - 1 ][0];
		if ( $t1 <= $t0 ) {
			return '';
		}
		$w = 600; $h = 220; $l = 96; $r = 16; $top = 18; $bot = 44;
		$x = function ( $t ) use ( $t0, $t1, $w, $l, $r ) { return round( $l + ( $t - $t0 ) / ( $t1 - $t0 ) * ( $w - $l - $r ), 1 ); };
		$y = function ( $p ) use ( $min, $max, $h, $top, $bot ) { return round( $top + ( 1 - ( $p - $min ) / ( $max - $min ) ) * ( $h - $top - $bot ), 1 ); };
		$d = 'M' . $x( $pts[0][0] ) . ' ' . $y( $pts[0][1] );
		for ( $i = 1; $i < count( $pts ); $i++ ) {
			$d .= ' H' . $x( $pts[ $i ][0] ) . ' V' . $y( $pts[ $i ][1] );
		}
		$money = function ( $n ) { return '$' . number_format( $n ); };
		$first = $pts[0][1];
		$last  = $pts[ count( $pts ) - 1 ][1];
		$label = 'Price history chart: from ' . $money( $first ) . ' on ' . gmdate( 'M j, Y', $t0 ) . ' to ' . $money( $last ) . ' on ' . gmdate( 'M j, Y', $t1 ) . '; lowest ' . $money( $min ) . ', highest ' . $money( $max ) . '.';
		$svg   = '<svg class="db-ph-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . htmlspecialchars( $label, ENT_QUOTES ) . '" style="width:100%;height:auto;max-width:640px;display:block">'
			. '<line x1="' . $l . '" x2="' . ( $w - $r ) . '" y1="' . $y( $max ) . '" y2="' . $y( $max ) . '" stroke="#e5e7eb"/>'
			. '<line x1="' . $l . '" x2="' . ( $w - $r ) . '" y1="' . $y( $min ) . '" y2="' . $y( $min ) . '" stroke="#e5e7eb"/>'
			. '<text x="' . ( $l - 10 ) . '" y="' . ( $y( $max ) + 7 ) . '" text-anchor="end" font-size="22" fill="#4b5563">' . $money( $max ) . '</text>'
			. '<text x="' . ( $l - 10 ) . '" y="' . ( $y( $min ) + 7 ) . '" text-anchor="end" font-size="22" fill="#4b5563">' . $money( $min ) . '</text>'
			. '<path d="' . $d . '" fill="none" stroke="#137a3e" stroke-width="2.5" stroke-linejoin="round"/>'
			. '<circle cx="' . $x( $t1 ) . '" cy="' . $y( $last ) . '" r="4.5" fill="#137a3e"/>'
			. '<text x="' . $l . '" y="' . ( $h - 12 ) . '" font-size="22" fill="#4b5563">' . gmdate( 'M j', $t0 ) . '</text>'
			. '<text x="' . ( $w - $r ) . '" y="' . ( $h - 12 ) . '" text-anchor="end" font-size="22" fill="#4b5563">' . gmdate( 'M j, Y', $t1 ) . '</text>'
			. '</svg>';
		return $svg;
	}
}

if ( ! function_exists( 'db_tools_price_history_html' ) ) {
	function db_tools_price_history_html( $post ) {
		$rows = db_tools_price_rows( $post->ID );
		if ( ! $rows ) {
			return '';
		}
		$money = function ( $n ) { return (float) $n > 0 ? '$' . number_format( (float) $n, 0 ) : 'Open to offers'; };
		$h     = '<section class="db-tools-block" aria-labelledby="db-ph-h"><h2 id="db-ph-h">Price history</h2>';
		$drop  = db_tools_drop_percent( $rows[0]->old_price, $rows[0]->new_price );
		if ( $drop > 0 && db_listing_is_sellable( $post ) ) {
			$h .= '<p class="db-tools-note"><strong>Price reduced ' . (int) $drop . '%</strong> on ' . esc_html( wp_date( 'F j, Y', strtotime( $rows[0]->changed_at . ' UTC' ) ) ) . ': from ' . esc_html( $money( $rows[0]->old_price ) ) . ' to ' . esc_html( $money( $rows[0]->new_price ) ) . '.</p>';
		}
		$h .= db_ph_svg( db_ph_points( $rows, time() ) );
		$h .= '<table class="db-tools-table"><thead><tr><th scope="col">Date</th><th scope="col">From</th><th scope="col">To</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$h .= '<tr><td>' . esc_html( wp_date( 'M j, Y', strtotime( $r->changed_at . ' UTC' ) ) ) . '</td><td>' . esc_html( $money( $r->old_price ) ) . '</td><td>' . esc_html( $money( $r->new_price ) ) . '</td></tr>';
		}
		return $h . '</tbody></table><p class="db-tools-small">Changes recorded by this site since November 2026.</p></section>';
	}
}

/* --------------------------------------------------------- 4. ways to pay */

if ( ! function_exists( 'db_tools_pay_rows' ) ) {
	/**
	 * Pay in full and every plan term for a price.
	 *
	 * @return array<int, array{months:int, rate:float, monthly:float, total:float, extra:float}>
	 */
	function db_tools_pay_rows( $price ) {
		$price = (float) $price;
		if ( $price <= 0 ) {
			return array();
		}
		$rows = array( array( 'months' => 0, 'rate' => 0.0, 'monthly' => $price, 'total' => $price, 'extra' => 0.0 ) );
		foreach ( (array) db_plan_terms() as $m ) {
			$m      = (int) $m;
			$total  = db_plan_total_cents( $price, $m ) / 100;
			$rows[] = array(
				'months'  => $m,
				'rate'    => (float) db_plan_rate( $m ),
				'monthly' => db_stripe_installment_cents( $price, $m, 1 ) / 100,
				'total'   => $total,
				'extra'   => round( $total - $price, 2 ),
			);
		}
		return $rows;
	}
}

if ( ! function_exists( 'db_tools_pay_html' ) ) {
	function db_tools_pay_html( $post, array $f ) {
		if ( 'standard' !== db_listing_state( $post ) ) {
			return '';
		}
		$rows = db_tools_pay_rows( $f['price'] );
		if ( ! $rows ) {
			return '';
		}
		$urls = db_dd_checkout_urls( get_the_title( $post ), $f['price'] );
		$m2   = function ( $n ) { return '$' . number_format( (float) $n, 2 ); };
		$h    = '<section class="db-tools-block" aria-labelledby="db-pay-h"><h2 id="db-pay-h">Ways to pay</h2>';
		// One-tap chooser above the table: pick an option, see the numbers, go to checkout with it.
		$h   .= '<div class="db-pay-pick" role="radiogroup" aria-label="Choose how to pay">';
		foreach ( $rows as $i => $r ) {
			$href  = 0 === $r['months'] ? $urls['buy'] : add_query_arg( 'months', (int) $r['months'], $urls['buy'] );
			$label = 0 === $r['months'] ? 'Pay in full' : $r['months'] . ' months';
			$h    .= '<button type="button" role="radio" aria-checked="' . ( 0 === $i ? 'true' : 'false' ) . '" class="db-pay-opt" data-href="' . esc_url( $href ) . '" data-each="' . esc_attr( $m2( $r['monthly'] ) ) . '" data-total="' . esc_attr( $m2( $r['total'] ) ) . '" data-extra="' . esc_attr( $r['extra'] > 0 ? $m2( $r['extra'] ) : 'None' ) . '" data-n="' . (int) $r['months'] . '">' . esc_html( $label ) . ( 0 !== $r['months'] && 0.0 === (float) $r['rate'] ? '<small>0% interest</small>' : '' ) . '</button>';
		}
		$first = $rows[0];
		$h   .= '</div><div class="db-pay-sum" aria-live="polite"><p><span class="db-pay-each">' . esc_html( $m2( $first['monthly'] ) ) . '</span> <span class="db-pay-unit">today</span></p><p class="db-pay-meta">Total <strong class="db-pay-total">' . esc_html( $m2( $first['total'] ) ) . '</strong> · Extra over paying in full <strong class="db-pay-extra">None</strong></p><a class="db-sales-btn db-pay-go" href="' . esc_url( $urls['buy'] ) . '">Continue to checkout</a></div>';
		$h   .= '<details class="db-pay-table"><summary>Compare every option</summary><div class="db-tools-scroll"><table class="db-tools-table"><thead><tr><th scope="col">Option</th><th scope="col">Each payment</th><th scope="col">Interest</th><th scope="col">Total</th><th scope="col">Extra over paying in full</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$label = 0 === $r['months'] ? 'Pay in full' : $r['months'] . ' monthly payments';
			$h    .= '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $m2( $r['monthly'] ) ) . '</td><td>' . esc_html( 0 === $r['months'] ? 'None' : rtrim( rtrim( number_format( $r['rate'], 1 ), '0' ), '.' ) . '%' ) . '</td><td>' . esc_html( $m2( $r['total'] ) ) . '</td><td>' . esc_html( $r['extra'] > 0 ? $m2( $r['extra'] ) : 'None' ) . '</td></tr>';
		}
		$h .= '</tbody></table></div><p class="db-tools-links"><a href="' . esc_url( $urls['buy'] ) . '">Pay in full</a> · <a href="' . esc_url( $urls['plan'] ) . '">Choose a payment plan</a></p>';
		return $h . '</details><p class="db-tools-small">The final payment of a plan can differ by a few cents so the payments add up to the total exactly.</p></section>';
	}
}

/* ------------------------------------------------------ 5. registry record */

if ( ! function_exists( 'db_tools_rdap_parse' ) ) {
	/**
	 * Pulls the registration year and registrar name out of an RDAP domain record.
	 *
	 * @return array{year:int, registrar:string}
	 */
	function db_tools_rdap_parse( $json ) {
		$out = array( 'year' => 0, 'registrar' => '' );
		$d   = is_array( $json ) ? $json : json_decode( (string) $json, true );
		if ( ! is_array( $d ) ) {
			return $out;
		}
		foreach ( (array) ( $d['events'] ?? array() ) as $e ) {
			if ( 'registration' === ( $e['eventAction'] ?? '' ) && preg_match( '/^(\d{4})-\d{2}-\d{2}/', (string) ( $e['eventDate'] ?? '' ), $m ) ) {
				$year = (int) $m[1];
				if ( $year >= 1985 && $year <= (int) gmdate( 'Y' ) ) {
					$out['year'] = $year;
				}
			}
		}
		foreach ( (array) ( $d['entities'] ?? array() ) as $ent ) {
			if ( ! in_array( 'registrar', (array) ( $ent['roles'] ?? array() ), true ) ) {
				continue;
			}
			foreach ( (array) ( $ent['vcardArray'][1] ?? array() ) as $v ) {
				if ( 'fn' === ( $v[0] ?? '' ) && is_string( $v[3] ?? null ) ) {
					$out['registrar'] = trim( wp_strip_all_tags( $v[3] ) );
				}
			}
		}
		$out['registrar'] = mb_substr( $out['registrar'], 0, 80 );
		return $out;
	}
}

if ( ! function_exists( 'db_tools_valid_domain' ) ) {
	function db_tools_valid_domain( $name ) {
		$name = strtolower( trim( (string) $name ) );
		return preg_match( '/^(?=.{4,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,24}$/', $name ) ? $name : '';
	}
}

if ( ! function_exists( 'db_tools_rdap_fetch' ) ) {
	/**
	 * Reads the public RDAP record of one listed domain and stores the result.
	 * A record is kept 30 days; a failed lookup keeps its reason and is retried
	 * after a day. Returns the stored record.
	 */
	function db_tools_rdap_fetch( $post_id, $timeout = 8 ) {
		$post = get_post( (int) $post_id );
		$name = $post && 'domain' === $post->post_type ? db_tools_valid_domain( $post->post_title ) : '';
		if ( '' === $name ) {
			return array();
		}
		// A registry that rate-limited us recently is left alone until its pause ends.
		$pause = (int) get_option( 'db_rdap_pause_until', 0 );
		if ( $pause > time() ) {
			return array( 'year' => 0, 'registrar' => '', 'paused' => $pause );
		}
		$res  = wp_remote_get( 'https://rdap.org/domain/' . rawurlencode( $name ), array(
			'timeout' => (int) $timeout, 'redirection' => 3, 'headers' => array( 'Accept' => 'application/rdap+json' ),
		) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( 429 === $code ) {
			// Rate limited: keep any earlier record, honour Retry-After (60 s to 1 h,
			// 15 min when absent) for every lookup, and retry this listing after it.
			$after = (int) wp_remote_retrieve_header( $res, 'retry-after' );
			$after = $after > 0 ? min( HOUR_IN_SECONDS, max( MINUTE_IN_SECONDS, $after ) ) : 15 * MINUTE_IN_SECONDS;
			update_option( 'db_rdap_pause_until', time() + $after, false );
			update_post_meta( $post->ID, 'db_rdap_until', time() + $after + MINUTE_IN_SECONDS );
			$old = get_post_meta( $post->ID, 'db_rdap', true );
			if ( ! is_array( $old ) || empty( $old['year'] ) ) {
				update_post_meta( $post->ID, 'db_rdap', array( 'year' => 0, 'registrar' => '', 'checked' => time(), 'error' => 'registry is rate limiting lookups; retried automatically' ) );
			}
			return array( 'year' => 0, 'registrar' => '', 'paused' => time() + $after );
		}
		$data = 200 === $code ? db_tools_rdap_parse( wp_remote_retrieve_body( $res ) ) : array( 'year' => 0, 'registrar' => '' );
		$data['checked'] = time();
		$retry = DAY_IN_SECONDS;
		if ( ! $data['year'] ) {
			if ( 404 === $code ) {
				// The registry holds no record: the name is not currently registered.
				$data['error'] = 'not registered at the registry (HTTP 404); check that the name is still held';
				$retry         = 7 * DAY_IN_SECONDS;
			} else {
				$data['error'] = is_wp_error( $res ) ? mb_substr( $res->get_error_message(), 0, 160 ) : ( 200 === $code ? 'no registration date in the record' : 'registry answered HTTP ' . $code );
			}
		}
		update_post_meta( $post->ID, 'db_rdap', $data );
		update_post_meta( $post->ID, 'db_rdap_until', time() + ( $data['year'] ? 30 * DAY_IN_SECONDS : $retry ) );
		return $data;
	}
}
add_action( 'db_tools_rdap_fetch', 'db_tools_rdap_fetch' );

// Saving a published listing reads its record at once, so it does not wait for cron.
add_action( 'save_post_domain', function ( $post_id, $post ) {
	if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status || (int) get_post_meta( $post_id, 'db_rdap_until', true ) > time() ) {
		return;
	}
	db_tools_rdap_fetch( $post_id, 6 );
}, 30, 2 );


if ( ! function_exists( 'db_tools_rdap_html' ) ) {
	function db_tools_rdap_html( $post ) {
		$until = (int) get_post_meta( $post->ID, 'db_rdap_until', true );
		if ( $until < time() && ! wp_next_scheduled( 'db_tools_rdap_fetch', array( (int) $post->ID ) ) ) {
			wp_schedule_single_event( time() + 5, 'db_tools_rdap_fetch', array( (int) $post->ID ) );
		}
		$d = get_post_meta( $post->ID, 'db_rdap', true );
		if ( ! is_array( $d ) || empty( $d['year'] ) ) {
			return '';
		}
		$age = max( 0, (int) gmdate( 'Y' ) - (int) $d['year'] );
		$h   = '<section class="db-tools-block" aria-labelledby="db-reg-h"><h2 id="db-reg-h">Registry record</h2><dl class="db-tools-facts">';
		$h  .= '<div><dt>First registered</dt><dd>' . (int) $d['year'] . ( $age >= 1 ? ' (' . (int) $age . ' ' . ( 1 === $age ? 'year' : 'years' ) . ' ago)' : '' ) . '</dd></div>';
		if ( ! empty( $d['registrar'] ) ) {
			$h .= '<div><dt>Current registrar</dt><dd>' . esc_html( $d['registrar'] ) . '</dd></div>';
		}
		return $h . '</dl><p class="db-tools-small">From the public registration record (RDAP), checked ' . esc_html( wp_date( 'F j, Y', (int) ( $d['checked'] ?? time() ) ) ) . '.</p></section>';
	}
}

/* --------------------------------------------------------------- 1. compare */

if ( ! function_exists( 'db_tools_compare_names' ) ) {
	/** Cleans a comma-separated list: valid, unique, lower-case names, at most four. */
	function db_tools_compare_names( $raw ) {
		$out = array();
		foreach ( explode( ',', (string) $raw ) as $part ) {
			$name = db_tools_valid_domain( $part );
			if ( '' !== $name && ! in_array( $name, $out, true ) ) {
				$out[] = $name;
			}
			if ( count( $out ) >= DB_TOOLS_COMPARE_MAX ) {
				break;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'db_tools_compare_body' ) ) {
	function db_tools_compare_body() {
		$names = db_tools_compare_names( isset( $_GET['d'] ) ? sanitize_text_field( wp_unslash( $_GET['d'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$cols  = array();
		foreach ( $names as $n ) {
			$p = db_listing_post_for_domain( $n );
			if ( $p ) {
				$cols[] = array( 'post' => $p, 'f' => db_dd_facts( $p ), 'state' => db_listing_state( $p ) );
			}
		}
		$h = '<p class="db-sales-lead">Compare up to ' . (int) DB_TOOLS_COMPARE_MAX . ' domains side by side. Open a domain and press <strong>Compare</strong> to add it.</p>';
		if ( ! $cols ) {
			return $h . '<div class="db-sales-card"><h2>Nothing to compare yet</h2><p>Add domains from their pages, or <a href="' . esc_url( home_url( '/all-domains/' ) ) . '">browse all domains</a>.</p></div><div id="db-cmp-empty"></div>';
		}
		$states = function_exists( 'db_listing_states' ) ? db_listing_states() : array();
		$line   = function ( $label, $fn ) use ( $cols ) {
			$r = '<tr><th scope="row">' . esc_html( $label ) . '</th>';
			foreach ( $cols as $c ) {
				$r .= '<td>' . $fn( $c ) . '</td>';
			}
			return $r . '</tr>';
		};
		$h .= '<div class="db-tools-scroll"><table class="db-tools-table db-tools-compare"><thead><tr><th scope="col">Domain</th>';
		foreach ( $cols as $c ) {
			$h .= '<th scope="col"><a href="' . esc_url( get_permalink( $c['post'] ) ) . '">' . esc_html( $c['f']['name'] ) . '</a></th>';
		}
		$h .= '</tr></thead><tbody>';
		$h .= $line( 'Price', function ( $c ) { return 'standard' === $c['state'] && $c['f']['price'] > 0 ? esc_html( '$' . number_format( $c['f']['price'], 0 ) ) : ( 'offer' === $c['state'] ? 'Open to offers' : '&mdash;' ); } );
		$h .= $line( 'Lowest monthly payment', function ( $c ) { return 'standard' === $c['state'] && $c['f']['price'] > 0 ? esc_html( db_dd_monthly( $c['f']['price'] ) . '/mo over 12 months' ) : '&mdash;'; } );
		$h .= $line( 'Status', function ( $c ) use ( $states ) { return esc_html( $states[ $c['state'] ] ?? $c['state'] ); } );
		$h .= $line( 'Length', function ( $c ) { return (int) $c['f']['len'] . ' characters'; } );
		$h .= $line( 'Extension', function ( $c ) { return esc_html( $c['f']['ext'] ); } );
		$h .= $line( 'Type', function ( $c ) { return esc_html( ucfirst( $c['f']['type'] ) ); } );
		$h .= $line( 'Category', function ( $c ) { return $c['f']['cats'] ? esc_html( implode( ', ', $c['f']['cats'] ) ) : '&mdash;'; } );
		$h .= $line( 'First registered', function ( $c ) { $d = get_post_meta( $c['post']->ID, 'db_rdap', true ); return is_array( $d ) && ! empty( $d['year'] ) ? (int) $d['year'] : '&mdash;'; } );
		$h .= $line( '', function ( $c ) use ( $names ) {
			$rest = array_values( array_diff( $names, array( $c['f']['name'] ) ) );
			$url  = $rest ? add_query_arg( 'd', implode( ',', $rest ), home_url( '/compare-domains/' ) ) : home_url( '/compare-domains/' );
			return '<a class="db-sales-btn" href="' . esc_url( get_permalink( $c['post'] ) ) . '">View</a> <a class="db-tools-remove" data-cmp-remove="' . esc_attr( $c['f']['name'] ) . '" href="' . esc_url( $url ) . '">Remove</a>';
		} );
		return $h . '</tbody></table></div>';
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) || 'compare-domains' !== db_sales_request_path() ) {
		return;
	}
	db_sales_render( 'Compare domains', 'Compare domains', db_tools_css() . db_tools_compare_body(), true );
}, 27 );

/* -------------------------------------------------- shared markup and script */

if ( ! function_exists( 'db_tools_css' ) ) {
	function db_tools_css() {
		return '<style>.db-tools-block{margin:28px 0}.db-tools-block h2{font-size:22px;margin:0 0 10px;color:#0a1628}'
			. '.db-tools-scroll{overflow-x:auto}.db-tools-table{width:100%;border-collapse:collapse;font-size:15px}'
			. '.db-tools-table th,.db-tools-table td{padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}'
			. '.db-tools-table thead th{background:#f8fafc;color:#0a1628;font-weight:700;white-space:nowrap}'
			. '.db-tools-compare td,.db-tools-compare thead th{min-width:150px}'
			. '.db-tools-note{border-left:3px solid #137a3e;background:#f1fbf3;padding:8px 12px;margin:0 0 12px}'
			. '.db-tools-small{font-size:13px;color:#4b5563;margin:8px 0 0}'
			. '.db-tools-links a,.db-tools-remove{display:inline-flex;align-items:center;min-height:44px}'
			. '.db-tools-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin:0}'
			. '.db-tools-facts div{border:1px solid #d7dee8;border-radius:10px;padding:10px 12px}.db-tools-facts dt{font-weight:700}.db-tools-facts dd{margin:4px 0 0}'
			. '.db-tools-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:18px 0}'
			. '.db-tools-btn{display:inline-flex;align-items:center;min-height:44px;padding:8px 16px;border:1px solid #0a1628;border-radius:10px;background:#fff;color:#0a1628;font:inherit;font-weight:700;cursor:pointer;text-decoration:none}'
			. '.db-tools-btn[aria-pressed="true"]{background:#0a1628;color:#fff}a.db-sales-btn,a.db-sales-btn:visited,a.db-sales-btn:hover{color:#fff!important}'
			. '.db-tools-rv{list-style:none;padding:0;margin:0;display:flex;flex-wrap:wrap;gap:8px}'
			. '.db-tools-sim{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px}.db-tools-sim li{border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:2px}.db-tools-sim a{font-weight:700;color:#0a1628;overflow-wrap:anywhere}'
			. '.db-tools-rv a{display:inline-flex;align-items:center;min-height:44px;padding:6px 14px;border:1px solid #cbd5e1;border-radius:999px;color:#0a1628;text-decoration:none;overflow-wrap:anywhere}'
			. '.db-pay-pick{display:flex;flex-wrap:wrap;gap:8px;margin:4px 0 14px}'
			. '.db-pay-opt{display:inline-flex;flex-direction:column;align-items:center;justify-content:center;min-height:52px;min-width:92px;padding:6px 14px;border:1.5px solid #cbd5e1;border-radius:14px;background:#fff;color:#0a1628;font:inherit;font-weight:700;cursor:pointer;transition:border-color .15s,background .15s}'
			. '.db-pay-opt small{font-size:11px;font-weight:600;color:#137a3e}'
			. '.db-pay-opt[aria-checked="true"]{border-color:#0a1628;background:#0a1628;color:#fff}.db-pay-opt[aria-checked="true"] small{color:#c8e67a}'
			. '.db-pay-opt:focus-visible,.db-sticky-btn:focus-visible{outline:3px solid #94bf1e;outline-offset:2px}'
			. '.db-pay-sum{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;padding:16px 18px;border-radius:16px;background:linear-gradient(135deg,#f4f8ec,#eef3f9);border:1px solid #d7dee8}'
			. '.db-pay-sum p{margin:0}.db-pay-each{font-size:28px;font-weight:800;color:#0a1628;font-variant-numeric:tabular-nums}.db-pay-unit{color:#4b5563}'
			. '.db-pay-meta{flex-basis:100%;order:2;font-size:14px;color:#374151}.db-pay-go{order:1}'
			. '.db-pay-table{margin-top:12px}.db-pay-table summary{cursor:pointer;min-height:44px;display:flex;align-items:center;font-weight:700;color:#0a1628}'
			. '.db-sticky{position:fixed;left:0;right:0;bottom:0;z-index:9990;padding:10px 12px calc(10px + env(safe-area-inset-bottom));background:rgba(10,22,40,.97);color:#fff;box-shadow:0 -8px 24px rgba(10,22,40,.25);transform:translateY(110%);transition:transform .25s ease}'
			. '.db-sticky:not(.is-on){visibility:hidden;transition:transform .25s ease,visibility 0s linear .25s}.db-sticky.is-on{transform:translateY(0)}.db-sticky-in{max-width:1100px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:12px}'
			. '.db-sticky-txt{display:flex;flex-direction:column;min-width:0}.db-sticky-txt strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.db-sticky-txt span{font-size:13px;color:#c9d4e3}'
			. '.db-sticky-act{display:flex;gap:8px;flex:0 0 auto}.db-sticky-btn{display:inline-flex;align-items:center;min-height:44px;padding:0 18px;border-radius:999px;background:#94bf1e;color:#0a1628;font-weight:800;text-decoration:none;white-space:nowrap}'
			. '.db-sticky-btn--ghost{background:transparent;color:#fff;border:1px solid rgba(255,255,255,.4)}'
			. '@media (max-width:480px){.db-sticky-btn--ghost{display:none}}'
			. '@media (prefers-reduced-motion:reduce){.db-sticky,.db-pay-opt{transition:none}}@media print{.db-sticky{display:none!important}}'
			. '</style>';
	}
}

if ( ! function_exists( 'db_tools_sticky_html' ) ) {
	/** Bottom bar with the price and the main action, shown once the page's own buttons scroll away. */
	function db_tools_sticky_html( $post, array $f ) {
		$state = db_listing_state( $post );
		if ( ! in_array( $state, array( 'standard', 'offer' ), true ) ) {
			return '';
		}
		$h = '<div class="db-sticky" id="db-sticky" hidden><div class="db-sticky-in"><div class="db-sticky-txt"><strong>' . esc_html( $f['name'] ) . '</strong><span>';
		if ( 'standard' === $state && $f['price'] > 0 ) {
			$urls = db_dd_checkout_urls( get_the_title( $post ), $f['price'] );
			$h   .= esc_html( '$' . number_format( $f['price'], 0 ) . ' or ' . db_dd_monthly( $f['price'] ) . '/mo' ) . '</span></div><div class="db-sticky-act"><a class="db-sticky-btn db-sticky-btn--ghost" href="#db-pay-h">Ways to pay</a><a class="db-sticky-btn" href="' . esc_url( $urls['buy'] ) . '">Buy now</a>';
		} else {
			$h .= 'Open to offers</span></div><div class="db-sticky-act"><a class="db-sticky-btn" href="#offer" data-sticky-offer>Make an offer</a>';
		}
		return $h . '</div></div></div>';
	}
}

/* ------------------------------------------------------ similar names */

if ( ! function_exists( 'db_sim_score' ) ) {
	/**
	 * How alike two names are for a buyer: same extension, close length, close price, shared category.
	 * Pure function. Both arguments use the db_dd_facts() shape (name, ext, len, price, cats).
	 */
	function db_sim_score( array $a, array $b ) {
		if ( strtolower( (string) $a['name'] ) === strtolower( (string) $b['name'] ) ) {
			return -1.0;
		}
		$score  = ( $a['ext'] === $b['ext'] ) ? 3.0 : 0.0;
		$score += max( 0.0, 2.0 - abs( (int) $a['len'] - (int) $b['len'] ) * 0.5 );
		if ( (float) $a['price'] > 0 && (float) $b['price'] > 0 ) {
			$ratio  = max( (float) $a['price'], (float) $b['price'] ) / min( (float) $a['price'], (float) $b['price'] );
			$score += max( 0.0, 3.0 - ( $ratio - 1.0 ) * 1.5 );
		}
		$shared = array_intersect( array_map( 'strtolower', (array) $a['cats'] ), array_map( 'strtolower', (array) $b['cats'] ) );
		return $score + min( 3.0, count( $shared ) * 1.5 );
	}
}

if ( ! function_exists( 'db_sim_pick' ) ) {
	/** Best $n candidates by score; ties break alphabetically so output is stable. Scores of 3 or less are dropped. */
	function db_sim_pick( array $cur, array $cands, $n = 6 ) {
		$scored = array();
		foreach ( $cands as $c ) {
			$s = db_sim_score( $cur, $c );
			if ( $s > 3.0 ) {
				$scored[] = array( 's' => $s, 'c' => $c );
			}
		}
		usort( $scored, function ( $x, $y ) {
			return $y['s'] <=> $x['s'] ?: strcmp( (string) $x['c']['name'], (string) $y['c']['name'] );
		} );
		return array_slice( wp_list_pluck( $scored, 'c' ), 0, max( 0, (int) $n ) );
	}
}

if ( ! function_exists( 'db_sim_html' ) ) {
	function db_sim_html( $post, array $f ) {
		$ids = get_posts( array(
			'post_type' => 'domain', 'post_status' => 'publish', 'posts_per_page' => 80, 'fields' => 'ids',
			'no_found_rows' => true, 'post__not_in' => array( $post->ID ), 'orderby' => 'ID', 'order' => 'DESC',
		) );
		$cands = array();
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( ! $p || 'standard' !== db_listing_state( $p ) ) {
				continue;
			}
			$c         = db_dd_facts( $p );
			$c['id']   = (int) $id;
			$cands[]   = $c;
		}
		$pick = db_sim_pick( $f, $cands, 6 );
		if ( count( $pick ) < 3 ) {
			return '';
		}
		$h = '<section class="db-tools-block" aria-labelledby="db-sim-h"><h2 id="db-sim-h">Similar names</h2><ul class="db-tools-sim">';
		foreach ( $pick as $c ) {
			$rows = db_tools_pay_rows( $c['price'] );
			$last = $rows ? end( $rows ) : null;
			$cost = '$' . number_format( (float) $c['price'] );
			if ( $last && $last['months'] > 0 ) {
				$cost .= ' · from $' . number_format( $last['monthly'] ) . '/mo';
			}
			$h .= '<li><a href="' . esc_url( get_permalink( $c['id'] ) ) . '">' . esc_html( $c['name'] ) . '</a> <span class="db-tools-small">' . esc_html( $cost ) . '</span></li>';
		}
		return $h . '</ul></section>';
	}
}

/* ------------------------------------------------------ recently sold */

if ( ! function_exists( 'db_rsold_pick' ) ) {
	/**
	 * Newest sales first (sale date, then ID), unique by name. Names only: prices and buyers stay private.
	 *
	 * @param array<int, array{id:int, name:string, at:int}> $rows
	 * @return array<int, array{id:int, name:string, at:int}>
	 */
	function db_rsold_pick( array $rows, $n = 6 ) {
		usort( $rows, function ( $a, $b ) {
			return ( (int) $b['at'] <=> (int) $a['at'] ) ?: ( (int) $b['id'] <=> (int) $a['id'] );
		} );
		$seen = array();
		$out  = array();
		foreach ( $rows as $r ) {
			$k = strtolower( trim( (string) $r['name'] ) );
			if ( '' === $k || isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$out[]      = $r;
		}
		return array_slice( $out, 0, max( 0, (int) $n ) );
	}
}

if ( ! function_exists( 'db_rsold_html' ) ) {
	function db_rsold_html( $post ) {
		$ids = get_posts( array(
			'post_type' => 'domain', 'post_status' => 'publish', 'posts_per_page' => 40, 'fields' => 'ids',
			'no_found_rows' => true, 'post__not_in' => array( $post->ID ), 'orderby' => 'ID', 'order' => 'DESC',
			'meta_query' => array( 'relation' => 'OR',
				array( 'key' => 'db_listing_state', 'value' => 'sold' ),
				array( 'key' => 'domain_status', 'value' => 'sold', 'compare' => 'LIKE' ),
			),
		) );
		$rows = array();
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			if ( $p && 'sold' === db_listing_state( $p ) ) {
				$rows[] = array( 'id' => (int) $id, 'name' => strtolower( trim( wp_strip_all_tags( $p->post_title ) ) ), 'at' => (int) get_post_meta( $id, 'db_sold_at', true ) );
			}
		}
		$pick = db_rsold_pick( $rows, 6 );
		if ( count( $pick ) < 3 ) {
			return '';
		}
		$h = '<section class="db-tools-block" aria-labelledby="db-rsold-h"><h2 id="db-rsold-h">Recently sold</h2><ul class="db-tools-sim">';
		foreach ( $pick as $r ) {
			$h .= '<li><span>' . esc_html( $r['name'] ) . '</span> <span class="db-tools-small">Sold</span></li>';
		}
		return $h . '</ul><p class="db-tools-links"><a href="' . esc_url( home_url( '/sold/' ) ) . '">See every sale</a></p></section>';
	}
}

if ( ! function_exists( 'db_tools_domain_html' ) ) {
	/** Everything the domain page gets from this module, in reading order. */
	function db_tools_domain_html( $post ) {
		$f    = db_dd_facts( $post );
		$name = $f['name'];
		$h    = db_tools_css();
		$h   .= '<div class="db-tools-actions" data-db-tools data-name="' . esc_attr( $name ) . '" data-url="' . esc_url( get_permalink( $post ) ) . '" data-compare="' . esc_url( home_url( '/compare-domains/' ) ) . '" data-max="' . (int) DB_TOOLS_COMPARE_MAX . '">'
			. '<button type="button" class="db-tools-btn" id="db-cmp-toggle" aria-pressed="false">Compare</button>'
			. '<a class="db-tools-btn" id="db-cmp-open" href="' . esc_url( home_url( '/compare-domains/' ) ) . '" hidden>Open comparison</a>'
			. '<span id="db-cmp-status" role="status" class="db-tools-small"></span></div>';
		$h   .= db_tools_pay_html( $post, $f );
		$h   .= db_tools_sticky_html( $post, $f );
		$h   .= db_tools_price_history_html( $post );
		$h   .= db_tools_rdap_html( $post );
		$h   .= db_sim_html( $post, $f );
		$h   .= db_rsold_html( $post );
		$h   .= '<section class="db-tools-block" id="db-rv" aria-labelledby="db-rv-h" hidden><h2 id="db-rv-h">Recently viewed</h2><ul class="db-tools-rv" id="db-rv-list"></ul></section>';
		return $h;
	}
}

add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	$on_domain  = is_singular( 'domain' );
	$on_compare = function_exists( 'db_sales_request_path' ) && 'compare-domains' === db_sales_request_path();
	if ( ! $on_domain && ! $on_compare ) {
		return;
	}
	?>
<script>
(function () {
	function read(key) { try { var v = JSON.parse(localStorage.getItem(key) || '[]'); return Array.isArray(v) ? v : []; } catch (e) { return []; } }
	function write(key, v) { try { localStorage.setItem(key, JSON.stringify(v)); } catch (e) {} }
	var ok = /^[a-z0-9][a-z0-9.-]{2,252}$/;
	var box = document.querySelector('[data-db-tools]');
	if (box) {
		var name = box.getAttribute('data-name'), url = box.getAttribute('data-url');
		var max = parseInt(box.getAttribute('data-max'), 10) || 4, base = box.getAttribute('data-compare');
		var btn = document.getElementById('db-cmp-toggle'), open = document.getElementById('db-cmp-open'), status = document.getElementById('db-cmp-status');
		var paint = function (msg) {
			var list = read('dbCompare').filter(function (n) { return ok.test(n); });
			var on = list.indexOf(name) !== -1;
			btn.setAttribute('aria-pressed', on ? 'true' : 'false');
			btn.textContent = on ? 'Added to compare' : 'Compare';
			open.hidden = list.length === 0;
			open.href = base + '?d=' + encodeURIComponent(list.join(','));
			open.textContent = 'Open comparison (' + list.length + ')';
			status.textContent = msg || '';
		};
		btn.addEventListener('click', function () {
			var list = read('dbCompare').filter(function (n) { return ok.test(n); });
			var i = list.indexOf(name), msg = '';
			if (i !== -1) { list.splice(i, 1); msg = 'Removed from compare.'; }
			else if (list.length >= max) { msg = 'You can compare ' + max + ' domains. Remove one first.'; }
			else { list.push(name); msg = 'Added to compare.'; }
			write('dbCompare', list);
			paint(msg);
		});
		paint('');

		var seen = read('dbViewed').filter(function (x) { return x && ok.test(x.n || '') && /^https?:\/\//.test(x.u || ''); });
		var others = seen.filter(function (x) { return x.n !== name; });
		var listEl = document.getElementById('db-rv-list'), sec = document.getElementById('db-rv');
		if (others.length && listEl && sec) {
			others.slice(0, 7).forEach(function (x) {
				var li = document.createElement('li'), a = document.createElement('a');
				if (x.u.indexOf(location.origin + '/') !== 0) { return; }
				a.href = x.u; a.textContent = x.n; li.appendChild(a); listEl.appendChild(li);
			});
			sec.hidden = listEl.children.length === 0;
		}
		write('dbViewed', [{ n: name, u: url }].concat(others).slice(0, 8));
	}
	var pick = document.querySelector('.db-pay-pick'), sum = document.querySelector('.db-pay-sum');
	if (pick && sum) {
		var opts = Array.prototype.slice.call(pick.querySelectorAll('.db-pay-opt')), go = sum.querySelector('.db-pay-go');
		var choose = function (b, focus) {
			opts.forEach(function (x) { var on = x === b; x.setAttribute('aria-checked', on ? 'true' : 'false'); x.tabIndex = on ? 0 : -1; });
			var n = parseInt(b.getAttribute('data-n'), 10) || 0;
			sum.querySelector('.db-pay-each').textContent = b.getAttribute('data-each');
			sum.querySelector('.db-pay-unit').textContent = n ? 'a month for ' + n + ' months' : 'today';
			sum.querySelector('.db-pay-total').textContent = b.getAttribute('data-total');
			sum.querySelector('.db-pay-extra').textContent = b.getAttribute('data-extra');
			go.href = b.getAttribute('data-href');
			go.textContent = n ? 'Continue with ' + n + ' monthly payments' : 'Continue to checkout';
			if (focus) { b.focus(); }
		};
		opts.forEach(function (b, i) { b.tabIndex = i === 0 ? 0 : -1; });
		pick.addEventListener('click', function (e) { var b = e.target.closest('.db-pay-opt'); if (b) { choose(b); } });
		pick.addEventListener('keydown', function (e) {
			var i = opts.indexOf(document.activeElement); if (i === -1) { return; }
			var k = e.key, j = k === 'ArrowRight' || k === 'ArrowDown' ? (i + 1) % opts.length : k === 'ArrowLeft' || k === 'ArrowUp' ? (i - 1 + opts.length) % opts.length : -1;
			if (j !== -1) { e.preventDefault(); choose(opts[j], true); }
		});
	}
	var sticky = document.getElementById('db-sticky');
	if (sticky) {
		// The buy area can be moved or revealed by other scripts after this one runs,
		// so it is looked up on every (throttled) scroll instead of once.
		var findAnchor = function () { return ['.db-pr-pay', '.domain-detail-form.make_an_offer', '.db-dd-cta', '.db-dd'].map(function (q) { return document.querySelector(q); }).filter(function (e) { return e && e.offsetParent; })[0]; };
		var ticking = false;
		var update = function () {
			ticking = false;
			var a = findAnchor(), past = !!a && a.getBoundingClientRect().bottom < 0;
			sticky.classList.toggle('is-on', past);
			if (past) { sticky.removeAttribute('inert'); } else { sticky.setAttribute('inert', ''); }
		};
		sticky.hidden = false;
		sticky.setAttribute('inert', '');
		window.addEventListener('scroll', function () { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });
		window.addEventListener('resize', update);
		window.addEventListener('load', update);
		update();
		var offer = sticky.querySelector('[data-sticky-offer]');
		if (offer) {
			offer.addEventListener('click', function (e) {
				var f = document.querySelector('.domain-detail-form.make_an_offer') || document.querySelector('.wpcf7');
				if (!f) { return; }
				e.preventDefault();
				f.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
				var first = f.querySelector('input:not([type=hidden]), textarea'); if (first) { setTimeout(function () { first.focus({ preventScroll: true }); }, 400); }
			});
		}
	}
	document.addEventListener('click', function (e) {
		var a = e.target.closest && e.target.closest('[data-cmp-remove]');
		if (!a) { return; }
		var gone = a.getAttribute('data-cmp-remove');
		write('dbCompare', read('dbCompare').filter(function (n) { return n !== gone; }));
	});
	if (document.getElementById('db-cmp-empty') && location.search.indexOf('d=') === -1) {
		var saved = read('dbCompare').filter(function (n) { return ok.test(n); });
		if (saved.length) { location.replace(location.pathname + '?d=' + encodeURIComponent(saved.join(','))); }
	}
})();
</script>
	<?php
} );

/* -------------------------------------------- admin: registry record status */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Registry records', 'Registry records', 'manage_options', 'db-rdap', 'db_tools_rdap_admin_page' );
}, 31 );

if ( ! function_exists( 'db_tools_rdap_pending' ) ) {
	/** Published listings whose record is missing or due for a refresh. */
	function db_tools_rdap_pending( $limit ) {
		$q = new WP_Query( array(
			'post_type' => 'domain', 'post_status' => 'publish', 'posts_per_page' => (int) $limit, 'fields' => 'ids', 'no_found_rows' => true,
			'meta_query' => array( 'relation' => 'OR',
				array( 'key' => 'db_rdap_until', 'compare' => 'NOT EXISTS' ),
				array( 'key' => 'db_rdap_until', 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC' ),
			),
		) );
		return array_map( 'intval', $q->posts );
	}
}

// Hourly sweep: ten listings per run, spaced out, so every listing gets a record
// without anyone pressing the batch button. Stops when the registry rate-limits.
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'db_tools_rdap_sweep' ) ) {
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'db_tools_rdap_sweep' );
	}
} );
add_action( 'db_tools_rdap_sweep', function () {
	$start = time();
	foreach ( db_tools_rdap_pending( 10 ) as $i => $id ) {
		if ( time() - $start > 20 ) {
			break;
		}
		if ( $i ) {
			usleep( 1000000 );
		}
		$r = db_tools_rdap_fetch( $id, 6 );
		if ( ! empty( $r['paused'] ) ) {
			break;
		}
	}
} );

add_action( 'admin_post_db_tools_rdap_batch', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_tools_rdap_batch' );
	$done = 0;
	$start = time();
	foreach ( db_tools_rdap_pending( 15 ) as $id ) {
		if ( time() - $start > 20 ) {
			break;
		}
		if ( $done ) {
			usleep( 700000 ); // Spaced out so the registry does not rate-limit the batch.
		}
		$r = db_tools_rdap_fetch( $id, 6 );
		if ( ! empty( $r['paused'] ) ) {
			break;
		}
		$done++;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=db-rdap&done=' . $done ) );
	exit;
} );

if ( ! function_exists( 'db_tools_rdap_admin_page' ) ) {
	function db_tools_rdap_admin_page() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'domain' AND post_status = 'publish'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows  = (array) $wpdb->get_results( "SELECT p.post_title, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = 'db_rdap' AND p.post_type = 'domain' AND p.post_status = 'publish'" );
		$ok    = 0;
		$fails = array();
		foreach ( $rows as $r ) {
			$d = maybe_unserialize( $r->meta_value );
			if ( is_array( $d ) && ! empty( $d['year'] ) ) {
				$ok++;
			} elseif ( is_array( $d ) ) {
				$fails[] = array( strtolower( $r->post_title ), (string) ( $d['error'] ?? 'unknown' ) );
			}
		}
		echo '<div class="wrap"><h1>Registry records</h1>';
		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success"><p>Checked ' . (int) $_GET['done'] . ' listings.</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<p>Domain pages show the year a name was first registered and its registrar, from the public RDAP record (looked up through rdap.org). Records refresh every 30 days in the background, and when a listing is saved.</p>';
		echo '<p><strong>' . (int) $ok . '</strong> of ' . (int) $total . ' published listings have a record. <strong>' . count( $fails ) . '</strong> lookups failed (retried automatically).</p>';
		$pause = (int) get_option( 'db_rdap_pause_until', 0 );
		if ( $pause > time() ) {
			echo '<div class="notice notice-warning inline"><p>The registry asked us to slow down. Lookups resume in about ' . (int) ceil( ( $pause - time() ) / 60 ) . ' minutes.</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'db_tools_rdap_batch' );
		echo '<input type="hidden" name="action" value="db_tools_rdap_batch"><button class="button button-primary">Check the next 15 listings now</button></form>';
		if ( $fails ) {
			echo '<h2>Failed lookups</h2><table class="widefat striped" style="max-width:760px"><thead><tr><th>Domain</th><th>Reason</th></tr></thead><tbody>';
			foreach ( array_slice( $fails, 0, 50 ) as $f ) {
				echo '<tr><td>' . esc_html( $f[0] ) . '</td><td>' . esc_html( $f[1] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
