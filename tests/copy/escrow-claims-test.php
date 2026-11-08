<?php
/**
 * Fails if the site makes an escrow claim. The plugin has no escrow integration,
 * so no page, email, meta description or guide may describe escrow as part of
 * Domain Brothers' service. General advice about using escrow for private sales
 * (guides) is allowed; the claim patterns below are not.
 *
 *     php tests/copy/escrow-claims-test.php
 */

$root    = dirname( __DIR__, 2 );
// Claims about this business, anywhere on the site.
$claims  = '/escrow[- ](protected|backed|secure|safe)|held (in|securely in|with us in) escrow|secure escrow|escrow services|escrow (transfer|handling|protection)|escrow-protected|all (domain )?transactions include escrow|escrow arrangement|we (use|offer|provide|hold) escrow/i';
// Escrow tied to us in the same sentence is a claim. General buyer advice ("pay through escrow") is not.
$tied    = '/\b(we|our|us|domain brothers)\b[^.!?]{0,80}\bescrow\b|\bescrow\b[^.!?]{0,60}\b(we|our|domain brothers)\b/i';
$files   = array_merge(
	glob( $root . '/db-custom-blocks/*.php' ),
	glob( $root . '/db-custom-blocks/cluster/*.html' ),
	glob( $root . '/content-cluster/src/*.md' )
);
$fails = 0;
foreach ( $files as $f ) {
	if ( basename( $f ) === 'db-launch-block.php' ) {
		continue; // The owner checklist names escrow in order to say it is not claimed.
	}
	$lines = file( $f, FILE_IGNORE_NEW_LINES );
	foreach ( $lines as $i => $line ) {
		$hit = preg_match( $claims, $line, $m ) ? $m[0] : '';
		if ( '' === $hit && preg_match( $tied, $line, $m ) ) {
			$hit = $m[0];
		}
		if ( '' !== $hit ) {
			$m = array( $hit );
			echo 'FAIL ' . substr( $f, strlen( $root ) + 1 ) . ':' . ( $i + 1 ) . ' — "' . $m[0] . '"' . "\n";
			$fails++;
		}
	}
}
echo $fails ? "\n$fails escrow claim(s) found\n" : 'PASS no escrow claims in ' . count( $files ) . " files\n";
exit( $fails ? 1 : 0 );
