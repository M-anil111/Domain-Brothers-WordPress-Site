<?php
/**
 * Service pages in the design of /website-design-development/.
 *
 * That page is the template: a light hero with an illustration, a feature grid,
 * a four-step process, an accordion of questions and a closing call to action.
 * This file renders the same markup (#dbs-page, assets/service-template.css) for
 * the other service pages from the copy below. Each page keeps its own content,
 * the guide section the focus block adds, and the enquiry form at the end:
 *
 *  - the focus block's output buffer places the guide above `<section class="dbsv-form"`,
 *    so the form section is appended last;
 *  - questions are real <button> accordions with aria-expanded.
 *
 * Domain transfer is a working form page with its own markup; it gets the same
 * look by CSS only (see db_svt_transfer_css).
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_svt_pages' ) ) {
	/**
	 * Copy and settings for each page, keyed by slug.
	 *
	 * Each row: eyebrow, h1, lead, cta (label), icon, chips (2 x [icon,label]), interest
	 * (the form's pre-selected service), features (5 x [icon,title,text]), steps (4 x [icon,title,text]),
	 * faqs (n x [question,answer]), faq_title, faq_intro, close_title, close_text, facts (strip items).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	function db_svt_pages() {
		return array(
			'domain-brokerage-services'            => array(
				'eyebrow' => 'Domain Brokerage',
				'h1'      => 'The domain you want, negotiated for you.',
				'lead'    => 'A domain broker finds the owner of the name you want, sets a fair price from comparable sales and negotiates for you in confidence.',
				'icon'    => 'handshake',
				'chips'   => array( array( 'search', 'Owner found' ), array( 'chat', 'Offer accepted' ) ),
				'caption' => 'Find. Negotiate. Transfer.',
				'interest' => 'Domain brokerage',
				'facts'   => array( '27+ years in domains', '5,000+ names sold', 'Confidential approach' ),
				'feat_title' => 'Everything a domain deal needs.',
				'features' => array(
					array( 'search', 'Domain search and outreach', 'We research who owns the name, approach them for you and keep your identity private until you choose to reveal it.' ),
					array( 'chat', 'Confidential negotiation', 'Your budget stays between us. We set a realistic price from comparable sales and negotiate to reach it.' ),
					array( 'lock', 'Secure transactions', 'Payment and transfer run through a clear, documented process, so neither side is exposed to risk.' ),
					array( 'doc', 'Contracts and legal support', 'From the purchase agreement to the transfer paperwork, we handle the details a domain sale or acquisition involves.' ),
					array( 'users', 'After-sale support', 'Once the name is yours we stay available to help you point it, set it up and get the most from it.' ),
				),
				'steps_title' => 'A clear process. From first message to ownership.',
				'steps'   => array(
					array( 'search', 'Tell us the name', 'Share the domain and the most you would pay.' ),
					array( 'users', 'We find the owner', 'We research and approach them for you.' ),
					array( 'chat', 'We negotiate', 'You approve the price before anything is agreed.' ),
					array( 'check', 'Secure transfer', 'Payment and transfer are completed safely.' ),
				),
				'faq_title' => 'Your brokerage questions, answered.',
				'faq_intro' => 'Quick answers about how domain brokerage works with Domain Brothers.',
				'faqs'    => array(
					array( 'How do you find the owner of a domain?', 'We research the registration history, the website and public contact routes, then approach the owner on your behalf. You do not need to find them yourself, and your name is not shared unless you agree.' ),
					array( 'What does brokerage cost?', 'Fees depend on the name and on how complex the deal is. Tell us the domain and we explain the fee before you commit to anything.' ),
					array( 'How long does a deal take?', 'It depends on how quickly the owner responds. Some names close within days; others take weeks. We keep you updated at every stage.' ),
					array( 'Is my information safe during the deal?', 'Yes. Your identity and budget stay confidential, and payment runs through the documented process described in our payment terms.' ),
					array( 'What if the owner will not sell?', 'We tell you straight away and suggest comparable names from our own listings or elsewhere, so the search does not stop there.' ),
					array( 'Can you handle international deals?', 'Yes. We work with owners and buyers in other countries and handle the transfer between registrars.' ),
				),
				'close_title' => 'Let us find your domain.',
				'close_text'  => 'Tell us the name and your budget. A broker replies within one business day.',
			),
			'domain-appraisal-services'            => array(
				'eyebrow' => 'Domain Appraisal',
				'h1'      => 'Know what a domain is worth before you decide.',
				'lead'    => 'A written price range for a name, based on comparable sales, before you buy, sell or negotiate.',
				'icon'    => 'chart',
				'chips'   => array( array( 'trend', 'Comparable sales' ), array( 'doc', 'Written report' ) ),
				'caption' => 'Evidence. Range. Report.',
				'interest' => 'Domain appraisal',
				'facts'   => array( '27+ years in domains', '5,000+ names sold', 'Confidential reports' ),
				'feat_title' => 'A value you can explain to anyone.',
				'features' => array(
					array( 'doc', 'Written report', 'A breakdown of the value by the factors that matter: length, extension, keywords, search demand and comparable sales.' ),
					array( 'trend', 'Market trends', 'An overview of the trends that could move the price of a name like yours up or down.' ),
					array( 'chat', 'Consultation', 'Talk through the appraisal with a person who can explain the reasoning behind each number.' ),
					array( 'clock', 'Fast turnaround', 'Need a figure before a call or an offer? Tell us the deadline when you ask.' ),
					array( 'users', 'Portfolios', 'Several names to value? Ask about appraising a portfolio in one request.' ),
				),
				'steps_title' => 'A clear process. From the name to the number.',
				'steps'   => array(
					array( 'search', 'Send the name', 'Tell us the domain and why you need a value.' ),
					array( 'chart', 'We research', 'Comparable sales, keywords, search demand and market trends.' ),
					array( 'doc', 'You get a report', 'A written range with the reasoning behind it.' ),
					array( 'chat', 'We talk it through', 'Go over the figures and decide your next step.' ),
				),
				'faq_title' => 'Your appraisal questions, answered.',
				'faq_intro' => 'Quick answers about domain appraisal with Domain Brothers.',
				'faqs'    => array(
					array( 'What do you look at in an appraisal?', 'Length, extension, keywords, search relevance, historical sales of similar names and current market demand.' ),
					array( 'How long does an appraisal take?', 'Tell us your deadline when you ask. We confirm the turnaround before we start.' ),
					array( 'Is the appraisal confidential?', 'Yes. Your request and the report are kept confidential.' ),
					array( 'Is an appraisal a guaranteed price?', 'No. An appraisal is an informed estimate. What a name sells for depends on the buyer, the seller and the moment.' ),
					array( 'How is this different from a free online valuation?', 'Automated tools use a formula. Ours is written by a person who reads the comparable sales and explains the range.' ),
					array( 'Can you appraise a whole portfolio?', 'Yes. Send the list and we quote for the group.' ),
				),
				'close_title' => 'Put a number on your domain.',
				'close_text'  => 'Send us the name and we reply within one business day.',
			),
			'domain-management-services'           => array(
				'eyebrow' => 'Domain Management',
				'h1'      => 'Your domains, renewed, secure and pointing the right way.',
				'lead'    => 'Domain management keeps your names renewed, secure and pointing where they should, so a lapsed registration never takes your website or email offline.',
				'icon'    => 'shield',
				'chips'   => array( array( 'clock', 'Auto-renewed' ), array( 'lock', 'Transfer locked' ) ),
				'caption' => 'Renewed. Protected. Organised.',
				'interest' => 'Domain management',
				'facts'   => array( '27+ years in domains', 'One name or hundreds', 'Plain-language support' ),
				'feat_title' => 'Domains handled, so you can get on with business.',
				'features' => array(
					array( 'clock', 'Renewals', 'We track every renewal date so an expired name never takes your website or email offline.' ),
					array( 'globe', 'DNS management', 'From a simple record change to advanced configuration, your names point where they should.' ),
					array( 'lock', 'Privacy and security', 'Transfer locks and privacy settings help keep your details off public records and your names in your hands.' ),
					array( 'server', 'Portfolio management', 'Many names, one view: renewal dates, settings and registrars organised in a single place.' ),
					array( 'users', 'Help from a person', 'Ask a question about a registrar, a record or a renewal and get a plain answer.' ),
				),
				'steps_title' => 'A clear process. From scattered names to one plan.',
				'steps'   => array(
					array( 'search', 'Tell us what you own', 'List your names and where they are registered.' ),
					array( 'doc', 'We review', 'Renewal dates, DNS, locks and privacy settings.' ),
					array( 'check', 'We agree a plan', 'What we manage, what stays with you, and the cost.' ),
					array( 'clock', 'We keep it running', 'Renewals and changes handled as they come up.' ),
				),
				'faq_title' => 'Your domain management questions, answered.',
				'faq_intro' => 'Quick answers about managing domains with Domain Brothers.',
				'faqs'    => array(
					array( 'What is domain management?', 'It is the ongoing work of keeping a domain registered, renewed, secured and correctly configured.' ),
					array( 'Can you manage names that are registered elsewhere?', 'Yes. We can work with your current registrar, or help move the names if that is simpler. See our domain transfer page for how a move works.' ),
					array( 'Do I need domain privacy?', 'Privacy hides your personal contact details from the public registration record. We recommend it for most owners.' ),
					array( 'Can renewals happen automatically?', 'Yes. Automatic renewal is the usual setup, and we check the payment method ahead of each renewal.' ),
					array( 'Can I manage several domains together?', 'Yes. A portfolio is managed as one plan with one set of dates.' ),
					array( 'How is my information protected?', 'We limit access, use transfer locks and follow the security practices described in our privacy policy.' ),
				),
				'close_title' => 'Never lose a domain to a missed renewal.',
				'close_text'  => 'Tell us what you own and we reply within one business day.',
			),
			'digital-marketing-services'           => array(
				'eyebrow' => 'Digital Marketing',
				'h1'      => 'Marketing scoped in writing, with a fixed quote.',
				'lead'    => 'Digital marketing services scoped in writing with a fixed quote, so you know what a provider will do, for how long and at what cost.',
				'icon'    => 'horn',
				'chips'   => array( array( 'trend', 'Qualified leads' ), array( 'search', 'Search visibility' ) ),
				'caption' => 'Visible. Measured. Accountable.',
				'interest' => 'Digital marketing',
				'partners' => true,
				'feat_title' => 'Channels chosen for your goal.',
				'features' => array(
					array( 'search', 'SEO', 'Technical fixes, keyword research and content that help the right customers find your site in search.' ),
					array( 'doc', 'Content marketing', 'Articles, guides and pages written for your audience and built to earn search traffic.' ),
					array( 'users', 'Social media marketing', 'Plans and posting that grow your reach and engagement on the networks your customers use.' ),
					array( 'horn', 'Pay-per-click and email', 'Paid campaigns for immediate traffic and email programmes that nurture and convert leads.' ),
					array( 'chart', 'Analytics and reporting', 'Clear tracking and regular reports, so you see what each channel delivers.' ),
				),
				'steps_title' => 'A clear process. From first call to measured results.',
				'steps'   => array(
					array( 'chat', 'Discovery call', 'Understand goals, audience and budget.' ),
					array( 'doc', 'Fixed-scope proposal', 'Agree on channels, deliverables and price.' ),
					array( 'horn', 'Launch and optimise', 'Campaigns go live and are tuned on real data.' ),
					array( 'chart', 'Report', 'See traffic, leads and cost against the plan.' ),
				),
				'faq_title' => 'Your marketing questions, answered.',
				'faq_intro' => 'Quick answers about digital marketing with Domain Brothers.',
				'faqs'    => array(
					array( 'How do you measure success?', 'Against the goals we agree at the start: traffic, leads and conversions, reported regularly.' ),
					array( 'Do you work with small businesses?', 'Yes. The scope and the quote are set to fit your size and budget.' ),
					array( 'How often will I get reports?', 'We agree the schedule with you. Monthly is common.' ),
					array( 'Is there a minimum contract?', 'Terms are agreed per project in the written proposal, so you know the commitment before you sign.' ),
					array( 'Can you manage my social media accounts?', 'Yes. We can plan, publish and report on your accounts.' ),
					array( 'Do you create the content too?', 'Yes. We write and design content for the channels we run.' ),
				),
				'close_title' => 'Let us plan your next campaign.',
				'close_text'  => 'Tell us your goal and budget. You get a written plan and a fixed quote.',
			),
			'custom-software-development-services' => array(
				'eyebrow' => 'Software Development',
				'h1'      => 'Custom software, built to a written scope.',
				'lead'    => 'Custom software development built to a written scope and fixed quote, with progress shown every week and the code handed over to you.',
				'icon'    => 'code',
				'chips'   => array( array( 'check', 'Weekly demos' ), array( 'lock', 'You own the code' ) ),
				'caption' => 'Scope. Build. Hand over.',
				'interest' => 'Software development',
				'partners' => true,
				'feat_title' => 'Built around how your business runs.',
				'features' => array(
					array( 'code', 'Custom applications', 'Software shaped to your processes, not the other way round.' ),
					array( 'phone', 'Mobile apps', 'Functional, well-designed apps for iOS and Android.' ),
					array( 'server', 'Enterprise systems', 'Tools that streamline internal processes and connect the systems you already use.' ),
					array( 'tag', 'E-commerce platforms', 'Scalable online stores and marketplaces built to convert.' ),
					array( 'shield', 'Maintenance and support', 'Ongoing support to keep your software running smoothly after launch.' ),
				),
				'steps_title' => 'A clear process. From idea to working software.',
				'steps'   => array(
					array( 'chat', 'Discovery call', 'Understand the problem, users and budget.' ),
					array( 'doc', 'Fixed-scope proposal', 'Agree on scope, milestones and price.' ),
					array( 'code', 'Build in sprints', 'See working software every week.' ),
					array( 'check', 'Launch and support', 'Test, release and hand over the code.' ),
				),
				'faq_title' => 'Your software questions, answered.',
				'faq_intro' => 'Quick answers about software development with Domain Brothers.',
				'faqs'    => array(
					array( 'How do you manage projects?', 'In short iterations with a working demo every week, so you can steer as we go.' ),
					array( 'What technologies do you use?', 'We choose the stack to suit the project; our work covers JavaScript, Python and a range of database technologies.' ),
					array( 'Who owns the code?', 'You do. The code is handed over to you as set out in the written proposal.' ),
					array( 'Do you offer support after launch?', 'Yes. Maintenance and support can be agreed as part of the project or afterwards.' ),
					array( 'How do you protect my data during development?', 'We limit access to what the project needs and follow standard security practice.' ),
					array( 'How do you check quality?', 'Testing runs throughout the build, not just at the end, and each release is reviewed with you.' ),
				),
				'close_title' => 'Let us scope your project.',
				'close_text'  => 'Describe the idea. You get a written scope and a fixed quote.',
			),
			'mobile-app-development-services'      => array(
				'eyebrow' => 'Mobile App Development',
				'h1'      => 'Mobile apps for iOS and Android, scoped before any code.',
				'lead'    => 'Mobile app development for iOS and Android, scoped in writing with milestones and a fixed quote before any design or code begins.',
				'icon'    => 'phone',
				'chips'   => array( array( 'check', 'iOS and Android' ), array( 'spark', 'Store ready' ) ),
				'caption' => 'Design. Build. Publish.',
				'interest' => 'Mobile app development',
				'partners' => true,
				'feat_title' => 'Apps people keep on their phone.',
				'features' => array(
					array( 'phone', 'Native apps', 'Built for the best performance on iOS and on Android.' ),
					array( 'monitor', 'Cross-platform apps', 'One codebase for both platforms when speed to market matters most.' ),
					array( 'spark', 'App redesign', 'Refresh an existing app so it is faster, clearer and easier to use.' ),
					array( 'users', 'User experience design', 'Flows and screens designed around how your customers actually use the app.' ),
					array( 'shield', 'Maintenance and support', 'Updates for new devices and operating systems, and fixes when they are needed.' ),
				),
				'steps_title' => 'A clear process. From concept to the app stores.',
				'steps'   => array(
					array( 'chat', 'Discovery call', 'Goals, users, platforms and budget.' ),
					array( 'doc', 'Fixed-scope proposal', 'Features, milestones and price in writing.' ),
					array( 'phone', 'Design and build', 'Review designs and test builds as they arrive.' ),
					array( 'check', 'Publish and support', 'Submit to the stores and support the launch.' ),
				),
				'faq_title' => 'Your mobile app questions, answered.',
				'faq_intro' => 'Quick answers about mobile app development with Domain Brothers.',
				'faqs'    => array(
					array( 'Native or cross-platform: which should I choose?', 'Native apps give the best performance and feel; cross-platform apps reach both stores from one codebase. We recommend one after the discovery call.' ),
					array( 'Do you build for both iOS and Android?', 'Yes. We build for either platform or both.' ),
					array( 'Can you improve an app I already have?', 'Yes. We review it and propose a redesign or fixes with a written quote.' ),
					array( 'Who publishes the app to the stores?', 'We prepare and submit it. The app listing is created under your developer account so you keep control.' ),
					array( 'Do you support the app after launch?', 'Yes. Maintenance and updates can be agreed in the proposal or later.' ),
					array( 'How do I know what it will cost?', 'The proposal sets out features, milestones and a fixed quote before any design or code begins.' ),
				),
				'close_title' => 'Let us scope your app.',
				'close_text'  => 'Describe the idea and the users. You get a written scope and a fixed quote.',
			),
			'web-hosting-maintenance-services'     => array(
				'eyebrow' => 'Hosting and Maintenance',
				'h1'      => 'Hosting and maintenance that keeps your site fast and safe.',
				'lead'    => 'Managed hosting, updates, backups and monitoring, so your website stays online, secure and fast without you managing a server.',
				'icon'    => 'server',
				'chips'   => array( array( 'check', 'Monitored' ), array( 'shield', 'Backed up' ) ),
				'caption' => 'Online. Patched. Backed up.',
				'interest' => 'Web hosting and maintenance',
				'partners' => true,
				'feat_title' => 'Looked after, so you do not have to.',
				'features' => array(
					array( 'server', 'Managed hosting', 'Reliable hosting set up for your site and your traffic.' ),
					array( 'shield', 'Updates and security', 'Core, plugin and theme updates applied and checked, with security monitoring.' ),
					array( 'doc', 'Backups', 'Regular backups so a problem is a restore, not a rebuild.' ),
					array( 'chart', 'Speed and monitoring', 'Uptime monitoring and performance checks to catch problems early.' ),
					array( 'users', 'Support from a person', 'Ask for a change or report a problem and get a reply from someone who knows your site.' ),
				),
				'steps_title' => 'A clear process. From today\'s site to a looked-after one.',
				'steps'   => array(
					array( 'search', 'Site review', 'We look at your site, host and current risks.' ),
					array( 'doc', 'Written plan', 'What we maintain, how often and the price.' ),
					array( 'server', 'Move and set up', 'Hosting, backups and monitoring switched on.' ),
					array( 'check', 'Ongoing care', 'Updates, checks and support every month.' ),
				),
				'faq_title' => 'Your hosting questions, answered.',
				'faq_intro' => 'Quick answers about hosting and maintenance with Domain Brothers.',
				'faqs'    => array(
					array( 'Do you host sites you did not build?', 'Yes. We review the site first and tell you what it needs.' ),
					array( 'What is included in maintenance?', 'Updates, security checks, backups, monitoring and support, as set out in the written plan.' ),
					array( 'How often are backups taken?', 'We agree the schedule in the plan, based on how often your site changes.' ),
					array( 'What happens if my site goes down?', 'Monitoring flags it, and we investigate and restore it as the plan describes.' ),
					array( 'Can you move my site without downtime?', 'In most cases yes. We prepare the new host and switch over once it is tested.' ),
					array( 'Do I keep control of my domain and content?', 'Yes. Your domain, content and accounts stay yours.' ),
				),
				'close_title' => 'Give your site a safe home.',
				'close_text'  => 'Tell us about your site. You get a written plan and a fixed price.',
			),
		);
	}
}

if ( ! function_exists( 'db_svt_icon' ) ) {
	/** Line icon from the shared service-icon set. */
	function db_svt_icon( $key, $size = 24, $stroke = '1.6' ) {
		$paths = function_exists( 'db_svc_icon_paths' ) ? db_svc_icon_paths() : array();
		$d     = isset( $paths[ $key ] ) ? $paths[ $key ] : ( $paths['check'] ?? '' );
		return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="none" stroke="currentColor" stroke-width="' . esc_attr( $stroke ) . '" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
	}
}

