# Bug register and fix plan (2026-11-21, plugin 3.118.8)

Method: a two-viewport browser crawl (desktop 1366 and phone 390) of 113 URLs, a check of
the 431 internal links those pages contain, structured-data and social-tag checks on 78
pages, functional tests of the 17 domain tools and the lookup API, malformed-input probes
of checkout, offer and search, exposed-endpoint and header probes, and a static scan of the
plugin PHP. Every item below was reproduced; two candidates were checked and dropped
(`/sell-domains-service/` is a 302 to `/contact-us/`, and "Coming soon" on `/domain-parking/`
is glossary copy).

**Count: 41 distinct defects (CO-5 added later). Counted per affected page they are about 400 occurrences**
(for example 113 pages with the duplicate style id, 59 pages without `og:image`). The static
scan found no SQL injection, unsafe unserialize, weak randomness or open redirects: the
interpolated SQL only inserts table names, and all 23 public REST routes are intentional.

Severity: **H** high, **M** medium, **L** low. Effort: S under 1 hour, M half a day, L a day or more.

## Checkout and offers

| ID | Sev | Defect | Evidence | Effort |
|---|---|---|---|---|
| CO-1 | H | `/buy-now/` returns HTTP 500 with the raw WordPress error page for a malformed domain value, a negative price, an unlisted domain and markup in `d`. | `?d=!!!notbase64`, `?p=` of `-5`, `?d=` of `notlisted.xyz` all give 500 | S |
| CO-2 | M | `/payment-plan-setup/` for an unlisted domain returns 200 with an empty page (soft 404). | `?d=notlisted.xyz` | S |
| CO-3 | L | `/buy-now/?months=2`, `99` or `-3` silently falls back to pay in full with no notice; `3abc` is read as 3. | crawl | S |
| CO-5 | L | The checkout schedule dates use the site time zone while the plan chooser computes dates in the browser, so around midnight they disagree (checkout "Nov 20 (today)", plan page "Nov 21"). | 2026-11-21 live check | S |
| CO-4 | M | `/make-an-offer/?d=notlisted.xyz` shows a generic "Offer on a Premium Domain" form, so offers can be sent for names not for sale. | probe | S |

## Routing and SEO

| ID | Sev | Defect | Evidence | Effort |
|---|---|---|---|---|
| RS-1 | M | The parameter name `domain` collides with the domain post type: "Want to develop this domain?" and broker links 404 or 301 for some names. | `/contact-us/?domain=restaurantsincayman.com&topic=develop` is 404; 10 domain pages carry both links | S |
| RS-2 | M | `/my-wishlist/`, `/sign-in/` and `/my-wishlist/?auth=bogus` have the title "Page Not Found". | titles | S |
| RS-3 | L | `/news/page/99/` returns 200 instead of 404. | probe | S |
| RS-4 | M | `/all-domains/page/2/` returns 200 with the same content as page 1. | 295 KB both | S |
| RS-5 | L | Empty search `/?s=` returns 200 with the blank title "Search results for". | probe | S |
| RS-6 | L | 22 internal links still use old slugs that redirect (`/digital-marketing/`, `/software-development/`, `/other-services/`, `/mobile-app-development/`). | 10, 4, 5 and 3 pages | S |
| RS-7 | L | Titles over 65 characters: `/find-a-domain/` (68), `/payment-terms/` (67), `/domain-tools/` (84). | crawl | S |
| RS-8 | L | Descriptions over 160 characters on `/acquire-premium-domain/`, `/payment-terms/`, `/domain-tools/`; none on `/thank-you/`, `/register/`, `/sign-in/`, `/my-wishlist/`. | crawl | S |
| RS-9 | H | `og:image` is missing on 59 of 78 sampled pages (home, listings, services, 14 of 17 tools), so shared links show no image. | seo crawl | M |
| RS-10 | L | `og:title`, `og:description` or `og:url` missing on `/sign-in/`, `/search/` and (url) `/payment-terms/`. | seo crawl | S |
| RS-11 | M | Product structured data has no `offers` or `image` on 8 offer-only domain pages, which fails rich-result validation. | e.g. `/domains/bilt-ca/` | M |
| RS-12 | L | `/favicon.ico` and `/apple-touch-icon.png` return 404 although a `<link rel=icon>` exists. | probe | S |
| RS-13 | verify | Canonical is absent on 72 pages. Expected on staging (noindex); confirm it appears on production. | crawl | S |

