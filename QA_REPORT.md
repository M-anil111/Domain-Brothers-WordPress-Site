# Domain Brothers: full-site QA report (staging)

Site: `beta.domainbrothers.com` · Plugin: Domain Brothers Custom Blocks v3.46.0 · 2026-10-24
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

## 9. Accessibility and performance pass (v3.44.x)

**Tools:**
- **axe-core 4.10** (WCAG 2.1 A/AA) on 14 pages at desktop and mobile widths (28 scans).
- **Lighthouse 12** (mobile) on the home page, a domain page, `/all-domains/` and a post.

**Results:**
- **axe:** 28 scans had violations at first; now **0 violations on all 28**.
- **Lighthouse accessibility:** now **99–100** on the domain page and `/all-domains/`, and 98–99 on the home page and the post.
- **Live suite:** **385 / 385**, after adding zoom and paste checks to every page.

### Fixed
| Issue | Where | Fix |
|---|---|---|
| **Pinch-zoom disabled** (critical): `maximum-scale=1, user-scalable=no` | every page | Removed from the viewport tag. |
| **Body text rendered at 10px on mobile**: the plugin's design tokens are in `rem`, but the theme's Bootstrap 3 sets `html { font-size: 10px }`. Lighthouse found only 51% of homepage text legible. | site-wide | Body and small-text tokens set in px (16 and 14), card headings 18px. Only an 11px uppercase label remains. |
| Six form fields with no accessible name: the label sits next to the field, not around it | `/offer/` | Each field gets an `aria-label` taken from its visible label. |
| Cut, copy and paste blocked on phone and offer fields | domain pages, contact | Inline `onpaste="return false"` handlers and jQuery `cut copy paste` bindings neutralised. |
| Colour contrast: white on brand green (2.35:1); greys #999, #888, #777; link blue on dark buttons; green headings (2.35:1) | domain pages, services, `/sold/`, plan page | Darker brand green for text and buttons, darker greys. Visually checked. |
| Links in text distinguished by colour alone (2:1) | about, contact, domain pages, home | Underlined. |
| Duplicate image links in hidden figures reachable by keyboard | `/news/` | `tabindex="-1"`. |
| Four "Learn More" links with no context; a "here" link | home, domain pages | Hidden text names the service; "here" becomes "privacy policy". |
| Trust icons: `alt="happy"` on the best-price badge, a misspelt "Gaurentee", redundant alts | domain pages | Fixed, and decorative icons get empty alt text. |
| The domain-page banner is a CSS background, so it was discovered late (slow LCP on mobile) | domain pages | `<link rel="preload" fetchpriority="high">`. The production-host rewrite now leaves `/images/detail/` alone. |

### Not fixable in the plugin (for you or your marketing team)
- **Third-party tags block the main thread for about 860 ms on mobile.** These are Google Tag Manager, gtag (GA4 and Google Ads), Microsoft Clarity, HubSpot, AddToAny and Cloudflare Zaraz. They are the main reason Total Blocking Time is 670–1,220 ms and mobile performance is 42–78.
  - Removing unused tags, or loading them after the user's first interaction, is a marketing decision, because it affects ad attribution.
- **Universal Analytics `UA-78917810-32` still loads** (`analytics.js`, `linkid.js`) through Cloudflare Zaraz. Google stopped processing Universal Analytics in July 2024, so it collects nothing. Remove it in the Cloudflare dashboard under Zaraz.
- **Staging timings run with no page cache,** because of the staging password, and Lighthouse timings varied by up to 3× between runs on the shared host. Measure performance on production after deploy.
- **Small leftovers:** the theme's `heading-order` (skipped heading levels) and `td-has-header` (sidebar tables without headers) are minor theme-template issues.

## 10. Independent code review of sections 7–9, and fixes (v3.45.0)

A separate reviewer, which hadn't seen my reasoning, audited the ~1,100 lines added in sections 7–9. It reported 10 defects and reproduced 8 of them with test harnesses. All 10 are fixed, and each reproduced case is now a regression test.

| # | Defect | Severity | Fix |
|---|---|---|---|
| 1 | One invalid UTF-8 byte on a page containing `=”` blanked the whole page: the curly-quote regex used `/u`, which returns null on bad bytes, and later steps turned null into `''` | high | Byte pattern without `/u`. The output buffer and content filter now fall back to the original markup if any step returns nothing. |
| 2 | Escaping hole in the page `<title>`: an entity-encoded post title such as `&lt;/title&gt;&lt;script&gt;…` printed as real markup. This predates my change but was kept by it. | high | `esc_html()` on the `pre_get_document_title` result. Existing entities are not double-encoded. |
| 3 | The `-2` duplicate rule would also canonicalise distinct posts in a series that share a title | medium | The canonical now also requires identical post text. |
| 4 | The link normaliser could rewrite inside JavaScript strings (`'<a href="/news'+s`), because its closing quote wasn't tied to the opening one | medium | Back-reference to the opening quote. |
| 5 | Title shortener: a single long word became just "…", and "…Demand More" lost its last letters | low | Word boundaries, and a cut when no word fits. An empty title becomes "Domain Brothers". |
| 6 | `rel` rewrite dropped an unquoted `rel=nofollow`, and edited `rel` text inside other attributes | low | Attribute-aware `db_onpage_set_rel()`, used for both noopener and sponsored links. |
| 7 | Icon alt fix did nothing when the theme gave no alt, so the filename became the alt | low | Alt is inserted when missing. |
| 8 | Domain lower-casing also lower-cased query strings | low | Stops at `?` and `#`. |
| 9 | Plan terms consent was only best-effort: if the call that records it failed, the payment still went through | medium | For plans, recording the acceptance must succeed before the card is charged, with a clear error if it fails. |
| 10 | A `<!--` inside a script made a real `<h1>` look commented out | low | Heading and comment checks run on a copy with script and style bodies blanked. |

