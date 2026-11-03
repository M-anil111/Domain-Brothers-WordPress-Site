<?php
/**
 * Listing states: every domain page is one of four kinds.
 *
 *   standard      for sale at a fixed price: buy now, pay monthly, or offer.
 *   offer         for sale by custom offer only (no price): the buyer names a
 *                 price and the team negotiates (see db-offers-block.php).
 *   not_for_sale  the owner is not selling; the page says so and takes an
 *                 enquiry instead of an offer.
 *   sold          sold, or reserved on someone's payment plan.
 *
 * The state is derived, in this order:
 *   1. the "Listing status" box on the domain's edit screen (meta
 *      db_listing_state: not_for_sale | sold | auto);
 *   2. the legacy domain_status = "sold";
 *   3. a completed sale or an active payment plan recorded by the Stripe block;
 *   4. otherwise a price (domain_price > 0) means standard, none means offer.
 *
 * Sold and not-for-sale names get their own page template (the theme's own
 * template kept saying "is for sale!" and showing the offer form on a sold
 * name), are refused by the checkout, and are left out of the domain list and
 * of recommendations.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

/* ───────────────────────────── 1. State ───────────────────────────────── */

if ( ! function_exists( 'db_listing_states' ) ) {
	/** state => admin label */
	function db_listing_states() {
		return array(
			'standard'     => 'For sale: fixed price',
			'offer'        => 'For sale: custom offers',
			'not_for_sale' => 'Not for sale',
			'sold'         => 'Sold',
		);
	}
}

if ( ! function_exists( 'db_listing_price' ) ) {
	function db_listing_price( $post_id ) {
		$raw = preg_replace( '/[^0-9.]/', '', (string) get_post_meta( (int) $post_id, 'domain_price', true ) );
		return ( '' !== $raw && is_numeric( $raw ) && (float) $raw > 0 ) ? (float) $raw : 0.0;
	}
}

if ( ! function_exists( 'db_listing_state' ) ) {
	/**
	 * @param int|WP_Post $post A domain listing.
	 * @return string standard | offer | not_for_sale | sold
	 */
	function db_listing_state( $post = 0 ) {
		static $cache = array();
		$post = get_post( $post );
		if ( ! $post || 'domain' !== $post->post_type ) {
			return 'offer';
		}
		$id = (int) $post->ID;
		if ( isset( $cache[ $id ] ) ) {
			return $cache[ $id ];
		}

		$override = (string) get_post_meta( $id, 'db_listing_state', true );
		if ( in_array( $override, array( 'not_for_sale', 'sold' ), true ) ) {
			$state = $override;
		} elseif ( 'sold' === strtolower( trim( (string) get_post_meta( $id, 'domain_status', true ) ) ) ) {
			$state = 'sold';
		} elseif ( function_exists( 'db_stripe_domain_taken' ) && db_stripe_domain_taken( strtolower( trim( wp_strip_all_tags( $post->post_title ) ) ) ) ) {
			$state = 'sold';
		} else {
			$state = db_listing_price( $id ) > 0 ? 'standard' : 'offer';
		}
		return $cache[ $id ] = $state;
	}
}

if ( ! function_exists( 'db_listing_is_sellable' ) ) {
	function db_listing_is_sellable( $post ) {
		return in_array( db_listing_state( $post ), array( 'standard', 'offer' ), true );
	}
}

if ( ! function_exists( 'db_listing_post_for_domain' ) ) {
	/** The published listing whose title is $domain, or null. */
	function db_listing_post_for_domain( $domain ) {
		static $cache = array();
		$domain = strtolower( trim( (string) $domain ) );
		if ( '' === $domain || strlen( $domain ) > 100 ) {
			return null;
		}
		if ( array_key_exists( $domain, $cache ) ) {
			return $cache[ $domain ];
		}
		$q    = new WP_Query( array( 'post_type' => 'domain', 'post_status' => 'publish', 'title' => $domain, 'posts_per_page' => 1, 'no_found_rows' => true ) );
		$post = ( $q->posts && 0 === strcasecmp( $q->posts[0]->post_title, $domain ) ) ? $q->posts[0] : null;
		return $cache[ $domain ] = $post;
	}
}

