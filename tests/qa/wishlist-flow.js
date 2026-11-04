/**
 * End to end: wishlist sign-in by emailed code, saving domains, the wishlist
 * page, Domain Statistics, and the security limits.
 *
 * Staging cannot send email, so the test reads the code it asked for through
 * an administrators-only staging route (/wp-json/db/v1/wishlist/test-code).
 *
 *   1. a temporary domain is added in the admin;
 *   2. a visitor asks for a code; the reply does not say whether the address is known;
 *   3. wrong codes are refused; five wrong guesses burn the code;
 *   4. a new code signs in: the cookie is HttpOnly, Secure, SameSite=Lax;
 *   5. a write without an Origin from this site is refused;
 *   6. "Add to wishlist" on the domain page opens the dialog, signs in and saves;
 *   7. /my-wishlist/ lists the domain, with a Remove button; it is private and noindex;
 *   8. the domain page shows Domain Statistics with "Watching 1";
 *   9. the admin Wishlists screen shows it; Sign out ends the session;
 *  10. everything is removed.
 *
 * Staging only (admin login: DB_WP_USER / DB_WP_PASS).
 */
const { chromium, request } = require('playwright');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const STAMP = Date.now().toString(36);
const DOMAIN = `qa-wish-${STAMP}.com`;
const EMAIL = `qa-wish-${STAMP}@example.com`;
const SHOTS = require('path').join(__dirname, '..', '..', 'docs', 'screenshots', 'wishlist');
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const actx = await browser.newContext({ ...creds, viewport: { width: 1400, height: 1000 } });
	const admin = await actx.newPage();
	admin.setDefaultTimeout(60000);
	await admin.goto(`${BASE}/wp-login.php`);
	await admin.fill('#user_login', process.env.DB_WP_USER);
	await admin.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);

	// 1. a listing to save
	await admin.goto(`${BASE}/wp-admin/post-new.php?post_type=domain`, { waitUntil: 'domcontentloaded' });
	await admin.fill('#title', DOMAIN);
	await admin.fill('input[name=domain_price]', '2500');
	await admin.selectOption('select[name=domain_status]', 'sale');
	await Promise.all([admin.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 90000 }), admin.click('#publish')]);
	const postId = Number(new URL(admin.url()).searchParams.get('post'));
	const link = await admin.locator('#sample-permalink a, #view-post-btn a').first().getAttribute('href');

	let restNonce = '';
	const testCode = async () => {
		if (!restNonce) { restNonce = (await (await actx.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim(); }
		const r = await actx.request.get(`${BASE}/wp-json/db/v1/wishlist/test-code?email=${encodeURIComponent(EMAIL)}`, { headers: { 'X-WP-Nonce': restNonce } });
		return r.ok() ? (await r.json()).code : '';
	};

	const visitor = await browser.newContext({ ...creds, viewport: { width: 1280, height: 900 } });
	const api = (path, method, body, headers = {}) => visitor.request.fetch(`${BASE}/wp-json/db/v1/wishlist/${path}`, { method, headers: { Origin: BASE, 'Content-Type': 'text/plain', ...headers }, data: body ? JSON.stringify(body) : undefined });

	// 2. ask for a code: same reply for any address
	const unknown = await (await api('code', 'POST', { email: `nobody-${STAMP}@example.com` })).json();
	const ours = await api('code', 'POST', { email: EMAIL });
	const oursJ = await ours.json();
	check('asking for a code gives the same reply for any address', unknown.ok === true && oursJ.ok === true && unknown.message === oursJ.message);
	const bad = await api('code', 'POST', { email: 'not-an-email' });
	check('an invalid address is refused', bad.status() === 400);
	const noOrigin = await visitor.request.fetch(`${BASE}/wp-json/db/v1/wishlist/code`, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, data: JSON.stringify({ email: EMAIL }) });
	check('a request with no Origin from this site is refused', noOrigin.status() === 403);

	// 3. wrong codes
	const code = await testCode();
	check('the staging test route returns a 6-digit code', /^\d{6}$/.test(code), code ? 'ok' : 'empty');
	const wrong = code === '000000' ? '111111' : '000000';
	let burned = false;
	for (let i = 0; i < 5; i++) { await api('verify', 'POST', { email: EMAIL, code: wrong }); }
	const afterFive = await api('verify', 'POST', { email: EMAIL, code });
	burned = afterFive.status() === 400;
	check('five wrong guesses burn the code (the right one then fails)', burned);

	// 4. a new code signs in
	await api('code', 'POST', { email: EMAIL });
	const code2 = await testCode();
	const okRes = await api('verify', 'POST', { email: EMAIL, code: code2, post: postId });
	const okJ = await okRes.json();
	check('the new code signs in and saves the domain', okJ.ok === true && okJ.saved.includes(postId));
	const cookies = (await visitor.cookies()).filter((c) => c.name === 'db_wl');
	check('session cookie is HttpOnly, Secure and SameSite=Lax', cookies.length === 1 && cookies[0].httpOnly && cookies[0].secure && cookies[0].sameSite === 'Lax', JSON.stringify(cookies.map((c) => ({ h: c.httpOnly, s: c.secure, ss: c.sameSite }))));
	const reuse = await api('verify', 'POST', { email: EMAIL, code: code2 });
	check('a code works only once', reuse.status() === 400);

	// 5. write without Origin
	const noOriginToggle = await visitor.request.fetch(`${BASE}/wp-json/db/v1/wishlist/toggle`, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, data: JSON.stringify({ post: postId }) });
	check('saving or removing without an Origin from this site is refused', noOriginToggle.status() === 403);
	const anon = await request.newContext({ ...creds });
	const anonToggle = await anon.fetch(`${BASE}/wp-json/db/v1/wishlist/toggle`, { method: 'POST', headers: { Origin: BASE, 'Content-Type': 'text/plain' }, data: JSON.stringify({ post: postId }) });
	check('saving without signing in is refused (401)', anonToggle.status() === 401);

	// 6. the button and dialog, in a fresh browser
	const ui = await browser.newContext({ ...creds, viewport: { width: 1280, height: 1000 } });
	const page = await ui.newPage();
	page.setDefaultTimeout(60000);
	await page.goto(link, { waitUntil: 'load' });
	const btn = page.locator('.db-wl-btn');
	await btn.waitFor();
	check('domain page has "Add to wishlist"', /add to wishlist/i.test(await btn.innerText()));
	await page.locator('.db-wl-wrap').scrollIntoViewIfNeeded();
	await page.screenshot({ path: SHOTS + '/0-domain-page-button.png' });
	await btn.click();
	await page.waitForSelector('.db-wl-dialog[open]');
	await page.screenshot({ path: SHOTS + '/1-sign-in-dialog.png' });
	await page.fill('.db-wl-dialog input[name=email]', EMAIL);
	await page.click('.db-wl-dialog [data-step=email] button[type=submit]');
	await page.waitForSelector('.db-wl-dialog [data-step=code]:not([hidden])');
	await page.screenshot({ path: SHOTS + '/2-enter-code.png' });
	const code3 = await testCode();
	await page.fill('.db-wl-dialog input[name=code]', code3);
	await page.click('.db-wl-dialog [data-step=code] button[type=submit]');
	await page.waitForSelector('.db-wl-toast');
	check('the dialog signs in and shows "Saved to your wishlist"', /Saved to your wishlist|signed in/i.test(await page.locator('.db-wl-toast').innerText()));
	check('the button now reads "Saved to wishlist"', /saved to wishlist/i.test(await btn.innerText()));

	// 7. the wishlist page
	const resp = await page.goto(`${BASE}/my-wishlist/`, { waitUntil: 'load' });
	await page.screenshot({ path: SHOTS + '/3-my-wishlist.png' });
	const text = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
	check('/my-wishlist/ lists the domain with its price', resp.status() === 200 && text.includes(DOMAIN) && /\$2,500/.test(text) && new RegExp(EMAIL).test(text));
	check('the wishlist page is private and noindex', /no-store|private/.test(resp.headers()['cache-control'] || '') && /noindex/.test(resp.headers()['x-robots-tag'] || ''));

	// 8. Domain Statistics
	await page.goto(link + (link.includes('?') ? '&' : '?') + 'nc=' + STAMP, { waitUntil: 'load' });
	await page.locator('.db-stats').first().scrollIntoViewIfNeeded().catch(() => {});
	await page.screenshot({ path: SHOTS + '/4-domain-statistics.png' });
	const stats = (await page.locator('.db-stats').first().innerText().catch(() => '')).replace(/\s+/g, ' ');
	check('the domain page shows Domain Statistics with Watching 1', /Domain Statistics/.test(stats) && /1\s*Watching/.test(stats), stats.slice(0, 120));

	// 9. admin view, then removal and sign out
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-wishlists`, { waitUntil: 'domcontentloaded' });
	check('the admin Wishlists screen lists it', (await admin.locator('tr', { hasText: DOMAIN }).count()) === 1);
	await page.goto(`${BASE}/my-wishlist/`, { waitUntil: 'load' });
	await Promise.all([page.waitForLoadState('load'), page.click('[data-wl-remove]')]);
	await page.waitForTimeout(1500);
	await page.goto(`${BASE}/my-wishlist/`, { waitUntil: 'load' });
	check('Remove takes it off the wishlist', /Nothing saved yet/.test(await page.locator('body').innerText()));
	await page.click('#db-wl-signout');
	await page.waitForSelector('#db-wl-signin', { timeout: 30000 }).catch(() => {});
	check('Sign out ends the session', await page.locator('#db-wl-signin').count() === 1);

	// 10. clean up (test domain and its rows)
	await admin.goto(`${BASE}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
	const trash = await admin.locator('#delete-action a.submitdelete').getAttribute('href');
	await admin.goto(trash.startsWith('http') ? trash : BASE + trash, { waitUntil: 'domcontentloaded' });
	await admin.goto(`${BASE}/wp-admin/edit.php?post_status=trash&post_type=domain&s=${STAMP}`, { waitUntil: 'domcontentloaded' });
	for (const href of await admin.locator('#the-list a.submitdelete').evaluateAll((as) => as.map((a) => a.href))) { await admin.goto(href, { waitUntil: 'domcontentloaded' }); }
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-wishlists`, { waitUntil: 'domcontentloaded' });
	check('test domain and its wishlist rows are gone', (await admin.locator('tr', { hasText: DOMAIN }).count()) === 0);

	await browser.close();
	console.log(`\n${results.filter((r) => r.pass).length}/${results.length} passed`);
	process.exit(results.every((r) => r.pass) ? 0 : 1);
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
