<?php
/**
 * Domain transfer page (/domain-transfer/): secure handling of the
 * authorization (EPP) code, and the page layout around the form.
 *
 * Security. The form is Contact Form 7 form 21860. Before this block, the EPP
 * code travelled in the notification email in plain text, and the Contact Form
 * DB plugin stored it in plain text in the database. Now:
 *   1. When the form is submitted, the code fields (EPPCode, DomainEPPCode) are
 *      taken out of the submission and replaced with a masked value
 *      ("Received (stored encrypted)"). The email and Contact Form DB only ever see
 *      the masked value.
 *   2. After the form passes validation and is sent, the real code is stored
 *      encrypted (AES-256-GCM, key derived from the site's secret salts, the
 *      same helpers as db-sold-block.php) in the table {prefix}db_transfer_in.
 *   3. Administrators read it on Tools > Transfer codes. Each reveal is
 *      recorded. Codes are erased 30 days after submission.
 * The page is served over HTTPS only and is never cached.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_TRANSFER_FORM_ID' ) ) {
	define( 'DB_TRANSFER_FORM_ID', 21860 );
}
if ( ! defined( 'DB_TRANSFER_KEEP_DAYS' ) ) {
	define( 'DB_TRANSFER_KEEP_DAYS', 30 );
}
define( 'DB_TRANSFER_TABLE_VERSION', '1' );

/* ───────────────────────────── Table ───────────────────────────── */

if ( ! function_exists( 'db_transfer_table' ) ) {
	function db_transfer_table() {
		global $wpdb;
		return $wpdb->prefix . 'db_transfer_in';
	}
}

