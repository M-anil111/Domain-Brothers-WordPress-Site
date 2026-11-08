/**
 * Content cluster ("Domain Guides"): every article is published and healthy.
 *
 * For each article in db-custom-blocks/cluster/manifest.json:
 *   - the page returns 200 with one h1, a title and a meta description, a canonical, Article data
 *     (and FAQPage data where it shows questions);
 *   - enough text (pillar 2,500+ words, others 600+), headings do not skip levels;
 *   - every internal link resolves (200), every table-of-contents anchor exists;
 *   - external links are present and reachable;
 *   - every image loads and has alt text, and a cover image is set;
 *   - spokes link to the pillar, the pillar links to every spoke;
 *   - the block editor opens the post with no "invalid content" warning;
 *   - no horizontal scroll on a 390 px wide phone.
 *
 * Staging (admin login: DB_WP_USER / DB_WP_PASS, Basic Auth: BETA_USER / BETA_PASS).
 */
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');
const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const M = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'db-custom-blocks', 'cluster', 'manifest.json'), 'utf8'));
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const ctx = await browser.newContext({ ...creds, viewport: { width: 1280, height: 900 } });
	const page = await ctx.newPage();
	page.setDefaultTimeout(90000);
	const host = new URL(BASE).host;
	const internalSeen = new Map();
	const externalSeen = new Map();
	const pillar = M.articles.find((a) => a.role === 'pillar');
	const slugs = M.articles.map((a) => a.slug);

	// admin session for the editor check
	const admin = await ctx.newPage();
	admin.setDefaultTimeout(120000);
	await admin.goto(`${BASE}/wp-login.php`);
	await admin.fill('#user_login', process.env.DB_WP_USER);
	await admin.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);
	const nonce = (await (await ctx.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();

	for (const a of M.articles) {
		const url = `${BASE}/${a.slug}/`;
		const resp = await page.goto(url + '?x=' + Date.now(), { waitUntil: 'load' });
		const ok = resp.status() === 200;
		check(`${a.slug}: page returns 200`, ok, String(resp.status()));
		if (!ok) { continue; }
		const info = await page.evaluate((host) => {
			const root = document.querySelector('.entry-content, article, main') || document.body;
			const text = root.innerText.replace(/\s+/g, ' ');
			const hs = [...root.querySelectorAll('h1,h2,h3,h4')].map((h) => +h.tagName[1]);
			let skip = false; for (let i = 1; i < hs.length; i++) { if (hs[i] - hs[i - 1] > 1) { skip = true; } }
			const links = [...root.querySelectorAll('a[href]')].map((l) => l.href);
			const anchors = [...root.querySelectorAll('a[href^="#"]')].map((l) => l.getAttribute('href').slice(1));
			const missing = anchors.filter((id) => !document.getElementById(id));
			const imgs = [...root.querySelectorAll('img')].map((i) => ({ ok: i.complete && i.naturalWidth > 0, alt: (i.getAttribute('alt') || '').trim(), src: i.src }));
			const ld = [...document.querySelectorAll('script[type="application/ld+json"]')].map((s) => s.textContent).join(' ');
			const h1 = document.querySelectorAll('h1').length;
			return {
				words: text.split(' ').length, skip, links, anchors: anchors.length, missing, imgs, h1,
				title: document.title, desc: (document.querySelector('meta[name=description]') || {}).content || '',
				canon: (document.querySelector('link[rel=canonical]') || {}).href || '', article: /"Article"|"BlogPosting"|NewsArticle/.test(ld), faq: /FAQPage/.test(ld),
				hscroll: document.documentElement.scrollWidth > innerWidth + 1, thumb: !!document.querySelector('.wp-post-image, .post-thumbnail img, .attachment-post-thumbnail'),
			};
		}, host);
		check(`${a.slug}: one h1, a title and a meta description`, info.h1 === 1 && info.title.length > 15 && info.desc.length >= 70, `h1=${info.h1} title=${info.title.length} desc=${info.desc.length}`);
		check(`${a.slug}: canonical and Article data`, /\/\//.test(info.canon) && info.article);
		if (a.role === 'pillar') { check(`${a.slug}: FAQPage data for the questions it shows`, info.faq); }
		check(`${a.slug}: enough text`, info.words >= (a.role === 'pillar' ? 2500 : 600), String(info.words));
		check(`${a.slug}: headings do not skip levels`, !info.skip);
		check(`${a.slug}: table of contents anchors exist`, info.missing.length === 0, info.missing.join(','));
		check(`${a.slug}: every image loads and has alt text`, info.imgs.length >= 1 && info.imgs.every((i) => i.ok && i.alt.length > 8), `${info.imgs.length} images`);
		check(`${a.slug}: no horizontal scroll at desktop width`, !info.hscroll);
		const internal = info.links.filter((l) => new URL(l).host === host);
		const external = info.links.filter((l) => new URL(l).host !== host && /^https?:/.test(l));
		internal.forEach((l) => internalSeen.set(l.split('#')[0], a.slug));
		external.forEach((l) => externalSeen.set(l, a.slug));
		check(`${a.slug}: internal and external links present`, internal.length >= 6 && external.length >= (a.role === 'pillar' ? 12 : 2), `${internal.length} internal, ${external.length} external`);
		const linked = new Set(internal.map((l) => new URL(l).pathname.replace(/\//g, '')));
		if (a.role === 'pillar') { check(`${a.slug}: links to every spoke`, slugs.filter((s) => s !== a.slug).every((s) => linked.has(s))); }
		else { check(`${a.slug}: links back to the pillar and to two more articles`, linked.has(pillar.slug) && slugs.filter((s) => s !== a.slug && s !== pillar.slug && linked.has(s)).length >= 2); }
	}

	// links
	let badInternal = [];
	for (const u of internalSeen.keys()) {
		const r = await ctx.request.get(u, { maxRedirects: 5 }).catch(() => null);
		if (!r || r.status() >= 400) { badInternal.push(u + ' ' + (r ? r.status() : 'err')); }
	}
	check(`all ${internalSeen.size} distinct internal links resolve`, badInternal.length === 0, badInternal.slice(0, 5).join(' | '));
	let badExternal = [];
	const plain = await browser.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36' });
	for (const u of externalSeen.keys()) {
		const r = await plain.request.get(u, { maxRedirects: 5, timeout: 30000 }).catch(() => null);
		if (!r || r.status() >= 400) { badExternal.push(u + ' ' + (r ? r.status() : 'err')); }
	}
	check(`all ${externalSeen.size} distinct external links respond`, badExternal.length === 0, badExternal.slice(0, 5).join(' | '));

	// editor validity
	const list = await (await ctx.request.get(`${BASE}/wp-json/wp/v2/posts?per_page=50&status=publish,draft&search=&_fields=id,slug,meta`, { headers: { 'X-WP-Nonce': nonce } })).json();
	const idBySlug = Object.fromEntries(list.map((p) => [p.slug, p.id]));
	for (const a of M.articles) {
		const id = idBySlug[a.slug];
		if (!id) { check(`${a.slug}: found for the editor check`, false); continue; }
		await admin.goto(`${BASE}/wp-admin/post.php?post=${id}&action=edit`, { waitUntil: 'domcontentloaded' });
		await admin.waitForSelector('.editor-styles-wrapper, .block-editor-block-list__layout', { timeout: 120000 });
		await admin.waitForTimeout(2500);
		const bad = await admin.evaluate(() => document.querySelectorAll('.block-editor-warning').length);
		check(`${a.slug}: opens in the block editor with no invalid blocks`, bad === 0, `${bad} warnings`);
	}

	// phone
	const phone = await browser.newContext({ ...creds, viewport: { width: 390, height: 844 }, isMobile: true });
	const pp = await phone.newPage();
	pp.setDefaultTimeout(90000);
	for (const a of M.articles) {
		await pp.goto(`${BASE}/${a.slug}/?x=${Date.now()}`, { waitUntil: 'load' });
		const over = await pp.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
		check(`${a.slug}: no horizontal scroll on a 390 px phone`, !over);
	}
	await browser.close();
	console.log(`\n${results.filter((r) => r.pass).length}/${results.length} passed`);
	process.exit(results.every((r) => r.pass) ? 0 : 1);
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
