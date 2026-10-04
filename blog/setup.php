<?php
/**
 * Mission Log import (run once by first-boot.sh via `wp eval-file`): options, six topic categories,
 * authors, ~1,000 NASA articles with featured and inline images. Re-runnable: posts already imported
 * (matched by _demo_source_id) are skipped, so a first boot interrupted half-way can be retried.
 */

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Only the image sizes the theme and the plugin's autocomplete use: each size is one more resize per image.
add_filter( 'intermediate_image_sizes_advanced', static fn ( array $sizes ): array => array_intersect_key( $sizes, array_flip( array( 'thumbnail', 'medium', 'medium_large', 'large' ) ) ) );
add_filter( 'big_image_size_threshold', '__return_false' );

// first-boot.sh runs DEMO_PHASE=prepare once (settings, topics, authors), then several DEMO_PHASE=import
// processes in parallel, each with DEMO_SHARD="i/n" (articles whose index % n == i).
$phase = getenv( 'DEMO_PHASE' ) ?: 'all';
[ $shard, $shards ] = array_map( 'intval', explode( '/', getenv( 'DEMO_SHARD' ) ?: '0/1' ) ) + array( 0, 1 );
$shards             = max( 1, $shards );

$data_dir = getenv( 'DEMO_DATA_DIR' ) ?: '/opt/demo/data';
$articles = json_decode( (string) file_get_contents( $data_dir . '/articles.json' ), true, 512, JSON_THROW_ON_ERROR );
$topics   = array(
	'missions'      => 'Missions',
	'space-station' => 'Space Station',
	'earth'         => 'Earth',
	'solar-system'  => 'Solar System',
	'universe'      => 'Universe',
	'history'       => 'History',
);

update_option( 'blogdescription', 'Stories from NASA science, searched with Meilisearch' );
update_option( 'default_comment_status', 'closed' );
update_option( 'default_ping_status', 'closed' );
update_option( 'users_can_register', 0 );
update_option( 'posts_per_page', 10 );
update_option( 'show_on_front', 'posts' );
update_option( 'thumbnail_size_w', 160 );
update_option( 'thumbnail_size_h', 160 );
// WordPress's sample content has no place in the demo (and Hello world! has no image).
foreach ( array( 'hello-world' => 'post', 'sample-page' => 'page' ) as $slug => $type ) {
	$sample = get_page_by_path( $slug, OBJECT, $type );
	if ( $sample ) {
		wp_delete_post( $sample->ID, true );
	}
}
update_option(
	'meilisearch_content',
	array(
		'post_types' => array( 'post', 'page' ), // Everything the default site search covers, so the plugin can answer it.
		'taxonomies' => array( 'post' => array( 'category', 'post_tag' ) ),
		'meta_keys'  => array(),
	)
);

$term_ids = array();
foreach ( $topics as $slug => $name ) {
	$existing          = term_exists( $slug, 'category' );
	$term_ids[ $slug ] = (int) ( $existing ? $existing['term_id'] : wp_insert_term( $name, 'category', array( 'slug' => $slug ) )['term_id'] );
}
update_option( 'default_category', $term_ids['missions'] );
$uncategorized = get_term_by( 'slug', 'uncategorized', 'category' );
if ( $uncategorized ) {
	wp_delete_term( $uncategorized->term_id, 'category' );
}

/** Copies a data image to a temp file and sideloads it; returns the attachment ID. */
$sideload = static function ( string $relative, int $post_id, string $alt ) use ( $data_dir ): int {
	static $fallbacks = array(); // Topic fallback images are imported once and shared.
	if ( str_starts_with( $relative, 'fallback-' ) && isset( $fallbacks[ $relative ] ) ) {
		return $fallbacks[ $relative ];
	}
	$tmp = wp_tempnam( basename( $relative ) );
	copy( $data_dir . '/images/' . $relative, $tmp );
	$id = media_handle_sideload(
		array(
			'name'     => basename( $relative ),
			'tmp_name' => $tmp,
		),
		$post_id,
		null,
		array( 'post_title' => $alt )
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'Image import failed for ' . $relative . ': ' . $id->get_error_message() );
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	if ( str_starts_with( $relative, 'fallback-' ) ) {
		$fallbacks[ $relative ] = (int) $id;
	}
	return (int) $id;
};

