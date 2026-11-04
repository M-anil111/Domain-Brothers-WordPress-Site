/**
 * End to end: the signed-in account (alerts, My offers, Discover) and the
 * emails to people following a domain.
 *
 *   1. two temporary domains are added in the admin (A: priced, B: open to offers);
 *   2. a follower signs in with an emailed code and saves A; an offerer signs in;
 *   3. the admin lowers A's price: the follower gets a price alert;
 *   4. the offerer makes an offer on A from their account: the follower gets a
 *      plain "new offer" alert (no amount, no name); the offerer does not;
 *   5. My offers lists the offer for the offerer only; a sold or unknown domain is refused;
 *   6. the admin marks A sold: the follower gets a "sold" alert;
 *   7. the email batch builds ONE email with all three, a stop link and no amounts of offers;
 *      the stop link switches emails off, and only for that address;
 *   8. Alerts, My offers and Discover (recently listed) render, Alerts are marked read;
 *   9. everything is removed.
 *
 * Staging only (admin login: DB_WP_USER / DB_WP_PASS). Staging cannot send mail,
 * so the email is read through an administrators-only staging route.
 */
const { chromium } = require('playwright');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const STAMP = Date.now().toString(36);
const A = `qa-acct-a-${STAMP}.com`;
const B = `qa-acct-b-${STAMP}.com`;
const FOLLOWER = `qa-acct-follower-${STAMP}@example.com`;
const OFFERER = `qa-acct-offerer-${STAMP}@example.com`;
const SHOTS = require('path').join(__dirname, '..', '..', 'docs', 'screenshots', 'account');
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };

