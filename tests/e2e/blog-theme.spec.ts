import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { wp } from './utils';

test( 'home: header topics, hero from a sticky post, latest grid', async ( { page } ) => {
	await page.goto( '/' );
	const nav = page.getByRole( 'navigation', { name: 'Topics' } );
	for ( const name of [ 'Missions', 'Space Station', 'Earth', 'Solar System', 'Universe', 'History' ] ) {
		await expect( nav.getByRole( 'link', { name } ) ).toBeVisible();
	}
	await expect( page.locator( '.ml-hero h1' ) ).toBeVisible();
	await expect( page.locator( '.ml-grid .ml-card' ) ).toHaveCount( 8 );
	await expect( page.getByRole( 'contentinfo' ) ).toContainText( 'Content: NASA (public domain). Not endorsed by NASA.' );
} );

test( 'search page uses the theme layout and highlighted excerpts', async ( { page } ) => {
	await page.goto( '/?s=saturn+rings' );
	await expect( page.locator( '.ml-serp' ) ).toBeVisible();
	await expect( page.locator( '.ml-result' ).first() ).toBeVisible();
	await expect( page.locator( '.ml-result mark' ).first() ).toBeVisible();
} );

test( 'no serious accessibility violations on home, search and an article', async ( { page } ) => {
	const article = wp( 'blog', 'eval', 'echo get_permalink( get_posts( [ "numberposts" => 1 ] )[0] );' );
	for ( const path of [ '/', '/?s=mars', article ] ) {
		await page.goto( path );
		const results = await new AxeBuilder( { page } ).analyze();
		const serious = results.violations.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' );
		expect( serious, `${ path }: ${ JSON.stringify( serious, null, 2 ) }` ).toEqual( [] );
	}
} );
