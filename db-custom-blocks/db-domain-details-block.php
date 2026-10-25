<?php
/**
 * DB Domain Details Block — real content, a way to buy, and related names on every domain page
 *
 * A crawl found every domain page carries ~77 words, and the only descriptive
 * paragraph is the same sentence for all 207 domains with the name swapped
 * in — thin and near-duplicate content, with no way to buy from the page
 * (only "Make an Offer"). Marketplaces such as GoDaddy/Afternic put the
 * price, a financing line, the domain's facts and similar names on the
 * listing itself. This adds that, computed from each domain's own data
 * (name, extension, category, price) so no copy is stored or can go stale:
 *
 *   - a facts table (extension, length, type, category, price, monthly)
 *   - an intro written from those facts (extension/length specific)
 *   - Buy now / Pay monthly buttons into the existing checkout flow
 *   - how buying works + what the plan terms are
 *   - related domains from the same category (internal links)
 *
 * Monthly figures use db_plan_rate()/db_plan_total_cents() — the same maths
 * as the plan chooser and the Stripe charge.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_dd_price' ) ) {
	function db_dd_price( $post_id ) {
		$clean = preg_replace( '/[^0-9.]/', '', (string) get_post_meta( $post_id, 'domain_price', true ) );
		return is_numeric( $clean ) && (float) $clean > 0 ? (float) $clean : 0.0;
	}
}

if ( ! function_exists( 'db_dd_monthly' ) ) {
	/** Lowest monthly payment (the 12-month plan), formatted without cents. */
	function db_dd_monthly( $price ) {
		$total = db_plan_total_cents( $price, 12 ) / 100;
		return '$' . number_format( round( $total / 12 ), 0 );
	}
}

if ( ! function_exists( 'db_dd_checkout_urls' ) ) {
	/**
	 * Buy-now and payment-plan links for a listing (the same d/p base64
	 * parameters the checkout reads; the amount charged is always looked up
	 * server-side, so the p value only selects the listing).
	 *
	 * @return array{buy:string,plan:string}
	 */
	function db_dd_checkout_urls( $name, $price ) {
		$d64 = base64_encode( (string) $name );
		$p64 = base64_encode( (string) (int) round( $price ) );
		$q   = array( 'd' => rawurlencode( $d64 ), 'p' => rawurlencode( $p64 ) );
		return array(
			'buy'  => add_query_arg( $q, home_url( '/buy-now/' ) ),
			'plan' => add_query_arg( $q, home_url( '/payment-plan-setup/' ) ),
		);
	}
}

if ( ! function_exists( 'db_dd_facts' ) ) {
	function db_dd_facts( $post ) {
		$name  = strtolower( get_the_title( $post ) );
		$dot   = strpos( $name, '.' );
		$label = false === $dot ? $name : substr( $name, 0, $dot );
		$ext   = false === $dot ? '' : substr( $name, $dot );
		$terms = get_the_terms( $post->ID, 'domain_category' );
		$cats  = ( is_array( $terms ) ) ? array_values( array_filter( wp_list_pluck( $terms, 'name' ), function ( $n ) { return ! in_array( strtolower( $n ), array( 'featured', 'latest' ), true ); } ) ) : array();
		$type  = preg_match( '/^[0-9]+$/', $label ) ? 'numeric' : ( false !== strpos( $label, '-' ) ? 'hyphenated' : ( preg_match( '/[0-9]/', $label ) ? 'letters and numbers' : 'letters only' ) );
		return array(
			'name'  => $name, 'label' => $label, 'ext' => $ext, 'len' => strlen( $label ),
			'type'  => $type, 'cats' => $cats, 'price' => db_dd_price( $post->ID ),
		);
	}
}

