<?php
// php tests/php/run.php — runs every tests/php/*Test.php; each test is a public static function test_*().
require __DIR__ . '/../../shared/mu-plugins/meili-demo/src/JsonHighlighter.php';
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
$failures = 0;
foreach ( glob( __DIR__ . '/*Test.php' ) as $file ) {
	require $file;
	$class = basename( $file, '.php' );
	foreach ( get_class_methods( $class ) as $method ) {
		if ( ! str_starts_with( $method, 'test_' ) ) { continue; }
		try { $class::$method(); echo "ok   $class::$method\n"; }
		catch ( Throwable $e ) { $failures++; echo "FAIL $class::$method: {$e->getMessage()}\n"; }
	}
}
exit( $failures ? 1 : 0 );
function check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
