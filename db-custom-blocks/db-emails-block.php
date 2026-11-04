<?php
/**
 * Email system: one look for every email, and templates the master admin can edit.
 *
 * Loaded before the older email blocks so it owns the three shared pieces:
 *
 *   db_mail_wrap()        the outer shell (logo, soft background, white card, footer)
 *   db_plan_email_shell() the inner content used by the plan and offer emails
 *   db_mail_inner()       headline + text + details + button, centred
 *
 * Every transactional email now has a template key (see db_mail_registry()).
 * The senders call db_mail_send( $key, $to, $vars ); the template's subject,
 * headline, text, details and button come from the registry's defaults or from
 * what the administrator saved under Domain Brothers > Email templates, with
 * {{placeholders}} filled in. Colours, logo, font and footer come from the
 * Design tab and apply to every email, including the older ones that still
 * pass finished HTML through db_send_branded_email().
 *
 * Placeholder rules: {{name}} is HTML-escaped; {{name_url}} is a URL;
 * {{name_html}} is trusted HTML that the sender built (always from escaped
 * parts). Subjects are plain text.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────── 1. Design ──────────────────────────────── */

if ( ! function_exists( 'db_mail_design_defaults' ) ) {
	function db_mail_design_defaults() {
		return array(
			'logo_url'    => '',
			'logo_width'  => 168,
			'bg'          => '#f5f5f7',
			'card'        => '#ffffff',
			'ink'         => '#1d1d1f',
			'muted'       => '#6e6e73',
			'primary'     => '#0b7a75',
			'secondary'   => '#1d4f9c',
			'btn_bg'      => '#0b7a75',
			'btn_text'    => '#ffffff',
			'font'        => 'system',
			'tagline'     => 'Premium domains, handled personally',
			'footer_text' => 'You are receiving this email because of activity on your Domain Brothers account or an enquiry you made.',
			'address'     => 'Domain Brothers',
		);
	}
}

if ( ! function_exists( 'db_mail_fonts' ) ) {
	function db_mail_fonts() {
		return array(
			'system'    => array( 'Apple system (San Francisco)', '-apple-system,BlinkMacSystemFont,\'SF Pro Display\',\'SF Pro Text\',\'Helvetica Neue\',Helvetica,Arial,sans-serif' ),
			'helvetica' => array( 'Helvetica / Arial', '\'Helvetica Neue\',Helvetica,Arial,sans-serif' ),
			'georgia'   => array( 'Georgia (serif)', 'Georgia,\'Times New Roman\',Times,serif' ),
		);
	}
}

if ( ! function_exists( 'db_mail_design' ) ) {
	function db_mail_design() {
		$saved = get_option( 'db_mail_design', array() );
		$d     = array_merge( db_mail_design_defaults(), is_array( $saved ) ? $saved : array() );
		if ( '' === trim( (string) $d['logo_url'] ) ) {
			$d['logo_url'] = function_exists( 'db_brand_logo_url' ) ? db_brand_logo_url() : home_url( '/wp-content/uploads/2017/03/Domain-Brothers.png' );
		}
		return $d;
	}
}

if ( ! function_exists( 'db_mail_hex' ) ) {
	function db_mail_hex( $v, $fallback ) {
		$v = sanitize_hex_color( (string) $v );
		return $v ? $v : $fallback;
	}
}

/* ───────────────────────────── 2. Rendering ───────────────────────────── */

if ( ! function_exists( 'db_mail_fill' ) ) {
	/**
	 * Replaces {{name}} with the value, escaped by the rule in the file header.
	 *
	 * @param string $mode html | text
	 */
	function db_mail_fill( $text, array $vars, $mode = 'html' ) {
		return preg_replace_callback( '/\{\{\s*([a-z0-9_]+)\s*\}\}/i', static function ( $m ) use ( $vars, $mode ) {
			$k = strtolower( $m[1] );
			if ( ! array_key_exists( $k, $vars ) ) {
				return '';
			}
			$v = (string) $vars[ $k ];
			if ( 'text' === $mode ) {
				return wp_strip_all_tags( html_entity_decode( $v, ENT_QUOTES, 'UTF-8' ) );
			}
			if ( '_html' === substr( $k, -5 ) ) {
				return $v;
			}
			if ( '_url' === substr( $k, -4 ) ) {
				return esc_url( $v );
			}
			return esc_html( $v );
		}, (string) $text );
	}
}

if ( ! function_exists( 'db_mail_style_body' ) ) {
	/** Gives the editor's HTML the email look (inline styles only: mail clients ignore stylesheets). */
	function db_mail_style_body( $html, array $d ) {
		$ink   = db_mail_hex( $d['ink'], '#1d1d1f' );
		$muted = db_mail_hex( $d['muted'], '#6e6e73' );
		$prim  = db_mail_hex( $d['primary'], '#0b7a75' );
		$p     = 'margin:0 0 16px;font-size:17px;line-height:1.55;color:' . $muted . ';text-align:center;';
		$html  = preg_replace_callback( '/<p(\s[^>]*)?>/i', static function ( $m ) use ( $p ) {
			$small = isset( $m[1] ) && preg_match( '/font-size:\s*(1[0-5])px/i', $m[1], $f ) ? 'font-size:' . $f[1] . 'px;' : '';
			return '<p style="' . $p . $small . '">';
		}, (string) $html );
		$html  = preg_replace( '/<(ul|ol)(\s[^>]*)?>/i', '<$1 style="margin:0 auto 16px;padding:0;list-style:none;font-size:17px;line-height:1.7;color:' . $muted . ';text-align:center;">', $html );
		$html  = preg_replace( '/<li(\s[^>]*)?>/i', '<li style="margin:0 0 6px;">', $html );
		$html  = preg_replace( '/<h([23])(\s[^>]*)?>/i', '<h$1 style="margin:24px 0 8px;font-size:22px;line-height:1.2;font-weight:700;letter-spacing:-.01em;color:' . $ink . ';text-align:center;">', $html );
		$html  = preg_replace( '/<strong>/i', '<strong style="color:' . $ink . ';font-weight:600;">', $html );
		$html  = preg_replace( '/<a\s+href=/i', '<a style="color:' . $prim . ';text-decoration:underline;" href=', $html );
		return $html;
	}
}

if ( ! function_exists( 'db_mail_inner' ) ) {
	/**
	 * The content of an email, centred: eyebrow, headline, text, details panel, button.
	 *
	 * @param array $o eyebrow, heading, body_html, details (label => value), cta_label, cta_url, tone, hero, raw_body (true: body_html is already styled)
	 */
	function db_mail_inner( array $o ) {
		$d     = db_mail_design();
		$ink   = db_mail_hex( $d['ink'], '#1d1d1f' );
		$muted = db_mail_hex( $d['muted'], '#6e6e73' );
		$tones = array( 'neutral' => db_mail_hex( $d['primary'], '#0b7a75' ), 'good' => '#188038', 'warn' => '#b45309', 'danger' => '#c5221f' );
		$tone  = $tones[ $o['tone'] ?? 'neutral' ] ?? $tones['neutral'];
		$h     = '';
		if ( ! empty( $o['hero'] ) ) {
			$h .= '<img src="' . esc_url( $o['hero'] ) . '" alt="" width="504" style="display:block;width:100%;max-width:504px;height:auto;margin:0 auto 28px;border-radius:20px;border:0;">';
		}
		if ( ! empty( $o['eyebrow'] ) ) {
			$h .= '<p class="db-m-eyebrow" style="margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:' . $tone . ';text-align:center;">' . esc_html( $o['eyebrow'] ) . '</p>';
		}
		if ( ! empty( $o['heading'] ) ) {
			$h .= '<h1 class="db-m-ink" style="margin:0 0 18px;font-size:36px;line-height:1.1;font-weight:700;letter-spacing:-.025em;color:' . $ink . ';text-align:center;">' . esc_html( $o['heading'] ) . '</h1>';
		}
		$body = (string) ( $o['body_html'] ?? '' );
		$h   .= empty( $o['raw_body'] ) ? db_mail_style_body( $body, $d ) : $body;
		$det  = (array) ( $o['details'] ?? array() );
		if ( $det ) {
			$h .= '<table role="presentation" class="db-m-panel" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:26px 0 8px;background:#f5f5f7;border-radius:18px;"><tr><td style="padding:10px 24px;">';
			$i  = 0;
			foreach ( $det as $label => $val ) {
				if ( '' === trim( (string) $val ) ) {
					continue;
				}
				$h .= '<p style="margin:0;padding:14px 0;' . ( $i++ ? 'border-top:1px solid #e5e5ea;' : '' ) . 'text-align:center;"><span style="display:block;font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:' . $muted . ';">' . esc_html( $label ) . '</span><span class="db-m-ink" style="display:block;margin-top:4px;font-size:19px;font-weight:600;color:' . $ink . ';">' . esc_html( $val ) . '</span></p>';
			}
			$h .= '</td></tr></table>';
		}
		if ( ! empty( $o['cta_label'] ) && ! empty( $o['cta_url'] ) ) {
			$bg = db_mail_hex( $d['btn_bg'], '#0b7a75' );
			$tx = db_mail_hex( $d['btn_text'], '#ffffff' );
			$h .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" align="center" style="margin:30px auto 6px;"><tr><td align="center" bgcolor="' . $bg . '" style="border-radius:999px;background:' . $bg . ';"><a href="' . esc_url( $o['cta_url'] ) . '" style="display:inline-block;padding:16px 36px;font-size:17px;font-weight:600;color:' . $tx . ';text-decoration:none;border-radius:999px;">' . esc_html( $o['cta_label'] ) . '</a></td></tr></table>';
		}
		return $h;
	}
}