Also fixed:
- Tracker iframes are removed together with their fallback content.
- Ids that a link in the same post points to (`href="#page"`) are kept.

### Theme leftovers, also fixed
- **Homepage headings:** the feature and service card headings were `<h4>` directly under an `<h2>` (Lighthouse heading-order). They are now `<h3>`. Styling is identical, and the scripts that read them are updated.
- **Sidebar "Browse by" tables:** marked `role="presentation"`, since they are layout tables (td-has-header).
- **Domain-page copy:** "Other Domain you might be interested In:" is now "Other domains you might be interested in:".

**Results:**
- **Suites:** live 385/385; offline plan 29/29, markup 30/30, title 10/10.
- **axe:** 0 violations across 28 scans.
- **Lighthouse accessibility:** 100 on the home page and `/all-domains/`.
- **Crawl re-check:** 282 pages, all 200, none blank or missing a title.

## 11. Independent review of the payment code (v3.46.0)

A second reviewer, which hadn't seen my reasoning, audited the Stripe checkout and webhooks (`db-stripe-block.php`) and the plan lifecycle (`db-plan-lifecycle-block.php`). It reported 10 defects and reproduced 7. I found an 11th while fixing them. All are fixed, and the offline plan test now covers each one: **50/50**, up from 29.

No real payment has run through this code yet, because staging has no Stripe keys. These were found before go-live, not after.

| # | Defect | Fix |
|---|---|---|
| 1 | **A late or resent success event reopened a completed or terminated plan, which led to a repeat charge.** For example, a resend of installment 2 after the plan completed set it back to "active", and the next cron run charged again. | A plan payment is applied only for the installment the plan expects, from the plan's own customer. Anything else is not applied, and the admin gets "PAYMENT NEEDS REVIEW (possible refund)". Processed PaymentIntent ids are stored with the plan, replacing a 14-day transient. |
| 2 | **Retry succeeded during the notice email → overwritten.** The daily job saved a copy of the plan it had read before sending, wiping a success that arrived meanwhile, so the paid installment was charged again on day 6. | The job re-reads the plan after the charge and again after the email. Only the notice stage is written, onto the latest record. A success reported by Stripe straight away skips the notice entirely. |
| 3 | **Second buyer for the same domain:** both payments were accepted, and the second plan was merged into the first (wrong payment history, customer replaced). | Availability is re-checked when a payment arrives. A full sale or a first installment for a sold or reserved domain is not applied, and the admin is alerted to refund it. |
| 4 | **Any payment on the Stripe account was treated as a domain sale,** for example a Payment Link: "Your domain is secured!" email, "DOMAIN SOLD" alert, and a sold flag for an empty name. | Events without this checkout's `domain` and `type` metadata are ignored. |
| 5 | **"Update my card" link** was signed over the domain only and never expired. An old or forwarded email could open the card portal for a later buyer of the same domain. | Signed over the domain, the plan's Stripe customer and an expiry (21 days). It fails for any other customer. |
| 6 | **Case bypass:** a plan stored as `Fatality.ca` didn't block a checkout for `FATALITY.CA`. | One lower-case spelling everywhere: metadata, record keys, index and sold flag. |
| 7 | **Overlapping cron runs could each charge the same installment;** no idempotency key. | Atomic lock per plan, `Idempotency-Key` on every off-session charge, and the attempt recorded *before* the request. |
| 8 | **Stripe API errors** (bad key, deleted customer, mode switch) silently pushed the installment a month out. Test-mode purchases also marked real listings sold. | Errors leave the due date in place, retry the next day and alert the admin ("PLAN CHARGE ERROR"). A success or decline in the charge response is applied immediately, without waiting for the webhook. Test and live mode keep separate plan, index and sold records. |
| 9 | **Webhook marked handled before processing,** so a failure mid-way was never retried. | Atomic `add_option` claim, released (HTTP 500 → Stripe retries) if processing throws. Old claims are purged after 30 days. |
| 10 | **Receipt email could be changed after payment,** redirecting plan reminders and card links. | Only allowed while the payment is still open. |
| 11 | **Receipt-email endpoint accepted forged secrets** (found while fixing #10). It only extracted the PaymentIntent id from `pi_…_secret_…` and never compared the secret, so anyone with a PaymentIntent id could set its receipt email. | The full client_secret must match Stripe's (`hash_equals`). |

Also fixed:
- **Webhook signatures:** any of several `v1` signatures is now accepted, so verification keeps working while the signing secret is rotated.
- **Stale declines:** a failure event for an earlier installment no longer marks a current plan past due.
- **Termination re-check:** termination re-reads the plan first, so a payment that just cleared is not terminated.

Verified sound by the reviewer and left as is:
- **Webhook security:** HMAC verification with a 5-minute window, and only the active mode's secret accepted.
- **Amounts:** charges always use the server-side listing price, and the installment maths sum exactly.
- **Admin routes:** they check capability and nonce.
- **Emails and portal:** emails are escaped, and the billing portal's return URL is fixed.

**Results:**
- **Offline:** plan 50/50, markup 30/30, titles 10/10.
- **Live suite:** 385/385.
- **Checkout check:** checkout for `FATALITY.CA` normalises to `fatality.ca`.
