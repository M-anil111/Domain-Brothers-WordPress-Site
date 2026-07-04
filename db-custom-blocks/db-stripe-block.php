<?php
// =============================================================================
// DB Stripe Block — Domain Brothers payment integration
// =============================================================================

// ─── Mode & key helpers ────────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_is_test' ) ) {
	/**
	 * Returns true when Stripe is operating in test mode.
	 */
	function db_stripe_is_test() : bool {
		return get_option( 'db_stripe_mode', 'live' ) === 'test';
	}
}

if ( ! function_exists( 'db_stripe_keys' ) ) {
	/**
	 * Returns the active-mode Stripe credentials.
	 *
	 * @return array{pub: string, sec: string, webhook_secret: string}
	 */
	function db_stripe_keys() : array {
		if ( db_stripe_is_test() ) {
			return [
				'pub'            => (string) get_option( 'db_stripe_test_pub_key', '' ),
				'sec'            => (string) get_option( 'db_stripe_test_sec_key', '' ),
				'webhook_secret' => (string) get_option( 'db_stripe_wh_secret_test', '' ),
			];
		}
		return [
			'pub'            => (string) get_option( 'db_stripe_live_pub_key', '' ),
			'sec'            => (string) get_option( 'db_stripe_live_sec_key', '' ),
			'webhook_secret' => (string) get_option( 'db_stripe_wh_secret_live', '' ),
		];
	}
}

// ─── Admin menu ────────────────────────────────────────────────────────────────

add_action( 'admin_menu', static function () {
	add_options_page(
		'DB Stripe Keys',
		'DB Stripe Keys',
		'manage_options',
		'db-stripe-settings',
		'db_stripe_settings_page'
	);
} );

// ─── Settings registration ─────────────────────────────────────────────────────

add_action( 'admin_init', static function () {
	$all_opts = [
		'db_stripe_live_pub_key',
		'db_stripe_live_sec_key',
		'db_stripe_test_pub_key',
		'db_stripe_test_sec_key',
		'db_stripe_wh_secret_live',
		'db_stripe_wh_secret_test',
		'db_stripe_mode',
	];
	foreach ( $all_opts as $opt ) {
		register_setting( 'db_stripe_settings_group', $opt, [ 'sanitize_callback' => 'sanitize_text_field' ] );
	}

	// Section: Stripe Keys
	add_settings_section( 'db_stripe_keys_section', 'Stripe Keys', '__return_false', 'db-stripe-settings' );

	foreach ( [
		'db_stripe_live_pub_key' => 'Live Publishable Key',
		'db_stripe_live_sec_key' => 'Live Secret Key',
		'db_stripe_test_pub_key' => 'Test Publishable Key',
		'db_stripe_test_sec_key' => 'Test Secret Key',
	] as $id => $label ) {
		add_settings_field(
			$id, $label,
			static function () use ( $id ) {
				printf(
					'<input type="text" name="%s" value="%s" class="regular-text" autocomplete="off">',
					esc_attr( $id ),
					esc_attr( (string) get_option( $id, '' ) )
				);
			},
			'db-stripe-settings',
			'db_stripe_keys_section'
		);
	}

	// Section: Webhook
	add_settings_section( 'db_stripe_wh_section', 'Webhook', '__return_false', 'db-stripe-settings' );

	foreach ( [
		'db_stripe_wh_secret_live' => 'Webhook Signing Secret (Live)',
		'db_stripe_wh_secret_test' => 'Webhook Signing Secret (Test)',
	] as $id => $label ) {
		add_settings_field(
			$id, $label,
			static function () use ( $id ) {
				printf(
					'<input type="text" name="%s" value="%s" class="regular-text" autocomplete="off">',
					esc_attr( $id ),
					esc_attr( (string) get_option( $id, '' ) )
				);
			},
			'db-stripe-settings',
			'db_stripe_wh_section'
		);
	}

	// Section: Mode toggle
	add_settings_section( 'db_stripe_mode_section', 'Mode', '__return_false', 'db-stripe-settings' );
	add_settings_field(
		'db_stripe_mode', 'Active Mode',
		static function () {
			$mode = (string) get_option( 'db_stripe_mode', 'live' );
			echo '<label><input type="radio" name="db_stripe_mode" value="live" ' . checked( $mode, 'live', false ) . '> Live</label>';
			echo '&ensp;<label><input type="radio" name="db_stripe_mode" value="test" ' . checked( $mode, 'test', false ) . '> Test</label>';
		},
		'db-stripe-settings',
		'db_stripe_mode_section'
	);
} );

