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
	$offer = isset( $_GET['offer'] ) ? sanitize_text_field( wp_unslash( $_GET['offer'] ) ) : '';
	// phpcs:enable

	// An accepted custom offer: the domain and the price come from the stored
	// deal (never from the URL), so the link cannot be edited to pay less.
	$deal_ctx = null;
	if ( '' !== $offer && function_exists( 'db_deal_checkout_context' ) ) {
		$deal_ctx = db_deal_checkout_context( $offer );
		if ( ! $deal_ctx ) {
			wp_die(
				'<h1>This offer link cannot be used</h1><p>It may have expired, already been paid, or the domain is no longer available. <a href="' . esc_url( home_url( '/' ) ) . '">Go to the home page</a> or <a href="mailto:sales@domainbrothers.com">contact us</a>.</p>',
				'Offer unavailable',
				[ 'response' => 410 ]
			);
		}
		$GLOBALS['db_deal_checkout'] = $deal_ctx['deal'];
	}

	if ( ! $deal_ctx && ( ! $d_raw || ! $p_raw ) ) {
		// Never fall through to the theme here. DomainFolio's buy-now template
		// fatals when it renders without a selected domain, so a bare
		// /buy-now/ returned HTTP 500 to every direct visitor and to
		// Googlebot. There is nothing meaningful to show without a domain
		// anyway, so send the visitor to the marketplace.
		wp_safe_redirect( home_url( '/all-domains/' ), 302 );
		exit;
	}

	if ( $deal_ctx ) {
		$domain = db_stripe_norm( $deal_ctx['domain'] );
		$price  = (float) $deal_ctx['price'];
	} else {
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

		// A sold, reserved or not-for-sale name is "no longer available" (410)
		// whether or not it still carries a price; check before the price lookup,
		// which would otherwise fail such a listing with a generic 500.
		if ( db_stripe_domain_unavailable( db_stripe_norm( $domain ) ) ) {
			wp_die(
				'<h1>This domain is no longer available</h1><p>' . esc_html( $domain ) . ' has been sold, is reserved on a payment plan, or is not for sale. <a href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse other premium domains</a> or <a href="mailto:sales@domainbrothers.com">contact us</a>.</p>',
				'Domain unavailable',
				[ 'response' => 410 ]
			);
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
	}

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

		// Extra metadata from other blocks (an accepted custom offer adds its lead and deal ids).
		foreach ( (array) apply_filters( 'db_stripe_pi_metadata', [], $domain ) as $mk => $mv ) {
			$body[ 'metadata[' . sanitize_key( (string) $mk ) . ']' ] = (string) $mv;
		}

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

if ( ! function_exists( 'db_stripe_listing_url' ) ) {
	/**
	 * Permalink of the domain listing a checkout is for (the order summary's
	 * remove link, as on the live site). Falls back to the marketplace.
	 */
	function db_stripe_listing_url( string $domain ) : string {
		if ( function_exists( 'get_posts' ) ) {
			$posts = get_posts( [
				'post_type'      => 'domain',
				'post_status'    => 'publish',
				'title'          => $domain,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			] );
			if ( ! empty( $posts ) && 0 === strcasecmp( $posts[0]->post_title, $domain ) ) {
				return (string) get_permalink( $posts[0] );
			}
		}
		return home_url( '/all-domains/' );
	}
}

if ( ! function_exists( 'db_stripe_money' ) ) {
	/** Live-site money format: "$2500.00" (two decimals, no thousands separator). */
	function db_stripe_money( float $usd ) : string {
		return '$' . number_format( $usd, 2, '.', '' );
	}
}

if ( ! function_exists( 'db_stripe_render_checkout_page' ) ) {
	/**
	 * Checkout page. Layout and styling follow the live site's three-step
	 * checkout (Billing Information → Order Confirmation → Payment) with the
	 * "Pay in full / Pay less up front" selector, order summary panel, "next
	 * steps" column and payment-page footer. Payment itself is still the
	 * Stripe Payment Element on this page; the three steps run client-side so
	 * no new endpoints are needed.
	 */
	function db_stripe_render_checkout_page( string $domain, float $price, int $months, array $pi ) : void {
		$keys           = db_stripe_keys();
		$pub_key        = $keys['pub'];
		$is_plan        = $months > 0;
		$has_error      = isset( $pi['error'] );
		$client_secret  = $pi['client_secret'] ?? '';
		$not_configured = empty( $pub_key ) || empty( $keys['sec'] );

		$monthly_cents   = $is_plan ? db_stripe_installment_cents( $price, $months, 1 ) : 0;
		$btn_label       = $is_plan ? 'Start Payment Plan' : 'Pay Now';
		// 'd', not 'domain' — the domain CPT registers 'domain' as its own
		// public query var, so ?domain=... hijacks the main query away from
		// the thank-you page (renders the listing or a 404 instead)
		// The signature is 'sig', not 's': 's' is WordPress's search query
		// var, and ?s=... on a page URL turns it into a search for that page
		// (a 404 here, after every successful payment).
		$ty_type         = $is_plan ? 'plan' : 'full';
		$ty_amount       = number_format( $price, 0, '.', ',' );
		$return_url      = home_url(
			'/thank-you/?type=' . $ty_type
			. '&d=' . rawurlencode( $domain )
			. '&amt=' . rawurlencode( $ty_amount )
			// Signed, so the confirmation page can't be faked by editing the URL.
			. ( function_exists( 'db_ty_sig' ) ? '&sig=' . db_ty_sig( $ty_type, $domain, $ty_amount ) : '' )
		);

		// "Pay in full / Pay less up front" selector. The plan figure is the
		// 6-month plan (as on the live site) unless a term is already chosen.
		$plan_months      = $is_plan ? $months : 6;
		$plan_first_usd   = db_stripe_installment_cents( $price, $plan_months, 1 ) / 100;
		$plan_rate        = function_exists( 'db_plan_rate' ) ? (float) db_plan_rate( $plan_months ) : 0.0;
		$plan_total_usd   = function_exists( 'db_plan_total_cents' ) ? db_plan_total_cents( $price, $plan_months ) / 100 : $price;
		$rate_label       = rtrim( rtrim( number_format( $plan_rate, 1 ), '0' ), '.' );
		$deal             = $GLOBALS['db_deal_checkout'] ?? null;
		$d_b64            = rawurlencode( base64_encode( $domain ) );
		$p_b64            = rawurlencode( base64_encode( (string) (int) round( $price ) ) );
		$offer_q          = ( $deal && isset( $_GET['offer'] ) ) ? '&offer=' . rawurlencode( sanitize_text_field( wp_unslash( $_GET['offer'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $deal ) {
			// An accepted offer is priced by the stored deal, so its plan link
			// stays on this checkout rather than the listing's plan chooser.
			$full_url = home_url( '/buy-now/?' . ltrim( $offer_q, '&' ) );
			$plan_url = home_url( '/buy-now/?' . ltrim( $offer_q, '&' ) . '&months=' . $plan_months );
		} else {
			$full_url = home_url( '/buy-now/?d=' . $d_b64 . '&p=' . $p_b64 );
			$plan_url = home_url( '/payment-plan-setup/?d=' . $d_b64 . '&p=' . $p_b64 );
		}
		$today_usd   = $is_plan ? $monthly_cents / 100 : $price;
		$listing_url = db_stripe_listing_url( $domain );
		$theme_uri   = function_exists( 'get_template_directory_uri' ) ? get_template_directory_uri() . '/assets' : home_url( '/wp-content/themes/DomainFolio/assets' );
		$header_logo = function_exists( 'db_brand_logo_url' ) ? db_brand_logo_url() : home_url( '/wp-content/uploads/2017/03/Domain-Brothers.png' );
		$footer_logo = home_url( '/wp-content/uploads/2016/11/logo1.png' );
		$sales_email = 'Sales@DomainBrothers.com';

		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Checkout — <?php echo esc_html( $domain ); ?> — Domain Brothers</title>
<style>
@font-face{font-family:'latoregular';src:url('<?php echo esc_url( $theme_uri . '/fonts/lato-regular-webfont.woff2' ); ?>') format('woff2');font-display:swap}
@font-face{font-family:'latomedium';src:url('<?php echo esc_url( $theme_uri . '/fonts/lato-medium-webfont.woff2' ); ?>') format('woff2');font-display:swap}
@font-face{font-family:'latosemibold';src:url('<?php echo esc_url( $theme_uri . '/fonts/lato-semibold-webfont.woff2' ); ?>') format('woff2');font-display:swap}
@font-face{font-family:'latobold';src:url('<?php echo esc_url( $theme_uri . '/fonts/lato-bold.woff2' ); ?>') format('woff2');font-display:swap}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'latoregular',Arial,sans-serif;background:#e4ebec;color:#000;min-height:100vh;display:flex;flex-direction:column;line-height:1.42857}
a{color:#67bd3c;text-decoration:none}
.co-wrap{width:100%;max-width:1250px;margin:0 auto;padding:0 15px}
/* Header */
.co-header{background:#00466b;padding:40px 0}
.co-header .co-wrap{display:flex;align-items:center;justify-content:space-between;gap:24px}
.co-logo{display:inline-block;flex:0 0 auto}
.co-logo img{display:block;width:200px;height:auto}
.co-ques{text-align:right;font-family:'latoregular';color:#fff;font-size:20px;line-height:30px;font-weight:normal}
.co-ques a{display:inline-block;color:#67bd3c}
.co-ques a:hover,.co-ques a:focus-visible{color:#fff}
/* Progress */
.co-body{flex:1;background:#e4ebec;padding:40px 0 30px}
.co-progress{display:flex;justify-content:center;margin:0 auto 42px;padding:0 15px;list-style:none;counter-reset:step}
.co-progress li{flex:0 0 30.33%;position:relative;text-align:center;font-size:16px;letter-spacing:1px;color:#333}
.co-progress li::before{content:counter(step);counter-increment:step;width:36px;height:36px;line-height:32px;border:2px solid #5e5e5e;border-radius:100%;display:block;text-align:center;margin:0 auto 10px;background:#e5ebec;position:relative;z-index:2;font-size:16px;letter-spacing:0;color:#333}
.co-progress li::after{content:"";position:absolute;width:100%;height:2px;background:#5e5e5e;top:17px;left:-50%;z-index:0}
.co-progress li:first-child::after{content:none}
.co-progress li:last-child::after{background:#cacaca}
.co-progress li.is-active,.co-progress li.is-done{color:#000;font-family:'latosemibold'}
.co-progress li.is-active::before,.co-progress li.is-done::before{border-color:#54b524;background:#54b524;color:#fff;line-height:32px}
.co-progress li.is-done::before{content:"\2713"}
.co-progress li.is-active+li::after,.co-progress li.is-done+li::after,.co-progress li.is-done::after,.co-progress li.is-active::after{background:#54b524}
.co-progress li:first-child.is-active::after,.co-progress li:first-child.is-done::after{content:none}
/* Card + next steps */
.co-main{display:flex;justify-content:center;align-items:flex-start;gap:30px;max-width:1250px;margin:0 auto;padding:0 15px}
.co-card{flex:0 1 840px;background:#f3f3f3;border-radius:10px;box-shadow:0 2px 86px rgba(0,0,0,.1);display:flex;position:relative;overflow:hidden}
.co-left{flex:0 0 58.333%;background:#fff;border-radius:10px 0 0 10px;min-height:600px;display:flex;flex-direction:column}
.co-right{flex:0 0 41.667%;padding:15px 15px 90px;position:relative}
.co-right::after{content:"";position:absolute;width:270px;height:34px;bottom:34px;right:40px;background:url('<?php echo esc_url( $theme_uri . '/images/payment_icon_n.png' ); ?>') no-repeat;max-width:calc(100% - 30px)}
/* Pay in full / Pay less up front */
.co-opts{padding:15px 15px 0;text-align:center;display:flex;justify-content:center;gap:6px}
.co-opts[hidden]{display:none}
.co-opt{border:1px solid #5e5e5e;border-radius:6px;position:relative;text-align:left;transition:.3s;display:block;color:#333;font-family:'latosemibold';font-size:15px;letter-spacing:.8px;padding:10px 5px 10px 30px;line-height:20px}
.co-opt:hover{border-color:#67bd3c}
.co-opt:focus-visible{outline:3px solid #54b524;outline-offset:2px}
.co-opt.is-active{border-color:#67bd3c;background:#e7f9de}
.co-opt .co-radio{position:absolute;top:13px;left:4px;width:20px;height:20px;border-radius:80px;background:#a2a2a2}
.co-opt .co-radio::after{content:"";position:absolute;left:0;right:0;top:0;bottom:0;width:5px;height:10px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg);margin:auto;margin-top:4px}
.co-opt.is-active .co-radio{background:#54b524}
.co-opt-price{display:block;font-family:'latobold';font-size:15px;line-height:20px}
.co-opt--plan{min-width:300px}
.co-opt-head{display:flex;justify-content:space-between;gap:12px;line-height:26px}
.co-opt-head b{font-family:'latobold';font-weight:normal;font-size:17px}
.co-opt-month{font-family:'latoregular';font-size:13px;line-height:18px;padding-top:4px;letter-spacing:0;display:flex;justify-content:space-between;gap:8px}
.co-opt-month b{font-family:'latobold';font-weight:normal}
/* Step content */
.co-step{padding:25px 45px 30px 32px;flex:1}
.co-step[hidden]{display:none}
.co-step h2{font-family:'latosemibold';font-weight:normal;font-size:16px;letter-spacing:.8px;color:#000;line-height:26px;margin:0 0 13px;padding:6px 15px;text-align:center;background:rgba(234,238,243,.4);border-radius:10px;box-shadow:0 3px 5px #dfdfdf;border:1px solid #efebeb}
.co-step h2:focus{outline:none}
.co-note{margin:0 0 26px;font-size:14px;line-height:20px;letter-spacing:.4px;color:#000}
.co-field{position:relative;margin:0 0 23px}
.co-field label{display:block;font-size:14px;line-height:20px;letter-spacing:.4px;color:#000}
.co-field input{display:block;background:#fff;border:1px solid #bfbfbf;padding:7px 20px;width:100%;height:38px;font-size:15px;font-family:'latoregular';margin:10px 0 0;outline:none;border-radius:80px;color:#000}
.co-field input:focus{border-color:#0c5378;box-shadow:0 0 0 3px rgba(12,83,120,.15)}
.co-field input[aria-invalid="true"]{border-color:#dc3232}
.co-req{position:absolute;right:-18px;bottom:6px;color:#f00;font-size:22px;line-height:1}
.co-ferr{display:block;color:#dc3232;font-size:13px;margin:5px 0 0 20px;min-height:0}
.co-ferr:empty{display:none}
.co-actions{display:flex;justify-content:center;align-items:center;gap:14px;margin-top:19px;flex-wrap:wrap}
.co-btn{color:#fff;background:#54b524;border:0;border-radius:80px;min-width:148px;min-height:42px;padding:10px 26px;font-family:'latobold';font-size:16px;letter-spacing:.6px;text-transform:uppercase;cursor:pointer;transition:.3s;display:inline-flex;align-items:center;justify-content:center;gap:8px}
.co-btn:hover:not(:disabled){background:#002b41}
.co-btn:focus-visible{outline:3px solid #002b41;outline-offset:2px}
.co-btn:disabled{background:#64748b;cursor:not-allowed}
.co-btn svg{width:15px;height:15px;fill:currentColor}
.co-btn--ghost{background:#fff;color:#002b41;border:1px solid #bfbfbf}
.co-btn--ghost:hover:not(:disabled){background:#002b41;color:#fff;border-color:#002b41}
/* Confirmation */
.co-review{list-style:none;margin:0 0 18px;border:1px solid #ebebeb;border-radius:10px;overflow:hidden}
.co-review li{display:flex;justify-content:space-between;gap:16px;padding:10px 16px;font-size:15px;letter-spacing:.4px;border-top:1px solid #ebebeb}
.co-review li:first-child{border-top:0}
.co-review span{color:#555}
.co-review b{font-family:'latosemibold';font-weight:normal;text-align:right;word-break:break-word}
.co-review .is-total b{color:#54b524;font-size:18px}
.co-edit{background:none;border:0;color:#0c5378;font-family:'latosemibold';font-size:14px;text-decoration:underline;cursor:pointer;padding:0}
.db-stripe-terms{font-size:13px;line-height:1.5;background:#fff8e6;border:1px solid #f0d58a;border-radius:10px;padding:12px 14px;margin:0 0 14px}
.db-stripe-agree{display:flex;gap:10px;align-items:flex-start;font-size:13px;line-height:1.5;margin:0 0 6px;cursor:pointer}
.db-stripe-agree input{margin-top:3px;flex:0 0 auto;width:16px;height:16px}
/* Payment */
.db-stripe-pe-wrap{background:#fff;border:1px solid #dfdfdf;border-radius:10px;padding:16px;margin-bottom:6px;min-height:80px}
.db-stripe-err{background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:11px 15px;color:#991b1b;font-size:14px;margin-bottom:14px;display:none}
.co-trust{display:flex;gap:18px;justify-content:center;flex-wrap:wrap;margin-top:16px;font-size:13px;color:#555}
.co-trust span{display:inline-flex;align-items:center;gap:6px}
.co-trust svg{width:13px;height:13px;fill:#54b524}
.db-stripe-notice{text-align:center;padding:24px 0;color:#333;font-size:15px;line-height:1.6}
.db-stripe-notice a{color:#0c5378;text-decoration:underline}
/* Order summary */
.co-summary{padding:15px;background:rgba(255,255,255,.8);box-shadow:0 0 5px rgba(0,0,0,.1);border-radius:6px}
.co-summary h3{font-family:'latoregular';font-weight:normal;font-size:16px;line-height:30px;color:#000;letter-spacing:.8px;margin:0}
.co-sum-row{position:relative;display:flex;align-items:center;min-height:34px;padding-left:42px;margin:10px 0 15px}
.co-sum-row::before{content:"";position:absolute;left:0;top:0;bottom:0;margin:auto 0;width:36px;height:28px;background:#fff url('<?php echo esc_url( $theme_uri . '/images/12domain_ic.png' ); ?>') no-repeat center bottom;border-radius:4px;box-shadow:0 5px rgba(83,181,36,.1)}
.co-sum-row--price{margin:0;padding-top:0}
.co-sum-row--price::before{height:34px;background-image:url('<?php echo esc_url( $theme_uri . '/images/price_ic.png' ); ?>')}
.co-sum-domain{font-family:'latosemibold';font-size:16px;letter-spacing:.6px;line-height:22px;word-break:break-all;padding-right:28px}
.co-sum-price{font-family:'latosemibold';font-size:20px;color:#54b524;letter-spacing:.6px;margin-right:auto}
.co-sum-tax{font-size:16px;color:#000;padding-right:20px}
.co-remove{position:absolute;right:0;top:0;bottom:0;margin:auto 0;width:20px;height:20px;border:1px solid #999;border-radius:50%;color:#999;display:flex;align-items:center;justify-content:center;font-size:13px;line-height:1}
.co-remove:hover,.co-remove:focus-visible{border-color:#67bd3c;color:#67bd3c}
.co-sum-plan{margin-top:12px;padding-top:10px;border-top:1px solid #ebebeb;font-family:'latosemibold';font-size:14px;letter-spacing:.6px;line-height:22px;color:#333}
.co-sum-plan b{color:#54b524;font-weight:normal}
/* Next steps */
.co-next{flex:0 0 340px;padding-top:15px}
.co-next h2{font-family:'latoregular';font-weight:normal;font-size:20px;color:#000;line-height:30px;margin:0 0 30px;padding:0 0 13px;position:relative;text-align:center}
.co-next h2::after{content:"";width:61px;height:1px;background:#000;position:absolute;left:0;right:0;margin:auto;bottom:0}
.co-next ul{list-style:none}
.co-next li{position:relative;padding-left:108px;min-height:94px;display:flex;align-items:center;font-size:16px;line-height:22px;letter-spacing:.6px;color:#000;margin-bottom:6px}
.co-next li img{position:absolute;left:0;top:0;bottom:0;margin:auto 0;width:88px;height:auto}
/* Footer */
.co-footer-top{background:#67bd3c;padding:30px 0}
.co-footer-top .co-wrap,.co-footer-bottom .co-wrap{max-width:1170px;display:flex;align-items:center;justify-content:space-between;gap:20px}
.co-footer-logo img{display:block;width:200px;height:auto}
.co-social{display:flex;gap:20px;list-style:none}
.co-social a{display:flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:80px;transition:.5s all}
.co-social svg{width:24px;height:24px;fill:#fff;transition:.5s all}
.co-social a:hover,.co-social a:focus-visible{background:#fff;box-shadow:0 0 13px 2px rgba(37,57,77,.6)}
.co-social a:hover svg,.co-social a:focus-visible svg{fill:#25394d}
.co-footer-bottom{background:#0d2b3a;padding:20px 0;color:#68bd3c;font-size:12px}
.co-footer-links{display:flex;flex-wrap:wrap;justify-content:flex-end;list-style:none}
.co-footer-links a{color:#68bd3c;margin:0 8px;display:inline-block;padding:4px 0}
.co-footer-links a:hover,.co-footer-links a:focus-visible{color:#fff}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0}
/* Responsive — same breakpoints as the live theme */
@media (max-width:1199px){
	.co-main{flex-direction:column;align-items:center}
	.co-card{flex:0 1 auto;width:100%;max-width:840px}
	.co-next{flex:0 0 auto;width:100%;max-width:982px;padding-top:30px}
	.co-next ul{display:flex;gap:20px;justify-content:space-between}
	.co-next li{flex:1 1 0}
	.co-opt-head b,.co-opt-price{font-size:15px}
}
@media (max-width:991px){
	.co-opt{padding:10px 5px 10px 30px}
	.co-opt--plan{min-width:0}
	.co-opts{padding:15px 5px 0}
}
@media (max-width:767px){
	.co-header{padding:20px 0 15px}
	.co-header .co-wrap{justify-content:center}
	.co-logo img{width:180px}
	.co-ques{display:none}
	.co-body{padding:24px 0}
	.co-progress{margin-bottom:24px}
	.co-progress li{font-size:15px;line-height:18px}
	.co-progress .co-long{display:none}
	.co-card{flex-direction:column}
	.co-right{order:-1;padding:15px 15px 15px}
	.co-right::after{display:none}
	.co-left{border-radius:0 0 10px 10px;min-height:0}
	.co-step{padding:24px 24px 30px}
	.co-step h2.co-h-billing,.co-note{display:none}
	.co-opt-month{display:none}
	.co-opt-head{display:block}
	.co-opt-head b{display:block;line-height:22px}
	.co-req{right:-16px}
	.co-next ul{display:block}
	.co-next li{padding-left:100px;min-height:84px;margin-bottom:10px}
	.co-next li img{width:84px}
	.co-next h2{margin-bottom:20px}
	.co-footer-top .co-wrap,.co-footer-bottom .co-wrap{flex-direction:column;text-align:center}
	.co-footer-links{justify-content:center}
}
@media (max-width:479px){
	.co-opts{flex-wrap:wrap}
	.co-opt{font-size:14px;flex:1 1 120px}
	.co-progress li{font-size:13px;letter-spacing:.6px;line-height:16px}
	.co-step{padding:20px 18px 26px}
	.co-field{margin-right:14px}
	.co-review li{flex-direction:column;gap:2px}
	.co-review b{text-align:left}
	.co-footer-links a{margin:0 6px}
}
@media (max-width:359px){
	.co-opt{padding:10px 8px 10px 28px;letter-spacing:.4px}
}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
</style>
</head>
<body>
<header class="co-header">
	<div class="co-wrap">
		<a class="co-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Domain Brothers home"><img src="<?php echo esc_url( $header_logo ); ?>" alt="Domain Brothers" width="200" height="54"></a>
		<p class="co-ques">Questions? We're here to help! Email today:<br><a href="mailto:<?php echo esc_attr( strtolower( $sales_email ) ); ?>"><?php echo esc_html( $sales_email ); ?></a></p>
	</div>
</header>

<main class="co-body" id="main">
	<ol class="co-progress" id="co-progress" aria-label="Checkout steps">
		<li class="is-active" aria-current="step">Billing <span class="co-long">Information</span></li>
		<li>Order <span class="co-long">Confirmation</span></li>
		<li>Payment</li>
	</ol>

	<div class="co-main">
		<div class="co-card">
			<div class="co-left">

<?php if ( $not_configured ) : ?>
				<div class="co-step">
					<div class="db-stripe-notice">
						<p>Our payment system is being configured.</p>
						<p style="margin-top:8px">Please <a href="mailto:<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">contact support</a> to complete your purchase.</p>
					</div>
				</div>

<?php elseif ( $has_error ) : ?>
				<div class="co-step">
					<div class="db-stripe-notice">
						<p><?php echo esc_html( $pi['error'] ); ?></p>
						<p style="margin-top:8px;font-size:.85rem;opacity:.7">Please try again or <a href="mailto:<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>">contact support</a>.</p>
					</div>
				</div>

<?php else : ?>
				<div class="co-opts" id="co-opts" role="group" aria-label="Payment option">
<?php if ( $is_plan ) : ?>
					<a class="co-opt" href="<?php echo esc_url( $full_url ); ?>"><span class="co-radio" aria-hidden="true"></span>Pay in full <span class="co-opt-price"><?php echo esc_html( db_stripe_money( $price ) ); ?></span></a>
					<div class="co-opt co-opt--plan is-active" aria-current="true">
<?php else : ?>
					<div class="co-opt is-active" aria-current="true"><span class="co-radio" aria-hidden="true"></span>Pay in full <span class="co-opt-price"><?php echo esc_html( db_stripe_money( $price ) ); ?></span></div>
					<a class="co-opt co-opt--plan" href="<?php echo esc_url( $plan_url ); ?>">
<?php endif; ?>
						<span class="co-radio" aria-hidden="true"></span>
						<span class="co-opt-head"><span>Pay less up front</span> <b><?php echo esc_html( db_stripe_money( $plan_first_usd ) ); ?></b></span>
						<span class="co-opt-month"><span>Monthly: <b><?php echo esc_html( db_stripe_money( $plan_first_usd ) ); ?></b> (<b><?php echo esc_html( (string) $plan_months ); ?></b> Payments)</span><span>Now</span></span>
<?php echo $is_plan ? "\t\t\t\t\t</div>\n" : "\t\t\t\t\t</a>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>

				<form id="db-stripe-form" novalidate>
					<section class="co-step" id="co-step-1" data-step="1" aria-labelledby="co-h1">
						<h2 class="co-h-billing" id="co-h1" tabindex="-1">Please fill-in your billing information</h2>
						<p class="co-note">You will receive an invoice once your payment is processed.</p>
						<div class="co-field">
							<label for="co-name">Full Name</label>
							<input type="text" id="co-name" name="name" autocomplete="name" required aria-describedby="co-name-err">
							<span class="co-req" aria-hidden="true">*</span>
							<span class="co-ferr" id="co-name-err" role="alert"></span>
						</div>
						<div class="co-field">
							<label for="db-stripe-email">Email Id</label>
							<input type="email" id="db-stripe-email" name="email" autocomplete="email" required aria-describedby="co-email-err">
							<span class="co-req" aria-hidden="true">*</span>
							<span class="co-ferr" id="co-email-err" role="alert"></span>
						</div>
						<div class="co-field">
							<label for="co-phone">Phone #</label>
							<input type="tel" id="co-phone" name="phone" autocomplete="tel" inputmode="tel" required aria-describedby="co-phone-err">
							<span class="co-req" aria-hidden="true">*</span>
							<span class="co-ferr" id="co-phone-err" role="alert"></span>
						</div>
						<div class="co-actions">
							<button type="button" class="co-btn" id="co-next-1">Next</button>
						</div>
					</section>

					<section class="co-step" id="co-step-2" data-step="2" aria-labelledby="co-h2" hidden>
						<h2 id="co-h2" tabindex="-1">Please confirm your order</h2>
						<ul class="co-review">
							<li><span>Full Name</span><b id="co-r-name"></b></li>
							<li><span>Email Id</span><b id="co-r-email"></b></li>
							<li><span>Phone #</span><b id="co-r-phone"></b></li>
							<li><span>Domain</span><b><?php echo esc_html( $domain ); ?></b></li>
							<li><span>Payment</span><b><?php echo esc_html( $is_plan ? $months . ' monthly payments of ' . db_stripe_money( $monthly_cents / 100 ) . ( $plan_rate > 0 ? ' (' . $rate_label . '% interest, total ' . db_stripe_money( $plan_total_usd ) . ')' : ' (0% interest)' ) : 'Pay in full' ); ?></b></li>
							<li class="is-total"><span>Due today</span><b><?php echo esc_html( db_stripe_money( $today_usd ) ); ?></b></li>
						</ul>
<?php if ( $is_plan ) : ?>
						<p class="db-stripe-terms"><?php echo wp_kses( db_plan_terms_html(), [ 'strong' => [], 'em' => [] ] ); ?></p>
						<label class="db-stripe-agree" for="db-stripe-agree">
							<input type="checkbox" id="db-stripe-agree" required aria-describedby="co-agree-err">
							<span>I agree to the payment plan terms above: the domain transfers to me only after the final installment, and if a payment stays unpaid <?php echo esc_html( (string) ( defined( 'DB_PLAN_GRACE_DAYS' ) ? DB_PLAN_GRACE_DAYS : 10 ) ); ?> days after its due date the plan ends and installments already paid are not refunded.</span>
						</label>
						<span class="co-ferr" id="co-agree-err" role="alert"></span>
<?php endif; ?>
						<div class="co-actions">
							<button type="button" class="co-btn co-btn--ghost" data-go="1">Back</button>
							<button type="button" class="co-btn" id="co-next-2">Confirm</button>
						</div>
					</section>

					<section class="co-step" id="co-step-3" data-step="3" aria-labelledby="co-h3" hidden>
						<h2 id="co-h3" tabindex="-1">Secure payment &mdash; <?php echo esc_html( db_stripe_money( $today_usd ) ); ?></h2>
						<div id="db-stripe-err" class="db-stripe-err" role="alert"></div>
						<div class="db-stripe-pe-wrap">
							<div id="db-stripe-payment-element"></div>
						</div>
						<div class="co-actions">
							<button type="button" class="co-btn co-btn--ghost" data-go="2">Back</button>
							<button type="submit" id="db-stripe-btn" class="co-btn db-stripe-btn"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6z"/></svg><?php echo esc_html( $btn_label ); ?></button>
						</div>
						<div class="co-trust">
							<span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5z"/></svg>SSL Secured</span>
							<span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>Escrow Protected</span>
							<span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>24-hr Support</span>
						</div>
					</section>
				</form>
<?php endif; ?>
			</div>

			<aside class="co-right" aria-label="Order summary">
				<div class="co-summary">
					<h3>Your Order Summary - 1 Item</h3>
					<div class="co-sum-row">
						<span class="co-sum-domain"><?php echo esc_html( ucfirst( $domain ) ); ?></span>
						<a class="co-remove" href="<?php echo esc_url( $listing_url ); ?>" aria-label="Remove <?php echo esc_attr( $domain ); ?> and return to the listing">&times;</a>
					</div>
					<div class="co-sum-row co-sum-row--price">
						<span class="co-sum-price"><?php echo esc_html( db_stripe_money( $price ) ); ?></span>
						<span class="co-sum-tax">(USD)</span>
					</div>
<?php if ( $is_plan ) : ?>
					<p class="co-sum-plan">Today: <b><?php echo esc_html( db_stripe_money( $monthly_cents / 100 ) ); ?></b> (payment 1 of <?php echo esc_html( (string) $months ); ?>)<br><?php echo esc_html( $plan_rate > 0 ? $rate_label . '% interest · total ' . db_stripe_money( $plan_total_usd ) : '0% interest' ); ?></p>
<?php endif; ?>
				</div>
			</aside>
		</div>

		<section class="co-next" aria-labelledby="co-next-h">
			<h2 id="co-next-h">What are the next steps?</h2>
			<ul>
				<li><img src="<?php echo esc_url( $theme_uri . '/images/payicon_img1.png' ); ?>" alt="" width="88" height="88" loading="lazy">Your payment is securely processed</li>
				<li><img src="<?php echo esc_url( $theme_uri . '/images/payicon_img2.png' ); ?>" alt="" width="88" height="88" loading="lazy">The domain is in hold &amp; secured for you</li>
				<li><img src="<?php echo esc_url( $theme_uri . '/images/payicon_img3.png' ); ?>" alt="" width="88" height="88" loading="lazy">We give you instruction on the domain transfer</li>
			</ul>
		</section>
	</div>
</main>

<footer class="co-footer">
	<div class="co-footer-top">
		<div class="co-wrap">
			<a class="co-footer-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="Domain Brothers home"><img src="<?php echo esc_url( $footer_logo ); ?>" alt="Domain Brothers" width="200" height="70" loading="lazy"></a>
			<ul class="co-social">
				<li><a href="https://www.facebook.com/Domain-Brothers-886942828108073/" target="_blank" rel="noopener" aria-label="Domain Brothers on Facebook"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 8V6.2c0-.8.2-1.2 1.4-1.2H17V2h-2.6C11.5 2 10.6 3.4 10.6 5.9V8H8.5v3h2.1v11H14V11h2.6l.4-3z"/></svg></a></li>
				<li><a href="https://twitter.com/DomainBrothers" target="_blank" rel="noopener" aria-label="Domain Brothers on Twitter"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M23 5.1c-.8.4-1.7.6-2.6.7.9-.6 1.6-1.4 2-2.5-.9.5-1.9.9-2.9 1.1A4.5 4.5 0 0 0 11.8 8.5 12.8 12.8 0 0 1 2.5 3.8a4.5 4.5 0 0 0 1.4 6c-.7 0-1.4-.2-2-.6 0 2.2 1.6 4 3.6 4.4-.7.2-1.4.2-2 .1.6 1.8 2.2 3.1 4.2 3.1A9 9 0 0 1 1 18.7 12.8 12.8 0 0 0 7.9 20.7c8.3 0 12.8-6.9 12.8-12.8v-.6c.9-.6 1.7-1.4 2.3-2.2z"/></svg></a></li>
				<li><a href="https://www.instagram.com/domainbrothers/" target="_blank" rel="noopener" aria-label="Domain Brothers on Instagram"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 2h10a5 5 0 0 1 5 5v10a5 5 0 0 1-5 5H7a5 5 0 0 1-5-5V7a5 5 0 0 1 5-5zm0 2a3 3 0 0 0-3 3v10a3 3 0 0 0 3 3h10a3 3 0 0 0 3-3V7a3 3 0 0 0-3-3H7zm5 3.5a4.5 4.5 0 1 1 0 9 4.5 4.5 0 0 1 0-9zm0 2a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM17.5 5.5a1 1 0 1 1 0 2 1 1 0 0 1 0-2z"/></svg></a></li>
			</ul>
		</div>
	</div>
	<div class="co-footer-bottom">
		<div class="co-wrap">
			<p>Copyright &copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> | A Mindshare Consulting Inc company | All Rights Reserved.</p>
			<ul class="co-footer-links">
				<li><a href="https://blog.domainbrothers.com/" target="_blank" rel="noopener">Blog</a></li>
				<li><a href="<?php echo esc_url( home_url( '/about-us/' ) ); ?>">About</a></li>
				<li><a href="<?php echo esc_url( home_url( '/our-team/' ) ); ?>">Our Team</a></li>
				<li><a href="<?php echo esc_url( home_url( '/news/' ) ); ?>">News</a></li>
				<li><a href="<?php echo esc_url( home_url( '/privacy-policy/' ) ); ?>">Privacy Policy</a></li>
				<li><a href="<?php echo esc_url( home_url( '/refund-policy/' ) ); ?>">Refund Policy</a></li>
				<li><a href="<?php echo esc_url( home_url( '/terms-of-use/' ) ); ?>">Terms of Use</a></li>
				<li><a href="<?php echo esc_url( home_url( '/faqs/' ) ); ?>">FAQs</a></li>
				<li><a href="<?php echo esc_url( home_url( '/site-map/' ) ); ?>">Sitemap</a></li>
			</ul>
		</div>
	</div>
</footer>

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
	var form     = document.getElementById('db-stripe-form');
	var btn      = document.getElementById('db-stripe-btn');
	var errDiv   = document.getElementById('db-stripe-err');
	var nameInp  = document.getElementById('co-name');
	var emailInp = document.getElementById('db-stripe-email');
	var phoneInp = document.getElementById('co-phone');
	var agreeInp = document.getElementById('db-stripe-agree');
	var opts     = document.getElementById('co-opts');
	var steps    = document.querySelectorAll('#co-progress li');
	var current  = 1;
	var stripe = null, elements = null, payEl = null, mounted = false;

	function val(el) { return (el.value || '').trim(); }
	function fieldErr(input, errId, msg) {
		var e = document.getElementById(errId);
		if (e) { e.textContent = msg || ''; }
		if (input) { input.setAttribute('aria-invalid', msg ? 'true' : 'false'); }
		return !msg;
	}
	function validBilling() {
		var ok = true, first = null;
		var n = val(nameInp), m = val(emailInp), p = val(phoneInp).replace(/[\s().-]/g, '');
		if (!fieldErr(nameInp, 'co-name-err', n.length < 2 ? 'Please enter your full name.' : '')) { ok = false; first = first || nameInp; }
		if (!fieldErr(emailInp, 'co-email-err', !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(m) ? 'Please enter a valid email address.' : '')) { ok = false; first = first || emailInp; }
		if (!fieldErr(phoneInp, 'co-phone-err', !/^\+?\d{7,15}$/.test(p) ? 'Please enter a valid phone number.' : '')) { ok = false; first = first || phoneInp; }
		if (first) { first.focus(); }
		return ok;
	}

	function show(n) {
		current = n;
		[1, 2, 3].forEach(function (i) {
			var s = document.getElementById('co-step-' + i);
			if (s) { s.hidden = i !== n; }
			var li = steps[i - 1];
			li.className = i < n ? 'is-done' : (i === n ? 'is-active' : '');
			if (i === n) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
		});
		if (opts) { opts.hidden = n !== 1; }
		if (n === 2) {
			document.getElementById('co-r-name').textContent  = val(nameInp);
			document.getElementById('co-r-email').textContent = val(emailInp);
			document.getElementById('co-r-phone').textContent = val(phoneInp);
		}
		if (n === 3) { mountPayment(); }
		var h = document.getElementById('co-h' + n);
		if (h) { h.focus(); }
		window.scrollTo({ top: Math.max(0, document.getElementById('co-progress').getBoundingClientRect().top + window.pageYOffset - 10), behavior: 'smooth' });
	}

	function mountPayment() {
		if (mounted || typeof Stripe === 'undefined') { return; }
		mounted = true;
		stripe   = Stripe(window.DB_PUB_KEY);
		elements = stripe.elements({ clientSecret: window.DB_PI_SECRET, appearance: { theme: 'stripe', variables: { borderRadius: '10px', colorPrimary: '#54b524', colorText: '#000000', colorDanger: '#dc3232', fontFamily: 'Lato, Arial, sans-serif' } } });
		payEl    = elements.create('payment', { defaultValues: { billingDetails: { name: val(nameInp), email: val(emailInp), phone: val(phoneInp) } } });
		payEl.mount('#db-stripe-payment-element');
	}

	function showErr(msg) {
		errDiv.textContent = msg;
		errDiv.style.display = 'block';
		btn.disabled = false;
		btn.textContent = window.DB_BTN_LABEL;
	}

	document.getElementById('co-next-1').addEventListener('click', function () { if (validBilling()) { show(2); } });
	document.getElementById('co-next-2').addEventListener('click', function () {
		if (agreeInp && !agreeInp.checked) {
			fieldErr(agreeInp, 'co-agree-err', 'Please confirm you agree to the payment plan terms.');
			agreeInp.focus();
			return;
		}
		if (agreeInp) { fieldErr(agreeInp, 'co-agree-err', ''); }
		show(3);
	});
	Array.prototype.forEach.call(document.querySelectorAll('[data-go]'), function (b) {
		b.addEventListener('click', function () { show(parseInt(b.getAttribute('data-go'), 10)); });
	});
	[nameInp, emailInp, phoneInp].forEach(function (inp) {
		inp.addEventListener('input', function () { if (inp.getAttribute('aria-invalid') === 'true') { validBilling(); } });
	});

	form.addEventListener('submit', function (e) {
		e.preventDefault();
		// Enter in an earlier step moves the buyer forward, never pays.
		if (current === 1) { document.getElementById('co-next-1').click(); return; }
		if (current === 2) { document.getElementById('co-next-2').click(); return; }
		if (!stripe || !elements) { showErr('The payment form is still loading. Please wait a moment and try again.'); return; }

		var email = val(emailInp);
		if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { show(1); validBilling(); return; }
		if (agreeInp && !agreeInp.checked) { show(2); return; }

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
				confirmParams: {
					return_url: window.DB_RETURN_URL,
					payment_method_data: { billing_details: { name: val(nameInp), email: email, phone: val(phoneInp) } }
				},
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
			do_action( 'db_order_event', 'full', compact( 'domain', 'amount_usd', 'customer_email', 'pi_id', 'meta' ) );
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
		do_action( 'db_order_event', 'plan_installment', [
			'domain'         => $domain,
			'amount_usd'     => $amount_usd,
			'customer_email' => $customer_email,
			'pi_id'          => $pi_id,
			'meta'           => $meta,
			'installment'    => $installment_num,
			'total_months'   => $total_months,
			'total_price'    => $total_price,
		] );

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

if ( ! function_exists( 'db_stripe_domain_taken' ) ) {
	/** True once a domain is sold, or while a payment plan on it is active or past due. */
	function db_stripe_domain_taken( string $domain ) : bool {
		if ( get_option( db_stripe_store_key( 'sold', $domain ) ) ) {
			return true;
		}
		$plan = (array) get_option( db_stripe_store_key( 'plan', $domain ), [] );
		return in_array( $plan['status'] ?? '', [ 'active', 'past_due', 'completed' ], true );
	}
}

if ( ! function_exists( 'db_stripe_domain_unavailable' ) ) {
	/** Taken by a buyer, or marked sold / not for sale by an admin (listing status). */
	function db_stripe_domain_unavailable( string $domain ) : bool {
		return db_stripe_domain_taken( $domain )
			|| ( function_exists( 'db_listing_blocked_by_name' ) && db_listing_blocked_by_name( $domain ) );
	}
}

if ( ! function_exists( 'db_stripe_on_sale_complete' ) ) {
	function db_stripe_on_sale_complete( string $domain, float $amount_usd, string $customer_email, string $type, string $pi_id = '' ) : void {
		update_option( db_stripe_store_key( 'sold', $domain ), [ 'ts' => time(), 'pi' => $pi_id ], false );
		if ( function_exists( 'db_listing_mark_sold' ) ) {
			db_listing_mark_sold( $domain, $amount_usd, $customer_email, 'full' === $type ? 'website' : 'website_plan' );
		}
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
