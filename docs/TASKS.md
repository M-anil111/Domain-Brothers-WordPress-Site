# Domain Brothers: complete task list

Status on 2026-11-22, plugin 3.127.2 on beta (`https://beta.domainbrothers.com`).
`[x]` done and checked on beta · `[~]` built, final check outstanding · `[ ]` not started.
Production (`www.domainbrothers.com`) has none of this until it is deployed there (it returned HTTP 500 at last check and is not in the connected hosting account).

Totals: see the summary table at the end.

---

## A. Sell with us (seller marketplace)

### A1. Phase 1: seller basics (13 features)

**1. Public landing page `/sell-your-domains/`**
- [x] Page served at `/sell-your-domains/` with breadcrumb and title
- [x] Hero: headline, one-line promise, "Start listing" button
- [x] How it works: 4 steps (set up, add names, prove ownership, go live)
- [x] Fees block: 15% commission, $100 minimum fee, worked example
- [x] What we do for you: listing page, broker handling, payment plans, escrow-style transfer
- [x] FAQs (fees, ownership proof, exclusivity, payouts, removing a name)
- [x] Link to the seller agreement
- [x] "Sell your domains" entry in the header Services menu (only while the feature is on)
- [x] Phone layout, no sideways scroll; axe audit clean
- [x] Page indexable on production, noindex on staging

**2. Seller agreement `/seller-agreement/`**
- [x] Full agreement text: parties, listing rights, exclusivity, commission, payouts, transfer, warranties, removal, termination, law (final, no lawyer review per owner)
- [x] Version stamp `2026-11-22` shown on the page
- [x] Agreement version and date stored on each seller at acceptance
- [x] Printable layout

**3. Sign-in**
- [x] Uses the existing email-code account (no new password)
- [x] Signed-out visitors to `/seller/` are sent to sign-in and returned afterwards

**4. Seller setup**
- [x] Fields: legal name, company (optional), country, phone
- [x] Country limited to United States and Canada
- [x] Agreement checkbox required
- [x] Validation messages for each field
- [x] Seller row created with status `active`, trust `new`

**5. Add names (paste)**
- [x] Paste up to 50 names, one per line or comma separated
- [x] Clean-up: strips `http://`, `www.`, paths, spaces, capitals
- [x] Validation: name format, supported TLD, length
- [x] Duplicates in the paste removed and reported
- [x] Names already listed on the site refused
- [x] Names already submitted by another seller refused
- [x] Summary message: added, skipped and why

**6. Prices**
- [x] Option per name: Buy Now or Make Offer
- [x] Buy Now minimum $100, maximum $10,000,000
- [x] Private minimum offer (never shown publicly)
- [x] Validation messages (too low, not a number, minimum above price)
- [x] Offer-only listings store price `0` (prevents the theme footer fatal error)

**7. Ownership check (DNS)**
- [x] TXT record method with a per-name value
- [x] CNAME method pointing to `verify.domainbrothers.com`
- [x] Copy-ready instructions for each method
- [x] "Check now" button
- [x] Hourly automatic check (`db_seller_verify_cron`)
- [x] Marked failed after 72 hours without a match
- [x] Verified date stored

**8. Submit for review**
- [x] Submit button enabled only when price and ownership are complete
- [x] New sellers go to review; trusted sellers publish instantly

**9. Admin review queue (Tools > Seller listings)**
- [x] List of pending names with seller, price, check status
- [x] Approve
- [x] Reject with a reason (reason emailed)
- [x] Mark seller trusted
- [x] Pause (suspend) seller

**10. Publishing**
- [x] Approved names become normal domain listings
- [x] Buy Now names get price and checkout; offer names open to offers
- [x] Minimum offer kept private on the listing
- [x] Listing linked back to the seller row (`post_id`)

**11. Remove a name**
- [x] Remove link with confirmation
- [x] Live names taken off the site (404 afterwards)

**12. Emails**
- [x] Seller: names submitted
- [x] Seller: name is live
- [x] Seller: name not accepted (with reason)
- [x] Team: new names waiting for review

**13. On/off switch**
- [x] Option `db_seller_on` (`1` on, `0` off)
- [x] Staging on when the option is unset; production off until set

### A2. Phase 2: portfolio and bulk

**Import and sync**
- [x] CSV import in the Sedo column layout
- [x] XLSX import (read with ZipArchive, no extra library)
- [x] Template CSV download (`/seller/?template=1`)
- [x] Import mode (add new names only)
- [x] Sync mode (update prices of names already in the account)
- [x] Preview with counts: new, updated, skipped, errors
- [x] Confirm and cancel steps
- [x] Row limit 1,000 per file

**Portfolio screen**
- [x] Filters: all, live, in review, needs action
- [x] Bulk actions: change price by a percentage
- [x] Bulk actions: switch to Make Offer
- [x] Bulk actions: remove
- [x] Price edits on live names update the public page at once
- [x] Domain index refreshed after a price change (search and filters see it)
- [x] Wishlist saves per live name
- [x] Offers per live name
- [x] Page views per live name (bots and headless browsers not counted)

