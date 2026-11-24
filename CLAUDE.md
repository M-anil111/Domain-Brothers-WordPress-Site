# Domain Brothers website: working notes

Domain Brothers is a premium domain marketplace on WordPress. Staging is
`https://beta.domainbrothers.com` (always `noindex`; its HTTP Basic Auth was removed on 2026-11-20 so Stripe webhooks can reach it);
production is `https://www.domainbrothers.com`. All custom behaviour lives in
one plugin, `db-custom-blocks/` (the designer's All Domains redesign is bundled in
`db-custom-blocks/designer/`; the old `db_*.php` snippets are archived in `legacy/snippets/`). The theme (DomainFolio, commercial, no child
theme) is not edited.

## Main parts of the system

| Area | Files in `db-custom-blocks/` |
|---|---|
| Loader, shared helpers, version | `db-custom-blocks.php` (header `Version:` and `DB_BLOCKS_LOADED` must match) |
| Header, mega menus, breadcrumbs bar | `db-header-block.php`, `db-mobilenav-block.php`, `db-breadcrumbs-block.php` |
| Domain listings, list pages, filters, finder, collections | `db-domain-*.php`, `db-listing-*.php`, `db-pages-block.php`, `db-sales-block.php`, `db-priced-*.php`, `db-sold-block.php` |
| Offer page and shared page actions (no popups: offers, wishlist sign-in and exit offer are pages or off) | `db-offer-page-block.php` (`/make-an-offer/`), `db-page-actions-block.php` (Back and Share bar, real proof lines), `db-hero-showcase-block.php` (home hero cards) |
| Designer's UI fixes (header logo, archive buttons, availability count, filters, accessibility, checkout seal, shared 1320px layout) | `db-ui-fixes-block.php` + `designer/ui-fixes/` (bundled from the standalone add-on "Domain Brothers UI Fixes"; it does nothing while that add-on is active, so deactivate the add-on to switch over. The designer re-activated and edited the add-on on beta on 2026-11-23 (1.2.0/1.2.1: `layout.css` §4 and the new `layout-fixes.css`); that work is bundled since 3.150.45 and the add-on was deactivated again on 2026-11-24. Production still needs the same switch. Ask the designer to edit `designer/ui-fixes/` in this repository rather than the add-on on the server). The design standard is checked with `tests/qa/design-audit.js` + `design-audit-report.py` (results in `docs/DESIGN_QA.md`) |
| Seller marketplace phase 1 ("list your domains with us") | `db-seller-block.php` (pages `/sell-your-domains/`, `/seller/`, `/seller-agreement/`, admin Tools > Seller listings, hourly DNS check) and `db-seller-lib.php` (rules, tested offline). On only when option `db_seller_on` is `1`, or on staging when the option is unset. Plan and status: `MARKETPLACE_PLAN.md` |
| Offers, checkout, payment plans | `db-offers-block.php`, `db-offer-template.php`, `db-stripe-block.php`, `db-plan-lifecycle-block.php`, `db-orders-block.php` |
| Stripe Connect (seller payouts) | `db-connect-block.php`: V2 connected accounts (recipient, US sellers), hosted onboarding on the seller's Sales and payouts tab, thin account events at `/wp-json/db/v1/stripe-connect-webhook`, platform products, storefront `/connect-store/`, destination charges with an application fee. Uses the bundled official SDK `vendor/stripe-php` (v23.1.0, API 2026-11-18.endive). Admin: Tools > Stripe Connect |
| Accounts, wishlist, alerts | `db-account-block.php`, `db-wishlist-block.php` |
| Service pages and forms | `db-service-art-block.php`, `db-handles-block.php` (social handle pages), `db-transfer-block.php` (EPP codes, encrypted), `db-form-entries-block.php` (copy of every form submission) |
| Blog and news | `db-article-block.php` (post layout, TOC, CTAs), `db-news-block.php`, `db-index-policy-block.php` |
| Domain tools (free lookups, one SEO page per tool) | `db-domain-lookup-block.php` (hub and lookup API), `db-tool-pages-block.php` + `db-tool-pages-data.php` (17 pages under `/domain-tools/`), `assets/domain-lookup.js`; keyword research in `tools/seo-tools-keywords.json` |
| Site hygiene and ops | `db-site-hygiene-block.php` (image attributes, 404 page, llms.txt, security.txt, IndexNow, entry purge), `db-search-log-block.php`, `db-alerts-block.php` (fatal error email), `db-resolve-tools-block.php`, `db-learn-pages-block.php` (glossary, price index); CI in `.github/workflows/ci.yml` |
| SEO | `db-crawl-block.php` (301 path normalisation, canonical fallback, facet noindex, robots.txt, `/db-pages-sitemap.xml`, sitemap lastmod), `db-schema-block.php` (repairs to Rank Math's JSON-LD), checks in `tools/seo-check.py` and `tools/crawl-log-report.py`, overview in `docs/SEO_ENGINEERING.md`; `db-rankmath-block.php` (Rank Math integration and head ownership switch), `db-seo-meta-block.php`, `db-focus-block.php` + `tools/seo-focus.json` (researched focus keywords), `db-seo-audit-block.php`, `db-onpage-seo-block.php` |
| Speed | `db-pagecache-block.php` + `pagecache/`, `db-perf-block.php`, `db-delay-block.php` (inline scripts wait for the first interaction), `db-cache-block.php` |
| Safety | `db-safety-block.php` (fatal-error log, staging noindex), `db-tls-block.php`, `db-turnstile-block.php` (spam protection) |
| CRM and email | `db-crm-block.php`, `db-emails-block.php`, `db-email-thankyou-block.php` |

`MARKETPLACE_PLAN.md` describes the seller marketplace (not built).

## Run locally

There is no local WordPress setup in this repository. Changes are checked with:

```bash
# Syntax of every plugin file (the deploy script refuses to ship otherwise)
for f in db-custom-blocks/*.php db-custom-blocks/pagecache/*.php; do php -l "$f" >/dev/null || echo "FAIL $f"; done

# Offline unit tests (no WordPress needed; stubs included)
php tests/pages/pages-test.php
php tests/plan/plan-lifecycle-test.php
php tests/seo/html-hygiene-test.php

# Live staging QA (Playwright; sequential, the host returns 508 under parallel load)
BETA_USER=<user> BETA_PASS=<password> node tests/qa/run-qa.js ./qa-report

# Accessibility audit (axe-core, WCAG 2.1 A and AA; add VIEWPORT=phone for phone width)
npm install --prefix /tmp/axe axe-core@4.10.2
BETA_USER=<user> BETA_PASS=<password> AXE_DIR=/tmp/axe node tests/qa/axe-audit.js
```

The same QA and axe audits run weekly on GitHub (`.github/workflows/live-qa.yml`, also on demand
from the Actions tab); reports are kept as a workflow artifact.

Scripted sign-in and offer tests on staging: Turnstile would block them, so set the option
`db_turnstile_test_until` to a Unix time up to two hours ahead and send the cookie `db_ts_qa` with the
value of the option `db_turnstile_test_key` (32+ random characters). Only those requests get Cloudflare's
always-pass test keys (real visitors keep the real widget); it switches back by itself and never applies
on production. QA scripts read the key from `TS_QA_KEY`.

To try the plugin on a local WordPress (6.9+, PHP 8.2+), copy `db-custom-blocks/`
into `wp-content/plugins/` with the DomainFolio theme active.

## Deploy

1. Bump the version in both places in `db-custom-blocks.php`.
2. Lint every PHP file (see above). A parse error takes the whole site down.
3. Zip the `db-custom-blocks` folder and upload it in WP Admin > Plugins >
   Add New > Upload Plugin > Replace current with uploaded.
4. Confirm the new version on the Plugins screen, load a few pages and check
   Tools > DB Fatal Log.
5. The page cache is purged automatically on plugin update, post and menu
   changes (`db_pc_purge_all()` in `db-pagecache-block.php`).
6. Clear Hostinger's server cache (hPanel > Websites > beta > Cache, or the Hostinger API
   `agency-hosting_cache_clear-website`). It sits in front of WordPress and keeps serving the
   old pages otherwise. Signed-in visitors (cookie `db_wl`) are excluded from LiteSpeed's cache
   (LiteSpeed Cache > Excludes > Do Not Cache Cookies) and their pages are sent `no-store`.

