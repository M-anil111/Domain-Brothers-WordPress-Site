<?php
/**
 * Domain hits: how often each domain page is viewed, and by how many people.
 *
 *   hits     every view of the domain's page ("full hits")
 *   unique   different visitors who have viewed it ("unique hits")
 *
 * Counting is done by a small beacon the page sends after it has loaded
 * (REST route db/v1/hit), not by PHP while the page is built: production
 * pages are served from the LiteSpeed cache, and a counter in PHP would miss
 * every cached view.
 *
 * A visitor is a salted hash of IP address + browser string; the address
 * itself is never stored. Not counted: bots and link-preview fetchers,
 * logged-in editors (the team looking at its own pages), prefetches, pages
 * viewed in a background tab, and the same visitor on the same page twice in
 * ten seconds. Visitor hashes are deleted after 13 months; the daily totals
 * are kept.
 *
 * Domain Brothers > Domain hits shows the numbers per domain (all time, last
 * 7 and 30 days, last visit, offers received), sortable, searchable and
 * exportable; the domain list in the admin gets a Hits column.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_HITS_TABLE_VERSION' ) ) {
	define( 'DB_HITS_TABLE_VERSION', '2' );
}

/* ───────────────────────────── 1. Tables ──────────────────────────────── */

add_action( 'init', function () {
	if ( get_option( 'db_hits_table_version', '' ) !== DB_HITS_TABLE_VERSION ) {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$daily   = $wpdb->prefix . 'db_hits';
		$vis     = $wpdb->prefix . 'db_hit_visitors';
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( "CREATE TABLE {$daily} (
			post_id INT      NOT NULL,
			day     DATE     NOT NULL,
			hits    INT      NOT NULL DEFAULT 0,
			uniques INT      NOT NULL DEFAULT 0,
			PRIMARY KEY (post_id, day),
			KEY idx_day (day)
		) {$charset};" );
		dbDelta( "CREATE TABLE {$vis} (
			post_id  INT      NOT NULL,
			vhash    CHAR(16) NOT NULL,
			first_ts INT      NOT NULL DEFAULT 0,
			last_ts  INT      NOT NULL DEFAULT 0,
			hits     INT      NOT NULL DEFAULT 1,
			PRIMARY KEY (post_id, vhash),
			KEY idx_last (last_ts)
		) {$charset};" );
		dbDelta( "CREATE TABLE {$wpdb->prefix}db_hit_vdays (
			post_id INT      NOT NULL,
			day     DATE     NOT NULL,
			vhash   CHAR(16) NOT NULL,
			PRIMARY KEY (post_id, day, vhash),
			KEY idx_day (day)
		) {$charset};" );
		update_option( 'db_hits_table_version', DB_HITS_TABLE_VERSION );
	}
	if ( ! wp_next_scheduled( 'db_hits_purge' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'db_hits_purge' );
	}
} );

add_action( 'db_hits_purge', function () {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}db_hit_visitors WHERE last_ts < %d", time() - 400 * DAY_IN_SECONDS ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}db_hit_vdays WHERE day < %s", wp_date( 'Y-m-d', time() - 400 * DAY_IN_SECONDS ) ) );
} );

// Deleting a domain removes its counts, so the totals only describe live domains.
add_action( 'deleted_post', function ( $post_id ) {
	if ( 'domain' !== get_post_type( $post_id ) && get_post_type( $post_id ) ) {
		return;
	}
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->delete( $wpdb->prefix . 'db_hits', array( 'post_id' => (int) $post_id ), array( '%d' ) );
	$wpdb->delete( $wpdb->prefix . 'db_hit_visitors', array( 'post_id' => (int) $post_id ), array( '%d' ) );
	$wpdb->delete( $wpdb->prefix . 'db_hit_vdays', array( 'post_id' => (int) $post_id ), array( '%d' ) );
	// phpcs:enable
} );

add_action( 'db_hits_purge', function () {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( array( 'db_hits', 'db_hit_visitors', 'db_hit_vdays' ) as $t ) {
		$wpdb->query( "DELETE h FROM {$wpdb->prefix}{$t} h LEFT JOIN {$wpdb->posts} p ON p.ID = h.post_id WHERE p.ID IS NULL" );
	}
	// phpcs:enable
} );

