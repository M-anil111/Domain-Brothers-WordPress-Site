<?php
/**
 * Exit offer on domain pages.
 *
 * When a visitor looks like they are about to leave a domain page, offer
 * them three ways to say yes instead: name their price (feeds the page's own
 * Make an Offer form, so the lead still lands in the CRM and the usual
 * emails go out), buy now, or pay monthly. Three recommended names follow
 * for visitors who want a different domain.
 *
 * When it shows (never on arrival, so it is not an intrusive interstitial):
 *   - desktop / mouse: the pointer leaves through the top of the window
 *     after the visitor has been on the page for at least 6 seconds;
 *   - touch: a quick scroll back up after reading at least a third of the
 *     page, or 45 seconds on the page with no interaction.
 *
 * It does not show when the visitor has already started an offer, clicked
 * buy or a plan, or seen it this session / in the last 3 days; it closes on
 * Escape, the backdrop or the close button; focus is trapped and returned.
 * Append ?db_exit_test=1 to a domain URL to ignore the delay and the
 * "already seen" memory (for testing).
 *
 * Events are pushed to window.dataLayer (db_exit_popup: shown, offer, buy,
 * plan, recommended, dismissed) so Tag Manager can report on it.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_footer', function () {
	if ( ! is_singular( 'domain' ) ) {
		return;
	}
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post || '' !== $post->post_password ) {
		return;
	}
	$name  = strtolower( get_the_title( $post ) );
	$price = function_exists( 'db_dd_price' ) ? db_dd_price( $post->ID ) : 0.0;
	if ( '' === $name ) {
		return;
	}
	$sold = function_exists( 'db_stripe_domain_unavailable' ) && db_stripe_domain_unavailable( $name );
	if ( $sold ) {
		return; // Nothing to offer on a name that is no longer for sale.
	}

	$urls = ( $price > 0 && function_exists( 'db_dd_checkout_urls' ) ) ? db_dd_checkout_urls( $name, $price ) : array();
	$recs = function_exists( 'db_rec_for' ) ? db_rec_for( $post->ID, 3 ) : array();
	$test = isset( $_GET['db_exit_test'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$cfg  = array(
		'domain' => $name,
		'minMs'  => $test ? 0 : 6000,
		'idleMs' => 45000,
		'test'   => $test,
	);
	?>
<div id="db-exit" class="db-exit" hidden>
	<div class="db-exit-backdrop" data-db-exit-close></div>
	<div class="db-exit-card" role="dialog" aria-modal="true" aria-labelledby="db-exit-title" aria-describedby="db-exit-desc" tabindex="-1">
		<button type="button" class="db-exit-x" data-db-exit-close aria-label="Close">&times;</button>
		<p class="db-exit-eyebrow">Before you go</p>
		<h2 id="db-exit-title" class="db-exit-title">Make an offer on <span><?php echo esc_html( $name ); ?></span></h2>
		<p id="db-exit-desc" class="db-exit-text">
			<?php if ( $price > 0 ) : ?>
				Listed at <strong>$<?php echo esc_html( number_format( $price, 0 ) ); ?></strong>, but we welcome offers. Tell us what you would pay and we reply personally, usually within 24 hours. No commitment.
			<?php else : ?>
				This name is open to offers. Tell us what you would pay and we reply personally, usually within 24 hours. No commitment.
			<?php endif; ?>
		</p>
		<form class="db-exit-form" novalidate>
			<label class="db-exit-label" for="db-exit-amount">Your offer (USD)</label>
			<div class="db-exit-row">
				<div class="db-exit-field">
					<span class="db-exit-cur" aria-hidden="true">$</span>
					<input id="db-exit-amount" class="db-exit-input" type="text" inputmode="numeric" autocomplete="off" placeholder="e.g. <?php echo esc_attr( $price > 0 ? number_format( max( 100, round( $price * 0.8, -2 ) ), 0 ) : '1,500' ); ?>" aria-describedby="db-exit-err">
				</div>
				<button type="submit" class="db-exit-go">Send my offer</button>
			</div>
			<p id="db-exit-err" class="db-exit-err" role="alert" hidden>Enter an amount in dollars, for example 1500.</p>
		</form>
		<?php if ( $urls ) : ?>
			<p class="db-exit-or">Rather buy it now?</p>
			<div class="db-exit-alt">
				<a class="db-exit-btn db-exit-btn--solid" data-db-exit-evt="buy" href="<?php echo esc_url( $urls['buy'] ); ?>">Buy now &middot; $<?php echo esc_html( number_format( $price, 0 ) ); ?></a>
				<a class="db-exit-btn" data-db-exit-evt="plan" href="<?php echo esc_url( $urls['plan'] ); ?>">Pay monthly from <?php echo esc_html( function_exists( 'db_dd_monthly' ) ? db_dd_monthly( $price ) : '' ); ?>/mo</a>
			</div>
		<?php endif; ?>
		<?php if ( $recs ) : ?>
			<h3 class="db-exit-sub">Or look at these</h3>
			<div data-db-exit-evt="recommended"><?php echo db_rec_cards_html( $recs, 'db-rec-grid db-exit-recs' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></div>
		<?php endif; ?>
	</div>
</div>
<style id="db-exit-css">
#db-exit[hidden]{display:none!important}
#db-exit{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;padding:max(12px,env(safe-area-inset-top)) max(12px,env(safe-area-inset-right)) max(12px,env(safe-area-inset-bottom)) max(12px,env(safe-area-inset-left))}
#db-exit *{box-sizing:border-box}
#db-exit .db-exit-backdrop{position:absolute;inset:0;background:rgba(8,16,32,.62);animation:dbExitFade .2s ease-out}
#db-exit .db-exit-card{position:relative;width:min(100%,540px);max-height:calc(100dvh - 24px);overflow:auto;overscroll-behavior:contain;background:#fff;color:#0a1628;border-radius:18px;padding:28px;box-shadow:0 24px 70px rgba(0,0,0,.35);animation:dbExitPop .25s cubic-bezier(.2,.8,.2,1);font-size:16px;line-height:1.5;display:flex;flex-direction:column;gap:14px}
#db-exit .db-exit-card>*{margin:0}
#db-exit .db-exit-card:focus{outline:none}
#db-exit .db-exit-x{position:absolute;top:8px;right:8px;width:44px;height:44px;margin:0;padding:0;border:0;border-radius:50%;background:transparent;color:#374151;font-size:28px;line-height:1;cursor:pointer}
#db-exit .db-exit-x:hover{background:#f1f5f9}
#db-exit .db-exit-x:focus-visible,#db-exit .db-exit-go:focus-visible,#db-exit .db-exit-btn:focus-visible{outline:3px solid #2e7d14;outline-offset:2px}
#db-exit .db-exit-eyebrow{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#2e7d14;margin-bottom:-8px}
#db-exit .db-exit-title{margin-right:36px;font-size:clamp(22px,4.5vw,28px);line-height:1.2;color:#0a1628;letter-spacing:-.01em}
#db-exit .db-exit-title span{color:#2e7d14;overflow-wrap:anywhere}
#db-exit .db-exit-text{color:#374151}
#db-exit .db-exit-form{display:flex;flex-direction:column;gap:6px}
#db-exit .db-exit-label{display:block;margin:0;font-size:13px;font-weight:700;color:#374151}
#db-exit .db-exit-row{display:flex;flex-wrap:wrap;gap:10px}
#db-exit .db-exit-field{flex:1 1 170px;display:flex;align-items:center;height:52px;padding:0 0 0 14px;border:2px solid #cbd5e1;border-radius:10px;background:#fff}
#db-exit .db-exit-field:focus-within{border-color:#2e7d14;box-shadow:0 0 0 3px rgba(46,125,20,.25)}
#db-exit .db-exit-cur{margin-right:6px;font-weight:700;font-size:18px;color:#374151}
#db-exit .db-exit-input{flex:1 1 auto;min-width:0;width:100%;height:100%!important;margin:0!important;padding:0 12px 0 0!important;border:0!important;outline:0!important;box-shadow:none!important;background:transparent!important;font-size:18px!important;font-weight:600;color:#0a1628}
#db-exit .db-exit-field:has([aria-invalid=true]){border-color:#b91c1c}
#db-exit .db-exit-go{flex:1 1 150px;height:52px;margin:0;padding:0 20px;border:0;border-radius:10px;background:#2e7d14;color:#fff;font-size:16px;font-weight:700;cursor:pointer;white-space:nowrap}
#db-exit .db-exit-go:hover{background:#256510}
#db-exit .db-exit-err{margin:2px 0 0;color:#b91c1c;font-size:14px}
#db-exit .db-exit-or{margin-top:6px;font-size:13px;color:#4b5563;font-weight:600}
#db-exit .db-exit-alt{display:flex;flex-wrap:wrap;gap:10px;margin-top:-6px}
#db-exit .db-exit-btn{flex:1 1 200px;display:grid;place-items:center;min-height:48px;padding:10px 16px;border:2px solid #0a1628;border-radius:10px;color:#0a1628!important;font-weight:700;text-decoration:none!important;text-align:center}
#db-exit .db-exit-btn--solid{background:#0a1628;color:#fff!important}
#db-exit .db-exit-sub{margin-top:8px;font-size:15px;color:#0a1628}
#db-exit .db-exit-recs{grid-template-columns:repeat(auto-fit,minmax(min(100%,140px),1fr))!important;gap:8px!important;margin-top:-4px}
#db-exit .db-exit-recs .db-rec-link{padding:10px 12px}
#db-exit .db-exit-recs .db-rec-name{font-size:14px}
#db-exit .db-exit-recs .db-rec-mo{display:none}
body.db-exit-open{overflow:hidden}
@keyframes dbExitFade{from{opacity:0}to{opacity:1}}
@keyframes dbExitPop{from{opacity:0;transform:translateY(14px) scale(.98)}to{opacity:1;transform:none}}
/* Phones and the cover screen of a foldable: a bottom sheet is easier to reach. */
@media (max-width:480px){#db-exit{place-items:end center;padding:0}#db-exit .db-exit-card{width:100%;border-radius:18px 18px 0 0;padding:24px 18px calc(18px + env(safe-area-inset-bottom))}#db-exit .db-exit-field,#db-exit .db-exit-go{flex:1 1 100%}}
/* Short landscape screens (a phone on its side, a half-folded device): compact and scrollable. */
@media (max-height:520px){#db-exit .db-exit-card{padding:16px 20px;gap:10px}#db-exit .db-exit-sub,#db-exit .db-exit-recs{display:none}}
/* Dual-screen / foldable with a vertical hinge: keep the card on one screen. */
@media (horizontal-viewport-segments:2){#db-exit{padding-right:calc(50vw + 12px)}}
@media (spanning:single-fold-vertical){#db-exit{padding-right:calc(50vw + 12px)}}
@media (prefers-reduced-motion:reduce){#db-exit .db-exit-backdrop,#db-exit .db-exit-card{animation:none}}
<?php echo function_exists( 'db_rec_css' ) ? db_rec_css() : ''; // phpcs:ignore WordPress.Security.EscapeOutput ?>
</style>
<script id="db-exit-js">
(function () {
	'use strict';
	var CFG = <?php echo wp_json_encode( $cfg ); ?>;
	var root = document.getElementById('db-exit');
	var offerBtn = document.querySelector('#buy_now button[data-target="#first"]');
	var amountField = document.getElementById('number-99');
	// No offer form on this page (e.g. a buy-now-only listing): nothing to feed.
	if (!root || !offerBtn || !amountField) { return; }

	var SEEN_KEY = 'dbExitSeen';
	var COOLDOWN = 3 * 24 * 3600 * 1000;
	var card = root.querySelector('.db-exit-card');
	var input = document.getElementById('db-exit-amount');
	var err = document.getElementById('db-exit-err');
	var t0 = Date.now(), shown = false, engaged = false, lastFocus = null;

	function track(action) {
		try { (window.dataLayer = window.dataLayer || []).push({ event: 'db_exit_popup', action: action, domain: CFG.domain }); } catch (e) {}
	}
	function seenRecently() {
		if (CFG.test) { return false; }
		try {
			if (sessionStorage.getItem(SEEN_KEY)) { return true; }
			var ts = parseInt(localStorage.getItem(SEEN_KEY) || '0', 10);
			return ts && Date.now() - ts < COOLDOWN;
		} catch (e) { return false; }
	}
	function markSeen() {
		try { sessionStorage.setItem(SEEN_KEY, '1'); localStorage.setItem(SEEN_KEY, String(Date.now())); } catch (e) {}
	}
	if (seenRecently()) { return; }

	// Anything that shows intent to act stops the popup for this page view.
	['input', 'focusin'].forEach(function (evt) {
		amountField.addEventListener(evt, function () { engaged = true; });
	});
	document.addEventListener('click', function (e) {
		var a = e.target.closest ? e.target.closest('#buy_now button, .db-dd-btn, a[href*="/buy-now/"], a[href*="/payment-plan-setup/"], [data-toggle="modal"]') : null;
		if (a && !root.contains(a)) { engaged = true; }
	}, true);

	function focusables() {
		return card.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])');
	}
	function open() {
		if (shown || engaged || document.querySelector('.modal.in, body.modal-open')) { return; }
		if (Date.now() - t0 < CFG.minMs) { return; }
		shown = true;
		lastFocus = document.activeElement;
		root.hidden = false;
		document.body.classList.add('db-exit-open');
		markSeen();
		track('shown');
		setTimeout(function () { input.focus(); }, 30);
	}
	function close(action) {
		if (root.hidden) { return; }
		root.hidden = true;
		document.body.classList.remove('db-exit-open');
		if (action) { track(action); }
		if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
	}

	// Desktop: pointer leaves through the top edge.
	document.addEventListener('mouseout', function (e) {
		if (!e.relatedTarget && e.clientY <= 0) { open(); }
	});
	// Touch: a quick scroll back up after reading, or a long idle.
	if (window.matchMedia && window.matchMedia('(hover: none)').matches) {
		var maxY = 0, lastY = window.scrollY, lastT = Date.now();
		window.addEventListener('scroll', function () {
			var y = window.scrollY, now = Date.now(), dt = Math.max(1, now - lastT);
			var doc = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
			maxY = Math.max(maxY, y);
			var up = lastY - y;
			if (maxY / doc > 0.33 && up > 0 && up / dt > 1.6 && y < maxY * 0.6) { open(); }
			lastY = y; lastT = now;
		}, { passive: true });
		setTimeout(function () { open(); }, CFG.idleMs);
	}

	root.addEventListener('click', function (e) {
		if (e.target.closest('[data-db-exit-close]')) { close('dismissed'); return; }
		var evt = e.target.closest('[data-db-exit-evt]');
		if (evt) { track(evt.getAttribute('data-db-exit-evt')); }
	});
	document.addEventListener('keydown', function (e) {
		if (root.hidden) { return; }
		if (e.key === 'Escape') { close('dismissed'); return; }
		if (e.key !== 'Tab') { return; }
		var f = focusables();
		if (!f.length) { return; }
		var first = f[0], last = f[f.length - 1];
		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	});

	// Send the amount to the page's own offer form and open its confirmation.
	root.querySelector('.db-exit-form').addEventListener('submit', function (e) {
		e.preventDefault();
		var digits = (input.value || '').replace(/[^0-9]/g, '');
		if (!digits || parseInt(digits, 10) < 1) {
			err.hidden = false;
			input.setAttribute('aria-invalid', 'true');
			input.focus();
			return;
		}
		err.hidden = true;
		amountField.value = digits;
		amountField.dispatchEvent(new Event('input', { bubbles: true }));
		track('offer');
		close();
		offerBtn.click();
	});
	input.addEventListener('input', function () { err.hidden = true; input.removeAttribute('aria-invalid'); });
})();
</script>
	<?php
}, 40 );
