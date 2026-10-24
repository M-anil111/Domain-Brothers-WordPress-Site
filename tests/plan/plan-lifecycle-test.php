<?php
/**
 * Offline end-to-end test of the payment-plan code paths (no WordPress, no
 * Stripe). Loads the real db-stripe-block.php and db-plan-lifecycle-block.php
 * against in-memory stubs and drives a plan through: start -> monthly charge
 * -> decline -> dunning notices -> recovery, a second plan -> default, and
 * the "already sold" checkout guard.
 *
 *   php tests/plan/plan-lifecycle-test.php
 *
 * Exits non-zero on the first failed assertion.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['OPT'] = array(); $GLOBALS['MAIL'] = array(); $GLOBALS['STRIPE'] = array(); $GLOBALS['TRANS'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['OPT'] ) ? $GLOBALS['OPT'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['OPT'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['TRANS'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['TRANS'][ $k ] = $v; return true; }
function add_action() {} function add_filter() {} function do_action() {} function register_rest_route() {} function register_setting() {}
function add_options_page() {} function add_settings_section() {} function add_settings_field() {} function wp_next_scheduled() { return true; } function wp_schedule_event() {}
function esc_html( $s ) { return htmlspecialchars( (string) $s ); } function esc_attr( $s ) { return htmlspecialchars( (string) $s ); } function esc_url( $s ) { return (string) $s; }
function wp_kses( $s ) { return $s; } function sanitize_text_field( $s ) { return trim( (string) $s ); } function wp_unslash( $s ) { return $s; } function nocache_headers() {}
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function home_url( $p = '/' ) { return 'https://beta.example.test' . $p; } function rest_url( $p = '' ) { return home_url( '/wp-json/' . $p ); } function admin_url() { return home_url( '/wp-admin/' ); }
function wp_salt() { return 'test-salt'; } function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); } function wp_date( $f, $t = null ) { return gmdate( $f, $t ?: time() ); }
function get_bloginfo() { return 'Domain Brothers'; } function is_wp_error( $x ) { return false; } function error_log_noop() {}
function wp_mail( $to, $s, $b ) { $GLOBALS['MAIL'][] = array( $to, $s ); return true; }
function wp_remote_post( $url, $args ) { $GLOBALS['STRIPE'][] = array( $url, $args['body'] ?? array() ); return array( 'body' => '{"id":"pi_x","client_secret":"cs"}' ); }
function wp_remote_get( $url, $args ) { return array( 'body' => '{"invoice_settings":{"default_payment_method":"pm_new"}}' ); }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function db_send_branded_email( $to, $s, $b ) { $GLOBALS['MAIL'][] = array( $to, $s ); return true; }

// Plan maths (from db-custom-blocks.php BLOCK 5).
function db_plan_rate( $m ) { $r = array( 3 => 0, 6 => 0, 9 => 5, 12 => 10 ); return isset( $r[ (int) $m ] ) ? (float) $r[ (int) $m ] : 0.0; }
function db_plan_total_cents( $p, $m ) { return (int) round( (float) $p * ( 100 + db_plan_rate( $m ) ) ); }

require __DIR__ . '/../../db-custom-blocks/db-stripe-block.php';
require __DIR__ . '/../../db-custom-blocks/db-plan-lifecycle-block.php';
// Test keys so the cron/charge paths run.
$GLOBALS['OPT']['db_stripe_mode'] = 'test';
$GLOBALS['OPT']['db_stripe_test_sec_key'] = 'sk_test_x';
$GLOBALS['OPT']['db_stripe_test_pub_key'] = 'pk_test_x';
$GLOBALS['OPT']['admin_email'] = 'admin@example.test';

$fails = 0; $n = 0;
function ok( $cond, $msg ) { global $fails, $n; $n++; echo ( $cond ? "PASS " : "FAIL " ) . $msg . "\n"; if ( ! $cond ) { $fails++; } }
function mails_since( $i ) { return array_slice( $GLOBALS['MAIL'], $i ); }
function subj( $i ) { return implode( ' || ', array_map( function ( $m ) { return $m[1]; }, mails_since( $i ) ) ); }
function pi_ok( $id, $n, $months, $amount, $email = 'buyer@example.test', $domain = 'fatality.ca' ) {
	$meta = array( 'domain' => $domain, 'type' => 'plan', 'total_months' => $months, 'installment_number' => $n, 'total_price_usd' => '2500' );
	if ( 1 === $n ) {
		$meta['terms_accepted_at'] = '2026-10-24T08:00:00+00:00';
		$meta['terms_sha']         = 'abcdef0123456789';
	}
	db_stripe_handle_pi_succeeded( array( 'id' => $id, 'amount_received' => $amount, 'receipt_email' => $email, 'customer' => 'cus_1', 'payment_method' => 'pm_1', 'metadata' => $meta ) );
}
$key = 'db_stripe_plan_' . md5( 'fatality.ca' );

// 1. Plan start (9 months, 5%) ------------------------------------------------
$m0 = count( $GLOBALS['MAIL'] );
pi_ok( 'pi_1', 1, 9, 29167 );
$p = get_option( $key );
ok( 'active' === $p['status'] && 2 === $p['next_installment'], 'plan start -> active, next installment 2' );
ok( '2026-10-24T08:00:00+00:00' === ( $p['terms_accepted_at'] ?? '' ) && 'abcdef0123456789' === ( $p['terms_sha'] ?? '' ), 'terms acceptance from checkout is stored on the plan record' );
$rb = db_stripe_receipt_body( 'buyer@example.test', true );
ok( isset( $rb['metadata[terms_accepted_at]'], $rb['metadata[terms_sha]'] ) && 16 === strlen( $rb['metadata[terms_sha]'] ), 'checkout sends acceptance time + terms fingerprint to Stripe' );
ok( array( 'receipt_email' => 'x@example.test' ) === db_stripe_receipt_body( 'x@example.test', false ), 'no acceptance metadata when the box was not ticked' );
ok( false !== strpos( subj( $m0 ), 'has started' ) && false !== strpos( subj( $m0 ), 'PLAN STARTED' ), 'plan start emails customer + admin: ' . subj( $m0 ) );
ok( db_stripe_domain_unavailable( 'fatality.ca' ), 'domain on an active plan is unavailable for a second buyer' );
$m1 = count( $GLOBALS['MAIL'] );
pi_ok( 'pi_1', 1, 9, 29167 );
ok( count( $GLOBALS['MAIL'] ) === $m1 && 1 === count( get_option( $key )['history'] ), 'duplicate webhook for the same payment is ignored' );

// 2. Reminder 3 days before, then the charge on the due date -------------------
$p = get_option( $key ); $p['next_charge_ts'] = time() + 2 * DAY_IN_SECONDS; update_option( $key, $p );
$m2 = count( $GLOBALS['MAIL'] );
db_plan_run_daily(); db_plan_run_daily();
ok( 1 === count( mails_since( $m2 ) ) && false !== strpos( subj( $m2 ), 'will be charged on' ), 'one reminder email, not repeated: ' . subj( $m2 ) );
$p = get_option( $key ); $p['next_charge_ts'] = time() - 60; update_option( $key, $p );
$s0 = count( $GLOBALS['STRIPE'] );
db_plan_run_daily(); db_plan_run_daily();
$charges = array_values( array_filter( array_slice( $GLOBALS['STRIPE'], $s0 ), function ( $c ) { return false !== strpos( $c[0], 'payment_intents' ); } ) );
ok( 1 === count( $charges ), 'due installment charged exactly once across two cron runs' );
ok( isset( $charges[0] ) && 29167 === $charges[0][1]['amount'] && '2' === $charges[0][1]['metadata[installment_number]'], 'installment 2 amount = $291.67 (5% plan)' );
ok( isset( $charges[0] ) && 'pm_new' === $charges[0][1]['payment_method'], "charge uses the customer's current default card" );

// 3. Decline -> grace period notices -> recovery -----------------------------
$m3 = count( $GLOBALS['MAIL'] );
db_plan_on_installment_failed( 'fatality.ca' );
$p = get_option( $key );
ok( 'past_due' === $p['status'], 'decline -> past_due' );
ok( false !== strpos( subj( $m3 ), 'did not go through' ) && false !== strpos( subj( $m3 ), 'PLAN PAYMENT FAILED' ), 'decline emails customer + admin' );
db_plan_on_installment_failed( 'fatality.ca' );
ok( count( $GLOBALS['MAIL'] ) === $m3 + 2, 'a second decline webhook during the grace period sends nothing extra' );
foreach ( array( 3 => 'Still unpaid', 6 => 'URGENT', 9 => 'FINAL NOTICE' ) as $day => $want ) {
	$p = get_option( $key ); $p['past_due_since'] = time() - $day * DAY_IN_SECONDS - 60; $p['last_attempt_ts'] = 0; update_option( $key, $p );
	$mi = count( $GLOBALS['MAIL'] ); $si = count( $GLOBALS['STRIPE'] );
	db_plan_run_daily();
	ok( false !== strpos( subj( $mi ), $want ), "day $day: '$want' notice sent (" . subj( $mi ) . ')' );
	ok( count( $GLOBALS['STRIPE'] ) > $si, "day $day: charge retried" );
}
$mr = count( $GLOBALS['MAIL'] );
pi_ok( 'pi_2', 2, 9, 29167 );
$p = get_option( $key );
ok( 'active' === $p['status'] && empty( $p['past_due_since'] ), 'late payment clears -> active again' );
ok( false !== strpos( subj( $mr ), 'back on track' ), 'recovery email sent: ' . subj( $mr ) );

// 4. Missed payment that is never cured -> termination ------------------------
db_plan_on_installment_failed( 'fatality.ca' );
$p = get_option( $key ); $p['past_due_since'] = time() - 10 * DAY_IN_SECONDS - 60; update_option( $key, $p );
$md = count( $GLOBALS['MAIL'] );
db_plan_run_daily();
$p = get_option( $key );
ok( 'defaulted' === $p['status'], 'day 10 unpaid -> plan terminated' );
ok( false !== strpos( subj( $md ), 'has been terminated' ) && false !== strpos( subj( $md ), 'ACTION REQUIRED' ), 'termination emails customer + admin' );
ok( ! in_array( md5( 'fatality.ca' ), (array) get_option( 'db_stripe_active_plans', array() ), true ), 'terminated plan leaves the billing index' );
ok( ! db_stripe_domain_unavailable( 'fatality.ca' ), 'terminated (forfeited) domain is available to sell again' );

// 5. A new buyer starts a plan on the same domain --------------------------------
pi_ok( 'pi_3', 1, 3, 83333, 'second@example.test' );
$p = get_option( $key );
ok( 1 === count( $p['history'] ) && 'second@example.test' === $p['customer_email'], "new plan starts clean (previous buyer's payments not merged)" );

// 6. Full purchase marks the domain sold ------------------------------------
db_stripe_handle_pi_succeeded( array( 'id' => 'pi_full', 'amount_received' => 1000000, 'receipt_email' => 'b@example.test', 'metadata' => array( 'domain' => 'aethereum.ca', 'type' => 'full', 'total_price_usd' => '10000' ) ) );
ok( db_stripe_domain_unavailable( 'aethereum.ca' ) && db_stripe_domain_unavailable( 'Aethereum.ca' ), 'fully paid domain is unavailable (case-insensitive)' );

// 7. Installment maths: every term sums to price + interest --------------------
foreach ( array( 3, 6, 9, 12 ) as $months ) {
	foreach ( array( 2500, 999, 12345.67, 100 ) as $price ) {
		$sum = 0; for ( $i = 1; $i <= $months; $i++ ) { $sum += db_stripe_installment_cents( (float) $price, $months, $i ); }
		if ( $sum !== db_plan_total_cents( $price, $months ) ) { ok( false, "sum mismatch $price/$months" ); }
	}
}
ok( true, 'installments sum to price + interest for 16 price/term cases' );

echo "\n" . ( $n - $fails ) . "/$n passed\n";
exit( $fails ? 1 : 0 );
