<?php
/**
 * Log manager: one place to see every error the site meets, and to trace it back.
 *
 * What is recorded (source in brackets):
 *  - PHP warnings and errors [php], and fatal errors at shutdown [fatal]
 *  - emails WordPress could not send [mail] and Contact Form 7 send failures [form]
 *  - failed outgoing HTTP calls to Stripe, SendGrid, HubSpot, Cloudflare and other APIs [http]
 *  - errors reported by plugin modules through db_log() [stripe, connect, seller, crm, ...]
 *  - JavaScript errors in visitors' browsers, rate limited [js]
 *
 * How it stays small and safe:
 *  - Repeats are folded: the same error (same source, level, message and place) on the same day
 *    is one row with a count and a last-seen time, so a loop cannot flood the table.
 *  - At most DB_LOG_MAX_PER_MIN new rows a minute; the rest are counted in one summary row.
 *  - Messages and context are capped in length, and emails, API keys, tokens and card numbers
 *    are masked before anything is stored (db_log_redact()).
 *  - 12 months stay in the database. A daily job moves older rows, month by month, into gzip
 *    files (one JSON line per row) under uploads/db-logs/ with unguessable names, then deletes
 *    them from the table. The table is also capped at DB_LOG_MAX_ROWS rows (oldest archived
 *    first), and archives are capped at DB_LOG_ARCHIVE_MAX_MB (oldest archive files removed).
 *    So neither the database nor the disk can grow without limit.
 *  - Archives are downloaded only through WP Admin (capability check), never by direct URL.
 *
 * Admin: Tools > Error log (filters, search, details, CSV export, archives, daily email setting).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_LOG_DB_VERSION' ) ) {
	define( 'DB_LOG_DB_VERSION', '1' );
}
if ( ! defined( 'DB_LOG_RETENTION_DAYS' ) ) {
	define( 'DB_LOG_RETENTION_DAYS', 365 );
}
if ( ! defined( 'DB_LOG_MAX_ROWS' ) ) {
	define( 'DB_LOG_MAX_ROWS', 200000 );
}
if ( ! defined( 'DB_LOG_MAX_PER_MIN' ) ) {
	define( 'DB_LOG_MAX_PER_MIN', 120 );
}
if ( ! defined( 'DB_LOG_ARCHIVE_MAX_MB' ) ) {
	define( 'DB_LOG_ARCHIVE_MAX_MB', 500 );
}

/* ------------------------------------------------------------- pure helpers */

if ( ! function_exists( 'db_log_redact' ) ) {
	/**
	 * Pure: masks personal data and secrets in a string before it is stored.
	 * Emails, Stripe-style keys and secrets, bearer tokens, long hex or base64 tokens,
	 * card-like digit runs and passwords in query strings.
	 */
	function db_log_redact( $text ) {
		$text = (string) $text;
		$rules = array(
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i'                                  => '[email]',
			'/\b(sk|pk|rk|whsec|acct|cus|pm|pi|seti|ch)_(live|test)_[A-Za-z0-9]{6,}/'     => '$1_$2_[redacted]',
			'/\bwhsec_[A-Za-z0-9]{8,}/'                                                   => 'whsec_[redacted]',
			'/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=\-]{8,}/i'                              => '$1 [redacted]',
			'/\b(SG|xox[abp])\.[A-Za-z0-9._\-]{10,}/'                                     => '[token]',
			'/\b(?:\d[ \-]?){13,19}\b/'                                                   => '[number]',
			'/([?&](?:pass(?:word)?|pwd|token|key|secret|code|nonce|_wpnonce)=)[^&\s"\']+/i' => '$1[redacted]',
			'/\b[a-f0-9]{40,}\b/i'                                                        => '[hex]',
			'/\b(code|passcode|PIN)(:?\s*)\d{4,8}\b/i'                                    => '$1$2[code]',
		);
		foreach ( $rules as $re => $to ) {
			$out = preg_replace( $re, $to, $text );
			$text = null === $out ? $text : $out;
		}
		return $text;
	}
}

if ( ! function_exists( 'db_log_fingerprint' ) ) {
	/**
	 * Pure: the key used to fold repeats. Numbers and quoted values are normalised, so
	 * "post 123 not found" and "post 456 not found" count as the same error.
	 */
	function db_log_fingerprint( $source, $level, $message, $where = '' ) {
		$norm = preg_replace( array( '/\d+/', "/'[^']*'|\"[^\"]*\"/" ), array( '#', '?' ), strtolower( (string) $message ) );
		return md5( $source . '|' . $level . '|' . $norm . '|' . $where );
	}
}

