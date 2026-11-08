<?php
/**
 * Offline test: the payment schedule, the retry dates and the terms that every
 * plan email carries (db-plan-lifecycle-block.php).
 *
 *     php tests/plan/plan-schedule-test.php
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

$fails = 0;
function ok( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fails++; }
}

$start = strtotime( '2026-01-10 12:00:00' );
$plan  = array(
	'domain' => 'brandable.com', 'total_months' => 3, 'price_usd' => 900.0,
	'paid_installments' => 1, 'next_installment' => 2, 'status' => 'active',
	'history' => array( array( 'installment' => 1, 'amount_usd' => 300.0, 'ts' => $start ) ),
);

$rows = db_plan_schedule( $plan );
ok( 3 === count( $rows ), 'a three-month plan has three instalments' );
ok( array( 'paid', 'next', 'scheduled' ) === array_column( $rows, 'state' ), 'states: paid, next, scheduled' );
ok( $rows[0]['due'] === $start, 'instalment 1 is on the first payment date' );
ok( $rows[1]['due'] === strtotime( '+1 month', $start ), 'instalment 2 falls one month after instalment 1' );
ok( $rows[2]['due'] === strtotime( '+2 months', $start ), 'instalment 3 falls two months after instalment 1' );
ok( abs( array_sum( array_column( $rows, 'amount' ) ) - 900.0 ) < 0.001, 'instalment amounts add up to the plan total' );
ok( array() === db_plan_schedule( array( 'total_months' => 3, 'price_usd' => 900 ) ), 'no schedule without a first payment' );

$html = db_plan_schedule_html( $rows, true );
ok( false === strpos( $html, 'Paid<' ) && false !== strpos( $html, 'Next charge' ), 'remaining-only table omits paid rows and marks the next charge' );

$terms = db_plan_terms_html();
ok( false !== strpos( $terms, 'retry automatically 3 more times' ), 'terms say three automatic retries' );
ok( false !== strpos( $terms, 'days 3, 6, 9 after the first decline' ), 'terms give the retry days' );
ok( false !== strpos( $terms, '4 attempts in total' ), 'terms count the first attempt' );
ok( false !== strpos( $terms, 'may be sold to someone else' ), 'terms say the domain may be sold after the plan ends' );
ok( false !== strpos( $terms, 'non-refundable' ), 'terms keep the non-refundable rule' );

$failed = db_plan_extra_html( 'failed_2', $plan + array( 'past_due_since' => $start ) );
ok( false !== strpos( $failed, 'Failed payment notice 2 of 4' ), 'failure notice names its number' );
ok( false !== strpos( $failed, 'retry 1 on' ) && false !== strpos( $failed, 'retry 3 on' ), 'failure notice lists the retry dates' );
ok( false !== strpos( $failed, wp_date( 'F j, Y', $start + 10 * DAY_IN_SECONDS ) ), 'failure notice gives the termination date' );

$monthly = db_plan_extra_html( 'reminder', $plan );
ok( false !== strpos( $monthly, 'Remaining payments' ) && false !== strpos( $monthly, 'Payment plan terms' ), 'monthly email carries remaining payments and terms' );

$msg = db_plan_message( 'failed_1', $plan + array( 'past_due_since' => $start ), array( 'grace_end_ts' => $start + 10 * DAY_IN_SECONDS ) );
ok( false !== strpos( $msg[1], 'Failed payment notice 1 of 4' ), 'the failure email body includes the notice block' );
ok( false !== strpos( $msg[1], 'Questions or need more time?' ), 'the footer is still last in the email body' );
ok( strpos( $msg[1], 'Failed payment notice 1 of 4' ) < strpos( $msg[1], 'Questions or need more time?' ), 'the notice block sits above the footer' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
