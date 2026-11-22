#!/usr/bin/env bash
# Skenario k6 P0.24 (load/k6/README.md) lewat image grafana/k6 — tidak perlu k6 terpasang di host.
#   bash scripts/k6.sh seed                       # LoadTestSeeder di stack lokal (docker/compose.local.yaml)
#   bash scripts/k6.sh catalog|checkout|contention [argumen k6 tambahan]
# Target dan beban dari env: BASE_URL (default http://localhost), PROFILE=smoke|baseline,
# LOADTEST_PASSWORD, VUS, DURATION, RATE, ITERATIONS, ... Staging cukup mengganti BASE_URL.
set -euo pipefail
cd "$(dirname "$0")/.."

K6_IMAGE=${K6_IMAGE:-grafana/k6:1.8.3}
scenario=${1:?pakai: scripts/k6.sh seed|catalog|checkout|contention}
shift

if [[ $scenario == seed ]]; then
  : "${LOADTEST_PASSWORD:?LOADTEST_PASSWORD wajib diisi}"
  exec docker compose --env-file docker/.env -f docker/compose.local.yaml exec -T \
    -e LOADTEST_PASSWORD -e LOADTEST_CUSTOMERS="${LOADTEST_CUSTOMERS:-50}" -e LOADTEST_CONTENTION_STOCK="${LOADTEST_CONTENTION_STOCK:-10}" \
    api php artisan db:seed --class='Database\Seeders\LoadTestSeeder' --force
fi

[[ -f load/k6/$scenario.js ]] || { echo "skenario tidak dikenal: $scenario" >&2; exit 2; }
mkdir -p load/k6/results
stamp=$(date -u +%Y%m%dT%H%M%SZ)
env_args=()
for name in BASE_URL PROFILE LOADTEST_PASSWORD LOADTEST_CUSTOMERS VUS MAX_VUS DURATION RATE ITERATIONS THINK_SECONDS \
  PLACE_ORDER CHECKOUT_SLUG CONTENTION_SLUG CONTENTION_STOCK CONTENTION_BUYERS; do
  [[ -n ${!name:-} ]] && env_args+=(-e "$name=${!name}")
done

# --network host: http://localhost di container = edge lokal di host (port 80 loopback).
exec docker run --rm -i --network host --user "$(id -u):$(id -g)" -v "$PWD/load/k6:/scripts" -w /scripts "$K6_IMAGE" \
  run "${env_args[@]}" --summary-export "results/$scenario-$stamp.json" "$@" "$scenario.js"
