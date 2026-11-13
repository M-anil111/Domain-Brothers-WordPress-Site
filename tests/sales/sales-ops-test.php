<?php
/**
 * Offline test for db-sales-ops-block.php: the email templates and the emails
 * each sales path sends, message validation, the checkout reminder stop link,
 * and the experiment variant.
 *
 *     php tests/sales/sales-ops-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
function add_action() {}
function add_filter() {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_url( $s ) { return (string) $s; }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_email( $s ) { return filter_var( trim( (string) $s ), FILTER_SANITIZE_EMAIL ); }
function is_email( $s ) { return (bool) filter_var( (string) $s, FILTER_VALIDATE_EMAIL ); }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function wp_salt() { return 'test-salt'; }
function current_time( $f ) { return '2026-11-08 10:00:00'; }
function wp_date( $f, $t = null ) { return date( $f, null === $t ? time() : (int) $t ); }
function get_option( $k, $d = false ) { return 'admin_email' === $k ? 'team@example.test' : $d; }
function get_post_meta( $id, $k, $single = false ) { return $GLOBALS['META'][ $id ][ $k ] ?? ''; }
function get_the_title( $id ) { return $GLOBALS['TITLE'][ $id ] ?? ''; }
function db_mail_team_to() { return 'team@example.test'; }
function db_transfer_stages() {
	return array( 'paid' => 'Payment received', 'registrar_lock' => 'Registrar lock removed', 'epp_sent' => 'Transfer code sent', 'transfer_approved' => 'Transfer approved', 'dns_configured' => 'DNS configured', 'completed' => 'Transfer completed' );
}
$GLOBALS['SENT'] = array();
$GLOBALS['META'] = array();
$GLOBALS['TITLE'] = array( 7 => 'brandable.com' );
function db_mail_send( $key, $to, array $vars, $reply_to = '' ) {
	$GLOBALS['SENT'][] = array( 'key' => $key, 'to' => $to, 'vars' => $vars, 'reply_to' => $reply_to );
	return true;
}

require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-sales-ops-block.php';

$fails = 0;
function ok( $cond, $label ) {
	global $fails;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
	if ( ! $cond ) { $fails++; }
}
function last_sent() { return end( $GLOBALS['SENT'] ); }

/* Templates: every placeholder used in subject or body is declared as a variable */
$reg = db_sales_mail_registry();
foreach ( array( 'sales_broker_team', 'sales_broker_ack', 'transfer_stage', 'transfer_message_team', 'transfer_message_buyer', 'offer_sla_team', 'checkout_reminder' ) as $key ) {
	ok( isset( $reg[ $key ] ), "template $key is registered" );
	$t = $reg[ $key ];
	$text = $t['subject'] . ' ' . $t['heading'] . ' ' . $t['body'] . ' ' . $t['details'];
	preg_match_all( '/\{\{(\w+)\}\}/', $text, $m );
	$undeclared = array_diff( array_unique( $m[1] ), array_keys( $t['vars'] ) );
	ok( empty( $undeclared ), "template $key uses only declared variables" . ( $undeclared ? ' (missing: ' . implode( ',', $undeclared ) . ')' : '' ) );
}

/* Broker request: team alert with reply-to, then buyer acknowledgement */
$GLOBALS['SENT'] = array();
db_ops_broker_notify( 'buyer@example.com', 'Alex', '555', 'brandable.com', "Need a name\n<b>x</b>", 'Business' );
ok( count( $GLOBALS['SENT'] ) === 2, 'a broker request sends two emails' );
ok( $GLOBALS['SENT'][0]['key'] === 'sales_broker_team' && $GLOBALS['SENT'][0]['to'] === 'team@example.test', 'the team gets the broker request' );
ok( $GLOBALS['SENT'][0]['reply_to'] === 'buyer@example.com', 'the team email replies to the buyer' );
ok( false === strpos( $GLOBALS['SENT'][0]['vars']['note_html'], '<b>' ), 'the buyer note is escaped in the team email' );
ok( $GLOBALS['SENT'][1]['key'] === 'sales_broker_ack' && $GLOBALS['SENT'][1]['to'] === 'buyer@example.com', 'the buyer gets an acknowledgement' );

/* Transfer stage: buyer email only on a real change to a stage */
$GLOBALS['SENT'] = array();
$GLOBALS['META'][7] = array( 'db_sold_buyer' => 'buyer@example.com' );
db_ops_stage_changed( 7, 'paid', 'paid', 'x' );
ok( count( $GLOBALS['SENT'] ) === 0, 'no email when the stage did not change' );
db_ops_stage_changed( 7, 'paid', '', 'x' );
ok( count( $GLOBALS['SENT'] ) === 0, 'no email when the stage is cleared' );
db_ops_stage_changed( 7, 'paid', 'epp_sent', 'Code is in your registrar inbox.' );
$m = last_sent();
ok( $m['key'] === 'transfer_stage' && $m['to'] === 'buyer@example.com', 'the buyer is emailed on a stage change' );
ok( $m['vars']['stage'] === 'Transfer code sent', 'the email names the stage' );
ok( false !== strpos( $m['vars']['note_html'], 'registrar inbox' ), 'the email carries the team note' );
$GLOBALS['META'][8] = array();
$GLOBALS['SENT'] = array();
db_ops_stage_changed( 8, '', 'paid', '' );
ok( count( $GLOBALS['SENT'] ) === 0, 'no email when there is no buyer on record' );

/* Messages: validation */
ok( db_ops_add_message( 7, 'buyer', 'buyer@example.com', '   ' ) === false, 'an empty message is refused' );
ok( db_ops_add_message( 7, 'buyer', 'buyer@example.com', str_repeat( 'a', 2001 ) ) === false, 'a message over 2000 characters is refused' );

/* Checkout reminder stop link: bound to the payment and the email */
$s1 = db_ops_reminder_stop_sig( 'pi_1', 'buyer@example.com' );
ok( hash_equals( $s1, db_ops_reminder_stop_sig( 'pi_1', 'buyer@example.com' ) ), 'the stop signature is stable' );
ok( ! hash_equals( $s1, db_ops_reminder_stop_sig( 'pi_2', 'buyer@example.com' ) ), 'the stop signature is bound to the payment' );
ok( ! hash_equals( $s1, db_ops_reminder_stop_sig( 'pi_1', 'other@example.com' ) ), 'the stop signature is bound to the email' );

/* Experiment: variant from the cookie, default A */
ok( 'A' === db_exp_variant(), 'with no cookie the variant is A' );
$_COOKIE['db_exp_cta'] = 'B';
ok( 'B' === db_exp_variant() && 'Buy this domain' === db_exp_cta_label(), 'cookie B shows the B copy' );
$_COOKIE['db_exp_cta'] = 'Z';
ok( 'A' === db_exp_variant(), 'an invalid cookie falls back to A' );

echo $fails ? "\n$fails FAILED\n" : "\nALL PASSED\n";
exit( $fails ? 1 : 0 );