Emergency rollback when wp-admin is down: upload the previous file versions to
`public_html/wp-content/plugins/db-custom-blocks/` with the Hostinger File
Manager.

## Where secrets live (names only, never in this repository)

| Secret | Stored in |
|---|---|
| Stripe keys (`db_stripe_live_pub_key`, `db_stripe_live_sec_key`, `db_stripe_test_pub_key`, `db_stripe_test_sec_key`) and webhook secrets (`db_stripe_wh_secret_live`, `db_stripe_wh_secret_test`) | WordPress options, set in WP Admin |
| Stripe Connect event destination signing secrets (`db_connect_wh_secret_test`, `db_connect_wh_secret_live`) | WordPress options, saved by Tools > Stripe Connect |
| SendGrid API key (`db_sg_api_key`) | WordPress option |
| SMTP password (`DB_SMTP_PASS`) | constant in `wp-config.php` |
| Domain index key (`DB_DOMAIN_INDEX_KEY`) | constant in `wp-config.php` |
| Turnstile site and secret keys (`db_turnstile`) | WordPress option |
| EPP code encryption key | derived from the WordPress `SECURE_AUTH` salt in `wp-config.php`; rotating the salts makes stored codes unreadable |
| Database, salts | `wp-config.php` on the server |
| Hosting, Search Console, Rank Math accounts | owner's password manager |