/* ───────────────────────────── 2. Counting ────────────────────────────── */

if ( ! function_exists( 'db_hits_is_bot' ) ) {
	function db_hits_is_bot( $ua ) {
		$ua = (string) $ua;
		if ( strlen( $ua ) < 12 ) {
			return true; // empty or tiny: a script, not a browser
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|scrape|fetch|monitor|uptime|preview|headless|lighthouse|pingdom|curl|wget|python|java\/|go-http|httpclient|facebookexternalhit|whatsapp|telegram|discord|skype|embedly|bingpreview|yahoo|duckduck|baidu|yandex|semrush|ahrefs|mj12|petalbot|applebot/i', $ua );
	}
}

if ( ! function_exists( 'db_hits_visitor' ) ) {
	/** Salted hash of address + browser; the address is not kept. */
	function db_hits_visitor( $ip, $ua ) {
		return substr( hash( 'sha256', $ip . '|' . $ua . '|' . wp_salt( 'nonce' ) ), 0, 16 );
	}
}

if ( ! function_exists( 'db_hits_record' ) ) {
	/**
	 * Adds one view. Returns 'counted', 'repeat' (inside the 10-second window)
	 * or 'ignored'.
	 */
	function db_hits_record( $post_id, $vhash ) {
		global $wpdb;
		$key = 'db_hit_' . md5( $vhash . '|' . (int) $post_id );
		if ( get_transient( $key ) ) {
			return 'repeat';
		}
		set_transient( $key, 1, 10 );

		$now = time();
		$vt  = $wpdb->prefix . 'db_hit_visitors';
		$dt  = $wpdb->prefix . 'db_hits';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$seen = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$vt} WHERE post_id = %d AND vhash = %s", (int) $post_id, $vhash ) );
		$new  = $seen ? 0 : 1;
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$vt} (post_id, vhash, first_ts, last_ts, hits) VALUES (%d, %s, %d, %d, 1) ON DUPLICATE KEY UPDATE hits = hits + 1, last_ts = %d",
			(int) $post_id, $vhash, $now, $now, $now
		) );
		$wpdb->query( $wpdb->prepare(
			"INSERT INTO {$dt} (post_id, day, hits, uniques) VALUES (%d, %s, 1, %d) ON DUPLICATE KEY UPDATE hits = hits + 1, uniques = uniques + %d",
			(int) $post_id, wp_date( 'Y-m-d', $now ), $new, $new
		) );
		// Who visited on which day: lets "unique visitors" be counted for any period.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->prefix}db_hit_vdays (post_id, day, vhash) VALUES (%d, %s, %s)", (int) $post_id, wp_date( 'Y-m-d', $now ), $vhash ) );
		// phpcs:enable
		return 'counted';
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/hit', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$done = static function () {
				$res = new WP_REST_Response( null, 204 );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			};
			$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only hashed and pattern-matched.
			if ( db_hits_is_bot( $ua ) ) {
				return $done();
			}
			$purpose = strtolower( (string) ( $_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( false !== strpos( $purpose, 'prefetch' ) || false !== strpos( $purpose, 'prerender' ) ) {
				return $done();
			}
			$data = json_decode( (string) $req->get_body(), true );
			$id   = is_array( $data ) ? (int) ( $data['p'] ?? 0 ) : 0;
			$post = $id > 0 ? get_post( $id ) : null;
			if ( ! $post || 'domain' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
				return $done();
			}
			$ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? (string) $_SERVER['HTTP_CF_CONNECTING_IP'] : ( isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- hashed.
			db_hits_record( $id, db_hits_visitor( $ip, $ua ) );
			return $done();
		},
	) );
} );

