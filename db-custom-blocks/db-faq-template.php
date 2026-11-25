<?php
/**
 * Template for /faqs/ (loaded through the template_include filter in
 * db-faq-block.php). The theme's header and footer are kept; the page body
 * comes from db_faq_html().
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="site-main db-faq-main">
	<?php echo db_faq_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by db_faq_html(). ?>
	<?php echo function_exists( 'db_link_hub_html' ) ? '<div style="max-width:1320px;margin:0 auto;padding:0 15px 60px">' . db_link_hub_html( '/faqs/' ) . '</div>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
</main>
<?php
get_footer();
