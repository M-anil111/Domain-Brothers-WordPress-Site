<?php
/**
 * /all-domains/ filter and sort bar.
 *
 * The theme lists domains 100 per page with no way to narrow or reorder the
 * list, so a buyer looking for "a short .ca under $5,000" has to page through
 * every listing. This adds a toolbar above the table: name search,
 * extension, maximum name length, maximum price and sort order. It works
 * across every published listing, not just the current page, using a small
 * cached JSON index (GET /wp-json/db/v1/domain-index).
 *
 * The server-rendered, paginated table is left untouched, so crawlers and
 * visitors without JavaScript see exactly what they saw before. Once a
 * filter is applied, the table body is replaced client-side and the
 * pagination hidden; "Clear" restores the original page.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DB_DOMAIN_INDEX_KEY' ) ) {
	define( 'DB_DOMAIN_INDEX_KEY', 'db_domain_index_v3' );
}

if ( ! function_exists( 'db_domain_index' ) ) {
	/**
	 * Published domain listings as compact rows: id, n (name), u (URL),
	 * p (price, 0 = make an offer), c (categories as [name, url]), k (keywords).
	 * Built with direct, bounded queries and cached for an hour.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	function db_domain_index() {
		$cached = get_transient( DB_DOMAIN_INDEX_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$ids  = get_posts( array(
			'post_type'              => 'domain',
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => 2000,
			'fields'                 => 'ids',
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_term_cache' => true,
			'update_post_meta_cache' => true,
		) );
		$rows = array();
		foreach ( $ids as $id ) {
			$name = strtolower( trim( wp_strip_all_tags( get_the_title( $id ) ) ) );
			if ( '' === $name ) {
				continue;
			}
			// Sold and not-for-sale names stay reachable by URL but are not offered in lists.
			if ( function_exists( 'db_listing_state' ) && in_array( db_listing_state( $id ), array( 'sold', 'not_for_sale' ), true ) ) {
				continue;
			}
			$raw   = preg_replace( '/[^0-9.]/', '', (string) get_post_meta( $id, 'domain_price', true ) );
			$price = is_numeric( $raw ) && (float) $raw > 0 ? (float) $raw : 0.0;
			$cats  = array();
			$terms = get_the_terms( $id, 'domain_category' );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $t ) {
					$link = get_term_link( $t );
					if ( ! is_wp_error( $link ) ) {
						$cats[] = array( $t->name, $link );
					}
				}
			}
			$rows[] = array(
				'id' => (int) $id,
				'n'  => $name,
				'u'  => get_permalink( $id ),
				'p'  => $price,
				'c'  => $cats,
				// Keywords the owner entered (used by the recommendations).
				'k'  => strtolower( trim( (string) get_post_meta( $id, 'domain_keyword', true ) ) ),
			);
		}
		if ( ! function_exists( 'db_on_canonical_host' ) || db_on_canonical_host() ) {
			set_transient( DB_DOMAIN_INDEX_KEY, $rows, HOUR_IN_SECONDS ); // never cache links built for a preview host
		}
		return $rows;
	}
}

// Listing changes (new domain, price edit, unpublish) rebuild the index.
add_action( 'save_post_domain', function () {
	delete_transient( DB_DOMAIN_INDEX_KEY );
}, 20 );
// A price or status written straight to post meta (bulk price changes, seller edits) also rebuilds it.
$db_index_meta_hook = function ( $meta_id, $object_id, $key ) {
	if ( in_array( $key, array( 'domain_price', 'domain_status', 'db_listing_state', 'domain_keyword' ), true ) ) {
		delete_transient( DB_DOMAIN_INDEX_KEY );
	}
};
// New meta too: a seller listing is created first and its price written after, so an index built
// in between would keep the name without a price until it expired.
add_action( 'updated_post_meta', $db_index_meta_hook, 10, 3 );
add_action( 'added_post_meta', $db_index_meta_hook, 10, 3 );
add_action( 'set_object_terms', function ( $object_id ) {
	if ( 'domain' === get_post_type( $object_id ) ) {
		delete_transient( DB_DOMAIN_INDEX_KEY );
	}
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/domain-index', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$res = new WP_REST_Response( db_domain_index(), 200 );
			$res->header( 'Cache-Control', 'public, max-age=600' );
			return $res;
		},
	) );
} );

add_action( 'wp_footer', function () {
	if ( ! is_page( 'all-domains' ) ) {
		return;
	}
	$endpoint = add_query_arg( 'ver', DB_BLOCKS_LOADED, rest_url( 'db/v1/domain-index' ) );
	?>
<style id="db-dfilter-css">
.db-dfilter{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin:0 0 16px;padding:14px;border:1px solid #e3e7ee;border-radius:10px;background:#f8fafc}
.db-dfilter label{display:flex;flex-direction:column;gap:4px;font-size:12px;font-weight:600;color:#475467;letter-spacing:.02em;flex:1 1 140px;min-width:0}
.db-dfilter input,.db-dfilter select{height:38px;padding:0 10px;border:1px solid #cfd6e0;border-radius:8px;font-size:14px;background:#fff;color:#101828;width:100%;box-sizing:border-box}
.db-dfilter input:focus-visible,.db-dfilter select:focus-visible,.db-dfilter button:focus-visible{outline:2px solid #67BD3C;outline-offset:1px}
.db-dfilter .db-dfilter-q{flex:2 1 200px}
.db-dfilter button{height:38px;padding:0 14px;border:1px solid #cfd6e0;border-radius:8px;background:#fff;font-size:14px;cursor:pointer;color:#344054}
.db-dfilter-count{flex:1 1 100%;font-size:13px;color:#475467;margin:0}
.db-dfilter-count:empty{display:none}
</style>
<script id="db-dfilter-js">
(function () {
	'use strict';
	var ENDPOINT = <?php echo wp_json_encode( $endpoint ); ?>;
	var th = document.querySelector('table th.data-domain');
	var table = th && th.closest('table');
	if (!table || !table.querySelector('tbody')) { return; }
	var original = null;
	var pager = document.querySelector('.pagination, .nav-links, .navigation.pagination');
	var data = null, loading = null;

	var bar = document.createElement('form');
	bar.className = 'db-dfilter';
	bar.setAttribute('role', 'search');
	bar.setAttribute('aria-label', 'Filter domains');
	bar.innerHTML =
		'<label class="db-dfilter-q">Search name<input type="search" name="q" placeholder="e.g. fund" autocomplete="off"></label>' +
		'<label>Extension<select name="tld"><option value="">Any</option></select></label>' +
		'<label>Max length<select name="len"><option value="">Any</option><option value="3">3 or fewer</option><option value="4">4 or fewer</option><option value="5">5 or fewer</option><option value="6">6 or fewer</option><option value="8">8 or fewer</option><option value="10">10 or fewer</option></select></label>' +
		'<label>Max price<select name="max"><option value="">Any</option><option value="1000">$1,000</option><option value="2500">$2,500</option><option value="5000">$5,000</option><option value="10000">$10,000</option><option value="25000">$25,000</option><option value="offer">Make an offer only</option></select></label>' +
		'<label>Sort<select name="sort"><option value="">Featured</option><option value="az">Name A–Z</option><option value="pa">Price: low to high</option><option value="pd">Price: high to low</option><option value="la">Shortest first</option></select></label>' +
		'<button type="reset">Clear</button>' +
		'<p class="db-dfilter-count" aria-live="polite"></p>';
	table.parentNode.insertBefore(bar, table);
	var count = bar.querySelector('.db-dfilter-count');

	function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
	function sld(n) { var i = n.indexOf('.'); return i < 0 ? n : n.slice(0, i); }
	function tld(n) { var i = n.indexOf('.'); return i < 0 ? '' : n.slice(i); }
	function money(p) { return '$' + Math.round(p).toLocaleString('en-US'); }

	function load() {
		if (data) { return Promise.resolve(data); }
		if (!loading) {
			count.textContent = 'Loading all listings…';
			loading = fetch(ENDPOINT, { credentials: 'same-origin' })
				.then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
				.then(function (rows) {
					data = rows;
					var sel = bar.elements.tld, seen = {};
					rows.forEach(function (r) { var t = tld(r.n); if (t) { seen[t] = (seen[t] || 0) + 1; } });
					Object.keys(seen).sort().forEach(function (t) { var o = document.createElement('option'); o.value = t; o.textContent = t + ' (' + seen[t] + ')'; sel.appendChild(o); });
					return rows;
				});
		}
		return loading;
	}

	// The UI script (db-custom-blocks.php step 6) replaces the table with a
	// .db-domain-grid of cards after load; the bar sits just before
	// whichever of the two is on the page, so results go there, in the same
	// markup.
	function target() {
		var el = bar.nextElementSibling;
		if (el && el.classList.contains('db-domain-grid')) { return { el: el, grid: true }; }
		return { el: (el && el.tagName === 'TABLE' ? el : table).querySelector('tbody'), grid: false };
	}

	function card(r) {
		var name = r.n.charAt(0).toUpperCase() + r.n.slice(1), hash = 0, i;
		for (i = 0; i < name.length; i++) { hash = (hash * 31 + name.charCodeAt(i)) % 360; }
		var letter = (name.replace(/[^a-zA-Z0-9]/, '')[0] || '?').toUpperCase();
		var price = r.p > 0
			? '<span class="db-domain-card__price">' + money(r.p) + '</span>' + (r.p >= 600 ? '<span class="db-domain-card__mo">or from $' + Math.round(r.p * 1.10 / 12).toLocaleString('en-US') + '/mo</span>' : '')
			: '<span class="db-domain-card__price--offer">Open to offers</span>';
		var action = r.p > 0
			? '<a href="' + esc(r.u) + '" class="db-act-buy"> <span class="badge-offer badge ad-cart">Buy Now</span></a>'
			: '<a href="' + esc(r.u) + '" class="db-act-offer"> <span class="badge-offer badge ad-cart">Make an Offer</span></a>';
		return '<div class="db-domain-card"><span class="db-domain-card__mono" aria-hidden="true" style="background:linear-gradient(160deg,hsl(' + hash + ',58%,42%),hsl(' + ((hash + 40) % 360) + ',58%,32%))">' + esc(letter) + '</span>' +
			'<a class="db-domain-card__name" href="' + esc(r.u) + '">' + esc(name) + '</a>' +
			'<div class="db-domain-card__row"><div class="db-domain-card__pricewrap">' + price + '</div>' + action + '</div></div>';
	}

	function row(r) {
		var cats = r.c.map(function (c) { return '<a href="' + esc(c[1]) + '" rel="tag">' + esc(c[0]) + '</a>'; }).join(', ');
		var name = r.n.charAt(0).toUpperCase() + r.n.slice(1);
		var price = r.p > 0 ? '<span class="badge-price"> ' + money(r.p) + ' </span>' : '<span class="badge-price"> Make an offer </span>';
		return '<tr><td class="data-domain"><a href="' + esc(r.u) + '" rel="bookmark" title="' + esc(r.n) + '">' + esc(name) + '</a></td>' +
			'<td class="data-domain mbl_hd">' + cats + '</td>' +
			'<td class="innerpage-price-section">' + price + '<a href="' + esc(r.u) + '"> <span class="badge-offer badge ad-cart">' + (r.p > 0 ? 'Buy Now' : 'View') + '</span></a></td></tr>';
	}

	function active() {
		var f = bar.elements;
		return f.q.value.trim() || f.tld.value || f.len.value || f.max.value || f.sort.value;
	}

	function apply() {
		if (!active()) { reset(); return; }
		load().then(function (rows) {
			var f = bar.elements, q = f.q.value.trim().toLowerCase(), t = f.tld.value, len = parseInt(f.len.value, 10), max = f.max.value;
			var out = rows.filter(function (r) {
				if (q && r.n.indexOf(q) === -1) { return false; }
				if (t && tld(r.n) !== t) { return false; }
				if (len && sld(r.n).length > len) { return false; }
				if (max === 'offer' && r.p > 0) { return false; }
				if (max && max !== 'offer' && !(r.p > 0 && r.p <= parseFloat(max))) { return false; }
				return true;
			});
			var s = f.sort.value;
			if (s === 'az') { out.sort(function (a, b) { return a.n < b.n ? -1 : 1; }); }
			if (s === 'pa') { out.sort(function (a, b) { return (a.p || Infinity) - (b.p || Infinity); }); }
			if (s === 'pd') { out.sort(function (a, b) { return b.p - a.p; }); }
			if (s === 'la') { out.sort(function (a, b) { return sld(a.n).length - sld(b.n).length || (a.n < b.n ? -1 : 1); }); }
			var t = target();
			if (original === null) { original = { el: t.el, html: t.el.innerHTML }; }
			var none = 'No domains match these filters. <a href="/contact-us/">Tell us what you are looking for</a> and we will search for you.';
			if (t.grid) {
				t.el.innerHTML = out.length ? out.map(card).join('') : '<p class="db-dfilter-none" style="grid-column:1/-1;margin:0">' + none + '</p>';
			} else {
				t.el.innerHTML = out.length ? out.map(row).join('') : '<tr><td colspan="3" class="data-domain">' + none + '</td></tr>';
			}
			if (pager) { pager.style.display = 'none'; }
			count.textContent = out.length + ' of ' + rows.length + ' domains';
		}).catch(function () {
			count.textContent = 'Filters are unavailable right now. Showing the standard list.';
		});
	}

	function reset() {
		if (original) { original.el.innerHTML = original.html; original = null; }
		if (pager) { pager.style.display = ''; }
		count.textContent = '';
	}

	var timer;
	bar.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(apply, 200); });
	bar.addEventListener('change', apply);
	bar.addEventListener('submit', function (e) { e.preventDefault(); apply(); });
	bar.addEventListener('reset', function () { setTimeout(reset, 0); });
	// Arrive with a search already applied (?q=, ?tld=, ?max=, ?sort=), e.g. from the home page.
	(function () {
		var qs = new URLSearchParams(window.location.search);
		if (!(qs.get('q') || qs.get('tld') || qs.get('max') || qs.get('sort'))) { return; }
		if (qs.get('q')) { bar.elements.q.value = qs.get('q'); }
		load().then(function () {
			// ?tld=ca and ?tld=.CA both mean .ca; an unknown value was dropped silently (LS-15).
			if (qs.get('tld')) { var tv = String(qs.get('tld')).trim().toLowerCase(); if (tv && tv.charAt(0) !== '.') { tv = '.' + tv; } bar.elements.tld.value = tv; }
			if (qs.get('max')) { bar.elements.max.value = qs.get('max'); }
			if (qs.get('sort')) { bar.elements.sort.value = qs.get('sort'); }
			apply();
		}).catch(function () {});
	})();
	// Populate the extension list as soon as someone reaches for the bar.
	bar.addEventListener('focusin', function () { load().catch(function () {}); }, { once: true });
	// Fill the extension list as soon as the bar is ready: loading on first focus meant the native
	// picker opened with only 'Any' (bug register batch 2, LS-9).
	(window.requestIdleCallback || function (f) { return setTimeout(f, 200); })(function () {
		load().then(function () { if (count.textContent === 'Loading all listings…') { count.textContent = ''; } }).catch(function () { count.textContent = ''; });
	});
})();
</script>
	<?php
}, 30 );

/*
 * All Domains lists every name for sale. The theme's template (page-templates/domains.php) runs
 * its own WP_Query: one page of 100 posts, sold names included, and its price and length views
 * also require Domain_owner = "Jay Mehta". With page 2 redirected to page 1, names past the first
 * 100 (every new seller listing among them) never appeared, and seller names were missing from
 * the filtered views. Here the theme's domain query on that page loads all names for sale (up to
 * 1000), without sold names and without the owner condition.
 */
