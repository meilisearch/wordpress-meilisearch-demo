<?php
namespace MeiliDemo;

/**
 * Public-demo hardening: no XML-RPC, no anonymous user listing, no author archives, no new comments, no orders.
 * With MEILI_DEMO_LOCKDOWN (the Fly deploy), no one can log in over HTTP: WP-CLI is the only way in.
 */
final class Hardening {
	public static function register(): void {
		// Author archives and the users sitemap give away login names (?author=1 redirects to the admin's).
		add_filter( 'wp_sitemaps_add_provider', static fn ( $provider, string $name ) => 'users' === $name ? false : $provider, 10, 2 );
		add_action( 'template_redirect', array( self::class, 'not_found_if_hidden' ), 1 );
		if ( self::locked() ) {
			add_filter( 'authenticate', static fn () => new \WP_Error( 'demo_locked', 'Logins are off on this demo.' ), 100 );
			add_filter( 'determine_current_user', static fn () => 0, 100 );
			add_filter( 'wp_is_application_passwords_available', '__return_false' );
			add_filter( 'pre_option_woocommerce_enable_checkout_login_reminder', static fn (): string => 'no' );
		}
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
		// REST routes match case-insensitively, so compare a normalised route.
		$route = strtolower( untrailingslashit( $request->get_route() ) );
		if ( str_starts_with( $route, '/wp/v2/users' ) && ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', 'Not available on this demo.', array( 'status' => 401 ) );
		}
		// Checkout, directly or inside a batch (the mini-cart's cart batches keep working): no order is ever created.
		if ( self::is_checkout_route( $route ) || ( 1 === preg_match( '#^/wc/store(/v\d+)?/batch\b#', $route ) && self::batch_has_checkout( $request ) ) ) {
			return new \WP_Error( 'demo_store', 'Demo store: no orders are taken', array( 'status' => 403 ) );
		}
		return $result;
	}


	public static function locked(): bool {
		return defined( 'MEILI_DEMO_LOCKDOWN' ) && '1' === (string) MEILI_DEMO_LOCKDOWN && ! ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/** Runs before redirect_canonical, which would otherwise send ?author=1 to /author/<login>/. */
	public static function not_found_if_hidden(): void {
		$account = self::locked() && function_exists( 'is_account_page' ) && is_account_page(); // WooCommerce's login form.
		if ( is_author() || $account ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	private static function is_checkout_route( string $route ): bool {
		return 1 === preg_match( '#^/wc/store(/v\d+)?/checkout\b#', strtolower( $route ) );
	}

	private static function batch_has_checkout( \WP_REST_Request $request ): bool {
		$requests = $request->get_param( 'requests' );
		foreach ( is_array( $requests ) ? $requests : array() as $sub ) {
			$path = is_array( $sub ) && isset( $sub['path'] ) ? (string) wp_parse_url( (string) $sub['path'], PHP_URL_PATH ) : '';
			if ( self::is_checkout_route( untrailingslashit( $path ) ) ) {
				return true;
			}
		}
		return false;
	}
}
