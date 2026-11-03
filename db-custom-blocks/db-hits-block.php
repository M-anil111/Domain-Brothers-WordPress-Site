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
	define( 'DB_HITS_TABLE_VERSION', '1' );
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
			(int) $post_id, gmdate( 'Y-m-d', $now ), $new, $new
		) );
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

/* ───────────────────────────── 3. Admin report ────────────────────────── */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Domain hits', 'Domain hits', 'manage_options', 'db-hits', 'db_hits_page' );
}, 23 );

if ( ! function_exists( 'db_hits_query' ) ) {
	/** @return array{rows:array,total:int} */
	function db_hits_query( $search, $orderby, $dir, $limit, $offset ) {
		global $wpdb;
		$dt = $wpdb->prefix . 'db_hits';
		$vt = $wpdb->prefix . 'db_hit_visitors';
		$et = function_exists( 'db_deal_table' ) ? db_deal_table( 'events' ) : '';
		$dl = function_exists( 'db_deal_table' ) ? db_deal_table() : '';
		$cols = array( 'name' => 'p.post_title', 'hits' => 'total', 'unique' => 'uniq', 'd7' => 'h7', 'd30' => 'h30', 'last' => 'last_ts', 'offers' => 'offers' );
		$ob   = $cols[ $orderby ] ?? 'total';
		$dir  = 'asc' === $dir ? 'ASC' : 'DESC';
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$d7   = gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS );
		$d30  = gmdate( 'Y-m-d', time() - 29 * DAY_IN_SECONDS );
		$offers = ( '' !== $et && '' !== $dl )
			? "LEFT JOIN (SELECT d.domain AS dom, COUNT(*) AS offers FROM {$et} e JOIN {$dl} d ON d.id = e.deal_id WHERE e.actor = 'buyer' AND e.action = 'offer' GROUP BY d.domain) o ON o.dom = LOWER(p.post_title)"
			: 'LEFT JOIN (SELECT NULL AS dom, 0 AS offers) o ON 1=0';
		$base = "FROM {$wpdb->posts} p
			LEFT JOIN (SELECT post_id, SUM(hits) AS total FROM {$dt} GROUP BY post_id) t ON t.post_id = p.ID
			LEFT JOIN (SELECT post_id, COUNT(*) AS uniq, MAX(last_ts) AS last_ts FROM {$vt} GROUP BY post_id) v ON v.post_id = p.ID
			LEFT JOIN (SELECT post_id, SUM(hits) AS h7 FROM {$dt} WHERE day >= %s GROUP BY post_id) w ON w.post_id = p.ID
			LEFT JOIN (SELECT post_id, SUM(hits) AS h30 FROM {$dt} WHERE day >= %s GROUP BY post_id) m ON m.post_id = p.ID
			{$offers}
			WHERE p.post_type = 'domain' AND p.post_status = 'publish' AND p.post_title LIKE %s";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base}", $d7, $d30, $like ) );
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, COALESCE(t.total,0) AS total, COALESCE(v.uniq,0) AS uniq, COALESCE(w.h7,0) AS h7, COALESCE(m.h30,0) AS h30, COALESCE(v.last_ts,0) AS last_ts, COALESCE(o.offers,0) AS offers {$base} ORDER BY {$ob} {$dir}, p.post_title ASC LIMIT %d OFFSET %d",
			$d7, $d30, $like, (int) $limit, (int) $offset
		) );
		// phpcs:enable
		return array( 'rows' => (array) $rows, 'total' => $total );
	}
}

if ( ! function_exists( 'db_hits_summary' ) ) {
	function db_hits_summary() {
		global $wpdb;
		$dt = $wpdb->prefix . 'db_hits';
		$vt = $wpdb->prefix . 'db_hit_visitors';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'total'  => (int) $wpdb->get_var( "SELECT COALESCE(SUM(hits),0) FROM {$dt}" ),
			'unique' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$vt}" ),
			'today'  => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits),0) FROM {$dt} WHERE day = %s", gmdate( 'Y-m-d' ) ) ),
			'week'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(hits),0) FROM {$dt} WHERE day >= %s", gmdate( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ) ) ),
		);
		// phpcs:enable
	}
}

