<?php
/**
 * Domain Brothers page cache: request rules and serving.
 *
 * Loaded by wp-content/advanced-cache.php before WordPress loads plugins, and
 * by the plugin itself (db-pagecache-block.php) to save pages. Both use the
 * same rules here, so a page is saved and served under the same key.
 *
 * A request is cacheable when it is a GET or HEAD from a signed-out visitor,
 * carries no query string other than tracking parameters, has no sign-in or
 * account cookie, and its path is not on the skip list. The key holds the
 * host, the path, the buy-button experiment variant, and a hash of the
 * Authorization header, so a staging password can never be bypassed: a
 * request without the right credentials never matches a saved page.
 *
 * Saved files are PHP files that exit at once, so a saved page can never be
 * fetched directly from wp-content.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_PC_DIR' ) ) {
	define( 'DB_PC_DIR', WP_CONTENT_DIR . '/cache/db-page' );
}
if ( ! defined( 'DB_PC_TTL' ) ) {
	// Ten hours: well inside the 12-hour minimum life of the form nonces a page carries.
	define( 'DB_PC_TTL', 10 * 3600 );
}
if ( ! defined( 'DB_PC_GUARD' ) ) {
	define( 'DB_PC_GUARD', "<?php exit; ?>\n" );
}

if ( ! function_exists( 'db_pc_skip_path' ) ) {
	/** True for (lower-case) paths that are never cached: admin, API, personal and transactional pages. */
	function db_pc_skip_path( $lower ) {
		return (bool) preg_match( '#^/(wp-admin|wp-login|wp-json|wp-content|wp-includes|wp-cron|xmlrpc|feed|comments/feed|checkout|thank-you|my-wishlist|my-account|account|compare-domains|find-a-domain|trust-center|cart|sign-in|signin|login|log-in|register|sign-up|signup|create-account|transfer)(/|\.|$)#', $lower )
			|| (bool) preg_match( '#(/feed/?|\.(php|xml|txt|json|xsl|ico|map))$#', $lower );
	}
}

if ( ! function_exists( 'db_pc_file_for' ) ) {
	/** Saved-page file for a URL as a signed-out visitor without credentials sees it. */
	function db_pc_file_for( $url, $variant = 'x' ) {
		$p = parse_url( $url );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$path = isset( $p['path'] ) && '' !== $p['path'] ? rawurldecode( $p['path'] ) : '/';
		return DB_PC_DIR . '/' . md5( ( isset( $p['scheme'] ) ? strtolower( $p['scheme'] ) : 'https' ) . '://' . strtolower( $p['host'] ) . $path ) . '/' . $variant . '-none.php';
	}
}

if ( ! function_exists( 'db_pc_request' ) ) {
	/**
	 * Cache location for the current request, or false when it must not be cached.
	 *
	 * @return array{dir:string,file:string,variant:string,assign:bool}|false
	 */
	function db_pc_request() {
		static $memo = null;
		if ( null !== $memo ) {
			return $memo;
		}
		$memo = false;
		if ( ( 'cli' === PHP_SAPI && ! defined( 'DB_PC_TEST' ) ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return $memo;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return $memo;
		}
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( preg_replace( '/:\d+$/', '', (string) $_SERVER['HTTP_HOST'] ) ) : '';
		if ( '' === $host || ! preg_match( '/^[a-z0-9.-]+$/', $host ) ) {
			return $memo;
		}
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
		$parts = explode( '?', $uri, 2 );
		$path  = rawurldecode( $parts[0] );
		if ( '' === $path || '/' !== $path[0] || strlen( $path ) > 300 || false !== strpos( $path, '..' ) ) {
			return $memo;
		}
		// Only tracking parameters may be present; anything else changes the page.
		if ( isset( $parts[1] ) && '' !== $parts[1] ) {
			$q = array();
			parse_str( $parts[1], $q );
			foreach ( array_keys( $q ) as $k ) {
				if ( ! preg_match( '/^(utm_[a-z_]+|gclid|gbraid|wbraid|fbclid|msclkid|dclid|_ga|_gl|mc_cid|mc_eid|ref)$/i', (string) $k ) ) {
					return $memo;
				}
			}
		}
		$lower = strtolower( $path );
		if ( db_pc_skip_path( $lower ) ) {
			return $memo;
		}
		$variant = 'x';
		$assign  = false;
		foreach ( array_keys( $_COOKIE ) as $name ) {
			$name = (string) $name;
			if ( preg_match( '/^(wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_|wp_woocommerce_|woocommerce_|db_wl)/', $name ) ) {
				return $memo;
			}
		}
		// Buy-button copy experiment: domain pages vary by its cookie. A first
		// visit is assigned here, so it can be served from the cache too.
		if ( 0 === strpos( $lower, '/domains/' ) ) {
			$c = isset( $_COOKIE['db_exp_cta'] ) ? (string) $_COOKIE['db_exp_cta'] : '';
			if ( 'A' === $c || 'B' === $c ) {
				$variant = $c;
			} else {
				$variant = ( 0 === random_int( 0, 1 ) ) ? 'A' : 'B';
				$assign  = true;
			}
		}
		$auth = '';
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		} elseif ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			$auth = 'Basic ' . base64_encode( (string) $_SERVER['PHP_AUTH_USER'] . ':' . ( isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) $_SERVER['PHP_AUTH_PW'] : '' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		}
		$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) );
		$dir   = DB_PC_DIR . '/' . md5( ( $https ? 'https' : 'http' ) . '://' . $host . $path );
		$file  = $dir . '/' . $variant . '-' . ( '' === $auth ? 'none' : substr( hash( 'sha256', $auth ), 0, 20 ) ) . '.php';
		$memo  = array( 'dir' => $dir, 'file' => $file, 'variant' => $variant, 'assign' => $assign );
		return $memo;
	}
}

