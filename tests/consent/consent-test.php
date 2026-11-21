<?php
/**
 * Offline tests for cookie consent (db-consent-block.php) and its hold on the
 * delay loader (db-delay-block.php). Run: php tests/consent/consent-test.php
 * The generated scripts are also executed in Node when it is installed.
 */

define( 'ABSPATH', '/' );
$GLOBALS['opts'] = array();
function apply_filters( $t, $v ) { return $v; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function add_action() {}
function add_filter() {}
function wp_json_encode( $v ) { return json_encode( $v ); }
require __DIR__ . '/../../db-custom-blocks/db-delay-block.php';
require __DIR__ . '/../../db-custom-blocks/db-consent-block.php';

$pass = 0;
$fail = 0;
function check( $name, $ok ) { global $pass, $fail; if ( $ok ) { $pass++; echo "PASS $name\n"; } else { $fail++; echo "FAIL $name\n"; } }

// Settings.
check( 'default mode is region', 'region' === db_consent_mode() && db_consent_active() );
$GLOBALS['opts']['db_consent'] = array( 'mode' => 'off' );
check( 'off switches consent off', ! db_consent_active() );
$GLOBALS['opts']['db_consent'] = array( 'mode' => 'bogus' );
check( 'unknown mode falls back to region', 'region' === db_consent_mode() );
$GLOBALS['opts']['db_consent'] = array();

$regions = db_consent_regions();
check( 'regions cover EEA + UK + CH (32)', 32 === count( $regions ) && in_array( 'GB', $regions, true ) && in_array( 'CH', $regions, true ) && in_array( 'DE', $regions, true ) && ! in_array( 'US', $regions, true ) );

$js = db_consent_head_js( 'region', $regions );
check( 'region mode: denied default limited to regions', false !== strpos( $js, "analytics_storage:'denied',wait_for_update:500,region:[\"AT\"" ) );
check( 'region mode: granted default elsewhere', false !== strpos( $js, "gtag('consent','default',{ad_storage:'granted'" ) );
$all = db_consent_head_js( 'all', $regions );
check( 'all mode: denied for everyone, no region list', false === strpos( $all, 'region:[' ) && false === strpos( $all, "'default',{ad_storage:'granted'" ) );

// Delay loader: consent hold and immediate start.
$page = '<html><head></head><body><script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST"></script></body></html>';
$held = db_delay_rewrite( $page, db_delay_hosts() );
check( 'loader checks consent before starting', false !== strpos( $held, 'function ok(){var C=window.dbConsent;return !C||C.ok();}' ) && false !== strpos( $held, 'if(done||!ok())return;' ) );
check( 'loader starts on "Accept all"', false !== strpos( $held, 'addEventListener("db-consent"' ) );
check( 'default loader waits for interaction', false === strpos( $held, 'go();function later' ) );
$now = db_delay_rewrite( $page, db_delay_hosts(), true );
check( 'immediate loader starts at once', false !== strpos( $now, 'go();function later' ) );
check( 'consent head script is never delayed', false !== strpos( db_delay_rewrite( '<html><body><script data-no-delay>' . $js . '</script><script src="https://www.clarity.ms/tag/x"></script></body></html>', db_delay_hosts() ), '<script data-no-delay>window.dataLayer' ) );

// Behaviour in a JS runtime.
$node = trim( (string) shell_exec( 'command -v node 2>/dev/null' ) );
if ( '' === $node ) {
	echo "SKIP JS behaviour (node not installed)\n";
} else {
	preg_match( '#<script data-no-delay>(.*?)</script>#s', $now, $lm );
	$loader = $lm[1];
	$harness = function ( $tz, $stored, $head, $loader_js, $after ) {
		return 'var L={};var listeners={};global.window=global;global.localStorage={getItem:function(k){return ' . json_encode( $stored ) . ';},setItem:function(){}};'
			. 'global.document={cookie:"",readyState:"complete",listeners:{},addEventListener:function(e,f){(this.listeners[e]=this.listeners[e]||[]).push(f);},'
			. 'dispatch:function(e,d){(this.listeners[e]||[]).forEach(function(f){f({detail:d});});},'
			. 'querySelectorAll:function(){return started?[]:[{attributes:[],getAttribute:function(){return "x";},hasAttribute:function(){return false;},parentNode:{replaceChild:function(){started=true;}}}];},'
			. 'createElement:function(){return {setAttribute:function(){}};}};var started=false;'
			. 'global.addEventListener=function(){};global.removeEventListener=function(){};'
			. 'Intl.DateTimeFormat=function(){return {resolvedOptions:function(){return {timeZone:' . json_encode( $tz ) . '};}};};'
			. $head . $loader_js . $after;
	};
	$cases = array(
		array( 'US visitor: scripts start, consent granted by default', 'America/New_York', null, 'console.log(JSON.stringify([started,window.dbConsent.region]))', '[true,false]' ),
		array( 'UK visitor without a choice: scripts held', 'Europe/London', null, 'console.log(JSON.stringify([started,window.dbConsent.region]))', '[false,true]' ),
		array( 'UK visitor: "Accept all" starts scripts', 'Europe/London', null, 'window.dbConsent.choice="all";document.dispatch("db-consent",{choice:"all"});console.log(JSON.stringify([started]))', '[true]' ),
		array( 'UK visitor: "Reject" keeps scripts held', 'Europe/London', null, 'window.dbConsent.choice="essential";document.dispatch("db-consent",{choice:"essential"});console.log(JSON.stringify([started]))', '[false]' ),
		array( 'UK visitor who accepted before: scripts start', 'Europe/Paris', 'all', 'console.log(JSON.stringify([started]))', '[true]' ),
		array( 'Stored choice is re-applied to Consent Mode', 'Europe/Paris', 'all', 'console.log(JSON.stringify([dataLayer.filter(function(a){return a[0]==="consent"&&a[1]==="update";}).length]))', '[1]' ),
	);
	foreach ( $cases as $c ) {
		$f = tempnam( sys_get_temp_dir(), 'dbc' ) . '.js';
		file_put_contents( $f, $harness( $c[1], $c[2], $js, $loader, $c[3] ) );
		$out = trim( (string) shell_exec( escapeshellarg( $node ) . ' ' . escapeshellarg( $f ) . ' 2>&1' ) );
		unlink( $f );
		check( $c[0], $c[4] === $out );
		if ( $c[4] !== $out ) {
			echo "  got: $out\n";
		}
	}
}

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
