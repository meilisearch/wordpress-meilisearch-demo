import { expect, test } from '@playwright/test';
import { wp } from './utils';

const panel = ( page ) => page.locator( '.uth' );

test( 'the panel shows the request the plugin sent', async ( { page } ) => {
	await page.goto( '/?s=saturn+rngs' );
	await expect( panel( page ) ).toContainText( 'POST /indexes/blog_content/search' );
	await expect( panel( page ) ).toContainText( '"saturn rngs"' );
	await expect( panel( page ).locator( '.uth__cmp' ) ).toContainText( /Meilisearch\s*\d+ hits/ );
	await expect( panel( page ).locator( '.uth__source' ) ).toContainText( 's=saturn rngs' );
} );

test( 'a category link becomes a filter', async ( { page } ) => {
	await page.goto( '/?s=saturn&category_name=solar-system' );
	await expect( panel( page ) ).toContainText( 'tax_category_ids' );
} );

test( 'compare with MySQL switches engines', async ( { page } ) => {
	await page.goto( '/?s=saturn+rngs' );
	await panel( page ).getByRole( 'link', { name: 'Compare with MySQL' } ).click();
	await expect( page ).toHaveURL( /engine=mysql/ );
	await expect( panel( page ) ).toContainText( 'Served by MySQL' );
	await expect( panel( page ).locator( '.uth__cmp' ) ).toContainText( /MySQL\s*0 hits/ );
	await panel( page ).getByRole( 'link', { name: 'Meilisearch', exact: true } ).click();
	await expect( page ).not.toHaveURL( /engine=mysql/ );
} );

test( 'panel escapes the query', async ( { page } ) => {
	const hostile = `<img src=x onerror="window.__pwned=1">"quoted" 🚀 ${ 'x'.repeat( 250 ) }`;
	await page.goto( '/?s=' + encodeURIComponent( hostile ) );
	expect( await page.evaluate( () => ( window as unknown as { __pwned?: number } ).__pwned ) ).toBeUndefined();
	await expect( panel( page ) ).toContainText( 'quoted' );
	// WordPress core's emoji script turns the 🚀 into <img class="emoji">; anything else would be injected markup.
	await expect( panel( page ).locator( 'img:not(.emoji)' ) ).toHaveCount( 0 );
} );

test( 'panel never shows a key', async ( { page } ) => {
	await page.goto( '/?s=mars' );
	const html = await panel( page ).innerHTML();
	expect( html ).not.toMatch( /Bearer|Authorization|meili-wp-demo-master-key/i );
} );

test( 'panel says MySQL when the plugin declines', async ( { page } ) => {
	// Force the circuit breaker open: the plugin skips Meilisearch for 60 s.
	wp( 'blog', 'transient', 'set', 'meilisearch_circuit_open', '1', '60' );
	try {
		await page.goto( '/?s=mars' );
		await expect( page.locator( '.ml-result' ).first() ).toBeVisible();
		await expect( panel( page ) ).toContainText( 'Served by MySQL' );
		await expect( panel( page ) ).toContainText( 'circuit breaker' );
	} finally {
		wp( 'blog', 'transient', 'delete', 'meilisearch_circuit_open' );
	}
} );
