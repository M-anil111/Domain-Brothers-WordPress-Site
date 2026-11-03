<?php
/**
 * Page template for domains that are sold or not for sale (loaded by the
 * template_include filter in db-listing-state-block.php). The theme's own
 * single-domain template always said "is for sale!" and showed the offer form.
 *
 * The theme also prints `window.location.href = '<home>'` for any domain whose
 * status is "sold", which is why a sold domain only showed its page with the
 * legacy ?lis=y address and otherwise bounced visitors to the home page. This
 * template renders the sold page itself, so that one redirect line is removed
 * from the header output.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

ob_start();
get_header();
echo preg_replace( '#<script\b[^>]*>\s*window\.location\.href\s*=\s*([\'"])[^\'"]*\1\s*;?\s*</script>#i', '', (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput -- theme header markup, only a redirect line removed.
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
