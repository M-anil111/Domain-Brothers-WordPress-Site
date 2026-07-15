<?php
/**
 * Plugin Name: Domain Brothers Custom Blocks
 * Plugin URI:  https://beta.domainbrothers.com
 * Description: All Domain Brothers custom functionality — Stripe checkout & webhooks, CRM lead management, branded email system, thank-you flows, SMTP routing, offer flow, payment plans, modern UI, AEO/SEO, performance hardening, honeypot anti-spam, dynamic meta, and service pages.
 * Version:     3.2.0
 * Author:      Domain Brothers
 * License:     Proprietary
 * Text Domain: db-blocks
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
define( 'DB_BLOCKS_LOADED', '3.2.0' );


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
if ( ! defined( 'DB_HERO_USE_HOOK' ) )      define( 'DB_HERO_USE_HOOK',      'the_content' );

if ( ! function_exists( 'db_hp_hero_html' ) ) {
	function db_hp_hero_html() {
		$browse_href = DB_HERO_BROWSE_URL ? esc_url( DB_HERO_BROWSE_URL ) : '#db-below-hero';
		$offer_href  = esc_url( home_url( DB_HERO_OFFER_URL ) );
		$trust_items = array( 'Expert Brokerage', '0% Interest Plans', 'Secure Escrow', '24-Hour Response' );
		$trust_html  = '';
		foreach ( $trust_items as $i => $item ) {
			if ( $i > 0 ) {
				$trust_html .= '<span class="db-hp-tdot" aria-hidden="true"></span>';
			}
			$trust_html .= '<span class="db-hp-titem">' . esc_html( $item ) . '</span>';
		}
		$html  = '<section class="db-hp-hero" aria-label="Domain Brothers">';
		$html .= '<div class="db-hp-inner">';
		$html .= '<p class="db-hp-eyebrow">Premium Domain Brokerage</p>';
		$html .= '<h1 class="db-hp-h1">The Right Domain<br>Changes Everything.</h1>';
		$html .= '<p class="db-hp-sub">We source, negotiate, and transfer premium domain names with expert precision — flexible payment plans, secure escrow, and guidance from a team with 25+ years of combined experience.</p>';
		$html .= '<div class="db-hp-ctas">';
		$html .= '<a href="' . $browse_href . '" class="db-hp-btn-primary db-hp-browse-btn">Browse Premium Domains</a>';
		$html .= '<a href="' . $offer_href . '" class="db-hp-btn-ghost">Make an Offer</a>';
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
} else {
	add_action( 'wp_body_open', function () {
		if ( ! is_front_page() || is_admin() || $GLOBALS['db_hero_rendered'] ) {
			return;
		}
		$GLOBALS['db_hero_rendered'] = true;
		echo db_hp_hero_html();
	} );
}

add_action( 'wp_head', function () {
	?>
	<style id="db-hp-styles">
	body {
		font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
		-webkit-font-smoothing: antialiased;
		-moz-osx-font-smoothing: grayscale;
		text-rendering: optimizeLegibility;
	}
	h1, h2, h3, h4 { letter-spacing: -0.02em; }
	:focus-visible { outline: 2px solid #0b6ed4; outline-offset: 3px; border-radius: 4px; }
	.wp-block-button__link,
	.btn, .button:not(.db-plan-cta):not(.db-hp-btn-primary):not(.db-hp-btn-ghost),
	input[type="submit"], button[type="submit"] {
		border-radius: 8px !important;
		transition: transform 0.12s ease, box-shadow 0.12s ease !important;
	}
	.wp-block-button__link:hover, .btn:hover,
	input[type="submit"]:hover, button[type="submit"]:hover {
		transform: translateY(-1px);
		box-shadow: 0 4px 14px rgba(0,0,0,.14);
	}
	.db-hp-hero {
		position: relative; width: 100vw; left: 50%; right: 50%;
		margin-left: -50vw; margin-right: -50vw; margin-top: 0; margin-bottom: 48px;
		background: linear-gradient(140deg, #060d24 0%, #0b2250 45%, #0e3272 100%);
		overflow: hidden;
		padding: clamp(72px, 12vw, 140px) 24px clamp(64px, 10vw, 120px);
		color: #f5f5f7; box-sizing: border-box;
	}
	.db-hp-hero::before {
		content: ''; position: absolute; top: -10%; left: 50%;
		width: 90vw; height: 90vw; max-width: 960px; max-height: 960px;
		transform: translateX(-50%);
		background: radial-gradient(ellipse, rgba(59,130,246,0.20) 0%, transparent 68%);
		pointer-events: none;
	}
	.db-hp-hero::after {
		content: ''; position: absolute; inset: 0;
		background-image: radial-gradient(rgba(255,255,255,.06) 1px, transparent 1px);
		background-size: 32px 32px; pointer-events: none;
	}
	.db-hp-inner { position: relative; z-index: 1; max-width: 840px; margin: 0 auto; text-align: center; }
	.db-hp-eyebrow {
		display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: 0.14em;
		text-transform: uppercase; color: #93c5fd; margin: 0 0 26px; padding: 6px 16px;
		border: 1px solid rgba(147,197,253,0.35); border-radius: 999px;
		animation: db-fade-up 0.6s ease both;
	}
	.db-hp-h1 {
		font-size: clamp(38px, 7vw, 78px); font-weight: 700; line-height: 1.03;
		letter-spacing: -0.04em; color: #f5f5f7; margin: 0 0 26px;
		animation: db-fade-up 0.6s 0.10s ease both;
	}
	.db-hp-sub {
		font-size: clamp(16px, 2vw, 20px); line-height: 1.58; color: rgba(245,245,247,0.70);
		max-width: 640px; margin: 0 auto 44px; font-weight: 400;
		animation: db-fade-up 0.6s 0.20s ease both;
	}
	.db-hp-ctas {
		display: flex; flex-wrap: wrap; gap: 14px; justify-content: center;
		margin-bottom: 56px; animation: db-fade-up 0.6s 0.30s ease both;
	}
	.db-hp-btn-primary {
		display: inline-block; padding: 16px 36px; background: #f5f5f7; color: #0b1430 !important;
		font-size: 16px; font-weight: 600; text-decoration: none !important; border-radius: 999px;
		box-shadow: 0 2px 10px rgba(0,0,0,.22);
		transition: background 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
	}
	.db-hp-btn-primary:hover { background: #ffffff; color: #060d24 !important; transform: scale(1.03); box-shadow: 0 8px 28px rgba(0,0,0,.32); }
	.db-hp-btn-ghost {
		display: inline-block; padding: 16px 36px; background: transparent; color: #f5f5f7 !important;
		font-size: 16px; font-weight: 600; text-decoration: none !important;
		border: 1.5px solid rgba(245,245,247,0.45); border-radius: 999px;
		transition: border-color 0.15s ease, background 0.15s ease, transform 0.15s ease;
	}
	.db-hp-btn-ghost:hover { border-color: rgba(245,245,247,0.85); background: rgba(255,255,255,.10); color: #ffffff !important; transform: scale(1.03); }
	.db-hp-trust {
		display: flex; flex-wrap: wrap; align-items: center; justify-content: center;
		font-size: 13px; color: rgba(245,245,247,0.50); letter-spacing: 0.025em;
		animation: db-fade-up 0.6s 0.42s ease both;
	}
	.db-hp-titem { padding: 4px 14px; }
	.db-hp-tdot { display: inline-block; width: 3px; height: 3px; border-radius: 50%; background: rgba(245,245,247,0.28); vertical-align: middle; }
	@keyframes db-fade-up { from { opacity: 0; transform: translateY(22px); } to { opacity: 1; transform: translateY(0); } }
	@media (max-width: 580px) {
		.db-hp-ctas { flex-direction: column; align-items: center; }
		.db-hp-btn-primary, .db-hp-btn-ghost { width: 100%; max-width: 320px; text-align: center; }
		.db-hp-tdot { display: none; }
		.db-hp-titem { padding: 2px 8px; }
	}
	</style>
	<?php
}, 5 );

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
	if ( is_admin() || class_exists( 'RankMath' ) ) { return; }
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
	if ( is_admin() || class_exists( 'RankMath' ) ) { return; }
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

add_action( 'wp_head', function () {
	?>
<style id="db-modern-ui">

/* ==========================================================================
   0. DESIGN TOKENS
   ========================================================================== */
