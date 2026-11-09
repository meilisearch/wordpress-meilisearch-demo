#!/usr/bin/env bash
# Checks the Fly image's Apache: it serves DEMO_ROOT (/data/html), not the base image's /var/www/html, and it
# refuses the login page and wp-admin (docker/apache-lockdown.conf) while admin-ajax.php stays reachable.
# Usage: bin/check-fly-image.sh [image]   (default meili-wp-blog:fly, built with --target fly)
set -euo pipefail
image="${1:-meili-wp-blog:fly}"
name="fly-docroot-check-$$"
docker run -d --rm --name "$name" -p 127.0.0.1::80 --entrypoint sh "$image" \
	-c 'mkdir -p "$DEMO_ROOT/wp-admin" && echo docroot-ok > "$DEMO_ROOT/ping.txt" \
		&& for f in wp-login.php xmlrpc.php wp-admin/index.php wp-admin/admin-ajax.php; do echo ok > "$DEMO_ROOT/$f"; done \
		&& exec apache2-foreground' >/dev/null
trap 'docker rm -f "$name" >/dev/null 2>&1 || true' EXIT
port="$(docker port "$name" 80/tcp | head -1 | cut -d: -f2)"
fail() { echo "FAIL: $*" >&2; exit 1; }
ok=0
for _ in $(seq 1 20); do
	if body="$(curl -fsS "http://127.0.0.1:$port/ping.txt" 2>/dev/null)"; then ok=1; break; fi
	sleep 0.5
done
[ "$ok" = 1 ] && [ "$body" = docroot-ok ] || fail "Apache in $image does not serve DEMO_ROOT"
echo "fly image ok: Apache serves DEMO_ROOT"
code() { curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:$port$1"; }
for path in /wp-login.php /WP-LOGIN.PHP //wp-login.php /xmlrpc.php /wp-admin /wp-admin/ /wp-admin/index.php "/wp-admin/admin-ajax.php/../index.php"; do
	c="$(code "$path")"
	[ "$c" = 403 ] || [ "$c" = 404 ] || fail "$path answered $c, expected 403 or 404"
done
[ "$(code /wp-admin/admin-ajax.php)" = 200 ] || fail "/wp-admin/admin-ajax.php is blocked"
echo "fly image ok: login page and wp-admin refused, admin-ajax.php served"
