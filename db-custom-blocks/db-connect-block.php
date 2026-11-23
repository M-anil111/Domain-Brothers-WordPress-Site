<?php
/**
 * Stripe Connect: seller onboarding, platform products, a storefront and destination charges.
 *
 * What this file does, in the order money moves:
 *
 *  1. Loads the official Stripe PHP SDK (bundled in vendor/stripe-php, v23.1.0, which pins the
 *     API version 2026-11-18.endive itself, so no version is set here).
 *  2. Builds one Stripe client (\Stripe\StripeClient) from the site's Stripe secret key. Every
 *     Stripe request in this file goes through db_connect_client().
 *  3. Creates a connected account for a seller with the V2 Accounts API. The platform (Domain
 *     Brothers) sets prices and collects fees and is responsible for losses; the seller only
 *     receives transfers ("recipient" configuration, stripe_transfers capability).
 *     The seller to account mapping is stored in the sellers table (connect_test / connect_live,
 *     one column per Stripe mode because test and live account ids differ).
 *  4. Onboards the seller with a V2 Account Link (Stripe-hosted onboarding). The seller page shows
 *     "Onboard to collect payments" and the onboarding status, read live from the API every time
 *     (never stored).
 *  5. Listens for V2 account events (thin payload) at /wp-json/db/v1/stripe-connect-webhook:
 *     requirements changes and recipient capability changes.
 *  6. Creates products at the platform level (not on the connected account), each carrying the
 *     connected account id in its metadata.
 *  7. Shows a storefront (/connect-store/) with every product and every connected account.
 *  8. Sells with Stripe-hosted Checkout as a destination charge: the customer pays the platform,
 *     the platform keeps an application fee and Stripe transfers the rest to the seller.
 *
 * Settings to fill in (WordPress options, never in code):
 *  - db_stripe_test_sec_key / db_stripe_live_sec_key: the platform secret key (WP Admin > Stripe
 *    Settings). Placeholder check: db_connect_client() returns a WP_Error naming the option.
 *  - db_connect_wh_secret_test / db_connect_wh_secret_live: the signing secret of the thin event
 *    destination. Tools > Stripe Connect > "Create event destination" creates the destination and
 *    saves the secret; or paste it there after creating the destination in the Dashboard.
 *  - db_connect_fee_pct (optional): application fee for products that are not seller domain
 *    listings, in percent. Default 15, the marketplace commission.
 *  - db_connect_store_public (optional): '1' opens the storefront to everyone on production.
 *    On staging it is always open; on production only administrators see it until this is set.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ 1. SDK */

// Another plugin may already have loaded the Stripe SDK; load ours only when it has not.
if ( ! class_exists( '\Stripe\StripeClient' ) && is_readable( __DIR__ . '/vendor/stripe-php/init.php' ) ) {
	require_once __DIR__ . '/vendor/stripe-php/init.php';
}

/* --------------------------------------------------------- 2. Stripe client */

if ( ! function_exists( 'db_connect_mode' ) ) {
	/** 'test' or 'live', the same mode the checkout uses. */
	function db_connect_mode() {
		return function_exists( 'db_stripe_mode' ) ? db_stripe_mode() : 'test';
	}
}

if ( ! function_exists( 'db_connect_client' ) ) {
	/**
	 * The one Stripe client used for every request in this file.
	 *
	 * @return \Stripe\StripeClient|WP_Error
	 */
	function db_connect_client() {
		static $client = null;
		if ( null !== $client ) {
			return $client;
		}
		if ( ! class_exists( '\Stripe\StripeClient' ) ) {
			return new WP_Error( 'db_connect_sdk', 'The Stripe PHP SDK is missing. Upload the plugin again: it ships in db-custom-blocks/vendor/stripe-php.' );
		}
		// PLACEHOLDER: the platform's secret key comes from WP Admin > Stripe Settings.
		// Test mode reads db_stripe_test_sec_key (sk_test_...), live mode db_stripe_live_sec_key (sk_live_...).
		$keys   = function_exists( 'db_stripe_keys' ) ? db_stripe_keys() : array( 'sec' => '' );
		$secret = trim( (string) ( $keys['sec'] ?? '' ) );
		$option = 'test' === db_connect_mode() ? 'db_stripe_test_sec_key' : 'db_stripe_live_sec_key';
		if ( '' === $secret ) {
			return new WP_Error( 'db_connect_no_key', 'The Stripe secret key is not set. Add it in WP Admin > Stripe Settings (option ' . $option . ').' );
		}
		if ( ! preg_match( '/^(sk|rk)_(test|live)_[A-Za-z0-9]+$/', $secret ) ) {
			return new WP_Error( 'db_connect_bad_key', 'The Stripe secret key in ' . $option . ' does not look like a secret key (it should start with sk_' . db_connect_mode() . '_).' );
		}
		// No API version is passed: the SDK uses the version it was built for (2026-11-18.endive).
		$client = new \Stripe\StripeClient( array( 'api_key' => $secret ) );
		return $client;
	}
}

