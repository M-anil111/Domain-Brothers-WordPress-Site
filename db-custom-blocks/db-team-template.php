<?php
/**
 * Template for /our-team/ (loaded through the template_include filter in
 * db-team-block.php). The theme's header and footer are kept; the page body
 * comes from db_team_html().
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="main" class="site-main db-team-main">
	<?php echo db_team_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped by db_team_html(). ?>
</main>
<?php
get_footer();