// ─── Settings page render ──────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_settings_page' ) ) {
	function db_stripe_settings_page() : void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'db-blocks' ) );
		}
		$wh_url     = home_url( '/wp-json/db/v1/stripe-webhook' );
		$create_url = admin_url( '/?db_create_webhook=1&_wpnonce=' . wp_create_nonce( 'db_create_webhook' ) );
		$log_url    = admin_url( '/?db_stripe_log=1&_wpnonce=' . wp_create_nonce( 'db_stripe_log' ) );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'db_stripe_settings_group' ); ?>
				<?php do_settings_sections( 'db-stripe-settings' ); ?>
				<?php submit_button(); ?>
			</form>
			<hr>
			<h2>Webhook Endpoint</h2>
			<p>URL for Stripe dashboard: <code><?php echo esc_html( $wh_url ); ?></code></p>
			<p>
				<a class="button" href="<?php echo esc_url( $create_url ); ?>">Auto-Create Stripe Webhook</a>
				&ensp;
				<a class="button" href="<?php echo esc_url( $log_url ); ?>">View Webhook Log</a>
			</p>
		</div>
		<?php
	}
}

// ─── Checkout page (template_redirect) ────────────────────────────────────────

add_action( 'template_redirect', static function () {
	if ( ! is_page( 'buy-now' ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended
	$d_raw = isset( $_GET['d'] ) ? sanitize_text_field( wp_unslash( $_GET['d'] ) ) : '';
	$p_raw = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : '';
	// phpcs:enable

	if ( ! $d_raw || ! $p_raw ) {
		return; // No params — let WordPress render the normal page.
	}

	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$domain    = base64_decode( $d_raw, true );
	$price_raw = base64_decode( $p_raw, true );
	// phpcs:enable

	if ( $domain === false || $price_raw === false ) {
		wp_die( esc_html( 'Invalid request.' ) );
	}

	$domain = sanitize_text_field( $domain );
	$price  = (float) $price_raw;

	if ( ! $domain || $price <= 0.0 ) {
		wp_die( esc_html( 'Invalid request parameters.' ) );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$months_raw = isset( $_GET['m'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['m'] ) ) : 0;
	$is_plan    = in_array( $months_raw, [ 3, 6, 9, 12 ], true );
	$months     = $is_plan ? $months_raw : 0;

	$pi = db_stripe_create_payment_intent( $domain, $price, $months );
	db_stripe_render_checkout_page( $domain, $price, $months, $pi );
	exit;
} );

// ─── PaymentIntent creation ────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_create_payment_intent' ) ) {
	/**
	 * Creates a Stripe PaymentIntent via wp_remote_post.
	 * For payment plans, creates an intent for the first installment only.
	 *
	 * @return array{client_secret?: string, pi_id?: string, amount_cents?: int, error?: string}
	 */
	function db_stripe_create_payment_intent( string $domain, float $price_usd, int $months ) : array {
		$keys = db_stripe_keys();

		if ( empty( $keys['sec'] ) ) {
			return [ 'error' => 'Payment system is not configured. Please contact support.' ];
		}

		$amount_cents = $months > 0
			? (int) ceil( $price_usd / $months * 100 )
			: (int) round( $price_usd * 100 );

		$body = [
			'amount'                             => $amount_cents,
			'currency'                           => 'usd',
			'automatic_payment_methods[enabled]' => 'true',
			'metadata[domain]'                   => $domain,
			'metadata[type]'                     => $months > 0 ? 'plan' : 'full',
			'metadata[total_price_usd]'          => (string) $price_usd,
		];

		if ( $months > 0 ) {
			$body['metadata[total_months]']       = (string) $months;
			$body['metadata[installment_number]'] = '1';
		}

		$response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', [
			'headers'   => [
				'Authorization' => 'Bearer ' . $keys['sec'],
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'      => $body,
			'timeout'   => 30,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) ) {
			error_log( 'DB Stripe: PaymentIntent creation failed — ' . $response->get_error_message() );
			return [ 'error' => 'Payment system is temporarily unavailable. Please try again.' ];
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$data      = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $http_code !== 200 || empty( $data['client_secret'] ) ) {
			$stripe_msg = $data['error']['message'] ?? 'An unexpected error occurred.';
			error_log( 'DB Stripe: Stripe API error ' . $http_code . ' — ' . $stripe_msg );
			return [ 'error' => $stripe_msg ];
		}

		return [
			'client_secret' => $data['client_secret'],
			'pi_id'         => $data['id'],
			'amount_cents'  => $amount_cents,
		];
	}
}

// ─── Checkout page HTML render ─────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_render_checkout_page' ) ) {
	function db_stripe_render_checkout_page( string $domain, float $price, int $months, array $pi ) : void {
		$keys           = db_stripe_keys();
		$pub_key        = $keys['pub'];
		$is_plan        = $months > 0;
		$has_error      = isset( $pi['error'] );
		$client_secret  = $pi['client_secret'] ?? '';
		$not_configured = empty( $pub_key ) || empty( $keys['sec'] );

		$price_display   = '$' . number_format( $price, 0, '.', ',' );
		$monthly_cents   = $is_plan ? (int) ceil( $price / $months * 100 ) : 0;
		$monthly_display = $is_plan ? '$' . number_format( $monthly_cents / 100, 2 ) : '';
		$btn_label       = $is_plan ? 'Start Payment Plan' : 'Pay Now';
		$return_url      = home_url(
			'/thank-you/?type=' . ( $is_plan ? 'plan' : 'full' )
			. '&domain=' . rawurlencode( $domain )
		);

		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Checkout — <?php echo esc_html( $domain ); ?> — Domain Brothers</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:linear-gradient(135deg,#0a1628 0%,#1a2d4f 55%,#0d2140 100%);min-height:100vh;color:#fff;display:flex;flex-direction:column}
/* Header */
.db-stripe-header{padding:16px 32px;border-bottom:1px solid rgba(255,255,255,.08);display:flex;align-items:center}
.db-stripe-logo{font-size:1.3rem;font-weight:700;letter-spacing:-.5px;color:#fff;text-decoration:none}
.db-stripe-logo span{color:#4f9cf9}
/* Layout */
.db-stripe-main{flex:1;display:flex;align-items:flex-start;justify-content:center;padding:48px 16px 64px}
.db-stripe-card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:40px;width:100%;max-width:520px;backdrop-filter:blur(10px)}
/* Domain & Price */
.db-stripe-domain{font-size:1.55rem;font-weight:700;color:#4f9cf9;word-break:break-all;margin-bottom:6px}
.db-stripe-price{font-size:2.5rem;font-weight:800;margin-bottom:24px}
/* Plan info */
.db-stripe-plan-box{background:rgba(79,156,249,.1);border:1px solid rgba(79,156,249,.25);border-radius:10px;padding:14px 18px;margin-bottom:24px;font-size:.93rem;color:rgba(255,255,255,.85);line-height:1.5}
.db-stripe-plan-box strong{color:#4f9cf9;font-size:1.15rem}
.db-stripe-badge{display:inline-block;background:#16a34a;color:#fff;font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;vertical-align:middle;margin-left:6px}
/* Payment element */
.db-stripe-pe-wrap{background:#fff;border-radius:10px;padding:20px 18px;margin-bottom:18px;min-height:80px}
/* Button */
.db-stripe-btn{width:100%;padding:15px;background:#2563eb;color:#fff;border:none;border-radius:10px;font-size:1.02rem;font-weight:700;cursor:pointer;transition:background .15s,transform .1s;margin-bottom:20px}
.db-stripe-btn:hover:not(:disabled){background:#1d4ed8}
.db-stripe-btn:active:not(:disabled){transform:scale(.98)}
.db-stripe-btn:disabled{background:#475569;cursor:not-allowed}
/* Error */
.db-stripe-err{background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.4);border-radius:8px;padding:11px 15px;color:#fca5a5;font-size:.88rem;margin-bottom:14px;display:none}
/* Trust row */
.db-stripe-trust{display:flex;gap:18px;justify-content:center;flex-wrap:wrap}
.db-stripe-trust-item{display:flex;align-items:center;gap:5px;font-size:.78rem;color:rgba(255,255,255,.45)}
.db-stripe-trust-item svg{width:13px;height:13px;fill:rgba(255,255,255,.35);flex-shrink:0}
/* Notice */
.db-stripe-notice{text-align:center;padding:24px 0;color:rgba(255,255,255,.6);font-size:.95rem;line-height:1.6}
.db-stripe-notice a{color:#4f9cf9}
@media(max-width:540px){.db-stripe-card{padding:26px 18px}.db-stripe-domain{font-size:1.25rem}.db-stripe-price{font-size:2rem}}
</style>
</head>
<body>
<header class="db-stripe-header">
	<a class="db-stripe-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">Domain<span>Brothers</span></a>
</header>
<main class="db-stripe-main">
	<div class="db-stripe-card">

<?php if ( $not_configured ) : ?>
		<div class="db-stripe-notice">
			<p>Our payment system is being configured.</p>
			<p style="margin-top:8px">Please <a href="mailto:<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">contact support</a> to complete your purchase.</p>
		</div>

<?php elseif ( $has_error ) : ?>
		<div class="db-stripe-notice">
			<p><?php echo esc_html( $pi['error'] ); ?></p>
			<p style="margin-top:8px;font-size:.85rem;opacity:.7">Please try again or <a href="mailto:<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">contact support</a>.</p>
		</div>

<?php else : ?>
		<p class="db-stripe-domain"><?php echo esc_html( $domain ); ?></p>
		<p class="db-stripe-price"><?php echo esc_html( $price_display ); ?></p>

<?php if ( $is_plan ) : ?>
		<div class="db-stripe-plan-box">
			<strong><?php echo esc_html( $monthly_display ); ?>/mo</strong>
			&times; <?php echo esc_html( (string) $months ); ?> months
			<span class="db-stripe-badge">0% interest</span>
			<br><small style="opacity:.65;margin-top:4px;display:block">First installment charged today. Remaining installments billed monthly.</small>
		</div>
<?php endif; ?>

		<div id="db-stripe-err" class="db-stripe-err"></div>
		<form id="db-stripe-form" novalidate>
			<div class="db-stripe-pe-wrap">
				<div id="db-stripe-payment-element"></div>
			</div>
			<button type="submit" id="db-stripe-btn" class="db-stripe-btn"><?php echo esc_html( $btn_label ); ?></button>
		</form>

		<div class="db-stripe-trust">
			<span class="db-stripe-trust-item">
				<svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5z"/></svg>
				SSL Secured
			</span>
			<span class="db-stripe-trust-item">
				<svg viewBox="0 0 24 24"><path d="M20 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>
				Escrow Protected
			</span>
			<span class="db-stripe-trust-item">
				<svg viewBox="0 0 24 24"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>
				24-hr Support
			</span>
		</div>
<?php endif; ?>

	</div>
</main>

<?php if ( ! $not_configured && ! $has_error && $client_secret ) : ?>
<script>
window.DB_PUB_KEY    = <?php echo wp_json_encode( $pub_key ); ?>;
window.DB_PI_SECRET  = <?php echo wp_json_encode( $client_secret ); ?>;
window.DB_RETURN_URL = <?php echo wp_json_encode( $return_url ); ?>;
window.DB_BTN_LABEL  = <?php echo wp_json_encode( $btn_label ); ?>;
</script>
<script src="https://js.stripe.com/v3/" crossorigin="anonymous"></script>
<script>
(function () {
	'use strict';
	function waitStripe(cb) {
		if (typeof Stripe !== 'undefined') { cb(); return; }
		var iv = setInterval(function () { if (typeof Stripe !== 'undefined') { clearInterval(iv); cb(); } }, 80);
	}
	waitStripe(function () {
		var stripe   = Stripe(window.DB_PUB_KEY);
		var elements = stripe.elements({ clientSecret: window.DB_PI_SECRET, appearance: { theme: 'stripe', variables: { borderRadius: '6px' } } });
		var payEl    = elements.create('payment');
		payEl.mount('#db-stripe-payment-element');

		var form   = document.getElementById('db-stripe-form');
		var btn    = document.getElementById('db-stripe-btn');
		var errDiv = document.getElementById('db-stripe-err');

		function showErr(msg) {
			errDiv.textContent = msg;
			errDiv.style.display = 'block';
			btn.disabled = false;
			btn.textContent = window.DB_BTN_LABEL;
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			btn.disabled = true;
			btn.textContent = 'Processing…';
			errDiv.style.display = 'none';

			stripe.confirmPayment({
				elements: elements,
				confirmParams: { return_url: window.DB_RETURN_URL },
				redirect: 'if_required'
			}).then(function (result) {
				if (result.error) {
					showErr(result.error.message || 'Payment failed. Please try again.');
				} else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
					window.location.href = window.DB_RETURN_URL;
				} else {
					showErr('Payment could not be confirmed. Please contact support.');
				}
			}).catch(function () {
				showErr('An unexpected error occurred. Please refresh and try again.');
			});
		});
	});
}());
</script>
<?php endif; ?>
</body>
</html>
		<?php
	}
}

// ─── REST: register webhook route ─────────────────────────────────────────────

add_action( 'rest_api_init', static function () {
	register_rest_route( 'db/v1', '/stripe-webhook', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'db_stripe_handle_webhook',
		'permission_callback' => '__return_true',
	] );
} );

// ─── Init fallback webhook (?db_stripe_wh=1) ──────────────────────────────────

add_action( 'init', static function () {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $_GET['db_stripe_wh'] ) || '1' !== $_GET['db_stripe_wh'] ) {
		return;
	}
	db_stripe_handle_webhook( null );
	exit;
} );

// ─── Webhook handler ──────────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_handle_webhook' ) ) {
	/**
	 * Handles incoming Stripe webhook events.
	 * Compatible with both REST API dispatch and the ?db_stripe_wh=1 fallback.
	 *
	 * @param WP_REST_Request|null $request
	 * @return WP_REST_Response|void
	 */
	function db_stripe_handle_webhook( $request = null ) {
		$payload    = (string) file_get_contents( 'php://input' );
		$sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] )
			? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) )
			: '';

		if ( ! $payload || ! $sig_header ) {
			return db_stripe_wh_respond( $request, [ 'error' => 'Missing payload or signature.' ], 400 );
		}

		// Try both live and test signing secrets; use whichever verifies.
		$event = null;
		foreach ( [
			(string) get_option( 'db_stripe_wh_secret_live', '' ),
			(string) get_option( 'db_stripe_wh_secret_test', '' ),
		] as $secret ) {
			if ( $secret ) {
				$event = db_stripe_verify_webhook_sig( $payload, $sig_header, $secret );
				if ( null !== $event ) {
					break;
				}
			}
		}

		if ( null === $event ) {
			error_log( 'DB Stripe: Webhook signature verification failed.' );
			return db_stripe_wh_respond( $request, [ 'error' => 'Invalid signature.' ], 400 );
		}

		$event_type = (string) ( $event['type'] ?? '' );
		$obj        = (array)  ( $event['data']['object'] ?? [] );
		$domain     = (string) ( $obj['metadata']['domain'] ?? '' );

		$log = [
			'ts'         => time(),
			'event_type' => $event_type,
			'domain'     => $domain,
			'status'     => 'received',
		];

		switch ( $event_type ) {
			case 'payment_intent.succeeded':
				db_stripe_handle_pi_succeeded( $obj );
				$log['status'] = 'processed';
				break;

			case 'invoice.payment_failed':
				$email      = (string) ( $obj['customer_email'] ?? '' );
				$amount_usd = isset( $obj['amount_due'] ) ? round( (float) $obj['amount_due'] / 100, 2 ) : 0.0;
				db_stripe_on_payment_failed( $domain, $email, $amount_usd );
				$log['status'] = 'processed';
				break;

			case 'charge.refunded':
				db_stripe_log_refund( $obj );
				$log['status'] = 'logged';
				break;

			default:
				$log['status'] = 'ignored';
				break;
		}

		db_stripe_append_wh_log( $log );
		return db_stripe_wh_respond( $request, [ 'received' => true ], 200 );
	}
}

