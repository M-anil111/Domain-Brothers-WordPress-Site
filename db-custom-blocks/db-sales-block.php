<?php
/**
 * Sales features.
 *
 *  - Conversion funnel: server-side counts of six steps (no IP, cookie or
 *    email is stored with an event), staff and known bots excluded. Admin
 *    report under Launch checks' parent menu: "Sales funnel".
 *  - Guided domain finder at /find-a-domain/: five questions, an explainable
 *    ranking over listings for sale, a saved search with new-match alerts for
 *    signed-in visitors, and a broker call request (Turnstile and rate limited).
 *  - Collections at /collections/<slug>/: fixed searches shown only when they
 *    have at least three live matches.
 *  - "Why this price?" on domain pages: facts about the name, recorded sales of
 *    similar names (same extension, length within two characters) and optional
 *    seller guidance. No valuation is invented.
 *  - Transfer workspace: a stage per domain set in admin and shown on the
 *    buyer's account, with service options for after purchase.
 *  - Buyer-intent score: explainable, counts only (no buyer emails), with a
 *    follow-up task per domain. Admin page "Buyer intent".
 *  - Trust center at /trust-center/: only statements the plugin code enforces.
 *
 * @package DomainBrothers
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_SALES_TABLE_VERSION' ) ) {
	define( 'DB_SALES_TABLE_VERSION', '1' );
}
if ( ! defined( 'DB_SALES_MAX_SEARCHES' ) ) {
	define( 'DB_SALES_MAX_SEARCHES', 10 );
}

if ( ! function_exists( 'db_sales_table' ) ) {
	function db_sales_table( $which = 'funnel' ) {
		global $wpdb;
		$map = array( 'funnel' => 'db_funnel', 'searches' => 'db_saved_searches' );
		return $wpdb->prefix . $map[ $which ];
	}
}

/* ------------------------------------------------------------------ schema */

add_action( 'init', function () {
	if ( get_option( 'db_sales_table_version', '' ) !== DB_SALES_TABLE_VERSION ) {
		global $wpdb;
		$cs = $wpdb->get_charset_collate();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( 'CREATE TABLE ' . db_sales_table( 'funnel' ) . " (
			id         BIGINT      NOT NULL AUTO_INCREMENT,
			ev         VARCHAR(32) NOT NULL,
			post_id    INT         NOT NULL DEFAULT 0,
			day        DATE        NOT NULL,
			created_at DATETIME    NOT NULL,
			PRIMARY KEY (id),
			KEY idx_ev_day (ev, day)
		) {$cs};" );
		dbDelta( 'CREATE TABLE ' . db_sales_table( 'searches' ) . " (
			id         INT          NOT NULL AUTO_INCREMENT,
			email      VARCHAR(190) NOT NULL,
			label      VARCHAR(160) NOT NULL,
			criteria   TEXT         NOT NULL,
			seen       TEXT         NULL,
			created_at DATETIME     NOT NULL,
			PRIMARY KEY (id),
			KEY idx_email (email)
		) {$cs};" );
		update_option( 'db_sales_table_version', DB_SALES_TABLE_VERSION, false );
	}
	if ( ! wp_next_scheduled( 'db_sales_cron' ) ) {
		wp_schedule_event( time() + 600, 'db_quarter_hour', 'db_sales_cron' );
	}
} );

/* ----------------------------------------------------------------- funnel */

if ( ! function_exists( 'db_funnel_steps' ) ) {
	function db_funnel_steps() {
		return array(
			'listing_view'   => 'Domain page viewed',
			'finder_start'   => 'Finder opened',
			'finder_result'  => 'Finder results shown',
			'saved_search'   => 'Search saved',
			'broker_request' => 'Broker call requested',
			'trust_view'     => 'Trust center viewed',
			'exp_view_A'     => 'CTA test: copy A seen',
			'exp_view_B'     => 'CTA test: copy B seen',
			'exp_click_A'    => 'CTA test: copy A clicked',
			'exp_click_B'    => 'CTA test: copy B clicked',
		);
	}
}

if ( ! function_exists( 'db_funnel_track' ) ) {
	/** Records one funnel event. Returns false when the event is not recorded. */
	function db_funnel_track( $ev, $post_id = 0 ) {
		$steps = db_funnel_steps();
		if ( ! isset( $steps[ $ev ] ) ) {
			return false;
		}
		if ( current_user_can( 'edit_posts' ) ) {
			return false; // Staff browsing is not buyer behaviour.
		}
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( function_exists( 'db_hits_is_bot' ) && db_hits_is_bot( $ua ) ) {
			return false;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			db_sales_table( 'funnel' ),
			array( 'ev' => $ev, 'post_id' => max( 0, (int) $post_id ), 'day' => current_time( 'Y-m-d' ), 'created_at' => current_time( 'mysql' ) ),
			array( '%s', '%d', '%s', '%s' )
		);
		return true;
	}
}

add_action( 'template_redirect', function () {
	if ( ! is_admin() && is_singular( 'domain' ) ) {
		db_funnel_track( 'listing_view', get_queried_object_id() );
	}
}, 4 );

