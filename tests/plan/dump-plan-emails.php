<?php
/**
 * Writes every plan email the plugin builds (reminder, failure notices 1-4,
 * back on track, terminated) as HTML files for review or screenshots.
 *
 *     php tests/plan/dump-plan-emails.php <output-dir>
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DB_PLAN_GRACE_DAYS', 10 );
function add_action() {}
function add_filter() {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function wp_date( $format, $ts = null ) { return date( $format, null === $ts ? time() : (int) $ts ); }
function wp_salt() { return 'test-salt'; }
function db_stripe_store_key( $kind, $domain ) { return 'db_' . $kind . '_' . md5( $domain ); }
function db_stripe_installment_cents( $price, $months, $n ) {
	$cents = (int) round( $price * 100 / $months );
	return $n === $months ? (int) round( $price * 100 ) - $cents * ( $months - 1 ) : $cents;
}
function db_plan_total_cents( $price, $months ) { return (int) round( $price * 100 ); }
function db_plan_update_card_url() { return 'https://example.test/card'; }
function db_send_branded_email() { return true; }
function db_mail_compose() { return null; }

require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-plan-lifecycle-block.php';

$out = $argv[1] ?? sys_get_temp_dir();
$start = strtotime( '2026-01-10 12:00:00' );
$plan  = array(
	'domain' => 'brandable.com', 'customer_email' => 'buyer@example.com', 'total_months' => 3, 'price_usd' => 900.0,
	'paid_installments' => 1, 'next_installment' => 2, 'status' => 'active',
	'history' => array( array( 'installment' => 1, 'amount_usd' => 300.0, 'ts' => $start ) ),
);
$since = strtotime( "+1 month", $start ); // the second instalment is declined on its due date
$cases = array(
	'reminder'  => array( $plan, array( 'due_ts' => strtotime( '+1 month', $start ) ) ),
	'failed_1'  => array( array_merge( $plan, array( 'status' => 'past_due', 'past_due_since' => $since ) ), array( 'grace_end_ts' => $since + 10 * DAY_IN_SECONDS ) ),
	'failed_2'  => array( array_merge( $plan, array( 'status' => 'past_due', 'past_due_since' => $since ) ), array( 'grace_end_ts' => $since + 10 * DAY_IN_SECONDS, 'days_left' => 7 ) ),
	'failed_3'  => array( array_merge( $plan, array( 'status' => 'past_due', 'past_due_since' => $since ) ), array( 'grace_end_ts' => $since + 10 * DAY_IN_SECONDS, 'days_left' => 4 ) ),
	'failed_4'  => array( array_merge( $plan, array( 'status' => 'past_due', 'past_due_since' => $since ) ), array( 'grace_end_ts' => $since + 10 * DAY_IN_SECONDS, 'days_left' => 1 ) ),
	'recovered' => array( $plan + array( 'paid_installments' => 2, 'next_installment' => 3, 'history' => array( $plan['history'][0], array( 'installment' => 2, 'amount_usd' => 300.0, 'ts' => $since + 5 * DAY_IN_SECONDS ) ) ), array() ),
	'defaulted' => array( array_merge( $plan, array( 'status' => 'defaulted', 'past_due_since' => $since ) ), array() ),
);
foreach ( $cases as $key => $c ) {
	list( $subj, $html ) = db_plan_message( $key, $c[0], $c[1] );
	file_put_contents( "$out/$key.html", '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars( $subj ) . '</title><body style="font-family:Arial,sans-serif;max-width:620px;margin:20px auto;padding:0 16px;color:#111827">' . $html . '</body>' );
	echo $key . ': ' . $subj . "\n";
}
