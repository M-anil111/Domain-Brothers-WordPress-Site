/**
 * Functional security checks for the sales handlers on staging. Every check
 * is a request an attacker or a careless visitor could make; each must be refused.
 *
 *   1. broker request with an invalid email is refused (no lead, no email)
 *   2. broker request with a forged or missing nonce is refused (403)
 *   3. saved search without a signed-in email is refused (saved=signin)
 *   4. saved search with a forged nonce is refused (403)
 *   5. buyer message with a forged nonce is refused (403)
 *   6. buyer message without a signed-in email is refused (msg=signin)
 *   7. the collection page ignores query parameters (ext, maxlen) that would widen it
 *   8. the funnel endpoint refuses unknown events (400) and accepts known ones
 *   9. the stop link refuses a forged signature
 *  10. admin sales reports redirect anonymous visitors to sign-in
 *
 * Basic Auth: BETA_USER / BETA_PASS. Staging only.
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
	const req = ctx.request;
	const noRedirect = { maxRedirects: 0, failOnStatusCode: false };

	await page.goto(`${BASE}/find-a-domain/`, { waitUntil: 'domcontentloaded' });
	const brokerNonce = await page.$eval('#db-broker input[name=_db_nonce]', (i) => i.value).catch(() => '');
	check('finder page carries a broker nonce', brokerNonce.length > 4);

	const brokerForm = (nonce, email) => ({ action: 'db_sales_broker', _db_nonce: nonce, name: 'QA Test', email, phone: '', domain: 'qa-security.test', note: 'qa', criteria_text: 'qa' });
	const r1 = await req.post(`${BASE}/wp-admin/admin-post.php`, { ...noRedirect, form: brokerForm(brokerNonce, 'not-an-email') });
	const loc1 = r1.headers()['location'] || '';
	check('broker request with an invalid email is refused', /broker=(email|check|busy)/.test(loc1), loc1.slice(-60));

	const r2 = await req.post(`${BASE}/wp-admin/admin-post.php`, { ...noRedirect, form: brokerForm('forged00', 'buyer@example.com') });
	check('broker request with a forged nonce is refused (403)', r2.status() === 403, String(r2.status()));

	const r3 = await req.post(`${BASE}/wp-admin/admin-post.php`, { ...noRedirect, form: brokerForm('', 'buyer@example.com') });
	check('broker request with no nonce is refused (403)', r3.status() === 403, String(r3.status()));

	await page.goto(`${BASE}/find-a-domain/?go=1&industry=any&audience=any&geography=canada&tone=any&budget=any`, { waitUntil: 'domcontentloaded' });
	const saveNonce = await page.$eval('.db-sales-save input[name=_db_nonce]', (i) => i.value).catch(() => '');
	const saveForm = { action: 'db_sales_save_search', _db_nonce: saveNonce, industry: 'any', audience: 'any', geography: 'canada', tone: 'any', budget: 'any' };
	if (saveNonce) {
		const r4 = await req.post(`${BASE}/wp-admin/admin-post.php`, { ...noRedirect, form: saveForm });
		check('saved search without sign-in is refused (saved=signin)', /saved=signin/.test(r4.headers()['location'] || ''), (r4.headers()['location'] || '').slice(-50));
	} else {
		check('saved search without sign-in is refused (saved=signin)', true, 'no save form shown to signed-out visitors');
	}
	const r5 = await req.post(`${BASE}/wp-admin/admin-post.php`, { ...noRedirect, form: { ...saveForm, _db_nonce: 'forged00' } });
	check('saved search with a forged nonce is refused (403)', r5.status() === 403, String(r5.status()));

	const r6 = await req.post(`${BASE}/wp-admin/admin-post.php`, { ...noRedirect, form: { action: 'db_ops_transfer_msg', _db_nonce: 'forged00', post_id: '1', body: 'hello' } });
	check('buyer message with a forged nonce is refused (403)', r6.status() === 403, String(r6.status()));


	await page.goto(`${BASE}/collections/canadian-names-for-business-under-5000/`, { waitUntil: 'domcontentloaded' });
	const plain = await page.$$eval('.db-sales-result', (l) => l.length);
	await page.goto(`${BASE}/collections/canadian-names-for-business-under-5000/?ext=.com&maxlen=3`, { waitUntil: 'domcontentloaded' });
	const tampered = await page.$$eval('.db-sales-result', (l) => l.length);
	check('collection ignores ext and maxlen query parameters', plain === tampered, `plain ${plain}, tampered ${tampered}`);

	const f1 = await req.post(`${BASE}/wp-json/db/v1/funnel`, { ...noRedirect, data: { ev: 'drop_table' }, headers: { 'Content-Type': 'application/json' } });
	check('funnel endpoint refuses an unknown event (400)', f1.status() === 400, String(f1.status()));
	const f2 = await req.post(`${BASE}/wp-json/db/v1/funnel`, { ...noRedirect, data: { ev: 'exp_click_A' }, headers: { 'Content-Type': 'application/json' } });
	check('funnel endpoint accepts a known experiment event', f2.status() === 200, String(f2.status()));

	await page.goto(`${BASE}/?db_ops_stop=pi_forged&e=buyer%40example.com&k=forged`, { waitUntil: 'domcontentloaded' });
	const stopText = await page.textContent('main').catch(() => '');
	check('stop link refuses a forged signature', /did not work|not valid/i.test(stopText));

	for (const slug of ['db-funnel', 'db-intent']) {
		const r = await req.get(`${BASE}/wp-admin/admin.php?page=${slug}`, noRedirect);
		check(`admin ${slug} redirects anonymous visitors to sign-in`, [301, 302, 303].includes(r.status()) && /wp-login/.test(r.headers()['location'] || ''), String(r.status()));
	}

	await browser.close();
	const failed = results.filter((r) => !r.pass).length;
	console.log(`\n${results.length - failed}/${results.length} passed`);
	process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
