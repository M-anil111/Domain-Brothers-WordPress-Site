<?php
/**
 * Sold domains: what has been sold, at what price, the total made, and the
 * transfer (EPP / unlock) code that goes to each buyer.
 *
 * Domain Brothers > Sold domains lists every listing that is sold, from any
 * source: a website sale (price and buyer are recorded automatically), a sale
 * entered by hand on the domain's edit screen, or an older sold listing whose
 * price was never stored (shown as "not recorded"; fill it in one by one or
 * paste a list). Tiles show the number sold, the number with a known price,
 * the total and the average.
 *
 * EPP codes. A domain that is sold gets its code from the admin: type the code
 * and the buyer's email next to the domain. The buyer is emailed a private
 * link, never the code itself. The code is shown on that page only after the
 * buyer presses "Show my code" (a POST, so an email scanner that opens the link
 * cannot reveal it), the first view is recorded, and the link lapses after 14
 * days. Codes are stored encrypted (AES-256-GCM, key derived from the site's
 * secret salts) and are never shown in the admin again. For a payment plan the
 * code is held back until the last payment has been made.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_EPP_TABLE_VERSION' ) ) {
	define( 'DB_EPP_TABLE_VERSION', '1' );
}
if ( ! defined( 'DB_EPP_LINK_DAYS' ) ) {
	define( 'DB_EPP_LINK_DAYS', 14 );
}

/* ───────────────────────────── 1. Table ───────────────────────────────── */

add_action( 'init', function () {
	if ( get_option( 'db_epp_table_version', '' ) === DB_EPP_TABLE_VERSION ) {
		return;
	}
	global $wpdb;
	$t       = $wpdb->prefix . 'db_epp';
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$t} (
		id          INT          NOT NULL AUTO_INCREMENT,
		domain      VARCHAR(255) NOT NULL DEFAULT '',
		buyer_email VARCHAR(255) NOT NULL DEFAULT '',
		code_enc    TEXT         NULL,
		token       CHAR(32)     NOT NULL,
		note        TEXT         NULL,
		status      VARCHAR(10)  NOT NULL DEFAULT 'sent',
		sent_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		viewed_at   DATETIME     NULL,
		expires_at  DATETIME     NULL,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_token (token),
		KEY idx_domain (domain(40))
	) {$charset};" );
	update_option( 'db_epp_table_version', DB_EPP_TABLE_VERSION );
} );

/* ───────────────────────── 2. Code storage ────────────────────────────── */

if ( ! function_exists( 'db_epp_key' ) ) {
	function db_epp_key() {
		return hash( 'sha256', wp_salt( 'secure_auth' ) . '|db_epp_code', true );
	}
}

if ( ! function_exists( 'db_epp_encrypt' ) ) {
	/** @return string|false base64 of iv + tag + ciphertext, or false when OpenSSL is unavailable. */
	function db_epp_encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) || ! in_array( 'aes-256-gcm', (array) openssl_get_cipher_methods(), true ) ) {
			return false;
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$c   = openssl_encrypt( (string) $plain, 'aes-256-gcm', db_epp_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $c ? false : base64_encode( $iv . $tag . $c );
	}
}

if ( ! function_exists( 'db_epp_decrypt' ) ) {
	function db_epp_decrypt( $b64 ) {
		$raw = base64_decode( (string) $b64, true );
		if ( false === $raw || strlen( $raw ) < 29 || ! function_exists( 'openssl_decrypt' ) ) {
			return false;
		}
		return openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', db_epp_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
	}
}

if ( ! function_exists( 'db_epp_clean_code' ) ) {
	/** The code as typed, trimmed; null when it is empty, too long or has control characters. */
	function db_epp_clean_code( $raw ) {
		$code = trim( (string) $raw );
		if ( '' === $code || strlen( $code ) > 120 || preg_match( '/[\x00-\x1f\x7f\s]/', $code ) ) {
			return null;
		}
		return $code;
	}
}

if ( ! function_exists( 'db_epp_row' ) ) {
	function db_epp_row( $where_sql, $arg ) {
		global $wpdb;
		$t = $wpdb->prefix . 'db_epp';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where_sql} ORDER BY id DESC LIMIT 1", $arg ) );
	}
}

/* ─────────────────────── 3. Who may be sent a code ────────────────────── */

