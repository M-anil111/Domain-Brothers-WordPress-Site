<?php
/**
 * Contact Form 7 delivery: sender fix, submission log, spam reasons.
 *
 *     php tests/forms/cf7-delivery-test.php
 *
 * Loads db-forms-block.php with WordPress and Contact Form 7 stubbed out.
 */

define( 'ABSPATH', '/' );
$GLOBALS['_a'] = array(); $GLOBALS['_o'] = array();
function add_action( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['_a'][ $h ][ $p ][] = $cb; }
function add_filter( $h, $cb, $p = 10, $n = 1 ) { $GLOBALS['_a'][ $h ][ $p ][] = $cb; }
function run( $h, ...$args ) { $l = $GLOBALS['_a'][ $h ] ?? array(); ksort( $l ); foreach ( $l as $cbs ) { foreach ( $cbs as $cb ) { $r = $cb( ...$args ); if ( null !== $r ) { $args[0] = $r; } } } return $args[0] ?? null; }
function get_option( $k, $d = false ) { return $GLOBALS['_o'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['_o'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['_o'][ $k ] ); }
function is_email( $e ) { return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL ); }
function is_wp_error( $e ) { return $e instanceof WP_Error; }
class WP_Error { private $m; public function __construct( $c, $m ) { $this->m = $m; } public function get_error_message() { return $this->m; } }
class Form { public function id() { return 1878; } public function title() { return 'Contact form 1'; } }
class WPCF7_Submission {
	public static $i; public $spam = array();
	public static function get_instance() { return self::$i; }
	public function add_spam_log( $row ) { $this->spam[] = $row; }
	public function get_spam_log() { return $this->spam; }
	public function get_meta( $k ) { return 'container_post_id' === $k ? 42 : null; }
	public function get_invalid_fields() { return array( 'your-email' => array() ); }
}
define( 'DB_SG_FROM', 'sales@domainbrothers.com' );
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-forms-block.php';

$pass = 0; $fail = 0;
function ok( $c, $l ) { global $pass, $fail; if ( $c ) { $pass++; } else { $fail++; echo "FAIL $l\n"; } }

/* 1. Sender */
$c = db_forms_fix_sender( array( 'sender' => 'Jane Buyer <jane@gmail.com>', 'additional_headers' => '' ) );
ok( 'Jane Buyer <sales@domainbrothers.com>' === $c['sender'], 'visitor sender becomes the site address, name kept (got ' . $c['sender'] . ')' );
ok( 'Reply-To: Jane Buyer <jane@gmail.com>' === $c['additional_headers'], 'visitor becomes Reply-To (got ' . $c['additional_headers'] . ')' );
$c = db_forms_fix_sender( array( 'sender' => 'Domain Brothers <sales@domainbrothers.com>', 'additional_headers' => '' ) );
ok( 'Domain Brothers <sales@domainbrothers.com>' === $c['sender'] && '' === $c['additional_headers'], 'own-domain sender left alone' );
$c = db_forms_fix_sender( array( 'sender' => 'info@DomainBrothers.com', 'additional_headers' => '' ) );
ok( 'info@DomainBrothers.com' === $c['sender'], 'other address on the same domain left alone (case-insensitive)' );
$c = db_forms_fix_sender( array( 'sender' => 'jane@gmail.com', 'additional_headers' => 'Reply-To: x@y.com' ) );
ok( 'sales@domainbrothers.com' === $c['sender'] && 'Reply-To: x@y.com' === $c['additional_headers'], 'existing Reply-To kept, bare sender replaced' );
$c = db_forms_fix_sender( array( 'sender' => "Evil\r\nBcc: x@y.com <jane@gmail.com>", 'additional_headers' => '' ) );
ok( false === strpos( $c['sender'], "\n" ) && false === strpos( $c['additional_headers'], "Bcc: x@y.com\n" ), 'no header injection through the name' );
$c = db_forms_fix_sender( array( 'sender' => 'WordPress <wordpress@beta.domainbrothers.com>', 'additional_headers' => '' ) );
ok( 'WordPress <sales@domainbrothers.com>' === $c['sender'], 'subdomain address replaced by the verified sender' );
$GLOBALS['_o']['wp_mail_smtp'] = array( 'mail' => array( 'from_email' => 'hello@domainbrothers.com' ) );
ok( 'hello@domainbrothers.com' === db_forms_site_sender(), 'WP Mail SMTP From address is used when set' );
unset( $GLOBALS['_o']['wp_mail_smtp'] );
$GLOBALS['_o']['wp_smtp_options'] = array( 'from' => 'mail@domainbrothers.com' );
ok( 'mail@domainbrothers.com' === db_forms_site_sender(), 'WP SMTP From address is used when set' );
unset( $GLOBALS['_o']['wp_smtp_options'] );
$GLOBALS['db_forms_sender_fixed'] = false;

