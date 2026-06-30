<?php
/* === DB SMTP routing (Brevo) ===
 *
 * Routes all WordPress wp_mail() through Brevo SMTP relay.
 * Replaces db_smtp_routing_block_v3.php (Hostinger SMTP).
 *
 * DEPLOY:
 *   1. In functions.php, DELETE the old block delimited by
 *        /* === DB SMTP routing === * /  ...  /* === end DB SMTP routing === * /
 *      (that was the Hostinger block). Then paste THIS block at the end.
 *   2. In WP admin go to:  Settings → DB SMTP (Brevo)
 *   3. Paste the SMTP key (starts with xsmtpsib-) into the SMTP Key field and Save.
 *   4. Click "Send test email" (or visit /?db_mailtest=you@email.com as admin).
 *      A green ✓ = working. All receipts, offer emails, and alerts will now deliver.
 *
 * BREVO SETTINGS (pre-configured — do not change unless you switch Brevo accounts):
 *   Server   : smtp-relay.brevo.com
 *   Port     : 587 (STARTTLS)
 *   Login    : 9fe6c7001@smtp-brevo.com
 *   From     : sales@domainbrothers.com
 *
 * The SMTP key (password) is stored as a WordPress option — never in this file.
 * Rotate it any time at Brevo → SMTP & API → SMTP Keys without touching PHP.
 */

if ( ! defined( 'DB_BREVO_HOST' ) ) define( 'DB_BREVO_HOST', 'smtp-relay.brevo.com' );
if ( ! defined( 'DB_BREVO_PORT' ) ) define( 'DB_BREVO_PORT', 587 );
if ( ! defined( 'DB_BREVO_USER' ) ) define( 'DB_BREVO_USER', '9fe6c7001@smtp-brevo.com' );
if ( ! defined( 'DB_BREVO_FROM' ) ) define( 'DB_BREVO_FROM', 'sales@domainbrothers.com' );
if ( ! defined( 'DB_BREVO_NAME' ) ) define( 'DB_BREVO_NAME', 'Domain Brothers' );

/* Return the stored SMTP key, or '' if not yet set. */
function db_brevo_smtp_key() {
	return (string) get_option( 'db_brevo_smtp_key', '' );
}

/* ---- Configure PHPMailer to use Brevo SMTP on every wp_mail() call ---- */
add_action( 'phpmailer_init', function ( $phpmailer ) {
	$key = db_brevo_smtp_key();
	if ( ! $key ) {
		// Key not saved yet — leave PHPMailer as-is; mail() fallback is used.
		return;
	}
	$phpmailer->isSMTP();
	$phpmailer->SMTPAuth   = true;
	$phpmailer->SMTPSecure = 'tls'; // STARTTLS on port 587
	$phpmailer->Host       = DB_BREVO_HOST;
	$phpmailer->Port       = DB_BREVO_PORT;
	$phpmailer->Username   = DB_BREVO_USER;
	$phpmailer->Password   = $key;
	$phpmailer->From       = DB_BREVO_FROM;
	$phpmailer->FromName   = DB_BREVO_NAME;
	// Enforce certificate verification (do not set to false in production).
	$phpmailer->SMTPOptions = array(
		'ssl' => array(
			'verify_peer'       => true,
			'verify_peer_name'  => true,
			'allow_self_signed' => false,
		),
	);
} );

/* Force the From address on every wp_mail() call — prevents plugins overriding it. */
add_filter( 'wp_mail_from',      function () { return DB_BREVO_FROM; } );
add_filter( 'wp_mail_from_name', function () { return DB_BREVO_NAME; } );

