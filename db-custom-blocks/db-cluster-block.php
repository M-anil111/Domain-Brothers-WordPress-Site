<?php
/**
 * Content cluster importer: the "Domain Guides" series about premium domains,
 * websites and marketing.
 *
 * The articles live in db-custom-blocks/cluster/ as block-editor markup (built from
 * content-cluster/src by tools/build-cluster.py) with a manifest. Domain Brothers >
 * Content cluster creates or updates them as posts in the "Domain Guides" category,
 * uploads their images to the media library (with alt text) and sets each cover image.
 * Nothing is published unless you choose "Import and publish". Running it again
 * updates the same posts, never duplicates them.
 *
 * Also here: FAQPage structured data for the questions shown on a cluster article, and
 * the front-end styles for its blocks (key takeaways, contents, call to action, related).
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'db_cluster_manifest' ) ) {
	function db_cluster_manifest() {
		$f = __DIR__ . '/cluster/manifest.json';
		$d = is_readable( $f ) ? json_decode( (string) file_get_contents( $f ), true ) : null;
		return is_array( $d ) ? $d : array( 'articles' => array(), 'images' => array(), 'category' => array() );
	}
}

if ( ! function_exists( 'db_cluster_post_for' ) ) {
	function db_cluster_post_for( $slug ) {
		$q = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_db_cluster_key', 'meta_value' => $slug, 'numberposts' => 1, 'suppress_filters' => true ) );
		return $q ? $q[0] : null;
	}
}

if ( ! function_exists( 'db_cluster_image' ) ) {
	/** Attachment id for a cluster image, uploading it the first time. */
	function db_cluster_image( $key, array $img ) {
		$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_db_cluster_img', 'meta_value' => $key, 'numberposts' => 1 ) );
		if ( $found ) {
			return (int) $found[0]->ID;
		}
		$src = __DIR__ . '/assets/cluster/' . basename( (string) $img['file'] );
		if ( ! is_readable( $src ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$tmp = wp_tempnam( $img['file'] );
		copy( $src, $tmp );
		$id = media_handle_sideload( array( 'name' => 'db-' . basename( $img['file'] ), 'tmp_name' => $tmp ), 0, null, array( 'post_title' => (string) $img['title'], 'post_excerpt' => '' ) );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return 0;
		}
		update_post_meta( $id, '_wp_attachment_image_alt', wp_strip_all_tags( (string) $img['alt'] ) );
		update_post_meta( $id, '_db_cluster_img', $key );
		return (int) $id;
	}
}

