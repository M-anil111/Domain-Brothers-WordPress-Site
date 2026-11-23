# SEO engineering: what is in place and how to check it

Status on 23 Nov 2026, plugin 3.149.3 on staging (`https://beta.domainbrothers.com`). Production
still runs an older plugin; the items marked **owner** need an account this repository has no access to.

## 1. Rendering and crawl architecture

| Item | Where | State |
|---|---|---|
| Server-side rendering | WordPress renders every page on the server; listings, prices, filters' first state, breadcrumbs and JSON-LD are in the HTML. Inline scripts wait for the first interaction (`db-delay-block.php`), so nothing a crawler needs depends on JavaScript. | Done |
| Page cache | `db-pagecache-block.php` + `pagecache/serve.php` (static HTML for anonymous visitors), then Hostinger's LiteSpeed cache, then Cloudflare (static assets, 1-year `max-age`). Cached TTFB 0.23–0.7 s. | Done |
| Edge HTML caching | Cloudflare answers `cf-cache-status: DYNAMIC` for HTML. Caching HTML at the edge needs a Cloudflare Cache Rule ("Cache Everything" for anonymous requests, bypass on the `db_wl` and `wordpress_logged_in_*` cookies, edge TTL 10 min). | **Owner**: Cloudflare dashboard |
| 301 normalisation | `db-crawl-block.php`: uppercase paths and `//` → 301 to the lowercase single-slash path; `/page/N/` on static pages and the front page → 301 to the page; legacy routes 410 (`db-safety-block.php`). | Done |
| Parameter traps | Search and filter URLs (`s, q, sort, order, orderby, tld, ext, max, min, len, length, view, filter, cat, price`) send `X-Robots-Tag: noindex, follow` and are disallowed in robots.txt; canonicals never carry a query string. | Done |
| Canonical on every page | Rank Math writes it on WordPress objects; `db-crawl-block.php` adds one to any 200 page that has none (the plugin's own routes, theme templates), built from the clean URL. | Done |
| Segmented XML sitemaps | Rank Math: posts, pages, domains (sold and not-for-sale names excluded in SQL, so page counts stay right), categories. Plugin: `/domain-tools-sitemap.xml` (17 tools) and `/db-pages-sitemap.xml` (seller, payment terms, finder, collections, trust centre, glossary, price index, 17 domain categories). The empty Local SEO sitemap and the broken `/domains/` archive are left out. | Done |
| Accurate lastmod | Listings: the later of the post edit and `_db_changed_at`, set whenever `domain_price`, `db_listing_state` or `domain_status` changes (the 89 listings repriced on 22 Nov were backfilled). Plugin pages: the date the file that builds them last changed by content hash (option `db_crawl_file_seen`), not the deploy time. Lists: the newest listing change. | Done |

## 2. Structured data and AI readiness

| Item | Where | State |
|---|---|---|
| One JSON-LD graph per page | Rank Math's graph, repaired by `db-schema-block.php` (`rank_math/json_ld`): Organization with a 600 px square logo (option `db_schema_logo_attachment`) and a sales contact point; absolute `@id`s and a WebPage url on the plugin's own routes; breadcrumbs that match the visible trail. | Done |
| Organization | `https://…/#organization`, referenced by WebSite, articles (author and publisher) and every Product offer's seller. | Done |
| Product | Priced listings only (`db-custom-blocks.php`, block 9): name, description, image, brand, Offer with price, USD, InStock and the Organization as seller. Offer-only, sold and not-for-sale names get no Product. | Done |
| Article | Rank Math's BlogPosting; the author was a Person named after the brand and is now the Organization. | Done |
| FAQPage | Only on pages that show the same questions (FAQs page, tool pages, guides). | Done |
| BreadcrumbList | From `db_breadcrumbs_trail()`, the same trail visitors see; one per page. | Done |
| Team pages | The theme's invalid `"@type":"person"` block (personal email, birth date, production URLs) is removed from the output; Rank Math's Person stays. | Done |
| Semantic, chunk-friendly HTML | One `<h1>` per page (`db_hyg_single_h1`), real heading order in articles with a TOC, `<nav aria-label="Breadcrumb">`, `<main>` landmark, lists and tables as HTML, FAQ answers as plain text under their question, `llms.txt` (`db-site-hygiene-block.php`). | Done |
| Crawler and AI bot rules | `db_crawl_robots_txt()`: private and checkout routes and filters disallowed, `admin-ajax.php` allowed, abusive scrapers (Bytespider, MJ12bot, DotBot, BLEXBot, DataForSeoBot, serpstatbot) blocked. Search crawlers and AI answer/retrieval crawlers (OAI-SearchBot, ChatGPT-User, PerplexityBot, Claude-User/SearchBot) are allowed. Training crawlers (GPTBot, Google-Extended, CCBot, anthropic-ai, ClaudeBot, Applebot-Extended, meta-externalagent) are blocked only when option `db_crawl_block_ai_training` is `1`. Staging always serves `Disallow: /`. | Done in WordPress |
| robots.txt at the edge | Cloudflare's managed robots.txt (Content Signals) answers `/robots.txt` on beta before WordPress, so the rules above, and staging's `Disallow: /`, are not what crawlers read. Staging pages still send `noindex`. Turn off "Manage robots.txt" in Cloudflare (Security > Bots / AI Crawl Control) on both zones, or keep it and add the same rules there. | **Owner**: Cloudflare dashboard |

## 3. Core Web Vitals

Measured with Playwright on a Moto G4 profile (4× CPU throttle, slow network) and desktop, uncached:

| Page (phone) | LCP before → after | Longest task before → after | CLS |
|---|---|---|---|
| Home | 3.7 s → 3.6 s (TTFB-bound uncached) | 372 ms → 232 ms | 0 |
| All Domains | 2.4 s → 2.5 s | 532 ms → 533 ms (third-party, see below) | 0.006 |
| Listing | 4.1 s → 2.2 s | 305 ms → 255 ms | 0 |
| Article | 2.1 s → 2.1 s | 266 ms → 301 ms | 0 |

What changed:

- **INP.** The delay loader used to run every delayed script inside the visitor's first tap, so the
  response to that tap waited for all of them. It now yields first and runs one script per task
  (`db-delay-block.php`). After the change the work following the first interaction is split into
  tasks of 50–450 ms instead of one block.
- **Turnstile** (Cloudflare's bot check, about 100 KB of script) loads only when a form with a check
  is near the viewport or focused, not on every page (`db-turnstile-block.php`).
- **Layout thrashing.** The UI enhancer read layout between style writes for every footer and header
  element; reads now come first. The sticky header changes its class once per frame at most.
- Already in place and verified: images carry width and height (CLS 0–0.017 everywhere), the
  article hero image has `fetchpriority="high"` and is not lazy, the Lato subset (31 KB) uses
  `font-display: swap`, static assets get a 1-year cache and are served from Cloudflare's edge.

Remaining main-thread time on phones is third-party: AdSense (~950 ms CPU), Google tag (~940 ms),
Microsoft Clarity (~490 ms) and Cloudflare Zaraz (~250 ms, injected by Cloudflare). Removing AdSense
from listing and checkout pages, or Zaraz if Google tag already covers the same tracking, is the
largest remaining gain. **Owner decision.**

## 4. Automation

| Tool | Use |
|---|---|
| `tools/seo-check.py` | `python3 tools/seo-check.py https://beta.domainbrothers.com --env staging` (or `--env production` on www). Errors: non-200, missing or duplicate title, missing/relative/cross-host/parameterised canonical or one whose target is not 200, noindex on a production sitemap page, staging page without noindex, robots.txt rules, broken sitemaps, invalid JSON-LD, relative `@id`, breadcrumb positions, Product offers without price. Exit code 1 on any error. Offline tests: `tests/seo/seo-check-test.py`. |
| `.github/workflows/seo-check.yml` | Runs the check weekly on staging and on demand on either site. Production on the schedule after setting the repository variable `SEO_CHECK_PRODUCTION=1`. |
| `.github/workflows/ci.yml` | On every pull request: PHP lint, version match, offline tests including `tests/seo/crawl-test.php` (27) and `tests/seo/schema-test.php` (15), and the checker's own tests (23). |
| `tools/crawl-log-report.py` | `python3 tools/crawl-log-report.py access.log.gz --days 30 --verify`. Search and AI crawler hits by section and status, Googlebot per day and top URLs, crawl waste by reason (filters, tracking parameters, private routes, redirects, deep pagination), top 404 and 5xx URLs, error spikes (a day over twice the median and at least 20). Logs: hPanel > Websites > Access logs. |

## 5. Owner actions

1. Cloudflare: turn off the managed robots.txt on both zones (or copy the WordPress rules into it).
2. Cloudflare: a Cache Rule for anonymous HTML (see section 1), if wanted.
3. Hostinger: clear the server cache after each deploy (hPanel > Websites > Cache). On 23 Nov it still
   served copies of `/about-us/`, `/faqs/`, `/news/` and others from before the canonical change.
4. Decide on AdSense, Clarity and Zaraz on commercial pages (section 3).
5. After the production deploy, set `SEO_CHECK_PRODUCTION=1` and run the SEO check workflow on production.
