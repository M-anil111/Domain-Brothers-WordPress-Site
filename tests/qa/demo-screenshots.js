/**
 * Builds a small set of demo records on staging (temporary domain, visits,
 * two offers, a completed payment plan and an EPP code), photographs each
 * screen into docs/screenshots, then removes everything it created.
 */
const { chromium } = require('playwright');
const path = require('path');
const sendWebhook = require('./send-test-webhook.js');
const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const STAMP = Date.now().toString(36);
const DOMAIN = `qa-demo-${STAMP}.com`;
const BUYER = `qa-demo-${STAMP}@example.com`;
const OUT = path.join(__dirname, '..', '..', 'docs', 'screenshots');
const UAS = ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'];
(async () => {
	const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } };
	const actx = await browser.newContext({ ...creds, viewport: { width: 1400, height: 900 }, deviceScaleFactor: 1 });
	const a = await actx.newPage(); a.setDefaultTimeout(60000); a.on('dialog', (d) => d.accept());
	const shot = async (name, full = true) => { await a.screenshot({ path: path.join(OUT, name + '.png'), fullPage: full }); console.log('shot', name); };
	await a.goto(`${BASE}/wp-login.php`); await a.fill('#user_login', process.env.DB_WP_USER); await a.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([a.waitForURL(/wp-admin/, { waitUntil: 'commit' }), a.click('#wp-submit')]);
	await a.goto(`${BASE}/wp-admin/post-new.php?post_type=domain`, { waitUntil: 'domcontentloaded' });
	await a.fill('#title', DOMAIN); await a.fill('input[name=domain_price]', '3000'); await a.selectOption('select[name=domain_status]', 'sale');
	await Promise.all([a.waitForURL(/post\.php\?post=\d+&action=edit/), a.click('#publish')]);
	const postId = new URL(a.url()).searchParams.get('post');
	const link = await a.locator('#sample-permalink a, #view-post-btn a').first().getAttribute('href');
	for (const ua of [UAS[0], UAS[1], UAS[1]]) {
		const c = await browser.newContext({ ...creds, userAgent: ua }); const p = await c.newPage(); p.setDefaultTimeout(60000);
		await p.goto(link, { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(3500); await c.close(); await new Promise((r) => setTimeout(r, 11000));
	}
	// two offers through the real form
	for (const amt of [1800, 2200]) {
		const c = await browser.newContext({ ...creds, userAgent: UAS[1], viewport: { width: 1280, height: 1000 } }); const p = await c.newPage(); p.setDefaultTimeout(60000);
		await p.goto(link, { waitUntil: 'domcontentloaded' }); await p.waitForTimeout(2000);
		await p.fill('#number-99', String(amt)); await p.locator('#buy_now button[data-target="#first"]').first().click(); await p.waitForTimeout(1200);
		const f = p.locator('form.wpcf7-form:visible').first();
		const set = async (s, v) => { const l = f.locator(s).first(); if (await l.count()) await l.fill(v); };
		await set('input[name*=name]', 'Demo Buyer'); await set('input[type=email]', BUYER); await set('input[name*=phone], input[type=tel]', '5125550100'); await set('textarea', `Demo offer ${amt}`);
		await f.locator('input[type=submit], button[type=submit]').first().click(); await p.waitForURL(/thank-you/, { timeout: 60000 }).catch(() => {}); await p.waitForTimeout(3000); await c.close();
	}
	await a.goto(`${BASE}/wp-admin/admin.php?page=db-hits&q=${STAMP}`, { waitUntil: 'domcontentloaded' }); await shot('1-domain-hits');
	await a.goto(`${BASE}/wp-admin/admin.php?page=db-hits&q=${STAMP}&from=2026-11-01&to=2026-11-30`, { waitUntil: 'domcontentloaded' }); await shot('1b-domain-hits-date-range');
	await a.goto(`${BASE}/wp-admin/edit.php?post_type=domain&s=${STAMP}`, { waitUntil: 'domcontentloaded' }); await shot('2-domain-list-hits-and-listing-columns');
	await a.goto(`${BASE}/wp-admin/admin.php?page=db-offers&tab=all&q=${STAMP}`, { waitUntil: 'domcontentloaded' }); await shot('3-all-offers-received');
	await a.goto(`${BASE}/wp-admin/admin.php?page=db-offers`, { waitUntil: 'domcontentloaded' });
	await a.locator('tbody tr', { hasText: DOMAIN }).first().locator('a.button').click(); await a.waitForLoadState('domcontentloaded'); await shot('4-offer-reply-and-history');
	for (const n of ['1', '2', '3']) await sendWebhook({ kind: 'plan', domain: DOMAIN, args: [n, '3', '3000', BUYER] });
	await a.goto(`${BASE}/wp-admin/admin.php?page=db-sold&q=${STAMP}`, { waitUntil: 'domcontentloaded' });
	const row = a.locator('tr', { hasText: DOMAIN }); await row.locator('details.dbs-epp summary').click(); await row.locator('input[name=code]').fill('DEMO-epp-0000');
	await Promise.all([a.waitForURL(/db-sold/), row.locator('details.dbs-epp button').click()]);
	await a.goto(`${BASE}/wp-admin/admin.php?page=db-sold&q=${STAMP}`, { waitUntil: 'domcontentloaded' }); await shot('5-sold-domains-report-and-epp');
	const link1 = await a.locator('tr', { hasText: DOMAIN }).locator('a.dbs-eppurl').first().getAttribute('href');
	const bctx = await browser.newContext({ ...creds, viewport: { width: 480, height: 800 } }); const b = await bctx.newPage(); b.setDefaultTimeout(60000);
	await b.goto(link1, { waitUntil: 'domcontentloaded' }); await b.screenshot({ path: path.join(OUT, '6-buyer-epp-page.png') });
	await a.goto(`${BASE}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' }); const t0 = a.locator('#db_listing_state, .db-listing-box').first(); await shot('7-domain-edit-listing-status', false);
	// cleanup
	await a.goto(`${BASE}/wp-admin/admin.php?page=db-sales&mode=test&q=${STAMP}`, { waitUntil: 'domcontentloaded' });
	if (await a.locator('a.dbs-reset').count()) await Promise.all([a.waitForURL(/reset=/), a.locator('a.dbs-reset').first().click()]);
	for (let i = 0; i < 6; i++) { await a.goto(`${BASE}/wp-admin/admin.php?page=db-crm-leads&s=${encodeURIComponent(BUYER)}`, { waitUntil: 'load' }); const d = a.locator('tr', { hasText: BUYER }).first().locator('a:has-text("Delete"), button:has-text("Delete"), .db-crm-delete, [data-action=delete]'); if (!(await d.count())) break; await d.first().click(); await a.waitForTimeout(1200); }
	await a.goto(`${BASE}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
	const trash = await a.locator('#delete-action a.submitdelete').getAttribute('href'); await a.goto(trash.startsWith('http') ? trash : BASE + trash, { waitUntil: 'domcontentloaded' });
	await a.goto(`${BASE}/wp-admin/edit.php?post_status=trash&post_type=domain&s=${STAMP}`, { waitUntil: 'domcontentloaded' });
	for (const h of await a.locator('#the-list a.submitdelete').evaluateAll((as) => as.map((x) => x.href))) await a.goto(h, { waitUntil: 'domcontentloaded' });
	await browser.close();
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
