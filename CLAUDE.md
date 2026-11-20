# Domain Brothers website: working notes

Domain Brothers is a premium domain marketplace on WordPress. Staging is
`https://beta.domainbrothers.com` (HTTP Basic Auth, always `noindex`);
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
| Offers, checkout, payment plans | `db-offers-block.php`, `db-offer-template.php`, `db-stripe-block.php`, `db-plan-lifecycle-block.php`, `db-orders-block.php` |
| Accounts, wishlist, alerts | `db-account-block.php`, `db-wishlist-block.php` |
| Service pages and forms | `db-service-art-block.php`, `db-handles-block.php` (social handle pages), `db-transfer-block.php` (EPP codes, encrypted), `db-form-entries-block.php` (copy of every form submission) |
| Blog and news | `db-article-block.php` (post layout, TOC, CTAs), `db-news-block.php`, `db-index-policy-block.php` |
| Domain tools (free lookups, one SEO page per tool) | `db-domain-lookup-block.php` (hub and lookup API), `db-tool-pages-block.php` + `db-tool-pages-data.php` (14 pages under `/domain-tools/`), `assets/domain-lookup.js`; keyword research in `tools/seo-tools-keywords.json` |
| Site hygiene and ops | `db-site-hygiene-block.php` (image attributes, 404 page, llms.txt, security.txt, IndexNow, entry purge), `db-search-log-block.php`, `db-alerts-block.php` (fatal error email), `db-resolve-tools-block.php`, `db-learn-pages-block.php` (glossary, price index); CI in `.github/workflows/ci.yml` |
| SEO | `db-rankmath-block.php` (Rank Math integration and head ownership switch), `db-seo-meta-block.php`, `db-focus-block.php` + `tools/seo-focus.json` (researched focus keywords), `db-seo-audit-block.php`, `db-onpage-seo-block.php` |
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
```

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

Emergency rollback when wp-admin is down: upload the previous file versions to
`public_html/wp-content/plugins/db-custom-blocks/` with the Hostinger File
Manager.

## Where secrets live (names only, never in this repository)

| Secret | Stored in |
|---|---|
| Stripe keys (`db_stripe_live_pub_key`, `db_stripe_live_sec_key`, `db_stripe_test_pub_key`, `db_stripe_test_sec_key`) and webhook secrets (`db_stripe_wh_secret_live`, `db_stripe_wh_secret_test`) | WordPress options, set in WP Admin |
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

- Inline scripts are delayed until the first user interaction. Anything a
  visitor must see on load (selected form options, pre-filled fields) is set
  in the server-rendered HTML.
- Pages with a query string, a sign-in cookie or a POST bypass the page cache.
- Rank Math owns the `<head>` when option `db_rankmath_owns_head` is `1`
  (`/wp-json/db/v1/seo-mode`); the plugin then only supplies fallbacks and
  staging `noindex`.
- One commit per fix, clear messages, no force-push.