if ( ! function_exists( 'db_pc_set_variant_cookie' ) ) {
	/** Sends and records the experiment cookie chosen for a first visit. */
	function db_pc_set_variant_cookie( array $req ) {
		if ( ! $req['assign'] || headers_sent() ) {
			return;
		}
		$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) ) || ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) );
		setcookie( 'db_exp_cta', $req['variant'], array( 'expires' => time() + 30 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => false, 'samesite' => 'Lax' ) );
		$_COOKIE['db_exp_cta'] = $req['variant'];
	}
}

if ( ! function_exists( 'db_pc_serve' ) ) {
	/** Sends the saved copy of this page and ends the request, when there is a fresh one. */
	function db_pc_serve() {
		$req = db_pc_request();
		if ( ! $req ) {
			return;
		}
		if ( ! defined( 'DB_PC_CANDIDATE' ) ) {
			define( 'DB_PC_CANDIDATE', true );
		}
		$mtime = @filemtime( $req['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $mtime || ( time() - $mtime ) > DB_PC_TTL ) {
			db_pc_set_variant_cookie( $req );
			return;
		}
		$raw = @file_get_contents( $req['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		if ( ! is_string( $raw ) || 0 !== strpos( $raw, DB_PC_GUARD ) ) {
			db_pc_set_variant_cookie( $req );
			return;
		}
		$nl   = strpos( $raw, "\n", strlen( DB_PC_GUARD ) );
		$meta = false === $nl ? null : json_decode( substr( $raw, strlen( DB_PC_GUARD ), $nl - strlen( DB_PC_GUARD ) ), true );
		if ( ! is_array( $meta ) || empty( $meta['ct'] ) ) {
			db_pc_set_variant_cookie( $req );
			return;
		}
		db_pc_set_variant_cookie( $req );
		// Replay the headers the page was sent with (security headers, robots, link).
		foreach ( (array) ( $meta['h'] ?? array() ) as $line ) {
			$line = preg_replace( '/[\r\n]/', '', (string) $line );
			if ( preg_match( '/^[A-Za-z0-9-]+:/', $line ) ) {
				header( $line, false );
			}
		}
		header( 'Content-Type: ' . preg_replace( '/[\r\n]/', '', (string) $meta['ct'] ) );
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
		header( 'X-DB-Cache: HIT' );
		header( 'Age: ' . max( 0, time() - $mtime ) );
		if ( 'HEAD' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			echo substr( $raw, $nl + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		exit;
	}
}

if ( ! function_exists( 'db_pc_rmdir' ) ) {
	/** Deletes a cache directory tree. Only paths inside wp-content/cache are touched. */
	function db_pc_rmdir( $dir ) {
		$root = realpath( WP_CONTENT_DIR . '/cache' );
		$real = realpath( $dir );
		if ( ! $root || ! $real || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
			return;
		}
		$items = @scandir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		foreach ( (array) $items as $i ) {
			if ( '.' === $i || '..' === $i ) {
				continue;
			}
			$p = $real . DIRECTORY_SEPARATOR . $i;
			if ( is_dir( $p ) && ! is_link( $p ) ) {
				db_pc_rmdir( $p );
			} else {
				@unlink( $p ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}

if ( defined( 'DB_PC_FROM_DROPIN' ) ) {
	db_pc_serve();
}