**Listing detail**
- [x] Description per name, shown on the listing as "From the owner"
- [x] Keywords per name, up to 8, markup stripped
- [x] Category per name: up to 3 of the site's 17 domain categories, applied to the live listing

**Checks**
- [x] Blocked-names check: abusive terms refused, famous brands sent to broker review
- [x] RDAP lookup: unregistered names refused (.ca and most registries; .com/.net rely on the DNS check, Verisign drops not-found replies from this host)

**Pricing help**
- [x] Price guide from Domain Brothers' own sold list (18 sold names, e.g. .ca median $1,600)

**Exclusive listings**
- [x] Exclusive checkbox per name
- [x] "Exclusive to Domain Brothers" badge on the listing
- [x] 10% commission on exclusive names in the fee calculation

**Phase 2 finish**
- [x] Browser test: details editor, badge, owner description, views, categories, price guide, blocked names
- [x] Fixed: dashboard crash on names with view data; empty price guide
- [x] Commit and push phase 2 extras

### A3. Phase 3: seller negotiations (3.126, `db-seller-offers-block.php`)
- [x] Offers on a seller's live names listed in the seller dashboard ("Offers on your names", waiting offers first, count of offers needing an answer)
- [x] Offer thread per name: every offer, counter, acceptance and note with date
- [x] Buyer's email never shown to the seller (first name only)
- [x] Seller action: accept (opens payment for the buyer)
- [x] Seller action: counter with a price (minimum $100) and an optional note
- [x] Seller action: decline with confirmation
- [x] Broker oversight: broker emailed on every seller move; can act on any offer in WP Admin > Offers
- [x] Offers below the private minimum answered automatically with the minimum (seller told)
- [x] Unanswered offers: reminder to seller and broker after 3 days, closed after 14 days (buyer and seller told)
- [x] Email to seller: new offer, auto-answered offer, buyer accepted, buyer declined, offer closed
- [x] Buyer emails reuse the offer engine (countered, accepted with pay link, declined)
- [x] Accepted offer opens checkout at the agreed price (checked: $2,600 on `/buy-now/?offer=...`)
- [x] Tests: 7 offline rule tests; browser flow (counter, refused low counter, accept, history, axe) and sweep on beta
- [x] Fixed during testing: Decline button invisible (theme forced white text)

### A4. Phase 4: sales and payouts
- [ ] Sale of a seller's name creates a payout record (gross, commission, net)
- [ ] Payout states: waiting for transfer, waiting for buyer confirmation, due, paid, held
- [ ] Payout date rule: 7 days after buyer confirms control, or 14 days after transfer with no dispute
- [ ] Payment-plan sales: payout follows the plan (paid as instalments clear, or at the end)
- [ ] Seller payout details through Stripe Connect onboarding (owner must enable Connect in Stripe)
- [ ] Admin payouts screen: mark transferred, confirm, hold, pay
- [ ] Seller "Sales and payouts" page
- [ ] Downloadable statement (CSV)
- [ ] Audit log: price changes, publication, offers, payouts
- [ ] Emails: sale made, transfer needed, payout sent
- [ ] Tests: offline payout maths and state changes

### A5. Phase 5: sales pages for pointed domains
- [ ] Instructions to point a name (nameservers or A record) at Domain Brothers
- [ ] Sales page served when a pointed name is visited (name, price, Buy and Offer)
- [ ] Visits from pointed names counted as views
- [ ] Hosting side: wildcard or per-name site mapping on the server (needs hosting change)

---

## B. Account area (`/my-wishlist/`)

**Header account menu (3.124)**
- [x] Signed in: one "My account" button with initial, first name and chevron
- [x] Dropdown: name and email, "My account overview", Wishlist, Alerts (new count), My offers, Transfers, Discover, Profile, Seller dashboard (when on)
- [x] Sign out inside the dropdown; no separate Sign out button
- [x] Sign out works without scripts (signed link)
- [x] Keyboard: arrows move, Escape closes; closes on outside click
- [x] Mobile drawer: "My account" group with the same sections and Sign out
- [x] Cached signed-out pages swap in the account menu for signed-in visitors
- [x] Signed-in pages never stored in a shared cache (LiteSpeed no-cache cookie `db_wl`)
- [ ] Final browser re-test after the cache clear (dropdown on a cached page, sign out)

**Payment plans tab (3.127)**
- [x] Payment-plan checkout needs a free account: signed-out visitors go to sign-in (same code creates the account) with a note, then straight back to checkout
- [x] Pay in full stays open to guests
- [x] Account email stored on the payment and the plan, so the plan shows even if another receipt email is typed
- [x] "Payment plans" tab (only when the account has a plan) and link in the header account menu
- [x] Per plan: next payment amount and date, paid so far (n of N), remaining, progress bar, status (on track, payment declined, paid off)
- [x] Full schedule table (paid, next, scheduled, declined and retrying), next row uses the real charge date
- [x] Update payment card button; declined-payment warning with the card link
- [x] Phone and desktop layout, axe clean
- [ ] Real end-to-end check with a Stripe sandbox plan purchase (needs a person past Turnstile)