:root {
	--db-navy:      #060d24;
	--db-blue:      #0a6ed1;
	--db-blue-lt:   #e8f0fb;
	--db-green:     #137a3e;
	--db-red:       #c0392b;

	--db-gray-50:   #f9fafb;
	--db-gray-100:  #f3f4f6;
	--db-gray-200:  #e5e7eb;
	--db-gray-300:  #d1d5db;
	--db-gray-500:  #6b7280;
	--db-gray-700:  #374151;
	--db-gray-900:  #111827;

	--db-font:      -apple-system, BlinkMacSystemFont, 'SF Pro Text', 'Inter', 'Segoe UI', sans-serif;

	--db-sp-1:  4px;  --db-sp-2:  8px;  --db-sp-3:  12px; --db-sp-4:  16px;
	--db-sp-6:  24px; --db-sp-8:  32px; --db-sp-12: 48px; --db-sp-16: 64px;

	--db-r-sm:   6px;
	--db-r-md:   10px;
	--db-r-lg:   16px;
	--db-r-pill: 999px;

	--db-shadow-sm: 0 1px 3px rgba(0,0,0,.08), 0 1px 2px rgba(0,0,0,.06);
	--db-shadow-md: 0 4px 6px rgba(0,0,0,.07), 0 10px 15px rgba(0,0,0,.1);
	--db-shadow-lg: 0 10px 15px rgba(0,0,0,.1), 0 20px 25px rgba(0,0,0,.12);

	--db-ease:      cubic-bezier(.4,0,.2,1);
	--db-dur-fast:  120ms;
	--db-dur-base:  200ms;
	--db-dur-slow:  350ms;

	--db-max-content: 1200px;
	--db-max-prose:    700px;
}
@media (prefers-color-scheme: dark) {
	:root {
		--db-gray-50:  #1a1f2e;
		--db-gray-100: #252b3b;
		--db-gray-200: #2e3548;
		--db-gray-300: #3d4560;
		--db-gray-500: #8892a4;
		--db-gray-700: #c4cdd8;
		--db-gray-900: #f0f2f5;
		--db-blue-lt:  #0d1f3c;
	}
}
@media (prefers-reduced-motion: reduce) {
	*, *::before, *::after { transition-duration: .01ms !important; animation-duration: .01ms !important; }
}

