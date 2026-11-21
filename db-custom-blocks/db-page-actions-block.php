<?php
/**
 * Shared page furniture: a Back and Share bar, and "social proof" lines built only from real records.
 *
 * Used on the offer page, the payment plan page and the checkout. The proof lines state counts
 * the site really holds (wishlist saves, distinct buyers who made an offer in the last 30 days,
 * sales on record) and a line is shown only when its number is big enough to mean something.
 * Nothing is invented, estimated or rounded up.
 *
 * @package DB_Custom_Blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_pa_css' ) ) {
	function db_pa_css() {
		return '<style>.db-pa{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin:0 0 18px}'
			. '.db-pa-btn{display:inline-flex;align-items:center;gap:8px;min-height:44px;padding:0 18px;border:1.5px solid #cfd6d2;border-radius:999px;background:#fff;color:#0a1628!important;font:600 15px/1 inherit;text-decoration:none!important;cursor:pointer;transition:border-color .15s,background .15s}'
			. '.db-pa-btn:hover,.db-pa-btn:focus-visible{border-color:#0a1628;background:#f3f6f4}.db-pa-btn svg{width:18px;height:18px;flex:none}'
			. '.db-pa-note{font-size:14px;color:#137a3e;font-weight:600}'
			. '.db-proof{list-style:none;margin:0;padding:0;display:grid;gap:8px}.db-proof li{display:flex;gap:10px;align-items:flex-start;font-size:15px;line-height:1.45;color:#1f2937}'
			. '.db-proof i{flex:none;width:9px;height:9px;margin-top:7px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 0 rgba(22,163,74,.5);animation:db-pa-pulse 2s infinite}'
			. '@keyframes db-pa-pulse{0%{box-shadow:0 0 0 0 rgba(22,163,74,.45)}70%{box-shadow:0 0 0 8px rgba(22,163,74,0)}100%{box-shadow:0 0 0 0 rgba(22,163,74,0)}}'
			. '@media(prefers-reduced-motion:reduce){.db-proof i{animation:none}.db-pa-btn{transition:none}}</style>';
	}
}

if ( ! function_exists( 'db_pa_html' ) ) {
	/**
	 * The Back and Share bar. Pure given its inputs (the script is a constant string).
	 *
	 * @param array $a back_url, back_label, share_url, share_title.
	 */
	function db_pa_html( array $a ) {
		$e     = function ( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); };
		$back  = (string) ( $a['back_url'] ?? '' );
		$share = (string) ( $a['share_url'] ?? '' );
		if ( '' === $back && '' === $share ) {
			return '';
		}
		$h = db_pa_css() . '<div class="db-pa" role="group" aria-label="Page actions">';
		if ( '' !== $back ) {
			$h .= '<a class="db-pa-btn db-pa-back" href="' . $e( $back ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>' . $e( $a['back_label'] ?? 'Back' ) . '</a>';
		}
		if ( '' !== $share ) {
			$h .= '<button type="button" class="db-pa-btn db-pa-share" data-url="' . $e( $share ) . '" data-title="' . $e( $a['share_title'] ?? '' ) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>Share</button><span class="db-pa-note" role="status" aria-live="polite"></span>';
		}
		return $h . '</div><script data-no-delay>' . db_pa_js() . '</script>';
	}
}

if ( ! function_exists( 'db_pa_js' ) ) {
	function db_pa_js() {
		return '(function(){if(window.dbPaBound){return;}window.dbPaBound=true;'
			. 'document.addEventListener("click",function(e){var b=e.target.closest?e.target.closest(".db-pa-back,.db-pa-share"):null;if(!b){return;}'
			. 'if(b.classList.contains("db-pa-back")){var same=false;try{same=document.referrer&&new URL(document.referrer).origin===location.origin;}catch(x){}if(same&&history.length>1){e.preventDefault();history.back();}return;}'
			. 'var u=b.getAttribute("data-url")||location.href,t=b.getAttribute("data-title")||document.title,n=b.parentNode.querySelector(".db-pa-note");'
			. 'function say(m){if(n){n.textContent=m;setTimeout(function(){n.textContent="";},3500);}}'
			. 'if(navigator.share){navigator.share({title:t,url:u}).catch(function(){});return;}'
			. 'if(navigator.clipboard&&navigator.clipboard.writeText){navigator.clipboard.writeText(u).then(function(){say("Link copied");},function(){say(u);});return;}'
			. 'var i=document.createElement("input");i.value=u;document.body.appendChild(i);i.select();try{document.execCommand("copy");say("Link copied");}catch(x){say(u);}i.remove();'
			. '});})();';
	}
}

