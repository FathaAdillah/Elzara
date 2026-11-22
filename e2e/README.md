# Storefront journeys (P0.23)

Prerequisites: PHP 8.4 with the extensions in `.github/workflows/e2e.yml`, Composer,
Node 22/npm, Docker, curl, OpenSSL and Linux `setsid`/`timeout`. Install Chromium
OS dependencies once if needed: `cd e2e && npm ci && npx playwright install --with-deps chromium`.

From the repository root:

```sh
scripts/e2e-local.sh
```

The runner starts disposable PostgreSQL 16/Redis 7 containers on random ports,
builds a **production** Next storefront, migrates and seeds a fresh database,
starts Laravel and the storefront, and runs Chromium through a same-origin HTTP
proxy at `http://127.0.0.1:3080`. Ports 3080, 3000 and 8081 must be free. The proxy
forwards `/api/*` and `/sanctum/*` to Laravel and supplies a generated server-only
`X-Elzara-Storefront-Key` (at least 32 characters); the rest goes to Next. No app
code, dev-mode CSP exceptions, real payments, Elasticsearch or MinIO are needed.

The API `.env` is temporarily moved aside and restored on exit. Next's generated
`next-env.d.ts` and `tsconfig.json` rewrites are also restored. Containers and
server process groups are cleaned up. Logs remain under `e2e/logs/run.*`.
Do not interrupt with SIGKILL: shell exit cleanup cannot run in that case.

CI uses exactly this script with `E2E_EXTERNAL_SERVICES=1`, `DB_PORT` and
`REDIS_PORT`, pointing to its job services. **This mode runs `migrate:fresh` on
the dedicated `elzara_e2e` database** at localhost with the disposable service
credentials in the workflow; never point it at shared data.

## Honest coverage of master

- **RTS:** home → catalog → Lumen Fox Plush → Mint/S variant → anonymous add/login
  redirect → demo login → reselect/add → authenticated cart (also read through
  the API) → checkout address → shipping quote → select JNE → server total.
  Master has no guest-cart replay, so the add must be repeated after login.
  `Bayar` is asserted disabled. Order placement/fake payment is a separate
  `test.fixme`, pending PR #38; no dependency on PRs #35–#38.
- **PO:** `/preorder/e2e-batch` currently only echoes its URL identifier. The test
  checks that shell honestly, then exercises the seeded Nova Keychain PO PDP,
  add-to-cart and pre-order cart grouping. There is **no batch table, endpoint or
  batch-detail rendering on master**, so inventing a seeded batch would require
  migrations/production features outside this task. Quota/ETA/enrollment are
  explicitly `test.fixme`, not reported as covered.
- **Request Order:** real catalog mode filter → isolated E2E Request Charm card
  and Quote/Request badge → PDP request information. Request submission,
  operator quote and payment are `test.fixme`: no such flow is on master.

`E2eSeeder` calls Reference → DemoAccount → DemoCatalog → DemoShipping and adds
only the missing request product. Media-less demo products use the storefront's
existing fallback images. Each script run uses a fresh DB. The demo account is
`demo.customer@elzara.test`; `DEMO_PASSWORD` can override the disposable default.

## Reports and recordings

- `e2e/playwright-report/index.html` — HTML report (`cd e2e && npx playwright show-report`).
- `e2e/test-results/**/video.webm` — every executed test, including passes.
- `e2e/test-results/**/test-finished-*.png` — screenshots on success/failure.
- `e2e/test-results/**/trace.zip` — failures only (`trace: retain-on-failure`).

CI uploads the report, test results and stack logs **always**, retaining them for
14 days. Fixme tests are visible as skipped; they are not silently removed.
For an already-running equivalent stack: `cd e2e && E2E_BASE_URL=http://127.0.0.1:3080 npm test`.
