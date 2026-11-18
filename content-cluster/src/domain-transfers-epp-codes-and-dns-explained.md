---
title: How to Transfer a Domain: EPP Codes, Locks and DNS Explained
slug: domain-transfers-epp-codes-and-dns-explained
role: spoke
excerpt: How to transfer a domain from start to finish: who does what, the EPP code, transfer locks, waiting periods, DNS records and the checks that stop problems.
tags: domain transfer, EPP code, DNS, registrar
---
The moment you buy a premium domain, the next question is how to transfer a domain from the seller's account to your own. Transfers have their own words (registrar, EPP code, lock, nameserver) and a few traps. This guide explains the whole process in plain English so you know what is happening at each step. It is part of our [complete guide to premium domain names](/premium-domain-names-complete-guide/).

!takeaways In short
- A transfer moves the domain from the seller's registrar account to yours, using an authorisation code (the EPP code).
- The seller must unlock the domain first, and you start the transfer at your registrar.
- DNS controls where the name points. Keep it working through the move.
- Most transfers take a few days. Keep your records and secure the name afterwards.

!img:infographic-i7|How to transfer a domain in five steps: unlock, share the EPP code, request the transfer, approve it, then secure the domain|The five steps of a change of registrar.

## The cast of characters

| Term | What it is |
|---|---|
| **Registrant** | The person or company that owns the domain |
| **Registrar** | The company where the domain is registered and managed, such as the place you log in |
| **Registry** | The organisation that runs the extension, for example the operator of .com |
| **ICANN** | The body that sets the policies registrars follow. See [ICANN](https://www.icann.org/) |
| **DNS** | The system that maps names to server addresses |
| **EPP code** | A secret authorisation code, also called an auth code, that proves the right to move a domain |

The [ICANN transfer policy](https://www.icann.org/resources/pages/transfer-policy-2016-06-01-en) is the rulebook for moving domains between registrars, and the [Wikipedia article on the Domain Name System](https://en.wikipedia.org/wiki/Domain_Name_System) explains DNS in more depth.

## Two kinds of move

1. **A change of registrar**: the domain moves from one registrar to another. This needs the EPP code.
2. **A push or account move**: the domain stays at the same registrar and moves from one account to another. This is usually faster and needs no code, only the registrar's internal tool.

A sale uses one or the other, depending on where the seller and buyer hold their accounts, so the first step in working out how to transfer a domain is to ask where each side holds it. Your registrar's help pages will say which options it supports.

### How to transfer a domain between accounts at the same registrar

If you and the seller use the same registrar, an account move is usually the simplest route. You give the seller the account name or email address that identifies you at that registrar. The seller starts the push from their account, and you accept it in yours. There is no EPP code and no registry step, so the move often completes within a day. It does not normally add a year to the registration, and if the domain uses the registrar's DNS, the records usually move with it.

## Before you start: how to transfer a domain without delays

Most failed or slow transfers come from something that could have been checked in five minutes. Before anyone requests a code, run through this list with the seller.

!img:ib-transfer-a|Checks before you transfer a domain: recent changes, expiry date, contact email, account, DNS records and extension rules|What to check before you transfer a domain.

- **Recent changes.** ICANN policy lets a registrar refuse a transfer within 60 days of the initial registration or a previous change of registrar. If the name was registered or moved recently, plan around that date.
- **Expiry date.** If the domain expires within the next few weeks, agree who renews it first. A name that lapses during the move can get stuck or cost more to recover.
- **Contact details.** The registrant email address must be one the seller can still read, because confirmation messages go there.
- **Your account.** Open an account at your chosen registrar, turn on two-factor sign-in, and check that it supports the extension you are buying.
- **DNS records.** Export or screenshot every record so you can rebuild it if anything is lost.
- **The extension's rules.** Some country-code extensions use their own process. For example, .uk names move by changing a registrar tag rather than with an EPP code.

## Step by step: how to transfer a domain to a new registrar

1. **The seller unlocks the domain** at their registrar. Domains are locked by default so nobody can steal them.
2. **The seller gets the EPP code** from their registrar and shares it with you through a secure channel, such as a private link, never in a public message. On our side we send buyers a private page that shows the code, and you can read more on our [EPP guidelines](/epp-guidelines/).
3. **You start the transfer** at your registrar: enter the domain and the code.
4. **Both sides confirm.** There may be confirmation emails to the owner's address. Check the spam folder and act within the stated time.
5. **The registry processes the transfer**, normally in a few days. Some registries are faster, some have extra steps.
6. **You secure it:** lock the domain, turn on two-factor sign-in, set auto-renew.

## Waiting periods and locks

Many registrars apply a **lock of about sixty days** after a new registration or a change of the registrant's details, and registrars follow the [ICANN transfer policy](https://www.icann.org/resources/pages/transfer-policy-2016-06-01-en) in different ways. Ask the seller's registrar about locks before you agree a delivery date, because no amount of knowing how to transfer a domain gets around a lock that is still running. A transfer also usually renews the domain for a year, which the buyer pays at the registrar.

Once your registrar submits the request, the current registrar has a short window, normally five days, to approve or reject it. If it does nothing, the transfer usually goes through automatically at the end of that window. The seller can often approve it sooner from their account, which is the easiest way to save time.

## DNS: keeping the website and email working

DNS is the address book that tells the internet where your website and email are. When a domain moves, the **nameservers** decide who controls this address book.

- If the nameservers stay the same, nothing changes for visitors during the move.
- If you change nameservers, copy every record first: A, AAAA, CNAME, MX, TXT (for SPF, DKIM and DMARC) and any others.
- Lower the **TTL** to a few minutes a day before you change records so changes spread quickly.
- After the move, test the website and send test emails.

Watch for one common trap. If the seller used the old registrar's free DNS service, those records can disappear when the domain leaves that registrar. Set up DNS at your new host before the transfer completes, then point the nameservers there.

Our [website launch checklist](/website-launch-checklist-after-buying-a-domain/) shows which records to add on a new site.

## Common problems and fixes

Even when both sides know how to transfer a domain, small details cause most delays. These are the ones we see most often.

| Problem | Likely cause | What to do |
|---|---|---|
| "Domain is locked" | Seller has not unlocked it | Ask the seller to unlock at their registrar |
| "Invalid authorisation code" | Code mistyped, expired or for another domain | Ask for a fresh code and paste it without spaces |
| No confirmation email | Old contact address or spam filter | Check spam; update contacts before the transfer |
| Transfer pending for days | Waiting for the approval step | Approve the request or ask the registrar to push it |
| Website down after the move | Nameservers changed with missing records | Restore the records at the new DNS host |
| Email stopped | MX records missing | Add MX and TXT records again |

## After the transfer: secure the domain

Knowing how to transfer a domain is half the job; the other half is keeping it. On the day the transfer completes:

!img:ib-transfer-b|After you transfer a domain: check the record, lock it, two-factor sign-in, auto-renew and save records|How to secure the name the day you transfer a domain.

1. **Check the record.** Look up the domain and confirm that your registrar is now listed.
2. **Turn the lock back on** so nobody can move the name without your approval.
3. **Turn on two-factor sign-in** for the registrar account and for the email address it uses.
4. **Set auto-renew** and add a payment card that will not expire soon.
5. **Update your contact details** if anything is out of date.
6. **Save your records:** the purchase confirmation, the transfer confirmation and the date.

## How we handle transfers

For names bought from us: after payment clears, we start the transfer within the agreed time and send you what you need. Payment is confirmed before the transfer starts, so there is no third party in the middle. For payment plans, the plan terms set out that the domain stays with us until the final payment clears. EPP codes submitted on our site are encrypted and erased after 30 days. Read more on the [EPP guidelines](/epp-guidelines/) page, on our [domain transfer](/domain-transfer/) page, in the [FAQs](/faqs/), or ask our [team](/contact-us/).

## Frequently asked questions

!details:How long does it take to transfer a domain?|A change of registrar usually takes a few days, because the current registrar has a short window, normally five days, to respond. The seller can often approve the request sooner, which shortens it. A move between two accounts at the same registrar is usually faster and often finishes within a day.
!details:Will my website go down while I transfer a domain?|Not if you plan it. If the nameservers stay the same, visitors notice nothing. If you change nameservers, copy every DNS record to the new host first and lower the TTL a day before. Then test the website and send a test email as soon as the move completes.
!details:Can I transfer a domain that was registered last month?|Often not yet. ICANN policy lets a registrar refuse a transfer within 60 days of the initial registration or a previous change of registrar. An account move at the same registrar can still be possible. Ask the registrar which options apply before you agree a delivery date.
!details:What do I need to know about how to transfer a domain bought from Domain Brothers?|After your payment clears, we start the transfer within the agreed time and send you what you need. On a payment plan, the domain stays with us until the final payment clears and then transfers. Codes submitted on our site are encrypted and erased after 30 days.

## Where to go next

- Learn the buying side in [How to Buy a Premium Domain Safely](/how-to-buy-a-premium-domain-safely/).
- Learn why registrars differ in the [domain management services](/domain-management-services/) overview.
- See [Trademarks and Domain Disputes](/trademarks-and-domain-disputes-udrp-basics/) if you receive a complaint after a transfer.

!cta Want us to handle the transfer?|We guide every buyer through the process, from the authorisation code to the first day on your own account.|Buy a domain|/buy-domains-service/|Read our EPP guidelines|/epp-guidelines/

!related
