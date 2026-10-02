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
