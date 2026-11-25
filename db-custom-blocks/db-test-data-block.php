<?php
/**
 * Tools > Test data: find and delete records left by QA and manual testing.
 *
 * Listed (nothing is deleted until an administrator ticks rows and confirms):
 *   - Transfer (EPP) records #1 and any with a test email address.
 *   - Form entries #1 and any with a test email address.
 *   - CRM leads with a test email address, or with "test only" / "please
 *     ignore" in the name or message.
 *   - Accounts (db_accounts) with a test email address. Deleting one also
 *     deletes its wishlist items, notes, alert settings, sign-in codes and
 *     sessions.
 *
 * A test email address is one on a domain reserved for testing (example.com,
 * example.org, example.net, *.example, *.test, *.invalid, *.localhost; RFC
 * 2606 / 6761), so a real customer can never match.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_test_email' ) ) {
	/** True for an address on a domain reserved for testing. Pure: tested offline. */
	function db_test_email( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}
		$host = rtrim( substr( $email, $at + 1 ), '.' );
		if ( in_array( $host, array( 'example.com', 'example.org', 'example.net' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/(^|\.)(example|test|invalid|localhost)$/', $host );
	}
}

if ( ! function_exists( 'db_test_text' ) ) {
	/** True for a name or message that says it is a test. Pure: tested offline. */
	function db_test_text( $text ) {
		return (bool) preg_match( '/\b(test only|please ignore|qa test)\b/i', (string) $text );
	}
}

if ( ! function_exists( 'db_test_data_find' ) ) {
	/**
	 * Candidate rows per group.
	 *
	 * @return array<string, array{label:string, rows:array<int, array{key:string, cells:array<int,string>, why:string}>}>
	 */
	function db_test_data_find() {
		global $wpdb;
		$exists = function ( $t ) use ( $wpdb ) {
			return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) );
		};
		$like   = function ( $col ) use ( $wpdb ) {
			// Narrow in SQL, then confirm each row with db_test_email().
			return "( {$col} LIKE '%example.com' OR {$col} LIKE '%example.org' OR {$col} LIKE '%example.net' OR {$col} LIKE '%.example' OR {$col} LIKE '%.test' OR {$col} LIKE '%.invalid' OR {$col} LIKE '%.localhost' OR {$col} LIKE '%@localhost' )";
		};
		$out = array();

		$t = $wpdb->prefix . 'db_transfer_in';
		if ( $exists( $t ) ) {
			$rows = array();
			foreach ( (array) $wpdb->get_results( "SELECT id, domain, order_no, email, created_at FROM {$t} WHERE id = 1 OR " . $like( 'email' ) . ' ORDER BY id LIMIT 500', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$test = db_test_email( $r['email'] );
				if ( 1 === (int) $r['id'] || $test ) {
					$rows[] = array( 'key' => (string) (int) $r['id'], 'cells' => array( '#' . $r['id'], $r['domain'], $r['order_no'], $r['email'], $r['created_at'] ), 'why' => $test ? 'test email' : 'first record' );
				}
			}
			$out['transfer'] = array( 'label' => 'Transfer (EPP) records', 'head' => array( 'ID', 'Domain', 'Order', 'Email', 'Created' ), 'rows' => $rows );
		}

		$t = $wpdb->prefix . 'db_form_entries';
		if ( $exists( $t ) ) {
			$rows = array();
			foreach ( (array) $wpdb->get_results( "SELECT id, form_title, page_url, email, created_at FROM {$t} WHERE id = 1 OR " . $like( 'email' ) . ' ORDER BY id LIMIT 500', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$test = db_test_email( $r['email'] );
				if ( 1 === (int) $r['id'] || $test ) {
					$rows[] = array( 'key' => (string) (int) $r['id'], 'cells' => array( '#' . $r['id'], $r['form_title'], $r['page_url'], $r['email'], $r['created_at'] ), 'why' => $test ? 'test email' : 'first record' );
				}
			}
			$out['forms'] = array( 'label' => 'Form entries', 'head' => array( 'ID', 'Form', 'Page', 'Email', 'Created' ), 'rows' => $rows );
		}

		$t = $wpdb->prefix . 'db_leads';
		if ( $exists( $t ) ) {
			$rows = array();
			$sql  = "SELECT id, domain, name, email, message, created_at FROM {$t} WHERE " . $like( 'email' ) . " OR name LIKE '%test only%' OR message LIKE '%test only%' OR message LIKE '%please ignore%' OR name LIKE '%please ignore%' OR message LIKE '%qa test%' ORDER BY id LIMIT 500";
			foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$test = db_test_email( $r['email'] );
				if ( $test || db_test_text( $r['name'] ) || db_test_text( $r['message'] ) ) {
					$rows[] = array( 'key' => (string) (int) $r['id'], 'cells' => array( '#' . $r['id'], $r['domain'], $r['name'], $r['email'], $r['created_at'] ), 'why' => $test ? 'test email' : 'marked as a test' );
				}
			}
			$out['leads'] = array( 'label' => 'CRM leads and enquiries', 'head' => array( 'ID', 'Domain', 'Name', 'Email', 'Created' ), 'rows' => $rows );
		}

		$t = $wpdb->prefix . 'db_accounts';
		if ( $exists( $t ) ) {
			$rows = array();
			foreach ( (array) $wpdb->get_results( "SELECT email, full_name, created_at FROM {$t} WHERE " . $like( 'email' ) . ' ORDER BY created_at LIMIT 500', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				if ( db_test_email( $r['email'] ) ) {
					$rows[] = array( 'key' => $r['email'], 'cells' => array( $r['email'], $r['full_name'], (string) ( $r['created_at'] ?? '' ) ), 'why' => 'test email' );
				}
			}
			$out['accounts'] = array( 'label' => 'Accounts (with their wishlist, notes, alerts and sessions)', 'head' => array( 'Email', 'Name', 'Created' ), 'rows' => $rows );
		}
		return $out;
	}
}

if ( ! function_exists( 'db_test_data_delete' ) ) {
	/**
	 * Deletes the ticked rows. Each key is re-checked against a fresh scan, so
	 * only rows this screen lists can be deleted.
	 *
	 * @param array<string, array<int,string>> $picked group => keys.
	 * @return array<string,int> group => rows deleted.
	 */
	function db_test_data_delete( array $picked ) {
		global $wpdb;
		$found = db_test_data_find();
		$done  = array();
		foreach ( $picked as $group => $keys ) {
			if ( empty( $found[ $group ] ) ) {
				continue;
			}
			$allowed = wp_list_pluck( $found[ $group ]['rows'], 'key' );
			$n       = 0;
			foreach ( array_unique( array_map( 'strval', (array) $keys ) ) as $k ) {
				if ( ! in_array( $k, $allowed, true ) ) {
					continue;
				}
				switch ( $group ) {
					case 'transfer':
						$n += (int) $wpdb->delete( $wpdb->prefix . 'db_transfer_in', array( 'id' => (int) $k ), array( '%d' ) );
						break;
					case 'forms':
						$n += (int) $wpdb->delete( $wpdb->prefix . 'db_form_entries', array( 'id' => (int) $k ), array( '%d' ) );
						break;
					case 'leads':
						$n += (int) $wpdb->delete( $wpdb->prefix . 'db_leads', array( 'id' => (int) $k ), array( '%d' ) );
						break;
					case 'accounts':
						foreach ( array( 'db_wl_items', 'db_wl_notes', 'db_wl_prefs', 'db_wl_codes', 'db_wl_sessions' ) as $side ) {
							$st = $wpdb->prefix . $side;
							if ( $st === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $st ) ) ) ) {
								$wpdb->delete( $st, array( 'email' => $k ), array( '%s' ) );
							}
						}
						$n += (int) $wpdb->delete( $wpdb->prefix . 'db_accounts', array( 'email' => $k ), array( '%s' ) );
						break;
				}
			}
			$done[ $group ] = $n;
		}
		if ( array_sum( $done ) > 0 ) {
			error_log( 'DB test data: deleted ' . wp_json_encode( $done ) . ' by user ' . get_current_user_id() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $done;
	}
}