if ( ! function_exists( 'db_listing_blocked_by_name' ) ) {
	/** True when the admin has marked the listing named $domain sold or not for sale. */
	function db_listing_blocked_by_name( $domain ) {
		$post = db_listing_post_for_domain( $domain );
		return $post && in_array( db_listing_state( $post ), array( 'sold', 'not_for_sale' ), true );
	}
}

if ( ! function_exists( 'db_listing_mark_sold' ) ) {
	/** Called when a sale completes: records it on the listing and refreshes caches. */
	function db_listing_mark_sold( $domain ) {
		$post = db_listing_post_for_domain( $domain );
		if ( ! $post ) {
			return;
		}
		update_post_meta( $post->ID, 'db_listing_state', 'sold' );
		update_post_meta( $post->ID, 'db_sold_at', time() );
		delete_transient( defined( 'DB_DOMAIN_INDEX_KEY' ) ? DB_DOMAIN_INDEX_KEY : 'db_domain_index_v3' );
		do_action( 'litespeed_purge_post', $post->ID );
	}
}

/* ───────────────────── 2. Admin: "Listing status" box ──────────────────── */

add_action( 'add_meta_boxes_domain', function () {
	add_meta_box( 'db_listing_state', 'Listing status', function ( $post ) {
		$current = (string) get_post_meta( $post->ID, 'db_listing_state', true );
		$current = in_array( $current, array( 'not_for_sale', 'sold' ), true ) ? $current : 'auto';
		$effective = db_listing_state( $post );
		wp_nonce_field( 'db_listing_state_save', 'db_listing_state_nonce' );
		$opts = array(
			'auto'         => 'Automatic (price decides: fixed price, or custom offers)',
			'not_for_sale' => 'Not for sale (shows the "not for sale" page)',
			'sold'         => 'Sold (shows the "sold" page; checkout closed)',
		);
		foreach ( $opts as $val => $label ) {
			printf(
				'<p><label><input type="radio" name="db_listing_state" value="%s"%s> %s</label></p>',
				esc_attr( $val ),
				checked( $current, $val, false ),
				esc_html( $label )
			);
		}
		$states = db_listing_states();
		echo '<p style="margin-top:12px"><strong>Shown to visitors as:</strong> ' . esc_html( $states[ $effective ] ?? $effective ) . '</p>';
	}, 'domain', 'side', 'high' );
} );

