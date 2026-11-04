/**
 * Back-end sweep: opens every Domain Brothers admin screen and the main WordPress
 * screens as an administrator and checks that each one loads (HTTP 200), shows its
 * heading, prints no PHP warning / notice / fatal error and throws no JavaScript
 * error. Also reports the plugin version, pending updates and Site Health.
 *
 * Staging only (admin login: DB_WP_USER / DB_WP_PASS). Read-only.
 */
const { chromium } = require('playwright');
const BASE = process.env.QA_BASE || 'https://beta.domainbrothers.com';
const SCREENS = [
	['Dashboard', '/wp-admin/index.php', /Dashboard/],
	['All Leads', '/wp-admin/admin.php?page=db-crm-leads', /Leads/i],
	['Analytics', '/wp-admin/admin.php?page=db-crm-analytics', /Analytics|Leads/i],
	['Sales', '/wp-admin/admin.php?page=db-sales', /Sales/],
	['Offers: negotiations', '/wp-admin/admin.php?page=db-offers', /Custom offers/],
	['Offers: all received', '/wp-admin/admin.php?page=db-offers&tab=all', /All offers received/],
	['Sold domains', '/wp-admin/admin.php?page=db-sold', /Sold domains/],
	['Domain hits', '/wp-admin/admin.php?page=db-hits', /Domain hits/],
	['Domain hits with a date range', '/wp-admin/admin.php?page=db-hits&from=2026-01-01&to=2026-12-31', /Selected range/],
	['Wishlists', '/wp-admin/admin.php?page=db-wishlists', /Wishlists/],
	['Domains list', '/wp-admin/edit.php?post_type=domain', /Domains/],
	['Add domain', '/wp-admin/post-new.php?post_type=domain', /Add New Domain|Add new/i],
	['Plugins', '/wp-admin/plugins.php', /Plugins/],
	['Updates', '/wp-admin/update-core.php', /WordPress Updates/],
	['Site Health', '/wp-admin/site-health.php', /Site Health/],
	['Contact forms', '/wp-admin/admin.php?page=wpcf7', /Contact Forms/i],
];
const BAD = /(Fatal error|Parse error|Warning:|Notice:|Deprecated:|Uncaught|Stack trace)/;
const results = [];
const check = (name, pass, detail = '') => { results.push({ name, pass }); console.log(`${pass ? 'PASS' : 'FAIL'} ${name}${detail ? ' - ' + detail : ''}`); };

(async () => {
	const browser = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const ctx = await browser.newContext({ ...creds, viewport: { width: 1400, height: 900 } });
	const page = await ctx.newPage();
	page.setDefaultTimeout(90000);
	const errs = [];
	page.on('pageerror', (e) => errs.push(e.message.slice(0, 120)));
	await page.goto(`${BASE}/wp-login.php`);
	await page.fill('#user_login', process.env.DB_WP_USER);
	await page.fill('#user_pass', process.env.DB_WP_PASS);
	await Promise.all([page.waitForURL(/wp-admin/, { waitUntil: 'commit' }), page.click('#wp-submit')]);
	for (const [name, path, head] of SCREENS) {
		errs.length = 0;
		let status = 0;
		let text = '';
		try {
			const r = await page.goto(BASE + path, { waitUntil: 'domcontentloaded' });
			status = r.status();
			await page.waitForTimeout(800);
			text = await page.locator('#wpbody-content').innerText().catch(() => '');
		} catch (e) { text = 'ERROR ' + e.message.slice(0, 80); }
		const optional = name === 'Contact forms';
		const ok = status === 200 && !BAD.test(text) && errs.length === 0 && (head.test(text) || optional);
		check(`${name}`, ok, ok ? '' : `status=${status} bad=${(text.match(BAD) || [''])[0]} head=${head.test(text)} js=${errs.join('|')}`);
	}
	// versions and updates
	await page.goto(`${BASE}/wp-admin/plugins.php`, { waitUntil: 'domcontentloaded' });
	const plugins = (await page.locator('#the-list').innerText()).replace(/\s+/g, ' ');
	const ver = (plugins.match(/Domain Brothers Custom Blocks.{0,400}?Version ([0-9.]+)/) || [])[1];
	check('Domain Brothers plugin is active and current', !!ver && /^3\.59\./.test(ver), `version ${ver}`);
	await page.goto(`${BASE}/wp-admin/update-core.php?force-check=1`, { waitUntil: 'domcontentloaded' });
	const uc = (await page.locator('#wpbody-content').innerText()).replace(/\s+/g, ' ');
	check('WordPress is on the latest version', /You have the latest version of WordPress/.test(uc), (uc.match(/Current version: ([0-9.]+)/) || [])[0]);
	check('all plugins and themes are up to date', /Your plugins are all up to date/.test(uc) && /Your themes are all up to date/.test(uc));
	// Site Health status (asynchronous tests)
	await page.goto(`${BASE}/wp-admin/site-health.php`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(15000);
	const sh = (await page.locator('#wpbody-content').innerText()).replace(/\s+/g, ' ');
	const crit = (sh.match(/(\d+) critical issues?/) || [])[1];
	check('Site Health reports no critical issues', !crit || Number(crit) === 0, `critical=${crit || 0}; ${(sh.match(/(\d+) recommended improvements?/) || [])[0] || ''}`);
	await browser.close();
	console.log(`\n${results.filter((r) => r.pass).length}/${results.length} passed`);
	process.exit(results.every((r) => r.pass) ? 0 : 1);
})().catch((e) => { console.error('ERROR', e.message); process.exit(1); });
