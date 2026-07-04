<?php
/**
 * DB Email + Thank-You Block — Phase 3
 *
 * Included via require_once from db-custom-blocks.php.
 *
 * PART A — Branded Email System
 *   db_mail_wrap(), db_send_branded_email()
 *   db_mail_send_sale_receipt(), db_mail_send_admin_alert()
 *   db_mail_send_payment_failed(), db_mail_send_payment_failed_admin()
 *   db_mail_send_offer_ack()
 *   CF7 wpcf7_mail_components filter
 *
 * PART B — Thank-You Page
 *   the_content filter (thank-you page), db_ty_html(), db_ty_styles()
 *   [db_thankyou] shortcode
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

/* ===========================================================
   PART A — BRANDED EMAIL SYSTEM
   =========================================================== */

/* -----------------------------------------------------------
   A1. db_mail_wrap() — returns a full branded HTML email string
   ----------------------------------------------------------- */

if ( ! function_exists( 'db_mail_wrap' ) ) {
	function db_mail_wrap( $subject, $body_html, $from_name = 'Domain Brothers', $from_email = 'sales@domainbrothers.com' ) {
		$year      = gmdate( 'Y' );
		$title_esc = esc_html( $subject );
		$name_esc  = esc_html( $from_name );
		$contact   = esc_url( home_url( '/contact/' ) );

		$html  = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">';
		$html .= '<html xmlns="http://www.w3.org/1999/xhtml"><head>';
		$html .= '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">';
		$html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
		$html .= '<title>' . $title_esc . '</title>';
		$html .= '</head>';
		$html .= '<body style="margin:0;padding:0;background-color:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">';

		// Outer table
		$html .= '<table class="db-email-wrapper" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color:#f4f6f9;padding:40px 20px;">';
		$html .= '<tr><td align="center">';

		// Container
		$html .= '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;background-color:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">';

		// Header — dark navy #08173A as specified
		$html .= '<tr><td style="background-color:#08173A;padding:32px 40px;text-align:center;">';
		$html .= '<h1 style="margin:0 0 6px 0;font-size:26px;font-weight:700;color:#ffffff;letter-spacing:-0.03em;">' . $name_esc . '</h1>';
		$html .= '<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.60);letter-spacing:0.10em;text-transform:uppercase;">Premium Domain Brokerage</p>';
		$html .= '</td></tr>';

		// Body
		$html .= '<tr><td style="padding:36px 40px;background-color:#ffffff;font-size:15px;line-height:1.68;color:#374151;">';
		$html .= $body_html;
		$html .= '</td></tr>';

		// Footer
		$html .= '<tr><td style="background-color:#f8f9fb;padding:24px 40px;border-top:1px solid #e5e7eb;text-align:center;">';
		$html .= '<p style="margin:0 0 6px 0;font-size:13px;color:#9ca3af;">&copy; ' . esc_html( $year ) . ' Domain Brothers. All rights reserved.</p>';
		$html .= '<p style="margin:0;font-size:12px;color:#c4c9d4;">You received this email because of a transaction or inquiry with Domain Brothers. ';
		$html .= 'Received in error? <a href="' . $contact . '" style="color:#6b7280;text-decoration:underline;">Contact us</a>.</p>';
		$html .= '</td></tr>';

		$html .= '</table>'; // container
		$html .= '</td></tr></table>'; // outer
		$html .= '</body></html>';

		return $html;
	}
}

/* -----------------------------------------------------------
   A2. db_send_branded_email() — wraps and sends via wp_mail()
   ----------------------------------------------------------- */

if ( ! function_exists( 'db_send_branded_email' ) ) {
	function db_send_branded_email( $to, $subject, $body_html, $reply_to = '' ) {
		$from_name  = defined( 'DB_SG_NAME' ) ? DB_SG_NAME : 'Domain Brothers';
		$from_email = defined( 'DB_SG_FROM' ) ? DB_SG_FROM : 'sales@domainbrothers.com';

		$wrapped = db_mail_wrap( $subject, $body_html, $from_name, $from_email );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from_name . ' <' . $from_email . '>',
		);
		if ( $reply_to && is_email( $reply_to ) ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return wp_mail( $to, $subject, $wrapped, $headers );
	}
}

/* -----------------------------------------------------------
   A3. db_mail_send_sale_receipt() — customer purchase / plan receipt
   ----------------------------------------------------------- */

