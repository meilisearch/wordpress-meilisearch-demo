#!/usr/bin/env bash
# Usage: bin/fly-deploy.sh blog|shop  — stages the plugin source (HEAD of MEILISEARCH_PLUGIN_PATH) and deploys.
set -euo pipefail
site="${1:?blog or shop}"
cd "$(dirname "$0")/.."
# shellcheck disable=SC1091
[ -f .env ] && set -a && . ./.env && set +a
plugin="${MEILISEARCH_PLUGIN_PATH:-../../_sdk/meilisearch-wordpress}"
rm -rf .plugin && mkdir .plugin
# Tracked files of the checkout (not `git archive`: the plugin's .gitattributes export-ignores the build files
# — composer.json, package.json — that the image needs).
git -C "$plugin" ls-files -z | (cd "$plugin" && tar --null -T - -cf -) | tar -x -C .plugin
echo "Plugin $(git -C "$plugin" rev-parse --short HEAD) staged in .plugin/ (working tree of tracked files)"
fly deploy --config "$site/fly.toml" --dockerfile Dockerfile --remote-only
