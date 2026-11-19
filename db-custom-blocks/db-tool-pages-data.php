<?php
/**
 * Copy and settings for the single-purpose domain tool pages (/domain-tools/<slug>/).
 *
 * Keyword targets come from SE Ranking (US, 2026-11-19; see tools/seo-tools-keywords.json):
 * each page aims at a low-difficulty phrase and carries the harder head term as a secondary.
 * Statements are limited to facts the tool can show or that are widely documented.
 *
 * Row keys: name, title (SEO title without brand), meta, kw (focus phrase), lead, parts, show,
 * button, label, hint, autoext, steps (3 x [heading, text]), sections (n x [heading, paragraphs[]]),
 * faqs (n x [question, answer]), related (slugs).
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(

	'domain-age-checker' => array(
		'name'     => 'Domain Age Checker',
		'title'    => 'Domain Age Checker: How Old Is a Website?',
		'meta'     => 'Find out how old any domain is. See its registration date, exact age, expiry date and web archive history. Free, no sign-up.',
		'kw'       => 'domain age checker',
		'lead'     => 'Type a domain to see when it was first registered, how old it is today and when it expires, plus the latest capture in the web archive.',
		'parts'    => array( 'registry', 'archive' ),
		'show'     => array( 'Age and expiry', 'Registry record', 'Web history' ),
		'button'   => 'Check age',
		'steps'    => array(
			array( 'Enter the domain', 'Type the name with its extension, such as example.com.' ),
			array( 'Read the age', 'The result shows the registration date and the age in years, months and days.' ),
			array( 'Compare with history', 'Check the web archive to see whether a site was live before or after that date.' ),
		),
		'sections' => array(
			array( 'How old is a domain or website?', array(
				'Domain age is the time since the registry first recorded the name. It comes from the registration date in the public registry record (RDAP), so it is the same figure a registrar sees.',
				'A website can be younger than its domain. Someone may register a name and leave it parked for years before launching a site. The web archive result on this page shows the latest capture so you can tell the two apart.',
			) ),
			array( 'Registration date, transfers and renewals', array(
				'Renewing a domain or moving it to a new registrar does not reset its registration date. Selling it to a new owner does not either. The date resets only when a name is allowed to expire, is deleted by the registry and is registered again by someone else.',
				'That is why a name that looks old can still have had gaps in ownership, and why age alone is a weak guide to quality.',
			) ),
			array( 'Why buyers look at domain age', array(
				'When you buy a domain, age tells you how long the name has existed and how many owners it may have had. An older name can carry history, good or bad, such as links from past sites or a record of spam. Search engines have said that age alone is not a ranking factor, so judge a name by its history and fit for your brand, not by years alone.',
				'If you want a name that is already registered, a broker can find the owner and negotiate for you. See our domain brokerage service or browse the names we have for sale.',
			) ),
		),
		'faqs'     => array(
			array( 'How do I check how old a domain is?', 'Enter the domain above. The tool reads the public registry record and shows the registration date and the age counted from it.' ),
			array( 'When was a website created?', 'The registration date shows when the domain was first registered, which is the earliest the website could have existed. For when pages were actually published, use the web archive link to see the oldest captures.' ),
			array( 'Does domain age help SEO?', 'Search engines have said that domain age by itself is not a ranking factor. Content quality, links and how well the site answers searches matter far more.' ),
			array( 'Why does the registry show a newer date than the website?', 'A name that expired and was registered again gets a new registration date. The website may have started earlier under a previous owner.' ),
			array( 'Can I see the history of a domain?', 'Yes. The web history card links to the Wayback Machine. For a fuller view, use the website history checker.' ),
		),
		'related'  => array( 'domain-registration-lookup', 'website-history-checker', 'domain-expiry-checker' ),
	),

	'domain-registration-lookup' => array(
		'name'     => 'Domain Registration Lookup',
		'title'    => 'When Was a Domain Registered? Free Lookup',
		'meta'     => 'See when a domain was first registered, its registrar, status codes and name servers from the public registry record. Free RDAP lookup.',
		'kw'       => 'when was a domain first registered',
		'lead'     => 'Look up the public registry record for any domain: registration and expiry dates, registrar, status codes and name servers.',
		'parts'    => array( 'registry' ),
		'show'     => array( 'Registry record', 'Age and expiry' ),
		'button'   => 'Look up record',
		'steps'    => array(
			array( 'Enter the domain', 'Use the full name, for example example.com.' ),
			array( 'Read the record', 'Dates, registrar, status codes and name servers appear in one card.' ),
			array( 'Check the age', 'A second card turns the dates into an age and a time left before expiry.' ),
		),
		'sections' => array(
			array( 'WHOIS and RDAP: the same information, a newer format', array(
				'WHOIS is the older text service for domain registration data. RDAP is its replacement and returns the same kind of information in a structured format. This tool reads RDAP, which is what registries now publish.',
			) ),
			array( 'What the status codes mean', array(
				'Status codes tell you what a registry or registrar allows. "client transfer prohibited" means the registrar has locked the name against transfers, which is normal and protects it from hijacking. "client delete prohibited" and "client update prohibited" are similar protections. A name with no lock status can be moved more easily.',
			) ),
			array( 'Who owns a domain?', array(
				'Most registrars hide the owner\'s personal details from the public record, and privacy law in many countries requires it. So a lookup usually shows the registrar and dates but not a name or an email.',
				'If you want to buy a name that someone already owns, you do not need their details. Domain Brothers\' brokerage service contacts the owner for you, keeps your identity private and negotiates the price.',
			) ),
		),
		'faqs'     => array(
			array( 'Is a WHOIS lookup the same as an RDAP lookup?', 'They show the same kind of registration data. RDAP is the modern, structured version that registries now run, and it is what this page uses.' ),
			array( 'Why is the owner\'s name hidden?', 'Registrars redact personal contact details to protect privacy, and data protection rules often require it. The registrar and dates are still public.' ),
			array( 'What does "client transfer prohibited" mean?', 'The registrar has locked the domain so it cannot be transferred away without the owner unlocking it. It is a standard protection.' ),
			array( 'How can I buy a domain that is already registered?', 'Ask a broker to approach the owner. Domain Brothers does this on your behalf and keeps your identity confidential.' ),
			array( 'Why does my extension show no record?', 'Some extensions do not run a public RDAP service. The tool says so instead of guessing.' ),
		),
		'related'  => array( 'domain-age-checker', 'domain-expiry-checker', 'domain-extension-checker' ),
	),

	'domain-expiry-checker' => array(
		'name'     => 'Domain Expiry Date Checker',
		'title'    => 'Check Domain Name Expiry Date (Free)',
		'meta'     => 'Check when a domain name expires and how much time is left. Free expiry date checker using the public registry record.',
		'kw'       => 'check domain name expiry',
		'lead'     => 'See when a domain expires and how long is left, so a missed renewal never takes your website or email offline.',
		'parts'    => array( 'registry' ),
		'show'     => array( 'Age and expiry', 'Registry record' ),
		'button'   => 'Check expiry',
		'steps'    => array(
			array( 'Enter the domain', 'Type the name you own or want to watch.' ),
			array( 'Read the expiry date', 'The result gives the date and the time left, or how long ago it expired.' ),
			array( 'Act in time', 'Renew with your registrar, or ask us about a name that is about to expire.' ),
		),
		'sections' => array(
			array( 'Why check a domain\'s expiry date', array(
				'A lapsed registration can take a website and every email address on the domain offline within hours. Checking the date gives you a margin to renew, and it is the simplest way to audit names registered by a former employee or agency.',
			) ),
			array( 'What happens when a domain expires', array(
				'Timelines differ by registrar and extension, but the usual pattern is a short renewal grace period, a redemption period in which the owner can still recover the name for an extra fee, and then deletion, after which anyone can register it. Do not assume you have grace time: some registrars switch the site off the day the registration lapses.',
			) ),
			array( 'Interested in a domain that is about to expire?', array(
				'An expiry date does not mean a name is available. Many owners renew at the last minute. If you want a specific name, a broker can contact the owner while it is still registered. See our domain brokerage service.',
			) ),
		),
		'faqs'     => array(
			array( 'How do I check when a domain expires?', 'Enter the domain above. The tool reads the expiry date from the registry record and shows the time left.' ),
			array( 'Is the expiry date always accurate?', 'It is the date the registry holds. Auto-renew at the registrar usually extends it shortly before the date, so a name close to expiry may already be set to renew.' ),
			array( 'What happens if I forget to renew?', 'Your website and email can stop working. Most registrars offer a short window to renew, often at a higher price. After that the name can be deleted and registered by someone else.' ),
			array( 'Can I buy a domain once it expires?', 'Not until it is deleted and released, and even then drop-catching services and investors compete for good names. If you want a specific name now, ask a broker to approach the owner.' ),
		),
		'related'  => array( 'domain-age-checker', 'domain-registration-lookup', 'ssl-certificate-checker' ),
	),

	'ssl-certificate-checker' => array(
		'name'     => 'SSL Certificate Checker',
		'title'    => 'SSL Certificate Checker: Expiry and Issuer',
		'meta'     => 'Check any website\'s SSL certificate: issuer, valid dates, days left and whether it covers the domain. Free, instant.',
		'kw'       => 'ssl certificate expiry check',
		'lead'     => 'Enter a domain to see the SSL certificate it presents: who issued it, when it is valid and how many days are left.',
		'parts'    => array( 'ssl' ),
		'show'     => array( 'SSL certificate' ),
		'button'   => 'Check certificate',
		'steps'    => array(
			array( 'Enter the domain', 'We connect to the site on port 443 and read its certificate.' ),
			array( 'Check the dates', 'See when the certificate started, when it expires and the days left.' ),
			array( 'Confirm the name', 'The result says whether the certificate covers the exact name you entered.' ),
		),
		'sections' => array(
			array( 'What this SSL check shows', array(
				'The tool reads the certificate a site presents and reports the issuer, the valid-from and expiry dates, and whether the domain is covered by the certificate\'s names. It does not test the full certificate chain or which protocol versions a server accepts.',
			) ),
			array( 'Why certificate expiry matters', array(
				'When a certificate expires, browsers show a full-page warning and most visitors leave. Publicly trusted certificates are also being issued with shorter and shorter lifetimes, so renewal needs to be automatic. If the days left are low, renew now.',
			) ),
			array( 'Common certificate problems', array(
				'The most common problems are an expired certificate, a certificate issued for a different name (for example example.com but not www.example.com), and a site that serves no HTTPS at all. The "Covers this name" line helps you spot the second one quickly.',
			) ),
		),
		'faqs'     => array(
			array( 'How do I check an SSL certificate?', 'Enter the domain above. The tool shows the issuer, validity dates, days left and whether the certificate covers the name.' ),
			array( 'How long does an SSL certificate last?', 'It depends on the issuer, and the maximum lifetime for publicly trusted certificates keeps getting shorter. Use automatic renewal so you do not track it by hand.' ),
			array( 'What does "does not cover this name" mean?', 'The certificate was issued for other host names. Visitors to this name will see a browser warning. Reissue the certificate to include it.' ),
			array( 'Why was no certificate found?', 'The site may not offer HTTPS on port 443, may be offline, or may block connections from outside its network.' ),
		),
		'related'  => array( 'security-headers-checker', 'http-header-checker', 'domain-expiry-checker' ),
	),

	'http-header-checker' => array(
		'name'     => 'HTTP Header Checker',
		'title'    => 'HTTP Header Checker: Status and Redirects',
		'meta'     => 'Check a website\'s HTTP response headers, status code and redirect chain. Free live header checker, no sign-up.',
		'kw'       => 'http header checker',
		'lead'     => 'See exactly how a website answers a request: the redirect chain, the status code and the response headers it sends.',
		'parts'    => array( 'site' ),
		'show'     => array( 'Redirect chain', 'HTTP response headers' ),
		'button'   => 'Check headers',
		'steps'    => array(
			array( 'Enter the domain', 'We request the home page over HTTPS and follow up to five redirects.' ),
			array( 'Follow the chain', 'Each step shows its status code and address.' ),
			array( 'Read the headers', 'The final response headers are listed, including caching and server details.' ),
		),
		'sections' => array(
			array( 'What HTTP headers are', array(
				'Every web response starts with headers: short lines of information sent before the page. They describe the content type, how long it can be cached, which server answered and which security rules a browser should apply.',
			) ),
			array( 'Status codes you will see', array(
				'200 means the page was served. 301 and 308 are permanent redirects and 302 and 307 are temporary ones. 403 means the request was refused, 404 means the page was not found and 500 and above mean the server failed. A long chain of redirects slows a site, so aim for one step from the address people type to the final page.',
			) ),
			array( 'Headers worth checking', array(
				'Cache-Control and Content-Encoding show whether pages are cached and compressed. Server and X-Powered-By can reveal software versions, which some teams prefer to hide. The security headers have their own checker.',
			) ),
		),
		'faqs'     => array(
			array( 'How do I check HTTP headers?', 'Enter the domain above. The tool requests the home page and lists the redirect steps and the response headers.' ),
			array( 'What is a redirect chain?', 'It is the series of redirects between the address you request and the page finally served, for example http to https and then to www.' ),
			array( 'Why does the tool follow redirects?', 'Because most sites redirect from one address to another. Showing every step reveals delays and misconfigured redirects.' ),
			array( 'Can I check a page other than the home page?', 'This tool checks the home page of the domain you enter.' ),
		),
		'related'  => array( 'security-headers-checker', 'ssl-certificate-checker', 'dns-lookup' ),
	),

	'security-headers-checker' => array(
		'name'     => 'Security Headers Checker',
		'title'    => 'Security Headers Check: Is Your Site Safe?',
		'meta'     => 'Check which security headers a website sends: HSTS, CSP, X-Frame-Options and more. Free security headers checker.',
		'kw'       => 'security headers check',
		'lead'     => 'Find out which security headers a website sends, and which are missing, in one request.',
		'parts'    => array( 'site' ),
		'show'     => array( 'Security headers' ),
		'button'   => 'Check headers',
		'steps'    => array(
			array( 'Enter the domain', 'We request the home page and read the response headers.' ),
			array( 'See what is present', 'Six common security headers are marked Present or Missing.' ),
			array( 'Fix the gaps', 'Add missing headers in your server settings or through a plugin.' ),
		),
		'sections' => array(
			array( 'The six headers this tool checks', array(
				'Strict-Transport-Security (HSTS) tells browsers to use HTTPS only. Content-Security-Policy (CSP) limits where scripts and other content may load from. X-Frame-Options stops other sites embedding yours in a frame. X-Content-Type-Options stops browsers guessing file types. Referrer-Policy controls how much address information is shared when a visitor follows a link. Permissions-Policy limits browser features such as the camera.',
			) ),
			array( 'Missing is not the same as broken', array(
				'A missing header does not mean a site is hacked. It means a protection is switched off. HSTS and X-Content-Type-Options are low risk to add. CSP needs care because a strict policy can block scripts your site relies on, so test it before enforcing it.',
			) ),
			array( 'How to add security headers', array(
				'They are set by the web server or by a plugin, not in page content. Ask your host or developer to add them, then run this check again to confirm.',
			) ),
		),
		'faqs'     => array(
			array( 'What are security headers?', 'They are response headers that tell a browser how to protect visitors, for example by forcing HTTPS or blocking framing.' ),
			array( 'Which security headers should every site have?', 'At a minimum HSTS, X-Content-Type-Options and a Referrer-Policy. Add X-Frame-Options or a CSP frame rule if your pages should not be embedded.' ),
			array( 'Will adding a Content-Security-Policy break my site?', 'It can, if the policy blocks scripts or styles the site uses. Start in report-only mode and tighten it step by step.' ),
			array( 'Is a high score a guarantee of security?', 'No. Headers are one layer. They do not replace updates, strong passwords and a firewall.' ),
		),
		'related'  => array( 'http-header-checker', 'ssl-certificate-checker', 'dnssec-checker' ),
	),

	'dnssec-checker' => array(
		'name'     => 'DNSSEC Checker',
		'title'    => 'DNSSEC Checker: Is Your Domain Signed?',
		'meta'     => 'Check whether a domain uses DNSSEC: signed at the registry and validated by a public resolver. Free DNSSEC test.',
		'kw'       => 'dnssec checker',
		'lead'     => 'Test whether a domain is protected by DNSSEC, both at the registry and as seen by a public resolver.',
		'parts'    => array( 'registry', 'dns' ),
		'show'     => array( 'DNSSEC', 'DNS' ),
		'button'   => 'Test DNSSEC',
		'steps'    => array(
			array( 'Enter the domain', 'Use the full name, for example example.com.' ),
			array( 'Check both lines', 'One line shows whether the registry holds a signed delegation; the other whether a public resolver validated the answer.' ),
			array( 'Fix any mismatch', 'If only one is Yes, your DNS host and registrar settings do not match.' ),
		),
		'sections' => array(
			array( 'What DNSSEC does', array(
				'DNSSEC adds digital signatures to DNS answers so a resolver can check they were not forged on the way to a visitor. It protects against attackers sending people to the wrong address by tampering with DNS responses.',
			) ),
			array( 'The two results explained', array(
				'"Signed at the registry" means the extension\'s registry holds a DS record for the domain, which links it to the signing keys at your DNS host. "Validated by a public resolver" means a validating resolver accepted the answer as authentic. A fully protected name shows Yes on both.',
				'If the registry shows a DS record but validation fails, visitors who use validating resolvers may be unable to reach the site, so fix mismatches quickly. If neither is Yes, DNSSEC is not enabled.',
			) ),
			array( 'Should you turn it on?', array(
				'It adds protection but also adds a thing to manage: keys must be rolled and DS records kept in step. Many DNS hosts automate this. Ask your DNS provider before enabling it, and remove the DS record before moving DNS to a host that does not support it.',
			) ),
		),
		'faqs'     => array(
			array( 'How do I check if DNSSEC is enabled?', 'Enter the domain above. If both lines read Yes, the domain is signed and validated.' ),
			array( 'What is a DS record?', 'A DS record is published at the registry and points to the signing key held by your DNS provider, completing the chain of trust.' ),
			array( 'Why is only one line Yes?', 'The registry and DNS host are out of step: for example the DS record was left behind after changing DNS provider. Update or remove it.' ),
			array( 'Does every extension support DNSSEC?', 'Most do, but not all registrars offer it. Check with your registrar.' ),
		),
		'related'  => array( 'dns-lookup', 'security-headers-checker', 'domain-registration-lookup' ),
	),

	'dmarc-spf-checker' => array(
		'name'     => 'DMARC and SPF Checker',
		'title'    => 'DMARC and SPF Checker for Your Domain',
		'meta'     => 'Check a domain\'s SPF and DMARC records and see its DMARC policy. Free email security check for better deliverability.',
		'kw'       => 'dmarc verification',
		'lead'     => 'Check whether a domain publishes SPF and DMARC records, and what policy DMARC asks receivers to apply.',
		'parts'    => array( 'dns', 'dmarc' ),
		'show'     => array( 'Email security' ),
		'button'   => 'Check email security',
		'steps'    => array(
			array( 'Enter the domain', 'The domain you send email from.' ),
			array( 'Read SPF and DMARC', 'Each shows Present or Not found, and DMARC shows its policy.' ),
			array( 'Strengthen the policy', 'Move from none to quarantine, then reject, once reports show your mail passes.' ),
		),
		'sections' => array(
			array( 'SPF, DKIM and DMARC in plain terms', array(
				'SPF is a record that lists which servers may send mail for your domain. DKIM signs each message so receivers can check it was not altered. DMARC ties the two together and tells receivers what to do with mail that fails: nothing (none), put it in spam (quarantine) or refuse it (reject).',
				'This tool checks SPF and DMARC. It does not check DKIM, because DKIM records live under a selector name that only your email provider knows.',
			) ),
			array( 'What the DMARC policy means', array(
				'A policy of none only monitors. Quarantine asks receivers to treat failing mail as suspicious. Reject asks them to refuse it, which gives the strongest protection against someone sending mail that pretends to be you. Move up gradually, using DMARC reports to confirm that your legitimate mail passes first.',
			) ),
			array( 'Why it matters for deliverability', array(
				'Large mailbox providers have required SPF, DKIM and DMARC from bulk senders since 2024, and missing records push even ordinary mail towards spam. A domain you use for business email should publish all three.',
			) ),
		),
		'faqs'     => array(
			array( 'How do I check a DMARC record?', 'Enter the domain above. The tool looks up the DMARC record and shows its policy.' ),
			array( 'Why does it say DMARC is not found?', 'The domain has no DMARC record published. It can be added as a DNS TXT record at the name _dmarc.' ),
			array( 'Does this check DKIM?', 'No. DKIM needs a selector name from your email provider, which cannot be discovered from the domain alone.' ),
			array( 'Is SPF enough on its own?', 'No. SPF alone does not tell receivers what to do with failing mail, and it breaks when mail is forwarded. DMARC and DKIM complete the picture.' ),
		),
		'related'  => array( 'dns-lookup', 'dnssec-checker', 'domain-ip-lookup' ),
	),

	'domain-ip-lookup' => array(
		'name'     => 'Domain IP Lookup',
		'title'    => 'Get the IP Address of a Domain (Free)',
		'meta'     => 'Find the IP address of any domain and see which network hosts it. Free domain to IP lookup with DNS records.',
		'kw'       => 'get ip of domain',
		'lead'     => 'Enter a domain to get its IP address and the network that hosts it.',
		'parts'    => array( 'dns', 'host' ),
		'show'     => array( 'Hosting network', 'DNS' ),
		'button'   => 'Find IP',
		'steps'    => array(
			array( 'Enter the domain', 'For example example.com.' ),
			array( 'Get the address', 'The DNS card lists the IPv4 and IPv6 addresses the name points to.' ),
			array( 'See the network', 'The hosting card names the network that owns the first address and its country.' ),
		),
		'sections' => array(
			array( 'How a domain maps to an IP address', array(
				'A domain name is a label that DNS translates into an IP address. The A record gives an IPv4 address and the AAAA record gives an IPv6 address. Look them up here to confirm where a name points, for example after changing hosts.',
			) ),
			array( 'Who hosts a website?', array(
				'The hosting card reads the public registration record for the IP address, which names the network that owns it. For many sites that is the hosting company. If the site sits behind a content delivery network such as Cloudflare, the address belongs to the network, not to the server that actually stores the site.',
			) ),
			array( 'When the answer looks wrong', array(
				'DNS answers are cached for a time set by each record, so a change you just made may take a while to show everywhere. Several addresses for one name are normal: they spread traffic across servers.',
			) ),
		),
		'faqs'     => array(
			array( 'How do I find the IP address of a domain?', 'Enter the domain above. The DNS card lists the addresses from its A and AAAA records.' ),
			array( 'How do I find out who hosts a website?', 'Check the hosting network card. It shows the organisation that owns the IP address. If a CDN is in front of the site, it shows the CDN.' ),
			array( 'Why does a domain have more than one IP address?', 'To share traffic or to provide both IPv4 and IPv6. It is common.' ),
			array( 'Does the IP address show the owner of the site?', 'No. It shows the network that carries the site, not who owns the domain.' ),
		),
		'related'  => array( 'dns-lookup', 'http-header-checker', 'domain-registration-lookup' ),
	),

	'dns-lookup' => array(
		'name'     => 'DNS Lookup',
		'title'    => 'DNS Lookup: A, MX, NS and SPF Records',
		'meta'     => 'Run a free DNS query for any domain: A, AAAA, MX, NS records and SPF. Check where a domain points and who handles its email.',
		'kw'       => 'dns query',
		'lead'     => 'Query a domain\'s DNS records: website addresses, mail servers, name servers and whether an SPF record exists.',
		'parts'    => array( 'dns' ),
		'show'     => array( 'DNS' ),
		'button'   => 'Run DNS query',
		'steps'    => array(
			array( 'Enter the domain', 'Use the name without www, for example example.com.' ),
			array( 'Read the records', 'A and AAAA are website addresses, MX are mail servers, NS are name servers.' ),
			array( 'Compare with your settings', 'If a record is not what you set, wait for caching to expire and check again.' ),
		),
		'sections' => array(
			array( 'The records this lookup returns', array(
				'A and AAAA records point the name to IPv4 and IPv6 addresses. MX records name the servers that accept email for the domain, with a priority number: the lowest number is tried first. NS records name the servers that hold the domain\'s DNS. The SPF line says whether a mail-sending policy record exists.',
				'Answers come from Cloudflare\'s public resolver, so you see what an ordinary visitor sees.',
			) ),
			array( 'DNS changes and propagation', array(
				'Every record has a time to live that tells resolvers how long they may keep it. After a change, some resolvers still answer with the old value until that time ends. If you moved hosts or email, expect a delay of minutes to hours.',
			) ),
			array( 'Checking mail records', array(
				'If email is not arriving, check the MX records first. They should point to your email provider, not your old host. Then check the email security tool for SPF and DMARC.',
			) ),
		),
		'faqs'     => array(
			array( 'What is a DNS lookup?', 'A DNS lookup asks the domain name system which addresses, mail servers and other records belong to a name.' ),
			array( 'How do I check the MX records of a domain?', 'Enter the domain above and read the Mail servers line. The number before each server is its priority.' ),
			array( 'Why do I see old records after a change?', 'Resolvers cache records for their time to live. Wait for it to expire and check again.' ),
			array( 'Does this show every record type?', 'It shows A, AAAA, MX and NS records and an SPF flag. Other types, such as TXT contents, are not listed.' ),
		),
		'related'  => array( 'domain-ip-lookup', 'dmarc-spf-checker', 'dnssec-checker' ),
	),

	'website-history-checker' => array(
		'name'     => 'Website History Checker',
		'title'    => 'Website History Checker: See Past Versions',
		'meta'     => 'Check a website\'s history with the Wayback Machine and its domain registration age. Free site history checker before you buy a domain.',
		'kw'       => 'website history checker',
		'lead'     => 'See whether the web archive has captured a site, when it last did, and how old the domain is.',
		'parts'    => array( 'archive', 'registry' ),
		'show'     => array( 'Web history', 'Age and expiry' ),
		'button'   => 'Check history',
		'steps'    => array(
			array( 'Enter the domain', 'The site or the name you are considering.' ),
			array( 'Open the captures', 'The link takes you to every saved version in the Internet Archive.' ),
			array( 'Compare with the age', 'Check that the site history fits the registration date.' ),
		),
		'sections' => array(
			array( 'What the web archive shows', array(
				'The Internet Archive\'s Wayback Machine saves copies of web pages over time. This tool tells you whether the name has any saved copies and the date of the nearest recent one, with a link to browse them all.',
			) ),
			array( 'Why check history before buying a domain', array(
				'A name may have been used for something you would not want to be linked to, such as a spam or adult site, or may have been a brand that is now closed. Looking at old versions helps you spot problems before you invest, and also shows whether the name once had a real audience.',
			) ),
			array( 'Limits of an archive check', array(
				'The archive does not capture every site, and a missing capture does not prove a name was never used. Treat it as one signal alongside the registration date and a search for the name.',
			) ),
		),
		'faqs'     => array(
			array( 'How do I check the history of a website?', 'Enter the domain above, then follow the link to the Wayback Machine to browse saved versions by date.' ),
			array( 'Why is there no history for my domain?', 'The archive may not have captured it, especially for new or rarely linked sites.' ),
			array( 'Can history affect a domain I want to buy?', 'It can. Past spam or penalties may linger in search results, so check what the name was used for.' ),
			array( 'How is this different from domain age?', 'Age counts from registration. History shows what the site actually looked like.' ),
		),
		'related'  => array( 'domain-age-checker', 'domain-registration-lookup', 'website-rank-checker' ),
	),

	'domain-extension-checker' => array(
		'name'     => 'Domain Extension Checker',
		'title'    => 'Domain Extension Checker: Check Every TLD',
		'meta'     => 'Check whether a name is registered under .com, .net, .org, .ca, .ai, .app and more. Free domain extension checker.',
		'kw'       => 'domain extension checker',
		'lead'     => 'Enter a name and see at a glance which popular extensions already have a registration record.',
		'parts'    => array( 'registry' ),
		'show'     => array( 'Other extensions' ),
		'autoext'  => true,
		'button'   => 'Check extensions',
		'label'    => 'Name',
		'hint'     => 'yourbrand or yourbrand.com',
		'steps'    => array(
			array( 'Enter the name', 'With or without an extension. Without one we start from .com.' ),
			array( 'Read the results', 'Each extension shows registered or no record found.' ),
			array( 'Confirm before you decide', 'No record found is a strong hint, not a purchase. Check with a registrar.' ),
		),
		'sections' => array(
			array( 'What the results mean', array(
				'We read each extension\'s public registry record. "Registered" means a record exists. "No record found" means the registry shows none, which usually means the name is open to register. "Cannot be checked" means the extension does not publish a public record, or the registry did not answer.',
			) ),
			array( 'Choosing the right extension', array(
				'A .com is the most recognised choice worldwide. Country extensions such as .ca signal a local business and build trust with local customers. Newer extensions such as .ai or .app suit particular fields. If your first choice is taken, compare similar names rather than settling for a hyphenated version.',
			) ),
			array( 'If the name you want is registered', array(
				'A registered name is not always out of reach. Many owners will sell. A broker can find the owner and negotiate for you, or you can browse the premium names Domain Brothers already has for sale.',
			) ),
		),
		'faqs'     => array(
			array( 'How do I check a domain name across extensions?', 'Enter the name above. The tool checks ten popular extensions at once.' ),
			array( 'Does "no record found" mean the name is available?', 'It means the registry shows no registration. Confirm availability with a registrar before you rely on it.' ),
			array( 'Why are some extensions marked cannot be checked?', 'Some extensions, such as .io and .co, do not run a public registry lookup.' ),
			array( 'Which extension should I choose?', 'For a global brand choose .com if you can. For a local business a country extension such as .ca works well.' ),
		),
		'related'  => array( 'domain-name-analyzer', 'domain-registration-lookup', 'domain-age-checker' ),
	),

	'website-rank-checker' => array(
		'name'     => 'Website Ranking Checker',
		'title'    => 'Website Ranking Checker: Tranco Top Sites',
		'meta'     => 'Check whether a website ranks in the Tranco top one million sites and where. Free domain popularity rank checker.',
		'kw'       => 'website ranking checker',
		'lead'     => 'See whether a domain appears in the Tranco list of the one million most visited sites, and at what position.',
		'parts'    => array( 'rank' ),
		'show'     => array( 'Popularity' ),
		'button'   => 'Check rank',
		'steps'    => array(
			array( 'Enter the domain', 'Use the main domain, such as example.com.' ),
			array( 'Read the position', 'A rank means the domain is in the top one million; no rank means it is not.' ),
			array( 'Use it as one signal', 'Rank shows popularity, not value or quality.' ),
		),
		'sections' => array(
			array( 'What the Tranco list is', array(
				'Tranco is a research-grade ranking of popular domains that combines several sources and averages them over a period, which makes it harder to manipulate than a single list. It is used in security and web research.',
			) ),
			array( 'This is not a domain authority score', array(
				'A Tranco rank measures popularity of a domain, not the strength of its links or how it will rank in search. Tools that report "domain authority" use private scoring that this page does not reproduce.',
			) ),
			array( 'Most sites are not ranked', array(
				'Only one million domains appear in the list, so most sites, including many successful small businesses, are unranked. No rank does not mean a name is worthless.',
			) ),
		),
		'faqs'     => array(
			array( 'How do I check my website\'s ranking?', 'Enter the domain above. If it is in the top million sites it shows the position and the date of the list.' ),
			array( 'Is this the same as Google ranking?', 'No. It measures how popular the domain is overall, not where pages appear for a search.' ),
			array( 'What is a good Tranco rank?', 'Any rank means the domain is among the most visited. The lower the number, the more popular.' ),
			array( 'Why is my site not ranked?', 'Only the top one million domains are listed. Most sites fall outside it.' ),
		),
		'related'  => array( 'website-history-checker', 'domain-age-checker', 'domain-name-analyzer' ),
	),

	'domain-name-analyzer' => array(
		'name'     => 'Domain Name Analyzer',
		'title'    => 'Domain Name Analyzer: Length, Type and Price',
		'meta'     => 'Analyze a domain name: length, extension, letters or numbers, hyphens and how similar names are priced at Domain Brothers.',
		'kw'       => 'domain name analyzer',
		'lead'     => 'Get the facts about a name: its length, type, hyphens, digits and how it reads, with asking prices of similar names for sale.',
		'parts'    => array( 'comparables' ),
		'show'     => array( 'The name itself', 'Similar names for sale now (asking prices)', 'Comparable sales at Domain Brothers' ),
		'button'   => 'Analyze name',
		'steps'    => array(
			array( 'Enter a name', 'Type the domain you are considering.' ),
			array( 'Read the facts', 'Length, extension, letters or numbers, hyphens and digits.' ),
			array( 'Compare prices', 'See the asking prices of similar names we have for sale.' ),
		),
		'sections' => array(
			array( 'What makes a name easy to use', array(
				'Short names are easier to type and remember. Names with no hyphens or digits are easier to say aloud and to spell in an email. A name that can be read as a word, or two simple words, is easier to recall than a string of consonants. The tool reports each of these facts so you can compare options.',
			) ),
			array( 'Why length and extension drive price', array(
				'Across the domain market, shorter names and the .com extension tend to command higher prices because fewer exist. The similar-names card shows what names of the same length and extension are listed for at Domain Brothers, so you can see where a name sits.',
			) ),
			array( 'This is information, not an appraisal', array(
				'The tool does not value a name. What a domain sells for depends on the buyer, the seller and the moment. Use these facts as a starting point, then browse our listings or ask a broker.',
			) ),
		),
		'faqs'     => array(
			array( 'What makes a good domain name?', 'Short, easy to spell, easy to say, with no hyphens or numbers, and a .com or a local extension your customers recognise.' ),
			array( 'Does this tool tell me what a domain is worth?', 'No. It shows facts about the name and asking prices of similar names listed here. It is not an appraisal.' ),
			array( 'Why are some names marked hard to read aloud?', 'The check flags names with long runs of consonants or no vowels, which are harder to pronounce and remember.' ),
			array( 'Where can I find names of a certain length?', 'Browse our 3, 4, 5 and 6-character domain lists, or all domains.' ),
		),
		'related'  => array( 'domain-extension-checker', 'domain-age-checker', 'website-rank-checker' ),
	),

);