(async () => {
	require('fs').mkdirSync(SHOTS, { recursive: true });
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const actx = await browser.newContext({ ...creds, viewport: { width: 1400, height: 1000 } });
	const admin = await actx.newPage();
	admin.setDefaultTimeout(60000);
	await admin.goto(`${BASE}/wp-login.php`);
	await admin.fill('#user_login', process.env.DB_WP_USER);
	await admin.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);

	const addDomain = async (title, price) => {
		await admin.goto(`${BASE}/wp-admin/post-new.php?post_type=domain`, { waitUntil: 'domcontentloaded' });
		await admin.fill('#title', title);
		if (price) { await admin.fill('input[name=domain_price]', String(price)); }
		await admin.selectOption('select[name=domain_status]', 'sale');
		await Promise.all([admin.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 90000 }), admin.click('#publish')]);
		return Number(new URL(admin.url()).searchParams.get('post'));
	};
	const idA = await addDomain(A, 2500);
	const idB = await addDomain(B, 0);

	let restNonce = '';
	const nonce = async () => restNonce || (restNonce = (await (await actx.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim());
	const testCode = async (email) => { const r = await actx.request.get(`${BASE}/wp-json/db/v1/wishlist/test-code?email=${encodeURIComponent(email)}`, { headers: { 'X-WP-Nonce': await nonce() } }); return r.ok() ? (await r.json()).code : ''; };
	const testMail = async (email, run) => { const r = await actx.request.get(`${BASE}/wp-json/db/v1/account/test-mail?email=${encodeURIComponent(email)}${run ? '&run=1' : ''}`, { headers: { 'X-WP-Nonce': await nonce() } }); return r.ok() ? (await r.json()) : { mail: '' }; };

	const signIn = async (email, post) => {
		const ctx = await browser.newContext({ ...creds, viewport: { width: 1280, height: 1000 } });
		const call = (path, body) => ctx.request.fetch(`${BASE}/wp-json/db/v1/${path}`, { method: 'POST', headers: { Origin: BASE, 'Content-Type': 'text/plain' }, data: JSON.stringify(body || {}) });
		await call('wishlist/code', { email });
		const code = await testCode(email);
		const v = await (await call('wishlist/verify', { email, code, post: post || 0 })).json();
		return { ctx, call, ok: v.ok === true };
	};

	// 2. follower saves A; offerer signs in
	const f = await signIn(FOLLOWER, idA);
	const o = await signIn(OFFERER, 0);
	check('follower signs in and saves the domain; offerer signs in', f.ok && o.ok);

	// 3. price drop
	await admin.goto(`${BASE}/wp-admin/post.php?post=${idA}&action=edit`, { waitUntil: 'domcontentloaded' });
	await admin.fill('input[name=domain_price]', '1800');
	await Promise.all([admin.waitForURL(/message=1/, { timeout: 90000 }), admin.click('#publish')]);
	const page = await f.ctx.newPage();
	page.setDefaultTimeout(60000);
	await page.goto(`${BASE}/my-wishlist/?tab=alerts&x=${STAMP}`, { waitUntil: 'load' });
	let text = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
	check('a price change alerts the follower, with old and new price', /dropped from \$2,500 to \$1,800/.test(text) && text.includes(A), text.slice(0, 160));

	// 4. an offer from another account
	const bad1 = await (await o.call('account/offer', { domain: B + '.nope', amount: 100 })).json();
	check('an unknown domain is refused', bad1.ok === false);
	const bad2 = await (await o.call('account/offer', { domain: A, amount: 'abc' })).json();
	check('an offer without an amount is refused', bad2.ok === false);
	const anon = await browser.newContext({ ...creds });
	const noAuth = await anon.request.fetch(`${BASE}/wp-json/db/v1/account/offer`, { method: 'POST', headers: { Origin: BASE, 'Content-Type': 'text/plain' }, data: JSON.stringify({ domain: A, amount: 100 }) });
	check('making an offer while signed out is refused (401)', noAuth.status() === 401);
	const noOrigin = await o.ctx.request.fetch(`${BASE}/wp-json/db/v1/account/offer`, { method: 'POST', headers: { 'Content-Type': 'text/plain' }, data: JSON.stringify({ domain: A, amount: 100 }) });
	check('an offer without an Origin from this site is refused (403)', noOrigin.status() === 403);
	const made = await (await o.call('account/offer', { domain: A, amount: '1200', message: 'qa account flow' })).json();
	check('the offerer can make an offer from their account', made.ok === true, JSON.stringify(made).slice(0, 120));

	// 5. alerts and My offers
	await page.goto(`${BASE}/my-wishlist/?tab=alerts&x=${STAMP}b`, { waitUntil: 'load' });
	await page.screenshot({ path: SHOTS + '/1-alerts.png', fullPage: true });
	text = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
	check('the follower gets a plain new-offer alert (no amount, no name)', /A new offer was made on /.test(text) && !/1,?200/.test(text) && !text.includes(OFFERER));
	const op = await o.ctx.newPage();
	op.setDefaultTimeout(60000);
	await op.goto(`${BASE}/my-wishlist/?tab=alerts&x=${STAMP}c`, { waitUntil: 'load' });
	const otext = (await op.locator('body').innerText()).replace(/\s+/g, ' ');
	check('the person who made the offer is not alerted about it', !/A new offer was made on /.test(otext));
	await op.goto(`${BASE}/my-wishlist/?tab=offers&x=${STAMP}`, { waitUntil: 'load' });
	await op.screenshot({ path: SHOTS + '/2-my-offers.png', fullPage: true });
	const offersText = (await op.locator('body').innerText()).replace(/\s+/g, ' ');
	check('My offers lists the offer with its amount and status', offersText.includes(A) && /\$1,200/.test(offersText) && /Waiting for us/.test(offersText));
	check('My offers links to the negotiation', (await op.locator('.db-ac-deal a.db-ac-btn').first().getAttribute('href') || '').includes('c='));
	await page.goto(`${BASE}/my-wishlist/?tab=offers&x=${STAMP}`, { waitUntil: 'load' });
	check('another account does not see that offer', !(await page.locator('body').innerText()).includes('$1,200'));

	// 6. sold
	await admin.goto(`${BASE}/wp-admin/post.php?post=${idA}&action=edit`, { waitUntil: 'domcontentloaded' });
	await admin.check('input[name=db_listing_state][value=sold]');
	await Promise.all([admin.waitForURL(/message=1/, { timeout: 90000 }), admin.click('#publish')]);
	await page.goto(`${BASE}/my-wishlist/?tab=alerts&x=${STAMP}d`, { waitUntil: 'load' });
	text = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
	check('a sale alerts the follower', /has been sold/.test(text));

	// 7. one email, with a stop link
	const run = await testMail(FOLLOWER, true);
	const mail = run.mail || '';
	check('one email is built with the price change, the new offer and the sale', /dropped from \$2,500 to \$1,800/.test(mail) && /A new offer was made on/.test(mail) && /has been sold/.test(mail), mail.split('\n')[0]);
	check('the email does not give the offer amount or the offerer', !/1,?200/.test(mail) && !mail.includes(OFFERER));
	const stop = (mail.match(/Stop these emails: (\S+)/) || [])[1] || '';
	check('the email has a stop link for this address', /alerts=off/.test(stop) && stop.includes('s='));
	const sp = await anon.newPage();
	const stopResp = await sp.goto(stop.replace(/&amp;/g, '&'), { waitUntil: 'load' });
	check('the stop link switches emails off without signing in', /Alert emails are off/.test(await sp.locator('body').innerText()));
	const forged = await sp.goto(stop.replace(/s=[0-9a-f]+/, 's=' + '0'.repeat(64)).replace(encodeURIComponent(FOLLOWER), encodeURIComponent(OFFERER)), { waitUntil: 'load' });
	check('a stop link cannot be used for another address', /That link did not work/.test(await sp.locator('body').innerText()));
	await page.goto(`${BASE}/my-wishlist/?tab=alerts&x=${STAMP}e`, { waitUntil: 'load' });
	check('the Alerts tab shows the email switch as off', !(await page.locator('#db-ac-emails').isChecked()));
	await page.check('#db-ac-emails');
	await page.waitForSelector('#db-ac-prefmsg.is-good');
	check('turning the email switch back on works', /We will email you/.test(await page.locator('#db-ac-prefmsg').innerText()));

	// 8. Discover, read marks
	await page.goto(`${BASE}/my-wishlist/?tab=discover&x=${STAMP}`, { waitUntil: 'load' });
	await page.screenshot({ path: SHOTS + '/3-discover.png', fullPage: true });
	const dtext = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
	check('Discover lists the new domain under Recently listed (and not the sold one)', /Recently listed/.test(dtext) && dtext.includes(B) && !dtext.includes(A));
	check('Discover has a Hot domains section', /Hot domains/.test(dtext));
	await page.locator(`[data-ac-save="${idB}"]`).click();
	await page.waitForFunction((id) => document.querySelector(`[data-ac-save="${id}"]`).textContent.trim() === 'Saved', idB);
	check('Save on a Discover card adds it to the wishlist', true);
	await page.goto(`${BASE}/my-wishlist/?tab=offers&domain=${B}&x=${STAMP}`, { waitUntil: 'load' });
	check('Make an offer from a card pre-fills the domain', (await page.inputValue('#db-ac-offer input[name=domain]')) === B);
	await page.goto(`${BASE}/my-wishlist/?tab=alerts&x=${STAMP}f`, { waitUntil: 'load' });
	await page.waitForTimeout(1500);
	await page.goto(`${BASE}/my-wishlist/?x=${STAMP}g`, { waitUntil: 'load' });
	check('opening Alerts marks them read (no unread badge left)', (await page.locator('.db-ac-n--new').count()) === 0);
	await page.screenshot({ path: SHOTS + '/0-wishlist-tabs.png', fullPage: true });
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-wishlists`, { waitUntil: 'domcontentloaded' });
	check('the admin Wishlists screen lists the alerts and their email status', (await admin.locator('tr', { hasText: A }).count()) >= 1 && /Alerts to followers/.test(await admin.locator('body').innerText()));

	// 9. clean up
	for (const id of [idA, idB]) {
		await admin.goto(`${BASE}/wp-admin/post.php?post=${id}&action=edit`, { waitUntil: 'domcontentloaded' });
		const trash = await admin.locator('#delete-action a.submitdelete').getAttribute('href');
		await admin.goto(trash.startsWith('http') ? trash : BASE + trash, { waitUntil: 'domcontentloaded' });
	}
	await admin.goto(`${BASE}/wp-admin/edit.php?post_status=trash&post_type=domain&s=${STAMP}`, { waitUntil: 'domcontentloaded' });
	for (const href of await admin.locator('#the-list a.submitdelete').evaluateAll((as) => as.map((a) => a.href))) { await admin.goto(href, { waitUntil: 'domcontentloaded' }); }
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-wishlists`, { waitUntil: 'domcontentloaded' });
	check('test domains and their alerts are gone', (await admin.locator('tr', { hasText: STAMP }).count()) === 0);

	await browser.close();
	console.log(`\n${results.filter((r) => r.pass).length}/${results.length} passed`);
	process.exit(results.every((r) => r.pass) ? 0 : 1);
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
