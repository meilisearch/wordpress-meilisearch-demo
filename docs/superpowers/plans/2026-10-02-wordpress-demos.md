# WordPress demos (Mission Log + Met Prints) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build two public showcase sites for the Meilisearch for WordPress plugin:
- **Mission Log**: a NASA articles magazine on plain WordPress.
- **Met Prints**: a WooCommerce print shop of Met Open Access artworks.

Both run locally with `docker compose watch` and deploy to Fly.io against Meilisearch Cloud. The plugin itself gets the small additions the demos need.

**Architecture:** One demo repo holds two sites.
- **Image:** a single Docker image, built with `SITE=blog|shop`, installs WordPress, the plugin (built from a local checkout with its own `bin/build-zip.sh`) and the site's custom block theme. A demo-only mu-plugin provides the Try chips, the Under the hood panel, the MySQL comparison, the blog facet counts and attribution.
- **Data:** content comes from committed snapshots produced by two deterministic Python scripts.
- **First boot:** an idempotent entrypoint installs the site, imports the data, connects the plugin and reindexes.
- **Plugin repo:** gains `wp meilisearch connect`, an autocomplete subtitle filter and a "See all results" footer.

**Tech Stack:**
- WordPress 6.9+, WooCommerce (pinned), PHP 8.3 (`wordpress:php8.3-apache`), MariaDB 11.4, WP-CLI.
- Meilisearch v1.34 locally (Meilisearch Cloud in production).
- Python 3.12 (requests, Pillow, pytest), Playwright + axe.
- Docker Compose watch (OrbStack), Fly.io, GitHub Actions, Git LFS.

**Spec:** `docs/superpowers/specs/2026-10-02-wordpress-demos-design.md` (the plan argues from it; read both). Approved visuals: `docs/mockups/demos.html`.

## Global Constraints