if ( ! function_exists( 'db_epp_eligibility' ) ) {
	/** A payment plan must be finished before the code is released. */
	function db_epp_eligibility( $domain ) {
		global $wpdb;
		$t = function_exists( 'db_orders_table' ) ? db_orders_table() : '';
		if ( '' === $t ) {
			return array( 'ok' => true, 'why' => '' );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$orders = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE domain = %s AND mode = %s ORDER BY id DESC", strtolower( trim( $domain ) ), db_orders_mode() ) );
		foreach ( $orders as $o ) {
			if ( (int) $o->plan_months < 1 ) {
				return array( 'ok' => true, 'why' => '' ); // paid in full
			}
			$live = db_orders_live( $o );
			if ( 'plan_completed' === $live['status'] ) {
				return array( 'ok' => true, 'why' => '' );
			}
			return array( 'ok' => false, 'why' => 'Payment plan not finished (' . $live['detail'] . '). Release the code after the last payment.' );
		}
		return array( 'ok' => true, 'why' => '' ); // sold by hand or before this system: the admin vouches for it
	}
}

/* ─────────────────────────── 4. Sold listings ─────────────────────────── */

if ( ! function_exists( 'db_sold_listings' ) ) {
	/** Every listing that is sold, any source. @return WP_Post[] */
	function db_sold_listings() {
		$q = new WP_Query( array(
			'post_type'      => 'domain',
			'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'unpublish', 'future' ),
			'posts_per_page' => 3000,
			'no_found_rows'  => true,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => 'db_listing_state', 'value' => 'sold' ),
				array( 'key' => 'domain_status', 'value' => 'sold', 'compare' => 'LIKE' ),
			),
		) );
		return array_values( array_filter( $q->posts, static function ( $p ) {
			return 'sold' === db_listing_state( $p ) || 'sold' === strtolower( trim( (string) get_post_meta( $p->ID, 'domain_status', true ) ) );
		} ) );
	}
}

if ( ! function_exists( 'db_sold_info' ) ) {
	/**
	 * What is known about one sale. A website order (in the current Stripe mode)
	 * wins; otherwise the details saved on the listing.
	 *
	 * price     what it was sold for (a plan: the agreed price)
	 * collected money actually received so far (a plan: what has been paid)
	 *
	 * @return array{price:float,collected:float,date:int,buyer:string,via:string,order:object|null,detail:string}
	 */
	function db_sold_info( $post, $order = null ) {
		$price = $post ? (float) get_post_meta( $post->ID, 'db_sold_price', true ) : 0.0;
		$date  = $post ? (int) get_post_meta( $post->ID, 'db_sold_at', true ) : 0;
		$buyer = $post ? (string) get_post_meta( $post->ID, 'db_sold_buyer', true ) : '';
		$via   = $post ? (string) get_post_meta( $post->ID, 'db_sold_via', true ) : '';
		$coll  = $price;
		$detail = '';
		if ( $order ) {
			$live   = db_orders_live( $order );
			$price  = (float) $order->price > 0 ? (float) $order->price : $live['paid'];
			$coll   = $live['paid'];
			$detail = $live['detail'];
			$buyer  = $buyer ?: (string) $order->buyer_email;
			$date   = $date ?: (int) strtotime( $order->created_at . ' UTC' );
			$via    = 'custom_offer' === $order->kind ? 'custom_offer' : ( (int) $order->plan_months > 0 ? 'website_plan' : 'website' );
			if ( (int) $order->plan_months < 1 ) {
				$price = $coll;
			}
		} elseif ( '' === $via ) {
			$via = $price > 0 ? 'manual' : 'legacy';
		}
		return array( 'price' => $price, 'collected' => $coll, 'date' => $date, 'buyer' => $buyer, 'via' => $via, 'order' => $order, 'detail' => $detail );
	}
}

if ( ! function_exists( 'db_sold_via_label' ) ) {
	function db_sold_via_label( $via ) {
		return array( 'website' => 'Website: paid in full', 'website_plan' => 'Website: payment plan', 'custom_offer' => 'Website: custom offer', 'manual' => 'Entered by you', 'legacy' => 'Earlier sale' )[ $via ] ?? $via;
	}
}

if ( ! function_exists( 'db_sold_money' ) ) {
	function db_sold_money( $n ) {
		return '$' . number_format( (float) $n, 2 );
	}
}

/* ─────────────────────────── 5. The report ────────────────────────────── */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Sold domains', 'Sold domains', 'manage_options', 'db-sold', 'db_sold_page' );
}, 22 );

