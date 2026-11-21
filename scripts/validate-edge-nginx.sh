#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
for listener in dev dokploy; do
  docker run --rm \
    --add-host api:127.0.0.1 --add-host web:127.0.0.1 \
    --add-host admin:127.0.0.1 --add-host minio:127.0.0.1 \
    -e ELZARA_ADMIN_HOST=admin.localhost \
    -e ELZARA_EDGE_URL=https://localhost \
    -e MINIO_PUBLIC_ENDPOINT=https://storage.localhost/storage \
    -e 'NGINX_ENVSUBST_FILTER=^(ELZARA_(ADMIN_HOST|EDGE_URL)|MINIO_PUBLIC_ENDPOINT)$' \
    -v "$ROOT/docker/nginx/$listener.conf:/etc/nginx/conf.d/default.conf:ro" \
    -v "$ROOT/docker/nginx/edge.conf.template:/etc/nginx/templates/edge.conf.template:ro" \
    -v "$ROOT/docker/nginx/routes.inc:/etc/nginx/elzara/routes.inc:ro" \
    -v "$ROOT/docker/nginx/security.inc:/etc/nginx/elzara/security.inc:ro" \
    nginx:1.27-alpine nginx -t
  echo "PASS nginx -t ($listener)"
done
