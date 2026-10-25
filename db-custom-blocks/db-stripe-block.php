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

if ( ! function_exists( 'db_stripe_mode' ) ) {
	function db_stripe_mode() : string {
		return db_stripe_is_test() ? 'test' : 'live';
	}
}

if ( ! function_exists( 'db_stripe_norm' ) ) {
	/**
	 * The one spelling of a domain used for metadata, record keys and the sold
	 * flag. Mixed case ("Fatality.ca" vs "FATALITY.CA") used to get past the
	 * "already sold / reserved" check.
	 */
	function db_stripe_norm( string $domain ) : string {
		return strtolower( trim( $domain ) );
	}
}

if ( ! function_exists( 'db_stripe_store_key' ) ) {
	/**
	 * Option name for a domain's 'plan' or 'sold' record. Test mode has its
	 * own records, so a test-card purchase never reserves or sells a real
	 * listing.
	 */
	function db_stripe_store_key( string $kind, string $domain ) : string {
		$prefix = 'test' === db_stripe_mode() ? 'db_stripe_test_' : 'db_stripe_';
		return $prefix . $kind . '_' . md5( db_stripe_norm( $domain ) );
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

// ─── Authoritative price lookup ────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_lookup_domain_price' ) ) {
	/**
	 * Looks up the real, authoritative price for a domain listing from its
	 * `domain` CPT post (domain_price meta) by exact (case-insensitive) title
	 * match. This is the ONLY source of truth for what a checkout charges —
	 * never the client-supplied ?p= URL parameter.
	 *
	 * @return float|null Price in USD, or null if no matching listing exists.
	 */
	function db_stripe_lookup_domain_price( string $domain ) : ?float {
		$q = new WP_Query( [
			'post_type'      => 'domain',
			'post_status'    => 'publish',
			'title'          => $domain,
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		] );

		if ( empty( $q->posts ) ) {
			return null;
		}

		$post = $q->posts[0];
		if ( 0 !== strcasecmp( $post->post_title, $domain ) ) {
			return null; // WP_Query's 'title' can loosely match — require exact.
		}

		$raw   = (string) get_post_meta( $post->ID, 'domain_price', true );
		$clean = preg_replace( '/[^0-9.]/', '', $raw );
		$price = is_numeric( $clean ) ? (float) $clean : 0.0;

		return $price > 0.0 ? $price : null;
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
		// Never fall through to the theme here. DomainFolio's buy-now template
		// fatals when it renders without a selected domain, so a bare
		// /buy-now/ returned HTTP 500 to every direct visitor and to
		// Googlebot. There is nothing meaningful to show without a domain
		// anyway, so send the visitor to the marketplace.
		wp_safe_redirect( home_url( '/all-domains/' ), 302 );
		exit;
	}

	// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$domain    = base64_decode( $d_raw, true );
	$price_raw = base64_decode( $p_raw, true );
	// phpcs:enable

	if ( $domain === false || $price_raw === false ) {
		wp_die( esc_html( 'Invalid request.' ) );
	}

	$domain       = sanitize_text_field( $domain );
	$client_price = (float) $price_raw;

	if ( ! $domain || $client_price <= 0.0 ) {
		wp_die( esc_html( 'Invalid request parameters.' ) );
	}

	// SECURITY: the URL's price is client-supplied and untrusted — it only
	// selects WHICH domain to show. The actual charge amount always comes
	// from the domain listing's own domain_price meta, looked up here.
	// Without this, anyone could edit ?p= and buy any domain for $1.
	$price = db_stripe_lookup_domain_price( $domain );
	if ( null === $price ) {
		wp_die( esc_html( 'This domain listing could not be verified. Please contact support.' ) );
	}
	$domain = db_stripe_norm( $domain );

	// 'months', not 'm' — m is a reserved WordPress query var (date archive
	// month); a request carrying it turns the main query into an empty date
	// archive and the buy-now page 404s before this hook can render.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$months_raw = isset( $_GET['months'] ) ? (int) sanitize_text_field( wp_unslash( $_GET['months'] ) ) : 0;
	$is_plan    = in_array( $months_raw, [ 3, 6, 9, 12 ], true );
	$months     = $is_plan ? $months_raw : 0;

	// Never take a second buyer's money for a name that is already sold or on
	// someone else's active payment plan: the listing stays published until
	// an admin unpublishes it, so without this two people could pay for the
	// same domain.
	if ( db_stripe_domain_unavailable( $domain ) ) {
		wp_die(
			'<h1>This domain is no longer available</h1><p>' . esc_html( $domain ) . ' has been sold or is reserved on a payment plan. <a href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse other premium domains</a> or <a href="mailto:sales@domainbrothers.com">contact us</a>.</p>',
			'Domain unavailable',
			[ 'response' => 410 ]
		);
	}

	$pi = db_stripe_create_payment_intent( $domain, $price, $months );
	db_stripe_render_checkout_page( $domain, $price, $months, $pi );
	exit;
} );

// ─── Installment math (must match the payment-plan widget exactly) ────────────

if ( ! function_exists( 'db_stripe_installment_cents' ) ) {
	/**
	 * Cents for installment $n (1-indexed) of a $months-month plan (interest per db_plan_rate()).
	 * Mirrors the widget's installments(total, months) JS exactly: every
	 * installment is round(total/months) in cents except the last, which absorbs
	 * the rounding remainder so the sum always equals price + the term's interest.
	 */
	function db_stripe_installment_cents( float $price_usd, int $months, int $n ) : int {
		// Total includes the term's interest (db_plan_rate(): 0% for 3/6
		// months, 5% for 9, 10% for 12) — same figure the plan chooser shows.
		$total_cents   = db_plan_total_cents( $price_usd, $months );
		$monthly_cents = (int) round( $total_cents / $months );
		if ( $n < $months ) {
			return $monthly_cents;
		}
		return $total_cents - $monthly_cents * ( $months - 1 );
	}
}

