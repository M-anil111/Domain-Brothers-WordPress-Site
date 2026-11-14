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
	const payRows = await page.$$eval('#db-pay-h ~ .db-tools-scroll tbody tr', (trs) => trs.map((tr) => Array.from(tr.children).map((c) => c.textContent.trim())));
	check('Ways to pay lists pay in full and four plan terms', payRows.length === 5 && /Pay in full/.test(payRows[0][0]), `${payRows.length} rows`);
	if (payRows.length === 5) {
		const full = money(payRows[0][3]), twelve = money(payRows[4][3]);
		check('the 12-month total is 10% over the price', Math.abs(twelve - full * 1.1) < 0.01, `${full} -> ${twelve}`);
		check('the 3-month plan shows no extra cost', /None/.test(payRows[1][4]), payRows[1][4]);
	}

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
	await Promise.all([page.waitForLoadState('domcontentloaded'), page.click(`[data-cmp-remove="${nameA}"]`)]);
	const after = await page.$$eval('.db-tools-compare thead th a', (as) => as.map((a) => a.textContent));
	check('Remove drops one domain from the comparison', after.length === 1 && after[0] === nameB, after.join(', '));

	await page.goto(`${BASE}/compare-domains/?d=${encodeURIComponent('<script>alert(1)</script>,../x,' + nameA + ',' + nameA + ',not-listed-zz9.com')}`, { waitUntil: 'domcontentloaded' });
	const cleaned = await page.$$eval('.db-tools-compare thead th a', (as) => as.map((a) => a.textContent));
	const html = await page.content();
	check('comparison ignores invalid, duplicate and unlisted names', cleaned.length === 1 && cleaned[0] === nameA && !/<script>alert\(1\)/.test(html), cleaned.join(', '));

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
		await admin.goto(`${BASE}/domains/${A}/`, { waitUntil: 'domcontentloaded' });
		const postId = await admin.evaluate(() => { const m = document.body.className.match(/postid-(\d+)/); return m ? m[1] : ''; });
		const nonce = await admin.evaluate(async (b) => { const r = await fetch(b + '/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'include' }); return (await r.text()).trim(); }, BASE);
		const api = async (meta) => admin.evaluate(async ([b, id, n, m]) => { const r = await fetch(`${b}/wp-json/wp/v2/domain/${id}`, { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': n }, body: JSON.stringify({ meta: m }) }); return r.status; }, [BASE, postId, nonce, meta]);
		const before = money(payRows[0] ? payRows[0][3] : '0');
		const st = postId && before > 0 ? await api({ domain_price: String(before - 100) }) : 0;
		if (st === 200) {
			await admin.goto(`${BASE}/domains/${A}/?nocache=${Date.now()}`, { waitUntil: 'domcontentloaded' });
			const hist = await admin.$$eval('#db-ph-h ~ table tbody tr', (trs) => trs.map((tr) => tr.textContent.replace(/\s+/g, ' ').trim()));
			check('a price change appears under Price history', hist.length > 0 && hist[0].includes((before - 100).toLocaleString('en-US')), hist[0] || 'no rows');
			check('a price drop is announced with its percent', /Price reduced \d+%/.test((await admin.textContent('.db-tools-note').catch(() => '')) || ''));
			const back = await api({ domain_price: String(before) });
			check('the test price was restored', back === 200, String(back));
		} else {
			console.log(`SKIP price history (the domain price is not writable through the REST API here: status ${st})`);
		}
	}

	await browser.close();
	const failed = results.filter((r) => !r.pass).length;
	console.log(`\n${results.length - failed}/${results.length} passed`);
	process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
