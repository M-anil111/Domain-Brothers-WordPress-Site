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
function add_option( $k, $v, $d = '', $a = null ) { if ( array_key_exists( $k, $GLOBALS['OPT'] ) ) { return false; } $GLOBALS['OPT'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['OPT'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['TRANS'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['TRANS'][ $k ] = $v; return true; }
function add_action() {} function add_filter() {} function do_action() {} function register_rest_route() {} function register_setting() {}
function add_options_page() {} function add_settings_section() {} function add_settings_field() {} function wp_next_scheduled() { return true; } function wp_schedule_event() {}
function esc_html( $s ) { return htmlspecialchars( (string) $s ); } function esc_attr( $s ) { return htmlspecialchars( (string) $s ); } function esc_url( $s ) { return (string) $s; }
function wp_kses( $s ) { return $s; } function sanitize_text_field( $s ) { return trim( (string) $s ); } function wp_unslash( $s ) { return $s; } function nocache_headers() {}
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_SANITIZE_EMAIL ); } function is_email( $s ) { return false !== filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function home_url( $p = '/' ) { return 'https://beta.example.test' . $p; } function rest_url( $p = '' ) { return home_url( '/wp-json/' . $p ); } function admin_url() { return home_url( '/wp-admin/' ); }
function wp_salt() { return 'test-salt'; } function add_query_arg( $a, $u ) { return $u . '?' . http_build_query( $a ); } function wp_date( $f, $t = null ) { return gmdate( $f, $t ?: time() ); }
function get_bloginfo() { return 'Domain Brothers'; } function is_wp_error( $x ) { return false; } function error_log_noop() {}
function wp_mail( $to, $s, $b ) { $GLOBALS['MAIL'][] = array( $to, $s ); return true; }
// Stripe stub. $GLOBALS['STRIPE_NEXT'] queues responses for POST /payment_intents
// (array( code, body-array )); default is an open PaymentIntent with no status.
$GLOBALS['STRIPE_NEXT'] = array(); $GLOBALS['PI_GET'] = array();
function wp_remote_post( $url, $args ) {
	$GLOBALS['STRIPE'][] = array( $url, $args['body'] ?? array(), $args['headers'] ?? array() );
	if ( '/v1/payment_intents' === substr( $url, -19 ) && $GLOBALS['STRIPE_NEXT'] ) {
		list( $code, $body ) = array_shift( $GLOBALS['STRIPE_NEXT'] );
		return array( 'code' => $code, 'body' => json_encode( $body ) );
	}
	return array( 'code' => 200, 'body' => '{"id":"pi_x","client_secret":"cs"}' );
}
function wp_remote_get( $url, $args ) {
	if ( false !== strpos( $url, '/payment_intents/' ) ) { return array( 'code' => 200, 'body' => json_encode( $GLOBALS['PI_GET'] ) ); }
	return array( 'code' => 200, 'body' => '{"invoice_settings":{"default_payment_method":"pm_new"}}' );
}
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code'] ?? 200; }
// Lets a test run code "while an email is being sent" (webhook/cron interleaving).
$GLOBALS['ON_MAIL'] = null;
function db_send_branded_email( $to, $s, $b ) { $GLOBALS['MAIL'][] = array( $to, $s ); if ( $GLOBALS['ON_MAIL'] ) { $f = $GLOBALS['ON_MAIL']; $GLOBALS['ON_MAIL'] = null; $f( $s ); } return true; }
class WP_REST_Request { private $p; function __construct( $p ) { $this->p = $p; } function get_param( $k ) { return $this->p[ $k ] ?? null; } }
class WP_REST_Response { public $data; public $status; function __construct( $d, $s ) { $this->data = $d; $this->status = $s; } }

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
function pi_ok( $id, $n, $months, $amount, $email = 'buyer@example.test', $domain = 'fatality.ca', $cust = 'cus_1' ) {
	$meta = array( 'domain' => $domain, 'type' => 'plan', 'total_months' => $months, 'installment_number' => $n, 'total_price_usd' => '2500' );
	if ( 1 === $n ) {
		$meta['terms_accepted_at'] = '2026-10-24T08:00:00+00:00';
		$meta['terms_sha']         = 'abcdef0123456789';
	}
	db_stripe_handle_pi_succeeded( array( 'id' => $id, 'amount_received' => $amount, 'receipt_email' => $email, 'customer' => $cust, 'payment_method' => 'pm_1', 'metadata' => $meta ) );
}
$key = db_stripe_store_key( 'plan', 'fatality.ca' );

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
ok( ! in_array( 'fatality.ca', (array) get_option( db_stripe_plan_index_option(), array() ), true ), 'terminated plan leaves the billing index' );
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

// 8. Payment-code review regressions (2026-10-25) -------------------------------
function plan_of( $d ) { return (array) get_option( db_stripe_store_key( 'plan', $d ), array() ); }
function mails_with( $i, $needle ) { return count( array_filter( mails_since( $i ), function ( $m ) use ( $needle ) { return false !== strpos( $m[1], $needle ); } ) ); }

// 8a. Late/resent success on a completed plan is not applied (no reopen, no recharge).
pi_ok( 'pi_c1', 1, 3, 83333, 'c@example.test', 'late.ca', 'cus_c' );
pi_ok( 'pi_c2', 2, 3, 83333, 'c@example.test', 'late.ca', 'cus_c' );
pi_ok( 'pi_c3', 3, 3, 83334, 'c@example.test', 'late.ca', 'cus_c' );
ok( 'completed' === plan_of( 'late.ca' )['status'], '3-month plan completes after 3 payments' );
$mi = count( $GLOBALS['MAIL'] );
pi_ok( 'pi_c2', 2, 3, 83333, 'c@example.test', 'late.ca', 'cus_c' );
ok( count( $GLOBALS['MAIL'] ) === $mi && 'completed' === plan_of( 'late.ca' )['status'], 'resent event for an already-applied payment is a silent no-op' );
pi_ok( 'pi_c2_other', 2, 3, 83333, 'c@example.test', 'late.ca', 'cus_c' );
$p = plan_of( 'late.ca' );
ok( 'completed' === $p['status'] && 3 === count( $p['history'] ) && 1 === mails_with( $mi, 'NEEDS REVIEW' ), 'late installment on a completed plan: not applied, admin asked to review/refund' );

// 8b. Second buyer paying installment 1 for a reserved domain is not merged.
pi_ok( 'pi_d1', 1, 6, 41667, 'first@example.test', 'Dup.CA', 'cus_a' );
$mi = count( $GLOBALS['MAIL'] );
pi_ok( 'pi_d1b', 1, 6, 41667, 'second@example.test', 'dup.ca', 'cus_b' );
$p = plan_of( 'dup.ca' );
ok( 1 === count( $p['history'] ) && 'cus_a' === $p['customer_id'] && 'first@example.test' === $p['customer_email'] && 1 === mails_with( $mi, 'NEEDS REVIEW' ), 'second buyer on a reserved domain: flagged, first buyer\'s plan untouched' );
ok( db_stripe_domain_unavailable( 'DUP.CA' ) && db_stripe_domain_unavailable( 'dup.ca' ) && 'dup.ca' === $p['domain'], 'mixed-case domain stored once, lower-case; reserved under any spelling' );

// 8c. Foreign PaymentIntent on the same Stripe account is ignored.
$mi = count( $GLOBALS['MAIL'] );
db_stripe_handle_pi_succeeded( array( 'id' => 'pi_link', 'amount_received' => 4900, 'receipt_email' => 'x@example.test', 'metadata' => array() ) );
ok( count( $GLOBALS['MAIL'] ) === $mi && ! get_option( db_stripe_store_key( 'sold', '' ) ), 'payment without checkout metadata: no sale, no emails' );

// 8d. Retry succeeds while the cron is sending its notice -> not overwritten, no recharge.
pi_ok( 'pi_r1', 1, 9, 29167, 'r@example.test', 'race.ca', 'cus_r' );
db_plan_on_installment_failed( 'race.ca' );
$p = plan_of( 'race.ca' ); $p['past_due_since'] = time() - 3 * DAY_IN_SECONDS - 60; $p['last_attempt_ts'] = 0; update_option( db_stripe_store_key( 'plan', 'race.ca' ), $p );
$GLOBALS['ON_MAIL'] = function ( $subject ) { if ( false !== strpos( $subject, 'Still unpaid' ) ) { pi_ok( 'pi_r2', 2, 9, 29167, 'r@example.test', 'race.ca', 'cus_r' ); } };
db_plan_run_daily();
$p = plan_of( 'race.ca' );
ok( 'active' === $p['status'] && 3 === $p['next_installment'] && 2 === count( $p['history'] ), 'payment landing during the notice email is kept (plan active, next installment 3)' );

// 8e. Synchronous success from the retry: plan recovered, no "Still unpaid" notice.
pi_ok( 'pi_s1', 1, 9, 29167, 's@example.test', 'sync.ca', 'cus_s' );
db_plan_on_installment_failed( 'sync.ca' );
$p = plan_of( 'sync.ca' ); $p['past_due_since'] = time() - 3 * DAY_IN_SECONDS - 60; $p['last_attempt_ts'] = 0; update_option( db_stripe_store_key( 'plan', 'sync.ca' ), $p );
$GLOBALS['STRIPE_NEXT'][] = array( 200, array( 'id' => 'pi_s2', 'object' => 'payment_intent', 'status' => 'succeeded', 'amount_received' => 29167, 'customer' => 'cus_s', 'receipt_email' => 's@example.test', 'metadata' => array( 'domain' => 'sync.ca', 'type' => 'plan', 'total_months' => '9', 'installment_number' => '2', 'total_price_usd' => '2500' ) ) );
$mi = count( $GLOBALS['MAIL'] );
db_plan_run_daily();
ok( 'active' === plan_of( 'sync.ca' )['status'] && 0 === mails_with( $mi, 'Still unpaid' ) && 1 === mails_with( $mi, 'back on track' ), 'retry that succeeds at once: recovered, no overdue notice' );

// 8f. Stripe error (no PaymentIntent): nothing pushed a month out; admin told.
pi_ok( 'pi_e1', 1, 6, 41667, 'e@example.test', 'err.ca', 'cus_e' );
$p = plan_of( 'err.ca' ); $due = time() - 60; $p['next_charge_ts'] = $due; update_option( db_stripe_store_key( 'plan', 'err.ca' ), $p );
$GLOBALS['STRIPE_NEXT'][] = array( 400, array( 'error' => array( 'message' => 'No such customer: cus_e' ) ) );
$mi = count( $GLOBALS['MAIL'] );
db_plan_run_daily();
$p = plan_of( 'err.ca' );
ok( $due === $p['next_charge_ts'] && 'active' === $p['status'] && 1 === mails_with( $mi, 'PLAN CHARGE ERROR' ), 'API error: due date kept for tomorrow\'s retry, admin alerted' );

// 8g. Decline returned directly (402 + PaymentIntent) -> past due at once.
$p = plan_of( 'err.ca' ); $p['last_attempt_ts'] = 0; update_option( db_stripe_store_key( 'plan', 'err.ca' ), $p );
$GLOBALS['STRIPE_NEXT'][] = array( 402, array( 'error' => array( 'code' => 'card_declined', 'payment_intent' => array( 'id' => 'pi_e2', 'status' => 'requires_payment_method' ) ) ) );
db_plan_run_daily();
ok( 'past_due' === plan_of( 'err.ca' )['status'], 'decline in the charge response marks the plan past due without waiting for the webhook' );

// 8h. Concurrency lock and Stripe idempotency key.
$p = plan_of( 'err.ca' ); $p['status'] = 'active'; $p['last_attempt_ts'] = 0; update_option( db_stripe_store_key( 'plan', 'err.ca' ), $p );
add_option( db_stripe_store_key( 'plan', 'err.ca' ) . '_lock', time() );
$si = count( $GLOBALS['STRIPE'] );
ok( 'locked' === db_stripe_charge_next_installment( $p ) && count( $GLOBALS['STRIPE'] ) === $si, 'a second concurrent charge attempt is refused by the lock' );
delete_option( db_stripe_store_key( 'plan', 'err.ca' ) . '_lock' );
db_stripe_charge_next_installment( $p );
$last = end( $GLOBALS['STRIPE'] );
ok( 0 === strpos( (string) ( $last[2]['Idempotency-Key'] ?? '' ), 'db-plan-' ), 'off-session charge carries an Idempotency-Key' );
ok( 'skipped' === db_stripe_charge_next_installment( plan_of( 'err.ca' ) ), 'charge attempted again within 20 hours is skipped' );

// 8i. Update-card link: bound to the current customer, expires.
$url = db_plan_update_card_url( 'dup.ca' );
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
ok( hash_equals( db_plan_card_sig( 'dup.ca', 'cus_a', (int) $q['e'] ), $q['t'] ), 'card link verifies for the plan\'s customer' );
ok( ! hash_equals( db_plan_card_sig( 'dup.ca', 'cus_other', (int) $q['e'] ), $q['t'] ) && (int) $q['e'] > time() && (int) $q['e'] <= time() + 21 * DAY_IN_SECONDS, 'card link fails for another customer and expires within 21 days' );

// 8j. Receipt email: real client_secret required, only while the payment is open.
$req = new WP_REST_Request( array( 'client_secret' => 'pi_abc_secret_forged', 'email' => 'attacker@example.test' ) );
$GLOBALS['PI_GET'] = array( 'id' => 'pi_abc', 'client_secret' => 'pi_abc_secret_real', 'status' => 'requires_payment_method', 'metadata' => array( 'domain' => 'dup.ca' ) );
ok( 400 === db_stripe_set_receipt_email( $req )->status, 'forged client_secret (right PaymentIntent id) is rejected' );
$GLOBALS['PI_GET']['status'] = 'succeeded';
ok( 409 === db_stripe_set_receipt_email( new WP_REST_Request( array( 'client_secret' => 'pi_abc_secret_real', 'email' => 'x@example.test' ) ) )->status, 'receipt email cannot be changed after payment' );
$GLOBALS['PI_GET']['status'] = 'requires_payment_method';
ok( 200 === db_stripe_set_receipt_email( new WP_REST_Request( array( 'client_secret' => 'pi_abc_secret_real', 'email' => 'buyer@example.test', 'terms_accepted' => true ) ) )->status, 'genuine buyer can set the receipt email before paying' );

// 8k. Webhook signature: any of several v1 values (secret rotation); stale rejected.
$payload = '{"id":"evt_1","type":"x"}'; $t = time();
$good = hash_hmac( 'sha256', $t . '.' . $payload, 'whsec_test' );
ok( is_array( db_stripe_verify_webhook_sig( $payload, "t=$t,v1=deadbeef,v1=$good", 'whsec_test' ) ), 'signature accepted when any v1 matches' );
ok( null === db_stripe_verify_webhook_sig( $payload, 't=' . ( $t - 600 ) . ',v1=' . hash_hmac( 'sha256', ( $t - 600 ) . '.' . $payload, 'whsec_test' ), 'whsec_test' ), 'signature older than 5 minutes rejected' );

// 8l. Test-mode records never reserve or sell a live listing.
$GLOBALS['OPT']['db_stripe_mode'] = 'live';
ok( ! db_stripe_domain_unavailable( 'aethereum.ca' ) && ! db_stripe_domain_unavailable( 'dup.ca' ), 'test-mode sale/plan does not block the live listing' );
$GLOBALS['OPT']['db_stripe_mode'] = 'test';

echo "\n" . ( $n - $fails ) . "/$n passed\n";
exit( $fails ? 1 : 0 );
