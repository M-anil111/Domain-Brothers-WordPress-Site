<?php
/**
 * "Read next": the blog guides linked from each page's guide section, by page
 * slug ('_front' is the home page). Slugs are posts in the Domain Guides
 * category; a slug that is not published is skipped.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$buy     = array( 'how-to-buy-a-domain-name-for-a-website', 'how-to-buy-a-premium-domain-safely', 'premium-domain-payment-plans-and-financing' );
$short   = array( 'short-domain-names', 'brandable-domain-names', 'how-to-value-a-premium-domain-name' );
$handles = array( 'brandable-domain-names', 'domain-names-for-business', 'trademarks-and-domain-disputes-udrp-basics' );
$company = array( 'how-to-buy-a-domain-name-for-a-website', 'premium-domain-names-complete-guide', 'sell-my-domain' );
$build   = array( 'website-launch-checklist-after-buying-a-domain', 'domain-names-for-business', 'change-domain-name-without-losing-seo' );
$market  = array( 'domain-name-marketing-playbook', 'do-premium-domains-help-seo', 'website-launch-checklist-after-buying-a-domain' );
$legal   = array( 'premium-domain-payment-plans-and-financing', 'domain-escrow-explained', 'how-to-buy-a-premium-domain-safely' );
$move    = array( 'how-to-transfer-a-domain', 'how-to-buy-a-premium-domain-safely', 'change-domain-name-without-losing-seo' );

return array(
	'_front'                               => array( 'how-to-buy-a-domain-name-for-a-website', 'premium-domain-names-complete-guide', 'domain-names-for-business' ),
	'all-domains'                          => $buy,
	'3-character-domains'                  => $short,
	'4-character-domains'                  => $short,
	'5-character-domains'                  => $short,
	'6-character-domains'                  => array( 'brandable-domain-names', 'short-domain-names', 'how-to-value-a-premium-domain-name' ),
	'numeric-domains-for-sale'             => array( 'short-domain-names', 'how-to-value-a-premium-domain-name', 'domain-flipping' ),
	'premium-domain-deals'                 => array( 'how-to-value-a-premium-domain-name', 'how-to-buy-a-premium-domain-safely', 'premium-domain-payment-plans-and-financing' ),
	'sold'                                 => array( 'how-to-value-a-premium-domain-name', 'sell-my-domain', 'domain-flipping' ),
	'offer'                                => array( 'how-to-buy-a-domain-name-from-someone', 'how-to-value-a-premium-domain-name', 'premium-domain-payment-plans-and-financing' ),
	'news'                                 => array( 'premium-domain-names-complete-guide', 'domain-flipping', 'domain-backorder-and-expired-domains' ),
	'domain-brokerage-services'            => array( 'how-to-buy-a-domain-name-from-someone', 'sell-my-domain', 'domain-escrow-explained' ),
	'domain-appraisal-services'            => array( 'how-to-value-a-premium-domain-name', 'sell-my-domain', 'domain-flipping' ),
	'domain-management-services'           => array( 'how-to-transfer-a-domain', 'domain-parking', 'website-launch-checklist-after-buying-a-domain' ),
	'buy-domains-service'                  => array( 'how-to-buy-a-domain-name-from-someone', 'domain-backorder-and-expired-domains', 'how-to-buy-a-premium-domain-safely' ),
	'acquire-premium-domain'               => array( 'how-to-buy-a-domain-name-from-someone', 'how-to-value-a-premium-domain-name', 'premium-domain-payment-plans-and-financing' ),
	'acquire-social-media-handles'         => $handles,
	'sell-social-media-handles'            => $handles,
	'acquire-a-handle-now'                 => $handles,
	'our-services'                         => array( 'how-to-buy-a-domain-name-for-a-website', 'sell-my-domain', 'how-to-value-a-premium-domain-name' ),
	'domain-transfer'                      => $move,
	'epp-guidelines'                       => $move,
	'digital-marketing'                    => $market,
	'digital-marketing-services'           => $market,
	'comprehensive-digital-solutions'      => $market,
	'other-services'                       => array( 'website-launch-checklist-after-buying-a-domain', 'how-to-transfer-a-domain', 'domain-parking' ),
	'custom-software-development-services' => $build,
	'software-development'                 => $build,
	'mobile-app-development-services'      => $build,
	'mobile-app-development'               => $build,
	'website-design-development'           => array( 'website-launch-checklist-after-buying-a-domain', 'do-premium-domains-help-seo', 'change-domain-name-without-losing-seo' ),
	'web-hosting-maintenance-services'     => array( 'website-launch-checklist-after-buying-a-domain', 'how-to-transfer-a-domain', 'domain-parking' ),
	'faqs'                                 => array( 'how-to-buy-a-domain-name-for-a-website', 'premium-domain-payment-plans-and-financing', 'domain-escrow-explained' ),
	'about-us'                             => $company,
	'about-domain-brothers'                => $company,
	'our-team'                             => $company,
	'jay-mehta'                            => $company,
	'contact-us'                           => $company,
	'privacy-policy'                       => $legal,
	'refund-policy'                        => $legal,
	'terms-of-use'                         => $legal,
	'site-map'                             => array( 'how-to-buy-a-domain-name-for-a-website', 'premium-domain-names-complete-guide', 'domain-names-for-business' ),
);
