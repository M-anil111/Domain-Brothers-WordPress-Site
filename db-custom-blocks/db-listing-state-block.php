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
	/** Called when a sale completes: records it (price, buyer, how) on the listing and refreshes caches. */
	function db_listing_mark_sold( $domain, $price = 0.0, $buyer_email = '', $via = 'website' ) {
		$post = db_listing_post_for_domain( $domain );
		if ( ! $post ) {
			return;
		}
		update_post_meta( $post->ID, 'db_listing_state', 'sold' );
		update_post_meta( $post->ID, 'db_sold_at', time() );
		if ( (float) $price > 0 ) {
			update_post_meta( $post->ID, 'db_sold_price', round( (float) $price, 2 ) );
		}
		if ( is_email( $buyer_email ) ) {
			update_post_meta( $post->ID, 'db_sold_buyer', sanitize_email( $buyer_email ) );
		}
		update_post_meta( $post->ID, 'db_sold_via', sanitize_key( $via ) );
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
		$sp  = get_post_meta( $post->ID, 'db_sold_price', true );
		$sat = (int) get_post_meta( $post->ID, 'db_sold_at', true );
		$sb  = (string) get_post_meta( $post->ID, 'db_sold_buyer', true );
		echo '<hr><p><strong>If sold</strong> (shown in Domain Brothers &gt; Sold domains)</p>';
		echo '<p><label>Sold for (USD)<br><input type="text" name="db_sold_price" value="' . esc_attr( '' !== (string) $sp ? (string) $sp : '' ) . '" class="widefat" inputmode="decimal"></label></p>';
		echo '<p><label>Sold on<br><input type="date" name="db_sold_date" value="' . esc_attr( $sat ? wp_date( 'Y-m-d', $sat ) : '' ) . '" class="widefat"></label></p>';
		echo '<p><label>Buyer email (for the transfer code)<br><input type="email" name="db_sold_buyer" value="' . esc_attr( $sb ) . '" class="widefat"></label></p>';
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

// Sale details can be filled in for any listing the admin has sold, including ones marked sold in the theme's own box.
add_action( 'save_post_domain', function ( $post_id ) {
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['db_listing_state_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['db_listing_state_nonce'] ) ), 'db_listing_state_save' ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$price = isset( $_POST['db_sold_price'] ) ? preg_replace( '/[^0-9.]/', '', sanitize_text_field( wp_unslash( $_POST['db_sold_price'] ) ) ) : '';
	if ( '' !== $price && is_numeric( $price ) && (float) $price > 0 ) {
		update_post_meta( $post_id, 'db_sold_price', round( (float) $price, 2 ) );
	} else {
		delete_post_meta( $post_id, 'db_sold_price' );
	}
	$date = isset( $_POST['db_sold_date'] ) ? sanitize_text_field( wp_unslash( $_POST['db_sold_date'] ) ) : '';
	$ts   = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? strtotime( $date . ' 12:00:00 UTC' ) : 0;
	if ( $ts ) {
		update_post_meta( $post_id, 'db_sold_at', $ts );
	}
	$buyer = isset( $_POST['db_sold_buyer'] ) ? sanitize_email( wp_unslash( $_POST['db_sold_buyer'] ) ) : '';
	if ( is_email( $buyer ) ) {
		update_post_meta( $post_id, 'db_sold_buyer', $buyer );
	} else {
		delete_post_meta( $post_id, 'db_sold_buyer' );
	}
}, 11 );

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

add_filter( 'body_class', function ( $classes ) {
	if ( is_singular( 'domain' ) ) {
		$post  = get_queried_object();
		$state = $post instanceof WP_Post ? db_listing_state( $post ) : '';
		if ( 'sold' === $state ) {
			$classes[] = 'db-listing-sold';
		} elseif ( 'not_for_sale' === $state ) {
			$classes[] = 'db-listing-nfs';
		}
	}
	return $classes;
} );

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


if ( ! function_exists( 'db_listing_sold_css' ) ) {
	function db_listing_sold_css() {
		return 'body.single-domain #page.container:has(.db-sold){padding-left:0!important;padding-right:0!important}'
			. 'body.db-listing-sold .db-breadcrumbs,body.db-listing-nfs .db-breadcrumbs{display:none!important}'
			. 'body.single-domain #content.site-content:has(.db-sold){padding:0!important;max-width:none;min-height:0!important}'
			. 'body.single-domain #main.site-main:has(.db-sold){padding:0!important;background:none!important;box-shadow:none!important;border:0!important}'
			. '.db-sold{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;color:#1f2937}'
			. '.db-sold-in{max-width:1100px;margin:0 auto;padding:0 clamp(16px,4vw,32px)}'
			. '.db-sold-band{background:#00476c;color:#fff;text-align:center;padding:clamp(28px,5vw,44px) 16px clamp(30px,5vw,48px)}'
			. '.db-sold-sign{display:flex;justify-content:center;min-height:120px}.db-sold-sign img,.db-sold-signsvg{display:block;height:auto;max-width:100%}'
			. '.db-sold .db-sold-name{margin:14px 0 0!important;font-family:latobold,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;font-weight:700;font-size:clamp(28px,6vw,42px);line-height:1.15;color:#67bd3c!important;overflow-wrap:anywhere}'
			. '.db-sold .db-sold-lead{max-width:620px;margin:14px auto 0!important;font-size:18px;color:#e6eef4!important}'
			. '.db-sold-actions{display:flex;gap:12px;flex-wrap:wrap;justify-content:center;margin-top:22px}'
			. '.db-sold-btn{background:#67bd3c!important;border-color:#67bd3c!important;color:#08202e!important}.db-sold-btn--ghost{background:transparent!important;color:#fff!important;border-color:#fff!important}'
			. '.db-sold-ask{padding-top:28px;padding-bottom:6px}.db-sold-ask .db-un-form{margin:0 auto;background:#fff;border:1px solid #e5eaf1;border-radius:18px;padding:clamp(20px,4vw,32px);box-shadow:0 10px 30px rgba(10,22,40,.08)}.db-sold-ask .db-un-note{margin-top:0}'
			. '.db-sold-more{padding-top:34px;padding-bottom:10px}'
			. '.db-sold .db-sold-h2{margin:0 0 22px!important;text-align:center;font-size:clamp(26px,5vw,38px);font-weight:400;color:#00476c!important;line-height:1.2}'
			. '.db-sold-news{padding:34px 0 26px;background:linear-gradient(#f3f4f4,#fff 70%)}'
			. '.db-sold-carousel{position:relative;padding:0 34px}'
			. '.db-sold-track{display:flex;gap:20px;overflow-x:auto;scroll-snap-type:x mandatory;scroll-behavior:smooth;padding:6px 2px 36px;scrollbar-width:none;-webkit-overflow-scrolling:touch}.db-sold-track::-webkit-scrollbar{display:none}'
			. '.db-sold-track:focus-visible{outline:3px solid #2e7d14;outline-offset:2px}'
			. '.db-sold-card{position:relative;flex:0 0 calc((100% - 40px)/3);min-width:250px;scroll-snap-align:start;box-sizing:border-box;background:#fff;border:1px solid #dfe3e8;padding:20px 20px 46px;min-height:190px}'
			. '.db-sold-card h3{margin:0 0 10px!important;font-size:20px;line-height:1.25;font-weight:400}.db-sold-card h3 a{color:#2e7d14!important;text-decoration:none}.db-sold-card h3 a:hover,.db-sold-card h3 a:focus-visible{text-decoration:underline}'
			. '.db-sold-card p{margin:0!important;font-size:14px;line-height:1.55;color:#374151}'
			. '.db-sold-date{position:absolute;right:-1px;bottom:-26px;width:56px;padding:6px 0;background:#00476c;color:#fff;text-align:center;line-height:1.1;font-style:normal}.db-sold-date b{display:block;font-size:20px;font-weight:700}.db-sold-date i{display:block;font-size:10px;font-style:normal}'
			. '.db-sold-arrow{position:absolute;top:calc(50% - 30px);z-index:2;width:36px;height:36px;border:0;border-radius:50%;background:#2e7d14;color:#fff;font-size:26px;line-height:34px;cursor:pointer;padding:0}.db-sold-arrow--prev{left:0}.db-sold-arrow--next{right:0}.db-sold-arrow:focus-visible{outline:3px solid #0a1628;outline-offset:2px}'
			. '.db-sold-testi{padding:34px 0 clamp(90px,16vw,220px);background-size:cover;background-position:50% 100%;background-repeat:no-repeat}'
			. '.db-sold-quotes{position:relative;max-width:860px;margin:0 auto;text-align:center}'
			. '.db-sold-quote{display:none;margin:0;position:relative;padding:0 clamp(26px,7vw,64px)}.db-sold-quote.is-on{display:block}'
			. '.db-sold-quote::before,.db-sold-quote::after{position:absolute;font-family:Georgia,serif;font-size:64px;line-height:1;color:#67bd3c}.db-sold-quote::before{content:"\\201C";left:0;top:0}.db-sold-quote::after{content:"\\201D";right:0;top:0}'
			. '.db-sold .db-sold-quote blockquote{margin:0!important;padding:0!important;border:0!important;background:none!important;box-shadow:none!important;quotes:none}.db-sold .db-sold-quote blockquote::before,.db-sold .db-sold-quote blockquote::after{content:none!important}.db-sold-quote blockquote p{margin:0!important;font-size:clamp(16px,2.4vw,19px);line-height:1.65;color:#1f2937}'
			. '.db-sold-quote figcaption{margin-top:22px;font-size:18px;font-weight:600;color:#2e7d14}.db-sold-stars{margin-top:12px;color:#e5a400;font-size:22px;letter-spacing:4px}'
			. '.db-sold-dots{display:flex;justify-content:center;gap:12px;margin-top:14px}.db-sold-dot{width:24px;height:24px;border-radius:50%;border:0;background:transparent;position:relative;cursor:pointer;padding:0}.db-sold-dot::after{content:"";position:absolute;inset:6px;border-radius:50%;border:2px solid #67bd3c;background:#fff}.db-sold-dot.is-on::after{background:#67bd3c}.db-sold-dot:focus-visible{outline:3px solid #0a1628;outline-offset:1px}'
			. '@media (max-width:860px){.db-sold-card{flex-basis:calc((100% - 20px)/2)}}@media (max-width:560px){.db-sold-carousel{padding:0 4px}.db-sold-arrow{display:none}.db-sold-card{flex-basis:86%}}'
			. '@media (prefers-reduced-motion:reduce){.db-sold-track{scroll-behavior:auto}}';
	}
}

if ( ! function_exists( 'db_listing_sold_js' ) ) {
	function db_listing_sold_js() {
		return '(function(){var t=document.querySelector(".db-sold-track");if(t){var step=function(d){var c=t.querySelector(".db-sold-card");t.scrollBy({left:d*(c?c.getBoundingClientRect().width+20:300)})};'
			. 'var p=document.querySelector(".db-sold-arrow--prev"),n=document.querySelector(".db-sold-arrow--next");if(p)p.addEventListener("click",function(){step(-1)});if(n)n.addEventListener("click",function(){step(1)});}'
			. 'var q=document.querySelector("[data-db-quotes]");if(q){var f=q.querySelectorAll(".db-sold-quote"),d=q.querySelectorAll(".db-sold-dot"),i=0,tm=null;'
			. 'var show=function(k){i=(k+f.length)%f.length;f.forEach(function(e,j){e.classList.toggle("is-on",j===i)});d.forEach(function(e,j){e.classList.toggle("is-on",j===i);if(j===i){e.setAttribute("aria-current","true")}else{e.removeAttribute("aria-current")}})};'
			. 'd.forEach(function(e,j){e.addEventListener("click",function(){show(j);clear()})});var clear=function(){if(tm){clearInterval(tm);tm=null}};'
			. 'if(!window.matchMedia||!window.matchMedia("(prefers-reduced-motion: reduce)").matches){tm=setInterval(function(){show(i+1)},7000);q.addEventListener("focusin",clear);q.addEventListener("mouseenter",clear)}}})();';
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
	$css = db_listing_css() . db_listing_sold_css();
	if ( ! function_exists( 'db_external_style' ) || ! db_external_style( 'listing', $css ) ) {
		add_action( 'wp_head', function () use ( $css ) {
			echo '<style id="db-listing-css">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}, 100 );
	}
	add_action( 'wp_footer', function () {
		echo '<script id="db-sold-js">' . db_listing_sold_js() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}, 50 );
	if ( function_exists( 'db_rec_css' ) ) {
		wp_register_style( 'db-un-rec', false );
		wp_enqueue_style( 'db-un-rec' );
		wp_add_inline_style( 'db-un-rec', db_rec_css() );
	}
}, 110 );

if ( ! function_exists( 'db_listing_news' ) ) {
	/** The latest news posts as carousel cards: [title, excerpt, url, day, month, year]. Cached for an hour. */
	function db_listing_news( $limit = 6 ) {
		$cached = get_transient( 'db_listing_news_v1' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$out   = array();
		$posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => (int) $limit, 'no_found_rows' => true, 'has_password' => false ) );
		foreach ( $posts as $p ) {
			$text  = wp_strip_all_tags( '' !== trim( $p->post_excerpt ) ? $p->post_excerpt : strip_shortcodes( $p->post_content ) );
			$out[] = array(
				'title'   => wp_strip_all_tags( $p->post_title ),
				'excerpt' => wp_trim_words( $text, 24, '…' ),
				'url'     => get_permalink( $p ),
				'day'     => get_the_date( 'd', $p ),
				'month'   => get_the_date( 'M', $p ),
				'year'    => get_the_date( 'Y', $p ),
			);
		}
		set_transient( 'db_listing_news_v1', $out, HOUR_IN_SECONDS );
		return $out;
	}
}

if ( ! function_exists( 'db_listing_testimonials' ) ) {
	function db_listing_testimonials() {
		return array(
			array( 'Domain Brothers was very responsive concerning my request. When I placed my request for acquiring my desired domain to the Domain Brothers, within hours I got the reply for the same. Very responsive and very cooperative.', 'Mildred L. Shepard, Quebec, Canada' ),
			array( 'The process is much easier than I have thought of. I am happy with the service that Domain Brothers provided to me and am particularly pleased to be able to acquire a domain which I always wanted to own and which is easy to brand. I have a strong belief that this will enhance my business.', 'Leonor Santos Roka, Toronto, Ontario' ),
			array( 'Purchase process was simple, straight-forward and easily understandable. No hassles and stress was experienced while purchasing my desired domain. Although it was a costly affair, yet it was very fuss-free while doing the payment. In no time, I became the owner of my desired domain.', 'Sean Porter - Texas, United States' ),
		);
	}
}

if ( ! function_exists( 'db_listing_nfs_sign' ) ) {
	/** Hanging "NOT FOR SALE" sign, drawn to match the red SOLD sign. */
	function db_listing_nfs_sign() {
		return '<svg class="db-sold-signsvg" viewBox="0 0 150 130" width="150" height="130" role="img" aria-label="Not for sale" xmlns="http://www.w3.org/2000/svg">'
			. '<path d="M75 8 L38 52 M75 8 L112 52" stroke="#9aa3ad" stroke-width="6" stroke-linecap="round" fill="none"/><circle cx="75" cy="12" r="9" fill="#f5a524"/>'
			. '<rect x="8" y="50" width="134" height="62" rx="4" fill="#f5a524"/>'
			. '<text x="75" y="77" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-size="19" fill="#0a1628">NOT FOR</text>'
			. '<text x="75" y="101" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-weight="800" font-size="24" fill="#0a1628">SALE</text></svg>';
	}
}

if ( ! function_exists( 'db_listing_unavailable_html' ) ) {
	/**
	 * Body of the sold / not-for-sale page, after the production "sold" design:
	 * a full-width blue banner with a hanging sign and the domain in green,
	 * then Domain News and Testimonials. Not-for-sale shares the layout and
	 * adds the "Ask the owner" form; both add similar available domains.
	 */
	function db_listing_unavailable_html( WP_Post $post, $state ) {
		$name    = strtolower( trim( wp_strip_all_tags( $post->post_title ) ) );
		$sold    = 'sold' === $state;
		$sold_at = (int) get_post_meta( $post->ID, 'db_sold_at', true );
		$browse  = home_url( '/all-domains/' );
		$contact = home_url( '/contact-us/' );
		$inq     = isset( $_GET['inq'] ) ? sanitize_key( wp_unslash( $_GET['inq'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$assets  = plugin_dir_url( __FILE__ ) . 'assets/';

		$h  = '<div class="db-sold ' . ( $sold ? 'db-sold--sold' : 'db-sold--nfs' ) . '">';

		// Banner.
		$h .= '<section class="db-sold-band" aria-labelledby="db-sold-h"><div class="db-sold-sign">';
		$h .= $sold ? '<img src="' . esc_url( $assets . 'sold-sign.png' ) . '" width="120" height="120" alt="Sold">' : db_listing_nfs_sign();
		$h .= '</div><h1 class="db-sold-name" id="db-sold-h">' . esc_html( ucfirst( $name ) ) . '</h1>';
		if ( $sold ) {
			$h .= '<p class="db-sold-lead">This domain has a new owner' . ( $sold_at ? ' (sold ' . esc_html( wp_date( 'F j, Y', $sold_at ) ) . ')' : '' ) . '. We have similar names that are still available.</p>';
			$h .= '<div class="db-sold-actions"><a class="db-un-btn db-sold-btn" href="' . esc_url( $browse ) . '">Browse available domains</a><a class="db-un-btn db-sold-btn db-sold-btn--ghost" href="' . esc_url( $contact ) . '">Tell us what you need</a></div>';
		} else {
			$h .= '<p class="db-sold-lead">The owner is not selling this domain right now. If you would still like it, we can ask whether they would consider an offer.</p>';
		}
		$h .= '</section>';

		// Not for sale: the enquiry.
		if ( ! $sold ) {
			$h .= '<section class="db-sold-in db-sold-ask">';
			if ( 'ok' === $inq ) {
				$h .= '<p class="db-un-note db-un-note--ok" role="status">Thanks. We have your request and will come back to you by email.</p>';
			} else {
				if ( in_array( $inq, array( 'bad', 'wait' ), true ) ) {
					$h .= '<p class="db-un-note db-un-note--err" role="alert">' . ( 'wait' === $inq ? 'Please wait a little before sending another request.' : 'Please enter a valid email address and try again.' ) . '</p>';
				}
				$h .= '<form class="db-un-form" id="inquiry" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				$h .= '<h2>Ask the owner</h2><p>Leave your email. We will let you know what they say.</p>';
				$h .= '<input type="hidden" name="action" value="db_listing_inquiry"><input type="hidden" name="listing" value="' . (int) $post->ID . '"><input type="hidden" name="t" value="' . time() . '">';
				$h .= '<div class="db-un-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
				$h .= '<label for="db-un-email">Your email</label><input type="email" id="db-un-email" name="email" required autocomplete="email" maxlength="190">';
				$h .= '<label for="db-un-msg">Anything we should tell the owner? (optional)</label><textarea id="db-un-msg" name="message" rows="3" maxlength="600"></textarea>';
				$h .= '<button type="submit">Ask the owner</button></form>';
			}
			$h .= '</section>';
		}

		// Similar domains that can be bought.
		$recs = function_exists( 'db_rec_for' ) ? db_rec_for( $post->ID, 4 ) : array();
		if ( $recs && function_exists( 'db_rec_cards_html' ) ) {
			$h .= '<section class="db-sold-in db-sold-more"><h2 class="db-sold-h2">Similar domains that are available</h2>' . db_rec_cards_html( $recs ) . '</section>';
		}

		// Domain News.
		$news = db_listing_news();
		if ( $news ) {
			$h .= '<section class="db-sold-news" aria-labelledby="db-sold-news-h"><div class="db-sold-in"><h2 class="db-sold-h2" id="db-sold-news-h">Domain News</h2>';
			$h .= '<div class="db-sold-carousel"><button type="button" class="db-sold-arrow db-sold-arrow--prev" aria-label="Previous news">&#8249;</button>';
			$h .= '<div class="db-sold-track" tabindex="0" aria-label="Domain news">';
			foreach ( $news as $n ) {
				$h .= '<article class="db-sold-card"><h3><a href="' . esc_url( $n['url'] ) . '">' . esc_html( $n['title'] ) . '</a></h3><p>' . esc_html( $n['excerpt'] ) . '</p>'
					. '<time class="db-sold-date"><b>' . esc_html( $n['day'] ) . '</b>' . esc_html( $n['month'] ) . '<i>' . esc_html( $n['year'] ) . '</i></time></article>';
			}
			$h .= '</div><button type="button" class="db-sold-arrow db-sold-arrow--next" aria-label="Next news">&#8250;</button></div></div></section>';
		}

		// Testimonials.
		$h .= '<section class="db-sold-testi" style="background-image:linear-gradient(#fff 0,rgba(255,255,255,0) 38%),url(' . esc_url( $assets . 'testimonials-bg.jpg' ) . ')" aria-labelledby="db-sold-testi-h"><div class="db-sold-in"><h2 class="db-sold-h2" id="db-sold-testi-h">Testimonials</h2><div class="db-sold-quotes" data-db-quotes>';
		foreach ( db_listing_testimonials() as $i => $t ) {
			$h .= '<figure class="db-sold-quote' . ( 0 === $i ? ' is-on' : '' ) . '"><blockquote><p>' . esc_html( $t[0] ) . '</p></blockquote><figcaption>' . esc_html( $t[1] ) . '</figcaption>'
				. '<div class="db-sold-stars" role="img" aria-label="5 out of 5 stars">&#9733;&#9733;&#9733;&#9733;&#9733;</div></figure>';
		}
		$h .= '<div class="db-sold-dots" role="group" aria-label="Choose a testimonial">';
		foreach ( db_listing_testimonials() as $i => $t ) {
			$h .= '<button type="button" class="db-sold-dot' . ( 0 === $i ? ' is-on' : '' ) . '" aria-label="Testimonial ' . ( $i + 1 ) . '"' . ( 0 === $i ? ' aria-current="true"' : '' ) . '></button>';
		}
		$h .= '</div></div></div></section></div>';
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