add_action( 'init', function () {
	if ( get_option( 'db_transfer_table_version', '' ) === DB_TRANSFER_TABLE_VERSION ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$t = db_transfer_table();
	dbDelta( "CREATE TABLE {$t} (
		id          INT          NOT NULL AUTO_INCREMENT,
		domain      VARCHAR(255) NOT NULL DEFAULT '',
		order_no    VARCHAR(100) NOT NULL DEFAULT '',
		email       VARCHAR(255) NOT NULL DEFAULT '',
		unlock_ok   VARCHAR(10)  NOT NULL DEFAULT '',
		code_enc    TEXT         NULL,
		code_hint   VARCHAR(12)  NOT NULL DEFAULT '',
		created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
		viewed_at   DATETIME     NULL,
		viewed_by   BIGINT       NOT NULL DEFAULT 0,
		erased_at   DATETIME     NULL,
		PRIMARY KEY (id),
		KEY idx_domain (domain(40)),
		KEY idx_created (created_at)
	) {$wpdb->get_charset_collate()};" );
	update_option( 'db_transfer_table_version', DB_TRANSFER_TABLE_VERSION );
} );

/* ─────────────────────── Contact Form 7 hooks ───────────────────── */

if ( ! function_exists( 'db_transfer_is_form' ) ) {
	/**
	 * @param WPCF7_ContactForm|int|null $form Form or id.
	 * @return bool
	 */
	function db_transfer_is_form( $form ) {
		$id = is_object( $form ) && method_exists( $form, 'id' ) ? (int) $form->id() : (int) $form;
		return (int) apply_filters( 'db_transfer_form_id', DB_TRANSFER_FORM_ID ) === $id;
	}
}

if ( ! function_exists( 'db_transfer_mask' ) ) {
	/**
	 * What the email and form entries show instead of the code. No characters of the code: the
	 * email goes to an outside mailbox, and the last four gave away half of an 8-character code
	 * (AC-15). The hint stays on Tools > Transfer codes.
	 */
	function db_transfer_mask( $code ) {
		return 'Received (stored encrypted)';
	}
}

$GLOBALS['db_transfer_pending'] = null;

// Runs while CF7 builds the submission, before validation, mail and Contact
// Form DB: the code is taken out and kept in memory for this request only.
add_filter( 'wpcf7_posted_data', function ( $data ) {
	$form = class_exists( 'WPCF7_ContactForm' ) ? WPCF7_ContactForm::get_current() : null;
	$id   = isset( $_POST['_wpcf7'] ) ? absint( $_POST['_wpcf7'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- CF7 verifies its own submissions.
	if ( ! is_array( $data ) || ! ( db_transfer_is_form( $form ) || db_transfer_is_form( $id ) ) ) {
		return $data;
	}
	$code = '';
	foreach ( array( 'EPPCode', 'DomainEPPCode' ) as $field ) {
		if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) && '' !== trim( $data[ $field ] ) ) {
			if ( '' === $code ) {
				$code = trim( $data[ $field ] );
			}
			$data[ $field ] = db_transfer_mask( trim( $data[ $field ] ) );
		}
	}
	$GLOBALS['db_transfer_pending'] = array(
		'code'   => $code,
		'domain' => isset( $data['EPPDomainName'] ) ? strtolower( sanitize_text_field( (string) $data['EPPDomainName'] ) ) : '',
		'order'  => isset( $data['OrderNumber'] ) ? sanitize_text_field( (string) $data['OrderNumber'] ) : '',
		'email'  => isset( $data['Buyer_EmailID'] ) ? sanitize_email( (string) $data['Buyer_EmailID'] ) : '',
		'unlock' => isset( $data['unlock_domain'] ) ? sanitize_text_field( is_array( $data['unlock_domain'] ) ? (string) reset( $data['unlock_domain'] ) : (string) $data['unlock_domain'] ) : '',
	);
	return $data;
}, 5 );

// Stored once the submission passed validation, before the email goes out: storing on
// wpcf7_mail_sent lost the code whenever the email failed (bug register batch 2, AC-11).
add_action( 'wpcf7_before_send_mail', function ( $form ) {
	$p = $GLOBALS['db_transfer_pending'];
	$GLOBALS['db_transfer_pending'] = null;
	if ( ! db_transfer_is_form( $form ) || ! is_array( $p ) || '' === $p['code'] ) {
		return;
	}
	$clean = function_exists( 'db_epp_clean_code' ) ? db_epp_clean_code( db_transfer_unspace( $p['code'] ) ) : trim( $p['code'] );
	$enc   = ( null !== $clean && function_exists( 'db_epp_encrypt' ) ) ? db_epp_encrypt( $clean ) : false;
	if ( false === $enc ) {
		if ( function_exists( 'db_log_error' ) ) {
			db_log_error( 'transfer', 'EPP code not stored: encryption unavailable or code invalid', array( 'domain' => $p['domain'] ) );
		}
		return; // never store a code unencrypted
	}
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->insert( db_transfer_table(), array(
		'domain'    => substr( $p['domain'], 0, 255 ),
		'order_no'  => substr( $p['order'], 0, 100 ),
		'email'     => substr( $p['email'], 0, 255 ),
		'unlock_ok' => substr( $p['unlock'], 0, 10 ),
		'code_enc'  => $enc,
		'code_hint' => strlen( $clean ) >= 8 ? substr( $clean, -4 ) : '',
	) );
} );

// Even if another plugin reads the raw request, the stored form entry and the
// email show only the mask (belt and braces for Contact Form DB).
add_filter( 'cfdb_form_data', function ( $form_data ) {
	if ( is_object( $form_data ) && isset( $form_data->posted_data ) && is_array( $form_data->posted_data ) ) {
		foreach ( array( 'EPPCode', 'DomainEPPCode' ) as $field ) {
			if ( isset( $form_data->posted_data[ $field ] ) && false === strpos( (string) $form_data->posted_data[ $field ], 'stored encrypted' ) && '' !== trim( (string) $form_data->posted_data[ $field ] ) ) {
				$form_data->posted_data[ $field ] = db_transfer_mask( trim( (string) $form_data->posted_data[ $field ] ) );
			}
		}
	}
	return $form_data;
} );

/* ────────────────────────── Retention ───────────────────────────── */

if ( ! function_exists( 'db_transfer_purge' ) ) {
	/** Erase codes older than DB_TRANSFER_KEEP_DAYS days; the record itself stays. */
	function db_transfer_purge() {
		global $wpdb;
		$t = db_transfer_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET code_enc = NULL, erased_at = UTC_TIMESTAMP() WHERE code_enc IS NOT NULL AND created_at < ( UTC_TIMESTAMP() - INTERVAL %d DAY )", (int) DB_TRANSFER_KEEP_DAYS ) );
	}
}
add_action( 'db_transfer_daily', 'db_transfer_purge' );
add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'db_transfer_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'db_transfer_daily' );
	}
} );

