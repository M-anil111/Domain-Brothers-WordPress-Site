<?php
/**
 * Page template for the FAQs page (loaded by db-faq-block.php).
 * The theme's header and footer, and every plugin hook on them, run exactly as
 * on any other page.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div id="primary" class="content-area col-lg-12 col-md-12 col-sm-12">
	<main id="main" class="site-main db-faq-main-wrap">
		<?php echo db_faq_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
	</main>
</div>
<?php
get_footer();
