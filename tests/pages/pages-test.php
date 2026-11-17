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
echo "\n$pass/" . ( $pass + $fail ) . " passed\n"; exit( $fail ? 1 : 0 );