if ( ! function_exists( 'db_ad_fix_query_vars' ) ) {
	/**
	 * Pure: the corrected query vars for the theme's All Domains query.
	 *
	 * Names marked sold or not for sale by the plugin (db_listing_state) are left out as well as
	 * domain_status = sold. With $for_sale_ids (the ids in db_domain_index(), which also knows the
	 * names already taken through checkout) the list is limited to exactly those names.
	 *
	 * @param array      $qv           WP_Query vars.
	 * @param array|null $for_sale_ids Ids of the names for sale, or null when not known.
	 * @return array
	 */
	function db_ad_fix_query_vars( array $qv, $for_sale_ids = null ) {
		$qv['posts_per_page'] = 1000;
		$qv['paged']          = 1;
		$qv['no_found_rows']  = false;
		$meta = isset( $qv['meta_query'] ) && is_array( $qv['meta_query'] ) ? $qv['meta_query'] : array();
		$meta = array_values( array_filter( $meta, static function ( $c ) {
			return ! ( is_array( $c ) && isset( $c['key'] ) && 'Domain_owner' === $c['key'] );
		} ) );
		$has_status = (bool) array_filter( $meta, static function ( $c ) {
			return is_array( $c ) && isset( $c['key'] ) && 'domain_status' === $c['key'];
		} );
		if ( ! $has_status ) {
			$meta[] = array(
				'relation' => 'OR',
				array( 'key' => 'domain_status', 'value' => 'sold', 'compare' => '!=' ),
				array( 'key' => 'domain_status', 'compare' => 'NOT EXISTS' ),
			);
		}
		$meta[] = array(
			'relation' => 'OR',
			array( 'key' => 'db_listing_state', 'value' => array( 'sold', 'not_for_sale' ), 'compare' => 'NOT IN' ),
			array( 'key' => 'db_listing_state', 'compare' => 'NOT EXISTS' ),
		);
		$qv['meta_query'] = $meta;
		if ( is_array( $for_sale_ids ) && $for_sale_ids ) {
			$ids = array_values( array_unique( array_map( 'intval', $for_sale_ids ) ) );
			$qv['post__in'] = isset( $qv['post__in'] ) && is_array( $qv['post__in'] ) && $qv['post__in']
				? array_values( array_intersect( array_map( 'intval', $qv['post__in'] ), $ids ) )
				: $ids;
			if ( ! $qv['post__in'] ) {
				$qv['post__in'] = array( 0 ); // nothing for sale matches: an empty list, not every post
			}
		}
		return $qv;
	}
}

