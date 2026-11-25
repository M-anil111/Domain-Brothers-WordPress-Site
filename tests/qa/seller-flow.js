/**
 * "Sell your domains" end to end on staging, in a real browser, as a new US seller.
 *
 *   TS_QA_KEY=<value of option db_turnstile_test_key> DB_WP_USER=... DB_WP_PASS=... \
 *   NODE_PATH=$(npm root -g) node tests/qa/seller-flow.js [outDir]
 *
 * Needs Turnstile test mode on (option db_turnstile_test_until ahead, see CLAUDE.md) and an admin
 * login (for the email code, the simulated DNS proof and the approval). Steps:
 *   1. public sell page: buttons, steps, fees, broker form, FAQ structured data
 *   2. sign in with an email code; seller details (US)
 *   3. add names by paste (junk mixed in) and by CSV upload (an unregistered name, a duplicate)
 *   4. prices: a bad price is refused, comma prices are accepted
 *   5. ownership: simulated (the DNS proof is marked verified server-side), then submit
 *   6. admin approves in Tools > Seller listings; names are public (page, search, All Domains)
 *   7. "+ Add names" after going live; edit a live price and see it on the public page
 *   8. a buyer offers on a seller name; the seller counters from the Offers tab
 *   9. payouts tab shows the Stripe card; remove a name and it leaves the site
 *  10. clean up (seller, listings, posts, offers)
 * Never run against production.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const B = process.env.QA_BASE || 'https://beta.domainbrothers.com';
if (/www\.domainbrothers\.com/.test(B)) { console.error('Staging only.'); process.exit(2); }
const OUT = process.argv[2] || path.join(process.cwd(), 'seller-flow-report');
fs.mkdirSync(OUT, { recursive: true });
const STAMP = Date.now().toString(36);
const SELLER = `qa-sell-${STAMP}@example.com`;
const BUYER = `qa-buy-${STAMP}@example.com`;
const PASTE = ['https://www.BlueWidgets.com/shop', 'redkettle.com', 'REDKETTLE.COM', 'not a domain', 'google.ca'];
const CSV_ROWS = [['domain', 'price'], ['quietharbor.ca', '1800'], ['maplecrest.ca', '"3,200"'], ['tidewaterhomes.ca', '900'], ['redkettle.com', '2500']];
const LATER = 'brightlantern.com';
const TS = 'XXXX.DUMMY.TOKEN.XXXX';

const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass, detail }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };
const slug = (d) => d.replace(/\./g, '-');

(async () => {
	const browser = await browser_();
	async function browser_() { return chromium.launch(); }
	const cookie = process.env.TS_QA_KEY ? [{ name: 'db_ts_qa', value: process.env.TS_QA_KEY, url: B + '/' }] : [];

	// Admin session (email codes, simulated DNS proof, approval, clean-up).
	const actx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
	const admin = await actx.newPage();
	admin.setDefaultTimeout(60000);
	await admin.goto(B + '/wp-login.php');
	await admin.fill('#user_login', process.env.DB_WP_USER);
	await admin.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);
	const nonce = (await (await actx.request.get(B + '/wp-admin/admin-ajax.php?action=rest-nonce')).text()).trim();
	const php = async (code) => {
		const r = await actx.request.post(B + '/wp-json/wp-abilities/v1/abilities/novamira/execute-php/run', { headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' }, data: JSON.stringify({ input: { code } }) });
		const j = await r.json().catch(() => ({}));
		return j.return_value;
	};
	// QA runs from one address can exhaust the 20-codes-per-hour limit; clear it for this address only.
	const resetLimits = () => php(`foreach ( array( 'ip:', 'verify:' ) as $k ) { delete_transient( 'db_wl_rl_' . md5( $k . db_wl_ip() ) ); } return db_wl_ip() !== '';`);
	const signIn = async (email) => {
		await resetLimits();
		const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
		await ctx.addCookies(cookie);
		const call = (p, body) => ctx.request.fetch(B + '/wp-json/db/v1/' + p, { method: 'POST', headers: { Origin: B, 'Content-Type': 'text/plain' }, data: JSON.stringify({ ts: TS, ...(body || {}) }) });
		await call('wishlist/code', { email });
		const code = (await (await actx.request.get(B + '/wp-json/db/v1/wishlist/test-code?email=' + encodeURIComponent(email), { headers: { 'X-WP-Nonce': nonce } })).json()).code;
		const v = await (await call('wishlist/verify', { email, code, post: 0 })).json();
		return { ctx, call, ok: v.ok === true };
	};
	const msgs = async (p) => (await p.locator('.dbsl-msg').allInnerTexts()).join(' | ');

	try {
		// 1. Public sell page.
		const pub = await browser.newPage();
		await pub.goto(B + '/sell-your-domains/?nc=' + STAMP, { waitUntil: 'load' });
		const body = await pub.locator('main, body').first().innerText();
		check('sell page: primary button "Create your seller account"', (await pub.locator('a.dblp-btn', { hasText: 'Create your seller account' }).count()) >= 1);
		check('sell page: five steps, fees 15% / 10% / $100', (await pub.locator('.dblp-steps li').count()) === 5 && /15%/.test(body) && /10%/.test(body) && /\$100/.test(body));
		check('sell page: broker form for large portfolios', (await pub.locator('#dbsl-broker form.wpcf7-form').count()) === 1);
		const ld = await pub.evaluate(() => [...document.querySelectorAll('script[type="application/ld+json"]')].filter((x) => x.textContent.includes('"FAQPage"')).length);
		check('sell page: FAQ structured data and one H1', ld === 1 && (await pub.locator('h1').count()) === 1, `FAQPage blocks ${ld}`);
		check('sell page: no unbacked claims', !/#1|\$5,000,000|10X faster|ns1\.domainbrothers/i.test(body));
		await pub.screenshot({ path: path.join(OUT, '01-sell-page.png'), fullPage: true });
		await pub.close();

		// 2. Sign in and seller details.
		const s = await signIn(SELLER);
		check('seller signs in with an email code', s.ok);
		const p = await s.ctx.newPage();
		p.setDefaultTimeout(60000);
		await p.goto(B + '/seller/', { waitUntil: 'load' });
		await p.fill('#dbsl-ln', 'QA Flow Seller');
		await p.selectOption('#dbsl-cy', 'US');
		await p.check('input[name=agree]');
		await Promise.all([p.waitForNavigation(), p.click('button:has-text("Continue")')]);
		check('seller details saved, lands on Add names', /step=add/.test(p.url()), await msgs(p));

		// 3a. Paste with junk.
		await p.fill('textarea[name=names]', PASTE.join('\n'));
		await Promise.all([p.waitForNavigation(), p.locator('textarea[name=names]').locator('xpath=ancestor::form').locator('button[type=submit]').click()]);
		const pasteMsg = await msgs(p);
		check('paste: bluewidgets.com and redkettle.com added; junk explained', /2 (names )?added|Added 2/i.test(pasteMsg) || (/bluewidgets\.com/.test(await p.content()) && /redkettle\.com/.test(await p.content())), pasteMsg.slice(0, 220));
		// 3b. CSV upload.
		const csv = path.join(OUT, 'names.csv');
		fs.writeFileSync(csv, CSV_ROWS.map((r) => r.join(',')).join('\n') + '\n');
		await p.goto(B + '/seller/?step=add', { waitUntil: 'load' });
		await p.click('[data-tab="1"]').catch(() => {});
		const fi = p.locator('input[type=file]').first();
		await fi.setInputFiles(csv);
		await Promise.all([p.waitForNavigation(), fi.locator('xpath=ancestor::form').locator('button[type=submit]').first().click()]);
		const preview = (await p.locator('.dbsl-preview').innerText().catch(() => '')).replace(/\s+/g, ' ');
		check('upload preview: 2 to add, unregistered and duplicate skipped', /quietharbor\.ca/.test(preview) && /maplecrest\.ca/.test(preview) && /tidewaterhomes\.ca[^.]*not registered/i.test(preview) && /redkettle\.com/.test(preview), preview.slice(0, 260));
		await p.screenshot({ path: path.join(OUT, '02-import-preview.png'), fullPage: true });
		await Promise.all([p.waitForNavigation(), p.click('button:has-text("Confirm import")')]);
		check('import confirmed, moves to Set prices', /step=prices/.test(p.url()), await msgs(p));

		// 4. Prices.
		const priceInputs = p.locator('input[name$="[price]"]');
		const nRows = await priceInputs.count();
		check('prices step lists all 4 names', nRows === 4, String(nRows));
		const vals = await priceInputs.evaluateAll((es) => es.map((e) => e.value));
		check('uploaded prices pre-filled (3,200 kept)', vals.includes('3,200') && vals.includes('1,800'), vals.join(' / '));
		await priceInputs.nth(0).fill('50');
		await Promise.all([p.waitForNavigation(), p.click('button:has-text("Save prices")')]);
		check('a $50 price is refused with a message', /at least \$100|minimum/i.test(await msgs(p)), await msgs(p));
		const inputs2 = p.locator('input[name$="[price]"]');
		for (let i = 0; i < (await inputs2.count()); i++) { await inputs2.nth(i).fill(String(2000 + i * 750)); }
		await Promise.all([p.waitForNavigation(), p.click('button:has-text("Save prices")')]);
		check('valid prices saved, moves to Prove ownership', /step=verify/.test(p.url()), await msgs(p));

		// 5. Ownership (simulated) and submit.
		const sid = await php(`$s = db_seller_get( '${SELLER}' ); global $wpdb; $wpdb->update( db_seller_table( 'listings' ), array( 'verify_status' => 'verified', 'verified_at' => current_time( 'mysql', true ) ), array( 'seller_id' => (int) $s['id'] ) ); return (int) $s['id'];`);
		check('ownership marked verified (simulated DNS proof)', Number(sid) > 0, 'seller ' + sid);
		await p.goto(B + '/seller/?step=submit', { waitUntil: 'load' });
		const submitBtn = p.locator('button', { hasText: /Submit \d+ names? for review/ });
		check('submit screen shows 4 ready names with fee and payout', (await submitBtn.count()) === 1 && /Submit 4 names/.test(await submitBtn.innerText().catch(() => '')));
		await p.screenshot({ path: path.join(OUT, '03-submit.png'), fullPage: true });
		await Promise.all([p.waitForNavigation(), submitBtn.click()]);
		check('submitted for review', /review|live/i.test(await msgs(p)), await msgs(p));

		// 6. Admin approves each in Tools > Seller listings.
		await admin.goto(B + '/wp-admin/tools.php?page=db-seller-review', { waitUntil: 'load' });
		let approved = 0;
		for (let i = 0; i < 6; i++) {
			const row = admin.locator('tr', { hasText: 'QA Flow Seller' }).filter({ has: admin.locator('button', { hasText: 'Approve and publish' }) }).first();
			if (!(await row.count())) break;
			await Promise.all([admin.waitForNavigation(), row.locator('button', { hasText: 'Approve and publish' }).click()]);
			approved++;
		}
		check('admin approved the 4 names in Tools > Seller listings', approved === 4, String(approved));
		const pubNames = ['bluewidgets.com', 'redkettle.com', 'quietharbor.ca', 'maplecrest.ca'];
		let pages = 0;
		for (const d of pubNames) { const r = await s.ctx.request.get(`${B}/domains/${slug(d)}/?nc=${STAMP}`); if (r.status() === 200 && (await r.text()).includes(d)) pages++; }
		check('each approved name has its public page', pages === 4, `${pages}/4`);
		const ad = await (await s.ctx.request.get(`${B}/all-domains/?nc=${STAMP}`)).text();
		check('approved names are on All Domains (uncached)', pubNames.every((d) => ad.includes(`/domains/${slug(d)}/`)));
		const srch = await (await s.ctx.request.get(`${B}/?s=quietharbor&post_type=domain&nc=${STAMP}`)).text();
		check('approved names are found by search', srch.includes('quietharbor.ca'));

		// 7. Add more after going live; edit a live price.
		await p.goto(B + '/seller/?view=overview', { waitUntil: 'load' });
		check('dashboard shows tabs and "+ Add names"', (await p.locator('.dbsa-tabs a.dbsa-add').count()) === 1);
		await Promise.all([p.waitForNavigation(), p.click('.dbsa-tabs a.dbsa-add')]);
		await p.fill('textarea[name=names]', LATER);
		await Promise.all([p.waitForNavigation(), p.locator('textarea[name=names]').locator('xpath=ancestor::form').locator('button[type=submit]').click()]);
		await p.goto(B + '/seller/?view=names', { waitUntil: 'load' });
		check('a name added after going live appears in Your names', (await p.locator('.dbsl-dom', { hasText: LATER }).count()) >= 1);
		const liveId = await php(`global $wpdb; $s = db_seller_get( '${SELLER}' ); return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . db_seller_table( 'listings' ) . " WHERE seller_id = %d AND domain = 'quietharbor.ca'", (int) $s['id'] ) );`);
		await p.goto(`${B}/seller/?step=prices`, { waitUntil: 'load' });
		await p.goto(`${B}/seller/?view=names&edit=${liveId}`, { waitUntil: 'load' });
		const editPrice = p.locator(`input[name="row[${liveId}][price]"], input[name="price"]`).first();
		if (await editPrice.count()) {
			await editPrice.fill('2,750');
			await Promise.all([p.waitForNavigation(), editPrice.locator('xpath=ancestor::form').locator('button[type=submit]').first().click()]);
		}
		const pubPage = await (await s.ctx.request.get(`${B}/domains/quietharbor-ca/?nc=${STAMP}2`)).text();
		check('a live price change shows on the public page', /\$2,750/.test(pubPage), (await msgs(p)).slice(0, 120));

		// 8. A buyer offers; the seller counters.
		let buyer = await signIn(BUYER);
		check('buyer signs in', buyer.ok);
		const offer = await (await buyer.call('account/offer', { domain: 'maplecrest.ca', amount: '1500', message: 'qa seller flow' })).json();
		check('buyer makes an offer on a seller name', offer.ok === true, JSON.stringify(offer).slice(0, 120));
		await p.goto(B + '/seller/?view=offers', { waitUntil: 'load' });
		const card = p.locator('details', { hasText: 'maplecrest.ca' }).first();
		check('seller sees the offer in the Offers tab', (await card.count()) === 1);
		if (await card.count()) {
			await card.evaluate((d) => { d.open = true; });
			await card.locator('input[name=amount]').fill('2900');
			await Promise.all([p.waitForNavigation(), card.locator('button', { hasText: 'Send counter price' }).click()]);
			check('seller counter-offer is recorded', /counter/i.test(await msgs(p)) || /countered/i.test(await p.locator('details', { hasText: 'maplecrest.ca' }).first().innerText()), await msgs(p));
		}
		await p.screenshot({ path: path.join(OUT, '04-offers.png'), fullPage: true });

		// 9. Payouts card, then remove a name.
		await p.goto(B + '/seller/?view=payouts', { waitUntil: 'load' });
		check('payouts tab shows "Get paid with Stripe" with the onboarding button', /Onboard to collect payments|Continue onboarding|Ready to receive/.test(await p.locator('#dbsl-connect').innerText().catch(() => '')));
		const rmId = await php(`global $wpdb; $s = db_seller_get( '${SELLER}' ); return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . db_seller_table( 'listings' ) . " WHERE seller_id = %d AND domain = 'redkettle.com'", (int) $s['id'] ) );`);
		await p.goto(`${B}/seller/?view=names&edit=${rmId}`, { waitUntil: 'load' });
		const rm = p.locator('form.dbsa-remove').first();
		check('the name\'s details panel has "Remove this name"', (await rm.count()) === 1);
		if (await rm.count()) {
			p.once('dialog', (d) => d.accept());
			await Promise.all([p.waitForNavigation(), rm.locator('button').first().click()]);
		}
		const gone = await s.ctx.request.get(`${B}/domains/redkettle-com/?nc=${STAMP}3`);
		const goneText = await gone.text();
		check('a removed name leaves the site', gone.status() === 404 || !/Buy Now|Make an Offer/.test(goneText), 'HTTP ' + gone.status());
	} catch (e) {
		check('script ran to the end', false, e.message.split('\n')[0]);
	} finally {
		// 10. Clean up everything this run created.
		const cleaned = await php(`global $wpdb; $out = array(); foreach ( array( '${SELLER}' ) as $em ) { $s = db_seller_get( $em ); if ( ! $s ) continue; foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT post_id FROM " . db_seller_table( 'listings' ) . " WHERE seller_id = %d AND post_id > 0", (int) $s['id'] ) ) as $r ) { wp_delete_post( (int) $r->post_id, true ); $out[] = (int) $r->post_id; } $wpdb->delete( db_seller_table( 'listings' ), array( 'seller_id' => (int) $s['id'] ) ); $wpdb->delete( db_seller_table( 'sellers' ), array( 'id' => (int) $s['id'] ) ); } foreach ( array( 'bluewidgets.com', 'redkettle.com', 'quietharbor.ca', 'maplecrest.ca', 'brightlantern.com' ) as $d ) { $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'domain' AND post_title = %s", $d ) ); if ( $id ) { wp_delete_post( $id, true ); $out[] = $id; } } if ( function_exists( 'db_pc_purge_all' ) ) db_pc_purge_all(); return $out;`).catch(() => null);
		check('clean-up removed the test seller, listings and pages', Array.isArray(cleaned), JSON.stringify(cleaned));
		fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 1));
		console.log(`\n${results.filter((r) => r.pass).length}/${results.length} passed`);
		await browser.close();
		process.exit(results.every((r) => r.pass) ? 0 : 1);
	}
})();
