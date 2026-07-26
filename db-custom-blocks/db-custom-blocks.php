<?php
/**
 * Plugin Name: Domain Brothers Custom Blocks
 * Plugin URI:  https://beta.domainbrothers.com
 * Description: All Domain Brothers custom functionality — Stripe checkout & webhooks, CRM lead management, branded email system, thank-you flows, SMTP routing, offer flow, payment plans, modern UI, AEO/SEO, performance hardening, honeypot anti-spam, dynamic meta, and service pages.
 * Version:     3.9.4
 * Author:      Domain Brothers
 * License:     Proprietary
 * Text Domain: db-blocks
 * Requires at least: 6.2
 * Tested up to: 7.1
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * DOUBLE-LOAD GUARD
 * If the old blocks are still pasted inside functions.php, this constant
 * prevents duplicate function definitions and double-registered hooks.
 * Safe to leave in functions.php indefinitely — this constant wins because
 * plugins load before the active theme.
 */
if ( defined( 'DB_BLOCKS_LOADED' ) ) {
	return;
}
define( 'DB_BLOCKS_LOADED', '3.9.4' );

if ( ! function_exists( 'db_brand_logo_url' ) ) {
	/**
	 * The Domain Brothers logo URL, resolved once for the hero, the nav
	 * drawer and the page curtain.
	 *
	 * The three of them each hardcoded the same uploads path built from
	 * home_url(), which is the wrong base for an uploads file — it breaks
	 * whenever WP_CONTENT_URL or UPLOADS is customized, WordPress lives in a
	 * subdirectory, or uploads are offloaded — and it meant a single media
	 * re-upload would silently break two of the three. Prefers the theme's
	 * configured custom logo, then the known uploads file via the real
	 * uploads base URL.
	 */
	function db_brand_logo_url() {
		$id = get_theme_mod( 'custom_logo' );
		if ( $id ) {
			$src = wp_get_attachment_image_url( $id, 'full' );
			if ( $src ) {
				return $src;
			}
		}
		$upload = wp_upload_dir();
		if ( empty( $upload['error'] ) && ! empty( $upload['baseurl'] ) ) {
			return $upload['baseurl'] . '/2017/03/Domain-Brothers.png';
		}
		return home_url( '/wp-content/uploads/2017/03/Domain-Brothers.png' );
	}
}

if ( ! function_exists( 'db_seo_other_plugin' ) ) {
	/**
	 * True when a dedicated SEO plugin owns <head> meta output. Our AEO,
	 * dynamic-meta and canonical blocks defer to it to avoid duplicate
	 * title/description/OG/canonical tags. Covers RankMath AND Yoast — the
	 * site currently runs Yoast, and the blocks previously only checked
	 * RankMath, so they were double-emitting meta alongside Yoast.
	 */
	function db_seo_other_plugin() {
		return class_exists( 'RankMath' )
			|| defined( 'RANK_MATH_VERSION' )
			|| defined( 'WPSEO_VERSION' )
			|| class_exists( 'WPSEO_Options' );
	}
}

/*
 * Keep the homepage HTML out of the LiteSpeed / Hostinger page cache.
 * The front page's domain-card layout is built by inline enhancer JS that
 * ships inside the HTML and changes with each release; a cached page made
 * visitors run stale scripts (old "Make an offer" cards, etc.). The
 * external CSS/JS assets are content-hashed and still cache normally —
 * only the small HTML document is kept fresh.
 */
add_action( 'send_headers', function () {
	if ( is_admin() || ! is_front_page() ) {
		return;
	}
	if ( headers_sent() ) {
		return;
	}
	header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
	header( 'X-LiteSpeed-Cache-Control: no-cache' ); // LiteSpeed/Hostinger opt-out
} );


/* ============================================================
   BLOCK 1 — DB SMTP routing (SendGrid)
   ============================================================ */

if ( ! defined( 'DB_SG_HOST' ) )       define( 'DB_SG_HOST',       'smtp.sendgrid.net' );
if ( ! defined( 'DB_SG_PORT' ) )       define( 'DB_SG_PORT',       587 );
if ( ! defined( 'DB_SG_USER' ) )       define( 'DB_SG_USER',       'apikey' );
if ( ! defined( 'DB_SG_ENCRYPTION' ) ) define( 'DB_SG_ENCRYPTION', 'tls' );
if ( ! defined( 'DB_SG_FROM' ) )       define( 'DB_SG_FROM',       'sales@domainbrothers.com' );
if ( ! defined( 'DB_SG_NAME' ) )       define( 'DB_SG_NAME',       'Domain Brothers' );

if ( ! function_exists( 'db_sg_api_key' ) ) {
	function db_sg_api_key() {
		return (string) get_option( 'db_sg_api_key', '' );
	}
}

