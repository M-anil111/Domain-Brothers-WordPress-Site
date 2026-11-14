/**
 * Domain tools on staging: ways to pay, compare, recently viewed, price history,
 * registry record.
 *
 *   1. a fixed-price domain page shows "Ways to pay" with pay in full and four terms,
 *      and the 12-month total is 10% over the price;
 *   2. Compare: the button adds the domain, the comparison page lists both domains,
 *      Remove drops one, a fifth domain is refused;
 *   3. the comparison page ignores invalid names and is noindex;
 *   4. Recently viewed lists the first domain on the second domain's page;
 *   5. a price change in wp-admin appears under Price history, then the price is restored;
 *   6. no horizontal overflow at 375px on the domain and comparison pages.
 *
 * Basic Auth: BETA_USER / BETA_PASS. Admin: DB_WP_USER / DB_WP_PASS. Staging only.
 */
const { chromium } = require('playwright');
const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const A = process.env.QA_DOMAIN_A || 'fatality-ca';
const B = process.env.QA_DOMAIN_B || 'aethereum-ca';
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };
const money = (s) => parseFloat(String(s).replace(/[^0-9.]/g, ''));

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const ctx = await browser.newContext({ ...creds, viewport: { width: 1280, height: 900 } });
	const page = await ctx.newPage();
	page.setDefaultTimeout(90000);

	await page.goto(`${BASE}/domains/${A}/`, { waitUntil: 'domcontentloaded' });
	const nameA = await page.getAttribute('[data-db-tools]', 'data-name');
	const payRows = await page.$$eval('.db-pay-table tbody tr', (trs) => trs.map((tr) => Array.from(tr.children).map((c) => c.textContent.trim())));
	check('Ways to pay lists pay in full and four plan terms', payRows.length === 5 && /Pay in full/.test(payRows[0][0]), `${payRows.length} rows`);
	if (payRows.length === 5) {
		const full = money(payRows[0][3]), twelve = money(payRows[4][3]);
		check('the 12-month total is 10% over the price', Math.abs(twelve - full * 1.1) < 0.01, `${full} -> ${twelve}`);
		check('the 3-month plan shows no extra cost', /None/.test(payRows[1][4]), payRows[1][4]);
	}

	const opts = await page.$$('.db-pay-opt');
	check('the payment chooser offers five options', opts.length === 5, String(opts.length));
	if (opts.length === 5) {
		await opts[4].click();
		const go = await page.getAttribute('.db-pay-go', 'href');
		const each = await page.textContent('.db-pay-each');
		check('choosing 12 months points checkout at the 12-month plan', /[?&]months=12\b/.test(go) && /buy-now/.test(go), go);
		check('the chooser shows the 12-month payment', payRows[4] && each.trim() === payRows[4][1].trim(), `${each} vs ${payRows[4] && payRows[4][1]}`);
		await opts[4].focus();
		await page.keyboard.press('ArrowRight');
		check('arrow keys move the choice', (await page.getAttribute('.db-pay-opt:nth-child(1)', 'aria-checked')) === 'true');
	}
	check('the sticky bar is hidden at the top of the page', !(await page.$eval('#db-sticky', (e) => e.classList.contains('is-on')).catch(() => true)));
	await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
	await page.waitForTimeout(600);
	const stickyOn = await page.$eval('#db-sticky', (e) => e.classList.contains('is-on') && !e.hasAttribute('inert')).catch(() => false);
	check('the sticky bar appears once the buy button scrolls away', stickyOn);
	const stickyBuy = await page.getAttribute('#db-sticky .db-sticky-btn:not(.db-sticky-btn--ghost)', 'href').catch(() => '');
	check('the sticky Buy now goes to this domain\'s checkout', /buy-now/.test(stickyBuy) && stickyBuy.includes(encodeURIComponent(Buffer.from(nameA).toString('base64'))), stickyBuy);
	await page.evaluate(() => window.scrollTo(0, 0));

	await page.click('#db-cmp-toggle');
	check('Compare button marks the domain as added', (await page.getAttribute('#db-cmp-toggle', 'aria-pressed')) === 'true');
	await page.goto(`${BASE}/domains/${B}/`, { waitUntil: 'domcontentloaded' });
	const nameB = await page.getAttribute('[data-db-tools]', 'data-name');
	const rv = await page.$$eval('#db-rv-list a', (as) => as.map((a) => a.textContent));
	check('Recently viewed lists the previous domain', rv.includes(nameA), rv.join(', '));
	await page.click('#db-cmp-toggle');
	await Promise.all([page.waitForURL(/compare-domains/), page.click('#db-cmp-open')]);
	const heads = await page.$$eval('.db-tools-compare thead th a', (as) => as.map((a) => a.textContent));
	check('comparison page lists both domains', heads.includes(nameA) && heads.includes(nameB), heads.join(', '));
	const robots = await page.evaluate(async (u) => (await fetch(u)).headers.get('x-robots-tag') || '', page.url());
	check('comparison page is noindex', /noindex/.test(robots), robots);
	await Promise.all([page.waitForURL((u) => !decodeURIComponent(u.toString()).includes(nameA), { waitUntil: 'domcontentloaded' }), page.click(`[data-cmp-remove="${nameA}"]`)]);
	const after = await page.$$eval('.db-tools-compare thead th a', (as) => as.map((a) => a.textContent));
	check('Remove drops one domain from the comparison', after.length === 1 && after[0] === nameB, after.join(', '));

	const hostile = await page.request.get(`${BASE}/compare-domains/?d=${encodeURIComponent('<script>alert(1)</script>,../x,' + nameA)}`, { failOnStatusCode: false });
	const hostileBody = await hostile.text();
	check('script or path input is refused or never echoed', hostile.status() === 403 || !/<script>alert\(1\)/.test(hostileBody), String(hostile.status()));
	await page.goto(`${BASE}/compare-domains/?d=${encodeURIComponent('a b.com,-x.com,' + nameA.toUpperCase() + ',' + nameA + ',not-listed-zz9.com')}`, { waitUntil: 'domcontentloaded' });
	const cleaned = await page.$$eval('.db-tools-compare thead th a', (as) => as.map((a) => a.textContent));
	check('comparison ignores invalid, duplicate and unlisted names', cleaned.length === 1 && cleaned[0] === nameA, cleaned.join(', '));

	await page.evaluate(() => localStorage.setItem('dbCompare', JSON.stringify(['a1.com', 'a2.com', 'a3.com', 'a4.com'])));
	await page.goto(`${BASE}/domains/${A}/`, { waitUntil: 'domcontentloaded' });
	await page.click('#db-cmp-toggle');
	check('a fifth domain is refused', (await page.getAttribute('#db-cmp-toggle', 'aria-pressed')) === 'false' && /Remove one first/.test(await page.textContent('#db-cmp-status')));
	await page.evaluate(() => localStorage.removeItem('dbCompare'));

	const phone = await browser.newContext({ ...creds, viewport: { width: 375, height: 812 } });
	const pp = await phone.newPage();
	for (const u of [`/domains/${A}/`, `/compare-domains/?d=${nameA},${nameB}`]) {
		await pp.goto(BASE + u, { waitUntil: 'domcontentloaded' });
		const over = await pp.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
		check(`no horizontal overflow at 375px on ${u.split('?')[0]}`, over <= 0, `overflow ${over}px`);
	}
	await phone.close();

	if (process.env.DB_WP_USER) {
		const admin = await ctx.newPage();
		admin.setDefaultTimeout(90000);
		await admin.goto(`${BASE}/wp-login.php`);
		await admin.fill('#user_login', process.env.DB_WP_USER);
		await admin.fill('#user_pass', process.env.DB_WP_PASS);
		await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);
		const stamp = Date.now().toString(36);
		const title = `qa-ph-${stamp}.com`;
		await admin.goto(`${BASE}/wp-admin/post-new.php?post_type=domain`, { waitUntil: 'domcontentloaded' });
		await admin.fill('#title', title);
		await admin.fill('input[name=domain_price]', '2500');
		await admin.selectOption('select[name=domain_status]', 'sale');
		await Promise.all([admin.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 90000 }), admin.click('#publish')]);
		const id = Number(new URL(admin.url()).searchParams.get('post'));
		await admin.fill('input[name=domain_price]', '2000');
		await Promise.all([admin.waitForURL(/message=1/, { timeout: 90000 }), admin.click('#publish')]);
		const link = await admin.locator('#sample-permalink a, #view-post-btn a').first().getAttribute('href').catch(() => '');
		const viewer = await ctx.newPage();
		await viewer.goto(`${link || `${BASE}/?p=${id}`}${(link || '').includes('?') ? '&' : '?'}x=${stamp}`, { waitUntil: 'domcontentloaded' });
		const hist = await viewer.$$eval('#db-ph-h ~ table tbody tr', (trs) => trs.map((tr) => tr.textContent.replace(/\s+/g, ' ').trim())).catch(() => []);
		check('a price change appears under Price history', hist.length >= 1 && /\$2,500/.test(hist[0]) && /\$2,000/.test(hist[0]), hist[0] || 'no rows');
		const note = (await viewer.textContent('.db-tools-note').catch(() => '')) || '';
		check('the price drop is announced with its percent', /Price reduced 20%/.test(note), note.slice(0, 80));
		await viewer.close();
		await admin.goto(`${BASE}/wp-admin/post.php?post=${id}&action=edit`, { waitUntil: 'domcontentloaded' });
		const trash = await admin.locator('#delete-action a.submitdelete').getAttribute('href');
		await admin.goto(trash.startsWith('http') ? trash : BASE + trash, { waitUntil: 'domcontentloaded' });
		await admin.goto(`${BASE}/wp-admin/edit.php?post_status=trash&post_type=domain&s=${stamp}`, { waitUntil: 'domcontentloaded' });
		for (const href of await admin.locator('#the-list a.submitdelete').evaluateAll((as) => as.map((a) => a.href))) { await admin.goto(href, { waitUntil: 'domcontentloaded' }); }
		await admin.goto(`${BASE}/wp-admin/edit.php?post_status=all&post_type=domain&s=${stamp}`, { waitUntil: 'domcontentloaded' });
		check('the test listing was deleted', (await admin.locator('#the-list tr', { hasText: stamp }).count()) === 0);
	}

	await browser.close();
	const failed = results.filter((r) => !r.pass).length;
	console.log(`\n${results.length - failed}/${results.length} passed`);
	process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