if ( ! function_exists( 'db_log_level_of' ) ) {
	/** Pure: a PHP error number as one of the log levels. */
	function db_log_level_of( $errno ) {
		switch ( (int) $errno ) {
			case E_ERROR:
			case E_PARSE:
			case E_CORE_ERROR:
			case E_COMPILE_ERROR:
			case E_USER_ERROR:
			case E_RECOVERABLE_ERROR:
				return 'error';
			case E_WARNING:
			case E_CORE_WARNING:
			case E_COMPILE_WARNING:
			case E_USER_WARNING:
				return 'warning';
			default:
				return 'notice';
		}
	}
}

if ( ! function_exists( 'db_log_archive_months' ) ) {
	/**
	 * Pure: the months (Y-m) whose rows are all older than the retention cut-off, oldest first,
	 * from the oldest row's date to the cut-off.
	 */
	function db_log_archive_months( $oldest_ymd, $now_ts, $days = DB_LOG_RETENTION_DAYS ) {
		$cut = strtotime( gmdate( 'Y-m-01', $now_ts - $days * 86400 ) . ' 00:00:00 UTC' ); // first day of the cut-off month
		$t   = strtotime( substr( (string) $oldest_ymd, 0, 7 ) . '-01 00:00:00 UTC' );
		$out = array();
		while ( false !== $t && $t < $cut && count( $out ) < 240 ) {
			$out[] = gmdate( 'Y-m', $t );
			$t     = strtotime( '+1 month', $t );
		}
		return $out;
	}
}

/* ------------------------------------------------------------------ storage */

if ( ! function_exists( 'db_log_table' ) ) {
	function db_log_table() {
		global $wpdb;
		return $wpdb->prefix . 'db_log';
	}
}

add_action( 'init', function () {
	if ( DB_LOG_DB_VERSION === get_option( 'db_log_db_ver' ) ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = db_log_table();
	dbDelta( "CREATE TABLE {$t} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		created_at datetime NOT NULL,
		last_seen datetime NOT NULL,
		day date NOT NULL,
		level varchar(10) NOT NULL DEFAULT 'error',
		source varchar(24) NOT NULL DEFAULT 'php',
		message varchar(1000) NOT NULL DEFAULT '',
		context text NULL,
		place varchar(255) NOT NULL DEFAULT '',
		url varchar(255) NOT NULL DEFAULT '',
		hits int(10) unsigned NOT NULL DEFAULT 1,
		fp char(32) NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY fp_day (fp,day),
		KEY created_at (created_at),
		KEY level_source (level,source)
	) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'db_log_db_ver', DB_LOG_DB_VERSION, false );
}, 4 );

if ( ! function_exists( 'db_log' ) ) {
	/**
	 * Records one event. Safe to call from anywhere (never throws, never recurses).
	 *
	 * @param string $source  Short module name: php, fatal, mail, form, http, stripe, connect, seller, crm, js ...
	 * @param string $level   error, warning, notice or info.
	 * @param string $message What happened, in one line.
	 * @param array  $context Extra details (stored as JSON, redacted, capped at 4 KB).
	 * @param string $place   Where: file:line, function, or endpoint.
	 */
	function db_log( $source, $level, $message, array $context = array(), $place = '' ) {
		static $busy = false;
		global $wpdb;
		if ( $busy || ! isset( $wpdb ) || ! did_action( 'init' ) && ! doing_action( 'init' ) && 'fatal' !== $source ) {
			return; // too early (tables may not exist) or already inside a log write
		}
		$busy = true;
		try {
			$level   = in_array( $level, array( 'error', 'warning', 'notice', 'info' ), true ) ? $level : 'error';
			$source  = substr( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $source ) ), 0, 24 );
			// Server paths (they include the hosting account directory) are stripped from the message
			// as well as from the place (SC-7).
			$message = mb_substr( db_log_redact( trim( preg_replace( '/\s+/', ' ', str_replace( ABSPATH, '', (string) $message ) ) ) ), 0, 1000 );
			$place   = mb_substr( db_log_redact( str_replace( ABSPATH, '', (string) $place ) ), 0, 255 );
			$fp      = db_log_fingerprint( $source, $level, $message, $place );
			$now     = gmdate( 'Y-m-d H:i:s' );
			$day     = gmdate( 'Y-m-d' );
			$t       = db_log_table();
			$suppress = $wpdb->suppress_errors( true );
			// Fold a repeat into today's row.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$hit = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET hits = hits + 1, last_seen = %s WHERE fp = %s AND day = %s", $now, $fp, $day ) );
			if ( ! $hit ) {
				// New row: within the per-minute cap, or counted in a summary row.
				$minute = 'db_log_rate_' . gmdate( 'YmdHi' );
				$n      = (int) get_transient( $minute );
				if ( $n >= DB_LOG_MAX_PER_MIN ) {
					$sfp = db_log_fingerprint( 'log', 'warning', 'rate limit', $day );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					if ( ! $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET hits = hits + 1, last_seen = %s WHERE fp = %s AND day = %s", $now, $sfp, $day ) ) ) {
						$wpdb->insert( $t, array( 'created_at' => $now, 'last_seen' => $now, 'day' => $day, 'level' => 'warning', 'source' => 'log', 'message' => 'More than ' . DB_LOG_MAX_PER_MIN . ' new log entries in a minute; extra entries were counted here instead of stored.', 'context' => '', 'place' => '', 'url' => '', 'hits' => 1, 'fp' => $sfp ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					}
				} else {
					set_transient( $minute, $n + 1, 120 );
					$ctx = $context ? db_log_redact( (string) wp_json_encode( $context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR ) ) : '';
					$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ( defined( 'WP_CLI' ) ? 'wp-cli' : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					$wpdb->insert( $t, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						'created_at' => $now,
						'last_seen'  => $now,
						'day'        => $day,
						'level'      => $level,
						'source'     => '' !== $source ? $source : 'php',
						'message'    => $message,
						'context'    => mb_substr( $ctx, 0, 4000 ),
						'place'      => $place,
						'url'        => mb_substr( db_log_redact( $uri ), 0, 255 ),
						'hits'       => 1,
						'fp'         => $fp,
					) );
				}
			}
			$wpdb->suppress_errors( $suppress );
		} catch ( \Throwable $e ) {
			// The log must never break the page it is recording.
			unset( $e );
		}
		$busy = false;
	}
}