// ─── Webhook response helper ───────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_wh_respond' ) ) {
	/**
	 * Sends a JSON response either as a WP_REST_Response (REST context) or via
	 * http_response_code + echo + exit (init fallback context).
	 *
	 * @return WP_REST_Response|void
	 */
	function db_stripe_wh_respond( $request, array $data, int $code ) {
		if ( $request instanceof WP_REST_Request ) {
			return new WP_REST_Response( $data, $code );
		}
		http_response_code( $code );
		header( 'Content-Type: application/json' );
		echo wp_json_encode( $data );
		exit;
	}
}

// ─── Signature verification ────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_verify_webhook_sig' ) ) {
	/**
	 * Verifies a Stripe webhook signature.
	 * Returns the decoded event array on success, null on failure.
	 */
	function db_stripe_verify_webhook_sig( string $payload, string $sig_header, string $secret ) : ?array {
		$timestamp = null;
		$v1_sig    = null;

		foreach ( explode( ',', $sig_header ) as $chunk ) {
			$parts = explode( '=', $chunk, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			if ( $parts[0] === 't' ) {
				$timestamp = $parts[1];
			} elseif ( $parts[0] === 'v1' ) {
				$v1_sig = $parts[1];
			}
		}

		if ( ! $timestamp || ! $v1_sig ) {
			return null;
		}

		// Reject events older than 5 minutes to prevent replay attacks.
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			error_log( 'DB Stripe: Webhook timestamp is stale.' );
			return null;
		}

		$computed = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		if ( ! hash_equals( $computed, $v1_sig ) ) {
			return null;
		}

		$event = json_decode( $payload, true );
		return is_array( $event ) ? $event : null;
	}
}

