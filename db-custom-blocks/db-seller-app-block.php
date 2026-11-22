<?php
/**
 * The seller page as a guided flow (2026-11).
 *
 * New sellers move through five steps, one task per screen, each with a short
 * explainer: details, add names, prices, prove ownership, submit. Once names
 * are live the page opens on a dashboard with tabs (overview, names, offers,
 * sales and payouts, point your names, account). The forms and their
 * handlers are unchanged: db_seller_dashboard_body() still builds every
 * section, tagged with <!--dbsl:name--> markers, and this file shows only the
 * sections a screen needs.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_seller_sections' ) ) {
	/** Splits the full seller page into its named sections. */
	function db_seller_sections( $html ) {
		$parts = preg_split( '/<!--dbsl:([a-z]+)-->/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = array( 'head' => (string) array_shift( $parts ) );
		for ( $i = 0; $i + 1 < count( $parts ); $i += 2 ) {
			$out[ $parts[ $i ] ] = $parts[ $i + 1 ];
		}
		return $out;
	}
}

if ( ! function_exists( 'db_seller_steps' ) ) {
	function db_seller_steps() {
		return array(
			'details' => array( 'Your details', 'Tell us who is selling', 'We need your legal name and country for the seller agreement and to pay you. Your details are never shown to buyers.', 'Why we ask: payouts and the agreement must name the real owner.' ),
			'add'     => array( 'Add names', 'Add the names you want to sell', 'Paste up to ' . DB_SELLER_MAX_NAMES . ' names, or upload a spreadsheet (CSV or Excel, up to ' . DB_SELLER_MAX_ROWS . ' rows; a Sedo export works as it is). We tidy web addresses and skip duplicates, names already listed, and names that are not registered.', 'Why we check: each name can be listed by one owner only.' ),
			'prices'  => array( 'Set prices', 'Choose how each name sells', 'Buy Now names need a price of at least $' . DB_SELLER_MIN_PRICE . '. Make Offer names show "Open to offers". The minimum offer is private: offers below it are answered with your minimum automatically. Our commission is ' . DB_SELLER_COMMISSION_PCT . '% (' . DB_SELLER_EXCLUSIVE_PCT . '% if the name is exclusive with us), and only when it sells.', 'Tip: the price guide shows what similar names sold for through us.' ),
			'verify'  => array( 'Prove ownership', 'Show us the names are yours', 'Add one DNS record at your registrar (the same record works for all your names). Only the person who controls a name\'s DNS can add it, so this proves you own it. We check every hour for 3 days; most changes show within an hour.', 'Why we ask: it stops anyone listing a name they do not own, and it is how a name moves to a new owner.' ),
			'submit'  => array( 'Submit', 'Send your names for review', 'We check each name and price, usually within one business day, then it goes live with its own page, payment plans and our brokers working on it. You can change prices or take a name down at any time.', 'After this: you get an email when names go live, and when offers arrive.' ),
		);
	}
}

if ( ! function_exists( 'db_seller_stepper' ) ) {
	function db_seller_stepper( $current, $done ) {
		$steps = db_seller_steps();
		$keys  = array_keys( $steps );
		$idx   = array_search( $current, $keys, true );
		$h     = '<nav class="dbsa-stepper" aria-label="Listing steps"><p class="dbsa-stepcount">Step ' . ( (int) $idx + 1 ) . ' of ' . count( $keys ) . '</p><ol>';
		foreach ( $keys as $i => $k ) {
			$state = $k === $current ? 'is-current' : ( ! empty( $done[ $k ] ) ? 'is-done' : '' );
			$label = '<span class="dbsa-dot" aria-hidden="true">' . ( ! empty( $done[ $k ] ) && $k !== $current ? '&#10003;' : ( $i + 1 ) ) . '</span><span class="dbsa-lbl">' . esc_html( $steps[ $k ][0] ) . '</span>';
			$link  = ( 'details' === $k && ! empty( $done['details'] ) ) ? false : ( ! empty( $done[ $k ] ) || $k === $current || ( $i > 0 && ! empty( $done[ $keys[ $i - 1 ] ] ) ) );
			$h    .= '<li class="' . $state . '"' . ( $k === $current ? ' aria-current="step"' : '' ) . '>' . ( $link && $k !== $current ? '<a href="' . esc_url( add_query_arg( 'step', $k, home_url( '/seller/' ) ) ) . '">' . $label . '</a>' : '<span>' . $label . '</span>' ) . '</li>';
		}
		return $h . '</ol><div class="dbsa-bar" aria-hidden="true"><span style="width:' . (int) round( ( (int) $idx + 1 ) / count( $keys ) * 100 ) . '%"></span></div></nav>';
	}
}

