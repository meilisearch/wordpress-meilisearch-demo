import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { wp } from './utils';

const panel = ( page ) => page.locator( '.uth' );

const a11y = ( page ) =>
	// Two upstream WooCommerce markup issues, not this theme's: the mini-cart drawer keeps a focusable close
	// button inside an aria-hidden drawer, and the rating filter puts aria-label on a role-less <span>.
	new AxeBuilder( { page } )
		.exclude( '.wc-block-mini-cart__drawer' )
		.exclude( '.wc-block-components-drawer__screen-overlay' )
		.exclude( '.wc-block-product-filter-checkbox-list__stars' );

test( 'home: hero, collector favorites, store notice', async ( { page } ) => {
	await page.goto( '/' );
	await expect( page.locator( '.mp-hero h1' ) ).toBeVisible();
	await expect( page.locator( '.mp-grid .wc-block-product' ) ).toHaveCount( 8 );
	await expect( page.getByText( 'Demo store: no orders are taken' ) ).toBeVisible();
	await expect( page.getByRole( 'contentinfo' ) ).toContainText( 'The Metropolitan Museum of Art, Open Access (CC0). Not affiliated with The Met.' );
} );

test( 'product search with department filter, max price and price sort is served by Meilisearch', async ( { page } ) => {
	await page.goto( '/?s=van+gof&post_type=product&filter_department=european-paintings&max_price=80&orderby=price' );
	await expect( page.locator( '.mp-serp .wc-block-product' ).first() ).toBeVisible();
	await expect( panel( page ) ).toContainText( '/indexes/shop_products/search' );
	await expect( panel( page ) ).toContainText( 'attr_department' );
	await expect( panel( page ) ).toContainText( 'price <= 80' );
	await expect( panel( page ) ).toContainText( '"price:asc"' );
} );

test( 'the filter blocks produce URLs the plugin intercepts', async ( { page } ) => {
	await page.goto( '/?s=landscape&post_type=product' );
	await page.locator( '.mp-filters' ).getByRole( 'checkbox', { name: /European Paintings/ } ).check();
	await page.waitForURL( /filter_department=/ );
	await expect( panel( page ) ).not.toContainText( 'Served by MySQL' );
	await expect( panel( page ) ).toContainText( 'attr_department' );
} );

test( 'untranslatable filter falls back visibly', async ( { page } ) => {
	// A random order has no Meilisearch equivalent, so the plugin declines it (ProductQueryTranslator::sort()):
	// WordPress must still answer, and the panel must say so.
	await page.goto( '/?s=landscape&post_type=product&orderby=rand' );
	await expect( page.locator( 'main' ) ).toBeVisible();
	await expect( panel( page ) ).toContainText( 'Served by MySQL' );
} );

test( 'variable product page shows size and finish and updates the price', async ( { page } ) => {
	await page.goto( wp( 'shop', 'eval', 'echo get_permalink( wc_get_product_id_by_sku( "MET-436535" ) ?: wc_get_products( [ "limit" => 1, "return" => "ids" ] )[0] );' ) );
	await page.getByLabel( 'Size' ).selectOption( { index: 2 } );
	await page.getByLabel( 'Finish' ).selectOption( { label: 'Oak frame' } );
	await expect( page.locator( '.woocommerce-variation-price' ) ).toContainText( '$' );
} );

test( 'no serious accessibility violations on shop pages', async ( { page } ) => {
	for ( const path of [ '/', '/?s=hokusai&post_type=product' ] ) {
		await page.goto( path );
		const results = await a11y( page ).analyze();
		const serious = results.violations.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' );
		expect( serious, `${ path }: ${ JSON.stringify( serious, null, 2 ) }` ).toEqual( [] );
	}
} );
