<?php
/**
 * Form entries: a copy of every Contact Form 7 submission in the database.
 *
 * Replaces the "Contact Form DB" plugin (2.10.26, no longer maintained), which
 * stops every submission that contains a checkbox, radio button or select with
 * a PHP 8 TypeError (implode() argument order, CF7DBPlugin.php line 848), so
 * the visitor gets an error and no email is sent.
 *
 * Stored after the form validates and before the email is attempted, so an
 * email outage never loses an entry. Sensitive fields (EPP codes) are already
 * masked by db-transfer-block.php at that point. Uploaded files are not kept.
 * Administrators read and export entries on Tools > Form entries.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DB_FORM_ENTRIES_VERSION', '1' );

if ( ! function_exists( 'db_form_entries_table' ) ) {
	function db_form_entries_table() {
		global $wpdb;
		return $wpdb->prefix . 'db_form_entries';
	}
}

add_action( 'init', function () {
	if ( get_option( 'db_form_entries_version', '' ) === DB_FORM_ENTRIES_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = db_form_entries_table();
	dbDelta( "CREATE TABLE {$t} (
		id         BIGINT       NOT NULL AUTO_INCREMENT,
		form_id    BIGINT       NOT NULL DEFAULT 0,
		form_title VARCHAR(200) NOT NULL DEFAULT '',
		page_url   VARCHAR(255) NOT NULL DEFAULT '',
		email      VARCHAR(255) NOT NULL DEFAULT '',
		data       LONGTEXT     NULL,
		created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY idx_form (form_id),
		KEY idx_created (created_at)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'db_form_entries_version', DB_FORM_ENTRIES_VERSION );
} );

if ( ! function_exists( 'db_form_entries_clean' ) ) {
	/**
	 * Submission fields worth keeping: no CF7 internals, no honeypots, no tokens.
	 *
	 * @param array $posted Posted data.
	 * @return array<string,string>
	 */
	function db_form_entries_clean( array $posted ) {
		$out = array();
		foreach ( $posted as $key => $value ) {
			$key = (string) $key;
			if ( 0 === strpos( $key, '_wpcf7' ) || 0 === strpos( $key, 'db_hp' ) || in_array( $key, array( 'cf-turnstile-response', 'g-recaptcha-response', 'ts' ), true ) ) {
				continue;
			}
			$value = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			$out[ substr( $key, 0, 80 ) ] = mb_substr( $value, 0, 5000 );
		}
		return $out;
	}
}

add_action( 'wpcf7_before_send_mail', function ( $form ) {
	if ( ! class_exists( 'WPCF7_Submission' ) || ! is_object( $form ) ) {
		return;
	}
	$sub = WPCF7_Submission::get_instance();
	if ( ! $sub ) {
		return;
	}
	$data  = db_form_entries_clean( (array) $sub->get_posted_data() );
	$email = '';
	foreach ( $data as $k => $v ) {
		if ( '' === $email && is_email( $v ) ) {
			$email = $v;
		}
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( db_form_entries_table(), array(
		'form_id'    => (int) $form->id(),
		'form_title' => mb_substr( (string) $form->title(), 0, 200 ),
		'page_url'   => mb_substr( (string) $sub->get_meta( 'url' ), 0, 255 ),
		'email'      => mb_substr( $email, 0, 255 ),
		'data'       => wp_json_encode( $data ),
	) );
}, 1 );

add_action( 'admin_menu', function () {
	add_management_page( 'Form entries', 'Form entries', 'manage_options', 'db-form-entries', 'db_form_entries_page' );
} );

add_action( 'admin_post_db_form_entries_csv', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_form_entries_csv' ) ) {
		wp_die( 'Not allowed.' );
	}
	global $wpdb;
	$t    = db_form_entries_table();
	$form = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = (array) $wpdb->get_results( $form ? $wpdb->prepare( "SELECT * FROM {$t} WHERE form_id = %d ORDER BY id DESC", $form ) : "SELECT * FROM {$t} ORDER BY id DESC LIMIT 5000" );
	$keys = array();
	foreach ( $rows as $r ) {
		$keys = array_unique( array_merge( $keys, array_keys( (array) json_decode( (string) $r->data, true ) ) ) );
	}
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="form-entries-' . gmdate( 'Y-m-d' ) . '.csv"' );
	$out  = fopen( 'php://output', 'w' );
	$cell = function ( $v ) {
		$v = (string) $v;
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v; // no spreadsheet formulas
	};
	fputcsv( $out, array_merge( array( 'id', 'form', 'received_utc', 'page' ), $keys ) );
	foreach ( $rows as $r ) {
		$d    = (array) json_decode( (string) $r->data, true );
		$line = array( $r->id, $r->form_title, $r->created_at, $r->page_url );
		foreach ( $keys as $k ) {
			$line[] = isset( $d[ $k ] ) ? $d[ $k ] : '';
		}
		fputcsv( $out, array_map( $cell, $line ) );
	}
	fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit;
} );

if ( ! function_exists( 'db_form_entries_page' ) ) {
	function db_form_entries_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		global $wpdb;
		$t     = db_form_entries_table();
		$form  = isset( $_GET['form'] ) ? absint( $_GET['form'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$forms = (array) $wpdb->get_results( "SELECT form_id, form_title, COUNT(*) AS n FROM {$t} GROUP BY form_id, form_title ORDER BY n DESC" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $form ? $wpdb->prepare( "SELECT * FROM {$t} WHERE form_id = %d ORDER BY id DESC LIMIT 200", $form ) : "SELECT * FROM {$t} ORDER BY id DESC LIMIT 200" );
		$base = admin_url( 'tools.php?page=db-form-entries' );
		echo '<div class="wrap"><h1>Form entries</h1><p>Every Contact Form 7 submission, saved before the email is sent. Transfer codes appear masked; the codes themselves are on Tools &gt; Transfer codes.</p><p>';
		echo '<a href="' . esc_url( $base ) . '"' . ( $form ? '' : ' style="font-weight:700"' ) . '>All</a>';
		foreach ( $forms as $f ) {
			echo ' &middot; <a href="' . esc_url( add_query_arg( 'form', (int) $f->form_id, $base ) ) . '"' . ( (int) $f->form_id === $form ? ' style="font-weight:700"' : '' ) . '>' . esc_html( $f->form_title ) . ' (' . (int) $f->n . ')</a>';
		}
		$csv = wp_nonce_url( add_query_arg( array( 'action' => 'db_form_entries_csv', 'form' => $form ), admin_url( 'admin-post.php' ) ), 'db_form_entries_csv' );
		echo '</p><p><a class="button" href="' . esc_url( $csv ) . '">Download CSV</a></p>';
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Received (UTC)</th><th>Form</th><th>Email</th><th>Fields</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">No entries yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$d    = (array) json_decode( (string) $r->data, true );
			$list = '';
			foreach ( $d as $k => $v ) {
				$list .= '<div><strong>' . esc_html( $k ) . ':</strong> ' . esc_html( mb_substr( (string) $v, 0, 400 ) ) . '</div>';
			}
			echo '<tr><td>' . (int) $r->id . '</td><td>' . esc_html( $r->created_at ) . '</td><td>' . esc_html( $r->form_title ) . '<br><small>' . esc_html( $r->page_url ) . '</small></td><td>' . esc_html( $r->email ) . '</td><td>' . $list . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $list built from escaped parts.
		}
		echo '</tbody></table></div>';
	}
}
