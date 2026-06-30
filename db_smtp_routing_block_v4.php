<?php
/* === DB SMTP routing (SendGrid) ===
 *
 * Routes all WordPress wp_mail() through SendGrid SMTP.
 * Replaces any earlier db_smtp_routing_block_v*.php.
 *
 * WHY SENDGRID:
 *   - Free tier: 100 emails/day (more than enough for receipts, offers, alerts).
 *   - Excellent Gmail / Outlook deliverability.
 *   - No monthly fee at Domain Brothers' send volume.
 *
 * SETUP (one time, ~10 minutes):
 *   1. Sign up free at https://sendgrid.com
 *   2. Settings → Sender Authentication → Authenticate a Domain → follow the
 *      steps to add DKIM DNS records for domainbrothers.com in Hostinger hPanel.
 *      (Without authentication, emails often land in spam.)
 *   3. Settings → API Keys → Create API Key → "Restricted Access" → toggle
 *      "Mail Send" to Full Access → Create & View → copy the key (sg.xxx...).
 *   4. In functions.php, DELETE any existing
 *        /* === DB SMTP routing === * /  block (old Hostinger or Brevo version).
 *      Then paste THIS block at the end and save.
 *   5. In WP admin: Settings → DB SMTP (SendGrid) → paste the API key → Save.
 *   6. Click "Send test email" on that page. Green ✓ = all emails now deliver.
 *
 * SENDGRID SMTP SETTINGS (pre-configured — do not change):
 *   Server   : smtp.sendgrid.net
 *   Port     : 587 (STARTTLS)
 *   Username : apikey   ← literal string, not your email
 *   From     : sales@domainbrothers.com
 *
 * The API key is stored as a WordPress option — never in this file.
 * Rotate it in SendGrid → API Keys without touching PHP.
 *
 * FALLBACK (Hostinger SMTP — if you prefer to avoid a new account):
 *   Change DB_SG_HOST to 'smtp.hostinger.com', DB_SG_PORT to 465,
 *   DB_SG_USER to 'sales@domainbrothers.com', DB_SG_ENCRYPTION to 'ssl',
 *   and enter the sales@domainbrothers.com email password as the "API key".
 */

if ( ! defined( 'DB_SG_HOST' ) )       define( 'DB_SG_HOST',       'smtp.sendgrid.net' );
if ( ! defined( 'DB_SG_PORT' ) )       define( 'DB_SG_PORT',       587 );
if ( ! defined( 'DB_SG_USER' ) )       define( 'DB_SG_USER',       'apikey' ); // literal string for SendGrid
if ( ! defined( 'DB_SG_ENCRYPTION' ) ) define( 'DB_SG_ENCRYPTION', 'tls' );   // STARTTLS on 587
if ( ! defined( 'DB_SG_FROM' ) )       define( 'DB_SG_FROM',       'sales@domainbrothers.com' );
if ( ! defined( 'DB_SG_NAME' ) )       define( 'DB_SG_NAME',       'Domain Brothers' );

/* Return the stored API key / password, or '' if not yet configured. */
function db_sg_api_key() {
	return (string) get_option( 'db_sg_api_key', '' );
}

/* ---- Configure PHPMailer on every wp_mail() call ---- */
add_action( 'phpmailer_init', function ( $phpmailer ) {
	$key = db_sg_api_key();
	if ( ! $key ) {
		return; // key not saved yet — falls back to PHP mail()
	}
	$phpmailer->isSMTP();
	$phpmailer->SMTPAuth   = true;
	$phpmailer->SMTPSecure = DB_SG_ENCRYPTION;
	$phpmailer->Host       = DB_SG_HOST;
	$phpmailer->Port       = DB_SG_PORT;
	$phpmailer->Username   = DB_SG_USER;
	$phpmailer->Password   = $key;
	$phpmailer->From       = DB_SG_FROM;
	$phpmailer->FromName   = DB_SG_NAME;
	$phpmailer->SMTPOptions = array(
		'ssl' => array(
			'verify_peer'       => true,
			'verify_peer_name'  => true,
			'allow_self_signed' => false,
		),
	);
} );

/* Lock the From address so plugins can't override it. */
add_filter( 'wp_mail_from',      function () { return DB_SG_FROM; } );
add_filter( 'wp_mail_from_name', function () { return DB_SG_NAME; } );

/* ---- WP admin settings page: Settings → DB SMTP (SendGrid) ---- */
add_action( 'admin_menu', function () {
	add_options_page(
		'DB SMTP (SendGrid)',
		'DB SMTP (SendGrid)',
		'manage_options',
		'db-smtp-sendgrid',
		'db_sg_settings_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'db_sg_settings', 'db_sg_api_key', array(
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'default'           => '',
	) );
} );

