<?php
/* === DB make-an-offer flow ===
 *
 * Issue #39 — Make-a-Custom-Offer flow.
 *
 * Two fixes, both for the Contact Form 7 "make an offer" form:
 *   1. CUSTOMER CONFIRMATION EMAIL — send a branded "we received your offer"
 *      email to the person who submitted the offer. (CF7 "Mail" goes to admin;
 *      "Mail (2)" can do this too, but doing it in code guarantees it is branded
 *      and survives form edits.)
 *   2. REDIRECT FIX — the offer form currently redirects to the PRODUCTION
 *      /thank-you/. Send it to the BETA thank-you with offer context instead:
 *      /thank-you/?type=offer&domain=<domain>  (the thank-you page already
 *      handles ?type=offer — see db_thankyou_block.php).
 *
 * CONFIG — confirm these against the live form before deploying:
 *   - DB_OFFER_FORM_ID   : the CF7 form post ID of the offer form. Leave 0 to
 *                          auto-detect the first form whose title contains
 *                          "offer" (case-insensitive).
 *   - field names        : db_offer_field_map() maps logical fields to the CF7
 *                          field names actually used on the form.
 *
 * Requires Contact Form 7. Routes the confirmation through wp_mail() so the
 * branded wrapper in db_email_template_block.php styles it automatically.
 *
 * Paste at the end of functions.php. Self-contained.
 */

if ( ! defined( 'DB_OFFER_FORM_ID' ) ) {
	define( 'DB_OFFER_FORM_ID', 0 ); // 0 = auto-detect by title containing "offer"
}

/* Map logical fields -> possible CF7 field names (first match wins). */
function db_offer_field_map() {
	return array(
		'email'  => array( 'your-email', 'email', 'offer-email', 'customer-email' ),
		'name'   => array( 'your-name', 'name', 'offer-name', 'customer-name' ),
		'domain' => array( 'your-domain', 'domain', 'offer-domain', 'domain-name' ),
		'amount' => array( 'your-offer', 'offer', 'amount', 'offer-amount', 'price' ),
	);
}

/* Pull the first present value for a logical field from CF7 posted data. */
function db_offer_value( $data, $logical ) {
	$map = db_offer_field_map();
	if ( empty( $map[ $logical ] ) ) {
		return '';
	}
	foreach ( $map[ $logical ] as $name ) {
		if ( isset( $data[ $name ] ) ) {
			$val = is_array( $data[ $name ] ) ? reset( $data[ $name ] ) : $data[ $name ];
			$val = trim( (string) $val );
			if ( '' !== $val ) {
				return $val;
			}
		}
	}
	return '';
}

/* Decide whether a given CF7 form is the offer form. */
function db_is_offer_form( $contact_form ) {
	if ( ! $contact_form ) {
		return false;
	}
	if ( DB_OFFER_FORM_ID > 0 ) {
		return (int) $contact_form->id() === (int) DB_OFFER_FORM_ID;
	}
	$title = method_exists( $contact_form, 'title' ) ? $contact_form->title() : '';
	return ( false !== stripos( $title, 'offer' ) );
}

/* 1) Send the branded customer confirmation when an offer is submitted. */
add_action( 'wpcf7_mail_sent', function ( $contact_form ) {
	if ( ! db_is_offer_form( $contact_form ) ) {
		return;
	}
	$submission = class_exists( 'WPCF7_Submission' ) ? WPCF7_Submission::get_instance() : null;
	if ( ! $submission ) {
		return;
	}
	$data = $submission->get_posted_data();

	$email  = db_offer_value( $data, 'email' );
	$name   = db_offer_value( $data, 'name' );
	$domain = db_offer_value( $data, 'domain' );
	$amount = db_offer_value( $data, 'amount' );

	if ( ! is_email( $email ) ) {
		return; // nothing to send to
	}

	$greeting = $name ? 'Hi ' . $name . ',' : 'Hi,';
	$dom_line = $domain ? '<strong>' . esc_html( $domain ) . '</strong>' : 'the domain';
	$amt_line = $amount ? ' of <strong>' . esc_html( $amount ) . '</strong>' : '';

	$subject = $domain
		? 'We received your offer for ' . $domain
		: 'We received your offer';

	$body  = '<p>' . esc_html( $greeting ) . '</p>';
	$body .= '<p>Thank you for your interest in ' . $dom_line . '. We have received your offer' . $amt_line . ' and our team is reviewing it now.</p>';
	$body .= '<p>You can expect a personal reply from us shortly. If your offer is accepted, we will send secure checkout instructions to complete the purchase.</p>';
	$body .= '<p>If you have any questions in the meantime, simply reply to this email.</p>';
	$body .= '<p>&mdash; The Domain Brothers Team</p>';

	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	// wp_mail goes through the branded wrapper + SMTP routing blocks.
	wp_mail( $email, $subject, $body, $headers );
}, 10, 1 );

/* 2) Redirect the offer form to the BETA thank-you page with offer context.
 *
 * Expose the offer form ID + correct thank-you base to JS, then listen for the
 * CF7 'wpcf7mailsent' DOM event and redirect there. This overrides any
 * hard-coded production redirect configured elsewhere.
 */
add_action( 'wp_footer', function () {
	// Resolve the offer form id for the JS guard.
	$form_id = (int) DB_OFFER_FORM_ID;
	if ( $form_id === 0 && function_exists( 'WPCF7_ContactForm' ) ) {
		// Best-effort: find a form whose title contains "offer".
		$forms = get_posts( array(
			'post_type'      => 'wpcf7_contact_form',
			'posts_per_page' => 50,
			'fields'         => 'ids',
		) );
		foreach ( $forms as $fid ) {
			if ( false !== stripos( get_the_title( $fid ), 'offer' ) ) {
				$form_id = (int) $fid;
				break;
			}
		}
	}

	$thankyou = esc_url( home_url( '/thank-you/' ) );
	$map      = db_offer_field_map();
	?>
	<script>
	(function () {
		var DB_OFFER_FORM_ID = <?php echo (int) $form_id; ?>;
		var DB_THANKYOU = <?php echo wp_json_encode( $thankyou ); ?>;
		var DOMAIN_FIELDS = <?php echo wp_json_encode( array_values( $map['domain'] ) ); ?>;

		document.addEventListener('wpcf7mailsent', function (event) {
			// Only act on the offer form (when we know its id).
			if (DB_OFFER_FORM_ID && event.detail && event.detail.contactFormId &&
			    parseInt(event.detail.contactFormId, 10) !== DB_OFFER_FORM_ID) {
				return;
			}
			var domain = '';
			try {
				var inputs = (event.detail && event.detail.inputs) || [];
				for (var i = 0; i < inputs.length; i++) {
					if (DOMAIN_FIELDS.indexOf(inputs[i].name) !== -1 && inputs[i].value) {
						domain = inputs[i].value;
						break;
					}
				}
			} catch (e) {}

			var url = DB_THANKYOU + '?type=offer';
			if (domain) {
				url += '&domain=' + encodeURIComponent(domain);
			}
			window.location = url;
		}, false);
	})();
	</script>
	<?php
}, 99 );
/* === end DB make-an-offer flow === */