/* ─────────────────────────── Admin page ─────────────────────────── */

add_action( 'admin_menu', function () {
	add_management_page( 'Transfer codes', 'Transfer codes', 'manage_options', 'db-transfer-codes', 'db_transfer_admin_page' );
} );

if ( ! function_exists( 'db_transfer_admin_page' ) ) {
	function db_transfer_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		db_transfer_purge();
		global $wpdb;
		$t      = db_transfer_table();
		$reveal = 0;
		$plain  = '';
		if ( isset( $_POST['db_transfer_reveal'] ) && check_admin_referer( 'db_transfer_reveal' ) ) {
			$reveal = absint( $_POST['db_transfer_reveal'] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $reveal ) );
			if ( $row && $row->code_enc ) {
				$plain = (string) db_epp_decrypt( $row->code_enc );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $t, array( 'viewed_at' => current_time( 'mysql', true ), 'viewed_by' => get_current_user_id() ), array( 'id' => $reveal ) );
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( "SELECT id, domain, order_no, email, unlock_ok, code_hint, created_at, viewed_at, viewed_by, erased_at, ( code_enc IS NOT NULL ) AS has_code FROM {$t} ORDER BY id DESC LIMIT 200" );
		echo '<div class="wrap"><h1>Transfer codes</h1>';
		echo '<p>Authorization codes sent through the domain transfer form. They are stored encrypted, shown only here, and erased ' . (int) DB_TRANSFER_KEEP_DAYS . ' days after they arrive. Every reveal is recorded.</p>';
		if ( $reveal && '' !== $plain ) {
			echo '<div class="notice notice-warning"><p>Code for request #' . (int) $reveal . ': <code style="font-size:16px;user-select:all">' . esc_html( $plain ) . '</code> Do not paste it into email or chat.</p></div>';
		}
		echo '<table class="widefat striped"><thead><tr><th>#</th><th>Domain</th><th>Order</th><th>Email</th><th>Unlocked</th><th>Received</th><th>Code</th><th>Last viewed</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">No transfer codes yet.</td></tr>';
		}
		foreach ( $rows as $r ) {
			$code = $r->has_code
				? '<form method="post" style="margin:0">' . wp_nonce_field( 'db_transfer_reveal', '_wpnonce', true, false ) . '<input type="hidden" name="db_transfer_reveal" value="' . (int) $r->id . '"><button class="button">Reveal' . ( $r->code_hint ? ' (ends ' . esc_html( $r->code_hint ) . ')' : '' ) . '</button></form>'
				: '<em>' . ( $r->erased_at ? 'Erased ' . esc_html( $r->erased_at ) : 'None' ) . '</em>';
			$who  = $r->viewed_by ? get_userdata( (int) $r->viewed_by ) : null;
			echo '<tr><td>' . (int) $r->id . '</td><td>' . esc_html( $r->domain ) . '</td><td>' . esc_html( $r->order_no ) . '</td><td>' . esc_html( $r->email ) . '</td><td>' . esc_html( $r->unlock_ok ) . '</td><td>' . esc_html( $r->created_at ) . ' UTC</td><td>' . $code . '</td><td>' . ( $r->viewed_at ? esc_html( $r->viewed_at ) . ' UTC' . ( $who ? ' by ' . esc_html( $who->user_login ) : '' ) : '&ndash;' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $code built from escaped parts.
		}
		echo '</tbody></table></div>';
	}
}

/* ───────────────────────────── Page ─────────────────────────────── */

