# Domain Brothers Beta — Master Handoff

**Purpose:** Single source of truth so another agent or developer can continue this project with full context.

Owner: Jay Mehta (jay@jaymehta.co).

---

## 1. What this project is

Domain Brothers is a WordPress domain marketplace. Staging site: https://beta.domainbrothers.com. Production: https://www.domainbrothers.com. The goal: make beta fully functional (front + back end), custom on-site Stripe checkout + payment plans, branded emails, AEO/SEO, and site hardening — then promote to production.

### Environment & access

- **WP admin:** https://beta.domainbrothers.com/wp-admin — **rotate credentials** (exposed in prior chat).
- **Host:** Hostinger shared "Agency Startup" plan. hPanel under tech@netclues.com (impersonate). Site path: `/home/.../websites/boqfWdtEc/public_html`.
- **Theme:** DomainFolio (commercial, no child theme). All custom code is now in the **DB Custom Blocks plugin** (v3.0.0) — NOT in functions.php.
- **WordPress:** 6.9.x, PHP 8.5.
- **Custom post type:** `domain`. Price meta key: `domain_price`. Category: `domain_category`.
- **CDN:** Hostinger CDN — Development mode ON during development. **Turn OFF** in hPanel when done.
- **SSH:** enabled (credentials not in repo).

---

## 2. Plugin architecture (v3.0.0)

All custom code lives in the **`db-custom-blocks` WordPress plugin** (`wp-content/plugins/db-custom-blocks/db-custom-blocks.php`). This is a single-file monolithic plugin with 17 blocks (see §3). It replaces the old approach of pasting code into `functions.php`.

### How to install / update

1. WP Admin → Plugins → Add New → Upload Plugin → upload `db-custom-blocks.zip` → Activate.
2. OR use the one-shot `db-recover.php` recovery script (see §8) — it installs + activates automatically.

### Deployment for updates

After code changes: rebuild the zip, upload via WP Admin → Plugins → (hover) → Update, OR deactivate + delete + re-upload.

---

## 3. The 17 blocks in db-custom-blocks v3.0.0

| Block # | Block name | What it does |
|---------|-----------|--------------|
| 1 | SMTP routing (SendGrid) | Routes wp_mail() via SendGrid SMTP. WP Admin → Settings → DB SMTP (SendGrid). `/?db_sg_test=email` to test. |
| 2 | Homepage redesign | Full-width dark navy hero, eyebrow, headline, CTAs, trust bar. Injected via `the_content` on front page. |
| 3 | Live news RSS feed | Pulls live domain-industry RSS (domainnamewire.com, domaininvesting.com, dnjournal.com). Shortcode `[db_news]` or auto-inject on /news/ page. `/?db_news_refresh=1` to clear cache (admin). |
| 4 | Services nav menu | Injects "Services" dropdown into primary nav. CSS-only on desktop, JS tap-toggle on mobile. |
| 5 | Payment plan widget | `/payment-plan-setup/?d=BASE64&p=BASE64` — 3/6/9/12 month term selector, zero-interest, exact per-installment rounding, payment schedule. |
| 6 | Offer flow | CF7 offer form → redirect to `/thank-you/?type=offer`, customer confirmation email, 30-min rate limiting per email. |
| 7 | SEO block | Noindex utility pages, HTML sitemap shortcode `[db_sitemap]`, RankMath filters. `/?db_seo_setup=1` (admin) applies RankMath settings. |
| 8 | Performance hardening | Remove query strings, preconnect hints, lazy loading, disable XML-RPC, block author enumeration, restrict REST user listing, HTTP security headers. |
| 9 | AEO / JSON-LD | Organization, WebSite+SearchAction, FAQPage, Service, BreadcrumbList schemas on appropriate pages. Open Graph fallbacks. |
| 10 | Modern UI | CSS design system (tokens, typography, spacing), body class `db-ui-active`, dark mode via `prefers-color-scheme`. |
| 11 | Honeypot anti-spam | Hidden honeypot field on all CF7 forms. Blocks submission if filled. |
| 12 | Dynamic meta (domain CPT) | Override `<title>` and `<meta description>` for individual domain listing pages. Only fires if RankMath is NOT active. |
| 13 | Service pages creator | Creates/refreshes 5 service landing pages. Trigger: `/?db_make_service_pages=1` (admin + nonce). |
| 14 | DevOne logo fix | Replaces footer attribution logo on `?lis=y` pages server-side. |
| 15 | **[Phase 2] Stripe checkout + webhooks** | On-site Stripe Payment Element checkout at `/buy-now/?d=...&p=...`. Payment plan first installment with `&m=MONTHS`. Webhook handler at `/wp-json/db/v1/stripe-webhook`. Admin settings: Settings → DB Stripe Keys. Triggers: `/?db_create_webhook=1`. |
| 16 | **[Phase 3] Branded email + thank-you** | Unified HTML email template wrapping all wp_mail() calls for domain sales. Type-aware thank-you page (`?type=full\|plan\|offer`). Customer receipts, admin alerts, payment-failed emails, offer acks. |
| 17 | **[Phase 4] CRM lead management** | Custom DB table for leads. Auto-creates lead on CF7 offer form submission. WP Admin → Domain Brothers → Leads: list, filter, edit, bulk status change, CSV export. Dashboard widget. |

