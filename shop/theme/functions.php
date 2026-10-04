<?php
/**
 * Met Prints theme setup.
 */
add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'woocommerce' );
		add_theme_support( 'wc-product-gallery-zoom' );
		add_theme_support( 'wc-product-gallery-lightbox' );
	}
);
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'met-prints', get_stylesheet_uri(), array(), (string) filemtime( get_stylesheet_directory() . '/style.css' ) );
	}
);
