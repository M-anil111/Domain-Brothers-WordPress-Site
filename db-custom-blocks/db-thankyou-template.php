<?php
/**
 * Template for /thank-you/ (loaded through the template_include filter in
 * db-email-thankyou-block.php).
 *
 * The theme's own page-templates/thankyou.php prints a fixed "we've received
 * your request" message and never calls the_content(), so the plugin's
 * payment, plan and offer confirmations (db_ty_html) never reached the buyer:
 * a successful Stripe payment showed the same text as a contact form. This
 * renders the confirmation inside the theme's normal header and footer.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div id="primary" class="content-area col-lg-12 col-md-12 col-sm-12">
	<main id="main" class="site-main">
		<?php echo do_shortcode( '[db_thankyou]' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by db_ty_html(). ?>
	</main>
</div>
<?php
get_footer();