if ( ! function_exists( 'db_ad_is_theme_query' ) ) {
	/** Pure: true for the theme template's own list queries (100 per page, or the Domain_owner filter). */
	function db_ad_is_theme_query( array $qv ) {
		if ( 100 === (int) ( $qv['posts_per_page'] ?? 0 ) ) {
			return true;
		}
		foreach ( (array) ( $qv['meta_query'] ?? array() ) as $c ) {
			if ( is_array( $c ) && 'Domain_owner' === ( $c['key'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}
}

add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() || $q->is_main_query() || 'domain' !== $q->get( 'post_type' ) || ! did_action( 'template_redirect' ) ) {
		return;
	}
	if ( ! function_exists( 'is_page' ) || ! is_page( 'all-domains' ) || ! db_ad_is_theme_query( $q->query_vars ) ) {
		return;
	}
	$for_sale = function_exists( 'db_domain_index' ) ? array_map( static function ( $r ) {
		return (int) $r['id'];
	}, db_domain_index() ) : null;
	foreach ( db_ad_fix_query_vars( $q->query_vars, $for_sale ) as $k => $v ) {
		$q->set( $k, $v );
	}
} );

if ( ! function_exists( 'db_ad_only_for_sale_vars' ) ) {
	/**
	 * Pure: the sold and not-for-sale exclusion of db_ad_fix_query_vars() without its paging
	 * changes, for list queries that must keep their own size (the home page sections).
	 */
	function db_ad_only_for_sale_vars( array $qv, $for_sale_ids = null ) {
		$fixed = db_ad_fix_query_vars( $qv, $for_sale_ids );
		foreach ( array( 'posts_per_page', 'paged', 'no_found_rows' ) as $k ) {
			if ( array_key_exists( $k, $qv ) ) {
				$fixed[ $k ] = $qv[ $k ];
			} else {
				unset( $fixed[ $k ] );
			}
		}
		return $fixed;
	}
}

// The theme's home sections (Featured Top Domains, Newly Added, Latest Addition for ...) listed
// sold names as "Open to offers", and their offer buttons pointed at another listing (bug register
// batch 2, LS-1 and LS-2). Same rule as All Domains: only names in the for-sale index.
add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() || $q->is_main_query() || ! did_action( 'template_redirect' ) || ! function_exists( 'is_front_page' ) || ! is_front_page() ) {
		return;
	}
	$pt = $q->get( 'post_type' );
	if ( 'domain' !== $pt && array( 'domain' ) !== $pt ) {
		return;
	}
	$for_sale = function_exists( 'db_domain_index' ) ? array_map( static function ( $r ) {
		return (int) $r['id'];
	}, db_domain_index() ) : null;
	foreach ( db_ad_only_for_sale_vars( $q->query_vars, $for_sale ) as $k => $v ) {
		$q->set( $k, $v );
	}
} );