if ( ! function_exists( 'db_funnel_counts' ) ) {
	/** Event counts for the last $days days, keyed by event. */
	function db_funnel_counts( $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d', time() - max( 1, (int) $days ) * DAY_IN_SECONDS );
		$t     = db_sales_table( 'funnel' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT ev, COUNT(*) AS n FROM {$t} WHERE day >= %s GROUP BY ev", $since ), OBJECT_K );
		$out   = array();
		foreach ( db_funnel_steps() as $ev => $label ) {
			$out[ $ev ] = isset( $rows[ $ev ] ) ? (int) $rows[ $ev ]->n : 0;
		}
		return $out;
	}
}

if ( ! function_exists( 'db_funnel_rate' ) ) {
	function db_funnel_rate( $part, $whole ) {
		return $whole > 0 ? round( 100 * $part / $whole, 1 ) . '%' : '—';
	}
}

/* ------------------------------------------------- finder: options & match */

if ( ! function_exists( 'db_finder_options' ) ) {
	/** The five questions and their allowed answers. Anything else is ignored. */
	function db_finder_options() {
		$industry = array( 'any' => 'Not sure yet' );
		$terms    = get_terms( array( 'taxonomy' => 'domain_category', 'hide_empty' => false ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				if ( in_array( strtolower( $t->name ), array( 'featured', 'latest' ), true ) ) {
					continue;
				}
				$industry[ $t->slug ] = $t->name;
			}
		}
		return array(
			'industry'  => array( 'label' => 'What industry or topic is the business in?', 'choices' => $industry ),
			'audience'  => array( 'label' => 'Who is the main audience?', 'choices' => array( 'any' => 'Not sure', 'consumers' => 'Consumers', 'businesses' => 'Other businesses', 'both' => 'Both' ) ),
			'geography' => array( 'label' => 'Where do you do business?', 'choices' => array( 'any' => 'Not sure', 'canada' => 'Canada', 'global' => 'United States or worldwide' ) ),
			'tone'      => array( 'label' => 'What tone should the name have?', 'choices' => array( 'any' => 'No preference', 'short' => 'Short and punchy', 'descriptive' => 'Descriptive and clear', 'trustworthy' => 'Plain and trustworthy' ) ),
			'budget'    => array( 'label' => 'What is the budget?', 'choices' => array( 'any' => 'No limit', '2500' => 'Up to $2,500', '5000' => 'Up to $5,000', '15000' => 'Up to $15,000' ) ),
		);
	}
}

if ( ! function_exists( 'db_finder_criteria_from_array' ) ) {
	/** Keeps only known keys with allowed values; unknown answers fall back to "any". */
	function db_finder_criteria_from_array( array $in ) {
		$out = array();
		foreach ( db_finder_options() as $key => $def ) {
			$v           = isset( $in[ $key ] ) ? sanitize_key( (string) $in[ $key ] ) : 'any';
			$out[ $key ] = isset( $def['choices'][ $v ] ) ? $v : 'any';
		}
		// Fixed filters used by collections only; never read from the query string.
		return $out;
	}
}

if ( ! function_exists( 'db_finder_summary' ) ) {
	function db_finder_summary( array $c ) {
		$opts  = db_finder_options();
		$parts = array();
		foreach ( array( 'industry', 'audience', 'geography', 'tone', 'budget' ) as $k ) {
			if ( 'any' !== $c[ $k ] && isset( $opts[ $k ]['choices'][ $c[ $k ] ] ) ) {
				$parts[] = $opts[ $k ]['choices'][ $c[ $k ] ];
			}
		}
		return $parts ? implode( ' · ', $parts ) : 'Any domain for sale';
	}
}

if ( ! function_exists( 'db_finder_candidates' ) ) {
	/** Published listings that can be bought now (fixed price or open to offers). */
	function db_finder_candidates() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$q     = new WP_Query( array(
			'post_type'      => 'domain',
			'post_status'    => 'publish',
			'posts_per_page' => 800,
			'no_found_rows'  => true,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		$cache = array_values( array_filter( $q->posts, 'db_listing_is_sellable' ) );
		return $cache;
	}
}

if ( ! function_exists( 'db_finder_match' ) ) {
	/**
	 * Ranks listings against the criteria. Every score point has a reason, so
	 * the result can be explained to the buyer and audited by staff.
	 *
	 * Extra keys used only by collections: ext (hard filter), maxlen (hard filter).
	 *
	 * @return array<int, array{post: WP_Post, facts: array, state: string, score: float, reasons: string[]}>
	 */
	function db_finder_match( array $c, $limit = 10 ) {
		$rows = array();
		foreach ( db_finder_candidates() as $post ) {
			$f     = db_dd_facts( $post );
			$state = db_listing_state( $post );
			$cats  = array_map( 'sanitize_title', (array) $f['cats'] );
			$score = 0.0;
			$why   = array();
			$pure  = 'letters only' === $f['type'];

			if ( ! empty( $c['ext'] ) && $f['ext'] !== $c['ext'] ) {
				continue;
			}
			if ( ! empty( $c['maxlen'] ) && $f['len'] > (int) $c['maxlen'] ) {
				continue;
			}
			if ( 'any' !== $c['industry'] ) {
				if ( ! in_array( $c['industry'], $cats, true ) ) {
					continue;
				}
				$score += 4;
				$why[]  = 'Listed in the ' . implode( ' / ', array_slice( (array) $f['cats'], 0, 2 ) ) . ' space';
			}

			$price = (float) $f['price'];
			if ( 'any' !== $c['budget'] ) {
				if ( 'standard' === $state ) {
					if ( $price <= 0 || $price > (float) $c['budget'] ) {
						continue;
					}
					$why[] = 'Fixed price within your budget';
				} else {
					$why[] = 'Open to offers, so you can negotiate toward your budget';
				}
				$score += 1;
			}

			switch ( $c['audience'] ) {
				case 'consumers':
					if ( $f['len'] <= 10 && $pure ) { $score += 2; $why[] = 'Easy for consumers to say and remember'; }
					break;
				case 'businesses':
					if ( in_array( $f['ext'], array( '.com', '.ca' ), true ) ) { $score += 2; $why[] = 'A standard extension for business use'; }
					break;
			}

			if ( 'canada' === $c['geography'] && '.ca' === $f['ext'] ) {
				$score += 4;
				$why[]  = 'Canadian (.ca) extension';
			} elseif ( 'global' === $c['geography'] && '.com' === $f['ext'] ) {
				$score += 4;
				$why[]  = 'Worldwide (.com) extension';
			}

			switch ( $c['tone'] ) {
				case 'short':
					if ( $f['len'] <= 8 ) { $score += 3; $why[] = 'Short: ' . $f['len'] . ' characters'; }
					break;
				case 'descriptive':
					if ( $f['len'] >= 9 && $pure ) { $score += 2; $why[] = 'Descriptive, with a clear meaning'; }
					break;
				case 'trustworthy':
					if ( $pure ) { $score += 2; $why[] = 'Plain letters, no hyphens or numbers'; }
					break;
			}

			$rows[] = array( 'post' => $post, 'facts' => $f, 'state' => $state, 'score' => $score, 'reasons' => $why );
		}

		usort( $rows, function ( $a, $b ) {
			if ( $a['score'] !== $b['score'] ) {
				return $a['score'] < $b['score'] ? 1 : -1;
			}
			if ( $a['facts']['len'] !== $b['facts']['len'] ) {
				return $a['facts']['len'] - $b['facts']['len'];
			}
			return strcmp( $a['facts']['name'], $b['facts']['name'] );
		} );

		return array_slice( $rows, 0, max( 1, (int) $limit ) );
	}
}

/* ------------------------------------------------------- finder: markup */

if ( ! function_exists( 'db_sales_css' ) ) {
	/** Styles for the sales pages. Printed once per page that uses them. */
	function db_sales_css() {
		return '<style>.db-sales-page{max-width:880px;margin:0 auto;padding:0 16px}'
			. '.db-sales-lead{font-size:17px;line-height:1.6;color:#334155;max-width:65ch}'
			. '.db-sales-form fieldset{border:1px solid #d7dee8;border-radius:12px;padding:14px 16px;margin:14px 0;min-width:0}'
			. '.db-sales-form legend{font-weight:700;padding:0 6px;color:#0a1628}'
			. '.db-sales-step{display:inline-block;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#64748b;margin-right:8px}'
			. '.db-sales-choices{display:flex;flex-wrap:wrap;gap:8px}'
			. '.db-sales-choices label{display:inline-flex;align-items:center;gap:6px;min-height:44px;padding:8px 14px;border:1px solid #cbd5e1;border-radius:999px;cursor:pointer}'
			. '.db-sales-choices label:has(input:checked){border-color:#0a1628;background:#eef3f9}'
			. '.db-sales-btn{display:inline-flex;align-items:center;min-height:44px;padding:10px 20px;border-radius:10px;background:#0a1628;color:#fff;border:0;font-weight:700;cursor:pointer;text-decoration:none}'
			. '.db-sales-card{background:#fff;border:1px solid #d7dee8;border-radius:12px;padding:16px 18px;margin:14px 0}'
			. '.db-sales-results{list-style:none;padding:0;margin:0;display:grid;gap:10px}'
			. '.db-sales-result{border:1px solid #d7dee8;border-radius:12px;padding:14px 16px;background:#fff}'
			. '.db-sales-result>div{display:flex;flex-wrap:wrap;justify-content:space-between;gap:8px;align-items:baseline}'
			. '.db-sales-name{font-size:19px;font-weight:700;color:#0a1628;overflow-wrap:anywhere}'
			. '.db-sales-price{font-size:14px;color:#334155}'
			. '.db-sales-why{margin:8px 0 0;padding-left:18px;font-size:14px;color:#475569}'
			. '.db-sales-form label{display:grid;gap:4px;margin:10px 0;font-weight:600}'
			. '.db-sales-form input,.db-sales-form textarea{min-height:44px;padding:8px 12px;border:1px solid #cbd5e1;border-radius:8px;font:inherit;font-weight:400;max-width:100%}'
			. '.db-sales-notice{border-color:#9bd1a7;background:#f1fbf3}'
			. '.db-sales-trust{display:grid;gap:10px;margin:0}.db-sales-trust dt{font-weight:700;color:#0a1628}.db-sales-trust dd{margin:4px 0 0}'
			. '.db-sales-links{display:flex;flex-wrap:wrap;gap:10px 18px;padding:0;list-style:none}'
			. '.db-sales-links a{min-height:44px;display:inline-flex;align-items:center}'
			. '.db-sales-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin:12px 0}'
			. '.db-sales-facts div{border:1px solid #d7dee8;border-radius:10px;padding:10px 12px}.db-sales-facts dt{font-weight:700}.db-sales-facts dd{margin:4px 0 0}'
			. '.db-sales-guide{border-left:3px solid #0a1628;padding:6px 12px;background:#f8fafc}'
			. '.db-sales-steps{list-style:none;padding:0;display:grid;gap:6px}'
			. '.db-sales-steps li{display:flex;justify-content:space-between;gap:12px;padding:8px 12px;border-radius:8px;background:#f8fafc}'
			. '.db-sales-steps li.is-done em{color:#166534}.db-sales-steps li.is-current{background:#eef3f9;font-weight:700}'
			. '.db-sales-services{list-style:none;padding:0;display:grid;gap:10px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}'
			. '.db-sales-collections{list-style:none;padding:0;display:grid;gap:8px}.db-sales-collections a{font-weight:700}'
			. '</style>';
	}
}

if ( ! function_exists( 'db_sales_nonce' ) ) {
	function db_sales_nonce( $action ) {
		return wp_nonce_field( $action, '_db_nonce', true, false );
	}
}

if ( ! function_exists( 'db_sales_result_list' ) ) {
	function db_sales_result_list( array $rows ) {
		if ( ! $rows ) {
			return '<div class="db-sales-card"><h2>No names match yet</h2><p>Try a wider budget or another industry, or ask a broker to look for you below.</p></div>';
		}
		$h = '<ol class="db-sales-results">';
		foreach ( $rows as $r ) {
			$p     = $r['post'];
			$state = 'standard' === $r['state'] ? ( $r['facts']['price'] > 0 ? '$' . number_format( (float) $r['facts']['price'], 0 ) : 'Fixed price' ) : 'Open to offers';
			$h    .= '<li class="db-sales-result"><div><a class="db-sales-name" href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( $r['facts']['name'] ) . '</a>'
				. '<span class="db-sales-price">' . esc_html( $state ) . '</span></div>';
			if ( $r['reasons'] ) {
				$h .= '<ul class="db-sales-why">';
				foreach ( $r['reasons'] as $w ) {
					$h .= '<li>' . esc_html( $w ) . '</li>';
				}
				$h .= '</ul>';
			}
			$h .= '</li>';
		}
		return $h . '</ol>';
	}
}

if ( ! function_exists( 'db_sales_save_form' ) ) {
	/** Saved-search form. Signed-in visitors only; others are asked to sign in to the wishlist. */
	function db_sales_save_form( array $c ) {
		$email = function_exists( 'db_wl_email' ) ? db_wl_email() : '';
		$h     = '<div class="db-sales-card db-sales-save"><h2>Get new matches by email</h2>';
		if ( '' === $email ) {
			$h .= '<p>Sign in with your email on <a href="' . esc_url( home_url( '/my-wishlist/' ) ) . '">My account</a>, then come back here to save this search. We alert you when a new name matches.</p></div>';
			return $h;
		}
		$h .= '<p>We will alert you in your account and by email when a new name matches <strong>' . esc_html( db_finder_summary( $c ) ) . '</strong>.</p>';
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">' . db_sales_nonce( 'db_sales_save' );
		$h .= '<input type="hidden" name="action" value="db_sales_save_search">';
		foreach ( $c as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$h .= '<button type="submit" class="db-sales-btn">Save this search</button></form></div>';
		return $h;
	}
}

if ( ! function_exists( 'db_sales_broker_form' ) ) {
	function db_sales_broker_form( $domain_hint = '', $criteria_text = '' ) {
		$h  = '<div class="db-sales-card" id="db-broker"><h2>Ask a broker to call you</h2><p>A person reviews your request and replies by email or phone. We do not add pressure or deadlines.</p>';
		$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="db-sales-form">' . db_sales_nonce( 'db_sales_broker' );
		$h .= '<input type="hidden" name="action" value="db_sales_broker">';
		$h .= '<input type="hidden" name="criteria_text" value="' . esc_attr( $criteria_text ) . '">';
		$h .= '<label>Your name<input name="name" type="text" maxlength="120" autocomplete="name" required></label>';
		$h .= '<label>Email<input name="email" type="email" maxlength="190" autocomplete="email" required></label>';
		$h .= '<label>Phone (optional)<input name="phone" type="tel" maxlength="40" autocomplete="tel"></label>';
		$h .= '<label>Domain you are interested in (optional)<input name="domain" type="text" maxlength="120" value="' . esc_attr( $domain_hint ) . '"></label>';
		$h .= '<label>What are you trying to do?<textarea name="note" rows="4" maxlength="1000"></textarea></label>';
		$h .= '<div class="db-ts"></div>';
		$h .= '<button type="submit" class="db-sales-btn">Request a call</button></form>';
		$h .= '<script>if(window.dbTsRender){window.dbTsRender(document.getElementById("db-broker"));}</script></div>';
		return $h;
	}
}

if ( ! function_exists( 'db_sales_notice' ) ) {
	function db_sales_notice() {
		$msg = '';
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$code = sanitize_key( wp_unslash( $_GET['saved'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$msg  = array(
				'1'      => 'Search saved. We will alert you when a new name matches.',
				'signin' => 'Sign in on My account to save a search.',
				'full'   => 'You already have ' . DB_SALES_MAX_SEARCHES . ' saved searches. Remove one on the Searches tab first.',
			)[ $code ] ?? '';
		}
		if ( isset( $_GET['broker'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$code = sanitize_key( wp_unslash( $_GET['broker'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$msg  = array(
				'sent'  => 'Thanks. A broker will reply to the email you gave us.',
				'check' => 'Please complete the security check, then try again.',
				'email' => 'Please enter a valid email address.',
				'busy'  => 'We have received several requests from you in the last hour. Please try again later.',
				'error' => 'Your request could not be saved. Please try again or use the contact page.',
			)[ $code ] ?? '';
		}
		return '' === $msg ? '' : '<div class="db-sales-card db-sales-notice" role="status"><p>' . esc_html( $msg ) . '</p></div>';
	}
}

if ( ! function_exists( 'db_finder_body' ) ) {
	function db_finder_body() {
		$opts = db_finder_options();
		$in   = array();
		foreach ( array_keys( $opts ) as $k ) {
			$in[ $k ] = isset( $_GET[ $k ] ) ? wp_unslash( $_GET[ $k ] ) : 'any'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		$c       = db_finder_criteria_from_array( $in );
		$started = isset( $_GET['go'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$h       = db_sales_notice();
		$h      .= '<p class="db-sales-lead">Answer five short questions. We rank the domains we have for sale that fit, explain why each one fits, and you can ask a broker to help.</p>';
		$h      .= '<form method="get" action="' . esc_url( home_url( '/find-a-domain/' ) ) . '" class="db-sales-form db-sales-quiz" id="db-finder">';
		$n       = 0;
		foreach ( $opts as $key => $def ) {
			$n++;
			$h .= '<fieldset><legend><span class="db-sales-step">' . (int) $n . ' of 5</span> ' . esc_html( $def['label'] ) . '</legend><div class="db-sales-choices">';
			foreach ( $def['choices'] as $val => $label ) {
				$h .= '<label><input type="radio" name="' . esc_attr( $key ) . '" value="' . esc_attr( $val ) . '"' . checked( $c[ $key ], $val, false ) . '> ' . esc_html( $label ) . '</label>';
			}
			$h .= '</div></fieldset>';
		}
		$h .= '<input type="hidden" name="go" value="1"><button type="submit" class="db-sales-btn">Show my shortlist</button></form>';

		if ( $started ) {
			db_funnel_track( 'finder_result' );
			$rows = db_finder_match( $c, 10 );
			$h   .= '<section class="db-sales-shortlist" aria-labelledby="db-shortlist-h"><h2 id="db-shortlist-h">Your shortlist</h2>';
			$h   .= '<p class="db-sales-lead">' . esc_html( db_finder_summary( $c ) ) . ' · ' . count( $rows ) . ' ' . ( 1 === count( $rows ) ? 'match' : 'matches' ) . '</p>';
			$h   .= db_sales_result_list( $rows );
			$h   .= db_sales_save_form( $c );
			$names = array_map( function ( $r ) { return $r['facts']['name']; }, $rows );
			$h   .= db_sales_broker_form( '', 'Criteria: ' . db_finder_summary( $c ) . '. Shortlist: ' . implode( ', ', $names ) );
			$h   .= '</section>';
		} else {
			db_funnel_track( 'finder_start' );
			$hint = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
			$h   .= db_sales_broker_form( $hint );
		}
		return $h;
	}
}

/* ----------------------------------------------- finder: POST handlers */

if ( ! function_exists( 'db_sales_back' ) ) {
	function db_sales_back( array $args ) {
		$back = wp_get_referer();
		if ( ! $back || 0 !== strpos( $back, home_url() ) ) {
			$back = home_url( '/find-a-domain/' );
		}
		wp_safe_redirect( add_query_arg( $args, $back ) );
		exit;
	}
}

add_action( 'admin_post_nopriv_db_sales_broker', 'db_sales_handle_broker' );
add_action( 'admin_post_db_sales_broker', 'db_sales_handle_broker' );
if ( ! function_exists( 'db_sales_handle_broker' ) ) {
	function db_sales_handle_broker() {
		check_admin_referer( 'db_sales_broker', '_db_nonce' );
		$ip = function_exists( 'db_wl_ip' ) ? db_wl_ip() : '';
		if ( function_exists( 'db_ts_enabled' ) && db_ts_enabled() ) {
			$ts = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( ! db_ts_verify( $ts, $ip ) ) {
				db_sales_back( array( 'broker' => 'check' ) );
			}
		}
		$key   = 'db_sales_broker_' . md5( $ip );
		$count = (int) get_transient( $key );
		if ( $count >= 5 ) {
			db_sales_back( array( 'broker' => 'busy' ) );
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );

		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_email( $email ) ) {
			db_sales_back( array( 'broker' => 'email' ) );
		}
		$name   = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$phone  = sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$domain = sanitize_text_field( wp_unslash( $_POST['domain'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$note   = sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$crit   = sanitize_textarea_field( wp_unslash( $_POST['criteria_text'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		if ( '' === $domain ) {
			$domain = 'Domain finder request';
		}
		$result = function_exists( 'db_crm_create_lead' ) ? db_crm_create_lead( array(
			'domain'  => $domain,
			'name'    => $name,
			'email'   => $email,
			'phone'   => $phone,
			'message' => "Broker call requested.\n" . $note,
			'source'  => 'inquiry',
			'notes'   => $crit,
		) ) : new WP_Error( 'no_crm', 'CRM unavailable' );
		if ( is_wp_error( $result ) ) {
			db_sales_back( array( 'broker' => 'error' ) );
		}
		db_funnel_track( 'broker_request' );
		if ( function_exists( 'db_ops_broker_notify' ) ) {
			db_ops_broker_notify( $email, $name, $phone, $domain, $note, $crit );
		}
		db_sales_back( array( 'broker' => 'sent' ) );
	}
}

add_action( 'admin_post_nopriv_db_sales_save_search', 'db_sales_handle_save' );
add_action( 'admin_post_db_sales_save_search', 'db_sales_handle_save' );
if ( ! function_exists( 'db_sales_handle_save' ) ) {
	function db_sales_handle_save() {
		check_admin_referer( 'db_sales_save', '_db_nonce' );
		$email = function_exists( 'db_wl_email' ) ? db_wl_email() : '';
		if ( '' === $email ) {
			db_sales_back( array( 'saved' => 'signin' ) );
		}
		$c = db_finder_criteria_from_array( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		global $wpdb;
		$t = db_sales_table( 'searches' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE email = %s", $email ) );
		if ( $n >= DB_SALES_MAX_SEARCHES ) {
			db_sales_back( array( 'saved' => 'full' ) );
		}
		$seen = wp_list_pluck( db_finder_match( $c, 500 ), 'post' );
		$ids  = array_map( function ( $p ) { return (int) $p->ID; }, $seen );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $t, array(
			'email'      => $email,
			'label'      => substr( db_finder_summary( $c ), 0, 160 ),
			'criteria'   => wp_json_encode( $c ),
			'seen'       => wp_json_encode( $ids ),
			'created_at' => current_time( 'mysql' ),
		), array( '%s', '%s', '%s', '%s', '%s' ) );
		db_funnel_track( 'saved_search' );
		db_sales_back( array( 'saved' => '1' ) );
	}
}

/* ------------------------------------------------ alerts for saved searches */

add_action( 'db_sales_cron', function () {
	global $wpdb;
	$t    = db_sales_table( 'searches' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( "SELECT * FROM {$t} ORDER BY id ASC LIMIT 500" );
	foreach ( (array) $rows as $row ) {
		$c    = db_finder_criteria_from_array( (array) json_decode( (string) $row->criteria, true ) );
		$seen = (array) json_decode( (string) $row->seen, true );
		$now  = array_map( function ( $p ) { return (int) $p->ID; }, wp_list_pluck( db_finder_match( $c, 500 ), 'post' ) );
		$new  = array_values( array_diff( $now, $seen ) );
		foreach ( array_slice( $new, 0, 5 ) as $post_id ) {
			$p = get_post( $post_id );
			if ( ! $p ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->insert( $wpdb->prefix . 'db_wl_notes', array(
				'email'      => $row->email,
				'post_id'    => $post_id,
				'kind'       => 'match',
				'message'    => substr( 'New match for your search (' . $row->label . '): ' . strtolower( $p->post_title ), 0, 255 ),
				'created_at' => current_time( 'mysql' ),
				'emailed'    => 0,
				'tries'      => 0,
			), array( '%s', '%d', '%s', '%s', '%s', '%d', '%d' ) );
		}
		$merged = array_slice( array_values( array_unique( array_merge( $seen, $now ) ) ), -500 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update( $t, array( 'seen' => wp_json_encode( $merged ) ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
	}
} );

/* --------------------------------------------------------- collections */

if ( ! function_exists( 'db_sales_collections' ) ) {
	function db_sales_collections() {
		return array(
			'canadian-names-for-business-under-5000' => array(
				'title'    => 'Canadian names for business, up to $5,000',
				'intro'    => 'Names ending in .ca that suit other businesses and fall within a $5,000 fixed price or are open to offers.',
				'criteria' => array( 'ext' => '.ca', 'audience' => 'businesses', 'budget' => '5000' ),
			),
			'short-com-names'                        => array(
				'title'    => 'Short .com names',
				'intro'    => 'Names of eight characters or fewer, ending in .com.',
				'criteria' => array( 'ext' => '.com', 'maxlen' => 8, 'tone' => 'short' ),
			),
			'plain-names-for-consumers'              => array(
				'title'    => 'Plain, easy-to-say names for consumers',
				'intro'    => 'Letters-only names of ten characters or fewer that are easy to say and remember.',
				'criteria' => array( 'audience' => 'consumers', 'maxlen' => 10, 'tone' => 'trustworthy' ),
			),
		);
	}
}

if ( ! function_exists( 'db_sales_collection_rows' ) ) {
	/** Rows for a collection, or null if it has fewer than three live matches. */
	function db_sales_collection_rows( $slug ) {
		$all = db_sales_collections();
		if ( ! isset( $all[ $slug ] ) ) {
			return null;
		}
		$c           = db_finder_criteria_from_array( $all[ $slug ]['criteria'] );
		$c['ext']    = $all[ $slug ]['criteria']['ext'] ?? '';
		$c['maxlen'] = (int) ( $all[ $slug ]['criteria']['maxlen'] ?? 0 );
		$rows = db_finder_match( $c, 50 );
		return count( $rows ) >= 3 ? array( $c, $rows ) : null;
	}
}

/* ---------------------------------------------------------- pages */

if ( ! function_exists( 'db_sales_request_path' ) ) {
	function db_sales_request_path() {
		$p = wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return trim( (string) $p, '/' );
	}
}

if ( ! function_exists( 'db_sales_render' ) ) {
	/** Renders a public page in the theme. $noindex keeps thin or parameter pages out of search. */
	function db_sales_render( $title, $crumb, $body, $noindex = false ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) && ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			define( 'DONOTCACHEPAGE', true );
		}
		status_header( 200 );
		global $wp_query;
		$wp_query->is_404 = false;
		if ( $noindex ) {
			header( 'X-Robots-Tag: noindex, follow' );
		}
		get_header();
		echo '<div id="primary" class="content-area"><main id="main" class="site-main db-sales-page">';
		echo '<nav class="db-breadcrumbs" aria-label="Breadcrumb"><ol><li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li><li><span aria-current="page">' . esc_html( $crumb ) . '</span></li></ol></nav>';
		echo '<h1 class="page-title">' . esc_html( $title ) . '</h1>';
		echo db_sales_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
		echo '</main></div>';
		get_footer();
		exit;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() ) {
		return;
	}
	$path = db_sales_request_path();
	if ( 'find-a-domain' === $path ) {
		db_sales_render( 'Find a domain', 'Find a domain', db_finder_body(), ! empty( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}
	if ( 'trust-center' === $path ) {
		db_funnel_track( 'trust_view' );
		db_sales_render( 'Trust center', 'Trust center', db_trust_center_body() );
	}
	if ( 'collections' === $path ) {
		$links = '<ul class="db-sales-collections">';
		$any   = false;
		foreach ( db_sales_collections() as $slug => $col ) {
			$hit = db_sales_collection_rows( $slug );
			if ( null === $hit ) {
				continue;
			}
			$any    = true;
			$links .= '<li><a href="' . esc_url( home_url( '/collections/' . $slug . '/' ) ) . '">' . esc_html( $col['title'] ) . '</a> <span>' . count( $hit[1] ) . ' names</span></li>';
		}
		$links .= '</ul>';
		$body   = $any ? '<p class="db-sales-lead">Curated searches. Each one lists names we have for sale right now.</p>' . $links
			: '<p class="db-sales-lead">Collections appear here once they have enough names for sale.</p>';
		db_sales_render( 'Collections', 'Collections', $body );
	}
	if ( preg_match( '#^collections/([a-z0-9-]+)$#', $path, $m ) ) {
		$cols = db_sales_collections();
		if ( ! isset( $cols[ $m[1] ] ) ) {
			return;
		}
		$hit = db_sales_collection_rows( $m[1] );
		if ( null === $hit ) {
			db_sales_render( $cols[ $m[1] ]['title'], 'Collections', '<p class="db-sales-lead">This collection does not have enough names for sale yet. <a href="' . esc_url( home_url( '/find-a-domain/' ) ) . '">Use the domain finder</a> to search for other names.</p>', true );
		}
		list( $c, $rows ) = $hit;
		$body  = '<p class="db-sales-lead">' . esc_html( $cols[ $m[1] ]['intro'] ) . '</p>';
		$body .= '<p>' . count( $rows ) . ' names. <a href="' . esc_url( home_url( '/find-a-domain/' ) ) . '">Refine with the domain finder</a>.</p>';
		$body .= db_sales_result_list( $rows );
		$body .= db_sales_save_form( $c );
		db_sales_render( $cols[ $m[1] ]['title'], 'Collections', $body );
	}
}, 27 ); // After db-onpage-seo's output buffer (priority 26), so these pages get the same viewport and link fixes.

if ( ! function_exists( 'db_trust_center_body' ) ) {
	/** Only statements the plugin enforces in code. Refund and response terms link to their source pages. */
	function db_trust_center_body() {
		$items = array(
			array( 'Payments', 'Card details are entered into Stripe\'s payment form. Domain Brothers does not receive or store the card number.' ),
			array( 'Receipts', 'Each payment is confirmed by email. Receipt links are signed and expire.' ),
			array( 'Payment plans', 'The plan schedule and terms are shown before you accept. Your acceptance is recorded with the plan. Read the full terms on the payment plan page.' ),
			array( 'Transfer codes', 'Authorization (EPP) codes are encrypted when stored. The buyer receives a private link, never the code in an email, and the code is shown only after the buyer presses "Show my code". Codes for payment plans are held back until the final payment is made.' ),
			array( 'Offers', 'Every offer is answered by email and kept in your account, with the full negotiation history.' ),
			array( 'Transfers', 'Your account shows each transfer stage, from payment through to completion, with notes from our team.' ),
			array( 'Alerts and email', 'We email you only about domains and searches you chose to follow. Each alert email has a one-click stop link, and you can turn all alert emails off in your account.' ),
		);
		$h = '<p class="db-sales-lead">What we do, stated as the site enforces it. Questions go to our contact page.</p><dl class="db-sales-trust">';
		foreach ( $items as $it ) {
			$h .= '<div class="db-sales-card"><dt>' . esc_html( $it[0] ) . '</dt><dd>' . esc_html( $it[1] ) . '</dd></div>';
		}
		$h .= '</dl>';
		$h .= '<ul class="db-sales-links"><li><a href="' . esc_url( home_url( '/terms-of-use/' ) ) . '">Terms of use</a></li><li><a href="' . esc_url( home_url( '/payment-plan-setup/' ) ) . '">Payment plan terms</a></li><li><a href="' . esc_url( home_url( '/epp-guidelines/' ) ) . '">Transfer (EPP) guidelines</a></li><li><a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Contact us</a></li></ul>';
		return $h;
	}
}

/* ------------------------------------------- "Why this price?" on domains */

if ( ! function_exists( 'db_sales_comparables' ) ) {
	/** Recorded sales of similar names: same extension, length within two characters, price recorded. */
	function db_sales_comparables( array $f, $exclude_id ) {
		$cache = get_transient( 'db_sales_sold_index' );
		if ( false === $cache ) {
			$cache = array();
			foreach ( db_sold_listings() as $p ) {
				$price = (float) get_post_meta( $p->ID, 'db_sold_price', true );
				$at    = (int) get_post_meta( $p->ID, 'db_sold_at', true );
				$cache[] = array( 'id' => (int) $p->ID, 'name' => strtolower( $p->post_title ), 'price' => $price, 'at' => $at );
			}
			set_transient( 'db_sales_sold_index', $cache, HOUR_IN_SECONDS );
		}
		$out = array();
		foreach ( $cache as $row ) {
			if ( $row['id'] === (int) $exclude_id || $row['price'] <= 0 ) {
				continue;
			}
			$dot = strpos( $row['name'], '.' );
			$ext = false === $dot ? '' : substr( $row['name'], $dot );
			$len = false === $dot ? strlen( $row['name'] ) : $dot;
			if ( $ext !== $f['ext'] || abs( $len - (int) $f['len'] ) > 2 ) {
				continue;
			}
			$out[] = $row;
		}
		usort( $out, function ( $a, $b ) { return $b['at'] <=> $a['at']; } );
		return array_slice( $out, 0, 3 );
	}
}

if ( ! function_exists( 'db_sales_value_html' ) ) {
	function db_sales_value_html( $post ) {
		$f    = db_dd_facts( $post );
		$rows = array(
			'Length'    => $f['len'] . ' characters before the extension. Shorter names are easier to say, type and remember.',
			'Extension' => $f['ext'] . ' extension.',
			'Type'      => ucfirst( $f['type'] ) . '.',
		);
		if ( $f['cats'] ) {
			$rows['Category'] = implode( ', ', $f['cats'] ) . '.';
		}
		$h  = db_sales_css() . '<section class="db-sales-value" aria-labelledby="db-value-h"><h2 id="db-value-h">Why this price?</h2>';
		$h .= '<p>The price reflects the features below. We do not estimate a value we cannot support, so we show the recorded sales of similar names as the reference.</p>';
		$h .= '<dl class="db-sales-facts">';
		foreach ( $rows as $k => $v ) {
			$h .= '<div><dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd></div>';
		}
		$h .= '</dl>';

		$comps = db_sales_comparables( $f, $post->ID );
		if ( $comps ) {
			$h .= '<h3>Recent sales of similar names</h3><ul class="db-sales-comps">';
			foreach ( $comps as $c ) {
				$h .= '<li><strong>' . esc_html( $c['name'] ) . '</strong> sold for $' . esc_html( number_format( $c['price'], 0 ) ) . ( $c['at'] ? ' in ' . esc_html( wp_date( 'F Y', $c['at'] ) ) : '' ) . '.</li>';
			}
			$h .= '</ul>';
		} else {
			$h .= '<p>We have no recorded sales of similar names yet, so there is no comparison to show for this one.</p>';
		}

		$guide = trim( (string) get_post_meta( $post->ID, 'db_offer_guidance', true ) );
		if ( '' !== $guide ) {
			$h .= '<p class="db-sales-guide"><strong>Guidance from the seller:</strong> ' . esc_html( $guide ) . '</p>';
		}
		$h .= '<p class="db-sales-links"><a href="' . esc_url( home_url( '/find-a-domain/?broker=1&domain=' . rawurlencode( $f['name'] ) . '#db-broker' ) ) . '">Ask a broker to call about this name</a> · <a href="' . esc_url( home_url( '/trust-center/' ) ) . '">How buying and transfers work</a></p>';
		return $h . '</section>';
	}
}

if ( ! function_exists( 'db_sales_flush_sold_index' ) ) {
	function db_sales_flush_sold_index() {
		delete_transient( 'db_sales_sold_index' );
	}
}

/* ----------------------------------------------- transfer workspace */

if ( ! function_exists( 'db_transfer_stages' ) ) {
	function db_transfer_stages() {
		return array(
			'paid'              => 'Payment received',
			'registrar_lock'    => 'Registrar lock removed',
			'epp_sent'          => 'Transfer code sent',
			'transfer_approved' => 'Transfer approved',
			'dns_configured'    => 'DNS configured',
			'completed'         => 'Transfer completed',
		);
	}
}

add_action( 'add_meta_boxes', function () {
	add_meta_box( 'db_sales_box', 'Transfer and offer guidance', 'db_sales_meta_box', 'domain', 'side', 'default' );
} );

if ( ! function_exists( 'db_sales_meta_box' ) ) {
	function db_sales_meta_box( $post ) {
		wp_nonce_field( 'db_sales_meta', 'db_sales_meta_nonce' );
		$stage = (string) get_post_meta( $post->ID, 'db_transfer_stage', true );
		$note  = (string) get_post_meta( $post->ID, 'db_transfer_note', true );
		$guide = (string) get_post_meta( $post->ID, 'db_offer_guidance', true );
		echo '<p><label for="db_transfer_stage"><strong>Transfer stage</strong></label><br><select id="db_transfer_stage" name="db_transfer_stage" style="width:100%"><option value="">Not started</option>';
		foreach ( db_transfer_stages() as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $stage, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		echo '<p><label for="db_transfer_note"><strong>Note for the buyer</strong> (shown in their account)</label><textarea id="db_transfer_note" name="db_transfer_note" rows="3" style="width:100%">' . esc_textarea( $note ) . '</textarea></p>';
		echo '<p><label for="db_offer_guidance"><strong>Offer guidance</strong> (shown on the domain page; leave blank to hide)</label><textarea id="db_offer_guidance" name="db_offer_guidance" rows="3" style="width:100%">' . esc_textarea( $guide ) . '</textarea></p>';
		echo '<p class="description">Set the stage only after the step is done. Buyers see the stage and this note, nothing else.</p>';
		if ( function_exists( 'db_ops_team_thread_html' ) ) {
			db_ops_team_thread_html( $post );
		}
	}
}

add_action( 'save_post_domain', function ( $post_id ) {
	if ( ! isset( $_POST['db_sales_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['db_sales_meta_nonce'] ) ), 'db_sales_meta' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$stage = isset( $_POST['db_transfer_stage'] ) ? sanitize_key( wp_unslash( $_POST['db_transfer_stage'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( '' !== $stage && ! isset( db_transfer_stages()[ $stage ] ) ) {
		$stage = '';
	}
	$old_stage = (string) get_post_meta( $post_id, 'db_transfer_stage', true );
	update_post_meta( $post_id, 'db_transfer_stage', $stage );
	update_post_meta( $post_id, 'db_transfer_note', isset( $_POST['db_transfer_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['db_transfer_note'] ) ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	update_post_meta( $post_id, 'db_offer_guidance', isset( $_POST['db_offer_guidance'] ) ? sanitize_textarea_field( wp_unslash( $_POST['db_offer_guidance'] ) ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( function_exists( 'db_ops_stage_changed' ) ) {
		db_ops_stage_changed( $post_id, $old_stage, $stage, isset( $_POST['db_transfer_note'] ) ? wp_unslash( $_POST['db_transfer_note'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
	if ( function_exists( 'db_ops_team_reply' ) ) {
		db_ops_team_reply( $post_id );
	}
} );

if ( ! function_exists( 'db_sales_service_options' ) ) {
	function db_sales_service_options() {
		return array(
			array( 'Logo and brand kit', 'A logo and the basic brand files you need for the site, social profiles and print.' ),
			array( 'Website build', 'A site for the new name, built and hosted with the domain pointed to it.' ),
			array( 'Email and DNS setup', 'Mail for your new domain, with the DNS records set up and checked.' ),
			array( 'Marketing launch', 'A launch plan and the first campaign for the new name.' ),
		);
	}
}

if ( ! function_exists( 'db_sales_transfers_html' ) ) {
	/** The buyer's transfer timeline and service options, for the Transfers tab. */
	function db_sales_transfers_html( $email ) {
		$q = new WP_Query( array(
			'post_type'      => 'domain',
			'post_status'    => array( 'publish', 'private', 'draft', 'pending', 'unpublish', 'future' ),
			'posts_per_page' => 20,
			'no_found_rows'  => true,
			'meta_query'     => array( array( 'key' => 'db_sold_buyer', 'value' => $email, 'compare' => '=' ) ),
		) );
		if ( ! $q->posts ) {
			return db_sales_css() . '<div class="db-wl-card"><h2>No transfers yet</h2><p>Once a purchase is recorded, its transfer steps appear here with the next step and a way to message us.</p></div>';
		}
		$stages = db_transfer_stages();
		$keys   = array_keys( $stages );
		$h      = db_sales_css();
		$msgs   = array( 'sent' => 'Message sent. We will reply by email.', 'busy' => 'You have sent several messages in the last hour. Please try again later.', 'empty' => 'Please write a message before sending.', 'denied' => 'That domain is not on your account.', 'signin' => 'Sign in to write to our team.' );
		if ( isset( $_GET['msg'] ) && isset( $msgs[ sanitize_key( wp_unslash( $_GET['msg'] ) ) ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$h .= '<div class="db-sales-card db-sales-notice" role="status"><p>' . esc_html( $msgs[ sanitize_key( wp_unslash( $_GET['msg'] ) ) ] ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		foreach ( $q->posts as $p ) {
			$cur  = (string) get_post_meta( $p->ID, 'db_transfer_stage', true );
			$idx  = array_search( $cur, $keys, true );
			$note = (string) get_post_meta( $p->ID, 'db_transfer_note', true );
			$h   .= '<section class="db-wl-card db-sales-transfer"><h2>' . esc_html( strtolower( $p->post_title ) ) . '</h2>';
			$h   .= '<ol class="db-sales-steps">';
			foreach ( $keys as $i => $k ) {
				$state = false === $idx ? 'todo' : ( $i < $idx ? 'done' : ( $i === $idx ? 'current' : 'todo' ) );
				$h    .= '<li class="is-' . esc_attr( $state ) . '"><span>' . esc_html( $stages[ $k ] ) . '</span><em>' . esc_html( array( 'done' => 'Done', 'current' => 'Now', 'todo' => 'To do' )[ $state ] ) . '</em></li>';
			}
			$h .= '</ol>';
			if ( false === $idx ) {
				$h .= '<p>We will update this as each step is done.</p>';
			}
			if ( '' !== $note ) {
				$h .= '<p class="db-sales-note"><strong>From our team:</strong> ' . esc_html( $note ) . '</p>';
			}
			if ( function_exists( 'db_ops_thread_html' ) ) {
				$h .= db_ops_thread_html( $p->ID, $email );
			}
			$h .= '</section>';
		}
		$h .= '<h2 class="db-ac-h">Services for your new name</h2><p class="db-ac-lead">Optional. Each one is quoted separately; nothing here is added to your purchase.</p><ul class="db-sales-services">';
		foreach ( db_sales_service_options() as $s ) {
			$h .= '<li class="db-wl-card"><h3>' . esc_html( $s[0] ) . '</h3><p>' . esc_html( $s[1] ) . '</p><a href="' . esc_url( home_url( '/our-services/' ) ) . '">See our services</a></li>';
		}
		return $h . '</ul>';
	}
}

/* ------------------------------------------------ buyer-intent score */

if ( ! function_exists( 'db_intent_rows' ) ) {
	/**
	 * Explainable score per domain from 30-day views, wishlists and offers.
	 * views: up to 10 points (1 point per 6 views, capped at 60 views)
	 * wishlists: 2 points each, up to 5
	 * offers: 4 points each, up to 3
	 */
	function db_intent_rows() {
		global $wpdb;
		$since = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );
		$hits  = $wpdb->prefix . 'db_hits';
		$wl    = $wpdb->prefix . 'db_wl_items';
		$leads = $wpdb->prefix . 'db_leads';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$views = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, SUM(hits) AS n FROM {$hits} WHERE day >= %s GROUP BY post_id", $since ), OBJECT_K );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$saved = $wpdb->get_results( "SELECT post_id, COUNT(*) AS n FROM {$wl} GROUP BY post_id", OBJECT_K );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$offers = $wpdb->get_results( $wpdb->prepare( "SELECT LOWER(domain) AS d, COUNT(*) AS n FROM {$leads} WHERE source = 'offer_form' AND created_at >= %s GROUP BY LOWER(domain)", $since . ' 00:00:00' ), OBJECT_K );

		$ids = array_unique( array_merge( array_keys( (array) $views ), array_keys( (array) $saved ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$p = get_post( (int) $id );
			if ( ! $p || 'domain' !== $p->post_type ) {
				continue;
			}
			$name = strtolower( $p->post_title );
			$v    = isset( $views[ $id ] ) ? (int) $views[ $id ]->n : 0;
			$w    = isset( $saved[ $id ] ) ? (int) $saved[ $id ]->n : 0;
			$o    = isset( $offers[ $name ] ) ? (int) $offers[ $name ]->n : 0;
			$score = round( min( $v, 60 ) / 6 + min( $w, 5 ) * 2 + min( $o, 3 ) * 4, 1 );
			$band  = $score >= 12 ? 'hot' : ( $score >= 6 ? 'warm' : 'cool' );
			if ( 'hot' === $band && ( $o || $w ) ) {
				$task = 'Follow up within 2 hours';
			} elseif ( 'hot' === $band ) {
				$task = 'Send a personal note within 1 day';
			} elseif ( 'warm' === $band ) {
				$task = 'Review at the weekly check';
			} else {
				$task = 'No action';
			}
			$why = array();
			if ( $v ) { $why[] = $v . ' views in 30 days'; }
			if ( $w ) { $why[] = $w . ' wishlist ' . ( 1 === $w ? 'save' : 'saves' ); }
			if ( $o ) { $why[] = $o . ' ' . ( 1 === $o ? 'offer' : 'offers' ) . ' in 30 days'; }
			$out[] = array( 'post' => $p, 'score' => $score, 'band' => $band, 'task' => $task, 'why' => implode( ' · ', $why ) );
		}
		usort( $out, function ( $a, $b ) { return $b['score'] <=> $a['score']; } );
		return $out;
	}
}

/* ----------------------------------------------------------- admin */

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Sales funnel', 'Sales funnel', 'manage_options', 'db-funnel', 'db_funnel_admin_page' );
	add_submenu_page( 'db-crm-leads', 'Buyer intent', 'Buyer intent', 'manage_options', 'db-intent', 'db_intent_admin_page' );
}, 30 );

if ( ! function_exists( 'db_funnel_admin_page' ) ) {
	function db_funnel_admin_page() {
		$c     = db_funnel_counts( 30 );
		$steps = db_funnel_steps();
		echo '<div class="wrap"><h1>Sales funnel (last 30 days)</h1>';
		echo '<p>Server-side counts. No IP address, cookie or email is stored with these events, and staff and known bots are excluded. Checkout and offer steps are not in this report yet; use the Stripe and Offers screens for those.</p>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Step</th><th>Count</th><th>From previous step</th></tr></thead><tbody>';
		echo '<tr><td>' . esc_html( $steps['listing_view'] ) . '</td><td>' . (int) $c['listing_view'] . '</td><td>—</td></tr>';
		echo '<tr><td>' . esc_html( $steps['finder_start'] ) . '</td><td>' . (int) $c['finder_start'] . '</td><td>' . esc_html( db_funnel_rate( $c['finder_start'], $c['listing_view'] ) ) . '</td></tr>';
		echo '<tr><td>' . esc_html( $steps['finder_result'] ) . '</td><td>' . (int) $c['finder_result'] . '</td><td>' . esc_html( db_funnel_rate( $c['finder_result'], $c['finder_start'] ) ) . '</td></tr>';
		echo '<tr><td>' . esc_html( $steps['saved_search'] ) . '</td><td>' . (int) $c['saved_search'] . '</td><td>' . esc_html( db_funnel_rate( $c['saved_search'], $c['finder_result'] ) ) . '</td></tr>';
		echo '<tr><td>' . esc_html( $steps['broker_request'] ) . '</td><td>' . (int) $c['broker_request'] . '</td><td>' . esc_html( db_funnel_rate( $c['broker_request'], $c['finder_result'] ) ) . '</td></tr>';
		echo '<tr><td>' . esc_html( $steps['trust_view'] ) . '</td><td>' . (int) $c['trust_view'] . '</td><td>—</td></tr>';
		echo '</tbody></table></div>';
	}
}

if ( ! function_exists( 'db_intent_admin_page' ) ) {
	function db_intent_admin_page() {
		$rows = db_intent_rows();
		echo '<div class="wrap"><h1>Buyer intent (last 30 days)</h1>';
		echo '<p>Score = views (up to 10) + wishlist saves (2 each, up to 5) + offers (4 each, up to 3). Hot is 12 or more, warm is 6 or more. The page shows counts only, never buyer emails; open the CRM for the contact details of a lead.</p>';
		if ( ! $rows ) {
			echo '<p>No activity in the last 30 days yet.</p></div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Domain</th><th>Score</th><th>Band</th><th>Why</th><th>Next step</th></tr></thead><tbody>';
		foreach ( array_slice( $rows, 0, 100 ) as $r ) {
			echo '<tr><td><a href="' . esc_url( get_edit_post_link( $r['post']->ID ) ) . '">' . esc_html( strtolower( $r['post']->post_title ) ) . '</a></td>'
				. '<td>' . esc_html( (string) $r['score'] ) . '</td><td>' . esc_html( ucfirst( $r['band'] ) ) . '</td><td>' . esc_html( $r['why'] ) . '</td><td>' . esc_html( $r['task'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

/* ------------------------------------------- theme copy that claims escrow */

if ( ! function_exists( 'db_copy_fix_claims' ) ) {
	/**
	 * The theme's "Get started" steps (home page) say "Pay securely using Escrow".
	 * The theme template is outside this plugin, so its output is corrected here
	 * until the template text is changed. Each pair is an exact string match.
	 */
	function db_copy_fix_claims( $html ) {
		return str_replace(
			array( 'Pay securely using Escrow &amp; own it', 'Pay securely using Escrow & own it' ),
			array( 'Pay securely and own it', 'Pay securely and own it' ),
			$html
		);
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || is_feed() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
		return;
	}
	ob_start( 'db_copy_fix_claims' );
}, 1000 );
