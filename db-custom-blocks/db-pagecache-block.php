<?php
/**
 * Speed: page cache and object cache for servers without LiteSpeed.
 *
 * Staging runs on nginx with PHP-FPM. LiteSpeed Cache's page cache needs a
 * LiteSpeed server, so no page was ever served from cache: every visit built
 * the page in PHP (0.4-1.7 s before the first byte). The server has no Redis
 * or Memcached daemon, but APCu is on.
 *
 *   Page cache   wp-content/advanced-cache.php (drop-in) serves saved pages to
 *                signed-out visitors before plugins load. Rules and keys:
 *                pagecache/serve.php. Pages are saved here, at the end of a
 *                normal render, only when the response is a 200 text/html page
 *                that sets no cookie (other than the experiment cookie).
 *   Object cache wp-content/object-cache.php (drop-in, APCu).
 *
 * Both drop-ins are installed when the plugin runs on a server that is not
 * LiteSpeed, and removed when the plugin is deactivated or when they are
 * switched off on Domain Brothers > Platform. A drop-in written by another
 * plugin is never overwritten, except LiteSpeed Cache's page-cache drop-in on
 * a non-LiteSpeed server, where it does nothing.
 *
 * Purging: any published content change, menu, widget, theme or plugin
 * change, or a settings form saved in wp-admin clears the whole page cache.
 * A registry-record refresh clears only that listing's page. A new plugin
 * version clears everything (db-launch-block.php).
 *
 * Tracking: views that were counted while the page was built (listing views
 * and experiment views) are counted by a small beacon instead while the page
 * cache is on, so they are counted once whether the page came from cache or not.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/pagecache/serve.php';

define( 'DB_PC_DROPIN_MARK', 'Domain Brothers page cache drop-in' );

if ( ! function_exists( 'db_pc_server_is_litespeed' ) ) {
	function db_pc_server_is_litespeed() {
		$s = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
		return false !== strpos( $s, 'litespeed' );
	}
}

if ( ! function_exists( 'db_pc_enabled' ) ) {
	/** Owner switches (Platform page). Both default to on. */
	function db_pc_enabled( $which ) {
		$o = get_option( 'db_speed_switches', array() );
		return ! is_array( $o ) || ! isset( $o[ $which ] ) || ! empty( $o[ $which ] );
	}
}

if ( ! function_exists( 'db_pc_page_dropin_source' ) ) {
	function db_pc_page_dropin_source() {
		return "<?php\n/**\n * " . DB_PC_DROPIN_MARK . ".\n * Written by the Domain Brothers Custom Blocks plugin. It serves saved copies of\n * public pages to signed-out visitors. Delete this file to turn the page cache off.\n */\ndefined( 'ABSPATH' ) || exit;\n"
			. "\$db_pc_serve_file = WP_CONTENT_DIR . '/plugins/db-custom-blocks/pagecache/serve.php';\n"
			. "if ( is_file( \$db_pc_serve_file ) ) {\n\tdefine( 'DB_PC_FROM_DROPIN', true );\n\tinclude_once \$db_pc_serve_file;\n}\nunset( \$db_pc_serve_file );\n";
	}
}