/* ==========================================================================
   1. GLOBAL RESET + BASE
   ========================================================================== */
.db-ui-active {
	font-family: var(--db-font);
	color: var(--db-gray-900);
	line-height: 1.6;
	-webkit-font-smoothing: antialiased;
	-moz-osx-font-smoothing: grayscale;
}
.db-ui-active *, .db-ui-active *::before, .db-ui-active *::after { box-sizing: border-box; }
.db-ui-active img { max-width: 100%; height: auto; display: block; }
.db-ui-active a { color: var(--db-blue); transition: color var(--db-dur-fast) var(--db-ease); }
.db-ui-active a:hover { color: #0854b0; }
:focus-visible { outline: 2px solid var(--db-blue); outline-offset: 3px; border-radius: var(--db-r-sm); }

/* ==========================================================================
   2. TYPOGRAPHY
   ========================================================================== */
.db-ui-active h1, .db-ui-active h2, .db-ui-active h3,
.db-ui-active h4, .db-ui-active h5, .db-ui-active h6 {
	font-family: var(--db-font);
	font-weight: 700;
	line-height: 1.2;
	letter-spacing: -0.025em;
	color: var(--db-gray-900);
	margin: 0 0 var(--db-sp-4);
}
.db-ui-active h1 { font-size: clamp(28px, 5vw, 48px); }
.db-ui-active h2 { font-size: clamp(22px, 3.5vw, 36px); }
.db-ui-active h3 { font-size: clamp(18px, 2.5vw, 26px); }
.db-ui-active p  { margin: 0 0 var(--db-sp-4); color: var(--db-gray-700); }

/* ==========================================================================
   3. BUTTONS
   ========================================================================== */
.db-ui-active .btn-primary,
.db-ui-active input[type="submit"],
.db-ui-active button[type="submit"] {
	display: inline-flex; align-items: center; justify-content: center;
	gap: var(--db-sp-2);
	padding: 12px 24px;
	background: var(--db-blue);
	color: #fff !important;
	font-family: var(--db-font); font-size: 15px; font-weight: 600;
	text-decoration: none !important;
	border: none; border-radius: var(--db-r-md); cursor: pointer;
	box-shadow: var(--db-shadow-sm);
	transition: background var(--db-dur-fast) var(--db-ease),
	            transform var(--db-dur-fast) var(--db-ease),
	            box-shadow var(--db-dur-fast) var(--db-ease);
}
.db-ui-active .btn-primary:hover,
.db-ui-active input[type="submit"]:hover,
.db-ui-active button[type="submit"]:hover {
	background: #0854b0; transform: translateY(-1px); box-shadow: var(--db-shadow-md);
}

/* ==========================================================================
   4. DOMAIN CARDS
   ========================================================================== */
.db-ui-active .domain-listing,
.db-ui-active .domain-card,
.db-ui-active .listing-item {
	background: #fff;
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
	border-color: var(--db-gray-300);
}
@media (prefers-color-scheme: dark) {
	.db-ui-active .domain-listing,
	.db-ui-active .domain-card,
	.db-ui-active .listing-item {
		background: var(--db-gray-100);
		border-color: var(--db-gray-200);
	}
}

/* Price badges */
.db-ui-active .domain-price,
.db-ui-active .price,
.db-ui-active [class*="price"] {
	font-size: 1.15em; font-weight: 700; color: var(--db-blue);
	letter-spacing: -0.02em;
}

/* Buy / Offer buttons on listing cards */
.db-ui-active .buy-btn,
.db-ui-active .btn-buy,
.db-ui-active .add-to-cart {
	background: var(--db-blue); color: #fff !important;
	border: none; border-radius: var(--db-r-md);
	padding: 10px 18px; font-size: 14px; font-weight: 600;
	text-decoration: none !important; cursor: pointer;
	transition: background var(--db-dur-fast) var(--db-ease);
}
.db-ui-active .buy-btn:hover,
.db-ui-active .btn-buy:hover,
.db-ui-active .add-to-cart:hover { background: #0854b0; }

.db-ui-active .offer-btn {
	background: transparent; color: var(--db-blue) !important;
	border: 1.5px solid var(--db-blue); border-radius: var(--db-r-md);
	padding: 9px 18px; font-size: 14px; font-weight: 600;
	text-decoration: none !important; cursor: pointer;
	transition: background var(--db-dur-fast) var(--db-ease), color var(--db-dur-fast) var(--db-ease);
}
.db-ui-active .offer-btn:hover { background: var(--db-blue-lt); }

/* ==========================================================================
   5. NAVIGATION
   ========================================================================== */
.db-ui-active header,
.db-ui-active .site-header,
.db-ui-active #masthead {
	backdrop-filter: blur(8px);
	-webkit-backdrop-filter: blur(8px);
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
	transition: background var(--db-dur-fast) var(--db-ease), color var(--db-dur-fast) var(--db-ease);
}
.db-ui-active .main-navigation a:hover,
.db-ui-active .nav-menu a:hover,
.db-ui-active header nav a:hover {
	background: var(--db-gray-100);
	color: var(--db-gray-900) !important;
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
	padding: 12px 14px;
	font-family: var(--db-font); font-size: 15px;
	color: var(--db-gray-900);
	background: #fff;
	border: 1.5px solid var(--db-gray-300);
	border-radius: var(--db-r-md);
	outline: none;
	transition: border-color var(--db-dur-fast) var(--db-ease), box-shadow var(--db-dur-fast) var(--db-ease);
}
.db-ui-active .wpcf7-form input:focus,
.db-ui-active .wpcf7-form textarea:focus,
.db-ui-active .wpcf7-form select:focus {
	border-color: var(--db-blue);
	box-shadow: 0 0 0 3px rgba(10,110,209,.15);
}
.db-ui-active .wpcf7-form input[type="submit"] {
	background: var(--db-blue); color: #fff; border: none;
	padding: 13px 28px; font-size: 15px; font-weight: 600;
	border-radius: var(--db-r-md); cursor: pointer;
	transition: background var(--db-dur-fast) var(--db-ease), transform var(--db-dur-fast) var(--db-ease);
}
.db-ui-active .wpcf7-form input[type="submit"]:hover { background: #0854b0; transform: translateY(-1px); }
.db-ui-active .wpcf7-not-valid-tip { color: var(--db-red); font-size: 13px; margin-top: 4px; }
.db-ui-active .wpcf7-response-output { border-radius: var(--db-r-md); padding: 12px 16px; margin-top: 16px; }

/* ==========================================================================
   7. FOOTER
   ========================================================================== */
.db-ui-active footer,
.db-ui-active .site-footer,
.db-ui-active #colophon {
	background: var(--db-navy);
	color: rgba(255,255,255,.65);
	border-top: none;
}
.db-ui-active footer a,
.db-ui-active .site-footer a { color: rgba(255,255,255,.70); }
.db-ui-active footer a:hover,
.db-ui-active .site-footer a:hover { color: #fff; }

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
   9. TABLES
   ========================================================================== */
.db-ui-active table {
	width: 100%; border-collapse: collapse; font-size: 14px;
}
.db-ui-active th, .db-ui-active td {
	text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--db-gray-200);
}
.db-ui-active th { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--db-gray-500); }
.db-ui-active tr:hover td { background: var(--db-gray-50); }