if ( ! function_exists( 'db_sold_rows' ) ) {
	/** One row per sold or reserved domain: sold listings plus website orders (plans that ended in default are left out). */
	function db_sold_rows( $q = '' ) {
		global $wpdb;
		$by = array();
		foreach ( db_sold_listings() as $p ) {
			$by[ strtolower( trim( wp_strip_all_tags( $p->post_title ) ) ) ] = array( 'post' => $p, 'order' => null );
		}
		if ( function_exists( 'db_orders_table' ) ) {
			$t = db_orders_table();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$orders = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE mode = %s ORDER BY id DESC", db_orders_mode() ) );
			foreach ( $orders as $o ) {
				$name = strtolower( trim( $o->domain ) );
				if ( isset( $by[ $name ]['order'] ) && $by[ $name ]['order'] ) {
					continue; // newest order per domain only
				}
				if ( (int) $o->plan_months > 0 && 'plan_terminated' === db_orders_live( $o )['status'] ) {
					continue; // defaulted: the domain went back on sale
				}
				$by[ $name ] = array( 'post' => $by[ $name ]['post'] ?? ( function_exists( 'db_listing_post_for_domain' ) ? db_listing_post_for_domain( $name ) : null ), 'order' => $o );
			}
		}
		$rows = array();
		foreach ( $by as $name => $d ) {
			if ( '' !== $q && false === stripos( $name, $q ) ) {
				continue;
			}
			$rows[] = array( 'post' => $d['post'], 'name' => $name, 'info' => db_sold_info( $d['post'], $d['order'] ), 'epp' => db_epp_row( 'domain = %s', $name ) );
		}
		usort( $rows, static function ( $a, $b ) {
			return ( $b['info']['date'] <=> $a['info']['date'] ) ?: strcmp( $a['name'], $b['name'] );
		} );
		return $rows;
	}
}

