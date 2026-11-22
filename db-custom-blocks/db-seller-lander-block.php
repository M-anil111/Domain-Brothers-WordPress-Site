<?php
/**
 * Seller marketplace phase 5: a sales page for names pointed at Domain Brothers.
 *
 * When a listed name's DNS points at this site (and the name is added to the
 * hosting as an alias), a visit to that name shows a standalone sales page:
 * the name, its price, Buy now / Pay monthly / Make an offer (all on the main
 * site), and the visit is counted as a view of the listing. Sold names show a
 * short "sold" page. The seller dashboard explains how to point a name and
 * lets the seller tell the broker it is done.
 *
 * DNS target: option db_seller_point_ip (A record) and db_seller_point_cname
 * (www CNAME, defaults to the site host).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', function () {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}
	$name = db_seller_pointed_name( isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( '' === $name || ! function_exists( 'db_listing_post_for_domain' ) ) {
		return;
	}
	$post = db_listing_post_for_domain( $name );
	if ( ! $post ) {
		return;
	}
	echo db_seller_lander_html( $post ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
	exit;
}, 1 );

if ( ! function_exists( 'db_seller_lander_html' ) ) {
	/** The whole standalone page for a pointed name. Also counts the view. */
	function db_seller_lander_html( WP_Post $post ) {
		$name  = strtolower( $post->post_title );
		$sold  = function_exists( 'db_listing_state' ) && 'sold' === db_listing_state( $post );
		$price = function_exists( 'db_listing_price' ) ? (float) db_listing_price( $post->ID ) : 0.0;
		$page  = get_permalink( $post );
		$enc   = rawurlencode( base64_encode( $name ) );
		$buy   = $price > 0 ? add_query_arg( array( 'd' => $enc, 'p' => rawurlencode( base64_encode( (string) (int) $price ) ) ), home_url( '/buy-now/' ) ) : '';
		$offer = add_query_arg( 'd', $name, home_url( '/make-an-offer/' ) );
		$month = $price >= 600 ? ( function_exists( 'db_dd_monthly' ) ? (string) db_dd_monthly( $price ) : '$' . number_format( round( $price * 1.10 / 12 ) ) ) : '';
		$desc  = trim( (string) get_post_meta( $post->ID, 'db_seller_desc', true ) );
		$excl  = '1' === (string) get_post_meta( $post->ID, 'db_exclusive', true );

		// Count the visit as a landing view of the listing (bots excluded).
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $sold && function_exists( 'db_hits_record' ) && function_exists( 'db_hits_is_bot' ) && ! db_hits_is_bot( $ua ) ) {
			$ip = function_exists( 'db_wl_ip' ) ? db_wl_ip() : (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			db_hits_record( (int) $post->ID, db_hits_visitor( $ip, $ua ), true );
		}

		status_header( 200 );
		nocache_headers();
		header( 'X-Robots-Tag: noindex, follow' );
		header( 'Content-Type: text/html; charset=utf-8' );
		$title = $sold ? $name . ' has been sold' : $name . ' is for sale';
		$h     = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,follow">'
			. '<title>' . esc_html( $title ) . ' | Domain Brothers</title><link rel="canonical" href="' . esc_url( $page ) . '">'
			. '<style>:root{--navy:#0a1628;--lime:#b5e61d;--ink:#0f172a;--muted:#4b5563;--line:#e4e7ec}*{box-sizing:border-box}html,body{margin:0}body{min-height:100vh;display:flex;flex-direction:column;font:16px/1.6 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);background:radial-gradient(900px 500px at 85% -10%,rgba(181,230,29,.18),transparent 60%),#f6f8f2}'
			. 'header,footer{padding:18px 24px}header a{color:var(--ink);font-weight:800;text-decoration:none;letter-spacing:-.01em}main{flex:1;display:grid;place-items:center;padding:24px 16px 48px}'
			. '.card{width:min(720px,100%);padding:40px 36px;border-radius:28px;background:#fff;border:1px solid var(--line);box-shadow:0 30px 70px -30px rgba(10,22,40,.35);text-align:center}'
			. '.eyebrow{display:inline-block;padding:6px 14px;border-radius:999px;background:var(--navy);color:var(--lime);font-size:12.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}'
			. 'h1{margin:18px 0 6px;font-size:clamp(34px,7vw,58px);line-height:1.05;letter-spacing:-.03em;overflow-wrap:anywhere}.price{margin:6px 0 2px;font-size:28px;font-weight:900}.month{margin:0;color:var(--muted)}'
			. '.desc{margin:18px auto 0;max-width:52ch;color:#374151}.acts{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:26px}'
			. '.btn{display:inline-flex;align-items:center;justify-content:center;min-height:52px;padding:0 26px;border-radius:999px;font-weight:800;text-decoration:none;font-size:16.5px}.btn-main{background:var(--navy);color:#fff}.btn-main:hover{background:#1e2f47}.btn-alt{border:2px solid var(--navy);color:var(--navy)}.btn-alt:hover{background:var(--navy);color:#fff}'
			. '.btn:focus-visible{outline:3px solid #65a30d;outline-offset:3px}.trust{display:flex;flex-wrap:wrap;gap:8px 18px;justify-content:center;margin:26px 0 0;padding:0;list-style:none;color:var(--muted);font-size:14.5px}.trust li::before{content:"\2713";color:#3f6212;font-weight:900;margin-right:6px}'
			. 'footer{text-align:center;color:var(--muted);font-size:13.5px}footer a{color:#3f6212;font-weight:700}@media(max-width:520px){.card{padding:30px 20px}.btn{width:100%}}</style></head><body>'
			. '<header><a href="' . esc_url( home_url( '/' ) ) . '">Domain Brothers</a></header><main><div class="card">';
		if ( $sold ) {
			$h .= '<span class="eyebrow">Sold</span><h1>' . esc_html( $name ) . '</h1><p class="desc">This domain found a new owner through Domain Brothers. Looking for a name like it?</p>'
				. '<div class="acts"><a class="btn btn-main" href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse available domains</a><a class="btn btn-alt" href="' . esc_url( home_url( '/acquire-premium-domain/' ) ) . '">Ask us to find one</a></div>';
		} else {
			$h .= '<span class="eyebrow">' . ( $excl ? 'Exclusive to Domain Brothers' : 'This domain is for sale' ) . '</span><h1>' . esc_html( $name ) . '</h1>'
				. ( $price > 0 ? '<p class="price">$' . esc_html( number_format( $price ) ) . '</p>' . ( '' !== $month ? '<p class="month">or from ' . esc_html( $month ) . ' a month on a payment plan</p>' : '' ) : '<p class="price">Open to offers</p>' )
				. ( '' !== $desc ? '<p class="desc">' . esc_html( $desc ) . '</p>' : '' )
				. '<div class="acts">' . ( $buy ? '<a class="btn btn-main" href="' . esc_url( $buy ) . '">Buy now</a>' : '' ) . '<a class="btn ' . ( $buy ? 'btn-alt' : 'btn-main' ) . '" href="' . esc_url( $offer ) . '">Make an offer</a>' . ( $buy ? '<a class="btn btn-alt" href="' . esc_url( $page ) . '">Pay monthly</a>' : '' ) . '</div>'
				. '<ul class="trust"><li>Secure payment</li><li>Transfer handled for you</li><li>Payment plans available</li></ul>';
		}
		$h .= '</div></main><footer>Listed by <a href="' . esc_url( home_url( '/' ) ) . '">Domain Brothers</a>, a premium domain marketplace. <a href="' . esc_url( $page ) . '">Full details for ' . esc_html( $name ) . '</a></footer></body></html>';
		return $h;
	}
}

