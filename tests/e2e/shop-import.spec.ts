import { expect, test } from '@playwright/test';
import { wp } from './utils';

const php = ( code: string ) => wp( 'shop', 'eval', code );

test( 'catalog: 1,450+ variable products with 12 variations each', () => {
	expect( Number( php( 'echo count( wc_get_products( [ "type" => "variable", "limit" => -1, "return" => "ids" ] ) );' ) ) ).toBeGreaterThanOrEqual( 1450 );
	expect( php( '$p = wc_get_product( wc_get_products( [ "limit" => 1, "return" => "ids" ] )[0] ); echo count( $p->get_children() );' ) ).toBe( '12' );
} );

test( 'attributes have the fixed IDs the theme relies on', () => {
	expect( php( '$a = wc_get_attribute_taxonomies(); usort( $a, fn( $x, $y ) => $x->attribute_id <=> $y->attribute_id ); foreach ( $a as $t ) { echo $t->attribute_id, ":", $t->attribute_name, " "; }' ) ).toBe( '1:department 2:artist 3:century 4:medium 5:size 6:finish' );
} );

test( 'some products are on sale, some variations are out of stock, ratings exist', () => {
	expect( Number( php( 'echo count( wc_get_product_ids_on_sale() );' ) ) ).toBeGreaterThan( 50 );
	expect( Number( php( 'echo count( wc_get_products( [ "type" => "variation", "stock_status" => "outofstock", "limit" => -1, "return" => "ids" ] ) );' ) ) ).toBeGreaterThan( 100 );
	expect( Number( php( 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = \'_wc_average_rating\' AND meta_value >= 4" );' ) ) ).toBeGreaterThan( 0 );
} );

test( 'Meilisearch holds one product document per product', () => {
	const products = Number( php( 'echo count( wc_get_products( [ "limit" => -1, "return" => "ids", "status" => "publish" ] ) );' ) );
	const status = JSON.parse( wp( 'shop', 'meilisearch', 'status', '--format=json' ) ) as Array<{ index: string; documents: string }>;
	expect( Number( status.find( ( row ) => row.index === 'products' )?.documents ) ).toBe( products );
} );

test( 'a product page credits The Met', async ( { page } ) => {
	await page.goto( php( 'echo get_permalink( wc_get_products( [ "limit" => 1, "return" => "ids" ] )[0] );' ) );
	await expect( page.getByRole( 'link', { name: 'View the original at The Met' } ) ).toHaveAttribute( 'href', /metmuseum\.org/ );
} );