if ( ! function_exists( 'db_mail_send_sale_receipt' ) ) {
	function db_mail_send_sale_receipt( $to_email, $domain, $amount_usd, $type = 'full', $plan_months = 0 ) {
		$domain_safe = esc_html( $domain );
		$amount_fmt  = '$' . number_format( (float) $amount_usd, 2 );
		$type_label  = ( 'plan' === $type ) ? 'Payment Plan' : 'Full Purchase';
		$date_str    = gmdate( 'F j, Y' );
		$subject     = 'Your domain ' . $domain . ' is secured — Domain Brothers';

		// Order summary table (inline CSS for Gmail compatibility)
		$tbl  = '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:20px 0;">';
		$tbl .= '<tr><th colspan="2" style="padding:10px 14px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:0.07em;color:#6b7280;background:#f8f9fb;border-bottom:1px solid #e5e7eb;">Order Summary</th></tr>';
		foreach ( array( 'Domain' => $domain_safe, 'Amount' => esc_html( $amount_fmt ), 'Type' => esc_html( $type_label ), 'Date' => esc_html( $date_str ) ) as $label => $val ) {
			$tbl .= '<tr><td style="padding:9px 14px;font-size:14px;color:#6b7280;border-bottom:1px solid #f3f4f6;width:40%;">' . esc_html( $label ) . '</td>';
			$tbl .= '<td style="padding:9px 14px;font-size:14px;color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6;text-align:right;">' . $val . '</td></tr>';
		}
		$tbl .= '</table>';

		// "What happens next" steps
		$steps_html  = '<h3 style="margin:24px 0 12px;font-size:16px;font-weight:700;color:#111827;">What Happens Next</h3>';
		$steps_html .= '<table border="0" cellpadding="0" cellspacing="0" width="100%">';
		$steps = array(
			array( 'n' => 1, 'title' => 'Transfer initiated',         'sub' => 'Our team starts the domain transfer within 1 business day.' ),
			array( 'n' => 2, 'title' => 'Authorization email sent',   'sub' => 'You receive a transfer authorization email to approve the move.' ),
			array( 'n' => 3, 'title' => 'Domain live in your account','sub' => 'Once approved, transfer completes within 2&ndash;5 days.' ),
		);
		foreach ( $steps as $s ) {
			$steps_html .= '<tr><td valign="top" style="padding:8px 0;width:36px;">';
			$steps_html .= '<span style="display:inline-block;width:26px;height:26px;border-radius:50%;background:#08173A;color:#fff;font-size:12px;font-weight:700;text-align:center;line-height:26px;">' . esc_html( (string) $s['n'] ) . '</span>';
			$steps_html .= '</td><td valign="top" style="padding:8px 0 8px 10px;">';
			$steps_html .= '<strong style="color:#111827;">' . esc_html( $s['title'] ) . '</strong><br>';
			$steps_html .= '<span style="font-size:13px;color:#6b7280;">' . $s['sub'] . '</span></td></tr>';
		}
		$steps_html .= '</table>';

		if ( 'plan' === $type ) {
			$monthly    = ( $plan_months > 0 ) ? '$' . number_format( (float) $amount_usd / (int) $plan_months, 2 ) : $amount_fmt;
			$body  = '<p style="font-size:20px;font-weight:700;color:#111827;margin:0 0 12px;">Your payment plan is active!</p>';
			$body .= '<p>Your payment plan for <strong>' . $domain_safe . '</strong> has started. Your <strong>' . esc_html( $monthly ) . '/month</strong> plan over <strong>' . (int) $plan_months . ' months</strong> has been activated.</p>';
			$body .= '<p>Domain transfer begins after your final payment. Payment schedule and details have been recorded; you will receive reminders before each installment.</p>';
		} else {
			$body  = '<p style="font-size:20px;font-weight:700;color:#111827;margin:0 0 12px;">Congratulations! Your purchase is confirmed.</p>';
			$body .= '<p>Your purchase of <strong>' . $domain_safe . '</strong> for <strong>' . esc_html( $amount_fmt ) . '</strong> is confirmed. Our team will initiate the domain transfer within 1&ndash;2 business days.</p>';
			$body .= '<p>You will receive transfer instructions at this email address. Contact us at <a href="mailto:sales@domainbrothers.com" style="color:#0a6ed1;">sales@domainbrothers.com</a> with any questions.</p>';
		}

		$body .= $tbl . $steps_html;
		$body .= '<p style="margin-top:20px;font-size:13px;color:#6b7280;">Questions? Reply to this email or write to <a href="mailto:sales@domainbrothers.com" style="color:#0a6ed1;">sales@domainbrothers.com</a>.</p>';

		return db_send_branded_email( $to_email, $subject, $body );
	}
}

/* -----------------------------------------------------------
   A4. db_mail_send_admin_alert() — internal sale notification
   ----------------------------------------------------------- */

