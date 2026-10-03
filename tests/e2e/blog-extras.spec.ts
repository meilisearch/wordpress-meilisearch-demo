import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const chips = JSON.parse( readFileSync( join( __dirname, '../../blog/try-chips.json' ), 'utf8' ) ) as Array<{ q: string; why: string }>;

for ( const chip of chips ) {
	test( `try chip "${ chip.q }" returns results`, async ( { page } ) => {
		await page.goto( '/?s=' + encodeURIComponent( chip.q ) );
		await expect( page.locator( '.ml-result' ).first() ).toBeVisible();
	} );
}

test( 'chips link to their search', async ( { page } ) => {
	await page.goto( '/?s=mars' );
	await page.locator( '.demo-chips' ).getByRole( 'link', { name: 'jupitr moons' } ).click();
	await expect( page ).toHaveURL( /s=jupitr\+moons|s=jupitr%20moons/ );
} );

test( 'topic facets show counts and filter through the plugin', async ( { page } ) => {
	await page.goto( '/?s=moon' );
	const topics = page.locator( '.demo-facets' ).getByRole( 'list', { name: 'Topic' } );
	await expect( topics.getByRole( 'link', { name: /Solar System\s+\d+/ } ) ).toBeVisible();
	await topics.getByRole( 'link', { name: /Solar System/ } ).click();
	await expect( page.locator( '.uth' ) ).toContainText( 'tax_category_ids' );
} );

test( 'a year link becomes a date range the plugin translates', async ( { page } ) => {
	await page.goto( '/?s=moon' );
	const years = page.locator( '.demo-facets' ).getByRole( 'list', { name: 'Year' } );
	await years.getByRole( 'link', { name: /^\d{4}/ } ).first().click();
	await expect( page ).toHaveURL( /published=\d{4}/ );
	await expect( page.locator( '.uth' ) ).not.toContainText( 'Served by MySQL' );
	await expect( page.locator( '.uth' ) ).toContainText( 'date >=' );
} );

test( 'autocomplete shows topic · date subtitles and a See all footer', async ( { page } ) => {
	await page.goto( '/' );
	const input = page.getByRole( 'combobox' ).first();
	await input.pressSequentially( 'artem', { delay: 60 } );
	const option = page.getByRole( 'option' ).first();
	await expect( option.locator( '.meilisearch-ac__subtitle' ) ).toHaveText( /^[A-Z][\w ]+ · [A-Z][a-z]{2} \d{1,2}, \d{4}$/ );
	await page.getByRole( 'option', { name: /See all \d+ results/ } ).click();
	await expect( page ).toHaveURL( /s=artem/ );
} );

test( 'xmlrpc and user enumeration are closed', async ( { request } ) => {
	const xmlrpc = await request.post( '/xmlrpc.php', { data: '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName></methodCall>' } );
	expect( await xmlrpc.text() ).not.toContain( 'wp.getUsersBlogs' );
	const users = await request.get( '/wp-json/wp/v2/users' );
	expect( users.status() ).toBe( 401 );
} );
