#!/bin/sh
# Salinan off-node terenkripsi untuk objek MinIO aplikasi (runbook backup-restore-drill §3.4 butir 9).
# Tujuan = provider/bucket yang sama dengan WAL-G (BACKUP_S3_*), di bawah OBJECT_BACKUP_S3_PATH; tidak pernah
# bucket ketujuh di MinIO node ini (ADR-008 K-1). Isi dan nama objek dienkripsi rclone crypt sebelum keluar node.
#   loop (default): sync tiap OBJECT_BACKUP_INTERVAL_SECONDS · once: satu kali sync · restore: salin balik ke DST
#   list [path]: daftar isi salinan terdekripsi, mis. `list deleted` untuk memilih versi yang terhapus
# Layout tujuan: current/<bucket>/... (cermin terakhir) dan deleted/<UTC stamp>/<bucket>/... (versi yang
# terhapus/tertimpa, disimpan OBJECT_BACKUP_RETAIN_DAYS hari) sehingga penghapusan di sumber tidak langsung hilang.
set -eu
mode="${1:-loop}"
buckets="${OBJECT_BACKUP_BUCKETS:-product-media request-attachments invoice-documents shipment-documents audit-archives}"
interval="${OBJECT_BACKUP_INTERVAL_SECONDS:-3600}"
retain="${OBJECT_BACKUP_RETAIN_DAYS:-30}"
max_delete="${OBJECT_BACKUP_MAX_DELETE:-1000}"

: "${OBJECT_BACKUP_S3_PATH:?OBJECT_BACKUP_S3_PATH wajib diisi (bucket/prefix off-node)}"
: "${OBJECT_BACKUP_CRYPT_PASSWORD:?OBJECT_BACKUP_CRYPT_PASSWORD wajib diisi}"
: "${OBJECT_BACKUP_CRYPT_SALT:?OBJECT_BACKUP_CRYPT_SALT wajib diisi}"
: "${BACKUP_S3_ENDPOINT:?BACKUP_S3_ENDPOINT wajib diisi}"
: "${BACKUP_S3_ACCESS_KEY:?BACKUP_S3_ACCESS_KEY wajib diisi}"
: "${BACKUP_S3_SECRET_KEY:?BACKUP_S3_SECRET_KEY wajib diisi}"
: "${ELZARA_BACKUP_READER_ACCESS_KEY:?ELZARA_BACKUP_READER_ACCESS_KEY wajib diisi}"
: "${ELZARA_BACKUP_READER_SECRET_KEY:?ELZARA_BACKUP_READER_SECRET_KEY wajib diisi}"

export RCLONE_CONFIG=/tmp/rclone.conf
: > "$RCLONE_CONFIG"
export RCLONE_CONFIG_SRC_TYPE=s3 RCLONE_CONFIG_SRC_PROVIDER=Minio
export RCLONE_CONFIG_SRC_ENDPOINT="${MINIO_ENDPOINT:-http://minio:9000}"
export RCLONE_CONFIG_SRC_REGION="${AWS_DEFAULT_REGION:-elzara-1}"
export RCLONE_CONFIG_SRC_ACCESS_KEY_ID="$ELZARA_BACKUP_READER_ACCESS_KEY"
export RCLONE_CONFIG_SRC_SECRET_ACCESS_KEY="$ELZARA_BACKUP_READER_SECRET_KEY"
export RCLONE_CONFIG_OFFNODE_TYPE=s3 RCLONE_CONFIG_OFFNODE_PROVIDER=Other
export RCLONE_CONFIG_OFFNODE_ENDPOINT="$BACKUP_S3_ENDPOINT"
export RCLONE_CONFIG_OFFNODE_REGION="${BACKUP_S3_REGION:-}"
export RCLONE_CONFIG_OFFNODE_ACCESS_KEY_ID="$BACKUP_S3_ACCESS_KEY"
export RCLONE_CONFIG_OFFNODE_SECRET_ACCESS_KEY="$BACKUP_S3_SECRET_KEY"
export RCLONE_CONFIG_OFFNODE_FORCE_PATH_STYLE="${BACKUP_S3_FORCE_PATH_STYLE:-true}"
export RCLONE_CONFIG_VAULT_TYPE=crypt RCLONE_CONFIG_VAULT_REMOTE="offnode:${OBJECT_BACKUP_S3_PATH}"
export RCLONE_CONFIG_VAULT_FILENAME_ENCRYPTION=standard RCLONE_CONFIG_VAULT_DIRECTORY_NAME_ENCRYPTION=true
RCLONE_CONFIG_VAULT_PASSWORD="$(rclone obscure "$OBJECT_BACKUP_CRYPT_PASSWORD")"
RCLONE_CONFIG_VAULT_PASSWORD2="$(rclone obscure "$OBJECT_BACKUP_CRYPT_SALT")"
export RCLONE_CONFIG_VAULT_PASSWORD RCLONE_CONFIG_VAULT_PASSWORD2

