/**
 * Responsive + foldable layout scan.
 *
 * Loads the main page types at 13 viewport sizes (phones, a Galaxy Z Fold
 * cover and unfolded, Pixel Fold, a half-folded Z Flip, Surface Duo single
 * and spanned, tablets, laptops, desktop) and reports, per page and size:
 *   - horizontal overflow of the document;
 *   - visible elements that stick out past the right edge and are not
 *     inside a scroller (overflow-x auto/scroll/hidden/clip);
 *   - interactive elements under 24x24 CSS px (WCAG 2.5.8 AA minimum);
 *   - share of text rendered under 12px.
 *
 *   BETA_USER=... BETA_PASS=... NODE_PATH=$(npm root -g) node tests/responsive/run-responsive.js [outDir] [--shots]
 *
 * Staging is behind Basic Auth; set QA_BASE to scan another host. Sequential
 * on purpose: the shared host answers 508 under parallel load.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const USER = process.env.BETA_USER;
const PASS = process.env.BETA_PASS;
const OUT = process.argv[2] && !process.argv[2].startsWith('--') ? process.argv[2] : path.join(process.cwd(), 'responsive-report');
const SHOTS = process.argv.includes('--shots');
const CREDS = USER ? { httpCredentials: { username: USER, password: PASS } } : {};
fs.mkdirSync(OUT, { recursive: true });

const VIEWPORTS = [
	['phone-small-360', 360, 800, true],
	['fold-cover-344', 344, 882, true],
	['phone-390', 390, 844, true],
	['phone-large-430', 430, 932, true],
	['phone-landscape', 844, 390, true],
	['flip-half-412x400', 412, 400, true],
	['duo-single-540', 540, 720, true],
	['fold-open-673', 673, 841, true],
	['pixel-fold-841', 841, 701, true],
	['tablet-768', 768, 1024, true],
	['duo-spanned-1114', 1114, 720, true],
	['laptop-1280', 1280, 800, false],
	['desktop-1920', 1920, 1080, false],
];

const PAGES = [
	['home', '/'],
	['domain', '/domains/fatality-ca/'],
	['all-domains', '/all-domains/'],
	['category', '/domain-category/business/'],
	['news', '/news/'],
	['post', '/centr-tld-market-report-2024/'],
	['services', '/our-services/'],
	['about', '/about-us/'],
	['faqs', '/faqs/'],
	['contact', '/contact-us/'],
	['offer', '/offer/'],
	['sold', '/sold/'],
	['plan', '/payment-plan-setup/?d=ZmF0YWxpdHkuY2E%3D&p=MjUwMA%3D%3D'],
];

const MEASURE = () => {
	const w = document.documentElement.clientWidth;
	const vis = (el) => {
		const r = el.getBoundingClientRect();
		const cs = getComputedStyle(el);
		return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none' && cs.opacity !== '0';
	};
	const sel = (el) => {
		let s = el.tagName.toLowerCase();
		if (el.id) { return s + '#' + el.id; }
		const c = [...el.classList].slice(0, 2).join('.');
		return c ? s + '.' + c : s;
	};
	const contained = (el) => {
		for (let p = el.parentElement; p && p !== document.body; p = p.parentElement) {
			const cs = getComputedStyle(p);
			if (/(auto|scroll|hidden|clip)/.test(cs.overflowX) && p.getBoundingClientRect().right <= w + 1) { return true; }
			if (cs.position === 'fixed' && /db-exit|modal/.test(p.className)) { return true; }
		}
		return false;
	};
	const over = [];
	for (const el of document.querySelectorAll('body *')) {
		if (!vis(el) || el.closest('script,style,noscript,.screen-reader-text,[hidden]')) { continue; }
		const r = el.getBoundingClientRect();
		if (r.right > w + 1 && r.left < w && !contained(el)) {
			if (!over.some((o) => o.el.contains(el))) { over.push({ el, s: sel(el), right: Math.round(r.right) }); }
		}
	}
	const small = [];
	for (const el of document.querySelectorAll('a,button,input:not([type=hidden]),select,textarea,[role=button]')) {
		if (!vis(el) || el.closest('.screen-reader-text,[hidden]')) { continue; }
		const r = el.getBoundingClientRect();
		const inline = el.tagName === 'A' && getComputedStyle(el).display === 'inline';
		if (!inline && Math.min(r.width, r.height) < 24) { small.push(sel(el) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height)); }
	}
	let total = 0, tiny = 0;
	const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
	for (let n; (n = walker.nextNode());) {
		const t = n.textContent.trim();
		const el = n.parentElement;
		if (!t || !el || el.closest('script,style,noscript,.screen-reader-text') || !vis(el)) { continue; }
		total += t.length;
		if (parseFloat(getComputedStyle(el).fontSize) < 12) { tiny += t.length; }
	}
	return {
		w, scrollW: document.documentElement.scrollWidth,
		over: over.slice(0, 6).map((o) => o.s + ' →' + o.right),
		overN: over.length, small: small.slice(0, 6), smallN: small.length, tinyPct: total ? Math.round((tiny / total) * 1000) / 10 : 0,
	};
};

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const results = [];
	for (const [vname, w, h, touch] of VIEWPORTS) {
		const ctx = await browser.newContext({ ...CREDS, viewport: { width: w, height: h }, isMobile: touch, hasTouch: touch });
		const page = await ctx.newPage();
		for (const [pname, url] of PAGES) {
			let m;
			try {
				await page.goto(BASE + url, { waitUntil: 'load', timeout: 60000 });
				await page.waitForTimeout(900);
				m = await page.evaluate(MEASURE);
				if (SHOTS) { await page.screenshot({ path: path.join(OUT, `${pname}__${vname}.png`) }); }
			} catch (e) {
				m = { error: String(e.message).slice(0, 120) };
			}
			results.push({ viewport: vname, page: pname, ...m });
			const flags = m.error ? 'ERROR ' + m.error : [
				m.scrollW > m.w + 1 ? `OVERFLOW ${m.scrollW}>${m.w}` : '',
				m.overN ? `CUTOFF ${m.overN} (${m.over.slice(0, 2).join('; ')})` : '',
				m.smallN ? `SMALL-TARGETS ${m.smallN}` : '',
				m.tinyPct > 5 ? `TINY-TEXT ${m.tinyPct}%` : '',
			].filter(Boolean).join(' | ');
			console.log(`${vname.padEnd(20)} ${pname.padEnd(12)} ${flags || 'ok'}`);
			await page.waitForTimeout(350);
		}
		await ctx.close();
	}
	await browser.close();
	fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 1));
	const bad = results.filter((r) => r.error || r.scrollW > r.w + 1 || r.overN);
	console.log(`\n${results.length} scans; ${bad.length} with overflow/cut-off/errors; ${results.filter((r) => r.smallN).length} with targets under 24px; ${results.filter((r) => r.tinyPct > 5).length} with >5% tiny text`);
	process.exit(bad.length ? 1 : 0);
})();
