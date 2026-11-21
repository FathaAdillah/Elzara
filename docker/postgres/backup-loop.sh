#!/bin/sh
# Sidecar base backup harian: wal-g backup-push lalu retensi. Status terakhir ditulis ke /tmp untuk healthcheck.
set -eu
: "${WALG_S3_PREFIX:?WALG_S3_PREFIX wajib diisi}"
: "${WALG_LIBSODIUM_KEY:?WALG_LIBSODIUM_KEY wajib diisi}"
hour="${BACKUP_HOUR_UTC:-19}"
retain="${WALG_RETAIN_FULL:-7}"
pgdata="${PGDATA:-/var/lib/postgresql/data}"

run_backup() {
  started=$(date +%s)
  echo "{\"event\":\"backup.started\",\"at\":${started}}"
  if wal-g backup-push "$pgdata" && wal-g delete retain FULL "$retain" --confirm; then
    date +%s > /tmp/last-backup-success
    echo "{\"event\":\"backup.succeeded\",\"seconds\":$(( $(date +%s) - started ))}"
  else
    echo "{\"event\":\"backup.failed\",\"seconds\":$(( $(date +%s) - started ))}" >&2
  fi
}

date +%s > /tmp/backup-loop-started
[ "${BACKUP_ON_START:-false}" = "true" ] && run_backup
while :; do
  now=$(date -u +%s)
  next=$(date -u -d "$(date -u +%Y-%m-%d) ${hour}:00:00" +%s 2>/dev/null || date -u -D '%Y-%m-%d %H:%M:%S' -d "$(date -u +%Y-%m-%d) ${hour}:00:00" +%s)
  [ "$next" -le "$now" ] && next=$(( next + 86400 ))
  sleep $(( next - now ))
  run_backup
done
