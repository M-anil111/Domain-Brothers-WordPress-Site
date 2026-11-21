<?php
/**
 * Spam protection for Contact Form 7 that needs no account or keys.
 *
 * Three layers, all server-side decisions:
 *
 *  1. Honeypot: a field no person sees (db_hp_website). Anything in it is spam.
 *  2. Time on page: a hidden field (db_hp_ts) is filled in by a small script with
 *     the milliseconds since the page loaded. A form that is posted without it, or
 *     in under 2.5 seconds, is a bot (Contact Form 7 needs JavaScript to submit).
 *  3. Content score: db_spam_score() reads the text of the form and flags
 *     pharmacy and gambling spam, "guest post" and link-building offers, and cold
 *     pitches from agencies selling web development, SEO or marketing.
 *
 * The score keys on pitch language ("we offer", "our team", guest posts,
 * backlinks), not on topics, because this site genuinely sells web design,
 * development and marketing: a customer who writes "I need a website for my new
 * domain, can you quote?" must get through.
 *
 * Blocked posts are recorded in Contact Form 7's spam log and in the PHP error
 * log with the prefix "DB spam:". Turnstile (db-turnstile-block.php) is a
 * further layer once its keys are saved.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DB_SPAM_MIN_MS' ) ) {
	define( 'DB_SPAM_MIN_MS', 2500 );
}
if ( ! defined( 'DB_SPAM_THRESHOLD' ) ) {
	define( 'DB_SPAM_THRESHOLD', 3 );
}

if ( ! function_exists( 'db_spam_score' ) ) {
	/**
	 * Scores the text of a submission. Higher is spammier; DB_SPAM_THRESHOLD or more is blocked.
	 *
	 * @param array<string,string> $fields Posted field name => text (system fields already removed).
	 * @return array{score:int,reasons:string[]}
	 */
	function db_spam_score( array $fields ) {
		$text = trim( implode( "\n", array_map( 'strval', array_values( $fields ) ) ) );
		$low  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$out  = array( 'score' => 0, 'reasons' => array() );
		if ( '' === $low ) {
			return $out;
		}
		$add = function ( $points, $reason ) use ( &$out ) {
			$out['score']    += (int) $points;
			$out['reasons'][] = $reason;
		};

		// Pharmacy, adult, gambling and get-rich messages: blocked on sight.
		$blocked = '/\b(viagra|cialis|levitra|tadalafil|sildenafil|kamagra|online pharmacy|pharmacy|casino|poker|slots?|sports ?betting|porn\w*|xxx|escorts?|adult dating|weight loss|keto|diet pills?|payday loans?|forex|binary options|crypto (?:invest\w*|trading|signals)|bitcoin (?:invest\w*|doubler|generator)|replica watches?|mail[- ]order bride|hot girls)\b/iu';
		if ( preg_match( $blocked, $low, $m ) ) {
			$add( 10, 'blocked term: ' . $m[1] );
		}

		// Link-building and guest-post offers: never an enquiry to a domain broker.
		$links = '/\b(guest (?:post\w*|blog\w*|author\w*|article\w*)|sponsored (?:post|article|content)|write for us|link[- ]?building|link exchange|back-?links?|dofollow|do-follow|domain authority|\bda ?\d{2}\b|seo audit|buy (?:high[- ]quality )?links)\b/iu';
		if ( preg_match( $links, $low, $m ) ) {
			$add( 3, 'link or guest-post offer: ' . $m[1] );
		}

		// A pitch: someone offering their services, not asking for ours.
		$offer = '/\b(we (?:offer|provide|specialize|specialise|deliver|are (?:a|an|the)\b[^.]{0,40}(?:agency|company|team|firm|studio))|our (?:agency|team of|company|services|developers|experts|packages|clients|portfolio)|i (?:can|would like to) (?:offer|provide|help you)|we can help (?:you|your (?:business|website))|we help (?:businesses|brands|companies)|i represent|on behalf of our|(?:affordable|cheap|low[- ]cost|best) (?:price|rates?|packages?)|free (?:audit|quote|consultation|trial) (?:for|of) your (?:website|site))\b/iu';
		$service = '/\b(web ?(?:site)? ?(?:development|design|redesign)|web developers?|app development|mobile app|software development|e-?commerce (?:development|solutions)|wordpress (?:development|developers?|experts?)|seo|search engine optimi[sz]ation|digital marketing|social media (?:marketing|management)|ppc|lead generation|email marketing|content writing|graphic design|logo design|outsourc\w*|offshore|virtual assistants?|rank (?:your|higher|#?1)|first page of google|top of google|increase (?:your )?(?:traffic|sales|leads|rankings?)|more (?:traffic|customers|leads))\b/iu';
		if ( preg_match( $offer, $low, $o ) && preg_match( $service, $low, $s ) ) {
			$add( 3, 'service pitch: "' . trim( $o[0] ) . '" + "' . trim( $s[0] ) . '"' );
		} elseif ( preg_match( $service, $low, $s ) && preg_match_all( '/\b(?:rank|ranking|traffic|leads?|customers|sales)\b/iu', $low ) >= 3 ) {
			$add( 1, 'marketing language: ' . trim( $s[0] ) );
		}

		// Cold-outreach openers and templates.
		$opener = '/\b(i (?:came across|found|noticed|visited|was (?:visiting|browsing|checking)|have been (?:visiting|looking at)) your (?:website|site|page|domain)|hope this (?:email|message) finds you well|dear (?:sir|madam|webmaster|website owner|site owner|owner|team|business owner)|your website (?:is|has|was|does)(?:n.?t| not)? (?:missing|not|poorly|slow|outdated)|quick question about your (?:website|site)|are you (?:the )?(?:owner|manager) of|are you interested in (?:our|a|an))\b/iu';
		if ( preg_match( $opener, $low, $m ) ) {
			$add( 1, 'cold-outreach opener' );
		}

		// Links in the text.
		$urls = preg_match_all( '#(?:https?://|www\.)[^\s<>"\']+#iu', $text );
		if ( $urls >= 3 ) {
			$add( 3, $urls . ' links' );
		} elseif ( $urls === 2 ) {
			$add( 2, '2 links' );
		} elseif ( 1 === $urls && $out['score'] > 0 ) {
			$add( 1, 'a link in a message that already looks like a pitch' );
		}

		// A name that is a link or an address.
		foreach ( $fields as $key => $value ) {
			if ( preg_match( '/name/i', (string) $key ) && ! preg_match( '/domain/i', (string) $key ) && preg_match( '#(https?://|www\.|@|\.(?:com|net|org|info|biz|ru)\b)#i', (string) $value ) ) {
				$add( 3, 'link or address in the name field' );
				break;
			}
		}

		// Mostly non-Latin text on an English-language site.
		$letters = preg_match_all( '/\p{L}/u', $text );
		if ( $letters >= 25 ) {
			$other = preg_match_all( '/[\p{Cyrillic}\p{Han}\p{Hiragana}\p{Katakana}\p{Arabic}\p{Thai}\p{Hangul}]/u', $text );
			if ( $other / $letters > 0.4 ) {
				$add( 3, 'mostly non-Latin text' );
			}
		}

		// Shouting and repeated punctuation.
		if ( preg_match( '/!{4,}|\${3,}|\b(?:[A-Z]{3,}\b[\s,]*){8,}/u', $text ) ) {
			$add( 1, 'shouting or repeated symbols' );
		}
		return $out;
	}
}