if ( ! function_exists( 'db_mail_send_admin_alert' ) ) {
	function db_mail_send_admin_alert( $domain, $amount_usd, $type, $customer_email, $plan_months = 0 ) {
		$admin_email = defined( 'DB_SG_FROM' ) ? DB_SG_FROM : 'sales@domainbrothers.com';
		$amount_fmt  = '$' . number_format( (float) $amount_usd, 2 );
		$type_label  = ( 'plan' === $type ) ? 'Payment Plan' : 'Full Purchase';
		$subject     = 'DOMAIN SOLD: ' . $domain . ' — ' . $amount_fmt . ' (' . $type_label . ')';
		$date_str    = gmdate( 'Y-m-d H:i:s T' );

		$rows = array(
			'Domain'         => esc_html( $domain ),
			'Customer Email' => esc_html( $customer_email ),
			'Amount'         => esc_html( $amount_fmt ),
			'Type'           => esc_html( $type_label ),
			'Date / Time'    => esc_html( $date_str ),
		);
		if ( $plan_months > 0 ) {
			$rows['Plan Months'] = esc_html( (string) $plan_months );
		}

		$tbl  = '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:20px 0;">';
		$tbl .= '<tr><th colspan="2" style="padding:10px 14px;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:0.07em;color:#6b7280;background:#f8f9fb;border-bottom:1px solid #e5e7eb;">Sale Details</th></tr>';
		foreach ( $rows as $label => $val ) {
			$tbl .= '<tr><td style="padding:9px 14px;font-size:14px;color:#6b7280;border-bottom:1px solid #f3f4f6;width:38%;">' . esc_html( $label ) . '</td>';
			$tbl .= '<td style="padding:9px 14px;font-size:14px;color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6;">' . $val . '</td></tr>';
		}
		$tbl .= '</table>';

		$body  = '<p style="font-size:20px;font-weight:700;color:#137a3e;margin:0 0 12px;">&#127881; Domain Sold!</p>';
		$body .= '<p>A new domain sale has been recorded. Review the details below and initiate the transfer process.</p>';
		$body .= $tbl;
		$body .= '<p style="margin-top:20px;"><a href="' . esc_url( admin_url() ) . '" style="display:inline-block;background:#08173A;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;">View WP Admin &rarr;</a></p>';

		return db_send_branded_email( $admin_email, $subject, $body );
	}
}

/* -----------------------------------------------------------
   A5. db_mail_send_payment_failed() — payment failed, to customer
   ----------------------------------------------------------- */

if ( ! function_exists( 'db_mail_send_payment_failed' ) ) {
	function db_mail_send_payment_failed( $to_email, $domain, $amount_usd ) {
		$domain_safe = esc_html( $domain );
		$amount_fmt  = '$' . number_format( (float) $amount_usd, 2 );
		$subject     = 'Action needed: Payment failed for ' . $domain;
		$domain_url  = esc_url( home_url( '/' ) );

		$body  = '<p style="font-size:20px;font-weight:700;color:#c0392b;margin:0 0 12px;">&#9888; Payment Failed</p>';
		$body .= '<p>We were unable to process your payment of <strong>' . esc_html( $amount_fmt ) . '</strong> for <strong>' . $domain_safe . '</strong>.</p>';
		$body .= '<p>Please update your payment method to avoid any disruption to your payment plan and to maintain your reservation on this domain.</p>';
		$body .= '<p style="margin:24px 0;"><a href="' . $domain_url . '" style="display:inline-block;background:#c0392b;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;">Update Payment Method &rarr;</a></p>';
		$body .= '<p style="font-size:13px;color:#6b7280;">Believe this is an error? Contact us at <a href="mailto:sales@domainbrothers.com" style="color:#0a6ed1;">sales@domainbrothers.com</a> immediately.</p>';

		return db_send_branded_email( $to_email, $subject, $body );
	}
}

/* -----------------------------------------------------------
   A6. db_mail_send_payment_failed_admin() — payment failed, to admin
   ----------------------------------------------------------- */