if ( ! function_exists( 'db_pc_dropin_state' ) ) {
	/** 'ours', 'none', 'litespeed' (LiteSpeed Cache's), or 'other'. */
	function db_pc_dropin_state( $file ) {
		$path = WP_CONTENT_DIR . '/' . $file;
		if ( ! file_exists( $path ) ) {
			return 'none';
		}
		$head = (string) @file_get_contents( $path, false, null, 0, 2048 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( '' === trim( $head ) ) {
			return 'none';
		}
		if ( false !== strpos( $head, DB_PC_DROPIN_MARK ) || false !== strpos( $head, 'Domain Brothers object cache' ) ) {
			return 'ours';
		}
		if ( false !== stripos( $head, 'litespeed' ) || false !== strpos( $head, 'LSCACHE' ) ) {
			return 'litespeed';
		}
		return 'other';
	}
}

if ( ! function_exists( 'db_pc_write' ) ) {
	/** Writes a file atomically. */
	function db_pc_write( $path, $contents ) {
		$tmp = $path . '.' . wp_generate_password( 6, false ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return false;
		}
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'db_pc_site_answers' ) ) {
	/**
	 * Loopback check after a drop-in is written: true when the site still
	 * answers without a server error. A staging password answer (401) counts
	 * as answering: WordPress loaded both drop-ins before sending it.
	 */
	function db_pc_site_answers() {
		$res = wp_remote_get( add_query_arg( 'db_dropin_check', wp_generate_password( 6, false ), home_url( '/wp-login.php' ) ), array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => false ) );
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return $code > 0 && $code < 500;
	}
}

if ( ! function_exists( 'db_pc_install_checked' ) ) {
	/** Writes a drop-in, then removes it again if the site stops answering. */
	function db_pc_install_checked( $file, $contents ) {
		$path = WP_CONTENT_DIR . '/' . $file;
		if ( ! db_pc_write( $path, $contents ) ) {
			return 'Could not write wp-content/' . $file . '.';
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! db_pc_site_answers() ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			update_option( 'db_speed_dropin_failed_' . sanitize_key( $file ), time(), false );
			return 'wp-content/' . $file . ' was removed again: the site did not answer normally with it in place.';
		}
		delete_option( 'db_speed_dropin_failed_' . sanitize_key( $file ) );
		return 'wp-content/' . $file . ' installed and checked.';
	}
}

