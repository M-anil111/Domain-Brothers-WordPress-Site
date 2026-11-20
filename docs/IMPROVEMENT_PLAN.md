# Domain Brothers: improvement plan

Prepared 2026-11-20. Every recommendation is something that can be built or configured with little or no human involvement. Items that need a decision, an account or a login say so in the **Human** column.

## 1. Where the site stands (evidence)

I audited the **live production site** (`www.domainbrothers.com`, public) because the beta site now needs credentials I no longer hold. Production runs the older build, so many findings below are already fixed on beta and only need go-live. Those rows are marked **BUILT**.

| Area | What I measured | Result |
|---|---|---|
| Crawl | 142 URLs: all 57 pages, 45 of 1,512 posts, 40 of 207 domain pages | all return 200 |
| Social and schema | pages with Open Graph tags / JSON-LD | **0 of 57** / **1 of 57**; posts and domain pages have none |
| Headings | pages without exactly one h1 | 20 of 57 (home, All Domains, Sold have **none**); **40 of 40** domain pages (one sample had three) |
| Titles and descriptions | pages missing a title / description | 6 and 9 of 57; 33 of 45 posts have titles over 60 characters; 39 of 45 post and 40 of 40 domain descriptions are under 70 characters |
| Thin content | pages under 300 words | 26 of 57 (many are payment and utility pages) |
| Images | pages: images with no alt / no `loading` attribute | 71 of 195 / 182 of 195; domain pages: 440 of 440 lack `loading` |
| Speed (lab, headless Chrome) | server response / page weight / scripts | 1.0 to 1.6 s / 2.3 to 2.7 MB / **32 to 40 scripts, about 1.6 MB of JavaScript** on every page |
| Layout shift | `/news/` on desktop | **0.298** (poor; the limit is 0.25); home 0.052 |
| Security headers | HSTS, CSP, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy | **none sent**; `X-Powered-By: PHP/8.2.21` is exposed |
| Username exposure | `/wp-json/wp/v2/users`, `/author/admin/`, users sitemap | the `admin` username is public |
| Sitemap | what production serves | WordPress core sitemap with **1,512 post URLs**, plus utility pages such as `/plugnpay/`, `/paypal-ipn/`, `/installment-detail-*`, `/checkout/` |
| Robots | training crawlers | GPTBot, ClaudeBot, Google-Extended, CCBot and others are blocked (Cloudflare managed rules); `Sitemap:` points to `/xmlsitemap/`, an HTML page |
| Search visibility (SE Ranking, US) | organic traffic and keywords | about **2 visits a month**, 30 keywords, 1 in the top 5; the one top ranking is the brand name |
| Links (SE Ranking) | backlinks | 6,529 from 697 domains. **399 of those domains sit on six cheap extensions** (.shop .store .site .space .online .website) and 515 links use the anchor "Premium Backlinks to Strengthen SEO Authority" (likely spam) |
| Email authentication | `domainbrothers.com` DNS | DMARC is `p=none`; SPF lists only Google; no CAA record; no DNSSEC. The domain is paid to 2032-02-11 |
| Brand claims | production home title vs beta | production says "15+ years", the beta About page says "27+ years" |

Position data worth acting on: the 4-character page ranks 50, 71 and 77 for "4 letter domains for sale" variants (90 searches, difficulty 20 to 21). "Domain broker" ranks 83 (1,000 searches, difficulty 78). Search results also show **beta.domainbrothers.com URLs ranking**.

**Not verified:** beta (no credentials this session); real-user Core Web Vitals, Search Console and GA4 data (no access); competitors' sites; whether each beta fix behaves the same on production.

Legend: **Effort** S under half a day, M one to three days, L a week or more. **Human**: None, Low (a decision, a key or an account), Some (real work on your side).

## 2. Do these first (highest return)

1. Deploy the current plugin to production (A1). It resolves about a third of this list in one step.
2. Move production to the Rank Math sitemap and noindex the utility pages (A2, A3).
3. Add Open Graph and schema everywhere, and fix the h1 problem (A4, A5, B4, B5).
4. Clean the toxic backlinks with a disavow file, and keep beta out of search (B1, B3).
5. Push the 4-character and other length pages to page one (C1, C2).
6. Launch the 14 tool pages and their keyword plan (C20, C21) and submit them in Search Console.
7. Auto-accept and auto-counter offers within owner-set limits (D9).
8. Send a "names that match you" weekly email from the registration answers (K1).
9. Put CI on the repository (J2) so nothing ships untested.
10. Set DMARC to quarantine after confirming every sender (G1).