Rules: never commit or print a secret; use placeholders in examples. If a
secret is ever committed, rotate it at the provider first, then remove it.

## How errors are reported

- PHP fatal errors on any request are recorded by `db-safety-block.php`
  (repeats collapsed by file and line) and shown in WP Admin > Tools >
  DB Fatal Log.
- Payment, plan and CRM code writes `error_log()` lines prefixed with the
  module name to the PHP error log (Hostinger hPanel > Advanced > Error logs).
- Every Contact Form 7 submission is saved before the email is sent: Tools >
  Form entries (CSV export). Email failures therefore never lose an enquiry.
- Transfer (EPP) code reveals are logged on Tools > Transfer codes.

## Conventions

- No popups: the offer form lives on `/make-an-offer/`, signed-out wishlist clicks go to `/my-wishlist/?auth=signin`, the exit offer is off unless option `db_exit_offer_on` is set, and news cards open the article in a new tab. Add Back/Share with `db_pa_html()`. Social proof comes only from `db_proof_html()` (real counts, never invented).
- Inline scripts are delayed until the first user interaction. Anything a
  visitor must see on load (selected form options, pre-filled fields) is set
  in the server-rendered HTML.
- Pages with a query string, a sign-in cookie or a POST bypass the page cache.
- Rank Math owns the `<head>` when option `db_rankmath_owns_head` is `1`
  (`/wp-json/db/v1/seo-mode`); the plugin then only supplies fallbacks and
  staging `noindex`.
- Payment plans: `db_plan_rate()` is the rate a NEW plan pays (3 months 0%, 6 months 5%,
  9 months 8%, 12 months 10%). Each plan stores the rate it was sold at (`rate_pct`, from the
  PaymentIntent metadata); plans without one use `db_plan_rate_legacy()` (0, 0, 5, 10). Never
  recompute a stored plan from `db_plan_rate()`: use `db_plan_rate_of( $plan )`.
- Automatic charging: the daily event `db_stripe_charge_due_installments` calls
  `db_plan_run_daily()`. The site sets `DISABLE_WP_CRON`, so a server cron must call
  `wp-cron.php`. Stripe tells the site about payments at `/wp-json/db/v1/stripe-webhook`; create
  the endpoint in each Stripe mode with the admin action `/?db_create_webhook=1&_wpnonce=...`
  (it saves the signing secret). Without an endpoint a paid plan is never recorded or charged.
- Beta's old Basic Auth block was removed from `wp-config.php`; the removed text is kept in the
  option `db_wpconfig_basic_auth_block` (not autoloaded) if it must be restored.
- One commit per fix, clear messages, no force-push.
