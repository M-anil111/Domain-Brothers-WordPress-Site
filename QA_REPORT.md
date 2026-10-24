# Domain Brothers: full-site QA report (staging)

Site: `beta.domainbrothers.com` · Plugin: Domain Brothers Custom Blocks v3.43.0 · 2026-10-24
Commits: `894986a` (fixes and test suites), plus this report.

## Results

| Suite | Before fixes | After fixes |
|---|---|---|
| `tests/qa/run-qa.js`: 265 live checks (HTTP + real browser, desktop and mobile) | 242 / 265 | **265 / 265** |
| Same suite after section 7 (345 checks: added one-title, `rel=noopener`, duplicate-id checks and `/sold/`, `/epp-guidelines/`) | — | **345 / 345** |
| `tests/plan/plan-lifecycle-test.php`: 26 offline payment-plan checks | did not exist (would have failed: see 1.1) | **26 / 26** |

How to re-run both suites is in `tests/README.md`.

### What the live suite covers (per page type: home, domain, all-domains, category, news, post, services, service, about, FAQs, contact, offer, privacy)
- **HTTP:** status code, no PHP error text, response time.
- **SEO:** title 25–65 characters, description 70–160, canonical points at itself, exactly one `<h1>`, structured data (JSON-LD) parses, noindex on staging.
- **Accessibility:** every image has alt text.
- **Browser (desktop 1366px and mobile 390px):** no horizontal scroll, no uncaught JavaScript errors, no broken same-site assets, screenshot, timings.
- **Security:** HSTS, nosniff, frame, referrer and CSP headers; X-Powered-By hidden; REST user enumeration blocked; `?author=` enumeration; XML-RPC.
- **Checkout:**
  - bare `/checkout/`, `/buy-now/` and `/payment-plan-setup/` redirect;
  - legacy `/checkout/` forwards to Stripe;
  - `/buy-now/` is never cached and refuses domains that aren't listed;
  - the thank-you and payment-successful pages work.
- **Plans:** the chooser shows 4 terms. Each term's schedule has the right number of rows, sums to price plus interest ($2,500 / $2,500 / $2,625 / $2,750), and its checkout link carries the right `months=`.
- **Other:**
  - domain-page Buy button carries the right domain and price;
  - domain details section is 200+ words;
  - offer form 1878 redirects on-site;
  - 404 page works, and so does search;
  - OKF markdown bundle, sitemap, and a forged "update my card" link is rejected.

## 1. Bugs found and fixed

### 1.1 Payment plans: the failed-payment flow was only half connected (critical, my earlier work)
In the previous round I reported the missed-payment process (grace period, day 3/6/9 notices, termination, recovery) as done. That was wrong. The Stripe-side edit failed partway, so:

- A declined monthly payment went to the generic "payment failed" email. The plan never became past due, so no notices went out and no termination ever happened.
- Retries still used the card saved at the start, not one the customer updated.
- The old daily billing job still ran alongside the new one.
- A late payment that cleared never sent "back on track", and the plan stayed flagged overdue.

**Fixed:** declines now go into the grace-period flow. The duplicate billing job is removed. Retries use the customer's current default card. The recovery step works. `tests/plan` now drives all of it: start → reminder → charge once → decline → day 3/6/9 notices + retries → recovery → termination.

### 1.2 The same domain could be sold twice (high)
A listing stays published after it is bought, and nothing stopped a second person paying for it.
- **Fixed:** checkout now refuses (HTTP 410, "This domain is no longer available") once a domain is fully paid or is on an active or past-due plan.
- **Terminated plans:** a domain whose plan was terminated becomes available again.
- **Still needed:** unpublish sold listings in wp-admin. Doing that automatically is a separate decision.

### 1.3 A new buyer inherited the previous buyer's payment history (high)
Plan records are stored per domain. When a new plan started on a domain whose last plan had ended, the old payment history was merged in, so "paid so far" and forfeiture amounts were wrong. **Fixed:** the old record is archived and the new plan starts clean.

### 1.4 JavaScript error on every page (medium)
The theme loads Google AdSense and places an ad slot that earlier CSS hides. A hidden slot has zero width, so AdSense threw "No slot size for availableWidth=0", which the suite saw as error "Y". **Fixed:** the AdSense script and slots are removed. There's no approved AdSense account here, and ads on a sales page send buyers elsewhere.

