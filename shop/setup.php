<?php
/**
 * Met Prints import (run once by first-boot.sh via `wp eval-file`): store settings, attributes, categories,
 * ~1,500 variable products (4 sizes × 3 finishes) with images and seeded reviews. Re-runnable: products
 * already imported (matched by SKU) are skipped.
 */

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

// Only the image sizes the theme uses: every registered size is one more resize per imported image.
add_filter( 'intermediate_image_sizes_advanced', static fn ( array $sizes ): array => array_intersect_key( $sizes, array_flip( array( 'thumbnail', 'woocommerce_thumbnail', 'woocommerce_single' ) ) ) );
add_filter( 'big_image_size_threshold', '__return_false' );

// first-boot.sh runs DEMO_PHASE=prepare once (settings, attributes, every term), then several
// DEMO_PHASE=import processes in parallel, each with DEMO_SHARD="i/n" (items whose index % n == i).
$phase = getenv( 'DEMO_PHASE' ) ?: 'all';
[ $shard, $shards ] = array_map( 'intval', explode( '/', getenv( 'DEMO_SHARD' ) ?: '0/1' ) ) + array( 0, 1 );
$shards             = max( 1, $shards );

$data_dir = getenv( 'DEMO_DATA_DIR' ) ?: '/opt/demo/data';
$artworks = json_decode( (string) file_get_contents( $data_dir . '/artworks.json' ), true, 512, JSON_THROW_ON_ERROR );

// Store settings.
// Update WooCommerce's attribute lookup table on each save (the filter blocks read it) instead of queueing
// ~20,000 background actions that only run on page visits.
update_option( 'woocommerce_attribute_lookup_enabled', 'yes' );
update_option( 'woocommerce_attribute_lookup_direct_updates', 'yes' );

foreach ( array(
	'blogdescription'                          => 'Museum-quality prints of public-domain masterpieces',
	'woocommerce_currency'                     => 'USD',
	'woocommerce_default_country'              => 'US:NY',
	'woocommerce_store_address'                => '1000 Fifth Avenue',
	'woocommerce_store_city'                   => 'New York',
	'woocommerce_store_postcode'               => '10028',
	'woocommerce_coming_soon'                  => 'no',
	'woocommerce_store_pages_only'             => 'no',
	'woocommerce_demo_store'                   => 'yes',
	'woocommerce_demo_store_notice'            => 'Demo store: no orders are taken',
	'woocommerce_enable_reviews'               => 'yes',
	'woocommerce_enable_review_rating'         => 'yes',
	'woocommerce_review_rating_verification_required' => 'no',
	'woocommerce_hide_out_of_stock_items'      => 'no',
	'woocommerce_calc_taxes'                   => 'no',
	'woocommerce_allow_tracking'               => 'no',
	'woocommerce_analytics_enabled'            => 'no',
	'woocommerce_show_marketplace_suggestions' => 'no',
	'woocommerce_task_list_hidden'             => 'yes',
	'default_comment_status'                   => 'closed',
	'users_can_register'                       => 0,
) as $name => $value ) {
	update_option( $name, $value );
}
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
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
if ( ! wc_get_page_id( 'shop' ) || wc_get_page_id( 'shop' ) < 1 ) {
	WC_Install::create_pages();
}

