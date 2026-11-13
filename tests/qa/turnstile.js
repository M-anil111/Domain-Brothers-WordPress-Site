/**
 * Bot protection (Cloudflare Turnstile): on, checked, off again.
 *
 * Uses Cloudflare's published TEST keys, which always pass (site 1x00000000000000000000AA,
 * secret 1x0000000000000000000000000000000AA) and never reach real Cloudflare accounts.
 *
 *   1. save the test keys under Domain Brothers > Launch checks; the page shows "On";
 *   2. the sign-in code request refuses a missing token (400) and accepts a token (200), and
 *      refuses every token when the check says no (Cloudflare's always-fail secret);
 *   3. a contact form submission without the token is treated as spam; with it, the mail is sent;
 *   4. the Turnstile widget renders in the sign-in dialog;
 *   5. turn the check off; the page shows "Owner" again and forms work without a token.
 *
 * Staging only. Admin login: DB_WP_USER / DB_WP_PASS. Basic Auth: BETA_USER / BETA_PASS.
 */
const { chromium } = require('playwright');
const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const SITE = '1x00000000000000000000AA';
const SECRET = '1x0000000000000000000000000000000AA';
const DUMMY = 'XXXX.DUMMY.TOKEN.XXXX';
const EMAIL = `qa-ts-${Date.now().toString(36)}@example.com`;
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const ctx = await browser.newContext({ ...creds, viewport: { width: 1280, height: 900 } });
	const admin = await ctx.newPage();
	admin.setDefaultTimeout(120000);
	await admin.goto(`${BASE}/wp-login.php`);
	await admin.fill('#user_login', process.env.DB_WP_USER);
	await admin.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);

	const setKeys = async (site, secret, off) => {
		await admin.goto(`${BASE}/wp-admin/admin.php?page=db-launch`, { waitUntil: 'domcontentloaded' });
		if (off) { admin.once('dialog', (d) => d.accept()); await Promise.all([admin.waitForURL(/saved=turnstile/), admin.click('button[value=off]')]); return; }
		await admin.fill('#db_ts_site', site);
		await admin.fill('#db_ts_secret', secret);
		await Promise.all([admin.waitForURL(/saved=turnstile/), admin.click('button[value=save]')]);
	};

	await setKeys(SITE, SECRET, false);
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-launch`, { waitUntil: 'domcontentloaded' });
	const onText = await admin.locator('#turnstile').locator('xpath=following-sibling::p[1]').innerText();
	check('saving the keys turns the check on', /On\./.test(onText), onText.slice(0, 60));
	const secretShown = await admin.locator('#db_ts_secret').inputValue();
	check('the secret key is not shown again', secretShown === '', 'field empty');

	const nonce = (await (await ctx.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
	const api = (path, body) => ctx.request.fetch(`${BASE}/wp-json/db/v1/${path}`, { method: 'POST', headers: { Origin: BASE, 'Content-Type': 'text/plain', 'X-WP-Nonce': nonce }, data: JSON.stringify(body) });
	const noTok = await api('wishlist/code', { email: EMAIL });
	check('sign-in code without a check is refused (400)', noTok.status() === 400, String(noTok.status()));
	const withTok = await api('wishlist/code', { email: EMAIL, ts: DUMMY });
	check('sign-in code with a valid check is accepted (200)', withTok.status() === 200, String(withTok.status()));

	const cf7 = (fields) => ctx.request.post(`${BASE}/wp-json/contact-form-7/v1/contact-forms/1878/feedback`, { multipart: { _wpcf7_unit_tag: 'wpcf7-f1878-o1', 'your-name': 'QA Turnstile', 'your-email': EMAIL, hide_domainname: 'qa-turnstile-check.com', 'your-offer': '1200', 'your-phone': '5550100', 'your-message': 'QA turnstile check, please ignore.', ...fields } });
	// Cloudflare's always-fail secret (2x...) refuses every token: the refusal path.
	await setKeys('2x00000000000000000000AB', '2x0000000000000000000000000000000AA', false);
	const failSecret = await api('wishlist/code', { email: EMAIL, ts: DUMMY });
	check('a token the check refuses is turned away (400)', failSecret.status() === 400, String(failSecret.status()));
	const failForm = await (await cf7({ 'cf-turnstile-response': DUMMY })).json().catch(() => ({}));
	await setKeys(SITE, SECRET, false);
	check('a form whose token the check refuses is treated as spam', failForm.status === 'spam', String(failForm.status));
	const spamRes = await cf7({});
	const spamJ = await spamRes.json().catch(() => ({}));
	check('a form submission without the check is treated as spam', spamJ.status !== 'mail_sent', String(spamJ.status));
	const okRes = await cf7({ 'cf-turnstile-response': DUMMY });
	const okJ = await okRes.json().catch(() => ({}));
	check('a form submission with the check is sent', okJ.status === 'mail_sent', String(okJ.status));

	// The widget appears in the sign-in dialog on a domain page.
	const page = await ctx.newPage();
	page.setDefaultTimeout(120000);
	await page.goto(`${BASE}/all-domains/?x=${Date.now()}`, { waitUntil: 'load' });
	await page.waitForTimeout(3000);
	const hasScript = await page.evaluate(() => !!document.querySelector('script[src*="challenges.cloudflare.com/turnstile"]'));
	check('the Turnstile script is on the page', hasScript);
	await page.screenshot({ path: process.env.QA_SHOTS ? process.env.QA_SHOTS + '/turnstile-page.png' : '/dev/null' }).catch(() => {});

	await setKeys('', '', true);
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-launch`, { waitUntil: 'domcontentloaded' });
	const offText = await admin.locator('#turnstile').locator('xpath=following-sibling::p[1]').innerText();
	check('turning the check off shows Off', /Off\./.test(offText));
	const afterOff = await api('wishlist/code', { email: EMAIL });
	check('with the check off, the sign-in code works without a token', afterOff.status() === 200, String(afterOff.status()));
	const cfOff = await (await cf7({})).json().catch(() => ({}));
	check('with the check off, forms work without a token', cfOff.status === 'mail_sent', String(cfOff.status));

	await browser.close();
	console.log(`\n${results.filter((r) => r.pass).length}/${results.length} passed`);
	process.exit(results.every((r) => r.pass) ? 0 : 1);
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
