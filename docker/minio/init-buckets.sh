#!/usr/bin/env bash
# Buat tepat enam bucket aplikasi + service account least-privilege + TTL exports (Doc 01 §9.2, ADR-008).
# Idempoten, aman diulang. Bucket ketujuh terlarang: dump backup selalu off-node, bukan di MinIO node ini (Doc 11 §8.7).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
SCRIPT_HOST_DIR="$(cd "${SCRIPT_DIR}" && (pwd -W 2>/dev/null || pwd))"
export MSYS_NO_PATHCONV=1

# Dev: kredensial dibaca dari docker/.env bila belum ada di environment shell.
if [ "${ELZARA_SKIP_DOTENV:-0}" != 1 ] && [ -f "${ROOT}/docker/.env" ]; then
  set -a
  . "${ROOT}/docker/.env"
  set +a
fi

: "${MINIO_ROOT_USER:?MINIO_ROOT_USER wajib diisi - kredensial root MinIO, lihat docker/.env.example}"
: "${MINIO_ROOT_PASSWORD:?MINIO_ROOT_PASSWORD wajib diisi - kredensial root MinIO}"
: "${ELZARA_S3_ACCESS_KEY:?ELZARA_S3_ACCESS_KEY wajib diisi - access key service account aplikasi}"
: "${ELZARA_S3_SECRET_KEY:?ELZARA_S3_SECRET_KEY wajib diisi - secret key service account aplikasi}"
if (( ${#ELZARA_S3_SECRET_KEY} < 8 || ${#ELZARA_S3_SECRET_KEY} > 40 )); then
  echo 'ELZARA_S3_SECRET_KEY harus berisi 8 sampai 40 karakter.' >&2
  exit 1
fi

MINIO_ENDPOINT="${MINIO_ENDPOINT:-http://minio:9000}"
COMPOSE_NETWORK="${COMPOSE_NETWORK:-elzara_backend}"
# Image yang sama dengan service minio; mc dibangun dari source dalam Dockerfile.
MC_IMAGE="${MC_IMAGE:-elzara/minio:dev}"
AWS_CLI_IMAGE="${AWS_CLI_IMAGE:-public.ecr.aws/aws-cli/aws-cli:2.34.42}"
AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION:-elzara-1}"
BUCKETS="product-media request-attachments invoice-documents shipment-documents exports audit-archives"

# mc dan aws tidak pernah diinstal di host (Doc 01 §11.2): keduanya container sekali-pakai di
# jaringan backend, satu-satunya tempat minio:9000 bisa dijangkau.
docker run --rm \
  --entrypoint sh \
  --network "${COMPOSE_NETWORK}" \
  --volume "${SCRIPT_HOST_DIR}:/minio:ro" \
  -e MINIO_ENDPOINT="${MINIO_ENDPOINT}" \
  -e MINIO_ROOT_USER="${MINIO_ROOT_USER}" \
  -e MINIO_ROOT_PASSWORD="${MINIO_ROOT_PASSWORD}" \
  -e ELZARA_S3_ACCESS_KEY="${ELZARA_S3_ACCESS_KEY}" \
  -e ELZARA_S3_SECRET_KEY="${ELZARA_S3_SECRET_KEY}" \
  -e ELZARA_BACKUP_READER_ACCESS_KEY="${ELZARA_BACKUP_READER_ACCESS_KEY:-}" \
  -e ELZARA_BACKUP_READER_SECRET_KEY="${ELZARA_BACKUP_READER_SECRET_KEY:-}" \
  "${MC_IMAGE}" -euc '
    MC="mc --config-dir /tmp/mc"
    ${MC} alias set local "${MINIO_ENDPOINT}" "${MINIO_ROOT_USER}" "${MINIO_ROOT_PASSWORD}" >/dev/null
    for b in product-media request-attachments invoice-documents shipment-documents exports audit-archives; do
      ${MC} mb --ignore-existing "local/${b}"
      ${MC} anonymous set private "local/${b}"
      ${MC} version enable "local/${b}"
    done
    # Hanya published/* boleh dibaca anonim; incoming/* selalu privat.
    ${MC} anonymous set-json /minio/policies/product-media-public.json local/product-media
    for b in product-media request-attachments invoice-documents shipment-documents; do
      if ! ${MC} ilm export "local/${b}" 2>/dev/null | grep -q NoncurrentVersionExpiration; then
        ${MC} ilm rule add --prefix incoming/ --expire-days 1 --noncurrent-expire-days 1 "local/${b}"
        ${MC} ilm rule add --prefix incoming/ --expire-delete-marker "local/${b}"
      fi
    done
    if ! ${MC} ilm rule ls --json local/exports | grep -Eq "\"(days|Days)\"[[:space:]]*:[[:space:]]*7([,}])"; then
      ${MC} ilm rule add --expire-days 7 local/exports
    fi
    if ! ${MC} ilm export local/exports | grep -q NoncurrentVersionExpiration; then
      ${MC} ilm rule add --noncurrent-expire-days 7 --expire-delete-marker local/exports
    fi
    ${MC} admin user svcacct add local "${MINIO_ROOT_USER}" \
      --access-key "${ELZARA_S3_ACCESS_KEY}" --secret-key "${ELZARA_S3_SECRET_KEY}" \
      --policy /minio/policies/elzara-app.json >/dev/null 2>&1 \
      || ${MC} admin user svcacct edit local "${ELZARA_S3_ACCESS_KEY}" \
        --secret-key "${ELZARA_S3_SECRET_KEY}" --policy /minio/policies/elzara-app.json
    # Service account baca-saja untuk service object-backup (profil backup); exports tidak ikut (TTL 7 hari).
    if [ -n "${ELZARA_BACKUP_READER_ACCESS_KEY}" ]; then
      ${MC} admin user svcacct add local "${MINIO_ROOT_USER}" \
        --access-key "${ELZARA_BACKUP_READER_ACCESS_KEY}" --secret-key "${ELZARA_BACKUP_READER_SECRET_KEY}" \
        --policy /minio/policies/elzara-backup-reader.json >/dev/null 2>&1 \
        || ${MC} admin user svcacct edit local "${ELZARA_BACKUP_READER_ACCESS_KEY}" \
          --secret-key "${ELZARA_BACKUP_READER_SECRET_KEY}" --policy /minio/policies/elzara-backup-reader.json
    fi
    count=$(${MC} ls local --json | grep -c folder || true)
    [ "${count}" -eq 6 ] || { echo "bucket = ${count}, harus tepat 6 (Doc 01 §9.2)"; exit 1; }
  '

# Source-build MinIO memakai origin server dari compose; provider lain dapat memakai bucket-CORS.
if [ "${ELZARA_MINIO_SERVER_CORS:-1}" != 1 ]; then
docker run --rm \
  --entrypoint sh \
  --network "${COMPOSE_NETWORK}" \
  --volume "${SCRIPT_HOST_DIR}:/minio:ro" \
  -e AWS_ACCESS_KEY_ID="${MINIO_ROOT_USER}" \
  -e AWS_SECRET_ACCESS_KEY="${MINIO_ROOT_PASSWORD}" \
  -e AWS_DEFAULT_REGION="${AWS_DEFAULT_REGION}" \
  -e MINIO_ENDPOINT="${MINIO_ENDPOINT}" \
  "${AWS_CLI_IMAGE}" -euc '
    for b in product-media request-attachments invoice-documents shipment-documents exports audit-archives; do
      aws --endpoint-url "${MINIO_ENDPOINT}" s3api put-bucket-cors \
        --bucket "${b}" --cors-configuration file:///minio/cors/bucket-cors.json >/dev/null
    done
  '
fi

echo "enam bucket siap: ${BUCKETS}"
