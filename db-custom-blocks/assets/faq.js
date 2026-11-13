/*
 * FAQs page: accordion, question search and topic highlighting.
 * Loaded only on /faqs/ (db-faq-block.php). No dependencies.
 */
(function () {
	'use strict';

	var root = document.querySelector('.db-faq');
	if (!root) { return; }

	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var items = Array.prototype.slice.call(root.querySelectorAll('[data-db-faq-item]'));

	/* ---- Accordion ------------------------------------------------------ */
	function setOpen(item, open, instant) {
		var btn = item.querySelector('.db-faq-q button');
		var panel = document.getElementById(btn.getAttribute('aria-controls'));
		if (!panel || (btn.getAttribute('aria-expanded') === 'true') === open) { return; }
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		item.classList.toggle('is-open', open);

		if (reduce || instant) {
			panel.hidden = !open;
			panel.style.height = '';
			return;
		}
		var done = function (e) {
			if (e && e.target !== panel) { return; }
			panel.removeEventListener('transitionend', done);
			clearTimeout(panel._dbT);
			panel.style.height = '';
			panel.classList.remove('is-moving');
			if (!open) { panel.hidden = true; }
		};
		if (panel._dbDone) { panel.removeEventListener('transitionend', panel._dbDone); }
		clearTimeout(panel._dbT);
		panel._dbDone = done;
		panel.classList.add('is-moving');
		if (open) {
			panel.hidden = false;
			var h = panel.scrollHeight;
			panel.style.height = '0px';
			void panel.offsetHeight; // start from 0
			panel.style.height = h + 'px';
		} else {
			panel.style.height = panel.scrollHeight + 'px';
			void panel.offsetHeight;
			panel.style.height = '0px';
		}
		panel.addEventListener('transitionend', done);
		panel._dbT = setTimeout(done, 450); // in case transitionend never fires
	}

	items.forEach(function (item) {
		var btn = item.querySelector('.db-faq-q button');
		btn.addEventListener('click', function () {
			setOpen(item, btn.getAttribute('aria-expanded') !== 'true');
		});
	});

	/* ---- Open the question named in the address (#faq-dns-propagation) -- */
	function openFromHash() {
		var id = decodeURIComponent((location.hash || '').slice(1));
		if (!id) { return; }
		var target = document.getElementById(id);
		if (!target) { return; }
		var item = target.closest('[data-db-faq-item]');
		if (item) {
			setOpen(item, true, true);
			item.scrollIntoView({ block: 'start', behavior: reduce ? 'auto' : 'smooth' });
		}
	}
	openFromHash();
	window.addEventListener('hashchange', openFromHash);

	/* ---- Search --------------------------------------------------------- */
	var form = root.querySelector('[data-db-faq-search]');
	var input = form ? form.querySelector('input') : null;
	var status = document.getElementById('db-faq-status');
	var empty = root.querySelector('[data-db-faq-empty]');
	var sections = Array.prototype.slice.call(root.querySelectorAll('[data-db-faq-sec]'));
	var opened = null; // items we opened for a search, closed again when it is cleared

	function norm(s) {
		return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
	}
	items.forEach(function (item) { item._dbText = norm(item.textContent); });

	function runSearch(scroll) {
		var q = norm(input.value);
		var words = q ? q.split(' ') : [];
		var hits = 0;
		if (!opened) { opened = []; }
		items.forEach(function (item) {
			var match = words.every(function (w) { return item._dbText.indexOf(w) !== -1; });
			item.hidden = !match;
			if (match) {
				hits++;
				if (words.length && item.querySelector('.db-faq-q button').getAttribute('aria-expanded') !== 'true') {
					setOpen(item, true, true);
					opened.push(item);
				}
			}
		});
		if (!words.length) {
			opened.forEach(function (item) { setOpen(item, false, true); });
			opened = null;
		}
		sections.forEach(function (sec) {
			sec.hidden = !sec.querySelector('[data-db-faq-item]:not([hidden])');
		});
		if (empty) { empty.hidden = hits !== 0; }
		if (status) {
			status.textContent = !words.length ? '' : (hits === 0 ? 'No questions match your search.' : (hits === 1 ? '1 question matches your search.' : hits + ' questions match your search.'));
		}
		if (scroll && words.length) {
			var first = root.querySelector('[data-db-faq-item]:not([hidden])') || empty;
			if (first) { first.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' }); }
		}
	}

	if (form && input) {
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			runSearch(true);
		});
		var timer;
		input.addEventListener('input', function () {
			clearTimeout(timer);
			timer = setTimeout(function () { runSearch(false); }, 120);
		});
		var start = new URLSearchParams(location.search).get('faq');
		if (start) { input.value = start; runSearch(true); }
	}

	/* ---- Topic highlighting --------------------------------------------- */
	var links = Array.prototype.slice.call(root.querySelectorAll('[data-db-faq-topic]'));
	function activate(id) {
		links.forEach(function (a) {
			var on = a.getAttribute('data-db-faq-topic') === id;
			a.classList.toggle('is-active', on);
			if (on) { a.setAttribute('aria-current', 'true'); } else { a.removeAttribute('aria-current'); }
		});
	}
	links.forEach(function (a) {
		a.addEventListener('click', function () { activate(a.getAttribute('data-db-faq-topic')); });
	});
	if ('IntersectionObserver' in window && sections.length) {
		var visible = {};
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (en) { visible[en.target.id] = en.isIntersecting; });
			for (var i = 0; i < sections.length; i++) {
				if (visible[sections[i].id] && !sections[i].hidden) { activate(sections[i].id); return; }
			}
		}, { rootMargin: '-110px 0px -55% 0px', threshold: 0 });
		sections.forEach(function (s) { io.observe(s); });
	}
})();