if ( ! function_exists( 'db_plan_email_shell' ) ) {
	/** Kept for the plan and offer emails: the same call as before, now in the new look. */
	function db_plan_email_shell( $headline, $paras_html, $details = array(), $cta_label = '', $cta_url = '', $tone = 'neutral', $deadline_note = false ) {
		$support = defined( 'DB_PLAN_SUPPORT_EMAIL' ) ? DB_PLAN_SUPPORT_EMAIL : 'sales@domainbrothers.com';
		$paras_html .= '<p style="font-size:14px;">Questions' . ( $deadline_note ? ' or need more time? Please write to us <em>before</em> the deadline' : '' ) . '? Reply to this email or write to <a href="mailto:' . esc_attr( $support ) . '">' . esc_html( $support ) . '</a>.</p>';
		return db_mail_inner( array(
			'heading'   => $headline,
			'body_html' => $paras_html,
			'details'   => $details,
			'cta_label' => $cta_label,
			'cta_url'   => $cta_url,
			'tone'      => $tone,
		) );
	}
}

if ( ! function_exists( 'db_mail_wrap' ) ) {
	/** The full email: soft background, white rounded card, logo on top, small centred footer. */
	function db_mail_wrap( $subject, $body_html, $from_name = 'Domain Brothers', $from_email = 'sales@domainbrothers.com' ) {
		$d      = db_mail_design();
		$bg     = db_mail_hex( $d['bg'], '#f5f5f7' );
		$card   = db_mail_hex( $d['card'], '#ffffff' );
		$ink    = db_mail_hex( $d['ink'], '#1d1d1f' );
		$muted  = db_mail_hex( $d['muted'], '#6e6e73' );
		$prim   = db_mail_hex( $d['primary'], '#0b7a75' );
		$sec    = db_mail_hex( $d['secondary'], '#1d4f9c' );
		$fonts  = db_mail_fonts();
		$stack  = ( $fonts[ $d['font'] ] ?? $fonts['system'] )[1];
		$logo_w = max( 80, min( 320, (int) $d['logo_width'] ) );
		$pre    = isset( $GLOBALS['db_mail_pre'] ) ? (string) $GLOBALS['db_mail_pre'] : '';
		unset( $GLOBALS['db_mail_pre'] );
		$site   = esc_url( home_url( '/' ) );
		$h      = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark"><title>' . esc_html( $subject ) . '</title>';
		$h     .= '<style>@media (max-width:620px){.db-m-card{padding:36px 22px !important;border-radius:22px !important}.db-m-outer{padding:16px 10px !important}h1.db-m-ink{font-size:30px !important}}@media (prefers-color-scheme:dark){body,.db-m-bg{background:#000 !important}.db-m-card{background:#1c1c1e !important}.db-m-ink,h1,h2,h3,strong{color:#f5f5f7 !important}p,li,.db-m-muted{color:#a1a1a6 !important}.db-m-panel{background:#2c2c2e !important}}</style></head>';
		$h     .= '<body class="db-m-bg" style="margin:0;padding:0;background:' . $bg . ';font-family:' . $stack . ';-webkit-text-size-adjust:100%;">';
		if ( '' !== $pre ) {
			$h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . esc_html( $pre ) . str_repeat( '&#8199;&#847; ', 20 ) . '</div>';
		}
		$h .= '<table role="presentation" class="db-email-wrapper db-m-bg db-m-outer" border="0" cellpadding="0" cellspacing="0" width="100%" style="background:' . $bg . ';padding:40px 16px;"><tr><td align="center">';
		$h .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;"><tr><td align="center" style="padding:6px 0 26px;"><a href="' . $site . '" style="text-decoration:none;"><img src="' . esc_url( $d['logo_url'] ) . '" alt="Domain Brothers" width="' . $logo_w . '" style="display:block;width:' . $logo_w . 'px;max-width:100%;height:auto;border:0;margin:0 auto;"></a></td></tr></table>';
		$h .= '<table role="presentation" class="db-m-card" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;background:' . $card . ';border-radius:28px;overflow:hidden;"><tr><td style="height:6px;line-height:6px;font-size:0;background:' . $prim . ';background-image:linear-gradient(90deg,' . $prim . ',' . $sec . ');">&nbsp;</td></tr><tr><td class="db-m-card" align="center" style="padding:52px 48px 48px;text-align:center;">' . $body_html . '</td></tr></table>';
		$h .= '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;"><tr><td align="center" style="padding:26px 24px 8px;text-align:center;">';
		if ( '' !== trim( (string) $d['tagline'] ) ) {
			$h .= '<p class="db-m-muted" style="margin:0 0 10px;font-size:14px;font-weight:600;color:' . $ink . ';">' . esc_html( $d['tagline'] ) . '</p>';
		}
		$h .= '<p class="db-m-muted" style="margin:0 0 8px;font-size:12px;line-height:1.5;color:' . $muted . ';">' . esc_html( $d['footer_text'] ) . '</p>';
		$h .= '<p class="db-m-muted" style="margin:0;font-size:12px;color:' . $muted . ';">&copy; ' . esc_html( gmdate( 'Y' ) ) . ' ' . esc_html( $d['address'] ) . ' &middot; <a href="' . esc_url( home_url( '/contact-us/' ) ) . '" style="color:' . $muted . ';">Contact</a> &middot; <a href="' . $site . '" style="color:' . $muted . ';">domainbrothers.com</a></p>';
		$h .= '</td></tr></table></td></tr></table></body></html>';
		return $h;
	}
}

