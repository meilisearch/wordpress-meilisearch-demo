<?php
namespace MeiliDemo;

/** Pretty-prints a value as JSON and wraps tokens in spans (k = key, s = string, n = number/bool/null). Output is escaped HTML. */
final class JsonHighlighter {
	public static function render( mixed $data ): string {
		$json = (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$out  = '';
		preg_match_all( '/("(?:\\\\.|[^"\\\\])*")(\s*:)?|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null)|([\s\S])/u', $json, $tokens, PREG_SET_ORDER );
		foreach ( $tokens as $t ) {
			if ( '' !== ( $t[1] ?? '' ) ) {
				$class = '' !== ( $t[2] ?? '' ) ? 'k' : 's';
				$out  .= '<span class="' . $class . '">' . esc_html( $t[1] ) . '</span>' . esc_html( $t[2] ?? '' );
			} elseif ( '' !== ( $t[3] ?? '' ) ) {
				$out .= '<span class="n">' . esc_html( $t[3] ) . '</span>';
			} else {
				$out .= esc_html( $t[4] ?? '' );
			}
		}
		return $out;
	}
}