## 3. The full list

### A. Go-live: already built on beta, needs deploying to production

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| A1 | Upload plugin 3.105.x and deactivate the standalone designer plugin. | Production runs the older build; everything marked BUILT depends on this. | S | Low |
| A2 | Serve the Rank Math sitemap (26 posts, not 1,512 syndicated ones). | Production lists 1,512 post URLs, a conflict Search Console reports for noindexed pages. | S | None |
| A3 | Noindex payment, checkout and utility pages and drop them from the sitemap. | `/plugnpay/`, `/paypal-ipn/`, `/xmlsitemap/`, `/installment-detail-*`, `/checkout/`, `/buy-now/` are in the production sitemap. | S | None |
| A4 | Open Graph, Twitter cards and JSON-LD on every page. | 0 of 57 pages have them today. | S | None |
| A5 | One h1 per page (home, All Domains, Sold). | Three core pages have no h1. | S | None |
| A6 | Security headers and HSTS from the plugin. | Production sends none. | S | None |
| A7 | Hide the `admin` username (REST users, author archive, users sitemap). | The plugin already filters these; confirm it holds on production. | S | None |
| A8 | Cookie consent banner with Consent Mode v2. | None on production; European visitors need it. | S | None |
| A9 | Turnstile and the honeypot, replacing reCAPTCHA. | reCAPTCHA scripts load on every page; the new guard needs no puzzle. | S | Low |
| A10 | Payment Terms page and the six policy pages. | Missing on production; the refund policy is the legal backbone. | S | Low |
| A11 | Page cache and delayed third-party scripts. | About 1.6 MB of JavaScript per page today. | S | None |
| A12 | 301 redirects from old service and payment URLs. | Keeps existing links and rankings. | S | None |
| A13 | The 25-guide cluster and the news image guarantee. | Gives search engines real content instead of 1,512 syndicated posts. | S | None |