### Admin trigger URLs (must be logged in as admin)

- `/?db_create_webhook=1` — create Stripe webhook endpoint, auto-save signing secret.
- `/?db_make_service_pages=1` — create/refresh 5 service pages (nonce-protected confirm).
- `/?db_mailtest=you@email.com` — send a test email via SendGrid + report SMTP status.
- `/?db_news_refresh=1` — force-clear the news feed transient cache.
- `/?db_seo_setup=1` — apply RankMath settings (noindex, sitemap).
- `/?db_stripe_log=1` — view last 20 Stripe webhook events (admin only).

---

## 4. Service pages & key URLs

- `/website-design-development/`, `/digital-marketing/`, `/software-development/`, `/mobile-app-development/`, `/other-services/`
- Checkout: `/buy-now/?d=<base64 domain>&p=<base64 $price>` — full purchase.
- Payment plan checkout: `/buy-now/?d=...&p=...&m=<months>` — plan first installment.
- Payment plan setup (pricing widget): `/payment-plan-setup/?d=...&p=...`
- Thank you: `/thank-you/?type=full|plan|offer&domain=<base64>&amount=<base64>`

---

## 5. Stripe configuration

- Keys stored in WP options: `db_stripe_pub_live`, `db_stripe_sec_live`, `db_stripe_pub_test`, `db_stripe_sec_test`, `db_stripe_mode` ('test'|'live').
- Webhook signing secrets: `db_stripe_wh_secret_live`, `db_stripe_wh_secret_test`.
- Settings page: WP Admin → Settings → DB Stripe Keys.
- For go-live: switch to LIVE mode on settings page, then run `/?db_create_webhook=1`.
- Webhook events handled: `payment_intent.succeeded`, `invoice.payment_failed`, `charge.refunded`.

---

## 6. Leads / CRM (Block 17)

- DB table: `{$wpdb->prefix}db_leads` — auto-created on plugin load.
- Lead statuses: new → contacted → negotiating → won / lost.
- Lead sources: offer_form (CF7 auto-capture), direct (manual add).
- Admin: WP Admin → Domain Brothers → Leads.
- CSV export: WP Admin → Domain Brothers → Export CSV.

---

## 7. SECURITY — rotate these (exposed in prior chat)

- WordPress admin password.
- Stripe live + test secret keys and webhook signing secrets.
- SMTP/API keys — only ever enter in WP Settings pages, never commit.

---

## 8. Recovery from a broken site

If the site shows "critical error" or is broken:

1. Upload `db-recover.php` to WordPress root via Hostinger hPanel → File Manager.
2. Visit: `https://beta.domainbrothers.com/db-recover.php?token=DB_RECOVER_ALPHA7`
3. The script: cleans functions.php, installs + activates the plugin, creates QC user, self-destructs.

QC login (created by recovery script): `qc-tester` / `QCtest@DomBro2025!`

---

## 9. Remaining owner-only actions (no code needed, just admin clicks)

| Action | Where |
|--------|-------|
| Enter SendGrid API key and Save | WP Admin → Settings → DB SMTP (SendGrid) |
| Enter Stripe test keys (pub + secret) + set mode to Test | WP Admin → Settings → DB Stripe Keys |
| Run `/?db_create_webhook=1` after entering keys | Browser (admin) |
| One manual test purchase (card `4242 4242 4242 4242`) | /buy-now/ |
| Run `/?db_make_service_pages=1` to create service pages | Browser (admin) |
| Delete inactive plugins: WP PayPal, AIOS, WPS Hide Login, WP Mail SMTP | WP Admin → Plugins |
| Rotate WP admin password + Stripe keys | WP Admin / Stripe Dashboard |
| Turn CDN Development Mode OFF | Hostinger hPanel |
| Submit `/sitemap_index.xml` to Google Search Console | GSC |
