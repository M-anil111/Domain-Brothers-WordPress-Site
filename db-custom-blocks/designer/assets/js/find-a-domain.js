/*!
 * Domain Brothers – Find a Domain page (/find-a-domain/) presentation layer.
 *
 * Adds the decorative parts of the new design and nothing else:
 *   - the 01/02/03 steps strip under the hero,
 *   - the "Let's find your fit." intro above the quiz,
 *   - an actions row around the existing submit button with a
 *     "Reset answers" link (a plain link to the page without answers),
 *   - the "Prefer a conversation?" column in the broker section,
 *   - the "Want to explore on your own?" link to /all-domains/.
 * Form fields, names, values, hidden inputs, the nonce, the Turnstile
 * container and both submit buttons are left untouched; the broker heading
 * and paragraph are only moved within #db-broker. Idempotent.
 */
(function () {
	'use strict';

	var FLAG = 'dbrFd';

	function el(tag, className, text) {
		var node = document.createElement(tag);
		if (className) { node.className = className; }
		if (typeof text === 'string') { node.textContent = text; }
		return node;
	}

	function stepsStrip() {
		var list = el('ol', 'dbr-fd-steps');
		list.setAttribute('aria-label', 'How it works');
		[
			['01', 'Tell us about your business'],
			['02', 'Explore your shortlist'],
			['03', 'Talk to a broker']
		].forEach(function (step) {
			var li = el('li');
			var num = el('span', 'dbr-fd-num', step[0]);
			num.setAttribute('aria-hidden', 'true');
			li.appendChild(num);
			li.appendChild(el('span', 'dbr-fd-label', step[1]));
			list.appendChild(li);
		});
		return list;
	}

	function quizIntro() {
		var box = el('div', 'dbr-fd-intro');
		box.appendChild(el('h2', '', 'Let’s find your fit.'));
		box.appendChild(el('p', '', 'Choose one answer in each section. Not sure? That’s fine too.'));
		return box;
	}

	function enhanceQuiz(form) {
		var submit = form.querySelector('button[type="submit"], .db-sales-btn');
		if (!submit || form.querySelector('.dbr-fd-actions')) { return; }

		var actions = el('div', 'dbr-fd-actions');
		submit.parentNode.insertBefore(actions, submit);
		actions.appendChild(submit); // still inside the form, still type="submit"

		var reset = el('a', 'dbr-fd-reset', 'Reset answers');
		reset.href = window.location.pathname + '#db-finder';
		actions.appendChild(reset);
	}

	function enhanceBroker(broker) {
		if (broker.querySelector('.dbr-fd-broker-intro')) { return; }
		var form = broker.querySelector(':scope > form');
		if (!form) { return; }

		var intro = el('div', 'dbr-fd-broker-intro');
		intro.appendChild(el('h2', '', 'Prefer a conversation?'));

		var text = broker.querySelector(':scope > p');
		if (text) { intro.appendChild(text); }

		var perks = el('ul', 'dbr-fd-perks');
		['Personal guidance', 'Help comparing names', 'Clear next steps'].forEach(function (label) {
			perks.appendChild(el('li', '', label));
		});
		intro.appendChild(perks);

		var art = el('div', 'dbr-fd-headset');
		art.setAttribute('aria-hidden', 'true');
		intro.appendChild(art);

		broker.insertBefore(intro, broker.firstChild);

		// Existing "Ask a broker to call you" heading becomes the form card title.
		var heading = broker.querySelector(':scope > h2');
		if (heading) { form.insertBefore(heading, form.firstChild); }
	}

	function exploreCta() {
		var box = el('section', 'dbr-fd-cta');
		box.setAttribute('aria-label', 'Browse all domains');
		box.appendChild(el('h2', '', 'Want to explore on your own?'));
		var link = el('a', 'dbr-fd-cta-btn', 'Browse all domains');
		link.href = '/all-domains/';
		box.appendChild(link);
		return box;
	}

	function init() {
		var main = document.querySelector('#main.db-sales-page');
		if (!main || main.dataset[FLAG] === '1') { return; }
		main.dataset[FLAG] = '1';

		var hero = main.querySelector('.dbs2-hero');
		if (hero) { hero.insertAdjacentElement('afterend', stepsStrip()); }

		var quiz = main.querySelector('#db-finder');
		if (quiz) {
			quiz.parentNode.insertBefore(quizIntro(), quiz);
			enhanceQuiz(quiz);
		}

		var broker = main.querySelector('#db-broker');
		if (broker) { enhanceBroker(broker); }

		main.appendChild(exploreCta());
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init, { once: true });
	} else {
		init();
	}
})();
