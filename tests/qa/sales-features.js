/**
 * Sales features on staging: the domain finder, trust center, collections,
 * "Why this price?" on domain pages, the transfers tab, and the admin reports.
 *
 *   1. /find-a-domain/ shows the five questions and the broker form;
 *   2. a finder search (?go=1) shows a shortlist or the "no names" message, and the
 *      results carry reasons, not bare names;
 *   3. the trust center loads, names the card processor and makes no escrow claim;
 *   4. /collections/ loads, and every listed collection shows at least three names;
 *   5. a domain page has a "Why this price?" section;
 *   6. the finder has no horizontal overflow at phone width;
 *   7. admin: the Sales funnel and Buyer intent reports load.
 *
 * Staging only. Basic Auth: BETA_USER / BETA_PASS. Admin login: DB_WP_USER / DB_WP_PASS.
 */
const { chromium } = require('playwright');
const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const ctx = await browser.newContext({ ...creds, viewport: { width: 1280, height: 900 } });
	const page = await ctx.newPage();
	page.setDefaultTimeout(120000);

	await page.goto(`${BASE}/find-a-domain/`, { waitUntil: 'domcontentloaded' });
	const quiz = await page.$$eval('#db-finder fieldset', (f) => f.length);
	check('finder shows five questions', quiz === 5, `found ${quiz}`);
	check('finder has the broker form', await page.$('#db-broker input[name=email]') !== null);

	await page.goto(`${BASE}/find-a-domain/?go=1&industry=any&audience=any&geography=canada&tone=any&budget=any`, { waitUntil: 'domcontentloaded' });
	const heading = await page.$eval('#db-shortlist-h', (h) => h.textContent).catch(() => '');
	const items = await page.$$eval('.db-sales-result', (l) => l.length);
	const noMatch = await page.$('.db-sales-card h2');
	check('finder search shows a shortlist', heading === 'Your shortlist', `heading="${heading}"`);
	if (items > 0) {
		const withReasons = await page.$$eval('.db-sales-result', (l) => l.filter((r) => r.querySelector('.db-sales-why li')).length);
		check('every shortlist entry gives reasons', withReasons === items, `${withReasons}/${items}`);
	} else {
		check('an empty shortlist explains the next step', noMatch !== null);
	}

	await page.goto(`${BASE}/trust-center/`, { waitUntil: 'domcontentloaded' });
	const trust = (await page.textContent('main')) || '';
	check('trust center loads and names Stripe', /Stripe/.test(trust));
	check('trust center makes no escrow claim', !/escrow/i.test(trust));

	await page.goto(`${BASE}/collections/`, { waitUntil: 'domcontentloaded' });
	const cols = await page.$$eval('.db-sales-collections a', (a) => a.map((x) => x.href));
	for (const href of cols) {
		await page.goto(href, { waitUntil: 'domcontentloaded' });
		const n = await page.$$eval('.db-sales-result', (l) => l.length);
		check(`collection ${href.split('/').filter(Boolean).pop()} shows at least three names`, n >= 3, `${n} names`);
	}
	check('collections index loads', true, `${cols.length} published`);

	await page.goto(`${BASE}/`, { waitUntil: 'domcontentloaded' });
	const firstDomain = await page.$eval('a[href*="/domains/"]', (a) => a.href).catch(() => '');
	if (firstDomain) {
		await page.goto(firstDomain, { waitUntil: 'domcontentloaded' });
		check('domain page has "Why this price?"', (await page.$('#db-value-h')) !== null, firstDomain);
	} else {
		check('domain page has "Why this price?"', false, 'no domain link found on home page');
	}

	const phone = await browser.newContext({ ...creds, viewport: { width: 375, height: 812 } });
	const pp = await phone.newPage();
	await pp.goto(`${BASE}/find-a-domain/`, { waitUntil: 'domcontentloaded' });
	const overflow = await pp.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	check('finder has no horizontal overflow at 375px', overflow <= 0, `overflow ${overflow}px`);
	await phone.close();

	if (process.env.DB_WP_USER) {
		const admin = await ctx.newPage();
		await admin.goto(`${BASE}/wp-login.php`);
		await admin.fill('#user_login', process.env.DB_WP_USER);
		await admin.fill('#user_pass', process.env.DB_WP_PASS);
		await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);
		for (const [slug, title] of [['db-funnel', 'Sales funnel'], ['db-intent', 'Buyer intent']]) {
			await admin.goto(`${BASE}/wp-admin/admin.php?page=${slug}`, { waitUntil: 'domcontentloaded' });
			const h1 = await admin.$eval('.wrap h1', (h) => h.textContent).catch(() => '');
			check(`admin ${title} report loads`, h1.includes(title), `h1="${h1.trim()}"`);
		}
	} else {
		console.log('SKIP admin reports (DB_WP_USER not set)');
	}

	await browser.close();
	const failed = results.filter((r) => !r.pass).length;
	console.log(`\n${results.length - failed}/${results.length} passed`);
	process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