if ( ! function_exists( 'db_pc_sync_dropins' ) ) {
	/**
	 * Installs or removes the drop-ins to match the switches and the server.
	 * Returns a list of human-readable notes.
	 */
	function db_pc_sync_dropins() {
		$notes = array();
		if ( ! wp_mkdir_p( DB_PC_DIR ) ) {
			return array( 'wp-content/cache is not writable.' );
		}
		if ( ! file_exists( WP_CONTENT_DIR . '/cache/index.php' ) ) {
			@file_put_contents( WP_CONTENT_DIR . '/cache/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		// Page cache.
		$state = db_pc_dropin_state( 'advanced-cache.php' );
		$want  = db_pc_enabled( 'page' ) && ! db_pc_server_is_litespeed();
		if ( $want && in_array( $state, array( 'none', 'litespeed' ), true ) ) {
			$notes[] = db_pc_install_checked( 'advanced-cache.php', db_pc_page_dropin_source() );
		} elseif ( $want && 'ours' === $state && (string) @file_get_contents( WP_CONTENT_DIR . '/advanced-cache.php' ) !== db_pc_page_dropin_source() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			db_pc_write( WP_CONTENT_DIR . '/advanced-cache.php', db_pc_page_dropin_source() );
		} elseif ( ! $want && 'ours' === $state ) {
			@unlink( WP_CONTENT_DIR . '/advanced-cache.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			db_pc_purge_all();
			$notes[] = 'Page cache drop-in removed.';
		} elseif ( $want && 'other' === $state ) {
			$notes[] = 'Another plugin owns wp-content/advanced-cache.php, so the page cache was not installed.';
		}
		// Object cache.
		$state = db_pc_dropin_state( 'object-cache.php' );
		$src   = (string) @file_get_contents( __DIR__ . '/pagecache/object-cache.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$want  = db_pc_enabled( 'object' ) && function_exists( 'apcu_enabled' ) && apcu_enabled() && '' !== $src;
		if ( $want && 'none' === $state ) {
			$notes[] = db_pc_install_checked( 'object-cache.php', $src );
		} elseif ( $want && 'ours' === $state && (string) @file_get_contents( WP_CONTENT_DIR . '/object-cache.php' ) !== $src ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			db_pc_write( WP_CONTENT_DIR . '/object-cache.php', $src );
			wp_cache_flush();
		} elseif ( ! $want && 'ours' === $state ) {
			wp_cache_flush();
			@unlink( WP_CONTENT_DIR . '/object-cache.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$notes[] = 'Object cache drop-in removed.';
		} elseif ( $want && 'none' !== $state && 'ours' !== $state ) {
			$notes[] = 'Another plugin owns wp-content/object-cache.php, so the APCu object cache was not installed.';
		}
		return $notes;
	}
}

/* ------------------------------------------------------- PHP file checks */

/*
 * The host runs OPcache with revalidate_freq=0: PHP checks every one of the
 * ~500 files a request loads on disk, on every request, and WordPress core is
 * on a shared host path. A managed block in the site's .user.ini lets PHP
 * check at most once a minute. WordPress invalidates OPcache itself for files
 * it updates (plugin and theme updates), so deploys still show at once.
 * opcache.revalidate_freq is changeable per directory (PHP_INI_ALL).
 */
define( 'DB_SPEED_INI_BEGIN', '; BEGIN Domain Brothers speed (managed by the Domain Brothers plugin)' );
define( 'DB_SPEED_INI_END', '; END Domain Brothers speed' );

if ( ! function_exists( 'db_speed_ini_path' ) ) {
	function db_speed_ini_path() {
		$root = realpath( WP_CONTENT_DIR . '/..' );
		return $root ? $root . '/' . ( ini_get( 'user_ini.filename' ) ? ini_get( 'user_ini.filename' ) : '.user.ini' ) : '';
	}
}

if ( ! function_exists( 'db_speed_ini_sync' ) ) {
	/** Adds or removes the managed block; leaves every other line untouched. */
	function db_speed_ini_sync() {
		$path = db_speed_ini_path();
		if ( '' === $path || '' === (string) ini_get( 'user_ini.filename' ) ) {
			return 'This server does not read per-directory PHP settings.';
		}
		$cur  = file_exists( $path ) ? (string) @file_get_contents( $path ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		$base = preg_replace( '/\n?' . preg_quote( DB_SPEED_INI_BEGIN, '/' ) . '.*?' . preg_quote( DB_SPEED_INI_END, '/' ) . '\n?/s', "\n", $cur );
		$base = rtrim( (string) $base );
		$want = db_pc_enabled( 'phpchecks' );
		$new  = $want ? ( '' === $base ? '' : $base . "\n\n" ) . DB_SPEED_INI_BEGIN . "\nopcache.revalidate_freq=60\n" . DB_SPEED_INI_END . "\n" : ( '' === $base ? '' : $base . "\n" );
		if ( $new === $cur ) {
			return '';
		}
		if ( '' === $new ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return 'PHP file checks restored to every request.';
		}
		return db_pc_write( $path, $new ) ? ( $want ? 'PHP now checks files for changes once a minute (applies within 5 minutes).' : 'PHP file checks restored to every request.' ) : 'Could not write ' . basename( $path ) . '.';
	}
}

// Check once per plugin version, and whenever the switches change.
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$sig = ( defined( 'DB_BLOCKS_LOADED' ) ? DB_BLOCKS_LOADED : '' ) . '|' . md5( wp_json_encode( get_option( 'db_speed_switches', array() ) ) );
	if ( get_option( 'db_speed_dropins_sig' ) === $sig ) {
		return;
	}
	db_pc_sync_dropins();
	db_speed_ini_sync();
	update_option( 'db_speed_dropins_sig', $sig, false );
} );

// Deactivating the plugin removes both drop-ins and the saved pages.
register_deactivation_hook( dirname( __FILE__ ) . '/db-custom-blocks.php', function () {
	foreach ( array( 'advanced-cache.php', 'object-cache.php' ) as $f ) {
		if ( 'ours' === db_pc_dropin_state( $f ) ) {
			@unlink( WP_CONTENT_DIR . '/' . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
	db_pc_purge_all();
	update_option( 'db_speed_switches', array_merge( (array) get_option( 'db_speed_switches', array() ), array( 'phpchecks' => 0 ) ), false );
	db_speed_ini_sync();
	delete_option( 'db_speed_switches' );
	delete_option( 'db_speed_dropins_sig' );
} );

/* ------------------------------------------------------------- purging */

if ( ! function_exists( 'db_pc_purge_all' ) ) {
	function db_pc_purge_all() {
		if ( ! is_dir( DB_PC_DIR ) ) {
			return;
		}
		$old = DB_PC_DIR . '-old-' . time() . '-' . wp_generate_password( 4, false );
		if ( @rename( DB_PC_DIR, $old ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			db_pc_rmdir( $old );
		} else {
			db_pc_rmdir( DB_PC_DIR );
		}
		do_action( 'db_pc_purged_all' );
	}
}
add_action( 'db_page_cache_purge_all', 'db_pc_purge_all' );

/*
 * The host runs LiteSpeed Cache, which keeps its own saved pages and does not hear about our
 * plugin updates: a deployed fix can stay invisible on pages it cached earlier. Clear it
 * whenever we purge, and once after every plugin version change.
 */
add_action( 'db_pc_purged_all', function () {
	do_action( 'litespeed_purge_all' );
} );
add_action( 'init', function () {
	if ( defined( 'DB_BLOCKS_LOADED' ) && get_option( 'db_ls_purged_ver' ) !== DB_BLOCKS_LOADED ) {
		update_option( 'db_ls_purged_ver', DB_BLOCKS_LOADED, false );
		do_action( 'litespeed_purge_all' );
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Purge: *' ); // server-level purge, works even if the LiteSpeed Cache plugin ignores the action
		}
	}
}, 99 );

if ( ! function_exists( 'db_pc_purge_url' ) ) {
	/** Clears every saved variant of one URL. */
	function db_pc_purge_url( $url ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return;
		}
		$path = isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/';
		foreach ( array( 'https', 'http' ) as $scheme ) {
			db_pc_rmdir( DB_PC_DIR . '/' . md5( $scheme . '://' . strtolower( $p['host'] ) . $path ) );
		}
	}
}

// Collected during the request, applied once at the end.
$GLOBALS['db_pc_pending'] = array( 'all' => false, 'urls' => array() );

if ( ! function_exists( 'db_pc_queue_all' ) ) {
	function db_pc_queue_all() {
		$GLOBALS['db_pc_pending']['all'] = true;
	}
}

add_action( 'shutdown', function () {
	$p = $GLOBALS['db_pc_pending'];
	if ( $p['all'] ) {
		db_pc_purge_all();
	} else {
		foreach ( array_unique( $p['urls'] ) as $u ) {
			db_pc_purge_url( $u );
		}
	}
}, 0 );

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'publish' === $new || 'publish' === $old ) {
		if ( ! wp_is_post_revision( $post ) && ! wp_is_post_autosave( $post ) && 'nav_menu_item' !== $post->post_type ) {
			db_pc_queue_all();
		}
	}
}, 10, 3 );
add_action( 'save_post', function ( $id, $post ) {
	if ( 'publish' === $post->post_status && ! wp_is_post_revision( $id ) && ! wp_is_post_autosave( $id ) ) {
		db_pc_queue_all();
	}
}, 10, 2 );
foreach ( array( 'deleted_post', 'trashed_post', 'untrashed_post', 'edited_term', 'created_term', 'delete_term', 'wp_update_nav_menu', 'customize_save_after', 'switch_theme', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'comment_post', 'wp_set_comment_status' ) as $db_pc_hook ) {
	add_action( $db_pc_hook, 'db_pc_queue_all' );
}
unset( $db_pc_hook );

// Listing data changed outside the editor (sale, sold, transfer, price).
$db_pc_meta = function ( $meta_id, $object_id, $meta_key ) {
	if ( in_array( $meta_key, array( '_edit_lock', '_edit_last', 'db_rdap_until' ), true ) ) {
		return;
	}
	$post = get_post( (int) $object_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}
	if ( 'db_rdap' === $meta_key ) {
		$GLOBALS['db_pc_pending']['urls'][] = get_permalink( $post );
		return;
	}
	db_pc_queue_all();
};
add_action( 'added_post_meta', $db_pc_meta, 10, 3 );
add_action( 'updated_post_meta', $db_pc_meta, 10, 3 );
add_action( 'deleted_post_meta', $db_pc_meta, 10, 3 );
unset( $db_pc_meta );

// A settings form saved by a person in wp-admin (not background counters).
add_action( 'updated_option', function ( $option ) {
	// Options can change before WordPress knows the user (plugins save options while loading).
	if ( ! did_action( 'init' ) || ! function_exists( 'wp_get_current_user' ) || ! is_admin() || wp_doing_ajax() || wp_doing_cron() || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( 0 === strpos( $option, '_transient' ) || 0 === strpos( $option, '_site_transient' ) || in_array( $option, array( 'cron', 'db_speed_dropins_sig', 'db_cron_fallback_last' ), true ) ) {
		return;
	}
	db_pc_queue_all();
} );

/* -------------------------------------------------------------- saving */

add_action( 'template_redirect', function () {
	if ( ! defined( 'DB_PC_CANDIDATE' ) || ! db_pc_request() || is_user_logged_in() ) {
		return;
	}
	if ( is_preview() || is_search() || is_404() || is_feed() || is_robots() || is_trackback() || is_customize_preview() || ( is_singular() && post_password_required() ) ) {
		return;
	}
	header( 'X-DB-Cache: MISS' );
	ob_start( 'db_pc_capture' ); // Outermost buffer: it saves the page after every other rewrite.
}, -1000 );

if ( ! function_exists( 'db_pc_capture' ) ) {
	/** Output-buffer callback: saves a finished page, returns it unchanged. */
	function db_pc_capture( $html ) {
		if ( ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) || 200 !== http_response_code() || ! is_string( $html ) || strlen( $html ) < 1024 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}
		$req = db_pc_request();
		if ( ! $req ) {
			return $html;
		}
		$ct   = 'text/html; charset=' . get_option( 'blog_charset', 'UTF-8' );
		$keep = array();
		foreach ( headers_list() as $h ) {
			$name = strtolower( trim( strtok( $h, ':' ) ) );
			if ( 'set-cookie' === $name ) {
				if ( 0 !== stripos( ltrim( substr( $h, 11 ) ), 'db_exp_cta=' ) ) {
					return $html; // A page that signs someone in or stores state is never saved.
				}
				continue;
			}
			if ( 'content-type' === $name ) {
				if ( false === stripos( $h, 'text/html' ) ) {
					return $html;
				}
				$ct = trim( substr( $h, strpos( $h, ':' ) + 1 ) );
				continue;
			}
			if ( in_array( $name, array( 'content-length', 'date', 'expires', 'pragma', 'cache-control', 'age', 'last-modified', 'etag', 'x-db-cache', 'x-powered-by', 'x-litespeed-cache-control', 'x-litespeed-tag', 'x-litespeed-cache', 'www-authenticate', 'location', 'status' ), true ) ) {
				continue;
			}
			$keep[] = $h;
		}
		if ( ! wp_mkdir_p( $req['dir'] ) ) {
			return $html;
		}
		$meta = wp_json_encode( array( 'ct' => $ct, 'h' => $keep, 't' => time() ) );
		db_pc_write( $req['file'], DB_PC_GUARD . $meta . "\n" . $html . "\n<!-- Saved by the Domain Brothers page cache " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n" );
		return $html;
	}
}

/* ------------------------------------------------------------- warming */

/*
 * After a purge, and every hour, saved pages are rebuilt in the background so
 * the first visitor does not wait for a full render. Domain pages are saved
 * for both experiment variants. A run stops after 20 seconds and continues a
 * minute later. When the site answers 401 (a staging password), warming stops
 * and the Platform page says so; it works on a site open to the public.
 */
if ( ! function_exists( 'db_pc_warm_list' ) ) {
	/** URLs to keep warm, most visited first: home, listings index, pages, domains, recent posts. */
	function db_pc_warm_list() {
		$urls = array( home_url( '/' ) );
		$ids  = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 60, 'fields' => 'ids', 'orderby' => 'menu_order', 'order' => 'ASC', 'has_password' => false ) );
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}
		$ids = get_posts( array( 'post_type' => 'domain', 'post_status' => 'publish', 'numberposts' => 500, 'fields' => 'ids', 'orderby' => 'modified', 'order' => 'DESC' ) );
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}
		$ids = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 30, 'fields' => 'ids' ) );
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}
		$out = array();
		foreach ( array_unique( array_filter( $urls ) ) as $u ) {
			$path = strtolower( (string) wp_parse_url( $u, PHP_URL_PATH ) );
			if ( '' === $path || db_pc_skip_path( $path ) ) {
				continue;
			}
			$out[] = $u;
		}
		return $out;
	}
}

