# Domain Brothers: production go-live checklist

Current as of plugin **v3.46.0** (2026-10-25). This replaces the older go-live notes in `HANDOFF.md`, `GOLIVE_TEST_PLAN.md` and `NEXT_STEPS.md` (July 2026, plugin v3.4.0). Those describe 0%-interest plans and features that have since changed.

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
3. Check that Plugins shows **Domain Brothers Custom Blocks 3.46.0** as active.
4. Purge the caches: LiteSpeed Cache → Toolbox → Purge All, or visit `/?db_purge_cache=1` while logged in as admin. Then purge the CDN or Cloudflare cache if one is in front.

Production-specific behaviour, already handled in code:
- **Table prefix:** the theme hard-codes `Dmb_` (production's prefix). The rewrite only applies on hosts with a different prefix, so on production it does nothing.
- **Staging noindex:** this is tied to the `beta.` host. Production stays indexable.
- **Domain-page banners** (`/images/detail/*.jpg`): these load from `www.domainbrothers.com`, which is correct on production.
- **Contact Form 7 offer form id:** `DB_OFFER_FORM_ID` = **1878**. **Verify that form 1878 exists on production and is the "Make an Offer" form**; if production uses a different id, define `DB_OFFER_FORM_ID` in `wp-config.php`.

## 3. Stripe (owner, with the developer)

| # | Task |
|---|---|
| 3.1 | WP Admin → Settings → **DB Stripe Keys**: enter **live** publishable and secret keys, and **test** keys. Choose the mode. |
| 3.2 | Create the webhook, either with the **Auto-Create Stripe Webhook** button on that page or by hand in the Stripe Dashboard. Point it at `https://www.domainbrothers.com/wp-json/db/v1/stripe-webhook` with events `payment_intent.succeeded`, `payment_intent.payment_failed`, `invoice.paid`, `invoice.payment_failed`, `charge.refunded`. Paste the signing secret into the matching field. |
| 3.3 | Stripe Dashboard → Settings → Billing → **Customer portal**: enable it, allowing payment-method updates. The "update my card" link in missed-payment emails opens this portal. |
| 3.4 | **First, in test mode on staging:** run a full purchase and a 3-month plan with test card `4242 4242 4242 4242`. Then a plan with decline card `4000 0000 0000 0341`, which attaches but fails later. Confirm the emails and the plan record. `GOLIVE_TEST_PLAN.md` §1–4 and §12 have the click path, but its plan amounts predate interest: 3 and 6 months are 0%, 9 months 5%, 12 months 10%. |
| 3.5 | Check that WordPress cron runs daily, because plan reminders, retries and termination run from it. On shared hosting, add a real cron job that calls `wp-cron.php` every 15 minutes and set `DISABLE_WP_CRON` true. |
| 3.6 | **Act on "PAYMENT NEEDS REVIEW (possible refund)" emails.** The site sends one when a payment arrives that it will not apply: a second buyer for a reserved or sold domain, or a late installment on a finished or terminated plan. Check the payment in Stripe and refund it if needed. "PLAN CHARGE ERROR" emails mean Stripe refused the request itself (keys, mode, or a deleted customer); nothing was charged, and it retries the next day. |
| 3.7 | **Legal review** of the plan terms text: forfeiture of paid installments after 10 days unpaid, and transfer only on the final payment. The text is in `db_plan_terms_html()` in `db-plan-lifecycle-block.php`. Buyers must tick agreement to it, and their acceptance is stored. |

## 4. Marketing / analytics (owner)

- **Remove Universal Analytics `UA-78917810-32`** in Cloudflare → Zaraz. Google stopped processing UA in July 2024, so it only costs load time.
- **Decide which third-party tags stay:** Google Tag Manager, GA4, Google Ads, Microsoft Clarity, HubSpot, AddToAny and Zaraz together block the main thread for about 0.9 s on mobile. They are the main reason the mobile performance scores are 42–78. Removing unused ones, or loading them after the visitor's first interaction, is a marketing call, because it affects ad attribution.

## 5. Content decisions (owner)

- **19,700 domain records that aren't live** (17,891 private, 1,781 "unpublish", 52 trash, against 207 published). Archive or delete them after the backup, unless they are sold-domain history you need.
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
