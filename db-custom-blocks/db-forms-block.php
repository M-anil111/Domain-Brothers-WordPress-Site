<?php
/**
 * Contact Form 7: delivery fix and a submission log.
 *
 * 1. Sender. A form whose Mail tab sends *from* the visitor's own address
 *    ("[your-name] <[your-email]>") is refused by the SMTP provider, which
 *    only lets this site send as its own verified address; the visitor then
 *    sees "There was an error trying to send your message" although the SMTP
 *    test email works. When a form's sender is not on the site's sending
 *    domain, the mail goes out from the site's address instead (keeping the
 *    display name) and the visitor's address becomes the Reply-To, so
 *    replying still reaches them.
 *
 * 2. Log. Every submission's outcome is recorded: sent, mail failed (with the
 *    mail server's error), spam (with the check that flagged it), invalid
 *    fields, or aborted. Only the form, the outcome and the reason are kept:
 *    no names, email addresses or messages. Domain Brothers > Form log shows
 *    the last 100 entries, each form's mail settings and which delivery and
 *    spam checks are active.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_FORMS_LOG_OPTION' ) ) {
	define( 'DB_FORMS_LOG_OPTION', 'db_forms_log' );
}
if ( ! defined( 'DB_FORMS_LOG_KEEP' ) ) {
	define( 'DB_FORMS_LOG_KEEP', 100 );
}

/* ───────────────────────────── 1. Sending address ─────────────────────── */

if ( ! function_exists( 'db_forms_site_sender' ) ) {
	/**
	 * The address this site sends as: the Domain Brothers SMTP setting, then
	 * the WP Mail SMTP / WP SMTP plugin's From address, then sales@.
	 */
	function db_forms_site_sender() {
		if ( function_exists( 'db_smtp_config' ) ) {
			$c = db_smtp_config();
			if ( $c && is_email( $c['from'] ) ) {
				return $c['from'];
			}
		}
		$wpms = get_option( 'wp_mail_smtp', array() );
		if ( is_array( $wpms ) && ! empty( $wpms['mail']['from_email'] ) && is_email( $wpms['mail']['from_email'] ) ) {
			return (string) $wpms['mail']['from_email'];
		}
		foreach ( array( 'wp_smtp_options', 'wp_smtp' ) as $opt ) {
			$o = get_option( $opt, array() );
			foreach ( array( 'from', 'from_email', 'mail_from' ) as $k ) {
				if ( is_array( $o ) && ! empty( $o[ $k ] ) && is_email( $o[ $k ] ) ) {
					return (string) $o[ $k ];
				}
			}
		}
		return defined( 'DB_SG_FROM' ) ? DB_SG_FROM : 'sales@domainbrothers.com';
	}
}

if ( ! function_exists( 'db_forms_parse_address' ) ) {
	/** "Name <a@b.c>" or "a@b.c" -> array( name, email ). */
	function db_forms_parse_address( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(.*)<\s*([^<>\s]+@[^<>\s]+)\s*>\s*$/', $value, $m ) ) {
			return array( trim( trim( $m[1] ), '"\'' ), trim( $m[2] ) );
		}
		return array( '', $value );
	}
}

if ( ! function_exists( 'db_forms_domain_of' ) ) {
	function db_forms_domain_of( $email ) {
		$at = strrpos( (string) $email, '@' );
		return false === $at ? '' : strtolower( substr( (string) $email, $at + 1 ) );
	}
}

