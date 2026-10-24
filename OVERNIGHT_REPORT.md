# Overnight Work Report

For Jay — read this when you wake up. Honest status on the 5 things you asked for.

## The hard constraint (why I couldn't "fix everything")

**This environment cannot reach `beta.domainbrothers.com` at all** — not the admin,
not even the public pages. Confirmed two ways tonight:
- Admin login / curl → proxy returns **403 (policy denial)**.
- Public page via WebFetch → **403 Forbidden**.

The org network policy for this session allowlists GitHub, package registries, and
Anthropic only; your site is blocked. So I **cannot test the live site, see the
mobile/desktop design, read your Terms/pages, or diagnose email delivery against the
real code.** I will not push a blind site-wide CSS redesign or rewrite legal pages I
can't see — that risks breaking the live site with no way to verify.

**To let me actually do the design/content/testing work:** change this environment's
network policy to allow `beta.domainbrothers.com` (or "allow all outbound") and start
a fresh session — then I can log in, pull everything, and work for real. (Or paste the
relevant files into chat.)

---

## Your 5 points — triaged

### 1. Monthly-payment emails not arriving  → OWNER ACTION (2 min), not code
Almost certainly **#54: no authenticated SMTP is configured.** Without it `wp_mail`
falls back to PHP `mail()`, which Gmail and most providers silently drop. No code
change fixes this — the mailbox password has to be entered once:
- wp-admin → **Settings → DB SMTP Email** → enter the `sales@domainbrothers.com`
  mailbox password (host `smtp.hostinger.com`, port 465 SSL) → **Save**.
- Then visit `/?db_mailtest=jay@jaymehta.co` (logged in as admin) — it reports the
  SMTP result and sends a test.
Once that's green, receipts, recurring-payment receipts, dunning, offer and contact
emails will all start delivering.

### 2. Plan terms "don't show up correct"  → CODE WRITTEN, ready to paste
New block `db_planterm_block_v3.php`. It's a clean, self-contained replacement that
**doesn't depend on the theme's markup** (which is the most likely reason the old one
broke). It:
- reads domain `d` + price `p` from the URL (base64, as documented),
- shows **0% interest**, correct **per-term monthly** (3/6/9/12), with the final
  payment absorbing rounding so installments sum to the exact total,
- renders an **exact-date schedule** (first payment today, then monthly),
- keeps the transfer-after-final-payment + interim IP/MX messaging,
- routes "Continue" to `/buy-now/?t=plan&m=<months>&d=...&p=...`.
**Deploy:** delete the old `DB fix payment-plan term selection` block in
`functions.php`, paste this one at the end, save. Then open a plan link and confirm
each term shows the right monthly + dates.

### 3. Mobile issues + desktop "not modern enough"  → NEEDS ACCESS for site-wide
I can't see the theme/CSS, so a responsible global redesign isn't possible blind.
What I *can* control I've made modern + mobile-responsive:
- the new **plan widget** above (fluid type, responsive term grid, stacks on mobile),
- the **news feed** grid (#52) is already responsive,
- the **offer confirmation email** (#39) is a clean branded layout.
A real mobile/desktop design pass on the whole site needs the access change in the
box above. When you grant it, tell me the specific pages that look worst and I'll do
a measured, testable pass.

### 4. Terms & Conditions / other page content  → NEEDS ACCESS (or paste)
I can't review content I can't read. When I have access (or you paste the page text),
I'll proof Terms, Refund Policy, Privacy, FAQs for accuracy, consistency, and tone.
Per the handoff these were drafted earlier, so this is a review pass, not a rewrite.

### 5. "Deep dive + fix everything"  → done as far as the wall allows
Everything achievable blind is on the PR. The rest is gated on either the 2-minute
SMTP action (#1) or the network-policy change (design/content/testing).

---

## What's ready on the PR right now
- `db_planterm_block_v3.php` — corrected, modern, responsive plan terms (#2).
- `db_news_rss_block.php` — live RSS news (#52), reviewed.
- `db_offer_flow_block.php` — offer confirmation email + beta redirect (#39), reviewed.
- `HANDOFF.md`, `NEXT_STEPS.md`, this report.

## Do-this-first checklist when you wake
1. Enter the SMTP password + run `/?db_mailtest=` (fixes the email problem).
2. Paste `db_planterm_block_v3.php` over the old plan block; verify the terms page.
3. Decide on access: flip the network policy so I can do the design + content + live
   testing for real, then ping me.
4. Rotate the admin password and Stripe/webhook secrets (exposed in chat).
