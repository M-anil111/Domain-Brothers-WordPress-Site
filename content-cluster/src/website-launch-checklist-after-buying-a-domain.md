---
title: Website Launch Checklist: The First Week After Buying a Domain
slug: website-launch-checklist-after-buying-a-domain
role: spoke
excerpt: A website launch checklist for the first week after you buy a domain: secure it, connect DNS, turn on HTTPS, set up email, add Search Console and publish.
tags: website, launch checklist, DNS, email, SEO
---
This website launch checklist turns a newly bought domain into a working site in seven days. You have the name; now it has to do something. The first week sets up everything you will rely on for years: security, email that reaches inboxes, a clean address structure and the ability to measure. This checklist works for a new site and for a site moving to a new domain. It is part of our [complete guide to premium domain names](/premium-domain-names-complete-guide/).

!takeaways In short
- Secure the domain first: lock, two-factor sign-in, auto-renew.
- Connect DNS, turn on HTTPS and choose one preferred address.
- Set up email with SPF, DKIM and DMARC before you send anything.
- Add analytics and Search Console on day one.

!img:infographic-i4|Website launch checklist infographic with six launch tasks: DNS, HTTPS, email authentication, redirects, analytics and Search Console, and the core pages|The six launch tasks, in the order we recommend.

## The website launch checklist at a glance

Use this table as the summary, then work through the detail day by day below. Each task has a simple test that tells you it is done.

| Day | Task | Done when |
|---|---|---|
| 1 | Secure the domain | Lock, two-factor sign-in and auto-renew are on |
| 1 to 2 | Connect DNS | The name loads your host's page |
| 2 | Turn on HTTPS | Every page loads on https with no warnings |
| 2 to 3 | Set up email | SPF, DKIM and DMARC pass on a test message |
| 3 | Choose one address | All other versions redirect to it with a 301 |
| 4 | Measure | Analytics records a visit and Search Console is verified |
| 5 | Publish the basics | Home, about, contact and policy pages are live |
| 6 to 7 | Speed and quality check | The site works on a phone and every form sends |

## Day 1: Secure the domain

Security is the first item on any website launch checklist, because a lost or hijacked domain takes the site and the email with it.

!img:ib-launch-a|Website launch checklist for the first week, from securing the domain to the quality check|The website launch checklist across the first week.

1. **Confirm ownership.** Check that the registrant details and email address are yours.
2. **Turn on the registrar lock** so the name cannot be transferred without you.
3. **Turn on two-factor sign-in** on the registrar account and on the email address that receives its messages.
4. **Switch on auto-renew** and add a payment method that will not expire soon.
5. **Save records:** the purchase agreement, the receipt and the transfer confirmation.

If you bought through us on a payment plan, remember that ownership transfers after the final payment; meanwhile we can point the name at your site.

## Day 1 to 2: Connect DNS