if ( ! function_exists( 'db_forms_fix_sender' ) ) {
	/**
	 * Mail components for Contact Form 7. Sends from the site's own address when
	 * the form's sender is on another domain, with the original sender as
	 * Reply-To (unless the form already sets one).
	 *
	 * @param array $components subject, sender, body, recipient, additional_headers, attachments
	 * @return array
	 */
	function db_forms_fix_sender( $components ) {
		if ( ! is_array( $components ) || ! isset( $components['sender'] ) ) {
			return $components;
		}
		$site = db_forms_site_sender();
		list( $name, $email ) = db_forms_parse_address( $components['sender'] );
		$site_domain = db_forms_domain_of( $site );
		if ( '' === $site_domain || ( is_email( $email ) && db_forms_domain_of( $email ) === $site_domain ) ) {
			return $components; // already sends as this site
		}
		$name = str_replace( array( "\r", "\n", '<', '>', '"' ), '', $name );
		$components['sender'] = '' !== $name ? $name . ' <' . $site . '>' : $site;
		$headers = (string) ( $components['additional_headers'] ?? '' );
		if ( is_email( $email ) && ! preg_match( '/^\s*reply-to\s*:/im', $headers ) ) {
			$components['additional_headers'] = trim( $headers . "\nReply-To: " . ( '' !== $name ? $name . ' <' . $email . '>' : $email ) );
		}
		$GLOBALS['db_forms_sender_fixed'] = true;
		return $components;
	}
}
add_filter( 'wpcf7_mail_components', 'db_forms_fix_sender', 20, 1 );

/* ───────────────────────────── 2. Log ─────────────────────────────────── */

// The mail server's error for the submission being processed.
add_action( 'wpcf7_before_send_mail', function () {
	$GLOBALS['db_forms_in_send']   = true;
	$GLOBALS['db_forms_mail_error'] = '';
}, 1 );
add_action( 'wp_mail_failed', function ( $err ) {
	if ( ! empty( $GLOBALS['db_forms_in_send'] ) && is_wp_error( $err ) ) {
		$GLOBALS['db_forms_mail_error'] = $err->get_error_message();
	}
} );

if ( ! function_exists( 'db_forms_log_add' ) ) {
	function db_forms_log_add( array $entry ) {
		$log = get_option( DB_FORMS_LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift( $log, $entry );
		update_option( DB_FORMS_LOG_OPTION, array_slice( $log, 0, DB_FORMS_LOG_KEEP ), false );
	}
}

if ( ! function_exists( 'db_forms_spam_reasons' ) ) {
	/** Why the submission counted as spam, from Contact Form 7's own spam log. */
	function db_forms_spam_reasons( $submission ) {
		$out = array();
		if ( is_object( $submission ) && method_exists( $submission, 'get_spam_log' ) ) {
			foreach ( (array) $submission->get_spam_log() as $row ) {
				$agent  = is_array( $row ) ? (string) ( $row['agent'] ?? '' ) : '';
				$reason = is_array( $row ) ? (string) ( $row['reason'] ?? '' ) : '';
				$out[]  = trim( $agent . ( '' !== $reason ? ': ' . $reason : '' ), ': ' );
			}
		}
		return $out;
	}
}

add_action( 'wpcf7_submit', function ( $contact_form, $result ) {
	$status     = is_array( $result ) ? (string) ( $result['status'] ?? '' ) : '';
	$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
	$detail     = '';
	if ( 'spam' === $status ) {
		$detail = implode( '; ', db_forms_spam_reasons( $submission ) );
	} elseif ( 'mail_failed' === $status ) {
		$detail = (string) ( $GLOBALS['db_forms_mail_error'] ?? '' );
		if ( '' === $detail ) {
			$detail = 'wp_mail() returned false without an error message (check the SMTP plugin\'s own log).';
		}
	} elseif ( 'validation_failed' === $status && is_object( $submission ) && method_exists( $submission, 'get_invalid_fields' ) ) {
		$detail = 'Invalid fields: ' . implode( ', ', array_keys( (array) $submission->get_invalid_fields() ) );
	}
	db_forms_log_add( array(
		'ts'     => time(),
		'form'   => is_object( $contact_form ) && method_exists( $contact_form, 'id' ) ? (int) $contact_form->id() : 0,
		'title'  => is_object( $contact_form ) && method_exists( $contact_form, 'title' ) ? (string) $contact_form->title() : '',
		'status' => $status,
		'detail' => mb_substr( $detail, 0, 500 ),
		'sender' => ! empty( $GLOBALS['db_forms_sender_fixed'] ) ? 'sent as site address, visitor as Reply-To' : '',
		'page'   => is_object( $submission ) && method_exists( $submission, 'get_meta' ) ? (int) $submission->get_meta( 'container_post_id' ) : 0,
	) );
	$GLOBALS['db_forms_in_send']      = false;
	$GLOBALS['db_forms_sender_fixed'] = false;
}, 99, 2 );

/* ───────────────────────────── 3. Admin screen ────────────────────────── */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Form log', 'Form log', 'manage_options', 'db-forms', 'db_forms_admin_page' );
}, 24 );