if ( ! function_exists( 'db_cluster_import' ) ) {
	/**
	 * @param string $mode draft | publish
	 * @return array{created:int,updated:int,images:int,errors:string[]}
	 */
	function db_cluster_import( $mode = 'draft', $budget = 20 ) {
		$m     = db_cluster_manifest();
		$start = microtime( true );
		$res   = array( 'created' => 0, 'updated' => 0, 'images' => 0, 'errors' => array(), 'more' => false );
		// Only the sizes these articles use, so each upload is quick.
		$trim = static function ( $sizes ) {
			return array_intersect_key( $sizes, array_flip( array( 'thumbnail', 'medium', 'large' ) ) );
		};
		add_filter( 'intermediate_image_sizes_advanced', $trim );
		$cat = get_term_by( 'slug', $m['category']['slug'] ?? 'domain-guides', 'category' );
		if ( ! $cat ) {
			$t   = wp_insert_term( $m['category']['name'] ?? 'Domain Guides', 'category', array( 'slug' => $m['category']['slug'] ?? 'domain-guides', 'description' => $m['category']['description'] ?? '' ) );
			$cat = is_wp_error( $t ) ? null : get_term( $t['term_id'], 'category' );
		}
		$ids = array();
		foreach ( (array) $m['images'] as $key => $img ) {
			if ( microtime( true ) - $start > $budget ) {
				$res['more'] = true; // out of time: the caller runs this again, and the finished images are reused
				remove_filter( 'intermediate_image_sizes_advanced', $trim );
				return $res;
			}
			$id = db_cluster_image( $key, $img );
			if ( $id ) {
				$ids[ $key ] = $id;
				$res['images']++;
			} else {
				$res['errors'][] = 'Image not uploaded: ' . $key;
			}
		}
		remove_filter( 'intermediate_image_sizes_advanced', $trim );
		$home = untrailingslashit( home_url() );
		foreach ( (array) $m['articles'] as $a ) {
			$file = __DIR__ . '/cluster/' . basename( $a['file'] );
			if ( ! is_readable( $file ) ) {
				$res['errors'][] = 'Missing file: ' . $a['file'];
				continue;
			}
			$content = (string) file_get_contents( $file );
			$content = str_replace( '__HOME__', $home, $content );
			$content = preg_replace_callback( '/__IMG(URL|ID):([a-z0-9\-]+)__/', static function ( $mm ) use ( $ids ) {
				$id = $ids[ $mm[2] ] ?? 0;
				return 'ID' === $mm[1] ? (string) (int) $id : ( $id ? (string) wp_get_attachment_image_url( $id, 'large' ) : '' );
			}, $content );
			$existing = db_cluster_post_for( $a['slug'] );
			$args     = array(
				'post_type'    => 'post',
				'post_title'   => $a['title'],
				'post_name'    => $a['slug'],
				'post_content' => wp_slash( $content ),
				'post_excerpt' => $a['excerpt'],
				'post_category' => $cat ? array( (int) $cat->term_id ) : array(),
				'tags_input'   => $a['tags'],
			);
			if ( $existing ) {
				$args['ID'] = $existing->ID;
				if ( 'publish' === $mode && 'publish' !== $existing->post_status ) {
					$args['post_status'] = 'publish';
				}
				$pid = wp_update_post( $args, true );
				$res['updated']++;
			} else {
				$args['post_status'] = 'publish' === $mode ? 'publish' : 'draft';
				$args['post_author'] = get_current_user_id() ?: 1;
				$pid = wp_insert_post( $args, true );
				$res['created']++;
			}
			if ( is_wp_error( $pid ) ) {
				$res['errors'][] = $a['slug'] . ': ' . $pid->get_error_message();
				continue;
			}
			update_post_meta( $pid, '_db_cluster_key', $a['slug'] );
			update_post_meta( $pid, '_db_cluster_role', $a['role'] );
			if ( ! empty( $ids[ $a['featured'] ] ) ) {
				set_post_thumbnail( $pid, $ids[ $a['featured'] ] );
			}
		}
		return $res;
	}
}

add_action( 'admin_menu', function () {
	add_submenu_page( 'db-crm-leads', 'Content cluster', 'Content cluster', 'manage_options', 'db-cluster', 'db_cluster_admin_page' );
}, 28 );