if ( ! function_exists( 'db_proof_lines' ) ) {
	/**
	 * Honest proof lines from real counts. Pure: tested offline.
	 *
	 * @param array $d saves (people who saved the name), buyers30 (distinct buyers who made an offer in 30 days),
	 *                 sold_ext (sold names with this extension), ext (e.g. ".ca"), sold90 (names sold in the last 90 days).
	 * @return string[]
	 */
	function db_proof_lines( array $d ) {
		$out   = array();
		$saves = (int) ( $d['saves'] ?? 0 );
		$buy   = (int) ( $d['buyers30'] ?? 0 );
		$ext   = (int) ( $d['sold_ext'] ?? 0 );
		$s90   = (int) ( $d['sold90'] ?? 0 );
		$x     = (string) ( $d['ext'] ?? '' );
		if ( $buy >= 2 ) {
			$out[] = $buy . ' buyers have made an offer on this domain in the last 30 days.';
		}
		if ( $saves >= 2 ) {
			$out[] = $saves . ' people have saved this domain to their wishlist.';
		}
		if ( $s90 >= 2 ) {
			$out[] = $s90 . ' domains sold through Domain Brothers in the last 90 days.';
		}
		if ( $ext >= 3 && preg_match( '/^\.[a-z0-9\-]{2,24}$/', $x ) ) {
			$out[] = $ext . ' ' . $x . ' names have sold through Domain Brothers.';
		}
		return array_slice( $out, 0, 3 );
	}
}

if ( ! function_exists( 'db_proof_data' ) ) {
	/** Real counts for a domain name (listing id may be 0). Sold statistics are cached for an hour. */
	function db_proof_data( $post_id, $name ) {
		global $wpdb;
		$name = strtolower( trim( (string) $name ) );
		$dot  = strrpos( $name, '.' );
		$ext  = false === $dot ? '' : substr( $name, $dot );
		$d    = array( 'saves' => 0, 'buyers30' => 0, 'sold_ext' => 0, 'sold90' => 0, 'ext' => $ext );
		if ( (int) $post_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$d['saves'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}db_wl_items WHERE post_id = %d", (int) $post_id ) );
		}
		if ( function_exists( 'db_deal_table' ) && '' !== $name ) {
			$t = db_deal_table( 'deals' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$d['buyers30'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT buyer_email) FROM {$t} WHERE domain = %s AND created_at > %s", $name, gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		}
		$stats = get_transient( 'db_proof_sold' );
		if ( ! is_array( $stats ) && function_exists( 'db_sold_listings' ) ) {
			$stats = array( 'by_ext' => array(), 'last90' => 0 );
			$cut   = time() - 90 * DAY_IN_SECONDS;
			foreach ( db_sold_listings() as $p ) {
				$n  = strtolower( trim( wp_strip_all_tags( $p->post_title ) ) );
				$dp = strrpos( $n, '.' );
				if ( false !== $dp ) {
					$e                      = substr( $n, $dp );
					$stats['by_ext'][ $e ]  = (int) ( $stats['by_ext'][ $e ] ?? 0 ) + 1;
				}
				if ( (int) get_post_meta( $p->ID, 'db_sold_at', true ) >= $cut ) {
					++$stats['last90'];
				}
			}
			set_transient( 'db_proof_sold', $stats, HOUR_IN_SECONDS );
		}
		if ( is_array( $stats ) ) {
			$d['sold_ext'] = (int) ( $stats['by_ext'][ $ext ] ?? 0 );
			$d['sold90']   = (int) ( $stats['last90'] ?? 0 );
		}
		return $d;
	}
}

if ( ! function_exists( 'db_proof_html' ) ) {
	/** The proof list for a domain, or '' when no line qualifies. */
	function db_proof_html( $post_id, $name ) {
		$lines = db_proof_lines( db_proof_data( $post_id, $name ) );
		if ( ! $lines ) {
			return '';
		}
		$h = db_pa_css() . '<ul class="db-proof" aria-label="Interest in this domain">';
		foreach ( $lines as $l ) {
			$h .= '<li><i aria-hidden="true"></i><span>' . htmlspecialchars( $l, ENT_QUOTES ) . '</span></li>';
		}
		return $h . '</ul>';
	}
}