if ( ! function_exists( 'db_svt_art' ) ) {
	/** Hero illustration in the template palette: a large icon on layered discs with two status chips. */
	function db_svt_art( array $c, $alt ) {
		$chip = function ( $x, $y, array $item ) {
			$w = 64 + (int) round( strlen( $item[1] ) * 8.2 );
			return '<g transform="translate(' . (int) $x . ' ' . (int) $y . ')"><rect width="' . $w . '" height="52" rx="26" fill="#fff" stroke="#d3e8a0" stroke-width="2"/><circle cx="26" cy="26" r="15" fill="#eef6d6"/>'
				. '<g transform="translate(14 14) scale(1)" fill="none" stroke="#7fa60f" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . ( function_exists( 'db_svc_icon_paths' ) ? ( db_svc_icon_paths()[ $item[0] ] ?? '' ) : '' ) . '</g>'
				. '<text x="50" y="32" font-family="Inter, system-ui, sans-serif" font-size="15" font-weight="700" fill="#0b1a33">' . esc_html( $item[1] ) . '</text></g>';
		};
		$paths = function_exists( 'db_svc_icon_paths' ) ? db_svc_icon_paths() : array();
		$main  = $paths[ $c['icon'] ] ?? ( $paths['check'] ?? '' );
		return '<svg class="dbs-art" viewBox="0 0 440 360" role="img" aria-label="' . esc_attr( $alt ) . '"><defs><linearGradient id="dbs-g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#13254a"/><stop offset="1" stop-color="#0b1a33"/></linearGradient></defs>'
			. '<circle cx="220" cy="180" r="160" fill="#eef6d6"/><circle cx="220" cy="180" r="116" fill="#e6f3c3"/><circle cx="220" cy="180" r="78" fill="url(#dbs-g)"/>'
			. '<g transform="translate(176 136) scale(3.7)" fill="none" stroke="#a6cf2b" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . $main . '</g>'
			. '<circle cx="356" cy="76" r="9" fill="#a6cf2b"/><circle cx="84" cy="300" r="6" fill="#0b1a33"/><circle cx="380" cy="282" r="5" fill="#7fa60f"/>'
			. $chip( 18, 54, $c['chips'][0] ) . $chip( 222, 286, $c['chips'][1] ) . '</svg>';
	}
}

