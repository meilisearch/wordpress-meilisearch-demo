<?php
/**
 * Title: Topic navigation
 * Slug: mission-log/topic-nav
 * Inserter: no
 */
$topics = array( 'missions', 'space-station', 'earth', 'solar-system', 'universe', 'history' );
echo '<nav class="ml-topics" aria-label="' . esc_attr__( 'Topics', 'mission-log' ) . '"><ul>';
foreach ( $topics as $slug ) {
	$term = get_term_by( 'slug', $slug, 'category' );
	if ( $term ) {
		printf( '<li><a href="%s">%s</a></li>', esc_url( get_term_link( $term ) ), esc_html( $term->name ) );
	}
}
echo '</ul></nav>';