### B. Technical SEO

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| B1 | Build and submit a disavow file for the spam link set. | 399 referring domains on six cheap extensions; 515 links with the "Premium Backlinks…" anchor. | S | Low |
| B2 | Keep the six old posts that carry 178 backlinks each live, or 301 them to the closest guide. | Preserves link equity instead of 404s. | S | None |
| B3 | Remove beta from search and keep it behind login and noindex. | Beta URLs appear in ranking data. | S | Low |
| B4 | **BUILT** (Product and Offer schema already emitted by the plugin; missing on production only because it is not deployed). Product and Offer schema on every domain page (price, currency, availability). | Domain pages have no structured data. | M | None |
| B5 | **BUILT** (domain titles already templated by the plugin; missing on production only because it is not deployed). Fix domain page headings to a single h1 and a clean title such as "Name.ca – Premium Domain for Sale". | Sample title is "Gizmofreak.ca is on sale. Business Domain, Other Domain, Shopping Domain". | S | None |
| B6 | **BUILT** (domain descriptions already templated by the plugin; missing on production only because it is not deployed). Templated 140 to 155 character descriptions for domain pages. | 40 of 40 are under 70 characters. | S | None |
| B7 | Shorten titles over 60 characters. | 33 of 45 posts. | S | None |
| B8 | Intro copy, FAQs and ItemList schema on the 17 domain category pages. | Categories already attract links (103 to one of them). | M | None |
| B9 | **BUILT** (ItemList already emitted on category pages; missing on production only because it is not deployed). ItemList schema on every listing page. | Helps search engines understand lists of names. | S | None |
| B10 | Organization schema with logo, contact point and social profiles. | Needed for brand panels; verify the profile URLs. | S | Low |
| B11 | **BUILT** (BreadcrumbList already emitted on domain pages; missing on production only because it is not deployed). BreadcrumbList schema on domain pages. | None today. | S | None |
| B12 | "Related domains" and "more in this category" blocks on each domain page. | Internal links and cross-selling. | M | None |
| B13 | Update robots.txt so `Sitemap:` points to the real sitemap. | It points to an HTML page today. | S | Low |
| B14 | Decide the AI crawler policy: keep training blocked, allow search and answer bots (OAI-SearchBot, PerplexityBot, ChatGPT-User). | Blocking everything removes the site from AI answers. | S | Low |
| B15 | **BUILT 3.106.0** (llms.txt route). Add `llms.txt` describing the site for AI assistants. | Cheap and harmless. | S | None |
| B16 | Alt text on all images, generated in bulk and reviewable. | 71 of 195 page images have none. | M | None |
| B17 | **BUILT 3.106.0** (lazy and async image attributes). Add `loading="lazy"` and `decoding="async"` to theme images. | 182 of 195 page images and 440 of 440 domain images lack it. | S | None |
| B18 | **BUILT 3.106.0** (image width and height from local files). Width and height on images to stop layout shift. | 12 of 14 home images have none. | S | None |
| B19 | Fix `/news/` layout shift (0.298). | Above the "poor" line. | S | None |
| B20 | Preload the hero image and self-host fonts with `font-display: swap`. | Google Fonts and Font Awesome load from third parties. | M | None |
| B21 | Remove unused scripts per page (reCAPTCHA, chat, migrate). | 32 to 40 scripts per page. | M | None |
| B22 | Enable Brotli and long-lived caching for static files. | Only gzip is served; the theme stylesheet sets no cache header. | S | Low |
| B23 | Cache anonymous pages at the edge (Cloudflare). | `cf-cache-status: DYNAMIC`; server response 1.0 to 1.6 s. | M | Low |
| B24 | **BUILT 3.106.0** (X-Powered-By removed). Remove the `X-Powered-By` header. | Reveals the PHP version. | S | None |
| B25 | **BUILT 3.106.0** (helpful 404 page, 404 status, noindex). A useful 404 page (search, popular names, categories) with a proper title. | The 404 title is the generic "Get Latest Updates…". | S | None |
| B26 | Unique titles on every page. | Duplicate and empty titles on production. | S | None |
| B27 | **BUILT 3.106.0** (IndexNow on publish (off on staging)). Auto-ping IndexNow on publish and update. | Faster indexing in Bing and others. | S | Low |
| B28 | Add Bing Webmaster Tools. | A second search source. | S | Low |
| B29 | Add "Last updated" and bylines to guides, with Person schema for the founders. | E-E-A-T signals. | S | None |
| B30 | Stop syndicating full news text; use short excerpts with a link to the source. | A full copy of a TechRadar review ranks at 31, and copies dilute the site. | M | None |
| B31 | Make sure paginated domain lists are crawlable with real links. | Otherwise names beyond page one may never be found. | M | None |
| B32 | A monthly Search Console review checklist (coverage, vitals, rich results, links, manual actions). | Keeps problems from piling up. | S | Low |
| B33 | Add CAA records and enable DNSSEC for the domain. | Neither exists. | S | Low |

