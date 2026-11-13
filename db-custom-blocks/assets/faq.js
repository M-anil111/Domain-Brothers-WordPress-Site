/* Domain Brothers FAQs page: accordion, topic navigation and question search. */
(function () {
	'use strict';

	var root = document.getElementById('db-faq');
	if (!root) { return; }

	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var items = Array.prototype.slice.call(root.querySelectorAll('.dbf-item'));
	var sections = Array.prototype.slice.call(root.querySelectorAll('.dbf-sec'));
	var topicLinks = Array.prototype.slice.call(root.querySelectorAll('.dbf-topic'));
	var topicList = root.querySelector('.dbf-topics ul');
	var form = root.querySelector('.dbf-search');
	var input = document.getElementById('dbf-q');
	var status = document.getElementById('dbf-status');
	var empty = root.querySelector('.dbf-empty');

	/* ---- Accordion: one answer open at a time ---------------------------- */

	function setOpen(item, open, animate) {
		var btn = item.querySelector('.dbf-q-btn');
		var panel = item.querySelector('.dbf-a');
		if (!btn || !panel) { return; }
		var isOpen = btn.getAttribute('aria-expanded') === 'true';
		if (isOpen === open) { return; }

		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		item.classList.toggle('is-open', open);

		if (!animate || reduceMotion) {
			panel.classList.remove('is-collapsed', 'is-animating');
			panel.hidden = !open;
			return;
		}

		panel.classList.add('is-animating');
		if (open) {
			panel.hidden = false;
			panel.classList.add('is-collapsed');
			void panel.offsetHeight; // start from the collapsed height
			panel.classList.remove('is-collapsed');
		} else {
			panel.classList.add('is-collapsed');
		}
		var done = function (e) {
			if (e && e.target !== panel) { return; }
			panel.removeEventListener('transitionend', done);
			panel.classList.remove('is-animating');
			if (btn.getAttribute('aria-expanded') !== 'true') {
				panel.hidden = true;
				panel.classList.remove('is-collapsed');
			}
		};
		panel.addEventListener('transitionend', done);
		window.setTimeout(done, 400); // in case transitionend never fires
	}

	items.forEach(function (item) {
		var btn = item.querySelector('.dbf-q-btn');
		if (!btn) { return; }
		btn.addEventListener('click', function () {
			var willOpen = btn.getAttribute('aria-expanded') !== 'true';
			if (willOpen) {
				items.forEach(function (other) { if (other !== item) { setOpen(other, false, true); } });
			}
			setOpen(item, willOpen, true);
		});
	});

	/* ---- Topic navigation -------------------------------------------------- */

	function setActiveTopic(id) {
		topicLinks.forEach(function (link) {
			var on = link.getAttribute('href') === '#' + id;
			link.classList.toggle('is-active', on);
			if (on) {
				link.setAttribute('aria-current', 'true');
				// Keep the active chip visible in the sideways-scrolling row (tablet/phone),
				// without scrolling the page itself.
				if (topicList && topicList.scrollWidth > topicList.clientWidth) {
					var li = link.parentNode;
					var left = li.offsetLeft - 16;
					var right = li.offsetLeft + li.offsetWidth + 16 - topicList.clientWidth;
					if (topicList.scrollLeft > left) { topicList.scrollLeft = left; }
					else if (topicList.scrollLeft < right) { topicList.scrollLeft = right; }
				}
			} else {
				link.removeAttribute('aria-current');
			}
		});
	}

	var lockUntil = 0;
	topicLinks.forEach(function (link) {
		link.addEventListener('click', function (e) {
			var id = (link.getAttribute('href') || '').slice(1);
			var target = id ? document.getElementById(id) : null;
			if (!target || target.hidden) { return; }
			e.preventDefault();
			setActiveTopic(id);
			lockUntil = Date.now() + 900; // ignore the scroll spy while the smooth scroll runs
			target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
			if (history.replaceState) { history.replaceState(null, '', '#' + id); }
			var heading = target.querySelector('.dbf-sec-h');
			if (heading) {
				heading.setAttribute('tabindex', '-1');
				heading.focus({ preventScroll: true });
			}
		});
	});

	if ('IntersectionObserver' in window && sections.length) {
		var visible = {};
		var spy = new IntersectionObserver(function (entries) {
			entries.forEach(function (en) { visible[en.target.id] = en.isIntersecting; });
			if (Date.now() < lockUntil) { return; }
			for (var i = 0; i < sections.length; i++) {
				if (visible[sections[i].id] && !sections[i].hidden) { setActiveTopic(sections[i].id); return; }
			}
		}, { rootMargin: '-120px 0px -55% 0px', threshold: 0 });
		sections.forEach(function (s) { spy.observe(s); });
	}

	/* ---- Search ------------------------------------------------------------ */

	items.forEach(function (item) {
		var q = item.querySelector('.dbf-q-text');
		var a = item.querySelector('.dbf-a');
		item._q = q ? q.textContent : '';
		item._text = ((q ? q.textContent : '') + ' ' + (a ? a.textContent : '')).toLowerCase();
	});
	var initiallyOpen = items.filter(function (it) { return it.classList.contains('is-open'); })[0] || null;

	function escapeRe(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

	function highlight(item, words) {
		var q = item.querySelector('.dbf-q-text');
		if (!q) { return; }
		q.textContent = item._q;
		if (!words.length) { return; }
		var re = new RegExp('(' + words.map(escapeRe).join('|') + ')', 'gi');
		var parts = item._q.split(re);
		q.textContent = '';
		parts.forEach(function (part, i) {
			if (!part) { return; }
			if (i % 2 === 1) {
				var m = document.createElement('mark');
				m.className = 'dbf-hit';
				m.textContent = part;
				q.appendChild(m);
			} else {
				q.appendChild(document.createTextNode(part));
			}
		});
	}

	function runSearch(scroll) {
		var raw = (input.value || '').trim();
		var words = raw.toLowerCase().split(/\s+/).filter(function (w) { return w.length > 0; });
		var shown = 0;
		var firstMatch = null;

		items.forEach(function (item) {
			var match = words.every(function (w) { return item._text.indexOf(w) !== -1; });
			item.hidden = !match;
			highlight(item, match ? words : []);
			if (match) {
				shown++;
				if (!firstMatch) { firstMatch = item; }
			}
		});
		sections.forEach(function (sec) {
			sec.hidden = !sec.querySelector('.dbf-item:not([hidden])');
		});
		topicLinks.forEach(function (link) {
			var sec = document.getElementById((link.getAttribute('href') || '').slice(1));
			link.parentNode.hidden = !!(sec && sec.hidden);
		});
		empty.hidden = shown > 0;

		if (!words.length) {
			status.textContent = '';
			items.forEach(function (item) { setOpen(item, item === initiallyOpen, false); });
			return;
		}
		status.textContent = shown === 1
			? '1 question matches “' + raw + '”.'
			: shown + ' questions match “' + raw + '”.';

		items.forEach(function (item) { setOpen(item, item === firstMatch, false); });
		if (scroll) {
			var main = root.querySelector('.dbf-main');
			if (main) { main.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' }); }
		}
	}

	if (form && input) {
		var timer = 0;
		input.addEventListener('input', function () {
			window.clearTimeout(timer);
			timer = window.setTimeout(function () { runSearch(false); }, 150);
		});
		input.addEventListener('search', function () { runSearch(false); }); // the clear (x) button
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			window.clearTimeout(timer);
			runSearch(true);
		});
		// /faqs/?faq=transfer opens the page already filtered.
		var params = new URLSearchParams(window.location.search);
		if (params.get('faq')) {
			input.value = params.get('faq');
			runSearch(false);
		}
	}

	/* ---- Deep links: #domain-basics opens that topic ----------------------- */
	if (window.location.hash) {
		var target = document.getElementById(window.location.hash.slice(1));
		if (target && target.classList.contains('dbf-sec')) { setActiveTopic(target.id); }
	}
}());