// The beacon, on every domain page (editors are not counted).
add_action( 'wp_footer', function () {
	if ( ! is_singular( 'domain' ) || current_user_can( 'edit_posts' ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || '' !== $post->post_password ) {
		return;
	}
	?>
<script id="db-hit-js">
(function(){
	var url = <?php echo wp_json_encode( esc_url_raw( rest_url( 'db/v1/hit' ) ) ); ?>, body = JSON.stringify({p: <?php echo (int) $post->ID; ?>}), sent = false;
	function send(){
		if (sent) { return; }
		sent = true;
		try {
			if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([body], {type: 'text/plain'}))) { return; }
			fetch(url, {method: 'POST', body: body, keepalive: true, credentials: 'same-origin'});
		} catch (e) {}
	}
	function when(){
		if (document.visibilityState === 'visible') { setTimeout(send, 1200); return; }
		document.addEventListener('visibilitychange', function once(){ if (document.visibilityState === 'visible') { document.removeEventListener('visibilitychange', once); setTimeout(send, 1200); } });
	}
	if (document.readyState === 'complete') { when(); } else { window.addEventListener('load', when); }
})();
</script>
	<?php
}, 60 );

/* ───────────────────────────── 3. Numbers ─────────────────────────────── */

if ( ! function_exists( 'db_hits_day' ) ) {
	/** A Y-m-d date or ''. */
	function db_hits_day( $v ) {
		$v = (string) $v;
		return ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && false !== strtotime( $v ) ) ? $v : '';
	}
}

if ( ! function_exists( 'db_hits_bounds' ) ) {
	/** First day of each period, in the site's time zone. */
	function db_hits_bounds() {
		$now = time();
		return array(
			'today' => wp_date( 'Y-m-d', $now ),
			'd7'    => wp_date( 'Y-m-d', $now - 6 * DAY_IN_SECONDS ),
			'month' => wp_date( 'Y-m-01', $now ),
			'year'  => wp_date( 'Y-01-01', $now ),
		);
	}
}

if ( ! function_exists( 'db_hits_query' ) ) {
	/**
	 * One row per published domain with hits and unique visitors for today, the
	 * last 7 days, this month, this year, all time and (optionally) a date range.
	 * "Unique" means different visitors active in that period.
	 *
	 * @param string $from  Y-m-d or ''
	 * @param string $to    Y-m-d or ''
	 * @param int[]  $ids   limit to these domains (0 = all)
	 * @return array{rows:array,total:int}
	 */
	function db_hits_query( $search, $orderby, $dir, $limit, $offset, $from = '', $to = '', $ids = array() ) {
		global $wpdb;
		$dt = $wpdb->prefix . 'db_hits';
		$vt = $wpdb->prefix . 'db_hit_visitors';
		$vd = $wpdb->prefix . 'db_hit_vdays';
		$et = function_exists( 'db_deal_table' ) ? db_deal_table( 'events' ) : '';
		$dl = function_exists( 'db_deal_table' ) ? db_deal_table() : '';
		$b  = db_hits_bounds();
		$from = db_hits_day( $from );
		$to   = db_hits_day( $to );
		if ( '' !== $from && '' === $to ) {
			$to = $b['today'];
		}
		if ( '' === $from && '' !== $to ) {
			$from = '2000-01-01';
		}
		if ( '' !== $from && $from > $to ) {
			list( $from, $to ) = array( $to, $from );
		}
		$has_range = '' !== $from;
		$q = static function ( $d ) {
			return "'" . esc_sql( $d ) . "'"; // dates are validated Y-m-d strings
		};
		$conds = array(
			'today' => "day = {$q( $b['today'] )}",
			'd7'    => "day >= {$q( $b['d7'] )}",
			'month' => "day >= {$q( $b['month'] )}",
			'year'  => "day >= {$q( $b['year'] )}",
			'range' => $has_range ? "day BETWEEN {$q( $from )} AND {$q( $to )}" : '0',
		);
		$hsel = 'SUM(hits) AS all_h';
		$usel = '';
		foreach ( $conds as $k => $c ) {
			$hsel .= ", COALESCE(SUM(CASE WHEN {$c} THEN hits ELSE 0 END),0) AS {$k}_h";
			$usel .= ", COUNT(DISTINCT CASE WHEN {$c} THEN vhash END) AS {$k}_u";
		}
		$ob_map = array( 'name' => 'p.post_title', 'last' => 'last_ts', 'offers' => 'offers', 'all' => 'all_h', 'all_u' => 'all_u' );
		foreach ( array( 'today', 'd7', 'month', 'year', 'range' ) as $k ) {
			$ob_map[ $k ]          = "{$k}_h";
			$ob_map[ $k . '_u' ]   = "{$k}_u";
		}
		$ob   = $ob_map[ $orderby ] ?? 'all_h';
		$dirs = 'asc' === $dir ? 'ASC' : 'DESC';
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$in   = '';
		if ( $ids ) {
			$in = ' AND p.ID IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		$offers = ( '' !== $et && '' !== $dl )
			? "LEFT JOIN (SELECT d.domain AS dom, COUNT(*) AS offers FROM {$et} e JOIN {$dl} d ON d.id = e.deal_id WHERE e.actor = 'buyer' AND e.action = 'offer' GROUP BY d.domain) o ON o.dom = LOWER(p.post_title)"
			: 'LEFT JOIN (SELECT NULL AS dom, 0 AS offers) o ON 1=0';
		$base = "FROM {$wpdb->posts} p
			LEFT JOIN (SELECT post_id, {$hsel} FROM {$dt} GROUP BY post_id) h ON h.post_id = p.ID
			LEFT JOIN (SELECT post_id{$usel} FROM {$vd} GROUP BY post_id) u ON u.post_id = p.ID
			LEFT JOIN (SELECT post_id, COUNT(*) AS all_u, MAX(last_ts) AS last_ts FROM {$vt} GROUP BY post_id) v ON v.post_id = p.ID
			{$offers}
			WHERE p.post_type = 'domain' AND p.post_status = 'publish' AND p.post_title LIKE %s{$in}";
		$cols = 'p.ID, p.post_title, COALESCE(h.all_h,0) AS all_h, COALESCE(v.all_u,0) AS all_u, COALESCE(v.last_ts,0) AS last_ts, COALESCE(o.offers,0) AS offers';
		foreach ( array_keys( $conds ) as $k ) {
			$cols .= ", COALESCE(h.{$k}_h,0) AS {$k}_h, COALESCE(u.{$k}_u,0) AS {$k}_u";
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base}", $like ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT {$cols} {$base} ORDER BY {$ob} {$dirs}, p.post_title ASC LIMIT %d OFFSET %d", $like, (int) $limit, (int) $offset ) );
		// phpcs:enable
		return array( 'rows' => (array) $rows, 'total' => $total, 'from' => $has_range ? $from : '', 'to' => $has_range ? $to : '' );
	}
}

