#!/usr/bin/env bash
# Satu perintah migrate untuk seluruh repo (ADR-010 K-5): dev, CI, dan deploy semuanya memanggil script ini.
# Jalannya di container api terhadap postgres yang sudah service_healthy; tidak ada "migrasi milik app X".

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT}"

# Cara mundur bukan migrate:rollback (D-S0-9) - skema turun = restore dump pre-release (Doc 10 §9).
case " ${*:-} " in
  *rollback*)
    echo "rollback bukan jalur mundur: restore dump pre-release lalu jalankan migrasi maju." >&2
    exit 1
    ;;
esac

docker compose -f docker/compose.yaml exec -T api php artisan migrate --force "$@"