// Domain search results (?s=...&post_type=domain) list only names for sale, as the header's match
// count does: sold names were mixed into the results (LS-11).
add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() || ! $q->is_main_query() || ! $q->is_search() || ! function_exists( 'db_domain_index' ) ) {
		return;
	}
	$pt = $q->get( 'post_type' );
	if ( 'domain' !== $pt && array( 'domain' ) !== $pt ) {
		return;
	}
	$ids = array_map( static function ( $r ) {
		return (int) $r['id'];
	}, (array) db_domain_index() );
	$q->set( 'post__in', $ids ? $ids : array( 0 ) );
} );

if ( ! function_exists( 'db_ad_clean_rows_html' ) ) {
	/**
	 * Pure: in theme list tables, drops rows for names that are not for sale and makes every link
	 * in a row point at the row's own listing (LS-1, LS-2).
	 *
	 * @param string   $html     Page HTML.
	 * @param string[] $unsold   Listing slugs that are sold or not for sale.
	 * @param string   $base     Listing URL prefix, e.g. https://example.test/domains/.
	 * @return string
	 */
	function db_ad_clean_rows_html( $html, array $unsold, $base ) {
		if ( ! is_string( $html ) || false === stripos( $html, '<tr' ) ) {
			return $html;
		}
		$q    = preg_quote( rtrim( (string) $base, '/' ) . '/', '#' );
		$drop = array_flip( array_map( 'strtolower', $unsold ) );
		return (string) preg_replace_callback( '#<tr\b[^>]*>.*?</tr>#is', static function ( $m ) use ( $q, $drop ) {
			if ( ! preg_match_all( '#href=([\'"])' . $q . '([a-z0-9-]+)/?\1#i', $m[0], $links ) ) {
				return $m[0];
			}
			$own = strtolower( $links[2][0] );
			if ( isset( $drop[ $own ] ) ) {
				return '';
			}
			return (string) preg_replace_callback( '#(href=([\'"]))' . $q . '([a-z0-9-]+)(/?)\2#i', static function ( $l ) use ( $own ) {
				return strtolower( $l[3] ) === $own ? $l[0] : str_replace( $l[3], $own, $l[0] );
			}, $m[0] );
		}, $html );
	}
}

