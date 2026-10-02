# WordPress demos for Meilisearch for WordPress: design spec

Date: 2026-10-02. Status: approved in brainstorming, pending written review.
Plugin under demonstration: `meilisearch/meilisearch-wordpress` v1.0 (PR #32), checked out at `../../_sdk/meilisearch-wordpress`.
Approved mockups: `docs/mockups/demos.html` (open in a browser; two tabs).

## 1. Goal

Two public, hosted showcase sites that show what installing the plugin gives a WordPress site:

- **Mission Log**: a space magazine of about 1,000 NASA science articles on plain WordPress.
- **Met Prints**: a WooCommerce shop selling prints of about 1,500 public-domain artworks from The Met.

Each runs with one command locally (`docker compose watch`) and is deployed on Fly.io against Meilisearch Cloud, following the pattern of Meili Kitchen (`_demos/drupal-meilisearch-demo`).

### Success criteria

- A visitor sees typo-tolerant, as-you-type search on an ordinary-looking WordPress site and WooCommerce store, within a few seconds of landing.
- Every search the visitor runs goes through the plugin's real code path: `posts_pre_query` interception and its autocomplete. Nothing in the demo replaces the plugin's search.
- The "Under the hood" panel shows the exact Meilisearch request the plugin built from the `WP_Query`.
- "Compare with MySQL" runs the same search through WordPress's native search.
- All content is legally redistributable and attributed on the page.
- `docker compose up --build` from a clean checkout reaches `[demo] Ready` for both sites without network access to NASA or The Met.

### Non-goals

- A facet UI inside the plugin: it is deferred to plugin 1.1, and the demo does not ship one as plugin behaviour.
- Taking real orders.
- Semantic or hybrid search: the plugin supports it, but it needs an embedder. It is left for a later iteration.
- Multisite.

## 2. Decisions

| Topic | Decision |
|---|---|
| Purpose | Public hosted showcase, also runnable locally |
| Articles | science.nasa.gov posts (US government work, public domain) |
| Products | The Met Open Access objects (CC0), sold as prints |
| Repo | One repo, `_demos/wordpress-meilisearch-demo`, with two sites |
| Runtime | WordPress and MariaDB. Locally they are separate Compose services; on Fly they share one machine under supervisord |
| Themes | Two custom block themes, as in the mockups |
| Extras | A demo-only mu-plugin: Try chips, the Under the hood panel, the MySQL comparison, blog facet counts and attribution |
| Autocomplete gap | Implemented in the plugin itself (§ 8), not faked in the demo |

## 3. Repository layout

```
wordpress-meilisearch-demo/
├── compose.yaml              # meilisearch, mariadb, blog, shop
├── Dockerfile                # production image, ARG SITE=blog|shop
├── Dockerfile.dev            # same plus dev tooling (no opcache, Xdebug off by default)
├── docker/
│   ├── entrypoint.sh         # every start: apply env. First start: install + import + reindex
│   ├── supervisord.conf      # Fly only: apache + mariadb
│   ├── mariadb-init.sh       # Fly only: initialise the datadir on the volume
│   └── php.ini               # memory, upload and opcache settings
├── shared/mu-plugins/meili-demo/   # demo layer (§ 6)
├── blog/
│   ├── theme/mission-log/    # block theme
│   ├── setup.php             # WP-CLI eval-file: options, menus, plugin settings, import
│   ├── try-chips.json
│   └── fly.toml
├── shop/
│   ├── theme/met-prints/     # block theme + WooCommerce template overrides
│   ├── setup.php
│   ├── try-chips.json
│   └── fly.toml
├── scripts/
│   ├── nasa.py               # fetch + curate → data/blog
│   ├── met.py                # fetch + curate → data/shop
│   ├── common.py             # image resize to WebP, HTML sanitising, deterministic RNG
│   └── tests/                # pytest
├── data/                     # committed snapshot; images in Git LFS
│   ├── blog/articles.json, images/
│   └── shop/artworks.json, images/
├── tests/e2e/                # Playwright: blog.spec.ts, shop.spec.ts
├── .github/workflows/ci.yml
├── docs/mockups/demos.html
└── README.md
```

## 4. Runtime

### 4.1 Local (`compose.yaml`)

| Service | Image | Notes |
|---|---|---|
| `meilisearch` | `getmeili/meilisearch:${MEILISEARCH_VERSION:-v1.34}` (lowest supported; CI also runs `latest`) | Master key from `.env`; healthcheck on `/health` |
| `mariadb` | `mariadb:11.4` | Databases `blog` and `shop`, created by an init script; healthcheck `healthcheck.sh --connect` |
| `blog` | built from `Dockerfile.dev`, `SITE=blog` | Port `${BLOG_PORT:-8081}`; OrbStack URL `blog.wordpress-meilisearch-demo.orb.local` |
| `shop` | built from `Dockerfile.dev`, `SITE=shop` | Port `${SHOP_PORT:-8082}`; `shop.wordpress-meilisearch-demo.orb.local` |

- Both sites `depends_on` `meilisearch` and `mariadb` with `condition: service_healthy`.
- Named volumes: `meili_data`, `db_data`, `blog_uploads`, `shop_uploads`.
- `develop.watch` (no source volume mounts):
  - `sync`: `blog/theme` and `shop/theme` → `wp-content/themes`; `shared/mu-plugins` → `wp-content/mu-plugins`; `${MEILISEARCH_PLUGIN_PATH}/src` and `/assets` → `wp-content/plugins/meilisearch/...`.
  - `rebuild`: `Dockerfile*`, `docker/`, `${MEILISEARCH_PLUGIN_PATH}/composer.json`.
  - `sync+restart`: `blog/setup.php`, `shop/setup.php`.
- `.env.example` documents `MEILISEARCH_PLUGIN_PATH` (default `../../_sdk/meilisearch-wordpress`), `MEILI_MASTER_KEY`, the ports and `WP_ADMIN_PASSWORD` (default `admin`).

### 4.2 Image

- Base: `wordpress:php8.3-apache`, plus WP-CLI (pinned version, checksum verified), `mariadb-server` (only in the `fly` target), `supervisor` and `less`.
- Multi-stage build:
  1. A `plugin` stage (composer and node) uses the `additional_contexts: plugin` entry pointing at `MEILISEARCH_PLUGIN_PATH`. It runs that checkout's `bin/build-zip.sh`.
  2. The final stage copies `build/meilisearch/` to `wp-content/plugins/meilisearch`.
- WooCommerce: installed at build time with `wp plugin install woocommerce --version=<pinned>` into the image. This happens only for `SITE=shop`.
- `data/$SITE` is copied into `/opt/demo/data`.
- Targets: `dev` (Compose, external MariaDB) and `fly` (adds MariaDB and supervisord).

### 4.3 Entrypoint (`docker/entrypoint.sh`)

On every start:

1. Wait for the database.
2. Write `wp-config.php` from the environment: DB credentials, `WP_HOME`/`WP_SITEURL`, `DISALLOW_FILE_EDIT`, `WP_ENVIRONMENT_TYPE`, and the plugin's `MEILISEARCH_HOST` and `MEILISEARCH_ADMIN_KEY` constants. The plugin reads both, and its connection fields then become read-only. The index prefix is not a constant: `setup.php` sets the `prefix` field of the plugin's connection option group to `MEILISEARCH_INDEX_PREFIX`, re-applied on every start.
3. Reset the admin password from `WP_ADMIN_PASSWORD`.
4. If `wp-content/uploads/.demo-installed` is missing:
   1. `wp core install`.
   2. Activate the theme, WooCommerce (shop) and the plugin.
   3. `wp eval-file /opt/demo/$SITE/setup.php`, which sets options and imports `data/`.
   4. Connect the plugin to Meilisearch.
   5. `wp meilisearch reindex` (synchronous).
   6. Enable "Replace site search", highlighting and autocomplete.
   7. Touch the marker.
5. `wp meilisearch status` must report healthy. If it doesn't, the demo logs a warning and still serves, so MySQL fallback keeps the site usable.
6. Log `[demo] Ready`, then `exec` Apache (Compose) or supervisord (Fly).

The install is idempotent: if the reindex or the import fails, the marker is not written, and the next start retries.

### 4.4 Fly.io

- Two apps, `meili-wp-blog` and `meili-wp-shop`, built from the same Dockerfile (target `fly`, `SITE` build arg per `fly.toml`).
- Each app has one shared-cpu-2x machine with 2 GB RAM and a 3 GB volume mounted at `/data`. MariaDB's datadir and `wp-content/uploads` live on the volume.
- `auto_stop_machines = "suspend"`, `min_machines_running = 0`. A cold start is acceptable for a demo.
- HTTP health check on `/wp-login.php`.
- Secrets: `MEILISEARCH_URL`, `MEILISEARCH_API_KEY` (a Meilisearch Cloud key with the rights the plugin's connect step needs), `MEILISEARCH_INDEX_PREFIX` (`blog` / `shop`), `WP_ADMIN_PASSWORD`, `WP_HOME`, `DB_PASSWORD`.
- Deploy: `fly deploy --config blog/fly.toml` (and the same for `shop`), documented in the README. CI does not deploy.
- Reset: destroy the volume and deploy again.

### 4.5 Public hardening

- Comments are closed, registration is off, XML-RPC is disabled through a filter in the mu-plugin, and file editing is off.
- REST user enumeration is blocked (`/wp/v2/users` requires authentication).
- Shop:
  - The store notice reads "Demo store: no orders are taken".
  - No payment gateway is enabled. Checkout ends in a notice and cannot create orders, through a `woocommerce_checkout_process` error.
  - Cart and variant selection work.
- The only key the browser sees is the plugin's scoped search key.

## 5. Themes

Both themes are block themes (`theme.json` v3) with no build step. CSS lives in `theme.json` and a single `style.css`. Fonts are self-hosted WOFF2 files from Google Fonts, under the OFL. The visual design follows `docs/mockups/demos.html`.

### 5.1 Mission Log (blog)

- **Palette:** night `#070b18`, panels `#0f1630`, accent `#ff8a5c`. Space Grotesk for headings, Inter for body text.
- **Templates:**
  - `home` has a featured hero (the latest sticky post) and a grid of the latest posts.
  - `single`, `archive` (category/tag/author/date), `search` and `404`.
- **Header:**
  - The menu is built from the six top-level categories: Missions, Space Station, Earth, Solar System, Universe and History.
  - The search field is a core Search block, so the plugin's autocomplete attaches to it.
- **Search template:**
  - The core Query Loop inherits the main query; the plugin intercepts it.
  - The left column holds the topic, year and sort links, which are plain query vars. Their counts come from the demo layer (§ 6.4).
  - The right column holds the Under the hood panel (§ 6.2).
  - Excerpts come from the plugin's highlighter.

### 5.2 Met Prints (shop)

- **Palette:** paper `#f7f4ee`, ink `#1d1a16`, accent `#a3242c`. Cormorant Garamond for headings, Inter for body text.
- **Templates:**
  - `front-page` has a hero and a featured grid.
  - WooCommerce block templates are overridden: `archive-product`, `product-search-results` and `single-product`.
  - Product images sit in a white "mat" frame.
- **Search results:**
  - The left column is WooCommerce's own Product Filters blocks: attribute filters for `pa_department`, `pa_century` and `pa_medium`, plus the price filter and the stock filter. These produce the `filter_*` / `min_price` / `max_price` query vars the plugin translates.
  - Above the grid sits the WooCommerce catalog sorting.
  - The right column holds the Under the hood panel.
  - Implementation must verify that the plugin intercepts the URLs WooCommerce's current filter blocks generate (spec § 8.5 of the plugin). If a block emits something the plugin declines to translate, the search still works through MySQL, the panel shows "MySQL (not intercepted)", and the gap is logged as a plugin issue. It is not hidden.

## 6. Demo layer (`shared/mu-plugins/meili-demo/`)

A must-use plugin, namespaced `MeiliDemo`, with no build step. It only uses the plugin's public extension points.

### 6.1 Try chips

- Each site has a `try-chips.json` file: a list of `{ "q": "...", "why": "..." }`.
- The chips render as a block pattern under the search heading, and each one links to `/?s=…` (shop: `&post_type=product`).
- Blog chips: `saturn rngs`, `jupitr moons`, `"heat shield"`, `moon -apollo`, `astronot`.
- Shop chips: `van gof`, `hokusai wave`, `degas dancer`, `"still life"`, `landscape -river`.
- Each chip's query must return at least one result against the committed data; an E2E test checks this.

### 6.2 Under the hood

- **Recording the request:** the plugin sends every request through `wp_remote_request`. WordPress's `http_api_debug` action receives the URL, the request args and the response. The demo listens there and records calls whose URL starts with `MEILISEARCH_HOST` and ends in `/search` or `/multi-search`: the method, path, decoded JSON body, status and the response's `processingTimeMs` and `estimatedTotalHits`/`totalHits`. This is the exact request on the wire, not a reconstruction, and needs no plugin change. The `Authorization` header is never recorded.
- **Recording the source:** a `pre_get_posts` callback at priority `PHP_INT_MAX` records the main query's relevant vars: `s`, `paged`, `post_type`, taxonomy vars, `orderby`, `filter_*`, `query_type_*`, `min_price`, `max_price`. (`meilisearch_search_params` receives `($params, SearchRequest, $logical)` but not the `WP_Query`, so it is not used.)
- **Timing:** the panel shows Meilisearch's `processingTimeMs` and the PHP wall time of the whole HTTP call, from `http_api_debug` and a `pre_http_request` timestamp.
- **Rendering:** a block, `meili-demo/under-the-hood`, placed in both search templates. It renders the recorded request as escaped, pretty-printed JSON with syntax colouring done in PHP.
  - If nothing was recorded (not intercepted, or the circuit breaker is open), it says "Served by MySQL" and gives the reason when it is known.

### 6.3 Compare with MySQL

- The `?engine=mysql` query var makes `meilisearch_should_intercept` return false for that request. The panel's toggle switches between the two URLs.
- The panel shows the hit count and wall time for both engines. The other engine's count comes from one extra `WP_Query` with `fields => ids`, run only on search pages with the same vars and the opposite engine. It is capped at 1 per request and skipped when `paged > 1`.

### 6.4 Blog facet counts

- On blog search pages only, the demo layer sends one search to the content index with the same `q` and the same filter minus the facet's own clause. It asks for `facets: ["tax_category_ids", "year"]` and `limit: 0`, through a plain server-side `wp_remote_post` to `MEILISEARCH_HOST` with `MEILISEARCH_ADMIN_KEY`. The plugin's `Client` is internal and is not used. The index UID is read from the recorded request (§ 6.2), so the demo never recomputes the prefix. This request is excluded from the recorder.
- `year` is a demo-only field added through `meilisearch_document`. Making it filterable also needs `meilisearch_index_settings` to add it to `filterableAttributes`.
- The template states in small print that these counts come from the demo. The links remain plain `category_name` and `year` query vars.

### 6.5 Autocomplete subtitles

The demo layer uses the new plugin filter `meilisearch_autocomplete_subtitle_field` (§ 8) and adds the field through `meilisearch_document`:
- blog: `"{first top-level category} · {M j, Y}"`;
- shop: `"{artist} · {object date}"`.

### 6.6 Attribution

A footer block in each site:
- **Blog:** "Content: NASA (public domain). Not endorsed by NASA." Each post ends with "Source: <original URL>", added at import time.
- **Shop:** "Images and data: The Metropolitan Museum of Art, Open Access (CC0). Not affiliated with The Met." Each product page lists its Met object ID and links to the object.

## 7. Data pipeline (`scripts/`)

Python 3.12, standard library plus `requests`, `Pillow` and `pytest`. It runs on the host or in a `python:3.12-slim` container. Both scripts are deterministic: pinned ID lists are written to `data/*/manifest.json` and the RNG is seeded with a fixed seed. They are rerunnable, and output is committed. Images are WebP, longest side 1,600 px, quality 80, stored with Git LFS (`data/**/images/*`).

### 7.1 `nasa.py` → `data/blog/articles.json`

- **Source:** `https://science.nasa.gov/wp-json/wp/v2/posts?_embed=wp:featuredmedia,wp:term,author` (68,703 posts as of 2026-10-02). Requests are polite: at most 2 requests per second, with a User-Agent that identifies the project.
- **Topic quotas:** posts are taken newest first, so 1,000 posts are spread across the six demo topics. Each topic maps from source categories:
  - Missions: Artemis, ISS, Kennedy, Johnson and Marshall centres;
  - Space Station: ISS and ISS Research;
  - Earth: Earth, Earth Observatory, Wildfires and Hurricanes;
  - Solar System: The Solar System, Mars, Saturn, the Moon and Cassini;
  - Universe: Hubble, Webb, Galaxies, Exoplanets and Stars;
  - History: posts tagged with mission anniversaries, plus Apollo.
  The exact map lives in `scripts/nasa_topics.json`.
- **Excluded:** the categories Photojournal and APOD, and posts under 150 words.
- **Images:** the featured image is kept only when neither its caption nor its file name matches the third-party pattern list. The list lives in `scripts/nasa_third_party.txt`: `via Flickr`, `CC BY`, `Getty`, `AP Photo`, `Reuters`, `credit_` with a non-NASA name, and similar entries.
  - Posts without a usable image get their topic's fallback image: a NASA-made image chosen by hand, listed in `nasa_topics.json`.
  - Inline `<figure>` images in the body follow the same rule and are removed when they fail it.
- **Body:** sanitized to `p, h2, h3, ul, ol, li, blockquote, figure, figcaption, img, a, strong, em`. All attributes are stripped except `href` and `src`, and kept inline images are rewritten to local files.
- **Other fields:** `excerpt`, `author` (the display name, or "NASA Science" when empty), `date_gmt`, `topic`, `categories[]` (source category names kept as WordPress tags), `source_url` and `image`. About 5 posts per topic are marked `sticky` to feed the home hero.

### 7.2 `met.py` → `data/shop/artworks.json`

- **Selection:** `MetObjects.csv` from `github.com/metmuseum/openaccess`, via Git LFS media URL, with these filters:
  - `Is Public Domain = True`;
  - Classification is one of Paintings, Prints, Drawings or Photographs;
  - an image is present.
  Objects with `Is Highlight` or `Is Timeline Work` come first, then about 1,500 objects are balanced across departments.
- **Per object:** `GET /public/collection/v1/objects/{id}`. The search endpoint returns 410 and is not used.
  - The object is dropped unless `isPublicDomain` is true and `primaryImageSmall` is present.
  - Fields: title, artistDisplayName (or "Unknown artist"), objectDate, objectBeginDate (giving the century), department, medium, dimensions, creditLine, culture, tags and objectURL.
- **Product model:**
  - Each object becomes a variable product. Its global attributes are `pa_department`, `pa_artist`, `pa_century` and `pa_medium`. `pa_medium` is normalized to about 12 buckets, for example "Oil on canvas" and "Woodblock print".
  - Variation attributes are `pa_size` (`30 × 24 cm`, `50 × 40 cm`, `70 × 56 cm`, `100 × 80 cm`, with the aspect ratio following the artwork's orientation) and `pa_finish` (Unframed, Black frame, Oak frame).
- **Price:** base by size $45 / $70 / $110 / $160, plus finish $0 / $50 / $100. A deterministic 10% of products are on sale at 20% off. A deterministic 3% of variations are out of stock.
- **Ratings:** 0–12 seeded reviews per product with deterministic ratings. They are written as approved reviews authored by "Demo shopper", so `rating_average` and `rating_count` are real WooCommerce values.
- **Categories:** Paintings, Japanese Prints (Prints from the Asian Art department), Prints, Drawings and Photographs.

### 7.3 Import (`setup.php`)

- Batches of 100 run with `wp_defer_term_counting(true)` and `wp_suspend_cache_invalidation(true)`. Images are imported with `media_handle_sideload` from the local files.
- The plugin's change collector is not suspended. Its queued sync jobs are harmless, and the final `wp meilisearch reindex` is authoritative.
- Target times: under 5 minutes for the blog and under 10 minutes for the shop on a 2-CPU machine. If the shop exceeds that, image sub-size generation drops to the theme's two used sizes.

## 8. Plugin change: autocomplete subtitles and "See all" footer

This change is made in `meilisearch/meilisearch-wordpress` as a follow-up PR on top of #32, test-first, under the plugin spec's conventions.

- **New filter:** `apply_filters( 'meilisearch_autocomplete_subtitle_field', null, string $logical )`, where `$logical` is `content` or `products`.
  - A non-null return must be a valid field name, matching `^[A-Za-z0-9_.]+$`; anything else is ignored.
  - The field is passed in the localized config and added to `attributesToRetrieve` for that index.
- **JS:** when a hit has a string subtitle, it renders `<span class="meilisearch-ac__subtitle">` using `textContent` inside the option, under the title, and it is included in the option's accessible name.
- **Footer:**
  - A `<div class="meilisearch-ac__footer">` shows "See all results for “{q}”" with a translated string. The count uses the sum of `estimatedTotalHits` across the queries ("See all N results…") when every query returned one.
  - The footer is a listbox option with an id. Selecting it (click, or Enter while it is active) submits the original form, which is the same as Enter with no active option.
  - It renders only when at least one hit exists.
- **CSS:** minimal, with `currentColor` and opacity for the subtitle, consistent with the existing styles.
- **Docs:** the readme FAQ and the plugin spec § 10 and § 14 get the new filter and the footer.
- **Tests:**
  - Unit: the filter's value reaches the config, and an invalid name is ignored.
  - E2E: the subtitle renders when the filter is set; the footer renders, is reachable with arrow keys and submits the form; axe stays clean.
- **Release:** plugin 1.0.x or 1.1. The demo pins the plugin path or commit, so it does not wait for wordpress.org.

## 9. Testing and CI (demo repo)

- **pytest** (`scripts/tests/`):
  - the third-party image filter;
  - topic mapping and quotas;
  - HTML sanitising;
  - Met medium bucketing, price, sale and stock determinism;
  - variation size and orientation.
  All tests use recorded fixtures, with no network.
- **Playwright** (`tests/e2e/`, Chromium), against `docker compose up --wait`.
  - Blog:
    - every Try chip returns results;
    - `saturn rngs` returns a highlighted Cassini result;
    - the Under the hood panel shows `blog_content` and the `q`;
    - `?engine=mysql` shows "Served by MySQL";
    - autocomplete shows a subtitle and the footer, and Enter on the footer lands on the results page;
    - the category link narrows results and the panel shows `tax_category_ids`.
  - Shop:
    - a typo search returns products;
    - the attribute filter, `max_price` and `orderby=price` show up in the panel's filter and sort;
    - the product page shows the size and finish selectors and updates the price;
    - checkout shows the demo notice and creates no order.
  - axe: no serious or critical violations on home, search and the product page.
- **GitHub Actions:**
  - `scripts` job: pytest.
  - `e2e` job: checks out this repo and `meilisearch/meilisearch-wordpress` (the ref from `PLUGIN_REF`, default `main`) side by side, then runs `docker compose build` and `up --wait` and Playwright. Git LFS is pulled.
  - `hadolint` and `shellcheck` on `docker/` and the Dockerfiles.

## 10. Build order (input for the plan)

1. Repo skeleton, Compose, Dockerfiles and an entrypoint with an empty site; both sites reach Ready.
2. Data scripts with tests; commit the data snapshots (LFS).
3. Blog import, then the Mission Log theme.
4. Shop import (variable products and reviews), then the Met Prints theme.
5. Demo layer: chips, Under the hood, MySQL comparison, facet counts, attribution, hardening.
6. Plugin PR: subtitle filter and footer. Then wire up the demo's subtitles.
7. Playwright, CI and README.
8. Fly apps, Meilisearch Cloud project and first deploy.

## 11. Risks and open items

- **WooCommerce filter blocks.** It's unverified whether the URLs from the current Product Filters blocks always map to the query vars the plugin translates (§ 5.2). Verify early, in step 4.
- **Image licensing.** The NASA third-party filter is heuristic. Before the first deploy, a manual pass over a contact sheet of all kept images (`scripts/contact_sheet.py` → HTML) is required.
- **Repo size.** Git LFS holds about 300–400 MB of images. GitHub LFS free quota is 10 GiB of storage and bandwidth per month, so CI pulls count against it. CI caches LFS objects by manifest hash.
- **Fly sizing.** MariaDB and Apache under 2 GB are expected to fit. If the import runs out of memory, run it with `WP_MEMORY_LIMIT=1024M` in the one-off first boot only.
- **The populated gate.** The plugin does not intercept until an index is marked populated by a completed reindex (plugin spec § I5). The entrypoint's synchronous `wp meilisearch reindex` must complete before `[demo] Ready`. The E2E suite therefore runs only after `up --wait` and checks the panel never says "Served by MySQL" on a plain search.
