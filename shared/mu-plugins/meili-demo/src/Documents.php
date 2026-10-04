<?php
namespace MeiliDemo;

/** Adds demo fields to the plugin's documents and maps ?published=YYYY to a date_query the plugin translates. */
final class Documents {
	public static function register(): void {
		add_filter( 'meilisearch_document', array( self::class, 'document' ), 10, 3 );
		add_filter( 'meilisearch_index_settings', array( self::class, 'settings' ), 10, 2 );
		add_filter( 'meilisearch_autocomplete_subtitle_field', static fn ( $field ) => 'demo_subtitle', 10, 1 );
		add_filter( 'query_vars', static fn ( array $vars ): array => array_merge( $vars, array( 'published' ) ) );
		add_action( 'pre_get_posts', array( self::class, 'published' ), 1 );
	}

	public static function document( array $doc, \WP_Post $post, string $index ): array {
		if ( 'content' === $index ) {
			$doc['year'] = (int) get_post_time( 'Y', true, $post );
			$topic       = get_the_category( $post->ID )[0]->name ?? '';
			$doc['demo_subtitle'] = trim( $topic . ' · ' . get_the_date( 'M j, Y', $post ), ' ·' );
		} elseif ( 'products' === $index ) {
			$artist = wp_get_post_terms( $post->ID, 'pa_artist', array( 'fields' => 'names' ) );
			$date   = (string) get_post_meta( $post->ID, '_demo_object_date', true );
			$doc['demo_subtitle'] = trim( ( is_array( $artist ) ? ( $artist[0] ?? '' ) : '' ) . ' · ' . $date, ' ·' );
		}
		return $doc;
	}

	public static function settings( array $settings, string $logical ): array {
		if ( 'content' === $logical && ! in_array( 'year', $settings['filterableAttributes'], true ) ) {
			$settings['filterableAttributes'][] = 'year';
		}
		return $settings;
	}

	public static function published( \WP_Query $query ): void {
		$year = (int) $query->get( 'published' );
		if ( ! $query->is_main_query() || ! $query->is_search() || $year < 1900 || $year > 2100 ) {
			return;
		}
		$query->set( 'published', '' );
		$query->set(
			'date_query',
			array(
				array(
					'after'     => sprintf( '%d-01-01 00:00:00', $year ),
					'before'    => sprintf( '%d-12-31 23:59:59', $year ),
					'inclusive' => true,
				),
			)
		);
	}
}
