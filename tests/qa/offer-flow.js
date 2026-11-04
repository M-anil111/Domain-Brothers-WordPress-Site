/**
 * End-to-end test of the custom-offer negotiation, through the real admin and
 * the real buyer page, on a site in Stripe TEST mode.
 *
 *   offer in -> we counter -> buyer counters -> we accept -> buyer pays (test card)
 *   -> webhook closes the deal -> order shows as "Custom offer", lead is "won".
 *
 * Creates one CRM lead (synthetic address on example.com) and one Stripe test
 * PaymentIntent. Needs the admin login and, for the last step, the test-mode
 * webhook secret (the site cannot receive Stripe's own webhook behind Basic Auth).
 *
 *   BETA_USER=... BETA_PASS=... DB_WP_USER=... DB_WP_PASS=... \
 *   STRIPE_TEST_WEBHOOK_SECRET=whsec_... NODE_PATH=$(npm root -g) \
 *   node tests/qa/offer-flow.js [outDir]
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');
const sendWebhook = require('./send-test-webhook.js');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const OUT = process.argv[2] || path.join(process.cwd(), 'offer-flow-report');
const DOMAIN = process.env.QA_OFFER_DOMAIN || 'mycaymanproperty.com';
const SLUG = DOMAIN.replace(/\./g, '-');
const EMAIL = `qa-offer-${Date.now().toString(36)}@example.com`;
fs.mkdirSync(OUT, { recursive: true });

const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`); };
const text = async (page) => (await page.locator('body').innerText()).replace(/\s+/g, ' ');
const authCtx = (browser, extra = {}) => browser.newContext({ ...(process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {}), viewport: { width: 1200, height: 1400 }, ...extra });

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const adminCtx = await authCtx(browser);
	const admin = await adminCtx.newPage();
	admin.setDefaultTimeout(60000);

	// ── admin login ──
	await admin.goto(`${BASE}/wp-login.php`);
	await admin.fill('#user_login', process.env.DB_WP_USER);
	await admin.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([admin.waitForURL(/wp-admin/, { waitUntil: 'commit' }), admin.click('#wp-submit')]);

	// ── 1. an offer arrives (the same function the offer form uses) ──
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-crm-add-lead`, { waitUntil: 'domcontentloaded' });
	await admin.fill('input[name=domain]', DOMAIN);
	await admin.fill('input[name=name]', 'QA Buyer');
	await admin.fill('input[name=email]', EMAIL);
	await admin.fill('input[name=offer_amount]', '1500');
	await admin.fill('textarea[name=message]', 'QA offer, please ignore');
	await admin.selectOption('select[name=source]', 'offer_form');
	await Promise.all([admin.waitForLoadState('domcontentloaded'), admin.click('#submit, input[type=submit], button[type=submit]')]);
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-offers`, { waitUntil: 'domcontentloaded' });
	const row = admin.locator('tr', { hasText: EMAIL });
	check('a new offer becomes an open deal in Offers', (await row.count()) === 1 && /Waiting for us/.test(await row.innerText()) && /\$1,500/.test(await row.innerText()));
	await row.locator('a.button').click();
	await admin.waitForLoadState('domcontentloaded');
	const dealId = new URL(admin.url()).searchParams.get('deal');
	const buyerUrl = await admin.locator('a', { hasText: /^open$/ }).first().getAttribute('href');
	check('admin detail has the buyer\'s private link', /\?c=[0-9a-f]{32}$/.test(buyerUrl || ''), (buyerUrl || '').replace(/c=.*/, 'c=…'));

	// ── 2. we counter at $2,800 ──
	await admin.fill('input[name=amount]', '2800');
	await admin.locator('form:has(input[value=counter]) textarea[name=message]').fill('Best price this week');
	await Promise.all([admin.waitForLoadState('domcontentloaded'), admin.locator('form:has(input[value=counter]) button').click()]);
	check('counter-offer sent', /Done\. The buyer has been emailed/.test(await text(admin)));

	// ── 3. the buyer sees it ──
	const buyerCtx = await authCtx(browser);
	const buyer = await buyerCtx.newPage();
	buyer.setDefaultTimeout(60000);
	await buyer.goto(buyerUrl, { waitUntil: 'domcontentloaded' });
	let t = await text(buyer);
	check('buyer page shows our price, message and the accept button', /\$2,800/.test(t) && /Best price this week/.test(t) && /Accept \$2,800/i.test(t));
	check('buyer page is noindex', /noindex/.test(await buyer.locator('meta[name=robots]').getAttribute('content').catch(() => '')));
	await buyer.screenshot({ path: path.join(OUT, 'buyer-countered.png'), fullPage: true });

	// ── 4. a guessed or edited link gets nothing ──
	const bad = await buyerCtx.newPage();
	await bad.goto(buyerUrl.replace(/c=[0-9a-f]{32}/, 'c=' + '0'.repeat(32)), { waitUntil: 'domcontentloaded' });
	check('a wrong token shows no offer', !/Your offer|Accept \$/.test(await text(bad)));
	await bad.close();

	// ── 5. the buyer counters with $2,000 ──
	await buyer.fill('#db-of-amt', '2000');
	await buyer.fill('#db-of-note', 'Can you do 2000?');
	await Promise.all([buyer.waitForURL(/done=ok/), buyer.locator('form:has(input[value=offer]) button').click()]);
	t = await text(buyer);
	check('buyer\'s new offer is recorded (waiting for us, $2,000)', /\$2,000/.test(t) && /Change your offer/.test(t));

	// ── 6. we accept $2,000 ──
	await admin.goto(`${BASE}/wp-admin/admin.php?page=db-offers&deal=${dealId}`, { waitUntil: 'domcontentloaded' });
	t = await text(admin);
	check('admin sees the buyer\'s counter and the history', /\$2,000/.test(t) && /Buyer offer \$1,500|Buyer offer \$2,000/.test(t) && /Seller counter \$2,800/.test(t));
	await Promise.all([admin.waitForLoadState('domcontentloaded'), admin.locator('form:has(input[value=accept]) button').click()]);
	check('offer accepted', /Done\. The buyer has been emailed/.test(await text(admin)));

	// ── 7. the buyer can pay, in full or monthly ──
	await buyer.goto(buyerUrl, { waitUntil: 'domcontentloaded' });
	t = await text(buyer);
	check('accepted page offers Pay now and monthly plans', /Pay \$2,000 now/i.test(t) && /3 months/.test(t) && /12 months/.test(t));
	await buyer.screenshot({ path: path.join(OUT, 'buyer-accepted.png'), fullPage: true });

	// ── 8. checkout charges the deal's price, whatever the URL says ──
	const payHref = await buyer.locator('a.db-un-btn--primary').first().getAttribute('href');
	await buyer.goto(payHref + '&p=MQ%3D%3D&d=eC5jb20%3D', { waitUntil: 'domcontentloaded' });
	t = await text(buyer);
	check('checkout shows the agreed domain and $2,000 (extra URL parameters ignored)', new RegExp(DOMAIN.replace('.', '\\.'), 'i').test(t) && /\$2,000/.test(t));
	// Three-step checkout: billing details, order confirmation, then payment.
	await buyer.fill('#co-name', 'QA Offer Buyer');
	await buyer.fill('#co-email', EMAIL);
	await buyer.fill('#co-phone', '+1 555 010 0000');
	await buyer.click('#co-billing button[type=submit]');
	if (await buyer.locator('#co-agree').count()) { await buyer.check('#co-agree'); }
	await buyer.click('#co-confirm');
	await buyer.waitForSelector('iframe[name^="__privateStripeFrame"]', { timeout: 60000 });
	await buyer.waitForTimeout(3000);
	const fr = buyer.frameLocator('iframe[name^="__privateStripeFrame"]').first();
	await fr.locator('input[name="number"], input[autocomplete="cc-number"]').first().fill('4242424242424242');
	await fr.locator('input[name="expiry"], input[autocomplete="cc-exp"]').first().fill('12 / 34');
	await fr.locator('input[name="cvc"], input[autocomplete="cc-csc"]').first().fill('123');
	const zip = fr.locator('input[name="postalCode"], input[autocomplete="postal-code"]');
	if (await zip.count()) { await zip.first().fill('78701'); }
	await buyer.locator('#co-pay-btn').click();
	await buyer.waitForURL(/thank-you/, { timeout: 90000 }).catch(() => {});
	check('test payment completes and reaches the thank-you page', /thank-you/.test(buyer.url()));

	// ── 9. the webhook closes the deal ──
	if (process.env.STRIPE_TEST_WEBHOOK_SECRET) {
		// Real PaymentIntents carry lead_id and deal_id; the simulator sends the deal id and the site resolves the lead from it.
		const r = await sendWebhook({ kind: 'full', domain: DOMAIN, args: ['2000', EMAIL, '0', dealId] });
		check('webhook accepted', r.ok);
		await admin.goto(`${BASE}/wp-admin/admin.php?page=db-offers`, { waitUntil: 'domcontentloaded' });
		check('deal is Paid', /Paid/.test(await admin.locator('tr', { hasText: EMAIL }).innerText()));
		await admin.goto(`${BASE}/wp-admin/admin.php?page=db-sales&mode=test&q=${encodeURIComponent(EMAIL)}`, { waitUntil: 'domcontentloaded' });
		const srow = await text(admin);
		check('Sales lists it as a Custom offer, paid in full, $2,000', /Custom offer/.test(srow) && /\$2,000\.00/.test(srow));
		await admin.goto(`${BASE}/wp-admin/admin.php?page=db-crm-leads&s=${encodeURIComponent(EMAIL)}`, { waitUntil: 'domcontentloaded' });
		check('lead is marked Won', /Won/i.test(await admin.locator('tr', { hasText: EMAIL }).first().innerText()));
	} else {
		console.log('SKIP closing steps (set STRIPE_TEST_WEBHOOK_SECRET)');
	}

	// ── 10. clean up: the test sale must not leave the listing sold ──
	if (process.env.STRIPE_TEST_WEBHOOK_SECRET) {
		await admin.goto(`${BASE}/wp-admin/admin.php?page=db-sales&mode=test&q=${encodeURIComponent(EMAIL)}`, { waitUntil: 'domcontentloaded' });
		admin.once('dialog', (d) => d.accept());
		await Promise.all([admin.waitForURL(/reset=/), admin.locator('a.dbs-reset').first().click()]);
		const after = await buyerCtx.newPage();
		await after.goto(`${BASE}/domains/${SLUG}/`, { waitUntil: 'domcontentloaded' });
		check('after "Reset test sale" the domain is available again', !/has been sold|no longer available/i.test(await text(after)));
	}

	await browser.close();
	fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 1));
	console.log(`\n${results.filter((r) => r.pass).length}/${results.length} passed`);
	process.exit(results.every((r) => r.pass) ? 0 : 1);
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
