<?php
/**
 * Focus sections: additional copy appended to existing definitions, and
 * definitions for pages that only need a short section.
 *
 * 'append' entries add body blocks to the end of a definition from the other
 * focus files. 'defs' entries are complete definitions.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'append' => array(
		'all-domains'               => array(
			array( 'h3' => 'Questions to ask before you buy premium domains' ),
			'Most people who buy premium domains ask the same few questions. Is the name easy to say and spell? Does it suit the business for years, not just this launch? Is anyone else using a similar brand in the same market?',
			'When you buy premium domains here, each listing shows the price, the payment options and the name&rsquo;s history. If the price is open, send an offer and a broker replies within one business day.',
			'Card payments run through Stripe, in full or on a monthly plan of 3 to 12 months. Plans up to 6 months carry no interest; a 9-month plan adds 5% and a 12-month plan adds 10%.',
			'Teams that buy premium domains for a launch often shortlist three names and decide together. <a href="/find-a-domain/">The domain finder</a> and <a href="/collections/">curated collections</a> help you build that shortlist faster.',
		),
		'sold'                      => array(
			array( 'h3' => 'What recent domain sales do not tell you' ),
			'Recent domain sales show what a buyer paid, not what every similar name is worth. A sale can include factors you cannot see, such as a deadline, a trademark or a buyer who needed that exact word.',
			'Read recent domain sales as a range, not a price tag. If you want a written view on a specific name, <a href="/domain-appraisal-services/">a domain appraisal</a> compares it with recent domain sales and the names on sale today.',
		),
		'about-us'                  => array(
			array( 'h3' => 'What to expect from a domain name company' ),
			'A good domain name company answers quickly, writes prices down and handles the transfer to the end. Domain Brothers replies within one business day, and nothing is charged until you agree in writing.',
			'Choose a domain name company that can explain why a name is priced the way it is. Ask for comparable sales, payment options and how the transfer works before you commit. A domain name company that cannot answer those questions plainly is a risk.',
		),
		'faqs'                      => array(
			array( 'h3' => 'How to use this domain FAQ' ),
			'This domain FAQ is grouped by stage: choosing, paying and transferring. Start with the stage you are at. Each domain FAQ answer is short and links to the page with the detail.',
			'If this domain FAQ does not cover your question, <a href="/contact-us/">contact us</a>. A person replies within one business day, and useful questions are added to the domain FAQ for the next reader.',
			'The most common domain FAQ topics are payment plans, transfer times and offers. Payment plans run from 3 to 12 months; a transfer usually completes once the registrar releases the name; and an offer gets a reply within one business day.',
		),
		'site-map'                  => array(
			array( 'h3' => 'How this site map is organised' ),
			'This site map lists every public page in four groups: domains for sale, domain services, digital services and company pages. Use the site map when the menus do not show what you need.',
			'Search engines read the XML version of the site map; people read this one. Both list the same pages, so a page missing from this site map is a page we do not want indexed, such as checkout steps.',
			'If a link in this site map is broken or a page you expected is missing, <a href="/contact-us/">tell us</a> and we will fix the site map the same week.',
		),
		'epp-guidelines'            => array(
			array( 'h3' => 'Common EPP code problems' ),
			'Most failed transfers come down to the EPP code: it was copied with a space, it expired, or the registrar issued a new EPP code after the old one was sent. Request a fresh EPP code if a transfer fails, and paste it exactly.',
		),
		'3-character-domains'       => array(
			'Because supply is fixed, 3 letter domains rarely get cheaper. Prices on 3 letter domains move with demand from start-ups and established brands looking for a short, portable name.',
		),
		'4-character-domains'       => array(
			'Compare 4 letter domains for sale on pronunciation first, then on meaning. Two 4 letter domains for sale at the same price can differ widely in how easily a customer remembers them.',
		),
		'5-character-domains'       => array(
			array( 'h3' => 'Who buys 5 letter domains' ),
			'Start-ups buy 5 letter domains that read as a word or a coined brand. Established companies buy 5 letter domains to shorten a long name for campaigns and email. In both cases, the best 5 letter domains are easy to say aloud.',
		),
		'6-character-domains'       => array(
			array( 'h3' => 'Checking 6 letter domain names before you buy' ),
			'Say each of your shortlisted 6 letter domain names aloud to someone who has not seen it written. If they can spell it back, it passes the radio test. Many 6 letter domain names fail because of a double letter or an unusual ending.',
		),
		'our-team'                  => array(
			array( 'h3' => 'How to work with our domain experts' ),
			'Tell our domain experts what the name is for and what a good result looks like. The more context you give, the faster the domain experts can tell you what is realistic and what it costs.',
			'Our domain experts put research, prices and terms in writing before anything is agreed, so you always know where a deal stands.',
		),
		'contact-us'                => array(
			array( 'h3' => 'Other ways to contact Domain Brothers' ),
			'You can also contact Domain Brothers from any domain listing by sending an offer, or from your account page if you already have a purchase in progress. Every route reaches the same team, and you can contact Domain Brothers about any name, service or transfer.',
		),
		'our-services'              => array(
			array( 'h3' => 'Choosing between our domain name services' ),
			'Most clients need one of three domain name services: buying a name that is listed, acquiring a name someone else owns, or valuing and selling a name they hold. If you are not sure which of our domain name services fits, <a href="/contact-us/">describe the goal</a> and we will point you to the right one.',
			'All our domain name services follow the same rule: research and prices are written down before anyone is contacted, and nothing is charged until you agree in writing. Domain name services that skip those steps cost more in the end.',
		),
		'sell-social-media-handles' => array(
			array( 'h3' => 'Before you sell social media handles' ),
			'Read the platform rules before you sell social media handles, because several platforms prohibit selling accounts or usernames. When you sell social media handles through Domain Brothers, we only use routes the platform permits, such as releasing the name so the buyer can claim it.',
			'Never hand over a username before payment is confirmed. Most scams aimed at people who sell social media handles ask the seller to move first. We confirm the buyer and the payment before you change anything, which is the safest way to sell social media handles.',
		),
		'buy-domains-service'       => array(
			array( 'h3' => 'When a domain buy service is worth it' ),
			'A domain buy service is worth it when the name you want is registered but not listed for sale, or when the owner is hard to reach. A domain buy service keeps your identity private, which stops the asking price rising because a known brand is interested.',
			'If the name is already listed on this site, you do not need a domain buy service: open the listing and buy or send an offer directly from <a href="/all-domains/">all domains</a>.',
		),
	),
	'defs'   => array(
		'news' => array(
			'kw'          => 'domain industry news',
			'lede'        => 'Domain industry news from Domain Brothers: market moves, notable sales, registry changes and what they mean for buyers and sellers.',
			'h2'          => 'How to read domain industry news',
			'body'        => array(
				'Domain industry news moves fast, and most of it matters to only a few people. A registry price change matters if you hold names in that extension. A large sale matters as a signal of demand, not as a price for your name.',
				array( 'h3' => 'What to watch in domain industry news' ),
				array( 'Registry and ICANN policy changes that affect renewals or transfers', 'Notable sales in extensions and lengths you own or want', 'New top-level domains and how buyers are using them', 'Security issues such as phishing on look-alike names' ),
				'Use domain industry news to decide when to act, then check the detail. Before you buy or sell on the strength of a headline, compare it with <a href="/sold/">recent domain sales</a> and the <a href="/all-domains/">names on sale today</a>.',
			),
			'steps'       => 'buy',
			'steps_title' => 'From news to a decision, in four steps',
			'link'        => array( 'https://www.icann.org/', 'ICANN', 'For the official policy updates behind much domain industry news, see' ),
		),
	),
);
