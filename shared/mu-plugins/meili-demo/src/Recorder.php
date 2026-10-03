<?php
namespace MeiliDemo;

/**
 * Records the Meilisearch search calls of this request exactly as sent (the plugin uses wp_remote_request, so
 * http_api_debug sees URL, args and response) plus the main query's vars. Never records headers.
 */
final class Recorder {
	private static ?Recorder $instance = null;
	/** @var list<array<string, mixed>> */
	private array $calls = array();
	private array $started = array();
	private array $vars = array();
	private ?float $main_start = null;
	private ?float $main_ms = null;
	private bool $paused = false;

	public static function instance(): Recorder {
		return self::$instance ??= new Recorder();
	}

	public function register(): void {
		add_filter( 'pre_http_request', array( $this, 'start' ), 10, 3 );
		add_action( 'http_api_debug', array( $this, 'finish' ), 10, 5 );
		add_action( 'pre_get_posts', array( $this, 'capture_vars' ), PHP_INT_MAX );
		add_filter( 'the_posts', array( $this, 'stop_main' ), PHP_INT_MAX, 2 );
	}

	public function pause( callable $fn ): mixed {
		$this->paused = true;
		try {
			return $fn();
		} finally {
			$this->paused = false;
		}
	}

	private function is_search_call( string $url ): bool {
		$host = defined( 'MEILISEARCH_HOST' ) ? rtrim( (string) MEILISEARCH_HOST, '/' ) : '';
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' !== $host && str_starts_with( $url, $host ) && 1 === preg_match( '#/(search|multi-search)$#', $path );
	}

	public function start( mixed $pre, array $args, string $url ): mixed {
		if ( ! $this->paused && $this->is_search_call( $url ) ) {
			$this->started[ $url ] = microtime( true );
		}
		return $pre;
	}

	public function finish( mixed $response, string $context, string $class, array $args, string $url ): void {
		if ( $this->paused || 'response' !== $context || ! $this->is_search_call( $url ) ) {
			return;
		}
		$body    = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : null;
		$decoded = is_array( $response ) ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
		$results = is_array( $decoded['results'] ?? null ) ? $decoded['results'] : ( is_array( $decoded ) ? array( $decoded ) : array() );
		$hits    = null;
		$ms      = null;
		foreach ( $results as $result ) {
			$hits = ( $hits ?? 0 ) + (int) ( $result['totalHits'] ?? $result['estimatedTotalHits'] ?? 0 );
			$ms   = max( $ms ?? 0, (int) ( $result['processingTimeMs'] ?? 0 ) );
		}
		if ( isset( $decoded['processingTimeMs'] ) && ! isset( $decoded['results'] ) ) {
			$ms = (int) $decoded['processingTimeMs'];
		}
		$this->calls[] = array(
			'method'        => strtoupper( (string) ( $args['method'] ?? 'POST' ) ),
			'path'          => (string) wp_parse_url( $url, PHP_URL_PATH ),
			'body'          => is_array( $body ) ? $body : null,
			'status'        => is_array( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0,
			'processing_ms' => $ms,
			'hits'          => $hits,
			'wall_ms'       => isset( $this->started[ $url ] ) ? ( microtime( true ) - $this->started[ $url ] ) * 1000 : 0.0,
		);
	}

	public function capture_vars( \WP_Query $query ): void {
		if ( $this->paused || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		$keep = array( 's', 'paged', 'post_type', 'category_name', 'cat', 'tag', 'product_cat', 'product_tag', 'orderby', 'order', 'min_price', 'max_price', 'published' );
		foreach ( $query->query_vars as $name => $value ) {
			if ( in_array( $name, $keep, true ) || str_starts_with( $name, 'filter_' ) || str_starts_with( $name, 'query_type_' ) ) {
				if ( '' !== $value && null !== $value && array() !== $value && 0 !== $value ) {
					$this->vars[ $name ] = $value;
				}
			}
		}
		foreach ( $_GET as $name => $value ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only display.
			if ( is_string( $value ) && ( str_starts_with( $name, 'filter_' ) || str_starts_with( $name, 'query_type_' ) || in_array( $name, array( 'min_price', 'max_price', 'orderby' ), true ) ) ) {
				$this->vars[ $name ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}
		$this->main_start = microtime( true );
	}

	public function stop_main( array $posts, \WP_Query $query ): array {
		if ( null !== $this->main_start && null === $this->main_ms && $query->is_main_query() ) {
			$this->main_ms = ( microtime( true ) - $this->main_start ) * 1000;
		}
		return $posts;
	}

	public function calls(): array { return $this->calls; }
	public function query_vars(): array { return $this->vars; }
	public function main_ms(): ?float { return $this->main_ms; }
}
