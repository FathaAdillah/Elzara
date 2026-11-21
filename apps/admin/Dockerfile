# syntax=docker/dockerfile:1
FROM node:22 AS build
ARG NUXT_PUBLIC_BRAND_THEME=admin
ENV NUXT_TELEMETRY_DISABLED=1 NUXT_PUBLIC_BRAND_THEME=${NUXT_PUBLIC_BRAND_THEME}
WORKDIR /app/apps/admin
COPY apps/admin/package.json apps/admin/package-lock.json apps/admin/.npmrc ./
COPY packages/api-contracts /app/packages/api-contracts
COPY packages/ui /app/packages/ui
RUN --mount=type=cache,target=/root/.npm npm ci --no-audit --no-fund
COPY apps/admin/ ./
RUN npm run typecheck && npm run build

FROM node:22 AS runtime
ENV NODE_ENV=production NITRO_HOST=0.0.0.0 NITRO_PORT=3000
WORKDIR /app
COPY --from=build --chown=node:node /app/apps/admin/.output ./.output
USER node
EXPOSE 3000
HEALTHCHECK --interval=10s --timeout=3s --retries=10 \
  CMD node -e 'fetch("http://127.0.0.1:3000/health").then(r=>process.exit(r.ok?0:1)).catch(()=>process.exit(1))'
CMD ["node", ".output/server/index.mjs"]
