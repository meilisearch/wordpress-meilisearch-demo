<?php
use MeiliDemo\JsonHighlighter;

final class JsonHighlighterTest {
	public static function test_keys_strings_numbers_are_wrapped(): void {
		$html = JsonHighlighter::render( array( 'q' => 'saturn', 'page' => 1, 'ok' => true ) );
		check( str_contains( $html, '<span class="k">&quot;q&quot;</span>' ), $html );
		check( str_contains( $html, '<span class="s">&quot;saturn&quot;</span>' ), $html );
		check( str_contains( $html, '<span class="n">1</span>' ), $html );
		check( str_contains( $html, '<span class="n">true</span>' ), $html );
	}

	public static function test_hostile_strings_stay_inert(): void {
		$html = JsonHighlighter::render( array( 'q' => '<script>alert("x")</script> "quoted" 🚀' ) );
		check( ! str_contains( $html, '<script>' ), $html );
		check( str_contains( $html, '&lt;script&gt;' ), $html );
		check( str_contains( $html, '🚀' ), 'emoji kept unescaped as UTF-8' );
	}

	public static function test_filter_strings_with_escaped_quotes_are_one_token(): void {
		$html = JsonHighlighter::render( array( 'filter' => 'tax_category_ids IN [14] AND title = "a \"b\""' ) );
		check( 1 === substr_count( $html, '<span class="s">' ), $html );
	}
}