if ( ! function_exists( 'db_ad_unsold_slugs' ) ) {
	/** Slugs of published listings that are sold or not for sale (cached for 10 minutes). */
	function db_ad_unsold_slugs() {
		$c = get_transient( 'db_ad_unsold_slugs' );
		if ( is_array( $c ) ) {
			return $c;
		}
		$ids  = get_posts( array( 'post_type' => 'domain', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'suppress_filters' => true ) );
		$out  = array();
		foreach ( $ids as $id ) {
			if ( function_exists( 'db_listing_state' ) && in_array( db_listing_state( $id ), array( 'sold', 'not_for_sale' ), true ) ) {
				$out[] = get_post_field( 'post_name', $id );
			}
		}
		set_transient( 'db_ad_unsold_slugs', $out, 10 * MINUTE_IN_SECONDS );
		return $out;
	}
}
add_action( 'updated_post_meta', static function ( $mid, $pid, $key ) {
	if ( in_array( $key, array( 'db_listing_state', 'domain_status' ), true ) ) {
		delete_transient( 'db_ad_unsold_slugs' );
	}
}, 10, 3 );

add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_front_page() ) {
		return;
	}
	ob_start( static function ( $html ) {
		return db_ad_clean_rows_html( $html, db_ad_unsold_slugs(), home_url( '/domains/' ) );
	} );
}, -998 ); // before any route that renders and exits; inside the page cache's buffer