**Profile tab (3.123.1)**
- [x] Your details card: avatar initial, full name, phone, read-only sign-in email with note
- [x] Newsletter as a switch with description
- [x] What you are looking for: questions as pill groups, industry text field
- [x] Sticky Save bar with status message
- [x] Your data: member since, policy version (no blank version), privacy link, Download my data
- [x] Delete account card in red with confirm-by-email field and light red button
- [x] Two columns on desktop, one on phone; axe clean on both

**My offers tab**
- [x] Domain field suggests only listed names as you type
- [x] Amount and Send stay disabled until a listed name is chosen
- [x] Unlisted name blocked in the browser and on the server

**Other tabs**
- [x] Wishlist, Alerts, Transfers, Discover tabs in the designer style
- [x] Quick setup card (4 steps)

---

## C. Header search (3.124)
- [x] Search pill with icon, dark "Search" button, `/` keyboard shortcut
- [x] Panel opens on focus: Popular this week (most viewed available names), Just listed, Browse by category (with counts), See all N domains
- [x] Real-time results while typing: up to 8, matched text highlighted, price or "Make an offer", total match count
- [x] Matches name, keyword part and category
- [x] No match: helpful message, link to all domains and the domain finder, popular names
- [x] Keyboard: arrows, Enter opens the highlighted name, Escape closes
- [x] Hints endpoint `/wp-json/db/v1/search-hints`, cached 1 hour, cleared on price or status change
- [x] axe clean
- [ ] Phone: same panel inside the mobile drawer search

---

## D. Pages and content

**Domain listings**
- [x] All domains `/all-domains/` with filters and finder
- [x] Domain page: option cards, green ticks, Back/Share, real proof lines
- [x] Character, numeric, deals, collections, priced lists
- [x] Sold pages: "This domain found a new owner", "Do you own this domain now?" with six services (web development, marketing, apps, software, resell, management), testimonials as cards, news cards
- [x] Prices +35% on 89 beta listings under $10,000 (backup kept)
- [ ] Apply the 35% increase on production (needs production access)
- [ ] Paginate or lazy-render `/all-domains/` (288 KB page, P-2)

**Buying**
- [x] Make an offer page `/make-an-offer/` (no popup)
- [x] Checkout in the new design (billing, confirmation, payment)
- [x] Payment plan chooser with Back/Share and real proof
- [x] Plan rates 0/5/8/10% for 3/6/9/12 months; stored plans keep their rate
- [x] Automatic instalment charging verified in Stripe sandbox

**Service and content pages**
- [x] Service pages, social handle pages, transfer (EPP) page
- [x] 17 domain tool pages under `/domain-tools/`
- [x] Blog post layout, news page, glossary, price index
- [x] Guide sections moved below each page's main task; designer system applied to all templates

**Designer fixes**
- [x] Designer UI fixes bundled (`db-ui-fixes-block.php`); standalone add-on still active on beta
- [ ] Deactivate the standalone "Domain Brothers UI Fixes" add-on so the bundled copy takes over

---

## E. Bug register (`docs/BUG_REGISTER.md`, 41 defects)
- [ ] Batch 1: CO-1, CO-2, CO-4, RS-1, RS-2, RS-9, A-1, O-1
- [ ] Batch 2: RS-3 to RS-8, RS-10 to RS-12, A-2, A-5, A-6, CO-3
- [ ] Batch 3: A-3, A-4, P-1 to P-6
- [ ] Batch 4: S-1 to S-6, O-2 to O-5
- [ ] O-1 needs an owner decision: which GA4/GTM source stays

## F. Operations
- [x] Deploy script, lint, offline tests in CI
- [x] Full QA run 397/397
- [ ] Clear Hostinger's server cache after each deploy (it serves old pages otherwise; done by hand on 2026-11-22)
- [ ] Production outage: connect the hosting account that holds `www.domainbrothers.com`, or rename `.htaccess` and re-save Permalinks
- [ ] Deploy the plugin to production once it is back
- [ ] Real-person test of sign-in and offer submit (Turnstile blocks scripts)

---

## Summary

| Area | Done | Built, check outstanding | Not started |
|---|---|---|---|
| A1 Phase 1 (13 features, 66 sub-tasks) | 66 | 0 | 0 |
| A2 Phase 2 | 33 | 0 | 0 |
| A3 Phase 3 | 14 | 0 | 0 |
| A4 Phase 4 | 0 | 0 | 11 |
| A5 Phase 5 | 0 | 0 | 4 |
| B Account | 22 | 0 | 1 |
| C Search | 8 | 0 | 1 |
| D Pages | 15 | 0 | 3 |
| E Bugs | 0 | 0 | 5 |
| F Operations | 2 | 0 | 4 |