/* ==========================================================================
   10. MOBILE
   ========================================================================== */
@media (max-width: 768px) {
	.db-ui-active .site-content,
	.db-ui-active .entry-content { padding-left: var(--db-sp-4); padding-right: var(--db-sp-4); }
	.db-ui-active h1 { font-size: clamp(24px, 7vw, 36px); }
	.db-ui-active h2 { font-size: clamp(20px, 5vw, 28px); }
}
@media (max-width: 480px) {
	.db-ui-active .site-content,
	.db-ui-active .entry-content { padding-left: var(--db-sp-3); padding-right: var(--db-sp-3); }
}

</style>
	<?php
} );

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
	if ( ! is_singular( 'domain' ) || defined( 'RANK_MATH_VERSION' ) ) {
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

/* Domain listing's richer, price-inclusive description — consumed by both
 * the plain <meta name="description"> below and by the AEO block's OG/Twitter
 * tags (via the db_aeo_description filter) so there's one description, not
 * two competing ones. */
if ( ! function_exists( 'db_domain_meta_description' ) ) {
	function db_domain_meta_description( $post ) {
		$domain_name = get_post_meta( $post->ID, 'domain_name', true ) ?: $post->post_title;
		$price       = get_post_meta( $post->ID, 'domain_price', true );
		$price_fmt   = $price ? '$' . number_format( (float) $price, 0 ) : 'Contact for price';
		return 'Buy ' . $domain_name . ' for ' . $price_fmt . '. Premium domain brokerage — expert transfer, secure escrow, flexible payment plans. Domain Brothers.';
	}
}

add_filter( 'db_aeo_description', function ( $description ) {
	if ( ! is_singular( 'domain' ) || defined( 'RANK_MATH_VERSION' ) ) {
		return $description;
	}
	return db_domain_meta_description( get_queried_object() );
} );

/* Inject the plain SEO <meta name="description"> tag. Open Graph/Twitter
 * tags for domain pages are handled once, by the AEO block (Block 9) —
 * see the db_aeo_description filter above. */
add_action( 'wp_head', function () {
	if ( ! is_singular( 'domain' ) || defined( 'RANK_MATH_VERSION' ) ) {
		return;
	}
	$desc = db_domain_meta_description( get_queried_object() );
	echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
}, 1 ); // priority 1 so theme can override at default priority


/* ============================================================
   BLOCK 13 — DB Service pages creator
   ============================================================ */

/*
 * Creates (or refreshes) the 5 Domain Brothers service landing pages.
 * Trigger: /?db_make_service_pages=1 (admin only, nonce-protected confirm).
 *
 * Each page gets: slug, title, a featured image placeholder, and rich content
 * matching Domain Brothers' service offering + 25 yrs agency network.
 */

if ( ! function_exists( 'db_service_page_defs' ) ) {
	function db_service_page_defs() {
		return array(
			array(
				'slug'    => 'website-design-development',
				'title'   => 'Website Design & Development',
				'excerpt' => 'Custom, high-performance websites built for conversion — from domain acquisition to launch.',
				'content' => '<h2>Websites That Convert</h2>
<p>Your domain is just the start. Domain Brothers works with agency partners including <strong>Mindshare Consulting Inc.</strong>, <strong>Jay Mehta Digital</strong>, and <strong>Netclues</strong> — a network with <strong>25+ years of combined experience</strong> — to deliver websites that are fast, accessible, and built to rank.</p>
<h3>What We Deliver</h3>
<ul>
<li>Custom WordPress and headless CMS development</li>
<li>E-commerce and marketplace builds</li>
<li>Landing page and conversion rate optimisation</li>
<li>ADA/WCAG accessibility compliance</li>
<li>Core Web Vitals and speed optimisation</li>
</ul>
<h3>Ready to Build?</h3>
<p><a href="/contact/" class="button">Get a Free Quote</a></p>',
			),
			array(
				'slug'    => 'digital-marketing',
				'title'   => 'Digital Marketing',
				'excerpt' => 'SEO, PPC, content strategy — full-funnel digital marketing that drives qualified traffic and revenue.',
				'content' => '<h2>Marketing That Pays for Itself</h2>
<p>A premium domain is a head-start, not a guarantee. Our agency partners provide full-funnel digital marketing — from organic search to paid acquisition — to make sure the right visitors find your new domain.</p>
<h3>Our Services</h3>
<ul>
<li>Search Engine Optimisation (technical, on-page, off-page)</li>
<li>Google Ads and Meta Ads management</li>
<li>Content strategy and copywriting</li>
<li>Email marketing automation</li>
<li>Social media management</li>
<li>Analytics setup and reporting (GA4, Search Console)</li>
</ul>
<h3>Start Growing</h3>
<p><a href="/contact/" class="button">Book a Strategy Call</a></p>',
			),
			array(
				'slug'    => 'software-development',
				'title'   => 'Software Development',
				'excerpt' => 'Bespoke software solutions — APIs, SaaS platforms, custom tools — built by experienced engineers.',
				'content' => '<h2>Custom Software for Ambitious Brands</h2>
<p>Whether you need a proprietary platform, a REST API, or a complex integration, our software development partners bring enterprise-grade engineering to businesses of every size.</p>
<h3>Expertise</h3>
<ul>
<li>SaaS platform architecture and development</li>
<li>REST and GraphQL API design</li>
<li>Third-party integrations (Stripe, Salesforce, HubSpot, etc.)</li>
<li>Database design and optimisation</li>
<li>DevOps, CI/CD, and cloud infrastructure (AWS, GCP)</li>
</ul>
<h3>Have a Project?</h3>
<p><a href="/contact/" class="button">Discuss Your Requirements</a></p>',
			),
			array(
				'slug'    => 'mobile-app-development',
				'title'   => 'Mobile App Development',
				'excerpt' => 'iOS and Android apps — from MVP to App Store launch, built with React Native or native stacks.',
				'content' => '<h2>Mobile Apps Built to Launch</h2>
<p>Turn your domain into a product. Our mobile development partners build consumer and enterprise apps using React Native, Swift, and Kotlin — with a track record of successful App Store and Google Play launches.</p>
<h3>What We Build</h3>
<ul>
<li>Cross-platform apps (React Native / Flutter)</li>
<li>Native iOS (Swift) and Android (Kotlin) apps</li>
<li>MVP development with rapid iteration</li>
<li>App Store Optimisation (ASO)</li>
<li>Post-launch maintenance and updates</li>
</ul>
<h3>Let\'s Build Your App</h3>
<p><a href="/contact/" class="button">Get Started</a></p>',
			),
			array(
				'slug'    => 'other-services',
				'title'   => 'Other Services',
				'excerpt' => 'Domain consulting, valuation, brand strategy, and more — the full stack of services beyond the domain itself.',
				'content' => '<h2>Beyond the Domain</h2>
<p>Domain Brothers and our agency network offer a wide range of supporting services to ensure your new domain translates into a thriving online presence.</p>
<h3>Additional Services</h3>
<ul>
<li><strong>Domain Portfolio Consulting</strong> — strategy for buying, selling, and managing domain portfolios</li>
<li><strong>Brand Name Development</strong> — naming, trademark check, and brand identity</li>
<li><strong>Domain Valuation Reports</strong> — certified market valuations for financing, sale, or insurance</li>
<li><strong>DNS & Email Setup</strong> — professional DNS configuration and business email launch</li>
<li><strong>Domain Monitoring</strong> — watch for expiring or infringing domains in your niche</li>
</ul>
<h3>Get in Touch</h3>
<p><a href="/contact/" class="button">Contact Us</a></p>',
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