add_action( 'save_post_domain', function ( $post_id, $post ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['db_listing_state_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['db_listing_state_nonce'] ) ), 'db_listing_state_save' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$val = isset( $_POST['db_listing_state'] ) ? sanitize_key( wp_unslash( $_POST['db_listing_state'] ) ) : 'auto';
	if ( 'auto' === $val || ! in_array( $val, array( 'not_for_sale', 'sold' ), true ) ) {
		delete_post_meta( $post_id, 'db_listing_state' );
		return;
	}
	update_post_meta( $post_id, 'db_listing_state', $val );
	if ( 'sold' === $val && ! get_post_meta( $post_id, 'db_sold_at', true ) ) {
		update_post_meta( $post_id, 'db_sold_at', time() );
	}
}, 10, 2 );

add_filter( 'manage_domain_posts_columns', function ( $cols ) {
	$cols['db_listing'] = 'Listing';
	return $cols;
} );
add_action( 'manage_domain_posts_custom_column', function ( $col, $post_id ) {
	if ( 'db_listing' !== $col ) {
		return;
	}
	$state  = db_listing_state( $post_id );
	$states = db_listing_states();
	$colors = array( 'standard' => '#1f5c0d', 'offer' => '#8a5a00', 'not_for_sale' => '#4b5563', 'sold' => '#991b1b' );
	printf( '<strong style="color:%s">%s</strong>', esc_attr( $colors[ $state ] ?? '#111' ), esc_html( $states[ $state ] ?? $state ) );
}, 10, 2 );

/* ──────────────── 3. Template for sold / not-for-sale pages ────────────── */

add_filter( 'template_include', function ( $template ) {
	if ( ! is_singular( 'domain' ) ) {
		return $template;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || '' !== $post->post_password ) {
		return $template;
	}
	$file = __DIR__ . '/db-listing-template.php';
	if ( in_array( db_listing_state( $post ), array( 'sold', 'not_for_sale' ), true ) && is_readable( $file ) ) {
		return $file;
	}
	return $template;
}, 98 );

add_filter( 'document_title_parts', function ( $parts ) {
	if ( is_singular( 'domain' ) ) {
		$post  = get_queried_object();
		$state = $post instanceof WP_Post ? db_listing_state( $post ) : '';
		$name  = $post instanceof WP_Post ? wp_strip_all_tags( $post->post_title ) : '';
		if ( 'sold' === $state ) {
			$parts['title'] = $name . ' has been sold';
		} elseif ( 'not_for_sale' === $state ) {
			$parts['title'] = $name . ' is not for sale';
		}
	}
	return $parts;
}, 99 );

// A sold page has nothing to rank for; offer links with a private token are never indexable.
add_filter( 'wp_robots', function ( $robots ) {
	if ( is_singular( 'domain' ) ) {
		$post = get_queried_object();
		if ( ( $post instanceof WP_Post && 'sold' === db_listing_state( $post ) ) || isset( $_GET['c'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$robots['noindex'] = true;
			$robots['follow']  = true;
			unset( $robots['index'] );
		}
	}
	return $robots;
}, 99 );

if ( ! function_exists( 'db_listing_css' ) ) {
	function db_listing_css() {
		return '.db-un{max-width:860px;margin:0 auto;padding:clamp(20px,4vw,40px) 0}'
			. '.db-un-card{background:#fff;border:1px solid #e5eaf1;border-radius:18px;padding:clamp(24px,5vw,48px);box-shadow:0 10px 30px rgba(10,22,40,.08);text-align:center}'
			. '.db-un-eyebrow{display:block;width:max-content;max-width:100%;margin:0 auto;line-height:1.2;padding:6px 14px;border-radius:999px;font-size:12px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;background:#f1f5f9;color:#334155}'
			. '.db-un--sold .db-un-eyebrow{background:#fee2e2;color:#991b1b}.db-un--nfs .db-un-eyebrow{background:#fef3c7;color:#8a5a00}'
			. '.db-un-name{margin:22px 0 10px!important;font-size:clamp(28px,6vw,46px);line-height:1.1;font-weight:800;color:#0a1628;overflow-wrap:anywhere}'
			. '.db-un--sold .db-un-name{text-decoration:line-through;text-decoration-thickness:3px;text-decoration-color:#fca5a5;color:#64748b}'
			. '.db-un-lead{margin:0 auto 22px!important;max-width:560px;text-align:center;font-size:18px;color:#334155}'
			. '.db-un-actions{display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin:20px 0 4px}'
			. '.db-un-btn{display:inline-flex;align-items:center;justify-content:center;min-height:52px;padding:0 26px;border-radius:10px;font-weight:700;text-decoration:none!important;border:2px solid #0a1628}'
			. '.db-un-btn--primary{background:#0a1628;color:#fff!important}.db-un-btn--ghost{background:#fff;color:#0a1628!important}'
			. '.db-un-btn:focus-visible{outline:3px solid #2e7d14;outline-offset:2px}'
			. '.db-un-form{max-width:520px;margin:26px auto 0;text-align:left}.db-un-form h2{font-size:20px;margin:0 0 6px;color:#0a1628}.db-un-form p{margin:0 0 14px;color:#4b5563}'
			. '.db-un-form label{display:block;margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#2e7d14}'
			. '.db-un-form input[type=email],.db-un-form input[type=text],.db-un-form textarea{width:100%;box-sizing:border-box;padding:12px 14px;border:2px solid #cbd5e1;border-radius:10px;font:inherit;margin-bottom:14px}'
			. '.db-un-form input[type=email],.db-un-form input[type=text]{height:52px;padding-top:0;padding-bottom:0}'
			. '.db-un-form input:focus,.db-un-form textarea:focus{outline:none;border-color:#2e7d14;box-shadow:0 0 0 4px rgba(46,125,20,.18)}'
			. '.db-un-form button{width:100%;min-height:54px;border:0;border-radius:10px;background:#0a1628;color:#fff;font-weight:700;font-size:16px;cursor:pointer}'
			. '.db-un-hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;overflow:hidden}'
			. '.db-un-note{max-width:520px;margin:20px auto 0;padding:14px 16px;border-radius:10px;font-weight:600}.db-un-note--ok{background:#eaf5e3;border:1px solid #c9e4b8;color:#1f5c0d}.db-un-note--err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}'
			. '.db-un-more{margin-top:34px}.db-un-more h2{font-size:22px;text-align:center;margin:0 0 14px;color:#0a1628}'
			. 'body.single-domain.db-ui-active .db-breadcrumbs,body.single-domain.db-ui-active .db-breadcrumbs a,body.single-domain.db-ui-active .db-breadcrumbs span{color:#4b5563!important}body.single-domain.db-ui-active .db-breadcrumbs [aria-current],body.single-domain.db-ui-active .db-breadcrumbs li:last-child{color:#0a1628!important}'
			. '.db-un-search{display:flex;gap:8px;max-width:520px;margin:22px auto 0}.db-un-search input{flex:1;height:52px;padding:0 14px;border:2px solid #cbd5e1;border-radius:10px;font:inherit}'
			. '.db-un-search button{min-height:52px;padding:0 22px;border:0;border-radius:10px;background:#0a1628;color:#fff;font-weight:700;cursor:pointer}';
	}
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( 'domain' ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || ! in_array( db_listing_state( $post ), array( 'sold', 'not_for_sale' ), true ) ) {
		return;
	}
	if ( ! function_exists( 'db_external_style' ) || ! db_external_style( 'listing', db_listing_css() ) ) {
		add_action( 'wp_head', function () {
			echo '<style id="db-listing-css">' . db_listing_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}, 100 );
	}
	if ( function_exists( 'db_rec_css' ) ) {
		wp_register_style( 'db-un-rec', false );
		wp_enqueue_style( 'db-un-rec' );
		wp_add_inline_style( 'db-un-rec', db_rec_css() );
	}
}, 110 );

if ( ! function_exists( 'db_listing_unavailable_html' ) ) {
	/** Body of the sold / not-for-sale page. */
	function db_listing_unavailable_html( WP_Post $post, $state ) {
		$name     = strtolower( trim( wp_strip_all_tags( $post->post_title ) ) );
		$sold     = 'sold' === $state;
		$sold_at  = (int) get_post_meta( $post->ID, 'db_sold_at', true );
		$contact  = home_url( '/contact-us/' );
		$browse   = home_url( '/all-domains/' );
		$inq      = isset( $_GET['inq'] ) ? sanitize_key( wp_unslash( $_GET['inq'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$h  = '<section class="db-un ' . ( $sold ? 'db-un--sold' : 'db-un--nfs' ) . '" aria-labelledby="db-un-h">';
		$h .= '<div class="db-un-card">';
		$h .= '<span class="db-un-eyebrow">' . ( $sold ? 'Sold' : 'Not for sale' ) . '</span>';
		$h .= '<h1 class="db-un-name" id="db-un-h">' . esc_html( $name ) . '</h1>';
		if ( $sold ) {
			$h .= '<p class="db-un-lead">This domain has a new owner and is no longer available' . ( $sold_at ? ' (sold ' . esc_html( wp_date( 'F j, Y', $sold_at ) ) . ')' : '' ) . '. We have similar names that are.</p>';
			$h .= '<div class="db-un-actions"><a class="db-un-btn db-un-btn--primary" href="' . esc_url( $browse ) . '">Browse available domains</a><a class="db-un-btn db-un-btn--ghost" href="' . esc_url( $contact ) . '">Tell us what you need</a></div>';
		} else {
			$h .= '<p class="db-un-lead">The owner is not selling this domain right now. If you would still like it, we can ask whether they would consider an offer.</p>';
			if ( 'ok' === $inq ) {
				$h .= '<p class="db-un-note db-un-note--ok" role="status">Thanks. We have your request and will come back to you by email.</p>';
			} elseif ( in_array( $inq, array( 'bad', 'wait' ), true ) ) {
				$h .= '<p class="db-un-note db-un-note--err" role="alert">' . ( 'wait' === $inq ? 'Please wait a little before sending another request.' : 'Please enter a valid email address and try again.' ) . '</p>';
			}
			if ( 'ok' !== $inq ) {
				$h .= '<form class="db-un-form" id="inquiry" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				$h .= '<h2>Ask the owner</h2><p>Leave your email. We will let you know what they say.</p>';
				$h .= '<input type="hidden" name="action" value="db_listing_inquiry"><input type="hidden" name="listing" value="' . (int) $post->ID . '"><input type="hidden" name="t" value="' . time() . '">';
				$h .= '<div class="db-un-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
				$h .= '<label for="db-un-email">Your email</label><input type="email" id="db-un-email" name="email" required autocomplete="email" maxlength="190">';
				$h .= '<label for="db-un-msg">Anything we should tell the owner? (optional)</label><textarea id="db-un-msg" name="message" rows="3" maxlength="600"></textarea>';
				$h .= '<button type="submit">Ask the owner</button></form>';
			}
		}
		$h .= '</div>';

		$recs = function_exists( 'db_rec_for' ) ? db_rec_for( $post->ID, 6 ) : array();
		if ( $recs && function_exists( 'db_rec_cards_html' ) ) {
			$h .= '<div class="db-un-more"><h2>Similar domains that are available</h2>' . db_rec_cards_html( $recs ) . '</div>';
		}
		$h .= '<form class="db-un-search" role="search" method="get" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="post_type" value="domain"><label class="screen-reader-text" for="db-un-s">Search domains</label><input id="db-un-s" type="search" name="s" placeholder="Search all domains"><button type="submit">Search</button></form>';
		$h .= '</section>';
		return $h;
	}
}

/* ───────────── 4. "Ask the owner" enquiry (not-for-sale pages) ─────────── */

foreach ( array( 'admin_post_nopriv_db_listing_inquiry', 'admin_post_db_listing_inquiry' ) as $hook ) {
	add_action( $hook, 'db_listing_handle_inquiry' );
}

if ( ! function_exists( 'db_listing_handle_inquiry' ) ) {
	function db_listing_handle_inquiry() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public form: no session to protect; guarded by honeypot, timing, per-IP limit.
		$post = get_post( isset( $_POST['listing'] ) ? (int) $_POST['listing'] : 0 );
		if ( ! $post || 'domain' !== $post->post_type || 'publish' !== $post->post_status || 'not_for_sale' !== db_listing_state( $post ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$back = get_permalink( $post );
		$go   = static function ( $code ) use ( $back ) {
			wp_safe_redirect( add_query_arg( 'inq', $code, $back ) . '#inquiry' );
			exit;
		};

		if ( ! empty( $_POST['website'] ) ) {
			$go( 'ok' ); // bot: look successful, do nothing.
		}
		$t = isset( $_POST['t'] ) ? (int) $_POST['t'] : 0;
		if ( $t > 0 && ( time() - $t ) < 3 ) {
			$go( 'wait' );
		}
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'db_inq_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 3 ) {
			$go( 'wait' );
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			$go( 'bad' );
		}
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';
		$message = function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 600 ) : substr( $message, 0, 600 );
		$domain  = strtolower( trim( wp_strip_all_tags( $post->post_title ) ) );

		if ( function_exists( 'db_crm_create_lead' ) ) {
			db_crm_create_lead( array(
				'domain'  => $domain,
				'email'   => $email,
				'message' => $message,
				'source'  => 'inquiry',
				'status'  => 'new',
				'notes'   => 'Asked whether the owner would consider selling a not-for-sale domain.',
			) );
		}
		// Admin alert only: nothing the visitor typed is sent to anyone else.
		$to = (string) get_option( 'admin_email' );
		if ( is_email( $to ) ) {
			wp_mail( $to, 'Not-for-sale enquiry: ' . $domain, "Someone asked whether the owner of {$domain} would consider selling.\n\nEmail: {$email}\n\n" . ( '' !== $message ? "Message:\n{$message}\n\n" : '' ) . 'See Domain Brothers > All Leads.' );
		}
		$go( 'ok' );
		// phpcs:enable
	}
}
