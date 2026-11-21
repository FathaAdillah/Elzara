# syntax=docker/dockerfile:1
# Postgres 16 + WAL-G untuk base backup dan arsip WAL terenkripsi ke tujuan off-node (Doc 11 §8.6, runbook backup-restore-drill).
# Tetap alpine seperti image sebelumnya: ganti ke glibc mengubah collation dan merusak index teks pada volume yang sudah ada.
# Rilis WAL-G hanya untuk glibc, jadi binari dibangun dari tag rilis yang dipin.
FROM golang:1.27-alpine AS walg
ARG WALG_VERSION=v3.0.10
RUN apk add --no-cache build-base git brotli-dev libsodium-dev
RUN --mount=type=cache,target=/go/pkg/mod --mount=type=cache,target=/root/.cache/go-build \
    git clone --depth 1 --branch "${WALG_VERSION}" https://github.com/wal-g/wal-g.git /src \
    && cd /src \
    && CGO_ENABLED=1 go build -tags "brotli libsodium" -o /out/wal-g \
       -ldflags "-s -w -X github.com/wal-g/wal-g/cmd/pg.walgVersion=${WALG_VERSION}" ./main/pg

FROM postgres:16-alpine
RUN apk add --no-cache brotli-libs libsodium ca-certificates
COPY --from=walg /out/wal-g /usr/local/bin/wal-g
COPY docker/postgres/wal-archive.sh docker/postgres/backup-loop.sh /usr/local/bin/
RUN chmod 0755 /usr/local/bin/wal-archive.sh /usr/local/bin/backup-loop.sh && wal-g --version