### 1.5 Contact Form 7 errors on every domain page (medium)
An old hard-coded "Make an Offer" box was still wrapped in the CF7 class. CF7 tried to load form "NaN", got a 404, and threw twice. **Fixed:** the wrapper is renamed so CF7 leaves it alone. The real offer form (1878) is unaffected.

### 1.6 XML-RPC was open (medium, security)
`xmlrpc.php` answered `system.listMethods`. It's a common brute-force and pingback-abuse target, and nothing on the site uses it. **Fixed:** it now returns 403, and the X-Pingback header is removed.

### 1.7 Canonical links pointed at redirects (medium, SEO)
On category archives and `/news/`, the canonical link had no trailing slash, and that URL 301s to the slashed one. **Fixed:** every canonical now matches the real URL.

### 1.8 Homepage had two `<h1>` headings (low, SEO)
"Featured Top Domains" was promoted to `<h1>` because the hero heading is added later in rendering. **Fixed:** the front page is excluded from that rule.

### 1.9 Missing theme images (low; one was a real usability bug)
The theme stylesheet references two files that don't exist:
- **`cta_1.png`:** a decorative image, now removed.
- **`slid_ic_hover.png`:** the tick shown when a checkbox or radio is ticked, for example "Unlock the domain? Yes/No" and terms consent. A ticked box showed nothing. Now drawn with CSS.

### 1.10 Theme queries hit tables that don't exist on staging (medium)
The theme hard-codes production's table prefix (`Dmb_posts`, `Dmb_hitDomain_final`), but staging's prefix is `wp_`. Those queries failed silently, which broke the domain-page view and inquiry stats and the owner lookups. **Fixed:** the hard-coded prefix is rewritten to the real one. Production, where the prefixes already match, is unaffected.

## 2. Database review

The database is 151 MB, with 119 tables, on MariaDB 11.8.

| Finding | Size / count | Action |
|---|---|---|
| Autoloaded options (loaded on every request) | 565 KB, of which ~242 KB belonged to uninstalled plugins (WebinarIgnition, WP Automatic) | **Fixed:** autoload switched off for those options, and 72 expired plus 6 orphaned feed transients deleted. Now **181 KB**. Reversible. |
| Domain posts that aren't live | 17,891 private + 1,781 "unpublish" + 52 trash, against **207 published** | **Recommend.** This is about 90% of `wp_posts` and most of the 438k `wp_postmeta` rows (55 MB). Archive or delete them after a backup. That's your call, since they may be sold-domain history. |
| Post revisions | 4,926 | **Recommend:** limit revisions in `wp-config.php` (`WP_POST_REVISIONS` 5) and clean the old ones. |
| Tables from removed plugins | Yoast (~19 MB across 3 tables), WP Automatic, GOTMLS, old PayPal orders | **Recommend:** drop after a backup. |
| CF7 submissions | 27,580 rows (10 MB) | Keep, since these are leads. Consider a retention policy. |
| Indexes | `wp_postmeta` has the standard `post_id` and `meta_key` indexes; 10 orphaned meta rows | Fine. |
| Plan and sale records | Stored per domain in `wp_options`, not autoloaded | Fine at this volume. A dedicated orders table would be cleaner if sales grow. |

New admin-only tools (logged-in administrators only):
- **`/?db_db_report=1`:** read-only report of table sizes, autoload and bloat.
- **`/?db_db_maint=1` and `&commit=1`:** the safe clean-up above. It runs as a dry run unless `commit=1` is added.
- **`?db_perf=1` on any page:** query count, time and the slowest queries, shown as an HTML comment.

## 3. Speed

- **Server time:** about 0.42–0.5 s of PHP per page. No single database query took more than about 30 ms, so the database isn't the bottleneck.
- **WPForms Lite:** it had 0 forms and 0 uses but ran queries and background jobs on every request, and its scheduled job is the one Site Health reports as failing. **Deactivated on staging.** Homepage PHP time went from 500 to 419 ms and memory from 74 to 53 MB.
- **Page times:** 0.6–0.9 s to first byte, and 1.3–1.6 s to full load in the browser. All staging timings are uncached: LiteSpeed doesn't cache requests that carry the Basic Auth password, so production with caching will be faster than this. Site Health's "page cache not detected" warning on staging comes from this too.
- **Recommendations:**
  - Turn on a persistent object cache (Redis or Memcached via LiteSpeed Cache and hPanel).
  - Keep the homepage at 8 cards per column.
  - Consider dropping the theme's unused jQuery plugins later.

