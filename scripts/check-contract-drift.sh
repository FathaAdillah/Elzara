#!/usr/bin/env bash
# Gerbang drift kontrak untuk CI: generate ulang klien ke direktori sementara, lalu bandingkan isinya dengan generated/ yang di-commit.
# Merah berarti klien yang di-commit usang terhadap openapi.yaml (atau hasil generate disunting tangan) - D-S0-5, ADR-010 K-3.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PKG="packages/api-contracts"
cd "${ROOT}"
ROOT_HOST="$(pwd -W 2>/dev/null || pwd)"
export MSYS_NO_PATHCONV=1

if [ ! -f "${PKG}/generated/client.ts" ]; then
  echo "${PKG}/generated/client.ts belum ada - hasil generate harus di-commit (D-S0-5)." >&2
  exit 1
fi

TMP="$(mktemp -d)"
# Docker Desktop hanya bisa mount path Windows; di host Linux baris ini jatuh ke pwd biasa.
TMP_HOST="$(cd "${TMP}" && (pwd -W 2>/dev/null || pwd))"
trap 'rm -rf "${TMP}"' EXIT

docker run --rm \
  --volume "${TMP_HOST}:/out" \
  --volume "${ROOT_HOST}:/repo" \
  --workdir /repo/${PKG} \
  --volume elzara-codegen-modules:/repo/${PKG}/node_modules \
  --volume elzara-codegen-cache:/root/.npm \
  node:22-alpine sh -ec '
    npm install --no-audit --no-fund --no-package-lock --ignore-scripts
    npx openapi-typescript openapi.yaml -o /out/client.ts
  '

if ! diff -u "${PKG}/generated/client.ts" "${TMP}/client.ts"; then
  echo "kontrak drift: regenerate dengan bash scripts/codegen.sh lalu commit bersama openapi.yaml." >&2
  exit 1
fi

echo "tidak ada drift kontrak antara openapi.yaml dan generated/."