if ( ! function_exists( 'db_hits_for_domain' ) ) {
	/** The same figures for one domain post, or null when it has no row. */
	function db_hits_for_domain( $post_id, $from = '', $to = '' ) {
		$res = db_hits_query( '', 'all', 'desc', 1, 0, $from, $to, array( (int) $post_id ) );
		return $res['rows'][0] ?? null;
	}
}

if ( ! function_exists( 'db_hits_summary' ) ) {
	function db_hits_summary() {
		global $wpdb;
		$dt = $wpdb->prefix . 'db_hits';
		$vt = $wpdb->prefix . 'db_hit_visitors';
		$b  = db_hits_bounds();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'total'  => (int) $wpdb->get_var( "SELECT COALESCE(SUM(hits),0) FROM {$dt}" ),
			'unique' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vt}" ),
			'today'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits),0) FROM {$dt} WHERE day = %s", $b['today'] ) ),
			'week'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits),0) FROM {$dt} WHERE day >= %s", $b['d7'] ) ),
			'month'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits),0) FROM {$dt} WHERE day >= %s", $b['month'] ) ),
		);
		// phpcs:enable
	}
}

if ( ! function_exists( 'db_hits_cell' ) ) {
	/** "12 / 5": hits, then unique visitors. */
	function db_hits_cell( $h, $u ) {
		return number_format_i18n( (int) $h ) . ' <span class="dbh-u">/ ' . number_format_i18n( (int) $u ) . '</span>';
	}
}

/* ───────────────────────────── 4. Admin report ────────────────────────── */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Domain hits', 'Domain hits', 'manage_options', 'db-hits', 'db_hits_page' );
}, 23 );

