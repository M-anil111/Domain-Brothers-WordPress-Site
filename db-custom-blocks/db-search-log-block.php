<?php
/**
 * Site search log: what visitors type into the search box, and whether anything was found.
 *
 * Shows owners which names people look for that are not in the inventory (Tools > Search
 * terms). Stores only a normalised term with counts: never an address, IP or account.
 * Terms that look like an email address or a link are dropped, and so are bots.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_SEARCH_LOG_MAX' ) ) {
	define( 'DB_SEARCH_LOG_MAX', 300 );
}

if ( ! function_exists( 'db_sl_normalise' ) ) {
	/** A search term reduced to lowercase words, or '' when it should not be stored. */
	function db_sl_normalise( $term ) {
		$term = strtolower( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags_safe( (string) $term ) ) ) );
		if ( strlen( $term ) < 2 || false !== strpos( $term, '@' ) || preg_match( '#https?:|www\.|://#', $term ) ) {
			return '';
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $term, 0, 60 ) : substr( $term, 0, 60 );
	}
}

if ( ! function_exists( 'wp_strip_all_tags_safe' ) ) {
	function wp_strip_all_tags_safe( $s ) {
		return function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $s ) : strip_tags( $s );
	}
}

if ( ! function_exists( 'db_sl_add' ) ) {
	/**
	 * Adds one search to the log.
	 *
	 * @param array<string,array{n:int,miss:int,last:int}> $log   Current log.
	 * @param string                                       $term  Raw term.
	 * @param int                                          $found Results found.
	 * @param int                                          $now   Unix time.
	 * @return array<string,array{n:int,miss:int,last:int}>
	 */
	function db_sl_add( array $log, $term, $found, $now ) {
		$t = db_sl_normalise( $term );
		if ( '' === $t ) {
			return $log;
		}
		$row         = isset( $log[ $t ] ) ? $log[ $t ] : array( 'n' => 0, 'miss' => 0, 'last' => 0 );
		$row['n']    = (int) $row['n'] + 1;
		$row['miss'] = (int) $row['miss'] + ( $found > 0 ? 0 : 1 );
		$row['last'] = (int) $now;
		$log[ $t ]   = $row;
		if ( count( $log ) > DB_SEARCH_LOG_MAX ) {
			// Keep the terms with the highest decayed count: each search counts half as much every
			// 30 days. Ranking by raw count always cut the newest term once every stored term had
			// two searches, so new demand was never recorded (AD-8); popular terms still stay.
			$score = static function ( $r ) use ( $now ) {
				return (int) $r['n'] * pow( 0.5, max( 0, (int) $now - (int) $r['last'] ) / ( 30 * 86400 ) );
			};
			uasort( $log, static function ( $a, $b ) use ( $score ) {
				return array( $score( $b ), $b['last'] ) <=> array( $score( $a ), $a['last'] );
			} );
			$log = array_slice( $log, 0, DB_SEARCH_LOG_MAX, true );
		}
		return $log;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_search() || is_paged() ) {
		return; // page 2, 3 ... of the same search is not a new search (AD-8)
	}
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( '' === $ua || preg_match( '/bot|crawl|spider|slurp|curl|python|wget/i', $ua ) ) {
		return;
	}
	// At most 30 logged searches per address per hour (SC-10).
	if ( function_exists( 'db_wl_allow' ) && function_exists( 'db_wl_ip' ) && ! db_wl_allow( 'slog|' . db_wl_ip(), 30, HOUR_IN_SECONDS ) ) {
		return;
	}
	global $wp_query;
	$log = get_option( 'db_search_log', array() );
	update_option( 'db_search_log', db_sl_add( is_array( $log ) ? $log : array(), get_search_query( false ), (int) $wp_query->found_posts, time() ), false );
}, 20 );

add_action( 'admin_menu', function () {
	add_management_page( 'Search terms', 'Search terms', 'manage_options', 'db-search-terms', function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$log = get_option( 'db_search_log', array() );
		$log = is_array( $log ) ? $log : array();
		uasort( $log, static function ( $a, $b ) {
			return array( $b['miss'], $b['n'] ) <=> array( $a['miss'], $a['n'] );
		} );
		echo '<div class="wrap"><h1>Search terms</h1><p>What visitors searched for. Terms with many <strong>misses</strong> are names people want that you do not list: candidates for new inventory or a brokerage lead. Only the words are stored.</p>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Term</th><th>Searches</th><th>Found nothing</th><th>Last searched</th></tr></thead><tbody>';
		foreach ( array_slice( $log, 0, 100, true ) as $t => $r ) {
			echo '<tr><td>' . esc_html( $t ) . '</td><td>' . (int) $r['n'] . '</td><td>' . (int) $r['miss'] . '</td><td>' . esc_html( gmdate( 'Y-m-d', (int) $r['last'] ) ) . '</td></tr>';
		}
		if ( ! $log ) {
			echo '<tr><td colspan="4">Nothing logged yet.</td></tr>';
		}
		echo '</tbody></table><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:16px">';
		wp_nonce_field( 'db_search_log_clear' );
		echo '<input type="hidden" name="action" value="db_search_log_clear"><button class="button" onclick="return confirm(\'Clear the search log?\')">Clear log</button></form></div>';
	} );
} );

add_action( 'admin_post_db_search_log_clear', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_search_log_clear' ) ) {
		wp_die( 'Not allowed.' );
	}
	update_option( 'db_search_log', array(), false );
	wp_safe_redirect( admin_url( 'tools.php?page=db-search-terms' ) );
	exit;
} );
