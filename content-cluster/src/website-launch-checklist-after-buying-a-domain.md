---
title: Website Launch Checklist After Buying a Domain
slug: website-launch-checklist-after-buying-a-domain
role: spoke
excerpt: What to do in the first week after you buy a domain: secure it, connect DNS, turn on HTTPS, set up authenticated email, redirect properly, add analytics and Search Console, and publish the core pages.
tags: website, launch checklist, DNS, email, SEO
---
You have the name. Now it has to do something. The first week sets up everything you will rely on for years: security, email that reaches inboxes, a clean address structure and the ability to measure. This checklist works for a new site and for a site moving to a new domain. It is part of our [complete guide to premium domain names](/premium-domain-names-complete-guide/).

!takeaways In short
- Secure the domain first: lock, two-factor sign-in, auto-renew.
- Connect DNS, turn on HTTPS and choose one preferred address.
- Set up email with SPF, DKIM and DMARC before you send anything.
- Add analytics and Search Console on day one.

!img:infographic-i4|Checklist infographic with six launch tasks: DNS, HTTPS, email authentication, redirects, analytics and Search Console, and the core pages|The six launch tasks, in the order we recommend.

## Day 1: Secure the domain

1. **Confirm ownership.** Check that the registrant details and email address are yours.
2. **Turn on the registrar lock** so the name cannot be transferred without you.
3. **Turn on two-factor sign-in** on the registrar account and on the email address that receives its messages.
4. **Switch on auto-renew** and add a payment method that will not expire soon.
5. **Save records:** the purchase agreement, the receipt and the transfer confirmation.

If you bought through us on a payment plan, remember that ownership transfers after the final payment; meanwhile we can point the name at your site.

## Day 1 to 2: Connect DNS

DNS is the system that turns your name into the address of your server. The [Wikipedia article on the Domain Name System](https://en.wikipedia.org/wiki/Domain_Name_System) gives the background; in practice you do a few things.

- Add an **A record** (or CNAME) that points the name to your host.
- Add a record for the **www** version, or redirect it.
- Keep the **TTL** low (a few minutes) while you set up, then raise it.
- Add **MX records** for email.
- Note every record in a document so you can restore it.

See [Domain Transfers, EPP Codes and DNS Explained](/domain-transfers-epp-codes-and-dns-explained/) for more on DNS.

## Day 2: Turn on HTTPS

- Install an SSL/TLS certificate. Most hosts offer free ones.
- Redirect every http address to its https version.
- Fix mixed-content warnings, where a secure page loads an insecure image or script.

## Day 2 to 3: Set up email properly

A premium domain deserves a professional email address, and email needs three records to be trusted.

- **SPF** lists the servers allowed to send for your domain.
- **DKIM** signs your messages so they cannot be forged.
- **DMARC** tells receivers what to do with messages that fail, and gives you reports. The [DMARC overview](https://dmarc.org/overview/) explains it.

Start DMARC in monitoring mode, read the reports for a few weeks, then tighten the policy. Send a test message to several providers before you launch a campaign.

## Day 3: Choose one preferred address and redirect the rest

- Decide whether the site lives at `example.com` or `www.example.com`, and redirect the other.
- Redirect any other domains you own to the main one with permanent (301) redirects.
- If you are moving from an old site, map each old page to its new equivalent. Google's [site-move guide](https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes) lists the steps.

## Day 4: Measure

- Install analytics and set up conversions for your main goals: a contact form, a purchase, a booking.
- Add the domain to [Google Search Console](https://search.google.com/search-console/about) and verify it, then submit your sitemap.
- Check the [Search Console help](https://support.google.com/webmasters/answer/9008080) if you are new to it.

## Day 5: Publish the basics

At least:

1. A clear **home page** that says who you help and how.
2. An **about page** with real names and photos.
3. A **contact page** with a form, an email and a phone number if you have one.
4. A **privacy policy** and, if you collect payments or data, terms.
5. One **page per service or product** with a clear next step.

## Day 6 to 7: Speed and quality check

- Test the site on a phone. Most visitors will be on one.
- Run a speed test and read about [Core Web Vitals](https://web.dev/articles/vitals) to learn what the scores mean.
- Check every form and every link.
- Add alt text to images and titles and descriptions to pages.
- Ask a friend to find your phone number in ten seconds.

## After the first week

- Keep a **changelog** of DNS and site changes.
- Review email reports and search data monthly.
- Put the renewal date in your calendar.

## Need a hand?

If you would rather not do it yourself, we build and run sites. See our [website design and development](/website-design-development/), [hosting and maintenance](/web-hosting-maintenance-services/) and [comprehensive digital solutions](/comprehensive-digital-solutions/). For mobile, see [app development](/mobile-app-development-services/).

## Where to go next

- Learn how the name helps marketing in the [Domain Name Marketing Playbook](/domain-name-marketing-playbook/).
- Understand [SEO and domains](/do-premium-domains-help-seo/).

!cta Launch with a team beside you|Design, hosting, email and marketing from the people who found you the name.|Website design and development|/website-design-development/|Hosting and maintenance|/web-hosting-maintenance-services/

!related
