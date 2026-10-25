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
	define( 'DB_DOMAIN_INDEX_KEY', 'db_domain_index_v1' );
}

if ( ! function_exists( 'db_domain_index' ) ) {
	/**
	 * Published domain listings as compact rows: n (name), u (URL),
	 * p (price, 0 = make an offer), c (categories as [name, url]).
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
				'n' => $name,
				'u' => get_permalink( $id ),
				'p' => $price,
				'c' => $cats,
			);
		}
		set_transient( DB_DOMAIN_INDEX_KEY, $rows, HOUR_IN_SECONDS );
		return $rows;
	}
}

// Listing changes (new domain, price edit, unpublish) rebuild the index.
add_action( 'save_post_domain', function () {
	delete_transient( DB_DOMAIN_INDEX_KEY );
} );
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
	$endpoint = rest_url( 'db/v1/domain-index' );
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
	// Populate the extension list as soon as someone reaches for the bar.
	bar.addEventListener('focusin', function () { load().catch(function () {}); }, { once: true });
})();
</script>
	<?php
}, 30 );
