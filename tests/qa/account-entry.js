/**
 * Sign in / Create account across the site (passwordless email-code accounts).
 *
 *   1. /sign-in/, /login/, /account/ and /register/, /sign-up/ redirect to the account page
 *      with the right dialog mode;
 *   2. the desktop menu and the drawer show Sign in and Create account on ordinary pages,
 *      and each opens the dialog in place with the right title;
 *   3. a full sign-in from a non-domain page (staging test code) lands on My account,
 *      and the menu then shows My account and Sign out;
 *   4. Sign out signs out and the menu shows Sign in again;
 *   5. without JavaScript the menu links still lead to the account page.
 *
 * Basic Auth: BETA_USER / BETA_PASS. Admin (to read the staging test code): DB_WP_USER / DB_WP_PASS.
 */
const { chromium } = require('playwright');
const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const EMAIL = `qa-acct-entry-${Date.now().toString(36)}@example.com`;
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const ctx = await browser.newContext({ ...creds, viewport: { width: 1366, height: 900 } });
	const req = ctx.request;

	for (const [path, mode] of [['sign-in', 'signin'], ['login', 'signin'], ['account', 'signin'], ['register', 'register'], ['sign-up', 'register']]) {
		const r = await req.get(`${BASE}/${path}/`, { maxRedirects: 0, failOnStatusCode: false });
		const loc = r.headers()['location'] || '';
		check(`/${path}/ opens the account page in ${mode} mode`, [301, 302].includes(r.status()) && /\/my-wishlist\/\?auth=/.test(loc) && loc.endsWith(mode), `${r.status()} ${loc}`);
	}

	const page = await ctx.newPage();
	page.setDefaultTimeout(60000);
	await page.goto(`${BASE}/about-us/`, { waitUntil: 'load' });
	const desk = await page.$$eval('#db-desktop-nav [data-db-acct]', (as) => as.filter((a) => !a.closest('li').hidden).map((a) => a.textContent.trim()));
	check('desktop menu shows Sign in and Create account', desk.includes('Sign in') && desk.includes('Create account'), desk.join(', '));
	await page.click('#db-desktop-nav [data-db-acct="register"]');
	await page.waitForSelector('dialog.db-wl-dialog[open]');
	check('Create account opens the dialog in place', (await page.textContent('#db-wl-title')).trim() === 'Create your account' && /\/about-us\//.test(page.url()));
	await page.keyboard.press('Escape');
	await page.click('#db-desktop-nav [data-db-acct="signin"]');
	await page.waitForSelector('dialog.db-wl-dialog[open]');
	check('Sign in opens the dialog with the sign-in title', (await page.textContent('#db-wl-title')).trim() === 'Sign in');
	await page.keyboard.press('Escape');

	const phone = await browser.newContext({ ...creds, viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
	const pp = await phone.newPage();
	await pp.goto(`${BASE}/faqs/`, { waitUntil: 'load' });
	await pp.click('#db-mnav-toggle');
	await pp.waitForSelector('#db-mnav.is-open');
	const drawer = await pp.$$eval('#db-mnav [data-db-acct]', (as) => as.filter((a) => !a.closest('li').hidden).map((a) => a.textContent.trim()));
	check('drawer shows Sign in and Create account', drawer.includes('Sign in') && drawer.includes('Create account'), drawer.join(', '));
	await pp.click('#db-mnav [data-db-acct="register"]');
	await pp.waitForSelector('dialog.db-wl-dialog[open]');
	check('drawer Create account closes the drawer and opens the dialog', !(await pp.$eval('#db-mnav', (n) => n.classList.contains('is-open'))));
	const tapH = await pp.$eval('#db-mnav [data-db-acct="register"]', (a) => a.getBoundingClientRect().height);
	check('drawer account buttons are at least 44px tall', tapH >= 44, `${tapH}px`);
	const over = await pp.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
	check('no horizontal overflow at 390px with the account links', over <= 0, `${over}px`);
	await phone.close();

	if (process.env.DB_WP_USER) {
		const actx = await browser.newContext({ ...creds });
		const admin = await actx.newPage();
		await admin.goto(`${BASE}/wp-login.php`);
		await admin.fill('#user_login', process.env.DB_WP_USER);
		await admin.fill('#user_pass', process.env.DB_WP_PASS);
		await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);
		const nonce = (await (await actx.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();

		await page.goto(`${BASE}/news/`, { waitUntil: 'load' });
		await page.click('#db-desktop-nav [data-db-acct="signin"]');
		await page.fill('dialog.db-wl-dialog input[name=email]', EMAIL);
		await page.click('dialog.db-wl-dialog [data-step=email] button[type=submit]');
		await page.waitForSelector('dialog.db-wl-dialog [data-step=code]:not([hidden])');
		const code = await (await actx.request.get(`${BASE}/wp-json/db/v1/wishlist/test-code?email=${encodeURIComponent(EMAIL)}`, { headers: { 'X-WP-Nonce': nonce } })).json().then((j) => j.code).catch(() => '');
		await page.fill('dialog.db-wl-dialog input[name=code]', String(code));
		await Promise.all([page.waitForURL(/my-wishlist/), page.click('dialog.db-wl-dialog [data-step=code] button[type=submit]')]);
		check('signing in from another page lands on My account', /My account/.test(await page.textContent('h1')));
		await page.goto(`${BASE}/about-us/`, { waitUntil: 'load' });
		await page.waitForFunction(() => document.documentElement.classList.contains('db-signed-in'));
		const signedMenu = await page.$$eval('#db-desktop-nav [data-db-acct]', (as) => as.filter((a) => !a.closest('li').hidden).map((a) => a.textContent.trim()));
		check('signed in, the menu shows My account and Sign out', signedMenu.includes('My account') && signedMenu.includes('Sign out') && !signedMenu.includes('Create account'), signedMenu.join(', '));
		await Promise.all([page.waitForNavigation(), page.click('#db-desktop-nav [data-db-acct="signout"]')]);
		await page.goto(`${BASE}/contact-us/`, { waitUntil: 'load' });
		await page.waitForTimeout(800);
		const outMenu = await page.$$eval('#db-desktop-nav [data-db-acct]', (as) => as.filter((a) => !a.closest('li').hidden).map((a) => a.textContent.trim()));
		check('after Sign out the menu shows Sign in again', outMenu.includes('Sign in') && !outMenu.includes('Sign out'), outMenu.join(', '));
		await actx.close();
	}

	const nojs = await browser.newContext({ ...creds, javaScriptEnabled: false });
	const np = await nojs.newPage();
	await np.goto(`${BASE}/about-us/`, { waitUntil: 'domcontentloaded' });
	const href = await np.getAttribute('#db-desktop-nav [data-db-acct="signin"]', 'href');
	await np.goto(href, { waitUntil: 'domcontentloaded' });
	check('without JavaScript Sign in still reaches the account page', /my-wishlist\/\?auth=signin/.test(np.url()), np.url());
	await nojs.close();

	await browser.close();
	const failed = results.filter((r) => !r.pass).length;
	console.log(`\n${results.length - failed}/${results.length} passed`);
	process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