/* ---- Settings page: Settings → DB SMTP (Brevo) ---- */
add_action( 'admin_menu', function () {
	add_options_page(
		'DB SMTP (Brevo)',
		'DB SMTP (Brevo)',
		'manage_options',
		'db-smtp-brevo',
		'db_brevo_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'db_brevo_settings', 'db_brevo_smtp_key', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
} );

function db_brevo_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$key_saved = '' !== db_brevo_smtp_key();
	$test_url  = esc_url( add_query_arg( 'db_mailtest', get_option( 'admin_email' ), home_url( '/' ) ) );
	?>
	<div class="wrap">
		<h1>DB SMTP — Brevo</h1>
		<p>All WordPress emails (<code>wp_mail()</code>) are routed through Brevo's SMTP relay.
		Enter your Brevo SMTP key below, save, then click <strong>Send test email</strong>.</p>

		<table class="form-table widefat striped" style="max-width:620px;margin-bottom:24px">
			<tr><th>SMTP Server</th><td><code><?php echo esc_html( DB_BREVO_HOST ); ?></code></td></tr>
			<tr><th>Port</th>       <td><code><?php echo esc_html( DB_BREVO_PORT ); ?></code> &mdash; STARTTLS</td></tr>
			<tr><th>Login</th>      <td><code><?php echo esc_html( DB_BREVO_USER ); ?></code></td></tr>
			<tr><th>From</th>       <td><code><?php echo esc_html( DB_BREVO_FROM ); ?></code> / <?php echo esc_html( DB_BREVO_NAME ); ?></td></tr>
			<tr>
				<th>Status</th>
				<td><?php if ( $key_saved ) : ?>
					<span style="color:#1a7f37;font-weight:600">&#10003; SMTP key saved &mdash; Brevo routing is active.</span>
				<?php else : ?>
					<span style="color:#d63638;font-weight:600">&#9888; SMTP key not yet set. Emails are <em>not</em> delivering until you save a key.</span>
				<?php endif; ?></td>
			</tr>
		</table>

		<form method="post" action="options.php">
			<?php settings_fields( 'db_brevo_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="db_brevo_smtp_key">SMTP Key</label></th>
					<td>
						<input type="password" id="db_brevo_smtp_key" name="db_brevo_smtp_key"
							value="<?php echo esc_attr( db_brevo_smtp_key() ); ?>"
							class="regular-text" autocomplete="new-password" style="width:420px">
						<p class="description">
							Brevo SMTP key (starts with <code>xsmtpsib-</code>).
							Find or regenerate it at <strong>Brevo &rarr; SMTP &amp; API &rarr; SMTP Keys</strong>.
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save SMTP Key' ); ?>
		</form>

		<hr>
		<h2>Send a test email</h2>
		<p>After saving the key, send a test to confirm delivery:</p>
		<p>
			<a class="button button-secondary" href="<?php echo $test_url; ?>">
				Send test to <?php echo esc_html( get_option( 'admin_email' ) ); ?>
			</a>
			&nbsp;
			<span class="description">Or visit <code>/?db_mailtest=any@email.com</code> while logged in as admin.</span>
		</p>

		<hr>
		<h2>Rotate the key</h2>
		<p>Generate a new SMTP key in Brevo any time, paste it here, and Save.
		   No PHP or server changes needed.</p>
	</div>
	<?php
}

/* ---- Test email: /?db_mailtest=email@example.com ---- */
add_action( 'init', function () {
	if ( empty( $_GET['db_mailtest'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$to = sanitize_email( wp_unslash( $_GET['db_mailtest'] ) );
	if ( ! is_email( $to ) ) {
		wp_die( 'Invalid email address.', 'DB Mail Test', array( 'response' => 400 ) );
	}

	if ( ! db_brevo_smtp_key() ) {
		$settings_url = esc_url( admin_url( 'options-general.php?page=db-smtp-brevo' ) );
		wp_die(
			'<strong style="color:#d63638">SMTP key not set.</strong><br><br>'
			. 'Go to <a href="' . $settings_url . '">Settings &rarr; DB SMTP (Brevo)</a>, '
			. 'enter the SMTP key, and Save first.',
			'DB Mail Test',
			array( 'response' => 200 )
		);
	}

	/* Capture any wp_mail failure via the action WP fires on error. */
	$mail_error = '';
	add_action( 'wp_mail_failed', function ( $wp_error ) use ( &$mail_error ) {
		$mail_error = $wp_error->get_error_message();
	} );

	$subject = 'Domain Brothers — Brevo SMTP test';
	$body    = '<p>This is a test email from <strong>Domain Brothers</strong> via Brevo SMTP.</p>'
	         . '<p>If you received this, your SMTP configuration is working correctly and all site emails will deliver.</p>'
	         . '<p>Sent to: ' . esc_html( $to ) . '<br>Server: ' . esc_html( DB_BREVO_HOST . ':' . DB_BREVO_PORT ) . '</p>';
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	$sent = wp_mail( $to, $subject, $body, $headers );

	$settings_url = esc_url( admin_url( 'options-general.php?page=db-smtp-brevo' ) );
	if ( $sent ) {
		wp_die(
			'<strong style="color:#1a7f37;font-size:18px">&#10003; Email sent to ' . esc_html( $to ) . '</strong><br><br>'
			. 'Check your inbox (and spam folder). '
			. 'If it arrived — Brevo SMTP is working! All receipts, offer confirmations, and admin alerts will now deliver.<br><br>'
			. '<a href="' . $settings_url . '">&larr; Back to SMTP settings</a>',
			'DB Mail Test',
			array( 'response' => 200 )
		);
	} else {
		wp_die(
			'<strong style="color:#d63638;font-size:18px">&#10007; Send failed.</strong><br><br>'
			. ( $mail_error ? 'Error: <code>' . esc_html( $mail_error ) . '</code><br><br>' : '' )
			. 'Things to check:<br>'
			. '<ul>'
			. '<li>SMTP key is correct (starts with <code>xsmtpsib-</code>)</li>'
			. '<li>Brevo account is active and the key is not revoked</li>'
			. '<li>The hosting server can reach <code>smtp-relay.brevo.com:587</code> outbound '
			.     '(some shared plans block non-standard SMTP ports — check Hostinger hPanel or contact support)</li>'
			. '</ul>'
			. '<a href="' . $settings_url . '">&larr; Back to SMTP settings</a>',
			'DB Mail Test — Failed',
			array( 'response' => 200 )
		);
	}
} );
/* === end DB SMTP routing (Brevo) === */
