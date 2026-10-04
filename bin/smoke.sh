#!/usr/bin/env bash
# Smoke test for the running stack: both sites Ready, plugin connected, restart is idempotent.
# Usage: bin/smoke.sh [--restart]
set -euo pipefail
cd "$(dirname "$0")/.."
blog="${BLOG_URL:-http://localhost:${BLOG_PORT:-8081}}"
shop="${SHOP_URL:-http://localhost:${SHOP_PORT:-8082}}"
fail() { echo "SMOKE FAIL: $*" >&2; exit 1; }

for svc in blog shop; do
	logs="$(docker compose logs "$svc")" # captured first: grep -q would SIGPIPE the writer under pipefail
	grep -q '\[demo\] Ready' <<<"$logs" || fail "$svc never logged Ready"
	docker compose exec -T "$svc" wp --allow-root meilisearch status >/dev/null || fail "$svc: wp meilisearch status failed"
	key="$(docker compose exec -T "$svc" wp --allow-root option pluck meilisearch_connection search_key)"
	[ -n "$key" ] || fail "$svc: no browser search key (wp meilisearch connect did not run?)"
done
curl -fsS -o /dev/null "$blog/wp-login.php" || fail "blog not serving"
curl -fsS -o /dev/null "$shop/wp-login.php" || fail "shop not serving"

if [ "${1:-}" = "--restart" ]; then
	before="$(docker compose exec -T blog wp --allow-root post list --post_type=post --format=count)"
	# Only what the restarted site logs counts: `up --wait` may recreate the container, and a new container's
	# log starts empty (counting "First boot" lines across containers is meaningless).
	since="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	docker compose restart blog
	docker compose up --wait blog
	after="$(docker compose exec -T blog wp --allow-root post list --post_type=post --format=count)"
	[ "$before" = "$after" ] || fail "restart changed the post count ($before -> $after)"
	logs="$(docker compose logs --since "$since" blog)"
	grep -q '\[demo\] Ready' <<<"$logs" || fail "blog did not log Ready after the restart"
	if grep -q '\[demo\] First boot' <<<"$logs"; then fail "first boot ran again after the restart"; fi
fi
echo "smoke ok"
