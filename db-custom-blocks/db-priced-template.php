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
			$db_form = function_exists( 'db_offer_resolved_form_id' ) ? (int) db_offer_resolved_form_id() : 0;
			?>
			<div class="loginpopup modal fade popup_buy" id="firs1t" tabindex="-1" role="dialog" aria-labelledby="first" aria-hidden="true" style="display: none;">
				<div class="modal-dialog" role="document">
					<div class="close_btn"><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
					<div class="modal-content clearfix">
						<div class="confirm_Offer" style="display: none;"></div>
						<?php
						if ( $db_form > 0 ) {
							echo do_shortcode( '[contact-form-7 id="' . $db_form . '"]' ); // phpcs:ignore WordPress.Security.EscapeOutput
						}
						?>
					</div>
				</div>
			</div>
			<script>
			/* Same helpers the theme's own domain page defines (its header prints them only for that template). */
			function checkoffervalue() {
				var $ = window.jQuery;
				if (!$) { return true; }
				var v = $('#number-99').val();
				if (v === '') { $('#number-99').css('border', '1px solid red'); $('.loginpopup').prop('id', 'firs1t'); return false; }
				$('#number-99').css('border', '');
				$('.loginpopup').prop('id', 'first');
				$('#offer_popup').val(v);
				$('#offer_popup').prop('readonly', true);
			}
			function KeycheckOnlyPhonenumber(e) {
				var k = e.which || e.keyCode || e.charCode;
				return !((k >= 65 && k <= 90) || (k >= 97 && k <= 122) || (k >= 33 && k <= 39) || k === 42 || k === 44 || (k >= 46 && k <= 47) || (k >= 58 && k <= 64) || (k >= 91 && k <= 96) || (k >= 123 && k <= 126));
			}
			</script>
			<script>
			(function () {
				var domain = <?php echo wp_json_encode( strtolower( wp_strip_all_tags( $db_post->post_title ) ) ); ?>;
				function fill() { var f = document.getElementById('hide_domainname'); if (f) { f.value = domain; } }
				if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fill); } else { fill(); }
				window.addEventListener('load', fill);
			})();
			</script>
			<?php
		}
		?>
	</main>
</div>
<?php
get_footer();