if ( ! function_exists( 'db_svt_render' ) ) {
	/**
	 * The page markup (everything inside the page, without the enquiry form).
	 *
	 * @param string               $slug Page slug.
	 * @param string               $name Page title for breadcrumbs.
	 * @param array<string,mixed>  $c    Row from db_svt_pages().
	 * @return string
	 */
	function db_svt_render( $slug, $name, array $c ) {
		$arrow = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
		$h     = '<div class="dbs" id="dbs-page">';
		$h    .= '<section class="dbs-hero dbs-full" aria-labelledby="dbs-hero-title"><div class="dbs-wrap"><div class="dbs-hero-copy">'
			. '<nav class="dbs-bc" aria-label="Breadcrumb"><ol><li><a href="' . esc_url( home_url( '/' ) ) . '">Home</a></li><li><a href="' . esc_url( home_url( '/our-services/' ) ) . '">Services</a></li><li><span aria-current="page">' . esc_html( $name ) . '</span></li></ol></nav>'
			. '<span class="dbs-eyebrow">' . esc_html( $c['eyebrow'] ) . '</span><h1 id="dbs-hero-title">' . esc_html( $c['h1'] ) . '</h1><p class="dbs-lead">' . esc_html( $c['lead'] ) . '</p>'
			. '<div class="dbs-actions"><a class="dbs-btn" href="#get-started">Get a free consultation</a><a class="dbs-link-arrow" href="' . esc_url( home_url( '/all-domains/' ) ) . '">Browse domains ' . $arrow . '</a></div></div>'
			. '<figure class="dbs-hero-media"><div class="dbs-hero-art">' . db_svt_art( $c, $name )
			. '<svg class="dbs-spark" viewBox="0 0 60 60" fill="none" stroke="currentColor" stroke-width="5.5" stroke-linecap="round" aria-hidden="true"><path d="M12 6 7 22M27 26l15-11M30 44l22-2"/></svg></div><figcaption>' . esc_html( $c['caption'] ) . '</figcaption></figure></div></section>';

		if ( ! empty( $c['partners'] ) ) {
			$h .= '<section class="dbs-partners dbs-full" aria-label="Partner network"><div class="dbs-wrap"><p>Delivered through our partner network</p><ul><li>Mindshare Consulting Inc.</li><li>Jay Mehta Digital</li><li>Netclues</li></ul></div></section>';
		} elseif ( ! empty( $c['facts'] ) ) {
			$h .= '<section class="dbs-partners dbs-full" aria-label="Why Domain Brothers"><div class="dbs-wrap"><p>Domain Brothers</p><ul>';
			foreach ( $c['facts'] as $f ) {
				$h .= '<li>' . esc_html( $f ) . '</li>';
			}
			$h .= '</ul></div></section>';
		}

		$h .= '<section class="dbs-feat dbs-full" aria-labelledby="dbs-feat-title"><div class="dbs-wrap"><h2 class="dbs-h2c" id="dbs-feat-title">' . esc_html( $c['feat_title'] ) . '</h2><div class="dbs-fgrid">';
		$last = count( $c['features'] ) - 1;
		foreach ( $c['features'] as $i => $f ) {
			$h .= '<article class="dbs-fcard' . ( $i === $last && 0 === count( $c['features'] ) % 2 ? '' : '' ) . ( $i === $last && 1 === count( $c['features'] ) % 2 ? ' is-wide' : '' ) . '"><span class="dbs-fico">' . db_svt_icon( $f[0] ) . '</span><div><h3>' . esc_html( $f[1] ) . '</h3><p>' . esc_html( $f[2] ) . '</p></div></article>';
		}
		$h .= '</div></div></section>';

		$h .= '<section class="dbs-process dbs-full" aria-labelledby="dbs-process-title"><div class="dbs-wrap"><h2 id="dbs-process-title">' . esc_html( $c['steps_title'] ) . '</h2><ol class="dbs-steps">';
		foreach ( $c['steps'] as $i => $s ) {
			$h .= '<li class="dbs-step"><div class="dbs-step-top"><span class="dbs-num">' . sprintf( '%02d', $i + 1 ) . '</span>' . db_svt_icon( $s[0], 28, '1.7' ) . '</div><h3>' . esc_html( $s[1] ) . '</h3><p>' . esc_html( $s[2] ) . '</p></li>';
		}
		$h .= '</ol></div></section>';

		$h .= '<section class="dbs-faq dbs-full" aria-labelledby="dbs-faq-title"><div class="dbs-wrap"><div class="dbs-faq-intro"><h2 id="dbs-faq-title">' . esc_html( $c['faq_title'] ) . '</h2><p>' . esc_html( $c['faq_intro'] ) . '</p></div><div class="dbs-acc" data-dbs-acc>';
		foreach ( $c['faqs'] as $i => $qa ) {
			$n     = $i + 1;
			$open  = 0 === $i;
			$h    .= '<div class="dbs-qa' . ( $open ? ' is-open' : '' ) . '"><h3 class="dbs-q"><button type="button" id="dbs-q' . $n . '" aria-expanded="' . ( $open ? 'true' : 'false' ) . '" aria-controls="dbs-a' . $n . '"><span>' . esc_html( $qa[0] ) . '</span><span class="dbs-pm" aria-hidden="true"></span></button></h3>'
				. '<div class="dbs-ans" id="dbs-a' . $n . '" role="region" aria-labelledby="dbs-q' . $n . '"><div class="dbs-ans-clip"><div class="dbs-ans-in">' . esc_html( $qa[1] ) . '</div></div></div></div>';
		}
		$h .= '</div></div></section>';

		$h .= '<section class="dbs-cta-sec dbs-full" aria-labelledby="dbs-cta-title"><div class="dbs-wrap"><div class="dbs-cta"><div><h2 id="dbs-cta-title">' . esc_html( $c['close_title'] ) . '</h2><p>' . esc_html( $c['close_text'] ) . '</p></div><a class="dbs-btn" href="#get-started">Get a free consultation</a></div></div></section></div>';

		$h .= '<script data-no-optimize="1" data-no-defer="1" data-cfasync="false" data-no-delay>(function(){var a=document.querySelector("[data-dbs-acc]");if(!a)return;a.classList.add("is-js");a.addEventListener("click",function(e){var b=e.target.closest(".dbs-q button");if(!b||!a.contains(b))return;e.preventDefault();var it=b.closest(".dbs-qa"),open=!it.classList.contains("is-open");a.querySelectorAll(".dbs-qa.is-open").forEach(function(o){if(o!==it){o.classList.remove("is-open");o.querySelector(".dbs-q button").setAttribute("aria-expanded","false");}});it.classList.toggle("is-open",open);b.setAttribute("aria-expanded",open?"true":"false");});})();</script>';
		return $h;
	}
}