if ( ! function_exists( 'db_pc_warm_run' ) ) {
	function db_pc_warm_run() {
		if ( 'ours' !== db_pc_dropin_state( 'advanced-cache.php' ) || ! db_pc_enabled( 'page' ) ) {
			return;
		}
		$state  = array( 'at' => time(), 'saved' => 0, 'fresh' => 0, 'failed' => 0, 'blocked' => false, 'more' => false );
		$start  = microtime( true );
		foreach ( db_pc_warm_list() as $url ) {
			$path     = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$variants = 0 === strpos( $path, '/domains/' ) ? array( 'A', 'B' ) : array( 'x' );
			foreach ( $variants as $v ) {
				$file  = db_pc_file_for( $url, $v );
				$mtime = $file ? (int) @filemtime( $file ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( $mtime && ( time() - $mtime ) < DB_PC_TTL - HOUR_IN_SECONDS ) {
					++$state['fresh'];
					continue;
				}
				if ( microtime( true ) - $start > 20 ) {
					$state['more'] = true;
					break 2;
				}
				$args = array( 'timeout' => 15, 'redirection' => 0, 'user-agent' => 'DomainBrothersCacheWarmer/1.0 (+' . home_url( '/' ) . ')', 'headers' => array( 'Accept' => 'text/html' ) );
				if ( 'x' !== $v ) {
					$args['cookies'] = array( new WP_Http_Cookie( array( 'name' => 'db_exp_cta', 'value' => $v ) ) );
				}
				$res  = wp_remote_get( $url, $args );
				$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
				if ( 401 === $code ) {
					$state['blocked'] = true;
					break 2;
				}
				if ( 200 === $code && $file && file_exists( $file ) ) {
					++$state['saved'];
				} else {
					++$state['failed'];
				}
			}
		}
		update_option( 'db_pc_warm_state', $state, false );
		if ( $state['more'] && ! wp_next_scheduled( 'db_pc_warm' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'db_pc_warm' );
		}
	}
}
add_action( 'db_pc_warm', 'db_pc_warm_run' );
add_action( 'db_pc_warm_hourly', 'db_pc_warm_run' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'db_pc_warm_hourly' ) ) {
		wp_schedule_event( time() + 10 * MINUTE_IN_SECONDS, 'hourly', 'db_pc_warm_hourly' );
	}
} );
// A full purge schedules a rebuild half a minute later (after the change settles).
add_action( 'db_pc_purged_all', function () {
	if ( ! wp_next_scheduled( 'db_pc_warm' ) ) {
		wp_schedule_single_event( time() + 30, 'db_pc_warm' );
	}
} );