### C. Content and keywords

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| C1 | Page-one push for 4-character domains: guide copy, FAQs, links to the list. | Ranks 50, 71 and 77 for terms with difficulty 20 to 21. | M | None |
| C2 | Repeat for 3, 5 and 6 character, numeric and .ca lists, using SE Ranking to pick terms. | The same pattern applies to every list page. | M | None |
| C3 | Rewrite the brokerage page around "domain brokerage" (30 searches, difficulty 38) first, then "domain broker". | "Domain broker" is 1,000 searches but difficulty 78; build up to it. | M | None |
| C4 | Buy-side guide: "How to buy a domain name" with payment plans and transfer steps. | Supports every listing and the brokerage page. | M | None |
| C5 | **BUILT 3.106.0** (28-term glossary page). Glossary of 40 domain terms (EPP code, RDAP, ccTLD, backorder, registrar lock and so on). | Internal link hub and answer-engine material. | M | None |
| C6 | Honest comparison pages: Domain Brothers vs Sedo, Afternic and Atom. | High-intent searches; the policy research already gathered the facts. | M | Low |
| C7 | A page for each of the top 12 industries (food, finance, tech, health, travel, auto…). | Category pages exist; give each unique copy. | M | None |
| C8 | Unique "ideas for this domain" block on each listing (three business ideas, similar names). | Avoids thin, repeated copy across 200 pages. | M | Low |
| C9 | FAQ section on every listing page with FAQPage schema. | Targets "people also ask". | M | None |
| C10 | Auto-built monthly "domains sold" roundup from your own sold records. | Fresh content with no writing. | S | None |
| C11 | **BUILT 3.106.0** (live domain price index page). Public "domain price index": median asking price by length and extension, updated automatically. | Linkable data nobody else has for these names. | M | Low |
| C12 | Case studies from sold names (10 stories). | Social proof for buyers and brokerage clients. | M | Some |
| C13 | Canada pages: "buy a .ca domain", "Canadian domain broker". | Volumes need checking first; the audience and law are Canadian. | M | None |
| C14 | Reconcile "15+ years" and "27+ years" everywhere. | Inconsistent claims hurt trust. | S | Low |
| C15 | Generate a social image for every domain and guide (name, price, brand). | Better click-through when shared. | M | None |
| C16 | Downloadable "domain naming checklist" PDF for email sign-ups. | A lead magnet with no new tooling. | S | Low |
| C17 | Refresh the About and Our Team pages with credentials, dates and the broker names. | E-E-A-T and brand searches. | S | Low |
| C18 | A "Pricing and fees" page for brokerage, written plainly. | People search for what brokers charge; validate volume first. | M | Some |
| C19 | A "How we protect your payment" page tied to the Payment Terms. | Removes the main buying objection. | S | None |
| C20 | Publish the 14 tool pages, then submit them in Search Console. | Built; the targets are listed in `tools/seo-tools-keywords.json`. | S | Low |
| C21 | **BUILT 3.106.0** (DNS propagation checker and nslookup pages). Add tool pages for "propagation checker" (590, difficulty 49) and "nslookup specific DNS server" (590, difficulty 32 to 41). | Measured volume with realistic difficulty. | M | None |
| C22 | **BUILT 3.106.0** (certificate decoder page). Add a certificate decoder (320, difficulty 25) and an HSTS checker page (170, difficulty 30). | Low difficulty and easy to build. | M | None |
| C23 | Update the tool page copy every six months. | Keeps "last updated" honest. | S | None |

### D. Conversion

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| D1 | Auto-accept offers above the minimum price and auto-counter below it. | Replaces "one business day" with seconds; the minimum price field already exists. | M | Low |
| D2 | Show an explicit "Make an offer" state for the 7 names priced at 0. | Zero looks like an error. | S | None |
| D3 | Review the 5 trademark-risk names in the pricing review. | Legal exposure. | S | Low |
| D4 | Apply or reject the price recommendations in the pricing review. | One high-impact decision. | S | Some |
| D5 | Show "from $X a month" on every listing card. | Payment plans are the main selling point. | S | None |
| D6 | A "Recently sold" strip on the home and listing pages. | Social proof from real records. | S | None |
| D7 | Abandoned-offer reminder email after 24 hours. | Recovers started offers. | M | None |
| D8 | Weekly email of new names that match each saved search. | Saved searches exist; the digest does not. | M | None |
| D9 | Trust badges beside the buy button (secure payment, payment terms, held until final payment). | Reduces hesitation. | S | None |
| D10 | A/B test the exit offer and hero button text. | Both exist; neither is measured. | M | None |
| D11 | Newsletter sign-up with a clear promise ("10 premium names every week"). | Builds an owned audience. | S | None |
| D12 | GA4 events for offer start, offer sent, plan start, checkout and payment. | You cannot improve what you cannot see. | S | None |
| D13 | Sticky "Buy now" bar on phones for domain pages. | Keeps the action in reach. | S | None |
| D14 | Embed real Google or Trustpilot reviews when you have them. | Only genuine reviews; no invented ratings. | S | Some |
| D15 | "Similar names" under every listing. | Rescues visitors who do not like the first name. | M | None |
| D16 | Show total cost and the plan schedule before checkout. | Prevents surprises and refund requests. | S | None |

