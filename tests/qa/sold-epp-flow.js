/**
 * End to end: a sale shows in "Sold domains" with its price, and the transfer
 * (EPP) code reaches the buyer through the private page.
 *
 *   1. add a temporary domain in the admin (price $3,000);
 *   2. a 3-month payment plan starts (signed test webhook): the Sold domains
 *      report shows the price and what has been collected, and refuses the
 *      code until the last payment;
 *   3. instalments 2 and 3 arrive: the plan completes, the domain is marked sold;
 *   4. the admin sends the code: the buyer's private page asks "Show my code",
 *      the code is not in the page until it is pressed, the report shows
 *      "Viewed";
 *   5. sending a new code cancels the old link; a wrong token shows nothing;
 *   6. everything is removed.
 *
 * Test mode only. Needs the admin login and STRIPE_TEST_WEBHOOK_SECRET.
 */
const { chromium } = require('playwright');
const sendWebhook = require('./send-test-webhook.js');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const STAMP = Date.now().toString(36);
const DOMAIN = `qa-epp-${STAMP}.com`;
const BUYER = `qa-epp-${STAMP}@example.com`;
const CODE1 = 'QA-epp-9X7!k#2';
const CODE2 = 'NEW-code-4Zq@8';
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };
const body = async (p) => (await p.locator('body').innerText()).replace(/\s+/g, ' ');

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

	// 1. a temporary listing
	await admin.goto(`${BASE}/wp-admin/post-new.php?post_type=domain`, { waitUntil: 'domcontentloaded' });
	await admin.fill('#title', DOMAIN);
	await admin.fill('input[name=domain_price]', '3000');
	await admin.selectOption('select[name=domain_status]', 'sale');
	await Promise.all([admin.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 90000 }), admin.click('#publish')]);
	const postId = new URL(admin.url()).searchParams.get('post');

	const row = () => admin.locator('tr', { hasText: DOMAIN });
	const report = async () => { await admin.goto(`${BASE}/wp-admin/admin.php?page=db-sold&q=${STAMP}`, { waitUntil: 'domcontentloaded' }); return (await row().count()) ? (await row().first().innerText()).replace(/\s+/g, ' ') : ''; };

	// 2. plan starts
	process.env.STRIPE_TEST_WEBHOOK_SECRET || (console.error('Set STRIPE_TEST_WEBHOOK_SECRET'), process.exit(2));
	await sendWebhook({ kind: 'plan', domain: DOMAIN, args: ['1', '3', '3000', BUYER] });
	let t = await report();
	check('plan in progress is listed with its price and what has been collected', /\$3,000\.00/.test(t) && /\$1,000\.00/.test(t) && /1 of 3 paid/.test(t), t.slice(0, 120));
	check('the code is refused until the last payment', /Payment plan not finished/.test(t) && (await row().locator('details.dbs-epp').count()) === 0);

	// 3. plan completes
	await sendWebhook({ kind: 'plan', domain: DOMAIN, args: ['2', '3', '3000', BUYER] });
	await sendWebhook({ kind: 'plan', domain: DOMAIN, args: ['3', '3', '3000', BUYER] });
	t = await report();
	check('completed plan: $3,000 collected, Website: payment plan, buyer recorded', /Plan completed|3 of 3 paid/.test(t) && /\$3,000\.00/.test(t) && /payment plan/i.test(t) && new RegExp(BUYER).test(t), t.slice(0, 140));
	check('the code can now be sent', (await row().locator('details.dbs-epp').count()) === 1);
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-sold`, { waitUntil: 'domcontentloaded' });
	const tiles = await body(admin);
	check('totals show Domains sold, Total sold for and Money collected', /Domains sold \d+/.test(tiles) && /Money collected \$[\d,]+\.\d\d/.test(tiles));

	// 4. send the code
	await report();
	await row().locator('details.dbs-epp summary').click();
	await row().locator('input[name=code]').fill(CODE1);
	await Promise.all([admin.waitForURL(/db-sold/), row().locator('details.dbs-epp button').click()]);
	const note = await body(admin);
	check('code saved (email may fail on a site with no working mail)', /Done\. .* has been emailed|code is saved/.test(note));
	await report();
	const link1 = await row().locator('a.dbs-eppurl').first().getAttribute('href');
	check('a private link exists for the buyer', /[?&]db_epp=[0-9a-f]{32}/.test(link1 || ''));
	check('the report shows the code as sent, not opened', /Sent .*not opened yet/.test(await row().first().innerText()));

	const buyerCtx = await browser.newContext({ ...creds, viewport: { width: 420, height: 900 } });
	const buyer = await buyerCtx.newPage();
	buyer.setDefaultTimeout(60000);
	const resp = await buyer.goto(link1, { waitUntil: 'domcontentloaded' });
	t = await body(buyer);
	check('buyer link: page asks to show the code, and the code is not in it', resp.status() === 200 && /Show my code/.test(t) && !t.includes(CODE1) && !(await buyer.content()).includes(CODE1));
	check('page is private (noindex, no-store)', /noindex/.test(resp.headers()['x-robots-tag'] || '') && /no-store|no-cache/.test(resp.headers()['cache-control'] || ''));
	await Promise.all([buyer.waitForLoadState('domcontentloaded'), buyer.getByRole('button', { name: 'Show my code' }).click()]);
	t = await body(buyer);
	check('after pressing the button the buyer sees the exact code', (await buyer.locator('#db-epp-code').innerText()) === CODE1);
	await buyer.goto(link1, { waitUntil: 'domcontentloaded' });
	check('opening the link again does not show the code', !(await buyer.content()).includes(CODE1));
	check('the report now shows Viewed', /Viewed/.test(await report()));

	// 5. a new code cancels the old link
	await row().locator('details.dbs-epp summary').click();
	await row().locator('input[name=code]').fill(CODE2);
	await Promise.all([admin.waitForURL(/db-sold/), row().locator('details.dbs-epp button').click()]);
	const old = await buyerCtx.newPage();
	const r2 = await old.goto(link1, { waitUntil: 'domcontentloaded' });
	check('after sending a new code the old link no longer works (410)', r2.status() === 410 && /no longer active/.test(await body(old)));
	const bad = await old.goto(`${BASE}/?db_epp=${'0'.repeat(32)}`, { waitUntil: 'domcontentloaded' });
	check('a wrong token shows nothing (410)', bad.status() === 410);
	await old.goto(`${BASE}/?db_epp=${'0'.repeat(31)}`, { waitUntil: 'domcontentloaded' });
	check('a malformed token shows nothing', /no longer active/.test(await body(old)));

	// 6. clean up
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-sales&mode=test&q=${STAMP}`, { waitUntil: 'domcontentloaded' });
	admin.once('dialog', (d) => d.accept());
	if (await admin.locator('a.dbs-reset').count()) { await Promise.all([admin.waitForURL(/reset=/), admin.locator('a.dbs-reset').first().click()]); }
	await admin.goto(`${BASE}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
	const trash = await admin.locator('#delete-action a.submitdelete').getAttribute('href');
	await admin.goto(trash.startsWith('http') ? trash : BASE + trash, { waitUntil: 'domcontentloaded' });
	await admin.goto(`${BASE}/wp-admin/edit.php?post_status=trash&post_type=domain&s=${STAMP}`, { waitUntil: 'domcontentloaded' });
	for (const href of await admin.locator('#the-list a.submitdelete').evaluateAll((as) => as.map((a) => a.href))) { await admin.goto(href, { waitUntil: 'domcontentloaded' }); }
	check('test sale, code and domain removed', (await report()) === '');

	await browser.close();
	console.log(`\n${results.filter((r) => r.pass).length}/${results.length} passed`);
	process.exit(results.every((r) => r.pass) ? 0 : 1);
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
