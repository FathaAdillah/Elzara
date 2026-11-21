#!/usr/bin/env bash
# Drill PITR terisolasi (runbook docs/runbooks/backup-restore-drill.md): Postgres image produksi + WAL-G
# mengarsip WAL terenkripsi ke MinIO terpisah (stand-in tujuan off-node), lalu restore ke cluster scratch
# pada titik waktu sebelum "insiden". Tidak menyentuh stack yang sedang jalan; semua resource berprefix elzara-drill.
# Butuh image elzara/postgres:dev dan elzara/minio:dev (docker build -f docker/{postgres,minio}/Dockerfile .).
# ELZARA_DRILL_MIGRATE=1 (default bila image API ada) menjalankan migrasi Laravel asli dari container API_IMAGE
# (docker build -f apps/api/Dockerfile -t elzara/api:dev .), tidak pernah dengan PHP host.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PG_IMAGE="${PG_IMAGE:-elzara/postgres:dev}"
MINIO_IMAGE="${MINIO_IMAGE:-elzara/minio:dev}"
PORT="${ELZARA_DRILL_PORT:-55432}"
NET=elzara-drill
KEY="$(openssl rand -hex 32)"
S3_SECRET="$(openssl rand -hex 16)"
PGPASS="$(openssl rand -hex 12)"
API_IMAGE="${API_IMAGE:-elzara/api:dev}"
MIGRATE="${ELZARA_DRILL_MIGRATE:-$(docker image inspect "$API_IMAGE" >/dev/null 2>&1 && echo 1 || echo 0)}"

