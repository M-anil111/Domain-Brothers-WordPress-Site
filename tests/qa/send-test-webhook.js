/**
 * Sends a correctly signed, synthetic Stripe webhook event to the site's
 * webhook endpoint, so purchases can be simulated (orders log, sale and plan
 * emails, offer-to-order tracking) without a publicly reachable URL.
 *
 * Use it only on a site in Stripe TEST mode: the site accepts the event
 * because it is signed with the test-mode webhook secret you pass in.
 *
 *   STRIPE_TEST_WEBHOOK_SECRET=whsec_... [BETA_USER=... BETA_PASS=...] \
 *   node tests/qa/send-test-webhook.js full <domain> <amountUsd> <buyerEmail> [leadId] [dealId]
 *   node tests/qa/send-test-webhook.js plan <domain> <installment> <totalMonths> <listingPriceUsd> <buyerEmail> [leadId] [dealId]
 *
 * Domain names that are not real listings are safest (nothing on the site is
 * marked sold). Each call uses a fresh event and PaymentIntent id.
 */
const crypto = require('crypto');
const https = require('https');

const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const SECRET = process.env.STRIPE_TEST_WEBHOOK_SECRET;
if (!SECRET) { console.error('Set STRIPE_TEST_WEBHOOK_SECRET'); process.exit(2); }

function send({ kind, domain, args }) {
return new Promise((resolve) => {
const rest = args;

const rid = crypto.randomBytes(8).toString('hex');
let meta; let amountCents; let email;
if (kind === 'full') {
	const [amount, buyer, lead, deal] = rest;
	amountCents = Math.round(parseFloat(amount) * 100); email = buyer;
	meta = { domain, type: 'full' };
	if (lead) { meta.lead_id = String(lead); }
	if (deal) { meta.deal_id = String(deal); }
} else if (kind === 'plan') {
	const [inst, months, price, buyer, lead, deal] = rest;
	const n = parseInt(inst, 10); const m = parseInt(months, 10); const total = parseFloat(price);
	const rate = { 3: 0, 6: 0, 9: 0.05, 12: 0.10 }[m] ?? 0;
	const totalCents = Math.round(total * (100 + rate * 100));
	const monthly = Math.round(totalCents / m);
	amountCents = n < m ? monthly : totalCents - monthly * (m - 1);
	email = buyer;
	meta = { domain, type: 'plan', total_months: String(m), installment_number: String(n), total_price_usd: String(total) };
	if (lead) { meta.lead_id = String(lead); }
	if (deal) { meta.deal_id = String(deal); }
} else { console.error('usage: full|plan ...'); return resolve({ ok: false }); }

const pi = { id: 'pi_sim_' + rid, object: 'payment_intent', amount: amountCents, amount_received: amountCents, currency: 'usd', status: 'succeeded', receipt_email: email, customer: 'cus_sim_' + domain.replace(/[^a-z0-9]/gi, ''), payment_method: 'pm_sim', metadata: meta, livemode: false };
const payload = JSON.stringify({ id: 'evt_sim_' + rid, object: 'event', type: 'payment_intent.succeeded', livemode: false, data: { object: pi } });
const ts = Math.floor(Date.now() / 1000);
const sig = crypto.createHmac('sha256', SECRET).update(ts + '.' + payload).digest('hex');

const u = new URL(BASE + '/wp-json/db/v1/stripe-webhook');
const headers = { 'Content-Type': 'application/json', 'Stripe-Signature': `t=${ts},v1=${sig}`, 'Content-Length': Buffer.byteLength(payload) };
if (process.env.BETA_USER) { headers.Authorization = 'Basic ' + Buffer.from(process.env.BETA_USER + ':' + process.env.BETA_PASS).toString('base64'); }
const req = https.request({ hostname: u.hostname, path: u.pathname, method: 'POST', headers }, (res) => {
	let body = ''; res.on('data', (c) => { body += c; });
	res.on('end', () => { console.log(`${kind} ${domain} -> HTTP ${res.statusCode} ${body.slice(0, 120)} (pi ${pi.id}, $${(amountCents / 100).toFixed(2)})`); resolve({ ok: res.statusCode === 200, pi: pi.id }); });
});
req.on('error', (e) => { console.error(e.message); resolve({ ok: false }); });
req.write(payload); req.end();
});
}

module.exports = send;
if (require.main === module) {
	const [kind, domain, ...args] = process.argv.slice(2);
	send({ kind, domain, args }).then((r) => process.exit(r.ok ? 0 : 1));
}
