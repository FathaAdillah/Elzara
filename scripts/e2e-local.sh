#!/usr/bin/env bash
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
if ! command -v node >/dev/null && [[ -s "$HOME/.nvm/nvm.sh" ]]; then
  source "$HOME/.nvm/nvm.sh"
  nvm use 22
fi
cd "$ROOT"
mkdir -p e2e/logs
STATE=$(mktemp -d "$ROOT/e2e/logs/run.XXXXXX")
pids=() containers=()
cp apps/web/next-env.d.ts "$STATE/next-env.d.ts"
cp apps/web/tsconfig.json "$STATE/tsconfig.json"
had_env=0
if [[ -f apps/api/.env ]]; then mv apps/api/.env "$STATE/api.env"; had_env=1; fi
touch apps/api/.env
cleanup() {
  for pid in "${pids[@]}"; do kill -- "-$pid" 2>/dev/null || true; done
  for container in "${containers[@]}"; do docker rm -f "$container" >/dev/null 2>&1 || true; done
  cp "$STATE/next-env.d.ts" apps/web/next-env.d.ts
  cp "$STATE/tsconfig.json" apps/web/tsconfig.json
  rm -f apps/api/.env
  if [[ "$had_env" == 1 ]]; then mv "$STATE/api.env" apps/api/.env; fi
}
trap cleanup EXIT
trap 'exit 130' INT TERM

export APP_ENV=local APP_DEBUG=false APP_URL=http://127.0.0.1:3080
export APP_KEY="base64:$(openssl rand -base64 32)"
export APP_CONFIG_CACHE="$STATE/config.php" APP_MAINTENANCE_DRIVER=file
export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_DATABASE=elzara_e2e DB_USERNAME=elzara DB_PASSWORD=elzara DB_URL=
export REDIS_HOST=127.0.0.1 REDIS_URL= REDIS_USERNAME= REDIS_PASSWORD=
export REDIS_PREFIX=e2e_ CACHE_STORE=redis SESSION_DRIVER=redis SESSION_SECURE_COOKIE=false SESSION_DOMAIN=
export QUEUE_CONNECTION=sync MAIL_MAILER=array LOG_CHANNEL=stderr SHIPPING_DRIVER=manual ADMIN_2FA_ENABLED=false
export DEMO_PASSWORD="${DEMO_PASSWORD:-E2e-demo-password!42}"
export ELZARA_STOREFRONT_PROXY_KEY="$(openssl rand -hex 32)"
export API_INTERNAL_URL=http://127.0.0.1:8081 NEXT_PUBLIC_API_BASE_URL=/api/v1
export E2E_BASE_URL=http://127.0.0.1:3080 NEXT_TELEMETRY_DISABLED=1

if [[ "${E2E_EXTERNAL_SERVICES:-0}" != 1 ]]; then
  pg=$(docker run -d --rm -p 127.0.0.1::5432 -e POSTGRES_DB="$DB_DATABASE" -e POSTGRES_USER="$DB_USERNAME" -e POSTGRES_PASSWORD="$DB_PASSWORD" postgres:16-alpine)
  containers+=("$pg")
  redis=$(docker run -d --rm -p 127.0.0.1::6379 redis:7-alpine)
  containers+=("$redis")
  export DB_PORT="$(docker port "$pg" 5432/tcp | cut -d: -f2)"
  export REDIS_PORT="$(docker port "$redis" 6379/tcp | cut -d: -f2)"
  timeout 60 sh -c 'until docker exec "$1" pg_isready -h 127.0.0.1 -U elzara -d elzara_e2e; do sleep 1; done' sh "$pg"
  timeout 30 sh -c 'until docker exec "$1" redis-cli ping; do sleep 1; done' sh "$redis"
fi

composer install --working-dir=apps/api --no-interaction --prefer-dist >"$STATE/composer.log" 2>&1
(cd apps/api && php artisan migrate:fresh --force && php artisan db:seed --class=E2eSeeder --force) >"$STATE/seed.log" 2>&1
npm ci --prefix apps/web >"$STATE/web-install.log" 2>&1
npm ci --prefix e2e >"$STATE/e2e-install.log" 2>&1
(cd e2e && npx playwright install chromium) >"$STATE/browser-install.log" 2>&1
(cd apps/web && npm run build) >"$STATE/build.log" 2>&1
cp -R apps/web/.next/static apps/web/.next/standalone/.next/
cp -R apps/web/public apps/web/.next/standalone/
setsid php apps/api/artisan serve --host=127.0.0.1 --port=8081 >"$STATE/api.log" 2>&1 & pids+=("$!")
HOSTNAME=127.0.0.1 PORT=3000 setsid node apps/web/.next/standalone/server.js >"$STATE/web.log" 2>&1 & pids+=("$!")
setsid node e2e/proxy.mjs >"$STATE/proxy.log" 2>&1 & pids+=("$!")
ready() {
  for attempt in $(seq 1 60); do
    if curl --fail --silent "$1" >/dev/null; then return; fi
    sleep 1
  done
  echo "Readiness failed: $1; logs: $STATE" >&2
  return 1
}
ready http://127.0.0.1:8081/up
ready "$E2E_BASE_URL"
echo "Stack ready; logs: $STATE"
(cd e2e && npm test -- "$@")