// Every HTML email also carries a plain-text version.
add_action( 'phpmailer_init', function ( $m ) {
	if ( 'text/html' === $m->ContentType && '' === (string) $m->AltBody ) {
		$t = preg_replace( '#<(br|/p|/h[1-6]|/li|/tr)\s*/?>#i', "\n", (string) $m->Body );
		$t = preg_replace( '#<style.*?</style>#is', '', $t );
		$t = preg_replace( '#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $t );
		$m->AltBody = trim( preg_replace( "/\n{3,}/", "\n\n", html_entity_decode( wp_strip_all_tags( $t ), ENT_QUOTES, 'UTF-8' ) ) );
	}
} );

/* ───────────────────────────── 3. Template registry ───────────────────── */

if ( ! function_exists( 'db_mail_registry' ) ) {
	/**
	 * key => group, label, to (customer|team), when, vars (name => [what it is, sample]),
	 *        subject, eyebrow, heading, body, details ("Label|{{var}}" per line), cta, cta_var, tone, critical
	 */
	function db_mail_registry() {
		static $r = null;
		if ( null !== $r ) {
			return $r;
		}
		$dom = array( 'domain' => array( 'The domain name', 'brandable.com' ) );
		$r   = array(
			// ── Sign-in and following ───────────────────────────────────────
			'signin_code'      => array(
				'group' => 'Account', 'label' => 'Sign-in code', 'to' => 'customer', 'critical' => true,
				'when'  => 'A visitor asks for a code to sign in or save a domain.',
				'vars'  => array( 'code' => array( 'The 6-digit code', '482913' ), 'minutes' => array( 'Minutes it works for', '10' ) ),
				'subject' => 'Your Domain Brothers sign-in code: {{code}}', 'eyebrow' => 'Sign in', 'heading' => 'Your code is {{code}}',
				'body'  => '<p>Enter this code on the Domain Brothers site to sign in. It works for {{minutes}} minutes.</p><p>If you did not ask for it you can ignore this email. Nobody can sign in without the code.</p>',
				'details' => '', 'cta' => '', 'cta_var' => '', 'tone' => 'neutral',
			),
			'alerts_digest'    => array(
				'group' => 'Account', 'label' => 'Followed-domain alerts', 'to' => 'customer', 'critical' => false,
				'when'  => 'Price changes, sales and new offers on domains someone saved (one email, at most every 15 minutes).',
				'vars'  => array( 'summary' => array( 'One line summary', '2 updates on domains you follow' ), 'alerts_html' => array( 'The list of alerts', '<ul><li>The price of brandable.com dropped from $2,500 to $1,800.</li><li>A new offer was made on brandable.com.</li></ul>' ), 'account_url' => array( 'Link to the Alerts tab', home_url( '/my-wishlist/?tab=alerts' ) ), 'stop_url' => array( 'One-click stop link', home_url( '/my-wishlist/' ) ) ),
				'subject' => 'Domain Brothers: {{summary}}', 'eyebrow' => 'Your wishlist', 'heading' => 'News on domains you follow',
				'body'  => '{{alerts_html}}<p style="font-size:14px">You get this because you saved these domains. <a href="{{stop_url}}">Stop these emails</a></p>',
				'details' => '', 'cta' => 'See all alerts', 'cta_var' => 'account_url', 'tone' => 'neutral',
			),
			// ── Offers (buyer) ──────────────────────────────────────────────
			'offer_received'   => array(
				'group' => 'Offers', 'label' => 'Offer received (to the buyer)', 'to' => 'customer', 'critical' => false,
				'when'  => 'Right after someone makes an offer on a domain.',
				'vars'  => $dom + array( 'offer_amount' => array( 'The amount offered', '$1,500' ), 'offer_url' => array( 'The buyer\'s private offer page (may be empty)', home_url( '/' ) ), 'email' => array( 'The buyer\'s email', 'buyer@example.com' ) ),
				'subject' => 'We received your offer on {{domain}}', 'eyebrow' => 'Offer received', 'heading' => 'Thank you. We have your offer.',
				'body'  => '<p>We received your offer for <strong>{{domain}}</strong>. Our team reviews every offer personally and will reply by email, usually within 24 hours.</p>',
				'details' => "Domain|{{domain}}\nYour offer|{{offer_amount}}", 'cta' => 'View my offer', 'cta_var' => 'offer_url', 'tone' => 'good',
			),
			'offer_countered'  => array(
				'group' => 'Offers', 'label' => 'We made a price (to the buyer)', 'to' => 'customer', 'critical' => false,
				'when'  => 'We reply to an offer with our price.',
				'vars'  => $dom + array( 'price' => array( 'Our price', '$2,100' ), 'expires' => array( 'Open until', 'March 3, 2026' ), 'note_html' => array( 'Our message to the buyer', '<p>We think this is a fair price.</p>' ), 'offer_url' => array( 'The buyer\'s offer page', home_url( '/' ) ) ),
				'subject' => 'Our price for {{domain}}: {{price}}', 'eyebrow' => 'We have a price', 'heading' => 'Here is our price for {{domain}}',
				'body'  => '<p>Thank you for your offer on <strong>{{domain}}</strong>. After talking with the owner, we can offer it to you at <strong>{{price}}</strong>.</p>{{note_html}}',
				'details' => "Domain|{{domain}}\nOur price|{{price}}\nOpen until|{{expires}}", 'cta' => 'See the offer', 'cta_var' => 'offer_url', 'tone' => 'neutral',
			),
			'offer_accepted'   => array(
				'group' => 'Offers', 'label' => 'Offer accepted (to the buyer)', 'to' => 'customer', 'critical' => true,
				'when'  => 'We accept the buyer\'s offer, or they accept ours.',
				'vars'  => $dom + array( 'price' => array( 'The agreed price', '$1,800' ), 'expires' => array( 'Pay by', 'March 3, 2026' ), 'note_html' => array( 'Our message', '' ), 'offer_url' => array( 'Link to pay', home_url( '/' ) ) ),
				'subject' => 'Your offer on {{domain}} is accepted', 'eyebrow' => 'Accepted', 'heading' => 'We have a deal on {{domain}}',
				'body'  => '<p>We accepted <strong>{{price}}</strong> for <strong>{{domain}}</strong>. You can pay in full or monthly, and the domain is transferred to you once payment is complete.</p>{{note_html}}',
				'details' => "Domain|{{domain}}\nAgreed price|{{price}}\nPay by|{{expires}}", 'cta' => 'Pay now', 'cta_var' => 'offer_url', 'tone' => 'good',
			),
			'offer_declined'   => array(
				'group' => 'Offers', 'label' => 'Offer declined (to the buyer)', 'to' => 'customer', 'critical' => false,
				'when'  => 'We could not agree a price.',
				'vars'  => $dom + array( 'note_html' => array( 'Our message', '' ), 'offer_url' => array( 'Link to make another offer', home_url( '/' ) ) ),
				'subject' => 'About your offer on {{domain}}', 'eyebrow' => 'Update on your offer', 'heading' => 'We could not agree a price this time',
				'body'  => '<p>We could not agree a price on <strong>{{domain}}</strong> this time. You are welcome to make another offer, or look at similar domains.</p>{{note_html}}',
				'details' => '', 'cta' => 'Make another offer', 'cta_var' => 'offer_url', 'tone' => 'neutral',
			),
			// ── Purchases ───────────────────────────────────────────────────
			'purchase_full'    => array(
				'group' => 'Payments', 'label' => 'Payment received: full purchase (to the buyer)', 'to' => 'customer', 'critical' => true,
				'when'  => 'A domain is paid for in full.',
				'vars'  => $dom + array( 'amount' => array( 'Amount paid', '$8,000.00' ), 'date' => array( 'Date', 'March 3, 2026' ), 'type' => array( 'Purchase type', 'Full purchase' ) ),
				'subject' => 'Your domain {{domain}} is secured', 'eyebrow' => 'Payment received', 'heading' => '{{domain}} is yours',
				'body'  => '<p>Your purchase of <strong>{{domain}}</strong> is confirmed. Our team starts the transfer within 1 to 2 business days and will email you the instructions.</p><h3>What happens next</h3><ul><li>We start the transfer</li><li>You receive an authorisation email to approve it</li><li>The domain lands in your account within 2 to 5 days</li></ul>',
				'details' => "Domain|{{domain}}\nAmount|{{amount}}\nDate|{{date}}", 'cta' => '', 'cta_var' => '', 'tone' => 'good',
			),
			'plan_started'     => array(
				'group' => 'Payments', 'label' => 'Payment plan started (to the buyer)', 'to' => 'customer', 'critical' => true,
				'when'  => 'The first payment of a payment plan clears.',
				'vars'  => $dom + array( 'amount' => array( 'First payment', '$733.00' ), 'months' => array( 'Number of payments', '12' ), 'rate' => array( 'Interest', '0% interest' ), 'total' => array( 'Total of the plan', '$8,800.00' ), 'schedule_html' => array( 'The payment schedule', '<p>1. Today, $733.00</p>' ), 'terms_html' => array( 'The plan terms', '<p>The domain is held in escrow until the final payment.</p>' ), 'reminder_days' => array( 'Days before each payment we remind', '3' ) ),
				'subject' => 'Your payment plan for {{domain}} has started', 'eyebrow' => 'Payment plan active', 'heading' => 'Your plan for {{domain}} is active',
				'body'  => '<p>We received your first payment of <strong>{{amount}}</strong>. Your plan is <strong>{{months}} monthly payments</strong>, {{rate}}.</p>{{schedule_html}}<p>The remaining payments are charged to the card you used. You can use the domain during the plan: reply to this email and we will point its DNS to your hosting. Ownership transfers after the final payment; until then it is held securely in escrow.</p>{{terms_html}}<p style="font-size:14px">We email a reminder {{reminder_days}} days before each payment.</p>',
				'details' => "Domain|{{domain}}\nPlan total|{{total}}", 'cta' => '', 'cta_var' => '', 'tone' => 'good',
			),
			'plan_installment' => array(
				'group' => 'Payments', 'label' => 'Plan payment received (to the buyer)', 'to' => 'customer', 'critical' => false,
				'when'  => 'Each later payment of a plan clears.',
				'vars'  => $dom + array( 'number' => array( 'Payment number', '4' ), 'months' => array( 'Number of payments', '12' ), 'amount' => array( 'Amount', '$733.00' ), 'remaining' => array( 'Payments remaining', '8' ) ),
				'subject' => 'Payment {{number}} of {{months}} received for {{domain}}', 'eyebrow' => 'Payment received', 'heading' => 'Payment {{number}} of {{months}} received',
				'body'  => '<p>We received <strong>{{amount}}</strong> for <strong>{{domain}}</strong>. {{remaining}} payment(s) remain.</p>',
				'details' => "Domain|{{domain}}\nAmount|{{amount}}", 'cta' => '', 'cta_var' => '', 'tone' => 'good',
			),
			'payment_failed'   => array(
				'group' => 'Payments', 'label' => 'Payment failed (to the buyer)', 'to' => 'customer', 'critical' => true,
				'when'  => 'A card payment is declined.',
				'vars'  => $dom + array( 'amount' => array( 'Amount', '$733.00' ), 'update_url' => array( 'Where to fix it', home_url( '/' ) ) ),
				'subject' => 'Payment failed for {{domain}}: please update your payment method', 'eyebrow' => 'Action needed', 'heading' => 'Your payment did not go through',
				'body'  => '<p>We could not process your payment of <strong>{{amount}}</strong> for <strong>{{domain}}</strong>. Please update your payment method to keep your reservation.</p>',
				'details' => '', 'cta' => 'Update payment method', 'cta_var' => 'update_url', 'tone' => 'danger',
			),
			'plan_reminder'    => db_mail_plan_tpl( 'Plan payment reminder (to the buyer)', 'Three days before a plan payment.', 'Payment {{number}} of {{months}} for {{domain}} will be charged on {{due_date}}', 'Coming up', 'Your next payment is coming up', '<p>This is a courtesy reminder. We will charge your card on file <strong>{{amount}}</strong> on <strong>{{due_date}}</strong> for payment {{number}} of {{months}} on <strong>{{domain}}</strong>.</p><p>There is nothing to do if your card is current and has funds. If your card changed or expired, update it now so the payment does not fail.</p>', "Domain|{{domain}}\nPayment {{number}} of {{months}}|{{amount}}\nPaid so far|{{paid}}", 'Update my card', 'neutral' ),
			'plan_failed_1'    => db_mail_plan_tpl( 'Plan payment failed, notice 1 (to the buyer)', 'Day 0: the card was declined.', 'Action needed: your payment for {{domain}} did not go through', 'Action needed', 'Your payment did not go through', '<p>We tried to charge your card for payment {{number}} of {{months}} on <strong>{{domain}}</strong> and it was declined. This usually means an expired card, a changed card number or insufficient funds.</p><p><strong>What happens next:</strong> we will try again automatically over the next {{grace_days}} days. To avoid losing the domain, update your card or make sure funds are available <strong>by {{grace_end}}</strong>.</p><p>If the payment is not received by then, your plan is terminated, the domain returns to our inventory and the {{paid}} you have paid so far is not refunded.</p>', "Domain|{{domain}}\nPayment {{number}} of {{months}}|{{amount}}\nFix it by|{{grace_end}}", 'Update my card now', 'warn' ),
			'plan_failed_2'    => db_mail_plan_tpl( 'Plan payment failed, notice 2 (to the buyer)', 'Day 3: the retry was declined.', 'Still unpaid: {{domain}}, {{days_left}} days left to keep your domain', 'Still outstanding', 'Your payment is still outstanding', '<p>We retried your payment for <strong>{{domain}}</strong> and it was declined again. You have <strong>{{days_left}} days left</strong> (until {{grace_end}}).</p><p>If it is not paid by then the plan ends automatically. You would lose the domain and the {{paid}} already paid, and the plan cannot be reinstated.</p>', "Domain|{{domain}}\nDeadline|{{grace_end}}", 'Update my card now', 'warn' ),
			'plan_failed_3'    => db_mail_plan_tpl( 'Plan payment failed, notice 3 (to the buyer)', 'Day 6: urgent.', 'URGENT: {{domain}} will be released in {{days_left}} days', 'Urgent', 'Your domain is at risk', '<p>Your payment for <strong>{{domain}}</strong> is overdue. <strong>Only {{days_left}} days remain</strong> before the plan is terminated on {{grace_end}}.</p><p>If that happens the domain goes back on the market, the {{paid}} in previous payments is forfeited and you would have to buy the domain again at the then-current price.</p><p>If you cannot pay in time, contact us today. We can only discuss options before the deadline passes.</p>', "Domain|{{domain}}\nTerminated on|{{grace_end}}", 'Update my card now', 'danger' ),
			'plan_failed_4'    => db_mail_plan_tpl( 'Plan payment failed, final notice (to the buyer)', 'Day 9: 24 hours left.', 'FINAL NOTICE: {{domain}} will be released within 24 hours', 'Final notice', '24 hours left', '<p>This is the last notice before your payment plan for <strong>{{domain}}</strong> is terminated. If we have not received {{amount}} by <strong>{{grace_end}}</strong>:</p><ul><li>the plan ends and cannot be reinstated</li><li>the domain returns to our inventory and can be sold to someone else</li><li>the {{paid}} you have paid is non-refundable</li></ul>', "Domain|{{domain}}\nTerminated on|{{grace_end}}", 'Pay now: update my card', 'danger' ),
			'plan_recovered'   => db_mail_plan_tpl( 'Plan back on track (to the buyer)', 'A late payment finally clears.', 'Thank you: your plan for {{domain}} is back on track', 'Back on track', 'Payment received', '<p>We received your payment of <strong>{{amount}}</strong> for <strong>{{domain}}</strong>. Your plan is active again and nothing more is needed.</p><p>Your next payment is charged one month from today.</p>', "Domain|{{domain}}\nPaid so far|{{paid}}\nPayments remaining|{{remaining}}", '', 'good' ),
			'plan_defaulted'   => db_mail_plan_tpl( 'Plan terminated (to the buyer)', 'Day 10: the grace period ended unpaid.', 'Your payment plan for {{domain}} has been terminated', 'Plan ended', 'Your payment plan has been terminated', '<p>We could not collect payment {{number}} of {{months}} for <strong>{{domain}}</strong> within the {{grace_days}}-day grace period, despite our reminders. As set out in the plan terms you accepted, the plan has ended.</p><ul><li>The domain returns to our inventory. It was never transferred to you.</li><li>The {{paid}} paid to date is non-refundable and is not credited to any other purchase.</li><li>No more payments will be charged on this plan.</li></ul><p>If you still want the domain, contact us. If it is still available we can discuss a new purchase at the current price.</p>', "Domain|{{domain}}\nTotal paid (non-refundable)|{{paid}}", '', 'danger' ),
			'transfer_code'    => array(
				'group' => 'Payments', 'label' => 'Transfer code ready (to the buyer)', 'to' => 'customer', 'critical' => true,
				'when'  => 'The team sends the buyer the secure link to the transfer (EPP) code.',
				'vars'  => $dom + array( 'note_html' => array( 'Optional note from us', '' ), 'days' => array( 'Days the link works', '14' ), 'code_url' => array( 'The private page', home_url( '/' ) ) ),
				'subject' => 'Your transfer code for {{domain}}', 'eyebrow' => 'Domain transfer', 'heading' => 'Your transfer code is ready',
				'body'  => '<p>The transfer (EPP / unlock) code for <strong>{{domain}}</strong> is ready. For your security it is not in this email: open the private page below and press "Show my code".</p>{{note_html}}<p style="font-size:14px">The link works for {{days}} days. Please do not forward it.</p>',
				'details' => 'Domain|{{domain}}', 'cta' => 'Get my transfer code', 'cta_var' => 'code_url', 'tone' => 'good',
			),
			// ── Team notices ────────────────────────────────────────────────
			'admin_sale'       => array(
				'group' => 'Team notices', 'label' => 'Domain sold (to the team)', 'to' => 'team', 'critical' => false,
				'when'  => 'A sale or the start of a payment plan is recorded.',
				'vars'  => $dom + array( 'amount' => array( 'Amount', '$8,000.00' ), 'type' => array( 'Type', 'Full purchase' ), 'customer' => array( 'Customer email', 'buyer@example.com' ), 'time' => array( 'When', 'March 3, 2026 10:15' ), 'admin_url' => array( 'Admin link', admin_url() ) ),
				'subject' => 'DOMAIN SOLD: {{domain}} for {{amount}}', 'eyebrow' => 'New sale', 'heading' => '{{domain}} sold',
				'body'  => '<p>A domain sale has been recorded. Review the details and start the transfer.</p>',
				'details' => "Domain|{{domain}}\nAmount|{{amount}}\nType|{{type}}\nCustomer|{{customer}}\nTime|{{time}}", 'cta' => 'Open the dashboard', 'cta_var' => 'admin_url', 'tone' => 'good',
			),
			'admin_plan_payment' => array(
				'group' => 'Team notices', 'label' => 'Plan payment (to the team)', 'to' => 'team', 'critical' => false,
				'when'  => 'A payment plan starts or an instalment is paid.',
				'vars'  => $dom + array( 'headline' => array( 'Plan started / Payment 3 of 12', 'Plan started' ), 'amount' => array( 'Amount', '$733.00' ), 'plan' => array( 'Plan summary', '12 months, 0% interest, total $8,800.00' ), 'customer' => array( 'Customer', 'buyer@example.com' ), 'time' => array( 'When', 'March 3, 2026 10:15' ), 'subject_line' => array( 'Subject line', 'PLAN STARTED: brandable.com' ) ),
				'subject' => '{{subject_line}}', 'eyebrow' => 'Payment plan', 'heading' => '{{headline}}: {{domain}}',
				'body'  => '<p>A payment plan payment was received.</p>',
				'details' => "Domain|{{domain}}\nPayment|{{amount}}\nPlan|{{plan}}\nCustomer|{{customer}}\nTime|{{time}}", 'cta' => '', 'cta_var' => '', 'tone' => 'good',
			),
			'admin_payment_failed' => array(
				'group' => 'Team notices', 'label' => 'Payment failed (to the team)', 'to' => 'team', 'critical' => false,
				'when'  => 'A customer payment is declined.',
				'vars'  => $dom + array( 'amount' => array( 'Amount', '$733.00' ), 'customer' => array( 'Customer', 'buyer@example.com' ), 'time' => array( 'When', 'March 3, 2026 10:15' ), 'admin_url' => array( 'Admin link', admin_url() ) ),
				'subject' => 'PAYMENT FAILED: {{domain}}', 'eyebrow' => 'Payment failed', 'heading' => 'A payment failed on {{domain}}',
				'body'  => '<p>The customer has been told automatically. Follow up if needed.</p>',
				'details' => "Domain|{{domain}}\nAmount|{{amount}}\nCustomer|{{customer}}\nTime|{{time}}", 'cta' => 'Open the dashboard', 'cta_var' => 'admin_url', 'tone' => 'danger',
			),
			'admin_offer'      => array(
				'group' => 'Team notices', 'label' => 'New offer or reply (to the team)', 'to' => 'team', 'critical' => false,
				'when'  => 'A buyer makes an offer, accepts or declines.',
				'vars'  => $dom + array( 'what' => array( 'What happened', 'New offer from a buyer' ), 'buyer' => array( 'Buyer email', 'buyer@example.com' ), 'price' => array( 'Price', '$1,500' ), 'state' => array( 'Status', 'Waiting for us' ), 'message_html' => array( 'The buyer\'s message', '<p>Interested for my startup.</p>' ), 'respond_url' => array( 'Where to reply', admin_url() ), 'subject_line' => array( 'Subject line', 'Offer: brandable.com (Waiting for us)' ) ),
				'subject' => '{{subject_line}}', 'eyebrow' => 'Offer', 'heading' => '{{what}}',
				'body'  => '{{message_html}}',
				'details' => "Domain|{{domain}}\nBuyer|{{buyer}}\nPrice|{{price}}\nStatus|{{state}}", 'cta' => 'Respond', 'cta_var' => 'respond_url', 'tone' => 'neutral',
			),
			'admin_alert'      => array(
				'group' => 'Team notices', 'label' => 'Payment and plan alerts (to the team)', 'to' => 'team', 'critical' => false,
				'when'  => 'Plan problems, terminated plans, charge errors and payments needing review.',
				'vars'  => array( 'subject_line' => array( 'Subject line', 'PLAN TERMINATED: brandable.com' ), 'title' => array( 'Headline', 'PLAN TERMINATED' ), 'lines_html' => array( 'The facts', '<p>Domain: brandable.com</p>' ) ),
				'subject' => '{{subject_line}}', 'eyebrow' => 'Alert', 'heading' => '{{title}}',
				'body'  => '{{lines_html}}',
				'details' => '', 'cta' => '', 'cta_var' => '', 'tone' => 'warn',
			),
			'admin_enquiry'    => array(
				'group' => 'Team notices', 'label' => 'Not-for-sale enquiry (to the team)', 'to' => 'team', 'critical' => false,
				'when'  => 'Someone asks about a domain that is marked not for sale.',
				'vars'  => $dom + array( 'email' => array( 'Their email', 'asker@example.com' ), 'message_html' => array( 'Their message', '<p>Would the owner sell?</p>' ) ),
				'subject' => 'Not-for-sale enquiry: {{domain}}', 'eyebrow' => 'Enquiry', 'heading' => 'Someone asked about {{domain}}',
				'body'  => '{{message_html}}',
				'details' => "Domain|{{domain}}\nEmail|{{email}}", 'cta' => '', 'cta_var' => '', 'tone' => 'neutral',
			),
		);
		return $r;
	}

	/** Shared defaults for the plan lifecycle emails. */
	function db_mail_plan_tpl( $label, $when, $subject, $eyebrow, $heading, $body, $details, $cta, $tone ) {
		return array(
			'group' => 'Payments', 'label' => $label, 'to' => 'customer', 'critical' => true, 'when' => $when,
			'vars'  => array( 'domain' => array( 'The domain name', 'brandable.com' ), 'number' => array( 'Payment number', '4' ), 'months' => array( 'Number of payments', '12' ), 'amount' => array( 'Amount', '$733.00' ), 'paid' => array( 'Paid so far', '$2,199.00' ), 'remaining' => array( 'Payments remaining', '8' ), 'due_date' => array( 'Date of the next charge', 'March 3, 2026' ), 'grace_end' => array( 'Deadline', 'March 13, 2026' ), 'grace_days' => array( 'Grace period in days', '10' ), 'days_left' => array( 'Days left', '4' ), 'card_url' => array( 'Link to update the card', home_url( '/' ) ) ),
			'subject' => $subject, 'eyebrow' => $eyebrow, 'heading' => $heading, 'body' => $body, 'details' => $details, 'cta' => $cta, 'cta_var' => '' !== $cta ? 'card_url' : '', 'tone' => $tone,
		);
	}
}

/* ───────────────────────────── 4. Compose and send ────────────────────── */

if ( ! function_exists( 'db_mail_overrides' ) ) {
	function db_mail_overrides() {
		$o = get_option( 'db_mail_tpl', array() );
		return is_array( $o ) ? $o : array();
	}
}

if ( ! function_exists( 'db_mail_template' ) ) {
	/** The template as it is now: the registry default with the administrator's changes on top. */
	function db_mail_template( $key ) {
		$reg = db_mail_registry();
		if ( ! isset( $reg[ $key ] ) ) {
			return null;
		}
		$t   = $reg[ $key ];
		$ov  = db_mail_overrides()[ $key ] ?? array();
		foreach ( array( 'subject', 'eyebrow', 'heading', 'body', 'details', 'cta', 'hero' ) as $f ) {
			if ( isset( $ov[ $f ] ) ) {
				$t[ $f ] = (string) $ov[ $f ];
			}
		}
		$t['hero']    = $t['hero'] ?? '';
		$t['enabled'] = ! isset( $ov['enabled'] ) || ! empty( $ov['enabled'] ) || ! empty( $t['critical'] );
		$t['edited']  = ! empty( $ov );
		return $t;
	}
}

if ( ! function_exists( 'db_mail_common_vars' ) ) {
	function db_mail_common_vars() {
		return array(
			'site_name'     => 'Domain Brothers',
			'site_url'      => home_url( '/' ),
			'support_email' => defined( 'DB_PLAN_SUPPORT_EMAIL' ) ? DB_PLAN_SUPPORT_EMAIL : 'sales@domainbrothers.com',
			'year'          => gmdate( 'Y' ),
		);
	}
}

if ( ! function_exists( 'db_mail_compose' ) ) {
	/**
	 * @param array|null $override Fields from the editor (live preview), used instead of the saved template.
	 * @return array|null subject, inner (HTML to be wrapped), pre, enabled; null for an unknown key
	 */
	function db_mail_compose( $key, array $vars, $override = null ) {
		$t = db_mail_template( $key );
		if ( ! $t ) {
			return null;
		}
		if ( is_array( $override ) ) {
			foreach ( array( 'subject', 'eyebrow', 'heading', 'body', 'details', 'cta', 'hero' ) as $f ) {
				if ( isset( $override[ $f ] ) ) {
					$t[ $f ] = (string) $override[ $f ];
				}
			}
		}
		$v       = $vars + db_mail_common_vars();
		$details = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $t['details'] ) as $line ) {
			if ( false === strpos( $line, '|' ) ) {
				continue;
			}
			list( $label, $val ) = array_map( 'trim', explode( '|', $line, 2 ) );
			$val   = db_mail_fill( $val, $v, 'text' );
			$label = db_mail_fill( $label, $v, 'text' );
			if ( '' !== $label && '' !== $val ) {
				$details[ $label ] = $val;
			}
		}
		$cta_url = '';
		if ( '' !== (string) $t['cta_var'] && isset( $v[ $t['cta_var'] ] ) ) {
			$cta_url = (string) $v[ $t['cta_var'] ];
		}
		$body = db_mail_fill( wp_kses_post( (string) $t['body'] ), $v, 'html' );
		$inner = db_mail_inner( array(
			'hero'      => (string) $t['hero'],
			'eyebrow'   => db_mail_fill( $t['eyebrow'], $v, 'text' ),
			'heading'   => db_mail_fill( $t['heading'], $v, 'text' ),
			'body_html' => $body,
			'details'   => $details,
			'cta_label' => '' !== $cta_url ? db_mail_fill( $t['cta'], $v, 'text' ) : '',
			'cta_url'   => $cta_url,
			'tone'      => $t['tone'],
		) );
		$subject = trim( db_mail_fill( $t['subject'], $v, 'text' ) );
		return array(
			'subject' => '' !== $subject ? $subject : 'Domain Brothers',
			'inner'   => $inner,
			'pre'     => db_mail_fill( $t['heading'], $v, 'text' ),
			'enabled' => $t['enabled'],
		);
	}
}

