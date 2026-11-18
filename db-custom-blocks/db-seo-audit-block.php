<?php
/**
 * SEO audit: admin-only, read-only report of the Rank Math fields stored for each
 * page, post and domain listing (focus keyword, score, SEO title, description).
 *
 * GET /wp-json/db/v1/seo-audit?type=page|post|domain&page=1&per_page=200
 * Requires an administrator session (cookie + X-WP-Nonce). Nothing is written.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_seo_audit_row' ) ) {
	/**
	 * One audit row for a post.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	function db_seo_audit_row( $post ) {
		$content = (string) $post->post_content;
		$text    = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $content ) ) ) );
		return array(
			'id'          => (int) $post->ID,
			'type'        => $post->post_type,
			'slug'        => $post->post_name,
			'parent'      => (int) $post->post_parent,
			'title'       => $post->post_title,
			'link'        => get_permalink( $post ),
			'template'    => (string) get_post_meta( $post->ID, '_wp_page_template', true ),
			'keyword'     => (string) get_post_meta( $post->ID, 'rank_math_focus_keyword', true ),
			'score'       => (string) get_post_meta( $post->ID, 'rank_math_seo_score', true ),
			'seo_title'   => (string) get_post_meta( $post->ID, 'rank_math_title', true ),
			'description' => (string) get_post_meta( $post->ID, 'rank_math_description', true ),
			'robots'      => get_post_meta( $post->ID, 'rank_math_robots', true ),
			'words'       => '' === $text ? 0 : count( explode( ' ', $text ) ),
			'modified'    => $post->post_modified_gmt,
		);
	}
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'db/v1', '/seo-audit', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'manage_options' );
		},
		'args'                => array(
			'type'     => array(
				'default'           => 'page',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => function ( $value ) {
					return in_array( $value, array( 'page', 'post', 'domain' ), true );
				},
			),
			'page'     => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
			'per_page' => array( 'default' => 200, 'sanitize_callback' => 'absint' ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$per   = min( 500, max( 1, (int) $request['per_page'] ) );
			$query = new WP_Query( array(
				'post_type'              => $request['type'],
				'post_status'            => 'publish',
				'posts_per_page'         => $per,
				'paged'                  => max( 1, (int) $request['page'] ),
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'update_post_term_cache' => false,
			) );
			$rows = array_map( 'db_seo_audit_row', $query->posts );
			return rest_ensure_response( array(
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
				'rows'  => $rows,
			) );
		},
	) );
} );
