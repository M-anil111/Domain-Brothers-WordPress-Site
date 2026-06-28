# Domain Brothers Beta — Next Steps

Prioritized action list, split by who can do it. See `HANDOFF.md` for full context.

> **Important context:** This git repo currently holds only documentation plus
> the two freshly reconstructed code blocks below. The rest of the custom code
> (`db_onsite_checkout_*`, `db_webhook_*`, `db_smtp_*`, etc.) lives **only on the
> live Hostinger site** — it was never committed here. If you want those under
> version control, export `functions.php` from the live theme and commit the
> blocks. This environment has **no SSH/WP credentials**, so it cannot reach the
> live site directly.

---

## A. Owner-only actions (no code; do these in hPanel / WP admin / Stripe)

| # | Action | Where |
|---|---|---|
| #54 | Enter the SMTP mailbox password and Save, then verify with `/?db_mailtest=jay@jaymehta.co`. **Until done, no emails actually deliver.** | WP admin → Settings → DB SMTP Email |
| #48 | One manual test purchase (`4242 4242 4242 4242`) → confirm Thank-You redirect + branded receipt. | beta checkout |
| #44 | DELETE inactive plugins (WP PayPal, AIOS, WPS Hide Login, WP Mail SMTP). Keep All-in-One WP Migration. Replace "Team Members" plugin with static HTML. | WP admin → Plugins |
| #44 | Provide reCAPTCHA/Turnstile site + secret keys (honeypot active meanwhile). | owner → then wire in |
| — | Rotate all secrets exposed in chat: WP admin password, Stripe live/test secret keys, webhook signing secrets. | see HANDOFF §7 |
| — | Go-live: switch to LIVE Stripe keys → hit `/?db_create_webhook=1` → enable Smart Retries + turn OFF Stripe's failed-payment emails → set Stripe branding. | Stripe + WP |
| — | **Turn CDN "Development mode" OFF** in hPanel once changes are confirmed. | Hostinger hPanel |

---

## B. Code work (reconstructed this session — deploy + verify)

### #52 — Live RSS news feed → `db_news_rss_block.php`

1. Paste the block at the end of `functions.php` (Theme File Editor; see HANDOFF §6 for the safe recipe).
2. Either add `[db_news]` to the `/news/` page **or** rely on auto-inject (page slug must be `news`; change `$slug` in the block if different).
3. Verify `/news/` shows current items (not 2024 posts). Force a refresh with `/?db_news_refresh=1` (admin).
4. If a source is blocked from the server IP, edit `db_news_feeds()` and swap the feed URL.

**Assumptions to confirm:** the news page slug is `news`; feeds reachable from the Hostinger server.

### #39 — Make-an-offer flow → `db_offer_flow_block.php`

1. Paste the block at the end of `functions.php`.
2. **Confirm the offer form details** (the one thing this block can't know for sure):
   - Set `DB_OFFER_FORM_ID` to the CF7 form's post ID (or leave `0` to auto-detect by a title containing "offer").
   - Check `db_offer_field_map()` against the form's actual field names (`your-email`, `your-domain`, etc.).
3. Submit a test offer and verify:
   - The submitter receives a branded "we received your offer" email (requires #54 SMTP first).
   - The browser redirects to **beta** `/thank-you/?type=offer&domain=...` (not production).

**Assumptions to confirm:** offer form is Contact Form 7; redirect today is via the standard `wpcf7mailsent` event (this block listens to it and overrides the destination).

### #53 — Full payment-type test matrix (after #54 + #48)

Test, end to end: full purchase, each plan term (3/6/9/12), and an offer — confirming the right emails fire and the right thank-you variant shows.

---

## C. Still only on the live site (commit if you want them versioned)

`db_onsite_checkout_v2.php`, `db_onsite_checkout_block.php`,
`db_webhook_block_v2_admin_and_verify.php`, `db_create_webhook_block.php`,
`db_smtp_routing_block_v3.php`, `db_planterm_block_v2.php`,
`db_thankyou_block.php`, `db_service_pages_block.php`,
`db_link_services_about_block.php`, `db_email_template_block.php`,
`db_dynamic_meta_block.php`, `db_devone_logo_block.php`,
`db_honeypot_block.php`, `db_whlog_block.php`, plus `CHAT_EXPORT.md` and the
earlier report docs.
