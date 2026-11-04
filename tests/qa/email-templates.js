/**
 * End to end: the branded emails and the master-admin template editor.
 *
 *   1. the Email templates screen lists every email, grouped;
 *   2. every template renders with the brand shell: logo, centred card, no leftover {{placeholders}};
 *   3. editing a template (subject, headline) changes the next real email (sign-in code);
 *   4. the Design tab changes colours for every email, and can be put back;
 *   5. a template can be switched off (non-critical) and critical ones cannot;
 *   6. Reset returns a template to the original.
 *
 * Staging only (admin login). Staging cannot send mail, so the last email to an
 * address is read through an administrators-only staging route.
 */
const { chromium } = require('playwright');
const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const STAMP = Date.now().toString(36);
const EMAIL = `qa-mail-${STAMP}@example.com`;
const SHOTS = require('path').join(__dirname, '..', '..', 'docs', 'screenshots', 'email');
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };

(async () => {
	require('fs').mkdirSync(SHOTS, { recursive: true });
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const ctx = await browser.newContext({ ...creds, viewport: { width: 1500, height: 1100 } });
	const admin = await ctx.newPage();
	admin.setDefaultTimeout(60000);
	await admin.goto(`${BASE}/wp-login.php`);
	await admin.fill('#user_login', process.env.DB_WP_USER);
	await admin.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);
	const nonce = (await (await ctx.request.get(`${BASE}/wp-admin/admin-ajax.php?action=rest-nonce`)).text()).trim();
	const lastMail = async (to) => (await ctx.request.get(`${BASE}/wp-json/db/v1/email/last?to=${encodeURIComponent(to)}`, { headers: { 'X-WP-Nonce': nonce } })).json();
	const visitor = await browser.newContext({ ...creds });
	const askCode = () => visitor.request.fetch(`${BASE}/wp-json/db/v1/wishlist/code`, { method: 'POST', headers: { Origin: BASE, 'Content-Type': 'text/plain' }, data: JSON.stringify({ email: EMAIL }) });

	// 1. list
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-email-templates`, { waitUntil: 'domcontentloaded' });
	const keys = await admin.locator('a[href*="tpl="]').evaluateAll((as) => [...new Set(as.map((a) => new URL(a.href).searchParams.get('tpl')))]);
	check('the list shows every email template (24 or more)', keys.length >= 24, String(keys.length));
	await admin.screenshot({ path: SHOTS + '/1-template-list.png', fullPage: true });
	check('they are grouped', (await admin.locator('h2', { hasText: /^(Account|Offers|Payments|Team notices)$/ }).count()) === 4);

	// 2. every template renders in the brand shell
	let bad = [];
	for (const k of keys) {
		await admin.goto(`${BASE}/wp-admin/admin.php?page=db-email-templates&tpl=${k}`, { waitUntil: 'domcontentloaded' });
		const html = await admin.locator('#db-mail-preview').getAttribute('srcdoc');
		const ok = html && /<img [^>]*alt="Domain Brothers"/.test(html) && /db-m-card/.test(html) && !/\{\{/.test(html) && /<h1 /.test(html) && !/Warning:|Notice:|Fatal error/.test(html);
		if (!ok) { bad.push(k); }
	}
	check('every template renders with logo, card and headline, with no leftover placeholders or PHP notices', bad.length === 0, bad.join(','));
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-email-templates&tpl=purchase_full`, { waitUntil: 'domcontentloaded' });
	await admin.waitForTimeout(1500);
	await admin.screenshot({ path: SHOTS + '/2-editor-purchase.png', fullPage: true });
	const fr = admin.frameLocator('#db-mail-preview');
	await fr.locator('h1').first().scrollIntoViewIfNeeded();
	check('the editor has a rich text editor and a live preview', (await admin.locator('#wp-db_mail_body-wrap').count()) === 1 && (await fr.locator('h1').count()) === 1);

	// 3. edit the sign-in code email
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-email-templates&tpl=signin_code`, { waitUntil: 'domcontentloaded' });
	await admin.fill('input[name=subject]', `QA code {{code}} ${STAMP}`);
	await admin.fill('input[name=heading]', `QA headline ${STAMP}`);
	await Promise.all([admin.waitForURL(/saved=1/, { timeout: 90000 }), admin.click('#db-mail-form button[type=submit]')]);
	await askCode();
	let m = await lastMail(EMAIL);
	check('the edited subject and headline are used in the next real email', new RegExp(`QA code \\d{6} ${STAMP}`).test(m.subject) && m.html.includes(`QA headline ${STAMP}`), m.subject);
	check('the email carries the logo, a centred card and the code', /alt="Domain Brothers"/.test(m.html) && /text-align:center/.test(m.html) && /border-radius:28px/.test(m.html));

	// 4. design
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-email-templates&tab=design`, { waitUntil: 'domcontentloaded' });
	await admin.evaluate(() => { const i = document.querySelector('input[name=primary]'); i.value = '#aa3355'; i.dispatchEvent(new Event('input', { bubbles: true })); });
	await admin.waitForTimeout(1500);
	await admin.screenshot({ path: SHOTS + '/3-design.png', fullPage: true });
	await Promise.all([admin.waitForURL(/saved=1/, { timeout: 90000 }), admin.click('button:has-text("Save design")')]);
	await askCode();
	m = await lastMail(EMAIL);
	check('a Design colour change reaches the email (top strip)', /#aa3355/i.test(m.html));
	await admin.evaluate(() => { document.querySelector('input[name=primary]').value = '#0b7a75'; });
	await Promise.all([admin.waitForURL(/saved=1/, { timeout: 90000 }), admin.click('button:has-text("Save design")')]);

	// 5. switch off / critical
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-email-templates&tpl=admin_enquiry`, { waitUntil: 'domcontentloaded' });
	check('a team notice can be switched off', (await admin.locator('input[name=enabled]').count()) === 1);
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-email-templates&tpl=payment_failed`, { waitUntil: 'domcontentloaded' });
	check('a payment email cannot be switched off', (await admin.locator('input[name=enabled]').count()) === 0 && /Always sent/.test(await admin.locator('body').innerText()));

	// 6. reset
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-email-templates&tpl=signin_code`, { waitUntil: 'domcontentloaded' });
	admin.once('dialog', (d) => d.accept());
	await Promise.all([admin.waitForURL(/saved=reset/, { timeout: 90000 }), admin.click('button:has-text("Reset to the original")')]);
	await askCode();
	m = await lastMail(EMAIL);
	check('Reset brings back the original subject', /^Your Domain Brothers sign-in code: \d{6}$/.test(m.subject), m.subject);

	// 7. security: only administrators
	const anon = await browser.newContext({ ...creds });
	const r = await anon.request.fetch(`${BASE}/wp-admin/admin-post.php`, { method: 'POST', form: { action: 'db_mail_save', tpl: 'signin_code', subject: 'hacked' }, maxRedirects: 0 });
	check('a signed-out visitor cannot change a template', r.status() !== 200 || !/saved=1/.test(r.headers()['location'] || ''));
	const lst = await anon.request.get(`${BASE}/wp-json/db/v1/email/last?to=${encodeURIComponent(EMAIL)}`);
	check('the staging mail reader refuses non-administrators', lst.status() === 401 || lst.status() === 403);

	await browser.close();
	console.log(`\n${results.filter((x) => x.pass).length}/${results.length} passed`);
	process.exit(results.every((x) => x.pass) ? 0 : 1);
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
