#!/bin/sh
set -eu
cd /workspace
. ./e2e/runtime.sh
mode=$1
shift
case "$mode" in
  install)
    cd apps/api
    composer install --no-interaction --prefer-dist
    php /workspace/e2e/migrate.php
    php artisan db:seed --class=E2eSeeder --force
    ;;
  build)
    npm ci --prefix apps/web
    npm ci --prefix e2e
    cd apps/web
    npm run build
    cp -R .next/static .next/standalone/.next/
    cp -R public .next/standalone/
    ;;
  api) exec php apps/api/artisan serve --no-reload --host=0.0.0.0 --port=8081 ;;
  web) HOSTNAME=0.0.0.0 PORT=3000 exec node apps/web/.next/standalone/server.js ;;
  proxy) exec node e2e/proxy.mjs ;;
  ready)
    node --input-type=module <<'JS'
for (const url of ['http://api:8081/up', 'http://proxy:3080']) {
  let ready = false;
  for (let attempt = 0; attempt < 60; attempt++) {
    try {
      if ((await fetch(url, { signal: AbortSignal.timeout(2000) })).ok) { ready = true; break; }
    } catch {}
    await new Promise(resolve => setTimeout(resolve, 1000));
  }
  if (!ready) throw new Error(`Readiness failed: ${url}`);
}
console.log('Container stack ready');
for (const path of ['/api/v1/products', '/api/v1/categories']) {
  const response = await fetch(`http://api:8081${path}`, { headers: { Accept: 'application/json' } });
  if (!response.ok) throw new Error(`${path}: ${response.status} ${await response.text()}`);
}
JS
    ;;
  test) cd e2e; exec npm test -- "$@" ;;
  verify-order)
    if [ -f e2e/test-results/rts-order.json ]; then php e2e/verify-order.php; fi
    if [ -f e2e/test-results/po-order.json ]; then php e2e/verify-preorder.php; fi
    ;;
  *) echo "Unknown container task: $mode" >&2; exit 2 ;;
esac
