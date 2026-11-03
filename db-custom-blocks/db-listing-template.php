<?php
/**
 * Page template for domains that are sold or not for sale (loaded by the
 * template_include filter in db-listing-state-block.php). The theme's own
 * single-domain template always said "is for sale!" and showed the offer form.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

get_header();
$db_post = get_queried_object();
?>
<div id="primary" class="content-area col-lg-12 col-md-12 col-sm-12">
	<main id="main" class="site-main">
		<?php
		if ( $db_post instanceof WP_Post ) {
			echo db_listing_unavailable_html( $db_post, db_listing_state( $db_post ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}
		?>
	</main>
</div>
<?php
get_footer();
