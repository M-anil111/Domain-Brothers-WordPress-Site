# Domain Brothers Beta — Master Handoff

**Purpose:** Single source of truth so another agent (Codex / Claude Code / Cowork on a different machine) can continue this project with full context. Read this first, then `CHAT_EXPORT.md` for the full conversation history (not yet committed — see note below).

> **Repo note (added this session):** This GitHub repository previously contained only `README.md`. The `db_*_block.php` source files, `CHAT_EXPORT.md`, and the various report `.md` files referenced below were never committed here — they lived only in the previous Cowork session and on the live site. This session re-commits this `HANDOFF.md` plus freshly reconstructed code blocks for the two pending *code* items (#52 RSS news, #39 offer flow). See `NEXT_STEPS.md`.

Owner: Jay Mehta (jay@jaymehta.co).

---

## 1. What this project is

Domain Brothers is a WordPress domain marketplace. We are working on the staging site https://beta.domainbrothers.com (production is https://www.domainbrothers.com). The goal: make the beta fully functional (front + back end), build a custom on-site Stripe checkout + payment plans, standardize branded emails, fix migration bugs, add SEO service pages, and harden the site — then it can be promoted to production.

### Environment & access

- **WP admin:** https://beta.domainbrothers.com/wp-admin (rotate credentials — see Security).
- **Host:** Hostinger shared "Agency Startup" plan (hosts ~15 sites incl. jaymehta.co, mindshare.consulting). hPanel managed under the tech@netclues.com account (impersonate mode). Site path on server: `/home/.../websites/boqfWdtEc/public_html`.
- **Theme:** DomainFolio (commercial theme, active, no child theme). All custom code lives in `wp-content/themes/DomainFolio/functions.php`, appended as clearly-marked blocks (see section 3).
- **WordPress:** 6.9.x, PHP 8.5.
- **Custom post type:** `domain`. Price meta key: `domain_price`. Category taxonomy: `domain_category`.
- **CDN:** Hostinger CDN (cdn.domainbrothers.com) caches HTML and ignores query strings. During this work, CDN "Development mode" was turned ON to bypass caching. **TURN IT OFF** in hPanel when done, or pages won't update for visitors / will serve stale HTML. If a code change "doesn't show," it's almost always this cache.
- **SSH:** enabled on the account (but credentials are not in this repo; editing was done through the WP Theme File Editor).

---

## 2. Current status of every task

### Done & verified

- Core hardening: plugins updated, Wordfence firewall on, real server cron (WP pseudo-cron off), object-cache drop-in removed, `WP_MEMORY_LIMIT` 256M, tagline fixed.
- Migration bug fixes (PHP 8 / migration): single-domain pages, Buy Now critical error, Blog page, Acquire form + email, search spacing, sitemap links.
- Removed ex-team members; rewrote Refund Policy; reviewed Terms/FAQs.
- On-site Stripe checkout (custom branded page replacing Stripe hosted checkout) — full payment and payment-plan (subscription) flows.
- Branded email system — one table-based template, dynamic copyright year, beta→prod URL rewrite, wraps CF7 emails. Managed from theme code.
- Stripe webhooks — verified end-to-end: payment delivered to beta with valid signature, handler fires branded receipt + admin "DOMAIN SOLD". Recurring receipts, dunning/failed-payment warnings, term-cap (auto-cancel after final installment), admin alerts. Accepts BOTH live and test signing secrets.
- Payment-plan summary page — zero-interest across all terms, per-term pricing, exact-date payment schedule, transfer-after-final-payment + interim IP/MX messaging.
- Dynamic SEO meta (title/description/OG) for domain pages.
- Domain page banner image fixed; developed-one (`?lis=y`) footer logo fixed (server-side).
- 5 service landing pages created + linked from About Us with the agency group + 25 yrs (see section 4).
- Thank-you page — type-aware headline (purchase/plan/offer) + service-icon cross-sell links.
- Honeypot anti-spam on forms.
- Downtime root-caused (shared-plan 508s) and documented.

### Implemented but needs a manual check

- **Stripe Payment Element + Link/wallets (#48):** the checkout was switched to Stripe Payment Element (Link, Cash App Pay, Klarna, cards, wallets all render). The automated end-to-end card test could not be completed by the agent because Stripe Link forces a mobile number (inside a cross-origin iframe the tooling can't drive). **Action:** do ONE manual test purchase (test card `4242 4242 4242 4242`, any future expiry/CVC, uncheck "Save my information for faster checkout" or enter a phone) and confirm redirect to the Thank-You page + the branded receipt email. If anything is wrong, the proven previous checkout is preserved as `db_onsite_checkout_block.php` (v1, split CardElement) — paste it back over the v2 block.

### Pending (need the owner or a follow-up)

- **#54 SMTP delivery** — beta has NO authenticated SMTP, so `wp_mail` falls back to PHP `mail()` and Gmail drops it. A WordPress settings page was built: **Settings → DB SMTP Email** (pre-filled Hostinger host `smtp.hostinger.com`, port 465 SSL, from `sales@domainbrothers.com`). **Action:** enter the mailbox password there and Save, then visit `/?db_mailtest=jay@jaymehta.co` (as admin) to confirm. Until this is done, receipts/alerts/offer/contact emails won't actually deliver.
- **#52 Domain News** — the `/news/` page is just old WordPress posts dated 2024 (not a live feed). Owner chose "pull a live industry RSS feed". **Reconstructed this session** as `db_news_rss_block.php` (uses `fetch_feed()` from domain-industry sources). Needs deploy + verify.
- **#39 Make-a-Custom-Offer flow** — needs: customer confirmation email + fix offer redirect (currently lands on production `/thank-you/`; should go to beta `/thank-you/?type=offer&domain=...`). The thank-you page already supports `?type=offer`. **Reconstructed this session** as `db_offer_flow_block.php`. Needs the offer form ID/field names confirmed, then deploy + verify.
- **#44 Plugin cleanup** — owner must DELETE the inactive plugins (agent only deactivates, never deletes): WP PayPal, AIOS, WPS Hide Login, WP Mail SMTP. Keep All-in-One WP Migration (for migration). Replace "Team Members" plugin with static HTML. reCAPTCHA/Turnstile needs site+secret keys from the owner (honeypot is already active in the meantime).
- **#53 Full payment-type test matrix** — test full purchase + each plan term (3/6/9/12) + offer, end to end, once SMTP + Payment Element are confirmed.

---

## 3. Architecture — the custom code blocks

All custom code is appended to `functions.php` as blocks delimited by `/* === DB ... === */ ... /* === end DB ... === */`. The exact source of each block was kept as a `db_*_block*.php` file. To recreate the live state, the theme's original `functions.php` + these blocks (in order) = current `functions.php`.

| File in repo | Block marker | What it does |
|---|---|---|
| `db_onsite_checkout_v2.php` | `DB Stripe Checkout (on-site custom card form)` | LIVE checkout. Stripe Payment Element (Link/wallets). |
| `db_onsite_checkout_block.php` | (same marker) | v1 fallback — proven split CardElement version. |
| `db_webhook_block_v2_admin_and_verify.php` | `DB Stripe webhooks` | LIVE webhooks. Verifies signature, handles payment/invoice events. |
| `db_create_webhook_block.php` | `DB auto-create Stripe webhook endpoint` | Admin trigger `/?db_create_webhook=1`. |
| `db_smtp_routing_block_v3.php` | `DB SMTP routing` | LIVE SMTP. Settings → DB SMTP Email. |
| `db_planterm_block_v2.php` | `DB fix payment-plan term selection` | Zero-interest per-term pricing + schedule. |
| `db_thankyou_block.php` | `DB thank-you` | Type-aware thank-you headline + cross-sell. |
| `db_service_pages_block.php` | `DB service landing pages creator` | Admin trigger `/?db_make_service_pages=1`. |
| `db_link_services_about_block.php` | `DB link services on About page` | Admin trigger `/?db_link_services_about=1`. |
| `db_email_template_block.php` | `DB unified branded email` | Branded HTML email wrapper. |
| `db_dynamic_meta_block.php` | `DB dynamic SEO meta` | Dynamic meta for `is_singular('domain')`. |
| `db_devone_logo_block.php` | `DB developed-one footer logo` | Server-side logo injection on `?lis=` pages. |
| `db_honeypot_block.php` | `DB honeypot anti-spam` | Hidden field on CF7 forms. |
| `db_whlog_block.php` | `DB webhook hit logger` | Diagnostic webhook log. |
| `db_news_rss_block.php` | `DB live news RSS feed` | **NEW (#52)** live industry RSS on `/news/`. |
| `db_offer_flow_block.php` | `DB make-an-offer flow` | **NEW (#39)** offer confirmation email + redirect fix. |

### Admin-only trigger URLs (must be logged in as admin)

- `/?db_create_webhook=1` — create Stripe webhook endpoint + save signing secret.
- `/?db_make_service_pages=1` — create/refresh the 5 service pages.
- `/?db_link_services_about=1` — add services section to About.
- `/?db_mailtest=you@email.com` — send a test email + report SMTP status.
- `/?db_whlog=1` — view recent webhook deliveries.
- `/?db_news_refresh=1` — (new) force-clear the cached news feed.

---

## 4. Service pages & key URLs

- `/website-design-development/`, `/digital-marketing/`, `/software-development/`, `/mobile-app-development/`, `/other-services/`
- Linked from `/about-us/` ("Our Services" section). Agencies referenced: Mindshare Consulting Inc., Jay Mehta Digital, Netclues, 25+ years.
- Checkout entry: `/buy-now/?d=<base64 domain>&p=<base64 $price>` (full) or with `&m=<months>` / `&t=plan` (plan). Plan setup: `/payment-plan-setup/?d=...&p=...`.
- Thank you: `/thank-you/?domain=...&type=full|plan|offer`.

---

## 5. Stripe configuration

- Test keys are configured in Settings → Stripe Keys (checkout works in test mode).
- A test-mode webhook endpoint was auto-created pointing at beta; its test signing secret is saved (Settings → Stripe Webhook, "Test signing secret").
- **For production go-live:** switch to LIVE Stripe keys in Settings → Stripe Keys, then hit `/?db_create_webhook=1` (as admin) to create the LIVE endpoint and auto-save the LIVE signing secret. In the Stripe Dashboard, enable Smart Retries (Billing → dunning) and TURN OFF Stripe's own failed-payment emails (we send branded ones). Set Stripe branding (logo/color) for the few Stripe-only emails (e.g. 3DS).
- Webhook events used: `payment_intent.succeeded`, `invoice.paid`, `invoice.payment_succeeded`, `invoice.payment_failed`, `customer.subscription.deleted`, `charge.refunded`.

---

## 6. How to edit functions.php safely (lessons learned)

The agent edited via the WP Theme File Editor + CodeMirror. Reliable recipe:

1. Open `wp-admin/theme-editor.php?file=functions.php`.
2. In console/JS: get `document.querySelector('.CodeMirror').CodeMirror`, do a string replace of the marked block (or append), then `cm.setValue(...)` and also set `document.getElementById('newcontent').value`.
3. Verify brace balance (`{` count === `}` count) before saving — WordPress lints PHP on save and rejects unbalanced/broken code (the file on disk is left unchanged if rejected, so a bad save won't break the site).
4. Click `#submit` once (double-submitting can corrupt the buffer). Confirm "File edited successfully."

**Gotchas:**

- JavaScript `String.replace()` treats `$'`, `$&`, `` $` `` in the *replacement string* as special. Use the **function form**: `str.replace(search, () => replacement)`.
- For large blocks, gzip the PHP, base64 it, and decode in-browser with `DecompressionStream('gzip')`.
- Stripe Elements live in cross-origin iframes; you cannot click/type into them via coordinate tools reliably or read them via the accessibility tree.

---

## 7. SECURITY — rotate these (exposed in chat/exports)

`CHAT_EXPORT.md` contains secrets the user pasted during the session. Rotate all of them:

- WordPress admin password.
- Stripe live secret key, Stripe test secret key, and the webhook signing secrets.
- Any SMTP/mailbox password should only ever be entered in the WP Settings page, never committed.

Agent safety rules honored: never entered passwords/keys into fields itself (user pasted), never deleted plugins/files (only deactivated), test-mode only (no real charges), did not touch the blocked Stripe Dashboard via automation.

---

## 8. Files in this repo

- `HANDOFF.md` (this file) — start here.
- `NEXT_STEPS.md` — prioritized action list, owner-only vs. code work.
- `db_news_rss_block.php` — **NEW (#52)** live RSS news feed block.
- `db_offer_flow_block.php` — **NEW (#39)** offer confirmation email + redirect fix.
- (Not yet committed: `CHAT_EXPORT.md` and the other `db_*_block.php` files / earlier reports — they live only on the live site and the prior session. Provide them to add to version control.)

---

## 9. Immediate next steps for whoever picks this up

1. Enter the SMTP mailbox password (Settings → DB SMTP Email) and confirm `/?db_mailtest=`.
2. Do one manual test purchase to confirm the Payment Element checkout completes + receipt arrives.
3. Deploy + verify the live RSS feed for `/news/` (#52) — `db_news_rss_block.php`.
4. Confirm the offer form ID/fields, then deploy + verify the offer flow (#39) — `db_offer_flow_block.php`.
5. Owner: delete inactive plugins, provide reCAPTCHA keys (#44).
6. Go-live: live Stripe keys → `/?db_create_webhook=1` → Stripe dashboard dunning/email settings → turn CDN Development mode OFF.