### E. Product features

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| E1 | Bulk CSV import and export of listings and prices. | Removes manual edits. | M | None |
| E2 | Price history chart on each domain page. | The table exists; a chart is clearer. | S | None |
| E3 | Public read-only JSON feed of listings for partners. | The index endpoint already exists. | S | None |
| E4 | Affiliate and referral links with a commission report. | New traffic with no ad spend. | M | Low |
| E5 | Invoice and receipt PDFs in the account area. | Business buyers need them. | M | None |
| E6 | Weekly KPI email to the owner (visits, offers, plans, revenue). | One glance at the business. | M | None |
| E7 | Slack or email alert on every new offer or enquiry. | Faster replies win sales. | S | Low |
| E8 | Hot-lead list in the CRM from views, saves and offers. | Tells you whom to contact first. | M | None |
| E9 | **BUILT 3.106.0** (site search log (Tools > Search terms)). Log site searches and show what people look for that you do not have. | Pure inventory insight. | S | None |
| E10 | Self-serve "Delete my account and data" and "Download my data". | Privacy rights in several countries. | M | None |
| E11 | A "domain watch" tool: alert when a domain's registration or expiry changes. | Built on the RDAP lookup; useful to brokerage prospects. | M | None |
| E12 | Seller marketplace. | Planned and on hold. | L | Some |
| E13 | Syndicate listings to Afternic and Sedo networks. | More buyers; needs partner accounts. | L | Some |
| E14 | Multi-currency display (CAD and USD) with a clear note. | Canadian audience. | M | Low |
| E15 | Transfer status emails at each stage. | Fewer "where is my domain" messages. | S | None |
| E16 | Stronger finder: filter by length, extension, keyword and budget together. | Fewer dead ends. | M | None |
| E17 | Compare tool opens from every card. | The tool exists; make it findable. | S | None |

### F. Performance

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| F1 | Convert uploaded images to WebP on upload. | No WebP in use on production's home page. | S | None |
| F2 | Inline critical CSS and defer the rest. | 9 to 12 stylesheets per page. | M | None |
| F3 | Drop Font Awesome for inline icons. | Fewer requests. | M | None |
| F4 | Add `preconnect` to the few third-party hosts that remain. | Saves round trips. | S | None |
| F5 | Clean autoloaded options and old transients. | Smaller database overhead. | S | None |
| F6 | Move to a hosting plan that does not return 508 under load. | The shared host overloads during testing. | M | Some |
| F7 | Monitor Core Web Vitals from real users. | Lab numbers alone mislead. | S | Low |
| F8 | Set a performance budget (JS under 300 KB per page) and check it in CI. | Prevents regressions. | M | None |

### G. Security and compliance

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| G1 | Move DMARC from `p=none` to `quarantine`, after listing every sender. | SPF lists only Google yet mail also goes through SendGrid and other services. | S | Low |
| G2 | Turn on two-factor sign-in for all admins. | Wordfence is installed; this is a setting. | S | Low |
| G3 | Content Security Policy in report-only mode, then enforce. | None today. | M | None |
| G4 | Verify offsite backups and test a restore. | Backups are only real once restored. | S | Low |
| G5 | Remove the two inactive plugins (Contact Form DB, WP Mail SMTP). | Fewer things to patch. | S | Low |
| G6 | **BUILT 3.106.0** (security.txt). Publish `security.txt`. | A place to report problems. | S | None |
| G7 | **BUILT 3.106.0** (24-month form entry purge). Purge old form entries after 24 months. | Data minimisation. | S | None |
| G8 | CASL and unsubscribe check on every marketing email. | Canadian anti-spam law. | S | None |
| G9 | Schedule a weekly Wordfence scan with email alerts. | Early warning. | S | Low |
| G10 | Confirm the consent banner blocks analytics until accepted on production. | Tested on beta only. | S | None |

### H. Accessibility and UX

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| H1 | Run axe-core on every template in the test suite. | Catches regressions automatically. | S | None |
| H2 | Skip-to-content link and landmark roles. | Keyboard and screen-reader users. | S | None |
| H3 | Keyboard operation for the mega menus. | Menus are mouse-first today. | M | None |
| H4 | Respect reduced-motion across all animations. | Comfort and compliance. | S | None |
| H5 | Check contrast of the lime on white. | Brand colour can fail contrast. | S | None |
| H6 | Test 200 percent zoom and foldable layouts on every template. | Already done for accounts; extend. | S | None |
| H7 | Announce form errors to screen readers on every form. | Several forms do; make it universal. | S | None |
| H8 | Plain-language pass on checkout and policy pages. | Fewer questions and chargebacks. | S | None |