if ( ! function_exists( 'db_dd_intro' ) ) {
	function db_dd_intro( array $f ) {
		$n   = esc_html( $f['name'] );
		$len = (int) $f['len'];
		$cat = $f['cats'] ? ' in the ' . esc_html( implode( ' / ', array_slice( $f['cats'], 0, 2 ) ) ) . ' space' : '';
		if ( $len <= 5 )      { $size = 'is a very short ' . $len . '-character name'; $why = 'Short names are easy to type, say out loud and remember, and leave room on a logo, a sign or a social handle.'; }
		elseif ( $len <= 9 )  { $size = 'is a compact ' . $len . '-character name'; $why = 'At this length it stays easy to remember and spell while still reading as a brand rather than a sentence.'; }
		else                  { $size = 'is a descriptive ' . $len . '-character name'; $why = 'A longer, descriptive name tells visitors and search engines exactly what to expect before they click.'; }
		switch ( $f['ext'] ) {
			case '.ca':  $ext = 'The .ca extension is Canada\'s country-code domain and signals a Canadian business or audience; registration is limited to those who meet CIRA\'s Canadian presence requirements, so check that you qualify before buying.'; break;
			case '.com': $ext = 'The .com extension is the most widely recognised in the world, so the name works for a global audience and is the one people type by default.'; break;
			case '.in':  $ext = 'The .in extension is India\'s country-code domain, a natural fit for a business serving the Indian market.'; break;
			default:     $ext = 'The ' . esc_html( $f['ext'] ) . ' extension is a clear, credible home for the name.';
		}
		return '<p><strong>' . $n . '</strong> ' . $size . $cat . '. ' . $why . ' ' . $ext . '</p>';
	}
}

if ( ! function_exists( 'db_dd_html' ) ) {
	function db_dd_html( $post ) {
		$f   = db_dd_facts( $post );
		$name_for_url = get_the_title( $post );
		$out = '<section class="db-dd" aria-label="Domain details">';
		$out .= '<h2>' . esc_html( $f['name'] ) . ' &mdash; details &amp; pricing</h2>';
		$out .= db_dd_intro( $f );

		$rows = array(
			'Domain'    => $f['name'],
			'Extension' => $f['ext'],
			'Length'    => $f['len'] . ' characters (before the extension)',
			'Type'      => ucfirst( $f['type'] ),
		);
		if ( $f['cats'] ) { $rows['Category'] = implode( ', ', $f['cats'] ); }
		if ( $f['price'] > 0 ) {
			$rows['Price']    = '$' . number_format( $f['price'], 0 );
			$rows['Payments'] = 'From ' . db_dd_monthly( $f['price'] ) . '/month over 12 months (3 and 6 month plans are interest-free)';
		} else {
			$rows['Price'] = 'Make an offer';
		}
		$out .= '<table class="db-dd-facts"><tbody>';
		foreach ( $rows as $k => $v ) {
			$out .= '<tr><th scope="row">' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
		}
		$out .= '</tbody></table>';

		if ( $f['price'] > 0 ) {
			$urls  = db_dd_checkout_urls( $name_for_url, $f['price'] );
			$buy   = $urls['buy'];
			$plan  = $urls['plan'];
			$out  .= '<div class="db-dd-cta"><a class="db-dd-btn db-dd-btn--primary" href="' . esc_url( $buy ) . '">Buy now &mdash; $' . esc_html( number_format( $f['price'], 0 ) ) . '</a>'
				. '<a class="db-dd-btn" href="' . esc_url( $plan ) . '">Pay monthly from ' . esc_html( db_dd_monthly( $f['price'] ) ) . '/mo</a></div>';
		}

		$out .= '<h3>How buying works</h3><ol class="db-dd-steps">'
			. '<li><strong>Choose how to pay.</strong> Pay in full, or split it over 3, 6, 9 or 12 months.</li>'
			. '<li><strong>Your payment is held securely.</strong> The domain is reserved for you while payment clears.</li>'
			. '<li><strong>We start the transfer.</strong> Most .com transfers finish in 5&ndash;7 days; country-code names can be faster.</li>'
			. '<li><strong>It is yours.</strong> On a payment plan, ownership transfers after your final payment; you can use the domain in the meantime.</li></ol>';
		if ( function_exists( 'db_plan_terms_html' ) ) {
			$out .= '<p class="db-dd-terms">' . wp_kses( db_plan_terms_html(), array( 'strong' => array(), 'em' => array() ) ) . '</p>';
		}

		// Recommended domains: ranked by shared category and name similarity
		// (db-recommend-block.php), not a random draw.
		if ( function_exists( 'db_rec_for' ) ) {
			$recs = db_rec_for( $post->ID, 6 );
			if ( $recs ) {
				$out .= '<h3>Recommended domains</h3>'
					. '<p class="db-dd-rec-intro">Chosen for this name: shared category and similar wording first.</p>'
					. db_rec_cards_html( $recs );
			}
		}

		$out .= '</section>';
		$out .= '<style>.db-dd{margin:28px 0;font-size:16px;line-height:1.65;color:#1f2937}.db-dd h2{font-size:24px;margin:0 0 12px}.db-dd h3{font-size:18px;margin:24px 0 8px}'
			. '.db-dd-facts{width:100%;border-collapse:collapse;margin:16px 0;font-size:15px}.db-dd-facts th,.db-dd-facts td{padding:9px 12px;border-bottom:1px solid #e5e7eb;text-align:left}.db-dd-facts th{width:34%;color:#6b7280;font-weight:600}'
			. '.db-dd-cta{display:flex;flex-wrap:wrap;gap:12px;margin:18px 0}.db-dd-btn{display:inline-block;padding:13px 22px;border-radius:10px;border:2px solid #0a1628;color:#0a1628 !important;font-weight:700;text-decoration:none !important}'
			. '.db-dd-btn--primary{background:#0a1628;color:#fff !important}.db-dd-steps{padding-left:22px;list-style:decimal !important}.db-dd-steps li{list-style:decimal !important}.db-dd-steps li{margin:6px 0}'
			. '.db-dd-terms{font-size:13px;background:#fff8e6;border:1px solid #f0d58a;border-radius:10px;padding:12px 14px;display:block !important}'
			. '.db-dd-rec-intro{font-size:14px;color:#4b5563;margin:0 0 12px}' . db_rec_css() . '</style>';
		return $out;
	}
}