if ( ! function_exists( 'db_forms_status_label' ) ) {
	function db_forms_status_label( $s ) {
		$map = array(
			'mail_sent'          => array( 'Sent', '#1a7f37' ),
			'mail_failed'        => array( 'Mail failed', '#b42318' ),
			'spam'               => array( 'Blocked as spam', '#b54708' ),
			'validation_failed'  => array( 'Fields not valid', '#646970' ),
			'acceptance_missing' => array( 'Consent box not ticked', '#646970' ),
			'aborted'            => array( 'Aborted', '#646970' ),
		);
		$l = $map[ $s ] ?? array( $s ? $s : 'unknown', '#646970' );
		return '<strong style="color:' . esc_attr( $l[1] ) . '">' . esc_html( $l[0] ) . '</strong>';
	}
}

if ( ! function_exists( 'db_forms_setup_rows' ) ) {
	/** What is in effect for form email, as label => value rows. */
	function db_forms_setup_rows() {
		$rows  = array();
		$smtp  = function_exists( 'db_smtp_config' ) ? db_smtp_config() : null;
		$saved = get_option( 'db_smtp', array() );
		$rows['Site sends as'] = db_forms_site_sender();
		if ( $smtp ) {
			$rows['Domain Brothers SMTP'] = 'ON: ' . $smtp['host'] . ':' . $smtp['port'] . ' as ' . $smtp['user'] . ' (Settings > DB Email delivery). It also sets the SMTP server for every email, so keep it the same as the WP SMTP plugin or clear it.';
		} elseif ( is_array( $saved ) && ! empty( $saved['host'] ) ) {
			$rows['Domain Brothers SMTP'] = 'Saved but not usable (the password cannot be read). Not in use.';
		} else {
			$rows['Domain Brothers SMTP'] = 'Off';
		}
		$rows['SendGrid key (fallback)'] = function_exists( 'db_sg_api_key' ) && db_sg_api_key() ? ( $smtp ? 'Saved, not used (SMTP above wins)' : 'ON: every email goes through SendGrid' ) : 'Off';
		$plugins = array();
		if ( defined( 'WPMS_PLUGIN_VER' ) || class_exists( 'WPMailSMTP\\Core' ) ) {
			$plugins[] = 'WP Mail SMTP';
		}
		if ( class_exists( 'WP_SMTP' ) || defined( 'WP_SMTP_VERSION' ) || get_option( 'wp_smtp_options' ) ) {
			$plugins[] = 'WP SMTP';
		}
		$rows['SMTP plugin'] = $plugins ? implode( ', ', $plugins ) : 'none detected';
		$rows['Turnstile check'] = function_exists( 'db_ts_enabled' ) && db_ts_enabled() ? 'ON: every form needs a Cloudflare Turnstile token. The site key must allow this hostname (' . wp_parse_url( home_url(), PHP_URL_HOST ) . ').' : 'Off';
		$rows['Honeypot'] = 'ON (hidden field ' . ( defined( 'DB_HONEYPOT_FIELD' ) ? DB_HONEYPOT_FIELD : '' ) . ')';
		return $rows;
	}
}

