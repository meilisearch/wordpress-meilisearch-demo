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
# Everything the official image entrypoint would do (copy core, write wp-config.php) is done above.
exec "$@"