/* ------------------------------------------------------------------ capture */

// PHP warnings and errors. The previous handler (and PHP's own logging) still runs.
add_action( 'init', function () {
	$prev = set_error_handler( function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( &$prev ) {
		// Respect @-silenced calls and the configured error_reporting.
		if ( ! ( error_reporting() & $errno ) ) {
			return $prev ? call_user_func( $prev, $errno, $errstr, $errfile, $errline ) : false;
		}
		$level = db_log_level_of( $errno );
		// Notices and deprecations from third-party code are too noisy to keep; ours are kept.
		// Warnings from the DomainFolio theme are known and cannot be fixed here (the theme is not
		// edited): they were logged on every page view and buried real problems (SC-7).
		$theme_noise = 'error' !== $level && false !== strpos( str_replace( '\\', '/', (string) $errfile ), '/themes/DomainFolio/' );
		if ( ! $theme_noise && ( 'notice' !== $level || false !== strpos( (string) $errfile, 'db-custom-blocks' ) ) ) {
			db_log( 'php', $level, $errstr, array(), $errfile . ':' . $errline );
		}
		return $prev ? call_user_func( $prev, $errno, $errstr, $errfile, $errline ) : false;
	} );
}, 1 );

// Fatal errors (they bypass the handler above).
register_shutdown_function( function () {
	$e = error_get_last();
	if ( $e && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) && function_exists( 'db_log' ) ) {
		db_log( 'fatal', 'error', (string) $e['message'], array(), $e['file'] . ':' . $e['line'] );
	}
} );

// Emails WordPress could not send.
add_action( 'wp_mail_failed', function ( $err ) {
	if ( is_wp_error( $err ) ) {
		$d = (array) $err->get_error_data();
		// Subjects can carry one-time codes ("Your sign-in code: 123456"): digits are masked (batch 2, AD-2).
		$subject = preg_replace( '/\b\d{4,8}\b/', '[code]', (string) ( $d['subject'] ?? '' ) );
		db_log( 'mail', 'error', 'Email not sent: ' . $err->get_error_message(), array( 'subject' => (string) $subject ), 'wp_mail' );
	}
} );

// Contact Form 7: the visitor's message was saved (Tools > Form entries) but the email failed.
add_action( 'wpcf7_mail_failed', function ( $form ) {
	db_log( 'form', 'error', 'Contact form email failed: ' . ( is_object( $form ) && method_exists( $form, 'title' ) ? $form->title() : 'form' ), array(), 'wpcf7' );
} );