add_action( 'admin_menu', function () {
	add_management_page( 'Test data', 'Test data', 'manage_options', 'db-test-data', function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Test data</h1>';
		if ( isset( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$d = get_transient( 'db_test_data_done_' . get_current_user_id() );
			if ( is_array( $d ) ) {
				$parts = array();
				foreach ( $d as $g => $n ) {
					$parts[] = $g . ': ' . (int) $n;
				}
				echo '<div class="notice notice-success"><p>Deleted. ' . esc_html( implode( ', ', $parts ) ) . '</p></div>';
			}
		}
		echo '<p style="max-width:820px">Records left by QA and manual testing: addresses on domains reserved for testing (example.com, *.test and similar), enquiries marked &ldquo;test only&rdquo; or &ldquo;please ignore&rdquo;, and record #1 of the transfer and form-entry logs. Tick what to delete. Deleting cannot be undone, so take a database backup first if unsure.</p>';
		$found = db_test_data_find();
		$any   = false;
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Delete the ticked records permanently?\')">';
		wp_nonce_field( 'db_test_data_delete' );
		echo '<input type="hidden" name="action" value="db_test_data_delete">';
		foreach ( $found as $g => $grp ) {
			echo '<h2>' . esc_html( $grp['label'] ) . ' (' . count( $grp['rows'] ) . ')</h2>';
			if ( ! $grp['rows'] ) {
				echo '<p>None found.</p>';
				continue;
			}
			$any = true;
			echo '<table class="widefat striped" style="max-width:1100px"><thead><tr><td class="check-column" style="padding:8px 10px"><input type="checkbox" aria-label="Select all" onclick="var c=this.checked;this.closest(\'table\').querySelectorAll(\'tbody input\').forEach(function(i){i.checked=c;});"></td>';
			foreach ( $grp['head'] as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '<th>Why listed</th></tr></thead><tbody>';
			foreach ( $grp['rows'] as $r ) {
				echo '<tr><th scope="row" class="check-column" style="padding:8px 10px"><input type="checkbox" name="pick[' . esc_attr( $g ) . '][]" value="' . esc_attr( $r['key'] ) . '"></th>';
				foreach ( $r['cells'] as $c ) {
					echo '<td>' . esc_html( (string) $c ) . '</td>';
				}
				echo '<td>' . esc_html( $r['why'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( $any ) {
			echo '<p><button class="button button-primary">Delete ticked records</button></p>';
		}
		echo '</form><p style="max-width:820px">WordPress user logins created for testing are under Users; delete them there.</p></div>';
	} );
} );

add_action( 'admin_post_db_test_data_delete', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	check_admin_referer( 'db_test_data_delete' );
	$pick = isset( $_POST['pick'] ) && is_array( $_POST['pick'] ) ? wp_unslash( $_POST['pick'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$clean = array();
	foreach ( array( 'transfer', 'forms', 'leads', 'accounts' ) as $g ) {
		if ( ! empty( $pick[ $g ] ) && is_array( $pick[ $g ] ) ) {
			$clean[ $g ] = array_map( 'sanitize_text_field', $pick[ $g ] );
		}
	}
	set_transient( 'db_test_data_done_' . get_current_user_id(), db_test_data_delete( $clean ), 300 );
	wp_safe_redirect( admin_url( 'tools.php?page=db-test-data&deleted=1' ) );
	exit;
} );
