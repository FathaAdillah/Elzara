#!/usr/bin/env bash
# Drill backup + restore objek terisolasi (runbook docs/runbooks/backup-restore-drill.md §3.4 butir 9): MinIO sumber
# diprovisioning init-buckets.sh, docker/object-backup/object-backup.sh (image service object-backup) menyalin
# objek terenkripsi ke MinIO terpisah (stand-in off-node), lalu restore ke MinIO baru dan verifikasi isi + akses.
# Tidak menyentuh stack yang sedang jalan; semua resource berprefix elzara-objdrill. Butuh image elzara/minio:dev.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MINIO_IMAGE="${MINIO_IMAGE:-elzara/minio:dev}"
RCLONE_IMAGE="${RCLONE_IMAGE:-rclone/rclone:1.71.2}"
NET=elzara-objdrill
ROOT_SECRET="$(openssl rand -hex 16)"
OFFNODE_SECRET="$(openssl rand -hex 16)"
READER_SECRET="$(openssl rand -hex 16)"
CRYPT_PASSWORD="$(openssl rand -hex 32)"
CRYPT_SALT="$(openssl rand -hex 32)"
MARKER="elzara-objdrill-$(openssl rand -hex 6)"
export MSYS_NO_PATHCONV=1

cleanup() {
  docker rm -f elzara-objdrill-src elzara-objdrill-dst elzara-objdrill-offnode >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup
log() { printf '[objdrill %s] %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
fail() { log "GAGAL: $*"; exit 1; }
minio() { docker run -d --name "$1" --network "$NET" -e MINIO_ROOT_USER=drill-root -e "MINIO_ROOT_PASSWORD=$2" "$MINIO_IMAGE" server /data >/dev/null
  for _ in $(seq 1 60); do docker exec "$1" curl -fs http://127.0.0.1:9000/minio/health/live >/dev/null 2>&1 && return 0; sleep 1; done; return 1; }
mcx() { local host="$1"; shift; docker exec "$host" sh -c "mc alias set l http://127.0.0.1:9000 drill-root '$ROOT_SECRET' >/dev/null && $*"; }
provision() { MINIO_ENDPOINT="http://$1:9000" COMPOSE_NETWORK="$NET" ELZARA_SKIP_DOTENV=1 MC_IMAGE="$MINIO_IMAGE" \
  MINIO_ROOT_USER=drill-root MINIO_ROOT_PASSWORD="$ROOT_SECRET" ELZARA_S3_ACCESS_KEY=elzara-app ELZARA_S3_SECRET_KEY="$(openssl rand -hex 16)" \
  ELZARA_BACKUP_READER_ACCESS_KEY=elzara-backup-reader ELZARA_BACKUP_READER_SECRET_KEY="$READER_SECRET" \
  bash "$ROOT/docker/minio/init-buckets.sh" >/dev/null; }
objbackup() { local password="$1"; shift
  docker run --rm --network "$NET" --user 65534:65534 -v "$ROOT/docker/object-backup:/opt/object-backup:ro" \
    -e MINIO_ENDPOINT=http://elzara-objdrill-src:9000 -e ELZARA_BACKUP_READER_ACCESS_KEY=elzara-backup-reader \
    -e "ELZARA_BACKUP_READER_SECRET_KEY=$READER_SECRET" -e BACKUP_S3_ENDPOINT=http://elzara-objdrill-offnode:9000 \
    -e BACKUP_S3_REGION=ap-southeast-3 -e BACKUP_S3_ACCESS_KEY=drill-root -e "BACKUP_S3_SECRET_KEY=$OFFNODE_SECRET" \
    -e OBJECT_BACKUP_S3_PATH=elzara-backup/objects -e "OBJECT_BACKUP_RETAIN_DAYS=${RETAIN:-30}" -e "OBJECT_BACKUP_CRYPT_PASSWORD=$password" -e "OBJECT_BACKUP_CRYPT_SALT=$CRYPT_SALT" \
    -e RESTORE_S3_ENDPOINT=http://elzara-objdrill-dst:9000 -e RESTORE_S3_ACCESS_KEY=drill-root -e "RESTORE_S3_SECRET_KEY=$ROOT_SECRET" \
    --entrypoint /bin/sh "$RCLONE_IMAGE" /opt/object-backup/object-backup.sh "$@"; }
digest() { mcx "$1" "mc cat 'l/$2' | sha256sum | cut -d' ' -f1"; }
status() { docker exec "$1" curl -s -o /dev/null -w '%{http_code}' "$2"; }

docker network create "$NET" >/dev/null
log "sumber + target restore: MinIO image produksi, diprovisioning init-buckets.sh (enam bucket, versioning, policy)"
minio elzara-objdrill-src "$ROOT_SECRET"; minio elzara-objdrill-dst "$ROOT_SECRET"
provision elzara-objdrill-src; provision elzara-objdrill-dst
log "tujuan off-node: MinIO terpisah, kredensial khusus backup"
minio elzara-objdrill-offnode "$OFFNODE_SECRET"
docker exec elzara-objdrill-offnode sh -c "mc alias set d http://127.0.0.1:9000 drill-root '$OFFNODE_SECRET' >/dev/null && mc mb -q d/elzara-backup" >/dev/null

KEEP="product-media/published/hero.webp request-attachments/published/brief.pdf invoice-documents/published/inv-1.pdf shipment-documents/published/awb-1.pdf audit-archives/2026/11/audit.jsonl"
for obj in $KEEP invoice-documents/published/inv-void.pdf product-media/incoming/staging.webp exports/report.csv; do
  mcx elzara-objdrill-src "head -c 4096 /dev/urandom > /tmp/o && echo '$MARKER $obj' >> /tmp/o && mc cp -q /tmp/o 'l/$obj'" >/dev/null
done
# Versi kedua: hanya versi terkini yang dipulihkan (versi lama tetap di sumber selama ada).
mcx elzara-objdrill-src "echo '$MARKER brief v2' > /tmp/o && mc cp -q /tmp/o l/request-attachments/published/brief.pdf" >/dev/null

log "backup pertama (object-backup.sh once)"
START=$(date +%s); objbackup "$CRYPT_PASSWORD" once >/dev/null; BACKUP_SECONDS=$(( $(date +%s) - START ))
log "penghapusan di sumber lalu backup kedua: versi terhapus harus pindah ke deleted/<stamp>/"
mcx elzara-objdrill-src "mc rm -q l/invoice-documents/published/inv-void.pdf" >/dev/null
objbackup "$CRYPT_PASSWORD" once >/dev/null
LIST=$(objbackup "$CRYPT_PASSWORD" list)
grep -q '^current/invoice-documents/published/inv-void.pdf$' <<<"$LIST" && fail "objek terhapus masih di current/"
grep -Eq '^deleted/[0-9TZ]+/invoice-documents/published/inv-void.pdf$' <<<"$LIST" || fail "objek terhapus tidak diarsipkan di deleted/"
grep -Eq 'incoming/|exports/' <<<"$LIST" && fail "incoming/ atau exports ikut tersalin"
RETAIN=0 objbackup "$CRYPT_PASSWORD" once >/dev/null
objbackup "$CRYPT_PASSWORD" list deleted | grep -q . && fail "retensi tidak memangkas deleted/"

OFFNODE_OBJECTS=$(docker exec elzara-objdrill-offnode sh -c "mc ls -r d/elzara-backup | wc -l")
PLAINTEXT=$(docker exec elzara-objdrill-offnode sh -c "grep -rlE '$MARKER|published|request-attachments' /data/elzara-backup 2>/dev/null | wc -l")
PLAINTEXT=$(( PLAINTEXT + $(docker exec elzara-objdrill-offnode sh -c "find /data/elzara-backup | grep -cE 'published|request-attachments|audit' || true") ))
[ "$PLAINTEXT" -eq 0 ] || fail "isi atau nama objek terbaca plaintext di tujuan off-node ($PLAINTEXT berkas)"

log "restore dengan kunci salah harus gagal tanpa menulis apa pun"
if objbackup "$(openssl rand -hex 32)" restore >/dev/null 2>&1; then fail "restore dengan kunci salah dilaporkan sukses"; fi
WRONG_KEY_OBJECTS=$(mcx elzara-objdrill-dst "mc ls -r l | wc -l")
[ "$WRONG_KEY_OBJECTS" -eq 0 ] || fail "kunci salah menulis $WRONG_KEY_OBJECTS objek"

log "restore ke MinIO baru"
START=$(date +%s); objbackup "$CRYPT_PASSWORD" restore >/dev/null; RESTORE_SECONDS=$(( $(date +%s) - START ))
for obj in $KEEP; do
  [ "$(digest elzara-objdrill-src "$obj")" = "$(digest elzara-objdrill-dst "$obj")" ] || fail "isi $obj berbeda setelah restore"
done
RESTORED=$(mcx elzara-objdrill-dst "mc ls -r l | wc -l")
[ "$RESTORED" -eq 5 ] || fail "restore menghasilkan $RESTORED objek, harap 5"
VERSIONING=$(mcx elzara-objdrill-dst "mc version info l/request-attachments" | grep -c 'versioning is enabled' || true)
[ "$VERSIONING" -eq 1 ] || fail "versioning tidak aktif di target restore"
PUBLIC=$(status elzara-objdrill-dst http://127.0.0.1:9000/product-media/published/hero.webp)
PRIVATE=$(status elzara-objdrill-dst http://127.0.0.1:9000/request-attachments/published/brief.pdf)
SIGNED_URL=$(mcx elzara-objdrill-dst "mc share download --json --expire 2m l/request-attachments/published/brief.pdf" | sed -n 's/.*"share":"\([^"]*\)".*/\1/p')
SIGNED=$(docker exec elzara-objdrill-dst curl -fsS "$SIGNED_URL" | grep -c "$MARKER brief v2" || true)
[ "$PUBLIC" = 200 ] && [ "$PRIVATE" = 403 ] && [ "$SIGNED" = 1 ] || fail "akses pasca-restore: publik=$PUBLIC privat=$PRIVATE signed=$SIGNED"

printf '%s\n' \
  "object_drill.backup_seconds=$BACKUP_SECONDS" \
  "object_drill.offnode_objects=$OFFNODE_OBJECTS" \
  "object_drill.offnode_plaintext_files=$PLAINTEXT" \
  "object_drill.deleted_version_archived=yes (dipangkas saat retensi lewat)" \
  "object_drill.wrong_key_restored_objects=$WRONG_KEY_OBJECTS" \
  "object_drill.restore_seconds=$RESTORE_SECONDS" \
  "object_drill.restored_objects=$RESTORED (checksum cocok)" \
  "object_drill.access=public:$PUBLIC private_unsigned:$PRIVATE private_signed:200"
log "LULUS"