log() { echo "{\"event\":\"$1\",\"at\":$(date -u +%s)${2:+,$2}}"; }

prune() {
  cutoff=$(date -u -d "@$(( $(date -u +%s) - retain * 86400 ))" +%Y%m%d%H%M%S)
  for dir in $(rclone lsf --dirs-only vault:deleted 2>/dev/null); do
    stamp="${dir%/}"
    digits=$(printf '%s' "$stamp" | tr -d TZ)
    case "$digits" in *[!0-9]* | "") continue ;; esac
    if [ "$digits" -lt "$cutoff" ]; then rclone purge "vault:deleted/$stamp"; fi
  done
}

run_backup() {
  started=$(date -u +%s)
  stamp=$(date -u +%Y%m%dT%H%M%SZ)
  log object_backup.started
  failed=""
  for b in $buckets; do
    # incoming/ = staging presigned yang belum tervalidasi (ADR-008); bukan data yang perlu dipulihkan.
    rclone sync "src:$b" "vault:current/$b" --backup-dir "vault:deleted/$stamp/$b" \
      --exclude 'incoming/**' --max-delete "$max_delete" --stats-log-level NOTICE --stats 0 \
      || failed="$failed $b"
  done
  if [ -z "$failed" ] && prune; then
    date -u +%s > /tmp/last-object-backup-success
    log object_backup.succeeded "\"seconds\":$(( $(date -u +%s) - started ))"
    return 0
  fi
  log object_backup.failed "\"seconds\":$(( $(date -u +%s) - started )),\"buckets\":\"${failed# }\"" >&2
  return 1
}

run_restore() {
  # Target = MinIO baru yang sudah diprovisioning init-buckets.sh (versioning + policy); default = SRC.
  export RCLONE_CONFIG_DST_TYPE=s3 RCLONE_CONFIG_DST_PROVIDER=Minio
  export RCLONE_CONFIG_DST_ENDPOINT="${RESTORE_S3_ENDPOINT:-$RCLONE_CONFIG_SRC_ENDPOINT}"
  export RCLONE_CONFIG_DST_REGION="$RCLONE_CONFIG_SRC_REGION"
  export RCLONE_CONFIG_DST_ACCESS_KEY_ID="${RESTORE_S3_ACCESS_KEY:?RESTORE_S3_ACCESS_KEY wajib diisi}"
  export RCLONE_CONFIG_DST_SECRET_ACCESS_KEY="${RESTORE_S3_SECRET_KEY:?RESTORE_S3_SECRET_KEY wajib diisi}"
  started=$(date -u +%s)
  total=0
  for b in $buckets; do
    rclone copy "vault:current/$b" "dst:$b" --stats-log-level NOTICE --stats 0
    total=$(( total + $(rclone lsf -R --files-only "dst:$b" | grep -vc '^incoming/' || true) ))
  done
  log object_restore.finished "\"objects\":$total,\"seconds\":$(( $(date -u +%s) - started ))"
  # Kunci salah: nama berkas tidak bisa didekripsi sehingga tidak ada yang tersalin; itu kegagalan, bukan sukses kosong.
  [ "$total" -gt 0 ]
}

case "$mode" in
  once) run_backup ;;
  restore) run_restore ;;
  list) rclone lsf -R "vault:${2:-}" ;;
  loop)
    date -u +%s > /tmp/object-backup-loop-started
    while :; do run_backup || true; sleep "$interval"; done ;;
  *) echo "mode tidak dikenal: $mode (loop|once|restore|list)" >&2; exit 2 ;;
esac
