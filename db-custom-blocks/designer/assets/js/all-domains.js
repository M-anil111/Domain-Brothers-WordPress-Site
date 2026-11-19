/*!
 * Domain Brothers – All Domains UI redesign (presentation layer only).
 *
 * This script never touches data, requests, filters, sorting or the view
 * toggle. It only:
 *   1. adds a results heading (mirrors the existing live count),
 *   2. adds a column header for List View,
 *   3. marks each rendered card as processed (no extra card elements; extension
 *      labels were removed on request).
 * Cards are re-rendered by the existing filter script, so decoration is
 * re-applied through a MutationObserver and is idempotent.
 */
(function () {
	'use strict';

	var DONE_FLAG = 'dbrDecorated';

	function ready(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn, { once: true });
		} else {
			fn();
		}
	}

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (typeof text === 'string') { node.textContent = text; }
		return node;
	}

	function decorateCard(card) {
		if (!card || card.dataset[DONE_FLAG] === '1') { return; }

		if (!card.querySelector('.db-domain-card__name')) { return; }

		card.dataset[DONE_FLAG] = '1';
	}

	function decorateAll(grid) {
		var cards = grid.querySelectorAll('.db-domain-card');
		for (var i = 0; i < cards.length; i++) { decorateCard(cards[i]); }
	}

	function pluralise(n, one, many) {
		return n === 1 ? one : many;
	}

	function formatNumber(n) {
		try { return n.toLocaleString('en-US'); } catch (err) { return String(n); }
	}

	function updateHeading(heading, grid, countNode) {
		var title = heading.querySelector('.dbr-results__title');
		var sub = heading.querySelector('.dbr-results__sub');
		var rendered = grid.querySelectorAll('.db-domain-card').length;
		var countText = countNode ? (countNode.textContent || '').trim() : '';
		var match = /([\d,]+)\s+of\s+([\d,]+)/i.exec(countText);

		if (match) {
			var shown = parseInt(match[1].replace(/,/g, ''), 10);
			var total = parseInt(match[2].replace(/,/g, ''), 10);
			title.textContent = formatNumber(shown) + ' ' + pluralise(shown, 'domain', 'domains') + ' found';
			sub.textContent = 'Matching your filters, out of ' + formatNumber(total) + ' listed';
		} else if (countText) {
			// e.g. "Loading all listings…" – mirror the existing status message.
			title.textContent = formatNumber(rendered) + ' ' + pluralise(rendered, 'domain', 'domains') + ' available';
			sub.textContent = countText;
		} else {
			title.textContent = formatNumber(rendered) + ' ' + pluralise(rendered, 'domain', 'domains') + ' available';
			sub.textContent = rendered ? 'Showing 1–' + formatNumber(rendered) + ' of ' + formatNumber(rendered) : 'No domains match these filters';
		}
	}

	function buildHeading() {
		var heading = el('div', 'dbr-results');
		// The original .db-dfilter-count stays the aria-live region; this copy is visual.
		heading.setAttribute('aria-hidden', 'true');
		heading.appendChild(el('p', 'dbr-results__title'));
		heading.appendChild(el('p', 'dbr-results__sub'));
		return heading;
	}

	function buildListHead() {
		var head = el('div', 'dbr-listhead');
		head.setAttribute('aria-hidden', 'true');
		head.appendChild(el('span', 'dbr-listhead__name', 'Domain name'));
		head.appendChild(el('span', 'dbr-listhead__price', 'Price (USD)'));
		head.appendChild(el('span', 'dbr-listhead__actions', 'Purchase options'));
		return head;
	}

	function init() {
		var root = document.querySelector('.resp_table');
		if (!root) { return; }
		var grid = root.querySelector('.db-domain-grid');
		if (!grid) { return; }
		if (root.dataset.dbrInit === '1') { return; }
		root.dataset.dbrInit = '1';
		root.classList.add('dbr');

		var countNode = root.querySelector('.db-dfilter-count');

		var heading = buildHeading();
		root.insertBefore(heading, root.firstChild);

		// Must not sit between the form and the grid: the existing filter
		// script locates the grid relative to the form. CSS grid placement
		// positions it visually above the rows.
		var listHead = buildListHead();
		root.insertBefore(listHead, heading.nextSibling);

		decorateAll(grid);
		updateHeading(heading, grid, countNode);

		var scheduled = false;
		function refresh() {
			if (scheduled) { return; }
			scheduled = true;
			window.requestAnimationFrame(function () {
				scheduled = false;
				decorateAll(grid);
				updateHeading(heading, grid, countNode);
			});
		}

		if ('MutationObserver' in window) {
			new MutationObserver(refresh).observe(grid, { childList: true, subtree: true });
			if (countNode) {
				new MutationObserver(refresh).observe(countNode, { childList: true, characterData: true, subtree: true });
			}
		}
	}

	ready(init);
})();