if ( ! function_exists( 'db_hits_page' ) ) {
	function db_hits_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'all';
		$dir     = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$from    = isset( $_GET['from'] ) ? db_hits_day( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : '';
		$to      = isset( $_GET['to'] ) ? db_hits_day( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : '';
		// phpcs:enable
		$per = 50;
		do_action( 'db_hits_purge' );
		$res = db_hits_query( $search, $orderby, $dir, $per, ( $paged - 1 ) * $per, $from, $to );
		$sum = db_hits_summary();
		$rng = '' !== $res['from'];
		$keep = array( 'q' => $search, 'from' => $from, 'to' => $to );
		$base = admin_url( 'admin.php?page=db-hits' );
		$th = static function ( $key, $label ) use ( $orderby, $dir, $keep, $base ) {
			$next  = ( $orderby === $key && 'desc' === $dir ) ? 'asc' : 'desc';
			$arrow = $orderby === $key ? ( 'desc' === $dir ? ' &#9660;' : ' &#9650;' ) : '';
			return '<th><a href="' . esc_url( add_query_arg( array_merge( $keep, array( 'orderby' => $key, 'order' => $next ) ), $base ) ) . '">' . esc_html( $label ) . $arrow . '</a></th>';
		};

		echo '<div class="wrap"><h1>Domain hits</h1><p>Views of each domain page. Every figure reads <strong>hits / unique visitors</strong>: hits is every view; unique is the number of different people who viewed it in that period. Bots, the team and repeat refreshes within 10 seconds are not counted. Unique visitors are counted from the day tracking started.</p>';
		echo '<style>.dbs-tiles{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0}.dbs-tile{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 18px;min-width:130px}.dbs-tile b{display:block;font-size:22px}.dbs-sub{color:#646970;font-size:12px}.dbh-u{color:#646970}.dbh-range{background:#f0f6fc}.dbh-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin:10px 0}</style>';
		printf( '<div class="dbs-tiles"><div class="dbs-tile"><span>All hits</span><b>%s</b></div><div class="dbs-tile"><span>Unique visitor-pages</span><b>%s</b></div><div class="dbs-tile"><span>Hits today</span><b>%s</b></div><div class="dbs-tile"><span>Last 7 days</span><b>%s</b></div><div class="dbs-tile"><span>This month</span><b>%s</b></div></div>', esc_html( number_format_i18n( $sum['total'] ) ), esc_html( number_format_i18n( $sum['unique'] ) ), esc_html( number_format_i18n( $sum['today'] ) ), esc_html( number_format_i18n( $sum['week'] ) ), esc_html( number_format_i18n( $sum['month'] ) ) );
		echo '<form method="get" class="dbh-bar"><input type="hidden" name="page" value="db-hits"><input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="Domain"> <label>From <input type="date" name="from" value="' . esc_attr( $from ) . '"></label> <label>To <input type="date" name="to" value="' . esc_attr( $to ) . '"></label> <button class="button button-primary">Apply</button>';
		if ( $rng || '' !== $search ) {
			echo ' <a class="button" href="' . esc_url( $base ) . '">Clear</a>';
		}
		echo ' <a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'db_hits_export', 'q' => $search, 'from' => $from, 'to' => $to ), admin_url( 'admin-post.php' ) ), 'db_hits_export' ) ) . '">Export CSV</a></form>';
		if ( $rng ) {
			echo '<p><strong>Date range:</strong> ' . esc_html( $res['from'] . ' to ' . $res['to'] ) . ' (shown in the blue column)</p>';
		}

		echo '<table class="widefat striped"><thead><tr>' . $th( 'name', 'Domain' ) . $th( 'today', 'Today' ) . $th( 'd7', 'Last 7 days' ) . $th( 'month', 'This month' ) . $th( 'year', 'This year' ) . $th( 'all', 'All time' ); // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $rng ) {
			echo $th( 'range', 'Selected range' ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo $th( 'last', 'Last visit' ) . $th( 'offers', 'Offers' ) . '</tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="9">No domains match.</td></tr>';
		}
		foreach ( $res['rows'] as $r ) {
			echo '<tr><td><strong><a href="' . esc_url( get_edit_post_link( $r->ID ) ) . '">' . esc_html( strtolower( $r->post_title ) ) . '</a></strong></td>';
			foreach ( array( 'today', 'd7', 'month', 'year' ) as $k ) {
				echo '<td>' . db_hits_cell( $r->{$k . '_h'}, $r->{$k . '_u'} ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<td><strong>' . db_hits_cell( $r->all_h, $r->all_u ) . '</strong></td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			if ( $rng ) {
				echo '<td class="dbh-range">' . db_hits_cell( $r->range_h, $r->range_u ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '<td>' . ( $r->last_ts ? esc_html( wp_date( 'M j, g:i a', (int) $r->last_ts ) ) : '<span class="dbs-sub">never</span>' ) . '</td><td>' . ( (int) $r->offers ? '<a href="' . esc_url( admin_url( 'admin.php?page=db-offers&tab=all&q=' . rawurlencode( strtolower( $r->post_title ) ) ) ) . '">' . (int) $r->offers . '</a>' : '0' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $res['total'] / $per );
		if ( $pages > 1 ) {
			echo '<p>' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $base ), 'format' => '', 'current' => $paged, 'total' => $pages, 'add_args' => array_filter( array_merge( $keep, array( 'orderby' => $orderby, 'order' => $dir ) ) ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}
}

add_action( 'admin_post_db_hits_export', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_hits_export' ) ) {
		wp_die( 'Not allowed.' );
	}
	$q    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$from = isset( $_GET['from'] ) ? db_hits_day( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : '';
	$to   = isset( $_GET['to'] ) ? db_hits_day( sanitize_text_field( wp_unslash( $_GET['to'] ) ) ) : '';
	$res  = db_hits_query( $q, 'all', 'desc', 5000, 0, $from, $to );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="db-domain-hits-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	$head = array( 'Domain', 'Today hits', 'Today unique', 'Last 7 days hits', 'Last 7 days unique', 'This month hits', 'This month unique', 'This year hits', 'This year unique', 'All time hits', 'All time unique' );
	if ( '' !== $res['from'] ) {
		$head[] = 'Range ' . $res['from'] . ' to ' . $res['to'] . ' hits';
		$head[] = 'Range unique';
	}
	$head[] = 'Last visit (UTC)';
	$head[] = 'Offers received';
	fputcsv( $out, $head );
	foreach ( $res['rows'] as $r ) {
		$name = strtolower( $r->post_title );
		$line = array( ( '' !== $name && false !== strpos( '=+-@', $name[0] ) ) ? "'" . $name : $name, (int) $r->today_h, (int) $r->today_u, (int) $r->d7_h, (int) $r->d7_u, (int) $r->month_h, (int) $r->month_u, (int) $r->year_h, (int) $r->year_u, (int) $r->all_h, (int) $r->all_u );
		if ( '' !== $res['from'] ) {
			$line[] = (int) $r->range_h;
			$line[] = (int) $r->range_u;
		}
		$line[] = $r->last_ts ? gmdate( 'Y-m-d H:i', (int) $r->last_ts ) : '';
		$line[] = (int) $r->offers;
		fputcsv( $out, $line );
	}
	fclose( $out );
	exit;
} );

// A Hits column on the domain list.
add_filter( 'manage_domain_posts_columns', function ( $cols ) {
	$cols['db_hits'] = 'Hits / unique';
	return $cols;
} );
add_action( 'manage_domain_posts_custom_column', function ( $col, $post_id ) {
	if ( 'db_hits' !== $col ) {
		return;
	}
	static $map = null;
	if ( null === $map ) {
		global $wpdb, $wp_query;
		$map = array();
		$ids = wp_list_pluck( (array) $wp_query->posts, 'ID' );
		if ( $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( "SELECT post_id, SUM(hits) h FROM {$wpdb->prefix}db_hits WHERE post_id IN ({$in}) GROUP BY post_id" ) as $r ) {
				$map[ (int) $r->post_id ]['h'] = (int) $r->h;
			}
			foreach ( (array) $wpdb->get_results( "SELECT post_id, COUNT(*) u FROM {$wpdb->prefix}db_hit_visitors WHERE post_id IN ({$in}) GROUP BY post_id" ) as $r ) {
				$map[ (int) $r->post_id ]['u'] = (int) $r->u;
			}
			// phpcs:enable
		}
	}
	echo esc_html( number_format_i18n( $map[ $post_id ]['h'] ?? 0 ) . ' / ' . number_format_i18n( $map[ $post_id ]['u'] ?? 0 ) );
}, 10, 2 );