if ( ! function_exists( 'db_hits_page' ) ) {
	function db_hits_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'hits';
		$dir     = isset( $_GET['order'] ) && 'asc' === $_GET['order'] ? 'asc' : 'desc';
		$paged   = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		// phpcs:enable
		$per = 50;
		$res = db_hits_query( $search, $orderby, $dir, $per, ( $paged - 1 ) * $per );
		$sum = db_hits_summary();
		$base = admin_url( 'admin.php?page=db-hits' );
		$th = static function ( $key, $label ) use ( $orderby, $dir, $search, $base ) {
			$next = ( $orderby === $key && 'desc' === $dir ) ? 'asc' : 'desc';
			$arrow = $orderby === $key ? ( 'desc' === $dir ? ' &#9660;' : ' &#9650;' ) : '';
			return '<th><a href="' . esc_url( add_query_arg( array( 'orderby' => $key, 'order' => $next, 'q' => $search ), $base ) ) . '">' . esc_html( $label ) . $arrow . '</a></th>';
		};

		echo '<div class="wrap"><h1>Domain hits</h1><p>Views of each domain page. <strong>Hits</strong> is every view; <strong>Unique</strong> is the number of different visitors. Bots, the team and repeat refreshes within 10 seconds are not counted.</p>';
		echo '<style>.dbs-tiles{display:flex;gap:12px;flex-wrap:wrap;margin:14px 0}.dbs-tile{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:12px 18px;min-width:150px}.dbs-tile b{display:block;font-size:22px}.dbs-sub{color:#646970;font-size:12px}</style>';
		printf( '<div class="dbs-tiles"><div class="dbs-tile"><span>All hits</span><b>%s</b></div><div class="dbs-tile"><span>Unique visitor-pages</span><b>%s</b></div><div class="dbs-tile"><span>Hits today</span><b>%s</b></div><div class="dbs-tile"><span>Hits, last 7 days</span><b>%s</b></div></div>', esc_html( number_format_i18n( $sum['total'] ) ), esc_html( number_format_i18n( $sum['unique'] ) ), esc_html( number_format_i18n( $sum['today'] ) ), esc_html( number_format_i18n( $sum['week'] ) ) );
		echo '<form method="get" style="margin:10px 0"><input type="hidden" name="page" value="db-hits"><input type="search" name="q" value="' . esc_attr( $search ) . '" placeholder="Domain"> <button class="button">Search</button> ';
		echo '<a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'db_hits_export', 'q' => $search ), admin_url( 'admin-post.php' ) ), 'db_hits_export' ) ) . '">Export CSV</a></form>';

		echo '<table class="widefat striped"><thead><tr>' . $th( 'name', 'Domain' ) . $th( 'hits', 'Hits (all time)' ) . $th( 'unique', 'Unique visitors' ) . $th( 'd7', 'Last 7 days' ) . $th( 'd30', 'Last 30 days' ) . $th( 'last', 'Last visit' ) . $th( 'offers', 'Offers received' ) . '</tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="7">No domains match.</td></tr>';
		}
		foreach ( $res['rows'] as $r ) {
			echo '<tr><td><strong><a href="' . esc_url( get_edit_post_link( $r->ID ) ) . '">' . esc_html( strtolower( $r->post_title ) ) . '</a></strong></td><td>' . esc_html( number_format_i18n( (int) $r->total ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $r->uniq ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $r->h7 ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $r->h30 ) ) . '</td><td>' . ( $r->last_ts ? esc_html( wp_date( 'M j, g:i a', (int) $r->last_ts ) ) : '<span class="dbs-sub">never</span>' ) . '</td><td>' . ( (int) $r->offers ? '<a href="' . esc_url( admin_url( 'admin.php?page=db-offers&tab=all&q=' . rawurlencode( strtolower( $r->post_title ) ) ) ) . '">' . (int) $r->offers . '</a>' : '0' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $res['total'] / $per );
		if ( $pages > 1 ) {
			echo '<p>' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $base ), 'format' => '', 'current' => $paged, 'total' => $pages, 'add_args' => array_filter( array( 'q' => $search, 'orderby' => $orderby, 'order' => $dir ) ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</div>';
	}
}

add_action( 'admin_post_db_hits_export', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_hits_export' ) ) {
		wp_die( 'Not allowed.' );
	}
	$q = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
	$res = db_hits_query( $q, 'hits', 'desc', 5000, 0 );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="db-domain-hits-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'Domain', 'Hits (all time)', 'Unique visitors', 'Last 7 days', 'Last 30 days', 'Last visit (UTC)', 'Offers received' ) );
	foreach ( $res['rows'] as $r ) {
		$name = strtolower( $r->post_title );
		fputcsv( $out, array( ( '' !== $name && false !== strpos( "=+-@", $name[0] ) ) ? "'" . $name : $name, (int) $r->total, (int) $r->uniq, (int) $r->h7, (int) $r->h30, $r->last_ts ? gmdate( 'Y-m-d H:i', (int) $r->last_ts ) : '', (int) $r->offers ) );
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