if ( ! function_exists( 'db_seller_point_html' ) ) {
	/** The "Point your names at us" section of the seller dashboard. */
	function db_seller_point_html( array $seller, array $rows, $self, $t ) {
		$live = array_values( array_filter( $rows, static function ( $r ) { return 'approved' === $r['review_status']; } ) );
		if ( ! $live ) {
			return '';
		}
		$ip    = trim( (string) get_option( 'db_seller_point_ip', '' ) );
		$cname = trim( (string) get_option( 'db_seller_point_cname', '' ) );
		$cname = '' !== $cname ? $cname : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$h     = '<section class="dbsl-card dbsl-sec" id="dbsl-point" aria-labelledby="dbsl-pt-h"><h2 id="dbsl-pt-h">Point your names at us</h2>'
			. '<p>Visitors who type one of your names straight into a browser see a page saying it is for sale, with its price and buttons to buy or make an offer. Those visits count as views. Keep the name at your registrar; only change its DNS.</p>'
			. '<ol class="dbsl-ul"><li>At your registrar, open the DNS settings for the name.</li>'
			. ( '' !== $ip ? '<li>Set an <strong>A record</strong> for <code>@</code> to <code>' . esc_html( $ip ) . '</code> (remove other A or AAAA records for <code>@</code>).</li>' : '<li>Set an <strong>A record</strong> for <code>@</code> to the address our broker sends you.</li>' )
			. '<li>Set a <strong>CNAME</strong> for <code>www</code> to <code>' . esc_html( $cname ) . '</code>.</li>'
			. '<li>Keep the TXT record you added for the ownership check.</li><li>Tell us below. We connect the name on our side, usually within one business day, and email you when its sales page is live.</li></ol>'
			. '<form method="post" action="' . $self . '" class="dbsl-pointform"><input type="hidden" name="db_seller_action" value="pointed"><input type="hidden" name="t" value="' . esc_attr( $t ) . '">'
			. '<label for="dbsl-pt-name">Name you pointed</label><select id="dbsl-pt-name" name="id">';
		foreach ( $live as $r ) {
			$h .= '<option value="' . (int) $r['id'] . '">' . esc_html( $r['domain'] ) . '</option>';
		}
		return $h . '</select><button type="submit" class="dbsl-btn">I have pointed it</button></form></section>';
	}
}

if ( ! function_exists( 'db_seller_point_post' ) ) {
	function db_seller_point_post( array $seller, array $own, array $p ) {
		$id = (int) ( $p['id'] ?? 0 );
		if ( ! isset( $own[ $id ] ) || 'approved' !== $own[ $id ]['review_status'] ) {
			return array( array( 'bad', 'Choose one of your live names.' ) );
		}
		$name = $own[ $id ]['domain'];
		if ( ! db_wl_allow( 'seller-point:' . (int) $seller['id'], 10, DAY_IN_SECONDS ) ) {
			return array( array( 'bad', 'We already have your requests for today. We will be in touch.' ) );
		}
		if ( function_exists( 'db_plan_notify_admin' ) ) {
			db_plan_notify_admin( 'Seller pointed a name: ' . $name, array( 'Domain' => $name, 'Seller' => $seller['email'], 'To do' => 'Add ' . $name . ' and www.' . $name . ' as alias domains of this website in hosting, then check https://' . $name . '/ shows its sales page.' ) );
		}
		if ( function_exists( 'db_seller_audit' ) ) {
			db_seller_audit( (int) $seller['id'], $name, 'pointed', 'Seller says DNS points at us', 'seller' );
		}
		return array( array( 'ok', 'Thanks. We will connect ' . $name . ' and email you when its sales page is live.' ) );
	}
}