if ( ! function_exists( 'db_cluster_admin_page' ) ) {
	function db_cluster_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$m = db_cluster_manifest();
		echo '<div class="wrap"><h1>Content cluster: Domain Guides</h1>';
		if ( isset( $_GET['done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$n = array_map( 'intval', explode( '-', (string) wp_unslash( $_GET['done'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			echo '<div class="notice notice-success"><p>Done: ' . (int) ( $n[0] ?? 0 ) . ' created, ' . (int) ( $n[1] ?? 0 ) . ' updated, ' . (int) ( $n[2] ?? 0 ) . ' images in the media library' . ( ! empty( $n[3] ) ? ', ' . (int) $n[3] . ' problems (see the list below)' : '' ) . '.</p></div>';
		}
		echo '<p>A pillar guide and twelve supporting articles about premium domains, websites and marketing, with internal links, sourced external links, images and alt text. Import saves them as <strong>drafts</strong> so you can read and edit them first; running it again updates the same posts and never duplicates them. Edits you make in the editor are replaced by an update, so only update before you edit.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'db_cluster_import' );
		echo '<input type="hidden" name="action" value="db_cluster_import"><p><button class="button button-primary" name="mode" value="draft">Import as drafts</button> <button class="button" name="mode" value="publish" onclick="return confirm(\'Publish all of these articles now?\')">Import and publish</button></p></form>';
		echo '<table class="widefat striped"><thead><tr><th>Article</th><th>Role</th><th>Words</th><th>Status</th><th></th></tr></thead><tbody>';
		foreach ( (array) $m['articles'] as $a ) {
			$p  = db_cluster_post_for( $a['slug'] );
			$st = $p ? ( 'publish' === $p->post_status ? '<strong>Published</strong>' : esc_html( ucfirst( $p->post_status ) ) ) : 'Not imported';
			echo '<tr><td>' . esc_html( $a['title'] ) . '</td><td>' . esc_html( $a['role'] ) . '</td><td>' . (int) $a['words'] . '</td><td>' . wp_kses_post( $st ) . '</td><td>' . ( $p ? '<a href="' . esc_url( get_edit_post_link( $p ) ) . '">Edit</a>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}
}

add_action( 'admin_post_db_cluster_import', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_cluster_import' ) ) {
		wp_die( 'Not allowed.' );
	}
	$mode = isset( $_POST['mode'] ) && 'publish' === $_POST['mode'] ? 'publish' : 'draft';
	wp_safe_redirect( add_query_arg( array( 'action' => 'db_cluster_step', 'mode' => $mode, '_wpnonce' => wp_create_nonce( 'db_cluster_step' ) ), admin_url( 'admin-post.php' ) ) );
	exit;
} );

// One step of the import: uploads images for about 20 seconds, then comes back for more; the last step creates the posts.
add_action( 'admin_post_db_cluster_step', function () {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'db_cluster_step' ) ) {
		wp_die( 'Not allowed.' );
	}
	$mode = isset( $_GET['mode'] ) && 'publish' === $_GET['mode'] ? 'publish' : 'draft';
	$r    = db_cluster_import( $mode );
	if ( $r['more'] ) {
		$next = add_query_arg( array( 'action' => 'db_cluster_step', 'mode' => $mode, '_wpnonce' => wp_create_nonce( 'db_cluster_step' ) ), admin_url( 'admin-post.php' ) );
		echo '<!doctype html><meta charset="utf-8"><meta http-equiv="refresh" content="1;url=' . esc_url( $next ) . '"><body style="font:16px sans-serif;padding:40px">Uploading images (' . (int) $r['images'] . ' ready)&hellip; this page continues by itself.</body>'; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
	wp_safe_redirect( admin_url( 'admin.php?page=db-cluster&done=' . $r['created'] . '-' . $r['updated'] . '-' . $r['images'] . '-' . count( $r['errors'] ) ) );
	exit;
} );

// FAQPage structured data for the questions a cluster article shows (the collapsible answers).
add_action( 'wp_head', function () {
	if ( ! is_singular( 'post' ) || ! get_post_meta( get_queried_object_id(), '_db_cluster_key', true ) ) {
		return;
	}
	$c = (string) get_post_field( 'post_content', get_queried_object_id() );
	if ( ! preg_match_all( '#<details class="wp-block-details"><summary>(.*?)</summary>.*?<p>(.*?)</p>#s', $c, $mm, PREG_SET_ORDER ) ) {
		return;
	}
	$q = array();
	foreach ( $mm as $m ) {
		$q[] = array( '@type' => 'Question', 'name' => wp_strip_all_tags( html_entity_decode( $m[1] ) ), 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => wp_strip_all_tags( html_entity_decode( $m[2] ) ) ) );
	}
	if ( $q ) {
		echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $q ), JSON_UNESCAPED_SLASHES ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}, 20 );

// Lets the stylesheet target a cluster article.
add_filter( 'body_class', function ( $c ) {
	if ( is_singular( 'post' ) && get_post_meta( get_queried_object_id(), '_db_cluster_key', true ) ) {
		$c[] = 'db-cluster-post';
	}
	return $c;
} );