if ( ! function_exists( 'db_mail_send' ) ) {
	/**
	 * Sends a template. Returns true when sent (or switched off by the administrator),
	 * false when sending failed, and null when the key is unknown so the caller can fall back.
	 */
	function db_mail_send( $key, $to, array $vars, $reply_to = '' ) {
		$m = db_mail_compose( $key, $vars );
		if ( null === $m ) {
			return null;
		}
		if ( ! $m['enabled'] || ! is_email( $to ) ) {
			return ! is_email( $to ) ? false : true;
		}
		$GLOBALS['db_mail_pre'] = $m['pre'];
		return (bool) db_send_branded_email( $to, $m['subject'], $m['inner'], $reply_to );
	}
}

if ( ! function_exists( 'db_mail_local_url' ) ) {
	/** A link is only put in an email when it points at this site. */
	function db_mail_local_url( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$u    = wp_parse_url( (string) $url );
		if ( empty( $u['host'] ) || empty( $home['host'] ) || 0 !== strcasecmp( $u['host'], $home['host'] ) || ! in_array( $u['scheme'] ?? '', array( 'https', 'http' ), true ) ) {
			return '';
		}
		return (string) $url;
	}
}

if ( ! function_exists( 'db_mail_team_to' ) ) {
	function db_mail_team_to() {
		return (string) get_option( 'admin_email' );
	}
}

