#!/usr/bin/env node
/**
 * Domain Brothers: accessibility audit (axe-core, WCAG 2.1 A and AA) on the main templates of staging.
 *
 * Not part of CI: it needs the live staging site. Run it after a deploy.
 *
 * Usage:
 *   npm install --prefix /tmp/axe axe-core@4.10.2
 *   BETA_USER=... BETA_PASS=... AXE_DIR=/tmp/axe NODE_PATH=$(npm root -g) node tests/qa/axe-audit.js
 *
 * Optional: BETA_BASE (default https://beta.domainbrothers.com), AXE_PAGES (comma-separated paths),
 * VIEWPORT=phone for a 390 px wide run. Exit code 1 when any page has a violation or fails to load.
 * Credentials come only from the environment. Sequential on purpose: the shared host returns 508 under load.
 */
'use strict';

const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright');

const BASE = (process.env.BETA_BASE || 'https://beta.domainbrothers.com').replace(/\/$/, '');
const AXE_DIR = process.env.AXE_DIR || process.cwd();
const PAGES = (process.env.AXE_PAGES || '/,/all-domains/,/sold/,/domain-tools/,/domain-tools/dns-lookup/,/domain-glossary/,/domain-price-index/,/website-design-development/,/register/,/contact/,/news/')
	.split(',').map((s) => s.trim()).filter(Boolean);
const PHONE = 'phone' === process.env.VIEWPORT;

function loadAxe() {
	const candidates = [path.join(AXE_DIR, 'node_modules/axe-core/axe.min.js'), path.join(AXE_DIR, 'axe.min.js')];
	for (const c of candidates) {
		if (fs.existsSync(c)) {
			return fs.readFileSync(c, 'utf8');
		}
	}
	console.error('axe-core not found. Run: npm install --prefix ' + AXE_DIR + ' axe-core@4.10.2');
	process.exit(2);
}

(async () => {
	const axeSrc = loadAxe();
	const creds = process.env.BETA_USER ? { httpCredentials: { username: process.env.BETA_USER, password: process.env.BETA_PASS } } : {};
	const browser = await chromium.launch();
	const ctx = await browser.newContext({ ...creds, viewport: PHONE ? { width: 390, height: 800 } : { width: 1280, height: 900 } });
	let failed = 0;
	// Domain page: find one real listing from the All Domains page.
	const pages = PAGES.slice();
	for (const p of pages) {
		const pg = await ctx.newPage();
		let status = 0;
		for (let i = 0; i < 3 && (status < 200 || status >= 400); i++) {
			try {
				const r = await pg.goto(BASE + p, { waitUntil: 'load', timeout: 90000 });
				status = r ? r.status() : 0;
			} catch (e) {
				status = 0;
			}
			if (status < 200 || status >= 400) {
				await pg.waitForTimeout(4000);
			}
		}
		if (status < 200 || status >= 400) {
			console.log('FAIL ' + p + ' (HTTP ' + status + ')');
			failed++;
			await pg.close();
			continue;
		}
		await pg.waitForTimeout(1200);
		await pg.evaluate(axeSrc);
		const res = await pg.evaluate(() => axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] } }));
		if (res.violations.length) {
			failed++;
			console.log('FAIL ' + p);
			for (const v of res.violations) {
				console.log('  ' + v.id + ' (' + v.impact + ', ' + v.nodes.length + ' nodes): ' + v.help);
				v.nodes.slice(0, 2).forEach((n) => console.log('    ' + n.target.join(' ') + '  ' + (n.html || '').slice(0, 100)));
			}
		} else {
			console.log('PASS ' + p);
		}
		await pg.close();
	}
	await browser.close();
	console.log('\n' + (PAGES.length - failed) + ' of ' + PAGES.length + ' pages clean' + (PHONE ? ' (phone width)' : ''));
	process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e.message); process.exit(2); });
