import { expect, test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

const chips = JSON.parse( readFileSync( join( __dirname, '../../shop/try-chips.json' ), 'utf8' ) ) as Array<{ q: string; why: string }>;
import { wp } from './utils';

for ( const chip of chips ) {
	test( `try chip "${ chip.q }" returns products`, async ( { page } ) => {
		await page.goto( '/?post_type=product&s=' + encodeURIComponent( chip.q ) );
		await expect( page.locator( '.mp-serp .wc-block-product' ).first() ).toBeVisible();
	} );
}

test( 'autocomplete shows artist · date subtitles and prices', async ( { page } ) => {
	await page.goto( '/' );
	await page.getByRole( 'combobox' ).first().pressSequentially( 'van go', { delay: 60 } );
	const option = page.getByRole( 'option', { name: /Vincent van Gogh/ } ).first();
	await expect( option.locator( '.meilisearch-ac__subtitle' ) ).toContainText( 'Vincent van Gogh ·' );
	await expect( option.locator( '.meilisearch-ac__price' ) ).toContainText( '$' );
} );

test( 'checkout never creates an order', async ( { page, request } ) => {
	const before = Number( wp( 'shop', 'eval', 'echo count( wc_get_orders( [ "limit" => -1, "status" => array_merge( array_keys( wc_get_order_statuses() ), [ "checkout-draft" ] ), "return" => "ids" ] ) );' ) );
	// An in-stock variation, added through WooCommerce's add-to-cart URL (no race with the variation form's JS).
	const addUrl = wp( 'shop', 'eval', '$v = wc_get_products( [ "type" => "variation", "stock_status" => "instock", "limit" => 1 ] )[0]; $q = [ "add-to-cart" => $v->get_parent_id(), "variation_id" => $v->get_id() ]; foreach ( $v->get_attributes() as $k => $val ) { $q[ "attribute_" . $k ] = $val; } echo add_query_arg( $q, get_permalink( $v->get_parent_id() ) );' );
	await page.goto( addUrl );
	await expect( page.locator( '.woocommerce-message, .wc-block-components-notice-banner' ).first() ).toBeVisible();
	await page.goto( '/checkout/' );
	await expect( page.getByRole( 'heading', { name: 'Checkout is closed' } ) ).toBeVisible();
	for ( const path of [ '/wp-json/wc/store/v1/checkout', '/wp-json/wc/store/v1/Checkout', '/?rest_route=/wc/store/v1/CHECKOUT' ] ) {
		expect( ( await request.post( path, { data: {} } ) ).status(), path ).toBe( 403 );
	}
	// The batch route can carry a checkout request too.
	const batch = await request.post( '/wp-json/wc/store/v1/batch', { data: { requests: [ { method: 'POST', path: '/wc/store/v1/checkout', body: {} } ] } } );
	expect( batch.status() ).toBe( 403 );
	// Cart batches (the mini-cart) are not blocked.
	const cartBatch = await request.post( '/wp-json/wc/store/v1/batch', { data: { requests: [ { method: 'GET', path: '/wc/store/v1/cart', body: {} } ] } } );
	expect( cartBatch.status() ).not.toBe( 403 );
	const after = Number( wp( 'shop', 'eval', 'echo count( wc_get_orders( [ "limit" => -1, "status" => array_merge( array_keys( wc_get_order_statuses() ), [ "checkout-draft" ] ), "return" => "ids" ] ) );' ) );
	expect( after ).toBe( before );
} );