if ( ! function_exists( 'db_transfer_faqs' ) ) {
	function db_transfer_faqs() {
		return array(
			array( 'What is a domain transfer?', 'A transfer moves a domain name from one registrar account to another, usually from the seller to the buyer. The name keeps working while it moves, and the remaining registration time carries over.' ),
			array( 'What is an EPP code?', 'The EPP code, also called an authorization or auth code, is a password the current registrar issues for one domain. The new registrar needs it to accept the transfer. Our EPP guide shows where to find it at each registrar.' ),
			array( 'Why does the domain need to be unlocked?', 'Registrars lock domains against unauthorized transfers. The lock has to be switched off in the current registrar account before a transfer can start.' ),
			array( 'How long does a transfer take?', 'A transfer between registrars usually completes within five to seven days. A move inside the same registrar (a "push" between accounts) can finish the same day.' ),
			array( 'Can every domain be transferred?', 'Most can. ICANN rules block a transfer to a new registrar within 60 days of registration or of a previous transfer. In that case we can move the name inside its current registrar instead.' ),
			array( 'Is my EPP code safe with you?', 'Yes. The page is served over HTTPS. The code is removed from the notification email, stored encrypted (AES-256), shown only to our transfer team, and erased 30 days after you send it.' ),
			array( 'Is there a fee for the transfer?', 'There is no fee from Domain Brothers. Some registrars charge to receive a transfer, and that charge usually adds a year of registration.' ),
		);
	}
}

if ( ! function_exists( 'db_transfer_page_html' ) ) {
	/**
	 * Wraps the theme's form markup with the page structure.
	 *
	 * @param string $html Full page HTML.
	 * @return string
	 */
	function db_transfer_page_html( $html ) {
		$main = strpos( $html, '<main id="main"' );
		$end  = strrpos( $html, '</main>' );
		if ( false === $main || false === $end || false === strpos( $html, 'wpcf7-f' . DB_TRANSFER_FORM_ID ) || false !== strpos( $html, 'class="dbx-tr"' ) ) {
			return $html;
		}
		$open  = strpos( $html, '>', $main ) + 1;
		$inner = substr( $html, $open, $end - $open );
		$inner = preg_replace( '#<h1 class="screen-reader-text">.*?</h1>#s', '', $inner, 1 );

		$ico = function ( $p ) {
			return '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
		};
		$steps = array(
			array( $ico( '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/>' ), 'Unlock the domain', 'Switch off the transfer lock in your registrar account.' ),
			array( $ico( '<circle cx="8" cy="15" r="4"/><path d="M11 12l9-9M16 7l3 3"/>' ), 'Get the EPP code', 'Request the authorization code from your registrar.' ),
			array( $ico( '<path d="M12 3l8 3v6c0 5-3.400 8-8 9.500C7.400 20 4 17 4 12V6z"/><path d="M9 12l2.200 2.200L15 10"/>' ), 'Send it securely', 'Use this form. The code is encrypted the moment it arrives.' ),
			array( $ico( '<path d="M4 9h15M15 5l4 4-4 4M20 15H5M9 11l-4 4 4 4"/>' ), 'We complete the move', 'We start the transfer and email you at each stage.' ),
		);
		$s = '<ol class="dbx-tr-steps">';
		foreach ( $steps as $i => $st ) {
			$s .= '<li><span class="dbx-tr-ico">' . $st[0] . '</span><strong>' . ( $i + 1 ) . '. ' . esc_html( $st[1] ) . '</strong><span>' . esc_html( $st[2] ) . '</span></li>';
		}
		$s .= '</ol>';

		$faq    = '';
		$schema = array();
		foreach ( db_transfer_faqs() as $i => $f ) {
			$faq     .= '<details class="dbx-tr-faq"' . ( 0 === $i ? ' open' : '' ) . '><summary>' . esc_html( $f[0] ) . '</summary><p>' . esc_html( $f[1] ) . '</p></details>';
			$schema[] = array( '@type' => 'Question', 'name' => $f[0], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f[1] ) );
		}
		$guide = esc_url( home_url( '/epp-guidelines/' ) );

		$new = '<div class="dbx-tr">'
			. '<section class="dbx-tr-hero"><p class="dbp-eyebrow">Transfers</p><h1 class="dbx-tr-h1">Domain transfer</h1>'
			. '<p class="dbx-tr-lead">Send the authorization (EPP) code for a domain you bought or sold through Domain Brothers. We handle the move to the new registrar and keep you updated until it is complete.</p>'
			. '<ul class="dbx-tr-trust"><li>' . $ico( '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 018 0v3"/>' ) . 'Sent over HTTPS</li><li>' . $ico( '<path d="M12 3l8 3v6c0 5-3.400 8-8 9.500C7.400 20 4 17 4 12V6z"/>' ) . 'Stored encrypted (AES-256)</li><li>' . $ico( '<path d="M3 6h18M8 6V4h8v2M6 6l1 14h10l1-14"/>' ) . 'Erased after 30 days</li></ul></section>'
			. $s
			. '<div class="dbx-tr-grid"><section class="dbx-tr-form" aria-labelledby="dbx-tr-fh"><h2 id="dbx-tr-fh">Transfer details</h2><p class="dbx-tr-note">Fields marked * are required. Not sure where the code is? <a href="' . $guide . '">Read the EPP code guide</a>.</p>' . $inner . '</section>'
			. '<aside class="dbx-tr-side"><h2>Before you send</h2><ul><li>The domain is at least 60 days old and was not transferred in the last 60 days.</li><li>The registrant email on the domain is current: some registrars send an approval request there.</li><li>Privacy protection does not block transfers, but some registrars ask you to switch it off.</li></ul><p>Questions? <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Contact the transfer team</a>.</p></aside></div>'
			. '<section class="dbx-tr-faqs" aria-labelledby="dbx-tr-qh"><h2 id="dbx-tr-qh">Domain transfer questions</h2>' . $faq . '</section>'
			. '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $schema ) ) . '</script>'
			. '</div>';
		return substr( $html, 0, $open ) . $new . substr( $html, $end );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_page( 'domain-transfer' ) ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	nocache_headers();
	ob_start( 'db_transfer_page_html' );
}, 28 );