if ( ! function_exists( 'db_connect_call' ) ) {
	/**
	 * Runs one Stripe call and turns any failure into a WP_Error, logged with the module name so
	 * it shows in the PHP error log (and the log manager).
	 *
	 * @param string   $what  Short label for the log.
	 * @param callable $fn    Receives the client, returns the API result.
	 * @return mixed|WP_Error
	 */
	function db_connect_call( $what, callable $fn ) {
		$client = db_connect_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		try {
			return $fn( $client );
		} catch ( \Stripe\Exception\ApiErrorException $e ) {
			error_log( 'db-connect: ' . $what . ' failed: ' . $e->getMessage() . ' (request ' . (string) $e->getRequestId() . ')' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			if ( function_exists( 'db_log' ) ) {
				db_log( 'connect', 'error', $what . ' failed: ' . $e->getMessage(), array( 'request' => (string) $e->getRequestId(), 'code' => (string) $e->getStripeCode() ), 'db_connect_call' );
			}
			return new WP_Error( 'db_connect_api', 'Stripe said: ' . $e->getMessage() );
		} catch ( \Throwable $e ) {
			error_log( 'db-connect: ' . $what . ' failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			if ( function_exists( 'db_log' ) ) {
				db_log( 'connect', 'error', $what . ' failed: ' . $e->getMessage(), array(), 'db_connect_call' );
			}
			return new WP_Error( 'db_connect_error', 'Something went wrong talking to Stripe. Please try again.' );
		}
	}
}

/* ---------------------------------------- 3. Connected accounts (V2 API) */

if ( ! function_exists( 'db_connect_account_id' ) ) {
	/** The seller's connected account id in the current Stripe mode, or ''. */
	function db_connect_account_id( array $seller ) {
		$col = 'test' === db_connect_mode() ? 'connect_test' : 'connect_live';
		return preg_match( '/^acct_[A-Za-z0-9]+$/', (string) ( $seller[ $col ] ?? '' ) ) ? (string) $seller[ $col ] : '';
	}
}

if ( ! function_exists( 'db_connect_seller_by_account' ) ) {
	/** The seller row mapped to a connected account id, or null. */
	function db_connect_seller_by_account( $account_id ) {
		global $wpdb;
		$t = db_seller_table( 'sellers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE connect_test = %s OR connect_live = %s LIMIT 1", $account_id, $account_id ), ARRAY_A );
		return $row ? $row : null;
	}
}

if ( ! function_exists( 'db_connect_create_account' ) ) {
	/**
	 * Creates the seller's connected account and stores the mapping.
	 *
	 * Only the properties below are sent. There is deliberately no top-level `type`
	 * (express/standard/custom): with V2 accounts the dashboard, responsibilities and
	 * configuration describe the account instead.
	 *
	 * @return string|WP_Error The account id.
	 */
	function db_connect_create_account( array $seller ) {
		$existing = db_connect_account_id( $seller );
		if ( '' !== $existing ) {
			return $existing; // one account per seller per mode
		}
		// One create at a time per seller: double clicks used to make two accounts (SL-21).
		$lock = 'db_connect_lock_' . (int) $seller['id'] . '_' . db_connect_mode();
		$held = (int) get_option( $lock, 0 );
		if ( $held && $held < time() - 60 ) {
			delete_option( $lock ); // stale lock from a request that died
		}
		if ( ! add_option( $lock, time(), '', 'no' ) ) {
			return new WP_Error( 'db_connect_busy', 'Your payout account is being set up. Please wait a few seconds and try again.' );
		}
		$fresh = function_exists( 'db_seller_get' ) ? db_seller_get( (string) $seller['email'] ) : null;
		if ( is_array( $fresh ) && '' !== db_connect_account_id( $fresh ) ) {
			delete_option( $lock );
			return db_connect_account_id( $fresh );
		}
		$result = db_connect_create_account_locked( $seller );
		delete_option( $lock );
		return $result;
	}
}

if ( ! function_exists( 'db_connect_create_account_locked' ) ) {
	/** Creates the connected account; call only through db_connect_create_account(), which holds the lock. */
	function db_connect_create_account_locked( array $seller ) {
		$country = strtolower( (string) $seller['country'] );
		if ( 'us' !== $country ) {
			// Stripe does not allow a recipient-only account (stripe_transfers without card payments)
			// for Canada, the other seller country; those sellers keep bank-transfer payouts.
			return new WP_Error( 'db_connect_country', 'Stripe payouts are available to sellers in the United States. Sellers in Canada are paid by bank transfer.' );
		}
		$account = db_connect_call( 'create account', static function ( $stripe ) use ( $seller, $country ) {
			return $stripe->v2->core->accounts->create( array(
				// The name the seller is known by, and where Stripe sends account emails.
				'display_name'  => '' !== trim( (string) $seller['company'] ) ? (string) $seller['company'] : (string) $seller['legal_name'],
				'contact_email' => (string) $seller['email'],
				// The country the seller's business is based in (United States; see above).
				'identity'      => array( 'country' => $country ),
				// Express Dashboard: Stripe hosts the seller's payout settings.
				'dashboard'     => 'express',
				// The platform sets fees and is liable for losses (refunds, disputes).
				'defaults'      => array(
					'responsibilities' => array(
						'fees_collector'   => 'application',
						'losses_collector' => 'application',
					),
				),
				// The seller only receives money: transfers into their Stripe balance.
				'configuration' => array(
					'recipient' => array(
						'capabilities' => array(
							'stripe_balance' => array(
								'stripe_transfers' => array( 'requested' => true ),
							),
						),
					),
				),
			) );
		} );
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		// Store the user (seller) to account mapping in the current mode's column.
		global $wpdb;
		$col = 'test' === db_connect_mode() ? 'connect_test' : 'connect_live';
		$wpdb->update( db_seller_table( 'sellers' ), array( $col => (string) $account->id ), array( 'id' => (int) $seller['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( function_exists( 'db_seller_audit' ) ) {
			db_seller_audit( (int) $seller['id'], '', 'connect_account', (string) $account->id );
		}
		return (string) $account->id;
	}
}

/* ----------------------------------------------------------- 4. Onboarding */

if ( ! function_exists( 'db_connect_account_link' ) ) {
	/**
	 * A fresh Stripe-hosted onboarding link for the account. Links expire quickly, so one is
	 * made each time the seller clicks; the refresh URL makes another if it expires.
	 *
	 * @return string|WP_Error The URL to send the seller to.
	 */
	function db_connect_account_link( $account_id ) {
		$link = db_connect_call( 'account link', static function ( $stripe ) use ( $account_id ) {
			return $stripe->v2->core->accountLinks->create( array(
				'account'  => $account_id,
				'use_case' => array(
					'type'               => 'account_onboarding',
					'account_onboarding' => array(
						// No `configurations` list: API 2026-11-18.endive removed it from account links
						// (Stripe answers "Unknown field"); onboarding covers the configurations the
						// account already requested, here `recipient`.
						// Stripe sends the seller here if the link expired or was used: we make a new one.
						'refresh_url'    => add_query_arg( 'connect', 'refresh', home_url( '/seller/' ) ),
						// And here when they finish (or leave) onboarding.
						'return_url'     => add_query_arg( array( 'view' => 'payouts', 'connect' => 'return', 'accountId' => $account_id ), home_url( '/seller/' ) ),
					),
				),
			) );
		} );
		return is_wp_error( $link ) ? $link : (string) $link->url;
	}
}

if ( ! function_exists( 'db_connect_status' ) ) {
	/**
	 * Onboarding status, always read from the API (never stored), as asked.
	 *
	 * @return array{ready: bool, complete: bool, requirements: string, transfers: string}|WP_Error
	 */
	function db_connect_status( $account_id ) {
		$account = db_connect_call( 'retrieve account', static function ( $stripe ) use ( $account_id ) {
			return $stripe->v2->core->accounts->retrieve( $account_id, array( 'include' => array( 'configuration.recipient', 'requirements' ) ) );
		} );
		if ( is_wp_error( $account ) ) {
			return $account;
		}
		return db_connect_status_from( $account->toArray() );
	}
}

if ( ! function_exists( 'db_connect_status_from' ) ) {
	/**
	 * Pure: the status fields from a V2 account array (tested offline).
	 *
	 * - ready: the stripe_transfers capability is active, so the seller can be paid.
	 * - complete: nothing is currently due or past due in the requirements.
	 */
	function db_connect_status_from( array $a ) {
		$transfers    = (string) ( $a['configuration']['recipient']['capabilities']['stripe_balance']['stripe_transfers']['status'] ?? '' );
		$requirements = (string) ( $a['requirements']['summary']['minimum_deadline']['status'] ?? '' );
		return array(
			'ready'        => 'active' === $transfers,
			'complete'     => 'currently_due' !== $requirements && 'past_due' !== $requirements,
			'requirements' => $requirements,
			'transfers'    => $transfers,
		);
	}
}

if ( ! function_exists( 'db_connect_seller_card' ) ) {
	/** The "Get paid with Stripe" card on the seller's Sales and payouts tab. */
	function db_connect_seller_card( array $seller, $email ) {
		$h   = '<section class="dbsl-card dbsl-sec" id="dbsl-connect" aria-labelledby="dbsl-cn-h"><h2 id="dbsl-cn-h">Get paid with Stripe</h2>';
		$acc = db_connect_account_id( $seller );
		$t   = function_exists( 'db_seller_csrf' ) ? esc_attr( db_seller_csrf( $email ) ) : '';
		$btn = static function ( $label ) use ( $t ) {
			return '<form method="post" action="' . esc_url( home_url( '/seller/' ) ) . '" class="dbsl-actions"><input type="hidden" name="db_connect_action" value="onboard"><input type="hidden" name="t" value="' . $t . '"><button type="submit" class="dbsl-btn">' . esc_html( $label ) . '</button></form>';
		};
		if ( '' === $acc && 'US' !== strtoupper( (string) $seller['country'] ) ) {
			// A recipient-only connected account (transfers without card payments) is not available
			// for Canada, so Canadian sellers are paid by bank transfer as before.
			return $h . '<p>Stripe payouts are available to sellers in the United States. As a seller in Canada, you are paid by bank transfer: we email you to confirm your bank details after your first sale.</p></section>';
		}
		if ( '' === $acc ) {
			return $h . '<p>Connect a Stripe account now so future payouts can go straight to your bank. For now, payouts are sent by bank transfer to the details you give our broker. Stripe checks your identity and bank details; it takes about 5 minutes.</p>' . $btn( 'Onboard to collect payments' ) . '</section>';
		}
		$st = db_connect_status( $acc );
		if ( is_wp_error( $st ) ) {
			return $h . '<p class="dbsl-msg dbsl-msg--warn" role="status">We could not read your Stripe status just now: ' . esc_html( $st->get_error_message() ) . '</p></section>';
		}
		if ( $st['ready'] && $st['complete'] ) {
			return $h . '<p class="dbsl-msg dbsl-msg--ok" role="status"><strong>Stripe account connected.</strong> Payouts are still sent by bank transfer for now; we will tell you before they move to Stripe.</p>'
				. '<p class="dbsl-muted">Stripe account ' . esc_html( $acc ) . '. Change your bank details in the Stripe dashboard from the email Stripe sent you.</p></section>';
		}
		$msg = $st['complete'] ? 'Stripe is reviewing your details. This usually takes a few minutes; this page shows the latest status.' : 'Stripe needs more information before you can be paid.';
		return $h . '<p class="dbsl-msg dbsl-msg--warn" role="status"><strong>Onboarding not finished.</strong> ' . esc_html( $msg ) . '</p>'
			. ( $st['complete'] ? '' : $btn( 'Continue onboarding' ) ) . '</section>';
	}
}

// The onboarding button (POST, with the seller page's CSRF token) and Stripe's refresh link.
add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) || 'seller' !== db_sales_request_path() ) {
		return;
	}
	$is_post = 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['db_connect_action'] ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
	$refresh = isset( $_GET['connect'] ) && 'refresh' === $_GET['connect']; // phpcs:ignore WordPress.Security.NonceVerification
	if ( ! $is_post && ! $refresh ) {
		return;
	}
	nocache_headers();
	$email  = function_exists( 'db_wl_email' ) ? db_wl_email() : '';
	$seller = '' !== $email && function_exists( 'db_seller_get' ) ? db_seller_get( $email ) : null;
	$back   = add_query_arg( 'view', 'payouts', home_url( '/seller/' ) );
	if ( ! $seller || 'active' !== $seller['status'] ) {
		wp_safe_redirect( home_url( '/seller/' ) );
		exit;
	}
	if ( $is_post ) {
		$t = isset( $_POST['t'] ) ? sanitize_text_field( wp_unslash( $_POST['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! function_exists( 'db_seller_csrf' ) || ! hash_equals( db_seller_csrf( $email ), $t ) ) {
			db_seller_flash( $email, array( array( 'bad', 'That form expired. Please try again.' ) ) );
			wp_safe_redirect( $back );
			exit;
		}
	}
	// Stripe's refresh link (a GET without our token) only renews the link for an account that
	// already exists; creating an account needs the POST with the CSRF token (SL-21).
	$acc = $is_post ? db_connect_create_account( $seller ) : db_connect_account_id( $seller );
	if ( '' === $acc ) {
		wp_safe_redirect( $back );
		exit;
	}
	$url = is_wp_error( $acc ) ? $acc : db_connect_account_link( $acc );
	if ( is_wp_error( $url ) ) {
		db_seller_flash( $email, array( array( 'bad', $url->get_error_message() ) ) );
		wp_safe_redirect( $back );
		exit;
	}
	wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- Stripe's hosted onboarding (connect.stripe.com).
	exit;
}, 9 );

/* ---------------------------------------------- 5. Thin events (webhook) */

if ( ! function_exists( 'db_connect_wh_secret' ) ) {
	/** PLACEHOLDER: the thin event destination's signing secret (whsec_...), per mode. */
	function db_connect_wh_secret() {
		return trim( (string) get_option( 'test' === db_connect_mode() ? 'db_connect_wh_secret_test' : 'db_connect_wh_secret_live', '' ) );
	}
}

if ( ! function_exists( 'db_connect_events' ) ) {
	/** The V2 account events this site listens for (thin payload). */
	function db_connect_events() {
		return array(
			'v2.core.account[requirements].updated',
			'v2.core.account[configuration.recipient].capability_status_updated',
		);
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/stripe-connect-webhook', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true', // Stripe calls it; the signature check below is the gate.
		'callback'            => 'db_connect_webhook',
	) );
} );

