<?php
/**
 * Account data: self-service export and deletion (Profile tab > Your data).
 *
 * Export returns the signed-in person's own data as a JSON download. Delete
 * removes the account, saved domains, sign-in codes and sessions after the
 * person types their email address to confirm, then signs them out.
 *
 * Kept on purpose, and stated to the person: purchase and payment-plan records
 * (tax and payment-dispute obligations) and enquiries they sent us. They can
 * ask for those to be reviewed by email.
 *
 * @package DB_Custom_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_ad_export_array' ) ) {
	/**
	 * What we hold for one person. Pure: tested offline.
	 *
	 * @param string $email  Sign-in address.
	 * @param array  $row    db_accounts row (may be empty).
	 * @param array  $saved  Domain names the person saved.
	 * @param array  $orders Rows with domain, kind, price, paid, plan_months, status, created_at.
	 * @return array
	 */
	function db_ad_export_array( $email, array $row, array $saved, array $orders, array $extra = array() ) {
		$profile = json_decode( (string) ( $row['profile'] ?? '' ), true );
		$out     = array(
			'exported_at' => gmdate( 'c' ),
			'email'       => (string) $email,
			'account'     => array(
				'full_name'          => (string) ( $row['full_name'] ?? '' ),
				'phone'              => (string) ( $row['phone'] ?? '' ),
				'newsletter'         => ! empty( $row['newsletter'] ),
				'newsletter_changed' => (string) ( $row['newsletter_at'] ?? '' ),
				'consent_version'    => (string) ( $row['consent_version'] ?? '' ),
				'consent_at'         => (string) ( $row['consent_at'] ?? '' ),
				'profile_answers'    => is_array( $profile ) ? $profile : new stdClass(),
				'created_at'         => (string) ( $row['created_at'] ?? '' ),
			),
			'saved_domains' => array_values( array_map( 'strval', $saved ) ),
			'orders'        => array(),
		);
		foreach ( $orders as $o ) {
			$out['orders'][] = array(
				'domain'      => (string) ( $o['domain'] ?? '' ),
				'type'        => (string) ( $o['kind'] ?? '' ),
				'price'       => round( (float) ( $o['price'] ?? 0 ), 2 ),
				'paid'        => round( (float) ( $o['paid'] ?? 0 ), 2 ),
				'plan_months' => (int) ( $o['plan_months'] ?? 0 ),
				'status'      => (string) ( $o['status'] ?? '' ),
				'date'        => (string) ( $o['created_at'] ?? '' ),
			);
		}
		// Alerts, offers and the alert-email setting are personal data too (AC-14). Deal tokens
		// are left out: they work like passwords for the checkout link.
		$out['email_alerts'] = ! array_key_exists( 'email_alerts', $extra ) || ! empty( $extra['email_alerts'] );
		$out['alerts']       = array();
		foreach ( (array) ( $extra['alerts'] ?? array() ) as $a ) {
			$out['alerts'][] = array(
				'kind'    => (string) ( $a['kind'] ?? '' ),
				'message' => (string) ( $a['message'] ?? '' ),
				'date'    => (string) ( $a['created_at'] ?? '' ),
				'read'    => (string) ( $a['read_at'] ?? '' ),
			);
		}
		$out['offers'] = array();
		foreach ( (array) ( $extra['offers'] ?? array() ) as $d ) {
			$msgs = array();
			foreach ( (array) ( $d['events'] ?? array() ) as $e ) {
				$msgs[] = array(
					'from'    => (string) ( $e['actor'] ?? '' ),
					'action'  => (string) ( $e['action'] ?? '' ),
					'amount'  => round( (float) ( $e['amount'] ?? 0 ), 2 ),
					'message' => (string) ( $e['message'] ?? '' ),
					'date'    => (string) ( $e['created_at'] ?? '' ),
				);
			}
			$out['offers'][] = array(
				'domain'   => (string) ( $d['domain'] ?? '' ),
				'amount'   => round( (float) ( $d['amount'] ?? 0 ), 2 ),
				'state'    => (string) ( $d['state'] ?? '' ),
				'started'  => (string) ( $d['created_at'] ?? '' ),
				'updated'  => (string) ( $d['updated_at'] ?? '' ),
				'messages' => $msgs,
			);
		}
		return $out;
	}
}