// The domain field arrives filled from an order link; when someone opens the
// page directly it has to be typable, not read-only and empty.
add_action( 'wp_footer', function () {
	if ( ! is_page( 'domain-transfer' ) ) {
		return;
	}
	echo '<script>(function(){var d=document.getElementById("EPPDomainName");if(d&&!d.value){d.removeAttribute("readonly");d.setAttribute("placeholder","example.com");d.setAttribute("autocomplete","off");}var e=document.getElementById("EPPCode");if(e){e.setAttribute("autocomplete","off");e.setAttribute("spellcheck","false");e.setAttribute("autocapitalize","off");}})();</script>';
}, 99 );

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page( 'domain-transfer' ) ) {
		return;
	}
	$css = 'html body.dbp-form .billing_cnt.epp_page{max-width:1120px!important}'
		// The theme's two-step bar repeats the four steps in the hero; hidden, and the gap it left closed.
		. '.billing_cnt.epp_page #order-standard_cart,.billing_cnt.epp_page>.container>.row:first-child{display:none!important}.billing_cnt.epp_page .epp_primary_info{margin-top:0!important;padding-top:0!important}html body.db-ui-active.page:not(.home) .billing_cnt.epp_page #main.site-main:not(:has(.entry-wrapper)){margin-top:0!important;padding-top:0!important}'
		. 'html body .dbx-tr-hero .dbx-lede{color:rgba(255,255,255,.8)!important;opacity:1}'
		. '.dbx-tr{max-width:1120px;margin:0 auto;padding:0}'
		. 'html body.db-ui-active .dbx-tr-form .wpcf7{padding:0!important;border:0!important;box-shadow:none!important;background:none!important}'
		. 'html body.db-ui-active .dbx-tr-form .hide-domain-div{display:none!important}'
		. 'html body.db-ui-active .dbx-tr-form .star{display:none}'
		. 'html body.db-ui-active .dbx-tr-form .epp-req p::after{content:" *";color:#b42318}'
		. 'html body.db-ui-active .dbx-tr-form .form_group{grid-template-columns:minmax(0,1fr)!important}'
		. 'html body.db-ui-active .dbx-tr-form .epp-req p{margin:0!important}'
		. '.dbx-tr-hero{padding:clamp(28px,4.4vw,52px);border-radius:28px;color:#fff;background:radial-gradient(900px 420px at 100% 0%,rgba(25,179,166,.45) 0%,rgba(25,179,166,0) 62%),#0c1424;margin:0 0 22px}'
		. 'html body.db-ui-active .dbx-tr-h1{margin:0 0 12px;font-size:clamp(32px,5vw,54px);line-height:1.05;letter-spacing:-.03em;font-weight:800;color:#fff!important;text-align:left}'
		. 'html body.db-ui-active .dbx-tr-hero .dbp-eyebrow{margin:0 0 14px;color:#b6f0ea!important;font-size:12.5px;font-weight:800;letter-spacing:.14em;text-transform:uppercase}'
		. 'html body.db-ui-active .dbx-tr-lead{margin:0;max-width:62ch;font-size:clamp(16.5px,1.5vw,18.5px);line-height:1.6;color:rgba(255,255,255,.84)!important}'
		. '.dbx-tr-trust{display:flex;flex-wrap:wrap;gap:10px 26px;margin:24px 0 0;padding:18px 0 0;border-top:1px solid rgba(255,255,255,.14);list-style:none}'
		. 'html body.db-ui-active .dbx-tr-trust li{display:flex;align-items:center;gap:8px;margin:0;font-size:14.5px;color:rgba(255,255,255,.88)!important}.dbx-tr-trust svg{color:#34d5c5}'
		. '.dbx-tr-steps{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin:0 0 26px;padding:0;list-style:none}'
		. '.dbx-tr-steps li{display:flex;flex-direction:column;gap:6px;margin:0;padding:18px;border:1px solid var(--m-line,#e5e7eb);border-radius:18px;background:#fff}'
		. '.dbx-tr-ico{display:flex;align-items:center;justify-content:center;width:44px;height:44px;margin:0 0 6px;border-radius:12px;background:#e8f4f3;color:var(--m-accent,#0b7a75)}'
		. 'html body.db-ui-active .dbx-tr-steps strong{font-size:16px;color:var(--m-ink,#111827)}html body.db-ui-active .dbx-tr-steps span{font-size:14.5px;line-height:1.5;color:var(--m-text,#374151)}'
		. '.dbx-tr-grid{display:grid;grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);gap:22px;align-items:start;margin:0 0 clamp(32px,5vw,56px)}'
		. '.dbx-tr-form,.dbx-tr-side{padding:clamp(20px,3vw,32px);border:1px solid var(--m-line,#e5e7eb);border-radius:22px;background:#fff}.dbx-tr-side{background:var(--m-soft,#f6f7f9)}'
		. 'html body.db-ui-active .dbx-tr h2{margin:0 0 10px;font-size:clamp(21px,2.4vw,26px);line-height:1.2;font-weight:800;letter-spacing:-.02em;color:var(--m-ink,#111827);text-align:left}'
		. 'html body.db-ui-active .dbx-tr-note,html body.db-ui-active .dbx-tr-side p,html body.db-ui-active .dbx-tr-side li{font-size:15.5px;line-height:1.6;color:var(--m-text,#374151)}'
		. '.dbx-tr-side ul{margin:0 0 14px;padding-left:20px}.dbx-tr-side li{margin:0 0 8px;list-style:disc}'
		. 'html body.db-ui-active .dbx-tr a{color:var(--m-accent,#0b7a75);text-decoration:underline;text-underline-offset:3px}'
		. 'html body.db-ui-active .dbx-tr-form .epp-title{display:none}'
		. 'html body.db-ui-active .dbx-tr-form .form_group{display:grid;grid-template-columns:minmax(0,1fr);gap:6px;margin:0 0 16px!important}'
		. 'html body.db-ui-active .dbx-tr-form .form_group>[class*=col-]{width:auto!important;float:none!important;padding:0!important;max-width:none!important}'
		. 'html body.db-ui-active .dbx-tr-form .epp-req p{margin:0;font-size:15px;font-weight:700;color:var(--m-ink,#111827)}'
		. 'html body.db-ui-active .dbx-tr-form input[type=text]{width:100%!important;min-height:50px;padding:0 16px!important;border:1.5px solid #9aa3af!important;border-radius:12px!important;font-size:16px!important;background:#fff!important}'
		. 'html body.db-ui-active .dbx-tr-form input[type=text]:focus{border-color:var(--m-ink,#111827)!important;box-shadow:0 0 0 3px rgba(11,122,117,.22)!important;outline:0}'
		. 'html body.db-ui-active .dbx-tr-form input[readonly]{background:var(--m-soft,#f6f7f9)!important}'
		. 'html body.db-ui-active .dbx-tr-form .wpcf7-list-item{margin:0 18px 0 0}html body.db-ui-active .dbx-tr-form .wpcf7-list-item label,html body.db-ui-active .dbx-tr-form .wpcf7-list-item{font-size:15.5px}'
		. 'html body.db-ui-active .dbx-tr-form .hlp-info{margin:8px 0 0;padding:12px 14px;border-radius:12px;background:#f1f8f7;font-size:14.5px;line-height:1.55}'
		. 'html body.db-ui-active .dbx-tr-form .auth_chk_sp{display:none}html body.db-ui-active .dbx-tr-form .auth-chk{width:auto!important;float:none!important;padding:0!important;margin:4px 0 16px}'
		. 'html body.db-ui-active .dbx-tr-form input[type=submit]{min-width:200px;min-height:52px;padding:0 26px!important;border:0!important;border-radius:999px!important;background:#111827!important;color:#fff!important;font-size:16px!important;font-weight:700;cursor:pointer}'
		. 'html body.db-ui-active .dbx-tr-form input[type=submit]:hover{background:var(--m-accent,#0b7a75)!important}'
		. '.dbx-tr-faqs{margin:0 0 clamp(24px,4vw,40px)}.dbx-tr-faq{border-bottom:1px solid var(--m-line,#e5e7eb)}'
		. 'html body.db-ui-active .dbx-tr-faq summary{display:flex;justify-content:space-between;gap:16px;padding:16px 0;cursor:pointer;list-style:none;font-size:17px;font-weight:700;color:var(--m-ink,#111827)}'
		. '.dbx-tr-faq summary::-webkit-details-marker{display:none}.dbx-tr-faq summary::after{content:"+";font-size:22px;line-height:1;color:var(--m-accent,#0b7a75)}.dbx-tr-faq[open] summary::after{content:"\2212"}'
		. 'html body.db-ui-active .dbx-tr-faq p{margin:0 0 16px;max-width:72ch;font-size:16px;line-height:1.65;color:var(--m-text,#374151)}'
		. '@media (max-width:900px){.dbx-tr-steps{grid-template-columns:repeat(2,minmax(0,1fr))}.dbx-tr-grid{grid-template-columns:minmax(0,1fr)}}@media (max-width:460px){.dbx-tr-steps{grid-template-columns:minmax(0,1fr)}}';
	if ( function_exists( 'db_external_style' ) && db_external_style( 'transfer', $css ) ) {
		return;
	}
	wp_register_style( 'db-transfer', false, array(), DB_BLOCKS_LOADED );
	wp_enqueue_style( 'db-transfer' );
	wp_add_inline_style( 'db-transfer', $css );
}, 114 );


