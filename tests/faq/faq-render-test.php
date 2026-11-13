<?php
/**
 * Offline test of the FAQs page markup (no WordPress). Loads the real
 * db-faq-block.php against small stubs, renders the page body and checks the
 * things the design and accessibility depend on.
 *
 *   php tests/faq/faq-render-test.php            run the checks
 *   php tests/faq/faq-render-test.php --html     print the rendered body
 *
 * Exits non-zero if a check fails.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['IS_FAQ'] = true;
function add_action() {} function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function is_admin() { return false; }
function is_page( $p = null ) { return $GLOBALS['IS_FAQ'] && ( null === $p || in_array( 'faqs', (array) $p, true ) ); }
function home_url( $p = '/' ) { return 'https://beta.domainbrothers.com' . $p; }
function plugin_dir_url( $f ) { return 'https://beta.domainbrothers.com/wp-content/plugins/db-custom-blocks/'; }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function wp_kses( $s, $allowed ) { return strip_tags( (string) $s ); }
function wp_kses_post( $s ) { return strip_tags( (string) $s, '<a><strong><em><br>' ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_title( $s ) { return strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $s ) ); }
function db_breadcrumbs_trail() { return array( array( 'label' => 'Home', 'href' => home_url( '/' ) ), array( 'label' => 'FAQs', 'href' => '' ) ); }
function db_breadcrumbs_html( $trail ) {
	$h = '<nav class="db-breadcrumbs" aria-label="Breadcrumb"><ol>';
	foreach ( $trail as $i => $c ) {
		$h .= '<li>' . ( '' !== $c['href'] ? '<a href="' . esc_url( $c['href'] ) . '">' . esc_html( $c['label'] ) . '</a>' : '<span aria-current="page">' . esc_html( $c['label'] ) . '</span>' ) . '</li>';
	}
	return $h . '</ol></nav>';
}

require __DIR__ . '/../../db-custom-blocks/db-faq-block.php';

$html = db_faq_html();
if ( in_array( '--html', $argv, true ) ) {
	echo $html;
	exit( 0 );
}

$fail = 0; $n = 0;
function check( $ok, $what ) {
	global $fail, $n;
	$n++;
	if ( ! $ok ) { $fail++; echo "FAIL  $what\n"; } else { echo "ok    $what\n"; }
}

$doc = new DOMDocument();
libxml_use_internal_errors( true );
$doc->loadHTML( '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>' );
$errors = array_filter( libxml_get_errors(), function ( $e ) { return false === strpos( $e->message, 'Tag nav invalid' ) && false === strpos( $e->message, 'Tag main invalid' ) && false === strpos( $e->message, 'Tag section invalid' ) && false === strpos( $e->message, 'Tag aside invalid' ) && false === strpos( $e->message, 'Tag svg invalid' ) && false === strpos( $e->message, 'Tag path invalid' ) && false === strpos( $e->message, 'Tag circle invalid' ); } );
check( 0 === count( $errors ), 'markup parses without errors (' . count( $errors ) . ')' );
$x = new DOMXPath( $doc );

// Hero.
check( 1 === $x->query( '//h1' )->length, 'one h1' );
check( 'Clear answers. Confident decisions.' === trim( preg_replace( '/\s+/', ' ', $x->query( '//h1' )->item( 0 )->textContent ) ), 'h1 text matches the design' );
check( 1 === $x->query( '//nav[contains(@class,"db-breadcrumbs")]' )->length, 'breadcrumb inside the hero' );
check( 'FAQs' === $x->query( '//nav[contains(@class,"db-breadcrumbs")]//*[@aria-current="page"]' )->item( 0 )->textContent, 'breadcrumb ends at FAQs' );
$img = $x->query( '//img[contains(@class,"db-faq-hero-img")]' )->item( 0 );
check( null !== $img, 'hero image present' );
check( $img && strlen( $img->getAttribute( 'alt' ) ) > 20, 'hero image has descriptive alt text' );
check( $img && '1000' === $img->getAttribute( 'width' ) && '833' === $img->getAttribute( 'height' ), 'hero image has width and height (no layout shift)' );
check( $img && 'high' === $img->getAttribute( 'fetchpriority' ) && '' === $img->getAttribute( 'loading' ), 'hero image is not lazy-loaded' );
check( is_readable( __DIR__ . '/../../db-custom-blocks/assets/faq-hero.webp' ) && is_readable( __DIR__ . '/../../db-custom-blocks/assets/faq-hero-560.webp' ), 'hero image files exist' );
$input = $x->query( '//form[@role="search"]//input[@type="search"]' )->item( 0 );
check( null !== $input, 'search field present' );
check( $input && 1 === $x->query( '//label[@for="' . $input->getAttribute( 'id' ) . '"]' )->length, 'search field has a label' );
check( 'Search your question…' === html_entity_decode( $input ? $input->getAttribute( 'placeholder' ) : '' ), 'search placeholder matches the design' );

// Topics and sections.
$topics = db_faq_topics();
$links  = $x->query( '//nav[contains(@class,"db-faq-topics")]//a' );
check( 6 === count( $topics ) && 6 === $links->length, 'six topics in "Browse by topic"' );
$labels = array();
foreach ( $links as $a ) { $labels[] = trim( $a->textContent ); }
check( array( 'Domain basics', 'Business strategy', 'Buying & selling', 'Legal & security', 'Additional services', 'Other questions' ) === $labels, 'topic labels and order match the design' );
foreach ( $links as $a ) {
	$id = substr( $a->getAttribute( 'href' ), 1 );
	check( 1 === $x->query( '//section[@id="' . $id . '"]' )->length, "topic link #$id has a section" );
}
check( 1 === $x->query( '//nav[contains(@class,"db-faq-topics")]//a[@aria-current="true"]' )->length, 'one topic marked current' );

// Accordion.
$buttons = $x->query( '//*[contains(@class,"db-faq-q")]/button' );
$total   = 0;
foreach ( $topics as $t ) { $total += count( $t['items'] ); }
check( $buttons->length === $total && $total >= 18, "every question is a button ($total)" );
$ids = array(); $open = 0; $okPairs = true;
foreach ( $buttons as $b ) {
	$panelId = $b->getAttribute( 'aria-controls' );
	$panel   = $doc->getElementById( $panelId );
	$ids[]   = $panelId; $ids[] = $b->getAttribute( 'id' );
	if ( ! $panel || $panel->getAttribute( 'aria-labelledby' ) !== $b->getAttribute( 'id' ) || 'region' !== $panel->getAttribute( 'role' ) ) { $okPairs = false; }
	$exp = $b->getAttribute( 'aria-expanded' );
	if ( 'true' === $exp ) { $open++; if ( $panel && $panel->hasAttribute( 'hidden' ) ) { $okPairs = false; } }
	if ( 'false' === $exp && $panel && ! $panel->hasAttribute( 'hidden' ) ) { $okPairs = false; }
	if ( 'button' !== $b->getAttribute( 'type' ) ) { $okPairs = false; }
}
check( $okPairs, 'buttons and answers are linked (aria-controls / aria-labelledby / hidden)' );
check( 1 === $open && 'What are premium domains?' === trim( $buttons->item( 0 )->textContent ), 'only the first question starts open' );
check( count( $ids ) === count( array_unique( $ids ) ), 'ids are unique' );
$allIds = array();
foreach ( $x->query( '//*[@id]' ) as $el ) { $allIds[] = $el->getAttribute( 'id' ); }
check( count( $allIds ) === count( array_unique( $allIds ) ), 'no duplicate ids on the page body' );
check( 1 === $x->query( '//*[@role="status"]' )->length && 1 === $x->query( '//*[@data-db-faq-empty]' )->length, 'search has a status line and an empty state' );

// Headings in order: h1 > h2 > h3, no skipped levels.
$levels = array();
foreach ( $x->query( '//h1|//h2|//h3|//h4' ) as $hd ) { $levels[] = (int) substr( $hd->nodeName, 1 ); }
$skip = false;
for ( $i = 1; $i < count( $levels ); $i++ ) { if ( $levels[ $i ] > $levels[ $i - 1 ] + 1 ) { $skip = true; } }
check( ! $skip, 'heading levels do not skip' );

// Calls to action and links.
check( 2 === $x->query( '//a[contains(@class,"db-faq-btn") and @href="https://beta.domainbrothers.com/contact-us/"]' )->length, 'both "Contact our team" buttons go to /contact-us/' );
check( 1 === $x->query( '//h2[normalize-space()="Still have a question?"]' )->length, '"Still have a question?" band present' );
check( 1 === $x->query( '//h2[normalize-space()="Need a little guidance?"]' )->length, '"Need a little guidance?" card present' );
check( false === strpos( $html, 'db-ad' ) && false === stripos( $html, 'Advertisement' ), 'no ad slots inside the page body' );
foreach ( $x->query( '//svg' ) as $svg ) {
	if ( 'true' !== $svg->getAttribute( 'aria-hidden' ) ) { check( false, 'decorative svg without aria-hidden' ); }
}

// Structured data matches what is on the page.
$schema = db_faq_schema();
check( 'FAQPage' === $schema['@type'] && count( $schema['mainEntity'] ) === $total, 'FAQPage schema lists every visible question' );
$visibleQ = array();
foreach ( $buttons as $b ) { $visibleQ[] = trim( $b->textContent ); }
$schemaQ = array_map( function ( $q ) { return $q['name']; }, $schema['mainEntity'] );
check( $visibleQ === $schemaQ, 'schema questions are word for word the visible ones' );
$noTags = true;
foreach ( $schema['mainEntity'] as $q ) { if ( $q['acceptedAnswer']['text'] !== strip_tags( $q['acceptedAnswer']['text'] ) || '' === $q['acceptedAnswer']['text'] ) { $noTags = false; } }
check( $noTags, 'schema answers are plain text' );

// The page is detected only on the FAQs page.
check( db_faq_is_page(), 'detects the FAQs page' );
$GLOBALS['IS_FAQ'] = false;
check( ! db_faq_is_page(), 'other pages are left alone' );

// The template prints the theme header and footer.
$tpl = file_get_contents( __DIR__ . '/../../db-custom-blocks/db-faq-template.php' );
check( false !== strpos( $tpl, 'get_header();' ) && false !== strpos( $tpl, 'get_footer();' ) && false !== strpos( $tpl, '<main id="main"' ), 'template keeps the theme header, footer and #main' );

// Ads are switched off on the FAQs page.
$ads = file_get_contents( __DIR__ . '/../../db-custom-blocks/db-ads-block.php' );
check( (bool) preg_match( "/\\\$bad = array\\([^)]*'faqs'/", $ads ), 'ads block skips the FAQs page' );

echo "\n" . ( $n - $fail ) . "/$n checks passed\n";
exit( $fail ? 1 : 0 );