if ( ! function_exists( 'db_ad_heading_count_html' ) ) {
	/** Pure: replaces the number in the archive heading's "<span>N Domains</span>". */
	function db_ad_heading_count_html( $html, $n ) {
		if ( ! is_string( $html ) ) {
			return $html;
		}
		$n = (int) $n;
		return (string) preg_replace( '#(<h1\b[^>]*class=["\'][^"\']*page-title[^>]*>.*?<span>)\d+\s+Domains?(</span>)#is', '${1}' . $n . ( 1 === $n ? ' Domain' : ' Domains' ) . '${2}', $html, 1 );
	}
}

// Category headings counted every published name, sold ones included ("Business Names - 98
// Domains" while 76 are listed; bug register batch 2, LS-6). The number is now the names for sale
// in that category, the same set the list shows.
add_action( 'template_redirect', function () {
	if ( is_admin() || ! is_tax( 'domain_category' ) || ! function_exists( 'db_domain_index' ) ) {
		return;
	}
	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return;
	}
	$for_sale = array_map( static function ( $r ) {
		return (int) $r['id'];
	}, db_domain_index() );
	$in_term  = get_objects_in_term( (int) $term->term_id, 'domain_category' );
	$n        = is_array( $in_term ) ? count( array_intersect( array_map( 'intval', $in_term ), $for_sale ) ) : 0;
	ob_start( static function ( $html ) use ( $n ) {
		return db_ad_heading_count_html( $html, $n );
	} );
}, -996 );

