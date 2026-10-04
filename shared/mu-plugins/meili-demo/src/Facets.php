<?php
namespace MeiliDemo;

/**
 * Block meili-demo/facets (blog): topic and year links with counts. The links are plain query vars the plugin
 * translates; the counts come from one demo-side multi-search (the plugin has no facets in v1).
 */
final class Facets {
	private const TOPICS = array( 'missions', 'space-station', 'earth', 'solar-system', 'universe', 'history' );

	public static function register(): void {
		register_block_type( 'meili-demo/facets', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	public static function render(): string {
		if ( ! is_search() || ! defined( 'MEILISEARCH_HOST' ) || ! defined( 'MEILISEARCH_ADMIN_KEY' ) ) {
			return '';
		}
		$uid = self::content_uid();
		if ( null === $uid ) {
			return '';
		}
		$q        = (string) get_search_query( false );
		$selected = get_query_var( 'category_name' );
		$year     = (int) ( $_GET['published'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$term     = $selected ? get_term_by( 'slug', (string) $selected, 'category' ) : null;
		$base     = array( 'post_type IN ["post"]' );
		$queries  = array(
			array( 'indexUid' => $uid, 'q' => $q, 'limit' => 0, 'facets' => array( 'tax_category_ids' ), 'filter' => implode( ' AND ', $year ? array_merge( $base, array( 'year = ' . $year ) ) : $base ) ),
			array( 'indexUid' => $uid, 'q' => $q, 'limit' => 0, 'facets' => array( 'year' ), 'filter' => implode( ' AND ', $term ? array_merge( $base, array( 'tax_category_ids = ' . (int) $term->term_id ) ) : $base ) ),
		);
		$response = Recorder::instance()->pause(
			static fn () => wp_remote_post(
				rtrim( (string) MEILISEARCH_HOST, '/' ) . '/multi-search',
				array(
					'timeout' => 2,
					'headers' => array( 'Authorization' => 'Bearer ' . MEILISEARCH_ADMIN_KEY, 'Content-Type' => 'application/json' ),
					'body'    => (string) wp_json_encode( array( 'queries' => $queries ) ),
				)
			)
		);
		$data = is_array( $response ) ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
		if ( ! is_array( $data['results'] ?? null ) ) {
			return '';
		}
		$topic_counts = (array) ( $data['results'][0]['facetDistribution']['tax_category_ids'] ?? array() );
		$year_counts  = (array) ( $data['results'][1]['facetDistribution']['year'] ?? array() );
		krsort( $year_counts, SORT_NUMERIC );

		$keep  = array_filter( array( 's' => $q, 'published' => $year ?: null, 'category_name' => $selected ?: null ) );
		$html  = '<div class="demo-facets">';
		$html .= self::list( __( 'Topic', 'meili-demo' ), self::topic_items( $topic_counts, (string) $selected, $keep ) );
		$items = array( self::item( __( 'Any year', 'meili-demo' ), null, add_query_arg( array_merge( $keep, array( 'published' => false ) ), home_url( '/' ) ), ! $year ) );
		foreach ( array_slice( $year_counts, 0, 8, true ) as $y => $count ) {
			$items[] = self::item( (string) $y, (int) $count, add_query_arg( array_merge( $keep, array( 'published' => (int) $y ) ), home_url( '/' ) ), (int) $y === $year );
		}
		$html .= self::list( __( 'Year', 'meili-demo' ), $items );
		$orderby = sanitize_key( $_GET['orderby'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$order   = strtolower( sanitize_key( $_GET['order'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$html   .= self::list(
			__( 'Sort', 'meili-demo' ),
			array(
				self::item( __( 'Most relevant', 'meili-demo' ), null, add_query_arg( array_merge( $keep, array( 'orderby' => false, 'order' => false ) ), home_url( '/' ) ), '' === $orderby ),
				self::item( __( 'Newest first', 'meili-demo' ), null, add_query_arg( array_merge( $keep, array( 'orderby' => 'date', 'order' => 'desc' ) ), home_url( '/' ) ), 'date' === $orderby && 'asc' !== $order ),
				self::item( __( 'Oldest first', 'meili-demo' ), null, add_query_arg( array_merge( $keep, array( 'orderby' => 'date', 'order' => 'asc' ) ), home_url( '/' ) ), 'date' === $orderby && 'asc' === $order ),
			)
		);
		$html .= '<p class="demo-facets__note">' . esc_html__( 'Counts come from a demo-side Meilisearch request: the plugin has no facets yet. The links are ordinary WordPress query vars that the plugin translates.', 'meili-demo' ) . '</p></div>';
		return $html;
	}

	private static function content_uid(): ?string {
		foreach ( Recorder::instance()->calls() as $call ) {
			if ( preg_match( '#/indexes/([^/]+_content)/search$#', $call['path'], $m ) ) {
				return $m[1];
			}
		}
		$prefix = defined( 'MEILI_DEMO_INDEX_PREFIX' ) ? MEILI_DEMO_INDEX_PREFIX : getenv( 'MEILISEARCH_INDEX_PREFIX' );
		return $prefix ? $prefix . '_content' : null;
	}

	private static function topic_items( array $counts, string $selected, array $keep ): array {
		$items = array( self::item( __( 'All topics', 'meili-demo' ), null, add_query_arg( array_merge( $keep, array( 'category_name' => false ) ), home_url( '/' ) ), '' === $selected ) );
		foreach ( self::TOPICS as $slug ) {
			$term = get_term_by( 'slug', $slug, 'category' );
			if ( $term ) {
				$items[] = self::item( $term->name, (int) ( $counts[ (string) $term->term_id ] ?? 0 ), add_query_arg( array_merge( $keep, array( 'category_name' => $slug ) ), home_url( '/' ) ), $slug === $selected );
			}
		}
		return $items;
	}

	private static function item( string $label, ?int $count, string $url, bool $current ): string {
		return sprintf(
			'<li><a href="%1$s"%2$s><span>%3$s</span>%4$s</a></li>',
			esc_url( $url ),
			$current ? ' aria-current="true"' : '',
			esc_html( $label ),
			null === $count ? '' : ' <b>' . esc_html( (string) $count ) . '</b>'
		);
	}

	private static function list( string $label, array $items ): string {
		return '<h2 class="demo-facets__h">' . esc_html( $label ) . '</h2><ul aria-label="' . esc_attr( $label ) . '">' . implode( '', $items ) . '</ul>';
	}
}