if ( ! function_exists( 'db_seller_app_body' ) ) {
	/** The seller page: guided steps for new names, a dashboard once names are live. */
	function db_seller_app_body( $email, $seller, array $msgs ) {
		$full = db_seller_dashboard_body( $email, $seller, $msgs );
		$sec  = db_seller_sections( $full );
		$css  = db_seller_app_css();
		if ( ! $seller ) {
			return $css . db_seller_step_screen( 'details', array(), $full, '', '' );
		}
		if ( 'active' !== $seller['status'] && function_exists( 'db_seller_status_screen' ) ) {
			return $css . db_seller_status_screen( $email, $seller, $msgs );
		}
		$rows   = db_seller_listings( (int) $seller['id'] );
		$import = is_array( get_transient( 'db_seller_import_' . (int) $seller['id'] ) );
		$next   = db_seller_next_step( $rows, $import );
		$step   = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$view   = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( $_GET['edit'] ) || isset( $_GET['f'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$view = 'names';
		}
		if ( isset( $_GET['offer'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$view = 'offers';
		}
		if ( '' === $step && '' === $view ) {
			if ( 'dashboard' === $next ) {
				$view = 'overview';
			} else {
				$step = $next;
			}
		}
		$steps = db_seller_steps();
		if ( '' !== $step && isset( $steps[ $step ] ) && 'details' !== $step ) {
			$drafts  = array_filter( $rows, static function ( $r ) { return in_array( $r['review_status'], array( 'draft', 'rejected' ), true ); } );
			$priced  = $drafts && ! array_filter( $drafts, static function ( $r ) { list( , $e ) = db_seller_validate_row( $r ); return (bool) $e; } );
			$checked = $drafts && ! array_filter( $drafts, static function ( $r ) { return 'verified' !== $r['verify_status']; } );
			$done    = array( 'details' => true, 'add' => (bool) $rows, 'prices' => $priced, 'verify' => $checked, 'submit' => false );
			$body    = '';
			$add_first = '<p class="dbsl-msg dbsl-msg--warn">You have no names waiting. <a href="' . esc_url( add_query_arg( 'step', 'add', home_url( '/seller/' ) ) ) . '">Add names first</a>.</p>';
			if ( 'add' === $step ) {
				$body = ( $sec['import'] ?? '' ) . ( $import ? '' : db_seller_add_tabs( $sec['add'] ?? '' ) );
			} elseif ( ! $drafts && in_array( $step, array( 'prices', 'verify' ), true ) ) {
				$body = $add_first;
			} elseif ( 'prices' === $step ) {
				$body = $sec['prices'] ?? '<p>Add names first.</p>';
			} elseif ( 'verify' === $step ) {
				$body = '' !== trim( (string) ( $sec['verify'] ?? '' ) ) ? $sec['verify'] . db_seller_registrar_help() : '<p class="dbsl-msg dbsl-msg--ok">Every name in this batch is verified.</p>';
			} elseif ( 'submit' === $step ) {
				$body = db_seller_submit_screen( $rows, $email );
			}
			$nav = db_seller_step_nav( $step, $done, $rows );
			return $css . db_seller_offer_banner( $seller ) . db_seller_step_screen( $step, $done, $sec['head'] . $body . '</div>', $nav, db_seller_tabs( '', $rows ) );
		}
		return $css . db_seller_offer_banner( $seller ) . db_seller_dashboard_view( $view, $seller, $rows, $sec );
	}
}

if ( ! function_exists( 'db_seller_step_screen' ) ) {
	function db_seller_step_screen( $step, $done, $body, $nav, $tabs ) {
		$s = db_seller_steps()[ $step ];
		return '<div class="dbsa">' . $tabs . db_seller_stepper( $step, $done )
			. '<header class="dbsa-explain"><p class="dbsa-eyebrow">' . esc_html( $s[0] ) . '</p><h2>' . esc_html( $s[1] ) . '</h2><p>' . esc_html( $s[2] ) . '</p><p class="dbsa-why">' . esc_html( $s[3] ) . '</p></header>'
			. $body . $nav . '</div>';
	}
}

if ( ! function_exists( 'db_seller_step_nav' ) ) {
	function db_seller_step_nav( $step, $done, $rows ) {
		$keys = array_keys( db_seller_steps() );
		$i    = array_search( $step, $keys, true );
		$prev = $i > 1 ? $keys[ $i - 1 ] : '';
		$next = $i < count( $keys ) - 1 ? $keys[ $i + 1 ] : '';
		$h    = '<div class="dbsa-nav">';
		$h   .= $prev ? '<a class="dbsl-btn dbsl-btn--ghost" href="' . esc_url( add_query_arg( 'step', $prev, home_url( '/seller/' ) ) ) . '">&larr; Back</a>' : '<span></span>';
		if ( $next ) {
			$h .= ! empty( $done[ $step ] ) ? '<a class="dbsl-btn" href="' . esc_url( add_query_arg( 'step', $next, home_url( '/seller/' ) ) ) . '">Continue &rarr;</a>' : '<span class="dbsa-hint">' . esc_html( array( 'add' => 'Add at least one name to continue.', 'prices' => 'Save a valid price for every name to continue.', 'verify' => 'Continue appears once your names are verified.' )[ $step ] ?? '' ) . '</span>';
		}
		return $h . '</div>';
	}
}

if ( ! function_exists( 'db_seller_add_tabs' ) ) {
	/** Paste or spreadsheet: two tabs instead of two cards side by side. */
	function db_seller_add_tabs( $html ) {
		if ( '' === trim( $html ) ) {
			return '';
		}
		return '<div class="dbsa-addtabs" data-tabs><div class="dbsa-seg" role="tablist" aria-label="How to add names"><button type="button" role="tab" aria-selected="true" data-tab="0">Paste names</button><button type="button" role="tab" aria-selected="false" data-tab="1">Upload a spreadsheet</button></div>' . $html . '</div>'
			. '<script data-no-delay>(function(){var w=document.querySelector("[data-tabs]");if(!w)return;var cards=w.querySelectorAll(".dbsl-two > section"),b=w.querySelectorAll("[data-tab]");function set(i){cards.forEach(function(c,j){c.hidden=j!==i;});b.forEach(function(x,j){x.setAttribute("aria-selected",j===i?"true":"false");});}b.forEach(function(x){x.addEventListener("click",function(){set(+x.getAttribute("data-tab"));});});set(0);})();</script>';
	}
}

if ( ! function_exists( 'db_seller_registrar_help' ) ) {
	function db_seller_registrar_help() {
		$r = array(
			'GoDaddy'    => 'My Products, then DNS next to the name. Add, type TXT, Name @, Value: your record. Save.',
			'Namecheap'  => 'Domain List, Manage, Advanced DNS. Add New Record, TXT Record, Host @, Value: your record. Save (the green tick).',
			'Cloudflare' => 'Choose the site, DNS, Records, Add record. Type TXT, Name @, Content: your record. Save.',
			'Google, Squarespace or other' => 'Find "DNS settings" or "Manage DNS" for the name. Add a TXT record with host or name @ (or blank) and your record as the value.',
		);
		$h = '<section class="dbsl-card dbsl-sec dbsa-help" aria-labelledby="dbsa-reg"><h2 id="dbsa-reg">How to add the record at your registrar</h2>';
		foreach ( $r as $name => $how ) {
			$h .= '<details><summary>' . esc_html( $name ) . '</summary><p>' . esc_html( $how ) . '</p></details>';
		}
		return $h . '<p class="dbsl-muted">Stuck? <a href="' . esc_url( home_url( '/contact-us/' ) ) . '">Ask a broker</a> and we will walk you through it.</p></section>';
	}
}

if ( ! function_exists( 'db_seller_submit_screen' ) ) {
	function db_seller_submit_screen( array $rows, $email ) {
		$ready = array_filter( $rows, static function ( $r ) { return 'draft' === $r['review_status'] && db_seller_ready( $r ); } );
		$t     = esc_attr( db_seller_csrf( $email ) );
		$h     = '<section class="dbsl-card dbsl-sec"><h2>Ready to go live</h2>';
		if ( ! $ready ) {
			return $h . '<p>No names are ready yet. A name is ready once it has a valid price and a verified ownership record.</p></section>';
		}
		$h .= '<div class="dbsl-scroll"><table class="dbsl-tbl"><thead><tr><th scope="col">Name</th><th scope="col">Sells as</th><th scope="col">Our fee if sold</th><th scope="col">You receive</th></tr></thead><tbody>';
		foreach ( $ready as $r ) {
			$buy = 'make_offer' !== $r['option_type'];
			$fee = $buy ? db_seller_fee( (int) $r['price'], ! empty( $r['exclusive'] ) ) : 0;
			$h  .= '<tr><td class="dbsl-dom">' . esc_html( $r['domain'] ) . ( ! empty( $r['exclusive'] ) ? ' <span class="dbsl-chip dbsl-chip--ok">Exclusive</span>' : '' ) . '</td><td>' . ( $buy ? 'Buy Now $' . esc_html( number_format( (int) $r['price'] ) ) : 'Open to offers' ) . '</td><td>' . ( $buy ? '$' . esc_html( number_format( $fee ) ) : esc_html( ( ! empty( $r['exclusive'] ) ? DB_SELLER_EXCLUSIVE_PCT : DB_SELLER_COMMISSION_PCT ) . '% of the sale' ) ) . '</td><td>' . ( $buy ? '<strong>$' . esc_html( number_format( (int) $r['price'] - $fee ) ) . '</strong>' : '&mdash;' ) . '</td></tr>';
		}
		$h .= '</tbody></table></div><p class="dbsl-muted">By submitting you confirm you own these names and accept the <a href="' . esc_url( home_url( '/seller-agreement/' ) ) . '">seller agreement</a>. Please answer offers within 24 hours: buyers are told if an owner does not reply, and listings go offline after repeated missed offers.</p>'
			. '<form method="post" action="' . esc_url( home_url( '/seller/' ) ) . '" class="dbsl-actions"><input type="hidden" name="db_seller_action" value="submit"><input type="hidden" name="t" value="' . $t . '"><button type="submit" class="dbsl-btn">Submit ' . count( $ready ) . ' ' . ( 1 === count( $ready ) ? 'name' : 'names' ) . ' for review</button></form></section>';
		return $h;
	}
}

if ( ! function_exists( 'db_seller_tabs' ) ) {
	function db_seller_tabs( $current, array $rows ) {
		// The first visit (no names yet) is the step flow alone. Once a seller has any name, the
		// tabs and "+ Add names" are on every screen, so there is always a way back to adding
		// names (they used to appear only after a name was in review or live).
		if ( ! $rows ) {
			return '';
		}
		$tabs = array( 'overview' => 'Overview', 'names' => 'Your names', 'offers' => 'Offers', 'payouts' => 'Sales and payouts', 'point' => 'Point your names', 'account' => 'Account' );
		$h    = '<nav class="dbsa-tabs" aria-label="Seller sections">';
		foreach ( $tabs as $k => $label ) {
			$h .= '<a href="' . esc_url( add_query_arg( 'view', $k, home_url( '/seller/' ) ) ) . '"' . ( $current === $k ? ' class="is-on" aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		return $h . '<a class="dbsa-add" href="' . esc_url( add_query_arg( 'step', 'add', home_url( '/seller/' ) ) ) . '">+ Add names</a></nav>';
	}
}

if ( ! function_exists( 'db_seller_waiting_offers' ) ) {
	function db_seller_waiting_offers( array $seller ) {
		if ( ! function_exists( 'db_seller_deals' ) ) {
			return 0;
		}
		return count( array_filter( db_seller_deals( (int) $seller['id'] ), static function ( $d ) { return 'open' === $d->state; } ) );
	}
}

if ( ! function_exists( 'db_seller_offer_banner' ) ) {
	function db_seller_offer_banner( array $seller ) {
		$n = db_seller_waiting_offers( $seller );
		return $n ? '<p class="dbsa-alert" role="status"><strong>' . $n . ' ' . ( 1 === $n ? 'offer is' : 'offers are' ) . ' waiting for your answer.</strong> Buyers are told if an owner does not reply. <a href="' . esc_url( add_query_arg( 'view', 'offers', home_url( '/seller/' ) ) ) . '">Answer now</a></p>' : '';
	}
}

if ( ! function_exists( 'db_seller_dashboard_view' ) ) {
	function db_seller_dashboard_view( $view, array $seller, array $rows, array $sec ) {
		$views = array( 'overview', 'names', 'offers', 'payouts', 'point', 'account' );
		$view  = in_array( $view, $views, true ) ? $view : 'overview';
		$h     = '<div class="dbsa">' . db_seller_tabs( $view, $rows ) . $sec['head'];
		if ( 'overview' === $view ) {
			$live   = count( array_filter( $rows, static function ( $r ) { return 'approved' === $r['review_status']; } ) );
			$review = count( array_filter( $rows, static function ( $r ) { return 'pending' === $r['review_status']; } ) );
			$todo   = count( array_filter( $rows, static function ( $r ) { return in_array( $r['review_status'], array( 'draft', 'rejected' ), true ); } ) );
			$due    = 0.0;
			if ( function_exists( 'db_seller_payouts_for' ) ) {
				foreach ( db_seller_payouts_for( (int) $seller['id'] ) as $p ) {
					if ( ! in_array( $p['state'], array( 'paid', 'held' ), true ) ) {
						$due += (float) $p['net'];
					}
				}
			}
			$wait = db_seller_waiting_offers( $seller );
			$card = static function ( $n, $label, $view, $tone = '' ) {
				return '<a class="dbsa-stat' . ( $tone ? ' dbsa-stat--' . $tone : '' ) . '" href="' . esc_url( add_query_arg( 'view', $view, home_url( '/seller/' ) ) ) . '"><strong>' . $n . '</strong><span>' . esc_html( $label ) . '</span></a>';
			};
			$h .= '<section class="dbsa-stats" aria-label="Summary">' . $card( $live, 'Live names', 'names' ) . $card( $review, 'In review', 'names' ) . $card( $wait, 'Offers waiting for you', 'offers', $wait ? 'alert' : '' ) . $card( '$' . number_format( $due, 0 ), 'Payouts on the way', 'payouts' ) . '</section>';
			if ( $todo ) {
				$h .= '<p class="dbsl-msg dbsl-msg--warn">' . $todo . ' ' . ( 1 === $todo ? 'name needs' : 'names need' ) . ' a price or an ownership record before it can go live. <a href="' . esc_url( add_query_arg( 'step', db_seller_next_step( $rows ), home_url( '/seller/' ) ) ) . '">Continue listing</a></p>';
			}
			$h .= '<section class="dbsl-card dbsl-sec"><h2>How selling with us works</h2><ol class="dbsa-how"><li><strong>Buyers find your names</strong> on Domain Brothers, with payment plans and our brokers promoting them.</li><li><strong>Answer offers quickly.</strong> Accept, counter or decline from the Offers tab. We remind you on day 1, 3 and 5; after 7 days without an answer the buyer is told and our broker takes over.</li><li><strong>When a name sells</strong>, we email the transfer steps. You are paid 7 days after the buyer confirms control (14 days after the transfer at the latest).</li><li><strong>Keep your DNS record.</strong> We re-check ownership every ' . DB_SELLER_RECHECK_DAYS . ' days.</li></ol></section>';
		} elseif ( 'names' === $view ) {
			$h .= '<p class="dbsa-addrow"><a class="dbsl-btn" href="' . esc_url( add_query_arg( 'step', 'add', home_url( '/seller/' ) ) ) . '">+ Add new names</a> <span class="dbsl-muted">Paste names or upload a spreadsheet.</span></p>';
			$h .= ( $sec['edit'] ?? '' ) . ( $sec['names'] ?? '' );
		} elseif ( 'offers' === $view ) {
			$h .= $sec['offers'] ?? '';
		} elseif ( 'payouts' === $view ) {
			$h .= $sec['payouts'] ?? '';
		} elseif ( 'point' === $view ) {
			$h .= '' !== trim( (string) ( $sec['point'] ?? '' ) ) ? $sec['point'] : '<section class="dbsl-card dbsl-sec"><h2>Point your names at us</h2><p>Once a name is live, you can point it at Domain Brothers so visitors who type it see that it is for sale.</p></section>';
		} elseif ( 'account' === $view ) {
			$h .= function_exists( 'db_seller_account_html' ) ? db_seller_account_html( $seller ) : '';
		}
		return $h . ( $sec['tail'] ?? '</div>' ) . '</div>';
	}
}

if ( ! function_exists( 'db_seller_app_css' ) ) {
	function db_seller_app_css() {
		return '<style>.dbsa{max-width:1100px;margin:0 auto}'
			. '.dbsa-stepper{margin:0 0 22px}.dbsa-stepper ol{display:flex;gap:6px;margin:0;padding:0;list-style:none}.dbsa-stepper li{flex:1 1 0;min-width:0}'
			. '.dbsa-stepper li>a,.dbsa-stepper li>span{display:flex;align-items:center;gap:10px;padding:12px 14px;border:1.5px solid #e4e7ec;border-radius:14px;background:#fff;color:#4b5563;text-decoration:none;font-weight:700;font-size:14.5px}'
			. '.dbsa-stepper li.is-done>a,.dbsa-stepper li.is-done>span{border-color:#b5e61d;background:#f7fde8;color:#0a1628}.dbsa-stepper li.is-current>span{border-color:#0a1628;background:#0a1628;color:#fff}'
			. '.dbsa-dot{flex:none;display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:#eef1f4;color:#0a1628;font-size:13px;font-weight:900}.is-current .dbsa-dot{background:#b5e61d}.is-done .dbsa-dot{background:#b5e61d}'
			. '.dbsa-lbl{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dbsa-stepcount{display:none;margin:0 0 6px;font-weight:800;color:#0a1628}.dbsa-bar{display:none;height:8px;border-radius:999px;background:#eef1f4;overflow:hidden}.dbsa-bar span{display:block;height:100%;background:#65a30d}'
			. '@media(max-width:760px){.dbsa-stepper ol{display:none}.dbsa-stepcount,.dbsa-bar{display:block}}'
			. '.dbsa-explain{margin:0 0 18px;padding:22px 24px;border-radius:20px;background:#0a1628;color:#fff}.dbsa-eyebrow{margin:0 0 6px;color:#b5e61d;font-size:12.5px;font-weight:800;letter-spacing:.12em;text-transform:uppercase}'
			. 'html body .dbsa-explain h2{margin:0 0 8px;color:#fff;font-size:26px;font-weight:900;letter-spacing:-.02em}.dbsa-explain p{margin:0 0 6px;color:rgba(255,255,255,.88);font-size:15.5px;line-height:1.6;max-width:75ch}.dbsa-explain .dbsa-why{margin-top:10px;color:#d9f99d;font-size:14px}.dbsa.dbsa .dbsa-explain.dbsa-explain p{color:rgba(255,255,255,.88)!important}.dbsa.dbsa .dbsa-explain.dbsa-explain p.dbsa-eyebrow{color:#b5e61d!important}.dbsa.dbsa .dbsa-explain.dbsa-explain p.dbsa-why{color:#d9f99d!important}'
			. '.dbsa-nav{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:18px 0 8px;padding:16px 0 0;border-top:1px solid #e4e7ec}.dbsa-hint{color:#4b5563;font-size:14.5px}'
			. '.dbsa-seg{display:inline-flex;gap:4px;margin:0 0 14px;padding:4px;border-radius:999px;background:#eef1f4}html body .dbsa-seg button{min-height:42px;padding:0 18px;border:0;border-radius:999px;background:transparent;color:#0a1628;font-weight:700;cursor:pointer}html body .dbsa-seg button[aria-selected=true]{background:#0a1628;color:#fff}'
			. '.dbsa-addtabs .dbsl-two{display:block}.dbsa-help details{border-top:1px solid #eef1f4;padding:10px 0}.dbsa-help summary{cursor:pointer;font-weight:700}'
			. '.dbsa-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 18px;padding:6px;border:1px solid #e4e7ec;border-radius:18px;background:#fff}.dbsa-tabs a{padding:10px 16px;border-radius:12px;color:#0a1628;font-weight:700;text-decoration:none}.dbsa-tabs a.is-on{background:#0a1628;color:#fff}.dbsa-tabs .dbsa-add{margin-left:auto;background:#b5e61d}'
			. '.dbsa-stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 18px}@media(max-width:760px){.dbsa-stats{grid-template-columns:1fr 1fr}}'
			. '.dbsa-stat{display:grid;gap:2px;padding:18px;border:1px solid #e4e7ec;border-radius:18px;background:#fff;text-decoration:none;color:#0a1628}.dbsa-stat strong{font-size:28px;font-weight:900}.dbsa-stat span{color:#4b5563;font-weight:600}.dbsa-stat--alert{border-color:#f3a5a5;background:#fff5f4}.dbsa-stat--alert strong{color:#b42318}'
			. '.dbsa-alert{max-width:1100px;margin:0 auto 16px;padding:14px 18px;border-radius:14px;background:#fff1f0;border:1px solid #f3a5a5;color:#7a1610}.dbsa-alert a{color:#7a1610;font-weight:800}.dbsa-how{margin:0;padding-left:20px;display:grid;gap:8px;line-height:1.6}.dbsa-addrow{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:0 0 16px}</style>';
	}
}
