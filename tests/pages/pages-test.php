<?php
/**
 * Offline tests for the page redesign transforms. Run: php tests/pages/pages-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
function add_filter() {} function add_action() {} function apply_filters( $t, $v ) { return $v; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, "UTF-8", false ); } // like WordPress: no double encoding
function esc_url( $s ) { return $s; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
require __DIR__ . '/../../db-custom-blocks/db-pages-block.php';
$pass = 0; $fail = 0;
function check( $n, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $n\n"; } else { $fail++; echo "FAIL $n\n"; } }

$acc = '<div class="wp-block-aab-accordion-item aagb__accordion_container" style="border:1px solid #ccc"><div class="aagb__accordion_head" data-active="false"><div class="aagb__accordion_heading"><h4 class="aagb__accordion_title" style="margin:0">How long &amp; how much?</h4></div><div class="aagb__accordion_icon"><span class="dashicons"></span></div></div><div class="aagb__accordion_body " role="region" style="padding:10px">' . "\n<p>About a week.</p>\n</div></div>";
$page = '<h2 class="wp-block-heading mt-25"><strong>Know Your Domain&#8217;s True Worth</strong></h2>' . "\n<p>First para.</p>\n<p>Second para.</p>\n"
	. '<h2 class="wp-block-heading"><strong>Why People Choose Us</strong></h2><p>Body.</p><div class="shadowBox"><h3>Look</h3><ul class="wp-block-list"><li><strong>Detailed Report:</strong>A full analysis</li><li><strong>Consultation</strong>: Discuss</li><li>Plain first<br>More text</li></ul></div>'
	. '<div class="faqPage mt-50"><h2>FAQs:</h2><div class="wp-block-aab-group-accordion">' . $acc . $acc . '</div></div><div class="cta_box"><a href="/contact-us/">Contact Us Now</a></div>';
$out = db_pages_service_layout( $page, 'Domain Appraisal & Services', 'https://ex.test/contact-us/', 'https://ex.test/all-domains/' );
check( 'hero holds the title, escaped', false !== strpos( $out, '<h1 class="dbp-title">Domain Appraisal &amp; Services</h1>' ) );
check( 'first heading becomes the tagline', false !== strpos( $out, '<p class="dbp-tagline">Know Your Domain&#8217;s True Worth</p>' ) || false !== strpos( $out, 'dbp-tagline">Know Your Domain' ) );
check( 'intro paragraphs move into the hero, once', 1 === substr_count( $out, 'First para.' ) && strpos( $out, 'First para.' ) < strpos( $out, 'dbp-body' ) );
check( 'second heading stays in the body', strpos( $out, 'Why People Choose Us' ) > strpos( $out, 'dbp-body' ) );
check( 'glance lists the bullet labels', false !== strpos( $out, '<li>Detailed Report</li><li>Consultation</li><li>Plain first</li>' ) );
check( 'FAQ items become details', 2 === substr_count( $out, '<details class="dbp-faq"><summary>How long &amp; how much?</summary>' ) && false === strpos( $out, 'aagb__accordion_container' ) );
check( 'FAQ answer kept', 2 === substr_count( $out, '<p>About a week.</p>' ) );
check( 'old button box removed, band added', false === strpos( $out, 'cta_box' ) && 1 === substr_count( $out, 'class="dbp-cta"' ) );
check( 'both actions link out', 2 === substr_count( $out, 'href="https://ex.test/contact-us/"' ) );
$w = db_pages_service_layout( '<div class="aboutUsPage"><h2>Tag</h2><p>Lead</p><h2>Next</h2><p>x</p></div>', 'T', '/c', '/b' );
check( 'old page wrapper is unwrapped', false === strpos( $w, 'aboutUsPage' ) && false !== strpos( $w, 'dbp-tagline">Tag' ) && substr_count( $w, '<div' ) === substr_count( $w, '</div>' ) );
$plain = db_pages_service_layout( '<p>Only text.</p>', 'T', '/c', '/b' );
check( 'page without headings still gets a hero and no glance', false !== strpos( $plain, 'dbp-lead"><p>Only text.</p>' ) && false === strpos( $plain, 'dbp-glance' ) );
check( 'content without accordions is unchanged by the FAQ pass', '<p>x</p>' === db_pages_faq_details( '<p>x</p>' ) );
// Listing pages.
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, "UTF-8", false ); }
$sets = db_pages_listing_sets();
check( 'length counts the label before the first dot', db_pages_listing_match( $sets['5-character-domains'], 'Mobiz.co.uk' ) && ! db_pages_listing_match( $sets['5-character-domains'], 'mobi.ca' ) );
check( 'numeric means digits only', db_pages_listing_match( $sets['numeric-domains-for-sale'], '8888.com' ) && ! db_pages_listing_match( $sets['numeric-domains-for-sale'], 'a888.com' ) );
$links = array( 'all' => '/all/', 'finder' => '/find/', 'acquire' => '/acq/', 'deals' => '/deals/' ); foreach ( $sets as $k => $v ) { $links[ $k ] = '/' . $k . '/'; }
$rows = array( array( 'name' => 'mobiz.ca', 'url' => '/domains/mobiz-ca/', 'price' => 2500, 'monthly' => 229.4, 'cat' => 'Business & <Tech>' ), array( 'name' => 'saska.ca', 'url' => '/domains/saska-ca/', 'price' => 0, 'monthly' => 0, 'cat' => '' ) );
$l = db_pages_listing_html( '5-character-domains', $rows, $links );
check( 'title and count', false !== strpos( $l, '<h1 class="dbl-title">5-character domains for sale</h1>' ) && false !== strpos( $l, '<strong>2 names available.</strong>' ) );
check( 'current list is marked in the links', 1 === substr_count( $l, 'aria-current="page"' ) && false !== strpos( $l, '<a href="/5-character-domains/" aria-current="page">' ) );
check( 'priced card shows price, monthly and Buy now', false !== strpos( $l, '$2,500<small>or from $229/mo</small>' ) && false !== strpos( $l, 'Buy now' ) );
check( 'offer card shows Make an offer and no price', false !== strpos( $l, 'data-name="saska.ca" data-price="0"' ) && false !== strpos( $l, 'Make an offer' ) );
check( 'category is escaped', false !== strpos( $l, 'Business &amp; &lt;Tech&gt;' ) );
$e = db_pages_listing_html( '3-character-domains', array(), $links );
check( 'empty list explains and links on, with no search box', false !== strpos( $e, 'No names in this list right now' ) && false === strpos( $e, 'dbl-q' ) && false !== strpos( $e, 'href="/find/"' ) && false !== strpos( $e, '<strong>0 names available.</strong>' ) );
$sd = db_pages_listing_html( 'sold', array( array( 'name' => 'wingcms.com', 'url' => '/domains/wingcms-com/', 'price' => 0, 'monthly' => 0, 'cat' => '' ) ), $links );
check( 'sold list shows a Sold badge, no sort and no price', false !== strpos( $sd, 'dbl-badge">Sold' ) && false === strpos( $sd, 'id="dbl-sort"' ) && false === strpos( $sd, 'Make an offer' ) && false !== strpos( $sd, '<strong>1 name sold.</strong>' ) );

// EPP guide and form layout.
$epp = '<div class="epp-hlp"><div class="qr_title"><h4 aria-level="2">1. How to obtain EPP code for my Domain from GoDaddy? <span class="logo_bg"><a href="#"><img src="g.png" alt="GoDaddy"></a></span></h4></div><p>Ans one</p><ul><li>a</li></ul><div class="qr_title"><h4>5.  How to obtain EPP code for my domain from 1&#038;1?</h4></div><p>Ans five</p><div class="qr_title"><h4>6. General guidelines to transfer your domain from current registrar</h4></div><p>Ans six</p></div>';
$eo  = db_pages_epp_layout( $epp, 'EPP Guidelines', '/guide/' );
check( 'each registrar becomes a section, the first open', 3 === substr_count( $eo, '<details class="dbp-faq dbe-item"' ) && 1 === substr_count( $eo, ' open>' ) && false !== strpos( $eo, 'id="epp-1" open' ) );
check( 'links name the registrars', false !== strpos( $eo, '<a href="#epp-1">GoDaddy</a>' ) && false !== strpos( $eo, '<a href="#epp-2">1&amp;1</a>' ) && false !== strpos( $eo, '<a href="#epp-3">Any registrar</a>' ) );
check( 'answers and logo are kept', false !== strpos( $eo, '<p>Ans five</p>' ) && false !== strpos( $eo, '<img src="g.png" alt="GoDaddy">' ) && false === strpos( $eo, 'qr_title' ) );
check( 'other content is returned unchanged', '<p>plain</p>' === db_pages_epp_layout( '<p>plain</p>', 'T', '/g/' ) );
$fo = db_pages_form_layout( '<div class="wpcf7"><form></form></div>', 'Acquire', 'Lead <b>', array( array( 'One', 'First' ), array( 'Two', 'Second' ) ) );
check( 'form sits in a card beside the steps', false !== strpos( $fo, '<div class="dbf-card"><div class="wpcf7">' ) && 2 === substr_count( $fo, '<li><strong>' ) && false !== strpos( $fo, 'Lead &lt;b&gt;' ) );
check( 'a page without a form is unchanged', '<p>x</p>' === db_pages_form_layout( '<p>x</p>', 'T', 'L', array() ) );

$dl = db_pages_deals_layout( '<main id="main" class="site-main"><h1 class="page-title deals-title">Premium Domains on Special Deals <span></span></h1><div class="resp_table"><table><tr><td>Sorry! No record(s) are found</td></tr></table></div></main>', $links );
check( 'empty deals page gets the header, an empty state and a hiding class', false !== strpos( $dl, 'class="dbl-deals-empty site-main"' ) && false !== strpos( $dl, 'No deals at the moment' ) && false === strpos( $dl, 'deals-title' ) && false !== strpos( $dl, '<a href="/deals/" aria-current="page">Deals</a>' ) );
$dl2 = db_pages_deals_layout( '<main id="main" class="site-main"><h1 class="page-title deals-title">X</h1><table><tr><td>name.com</td></tr></table></main>', $links );
check( 'deals page with deals keeps its table and gets no empty state', false === strpos( $dl2, 'dbl-empty' ) && false !== strpos( $dl2, 'name.com' ) && false === strpos( $dl2, 'dbl-deals-empty' ) );

$al = db_pages_all_layout( '<main><h1 class="page-title all_domains">Names &mdash; <span>206 Domains</span></h1><form></form></main>', $links );
check( 'all-domains heading becomes the shared header with the count', false !== strpos( $al, 'Premium domains for sale' ) && false !== strpos( $al, '<strong>206 names available.</strong>' ) && false !== strpos( $al, '<a href="/all/" aria-current="page">All domains</a>' ) && false === strpos( $al, 'all_domains' ) && false !== strpos( $al, '<form></form>' ) );
check( 'another page is unchanged', '<h1>x</h1>' === db_pages_all_layout( '<h1>x</h1>', $links ) );
$al2 = db_pages_all_layout( '<h2 class="page-title all_domains">Names &mdash; <span>1 Domains</span></h2>', $links );
check( 'the theme h2 form is matched too', false !== strpos( $al2, '<strong>1 name available.</strong>' ) );

echo "\n$pass/" . ( $pass + $fail ) . " passed\n"; exit( $fail ? 1 : 0 );
