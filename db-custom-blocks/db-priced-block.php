<?php
/**
 * The page for a domain that has a price (listing state "standard").
 *
 * Laid out after the production pricing page: a "this domain is on sale"
 * band, "Create something meaningful with <name>", three numbered ways to
 * get it (buy now, pay monthly, make a custom offer), accepted payments, the
 * domain's details and recommendations, news and testimonials.
 *
 * Everything the theme's own domain page did for offers is kept, with the same
 * ids, so the existing flow keeps working: the amount box is #number-99, the
 * button is inside #buy_now with data-target="#first", the theme's
 * checkoffervalue() (printed by its header) copies the amount into the form,
 * and the Make an Offer form (Contact Form 7) sits in the same modal markup.
 * The price, the checkout links and the plan maths come from the same
 * functions as before (db_dd_price, db_dd_checkout_urls, db_dd_monthly).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'template_include', function ( $template ) {
	if ( ! is_singular( 'domain' ) ) {
		return $template;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || '' !== $post->post_password || ! function_exists( 'db_listing_state' ) ) {
		return $template;
	}
	$file = __DIR__ . '/db-priced-template.php';
	if ( 'standard' === db_listing_state( $post ) && is_readable( $file ) ) {
		return $file;
	}
	return $template;
}, 99 );

add_filter( 'body_class', function ( $classes ) {
	if ( is_singular( 'domain' ) && function_exists( 'db_listing_state' ) ) {
		$post = get_queried_object();
		if ( $post instanceof WP_Post && 'standard' === db_listing_state( $post ) ) {
			$classes[] = 'db-listing-priced';
		}
	}
	return $classes;
} );

if ( ! function_exists( 'db_priced_html' ) ) {
	/** The page body for a priced domain. Strings only (no output buffering). */
	function db_priced_html( WP_Post $post ) {
		$name  = strtolower( trim( wp_strip_all_tags( $post->post_title ) ) );
		$shown = ucfirst( $name );
		$price = function_exists( 'db_dd_price' ) ? db_dd_price( $post->ID ) : 0.0;
		$urls  = function_exists( 'db_dd_checkout_urls' ) ? db_dd_checkout_urls( $name, $price ) : array( 'buy' => home_url( '/buy-now/' ), 'plan' => home_url( '/payment-plan-setup/' ) );
		$money = '$' . number_format( $price, 0 );
		$plan  = $price >= ( defined( 'DB_DEAL_MIN_PLAN_AMOUNT' ) ? DB_DEAL_MIN_PLAN_AMOUNT : 600 ) && function_exists( 'db_dd_monthly' );
		$cats  = get_the_terms( $post, 'domain_category' );
		$cat   = null;
		if ( is_array( $cats ) && $cats ) {
			// A general, useful category first; the first one found otherwise.
			$prefer = array( 'business', 'technology', 'finance-legal', 'international', 'shopping', 'travel', 'health-fitness', 'food-drinks', 'entertainment' );
			foreach ( $prefer as $slug ) {
				foreach ( $cats as $t ) {
					if ( $t->slug === $slug ) {
						$cat = $t;
						break 2;
					}
				}
			}
			$cat = $cat ?: $cats[0];
		}
		$tick  = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M7.500 12.500l3 3 6-7"/></svg>';
		$li    = static function ( $text ) use ( $tick ) {
			return '<li>' . $tick . '<span>' . $text . '</span></li>';
		};

		$h  = '<div class="db-priced">';
		$h .= '<div class="db-sale-band"><p>This domain <strong>' . esc_html( strtoupper( $name ) ) . '</strong> is on sale <span aria-hidden="true">&#9733;</span></p></div>';
		$h .= '<section class="db-pr-hero" aria-labelledby="db-pr-h"><h1 id="db-pr-h">Create something meaningful with <span>' . esc_html( $shown ) . '</span></h1><p class="db-pr-ask">Are you interested in this domain? <strong>Get started here.</strong></p></section>';

		// The three ways to get it.
		$h .= '<section class="db-pr-options' . ( $plan ? '' : ' is-two' ) . '" aria-label="Ways to get ' . esc_attr( $name ) . '">';
		$h .= '<article class="db-pr-card"><span class="db-pr-num" aria-hidden="true">1</span><h2>Buy now</h2><p class="db-pr-price">' . esc_html( $money ) . '</p><p class="db-pr-note">One payment, full ownership</p><ul class="db-pr-list">'
			. $li( 'Take ownership as soon as your payment clears.' )
			. $li( $cat ? 'Listed under <a href="' . esc_url( get_term_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>.' : 'A premium name, ready to use.' )
			. '</ul><a class="db-pr-btn db-pr-btn--solid" href="' . esc_url( $urls['buy'] ) . '">Buy now</a><p class="db-pr-fine">By continuing you agree to our <a href="' . esc_url( home_url( '/terms-of-use/' ) ) . '">Terms &amp; Conditions</a>.</p></article>';
		if ( $plan ) {
			$h .= '<article class="db-pr-card"><span class="db-pr-num" aria-hidden="true">2</span><h2>Finance this domain</h2><p class="db-pr-price"><small>from</small> ' . esc_html( db_dd_monthly( $price ) ) . '<small>/mo</small></p><p class="db-pr-note">Pay over 3, 6, 9 or 12 months</p><ul class="db-pr-list">'
				. $li( 'Start today with the first monthly payment.' )
				. $li( 'Use the domain while you pay; ownership transfers after the final payment.' )
				. '</ul><a class="db-pr-btn" href="' . esc_url( $urls['plan'] ) . '">Start payment plan</a><p class="db-pr-fine">Prefer to pay in full? Choose Buy now.</p></article>';
		}
		$h .= '<article class="db-pr-card db-pr-card--offer"><span class="db-pr-num" aria-hidden="true">' . ( $plan ? '3' : '2' ) . '</span><h2>Make a custom offer</h2><p class="db-pr-price db-pr-price--text">Name your price</p><p class="db-pr-note">The owner will consider other proposals</p><ul class="db-pr-list">'
			. $li( 'No offer is too big or too small.' )
			. $li( 'Our team negotiates on your behalf and replies personally.' )
			. '</ul><div class="db-pr-offer"><label for="number-99">My offer (USD, excl. taxes)</label><div class="db-pr-amount"><span aria-hidden="true">$</span><input type="text" id="number-99" inputmode="numeric" autocomplete="off" placeholder="e.g. ' . esc_attr( number_format( max( 100, round( $price * 0.8, -2 ) ), 0, '.', '' ) ) . '" onkeypress="return KeycheckOnlyPhonenumber(event)" maxlength="12"></div></div>'
			. '<div id="buy_now" class="db-pr-offerbtn"><button type="button" class="db-pr-btn" data-toggle="modal" data-target="#first" onclick="checkoffervalue();">Make a custom offer</button></div><p class="db-pr-fine">Free to ask. No obligation until we agree a price.</p></article>';
		$h .= '</section>';

		$tld = strrchr( $name, '.' );
		$h  .= '<div class="db-pr-pay"><p class="db-pr-badges" aria-label="Accepted payments"><span>Secure checkout</span><span>Visa</span><span>Mastercard</span><span>Amex</span><span>Apple Pay</span><span>Google Pay</span></p>';
		if ( '.ca' === $tld ) {
			$h .= '<p class="db-pr-ca">To register a .ca domain name you must be a registered Canadian business or organization, a Canadian trademark holder, a citizen of Canada, have permanent residency in Canada, or otherwise qualify under the Canadian Presence Requirements.</p>';
		}
		$h .= '</div>';
		$h .= function_exists( 'db_ad_html' ) ? db_ad_html( 'after_offers' ) : '';

		// Details, statistics and recommendations (built once in db-domain-details-block.php).
		$h .= '<div class="db-pr-details">' . (string) ( $GLOBALS['db_dd_html'] ?? '' ) . '</div>';

		// Testimonials and news.
		$tt = function_exists( 'db_listing_testimonials' ) ? db_listing_testimonials() : array();
		if ( $tt ) {
			$h .= '<section class="db-pr-quotes" aria-labelledby="db-pr-q"><h2 id="db-pr-q">What buyers say</h2><div class="db-pr-quotegrid">';
			foreach ( $tt as $q ) {
				$h .= '<figure><blockquote>' . esc_html( $q[0] ) . '</blockquote><figcaption>' . esc_html( $q[1] ) . '</figcaption></figure>';
			}
			$h .= '</div></section>';
		}
		$news = function_exists( 'db_listing_news' ) ? array_slice( db_listing_news( 6 ), 0, 3 ) : array();
		if ( $news ) {
			$h .= '<section class="db-pr-news" aria-labelledby="db-pr-n"><h2 id="db-pr-n">Domain news</h2><div class="db-pr-newsgrid">';
			foreach ( $news as $n ) {
				$h .= '<a href="' . esc_url( $n['url'] ) . '"><time datetime="' . esc_attr( $n['year'] . '-' . $n['month'] . '-' . $n['day'] ) . '">' . esc_html( $n['day'] . ' ' . $n['month'] . ' ' . $n['year'] ) . '</time><strong>' . esc_html( $n['title'] ) . '</strong><span>' . esc_html( $n['excerpt'] ) . '</span></a>';
			}
			$h .= '</div></section>';
		}
		return $h . '</div>';
	}
}
