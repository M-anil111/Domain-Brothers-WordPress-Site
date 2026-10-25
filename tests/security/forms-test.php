<?php
/**
 * Offline test for the public-form protections in db-email-thankyou-block.php:
 * the thank-you page can't be used to fake a payment confirmation, and the
 * offer-form confirmation email can't be used to send phishing text.
 *
 *     php tests/security/forms-test.php
 */

define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
function add_filter() {}
function add_action() {}
function add_shortcode() {}
function wp_salt() { return 'test-salt'; }
function esc_url( $s ) { return (string) $s; } function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function home_url( $p = '/' ) { return 'https://beta.example.test' . $p; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function is_email( $e ) { return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ); }
$GLOBALS['TR'] = array();
function get_transient( $k ) { return $GLOBALS['TR'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['TR'][ $k ] = $v; return true; }
// Published domain listings for WP_Query( post_type => domain, title => … ).
$GLOBALS['LISTINGS'] = array( 'Fatality.ca', 'aethereum.ca' );
class WP_Query {
	public $posts = array();
	function __construct( $args ) {
		foreach ( $GLOBALS['LISTINGS'] as $t ) {
			if ( 0 === strcasecmp( $t, (string) ( $args['title'] ?? '' ) ) ) {
				$this->posts[] = (object) array( 'post_title' => $t );
			}
		}
	}
}
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-email-thankyou-block.php';

$fails = 0;
$n     = 0;
function ok( $cond, $msg ) {
	global $fails, $n;
	$n++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

// Thank-you page.
$sig = db_ty_sig( 'full', 'fatality.ca', '2,500' );
ok( array( 'full', 'fatality.ca', '2,500' ) === db_ty_resolve( 'full', 'fatality.ca', '2,500', $sig ), 'signed checkout return shows domain and amount' );
ok( array( 'generic', '', '' ) === db_ty_resolve( 'full', 'google.com', '250000', '' ), 'unsigned "payment confirmed" link falls back to a neutral thank-you' );
ok( array( 'generic', '', '' ) === db_ty_resolve( 'full', 'fatality.ca', '25', $sig ), 'editing the amount on a signed link invalidates it' );
ok( array( 'generic', '', '' ) === db_ty_resolve( 'plan', 'fatality.ca', '2,500', $sig ), 'a full-purchase signature does not validate a plan page' );
ok( array( 'offer', 'fatality.ca', '' ) === db_ty_resolve( 'offer', 'FATALITY.CA', '999', '' ), 'offer page names a real listing (normalised), never an amount' );
ok( array( 'offer', '', '' ) === db_ty_resolve( 'offer', 'URGENT: verify at evil.example', '', '' ), 'offer page drops text that is not a listing' );
$html = db_ty_html( 'generic', '', '' );
ok( false === strpos( $html, 'new owner' ) && false === stripos( $html, 'has been confirmed' ), 'neutral page makes no payment or ownership claim' );

// Offer confirmation email.
$m = db_offer_confirmation_email( 'v1@example.test', 'there - your account is locked, verify at evil.example/login', 'URGENT: invoice overdue, pay at evil.example', '1', '203.0.113.5' );
ok( is_array( $m ) && 'We received your offer' === $m['subject'] && false === strpos( $m['body'], 'evil.example' ) && false === strpos( $m['body'], 'URGENT' ), 'attacker text kept out of subject and body' );
$m = db_offer_confirmation_email( 'v2@example.test', 'Priya', 'fatality.ca', '$2,000', '203.0.113.6' );
ok( 'We received your offer for fatality.ca' === $m['subject'] && false !== strpos( $m['body'], 'Hi Priya,' ) && false !== strpos( $m['body'], '$2,000' ), 'genuine offer: name, listing and amount shown' );
ok( null === db_offer_confirmation_email( 'v2@example.test', 'Priya', 'fatality.ca', '1', '203.0.113.7' ), 'same recipient within 30 minutes: not sent again' );
$GLOBALS['TR'] = array();
$sent = 0;
for ( $i = 1; $i <= 5; $i++ ) {
	$sent += db_offer_confirmation_email( "victim$i@example.test", '', '', '', '198.51.100.9' ) ? 1 : 0;
}
ok( 3 === $sent, 'one visitor IP can trigger at most 3 confirmation emails an hour' );
ok( null === db_offer_confirmation_email( 'not-an-email', 'x', 'fatality.ca', '1', '198.51.100.10' ), 'invalid address: nothing sent' );

echo "\n" . ( $n - $fails ) . "/$n passed\n";
exit( $fails ? 1 : 0 );