// Failed outgoing calls to the services the site depends on.
add_action( 'http_api_debug', function ( $response, $context, $class, $args, $url ) {
	unset( $context, $class, $args );
	$host = (string) wp_parse_url( (string) $url, PHP_URL_HOST );
	$watch = array( 'api.stripe.com', 'api.sendgrid.com', 'api.hubapi.com', 'challenges.cloudflare.com', 'api.indexnow.org', 'rdap.org', 'web.archive.org', 'api.hostinger.com' );
	$ours = false;
	foreach ( $watch as $w ) {
		$ours = $ours || $host === $w || str_ends_with( $host, '.' . $w );
	}
	if ( ! $ours ) {
		return;
	}
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
	if ( is_wp_error( $response ) ) {
		db_log( 'http', 'error', 'Request to ' . $host . ' failed: ' . $response->get_error_message(), array( 'path' => $path ), $host . $path );
		return;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code >= 500 || 429 === $code || ( $code >= 400 && 'rdap.org' !== $host && 'web.archive.org' !== $host ) ) {
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$msg  = is_array( $body ) ? (string) ( $body['error']['message'] ?? ( $body['errors'][0]['message'] ?? ( $body['message'] ?? '' ) ) ) : '';
		db_log( 'http', $code >= 500 ? 'error' : 'warning', $host . ' answered ' . $code . ( '' !== $msg ? ': ' . $msg : '' ), array( 'path' => $path ), $host . $path );
	}
}, 10, 5 );

// Browser JavaScript errors: a tiny reporter on public pages, posting to a rate-limited endpoint.
add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/log-js', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $r ) {
			// Exact same-site Origin (browsers send it on every POST). A prefix match let
			// https://<site>.evil.example through, and a missing Origin skipped the check (SC-6).
			$origin = (string) $r->get_header( 'origin' );
			$oh     = strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) );
			if ( '' === $oh || $oh !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
				return new WP_REST_Response( null, 204 );
			}
			// Site-wide cap for browser reports, so a script cannot push real errors out of the log.
			$mk = 'db_log_js_min_' . gmdate( 'YmdHi' );
			$mn = (int) get_transient( $mk );
			if ( $mn >= 30 ) {
				return new WP_REST_Response( null, 204 );
			}
			set_transient( $mk, $mn + 1, 2 * MINUTE_IN_SECONDS );
			$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$key = 'db_log_js_' . md5( $ip . gmdate( 'YmdH' ) );
			$n   = (int) get_transient( $key );
			if ( $n >= 20 ) {
				return new WP_REST_Response( null, 204 ); // 20 reports per visitor per hour
			}
			set_transient( $key, $n + 1, HOUR_IN_SECONDS );
			$d   = json_decode( (string) $r->get_body(), true );
			$msg = is_array( $d ) ? trim( (string) ( $d['m'] ?? '' ) ) : '';
			if ( '' === $msg || preg_match( '/^(Script error\.?|ResizeObserver loop)/i', $msg ) ) {
				return new WP_REST_Response( null, 204 ); // cross-origin noise carries nothing useful
			}
			$src = mb_substr( (string) ( $d['s'] ?? '' ), 0, 200 );
			db_log( 'js', 'error', mb_substr( $msg, 0, 300 ), array( 'page' => mb_substr( (string) ( $d['p'] ?? '' ), 0, 200 ), 'ua' => mb_substr( (string) $r->get_header( 'user_agent' ), 0, 160 ) ), $src . ':' . (int) ( $d['l'] ?? 0 ) );
			return new WP_REST_Response( null, 204 );
		},
	) );
} );

add_action( 'wp_footer', function () {
	if ( is_admin() || '1' !== (string) get_option( 'db_log_js_on', '1' ) ) {
		return;
	}
	$u = esc_url_raw( rest_url( 'db/v1/log-js' ) );
	echo '<script data-no-delay>(function(){var n=0,u=' . wp_json_encode( $u ) . ';function s(m,f,l){if(n++>4||!m)return;try{var b=JSON.stringify({m:String(m).slice(0,300),s:String(f||"").slice(0,200),l:l||0,p:location.pathname});if(navigator.sendBeacon){navigator.sendBeacon(u,new Blob([b],{type:"text/plain"}));}else{fetch(u,{method:"POST",body:b,keepalive:true});}}catch(e){}}'
		. 'window.addEventListener("error",function(e){s(e.message,e.filename,e.lineno);});window.addEventListener("unhandledrejection",function(e){var r=e.reason;s(r&&(r.message||r),"promise",0);});})();</script>' . "\n";
}, 99 );

/* ------------------------------------------------------- retention and archive */

if ( ! function_exists( 'db_log_archive_dir' ) ) {
	/** uploads/db-logs, with an index.php and deny rules so it is never listed or served. */
	function db_log_archive_dir() {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return '';
		}
		$dir = $up['basedir'] . '/db-logs';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return '';
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}
		return $dir;
	}
}

