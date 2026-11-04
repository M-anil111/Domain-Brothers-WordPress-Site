/**
 * End-to-end test purchase through the real checkout, using Stripe's test card.
 * Only run it against a site whose Stripe mode is "test" (Settings > DB Stripe
 * Keys): it creates a real PaymentIntent in the connected Stripe sandbox.
 *
 *   [QA_MONTHS=3] [QA_DOMAIN=fatality.ca QA_PRICE=2500] BETA_USER=... BETA_PASS=... NODE_PATH=$(npm root -g) node tests/qa/stripe-test-purchase.js [outDir]
 *
 * Checks: the checkout renders the Stripe Payment Element, a 4242 card pays,
 * the browser lands on /thank-you/ with HTTP 200 and the confirmation names the
 * domain and amount (i.e. the signed return link is accepted). Exits non-zero
 * on any failure. Uses a synthetic receipt address (example.com).
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const DOMAIN_B64 = encodeURIComponent(Buffer.from(process.env.QA_DOMAIN || 'fatality.ca').toString('base64'));
const PRICE_B64 = encodeURIComponent(Buffer.from(process.env.QA_PRICE || '2500').toString('base64'));
const MONTHS = process.env.QA_MONTHS || ''; // set to 3/6/9/12 to test a payment plan's first instalment
const OUT = process.argv[2] || path.join(process.cwd(), 'stripe-purchase-report');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const ctx = await browser.newContext({
		...(process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {}),
		viewport: { width: 1100, height: 1500 },
	});
	const page = await ctx.newPage();
	const results = [];
	const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' — ' + detail : ''}`); };

	await page.goto(`${BASE}/buy-now/?d=${DOMAIN_B64}&p=${PRICE_B64}${MONTHS ? '&months=' + MONTHS : ''}`, { waitUntil: 'load' });
	// Three-step checkout: billing details, order confirmation, then payment.
	await page.fill('#co-name', 'QA Test Buyer');
	await page.fill('#co-email', 'qa-test@example.com');
	await page.fill('#co-phone', '+1 555 010 0000');
	await page.click('#co-billing button[type=submit]');
	if (await page.locator('#co-agree').count()) { await page.check('#co-agree'); }
	await page.click('#co-confirm');
	const frame = await page.waitForSelector('iframe[name^="__privateStripeFrame"]', { timeout: 30000 }).catch(() => null);
	check('checkout shows the Stripe Payment Element', !!frame);
	if (!frame) { await browser.close(); process.exit(1); }
	await page.waitForTimeout(3000);
	await page.screenshot({ path: path.join(OUT, 'checkout.png') });

	const fr = page.frameLocator('iframe[name^="__privateStripeFrame"]').first();
	await fr.locator('input[name="number"], input[autocomplete="cc-number"]').first().fill('4242424242424242');
	await fr.locator('input[name="expiry"], input[autocomplete="cc-exp"]').first().fill('12 / 34');
	await fr.locator('input[name="cvc"], input[autocomplete="cc-csc"]').first().fill('123');
	const zip = fr.locator('input[name="postalCode"], input[autocomplete="postal-code"]');
	if (await zip.count()) { await zip.first().fill('78701'); }
	const [resp] = await Promise.all([
		page.waitForResponse((r) => /\/thank-you\//.test(r.url()) && r.request().resourceType() === 'document', { timeout: 60000 }).catch(() => null),
		page.locator('#co-pay-btn').click(),
	]);
	await page.waitForTimeout(2000);
	check('payment redirects to /thank-you/', /\/thank-you\//.test(page.url()), page.url().replace(/sig=[^&]+/, 'sig=…'));
	check('thank-you page returns HTTP 200', !!resp && resp.status() === 200, resp ? String(resp.status()) : 'no response');
	const text = await page.locator('body').innerText();
	check('confirmation names the domain' + (MONTHS ? '' : ' and amount'), /fatality\.ca/i.test(text) && (MONTHS || /2,500/.test(text)), MONTHS ? 'plan, ' + MONTHS + ' months' : '');
	await page.screenshot({ path: path.join(OUT, 'thank-you.png') });
	await browser.close();
	fs.writeFileSync(path.join(OUT, 'results.json'), JSON.stringify(results, null, 1));
	process.exit(results.every((r) => r.pass) ? 0 : 1);
})();
