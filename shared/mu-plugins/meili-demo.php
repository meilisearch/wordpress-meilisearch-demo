<?php
/**
 * Plugin Name: Meilisearch demo layer
 * Description: Demo-only extras for the Meilisearch for WordPress showcase (request panel, MySQL comparison, try chips, facet counts, hardening). Not part of the plugin.
 */
defined( 'ABSPATH' ) || exit;

foreach ( glob( __DIR__ . '/meili-demo/src/*.php' ) as $file ) {
	require_once $file;
}
MeiliDemo\Recorder::instance()->register();
MeiliDemo\Engine::register();
add_action( 'init', array( MeiliDemo\UnderTheHood::class, 'register' ) );
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'meili-demo', content_url( 'mu-plugins/meili-demo/assets/demo.css' ), array(), (string) filemtime( __DIR__ . '/meili-demo/assets/demo.css' ) );
		if ( is_search() ) {
			wp_enqueue_script( 'meili-demo', content_url( 'mu-plugins/meili-demo/assets/demo.js' ), array(), (string) filemtime( __DIR__ . '/meili-demo/assets/demo.js' ), array( 'strategy' => 'defer' ) );
		}
	}
);
MeiliDemo\Documents::register();
MeiliDemo\Hardening::register();
add_action(
	'init',
	static function (): void {
		MeiliDemo\TryChips::register();
		MeiliDemo\Facets::register();
	}
);
