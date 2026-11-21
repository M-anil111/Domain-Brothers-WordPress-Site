# Domain Brothers UI Fixes 1.1.0

A small add-on plugin for beta.domainbrothers.com. It fixes the design and responsive items from the beta audit. It does not edit Domain Brothers Custom Blocks 3.118.8 or the DomainFolio theme. To roll back, deactivate it.

| Audit ID | What changes | Where |
| --- | --- | --- |
| DB-11 | The header logo shows below 992px on every template. It uses the same size and inset as the single-domain header. The duplicate hero logo on the home page is hidden at those widths. | `assets/ui-fixes.css` |
| DB-12 | On `/domains/` and its paginated pages, the light pill inside Buy Now is removed. Buy Now and Make an Offer share a 150px width. The archive title `<h2 class="page-title">` becomes an H1 and keeps its existing styling. | `assets/ui-fixes.css`, `includes/html-filters.php` |
| DB-06 | On `/all-domains/`, the hero's "206 names available" counts sold and unpriced posts. It now shows the real available count (128). The results heading reads "Showing X–Y of N" across pages 1–3. | `includes/html-filters.php`, `assets/listing-state.js` |
| DB-14 | The count stays visible while filters are active, and the empty state is clear. Filters are written to the URL (`q`, `tld`, `len`, `max`, `sort`). They are restored on reload, on Back and from shared links. | `assets/listing-state.js` |
| DB-28 | Both header domain-search inputs get `aria-label="Search domains for sale"`. Footer link targets grow from 23px to 33px tall with the same vertical rhythm. The header logo gets a focus ring. | `includes/html-filters.php`, `assets/ui-fixes.css` |
| DB-29 | The checkout order summary no longer reads "$ $2,500.00". Only the "$" glyph inside the decorative seal icon is replaced with a check mark. The amount and its server-side formatting are unchanged. | `includes/html-filters.php` |

## Install on beta

Before you start, take a Hostinger backup of beta's files and database, and confirm it can be restored.

1. Go to WP Admin → Plugins → Add Plugin → Upload Plugin, choose `db-ui-fixes-1.0.0.zip`, then Install and Activate.
2. Go to LiteSpeed Cache → Toolbox → Purge All. Also purge Hostinger CDN if Development Mode is off.
3. Check the result in a private window (logged out), using the steps below.

## Verify on beta

- At 390px, open `/`, `/all-domains/`, `/domains/` and `/contact-us/`. The logo should sit at the left of the 72px bar, next to the hamburger.
- On `/domains/` at 1440px, Buy Now should be a single dark pill. The page should have exactly one H1 ("Names — 206 Domains").
- On `/all-domains/`:
  - The hero should read "128 names available." and the heading "Showing 1–55 of 128". Page 2 should show 56–122 and page 3 should show 123–128.
  - Set Max length to 4. The URL should become `?len=4`. Open a domain, then press Back; the 4 filtered results should come back.
- On `/buy-now/?d=bW9iaXouY2E%3D&p=MjUwMA%3D%3D`, the order summary should read "$2,500.00".

## Roll back

Deactivate "Domain Brothers UI Fixes". Deactivation clears the Custom Blocks page cache itself. Then purge LiteSpeed by URL (Toolbox → Purge By → URL), because "Purge All" does not clear this host's LiteSpeed entries. The plugin stores one option, `dbuif_purged_ver`, which deactivation deletes, and creates no database tables.

## Notes

- `listing-state.js` needs the server HTML of each `/all-domains/page/N/` page to work out the total and offset, because the server pages also contain sold and unpriced rows. On a visitor's first visit it fetches up to 2 cached pages, and caches the counts in sessionStorage for 30 minutes. A server-side count in Custom Blocks would remove these requests.
- `fatality.ca` is listed for sale and shows as a card, but `/wp-json/db/v1/domain-index` leaves it out. The filters therefore report "of 127" while the listing shows 128. Check whether the index excludes it on purpose.
- `tests/run-filters.php` runs the same HTML filters from the command line. It is not included in the ZIP.

## 1.1.0 – shared layout

- `assets/layout.css` (generated from the section inventory in `build_layout.py`, which is kept with the source bundle and not shipped in the plugin): one 1320px container with 15px gutters, section padding 80/60/45px (desktop ≥992 / tablet 768–991 / mobile ≤767), a single shared gap between neighbouring white sections, and a single mobile gutter (`.dbx-wrap` and tool columns no longer add their own).
- `assets/ui-fixes.css`: the tap focus ring is removed (`a:focus:not(:focus-visible)`) while keyboard `:focus-visible` stays; breadcrumbs keep "Home", the separator and the current page on one line; domain-name titles are set to font-weight 700.
