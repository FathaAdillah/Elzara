#!/usr/bin/env bash
# Naikkan stack dev dari repo root lalu provisioning enam bucket; tidak ada instalasi dependensi di host (Doc 01 §11.2).
# Butuh Docker Engine + Compose v2 dan docker/.env yang lengkap; variabel yang hilang membuat compose gagal menyebut namanya.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

if [ ! -f docker/.env ]; then
  echo "docker/.env belum ada - salin dari docker/.env.example lalu isi nilainya." >&2
  exit 1
fi

min_free_gib=${ELZARA_DEV_MIN_FREE_GIB:-15}
build_timeout=${ELZARA_DEV_BUILD_TIMEOUT:-20m}
disk_path=${ELZARA_DOCKER_DISK_PATH:-$ROOT}
if [[ ! "$min_free_gib" =~ ^[1-9][0-9]?$ || ! "$build_timeout" =~ ^[1-9][0-9]*[smh]$ ]]; then
  echo "Gunakan minimum disk 1-99 GiB dan batas waktu build seperti 20m." >&2
  exit 1
fi
command -v timeout >/dev/null || { echo "Perintah timeout diperlukan untuk build development." >&2; exit 1; }
check_free_space() {
  local free_kib
  if ! free_kib=$(df -Pk -- "$disk_path" | awk 'NR == 2 { print $4 }') ||
    [[ ! "$free_kib" =~ ^[0-9]+$ ]] || (( free_kib < min_free_gib * 1024 * 1024 )); then
    echo "Ruang disk tidak cukup atau tidak terbaca. Build membutuhkan minimal ${min_free_gib} GiB kosong pada $disk_path." >&2
    return 1
  fi
}
check_free_space

for lockfile in apps/api/composer.lock apps/web/package-lock.json apps/admin/package-lock.json; do
  [[ -s "$lockfile" ]] || { echo "Lockfile belum tersedia: $lockfile" >&2; exit 1; }
done

compose=(docker compose --env-file docker/.env -f docker/compose.local.yaml)
"${compose[@]}" config --quiet
timeout 30s docker info --format '{{.ServerVersion}}' >/dev/null

build_lock=docker/.env.dev-build.lock
if ! mkdir "$build_lock"; then
  echo "Eksekusi development lain aktif atau lock tersisa: $build_lock" >&2
  exit 1
fi
trap 'rmdir "$build_lock"' EXIT

for service in api web admin minio postgres; do
  check_free_space
  if timeout --kill-after=15s "$build_timeout" "${compose[@]}" --progress plain build "$service"; then
    continue
  else
    status=$?
    echo "Build $service berhenti (kode $status). Periksa keluaran tahap pertama yang gagal sebelum mencoba lagi." >&2
    exit "$status"
  fi
done
"${compose[@]}" up -d --no-build --wait --wait-timeout 240
MC_IMAGE=elzara/minio:dev ELZARA_MINIO_SERVER_CORS=1 bash docker/minio/init-buckets.sh
"${compose[@]}" ps
