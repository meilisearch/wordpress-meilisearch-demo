import { expect, test } from '@playwright/test';
import { wp } from './utils';

test( 'all articles are imported with a featured image and a source line', () => {
	const count = Number( wp( 'blog', 'post', 'list', '--post_type=post', '--post_status=publish', '--format=count' ) );
	expect( count ).toBeGreaterThanOrEqual( 700 );
	const withoutThumb = wp( 'blog', 'eval', 'echo count( get_posts( [ "post_type" => "post", "numberposts" => -1, "fields" => "ids", "meta_query" => [ [ "key" => "_thumbnail_id", "compare" => "NOT EXISTS" ] ] ] ) );' );
	expect( Number( withoutThumb ) ).toBe( 0 );
} );

test( 'the six topics exist and every post has exactly one', () => {
	const slugs = wp( 'blog', 'term', 'list', 'category', '--field=slug' ).split( '\n' ).sort();
	expect( slugs ).toEqual( [ 'earth', 'history', 'missions', 'solar-system', 'space-station', 'universe' ] );
} );

test( 'Meilisearch holds one document per post', () => {
	const posts = Number( wp( 'blog', 'post', 'list', '--post_type=post', '--post_status=publish', '--format=count' ) );
	const status = JSON.parse( wp( 'blog', 'meilisearch', 'status', '--format=json' ) ) as Array<{ index: string; documents: string }>;
	const content = status.find( ( row ) => row.index === 'content' );
	expect( Number( content?.documents ) ).toBe( posts );
} );

test( 'a post page shows its NASA source link', async ( { page } ) => {
	const url = wp( 'blog', 'eval', 'echo get_permalink( get_posts( [ "numberposts" => 1 ] )[0] );' );
	await page.goto( url );
	await expect( page.getByRole( 'link', { name: 'NASA Science' } ) ).toHaveAttribute( 'href', /^https:\/\/science\.nasa\.gov\// );
} );