if ( ! function_exists( 'db_log_archive_rows' ) ) {
	/**
	 * Writes rows created between $from and $to (UTC, $to exclusive) to a gzip file, then
	 * deletes them. Works in batches so a large month never exhausts memory or time.
	 *
	 * @return int Rows archived, or -1 if the file could not be written (nothing is deleted).
	 */
	function db_log_archive_rows( $from, $to, $label ) {
		global $wpdb;
		$dir = db_log_archive_dir();
		if ( '' === $dir || ! function_exists( 'gzopen' ) ) {
			return -1;
		}
		$t     = db_log_table();
		$token = get_option( 'db_log_archive_token' );
		if ( ! is_string( $token ) || strlen( $token ) < 16 ) {
			$token = wp_generate_password( 20, false );
			update_option( 'db_log_archive_token', $token, false );
		}
		$file = $dir . '/log-' . preg_replace( '/[^0-9a-z\-]/', '', $label ) . '-' . $token . '.jsonl.gz';
		$gz   = gzopen( $file, 'ab6' ); // append, so a month archived in two runs stays one file
		if ( ! $gz ) {
			return -1;
		}
		$done = 0;
		$last = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE created_at >= %s AND created_at < %s AND id > %d ORDER BY id ASC LIMIT 2000", $from, $to, $last ), ARRAY_A );
			$ok   = true;
			foreach ( $rows as $r ) {
				$ok   = $ok && false !== gzwrite( $gz, wp_json_encode( $r ) . "\n" );
				$last = (int) $r['id'];
			}
			if ( ! $ok ) {
				gzclose( $gz );
				return -1;
			}
			if ( $rows ) {
				$ids = implode( ',', array_map( 'intval', wp_list_pluck( $rows, 'id' ) ) );
				$wpdb->query( "DELETE FROM {$t} WHERE id IN ({$ids})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$done += count( $rows );
			}
		} while ( count( $rows ) === 2000 && $done < 200000 );
		gzclose( $gz );
		return $done;
	}
}

if ( ! function_exists( 'db_log_maintain' ) ) {
	/** Daily: archive rows past the retention period, enforce the row cap, cap the archive size. */
	function db_log_maintain() {
		global $wpdb;
		$t = db_log_table();
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		// 1. Months entirely past the retention period.
		$oldest = (string) $wpdb->get_var( "SELECT MIN(created_at) FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( '' !== $oldest ) {
			foreach ( db_log_archive_months( $oldest, time() ) as $ym ) {
				$from = $ym . '-01 00:00:00';
				$to   = gmdate( 'Y-m-01 00:00:00', strtotime( '+1 month', strtotime( $from . ' UTC' ) ) );
				db_log_archive_rows( $from, $to, $ym );
			}
		}
		// 2. Row cap: archive the oldest rows beyond DB_LOG_MAX_ROWS.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count > DB_LOG_MAX_ROWS ) {
			$over  = $count - (int) ( DB_LOG_MAX_ROWS * 0.9 );
			$until = (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM {$t} ORDER BY created_at ASC LIMIT 1 OFFSET %d", $over ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( '' !== $until ) {
				db_log_archive_rows( '1970-01-01 00:00:00', $until, 'cap-' . gmdate( 'Y-m-d' ) );
			}
		}
		// 3. Archive size cap: remove the oldest archive files beyond the limit.
		$dir = db_log_archive_dir();
		if ( '' !== $dir ) {
			$files = (array) glob( $dir . '/log-*.jsonl.gz' );
			usort( $files, static function ( $a, $b ) { return filemtime( $a ) <=> filemtime( $b ); } );
			$total = array_sum( array_map( 'filesize', $files ) );
			while ( $files && $total > DB_LOG_ARCHIVE_MAX_MB * 1048576 ) {
				$f      = array_shift( $files );
				$total -= (int) filesize( $f );
				@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		// 4. Optional daily summary email of yesterday's errors.
		if ( '1' === (string) get_option( 'db_log_digest_on', '1' ) ) {
			db_log_send_digest();
		}
	}
}

if ( ! function_exists( 'db_log_send_digest' ) ) {
	function db_log_send_digest() {
		global $wpdb;
		$t    = db_log_table();
		$day  = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT source, level, message, place, hits FROM {$t} WHERE day = %s AND level = 'error' ORDER BY hits DESC LIMIT 15", $day ), ARRAY_A );
		if ( ! $rows || get_option( 'db_log_digest_sent' ) === $day ) {
			return;
		}
		$li = '';
		foreach ( $rows as $r ) {
			$li .= '<li><strong>[' . esc_html( $r['source'] ) . ']</strong> ' . esc_html( $r['message'] ) . ' <em>(' . (int) $r['hits'] . '&times;' . ( '' !== $r['place'] ? ', ' . esc_html( $r['place'] ) : '' ) . ')</em></li>';
		}
		$to = (string) get_option( 'db_log_digest_to', get_option( 'admin_email' ) );
		// Marked sent only when the mail goes out, so a failed send is retried next run (AD-13).
		$sent = wp_mail( $to, 'Domain Brothers: errors on ' . $day, '<p>Errors recorded yesterday (most frequent first):</p><ul>' . $li . '</ul><p><a href="' . esc_url( admin_url( 'tools.php?page=db-log&day=' . $day ) ) . '">Open the error log</a></p>', array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( $sent ) {
			update_option( 'db_log_digest_sent', $day, false );
		}
	}
}

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'db_log_maintain' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'db_log_maintain' );
	}
} );
add_action( 'db_log_maintain', 'db_log_maintain' );