add_action( 'phpmailer_init', function ( $phpmailer ) {
	$key = db_sg_api_key();
	if ( ! $key ) {
		return;
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

add_filter( 'wp_mail_from',      function ( $email ) { return db_sg_api_key() ? DB_SG_FROM : $email; } );
add_filter( 'wp_mail_from_name', function ( $name )  { return db_sg_api_key() ? DB_SG_NAME : $name; } );

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

if ( ! function_exists( 'db_sg_settings_page' ) ) {
	function db_sg_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$key_saved = '' !== db_sg_api_key();
		$test_url  = esc_url( wp_nonce_url( add_query_arg( 'db_mailtest', get_option( 'admin_email' ), home_url( '/' ) ), 'db_mailtest' ) );
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
			</p>

			<hr>
			<h2>Rotate the key</h2>
			<p>Go to SendGrid → API Keys, revoke the old key, create a new one, paste it above, Save.
			   No PHP changes needed.</p>
		</div>
		<?php
	}
}

add_action( 'init', function () {
	if ( empty( $_GET['db_mailtest'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'db_mailtest' ) ) {
		$confirm = wp_nonce_url( add_query_arg( 'db_mailtest', sanitize_email( wp_unslash( $_GET['db_mailtest'] ) ), home_url( '/' ) ), 'db_mailtest' );
		wp_die( 'Send a test email? <a href="' . esc_url( $confirm ) . '">Confirm</a>', 'DB Mail Test', array( 'response' => 200 ) );
	}
	$to = sanitize_email( wp_unslash( $_GET['db_mailtest'] ) );
	if ( ! is_email( $to ) ) {
		wp_die( 'Invalid email address.', 'DB Mail Test', array( 'response' => 400 ) );
	}
	if ( ! db_sg_api_key() ) {
		wp_die( '<strong style="color:#d63638">API key not set.</strong><br>Go to Settings → DB SMTP (SendGrid) and enter the key first.', 'DB Mail Test', array( 'response' => 200 ) );
	}
	$mail_error = '';
	add_action( 'wp_mail_failed', function ( $wp_error ) use ( &$mail_error ) {
		$mail_error = $wp_error->get_error_message();
	} );
	$sent = wp_mail( $to, 'Domain Brothers — SendGrid SMTP test',
		'<p>Test email from <strong>Domain Brothers</strong> via SendGrid SMTP.</p><p>If you received this, SMTP is working.</p>',
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
	if ( $sent ) {
		wp_die( '<strong style="color:#1a7f37">&#10003; Email sent to ' . esc_html( $to ) . '</strong><br><br><a href="' . esc_url( admin_url( 'options-general.php?page=db-smtp-sendgrid' ) ) . '">&larr; Back to SMTP settings</a>', 'DB Mail Test', array( 'response' => 200 ) );
	} else {
		wp_die( '<strong style="color:#d63638">&#10007; Send failed.</strong><br><br>' . ( $mail_error ? 'Error: <code>' . esc_html( $mail_error ) . '</code><br>' : '' ) . 'Check the API key has Mail Send → Full Access permission.', 'DB Mail Test — Failed', array( 'response' => 200 ) );
	}
} );


/* ============================================================
   BLOCK 2 — DB homepage redesign
   ============================================================ */

if ( ! defined( 'DB_HERO_BROWSE_URL' ) )    define( 'DB_HERO_BROWSE_URL',    '' );
if ( ! defined( 'DB_HERO_OFFER_URL' ) )     define( 'DB_HERO_OFFER_URL',     '/contact/' );
if ( ! defined( 'DB_HERO_HIDE_EXISTING' ) ) define( 'DB_HERO_HIDE_EXISTING', false );
if ( ! defined( 'DB_HERO_LOGO_URL' ) )      define( 'DB_HERO_LOGO_URL',      '' );
// 'buffer' by default: DomainFolio's homepage uses a custom frontpage.php
// template that runs neither the_content nor wp_body_open, so the hero is
// spliced in via front-page output buffering (see the dispatch below).
// Override to 'the_content' or 'wp_body_open' for themes that support them.
if ( ! defined( 'DB_HERO_USE_HOOK' ) )      define( 'DB_HERO_USE_HOOK',      'buffer' );

if ( ! function_exists( 'db_hp_hero_html' ) ) {
	function db_hp_hero_html() {
		$browse_href = DB_HERO_BROWSE_URL ? esc_url( DB_HERO_BROWSE_URL ) : '#db-below-hero';
		$offer_href  = esc_url( home_url( DB_HERO_OFFER_URL ) );
		$trust_items = array( 'Escrow-Protected Transfers', '0% Interest Payment Plans', '27+ Years Combined Experience', '500+ Domains Sold' );
		$trust_html  = '';
		foreach ( $trust_items as $i => $item ) {
			if ( $i > 0 ) {
				$trust_html .= '<span class="db-hp-tdot" aria-hidden="true"></span>';
			}
			$trust_html .= '<span class="db-hp-titem">' . esc_html( $item ) . '</span>';
		}
		$logo_url = DB_HERO_LOGO_URL ? DB_HERO_LOGO_URL : db_brand_logo_url();

		// Burger menu links (no WP nav menu is registered on this theme).
		$menu = array(
			'Browse Domains' => $browse_href,
			'Payment Plans'  => esc_url( home_url( '/payment-plan-setup/' ) ),
			'Services'       => esc_url( home_url( '/website-design-development/' ) ),
			'News'           => esc_url( home_url( '/news/' ) ),
			'Contact'        => $offer_href,
		);
		$menu_html = '';
		foreach ( $menu as $label => $href ) {
			$menu_html .= '<a href="' . $href . '">' . esc_html( $label ) . '</a>';
		}

		// The site-wide mobile-nav block (db-mobilenav-block.php) provides the
		// hamburger on all pages, so the hero shows its own burger only if
		// that block isn't handling it.
		$show_burger = ! ( defined( 'DB_HERO_HIDE_BURGER' ) && DB_HERO_HIDE_BURGER );

		$html  = '<section class="db-hp-hero" aria-label="Domain Brothers">';
		// Brand bar at the top of the hero: logo left, burger right.
		$html .= '<div class="db-hp-nav">';
		$html .= '<a class="db-hp-logo" href="' . esc_url( home_url( '/' ) ) . '" aria-label="Domain Brothers home">';
		$html .= '<img src="' . esc_url( $logo_url ) . '" alt="Domain Brothers" width="200" height="64" decoding="async">';
		$html .= '</a>';
		if ( $show_burger ) {
			$html .= '<button type="button" class="db-hp-burger" aria-label="Open menu" aria-expanded="false" aria-controls="db-hp-menu">';
			$html .= '<span></span><span></span><span></span></button>';
		}
		$html .= '</div>';
		// Slide-down menu panel (only when the hero owns the burger).
		if ( $show_burger ) {
			$html .= '<nav id="db-hp-menu" class="db-hp-menu" aria-label="Primary" hidden>' . $menu_html . '</nav>';
		}
		$html .= '<div class="db-hp-inner">';
		$html .= '<p class="db-hp-eyebrow">Premium Domain Marketplace</p>';
		$html .= '<h1 class="db-hp-h1">The right domain<br>changes everything.</h1>';
		$html .= '<p class="db-hp-sub">Own the domain that defines your brand. With 27+ years of combined experience, every purchase is escrow-protected and eligible for 0% interest payment plans.</p>';
		$html .= '<div class="db-hp-ctas">';
		$html .= '<a href="' . $browse_href . '" class="db-hp-btn-primary db-hp-browse-btn">Browse Premium Domains</a>';
		$html .= '</div>';
		$html .= '<div class="db-hp-trust">' . $trust_html . '</div>';
		$html .= '</div></section>';
		$html .= '<span id="db-below-hero" aria-hidden="true"></span>';
		return $html;
	}
}

if ( ! isset( $GLOBALS['db_hero_rendered'] ) ) {
	$GLOBALS['db_hero_rendered'] = false;
}

if ( 'the_content' === DB_HERO_USE_HOOK ) {
	add_filter( 'the_content', function ( $content ) {
		static $done = false;
		if ( $done || $GLOBALS['db_hero_rendered'] || ! is_front_page() || is_admin() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$done = true;
		$GLOBALS['db_hero_rendered'] = true;
		return db_hp_hero_html() . $content;
	} );
} elseif ( 'buffer' === DB_HERO_USE_HOOK ) {
	// DomainFolio's custom frontpage.php calls neither the_content nor
	// wp_body_open, so the only reliable injection point is to buffer the
	// whole front-page response and splice the hero in right after the
	// opening <body> tag. Scoped to the front page only; if no <body> is
	// found the original output is returned untouched.
	add_action( 'template_redirect', function () {
		if ( is_admin() || ! is_front_page() || is_feed() || is_robots() ) {
			return;
		}
		ob_start( function ( $html ) {
			if ( $GLOBALS['db_hero_rendered'] || false === stripos( $html, '<body' ) ) {
				return $html;
			}
			$GLOBALS['db_hero_rendered'] = true;
			return preg_replace(
				'/(<body[^>]*>)/i',
				'$1' . db_hp_hero_html(),
				$html,
				1
			);
		} );
	}, 1 );
} else {
	add_action( 'wp_body_open', function () {
		if ( ! is_front_page() || is_admin() || $GLOBALS['db_hero_rendered'] ) {
			return;
		}
		$GLOBALS['db_hero_rendered'] = true;
		echo db_hp_hero_html();
	} );
}

if ( ! function_exists( 'db_hp_css' ) ) {
	function db_hp_css() : string {
		ob_start();
		?>
	body {
		font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
		-webkit-font-smoothing: antialiased;
		-moz-osx-font-smoothing: grayscale;
		text-rendering: optimizeLegibility;
	}
	h1, h2, h3, h4 { letter-spacing: -0.02em; }
	:focus-visible { outline: 2px solid #2563eb; outline-offset: 3px; border-radius: 4px; }
	.wp-block-button__link,
	.btn, .button:not(.db-plan-cta):not(.db-hp-btn-primary):not(.db-hp-btn-ghost),
	input[type="submit"], button[type="submit"] {
		border-radius: 12px !important;
		transition: transform 0.18s ease, box-shadow 0.18s ease !important;
	}
	.wp-block-button__link:hover, .btn:hover,
	input[type="submit"]:hover, button[type="submit"]:hover {
		transform: translateY(-1px);
		box-shadow: 0 1px 2px rgba(8,23,58,.10), 0 8px 24px rgba(8,23,58,.16);
	}
	.db-hp-hero {
		position: relative; width: 100vw; left: 50%; right: 50%;
		margin-left: -50vw; margin-right: -50vw; margin-top: 0; margin-bottom: 48px;
		background: linear-gradient(150deg, #0a1628 0%, #08173a 42%, #132b52 100%);
		overflow: hidden;
		padding: 0 24px clamp(64px, 10vw, 124px);
		color: #f5f7fb; box-sizing: border-box;
	}
	.db-hp-nav {
		position: relative; z-index: 3; max-width: 1140px; margin: 0 auto;
		display: flex; align-items: center; justify-content: space-between;
		padding: 20px 0 clamp(40px, 8vw, 92px);
	}
	.db-hp-logo { display: inline-flex; align-items: center; text-decoration: none; }
	.db-hp-logo img {
		height: clamp(40px, 8vw, 52px); width: auto; max-width: 220px;
		display: block;
	}
	/* Burger */
	.db-hp-burger {
		display: inline-flex; flex-direction: column; justify-content: center; gap: 5px;
		width: 46px; height: 46px; padding: 0 11px; cursor: pointer;
		background: rgba(255,255,255,0.06); border: 1px solid rgba(157,199,251,0.28);
		border-radius: 12px; transition: background 0.18s ease, border-color 0.18s ease;
	}
	.db-hp-burger:hover { background: rgba(79,156,249,0.14); border-color: rgba(157,199,251,0.5); }
	.db-hp-burger span {
		display: block; height: 2px; width: 100%; background: #f5f7fb; border-radius: 2px;
		transition: transform 0.22s ease, opacity 0.22s ease;
	}
	.db-hp-burger[aria-expanded="true"] span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
	.db-hp-burger[aria-expanded="true"] span:nth-child(2) { opacity: 0; }
	.db-hp-burger[aria-expanded="true"] span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
	/* Slide-down menu */
	.db-hp-menu {
		position: relative; z-index: 3; max-width: 1140px; margin: -28px auto 0;
		display: flex; flex-direction: column; gap: 2px;
		background: rgba(8,23,58,0.72); border: 1px solid rgba(157,199,251,0.2);
		border-radius: 14px; padding: 10px; overflow: hidden;
		backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
	}
	.db-hp-menu[hidden] { display: none; }
	.db-hp-menu a {
		color: #e9f0fb !important; text-decoration: none; font-size: 16px; font-weight: 600;
		padding: 13px 16px; border-radius: 9px; transition: background 0.16s ease, color 0.16s ease;
	}
	.db-hp-menu a:hover { background: rgba(79,156,249,0.16); color: #ffffff !important; }
	.db-hp-sell {
		margin: 26px 0 0; font-size: 13.5px;
		animation: db-fade-up 0.6s 0.5s ease both;
	}
	.db-hp-sell a {
		color: rgba(245,247,251,0.60) !important; text-decoration: none;
		border-bottom: 1px solid rgba(245,247,251,0.22); padding-bottom: 1px;
		transition: color 0.18s ease, border-color 0.18s ease;
	}
	.db-hp-sell a:hover { color: rgba(245,247,251,0.92) !important; border-color: rgba(245,247,251,0.5); }
	.db-hp-hero::before {
		content: ''; position: absolute; top: -14%; left: 50%;
		width: 92vw; height: 92vw; max-width: 1040px; max-height: 1040px;
		transform: translateX(-50%);
		background:
			radial-gradient(ellipse at 50% 38%, rgba(79,156,249,0.26) 0%, rgba(37,99,235,0.12) 42%, transparent 70%);
		filter: blur(2px);
		pointer-events: none;
	}
	.db-hp-hero::after {
		content: ''; position: absolute; inset: 0;
		background-image: radial-gradient(rgba(255,255,255,.05) 1px, transparent 1px);
		background-size: 32px 32px;
		-webkit-mask-image: radial-gradient(ellipse at 50% 30%, #000 30%, transparent 78%);
		mask-image: radial-gradient(ellipse at 50% 30%, #000 30%, transparent 78%);
		pointer-events: none;
	}
	.db-hp-inner { position: relative; z-index: 1; max-width: 860px; margin: 0 auto; text-align: center; }
	.db-hp-eyebrow {
		display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: 0.16em;
		text-transform: uppercase; color: #9dc7fb !important; margin: 0 0 28px; padding: 7px 18px;
		border: 1px solid rgba(79,156,249,0.38); border-radius: 999px;
		background: rgba(79,156,249,0.08);
		animation: db-fade-up 0.6s ease both;
	}
	.db-hp-h1 {
		font-size: clamp(40px, 7vw, 80px); font-weight: 700; line-height: 1.04;
		letter-spacing: -0.04em; color: #f5f7fb !important; margin: 0 0 24px;
		text-wrap: balance;
		animation: db-fade-up 0.6s 0.10s ease both;
	}
	.db-hp-sub {
		font-size: clamp(16px, 2vw, 20px); line-height: 1.65; color: rgba(245,247,251,0.86) !important;
		max-width: 660px; margin: 0 auto 44px; font-weight: 400;
		animation: db-fade-up 0.6s 0.20s ease both;
	}
	.db-hp-ctas {
		display: flex; flex-wrap: wrap; gap: 14px; justify-content: center;
		margin-bottom: 56px; animation: db-fade-up 0.6s 0.30s ease both;
	}
	.db-hp-btn-primary {
		display: inline-block; padding: 16px 36px; background: #f5f7fb; color: #0a1628 !important;
		font-size: 16px; font-weight: 600; text-decoration: none !important; border-radius: 999px;
		box-shadow: 0 1px 2px rgba(0,0,0,.18), 0 8px 24px rgba(0,0,0,.24);
		transition: background 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
	}
	.db-hp-btn-primary:hover { background: #ffffff; color: #0a1628 !important; transform: translateY(-2px); box-shadow: 0 2px 4px rgba(0,0,0,.20), 0 14px 36px rgba(0,0,0,.34); }
	.db-hp-btn-ghost {
		display: inline-block; padding: 16px 36px; background: transparent; color: #f5f7fb !important;
		font-size: 16px; font-weight: 600; text-decoration: none !important;
		border: 1.5px solid rgba(157,199,251,0.45); border-radius: 999px;
		transition: border-color 0.18s ease, background 0.18s ease, transform 0.18s ease;
	}
	.db-hp-btn-ghost:hover { border-color: rgba(157,199,251,0.90); background: rgba(79,156,249,.12); color: #ffffff !important; transform: translateY(-2px); }
	.db-hp-trust {
		display: flex; flex-wrap: wrap; align-items: center; justify-content: center;
		font-size: 13px; font-weight: 500; color: rgba(245,247,251,0.72) !important; letter-spacing: 0.025em;
		animation: db-fade-up 0.6s 0.42s ease both;
	}
	.db-hp-titem { padding: 4px 14px; white-space: nowrap; }
	.db-hp-tdot { display: inline-block; width: 3px; height: 3px; border-radius: 50%; background: rgba(79,156,249,0.55); vertical-align: middle; }
	@keyframes db-fade-up { from { opacity: 0; transform: translateY(22px); } to { opacity: 1; transform: translateY(0); } }
	@media (prefers-reduced-motion: reduce) {
		.db-hp-eyebrow, .db-hp-h1, .db-hp-sub, .db-hp-ctas, .db-hp-trust, .db-hp-sell { animation: none; }
		.db-hp-btn-primary:hover, .db-hp-btn-ghost:hover { transform: none; }
	}
	@media (max-width: 580px) {
		.db-hp-hero { margin-bottom: 32px; }
		.db-hp-ctas { flex-direction: column; align-items: center; gap: 12px; margin-bottom: 44px; }
		.db-hp-btn-primary, .db-hp-btn-ghost { width: 100%; max-width: 340px; text-align: center; }
		.db-hp-tdot { display: none; }
		.db-hp-trust { display: grid; grid-template-columns: repeat(2, auto); justify-content: center; column-gap: 8px; row-gap: 6px; }
		.db-hp-titem { padding: 2px 6px; font-size: 12px; }
	}
		<?php
		return (string) ob_get_clean();
	}
}

add_action( 'wp_enqueue_scripts', function () {
	// Hero CSS only exists to style front-page markup — shipping it on
	// every other page was dead weight on 95%+ of views.
	if ( ! is_front_page() ) {
		return;
	}
	if ( ! db_external_style( 'hero', db_hp_css() ) ) {
		add_action( 'wp_head', function () {
			echo '<style id="db-hp-styles">' . db_hp_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}, 5 );
	}
} );

add_action( 'wp_footer', function () {
	if ( ! is_front_page() ) { return; }
	?>
	<script>
	(function(){
		var btn = document.querySelector('.db-hp-browse-btn');
		if(!btn || btn.getAttribute('href') !== '#db-below-hero') return;
		btn.addEventListener('click', function(e){
			e.preventDefault();
			var t = document.getElementById('db-below-hero');
			if(!t) return;
			window.scrollTo({ top: t.getBoundingClientRect().top + window.pageYOffset - 20, behavior: 'smooth' });
		});
	})();
	</script>
	<?php
}, 99 );


/* ============================================================
   BLOCK 3 — DB live news RSS feed
   ============================================================ */

if ( ! defined( 'DB_NEWS_TTL' ) ) {
	define( 'DB_NEWS_TTL', 6 * HOUR_IN_SECONDS );
}

if ( ! function_exists( 'db_news_feeds' ) ) {
	function db_news_feeds() {
		return array(
			'https://domainnamewire.com/feed/',
			'https://domaininvesting.com/feed/',
			'https://www.dnjournal.com/rss/rss.xml',
		);
	}
}

add_filter( 'wp_feed_cache_transient_lifetime', function ( $seconds, $url = '' ) {
	return in_array( $url, db_news_feeds(), true ) ? DB_NEWS_TTL : $seconds;
}, 10, 2 );

add_action( 'init', function () {
	if ( empty( $_GET['db_news_refresh'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'db_news_refresh' ) ) {
		$confirm = wp_nonce_url( add_query_arg( 'db_news_refresh', '1', home_url( '/' ) ), 'db_news_refresh' );
		wp_die( 'Clear the cached news feed? <a href="' . esc_url( $confirm ) . '">Confirm refresh</a>', 'DB News', array( 'response' => 200 ) );
	}
	$cleared = 0;
	foreach ( db_news_feeds() as $feed_url ) {
		$hash = md5( $feed_url );
		delete_transient( 'feed_' . $hash );
		delete_transient( 'feed_mod_' . $hash );
		$cleared++;
	}
	wp_die( 'DB news cache cleared (' . (int) $cleared . ' feed(s)). <a href="' . esc_url( home_url( '/news/' ) ) . '">View /news/</a>', 'DB News', array( 'response' => 200 ) );
} );

if ( ! function_exists( 'db_render_news' ) ) {
	function db_render_news( $limit = 12 ) {
		if ( ! function_exists( 'fetch_feed' ) ) {
			include_once ABSPATH . WPINC . '/feed.php';
		}
		$items = array();
		foreach ( db_news_feeds() as $url ) {
			$feed = fetch_feed( $url );
			if ( is_wp_error( $feed ) ) { continue; }
			$source     = $feed->get_title();
			$max        = $feed->get_item_quantity( $limit );
			$feed_items = $feed->get_items( 0, $max );
			if ( ! is_array( $feed_items ) ) { continue; }
			foreach ( $feed_items as $item ) {
				$ts      = $item->get_date( 'U' );
				$items[] = array(
					'title'   => $item->get_title(),
					'link'    => $item->get_permalink(),
					'date'    => $ts ? (int) $ts : 0,
					'source'  => $source,
					'excerpt' => wp_trim_words( html_entity_decode( wp_strip_all_tags( $item->get_description() ), ENT_QUOTES, 'UTF-8' ), 32, "\xE2\x80\xA6" ),
				);
			}
			if ( count( $items ) >= $limit ) { break; }
		}
		if ( empty( $items ) ) {
			return '<p class="db-news-empty">Industry news is temporarily unavailable. Please check back shortly.</p>';
		}
		usort( $items, function ( $a, $b ) { return $b['date'] <=> $a['date']; } );
		$items = array_slice( $items, 0, $limit );
		$html  = '<div class="db-news-grid">';
		foreach ( $items as $it ) {
			$date_str = $it['date'] ? date_i18n( get_option( 'date_format' ), $it['date'] ) : '';
			$html .= '<article class="db-news-card">';
			$html .= '<a class="db-news-title" href="' . esc_url( $it['link'] ) . '" target="_blank" rel="noopener nofollow">' . esc_html( $it['title'] ) . '</a>';
			$html .= '<div class="db-news-meta">';
			if ( $it['source'] ) { $html .= '<span class="db-news-source">' . esc_html( $it['source'] ) . '</span>'; }
			if ( $date_str )     { $html .= '<span class="db-news-date">' . esc_html( $date_str ) . '</span>'; }
			$html .= '</div>';
			if ( $it['excerpt'] ) { $html .= '<p class="db-news-excerpt">' . esc_html( $it['excerpt'] ) . '</p>'; }
			$html .= '<a class="db-news-readmore" href="' . esc_url( $it['link'] ) . '" target="_blank" rel="noopener nofollow">Read more &rarr;</a>';
			$html .= '</article>';
		}
		$html .= '</div>';
		$html .= '<style>.db-news-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:24px;margin:24px 0;}.db-news-card{border:1px solid #e6e6e6;border-radius:10px;padding:20px;background:#fff;display:flex;flex-direction:column;}.db-news-title{font-size:18px;font-weight:600;line-height:1.35;text-decoration:none;color:#111;}.db-news-title:hover{text-decoration:underline;}.db-news-meta{display:flex;gap:10px;flex-wrap:wrap;font-size:12px;color:#888;margin:8px 0 10px;}.db-news-excerpt{font-size:14px;color:#444;line-height:1.5;flex:1;margin:0 0 14px;}.db-news-readmore{font-size:14px;font-weight:600;text-decoration:none;color:#0a6ed1;}.db-news-empty{padding:24px;color:#666;}</style>';
		return $html;
	}
}

add_shortcode( 'db_news', function ( $atts ) {
	$atts = shortcode_atts( array( 'limit' => 12 ), $atts, 'db_news' );
	return db_render_news( (int) $atts['limit'] );
} );

add_filter( 'the_content', function ( $content ) {
	static $done = false;
	if ( $done || ! is_page( 'news' ) || is_admin() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( has_shortcode( $content, 'db_news' ) || false !== strpos( $content, 'db-news-grid' ) ) {
		return $content;
	}
	$done = true;
	return $content . db_render_news( 12 );
} );


/* ============================================================
   BLOCK 4 — DB services navigation menu
   ============================================================ */

if ( ! defined( 'DB_SERVICES_MENU_LOCATIONS' ) ) {
	define( 'DB_SERVICES_MENU_LOCATIONS', 'primary,main-menu,header-menu,main,nav,navigation,top-menu,header-nav,menu-1' );
}

if ( ! function_exists( 'db_services_list' ) ) {
	function db_services_list() {
		return array(
			'Website Design & Development' => '/website-design-development/',
			'Digital Marketing'            => '/digital-marketing/',
			'Software Development'         => '/software-development/',
			'Mobile App Development'       => '/mobile-app-development/',
			'Other Services'               => '/other-services/',
		);
	}
}

add_filter( 'wp_nav_menu_items', function ( $items, $args ) {
	$allowed = array_map( 'trim', explode( ',', DB_SERVICES_MENU_LOCATIONS ) );
	if ( empty( $args->theme_location ) || ! in_array( $args->theme_location, $allowed, true ) ) {
		return $items;
	}
	if ( false !== strpos( $items, 'db-svc-parent' ) ) {
		return $items;
	}
	$children = '';
	foreach ( db_services_list() as $label => $path ) {
		$children .= '<li class="menu-item db-svc-child"><a href="' . esc_url( home_url( $path ) ) . '">' . esc_html( $label ) . '</a></li>';
	}
	return $items . '
<li class="menu-item menu-item-has-children db-svc-parent">
	<a href="#" class="db-svc-toggle" aria-haspopup="true" aria-expanded="false">Services<span class="db-svc-arrow" aria-hidden="true"></span></a>
	<ul class="sub-menu db-svc-dropdown" role="menu">' . $children . '</ul>
</li>';
}, 10, 2 );

add_action( 'wp_head', function () {
	?>
	<style id="db-svc-menu-css">
	.db-svc-parent { position: relative; list-style: none; }
	.db-svc-arrow { display: inline-block; width: 0; height: 0; margin-left: 5px; vertical-align: middle; border-left: 4px solid transparent; border-right: 4px solid transparent; border-top: 5px solid currentColor; transition: transform 0.2s ease; }
	.db-svc-dropdown { list-style: none !important; margin: 0 !important; padding: 6px 0 !important; position: absolute; top: calc(100% + 8px); left: 50%; transform: translateX(-50%) translateY(6px); min-width: 244px; background: #ffffff; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,.06), 0 14px 32px rgba(0,0,0,.11); border: 1px solid rgba(0,0,0,.07); opacity: 0; visibility: hidden; pointer-events: none; transition: opacity 0.18s ease, transform 0.18s ease, visibility 0s 0.18s; z-index: 99999; }
	.db-svc-parent:hover > .db-svc-dropdown, .db-svc-parent:focus-within > .db-svc-dropdown { opacity: 1; visibility: visible; pointer-events: auto; transform: translateX(-50%) translateY(0); transition-delay: 0s; }
	.db-svc-parent:hover > a .db-svc-arrow, .db-svc-parent:focus-within > a .db-svc-arrow { transform: rotate(180deg); }
	.db-svc-child { list-style: none !important; }
	.db-svc-child a { display: block !important; padding: 10px 20px !important; font-size: 14px !important; color: #1d1d1f !important; text-decoration: none !important; white-space: nowrap; transition: background 0.12s, color 0.12s; }
	.db-svc-child a:hover, .db-svc-child a:focus { background: #eef2ff; color: #0b3d91 !important; outline: none; }
	@media (max-width: 900px) {
		.db-svc-dropdown { position: static !important; transform: none !important; box-shadow: none !important; border: none !important; border-radius: 0 !important; background: rgba(0,0,0,.04) !important; padding: 2px 0 2px 14px !important; display: none; opacity: 1; visibility: visible; pointer-events: auto; transition: none; min-width: 0; }
		.db-svc-parent.is-open > .db-svc-dropdown { display: block; }
		.db-svc-parent.is-open > a .db-svc-arrow { transform: rotate(180deg); }
		.db-svc-parent:hover > .db-svc-dropdown { display: none; }
		.db-svc-parent.is-open:hover > .db-svc-dropdown { display: block; }
	}
	</style>
	<?php
} );

add_action( 'wp_footer', function () {
	?>
	<script>
	(function(){
		'use strict';
		var toggles = document.querySelectorAll('.db-svc-toggle');
		if(!toggles.length) return;
		function isMobile(){ return window.innerWidth <= 900; }
		toggles.forEach(function(toggle){
			var parent = toggle.closest ? toggle.closest('.db-svc-parent') : toggle.parentElement;
			if(!parent) return;
			toggle.addEventListener('click', function(e){
				if(!isMobile()) return;
				e.preventDefault();
				var open = parent.classList.toggle('is-open');
				toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
			document.addEventListener('click', function(e){
				if(!isMobile()) return;
				if(parent.classList.contains('is-open') && !parent.contains(e.target)){
					parent.classList.remove('is-open');
					toggle.setAttribute('aria-expanded', 'false');
				}
			});
			document.addEventListener('keydown', function(e){
				if((e.key==='Escape'||e.keyCode===27) && parent.classList.contains('is-open')){
					parent.classList.remove('is-open');
					toggle.setAttribute('aria-expanded', 'false');
					toggle.focus();
				}
			});
		});
	})();
	</script>
	<?php
}, 99 );


/* ============================================================
   BLOCK 5 — DB payment-plan term selection (v3)
   ============================================================ */

if ( ! defined( 'DB_PLAN_PAGE_SLUG' ) )        define( 'DB_PLAN_PAGE_SLUG',        'payment-plan-setup' );
if ( ! defined( 'DB_PLAN_CURRENCY' ) )         define( 'DB_PLAN_CURRENCY',         '$' );
if ( ! defined( 'DB_PLAN_HIDE_THEME_WIDGET' ) ) define( 'DB_PLAN_HIDE_THEME_WIDGET', false );

if ( ! function_exists( 'db_plan_terms' ) ) {
	function db_plan_terms() {
		return array( 3, 6, 9, 12 );
	}
}

if ( ! function_exists( 'db_is_plan_page' ) ) {
	function db_is_plan_page() {
		return is_page( DB_PLAN_PAGE_SLUG );
	}
}

if ( ! function_exists( 'db_plan_b64' ) ) {
	function db_plan_b64( $key ) {
		if ( empty( $_GET[ $key ] ) ) { return ''; }
		$raw = base64_decode( wp_unslash( $_GET[ $key ] ), true );
		return ( false === $raw ) ? '' : $raw;
	}
}

add_filter( 'the_content', function ( $content ) {
	static $done = false;
	if ( $done || is_admin() || ! in_the_loop() || ! is_main_query() || ! db_is_plan_page() ) {
		return $content;
	}
	$domain    = sanitize_text_field( db_plan_b64( 'd' ) );
	$price_raw = db_plan_b64( 'p' );
	$price     = 0.0;
	if ( preg_match( '/[0-9]+(?:\.[0-9]+)?/', preg_replace( '/[^0-9.]/', '', $price_raw ), $m ) ) {
		$price = (float) $m[0];
	}
	if ( $price <= 0 ) { return $content; }

	$terms = db_plan_terms();
	$cur   = DB_PLAN_CURRENCY;
	$d_b64 = isset( $_GET['d'] ) ? sanitize_text_field( wp_unslash( $_GET['d'] ) ) : '';
	$p_b64 = isset( $_GET['p'] ) ? sanitize_text_field( wp_unslash( $_GET['p'] ) ) : '';

	ob_start();
	?>
	<div class="db-plan" id="db-plan"
		data-price="<?php echo esc_attr( $price ); ?>"
		data-terms="<?php echo esc_attr( implode( ',', $terms ) ); ?>"
		data-domain="<?php echo esc_attr( $domain ); ?>"
		data-d="<?php echo esc_attr( $d_b64 ); ?>"
		data-p="<?php echo esc_attr( $p_b64 ); ?>"
		data-cur="<?php echo esc_attr( $cur ); ?>"
		data-buynow="<?php echo esc_attr( home_url( '/buy-now/' ) ); ?>">

		<div class="db-plan-head">
			<h2>Choose your payment plan<?php echo $domain ? ' for <span class="db-plan-domain">' . esc_html( $domain ) . '</span>' : ''; ?></h2>
			<p class="db-plan-total">Total price: <strong><?php echo esc_html( $cur . number_format( $price, 0 ) ); ?></strong> &middot; <span class="db-plan-zero">0% interest</span></p>
		</div>
		<div class="db-plan-terms" role="tablist" aria-label="Payment terms"></div>
		<div class="db-plan-summary">
			<div class="db-plan-monthly">
				<span class="db-plan-monthly-label">Monthly payment</span>
				<span class="db-plan-monthly-amount" id="db-plan-monthly">&mdash;</span>
				<span class="db-plan-monthly-sub" id="db-plan-monthly-sub"></span>
			</div>
			<a href="#" class="db-plan-cta" id="db-plan-cta">Continue to secure checkout &rarr;</a>
		</div>
		<div class="db-plan-schedule-wrap">
			<h3>Payment schedule</h3>
			<table class="db-plan-schedule">
				<thead><tr><th>#</th><th>Date</th><th>Amount</th></tr></thead>
				<tbody id="db-plan-schedule-body"></tbody>
			</table>
		</div>
		<div class="db-plan-notes">
			<p><strong>When do I get the domain?</strong> Ownership transfers to you once the <em>final</em> payment clears. Until then the domain is held securely in escrow on your behalf.</p>
			<p><strong>Can I use it in the meantime?</strong> Yes &mdash; during the plan we can point the domain to your hosting (A record / IP) and email (MX records) so you can start using it right away, while ownership transfers at the end.</p>
		</div>
	</div>
	<style>
		.db-plan{max-width:760px;margin:32px auto;padding:0 4px;font-family:inherit;color:#1a1a1a;}
		.db-plan-head h2{font-size:clamp(20px,4vw,28px);line-height:1.25;margin:0 0 8px;font-weight:700;}
		.db-plan-domain{color:#0a6ed1;}
		.db-plan-total{font-size:15px;color:#444;margin:0 0 20px;}
		.db-plan-zero{display:inline-block;background:#e7f6ec;color:#137a3e;font-weight:600;padding:2px 10px;border-radius:999px;font-size:13px;}
		.db-plan-terms{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin:0 0 24px;}
		.db-plan-term{cursor:pointer;border:1.5px solid #e2e2e2;border-radius:12px;padding:16px 12px;text-align:center;background:#fff;transition:border-color .15s,box-shadow .15s;}
		.db-plan-term:hover{border-color:#9cc6f0;}
		.db-plan-term.is-active{border-color:#0a6ed1;box-shadow:0 0 0 3px rgba(10,110,209,.12);}
		.db-plan-term .t-months{display:block;font-size:18px;font-weight:700;}
		.db-plan-term .t-per{display:block;font-size:13px;color:#666;margin-top:4px;}
		.db-plan-summary{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;background:#f7f9fc;border:1px solid #e6ecf5;border-radius:14px;padding:20px 22px;margin:0 0 28px;}
		.db-plan-monthly{display:flex;flex-direction:column;}
		.db-plan-monthly-label{font-size:13px;color:#666;text-transform:uppercase;letter-spacing:.04em;}
		.db-plan-monthly-amount{font-size:clamp(26px,6vw,34px);font-weight:800;line-height:1.1;}
		.db-plan-monthly-sub{font-size:13px;color:#666;margin-top:2px;}
		.db-plan-cta{display:inline-block;background:#0a6ed1;color:#fff;font-weight:600;text-decoration:none;padding:14px 22px;border-radius:10px;white-space:nowrap;transition:background .15s;}
		.db-plan-cta:hover{background:#085bb0;color:#fff;}
		.db-plan-schedule-wrap h3{font-size:17px;margin:0 0 10px;}
		.db-plan-schedule{width:100%;border-collapse:collapse;font-size:14px;}
		.db-plan-schedule th,.db-plan-schedule td{text-align:left;padding:10px 12px;border-bottom:1px solid #eee;}
		.db-plan-schedule th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#888;}
		.db-plan-schedule td:last-child,.db-plan-schedule th:last-child{text-align:right;}
		.db-plan-notes{margin-top:24px;font-size:14px;color:#444;line-height:1.55;}
		.db-plan-notes p{margin:0 0 10px;}
		@media(max-width:560px){.db-plan-summary{flex-direction:column;align-items:stretch;}.db-plan-cta{text-align:center;}}
	</style>
	<script>
	(function(){
		var root = document.getElementById('db-plan');
		if(!root) return;
		var price  = parseFloat(root.getAttribute('data-price')) || 0;
		var terms  = (root.getAttribute('data-terms')||'').split(',').map(function(n){return parseInt(n,10);}).filter(Boolean);
		var cur    = root.getAttribute('data-cur') || '$';
		var d      = root.getAttribute('data-d') || '';
		var p      = root.getAttribute('data-p') || '';
		var buynow = root.getAttribute('data-buynow') || '/buy-now/';
		var active = terms[0];
		function money(n){ return cur+n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
		function installments(total,months){
			var monthly=Math.round((total/months)*100)/100,arr=[],sum=0;
			for(var i=0;i<months;i++){
				if(i===months-1){arr.push(Math.round((total-sum)*100)/100);}
				else{arr.push(monthly);sum+=monthly;}
			}
			return arr;
		}
		function addMonths(date,m){
			var dt=new Date(date.getFullYear(),date.getMonth()+m,1);
			var last=new Date(dt.getFullYear(),dt.getMonth()+1,0).getDate();
			dt.setDate(Math.min(date.getDate(),last));
			return dt;
		}
		function fmtDate(dt){ return dt.toLocaleDateString(undefined,{year:'numeric',month:'short',day:'numeric'}); }
		function renderTerms(){
			var wrap=root.querySelector('.db-plan-terms');
			wrap.innerHTML='';
			terms.forEach(function(m){
				var per=installments(price,m)[0];
				var b=document.createElement('button');
				b.type='button';
				b.className='db-plan-term'+(m===active?' is-active':'');
				b.setAttribute('role','tab');
				b.setAttribute('aria-selected',m===active?'true':'false');
				b.innerHTML='<span class="t-months">'+m+' months</span><span class="t-per">'+money(per)+'/mo</span>';
				b.addEventListener('click',function(){active=m;renderTerms();renderSummary();});
				wrap.appendChild(b);
			});
		}
		function renderSummary(){
			var arr=installments(price,active);
			document.getElementById('db-plan-monthly').textContent=money(arr[0]);
			document.getElementById('db-plan-monthly-sub').textContent=active+' payments, 0% interest';
			var body=document.getElementById('db-plan-schedule-body');
			body.innerHTML='';
			var today=new Date();
			arr.forEach(function(amt,i){
				var tr=document.createElement('tr');
				var dt=i===0?today:addMonths(today,i);
				tr.innerHTML='<td>'+(i+1)+'</td><td>'+fmtDate(dt)+(i===0?' (today)':'')+'</td><td>'+money(amt)+'</td>';
				body.appendChild(tr);
			});
			var cta=document.getElementById('db-plan-cta');
			/* 'months', not 'm' — m is a reserved WP query var (date archive)
			   and makes the buy-now page 404. */
			var url=buynow+'?t=plan&months='+encodeURIComponent(active);
			if(d) url+='&d='+encodeURIComponent(d);
			if(p) url+='&p='+encodeURIComponent(p);
			cta.setAttribute('href',url);
		}
		renderTerms();
		renderSummary();
	})();
	</script>
	<?php
	$widget = ob_get_clean();
	$done   = true;
	return $content . $widget;
} );

add_action( 'wp_head', function () {
	if ( DB_PLAN_HIDE_THEME_WIDGET && db_is_plan_page() ) {
		echo "<style>.theme-payment-plan,.payment-plan-default{display:none!important;}</style>\n";
	}
} );


/* ============================================================
   BLOCK 6 — DB make-an-offer flow
   ============================================================ */

if ( ! defined( 'DB_OFFER_FORM_ID' ) ) {
	define( 'DB_OFFER_FORM_ID', 0 );
}

if ( ! function_exists( 'db_offer_field_map' ) ) {
	function db_offer_field_map() {
		return array(
			'email'  => array( 'your-email', 'email', 'offer-email', 'customer-email' ),
			'name'   => array( 'your-name', 'name', 'offer-name', 'customer-name' ),
			'domain' => array( 'your-domain', 'domain', 'offer-domain', 'domain-name' ),
			'amount' => array( 'your-offer', 'offer', 'amount', 'offer-amount', 'price' ),
		);
	}
}

if ( ! function_exists( 'db_offer_value' ) ) {
	function db_offer_value( $data, $logical ) {
		$map = db_offer_field_map();
		if ( empty( $map[ $logical ] ) ) { return ''; }
		foreach ( $map[ $logical ] as $name ) {
			if ( isset( $data[ $name ] ) ) {
				$val = is_array( $data[ $name ] ) ? reset( $data[ $name ] ) : $data[ $name ];
				$val = trim( (string) $val );
				if ( '' !== $val ) { return $val; }
			}
		}
		return '';
	}
}

if ( ! function_exists( 'db_is_offer_form' ) ) {
	function db_is_offer_form( $contact_form ) {
		if ( ! is_object( $contact_form ) ) { return false; }
		if ( DB_OFFER_FORM_ID > 0 && method_exists( $contact_form, 'id' ) ) {
			return (int) $contact_form->id() === (int) DB_OFFER_FORM_ID;
		}
		$title = method_exists( $contact_form, 'title' ) ? $contact_form->title() : '';
		return ( false !== stripos( $title, 'offer' ) );
	}
}

if ( ! function_exists( 'db_offer_resolved_form_id' ) ) {
	function db_offer_resolved_form_id() {
		if ( DB_OFFER_FORM_ID > 0 ) { return (int) DB_OFFER_FORM_ID; }
		$cached = get_transient( 'db_offer_form_id' );
		if ( false !== $cached ) { return (int) $cached; }
		$form_id = 0;
		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			$forms = get_posts( array( 'post_type' => 'wpcf7_contact_form', 'posts_per_page' => 50 ) );
			foreach ( $forms as $form_post ) {
				if ( false !== stripos( $form_post->post_title, 'offer' ) ) {
					$form_id = (int) $form_post->ID;
					break;
				}
			}
		}
		set_transient( 'db_offer_form_id', $form_id, DAY_IN_SECONDS );
		return $form_id;
	}
}

add_action( 'wpcf7_mail_sent', function ( $contact_form ) {
	if ( ! db_is_offer_form( $contact_form ) ) { return; }
	$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
	if ( ! $submission ) { return; }
	$data = $submission->get_posted_data();

	$email  = db_offer_value( $data, 'email' );
	$name   = db_offer_value( $data, 'name' );
	$domain = db_offer_value( $data, 'domain' );
	$amount = db_offer_value( $data, 'amount' );

	if ( ! is_email( $email ) ) { return; }

	$rate_key = 'db_offer_conf_' . md5( strtolower( $email ) );
	if ( get_transient( $rate_key ) ) { return; }
	set_transient( $rate_key, 1, 30 * MINUTE_IN_SECONDS );

	$greeting = $name ? 'Hi ' . $name . ',' : 'Hi,';
	$dom_line = $domain ? '<strong>' . esc_html( $domain ) . '</strong>' : 'the domain';
	$amt_line = $amount ? ' of <strong>' . esc_html( $amount ) . '</strong>' : '';
	$subject  = $domain ? 'We received your offer for ' . sanitize_text_field( $domain ) : 'We received your offer';

	$body  = '<p>' . esc_html( $greeting ) . '</p>';
	$body .= '<p>Thank you for your interest in ' . $dom_line . '. We have received your offer' . $amt_line . ' and our team is reviewing it now.</p>';
	$body .= '<p>You can expect a personal reply from us shortly. If your offer is accepted, we will send secure checkout instructions to complete the purchase.</p>';
	$body .= '<p>If you have any questions in the meantime, simply reply to this email.</p>';
	$body .= '<p>&mdash; The Domain Brothers Team</p>';

	wp_mail( $email, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
}, 10, 1 );

add_action( 'wp_footer', function () {
	$form_id  = db_offer_resolved_form_id();
	$thankyou = esc_url( home_url( '/thank-you/' ) );
	$map      = db_offer_field_map();
	?>
	<script>
	(function(){
		var DB_OFFER_FORM_ID = <?php echo (int) $form_id; ?>;
		var DB_THANKYOU = <?php echo wp_json_encode( $thankyou ); ?>;
		var DOMAIN_FIELDS = <?php echo wp_json_encode( array_values( $map['domain'] ) ); ?>;
		document.addEventListener('wpcf7mailsent', function(event){
			var formId = event.detail ? parseInt(event.detail.contactFormId,10) : 0;
			if(!DB_OFFER_FORM_ID || !formId || formId !== DB_OFFER_FORM_ID){ return; }
			var domain = '';
			try {
				var inputs = (event.detail && event.detail.inputs) || [];
				for(var i=0;i<inputs.length;i++){
					if(DOMAIN_FIELDS.indexOf(inputs[i].name) !== -1 && inputs[i].value){ domain = inputs[i].value; break; }
				}
			} catch(e) {}
			var url = DB_THANKYOU + '?type=offer';
			/* 'd', not 'domain' — the domain CPT's public query var hijacks
			   ?domain= and the thank-you page never renders. */
			if(domain) url += '&d=' + encodeURIComponent(domain);
			window.location = url;
		}, false);
	})();
	</script>
	<?php
}, 99 );


/* ============================================================
   BLOCK 7 — DB SEO & sitemap
   ============================================================ */

add_filter( 'rank_math/frontend/robots', function ( $robots ) {
	$noindex_slugs = array( 'buy-now', 'thank-you', 'payment-plan-setup' );
	if ( is_page( $noindex_slugs ) ) {
		$robots['index']  = 'noindex';
		$robots['follow'] = 'nofollow';
	}
	return $robots;
} );

add_action( 'wp_head', function () {
	if ( is_admin() || db_seo_other_plugin() ) { return; }
	$noindex_slugs = array( 'buy-now', 'thank-you', 'payment-plan-setup' );
	if ( is_page( $noindex_slugs ) ) {
		echo '<meta name="robots" content="noindex, nofollow">' . "\n";
	}
}, 1 );

add_shortcode( 'db_sitemap', function ( $atts ) {
	$atts = shortcode_atts(
		array( 'exclude_slugs' => 'buy-now,thank-you,payment-plan-setup,sitemap' ),
		$atts,
		'db_sitemap'
	);
	$always_excluded = array( 'buy-now', 'thank-you', 'payment-plan-setup' );
	$excluded = array_unique( array_merge( $always_excluded, array_map( 'trim', explode( ',', $atts['exclude_slugs'] ) ) ) );

	ob_start();
	?>
	<div class="db-sitemap">
	<?php
	$pages = get_pages( array( 'post_status' => 'publish', 'sort_column' => 'menu_order', 'sort_order' => 'ASC', 'hierarchical' => false ) );
	if ( $pages ) {
		echo '<h2 class="db-sitemap-h2">Pages</h2><ul class="db-sitemap-list">';
		foreach ( $pages as $page ) {
			if ( in_array( $page->post_name, $excluded, true ) ) { continue; }
			echo '<li><a href="' . esc_url( get_permalink( $page ) ) . '">' . esc_html( get_the_title( $page ) ) . '</a></li>';
		}
		echo '</ul>';
	}
	$domains = new WP_Query( array( 'post_type' => 'domain', 'post_status' => 'publish', 'posts_per_page' => 300, 'orderby' => 'title', 'order' => 'ASC', 'no_found_rows' => true ) );
	if ( $domains->have_posts() ) {
		echo '<h2 class="db-sitemap-h2">Domain Listings</h2><ul class="db-sitemap-list db-sitemap-domains">';
		while ( $domains->have_posts() ) {
			$domains->the_post();
			echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a></li>';
		}
		wp_reset_postdata();
		echo '</ul>';
	}
	$posts = new WP_Query( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'date', 'order' => 'DESC', 'no_found_rows' => true ) );
	if ( $posts->have_posts() ) {
		echo '<h2 class="db-sitemap-h2">News &amp; Articles</h2><ul class="db-sitemap-list">';
		while ( $posts->have_posts() ) {
			$posts->the_post();
			echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( get_the_title() ) . '</a><span class="db-sitemap-date">' . esc_html( get_the_date() ) . '</span></li>';
		}
		wp_reset_postdata();
		echo '</ul>';
	}
	?>
	<style>.db-sitemap{max-width:820px;margin:0 auto;}.db-sitemap-h2{font-size:20px;font-weight:700;margin:36px 0 12px;border-bottom:2px solid #e6e6e6;padding-bottom:8px;}.db-sitemap-list{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px 24px;}.db-sitemap-list li{padding:4px 0;}.db-sitemap-list a{color:#0b3d91;text-decoration:none;font-size:15px;}.db-sitemap-list a:hover{text-decoration:underline;}.db-sitemap-date{font-size:12px;color:#888;margin-left:8px;}</style>
	</div>
	<?php
	return ob_get_clean();
} );

add_filter( 'rank_math/sitemap/entry/query_vars', function ( $vars, $post_type ) {
	if ( 'domain' === $post_type ) {
		$vars['post_type']   = 'domain';
		$vars['post_status'] = 'publish';
	}
	return $vars;
}, 10, 2 );

add_filter( 'rank_math/sitemap/exclude_empty_terms', '__return_true' );

add_filter( 'rank_math/sitemap/entry', function ( $xml, $url ) {
	$blocked = array( '/buy-now/', '/thank-you/', '/payment-plan-setup/' );
	$loc = is_array( $url ) ? ( $url['loc'] ?? '' ) : ( is_string( $url ) ? $url : '' );
	foreach ( $blocked as $slug ) {
		if ( '' !== $loc && false !== strpos( $loc, $slug ) ) { return ''; }
	}
	return $xml;
}, 10, 2 );

add_action( 'init', function () {
	if ( empty( $_GET['db_seo_setup'] ) || ! current_user_can( 'manage_options' ) ) { return; }
	if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'db_seo_setup' ) ) {
		$confirm = wp_nonce_url( add_query_arg( 'db_seo_setup', '1', home_url( '/' ) ), 'db_seo_setup' );
		wp_die( 'Apply recommended RankMath SEO settings? <a href="' . esc_url( $confirm ) . '">Confirm</a>', 'DB SEO Setup', array( 'response' => 200 ) );
	}
	$log     = array();
	$modules = get_option( 'rank_math_modules', array() );
	if ( is_array( $modules ) ) {
		if ( ! in_array( 'sitemap', $modules, true ) ) { $modules[] = 'sitemap'; update_option( 'rank_math_modules', array_values( $modules ) ); $log[] = '✓ Sitemap module: ENABLED.'; }
		else { $log[] = '✓ Sitemap module: already enabled.'; }
	}
	$sitemap_opts = get_option( 'rank_math_sitemap_options', array() );
	if ( ! is_array( $sitemap_opts ) ) { $sitemap_opts = array(); }
	$sitemap_opts = array_merge( $sitemap_opts, array( 'pt_domain_sitemap' => 'on', 'pt_post_sitemap' => 'on', 'pt_page_sitemap' => 'on', 'include_images' => '1', 'ping_search_engines' => '1' ) );
	update_option( 'rank_math_sitemap_options', $sitemap_opts );
	$log[] = '✓ Sitemap options: domain CPT included, images on, ping on.';
	if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) ) { \RankMath\Sitemap\Cache::invalidate_storage(); $log[] = '✓ Cache cleared.'; }
	elseif ( function_exists( 'rank_math_invalidate_sitemap' ) ) { rank_math_invalidate_sitemap(); $log[] = '✓ Cache cleared.'; }
	$body = '<h2>DB SEO Setup — Done</h2><ul>';
	foreach ( $log as $line ) { $body .= '<li>' . esc_html( $line ) . '</li>'; }
	$body .= '</ul><p><a href="' . esc_url( home_url( '/sitemap_index.xml' ) ) . '">View XML Sitemap</a></p><p><a href="' . esc_url( home_url( '/' ) ) . '">← Back to site</a></p>';
	wp_die( $body, 'DB SEO Setup', array( 'response' => 200 ) );
} );


/* ============================================================
   BLOCK 8 — DB performance & security hardening
   ============================================================ */

if ( ! defined( 'DB_PERF_DISABLE_JQUERY_MIGRATE' ) ) { define( 'DB_PERF_DISABLE_JQUERY_MIGRATE', false ); }
if ( ! defined( 'DB_PERF_DEFER_SCRIPTS' ) )           { define( 'DB_PERF_DEFER_SCRIPTS', '' ); }

add_action( 'init', function () {
	remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles',     'print_emoji_styles' );
	remove_action( 'admin_print_styles',  'print_emoji_styles' );
	remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
	remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
	remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
	remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	remove_action( 'wp_head', 'feed_links',       2 );
	remove_action( 'wp_head', 'feed_links_extra',  3 );
} );

if ( DB_PERF_DISABLE_JQUERY_MIGRATE ) {
	add_action( 'wp_default_scripts', function ( $scripts ) {
		if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
			$scripts->registered['jquery']->deps = array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) );
		}
	} );
}

add_action( 'wp_head', function () {
	if ( is_page( array( 'buy-now', 'payment-plan-setup' ) ) ) {
		echo '<link rel="preconnect" href="https://js.stripe.com">' . "\n";
		echo '<link rel="preconnect" href="https://api.stripe.com">' . "\n";
		echo '<link rel="dns-prefetch" href="//js.stripe.com">' . "\n";
	}
	echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}, 0 );

add_action( 'send_headers', function () {
	if ( is_admin() ) { return; }
	header( 'X-Content-Type-Options: nosniff' );
	header( 'X-Frame-Options: SAMEORIGIN' );
	header( 'X-XSS-Protection: 1; mode=block' );
	header( 'Referrer-Policy: strict-origin-when-cross-origin' );
	header( 'Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()' );
} );

add_filter( 'style_loader_src', function ( $src ) {
	if ( strpos( $src, 'fonts.googleapis.com' ) !== false && strpos( $src, 'display=' ) === false ) {
		return add_query_arg( 'display', 'swap', $src );
	}
	return $src;
}, 20 );

add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment, $size ) {
	if ( ! isset( $attr['loading'] ) ) {
		static $count = 0;
		$count++;
		$attr['loading'] = ( $count === 1 ) ? 'eager' : 'lazy';
		if ( $count === 1 ) { $attr['fetchpriority'] = 'high'; }
	}
	return $attr;
}, 10, 3 );

add_filter( 'script_loader_tag', function ( $tag, $handle ) {
	if ( is_admin() ) { return $tag; }
	$defer_handles = array_filter( array_merge(
		array( 'comment-reply', 'wp-embed' ),
		array_map( 'trim', explode( ',', DB_PERF_DEFER_SCRIPTS ) )
	) );
	if ( in_array( $handle, $defer_handles, true ) && strpos( $tag, ' defer' ) === false ) {
		$tag = str_replace( ' src=', ' defer src=', $tag );
	}
	return $tag;
}, 10, 2 );

add_filter( 'xmlrpc_enabled', '__return_false' );
add_action( 'xmlrpc_call', function () { status_header( 403 ); die( 'XML-RPC is disabled.' ); } );

add_action( 'template_redirect', function () {
	if ( ! is_admin() && isset( $_GET['author'] ) ) {
		wp_safe_redirect( home_url( '/' ), 301 );
		exit;
	}
} );

add_filter( 'rest_endpoints', function ( $endpoints ) {
	if ( ! is_user_logged_in() ) {
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
	}
	return $endpoints;
} );

add_action( 'init', function () {
	if ( function_exists( 'header_remove' ) ) { header_remove( 'X-Powered-By' ); }
} );


/* ============================================================
   BLOCK 9 — DB AEO & structured data
   ============================================================ */

if ( ! defined( 'DB_AEO_ORG_NAME' ) )       define( 'DB_AEO_ORG_NAME',       get_bloginfo( 'name' ) );
if ( ! defined( 'DB_AEO_CONTACT_EMAIL' ) )   define( 'DB_AEO_CONTACT_EMAIL',  'sales@domainbrothers.com' );
if ( ! defined( 'DB_AEO_TWITTER_HANDLE' ) )  define( 'DB_AEO_TWITTER_HANDLE', '' );
if ( ! defined( 'DB_AEO_PRICE_META' ) )      define( 'DB_AEO_PRICE_META',     'domain_price' );
if ( ! function_exists( 'db_aeo_og_image' ) ) {
	/**
	 * Site-icon lookup, done lazily on first actual use instead of at plugin
	 * parse time — the old top-level define() ran get_site_icon_url() (an
	 * attachment/meta lookup) on every single request, admin-ajax and
	 * Stripe webhook POSTs included, even when no OG tag would ever print.
	 */
	function db_aeo_og_image() {
		static $image = null;
		if ( null === $image ) {
			$image = get_site_icon_url( 512 ) ?: '';
		}
		return $image;
	}
}

if ( ! function_exists( 'db_aeo_emit' ) ) {
	function db_aeo_emit( $graph_node ) {
		$json = wp_json_encode( array_merge( array( '@context' => 'https://schema.org' ), $graph_node ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		echo '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
	}
}

if ( ! function_exists( 'db_aeo_canonical' ) ) {
	function db_aeo_canonical() {
		global $wp;
		return esc_url( home_url( add_query_arg( array(), $wp->request ) ) );
	}
}

add_action( 'wp_head', function () {
	if ( is_admin() ) { return; }
	$site_url  = esc_url( home_url( '/' ) );
	$site_name = DB_AEO_ORG_NAME;
	$logo_url  = db_aeo_og_image();

	$org = array( '@type' => 'Organization', 'name' => $site_name, 'url' => $site_url,
		'description' => 'Premium domain name brokerage offering expert acquisition, flexible payment plans, and secure escrow services.',
		'contactPoint' => array( '@type' => 'ContactPoint', 'contactType' => 'sales', 'email' => DB_AEO_CONTACT_EMAIL, 'availableLanguage' => 'English' ) );
	if ( $logo_url ) { $org['logo'] = array( '@type' => 'ImageObject', 'url' => $logo_url ); }
	db_aeo_emit( $org );

	db_aeo_emit( array( '@type' => 'WebSite', 'name' => $site_name, 'url' => $site_url,
		'potentialAction' => array( '@type' => 'SearchAction', 'target' => array( '@type' => 'EntryPoint', 'urlTemplate' => $site_url . '?s={search_term_string}' ), 'query-input' => 'required name=search_term_string' ) ) );

	if ( is_front_page() || is_page( 'faq' ) ) {
		db_aeo_emit( array( '@type' => 'FAQPage', 'mainEntity' => array(
			array( '@type' => 'Question', 'name' => 'What is domain brokerage?', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Domain brokerage is a service where an expert negotiates and acquires a premium domain name on your behalf. The broker handles outreach to the current owner, price negotiation, secure transfer, and escrow.' ) ),
			array( '@type' => 'Question', 'name' => 'How do payment plans work?', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Domain Brothers offers 0% interest instalment plans over 3, 6, 9, or 12 months. The total price is divided into equal monthly payments.' ) ),
			array( '@type' => 'Question', 'name' => 'Can I use a domain while I am still paying for it?', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Yes. During the payment plan we can point the domain\'s DNS records to your servers so you can start using the domain immediately. Legal ownership transfers to you once the final payment clears.' ) ),
			array( '@type' => 'Question', 'name' => 'How long does a domain transfer take?', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Most .com domain transfers complete within 5–7 days. Country-code domains can complete faster — sometimes within 24 hours.' ) ),
			array( '@type' => 'Question', 'name' => 'Is it safe to buy a domain through a broker?', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Yes. Domain Brothers uses secure escrow services and established registrar transfer protocols. Payments are processed through Stripe with full PCI-DSS compliance.' ) ),
			array( '@type' => 'Question', 'name' => 'What is a premium domain name?', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'A premium domain name is a short, memorable, and often generic name that was registered years ago and carries significant brand equity, direct search traffic, and SEO authority.' ) ),
			array( '@type' => 'Question', 'name' => 'What payment methods do you accept?', 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'We accept all major credit and debit cards (Visa, Mastercard, American Express) as well as Apple Pay and Google Pay through our Stripe-powered secure checkout.' ) ),
		) ) );
	}

	if ( is_singular( 'domain' ) ) {
		global $post;
		$domain_name  = get_the_title( $post );
		$domain_price = get_post_meta( $post->ID, DB_AEO_PRICE_META, true );
		$domain_url   = get_permalink( $post );
		$product      = array( '@type' => 'Product', 'name' => $domain_name,
			'description' => 'Premium domain name ' . esc_html( $domain_name ) . ' available for purchase or via flexible monthly payment plan.',
			'url' => esc_url( $domain_url ),
			'brand' => array( '@type' => 'Brand', 'name' => $site_name ) );
		if ( $domain_price && is_numeric( preg_replace( '/[^0-9.]/', '', $domain_price ) ) ) {
			$price = (float) preg_replace( '/[^0-9.]/', '', $domain_price );
			$product['offers'] = array( '@type' => 'Offer', 'price' => number_format( $price, 2, '.', '' ), 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock', 'url' => esc_url( $domain_url ), 'seller' => array( '@type' => 'Organization', 'name' => $site_name ) );
		}
		db_aeo_emit( $product );
	}

	$service_slugs = array( 'website-design-development' => 'Website Design & Development', 'digital-marketing' => 'Digital Marketing', 'software-development' => 'Software Development', 'mobile-app-development' => 'Mobile App Development', 'other-services' => 'Other Services' );
	if ( is_page( array_keys( $service_slugs ) ) ) {
		global $post;
		$slug         = $post->post_name;
		$service_name = isset( $service_slugs[ $slug ] ) ? $service_slugs[ $slug ] : get_the_title();
		db_aeo_emit( array( '@type' => 'Service', 'name' => $service_name, 'provider' => array( '@type' => 'Organization', 'name' => $site_name, 'url' => $site_url ), 'url' => esc_url( get_permalink() ), 'areaServed' => 'Worldwide', 'description' => get_the_excerpt() ) );
	}

	if ( ! is_front_page() && ( is_page() || is_singular() || is_category() ) ) {
		$items = array( array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $site_url ) );
		if ( is_singular( 'domain' ) ) {
			$items[] = array( '@type' => 'ListItem', 'position' => 2, 'name' => 'Domain Listings', 'item' => esc_url( home_url( '/domains/' ) ) );
			$items[] = array( '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title(), 'item' => esc_url( get_permalink() ) );
		} elseif ( is_page() ) {
			global $post;
			if ( $post->post_parent ) {
				$parent  = get_post( $post->post_parent );
				$items[] = array( '@type' => 'ListItem', 'position' => 2, 'name' => get_the_title( $parent ), 'item' => esc_url( get_permalink( $parent ) ) );
				$items[] = array( '@type' => 'ListItem', 'position' => 3, 'name' => get_the_title(), 'item' => esc_url( get_permalink() ) );
			} else {
				$items[] = array( '@type' => 'ListItem', 'position' => 2, 'name' => get_the_title(), 'item' => esc_url( get_permalink() ) );
			}
		}
		if ( count( $items ) > 1 ) {
			db_aeo_emit( array( '@type' => 'BreadcrumbList', 'itemListElement' => $items ) );
		}
	}
}, 5 );

add_action( 'wp_head', function () {
	if ( is_admin() || db_seo_other_plugin() ) { return; }
	$title       = wp_get_document_title();
	$description = get_bloginfo( 'description' );
	$url         = db_aeo_canonical();
	$image       = db_aeo_og_image();
	$type        = 'website';
	$site_name   = DB_AEO_ORG_NAME;
	if ( is_singular() ) {
		$type = is_singular( 'domain' ) ? 'product' : 'article';
		if ( has_post_thumbnail() ) { $img = wp_get_attachment_image_src( get_post_thumbnail_id(), 'large' ); $image = $img ? $img[0] : $image; }
		$description = has_excerpt() ? get_the_excerpt() : $description;
		$url         = esc_url( get_permalink() );
	}
	// Lets Block 12 (dynamic meta) supply a richer, price-inclusive
	// description for domain listing pages instead of duplicating this
	// whole OG/Twitter block a second time.
	$description = apply_filters( 'db_aeo_description', $description );
	echo '<meta property="og:type"        content="' . esc_attr( $type )        . '">' . "\n";
	echo '<meta property="og:site_name"   content="' . esc_attr( $site_name )   . '">' . "\n";
	echo '<meta property="og:title"       content="' . esc_attr( $title )       . '">' . "\n";
	echo '<meta property="og:description" content="' . esc_attr( $description ) . '">' . "\n";
	echo '<meta property="og:url"         content="' . esc_url( $url )          . '">' . "\n";
	if ( $image ) { echo '<meta property="og:image" content="' . esc_url( $image ) . '">' . "\n"; }
	echo '<meta name="twitter:card"        content="summary_large_image">' . "\n";
	echo '<meta name="twitter:title"       content="' . esc_attr( $title )       . '">' . "\n";
	echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '">' . "\n";
	if ( $image ) { echo '<meta name="twitter:image" content="' . esc_url( $image ) . '">' . "\n"; }
	if ( DB_AEO_TWITTER_HANDLE ) { echo '<meta name="twitter:site" content="@' . esc_attr( DB_AEO_TWITTER_HANDLE ) . '">' . "\n"; }
	echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
}, 5 );


/* ============================================================
   BLOCK 10 — DB modern UI overhaul
   ============================================================ */

add_filter( 'body_class', function ( $classes ) {
	$classes[] = 'db-ui-active';
	return $classes;
} );

if ( ! function_exists( 'db_external_style' ) ) {
	/**
	 * Serves a CSS payload as a browser-cacheable static file instead of
	 * inline <style> in every HTML response. Content-addressed filename
	 * (md5 of the CSS) means updates bust caches automatically and repeat
	 * visitors transfer the CSS once instead of on every page view —
	 * this is the single biggest PageSpeed lever in the plugin (~50 KB of
	 * inline CSS otherwise rides along with every page).
	 *
	 * @return bool False if the uploads dir is unwritable (caller should
	 *              fall back to inline output).
	 */
	function db_external_style( string $handle, string $css ) : bool {
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return false;
		}
		$dir  = $upload['basedir'] . '/db-blocks';
		$ver  = substr( md5( $css ), 0, 10 );
		$file = $dir . '/' . $handle . '-' . $ver . '.css';

		if ( ! file_exists( $file ) ) {
			if ( ! wp_mkdir_p( $dir ) || false === file_put_contents( $file, $css ) ) {
				return false;
			}
			foreach ( (array) glob( $dir . '/' . $handle . '-*.css' ) as $old ) {
				if ( $old !== $file ) {
					@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		}

		wp_enqueue_style(
			'db-' . $handle,
			$upload['baseurl'] . '/db-blocks/' . basename( $file ),
			array(),
			null // version is in the filename
		);
		return true;
	}
}

if ( ! function_exists( 'db_ui_css' ) ) {
	function db_ui_css() : string {
		ob_start();
		?>

/* ==========================================================================
   0. DESIGN TOKENS
   ========================================================================== */
:root {
	--db-navy:      #0a1628;
	--db-navy-2:    #08173a;
	--db-navy-3:    #132b52;
	--db-blue:      #2563eb;
	--db-accent:    #4f9cf9;
	--db-blue-dk:   #1d4fd7;
	--db-blue-lt:   #e8f0fd;
	--db-green:     #137a3e;
	--db-red:       #c0392b;

	--db-gray-50:   #f8fafc;
	--db-gray-100:  #f1f5f9;
	--db-gray-200:  #e2e8f0;
	--db-gray-300:  #cbd5e1;
	--db-gray-500:  #64748b;
	--db-gray-700:  #334155;
	--db-gray-900:  #0f172a;

	--db-surface:      #ffffff;
	--db-header-bg:    rgba(255,255,255,.82);
	--db-focus-ring:   rgba(37,99,235,.22);

	--db-font:      -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Inter', 'Segoe UI', Roboto, sans-serif;

	--db-fs-h1:   clamp(2rem, 5vw, 3.2rem);
	--db-fs-h2:   clamp(1.5rem, 3.5vw, 2.25rem);
	--db-fs-h3:   clamp(1.2rem, 2.4vw, 1.55rem);
	--db-fs-body: 1rem;
	--db-fs-sm:   0.875rem;

	--db-sp-1:  4px;  --db-sp-2:  8px;  --db-sp-3:  12px; --db-sp-4:  16px;
	--db-sp-6:  24px; --db-sp-8:  32px; --db-sp-12: 48px; --db-sp-16: 64px;

	--db-r-sm:   8px;
	--db-r-md:   12px;
	--db-r-lg:   16px;
	--db-r-pill: 999px;

	--db-shadow-sm: 0 1px 2px rgba(8,23,58,.06), 0 2px 6px rgba(8,23,58,.05);
	--db-shadow-md: 0 1px 2px rgba(8,23,58,.08), 0 8px 24px rgba(8,23,58,.10);
	--db-shadow-lg: 0 2px 4px rgba(8,23,58,.08), 0 16px 40px rgba(8,23,58,.16);

	--db-ease:      ease;
	--db-dur-fast:  120ms;
	--db-dur-base:  180ms;
	--db-dur-slow:  320ms;

	--db-grad-navy: linear-gradient(180deg, #0a1628 0%, #132b52 100%);

	--db-max-content: 1200px;
	--db-max-prose:    700px;
}
@media (prefers-color-scheme: dark) {
	:root {
		--db-gray-50:  #0e1524;
		--db-gray-100: #16203a;
		--db-gray-200: #22304f;
		--db-gray-300: #33456b;
		--db-gray-500: #8fa0bd;
		--db-gray-700: #c7d2e4;
		--db-gray-900: #eef2f9;
		--db-blue:     #4f9cf9;
		--db-accent:   #7ab6fb;
		--db-blue-dk:  #6faaf9;
		--db-blue-lt:  #10254a;
		--db-surface:  #101b33;
		--db-header-bg: rgba(10,22,40,.82);
		--db-focus-ring: rgba(79,156,249,.28);
		--db-shadow-sm: 0 1px 2px rgba(0,0,0,.35), 0 2px 6px rgba(0,0,0,.28);
		--db-shadow-md: 0 1px 2px rgba(0,0,0,.40), 0 8px 24px rgba(0,0,0,.38);
		--db-shadow-lg: 0 2px 4px rgba(0,0,0,.42), 0 16px 40px rgba(0,0,0,.50);
		--db-grad-navy: linear-gradient(180deg, #132b52 0%, #1c3a6b 100%);
	}
}
@media (prefers-reduced-motion: reduce) {
	*, *::before, *::after { transition-duration: .01ms !important; animation-duration: .01ms !important; scroll-behavior: auto !important; }
}

/* ==========================================================================
   1. GLOBAL RESET + BASE
   ========================================================================== */
.db-ui-active {
	font-family: var(--db-font);
	font-size: var(--db-fs-body);
	color: var(--db-gray-900);
	line-height: 1.65;
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
	text-rendering: optimizeLegibility;
}
.db-ui-active *, .db-ui-active *::before, .db-ui-active *::after { box-sizing: border-box; }
.db-ui-active img { max-width: 100%; height: auto; display: block; }
.db-ui-active a { color: var(--db-blue); transition: color var(--db-dur-base) var(--db-ease); }
.db-ui-active a:hover { color: var(--db-blue-dk); }
:focus-visible { outline: 2px solid var(--db-blue); outline-offset: 3px; border-radius: var(--db-r-sm); }
.db-ui-active ::selection { background: var(--db-blue-lt); color: var(--db-gray-900); }

/* ==========================================================================
   2. TYPOGRAPHY
   ========================================================================== */
.db-ui-active h1, .db-ui-active h2, .db-ui-active h3,
.db-ui-active h4, .db-ui-active h5, .db-ui-active h6 {
	font-family: var(--db-font);
	font-weight: 700;
	line-height: 1.18;
	letter-spacing: -0.028em;
	color: var(--db-gray-900);
	margin: 0 0 var(--db-sp-4);
	text-wrap: balance;
}
.db-ui-active h1 { font-size: var(--db-fs-h1); }
.db-ui-active h2 { font-size: var(--db-fs-h2); letter-spacing: -0.024em; }
.db-ui-active h3 { font-size: var(--db-fs-h3); letter-spacing: -0.018em; line-height: 1.3; }
.db-ui-active p  { margin: 0 0 var(--db-sp-4); color: var(--db-gray-700); line-height: 1.65; }
.db-ui-active .entry-content > p,
.db-ui-active .entry-content > ul,
.db-ui-active .entry-content > ol { max-width: var(--db-max-prose); }
.db-ui-active li { margin-bottom: var(--db-sp-2); color: var(--db-gray-700); }

/* ==========================================================================
   3. BUTTONS
   ========================================================================== */
.db-ui-active .btn-primary,
.db-ui-active .button:not(.db-hp-btn-primary):not(.db-hp-btn-ghost),
.db-ui-active input[type="submit"],
.db-ui-active button[type="submit"] {
	display: inline-flex; align-items: center; justify-content: center;
	gap: var(--db-sp-2);
	padding: 12px 26px;
	background: var(--db-grad-navy);
	color: #fff !important;
	font-family: var(--db-font); font-size: 15px; font-weight: 600;
	letter-spacing: 0.01em;
	text-decoration: none !important;
	border: none; border-radius: var(--db-r-md); cursor: pointer;
	box-shadow: var(--db-shadow-sm), inset 0 1px 0 rgba(255,255,255,.08);
	transition: background var(--db-dur-base) var(--db-ease),
	            transform var(--db-dur-base) var(--db-ease),
	            box-shadow var(--db-dur-base) var(--db-ease);
}
.db-ui-active .btn-primary:hover,
.db-ui-active .button:not(.db-hp-btn-primary):not(.db-hp-btn-ghost):hover,
.db-ui-active input[type="submit"]:hover,
.db-ui-active button[type="submit"]:hover {
	background: var(--db-blue); transform: translateY(-1px); box-shadow: var(--db-shadow-md);
}
.db-ui-active .btn-primary:active,
.db-ui-active input[type="submit"]:active,
.db-ui-active button[type="submit"]:active {
	transform: translateY(0); box-shadow: var(--db-shadow-sm);
}
.db-ui-active .btn-secondary {
	display: inline-flex; align-items: center; justify-content: center;
	gap: var(--db-sp-2);
	padding: 11px 26px;
	background: transparent;
	color: var(--db-navy) !important;
	font-family: var(--db-font); font-size: 15px; font-weight: 600;
	text-decoration: none !important;
	border: 1.5px solid var(--db-gray-300); border-radius: var(--db-r-pill); cursor: pointer;
	transition: border-color var(--db-dur-base) var(--db-ease),
	            background var(--db-dur-base) var(--db-ease),
	            color var(--db-dur-base) var(--db-ease);
}
.db-ui-active .btn-secondary:hover {
	border-color: var(--db-blue); background: var(--db-blue-lt); color: var(--db-blue) !important;
}
@media (prefers-color-scheme: dark) {
	.db-ui-active .btn-secondary { color: var(--db-gray-700) !important; }
	.db-ui-active .btn-secondary:hover { color: var(--db-accent) !important; }
}

/* ==========================================================================
   4. DOMAIN CARDS
   ========================================================================== */
.db-ui-active .domain-listing,
.db-ui-active .domain-card,
.db-ui-active .listing-item {
	background: var(--db-surface);
	border: 1px solid var(--db-gray-200);
	border-radius: var(--db-r-lg);
	box-shadow: var(--db-shadow-sm);
	overflow: hidden;
	transition: transform var(--db-dur-base) var(--db-ease),
	            box-shadow var(--db-dur-base) var(--db-ease),
	            border-color var(--db-dur-base) var(--db-ease);
}
.db-ui-active .domain-listing:hover,
.db-ui-active .domain-card:hover,
.db-ui-active .listing-item:hover {
	transform: translateY(-3px);
	box-shadow: var(--db-shadow-lg);
	border-color: rgba(37,99,235,.35);
}
@media (prefers-reduced-motion: reduce) {
	.db-ui-active .domain-listing:hover,
	.db-ui-active .domain-card:hover,
	.db-ui-active .listing-item:hover { transform: none; }
}

/* Domain name — the hero of every card */
.db-ui-active .domain-listing h2, .db-ui-active .domain-listing h3,
.db-ui-active .domain-card h2,    .db-ui-active .domain-card h3,
.db-ui-active .listing-item h2,   .db-ui-active .listing-item h3,
.db-ui-active .domain-name,
.db-ui-active .domain-title {
	font-size: clamp(1.15rem, 2vw, 1.4rem);
	font-weight: 700;
	letter-spacing: -0.02em;
	color: var(--db-gray-900);
	margin-bottom: var(--db-sp-2);
	word-break: break-word;
}

/* Price — bold navy badge with blue accent */
.db-ui-active .domain-price,
.db-ui-active .price,
.db-ui-active .listing-price {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 5px 14px;
	background: var(--db-grad-navy);
	color: #fff;
	font-size: 1em; font-weight: 700;
	letter-spacing: -0.01em;
	border-radius: var(--db-r-pill);
	box-shadow: inset 0 0 0 1px rgba(79,156,249,.40), var(--db-shadow-sm);
}
.db-ui-active .domain-price::before,
.db-ui-active .price::before,
.db-ui-active .listing-price::before {
	content: ''; width: 6px; height: 6px; border-radius: 50%;
	background: var(--db-accent); flex: none;
}

/* Buy / Offer buttons on listing cards */
.db-ui-active .buy-btn,
.db-ui-active .btn-buy,
.db-ui-active .add-to-cart {
	background: var(--db-grad-navy); color: #fff !important;
	border: none; border-radius: var(--db-r-md);
	padding: 10px 20px; font-size: 14px; font-weight: 600;
	text-decoration: none !important; cursor: pointer;
	box-shadow: var(--db-shadow-sm);
	transition: background var(--db-dur-base) var(--db-ease),
	            box-shadow var(--db-dur-base) var(--db-ease);
}
.db-ui-active .buy-btn:hover,
.db-ui-active .btn-buy:hover,
.db-ui-active .add-to-cart:hover { background: var(--db-blue); box-shadow: var(--db-shadow-md); }

.db-ui-active .offer-btn {
	background: transparent; color: var(--db-blue) !important;
	border: 1.5px solid var(--db-blue); border-radius: var(--db-r-pill);
	padding: 9px 20px; font-size: 14px; font-weight: 600;
	text-decoration: none !important; cursor: pointer;
	transition: background var(--db-dur-base) var(--db-ease), color var(--db-dur-base) var(--db-ease);
}
.db-ui-active .offer-btn:hover { background: var(--db-blue-lt); }

/* ==========================================================================
   5. NAVIGATION
   ========================================================================== */
/* NOT sticky: DomainFolio's header stacks logo + toggles + search into a
   ~250px-tall block. Pinning that to the top overlaid every section
   heading on scroll (with the blur showing content bleeding through).
   A normal-flow header that scrolls away is the correct behavior for
   this theme. */
.db-ui-active .site-header,
.db-ui-active #masthead {
	position: static;
	background: var(--db-surface);
	border-bottom: 1px solid var(--db-gray-200);
}
.db-ui-active .main-navigation a,
.db-ui-active .nav-menu a,
.db-ui-active header nav a {
	font-size: 14px; font-weight: 500;
	color: var(--db-gray-700) !important;
	text-decoration: none;
	padding: 6px 12px;
	border-radius: var(--db-r-sm);
	transition: background var(--db-dur-base) var(--db-ease), color var(--db-dur-base) var(--db-ease);
}
.db-ui-active .main-navigation a:hover,
.db-ui-active .nav-menu a:hover,
.db-ui-active header nav a:hover {
	background: var(--db-blue-lt);
	color: var(--db-blue) !important;
}

/* ==========================================================================
   6. CF7 FORMS
   ========================================================================== */
.db-ui-active .wpcf7-form input[type="text"],
.db-ui-active .wpcf7-form input[type="email"],
.db-ui-active .wpcf7-form input[type="tel"],
.db-ui-active .wpcf7-form input[type="number"],
.db-ui-active .wpcf7-form input[type="url"],
.db-ui-active .wpcf7-form textarea,
.db-ui-active .wpcf7-form select {
	width: 100%;
	padding: 13px 16px;
	font-family: var(--db-font); font-size: 15px;
	color: var(--db-gray-900);
	background: var(--db-surface);
	border: 1.5px solid var(--db-gray-300);
	border-radius: 12px;
	outline: none;
	box-shadow: var(--db-shadow-sm);
	transition: border-color var(--db-dur-base) var(--db-ease), box-shadow var(--db-dur-base) var(--db-ease);
}
.db-ui-active .wpcf7-form ::placeholder { color: var(--db-gray-500); opacity: 1; }
.db-ui-active .wpcf7-form input:focus,
.db-ui-active .wpcf7-form textarea:focus,
.db-ui-active .wpcf7-form select:focus {
	border-color: var(--db-blue);
	box-shadow: 0 0 0 4px var(--db-focus-ring), var(--db-shadow-sm);
}
.db-ui-active .wpcf7-form label {
	font-size: var(--db-fs-sm); font-weight: 600; color: var(--db-gray-700);
}
.db-ui-active .wpcf7-form input[type="submit"] {
	background: var(--db-grad-navy); color: #fff; border: none;
	padding: 14px 30px; font-size: 15px; font-weight: 600;
	border-radius: 12px; cursor: pointer;
	box-shadow: var(--db-shadow-sm), inset 0 1px 0 rgba(255,255,255,.08);
	transition: background var(--db-dur-base) var(--db-ease), transform var(--db-dur-base) var(--db-ease), box-shadow var(--db-dur-base) var(--db-ease);
}
.db-ui-active .wpcf7-form input[type="submit"]:hover { background: var(--db-blue); transform: translateY(-1px); box-shadow: var(--db-shadow-md); }
.db-ui-active .wpcf7-not-valid-tip { color: var(--db-red); font-size: 13px; margin-top: 4px; }
.db-ui-active .wpcf7-response-output { border-radius: 12px; padding: 12px 16px; margin-top: 16px; border-width: 1px !important; }

/* ==========================================================================
   7. FOOTER
   ========================================================================== */
.db-ui-active footer,
.db-ui-active .site-footer,
.db-ui-active #colophon {
	background: #0a1628;
	color: rgba(233,240,251,.78);
	border-top: 1px solid rgba(79,156,249,.18);
}
.db-ui-active footer a,
.db-ui-active .site-footer a,
.db-ui-active #colophon a {
	color: rgba(233,240,251,.85);
	text-decoration: none;
	text-underline-offset: 3px;
	transition: color var(--db-dur-base) var(--db-ease);
}
.db-ui-active footer a:hover,
.db-ui-active .site-footer a:hover,
.db-ui-active #colophon a:hover { color: #7ab6fb; text-decoration: underline; }
.db-ui-active footer h2, .db-ui-active footer h3, .db-ui-active footer h4,
.db-ui-active .site-footer h2, .db-ui-active .site-footer h3, .db-ui-active .site-footer h4 {
	color: #ffffff;
}

/* ==========================================================================
   8. LAYOUT
   ========================================================================== */
.db-ui-active .site-content,
.db-ui-active .entry-content,
.db-ui-active .page-content {
	max-width: var(--db-max-content);
	margin-left: auto;
	margin-right: auto;
	padding-left: var(--db-sp-6);
	padding-right: var(--db-sp-6);
}

/* ==========================================================================
   9. TABLES + PAGINATION
   ========================================================================== */
.db-ui-active table {
	width: 100%; border-collapse: separate; border-spacing: 0; font-size: 14px;
	border: 1px solid var(--db-gray-200); border-radius: var(--db-r-md);
	overflow: hidden;
	box-shadow: var(--db-shadow-sm);
	background: var(--db-surface);
}
.db-ui-active th, .db-ui-active td {
	text-align: left; padding: 12px 16px; border-bottom: 1px solid var(--db-gray-200);
}
.db-ui-active th {
	font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em;
	color: var(--db-gray-500); background: var(--db-gray-50);
}
.db-ui-active tr:last-child td { border-bottom: none; }
.db-ui-active tbody tr { transition: background var(--db-dur-base) var(--db-ease); }
.db-ui-active tr:hover td { background: var(--db-gray-50); }

.db-ui-active .pagination,
.db-ui-active .nav-links,
.db-ui-active .page-numbers-wrap {
	display: flex; flex-wrap: wrap; align-items: center; justify-content: center;
	gap: var(--db-sp-2); margin: var(--db-sp-8) 0;
}
.db-ui-active a.page-numbers,
.db-ui-active span.page-numbers,
.db-ui-active .pagination a,
.db-ui-active .pagination span {
	display: inline-flex; align-items: center; justify-content: center;
	min-width: 38px; height: 38px; padding: 0 12px;
	font-size: 14px; font-weight: 600;
	color: var(--db-gray-700);
	background: var(--db-surface);
	border: 1px solid var(--db-gray-200);
	border-radius: var(--db-r-md);
	text-decoration: none;
	transition: border-color var(--db-dur-base) var(--db-ease),
	            color var(--db-dur-base) var(--db-ease),
	            background var(--db-dur-base) var(--db-ease);
}
.db-ui-active a.page-numbers:hover,
.db-ui-active .pagination a:hover {
	border-color: var(--db-blue); color: var(--db-blue); background: var(--db-blue-lt);
}
.db-ui-active span.page-numbers.current,
.db-ui-active .pagination .current {
	background: var(--db-grad-navy); border-color: transparent; color: #fff;
	box-shadow: var(--db-shadow-sm);
}

/* ==========================================================================
   10. THEME POLISH — DomainFolio markup quirks
   (logo size, header search, price-table links, heading/View-All spacing)
   ========================================================================== */

/* Header logo: theme ships it unconstrained and it fills the viewport on
   mobile. Constrain any image inside the site header/branding area. */
.db-ui-active .site-header img,
.db-ui-active header img,
.db-ui-active .site-branding img,
.db-ui-active .custom-logo {
	max-height: 52px; width: auto; height: auto;
}

/* Header search: consistent pill treatment for the theme's search forms. */
.db-ui-active header form input[type="text"],
.db-ui-active header form input[type="search"],
.db-ui-active form.search-form input[type="text"],
.db-ui-active form.search-form input[type="search"] {
	height: 44px; padding: 0 16px;
	border: 1px solid var(--db-gray-200); border-radius: var(--db-r-md);
	font-size: 15px;
}
.db-ui-active header form input[type="submit"],
.db-ui-active header form button[type="submit"],
.db-ui-active form.search-form input[type="submit"] {
	height: 44px; padding: 0 22px;
	background: var(--db-grad-navy); color: #fff;
	border: none; border-radius: var(--db-r-md);
	font-size: 15px; font-weight: 600; cursor: pointer;
}

/* "Featured Domains" + inline "View All": give the crammed inline link
   breathing room and a quieter treatment than the heading. */
.db-ui-active .entry-content h1 > a,
.db-ui-active .entry-content h2 > a,
.db-ui-active .entry-content h3 > a,
.db-ui-active h2 + a,
.db-ui-active a.db-view-all {
	margin-left: 14px; font-size: 14px; font-weight: 600;
	color: var(--db-blue) !important; text-decoration: none; white-space: nowrap;
	vertical-align: middle;
}

/* Domain price-table actions: tagged by the enhancer JS below. */
.db-ui-active a.db-act-buy {
	display: inline-flex; align-items: center; justify-content: center;
	padding: 8px 16px; margin-left: 10px;
	background: var(--db-grad-navy); color: #fff !important;
	border-radius: 999px; font-size: 13.5px; font-weight: 700;
	text-decoration: none !important; white-space: nowrap;
	box-shadow: var(--db-shadow-sm);
	transition: transform var(--db-dur-base) var(--db-ease), box-shadow var(--db-dur-base) var(--db-ease);
}
.db-ui-active a.db-act-buy:hover { transform: translateY(-1px); box-shadow: var(--db-shadow-md); }
.db-ui-active a.db-act-offer {
	display: inline-flex; align-items: center; justify-content: center;
	padding: 7px 15px;
	border: 1.5px solid var(--db-blue); color: var(--db-blue) !important;
	border-radius: 999px; font-size: 13.5px; font-weight: 700;
	text-decoration: none !important; white-space: nowrap;
	transition: background var(--db-dur-base) var(--db-ease), color var(--db-dur-base) var(--db-ease);
}
.db-ui-active a.db-act-offer:hover { background: var(--db-blue); color: #fff !important; }
.db-ui-active td .db-price-strong { font-weight: 800; color: var(--db-navy); font-variant-numeric: tabular-nums; }

/* Front page only: hide the theme's duplicate logo (the hero already
   carries the brand). Keep the header search usable. */
.home.db-ui-active .custom-logo-link,
.home.db-ui-active .site-header .custom-logo { display: none !important; }

/* ==========================================================================
   10b. BELOW-HERO — section rhythm + domain listing cards + decor
   ========================================================================== */

/* Soft blue canvas on EVERY front-end page (home, About, Contact, all CMS
   pages, search, domain listing/single, buy, offer). This is purely a
   background-color change — no layout/structure is touched elsewhere. */
.db-ui-active #primary,
.db-ui-active .site-content,
.db-ui-active .content-area,
.db-ui-active #main,
.db-ui-active .site-main {
	background: linear-gradient(180deg, #f4f8fe 0%, #eef4fc 100%);
}
/* Section headings */
.db-ui-active h2 {
	color: var(--db-navy); letter-spacing: -0.02em;
}

/* ── CMS / content pages: centered white "sheet" on the blue canvas ──────────
   Applies to regular pages (About, Contact, service pages, FAQs, Our Team,
   policies) — NOT the homepage (own design) and NOT functional pages
   (search / domain / buy / offer) which the owner asked to keep as color-only. */
body.page:not(.home) .db-ui-active #main.site-main,
body.page:not(.home).db-ui-active #main.site-main {
	max-width: 900px;
	margin: clamp(28px, 5vw, 56px) auto;
	background: #ffffff;
	border: 1px solid #e4ecf7;
	border-radius: 18px;
	padding: clamp(26px, 5vw, 60px);
	box-shadow: 0 1px 2px rgba(8,23,58,.05), 0 14px 44px rgba(8,23,58,.07);
}
body.page:not(.home) .entry-title {
	margin-top: 0; margin-bottom: 18px;
	font-size: clamp(28px, 4.5vw, 44px); color: var(--db-navy);
	letter-spacing: -0.03em; text-wrap: balance;
}
/* Comfortable prose inside the sheet */
body.page:not(.home) .entry-content { max-width: 100%; font-size: 16px; }
body.page:not(.home) .entry-content > h2 { margin: 38px 0 14px; padding-top: 6px; }
body.page:not(.home) .entry-content > h3 { margin: 26px 0 8px; color: var(--db-navy); }
body.page:not(.home) .entry-content p { margin: 0 0 16px; line-height: 1.7; color: #334155; }
body.page:not(.home) .entry-content ul li,
body.page:not(.home) .entry-content ol li { margin: 6px 0; line-height: 1.65; color: #334155; }
body.page:not(.home) .entry-content a { color: var(--db-blue); }
body.page:not(.home) .entry-content img { border-radius: 12px; }
/* Numbered "How We Work" style steps read as a subtle divided list */
body.page:not(.home) .entry-content > h3 + p { margin-bottom: 20px; }

/* Domain listing card grid (built from the theme's tables by the enhancer) */
.db-domain-grid {
	display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
	gap: 16px; margin: 22px 0 8px;
}
.db-domain-card {
	position: relative; display: flex; flex-direction: column; gap: 14px;
	background: #ffffff; border: 1px solid #e4ecf7; border-radius: 16px;
	padding: 20px 20px 18px;
	box-shadow: 0 1px 2px rgba(8,23,58,.05), 0 10px 30px rgba(8,23,58,.06);
	transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
	overflow: hidden;
}
.db-domain-card::before {
	content: ""; position: absolute; inset: 0 0 auto 0; height: 4px;
	background: linear-gradient(90deg, #2563eb, #4f9cf9);
	opacity: 0; transition: opacity 0.18s ease;
}
.db-domain-card:hover { transform: translateY(-4px); box-shadow: 0 6px 14px rgba(8,23,58,.10), 0 20px 44px rgba(8,23,58,.14); border-color: #cfe0f7; }
.db-domain-card:hover::before { opacity: 1; }
.db-domain-card__name {
	font-size: 19px; font-weight: 700; color: var(--db-navy); word-break: break-word;
	text-decoration: none; line-height: 1.25;
}
.db-domain-card__name:hover { color: var(--db-blue); }
.db-domain-card__row { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: auto; }
.db-domain-card__price {
	font-size: 20px; font-weight: 800; color: var(--db-navy); font-variant-numeric: tabular-nums;
}
.db-domain-card__price--offer { font-size: 14px; font-weight: 600; color: #64748b; }
.db-domain-card .db-act-buy,
.db-domain-card .db-act-offer { margin-left: 0; }

/* Decorative floating domain/web motifs (subtle, blue on the light canvas) */
.db-decor { position: absolute; inset: 0; overflow: hidden; pointer-events: none; z-index: 0; }
.db-decor span {
	position: absolute; font-weight: 800; color: #2563eb; opacity: 0.06;
	font-family: var(--db-font); user-select: none; will-change: transform;
}
.home.db-ui-active #primary,
.home.db-ui-active .site-content,
.home.db-ui-active .content-area { position: relative; }
.home.db-ui-active .site-content > * { position: relative; z-index: 1; }
@media (prefers-reduced-motion: reduce) {
	.db-domain-card { transition: none; }
	.db-decor span { transition: none !important; }
}

/* ==========================================================================
   11. MOBILE
   ========================================================================== */
@media (max-width: 768px) {
	.db-ui-active .site-content,
	.db-ui-active .entry-content { padding-left: var(--db-sp-4); padding-right: var(--db-sp-4); }
	.db-ui-active h1 { font-size: clamp(24px, 7vw, 36px); }
	.db-ui-active h2 { font-size: clamp(20px, 5vw, 28px); }
	.db-ui-active .site-header img, .db-ui-active header img { max-height: 44px; }
	.db-ui-active th, .db-ui-active td { padding: 10px 10px; }
	.db-ui-active a.db-act-buy, .db-ui-active a.db-act-offer { padding: 7px 13px; font-size: 13px; margin-left: 6px; }
}
@media (max-width: 480px) {
	.db-ui-active .site-content,
	.db-ui-active .entry-content { padding-left: var(--db-sp-3); padding-right: var(--db-sp-3); }
}

		<?php
		return (string) ob_get_clean();
	}
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! db_external_style( 'ui', db_ui_css() ) ) {
		// Uploads not writable — fall back to the old inline behavior.
		add_action( 'wp_head', function () {
			echo '<style id="db-modern-ui">' . db_ui_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		} );
	}
} );

/*
 * Theme-markup enhancer. DomainFolio renders things CSS alone can't fix:
 * two stacked header search forms (desktop + mobile variant both visible),
 * and Buy Now / Make an Offer as bare text links CSS cannot select by text.
 * This tags them with classes the design system styles (see section 10).
 */
add_action( 'wp_footer', function () {
	?>
	<script id="db-ui-enhancer">
	(function () {
		'use strict';
		try {
			/* 1. Hide duplicate search forms — keep the first visible one. */
			var seen = 0;
			document.querySelectorAll('form').forEach(function (f) {
				var inp = f.querySelector('input[type="text"], input[type="search"]');
				if (!inp) { return; }
				var ph = (inp.getAttribute('placeholder') || '').toLowerCase();
				if (ph.indexOf('search') === -1) { return; }
				seen++;
				if (seen > 1 && f.offsetParent !== null) { f.style.display = 'none'; }
			});

			/* 2. Tag action links + "View All" by their visible text. */
			document.querySelectorAll('a').forEach(function (a) {
				var t = (a.textContent || '').trim().toLowerCase();
				if (t === 'buy now') { a.classList.add('db-act-buy'); }
				else if (t === 'make an offer') { a.classList.add('db-act-offer'); }
				else if (t === 'view all') { a.classList.add('db-view-all'); }
			});

			/* 3. Bold the price text that precedes a Buy Now link, and
			   normalize it to comma-grouped format (the two homepage tables
			   disagree: "$10,000" vs "$10000"). */
			document.querySelectorAll('a.db-act-buy').forEach(function (a) {
				var n = a.previousSibling;
				while (n && n.nodeType === 3 && !n.textContent.trim()) { n = n.previousSibling; }
				if (n && n.nodeType === 3 && /\$[\d,]/.test(n.textContent)) {
					var num = n.textContent.replace(/[^\d]/g, '');
					var pretty = num ? '$' + Number(num).toLocaleString('en-US') : n.textContent.trim();
					var span = document.createElement('span');
					span.className = 'db-price-strong';
					span.textContent = pretty;
					n.parentNode.replaceChild(span, n);
				}
			});

			/* 4. Hide elements that render as empty stray bullets/pills: the
			   blank header nav-toggle and the footer social icons whose
			   Font Awesome glyphs aren't loading (so the <i> exists but paints
			   nothing). We test ACTUAL rendered size of any icon child rather
			   than just its presence. */
			var iconRenders = function (el) {
				var ic = el.querySelector('img, svg, i, [class*="icon"], .dashicons');
				if (!ic) {
					var bg = window.getComputedStyle(el).backgroundImage;
					return bg && bg !== 'none';
				}
				var r = ic.getBoundingClientRect();
				return (r.width > 3 && r.height > 3); // a real, painted glyph/image
			};
			document.querySelectorAll(
				'header a, header button, header label, header div, header span,' +
				'footer li, footer a, .site-footer li, .site-footer a, #colophon li, #colophon a'
			).forEach(function (el) {
				if ((el.textContent || '').trim().length > 0) { return; }
				if (iconRenders(el)) { return; }
				if (el.getBoundingClientRect().height > 80) { return; } // never a big wrapper
				el.style.display = 'none';
			});

			/* 5. Hero burger menu toggle. */
			var burger = document.querySelector('.db-hp-burger');
			var menu = document.getElementById('db-hp-menu');
			if (burger && menu) {
				burger.addEventListener('click', function () {
					var open = burger.getAttribute('aria-expanded') === 'true';
					burger.setAttribute('aria-expanded', open ? 'false' : 'true');
					burger.setAttribute('aria-label', open ? 'Open menu' : 'Close menu');
					if (open) { menu.setAttribute('hidden', ''); } else { menu.removeAttribute('hidden'); }
				});
			}

			/* 6. Turn the theme's plain domain tables into a card grid.
			   A domain table is any <table> containing a Buy Now / Make an
			   Offer action (tagged in step 2). */
			document.querySelectorAll('table').forEach(function (table) {
				if (!table.querySelector('.db-act-buy, .db-act-offer')) { return; }
				var grid = document.createElement('div');
				grid.className = 'db-domain-grid';
				table.querySelectorAll('tr').forEach(function (tr) {
					var action = tr.querySelector('.db-act-buy, .db-act-offer');
					if (!action) { return; }
					var nameLink = tr.querySelector('a:not(.db-act-buy):not(.db-act-offer)');
					if (!nameLink) { return; }

					// Extract the price by scanning the price cell's text and
					// removing the button's own label — robust whether the
					// price is a raw text node or wrapped in a span/p.
					var cell = action.closest('td') || action.parentNode;
					var cellText = (cell ? cell.textContent : '').replace(action.textContent || '', '');
					var pm = cellText.match(/\$\s?[\d.,]+/);
					var priceText = '';
					if (pm) {
						var digits = pm[0].replace(/[^\d]/g, '');
						if (digits) { priceText = '$' + Number(digits).toLocaleString('en-US'); }
					}

					var card = document.createElement('div');
					card.className = 'db-domain-card';
					var name = document.createElement('a');
					name.className = 'db-domain-card__name';
					name.href = nameLink.getAttribute('href') || '#';
					name.textContent = (nameLink.textContent || '').trim();
					var row = document.createElement('div');
					row.className = 'db-domain-card__row';
					var price = document.createElement('span');
					if (priceText) {
						price.className = 'db-domain-card__price';
						price.textContent = priceText;
					} else {
						price.className = 'db-domain-card__price--offer';
						price.textContent = 'Open to offers';
					}
					row.appendChild(price);
					row.appendChild(action);
					card.appendChild(name);
					card.appendChild(row);
					grid.appendChild(card);
				});
				if (grid.children.length) { table.parentNode.replaceChild(grid, table); }
			});

			/* 7. Subtle floating domain/web motifs on the below-hero canvas,
			   with a light parallax drift on scroll (front page, motion-safe). */
			var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
			var canvas = document.querySelector('.home.db-ui-active .site-content, .home.db-ui-active #primary, .home.db-ui-active .content-area');
			if (canvas && !reduce && document.body.classList.contains('home')) {
				var decor = document.createElement('div');
				decor.className = 'db-decor';
				decor.setAttribute('aria-hidden', 'true');
				var motifs = ['.com', '@', '</>', '.io', 'www', '.ai', '#', '.co'];
				var sizes = [42, 68, 30, 54, 38, 60, 34, 48];
				for (var i = 0; i < motifs.length; i++) {
					var sp = document.createElement('span');
					sp.textContent = motifs[i];
					sp.style.fontSize = sizes[i] + 'px';
					sp.style.left = ((i * 12 + 6) % 92) + '%';
					sp.style.top = ((i * 127) % 90 + 4) + '%';
					sp.setAttribute('data-depth', (0.15 + (i % 4) * 0.12).toFixed(2));
					decor.appendChild(sp);
				}
				canvas.appendChild(decor);
				var spans = decor.querySelectorAll('span');
				var ticking = false;
				window.addEventListener('scroll', function () {
					if (ticking) { return; }
					ticking = true;
					window.requestAnimationFrame(function () {
						var y = window.pageYOffset || 0;
						for (var k = 0; k < spans.length; k++) {
							var d = parseFloat(spans[k].getAttribute('data-depth')) || 0.2;
							spans[k].style.transform = 'translateY(' + (-(y * d)).toFixed(1) + 'px)';
						}
						ticking = false;
					});
				}, { passive: true });
			}
		} catch (e) { /* enhancement only — never break the page */ }
	})();
	</script>
	<?php
}, 98 );

/* ============================================================
   BLOCK 11 — DB Honeypot anti-spam for CF7 forms
   ============================================================ */

/*
 * Adds a hidden honeypot field to all CF7 forms.
 * Bots fill every field; humans never see or touch the honeypot.
 * If it arrives non-empty, the submission is silently discarded
 * (wpcf7_spam filter returns true = block the mail).
 */

if ( ! defined( 'DB_HONEYPOT_FIELD' ) ) {
	define( 'DB_HONEYPOT_FIELD', 'db_hp_website' ); // field name bots love
}

/* Inject the honeypot field into every CF7 form output. */
add_filter( 'wpcf7_form_elements', function ( $html ) {
	$hp = '<div class="db-hp-trap" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;">'
	      . '<label for="' . DB_HONEYPOT_FIELD . '">Leave this field empty</label>'
	      . '<input type="text" name="' . DB_HONEYPOT_FIELD . '" id="' . DB_HONEYPOT_FIELD . '" tabindex="-1" autocomplete="off" value="">'
	      . '</div>';
	// Inject just before the closing </form> equivalent (after last submit button area).
	return $html . $hp;
} );

/* Block submission if honeypot was filled. */
add_filter( 'wpcf7_spam', function ( $is_spam ) {
	if ( $is_spam ) {
		return true; // already flagged by another check
	}
	if ( ! empty( $_POST[ DB_HONEYPOT_FIELD ] ) ) {
		return true; // bot filled the trap
	}
	return false;
} );


/* ============================================================
   BLOCK 12 — DB Dynamic SEO meta for domain CPT pages
   ============================================================ */

/*
 * Overrides <title> and <meta description> for individual domain listing pages
 * (custom post type "domain"). Works alongside RankMath — if RankMath is active
 * and has a manually set title/description we leave it alone; we only fill the
 * gap when no custom meta is set.
 *
 * Also adds Open Graph tags specific to the domain post.
 */

/* Remove the default wp_title on domain singular pages so we can set our own.
 * Deferred to RankMath's own title output when it's active, same as the
 * meta-description hook below — otherwise this always wins the title-filter
 * chain (it's the last one applied) and silently overrides RankMath's title
 * on every domain listing. */
add_filter( 'pre_get_document_title', function ( $title ) {
	if ( ! is_singular( 'domain' ) || db_seo_other_plugin() ) {
		return $title;
	}
	$post        = get_queried_object();
	$domain_name = get_post_meta( $post->ID, 'domain_name', true );
	if ( ! $domain_name ) {
		$domain_name = $post->post_title;
	}
	$price = get_post_meta( $post->ID, 'domain_price', true );
	$price_str = $price ? ' — $' . number_format( (float) $price, 0 ) : '';
	return esc_html( $domain_name ) . $price_str . ' | Domain Brothers';
} );

/*
 * This block no longer emits <meta name="description"> or its own
 * db_aeo_description filter. The SEO Meta block is now the single owner of
 * the description tag for every URL on the site, domain listings included;
 * while both emitted, every listing page shipped two competing descriptions.
 * See db_seo_domain_def() in db-seo-meta-block.php.
 */


/* ============================================================
   BLOCK 13 — DB Service pages creator
   ============================================================ */

/*
 * Creates (or refreshes) the 5 Domain Brothers service landing pages.
 * Trigger: /?db_make_service_pages=1 (admin only, nonce-protected confirm).
 *
 * Each page gets: slug, title, a featured image placeholder, and rich content
 * matching Domain Brothers' service offering + 27+ yrs agency network.
 */

if ( ! function_exists( 'db_service_page_defs' ) ) {
	function db_service_page_defs() {
		return array(
			array(
				'slug'    => 'website-design-development',
				'title'   => 'Website Design & Development',
				'excerpt' => 'Custom, high-performance websites designed and built by vetted agency partners with 27+ years of combined experience — from domain to launch in as little as 4 weeks.',
				'content' => <<<'HTML'
<p>A premium domain deserves a website that lives up to it. Domain Brothers connects you with a network of vetted agency partners — including <strong>Mindshare Consulting Inc.</strong>, <strong>Jay Mehta Digital</strong>, and <strong>Netclues</strong> — with <strong>27+ years of combined experience</strong> building websites that rank, convert, and scale. You get one point of contact, a fixed scope, and a team that has already shipped hundreds of sites; we handle the coordination so you never have to manage an agency yourself.</p>

<h2>Custom WordPress &amp; CMS Development</h2>
<p>Most business sites we deliver are built on WordPress or a headless CMS, so your team can update content without a developer. Every build is custom-designed — no recycled templates — with clean, documented code that any future developer can maintain. You own the site and the code outright.</p>

<h2>E-Commerce &amp; Marketplace Builds</h2>
<p>From WooCommerce stores to full multi-vendor marketplaces, our partners build stores around the way you actually sell: product configurators, subscriptions, wholesale pricing tiers, and payment plans. Checkout flows are tested against real user behaviour to reduce cart abandonment before launch, not after.</p>

<h2>Landing Pages &amp; Conversion Optimization</h2>
<p>A landing page has one job: turn a visitor into a lead. We write the copy, structure the page around a single call to action, and instrument it with analytics so you can see exactly where visitors drop off. For paid-traffic campaigns, we A/B test headlines and layouts until the numbers improve.</p>

<h2>Performance &amp; Core Web Vitals</h2>
<p>Site speed is a ranking factor and a revenue factor. Every site we ship is optimized to pass Google's Core Web Vitals — compressed images, cached pages, minimal scripts — because a one-second delay in load time measurably cuts conversions.</p>

<h2>Accessibility &amp; Compliance</h2>
<p>We build to WCAG 2.1 AA standards: keyboard navigation, screen-reader support, sufficient colour contrast. Accessible sites reach more customers and reduce legal exposure under ADA and similar legislation.</p>

<h2>How We Work</h2>
<h3>1. Discovery call</h3>
<p>A free 30-minute call to understand your goals, audience, and budget. No pitch deck, no obligation.</p>
<h3>2. Fixed-scope proposal</h3>
<p>Within 48 hours you receive a written proposal with a fixed price, a page-by-page scope, and a delivery date.</p>
<h3>3. Design and build</h3>
<p>You review designs before a line of code is written, then see staged progress weekly until launch.</p>
<h3>4. Launch and support</h3>
<p>We deploy, test, and hand over full credentials — with 30 days of post-launch support included.</p>

<h2>Frequently Asked Questions</h2>
<h3>How much does a custom website cost?</h3>
<p>A custom-designed business website typically costs $3,500–$8,000; e-commerce builds run $8,000–$25,000 depending on catalogue size and integrations. You get an exact fixed quote within 48 hours of the discovery call, and 0% interest payment plans are available.</p>
<h3>How long does a website take to build?</h3>
<p>A standard business site takes 4–6 weeks from kickoff to launch. E-commerce and custom-functionality builds take 8–12 weeks. The timeline is written into your proposal.</p>
<h3>Can you build on a domain I already own?</h3>
<p>Yes. You do not need to buy a domain from Domain Brothers to use our web design services — we build on any domain you own, and we can also help you upgrade to a stronger domain as part of the project.</p>
<h3>Who owns the website when it's done?</h3>
<p>You do. On final payment you receive full ownership of the design, code, content, and all accounts — no lock-in, no ongoing licence fees.</p>

<p>Ready to build on your domain? <a href="/contact/" class="button">Get a Free Quote</a> — we respond within one business day.</p>
HTML
				,
			),
			array(
				'slug'    => 'digital-marketing',
				'title'   => 'Digital Marketing',
				'excerpt' => 'SEO, Google and Meta ads, content, and email automation — full-funnel digital marketing run by vetted agency partners, reported in numbers you can act on.',
				'content' => <<<'HTML'
<p>A premium domain gives you a head start in search and instant credibility with customers — but traffic still has to be earned. Domain Brothers works with a network of vetted marketing partners with <strong>27+ years of combined experience</strong> running campaigns for businesses from local services to national e-commerce brands. Because we broker domains for a living, we understand better than most agencies how domain authority, branding, and search behaviour fit together.</p>

<h2>Search Engine Optimization</h2>
<p>We cover all three layers of SEO: technical (crawlability, site speed, structured data), on-page (content targeting the queries your customers actually type), and off-page (earning links from relevant, reputable sites). Campaigns start with a keyword and competitor audit so budget goes to the terms most likely to produce revenue — not vanity rankings.</p>

<h2>Google Ads &amp; Meta Ads Management</h2>
<p>Paid search and social put you in front of buyers on day one. Our partners build campaigns around tracked conversions — calls, form fills, purchases — and report cost per lead, not clicks. Accounts are audited weekly; underperforming ads are paused and budgets shift to what works.</p>

<h2>Content Strategy &amp; Copywriting</h2>
<p>Search engines and AI assistants now reward pages that answer questions directly. We plan and write content mapped to real customer questions — service pages, comparison guides, FAQs — written by humans, edited for accuracy, and structured so both Google and answer engines can cite it.</p>

<h2>Email Marketing Automation</h2>
<p>Email remains the highest-ROI channel in digital marketing. We set up automated sequences — welcome flows, abandoned-cart recovery, post-purchase follow-ups — in platforms like Mailchimp and Klaviyo, so revenue arrives while you sleep.</p>

<h2>Analytics &amp; Reporting</h2>
<p>Every engagement includes GA4 and Search Console configured correctly, conversion tracking verified end-to-end, and a monthly plain-English report: what we did, what it produced, and what happens next.</p>

<h2>How We Work</h2>
<h3>1. Audit</h3>
<p>We review your current traffic, rankings, ad accounts, and competitors, and identify the fastest wins.</p>
<h3>2. Strategy</h3>
<p>You receive a 90-day plan with specific targets — traffic, leads, cost per acquisition — and a fixed monthly price.</p>
<h3>3. Execute and report</h3>
<p>We run the campaigns and send a monthly performance report; you keep full ownership and admin access to every account.</p>

<h2>Frequently Asked Questions</h2>
<h3>How long does SEO take to show results?</h3>
<p>Expect meaningful movement in 3–6 months for most markets. Quick technical fixes can lift traffic within weeks, but competitive rankings are earned over months — any agency promising page one in 30 days is guessing or gaming.</p>
<h3>What ad budget do I need to start?</h3>
<p>For most local and niche campaigns, $1,000–$2,500 per month in media spend is enough to gather reliable data and produce leads. We tell you honestly if your market needs more before you commit.</p>
<h3>Do you require long-term contracts?</h3>
<p>No. Engagements start with a 90-day initial term — the minimum needed to show real results — then continue month to month. You can leave any time after that with 30 days' notice and keep all accounts and data.</p>
<h3>How will I know it's working?</h3>
<p>Every campaign is tied to tracked conversions. Your monthly report shows leads generated, cost per lead, and revenue attributed — the same numbers we use to judge our own work.</p>

<p>Want the traffic your domain deserves? <a href="/contact/" class="button">Book a Free Strategy Call</a> and get a 90-day plan with no obligation.</p>
HTML
				,
			),
			array(
				'slug'    => 'software-development',
				'title'   => 'Software Development',
				'excerpt' => 'SaaS platforms, APIs, and integrations engineered by senior developers from a 27+ year agency network — fixed scopes, weekly demos, and code you own outright.',
				'content' => <<<'HTML'
<p>Custom software is where good ideas either become products or become expensive lessons. Domain Brothers' engineering partners — drawn from an agency network with <strong>27+ years of combined experience</strong> — have shipped SaaS platforms, internal tools, and integrations for startups and established businesses alike. Every project gets a senior engineer from day one, a written scope, and working software demonstrated every week, so you always know exactly where your budget is going.</p>

<h2>SaaS Platform Development</h2>
<p>From first prototype to paying customers: multi-tenant architecture, subscription billing through Stripe, role-based access, and an admin panel your team can actually use. We design for the load you will have in two years, not just the demo next month.</p>

<h2>API Design &amp; Integrations</h2>
<p>Well-designed REST and GraphQL APIs let your product plug into the tools your customers already use. We build APIs with versioning, authentication, and documentation included by default, and we integrate with third-party platforms — Stripe, Salesforce, HubSpot, QuickBooks, shipping carriers — without the brittle glue code that breaks at 2 a.m.</p>

<h2>Database Design &amp; Architecture</h2>
<p>Most slow software is slow because of its data layer. Our engineers design schemas around your real query patterns, index deliberately, and load-test before launch, so the application that is fast with 100 records stays fast with 10 million.</p>

<h2>Cloud Infrastructure &amp; DevOps</h2>
<p>We deploy to AWS and Google Cloud with infrastructure defined as code, automated CI/CD pipelines, monitoring, and daily backups. Deployments become a non-event: push, test, release — with instant rollback if anything looks wrong.</p>

<h2>Maintenance &amp; Long-Term Support</h2>
<p>Software is never finished. We offer monthly support retainers covering security patches, dependency updates, small features, and priority bug fixes — with guaranteed response times in writing.</p>

<h2>How We Work</h2>
<h3>1. Technical discovery</h3>
<p>A working session to map requirements, integrations, and risks. You get a written technical brief even if you build elsewhere.</p>
<h3>2. Scope and estimate</h3>
<p>A milestone-based plan with a price and delivery date for each stage — you approve every milestone before it starts.</p>
<h3>3. Build in weekly sprints</h3>
<p>Working software demonstrated every week in a staging environment you can click through yourself.</p>
<h3>4. Launch and handover</h3>
<p>Deployment, documentation, and a full handover of code and infrastructure credentials. The IP is yours.</p>

<h2>Frequently Asked Questions</h2>
<h3>How much does custom software development cost?</h3>
<p>A focused MVP typically costs $20,000–$60,000; larger platforms range higher. Because we quote per milestone, you can fund the first stage, evaluate the result, and decide whether to continue — you are never locked into the full budget up front.</p>
<h3>How long does it take to build an MVP?</h3>
<p>Most MVPs ship in 8–16 weeks. The discovery phase produces a week-by-week plan, and weekly demos mean you see progress from the second week — not a big reveal at the end.</p>
<h3>What technologies do you use?</h3>
<p>Primarily PHP/Laravel, Node.js, and Python on the backend, React and Vue on the frontend, and PostgreSQL or MySQL for data — mature, widely supported stacks that any competent developer can maintain after handover.</p>
<h3>Who owns the intellectual property?</h3>
<p>You do. Every contract assigns full IP ownership of the code, designs, and documentation to you on payment. We keep nothing proprietary in your stack.</p>

<p>Have a product in mind? <a href="/contact/" class="button">Discuss Your Requirements</a> — a senior engineer reviews every enquiry.</p>
HTML
				,
			),
			array(
				'slug'    => 'mobile-app-development',
				'title'   => 'Mobile App Development',
				'excerpt' => 'iOS and Android apps from concept to App Store launch — React Native, Swift, and Kotlin builds with store submission and post-launch support handled for you.',
				'content' => <<<'HTML'
<p>Turning a domain into a product often means putting it in your customers' pockets. Domain Brothers' mobile development partners — part of an agency network with <strong>27+ years of combined experience</strong> — have taken consumer and business apps from first sketch to live App Store and Google Play listings. We handle the parts most first-time founders underestimate: store review guidelines, push notification infrastructure, analytics, and the updates that keep an app alive after launch.</p>

<h2>Cross-Platform Development</h2>
<p>For most products, React Native or Flutter is the right call: one codebase runs on both iOS and Android, cutting build cost and time by 30–40% compared with two native apps, while still delivering native-feeling performance for the vast majority of use cases.</p>

<h2>Native iOS &amp; Android</h2>
<p>When your app depends on heavy device features — camera pipelines, Bluetooth hardware, background processing, ARKit — we build native in Swift and Kotlin. You get the full performance and platform integration that only native code provides.</p>

<h2>MVP Development &amp; Prototyping</h2>
<p>The cheapest mistake is the one you catch before building. We start with clickable prototypes you can put in front of real users within two weeks, then build a focused first version around the features those users actually respond to.</p>

<h2>App Store Launch &amp; ASO</h2>
<p>We prepare listings, screenshots, and metadata; manage TestFlight and Play Console beta programs; handle Apple's review process (including the rejections that stall most first submissions); and optimize your store listing so the right users find it.</p>

<h2>Post-Launch Maintenance</h2>
<p>iOS and Android each ship major updates every year, and an unmaintained app breaks within one or two cycles. Our maintenance retainers cover OS compatibility updates, crash monitoring, security patches, and incremental feature releases.</p>

<h2>How We Work</h2>
<h3>1. Product workshop</h3>
<p>We define the core user journey, cut the feature list to what launch actually requires, and agree success metrics.</p>
<h3>2. Prototype and validate</h3>
<p>A clickable design prototype in about two weeks — test it with real users before committing to development.</p>
<h3>3. Build and beta</h3>
<p>Development in two-week sprints with a new installable build on your own phone at the end of each one.</p>
<h3>4. Launch and iterate</h3>
<p>We manage store submission and release, then use live analytics and crash data to plan the next versions.</p>

<h2>Frequently Asked Questions</h2>
<h3>How much does it cost to build a mobile app?</h3>
<p>A cross-platform MVP typically costs $25,000–$60,000; complex or fully native apps run higher. The product workshop produces a fixed, milestone-based quote, and 0% interest payment plans are available on development fees.</p>
<h3>Should I build native or cross-platform?</h3>
<p>Cross-platform (React Native or Flutter) is right for roughly 80% of apps — it is faster and cheaper with near-native quality. Go native when you rely heavily on device hardware or need every millisecond of performance. We recommend the cheaper option whenever it genuinely fits.</p>
<h3>How long does app development take?</h3>
<p>From workshop to App Store launch, plan for 12–20 weeks for an MVP. You will have an installable beta on your own device around the halfway mark.</p>
<h3>Do you handle App Store and Google Play submission?</h3>
<p>Yes — accounts, certificates, listings, review responses, and release management are all included. Both store listings are registered under your accounts, so you keep full control.</p>

<p>Ready to put your brand on the home screen? <a href="/contact/" class="button">Start Your App Project</a> with a free product workshop consultation.</p>
HTML
				,
			),
			array(
				'slug'    => 'other-services',
				'title'   => 'Other Services',
				'excerpt' => 'Domain valuations, portfolio consulting, brand naming, DNS and email setup, and acquisition monitoring — everything around the domain, handled by the brokers who trade them daily.',
				'content' => <<<'HTML'
<p>Buying the domain is one decision; making it work for your business involves a dozen more. Domain Brothers — backed by an agency network with <strong>27+ years of combined experience</strong> — offers the supporting services that turn a domain purchase into a working brand: honest valuations, portfolio strategy, naming, technical setup, and ongoing monitoring. These are services delivered by people who broker domains every day, not generalists reading the same public price guides you can.</p>

<h2>Domain Valuation Reports</h2>
<p>Whether you are selling, buying, insuring, or raising finance against a domain, you need a defensible number. Our written valuation reports combine comparable-sale data, search volume, TLD strength, and brandability into a documented market value you can hand to a buyer, a bank, or a tax adviser.</p>

<h2>Domain Portfolio Consulting</h2>
<p>Most portfolios carry dead weight — renewals on names that will never sell, while strong names sit unlisted. We audit your holdings, tell you plainly which domains to sell, hold, or drop, and set realistic pricing so your portfolio produces income instead of renewal invoices.</p>

<h2>Brand Name Development</h2>
<p>Naming a company backwards — falling in love with a name whose domain is taken — is expensive. We run naming the right way: shortlists screened against available (or acquirable) domains, preliminary trademark checks, and social handle availability, so the name you choose is a name you can actually own everywhere.</p>

<h2>DNS &amp; Business Email Setup</h2>
<p>A new domain is only useful once it resolves. We configure DNS correctly the first time — including SPF, DKIM, and DMARC records so your email lands in inboxes rather than spam folders — and set up business email on Google Workspace or Microsoft 365, with migration from your old addresses handled for you.</p>

<h2>Domain Monitoring &amp; Acquisition Watch</h2>
<p>The domain you want today may expire or come to market tomorrow. We monitor target domains for expiry, ownership changes, and listing events, and can open anonymous negotiations the moment an opportunity appears — before the name hits public auction.</p>

<h2>How We Work</h2>
<h3>1. Tell us what you need</h3>
<p>Send the domain, portfolio, or naming brief through our contact form — we respond within one business day.</p>
<h3>2. Fixed quote</h3>
<p>Every service is quoted as a fixed fee in writing before any work begins. No hourly surprises.</p>
<h3>3. Delivery</h3>
<p>Valuations and audits are delivered as written reports; technical setups are tested end-to-end and documented before handover.</p>

<h2>Frequently Asked Questions</h2>
<h3>How much does a domain valuation cost?</h3>
<p>A single-domain written valuation report starts at $199 and is delivered within 3–5 business days. Portfolio valuations are quoted by size, and the fee is credited back if you later sell the domain through Domain Brothers.</p>
<h3>Can you manage my domain portfolio for me?</h3>
<p>Yes. We offer ongoing portfolio management covering renewals, listings, inbound offers, and negotiation, under a simple agreement where you approve every sale before it happens.</p>
<h3>Do you handle trademark registration?</h3>
<p>We run preliminary trademark screening as part of every naming project to flag obvious conflicts early, then refer you to a licensed trademark attorney for filing. We tell you when you need a lawyer — we don't pretend to be one.</p>
<h3>How quickly can you set up DNS and email on a new domain?</h3>
<p>Standard DNS and business email setup completes within 24–48 hours of receiving access, including SPF, DKIM, and DMARC configuration and deliverability testing.</p>

<p>Need something around your domain rather than the domain itself? <a href="/contact/" class="button">Contact Us</a> and tell us what you're trying to do — we'll point you straight even if it's not a service we sell.</p>
HTML
				,
			),
		);
	}
}

add_action( 'init', function () {
	if ( empty( $_GET['db_make_service_pages'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Nonce gate.
	if ( empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'db_make_service_pages' ) ) {
		$confirm_url = wp_nonce_url( add_query_arg( 'db_make_service_pages', '1', home_url( '/' ) ), 'db_make_service_pages' );
		wp_die( 'Create / refresh the 5 service pages? <a href="' . esc_url( $confirm_url ) . '">Confirm</a>', 'DB Service Pages', array( 'response' => 200 ) );
	}

	$results = array();
	foreach ( db_service_page_defs() as $def ) {
		$existing = get_page_by_path( $def['slug'] );
		$page_data = array(
			'post_title'   => $def['title'],
			'post_content' => $def['content'],
			'post_excerpt' => $def['excerpt'],
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_name'    => $def['slug'],
		);
		if ( $existing ) {
			$page_data['ID'] = $existing->ID;
			wp_update_post( $page_data );
			$results[] = 'Updated: /' . $def['slug'] . '/';
		} else {
			$id = wp_insert_post( $page_data );
			$results[] = $id && ! is_wp_error( $id ) ? 'Created: /' . $def['slug'] . '/' : 'Failed: ' . $def['slug'];
		}
	}

	$list = '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $results ) ) . '</li></ul>';
	wp_die( 'DB Service Pages — Done:<br>' . $list . '<br><a href="' . esc_url( home_url( '/about-us/' ) ) . '">View About Us</a>', 'DB Service Pages', array( 'response' => 200 ) );
} );


/* ============================================================
   BLOCK 14 — DB DevOne logo fix (developed-by footer logo)
   ============================================================ */

/*
 * On domain listing pages where ?lis=y is present in the URL (the DomainFolio
 * theme appends this for "developed by" attribution pages), the theme renders
 * a generic logo. This block replaces it with the Domain Brothers wordmark
 * served server-side so it's present on first paint (no JS flash).
 *
 * If DomainFolio changes the filter name, update the hook below.
 */

add_filter( 'devone_logo_url', function ( $url ) {
	if ( isset( $_GET['lis'] ) ) {
		return esc_url( home_url( '/wp-content/themes/DomainFolio/img/logo.png' ) );
	}
	return $url;
} );

/* Also inject an inline CSS override for the devone footer on ?lis pages. */
add_action( 'wp_head', function () {
	if ( ! isset( $_GET['lis'] ) ) {
		return;
	}
	?>
	<style id="db-devone-css">
	.devone-footer-logo img { max-height: 28px !important; width: auto !important; }
	.devone-footer { display: flex !important; align-items: center !important; gap: 8px !important; font-size: 12px !important; color: #888 !important; }
	</style>
	<?php
} );

/* ============================================================
   PHASE 4 — CRM Lead Management
   ============================================================ */
require_once __DIR__ . '/db-crm-block.php';

/* ============================================================
   EMAIL + THANK-YOU BLOCK — Branded emails & thank-you page
   ============================================================ */
require_once __DIR__ . '/db-email-thankyou-block.php';

/* ============================================================
   PHASE 2 — Stripe Checkout, Webhooks & Payment Integration
   ============================================================ */
require_once __DIR__ . '/db-stripe-block.php';

/* ============================================================
   ANALYTICS — CRM dashboard charts (leads, pipeline, sources)
   ============================================================ */
require_once __DIR__ . '/db-analytics-block.php';

/* ============================================================
   PWA — installable app + home-screen support
   ============================================================ */
require_once __DIR__ . '/db-pwa-block.php';

/* ============================================================
   TLS — forced HTTPS, HSTS, upgrade-insecure-requests
   ============================================================ */
require_once __DIR__ . '/db-tls-block.php';

/* ============================================================
   SEO META — Yoast-aware per-page titles/descriptions/keywords
   ============================================================ */
require_once __DIR__ . '/db-seo-meta-block.php';

/* ============================================================
   MOBILE NAV — site-wide hamburger drawer
   ============================================================ */
require_once __DIR__ . '/db-mobilenav-block.php';

/* ============================================================
   PAGE CURTAIN — branded transition overlay
   ============================================================ */
require_once __DIR__ . '/db-curtain-block.php';

/* ============================================================
   SAFETY — fatal-error recorder + branded failure page
   ============================================================ */
require_once __DIR__ . '/db-safety-block.php';
