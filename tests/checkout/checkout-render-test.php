<?php
/**
 * Offline test of the /buy-now/ checkout markup (no WordPress, no Stripe).
 * Renders db_stripe_render_checkout_page() against stubs and checks the
 * live-site layout: three steps, Pay in full / Pay less up front selector,
 * billing fields, order summary, next steps and footer — and that the
 * Stripe hooks the payment script and QA suites rely on are still there.
 *
 *   php tests/checkout/checkout-render-test.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['OPT'] = array();
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['OPT'] ) ? $GLOBALS['OPT'][ $k ] : $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['OPT'][ $k ] = $v; return true; }
function add_option( $k, $v, $d = '', $a = null ) { $GLOBALS['OPT'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['OPT'][ $k ] ); return true; }
function get_transient( $k ) { return false; } function set_transient( $k, $v, $t = 0 ) { return true; }
function add_action() {} function add_filter() {} function do_action() {} function register_rest_route() {} function register_setting() {}
function add_options_page() {} function add_settings_section() {} function add_settings_field() {} function wp_next_scheduled() { return true; } function wp_schedule_event() {}
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function wp_kses( $s ) { return $s; } function sanitize_text_field( $s ) { return trim( (string) $s ); } function wp_unslash( $s ) { return $s; }
function home_url( $p = '/' ) { return 'https://beta.example.test' . $p; } function rest_url( $p = '' ) { return home_url( '/wp-json/' . $p ); }
function wp_json_encode( $v ) { return json_encode( $v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); }
function get_template_directory_uri() { return 'https://beta.example.test/wp-content/themes/DomainFolio'; }
function get_posts( $a ) { return 'fatality.ca' === strtolower( $a['title'] ?? '' ) ? array( (object) array( 'ID' => 7, 'post_title' => 'fatality.ca' ) ) : array(); }
function get_permalink( $p ) { return 'https://beta.example.test/domains/fatality-ca/'; }
function wp_salt() { return 'test-salt'; } function get_bloginfo() { return 'Domain Brothers'; } function is_wp_error( $x ) { return false; }
function db_plan_rate( $m ) { $r = array( 3 => 0, 6 => 0, 9 => 5, 12 => 10 ); return isset( $r[ (int) $m ] ) ? (float) $r[ (int) $m ] : 0.0; }
function db_plan_total_cents( $p, $m ) { return (int) round( (float) $p * ( 100 + db_plan_rate( $m ) ) ); }

require __DIR__ . '/../../db-custom-blocks/db-stripe-block.php';
require __DIR__ . '/../../db-custom-blocks/db-plan-lifecycle-block.php';

$pass = 0;
$GLOBALS['LOG'] = array();
function ok( $cond, $name ) {
	global $pass;
	if ( ! $cond ) { fwrite( STDERR, "FAIL: $name\n" ); exit( 1 ); }
	$pass++;
	$GLOBALS['LOG'][] = "ok - $name"; // printed at the end: output before render() would block its header() calls
}
function render( $domain, $price, $months, $pi ) {
	ob_start();
	db_stripe_render_checkout_page( $domain, $price, $months, $pi );
	return ob_get_clean();
}

$GLOBALS['OPT']['db_stripe_mode']         = 'test';
$GLOBALS['OPT']['db_stripe_test_sec_key'] = 'sk_test_x';
$GLOBALS['OPT']['db_stripe_test_pub_key'] = 'pk_test_x';
$GLOBALS['OPT']['admin_email']            = 'admin@example.test';
$pi = array( 'client_secret' => 'pi_1_secret_2', 'pi_id' => 'pi_1' );

// ── Full purchase ──
$h = render( 'fatality.ca', 2500.0, 0, $pi );
ok( substr_count( $h, '<li' ) >= 3 && false !== strpos( $h, 'Billing <span class="co-long">Information</span>' ) && false !== strpos( $h, 'Order <span class="co-long">Confirmation</span>' ) && false !== strpos( $h, '<li>Payment</li>' ), 'three-step progress bar' );
ok( 1 === preg_match( '/id="co-step-1"[^>]*>/', $h ) && 1 === preg_match( '/id="co-step-2"[^>]*hidden/', $h ) && 1 === preg_match( '/id="co-step-3"[^>]*hidden/', $h ), 'step 1 visible, steps 2 and 3 hidden on load' );
ok( false !== strpos( $h, 'Questions? We\'re here to help! Email today:' ) && false !== strpos( $h, 'mailto:sales@domainbrothers.com' ), 'header help line with sales email' );
ok( false !== strpos( $h, 'Pay in full <span class="co-opt-price">$2500.00</span>' ), 'Pay in full shows the price in live format' );
ok( false !== strpos( $h, '<b>$416.67</b>' ) && false !== strpos( $h, '(<b>6</b> Payments)' ), 'Pay less up front shows the 6-month first payment' );
ok( false !== strpos( $h, 'href="https://beta.example.test/payment-plan-setup/?d=ZmF0YWxpdHkuY2E%3D&amp;p=MjUwMA%3D%3D"' ), 'Pay less up front opens the plan chooser for this domain' );
ok( false !== strpos( $h, 'id="co-name"' ) && false !== strpos( $h, 'id="db-stripe-email"' ) && false !== strpos( $h, 'id="co-phone"' ), 'billing fields: full name, email, phone' );
ok( 3 === substr_count( $h, 'class="co-req"' ), 'three required markers' );
ok( false !== strpos( $h, 'Your Order Summary - 1 Item' ) && false !== strpos( $h, 'class="co-sum-price">$2500.00' ), 'order summary with domain price' );
ok( false !== strpos( $h, 'href="https://beta.example.test/domains/fatality-ca/" aria-label="Remove fatality.ca' ), 'remove link returns to the listing' );
ok( false === stripos( $h, 'GST' ), 'no GST wording (charge is the listed USD price)' );
ok( false !== strpos( $h, 'What are the next steps?' ) && 3 === substr_count( $h, '/images/payicon_img' ), 'next steps column with three icons' );
ok( false !== strpos( $h, 'class="co-footer-top"' ) && false !== strpos( $h, 'A Mindshare Consulting Inc company' ) && false !== strpos( $h, '/refund-policy/' ), 'payment-page footer' );
ok( false !== strpos( $h, 'id="db-stripe-payment-element"' ) && false !== strpos( $h, 'id="db-stripe-form"' ) && false !== strpos( $h, 'class="co-btn db-stripe-btn"' ), 'Stripe hooks kept (form, element, pay button)' );
ok( false !== strpos( $h, 'Pay Now</button>' ) && false === strpos( $h, 'id="db-stripe-agree"' ), 'full purchase: Pay Now, no plan agreement' );
ok( false !== strpos( $h, 'window.DB_PI_SECRET  = "pi_1_secret_2"' ), 'client secret passed to the payment script' );
ok( false !== strpos( $h, 'billing_details: { name: val(nameInp), email: email, phone: val(phoneInp) }' ), 'name, email and phone sent to Stripe as billing details' );
ok( false !== strpos( $h, '<meta name="robots" content="noindex, nofollow">' ), 'checkout is noindex' );

// ── Payment plan (12 months, 10% interest) ──
$h = render( 'fatality.ca', 2500.0, 12, $pi );
ok( 1 === preg_match( '/<a class="co-opt" href="https:\/\/beta\.example\.test\/buy-now\/\?d=ZmF0YWxpdHkuY2E%3D&amp;p=MjUwMA%3D%3D">/', $h ), 'plan checkout: Pay in full links back to the full purchase' );
ok( false !== strpos( $h, 'co-opt co-opt--plan is-active' ) && false !== strpos( $h, '(<b>12</b> Payments)' ), 'plan option active with the chosen term' );
ok( false !== strpos( $h, '12 monthly payments of $229.17 (10% interest, total $2750.00)' ), 'confirmation shows term, interest and total' );
ok( false !== strpos( $h, 'id="db-stripe-agree"' ) && false !== strpos( $h, 'Payment plan terms.' ), 'plan terms and agreement on the confirmation step' );
ok( false !== strpos( $h, 'Start Payment Plan</button>' ), 'plan button label' );

// ── Escaping ──
$h = render( 'x"><script>alert(1)</script>.com', 100.0, 0, $pi );
ok( false === strpos( $h, '<script>alert(1)</script>' ), 'domain text is escaped' );

// ── Not configured / error ──
$GLOBALS['OPT']['db_stripe_test_pub_key'] = '';
$h = render( 'fatality.ca', 2500.0, 0, $pi );
ok( false !== strpos( $h, 'Our payment system is being configured.' ) && false === strpos( $h, 'DB_PI_SECRET' ) && false === strpos( $h, 'id="co-step-1"' ), 'not configured: notice only, no payment script or steps' );
$GLOBALS['OPT']['db_stripe_test_pub_key'] = 'pk_test_x';
$h = render( 'fatality.ca', 2500.0, 0, array( 'error' => 'Card network down.' ) );
ok( false !== strpos( $h, 'Card network down.' ) && false === strpos( $h, 'DB_PI_SECRET' ), 'PaymentIntent error shown, no payment script' );

echo implode( "\n", $GLOBALS['LOG'] ), "\n$pass/$pass passed\n";
