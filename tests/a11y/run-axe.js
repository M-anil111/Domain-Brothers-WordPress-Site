/**
 * axe-core WCAG 2.1 A/AA scan of key staging pages, desktop + mobile.
 *
 *     npm i axe-core@4.10.2
 *     BETA_USER=... BETA_PASS=... NODE_PATH=$(npm root -g):./node_modules node tests/a11y/run-axe.js out.json
 */
const { chromium } = require('playwright');
const fs = require('fs');
const axe = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');
const B = 'https://beta.domainbrothers.com';
const PAGES = ['/', '/domains/fatality-ca/', '/domains/aethereum-ca/', '/all-domains/', '/domain-category/business/', '/news/', '/centr-tld-market-report-2024/', '/our-services/', '/about-us/', '/faqs/', '/contact-us/', '/offer/', '/sold/', '/payment-plan-setup/?d=ZmF0YWxpdHkuY2E%3D&p=MjUwMA%3D%3D'];
(async () => {
  const b = await chromium.launch({ executablePath: process.env.QA_CHROMIUM || '/opt/pw-browsers/chromium', args: ['--no-sandbox', '--disable-gpu', '--disable-dev-shm-usage'] });
  const all = [];
  for (const vp of [{ n: 'desktop', w: 1366, h: 900 }, { n: 'mobile', w: 390, h: 844 }]) {
    const ctx = await b.newContext({ httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS }, viewport: { width: vp.w, height: vp.h }, isMobile: vp.n === 'mobile' });
    for (const p of PAGES) {
      const page = await ctx.newPage();
      try {
        await page.goto(B + p, { waitUntil: 'load', timeout: 60000 }); await page.waitForTimeout(1200);
        await page.addScriptTag({ content: axe });
        const r = await page.evaluate(async () => (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } })).violations.map(v => ({ id: v.id, impact: v.impact, help: v.help, n: v.nodes.length, ex: v.nodes.slice(0, 3).map(x => x.target.join(' ') + ' :: ' + (x.failureSummary || '').split('\n')[1]) })));
        all.push({ vp: vp.n, p, r });
        console.log(vp.n, p, r.map(v => `${v.id}(${v.impact},${v.n})`).join(' ') || 'OK');
      } catch (e) { console.log(vp.n, p, 'ERR', e.message.slice(0, 100)); }
      await page.close();
    }
    await ctx.close();
  }
  fs.writeFileSync(process.argv[2], JSON.stringify(all, null, 1)); await b.close();
})();
