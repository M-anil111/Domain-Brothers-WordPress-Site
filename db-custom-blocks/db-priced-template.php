<?php
/**
 * Page template for a domain that has a price (loaded by db-priced-block.php).
 * The header, footer and every plugin hook (SEO, breadcrumbs, exit offer,
 * analytics, the offer thank-you redirect) run exactly as on any domain page.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div id="primary" class="content-area col-lg-12 col-md-12 col-sm-12">
	<main id="main" class="site-main domain-detail db-priced-main">
		<?php
		while ( have_posts() ) {
			the_post();
			$db_post = get_post();
			echo db_priced_html( $db_post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
			?>
			<script>
			/* The key filter the amount box uses (the theme's own domain page defines it; its header prints it only for that template). */
			function KeycheckOnlyPhonenumber(e) {
				var k = e.which || e.keyCode || e.charCode;
				return !((k >= 65 && k <= 90) || (k >= 97 && k <= 122) || (k >= 33 && k <= 39) || k === 42 || k === 44 || (k >= 46 && k <= 47) || (k >= 58 && k <= 64) || (k >= 91 && k <= 96) || (k >= 123 && k <= 126));
			}
			</script>
			<script>
			/* The theme's footer pre-fills the offer box with "$" + a suggested amount
			   (jQuery("#number-99").val('$2625')) and writes it back on keyup when the
			   box is emptied. This page already shows a fixed "$" in front of the box,
			   so the visitor saw "$ $2750". Keep the number, drop the symbol: only in
			   this box, and only while it sits next to that fixed "$".

			   The box's own value setter does the cleaning, so it works whatever order
			   the scripts run in. Cloudflare Rocket Loader delays every inline script
			   (this one included) until after the page's load event, so timing-based
			   clean-ups can run before the theme's script and miss it. */
			(function () {
				var STRIP = /[$\s,]/g;
				function guard(f) {
					if (!f || f.getAttribute('data-db-clean') || !f.closest || !f.closest('.db-pr-amount')) { return; }
					f.setAttribute('data-db-clean', '1');
					var d = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
					if (d && d.get && d.set) {
						try {
							Object.defineProperty(f, 'value', {
								configurable: true,
								get: function () { return d.get.call(this); },
								set: function (v) { d.set.call(this, String(v == null ? '' : v).replace(STRIP, '')); }
							});
						} catch (e) {}
					}
					// Pasted or typed text, and anything already in the box.
					['input', 'keyup', 'change', 'blur', 'paste'].forEach(function (e) {
						f.addEventListener(e, function () { setTimeout(function () { var v = f.value; if (/[$\s,]/.test(v)) { f.value = v; } }, 0); });
					});
					f.value = f.value;
				}
				guard(document.getElementById('number-99'));
				document.addEventListener('DOMContentLoaded', function () { guard(document.getElementById('number-99')); });
			})();
			</script>
			<?php
		}
		?>
	</main>
</div>
<?php
get_footer();