/* -------------------------------------------------------------------- admin */

add_action( 'admin_menu', function () {
	global $wpdb;
	$n = 0;
	if ( current_user_can( 'manage_options' ) && DB_LOG_DB_VERSION === get_option( 'db_log_db_ver' ) ) {
		$t = db_log_table();
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE level = 'error' AND last_seen >= %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	add_management_page( 'Error log', 'Error log' . ( $n ? ' <span class="awaiting-mod">' . (int) $n . '</span>' : '' ), 'manage_options', 'db-log', 'db_log_admin_page' );
} );

if ( ! function_exists( 'db_log_filters' ) ) {
	/** The admin filters from the query string, sanitised. */
	function db_log_filters() {
		// phpcs:disable WordPress.Security.NonceVerification
		$g = static function ( $k ) { return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; };
		$f = array(
			'level'  => in_array( $g( 'level' ), array( 'error', 'warning', 'notice', 'info' ), true ) ? $g( 'level' ) : '',
			'source' => preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $g( 'source' ) ) ),
			'q'      => mb_substr( $g( 'q' ), 0, 100 ),
			'from'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $g( 'from' ) ) ? $g( 'from' ) : '',
			'to'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $g( 'to' ) ) ? $g( 'to' ) : '',
			'day'    => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $g( 'day' ) ) ? $g( 'day' ) : '',
			'paged'  => max( 1, (int) $g( 'paged' ) ),
		);
		// phpcs:enable WordPress.Security.NonceVerification
		if ( '' !== $f['day'] ) {
			$f['from'] = $f['day'];
			$f['to']   = $f['day'];
		}
		return $f;
	}
}

if ( ! function_exists( 'db_log_where' ) ) {
	/** SQL WHERE clause (prepared) for the filters. */
	function db_log_where( array $f ) {
		global $wpdb;
		$w = array( '1=1' );
		if ( '' !== $f['level'] ) {
			$w[] = $wpdb->prepare( 'level = %s', $f['level'] );
		}
		if ( '' !== $f['source'] ) {
			$w[] = $wpdb->prepare( 'source = %s', $f['source'] );
		}
		if ( '' !== $f['from'] ) {
			$w[] = $wpdb->prepare( 'last_seen >= %s', $f['from'] . ' 00:00:00' );
		}
		if ( '' !== $f['to'] ) {
			$w[] = $wpdb->prepare( 'created_at <= %s', $f['to'] . ' 23:59:59' );
		}
		if ( '' !== $f['q'] ) {
			$like = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$w[]  = $wpdb->prepare( '(message LIKE %s OR place LIKE %s OR url LIKE %s)', $like, $like, $like );
		}
		return implode( ' AND ', $w );
	}
}

