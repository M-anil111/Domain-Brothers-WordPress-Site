# Domain Brothers — Go-Live Test Plan

> **Run this top to bottom before switching to live Stripe keys.**
> Each section states what to do, what to look for, and what "pass" means.

---

## 0. Pre-flight (5 min)

| Check | How | Pass |
|---|---|---|
| CDN is in Development Mode | Hostinger hPanel → CDN → Development Mode ON | Toggle is green |
| All new blocks pasted | WP admin → Appearance → Theme File Editor → functions.php → scroll to bottom | All 6 blocks present |
| SMTP key entered | Settings → DB SMTP (SendGrid) | Status shows green ✓ |
| Test email delivered | /?db_mailtest=jay@jaymehta.co (as admin) | Inbox receives email within 60s |
| Stripe is in TEST mode | Settings → Stripe Keys | Keys start with `sk_test_` |

---

## 1. Full one-time purchase test

**Setup:** Have the test card `4242 4242 4242 4242` (any future date, any CVC) ready.

**Steps:**
1. Go to a domain listing page on the beta site.
2. Click **Buy Now** (or go to `/buy-now/?d=<base64 domain>&p=<base64 price>` directly).
3. Fill in the Stripe Payment Element with the test card.
4. Submit the payment.

**Pass criteria:**
- [ ] Browser redirects to `/thank-you/?type=full&domain=...`
- [ ] Thank-you page shows "Purchase confirmed" headline (not plan/offer variant)
- [ ] Admin receives a "DOMAIN SOLD" email at `sales@domainbrothers.com`
- [ ] Customer receives a branded purchase receipt email
- [ ] In Stripe Dashboard (test mode) → Payments → a new succeeded payment appears

**Fail indicators:**
- Blank page after payment → Stripe webhook not reaching the site
- No admin email → SMTP not working or webhook handler not firing
- No customer email → same

---

## 2. Payment plan — 3 months

**Steps:**
1. Go to `/payment-plan-setup/?d=<base64 domain>&p=<base64 price>` with a known price (e.g. `p=<base64 of "1200">`).
2. Select the **3-month** term in the plan widget.
3. Verify the widget shows: monthly = price ÷ 3 (rounded, final payment absorbs remainder), today's date for payment 1, +1 month for payment 2, +2 months for payment 3.
4. Click **Continue to secure checkout →** — URL should be `/buy-now/?t=plan&m=3&d=...&p=...`.
5. Complete checkout with test card.

**Pass criteria:**
- [ ] Plan widget renders with correct per-term amounts (not "—")
- [ ] "Continue" CTA links to `/buy-now/` with `t=plan&m=3`
- [ ] Payment creates a Stripe Subscription (not a one-time PaymentIntent) in test Dashboard
- [ ] Thank-you page shows "Payment plan confirmed" or "Plan activated" headline
- [ ] Customer receives plan confirmation email
- [ ] Admin receives notification

---

## 3. Payment plan — 6 months

Same as section 2 but select **6-month** term.

**Pass criteria:**
- [ ] Monthly = price ÷ 6, schedule shows 6 dates
- [ ] Stripe Subscription with 6 invoices scheduled
- [ ] Correct emails

---

## 4. Payment plan — 12 months (annual)

Same as section 2 but select **12-month** term.

**Pass criteria:**
- [ ] Monthly = price ÷ 12, schedule shows 12 dates
- [ ] Stripe Subscription with 12 invoices
- [ ] Correct emails

**Edge case — rounding:** For a $100 domain on 12 months: 12 × $8.33 = $99.96, so the 12th payment should be $8.37 (not $8.33). Verify the final row in the schedule table shows the slightly higher amount.

---

## 5. Make-an-Offer flow

**Steps:**
1. Go to a domain page that has the CF7 offer form.
2. Fill in name, email, domain, offer amount.
3. Submit.

**Pass criteria:**
- [ ] Browser redirects to `/thank-you/?type=offer&domain=<domain>` (NOT production URL)
- [ ] Thank-you page shows "Offer received" headline
- [ ] Submitter receives a branded "We received your offer" email at the address they entered
- [ ] Admin receives notification

**Check:** The redirect must go to the **beta** domain, not `www.domainbrothers.com`.

---

## 6. Homepage hero

1. Open the front page (not logged in, incognito).

