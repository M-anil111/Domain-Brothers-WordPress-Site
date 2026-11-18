# Seller marketplace plan: list-your-domain service

Status: plan only. Nothing in this document is built yet.

Goal: let any domain owner create a free account, list names for sale on Domain Brothers, receive and negotiate offers, and get paid after a managed transfer. The reference products are Sedo (the 17 screenshots and the bulk-import file supplied), Afternic and Uniregistry Market. The function set follows them; the interface does not. Sedo's seller area is dense tables, tabbed forms and pop-up wizards from the 2000s. This plan keeps every capability and rebuilds the experience as a clean, spacious, card-based interface in the style of the new site header and account pages.

---

## 1. What the references do, and what we take from each

| Capability | Sedo (screenshots) | Afternic / Uniregistry | Domain Brothers plan |
|---|---|---|---|
| Free seller account | Yes | Yes | Reuse the existing email-code account. Add a seller profile (payout details, agreements accepted). |
| Add domains | Paste box, then a price table, then "activate premium network" | Paste or upload; nameserver-based verification | 3-step flow: add names, set prices, verify and publish. |
| Bulk import | XLSX/CSV with Domain Name, Selling Option, For Sale, Price, Minimum Price, Currency, Action Type; import or sync modes | CSV | Same seven columns so a Sedo export loads unchanged. Import and sync modes. |
| Price options | Buy Now or Make Offer, minimum offer as a percentage of price (60% default), currency | Buy now, make offer, floor price, lease to own | Buy now, make offer, minimum offer (hidden floor), payment plan on or off. One currency at launch (USD), CAD next. |
| Ownership verification | Personal verification ID placed in a DNS TXT record, or a CNAME | Nameserver change | TXT record or CNAME, checked automatically; nameserver option when the seller also wants a sales page. |
| Listing management | Table with inline bulk edit, set prices, delete, sort, date range | Portfolio view with filters | Card and table views, multi-select bulk actions, filters by status, price and extension. |
| Offers | "Offers received" list, bid thread per domain, cancelled after inactivity | Negotiation inbox, counter-offers | Reuse the existing offers engine (states: waiting, countered, agreed, declined, paid, expired). Add the seller as a party. |
| For-sale landing page | Parking, Selling or Web Archive layouts per domain | "For sale" landers with buy box | One modern sales page per domain, served when the seller points the domain at us. No ad parking at launch. |
| Statistics | Uniques, clicks, earnings, CTR, referrers | Views, leads | Views, unique visitors, wishlist saves, offers and conversion per listing. |
| Per-listing settings | Offer description, show stats, link to seller portfolio, category, keywords | Category, description | Description, category, keywords, show or hide stats, link to the seller's other names. |
| Payment and transfer | Sedo transfer service: buyer pays, then the domain moves, then the seller is paid | Fast transfer through partner registrars | Buyer pays through Stripe; transfer stages already tracked on site; seller payout after the buyer confirms control or after a fixed window. |
| Agreements | Marketplace, transfer and user agreements, data-processing agreement | Membership agreement | Seller agreement and listing rules accepted once, recorded with date and version. |
| Distribution network | SedoMLS partner registrars | Afternic DLS (100+ registrars) | Out of scope. Possible later through a partner API. |
| Auctions, parking revenue | Yes | Limited | Out of scope for the first releases. |

---

## 2. What already exists on the site and is reused

The plugin already contains most of the buyer side. The marketplace adds the seller side on top of it.

- Accounts with email-code sign-in, wishlist, alerts and an account page with tabs.
- Offers and negotiation: threads, counter-offers, expiry, email notifications, admin screens.
- Stripe payments, payment plans over 3 to 12 months, receipts, orders.
- Transfer tracking in the account, encrypted storage of authorization (EPP) codes, private code links.
- Listing states (for sale, sold, not for sale, under review), price history, registry (RDAP) record checks.
- Listing pages, list pages by length and type, the domain finder, collections.
- Spam protection (Turnstile, honeypots), page cache, admin CRM for leads.