if ( ! function_exists( 'db_transfer_unspace' ) ) {
	/** Pure: removes the spaces and line breaks that copy and paste adds to a transfer code (AC-11). */
	function db_transfer_unspace( $code ) {
		return (string) preg_replace( '/\s+/u', '', (string) $code );
	}
}

// A code that still cannot be stored is refused with a message, instead of being dropped while the
// email and the form entry said 'Received, stored encrypted' (AC-11).
add_filter( 'wpcf7_validate', function ( $result, $tags ) {
	$form = class_exists( 'WPCF7_ContactForm' ) ? WPCF7_ContactForm::get_current() : null;
	if ( ! db_transfer_is_form( $form ) || ! function_exists( 'db_epp_clean_code' ) ) {
		return $result;
	}
	foreach ( (array) $tags as $tag ) {
		$name = is_object( $tag ) ? $tag->name : ( $tag['name'] ?? '' );
		if ( ! in_array( $name, array( 'EPPCode', 'DomainEPPCode' ), true ) ) {
			continue;
		}
		$raw = isset( $_POST[ $name ] ) && is_string( $_POST[ $name ] ) ? wp_unslash( $_POST[ $name ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( '' !== trim( $raw ) && null === db_epp_clean_code( db_transfer_unspace( $raw ) ) ) {
			$result->invalidate( $tag, 'Paste the transfer code exactly as your registrar shows it (up to 120 characters).' );
		}
	}
	return $result;
}, 20, 2 );
