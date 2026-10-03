<?php
namespace MeiliDemo;

/** Block meili-demo/try-chips: example searches from /opt/demo/site/try-chips.json. */
final class TryChips {
	public static function register(): void {
		register_block_type( 'meili-demo/try-chips', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	public static function render(): string {
		$file  = '/opt/demo/site/try-chips.json';
		$chips = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array();
		if ( ! is_array( $chips ) || array() === $chips ) {
			return '';
		}
		$extra = 'shop' === getenv( 'SITE' ) ? array( 'post_type' => 'product' ) : array();
		$items = '';
		foreach ( $chips as $chip ) {
			$url    = add_query_arg( array_merge( array( 's' => rawurlencode( (string) $chip['q'] ) ), $extra ), home_url( '/' ) );
			$items .= sprintf( '<li><a href="%1$s" title="%2$s">%3$s</a></li>', esc_url( $url ), esc_attr( (string) $chip['why'] ), esc_html( (string) $chip['q'] ) );
		}
		return '<nav class="demo-chips" aria-label="' . esc_attr__( 'Try a search', 'meili-demo' ) . '"><span>' . esc_html__( 'Try:', 'meili-demo' ) . '</span><ul>' . $items . '</ul></nav>';
	}
}
