# Tests

## `qa/run-qa.js` — live staging QA suite (353 checks)
Real-browser (Playwright/Chromium) and HTTP checks against staging: status codes,
PHP error leaks, titles/descriptions/canonicals/H1/JSON-LD, noindex on staging,
image alt text, security headers, XML-RPC, user enumeration, checkout redirects
and guards, plan chooser maths for every term (3/6/9/12), domain-page buy
buttons, offer form, 404 page, OKF bundle, sitemap, horizontal overflow, JS
errors and broken assets on desktop + mobile, plus per-page timings.

    BETA_USER=... BETA_PASS=... NODE_PATH=$(npm root -g) node tests/qa/run-qa.js ./qa-report

Runs sequentially (the shared host returns 508 under parallel load). Writes
`report.md`, `results.json` and screenshots to the output folder. Note: staging
sits behind HTTP Basic Auth, and LiteSpeed does not cache authenticated
requests, so timings here are always uncached (worst case).

## `plan/plan-lifecycle-test.php` — payment-plan logic, offline (26 checks)
Loads the real Stripe and plan-lifecycle code against in-memory stubs and drives
a plan through start, reminder, monthly charge, decline, day 3/6/9 notices,
recovery, termination, a second buyer on the same domain, the "already sold"
guard, and installment sums for every term.

    php tests/plan/plan-lifecycle-test.php

## `qa/site-crawl.py` — full-site crawl (every sitemap URL)
Sequential crawl of a list of paths with per-page SEO/markup checks; writes
JSON with titles, descriptions and internal links for duplicate and broken-link
analysis. A full run of 1,785 URLs takes about 90 minutes.

    BETA_BASIC=user:pass python3 tests/qa/site-crawl.py paths.txt out.json > out.txt

## `seo/html-hygiene-test.php` — markup fixes, offline (20 checks)
New-tab rel, https upgrades, internal link normalisation, duplicate ids,
commented-out headings, /payment-cancel/ assets, imported-news cleanup.

    php tests/seo/html-hygiene-test.php
