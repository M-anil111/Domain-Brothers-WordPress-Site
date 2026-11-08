# Domain Brothers: production go-live checklist

Current as of plugin **v3.68.0** (2026-11-08). Before approval, record the exact commit that is deployed: run `tools/build-release.sh` before packaging, then check Domain Brothers > Launch checks, which shows the version and commit. A test pass only counts for the commit it was run against. This replaces the older go-live notes in `HANDOFF.md`, `GOLIVE_TEST_PLAN.md` and `NEXT_STEPS.md` (July 2026, plugin v3.4.0). Those describe 0%-interest plans and features that have since changed.

The detailed record of what was changed, and why, is in `QA_REPORT.md`. How to run the test suites is in `tests/README.md`.

Everything below has been built and tested on **staging only** (`beta.domainbrothers.com`). Production (`www.domainbrothers.com`) does not have this plugin yet.

---

## 1. Before anything else (host / owner)

| # | Task | Why |
|---|---|---|
| 1.1 | **Patch PHP:** production 8.2.21 → **8.2.35**, staging 8.5.11 → **8.5.12** (hPanel → Advanced → PHP Configuration, or ask Hostinger). | The PHP releases of 2026-10-21 fix remote-code-execution bugs. |
| 1.2 | **Rotate every credential that was shared in chat or email:** WP admin, hosting/hPanel, database, the Hostinger API token, and the staging Basic Auth password. | They are exposed. Rotating costs minutes; leaving them is open-ended risk. |
| 1.3 | **Back up production:** a full database export and `wp-content/`. | Needed for rollback. |
| 1.4 | Staging `robots.txt` (the physical file in the site root) → `User-agent: *` / `Disallow: /`. | The plugin already sends noindex, but the physical file still says "allow". |

## 2. Install the plugin on production