## Accessibility

| ID | Sev | Defect | Evidence | Effort |
|---|---|---|---|---|
| A-1 | H | Form fields have no label (`contact-name`, `-email`, `-phone`, `-interest`, `-domain`) on 9 service and handle pages (45 fields). | crawl | S |
| A-2 | L | Duplicate element id `db-combo-css` on every page. | crawl | S |
| A-3 | M | Targets under 32 px on phone: `/all-domains/` (56), `/find-a-domain/` (33), 8 domain pages (4 each), `/thank-you/` (6). | crawl | M |
| A-4 | L | Text under 12 px on `/find-a-domain/` (5 elements) and `/thank-you/` (1). | crawl | S |
| A-5 | L | Heading levels skipped on `/offer/`, `/comprehensive-digital-solutions/`, `/about-domain-brothers/`. | crawl | S |
| A-6 | L | An iframe without a title on the home page. | seo crawl | S |

## Performance

| ID | Sev | Defect | Evidence | Effort |
|---|---|---|---|---|
| P-1 | M | `/news/page/2/` takes 4.05 s to first byte (feed fetch on the request). | link check | M |
| P-2 | M | `/all-domains/` HTML is 288 KB because every card is inline. | link check | M |
| P-3 | L | The 404 page is 150 to 158 KB (full theme and menus). | probe | S |
| P-4 | L | Images over 150 KB: `dnx-dashboard.webp` 323 KB, `our-team` PNG 184 KB, guide infographic PNG 159 KB (2 guides). | seo crawl | S |
| P-5 | L | Domain pages take 1.2 to 1.5 s to first byte. | link check | M |
| P-6 | L | `/buy-now/` document is 195 KB (inline CSS and scripts). | probe | M |

## Security hardening and API

| ID | Sev | Defect | Evidence | Effort |
|---|---|---|---|---|
| S-1 | M | The content security policy is only `upgrade-insecure-requests`; scripts and frames are unrestricted. | headers | L |
| S-2 | L | No cross-origin opener or resource policy headers. | headers | S |
| S-3 | L | The lookup API rejects a Unicode name (`bücher.de`) with an empty 400 body, so the tool shows no message. | probe | S |
| S-4 | L | The lookup API rejects a trailing dot (`example.com.`) and a pasted port (`example.com:8080`). | probe | S |
| S-5 | L | Unknown `parts` values are ignored and the full lookup runs. | probe | S |
| S-6 | L | "Website history checker" shows registry age and expiry, not site history. | tool test | M |

## Operations and testing

