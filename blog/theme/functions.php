<?php
/**
 * Mission Log theme setup.
 */
add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'editor-styles' );
	}
);
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'mission-log', get_stylesheet_uri(), array(), (string) filemtime( get_stylesheet_directory() . '/style.css' ) );
	}
);
