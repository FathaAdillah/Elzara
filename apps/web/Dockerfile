# syntax=docker/dockerfile:1
# apps/web — image MANDIRI (ADR-010 K-1: satu repo, tiga artefak; Doc 04 §12: tidak ada
# build gabungan yang membuat bundle admin ikut ter-cache di CDN publik — dan sebaliknya,
# storefront tidak pernah membawa kode admin).
#
# Konteks build HARUS root repo, bukan folder app:
#   docker build -f apps/web/Dockerfile .
# alasannya `file:../../packages/*` di package.json — @elzara/ui, @elzara/shared-types
# dan klien ter-generate @elzara/api-client (folder packages/api-contracts) adalah paket
# lokal, jadi posisinya harus
# sama seperti di repo. Ini BUKAN npm workspace: tidak ada `workspaces` di root dan app
# ini punya package-lock.json sendiri, sehingga lockfile web dan lockfile admin tetap
# dua dan boleh di-deploy terpisah (Doc 01 §11.2, ADR-010 K-2).
#
# `node:22` dipakai sebagai basis build DAN runtime; build tidak pernah berjalan di Node
# host (Doc 01 §11.2), makanya kerangka ini di-commit tanpa node_modules dan lockfile
# dibangkitkan di dalam container:
#   docker run --rm -v "$PWD:/w" -w /w/apps/web node:22 npm install

# --- deps: resolve `file:` lewat struktur path repo, hanya lockfile app ini
FROM node:22 AS deps
WORKDIR /app/apps/web
COPY apps/web/package.json apps/web/package-lock.json apps/web/.npmrc ./
COPY packages /app/packages
RUN npm ci

# --- build: `next build` dengan output standalone (Doc 01 §11.1/§11.2)
FROM node:22 AS build
ARG NEXT_PUBLIC_API_BASE_URL=/api/v1
ARG NEXT_PUBLIC_BRAND_THEME=brand
ARG NEXT_PUBLIC_SITE_URL=http://localhost
ARG MEDIA_PUBLIC_ORIGIN=http://localhost/storage
ENV NEXT_TELEMETRY_DISABLED=1 \
    NEXT_PUBLIC_API_BASE_URL=${NEXT_PUBLIC_API_BASE_URL} \
    NEXT_PUBLIC_BRAND_THEME=${NEXT_PUBLIC_BRAND_THEME} \
    NEXT_PUBLIC_SITE_URL=${NEXT_PUBLIC_SITE_URL} \
    MEDIA_PUBLIC_ORIGIN=${MEDIA_PUBLIC_ORIGIN}
WORKDIR /app/apps/web
COPY --from=deps /app/apps/web/node_modules ./node_modules
COPY --from=deps /app/packages /app/packages
# --exclude: image tidak pernah berisi `.env`, kredensial, atau data (Doc 01 §11.2/§9.3).
COPY --exclude=".env*" apps/web/ ./
RUN npm run build

# --- runtime: standalone saja, non-root, tanpa source dan tanpa node_modules penuh
FROM node:22 AS runtime
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0 \
    NEXT_HEALTH_URL=http://127.0.0.1:3000/health
WORKDIR /app
COPY --from=build /app/apps/web/.next/standalone ./
COPY --from=build /app/apps/web/.next/static ./.next/static
COPY --from=build /app/apps/web/public ./public
USER node
EXPOSE 3000
# /health tidak ikut aturan privat supaya probe tidak pernah memicu redirect.
HEALTHCHECK --interval=10s --timeout=3s --retries=10 \
  CMD node -e 'fetch(process.env.NEXT_HEALTH_URL).then(r=>process.exit(r.status<500?0:1)).catch(()=>process.exit(1))'
CMD ["node", "server.js"]