// ─── payment_intent.succeeded ─────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_handle_pi_succeeded' ) ) {
	function db_stripe_handle_pi_succeeded( array $pi ) : void {
		$meta            = (array)  ( $pi['metadata'] ?? [] );
		$domain          = (string) ( $meta['domain'] ?? '' );
		$type            = (string) ( $meta['type'] ?? 'full' );
		$total_months    = isset( $meta['total_months'] )    ? (int) $meta['total_months']    : 0;
		$installment_num = isset( $meta['installment_number'] ) ? (int) $meta['installment_number'] : 1;
		$amount_usd      = isset( $pi['amount_received'] )   ? round( (float) $pi['amount_received'] / 100, 2 ) : 0.0;

		// Resolve customer email from PaymentIntent or first charge
		$customer_email = (string) ( $pi['receipt_email'] ?? '' );
		if ( ! $customer_email ) {
			$customer_email = (string) ( $pi['charges']['data'][0]['billing_details']['email'] ?? '' );
		}

		if ( $type === 'full' ) {
			db_stripe_on_sale_complete( $domain, $amount_usd, $customer_email, 'full' );
			return;
		}

		// Payment plan — record this installment.
		error_log( sprintf(
			'DB Stripe: Plan installment %d/%d paid — %s ($%.2f)',
			$installment_num, $total_months, $domain, $amount_usd
		) );

		$opt_key   = 'db_stripe_plan_' . md5( $domain );
		$history   = (array) get_option( $opt_key, [] );
		$history[] = [
			'installment' => $installment_num,
			'amount_usd'  => $amount_usd,
			'ts'          => time(),
		];
		update_option( $opt_key, $history, false );

		if ( $installment_num >= $total_months ) {
			$total_price = isset( $meta['total_price_usd'] ) ? (float) $meta['total_price_usd'] : $amount_usd;
			db_stripe_on_sale_complete( $domain, $total_price, $customer_email, 'plan' );
		}
	}
}