if ( ! function_exists( 'db_mail_money' ) ) {
	function db_mail_money( $n, $dec = 2 ) {
		return '$' . number_format( (float) $n, $dec );
	}
}

/* ───────────────────────────── 5. Admin: Email templates ──────────────── */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Email templates', 'Email templates', 'manage_options', 'db-email-templates', 'db_mail_admin_page' );
}, 27 );

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( false === strpos( (string) $hook, 'db-email-templates' ) ) {
		return;
	}
	wp_enqueue_media();
	wp_enqueue_style( 'wp-color-picker' );
	wp_enqueue_script( 'wp-color-picker' );
} );

if ( ! function_exists( 'db_mail_admin_url' ) ) {
	function db_mail_admin_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => 'db-email-templates' ), $args ), admin_url( 'admin.php' ) );
	}
}

if ( ! function_exists( 'db_mail_sample_vars' ) ) {
	function db_mail_sample_vars( $key ) {
		$reg = db_mail_registry();
		$out = array();
		foreach ( (array) ( $reg[ $key ]['vars'] ?? array() ) as $name => $def ) {
			$out[ $name ] = (string) $def[1];
		}
		return $out;
	}
}

if ( ! function_exists( 'db_mail_admin_page' ) ) {
	function db_mail_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$tpl = isset( $_GET['tpl'] ) ? sanitize_key( wp_unslash( $_GET['tpl'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<style>.db-mail-admin input.regular-text,.db-mail-admin input.large-text{max-width:100%;box-sizing:border-box}.db-mail-admin form{min-width:0}</style><div class="wrap db-mail-admin"><h1>Email templates</h1>';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( 'reset' === $_GET['saved'] ? 'Template reset to the original.' : ( 'sent' === $_GET['saved'] ? 'Test email sent.' : ( 'failed' === $_GET['saved'] ? 'The test email could not be sent. Check the email settings.' : 'Saved.' ) ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		echo '<h2 class="nav-tab-wrapper"><a class="nav-tab' . ( '' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( db_mail_admin_url() ) . '">Templates</a><a class="nav-tab' . ( 'design' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( db_mail_admin_url( array( 'tab' => 'design' ) ) ) . '">Design: logo, colours, fonts</a></h2>';
		if ( 'design' === $tab ) {
			db_mail_admin_design();
		} elseif ( '' !== $tpl && isset( db_mail_registry()[ $tpl ] ) ) {
			db_mail_admin_edit( $tpl );
		} else {
			db_mail_admin_list();
		}
		echo '</div>';
	}
}

if ( ! function_exists( 'db_mail_admin_list' ) ) {
	function db_mail_admin_list() {
		$groups = array();
		foreach ( db_mail_registry() as $k => $t ) {
			$groups[ $t['group'] ][ $k ] = $t;
		}
		echo '<p>Every email the website sends. Click one to change its subject, headline, text, details, button or image, with a live preview. The logo, colours and fonts for all emails are on the Design tab.</p>';
		foreach ( $groups as $g => $list ) {
			echo '<h2 style="margin-top:26px">' . esc_html( $g ) . '</h2><table class="widefat striped"><thead><tr><th style="width:30%">Email</th><th>Sent when</th><th style="width:110px">To</th><th style="width:110px">Status</th><th style="width:90px"></th></tr></thead><tbody>';
			foreach ( $list as $k => $t ) {
				$now = db_mail_template( $k );
				$st  = ! $now['enabled'] ? '<span style="color:#b32d2e">Switched off</span>' : ( $now['edited'] ? '<strong>Edited</strong>' : 'Original' );
				echo '<tr><td><strong><a href="' . esc_url( db_mail_admin_url( array( 'tpl' => $k ) ) ) . '">' . esc_html( $t['label'] ) . '</a></strong></td><td>' . esc_html( $t['when'] ) . '</td><td>' . esc_html( 'team' === $t['to'] ? 'Our team' : 'Customer' ) . '</td><td>' . wp_kses_post( $st ) . '</td><td><a class="button button-small" href="' . esc_url( db_mail_admin_url( array( 'tpl' => $k ) ) ) . '">Edit</a></td></tr>';
			}
			echo '</tbody></table>';
		}
	}
}

if ( ! function_exists( 'db_mail_admin_edit' ) ) {
	function db_mail_admin_edit( $key ) {
		$reg = db_mail_registry()[ $key ];
		$t   = db_mail_template( $key );
		echo '<p><a href="' . esc_url( db_mail_admin_url() ) . '">&larr; All templates</a></p><h2>' . esc_html( $reg['label'] ) . '</h2><p class="description">' . esc_html( $reg['when'] ) . '</p>';
		echo '<div style="display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:28px;align-items:start;max-width:1500px">';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" id="db-mail-form"><input type="hidden" name="action" value="db_mail_save"><input type="hidden" name="tpl" value="' . esc_attr( $key ) . '">';
		wp_nonce_field( 'db_mail_save_' . $key );
		echo '<table class="form-table" role="presentation">';
		$row = static function ( $label, $html ) {
			echo '<tr><th scope="row" style="width:130px">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		if ( empty( $reg['critical'] ) ) {
			$row( 'Sending', '<label><input type="checkbox" name="enabled" value="1"' . checked( $t['enabled'], true, false ) . '> Send this email</label>' );
		} else {
			$row( 'Sending', '<em>Always sent. It is part of a payment or sign-in.</em>' );
		}
		$row( 'Subject', '<input type="text" class="large-text" name="subject" value="' . esc_attr( $t['subject'] ) . '">' );
		$row( 'Small title', '<input type="text" class="regular-text" name="eyebrow" value="' . esc_attr( $t['eyebrow'] ) . '"><p class="description">The small coloured line above the headline.</p>' );
		$row( 'Headline', '<input type="text" class="large-text" name="heading" value="' . esc_attr( $t['heading'] ) . '">' );
		$row( 'Image', '<input type="text" class="regular-text" name="hero" id="db-mail-hero" value="' . esc_attr( $t['hero'] ) . '" placeholder="https://"> <button type="button" class="button" id="db-mail-hero-pick">Choose image</button><p class="description">Optional picture above the headline (about 1000 px wide).</p>' );
		echo '</table><h3>Text</h3>';
		wp_editor( wp_kses_post( $t['body'] ), 'db_mail_body', array( 'textarea_name' => 'body', 'textarea_rows' => 12, 'media_buttons' => false, 'teeny' => false, 'quicktags' => true, 'tinymce' => array( 'toolbar1' => 'formatselect,bold,italic,link,bullist,numlist,undo,redo', 'toolbar2' => '' ) ) );
		echo '<table class="form-table" role="presentation">';
		$row( 'Details', '<textarea name="details" rows="5" class="large-text code" placeholder="Label|{{placeholder}}">' . esc_textarea( $t['details'] ) . '</textarea><p class="description">One per line as <code>Label|value</code>. They show in a rounded panel.</p>' );
		$row( 'Button', '<input type="text" class="regular-text" name="cta" value="' . esc_attr( $t['cta'] ) . '">' . ( '' !== (string) $reg['cta_var'] ? '<p class="description">Links to <code>{{' . esc_html( $reg['cta_var'] ) . '}}</code>. Leave the label empty for no button.</p>' : '<p class="description">This email has no button.</p>' ) );
		echo '</table>';
		echo '<p class="submit"><button class="button button-primary" type="submit">Save template</button> <button class="button" type="button" id="db-mail-refresh">Update preview</button></p></form>';

		echo '<div><h3 style="margin-top:0">Preview</h3><iframe id="db-mail-preview" title="Email preview" style="width:100%;height:760px;border:1px solid #c3c4c7;border-radius:8px;background:#fff" srcdoc="' . esc_attr( db_mail_preview_html( $key ) ) . '"></iframe>';
		echo '<h3>Placeholders</h3><table class="widefat striped"><tbody>';
		foreach ( $reg['vars'] as $name => $def ) {
			echo '<tr><td><code>{{' . esc_html( $name ) . '}}</code></td><td>' . esc_html( $def[0] ) . '</td></tr>';
		}
		foreach ( array( 'site_name' => 'Site name', 'support_email' => 'Support email address', 'site_url' => 'Home page address' ) as $n => $d ) {
			echo '<tr><td><code>{{' . esc_html( $n ) . '}}</code></td><td>' . esc_html( $d ) . '</td></tr>';
		}
		echo '</tbody></table><p class="description">Use <code>{{name_url}}</code> style names exactly as shown. Placeholders ending in <code>_html</code> hold ready-made formatted text.</p>';
		echo '<h3>Send a test</h3><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="db_mail_test"><input type="hidden" name="tpl" value="' . esc_attr( $key ) . '">';
		wp_nonce_field( 'db_mail_test_' . $key );
		echo '<input type="email" name="to" class="regular-text" value="' . esc_attr( wp_get_current_user()->user_email ) . '"> <button class="button" type="submit">Send test email</button></form>';
		if ( $t['edited'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:14px" onsubmit="return confirm(\'Reset this template to the original?\')"><input type="hidden" name="action" value="db_mail_reset"><input type="hidden" name="tpl" value="' . esc_attr( $key ) . '">';
			wp_nonce_field( 'db_mail_reset_' . $key );
			echo '<button class="button-link-delete" type="submit">Reset to the original</button></form>';
		}
		echo '</div></div>';
		$nonce = wp_create_nonce( 'db_mail_preview' );
		?>
<script>
(function () {
	var form = document.getElementById('db-mail-form'), frame = document.getElementById('db-mail-preview');
	function body() { return (window.tinyMCE && tinyMCE.get('db_mail_body') && !tinyMCE.get('db_mail_body').isHidden()) ? tinyMCE.get('db_mail_body').getContent() : document.getElementById('db_mail_body').value; }
	function refresh() {
		var fd = new FormData(form);
		fd.set('body', body()); fd.set('action', 'db_mail_preview'); fd.set('_wpnonce', <?php echo wp_json_encode( $nonce ); ?>);
		fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (h) { frame.srcdoc = h; });
	}
	document.getElementById('db-mail-refresh').addEventListener('click', refresh);
	var t; form.addEventListener('input', function () { clearTimeout(t); t = setTimeout(refresh, 700); });
	var pick = document.getElementById('db-mail-hero-pick');
	if (pick && window.wp && wp.media) { pick.addEventListener('click', function () { var m = wp.media({ title: 'Choose image', multiple: false, library: { type: 'image' } }); m.on('select', function () { document.getElementById('db-mail-hero').value = m.state().get('selection').first().toJSON().url; refresh(); }); m.open(); }); }
})();
</script>
		<?php
	}
}

if ( ! function_exists( 'db_mail_preview_html' ) ) {
	function db_mail_preview_html( $key, $override = null ) {
		$m = db_mail_compose( $key, db_mail_sample_vars( $key ), $override );
		if ( ! $m ) {
			return '';
		}
		$GLOBALS['db_mail_pre'] = $m['pre'];
		return db_mail_wrap( $m['subject'], $m['inner'] );
	}
}

add_action( 'wp_ajax_db_mail_preview', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'db_mail_preview', '_wpnonce', false ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	$key = isset( $_POST['tpl'] ) ? sanitize_key( wp_unslash( $_POST['tpl'] ) ) : '';
	if ( ! isset( db_mail_registry()[ $key ] ) ) {
		wp_die( 'Unknown template.', 404 );
	}
	$ov = db_mail_clean_post();
	if ( isset( $_POST['design'] ) ) {
		$ov = array();
	}
	header( 'Content-Type: text/html; charset=utf-8' );
	echo db_mail_preview_html( $key, $ov ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
} );

if ( ! function_exists( 'db_mail_clean_post' ) ) {
	/** The editor's fields, cleaned. Text fields are plain text; the body is limited to safe formatting tags. */
	function db_mail_clean_post() {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$p = wp_unslash( $_POST );
		// phpcs:enable
		return array(
			'subject' => sanitize_text_field( $p['subject'] ?? '' ),
			'eyebrow' => sanitize_text_field( $p['eyebrow'] ?? '' ),
			'heading' => sanitize_text_field( $p['heading'] ?? '' ),
			'hero'    => esc_url_raw( (string) ( $p['hero'] ?? '' ) ),
			'cta'     => sanitize_text_field( $p['cta'] ?? '' ),
			'details' => sanitize_textarea_field( $p['details'] ?? '' ),
			'body'    => wp_kses_post( (string) ( $p['body'] ?? '' ) ),
		);
	}
}

add_action( 'admin_post_db_mail_save', function () {
	$key = isset( $_POST['tpl'] ) ? sanitize_key( wp_unslash( $_POST['tpl'] ) ) : '';
	if ( ! current_user_can( 'manage_options' ) || ! isset( db_mail_registry()[ $key ] ) || ! check_admin_referer( 'db_mail_save_' . $key ) ) {
		wp_die( 'Not allowed.' );
	}
	$all         = db_mail_overrides();
	$new         = db_mail_clean_post();
	$new['enabled'] = ! empty( $_POST['enabled'] ) ? 1 : 0;
	$all[ $key ] = $new;
	update_option( 'db_mail_tpl', $all, false );
	wp_safe_redirect( db_mail_admin_url( array( 'tpl' => $key, 'saved' => 1 ) ) );
	exit;
} );

add_action( 'admin_post_db_mail_reset', function () {
	$key = isset( $_POST['tpl'] ) ? sanitize_key( wp_unslash( $_POST['tpl'] ) ) : '';
	if ( ! current_user_can( 'manage_options' ) || ! isset( db_mail_registry()[ $key ] ) || ! check_admin_referer( 'db_mail_reset_' . $key ) ) {
		wp_die( 'Not allowed.' );
	}
	$all = db_mail_overrides();
	unset( $all[ $key ] );
	update_option( 'db_mail_tpl', $all, false );
	wp_safe_redirect( db_mail_admin_url( array( 'tpl' => $key, 'saved' => 'reset' ) ) );
	exit;
} );

add_action( 'admin_post_db_mail_test', function () {
	$key = isset( $_POST['tpl'] ) ? sanitize_key( wp_unslash( $_POST['tpl'] ) ) : '';
	if ( ! current_user_can( 'manage_options' ) || ! isset( db_mail_registry()[ $key ] ) || ! check_admin_referer( 'db_mail_test_' . $key ) ) {
		wp_die( 'Not allowed.' );
	}
	$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
	$ok = false;
	if ( is_email( $to ) ) {
		$m = db_mail_compose( $key, db_mail_sample_vars( $key ) );
		$GLOBALS['db_mail_pre'] = $m['pre'];
		$ok = (bool) db_send_branded_email( $to, '[Test] ' . $m['subject'], $m['inner'] );
	}
	wp_safe_redirect( db_mail_admin_url( array( 'tpl' => $key, 'saved' => $ok ? 'sent' : 'failed' ) ) );
	exit;
} );

if ( ! function_exists( 'db_mail_admin_design' ) ) {
	function db_mail_admin_design() {
		$d = db_mail_design();
		$s = get_option( 'db_mail_design', array() );
		$s = is_array( $s ) ? $s : array();
		echo '<p>These settings style every email the site sends. Pick the template to see it in the preview.</p>';
		echo '<div style="display:grid;grid-template-columns:minmax(0,420px) minmax(0,1fr);gap:28px;align-items:start;max-width:1500px"><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="db_mail_design_save">';
		wp_nonce_field( 'db_mail_design_save' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>Logo</th><td><input type="text" class="regular-text" name="logo_url" id="db-mail-logo" value="' . esc_attr( $s['logo_url'] ?? '' ) . '" placeholder="Uses the site logo when empty"> <button type="button" class="button" id="db-mail-logo-pick">Choose logo</button></td></tr>';
		echo '<tr><th>Logo width</th><td><input type="number" min="80" max="320" name="logo_width" value="' . esc_attr( (string) $d['logo_width'] ) . '"> px</td></tr>';
		foreach ( array( 'primary' => 'Primary colour', 'secondary' => 'Secondary colour', 'btn_bg' => 'Button colour', 'btn_text' => 'Button text', 'bg' => 'Page background', 'card' => 'Card background', 'ink' => 'Headline text', 'muted' => 'Body text' ) as $k => $label ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td><input type="text" class="db-mail-color" name="' . esc_attr( $k ) . '" value="' . esc_attr( $d[ $k ] ) . '" data-default-color="' . esc_attr( db_mail_design_defaults()[ $k ] ) . '"></td></tr>';
		}
		echo '<tr><th>Font</th><td><select name="font">';
		foreach ( db_mail_fonts() as $k => $f ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $d['font'], $k, false ) . '>' . esc_html( $f[0] ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th>Tagline</th><td><input type="text" class="large-text" name="tagline" value="' . esc_attr( $d['tagline'] ) . '"></td></tr>';
		echo '<tr><th>Footer text</th><td><textarea name="footer_text" rows="3" class="large-text">' . esc_textarea( $d['footer_text'] ) . '</textarea></td></tr>';
		echo '<tr><th>Company line</th><td><input type="text" class="regular-text" name="address" value="' . esc_attr( $d['address'] ) . '"></td></tr></table>';
		echo '<p class="submit"><button class="button button-primary" type="submit">Save design</button></p></form>';
		$keys = array_keys( db_mail_registry() );
		echo '<div><p><label>Preview with <select id="db-mail-pick">';
		foreach ( db_mail_registry() as $k => $t ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( 'purchase_full', $k, false ) . '>' . esc_html( $t['label'] ) . '</option>';
		}
		echo '</select></label></p><iframe id="db-mail-preview" title="Email preview" style="width:100%;height:820px;border:1px solid #c3c4c7;border-radius:8px;background:#fff" srcdoc="' . esc_attr( db_mail_preview_html( 'purchase_full' ) ) . '"></iframe></div></div>';
		$nonce = wp_create_nonce( 'db_mail_preview' );
		?>
<script>
jQuery(function ($) {
	$('.db-mail-color').wpColorPicker({ change: function () { setTimeout(refresh, 50); }, clear: function () { setTimeout(refresh, 50); } });
	var form = $('form[action$="admin-post.php"]').first()[0], frame = document.getElementById('db-mail-preview'), pick = document.getElementById('db-mail-pick');
	function refresh() {
		var fd = new FormData(form); fd.set('action', 'db_mail_preview_design'); fd.set('tpl', pick.value); fd.set('_wpnonce', <?php echo wp_json_encode( $nonce ); ?>);
		fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (h) { frame.srcdoc = h; });
	}
	pick.addEventListener('change', refresh);
	$(form).on('input change', refresh);
	$('#db-mail-logo-pick').on('click', function () { var m = wp.media({ title: 'Choose logo', multiple: false, library: { type: 'image' } }); m.on('select', function () { $('#db-mail-logo').val(m.state().get('selection').first().toJSON().url); refresh(); }); m.open(); });
});
</script>
		<?php
	}
}

if ( ! function_exists( 'db_mail_clean_design' ) ) {
	function db_mail_clean_design( array $p ) {
		$def = db_mail_design_defaults();
		$out = array();
		$out['logo_url']    = esc_url_raw( (string) ( $p['logo_url'] ?? '' ) );
		$out['logo_width']  = max( 80, min( 320, (int) ( $p['logo_width'] ?? 168 ) ) );
		foreach ( array( 'primary', 'secondary', 'btn_bg', 'btn_text', 'bg', 'card', 'ink', 'muted' ) as $k ) {
			$out[ $k ] = db_mail_hex( $p[ $k ] ?? '', $def[ $k ] );
		}
		$out['font']        = isset( db_mail_fonts()[ $p['font'] ?? '' ] ) ? $p['font'] : 'system';
		$out['tagline']     = sanitize_text_field( (string) ( $p['tagline'] ?? '' ) );
		$out['footer_text'] = sanitize_textarea_field( (string) ( $p['footer_text'] ?? '' ) );
		$out['address']     = sanitize_text_field( (string) ( $p['address'] ?? '' ) );
		return $out;
	}
}

// Live preview of unsaved design changes: the design is applied for this one request only.
add_action( 'wp_ajax_db_mail_preview_design', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'db_mail_preview', '_wpnonce', false ) ) {
		wp_die( 'Not allowed.', 403 );
	}
	$key = isset( $_POST['tpl'] ) ? sanitize_key( wp_unslash( $_POST['tpl'] ) ) : '';
	if ( ! isset( db_mail_registry()[ $key ] ) ) {
		wp_die( 'Unknown template.', 404 );
	}
	$clean = db_mail_clean_design( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	add_filter( 'pre_option_db_mail_design', static function () use ( $clean ) {
		return $clean;
	} );
	header( 'Content-Type: text/html; charset=utf-8' );
	echo db_mail_preview_html( $key ); // phpcs:ignore WordPress.Security.EscapeOutput
	exit;
} );

add_action( 'admin_post_db_mail_design_save', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_mail_design_save' ) ) {
		wp_die( 'Not allowed.' );
	}
	update_option( 'db_mail_design', db_mail_clean_design( wp_unslash( $_POST ) ), false ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	wp_safe_redirect( db_mail_admin_url( array( 'tab' => 'design', 'saved' => 1 ) ) );
	exit;
} );

// Staging cannot send mail, so the last email to each address is kept for an hour for the administrators-only test route.
add_filter( 'wp_mail', function ( $args ) {
	if ( function_exists( 'db_wl_is_staging' ) && db_wl_is_staging() ) {
		foreach ( (array) $args['to'] as $to ) {
			set_transient( 'db_mail_last_' . md5( strtolower( trim( (string) $to ) ) ), array( 'subject' => (string) $args['subject'], 'html' => (string) $args['message'] ), HOUR_IN_SECONDS );
		}
	}
	return $args;
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/email/last', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return function_exists( 'db_wl_is_staging' ) && db_wl_is_staging() && current_user_can( 'manage_options' );
		},
		'callback'            => function ( WP_REST_Request $req ) {
			$to = strtolower( trim( (string) $req->get_param( 'to' ) ) );
			$m  = get_transient( 'db_mail_last_' . md5( $to ) );
			return new WP_REST_Response( is_array( $m ) ? $m : array( 'subject' => '', 'html' => '' ) );
		},
	) );
} );