## 4. SEO
Every tested page passes the title, description, canonical, single-H1, structured-data and alt-text checks.
- **Structured data:** domain pages have product, price and breadcrumb data, news posts have article data, and categories have collection and list data. Organization and website data is on every page. FAQ data is no longer on the homepage, where its questions weren't visible.
- **Staging:** every page sends noindex.
- **Still open:** the physical `robots.txt` on staging still says allow. Have the host replace it with `Disallow: /`.

## 5. Security
- **Fixed this round:** XML-RPC closed, AdSense removed. On staging, five plugins were updated earlier and WPForms is now deactivated.
- **Already in place and verified:** HSTS, nosniff, frame and referrer headers; X-Powered-By hidden; REST user list blocked; author enumeration blocked; forged card-update links rejected.
- **Still needs you:**
  - **PHP patch (critical).** Patch the PHP vulnerabilities CVE-2026-109618 and CVE-2026-109619. Beta needs 8.5.12 (it has 8.5.11) and production needs 8.2.35 (it has 8.2.21). This is done in hPanel or by Hostinger.
  - **Core security updates are switched off.** `WP_AUTO_UPDATE_CORE` is false. Set it to `'minor'` so security releases install automatically.
  - **The content security policy is minimal.** It only upgrades insecure requests; a fuller policy would be a later project.
  - **Production plugins:** update them as was done on staging.

## 6. Not tested
- **Real Stripe payments:** a card payment, the Stripe notifications reaching the site, or the customer portal. Beta has no Stripe keys, so these are only covered by the offline test.
- **Live form submissions:** submitting forms would send real emails and create leads.
- **Emails arriving in inboxes:** only rendering was checked.

## 7. Production HTTP audit (132 URLs, 796 failures) checked against staging

The production audit ran against `www.domainbrothers.com`, which doesn't have any of the plugin work. I re-ran the same checks on staging for all 55 URLs named in the report.

**Already passing on staging, no change needed.** These account for most of the 796 failures:
- `exactly_one_title` (132) and `jsonld_valid` (131);
- `title_25_65` (112), `exactly_one_h1` (61) and `canonical_self` (33);
- `all_images_have_alt` (28), `has_viewport` and `html_has_lang`;
- `production_indexable` (10). On production, the utility list in `db-seo-meta-block.php` already noindexes every flagged page (`/buy-now/`, `/checkout/`, `/domain-transfer/`, `/payment-cancel/`, `/payment-successful/`, `/installment-detail-*`, `/payment-plan-setup/`) and keeps them out of the sitemap.

**Fixed in v3.41.2** (`db-onpage-seo-block.php`):
| Audit check | Cause | Fix |
|---|---|---|
| `blank_links_safe` (122) | Footer social icons on every page, outbound news-source links and domain "visit" links opened in a new tab without `rel="noopener"` | `rel="noopener"` is added to every `target="_blank"` link. Curly-quoted `rel=”nofollow”` on `/epp-guidelines/` is normalised. |
| `no_duplicate_ids` (26) | The theme repeats `id="browse_by_domain"` on every sidebar table | The second and later copies become `browse_by_domain-2`, `-3` and so on. The tab widget's selector is updated to match. |
| `exactly_one_h1` on `/sold/` | Two bugs: the theme's commented-out `<!--<h2 class="page-title">` was promoted instead of the visible heading, and the template's `query_posts()` makes `is_singular()` false by flush time | The fixer now skips headings inside comments, and the page context is captured at `template_redirect`. Pages with no `<main>` get the heading after `<body>`. |
| `no_http_navigation_links` and `no_mixed_content` | `http://` links and images | Rewritten to `https://` for hosts verified to serve valid TLS: own domain, domaining.com, domaingang.com, thedomains.com and domainnamewire.com. |
| (found while checking) `/payment-cancel/` unstyled | The template builds `…domainbrothers.comwp-content/…` (missing slash), so both stylesheets 404. It also hard-codes `<title>DomainBrothers</title>`. | URL repaired, and the title is now "Payment Cancelled". |
| Broken images in old news posts | 2017-era hotlinked images from domainpulse.com (403, expired certificate) and dropgrabs.com (offline) | Images that fail to load inside article content are hidden, along with their caption box. |

