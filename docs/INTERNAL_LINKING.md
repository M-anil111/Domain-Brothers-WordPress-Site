# Internal and external linking

How pages on domainbrothers.com link to each other and to other sites, why, and how to keep it
that way. Written 2026-11-25 from a crawl of beta (441 URLs), Rank Math's link counter and
Search Console data (Rank Math Analytics, 20 Aug to 22 Nov 2026), checked against Google's
[Link best practices](https://developers.google.com/search/docs/crawling-indexing/links-crawlable)
(updated 2025-12-10) and
[Qualify outbound links](https://developers.google.com/search/docs/crawling-indexing/qualify-outbound-links).

## 1. What the audit found

| Finding | Evidence | Fix |
|---|---|---|
| Production returned 401 to Google | Rank Math URL inspections: `/`, `/4-character-domains/`, `/6-character-domains/` "Blocked due to unauthorized request (401)" | Owner: production must answer 200 without a password. Nothing ranks while it does not. |
| Broker searches land on the home page | "domain broker", "domain brokerage", "domain broker service": all on `/` at positions 63 to 86. `/domain-brokerage-services/` (focus keyword "domain broker"): 2 impressions, position 76; 22 links from page text, none with the words "domain broker" | Contextual links with broker wording point to the brokerage page, including one from the home page's own text |
| Glossary orphaned | `/domain-glossary/`: no links at all; `/domain-category/latest/` the same | Learn menu, contextual links from glossary terms (ccTLD, gTLD, aftermarket, name server) |
| Money pages barely linked | Domain appraisal 2 links, Domain buy service 6, 3-letter domains 8 (4- and 5-letter: 438 each), Deals 6 | Contextual links from guides and news |
| Long, diluted anchors | The "Explore Domain Brothers" block on 166 pages wrapped title and description in one link ("Acquire a specific domainA broker approaches the owner for you...") | Link wraps the title only; the card stays clickable |
| Links to a redirect | Header "Sign in" pointed at `/sign-in/` (302) on every page: 1,315 links | Links go to the final address |
| Dead links | Theme's hidden listing table: `http://www.` and empty `?lis=y` anchors, 34 on `/all-domains/` | Removed from the output |
| Old news | 1,500 syndicated posts; 569 of 638 inspected URLs "unknown to Google" | Already handled: news older than 60 days is `noindex, follow` and out of the sitemap (`db-index-policy-block.php`) |

What was already right: all canonicals self-referencing; no "click here" anchors; outbound
links cite primary sources (ICANN, WIPO, USPTO, Wikipedia, Google developer docs); news credits
its source; the 25 guides form a cluster around `/premium-domain-names-complete-guide/`
(24 links in); every listing is two clicks from home.

## 2. The structure

Four hubs. Each owns one search intent; everything under it links up to it, and the hubs link
to each other. No indexable page is more than three clicks from home.

| Hub | Pages under it | Owns |
|---|---|---|
| Buy: `/all-domains/` | `/3-` to `/6-character-domains/`, `/numeric-domains-for-sale/`, `/premium-domain-deals/`, `/collections/`, `/domain-category/*/`, then each `/domains/<name>/` | premium domains for sale, N-letter domains |
| Broker services: `/our-services/` | `/domain-brokerage-services/` (domain broker), `/acquire-premium-domain/` (domain acquisition), `/buy-domains-service/`, `/domain-appraisal-services/`, `/domain-management-services/`, `/domain-transfer/`, social handle pages | domain broker, acquisition, appraisal |
| Sell: `/sell-your-domains/` | `/seller-agreement/`, `/sell-my-domain/` guide, appraisal | sell my domain |
| Learn: `/premium-domain-names-complete-guide/` | 25 guides in `/category/domain-guides/`, `/domain-glossary/`, `/domain-price-index/`, `/domain-tools/` and 17 tools, `/faqs/`, `/epp-guidelines/`, `/news/` | informational searches |

Which page owns which keyword is set in `tools/seo-focus.json`. One topic, one page: when two
pages could rank for the same words, links with those words go to the owner only.

## 3. Where links come from

| Layer | Source | Notes |
|---|---|---|
| Header mega menu and phone drawer | `db_header_panels()` in `db-header-block.php` | Hubs and their main pages. Learn column now has the glossary and price index |
| Breadcrumbs | `db-breadcrumbs-block.php` + Rank Math BreadcrumbList | Home > hub > page |
| Contextual links in text | `db-autolink-block.php` (new) | See section 4 |
| Explore Domain Brothers | `db-links-block.php` | Under content pages; the title is the anchor |
| Related listings | `db-recommend-block.php` | Listing to listing, by length, extension and topic |
| Guide cluster | `cluster/` articles: key takeaways, contents, related reading | Guides link to the pillar and to related guides (24 of 25 guides link to the pillar; 5 to 22 guide links in per guide) |
| Lists and archives | `/all-domains/`, category, length pages, collections | Every listing is two clicks from home |
| HTML and XML sitemaps | `/site-map/`, Rank Math sitemaps, `/db-pages-sitemap.xml`, `/domain-tools-sitemap.xml` | Every indexable URL |
| Footer | theme footer (`theme-overrides/DomainFolio/footer.php`) | Policies, social profiles |

## 4. Contextual links (`db-custom-blocks/db-autolink-block.php`)

The first natural mention of a topic in an article or content page links to the page that owns
the topic. The map is `db_autolink_map()`; examples:

| Words in the text | Links to |
|---|---|
| domain broker, domain brokerage (services) | `/domain-brokerage-services/` |
| domain acquisition, acquire a specific domain | `/acquire-premium-domain/` |
| domain appraisal | `/domain-appraisal-services/` |
| 3-letter / 4-letter / 5-letter / 6-letter domains, numeric domains | the matching list page |
| payment plan(s) | `/payment-terms/` |
| sell my/your domain | `/sell-your-domains/` (only while selling is on) |
| premium domain names | the complete guide |
| escrow, lease-to-own, domain parking, flipping, backorder, UDRP, transfer a domain, EPP code | the matching guide |
| WHOIS lookup, DNS lookup, SSL certificate, SPF/DMARC, DNSSEC | the matching free tool |
| ccTLD, gTLD, aftermarket, name server | the glossary entry |

Rules (enforced in code, tested in `tests/seo/autolink-test.php`):

- first match only, one link per target page, at most 5 per page, at most one per text run;
- never inside headings, existing links, buttons, code, captions, table headers, navigation or
  the plugin's own boxes (takeaways, call to action, related, hub, contents, FAQ);
- never to the page itself, never to a page the text already links to;
- the anchor is the visitor's wording as written; whole words only;
- a target is linked only after it is confirmed to exist (published page or post, a tool, or a
  plugin route); the check is cached for 12 hours;
- not on listings (seller text), checkout, account, legal and contact pages;
- the home page gets only the broker and acquisition links (its template prints the text with
  `get_the_content()`, so this runs on `content_pagination`).

Switch off with the option `db_autolink_off = 1`. Add or change a topic in `db_autolink_map()`,
most specific phrase first, and add a test case.

## 5. Anchor text rules (Google, 2025-12-10)

- Descriptive and short: "domain appraisal", not "click here" or a whole sentence.
- Varied and natural: the contextual linker keeps the writer's own words, so anchors to one page
  differ across pages. Do not force exact-match keywords.
- Context around the link matters; do not chain links next to each other.
- Images used as links need descriptive `alt` text (it becomes the anchor).
- Links are plain `<a href>` in the server HTML. Nothing important is linked only by script.

## 6. External links

- Link out when it helps the reader, above all to cite a source (registry, ICANN, WIPO, USPTO,
  standards, the original news story). Linking to trustworthy sites does not need `nofollow`.
- `rel="sponsored"` for any paid or affiliate link; `rel="ugc"` for links people add (seller
  listing descriptions are plain text today; if links are ever allowed there, they get `ugc`);
  `rel="nofollow"` only for sites we do not vouch for.
- External links open in a new tab with `rel="noopener"` (Rank Math setting "Open external links
  in new tab" is on; "nofollow external links" is off, which matches Google's advice).
- Listings that point to the live domain itself keep `rel="noopener"`; they are our own assets.

## 7. Keeping it right

After content or menu changes, and every month:

```bash
python3 tools/link-audit.py crawl https://beta.domainbrothers.com /tmp/link-audit.json   # ~25 min, one page at a time
python3 tools/link-audit.py report /tmp/link-audit.json
```

Check in the report: no indexable page without inlinks; nothing in the sitemap unreachable from
home; depth 3 or less for hubs, lists, services, guides and listings; no internal link to a
redirect or error; key pages' anchors match their topic.

In Search Console (or Rank Math > Analytics), watch per topic whether the owning page now gets
the impressions: "domain broker" moving from `/` to `/domain-brokerage-services/` is the first
sign the structure is read as intended. Expect weeks, not days, and only once production
answers Google with 200.

New content: every new guide links to the pillar and to the hub it serves, and gets at least
three links from older guides. A new service or list page goes into the menu panel for its hub,
the Explore block if it is a main page, and `db_autolink_map()` if it owns a topic.
