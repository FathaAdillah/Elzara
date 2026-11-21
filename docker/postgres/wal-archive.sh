#!/bin/sh
# archive_command Postgres. Exit non-zero menahan segmen WAL di node sampai arsip off-node berhasil;
# jangan pernah "exit 0" diam-diam di produksi karena itu membuang segmen dari rantai PITR.
set -eu
case "${ELZARA_BACKUP_MODE:-required}" in
  off) exit 0 ;; # hanya profil local: tidak ada tujuan off-node.
  required) ;;
  *) echo "ELZARA_BACKUP_MODE harus required|off" >&2; exit 2 ;;
esac
: "${WALG_S3_PREFIX:?WALG_S3_PREFIX wajib diisi saat ELZARA_BACKUP_MODE=required}"
: "${WALG_LIBSODIUM_KEY:?WALG_LIBSODIUM_KEY wajib diisi: backup dienkripsi sebelum meninggalkan node}"
exec wal-g wal-push "$1"
