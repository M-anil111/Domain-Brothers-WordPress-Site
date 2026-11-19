# DB All Domains Redesign

Front-end-only redesign of `/all-domains/` (page slug `all-domains`, ID 22 on beta), packaged as a standalone WordPress plugin. Deactivating the plugin restores the current design.

## Install

1. WP Admin → Plugins → Add New → Upload Plugin → `db-all-domains-redesign.zip` → Activate.
2. Purge the Cloudflare cache and any page cache for `/all-domains/`.

Assets load only on the All Domains page. To target another page, filter `dbr_all_domains_is_target`.

## Files

| File | Purpose |
|---|---|
| `db-all-domains-redesign.php` | Enqueues CSS/JS on the All Domains page only; preloads the banner image. |
| `assets/css/all-domains.css` | All visual changes: banner, filter panel, results bar, Grid View, List View, responsive rules. |
| `assets/js/all-domains.js` | Adds the results heading and the List View column header (Domain name / Price (USD) / Purchase options). |
| `assets/css/listing-pages.css` | Shared fix on all five listing pages (`all-domains`, `4-`, `5-`, `6-character-domains`, `sold`): the `.dbx` content block widens to 1140px and its paragraphs use the full width. Also sets the content area to the header's width on these pages: `#primary.content-area` max-width 1360px with the header's `clamp(20px, 3vw, 40px)` side padding (the extra side padding on `#content` and `#main` is removed so the edges line up with the header). Slugs can be changed with the `dbr_listing_page_slugs` filter. |
| `assets/css/length-pages.css` | Shared "browse by length" redesign for `/4-character-domains/`, `/5-character-domains/`, `/6-character-domains/` and `/sold/`: banner (lime eyebrow and active chip, illustration on the right), toolbar heading, centred cards with a lime header band and full-width "Buy now →" button. CSS only; search, sort, the no-match message and card links are untouched. Pages are set with the `dbr_length_pages` filter. |
| `assets/css/four-character.css` | 4-character page values: ABCD `.ca` image, "Available 4-character domains", count shown as a pill. |
| `assets/css/five-character.css` | 5-character page values: ABCDE `.com` image, "Available 5-character domains", count as lime text, heading on its own row with a wide search (icon on the left) and sort. |
| `assets/img/hero-4-character.webp` | ABCD `.ca` illustration for the 4-character banner (760×383, transparent). |
| `assets/css/six-character.css` | 6-character page values: ABCDEF `.com` image, "Available 6-character domains", count as bold white text, search on the left with sort pushed right. Offer-only names ("Open to offers" / "Make an offer") use the shared offer styling in length-pages.css. |
| `assets/css/sold.css` | Sold page values: `.com SOLD` image, "Track record" eyebrow with icon, "78 names sold." outlined pill, "Domains with a new home." heading, search left with the live count right, grey-band cards with a red SOLD pill (4 / 3 / 2 columns). No extension filter or pagination is added because the page has none. |
| `assets/img/hero-sold.webp` | `.com SOLD` illustration (background removed, 720×598). |
| `assets/img/hero-6-character.webp` | ABCDEF `.com` illustration for the 6-character banner (760×391, transparent). |
| `assets/img/hero-5-character.webp` | ABCDE `.com` illustration for the 5-character banner (760×377, transparent). |
| `assets/css/find-a-domain.css` | `/find-a-domain/` only: content area 1360px aligned with the header, hero with finder image, 01/02/03 steps strip, "Let's find your fit." quiz (lime number badges, navy chip for the default answer, lime chip for any other choice), shortlist cards, broker section (intro column + form card) and the lime "Want to explore on your own?" bar. |
| `assets/js/find-a-domain.js` | Adds the decorative parts of that design (steps strip, quiz intro, "Reset answers" link to the page without answers, broker intro with perks and headset, "Browse all domains" link). Form fields, hidden inputs, nonce and submit buttons are untouched. |
| `assets/img/find-finder.webp`, `assets/img/find-headset.webp` | Find a Domain illustrations (transparent). |
| `assets/img/hero-domain.webp` | 3D `.com` banner illustration with a transparent background (720×480, 55 KB). |

## What is not touched

- `#db-dfilter-js`, the `/wp-json/db/v1/domain-index` request, the filter fields, sorting, reset and the `aria-live` count.
- `#db-view-toggle` (`html.db-view-grid` / `html.db-view-list` and `localStorage.db_view`).
- Card markup rendered by the server and by the filter script. The script only *adds* elements next to the existing ones.
- Buy Now links, the "Make an Offer" button on offer-only names, and the offer modal on the domain page.

## Implementation notes

- **The form keeps its box-free layout (`display: contents`).** Its fields are placed with CSS Grid, so the Sort field appears in the results bar without leaving the `<form>`.
- **Added elements sit before `.db-viewbar`, never between the form and the grid.** The existing filter script finds the grid relative to the form. When an element was inserted between them, filtering stopped re-rendering, which was found and fixed during testing.
- **Every declaration uses `!important`, and selectors are prefixed with `html body.dbp-all.dbp-all`.** The theme's db-ui and combo layers already use `!important`, with selectors as specific as `html body.db-ui-active.db-ui-active a.db-act-buy.db-act-buy`.
- **No extra "Make an offer" link on priced names** (removed on request). Priced names show Buy Now only; offer-only names keep their original "Make an Offer" button.
- **Hidden labels:** no "USD" prefix on prices and no extension labels on any card (Grid and List View, all breakpoints). List View has no Extension column. Domain names and prices are unchanged.
- **Breakpoints:** ≥1200 desktop, 992–1199 laptop, 768–991 tablet, 576–767 mobile, <576 small mobile, <380 narrow.

## Verified on beta (styles injected into the live page; nothing was saved to the site)

- Filters (extension, max price, "Make an offer only"), sort (price high→low), search and Clear filters. Cards re-render and are decorated again with no duplicates.
- Switching between Grid and List works, and the choice is kept after a page reload.
- No horizontal overflow at 390, 820, 1024 or 1366 px.
- No console errors.

## Intentionally left out (not part of existing functionality)

- **Wishlist hearts:** the listing has no wishlist endpoint. Add to wishlist exists only on the domain detail page.
- **Numbered pagination / per-page selector:** the page has no pagination today.
- **Bottom feature strip and "Talk to Our Team" banner:** these sections do not exist on the current page.
