/**
 * End to end: per-domain hit counting and the "All offers received" log.
 *
 *   1. add a temporary domain (price $2,500) in the admin;
 *   2. two different visitors open it, one of them twice (after the
 *      10-second repeat window); a bot and a logged-in editor are not counted;
 *   3. Domain hits shows total 3, unique 2 for it (and the domain list column);
 *   4. the same buyer submits the offer form twice: both offers are in
 *      "All offers received", and the negotiation holds both in one history;
 *   5. everything is removed.
 *
 * Staging only. Needs the admin login (DB_WP_USER / DB_WP_PASS).
 */
const { chromium } = require('playwright');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const STAMP = Date.now().toString(36);
const DOMAIN = `qa-hits-${STAMP}.com`;
const BUYER = `qa-hits-${STAMP}@example.com`;
const UA1 = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
const UA2 = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const adminCtx = await browser.newContext({ ...creds, viewport: { width: 1500, height: 1200 } });
	const admin = await adminCtx.newPage();
	admin.setDefaultTimeout(60000);
	await admin.goto(`${BASE}/wp-login.php`);
	await admin.fill('#user_login', process.env.DB_WP_USER);
	await admin.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);

	await admin.goto(`${BASE}/wp-admin/post-new.php?post_type=domain`, { waitUntil: 'domcontentloaded' });
	await admin.fill('#title', DOMAIN);
	await admin.fill('input[name=domain_price]', '2500');
	await admin.selectOption('select[name=domain_status]', 'sale');
	await Promise.all([admin.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 90000 }), admin.click('#publish')]);
	const postId = new URL(admin.url()).searchParams.get('post');
	const link = await admin.locator('#sample-permalink a, #view-post-btn a').first().getAttribute('href');

	const visit = async (ua) => {
		const ctx = await browser.newContext({ ...creds, userAgent: ua, viewport: { width: 1280, height: 900 } });
		const p = await ctx.newPage();
		p.setDefaultTimeout(60000);
		await p.goto(link, { waitUntil: 'domcontentloaded' });
		await p.waitForTimeout(3500);
		await ctx.close();
	};

	// 2. visits
	await visit(UA1);
	await visit(UA2);
	await sleep(11000);
	await visit(UA1); // same visitor again, outside the 10-second window
	await visit('Googlebot/2.1 (+http://www.google.com/bot.html) Mozilla/5.0'); // bot
	// the logged-in editor looks at the page
	await admin.goto(link, { waitUntil: 'domcontentloaded' });
	await admin.waitForTimeout(3000);
	check('editors never get the counting beacon', (await admin.locator('#db-hit-js').count()) === 0);

	// 3. report
	const hitsRow = async () => {
		await admin.goto(`${BASE}/wp-admin/admin.php?page=db-hits&q=${STAMP}`, { waitUntil: 'domcontentloaded' });
		const row = admin.locator('tr', { hasText: DOMAIN });
		return (await row.count()) ? (await row.first().locator('td').allInnerTexts()).map((s) => s.trim()) : [];
	};
	const cells = await hitsRow();
	const hu = (c) => (c || '').replace(/\s+/g, ' ').split(' / ').map(Number);
	check('Domain hits: today, 7 days, month, year and all time each show 3 hits and 2-3 unique (bot and editor not counted)', ['1', '2', '3', '4', '5'].every((i) => { const [h, u] = hu(cells[i]); return h === 3 && u >= 2 && u <= 3; }), cells.join(' | '));
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-hits&q=${STAMP}&from=2000-01-01&to=2000-01-31`, { waitUntil: 'domcontentloaded' });
	let rt = (await admin.locator('tr', { hasText: DOMAIN }).first().innerText()).replace(/\s+/g, ' ');
	check('a date range with no visits shows 0 / 0 in the range column', /Selected range/.test(await admin.locator('thead').innerText()) && /\b0 \/ 0\b/.test(rt), rt.slice(0, 120));
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-hits&q=${STAMP}&from=2026-01-01&to=2099-12-31`, { waitUntil: 'domcontentloaded' });
	rt = (await admin.locator('tr', { hasText: DOMAIN }).first().locator('td').allInnerTexts()).map((x) => x.trim());
	check('a range covering today shows the 3 hits', hu(rt[6])[0] === 3, rt.join(' | '));
	await admin.goto(`${BASE}/wp-admin/edit.php?post_type=domain&s=${STAMP}`, { waitUntil: 'domcontentloaded' });
	check('domain list shows "3 / n" in the Hits column', /3 \/ [23]/.test(await admin.locator('#the-list').innerText()));

	// 4. two offers from the same buyer through the real form
	const sendOffer = async (amount) => {
		const ctx = await browser.newContext({ ...creds, userAgent: UA2, viewport: { width: 1280, height: 1100 } });
		const p = await ctx.newPage();
		p.setDefaultTimeout(60000);
		await p.goto(link, { waitUntil: 'domcontentloaded' });
		await p.waitForTimeout(2000);
		await p.fill('#number-99', String(amount));
		await p.locator('#buy_now button[data-target="#first"]').first().click();
		await p.waitForTimeout(1200);
		const form = p.locator('form.wpcf7-form:visible').first();
		const set = async (sel, v) => { const l = form.locator(sel).first(); if (await l.count()) { await l.fill(v); } };
		await set('input[name*=name]', 'QA Buyer');
		await set('input[type=email]', BUYER);
		await set('input[name*=phone], input[type=tel]', '5125550100');
		await set('textarea', `QA offer ${amount}`);
		await form.locator('input[type=submit], button[type=submit]').first().click();
		await p.waitForURL(/thank-you/, { timeout: 60000 }).catch(() => {});
		await ctx.close();
	};
	await sendOffer(1800);
	await sendOffer(2200);
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-offers&tab=all&q=${STAMP}`, { waitUntil: 'domcontentloaded' });
	const rows = await admin.locator('tbody tr', { hasText: DOMAIN }).allInnerTexts();
	check('All offers received lists both offers from the same buyer', rows.length === 2 && rows.some((r) => /\$1,800/.test(r)) && rows.some((r) => /\$2,200/.test(r)), rows.map((r) => r.replace(/\s+/g, ' ').slice(0, 220)).join(' || '));
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-offers`, { waitUntil: 'domcontentloaded' });
	const negotiations = await admin.locator('tbody tr', { hasText: DOMAIN }).count();
	check('the negotiations tab holds one negotiation, not two', negotiations === 1, String(negotiations));
	await admin.locator('tbody tr', { hasText: DOMAIN }).first().locator('a.button').click();
	await admin.waitForLoadState('domcontentloaded');
	const detail = (await admin.locator('body').innerText()).replace(/\s+/g, ' ');
	check('the negotiation history shows both offers; current price $2,200', /\$1,800/.test(detail) && /\$2,200/.test(detail), detail.slice(0, 400));
	check('the offer page shows visits, offers on the domain and similar sales', /About qa-hits/.test(detail) && /Today.*Last 7 days.*This month.*This year.*All time/.test(detail) && /2 offers from 1 buyer/.test(detail) && /Similar domains sold/.test(detail), '');
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-offers&tab=all&q=${STAMP}`, { waitUntil: 'domcontentloaded' });
	const exp = await admin.locator('a.button', { hasText: 'Export CSV' }).getAttribute('href');
	const csv = await (await adminCtx.request.get(exp.replace(/&#038;/g, '&').startsWith('http') ? exp.replace(/&#038;/g, '&') : BASE + exp.replace(/&#038;/g, '&'))).text();
	check('CSV export has both offers', /1800\.00/.test(csv) && /2200\.00/.test(csv));

	// 5. clean up (the two test leads, then the domain)
	admin.on('dialog', (d) => d.accept());
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-crm-leads&s=${encodeURIComponent(BUYER)}`, { waitUntil: 'load' });
	for (let i = 0; i < 4; i++) {
		const del = admin.locator('tr', { hasText: BUYER }).first().locator('a:has-text("Delete"), button:has-text("Delete"), .db-crm-delete, [data-action=delete]');
		if (!(await del.count())) { break; }
		await del.first().click();
		await admin.waitForTimeout(1500);
		await admin.goto(`${BASE}/wp-admin/admin.php?page=db-crm-leads&s=${encodeURIComponent(BUYER)}`, { waitUntil: 'load' });
	}
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-sales&mode=test&q=${STAMP}`, { waitUntil: 'domcontentloaded' });
	await admin.goto(`${BASE}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
	const trash = await admin.locator('#delete-action a.submitdelete').getAttribute('href');
	await admin.goto(trash.startsWith('http') ? trash : BASE + trash, { waitUntil: 'domcontentloaded' });
	await admin.goto(`${BASE}/wp-admin/edit.php?post_status=trash&post_type=domain&s=${STAMP}`, { waitUntil: 'domcontentloaded' });
	for (const href of await admin.locator('#the-list a.submitdelete').evaluateAll((as) => as.map((a) => a.href))) { await admin.goto(href, { waitUntil: 'domcontentloaded' }); }
	check('test domain removed', (await hitsRow()).length === 0);

	await browser.close();
	console.log(`\n${results.filter((r) => r.pass).length}/${results.length} passed`);
	process.exit(results.every((r) => r.pass) ? 0 : 1);
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