if ( ! function_exists( 'db_svt_current' ) ) {
	/** Row for the current page, or null. */
	function db_svt_current() {
		if ( is_admin() || ! is_page() ) {
			return null;
		}
		$pages = db_svt_pages();
		$post  = get_queried_object();
		return ( $post instanceof WP_Post && isset( $pages[ $post->post_name ] ) ) ? array( $post, $pages[ $post->post_name ] ) : null;
	}
}

// Replaces the service layout (priority 20/25) with the template page, then the enquiry form last.
add_filter( 'the_content', function ( $html ) {
	if ( ! in_the_loop() || ! is_main_query() ) {
		return $html;
	}
	$cur = db_svt_current();
	if ( null === $cur ) {
		return $html;
	}
	list( $post, $c ) = $cur;
	$def = function_exists( 'db_focus_current' ) ? db_focus_current() : null;
	if ( is_array( $def ) && ! empty( $def['lede'] ) ) {
		$c['lead'] = (string) $def['lede'];
	}
	$form = function_exists( 'db_svc_form_section' ) ? db_svc_form_section( $c['interest'] ) : '';
	return db_svt_render( $post->post_name, get_the_title( $post ), $c ) . ( '' === $form ? '' : '<div class="dbp dbsv-built">' . $form . '</div>' );
}, 26 );