**Remaining, accepted:**
- **`http://` links to hosts without working HTTPS:** domainpulse.com (expired certificate) and the parked `.ca` names in the domain list (e.g. holi.ca). Rewriting these would break the link.
- **Empty meta description on noindex utility pages:** `/thank-you/`, `/payment-successful/`, `/confirm-subscription/`, `/paypal-ipn/`. These pages never appear in search.
- **`/plugnpay/`:** an empty legacy payment-gateway endpoint. It is noindexed (header) and excluded from the sitemap. Recommendation: delete the page if PlugnPay is no longer used.

**Production:** all of the above, and the earlier sections, take effect on production only when this plugin is installed there.

## 8. Pending items done, and a full-site crawl (v3.42.0 to v3.43.0)

**Results:**
- `tests/qa/run-qa.js`: **353 / 353**.
- `tests/plan`: **29 / 29**.
- `tests/seo/html-hygiene-test.php`: **20 / 20**.
- Full crawl of all **1,785** sitemap URLs: every page returned 200. After the fixes, a re-check of the 282 pages that failed shows no title, H1, schema, id or mixed-content failures.

### Pending items completed
- **Payment-plan consent:** a required "I agree" checkbox on plan checkout, covering hand-over on the final payment and forfeiture after 10 days unpaid.
  - The time of acceptance and a fingerprint of the terms text are stored on the Stripe payment and on the plan record.
  - Tested offline only, because staging has no Stripe keys, so the checkout form isn't shown there.
- **Filter and sort on `/all-domains/`:** search by name and filter by extension, maximum length and maximum price. Sort by name, price or length.
  - It works across all 207 listings through a cached `/wp-json/db/v1/domain-index`.
  - Browser-tested on desktop and mobile.
  - Without JavaScript the page is unchanged.
- **WordPress security updates:** minor core releases install automatically again, even though `WP_AUTO_UPDATE_CORE` is false. Major versions remain manual.
- **Revisions:** limited to 5 per post.

### Bugs found by the crawl and fixed
| Finding | Pages | Fix |
|---|---|---|
| **The admin login name was public**: "By admin" byline, `/author/admin/` links and `"author": "admin"` in the Article schema | 1,515 | Shown as "Domain Brothers". Author links go to `/about-us/`. `/author/*` returns 301 to `/news/`. oEmbed author replaced. |
| **Titles containing `$1` broke the page**: "So $1,999 for 83.com" had its `$1` replaced with the page's `<main>` tag inside the breadcrumb, giving a second `<main id="main">` | 13 | Breadcrumb insertion uses a callback. The same latent bug in the homepage hero insert is fixed too. |
| Affiliate tracking pixels and widgets in imported posts (Commission Junction, Amazon, Business Wire), plus links that disguised the affiliate URL as godaddy.com | ~15 | Trackers removed. Affiliate links marked `rel="sponsored nofollow"`. The disguising code is removed. |
| Duplicate posts (`…-enom` and `…-enom-2`, same title and text) | 13 pairs | The copy's canonical points at the original. |
| Different posts with the same `<title>`, because long titles were cut to 42 characters | 26 groups | Long titles keep their distinguishing end (a date or domain name), and the brand suffix is dropped when it won't fit. |
| Internal links without a trailing slash, each a 301 (category links, `/privacy-policy`, `/3-character-domains`), and `/domains/skiClub-ca/` with capitals | every page | Rewritten to the real URL. |
| `/about-us/` linked `/sell-your-domain/`, which returned 404 | 1 | 301 to `/contact-us/`. |
| Domain-page descriptions over 160 characters for long names | 5 | A shorter template is used when the full one is too long. |
| Pasted ids that clash with the theme (`main`, `content`) or repeat inside a post | 26 | Stripped, or de-duplicated. |
| `http://` images in imported posts | 183 | Upgraded to `https://`. The browser already did this; images that fail to load are hidden. |

### Left as is
- **`http://` links to sites without working HTTPS:** about 590, mostly domainpulse.com (expired certificate) and the parked `.ca` names.
- **One post with a 63-character description:** its excerpt is naturally short.
- **Old affiliate links:** they are kept but marked sponsored. Deleting them is a content decision.