---

## 3. Seller journey

1. **Create a free account.** Same sign-up page. A "Sell domains" entry in the header menu and account page leads to a short seller setup: legal name or company, country, payout method, acceptance of the seller agreement.
2. **Add domains.** Paste names (comma, space or line separated) or upload a spreadsheet. Each name is checked immediately: valid syntax, not already listed, registered (RDAP), not on a blocked list.
3. **Set prices.** One row per name: price, buy now or make offer, minimum offer, payment plan allowed. A "set for all" bar applies a choice to every row. Price guidance shows comparable sold names from our own records where we have them.
4. **Verify ownership.** The seller's personal verification ID goes into a TXT record (or a CNAME). We check DNS automatically every few minutes for 72 hours and on demand. Names verified by nameserver change are verified and get a sales page at the same time.
5. **Review and publish.** New sellers' first listings go through a short manual review (trademark and abuse check). Trusted sellers publish instantly. Names our brokers consider exceptional receive a "Top domain" label.
6. **Manage.** A portfolio screen with search, filters, bulk price changes, pause and remove. Each listing has its own page: statistics, description, category, sales page settings.
7. **Negotiate.** Offers arrive by email and in the account. The seller accepts, counters or declines. Offers below the minimum are answered automatically with the minimum. Threads close after a set period without a reply.
8. **Sale and transfer.** The buyer pays Domain Brothers. The seller is told to release the name: provide the authorization code or push the name to the buyer's account. Each stage is shown to both sides.
9. **Payout.** After the buyer confirms control of the name (or a fixed number of days passes without a dispute), the sale price minus commission is paid to the seller. A statement is kept in the account.

---

## 4. Screens (modern, card-based)

Design language: the white header with mega menus, large type, generous spacing, rounded cards, one accent colour, clear single primary action per screen, no pop-up wizards. Each multi-step task is a full page with a progress indicator at the top and inline help beside the field it explains.

| Screen | Replaces in Sedo | Notes |
|---|---|---|
| Sell landing page | "Sell domains" marketing pages | Explains the six-point checklist the owner supplied, commission, and a "List a domain" button. |
| Seller setup | Account settings | Three short cards: identity, payout, agreements. |
| Add domains: step 1 | Paste box and bulk uploader | Two cards side by side: paste names, or drop a file. Template download. Live validation list under the box. |
| Add domains: step 2 | Price table with wizard pop-ups | Editable rows with a sticky "apply to all" bar. Guidance appears inline, not in pop-ups. |
| Add domains: step 3 | Ownership verification tab | Verification ID with a copy button, registrar-specific instructions, a live status chip per name. |
| Portfolio | Domain management table | Cards on mobile, table on desktop; status chips; bulk action bar appears when rows are selected. |
| Listing detail | Traffic, layout, keywords, domain info, offer page tabs | One page with sections: performance chart, price and options, description and category, sales page, history. |
| Offers inbox | Offers received and bid thread | Conversation view per offer with accept, counter and decline; quick filters by state. |
| Sales and payouts | Not shown in the screenshots | Each sale with transfer stage, commission, payout status and a downloadable statement. |
| Admin: review queue | Internal | Approve, reject with reason, mark "Top domain", suspend seller. |

Every screen is responsive down to 344px (folded phones) and meets the tap-target and contrast rules already enforced on the site.

---

## 5. Bulk import format

Kept identical to the supplied example so existing portfolios load without editing.

| Column | Values | Required |
|---|---|---|
| Domain Name | example.com | Yes |
| Selling Option | `BUY_NOW` or `MAKE_OFFER` | Yes |
| For Sale | `yes` or `no` (blank means yes) | No |
| Price | Number, no symbols | Required for `BUY_NOW` |
| Minimum Price | Number, below Price | No |
| Currency | `USD` (and `CAD` when enabled); `EUR` and `GBP` rows are flagged for conversion | No |
| Action Type | blank to add or update, `DELETE` to remove | No |