// ─── Email: sale complete ──────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_on_sale_complete' ) ) {
	function db_stripe_on_sale_complete( string $domain, float $amount_usd, string $customer_email, string $type ) : void {
		$admin_email    = (string) get_option( 'admin_email' );
		$site_name      = get_bloginfo( 'name' );
		$amount_display = '$' . number_format( $amount_usd, 2 );
		$type_label     = $type === 'plan' ? 'Payment Plan (Complete)' : 'Full Purchase';

		// Customer receipt
		if ( $customer_email ) {
			$subj = sprintf( 'Your domain %s is secured!', $domain );
			$body = implode( "\n", [
				'Congratulations!',
				'',
				"Your purchase of {$domain} ({$type_label}) has been completed successfully.",
				'',
				"Amount paid: {$amount_display}",
				'',
				'Our team will contact you shortly to initiate the domain transfer.',
				'',
				"Thank you for choosing {$site_name}.",
			] );

			if ( function_exists( 'db_send_branded_email' ) ) {
				db_send_branded_email( $customer_email, $subj, $body );
			} else {
				wp_mail( $customer_email, $subj, $body );
			}
		}

		// Admin alert
		$admin_subj = sprintf( 'DOMAIN SOLD: %s for %s', $domain, $amount_display );
		$admin_body = implode( "\n", [
			'A domain sale has been completed.',
			'',
			"Domain   : {$domain}",
			"Amount   : {$amount_display}",
			"Type     : {$type_label}",
			'Customer : ' . ( $customer_email ?: 'Unknown' ),
			'Time     : ' . wp_date( 'Y-m-d H:i:s T' ),
		] );

		if ( function_exists( 'db_send_branded_email' ) ) {
			db_send_branded_email( $admin_email, $admin_subj, $admin_body );
		} else {
			wp_mail( $admin_email, $admin_subj, $admin_body );
		}
	}
}