add_filter( 'body_class', function ( $classes ) {
	if ( null !== db_svt_current() ) {
		$classes[] = 'dbs-tpl';
	}
	return $classes;
} );

add_action( 'wp_enqueue_scripts', function () {
	$on = null !== db_svt_current() || is_page( 'domain-transfer' );
	if ( ! $on ) {
		return;
	}
	wp_enqueue_style( 'db-svt-fonts', 'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
	$file = __DIR__ . '/assets/service-template.css';
	$css  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( null !== db_svt_current() && '' !== $css ) {
		$css .= '#dbs-page .dbs-hero-art .dbs-art{position:relative;z-index:1;display:block;width:100%;height:auto;aspect-ratio:440/360}'
			. 'body.dbs-tpl .dbsv-built{max-width:1240px;margin:0 auto;padding:0 32px}';
	}
	if ( is_page( 'domain-transfer' ) ) {
		$css = db_svt_transfer_css();
	}
	if ( '' !== $css ) {
		wp_register_style( 'db-svt', false, array(), DB_BLOCKS_LOADED );
		wp_enqueue_style( 'db-svt' );
		wp_add_inline_style( 'db-svt', $css );
	}
}, 115 );

if ( ! function_exists( 'db_svt_transfer_css' ) ) {
	/** The transfer page keeps its working form; this gives its hero, steps and cards the template's look. */
	function db_svt_transfer_css() {
		return '.dbx-tr{--navy:#0b1a33;--lime:#a6cf2b;--lime-d:#7fa60f;--lime-t:#eef6d6;--line:#e1e5eb}'
			. 'html body .dbx-tr :is(h1,h2,h3){font-family:"Plus Jakarta Sans",Inter,ui-sans-serif,system-ui,sans-serif;color:var(--navy);letter-spacing:-.025em;font-weight:800;text-wrap:balance}'
			. 'html body .dbx-tr :is(p,li,a,span,label,strong){font-family:Inter,ui-sans-serif,system-ui,sans-serif}'
			. '.dbx-tr .dbx-tr-hero{position:relative;width:100vw;left:50%;margin-left:-50vw;padding:clamp(32px,5vw,64px) max(32px,calc(50vw - 620px));background:radial-gradient(110% 90% at 80% 35%,#fff 0,rgba(255,255,255,0) 60%),linear-gradient(180deg,#f8f9fa 0,#f1f3f5 100%)}'
			. '.dbx-tr .dbx-tr-hero .dbp-eyebrow{display:inline-block;margin:0 0 14px;padding:6px 12px;border-radius:999px;background:var(--lime-t);color:#4f6f0f;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}'
			. 'html body .dbx-tr .dbx-tr-hero{border-radius:0!important;border:0!important}html body .dbx-tr .dbx-tr-hero :is(h1,.dbx-tr-h1){color:#0b1a33!important}html body .dbx-tr .dbx-tr-hero :is(p,.dbx-lede,.dbx-tr-lead){color:#4a5568!important}html body .dbx-tr .dbx-tr-hero .dbx-tr-trust li{color:#0b1a33!important}html body .dbx-tr .dbx-tr-hero .dbp-eyebrow{background:#eef6d6!important;color:#4f6f0f!important}'
			. '.dbx-tr .dbx-tr-h1{font-size:clamp(36px,4.6vw,60px);line-height:1.05;margin:0 0 16px}'
			. '.dbx-tr .dbx-tr-lead,.dbx-tr .dbx-lede{max-width:62ch;font-size:clamp(16px,1.5vw,19px);line-height:1.6;color:#4a5568}'
			. '.dbx-tr .dbx-tr-trust{display:flex;flex-wrap:wrap;gap:10px 22px;margin:20px 0 0;padding:0;list-style:none}'
			. '.dbx-tr .dbx-tr-trust li{font-size:14px;font-weight:600;color:var(--navy)}'
			. '.dbx-tr .dbx-tr-steps{counter-reset:s;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin:clamp(28px,4vw,48px) 0;padding:0;list-style:none}'
			. '.dbx-tr .dbx-tr-steps>li{padding:22px;border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 1px 0 rgba(11,26,51,.03)}'
			. '.dbx-tr .dbx-tr-steps strong{display:block;margin:6px 0 6px;font-size:18px;line-height:1.3;color:var(--navy)}'
			. '.dbx-tr .dbx-tr-steps span{font-size:15px;line-height:1.55;color:#4a5568}'
			. '@media (max-width:900px){.dbx-tr .dbx-tr-steps{grid-template-columns:repeat(2,minmax(0,1fr))}}'
			. '@media (max-width:560px){.dbx-tr .dbx-tr-steps{grid-template-columns:minmax(0,1fr)}}';
	}
}
