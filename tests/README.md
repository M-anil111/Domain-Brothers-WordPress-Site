# Tests

## `qa/run-qa.js` — live staging QA suite (265 checks)
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