if ( ! function_exists( 'db_ad_confirm_ok' ) ) {
	/** True when the typed confirmation matches the signed-in address (case and spacing ignored). Pure. */
	function db_ad_confirm_ok( $email, $typed ) {
		$email = strtolower( trim( (string) $email ) );
		return '' !== $email && $email === strtolower( trim( (string) $typed ) );
	}
}

if ( ! function_exists( 'db_ad_delete_targets' ) ) {
	/** Table => column pairs removed on deletion. Pure. */
	function db_ad_delete_targets( $prefix ) {
		return array(
			$prefix . 'db_wl_items'    => 'email',
			$prefix . 'db_wl_codes'    => 'email',
			$prefix . 'db_wl_sessions' => 'email',
			$prefix . 'db_accounts'    => 'email',
			// Alerts, alert settings, saved searches and checkout reminders: an alert already queued
			// was still emailed after the account was deleted (bug register batch 2, AC-9). Orders,
			// offers and transfer records are kept as business records.
			$prefix . 'db_wl_notes'            => 'email',
			$prefix . 'db_wl_prefs'            => 'email',
			$prefix . 'db_saved_searches'      => 'email',
			$prefix . 'db_checkout_reminders'  => 'email',
		);
	}
}

add_action( 'rest_api_init', function () {
	$send = static function ( $data, $status = 200 ) {
		$r = new WP_REST_Response( $data, $status );
		$r->header( 'Cache-Control', 'no-store, private' );
		return $r;
	};
	$guard = static function () use ( $send ) {
		if ( ! function_exists( 'db_wl_same_origin' ) || ! db_wl_same_origin() ) {
			return $send( array( 'ok' => false, 'message' => 'Please use the website.' ), 403 );
		}
		if ( '' === db_wl_email() ) {
			return $send( array( 'ok' => false, 'message' => 'Please sign in again.' ), 401 );
		}
		return null;
	};

	register_rest_route( 'db/v1', '/account/export', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function () use ( $send, $guard ) {
			if ( $bad = $guard() ) {
				return $bad;
			}
			global $wpdb;
			$email = db_wl_email();
			$saved = array();
			$ids   = (array) $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->prefix}db_wl_items WHERE email = %s", $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $ids as $id ) {
				$t = get_the_title( (int) $id );
				if ( '' !== $t ) {
					$saved[] = wp_strip_all_tags( $t );
				}
			}
			$orders = array();
			if ( function_exists( 'db_orders_table' ) ) {
				$ot     = db_orders_table();
				$orders = (array) $wpdb->get_results( $wpdb->prepare( "SELECT domain, kind, price, paid, plan_months, status, created_at FROM {$ot} WHERE buyer_email = %s ORDER BY id DESC", $email ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$row   = function_exists( 'db_acct_row' ) ? ( db_acct_row( $email ) ?: array() ) : array();
			$extra = array(
				'alerts' => (array) $wpdb->get_results( $wpdb->prepare( "SELECT kind, message, created_at, read_at FROM {$wpdb->prefix}db_wl_notes WHERE email = %s ORDER BY id DESC", $email ), ARRAY_A ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'offers' => array(),
			);
			if ( function_exists( 'db_acct_emails_on' ) ) {
				$extra['email_alerts'] = (bool) db_acct_emails_on( $email );
			}
			if ( function_exists( 'db_deal_table' ) ) {
				$dt = db_deal_table();
				$et = db_deal_table( 'events' );
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, domain, amount, state, created_at, updated_at FROM {$dt} WHERE LOWER(buyer_email) = %s ORDER BY id DESC", strtolower( $email ) ), ARRAY_A ) as $d ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$d['events']       = (array) $wpdb->get_results( $wpdb->prepare( "SELECT actor, action, amount, message, created_at FROM {$et} WHERE deal_id = %d ORDER BY id ASC", (int) $d['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$extra['offers'][] = $d;
				}
			}
			return $send( array( 'ok' => true, 'data' => db_ad_export_array( $email, $row, $saved, $orders, $extra ) ) );
		},
	) );

	register_rest_route( 'db/v1', '/account/delete', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) use ( $send, $guard ) {
			if ( $bad = $guard() ) {
				return $bad;
			}
			$email = db_wl_email();
			$body  = json_decode( (string) $req->get_body(), true );
			if ( ! is_array( $body ) || ! db_ad_confirm_ok( $email, $body['confirm'] ?? '' ) ) {
				return $send( array( 'ok' => false, 'message' => 'Type your sign-in email exactly to confirm.' ), 400 );
			}
			global $wpdb;
			do_action( 'db_account_before_delete', $email ); // e.g. a seller's live names come down first
			foreach ( db_ad_delete_targets( $wpdb->prefix ) as $table => $col ) {
				$wpdb->delete( $table, array( $col => $email ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( ! headers_sent() ) {
				setcookie( DB_WL_COOKIE, '', array( 'expires' => time() - DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			}
			unset( $_COOKIE[ DB_WL_COOKIE ] );
			if ( ! headers_sent() ) {
				setcookie( 'db_in', '', array( 'expires' => time() - DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'samesite' => 'Lax' ) ); // signed-in hint (AC-9)
			}
			delete_transient( function_exists( 'db_reg_pending_key' ) ? db_reg_pending_key( $email ) : 'db_reg_' . md5( $email ) );
			error_log( 'DB account: self-service deletion completed.' ); // No address in the log.
			return $send( array( 'ok' => true, 'message' => 'Your account and saved domains are deleted.' ) );
		},
	) );
} );

add_action( 'wp_footer', function () {
	if ( ! function_exists( 'db_wl_is_page_request' ) || ! db_wl_is_page_request() || '' === db_wl_email() ) {
		return;
	}
	?>
<script data-no-delay>
(function () {
	var API = <?php echo wp_json_encode( esc_url_raw( rest_url( 'db/v1/' ) ) ); ?>;
	function post(path, body) {
		return fetch(API + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'text/plain' }, body: JSON.stringify(body || {}) })
			.then(function (r) { return r.json(); });
	}
	var ex = document.getElementById('db-ad-export'), msg = document.getElementById('db-ad-msg');
	function say(t, bad) { if (msg) { msg.textContent = t; msg.className = 'db-ac-msg ' + (bad ? 'is-bad' : 'is-good'); } }
	if (ex) {
		ex.addEventListener('click', function () {
			ex.disabled = true; say('Preparing your file…', false);
			post('account/export').then(function (j) {
				ex.disabled = false;
				if (!j.ok) { say(j.message || 'Could not export.', true); return; }
				var a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([JSON.stringify(j.data, null, 2)], { type: 'application/json' }));
				a.download = 'domain-brothers-my-data.json';
				document.body.appendChild(a); a.click(); a.remove();
				say('Downloaded.', false);
			}).catch(function () { ex.disabled = false; say('Could not reach the server. Try again.', true); });
		});
	}
	var f = document.getElementById('db-ad-delete');
	if (f) {
		f.addEventListener('submit', function (e) {
			e.preventDefault();
			var b = f.querySelector('button[type=submit]'); b.disabled = true; say('Deleting…', false);
			post('account/delete', { confirm: f.elements.confirm.value }).then(function (j) {
				if (j.ok) { say(j.message, false); setTimeout(function () { location.href = '/'; }, 1200); return; }
				b.disabled = false; say(j.message || 'Could not delete.', true);
			}).catch(function () { b.disabled = false; say('Could not reach the server. Try again.', true); });
		});
	}
}());
</script>
	<?php
}, 94 );