if ( ! function_exists( 'db_connect_webhook' ) ) {
	function db_connect_webhook( WP_REST_Request $request ) {
		$secret = db_connect_wh_secret();
		if ( '' === $secret ) {
			if ( function_exists( 'db_wh_log_limited' ) ) {
				db_wh_log_limited( 'connect', 'db-connect: webhook received but no signing secret is set (option db_connect_wh_secret_' . db_connect_mode() . ').' );
			}
			return new WP_REST_Response( array( 'error' => 'Not available.' ), 503 );
		}
		$client = db_connect_client();
		if ( is_wp_error( $client ) ) {
			return new WP_REST_Response( array( 'error' => $client->get_error_message() ), 500 );
		}
		try {
			// 1. Verify the signature and read the thin notification (ids and type only, no data).
			$note = $client->parseEventNotification( $request->get_body(), (string) $request->get_header( 'stripe_signature' ), $secret );
		} catch ( \Throwable $e ) {
			return new WP_REST_Response( array( 'error' => 'Invalid signature or payload.' ), 400 );
		}
		// Stripe can deliver the same event more than once; handle each id once (SL-22).
		$seen_key = 'db_connect_evt_' . md5( (string) $note->id );
		if ( get_transient( $seen_key ) ) {
			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}
		try {
			// 2. Fetch the full event to see what happened, then act on its type.
			$event = $client->v2->core->events->retrieve( $note->id );
			$acct  = (string) ( $event->related_object->id ?? '' );
			db_connect_handle_event( (string) $event->type, $acct );
			set_transient( $seen_key, 1, 3 * DAY_IN_SECONDS );
		} catch ( \Throwable $e ) {
			error_log( 'db-connect: event ' . $note->id . ' could not be handled: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return new WP_REST_Response( array( 'error' => 'Could not handle the event; Stripe will retry.' ), 500 );
		}
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}
}

if ( ! function_exists( 'db_connect_handle_event' ) ) {
	/** One handler per event type. Unknown types are acknowledged and logged. */
	function db_connect_handle_event( $type, $account_id ) {
		$seller = '' !== $account_id ? db_connect_seller_by_account( $account_id ) : null;
		if ( ! $seller ) {
			error_log( 'db-connect: ' . $type . ' for an account with no seller: ' . $account_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return;
		}
		$page = add_query_arg( 'view', 'payouts', home_url( '/seller/' ) );
		if ( 'v2.core.account[requirements].updated' === $type ) {
			// Requirements changed (often new rules from regulators or card networks): ask the
			// seller to finish whatever Stripe now needs, if anything is due.
			$st = db_connect_status( $account_id );
			// Email only when the account goes from complete (or unknown) to needing details, not
			// on every requirements update while it is incomplete (SL-22).
			$was = db_connect_last_state( $account_id );
			if ( ! is_wp_error( $st ) ) {
				db_connect_last_state( $account_id, array( 'complete' => (bool) $st['complete'] ) );
			}
			if ( ! is_wp_error( $st ) && ! $st['complete'] && false !== ( $was['complete'] ?? null ) && function_exists( 'db_seller_mail' ) ) {
				db_seller_mail( (string) $seller['email'], 'Stripe needs more information to pay you', '<p>Stripe has asked for more details before it can send your sale proceeds.</p><p><a href="' . esc_url( $page ) . '">Open your seller page</a> and choose <strong>Continue onboarding</strong>.</p>' );
			}
			db_connect_audit( $seller, 'connect_requirements', is_wp_error( $st ) ? 'status unavailable' : (string) $st['requirements'] );
			return;
		}
		if ( 'v2.core.account[configuration.recipient].capability_status_updated' === $type ) {
			// The transfers capability changed: tell the seller when they can be paid.
			$st  = db_connect_status( $account_id );
			$was = db_connect_last_state( $account_id );
			if ( ! is_wp_error( $st ) ) {
				db_connect_last_state( $account_id, array( 'ready' => (bool) $st['ready'] ) );
			}
			if ( ! is_wp_error( $st ) && $st['ready'] && true !== ( $was['ready'] ?? null ) && function_exists( 'db_seller_mail' ) ) {
				db_seller_mail( (string) $seller['email'], 'You are ready to receive payments', '<p>Your Stripe account is set up. Payouts are still sent by bank transfer for now; we will email you before they move to Stripe.</p>' );
			}
			db_connect_audit( $seller, 'connect_capability', is_wp_error( $st ) ? 'status unavailable' : (string) $st['transfers'] );
			return;
		}
		db_connect_audit( $seller, 'connect_event', $type );
	}
}

if ( ! function_exists( 'db_connect_last_state' ) ) {
	/**
	 * The last account state we told the seller about ('ready', 'complete'), per connected
	 * account. With $merge, stores those keys and returns the new state.
	 */
	function db_connect_last_state( $account_id, ?array $merge = null ) {
		$key   = 'db_connect_state_' . md5( (string) $account_id );
		$state = (array) get_option( $key, array() );
		if ( null !== $merge ) {
			$state = array_merge( $state, $merge );
			update_option( $key, $state, false );
		}
		return $state;
	}
}

if ( ! function_exists( 'db_connect_audit' ) ) {
	function db_connect_audit( array $seller, $what, $detail ) {
		if ( function_exists( 'db_seller_audit' ) ) {
			db_seller_audit( (int) $seller['id'], '', $what, (string) $detail );
		}
	}
}

/* ---------------------------------------------------------- 6. Products */

if ( ! function_exists( 'db_connect_create_product' ) ) {
	/**
	 * Creates a product on the platform account (not on the connected account). The connected
	 * account that receives the money is kept in the product's metadata.
	 *
	 * @param string $name        Product name.
	 * @param string $description Optional description.
	 * @param int    $cents       Price in the smallest currency unit (cents).
	 * @param string $currency    Three-letter currency, e.g. 'usd'.
	 * @param string $account_id  The connected account that receives the sale (acct_...).
	 * @return \Stripe\Product|WP_Error
	 */
	function db_connect_create_product( $name, $description, $cents, $currency, $account_id ) {
		$name     = trim( wp_strip_all_tags( (string) $name ) );
		$currency = strtolower( (string) $currency );
		if ( '' === $name || mb_strlen( $name ) > 120 ) {
			return new WP_Error( 'db_connect_name', 'Give the product a name (up to 120 characters).' );
		}
		if ( (int) $cents < 100 || (int) $cents > 99999999 ) {
			return new WP_Error( 'db_connect_price', 'Enter a price between $1 and $999,999.99.' );
		}
		if ( ! in_array( $currency, array( 'usd', 'cad' ), true ) ) {
			return new WP_Error( 'db_connect_currency', 'Choose USD or CAD.' );
		}
		if ( ! preg_match( '/^acct_[A-Za-z0-9]+$/', (string) $account_id ) || ! db_connect_seller_by_account( $account_id ) ) {
			return new WP_Error( 'db_connect_account', 'Choose a connected seller account.' );
		}
		return db_connect_call( 'create product', static function ( $stripe ) use ( $name, $description, $cents, $currency, $account_id ) {
			$params = array(
				'name'               => $name,
				'default_price_data' => array(
					'unit_amount' => (int) $cents,
					'currency'    => $currency,
				),
				// The product to connected account mapping lives here.
				'metadata'           => array( 'connected_account' => $account_id, 'source' => 'db-connect' ),
			);
			$description = trim( wp_strip_all_tags( (string) $description ) );
			if ( '' !== $description ) {
				$params['description'] = mb_substr( $description, 0, 500 );
			}
			return $stripe->products->create( $params );
		} );
	}
}

if ( ! function_exists( 'db_connect_products' ) ) {
	/**
	 * Active platform products created here (those carrying a connected account), with their
	 * default price expanded. Only products with a usable price are returned.
	 *
	 * @return array|WP_Error List of arrays: id, name, description, amount, currency, account.
	 */
	function db_connect_products() {
		$list = db_connect_call( 'list products', static function ( $stripe ) {
			return $stripe->products->all( array( 'active' => true, 'limit' => 100, 'expand' => array( 'data.default_price' ) ) );
		} );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$out = array();
		foreach ( $list->data as $p ) {
			$acct  = (string) ( $p->metadata['connected_account'] ?? '' );
			$price = $p->default_price;
			if ( '' === $acct || ! is_object( $price ) || empty( $price->unit_amount ) ) {
				continue;
			}
			$out[] = array(
				'id'          => (string) $p->id,
				'name'        => (string) $p->name,
				'description' => (string) ( $p->description ?? '' ),
				'amount'      => (int) $price->unit_amount,
				'currency'    => (string) $price->currency,
				'account'     => $acct,
			);
		}
		return $out;
	}
}

/* ------------------------------------------------- 8. Destination charges */

if ( ! function_exists( 'db_connect_fee' ) ) {
	/**
	 * Pure: the application fee in cents. The marketplace commission (15%, set by the option
	 * db_connect_fee_pct), never the whole amount: the seller always receives something.
	 */
	function db_connect_fee( $amount_cents, $pct ) {
		$amount_cents = max( 0, (int) $amount_cents );
		$pct          = min( 90, max( 0, (float) $pct ) );
		return (int) min( max( 0, $amount_cents - 1 ), (int) round( $amount_cents * $pct / 100 ) );
	}
}

if ( ! function_exists( 'db_connect_checkout' ) ) {
	/**
	 * Starts Stripe-hosted Checkout for one product as a destination charge: the platform keeps
	 * the application fee and the rest is transferred to the product's connected account.
	 *
	 * @return string|WP_Error The Checkout URL.
	 */
	function db_connect_checkout( $product_id ) {
		$product = null;
		$all     = db_connect_products();
		if ( is_wp_error( $all ) ) {
			return $all;
		}
		foreach ( $all as $p ) {
			if ( $p['id'] === $product_id ) {
				$product = $p;
			}
		}
		if ( ! $product ) {
			return new WP_Error( 'db_connect_product', 'That product is no longer for sale.' );
		}
		$fee   = db_connect_fee( $product['amount'], (float) get_option( 'db_connect_fee_pct', 15 ) );
		$store = home_url( '/connect-store/' );
		$sess  = db_connect_call( 'checkout session', static function ( $stripe ) use ( $product, $fee, $store ) {
			return $stripe->checkout->sessions->create( array(
				'mode'                => 'payment',
				'line_items'          => array(
					array(
						// Price data for the product, read from its default price on our side.
						'price_data' => array(
							'currency'     => $product['currency'],
							'unit_amount'  => $product['amount'],
							'product'      => $product['id'],
						),
						'quantity'   => 1,
					),
				),
				'payment_intent_data' => array(
					// What the platform keeps.
					'application_fee_amount' => $fee,
					// Who receives the rest.
					'transfer_data'          => array( 'destination' => $product['account'] ),
					'metadata'               => array( 'source' => 'db-connect', 'product' => $product['id'] ),
				),
				// {CHECKOUT_SESSION_ID} is filled in by Stripe.
				'success_url'         => $store . '?session_id={CHECKOUT_SESSION_ID}',
				'cancel_url'          => $store . '?cancelled=1',
			) );
		} );
		return is_wp_error( $sess ) ? $sess : (string) $sess->url;
	}
}

/* ------------------------------------------------------- 7. Storefront */

if ( ! function_exists( 'db_connect_store_open' ) ) {
	/** Staging: always. Production: administrators, or everyone once db_connect_store_public is '1'. */
	function db_connect_store_open() {
		if ( function_exists( 'db_is_staging_host' ) && db_is_staging_host() ) {
			return true;
		}
		return '1' === (string) get_option( 'db_connect_store_public', '' ) || current_user_can( 'manage_options' );
	}
}

if ( ! function_exists( 'db_connect_money' ) ) {
	function db_connect_money( $cents, $currency ) {
		return ( 'cad' === strtolower( (string) $currency ) ? 'CA$' : '$' ) . number_format( (int) $cents / 100, 2 );
	}
}

if ( ! function_exists( 'db_connect_css' ) ) {
	function db_connect_css() {
		return '<style>.dbcs{max-width:1100px;margin:0 auto;color:#0a1628}.dbcs *{box-sizing:border-box}'
			. '.dbcs-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px;margin:0 0 28px;padding:0;list-style:none}'
			. '.dbcs-card{display:flex;flex-direction:column;gap:6px;background:#fff;border:1px solid #dfe5e1;border-radius:18px;padding:20px;box-shadow:0 6px 20px rgba(10,22,40,.05)}'
			. '.dbcs-card h3{margin:0;font-size:19px;font-weight:800;overflow-wrap:anywhere}.dbcs-card p{margin:0;color:#374151;font-size:15px;line-height:1.55}'
			. '.dbcs-price{font-size:22px;font-weight:800}.dbcs-by{font-size:13.5px;color:#4b5563}'
			. '.dbcs-btn{margin-top:auto;display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:0 22px;border:0;border-radius:999px;background:#0a1628;color:#fff;font:700 15px/1 inherit;cursor:pointer;width:100%}'
			. '.dbcs-btn:hover,.dbcs-btn:focus-visible{background:#16324f}.dbcs-btn:disabled{background:#c9d1cd;color:#36423d;cursor:not-allowed}.dbcs h2{margin:0 0 12px;font-size:24px;font-weight:800}'
			. '.dbcs-msg{margin:0 0 16px;padding:12px 16px;border-radius:14px;border:1px solid}.dbcs-ok{background:#ecf7ef;border-color:#b7e1c3;color:#0b5a2b}.dbcs-bad{background:#fef2f2;border-color:#fca5a5;color:#991b1b}'
			. '.dbcs-acc{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 28px;padding:0;list-style:none}.dbcs-acc li{padding:8px 14px;border:1px solid #dfe5e1;border-radius:999px;background:#fff;font-weight:600}</style>';
	}
}

if ( ! function_exists( 'db_connect_store_body' ) ) {
	function db_connect_store_body() {
		$h = db_connect_css() . '<div class="dbcs">';
		if ( isset( $_GET['session_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// Back from Checkout: read the session to show the real result (never trust the URL alone).
			$sid  = sanitize_text_field( wp_unslash( $_GET['session_id'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$sess = preg_match( '/^cs_(test|live)_[A-Za-z0-9]+$/', $sid ) ? db_connect_call( 'retrieve session', static function ( $stripe ) use ( $sid ) {
				return $stripe->checkout->sessions->retrieve( $sid );
			} ) : new WP_Error( 'db_connect_session', 'Unknown order.' );
			$h .= ( ! is_wp_error( $sess ) && 'paid' === $sess->payment_status )
				? '<p class="dbcs-msg dbcs-ok" role="status"><strong>Thank you, payment received.</strong> A receipt is on its way to ' . esc_html( (string) ( $sess->customer_details->email ?? 'your email' ) ) . '.</p>'
				: '<p class="dbcs-msg dbcs-bad" role="status">We could not confirm that payment. If you were charged, contact us and quote ' . esc_html( $sid ) . '.</p>';
		} elseif ( isset( $_GET['cancelled'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$h .= '<p class="dbcs-msg dbcs-bad" role="status">Checkout was cancelled. Nothing was charged.</p>';
		}
		if ( isset( $_GET['err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			// A fixed message: any text in ?err= was shown as an on-site alert, a phishing lure on
			// our own domain (bug register batch 2, SL-19). The real reason is in the error log.
			$h .= '<p class="dbcs-msg dbcs-bad" role="alert">The checkout could not be started. Please try again, or contact us if it keeps happening.</p>';
		}
		$products = db_connect_products();
		if ( is_wp_error( $products ) ) {
			return $h . '<p class="dbcs-msg dbcs-bad" role="alert">' . esc_html( $products->get_error_message() ) . '</p></div>';
		}
		// No seller directory and no seller names: sellers are told their details are never shown
		// to buyers, and the list printed company or legal names to anyone (SL-12).
		$h .= '<h2>Products</h2>';
		if ( ! $products ) {
			return $h . '<p>No products yet. Create one in WP Admin &gt; Tools &gt; Stripe Connect.</p></div>';
		}
		// A destination charge fails until the seller's transfers capability is active, so each
		// seller's status is read (live, once per seller on this page) before offering Buy now.
		$ready = array();
		foreach ( array_unique( wp_list_pluck( $products, 'account' ) ) as $acct ) {
			$st             = db_connect_status( $acct );
			$ready[ $acct ] = ! is_wp_error( $st ) && $st['ready'];
		}
		$h .= '<ul class="dbcs-grid">';
		foreach ( $products as $p ) {
			$h .= '<li class="dbcs-card"><h3>' . esc_html( $p['name'] ) . '</h3>'
				. ( '' !== $p['description'] ? '<p>' . esc_html( $p['description'] ) . '</p>' : '' )
				. '<span class="dbcs-price">' . esc_html( db_connect_money( $p['amount'], $p['currency'] ) ) . '</span>'
				. '<span class="dbcs-by">Sold by a Domain Brothers seller</span>'
				. ( empty( $ready[ $p['account'] ] )
					? '<button type="button" class="dbcs-btn" disabled aria-disabled="true">Seller is finishing setup</button></li>'
					: '<form method="post" action="' . esc_url( home_url( '/connect-store/' ) ) . '">' . wp_nonce_field( 'db_connect_buy', '_dbcs', true, false )
						. '<input type="hidden" name="product" value="' . esc_attr( $p['id'] ) . '"><button type="submit" class="dbcs-btn">Buy now</button></form></li>' );
		}
		return $h . '</ul><p class="dbcs-by">Payments are processed by Stripe. Domain Brothers keeps a service fee and the seller receives the rest.</p></div>';
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! function_exists( 'db_sales_request_path' ) || 'connect-store' !== db_sales_request_path() ) {
		return;
	}
	if ( ! db_connect_store_open() ) {
		return; // stays a 404 for visitors on production until switched on
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ok  = isset( $_POST['_dbcs'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_dbcs'] ) ), 'db_connect_buy' );
		$pid = isset( $_POST['product'] ) ? sanitize_text_field( wp_unslash( $_POST['product'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$url = $ok && preg_match( '/^prod_[A-Za-z0-9]+$/', $pid ) ? db_connect_checkout( $pid ) : new WP_Error( 'db_connect_form', 'That form expired. Please try again.' );
		if ( is_wp_error( $url ) ) {
			error_log( 'DB Connect: store checkout failed: ' . $url->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			wp_safe_redirect( add_query_arg( 'err', '1', home_url( '/connect-store/' ) ) );
			exit;
		}
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- Stripe-hosted Checkout (checkout.stripe.com).
		exit;
	}
	db_sales_render( 'Seller storefront', 'Storefront', db_connect_store_body() );
}, 9 );

/* ------------------------------------------------- Admin: Tools > Stripe Connect */

add_action( 'admin_menu', function () {
	add_management_page( 'Stripe Connect', 'Stripe Connect', 'manage_options', 'db-connect', 'db_connect_admin_page' );
} );

if ( ! function_exists( 'db_connect_admin_page' ) ) {
	function db_connect_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$mode   = db_connect_mode();
		$client = db_connect_client();
		echo '<div class="wrap"><h1>Stripe Connect</h1>';
		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<p>Mode: <strong>' . esc_html( $mode ) . '</strong>. Sellers onboard from their seller page (Sales and payouts tab). Storefront: <a href="' . esc_url( home_url( '/connect-store/' ) ) . '">' . esc_html( home_url( '/connect-store/' ) ) . '</a>.</p>';
		if ( is_wp_error( $client ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $client->get_error_message() ) . '</p></div></div>';
			return;
		}
		// Event destination for thin V2 account events.
		$secret = db_connect_wh_secret();
		echo '<h2>Account events</h2><p>Endpoint: <code>' . esc_html( rest_url( 'db/v1/stripe-connect-webhook' ) ) . '</code><br>Events: <code>' . esc_html( implode( '</code>, <code>', db_connect_events() ) ) . '</code> (thin payload, from connected accounts).</p>';
		echo '<p>Signing secret: ' . ( '' !== $secret ? '<strong>saved</strong>' : '<strong style="color:#b42318">not set</strong>' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
		wp_nonce_field( 'db_connect_dest' );
		echo '<input type="hidden" name="action" value="db_connect_dest"><button class="button button-primary">Create event destination</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block">';
		wp_nonce_field( 'db_connect_secret' );
		echo '<input type="hidden" name="action" value="db_connect_secret"><input type="password" name="secret" placeholder="whsec_... (if created in the Dashboard)" autocomplete="off" style="width:320px"> <button class="button">Save secret</button></form>';

		// Connected sellers with live status.
		global $wpdb;
		$col = 'test' === $mode ? 'connect_test' : 'connect_live';
		$t   = db_seller_table( 'sellers' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT id, email, legal_name, company, {$col} AS acct FROM {$t} WHERE {$col} <> '' ORDER BY id DESC LIMIT 50", ARRAY_A );
		echo '<h2>Connected sellers</h2>';
		if ( ! $rows ) {
			echo '<p>No seller has started onboarding yet.</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:980px"><thead><tr><th>Seller</th><th>Account</th><th>Ready to receive payments</th><th>Requirements</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$st = db_connect_status( $r['acct'] );
				echo '<tr><td>' . esc_html( ( '' !== $r['company'] ? $r['company'] : $r['legal_name'] ) . ' (' . $r['email'] . ')' ) . '</td><td><code>' . esc_html( $r['acct'] ) . '</code></td>'
					. '<td>' . ( is_wp_error( $st ) ? esc_html( $st->get_error_message() ) : ( $st['ready'] ? 'Yes' : 'Not yet (' . esc_html( '' !== $st['transfers'] ? $st['transfers'] : 'pending' ) . ')' ) ) . '</td>'
					. '<td>' . ( is_wp_error( $st ) ? '' : esc_html( '' !== $st['requirements'] ? $st['requirements'] : 'none due' ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Create a product for a connected seller.
		echo '<h2>Create a product</h2>';
		if ( ! $rows ) {
			echo '<p>A seller must start onboarding first.</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><table class="form-table" role="presentation">';
		wp_nonce_field( 'db_connect_product' );
		echo '<input type="hidden" name="action" value="db_connect_product">';
		echo '<tr><th><label for="dbc-name">Name</label></th><td><input id="dbc-name" name="name" class="regular-text" required maxlength="120"></td></tr>';
		echo '<tr><th><label for="dbc-desc">Description</label></th><td><textarea id="dbc-desc" name="description" class="large-text" rows="3" maxlength="500"></textarea></td></tr>';
		echo '<tr><th><label for="dbc-price">Price</label></th><td><input id="dbc-price" name="price" type="number" min="1" step="0.01" required> <select name="currency"><option value="usd">USD</option><option value="cad">CAD</option></select></td></tr>';
		echo '<tr><th><label for="dbc-acct">Seller</label></th><td><select id="dbc-acct" name="account">';
		foreach ( $rows as $r ) {
			echo '<option value="' . esc_attr( $r['acct'] ) . '">' . esc_html( ( '' !== $r['company'] ? $r['company'] : $r['legal_name'] ) . ' - ' . $r['acct'] ) . '</option>';
		}
		echo '</select></td></tr></table><p><button class="button button-primary">Create product</button></p></form>';
		echo '<p class="description">Application fee on each sale: ' . esc_html( (string) (float) get_option( 'db_connect_fee_pct', 15 ) ) . '% (option db_connect_fee_pct).</p></div>';
	}
}

add_action( 'admin_post_db_connect_product', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_connect_product' );
	$price = isset( $_POST['price'] ) ? (float) wp_unslash( $_POST['price'] ) : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$res   = db_connect_create_product(
		isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
		isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
		(int) round( $price * 100 ),
		isset( $_POST['currency'] ) ? sanitize_key( wp_unslash( $_POST['currency'] ) ) : 'usd',
		isset( $_POST['account'] ) ? sanitize_text_field( wp_unslash( $_POST['account'] ) ) : ''
	);
	$msg = is_wp_error( $res ) ? $res->get_error_message() : 'Product created: ' . $res->id . '. It is on the storefront now.';
	wp_safe_redirect( add_query_arg( array( 'page' => 'db-connect', 'msg' => rawurlencode( $msg ) ), admin_url( 'tools.php' ) ) );
	exit;
} );

add_action( 'admin_post_db_connect_dest', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_connect_dest' );
	// Creates the thin event destination for V2 account events from connected accounts and saves
	// its signing secret, the same as doing it by hand in the Dashboard (Developers > Webhooks).
	$dest = db_connect_call( 'create event destination', static function ( $stripe ) {
		return $stripe->v2->core->eventDestinations->create( array(
			'name'             => 'Domain Brothers seller accounts',
			'description'      => 'V2 account requirements and recipient capability changes',
			'type'             => 'webhook_endpoint',
			'event_payload'    => 'thin',
			'events_from'      => array( '@accounts' ),
			'enabled_events'   => db_connect_events(),
			'webhook_endpoint' => array( 'url' => rest_url( 'db/v1/stripe-connect-webhook' ) ),
			'include'          => array( 'webhook_endpoint.signing_secret' ),
		) );
	} );
	if ( is_wp_error( $dest ) ) {
		$msg = $dest->get_error_message();
	} else {
		$secret = (string) ( $dest->webhook_endpoint->signing_secret ?? '' );
		if ( '' !== $secret ) {
			update_option( 'test' === db_connect_mode() ? 'db_connect_wh_secret_test' : 'db_connect_wh_secret_live', $secret, false );
		}
		$msg = 'Event destination ' . $dest->id . ' created' . ( '' !== $secret ? ' and its signing secret saved.' : '. Copy its signing secret from the Dashboard and save it here.' );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'db-connect', 'msg' => rawurlencode( $msg ) ), admin_url( 'tools.php' ) ) );
	exit;
} );

add_action( 'admin_post_db_connect_secret', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_connect_secret' );
	$s = isset( $_POST['secret'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['secret'] ) ) ) : '';
	$ok = (bool) preg_match( '/^whsec_[A-Za-z0-9]+$/', $s );
	if ( $ok ) {
		update_option( 'test' === db_connect_mode() ? 'db_connect_wh_secret_test' : 'db_connect_wh_secret_live', $s, false );
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'db-connect', 'msg' => rawurlencode( $ok ? 'Signing secret saved.' : 'That does not look like a signing secret (whsec_...).' ) ), admin_url( 'tools.php' ) ) );
	exit;
} );
