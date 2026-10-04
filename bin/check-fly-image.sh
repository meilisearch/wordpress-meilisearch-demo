#!/usr/bin/env bash
# Checks that the Fly image's Apache serves DEMO_ROOT (/data/html), not the base image's /var/www/html.
# Usage: bin/check-fly-image.sh [image]   (default meili-wp-blog:fly, built with --target fly)
set -euo pipefail
image="${1:-meili-wp-blog:fly}"
name="fly-docroot-check-$$"
docker run -d --rm --name "$name" -p 127.0.0.1::80 --entrypoint sh "$image" \
	-c 'mkdir -p "$DEMO_ROOT" && echo docroot-ok > "$DEMO_ROOT/ping.txt" && exec apache2-foreground' >/dev/null
trap 'docker rm -f "$name" >/dev/null 2>&1 || true' EXIT
port="$(docker port "$name" 80/tcp | head -1 | cut -d: -f2)"
for _ in $(seq 1 20); do
	if body="$(curl -fsS "http://127.0.0.1:$port/ping.txt" 2>/dev/null)"; then
		[ "$body" = docroot-ok ] && { echo "fly image ok: Apache serves DEMO_ROOT"; exit 0; }
	fi
	sleep 0.5
done
echo "FAIL: Apache in $image does not serve DEMO_ROOT" >&2
exit 1