// ─── Email: payment failed ─────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_on_payment_failed' ) ) {
	function db_stripe_on_payment_failed( string $domain, string $customer_email, float $amount_usd ) : void {
		$admin_email    = (string) get_option( 'admin_email' );
		$site_name      = get_bloginfo( 'name' );
		$amount_display = '$' . number_format( $amount_usd, 2 );

		// Customer notification
		if ( $customer_email ) {
			$subj = sprintf( 'Payment failed for %s — please update your payment method', $domain );
			$body = implode( "\n", [
				"We were unable to process your payment for {$domain} ({$amount_display}).",
				'',
				'Please update your payment method as soon as possible to avoid losing your reservation.',
				'',
				'Reply to this email or contact support if you need assistance.',
				'',
				"— {$site_name} Team",
			] );

			if ( function_exists( 'db_send_branded_email' ) ) {
				db_send_branded_email( $customer_email, $subj, $body );
			} else {
				wp_mail( $customer_email, $subj, $body );
			}
		}

		// Admin alert
		$admin_subj = sprintf( 'PAYMENT FAILED: %s', $domain );
		$admin_body = implode( "\n", [
			'A payment has failed.',
			'',
			"Domain   : {$domain}",
			"Amount   : {$amount_display}",
			'Customer : ' . ( $customer_email ?: 'Unknown' ),
			'Time     : ' . wp_date( 'Y-m-d H:i:s T' ),
		] );

		if ( function_exists( 'db_send_branded_email' ) ) {
			db_send_branded_email( $admin_email, $admin_subj, $admin_body );
		} else {
			wp_mail( $admin_email, $admin_subj, $admin_body );
		}
	}
}

