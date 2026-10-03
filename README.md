# Meilisearch for WordPress: demos

Two sites that show what the [Meilisearch for WordPress](https://github.com/meilisearch/meilisearch-wordpress) plugin gives a site, out of the box:

- **Mission Log**: a space magazine of 743 NASA science articles on plain WordPress.
- **Met Prints**: a WooCommerce shop that sells prints of 1,500 public-domain artworks from The Met.

Both use their theme's own search page. The plugin replaces WordPress's MySQL search with Meilisearch, so search tolerates typos and filters, sorting and WooCommerce's filter blocks keep working. Its autocomplete adds suggestions as you type.

## Quick start

```bash
cp .env.example .env    # set MEILISEARCH_PLUGIN_PATH if the plugin checkout is elsewhere
docker compose watch
```

The first start installs WordPress, imports the content and indexes it. That takes about 5 minutes for the blog and about 10 for the shop; the logs end with `[demo] Ready`. Then open:

- <http://blog.wordpress-meilisearch-demo.orb.local>
- <http://shop.wordpress-meilisearch-demo.orb.local>

These OrbStack names are the recommended URLs. Without OrbStack, use <http://localhost:8081> and <http://localhost:8082>, and set `MEILISEARCH_HOST` to an address your browser can reach (for example `http://localhost:7701`). The plugin hands the same host to the browser for autocomplete.

Log in with `admin` / `admin` (`WP_ADMIN_PASSWORD`).

## A two-minute demo

**Mission Log**

1. **Type** in the header search. Suggestions appear with topic, date and thumbnail. The last row, *See all N results*, opens the search page.
2. **Click the *Try* chips** on the results page:

   | Chip | What it shows |
   |---|---|
   | `saturn rngs`, `jupitr moons`, `astronot` | Typo tolerance |
   | `"heat shield"` | Phrase search |
   | `moon -apollo` | Leaving a word out |

3. **Read *Under the hood*.** It shows the exact request the plugin sent to Meilisearch for this page, and the `WP_Query` vars it came from.
4. **Pick a topic or a year** on the left. Both are ordinary WordPress query vars, and the panel shows the filter they became.
5. **Compare with MySQL.** The same search runs on WordPress's own `LIKE` query. Typos find nothing there.
6. **Edit an article** as admin, then search for its new title. It is indexed on save.

**Met Prints**

1. **Type** `van go` in the header. You get prints with artist, date, thumbnail and price.
2. **Try** `rembrant` and `renior` (typos), `degas dancer`, `"still life"` or `landscape -river`.
3. **Filter** with WooCommerce's own blocks (Department, Century, Medium, Price, Rating) and **sort** by price. *Under the hood* shows each one as a Meilisearch filter or sort.
4. **Open a print.** Every print is a variable product with four sizes and three finishes, and some are on sale or out of stock.
5. **Checkout is closed**: this is a demo store.

## How it is built

| Part | Where |
|---|---|
| Stack: Meilisearch, MariaDB, both sites | `compose.yaml`, `Dockerfile.dev` (local), `Dockerfile` (Fly) |
| The plugin | Built from `MEILISEARCH_PLUGIN_PATH` the same way the plugin's own `Dockerfile.dev` does (Composer and asset build). It doesn't use `bin/build-zip.sh`, because a git worktree's `.git` file points outside the build context. |
| First start: install, import, `wp meilisearch connect`, `wp meilisearch reindex` | `docker/demo-entrypoint.sh`, `docker/first-boot.sh` |
| Content import | `blog/setup.php`, `shop/setup.php` |
| Themes (block themes) | `blog/theme` (Mission Log), `shop/theme` (Met Prints) |
| Demo-only layer: *Try* chips, *Under the hood*, *Compare with MySQL*, topic and year counts, public hardening | `shared/mu-plugins/meili-demo` |
| Data snapshots | `scripts/nasa.py`, `scripts/met.py` produce `data/` |

The demo layer only uses the plugin's public hooks. *Under the hood* records the request through WordPress's `http_api_debug` action, so it shows the request as sent, not a reconstruction. The blog's topic and year counts come from one extra request the demo makes itself, because the plugin has no facets in v1.

## Working on the plugin

`MEILISEARCH_PLUGIN_PATH` points at a checkout of `meilisearch/meilisearch-wordpress`. `docker compose watch` syncs its `src/` and `assets/` into both sites. A change to `composer.json` rebuilds the images.

Run the tests:

```bash
npm ci && npx playwright install chromium
npm run test:e2e                                   # both sites, against the running stack
docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/php/run.php
(cd scripts && python3 -m venv .venv && .venv/bin/pip install -r requirements.txt && .venv/bin/pytest -q)
bin/smoke.sh --restart
```

## Data and licences

- **Articles:** [science.nasa.gov](https://science.nasa.gov) through its WordPress REST API. US government works are in the public domain. The demo is not endorsed by NASA and uses no NASA logos. Each article links back to its source. Images credited to third parties are dropped, using `scripts/nasa_third_party.txt`; those articles get a NASA-made fallback image. `scripts/contact_sheet.py` writes a sheet of every kept image for a manual review.
- **Artworks:** metadata from The Met's [Open Access CSV](https://github.com/metmuseum/openaccess). Images come from Wikimedia Commons, found through Wikidata's "Met object ID" (P3634): mostly the Met's own CC0 uploads, otherwise public-domain photographs of these public-domain 2D works. Review them with the other images before a public deploy. The Met Collection API is not used, because its bot protection blocks bulk fetching. Prices, variants, sales, stock and reviews are invented and deterministic.
- **Fonts:** Space Grotesk, Inter, JetBrains Mono and Cormorant Garamond, under the SIL Open Font License (`*/theme/assets/fonts/OFL-*.txt`).

Rebuild a snapshot:

```bash
cd scripts
.venv/bin/python nasa.py              # replays data/blog/manifest.json (same posts, refreshed decisions)
.venv/bin/python nasa.py --refresh    # selects posts again
.venv/bin/python met.py [--refresh]
```

Images are stored with Git LFS.

## Deploy (Fly.io)

Each site is one Fly app: WordPress and MariaDB on one machine, with a volume for the database and uploads. Search runs on [Meilisearch Cloud](https://www.meilisearch.com/cloud).

1. Create a Meilisearch Cloud project. Copy its URL and a key that can also manage API keys (the project's **master key**, or a key with the `keys.create`, `keys.get` and `keys.delete` actions). The plugin uses it to create its own search-only key for browsers; with the Default Admin API Key, which cannot manage keys, autocomplete stays off, and the logs say so.
2. Create the apps and their volumes:

   ```bash
   fly apps create meili-wp-blog && fly apps create meili-wp-shop
   fly volumes create data --size 3 --region cdg -a meili-wp-blog
   fly volumes create data --size 3 --region cdg -a meili-wp-shop
   ```

3. Set the secrets, once per app:

   ```bash
   fly secrets set -a meili-wp-blog MEILISEARCH_HOST=https://… MEILISEARCH_ADMIN_KEY=… \
     WP_ADMIN_PASSWORD=… DB_PASSWORD=… WP_HOME=https://meili-wp-blog.fly.dev
   ```

4. Deploy: `bin/fly-deploy.sh blog` and `bin/fly-deploy.sh shop`. The script stages the plugin source from `MEILISEARCH_PLUGIN_PATH` into `.plugin/`, because Fly's builder has no named build contexts.
5. Watch `fly logs -a meili-wp-blog` until `[demo] Ready`. The first boot imports and indexes everything.

To reset a site, destroy its volume, create a new one and deploy again.

## Reset (local)

```bash
docker compose down -v
```

The next start installs everything again.

## Troubleshooting

- **No suggestions while typing:** the browser can't reach `MEILISEARCH_HOST`. Use the OrbStack URLs, or point `MEILISEARCH_HOST` at a host-reachable address.
- **"Served by MySQL" on every search:** run `docker compose exec blog wp --allow-root meilisearch check`. The usual cause is that the first reindex hasn't finished.
- **The first start failed:** read `docker compose logs blog` (or `shop`), fix the cause, then `docker compose down -v && docker compose watch`.
