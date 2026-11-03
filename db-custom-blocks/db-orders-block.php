<?php
/**
 * Orders: every purchase made on the website, listed in the admin.
 *
 * Until now a paid purchase left only a timestamp in wp_options, so the back
 * end could not show what had been bought, how, or by whom. This block keeps a
 * row per purchase in wp_db_orders and shows them under Domain Brothers >
 * Sales, with the kind of sale:
 *
 *   standard      bought at the listed fixed price, paid in full;
 *   plan          a payment plan (3, 6, 9 or 12 monthly payments);
 *   custom_offer  bought at a price the team and the buyer agreed
 *                 (full or on a plan; see db-offers-block.php).
 *
 * Rows are written from the Stripe webhook through the db_order_event action.
 * For a payment plan the row stores what was paid at the last event; the Sales
 * screen reads the plan's live record for current status, payments made and
 * the next charge.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_ORDERS_TABLE_VERSION' ) ) {
	define( 'DB_ORDERS_TABLE_VERSION', '1' );
}

/* ───────────────────────────── 1. Table ───────────────────────────────── */

if ( ! function_exists( 'db_orders_table' ) ) {
	function db_orders_table() {
		global $wpdb;
		return $wpdb->prefix . 'db_orders';
	}
}

add_action( 'init', function () {
	if ( get_option( 'db_orders_table_version', '' ) === DB_ORDERS_TABLE_VERSION ) {
		return;
	}
	global $wpdb;
	$table   = db_orders_table();
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( "CREATE TABLE {$table} (
		id           INT           NOT NULL AUTO_INCREMENT,
		created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		mode         VARCHAR(8)    NOT NULL DEFAULT 'live',
		domain       VARCHAR(255)  NOT NULL DEFAULT '',
		kind         VARCHAR(16)   NOT NULL DEFAULT 'standard',
		price        DECIMAL(12,2) NOT NULL DEFAULT 0,
		paid         DECIMAL(12,2) NOT NULL DEFAULT 0,
		plan_months  SMALLINT      NOT NULL DEFAULT 0,
		buyer_email  VARCHAR(255)  NOT NULL DEFAULT '',
		pi_id        VARCHAR(80)   NOT NULL DEFAULT '',
		lead_id      INT           NOT NULL DEFAULT 0,
		status       VARCHAR(20)   NOT NULL DEFAULT 'paid',
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_pi (pi_id),
		KEY idx_domain (domain(40)),
		KEY idx_kind (kind),
		KEY idx_mode (mode)
	) {$charset};" );
	update_option( 'db_orders_table_version', DB_ORDERS_TABLE_VERSION );
} );

/* ───────────────────────────── 2. Recording ───────────────────────────── */

if ( ! function_exists( 'db_orders_kinds' ) ) {
	function db_orders_kinds() {
		return array( 'standard' => 'Standard price', 'plan' => 'Payment plan', 'custom_offer' => 'Custom offer' );
	}
}

if ( ! function_exists( 'db_orders_mode' ) ) {
	function db_orders_mode() {
		return function_exists( 'db_stripe_mode' ) && 'test' === db_stripe_mode() ? 'test' : 'live';
	}
}

if ( ! function_exists( 'db_orders_find_open_plan' ) ) {
	/** The newest plan order for $domain in $mode that is not finished. */
	function db_orders_find_open_plan( $domain, $mode ) {
		global $wpdb;
		$t = db_orders_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE domain = %s AND mode = %s AND plan_months > 0 AND status IN ('plan_active','plan_past_due') ORDER BY id DESC LIMIT 1", $domain, $mode ) );
	}
}

/**
 * Webhook events.
 *   'full'              d: domain, amount_usd, customer_email, pi_id, meta
 *   'plan_installment'  d: domain, amount_usd, customer_email, pi_id, meta,
 *                          installment, total_months, total_price
 */
