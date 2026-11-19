<?php
/**
 * Every post and news card always has an image.
 *
 * Three layers, so no card or article is ever shown without one:
 *
 *  1. A post with no featured image, or whose image file is missing, uses one
 *     shared default cover (assets/news-default.jpg, added to the media library
 *     once). It is supplied through the _thumbnail_id meta lookup, so the theme,
 *     the news grid, the article page, the RSS feed and social previews all get
 *     it without any change to the post. The editor is not affected: in the
 *     admin the post still shows "no featured image", so a real one can be set.
 *  2. Every news card carries a branded placeholder tile behind its picture
 *     (db_img_placeholder), so a feed story whose remote image is missing or fails
 *     to load shows a tile, not an empty box.
 *  3. The artwork is a file in the plugin, never a remote URL.
 *
 * @package db-custom-blocks
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'db_img_default_id' ) ) {
	/**
	 * Attachment id of the default cover, adding it to the media library the
	 * first time it is needed. Returns 0 when it cannot be created.
	 */
	function db_img_default_id() {
		static $id = null;
		if ( null !== $id ) {
			return $id;
		}
		$saved = (int) get_option( 'db_news_default_image', 0 );
		if ( $saved && 'attachment' === get_post_type( $saved ) && is_readable( (string) get_attached_file( $saved ) ) ) {
			$id = $saved;
			return $id;
		}
		$src = __DIR__ . '/assets/news-default.jpg';
		if ( ! is_readable( $src ) || get_transient( 'db_news_default_lock' ) ) {
			$id = 0;
			return $id;
		}
		set_transient( 'db_news_default_lock', 1, 60 );
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( 'news-default.jpg' );
		copy( $src, $tmp );
		$new = media_handle_sideload( array( 'name' => 'domain-brothers-default-cover.jpg', 'tmp_name' => $tmp ), 0, null, array( 'post_title' => 'Domain Brothers default cover', 'post_excerpt' => '' ) );
		delete_transient( 'db_news_default_lock' );
		if ( is_wp_error( $new ) ) {
			error_log( 'DB images: could not add the default cover: ' . $new->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$id = 0;
			return $id;
		}
		update_post_meta( (int) $new, '_wp_attachment_image_alt', '' );
		update_option( 'db_news_default_image', (int) $new, false );
		$id = (int) $new;
		return $id;
	}
}

add_filter( 'get_post_metadata', function ( $value, $post_id, $key, $single ) {
	static $busy = false;
	static $seen = array();
	if ( '_thumbnail_id' !== $key || null !== $value || $busy || is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $value;
	}
	$post_id = (int) $post_id;
	if ( isset( $seen[ $post_id ] ) ) {
		return $seen[ $post_id ] ? ( $single ? $seen[ $post_id ] : array( $seen[ $post_id ] ) ) : $value;
	}
	$busy = true;
	$type = get_post_type( $post_id );
	$own  = 'post' === $type ? (int) get_post_meta( $post_id, '_thumbnail_id', true ) : 0;
	if ( 'post' === $type && $own ) {
		$file = get_attached_file( $own );
		if ( $file && ! file_exists( $file ) ) { // the picture was deleted from disk
			$own = 0;
		}
	}
	$use = ( 'post' === $type && ! $own ) ? db_img_default_id() : 0;
	$busy = false;
	$seen[ $post_id ] = $use;
	return $use ? ( $single ? $use : array( $use ) ) : $value;
}, 10, 4 );

if ( ! function_exists( 'db_img_placeholder' ) ) {
	/**
	 * A branded tile to sit behind a card's picture.
	 *
	 * @param string $label Source or category shown on the tile.
	 * @param string $seed  Text that picks the colour variant (the title).
	 * @return string
	 */
	function db_img_placeholder( $label, $seed ) {
		$label = trim( (string) $label );
		return '<span class="db-an-ph" data-v="' . (int) ( abs( crc32( (string) $seed ) ) % 4 ) . '" aria-hidden="true"><i>DB</i>'
			. ( '' !== $label ? '<b>' . esc_html( $label ) . '</b>' : '' ) . '</span>';
	}
}