### I. Analytics and operations

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| I1 | Confirm GA4 is collecting on production. | Production shows a tag manager but no gtag. | S | Low |
| I2 | Track 50 target keywords weekly in SE Ranking. | You see progress and lost rankings. | S | None |
| I3 | Weekly email of new and lost backlinks. | Spots spam early. | S | None |
| I4 | Uptime monitoring with alerts. | Know before customers do. | S | Low |
| I5 | **BUILT 3.106.0** (fatal error email alert). Email alert when a new fatal error appears in the DB Fatal Log. | Today you must look. | S | None |
| I6 | Nightly Playwright run against production. | Catches silent breakage. | M | Low |
| I7 | Weekly top-404 report and automatic redirect suggestions. | Recovers lost traffic. | S | None |
| I8 | A funnel report (view, save, offer, plan, paid). | Shows where buyers drop out. | M | None |

### J. Engineering

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| J1 | Split the 4,713-line `db-custom-blocks.php` into modules. | The largest file mixes many concerns; smaller files are safer to change. | M | None |
| J2 | **BUILT 3.106.0** (GitHub Actions CI). GitHub Actions: lint every PHP file, run every offline test and check JavaScript on each pull request. | Only manual checks exist today. | S | None |
| J3 | Add PHP static analysis and coding-standard checks. | Finds bugs before deploy. | M | None |
| J4 | Fixture tests for the lookup parsers (RDAP, DNS, certificate, headers). | The tool pages depend on them. | S | None |
| J5 | Run the live QA suite in CI against beta. | Needs the staging login as a secret. | M | Low |
| J6 | Automate deployment to staging and production. | Replaces manual zip uploads. | M | Some |
| J7 | Keep a changelog and tag releases. | Rollback and audit. | S | None |
| J8 | Delete `legacy/snippets/` once confirmed unused. | Dead code. | S | Low |
| J9 | A single Settings screen for consent, ads, Turnstile and the estimate token. | Today they are spread across screens. | M | None |
| J10 | Check custom tables have indexes on the columns they search. | Prevents slow queries as data grows. | S | None |
| J11 | A runbook: incidents, rollback, how to add a tool page or policy. | Reduces dependence on one person. | S | None |

### K. Email and lifecycle

| # | Recommendation | Why | Effort | Human |
|---|---|---|---|---|
| K1 | Weekly "names that match you" email from the registration answers (intent, budget, interests). | The answers are stored but unused. | M | None |
| K2 | Welcome series of three emails after registration. | First impressions and activation. | M | None |
| K3 | Post-purchase series: transfer, DNS, email setup, review request. | Reduces support load and gathers reviews. | M | None |
| K4 | Weekly newsletter built from new listings, price drops and one guide. | An owned audience. | M | None |
| K5 | "A name you saved was sold" email with alternatives. | Keeps the visitor. | S | None |
| K6 | Auto-reply to enquiries with an expected time and helpful links. | Sets expectations. | S | None |
| K7 | Re-engagement email to registered users with no activity in 60 days. | Revives dormant accounts. | S | None |

## 4. Suggested order

- **Week 1:** A1 to A13 (deploy), B1 to B3, B24 to B27, G1 to G6.
- **Weeks 2 to 3:** B4 to B12, C1 to C3, D1, D2, D5, D12, J2.
- **Month 2:** content (C4 to C13), tools (C21, C22), email (K1 to K4), D7, D8.
- **Month 3:** performance (B20 to B23, F1 to F4), engineering (J1, J3 to J6), product (E1 to E11).

Counts: A 13, B 33, C 23, D 16, E 17, F 8, G 10, H 8, I 8, J 11, K 7 = **154 recommendations**.

## 5. Status after the 2026-11-20 build

**Correction:** I first listed B4, B5, B6, B9 and B11 as new work. The plugin already produces them (product and offer markup, templated domain titles and descriptions, breadcrumb and list markup), so they are built and only absent on production because it runs the old build.

**Built and tested in 3.106.0** (15 items): B15, B17, B18, B24, B25, B27, C5, C11, C21, C22, E9, G6, G7, I5, J2. They add seven modules, three more tool pages (17 in all), a glossary, a price index and a CI workflow. Every item has an automated test, and I ran the plugin on a throwaway local WordPress to confirm the pages, the lookups and the error handling work for real.

**Built and tested in 3.107.0 to 3.110.1** (6 items), each deployed to beta and checked live:

