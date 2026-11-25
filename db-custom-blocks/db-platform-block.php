<?php
/**
 * Platform: server diagnostics, request timing and the WordPress core upgrade.
 *
 * Admin page Domain Brothers > Platform shows what the server offers (web
 * server, OPcache, object cache back ends), whether WordPress can reach the
 * WordPress.org update service, and the time each page phase takes. The
 * version check on staging returned no offers while WordPress.org listed a
 * newer release, so the page also reads the offer directly and can install it.
 *
 * Timing: an administrator (or anyone holding today's timing token, shown on
 * the Platform page) who adds ?db_timing=1 (or =<token>) to a front-end URL
 * gets an HTML comment at the end of the page with the phase timings, query
 * count and object-cache hit counts. Nothing is added otherwise.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

/* ------------------------------------------------------------------ timing */

if ( ! function_exists( 'db_platform_timing_token' ) ) {
	/** Today's token that lets a signed-out request see the timing comment. */
	function db_platform_timing_token() {
		return substr( wp_hash( 'db_timing|' . gmdate( 'Ymd' ) ), 0, 16 );
	}
}

if ( ! function_exists( 'db_platform_timing_on' ) ) {
	function db_platform_timing_on() {
		static $on = null;
		if ( null !== $on ) {
			return $on;
		}
		$v  = isset( $_GET['db_timing'] ) ? sanitize_text_field( wp_unslash( $_GET['db_timing'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$on = '' !== $v && ( hash_equals( db_platform_timing_token(), $v ) || ( function_exists( 'current_user_can' ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) );
		return $on;
	}
}

if ( ! function_exists( 'db_platform_mark' ) ) {
	/** Records the time since the request started under a phase name. */
	function db_platform_mark( $name ) {
		global $db_platform_marks;
		if ( ! is_array( $db_platform_marks ) ) {
			$db_platform_marks = array();
		}
		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$db_platform_marks[ $name ] = (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}

// Where the time before plugins_loaded goes: this plugin's own load, then each later plugin.
if ( isset( $GLOBALS['db_t_plugin_start'] ) ) {
	$db_t0 = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0;
	$GLOBALS['db_platform_marks'] = array( 'db_plugin_start' => (int) round( ( $GLOBALS['db_t_plugin_start'] - $db_t0 ) * 1000 ) );
	db_platform_mark( 'db_plugin_loaded' );
	unset( $db_t0 );
}
add_action( 'plugin_loaded', function ( $file ) {
	db_platform_mark( 'after_' . basename( dirname( (string) $file ) ) );
} );

foreach ( array(
	'plugins_loaded'    => PHP_INT_MAX,
	'init'              => PHP_INT_MAX,
	'wp_loaded'         => PHP_INT_MAX,
	'template_redirect' => PHP_INT_MAX,
	'wp_head'           => PHP_INT_MAX,
	'loop_start'        => 0,
	'wp_footer'         => 0,
) as $db_hook => $db_prio ) {
	add_action( $db_hook, function () use ( $db_hook ) {
		db_platform_mark( $db_hook );
	}, $db_prio );
}
unset( $db_hook, $db_prio );

add_action( 'wp_footer', function () {
	if ( is_admin() || ! db_platform_timing_on() ) {
		return;
	}
	global $db_platform_marks, $wpdb, $wp_object_cache;
	db_platform_mark( 'end' );
	$parts = array();
	foreach ( (array) $db_platform_marks as $k => $ms ) {
		$parts[] = $k . '=' . (int) $ms . 'ms';
	}
	$hits   = isset( $wp_object_cache->cache_hits ) ? (int) $wp_object_cache->cache_hits : -1;
	$misses = isset( $wp_object_cache->cache_misses ) ? (int) $wp_object_cache->cache_misses : -1;
	printf(
		"\n<!-- db-timing %s queries=%d peak_mem=%dMB ext_object_cache=%s oc_hits=%d oc_misses=%d opcache=%s -->\n",
		esc_html( implode( ' ', $parts ) ),
		(int) $wpdb->num_queries,
		(int) round( memory_get_peak_usage( true ) / 1048576 ),
		wp_using_ext_object_cache() ? 'yes' : 'no',
		$hits,
		$misses,
		( function_exists( 'opcache_get_status' ) && is_array( @opcache_get_status( false ) ) ) ? 'on' : 'off' // phpcs:ignore WordPress.PHP.NoSilencedErrors
	);
}, PHP_INT_MAX );

/* ------------------------------------------------------------ diagnostics */

if ( ! function_exists( 'db_platform_probe_port' ) ) {
	/** True when something accepts TCP connections on host:port within 1 s. */
	function db_platform_probe_port( $host, $port ) {
		$errno = 0;
		$err   = '';
		$fp    = @fsockopen( $host, (int) $port, $errno, $err, 1.0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $fp ) {
			fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'db_platform_backends' ) ) {
	/**
	 * Object-cache back ends this server can reach right now.
	 * Each row: [ label, method (redis|memcached|apcu), host, port, ok, note ].
	 */
	function db_platform_backends() {
		$rows  = array();
		$home  = function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ? (string) ( posix_getpwuid( posix_geteuid() )['dir'] ?? '' ) : '';
		$socks = array_filter( array(
			$home ? $home . '/.redis/redis.sock' : '',
			'/var/run/redis/redis.sock',
			'/run/redis/redis.sock',
			'/tmp/redis.sock',
		) );
		if ( class_exists( 'Redis' ) ) {
			$targets = array( array( '127.0.0.1', 6379 ) );
			foreach ( $socks as $s ) {
				if ( @file_exists( $s ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$targets[] = array( $s, 0 );
				}
			}
			foreach ( $targets as $t ) {
				$ok   = false;
				$note = '';
				try {
					$r  = new Redis();
					$ok = 0 === $t[1] ? @$r->connect( $t[0] ) : @$r->connect( $t[0], $t[1], 1.0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					if ( $ok ) {
						$pong = $r->ping();
						$ok   = ( true === $pong || '+PONG' === $pong || 'PONG' === $pong );
						$note = $ok ? 'PING answered' : 'connected, PING failed';
						$r->close();
					}
				} catch ( Throwable $e ) {
					$note = mb_substr( $e->getMessage(), 0, 120 );
				}
				$rows[] = array( 'Redis', 'redis', $t[0], $t[1], (bool) $ok, $note );
			}
		}
		if ( class_exists( 'Memcached' ) ) {
			$targets = array( array( '127.0.0.1', 11211 ) );
			foreach ( array_filter( array( $home ? $home . '/.memcached/memcached.sock' : '', '/var/run/memcached/memcached.sock', '/tmp/memcached.sock' ) ) as $s ) {
				if ( @file_exists( $s ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
					$targets[] = array( $s, 0 );
				}
			}
			foreach ( $targets as $t ) {
				$ok   = false;
				$note = '';
				if ( 0 !== $t[1] && ! db_platform_probe_port( $t[0], $t[1] ) ) {
					$note = 'nothing listening';
				} else {
					try {
						$m = new Memcached();
						$m->setOption( Memcached::OPT_CONNECT_TIMEOUT, 1000 );
						$m->addServer( $t[0], (int) $t[1] );
						$key = 'db_probe_' . wp_generate_password( 8, false );
						$ok  = $m->set( $key, '1', 30 ) && '1' === $m->get( $key );
						$m->delete( $key );
						$note = $ok ? 'set/get worked' : 'set/get failed (' . $m->getResultMessage() . ')';
					} catch ( Throwable $e ) {
						$note = mb_substr( $e->getMessage(), 0, 120 );
					}
				}
				$rows[] = array( 'Memcached', 'memcached', $t[0], $t[1], (bool) $ok, $note );
			}
		}
		if ( function_exists( 'apcu_enabled' ) ) {
			$ok     = apcu_enabled();
			$rows[] = array( 'APCu (in PHP memory)', 'apcu', '', 0, (bool) $ok, $ok ? 'enabled' : 'extension loaded, apc.enabled is off' );
		}
		return $rows;
	}
}

if ( ! function_exists( 'db_platform_core_managed' ) ) {
	/**
	 * Path of WordPress core when the host runs it from a shared copy outside
	 * this site (Hostinger keeps core under /opt/h5g/flavors/<version>), or ''.
	 * Such core is upgraded by the host, never from wp-admin.
	 */
	function db_platform_core_managed() {
		$inc  = realpath( ABSPATH . 'wp-includes' );
		$site = realpath( WP_CONTENT_DIR . '/..' );
		if ( ! $inc || ! $site ) {
			return '';
		}
		return 0 === strpos( $inc, $site . DIRECTORY_SEPARATOR ) ? '' : dirname( $inc );
	}
}

if ( ! function_exists( 'db_platform_core_offer' ) ) {
	/**
	 * Reads the newest WordPress release from the WordPress.org version API.
	 * Returns [ offer object|null, diagnostic string ].
	 */
	function db_platform_core_offer() {
		global $wp_version, $wp_local_package;
		$url = add_query_arg( array(
			'version' => $wp_version,
			'php'     => PHP_VERSION,
			'locale'  => get_locale(),
			'mysql'   => $GLOBALS['wpdb']->db_version(),
		), 'https://api.wordpress.org/core/version-check/1.7/' );
		$res = wp_remote_get( $url, array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			return array( null, 'request failed: ' . $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || empty( $body['offers'] ) || ! is_array( $body['offers'] ) ) {
			return array( null, 'WordPress.org answered HTTP ' . $code . ' without offers' );
		}
		foreach ( $body['offers'] as $o ) {
			if ( ! empty( $o['response'] ) && in_array( $o['response'], array( 'upgrade', 'latest' ), true ) && ! empty( $o['packages']['full'] ) ) {
				$offer = (object) $o;
				$offer->packages = (object) $o['packages'];
				return array( $offer, 'WordPress.org answered HTTP 200; newest release ' . $o['current'] );
			}
		}
		return array( null, 'no usable offer in the answer' );
	}
}

/* --------------------------------------------------------- core upgrade */

add_action( 'admin_post_db_platform_core_upgrade', function () {
	if ( ! current_user_can( 'update_core' ) ) {
		wp_die( 'You are not allowed to update WordPress on this site.', 403 );
	}
	check_admin_referer( 'db_platform_core_upgrade' );
	global $wp_version;
	$want = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
	list( $offer, $diag ) = db_platform_core_offer();
	$back = admin_url( 'admin.php?page=db-platform' );
	if ( ! $offer || $offer->current !== $want || version_compare( $offer->current, $wp_version, '<=' ) ) {
		update_option( 'db_platform_last_upgrade', array( 'time' => time(), 'ok' => false, 'log' => array( 'No newer release to install: ' . $diag ) ), false );
		wp_safe_redirect( $back );
		exit;
	}
	if ( '' !== db_platform_core_managed() ) {
		update_option( 'db_platform_last_upgrade', array( 'time' => time(), 'ok' => false, 'log' => array( 'WordPress core is provided by the host from ' . db_platform_core_managed() . '. Change the WordPress version in the hosting panel.' ) ), false );
		wp_safe_redirect( $back );
		exit;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	if ( 'direct' !== get_filesystem_method() ) {
		update_option( 'db_platform_last_upgrade', array( 'time' => time(), 'ok' => false, 'log' => array( 'WordPress cannot write its own files directly (filesystem method: ' . get_filesystem_method() . '). Upgrade from the hosting panel.' ) ), false );
		wp_safe_redirect( $back );
		exit;
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	WP_Filesystem();
	$skin     = new Automatic_Upgrader_Skin();
	$upgrader = new Core_Upgrader( $skin );
	$from     = $wp_version;
	$result   = $upgrader->upgrade( $offer, array( 'attempt_rollback' => true, 'do_rollback' => false ) );
	$log      = array_map( 'wp_strip_all_tags', array_map( 'strval', (array) $skin->get_upgrade_messages() ) );
	$ok       = ! is_wp_error( $result ) && is_string( $result );
	if ( is_wp_error( $result ) ) {
		$log[] = 'Error: ' . $result->get_error_code() . ' ' . $result->get_error_message();
	}
	update_option( 'db_platform_last_upgrade', array( 'time' => time(), 'ok' => $ok, 'from' => $from, 'to' => $ok ? $result : '', 'log' => array_slice( $log, -40 ) ), false );
	// The database step runs on the next admin load (wp-admin/upgrade.php).
	wp_safe_redirect( $ok ? admin_url( 'upgrade.php?step=1&backto=' . rawurlencode( 'admin.php?page=db-platform' ) ) : $back );
	exit;
} );

/* -------------------------------------------------------------- admin page */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Platform', 'Platform', 'manage_options', 'db-platform', 'db_platform_admin_page' );
}, 30 );

if ( ! function_exists( 'db_platform_admin_page' ) ) {
	function db_platform_admin_page() {
		global $wp_version;
		$row = function ( $k, $v ) {
			echo '<tr><th scope="row" style="width:260px">' . esc_html( $k ) . '</th><td>' . wp_kses_post( $v ) . '</td></tr>';
		};
		$yes = function ( $b, $t = 'Yes', $f = 'No' ) {
			return $b ? '<strong style="color:#1a7f37">' . esc_html( $t ) . '</strong>' : '<strong style="color:#b32d2e">' . esc_html( $f ) . '</strong>';
		};
		echo '<div class="wrap"><h1>Platform</h1>';

		// Server.
		$op = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		echo '<h2>Server</h2><table class="widefat striped" style="max-width:980px"><tbody>';
		$row( 'Web server', esc_html( isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'unknown' ) . ' (' . esc_html( PHP_SAPI ) . ')' );
		$row( 'PHP', esc_html( PHP_VERSION ) . ', memory limit ' . esc_html( (string) ini_get( 'memory_limit' ) ) );
		$oc = function_exists( 'opcache_get_configuration' ) ? @opcache_get_configuration() : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_array( $oc ) && isset( $oc['directives'] ) ) {
			$d = $oc['directives'];
			$row( 'OPcache settings', 'validate_timestamps=' . ( ! empty( $d['opcache.validate_timestamps'] ) ? 'on' : 'off' ) . ', revalidate_freq=' . (int) ( $d['opcache.revalidate_freq'] ?? 0 ) . 's, memory=' . (int) round( ( $d['opcache.memory_consumption'] ?? 0 ) / 1048576 ) . ' MB, max files=' . (int) ( $d['opcache.max_accelerated_files'] ?? 0 ) . ', JIT=' . esc_html( (string) ( $d['opcache.jit'] ?? 'n/a' ) ) );
		}
		$row( 'auto_prepend_file', esc_html( (string) ini_get( 'auto_prepend_file' ) ?: 'none' ) );
		$row( 'OPcache', is_array( $op ) ? $yes( ! empty( $op['opcache_enabled'] ), 'On', 'Off' ) . ( isset( $op['memory_usage']['used_memory'] ) ? ' &middot; ' . (int) round( $op['memory_usage']['used_memory'] / 1048576 ) . ' MB used, ' . (int) ( $op['opcache_statistics']['num_cached_scripts'] ?? 0 ) . ' scripts' : '' ) : $yes( false, 'On', 'Not available' ) );
		$row( 'Page cache (WP_CACHE)', $yes( defined( 'WP_CACHE' ) && WP_CACHE ) . ( file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ? ' &middot; advanced-cache.php present' : '' ) );
		$drop = file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ? get_file_data( WP_CONTENT_DIR . '/object-cache.php', array( 'n' => 'Plugin Name', 'd' => 'Description' ) ) : null;
		$row( 'Persistent object cache', $yes( wp_using_ext_object_cache(), 'In use', 'Not in use' ) . ( $drop ? ' &middot; drop-in: ' . esc_html( $drop['n'] ? $drop['n'] : ( $drop['d'] ? $drop['d'] : 'object-cache.php' ) ) : '' ) );
		echo '</tbody></table>';

		echo '<h2>Object-cache back ends this server can reach</h2><table class="widefat striped" style="max-width:980px"><thead><tr><th>Back end</th><th>Address</th><th>Reachable</th><th>Detail</th></tr></thead><tbody>';
		$rows = db_platform_backends();
		if ( ! $rows ) {
			echo '<tr><td colspan="4">No Redis, Memcached or APCu extension is loaded.</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . esc_html( $r[0] ) . '</td><td><code>' . esc_html( $r[2] . ( $r[3] ? ':' . $r[3] : '' ) ) . '</code></td><td>' . $yes( $r[4] ) . '</td><td>' . esc_html( $r[5] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';

		// WordPress core.
		list( $offer, $diag ) = db_platform_core_offer();
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$fsm = get_filesystem_method();
		echo '<h2>WordPress</h2><table class="widefat striped" style="max-width:980px"><tbody>';
		$row( 'Installed version', esc_html( $wp_version ) );
		$row( 'WordPress.org', esc_html( $diag ) );
		$cu = get_site_transient( 'update_core' );
		$row( 'Offer stored by WordPress', is_object( $cu ) && ! empty( $cu->updates ) ? esc_html( implode( ', ', array_map( function ( $u ) {
			return ( $u->response ?? '?' ) . ' ' . ( $u->current ?? '?' );
		}, $cu->updates ) ) ) . ' &middot; checked ' . esc_html( human_time_diff( (int) ( $cu->last_checked ?? 0 ) ) ) . ' ago' : 'none stored' );
		foreach ( array( 'WP_AUTO_UPDATE_CORE', 'AUTOMATIC_UPDATER_DISABLED', 'DISALLOW_FILE_MODS', 'WP_HTTP_BLOCK_EXTERNAL' ) as $c ) {
			$row( $c, defined( $c ) ? '<code>' . esc_html( var_export( constant( $c ), true ) ) . '</code>' : 'not set' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		$managed = db_platform_core_managed();
		$row( 'Core files', '' === $managed ? 'Inside this site (WordPress can update itself)' : 'Provided by the host from <code>' . esc_html( $managed ) . '</code>. The host sets the WordPress version; change it in the hosting panel (Hostinger: Websites &rsaquo; WordPress &rsaquo; Overview or Updates).' );
		$row( 'Filesystem method', '<code>' . esc_html( $fsm ) . '</code>' . ( 'direct' === $fsm ? '' : ' (WordPress cannot update itself here)' ) );
		echo '</tbody></table>';
		if ( $offer && version_compare( $offer->current, $wp_version, '>' ) && '' === $managed ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:14px 0" onsubmit="return confirm(\'Upgrade WordPress to ' . esc_js( $offer->current ) . '? The site shows a maintenance notice for about a minute. Make sure a backup exists.\');">';
			wp_nonce_field( 'db_platform_core_upgrade' );
			echo '<input type="hidden" name="action" value="db_platform_core_upgrade"><input type="hidden" name="version" value="' . esc_attr( $offer->current ) . '">';
			echo '<button class="button button-primary"' . ( 'direct' === $fsm && ! ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) ? '' : ' disabled' ) . '>Upgrade WordPress to ' . esc_html( $offer->current ) . '</button></form>';
		}
		$last = get_option( 'db_platform_last_upgrade' );
		if ( is_array( $last ) ) {
			echo '<h3>Last upgrade attempt (' . esc_html( wp_date( 'Y-m-d H:i', (int) $last['time'] ) ) . '): ' . ( ! empty( $last['ok'] ) ? 'succeeded' : 'did not complete' ) . '</h3><pre style="max-width:980px;white-space:pre-wrap;background:#fff;border:1px solid #dcdcde;padding:10px">' . esc_html( implode( "\n", (array) $last['log'] ) ) . '</pre>';
		}

		do_action( 'db_platform_sections' );

		echo '<h2>Page timing</h2><p>Add <code>?db_timing=1</code> to any page while signed in, or <code>?db_timing=' . esc_html( db_platform_timing_token() ) . '</code> when signed out (valid today, UTC). The page source then ends with the time each phase took, the query count and object-cache hits.</p>';
		echo '</div>';
	}
}