$authors = array();
$author  = static function ( string $name ) use ( &$authors ): int {
	if ( isset( $authors[ $name ] ) ) {
		return $authors[ $name ];
	}
	$login = sanitize_user( strtolower( str_replace( ' ', '.', remove_accents( $name ) ) ), true ) ?: 'nasa.science';
	$user  = get_user_by( 'login', $login );
	$id    = $user ? $user->ID : wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 32 ),
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => 'author',
			'user_email'   => $login . '@demo.invalid',
		)
	);
	return $authors[ $name ] = (int) $id;
};

if ( 'import' !== $phase ) {
	// Create every author and tag up front, so parallel import processes never race to insert the same one.
	foreach ( $articles as $article ) {
		$author( $article['author'] );
		foreach ( $article['tags'] as $tag ) {
			if ( ! term_exists( $tag, 'post_tag' ) ) {
				wp_insert_term( $tag, 'post_tag' );
			}
		}
	}
}
if ( 'prepare' === $phase ) {
	WP_CLI::success( '[setup] prepared' );
	return;
}

// Source IDs already imported (a first boot interrupted half-way is retried).
$done = array_flip( array_map( 'intval', $GLOBALS['wpdb']->get_col( "SELECT meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = '_demo_source_id'" ) ) );

wp_defer_term_counting( true );
$imported = 0;
foreach ( array_reverse( $articles ) as $index => $article ) { // Oldest first, so IDs grow with dates.
	if ( $index % $shards !== $shard || isset( $done[ (int) $article['id'] ] ) ) {
		continue;
	}
	$content = $article['content'];
	$post_id = wp_insert_post(
		array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => $article['title'],
			'post_name'     => $article['slug'],
			'post_excerpt'  => $article['excerpt'],
			'post_content'  => '',
			'post_date_gmt' => str_replace( 'T', ' ', $article['date_gmt'] ),
			'post_date'     => get_date_from_gmt( str_replace( 'T', ' ', $article['date_gmt'] ) ),
			'post_author'   => $author( $article['author'] ),
			'post_category' => array( $term_ids[ $article['topic'] ] ),
			'tags_input'    => $article['tags'],
			'meta_input'    => array(
				'_demo_source_id'  => (int) $article['id'],
				'_demo_source_url' => $article['source_url'],
			),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( $post_id->get_error_message() );
	}

	// Inline images: sideload, then point src at the attachment URL.
	$content = preg_replace_callback(
		'/src="(inline\/[^"]+)"/',
		static function ( array $m ) use ( $sideload, $post_id ): string {
			$id = $sideload( $m[1], $post_id, '' );
			return 'src="' . esc_url( (string) wp_get_attachment_image_url( $id, 'large' ) ) . '"';
		},
		$content
	);
	// Article images carry their description in the figcaption; an empty alt keeps screen readers from
	// announcing the file name.
	$content  = preg_replace( '/<img(?![^>]*\balt=)([^>]*)>/', '<img alt=""$1>', $content );
	// Links that only wrap an image (to the full-size file) would have no accessible name: keep the image.
	$content = preg_replace( '#<a\b[^>]*>\s*(<img\b[^>]*>)\s*</a>#', '$1', $content );
	// Links wrapping blocks (NASA "cards") are split by browsers into empty links: keep the content only.
	$content = preg_replace( '#<a\b[^>]*>((?:(?!</a>).)*?<(?:h[1-6]|p|ul|ol|li|figure|blockquote)\b(?:(?!</a>).)*)</a>#s', '$1', $content );
	$content = preg_replace( '#<a\b[^>]*>(?:\s|<br\s*/?>)*</a>#', '', $content ); // Links left empty.
	$content .= sprintf(
		"\n<p class=\"demo-source\">Source: <a href=\"%s\">NASA Science</a></p>",
		esc_url( $article['source_url'] )
	);
	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $content,
		)
	);
	set_post_thumbnail( $post_id, $sideload( $article['image'], $post_id, $article['image_alt'] ) );
	if ( $article['sticky'] ) {
		stick_post( $post_id );
	}

	++$imported;
	if ( 0 === $imported % 100 ) {
		WP_CLI::log( sprintf( '[setup] shard %d/%d: %d articles', $shard, $shards, $imported ) );
		wp_cache_flush_runtime();
	}
}
wp_defer_term_counting( false );
WP_CLI::success( sprintf( '[setup] imported %d articles', $imported ) );
