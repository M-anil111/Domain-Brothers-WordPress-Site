#!/usr/bin/env node
/**
 * Domain Brothers — automated site QA suite (staging).
 *
 * Runs real-browser and HTTP checks against beta.domainbrothers.com and writes
 * a JSON + Markdown report. Sequential on purpose: the shared Hostinger plan
 * answers 508 "Resource Limit Reached" under parallel load.
 *
 * Usage:
 *   BETA_USER=... BETA_PASS=... NODE_PATH=$(npm root -g) node tests/qa/run-qa.js [outDir]
 *
 * Credentials come only from the environment (the staging site sits behind
 * HTTP Basic Auth); nothing secret is stored in this file.
 *
 * Every check has an id (e.g. FN-03) so failures in the report map back here.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const USER = process.env.BETA_USER;
const PASS = process.env.BETA_PASS;
const OUT = process.argv[2] || path.join(process.cwd(), 'qa-report');
if (!USER || !PASS) {
	console.error('Set BETA_USER and BETA_PASS (staging Basic Auth).');
	process.exit(2);
}
fs.mkdirSync(OUT, { recursive: true });

const b64 = (s) => Buffer.from(String(s)).toString('base64');
const AUTH = 'Basic ' + b64(USER + ':' + PASS);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const results = [];
function record(id, area, name, pass, detail) {
	results.push({ id, area, name, pass: !!pass, detail: detail === undefined ? '' : String(detail) });
	console.log(`${pass ? 'PASS' : 'FAIL'} ${id} ${name}${detail ? ' — ' + String(detail).slice(0, 160) : ''}`);
}

async function http(p, opts = {}) {
	const t0 = Date.now();
	const res = await fetch(BASE + p, { redirect: opts.redirect || 'manual', headers: { Authorization: AUTH, 'User-Agent': 'DB-QA/1.0', ...(opts.headers || {}) } });
	const body = opts.body === false ? '' : await res.text();
	return { status: res.status, headers: res.headers, body, ms: Date.now() - t0 };
}

// Representative page per template.
const PAGES = [
	['home', '/'],
	['domain', '/domains/fatality-ca/'],
	['domain-priced', '/domains/aethereum-ca/'],
	['all-domains', '/all-domains/'],
	['category', '/domain-category/business/'],
	['news', '/news/'],
	['post', '/centr-tld-market-report-2024/'],
	['services', '/our-services/'],
	['service', '/digital-marketing/'],
	['about', '/about-us/'],
	['faqs', '/faqs/'],
	['contact', '/contact-us/'],
	['offer', '/offer/'],
	['privacy', '/privacy-policy/'],
	['sold', '/sold/'],
	['epp', '/epp-guidelines/'],
];

(async () => {
	/* ─── HTTP-level checks ─────────────────────────────────────────────── */
	for (const [name, p] of PAGES) {
		const r = await http(p);
		const h = r.body;
		record(`HT-${name}-status`, 'HTTP', `${p} returns 200`, r.status === 200, r.status);
		record(`HT-${name}-php`, 'HTTP', `${p} has no PHP error text`, !/(Fatal error|Warning:|Notice:|Deprecated:|Parse error)/.test(h));
		const title = (h.match(/<title>([^<]*)<\/title>/) || [])[1] || '';
		record(`SEO-${name}-title`, 'SEO', `${p} title 25–65 chars`, title.length >= 25 && title.length <= 65, `${title.length}: ${title}`);
		const desc = (h.match(/<meta name="description" content="([^"]*)"/) || [])[1] || '';
		record(`SEO-${name}-desc`, 'SEO', `${p} meta description 70–160 chars`, desc.length >= 70 && desc.length <= 160, desc.length);
		const canon = (h.match(/<link rel="canonical" href="([^"]+)"/) || [])[1] || '';
		record(`SEO-${name}-canonical`, 'SEO', `${p} canonical is self`, canon === BASE + p, canon);
		const live = h.replace(/<!--[\s\S]*?-->/g, '');
		const h1 = (live.match(/<h1[\s>]/g) || []).length;
		record(`SEO-${name}-h1`, 'SEO', `${p} has exactly one <h1>`, h1 === 1, h1);
		const ld = [...h.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)];
		let ldOk = ld.length > 0;
		const types = [];
		for (const m of ld) { try { const j = JSON.parse(m[1]); types.push(j['@type']); } catch (e) { ldOk = false; types.push('INVALID'); } }
		record(`SEO-${name}-jsonld`, 'SEO', `${p} JSON-LD parses`, ldOk, types.join(','));
		record(`SEO-${name}-noindex`, 'SEO', `${p} staging is noindex`, /noindex/.test(r.headers.get('x-robots-tag') || '') && /noindex/.test(h));
		const imgs = h.match(/<img\b[^>]*>/gi) || [];
		const noAlt = imgs.filter((t) => !/\salt=/.test(t)).length;
		record(`A11Y-${name}-alt`, 'A11y', `${p} every <img> has alt`, noAlt === 0, `${noAlt}/${imgs.length} missing`);
		const vpTag = (live.match(/<meta[^>]+name=["']viewport["'][^>]*>/gi) || []).join(' ');
		record(`A11Y-${name}-zoom`, 'A11y', `${p} viewport allows pinch-zoom`, vpTag !== '' && !/user-scalable\s*=\s*(no|0)|maximum-scale\s*=\s*1(\.0)?\b/i.test(vpTag), vpTag.slice(0, 120));
		record(`A11Y-${name}-paste`, 'A11y', `${p} no field blocks paste`, !/onpaste\s*=\s*"return false|\.bind\("cut copy paste"/i.test(live));
		const titles = (live.match(/<title[\s>]/g) || []).length;
		record(`SEO-${name}-one-title`, 'SEO', `${p} has exactly one <title>`, titles === 1, titles);
		const unsafe = (live.match(/<a\b[^>]*>/gi) || []).filter((t) => /target=["']_blank/i.test(t) && !/\srel=["'][^"']*noopener/i.test(t));
		record(`SEC-${name}-blank-rel`, 'Security', `${p} target=_blank links carry rel=noopener`, unsafe.length === 0, `${unsafe.length} unsafe ${unsafe.slice(0, 2).join(' ')}`);
		const seen = {}, dup = new Set();
		for (const m of live.replace(/<script\b[\s\S]*?<\/script>/gi, '').matchAll(/\sid="([^"]+)"/g)) { if (seen[m[1]]) dup.add(m[1]); seen[m[1]] = 1; }
		record(`A11Y-${name}-unique-ids`, 'A11y', `${p} has no duplicate element ids`, dup.size === 0, [...dup].slice(0, 5).join(','));
		record(`PERF-${name}-ttfb`, 'Perf', `${p} response < 2.5s`, r.ms < 2500, r.ms + 'ms');
		await sleep(700);
	}

	// Security headers (home)
	{
		const r = await http('/');
		for (const hd of ['strict-transport-security', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'content-security-policy']) {
			record(`SEC-${hd}`, 'Security', `header ${hd} present`, !!r.headers.get(hd), r.headers.get(hd) || 'missing');
		}
		record('SEC-x-powered-by', 'Security', 'X-Powered-By not exposed', !r.headers.get('x-powered-by'), r.headers.get('x-powered-by') || '');
		const users = await http('/wp-json/wp/v2/users');
		record('SEC-user-enum', 'Security', 'REST user enumeration blocked', users.status !== 200 || !/"slug"/.test(users.body), users.status);
		const xr = await fetch(BASE + '/xmlrpc.php', { method: 'POST', headers: { Authorization: AUTH, 'Content-Type': 'text/xml' }, body: '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>' });
		const xrBody = await xr.text();
		record('SEC-xmlrpc', 'Security', 'XML-RPC refuses system.listMethods', !/<methodResponse>/.test(xrBody), xr.status);
		const author = await http('/?author=1', { body: false });
		record('SEC-author-enum', 'Security', '?author=1 does not reveal a username', !/\/author\/[a-z0-9-]+/i.test(author.headers.get('location') || ''), author.headers.get('location') || author.status);
	}

	/* ─── Functional checks ─────────────────────────────────────────────── */
	const D = b64('fatality.ca'), P = b64('2500');
	{
		const r = await http('/checkout/');
		record('FN-01', 'Checkout', 'bare /checkout/ redirects to /all-domains/', r.status === 302 && /\/all-domains\/$/.test(r.headers.get('location') || ''), r.headers.get('location'));
		const r2 = await http(`/checkout/?d=${encodeURIComponent(D)}&p=${encodeURIComponent(P)}`);
		record('FN-02', 'Checkout', 'legacy /checkout/ with d+p goes to Stripe /buy-now/', r2.status === 302 && /\/buy-now\/\?d=/.test(r2.headers.get('location') || ''), r2.headers.get('location'));
		const r3 = await http('/buy-now/');
		record('FN-03', 'Checkout', 'bare /buy-now/ redirects to /all-domains/', r3.status === 302, r3.status);
		const r4 = await http(`/buy-now/?d=${encodeURIComponent(D)}&p=${encodeURIComponent(P)}`);
		record('FN-04', 'Checkout', '/buy-now/ renders (Stripe form or "being configured")', r4.status === 200 && /(db-stripe-payment-element|being configured)/.test(r4.body), r4.status);
		record('FN-05', 'Checkout', '/buy-now/ is never cached', /no-cache|no-store|private/.test(r4.headers.get('cache-control') || ''), r4.headers.get('cache-control'));
		const r5 = await http(`/buy-now/?d=${encodeURIComponent(b64('not-a-listing.zz'))}&p=${encodeURIComponent(P)}`);
		record('FN-06', 'Checkout', '/buy-now/ refuses a domain that is not a listing', !/db-stripe-payment-element/.test(r5.body) && /could not be verified|Invalid/.test(r5.body), r5.status);
		const r6 = await http('/payment-plan-setup/');
		record('FN-07', 'Checkout', 'bare plan page redirects', r6.status === 302, r6.status);
		const r7 = await http('/thank-you/?type=full&d=fatality.ca&amt=2500');
		record('FN-08', 'Checkout', 'thank-you page renders', r7.status === 200, r7.status);
		const r8 = await http('/payment-successful/');
		record('FN-09', 'Checkout', '/payment-successful/ redirects to thank-you', r8.status === 302, r8.headers.get('location'));
	}
	{
		const r = await http('/domains/fatality-ca/');
		record('FN-10', 'Offer', 'Make-an-Offer form 1878 is on domain pages', /data-wpcf7-id="1878"/.test(r.body));
		record('FN-11', 'Offer', 'offer redirect uses this site, not production', !/www\.domainbrothers\.com\/thank-you/.test(r.body) && /var DB_OFFER_FORM_ID = 1878;/.test(r.body));
		const r2 = await http('/domains/aethereum-ca/');
		const buy = (r2.body.match(/class="db-dd-btn db-dd-btn--primary" href="([^"]+)"/) || [])[1] || '';
		record('FN-12', 'Domain page', 'Buy now button carries this domain + price', buy.includes('/buy-now/') && buy.includes(encodeURIComponent(b64('aethereum.ca'))), buy);
		const words = r2.body.replace(/<script[\s\S]*?<\/script>|<style[\s\S]*?<\/style>/g, '').match(/class="db-dd"[\s\S]*?<\/section>/);
		const wc = words ? words[0].replace(/<[^>]+>/g, ' ').split(/\s+/).filter(Boolean).length : 0;
		record('FN-13', 'Domain page', 'domain details block has 200+ words', wc >= 200, wc);
	}
	{
		const r = await http('/this-page-does-not-exist-qa/');
		record('FN-14', '404', 'unknown URL returns 404', r.status === 404, r.status);
		record('FN-15', '404', '404 page offers search + links', /db-404-helper/.test(r.body));
		const s = await http('/?s=ca');
		record('FN-16', 'Search', 'site search returns 200', s.status === 200, s.status);
		for (const o of ['index', 'domains/index', 'domains/fatality-ca', 'categories/index', 'business/index']) {
			const m = await http(`/okf/${o}.md`);
			record(`FN-OKF-${o}`, 'OKF', `/okf/${o}.md is markdown with frontmatter`, m.status === 200 && /^---\ntype:/.test(m.body) && /text\/markdown/.test(m.headers.get('content-type') || ''), m.status);
		}
		const sm = await http('/wp-sitemap.xml');
		record('FN-17', 'SEO', 'wp-sitemap.xml is valid XML', sm.status === 200 && /<sitemapindex/.test(sm.body), sm.status);
		const un = await http('/?db_update_card=1&d=fatality.ca&t=forged');
		record('FN-18', 'Plans', 'forged "update my card" link is rejected', un.status === 200 && /could not open the card update page/.test(un.body), un.status);
	}

	/* ─── Browser checks ────────────────────────────────────────────────── */
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const perf = [];
	for (const [vp, viewport] of [['desktop', { width: 1366, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
		const ctx = await browser.newContext({ httpCredentials: { username: USER, password: PASS }, viewport, isMobile: vp === 'mobile' });
		for (const [name, p] of PAGES) {
			const page = await ctx.newPage();
			const errors = []; const bad = []; let bytes = 0; let reqs = 0;
			page.on('pageerror', (e) => errors.push(e.message.slice(0, 120)));
			page.on('response', async (res) => {
				reqs++;
				const u = res.url();
				if (u.startsWith(BASE) && res.status() >= 400) bad.push(res.status() + ' ' + u.replace(BASE, ''));
				const len = Number(res.headers()['content-length'] || 0); bytes += len;
			});
			try {
				await page.goto(BASE + p, { waitUntil: 'load', timeout: 60000 });
				await page.waitForTimeout(1200);
				const m = await page.evaluate(() => {
					const nav = performance.getEntriesByType('navigation')[0] || {};
					const lcp = window.__lcp || 0;
					return {
						overflow: document.documentElement.scrollWidth - window.innerWidth,
						domNodes: document.getElementsByTagName('*').length,
						ttfb: Math.round(nav.responseStart || 0),
						domContentLoaded: Math.round(nav.domContentLoadedEventEnd || 0),
						load: Math.round(nav.loadEventEnd || 0),
						height: document.body.scrollHeight,
						tapSmall: [...document.querySelectorAll('a,button')].filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && r.height > 0 && r.height < 24 && getComputedStyle(e).display !== 'inline'; }).length,
					};
				});
				record(`UI-${vp}-${name}-overflow`, 'Layout', `${p} no horizontal scroll (${vp})`, m.overflow <= 1, m.overflow + 'px');
				record(`UI-${vp}-${name}-jserr`, 'JS', `${p} no uncaught JS errors (${vp})`, errors.length === 0, errors.join(' | '));
				record(`UI-${vp}-${name}-404`, 'Assets', `${p} no same-site 4xx/5xx assets (${vp})`, bad.length === 0, bad.slice(0, 4).join(' | '));
				perf.push({ vp, page: name, path: p, ...m, requests: reqs, kbHeaderSized: Math.round(bytes / 1024) });
				await page.screenshot({ path: path.join(OUT, `${name}-${vp}.png`), fullPage: false });
			} catch (e) {
				record(`UI-${vp}-${name}-load`, 'Layout', `${p} loads in browser (${vp})`, false, e.message.slice(0, 120));
			}
			await page.close();
			await sleep(800);
		}
		await ctx.close();
	}

	// Plan chooser: every term updates the summary and schedule correctly.
	{
		const ctx = await browser.newContext({ httpCredentials: { username: USER, password: PASS }, viewport: { width: 1366, height: 900 } });
		const page = await ctx.newPage();
		await page.goto(`${BASE}/payment-plan-setup/?d=${encodeURIComponent(D)}&p=${encodeURIComponent(P)}`, { waitUntil: 'load' });
		await page.waitForTimeout(1000);
		const rates = { 3: 0, 6: 0, 9: 5, 12: 10 };
		const terms = await page.$$eval('.db-plan-term', (els) => els.length);
		record('FN-20', 'Plans', 'plan chooser shows 4 terms', terms === 4, terms);
		for (let i = 0; i < terms; i++) {
			await page.click(`.db-plan-term:nth-child(${i + 1})`);
			await page.waitForTimeout(200);
			const s = await page.evaluate(() => ({
				monthly: document.querySelector('#db-plan-monthly').textContent,
				rows: [...document.querySelectorAll('#db-plan-schedule-body tr td:last-child')].map((t) => Number(t.textContent.replace(/[^0-9.]/g, ''))),
				href: document.querySelector('#db-plan-cta').getAttribute('href'),
			}));
			const m = [3, 6, 9, 12][i];
			const totalC = Math.round(2500 * (100 + rates[m]));
			const sumC = Math.round(s.rows.reduce((a, b) => a + b, 0) * 100);
			record(`FN-21-${m}`, 'Plans', `${m}-month plan: ${m} rows summing to $${(totalC / 100).toFixed(2)}`, s.rows.length === m && sumC === totalC, `rows=${s.rows.length} sum=${sumC / 100} monthly=${s.monthly}`);
			record(`FN-22-${m}`, 'Plans', `${m}-month CTA carries months=${m}`, new RegExp(`months=${m}(&|$)`).test(s.href), s.href);
		}
		await page.screenshot({ path: path.join(OUT, 'plan-desktop.png') });
		await ctx.close();
	}

	// /all-domains/ filter + sort bar works across every listing, and Clear restores the page.
	for (const vp of ['desktop', 'mobile']) {
		const viewport = vp === 'desktop' ? { width: 1366, height: 900 } : { width: 390, height: 844 };
		const ctx = await browser.newContext({ httpCredentials: { username: USER, password: PASS }, viewport, isMobile: vp === 'mobile' });
		const page = await ctx.newPage();
		const errs = [];
		page.on('pageerror', (e) => errs.push(e.message));
		await page.goto(`${BASE}/all-domains/`, { waitUntil: 'load' });
		await page.waitForSelector('.db-dfilter + .db-domain-grid', { timeout: 10000 }).catch(() => {});
		const before = await page.$$eval('.db-dfilter + .db-domain-grid > .db-domain-card', (r) => r.length);
		record(`FN-23-${vp}`, 'Filter', `filter bar present (${vp})`, await page.$('.db-dfilter') !== null);
		await page.selectOption('.db-dfilter select[name="sort"]', 'pa');
		await page.waitForFunction(() => /of \d+ domains/.test(document.querySelector('.db-dfilter-count').textContent), null, { timeout: 15000 }).catch(() => {});
		const total = Number(((await page.textContent('.db-dfilter-count')) || '').replace(/.* of (\d+) domains.*/, '$1')) || 0;
		const prices = await page.$$eval('.db-dfilter + .db-domain-grid .db-domain-card__price, .db-dfilter + .db-domain-grid .db-domain-card__price--offer', (b) => b.map((x) => x.textContent.trim()));
		const nums = prices.filter((t) => /\$/.test(t)).map((t) => Number(t.replace(/[^0-9]/g, '')));
		const firstOffer = prices.indexOf('Open to offers');
		const ascending = nums.every((v, i) => i === 0 || nums[i - 1] <= v) && (firstOffer === -1 || prices.slice(firstOffer).every((t) => t === 'Open to offers'));
		record(`FN-24-${vp}`, 'Filter', `sort by price covers every listing, low to high, offers last (${vp})`, total > before && prices.length === total && ascending, `rows=${prices.length} total=${total} pagerows=${before}`);
		await page.selectOption('.db-dfilter select[name="tld"]', '.ca');
		await page.selectOption('.db-dfilter select[name="max"]', '2500');
		await page.waitForTimeout(400);
		const ca = await page.$$eval('.db-dfilter + .db-domain-grid > .db-domain-card', (rows) => rows.map((r) => ({ n: (r.querySelector('.db-domain-card__name') || {}).textContent || '', p: Number(((r.querySelector('.db-domain-card__price') || {}).textContent || '').replace(/[^0-9]/g, '')) })));
		record(`FN-25-${vp}`, 'Filter', `.ca + max $2,500 returns only matching names (${vp})`, ca.length > 0 && ca.every((r) => /\.ca$/i.test(r.n) && r.p > 0 && r.p <= 2500), `${ca.length} rows`);
		await page.click('.db-dfilter button[type="reset"]');
		await page.waitForTimeout(300);
		const after = await page.$$eval('.db-dfilter + .db-domain-grid > .db-domain-card', (r) => r.length);
		const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
		record(`FN-26-${vp}`, 'Filter', `Clear restores the page, no overflow, no JS errors (${vp})`, after === before && !overflow && errs.length === 0, `rows=${after}/${before} overflow=${overflow} errs=${errs.join('|')}`);
		await page.screenshot({ path: path.join(OUT, `filter-${vp}.png`) });
		await ctx.close();
	}
	await browser.close();

	/* ─── Report ────────────────────────────────────────────────────────── */
	const failed = results.filter((r) => !r.pass);
	fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify({ base: BASE, ran: new Date().toISOString(), results, perf }, null, 1));
	let md = `# QA report — ${BASE}\n\nRan ${new Date().toISOString()}: ${results.length} checks, ${results.length - failed.length} passed, ${failed.length} failed.\n\n## Failures\n\n| ID | Area | Check | Detail |\n|---|---|---|---|\n`;
	for (const f of failed) md += `| ${f.id} | ${f.area} | ${f.name} | ${f.detail.replace(/\|/g, '/').slice(0, 140)} |\n`;
	md += `\n## Performance (browser)\n\n| Viewport | Page | TTFB ms | DOMContentLoaded ms | Load ms | Requests | DOM nodes | Height px |\n|---|---|---|---|---|---|---|---|\n`;
	for (const p of perf) md += `| ${p.vp} | ${p.page} | ${p.ttfb} | ${p.domContentLoaded} | ${p.load} | ${p.requests} | ${p.domNodes} | ${p.height} |\n`;
	fs.writeFileSync(path.join(OUT, 'report.md'), md);
	console.log(`\n${results.length - failed.length}/${results.length} passed. Report: ${path.join(OUT, 'report.md')}`);
	process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