if ( ! function_exists( 'db_sold_page' ) ) {
	function db_sold_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$msg  = isset( $_GET['msg'] ) ? sanitize_text_field( wp_unslash( $_GET['msg'] ) ) : '';
		$bad  = isset( $_GET['bad'] );
		// phpcs:enable
		$rows = db_sold_rows( $q );
		$n    = count( $rows );
		$priced = 0;
		$total  = 0.0;
		$coll   = 0.0;
		foreach ( $rows as $r ) {
			if ( $r['info']['price'] > 0 ) {
				$priced++;
				$total += $r['info']['price'];
			}
			$coll += $r['info']['collected'];
		}
		$act = admin_url( 'admin-post.php' );

		echo '<div class="wrap"><h1>Sold domains</h1>';
		if ( $msg ) {
			echo '<div class="notice notice-' . ( $bad ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		echo '<style>.dbs-tiles{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0}.dbs-tile{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 18px;min-width:160px}.dbs-tile b{display:block;font-size:22px}.dbs-sub{color:#646970;font-size:12px}.dbs-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;background:#eef2f7;color:#334155}.dbs-pill.sent{background:#fff4e0;color:#8a5a00}.dbs-pill.viewed{background:#e7f6ec;color:#137a3e}.dbs-pill.none{background:#fee2e2;color:#991b1b}details.dbs-epp summary{cursor:pointer;color:#2271b1}details.dbs-epp form{margin-top:8px;max-width:340px}details.dbs-epp input[type=text],details.dbs-epp input[type=email]{width:100%;margin-bottom:6px}</style>';
		printf(
			'<div class="dbs-tiles"><div class="dbs-tile"><span>Domains sold</span><b>%d</b></div><div class="dbs-tile"><span>With a price recorded</span><b>%d</b></div><div class="dbs-tile"><span>Total sold for</span><b>%s</b></div><div class="dbs-tile"><span>Money collected</span><b>%s</b></div><div class="dbs-tile"><span>Average price</span><b>%s</b></div></div>',
			(int) $n, (int) $priced, esc_html( db_sold_money( $total ) ), esc_html( db_sold_money( $coll ) ), esc_html( $priced ? db_sold_money( $total / $priced ) : '-' )
		);
		if ( $n > $priced ) {
			echo '<p class="dbs-sub">' . (int) ( $n - $priced ) . ' sold domain(s) have no price recorded, so the totals do not include them. Enter prices below or on each domain\'s edit screen. "Money collected" counts only what has actually been paid (for a payment plan, the instalments so far).</p>';
		}

		echo '<form method="get" style="margin:10px 0"><input type="hidden" name="page" value="db-sold"><input type="search" name="q" value="' . esc_attr( $q ) . '" placeholder="Domain"> <button class="button">Search</button> ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=db_sold_export&q=' . rawurlencode( $q ) ), 'db_sold_export' ) ) . '">Export CSV</a></form>';

		echo '<table class="widefat striped"><thead><tr><th>Domain</th><th>Sold for</th><th>Collected</th><th>Sold on</th><th>How</th><th>Buyer</th><th>Transfer (EPP) code</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">No sold domains.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$i    = $r['info'];
			$epp  = $r['epp'];
			$elig = db_epp_eligibility( $r['name'] );
			echo '<tr><td><strong>' . esc_html( $r['name'] ) . '</strong></td>';
			echo '<td>' . ( $i['price'] > 0 ? esc_html( db_sold_money( $i['price'] ) ) : '<span class="dbs-sub">not recorded</span>' ) . '</td>';
			echo '<td>' . ( $i['collected'] > 0 ? esc_html( db_sold_money( $i['collected'] ) ) : '<span class="dbs-sub">-</span>' ) . ( $i['detail'] ? '<br><span class="dbs-sub">' . esc_html( $i['detail'] ) . '</span>' : '' ) . '</td>';
			echo '<td>' . ( $i['date'] ? esc_html( wp_date( 'M j, Y', $i['date'] ) ) : '<span class="dbs-sub">-</span>' ) . '</td>';
			echo '<td>' . esc_html( db_sold_via_label( $i['via'] ) ) . '</td>';
			echo '<td>' . ( $i['buyer'] ? '<a href="mailto:' . esc_attr( $i['buyer'] ) . '">' . esc_html( $i['buyer'] ) . '</a>' : '<span class="dbs-sub">not recorded</span>' ) . '</td>';

			// EPP.
			echo '<td>';
			if ( $epp && 'revoked' !== $epp->status ) {
				$st = 'viewed' === $epp->status ? 'viewed' : 'sent';
				echo '<span class="dbs-pill ' . esc_attr( $st ) . '">' . ( 'viewed' === $st ? 'Viewed ' . esc_html( wp_date( 'M j', strtotime( $epp->viewed_at . ' UTC' ) ) ) : 'Sent ' . esc_html( wp_date( 'M j', strtotime( $epp->sent_at . ' UTC' ) ) ) . ', not opened yet' ) . '</span>';
			} else {
				echo '<span class="dbs-pill none">Not sent</span>';
			}
			if ( $epp && 'revoked' !== $epp->status && $epp->code_enc ) {
				// The private page, for sharing by another channel if email is down. The page still asks "Show my code".
				echo '<br><a class="dbs-eppurl" href="' . esc_url( db_epp_url( $epp->token ) ) . '" target="_blank" rel="noopener">Private link</a> <span class="dbs-sub">to ' . esc_html( $epp->buyer_email ) . '</span>';
			}
			if ( ! $elig['ok'] ) {
				echo '<br><span class="dbs-sub">' . esc_html( $elig['why'] ) . '</span>';
			} else {
				echo '<details class="dbs-epp"><summary>' . ( $epp ? 'Send again' : 'Send code' ) . '</summary><form method="post" action="' . esc_url( $act ) . '" autocomplete="off">'
					. wp_nonce_field( 'db_epp_send', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="db_epp_send"><input type="hidden" name="domain" value="' . esc_attr( $r['name'] ) . '">'
					. '<input type="email" name="email" required placeholder="Buyer email" value="' . esc_attr( $i['buyer'] ) . '">'
					. '<input type="text" name="code" required placeholder="EPP / unlock code" autocomplete="off" spellcheck="false">'
					. '<input type="text" name="note" placeholder="Note to the buyer (optional)">'
					. '<button class="button button-primary">Email the secure link</button></form></details>';
			}
			echo '</td>';
			echo '<td>' . ( $r['post'] ? '<a href="' . esc_url( get_edit_post_link( $r['post']->ID ) ) . '">Edit</a>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// Price import.
		echo '<div style="max-width:760px;margin-top:26px"><h2>Fill in earlier sale prices</h2><p>One sale per line: <code>domain, price, date (YYYY-MM-DD, optional), buyer email (optional)</code>. Only domains that are already marked sold are updated.</p>';
		echo '<form method="post" action="' . esc_url( $act ) . '">' . wp_nonce_field( 'db_sold_import', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="db_sold_import"><textarea name="lines" rows="6" class="large-text code" placeholder="wingcms.com, 1500, 2023-09-11"></textarea><p><button class="button">Save prices</button></p></form></div>';
		echo '</div>';
	}
}

add_action( 'admin_post_db_sold_export', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_sold_export' ) ) {
		wp_die( 'Not allowed.' );
	}
	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="db-sold-domains-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Domain', 'Sold for', 'Money collected', 'Sold on', 'How', 'Buyer email', 'EPP code status' ) );
	$safe = static function ( $v ) { $v = (string) $v; return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v; };
	foreach ( db_sold_rows( $q ) as $r ) {
		$i = $r['info'];
		fputcsv( $out, array( $safe( $r['name'] ), $i['price'] > 0 ? $i['price'] : '', $i['collected'] > 0 ? $i['collected'] : '', $i['date'] ? gmdate( 'Y-m-d', $i['date'] ) : '', db_sold_via_label( $i['via'] ), $safe( $i['buyer'] ), $r['epp'] ? $r['epp']->status : 'not sent' ) );
	}
	fclose( $out );
	exit;
} );

