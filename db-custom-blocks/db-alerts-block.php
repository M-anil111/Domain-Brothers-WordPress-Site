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
	function db_alert_body( array $log, $since, $host ) {
		$new    = array();
		$newest = (int) $since;
		foreach ( $log as $e ) {
			$ts = isset( $e['time'] ) ? (int) strtotime( $e['time'] . ' UTC' ) : 0;
			if ( $ts > $since ) {
				$new[]  = $e + array( '_ts' => $ts );
				$newest = max( $newest, $ts );
			}
		}
		if ( ! $new ) {
			return null;
		}
		$lines = array();
		foreach ( array_slice( $new, 0, 5 ) as $e ) {
			$file    = isset( $e['file'] ) ? basename( (string) $e['file'] ) : '';
			$lines[] = '- ' . gmdate( 'Y-m-d H:i', $e['_ts'] ) . ' UTC  ' . substr( preg_replace( '/\s+/', ' ', (string) ( isset( $e['msg'] ) ? $e['msg'] : '' ) ), 0, 220 )
				. '  (' . $file . ':' . (int) ( isset( $e['line'] ) ? $e['line'] : 0 ) . ', page ' . ( isset( $e['uri'] ) ? $e['uri'] : '' ) . ( ! empty( $e['count'] ) ? ', seen ' . (int) $e['count'] . ' times' : '' ) . ')';
		}
		$more = count( $new ) > 5 ? "\n... and " . ( count( $new ) - 5 ) . " more.\n" : '';
		return array(
			'subject' => '[' . $host . '] ' . count( $new ) . ' new PHP fatal error' . ( 1 === count( $new ) ? '' : 's' ),
			'body'    => "New fatal errors were recorded on {$host}:\n\n" . implode( "\n", $lines ) . "\n" . $more . "\nSee Tools > DB Fatal Log in WordPress for the full list. This alert is sent at most once an hour.\n",
			'newest'  => $newest,
		);
	}
}

add_action( 'db_fatal_alert', function () {
	$log = get_option( defined( 'DB_SAFETY_OPTION' ) ? DB_SAFETY_OPTION : 'db_fatal_log', array() );
	$m   = db_alert_body( is_array( $log ) ? $log : array(), (int) get_option( 'db_fatal_alerted', 0 ), (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	if ( ! $m ) {
		return;
	}
	update_option( 'db_fatal_alerted', $m['newest'], false ); // first, so a mail failure cannot cause a flood.
	wp_mail( get_option( 'admin_email' ), $m['subject'], $m['body'] );
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'db_fatal_alert' ) ) {
		wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', 'db_fatal_alert' );
	}
} );
