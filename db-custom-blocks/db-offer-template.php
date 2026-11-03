<?php
/**
 * The buyer's private offer page (domain URL + ?c=<token>), loaded by the
 * template_include filter in db-offers-block.php. See that file for the flow.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

get_header();
$db_post = get_queried_object();
$db_deal = function_exists( 'db_deal_current' ) ? db_deal_current() : null;
?>
<div id="primary" class="content-area col-lg-12 col-md-12 col-sm-12">
	<main id="main" class="site-main">
		<?php
		if ( $db_post instanceof WP_Post && $db_deal ) {
			echo db_deal_buyer_html( $db_deal, $db_post ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}
		?>
	</main>
</div>
<?php
get_footer();
