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

if ( ! function_exists( 'db_stripe_render_checkout_page' ) ) {
	/**
	 * Renders /buy-now/ as the three-step checkout used on production:
	 * 1. Billing Information (pay-in-full / pay-less-up-front choice + name,
	 *    email, phone), 2. Order Confirmation (review, plan terms), 3. Payment
	 *    (Stripe Payment Element). Steps switch client-side; the PaymentIntent
	 *    is created server-side before render, exactly as before.
	 */
	function db_stripe_render_checkout_page( string $domain, float $price, int $months, array $pi ) : void {
		$keys           = db_stripe_keys();
		$pub_key        = $keys['pub'];
		$is_plan        = $months > 0;
		$has_error      = isset( $pi['error'] );
		$client_secret  = $pi['client_secret'] ?? '';
		$not_configured = empty( $pub_key ) || empty( $keys['sec'] );
		$can_pay        = ! $not_configured && ! $has_error && '' !== $client_secret;

		$price_display = '$' . number_format( $price, 2, '.', ',' );
		$btn_label     = $is_plan ? 'Start Payment Plan' : 'Pay Now';

		// "Pay less up front" option: the buyer's chosen term when already on a
		// plan, otherwise 6 months (production's default), falling back to the
		// first configured term.
		$plan_terms = function_exists( 'db_plan_terms' ) ? array_map( 'intval', (array) db_plan_terms() ) : [ 3, 6, 9, 12 ];
		$alt_months = $is_plan ? $months : ( in_array( 6, $plan_terms, true ) ? 6 : (int) reset( $plan_terms ) );
		$alt_first  = $alt_months > 0 ? db_stripe_installment_cents( $price, $alt_months, 1 ) / 100 : 0.0;
		$alt_rate   = function_exists( 'db_plan_rate' ) ? (float) db_plan_rate( $alt_months ) : 0.0;
		$alt_total  = function_exists( 'db_plan_total_cents' ) ? db_plan_total_cents( $price, $alt_months ) / 100 : $price;
		$rate_label = rtrim( rtrim( number_format( $alt_rate, 1 ), '0' ), '.' ) . '% interest';

		$due_today         = $is_plan ? $alt_first : $price;
		$due_today_display = '$' . number_format( $due_today, 2, '.', ',' );

		// Toggle targets keep the visitor's original ?d=/?p= (base64) so the
		// server-side lookup works exactly as on first load.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$d_b64 = isset( $_GET['d'] ) ? sanitize_text_field( wp_unslash( $_GET['d'] ) ) : base64_encode( $domain );
		$p_b64 = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : base64_encode( (string) $price );
		// phpcs:enable
		// An accepted custom offer (?offer=) is priced from the stored deal, so
		// the toggle keeps the offer token instead of ?d=/?p=, which would
		// switch the buyer back to the listing price.
		$offer_tok = isset( $_GET['offer'] ) ? sanitize_text_field( wp_unslash( $_GET['offer'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$base_args = '' !== $offer_tok && ! empty( $GLOBALS['db_deal_checkout'] )
			? [ 'offer' => rawurlencode( $offer_tok ) ]
			: [ 'd' => rawurlencode( $d_b64 ), 'p' => rawurlencode( $p_b64 ) ];
		$buy_now   = home_url( '/buy-now/' );
		$full_url  = add_query_arg( $base_args, $buy_now );
		$plan_url  = add_query_arg( array_merge( [ 't' => 'plan', 'months' => $alt_months ], $base_args ), $buy_now );
		// The plan-length chooser prices from the listing, so it is only
		// offered on listing checkouts, not on accepted offers.
		$terms_url = isset( $base_args['offer'] ) ? '' : add_query_arg( $base_args, home_url( '/payment-plan-setup/' ) );

		// 'd', not 'domain' — the domain CPT registers 'domain' as its own
		// public query var, so ?domain=... hijacks the main query away from
		// the thank-you page (renders the listing or a 404 instead).
		// The signature is 'sig', not 's': 's' is WordPress's search query
		// var, and ?s=... on a page URL turns it into a search for that page
		// (a 404 here, after every successful payment).
		$ty_type    = $is_plan ? 'plan' : 'full';
		$ty_amount  = number_format( $price, 0, '.', ',' );
		$return_url = home_url(
			'/thank-you/?type=' . $ty_type
			. '&d=' . rawurlencode( $domain )
			. '&amt=' . rawurlencode( $ty_amount )
			// Signed, so the confirmation page can't be faked by editing the URL.
			. ( $is_plan ? '&plan_months=' . (int) $months : '' )
			. ( function_exists( 'db_ty_sig' ) ? '&sig=' . db_ty_sig( $ty_type, $domain, $ty_amount, $is_plan ? (int) $months : 0 ) : '' )
		);

		$support_email = defined( 'DB_AEO_CONTACT_EMAIL' ) ? (string) DB_AEO_CONTACT_EMAIL : 'sales@domainbrothers.com';
		$grace_days    = defined( 'DB_PLAN_GRACE_DAYS' ) ? (int) DB_PLAN_GRACE_DAYS : 10;
		$check_svg     = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>';

		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		// Rendered inside the theme's own header and footer (get_header() /
		// get_footer()), so /buy-now/ carries the same site header, menu,
		// mobile menu and footer as every other page.
		add_filter( 'body_class', static function ( $classes ) {
			$classes[] = 'db-co-page';
			return $classes;
		} );
		add_filter( 'pre_get_document_title', static function () use ( $domain ) {
			return 'Checkout — ' . $domain . ' — Domain Brothers';
		} );
		wp_enqueue_style( 'db-co-lato', 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap', [], null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		add_action( 'wp_head', static function () {
			?>
<style id="db-checkout">
/* Checkout content, isolated from the theme and db-design-block styles
   (which restyle every input, label, heading and link on the page). Every
   rule is scoped to #db-co so an id outranks those class-based rules. */
#db-co{--co-navy:#00476b;--co-green:#5cb531;--co-green-btn:#3c9c1e;--co-green-text:#2f8a17;--co-green-soft:#ecf7e5;--co-ink:#1d2b36;--co-muted:#4a5864;--co-line:#c3ccd2;--co-bg:#e7edf0;--co-panel:#f1f2f3;--co-err:#c62828;position:relative;background:var(--co-bg);box-shadow:0 0 0 100vmax var(--co-bg);clip-path:inset(0 -100vmax);display:flow-root;color:var(--co-ink);font-family:Lato,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;line-height:1.5;font-size:15px;padding-bottom:40px}
#db-co,#db-co *,#db-co *::before,#db-co *::after{box-sizing:border-box}
#db-co *{margin:0;padding:0}
#db-co :is(h1,h2,h3,h4,h5,h6,p,li,a,td,th,label,input,select,textarea,button,small,strong,em,dt,dd,span){font-family:Lato,-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif !important}
#db-co :is(h1,h2,h3,h4,h5,h6,p,li,dt,dd,label){color:inherit;font-size:inherit;font-weight:inherit;line-height:inherit;letter-spacing:inherit;text-transform:none}
#db-co :is(ol,ul){list-style:none}
#db-co :is(button,a,span,strong){text-transform:none;letter-spacing:inherit}
#db-co a{color:inherit}
#db-co :is(input,select,textarea){min-height:0;max-width:none;box-shadow:none}
#db-co .co-wrap{width:100%;max-width:1240px;margin:0 auto;padding:0 24px}
/* The theme's content column is boxed and padded; the checkout runs full width. */
body.db-co-page #content.site-content{max-width:none !important;padding:0 !important;margin:0 !important;background:#e7edf0 !important}
#db-co .co-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
/* Stepper */
#db-co .co-steps-wrap{max-width:1290px;margin:48px auto 40px;padding:0 24px}
#db-co .co-steps{--w:190px;--p:.5;list-style:none;position:relative;display:flex;justify-content:space-between}
#db-co .co-steps::before,#db-co .co-steps::after{content:"";position:absolute;top:17px;left:calc(var(--w) / 2);height:2px}
#db-co .co-steps::before{right:calc(var(--w) / 2);background:var(--co-line)}
#db-co .co-steps::after{width:calc((100% - var(--w)) * var(--p));background:var(--co-green);transition:width .3s}
#db-co .co-step{position:relative;z-index:1;flex:0 0 var(--w);display:flex;justify-content:center}
#db-co .co-step-btn{display:flex;flex-direction:column;align-items:center;gap:12px;background:none;border:0;font:inherit;color:inherit;padding:0;cursor:default;text-align:center}
#db-co .co-step.is-done .co-step-btn{cursor:pointer}
#db-co .co-step-num{width:36px;height:36px;border-radius:50%;border:2px solid #6d7a84;background:#fff;color:#33424e;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700}
#db-co .co-step.is-active .co-step-num,#db-co .co-step.is-done .co-step-num{background:var(--co-green);border-color:var(--co-green);color:#fff}
#db-co .co-step-num svg{width:18px;height:18px;fill:#fff}
#db-co .co-step-label{font-size:17px;letter-spacing:.03em;color:#2d3d4a;white-space:nowrap}
#db-co .co-step.is-active .co-step-label{font-weight:700;color:var(--co-ink)}
#db-co .co-step.is-done .co-step-btn:hover .co-step-label,#db-co .co-step.is-done .co-step-btn:focus-visible .co-step-label{text-decoration:underline}
#db-co .co-step-btn:focus-visible{outline:3px solid var(--co-green-text);outline-offset:4px;border-radius:6px}
/* Layout */
#db-co .co-grid{display:grid;grid-template-columns:minmax(0,840px) minmax(0,1fr);gap:24px 40px;align-items:start}
#db-co .co-card{display:grid;grid-template-columns:58.5% 41.5%;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 2px 14px rgba(15,34,48,.06)}
#db-co .co-left{padding:16px 32px 48px}
#db-co .co-right{background:var(--co-panel);padding:16px 16px 32px;display:flex;flex-direction:column;justify-content:space-between;gap:24px}
/* Pay option toggle */
#db-co .co-toggle{display:grid;grid-template-columns:auto 1fr;gap:10px;margin:0 0 26px -16px}
#db-co .co-opt{position:relative;display:flex;gap:8px;align-items:flex-start;border:1px solid #6d7a84;border-radius:3px;padding:10px 6px 8px;background:#fff;text-decoration:none;color:var(--co-ink);min-height:70px;transition:border-color .15s,background .15s}
#db-co .co-opt:hover{border-color:var(--co-green)}
#db-co .co-opt:focus-visible{outline:3px solid var(--co-green-text);outline-offset:2px}
#db-co .co-opt.is-on{background:var(--co-green-soft);border-color:var(--co-green)}
#db-co .co-opt-tick{flex:0 0 20px;width:20px;height:20px;border-radius:50%;background:#8a949b;display:flex;align-items:center;justify-content:center;margin-top:1px}
#db-co .co-opt.is-on .co-opt-tick{background:var(--co-green)}
#db-co .co-opt-tick svg{width:14px;height:14px;fill:#fff}
#db-co .co-opt-body{display:flex;flex-direction:column;flex:1;min-width:0}
#db-co .co-opt-title{font-size:16px;letter-spacing:.03em;line-height:1.35}
#db-co .co-opt-amt{font-size:17px;letter-spacing:.03em;line-height:1.35}
#db-co .co-opt-sub{font-size:13px;line-height:1.4;color:var(--co-ink);white-space:nowrap}
#db-co .co-opt-side{display:flex;flex-direction:column;align-items:flex-end;justify-content:space-between;margin-left:auto;padding-right:2px;gap:4px}
#db-co .co-opt-side strong{font-size:17px;font-weight:700;letter-spacing:.02em}
#db-co .co-opt-side span{font-size:13px}
#db-co .co-opt-change{display:inline-block;margin:-16px 0 20px 4px;font-size:13px;color:var(--co-green-text)}
/* Step panels */
#db-co .co-panel[hidden]{display:none}
#db-co .co-pill{background:#fff;border-radius:6px;box-shadow:0 2px 8px rgba(15,34,48,.16);text-align:center;font-size:17px;font-weight:700;letter-spacing:.03em;padding:8px 14px;margin:0 16px 0 0}
#db-co .co-intro{font-size:14px;letter-spacing:.02em;margin:14px 0 30px}
#db-co .co-field{margin-bottom:26px}
#db-co .co-field label{display:block;font-size:14px;letter-spacing:.02em;margin-bottom:12px}
#db-co .co-input-row{display:flex;align-items:center;gap:10px}
#db-co .co-req{color:#e53935;font-size:16px;line-height:1;width:6px}
#db-co .co-input{min-height:38px;flex:1;min-width:0;height:38px;border:1px solid #98a3ab;border-radius:20px;padding:0 16px;font:inherit;font-size:15px;color:var(--co-ink);background:#fff;transition:border-color .15s,box-shadow .15s}
#db-co .co-input:focus{outline:none;border-color:var(--co-green);box-shadow:0 0 0 3px rgba(92,181,49,.25)}
#db-co .co-field label{font-weight:400;color:var(--co-ink)}
#db-co .co-input[aria-invalid="true"]{border-color:var(--co-err)}
#db-co .co-field-err{display:none;color:var(--co-err);font-size:13px;margin:6px 0 0 16px}
#db-co .co-field-err.is-on{display:block}
#db-co .co-actions{display:flex;align-items:center;justify-content:center;gap:20px;margin-top:22px;flex-wrap:wrap}
#db-co .co-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-width:148px;min-height:44px;padding:10px 28px;border:0;border-radius:22px;background:var(--co-green-btn);color:#fff;font:inherit;font-size:16px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;cursor:pointer;transition:background .15s,transform .1s}
#db-co .co-btn:hover:not(:disabled){background:#2f8a17}
#db-co .co-btn:active:not(:disabled){transform:scale(.98)}
#db-co .co-btn:focus-visible{outline:3px solid var(--co-navy);outline-offset:3px}
#db-co .co-btn:disabled{background:#8a949b;cursor:not-allowed}
#db-co .co-btn svg{width:15px;height:15px;fill:currentColor}
#db-co .co-btn-wide{width:100%}
#db-co .co-back{background:none;border:0;font:inherit;font-size:14px;color:var(--co-green-text);text-decoration:underline;cursor:pointer;padding:10px 4px;min-height:44px}
#db-co .co-back:focus-visible{outline:3px solid var(--co-green-text);outline-offset:2px;border-radius:4px}
/* Review */
#db-co .co-review{border:1px solid #dde3e7;border-radius:8px;margin-top:20px}
#db-co .co-review-row{display:flex;justify-content:space-between;gap:16px;padding:12px 16px;border-bottom:1px solid #e8ecef;font-size:14px}
#db-co .co-review-row:last-child{border-bottom:0}
#db-co .co-review-row dt{color:var(--co-muted)}
#db-co .co-review-row dd{font-weight:700;text-align:right;word-break:break-word}
#db-co .co-review-head{display:flex;justify-content:space-between;align-items:center;margin-top:24px}
#db-co .co-review-head h3{font-size:16px;letter-spacing:.03em}
#db-co .co-edit{background:none;border:0;font:inherit;font-size:14px;color:var(--co-green-text);text-decoration:underline;cursor:pointer;min-height:32px}
#db-co .co-terms{font-size:13px;line-height:1.5;background:#fff8e6;border:1px solid #f0d58a;border-radius:8px;padding:12px 14px;margin:20px 0 12px}
#db-co .co-agree{display:flex;gap:10px;align-items:flex-start;font-size:13px;line-height:1.5;cursor:pointer}
#db-co .co-agree input{margin-top:3px;flex:0 0 auto;width:16px;height:16px;accent-color:var(--co-green-btn)}
/* Payment */
#db-co .co-due{display:flex;justify-content:space-between;align-items:baseline;gap:12px;margin:20px 0 16px;font-size:15px}
#db-co .co-due strong{font-size:22px;color:var(--co-green-text)}
#db-co .co-pe{background:#fff;border:1px solid #dde3e7;border-radius:8px;padding:16px;margin-bottom:16px;min-height:80px}
#db-co .co-err{display:none;background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:11px 15px;color:#991b1b;font-size:14px;margin-bottom:14px}
#db-co .co-trust{display:flex;gap:20px;justify-content:center;flex-wrap:wrap;margin-top:18px}
#db-co .co-trust span{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--co-muted)}
#db-co .co-trust svg{width:13px;height:13px;fill:var(--co-muted);flex-shrink:0}
#db-co .co-notice{text-align:center;padding:40px 8px;color:#374151;font-size:15px;line-height:1.6}
#db-co .co-notice a{color:var(--co-green-text);text-decoration:underline}
/* Order summary */
#db-co .co-sum{background:#fff;border-radius:4px;box-shadow:0 1px 6px rgba(15,34,48,.08);padding:20px 14px 14px}
#db-co .co-sum h2{font-size:17px;font-weight:400;letter-spacing:.03em;margin-bottom:12px}
#db-co .co-sum-item{display:flex;align-items:center;gap:10px}
#db-co .co-sum-item .co-ico{flex:0 0 30px;width:30px;height:30px}
#db-co .co-sum-domain{font-size:17px;font-weight:700;letter-spacing:.02em;word-break:break-all;flex:1}
#db-co .co-remove{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;margin-right:-6px;border-radius:50%;color:#8a949b;text-decoration:none}
#db-co .co-remove:hover,#db-co .co-remove:focus-visible{color:var(--co-err)}
#db-co .co-remove svg{width:20px;height:20px}
#db-co .co-sum-price{display:flex;align-items:center;gap:10px;margin-top:12px;flex-wrap:wrap}
#db-co .co-sum-price .co-ico{flex:0 0 32px;width:32px;height:32px}
#db-co .co-sum-amt{font-size:22px;color:var(--co-green-text);letter-spacing:.02em}
#db-co .co-sum-note{font-size:15px;color:var(--co-ink);margin-left:auto;padding-right:24px}
#db-co .co-sum-plan{font-size:13px;color:var(--co-muted);margin-top:10px;line-height:1.5}
#db-co .co-cards{display:flex;justify-content:center;gap:10px;flex-wrap:wrap}
#db-co .co-cards svg{width:46px;height:32px;border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,.18)}
/* Next steps */
#db-co .co-next{padding-top:16px}
#db-co .co-next h2{font-size:21px;font-weight:400;text-align:center;letter-spacing:.02em}
#db-co .co-next h2::after{content:"";display:block;width:60px;height:2px;background:var(--co-ink);margin:16px auto 28px}
#db-co .co-next ul{list-style:none;display:flex;flex-direction:column;gap:18px}
#db-co .co-next li{display:flex;align-items:center;gap:24px;font-size:16px;letter-spacing:.02em;line-height:1.45}
#db-co .co-next .co-bubble{flex:0 0 84px;width:84px;height:84px;border-radius:50%;background:#fff;box-shadow:0 2px 10px rgba(15,34,48,.08);display:flex;align-items:center;justify-content:center}
#db-co .co-next .co-bubble svg{width:50px;height:50px}
/* Footer */
@media (max-width:1180px){
	#db-co .co-grid{grid-template-columns:1fr}
	#db-co .co-card{max-width:840px;width:100%;margin:0 auto}
	#db-co .co-next{max-width:840px;width:100%;margin:0 auto}
	#db-co .co-next ul{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
	#db-co .co-next li{flex-direction:column;text-align:center;gap:12px}
}
@media (max-width:860px){
	#db-co .co-steps-wrap{margin:32px auto 28px}
	#db-co .co-steps{--w:160px}
	#db-co .co-step-label{font-size:15px}
	#db-co .co-card{grid-template-columns:1fr}
	#db-co .co-right{order:-1;padding:16px}
	#db-co .co-cards{display:none}
	#db-co .co-left{padding:20px 20px 36px}
}
@media (max-width:600px){
	#db-co .co-wrap{padding:0 16px}
	#db-co .co-steps-wrap{padding:0 12px}
	#db-co .co-steps{--w:96px}
	#db-co .co-step-label{font-size:12px;letter-spacing:0;line-height:1.3;white-space:normal}
	#db-co .co-toggle{grid-template-columns:1fr;margin-left:0}
	#db-co .co-pill{margin-right:0;font-size:15px}
	#db-co .co-next ul{grid-template-columns:1fr}
	#db-co .co-next li{flex-direction:row;text-align:left}
	#db-co .co-next .co-bubble{flex-basis:64px;width:64px;height:64px}
	#db-co .co-next .co-bubble svg{width:38px;height:38px}
	#db-co .co-sum-note{padding-right:0}
}
@media (prefers-reduced-motion:reduce){
	#db-co *{transition:none!important;scroll-behavior:auto!important}
}
</style>
			<?php
		}, 120 );

		get_header();
		?>
<div id="db-co" class="db-co">
	<nav class="co-steps-wrap" aria-label="Checkout progress"><ol class="co-steps" id="co-steps">
		<li class="co-step is-active" data-step="1"><button type="button" class="co-step-btn" aria-current="step" disabled><span class="co-step-num">1</span><span class="co-step-label">Billing Information</span></button></li>
		<li class="co-step" data-step="2"><button type="button" class="co-step-btn" disabled><span class="co-step-num">2</span><span class="co-step-label">Order Confirmation</span></button></li>
		<li class="co-step" data-step="3"><button type="button" class="co-step-btn" disabled><span class="co-step-num">3</span><span class="co-step-label">Payment</span></button></li>
	</ol></nav>

	<div class="co-wrap">
		<div class="co-grid">
			<div class="co-card">
				<section class="co-left" aria-label="Checkout">
					<div class="co-toggle" role="group" aria-label="Payment option">
						<a class="co-opt<?php echo $is_plan ? '' : ' is-on'; ?>" href="<?php echo esc_url( $full_url ); ?>"<?php echo $is_plan ? '' : ' aria-current="true"'; ?>>
							<span class="co-opt-tick"><?php echo $check_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
							<span class="co-opt-body"><span class="co-opt-title">Pay in full</span><span class="co-opt-amt"><?php echo esc_html( $price_display ); ?></span></span>
						</a>
<?php if ( $alt_months > 0 ) : ?>
						<a class="co-opt<?php echo $is_plan ? ' is-on' : ''; ?>" href="<?php echo esc_url( $plan_url ); ?>"<?php echo $is_plan ? ' aria-current="true"' : ''; ?>>
							<span class="co-opt-tick"><?php echo $check_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
							<span class="co-opt-body"><span class="co-opt-title">Pay less up front</span><span class="co-opt-sub">Monthly: <?php echo esc_html( '$' . number_format( $alt_first, 2 ) ); ?> (<?php echo esc_html( (string) $alt_months ); ?> Payments)</span></span>
							<span class="co-opt-side"><strong><?php echo esc_html( '$' . number_format( $alt_first, 2 ) ); ?></strong><span>Now</span></span>
						</a>
<?php endif; ?>
					</div>
<?php if ( $is_plan && '' !== $terms_url ) : ?>
					<a class="co-opt-change" href="<?php echo esc_url( $terms_url ); ?>">Change plan length (<?php echo esc_html( $alt_months . ' months, ' . $rate_label ); ?>)</a>
<?php endif; ?>

<?php if ( $not_configured ) : ?>
					<div class="co-notice" role="status">
						<p>Our payment system is being configured.</p>
						<p style="margin-top:8px">Please <a href="mailto:<?php echo esc_attr( $support_email ); ?>">contact support</a> to complete your purchase.</p>
					</div>
<?php elseif ( $has_error ) : ?>
					<div class="co-notice" role="alert">
						<p><?php echo esc_html( $pi['error'] ); ?></p>
						<p style="margin-top:8px;font-size:.9rem">Please try again or <a href="mailto:<?php echo esc_attr( $support_email ); ?>">contact support</a>.</p>
					</div>
<?php else : ?>
					<!-- Step 1: Billing information -->
					<div class="co-panel" id="co-panel-1" data-panel="1">
						<h1 class="co-pill">Please fill-in your billing information</h1>
						<p class="co-intro">You will receive an invoice once your payment is processed.</p>
						<form id="co-billing" novalidate>
							<div class="co-field">
								<label for="co-name">Full Name</label>
								<div class="co-input-row"><input class="co-input" type="text" id="co-name" name="name" autocomplete="name" required aria-required="true" aria-describedby="co-name-err" maxlength="120"><span class="co-req" aria-hidden="true">*</span></div>
								<p class="co-field-err" id="co-name-err">Please enter your full name.</p>
							</div>
							<div class="co-field">
								<label for="co-email">Email Id</label>
								<div class="co-input-row"><input class="co-input" type="email" id="co-email" name="email" autocomplete="email" inputmode="email" required aria-required="true" aria-describedby="co-email-err" maxlength="190"><span class="co-req" aria-hidden="true">*</span></div>
								<p class="co-field-err" id="co-email-err">Please enter a valid email address.</p>
							</div>
							<div class="co-field">
								<label for="co-phone">Phone #</label>
								<div class="co-input-row"><input class="co-input" type="tel" id="co-phone" name="phone" autocomplete="tel" inputmode="tel" required aria-required="true" aria-describedby="co-phone-err" maxlength="30"><span class="co-req" aria-hidden="true">*</span></div>
								<p class="co-field-err" id="co-phone-err">Please enter a valid phone number.</p>
							</div>
							<div class="co-field co-remind"><label class="co-agree" for="co-remind"><input type="checkbox" id="co-remind" name="remind" value="1"> <span>Email me one reminder if I do not finish my purchase. Optional.</span></label></div>
							<div class="co-actions"><button type="submit" class="co-btn">Next</button></div>
						</form>
					</div>

					<!-- Step 2: Order confirmation -->
					<div class="co-panel" id="co-panel-2" data-panel="2" hidden>
						<h2 class="co-pill" tabindex="-1">Please confirm your order</h2>
						<div class="co-review-head"><h3>Billing information</h3><button type="button" class="co-edit" data-goto="1">Edit</button></div>
						<dl class="co-review">
							<div class="co-review-row"><dt>Full Name</dt><dd id="co-r-name"></dd></div>
							<div class="co-review-row"><dt>Email</dt><dd id="co-r-email"></dd></div>
							<div class="co-review-row"><dt>Phone</dt><dd id="co-r-phone"></dd></div>
						</dl>
						<div class="co-review-head"><h3>Order</h3></div>
						<dl class="co-review">
							<div class="co-review-row"><dt>Domain</dt><dd><?php echo esc_html( $domain ); ?></dd></div>
							<div class="co-review-row"><dt>Payment</dt><dd><?php echo esc_html( $is_plan ? $months . ' monthly payments (' . $rate_label . ')' : 'Pay in full' ); ?></dd></div>
<?php if ( $is_plan ) : ?>
							<div class="co-review-row"><dt>Plan total</dt><dd><?php echo esc_html( '$' . number_format( $alt_total, 2 ) ); ?></dd></div>
<?php endif; ?>
							<div class="co-review-row"><dt>Due today</dt><dd><?php echo esc_html( $due_today_display ); ?></dd></div>
						</dl>
<?php if ( $is_plan ) : ?>
						<?php echo function_exists( 'db_plan_preview_html' ) ? wp_kses( db_plan_preview_html( $price, $months ), array( 'p' => array( 'class' => array(), 'style' => array() ), 'strong' => array(), 'table' => array( 'border' => array(), 'cellpadding' => array(), 'cellspacing' => array(), 'width' => array(), 'style' => array() ), 'tr' => array(), 'th' => array( 'align' => array(), 'style' => array() ), 'td' => array( 'align' => array(), 'style' => array() ) ) ) : ''; // phpcs:ignore ?>
						<p class="co-terms"><?php echo wp_kses( db_plan_terms_html(), [ 'strong' => [], 'em' => [] ] ); ?></p>
						<label class="co-agree" for="co-agree">
							<input type="checkbox" id="co-agree" required aria-describedby="co-agree-err">
							<span>I agree to the payment plan terms above: the domain transfers to me only after the final installment, and if a payment stays unpaid <?php echo esc_html( (string) $grace_days ); ?> days after its due date the plan ends and installments already paid are not refunded.</span>
						</label>
						<p class="co-field-err" id="co-agree-err" style="margin-left:26px">Please confirm you agree to the payment plan terms.</p>
<?php endif; ?>
						<div class="co-actions">
							<button type="button" class="co-back" data-goto="1">&larr; Back</button>
							<button type="button" class="co-btn" id="co-confirm">Next</button>
						</div>
					</div>

					<!-- Step 3: Payment -->
					<div class="co-panel" id="co-panel-3" data-panel="3" hidden>
						<h2 class="co-pill" tabindex="-1">Enter your payment details</h2>
						<div class="co-due"><span>Amount due today</span><strong><?php echo esc_html( $due_today_display ); ?></strong></div>
						<div id="co-err" class="co-err" role="alert"></div>
						<form id="co-pay" novalidate>
							<div class="co-pe"><div id="db-stripe-payment-element"></div></div>
							<button type="submit" id="co-pay-btn" class="co-btn co-btn-wide"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zM9 6c0-1.66 1.34-3 3-3s3 1.34 3 3v2H9V6z"/></svg><span id="co-pay-label"><?php echo esc_html( $btn_label ); ?></span></button>
						</form>
						<div class="co-actions"><button type="button" class="co-back" data-goto="2">&larr; Back to order confirmation</button></div>
						<div class="co-trust">
							<span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5z"/></svg>SSL Secured</span>
							<span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 4H4c-1.11 0-2 .89-2 2v12c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V6c0-1.11-.89-2-2-2zm0 14H4v-6h16v6zm0-10H4V6h16v2z"/></svg>Secure payment</span>
							<span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z"/></svg>24-hr Support</span>
						</div>
					</div>
<?php endif; ?>
				</section>

				<aside class="co-right" aria-label="Order summary">
					<div class="co-sum">
						<h2>Your Order Summary - 1 Item</h2>
						<div class="co-sum-item">
							<svg class="co-ico" viewBox="0 0 40 40" aria-hidden="true"><circle cx="20" cy="22" r="15" fill="#e8f5df"/><path d="M8 22a12 12 0 0 1 24 0M8 22a12 12 0 0 0 24 0M20 10c-4 4-4 20 0 24M20 10c4 4 4 20 0 24M8 22h24" fill="none" stroke="#5cb531" stroke-width="1.6"/><rect x="2" y="3" width="36" height="10" rx="2" fill="#fff"/><text x="20" y="11.5" text-anchor="middle" font-family="Arial,sans-serif" font-size="9" font-weight="700" fill="#1d2b36">www</text></svg>
							<span class="co-sum-domain"><?php echo esc_html( $domain ); ?></span>
							<a class="co-remove" href="<?php echo esc_url( home_url( '/all-domains/' ) ); ?>" aria-label="Remove <?php echo esc_attr( $domain ); ?> and browse other domains"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8.5 8.5l7 7M15.5 8.5l-7 7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg></a>
						</div>
						<div class="co-sum-price">
							<svg class="co-ico" viewBox="0 0 40 40" aria-hidden="true"><path d="M20 2l4 3.5 5-1 1.5 5 5 1.5-1 5L38 20l-3.5 4 1 5-5 1.5-1.5 5-5-1L20 38l-4-3.5-5 1-1.5-5-5-1.5 1-5L2 20l3.5-4-1-5 5-1.5 1.5-5 5 1z" fill="#5cb531"/><circle cx="20" cy="20" r="11" fill="#fff"/><circle cx="20" cy="20" r="9" fill="none" stroke="#2f8a17" stroke-width="1.5"/><text x="20" y="25" text-anchor="middle" font-family="Arial,sans-serif" font-size="14" font-weight="700" fill="#2f8a17">$</text></svg>
							<span class="co-sum-amt"><?php echo esc_html( $price_display ); ?></span>
							<span class="co-sum-note">(Excl. GST)</span>
						</div>
<?php if ( $is_plan ) : ?>
						<p class="co-sum-plan"><?php echo esc_html( sprintf( '%d monthly payments, %s. %s due today, plan total $%s.', $months, $rate_label, $due_today_display, number_format( $alt_total, 2 ) ) ); ?></p>
<?php endif; ?>
					</div>
					<div class="co-cards" aria-label="Accepted cards">
						<svg viewBox="0 0 46 32" role="img" aria-label="Mastercard"><rect width="46" height="32" fill="#1a4a8c"/><circle cx="19" cy="16" r="8" fill="#eb001b"/><circle cx="27" cy="16" r="8" fill="#f79e1b" fill-opacity=".9"/></svg>
						<svg viewBox="0 0 46 32" role="img" aria-label="Visa"><rect width="46" height="32" fill="#fff"/><text x="23" y="21" text-anchor="middle" font-family="Arial,sans-serif" font-size="13" font-weight="900" font-style="italic" fill="#1a1f71">VISA</text></svg>
						<svg viewBox="0 0 46 32" role="img" aria-label="American Express"><rect width="46" height="32" fill="#2e77bb"/><text x="23" y="14" text-anchor="middle" font-family="Arial,sans-serif" font-size="6.5" font-weight="700" fill="#fff">AMERICAN</text><text x="23" y="22" text-anchor="middle" font-family="Arial,sans-serif" font-size="6.5" font-weight="700" fill="#fff">EXPRESS</text></svg>
						<svg viewBox="0 0 46 32" role="img" aria-label="Discover"><rect width="46" height="32" fill="#fff"/><text x="4" y="18" font-family="Arial,sans-serif" font-size="6.6" font-weight="700" fill="#231f20">DISC</text><circle cx="24.6" cy="15.8" r="2.9" fill="#f58220"/><text x="27.8" y="18" font-family="Arial,sans-serif" font-size="6.6" font-weight="700" fill="#231f20">VER</text><rect y="26" width="46" height="6" fill="#f58220"/></svg>
					</div>
				</aside>
			</div>

			<aside class="co-next" aria-labelledby="co-next-h">
				<h2 id="co-next-h">What are the next steps?</h2>
				<ul>
					<li><span class="co-bubble"><svg viewBox="0 0 50 50" aria-hidden="true"><rect x="5" y="6" width="36" height="26" rx="3" fill="#e3f0fa" stroke="#1d4f7a" stroke-width="2"/><rect x="5" y="11" width="36" height="5" fill="#1d4f7a"/><circle cx="33" cy="24" r="3" fill="#f57c00"/><circle cx="37" cy="24" r="3" fill="#ffb300" fill-opacity=".85"/><rect x="18" y="27" width="20" height="17" rx="3" fill="#ffcc4d" stroke="#1d4f7a" stroke-width="2"/><path d="M22 27v-4a6 6 0 0 1 12 0v4" fill="none" stroke="#1d4f7a" stroke-width="2"/><circle cx="28" cy="35" r="2" fill="#1d4f7a"/></svg></span><span>Your payment is securely processed</span></li>
					<li><span class="co-bubble"><svg viewBox="0 0 50 50" aria-hidden="true"><circle cx="25" cy="25" r="20" fill="#e3edf5"/><path d="M5 25h40M25 5c-7 6-7 34 0 40M25 5c7 6 7 34 0 40M9 14h32M9 36h32" fill="none" stroke="#b9c9d6" stroke-width="1.5"/><rect x="4" y="18" width="42" height="14" rx="2" fill="#ffd54f"/><text x="25" y="29" text-anchor="middle" font-family="Arial,sans-serif" font-size="11" font-weight="900" fill="#1d4f7a">www</text></svg></span><span>The domain is in hold &amp; secured for you</span></li>
					<li><span class="co-bubble"><svg viewBox="0 0 50 50" aria-hidden="true"><rect x="27" y="5" width="15" height="19" rx="2" fill="#fff3d6" stroke="#1d4f7a" stroke-width="2"/><path d="M31 11h7M31 15h7M31 19h5" stroke="#f57c00" stroke-width="1.8"/><rect x="8" y="26" width="15" height="19" rx="2" fill="#dff3e4" stroke="#1d4f7a" stroke-width="2"/><path d="M12 32h7M12 36h7M12 40h5" stroke="#2e7d32" stroke-width="1.8"/><path d="M20 9c-8 0-12 5-12 12" fill="none" stroke="#1d4f7a" stroke-width="2"/><path d="M5 18l3 4 3-4" fill="none" stroke="#1d4f7a" stroke-width="2"/><path d="M30 42c8 0 12-5 12-12" fill="none" stroke="#1d4f7a" stroke-width="2"/><path d="M39 33l3-4 3 4" fill="none" stroke="#1d4f7a" stroke-width="2"/></svg></span><span>We give you instruction on the domain transfer</span></li>
				</ul>
			</aside>
		</div>
	</div>
</div><!-- #db-co -->

<?php if ( $can_pay ) : ?>
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
	var current  = 1;
	var buyer    = { name: '', email: '', phone: '' };
	var stripe   = null, elements = null, payEl = null;

	var steps    = document.querySelectorAll('#co-steps .co-step');
	var panels   = document.querySelectorAll('[data-panel]');
	var billing  = document.getElementById('co-billing');
	var nameInp  = document.getElementById('co-name');
	var emailInp = document.getElementById('co-email');
	var remindInp = document.getElementById('co-remind');
	var phoneInp = document.getElementById('co-phone');
	var agreeInp = document.getElementById('co-agree');
	var payForm  = document.getElementById('co-pay');
	var payBtn   = document.getElementById('co-pay-btn');
	var payLabel = document.getElementById('co-pay-label');
	var errDiv   = document.getElementById('co-err');
	var CHECK    = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4z"/></svg>';

	function fieldError(input, errId, bad) {
		input.setAttribute('aria-invalid', bad ? 'true' : 'false');
		document.getElementById(errId).classList.toggle('is-on', bad);
		return bad;
	}

	function showStep(n) {
		current = n;
		document.getElementById('co-steps').style.setProperty('--p', n === 1 ? '.5' : '1');
		Array.prototype.forEach.call(panels, function (p) {
			p.hidden = parseInt(p.getAttribute('data-panel'), 10) !== n;
		});
		Array.prototype.forEach.call(steps, function (li) {
			var s   = parseInt(li.getAttribute('data-step'), 10);
			var btn = li.querySelector('.co-step-btn');
			var num = li.querySelector('.co-step-num');
			li.classList.toggle('is-active', s === n);
			li.classList.toggle('is-done', s < n);
			num.innerHTML = s < n ? CHECK : String(s);
			btn.disabled = s >= n;
			if (s === n) { btn.setAttribute('aria-current', 'step'); } else { btn.removeAttribute('aria-current'); }
			btn.setAttribute('aria-label', (s < n ? 'Completed: ' : '') + li.querySelector('.co-step-label').textContent);
		});
		if (n === 3) { mountPayment(); }
		var focusTarget = document.querySelector('#co-panel-' + n + ' .co-pill');
		if (n > 1 && focusTarget) { focusTarget.focus({ preventScroll: true }); }
		// Keep the steps bar in view below the site's fixed header bar.
		var offset = 16;
		Array.prototype.forEach.call(document.querySelectorAll('#masthead, .site-header .domain-search, #wpadminbar'), function (el) {
			var cs = window.getComputedStyle(el);
			if ((cs.position === 'fixed' || cs.position === 'sticky') && cs.display !== 'none') {
				offset = Math.max(offset, el.getBoundingClientRect().bottom + 16);
			}
		});
		var top = document.getElementById('co-steps').getBoundingClientRect().top + window.pageYOffset - offset;
		if (window.pageYOffset > top) { window.scrollTo(0, top); }
	}

	Array.prototype.forEach.call(steps, function (li) {
		li.querySelector('.co-step-btn').addEventListener('click', function () {
			var s = parseInt(li.getAttribute('data-step'), 10);
			if (s < current) { showStep(s); }
		});
	});
	Array.prototype.forEach.call(document.querySelectorAll('[data-goto]'), function (b) {
		b.addEventListener('click', function () { showStep(parseInt(b.getAttribute('data-goto'), 10)); });
	});

	// Step 1 → 2
	billing.addEventListener('submit', function (e) {
		e.preventDefault();
		var name  = (nameInp.value || '').trim().replace(/\s+/g, ' ');
		var email = (emailInp.value || '').trim();
		var phone = (phoneInp.value || '').trim();
		var digits = phone.replace(/\D/g, '');
		var bad = [
			fieldError(nameInp, 'co-name-err', name.length < 2) ? nameInp : null,
			fieldError(emailInp, 'co-email-err', !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) ? emailInp : null,
			fieldError(phoneInp, 'co-phone-err', digits.length < 7 || digits.length > 15 || !/^[+()\d\s.\-]+$/.test(phone)) ? phoneInp : null
		].filter(Boolean);
		if (bad.length) { bad[0].focus(); return; }
		buyer = { name: name, email: email, phone: phone };
		document.getElementById('co-r-name').textContent  = name;
		document.getElementById('co-r-email').textContent = email;
		document.getElementById('co-r-phone').textContent = phone;
		showStep(2);
	});

	// Step 2 → 3
	document.getElementById('co-confirm').addEventListener('click', function () {
		if (agreeInp && fieldError(agreeInp, 'co-agree-err', !agreeInp.checked)) { agreeInp.focus(); return; }
		showStep(3);
	});
	if (agreeInp) {
		agreeInp.addEventListener('change', function () { if (agreeInp.checked) { fieldError(agreeInp, 'co-agree-err', false); } });
	}

	function waitStripe(cb) {
		if (typeof Stripe !== 'undefined') { cb(); return; }
		var tries = 0;
		var iv = setInterval(function () {
			tries++;
			if (typeof Stripe !== 'undefined') { clearInterval(iv); cb(); }
			else if (tries > 150) { clearInterval(iv); showErr('The payment form could not load. Please refresh the page or contact support.'); }
		}, 80);
	}

	function mountPayment() {
		if (payEl) { return; }
		waitStripe(function () {
			if (payEl) { return; }
			stripe   = Stripe(window.DB_PUB_KEY);
			elements = stripe.elements({ clientSecret: window.DB_PI_SECRET, appearance: { theme: 'stripe', variables: { borderRadius: '8px', colorPrimary: '#3c9c1e', colorText: '#1d2b36', colorDanger: '#c62828', fontFamily: 'Lato, -apple-system, Segoe UI, Roboto, Arial, sans-serif' } }, fonts: [{ cssSrc: 'https://fonts.googleapis.com/css2?family=Lato:wght@400;700&display=swap' }] });
			payEl    = elements.create('payment', { defaultValues: { billingDetails: { name: buyer.name, email: buyer.email, phone: buyer.phone } } });
			payEl.mount('#db-stripe-payment-element');
		});
	}

	function showErr(msg) {
		errDiv.textContent = msg;
		errDiv.style.display = 'block';
		payBtn.disabled = false;
		payLabel.textContent = window.DB_BTN_LABEL;
	}

	// Step 3: pay
	payForm.addEventListener('submit', function (e) {
		e.preventDefault();
		if (!stripe || !elements) { showErr('The payment form is still loading. Please wait a moment and try again.'); return; }
		if (agreeInp && !agreeInp.checked) { showStep(2); return; }

		payBtn.disabled = true;
		payLabel.textContent = 'Processing…';
		errDiv.style.display = 'none';

		// Attach the buyer's email (receipt_email) and name/phone (metadata)
		// to the PaymentIntent before confirming — this is what lets the
		// sale-complete and payment-failed emails reach the actual buyer.
		fetch(window.DB_EMAIL_REST, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({ client_secret: window.DB_PI_SECRET, email: buyer.email, name: buyer.name, phone: buyer.phone, terms_accepted: !!(agreeInp && agreeInp.checked), remind: !!(remindInp && remindInp.checked) })
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
					payment_method_data: { billing_details: { name: buyer.name, email: buyer.email, phone: buyer.phone } }
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

	showStep(1);
}());
</script>
<?php endif; ?>
		<?php
		get_footer();
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
			'name'           => [ 'required' => false, 'type' => 'string' ],
			'phone'          => [ 'required' => false, 'type' => 'string' ],
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
	function db_stripe_receipt_body( $email, $terms_accepted, $name = '', $phone = '' ) {
		$body = [ 'receipt_email' => $email ];
		// Billing details from checkout step 1, kept with the order so the
		// sale can be followed up by phone. Stripe caps metadata values at 500.
		$name  = substr( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $name ) ) ), 0, 120 );
		$phone = substr( preg_replace( '/[^0-9+().\-\s]/', '', (string) $phone ), 0, 30 );
		if ( '' !== $name ) {
			$body['metadata[buyer_name]'] = $name;
		}
		if ( '' !== trim( $phone ) ) {
			$body['metadata[buyer_phone]'] = trim( $phone );
		}

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

		// One reminder, only if the buyer ticked the box (see db-sales-ops-block.php).
		if ( $request->get_param( 'remind' ) && function_exists( 'db_ops_reminder_opt_in' ) ) {
			db_ops_reminder_opt_in( $pi_id, $email );
		}

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
			'body'      => db_stripe_receipt_body(
				$email,
				(bool) $request->get_param( 'terms_accepted' ),
				sanitize_text_field( (string) $request->get_param( 'name' ) ),
				sanitize_text_field( (string) $request->get_param( 'phone' ) )
			),
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
		if ( function_exists( 'db_mail_send' ) ) {
			if ( $customer_email ) {
				db_mail_send( 'purchase_full', $customer_email, [ 'domain' => $domain, 'amount' => $amount_display, 'date' => wp_date( 'F j, Y' ), 'type' => $type_label ], 'sales@domainbrothers.com' );
			}
			db_mail_send( 'admin_sale', $admin_email, [ 'domain' => $domain, 'amount' => $amount_display, 'type' => $type_label, 'customer' => $customer_email ?: 'Unknown', 'time' => wp_date( 'Y-m-d H:i:s T' ), 'admin_url' => admin_url() ] );
			return;
		}

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

		if ( function_exists( 'db_mail_send' ) ) {
			if ( $customer_email ) {
				if ( 1 === $n ) {
					$sched = function_exists( 'db_plan_schedule_html' ) ? db_plan_schedule_html( db_plan_schedule( db_plan_get( $domain ) ) ) : '';
					db_mail_send( 'plan_started', $customer_email, [
						'domain' => $domain, 'amount' => $paid_fmt, 'months' => (string) $months, 'rate' => $rate_label, 'total' => '$' . number_format( $total, 2 ),
						'schedule_html' => $sched,
						'terms_html' => function_exists( 'db_plan_terms_html' ) ? '<p style="font-size:13px">' . db_plan_terms_html() . '</p>' : '',
						'reminder_days' => (string) ( defined( 'DB_PLAN_REMIND_DAYS' ) ? DB_PLAN_REMIND_DAYS : 3 ),
					], 'sales@domainbrothers.com' );
				} else {
					db_mail_send( 'plan_installment', $customer_email, [ 'domain' => $domain, 'number' => (string) $n, 'months' => (string) $months, 'amount' => $paid_fmt, 'remaining' => (string) max( 0, $months - $n ),
						'schedule_html' => function_exists( 'db_plan_schedule_html' ) ? db_plan_schedule_html( db_plan_schedule( db_plan_get( $domain ) ), true ) : '',
						'terms_html' => function_exists( 'db_plan_terms_html' ) ? '<p style="font-size:13px">' . db_plan_terms_html() . '</p>' : '',
					], 'sales@domainbrothers.com' );
				}
			}
			db_mail_send( 'admin_plan_payment', $admin_email, [
				'domain' => $domain, 'headline' => 1 === $n ? 'Plan started' : 'Payment ' . $n . ' of ' . $months, 'amount' => $paid_fmt,
				'plan' => $rate_label . ', total $' . number_format( $total, 2 ), 'customer' => $customer_email ?: 'Unknown', 'time' => wp_date( 'Y-m-d H:i:s T' ),
				'subject_line' => 1 === $n ? sprintf( 'PLAN STARTED: %s — %d months, %s', $domain, $months, $rate_label ) : sprintf( 'PLAN PAYMENT %d/%d: %s (%s)', $n, $months, $domain, $paid_fmt ),
			] );
			return;
		}

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
				$body .= '<p>Remaining payments are charged automatically to the card you used. You can use the domain during the plan &mdash; reply to this email and we will point its DNS to your hosting. Ownership transfers to you once the final payment clears; until then the domain stays with Domain Brothers.</p>';
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

		if ( function_exists( 'db_mail_send' ) ) {
			if ( $customer_email ) {
				db_mail_send( 'payment_failed', $customer_email, [ 'domain' => $domain, 'amount' => $amount_display, 'update_url' => home_url( '/' ) ], 'sales@domainbrothers.com' );
			}
			db_mail_send( 'admin_payment_failed', $admin_email, [ 'domain' => $domain, 'amount' => $amount_display, 'customer' => $customer_email ?: 'Unknown', 'time' => wp_date( 'Y-m-d H:i:s T' ), 'admin_url' => admin_url() ] );
			return;
		}

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