add_action( 'db_order_event', function ( $event, array $d ) {
	global $wpdb;
	$t       = db_orders_table();
	$mode    = db_orders_mode();
	$domain  = strtolower( trim( (string) ( $d['domain'] ?? '' ) ) );
	$meta    = (array) ( $d['meta'] ?? array() );
	$lead_id = isset( $meta['lead_id'] ) ? (int) $meta['lead_id'] : 0;
	$email   = sanitize_email( (string) ( $d['customer_email'] ?? '' ) );
	$pi      = sanitize_text_field( (string) ( $d['pi_id'] ?? '' ) );
	$amount  = round( (float) ( $d['amount_usd'] ?? 0 ), 2 );
	if ( '' === $domain ) {
		return;
	}

	if ( 'full' === $event ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$t} (mode, domain, kind, price, paid, plan_months, buyer_email, pi_id, lead_id, status) VALUES (%s,%s,%s,%f,%f,0,%s,%s,%d,'paid')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$mode, $domain, $lead_id ? 'custom_offer' : 'standard', $amount, $amount, $email, $pi, $lead_id
		) );
		return;
	}

	if ( 'plan_installment' === $event ) {
		$n      = (int) ( $d['installment'] ?? 1 );
		$months = (int) ( $d['total_months'] ?? 0 );
		$price  = round( (float) ( $d['total_price'] ?? 0 ), 2 );
		if ( 1 === $n ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare(
				"INSERT IGNORE INTO {$t} (mode, domain, kind, price, paid, plan_months, buyer_email, pi_id, lead_id, status) VALUES (%s,%s,%s,%f,%f,%d,%s,%s,%d,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$mode, $domain, $lead_id ? 'custom_offer' : 'plan', $price, $amount, $months, $email, $pi, $lead_id, $months <= 1 ? 'plan_completed' : 'plan_active'
			) );
			return;
		}
		$row = db_orders_find_open_plan( $domain, $mode );
		if ( $row ) {
			$status = $n >= $months ? 'plan_completed' : 'plan_active';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET paid = paid + %f, status = %s WHERE id = %d", $amount, $status, $row->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
	}
}, 10, 2 );

/* ───────────────────────── 3. Live plan status ────────────────────────── */

if ( ! function_exists( 'db_orders_live' ) ) {
	/**
	 * Current state of an order row.
	 *
	 * @return array{status:string,label:string,detail:string,paid:float}
	 */
	function db_orders_live( $row ) {
		$paid = (float) $row->paid;
		if ( (int) $row->plan_months < 1 ) {
			return array( 'status' => 'paid', 'label' => 'Paid in full', 'detail' => '', 'paid' => $paid );
		}
		$prefix = 'test' === $row->mode ? 'db_stripe_test_' : 'db_stripe_';
		$plan   = (array) get_option( $prefix . 'plan_' . md5( strtolower( trim( $row->domain ) ) ), array() );
		$pis    = (array) ( $plan['pi_ids'] ?? array() );
		$same   = $plan && ( '' === $row->pi_id || in_array( $row->pi_id, $pis, true ) || empty( $pis ) );
		$months = (int) $row->plan_months;
		if ( ! $same ) {
			$st = (string) $row->status;
			return array( 'status' => $st, 'label' => ucwords( str_replace( '_', ' ', $st ) ), 'detail' => '', 'paid' => $paid );
		}
		$made = (int) ( $plan['paid_installments'] ?? 0 );
		$sum  = 0.0;
		foreach ( (array) ( $plan['history'] ?? array() ) as $h ) {
			$sum += (float) ( $h['amount_usd'] ?? 0 );
		}
		$ps = (string) ( $plan['status'] ?? $row->status );
		$labels = array(
			'active'     => 'Plan active',
			'past_due'   => 'Payment overdue',
			'completed'  => 'Plan completed',
			'terminated' => 'Plan terminated',
			'plan_active' => 'Plan active', 'plan_past_due' => 'Payment overdue', 'plan_completed' => 'Plan completed', 'plan_terminated' => 'Plan terminated',
		);
		$detail = sprintf( '%d of %d paid', $made ?: 1, $months );
		if ( in_array( $ps, array( 'active', 'past_due' ), true ) && ! empty( $plan['next_charge_ts'] ) ) {
			$detail .= '; next charge ' . wp_date( 'M j', (int) $plan['next_charge_ts'] );
		}
		return array(
			'status' => 'plan_' . preg_replace( '/^plan_/', '', $ps ),
			'label'  => $labels[ $ps ] ?? ucwords( str_replace( '_', ' ', $ps ) ),
			'detail' => $detail,
			'paid'   => $sum > 0 ? $sum : $paid,
		);
	}
}