/* ------------------------------------------------- views counted once */

if ( ! function_exists( 'db_pc_beacon_mode' ) ) {
	/** True when views are counted by a beacon because pages may come from the cache. */
	function db_pc_beacon_mode() {
		return defined( 'DB_PC_CANDIDATE' );
	}
}

add_action( 'wp_footer', function () {
	if ( is_admin() || ! db_pc_beacon_mode() || ! is_singular( 'domain' ) ) {
		return;
	}
	$id = (int) get_queried_object_id();
	echo '<script>(function(){try{var c=document.cookie.match(/(?:^|; )db_exp_cta=([AB])/),v=c?c[1]:"A",u=' . wp_json_encode( rest_url( 'db/v1/funnel' ) ) . ';'
		. 'function s(e,p){fetch(u,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({ev:e,post:p||0})}).then(function(r){return r.text();}).catch(function(){});}'
		. 's("listing_view",' . $id . ');s("exp_view_"+v);document.querySelectorAll("a[data-exp]").forEach(function(a){a.setAttribute("data-exp",v);});}catch(e){}})();</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
}, 20 );

/* ----------------------------------------------------- Platform section */

add_action( 'admin_post_db_speed_settings', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_speed_settings' );
	$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
	if ( 'purge' === $do ) {
		db_pc_purge_all();
		wp_cache_flush();
		$notes = array( 'Saved pages and the object cache were cleared.' );
	} else {
		$sw           = get_option( 'db_speed_switches', array() );
		$sw           = is_array( $sw ) ? $sw : array();
		$sw['page']   = ! empty( $_POST['page'] ) ? 1 : 0;
		$sw['object']    = ! empty( $_POST['object'] ) ? 1 : 0;
		$sw['phpchecks'] = ! empty( $_POST['phpchecks'] ) ? 1 : 0;
		update_option( 'db_speed_switches', $sw, false );
		$notes   = db_pc_sync_dropins();
		$notes[] = db_speed_ini_sync();
		$notes   = array_filter( $notes );
		update_option( 'db_speed_dropins_sig', ( defined( 'DB_BLOCKS_LOADED' ) ? DB_BLOCKS_LOADED : '' ) . '|' . md5( wp_json_encode( get_option( 'db_speed_switches', array() ) ) ), false );
		$notes[] = 'Saved.';
	}
	set_transient( 'db_speed_notes', $notes, 120 );
	wp_safe_redirect( admin_url( 'admin.php?page=db-platform' ) );
	exit;
} );

