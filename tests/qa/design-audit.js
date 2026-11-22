/**
 * Design audit: checks the 2026-11-22 design changes (DB-06, DB-11, DB-12, DB-14, DB-28, DB-29 and
 * the layout standard) on every page template at nine widths, 320px to 1920px, in Chromium.
 *
 *   NODE_PATH=$(npm root -g) node tests/qa/design-audit.js [outDir]
 *
 * Writes results.json (one row per page and width, with each check's value) and prints a summary.
 * Sequential on purpose: the staging host answers 508 under parallel load.
 *
 * Expected values (from the design brief):
 *  - content container 1320px with 15px gutters: content starts at max(15, (width - 1320) / 2 + 15)
 *  - section padding top and bottom: 80px desktop (992px and up), 60px tablet (768-991px),
 *    45px mobile (under 768px); one gap, not two, where neighbouring sections share a background.
 *    Breakpoints and the section types follow the designer's layout system
 *    (db-custom-blocks/designer/ui-fixes/assets/layout.css): W/C page sections carry the padding,
 *    K cards carry the gap as their margin, components inside a section keep their own spacing.
 *  - .dbx-wrap content 15px from each side on mobile
 *  - no tap-highlight border on touch, visible keyboard focus
 *  - breadcrumb on one line where it fits; current domain name weight 700
 *  - mobile header: logo and menu button do not overlap
 *  - archive: Buy Now is one dark pill, both action buttons the same width, one H1
 *  - availability count equals the live index (128 on 2026-11-22), also on a filtered link
 *  - header search fields labelled, footer links at least 32px tall
 *  - checkout order summary equals the listing price
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
if (/www\.domainbrothers\.com/.test(BASE)) { console.error('Run against staging only.'); process.exit(2); }
const OUT = process.argv[2] || path.join(process.cwd(), 'design-audit-report');
fs.mkdirSync(OUT, { recursive: true });
const WIDTHS = (process.env.WIDTHS || '320,360,375,414,768,1024,1280,1440,1920').split(',').map(Number);
const b64 = (s) => Buffer.from(s).toString('base64');

const PAGES_ALL = [
	['Home', '/'],
	['All domains', '/all-domains/'],
	['All domains, filtered link', '/all-domains/?tld=.ca'],
	['Category archive', '/domain-category/business/'],
	['Domain page', '/domains/ster-ca/'],
	['Sold domain page', '/domains/wingcms-com/'],
	['Checkout', `/buy-now/?d=${encodeURIComponent(b64('ster.ca'))}&p=${encodeURIComponent(b64('10125'))}`],
	['Payment plan chooser', `/payment-plan-setup/?d=${encodeURIComponent(b64('ster.ca'))}&p=${encodeURIComponent(b64('10125'))}`],
	['Make an offer', '/make-an-offer/?d=ster.ca&amt=9000'],
	['Contact', '/contact-us/'],
	['About', '/about-us/'],
	['Services', '/our-services/'],
	['Brokerage service', '/domain-brokerage-services/'],
	['Acquire a domain', '/acquire-premium-domain/'],
	['Sell your domains', '/sell-your-domains/'],
	['FAQs', '/faqs/'],
	['Payment terms', '/payment-terms/'],
	['Blog archive', '/category/domain-guides/'],
	['Blog post', '/how-to-buy-a-domain-name-from-someone/'],
	['News', '/news/'],
	['Domain tools', '/domain-tools/'],
	['Tool page', '/domain-tools/domain-age-checker/'],
	['Glossary', '/domain-glossary/'],
	['Price index', '/domain-price-index/'],
	['Find a domain', '/find-a-domain/'],
	['Deals', '/premium-domain-deals/'],
	['Sold domains', '/sold/'],
	['Sign in', '/my-wishlist/?auth=signin'],
	['Register', '/register/'],
	['404 page', '/no-such-page-qa-audit/'],
];
const PAGES = process.env.ONLY ? PAGES_ALL.filter((p) => process.env.ONLY.split('|').includes(p[0])) : PAGES_ALL;

// Runs inside the page: every measurement for one page at one width.
function measure(arg) {
	const vw = window.innerWidth;
	const tier = vw >= 992 ? 'desktop' : (vw >= 768 ? 'tablet' : 'mobile');
	const wantPad = { desktop: 80, tablet: 60, mobile: 45 }[tier];
	const wantLeft = Math.max(15, (vw - 1320) / 2 + 15);
	const vis = (el) => { if (!el) return false; const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
	const px = (v) => Math.round(parseFloat(v) || 0);
	const out = { vw, tier };

	out.hscroll = document.documentElement.scrollWidth > vw + 1 ? document.documentElement.scrollWidth - vw : 0;
	out.h1 = [...document.querySelectorAll('h1')].filter(vis).length;
	out.h1Hidden = [...document.querySelectorAll('h1')].filter((h) => !vis(h)).map((h) => h.innerText.trim().slice(0, 50));

	// Container: the content box of the main column (main element, else the first wrapper), not
	// the text inside it (headings inside padded banners are inset on purpose).
	const mainEl = [...document.querySelectorAll('main, #main, .site-main')].find(vis);
	const ref = mainEl || [...document.querySelectorAll('.container, .dbx-wrap, [class*="wrap"]')].find(vis);
	if (ref) {
		const r = ref.getBoundingClientRect();
		const s = getComputedStyle(ref);
		let left = r.left + px(s.paddingLeft);
		let right = vw - (r.right - px(s.paddingRight));
		// A full-width main holds its own wrappers: measure the first wrapper inside it instead.
		if (left < 1 && right < 1) {
			const inner = [...ref.querySelectorAll('.container, .dbx-wrap, [class*="-wrap"], [class*="-inner"], [class*="container"]')].find((e) => vis(e) && e.getBoundingClientRect().left + px(getComputedStyle(e).paddingLeft) > 0);
			if (inner) { const ir = inner.getBoundingClientRect(); const is = getComputedStyle(inner); left = ir.left + px(is.paddingLeft); right = vw - (ir.right - px(is.paddingRight)); out.containerRef = String(inner.className).slice(0, 40); }
		}
		out.contentLeft = Math.round(left);
		out.contentRight = Math.round(right);
		out.containerRef = out.containerRef || (ref.id ? '#' + ref.id : ref.tagName.toLowerCase());
	}
	// Headings inset from the column (for information).
	const head = [...document.querySelectorAll('h1')].find(vis);
	out.h1Left = head ? Math.round(head.getBoundingClientRect().left) : null;
	const crumb = [...document.querySelectorAll('[class*="breadcrumb" i], nav[aria-label*="readcrumb" i]')].find(vis);
	out.wantLeft = Math.round(wantLeft);

	// .dbx-wrap gutters on mobile.
	const wraps = [...document.querySelectorAll('.dbx-wrap')].filter(vis);
	out.dbxWrap = wraps.slice(0, 3).map((w) => { const r = w.getBoundingClientRect(); const s = getComputedStyle(w); return Math.round(r.left + px(s.paddingLeft)); });

	// Section padding and gaps, by the designer's section types.
	//  W/C: page-level sections (padding = the tier's value; 0 only where it shares the gap with
	//       a neighbouring section of the same background).
	//  K:   cards on the page (the gap is their margin; inner padding is the card's own).
	// The visible gap between two neighbouring page blocks must be one gap (the tier's value).
	const W_SEL = 'section.db-hp-hero, .dbs > section.dbs-full:not(.dbs-partners), .dbx, .homepage-listing > .row, .db-pulse, .db-rsold-home, .db-aud, .db-how, .pageNewContent, .resp_table.dbr, .db-pr-hero, .db-pr-options, .db-pr-details, .db-pr-quotes, .db-pr-news, .db-news-live, .db-an-own, .pagenav, .dbb, .dbc-main, .dba-story, div.dba-bleed, .dbr-body, .dbs-path, .dbs-plan-sec, .dbs-faq, .dbs-cta-sec, .dbt-team, .dbf-body, .db-faq > .dbf-wrap:not(.dbf-body), .db-team > .dbt-wrap, .dbc-hero, .dba-hero, .dba-path, .dbr-hero, .dbs-hero, .dbs-how, .dbf-hero, .dbt-hero, .db-sold > section:not(.db-sold-band)';
	const K_SEL = '.dbl-head, .dbs2-hero, .dbsv-form, .db-sold > .db-sold-band';
	const bgOf = (c) => (c.backgroundImage !== 'none' ? 'img' : c.backgroundColor);
	// White is the page background, so a white band counts as part of the white page.
	const clear = (c) => c.backgroundImage === 'none' && /rgba\(0, 0, 0, 0\)|transparent|rgb\(255, 255, 255\)/.test(c.backgroundColor);
	const blocks = [...document.querySelectorAll(W_SEL + ', ' + K_SEL)].filter(vis)
		.filter((e) => !e.parentElement.closest(W_SEL + ', ' + K_SEL)); // page level only
	out.sections = [];
	out.gaps = [];
	// A section's own padding only matters where it has its own background (C): there it is the
	// space inside the band. Everywhere else the rule is the visible gap between neighbouring
	// page blocks, which must be one gap (a shared gap counts once), measured on the page.
	for (const e of blocks) {
		const cs = getComputedStyle(e);
		const card = e.matches(K_SEL);
		const row = { cls: String(e.className || e.tagName).slice(0, 30), kind: card ? 'K' : (clear(cs) ? 'W' : 'C'), top: px(cs.paddingTop), bottom: px(cs.paddingBottom), mb: px(cs.marginBottom), bg: bgOf(cs) };
		// A band next to a band of the same colour shares one gap: that edge may be 0.
		const sib = (el, dir) => { let x = el[dir]; while (x && (/^(STYLE|SCRIPT|LINK)$/.test(x.tagName) || !vis(x))) x = x[dir]; return x; };
		const p = sib(e, 'previousElementSibling'); const n = sib(e, 'nextElementSibling');
		const sameAbove = p && bgOf(getComputedStyle(p)) === row.bg; const sameBelow = n && bgOf(getComputedStyle(n)) === row.bg;
		const okEdge = (v, shared) => Math.abs(v - wantPad) <= 2 || (v === 0 && shared);
		row.bad = row.kind === 'C' && !(okEdge(row.top, sameAbove) && okEdge(row.bottom, sameBelow));
		out.sections.push(row);
	}
	// Neighbouring blocks in page order, also across wrappers, when no visible content sits
	// between them (text, images or form fields between two blocks belong to a component).
	const edge = (e) => { const r = e.getBoundingClientRect(); const c = getComputedStyle(e); return clear(c) ? { top: r.top + px(c.paddingTop), bottom: r.bottom - px(c.paddingBottom), clear: true } : { top: r.top, bottom: r.bottom, clear: false }; };
	const contentBetween = (a, b) => {
		// Start right after a's subtree, walk in document order until b.
		let n = a;
		while (n && !n.nextSibling && n !== document.body) n = n.parentNode;
		n = n && n.nextSibling;
		let cur = n; let guard = 0;
		while (cur && guard++ < 5000) {
			if (cur === b) return false;
			if (cur.nodeType === 1 && cur.contains(b)) { cur = cur.firstChild; continue; }
			if (cur.nodeType === 3 && cur.textContent.trim() && cur.parentElement && vis(cur.parentElement) && !/^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE)$/.test(cur.parentElement.tagName)) return true;
			if (cur.nodeType === 1 && /^(IMG|INPUT|SELECT|TEXTAREA|BUTTON|IFRAME|SVG|VIDEO)$/i.test(cur.tagName) && vis(cur)) return true;
			if (cur.nodeType === 1 && !/^(SCRIPT|STYLE|NOSCRIPT|TEMPLATE)$/.test(cur.tagName) && cur.firstChild && vis(cur)) { cur = cur.firstChild; continue; }
			while (cur && !cur.nextSibling) cur = cur.parentNode;
			cur = cur && cur.nextSibling;
		}
		return true;
	};
	for (let i = 1; i < blocks.length; i++) {
		const a = blocks[i - 1]; const b = blocks[i];
		if (a.contains(b) || b.contains(a)) continue;
		if (contentBetween(a, b)) continue;
		// Two bands of the same colour read as one band: their gap is between their contents.
		const sameBand = !a.matches(K_SEL) && !b.matches(K_SEL) && bgOf(getComputedStyle(a)) === bgOf(getComputedStyle(b));
		const ea = sameBand ? Object.assign(edge(a), { clear: true }) : edge(a);
		const eb = sameBand ? Object.assign(edge(b), { clear: true }) : edge(b);
		if (sameBand) { const ca = getComputedStyle(a); const cb = getComputedStyle(b); ea.bottom = a.getBoundingClientRect().bottom - px(ca.paddingBottom); eb.top = b.getBoundingClientRect().top + px(cb.paddingTop); }
		const gap = Math.round(eb.top - ea.bottom);
		const bothColoured = !ea.clear && !eb.clear && !a.matches(K_SEL) && !b.matches(K_SEL);
		const ok = Math.abs(gap - wantPad) <= 2 || (bothColoured && Math.abs(gap) <= 2);
		out.gaps.push({ between: String(a.className).slice(0, 24) + ' / ' + String(b.className).slice(0, 24), gap, ok });
	}
	out.wantPad = wantPad;

	// Tap highlight on links and buttons.
	const a = [...document.querySelectorAll('main a, header a')].find(vis);
	out.tapHighlight = a ? getComputedStyle(a).webkitTapHighlightColor : '';

	// Breadcrumb: one line and the current item's weight.
	if (vis(crumb)) {
		const items = [...crumb.querySelectorAll('li, a, span')].filter(vis);
		// Items on one line overlap vertically; count rows by grouping items whose boxes overlap.
		const rowsY = [];
		items.map((i) => i.getBoundingClientRect()).sort((a, b2) => a.top - b2.top).forEach((r) => { const row = rowsY.find((y) => r.top < y.bottom - 2 && r.bottom > y.top + 2); if (row) { row.top = Math.min(row.top, r.top); row.bottom = Math.max(row.bottom, r.bottom); } else { rowsY.push({ top: r.top, bottom: r.bottom }); } });
		out.crumbLines = rowsY.length;
		const last = crumb.querySelector('[aria-current], li:last-child, span:last-child');
		out.crumbLastWeight = last ? Number(getComputedStyle(last).fontWeight) : null;
		out.crumbText = crumb.innerText.replace(/\s+/g, ' ').trim().slice(0, 60);
	}

	// Mobile header: logo against the menu button.
	const logo = [...document.querySelectorAll('.db-mnav-logo img, .db-mnav-logo, header .custom-logo, .db-hp-logo img')].find(vis);
	const burger = document.getElementById('db-mnav-toggle');
	if (vis(logo) && vis(burger)) {
		const l = logo.getBoundingClientRect(); const m = burger.getBoundingClientRect();
		out.logoBurgerOverlap = !(l.right <= m.left || m.right <= l.left || l.bottom <= m.top || m.bottom <= l.top);
		out.logoBurgerGap = Math.round(m.left - l.right);
	}

	// Archive cards: Buy Now pill and action widths.
	const buy = [...document.querySelectorAll('.db-act-buy')].find(vis);
	if (buy) {
		const pill = [buy, buy.querySelector('.badge')].filter(Boolean).sort((a, b2) => b2.getBoundingClientRect().width * b2.getBoundingClientRect().height - a.getBoundingClientRect().width * a.getBoundingClientRect().height)[0];
		const s = getComputedStyle(pill); const r = pill.getBoundingClientRect();
		const rgba = (s.backgroundColor.match(/[\d.]+/g) || [255, 255, 255, 1]).map(Number);
		const lum = (0.2126 * rgba[0] + 0.7152 * rgba[1] + 0.0722 * rgba[2]) / 255;
		// A transparent background is not dark.
		out.buyDark = (rgba.length < 4 || rgba[3] > 0.5) && lum < 0.3;
		out.buyPill = px(s.borderTopLeftRadius) >= r.height / 2 - 2;
		const offer = [...document.querySelectorAll('.db-act-offer')].find(vis);
		out.actionWidths = [buy, offer].filter(Boolean).map((a) => Math.round(a.getBoundingClientRect().width));
		out.nestedPill = !!buy.querySelector('.badge .badge');
	}

	// Availability count.
	// The count is drawn by script after load: read it from the rendered text.
	const cm = (document.querySelector('main') || document.body).innerText.match(/(\d[\d,]*)\s+(?:available\s+|premium\s+)*(?:domains?|names?)\b[^\n]{0,40}/i);
	out.countText = cm ? cm[0].trim().slice(0, 80) : '';
	out.countNum = cm ? Number(cm[1].replace(/,/g, '')) : null;
	const fm = (document.querySelector('main') || document.body).innerText.match(/of\s+(\d[\d,]*)\s+matching/i);
	out.filteredNum = fm ? Number(fm[1].replace(/,/g, '')) : null;
	out.cardsShown = [...document.querySelectorAll('.db-domain-card, table tbody tr')].filter(vis).length;

	// Accessibility: header search labels and footer link heights.
	const searches = [...document.querySelectorAll('header input[type=search], .db-hd input[type=search], #db-ms-q, #db-hp-q')];
	out.searchUnlabelled = searches.filter((i) => !(i.getAttribute('aria-label') || (i.id && document.querySelector('label[for="' + i.id + '"]')) || i.closest('label'))).length;
	const ft = [...document.querySelectorAll('footer a, #colophon a')].filter(vis);
	out.footerMinTap = ft.length ? Math.min(...ft.map((x) => Math.round(x.getBoundingClientRect().height))) : null;

	// Checkout summary amount.
	const sum = [...document.querySelectorAll('[class*="summary" i], [id*="summary" i], aside')].find((e) => vis(e) && /\$[\d,]+\.\d\d/.test(e.innerText)) || document.body;
	out.summaryText = (sum.innerText.match(/\$[\d,]+\.\d\d/) || [''])[0];
	return out;
}

(async () => {
	const browser = await chromium.launch();
	const rows = [];
	// Live count of names for sale, from the same index the page uses.
	const idx = await (await fetch(`${BASE}/wp-json/db/v1/domain-index?x=${Date.now()}`)).json().catch(() => null);
	const liveCount = Array.isArray(idx) ? idx.length : (idx && Array.isArray(idx.rows) ? idx.rows.length : null);
	for (const [name, url] of PAGES) {
		for (const w of WIDTHS) {
			const ctx = await browser.newContext({ viewport: { width: w, height: 900 }, hasTouch: w < 1024, isMobile: w < 768 });
			const page = await ctx.newPage();
			const errs = [];
			page.on('pageerror', (e) => errs.push(e.message.slice(0, 120)));
			let status = 0;
			for (let t = 0; t < 3; t++) {
				// CACHEBUST=1 adds a throwaway query string so cached copies of a just-deployed page are skipped.
				const target = BASE + url + (process.env.CACHEBUST ? (url.includes('?') ? '&' : '?') + 'qa_nc=' + Date.now() : '');
				const r = await page.goto(target, { waitUntil: 'load', timeout: 60000 }).catch(() => null);
				status = r ? r.status() : 0;
				if (status !== 508 && status !== 503) break;
				await page.waitForTimeout(8000 * (t + 1));
			}
			await page.waitForTimeout(1200);
			const m = await page.evaluate(measure).catch((e) => ({ error: e.message }));
			// Keyboard focus is visible: tab to the first link and read its outline or shadow.
			await page.keyboard.press('Tab').catch(() => {});
			m.focusVisible = await page.evaluate(() => { const a = document.activeElement; if (!a || a === document.body) return null; const s = getComputedStyle(a); return (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) || (s.boxShadow && s.boxShadow !== 'none'); }).catch(() => null);
			if (w === 375 || w === 1440) await page.screenshot({ path: path.join(OUT, `${name.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}-${w}.png`), fullPage: false }).catch(() => {});
			rows.push({ page: name, url, width: w, status, errors: errs, liveCount, ...m });
			process.stdout.write('.');
			await ctx.close();
		}
		process.stdout.write(` ${name}\n`);
	}
	fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(rows, null, 1));
	console.log(`\n${rows.length} page-width combinations written to ${path.join(OUT, 'results.json')}`);
	await browser.close();
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