/* 2. Log: mail sent */
WPCF7_Submission::$i = new WPCF7_Submission();
run( 'wpcf7_before_send_mail', new Form() );
run( 'wpcf7_mail_components', array( 'sender' => 'Jane <jane@gmail.com>', 'additional_headers' => '' ) );
run( 'wpcf7_submit', new Form(), array( 'status' => 'mail_sent' ) );
$log = get_option( 'db_forms_log' );
ok( 'mail_sent' === $log[0]['status'] && 1878 === $log[0]['form'] && 42 === $log[0]['page'], 'sent submission logged with form and page' );
ok( '' !== $log[0]['sender'], 'log notes the sender was replaced' );
ok( ! isset( $log[0]['email'] ) && ! isset( $log[0]['message'] ), 'no personal data in the log' );

/* 3. Log: mail failed, with the server error */
run( 'wpcf7_before_send_mail', new Form() );
run( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', 'SMTP Error: Sender address rejected: not owned by user' ) );
run( 'wpcf7_submit', new Form(), array( 'status' => 'mail_failed' ) );
$log = get_option( 'db_forms_log' );
ok( 'mail_failed' === $log[0]['status'] && false !== strpos( $log[0]['detail'], 'Sender address rejected' ), 'mail failure logged with the SMTP error' );
ok( '' === $log[0]['sender'], 'sender flag reset between submissions' );

/* 4. Log: spam, with reasons */
WPCF7_Submission::$i = new WPCF7_Submission();
WPCF7_Submission::$i->add_spam_log( array( 'agent' => 'recaptcha', 'reason' => 'score 0.1 is lower than the threshold 0.5' ) );
WPCF7_Submission::$i->add_spam_log( array( 'agent' => 'db_turnstile', 'reason' => 'no Turnstile token' ) );
run( 'wpcf7_submit', new Form(), array( 'status' => 'spam' ) );
$log = get_option( 'db_forms_log' );
ok( 'spam' === $log[0]['status'] && false !== strpos( $log[0]['detail'], 'recaptcha: score 0.1' ) && false !== strpos( $log[0]['detail'], 'db_turnstile: no Turnstile token' ), 'spam logged with every check that flagged it (got ' . $log[0]['detail'] . ')' );

/* 5. Log: invalid fields; failures outside a form are not logged */
run( 'wpcf7_submit', new Form(), array( 'status' => 'validation_failed' ) );
ok( 'Invalid fields: your-email' === get_option( 'db_forms_log' )[0]['detail'], 'invalid fields named' );
$n = count( get_option( 'db_forms_log' ) );
$GLOBALS['db_forms_in_send'] = false;
run( 'wp_mail_failed', new WP_Error( 'x', 'unrelated failure' ) );
run( 'wpcf7_submit', new Form(), array( 'status' => 'mail_failed' ) );
ok( false === strpos( get_option( 'db_forms_log' )[0]['detail'], 'unrelated' ), 'a mail error outside a form submission is not attributed to it' );

/* 6. Log size */
for ( $i = 0; $i < 120; $i++ ) { run( 'wpcf7_submit', new Form(), array( 'status' => 'mail_sent' ) ); }
ok( 100 === count( get_option( 'db_forms_log' ) ), 'log keeps the latest 100' );

/* 7. Honeypot name */
$src = file_get_contents( dirname( __DIR__, 2 ) . '/db-custom-blocks/db-custom-blocks.php' );
ok( false !== strpos( $src, "define( 'DB_HONEYPOT_FIELD', 'db_hp_k7q' );" ), 'honeypot field no longer contains "website"' );
ok( false !== strpos( $src, "'agent' => 'db_honeypot'" ), 'honeypot records its reason' );
ok( false !== strpos( file_get_contents( dirname( __DIR__, 2 ) . '/db-custom-blocks/db-turnstile-block.php' ), "'agent'  => 'db_turnstile'" ), 'Turnstile records its reason' );

echo $fail ? "\n$fail failed, $pass passed\n" : "ALL PASSED ($pass checks)\n";
exit( $fail ? 1 : 0 );
