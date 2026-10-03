<?php
namespace MeiliDemo;

/** Public-demo hardening: no XML-RPC, no anonymous user listing, no new comments, no orders. */
final class Hardening {
	public static function register(): void {
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', static fn (): array => array() );
		add_filter( 'pings_open', '__return_false' );
		add_filter( 'comments_open', '__return_false' );
		add_filter( 'wp_headers', static function ( array $headers ): array {
			unset( $headers['X-Pingback'] );
			return $headers;
		} );
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest' ), 10, 3 );
		add_action( 'woocommerce_checkout_process', static function (): void {
			wc_add_notice( __( 'Demo store: no orders are taken', 'meili-demo' ), 'error' );
		} );
	}

	public static function rest( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		$route = $request->get_route();
		if ( str_starts_with( $route, '/wp/v2/users' ) && ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', 'Not available on this demo.', array( 'status' => 401 ) );
		}
		if ( str_starts_with( $route, '/wc/store/v1/checkout' ) || str_starts_with( $route, '/wc/store/checkout' ) ) {
			return new \WP_Error( 'demo_store', 'Demo store: no orders are taken', array( 'status' => 403 ) );
		}
		return $result;
	}
}
