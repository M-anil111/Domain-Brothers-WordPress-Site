---
title: Domain Transfers, EPP Codes and DNS Explained in Plain English
slug: domain-transfers-epp-codes-and-dns-explained
role: spoke
excerpt: How a domain transfer works from start to finish: registrar, registry and registrant, the EPP authorisation code, the transfer lock, waiting periods, DNS records and what to check so nothing breaks.
tags: domain transfer, EPP code, DNS, registrar
---
The moment you buy a premium domain, the next question is how it actually gets to you. Transfers have their own words (registrar, EPP code, lock, nameserver) and a few traps. This guide explains the whole process in plain English so you know what is happening at each step. It is part of our [complete guide to premium domain names](/premium-domain-names-complete-guide/).

!takeaways In short
- A transfer moves the domain from the seller's registrar account to yours, using an authorisation code (the EPP code).
- The seller must unlock the domain first, and you start the transfer at your registrar.
- DNS controls where the name points. Keep it working through the move.
- Most transfers take a few days. Keep your records and secure the name afterwards.

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

A sale uses one or the other, depending on where the seller and buyer hold their accounts. Your registrar's help pages will say which options it supports.

## Step by step: a change of registrar

1. **The seller unlocks the domain** at their registrar. Domains are locked by default so nobody can steal them.
2. **The seller gets the EPP code** from their registrar and shares it with you through a secure channel, ideally through the escrow service or a private link. On our side we send buyers a private page that shows the code, and you can read more on our [EPP guidelines](/epp-guidelines/).
3. **You start the transfer** at your registrar: enter the domain and the code.
4. **Both sides confirm.** There may be confirmation emails to the owner's address. Check the spam folder and act within the stated time.
5. **The registry processes the transfer**, normally in a few days. Some registries are faster, some have extra steps.
6. **You secure it:** lock the domain, turn on two-factor sign-in, set auto-renew.

## Waiting periods and locks

Many registrars apply a **lock of about sixty days** after a new registration or a change of the registrant's details, and registrars follow the [ICANN transfer policy](https://www.icann.org/resources/pages/transfer-policy-2016-06-01-en) in different ways. Ask the seller's registrar about locks before you agree a delivery date. A transfer also usually renews the domain for a year, which the buyer pays at the registrar.

## DNS: keeping the website and email working

DNS is the address book that tells the internet where your website and email are. When a domain moves, the **nameservers** decide who controls this address book.

- If the nameservers stay the same, nothing changes for visitors during the move.
- If you change nameservers, copy every record first: A, AAAA, CNAME, MX, TXT (for SPF, DKIM and DMARC) and any others.
- Lower the **TTL** to a few minutes a day before you change records so changes spread quickly.
- After the move, test the website and send test emails.

Our [website launch checklist](/website-launch-checklist-after-buying-a-domain/) shows which records to add on a new site.

## Common problems and fixes

| Problem | Likely cause | What to do |
|---|---|---|
| "Domain is locked" | Seller has not unlocked it | Ask the seller to unlock at their registrar |
| "Invalid authorisation code" | Code mistyped, expired or for another domain | Ask for a fresh code and paste it without spaces |
| No confirmation email | Old contact address or spam filter | Check spam; update contacts before the transfer |
| Transfer pending for days | Waiting for the approval step | Approve the request or ask the registrar to push it |
| Website down after the move | Nameservers changed with missing records | Restore the records at the new DNS host |
| Email stopped | MX records missing | Add MX and TXT records again |

## How we handle transfers

For names bought from us: after payment clears, we start the transfer within the agreed time and send you what you need. For payment plans, we hold the name in escrow and release it after the final payment. Read more on the [EPP guidelines](/epp-guidelines/) page, in the [FAQs](/faqs/), or ask our [team](/contact-us/).

## Where to go next

- Learn the buying side in [How to Buy a Premium Domain Safely](/how-to-buy-a-premium-domain-safely/).
- Learn why registrars differ in the [domain management services](/domain-management-services/) overview.
- See [Trademarks and Domain Disputes](/trademarks-and-domain-disputes-udrp-basics/) if you receive a complaint after a transfer.

!cta Want us to handle the transfer?|We guide every buyer through the process, from the authorisation code to the first day on your own account.|Buy a domain|/buy-domains-service/|Read our EPP guidelines|/epp-guidelines/

!related
