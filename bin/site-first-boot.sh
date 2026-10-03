#!/usr/bin/env bash
# First boot only: install WordPress, activate theme and plugins, configure, import, connect, reindex.
# Not idempotent by itself; site-start.sh writes the marker only when this exits 0.
set -euo pipefail
root="${DEMO_ROOT:-/var/www/html}"
site_dir="${DEMO_SITE_DIR:-/opt/demo/site}"
wp() { if [ "$(id -u)" = 0 ]; then command wp --path="$root" --allow-root "$@"; else command wp --path="$root" "$@"; fi; }
log() { echo "[demo] $*"; }

title="Mission Log"; [ "$SITE" = shop ] && title="Met Prints"
if ! wp core is-installed 2>/dev/null; then
	wp core install --url="$WP_HOME" --title="$title" --admin_user=admin \
		--admin_password="${WP_ADMIN_PASSWORD:-admin}" --admin_email=demo@example.com --skip-email
fi
wp rewrite structure '/%postname%/' --hard
theme_slug="$(cat "$site_dir/theme/slug.txt")"
wp theme activate "$theme_slug"
[ "$SITE" = shop ] && wp plugin activate woocommerce
wp plugin activate meilisearch
wp option patch update meilisearch_connection prefix "$MEILISEARCH_INDEX_PREFIX"

log "Importing content"
DEMO_PHASE=prepare wp eval-file "$site_dir/setup.php"
# The import is sharded across parallel WP-CLI processes (image resizing dominates); each skips items already
# imported, so a retried first boot resumes. With set -e, a failed shard fails the first boot (no marker).
shards="${DEMO_IMPORT_PROCESSES:-4}"
pids=()
for i in $(seq 0 $((shards - 1))); do
	DEMO_PHASE=import DEMO_SHARD="$i/$shards" wp eval-file "$site_dir/setup.php" &
	pids+=("$!")
done
for pid in "${pids[@]}"; do
	wait "$pid"
done

[ "$(id -u)" = 0 ] && chown -R "${DEMO_WEB_USER:-www-data}:${DEMO_WEB_USER:-www-data}" "$root/wp-content/uploads"
# Connecting and indexing happen on every start (demo-entrypoint.sh), so a Meilisearch problem never blocks
# the import, and a later start retries the indexing alone.