add_action( 'db_platform_sections', function () {
	$notes = get_transient( 'db_speed_notes' );
	if ( is_array( $notes ) && $notes ) {
		delete_transient( 'db_speed_notes' );
		echo '<div class="notice notice-info"><p>' . esc_html( implode( ' ', $notes ) ) . '</p></div>';
	}
	$count = 0;
	$size  = 0;
	if ( is_dir( DB_PC_DIR ) ) {
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( DB_PC_DIR, FilesystemIterator::SKIP_DOTS ) ) as $f ) {
			if ( $f->isFile() && '.php' === substr( $f->getFilename(), -4 ) ) {
				++$count;
				$size += $f->getSize();
			}
		}
	}
	$pstate = db_pc_dropin_state( 'advanced-cache.php' );
	$ostate = db_pc_dropin_state( 'object-cache.php' );
	$label  = array( 'ours' => 'installed', 'none' => 'not installed', 'litespeed' => 'LiteSpeed Cache drop-in', 'other' => 'another plugin\'s drop-in' );
	$apcu   = function_exists( 'apcu_sma_info' ) && function_exists( 'apcu_enabled' ) && apcu_enabled() ? @apcu_sma_info( true ) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors
	echo '<h2>Speed</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><table class="widefat striped" style="max-width:980px"><tbody>';
	echo '<tr><th scope="row" style="width:260px">Page cache</th><td><label><input type="checkbox" name="page" value="1"' . checked( db_pc_enabled( 'page' ), true, false ) . '> Serve saved pages to signed-out visitors</label><br><small>Drop-in: ' . esc_html( $label[ $pstate ] ) . ( db_pc_server_is_litespeed() ? ' &middot; LiteSpeed server detected: LiteSpeed Cache handles pages here' : '' ) . ' &middot; ' . (int) $count . ' saved pages, ' . esc_html( size_format( $size ) ) . ' &middot; pages are kept up to 10 hours and cleared on every content or settings change.</small></td></tr>';
	echo '<tr><th scope="row">Object cache (APCu)</th><td><label><input type="checkbox" name="object" value="1"' . checked( db_pc_enabled( 'object' ), true, false ) . '> Keep WordPress data in shared memory between requests</label><br><small>Drop-in: ' . esc_html( $label[ $ostate ] ) . ( $apcu ? ' &middot; APCu ' . esc_html( size_format( (float) $apcu['seg_size'] * (int) $apcu['num_seg'] ) ) . ', ' . esc_html( size_format( (float) $apcu['avail_mem'] ) ) . ' free' : ' &middot; APCu is not available' ) . '</small></td></tr>';
	$freq = (int) ini_get( 'opcache.revalidate_freq' );
	$ini  = db_speed_ini_path();
	$has  = $ini && file_exists( $ini ) && false !== strpos( (string) @file_get_contents( $ini ), DB_SPEED_INI_BEGIN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
	if ( '' === (string) ini_get( 'user_ini.filename' ) ) {
		echo '<tr><th scope="row">PHP file checks</th><td>PHP checks every code file for changes on every request (opcache.revalidate_freq=' . (int) $freq . '). This host does not allow per-directory PHP settings, so only the host can change it. Ask for <code>opcache.revalidate_freq=60</code>: it saves time on every page that is not served from the page cache.</td></tr>';
	} else {
		echo '<tr><th scope="row">PHP file checks</th><td><label><input type="checkbox" name="phpchecks" value="1"' . checked( db_pc_enabled( 'phpchecks' ), true, false ) . '> Let PHP check code files for changes once a minute instead of on every request</label><br><small>Now: every ' . ( $freq > 0 ? (int) $freq . ' s' : 'request' ) . '. ' . ( $has ? 'Written in ' . esc_html( basename( $ini ) ) . ' at the site root' . ( $freq > 0 ? '' : '; PHP applies it within ' . (int) ini_get( 'user_ini.cache_ttl' ) . ' s' ) : 'Not written yet' ) . '. WordPress refreshes files it updates straight away.</small></td></tr>';
	}
	$ws = get_option( 'db_pc_warm_state' );
	echo '<tr><th scope="row">Cache warming</th><td>';
	if ( ! is_array( $ws ) ) {
		echo 'Not run yet. Saved pages are rebuilt in the background after every purge and each hour.';
	} elseif ( ! empty( $ws['blocked'] ) ) {
		echo 'Paused: the site asks for a password (staging), so pages are saved as people visit. Warming runs once the site is public.';
	} else {
		echo 'Last run ' . esc_html( human_time_diff( (int) $ws['at'] ) ) . ' ago: ' . (int) $ws['saved'] . ' pages saved, ' . (int) $ws['fresh'] . ' already fresh, ' . (int) $ws['failed'] . ' not saved' . ( ! empty( $ws['more'] ) ? ', continuing.' : '.' );
	}
	echo '</td></tr></tbody></table>';
	wp_nonce_field( 'db_speed_settings' );
	echo '<input type="hidden" name="action" value="db_speed_settings"><p><button class="button button-primary" name="do" value="save">Save speed settings</button> <button class="button" name="do" value="purge">Clear saved pages and object cache</button></p></form>';
} );