/* ───────────────────────────── 4. Admin screen ────────────────────────── */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Sales', 'Sales', 'manage_options', 'db-sales', 'db_orders_page' );
}, 20 );

if ( ! function_exists( 'db_orders_query' ) ) {
	/** @return array{rows:array,total:int} */
	function db_orders_query( array $f, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$t     = db_orders_table();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['mode'] ) && in_array( $f['mode'], array( 'test', 'live' ), true ) ) {
			$where[] = 'mode = %s';
			$args[]  = $f['mode'];
		}
		if ( ! empty( $f['kind'] ) && isset( db_orders_kinds()[ $f['kind'] ] ) ) {
			$where[] = 'kind = %s';
			$args[]  = $f['kind'];
		}
		if ( ! empty( $f['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$where[] = '(domain LIKE %s OR buyer_email LIKE %s)';
			$args[]  = $like;
			$args[]  = $like;
		}
		$w = implode( ' AND ', $where );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$w}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$w}" ) );
		$qargs = array_merge( $args, array( (int) $limit, (int) $offset ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", $qargs ) );
		// phpcs:enable
		return array( 'rows' => (array) $rows, 'total' => $total );
	}
}

if ( ! function_exists( 'db_orders_filters' ) ) {
	function db_orders_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : db_orders_mode();
		return array(
			'mode' => in_array( $mode, array( 'test', 'live' ), true ) ? $mode : '',
			'kind' => isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '',
			'q'    => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
		);
		// phpcs:enable
	}
}