| Item | What shipped | Version |
|---|---|---|
| B12 | "Similar names" block on domain pages (extension, length, price, category). | 3.107.0 |
| D5 | "from $X/mo" on each similar name. The All Domains and home cards already showed "or from $X/mo" before this work (checked live: 54 and 8 cards). | 3.107.0 |
| D6 | "Recently sold" strip on domain pages (names only, links to `/sold/`). Not yet on the home page. | 3.107.1 |
| D12 | Six dataLayer events (`view_item`, `begin_checkout`, `offer_click`, `search`, `generate_lead`, `tool_use`). Added `offer_sent` (offer form on `/offer/`) and `purchase_complete` (the `/payment-successful/` page, once per session, no amount because that page belongs to the theme) in 3.111.0. Plan start is `begin_checkout` with method `payment_plan`. | 3.108.0, 3.111.0 |
| E10 | Download my data and self-service account deletion. Purchase records and sent enquiries are kept. | 3.109.0 |
| E2 | Price history chart above the existing table. | 3.110.1 |
| H1 | axe-core run on 12 templates at desktop and phone width: fixed zoom blocking on virtual pages, the Sold badge contrast and keyboard access to three sideways-scrolling tables. The audit is the script `tests/qa/axe-audit.js`; it needs the live site, so it runs by hand after a deploy, not in CI. | 3.109.1, 3.110.2 |

**Not built, and why:**

| Group | Items | Reason |
|---|---|---|
| Needs deploying to production | A1 to A13 | Production is not in the connected hosting account. |
| Needs an account, key or DNS access | B22, B23, B28, B33, G1, G2, G4, G5, G9, I1 to I4, I6, J5, J6 | Cloudflare, Bing, DNS, Wordfence, backups and monitoring all sit outside the repository. |
| Needs a decision or facts from you | C3, C6, C12 to C14, C17, C18, D3, D4, D14, E12 to E14 | Prices, claims, case studies and partner accounts are yours to confirm. |
| Larger builds not started | D1, D7, D8, E1, E4 to E8, E11, E15, E16, K1 to K7, J1, J3 | Each needs its own design and live testing with real email and payments. I did not want to ship them untested. |
| Content writing | B8, B16, B29, B30, C1 to C4, C7 to C10, C15, C16, C19, C23 | Large volumes of copy that should be read by a person before publishing. |
| Not touched | F, H and the rest of B | Need measuring on the live theme first. |

**Found while verifying (3.111.0):** the home page loads Google Tag Manager container `GTM-W7B64D4` twice and the GA4 tag `G-56DWCMLT8V` twice (two `noscript` copies too). One copy comes from the theme header and one from a plugin, not from `db-custom-blocks`. That can double-count visits and events. Remove one source in WP Admin (check Site Kit and any GTM plugin) and re-check with the browser console: `dataLayer.filter(e=>e.event=='gtm.js').length` should be 1.

**Found while building:** Rank Math is set to redirect unknown URLs to the home page on beta. That behaves like a soft 404, which Google discourages. Turn it off under Rank Math > Redirections > Settings so the new 404 page can show.

**Retest result (live on beta, plugin 3.106.2):**

- **Offline:** 15 test suites pass (more than 300 checks), every PHP file lints, every script passes a syntax check.
- **Live pages:** 15 of 15 checks pass. All 17 tool pages have one title, one canonical, FAQ schema, one h1 and a working widget; the glossary, price index, `llms.txt`, `security.txt`, the tools sitemap (20 URLs) and the Rank Math sitemap entry all load; `X-Powered-By` is gone; an unknown URL returns a real 404 with the helpful page and noindex; 24 existing pages (services, policies, news, register, wishlist and others) return 200 with complete HTML, one h1 and no printed PHP errors; 82 of 86 images on five real pages now carry a loading attribute, and all 86 carry decoding.
- **Live browser, desktop and phone:** 24 of 24 checks pass. Every tool returned real data (registry, SSL, headers, DNSSEC, email security, hosting network, history, rank, extension availability, resolver comparison, nslookup on a chosen server, certificate decoding and the private-key refusal), with no sideways scroll and no JavaScript errors.
- **Server side:** search logging records a search and shows misses, both scheduled jobs (fatal alert, entry purge) are queued, the IndexNow key file is served, and the fatal log shows no errors since 2026-11-19 02:35 UTC (before the 3.104 deploy). The fatal-error email now sets a baseline on its first run instead of mailing old history; I checked that it sends nothing.

**Two fixes made during the live retest:** `/register/` had three h1 headings (one per step) and `/news/` had two (the theme prints a hidden one). Both now have one.
