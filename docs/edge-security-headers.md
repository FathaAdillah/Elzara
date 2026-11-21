# Edge security headers (P0.17)

`docker/nginx/security.inc` owns the common response headers, including errors
(`always`). HSTS, nosniff, DENY framing, strict-origin-when-cross-origin and
restricted browser permissions follow Doc 01 security and Doc 08 section 6.
Proxy/FastCGI copies of those headers are hidden to prevent duplication.

HSTS is exactly `max-age=63072000; includeSubDomains; preload` on HTTPS listeners,
including the private Dokploy port 8080 behind Traefik. Local HTTP omits HSTS.
Deploy only under domains whose subdomains all support HTTPS; preload registration
is an operator decision. Never expose the private Dokploy listener as public HTTP.

## Per-request frontend nonces

Sprint 0 row 0.16 requires `script-src 'self' 'nonce-…'` for each frontend.
Neither frontend allows `unsafe-inline` or `unsafe-eval` for scripts.

- Storefront `apps/web/middleware.ts` generates a cryptographically random nonce
  for every page request. It overwrites the incoming CSP and `x-nonce`, forwards
  the policy to Next's renderer, and emits that same policy on the response.
  Next applies the nonce to framework/bootstrap and hydration scripts. The root
  layout forces dynamic rendering: static HTML cannot safely reuse a nonce.
  Existing private-route Cache-Control behavior is preserved.
- Admin `apps/admin/server/plugins/security-headers.ts` generates a random nonce
  in Nitro's `render:response` hook, annotates every SSR script (including payload,
  inline bootstrap and external scripts), and emits the corresponding CSP.
- Nonced HTML is not cacheable. Static assets retain their app-owned cache policy.
- Frontend CSP passes through nginx. If upstream emits none (static assets,
  redirects, edge errors), nginx supplies a fallback with `script-src 'self'`,
  never an inline-script exemption. API, CSRF, storage and health responses use
  edge-owned `default-src 'none'`; upstream CSP is hidden on API/storage routes.
  There is exactly one CSP header per response.

The storage location re-includes the common security snippet alongside its local
CSP hide directive: nginx does not inherit a parent's `proxy_hide_header` list
when a location defines its own. This prevents MinIO's HSTS/nosniff headers from
leaking through or duplicating edge-owned headers. The header script checks a
403 missing-object response on `/storage/` for both hostnames, including no HSTS
on HTTP and exactly one HSTS on HTTPS-mode listeners.

Inline styles remain permitted for React style props, Nuxt UI and chart positioning.
Fonts and scripts are self-hosted. Images allow self/data; admin permits blob
previews too. Media images and upload connections allow the origin extracted from
`MINIO_PUBLIC_ENDPOINT`, including custom `storage.localhost` and ports. Compose
passes this value to Next as `MEDIA_PUBLIC_ORIGIN`, to Nuxt as
`NUXT_MEDIA_PUBLIC_ORIGIN`, and to nginx. Browser API requests use same-origin
`/api/v1`, covered by `connect-src 'self'`. Cross-origin APIs, payment scripts and
external fonts require explicit review/allowlisting before enabling them.

## Verification

After starting the stack (see `docker/README.md`):

```sh
bash scripts/validate-edge-nginx.sh
docker compose --env-file docker/.env -f docker/compose.local.yaml exec nginx nginx -t
python3 scripts/check-edge-headers.py --base-url http://localhost --web-host localhost --admin-host admin.localhost --media-origin http://localhost
```

The `edge` CI workflow validates both local and Dokploy nginx listeners. The HTTP
script checks one copy of each header without following redirects, including
missing pages, 401 API responses and empty 204 CSRF responses. For frontend HTML
it checks script nonce presence/matching, absence of script unsafe-inline/eval,
no-store caching, and fresh nonces across requests. It also checks edge fallback
CSP on a real frontend script asset. Use `--https-edge` against the private
Dokploy listener or real HTTPS edge to require HSTS.

Production browser verification must load storefront `/` and admin `/login`,
record zero CSP violations and exercise hydration (storefront skip-to-content
focus and admin password visibility toggle). Authenticated dashboards, uploads
and payment flows are outside this smoke check.

### Local environment caveats

The default Next Turbopack build previously failed resolving
`@elzara/ui/tokens.css` despite the export being present after `npm ci`;
`next build --webpack` is the local verification equivalent, not proof of a
successful default Docker frontend build.

Cold admin navigation can hit the existing 15 requests/s, burst 30 limit when
Nuxt loads parallel chunks. Those 429 responses can cause CSS MIME rejection.
Browser verification paces requests below that limit; asset rate-limit separation
is follow-up work, not part of this header change.

Nonce verification used production Next (webpack) and Nuxt builds behind Docker
nginx, plus the Laravel FPM image with array sessions/cache. Both HTTP and
Dokploy HTTPS-mode header checks passed, including fresh nonces on both apps,
matching nonces on every SSR script, and fallback policies on real script assets.
Headless Chrome reported zero CSP violations after exercising the React skip link
and Vue password visibility toggle. Both app typecheck commands exited zero;
Nuxt also logged existing dependency/deprecation diagnostics (including the
vue-router Volar export warning). Neither app currently defines a test runner.
