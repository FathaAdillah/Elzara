#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
cd "$ROOT"
mkdir -p e2e/logs
STATE=$(mktemp -d "$ROOT/e2e/logs/run.XXXXXX")
export E2E_SOURCE="$ROOT"
export COMPOSE_PROJECT_NAME="elzara-e2e-$(basename "$STATE" | tr '[:upper:].' '[:lower:]-')"
export E2E_PLAYWRIGHT_VERSION
E2E_PLAYWRIGHT_VERSION=$(docker run --rm -v "$ROOT/e2e:/source:ro" node:22 \
  node -p 'require("/source/package-lock.json").packages["node_modules/@playwright/test"].version')
compose=(docker compose -f "$ROOT/e2e/compose.yaml")
echo "E2E logs: $STATE"
cleanup() {
  status=$?
  trap - EXIT
  "${compose[@]}" logs --no-color >"$STATE/services.log" 2>&1 || true
  if [[ -n "${workspace:-}" ]]; then
    for artifact in playwright-report test-results; do
      if docker cp "$workspace:/workspace/e2e/$artifact" "$STATE/" 2>/dev/null; then
        rm -rf "e2e/$artifact"
        cp -R "$STATE/$artifact" "e2e/$artifact"
      fi
    done
  fi
  "${compose[@]}" down --volumes --remove-orphans >"$STATE/cleanup.log" 2>&1 || true
  if [[ "$status" != 0 ]]; then echo "E2E failed; logs: $STATE" >&2; fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

"${compose[@]}" run --rm prepare >"$STATE/prepare.log" 2>&1
"${compose[@]}" create node >>"$STATE/prepare.log" 2>&1
workspace=$("${compose[@]}" ps -aq node)
if [[ "${E2E_REUSE_API_IMAGE:-0}" == 1 ]]; then
  docker image inspect elzara/e2e-php:local >"$STATE/php-build.log" 2>&1
else
  "${compose[@]}" build api >"$STATE/php-build.log" 2>&1
fi
"${compose[@]}" up -d --wait postgres redis >"$STATE/services-start.log" 2>&1
"${compose[@]}" run --rm api install >"$STATE/composer-seed.log" 2>&1
"${compose[@]}" run --rm node build >"$STATE/web-build.log" 2>&1
"${compose[@]}" up -d api web proxy >>"$STATE/services-start.log" 2>&1
"${compose[@]}" run --rm node ready
"${compose[@]}" run --rm browser test "$@"
"${compose[@]}" run --rm api verify-order