if ( ! function_exists( 'db_forms_admin_page' ) ) {
	function db_forms_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		if ( isset( $_POST['db_forms_clear'] ) && check_admin_referer( 'db_forms_clear' ) ) {
			delete_option( DB_FORMS_LOG_OPTION );
			echo '<div class="notice notice-success"><p>Log cleared.</p></div>';
		}
		echo '<div class="wrap"><h1>Form log</h1><p>Every Contact Form 7 submission and what happened to it. Only the form, the outcome and the reason are kept: no names, email addresses or messages.</p>';
		echo '<style>.dbf-setup td:first-child{width:220px;font-weight:600}.dbf-warn{color:#b42318;font-weight:600}</style>';

		echo '<h2>Email setup in effect</h2><table class="widefat striped dbf-setup"><tbody>';
		foreach ( db_forms_setup_rows() as $k => $v ) {
			echo '<tr><td>' . esc_html( $k ) . '</td><td>' . esc_html( $v ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>Forms</h2>';
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			echo '<p class="dbf-warn">Contact Form 7 is not active.</p>';
		} else {
			$site_domain = db_forms_domain_of( db_forms_site_sender() );
			echo '<table class="widefat striped"><thead><tr><th>Form</th><th>Mail To</th><th>Mail From (as set in the form)</th><th>Sent as</th></tr></thead><tbody>';
			foreach ( (array) WPCF7_ContactForm::find( array( 'posts_per_page' => 50 ) ) as $f ) {
				$mail = (array) $f->prop( 'mail' );
				list( , $from_email ) = db_forms_parse_address( (string) ( $mail['sender'] ?? '' ) );
				$tag_based = false !== strpos( $from_email, '[' );
				$own       = ! $tag_based && db_forms_domain_of( $from_email ) === $site_domain;
				$sent_as   = $own ? 'As set' : db_forms_site_sender() . ' (Reply-To: the form\'s sender)';
				$to        = (string) ( $mail['recipient'] ?? '' );
				echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=wpcf7&post=' . (int) $f->id() . '&action=edit' ) ) . '">' . esc_html( $f->title() ) . '</a> <span style="color:#646970">#' . (int) $f->id() . '</span></td>';
				echo '<td>' . ( '' === trim( $to ) ? '<span class="dbf-warn">empty: nothing is sent</span>' : esc_html( $to ) ) . '</td>';
				echo '<td>' . esc_html( (string) ( $mail['sender'] ?? '' ) ) . '</td><td>' . esc_html( $sent_as ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		$log = get_option( DB_FORMS_LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		echo '<h2>Submissions (latest ' . (int) DB_FORMS_LOG_KEEP . ')</h2>';
		echo '<table class="widefat striped"><thead><tr><th>When</th><th>Form</th><th>Page</th><th>Outcome</th><th>Reason / server message</th><th>Sender</th></tr></thead><tbody>';
		if ( ! $log ) {
			echo '<tr><td colspan="6">No submissions recorded yet. Send a test message through the form, then reload this page.</td></tr>';
		}
		foreach ( $log as $e ) {
			$page = (int) ( $e['page'] ?? 0 );
			echo '<tr><td>' . esc_html( wp_date( 'M j, Y g:i:s a', (int) ( $e['ts'] ?? 0 ) ) ) . '</td>';
			echo '<td>' . esc_html( (string) ( $e['title'] ?? '' ) ) . ' <span style="color:#646970">#' . (int) ( $e['form'] ?? 0 ) . '</span></td>';
			echo '<td>' . ( $page ? esc_html( get_the_title( $page ) ) : '' ) . '</td>';
			echo '<td>' . db_forms_status_label( (string) ( $e['status'] ?? '' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . esc_html( (string) ( $e['detail'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $e['sender'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<form method="post" style="margin-top:12px">';
		wp_nonce_field( 'db_forms_clear' );
		echo '<button class="button" name="db_forms_clear" value="1">Clear log</button></form></div>';
	}
}
