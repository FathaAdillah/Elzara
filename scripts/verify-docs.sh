#!/usr/bin/env bash
# Jalankan enam pemeriksa konsistensi + kelengkapan (Doc 11 §8.8) terhadap docs/ dan skeleton repo - hasilnya dicatat, tidak diklaim.
# Keenam pemeriksa hidup DI LUAR repo (bukan deliverable), jadi direktorinya wajib lewat ELZARA_AUDITORS_DIR, mis. C:/temp.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
AUDITORS="${ELZARA_AUDITORS_DIR:?ELZARA_AUDITORS_DIR wajib diisi - direktori berisi verify_schema.py, audit_sweep3.py, audit_blueprint_coverage.py, check_layout.py, scan_unicode.py, list_dead_cites.py}"
PYTHON="${PYTHON:-python}"
cd "${ROOT}"

if [ ! -d "${AUDITORS}" ]; then
  echo "ELZARA_AUDITORS_DIR menunjuk ke direktori yang tidak ada: ${AUDITORS}" >&2
  exit 1
fi

status=0
# Kelima pemeriksa ini membaca path yang ditanam di dalam dirinya; direktori ini hanya menentukan skripnya.
for checker in verify_schema.py audit_sweep3.py audit_blueprint_coverage.py check_layout.py list_dead_cites.py; do
  printf '== %s\n' "${checker}"
  "${PYTHON}" "${AUDITORS}/${checker}" || status=1
done

# Homoglyph bukan penyakit khusus dokumen: teks hasil generate masuk ke kode dan config juga,
# jadi cakupannya seluruh berkas teks repo - bukan docs/ saja.
printf '== scan_unicode.py\n'
find . -type d \( -name .git -o -name vendor -o -name node_modules -o -name .next -o -name bootstrap \) -prune \
  -o -type f \( -name '*.md' -o -name '*.php' -o -name '*.ts' -o -name '*.tsx' -o -name '*.yaml' -o -name '*.yml' -o -name '*.json' -o -name '*.sh' -o -name '*.css' \) -print0 \
  | xargs -0 "${PYTHON}" "${AUDITORS}/scan_unicode.py" || status=1

if [ "${status}" -ne 0 ]; then
  echo "minimal satu pemeriksa dokumen menyatakan temuan - lihat keluaran di atas." >&2
fi
exit "${status}"
