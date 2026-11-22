# Storefront journeys (P0.23)

Prasyarat host: Docker Engine dengan plugin Compose v2+, Bash dan utilitas shell
standar. Tidak perlu PHP, Composer, Node, npm, browser, curl atau OpenSSL di host.
Docker Desktop harus memakai Linux containers; jalankan skrip dari Bash/WSL.

From the repository root:

```sh
scripts/e2e-local.sh
```

Satu jalur container dipakai lokal dan CI (`.github/workflows/e2e.yml`):

- `node:22` menyalin sumber read-only ke volume kerja sementara (tanpa `.env*`,
  `.git`, dependensi, hasil build dan artefak lama), membuat kunci acak, menjalankan
  `npm ci` dan build Next **production**, lalu melayani storefront dan proxy.
- Stage `composer-tools` dari `apps/api/Dockerfile` dipakai ulang: PHP 8.4 dengan
  ekstensi API yang sama dan Composer dari `composer:2`. `composer install`,
  migrasi, seeder dan `artisan serve` semuanya berjalan di image ini.
- PostgreSQL 16 dan Redis 7 adalah container sekali pakai pada network privat.
  `migrate:fresh` hanya menyentuh database `elzara_e2e` di network tersebut.
  Cache konfigurasi/rute/provider Laravel memakai direktori acak baru di volume
  container, bukan cache checkout host. Sebelum migrasi, koneksi efektif harus
  cocok dengan PostgreSQL E2E; URL atau override read/write ditolak.
- Playwright memakai image resmi `mcr.microsoft.com/playwright:v<versi>-noble`.
  Versinya dibaca dari `e2e/package-lock.json` melalui `node:22`, sehingga browser
  dan `@playwright/test` selalu cocok tanpa instalasi browser di host.

Browser berbagi network namespace proxy dan membuka `http://127.0.0.1:3080`
sebagai secure context localhost (dibutuhkan `crypto.randomUUID`), tanpa flag
browser yang melonggarkan keamanan. Proxy meneruskan `/api/*`
dan `/sanctum/*` ke Laravel, sisanya ke Next, dengan kunci server-only
`X-Elzara-Storefront-Key` acak (64 karakter). Tidak ada port host yang dibuka;
tidak perlu MinIO, Elasticsearch atau layanan pembayaran.

Host `.env`, `node_modules`, `vendor`, `next-env.d.ts` dan `tsconfig.json` tidak
disentuh. Laporan disalin keluar sebelum container, network dan volume kerja
dihapus pada exit, termasuk saat tes gagal. Log per run ada di
`e2e/logs/run.*`. Hindari SIGKILL: cleanup shell tidak dapat berjalan setelahnya.
Run pertama memerlukan unduhan image dan kompilasi ekstensi PHP; run berikutnya
memakai cache layer Docker. Jika interrupted paksa, hapus project yang tertinggal
dengan `docker compose -p <nama-project-di-log> -f e2e/compose.yaml down -v`;
set `E2E_SOURCE` ke root repo dan `E2E_PLAYWRIGHT_VERSION` sesuai lockfile.
Jika image API sudah dibangun dari Dockerfile yang sama, gunakan
`E2E_REUSE_API_IMAGE=1 scripts/e2e-local.sh` untuk memakai image tersebut tanpa
memeriksa metadata Docker Hub lagi (misalnya saat registry membatasi request).
Default lokal dan CI tetap membangun image; jangan pakai opsi ini setelah
Dockerfile API berubah.

## Journey coverage

- **RTS:** home → catalog → Lumen Fox Plush → Mint/S variant → anonymous add/login
  redirect → demo login → reselect/add → authenticated cart (also read through
  the API) → checkout address → shipping quote → select JNE → server total.
  Master has no guest-cart replay, so the add must be repeated after login.
  A separate RTS test repeats checkout, reserves stock and completes the Fake
  payment UI now merged from PRs #35–#38. It asserts the order/payment API
  responses, success UI and emptied cart. The API container then checks that the
  same order/invoice are paid, its Fake intent is captured and its stock
  reservations are committed in PostgreSQL. Fake payment is enabled only in the
  disposable local API environment; no real payment provider is used.
- **PO:** discover the seeded open Nova batch through the public API → login →
  batch detail (server-time countdown, ETA, quota and account limit) → add Nova
  Keychain → cart → address → checkout without courier selection → place order
  (`201`, `order_type=preorder`, deposit invoice) → exact server invoice deposit
  versus order total → Fake payment → deposit-paid UI → batch quota decreased.
  A further API-level enrollment exceeding the seeded per-account limit returns
  `422 BATCH_ACCOUNT_LIMIT_EXCEEDED` without consuming additional quota. Another
  test checks unknown-batch not-found UI. PostgreSQL verification checks the same
  artifact's enrollment `deposit_paid`, quota increment, paid deposit invoice and
  captured Fake intent. This does not claim a quota-exhaustion browser scenario.
  Deposit amount is displayed after placement from the issued invoice; the
  checkout-session API does not provide a deposit preview. No frontend deposit
  calculation is used.
- **Request Order:** real catalog mode filter → isolated E2E Request Charm card
  and Quote/Request badge → PDP request information. Request submission,
  operator quote and payment are `test.fixme`: no such flow is on master.

`E2eSeeder` calls Reference → DemoAccount → DemoCatalog → DemoPreorder → DemoShipping and adds
only the missing request product. Media-less demo products use the storefront's
existing fallback images. Each script run uses a fresh DB. The demo account is
`demo.customer@elzara.test`; `DEMO_PASSWORD` can override the disposable default.

## Reports and recordings

- `e2e/playwright-report/index.html` — HTML report; buka berkas ini di browser.
- `e2e/test-results/**/video.webm` — every executed test, including passes.
- `e2e/test-results/**/test-finished-*.png` — screenshots on success/failure.
- `e2e/test-results/**/trace.zip` — failures only (`trace: retain-on-failure`).

CI uploads the report, test results and stack logs **always**, retaining them for
14 days. Fixme tests are visible as skipped; they are not silently removed.
The full run expects **5 passed, 1 skipped**. `test-results/rts-order.json` and
`test-results/po-order.json` link the UI/API results to post-test database
verification. PO screenshots also include `po-batch.png` and `po-deposit-paid.png`.
Argumen Playwright dapat diteruskan, misalnya
`scripts/e2e-local.sh --grep RTS`. Tidak ada mode runner-tool/layanan eksternal.