// ─── Log refund ────────────────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_log_refund' ) ) {
	function db_stripe_log_refund( array $charge ) : void {
		$log = (array) get_option( 'db_stripe_refund_log', [] );
		array_unshift( $log, [
			'ts'         => time(),
			'charge_id'  => (string) ( $charge['id'] ?? '' ),
			'amount_usd' => isset( $charge['amount_refunded'] ) ? round( (float) $charge['amount_refunded'] / 100, 2 ) : 0.0,
			'domain'     => (string) ( $charge['metadata']['domain'] ?? '' ),
		] );
		update_option( 'db_stripe_refund_log', array_slice( $log, 0, 50 ), false );
	}
}

// ─── Append to webhook log ─────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_append_wh_log' ) ) {
	function db_stripe_append_wh_log( array $entry ) : void {
		$log = get_transient( 'db_stripe_wh_log' );
		$log = is_array( $log ) ? $log : [];
		array_unshift( $log, $entry );
		set_transient( 'db_stripe_wh_log', array_slice( $log, 0, 20 ), WEEK_IN_SECONDS );
	}
}

// ─── Admin: auto-create Stripe webhook ────────────────────────────────────────

add_action( 'init', static function () {
	if ( ! isset( $_GET['db_create_webhook'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'Unauthorized.' ) );
	}
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'db_create_webhook' ) ) {
		wp_die( esc_html( 'Security check failed.' ) );
	}

	$keys = db_stripe_keys();
	if ( empty( $keys['sec'] ) ) {
		wp_die( esc_html( 'Stripe secret key is not configured.' ) );
	}

	$events = [
		'payment_intent.succeeded',
		'payment_intent.payment_failed',
		'invoice.paid',
		'invoice.payment_failed',
		'customer.subscription.deleted',
		'charge.refunded',
	];

	$body = [ 'url' => home_url( '/wp-json/db/v1/stripe-webhook' ) ];
	foreach ( $events as $i => $ev ) {
		$body[ 'enabled_events[' . $i . ']' ] = $ev;
	}

	$response = wp_remote_post( 'https://api.stripe.com/v1/webhook_endpoints', [
		'headers'   => [
			'Authorization' => 'Bearer ' . $keys['sec'],
			'Content-Type'  => 'application/x-www-form-urlencoded',
		],
		'body'      => $body,
		'timeout'   => 30,
		'sslverify' => true,
	] );

	if ( is_wp_error( $response ) ) {
		wp_die( esc_html( 'Stripe API error: ' . $response->get_error_message() ) );
	}

	$http_code = (int) wp_remote_retrieve_response_code( $response );
	$data      = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $http_code !== 200 || empty( $data['secret'] ) ) {
		$msg = $data['error']['message'] ?? 'Unknown error creating webhook endpoint.';
		wp_die( esc_html( 'Failed to create webhook: ' . $msg ) );
	}

	$opt_key = db_stripe_is_test() ? 'db_stripe_wh_secret_test' : 'db_stripe_wh_secret_live';
	update_option( $opt_key, sanitize_text_field( $data['secret'] ) );

	wp_die(
		'<p><strong>Webhook endpoint created successfully!</strong></p>'
		. '<p>Endpoint ID: <code>' . esc_html( $data['id'] ) . '</code></p>'
		. '<p>Signing secret has been saved to WP options.</p>'
		. '<p><a href="' . esc_url( admin_url( 'options-general.php?page=db-stripe-settings' ) ) . '">&larr; Return to Stripe Settings</a></p>',
		'Webhook Created'
	);
} );