add_action( 'admin_post_db_sold_import', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_sold_import' ) ) {
		wp_die( 'Not allowed.' );
	}
	$lines = isset( $_POST['lines'] ) ? preg_split( '/\r\n|\r|\n/', (string) wp_unslash( $_POST['lines'] ) ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is cleaned below.
	$sold  = array();
	foreach ( db_sold_listings() as $p ) {
		$sold[ strtolower( trim( wp_strip_all_tags( $p->post_title ) ) ) ] = $p;
	}
	$ok = 0; $skip = array();
	foreach ( array_slice( $lines, 0, 500 ) as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}
		$f      = array_map( 'trim', str_getcsv( $line ) );
		$domain = strtolower( sanitize_text_field( $f[0] ?? '' ) );
		$price  = preg_replace( '/[^0-9.]/', '', (string) ( $f[1] ?? '' ) );
		if ( ! isset( $sold[ $domain ] ) || '' === $price || ! is_numeric( $price ) || (float) $price <= 0 ) {
			$skip[] = $domain ?: '(blank)';
			continue;
		}
		$id = $sold[ $domain ]->ID;
		update_post_meta( $id, 'db_sold_price', round( (float) $price, 2 ) );
		update_post_meta( $id, 'db_sold_via', 'manual' );
		if ( ! empty( $f[2] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $f[2] ) ) {
			update_post_meta( $id, 'db_sold_at', strtotime( $f[2] . ' 12:00:00 UTC' ) );
		}
		if ( ! empty( $f[3] ) && is_email( $f[3] ) ) {
			update_post_meta( $id, 'db_sold_buyer', sanitize_email( $f[3] ) );
		}
		$ok++;
	}
	$msg = 'Saved ' . $ok . ' price(s).' . ( $skip ? ' Skipped (not sold, or no valid price): ' . implode( ', ', array_slice( $skip, 0, 10 ) ) . ( count( $skip ) > 10 ? '…' : '' ) : '' );
	wp_safe_redirect( add_query_arg( array( 'msg' => rawurlencode( $msg ) ) + ( $skip && ! $ok ? array( 'bad' => 1 ) : array() ), admin_url( 'admin.php?page=db-sold' ) ) );
	exit;
} );

/* ───────────────────────── 6. Sending the code ────────────────────────── */

if ( ! function_exists( 'db_epp_url' ) ) {
	function db_epp_url( $token ) {
		return add_query_arg( 'db_epp', $token, home_url( '/' ) );
	}
}

