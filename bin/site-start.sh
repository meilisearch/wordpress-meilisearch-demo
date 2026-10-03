#!/usr/bin/env bash
# Prepares one demo site and leaves it ready to serve: WordPress files and the demo symlinks, wp-config.php,
# the database wait, the first-boot install and import, then on every start the plugin settings, the
# Meilisearch connection and a reindex when an index is not populated. Meilisearch problems only log
# warnings: the plugin serves search from MySQL until they are fixed.
#
# Used by the Docker entrypoint (docker/demo-entrypoint.sh) and by bare-metal hosts (qdq-server). Paths default
# to the container layout; a host sets them to its own:
#   DEMO_ROOT        WordPress install directory              (/var/www/html)
#   WP_CORE_SRC      pristine WordPress core to copy from      (/usr/src/wordpress)
#   DEMO_SITE_DIR    this site's directory of the demo repo    (/opt/demo/site)
#   DEMO_DATA_DIR    this site's data snapshot                 (/opt/demo/data)
#   DEMO_MU_DIR      shared/mu-plugins of the demo repo        (/opt/demo/mu-plugins)
#   DEMO_PLUGINS_DIR built meilisearch/ (and woocommerce/)     (/opt/plugins)
#   DEMO_WEB_USER    owner of uploads when run as root         (www-data)
# Optional:
#   MEILISEARCH_PUBLIC_HOST  Meilisearch URL for browsers, when PHP uses a private one (MEILISEARCH_HOST)
#   MEILISEARCH_SEARCH_KEY   a search-only key, for an admin key that cannot create keys
set -euo pipefail

: "${SITE:?}" "${WP_HOME:?}" "${DB_HOST:?}" "${DB_NAME:?}" "${DB_USER:?}" "${DB_PASSWORD:?}"
: "${MEILISEARCH_HOST:?}" "${MEILISEARCH_ADMIN_KEY:?}" "${MEILISEARCH_INDEX_PREFIX:?}"
root="${DEMO_ROOT:-/var/www/html}"
core_src="${WP_CORE_SRC:-/usr/src/wordpress}"
site_dir="${DEMO_SITE_DIR:-/opt/demo/site}"
data_dir="${DEMO_DATA_DIR:-/opt/demo/data}"
mu_dir="${DEMO_MU_DIR:-/opt/demo/mu-plugins}"
plugins_dir="${DEMO_PLUGINS_DIR:-/opt/plugins}"
web_user="${DEMO_WEB_USER:-www-data}"
bin_dir="$(cd "$(dirname "$0")" && pwd)"
export DEMO_ROOT="$root" DEMO_SITE_DIR="$site_dir" DEMO_DATA_DIR="$data_dir"
as_root=0
[ "$(id -u)" = 0 ] && as_root=1
wp() { if [ "$as_root" = 1 ]; then command wp --path="$root" --allow-root "$@"; else command wp --path="$root" "$@"; fi; }
log() { echo "[demo] $*"; }
# PHP string literal for wp-config.php (PHP quotes it, so no shell escaping rules apply).
# shellcheck disable=SC2016 # $argv is PHP, not shell.
php_str() { php -r 'echo var_export( $argv[1], true );' -- "$1"; }

mkdir -p "$root"
if [ ! -f "$root/wp-includes/version.php" ]; then
	log "Copying WordPress core into $root"
	cp -a "$core_src/." "$root/"
fi
# Newer core from a rebuilt image replaces the files in the volume (wp-content is kept).
if ! cmp -s "$core_src/wp-includes/version.php" "$root/wp-includes/version.php"; then
	log "Updating WordPress core files"
	tar -C "$core_src" --exclude=./wp-content -cf - . | tar -C "$root" -xf -
fi

mkdir -p "$root/wp-content/plugins" "$root/wp-content/themes" "$root/wp-content/uploads"
ln -sfn "$plugins_dir/meilisearch" "$root/wp-content/plugins/meilisearch"
[ -d "$plugins_dir/woocommerce" ] && ln -sfn "$plugins_dir/woocommerce" "$root/wp-content/plugins/woocommerce"
theme_slug="$(cat "$site_dir/theme/slug.txt" 2>/dev/null || true)"
[ -n "$theme_slug" ] && ln -sfn "$site_dir/theme" "$root/wp-content/themes/$theme_slug"
ln -sfn "$mu_dir" "$root/wp-content/mu-plugins"
[ "$as_root" = 1 ] && chown -R "$web_user:$web_user" "$root/wp-content/uploads"

wp config create --force --skip-check \
	--dbhost="$DB_HOST" --dbname="$DB_NAME" --dbuser="$DB_USER" --dbpass="$DB_PASSWORD" \
	--extra-php <<PHP