// ─── Admin: webhook log viewer ─────────────────────────────────────────────────

add_action( 'init', static function () {
	if ( ! isset( $_GET['db_stripe_log'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html( 'Unauthorized.' ) );
	}
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
	if ( ! wp_verify_nonce( $nonce, 'db_stripe_log' ) ) {
		wp_die( esc_html( 'Security check failed.' ) );
	}

	$log  = get_transient( 'db_stripe_wh_log' );
	$log  = is_array( $log ) ? $log : [];
	$rows = '';

	foreach ( $log as $e ) {
		$rows .= '<tr>'
			. '<td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $e['ts'] ) ) . '</td>'
			. '<td>' . esc_html( (string) $e['event_type'] ) . '</td>'
			. '<td>' . esc_html( (string) $e['domain'] ) . '</td>'
			. '<td>' . esc_html( (string) $e['status'] ) . '</td>'
			. '</tr>';
	}

	$html  = '<style>body{font-family:sans-serif;padding:24px;max-width:900px}';
	$html .= 'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:8px 14px;text-align:left}';
	$html .= 'th{background:#f0f0f0}tr:nth-child(even){background:#f9f9f9}</style>';
	$html .= '<h2>DB Stripe Webhook Log &mdash; Last 20 Events</h2>';
	$html .= $rows
		? '<table><thead><tr><th>Time</th><th>Event Type</th><th>Domain</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table>'
		: '<p>No events logged yet.</p>';
	$html .= '<p style="margin-top:20px"><a href="' . esc_url( admin_url( 'options-general.php?page=db-stripe-settings' ) ) . '">&larr; Back to Stripe Settings</a></p>';

	wp_die( $html, 'DB Stripe Webhook Log', [ 'back_link' => false ] );
} );