// Attributes in a fixed order (IDs 1..6 on a fresh install; the theme's filter blocks use them).
$attribute_names = array(
	'department' => 'Department',
	'artist'     => 'Artist',
	'century'    => 'Century',
	'medium'     => 'Medium',
	'size'       => 'Size',
	'finish'     => 'Finish',
);
$attribute_ids   = array();
foreach ( $attribute_names as $slug => $label ) {
	$id = wc_attribute_taxonomy_id_by_name( $slug );
	if ( ! $id ) {
		$id = wc_create_attribute(
			array(
				'name'         => $label,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'name',
				'has_archives' => false,
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
	}
	$attribute_ids[ $slug ] = (int) $id;
	$taxonomy               = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy( $taxonomy, array( 'product', 'product_variation' ), array( 'hierarchical' => false, 'query_var' => true, 'rewrite' => false, 'show_ui' => false ) );
	}
}
if ( array_values( $attribute_ids ) !== array( 1, 2, 3, 4, 5, 6 ) ) {
	WP_CLI::error( 'Attribute IDs are not 1..6 (' . implode( ',', $attribute_ids ) . '): start from a fresh database (docker compose down -v).' );
}

$terms = array();
/** Term ID for a value in a taxonomy (created on first use). */
$term = static function ( string $taxonomy, string $name ) use ( &$terms ): int {
	$key = $taxonomy . '|' . $name;
	if ( ! isset( $terms[ $key ] ) ) {
		$existing      = term_exists( $name, $taxonomy );
		$terms[ $key ] = (int) ( $existing ? $existing['term_id'] : wp_insert_term( $name, $taxonomy )['term_id'] );
	}
	return $terms[ $key ];
};

$categories = array();
foreach ( array( 'Paintings', 'Japanese Prints', 'Prints', 'Drawings', 'Photographs' ) as $name ) {
	$categories[ $name ] = $term( 'product_cat', $name );
}
$uncategorized = get_term_by( 'slug', 'uncategorized', 'product_cat' );
if ( $uncategorized ) {
	update_option( 'default_product_cat', $categories['Paintings'] );
	wp_delete_term( $uncategorized->term_id, 'product_cat' );
}

if ( 'import' !== $phase ) {
	// Create every term up front, so parallel import processes never race to insert the same one.
	foreach ( $artworks as $art ) {
		$term( 'pa_department', $art['department'] );
		$term( 'pa_artist', $art['artist'] );
		$term( 'pa_century', $art['century'] );
		$term( 'pa_medium', $art['medium_bucket'] );
		foreach ( $art['variations'] as $v ) {
			$term( 'pa_size', $v['size_label'] );
			$term( 'pa_finish', $v['finish_label'] );
		}
		foreach ( array_slice( $art['tags'], 0, 8 ) as $tag ) {
			$term( 'product_tag', $tag );
		}
	}
}
if ( 'prepare' === $phase ) {
	WP_CLI::success( '[setup] prepared' );
	return;
}

$sideload = static function ( string $file, int $post_id, string $alt ) use ( $data_dir ): int {
	$tmp = wp_tempnam( $file );
	copy( $data_dir . '/images/' . $file, $tmp );
	$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), $post_id, null, array( 'post_title' => $alt ) );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'Image import failed for ' . $file . ': ' . $id->get_error_message() );
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return (int) $id;
};

$attribute = static function ( string $slug, array $term_ids, bool $variation ) use ( $attribute_ids ): WC_Product_Attribute {
	$attr = new WC_Product_Attribute();
	$attr->set_id( $attribute_ids[ $slug ] );
	$attr->set_name( wc_attribute_taxonomy_name( $slug ) );
	$attr->set_options( array_values( array_unique( $term_ids ) ) );
	$attr->set_visible( true );
	$attr->set_variation( $variation );
	return $attr;
};

