#!/usr/bin/env bash
# Pasang tumpukan dependensi auth/RBAC (Doc 11 §4.4 D + K) DI DALAM container php:8.4, bukan di host.
# Kenapa wajib container: spatie/laravel-activitylog 5.1.5 menuntut php ^8.4 dan phpunit 13.4.7 php >=8.4.1,
# sementara host berjalan PHP 8.3.30 - di host, resolve-nya gagal, bukan sekadar berbeda gaya.
# Vendor ditulis ke /tmp di dalam container supaya repo tetap bersih; lockfile tetap ditulis ke apps/api.
# Urutan: hapus predis -> require runtime -> require dev -> bangkitkan ketiga lockfile.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"
ROOT_HOST="$(pwd -W 2>/dev/null || pwd)"
export MSYS_NO_PATHCONV=1

if [ -z "${PHP_IMAGE:-}" ]; then
  PHP_IMAGE=elzara/composer-tools:local
  docker build -f apps/api/Dockerfile --target composer-tools -t "${PHP_IMAGE}" .
fi
export PHP_IMAGE

run_composer() {
  docker run --rm -w /repo/apps/api \
    -v "${ROOT_HOST}:/repo" \
    -e COMPOSER_ALLOW_SUPERUSER=1 \
    -e COMPOSER_VENDOR_DIR=/tmp/vendor \
    -e COMPOSER_HOME=/tmp/composer \
    "${PHP_IMAGE}" sh -c "composer $* --no-scripts --no-interaction --prefer-dist"
}

echo "== hapus predis (keputusan: ekstensi phpredis, satu driver Redis)"
run_composer "remove predis/predis"

echo "== require runtime (versi dibaca dari registry 2026-11-18, Doc 11 §4.4 D)"
run_composer "require \
  laravel/sanctum:^4.5 \
  laravel/socialite:^5.33 \
  socialiteproviders/twitter:^4.1 \
  spatie/laravel-permission:^8.5 \
  spatie/laravel-activitylog:^5.1 \
  spatie/laravel-data:^4.23 \
  dedoc/scramble:^0.13 \
  sentry/sentry-laravel:^4.30 \
  maatwebsite/excel:^4.0"

echo "== require dev (phpunit 13, bukan 12; deptrac/deptrac, bukan qossmic yang sudah abandoned)"
run_composer "require --dev \
  phpunit/phpunit:^13.0 \
  phpstan/phpstan:^2.3 \
  larastan/larastan:^3.15 \
  laravel/pint:^1.32 \
  nunomaduro/collision:^8.9 \
  laravel/tinker:^3.2 \
  mockery/mockery:^1.6 \
  fakerphp/faker:^1.24"

echo "== bangkitkan ketiga lockfile pada baseline container"
bash scripts/gen-lockfiles.sh

echo "== bukti: constraint yang tertulis di composer.json"
python - <<'PY'
import io, json
d = json.load(io.open("apps/api/composer.json", encoding="utf-8"))
for section in ("require", "require-dev"):
    print(section)
    for k, v in sorted(d.get(section, {}).items()):
        print("  %-32s %s" % (k, v))
assert "predis/predis" not in d.get("require", {}), "predis masih ada - keputusan phpredis dilanggar"
assert "qossmic/deptrac" not in d.get("require-dev", {}), "nama deptrac lama masih ada"
PY

echo "selesai. vendor TIDAK ditulis ke repo; image api yang memasang vendor saat build."
echo "langkah berikutnya: migrasi DB -> fitur -> testing -> rebuild docker (.qoder/agents/README.md)."
