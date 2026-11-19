<?php
/**
 * Legal pages: terms, privacy, refund, cookie, acceptable use, trademark.
 *
 * The text lives in content-policies/src/*.md and is built by
 * tools/build-policies.py into policies/<slug>.html. This file publishes those
 * files as WordPress pages. Tools > Policy pages has the button; the same
 * function is what the deploy runs on staging and production. Existing pages are
 * updated in place (WordPress keeps the previous text as a revision), missing ones
 * are created.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_policies_titles' ) ) {
	/**
	 * Slug => page title, taken from the build manifest.
	 *
	 * @return array<string,string>
	 */
	function db_policies_titles() {
		$file = __DIR__ . '/policies/manifest.json';
		$list = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array();
		$out  = array();
		foreach ( is_array( $list ) ? $list : array() as $row ) {
			if ( ! empty( $row['slug'] ) && ! empty( $row['title'] ) ) {
				$out[ sanitize_title( $row['slug'] ) ] = (string) $row['title'];
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'db_policies_import' ) ) {
	/**
	 * Publishes every built policy page.
	 *
	 * @return array<int,array{slug:string,action:string,id:int,error:string}>
	 */
	function db_policies_import() {
		$report = array();
		foreach ( db_policies_titles() as $slug => $title ) {
			$row  = array( 'slug' => $slug, 'action' => 'skipped', 'id' => 0, 'error' => '' );
			$file = __DIR__ . '/policies/' . $slug . '.html';
			if ( ! is_readable( $file ) ) {
				$row['error'] = 'built file is missing';
				$report[]     = $row;
				continue;
			}
			$html = (string) file_get_contents( $file );
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $page ) {
				$id     = (int) $page->ID;
				$result = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( str_replace( '__ID__', (string) $id, $html ) ), 'post_status' => 'publish' ), true );
				$row['action'] = 'updated';
			} else {
				$result = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_content' => wp_slash( $html ) ), true );
				if ( ! is_wp_error( $result ) ) {
					$id     = (int) $result;
					$result = wp_update_post( array( 'ID' => $id, 'post_content' => wp_slash( str_replace( '__ID__', (string) $id, $html ) ) ), true );
				}
				$row['action'] = 'created';
			}
			if ( is_wp_error( $result ) || ! $result ) {
				$row['action'] = 'failed';
				$row['error']  = is_wp_error( $result ) ? $result->get_error_message() : 'unknown error';
				error_log( 'DB policies: ' . $slug . ' ' . $row['error'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				$row['id'] = (int) $result;
			}
			$report[] = $row;
		}
		if ( function_exists( 'db_pc_purge_all' ) ) {
			db_pc_purge_all();
		}
		return $report;
	}
}

add_action( 'admin_menu', function () {
	add_management_page( 'Policy pages', 'Policy pages', 'manage_options', 'db-policies', function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap"><h1>Policy pages</h1>';
		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$rows = get_transient( 'db_policies_report' );
			if ( is_array( $rows ) ) {
				echo '<div class="notice notice-success"><p>Done.</p></div><table class="widefat striped" style="max-width:720px"><thead><tr><th>Page</th><th>Result</th></tr></thead><tbody>';
				foreach ( $rows as $r ) {
					echo '<tr><td><a href="' . esc_url( home_url( '/' . $r['slug'] . '/' ) ) . '">' . esc_html( $r['slug'] ) . '</a></td><td>' . esc_html( $r['action'] . ( $r['error'] ? ': ' . $r['error'] : '' ) ) . '</td></tr>';
				}
				echo '</tbody></table>';
			}
		}
		echo '<p>Publishes the Terms of Use, Privacy Policy, Refund Policy, Cookie Policy, Acceptable Use Policy and Trademark and Copyright Notices from the files shipped with the plugin. Existing pages are updated in place and the previous text stays in the page revisions. Have the wording reviewed by a lawyer before relying on it.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'db_policies_import' );
		echo '<input type="hidden" name="action" value="db_policies_import"><p><button class="button button-primary" onclick="return confirm(\'Replace the text of these six pages with the plugin versions?\')">Publish policy pages</button></p></form></div>';
	} );
} );

add_action( 'admin_post_db_policies_import', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_policies_import' ) ) {
		wp_die( 'Not allowed.' );
	}
	set_transient( 'db_policies_report', db_policies_import(), 300 );
	wp_safe_redirect( admin_url( 'tools.php?page=db-policies&done=1' ) );
	exit;
} );

/*
 * Staging only (beta.): the policy pages publish themselves the first time an
 * administrator opens WP Admin after an upload that changes them. Each bundle
 * (manifest + page files) is published once; the result is on Tools > Policy
 * pages. The live site keeps the manual "Publish policy pages" button.
 */
add_action( 'admin_init', function () {
	if ( wp_doing_ajax() || ! function_exists( 'db_is_staging_host' ) || ! db_is_staging_host() || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$files = array_merge( array( __DIR__ . '/policies/manifest.json' ), (array) glob( __DIR__ . '/policies/*.html' ) );
	$sig   = '';
	foreach ( $files as $f ) {
		$sig .= is_readable( $f ) ? md5_file( $f ) : 'x';
	}
	$sig = md5( $sig );
	if ( get_option( 'db_policies_auto_sig' ) === $sig || get_transient( 'db_policies_auto_lock' ) ) {
		return;
	}
	set_transient( 'db_policies_auto_lock', 1, 120 );
	$report = db_policies_import();
	$failed = array_filter( $report, function ( $r ) {
		return 'failed' === $r['action'] || '' !== $r['error'];
	} );
	set_transient( 'db_policies_report', $report, DAY_IN_SECONDS );
	if ( ! $failed ) {
		update_option( 'db_policies_auto_sig', $sig, false );
	}
	add_action( 'admin_notices', function () use ( $failed ) {
		echo '<div class="notice ' . ( $failed ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $failed ? 'Staging: some policy pages could not be published.' : 'Staging: the six policy pages were published from the plugin files.' ) . ' <a href="' . esc_url( admin_url( 'tools.php?page=db-policies&done=1' ) ) . '">See the result</a></p></div>';
	} );
} );