- **Repos and branches:**
  - Demo repo: `/Users/quentindequelen/Projects/Meilisearch/_demos/wordpress-meilisearch-demo` (branch `main`).
  - Plugin repo: `/Users/quentindequelen/Projects/Meilisearch/_sdk/meilisearch-wordpress`. Plugin tasks run in a new worktree, `.claude/worktrees/demo-support`, on branch `qdequele/demo-support`, created from `qdequele/integration-review-8c0cee` (PR #32 head, commit `8df10dc` or later).
- **Commits:** never add `Co-Authored-By` lines or any AI attribution to commits or PRs.
- **Host tooling:** the host has no PHP. Run every PHP/Composer command for the plugin in Docker with the plugin's established prefix:
  `docker run --rm -v "$PWD":/app -w /app -v meili-wp-composer-cache:/tmp/composer --add-host=host.docker.internal:host-gateway -e WP_TESTS_DB_HOST=host.docker.internal -e MEILISEARCH_TEST_HOST=http://host.docker.internal:7700 meili-wp-php81 <cmd>`
  The plugin's compose services `db` and `meilisearch` must be up: `docker compose up -d db meilisearch` in the plugin worktree.
- **Shell:** macOS has no `timeout` binary. Long commands run in the foreground with a tool timeout of at most 600000 ms.
- **Plugin requirements:** PHP ≥ 8.1, WordPress ≥ 6.9, Meilisearch ≥ 1.34. The demo pins `getmeili/meilisearch:v1.34` locally.
- **Plugin code style:** WordPress Coding Standards (`composer lint`) and PHPStan (`composer analyse`) must pass. Exception messages stay raw and are escaped once at display; every user-visible string is translatable with text domain `meilisearch`.
- **Plugin JS:** it must never insert HTML from the index; build DOM with `createElement`/`textContent` only.
- **Naming:** index prefixes are `blog` and `shop`; Fly apps `meili-wp-blog` and `meili-wp-shop`; OrbStack hosts `blog.wordpress-meilisearch-demo.orb.local` and `shop.wordpress-meilisearch-demo.orb.local`. Default ports: blog `8081`, shop `8082`, Meilisearch `7701` (the plugin repo's own compose already uses 7700).
- **Copy:**
  - Blog footer: "Content: NASA (public domain). Not endorsed by NASA."
  - Shop footer: "Images and data: The Metropolitan Museum of Art, Open Access (CC0). Not affiliated with The Met."
  - Store notice: "Demo store: no orders are taken".
- **Containers:** use `compose.yaml`, `Dockerfile` (production, multi-stage) and `Dockerfile.dev`. Source sync uses `develop.watch`, never bind mounts; named volumes are only for data. Every infrastructure service has a healthcheck, and the apps use `depends_on: condition: service_healthy`.
- **Data:** the scripts are deterministic, with a fixed seed of `20261002` and pinned ID manifests. Images are WebP, longest side 1600 px, quality 80, stored in Git LFS. The first boot makes no network calls to NASA or The Met.
- **Readiness:** the logs print `[demo] Ready` only after `wp meilisearch reindex` has completed for every active index.

## Review Focus

1. **A visitor searches before the index is populated.** For example, the first boot is still importing, or the reindex failed. The site must still answer through MySQL, and the panel must say "Served by MySQL" instead of breaking. Pinned by Task D7 Step 1, test `panel says MySQL when the plugin declines`.
2. **Hostile or odd search strings:** quotes, `<script>`, an emoji, 300 characters. The Under the hood panel must render them as inert text, and the recorder must never log the `Authorization` header. Pinned by Task D7 Step 1, tests `panel escapes the query` and `panel never shows a key`.
3. **A container restart after the first boot.** It must not re-import or duplicate posts and products, and changing `WP_ADMIN_PASSWORD` or `MEILISEARCH_INDEX_PREFIX` must take effect. Pinned by Task D1 Step 6, the restart check in `bin/smoke.sh`.
4. **A WooCommerce filter-block URL the plugin does not translate.** The shop must still show correct results through MySQL, and the panel must say so. Pinned by Task D9 Step 1, test `untranslatable filter falls back visibly`.
5. **Checkout attempted by a visitor.** No order may ever be created. Pinned by Task D10 Step 1, test `checkout never creates an order`.

---

## Part A: plugin changes (repo `_sdk/meilisearch-wordpress`, worktree `.claude/worktrees/demo-support`)

### Task P0: worktree

- [ ] **Step 1: Create the worktree and start its services**

```bash
cd /Users/quentindequelen/Projects/Meilisearch/_sdk/meilisearch-wordpress
git fetch origin
git worktree add -b qdequele/demo-support .claude/worktrees/demo-support origin/qdequele/integration-review-8c0cee
cd .claude/worktrees/demo-support
docker compose up -d db meilisearch
docker run --rm -v "$PWD":/app -w /app -v meili-wp-composer-cache:/tmp/composer meili-wp-php81 composer install --no-interaction
npm ci
```

Expected: `composer install` and `npm ci` succeed. `docker compose ps` shows `db` and `meilisearch` healthy.

- [ ] **Step 2: Baseline**

Run: `docker run --rm -v "$PWD":/app -w /app meili-wp-php81 composer test`
Expected: all unit tests pass (843 at the time of writing).

### Task P1: `wp meilisearch connect`

**Files:**
- Modify: `src/Ops/Cli.php`: add `use Meilisearch\WordPress\Indexing\IndexManager;`, append a constructor parameter, add the `connect()` subcommand.
- Modify: `src/Plugin.php:211`: pass `$indexes` to `new Ops\Cli(...)`.
- Test: `tests/integration/CliTest.php`.
- Modify: `readme.txt` (WP-CLI section of the description/FAQ), `docs/superpowers/specs/2026-09-30-meilisearch-wordpress-plugin-design.md` § 11.2 (command list).

**Interfaces:**
- Consumes: `IndexManager::connect(): array{version: string, key: string}` (key is `'created'|'kept'|'manual'`; throws `ApiError` or `\RuntimeException`), `Options::set_state(string $key, mixed $value): void`, `Options::search_key(): string`, and the private `Cli::fail(string): never`, `Cli::message(\Throwable|string): string`, `Cli::require_configured(): void`.
- Produces: the CLI command `wp meilisearch connect`, which exits 0 on success and 1 on failure. Demo Task D1 calls it.

- [ ] **Step 1: Write the failing tests**

In `tests/integration/CliTest.php`, change `cli()` so the constructor gets the options and the index manager (append the two arguments after `Queue`):

```php
		return new Cli(
			$this->service( 'reindexer', Reindexer::class ),
			$this->service( 'sync_job', SyncJob::class ),
			$this->service( 'clients', ClientFactory::class ),
			$this->service( 'names', IndexNames::class ),
			$health,
			$this->service( 'indexability', Indexability::class ),
			$this->service( 'queue', Queue::class ),
			$this->service( 'options', Options::class ),
			$this->service( 'index_manager', IndexManager::class )
		);
```

Add the tests:

```php
	public function test_connect_creates_indexes_and_the_search_key_and_records_last_connect(): void {
		$options = $this->service( 'options', Options::class );
		self::assertSame( '', $options->search_key() );

		$this->cli()->connect( array(), array() );

		$success = implode( "\n", $this->messages( 'success' ) );
		self::assertMatchesRegularExpression( '/Connected to Meilisearch \d+\.\d+/', $success );
		self::assertStringContainsString( 'search-only key', $success );
		self::assertNotSame( '', $options->search_key() );
		self::assertTrue( $options->search_key_is_verified() );
		$last = $options->state( 'last_connect' );
		self::assertIsArray( $last );
		self::assertNotSame( '', (string) ( $last['version'] ?? '' ) );
		$uid = $this->service( 'names', IndexNames::class )->uid( 'content' );
		self::assertSame( $uid, $this->meili( 'GET', '/indexes/' . $uid )['uid'] ?? null );

		$this->meili( 'DELETE', '/keys/' . (string) get_option( 'meilisearch_connection' )['search_key_uid'] );
	}

	public function test_connect_twice_keeps_the_key(): void {
		$this->cli()->connect( array(), array() );
		$first = $this->service( 'options', Options::class )->search_key();
		WP_CLI::reset();

		$this->cli()->connect( array(), array() );

		self::assertSame( $first, $this->service( 'options', Options::class )->search_key() );
		self::assertStringContainsString( 'Kept', implode( "\n", $this->messages( 'success' ) ) );
		$this->meili( 'DELETE', '/keys/' . (string) get_option( 'meilisearch_connection' )['search_key_uid'] );
	}

	public function test_connect_exits_1_with_a_redacted_message_when_meilisearch_is_unreachable(): void {
		$connection         = get_option( 'meilisearch_connection' );
		$connection['host'] = 'http://127.0.0.1:9';
		update_option( 'meilisearch_connection', $connection, false );

		$this->expect_exit( fn () => $this->cli()->connect( array(), array() ), 1 );

		$error = $this->messages( 'error' )[0] ?? '';
		self::assertStringContainsString( 'Could not connect to Meilisearch', $error );
		self::assertStringNotContainsString( self::test_key(), $error );
	}
```

Add `use Meilisearch\WordPress\Indexing\IndexManager;` and `use Meilisearch\WordPress\Settings\Options;` to the test's imports if they are missing.

- [ ] **Step 2: Run them to see them fail**

Run (plugin worktree): `docker run --rm -v "$PWD":/app -w /app --add-host=host.docker.internal:host-gateway -e WP_TESTS_DB_HOST=host.docker.internal -e MEILISEARCH_TEST_HOST=http://host.docker.internal:7700 meili-wp-php81 vendor/bin/phpunit -c phpunit-integration.xml.dist --filter 'CliTest::test_connect'`
Expected: an error saying `Call to undefined method ...Cli::connect()`. It may also report too many constructor arguments.

- [ ] **Step 3: Implement**

In `src/Ops/Cli.php`, extend the constructor:

```php
	public function __construct(
		private readonly Reindexer $reindexer,
		private readonly SyncJob $sync_job,
		private readonly ClientFactory $clients,
		private readonly IndexNames $names,
		private readonly SiteHealth $health,
		private readonly Indexability $indexability,
		private readonly Queue $queue,
		private readonly Options $options = new Options(),
		private readonly ?IndexManager $indexes = null
	) {}
```

(Keep the existing parameter names and types exactly as they are in the file. Only append `$indexes`.)

Add the subcommand before `reindex()`:

```php
	/**
	 * Connects to Meilisearch: checks the version, creates the indexes with their settings and creates
	 * the browser search key. This is the flow that saving the Connection screen runs, for sites
	 * configured with MEILISEARCH_HOST and MEILISEARCH_ADMIN_KEY in wp-config.php.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp meilisearch connect
	 *
	 * @param string[]             $args       Positional arguments (none).
	 * @param array<string, mixed> $assoc_args Associative arguments (none).
	 */
	public function connect( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI always passes both arrays.
		$this->require_configured();
		if ( null === $this->indexes ) {
			$this->fail( 'The connection service is not available.' );
		}

		try {
			$result = $this->indexes->connect();
		} catch ( \Throwable $error ) {
			$this->fail( sprintf( 'Could not connect to Meilisearch: %s', $this->message( $error ) ) );
		}

		$this->options->set_state(
			'last_connect',
			array(
				'version' => $result['version'],
				'time'    => time(),
			)
		);
		WP_CLI::success( sprintf( 'Connected to Meilisearch %s. Indexes are ready.', $result['version'] ) );

		if ( 'created' === $result['key'] ) {
			WP_CLI::success( 'Created a search-only key for autocomplete.' );
		} elseif ( 'kept' === $result['key'] ) {
			WP_CLI::success( 'Kept the existing search-only key.' );
		} else {
			WP_CLI::warning( 'The search-only key is managed manually: check it on the Meilisearch > Connection screen.' );
		}
	}
```

In `src/Plugin.php`, in the `WP_CLI` block, append `$indexes` after `$options`:
`new Ops\Cli( $reindexer, $sync_job, $clients, $names, $health, $indexability, $queue, $options, $indexes )`.

- [ ] **Step 4: Run the tests and the whole suite**

Run: the Step 2 command, then `... meili-wp-php81 composer test`, `... composer lint`, `... composer analyse`, and the full integration suite (`vendor/bin/phpunit -c phpunit-integration.xml.dist`).
Expected: everything passes.

- [ ] **Step 5: Docs**

In `readme.txt`, in the WP-CLI list (search for `wp meilisearch reindex`), add the line `* \`wp meilisearch connect\`: check the connection, create the indexes and the browser search key (for sites configured in wp-config.php).`. In the plugin spec § 11.2, add `connect` to the command table with the same wording.

- [ ] **Step 6: Commit**

```bash
git add src/Ops/Cli.php src/Plugin.php tests/integration/CliTest.php readme.txt docs/superpowers/specs/2026-09-30-meilisearch-wordpress-plugin-design.md
git commit -m "Add wp meilisearch connect for sites configured from wp-config.php"
```

### Task P2: autocomplete subtitle field

**Files:**
- Modify: `src/Frontend/Autocomplete.php` (`config()`, adding `subtitles`).
- Modify: `assets/js/autocomplete.js` (fields + rendering).
- Modify: `assets/css/autocomplete.css`.
- Test: `tests/unit/Frontend/AutocompleteTest.php`, `tests/e2e/autocomplete.spec.ts`.

**Interfaces:**
- Produces:
  - The filter `meilisearch_autocomplete_subtitle_field( ?string $field, string $logical ): ?string`, where `$logical` is `'content'|'products'`.
  - The config key `subtitles: array{content: ?string, products: ?string}`.
  - The CSS class `.meilisearch-ac__subtitle`.
  Demo Task D10 relies on the filter name and on the subtitle being read from a top-level document field.

- [ ] **Step 1: Write the failing unit tests**

Append to `AutocompleteTest`:

```php
	public function test_subtitles_default_to_null(): void {
		self::assertSame(
			array(
				'content'  => null,
				'products' => null,
			),
			$this->autocomplete()->config()['subtitles']
		);
	}

	public function test_subtitle_field_is_filterable_per_index_and_validated(): void {
		Filters\expectApplied( 'meilisearch_autocomplete_subtitle_field' )->twice()->andReturnUsing(
			static fn ( $field, string $logical ) => 'content' === $logical ? 'demo_subtitle' : 'bad field;'
		);

		self::assertSame(
			array(
				'content'  => 'demo_subtitle',
				'products' => null,
			),
			$this->autocomplete()->config()['subtitles']
		);
	}

	public function test_subtitle_field_ignores_non_strings(): void {
		Filters\expectApplied( 'meilisearch_autocomplete_subtitle_field' )->twice()->andReturn( array( 'title' ) );

		self::assertSame( array( 'content' => null, 'products' => null ), $this->autocomplete()->config()['subtitles'] );
	}
```

- [ ] **Step 2: Run to see them fail**

Run: `docker run --rm -v "$PWD":/app -w /app meili-wp-php81 vendor/bin/phpunit --filter AutocompleteTest`
Expected: FAIL with `Undefined array key "subtitles"`.

- [ ] **Step 3: Implement the PHP side**

In `Autocomplete::config()`, before the `return`:

```php
		$subtitles = array();
		foreach ( array( 'content', 'products' ) as $logical ) {
			/**
			 * Filters the document field shown as a second line under each autocomplete suggestion.
			 *
			 * @param string|null $field   Field name (top-level, [A-Za-z0-9_.]), or null for none.
			 * @param string      $logical 'content' | 'products'.
			 */
			$field                 = apply_filters( 'meilisearch_autocomplete_subtitle_field', null, $logical );
			$subtitles[ $logical ] = is_string( $field ) && 1 === preg_match( '/^[A-Za-z0-9_.]+$/', $field ) ? $field : null;
		}
```

Add `'subtitles' => $subtitles,` to the returned array after `'limits'`, and add `subtitles: array{content: ?string, products: ?string}` to the `@return` shape.

- [ ] **Step 4: Unit tests pass**

Run: the Step 2 command. Expected: PASS.

- [ ] **Step 5: Write the failing E2E test**

In `tests/e2e/autocomplete.spec.ts`, add a test that activates a throwaway mu-plugin through WP-CLI. Use the `wp()` helper from `./utils`, the same way `beforeAll` does. The existing E2E fixtures have a post titled "Mountain Photography Tips".

```ts
	test( 'a filtered subtitle field renders under the title', async ( { page } ) => {
		wp( 'eval', `file_put_contents( WPMU_PLUGIN_DIR . '/e2e-subtitle.php', '<?php add_filter( "meilisearch_autocomplete_subtitle_field", fn( $f, $l ) => "content" === $l ? "author_name" : $f, 10, 2 );' );` );
		try {
			await page.goto( '/search-demo/' );
			const input = searchInput( page );
			await input.pressSequentially( 'mountian', { delay: 50 } );
			const option = page.getByRole( 'option', { name: /Mountain Photography Tips/ } );
			await expect( option.locator( '.meilisearch-ac__subtitle' ) ).toHaveText( /\S/ );
		} finally {
			wp( 'eval', `@unlink( WPMU_PLUGIN_DIR . '/e2e-subtitle.php' );` );
		}
	} );
```

(If `WPMU_PLUGIN_DIR` doesn't exist in the E2E WordPress container, create it first in the same `eval` with `wp_mkdir_p( WPMU_PLUGIN_DIR );`.)

- [ ] **Step 6: Implement the JS side**

In `assets/js/autocomplete.js`:

1. After the `limits` constant, add:
```js
	const subtitles = Object.assign( { content: null, products: null }, config.subtitles || {} );
```
2. In `search()`, build each field list with its subtitle:
```js
	function fieldsFor( key, base ) {
		return subtitles[ key ] ? base.concat( [ subtitles[ key ] ] ) : base;
	}
```
   Place this next to `buildQuery`. Then replace `PRODUCT_FIELDS` / `CONTENT_FIELDS` in the two `buildQuery( ... )` calls with `fieldsFor( 'products', PRODUCT_FIELDS )` and `fieldsFor( 'content', CONTENT_FIELDS )`.
3. In `render()`, right after `option.appendChild( title );`, add:
```js
					const subtitleField = subtitles[ group.key ];
					const subtitleValue = subtitleField ? hit[ subtitleField ] : null;
					if ( typeof subtitleValue === 'string' && subtitleValue !== '' ) {
						const subtitle = element( 'span', { class: 'meilisearch-ac__subtitle' } );
						subtitle.textContent = subtitleValue;
						title.appendChild( document.createElement( 'br' ) );
						title.appendChild( subtitle );
					}
```
   The subtitle sits inside the title span, so the option's accessible name includes it, and the existing grid/flex layout keeps a single text column.

In `assets/css/autocomplete.css`, add:

```css
.meilisearch-ac__subtitle {
	font-size: 0.85em;
	opacity: 0.7;
}
```

Run `npm run build` to refresh `autocomplete.min.js`.

- [ ] **Step 7: Run the E2E autocomplete spec**

Run (plugin worktree): `docker compose up -d wordpress && npm run test:e2e -- autocomplete.spec.ts`
Expected: every autocomplete test passes, including axe. Stop the `wordpress` service afterwards (`docker compose stop wordpress`) to keep the plugin's environment as it was.

- [ ] **Step 8: Docs and commit**

Add the filter to the plugin spec § 14 (public extension points) and to the readme's developer FAQ:
`meilisearch_autocomplete_subtitle_field( ?string $field, string $logical )`: a document field shown under each suggestion.

```bash
npm run build
git add src/Frontend/Autocomplete.php assets/js/autocomplete.js assets/css/autocomplete.css tests/unit/Frontend/AutocompleteTest.php tests/e2e/autocomplete.spec.ts readme.txt docs/superpowers/specs/2026-09-30-meilisearch-wordpress-plugin-design.md
git commit -m "Autocomplete: optional subtitle line per suggestion (meilisearch_autocomplete_subtitle_field)"
```

(`autocomplete.min.js` is git-ignored, so it isn't committed.)

### Task P3: "See all results" footer

**Files:**
- Modify: `src/Frontend/Autocomplete.php` (i18n strings `seeAll`, `seeAllCount`).
- Modify: `assets/js/autocomplete.js`, `assets/css/autocomplete.css`.
- Test: `tests/unit/Frontend/AutocompleteTest.php`, `tests/e2e/autocomplete.spec.ts`.

**Interfaces:**
- Produces: an option element with class `meilisearch-ac__footer`, `role="option"`, id `{prefix}-footer` and `data-submit="1"`. Selecting it submits `input.form`, or navigates to `/?s=` when the input has no form. Demo E2E (Task D11) selects it by its accessible name `/See all/`.

- [ ] **Step 1: Failing unit test**

Update `test_config_contains_scoped_key_indexes_limits_and_strings` so the i18n keys assertion becomes:

```php
		self::assertSame( array( 'products', 'posts', 'listLabel', 'noResults', 'oneResult', 'results', 'seeAll', 'seeAllCount' ), array_keys( $config['i18n'] ) );
		self::assertStringContainsString( '%s', $config['i18n']['seeAll'] );
		self::assertStringContainsString( '%1$d', $config['i18n']['seeAllCount'] );
		self::assertStringContainsString( '%2$s', $config['i18n']['seeAllCount'] );
```

Run: `... vendor/bin/phpunit --filter AutocompleteTest`. Expected: FAIL (the keys don't match).

- [ ] **Step 2: Add the strings**

In `Autocomplete::config()` `i18n`:

```php
				/* translators: %s: the search terms. */
				'seeAll'      => __( 'See all results for “%s”', 'meilisearch' ),
				/* translators: 1: number of results, 2: the search terms. */
				'seeAllCount' => __( 'See all %1$d results for “%2$s”', 'meilisearch' ),
```

The JS replaces exactly `%1$d` and `%2$s`. Run the unit tests. Expected: PASS.

- [ ] **Step 3: Failing E2E test**

```ts
	test( 'the footer shows the total and submits the search form', async ( { page } ) => {
		await page.goto( '/search-demo/' );
		const input = searchInput( page );
		await input.pressSequentially( 'mountian', { delay: 50 } );
		const footer = page.getByRole( 'option', { name: /See all \d+ results for “mountian”/ } );
		await expect( footer ).toBeVisible();

		// Keyboard: the footer is the last option.
		await input.press( 'ArrowUp' );
		await expect( footer ).toHaveAttribute( 'aria-selected', 'true' );
		await input.press( 'Enter' );
		await expect( page ).toHaveURL( /[?&]s=mountian/ );
	} );
```

Run: `npm run test:e2e -- autocomplete.spec.ts -g footer`. Expected: FAIL (no such option).

- [ ] **Step 4: Implement**

In `assets/js/autocomplete.js`:

1. Extend the default `i18n` object with:
```js
			seeAll: 'See all results for “%s”',
			seeAllCount: 'See all %1$d results for “%2$s”',
```
2. Add `submit` handling next to `go()`:
```js
		function submitSearch() {
			close();
			if ( input.form ) {
				if ( typeof input.form.requestSubmit === 'function' ) {
					input.form.requestSubmit();
				} else {
					input.form.submit();
				}
				return;
			}
			window.location.assign( '/?s=' + encodeURIComponent( input.value ) );
		}
```
3. Change `render( groups )` to `render( groups, total )`. After the `groups.forEach(...)` block and before `setActive( -1 )`, when `options.length > 0`, append the footer:
```js
			if ( options.length > 0 ) {
				const footer = element( 'div', {
					id: prefix + '-footer',
					class: 'meilisearch-ac__footer',
					role: 'option',
					'aria-selected': 'false',
					'data-submit': '1',
				} );
				footer.textContent = typeof total === 'number' && total > 0
					? i18n.seeAllCount.replace( '%1$d', String( total ) ).replace( '%2$s', lastQuery )
					: i18n.seeAll.replace( '%s', lastQuery );
				footer.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
				} );
				footer.addEventListener( 'click', submitSearch );
				options.push( { element: footer, url: null } );
				listbox.appendChild( footer );
			}
```
4. The status announcement must count only real suggestions. Compute `const suggestions = options.filter( ( o ) => o.url !== null ).length;` and use it in the two `announce(...)` calls and in the empty check: `if ( suggestions === 0 ) { ... }`. Add the footer only when `suggestions > 0`, so test that before appending it.
5. In the `Enter` key handler, replace `go( options[ active ].url );` with:
```js
						if ( options[ active ].url === null ) {
							submitSearch();
						} else {
							go( options[ active ].url );
						}
```
6. `groupsFrom( data )` also computes the total: if every result has a numeric `estimatedTotalHits`, the total is their sum; otherwise it is `null`. Return `{ groups, total }` and call `render( result.groups, result.total )` in `search()`.

CSS:

```css
.meilisearch-ac__footer {
	border-top: 1px solid currentColor;
	border-top-color: color-mix(in srgb, currentColor 20%, transparent);
	font-size: 0.9em;
	padding: 0.5em 0.75em;
	cursor: pointer;
}
.meilisearch-ac__footer.is-active {
	text-decoration: underline;
}
```

- [ ] **Step 5: Run the E2E autocomplete spec and the unit tests**

Run: `npm run build && npm run test:e2e -- autocomplete.spec.ts`, then `... composer test`, `composer lint`, `composer analyse`.
Expected: all pass, including the existing keyboard and axe tests. If the existing test's `ArrowDown` → first option assertion breaks, that is a regression to fix in the JS, not in the test.

- [ ] **Step 6: Docs and commit**

Plugin spec § 10: add "A last option, 'See all N results for “q”', submits the form". `readme.txt` changelog: add a line under the unreleased version.

```bash
git add src/Frontend/Autocomplete.php assets/js/autocomplete.js assets/css/autocomplete.css tests/unit/Frontend/AutocompleteTest.php tests/e2e/autocomplete.spec.ts readme.txt docs/superpowers/specs/2026-09-30-meilisearch-wordpress-plugin-design.md
git commit -m "Autocomplete: 'See all results' footer option that submits the search form"
```

- [ ] **Step 7: Push and open the PR (stacked on #32)**

```bash
git push -u origin qdequele/demo-support
gh pr create --repo meilisearch/meilisearch-wordpress --base qdequele/integration-review-8c0cee --head qdequele/demo-support \
  --title "WP-CLI connect, autocomplete subtitles and 'See all' footer" \
  --body "Adds \`wp meilisearch connect\` (the Connection screen's flow, for wp-config.php setups), the \`meilisearch_autocomplete_subtitle_field\` filter and a 'See all N results' footer option. Needed by the WordPress/WooCommerce demos. Stacked on #32."
```

Expected: the PR URL is printed. Wait for CI to pass before relying on it in production. The demo builds from the local worktree, so it doesn't wait.

---
## Part B: demo repo (`_demos/wordpress-meilisearch-demo`)

Everything below runs in the demo repo, unless a step says otherwise. Copy `.env.example` to `.env` once:

```bash
cd /Users/quentindequelen/Projects/Meilisearch/_demos/wordpress-meilisearch-demo
```

In `.env`, set `MEILISEARCH_PLUGIN_PATH=../../_sdk/meilisearch-wordpress/.claude/worktrees/demo-support` (the Part A worktree), until PR "demo-support" is merged.

### Task D1: Stack skeleton (both sites reach Ready with an empty site)

**Files:**
- Create: `compose.yaml`, `Dockerfile`, `Dockerfile.dev`, `.env.example`, `.dockerignore`, `.gitattributes`
- Create: `docker/demo-entrypoint.sh`, `docker/first-boot.sh`, `docker/apache-demo.conf`, `docker/php.ini`
- Create: `docker/supervisord.conf`, `docker/fly-start.sh` (Fly target, exercised in Task D12)
- Create: `blog/setup.php`, `shop/setup.php` (stubs that only print `[setup] nothing to import yet`)
- Create: `bin/smoke.sh`

**Interfaces:**
- Consumes: the plugin command `wp meilisearch connect` (Task P1), and the plugin options `meilisearch_connection.prefix` and `meilisearch_search.{replace,highlight,autocomplete}`.
- Produces:
  - Environment variables, read by every later task: `SITE`, `WP_HOME`, `WP_ADMIN_PASSWORD`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `MEILISEARCH_HOST`, `MEILISEARCH_ADMIN_KEY`, `MEILISEARCH_INDEX_PREFIX`, `DEMO_ROOT` (default `/var/www/html`).
  - Paths inside the image:
    - `/opt/demo/site` (copy of `blog/` or `shop/`);
    - `/opt/demo/data` (copy of `data/$SITE`);
    - `/opt/demo/mu-plugins` (copy of `shared/mu-plugins`);
    - `/opt/plugins/meilisearch`;
    - `/opt/plugins/woocommerce` (shop only).
  - The first-boot marker: `$DEMO_ROOT/wp-content/uploads/.demo-installed`.
  - The log lines `[demo] First boot`, `[demo] Ready`.

- [ ] **Step 1: Pin versions**

Run:

```bash
curl -s 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce' | python3 -c 'import json,sys;print(json.load(sys.stdin)["version"])'
curl -s https://api.wordpress.org/core/version-check/1.7/ | python3 -c 'import json,sys;print(json.load(sys.stdin)["offers"][0]["current"])'
```

Write the two results into `.env.example` as `WOOCOMMERCE_VERSION` and `WORDPRESS_VERSION`. Check that the Docker Hub tag `wordpress:<WORDPRESS_VERSION>-php8.3-apache` exists: `docker manifest inspect wordpress:<v>-php8.3-apache >/dev/null && echo ok`. If it doesn't, use the newest tag that does.

- [ ] **Step 2: Write `.env.example`, `.gitattributes`, `.dockerignore`**

`.env.example`:

```dotenv
# Path to a checkout of meilisearch/meilisearch-wordpress (the plugin is built from it).
MEILISEARCH_PLUGIN_PATH=../../_sdk/meilisearch-wordpress
MEILI_MASTER_KEY=meili-wp-demo-master-key
MEILISEARCH_VERSION=v1.34
MEILISEARCH_PORT=7701
# The plugin's host is used by PHP *and* by visitors' browsers (autocomplete), so it must resolve in both.
# OrbStack resolves this name on the Mac and inside containers.
MEILISEARCH_HOST=http://meilisearch.wordpress-meilisearch-demo.orb.local:7700
BLOG_HOME=http://blog.wordpress-meilisearch-demo.orb.local
SHOP_HOME=http://shop.wordpress-meilisearch-demo.orb.local
BLOG_PORT=8081
SHOP_PORT=8082
WP_ADMIN_PASSWORD=admin
WORDPRESS_VERSION=7.1.2
WOOCOMMERCE_VERSION=10.3.0
```

(Replace the two version values with the Step 1 results.)

`.gitattributes`:

```
data/**/images/** filter=lfs diff=lfs merge=lfs -text
```

`.dockerignore`:

```
.git
.env
node_modules
test-results
playwright-report
scripts/.cache
docs
```

- [ ] **Step 3: Write `compose.yaml`**

```yaml
# Meilisearch for WordPress demos: Mission Log (blog) and Met Prints (shop).
#   docker compose watch        # build, start, live-sync themes / mu-plugin / plugin source
#   open http://blog.wordpress-meilisearch-demo.orb.local and http://shop.wordpress-meilisearch-demo.orb.local
name: wordpress-meilisearch-demo

x-site: &site
  build: &site-build
    context: .
    dockerfile: Dockerfile.dev
    additional_contexts:
      plugin: ${MEILISEARCH_PLUGIN_PATH:-../../_sdk/meilisearch-wordpress}
    args: &site-args
      WORDPRESS_VERSION: ${WORDPRESS_VERSION:-7.1.2}
      WOOCOMMERCE_VERSION: ${WOOCOMMERCE_VERSION:-10.3.0}
  depends_on:
    mariadb:
      condition: service_healthy
    meilisearch:
      condition: service_healthy
  healthcheck:
    test: ["CMD-SHELL", "test -f /tmp/demo-ready && curl -fsS -o /dev/null http://localhost/wp-login.php"]
    interval: 10s
    timeout: 5s
    retries: 120
    start_period: 30s

services:
  meilisearch:
    image: getmeili/meilisearch:${MEILISEARCH_VERSION:-v1.34}
    environment:
      MEILI_MASTER_KEY: ${MEILI_MASTER_KEY:-meili-wp-demo-master-key}
      MEILI_ENV: development
      MEILI_NO_ANALYTICS: "true"
    ports:
      - "${MEILISEARCH_PORT:-7701}:7700"
    volumes:
      - meili_data:/meili_data
    healthcheck:
      test: ["CMD", "curl", "-fsS", "http://localhost:7700/health"]
      interval: 3s
      timeout: 5s
      retries: 30

  mariadb:
    image: mariadb:11.4
    environment:
      MARIADB_ROOT_PASSWORD: root
      MARIADB_USER: wp
      MARIADB_PASSWORD: wp
    configs:
      - source: mariadb-init
        target: /docker-entrypoint-initdb.d/10-databases.sql
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 3s
      timeout: 5s
      retries: 40

  blog:
    <<: *site
    build:
      <<: *site-build
      args:
        <<: *site-args
        SITE: blog
    environment:
      SITE: blog
      WP_HOME: ${BLOG_HOME:-http://blog.wordpress-meilisearch-demo.orb.local}
      WP_ADMIN_PASSWORD: ${WP_ADMIN_PASSWORD:-admin}
      DB_HOST: mariadb
      DB_NAME: blog
      DB_USER: wp
      DB_PASSWORD: wp
      MEILISEARCH_HOST: ${MEILISEARCH_HOST:-http://meilisearch.wordpress-meilisearch-demo.orb.local:7700}
      MEILISEARCH_ADMIN_KEY: ${MEILI_MASTER_KEY:-meili-wp-demo-master-key}
      MEILISEARCH_INDEX_PREFIX: blog
    ports:
      - "${BLOG_PORT:-8081}:80"
    volumes:
      - blog_html:/var/www/html
    develop:
      watch: &watch-common
        - action: sync
          path: ./blog/theme
          target: /opt/demo/site/theme
        - action: sync
          path: ./shared/mu-plugins
          target: /opt/demo/mu-plugins
        - action: sync
          path: ${MEILISEARCH_PLUGIN_PATH:-../../_sdk/meilisearch-wordpress}/src
          target: /opt/plugins/meilisearch/src
        - action: sync
          path: ${MEILISEARCH_PLUGIN_PATH:-../../_sdk/meilisearch-wordpress}/assets
          target: /opt/plugins/meilisearch/assets
        - action: sync+restart
          path: ./blog/setup.php
          target: /opt/demo/site/setup.php
        - action: rebuild
          path: ./Dockerfile.dev
        - action: rebuild
          path: ./docker
        - action: rebuild
          path: ${MEILISEARCH_PLUGIN_PATH:-../../_sdk/meilisearch-wordpress}/composer.json

  shop:
    <<: *site
    build:
      <<: *site-build
      args:
        <<: *site-args
        SITE: shop
    environment:
      SITE: shop
      WP_HOME: ${SHOP_HOME:-http://shop.wordpress-meilisearch-demo.orb.local}
      WP_ADMIN_PASSWORD: ${WP_ADMIN_PASSWORD:-admin}
      DB_HOST: mariadb
      DB_NAME: shop
      DB_USER: wp
      DB_PASSWORD: wp
      MEILISEARCH_HOST: ${MEILISEARCH_HOST:-http://meilisearch.wordpress-meilisearch-demo.orb.local:7700}
      MEILISEARCH_ADMIN_KEY: ${MEILI_MASTER_KEY:-meili-wp-demo-master-key}
      MEILISEARCH_INDEX_PREFIX: shop
    ports:
      - "${SHOP_PORT:-8082}:80"
    volumes:
      - shop_html:/var/www/html
    develop:
      watch:
        - action: sync
          path: ./shop/theme
          target: /opt/demo/site/theme
        - action: sync
          path: ./shared/mu-plugins
          target: /opt/demo/mu-plugins
        - action: sync
          path: ${MEILISEARCH_PLUGIN_PATH:-../../_sdk/meilisearch-wordpress}/src
          target: /opt/plugins/meilisearch/src
        - action: sync
          path: ${MEILISEARCH_PLUGIN_PATH:-../../_sdk/meilisearch-wordpress}/assets
          target: /opt/plugins/meilisearch/assets
        - action: sync+restart
          path: ./shop/setup.php
          target: /opt/demo/site/setup.php
        - action: rebuild
          path: ./Dockerfile.dev
        - action: rebuild
          path: ./docker
        - action: rebuild
          path: ${MEILISEARCH_PLUGIN_PATH:-../../_sdk/meilisearch-wordpress}/composer.json

configs:
  mariadb-init:
    content: |
      CREATE DATABASE IF NOT EXISTS blog CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      CREATE DATABASE IF NOT EXISTS shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
      GRANT ALL PRIVILEGES ON blog.* TO 'wp'@'%';
      GRANT ALL PRIVILEGES ON shop.* TO 'wp'@'%';

volumes:
  meili_data:
  db_data:
  blog_html:
  shop_html:
```

Remove the unused `&watch-common` anchor if `docker compose config` warns about it.

Run `docker compose config >/dev/null && echo ok`. Expected: `ok`.

- [ ] **Step 4: Write `Dockerfile.dev` and `Dockerfile`**

The plugin's own `bin/build-zip.sh` needs `git ls-files`. A plugin checkout that is a git worktree has a `.git` *file* pointing outside the build context, so the image builds the plugin the same way the plugin's own `Dockerfile.dev` does. This is a deliberate deviation from spec § 4.2; record it in the README "How it is built" table.

`Dockerfile.dev`:

```dockerfile
# syntax=docker/dockerfile:1.7
# Dev image for one demo site (SITE=blog|shop): WordPress + WP-CLI + the plugin built from the
# `plugin` build context + the site's theme, setup and data. Used by compose.yaml (docker compose watch).
ARG WORDPRESS_VERSION=7.1.2

FROM composer:2 AS plugin-vendor
WORKDIR /app
COPY --from=plugin composer.json composer.lock ./
RUN composer install --no-dev --no-autoloader --no-scripts --no-interaction --no-progress --prefer-dist
COPY --from=plugin src ./src
RUN composer dump-autoload --no-dev --optimize --no-interaction

FROM node:22-alpine AS plugin-assets
WORKDIR /app
COPY --from=plugin package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=plugin assets ./assets
RUN npm run build

FROM wordpress:${WORDPRESS_VERSION}-php8.3-apache AS base
ARG SITE
ARG WOOCOMMERCE_VERSION
ENV SITE=${SITE} DEMO_ROOT=/var/www/html WP_CLI_CACHE_DIR=/tmp/wp-cli-cache WP_CLI_ALLOW_ROOT=1
RUN apt-get update && apt-get install -y --no-install-recommends less mariadb-client unzip && rm -rf /var/lib/apt/lists/* \
	&& curl -fsSL -o /usr/local/bin/wp https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar \
	&& chmod +x /usr/local/bin/wp
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-demo.ini
COPY docker/apache-demo.conf /etc/apache2/conf-enabled/zz-demo.conf

# Plugin (built), WooCommerce (shop only).
COPY --from=plugin meilisearch.php uninstall.php readme.txt /opt/plugins/meilisearch/
COPY --from=plugin src /opt/plugins/meilisearch/src
COPY --from=plugin assets /opt/plugins/meilisearch/assets
COPY --from=plugin languages /opt/plugins/meilisearch/languages
COPY --from=plugin-vendor /app/vendor /opt/plugins/meilisearch/vendor
COPY --from=plugin-assets /app/assets/js/autocomplete.min.js /opt/plugins/meilisearch/assets/js/autocomplete.min.js
RUN if [ "$SITE" = "shop" ]; then \
		curl -fsSL -o /tmp/wc.zip "https://downloads.wordpress.org/plugin/woocommerce.${WOOCOMMERCE_VERSION}.zip" \
		&& unzip -q /tmp/wc.zip -d /opt/plugins && rm /tmp/wc.zip; \
	fi

# Demo site: theme, setup, data, mu-plugin, scripts.
COPY ${SITE}/ /opt/demo/site/
COPY data/${SITE}/ /opt/demo/data/
COPY shared/mu-plugins/ /opt/demo/mu-plugins/
COPY docker/demo-entrypoint.sh docker/first-boot.sh /opt/demo/bin/
RUN chmod +x /opt/demo/bin/*.sh

ENTRYPOINT ["/opt/demo/bin/demo-entrypoint.sh"]
CMD ["apache2-foreground"]
```

`Dockerfile` (production, multi-stage). It holds the same stages as `Dockerfile.dev`, from `plugin-vendor` through `base`, except that every `COPY --from=plugin <src>` reads `COPY .plugin/<src>`. `fly deploy` has no named build contexts, so `bin/fly-deploy.sh` (Task D12) stages the plugin into `.plugin/`. Then:

```dockerfile
FROM base AS fly
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-server supervisor && rm -rf /var/lib/apt/lists/*
COPY docker/supervisord.conf /etc/supervisor/conf.d/demo.conf
COPY docker/fly-start.sh /opt/demo/bin/fly-start.sh
RUN chmod +x /opt/demo/bin/fly-start.sh \
	&& sed -i 's/^\(opcache.validate_timestamps\).*/\1=0/' /usr/local/etc/php/conf.d/zz-demo.ini
ENV DEMO_ROOT=/data/html
ENTRYPOINT ["/opt/demo/bin/fly-start.sh"]
CMD []
```

`data/blog/` and `data/shop/` don't exist yet: create them now, each with a `.gitkeep`, so `COPY data/${SITE}/` works.

- [ ] **Step 5: Write `docker/php.ini`, `docker/apache-demo.conf`**

`docker/php.ini`:

```ini
memory_limit = 512M
upload_max_filesize = 32M
post_max_size = 32M
max_execution_time = 120
opcache.enable = 1
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0
expose_php = Off
```

`docker/apache-demo.conf`:

```apache
# Theme, mu-plugin and plugins live in /opt and are symlinked into wp-content.
<Directory /opt/>
	Options FollowSymLinks
	AllowOverride None
	Require all granted
</Directory>
DocumentRoot ${DEMO_ROOT}
<Directory ${DEMO_ROOT}>
	Options FollowSymLinks
	AllowOverride All
	Require all granted
</Directory>
ServerTokens Prod
ServerSignature Off
LimitRequestFieldSize 16384
```

`apache2-foreground` exports the container environment, so `${DEMO_ROOT}` resolves. Verify this in Step 8. If Apache logs `DEMO_ROOT not defined`, add `PassEnv DEMO_ROOT` plus `Define DEMO_ROOT ${DEMO_ROOT}`, or add an `export DEMO_ROOT` line to `/etc/apache2/envvars` in the Dockerfile.

- [ ] **Step 6: Write the entrypoint and first boot**

`docker/demo-entrypoint.sh`:

```bash
#!/usr/bin/env bash
# Runs on every start: makes sure WordPress files and the demo symlinks exist, writes wp-config.php from the
# environment, waits for the database, installs on first boot, re-applies the per-start settings.
set -euo pipefail

: "${SITE:?}" "${WP_HOME:?}" "${DB_HOST:?}" "${DB_NAME:?}" "${DB_USER:?}" "${DB_PASSWORD:?}"
: "${MEILISEARCH_HOST:?}" "${MEILISEARCH_ADMIN_KEY:?}" "${MEILISEARCH_INDEX_PREFIX:?}"
root="${DEMO_ROOT:-/var/www/html}"
wp() { command wp --path="$root" --allow-root "$@"; }
log() { echo "[demo] $*"; }
rm -f /tmp/demo-ready

mkdir -p "$root"
if [ ! -f "$root/wp-includes/version.php" ]; then
	log "Copying WordPress core into $root"
	cp -a /usr/src/wordpress/. "$root/"
fi
# Newer core from a rebuilt image replaces the files in the volume (wp-content is kept).
if ! cmp -s /usr/src/wordpress/wp-includes/version.php "$root/wp-includes/version.php"; then
	log "Updating WordPress core files"
	tar -C /usr/src/wordpress --exclude=./wp-content -cf - . | tar -C "$root" -xf -
fi

mkdir -p "$root/wp-content/plugins" "$root/wp-content/themes" "$root/wp-content/uploads"
ln -sfn /opt/plugins/meilisearch "$root/wp-content/plugins/meilisearch"
[ -d /opt/plugins/woocommerce ] && ln -sfn /opt/plugins/woocommerce "$root/wp-content/plugins/woocommerce"
theme_slug="$(cat /opt/demo/site/theme/slug.txt 2>/dev/null || true)"
[ -n "$theme_slug" ] && ln -sfn /opt/demo/site/theme "$root/wp-content/themes/$theme_slug"
ln -sfn /opt/demo/mu-plugins "$root/wp-content/mu-plugins"
chown -R www-data:www-data "$root/wp-content/uploads"

wp config create --force --skip-check \
	--dbhost="$DB_HOST" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" \
	--extra-php <<PHP
define( 'WP_HOME', getenv( 'WP_HOME' ) );
define( 'WP_SITEURL', getenv( 'WP_HOME' ) );
define( 'MEILISEARCH_HOST', getenv( 'MEILISEARCH_HOST' ) );
define( 'MEILISEARCH_ADMIN_KEY', getenv( 'MEILISEARCH_ADMIN_KEY' ) );
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_ENVIRONMENT_TYPE', getenv( 'WP_ENVIRONMENT_TYPE' ) ?: 'production' );
define( 'WP_DEBUG', false );
if ( isset( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) { \$_SERVER['HTTPS'] = 'on'; }
PHP

for i in $(seq 1 60); do
	if mariadb-admin ping -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" --silent 2>/dev/null; then break; fi
	[ "$i" = 60 ] && { log "Database unreachable"; exit 1; }
	sleep 2
done

marker="$root/wp-content/uploads/.demo-installed"
if [ ! -f "$marker" ]; then
	log "First boot"
	DEMO_ROOT="$root" /opt/demo/bin/first-boot.sh
	touch "$marker"
fi

# Every start: password, index prefix (a change re-fingerprints the plugin, which then needs a reindex).
wp user update admin --user_pass="${WP_ADMIN_PASSWORD:-admin}" --skip-email >/dev/null
current_prefix="$(wp option pluck meilisearch_connection prefix 2>/dev/null || true)"
if [ "$current_prefix" != "$MEILISEARCH_INDEX_PREFIX" ]; then
	wp option patch update meilisearch_connection prefix "$MEILISEARCH_INDEX_PREFIX"
	wp meilisearch connect
	wp meilisearch reindex
fi
if ! wp meilisearch check >/dev/null 2>&1; then
	log "Warning: wp meilisearch check reports a problem; search falls back to MySQL until it is fixed"
fi
touch /tmp/demo-ready
log "Ready"
exec docker-entrypoint.sh "$@"
```

The official `docker-entrypoint.sh` copies core only when `index.php` is missing, and it leaves an existing `wp-config.php` alone, so `exec`-ing it is safe. If it rewrites the config because no `WORDPRESS_*` variables are set, replace the last line with `exec "$@"`.

`docker/first-boot.sh`:

```bash
#!/usr/bin/env bash
# First boot only: install WordPress, activate theme and plugins, configure, import, connect, reindex.
# Not idempotent by itself; demo-entrypoint.sh writes the marker only when this exits 0.
set -euo pipefail
root="${DEMO_ROOT:-/var/www/html}"
wp() { command wp --path="$root" --allow-root "$@"; }
log() { echo "[demo] $*"; }

title="Mission Log"; [ "$SITE" = shop ] && title="Met Prints"
if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$WP_HOME" --title="$title" --admin_user=admin \
		--admin_password="${WP_ADMIN_PASSWORD:-admin}" --admin_email=demo@example.com --skip-email
fi
wp rewrite structure '/%postname%/' --hard
theme_slug="$(cat /opt/demo/site/theme/slug.txt)"
wp theme activate "$theme_slug"
[ "$SITE" = shop ] && wp plugin activate woocommerce
wp plugin activate meilisearch
wp option patch update meilisearch_connection prefix "$MEILISEARCH_INDEX_PREFIX"

log "Importing content"
wp eval-file /opt/demo/site/setup.php

log "Connecting to Meilisearch"
wp meilisearch connect
log "Reindexing"
wp meilisearch reindex
wp option patch update meilisearch_search replace true
wp option patch update meilisearch_search highlight true
wp option patch update meilisearch_search autocomplete true
chown -R www-data:www-data "$root/wp-content/uploads"
```

`blog/setup.php` and `shop/setup.php` (stubs for now):

```php
<?php
/**
 * Demo import for this site, run once by first-boot.sh through `wp eval-file`.
 */
WP_CLI::log( '[setup] nothing to import yet' );
```

Create `blog/theme/slug.txt` containing `mission-log` and `shop/theme/slug.txt` containing `met-prints`. Also create minimal valid block themes so activation works:
- `blog/theme/style.css` with header `Theme Name: Mission Log`;
- `shop/theme/style.css` with header `Theme Name: Met Prints`;
- each with `templates/index.html` containing `<!-- wp:post-title /-->`.

Tasks D6 and D8 replace these.

`chmod +x docker/*.sh bin/*.sh`.

- [ ] **Step 7: Write `bin/smoke.sh`**

```bash
#!/usr/bin/env bash
# Smoke test for the running stack: both sites Ready, plugin connected, restart is idempotent.
# Usage: bin/smoke.sh [--restart]
set -euo pipefail
cd "$(dirname "$0")/.."
blog="${BLOG_URL:-http://localhost:${BLOG_PORT:-8081}}"
shop="${SHOP_URL:-http://localhost:${SHOP_PORT:-8082}}"
fail() { echo "SMOKE FAIL: $*" >&2; exit 1; }

for svc in blog shop; do
	docker compose logs "$svc" | grep -q '\[demo\] Ready' || fail "$svc never logged Ready"
	docker compose exec -T "$svc" wp --allow-root meilisearch status >/dev/null || fail "$svc: wp meilisearch status failed"
	key="$(docker compose exec -T "$svc" wp --allow-root option pluck meilisearch_connection search_key)"
	[ -n "$key" ] || fail "$svc: no browser search key (wp meilisearch connect did not run?)"
done
curl -fsS -o /dev/null "$blog/wp-login.php" || fail "blog not serving"
curl -fsS -o /dev/null "$shop/wp-login.php" || fail "shop not serving"

if [ "${1:-}" = "--restart" ]; then
	before="$(docker compose exec -T blog wp --allow-root post list --post_type=post --format=count)"
	docker compose restart blog
	docker compose up --wait blog
	after="$(docker compose exec -T blog wp --allow-root post list --post_type=post --format=count)"
	[ "$before" = "$after" ] || fail "restart changed the post count ($before -> $after)"
	[ "$(docker compose logs blog | grep -c '\[demo\] First boot')" = 1 ] || fail "first boot ran twice"
fi
echo "smoke ok"
```

- [ ] **Step 8: Build, start, check**

```bash
docker compose up --build --wait
bin/smoke.sh --restart
```

Expected: `smoke ok`. Then open `http://blog.wordpress-meilisearch-demo.orb.local` and the shop URL. Each shows the stub theme, and the shop admin lists WooCommerce as active.

Also check that a prefix change takes effect: set `MEILISEARCH_INDEX_PREFIX: blog2` temporarily with `docker compose run`, or edit `compose.yaml` and run `docker compose up -d blog`. Then `curl -s -H 'Authorization: Bearer meili-wp-demo-master-key' localhost:7701/indexes | grep blog2_content`. Revert afterwards and delete the stray index: `curl -X DELETE -H 'Authorization: Bearer meili-wp-demo-master-key' localhost:7701/indexes/blog2_content`.

If `wp meilisearch connect` fails with "not configured", the constants did not reach PHP. Run `docker compose exec blog wp --allow-root eval 'var_dump(MEILISEARCH_HOST);'`.

- [ ] **Step 9: Commit**

```bash
git lfs install
git add -A
git commit -m "Stack skeleton: compose (watch), images, entrypoint and first boot for both sites"
```

### Task D2: Data tooling (`scripts/common.py`)

**Files:**
- Create: `scripts/requirements.txt`, `scripts/common.py`, `scripts/tests/test_common.py`, `scripts/tests/conftest.py`, `scripts/pyproject.toml` (pytest config only)

**Interfaces:**
- Produces, used by `nasa.py` and `met.py`:
  - `SEED = 20261002`
  - `rng(name: str) -> random.Random`: a deterministic RNG per purpose; the same `name` always gives the same stream.
  - `sanitize_html(html: str, allowed: frozenset[str] = ALLOWED_TAGS) -> str`
  - `strip_tags(html: str) -> str`
  - `word_count(html: str) -> int`
  - `save_webp(data: bytes, dest: pathlib.Path, max_side: int = 1600, quality: int = 80) -> tuple[int, int]` (returns the saved width and height)
  - `http_get(url: str, *, cache_dir: pathlib.Path, min_interval: float = 0.5) -> bytes`: a polite, cached GET with User-Agent `meilisearch-wordpress-demo/1.0 (+https://github.com/meilisearch/meilisearch-wordpress)`, keyed by URL hash under `scripts/.cache/`.
  - `write_json(path: pathlib.Path, data) -> None`: stable output with sorted keys, `ensure_ascii=False` and a trailing newline.

- [ ] **Step 1: Environment**

`scripts/requirements.txt`:

```
requests==2.32.5
Pillow==11.3.0
pytest==8.4.2
```

Create the venv: `python3 -m venv scripts/.venv && scripts/.venv/bin/pip install -r scripts/requirements.txt`. Add `scripts/.venv/` and `scripts/.cache/` to `.gitignore`.

`scripts/pyproject.toml`:

```toml
[tool.pytest.ini_options]
testpaths = ["tests"]
pythonpath = ["."]
```

- [ ] **Step 2: Failing tests (`scripts/tests/test_common.py`)**

```python
import io
from pathlib import Path

from PIL import Image

import common


def test_rng_is_deterministic_per_name():
    assert [common.rng("a").random() for _ in range(1)] == [common.rng("a").random()]
    assert common.rng("a").random() != common.rng("b").random()


def test_sanitize_keeps_allowed_tags_and_only_href_src():
    html = '<div class="x"><p style="color:red">Hi <a href="https://nasa.gov" onclick="x()">there</a></p><script>alert(1)</script><img src="a.jpg" onerror="x"></div>'
    out = common.sanitize_html(html)
    assert out == '<p>Hi <a href="https://nasa.gov">there</a></p><img src="a.jpg">'


def test_sanitize_drops_javascript_urls():
    assert common.sanitize_html('<p><a href="javascript:alert(1)">x</a></p>') == "<p><a>x</a></p>"


def test_sanitize_unwraps_unknown_tags_but_keeps_text():
    assert common.sanitize_html("<section><p>One <span>two</span></p></section>") == "<p>One two</p>"


def test_word_count_ignores_markup():
    assert common.word_count("<p>One <strong>two</strong></p><p>three</p>") == 3


def test_save_webp_resizes_longest_side(tmp_path: Path):
    buf = io.BytesIO()
    Image.new("RGB", (4000, 1000), "red").save(buf, "JPEG")
    w, h = common.save_webp(buf.getvalue(), tmp_path / "x.webp")
    assert (w, h) == (1600, 400)
    assert Image.open(tmp_path / "x.webp").format == "WEBP"


def test_save_webp_never_upscales(tmp_path: Path):
    buf = io.BytesIO()
    Image.new("RGB", (800, 600), "blue").save(buf, "PNG")
    assert common.save_webp(buf.getvalue(), tmp_path / "y.webp") == (800, 600)


def test_write_json_is_stable(tmp_path: Path):
    common.write_json(tmp_path / "a.json", {"b": 1, "a": "é"})
    assert (tmp_path / "a.json").read_text(encoding="utf-8") == '{\n  "a": "é",\n  "b": 1\n}\n'
```

Run: `cd scripts && .venv/bin/pytest -q`. Expected: FAIL (`ModuleNotFoundError: common`).

- [ ] **Step 3: Implement `scripts/common.py`**

```python
"""Shared helpers for the demo data scripts: deterministic RNG, HTML sanitising, images, polite HTTP."""
from __future__ import annotations

import hashlib
import io
import json
import random
import re
import time
from html import escape
from html.parser import HTMLParser
from pathlib import Path

import requests
from PIL import Image

SEED = 20261002
USER_AGENT = "meilisearch-wordpress-demo/1.0 (+https://github.com/meilisearch/meilisearch-wordpress)"
ALLOWED_TAGS = frozenset({"p", "h2", "h3", "ul", "ol", "li", "blockquote", "figure", "figcaption", "img", "a", "strong", "em"})
VOID_TAGS = frozenset({"img"})
DROP_WITH_CONTENT = frozenset({"script", "style", "iframe", "noscript", "svg", "form", "button"})
_last_request = 0.0


def rng(name: str) -> random.Random:
    digest = hashlib.sha256(f"{SEED}:{name}".encode()).digest()
    return random.Random(int.from_bytes(digest[:8], "big"))


class _Sanitizer(HTMLParser):
    def __init__(self, allowed: frozenset[str]) -> None:
        super().__init__(convert_charrefs=True)
        self.allowed = allowed
        self.out: list[str] = []
        self.drop_depth = 0

    def handle_starttag(self, tag, attrs):
        if tag in DROP_WITH_CONTENT:
            self.drop_depth += 1
            return
        if self.drop_depth or tag not in self.allowed:
            return
        kept = []
        for name, value in attrs:
            if name in ("href", "src") and value and not re.match(r"\s*(javascript|data|vbscript):", value, re.I):
                kept.append(f' {name}="{escape(value, quote=True)}"')
        self.out.append(f"<{tag}{''.join(kept)}>")

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)

    def handle_endtag(self, tag):
        if tag in DROP_WITH_CONTENT:
            self.drop_depth = max(0, self.drop_depth - 1)
            return
        if self.drop_depth or tag not in self.allowed or tag in VOID_TAGS:
            return
        self.out.append(f"</{tag}>")

    def handle_data(self, data):
        if not self.drop_depth:
            self.out.append(escape(data, quote=False))


def sanitize_html(html: str, allowed: frozenset[str] = ALLOWED_TAGS) -> str:
    parser = _Sanitizer(allowed)
    parser.feed(html)
    parser.close()
    out = "".join(parser.out)
    out = re.sub(r"<(p|li|h2|h3|figcaption)>\s*</\1>", "", out)
    return re.sub(r"\s+\n|\n\s+", "\n", out).strip()


def strip_tags(html: str) -> str:
    return re.sub(r"\s+", " ", sanitize_html(html, frozenset())).strip()


def word_count(html: str) -> int:
    return len(strip_tags(html).split())


def save_webp(data: bytes, dest: Path, max_side: int = 1600, quality: int = 80) -> tuple[int, int]:
    image = Image.open(io.BytesIO(data))
    image = image.convert("RGB")
    image.thumbnail((max_side, max_side), Image.LANCZOS)
    dest.parent.mkdir(parents=True, exist_ok=True)
    image.save(dest, "WEBP", quality=quality, method=6)
    return image.size


def http_get(url: str, *, cache_dir: Path, min_interval: float = 0.5) -> bytes:
    global _last_request
    cache_dir.mkdir(parents=True, exist_ok=True)
    cached = cache_dir / hashlib.sha256(url.encode()).hexdigest()
    if cached.exists():
        return cached.read_bytes()
    wait = min_interval - (time.monotonic() - _last_request)
    if wait > 0:
        time.sleep(wait)
    for attempt in range(4):
        response = requests.get(url, headers={"User-Agent": USER_AGENT}, timeout=60)
        _last_request = time.monotonic()
        if response.status_code in (429, 500, 502, 503, 504):
            time.sleep(2 ** attempt * 2)
            continue
        response.raise_for_status()
        cached.write_bytes(response.content)
        return response.content
    response.raise_for_status()
    raise RuntimeError(f"GET {url} kept failing")


def write_json(path: Path, data) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2, sort_keys=True, ensure_ascii=False) + "\n", encoding="utf-8")
```

The `sanitize_html` tests expect `<img src="a.jpg">` with no closing tag, and the parser emits none for void tags, so it matches. The `\n` cleanup only touches whitespace.

- [ ] **Step 4: Tests pass**

Run: `cd scripts && .venv/bin/pytest -q`. Expected: `8 passed`.

- [ ] **Step 5: Commit**

```bash
git add scripts .gitignore
git commit -m "Data tooling: deterministic RNG, HTML sanitiser, WebP images, polite cached HTTP"
```

### Task D3: NASA articles snapshot (`scripts/nasa.py`)

**Files:**
- Create: `scripts/nasa.py`, `scripts/nasa_topics.json`, `scripts/nasa_third_party.txt`, `scripts/contact_sheet.py`, `scripts/tests/test_nasa.py`
- Output (committed): `data/blog/articles.json`, `data/blog/manifest.json`, `data/blog/images/*.webp` (LFS)

**Interfaces:**
- Consumes: `common.*` (Task D2).
- Produces:
  - `data/blog/articles.json`: a list sorted by `date_gmt` descending. Each item has exactly these keys:
    - `id: int` (source post ID), `slug: str`, `title: str` (plain text, entities decoded)
    - `date_gmt: "YYYY-MM-DDTHH:MM:SS"`, `author: str`
    - `topic: str`, one of the six topic slugs below
    - `tags: list[str]`, `excerpt: str` (plain text)
    - `content: str` (sanitized HTML; inline image `src` values are `inline/<file>.webp`, relative to `data/blog/images/`)
    - `image: str` (relative path under `data/blog/images/`), `image_alt: str`, `image_is_fallback: bool`
    - `source_url: str`, `sticky: bool`
  - `data/blog/manifest.json`: `{"topics": {slug: [ids...]}}`.
  - Topic slugs and display names (Task D5 creates them as categories):
    - `missions` → "Missions"
    - `space-station` → "Space Station"
    - `earth` → "Earth"
    - `solar-system` → "Solar System"
    - `universe` → "Universe"
    - `history` → "History"

- [ ] **Step 1: Discover source categories**

```bash
for p in 1 2 3; do curl -s -A 'meilisearch-wordpress-demo/1.0' "https://science.nasa.gov/wp-json/wp/v2/categories?per_page=100&page=$p&orderby=count&order=desc&_fields=id,name,count"; done | python3 -c 'import json,sys
for line in sys.stdin.read().replace("][", "]\n[").splitlines():
    for c in json.loads(line): print(c["count"], c["id"], c["name"])' > scripts/.cache/nasa-categories.txt
grep -iE 'artemis|apollo|history|station|iss|earth|mars|moon|saturn|jupiter|hubble|webb|galax|exoplanet|star|sun|skywatch|photojournal|apod' scripts/.cache/nasa-categories.txt | head -80
```

Use the output to write `scripts/nasa_topics.json`. Keep only category names that appear in the output, and give each topic at least two. The order is the assignment priority.

```json
{
  "excluded_categories": ["Photojournal", "APOD"],
  "min_words": 150,
  "topics": [
    {"slug": "missions", "name": "Missions", "quota": 170, "categories": ["Artemis", "Kennedy Space Center", "Johnson Space Center", "Marshall Space Flight Center", "Commercial Crew"], "fallback_image": "https://images-assets.nasa.gov/image/SLS_MAF_20260401_ArtemisIILaunch_05/SLS_MAF_20260401_ArtemisIILaunch_05~large.jpg"},
    {"slug": "space-station", "name": "Space Station", "quota": 140, "categories": ["International Space Station (ISS)", "ISS Research"], "fallback_image": "https://images-assets.nasa.gov/image/iss043e000030/iss043e000030~medium.jpg"},
    {"slug": "earth", "name": "Earth", "quota": 180, "categories": ["Earth", "Earth Observatory", "Wildfires", "Hurricanes & Typhoons", "Extreme Weather Events"], "fallback_image": "https://images-assets.nasa.gov/image/GSFC_20171208_Archive_e001646/GSFC_20171208_Archive_e001646~medium.jpg"},
    {"slug": "solar-system", "name": "Solar System", "quota": 200, "categories": ["The Solar System", "Mars", "Saturn", "Saturn Moons", "Cassini", "Earth's Moon", "Jupiter", "Curiosity (Rover)"], "fallback_image": "https://images-assets.nasa.gov/image/PIA22766/PIA22766~small.jpg"},
    {"slug": "universe", "name": "Universe", "quota": 200, "categories": ["Hubble Space Telescope", "James Webb Space Telescope (JWST)", "Galaxies", "Exoplanets", "Stars"], "fallback_image": "https://images-assets.nasa.gov/image/GSFC_20171208_Archive_e001569/GSFC_20171208_Archive_e001569~small.jpg"},
    {"slug": "history", "name": "History", "quota": 110, "categories": ["Skywatching", "Skywatching Tips", "Apollo", "NASA History"], "fallback_image": "https://images-assets.nasa.gov/image/jsc2007e034221/jsc2007e034221~medium.jpg"}
  ]
}
```

(The quotas sum to 1000. Replace any category name that the discovery output doesn't contain, and keep the JSON shape.) The script fails loudly when a listed name doesn't exist (Step 4).

`scripts/nasa_third_party.txt` lists one case-insensitive substring per line, matched against the image caption and the file name:

```
via flickr
flickr
cc by
creative commons
getty
ap photo
associated press
reuters
shutterstock
istock
canva
courtesy of
used with permission
©
copyright
```

- [ ] **Step 2: Failing tests (`scripts/tests/test_nasa.py`)**

```python
import nasa

TOPICS = [
    {"slug": "missions", "categories": ["Artemis"]},
    {"slug": "solar-system", "categories": ["Mars", "Saturn"]},
]
PATTERNS = ["via flickr", "cc by", "getty", "©"]


def test_assign_topic_uses_priority_order():
    assert nasa.assign_topic(["Saturn", "Artemis"], TOPICS) == "missions"
    assert nasa.assign_topic(["Mars"], TOPICS) == "solar-system"
    assert nasa.assign_topic(["Hubble"], TOPICS) is None


def test_excluded_categories():
    assert nasa.is_excluded(["APOD", "Mars"], ["Photojournal", "APOD"])
    assert not nasa.is_excluded(["Mars"], ["Photojournal", "APOD"])


def test_third_party_by_pattern():
    assert nasa.is_third_party("Meteors in 2017. Ben Goldstein via Flickr CC BY-NC-SA", "x.jpg", PATTERNS)
    assert nasa.is_third_party("", "Orionid_Ben%20Goldstein%20via%20Flickr_CC%20BY-NC-SA.jpg", PATTERNS)


def test_third_party_by_non_nasa_credit():
    assert nasa.is_third_party("A rocket. Credit: John Smith", "rocket.jpg", PATTERNS)
    assert nasa.is_third_party("", "Night scene_credit_Bill Dunford.png", PATTERNS)


def test_nasa_credit_is_fine():
    assert not nasa.is_third_party("Saturn's rings. Credit: NASA/JPL-Caltech/Space Science Institute", "PIA1.jpg", PATTERNS)
    assert not nasa.is_third_party("Pinwheel galaxy. Image credit: NASA, ESA, CSA, STScI", "m101.jpg", PATTERNS)
    assert not nasa.is_third_party("A false-color satellite image of the river.", "flood.jpg", PATTERNS)


def test_rewrite_figures_drops_rejected_and_renames_kept():
    html = (
        '<p>Intro</p>'
        '<figure><img src="https://x/a.jpg"><figcaption>Credit: NASA</figcaption></figure>'
        '<figure><img src="https://x/b.jpg"><figcaption>Photo via Flickr</figcaption></figure>'
        '<p>End</p>'
    )
    kept = []

    def decide(src, caption):
        if nasa.is_third_party(caption, src, PATTERNS):
            return None
        kept.append(src)
        return f"inline/1-{len(kept)}.webp"

    out = nasa.rewrite_images(html, decide)
    assert out == '<p>Intro</p><figure><img src="inline/1-1.webp"><figcaption>Credit: NASA</figcaption></figure><p>End</p>'
    assert kept == ["https://x/a.jpg"]


def test_rewrite_handles_bare_images():
    out = nasa.rewrite_images('<p>a <img src="https://x/c.jpg"> b</p>', lambda src, cap: None)
    assert out == "<p>a  b</p>"


def test_select_respects_quotas_dedupes_and_marks_one_sticky_per_topic():
    posts = [
        {"id": 3, "topic": "missions", "date_gmt": "2026-03-01T00:00:00", "image_is_fallback": False},
        {"id": 2, "topic": "missions", "date_gmt": "2026-02-01T00:00:00", "image_is_fallback": False},
        {"id": 1, "topic": "missions", "date_gmt": "2026-01-01T00:00:00", "image_is_fallback": False},
        {"id": 3, "topic": "missions", "date_gmt": "2026-03-01T00:00:00", "image_is_fallback": False},
        {"id": 9, "topic": "solar-system", "date_gmt": "2026-04-01T00:00:00", "image_is_fallback": True},
        {"id": 8, "topic": "solar-system", "date_gmt": "2026-03-15T00:00:00", "image_is_fallback": False},
    ]
    chosen = nasa.select(posts, {"missions": 2, "solar-system": 5})
    assert [p["id"] for p in chosen] == [9, 8, 3, 2]
    assert {p["id"]: p["sticky"] for p in chosen} == {9: False, 8: True, 3: True, 2: False}
```

Run: `cd scripts && .venv/bin/pytest -q tests/test_nasa.py`. Expected: FAIL (`ModuleNotFoundError: nasa`).

- [ ] **Step 3: Implement the pure functions in `scripts/nasa.py`**

```python
"""Builds data/blog from science.nasa.gov (US government work, public domain): ~1,000 articles across six topics."""
from __future__ import annotations

import argparse
import html as htmllib
import re
import sys
from pathlib import Path
from urllib.parse import unquote, urlparse

import common

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "data" / "blog"
CACHE = Path(__file__).resolve().parent / ".cache" / "nasa"
API = "https://science.nasa.gov/wp-json/wp/v2"
EMBED = "_embed=wp:featuredmedia,wp:term,author"
NASA_CREDIT = re.compile(r"\b(nasa|jpl|stsci|esa|csa|goddard|caltech|jhuapl|swri|usgs|noaa)\b", re.I)


def assign_topic(category_names: list[str], topics: list[dict]) -> str | None:
    names = set(category_names)
    for topic in topics:
        if names.intersection(topic["categories"]):
            return topic["slug"]
    return None


def is_excluded(category_names: list[str], excluded: list[str]) -> bool:
    return bool(set(category_names).intersection(excluded))


def is_third_party(caption: str, filename: str, patterns: list[str]) -> bool:
    name = unquote(filename).lower()
    text = (caption or "").lower()
    if any(p.lower() in text or p.lower() in name for p in patterns):
        return True
    credit = re.search(r"credit\s*:\s*(.+)$", caption or "", re.I | re.S)
    if credit and not NASA_CREDIT.search(credit.group(1)):
        return True
    file_credit = re.search(r"credit[_\s-]+([^/]+?)\.(jpe?g|png|webp|gif|tiff?)$", name)
    if file_credit and not NASA_CREDIT.search(file_credit.group(1)):
        return True
    return False


_FIGURE = re.compile(r"<figure>(.*?)</figure>", re.S)
_IMG = re.compile(r'<img src="([^"]*)">')
_CAPTION = re.compile(r"<figcaption>(.*?)</figcaption>", re.S)


def rewrite_images(content: str, decide) -> str:
    """decide(src, caption) -> new src, or None to drop the image (and its figure)."""

    def figure(match: re.Match) -> str:
        inner = match.group(1)
        img = _IMG.search(inner)
        if not img:
            return match.group(0)
        caption = common.strip_tags(_CAPTION.search(inner).group(1)) if _CAPTION.search(inner) else ""
        new_src = decide(htmllib.unescape(img.group(1)), caption)
        if new_src is None:
            return ""
        return "<figure>" + inner.replace(img.group(0), f'<img src="{new_src}">', 1) + "</figure>"

    content = _FIGURE.sub(figure, content)

    def bare(match: re.Match) -> str:
        if match.group(1).startswith("inline/"):
            return match.group(0)
        new_src = decide(htmllib.unescape(match.group(1)), "")
        return "" if new_src is None else f'<img src="{new_src}">'

    return _IMG.sub(bare, content)


def select(posts: list[dict], quotas: dict[str, int]) -> list[dict]:
    """Newest first per topic, deduplicated by id, capped by quota; the newest post with a real image per topic is sticky."""
    seen: set[int] = set()
    per_topic: dict[str, list[dict]] = {}
    for post in sorted(posts, key=lambda p: p["date_gmt"], reverse=True):
        if post["id"] in seen:
            continue
        bucket = per_topic.setdefault(post["topic"], [])
        if len(bucket) >= quotas.get(post["topic"], 0):
            continue
        seen.add(post["id"])
        bucket.append(dict(post, sticky=False))
    for bucket in per_topic.values():
        for post in bucket:
            if not post["image_is_fallback"]:
                post["sticky"] = True
                break
    return sorted((p for b in per_topic.values() for p in b), key=lambda p: p["date_gmt"], reverse=True)
```

Run the tests. Expected: `8 passed` in `test_nasa.py`. If `test_rewrite_handles_bare_images` gives `<p>a  b</p>` with different spacing, the assertion is the spec: keep both spaces.

- [ ] **Step 4: Implement fetching and the CLI (`main`) in `scripts/nasa.py`**

Append:

```python
def _json(url: str):
    import json
    return json.loads(common.http_get(url, cache_dir=CACHE))


def category_ids(names: set[str]) -> dict[str, int]:
    found: dict[str, int] = {}
    page = 1
    while True:
        batch = _json(f"{API}/categories?per_page=100&page={page}&_fields=id,name")
        if not batch:
            break
        for c in batch:
            name = htmllib.unescape(c["name"])
            if name in names:
                found[name] = c["id"]
        page += 1
        if page > 30:
            break
    missing = names - found.keys()
    if missing:
        sys.exit(f"nasa_topics.json lists categories that do not exist on science.nasa.gov: {sorted(missing)}")
    return found


def terms(post: dict) -> list[str]:
    groups = post.get("_embedded", {}).get("wp:term", [])
    return [htmllib.unescape(t["name"]) for g in groups for t in g if t.get("taxonomy") == "category"]


def build(post: dict, topic: str, cfg: dict, patterns: list[str], topics_by_slug: dict) -> dict | None:
    names = terms(post)
    if is_excluded(names, cfg["excluded_categories"]):
        return None
    content = common.sanitize_html(post["content"]["rendered"])
    if common.word_count(content) < cfg["min_words"]:
        return None
    pid = post["id"]
    counter = {"n": 0}

    def decide(src: str, caption: str):
        if is_third_party(caption, urlparse(src).path.rsplit("/", 1)[-1], patterns):
            return None
        try:
            data = common.http_get(src, cache_dir=CACHE / "img")
        except Exception:
            return None
        counter["n"] += 1
        rel = f"inline/{pid}-{counter['n']}.webp"
        common.save_webp(data, OUT / "images" / rel)
        return rel

    content = rewrite_images(content, decide)
    image, alt, fallback = None, "", True
    media = (post.get("_embedded", {}).get("wp:featuredmedia") or [{}])[0]
    src = media.get("source_url")
    caption = common.strip_tags((media.get("caption") or {}).get("rendered", ""))
    if src and not is_third_party(caption, urlparse(src).path.rsplit("/", 1)[-1], patterns):
        try:
            common.save_webp(common.http_get(src, cache_dir=CACHE / "img"), OUT / "images" / f"{pid}.webp")
            image, alt, fallback = f"{pid}.webp", media.get("alt_text") or caption[:200], False
        except Exception:
            pass
    if image is None:
        image, alt = f"fallback-{topic}.webp", topics_by_slug[topic]["name"]
    authors = post.get("_embedded", {}).get("author") or [{}]
    return {
        "id": pid,
        "slug": post["slug"],
        "title": common.strip_tags(htmllib.unescape(post["title"]["rendered"])),
        "date_gmt": post["date_gmt"],
        "author": _author(authors[0].get("name")),
        "topic": topic,
        "tags": sorted(set(names) - {"Uncategorized"}),
        "excerpt": common.strip_tags(htmllib.unescape(post["excerpt"]["rendered"]))[:400],
        "content": content,
        "image": image,
        "image_alt": alt,
        "image_is_fallback": fallback,
        "source_url": post["link"],
    }


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--refresh", action="store_true", help="re-select posts instead of replaying data/blog/manifest.json")
    args = parser.parse_args()
    import json
    cfg = json.loads((Path(__file__).parent / "nasa_topics.json").read_text())
    patterns = [l.strip() for l in (Path(__file__).parent / "nasa_third_party.txt").read_text().splitlines() if l.strip()]
    topics_by_slug = {t["slug"]: t for t in cfg["topics"]}
    ids = category_ids({c for t in cfg["topics"] for c in t["categories"]})
    for t in cfg["topics"]:
        common.save_webp(common.http_get(t["fallback_image"], cache_dir=CACHE / "img"), OUT / "images" / f"fallback-{t['slug']}.webp")

    manifest_path = OUT / "manifest.json"
    candidates: list[dict] = []
    if manifest_path.exists() and not args.refresh:
        manifest = json.loads(manifest_path.read_text())
        for slug, post_ids in manifest["topics"].items():
            for i in range(0, len(post_ids), 50):
                chunk = ",".join(map(str, post_ids[i:i + 50]))
                for post in _json(f"{API}/posts?include={chunk}&per_page=50&{EMBED}"):
                    built = build(post, slug, cfg, patterns, topics_by_slug)
                    if built:
                        candidates.append(built)
    else:
        for topic in cfg["topics"]:
            cat_ids = ",".join(str(ids[c]) for c in topic["categories"])
            page, kept = 1, 0
            while kept < topic["quota"] * 1.3 and page <= 40:
                for post in _json(f"{API}/posts?categories={cat_ids}&per_page=50&page={page}&{EMBED}"):
                    if assign_topic(terms(post), cfg["topics"]) != topic["slug"]:
                        continue
                    built = build(post, topic["slug"], cfg, patterns, topics_by_slug)
                    if built:
                        candidates.append(built)
                        kept += 1
                page += 1
                print(f"{topic['slug']}: page {page - 1}, {kept} candidates", file=sys.stderr)

    chosen = select(candidates, {t["slug"]: t["quota"] for t in cfg["topics"]})
    keep_files = {p["image"] for p in chosen} | {m for p in chosen for m in re.findall(r'src="(inline/[^"]+)"', p["content"])}
    for path in (OUT / "images").rglob("*.webp"):
        rel = path.relative_to(OUT / "images").as_posix()
        if rel not in keep_files and not rel.startswith("fallback-"):
            path.unlink()
    common.write_json(OUT / "articles.json", chosen)
    common.write_json(manifest_path, {"topics": {t["slug"]: sorted(p["id"] for p in chosen if p["topic"] == t["slug"]) for t in cfg["topics"]}})
    print(f"{len(chosen)} articles, {sum(1 for p in chosen if p['image_is_fallback'])} with a fallback image", file=sys.stderr)


if __name__ == "__main__":
    main()
```

Also add this helper above `build()`:

```python
def _author(name: str | None) -> str:
    if not name:
        return "NASA Science"
    return name.title() if name.isupper() else name
```

Add a test for `_author`:

```python
def test_author_normalisation():
    assert nasa._author(None) == "NASA Science"
    assert nasa._author("AMANDA BARNETT") == "Amanda Barnett"
    assert nasa._author("Alicia Cermak") == "Alicia Cermak"
```

Run `pytest -q`. Expected: all pass.

- [ ] **Step 5: Contact sheet**

`scripts/contact_sheet.py` takes `data/blog` and writes `data/blog/contact-sheet.html` (git-ignored). The page is a grid with one card per kept image (featured and inline): the image, its post title, its source URL and the caption or alt text. It exists for the manual licence review (spec § 11).

```python
"""Writes <data dir>/contact-sheet.html: every kept image with its post and caption, for manual licence review."""
import html
import json
import re
import sys
from pathlib import Path

base = Path(sys.argv[1])
items = json.loads((base / "articles.json").read_text())
cards = []
for a in items:
    for src in [a["image"], *re.findall(r'src="(inline/[^"]+)"', a["content"])]:
        if src.startswith("fallback-"):
            continue
        cards.append(
            f'<figure><img loading="lazy" src="images/{html.escape(src)}"><figcaption><a href="{html.escape(a["source_url"])}">'
            f'{html.escape(a["title"])}</a><br><small>{html.escape(a["image_alt"])}</small></figcaption></figure>'
        )
(base / "contact-sheet.html").write_text(
    "<!doctype html><meta charset=utf-8><title>Contact sheet</title><style>body{font:13px system-ui;display:grid;"
    "grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px}img{width:100%;aspect-ratio:4/3;object-fit:cover}</style>"
    + "".join(cards),
    encoding="utf-8",
)
print(f"{len(cards)} images")
```

Add `data/*/contact-sheet.html` to `.gitignore`.

- [ ] **Step 6: Run it for real**

```bash
cd scripts && .venv/bin/python nasa.py --refresh && .venv/bin/python contact_sheet.py ../data/blog && cd ..
python3 -c "import json;a=json.load(open('data/blog/articles.json'));import collections;print(len(a), collections.Counter(x['topic'] for x in a), sum(x['sticky'] for x in a))"
du -sh data/blog/images
```

Expected: about 1000 articles (at least 900; if a topic falls short, add categories to it in `nasa_topics.json` and rerun), roughly matching the quota per topic, 6 sticky, and images under 250 MB. Open `data/blog/contact-sheet.html` and list any image that looks third-party (watermarks, stock photos, people's portraits without a NASA credit). Add a pattern or the file's source to `nasa_third_party.txt`, then run `nasa.py` again **without** `--refresh`, so the manifest is replayed and only the image decisions change.

- [ ] **Step 7: Commit (LFS)**

```bash
git lfs track 'data/**/images/**'
git add .gitattributes scripts data/blog
git commit -m "Blog data: ~1,000 science.nasa.gov articles with NASA-only images (deterministic snapshot)"
```

### Task D4: Met prints snapshot (`scripts/met.py`)

**Files:**
- Create: `scripts/met.py`, `scripts/tests/test_met.py`
- Output (committed): `data/shop/artworks.json`, `data/shop/manifest.json`, `data/shop/images/*.webp` (LFS)

**Interfaces:**
- Consumes: `common.*`.
- Produces: `data/shop/artworks.json`, a list sorted by `id`. Each item has:
  - `id: int` (Met object ID), `title`, `artist` ("Unknown artist" when empty), `date: str`, `begin_date: int`
  - `department`, `classification` (first segment), `category` (one of `Paintings`, `Japanese Prints`, `Prints`, `Drawings`, `Photographs`)
  - `medium`, `medium_bucket`, `century`, `culture`, `dimensions`, `credit_line`, `tags: list[str]`, `object_url`
  - `featured: bool`
  - `image: "<id>.webp"`, `image_size: [w, h]`, `orientation: "portrait"|"landscape"|"square"`
  - `price_regular: int` (S/Unframed variant), `on_sale: bool`
  - `variations: list[{size_code, size_label, finish_code, finish_label, sku, regular_price: int, sale_price: int|None, in_stock: bool}]`, 12 per product
  - `reviews: list[{rating: int, text: str, days_ago: int}]`

  Task D7 consumes exactly these keys.

- [ ] **Step 1: Failing tests (`scripts/tests/test_met.py`)**

```python
import met


def test_medium_buckets():
    assert met.bucket_medium("Polychrome woodblock print; ink and color on paper") == "Woodblock print"
    assert met.bucket_medium("Oil on canvas") == "Oil on canvas"
    assert met.bucket_medium("Oil on wood") == "Oil on wood"
    assert met.bucket_medium("Tempera and gold on wood") == "Tempera"
    assert met.bucket_medium("Watercolor and graphite") == "Watercolor"
    assert met.bucket_medium("Etching and drypoint") == "Etching & engraving"
    assert met.bucket_medium("Lithograph") == "Lithograph"
    assert met.bucket_medium("Albumen silver print from glass negative") == "Photograph"
    assert met.bucket_medium("Pen and brown ink") == "Ink"
    assert met.bucket_medium("Black chalk on blue paper") == "Chalk, charcoal & pastel"
    assert met.bucket_medium("Oil on copper") == "Oil, other"
    assert met.bucket_medium("") == "Mixed media"


def test_century():
    assert met.century(1889) == "19th century"
    assert met.century(1901) == "20th century"
    assert met.century(1800) == "18th century"
    assert met.century(1621) == "17th century"
    assert met.century(1211) == "13th century"
    assert met.century(-300) == "3rd century BCE"
    assert met.century(-301) == "4th century BCE"
    assert met.century(0) == "1st century"


def test_category():
    assert met.category("Prints", "Asian Art") == "Japanese Prints"
    assert met.category("Prints", "Drawings and Prints") == "Prints"
    assert met.category("Paintings", "European Paintings") == "Paintings"


def test_orientation():
    assert met.orientation(1000, 800) == "landscape"
    assert met.orientation(800, 1000) == "portrait"
    assert met.orientation(1000, 1030) == "square"


def test_variations_prices_and_labels():
    product = met.build_product(
        {"objectID": 436535, "title": "Wheat Field with Cypresses", "artistDisplayName": "Vincent van Gogh",
         "objectDate": "1889", "objectBeginDate": 1889, "department": "European Paintings", "classification": "Paintings",
         "medium": "Oil on canvas", "culture": "", "dimensions": "28 7/8 × 36 3/4 in.", "creditLine": "Purchase, 1993",
         "tags": [{"term": "Landscapes"}, {"term": "Cypresses"}], "objectURL": "https://www.metmuseum.org/art/collection/search/436535",
         "isHighlight": True},
        (1600, 1250),
    )
    assert product["category"] == "Paintings" and product["featured"] is True
    assert product["orientation"] == "landscape"
    assert len(product["variations"]) == 12
    first = product["variations"][0]
    assert (first["size_label"], first["finish_label"], first["regular_price"]) == ("30 × 24 cm", "Unframed", 45)
    last = product["variations"][-1]
    assert (last["size_label"], last["finish_label"], last["regular_price"]) == ("100 × 80 cm", "Oak frame", 260)
    assert first["sku"] == "MET-436535-S-U" and last["sku"] == "MET-436535-XL-O"
    assert product["price_regular"] == 45
    assert product["tags"] == ["Cypresses", "Landscapes"]


def test_build_product_is_deterministic():
    obj = {"objectID": 1, "title": "T", "artistDisplayName": "", "objectDate": "", "objectBeginDate": 1850,
           "department": "Drawings and Prints", "classification": "Prints", "medium": "Etching", "culture": "",
           "dimensions": "", "creditLine": "", "tags": None, "objectURL": "", "isHighlight": False}
    a, b = met.build_product(obj, (800, 1000)), met.build_product(obj, (800, 1000))
    assert a == b
    assert a["artist"] == "Unknown artist"
    assert a["variations"][0]["size_label"] == "24 × 30 cm"


def test_sale_and_stock_rates_over_many_products():
    products = [
        met.build_product({"objectID": i, "title": "T", "artistDisplayName": "A", "objectDate": "", "objectBeginDate": 1700,
                           "department": "European Paintings", "classification": "Paintings", "medium": "Oil on canvas",
                           "culture": "", "dimensions": "", "creditLine": "", "tags": [], "objectURL": "", "isHighlight": False}, (1000, 800))
        for i in range(2000)
    ]
    sale = sum(p["on_sale"] for p in products) / len(products)
    out = sum(not v["in_stock"] for p in products for v in p["variations"]) / (12 * len(products))
    assert 0.07 < sale < 0.13
    assert 0.015 < out < 0.045
    on_sale = next(p for p in products if p["on_sale"])
    assert all(v["sale_price"] == round(v["regular_price"] * 0.8) for v in on_sale["variations"])
    assert all(0 <= len(p["reviews"]) <= 12 for p in products)
    assert all(1 <= r["rating"] <= 5 for p in products for r in p["reviews"])


def test_select_candidates_prioritises_highlights_and_balances_departments():
    rows = [
        {"Object ID": "1", "Is Public Domain": "True", "Is Highlight": "False", "Is Timeline Work": "False", "Department": "A", "Classification": "Paintings"},
        {"Object ID": "2", "Is Public Domain": "True", "Is Highlight": "True", "Is Timeline Work": "False", "Department": "B", "Classification": "Prints|Ephemera"},
        {"Object ID": "3", "Is Public Domain": "False", "Is Highlight": "True", "Is Timeline Work": "False", "Department": "A", "Classification": "Paintings"},
        {"Object ID": "4", "Is Public Domain": "True", "Is Highlight": "False", "Is Timeline Work": "True", "Department": "A", "Classification": "Drawings"},
        {"Object ID": "5", "Is Public Domain": "True", "Is Highlight": "False", "Is Timeline Work": "False", "Department": "A", "Classification": "Sculpture"},
        {"Object ID": "6", "Is Public Domain": "True", "Is Highlight": "False", "Is Timeline Work": "False", "Department": "C", "Classification": "Photographs"},
    ]
    assert met.select_candidates(rows)[:2] == [2, 4]
    assert sorted(met.select_candidates(rows)) == [1, 2, 4, 6]
```

Run: `cd scripts && .venv/bin/pytest -q tests/test_met.py`. Expected: FAIL (`ModuleNotFoundError: met`).

- [ ] **Step 2: Implement `scripts/met.py`**

```python
"""Builds data/shop from The Met Open Access (CC0): ~1,500 public-domain artworks sold as variable print products."""
from __future__ import annotations

import argparse
import csv
import io
import json
import re
import sys
from pathlib import Path

import common

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "data" / "shop"
CACHE = Path(__file__).resolve().parent / ".cache" / "met"
CSV_URL = "https://media.githubusercontent.com/media/metmuseum/openaccess/master/MetObjects.csv"
API = "https://collectionapi.metmuseum.org/public/collection/v1/objects/"
TARGET = 1500
CLASSES = ("Paintings", "Prints", "Drawings", "Photographs")
SIZES = [("S", 30, 24, 45), ("M", 50, 40, 70), ("L", 70, 56, 110), ("XL", 100, 80, 160)]
FINISHES = [("U", "Unframed", 0), ("B", "Black frame", 50), ("O", "Oak frame", 100)]
MEDIUM_RULES = [
    (r"woodblock", "Woodblock print"),
    (r"oil on canvas", "Oil on canvas"),
    (r"oil on (wood|panel|oak)", "Oil on wood"),
    (r"tempera", "Tempera"),
    (r"watercolou?r|gouache", "Watercolor"),
    (r"etching|engraving|drypoint|aquatint|mezzotint", "Etching & engraving"),
    (r"lithograph", "Lithograph"),
    (r"albumen|gelatin silver|salted paper|daguerreotype|platinum print|photograph|cyanotype", "Photograph"),
    (r"chalk|charcoal|pastel|graphite|crayon", "Chalk, charcoal & pastel"),
    (r"\bink\b", "Ink"),
    (r"\boil\b", "Oil, other"),
]
REVIEW_TEXTS = {
    5: ["Stunning print, the colours are spot on.", "Looks even better framed on the wall.", "Beautiful paper and very sharp detail."],
    4: ["Lovely print, arrived well packed.", "Great quality for the price.", "Very happy, slightly warmer tones than on screen."],
    3: ["Nice, but smaller than I imagined.", "Good print, frame could be sturdier."],
    2: ["Colours a little flat compared to the original."],
}


def bucket_medium(medium: str) -> str:
    text = (medium or "").lower()
    for pattern, label in MEDIUM_RULES:
        if re.search(pattern, text):
            return label
    return "Mixed media"


def _ordinal(n: int) -> str:
    suffix = "th" if 10 <= n % 100 <= 20 else {1: "st", 2: "nd", 3: "rd"}.get(n % 10, "th")
    return f"{n}{suffix}"


def century(year: int) -> str:
    if year < 0:
        return f"{_ordinal((-year - 1) // 100 + 1)} century BCE"
    return f"{_ordinal(max(year - 1, 0) // 100 + 1)} century"


def category(classification: str, department: str) -> str:
    if classification == "Prints" and department == "Asian Art":
        return "Japanese Prints"
    return classification


def orientation(width: int, height: int) -> str:
    if width > height * 1.05:
        return "landscape"
    if height > width * 1.05:
        return "portrait"
    return "square"


def _size_label(long: int, short: int, orient: str) -> str:
    if orient == "landscape":
        return f"{long} × {short} cm"
    if orient == "portrait":
        return f"{short} × {long} cm"
    return f"{long} × {long} cm"


def build_product(obj: dict, image_size: tuple[int, int]) -> dict:
    oid = int(obj["objectID"])
    r = common.rng(f"met-product-{oid}")
    orient = orientation(*image_size)
    classification = (obj.get("classification") or "").split("|")[0]
    on_sale = r.random() < 0.10
    variations = []
    for size_code, long, short, base in SIZES:
        for finish_code, finish_label, extra in FINISHES:
            regular = base + extra
            variations.append({
                "size_code": size_code,
                "size_label": _size_label(long, short, orient),
                "finish_code": finish_code,
                "finish_label": finish_label,
                "sku": f"MET-{oid}-{size_code}-{finish_code}",
                "regular_price": regular,
                "sale_price": round(regular * 0.8) if on_sale else None,
                "in_stock": r.random() >= 0.03,
            })
    reviews = []
    for _ in range(r.randint(0, 12)):
        rating = r.choices([5, 4, 3, 2], weights=[55, 30, 10, 5])[0]
        reviews.append({"rating": rating, "text": r.choice(REVIEW_TEXTS[rating]), "days_ago": r.randint(3, 700)})
    begin = int(obj.get("objectBeginDate") or 0)
    return {
        "id": oid,
        "title": obj.get("title") or "Untitled",
        "artist": obj.get("artistDisplayName") or "Unknown artist",
        "date": obj.get("objectDate") or "",
        "begin_date": begin,
        "department": obj.get("department") or "",
        "classification": classification,
        "category": category(classification, obj.get("department") or ""),
        "medium": obj.get("medium") or "",
        "medium_bucket": bucket_medium(obj.get("medium") or ""),
        "century": century(begin),
        "culture": obj.get("culture") or "",
        "dimensions": obj.get("dimensions") or "",
        "credit_line": obj.get("creditLine") or "",
        "tags": sorted({t["term"] for t in (obj.get("tags") or []) if t.get("term")}),
        "object_url": obj.get("objectURL") or "",
        "featured": bool(obj.get("isHighlight")),
        "image": f"{oid}.webp",
        "image_size": list(image_size),
        "orientation": orient,
        "price_regular": variations[0]["regular_price"],
        "on_sale": on_sale,
        "variations": variations,
        "reviews": reviews,
    }


def select_candidates(rows) -> list[int]:
    tiers: list[dict[str, list[int]]] = [{}, {}, {}]
    for row in rows:
        if row.get("Is Public Domain") != "True":
            continue
        if (row.get("Classification") or "").split("|")[0] not in CLASSES:
            continue
        tier = 0 if row.get("Is Highlight") == "True" else 1 if row.get("Is Timeline Work") == "True" else 2
        tiers[tier].setdefault(row.get("Department") or "", []).append(int(row["Object ID"]))
    ordered: list[int] = []
    for tier_index, tier in enumerate(tiers):
        r = common.rng(f"met-order-{tier_index}")
        queues = {dept: r.sample(ids, len(ids)) for dept, ids in sorted(tier.items())}
        while any(queues.values()):  # round-robin across departments
            for dept in sorted(queues):
                if queues[dept]:
                    ordered.append(queues[dept].pop(0))
    return ordered


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--refresh", action="store_true", help="re-select objects instead of replaying data/shop/manifest.json")
    args = parser.parse_args()
    manifest_path = OUT / "manifest.json"
    if manifest_path.exists() and not args.refresh:
        candidates = json.loads(manifest_path.read_text())["ids"]
    else:
        raw = common.http_get(CSV_URL, cache_dir=CACHE)
        candidates = select_candidates(csv.DictReader(io.StringIO(raw.decode("utf-8-sig"))))
    products = []
    for oid in candidates:
        if len(products) >= TARGET:
            break
        try:
            obj = json.loads(common.http_get(f"{API}{oid}", cache_dir=CACHE / "objects", min_interval=0.1))
        except Exception as error:
            print(f"skip {oid}: {error}", file=sys.stderr)
            continue
        if not obj.get("isPublicDomain") or not obj.get("primaryImageSmall"):
            continue
        try:
            size = common.save_webp(common.http_get(obj["primaryImageSmall"], cache_dir=CACHE / "img", min_interval=0.1), OUT / "images" / f"{oid}.webp")
        except Exception as error:
            print(f"skip image {oid}: {error}", file=sys.stderr)
            continue
        products.append(build_product(obj, size))
        if len(products) % 100 == 0:
            print(f"{len(products)} products", file=sys.stderr)
    keep = {p["image"] for p in products}
    for path in (OUT / "images").glob("*.webp"):
        if path.name not in keep:
            path.unlink()
    products.sort(key=lambda p: p["id"])
    common.write_json(OUT / "artworks.json", products)
    common.write_json(manifest_path, {"ids": [p["id"] for p in products]})
    print(f"{len(products)} products", file=sys.stderr)


if __name__ == "__main__":
    main()
```

- [ ] **Step 3: Tests pass**

Run: `cd scripts && .venv/bin/pytest -q`. Expected: all pass. If `test_select_candidates_prioritises_highlights_and_balances_departments` fails only on order within a tier, the expected order `[2, 4]` (highlight tier first, then timeline) is the contract. Fix the code, not the test.

- [ ] **Step 4: Run it for real**

```bash
cd scripts && .venv/bin/python met.py --refresh && cd ..
python3 -c "import json,collections;a=json.load(open('data/shop/artworks.json'));print(len(a));print(collections.Counter(x['category'] for x in a));print(collections.Counter(x['medium_bucket'] for x in a).most_common())"
du -sh data/shop/images
```

Expected:
- 1500 products;
- at least 4 categories with over 50 products each;
- no medium bucket above 40%, except possibly "Woodblock print" or "Oil on canvas";
- images under 200 MB.

The CSV is about 300 MB; it's cached in `scripts/.cache` (git-ignored).

- [ ] **Step 5: Commit**

```bash
git add scripts data/shop
git commit -m "Shop data: 1,500 Met Open Access artworks as variable print products (deterministic snapshot)"
```

### Task D5: Blog import + Playwright harness

**Files:**
- Create: `package.json`, `playwright.config.ts`, `tests/e2e/utils.ts`, `tests/e2e/blog-import.spec.ts`
- Modify: `blog/setup.php` (real import), `bin/smoke.sh` (blog content checks)

**Interfaces:**
- Consumes: `data/blog/articles.json` (Task D3 keys), `/opt/demo/data`.
- Produces:
  - Categories with slugs `missions`, `space-station`, `earth`, `solar-system`, `universe` and `history`, used by Task D6's navigation.
  - Post meta `_demo_source_id` (int) and `_demo_source_url` (string), used by Task D10.
  - Users with role `author`, one per distinct `author`.
  - The plugin option `meilisearch_content = {post_types: ['post'], taxonomies: {post: ['category','post_tag']}, meta_keys: []}`.
  - The Playwright projects `blog` and `shop`, with `baseURL` taken from `BLOG_URL` / `SHOP_URL`.
  - From `tests/e2e/utils.ts`: `wp(site: 'blog'|'shop', ...args: string[]): string`, which runs WP-CLI in the container through `docker compose exec -T`.

- [ ] **Step 1: Playwright harness**

`package.json`:

```json
{
  "name": "wordpress-meilisearch-demo",
  "private": true,
  "scripts": {
    "test:e2e": "playwright test"
  },
  "devDependencies": {
    "@axe-core/playwright": "^4.13.0",
    "@playwright/test": "^1.63.0"
  }
}
```

`playwright.config.ts`:

```ts
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
```

`tests/e2e/utils.ts`:

```ts
import { execFileSync } from 'node:child_process';

/** Runs WP-CLI inside a site container and returns trimmed stdout. */
export function wp( site: 'blog' | 'shop', ...args: string[] ): string {
	return execFileSync( 'docker', [ 'compose', 'exec', '-T', site, 'wp', '--allow-root', ...args ], { encoding: 'utf8' } ).trim();
}
```

Run: `npm install && npx playwright install chromium`. Add `node_modules/`, `test-results/` and `playwright-report/` to `.gitignore` (already there from repo creation).

- [ ] **Step 2: Failing E2E tests (`tests/e2e/blog-import.spec.ts`)**

```ts
import { expect, test } from '@playwright/test';
import { wp } from './utils';

test( 'all articles are imported with a featured image and a source line', () => {
	const count = Number( wp( 'blog', 'post', 'list', '--post_type=post', '--post_status=publish', '--format=count' ) );
	expect( count ).toBeGreaterThanOrEqual( 900 );
	const withoutThumb = wp( 'blog', 'eval', 'echo count( get_posts( [ "post_type" => "post", "numberposts" => -1, "fields" => "ids", "meta_query" => [ [ "key" => "_thumbnail_id", "compare" => "NOT EXISTS" ] ] ] ) );' );
	expect( Number( withoutThumb ) ).toBe( 0 );
} );

test( 'the six topics exist and every post has exactly one', () => {
	const slugs = wp( 'blog', 'term', 'list', 'category', '--field=slug' ).split( '\n' ).sort();
	expect( slugs ).toEqual( [ 'earth', 'history', 'missions', 'solar-system', 'space-station', 'universe' ] );
} );

test( 'Meilisearch holds one document per post', () => {
	const posts = Number( wp( 'blog', 'post', 'list', '--post_type=post', '--post_status=publish', '--format=count' ) );
	const status = JSON.parse( wp( 'blog', 'meilisearch', 'status', '--format=json' ) ) as Array<{ index: string; documents: number }>;
	const content = status.find( ( row ) => row.index.endsWith( '_content' ) );
	expect( content?.documents ).toBe( posts );
} );

test( 'a post page shows its NASA source link', async ( { page } ) => {
	const url = wp( 'blog', 'eval', 'echo get_permalink( get_posts( [ "numberposts" => 1 ] )[0] );' );
	await page.goto( url );
	await expect( page.getByRole( 'link', { name: 'NASA Science' } ) ).toHaveAttribute( 'href', /^https:\/\/science\.nasa\.gov\// );
} );
```

Run `wp blog meilisearch status --format=json` by hand once and adjust the two field names (`index`, `documents`) to what the command prints. Keep the assertion: content documents equal published posts.

Run: `npm run test:e2e -- --project=blog blog-import`. Expected: FAIL (0 posts).

- [ ] **Step 3: Implement `blog/setup.php`**

```php
<?php
/**
 * Mission Log import (run once by first-boot.sh via `wp eval-file`): options, six topic categories,
 * authors, ~1,000 NASA articles with featured and inline images. Re-runnable: posts already imported
 * (matched by _demo_source_id) are skipped, so a first boot interrupted half-way can be retried.
 */

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$data_dir = '/opt/demo/data';
$articles = json_decode( (string) file_get_contents( $data_dir . '/articles.json' ), true, 512, JSON_THROW_ON_ERROR );
$topics   = array(
	'missions'      => 'Missions',
	'space-station' => 'Space Station',
	'earth'         => 'Earth',
	'solar-system'  => 'Solar System',
	'universe'      => 'Universe',
	'history'       => 'History',
);

update_option( 'blogdescription', 'Stories from NASA science, searched with Meilisearch' );
update_option( 'default_comment_status', 'closed' );
update_option( 'default_ping_status', 'closed' );
update_option( 'users_can_register', 0 );
update_option( 'posts_per_page', 10 );
update_option( 'show_on_front', 'posts' );
update_option( 'thumbnail_size_w', 160 );
update_option( 'thumbnail_size_h', 160 );
update_option(
	'meilisearch_content',
	array(
		'post_types' => array( 'post' ),
		'taxonomies' => array( 'post' => array( 'category', 'post_tag' ) ),
		'meta_keys'  => array(),
	)
);

$term_ids = array();
foreach ( $topics as $slug => $name ) {
	$existing          = term_exists( $slug, 'category' );
	$term_ids[ $slug ] = (int) ( $existing ? $existing['term_id'] : wp_insert_term( $name, 'category', array( 'slug' => $slug ) )['term_id'] );
}
update_option( 'default_category', $term_ids['missions'] );
$uncategorized = get_term_by( 'slug', 'uncategorized', 'category' );
if ( $uncategorized ) {
	wp_delete_term( $uncategorized->term_id, 'category' );
}

/** Copies a data image to a temp file and sideloads it; returns the attachment ID. */
$sideload = static function ( string $relative, int $post_id, string $alt ) use ( $data_dir ): int {
	static $fallbacks = array(); // Topic fallback images are imported once and shared.
	if ( str_starts_with( $relative, 'fallback-' ) && isset( $fallbacks[ $relative ] ) ) {
		return $fallbacks[ $relative ];
	}
	$tmp = wp_tempnam( basename( $relative ) );
	copy( $data_dir . '/images/' . $relative, $tmp );
	$id = media_handle_sideload(
		array(
			'name'     => basename( $relative ),
			'tmp_name' => $tmp,
		),
		$post_id,
		null,
		array( 'post_title' => $alt )
	);
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'Image import failed for ' . $relative . ': ' . $id->get_error_message() );
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	if ( str_starts_with( $relative, 'fallback-' ) ) {
		$fallbacks[ $relative ] = (int) $id;
	}
	return (int) $id;
};

$authors = array();
$author  = static function ( string $name ) use ( &$authors ): int {
	if ( isset( $authors[ $name ] ) ) {
		return $authors[ $name ];
	}
	$login = sanitize_user( strtolower( str_replace( ' ', '.', remove_accents( $name ) ) ), true ) ?: 'nasa.science';
	$user  = get_user_by( 'login', $login );
	$id    = $user ? $user->ID : wp_insert_user(
		array(
			'user_login'   => $login,
			'user_pass'    => wp_generate_password( 32 ),
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => 'author',
			'user_email'   => $login . '@demo.invalid',
		)
	);
	return $authors[ $name ] = (int) $id;
};

// Source IDs already imported (a first boot interrupted half-way is retried).
$done = array_flip( array_map( 'intval', $GLOBALS['wpdb']->get_col( "SELECT meta_value FROM {$GLOBALS['wpdb']->postmeta} WHERE meta_key = '_demo_source_id'" ) ) );

wp_defer_term_counting( true );
$imported = 0;
foreach ( array_reverse( $articles ) as $article ) { // Oldest first, so IDs grow with dates.
	if ( isset( $done[ (int) $article['id'] ] ) ) {
		continue;
	}
	$content = $article['content'];
	$post_id = wp_insert_post(
		array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_title'    => $article['title'],
			'post_name'     => $article['slug'],
			'post_excerpt'  => $article['excerpt'],
			'post_content'  => '',
			'post_date_gmt' => str_replace( 'T', ' ', $article['date_gmt'] ),
			'post_date'     => get_date_from_gmt( str_replace( 'T', ' ', $article['date_gmt'] ) ),
			'post_author'   => $author( $article['author'] ),
			'post_category' => array( $term_ids[ $article['topic'] ] ),
			'tags_input'    => $article['tags'],
			'meta_input'    => array(
				'_demo_source_id'  => (int) $article['id'],
				'_demo_source_url' => $article['source_url'],
			),
		),
		true
	);
	if ( is_wp_error( $post_id ) ) {
		WP_CLI::error( $post_id->get_error_message() );
	}

	// Inline images: sideload, then point src at the attachment URL.
	$content = preg_replace_callback(
		'/src="(inline\/[^"]+)"/',
		static function ( array $m ) use ( $sideload, $post_id ): string {
			$id = $sideload( $m[1], $post_id, '' );
			return 'src="' . esc_url( (string) wp_get_attachment_image_url( $id, 'large' ) ) . '"';
		},
		$content
	);
	$content .= sprintf(
		"\n<p class=\"demo-source\">Source: <a href=\"%s\">NASA Science</a></p>",
		esc_url( $article['source_url'] )
	);
	wp_update_post(
		array(
			'ID'           => $post_id,
			'post_content' => $content,
		)
	);
	set_post_thumbnail( $post_id, $sideload( $article['image'], $post_id, $article['image_alt'] ) );
	if ( $article['sticky'] ) {
		stick_post( $post_id );
	}

	++$imported;
	if ( 0 === $imported % 100 ) {
		WP_CLI::log( sprintf( '[setup] %d articles', $imported ) );
		wp_cache_flush_runtime();
	}
}
wp_defer_term_counting( false );
WP_CLI::success( sprintf( '[setup] imported %d articles', $imported ) );
```

- [ ] **Step 4: Rebuild with a fresh volume and run the tests**

```bash
docker compose down -v && docker compose up --build --wait
npm run test:e2e -- --project=blog blog-import
```

Expected: PASS. First boot takes about 3–5 minutes (`docker compose logs -f blog` shows `[setup] N articles`).

- [ ] **Step 5: Extend `bin/smoke.sh`, then commit**

After the Ready checks, add:

```bash
posts="$(docker compose exec -T blog wp --allow-root post list --post_type=post --format=count)"
[ "$posts" -ge 900 ] || fail "blog has only $posts posts"
```

```bash
git add package.json package-lock.json playwright.config.ts tests blog/setup.php bin/smoke.sh
git commit -m "Blog import: topics, authors, 1,000 NASA articles with images; Playwright harness"
```

### Task D6: Mission Log theme

**Files:**
- Create in `blog/theme/`:
  - `style.css`, `theme.json`, `functions.php`
  - `assets/fonts/` (Space Grotesk 500/700, Inter 400/500/600/700, JetBrains Mono 400; woff2)
  - `parts/header.html`, `parts/footer.html`
  - `templates/index.html`, `home.html`, `single.html`, `archive.html`, `search.html`, `404.html`
  - `patterns/topic-nav.php`
- Test: `tests/e2e/blog-theme.spec.ts`

**Interfaces:**
- Consumes: the category slugs from Task D5, and the plugin's autocomplete CSS classes `.meilisearch-ac`, `.meilisearch-ac__option`, `.meilisearch-ac__thumb`, `.meilisearch-ac__title`, `.meilisearch-ac__subtitle`, `.meilisearch-ac__footer`, `.meilisearch-ac__group-label`, `mark`.
- Produces: in `search.html`, placeholders for the blocks that Tasks D7 and D10 register: `<!-- wp:meili-demo/under-the-hood /-->`, `<!-- wp:meili-demo/try-chips /-->` and `<!-- wp:meili-demo/facets /-->`. Unregistered blocks render nothing, so the theme works before D7. Also produces the CSS hooks `.ml-hero`, `.ml-grid`, `.ml-card`, `.ml-serp`, `.ml-result`.

- [ ] **Step 1: Failing E2E (`tests/e2e/blog-theme.spec.ts`)**

```ts
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
```

Run: `npm run test:e2e -- --project=blog blog-theme`. Expected: FAIL (no `Topics` navigation).

- [ ] **Step 2: Fonts**

```bash
mkdir -p blog/theme/assets/fonts
python3 - <<'PY'
import re, urllib.request
ua = {"User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 Chrome/126 Safari/537.36"}
css = urllib.request.urlopen(urllib.request.Request("https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@400&display=swap", headers=ua)).read().decode()
for block in re.findall(r"/\* latin \*/\s*@font-face \{(.*?)\}", css, re.S):
    family = re.search(r"font-family: '([^']+)'", block).group(1).replace(" ", "")
    weight = re.search(r"font-weight: (\d+)", block).group(1)
    url = re.search(r"url\((https://[^)]+\.woff2)\)", block).group(1)
    open(f"blog/theme/assets/fonts/{family}-{weight}.woff2", "wb").write(urllib.request.urlopen(url).read())
    print(family, weight)
PY
```

Expected: seven files. All three families are under the SIL Open Font License; put `blog/theme/assets/fonts/OFL.txt` next to them, with the text from <https://openfontlicense.org>.

- [ ] **Step 3: `theme.json`**

```json
{
	"$schema": "https://schemas.wp.org/trunk/theme.json",
	"version": 3,
	"settings": {
		"appearanceTools": true,
		"layout": { "contentSize": "720px", "wideSize": "1240px" },
		"color": {
			"defaultPalette": false,
			"palette": [
				{ "slug": "night", "color": "#070b18", "name": "Night" },
				{ "slug": "panel", "color": "#0f1630", "name": "Panel" },
				{ "slug": "line", "color": "#1c2440", "name": "Line" },
				{ "slug": "muted", "color": "#8c96b8", "name": "Muted" },
				{ "slug": "text", "color": "#e6e9f2", "name": "Text" },
				{ "slug": "flare", "color": "#ff8a5c", "name": "Flare" }
			]
		},
		"typography": {
			"fontFamilies": [
				{ "slug": "display", "name": "Space Grotesk", "fontFamily": "\"Space Grotesk\", system-ui, sans-serif",
				  "fontFace": [
					{ "fontFamily": "Space Grotesk", "fontWeight": "500", "src": [ "file:./assets/fonts/SpaceGrotesk-500.woff2" ] },
					{ "fontFamily": "Space Grotesk", "fontWeight": "700", "src": [ "file:./assets/fonts/SpaceGrotesk-700.woff2" ] } ] },
				{ "slug": "body", "name": "Inter", "fontFamily": "Inter, system-ui, sans-serif",
				  "fontFace": [
					{ "fontFamily": "Inter", "fontWeight": "400", "src": [ "file:./assets/fonts/Inter-400.woff2" ] },
					{ "fontFamily": "Inter", "fontWeight": "500", "src": [ "file:./assets/fonts/Inter-500.woff2" ] },
					{ "fontFamily": "Inter", "fontWeight": "600", "src": [ "file:./assets/fonts/Inter-600.woff2" ] },
					{ "fontFamily": "Inter", "fontWeight": "700", "src": [ "file:./assets/fonts/Inter-700.woff2" ] } ] },
				{ "slug": "mono", "name": "JetBrains Mono", "fontFamily": "\"JetBrains Mono\", ui-monospace, monospace",
				  "fontFace": [ { "fontFamily": "JetBrains Mono", "fontWeight": "400", "src": [ "file:./assets/fonts/JetBrainsMono-400.woff2" ] } ] }
			]
		}
	},
	"styles": {
		"color": { "background": "var(--wp--preset--color--night)", "text": "var(--wp--preset--color--text)" },
		"typography": { "fontFamily": "var(--wp--preset--font-family--body)", "fontSize": "16px", "lineHeight": "1.6" },
		"elements": {
			"heading": { "typography": { "fontFamily": "var(--wp--preset--font-family--display)", "letterSpacing": "-0.02em", "lineHeight": "1.15" } },
			"link": { "color": { "text": "inherit" }, "typography": { "textDecoration": "none" }, ":hover": { "color": { "text": "var(--wp--preset--color--flare)" } } }
		}
	},
	"templateParts": [
		{ "name": "header", "title": "Header", "area": "header" },
		{ "name": "footer", "title": "Footer", "area": "footer" }
	]
}
```

- [ ] **Step 4: Parts, patterns, templates**

`patterns/topic-nav.php`:

```php
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
```

`parts/header.html`:

```html
<!-- wp:group {"tagName":"header","className":"ml-header","layout":{"type":"flex","flexWrap":"nowrap"}} -->
<header class="wp-block-group ml-header">
	<!-- wp:site-title {"level":0,"className":"ml-logo"} /-->
	<!-- wp:pattern {"slug":"mission-log/topic-nav"} /-->
	<!-- wp:search {"label":"Search articles","showLabel":false,"placeholder":"Search 1,000 NASA stories…","buttonText":"Search","buttonPosition":"no-button","className":"ml-search"} /-->
</header>
<!-- /wp:group -->
```

`parts/footer.html`:

```html
<!-- wp:group {"tagName":"footer","className":"ml-footer","layout":{"type":"flex","justifyContent":"space-between"}} -->
<footer class="wp-block-group ml-footer">
	<!-- wp:paragraph --><p>Content: NASA (public domain). Not endorsed by NASA.</p><!-- /wp:paragraph -->
	<!-- wp:paragraph --><p>Search by <a href="https://wordpress.org/plugins/meilisearch/">Meilisearch for WordPress</a></p><!-- /wp:paragraph -->
</footer>
<!-- /wp:group -->
```

`templates/home.html` has the hero (one sticky post) plus a grid of 8 latest, non-sticky posts:

```html
<!-- wp:template-part {"slug":"header","tagName":"div"} /-->
<!-- wp:group {"tagName":"main","layout":{"type":"default"}} -->
<main class="wp-block-group">
	<!-- wp:query {"queryId":1,"query":{"perPage":1,"postType":"post","sticky":"only","inherit":false},"className":"ml-hero"} -->
	<div class="wp-block-query ml-hero">
		<!-- wp:post-template -->
			<!-- wp:post-featured-image {"isLink":true,"sizeSlug":"large","className":"ml-hero__img"} /-->
			<!-- wp:group {"className":"ml-hero__txt"} --><div class="wp-block-group ml-hero__txt">
				<!-- wp:post-terms {"term":"category","className":"ml-kicker"} /-->
				<!-- wp:post-title {"level":1,"isLink":true} /-->
				<!-- wp:post-excerpt {"excerptLength":40} /-->
				<!-- wp:group {"className":"ml-meta","layout":{"type":"flex"}} --><div class="wp-block-group ml-meta">
					<!-- wp:post-author-name /--><!-- wp:post-date /-->
				</div><!-- /wp:group -->
			</div><!-- /wp:group -->
		<!-- /wp:post-template -->
	</div>
	<!-- /wp:query -->
	<!-- wp:heading {"className":"ml-section-h"} --><h2 class="wp-block-heading ml-section-h">Latest from the archive</h2><!-- /wp:heading -->
	<!-- wp:query {"queryId":2,"query":{"perPage":8,"postType":"post","sticky":"exclude","inherit":false}} -->
	<div class="wp-block-query">
		<!-- wp:post-template {"className":"ml-grid","layout":{"type":"grid","columnCount":4}} -->
			<!-- wp:group {"className":"ml-card"} --><div class="wp-block-group ml-card">
				<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"4/3","sizeSlug":"medium_large"} /-->
				<!-- wp:post-terms {"term":"category","className":"ml-kicker"} /-->
				<!-- wp:post-title {"level":3,"isLink":true} /-->
				<!-- wp:post-date {"className":"ml-meta"} /-->
			</div><!-- /wp:group -->
		<!-- /wp:post-template -->
	</div>
	<!-- /wp:query -->
</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"div"} /-->
```

`templates/search.html`, three columns as in the mockup:

```html
<!-- wp:template-part {"slug":"header","tagName":"div"} /-->
<!-- wp:group {"tagName":"main","className":"ml-serp","layout":{"type":"default"}} -->
<main class="wp-block-group ml-serp">
	<!-- wp:group {"tagName":"aside","className":"ml-serp__facets"} --><aside class="wp-block-group ml-serp__facets">
		<!-- wp:meili-demo/facets /-->
	</aside><!-- /wp:group -->
	<!-- wp:group {"className":"ml-serp__results"} --><div class="wp-block-group ml-serp__results">
		<!-- wp:query-title {"type":"search","className":"ml-q"} /-->
		<!-- wp:meili-demo/try-chips /-->
		<!-- wp:query {"queryId":3,"query":{"inherit":true}} -->
		<div class="wp-block-query">
			<!-- wp:post-template -->
				<!-- wp:group {"className":"ml-result","layout":{"type":"grid","columnCount":null,"minimumColumnWidth":null}} --><div class="wp-block-group ml-result">
					<!-- wp:post-featured-image {"isLink":true,"aspectRatio":"4/3","sizeSlug":"medium"} /-->
					<!-- wp:group --><div class="wp-block-group">
						<!-- wp:group {"className":"ml-kicker","layout":{"type":"flex"}} --><div class="wp-block-group ml-kicker"><!-- wp:post-terms {"term":"category"} /--><!-- wp:post-date /--></div><!-- /wp:group -->
						<!-- wp:post-title {"level":3,"isLink":true} /-->
						<!-- wp:post-excerpt {"excerptLength":40} /-->
					</div><!-- /wp:group -->
				</div><!-- /wp:group -->
			<!-- /wp:post-template -->
			<!-- wp:query-pagination --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination -->
			<!-- wp:query-no-results --><!-- wp:paragraph --><p>No stories match. Try one of the suggestions above.</p><!-- /wp:paragraph --><!-- /wp:query-no-results -->
		</div>
		<!-- /wp:query -->
	</div><!-- /wp:group -->
	<!-- wp:group {"tagName":"aside","className":"ml-serp__uth"} --><aside class="wp-block-group ml-serp__uth">
		<!-- wp:meili-demo/under-the-hood /-->
	</aside><!-- /wp:group -->
</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"div"} /-->
```

`templates/archive.html` is `search.html` without the three `meili-demo/*` blocks, with `query-title {"type":"archive"}`, and with the results column spanning the width (class `ml-archive`). `templates/single.html` holds the featured image, kicker, title, author/date meta and `post-content` in a 720px column, plus a "Latest stories" query of 3 posts (`"inherit":false`, `perPage` 3). `templates/index.html` is a copy of `archive.html`. `templates/404.html` holds a heading, a search block and the latest 4 posts.

`functions.php`:

```php
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
```

- [ ] **Step 5: `style.css`**

Header:

```css
/*
Theme Name: Mission Log
Description: Space magazine theme for the Meilisearch for WordPress demo.
Version: 1.0.0
Requires at least: 6.9
Requires PHP: 8.1
License: GPL-2.0-or-later
Text Domain: mission-log
*/
```

Then port the `.ml …` rules from `docs/mockups/demos.html`, which is the approved visual reference, onto the block markup above:

| Mockup selector | Theme selector |
|---|---|
| `.ml header` | `.ml-header` (padding `18px 40px`, bottom border `--line`) |
| `.ml .logo` (with the glowing `span` dot) | `.ml-logo a::before` (same radial-gradient circle) |
| `.ml nav` | `.ml-topics ul` (flex, gap 22px, list-style none) |
| `.ml .search input` | `.ml-search .wp-block-search__input` |
| `.ml .ac*` | `.meilisearch-ac`, `.meilisearch-ac__option`, `.meilisearch-ac__option.is-active`, `.meilisearch-ac__thumb` (40×40, radius 6), `.meilisearch-ac__group-label`, `.meilisearch-ac__subtitle`, `.meilisearch-ac__footer` |
| `.ml .hero` / `.img` / `.txt` | `.ml-hero .wp-block-post-template > li` (grid 1.4fr 1fr), `.ml-hero__img img` (object-fit cover, min-height 420px), `.ml-hero__txt` |
| `.ml .kicker` | `.ml-kicker`, `.ml-kicker a` |
| `.ml .grid` / `.card` | `.ml-grid`, `.ml-card` |
| `.ml .serp` | `.ml-serp` (grid `220px 1fr 330px`, collapsing to one column under 1000px, with the panel after the results) |
| `.ml .res` | `.ml-result` (grid `150px 1fr`) |
| `mark` in results and dropdown | `.ml-result mark, .meilisearch-ac mark` (flare underline, from the mockup) |
| `.ml footer` | `.ml-footer` |

The panel's own styles (`.uth`) ship with the mu-plugin in Task D7, so leave them out here. The page must not scroll horizontally at 375px width: test that by hand with the Playwright viewport.

Set `.meilisearch-ac { background: var(--wp--preset--color--panel); border: 1px solid #2b3766; border-radius: 12px; color: var(--wp--preset--color--text); }`. The plugin's default CSS uses `currentColor` and positions the panel absolutely under the input; the theme only restyles it.

- [ ] **Step 6: Run the tests**

Run: `docker compose watch` (in another terminal; the theme syncs), then `npm run test:e2e -- --project=blog blog-theme`.
Expected: PASS. Then compare the blog home and the search page side by side with the mockup's Mission Log tab at 1240px. Matching layout, colours and type is the bar; pixel-perfect is not.

- [ ] **Step 7: Commit**

```bash
git add blog/theme tests/e2e/blog-theme.spec.ts
git commit -m "Mission Log block theme: header topics, hero, grid, search layout, restyled autocomplete"
```

### Task D7: Demo layer core: request recorder, Under the hood panel, Compare with MySQL

**Files:**
- Create:
  - `shared/mu-plugins/meili-demo.php` (loader)
  - `shared/mu-plugins/meili-demo/src/Recorder.php`
  - `shared/mu-plugins/meili-demo/src/JsonHighlighter.php`
  - `shared/mu-plugins/meili-demo/src/Engine.php`
  - `shared/mu-plugins/meili-demo/src/UnderTheHood.php`
  - `shared/mu-plugins/meili-demo/assets/demo.css`
- Test: `tests/php/run.php`, `tests/php/JsonHighlighterTest.php`, `tests/e2e/blog-under-the-hood.spec.ts`

**Interfaces:**
- Consumes:
  - The constant `MEILISEARCH_HOST`.
  - The WordPress hooks `pre_http_request` (filter, 10, 3), `http_api_debug` (action, 10, 5), `pre_get_posts`, `the_posts`.
  - The plugin filter `meilisearch_should_intercept( bool, WP_Query )`.
  - The plugin transient `meilisearch_circuit_open`.
- Produces:
  - `MeiliDemo\Recorder::instance(): Recorder`
  - `->calls(): list<array{method: string, path: string, body: array|null, status: int, processing_ms: ?int, hits: ?int, wall_ms: float}>`
  - `->query_vars(): array<string, mixed>`
  - `->main_ms(): ?float`
  - `->pause(callable $fn): mixed` (runs `$fn` without recording)
  - `MeiliDemo\Engine::is_mysql(): bool`
  - `MeiliDemo\Engine::compare(): ?array{engine: string, hits: int, ms: float}`
  - `MeiliDemo\JsonHighlighter::render(mixed $data): string` (escaped HTML)
  - The block `meili-demo/under-the-hood`
  - The CSS classes `.uth`, `.uth__cmp`, `.uth__toggle`

  Task D10 adds `meili-demo/try-chips` and `meili-demo/facets` to the same loader.

- [ ] **Step 1: Failing tests**

`tests/php/run.php` is a dependency-free runner (the demo has no Composer):

```php
<?php
// php tests/php/run.php — runs every tests/php/*Test.php; each test is a public static function test_*().
require __DIR__ . '/../../shared/mu-plugins/meili-demo/src/JsonHighlighter.php';
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
$failures = 0;
foreach ( glob( __DIR__ . '/*Test.php' ) as $file ) {
	require $file;
	$class = basename( $file, '.php' );
	foreach ( get_class_methods( $class ) as $method ) {
		if ( ! str_starts_with( $method, 'test_' ) ) { continue; }
		try { $class::$method(); echo "ok   $class::$method\n"; }
		catch ( Throwable $e ) { $failures++; echo "FAIL $class::$method: {$e->getMessage()}\n"; }
	}
}
exit( $failures ? 1 : 0 );
function check( bool $ok, string $message ): void { if ( ! $ok ) { throw new RuntimeException( $message ); } }
```

`tests/php/JsonHighlighterTest.php`:

```php
<?php
use MeiliDemo\JsonHighlighter;

final class JsonHighlighterTest {
	public static function test_keys_strings_numbers_are_wrapped(): void {
		$html = JsonHighlighter::render( array( 'q' => 'saturn', 'page' => 1, 'ok' => true ) );
		check( str_contains( $html, '<span class="k">&quot;q&quot;</span>' ), $html );
		check( str_contains( $html, '<span class="s">&quot;saturn&quot;</span>' ), $html );
		check( str_contains( $html, '<span class="n">1</span>' ), $html );
		check( str_contains( $html, '<span class="n">true</span>' ), $html );
	}

	public static function test_hostile_strings_stay_inert(): void {
		$html = JsonHighlighter::render( array( 'q' => '<script>alert("x")</script> "quoted" 🚀' ) );
		check( ! str_contains( $html, '<script>' ), $html );
		check( str_contains( $html, '&lt;script&gt;' ), $html );
		check( str_contains( $html, '🚀' ), 'emoji kept unescaped as UTF-8' );
	}

	public static function test_filter_strings_with_escaped_quotes_are_one_token(): void {
		$html = JsonHighlighter::render( array( 'filter' => 'tax_category_ids IN [14] AND title = "a \"b\""' ) );
		check( 1 === substr_count( $html, '<span class="s">' ), $html );
	}
}
```

Run: `docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/php/run.php`. Expected: a fatal error, because `JsonHighlighter.php` does not exist.

`tests/e2e/blog-under-the-hood.spec.ts`:

```ts
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
	await page.getByRole( 'link', { name: 'Compare with MySQL' } ).click();
	await expect( page ).toHaveURL( /engine=mysql/ );
	await expect( panel( page ) ).toContainText( 'Served by MySQL' );
	await expect( panel( page ).locator( '.uth__cmp' ) ).toContainText( /MySQL\s*0 hits/ );
	await page.getByRole( 'link', { name: 'Meilisearch' } ).click();
	await expect( page ).not.toHaveURL( /engine=mysql/ );
} );

test( 'panel escapes the query', async ( { page } ) => {
	const hostile = `<img src=x onerror="window.__pwned=1">"quoted" 🚀 ${ 'x'.repeat( 250 ) }`;
	await page.goto( '/?s=' + encodeURIComponent( hostile ) );
	expect( await page.evaluate( () => ( window as unknown as { __pwned?: number } ).__pwned ) ).toBeUndefined();
	await expect( panel( page ) ).toContainText( '"quoted"' );
	await expect( panel( page ).locator( 'img' ) ).toHaveCount( 0 );
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
```

Check the plugin's transient name and value with `grep -n "meilisearch_circuit_open" -r <plugin>/src/Search/CircuitBreaker.php`. If the breaker stores a timestamp rather than `1`, set that value.

Run: `npm run test:e2e -- --project=blog blog-under-the-hood`. Expected: FAIL (no `.uth`).

- [ ] **Step 2: `JsonHighlighter.php`**

```php
<?php
namespace MeiliDemo;

/** Pretty-prints a value as JSON and wraps tokens in spans (k = key, s = string, n = number/bool/null). Output is escaped HTML. */
final class JsonHighlighter {
	public static function render( mixed $data ): string {
		$json = (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$out  = '';
		preg_match_all( '/("(?:\\\\.|[^"\\\\])*")(\s*:)?|(-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|true|false|null)|([\s\S])/u', $json, $tokens, PREG_SET_ORDER );
		foreach ( $tokens as $t ) {
			if ( '' !== ( $t[1] ?? '' ) ) {
				$class = '' !== ( $t[2] ?? '' ) ? 'k' : 's';
				$out  .= '<span class="' . $class . '">' . esc_html( $t[1] ) . '</span>' . esc_html( $t[2] ?? '' );
			} elseif ( '' !== ( $t[3] ?? '' ) ) {
				$out .= '<span class="n">' . esc_html( $t[3] ) . '</span>';
			} else {
				$out .= esc_html( $t[4] ?? '' );
			}
		}
		return $out;
	}
}
```

Run the PHP tests. Expected: `ok` × 3.

- [ ] **Step 3: `Recorder.php`**

```php
<?php
namespace MeiliDemo;

/**
 * Records the Meilisearch search calls of this request exactly as sent (the plugin uses wp_remote_request, so
 * http_api_debug sees URL, args and response) plus the main query's vars. Never records headers.
 */
final class Recorder {
	private static ?Recorder $instance = null;
	/** @var list<array<string, mixed>> */
	private array $calls = array();
	private array $started = array();
	private array $vars = array();
	private ?float $main_start = null;
	private ?float $main_ms = null;
	private bool $paused = false;

	public static function instance(): Recorder {
		return self::$instance ??= new Recorder();
	}

	public function register(): void {
		add_filter( 'pre_http_request', array( $this, 'start' ), 10, 3 );
		add_action( 'http_api_debug', array( $this, 'finish' ), 10, 5 );
		add_action( 'pre_get_posts', array( $this, 'capture_vars' ), PHP_INT_MAX );
		add_filter( 'the_posts', array( $this, 'stop_main' ), PHP_INT_MAX, 2 );
	}

	public function pause( callable $fn ): mixed {
		$this->paused = true;
		try {
			return $fn();
		} finally {
			$this->paused = false;
		}
	}

	private function is_search_call( string $url ): bool {
		$host = defined( 'MEILISEARCH_HOST' ) ? rtrim( (string) MEILISEARCH_HOST, '/' ) : '';
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		return '' !== $host && str_starts_with( $url, $host ) && 1 === preg_match( '#/(search|multi-search)$#', $path );
	}

	public function start( mixed $pre, array $args, string $url ): mixed {
		if ( ! $this->paused && $this->is_search_call( $url ) ) {
			$this->started[ $url ] = microtime( true );
		}
		return $pre;
	}

	public function finish( mixed $response, string $context, string $class, array $args, string $url ): void {
		if ( $this->paused || 'response' !== $context || ! $this->is_search_call( $url ) ) {
			return;
		}
		$body    = is_string( $args['body'] ?? null ) ? json_decode( $args['body'], true ) : null;
		$decoded = is_array( $response ) ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
		$results = is_array( $decoded['results'] ?? null ) ? $decoded['results'] : ( is_array( $decoded ) ? array( $decoded ) : array() );
		$hits    = null;
		$ms      = null;
		foreach ( $results as $result ) {
			$hits = ( $hits ?? 0 ) + (int) ( $result['totalHits'] ?? $result['estimatedTotalHits'] ?? 0 );
			$ms   = max( $ms ?? 0, (int) ( $result['processingTimeMs'] ?? 0 ) );
		}
		if ( isset( $decoded['processingTimeMs'] ) && ! isset( $decoded['results'] ) ) {
			$ms = (int) $decoded['processingTimeMs'];
		}
		$this->calls[] = array(
			'method'        => strtoupper( (string) ( $args['method'] ?? 'POST' ) ),
			'path'          => (string) wp_parse_url( $url, PHP_URL_PATH ),
			'body'          => is_array( $body ) ? $body : null,
			'status'        => is_array( $response ) ? (int) wp_remote_retrieve_response_code( $response ) : 0,
			'processing_ms' => $ms,
			'hits'          => $hits,
			'wall_ms'       => isset( $this->started[ $url ] ) ? ( microtime( true ) - $this->started[ $url ] ) * 1000 : 0.0,
		);
	}

	public function capture_vars( \WP_Query $query ): void {
		if ( $this->paused || ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}
		$keep = array( 's', 'paged', 'post_type', 'category_name', 'cat', 'tag', 'product_cat', 'product_tag', 'orderby', 'order', 'min_price', 'max_price', 'published' );
		foreach ( $query->query_vars as $name => $value ) {
			if ( in_array( $name, $keep, true ) || str_starts_with( $name, 'filter_' ) || str_starts_with( $name, 'query_type_' ) ) {
				if ( '' !== $value && null !== $value && array() !== $value && 0 !== $value ) {
					$this->vars[ $name ] = $value;
				}
			}
		}
		foreach ( $_GET as $name => $value ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only display.
			if ( is_string( $value ) && ( str_starts_with( $name, 'filter_' ) || str_starts_with( $name, 'query_type_' ) || in_array( $name, array( 'min_price', 'max_price', 'orderby' ), true ) ) ) {
				$this->vars[ $name ] = sanitize_text_field( wp_unslash( $value ) );
			}
		}
		$this->main_start = microtime( true );
	}

	public function stop_main( array $posts, \WP_Query $query ): array {
		if ( null !== $this->main_start && null === $this->main_ms && $query->is_main_query() ) {
			$this->main_ms = ( microtime( true ) - $this->main_start ) * 1000;
		}
		return $posts;
	}

	public function calls(): array { return $this->calls; }
	public function query_vars(): array { return $this->vars; }
	public function main_ms(): ?float { return $this->main_ms; }
}
```

- [ ] **Step 4: `Engine.php`**

```php
<?php
namespace MeiliDemo;

/** ?engine=mysql opts the main query out of the plugin; compare() counts the same search on the other engine. */
final class Engine {
	public static function register(): void {
		add_filter( 'meilisearch_should_intercept', static fn ( bool $intercept, \WP_Query $query ): bool => self::is_mysql() && $query->is_main_query() ? false : $intercept, 10, 2 );
	}

	public static function is_mysql(): bool {
		return isset( $_GET['engine'] ) && 'mysql' === $_GET['engine']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Runs the main search once more on the other engine (ids only, first page) and times it.
	 *
	 * @return array{engine: string, hits: int, ms: float}|null
	 */
	public static function compare(): ?array {
		global $wp_query;
		if ( ! $wp_query instanceof \WP_Query || ! $wp_query->is_search() || (int) $wp_query->get( 'paged' ) > 1 ) {
			return null;
		}
		$vars = $wp_query->query_vars;
		unset( $vars['paged'] );
		$vars['fields']         = 'ids';
		$vars['posts_per_page'] = 1;
		$vars['no_found_rows']  = false;
		$other                  = self::is_mysql() ? 'meilisearch' : 'mysql';
		$vars['meilisearch']    = 'meilisearch' === $other; // Secondary queries are only intercepted when opted in.
		return Recorder::instance()->pause(
			static function () use ( $vars, $other ): array {
				$start = microtime( true );
				$query = new \WP_Query( $vars );
				return array(
					'engine' => $other,
					'hits'   => (int) $query->found_posts,
					'ms'     => ( microtime( true ) - $start ) * 1000,
				);
			}
		);
	}
}
```

- [ ] **Step 5: `UnderTheHood.php`, CSS and loader**

```php
<?php
namespace MeiliDemo;

/** Block meili-demo/under-the-hood: the request the plugin sent for this page, where it came from, both engines. */
final class UnderTheHood {
	public static function register(): void {
		register_block_type( 'meili-demo/under-the-hood', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	public static function render(): string {
		if ( ! is_search() ) {
			return '';
		}
		$recorder = Recorder::instance();
		$calls    = $recorder->calls();
		$compare  = Engine::compare();
		$mysql    = Engine::is_mysql() || array() === $calls;
		$here     = remove_query_arg( 'engine' );

		ob_start();
		echo '<details class="uth" open><summary>' . esc_html__( 'Under the hood', 'meili-demo' ) . '</summary>';
		if ( $mysql ) {
			echo '<p class="uth__sub"><strong>' . esc_html__( 'Served by MySQL', 'meili-demo' ) . '</strong>: ' . esc_html( self::reason() ) . '</p>';
		} else {
			echo '<p class="uth__sub">' . esc_html__( 'What the plugin sent to Meilisearch for this WordPress search', 'meili-demo' ) . '</p>';
			foreach ( $calls as $call ) {
				echo '<pre class="uth__req"><span class="m">' . esc_html( $call['method'] . ' ' . $call['path'] ) . "</span>\n" . JsonHighlighter::render( $call['body'] ) . '</pre>'; // JsonHighlighter output is escaped.
			}
		}
		$source = array();
		foreach ( $recorder->query_vars() as $name => $value ) {
			$source[] = $name . '=' . ( is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value ) );
		}
		if ( $source ) {
			echo '<p class="uth__source">' . esc_html__( 'From WP_Query:', 'meili-demo' ) . ' <code>' . esc_html( implode( ', ', $source ) ) . '</code></p>';
		}

		$tiles = array();
		if ( ! $mysql ) {
			$first             = $calls[0];
			$tiles['meilisearch'] = array( (int) $first['hits'], null !== $first['processing_ms'] ? (float) $first['processing_ms'] : $first['wall_ms'] );
		} else {
			global $wp_query;
			$tiles['mysql'] = array( (int) $wp_query->found_posts, (float) ( $recorder->main_ms() ?? 0 ) );
		}
		if ( $compare ) {
			$tiles[ $compare['engine'] ] = array( $compare['hits'], $compare['ms'] );
		}
		echo '<div class="uth__cmp">';
		foreach ( array( 'meilisearch' => 'Meilisearch', 'mysql' => 'MySQL' ) as $key => $label ) {
			if ( isset( $tiles[ $key ] ) ) {
				printf(
					'<div class="uth__tile uth__tile--%1$s">%2$s<b>%3$s</b></div>',
					esc_attr( $key ),
					esc_html( $label ),
					esc_html( sprintf( '%d hits · %s ms', $tiles[ $key ][0], number_format_i18n( $tiles[ $key ][1], 0 ) ) )
				);
			}
		}
		echo '</div>';
		printf(
			'<nav class="uth__toggle" aria-label="%1$s"><a href="%2$s"%3$s>Meilisearch</a><a href="%4$s"%5$s>%6$s</a></nav>',
			esc_attr__( 'Search engine', 'meili-demo' ),
			esc_url( $here ),
			Engine::is_mysql() ? '' : ' aria-current="page"',
			esc_url( add_query_arg( 'engine', 'mysql', $here ) ),
			Engine::is_mysql() ? ' aria-current="page"' : '',
			esc_html__( 'Compare with MySQL', 'meili-demo' )
		);
		echo '</details>';
		return (string) ob_get_clean();
	}

	private static function reason(): string {
		if ( Engine::is_mysql() ) {
			return __( 'you asked for MySQL, so the plugin stepped aside and WordPress ran its own LIKE search.', 'meili-demo' );
		}
		if ( false !== get_transient( 'meilisearch_circuit_open' ) ) {
			return __( 'Meilisearch failed recently, so the circuit breaker sends searches to MySQL for a minute.', 'meili-demo' );
		}
		return __( 'the plugin could not translate this query (or the index is still being built), so WordPress ran it unchanged.', 'meili-demo' );
	}
}
```

The toggle link texts must equal the test's link names: "Meilisearch" and "Compare with MySQL".

`shared/mu-plugins/meili-demo.php`:

```php
<?php
/**
 * Plugin Name: Meilisearch demo layer
 * Description: Demo-only extras for the Meilisearch for WordPress showcase (request panel, MySQL comparison, try chips, facet counts, hardening). Not part of the plugin.
 */
defined( 'ABSPATH' ) || exit;

foreach ( glob( __DIR__ . '/meili-demo/src/*.php' ) as $file ) {
	require_once $file;
}
MeiliDemo\Recorder::instance()->register();
MeiliDemo\Engine::register();
add_action( 'init', array( MeiliDemo\UnderTheHood::class, 'register' ) );
add_action(
	'wp_enqueue_scripts',
	static function (): void {
		wp_enqueue_style( 'meili-demo', content_url( 'mu-plugins/meili-demo/assets/demo.css' ), array(), (string) filemtime( __DIR__ . '/meili-demo/assets/demo.css' ) );
	}
);
```

`assets/demo.css`: port the mockup's `.uth` rules, using CSS custom properties so both themes can recolour it. Each theme sets the variables in its own `style.css`:

```css
.uth { --uth-bg: #0c1330; --uth-line: #2b3766; --uth-code-bg: #070b18; --uth-code: #cfd6f3; --uth-muted: #7d89b6; --uth-accent: #ff8a5c; --uth-accent-ink: #1a0c05;
	background: var(--uth-bg); border: 1px solid var(--uth-line); border-radius: 12px; padding: 16px; font-size: 12.5px; position: sticky; top: 24px; }
.uth summary { font-weight: 600; font-size: 14px; cursor: pointer; }
.uth__sub, .uth__source { color: var(--uth-muted); margin: 6px 0 12px; }
.uth__req { margin: 0 0 8px; font-family: var(--wp--preset--font-family--mono, ui-monospace, monospace); font-size: 11.5px; line-height: 1.55;
	white-space: pre-wrap; word-break: break-word; color: var(--uth-code); background: var(--uth-code-bg); border-radius: 8px; padding: 12px; }
.uth__req .k { color: #8ab4ff; } .uth__req .s { color: #ffc28a; } .uth__req .n { color: #9be29b; } .uth__req .m { color: #8ab4ff; font-weight: 600; }
.uth__cmp { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 12px; }
.uth__tile { border-radius: 8px; padding: 8px 10px; background: var(--uth-code-bg); }
.uth__tile b { display: block; font-size: 16px; }
.uth__toggle { display: flex; margin-top: 12px; border: 1px solid var(--uth-line); border-radius: 8px; overflow: hidden; }
.uth__toggle a { flex: 1; text-align: center; padding: 6px; text-decoration: none; color: inherit; }
.uth__toggle a[aria-current="page"] { background: var(--uth-accent); color: var(--uth-accent-ink); font-weight: 600; }
```

The mu-plugin directory is symlinked as `wp-content/mu-plugins`, so `content_url( 'mu-plugins/...' )` resolves.

- [ ] **Step 6: Run the tests**

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/php/run.php
npm run test:e2e -- --project=blog blog-under-the-hood
```

Expected: everything passes. If `the panel shows the request` fails because the path is `/multi-search`, the plugin used federation. That only happens for mixed post types, which the blog never has, so investigate before changing the assertion.

- [ ] **Step 7: Commit**

```bash
git add shared tests/php tests/e2e/blog-under-the-hood.spec.ts
git commit -m "Demo layer: record the plugin's Meilisearch requests, Under the hood panel, compare with MySQL"
```

### Task D8: Shop import (WooCommerce variable products, attributes, reviews)

**Files:**
- Modify: `shop/setup.php`
- Test: `tests/e2e/shop-import.spec.ts`

**Interfaces:**
- Consumes: `data/shop/artworks.json` (Task D4 keys).
- Produces:
  - Global attributes, created in this exact order so their IDs are fixed on a fresh install, and taxonomies `pa_<slug>`. Task D9's filter blocks reference these IDs.

    | ID | Slug | Name |
    |---|---|---|
    | 1 | `department` | Department |
    | 2 | `artist` | Artist |
    | 3 | `century` | Century |
    | 4 | `medium` | Medium |
    | 5 | `size` | Size |
    | 6 | `finish` | Finish |

  - Product categories with slugs `paintings`, `japanese-prints`, `prints`, `drawings` and `photographs`.
  - Product meta `_demo_met_id`, `_demo_object_url`, `_demo_object_date`.
  - The store options listed in Step 3.

- [ ] **Step 1: Failing E2E (`tests/e2e/shop-import.spec.ts`)**

```ts
import { expect, test } from '@playwright/test';
import { wp } from './utils';

const php = ( code: string ) => wp( 'shop', 'eval', code );

test( 'catalog: 1,450+ variable products with 12 variations each', () => {
	expect( Number( php( 'echo count( wc_get_products( [ "type" => "variable", "limit" => -1, "return" => "ids" ] ) );' ) ) ).toBeGreaterThanOrEqual( 1450 );
	expect( php( '$p = wc_get_product( wc_get_products( [ "limit" => 1, "return" => "ids" ] )[0] ); echo count( $p->get_children() );' ) ).toBe( '12' );
} );

test( 'attributes have the fixed IDs the theme relies on', () => {
	expect( php( 'foreach ( wc_get_attribute_taxonomies() as $a ) { echo $a->attribute_id, ":", $a->attribute_name, " "; }' ) ).toBe( '1:department 2:artist 3:century 4:medium 5:size 6:finish' );
} );

test( 'some products are on sale, some variations are out of stock, ratings exist', () => {
	expect( Number( php( 'echo count( wc_get_product_ids_on_sale() );' ) ) ).toBeGreaterThan( 50 );
	expect( Number( php( 'echo count( wc_get_products( [ "type" => "variation", "stock_status" => "outofstock", "limit" => -1, "return" => "ids" ] ) );' ) ) ).toBeGreaterThan( 100 );
	expect( Number( php( 'echo count( wc_get_products( [ "limit" => -1, "return" => "ids", "orderby" => "rating", "average_rating" => 4 ] ) );' ) ) ).toBeGreaterThan( 0 );
} );

test( 'Meilisearch holds one product document per product', () => {
	const products = Number( php( 'echo count( wc_get_products( [ "limit" => -1, "return" => "ids", "status" => "publish" ] ) );' ) );
	const status = JSON.parse( wp( 'shop', 'meilisearch', 'status', '--format=json' ) ) as Array<{ index: string; documents: number }>;
	expect( status.find( ( row ) => row.index.endsWith( '_products' ) )?.documents ).toBe( products );
} );

test( 'a product page credits The Met', async ( { page } ) => {
	await page.goto( php( 'echo get_permalink( wc_get_products( [ "limit" => 1, "return" => "ids" ] )[0] );' ) );
	await expect( page.getByRole( 'link', { name: 'View the original at The Met' } ) ).toHaveAttribute( 'href', /metmuseum\.org/ );
} );
```

If `wc_get_products` does not accept `average_rating`, replace that assertion with `php( 'global $wpdb; echo $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = \'_wc_average_rating\' AND meta_value >= 4" );' )`.

Run: `npm run test:e2e -- --project=shop shop-import`. Expected: FAIL.

- [ ] **Step 2: Implement `shop/setup.php`**

```php
<?php
/**
 * Met Prints import (run once by first-boot.sh via `wp eval-file`): store settings, attributes, categories,
 * ~1,500 variable products (4 sizes × 3 finishes) with images and seeded reviews. Re-runnable: products
 * already imported (matched by SKU) are skipped.
 */

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$data_dir = '/opt/demo/data';
$artworks = json_decode( (string) file_get_contents( $data_dir . '/artworks.json' ), true, 512, JSON_THROW_ON_ERROR );

// Store settings.
foreach ( array(
	'blogdescription'                          => 'Museum-quality prints of public-domain masterpieces',
	'woocommerce_currency'                     => 'USD',
	'woocommerce_default_country'              => 'US:NY',
	'woocommerce_store_address'                => '1000 Fifth Avenue',
	'woocommerce_store_city'                   => 'New York',
	'woocommerce_store_postcode'               => '10028',
	'woocommerce_coming_soon'                  => 'no',
	'woocommerce_store_pages_only'             => 'no',
	'woocommerce_demo_store'                   => 'yes',
	'woocommerce_demo_store_notice'            => 'Demo store: no orders are taken',
	'woocommerce_enable_reviews'               => 'yes',
	'woocommerce_enable_review_rating'         => 'yes',
	'woocommerce_review_rating_verification_required' => 'no',
	'woocommerce_hide_out_of_stock_items'      => 'no',
	'woocommerce_calc_taxes'                   => 'no',
	'woocommerce_allow_tracking'               => 'no',
	'woocommerce_analytics_enabled'            => 'no',
	'woocommerce_show_marketplace_suggestions' => 'no',
	'woocommerce_task_list_hidden'             => 'yes',
	'default_comment_status'                   => 'closed',
	'users_can_register'                       => 0,
) as $name => $value ) {
	update_option( $name, $value );
}
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option(
	'meilisearch_content',
	array(
		'post_types' => array( 'post' ),
		'taxonomies' => array( 'post' => array( 'category', 'post_tag' ) ),
		'meta_keys'  => array(),
	)
);
if ( ! wc_get_page_id( 'shop' ) || wc_get_page_id( 'shop' ) < 1 ) {
	WC_Install::create_pages();
}

// Attributes in a fixed order (IDs 1..6 on a fresh install; the theme's filter blocks use them).
$attribute_names = array(
	'department' => 'Department',
	'artist'     => 'Artist',
	'century'    => 'Century',
	'medium'     => 'Medium',
	'size'       => 'Size',
	'finish'     => 'Finish',
);
$attribute_ids   = array();
foreach ( $attribute_names as $slug => $label ) {
	$id = wc_attribute_taxonomy_id_by_name( $slug );
	if ( ! $id ) {
		$id = wc_create_attribute(
			array(
				'name'         => $label,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'name',
				'has_archives' => false,
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
	}
	$attribute_ids[ $slug ] = (int) $id;
	$taxonomy               = wc_attribute_taxonomy_name( $slug );
	if ( ! taxonomy_exists( $taxonomy ) ) {
		register_taxonomy( $taxonomy, array( 'product', 'product_variation' ), array( 'hierarchical' => false, 'query_var' => true, 'rewrite' => false, 'show_ui' => false ) );
	}
}
if ( array_values( $attribute_ids ) !== array( 1, 2, 3, 4, 5, 6 ) ) {
	WP_CLI::error( 'Attribute IDs are not 1..6 (' . implode( ',', $attribute_ids ) . '): start from a fresh database (docker compose down -v).' );
}

$terms = array();
/** Term ID for a value in a taxonomy (created on first use). */
$term = static function ( string $taxonomy, string $name ) use ( &$terms ): int {
	$key = $taxonomy . '|' . $name;
	if ( ! isset( $terms[ $key ] ) ) {
		$existing      = term_exists( $name, $taxonomy );
		$terms[ $key ] = (int) ( $existing ? $existing['term_id'] : wp_insert_term( $name, $taxonomy )['term_id'] );
	}
	return $terms[ $key ];
};

$categories = array();
foreach ( array( 'Paintings', 'Japanese Prints', 'Prints', 'Drawings', 'Photographs' ) as $name ) {
	$categories[ $name ] = $term( 'product_cat', $name );
}
$uncategorized = get_term_by( 'slug', 'uncategorized', 'product_cat' );
if ( $uncategorized ) {
	update_option( 'default_product_cat', $categories['Paintings'] );
	wp_delete_term( $uncategorized->term_id, 'product_cat' );
}

$sideload = static function ( string $file, int $post_id, string $alt ) use ( $data_dir ): int {
	$tmp = wp_tempnam( $file );
	copy( $data_dir . '/images/' . $file, $tmp );
	$id = media_handle_sideload( array( 'name' => $file, 'tmp_name' => $tmp ), $post_id, null, array( 'post_title' => $alt ) );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( 'Image import failed for ' . $file . ': ' . $id->get_error_message() );
	}
	update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return (int) $id;
};

$attribute = static function ( string $slug, array $term_ids, bool $variation ) use ( $attribute_ids ): WC_Product_Attribute {
	$attr = new WC_Product_Attribute();
	$attr->set_id( $attribute_ids[ $slug ] );
	$attr->set_name( wc_attribute_taxonomy_name( $slug ) );
	$attr->set_options( array_values( array_unique( $term_ids ) ) );
	$attr->set_visible( true );
	$attr->set_variation( $variation );
	return $attr;
};

wp_defer_term_counting( true );
$imported = 0;
foreach ( $artworks as $art ) {
	$sku = 'MET-' . $art['id'];
	if ( wc_get_product_id_by_sku( $sku ) ) {
		continue;
	}
	$sizes    = array();
	$finishes = array();
	foreach ( $art['variations'] as $v ) {
		$sizes[ $v['size_code'] ]      = $term( 'pa_size', $v['size_label'] );
		$finishes[ $v['finish_code'] ] = $term( 'pa_finish', $v['finish_label'] );
	}

	$product = new WC_Product_Variable();
	$product->set_name( $art['title'] );
	$product->set_slug( sanitize_title( $art['title'] ) . '-' . $art['id'] );
	$product->set_status( 'publish' );
	$product->set_sku( $sku );
	$product->set_featured( (bool) $art['featured'] );
	$product->set_reviews_allowed( true );
	$product->set_category_ids( array( $categories[ $art['category'] ] ) );
	$product->set_tag_ids( array_map( static fn ( string $tag ): int => $term( 'product_tag', $tag ), array_slice( $art['tags'], 0, 8 ) ) );
	$product->set_short_description( sprintf( '<p>%s, %s. Archival print from The Met’s open-access image.</p>', esc_html( $art['artist'] ), esc_html( $art['date'] ) ) );
	$product->set_description(
		sprintf(
			'<p><strong>%1$s</strong>, %2$s (%3$s).</p><p>%4$s. Original: %5$s.</p><p>%6$s. Printed from The Met’s Open Access image (CC0). <a href="%7$s">View the original at The Met</a> (object %8$d).</p>',
			esc_html( $art['title'] ),
			esc_html( $art['artist'] ),
			esc_html( $art['date'] ),
			esc_html( $art['medium'] ),
			esc_html( $art['dimensions'] ),
			esc_html( $art['credit_line'] ),
			esc_url( $art['object_url'] ),
			(int) $art['id']
		)
	);
	$product->set_attributes(
		array(
			$attribute( 'department', array( $term( 'pa_department', $art['department'] ) ), false ),
			$attribute( 'artist', array( $term( 'pa_artist', $art['artist'] ) ), false ),
			$attribute( 'century', array( $term( 'pa_century', $art['century'] ) ), false ),
			$attribute( 'medium', array( $term( 'pa_medium', $art['medium_bucket'] ) ), false ),
			$attribute( 'size', array_values( $sizes ), true ),
			$attribute( 'finish', array_values( $finishes ), true ),
		)
	);
	$product->update_meta_data( '_demo_met_id', (int) $art['id'] );
	$product->update_meta_data( '_demo_object_url', $art['object_url'] );
	$product->update_meta_data( '_demo_object_date', $art['date'] );
	$product->update_meta_data( 'total_sales', ( crc32( (string) $art['id'] ) % 400 ) + 10 * count( $art['reviews'] ) );
	$product_id = $product->save();
	$product->set_image_id( $sideload( $art['image'], $product_id, $art['title'] . ' by ' . $art['artist'] ) );
	$product->save();

	foreach ( $art['variations'] as $v ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_attributes(
			array(
				'pa_size'   => get_term( $sizes[ $v['size_code'] ] )->slug,
				'pa_finish' => get_term( $finishes[ $v['finish_code'] ] )->slug,
			)
		);
		$variation->set_sku( $v['sku'] );
		$variation->set_regular_price( (string) $v['regular_price'] );
		if ( null !== $v['sale_price'] ) {
			$variation->set_sale_price( (string) $v['sale_price'] );
		}
		$variation->set_manage_stock( false );
		$variation->set_stock_status( $v['in_stock'] ? 'instock' : 'outofstock' );
		$variation->save();
	}
	WC_Product_Variable::sync( $product_id );

	foreach ( $art['reviews'] as $review ) {
		wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Demo shopper',
				'comment_author_email' => 'shopper@demo.invalid',
				'comment_content'      => $review['text'],
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'comment_date'         => gmdate( 'Y-m-d H:i:s', strtotime( '2026-10-01' ) - DAY_IN_SECONDS * (int) $review['days_ago'] ),
				'comment_meta'         => array( 'rating' => (int) $review['rating'] ),
			)
		);
	}
	WC_Comments::clear_transients( $product_id );
	wc_delete_product_transients( $product_id );

	++$imported;
	if ( 0 === $imported % 100 ) {
		WP_CLI::log( sprintf( '[setup] %d products', $imported ) );
		wp_cache_flush_runtime();
	}
}
wp_defer_term_counting( false );
WP_CLI::success( sprintf( '[setup] imported %d products', $imported ) );
```

`WC_Comments::clear_transients()` recalculates `_wc_average_rating`, `_wc_rating_count` and `_wc_review_count` in current WooCommerce. If the rating test fails, add `$p = wc_get_product( $product_id ); $p->set_average_rating( WC_Comments::get_average_rating_for_product( $p ) ); $p->set_rating_counts( WC_Comments::get_rating_counts_for_product( $p ) ); $p->set_review_count( WC_Comments::get_review_count_for_product( $p ) ); $p->save();`.

- [ ] **Step 3: Rebuild the shop with a fresh database and run the tests**

```bash
docker compose rm -sf shop && docker volume rm wordpress-meilisearch-demo_shop_html
docker compose exec mariadb mariadb -uroot -proot -e 'DROP DATABASE shop; CREATE DATABASE shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
docker compose up --build --wait shop
npm run test:e2e -- --project=shop shop-import
```

Expected: PASS. First boot takes under 10 minutes (watch `docker compose logs -f shop`). If it takes longer, add `add_filter( 'intermediate_image_sizes_advanced', static fn ( $sizes ) => array_intersect_key( $sizes, array_flip( array( 'thumbnail', 'woocommerce_thumbnail', 'woocommerce_single' ) ) ) );` at the top of `setup.php`.

- [ ] **Step 4: Commit**

```bash
git add shop/setup.php tests/e2e/shop-import.spec.ts
git commit -m "Shop import: Met prints as variable products with attributes, sale prices, stock and reviews"
```

### Task D9: Met Prints theme (WooCommerce templates, filters, checkout notice)

**Files:**
- Create in `shop/theme/`:
  - `style.css`, `theme.json`, `functions.php`
  - `assets/fonts/` (Cormorant Garamond 500/600/500-italic, Inter 400/500/600; woff2 + OFL.txt)
  - `parts/header.html`, `parts/footer.html`
  - `templates/front-page.html`, `archive-product.html`, `product-search-results.html`, `single-product.html`, `page-checkout.html`, `page.html`, `index.html`
- Test: `tests/e2e/shop-theme.spec.ts`

**Interfaces:**
- Consumes: the attribute IDs 1–6 and the category slugs (Task D8); the blocks `meili-demo/under-the-hood`, `meili-demo/try-chips` (D7, D10); the plugin autocomplete classes.
- Produces: the CSS hooks `.mp-header`, `.mp-hero`, `.mp-card`, `.mp-serp`, `.mp-filters`, `.mp-product`.

- [ ] **Step 1: Failing E2E (`tests/e2e/shop-theme.spec.ts`)**

```ts
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { wp } from './utils';

const panel = ( page ) => page.locator( '.uth' );

test( 'home: hero, collector favorites, store notice', async ( { page } ) => {
	await page.goto( '/' );
	await expect( page.locator( '.mp-hero h1' ) ).toBeVisible();
	await expect( page.locator( '.mp-card' ) ).toHaveCount( 8 );
	await expect( page.getByText( 'Demo store: no orders are taken' ) ).toBeVisible();
	await expect( page.getByRole( 'contentinfo' ) ).toContainText( 'The Metropolitan Museum of Art, Open Access (CC0). Not affiliated with The Met.' );
} );

test( 'product search with department filter, max price and price sort is served by Meilisearch', async ( { page } ) => {
	await page.goto( '/?s=van+gof&post_type=product&filter_department=european-paintings&max_price=80&orderby=price' );
	await expect( page.locator( '.mp-serp .mp-card' ).first() ).toBeVisible();
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
	// exclude-from-catalog visibility is not translated by the plugin (spec § 8.5): WordPress must still answer.
	await page.goto( '/?s=landscape&post_type=product&product_visibility=exclude-from-catalog' );
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
		const results = await new AxeBuilder( { page } ).analyze();
		const serious = results.violations.filter( ( v ) => v.impact === 'serious' || v.impact === 'critical' );
		expect( serious, `${ path }: ${ JSON.stringify( serious, null, 2 ) }` ).toEqual( [] );
	}
} );
```

Two things to check when writing these tests:
- **`untranslatable filter`:** if `product_visibility=exclude-from-catalog` in the URL isn't turned into a tax query, pick another documented non-translatable var. A `post__in`-style var isn't reachable from a URL, so use `&orderby=menu_order`: the plugin's translator lists orderby values outside the supported set as not intercepted. Verify with `grep -n "menu_order\|orderby" <plugin>/src/Search/ProductTranslator.php`.
- **Variation selects:** if the variation form uses swatches or another UI instead of `<select>`, follow the markup WooCommerce renders.

Run: `npm run test:e2e -- --project=shop shop-theme`. Expected: FAIL.

- [ ] **Step 2: Fonts, `theme.json`, `functions.php`, parts**

Fonts: run the Task D6 Step 2 script with the URL `https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;1,500&family=Inter:wght@400;500;600&display=swap` and the target directory `shop/theme/assets/fonts`. Name italic files `CormorantGaramond-500-italic.woff2`: extend the script's name with `-italic` when the block contains `font-style: italic`.

`theme.json` follows the D6 structure with:
- palette `paper #f7f4ee`, `card #fbf9f4`, `ink #1d1a16`, `muted #6d6455`, `rule #e3dccd`, `crimson #a3242c`;
- font families `display` (Cormorant Garamond) and `body` (Inter);
- the heading font `display` at weight 500;
- `layout.wideSize` `1240px`.

`functions.php`: the same as D6, plus `add_theme_support( 'woocommerce' ); add_theme_support( 'wc-product-gallery-zoom' ); add_theme_support( 'wc-product-gallery-lightbox' );`.

`parts/header.html`:

```html
<!-- wp:group {"tagName":"header","className":"mp-header","layout":{"type":"flex","flexWrap":"nowrap"}} -->
<header class="wp-block-group mp-header">
	<!-- wp:html --><a class="mp-logo" href="/">Met <em>Prints</em></a><!-- /wp:html -->
	<!-- wp:navigation {"overlayMenu":"mobile","className":"mp-nav","ariaLabel":"Collections"} -->
		<!-- wp:navigation-link {"label":"Paintings","url":"/product-category/paintings/"} /-->
		<!-- wp:navigation-link {"label":"Japanese Prints","url":"/product-category/japanese-prints/"} /-->
		<!-- wp:navigation-link {"label":"Prints","url":"/product-category/prints/"} /-->
		<!-- wp:navigation-link {"label":"Drawings","url":"/product-category/drawings/"} /-->
		<!-- wp:navigation-link {"label":"Photographs","url":"/product-category/photographs/"} /-->
	<!-- /wp:navigation -->
	<!-- wp:search {"label":"Search prints","showLabel":false,"placeholder":"Search 1,500 prints: artist, title, subject…","buttonText":"Search","buttonPosition":"no-button","query":{"post_type":"product"},"className":"mp-search"} /-->
	<!-- wp:woocommerce/mini-cart /-->
</header>
<!-- /wp:group -->
```

`parts/footer.html`: the D6 footer with the text "Images and data: The Metropolitan Museum of Art, Open Access (CC0). Not affiliated with The Met."

- [ ] **Step 3: Templates**

`templates/product-search-results.html` (and `archive-product.html`, identical except the title block and without `try-chips` / `under-the-hood`):

```html
<!-- wp:template-part {"slug":"header","tagName":"div"} /-->
<!-- wp:group {"tagName":"main","className":"mp-serp","layout":{"type":"default"}} -->
<main class="wp-block-group mp-serp">
	<!-- wp:woocommerce/product-filters {"className":"mp-filters"} -->
	<div class="wp-block-woocommerce-product-filters mp-filters">
		<!-- wp:woocommerce/product-filter-active /-->
		<!-- wp:woocommerce/product-filter-attribute {"attributeId":1,"queryType":"or","displayStyle":"woocommerce/product-filter-checkbox-list","showCounts":true} /-->
		<!-- wp:woocommerce/product-filter-attribute {"attributeId":3,"queryType":"or","displayStyle":"woocommerce/product-filter-checkbox-list","showCounts":true} /-->
		<!-- wp:woocommerce/product-filter-attribute {"attributeId":4,"queryType":"or","displayStyle":"woocommerce/product-filter-checkbox-list","showCounts":true} /-->
		<!-- wp:woocommerce/product-filter-price /-->
		<!-- wp:woocommerce/product-filter-rating /-->
	</div>
	<!-- /wp:woocommerce/product-filters -->
	<!-- wp:group {"className":"mp-serp__results"} --><div class="wp-block-group mp-serp__results">
		<!-- wp:query-title {"type":"search","className":"mp-q"} /-->
		<!-- wp:group {"className":"mp-tools","layout":{"type":"flex","justifyContent":"space-between"}} --><div class="wp-block-group mp-tools">
			<!-- wp:woocommerce/product-results-count /-->
			<!-- wp:woocommerce/catalog-sorting /-->
		</div><!-- /wp:group -->
		<!-- wp:meili-demo/try-chips /-->
		<!-- wp:woocommerce/product-collection {"queryId":10,"query":{"inherit":true,"perPage":12},"displayLayout":{"type":"flex","columns":3}} -->
		<div class="wp-block-woocommerce-product-collection">
			<!-- wp:woocommerce/product-template -->
				<!-- wp:group {"className":"mp-card"} --><div class="wp-block-group mp-card">
					<!-- wp:woocommerce/product-image {"isDescendentOfQueryLoop":true,"aspectRatio":"4/5","scale":"contain"} /-->
					<!-- wp:post-title {"level":3,"isLink":true,"__woocommerceNamespace":"woocommerce/product-collection/product-title"} /-->
					<!-- wp:post-excerpt {"excerptLength":10,"className":"mp-by"} /-->
					<!-- wp:woocommerce/product-price {"isDescendentOfQueryLoop":true} /-->
				</div><!-- /wp:group -->
			<!-- /wp:woocommerce/product-template -->
			<!-- wp:query-pagination --><!-- wp:query-pagination-previous /--><!-- wp:query-pagination-numbers /--><!-- wp:query-pagination-next /--><!-- /wp:query-pagination -->
			<!-- wp:woocommerce/product-collection-no-results --><!-- wp:paragraph --><p>No prints match. Try one of the suggestions above.</p><!-- /wp:paragraph --><!-- /wp:woocommerce/product-collection-no-results -->
		</div>
		<!-- /wp:woocommerce/product-collection -->
	</div><!-- /wp:group -->
	<!-- wp:group {"tagName":"aside","className":"mp-serp__uth"} --><aside class="wp-block-group mp-serp__uth">
		<!-- wp:meili-demo/under-the-hood /-->
	</aside><!-- /wp:group -->
</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"div"} /-->
```

Block attribute names for WooCommerce's Product Filters change between releases. After writing the template, open it once in the Site Editor (`/wp-admin/site-editor.php?postType=wp_template`). If WooCommerce reports "This block contains unexpected or invalid content", let the editor upgrade it, then export the fixed markup back into the file (Site Editor → Templates → ⋮ → Export, or `wp eval 'echo get_block_template( "met-prints//product-search-results" )->content;'`). The short excerpt is the artist and date line (`set_short_description` in D8).

`templates/front-page.html`:
- a hero: a `columns` block with the text on the left and, on the right, the image of the featured product with SKU `MET-45434` (Hokusai), or else the first featured product, through a `woocommerce/product-collection` with `featured: true`, `perPage: 1`;
- a "Collector favorites" heading;
- a product collection of 8 featured products in 4 columns, with cards built as above.

`templates/single-product.html`: the WooCommerce single product blocks in two columns:
- left: `woocommerce/product-image-gallery`, framed by the `.mp-product .ph` mat styles from the mockup;
- right: breadcrumbs, then `post-title`, `woocommerce/product-price`, `woocommerce/add-to-cart-form`, then `woocommerce/product-details` (description and reviews tabs).

`templates/page-checkout.html`: header, a `.mp-checkout-closed` group with heading "Checkout is closed", the paragraph "Demo store: no orders are taken. Everything up to this point (search, filters, variants, cart) is real.", the `woocommerce/cart` totals hidden, and the footer. The template contains no checkout block, so no draft order can be created by visiting it.

`templates/page.html` and `index.html` are a simple content column.

- [ ] **Step 4: `style.css`**

Same header as D6 (Theme Name: Met Prints, Text Domain: met-prints). Port the `.mp …` rules from `docs/mockups/demos.html` onto the block markup:

| Mockup | Theme |
|---|---|
| `.mp header` | `.mp-header` |
| `.mp .logo em` | `.mp-logo em` |
| `.mp .search input` | `.mp-search .wp-block-search__input` |
| `.mp .ac*` | `.meilisearch-ac*` (46px thumbs, price right-aligned via `.meilisearch-ac__price { margin-left: auto }`) |
| `.mp .hero` | `.mp-hero` |
| `.mp .card .ph` (white mat + shadow) | `.mp-card .wc-block-components-product-image img` (`border: 10px solid #fff; box-shadow: 0 6px 18px rgba(60,40,10,.12); background: #ece6d9; object-fit: contain`) |
| `.mp .serp` | `.mp-serp` (grid `230px 1fr 320px`; single column under 1000px) |
| `.mp .facet*` | `.mp-filters` (uppercase 10.5px headings, square checkboxes) |
| `.mp .product .ph` (oak frame) | `.mp-product .woocommerce-product-gallery__image img` |
| `.mp .opt span` | `.variations select` (restyled; keep native selects for accessibility) |

Recolour the panel:

```css
.mp-serp__uth .uth { --uth-bg: #fff; --uth-line: #d8cfbd; --uth-code-bg: #1d1a16; --uth-code: #efe6d6; --uth-muted: #8a7f6c; --uth-accent: #1d1a16; --uth-accent-ink: #f7f4ee; color: #1d1a16; }
.mp-serp__uth .uth__tile { background: #f3eee3; color: #1d1a16; }
```

The store notice (`.woocommerce-store-notice`) is a slim ink bar, like the mockup's `.bar`.

- [ ] **Step 5: Run the tests**

Run: `npm run test:e2e -- --project=shop shop-theme`. Expected: PASS.

- **If `the filter blocks produce URLs the plugin intercepts` fails** because the panel says "Served by MySQL", this is the spec § 11 risk. Find the var the block added: the recorder's `.uth__source` line lists it.
  - If the var comes from the stock or rating filter, remove that filter block from the template.
  - If it comes from the attribute filter itself, the plugin has a real gap. Note the exact URL in `docs/known-gaps.md`, stop, and ask the user how to proceed (a fix in the plugin, or dropping the block).
- Then compare the pages visually with the mockup's Met Prints tab.

- [ ] **Step 6: Commit**

```bash
git add shop/theme tests/e2e/shop-theme.spec.ts
git commit -m "Met Prints block theme: WooCommerce search with filter blocks, product page, closed checkout"
```

### Task D10: Demo extras: Try chips, blog facets, `published` years, autocomplete subtitles, hardening

**Files:**
- Create: `blog/try-chips.json`, `shop/try-chips.json`
- Create: `shared/mu-plugins/meili-demo/src/TryChips.php`, `Facets.php`, `Documents.php`, `Hardening.php`
- Modify: `shared/mu-plugins/meili-demo.php` (register them), `shared/mu-plugins/meili-demo/assets/demo.css`, `blog/theme/style.css` (facet styles)
- Test: `tests/e2e/blog-extras.spec.ts`, `tests/e2e/shop-extras.spec.ts`

**Interfaces:**
- Consumes:
  - From D7: `Recorder::instance()->calls()` and `->pause()`.
  - Plugin filters: `meilisearch_document( array $doc, WP_Post $post, string $index )`, `meilisearch_index_settings( array $settings, string $logical )`, `meilisearch_autocomplete_subtitle_field( ?string, string )` (Task P2).
  - Plugin hooks: `pre_get_posts` priority 20 (the interceptor). Ours runs at priority 1.
- Produces:
  - The blocks `meili-demo/try-chips` and `meili-demo/facets`.
  - Document fields `year: int` (content) and `demo_subtitle: string` (content + products).
  - The query var `published`.

- [ ] **Step 1: Failing E2E**

`tests/e2e/blog-extras.spec.ts`:

```ts
import { expect, test } from '@playwright/test';
import chips from '../../blog/try-chips.json' with { type: 'json' };

for ( const chip of chips as Array<{ q: string }> ) {
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
	await years.getByRole( 'link' ).first().click();
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
```

The `date >=` assertion depends on how the plugin's FilterBuilder writes a date range. Check with `grep -n "TO\|>=" <plugin>/src/Search/QueryTranslator.php` around the date filter. If it uses `date 1704067200 TO 1735689599`, assert `/date\s+(>=|\d+ TO)/` instead.

`tests/e2e/shop-extras.spec.ts`:

```ts
import { expect, test } from '@playwright/test';
import chips from '../../shop/try-chips.json' with { type: 'json' };
import { wp } from './utils';

for ( const chip of chips as Array<{ q: string }> ) {
	test( `try chip "${ chip.q }" returns products`, async ( { page } ) => {
		await page.goto( '/?post_type=product&s=' + encodeURIComponent( chip.q ) );
		await expect( page.locator( '.mp-serp .mp-card' ).first() ).toBeVisible();
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
	await page.goto( wp( 'shop', 'eval', 'echo get_permalink( wc_get_products( [ "limit" => 1, "return" => "ids" ] )[0] );' ) );
	await page.getByLabel( 'Size' ).selectOption( { index: 1 } );
	await page.getByLabel( 'Finish' ).selectOption( { index: 1 } );
	await page.getByRole( 'button', { name: /Add to cart/i } ).click();
	await page.goto( '/checkout/' );
	await expect( page.getByRole( 'heading', { name: 'Checkout is closed' } ) ).toBeVisible();
	const api = await request.post( '/wp-json/wc/store/v1/checkout', { data: {} } );
	expect( api.status() ).toBe( 403 );
	const after = Number( wp( 'shop', 'eval', 'echo count( wc_get_orders( [ "limit" => -1, "status" => array_merge( array_keys( wc_get_order_statuses() ), [ "checkout-draft" ] ), "return" => "ids" ] ) );' ) );
	expect( after ).toBe( before );
} );
```

Run: `npm run test:e2e -- blog-extras shop-extras`. Expected: FAIL.

- [ ] **Step 2: Chip lists**

`blog/try-chips.json`:

```json
[
  { "q": "saturn rngs", "why": "Typo tolerance" },
  { "q": "jupitr moons", "why": "Typo tolerance" },
  { "q": "\"heat shield\"", "why": "Phrase search" },
  { "q": "moon -apollo", "why": "Leaving a word out" },
  { "q": "astronot", "why": "Typo tolerance" }
]
```

`shop/try-chips.json`:

```json
[
  { "q": "van gof", "why": "Typo tolerance" },
  { "q": "hokusai wave", "why": "Artist and subject" },
  { "q": "degas dancer", "why": "Artist and subject" },
  { "q": "\"still life\"", "why": "Phrase search" },
  { "q": "landscape -river", "why": "Leaving a word out" }
]
```

If a chip returns nothing against the committed data, swap it for a similar query that does, keeping its `why`. Do not change the data to fit a chip.

- [ ] **Step 3: `TryChips.php`**

```php
<?php
namespace MeiliDemo;

/** Block meili-demo/try-chips: example searches from /opt/demo/site/try-chips.json. */
final class TryChips {
	public static function register(): void {
		register_block_type( 'meili-demo/try-chips', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	public static function render(): string {
		$file  = '/opt/demo/site/try-chips.json';
		$chips = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array();
		if ( ! is_array( $chips ) || array() === $chips ) {
			return '';
		}
		$extra = 'shop' === getenv( 'SITE' ) ? array( 'post_type' => 'product' ) : array();
		$items = '';
		foreach ( $chips as $chip ) {
			$url    = add_query_arg( array_merge( array( 's' => rawurlencode( (string) $chip['q'] ) ), $extra ), home_url( '/' ) );
			$items .= sprintf( '<li><a href="%1$s" title="%2$s">%3$s</a></li>', esc_url( $url ), esc_attr( (string) $chip['why'] ), esc_html( (string) $chip['q'] ) );
		}
		return '<nav class="demo-chips" aria-label="' . esc_attr__( 'Try a search', 'meili-demo' ) . '"><span>' . esc_html__( 'Try:', 'meili-demo' ) . '</span><ul>' . $items . '</ul></nav>';
	}
}
```

- [ ] **Step 4: `Documents.php`: document fields, settings, subtitles, `published`**

```php
<?php
namespace MeiliDemo;

/** Adds demo fields to the plugin's documents and maps ?published=YYYY to a date_query the plugin translates. */
final class Documents {
	public static function register(): void {
		add_filter( 'meilisearch_document', array( self::class, 'document' ), 10, 3 );
		add_filter( 'meilisearch_index_settings', array( self::class, 'settings' ), 10, 2 );
		add_filter( 'meilisearch_autocomplete_subtitle_field', static fn ( $field ) => 'demo_subtitle', 10, 1 );
		add_filter( 'query_vars', static fn ( array $vars ): array => array_merge( $vars, array( 'published' ) ) );
		add_action( 'pre_get_posts', array( self::class, 'published' ), 1 );
	}

	public static function document( array $doc, \WP_Post $post, string $index ): array {
		if ( 'content' === $index ) {
			$doc['year'] = (int) get_post_time( 'Y', true, $post );
			$topic       = get_the_category( $post->ID )[0]->name ?? '';
			$doc['demo_subtitle'] = trim( $topic . ' · ' . get_the_date( 'M j, Y', $post ), ' ·' );
		} elseif ( 'products' === $index ) {
			$artist = wp_get_post_terms( $post->ID, 'pa_artist', array( 'fields' => 'names' ) );
			$date   = (string) get_post_meta( $post->ID, '_demo_object_date', true );
			$doc['demo_subtitle'] = trim( ( is_array( $artist ) ? ( $artist[0] ?? '' ) : '' ) . ' · ' . $date, ' ·' );
		}
		return $doc;
	}

	public static function settings( array $settings, string $logical ): array {
		if ( 'content' === $logical && ! in_array( 'year', $settings['filterableAttributes'], true ) ) {
			$settings['filterableAttributes'][] = 'year';
		}
		return $settings;
	}

	public static function published( \WP_Query $query ): void {
		$year = (int) $query->get( 'published' );
		if ( ! $query->is_main_query() || ! $query->is_search() || $year < 1900 || $year > 2100 ) {
			return;
		}
		$query->set( 'published', '' );
		$query->set(
			'date_query',
			array(
				array(
					'after'     => sprintf( '%d-01-01 00:00:00', $year ),
					'before'    => sprintf( '%d-12-31 23:59:59', $year ),
					'inclusive' => true,
				),
			)
		);
	}
}
```

Setting `published` back to `''` keeps WP_Query from treating it as an unknown var. `published` isn't a WP core var, so the plugin's unsupported-vars check ignores it; confirm by reading `has_unsupported_vars()` in the plugin's `QueryTranslator.php` (line ~293). If that check lists unknown vars as unsupported, use `unset( $query->query_vars['published'] )` instead.

- [ ] **Step 5: `Facets.php` (blog only)**

```php
<?php
namespace MeiliDemo;

/**
 * Block meili-demo/facets (blog): topic and year links with counts. The links are plain query vars the plugin
 * translates; the counts come from one demo-side multi-search (the plugin has no facets in v1).
 */
final class Facets {
	private const TOPICS = array( 'missions', 'space-station', 'earth', 'solar-system', 'universe', 'history' );

	public static function register(): void {
		register_block_type( 'meili-demo/facets', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	public static function render(): string {
		if ( ! is_search() || ! defined( 'MEILISEARCH_HOST' ) || ! defined( 'MEILISEARCH_ADMIN_KEY' ) ) {
			return '';
		}
		$uid = self::content_uid();
		if ( null === $uid ) {
			return '';
		}
		$q        = (string) get_search_query( false );
		$selected = get_query_var( 'category_name' );
		$year     = (int) ( $_GET['published'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$term     = $selected ? get_term_by( 'slug', (string) $selected, 'category' ) : null;
		$base     = array( 'post_type IN ["post"]' );
		$queries  = array(
			array( 'indexUid' => $uid, 'q' => $q, 'limit' => 0, 'facets' => array( 'tax_category_ids' ), 'filter' => implode( ' AND ', $year ? array_merge( $base, array( 'year = ' . $year ) ) : $base ) ),
			array( 'indexUid' => $uid, 'q' => $q, 'limit' => 0, 'facets' => array( 'year' ), 'filter' => implode( ' AND ', $term ? array_merge( $base, array( 'tax_category_ids = ' . (int) $term->term_id ) ) : $base ) ),
		);
		$response = Recorder::instance()->pause(
			static fn () => wp_remote_post(
				rtrim( (string) MEILISEARCH_HOST, '/' ) . '/multi-search',
				array(
					'timeout' => 2,
					'headers' => array( 'Authorization' => 'Bearer ' . MEILISEARCH_ADMIN_KEY, 'Content-Type' => 'application/json' ),
					'body'    => (string) wp_json_encode( array( 'queries' => $queries ) ),
				)
			)
		);
		$data = is_array( $response ) ? json_decode( (string) wp_remote_retrieve_body( $response ), true ) : null;
		if ( ! is_array( $data['results'] ?? null ) ) {
			return '';
		}
		$topic_counts = (array) ( $data['results'][0]['facetDistribution']['tax_category_ids'] ?? array() );
		$year_counts  = (array) ( $data['results'][1]['facetDistribution']['year'] ?? array() );
		krsort( $year_counts, SORT_NUMERIC );

		$keep  = array_filter( array( 's' => $q, 'published' => $year ?: null, 'category_name' => $selected ?: null ) );
		$html  = '<div class="demo-facets">';
		$html .= self::list( __( 'Topic', 'meili-demo' ), self::topic_items( $topic_counts, (string) $selected, $keep ) );
		$items = array( self::item( __( 'Any year', 'meili-demo' ), null, add_query_arg( array_merge( $keep, array( 'published' => false ) ), home_url( '/' ) ), ! $year ) );
		foreach ( array_slice( $year_counts, 0, 8, true ) as $y => $count ) {
			$items[] = self::item( (string) $y, (int) $count, add_query_arg( array_merge( $keep, array( 'published' => (int) $y ) ), home_url( '/' ) ), (int) $y === $year );
		}
		$html .= self::list( __( 'Year', 'meili-demo' ), $items );
		$orderby = sanitize_key( $_GET['orderby'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$order   = strtolower( sanitize_key( $_GET['order'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$html   .= self::list(
			__( 'Sort', 'meili-demo' ),
			array(
				self::item( __( 'Most relevant', 'meili-demo' ), null, add_query_arg( array_merge( $keep, array( 'orderby' => false, 'order' => false ) ), home_url( '/' ) ), '' === $orderby ),
				self::item( __( 'Newest first', 'meili-demo' ), null, add_query_arg( array_merge( $keep, array( 'orderby' => 'date', 'order' => 'desc' ) ), home_url( '/' ) ), 'date' === $orderby && 'asc' !== $order ),
				self::item( __( 'Oldest first', 'meili-demo' ), null, add_query_arg( array_merge( $keep, array( 'orderby' => 'date', 'order' => 'asc' ) ), home_url( '/' ) ), 'date' === $orderby && 'asc' === $order ),
			)
		);
		$html .= '<p class="demo-facets__note">' . esc_html__( 'Counts come from a demo-side Meilisearch request: the plugin has no facets yet. The links are ordinary WordPress query vars that the plugin translates.', 'meili-demo' ) . '</p></div>';
		return $html;
	}

	private static function content_uid(): ?string {
		foreach ( Recorder::instance()->calls() as $call ) {
			if ( preg_match( '#/indexes/([^/]+_content)/search$#', $call['path'], $m ) ) {
				return $m[1];
			}
		}
		$prefix = getenv( 'MEILISEARCH_INDEX_PREFIX' );
		return $prefix ? $prefix . '_content' : null;
	}

	private static function topic_items( array $counts, string $selected, array $keep ): array {
		$items = array( self::item( __( 'All topics', 'meili-demo' ), null, add_query_arg( array_merge( $keep, array( 'category_name' => false ) ), home_url( '/' ) ), '' === $selected ) );
		foreach ( self::TOPICS as $slug ) {
			$term = get_term_by( 'slug', $slug, 'category' );
			if ( $term ) {
				$items[] = self::item( $term->name, (int) ( $counts[ (string) $term->term_id ] ?? 0 ), add_query_arg( array_merge( $keep, array( 'category_name' => $slug ) ), home_url( '/' ) ), $slug === $selected );
			}
		}
		return $items;
	}

	private static function item( string $label, ?int $count, string $url, bool $current ): string {
		return sprintf(
			'<li><a href="%1$s"%2$s><span>%3$s</span>%4$s</a></li>',
			esc_url( $url ),
			$current ? ' aria-current="true"' : '',
			esc_html( $label ),
			null === $count ? '' : ' <b>' . esc_html( (string) $count ) . '</b>'
		);
	}

	private static function list( string $label, array $items ): string {
		return '<h2 class="demo-facets__h">' . esc_html( $label ) . '</h2><ul aria-label="' . esc_attr( $label ) . '">' . implode( '', $items ) . '</ul>';
	}
}
```

`add_query_arg` with a value of `false` removes the key, and `null` values were filtered out of `$keep`. The facet `<ul>` gets its accessible name from `aria-label`, which the test's `getByRole( 'list', { name: 'Topic' } )` relies on.

- [ ] **Step 6: `Hardening.php`**

```php
<?php
namespace MeiliDemo;

/** Public-demo hardening: no XML-RPC, no anonymous user listing, no new comments, no orders. */
final class Hardening {
	public static function register(): void {
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', static fn (): array => array() );
		add_filter( 'pings_open', '__return_false' );
		add_filter( 'comments_open', '__return_false' );
		add_filter( 'wp_headers', static function ( array $headers ): array {
			unset( $headers['X-Pingback'] );
			return $headers;
		} );
		add_filter( 'rest_pre_dispatch', array( self::class, 'rest' ), 10, 3 );
		add_action( 'woocommerce_checkout_process', static function (): void {
			wc_add_notice( __( 'Demo store: no orders are taken', 'meili-demo' ), 'error' );
		} );
	}

	public static function rest( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		$route = $request->get_route();
		if ( str_starts_with( $route, '/wp/v2/users' ) && ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', 'Not available on this demo.', array( 'status' => 401 ) );
		}
		if ( str_starts_with( $route, '/wc/store/v1/checkout' ) || str_starts_with( $route, '/wc/store/checkout' ) ) {
			return new \WP_Error( 'demo_store', 'Demo store: no orders are taken', array( 'status' => 403 ) );
		}
		return $result;
	}
}
```

Register everything in `shared/mu-plugins/meili-demo.php`:

```php
MeiliDemo\Documents::register();
MeiliDemo\Hardening::register();
add_action(
	'init',
	static function (): void {
		MeiliDemo\TryChips::register();
		MeiliDemo\Facets::register();
	}
);
```

Add `.demo-chips` styles to `assets/demo.css`: an inline flex list, dashed pill borders using `currentColor` at 40% via `color-mix`. Add `.demo-facets` to the blog `style.css`, following the mockup's `.ml .facet` rules: `aria-current` links get the flare dot, and counts are muted.

- [ ] **Step 7: Rebuild documents and run everything**

Document fields and settings only change at index time:

```bash
docker compose exec blog wp --allow-root meilisearch connect && docker compose exec blog wp --allow-root meilisearch reindex
docker compose exec shop wp --allow-root meilisearch connect && docker compose exec shop wp --allow-root meilisearch reindex
npm run test:e2e
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/php/run.php
```

Expected: all E2E projects pass (every spec so far), plus the PHP tests. Also check from a fresh volume: `docker compose down -v && docker compose up --build --wait && npm run test:e2e`. Expected: the same, because first boot reindexes after the mu-plugin is present.

- [ ] **Step 8: Commit**

```bash
git add blog/try-chips.json shop/try-chips.json shared blog/theme/style.css tests/e2e/blog-extras.spec.ts tests/e2e/shop-extras.spec.ts
git commit -m "Demo extras: try chips, topic/year facets, published years, autocomplete subtitles, public hardening"
```

### Task D11: CI, lint and README

**Files:**
- Create: `.github/workflows/ci.yml`, `README.md`, `docs/known-gaps.md` (only if Task D9 found one)
- Modify: `.dockerignore` (add `plugin`), `bin/smoke.sh` (CI-friendly URLs)

**Interfaces:**
- Consumes: everything above.
- Produces: green CI on `main`. The README is the entry point for humans, laid out like Meili Kitchen's: Quick start, A two-minute demo (both sites), How it is built (table), Working on the plugin (`MEILISEARCH_PLUGIN_PATH`, `docker compose watch`), Data (scripts, licences, review), Deploy, Reset, Troubleshooting.

- [ ] **Step 1: Workflow**

```yaml
name: CI
on:
  push:
    branches: [main]
  pull_request:
  workflow_dispatch:
    inputs:
      plugin_ref:
        description: meilisearch-wordpress ref to build the demo against
        default: qdequele/demo-support

permissions:
  contents: read

concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  scripts:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
        with: { persist-credentials: false }
      - uses: actions/setup-python@v6
        with: { python-version: "3.12" }
      - run: pip install -r scripts/requirements.txt
      - run: cd scripts && pytest -q

  lint:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v5
        with: { persist-credentials: false }
      - run: shellcheck docker/*.sh bin/*.sh
      - uses: hadolint/hadolint-action@v3.1.0
        with: { dockerfile: Dockerfile }
      - uses: hadolint/hadolint-action@v3.1.0
        with: { dockerfile: Dockerfile.dev }
      - run: docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c 'for f in $(find shared blog shop -name "*.php"); do php -l "$f" || exit 1; done && php tests/php/run.php'

  e2e:
    runs-on: ubuntu-latest
    timeout-minutes: 60
    env:
      MEILISEARCH_PLUGIN_PATH: ./plugin
      MEILISEARCH_HOST: http://meilisearch:7700
      MEILISEARCH_PORT: "7700"
      BLOG_HOME: http://localhost:8081
      SHOP_HOME: http://localhost:8082
      BLOG_URL: http://localhost:8081
      SHOP_URL: http://localhost:8082
      E2E_MAP_MEILISEARCH: "1"
    steps:
      - uses: actions/checkout@v5
        with: { persist-credentials: false, lfs: true }
      - uses: actions/checkout@v5
        with:
          repository: meilisearch/meilisearch-wordpress
          ref: ${{ inputs.plugin_ref || 'qdequele/demo-support' }}
          path: plugin
          persist-credentials: false
      - run: cp .env.example .env
      - run: docker compose up --build --wait --wait-timeout 1500
      - run: bin/smoke.sh --restart
      - uses: actions/setup-node@v5
        with: { node-version: 22, cache: npm }
      - run: npm ci && npx playwright install --with-deps chromium
      - run: npm run test:e2e
      - if: failure()
        run: docker compose logs --no-color > compose.log
      - if: failure()
        uses: actions/upload-artifact@v4
        with:
          name: e2e-debug
          path: |
            playwright-report
            test-results
            compose.log
```

The plugin checkout lives at `./plugin` inside the demo checkout. Add `plugin` to `.dockerignore`, so it never enters the demo's own build context, and to `.gitignore`. `bin/smoke.sh` already reads `BLOG_URL`/`SHOP_URL`, so it works unchanged in CI.

Once the plugin PR is merged, change the default `plugin_ref` and the fallback in the checkout step to `main`.

- [ ] **Step 2: Lint locally**

```bash
docker run --rm -v "$PWD":/mnt koalaman/shellcheck:stable docker/*.sh bin/*.sh
docker run --rm -i hadolint/hadolint < Dockerfile
docker run --rm -i hadolint/hadolint < Dockerfile.dev
```

Expected: no errors. Fix warnings, or add a scoped `# hadolint ignore=DL3008` for unpinned apt packages (acceptable for a demo image) next to the line, with a reason.

- [ ] **Step 3: README**

Write `README.md` with the sections listed in Interfaces. Required content:

- **Quick start:** `cp .env.example .env`, set `MEILISEARCH_PLUGIN_PATH`, `docker compose watch`. Mention the first-boot time (blog about 5 minutes, shop about 10). Give both OrbStack URLs, the localhost fallback ports (and that autocomplete needs `MEILISEARCH_HOST` reachable by the browser), and the `admin` / `admin` login.
- **A two-minute demo:** numbered steps for each site:
  1. type in the search box (autocomplete);
  2. click the chips (the table of chips and what they show, from the two JSON files);
  3. open Under the hood;
  4. pick a topic or year on the blog, or filter, price and sort on the shop;
  5. Compare with MySQL;
  6. edit a post or product title as admin and search for it (indexed on save through Action Scheduler).
- **How it is built:** a table mapping each part to its files. Include the note that the image builds the plugin like the plugin's own `Dockerfile.dev`, not through `bin/build-zip.sh`, because worktrees have no `.git` directory inside the build context.
- **Data and licences:**
  - NASA: public domain, no endorsement, third-party images filtered with `scripts/nasa_third_party.txt` and reviewed on a contact sheet.
  - The Met: CC0. How to rerun the scripts (`--refresh` vs manifest replay).
  - Fonts: OFL.
- **Deploy:** written in Task D12.
- **Reset:** `docker compose down -v`.
- **Troubleshooting:**
  - autocomplete silent → browser can't reach `MEILISEARCH_HOST`;
  - "Served by MySQL" everywhere → `docker compose exec blog wp --allow-root meilisearch check`;
  - first boot failed → logs, then `docker compose down -v`.

- [ ] **Step 4: Push and watch CI**

Ask the user before creating the GitHub repository, because that is outward-facing. Suggested: `gh repo create meilisearch/wordpress-meilisearch-demo --private --source . --push`. If they agree:

```bash
gh run watch --exit-status
```

Expected: `scripts`, `lint` and `e2e` all pass. LFS bandwidth: every e2e run pulls about 400 MB. If that becomes a problem, add `actions/cache` on `.git/lfs` keyed by `hashFiles('data/*/manifest.json')`.

- [ ] **Step 5: Commit**

```bash
git add .github README.md .dockerignore .gitignore bin/smoke.sh
[ -f docs/known-gaps.md ] && git add docs/known-gaps.md
git commit -m "CI (scripts, lint, e2e against the plugin), README"
```

### Task D12: Fly.io image and configuration

**Files:**
- Modify: `Dockerfile` (`fly` target reads the plugin from `.plugin/`)
- Create: `docker/supervisord.conf`, `docker/fly-start.sh` (flesh out), `blog/fly.toml`, `shop/fly.toml`, `bin/fly-deploy.sh`
- Modify: `README.md` (Deploy section), `.gitignore` (`.plugin/`)

**Interfaces:**
- Consumes: the image layout from Task D1.
- Produces: `bin/fly-deploy.sh blog|shop`, which stages the plugin source into `.plugin/` (`git archive` of `MEILISEARCH_PLUGIN_PATH`'s HEAD) and runs `fly deploy`. The Fly secrets are those named in spec § 4.4.

- [ ] **Step 1: Check the production Dockerfile's plugin source**

`Dockerfile` already reads the plugin from `.plugin/` (Task D1). Make sure `.dockerignore` does **not** exclude `.plugin`, and add `.plugin/` to `.gitignore`.

- [ ] **Step 2: Supervisor and start script**

`docker/fly-start.sh`:

```bash
#!/usr/bin/env bash
# Fly entrypoint: MariaDB and the site share one machine; everything persistent lives on the /data volume.
set -euo pipefail
: "${DB_NAME:?}" "${DB_PASSWORD:?}"
mkdir -p /data/html /data/mysql /run/mysqld
chown -R mysql:mysql /data/mysql /run/mysqld
if [ ! -d /data/mysql/mysql ]; then
	mariadb-install-db --datadir=/data/mysql --user=mysql --skip-test-db >/dev/null
fi
umask 077
cat > /run/mysqld/init.sql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'wp'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER 'wp'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO 'wp'@'127.0.0.1';
SQL
chown mysql:mysql /run/mysqld/init.sql
export DB_HOST=127.0.0.1 DB_USER=wp DEMO_ROOT=/data/html
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
```

`docker/supervisord.conf`:

```ini
[program:mariadb]
command=/usr/sbin/mariadbd --datadir=/data/mysql --user=mysql --bind-address=127.0.0.1 --init-file=/run/mysqld/init.sql --innodb-buffer-pool-size=256M --max-connections=60
priority=10
autorestart=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true

[program:web]
command=/opt/demo/bin/demo-entrypoint.sh apache2-foreground
priority=20
autorestart=true
startsecs=0
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
redirect_stderr=true
```

`demo-entrypoint.sh` already waits for the database and only installs on first boot. On Fly, `DEMO_ROOT=/data/html` replaces `/var/www/html`, which is why the Apache config uses `${DEMO_ROOT}`.

- [ ] **Step 3: `bin/fly-deploy.sh` and `fly.toml`**

```bash
#!/usr/bin/env bash
# Usage: bin/fly-deploy.sh blog|shop  — stages the plugin source (HEAD of MEILISEARCH_PLUGIN_PATH) and deploys.
set -euo pipefail
site="${1:?blog or shop}"
cd "$(dirname "$0")/.."
# shellcheck disable=SC1091
[ -f .env ] && set -a && . ./.env && set +a
plugin="${MEILISEARCH_PLUGIN_PATH:-../../_sdk/meilisearch-wordpress}"
rm -rf .plugin && mkdir .plugin
git -C "$plugin" archive HEAD | tar -x -C .plugin
echo "Plugin $(git -C "$plugin" rev-parse --short HEAD) staged in .plugin/"
fly deploy --config "$site/fly.toml" --dockerfile Dockerfile --remote-only
```

`blog/fly.toml`:

```toml
app = "meili-wp-blog"
primary_region = "cdg"

[build]
  build-target = "fly"
  [build.args]
    SITE = "blog"

[env]
  SITE = "blog"
  DB_NAME = "blog"
  MEILISEARCH_INDEX_PREFIX = "blog"
  WP_ENVIRONMENT_TYPE = "production"

[[mounts]]
  source = "data"
  destination = "/data"
  initial_size = "3gb"

[http_service]
  internal_port = 80
  force_https = true
  auto_stop_machines = "suspend"
  auto_start_machines = true
  min_machines_running = 0

  [[http_service.checks]]
    grace_period = "900s"
    interval = "30s"
    method = "GET"
    path = "/wp-login.php"
    timeout = "10s"

[[vm]]
  size = "shared-cpu-2x"
  memory = "2gb"
```

`shop/fly.toml` is the same with `meili-wp-shop` and `shop` everywhere.

Add WooCommerce's version build arg to both: `WOOCOMMERCE_VERSION = "<value from .env.example>"` under `[build.args]`, plus `WORDPRESS_VERSION` likewise.

- [ ] **Step 4: Verify the Fly image locally (no Fly account needed)**

```bash
rm -rf .plugin && mkdir .plugin && git -C "$MEILISEARCH_PLUGIN_PATH" archive HEAD | tar -x -C .plugin
docker build --target fly --build-arg SITE=blog --build-arg WORDPRESS_VERSION=7.1.2 --build-arg WOOCOMMERCE_VERSION=10.3.0 -t meili-wp-blog:fly .
docker volume create meili-wp-fly-test
docker run -d --name fly-test -p 8090:80 -v meili-wp-fly-test:/data \
  -e WP_HOME=http://localhost:8090 -e WP_ADMIN_PASSWORD=secret -e DB_PASSWORD=dbsecret \
  -e MEILISEARCH_HOST=http://meilisearch.wordpress-meilisearch-demo.orb.local:7700 -e MEILISEARCH_ADMIN_KEY=meili-wp-demo-master-key \
  -e MEILISEARCH_INDEX_PREFIX=flytest meili-wp-blog:fly
until docker logs fly-test 2>&1 | grep -q '\[demo\] Ready'; do sleep 10; done
curl -fsS "http://localhost:8090/?s=saturn" | grep -q 'ml-result' && echo "search ok"
docker restart fly-test && sleep 30 && test "$(docker logs fly-test 2>&1 | grep -c '\[demo\] First boot')" = 1 && echo "restart ok"
docker rm -f fly-test && docker volume rm meili-wp-fly-test
curl -X DELETE -H 'Authorization: Bearer meili-wp-demo-master-key' http://localhost:7701/indexes/flytest_content
```

Use the real versions from `.env.example`. Expected: `search ok` and `restart ok`.

- [ ] **Step 5: README Deploy section and commit**

Document:
1. create a Meilisearch Cloud project and copy its URL and **default admin API key**;
2. `fly apps create meili-wp-blog` and `meili-wp-shop`;
3. `fly volumes create data --size 3 --region cdg -a <app>`;
4. `fly secrets set -a <app> MEILISEARCH_HOST=… MEILISEARCH_ADMIN_KEY=… WP_ADMIN_PASSWORD=… DB_PASSWORD=… WP_HOME=https://<app>.fly.dev`;
5. `bin/fly-deploy.sh blog` and `bin/fly-deploy.sh shop`;
6. `fly logs -a <app>` until `[demo] Ready`.

Reset: `fly volumes destroy` and create again.

```bash
git add Dockerfile docker bin/fly-deploy.sh blog/fly.toml shop/fly.toml README.md .gitignore .dockerignore
git commit -m "Fly.io: single-machine image (MariaDB + Apache under supervisord), deploy script and docs"
```

### Task D13: First deploy (needs the user)

This task creates paid, public resources. **Stop and ask the user** before each of these:
- creating the Meilisearch Cloud project (the user does it and provides the URL and key);
- creating the Fly apps and volumes;
- setting secrets;
- deploying.

- [ ] **Step 1: Licence review gate**

The user (or a reviewer) has looked through `data/blog/contact-sheet.html` and confirmed that no third-party image remains (spec § 11). Record the date in the README's Data section.

- [ ] **Step 2: Deploy both apps**

Follow the README Deploy section exactly. Expected:
- `https://meili-wp-blog.fly.dev/?s=saturn+rngs` shows results with the panel naming `blog_content`;
- `https://meili-wp-shop.fly.dev/?s=van+gof&post_type=product` shows prints;
- autocomplete works on both, because the browser reaches the Meilisearch Cloud URL directly.

- [ ] **Step 3: Post-deploy E2E against production (read-only)**

```bash
BLOG_URL=https://meili-wp-blog.fly.dev SHOP_URL=https://meili-wp-shop.fly.dev npx playwright test blog-theme blog-under-the-hood shop-theme --grep-invert "checkout|wp\\("
```

Expected: PASS for every test that doesn't use `wp()`. Tests that shell into Compose can't run against Fly; the `--grep-invert` keeps them out, so adjust it to the actual test names.
