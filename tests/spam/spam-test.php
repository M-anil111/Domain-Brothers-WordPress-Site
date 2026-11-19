<?php
/**
 * Offline test for db-spamguard-block.php: vendor pitches, guest-post and
 * link offers, pharmacy spam and bots are blocked; real enquiries (including
 * a customer asking for web design) are not.
 *
 *     php tests/spam/spam-test.php
 */

define( 'ABSPATH', __DIR__ );
function add_action() {}
function add_filter() {}
require dirname( __DIR__, 2 ) . '/db-custom-blocks/db-spamguard-block.php';

$fails = 0;
$n     = 0;
function ok( $cond, $msg ) {
	global $fails, $n;
	$n++;
	echo ( $cond ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	if ( ! $cond ) {
		$fails++;
	}
}

/** A well-formed human post: JS ran, 20 seconds on the page, honeypot empty. */
function post( array $fields ) {
	return array_merge( array( 'contact-name' => 'Sam Rivera', 'contact-email' => 'sam@example.com', 'contact-phone' => '', 'db_hp_website' => '', 'db_hp_ts' => '20000' ), $fields );
}

$spam = array(
	'viagra'              => post( array( 'contact-msg' => 'Cheap viagra and cialis, best online pharmacy prices' ) ),
	'casino'              => post( array( 'contact-msg' => 'Play at our online casino and win big today' ) ),
	'web dev pitch'       => post( array( 'contact-msg' => 'Hello, we offer affordable web development and website redesign services. Our team of developers can help your business grow.' ) ),
	'seo pitch'           => post( array( 'contact-msg' => 'Dear website owner, our agency provides SEO services that will rank your site on the first page of Google and increase your traffic.' ) ),
	'guest post'          => post( array( 'contact-msg' => 'Hi, I would like to publish a guest post on your blog. We also sell high quality backlinks.' ) ),
	'link building'       => post( array( 'contact-msg' => 'We do link building and have DA 60 sites available for sponsored posts.' ) ),
	'app pitch'           => post( array( 'contact-msg' => 'I came across your website. We specialize in mobile app development and offshore outsourcing at the best price.' ) ),
	'links only'          => post( array( 'contact-msg' => 'Check http://spam-one.example http://spam-two.example http://spam-three.example' ) ),
	'name is a link'      => post( array( 'contact-name' => 'www.cheap-seo.example', 'contact-msg' => 'Please visit my site http://cheap-seo.example for deals' ) ),
	'cyrillic'            => post( array( 'contact-msg' => 'Здравствуйте! Мы предлагаем продвижение вашего сайта в поисковых системах по низкой цене' ) ),
	'honeypot filled'     => post( array( 'contact-msg' => 'I want to buy a domain', 'db_hp_website' => 'http://bot.example' ) ),
	'too fast'            => post( array( 'contact-msg' => 'I want to buy a domain', 'db_hp_ts' => '300' ) ),
	'no timestamp (bot)'  => array( 'contact-name' => 'Sam', 'contact-email' => 'sam@example.com', 'contact-msg' => 'I want to buy a domain', 'db_hp_website' => '' ),
);
foreach ( $spam as $label => $posted ) {
	$v = db_spam_verdict( $posted );
	ok( $v['spam'], 'blocked: ' . $label . ( $v['spam'] ? ' (' . implode( '; ', $v['reasons'] ) . ')' : '' ) );
}

$real = array(
	'buy a name'          => post( array( 'contact-msg' => 'I would like to buy fatality.ca on a nine month payment plan. Can you confirm the price is still current?' ) ),
	'offer'               => post( array( 'contact-msg' => 'I am interested in holi.ca. My budget is around $9,000. Is the owner open to an offer?' ) ),
	'web design customer' => post( array( 'contact-msg' => 'I just bought a domain and need a website built for my dental clinic. Can you send a quote for web design and SEO?' ) ),
	'marketing customer'  => post( array( 'contact-msg' => 'We need help with digital marketing and a new mobile app for our startup. What are your rates?' ) ),
	'transfer'            => post( array( 'contact-msg' => 'Please help me transfer my domain to your registrar. The EPP code is ready.' ) ),
	'one link'            => post( array( 'contact-msg' => 'This is my current site https://example.org. I want a better domain for it.' ) ),
	'seller'              => post( array( 'contact-msg' => 'I own a 4-letter .ca name and would like a broker to help me sell it.' ) ),
	'short message'       => post( array( 'contact-msg' => 'Hi, is mild.ca available?' ) ),
	'accented name'       => post( array( 'contact-name' => 'José Núñez', 'contact-msg' => 'Quiero comprar un dominio .ca para mi empresa en Toronto.' ) ),
	'phone given'         => post( array( 'contact-phone' => '+1 555 0100', 'contact-msg' => 'Please call me about premium domains for my brand.' ) ),
);
foreach ( $real as $label => $posted ) {
	$v = db_spam_verdict( $posted );
	ok( ! $v['spam'], 'allowed: ' . $label . ( $v['spam'] ? ' (' . implode( '; ', $v['reasons'] ) . ')' : '' ) );
}

echo "\n{$n} checks, {$fails} failed\n";
echo ( $n - $fails ) . '/' . $n . " passed\n";
exit( $fails ? 1 : 0 );