| ID | Sev | Defect | Evidence | Effort |
|---|---|---|---|---|
| O-1 | H | GA4 `G-56DWCMLT8V` and GTM `GTM-W7B64D4` each load twice (two plugins), which double-counts events. Owner decision on which source stays. | page source | S |
| O-2 | M | `wishlist-flow.js`, `offer-flow.js`, `account-flow.js` and `sales-features.js` are unverified against the page flows (the wishlist script's admin login timed out). | run | M |
| O-3 | M | Turnstile blocks scripted sign-in and offer submits, so those paths have no end-to-end test. Use Cloudflare's test keys on staging. | runs | M |
| O-4 | L | Live QA and the axe audit run by hand, not in CI. | ci.yml | M |
| O-5 | L | Three tool pages use another form id and were not exercised (`certificate-decoder`, `dns-propagation-checker`, `nslookup-online`). | tool test | S |

## Fix plan

1. **Batch 1, correctness and sharing (about 1 day): CO-1, CO-2, CO-4, RS-1, RS-2, RS-9, A-1, O-1.**
   Return a friendly error page or redirect instead of 500; stop using `domain` as a query
   name; set titles on the account pages; set a default `og:image` in Rank Math; add labels to
   the service forms; remove one GA4/GTM source. Add offline tests for the checkout parameter
   handling (CO-1) and the title filter (RS-2).
2. **Batch 2, SEO and structure (about half a day): RS-3 to RS-8, RS-10 to RS-12, A-2, A-5, A-6, CO-3.**
   Mostly one-line filters and copy edits.
3. **Batch 3, mobile and speed (about 1 day): A-3, A-4, P-1 to P-6.** Enlarge tap targets;
   cache the news feed; paginate or lazy-render `/all-domains/`; compress the four images.
4. **Batch 4, hardening and tests (about 1 day): S-1 to S-6, O-2 to O-5.** Start the CSP in
   report-only mode; fix the lookup input handling; fix or retire the older QA scripts.
5. **After each batch:** lint, offline tests, deploy, then re-run `tests/qa/run-qa.js` and the
   axe audit; commit one fix per commit.

Re-run the crawl script after batch 2 and batch 3 and compare counts against this register.

## Fix status (2026-11-22)

- Fixed earlier (3.119 to 3.134): CO-1 to CO-4, RS-1 to RS-7, RS-9, A-1.
- Fixed in 3.135.x: **S-3, S-4, S-5** (lookup API accepts ports, trailing dots, paths and Unicode names; unknown `parts` refused with a message), **RS-8** (descriptions kept within 160 characters; register and thank-you have one), **RS-10** (`og:url` on pages the plugin draws; register share title), **RS-12** (`favicon.ico` and `apple-touch-icon.png` written to the site root from the site icon).
- Fixed in 3.136 to 3.138:
  - **A-2:** unique ids for combined stylesheets.
  - **A-3, A-4:** tap targets of at least 32px; small text at 12.5px.
  - **A-5:** `aria-level` where heading levels skip.
  - **A-6:** iframe titles.
  - **S-1:** Content-Security-Policy in report-only mode, with the Tools > CSP reports screen. Zero reports after the fixes. Set option `db_csp_enforce` to `1` to enforce it.
  - **S-2:** COOP and CORP headers.
  - **S-6:** archive history from the Wayback CDX API.
  - **RS-11:** Product schema only for priced, available names with an image.
  - **CO-5:** the plan start date follows the site's time zone.
  - **P-1:** news block cached and refreshed by cron.
  - **P-2, P-3, P-6 (partly):** the UI enhancer script is served as a cached file, about 40KB less HTML per page.
  - **P-4:** cluster and infographic images compressed: 7.7MB to 4.0MB in the repository, 14.4MB to 5.0MB on beta (backups in `uploads/db-img-backup/2026-11/`).
  - **P-5:** the cache warmer bypasses LiteSpeed, so warm passes now save pages.
  - **O-3:** Turnstile test mode for scripted QA (option `db_turnstile_test_until`, staging only, at most 2 hours).
  - **O-4:** weekly live QA and axe audits on GitHub (`live-qa.yml`).
  - **O-2:** the old QA scripts were repaired and re-run on beta:
    - fixes: editor-load waits, Turnstile tokens, stale sign-in checks, webhook helper exit
    - results: wishlist-flow 20/20, account-flow 27/27, offer-flow 12/12 (webhook steps skipped, because they would mark a real listing sold), sales-features 14/14
  - **O-5:** checked the certificate decoder, DNS propagation checker and nslookup pages: all work, no change needed.
- Fixed in 3.139.x:
  - **O-1:** each Google tag now prints once. The theme header and Site Kit both printed GTM `GTM-W7B64D4`; Site Kit and Rank Math both configured GA4 `G-56DWCMLT8V`. The first copy is kept and later copies are removed (`db_hyg_dedupe_tags()`). No analytics source was turned off.
    - Owner may still want to tidy the settings: turn off Rank Math > Analytics > "Install code", and remove the theme's hard-coded GTM.
    - The GTM container also sends to a second GA4 property (`G-CZV2HH7JWM`) and an old Universal Analytics id. Remove those tags in GTM if they are no longer used.
  - **P-6:** the header script, mobile menu CSS and JS, and checkout CSS are served as cached files. `/buy-now/` went from 195 KB to 136 KB.
    - The 15.5 KB footer CSS (`db-ft-css`) comes from the standalone UI Fixes add-on, so it is not changed here. Deactivating that add-on switches to the bundled copy.
- QA on 3.139.2: run-qa 398/398, axe clean on 11 pages at desktop and phone width.
- Nothing open on this register.

## Price change log

- **2026-11-22, beta:** every published, unsold listing priced under $10,000 was raised 35% (rounded to the dollar): 89 listings. Old prices are kept in post meta `_db_price_pre_35` and in the option `db_price_backup_20261122` (post id to old price), so the change can be reversed. Watcher price emails were not sent for this change; price history records it. Not applied to production (no access; production was returning 500).
