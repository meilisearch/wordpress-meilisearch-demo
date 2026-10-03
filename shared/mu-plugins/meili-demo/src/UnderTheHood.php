<?php
namespace MeiliDemo;

/** Block meili-demo/under-the-hood: the request the plugin sent for this page, where it came from, both engines. */
final class UnderTheHood {
	public static function register(): void {
		register_block_type( 'meili-demo/under-the-hood', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	public static function render(): string {
		if ( ! is_search() ) {
			return '';
		}
		$recorder = Recorder::instance();
		$calls    = $recorder->calls();
		$compare  = Engine::compare();
		$mysql    = Engine::is_mysql() || array() === $calls;
		$here     = remove_query_arg( 'engine' );

		ob_start();
		echo '<details class="uth" open><summary>' . esc_html__( 'Under the hood', 'meili-demo' ) . '</summary>';
		if ( $mysql ) {
			echo '<p class="uth__sub"><strong>' . esc_html__( 'Served by MySQL', 'meili-demo' ) . '</strong>: ' . esc_html( self::reason() ) . '</p>';
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
		echo '</details>';
		return (string) ob_get_clean();
	}

	private static function reason(): string {
		if ( Engine::is_mysql() ) {
			return __( 'you asked for MySQL, so the plugin stepped aside and WordPress ran its own LIKE search.', 'meili-demo' );
		}
		if ( false !== get_transient( 'meilisearch_circuit_open' ) ) {
			return __( 'Meilisearch failed recently, so the circuit breaker sends searches to MySQL for a minute.', 'meili-demo' );
		}
		return __( 'the plugin could not translate this query (or the index is still being built), so WordPress ran it unchanged.', 'meili-demo' );
	}
}
