<?php
/**
 * Email alert for new PHP fatal errors.
 *
 * db-safety-block.php records fatal errors in the DB Fatal Log; this sends the site's admin
 * address a short summary at most once an hour when something new has been recorded since the
 * last alert, so a broken page is noticed without anyone checking Tools > DB Fatal Log.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_alert_body' ) ) {
	/**
	 * Builds the alert for fatal-log entries newer than $since.
	 *
	 * @param array<int,array<string,mixed>> $log   Fatal log (newest first), as db-safety-block.php stores it.
	 * @param int                            $since Unix time of the last alert.
	 * @param string                         $host  Site host.
	 * @return array{subject:string,body:string,newest:int}|null Null when nothing is new.
	 */
	function db_alert_body( array $log, $since, $host, array $seen = array(), $now = 0 ) {
		// A repeat of a known file:line is mailed at most once a day ("still happening"); only a
		// file:line first seen after the last alert counts as new. The old rule treated every repeat
		// as new and mailed one recurring fatal every hour for good (bug register batch 2, AD-1).
		$now    = $now ? (int) $now : time();
		$new    = array();
		$still  = array();
		$newest = (int) $since;
		foreach ( $log as $e ) {
			$ts = isset( $e['time'] ) ? (int) strtotime( $e['time'] . ' UTC' ) : 0;
			if ( $ts <= $since ) {
				continue;
			}
			$newest = max( $newest, $ts );
			$key    = ( isset( $e['file'] ) ? (string) $e['file'] : '' ) . ':' . (int) ( isset( $e['line'] ) ? $e['line'] : 0 );
			// Entries stored before first-seen was recorded: a repeated one counts as a repeat.
			$first  = isset( $e['first'] ) ? (int) strtotime( $e['first'] . ' UTC' ) : ( empty( $e['count'] ) ? $ts : 0 );
			if ( $first > $since ) {
				$new[] = $e + array( '_ts' => $ts, '_key' => $key );
			} elseif ( $now - (int) ( isset( $seen[ $key ] ) ? $seen[ $key ] : 0 ) >= 86400 ) {
				$still[] = $e + array( '_ts' => $ts, '_key' => $key );
			}
		}
		if ( ! $new && ! $still ) {
			return null;
		}
		$fmt = static function ( $e ) {
			$file = isset( $e['file'] ) ? basename( (string) $e['file'] ) : '';
			return '- ' . gmdate( 'Y-m-d H:i', $e['_ts'] ) . ' UTC  ' . substr( preg_replace( '/\s+/', ' ', (string) ( isset( $e['msg'] ) ? $e['msg'] : '' ) ), 0, 220 )
				. '  (' . $file . ':' . (int) ( isset( $e['line'] ) ? $e['line'] : 0 ) . ', page ' . ( isset( $e['uri'] ) ? $e['uri'] : '' ) . ( ! empty( $e['count'] ) ? ', seen ' . (int) $e['count'] . ' times' : '' ) . ')';
		};
		$body = '';
		if ( $new ) {
			$body .= "New fatal errors were recorded on {$host}:\n\n" . implode( "\n", array_map( $fmt, array_slice( $new, 0, 5 ) ) ) . "\n" . ( count( $new ) > 5 ? '... and ' . ( count( $new ) - 5 ) . " more.\n" : '' ) . "\n";
		}
		if ( $still ) {
			$body .= "Still happening (reminder at most once a day):\n\n" . implode( "\n", array_map( $fmt, array_slice( $still, 0, 5 ) ) ) . "\n\n";
		}
		foreach ( array_merge( $new, $still ) as $e ) {
			$seen[ $e['_key'] ] = $now;
		}
		$subject = $new
			? count( $new ) . ' new PHP fatal error' . ( 1 === count( $new ) ? '' : 's' )
			: count( $still ) . ' PHP fatal error' . ( 1 === count( $still ) ? '' : 's' ) . ' still happening';
		return array(
			'subject' => '[' . $host . '] ' . $subject,
			'body'    => $body . "See Tools > DB Fatal Log in WordPress for the full list.\n",
			'newest'  => $newest,
			'seen'    => $seen,
		);
	}
}

add_action( 'db_fatal_alert', function () {
	// First run: remember where the log stands now, so only errors recorded from here on are mailed.
	if ( false === get_option( 'db_fatal_alerted', false ) ) {
		update_option( 'db_fatal_alerted', time(), false );
		return;
	}
	$log = get_option( defined( 'DB_SAFETY_OPTION' ) ? DB_SAFETY_OPTION : 'db_fatal_log', array() );
	$m   = db_alert_body( is_array( $log ) ? $log : array(), (int) get_option( 'db_fatal_alerted', 0 ), (string) wp_parse_url( home_url(), PHP_URL_HOST ), (array) get_option( 'db_fatal_alert_seen', array() ) );
	if ( ! $m ) {
		return;
	}
	update_option( 'db_fatal_alerted', $m['newest'], false ); // first, so a mail failure cannot cause a flood.
	update_option( 'db_fatal_alert_seen', array_slice( $m['seen'], -200, null, true ), false );
	wp_mail( get_option( 'admin_email' ), $m['subject'], $m['body'] );
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'db_fatal_alert' ) ) {
		wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', 'db_fatal_alert' );
	}
} );
