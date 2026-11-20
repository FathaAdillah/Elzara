#!/usr/bin/env bash
# Bangkitkan TIGA lockfile (apps/api/composer.lock, apps/admin/package-lock.json, apps/web/package-lock.json)
# di dalam image ber-baseline (php:8.4 / node:22), bukan di host.
# Kenapa perlu: build image memakai `composer install` dengan lock dan `npm ci`, yang keduanya gagal tanpa lock;
# dan lock yang dihasilkan Node 26 / PHP 8.3 host bukan baseline Doc 01 §11.2. Run ini sekali per perubahan dependensi.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"
ROOT_HOST="$(pwd -W 2>/dev/null || pwd)"
export MSYS_NO_PATHCONV=1

if [ -z "${PHP_IMAGE:-}" ]; then
  PHP_IMAGE=elzara/composer-tools:local
  docker build -f apps/api/Dockerfile --target composer-tools -t "${PHP_IMAGE}" .
fi

# --package-lock-only: resolve tanpa menulis node_modules, jadi host tetap bersih.
for app in admin web; do
  docker run --rm -w /repo/apps/${app} \
    -v "${ROOT_HOST}:/repo" \
    node:22 sh -c "npm install --package-lock-only --ignore-scripts --no-audit --no-fund"
done

# API: kalau lock belum ada, resolve penuh (composer update). Kalau sudah ada, segarkan hash-nya saja
# (`update --lock` TIDAK menambah dependensi baru - untuk itu pakai scripts/install-deps.sh).
# COMPOSER_VENDOR_DIR=/tmp/vendor menjaga repo tetap bersih: yang kita butuhkan di sini hanya lockfile.
if [ -f apps/api/composer.lock ]; then
  API_CMD="composer update --lock --no-scripts --no-interaction"
else
  API_CMD="composer update --no-scripts --no-interaction --prefer-dist"
fi

docker run --rm -w /repo/apps/api \
  -v "${ROOT_HOST}:/repo" \
  -e COMPOSER_ALLOW_SUPERUSER=1 \
  -e COMPOSER_VENDOR_DIR=/tmp/vendor \
  -e COMPOSER_HOME=/tmp/composer \
  "${PHP_IMAGE}" sh -c "${API_CMD}"

echo "lockfile dihasilkan:"
ls -1 apps/api/composer.lock apps/admin/package-lock.json apps/web/package-lock.json
echo "commit ketiganya - jangan pernah regenerate dengan toolchain host."
