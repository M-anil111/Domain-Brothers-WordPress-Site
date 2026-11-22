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
	function db_ad_export_array( $email, array $row, array $saved, array $orders ) {
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
			$row = function_exists( 'db_acct_row' ) ? ( db_acct_row( $email ) ?: array() ) : array();
			return $send( array( 'ok' => true, 'data' => db_ad_export_array( $email, $row, $saved, $orders ) ) );
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