function db_sg_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$key_saved = '' !== db_sg_api_key();
	$test_url  = esc_url( add_query_arg( 'db_mailtest', get_option( 'admin_email' ), home_url( '/' ) ) );
	?>
	<div class="wrap">
		<h1>DB SMTP — SendGrid</h1>
		<p>All WordPress emails (<code>wp_mail()</code>) are routed through SendGrid.
		   Enter your SendGrid API key below, save, then send a test.</p>

		<table class="form-table widefat striped" style="max-width:620px;margin-bottom:24px">
			<tr><th>SMTP Server</th> <td><code><?php echo esc_html( DB_SG_HOST ); ?></code></td></tr>
			<tr><th>Port</th>        <td><code><?php echo esc_html( DB_SG_PORT ); ?></code> — STARTTLS</td></tr>
			<tr><th>Username</th>    <td><code><?php echo esc_html( DB_SG_USER ); ?></code></td></tr>
			<tr><th>From</th>        <td><code><?php echo esc_html( DB_SG_FROM ); ?></code> / <?php echo esc_html( DB_SG_NAME ); ?></td></tr>
			<tr>
				<th>Status</th>
				<td><?php if ( $key_saved ) : ?>
					<span style="color:#1a7f37;font-weight:600">&#10003; API key saved — SendGrid routing is active.</span>
				<?php else : ?>
					<span style="color:#d63638;font-weight:600">&#9888; API key not set. Emails are NOT delivering until you save a key below.</span>
				<?php endif; ?></td>
			</tr>
		</table>

		<form method="post" action="options.php">
			<?php settings_fields( 'db_sg_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="db_sg_api_key">SendGrid API Key</label></th>
					<td>
						<input type="password" id="db_sg_api_key" name="db_sg_api_key"
							value="<?php echo esc_attr( db_sg_api_key() ); ?>"
							class="regular-text" autocomplete="new-password" style="width:440px">
						<p class="description">
							Starts with <code>SG.</code> — create one at
							<strong>SendGrid → Settings → API Keys → Create API Key</strong>
							(Mail Send → Full Access).
						</p>
					</td>
				</tr>
			</table>
			<?php submit_button( 'Save API Key' ); ?>
		</form>

		<hr>
		<h2>Domain Authentication (important)</h2>
		<p>Without domain authentication, emails may land in spam. Set it up once:</p>
		<ol>
			<li>In SendGrid: <strong>Settings → Sender Authentication → Authenticate a Domain</strong></li>
			<li>Enter <code>domainbrothers.com</code> and follow the steps.</li>
			<li>SendGrid gives you 2–3 DNS records (CNAME). Add them in <strong>Hostinger hPanel → DNS Zone</strong>.</li>
			<li>Click Verify in SendGrid. Done — inbox rates improve immediately.</li>
		</ol>

		<hr>
		<h2>Test</h2>
		<p>After saving the API key, confirm delivery works:</p>
		<p>
			<a class="button button-secondary" href="<?php echo $test_url; ?>">
				Send test to <?php echo esc_html( get_option( 'admin_email' ) ); ?>
			</a>
			&nbsp;
			<span class="description">Or visit <code>/?db_mailtest=any@email.com</code> while logged in as admin.</span>
		</p>

		<hr>
		<h2>Rotate the key</h2>
		<p>Go to SendGrid → API Keys, revoke the old key, create a new one, paste it above, Save.
		   No PHP changes needed.</p>
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

	$settings_url = esc_url( admin_url( 'options-general.php?page=db-smtp-sendgrid' ) );

	if ( ! db_sg_api_key() ) {
		wp_die(
			'<strong style="color:#d63638">API key not set.</strong><br><br>'
			. 'Go to <a href="' . $settings_url . '">Settings → DB SMTP (SendGrid)</a> and enter the key first.',
			'DB Mail Test',
			array( 'response' => 200 )
		);
	}

	$mail_error = '';
	add_action( 'wp_mail_failed', function ( $wp_error ) use ( &$mail_error ) {
		$mail_error = $wp_error->get_error_message();
	} );

	$subject = 'Domain Brothers — SendGrid SMTP test';
	$body    = '<p>This is a test email from <strong>Domain Brothers</strong> via SendGrid SMTP.</p>'
	         . '<p>If you received this, your SMTP configuration is working correctly and all site emails will now deliver.</p>'
	         . '<p>Sent to: ' . esc_html( $to ) . '<br>Server: ' . esc_html( DB_SG_HOST . ':' . DB_SG_PORT ) . '</p>';
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );

	$sent = wp_mail( $to, $subject, $body, $headers );

	if ( $sent ) {
		wp_die(
			'<strong style="color:#1a7f37;font-size:18px">&#10003; Email sent to ' . esc_html( $to ) . '</strong><br><br>'
			. 'Check your inbox (and spam). If it arrived — SendGrid is working. '
			. 'All receipts, offer confirmations, and admin alerts will now deliver.<br><br>'
			. '<a href="' . $settings_url . '">&larr; Back to SMTP settings</a>',
			'DB Mail Test',
			array( 'response' => 200 )
		);
	} else {
		wp_die(
			'<strong style="color:#d63638;font-size:18px">&#10007; Send failed.</strong><br><br>'
			. ( $mail_error ? 'Error: <code>' . esc_html( $mail_error ) . '</code><br><br>' : '' )
			. 'Check:<ul>'
			. '<li>API key starts with <code>SG.</code> and has Mail Send → Full Access permission</li>'
			. '<li>The "From" address (<code>sales@domainbrothers.com</code>) is verified in SendGrid as a Sender</li>'
			. '<li>Domain authentication is set up (or at least a single Sender Identity is verified)</li>'
			. '</ul>'
			. '<a href="' . $settings_url . '">&larr; Back to SMTP settings</a>',
			'DB Mail Test — Failed',
			array( 'response' => 200 )
		);
	}
} );
/* === end DB SMTP routing (SendGrid) === */
