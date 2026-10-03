<?php
namespace MeiliDemo;

/** ?engine=mysql opts the main query out of the plugin; compare() counts the same search on the other engine. */
final class Engine {
	public static function register(): void {
		add_filter( 'meilisearch_should_intercept', static fn ( bool $intercept, \WP_Query $query ): bool => self::is_mysql() && $query->is_main_query() ? false : $intercept, 10, 2 );
	}

	public static function is_mysql(): bool {
		return isset( $_GET['engine'] ) && 'mysql' === $_GET['engine']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Runs the main search once more on the other engine (ids only, first page) and times it.
	 *
	 * @return array{engine: string, hits: int, ms: float}|null
	 */
	public static function compare(): ?array {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || ! $wp_query->is_search() || (int) $wp_query->get( 'paged' ) > 1 ) {
			return null;
		}
		$vars = $wp_query->query_vars;
		unset( $vars['paged'] );
		$vars['fields']         = 'ids';
		$vars['posts_per_page'] = 1;
		$vars['no_found_rows']  = false;
		$other                  = self::is_mysql() ? 'meilisearch' : 'mysql';
		$vars['meilisearch']    = 'meilisearch' === $other; // Secondary queries are only intercepted when opted in.
		return Recorder::instance()->pause(
			static function () use ( $vars, $other ): array {
				$start = microtime( true );
				$query = new \WP_Query( $vars );
				return array(
					'engine' => $other,
					'hits'   => (int) $query->found_posts,
					'ms'     => ( microtime( true ) - $start ) * 1000,
				);
			}
		);
	}
}
