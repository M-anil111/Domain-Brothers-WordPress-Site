/*!
 * Domain Brothers UI Fixes – All Domains listing state (DB-06, DB-14).
 *
 * Works on top of the existing filter bar (form.db-dfilter, inline script
 * "db-dfilter-js") and results heading (.dbr-results, all-domains.js) without
 * changing how they filter, sort or render cards. It:
 *   1. shows "Showing X–Y of N" where N is the number of available listings
 *      across every page of the marketplace, and X–Y is this page's range;
 *   2. keeps the count visible while filters are active, with an empty state;
 *   3. mirrors filter values in the URL (q, tld, len, max, sort) so results
 *      can be shared, and re-applies them on load, reload and Back.
 *
 * "Available" uses the same rule the card renderer applies to the server
 * table: a row with a Buy Now / Make an Offer badge and a real price or an
 * offer CTA. Price-less "$ Buy Now" rows are not rendered as cards and are not
 * counted.
 */
(function () {
	'use strict';

	var CFG = window.dbuxListing || {};
	var KEYS = ['q', 'tld', 'len', 'max', 'sort'];
	var CACHE_KEY = 'dbux-listing-counts-v3';
	var CACHE_TTL = 30 * 60 * 1000;

	function fmt(n) {
		try { return Number(n).toLocaleString('en-US'); } catch (e) { return String(n); }
	}
	function plural(n, one, many) { return n === 1 ? one : many; }

	function readCache() {
		try {
			var raw = window.sessionStorage.getItem(CACHE_KEY);
			if (!raw) { return null; }
			var c = JSON.parse(raw);
			if (!c || Date.now() - c.t > CACHE_TTL) { return null; }
			return c;
		} catch (e) { return null; }
	}
	function writeCache(c) {
		try { c.t = Date.now(); window.sessionStorage.setItem(CACHE_KEY, JSON.stringify(c)); } catch (e) { /* storage unavailable */ }
	}

	function basePath() {
		var p = CFG.basePath || window.location.pathname.replace(/page\/\d+\/?$/, '');
		return p.charAt(p.length - 1) === '/' ? p : p + '/';
	}
	function currentPage() {
		var m = window.location.pathname.match(/\/page\/(\d+)\/?$/);
		return m ? parseInt(m[1], 10) : 1;
	}
	function pageUrl(n) { return n <= 1 ? basePath() : basePath() + 'page/' + n + '/'; }

	function lastPage(doc) {
		var max = 1;
		var re = /\/page\/(\d+)\/?(?:[?#]|$)/;
		var links = doc.querySelectorAll('a[href]');
		for (var i = 0; i < links.length; i++) {
			var href = links[i].getAttribute('href') || '';
			if (href.indexOf(basePath()) === -1 && href.indexOf('/page/') !== 0) { continue; }
			var m = re.exec(href);
			if (m) { max = Math.max(max, parseInt(m[1], 10)); }
		}
		return max;
	}

	// Same availability rule as the card renderer (see header comment).
	function countAvailableRows(doc) {
		var cells = doc.querySelectorAll('.resp_table tbody td.innerpage-price-section');
		var seen = {};
		var n = 0;
		for (var i = 0; i < cells.length; i++) {
			var cell = cells[i];
			if (!cell.querySelector('.badge-offer')) { continue; }
			var text = (cell.textContent || '').replace(/\s+/g, ' ').trim();
			if (/^\$\s*Buy Now$/i.test(text)) { continue; }
			var row = cell.closest('tr');
			var link = row && row.querySelector('td.data-domain a[rel="bookmark"]');
			var key = link ? link.getAttribute('href') : 'row-' + i;
			if (seen[key]) { continue; }
			seen[key] = true;
			n++;
		}
		return n;
	}

	function fetchPageCount(n) {
		return fetch(pageUrl(n), { credentials: 'same-origin' })
			.then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.text(); })
			.then(function (html) {
				var doc = new DOMParser().parseFromString(html, 'text/html');
				// Only trust a complete listing page; an error or challenge
				// page must not be cached as "0 available".
				if (!doc.querySelector('.resp_table tbody tr')) { throw new Error('no listing'); }
				return { count: countAvailableRows(doc), last: lastPage(doc) };
			});
	}

	function init() {
		var bar = document.querySelector('form.db-dfilter');
		var root = document.querySelector('.resp_table');
		if (!bar || !root) { return false; }
		var grid = root.querySelector('.db-domain-grid');
		var heading = root.querySelector('.dbr-results');
		if (!grid || !heading) { return false; }
		if (root.getAttribute('data-dbux') === '1') { return true; }
		root.setAttribute('data-dbux', '1');

		var titleEl = heading.querySelector('.dbr-results__title');
		var subEl = heading.querySelector('.dbr-results__sub');
		var hero = document.querySelector('[data-dbux-available]');

		// The heading is the visible count; .db-dfilter-count stays the polite
		// live region that announces filter results.
		heading.removeAttribute('aria-hidden');
		bar.setAttribute('autocomplete', 'off');

		var page = currentPage();
		var initialCards = grid.querySelectorAll('.db-domain-card').length;
		var arrivedFiltered = KEYS.some(function (k) { return !!new URLSearchParams(window.location.search).get(k); });
		var totals = null; // { total, offset }

		function isFiltered() {
			var f = bar.elements;
			for (var i = 0; i < KEYS.length; i++) {
				var el = f[KEYS[i]];
				if (el && String(el.value || '').trim()) { return true; }
			}
			return false;
		}

		var writing = false;
		function setText(el, text) {
			if (el && el.textContent !== text) { el.textContent = text; }
		}
		function render() {
			if (writing) { return; }
			writing = true;
			var cards = grid.querySelectorAll('.db-domain-card').length;
			if (isFiltered()) {
				var countText = (bar.querySelector('.db-dfilter-count') || {}).textContent || '';
				if (/loading/i.test(countText)) {
					setText(titleEl, 'Updating results…');
					setText(subEl, countText.trim());
				} else if (cards === 0) {
					setText(titleEl, 'No domains found');
					setText(subEl, 'Try removing a filter or clearing your search.');
				} else {
					setText(titleEl, fmt(cards) + ' ' + plural(cards, 'domain', 'domains') + ' found');
					setText(subEl, 'Showing 1–' + fmt(cards) + ' of ' + fmt(cards) + ' matching your filters');
				}
			} else if (totals) {
				var from = cards ? totals.offset + 1 : 0;
				var to = totals.offset + cards;
				setText(titleEl, fmt(totals.total) + ' ' + plural(totals.total, 'domain', 'domains') + ' available');
				setText(subEl, cards ? 'Showing ' + fmt(from) + '–' + fmt(to) + ' of ' + fmt(totals.total) : 'No domains on this page');
			} else {
				setText(titleEl, fmt(cards) + ' ' + plural(cards, 'domain', 'domains') + ' on this page');
				setText(subEl, 'Counting all available domains…');
			}
			if (hero && totals) {
				setText(hero, fmt(totals.total) + ' ' + plural(totals.total, 'name', 'names') + ' available.');
			}
			writing = false;
		}

		// all-domains.js rewrites the heading on every grid mutation; re-assert ours after it.
		var queued = false;
		function schedule() {
			if (queued) { return; }
			queued = true;
			window.requestAnimationFrame(function () {
				window.requestAnimationFrame(function () { queued = false; render(); });
			});
		}
		if ('MutationObserver' in window) {
			new MutationObserver(schedule).observe(heading, { childList: true, characterData: true, subtree: true });
			new MutationObserver(schedule).observe(grid, { childList: true });
			var live = bar.querySelector('.db-dfilter-count');
			if (live) { new MutationObserver(schedule).observe(live, { childList: true, characterData: true, subtree: true }); }
		}

		// ---- totals across pages -------------------------------------------
		function computeTotals() {
			// The server gives the number of names for sale; on an unfiltered page 1 that is the
			// total, so the page is not fetched a second time (290 KB) just to count (LS-14).
			var given = window.dbuxListing && parseInt(window.dbuxListing.total, 10);
			if (given > 0 && page === 1 && !arrivedFiltered) { return Promise.resolve({ total: given, offset: 0 }); }
			// The rendered page no longer carries the theme's pagination links
			// (the card UI replaces that markup), so read the page count from the
			// server HTML of page 1 and walk the remaining pages from there.
			var cache = readCache() || { pages: {} };
			cache.pages = cache.pages || {};
			// Only trust this page's rendered cards when no filter was applied
			// on arrival (a shared ?len=… link renders the filtered set).
			var trustRendered = !arrivedFiltered;
			if (trustRendered) { cache.pages[page] = initialCards; }
			var last = Math.max(cache.last || 1, lastPage(document), page);

			function ensure(k) {
				if (typeof cache.pages[k] === 'number' && cache.last) { return Promise.resolve(); }
				return fetchPageCount(k).then(function (res) {
					if (k !== page || !trustRendered) { cache.pages[k] = res.count; }
					last = Math.max(last, res.last);
				});
			}

			return ensure(1).then(function () {
				var jobs = [];
				for (var n = 2; n <= last; n++) { if (n !== page || !trustRendered) { jobs.push(ensure(n)); } }
				return Promise.all(jobs);
			}).then(function () {
				var total = 0;
				var offset = 0;
				for (var k = 1; k <= last; k++) {
					var c = cache.pages[k] || 0;
					total += c;
					if (k < page) { offset += c; }
				}
				cache.last = last;
				writeCache(cache);
				return { total: total, offset: offset };
			});
		}

		// ---- URL <-> filter state ------------------------------------------
		function stateFromUrl() {
			var qs = new URLSearchParams(window.location.search);
			var s = {};
			KEYS.forEach(function (k) { s[k] = (qs.get(k) || '').trim(); });
			return s;
		}
		function hasState(s) {
			return KEYS.some(function (k) { return !!s[k]; });
		}
		function syncUrl() {
			var url = new URL(window.location.href);
			var f = bar.elements;
			KEYS.forEach(function (k) { url.searchParams.delete(k); });
			KEYS.forEach(function (k) {
				var v = f[k] ? String(f[k].value || '').trim() : '';
				if (v) { url.searchParams.set(k, v); }
			});
			if (url.href !== window.location.href) {
				window.history.replaceState(window.history.state, '', url.href);
			}
		}
		var syncTimer;
		function syncSoon() { clearTimeout(syncTimer); syncTimer = setTimeout(syncUrl, 250); }
		bar.addEventListener('input', syncSoon);
		bar.addEventListener('change', syncSoon);
		bar.addEventListener('reset', function () { setTimeout(function () { syncUrl(); schedule(); }, 0); });

		function waitForTldOptions() {
			return new Promise(function (resolve) {
				var sel = bar.elements.tld;
				var tries = 0;
				(function check() {
					if (!sel || sel.options.length > 1 || tries++ > 50) { resolve(); return; }
					setTimeout(check, 100);
				})();
			});
		}

		// Make the controls match the URL. The browser can restore stale form
		// values on reload/Back; the URL is the source of truth.
		function restore() {
			var s = stateFromUrl();
			var f = bar.elements;
			if (!hasState(s)) {
				var stale = KEYS.some(function (k) { return f[k] && String(f[k].value || '').trim(); });
				if (stale) {
					bar.reset(); // dfilter's reset handler restores the unfiltered page.
				}
				schedule();
				return;
			}
			// Ask the filter script to load its index (it populates Extension).
			bar.dispatchEvent(new Event('focusin', { bubbles: true }));
			waitForTldOptions().then(function () {
				KEYS.forEach(function (k) { if (f[k]) { f[k].value = s[k]; } });
				bar.dispatchEvent(new Event('change', { bubbles: true }));
				schedule();
			});
		}

		window.addEventListener('pageshow', function (e) {
			if (e.persisted) { restore(); }
		});

		restore();
		render();
		computeTotals().then(function (t) { totals = t; render(); }).catch(function () { render(); });
		return true;
	}

	function boot() {
		if (init()) { return; }
		// The grid and heading are built by deferred scripts; wait briefly for them.
		var tries = 0;
		var t = setInterval(function () {
			if (init() || ++tries > 40) { clearInterval(t); }
		}, 100);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot, { once: true });
	} else {
		boot();
	}
})();