wp_defer_term_counting( true );
$imported = 0;
foreach ( $artworks as $index => $art ) {
	if ( $index % $shards !== $shard ) {
		continue;
	}
	$sku = 'MET-' . $art['id'];
	if ( wc_get_product_id_by_sku( $sku ) ) {
		continue;
	}
	$sizes    = array();
	$finishes = array();
	foreach ( $art['variations'] as $v ) {
		$sizes[ $v['size_code'] ]      = $term( 'pa_size', $v['size_label'] );
		$finishes[ $v['finish_code'] ] = $term( 'pa_finish', $v['finish_label'] );
	}

	$product = new WC_Product_Variable();
	$product->set_name( $art['title'] );
	$product->set_slug( sanitize_title( $art['title'] ) . '-' . $art['id'] );
	$product->set_status( 'publish' );
	$product->set_sku( $sku );
	$product->set_featured( (bool) $art['featured'] );
	$product->set_reviews_allowed( true );
	$product->set_category_ids( array( $categories[ $art['category'] ] ) );
	$product->set_tag_ids( array_map( static fn ( string $tag ): int => $term( 'product_tag', $tag ), array_slice( $art['tags'], 0, 8 ) ) );
	$product->set_short_description( sprintf( '<p>%s, %s. Archival print from The Met’s open-access image.</p>', esc_html( $art['artist'] ), esc_html( $art['date'] ) ) );
	$product->set_description(
		sprintf(
			'<p><strong>%1$s</strong>, %2$s (%3$s).</p><p>%4$s. Original: %5$s.</p><p>%6$s. Printed from The Met’s Open Access image (CC0). <a href="%7$s">View the original at The Met</a> (object %8$d).</p>',
			esc_html( $art['title'] ),
			esc_html( $art['artist'] ),
			esc_html( $art['date'] ),
			esc_html( $art['medium'] ),
			esc_html( $art['dimensions'] ),
			esc_html( $art['credit_line'] ),
			esc_url( $art['object_url'] ),
			(int) $art['id']
		)
	);
	$product->set_attributes(
		array(
			$attribute( 'department', array( $term( 'pa_department', $art['department'] ) ), false ),
			$attribute( 'artist', array( $term( 'pa_artist', $art['artist'] ) ), false ),
			$attribute( 'century', array( $term( 'pa_century', $art['century'] ) ), false ),
			$attribute( 'medium', array( $term( 'pa_medium', $art['medium_bucket'] ) ), false ),
			$attribute( 'size', array_values( $sizes ), true ),
			$attribute( 'finish', array_values( $finishes ), true ),
		)
	);
	$product->update_meta_data( '_demo_met_id', (int) $art['id'] );
	$product->update_meta_data( '_demo_object_url', $art['object_url'] );
	$product->update_meta_data( '_demo_object_date', $art['date'] );
	$product->update_meta_data( 'total_sales', ( crc32( (string) $art['id'] ) % 400 ) + 10 * count( $art['reviews'] ) );
	$product_id = $product->save();
	$product->set_image_id( $sideload( $art['image'], $product_id, $art['title'] . ' by ' . $art['artist'] ) );
	$product->save();

	foreach ( $art['variations'] as $v ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_attributes(
			array(
				'pa_size'   => get_term( $sizes[ $v['size_code'] ] )->slug,
				'pa_finish' => get_term( $finishes[ $v['finish_code'] ] )->slug,
			)
		);
		$variation->set_sku( $v['sku'] );
		$variation->set_regular_price( (string) $v['regular_price'] );
		if ( null !== $v['sale_price'] ) {
			$variation->set_sale_price( (string) $v['sale_price'] );
		}
		$variation->set_manage_stock( false );
		$variation->set_stock_status( $v['in_stock'] ? 'instock' : 'outofstock' );
		$variation->save();
	}
	WC_Product_Variable::sync( $product_id );

	foreach ( $art['reviews'] as $review ) {
		wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Demo shopper',
				'comment_author_email' => 'shopper@demo.invalid',
				'comment_content'      => $review['text'],
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'comment_date'         => gmdate( 'Y-m-d H:i:s', strtotime( '2026-10-01' ) - DAY_IN_SECONDS * (int) $review['days_ago'] ),
				'comment_meta'         => array( 'rating' => (int) $review['rating'] ),
			)
		);
	}
	WC_Comments::clear_transients( $product_id );
	wc_delete_product_transients( $product_id );

	++$imported;
	if ( 0 === $imported % 100 ) {
		WP_CLI::log( sprintf( '[setup] shard %d/%d: %d products', $shard, $shards, $imported ) );
		wp_cache_flush_runtime();
	}
}
wp_defer_term_counting( false );
WP_CLI::success( sprintf( '[setup] imported %d products', $imported ) );