if ( ! function_exists( 'db_ad_sidebar_html' ) ) {
	/**
	 * Pure: the theme sidebar's "By TLD" links go to All Domains filtered by extension, with the
	 * number for sale; extensions with nothing for sale and empty length lists are blanked (the
	 * table keeps its shape). They used to search for '.net' and show only sold names
	 * (bug register batch 2, LS-8).
	 *
	 * @param string $html      Page HTML.
	 * @param array  $tld_count Extension (".ca") => names for sale.
	 * @param array  $empty     Paths of list pages with nothing for sale.
	 * @param string $all_url   All Domains URL.
	 */
	function db_ad_sidebar_html( $html, array $tld_count, array $empty, $all_url ) {
		if ( ! is_string( $html ) || false === strpos( $html, 'post_type=domain' ) ) {
			return $html;
		}
		$html = (string) preg_replace_callback( '#(<td\b[^>]*>)\s*<a href="[^"]*\?s=(\.[a-z.]+)&(?:amp;)?post_type=domain"[^>]*>[^<]*</a>\s*(</td>)#i', static function ( $m ) use ( $tld_count, $all_url ) {
			$ext = strtolower( $m[2] );
			if ( empty( $tld_count[ $ext ] ) ) {
				return $m[1] . $m[3];
			}
			$url = rtrim( (string) $all_url, '?' ) . ( false === strpos( (string) $all_url, '?' ) ? '?' : '&' ) . 'tld=' . rawurlencode( $ext );
			return $m[1] . '<a href="' . htmlspecialchars( $url, ENT_QUOTES ) . '">' . htmlspecialchars( $ext . ' (' . (int) $tld_count[ $ext ] . ')', ENT_QUOTES ) . '</a>' . $m[3];
		}, $html );
		foreach ( $empty as $path ) {
			$html = (string) preg_replace( '#(<td\b[^>]*>)\s*<a href="[^"]*' . preg_quote( $path, '#' ) . '"[^>]*>[^<]*</a>\s*(</td>)#i', '$1$2', $html );
		}
		return $html;
	}
}

add_action( 'template_redirect', function () {
	if ( is_admin() || ! ( is_front_page() || is_tax( 'domain_category' ) ) || ! function_exists( 'db_domain_index' ) ) {
		return;
	}
	ob_start( static function ( $html ) {
		$count = array();
		foreach ( db_domain_index() as $r ) {
			$n = strtolower( (string) ( $r['n'] ?? $r['name'] ?? get_the_title( (int) $r['id'] ) ) );
			$i = strpos( $n, '.' );
			if ( false !== $i ) {
				$ext           = substr( $n, $i );
				$count[ $ext ] = ( $count[ $ext ] ?? 0 ) + 1;
			}
		}
		$empty = array();
		if ( function_exists( 'db_pages_listing_counts' ) ) {
			foreach ( (array) db_pages_listing_counts() as $slug => $c ) {
				if ( 0 === (int) $c ) {
					$empty[] = '/' . $slug . '/';
				}
			}
		}
		return db_ad_sidebar_html( $html, $count, $empty, home_url( '/all-domains/' ) );
	} );
}, -995 );