1. Build the zip from this repo: `cd` to the repo root and run `zip -r db-custom-blocks.zip db-custom-blocks`.
2. WP Admin → Plugins → Add New → Upload Plugin → choose the zip → **Replace current with uploaded** (or Install + Activate if it isn't there yet).
3. Check that Plugins shows **Domain Brothers Custom Blocks 3.54.0** as active.
4. Purge the caches: LiteSpeed Cache → Toolbox → Purge All, or visit `/?db_purge_cache=1` while logged in as admin and click **Confirm**. Then purge the CDN or Cloudflare cache if one is in front.

Production-specific behaviour, already handled in code:
- **Table prefix:** the theme hard-codes `Dmb_` (production's prefix). The rewrite only applies on hosts with a different prefix, so on production it does nothing.
- **Staging noindex:** this is tied to the `beta.` host. Production stays indexable.
- **Domain-page banners** (`/images/detail/*.jpg`): these load from `www.domainbrothers.com`, which is correct on production.
- **Contact Form 7 offer form id:** `DB_OFFER_FORM_ID` = **1878**. **Verify that form 1878 exists on production and is the "Make an Offer" form**; if production uses a different id, define `DB_OFFER_FORM_ID` in `wp-config.php`.

## 3. Stripe (owner, with the developer)

| # | Task |
|---|---|
| 3.1 | WP Admin → Settings → **DB Stripe Keys**: enter **live** publishable and secret keys, and **test** keys. Choose the mode. |
| 3.1a | **Already done on staging (sandbox):** test keys entered, mode = test, checkout and a 3-month plan paid with the `4242` test card (QA_REPORT §17). Not yet tested: webhooks (staging is behind Basic Auth, so Stripe cannot reach it), Apple Pay / Google Pay. **Roll the sandbox secret key** (it was pasted in a chat) and never reuse test keys in live mode. |
| 3.2 | Create the webhook, either with the **Auto-Create Stripe Webhook** button on that page or by hand in the Stripe Dashboard. Point it at `https://www.domainbrothers.com/wp-json/db/v1/stripe-webhook` with events `payment_intent.succeeded`, `payment_intent.payment_failed`, `invoice.paid`, `invoice.payment_failed`, `charge.refunded`. Paste the signing secret into the matching field. |
| 3.3 | Stripe Dashboard → Settings → Billing → **Customer portal**: enable it, allowing payment-method updates. The "update my card" link in missed-payment emails opens this portal. |
| 3.4 | **First, in test mode on staging:** run a full purchase and a 3-month plan with test card `4242 4242 4242 4242`. Then a plan with decline card `4000 0000 0000 0341`, which attaches but fails later. Confirm the emails and the plan record. `GOLIVE_TEST_PLAN.md` §1–4 and §12 have the click path, but its plan amounts predate interest: 3 and 6 months are 0%, 9 months 5%, 12 months 10%. |
| 3.5 | Check that WordPress cron runs daily, because plan reminders, retries and termination run from it. On shared hosting, add a real cron job that calls `wp-cron.php` every 15 minutes and set `DISABLE_WP_CRON` true. |
| 3.6 | **Act on "PAYMENT NEEDS REVIEW (possible refund)" emails.** The site sends one when a payment arrives that it will not apply: a second buyer for a reserved or sold domain, or a late installment on a finished or terminated plan. Check the payment in Stripe and refund it if needed. "PLAN CHARGE ERROR" emails mean Stripe refused the request itself (keys, mode, or a deleted customer); nothing was charged, and it retries the next day. |
| 3.7 | **Legal review** of the plan terms text: forfeiture of paid installments after 10 days unpaid, and transfer only on the final payment. The text is in `db_plan_terms_html()` in `db-plan-lifecycle-block.php`. Buyers must tick agreement to it, and their acceptance is stored. |

## 3b. Spam protection on the forms (owner, with the developer)

- **Add a CAPTCHA to the Contact Form 7 forms.** Cloudflare Turnstile is free and invisible to most visitors: create a site key in Cloudflare, then enter it under Contact → Integration in WordPress. The reCAPTCHA tag currently has no site key.
  - The plugin now limits offer-confirmation emails to 3 per visitor IP per hour and 1 per recipient per 30 minutes, and keeps visitor text out of them.
  - A CAPTCHA also stops bots filling the CRM with fake leads.

## 4. Marketing / analytics (owner)

- **Remove Universal Analytics `UA-78917810-32`** in Cloudflare → Zaraz. Google stopped processing UA in July 2024, so it only costs load time.
- **Decide which third-party tags stay:** Google Tag Manager, GA4, Google Ads, Microsoft Clarity, HubSpot, AddToAny and Zaraz together block the main thread for about 0.9 s on mobile. They are the main reason the mobile performance scores are 42–78. Removing unused ones, or loading them after the visitor's first interaction, is a marketing call, because it affects ad attribution.

## 5. Content decisions (owner)

- **19,700 domain records that aren't live: do not delete.** They are consigned listings from other owners, mostly made private in 2017–18, and carry owner, booking and sale history. See `QA_REPORT.md` §13 and `/?db_domain_inventory=1`. Decide commercially which to re-confirm with their owners and republish, and which to mark expired.
- **Clean-up after the backup:** 4,926 old revisions, and the tables left by removed plugins (Yoast, WP Automatic, GOTMLS).
- **Unpublish sold listings.** Checkout already refuses them.
- **Delete `/plugnpay/`** (an empty legacy page) if PlugnPay is no longer used.
- **Affiliate links in old imported news posts:** they are kept and marked sponsored. Delete them if you prefer.

## 6. After go-live: verify

1. Run the live suite against production. It skips the password and checks that production pages are indexable:
   `QA_BASE=https://www.domainbrothers.com NODE_PATH=$(npm root -g) node tests/qa/run-qa.js ./qa-prod`
   - Expect every check to pass. A few checks use specific staging listings (`fatality.ca`, `aethereum.ca`), so a failure on one of those means the listing differs on production, not a bug.
   - This suite only reads pages. It never submits forms or payments.
2. Spot-check by hand:
   - home, a domain page, `/all-domains/` with the filter bar, a news post, `/contact-us/`;
   - one real **Make an Offer** submission, then confirm the email arrives and the CRM lead appears.
3. Google Search Console: submit `https://www.domainbrothers.com/wp-sitemap.xml`, then watch Coverage and Core Web Vitals over the next 2–4 weeks.
4. Re-measure speed on production with PageSpeed Insights. Staging numbers are uncached, because LiteSpeed doesn't cache requests that carry the staging password.

## 7. Rollback

- Plugins → deactivate **Domain Brothers Custom Blocks**. The site falls back to the plain theme.
- Payment plans already running keep their records in `wp_options` (`db_stripe_plan_*`). Re-activating the plugin resumes them.
- For a full rollback, restore the database export from step 1.3.

## Added in v3.62 - v3.63 (account, alerts, ads, emails)

| # | What to do |
|---|---|
| 8.1 | **Email delivery first.** Alerts, sign-in codes, receipts and every other email depend on working SMTP/SendGrid. Send a test of the sign-in code and one receipt to a real Gmail, Outlook and Apple Mail inbox (Domain Brothers > Email templates > a template > Send test email) and check the look. |
| 8.2 | WordPress cron must run (alert emails go out every 15 minutes from `db_acct_digest`). On shared hosting add a real cron job for `wp-cron.php`. |
| 8.3 | **Ads:** create three responsive display ad units in AdSense, then Domain Brothers > Ads: paste the publisher id (`ca-pub-...`) and the three ad unit ids. Add a consent banner for visitors in the EEA, UK and Switzerland before turning personalised ads on. Leave the publisher id empty to show no ads. |
| 8.4 | Review each email template's wording (Domain Brothers > Email templates), especially payment plan terms, and the Design tab (logo, colours, footer). |
| 8.5 | The follower alerts say only that "a new offer was made", never the amount or who. Keep it that way unless counsel advises otherwise. |
| 8.6 | **Email delivery (SMTP2GO)** is set under Settings > DB Email delivery (SMTP): server `mail.smtp2go.com`, port 2525 (STARTTLS), the SMTP2GO username and password, From `sales@domainbrothers.com`. The password is stored encrypted; it is never shown again or kept in the code. Enter the same settings on the production site (or define `DB_SMTP_HOST`, `DB_SMTP_USER`, `DB_SMTP_PASS` in `wp-config.php`). In SMTP2GO, verify the sender domain (SPF and DKIM DNS records) so mail does not land in spam. |
| 8.7 | **AdSense verification:** the code snippet and `/ads.txt` ship with the plugin (v3.65.0). After deploying to production, open `https://www.domainbrothers.com/ads.txt` and view the source of the home page to confirm both, then choose Verify in AdSense. Ads.txt can take Google up to a day to be picked up. |
| 8.8 | **Live ads.txt already exists as a real file** on www.domainbrothers.com with a different publisher (`pub-7194359990997088`), and the live theme prints an older AdSense script (`ca-pub-2138271276562459`). A real file overrides the plugin's `/ads.txt`. After deploying v3.65.1 either add the line `google.com, pub-1077085934387393, DIRECT, f08c47fec0942fa0` to that file, or delete the file so the plugin serves both lines (it keeps the old publisher's line under Domain Brothers > Ads > Other ads.txt lines). Beta cannot be verified by Google: it is behind a password and tells crawlers not to index it, so verify the live domain. |
| 8.9 | **Domain Guides (content cluster):** after deploying v3.67.0 go to Domain Brothers > Content cluster and choose **Import as drafts**. Read each article, adjust wording and the call-to-action buttons to taste, then publish. The articles are written without statistics we cannot verify; if you add numbers, add the source. Submit `/premium-domain-names-complete-guide/` in Search Console after it is live, and link to it from the home page or footer. |

## Go-live report, 2026-11-08: status of each finding

| Finding | Status |
|---|---|
| Release identity not recorded | Fixed in code: `tools/build-release.sh` writes the commit into the package and Launch checks shows it. Run it before every package. |
| Staging robots.txt allows crawling | Not fixed in code: the file served to the internet is Cloudflare's managed robots.txt (cached, `Allow: /`), and it overrides the plugin's staging `Disallow`. Owner action in Cloudflare: give the beta host a `Disallow: /` robots.txt, or turn off managed robots for it. Staging pages still send `X-Robots-Tag: noindex, nofollow`. Launch checks reports this. |
| Bot protection (Turnstile) | Built, off until keys are saved (Domain Brothers > Launch checks). Covers contact forms, sign-in codes and offers. Owner action: create the widget in Cloudflare and save its keys. |
| Stale "Email is failing" warning | Fixed: the warning clears after the first successful send. |
| Escrow statements | Owner decision: the plugin has no escrow integration. Confirm the arrangement, or the statements change. Tracked in Launch checks. |
| Credentials, backups, webhook reachability, SPF/DKIM, legal review, CDN mode, ad ids | Owner or host actions, listed in Launch checks. |
| Real cron | Launch checks reports overdue jobs. Owner action: a real cron job every 5 minutes. |
| Sales roadmap (10 items) | Built in v3.69.1 (QA_REPORT section 33): finder, buyer-intent score, verified "Why this price?", transfer workspace, service options, collections, offer guidance and broker request, funnel, trust center. Not built: abandoned checkout and offer reminders (needs marketing consent and owner sign-off on wording), experiments, automatic offer acknowledgements and response timers, transfer document history and two-way messaging. |