if ( ! function_exists( 'db_mail_send_payment_failed_admin' ) ) {
	function db_mail_send_payment_failed_admin( $domain, $customer_email, $amount_usd ) {
		$admin_email = defined( 'DB_SG_FROM' ) ? DB_SG_FROM : 'sales@domainbrothers.com';
		$amount_fmt  = '$' . number_format( (float) $amount_usd, 2 );
		$subject     = 'PAYMENT FAILED: ' . $domain . ' — ' . $amount_fmt;
		$date_str    = gmdate( 'Y-m-d H:i:s T' );

		$tbl  = '<table border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse;margin:20px 0;">';
		$tbl .= '<tr><td style="padding:9px 14px;font-size:14px;color:#6b7280;border-bottom:1px solid #f3f4f6;width:38%;">Domain</td><td style="padding:9px 14px;font-size:14px;color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6;">' . esc_html( $domain ) . '</td></tr>';
		$tbl .= '<tr><td style="padding:9px 14px;font-size:14px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Customer</td><td style="padding:9px 14px;font-size:14px;color:#111827;font-weight:600;border-bottom:1px solid #f3f4f6;">' . esc_html( $customer_email ) . '</td></tr>';
		$tbl .= '<tr><td style="padding:9px 14px;font-size:14px;color:#6b7280;border-bottom:1px solid #f3f4f6;">Failed Amount</td><td style="padding:9px 14px;font-size:14px;color:#c0392b;font-weight:700;border-bottom:1px solid #f3f4f6;">' . esc_html( $amount_fmt ) . '</td></tr>';
		$tbl .= '<tr><td style="padding:9px 14px;font-size:14px;color:#6b7280;">Date / Time</td><td style="padding:9px 14px;font-size:14px;color:#111827;font-weight:600;">' . esc_html( $date_str ) . '</td></tr>';
		$tbl .= '</table>';

		$body  = '<p style="font-size:20px;font-weight:700;color:#c0392b;margin:0 0 12px;">&#9888; Payment Failed</p>';
		$body .= '<p>A payment plan payment has failed. The customer has been notified automatically. Please follow up if needed.</p>';
		$body .= $tbl;
		$body .= '<p style="margin-top:20px;"><a href="' . esc_url( admin_url() ) . '" style="display:inline-block;background:#08173A;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:600;font-size:14px;">View WP Admin &rarr;</a></p>';

		return db_send_branded_email( $admin_email, $subject, $body );
	}
}

/* -----------------------------------------------------------
   A7. db_mail_send_offer_ack() — acknowledgment to offer submitter
   ----------------------------------------------------------- */

if ( ! function_exists( 'db_mail_send_offer_ack' ) ) {
	function db_mail_send_offer_ack( $to_email, $domain, $offer_amount = '' ) {
		$domain_safe = esc_html( $domain );
		$subject     = 'We received your offer — Domain Brothers';

		$body  = '<p style="font-size:20px;font-weight:700;color:#111827;margin:0 0 12px;">Thank you for your interest!</p>';
		$body .= '<p>We\'ve received your offer for <strong>' . $domain_safe . '</strong> and our team will review it within <strong>24 hours</strong>.</p>';

		if ( $offer_amount ) {
			$amt_display = '$' . ltrim( preg_replace( '/[^0-9.,]/', '', (string) $offer_amount ), '$' );
			$body .= '<div style="background:#f0f9f4;border-left:4px solid #137a3e;padding:12px 16px;border-radius:4px;margin:16px 0;font-size:15px;">';
			$body .= '<strong>Your offer:</strong> ' . esc_html( $amt_display );
			$body .= '</div>';
		}

		$body .= '<p>You\'ll hear back from us at <strong>' . esc_html( $to_email ) . '</strong>. Our team personally reviews every offer and responds with a decision or counter-offer.</p>';
		$body .= '<p>Questions in the meantime? Contact us at <a href="mailto:sales@domainbrothers.com" style="color:#0a6ed1;">sales@domainbrothers.com</a>.</p>';
		$body .= '<p style="margin-top:20px;">&#8212; The Domain Brothers Team</p>';

		return db_send_branded_email( $to_email, $subject, $body, 'sales@domainbrothers.com' );
	}
}

/* -----------------------------------------------------------
   A8. CF7 filter — wrap outgoing CF7 emails in branded template
   ----------------------------------------------------------- */

add_filter( 'wpcf7_mail_components', function ( $components, $cf7 ) {
	// Only wrap offer and contact forms.
	$title = ( is_object( $cf7 ) && method_exists( $cf7, 'title' ) ) ? strtolower( (string) $cf7->title() ) : '';
	if ( false === strpos( $title, 'offer' ) && false === strpos( $title, 'contact' ) ) {
		return $components;
	}

	// Prevent double-wrapping.
	if ( ! empty( $components['body'] ) && false !== strpos( (string) $components['body'], 'db-email-wrapper' ) ) {
		return $components;
	}

	if ( ! empty( $components['body'] ) ) {
		$from_name  = defined( 'DB_SG_NAME' ) ? DB_SG_NAME : 'Domain Brothers';
		$from_email = defined( 'DB_SG_FROM' ) ? DB_SG_FROM : 'sales@domainbrothers.com';
		$subj       = ! empty( $components['subject'] ) ? (string) $components['subject'] : 'Message — Domain Brothers';
		$raw_body   = (string) $components['body'];

		// Convert plain text to basic HTML if no tags present.
		$body_html = ( false === strpos( $raw_body, '<' ) )
			? '<p>' . nl2br( esc_html( $raw_body ) ) . '</p>'
			: $raw_body;

		$components['body'] = db_mail_wrap( $subj, $body_html, $from_name, $from_email );
	}

	// Ensure HTML content-type header.
	$existing_headers = ! empty( $components['additional_headers'] ) ? (string) $components['additional_headers'] : '';
	if ( false === stripos( $existing_headers, 'content-type' ) ) {
		$components['additional_headers'] = trim( $existing_headers . "\nContent-Type: text/html; charset=UTF-8" );
	}

	return $components;
}, 10, 2 );