cleanup() {
  docker rm -f elzara-drill-primary elzara-drill-restore elzara-drill-wrongkey elzara-drill-s3 >/dev/null 2>&1 || true
  docker volume rm elzara-drill-primary elzara-drill-restore >/dev/null 2>&1 || true
  docker network rm "$NET" >/dev/null 2>&1 || true
}
trap cleanup EXIT
cleanup
log() { printf '[drill %s] %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
psql_in() { docker exec -u postgres "$1" psql -X -v ON_ERROR_STOP=1 -qtA -d elzara -c "$2"; }
wait_ready() { for _ in $(seq 1 120); do docker exec "$1" pg_isready -q -U postgres -d elzara 2>/dev/null && return 0; sleep 1; done; docker logs "$1" >&2; return 1; }

WALG_ENV=(-e ELZARA_BACKUP_MODE=required -e WALG_S3_PREFIX=s3://elzara-backup/drill -e "WALG_LIBSODIUM_KEY=$KEY"
  -e WALG_LIBSODIUM_KEY_TRANSFORM=hex -e WALG_COMPRESSION_METHOD=brotli -e AWS_ENDPOINT=http://elzara-drill-s3:9000
  -e AWS_REGION=ap-southeast-3 -e AWS_ACCESS_KEY_ID=drill-backup -e "AWS_SECRET_ACCESS_KEY=$S3_SECRET" -e AWS_S3_FORCE_PATH_STYLE=true)

docker network create "$NET" >/dev/null
log "tujuan off-node: MinIO terpisah dengan kredensial khusus backup"
docker run -d --name elzara-drill-s3 --network "$NET" -e MINIO_ROOT_USER=drill-backup -e "MINIO_ROOT_PASSWORD=$S3_SECRET" \
  "$MINIO_IMAGE" server /data >/dev/null
for _ in $(seq 1 60); do docker exec elzara-drill-s3 curl -fs http://127.0.0.1:9000/minio/health/live >/dev/null 2>&1 && break; sleep 1; done
docker exec elzara-drill-s3 sh -c "mc alias set d http://127.0.0.1:9000 drill-backup '$S3_SECRET' >/dev/null && mc mb -q d/elzara-backup" >/dev/null

log "primary: image produksi, archive_command = wal-archive.sh"
docker run -d --name elzara-drill-primary --network "$NET" -p "127.0.0.1:$PORT:5432" -v elzara-drill-primary:/var/lib/postgresql/data \
  -e POSTGRES_DB=elzara -e POSTGRES_USER=postgres -e "POSTGRES_PASSWORD=$PGPASS" "${WALG_ENV[@]}" "$PG_IMAGE" \
  postgres -c wal_level=replica -c archive_mode=on -c archive_timeout=60 -c "archive_command=/usr/local/bin/wal-archive.sh %p" >/dev/null
wait_ready elzara-drill-primary
sleep 3; wait_ready elzara-drill-primary

if [ "$MIGRATE" = 1 ]; then
  log "migrasi Laravel asli ke primary (container $API_IMAGE)"
  docker run --rm --network "$NET" -e DB_CONNECTION=pgsql -e DB_HOST=elzara-drill-primary -e DB_PORT=5432 \
    -e DB_DATABASE=elzara -e DB_USERNAME=postgres -e "DB_PASSWORD=$PGPASS" -e "APP_KEY=base64:$(openssl rand -base64 32)" \
    -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e LOG_CHANNEL=stderr \
    "$API_IMAGE" php artisan migrate --force >/dev/null
else
  log "migrasi Laravel dilewati: image $API_IMAGE tidak ada"
fi
psql_in elzara-drill-primary "CREATE TABLE drill_orders (id bigint generated always as identity primary key, batch text not null, created_at timestamptz not null default clock_timestamp())"
psql_in elzara-drill-primary "INSERT INTO drill_orders (batch) SELECT 'before-base' FROM generate_series(1, 500)"

log "base backup (wal-g backup-push) seperti service backup"
BASE_START=$(date +%s)
docker exec -u postgres "${WALG_ENV[@]}" elzara-drill-primary sh -c 'wal-g backup-push "$PGDATA"' >/dev/null 2>&1
BASE_SECONDS=$(( $(date +%s) - BASE_START ))

psql_in elzara-drill-primary "INSERT INTO drill_orders (batch) SELECT 'after-base' FROM generate_series(1, 250)"
sleep 1
TARGET=$(psql_in elzara-drill-primary "SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') || '+00'")
sleep 1
psql_in elzara-drill-primary "INSERT INTO drill_orders (batch) SELECT 'incident' FROM generate_series(1, 100); DELETE FROM drill_orders WHERE batch = 'before-base' AND id % 2 = 0"
SEGMENT=$(psql_in elzara-drill-primary "SELECT pg_walfile_name(pg_switch_wal())")
for _ in $(seq 1 60); do
  [ "$(psql_in elzara-drill-primary "SELECT coalesce(last_archived_wal >= '$SEGMENT', false) FROM pg_stat_archiver")" = t ] && break; sleep 1
done
ARCHIVE=$(psql_in elzara-drill-primary "SELECT archived_count || ' archived, ' || failed_count || ' failed' FROM pg_stat_archiver")
EXPECTED_MIGRATIONS=$(psql_in elzara-drill-primary "SELECT count(*) || ':' || coalesce(max(migration), '-') FROM migrations" 2>/dev/null || echo "0:-")
OBJECTS=$(docker exec elzara-drill-s3 sh -c "mc ls -r d/elzara-backup | wc -l")
PLAINTEXT=$(docker exec elzara-drill-s3 sh -c "grep -rl drill_orders /data/elzara-backup 2>/dev/null | wc -l")
log "insiden disimulasikan; target PITR $TARGET; arsip: $ARCHIVE; objek off-node: $OBJECTS"
docker rm -f elzara-drill-primary >/dev/null

log "kunci salah harus gagal (backup terenkripsi)"
# WAL-G mengulang unduhan yang gagal dengan backoff panjang; cukup tunggu error dekripsi pertama.
docker run -d --name elzara-drill-wrongkey --network "$NET" "${WALG_ENV[@]}" -e "WALG_LIBSODIUM_KEY=$(openssl rand -hex 32)" \
  -v elzara-drill-restore:/restore --entrypoint wal-g "$PG_IMAGE" backup-fetch /restore/data LATEST >/dev/null
WRONG_KEY="tidak terbukti"
for _ in $(seq 1 60); do
  if docker logs elzara-drill-wrongkey 2>&1 | grep -q "corrupted chunk\|failed to decrypt\|Extraction error"; then WRONG_KEY="ditolak"; break; fi
  [ "$(docker inspect -f '{{.State.Running}}' elzara-drill-wrongkey)" = true ] || { [ "$(docker inspect -f '{{.State.ExitCode}}' elzara-drill-wrongkey)" = 0 ] && WRONG_KEY="BERHASIL (TIDAK AMAN)"; break; }
  sleep 1
done
docker rm -f elzara-drill-wrongkey >/dev/null
docker volume rm elzara-drill-restore >/dev/null

log "restore ke cluster scratch (RTO mulai)"
RESTORE_START=$(date +%s)
docker run --rm --network "$NET" "${WALG_ENV[@]}" -v elzara-drill-restore:/var/lib/postgresql/data --entrypoint sh "$PG_IMAGE" -c "
  set -e
  wal-g backup-fetch /var/lib/postgresql/data/pgdata LATEST
  touch /var/lib/postgresql/data/pgdata/recovery.signal
  cat >> /var/lib/postgresql/data/pgdata/postgresql.auto.conf <<CONF
restore_command = 'wal-g wal-fetch %f %p'
recovery_target_time = '$TARGET'
recovery_target_action = 'promote'
archive_mode = 'off'
CONF
  chown -R postgres:postgres /var/lib/postgresql/data/pgdata && chmod 700 /var/lib/postgresql/data/pgdata" >/dev/null 2>&1
docker run -d --name elzara-drill-restore --network "$NET" -v elzara-drill-restore:/var/lib/postgresql/data \
  -e PGDATA=/var/lib/postgresql/data/pgdata -e POSTGRES_PASSWORD=unused "${WALG_ENV[@]}" "$PG_IMAGE" postgres >/dev/null
for _ in $(seq 1 180); do
  [ "$(psql_in elzara-drill-restore 'SELECT NOT pg_is_in_recovery()' 2>/dev/null)" = t ] && break; sleep 1
done
RTO=$(( $(date +%s) - RESTORE_START ))

COUNTS=$(psql_in elzara-drill-restore "SELECT string_agg(batch || '=' || n, ' ' ORDER BY batch) FROM (SELECT batch, count(*) n FROM drill_orders GROUP BY batch) s")
LATEST=$(psql_in elzara-drill-restore "SELECT to_char(max(created_at) AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') || '+00' FROM drill_orders")
RESTORED_MIGRATIONS=$(psql_in elzara-drill-restore "SELECT count(*) || ':' || coalesce(max(migration), '-') FROM migrations" 2>/dev/null || echo "0:-")
TABLES=$(psql_in elzara-drill-restore "SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")
PG_VERSION=$(psql_in elzara-drill-restore "SHOW server_version")

STATUS=PASS
[ "$COUNTS" = "after-base=250 before-base=500" ] || STATUS=FAIL
[ "$RESTORED_MIGRATIONS" = "$EXPECTED_MIGRATIONS" ] || STATUS=FAIL
[ "$WRONG_KEY" = ditolak ] || STATUS=FAIL
[ "$PLAINTEXT" = 0 ] || STATUS=FAIL

cat <<REPORT
| Item | Hasil |
|---|---|
| Status | $STATUS |
| Postgres / WAL-G | $PG_VERSION / $(docker run --rm --entrypoint wal-g "$PG_IMAGE" --version | awk '{print $3}') |
| Tujuan | MinIO terpisah, kredensial khusus backup, enkripsi libsodium + brotli |
| Objek off-node | $OBJECTS (berkas memuat teks tabel: $PLAINTEXT) |
| Kunci salah | $WRONG_KEY |
| Durasi base backup | ${BASE_SECONDS}s |
| Arsip WAL | $ARCHIVE |
| Target PITR | $TARGET |
| Baris terakhir pulih | $LATEST |
| Isi tabel pulih | $COUNTS (diharapkan after-base=250 before-base=500; batch insiden & DELETE tidak ikut) |
| Migrasi | primary $EXPECTED_MIGRATIONS → restore $RESTORED_MIGRATIONS |
| Tabel public pulih | $TABLES |
| RTO terukur (fetch + replay WAL + promote) | ${RTO}s |
REPORT
[ "$STATUS" = PASS ]