**Pass criteria:**
- [ ] Dark navy hero section appears above the domain listings
- [ ] Headline "The Right Domain Changes Everything." is visible
- [ ] "Browse Premium Domains" and "Make an Offer" buttons are present and clickable
- [ ] Trust bar (Expert Brokerage / 0% Interest Plans / Secure Escrow / 24-Hour Response) is visible
- [ ] On mobile (resize to < 580px): buttons stack vertically, trust bar collapses

---

## 7. Services navigation menu

1. Open any page (not logged in).

**Pass criteria:**
- [ ] "Services" appears in the top navigation
- [ ] Hovering "Services" (desktop) reveals a dropdown with 5 items:
  - Website Design & Development
  - Digital Marketing
  - Software Development
  - Mobile App Development
  - Other Services
- [ ] Each dropdown link navigates to the correct service page
- [ ] On mobile: "Services" is tappable and reveals an accordion list

---

## 8. News page

1. Go to `/news/` (not logged in).

**Pass criteria:**
- [ ] Page shows live RSS articles (not old 2024 WordPress posts)
- [ ] Articles have titles, dates, and sources (Domain Name Wire, Domain Investing, etc.)
- [ ] "Read more" links open the correct external articles in a new tab

---

## 9. SEO checks

1. Visit `/sitemap_index.xml`

**Pass criteria:**
- [ ] Returns valid XML (not 404)
- [ ] Lists sub-sitemaps for pages and domain CPT

2. Visit `/buy-now/` and view page source.

**Pass criteria:**
- [ ] `<meta name="robots" content="noindex, nofollow">` is present

3. Repeat for `/thank-you/` and `/payment-plan-setup/`.

4. Add `[db_sitemap]` shortcode to the `/sitemap/` page in WP admin (if not done), then visit `/sitemap/`.

**Pass criteria:**
- [ ] Renders a grid of all pages and domain listings

---

## 10. Email — full delivery check (run after section 1–5)

| Email type | Trigger | Should arrive at |
|---|---|---|
| Full purchase receipt | Section 1 payment | Customer + admin |
| Plan confirmation | Section 2/3/4 | Customer + admin |
| Offer received | Section 5 | Customer (the submitter) |
| Admin "domain sold" | Section 1 | sales@domainbrothers.com |
| SMTP test | /?db_mailtest= | jay@jaymehta.co |

Check both inbox AND spam folder. If in spam, domain authentication (DKIM) is not yet set up in SendGrid — follow the "Domain Authentication" steps in Settings → DB SMTP (SendGrid).

---

## 11. PageSpeed (run via browser)

1. Go to https://pagespeed.web.dev
2. Enter `https://beta.domainbrothers.com` → Analyze

**Target scores:**
- Performance: 70+ mobile, 85+ desktop
- SEO: 90+
- Accessibility: 80+
- Best Practices: 90+

**Common quick wins if scores are low:**
- Add `loading="lazy"` to below-fold images
- Ensure CDN is serving assets (turn Development Mode OFF → CDN activates)
- Add `<link rel="preconnect">` for Google Fonts if used

---

## 12. Stripe webhook test

In Stripe Dashboard (test mode):
1. Go to Developers → Webhooks → your beta endpoint
2. Click "Send test event" → `payment_intent.succeeded`

**Pass criteria:**
- [ ] Endpoint returns 200 status
- [ ] Admin receives a notification email
- [ ] Check `/?db_whlog=1` (as admin) to see the logged event

---

## 13. Security checklist

- [ ] WP admin password rotated (old one was shared in chat)
- [ ] Stripe test secret key rotated (was shared in chat)
- [ ] Stripe webhook signing secret rotated
- [ ] SendGrid API key is restricted (Mail Send only, not Full Access)
- [ ] No inactive plugins left: WP PayPal, AIOS, WPS Hide Login, WP Mail SMTP deleted (not just deactivated)

---

## Go-live switch (ONLY after all above pass)

1. In Stripe: get LIVE secret key → save in Settings → Stripe Keys
2. Visit `/?db_create_webhook=1` (as admin) → creates LIVE webhook endpoint, saves signing secret
3. In Stripe Dashboard → Billing → Smart Retries: ON
4. In Stripe Dashboard → turn OFF Stripe's own failed-payment emails (we send branded ones)
5. Hostinger hPanel → CDN → turn Development Mode **OFF**
6. Verify one LIVE test purchase with a real card for $1 (refund it afterward)
7. Submit `/sitemap_index.xml` to Google Search Console

---

*Generated by Claude Code — Domain Brothers beta, 2026-07-03*
