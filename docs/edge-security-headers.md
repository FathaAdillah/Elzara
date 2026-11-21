# Edge security headers (P0.17)

`docker/nginx/security.inc` owns the response headers, including upstream errors
(`always`). Proxy and FastCGI security headers are hidden to prevent duplicate
policies. `edge.conf.template` selects independent storefront and admin policies
by the same upstream selection as routing; API, CSRF, health and storage responses
use `default-src 'none'`. No frontend header configuration is needed.

The baseline follows Doc 01 security and Doc 08 section 6: DENY framing,
nosniff, strict-origin-when-cross-origin and restricted browser permissions.
HSTS is exactly `max-age=63072000; includeSubDomains; preload` on the HTTPS
listeners (including private Dokploy port 8080 behind Traefik), and omitted on
local HTTP. Deploy only under domains whose subdomains all support HTTPS; preload
registration is an operator decision. Never publish the private Dokploy listener
as public HTTP. Cache policy remains app-owned so immutable assets can be cached.

## Explicit hydration exception

Both production SSR frontends currently require inline hydration scripts: Next
App Router emits inline Flight/bootstrap scripts, and Nuxt emits its payload and
configuration bootstrap. Their policies therefore extend `script-src 'self'`
with **`'unsafe-inline'`**, not `unsafe-eval`. This is a documented weakening of
the strict baseline, not full XSS prevention. An edge-generated nonce alone would
not annotate these scripts, and body substitution would be fragile with streamed
or compressed HTML. A future nonce migration must integrate both renderers and
make Next pages dynamic rather than just adding a nonce header to static HTML.
Inline styles are needed for React style props, Nuxt UI and chart positioning.

Fonts and scripts are self-hosted. Images allow self and data URLs; admin also
allows blob previews. Media images and upload connections permit only the origin
extracted from `MINIO_PUBLIC_ENDPOINT` (including custom `storage.localhost` and
ports). API calls use the same-origin `/api/v1` edge route, so `connect-src 'self'`
covers the actual browser API origin on each hostname. Cross-origin API, payment
scripts or external fonts must be reviewed and allowlisted before enabling them;
no wildcard HTTPS or payment origin is pre-authorized.

## Verification

After starting the stack (see `docker/README.md`):

```sh
docker compose --env-file docker/.env -f docker/compose.local.yaml exec nginx nginx -t
python3 scripts/check-edge-headers.py --base-url http://localhost --web-host localhost --admin-host admin.localhost
```

For Dokploy, run from inside the private network against `http://nginx:8080`
with real Host values and `--https-edge`. The script deliberately does not follow
redirects; it checks one copy of every header on public/admin pages, redirects,
missing pages, API errors and CSRF responses, and rejects server errors.
Also load `/` on storefront and `/login` on admin in a production-build browser
and check the console for CSP violations. Development HMR/eval is not allowlisted.
The `edge` CI workflow runs `bash scripts/validate-edge-nginx.sh` for both local
and Dokploy listeners. Existing app workflows deploy independently and do not
start a shared stack; the HTTP entry-point check is run against a started stack
rather than a fake app CI smoke.

### Local evidence (2026-11-21)

Nginx 1.27 ran in Docker with the committed listeners/template/snippets, routing
to production Next (Node 22, webpack build), production Nuxt and the built Laravel
FPM image. Laravel used array sessions/cache; no database or full Compose stack
was needed for the unauthenticated paths. Both local HTTP and Dokploy HTTPS-mode
listeners passed the header script, including 302, 401, 404 and empty 204 responses.
Both listeners passed `nginx -t` through the CI script.

The default Next Turbopack build failed resolving `@elzara/ui/tokens.css` despite
the export being present after `npm ci`; `next build --webpack` succeeded. This
verification does not claim the default frontend Docker build passed.

Cold admin navigation also exposed the existing admin rate limit (15 requests/s,
burst 30): parallel Nuxt chunk requests received 429, including the login CSS
which Chrome rejected as HTML MIME. The browser CSP check paces requests below
that limit; this is not a fix for the existing cold-load issue. Asset rate-limit
separation remains follow-up work, outside this header change.

Headless Chrome loaded storefront `/` and admin `/login` through port 8080 with
request pacing: both recorded zero `securitypolicyviolation` events and zero CSP
console errors. This verifies the public landing and unauthenticated admin login,
not authenticated dashboards, uploads or payment flows.