if ( ! function_exists( 'db_spam_verdict' ) ) {
	/**
	 * Decides on a whole submission.
	 *
	 * @param array<string,mixed> $posted Posted data.
	 * @return array{spam:bool,reasons:string[]}
	 */
	function db_spam_verdict( array $posted ) {
		$reasons = array();
		if ( isset( $posted['db_hp_website'] ) && '' !== trim( (string) $posted['db_hp_website'] ) ) {
			$reasons[] = 'honeypot field was filled in';
		}
		if ( ! isset( $posted['db_hp_ts'] ) || ! is_numeric( $posted['db_hp_ts'] ) ) {
			$reasons[] = 'no time-on-page value (the form was not loaded in a browser)';
		} elseif ( (float) $posted['db_hp_ts'] < DB_SPAM_MIN_MS ) {
			$reasons[] = 'submitted ' . (int) $posted['db_hp_ts'] . ' ms after the page loaded';
		}
		$fields = array();
		foreach ( $posted as $key => $value ) {
			$key = (string) $key;
			if ( '' === $key || '_' === $key[0] || 0 === strpos( $key, 'db_hp' ) || in_array( $key, array( 'cf-turnstile-response', 'g-recaptcha-response' ), true ) ) {
				continue;
			}
			$fields[ $key ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		$score = db_spam_score( $fields );
		if ( $score['score'] >= DB_SPAM_THRESHOLD ) {
			$reasons = array_merge( $reasons, $score['reasons'] );
		}
		return array( 'spam' => ! empty( $reasons ), 'reasons' => $reasons );
	}
}

/* ------------------------------------------------------------ Contact Form 7 */

// The two hidden fields, added to the end of every form.
add_filter( 'wpcf7_form_elements', function ( $html ) {
	return $html . '<div class="db-hp-wrap" aria-hidden="true" style="position:absolute!important;left:-9999px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important">'
		. '<label>Leave this field empty<input type="text" name="db_hp_website" value="" tabindex="-1" autocomplete="off"></label>'
		. '<input type="hidden" name="db_hp_ts" value=""></div>';
}, 30 );

// Fills in the time on page when a form is touched or submitted. Opted out of the
// site's script delay so the value is there on the very first keystroke.
add_action( 'wp_footer', function () {
	if ( is_admin() ) {
		return;
	}
	?>
<script data-no-delay>
(function () {
	function stamp(form) {
		var f = form && form.querySelector ? form.querySelector('input[name="db_hp_ts"]') : null;
		if (f) { f.value = String(Math.round(performance.now())); }
	}
	['input', 'change', 'focusin', 'submit'].forEach(function (ev) {
		document.addEventListener(ev, function (e) {
			var form = e.target && e.target.closest ? e.target.closest('form') : null;
			if (form) { stamp(form); }
		}, true);
	});
}());
</script>
	<?php
}, 91 );

add_filter( 'wpcf7_spam', function ( $spam ) {
	if ( $spam || ! class_exists( 'WPCF7_Submission' ) ) {
		return $spam;
	}
	$sub = WPCF7_Submission::get_instance();
	if ( ! $sub ) {
		return $spam;
	}
	$verdict = db_spam_verdict( (array) $sub->get_posted_data() );
	if ( ! $verdict['spam'] ) {
		return false;
	}
	$why = implode( '; ', $verdict['reasons'] );
	if ( method_exists( $sub, 'add_spam_log' ) ) {
		$sub->add_spam_log( array( 'agent' => 'db_spamguard', 'reason' => $why ) );
	}
	error_log( 'DB spam: blocked a Contact Form 7 post (' . $why . ').' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	return true;
}, 5 );
