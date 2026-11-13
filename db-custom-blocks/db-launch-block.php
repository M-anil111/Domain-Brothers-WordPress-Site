<?php
/**
 * Launch checks: one screen that answers "is this site ready to go live?"
 *
 * Every item is one of:
 *   ok     checked by the site and passing
 *   warn   checked and needs attention
 *   owner  something only the owner or the host can do (rotated keys, backups, DNS, legal review)
 *
 * Checks that read another page (robots.txt, the home page headers) are made from the server.
 * A staging site behind HTTP Basic Auth cannot be read that way, so those show "not checked" with the reason.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_release_info' ) ) {
	/** Which build is running: version from the plugin header, commit and build time from release.json (written by tools/build-release.sh). */
	function db_release_info() {
		$f = __DIR__ . '/release.json';
		$r = is_readable( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array(
			'version' => defined( 'DB_BLOCKS_LOADED' ) ? DB_BLOCKS_LOADED : 'unknown',
			'commit'   => is_array( $r ) ? (string) ( $r['commit'] ?? '' ) : '',
			'tree'     => is_array( $r ) ? (string) ( $r['tree'] ?? '' ) : '',
			'built'    => is_array( $r ) ? (string) ( $r['built'] ?? '' ) : '',
		);
	}
}

if ( ! function_exists( 'db_launch_fetch' ) ) {
	/** A page of this site read from the server. Cached for ten minutes. Null when it cannot be read. */
	function db_launch_fetch( $url, $head = false ) {
		$key = 'db_launch_' . md5( $url . ( $head ? 'h' : 'g' ) );
		$hit = get_transient( $key );
		if ( false !== $hit ) {
			return $hit;
		}
		$args = array( 'timeout' => 15, 'redirection' => 3, 'user-agent' => 'DomainBrothers-LaunchCheck/1.0' );
		$res  = $head ? wp_remote_head( $url, $args ) : wp_remote_get( $url, $args );
		$out  = null;
		if ( ! is_wp_error( $res ) ) {
			$out = array( 'code' => (int) wp_remote_retrieve_response_code( $res ), 'body' => (string) wp_remote_retrieve_body( $res ), 'headers' => wp_remote_retrieve_headers( $res ) );
		}
		set_transient( $key, $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}
}

if ( ! function_exists( 'db_launch_robots_blocks_all' ) ) {
	/** True when the robots.txt body disallows everything for "User-agent: *". */
	function db_launch_robots_blocks_all( $body ) {
		$group = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $body ) as $line ) {
			$line = trim( preg_replace( '/#.*$/', '', $line ) );
			if ( '' === $line || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $k, $v ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$k = strtolower( $k );
			if ( 'user-agent' === $k ) {
				$group = ( '*' === $v );
			} elseif ( $group && 'disallow' === $k && '/' === $v ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'db_smtp_settings_saved' ) ) {
	/** True when an SMTP password has been saved (whether or not it can be read). */
	function db_smtp_settings_saved() {
		$o = get_option( 'db_smtp', array() );
		return is_array( $o ) && '' !== (string) ( $o['pass'] ?? '' );
	}
}

if ( ! function_exists( 'db_launch_checks' ) ) {
	/** @return array<int,array{id:string,label:string,state:string,detail:string}> */
	function db_launch_checks() {
		$rows    = array();
		$add     = static function ( $id, $label, $state, $detail ) use ( &$rows ) {
			$rows[] = array( 'id' => $id, 'label' => $label, 'state' => $state, 'detail' => $detail );
		};
		$staging = function_exists( 'db_is_staging_host' ) && db_is_staging_host();
		$rel     = db_release_info();

		// Release identity
		$add( 'release', 'Build identity', '' !== $rel['commit'] ? 'ok' : 'warn',
			'Version ' . $rel['version'] . ( '' !== $rel['commit'] ? ', commit ' . $rel['commit'] . ( '' !== $rel['tree'] ? ' (' . $rel['tree'] . ' tree)' : '' ) . ', built ' . $rel['built'] : '. No release.json: run tools/build-release.sh before packaging, so the deployed commit is recorded.' ) );

		// PHP
		$add( 'php', 'PHP version', version_compare( PHP_VERSION, '8.2.0', '>=' ) ? 'ok' : 'warn', 'PHP ' . PHP_VERSION . '. Production must run a patched 8.2 or newer release.' );

		// Robots and indexing
		$robots = db_launch_fetch( home_url( '/robots.txt' ) );
		if ( null === $robots ) {
			$add( 'robots', 'robots.txt', 'warn', 'Not checked: the server could not read its own robots.txt (staging behind Basic Auth cannot be read from the server).' );
		} elseif ( 401 === $robots['code'] ) {
			$add( 'robots', 'robots.txt', 'warn', 'Not checked from the server (Basic Auth). Check it in a browser.' );
		} else {
			$blocks = db_launch_robots_blocks_all( $robots['body'] );
			if ( $staging ) {
				$add( 'robots', 'robots.txt blocks crawlers on staging', $blocks ? 'ok' : 'warn', $blocks ? 'Disallow: / for all crawlers.' : 'Crawlers can read this staging site. The plugin serves Disallow: / here, but the file served to the internet (Cloudflare managed robots or a physical robots.txt) overrides it. Replace it there with Disallow: / for User-agent: *.' );
			} else {
				$add( 'robots', 'robots.txt lets search engines in (production)', $blocks ? 'warn' : 'ok', $blocks ? 'Production robots.txt blocks everything. Search engines will not index the site.' : 'Search engines are allowed.' );
			}
		}
		if ( $staging ) {
			$home = db_launch_fetch( home_url( '/' ), true );
			if ( null === $home || 401 === ( $home['code'] ?? 0 ) ) {
				$add( 'noindex', 'Staging pages are noindex', 'warn', 'Not checked from the server (Basic Auth). Check the X-Robots-Tag header in a browser.' );
			} else {
				$xr = isset( $home['headers']['x-robots-tag'] ) ? (string) $home['headers']['x-robots-tag'] : '';
				$add( 'noindex', 'Staging pages are noindex', false !== stripos( $xr, 'noindex' ) ? 'ok' : 'warn', '' !== $xr ? 'X-Robots-Tag: ' . $xr : 'No X-Robots-Tag header on the home page.' );
			}
		}

		// Cron: our jobs must run on time
		$cron_hooks = array( 'db_acct_digest' => 'alert emails', 'db_wl_purge' => 'sign-in clean-up', 'db_hits_purge' => 'hit counts clean-up' );
		$late       = array();
		foreach ( $cron_hooks as $hook => $label ) {
			$next = wp_next_scheduled( $hook );
			if ( ! $next ) {
				$late[] = $label . ' (not scheduled)';
			} elseif ( $next < time() - 45 * MINUTE_IN_SECONDS ) {
				$late[] = $label . ' (due ' . human_time_diff( $next ) . ' ago)';
			}
		}
		$add( 'cron', 'WordPress cron runs on time', $late ? 'warn' : 'ok', $late ? 'Late: ' . implode( ', ', $late ) . '. Add a real cron job that calls wp-cron.php every 5 minutes, and set DISABLE_WP_CRON in wp-config.php.' : 'Our scheduled jobs are on time.' );

		// Email delivery
		$smtp = function_exists( 'db_smtp_config' ) ? db_smtp_config() : null;
		if ( $smtp ) {
			$add( 'smtp', 'Email delivery', 'ok', 'SMTP via ' . $smtp['host'] . ':' . $smtp['port'] . ' as ' . $smtp['user'] . '. Confirm SPF and DKIM are verified with the provider.' );
		} elseif ( function_exists( 'db_sg_api_key' ) && db_sg_api_key() ) {
			$add( 'smtp', 'Email delivery', 'warn', 'Using the SendGrid fallback. Save the SMTP settings to use SMTP2GO.' );
		} else {
			$saved = db_smtp_settings_saved();
			$add( 'smtp', 'Email delivery', 'warn', $saved ? 'The saved SMTP password cannot be read. The site salts have probably changed since it was saved: enter the password again under Settings > DB Email delivery.' : 'No email delivery configured.' );
		}
		$since = (int) get_option( 'db_mail_ok_ts', 0 );
		$fails = array_filter( (array) get_option( 'db_mail_failures', array() ), static function ( $f ) use ( $since ) {
			return ( $f['ts'] ?? 0 ) > max( $since, time() - DAY_IN_SECONDS );
		} );
		$add( 'mailfail', 'No email failures since the last success', $fails ? 'warn' : 'ok', $fails ? count( $fails ) . ' failed since the last successful send. Send a test from Settings > DB Email delivery.' : 'Last successful send: ' . ( $since ? wp_date( 'M j, Y g:i a', $since ) : 'none recorded yet' ) . '.' );

		// Payments
		$mode = function_exists( 'db_stripe_mode' ) ? db_stripe_mode() : 'unknown';
		if ( $staging ) {
			$add( 'stripe', 'Payments in test mode on staging', 'test' === $mode ? 'ok' : 'warn', 'Stripe mode: ' . $mode . '.' . ( 'test' === $mode ? '' : ' Switch to test mode before any staging payment test.' ) );
		} else {
			$add( 'stripe', 'Payments in live mode on production', 'live' === $mode ? 'ok' : 'warn', 'Stripe mode: ' . $mode . '.' );
		}

		// Bot protection
		$ts_on     = function_exists( 'db_ts_enabled' ) && db_ts_enabled();
		$ts_broken = ! $ts_on && function_exists( 'db_ts_settings' ) && '' !== (string) ( db_ts_settings()['secret'] ?? '' );
		if ( $ts_on ) {
			$add( 'turnstile', 'Bot protection on forms', 'ok', 'Turnstile is on for contact forms, sign-in codes and offers.' );
		} elseif ( $ts_broken ) {
			$add( 'turnstile', 'Bot protection on forms', 'warn', 'The saved Turnstile secret cannot be read (the site salts have changed). Enter both keys again below.' );
		} else {
			$add( 'turnstile', 'Bot protection on forms', 'owner', 'Add Cloudflare Turnstile keys below before launch.' );
		}

		// Owner-only items
		foreach ( array(
			'creds'   => 'Credentials shared in chat or email are rotated (SMTP2GO password and API key, mailbox password, WordPress admin passwords).',
			'backup'  => 'A backup of the production database and wp-content exists, and rollback is tested.',
			'webhook' => 'The Stripe webhook is reachable without Basic Auth on the live site, and its signing secret is saved.',
			'spf'     => 'SPF, DKIM and DMARC records are published for the sending domain.',
			'legal'   => 'Legal review of the payment plan terms (forfeiture and transfer on final payment).',
			'cdn'     => 'Cloudflare development mode is off and the cache was tested for logged-out pages.',
			'escrow'  => 'The site makes no escrow claim. Any new copy that mentions escrow must describe an arrangement you can show; the plugin does not integrate an escrow service.',
			'ads'     => 'AdSense ad unit ids are saved, and a consent banner is live for EEA, UK and Swiss visitors.',
		) as $id => $text ) {
			$add( $id, $text, 'owner', 'Owner or host action.' );
		}
		return $rows;
	}
}

if ( ! function_exists( 'db_launch_admin_page' ) ) {
	function db_launch_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$rows = db_launch_checks();
		$icon = array( 'ok' => '&#10003;', 'warn' => '&#9888;', 'owner' => '&#9744;', 'test' => '&#10003;', 'live' => '&#10003;' );
		$tone = array( 'ok' => '#1a7f37', 'warn' => '#b32d2e', 'owner' => '#6b5b00', 'test' => '#1a7f37', 'live' => '#1a7f37' );
		$count = array_count_values( array_column( $rows, 'state' ) );
		echo '<div class="wrap"><h1>Launch checks</h1>';
		echo '<p>' . (int) ( $count['ok'] ?? 0 ) . ' passing, ' . (int) ( $count['warn'] ?? 0 ) . ' to fix, ' . (int) ( $count['owner'] ?? 0 ) . ' owner actions. Go live only when nothing is marked "to fix" and every owner action is done.</p>';
		echo '<table class="widefat striped"><thead><tr><th style="width:44px"></th><th>Check</th><th>Detail</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$st = $r['state'];
			$sym = $icon[ $st ] ?? '&#9888;';
			$col = $tone[ $st ] ?? '#b32d2e';
			$lab = 'owner' === $st ? 'Owner' : ( 'warn' === $st ? 'Fix' : 'OK' );
			echo '<tr><td style="color:' . esc_attr( $col ) . ';font-size:18px;font-weight:700" title="' . esc_attr( $lab ) . '">' . $sym . '</td><td><strong>' . esc_html( $r['label'] ) . '</strong></td><td>' . esc_html( $r['detail'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<p style="margin-top:14px"><a class="button" href="' . esc_url( add_query_arg( 'nocache', time(), admin_url( 'admin.php?page=db-launch' ) ) ) . '">Run the checks again</a></p>';
		if ( function_exists( 'db_ts_admin_html' ) ) {
			echo db_ts_admin_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built inside, escaped.
		}
		echo '</div>';
	}
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Launch checks', 'Launch checks', 'manage_options', 'db-launch', 'db_launch_admin_page' );
}, 29 );

// Passing the mail check clears the alarm: a successful send is recorded (see db-offers-block.php).
add_action( 'admin_notices', function () {
	$scr = get_current_screen();
	if ( $scr && false !== strpos( (string) $scr->id, 'db-launch' ) && isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
	}
} );
