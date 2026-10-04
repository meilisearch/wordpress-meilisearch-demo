<?php
namespace MeiliDemo;

/**
 * Block meili-demo/under-the-hood: the request the plugin sent for this page, where it came from, both engines.
 * It renders as a hidden drawer; block meili-demo/info-button, in the site header, opens it (assets/demo.js).
 */
final class UnderTheHood {
	public const ID = 'meili-demo-uth';

	public static function register(): void {
		register_block_type( 'meili-demo/under-the-hood', array( 'render_callback' => array( self::class, 'render' ) ) );
		register_block_type( 'meili-demo/info-button', array( 'render_callback' => array( self::class, 'button' ) ) );
	}

	/** The (i) toggle, only on search pages, where the drawer exists. */
	public static function button(): string {
		if ( ! is_search() ) {
			return '';
		}
		$label = esc_attr__( 'Under the hood', 'meili-demo' );
		return sprintf(
			'<button type="button" class="demo-info" aria-controls="%1$s" aria-expanded="false" aria-label="%2$s" title="%2$s">'
			. '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">'
			. '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg></button>',
			self::ID,
			$label
		);
	}

	public static function render(): string {
		if ( ! is_search() ) {
			return '';
		}
		$recorder = Recorder::instance();
		$calls    = $recorder->calls();
		$compare  = Engine::compare();
		// Served by Meilisearch only when the plugin actually answered the main query: a failed call is still
		// recorded, but WordPress then runs MySQL (and the plugin's circuit breaker opens).
		$mysql    = Engine::is_mysql() || true !== $recorder->intercepted() || array() === $calls;
		$here     = remove_query_arg( 'engine' );

		ob_start();
		printf(
			'<aside id="%1$s" class="uth" aria-labelledby="%1$s-title" hidden><div class="uth__head"><h2 id="%1$s-title">%2$s</h2><button type="button" class="uth__close" aria-label="%3$s">&times;</button></div>',
			esc_attr( self::ID ),
			esc_html__( 'Under the hood', 'meili-demo' ),
			esc_attr__( 'Close', 'meili-demo' )
		);
		if ( $mysql ) {
			echo '<p class="uth__sub"><strong>' . esc_html__( 'Served by MySQL', 'meili-demo' ) . '</strong>: ' . esc_html( self::reason( $calls ) ) . '</p>';
		} else {
			echo '<p class="uth__sub">' . esc_html__( 'What the plugin sent to Meilisearch for this WordPress search', 'meili-demo' ) . '</p>';
			foreach ( $calls as $call ) {
				echo '<pre class="uth__req"><span class="m">' . esc_html( $call['method'] . ' ' . $call['path'] ) . "</span>\n" . JsonHighlighter::render( $call['body'] ) . '</pre>'; // JsonHighlighter output is escaped.
			}
		}
		$source = array();
		foreach ( $recorder->query_vars() as $name => $value ) {
			$source[] = $name . '=' . ( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
		}
		if ( $source ) {
			echo '<p class="uth__source">' . esc_html__( 'From WP_Query:', 'meili-demo' ) . ' <code>' . esc_html( implode( ', ', $source ) ) . '</code></p>';
		}

		$tiles = array();
		if ( ! $mysql ) {
			$first             = $calls[0];
			$tiles['meilisearch'] = array( (int) $first['hits'], null !== $first['processing_ms'] ? (float) $first['processing_ms'] : $first['wall_ms'] );
		} else {
			global $wp_query;
			$tiles['mysql'] = array( (int) $wp_query->found_posts, (float) ( $recorder->main_ms() ?? 0 ) );
		}
		if ( $compare ) {
			$tiles[ $compare['engine'] ] = array( $compare['hits'], $compare['ms'] );
		}
		echo '<div class="uth__cmp">';
		foreach ( array( 'meilisearch' => 'Meilisearch', 'mysql' => 'MySQL' ) as $key => $label ) {
			if ( isset( $tiles[ $key ] ) ) {
				printf(
					'<div class="uth__tile uth__tile--%1$s">%2$s<b>%3$s</b></div>',
					esc_attr( $key ),
					esc_html( $label ),
					esc_html( sprintf( '%d hits · %s ms', $tiles[ $key ][0], number_format_i18n( $tiles[ $key ][1], 0 ) ) )
				);
			}
		}
		echo '</div>';
		printf(
			'<nav class="uth__toggle" aria-label="%1$s"><a href="%2$s"%3$s>Meilisearch</a><a href="%4$s"%5$s>%6$s</a></nav>',
			esc_attr__( 'Search engine', 'meili-demo' ),
			esc_url( $here ),
			Engine::is_mysql() ? '' : ' aria-current="page"',
			esc_url( add_query_arg( 'engine', 'mysql', $here ) ),
			Engine::is_mysql() ? ' aria-current="page"' : '',
			esc_html__( 'Compare with MySQL', 'meili-demo' )
		);
		echo '</aside>';
		return (string) ob_get_clean();
	}

	private static function reason( array $calls = array() ): string {
		if ( Engine::is_mysql() ) {
			return __( 'you asked for MySQL, so the plugin stepped aside and WordPress ran its own LIKE search.', 'meili-demo' );
		}
		foreach ( $calls as $call ) {
			if ( null !== ( $call['error'] ?? null ) || $call['status'] < 200 || $call['status'] >= 300 ) {
				$detail = null !== ( $call['error'] ?? null ) ? (string) $call['error'] : sprintf( 'HTTP %d', $call['status'] );
				/* translators: %s: error message or HTTP status. */
				return sprintf( __( 'Meilisearch returned an error on this request (%s), so the plugin fell back to MySQL and pauses Meilisearch for a minute.', 'meili-demo' ), $detail );
			}
		}
		if ( false !== get_transient( 'meilisearch_circuit_open' ) ) {
			return __( 'Meilisearch failed recently, so the circuit breaker sends searches to MySQL for a minute.', 'meili-demo' );
		}
		return __( 'the plugin could not translate this query (or the index is still being built), so WordPress ran it unchanged.', 'meili-demo' );
	}
}
