/** Photographs the redesigned front end on a spread of devices into docs/screenshots/redesign. */
const { chromium } = require('playwright');
const path = require('path');
const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const OUT = path.join(__dirname, '..', '..', 'docs', 'screenshots', 'redesign');
const DEVICES = [
	['desktop-1440', 1440, 900, false], ['ipad-air-820', 820, 1180, true], ['iphone-390', 390, 844, true], ['fold-open-673', 673, 841, true],
];
const PAGES = [['home', '/'], ['all-domains-list', '/all-domains/'], ['all-domains-grid', '/all-domains/', 'grid'], ['domain-detail', '/domains/fatality-ca/']];
(async () => {
	const b = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	for (const [dn, w, h, m] of DEVICES) {
		const c = await b.newContext({ ...creds, viewport: { width: w, height: h }, isMobile: m, hasTouch: m, deviceScaleFactor: 1 });
		const p = await c.newPage(); p.setDefaultTimeout(90000);
		for (const [n, u, view] of PAGES) {
			await p.goto(BASE + u, { waitUntil: 'load' });
			await p.evaluate((v) => { try { localStorage.setItem('db_view', v || 'list'); } catch (e) {} }, view);
			await p.reload({ waitUntil: 'load' });
			await p.waitForTimeout(2500);
			await p.screenshot({ path: path.join(OUT, `${dn}_${n}.png`), fullPage: false });
			console.log('shot', dn, n);
		}
		await c.close();
	}
	await b.close();
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
