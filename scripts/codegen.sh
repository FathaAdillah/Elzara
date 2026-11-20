#!/usr/bin/env bash
# Regenerate packages/api-contracts/generated/ dari openapi.yaml di dalam node:22 - tidak ada npm install di host (Doc 01 §11.2).
# Berhenti bila generated/ berubah padahal openapi.yaml tidak: hasil generate tidak boleh disunting tangan (D-S0-5, ADR-010 K-3).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PKG="packages/api-contracts"
cd "${ROOT}"
ROOT_HOST="$(pwd -W 2>/dev/null || pwd)"
export MSYS_NO_PATHCONV=1

docker run --rm \
  --volume "${ROOT_HOST}:/repo" \
  --workdir /repo/${PKG} \
  --volume elzara-codegen-modules:/repo/${PKG}/node_modules \
  --volume elzara-codegen-cache:/root/.npm \
  node:22-alpine sh -ec '
    npm install --no-audit --no-fund --no-package-lock --ignore-scripts
    npx openapi-typescript openapi.yaml -o generated/client.ts
    npx --no-install spectral lint -r .spectral.yaml openapi.yaml
  '

# Gerbang K-3: diff terhadap HEAD, termasuk berkas baru yang belum di-stage.
touched_generated="$(git status --porcelain -- ${PKG}/generated)"
touched_spec="$(git status --porcelain -- ${PKG}/openapi.yaml)"

if [ -n "${touched_generated}" ] && [ -z "${touched_spec}" ]; then
  echo "generated/ berubah padahal openapi.yaml tidak - perbaiki spesifikasi, jangan sunting hasil generate." >&2
  exit 1
fi

echo "klien TypeScript sinkron dengan openapi.yaml; stage keduanya dalam satu commit."
