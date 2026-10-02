#!/usr/bin/env bash
# Fly entrypoint: one machine runs MariaDB and the site under supervisord, data on the /data volume.
# Meilisearch is a separate Fly app; the environment (MEILISEARCH_HOST, keys, ...) comes from fly.toml / secrets.
set -euo pipefail
mkdir -p /data/mysql /data/html /run/mysqld
chown -R mysql:mysql /data/mysql /run/mysqld
if [ ! -d /data/mysql/mysql ]; then
	mariadb-install-db --user=mysql --datadir=/data/mysql >/dev/null
	mariadbd --user=mysql --datadir=/data/mysql --skip-networking --socket=/run/mysqld/init.sock &
	pid=$!
	for _ in $(seq 1 30); do mariadb-admin --socket=/run/mysqld/init.sock ping --silent 2>/dev/null && break; sleep 1; done
	mariadb --socket=/run/mysqld/init.sock <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASSWORD}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
SQL
	mariadb-admin --socket=/run/mysqld/init.sock shutdown
	wait "$pid" || true
fi
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