if ( ! function_exists( 'db_log_admin_page' ) ) {
	function db_log_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$t     = db_log_table();
		$f     = db_log_filters();
		$where = db_log_where( $f );
		$per   = 50;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY last_seen DESC LIMIT %d OFFSET %d", $per, ( $f['paged'] - 1 ) * $per ), ARRAY_A );
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$stats = (array) $wpdb->get_results( $wpdb->prepare( "SELECT level, COUNT(*) AS n, SUM(hits) AS h FROM {$t} WHERE last_seen >= %s GROUP BY level", $since ), OBJECT_K );
		$srcs  = (array) $wpdb->get_col( "SELECT DISTINCT source FROM {$t} ORDER BY source" );
		$all   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
		// phpcs:enable
		$base = admin_url( 'tools.php?page=db-log' );
		echo '<div class="wrap"><h1>Error log</h1>';
		if ( isset( $_GET['msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-info"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['msg'] ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<p>Last 24 hours: ';
		foreach ( array( 'error' => '#b42318', 'warning' => '#b54708', 'notice' => '#475467', 'info' => '#475467' ) as $lv => $c ) {
			$s = $stats[ $lv ] ?? null;
			echo '<a href="' . esc_url( add_query_arg( array( 'level' => $lv, 'from' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ) ), $base ) ) . '" style="margin-right:14px;color:' . esc_attr( $c ) . '"><strong>' . (int) ( $s ? $s->n : 0 ) . '</strong> ' . esc_html( $lv ) . ( $s && (int) $s->h > (int) $s->n ? ' (' . (int) $s->h . ' times)' : '' ) . '</a>';
		}
		echo '</p><p class="description">' . number_format( $all ) . ' entries kept (12 months; older months are archived below). Times are UTC. Emails, keys and card numbers are masked before saving.</p>';

		// Filters.
		echo '<form method="get" style="margin:12px 0;display:flex;flex-wrap:wrap;gap:8px;align-items:center"><input type="hidden" name="page" value="db-log">';
		echo '<select name="level"><option value="">All levels</option>';
		foreach ( array( 'error', 'warning', 'notice', 'info' ) as $lv ) {
			echo '<option value="' . esc_attr( $lv ) . '"' . selected( $f['level'], $lv, false ) . '>' . esc_html( ucfirst( $lv ) ) . '</option>';
		}
		echo '</select><select name="source"><option value="">All sources</option>';
		foreach ( $srcs as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $f['source'], $s, false ) . '>' . esc_html( $s ) . '</option>';
		}
		echo '</select><label>From <input type="date" name="from" value="' . esc_attr( $f['from'] ) . '"></label><label>To <input type="date" name="to" value="' . esc_attr( $f['to'] ) . '"></label>';
		echo '<input type="search" name="q" value="' . esc_attr( $f['q'] ) . '" placeholder="Search message, place or URL"><button class="button">Filter</button>';
		echo ' <a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'db_log_csv' ), array_filter( array_diff_key( $f, array( 'paged' => 1 ) ) ) ), admin_url( 'admin-post.php' ) ), 'db_log_csv' ) ) . '">Export CSV</a></form>';

		// Entries.
		echo '<table class="widefat striped"><thead><tr><th style="width:150px">Last seen (UTC)</th><th style="width:70px">Level</th><th style="width:80px">Source</th><th>Message</th><th style="width:60px">Times</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">Nothing recorded for these filters.</td></tr>';
		}
		$color = array( 'error' => '#b42318', 'warning' => '#b54708', 'notice' => '#475467', 'info' => '#475467' );
		foreach ( $rows as $r ) {
			$ctx = '' !== (string) $r['context'] ? json_decode( (string) $r['context'], true ) : null;
			echo '<tr><td>' . esc_html( $r['last_seen'] ) . ( $r['created_at'] !== $r['last_seen'] ? '<br><small>first ' . esc_html( $r['created_at'] ) . '</small>' : '' ) . '</td>'
				. '<td style="color:' . esc_attr( $color[ $r['level'] ] ?? '#475467' ) . ';font-weight:600">' . esc_html( $r['level'] ) . '</td>'
				. '<td><a href="' . esc_url( add_query_arg( 'source', $r['source'], $base ) ) . '">' . esc_html( $r['source'] ) . '</a></td>'
				. '<td><details><summary style="cursor:pointer">' . esc_html( $r['message'] ) . '</summary><p style="margin:6px 0 0">'
				. ( '' !== $r['place'] ? '<strong>Where:</strong> <code>' . esc_html( $r['place'] ) . '</code><br>' : '' )
				. ( '' !== $r['url'] ? '<strong>Request:</strong> <code>' . esc_html( $r['url'] ) . '</code><br>' : '' )
				. ( $ctx ? '<strong>Details:</strong> <code style="white-space:pre-wrap">' . esc_html( (string) wp_json_encode( $ctx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</code>' : '' )
				. '</p></details></td><td>' . (int) $r['hits'] . '</td></tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p>' . esc_html( number_format( $total ) ) . ' entries. Page ';
			for ( $i = 1; $i <= min( $pages, 40 ); $i++ ) {
				echo $i === $f['paged'] ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( add_query_arg( array_merge( array_filter( $f ), array( 'paged' => $i ) ), $base ) ) . '">' . (int) $i . '</a> ';
			}
			echo '</p>';
		}

		// Archives.
		$dir   = db_log_archive_dir();
		$files = '' !== $dir ? (array) glob( $dir . '/log-*.jsonl.gz' ) : array();
		rsort( $files );
		echo '<h2>Archives</h2><p class="description">Entries older than 12 months are moved here each night, one file per month (gzip, one JSON line per entry). Up to ' . (int) DB_LOG_ARCHIVE_MAX_MB . ' MB are kept; the oldest files are removed beyond that.</p>';
		if ( ! $files ) {
			echo '<p>No archives yet.</p>';
		} else {
			echo '<ul>';
			foreach ( $files as $file ) {
				$name = basename( $file );
				$nice = preg_replace( '/-[A-Za-z0-9]{16,}\.jsonl\.gz$/', '.jsonl.gz', $name );
				echo '<li><a href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'db_log_archive', 'f' => $name ), admin_url( 'admin-post.php' ) ), 'db_log_archive' ) ) . '">' . esc_html( $nice ) . '</a> (' . esc_html( size_format( (int) filesize( $file ) ) ) . ')</li>';
			}
			echo '</ul>';
		}

		// Settings.
		echo '<h2>Settings</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'db_log_settings' );
		echo '<input type="hidden" name="action" value="db_log_settings"><p><label><input type="checkbox" name="digest" value="1"' . checked( '1', (string) get_option( 'db_log_digest_on', '1' ), false ) . '> Email a daily summary of errors to</label> <input type="email" name="to" value="' . esc_attr( (string) get_option( 'db_log_digest_to', get_option( 'admin_email' ) ) ) . '" class="regular-text"></p>';
		echo '<p><label><input type="checkbox" name="js" value="1"' . checked( '1', (string) get_option( 'db_log_js_on', '1' ), false ) . '> Record JavaScript errors from visitors\' browsers (at most 20 per visitor per hour)</label></p>';
		echo '<p><button class="button button-primary">Save</button> <button class="button" name="run" value="1">Run archiving now</button></p></form></div>';
	}
}

add_action( 'admin_post_db_log_settings', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_log_settings' );
	update_option( 'db_log_digest_on', isset( $_POST['digest'] ) ? '1' : '0', false );
	update_option( 'db_log_js_on', isset( $_POST['js'] ) ? '1' : '0', false );
	$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
	if ( is_email( $to ) ) {
		update_option( 'db_log_digest_to', $to, false );
	}
	$msg = 'Settings saved.';
	if ( ! empty( $_POST['run'] ) ) {
		db_log_maintain();
		$msg = 'Archiving ran.';
	}
	wp_safe_redirect( add_query_arg( array( 'page' => 'db-log', 'msg' => rawurlencode( $msg ) ), admin_url( 'tools.php' ) ) );
	exit;
} );

add_action( 'admin_post_db_log_csv', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_log_csv' );
	global $wpdb;
	$t     = db_log_table();
	$where = db_log_where( db_log_filters() );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="error-log-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	fputcsv( $out, array( 'first_seen_utc', 'last_seen_utc', 'level', 'source', 'message', 'place', 'request', 'times', 'details' ) );
	$last = PHP_INT_MAX;
	do {
		// Batches by id so a large export never loads everything into memory.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} AND id < %d ORDER BY id DESC LIMIT 2000", $last ), ARRAY_A );
		foreach ( $rows as $r ) {
			// Cells starting with = + - @ are prefixed so spreadsheets never run them as formulas.
			$cells = array_map( static function ( $v ) { $v = (string) $v; return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v; }, array( $r['created_at'], $r['last_seen'], $r['level'], $r['source'], $r['message'], $r['place'], $r['url'], $r['hits'], $r['context'] ) );
			fputcsv( $out, $cells );
			$last = (int) $r['id'];
		}
	} while ( count( $rows ) === 2000 );
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
} );

add_action( 'admin_post_db_log_archive', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_log_archive' );
	$name = isset( $_GET['f'] ) ? basename( sanitize_file_name( wp_unslash( $_GET['f'] ) ) ) : '';
	$file = db_log_archive_dir() . '/' . $name;
	if ( ! preg_match( '/^log-[0-9a-z\-]+-[A-Za-z0-9]{16,}\.jsonl\.gz$/', $name ) || ! is_file( $file ) ) {
		wp_die( 'Archive not found.', 404 );
	}
	nocache_headers();
	header( 'Content-Type: application/gzip' );
	header( 'Content-Disposition: attachment; filename="' . preg_replace( '/-[A-Za-z0-9]{16,}\.jsonl\.gz$/', '.jsonl.gz', $name ) . '"' );
	header( 'Content-Length: ' . (int) filesize( $file ) );
	readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
} );