// ─── PaymentIntent creation ────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_create_payment_intent' ) ) {
	/**
	 * Creates a Stripe PaymentIntent via wp_remote_post.
	 *
	 * For a full purchase, this is the only charge. For a payment plan, this
	 * creates installment 1 AND a Stripe Customer with the payment method
	 * saved for off-session reuse (setup_future_usage) — db_stripe_charge_next_installment()
	 * uses that saved customer/payment_method to charge installments 2..N
	 * automatically via WP-Cron. Without this, plans only ever collected the
	 * first installment and silently never billed the rest.
	 *
	 * @return array{client_secret?: string, pi_id?: string, amount_cents?: int, error?: string}
	 */
	function db_stripe_create_payment_intent( string $domain, float $price_usd, int $months ) : array {
		$keys = db_stripe_keys();

		if ( empty( $keys['sec'] ) ) {
			return [ 'error' => 'Payment system is not configured. Please contact support.' ];
		}

		$is_plan      = $months > 0;
		$amount_cents = $is_plan ? db_stripe_installment_cents( $price_usd, $months, 1 ) : (int) round( $price_usd * 100 );

		$customer_id = '';
		if ( $is_plan ) {
			$cust_response = wp_remote_post( 'https://api.stripe.com/v1/customers', [
				'headers'   => [
					'Authorization' => 'Bearer ' . $keys['sec'],
					'Content-Type'  => 'application/x-www-form-urlencoded',
				],
				'body'      => [ 'description' => 'Payment plan — ' . $domain ],
				'timeout'   => 15,
				'sslverify' => true,
			] );
			if ( is_wp_error( $cust_response ) ) {
				error_log( 'DB Stripe: Customer creation failed — ' . $cust_response->get_error_message() );
				return [ 'error' => 'Payment system is temporarily unavailable. Please try again.' ];
			}
			$cust_data   = json_decode( wp_remote_retrieve_body( $cust_response ), true );
			$customer_id = (string) ( $cust_data['id'] ?? '' );
			if ( ! $customer_id ) {
				return [ 'error' => 'Could not start payment plan. Please try again.' ];
			}
		}

		$body = [
			'amount'                             => $amount_cents,
			'currency'                           => 'usd',
			'automatic_payment_methods[enabled]' => 'true',
			'metadata[domain]'                   => $domain,
			'metadata[type]'                     => $is_plan ? 'plan' : 'full',
			'metadata[total_price_usd]'          => (string) $price_usd,
		];

		if ( $is_plan ) {
			$body['customer']                     = $customer_id;
			$body['setup_future_usage']           = 'off_session';
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
		$monthly_cents   = $is_plan ? db_stripe_installment_cents( $price, $months, 1 ) : 0;
		$monthly_display = $is_plan ? '$' . number_format( $monthly_cents / 100, 2 ) : '';
		$btn_label       = $is_plan ? 'Start Payment Plan' : 'Pay Now';
		// 'd', not 'domain' — the domain CPT registers 'domain' as its own
		// public query var, so ?domain=... hijacks the main query away from
		// the thank-you page (renders the listing or a 404 instead).
		$ty_type         = $is_plan ? 'plan' : 'full';
		$ty_amount       = number_format( $price, 0, '.', ',' );
		$return_url      = home_url(
			'/thank-you/?type=' . $ty_type
			. '&d=' . rawurlencode( $domain )
			. '&amt=' . rawurlencode( $ty_amount )
			// Signed, so the confirmation page can't be faked by editing the URL.
			. ( function_exists( 'db_ty_sig' ) ? '&s=' . db_ty_sig( $ty_type, $domain, $ty_amount ) : '' )
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
/* Order summary */
.db-stripe-summary{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:18px 20px;margin-bottom:24px}
.db-stripe-domain{font-size:1.55rem;font-weight:700;color:#4f9cf9;word-break:break-all;margin-bottom:6px}
.db-stripe-price{font-size:2.5rem;font-weight:800;line-height:1.1}
.db-stripe-onetime{font-size:.8rem;color:rgba(255,255,255,.55);margin-top:8px}
/* Plan info */
.db-stripe-plan-box{background:rgba(79,156,249,.1);border:1px solid rgba(79,156,249,.25);border-radius:10px;padding:14px 18px;margin-bottom:24px;font-size:.93rem;color:rgba(255,255,255,.85);line-height:1.5}
.db-stripe-plan-box strong{color:#4f9cf9;font-size:1.15rem}
.db-stripe-badge{display:inline-block;background:#16a34a;color:#fff;font-size:.68rem;font-weight:700;padding:2px 7px;border-radius:20px;text-transform:uppercase;letter-spacing:.5px;vertical-align:middle;margin-left:6px}
/* Email field */
.db-stripe-label{display:block;font-size:.78rem;font-weight:600;color:rgba(255,255,255,.65);text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px}
.db-stripe-input{width:100%;padding:13px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.15);background:rgba(255,255,255,.06);color:#fff;font-size:.95rem;margin-bottom:18px;transition:border-color .15s,box-shadow .15s}
.db-stripe-input::placeholder{color:rgba(255,255,255,.35)}
.db-stripe-input:focus{outline:none;border-color:#4f9cf9;box-shadow:0 0 0 3px rgba(79,156,249,.25)}
/* Payment element */
.db-stripe-pe-wrap{background:#fff;border-radius:12px;padding:20px 18px;margin-bottom:18px;min-height:80px}
/* Button */
.db-stripe-btn{width:100%;padding:17px 20px;background:#2563eb;color:#fff;border:none;border-radius:12px;font-size:1.02rem;font-weight:700;cursor:pointer;transition:background .15s,transform .1s;margin-bottom:20px;display:flex;align-items:center;justify-content:center;gap:8px}
.db-stripe-btn svg{width:15px;height:15px;fill:currentColor;flex-shrink:0}
.db-stripe-btn:hover:not(:disabled){background:#1d4ed8}
.db-stripe-btn:active:not(:disabled){transform:scale(.98)}
.db-stripe-btn:disabled{background:#475569;cursor:not-allowed}
/* Error */
.db-stripe-err{background:rgba(239,68,68,.14);border:1px solid rgba(239,68,68,.4);border-radius:8px;padding:11px 15px;color:#fca5a5;font-size:.88rem;margin-bottom:14px;display:none}
/* Trust row */
.db-stripe-trust{display:flex;gap:22px;justify-content:center;flex-wrap:wrap}
.db-stripe-trust-item{display:flex;align-items:center;gap:6px;font-size:.78rem;color:rgba(255,255,255,.55)}
.db-stripe-trust-item svg{width:13px;height:13px;fill:rgba(255,255,255,.45);flex-shrink:0}
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
		<div class="db-stripe-summary">
			<p class="db-stripe-domain"><?php echo esc_html( $domain ); ?></p>
			<p class="db-stripe-price"><?php echo esc_html( $price_display ); ?></p>
<?php if ( ! $is_plan ) : ?>
			<p class="db-stripe-onetime">One-time payment &middot; Instant transfer initiation</p>
<?php endif; ?>
		</div>

<?php if ( $is_plan ) : ?>
		<div class="db-stripe-plan-box">
			<strong><?php echo esc_html( $monthly_display ); ?>/mo</strong>
			&times; <?php echo esc_html( (string) $months ); ?> months
			<span class="db-stripe-badge"><?php echo esc_html( rtrim( rtrim( number_format( db_plan_rate( $months ), 1 ), '0' ), '.' ) ); ?>% interest</span>
			<br><small style="opacity:.65;margin-top:4px;display:block">First installment charged today &mdash; remaining <?php echo esc_html( (string) ( $months - 1 ) ); ?> installments billed monthly (<?php echo esc_html( rtrim( rtrim( number_format( db_plan_rate( $months ), 1 ), '0' ), '.' ) ); ?>% interest, total $<?php echo esc_html( number_format( db_plan_total_cents( $price, $months ) / 100, 2 ) ); ?>).</small>
		</div>
<?php endif; ?>

<?php if ( $is_plan ) : ?>
		<p class="db-stripe-terms" style="font-size:13px;line-height:1.5;background:#fff8e6;border:1px solid #f0d58a;border-radius:10px;padding:12px 14px;margin:0 0 16px;"><?php echo wp_kses( db_plan_terms_html(), [ 'strong' => [], 'em' => [] ] ); ?></p>
<?php endif; ?>

		<div id="db-stripe-err" class="db-stripe-err"></div>
		<form id="db-stripe-form" novalidate>
			<label class="db-stripe-label" for="db-stripe-email">Email for your receipt</label>
			<input type="email" id="db-stripe-email" class="db-stripe-input" placeholder="you@example.com" required autocomplete="email">
			<div class="db-stripe-pe-wrap">
				<div id="db-stripe-payment-element"></div>
			</div>
<?php if ( $is_plan ) : ?>
			<label class="db-stripe-agree" for="db-stripe-agree" style="display:flex;gap:10px;align-items:flex-start;font-size:13px;line-height:1.5;margin:0 0 14px;cursor:pointer;">
				<input type="checkbox" id="db-stripe-agree" required style="margin-top:3px;flex:0 0 auto;width:16px;height:16px;">
				<span>I agree to the payment plan terms above: the domain transfers to me only after the final installment, and if a payment stays unpaid <?php echo esc_html( (string) ( defined( 'DB_PLAN_GRACE_DAYS' ) ? DB_PLAN_GRACE_DAYS : 10 ) ); ?> days after its due date the plan ends and installments already paid are not refunded.</span>
			</label>
<?php endif; ?>
			<button type="submit" id="db-stripe-btn" class="db-stripe-btn"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6z"/></svg><?php echo esc_html( $btn_label ); ?></button>
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
window.DB_EMAIL_REST = <?php echo wp_json_encode( rest_url( 'db/v1/set-receipt-email' ) ); ?>;
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

		var form     = document.getElementById('db-stripe-form');
		var btn      = document.getElementById('db-stripe-btn');
		var errDiv   = document.getElementById('db-stripe-err');
		var emailInp = document.getElementById('db-stripe-email');
		var agreeInp = document.getElementById('db-stripe-agree');

		function showErr(msg) {
			errDiv.textContent = msg;
			errDiv.style.display = 'block';
			btn.disabled = false;
			btn.textContent = window.DB_BTN_LABEL;
		}

		form.addEventListener('submit', function (e) {
			e.preventDefault();

			var email = (emailInp.value || '').trim();
			if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
				showErr('Please enter a valid email address.');
				emailInp.focus();
				return;
			}
			if (agreeInp && !agreeInp.checked) {
				showErr('Please confirm you agree to the payment plan terms.');
				agreeInp.focus();
				return;
			}

			btn.disabled = true;
			btn.textContent = 'Processing…';
			errDiv.style.display = 'none';

			// Attach the buyer's email as the PaymentIntent's receipt_email
			// before confirming — this is what lets the sale-complete and
			// payment-failed emails reach the actual buyer.
			fetch(window.DB_EMAIL_REST, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({ client_secret: window.DB_PI_SECRET, email: email, terms_accepted: !!(agreeInp && agreeInp.checked) })
			}).then(function (res) {
				// Payment plans: the terms acceptance is recorded by this call,
				// so it must succeed before the card is charged.
				if (agreeInp && (!res || !res.ok)) { throw new Error('consent'); }
			}, function () {
				if (agreeInp) { throw new Error('consent'); }
				/* full purchase: non-fatal, still attempt payment */
			}).then(function () {
				return stripe.confirmPayment({
					elements: elements,
					confirmParams: { return_url: window.DB_RETURN_URL },
					redirect: 'if_required'
				});
			}).then(function (result) {
				if (result.error) {
					showErr(result.error.message || 'Payment failed. Please try again.');
				} else if (result.paymentIntent && result.paymentIntent.status === 'succeeded') {
					window.location.href = window.DB_RETURN_URL;
				} else {
					showErr('Payment could not be confirmed. Please contact support.');
				}
			}).catch(function (err) {
				showErr(err && err.message === 'consent'
					? 'We could not record your agreement to the plan terms, so your card was not charged. Please try again.'
					: 'An unexpected error occurred. Please refresh and try again.');
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

// ─── REST: register webhook + receipt-email routes ────────────────────────────

add_action( 'rest_api_init', static function () {
	register_rest_route( 'db/v1', '/stripe-webhook', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'db_stripe_handle_webhook',
		'permission_callback' => '__return_true',
	] );

	register_rest_route( 'db/v1', '/set-receipt-email', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'db_stripe_set_receipt_email',
		'permission_callback' => '__return_true',
		'args'                => [
			'client_secret' => [ 'required' => true, 'type' => 'string' ],
			'email'         => [ 'required' => true, 'type' => 'string' ],
			'terms_accepted' => [ 'required' => false, 'type' => 'boolean' ],
		],
	] );
} );

if ( ! function_exists( 'db_stripe_receipt_body' ) ) {
	/**
	 * PaymentIntent update body. When a payment-plan buyer ticks the terms
	 * box, the acceptance time and a fingerprint of the exact terms text are
	 * stored on the PaymentIntent's metadata, so there is a record of what
	 * was agreed if a forfeiture is ever disputed.
	 */
	function db_stripe_receipt_body( $email, $terms_accepted ) {
		$body = [ 'receipt_email' => $email ];
		if ( $terms_accepted && function_exists( 'db_plan_terms_html' ) ) {
			$body['metadata[terms_accepted_at]'] = gmdate( 'c' );
			$body['metadata[terms_sha]']         = substr( hash( 'sha256', wp_strip_all_tags( db_plan_terms_html() ) ), 0, 16 );
		}
		return $body;
	}
}

if ( ! function_exists( 'db_stripe_set_receipt_email' ) ) {
	/**
	 * Attaches the buyer's email to their own PaymentIntent as receipt_email,
	 * called from the checkout page right before confirmPayment(). Without
	 * this, sale-complete/payment-failed emails have no address to send to.
	 *
	 * Requires the full client_secret (not just the PI id) as proof of
	 * ownership — a bare PaymentIntent id is not a secret, but the
	 * client_secret only ever reaches the one browser that's paying it.
	 */
	function db_stripe_set_receipt_email( WP_REST_Request $request ) {
		$client_secret = sanitize_text_field( (string) $request->get_param( 'client_secret' ) );
		$email         = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( ! preg_match( '/^(pi_[a-zA-Z0-9]+)_secret_[a-zA-Z0-9]+$/', $client_secret, $m ) || ! is_email( $email ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid parameters.' ], 400 );
		}
		$pi_id = $m[1];

		$keys = db_stripe_keys();
		if ( empty( $keys['sec'] ) ) {
			return new WP_REST_Response( [ 'error' => 'Payment system not configured.' ], 500 );
		}

		// The regex above only extracts the PaymentIntent id; anyone who knew
		// an id could pass "pi_X_secret_anything". Check the full secret
		// against Stripe's, and only while the payment is still open — after
		// payment, changing receipt_email would redirect the buyer's plan
		// reminders and card-update links to someone else.
		$current = wp_remote_get( 'https://api.stripe.com/v1/payment_intents/' . rawurlencode( $pi_id ), [
			'headers' => [ 'Authorization' => 'Bearer ' . $keys['sec'] ],
			'timeout' => 15,
		] );
		$pi = is_wp_error( $current ) ? [] : (array) json_decode( (string) wp_remote_retrieve_body( $current ), true );
		if ( empty( $pi['client_secret'] ) || ! hash_equals( (string) $pi['client_secret'], $client_secret ) || empty( $pi['metadata']['domain'] ) ) {
			return new WP_REST_Response( [ 'error' => 'Invalid parameters.' ], 400 );
		}
		if ( ! in_array( (string) ( $pi['status'] ?? '' ), [ 'requires_payment_method', 'requires_confirmation', 'requires_action' ], true ) ) {
			return new WP_REST_Response( [ 'error' => 'This payment can no longer be changed.' ], 409 );
		}

		$response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents/' . rawurlencode( $pi_id ), [
			'headers'   => [
				'Authorization' => 'Bearer ' . $keys['sec'],
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'body'      => db_stripe_receipt_body( $email, (bool) $request->get_param( 'terms_accepted' ) ),
			'timeout'   => 15,
			'sslverify' => true,
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_REST_Response( [ 'error' => 'Could not update receipt email.' ], 502 );
		}

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}
}

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

		// Only the active mode's secret is accepted — otherwise a test-mode
		// event (e.g. a 4242 test card) verifies fine while the site is in
		// live mode and gets processed as a real sale.
		$secret = db_stripe_keys()['webhook_secret'];
		$event  = $secret ? db_stripe_verify_webhook_sig( $payload, $sig_header, $secret ) : null;

		if ( null === $event ) {
			error_log( 'DB Stripe: Webhook signature verification failed.' );
			return db_stripe_wh_respond( $request, [ 'error' => 'Invalid signature.' ], 400 );
		}

		$event_id   = (string) ( $event['id'] ?? '' );
		$event_type = (string) ( $event['type'] ?? '' );
		$obj        = (array)  ( $event['data']['object'] ?? [] );
		$domain     = (string) ( $obj['metadata']['domain'] ?? '' );

		// Idempotency: Stripe retries deliveries. add_option() is an atomic
		// claim (two simultaneous deliveries can't both pass), and the claim
		// is released if processing fails so Stripe's retry is processed
		// instead of being acknowledged as a duplicate. The plan/sale records
		// also de-duplicate by PaymentIntent id on their own.
		$claim = $event_id ? 'db_stripe_evt_' . $event_id : '';
		if ( $claim && ! add_option( $claim, time(), '', false ) ) {
			return db_stripe_wh_respond( $request, [ 'received' => true, 'duplicate' => true ], 200 );
		}
		$meta_type = (string) ( $obj['metadata']['type'] ?? '' );
		$ours      = '' !== $domain && in_array( $meta_type, [ 'full', 'plan' ], true );

		$log = [
			'ts'         => time(),
			'event_type' => $event_type,
			'domain'     => $domain,
			'status'     => 'received',
		];

		try {
			switch ( $event_type ) {
				case 'payment_intent.succeeded':
					db_stripe_handle_pi_succeeded( $obj );
					$log['status'] = $ours ? 'processed' : 'ignored (not a checkout payment)';
					break;

				case 'payment_intent.payment_failed':
					if ( ! $ours ) {
						$log['status'] = 'ignored (not a checkout payment)';
						break;
					}
					$email      = (string) ( $obj['receipt_email'] ?? '' );
					$amount_usd = isset( $obj['amount'] ) ? round( (float) $obj['amount'] / 100, 2 ) : 0.0;
					$inst       = (int) ( $obj['metadata']['installment_number'] ?? 0 );
					if ( 'plan' === $meta_type && $inst > 1 && function_exists( 'db_plan_on_installment_failed' ) ) {
						// A later installment declined: start/continue the grace-period flow.
						db_plan_on_installment_failed( db_stripe_norm( $domain ), $inst );
					} else {
						db_stripe_on_payment_failed( db_stripe_norm( $domain ), $email, $amount_usd );
					}
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
		} catch ( \Throwable $e ) {
			if ( $claim ) {
				delete_option( $claim );
			}
			error_log( 'DB Stripe: webhook ' . $event_type . ' failed — ' . $e->getMessage() );
			return db_stripe_wh_respond( $request, [ 'error' => 'Processing failed; will retry.' ], 500 );
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
		$v1_sigs   = [];

		foreach ( explode( ',', $sig_header ) as $chunk ) {
			$parts = explode( '=', $chunk, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			if ( $parts[0] === 't' ) {
				$timestamp = $parts[1];
			} elseif ( $parts[0] === 'v1' ) {
				// Several v1 values are sent while a signing secret is being rolled.
				$v1_sigs[] = $parts[1];
			}
		}

		if ( ! $timestamp || ! $v1_sigs ) {
			return null;
		}

		// Reject events older than 5 minutes to prevent replay attacks.
		if ( abs( time() - (int) $timestamp ) > 300 ) {
			error_log( 'DB Stripe: Webhook timestamp is stale.' );
			return null;
		}

		$computed = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );

		$valid = false;
		foreach ( $v1_sigs as $v1_sig ) {
			$valid = $valid || hash_equals( $computed, $v1_sig );
		}
		if ( ! $valid ) {
			return null;
		}

		$event = json_decode( $payload, true );
		return is_array( $event ) ? $event : null;
	}
}

// ─── payment_intent.succeeded ─────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_flag_conflict' ) ) {
	/**
	 * A payment arrived that the plan/sale state says should not exist (a
	 * second buyer for a reserved or sold domain, a late installment on an
	 * ended plan, an out-of-order installment). It is NOT applied — applying
	 * it reopened finished plans and led to repeat charges — and the admin is
	 * told to review it in Stripe and refund if needed.
	 */
	function db_stripe_flag_conflict( string $domain, array $pi, string $why ) : void {
		$lines = [
			$why,
			'',
			'Domain    : ' . $domain,
			'Payment   : ' . (string) ( $pi['id'] ?? '?' ) . ' (' . '$' . number_format( (float) ( $pi['amount_received'] ?? $pi['amount'] ?? 0 ) / 100, 2 ) . ')',
			'Customer  : ' . (string) ( $pi['receipt_email'] ?? '' ) . ' ' . (string) ( $pi['customer'] ?? '' ),
			'Mode      : ' . db_stripe_mode(),
			'',
			'This payment was NOT recorded against the plan/sale. Check it in the Stripe Dashboard and refund it if it should not have been taken.',
		];
		error_log( 'DB Stripe: payment needs review — ' . $domain . ' — ' . $why );
		if ( function_exists( 'db_plan_notify_admin' ) ) {
			db_plan_notify_admin( 'PAYMENT NEEDS REVIEW (possible refund): ' . $domain, $lines );
		} else {
			wp_mail( (string) get_option( 'admin_email' ), 'PAYMENT NEEDS REVIEW (possible refund): ' . $domain, implode( "\n", $lines ) );
		}
	}
}

if ( ! function_exists( 'db_stripe_handle_pi_succeeded' ) ) {
	/**
	 * Applies a successful PaymentIntent (from the webhook, or straight from
	 * the off-session charge response) to the sale or plan record. Safe to
	 * call more than once for the same PaymentIntent.
	 */
	function db_stripe_handle_pi_succeeded( array $pi ) : void {
		$meta   = (array) ( $pi['metadata'] ?? [] );
		$domain = db_stripe_norm( (string) ( $meta['domain'] ?? '' ) );
		$type   = (string) ( $meta['type'] ?? '' );

		// Only PaymentIntents this checkout created. A Payment Link or invoice
		// paid on the same Stripe account has no domain/type metadata and used
		// to be treated as a full sale of "" (receipt, admin alert, sold flag).
		if ( '' === $domain || ! in_array( $type, [ 'full', 'plan' ], true ) ) {
			error_log( 'DB Stripe: ignored payment_intent.succeeded without domain/type metadata (' . (string) ( $pi['id'] ?? '?' ) . ').' );
			return;
		}

		$total_months    = isset( $meta['total_months'] ) ? (int) $meta['total_months'] : 0;
		$installment_num = isset( $meta['installment_number'] ) ? (int) $meta['installment_number'] : 1;
		$amount_usd      = isset( $pi['amount_received'] ) ? round( (float) $pi['amount_received'] / 100, 2 ) : 0.0;
		$pi_id           = (string) ( $pi['id'] ?? '' );
		$customer        = (string) ( $pi['customer'] ?? '' );

		// receipt_email is set by db_stripe_set_receipt_email() before the
		// checkout form confirms payment — PaymentIntent responses no longer
		// include a 'charges' array in current Stripe API versions, so that
		// used to silently resolve to an empty email on every sale.
		$customer_email = (string) ( $pi['receipt_email'] ?? '' );

		if ( 'full' === $type ) {
			$sold = get_option( db_stripe_store_key( 'sold', $domain ) );
			if ( $sold ) {
				if ( is_array( $sold ) && '' !== $pi_id && ( $sold['pi'] ?? '' ) === $pi_id ) {
					return; // Same payment delivered again.
				}
				db_stripe_flag_conflict( $domain, $pi, 'A full purchase was paid for a domain that is already sold.' );
				return;
			}
			$plan = (array) get_option( db_stripe_store_key( 'plan', $domain ), [] );
			if ( in_array( $plan['status'] ?? '', [ 'active', 'past_due' ], true ) ) {
				db_stripe_flag_conflict( $domain, $pi, 'A full purchase was paid for a domain reserved on another buyer\'s payment plan.' );
				return;
			}
			db_stripe_on_sale_complete( $domain, $amount_usd, $customer_email, 'full', $pi_id );
			return;
		}

		$opt_key = db_stripe_store_key( 'plan', $domain );
		$plan    = (array) get_option( $opt_key, [] );
		$status  = (string) ( $plan['status'] ?? '' );

		// Durable duplicate check, stored with the plan. (A transient used to
		// do this: it was set before processing, could expire or be evicted,
		// and a resend after that was applied a second time.)
		if ( '' !== $pi_id && in_array( $pi_id, (array) ( $plan['pi_ids'] ?? [] ), true ) ) {
			return;
		}

		if ( 1 === $installment_num ) {
			if ( in_array( $status, [ 'active', 'past_due' ], true ) ) {
				db_stripe_flag_conflict( $domain, $pi, 'A second buyer paid installment 1 for a domain already on an active payment plan.' );
				return;
			}
			if ( get_option( db_stripe_store_key( 'sold', $domain ) ) ) {
				db_stripe_flag_conflict( $domain, $pi, 'Installment 1 of a new plan was paid for a domain that is already sold.' );
				return;
			}
			// A previous buyer's plan on this domain ended (defaulted): archive
			// it so the new buyer's "paid so far" starts at zero.
			if ( ! empty( $plan ) ) {
				update_option( $opt_key . '_archive_' . time(), $plan, false );
				$plan = [];
			}
		} else {
			$expected = (int) ( $plan['next_installment'] ?? 0 );
			if ( ! in_array( $status, [ 'active', 'past_due' ], true ) || $customer !== (string) ( $plan['customer_id'] ?? '' ) || $installment_num !== $expected ) {
				db_stripe_flag_conflict( $domain, $pi, sprintf(
					'Installment %d arrived, but the plan is "%s" and expects installment %d from customer %s. A late or resent payment on a finished, terminated or different plan is not applied.',
					$installment_num, $status ?: 'missing', $expected, (string) ( $plan['customer_id'] ?? '?' )
				) );
				return;
			}
		}

		error_log( sprintf( 'DB Stripe: Plan installment %d/%d paid — %s ($%.2f)', $installment_num, $total_months, $domain, $amount_usd ) );

		$was_past_due = 'past_due' === $status;
		$total_price  = isset( $meta['total_price_usd'] ) ? (float) $meta['total_price_usd'] : $amount_usd;

		$plan['domain']            = $domain;
		$plan['mode']              = db_stripe_mode();
		$plan['price_usd']         = $total_price;
		$plan['total_months']      = $total_months;
		$plan['customer_email']    = $customer_email ?: ( $plan['customer_email'] ?? '' );
		$plan['customer_id']       = $customer ?: (string) ( $plan['customer_id'] ?? '' );
		$plan['payment_method']    = (string) ( $pi['payment_method'] ?? ( $plan['payment_method'] ?? '' ) );
		$plan['paid_installments'] = $installment_num;
		$plan['reminded_for']      = (int) ( $plan['reminded_for'] ?? 0 );
		if ( ! empty( $meta['terms_accepted_at'] ) && empty( $plan['terms_accepted_at'] ) ) {
			$plan['terms_accepted_at'] = sanitize_text_field( (string) $meta['terms_accepted_at'] );
			$plan['terms_sha']         = sanitize_text_field( (string) ( $meta['terms_sha'] ?? '' ) );
		}
		$plan['history']   = (array) ( $plan['history'] ?? [] );
		$plan['history'][] = [ 'installment' => $installment_num, 'amount_usd' => $amount_usd, 'ts' => time(), 'pi' => $pi_id ];
		if ( '' !== $pi_id ) {
			$plan['pi_ids']   = array_slice( array_merge( (array) ( $plan['pi_ids'] ?? [] ), [ $pi_id ] ), -24 );
		}
		$plan['past_due_since'] = 0;
		$plan['notice_stage']   = 0;

		if ( $installment_num >= $total_months ) {
			$plan['status'] = 'completed';
			update_option( $opt_key, $plan, false );
			db_stripe_plan_index_remove( $domain );
			// "Amount paid" is what the customer actually paid across the whole
			// plan (listing price + the term's interest), not the listing price.
			$paid_total = 0.0;
			foreach ( (array) $plan['history'] as $h ) {
				$paid_total += (float) ( $h['amount_usd'] ?? 0 );
			}
			db_stripe_on_sale_complete( $domain, $paid_total > 0 ? $paid_total : $total_price, $customer_email, 'plan', $pi_id );
			return;
		}

		$plan['status']           = 'active';
		$plan['next_installment'] = $installment_num + 1;
		$plan['next_charge_ts']   = strtotime( '+1 month' ) ?: ( time() + 30 * DAY_IN_SECONDS );
		update_option( $opt_key, $plan, false );
		db_stripe_plan_index_add( $domain );
		if ( $was_past_due && function_exists( 'db_plan_on_recovered' ) ) {
			db_plan_on_recovered( $domain, $amount_usd );
		} else {
			db_stripe_on_plan_payment( $domain, $total_price, $total_months, $installment_num, $amount_usd, $customer_email );
		}
	}
}

// ─── Payment-plan index (so cron doesn't have to scan every wp_option) ────────
// Holds normalized domain names, one list per mode.

if ( ! function_exists( 'db_stripe_plan_index_option' ) ) {
	function db_stripe_plan_index_option() : string {
		return 'test' === db_stripe_mode() ? 'db_stripe_test_active_plans' : 'db_stripe_active_plans';
	}
}

if ( ! function_exists( 'db_stripe_plan_index_add' ) ) {
	function db_stripe_plan_index_add( string $domain ) : void {
		$domain = db_stripe_norm( $domain );
		$index  = (array) get_option( db_stripe_plan_index_option(), [] );
		if ( ! in_array( $domain, $index, true ) ) {
			$index[] = $domain;
			update_option( db_stripe_plan_index_option(), $index, false );
		}
	}
}

if ( ! function_exists( 'db_stripe_plan_index_remove' ) ) {
	function db_stripe_plan_index_remove( string $domain ) : void {
		$index = array_values( array_diff( (array) get_option( db_stripe_plan_index_option(), [] ), [ db_stripe_norm( $domain ) ] ) );
		update_option( db_stripe_plan_index_option(), $index, false );
	}
}

// ─── Cron: charge due plan installments (2..N) ────────────────────────────────

// The daily handler lives in db-plan-lifecycle-block.php (db_plan_run_daily):
// reminders, charges, failed-payment retries/notices and termination.

if ( ! function_exists( 'db_stripe_ensure_installment_cron' ) ) {
	function db_stripe_ensure_installment_cron() : void {
		if ( ! wp_next_scheduled( 'db_stripe_charge_due_installments' ) ) {
			wp_schedule_event( time(), 'daily', 'db_stripe_charge_due_installments' );
		}
	}
}
add_action( 'init', 'db_stripe_ensure_installment_cron' );

if ( ! function_exists( 'db_stripe_charge_next_installment' ) ) {
	/**
	 * Charges the next off-session installment with the Customer + card saved
	 * at plan start, and applies the result straight away when Stripe returns
	 * it (success or decline), so a lost webhook can't leave a paid plan
	 * looking unpaid. The webhook that follows is then a no-op.
	 *
	 * Returns: succeeded | declined | processing | error | locked | skipped.
	 */
	function db_stripe_charge_next_installment( array $plan ) : string {
		$keys    = db_stripe_keys();
		$domain  = db_stripe_norm( (string) ( $plan['domain'] ?? '' ) );
		$opt_key = db_stripe_store_key( 'plan', $domain );
		if ( '' === $domain || empty( $keys['sec'] ) ) {
			return 'skipped';
		}

		// One charge at a time per plan. Overlapping cron runs (WP-Cron's lock
		// is not atomic) used to both see the old last_attempt_ts and both
		// create a PaymentIntent. add_option() is an atomic claim.
		$lock = $opt_key . '_lock';
		if ( ! add_option( $lock, time(), '', false ) ) {
			if ( (int) get_option( $lock ) > time() - 5 * MINUTE_IN_SECONDS ) {
				return 'locked';
			}
			update_option( $lock, time(), false ); // Stale lock from a crashed run.
		}

		try {
			$fresh = (array) get_option( $opt_key, [] );
			if ( empty( $fresh ) || ! in_array( $fresh['status'] ?? '', [ 'active', 'past_due' ], true ) ) {
				return 'skipped';
			}
			if ( ( $fresh['mode'] ?? db_stripe_mode() ) !== db_stripe_mode() ) {
				return 'skipped'; // A test-mode plan while the site is live, or the reverse.
			}
			if ( time() - (int) ( $fresh['last_attempt_ts'] ?? 0 ) < 20 * HOUR_IN_SECONDS ) {
				return 'skipped';
			}
			$plan   = $fresh;
			$n      = (int) $plan['next_installment'];
			$months = (int) $plan['total_months'];
			$price  = (float) $plan['price_usd'];

			// Record the attempt before calling Stripe, not after it returns.
			$plan['last_attempt_ts'] = time();
			update_option( $opt_key, $plan, false );

			$response = wp_remote_post( 'https://api.stripe.com/v1/payment_intents', [
				'headers'   => [
					'Authorization'   => 'Bearer ' . $keys['sec'],
					'Content-Type'    => 'application/x-www-form-urlencoded',
					// Stripe returns the same PaymentIntent for a repeated key, so a
					// retried request on the same day can never charge twice.
					'Idempotency-Key' => 'db-plan-' . md5( $domain . '|' . ( $plan['customer_id'] ?? '' ) ) . '-' . $n . '-' . gmdate( 'Ymd' ),
				],
				'body'      => [
					'amount'                       => db_stripe_installment_cents( $price, $months, $n ),
					'currency'                     => 'usd',
					'customer'                     => $plan['customer_id'],
					'payment_method'               => db_stripe_current_payment_method( $plan ),
					'off_session'                  => 'true',
					'confirm'                      => 'true',
					'receipt_email'                => $plan['customer_email'] ?? '',
					'metadata[domain]'             => $domain,
					'metadata[type]'               => 'plan',
					'metadata[total_price_usd]'    => (string) $price,
					'metadata[total_months]'       => (string) $months,
					'metadata[installment_number]' => (string) $n,
				],
				'timeout'   => 30,
				'sslverify' => true,
			] );

			$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$data = is_wp_error( $response ) ? [] : (array) json_decode( (string) wp_remote_retrieve_body( $response ), true );
			// A decline comes back as a 402 error that still carries the PaymentIntent.
			$pi     = 0 === strpos( (string) ( $data['id'] ?? '' ), 'pi_' ) ? $data : (array) ( $data['error']['payment_intent'] ?? [] );
			$status = (string) ( $pi['status'] ?? '' );

			if ( 'succeeded' === $status ) {
				db_stripe_handle_pi_succeeded( $pi );
				return 'succeeded';
			}
			if ( in_array( $status, [ 'requires_payment_method', 'requires_action', 'canceled' ], true ) ) {
				// Declined, or the bank wants authentication the buyer isn't
				// present to give: both are a missed payment.
				if ( function_exists( 'db_plan_on_installment_failed' ) ) {
					db_plan_on_installment_failed( $domain, $n );
				}
				return 'declined';
			}
			if ( $pi ) {
				return 'processing'; // e.g. "processing": the webhook settles it; last_attempt_ts de-bounces.
			}

			// No PaymentIntent at all: network failure, bad key, deleted
			// customer, mode switch. Nothing was charged. This used to push the
			// due date a month out silently; now it is retried tomorrow (the
			// 20-hour de-bounce above) and the admin is told.
			$msg = is_wp_error( $response ) ? $response->get_error_message() : (string) ( $data['error']['message'] ?? 'HTTP ' . $code );
			error_log( 'DB Stripe: installment ' . $n . ' charge for ' . $domain . ' failed before reaching the card — ' . $msg );
			if ( function_exists( 'db_plan_notify_admin' ) ) {
				db_plan_notify_admin( 'PLAN CHARGE ERROR: ' . $domain, [
					'Installment ' . $n . ' of ' . $months . ' could not be submitted to Stripe (nothing was charged).',
					'Stripe said: ' . $msg,
					'It will be retried automatically tomorrow. If this repeats, check the Stripe keys/mode and the customer ' . (string) ( $plan['customer_id'] ?? '' ) . '.',
				] );
			}
			return 'error';
		} finally {
			delete_option( $lock );
		}
	}
}

if ( ! function_exists( 'db_stripe_current_payment_method' ) ) {
	/**
	 * The card to bill: the customer's default if they updated it through the
	 * "Update my card" portal link (that's how a failed plan gets fixed),
	 * otherwise the one saved when the plan started.
	 */
	function db_stripe_current_payment_method( array $plan ) : string {
		$saved = (string) ( $plan['payment_method'] ?? '' );
		$keys  = db_stripe_keys();
		if ( empty( $plan['customer_id'] ) || empty( $keys['sec'] ) ) {
			return $saved;
		}
		$r = wp_remote_get( 'https://api.stripe.com/v1/customers/' . rawurlencode( (string) $plan['customer_id'] ), [
			'headers' => [ 'Authorization' => 'Bearer ' . $keys['sec'] ],
			'timeout' => 15,
		] );
		$c       = is_wp_error( $r ) ? [] : json_decode( wp_remote_retrieve_body( $r ), true );
		$default = (string) ( $c['invoice_settings']['default_payment_method'] ?? '' );
		return $default ?: $saved;
	}
}

// ─── Email: sale complete ──────────────────────────────────────────────────────

if ( ! function_exists( 'db_stripe_domain_unavailable' ) ) {
	/** True once a domain is sold, or while a payment plan on it is active or past due. */
	function db_stripe_domain_unavailable( string $domain ) : bool {
		if ( get_option( db_stripe_store_key( 'sold', $domain ) ) ) {
			return true;
		}
		$plan = (array) get_option( db_stripe_store_key( 'plan', $domain ), [] );
		return in_array( $plan['status'] ?? '', [ 'active', 'past_due', 'completed' ], true );
	}
}

if ( ! function_exists( 'db_stripe_on_sale_complete' ) ) {
	function db_stripe_on_sale_complete( string $domain, float $amount_usd, string $customer_email, string $type, string $pi_id = '' ) : void {
		update_option( db_stripe_store_key( 'sold', $domain ), [ 'ts' => time(), 'pi' => $pi_id ], false );
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
				// db_send_branded_email() sends Content-Type: text/html, but
				// $body above is plain text joined with "\n" — a literal
				// newline is not a line break in HTML, so every customer
				// receipt rendered as one run-on paragraph. nl2br() only
				// here, not inside db_send_branded_email() itself, since
				// other callers already pass real HTML through that
				// function and a blanket nl2br() there would double up on
				// their intentional <p>/<br> tags. The plain-text wp_mail()
				// fallback below is correct as-is.
				db_send_branded_email( $customer_email, $subj, nl2br( esc_html( $body ) ) );
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
			db_send_branded_email( $admin_email, $admin_subj, nl2br( esc_html( $admin_body ) ) );
		} else {
			wp_mail( $admin_email, $admin_subj, $admin_body );
		}
	}
}

// ─── Email: plan started / installment received ───────────────────────────────

if ( ! function_exists( 'db_stripe_on_plan_payment' ) ) {
	/**
	 * Installments 1..N-1. Until now a customer who started a plan got no
	 * confirmation from the site at all (only the final installment sent
	 * anything), and the team was never told a plan had started. Installment
	 * 1 sends the full schedule; later ones send a short receipt.
	 */
	function db_stripe_on_plan_payment( string $domain, float $price, int $months, int $n, float $amount_usd, string $customer_email ) : void {
		$admin_email = (string) get_option( 'admin_email' );
		$rate        = db_plan_rate( $months );
		$rate_label  = rtrim( rtrim( number_format( $rate, 1 ), '0' ), '.' ) . '% interest';
		$total       = db_plan_total_cents( $price, $months ) / 100;
		$d           = esc_html( $domain );
		$paid_fmt    = '$' . number_format( $amount_usd, 2 );

		if ( $customer_email ) {
			if ( 1 === $n ) {
				$subj = sprintf( 'Your payment plan for %s has started', $domain );
				$rows = '';
				for ( $i = 1; $i <= $months; $i++ ) {
					$when  = 1 === $i ? 'Today (paid)' : wp_date( 'M j, Y', strtotime( '+' . ( $i - 1 ) . ' months' ) );
					$rows .= '<tr><td style="padding:8px 14px;border-bottom:1px solid #f3f4f6;">' . (int) $i . '</td><td style="padding:8px 14px;border-bottom:1px solid #f3f4f6;">' . esc_html( $when ) . '</td><td style="padding:8px 14px;border-bottom:1px solid #f3f4f6;text-align:right;">$' . esc_html( number_format( db_stripe_installment_cents( $price, $months, $i ) / 100, 2 ) ) . '</td></tr>';
				}
				$body  = '<p style="font-size:20px;font-weight:700;color:#111827;margin:0 0 12px;">Your payment plan is active</p>';
				$body .= '<p>We received your first payment of <strong>' . esc_html( $paid_fmt ) . '</strong> for <strong>' . $d . '</strong>. Your plan: <strong>' . (int) $months . ' monthly payments</strong>, ' . esc_html( $rate_label ) . ', total <strong>$' . esc_html( number_format( $total, 2 ) ) . '</strong>.</p>';
				$body .= '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:16px 0;font-size:14px;"><tr><th align="left" style="padding:8px 14px;background:#f8f9fb;">#</th><th align="left" style="padding:8px 14px;background:#f8f9fb;">Date</th><th align="right" style="padding:8px 14px;background:#f8f9fb;">Amount</th></tr>' . $rows . '</table>';
				$body .= '<p>Remaining payments are charged automatically to the card you used. You can use the domain during the plan &mdash; reply to this email and we will point its DNS to your hosting. Ownership transfers to you once the final payment clears; until then the domain is held securely in escrow.</p>';
				if ( function_exists( 'db_plan_terms_html' ) ) {
					$body .= '<p style="font-size:13px;color:#374151;background:#f8f9fb;padding:12px 14px;border-radius:8px;">' . db_plan_terms_html() . '</p>';
					$body .= '<p style="font-size:13px;color:#6b7280;">We will email you a reminder ' . (int) DB_PLAN_REMIND_DAYS . ' days before each payment.</p>';
				}
				$body .= '<p style="font-size:13px;color:#6b7280;">Questions? Reply to this email or write to <a href="mailto:sales@domainbrothers.com" style="color:#0a6ed1;">sales@domainbrothers.com</a>.</p>';
			} else {
				$subj = sprintf( 'Payment %d of %d received for %s', $n, $months, $domain );
				$body  = '<p style="font-size:20px;font-weight:700;color:#111827;margin:0 0 12px;">Payment received</p>';
				$body .= '<p>We received installment <strong>' . (int) $n . ' of ' . (int) $months . '</strong> (' . esc_html( $paid_fmt ) . ') for <strong>' . $d . '</strong>. ' . (int) ( $months - $n ) . ' payment(s) remain.</p>';
			}
			if ( function_exists( 'db_send_branded_email' ) ) {
				db_send_branded_email( $customer_email, $subj, $body );
			}
		}

		$admin_subj = 1 === $n
			? sprintf( 'PLAN STARTED: %s — %d months, %s', $domain, $months, $rate_label )
			: sprintf( 'PLAN PAYMENT %d/%d: %s (%s)', $n, $months, $domain, $paid_fmt );
		$admin_body = implode( "\n", [
			'Domain   : ' . $domain,
			'Payment  : ' . $n . ' of ' . $months . ' (' . $paid_fmt . ')',
			'Plan     : ' . $rate_label . ', total $' . number_format( $total, 2 ),
			'Customer : ' . ( $customer_email ?: 'Unknown' ),
			'Time     : ' . wp_date( 'Y-m-d H:i:s T' ),
		] );
		if ( function_exists( 'db_send_branded_email' ) ) {
			db_send_branded_email( $admin_email, $admin_subj, nl2br( esc_html( $admin_body ) ) );
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
				db_send_branded_email( $customer_email, $subj, nl2br( esc_html( $body ) ) );
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
			db_send_branded_email( $admin_email, $admin_subj, nl2br( esc_html( $admin_body ) ) );
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
