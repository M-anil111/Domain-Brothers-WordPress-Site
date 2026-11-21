/**
 * Builds db-custom-blocks/assets/domain-theme.css: the rules of the theme's
 * assets/css/style.css that the domain pages use.
 *
 * The theme stylesheet is 51 KB and render-blocking; domain pages use a small
 * part of it. A rule is kept when
 *   - its selector (pseudo-classes and pseudo-elements removed) matches an
 *     element on any of the sample domain pages (priced, offer, sold) at a
 *     phone, tablet or desktop width; or
 *   - its selector names a state or a script-added class (forms, validation,
 *     open/active/show, modals), which may not be in the page when it loads; or
 *   - it is @font-face or @keyframes.
 * Rules keep their order and their @media wrappers. Relative url()s are
 * written as {THEME}/assets/..., resolved by the plugin when it serves the file.
 * The first line records the size and hash of the source, so the plugin can
 * fall back to the full stylesheet when the theme's file changes.
 *
 *   BETA_USER=... BETA_PASS=... NODE_PATH=$(npm root -g) node tools/build-domain-css.js
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { chromium } = require('playwright');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const PAGES = (process.env.DOMAIN_PAGES || '/domains/247callers-com/,/domains/fatality-ca/,/domains/mycaymanproperty-com/,/domains/wingcms-com/').split(',');
const WIDTHS = [360, 820, 1366];
const OUT = path.join(__dirname, '..', 'db-custom-blocks', 'assets', 'domain-theme.css');
const DYNAMIC = /wpcf7|modal|\.active|\.open|\.show|\.in\b|collaps|error|valid|\.focus|:checked|:disabled|tooltip|dropdown|\.fade|\.hide|\.hidden|\.selected|\.current|\.loading|\.sticky|\.fixed|\[type=|\binput\b|\bselect\b|\btextarea\b|\bbutton\b|\blabel\b/i;

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	let source = null;
	const keep = new Set();
	let rules = null;
	for (const w of WIDTHS) {
		const ctx = await browser.newContext({ ...creds, viewport: { width: w, height: 900 } });
		const page = await ctx.newPage();
		await page.route(/db\/v1\/(hit|funnel)/, (r) => r.fulfill({ status: 204, body: '' }));
		for (const u of PAGES) {
			let ok = false;
			for (let t = 0; t < 6 && !ok; t++) {
				try { const r = await page.goto(BASE + u, { waitUntil: 'load', timeout: 60000 }); ok = r && r.status() === 200; } catch (e) { ok = false; }
				if (!ok) { await page.waitForTimeout(15000); }
			}
			if (!ok) { throw new Error('could not load ' + u); }
			await page.waitForTimeout(800);
			if (null === source) {
				const href = await page.evaluate(() => { const l = [...document.querySelectorAll('link[rel=stylesheet]')].find((x) => /themes\/[^/]+\/assets\/css\/style\.css/.test(x.href)); return l ? l.href : ''; });
				if (!href) { throw new Error('the theme style.css is not linked on ' + u + ' (is the trimmed file already live? set DB_DOMAIN_FULL_CSS)'); }
				source = await (await page.request.get(href)).text();
			}
			// Parse the source in the page (a constructed sheet: same parser, no CORS limits) and test every selector.
			const res = await page.evaluate(({ css, dyn }) => {
				const sheet = new CSSStyleSheet(); sheet.replaceSync(css);
				const DYN = new RegExp(dyn, 'i');
				const out = []; const hit = [];
				const strip = (sel) => sel.replace(/::?[a-zA-Z-]+(\([^)]*\))?/g, (m) => (/^:(not|is|where|has)\(/.test(m) ? m : '')).replace(/\s*([>+~])\s*$/, '').trim() || '*';
				const walk = (list, media) => {
					for (const r of list) {
						if (r instanceof CSSMediaRule) { walk(r.cssRules, media.concat(r.conditionText)); continue; }
						if (r instanceof CSSSupportsRule) { walk(r.cssRules, media.concat('@supports ' + r.conditionText)); continue; }
						const i = out.length;
						if (r instanceof CSSStyleRule) {
							out.push({ media, text: r.cssText });
							const parts = r.selectorText.split(',');
							let used = false;
							for (const s of parts) {
								if (DYN.test(s)) { used = true; break; }
								try { if (document.querySelector(strip(s))) { used = true; break; } } catch (e) { used = true; break; }
							}
							if (used) { hit.push(i); }
						} else {
							out.push({ media, text: r.cssText }); hit.push(i); // @font-face, @keyframes, @import ...
						}
					}
				};
				walk(sheet.cssRules, []);
				return { out, hit };
			}, { css: source, dyn: DYNAMIC.source });
			if (!rules) { rules = res.out; }
			res.hit.forEach((i) => keep.add(i));
			console.log(w, u, 'rules', res.out.length, 'kept so far', keep.size);
		}
		await ctx.close();
	}
	await browser.close();

	let css = ''; let open = '';
	rules.forEach((r, i) => {
		if (!keep.has(i)) { return; }
		const m = r.media.map((c) => (c.startsWith('@supports') ? c : '@media ' + c)).join('|');
		if (m !== open) {
			if (open) { css += '}'.repeat(open.split('|').length) + '\n'; }
			if (m) { css += m.split('|').map((c) => c + '{').join('') + '\n'; }
			open = m;
		}
		css += r.text + '\n';
	});
	if (open) { css += '}'.repeat(open.split('|').length) + '\n'; }
	// url(../x) and url("../x") are relative to the theme's assets/css/.
	css = css.replace(/url\((["']?)\.\.\//g, 'url($1{THEME}/assets/').replace(/url\((["']?)(?!data:|https?:|\/|\{THEME\})([^)"']+)/g, 'url($1{THEME}/assets/css/$2');
	const head = `/* db-domain-theme source-bytes=${Buffer.byteLength(source)} source-md5=${crypto.createHash('md5').update(source).digest('hex')} kept=${keep.size}/${rules.length} */\n`;
	fs.writeFileSync(OUT, head + css);
	console.log('wrote', OUT, Buffer.byteLength(head + css), 'bytes from', Buffer.byteLength(source));
})().catch((e) => { console.error(e.message); process.exit(1); });