DNS is the system that turns your name into the address of your server. The [Wikipedia article on the Domain Name System](https://en.wikipedia.org/wiki/Domain_Name_System) gives the background; in practice you do a few things. DNS comes early in any website launch checklist because changes can take hours to reach every network.

- Add an **A record** (or CNAME) that points the name to your host.
- Add a record for the **www** version, or redirect it.
- Keep the **TTL** low (a few minutes) while you set up, then raise it.
- Add **MX records** for email.
- Note every record in a document so you can restore it.

See [Domain Transfers, EPP Codes and DNS Explained](/how-to-transfer-a-domain/) for more on DNS.

## Day 2: Turn on HTTPS

No website launch checklist is complete without HTTPS on every page.

- Install an SSL/TLS certificate. Most hosts offer free ones.
- Redirect every http address to its https version.
- Fix mixed-content warnings, where a secure page loads an insecure image or script.
- Check that the certificate renews automatically. Free certificates usually last a few months, and an expired one shows visitors a full-page warning.

## Day 2 to 3: Set up email properly

A premium domain deserves a professional email address, and email needs three records to be trusted. Email is the step on a website launch checklist that people skip most often, and it is the one that sends invoices to spam.

- **SPF** lists the servers allowed to send for your domain.
- **DKIM** signs your messages so they cannot be forged.
- **DMARC** tells receivers what to do with messages that fail, and gives you reports. The [DMARC overview](https://dmarc.org/overview/) explains it.

Start DMARC in monitoring mode, read the reports for a few weeks, then tighten the policy. Send a test message to several providers before you launch a campaign.

## Day 3: Choose one preferred address and redirect the rest

- Decide whether the site lives at `example.com` or `www.example.com`, and redirect the other.
- Redirect any other domains you own to the main one with permanent (301) redirects.
- If you are moving from an old site, map each old page to its new equivalent. Google's [site-move guide](https://developers.google.com/search/docs/crawling-indexing/site-move-with-url-changes) lists the steps.

## Day 4: Measure

Measuring from day one shows what each step of the website launch checklist changed.

- Install analytics and set up conversions for your main goals: a contact form, a purchase, a booking.
- Add the domain to [Google Search Console](https://search.google.com/search-console/about) and verify it, then submit your sitemap.
- Check the [Search Console help](https://support.google.com/webmasters/answer/9008080) if you are new to it.
- If your analytics tool sets cookies, check the consent rules in the countries you serve and add a consent banner where they require one.
- Record a starting point: visits, enquiries and search impressions in the first week give you something to compare against.

## Day 5: Publish the basics

At least: These pages are the minimum a website launch checklist should require before you announce the site.

1. A clear **home page** that says who you help and how.
2. An **about page** with real names and photos.
3. A **contact page** with a form, an email and a phone number if you have one.
4. A **privacy policy** and, if you collect payments or data, terms.
5. One **page per service or product** with a clear next step.

Use the same business name, address and phone number on every page and every profile. Consistent details help visitors trust you and help search engines connect your site with your business.

## Day 6 to 7: Speed and quality check

- Test the site on a phone. Most visitors will be on one.
- Run a speed test and read about [Core Web Vitals](https://web.dev/articles/vitals) to learn what the scores mean.
- Check every form and every link.
- Add alt text to images and titles and descriptions to pages.
- Ask a friend to find your phone number in ten seconds.
- Check that every page can be used with a keyboard alone and that text has enough contrast against its background.

If an item on this website launch checklist fails, fix it before the announcement rather than after. Problems are cheaper to fix while nobody is visiting yet.

## Final tests: the website launch checklist before you announce

Before you tell customers about the site, run one last pass. It takes about an hour and catches the problems visitors would otherwise find for you.

!img:ib-launch-b|Website launch checklist final tests: addresses, forms, titles, noindex, 404 page and backup|Final tests on the website launch checklist.

1. **Visit every version of the address**: with and without `www`, on http and https. All of them should land on the one preferred address.
2. **Submit every form** and confirm that the message arrives in the right inbox, not the spam folder.
3. **Check the page titles and descriptions** in a search preview tool, so each page has its own.
4. **Look for a stray `noindex` tag.** Sites built on a staging address often carry one, and it keeps the site out of search results.
5. **Check the 404 page.** A missing page should show a helpful message and a link home.
6. **Test on two browsers and two phones.**
7. **Make a backup** of the site and note how to restore it.

### Website launch checklist for a site moving to a new domain

If you are replacing an old domain with the new one, add these steps:

- Keep the old domain registered and renewing, and redirect each old page to its new equivalent.
- Add both domains to Search Console and use the change-of-address tool where it applies.
- Update the address in social profiles, email signatures, ads, directory listings and printed material.
- Tell your customers and partners, and ask partners who link to you to update the link.
- Watch Search Console for crawl errors for several weeks.

Our guide to [changing a domain name without losing SEO](/change-domain-name-without-losing-seo/) covers the move in detail.

## After the first week

Keep this website launch checklist and run it again whenever you change hosts or add a domain.

- Keep a **changelog** of DNS and site changes.
- Review email reports and search data monthly.
- Put the renewal date in your calendar.
- Repeat the website launch checklist tests after any major change, such as a new theme or host.

## Need a hand?

If you would rather not do it yourself, we build and run sites. See our [website design and development](/website-design-development/), [hosting and maintenance](/web-hosting-maintenance-services/) and [comprehensive digital solutions](/comprehensive-digital-solutions/). For mobile, see [app development](/mobile-app-development-services/). We can work through the website launch checklist with you.

## Frequently asked questions

!details:How long does a website launch checklist take to complete?|For a small site with content ready, about a week, as laid out in this guide. DNS and certificates can work within hours, but email authentication and Search Console data need a few days to confirm. A larger site, or one moving from an old domain, needs more time for redirects and testing.
!details:What is the most important item on a website launch checklist?|Securing the domain comes first, because everything else depends on it. Turn on the registrar lock, two-factor sign-in and auto-renew on day one. After that, email authentication matters most, since messages without SPF, DKIM and DMARC are more likely to land in spam.
!details:Do I need a developer to launch a website on a new domain?|Not always. Many hosts and website builders connect a domain, install a certificate and set up email with a few clicks. A developer helps when you move an existing site, need custom features or want someone else to handle redirects, speed and security checks.
!details:Should I launch before the site is finished?|A short, accurate site is better than a holding page. Publish the home, about and contact pages with real information, and add service pages as they are ready. Avoid placeholder text, because visitors and search engines see it from the first day.

## Where to go next

- Learn how the name helps marketing in the [Domain Name Marketing Playbook](/domain-name-marketing-playbook/).
- Understand [SEO and domains](/do-premium-domains-help-seo/).

!cta Launch with a team beside you|Design, hosting, email and marketing from the people who found you the name.|Website design and development|/website-design-development/|Hosting and maintenance|/web-hosting-maintenance-services/

!related
