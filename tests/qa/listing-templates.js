/**
 * Does each kind of domain the admin can add get the right page template?
 *
 * Creates four temporary domains through the real admin screen, opens each on
 * the front end, checks which template it uses, then deletes them:
 *
 *   price set, status "For sale"      -> standard (buy now / pay monthly)
 *   no price                          -> custom offer (make an offer, no price)
 *   status "Sold" (theme field)       -> sold page
 *   Listing status "Not for sale"     -> not-for-sale page
 *
 * Staging only: it writes (and removes) four posts of type "domain".
 *
 *   BETA_USER=... BETA_PASS=... DB_WP_USER=... DB_WP_PASS=... \
 *   NODE_PATH=$(npm root -g) node tests/qa/listing-templates.js
 */
const { chromium } = require('playwright');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const STAMP = process.env.QA_STAMP || Date.now().toString(36);
const CLEANUP_ONLY = !!process.env.QA_STAMP;
const CASES = [
	{ key: 'standard', title: `qa-std-${STAMP}.com`, price: '1234', status: 'sale', listing: 'auto', expect: (h) => /\/buy-now\/\?d=/.test(h) && !/db-sold-band/.test(h), want: 'a buy-now link and no sold/not-for-sale banner' },
	{ key: 'offer', title: `qa-offer-${STAMP}.com`, price: '', status: 'sale', listing: 'auto', expect: (h) => !/\/buy-now\/\?d=/.test(h) && !/db-sold-band/.test(h) && /id="number-99"/.test(h), want: 'the make-an-offer box and no buy-now link' },
	{ key: 'sold', title: `qa-sold-${STAMP}.com`, price: '999', status: 'sold', listing: 'auto', expect: (h) => /db-sold--sold/.test(h) && !/id="number-99"/.test(h), want: 'the sold page, even though a price is set' },
	{ key: 'not_for_sale', title: `qa-nfs-${STAMP}.com`, price: '999', status: 'sale', listing: 'not_for_sale', expect: (h) => /db-sold--nfs/.test(h) && /Ask the owner/.test(h), want: 'the not-for-sale page with the enquiry form' },
];

const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const ctx = await browser.newContext({ ...creds, viewport: { width: 1400, height: 1100 } });
	const page = await ctx.newPage();
	page.setDefaultTimeout(60000);
	await page.goto(`${BASE}/wp-login.php`);
	await page.fill('#user_login', process.env.DB_WP_USER);
	await page.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([page.waitForURL(/wp-admin/, { waitUntil: 'commit' }), page.click('#wp-submit')]);

	const created = [];
	for (const c of CLEANUP_ONLY ? [] : CASES) {
		await page.goto(`${BASE}/wp-admin/post-new.php?post_type=domain`, { waitUntil: 'domcontentloaded' });
		await page.waitForSelector('#title');
		await page.fill('#title', c.title);
		await page.fill('input[name=domain_price]', c.price);
		await page.selectOption('select[name=domain_status]', c.status);
		await page.selectOption('select[name=domain_type]', c.price ? 'offer_buy_now' : 'offer').catch(() => {});
		await page.check(`input[name=db_listing_state][value=${c.listing}]`);
		await Promise.all([page.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 90000 }), page.click('#publish')]);
		const id = new URL(page.url()).searchParams.get('post');
		const link = await page.locator('#sample-permalink a, #view-post-btn a, .updated a:has-text("View")').first().getAttribute('href').catch(() => null);
		created.push({ ...c, id, link });
	}

	const front = await authCtxPage(browser, creds);
	for (const c of created) {
		const url = c.link || `${BASE}/domains/${c.title.replace(/\./g, '-')}/`;
		await front.goto(url, { waitUntil: 'domcontentloaded' });
		await front.waitForTimeout(800);
		const html = await front.content();
		check(`${c.key}: ${c.title} shows ${c.want}`, c.expect(html), (await front.title()).slice(0, 60));
	}

	// admin list shows the state
	await page.goto(`${BASE}/wp-admin/edit.php?post_type=domain&s=${STAMP}`, { waitUntil: 'domcontentloaded' });
	const list = await page.locator('body').innerText();
	check('admin domain list has a Listing column with each state', /For sale: fixed price/.test(list) && /For sale: custom offers/.test(list) && /Sold/.test(list) && /Not for sale/.test(list));

	// clean up: trash, then delete permanently
	for (const c of created) {
		await page.goto(`${BASE}/wp-admin/post.php?post=${c.id}&action=edit`, { waitUntil: 'domcontentloaded' });
		const trash = await page.locator('#delete-action a.submitdelete').getAttribute('href');
		await page.goto(trash.startsWith('http') ? trash : BASE + trash, { waitUntil: 'domcontentloaded' });
	}
	// Permanently delete only the posts this run created (never "Empty Trash": it removes every trashed domain).
	await page.goto(`${BASE}/wp-admin/edit.php?post_status=trash&post_type=domain&s=${STAMP}`, { waitUntil: 'domcontentloaded' });
	const dels = await page.locator('#the-list a.submitdelete').evaluateAll((as) => as.map((a) => a.href));
	for (const href of dels) { await page.goto(href, { waitUntil: 'domcontentloaded' }); }
	await page.goto(`${BASE}/wp-admin/edit.php?post_type=domain&post_status=all&s=${STAMP}`, { waitUntil: 'domcontentloaded' });
	check('test domains removed', !new RegExp(STAMP).test(await page.locator('#the-list').innerText().catch(() => '')));

	await browser.close();
	console.log(`\n${results.filter((r) => r.pass).length}/${results.length} passed`);
	process.exit(results.every((r) => r.pass) ? 0 : 1);
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });

async function authCtxPage(browser, creds) {
	const c = await browser.newContext({ ...creds, viewport: { width: 1280, height: 900 } });
	return c.newPage();
}