Modes: **Import** adds and updates the listed names. **Sync** also removes names that are missing from the file, after a confirmation screen that lists what will be removed. Files: XLSX or CSV, 25 MB maximum. Large files are processed in the background with a progress view and a row-by-row result report.

---

## 6. Technical outline

**Data**
- Seller profile table: account email, legal name, country, payout reference, agreement version and date, trust level, verification ID.
- Listings stay in the existing `domain` post type. New fields: seller id, selling option, minimum offer, verification status and method, review status, source (owner, seller, import batch).
- Verification log: name, method, last check, result.
- Payout ledger: sale, gross, commission, net, state, paid date, reference.
- Import batches: file, mode, counts, per-row results.

**Logic**
- DNS verification worker on the existing hourly scheduler plus an on-demand check.
- Offers engine gains a seller party and permission checks, so a seller sees only their own threads. Auto-reply below minimum; inactivity expiry.
- Sale flow: buyer payment (existing Stripe checkout) creates a transfer record and a pending payout. Payout releases on buyer confirmation or timeout, held on dispute.
- Sales page for pointed domains: a lightweight page served by host name, with the name, price, buy and offer actions.
- Notifications by email for each state change, with the existing unsubscribe controls.

**Security and compliance**
- Sellers can only read and change their own listings, offers and payouts; every action is permission checked on the server.
- Rate limits on adding names and on verification checks. Spam protection on all forms.
- Payout details are held by the payment provider, not stored in WordPress.
- Audit log of price changes, publication, offers and payouts.
- Seller agreement, listing rules, prohibited names policy and privacy terms must be written and approved before launch. The site makes no escrow claim today; payment protection wording must match what the payment flow really does.

---

## 7. Delivery phases

| Phase | Contents | Outcome |
|---|---|---|
| 1. Seller basics | Seller setup, add domains (paste), pricing step, DNS verification, review queue, listings appear in the marketplace with a seller id | Owners can list verified names; offers go through Domain Brothers brokers as today. |
| 2. Portfolio and bulk | Portfolio screen, bulk actions, XLSX/CSV import and sync, listing detail with statistics and description | Sellers with large portfolios can manage them alone. |
| 3. Seller negotiations | Seller access to offer threads, counter and accept, minimum-offer auto reply, expiry | Negotiation without a broker in the middle, with admin oversight. |
| 4. Payments and payouts | Commission rules, payout ledger, payout provider integration, statements, dispute hold | End-to-end sale with seller payout. |
| 5. Sales pages | Sales page for pointed domains, nameserver verification, visitor statistics | Type-in traffic turns into offers. |
| Later | Second currency, lease-to-own for seller names, partner distribution network, auctions, API | Parity with the large marketplaces where it pays off. |

Each phase ships behind a switch, with offline tests for the rules (pricing, verification, permissions, payouts) and end-to-end tests for the flows, as the rest of the plugin does.

---

## 8. Decisions needed from the owner before building

1. **Commission.** Percentage and minimum fee per sale (Sedo and Afternic charge roughly 15% to 25% depending on channel).
2. **Who negotiates.** Sellers directly with buyers (phase 3), or Domain Brothers brokers on every deal.
3. **Payouts.** Provider and method (Stripe Connect is the natural fit with the existing Stripe account), payout delay, supported seller countries.
4. **Payment protection wording.** Whether a licensed escrow partner is added. Until then the site must not describe the flow as escrow.
5. **Review policy.** Manual review for every listing, for new sellers only, or none.
6. **Currencies.** USD only at launch, or USD and CAD.
7. **Legal documents.** Seller agreement, listing rules and data terms need to be drafted or supplied.
8. **Exclusivity.** Whether sellers may list the same name elsewhere at a different price.