define( 'WP_HOME', $(php_str "$WP_HOME") );
define( 'WP_SITEURL', $(php_str "$WP_HOME") );
define( 'MEILISEARCH_HOST', $(php_str "$MEILISEARCH_HOST") );
define( 'MEILISEARCH_ADMIN_KEY', $(php_str "$MEILISEARCH_ADMIN_KEY") );
define( 'MEILI_DEMO_SITE', $(php_str "$SITE") );
define( 'MEILI_DEMO_SITE_DIR', $(php_str "$site_dir") );
define( 'MEILI_DEMO_INDEX_PREFIX', $(php_str "$MEILISEARCH_INDEX_PREFIX") );
define( 'MEILI_DEMO_PUBLIC_HOST', $(php_str "${MEILISEARCH_PUBLIC_HOST:-}") );
define( 'DISALLOW_FILE_EDIT', true );
define( 'DISALLOW_FILE_MODS', true );
define( 'AUTOMATIC_UPDATER_DISABLED', true );
define( 'WP_ENVIRONMENT_TYPE', $(php_str "${WP_ENVIRONMENT_TYPE:-production}") );
define( 'WP_DEBUG', false );
if ( isset( \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === \$_SERVER['HTTP_X_FORWARDED_PROTO'] ) { \$_SERVER['HTTPS'] = 'on'; }
PHP
# It holds the Meilisearch admin key: readable by its owner and the web server's group only.
chmod 0640 "$root/wp-config.php"
[ "$as_root" = 1 ] && chgrp "$web_user" "$root/wp-config.php"

for i in $(seq 1 60); do
	if mariadb-admin ping -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASSWORD" --silent 2>/dev/null; then break; fi
	[ "$i" = 60 ] && { log "Database unreachable"; exit 1; }
	sleep 2
done

marker="$root/wp-content/uploads/.demo-installed"
if [ ! -f "$marker" ]; then
	log "First boot"
	"$bin_dir/site-first-boot.sh"
	touch "$marker"
fi

# Every start: admin password, plugin settings, connection, and a reindex when an index is not populated.
# Meilisearch problems only log warnings: the plugin serves search from MySQL until they are fixed.
wp user update admin --user_pass="${WP_ADMIN_PASSWORD:-admin}" --skip-email >/dev/null
if [ "$(wp option pluck meilisearch_connection prefix 2>/dev/null || true)" != "$MEILISEARCH_INDEX_PREFIX" ]; then
	wp option patch update meilisearch_connection prefix "$MEILISEARCH_INDEX_PREFIX" >/dev/null
fi
for setting in replace highlight autocomplete; do
	wp option patch update meilisearch_search "$setting" true --format=json >/dev/null
done

# A search-only key provided by the host (its admin key cannot create keys): connect verifies it.
if [ -n "${MEILISEARCH_SEARCH_KEY:-}" ]; then
	wp option patch update meilisearch_connection search_key "$MEILISEARCH_SEARCH_KEY" >/dev/null
	wp option patch update meilisearch_state search_key_manual true --format=json >/dev/null
fi

# connect is idempotent: it checks the version, applies index settings, keeps or creates the browser search
# key, and (on a host, key or prefix change) marks the indexes unpopulated.
if connect_output="$(wp meilisearch connect 2>&1)"; then
	if printf '%s' "$connect_output" | grep -qE "managed manually and (none is set|is not a search-only key)"; then
		log "WARNING: no usable browser search key, so autocomplete is OFF. Use a key with the keys.* actions, or save a search-only key (see the README)."
	fi
else
	log "Warning: wp meilisearch connect failed; search falls back to MySQL until it succeeds: $(printf '%s' "$connect_output" | tail -1)"
fi

expected="content"
[ "$SITE" = shop ] && expected="content products"
populated="$(wp option pluck meilisearch_state populated --format=json 2>/dev/null || echo '[]')"
for logical in $expected; do
	if ! printf '%s' "$populated" | grep -q "\"$logical\""; then
		log "Reindexing"
		wp meilisearch reindex || log "Warning: wp meilisearch reindex failed; search falls back to MySQL until a reindex succeeds"
		break
	fi
done
# Warm Meilisearch up before visitors arrive: right after a (re)start its memory-mapped indexes are cold, and a
# first highlighted search can exceed the plugin's 2 s search timeout, which opens its circuit breaker for a
# minute (MySQL serves search meanwhile).
for logical in $expected; do
	curl -fsS -o /dev/null --max-time 30 -H "Authorization: Bearer $MEILISEARCH_ADMIN_KEY" -H 'Content-Type: application/json' \
		"$MEILISEARCH_HOST/indexes/${MEILISEARCH_INDEX_PREFIX}_$logical/search" \
		-d '{"q":"a","attributesToCrop":["content:30"],"attributesToHighlight":["title","content"]}' || true
done
if ! wp meilisearch check >/dev/null 2>&1; then
	log "Warning: wp meilisearch check reports a problem; search falls back to MySQL until it is fixed"
fi
log "Ready"