/* ===========================================================
   PART B — THANK-YOU PAGE
   =========================================================== */

/* -----------------------------------------------------------
   B1. the_content filter — inject thank-you content on /thank-you/
   ----------------------------------------------------------- */

add_filter( 'the_content', function ( $content ) {
	static $done = false;
	if ( $done || is_admin() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( ! is_page( array( 'thank-you', 'thankyou' ) ) ) {
		return $content;
	}

	$type = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'full';
	if ( ! in_array( $type, array( 'full', 'plan', 'offer' ), true ) ) {
		$type = 'full';
	}

	$domain = '';
	if ( ! empty( $_GET['domain'] ) ) {
		$raw    = base64_decode( wp_unslash( sanitize_text_field( wp_unslash( $_GET['domain'] ) ) ), true );
		$domain = ( false !== $raw && '' !== $raw ) ? sanitize_text_field( $raw ) : sanitize_text_field( wp_unslash( $_GET['domain'] ) );
	}

	$amount = '';
	if ( ! empty( $_GET['amount'] ) ) {
		$raw    = base64_decode( wp_unslash( sanitize_text_field( wp_unslash( $_GET['amount'] ) ) ), true );
		$amount = ( false !== $raw && preg_match( '/[0-9]/', (string) $raw ) ) ? sanitize_text_field( $raw ) : sanitize_text_field( wp_unslash( $_GET['amount'] ) );
	}

	$done = true;
	return db_ty_html( $type, $domain, $amount ) . $content;
}, 10 );

/* -----------------------------------------------------------
   B2. db_ty_html() — returns the full thank-you section HTML
   ----------------------------------------------------------- */

if ( ! function_exists( 'db_ty_html' ) ) {
	function db_ty_html( $type, $domain, $amount ) {
		$domain_safe = $domain ? esc_html( $domain ) : '';
		$amount_safe = '';
		if ( $amount ) {
			$clean = preg_replace( '/[^0-9.]/', '', $amount );
			$amount_safe = $clean ? '$' . number_format( (float) $clean, 0 ) : esc_html( $amount );
		}

		// Green checkmark SVG
		$check_svg  = '<svg class="db-ty-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="Success">';
		$check_svg .= '<circle cx="32" cy="32" r="32" fill="#137a3e"/>';
		$check_svg .= '<path d="M18 32l10 10 18-20" stroke="#fff" stroke-width="4.5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>';
		$check_svg .= '</svg>';

		// Service chips — reuse db_services_list() if available
		$services = function_exists( 'db_services_list' ) ? db_services_list() : array(
			'Website Design & Development' => '/website-design-development/',
			'Digital Marketing'            => '/digital-marketing/',
			'Software Development'         => '/software-development/',
			'Mobile App Development'       => '/mobile-app-development/',
			'Other Services'               => '/other-services/',
		);

		$chips = '<div class="db-ty-chips">';
		foreach ( $services as $label => $path ) {
			$chips .= '<a class="db-ty-chip" href="' . esc_url( home_url( $path ) ) . '">' . esc_html( $label ) . '</a>';
		}
		$chips .= '</div>';

		$html  = '<div class="db-ty-wrap">';
		$html .= '<div class="db-ty-card">';

		/* ---- type=full ---- */
		if ( 'full' === $type ) {
			$html .= $check_svg;
			$html .= '<h1 class="db-ty-headline">You\'re the new owner' . ( $domain_safe ? ' of <span class="db-ty-domain">' . $domain_safe . '</span>' : '' ) . '</h1>';
			$sub = $amount_safe
				? 'Your payment of <strong>' . esc_html( $amount_safe ) . '</strong> has been confirmed. Domain transfer begins within 1&ndash;2 business days.'
				: 'Your payment has been confirmed. Domain transfer begins within 1&ndash;2 business days.';
			$html .= '<p class="db-ty-sub">' . $sub . '</p>';

			$html .= '<div class="db-ty-timeline" role="list">';
			$steps = array(
				array( 'icon' => '&#9989;',    'label' => 'Payment confirmed',              'sub' => '',                         'done' => true ),
				array( 'icon' => '&#x1F504;',  'label' => 'Transfer initiated',             'sub' => 'within 24 hours',          'done' => false ),
				array( 'icon' => '&#x1F4E7;',  'label' => 'Authorization email sent to you','sub' => '',                         'done' => false ),
				array( 'icon' => '&#x1F310;',  'label' => 'Domain live in your account',   'sub' => '2&ndash;5 days',           'done' => false ),
			);
			foreach ( $steps as $step ) {
				$cls = 'db-ty-step' . ( $step['done'] ? ' db-ty-step--done' : '' );
				$html .= '<div class="' . esc_attr( $cls ) . '" role="listitem">';
				$html .= '<div class="db-ty-step-icon" aria-hidden="true">' . $step['icon'] . '</div>';
				$html .= '<div class="db-ty-step-body"><strong>' . esc_html( $step['label'] ) . '</strong>';
				if ( $step['sub'] ) { $html .= '<span class="db-ty-step-sub">' . $step['sub'] . '</span>'; }
				$html .= '</div></div>';
			}
			$html .= '</div>'; // timeline

			$html .= '<a class="db-ty-cta" href="' . esc_url( home_url( '/' ) ) . '">Browse More Domains &rarr;</a>';
		}

		/* ---- type=plan ---- */
		elseif ( 'plan' === $type ) {
			$html .= $check_svg;
			$html .= '<h1 class="db-ty-headline">Your payment plan is active' . ( $domain_safe ? ' &mdash; <span class="db-ty-domain">' . $domain_safe . '</span>' : '' ) . '</h1>';
			$sub = $domain_safe
				? 'Your first payment has been processed. <strong>' . $domain_safe . '</strong> will be transferred after your final installment.'
				: 'Your first payment has been processed. The domain will be transferred after your final installment.';
			$html .= '<p class="db-ty-sub">' . $sub . '</p>';

			$html .= '<div class="db-ty-infobox">';
			$html .= '<strong>&#128274; Secure escrow protection throughout.</strong><br>';
			$html .= 'Domain transfer occurs after your final payment. Your domain is held securely during the full plan term.';
			$html .= '</div>';

			$html .= '<a class="db-ty-cta" href="' . esc_url( home_url( '/services/' ) ) . '">View Our Services &rarr;</a>';

			$html .= '<div class="db-ty-svc-links"><strong>Quick links:</strong>';
			foreach ( $services as $label => $path ) {
				$html .= ' <a href="' . esc_url( home_url( $path ) ) . '">' . esc_html( $label ) . '</a>';
			}
			$html .= '</div>';
		}

		/* ---- type=offer ---- */
		else {
			$html .= '<div class="db-ty-icon db-ty-icon--letter" aria-hidden="true">&#128235;</div>';
			$html .= '<h1 class="db-ty-headline">Offer received &mdash; we\'ll be in touch' . ( $domain_safe ? ' about <span class="db-ty-domain">' . $domain_safe . '</span>' : '' ) . '</h1>';
			$html .= '<p class="db-ty-sub">Thank you for your interest' . ( $domain_safe ? ' in <strong>' . $domain_safe . '</strong>' : '' ) . '. Our team reviews all offers within <strong>24 hours</strong>.</p>';

			$html .= '<div class="db-ty-timeline" role="list">';
			$offer_steps = array(
				array( 'icon' => '&#x1F4CB;', 'label' => 'We review your offer',            'sub' => 'within 24 hours' ),
				array( 'icon' => '&#x1F4E7;', 'label' => 'You receive our response by email','sub' => '' ),
				array( 'icon' => '&#x1F91D;', 'label' => 'We negotiate &amp; close',        'sub' => 'secure transfer via escrow' ),
			);
			foreach ( $offer_steps as $step ) {
				$html .= '<div class="db-ty-step" role="listitem">';
				$html .= '<div class="db-ty-step-icon" aria-hidden="true">' . $step['icon'] . '</div>';
				$html .= '<div class="db-ty-step-body"><strong>' . $step['label'] . '</strong>';
				if ( $step['sub'] ) { $html .= '<span class="db-ty-step-sub">' . $step['sub'] . '</span>'; }
				$html .= '</div></div>';
			}
			$html .= '</div>'; // timeline

			$html .= '<a class="db-ty-cta" href="' . esc_url( home_url( '/' ) ) . '">Browse Other Domains &rarr;</a>';
		}

		$html .= '</div>'; // db-ty-card

		// "You might also like" service chips (shared)
		$html .= '<div class="db-ty-also">';
		$html .= '<p class="db-ty-also-label">You might also like</p>';
		$html .= $chips;
		$html .= '</div>';

		$html .= '</div>'; // db-ty-wrap

		$html .= db_ty_styles();

		return $html;
	}
}

/* -----------------------------------------------------------
   B3. db_ty_styles() — scoped CSS, theme-aware, mobile-responsive
   ----------------------------------------------------------- */

if ( ! function_exists( 'db_ty_styles' ) ) {
	function db_ty_styles() {
		ob_start();
		?>
<style id="db-ty-css">
/* ============================================================
   DB Thank-You Block — Scoped to .db-ty-*
   Theme-aware via CSS custom properties + prefers-color-scheme
   ============================================================ */

:root {
	--db-ty-bg:          #f4f6f9;
	--db-ty-card-bg:     #ffffff;
	--db-ty-border:      #e5e7eb;
	--db-ty-text:        #374151;
	--db-ty-heading:     #111827;
	--db-ty-muted:       #6b7280;
	--db-ty-green:       #137a3e;
	--db-ty-green-lt:    #e7f6ec;
	--db-ty-navy:        #08173A;
	--db-ty-blue:        #0a6ed1;
	--db-ty-blue-lt:     #e8f0fb;
	--db-ty-info-bg:     #eff6ff;
	--db-ty-info-border: #bfdbfe;
	--db-ty-step-line:   #d1d5db;
	--db-ty-chip-bg:     #f3f4f6;
	--db-ty-chip-text:   #374151;
	--db-ty-chip-hover:  #dbeafe;
}
@media (prefers-color-scheme: dark) {
	:root {
		--db-ty-bg:          #111827;
		--db-ty-card-bg:     #1f2937;
		--db-ty-border:      #374151;
		--db-ty-text:        #d1d5db;
		--db-ty-heading:     #f9fafb;
		--db-ty-muted:       #9ca3af;
		--db-ty-green-lt:    #052e16;
		--db-ty-info-bg:     #1e3a5f;
		--db-ty-info-border: #3b82f6;
		--db-ty-step-line:   #4b5563;
		--db-ty-chip-bg:     #374151;
		--db-ty-chip-text:   #d1d5db;
		--db-ty-chip-hover:  #1e3a5f;
	}
}
:root[data-theme="dark"]  { --db-ty-bg:#111827; --db-ty-card-bg:#1f2937; --db-ty-border:#374151; --db-ty-text:#d1d5db; --db-ty-heading:#f9fafb; --db-ty-muted:#9ca3af; --db-ty-green-lt:#052e16; --db-ty-info-bg:#1e3a5f; --db-ty-info-border:#3b82f6; --db-ty-step-line:#4b5563; --db-ty-chip-bg:#374151; --db-ty-chip-text:#d1d5db; --db-ty-chip-hover:#1e3a5f; }
:root[data-theme="light"] { --db-ty-bg:#f4f6f9; --db-ty-card-bg:#ffffff; --db-ty-border:#e5e7eb; --db-ty-text:#374151; --db-ty-heading:#111827; --db-ty-muted:#6b7280; --db-ty-green-lt:#e7f6ec; --db-ty-info-bg:#eff6ff; --db-ty-info-border:#bfdbfe; --db-ty-step-line:#d1d5db; --db-ty-chip-bg:#f3f4f6; --db-ty-chip-text:#374151; --db-ty-chip-hover:#dbeafe; }

.db-ty-wrap {
	max-width: 680px;
	margin: 0 auto 48px;
	padding: 0 16px;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

/* ---- Card ---- */
.db-ty-card {
	background: var(--db-ty-card-bg);
	border: 1px solid var(--db-ty-border);
	border-radius: 18px;
	padding: clamp(28px, 6vw, 52px);
	box-shadow: 0 4px 28px rgba(0,0,0,0.07);
	margin-bottom: 20px;
	text-align: center;
}

/* ---- Icon ---- */
.db-ty-icon {
	width: 72px;
	height: 72px;
	margin: 0 auto 22px;
	display: block;
}
.db-ty-icon--letter {
	font-size: 62px;
	line-height: 1;
	width: auto;
	height: auto;
}

/* ---- Headline ---- */
.db-ty-headline {
	font-size: clamp(21px, 4vw, 31px);
	font-weight: 700;
	color: var(--db-ty-heading);
	line-height: 1.22;
	letter-spacing: -0.025em;
	margin: 0 0 14px;
}
.db-ty-domain { color: var(--db-ty-blue); }

/* ---- Sub ---- */
.db-ty-sub {
	font-size: 16px;
	line-height: 1.65;
	color: var(--db-ty-text);
	margin: 0 auto 28px;
	max-width: 500px;
}

/* ---- Timeline ---- */
.db-ty-timeline {
	text-align: left;
	max-width: 420px;
	margin: 0 auto 28px;
	position: relative;
}
.db-ty-timeline::before {
	content: "";
	position: absolute;
	left: 19px;
	top: 30px;
	bottom: 30px;
	width: 2px;
	background: var(--db-ty-step-line);
	border-radius: 2px;
}
.db-ty-step {
	display: flex;
	align-items: flex-start;
	gap: 16px;
	padding: 8px 0;
	position: relative;
}
.db-ty-step-icon {
	width: 40px;
	height: 40px;
	flex-shrink: 0;
	background: var(--db-ty-card-bg);
	border: 2px solid var(--db-ty-border);
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 17px;
	position: relative;
	z-index: 1;
	transition: border-color 0.2s;
}
.db-ty-step--done .db-ty-step-icon {
	background: var(--db-ty-green-lt);
	border-color: var(--db-ty-green);
}
.db-ty-step-body {
	display: flex;
	flex-direction: column;
	gap: 2px;
	padding-top: 9px;
	font-size: 15px;
	color: var(--db-ty-heading);
}
.db-ty-step-sub {
	font-size: 12px;
	color: var(--db-ty-muted);
	font-weight: 400;
}

/* ---- Info box (plan type) ---- */
.db-ty-infobox {
	background: var(--db-ty-info-bg);
	border: 1px solid var(--db-ty-info-border);
	border-radius: 10px;
	padding: 14px 18px;
	font-size: 14px;
	line-height: 1.6;
	color: var(--db-ty-text);
	text-align: left;
	max-width: 460px;
	margin: 0 auto 24px;
}

/* ---- CTA ---- */
.db-ty-cta {
	display: inline-block;
	background: var(--db-ty-navy);
	color: #ffffff !important;
	text-decoration: none !important;
	padding: 14px 30px;
	border-radius: 999px;
	font-size: 15px;
	font-weight: 600;
	letter-spacing: -0.01em;
	transition: opacity 0.15s ease, transform 0.15s ease;
	margin-top: 6px;
}
.db-ty-cta:hover { opacity: 0.88; transform: translateY(-1px); }

/* ---- Service links (plan type) ---- */
.db-ty-svc-links {
	font-size: 13px;
	color: var(--db-ty-muted);
	margin-top: 18px;
	line-height: 2;
}
.db-ty-svc-links a { color: var(--db-ty-blue); text-decoration: none; }
.db-ty-svc-links a:hover { text-decoration: underline; }

/* ---- Also-like section ---- */
.db-ty-also {
	background: var(--db-ty-card-bg);
	border: 1px solid var(--db-ty-border);
	border-radius: 14px;
	padding: 22px 24px;
}
.db-ty-also-label {
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.09em;
	color: var(--db-ty-muted);
	margin: 0 0 12px;
}
.db-ty-chips { display: flex; flex-wrap: wrap; gap: 8px; }
.db-ty-chip {
	display: inline-block;
	background: var(--db-ty-chip-bg);
	color: var(--db-ty-chip-text) !important;
	text-decoration: none !important;
	font-size: 13px;
	font-weight: 500;
	padding: 6px 14px;
	border-radius: 999px;
	border: 1px solid var(--db-ty-border);
	white-space: nowrap;
	transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.db-ty-chip:hover {
	background: var(--db-ty-chip-hover);
	color: var(--db-ty-blue) !important;
	border-color: var(--db-ty-blue);
}

/* ---- Responsive ---- */
@media (max-width: 480px) {
	.db-ty-card { padding: 24px 16px; }
	.db-ty-timeline { max-width: 100%; }
	.db-ty-chips { gap: 6px; }
	.db-ty-chip { font-size: 12px; padding: 5px 11px; }
	.db-ty-icon { width: 58px; height: 58px; }
}
@media (prefers-reduced-motion: reduce) {
	.db-ty-cta, .db-ty-chip, .db-ty-step-icon { transition: none !important; }
}
</style>
		<?php
		return ob_get_clean();
	}
}

/* -----------------------------------------------------------
   B4. [db_thankyou] shortcode — manual placement fallback
   ----------------------------------------------------------- */

add_shortcode( 'db_thankyou', function ( $atts ) {
	$atts = shortcode_atts(
		array( 'type' => '', 'domain' => '', 'amount' => '' ),
		$atts,
		'db_thankyou'
	);

	$type = $atts['type'] ? sanitize_key( $atts['type'] ) : ( isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : 'full' );
	if ( ! in_array( $type, array( 'full', 'plan', 'offer' ), true ) ) {
		$type = 'full';
	}

	$domain = $atts['domain'];
	if ( ! $domain && ! empty( $_GET['domain'] ) ) {
		$raw    = base64_decode( wp_unslash( sanitize_text_field( wp_unslash( $_GET['domain'] ) ) ), true );
		$domain = ( false !== $raw && '' !== $raw ) ? sanitize_text_field( $raw ) : sanitize_text_field( wp_unslash( $_GET['domain'] ) );
	}
	$domain = sanitize_text_field( $domain );

	$amount = $atts['amount'];
	if ( ! $amount && ! empty( $_GET['amount'] ) ) {
		$raw    = base64_decode( wp_unslash( sanitize_text_field( wp_unslash( $_GET['amount'] ) ) ), true );
		$amount = ( false !== $raw && preg_match( '/[0-9]/', (string) $raw ) ) ? sanitize_text_field( $raw ) : sanitize_text_field( wp_unslash( $_GET['amount'] ) );
	}
	$amount = sanitize_text_field( $amount );

	return db_ty_html( $type, $domain, $amount );
} );