if ( ! function_exists( 'db_orders_page' ) ) {
	function db_orders_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$f      = db_orders_filters();
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 50;
		$res    = db_orders_query( $f, $per, ( $paged - 1 ) * $per );
		$kinds  = db_orders_kinds();
		$base   = admin_url( 'admin.php?page=db-sales' );
		$notice = isset( $_GET['imported'] ) ? (int) $_GET['imported'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Summary of what matches the filters (not just this page).
		$all = db_orders_query( $f, 5000, 0 );
		$sum = array( 'n' => $all['total'], 'collected' => 0.0, 'plans' => 0, 'overdue' => 0 );
		foreach ( $all['rows'] as $r ) {
			$live = db_orders_live( $r );
			$sum['collected'] += $live['paid'];
			if ( (int) $r->plan_months > 0 && 'active' === preg_replace( '/^plan_/', '', $live['status'] ) ) {
				$sum['plans']++;
			}
			if ( 'past_due' === preg_replace( '/^plan_/', '', $live['status'] ) ) {
				$sum['overdue']++;
			}
		}

		echo '<div class="wrap"><h1>Sales</h1>';
		if ( $notice >= 0 ) {
			echo '<div class="notice notice-success is-dismissible"><p>Imported ' . (int) $notice . ' earlier payment plan(s).</p></div>';
		}
		echo '<style>.dbs-tiles{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0}.dbs-tile{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 18px;min-width:150px}.dbs-tile b{display:block;font-size:22px}.dbs-pill{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600;background:#eef2f7;color:#334155}.dbs-pill.paid,.dbs-pill.plan_completed,.dbs-pill.completed{background:#e7f6ec;color:#137a3e}.dbs-pill.active,.dbs-pill.plan_active{background:#e0f0ff;color:#0a5aa8}.dbs-pill.past_due,.dbs-pill.plan_past_due{background:#fff4e0;color:#8a5a00}.dbs-pill.terminated,.dbs-pill.plan_terminated{background:#fee2e2;color:#991b1b}.dbs-kind{font-weight:600}.dbs-sub{color:#646970;font-size:12px}</style>';
		printf(
			'<div class="dbs-tiles"><div class="dbs-tile"><span>Orders</span><b>%d</b></div><div class="dbs-tile"><span>Collected</span><b>$%s</b></div><div class="dbs-tile"><span>Active plans</span><b>%d</b></div><div class="dbs-tile"><span>Overdue</span><b>%d</b></div></div>',
			(int) $sum['n'], esc_html( number_format( $sum['collected'], 2 ) ), (int) $sum['plans'], (int) $sum['overdue']
		);

		// Filters.
		echo '<form method="get" style="margin:10px 0"><input type="hidden" name="page" value="db-sales">';
		echo '<select name="mode"><option value="">Test and live</option><option value="live"' . selected( $f['mode'], 'live', false ) . '>Live</option><option value="test"' . selected( $f['mode'], 'test', false ) . '>Test</option></select> ';
		echo '<select name="kind"><option value="">All types</option>';
		foreach ( $kinds as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $f['kind'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <input type="search" name="q" value="' . esc_attr( $f['q'] ) . '" placeholder="Domain or email"> <button class="button">Filter</button> ';
		$export = wp_nonce_url( add_query_arg( array( 'action' => 'db_orders_export', 'mode' => $f['mode'], 'kind' => $f['kind'], 'q' => $f['q'] ), admin_url( 'admin-post.php' ) ), 'db_orders_export' );
		echo '<a class="button" href="' . esc_url( $export ) . '">Export CSV</a> ';
		$imp = wp_nonce_url( admin_url( 'admin-post.php?action=db_orders_import' ), 'db_orders_import' );
		echo '<a class="button" href="' . esc_url( $imp ) . '" title="Adds payment plans that started before this screen existed">Import earlier plans</a></form>';

		echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Domain</th><th>Type</th><th>Price</th><th>Paid so far</th><th>Buyer</th><th>Status</th><th>Links</th></tr></thead><tbody>';
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="8">No sales match. Purchases made on the website appear here once Stripe confirms the payment.</td></tr>';
		}
		foreach ( $res['rows'] as $r ) {
			$live = db_orders_live( $r );
			$kind = $kinds[ $r->kind ] ?? $r->kind;
			$sub  = (int) $r->plan_months > 0 ? $r->plan_months . '-month plan' : 'Paid once';
			$stripe = $r->pi_id ? 'https://dashboard.stripe.com/' . ( 'test' === $r->mode ? 'test/' : '' ) . 'payments/' . rawurlencode( $r->pi_id ) : '';
			echo '<tr>';
			echo '<td>' . esc_html( wp_date( 'M j, Y g:i a', strtotime( $r->created_at . ' UTC' ) ) ) . ( 'test' === $r->mode ? ' <span class="dbs-pill">test</span>' : '' ) . '</td>';
			echo '<td><strong>' . esc_html( $r->domain ) . '</strong></td>';
			echo '<td><span class="dbs-kind">' . esc_html( $kind ) . '</span><br><span class="dbs-sub">' . esc_html( $sub ) . '</span></td>';
			echo '<td>$' . esc_html( number_format( (float) $r->price, 2 ) ) . '</td>';
			echo '<td>$' . esc_html( number_format( $live['paid'], 2 ) ) . ( $live['detail'] ? '<br><span class="dbs-sub">' . esc_html( $live['detail'] ) . '</span>' : '' ) . '</td>';
			echo '<td>' . ( $r->buyer_email ? '<a href="mailto:' . esc_attr( $r->buyer_email ) . '">' . esc_html( $r->buyer_email ) . '</a>' : '<span class="dbs-sub">not recorded</span>' ) . '</td>';
			echo '<td><span class="dbs-pill ' . esc_attr( $live['status'] ) . '">' . esc_html( $live['label'] ) . '</span></td>';
			echo '<td>' . ( $stripe ? '<a href="' . esc_url( $stripe ) . '" target="_blank" rel="noopener">Stripe</a>' : '' )
				. ( $r->lead_id ? ' &middot; <a href="' . esc_url( admin_url( 'admin.php?page=db-crm-leads&s=' . rawurlencode( $r->buyer_email ?: $r->domain ) ) ) . '">Offer #' . (int) $r->lead_id . '</a>' : '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $res['total'] / $per );
		if ( $pages > 1 ) {
			echo '<p>' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $base ), 'format' => '', 'current' => $paged, 'total' => $pages, 'add_args' => array_filter( $f ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}
}

/* ───────────────────────────── 5. CSV + import ────────────────────────── */

add_action( 'admin_post_db_orders_export', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_orders_export' ) ) {
		wp_die( 'Not allowed.' );
	}
	$f = array(
		'mode' => isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '',
		'kind' => isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '',
		'q'    => isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '',
	);
	$res = db_orders_query( $f, 20000, 0 );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="db-sales-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Date (UTC)', 'Mode', 'Domain', 'Type', 'Plan months', 'Price', 'Paid so far', 'Status', 'Buyer email', 'Stripe payment', 'Offer #' ) );
	$kinds = db_orders_kinds();
	foreach ( $res['rows'] as $r ) {
		$live = db_orders_live( $r );
		// A cell starting with = + - @ would run as a formula when opened in a spreadsheet.
		$safe = static function ( $v ) { $v = (string) $v; return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v; };
		fputcsv( $out, array( $r->created_at, $r->mode, $safe( $r->domain ), $kinds[ $r->kind ] ?? $r->kind, $r->plan_months, $r->price, $live['paid'], $live['label'], $safe( $r->buyer_email ), $r->pi_id, $r->lead_id ?: '' ) );
	}
	fclose( $out );
	exit;
} );

add_action( 'admin_post_db_orders_import', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_orders_import' ) ) {
		wp_die( 'Not allowed.' );
	}
	global $wpdb;
	$t = db_orders_table();
	$n = 0;
	// Plans keep the domain, price, months, buyer and history inside the record.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$opts = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name REGEXP '^db_stripe_(test_)?plan_[0-9a-f]{32}$'" );
	foreach ( (array) $opts as $o ) {
		$plan = maybe_unserialize( $o->option_value );
		if ( ! is_array( $plan ) || empty( $plan['domain'] ) || empty( $plan['history'] ) ) {
			continue;
		}
		$first = (array) $plan['history'][0];
		$pi    = (string) ( $first['pi'] ?? '' );
		if ( '' === $pi ) {
			continue;
		}
		$paid = 0.0;
		foreach ( $plan['history'] as $h ) {
			$paid += (float) ( $h['amount_usd'] ?? 0 );
		}
		$ps   = (string) ( $plan['status'] ?? 'active' );
		$mode = ( 0 === strpos( $o->option_name, 'db_stripe_test_' ) ) ? 'test' : 'live';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$t} (created_at, mode, domain, kind, price, paid, plan_months, buyer_email, pi_id, status) VALUES (%s,%s,%s,'plan',%f,%f,%d,%s,%s,%s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			gmdate( 'Y-m-d H:i:s', (int) ( $first['ts'] ?? time() ) ), $mode, strtolower( (string) $plan['domain'] ), (float) ( $plan['price_usd'] ?? 0 ), $paid, (int) ( $plan['total_months'] ?? 0 ), sanitize_email( (string) ( $plan['customer_email'] ?? '' ) ), $pi, 'plan_' . $ps
		) );
		if ( $ok ) {
			$n++;
		}
	}
	wp_safe_redirect( admin_url( 'admin.php?page=db-sales&imported=' . $n ) );
	exit;
} );