if ( ! function_exists( 'db_dd_inject' ) ) {
	function db_dd_inject( $html ) {
		$post = $GLOBALS['db_dd_post'] ?? null;
		if ( ! is_string( $html ) || ! $post || false !== strpos( $html, 'class="db-dd"' ) ) {
			return $html;
		}
		// After the theme's own "About <domain>" paragraph block.
		$a = strpos( $html, 'class="about_cnt"' );
		if ( false === $a ) {
			return $html;
		}
		$p = stripos( $html, '</p>', $a );
		if ( false === $p ) {
			return $html;
		}
		$at = $p + 4;
		return substr( $html, 0, $at ) . ( $GLOBALS['db_dd_html'] ?? '' ) . substr( $html, $at );
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_singular( 'domain' ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}
	// Built before the buffer callback runs (WP_Query/ob use inside an
	// output-buffer handler is unsafe).
	$GLOBALS['db_dd_post'] = $post;
	$GLOBALS['db_dd_html'] = db_dd_html( $post );
	ob_start( 'db_dd_inject' );
}, 28 );

/* ─── Contact page: it was 33 words — say what to expect and where to go next ── */

add_filter( 'the_content', function ( $content ) {
	if ( is_admin() || ! is_page( 'contact-us' ) || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	$extra  = '<section class="db-contact-extra" style="max-width:760px;margin:28px auto;line-height:1.65">';
	$extra .= '<h2 style="font-size:22px">How we can help</h2>';
	$extra .= '<p>Domain Brothers brokers premium domain names, with escrow-protected transfers and payment plans over 3 to 12 months. Tell us what you are looking for and a member of the team will reply personally.</p>';
	$extra .= '<h3 style="font-size:18px">Include in your message</h3><ul><li>The domain you are interested in, or the kind of name you need (length, extension, industry).</li><li>Your budget, and whether you would like to pay in full or monthly.</li><li>If you are selling a domain, where it is registered and how long you have held it.</li></ul>';
	$extra .= '<p>You can also email <a href="mailto:sales@domainbrothers.com">sales@domainbrothers.com</a>. Before you write, these may already answer your question: <a href="' . esc_url( home_url( '/faqs/' ) ) . '">FAQs</a>, <a href="' . esc_url( home_url( '/all-domains/' ) ) . '">browse all domains</a>, <a href="' . esc_url( home_url( '/our-services/' ) ) . '">our services</a>.</p>';
	$extra .= '</section>';
	return $content . $extra;
}, 20 );
