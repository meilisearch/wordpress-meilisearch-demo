import { defineConfig, devices } from '@playwright/test';

// Locally the sites and Meilisearch are reached through OrbStack names. In CI, set BLOG_URL/SHOP_URL to
// localhost ports and E2E_MAP_MEILISEARCH=1: the plugin then points at http://meilisearch:7700 and Chrome
// maps that name to the port Compose publishes.
const args = process.env.E2E_MAP_MEILISEARCH ? [ '--host-resolver-rules=MAP meilisearch 127.0.0.1' ] : [];

export default defineConfig( {
	testDir: 'tests/e2e',
	timeout: 30_000,
	expect: { timeout: 10_000 },
	retries: process.env.CI ? 1 : 0,
	// One worker: the local stack is small, and parallel browsers make searches time out (the plugin then
	// correctly falls back to MySQL, which the tests would read as failures).
	workers: 1,
	reporter: process.env.CI ? [ [ 'github' ], [ 'html', { open: 'never' } ] ] : 'list',
	use: { trace: 'retain-on-failure', screenshot: 'only-on-failure' },
	projects: [
		{
			name: 'blog',
			testMatch: /blog.*\.spec\.ts/,
			use: { ...devices[ 'Desktop Chrome' ], baseURL: process.env.BLOG_URL ?? 'http://blog.wordpress-meilisearch-demo.orb.local', launchOptions: { args } },
		},
		{
			name: 'shop',
			testMatch: /shop.*\.spec\.ts/,
			use: { ...devices[ 'Desktop Chrome' ], baseURL: process.env.SHOP_URL ?? 'http://shop.wordpress-meilisearch-demo.orb.local', launchOptions: { args } },
		},
	],
} );