add_action( 'admin_post_db_epp_send', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_epp_send' ) ) {
		wp_die( 'Not allowed.' );
	}
	global $wpdb;
	$back   = static function ( $msg, $bad = false ) {
		wp_safe_redirect( add_query_arg( array( 'msg' => rawurlencode( $msg ) ) + ( $bad ? array( 'bad' => 1 ) : array() ), admin_url( 'admin.php?page=db-sold' ) ) );
		exit;
	};
	$domain = isset( $_POST['domain'] ) ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['domain'] ) ) ) ) : '';
	$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
	$code   = db_epp_clean_code( isset( $_POST['code'] ) ? wp_unslash( $_POST['code'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by db_epp_clean_code(); a code may contain any symbol.
	$note   = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '';
	$note   = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 300 ) : substr( $note, 0, 300 );

	$sold = false;
	foreach ( db_sold_listings() as $p ) {
		if ( strtolower( trim( wp_strip_all_tags( $p->post_title ) ) ) === $domain ) {
			$sold = $p;
			break;
		}
	}
	if ( ! $sold ) {
		$back( $domain . ' is not marked sold.', true );
	}
	if ( ! is_email( $email ) ) {
		$back( 'Enter the buyer\'s email address.', true );
	}
	if ( null === $code ) {
		$back( 'Enter the code with no spaces (up to 120 characters).', true );
	}
	$elig = db_epp_eligibility( $domain );
	if ( ! $elig['ok'] ) {
		$back( $elig['why'], true );
	}
	$enc = db_epp_encrypt( $code );
	if ( false === $enc ) {
		$back( 'This server cannot encrypt the code (OpenSSL is missing), so it was not saved or sent.', true );
	}

	$t = $wpdb->prefix . 'db_epp';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->update( $t, array( 'status' => 'revoked', 'code_enc' => null ), array( 'domain' => $domain ) ); // an earlier link for this domain stops working
	$token = bin2hex( random_bytes( 16 ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( $t, array(
		'domain'      => $domain,
		'buyer_email' => $email,
		'code_enc'    => $enc,
		'token'       => $token,
		'note'        => $note,
		'status'      => 'sent',
		'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + DB_EPP_LINK_DAYS * DAY_IN_SECONDS ),
	) );
	update_post_meta( $sold->ID, 'db_sold_buyer', $email );

	$sent = function_exists( 'db_mail_send' ) ? db_mail_send( 'transfer_code', $email, array( 'domain' => $domain, 'note_html' => '' !== $note ? '<p>' . esc_html( $note ) . '</p>' : '', 'days' => (string) (int) DB_EPP_LINK_DAYS, 'code_url' => db_epp_url( $token ) ), DB_PLAN_SUPPORT_EMAIL ) : null;
	if ( null === $sent && function_exists( 'db_plan_email_shell' ) && function_exists( 'db_plan_send' ) ) {
		$paras = '<p style="font-size:15px;color:#374151;margin:0 0 12px;">The transfer (EPP / unlock) code for <strong>' . esc_html( $domain ) . '</strong> is ready. For your security it is not in this email: open the private page below and press "Show my code".</p>'
			. ( '' !== $note ? '<p style="font-size:15px;color:#374151;margin:0 0 12px;border-left:3px solid #d1d5db;padding-left:12px;">' . esc_html( $note ) . '</p>' : '' )
			. '<p style="font-size:13px;color:#6b7280;margin:0 0 12px;">The link works for ' . (int) DB_EPP_LINK_DAYS . ' days. Do not forward it.</p>';
		$sent  = db_plan_send( $email, 'Your transfer code for ' . $domain, db_plan_email_shell( 'Your transfer code is ready', $paras, array( 'Domain' => $domain ), 'Get my transfer code', db_epp_url( $token ), 'good' ) );
	}
	$sent = (bool) $sent;
	$back( $sent ? 'Done. ' . $email . ' has been emailed a secure link to the code for ' . $domain . '.' : 'The code is saved, but the email could not be sent (see the email warning above). Fix email, then press Send again.', ! $sent );
} );

/* ─────────────────────── 7. The buyer's private page ──────────────────── */

add_action( 'template_redirect', function () {
	if ( empty( $_GET['db_epp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	global $wpdb;
	nocache_headers();
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Referrer-Policy: no-referrer' );
	$token = strtolower( sanitize_text_field( wp_unslash( $_GET['db_epp'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$row   = preg_match( '/^[0-9a-f]{32}$/', $token ) ? db_epp_row( 'token = %s', $token ) : null;
	$live  = $row && 'revoked' !== $row->status && $row->code_enc && $row->expires_at && strtotime( $row->expires_at . ' UTC' ) > time();
	$code  = '';
	$shown = false;

	if ( $live && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && ! empty( $_POST['reveal'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- the private token in the URL is the credential.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'db_epp_rl_' . md5( $ip . $token );
		$n   = (int) get_transient( $key );
		if ( $n < 10 ) {
			set_transient( $key, $n + 1, HOUR_IN_SECONDS );
			$plain = db_epp_decrypt( $row->code_enc );
			if ( false !== $plain ) {
				$code  = $plain;
				$shown = true;
				if ( 'viewed' !== $row->status ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->update( $wpdb->prefix . 'db_epp', array( 'status' => 'viewed', 'viewed_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $row->id ) );
				}
			}
		}
	}

	status_header( $live ? 200 : 410 );
	header( 'Content-Type: text/html; charset=UTF-8' );
	$logo = function_exists( 'db_brand_logo_url' ) ? db_brand_logo_url() : home_url( '/wp-content/uploads/2017/03/Domain-Brothers.png' );
	echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Your transfer code | Domain Brothers</title><style>'
		. '*{box-sizing:border-box}body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f6f8fb;color:#0a1628;line-height:1.55}'
		. 'header{background:#fff;border-bottom:1px solid #e5eaf1;padding:12px 24px}header img{height:40px;display:block}'
		. 'main{max-width:560px;margin:32px auto;padding:0 16px}.card{background:#fff;border:1px solid #e5eaf1;border-radius:18px;padding:clamp(22px,5vw,36px);box-shadow:0 10px 30px rgba(10,22,40,.08)}'
		. 'h1{font-size:24px;margin:0 0 6px}.dom{color:#2e7d14;font-weight:800;overflow-wrap:anywhere}p{margin:10px 0}'
		. 'button{min-height:52px;width:100%;border:0;border-radius:10px;background:#0a1628;color:#fff;font-size:16px;font-weight:700;cursor:pointer}button:focus-visible{outline:3px solid #2e7d14;outline-offset:2px}'
		. '.code{margin:14px 0;padding:16px;border:2px dashed #2e7d14;border-radius:12px;background:#f1f8ee;font:700 20px/1.3 ui-monospace,Menlo,Consolas,monospace;word-break:break-all;text-align:center;user-select:all}'
		. 'ol{padding-left:20px}li{margin:6px 0}.note{font-size:14px;color:#4b5563}.warn{font-size:13px;color:#4b5563;margin-top:16px}'
		. '</style></head><body><header><img src="' . esc_url( $logo ) . '" alt="Domain Brothers" width="148" height="40"></header><main><div class="card">';
	if ( ! $live ) {
		echo '<h1>This link is no longer active</h1><p>It may have expired or been replaced by a newer one. Email <a href="mailto:sales@domainbrothers.com">sales@domainbrothers.com</a> and we will send a new one.</p>';
	} else {
		echo '<h1>Transfer code for <span class="dom">' . esc_html( $row->domain ) . '</span></h1>';
		if ( $shown ) {
			echo '<div class="code" id="db-epp-code">' . esc_html( $code ) . '</div><button type="button" id="db-epp-copy">Copy code</button>';
			if ( '' !== trim( (string) $row->note ) ) {
				echo '<p class="note">' . esc_html( $row->note ) . '</p>';
			}
			echo '<h2 style="font-size:17px;margin:22px 0 6px">What to do next</h2><ol><li>Log in to the registrar you are moving the domain to.</li><li>Start a domain transfer for <strong>' . esc_html( $row->domain ) . '</strong> and paste this code when asked.</li><li>Approve the confirmation emails your registrar sends. Transfers usually finish within 5 to 7 days.</li></ol>';
			echo '<script>document.getElementById("db-epp-copy").addEventListener("click",function(){var t=document.getElementById("db-epp-code").textContent,b=this;(navigator.clipboard?navigator.clipboard.writeText(t):Promise.reject()).then(function(){b.textContent="Copied"},function(){var r=document.createRange();r.selectNodeContents(document.getElementById("db-epp-code"));var s=getSelection();s.removeAllRanges();s.addRange(r);b.textContent="Press Ctrl+C to copy"})});</script>';
		} else {
			echo '<p>Your domain is ready to transfer. Press the button to show your code. Keep it private: anyone with it can move your domain.</p><form method="post" action="' . esc_url( add_query_arg( 'db_epp', $token, home_url( '/' ) ) ) . '"><input type="hidden" name="reveal" value="1"><button type="submit">Show my code</button></form>';
		}
		echo '<p class="warn">This page is private to you and expires on ' . esc_html( wp_date( 'F j, Y', strtotime( $row->expires_at . ' UTC' ) ) ) . '.</p>';
	}
	echo '</div></main></body></html>';
	exit;
}, 1 );
