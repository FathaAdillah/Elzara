# Dokumen 03 — Rencana Sprint

**Rujukan induk:** `SNOWEGO_MICROSERVICES_BLUEPRINT.md` v1.0 — flow mengikuti Blueprint §5 (ADR-003 K-1); resolusi konflik di Doc 11. Roadmap dokumen ini mengikuti Blueprint §17; perhitungan jadwal ada di Doc 11 §5 dan pertanyaan klien di Doc 11 §6.

Tanggal: 2026-11-16 · direvisi 2026-11-17 mengikuti Blueprint (butir mengikat: **B-1, B-2, B-8, B-11, B-12, B-14, B-15** + §5 Doc 11) · direvisi 2026-11-18: status scaffold diverifikasi di disk, mekanisme auth diputuskan (ADR-007 (BFF/sesi) amandemen 2026-11-18 + ADR-012 (RBAC/identitas/dependensi)), Q-2 dipersempit, task instalasi dependensi container-only ditambahkan (0.17)
Tim: 1–2 dev (full-stack) + klien sebagai penyedia konten/keputusan.
Cadence: sprint 2 minggu. **Jadwal untuk cakupan Blueprint penuh = ±22–26 minggu (11–13 sprint), bukan ±18 minggu** (perhitungan: Doc 11 §5). Angka 18 minggu hanya bertahan bila salah satu opsi **J-b/J-c** di §2 disetujui klien; pilihan jadwal adalah **keputusan terbuka Q-1 dan belum diambil**. Baseline dokumen ini = **J-a**.

## 0. Cara pakai dokumen ini

Dokumen ini adalah roadmap + gate, bukan pengganti plan implementasi. Roadmap resmi Blueprint memakai **Phase 0–4** (§17); dokumen ini mempertahankan **sprint 2-minggu dengan tabel task dan estimasi** supaya kerja minggu depan tetap bisa dijadwalkan. Aturan pemetaan: 1 sprint = 2 minggu; tiap Phase punya rentang sprint dari Blueprint, dan kita memilih titik di dalam rentang itu (selalu dinyatakan, tidak implisit).

- Kapasitas diasumsikan 6–8 jam efektif/dev per hari, dikurangi rapat klien dan pekerjaan konten. Task di bawah = 2 dev; dengan 1 dev isi sprint yang sama tetap valid tetapi kalender bergeser ke batas atas rentang (itulah sebabnya Doc 11 §5 memberi pita 22–26, bukan satu angka).
- Tiap sprint dimulai dengan satu spec (`docs/specs/<sprint>-<topik>.md`) maksimal 2 halaman, disetujui klien, lalu dipecah ke task.
- Estimasi adalah kasar dan disengaja: Blueprint §17 juga memberi rentang sprint, bukan angka jaminan.

## 1. Peta Phase → Sprint (Blueprint §17)

| Phase Blueprint | Isi | Rentang sprint Blueprint | Sprint kita | Minggu | Titik yang kita ambil & alasannya |
|---|---|---|---|---|---|
| **0 — Architecture foundation** | monorepo, ADR, kontrak API, CI/CD, Docker dev; modul Laravel + migrasi + Redis + MinIO + auth + RBAC; OTel, log terstruktur, backup, staging | 1–2 | Sprint 0–1 | 1–4 | **2** (maksimum): tiga aplikasi yang di-deploy mandiri + pipeline OpenAPI + Testcontainers + RBAC 10 role bukan pekerjaan 1 sprint (B-1/B-2/B-10/B-11) |
| **1 — Ready to Ship MVP** | katalog, varian, stok, customer, cart, checkout; reservasi atomik + TTL, idempotency, webhook; admin order, packing, shipment, tracking; test concurrency & race pembayaran | 3–5 | Sprint 2–5 | 5–12 | **4**: 3 tidak cukup karena model stok agregat + ledger + gerbang §16 ditambahkan (B-5/B-6/B-11) |
| **2 — Pre-Order / Group Order** | lifecycle batch, atomisitas kuota, DP/full; konsolidasi, PO supplier, inbound, invoice tambahan; dashboard batch, ETA, penanganan exception | 2–4 | Sprint 6–8 | 13–18 | **3**: RS-1/RS-2 per-buyer dan `intl_fees` saat GRN menaikkan satu sprint di atas minimum |
| **3 — Request Order** | upload via MinIO, sourcing, quotation berversi; approval, konversi, procurement, fulfillment | 2–3 | Sprint 9–10 | 19–22 | **2** (minimum) — ini baris yang paling mungkin bertambah jadi 3; kalau itu terjadi, kalender = 24 minggu |
| **4 — Scale & advanced operations** | Waiting Room sesuai kapasitas teruji; multi-warehouse, landed cost, reporting, refund, rekonsiliasi; outbox/inbox penuh, ekstraksi service selektif; autoscaling, SLO, DR, security review | berkelanjutan | Sprint 11+ | 23+ | **di luar** angka 22–26; berjalan terus, tetapi artefak yang diminta Blueprint (test edge, runbook) dijadwalkan eksplisit di §7 dan §10 |

Rentang Blueprint untuk Phase 0–3 adalah **minimum 8 sprint (16 minggu) sampai maksimum 14 sprint (28 minggu)** — angka itu yang dipakai Doc 11 §5. Baseline kami ada di tengah-pita: total Phase 0–3 = **11 sprint = 22 minggu**; buffer dua sprint (Phase 3 +1, Phase 2 +1) = **13 sprint = 26 minggu**, sehingga kedua ujung rencana ini tetap di dalam rentang Blueprint dan di dalam pita Doc 11 §5 (22–26 minggu / 11–13 sprint).

## 2. Jadwal: angka jujur dan tiga opsi

**Fakta yang harus disampaikan ke klien, tidak dibungkus:** plan versi 2026-11-16 berjanji ±18 minggu. Angka itu dihitung di atas cakupan yang lebih kecil daripada Blueprint. Pekerjaan yang tidak ada dalam 18 minggu itu: aplikasi Next.js kedua (admin) + BFF (catatan 2026-11-18: BFF tidak lagi wajib — mekanisme sesi diputuskan lewat ADR-007 (BFF/sesi) amandemen 2026-11-18; perhitungan Doc 11 §5 tidak berubah) + client TS hasil-generate (B-1/B-2), `packages/api-contracts` + OpenAPI sebagai gerbang PR (B-2), model stok agregat + transfer + stocktake (B-5), tes concurrency & load nyata dengan Testcontainers/k6 (B-11), empat sumbu status + tes state machine per transisi (B-3), landed cost/customs/forwarder, Waiting Room + tes bypass (B-8), threat model + 11 runbook (B-14/B-12). Rinciannya ada di Doc 11 §5 — tidak diulang dan tidak diubah di sini.

| Opsi | Isi | Kalender | Yang hilang / yang dilanggar | Status |
|---|---|---|---|---|
| **J-a** | Terima cakupan Blueprint penuh | **22–26 minggu** (11–13 sprint) | tidak ada | **baseline dokumen ini** |
| **J-b** | Tahan 18 minggu dengan memotong **Phase 4** (Waiting Room, multi-warehouse, landed cost otomatis, ekstraksi service) **dan** aplikasi admin Next.js terpisah → admin jadi route group Inertia sampai Sprint 10 | ±18 minggu | B-1, multi-warehouse B-5, B-8 — **Blueprint dilanggar sebagian dan itu harus dinyatakan ke klien, bukan disembunyikan** | menunggu Q-1 |
| **J-c** | Tahan 18–20 minggu dengan meluncurkan **Phase 0–2 lebih dulu** (RTS + Pre-order penuh, ±18–20 minggu) lalu menyusulkan **Phase 3 (Request Order)** dan jalur `marketplace_purchase` | 18–20 minggu untuk tanggal pertama; Request Order ±4–6 minggu kemudian | Blueprint §5.3 ditunda, bukan dibuang — konsisten dengan urutan potongan Doc 03 §12 butir 2 versi lama | menunggu Q-1 |

**Rekomendasi (bukan keputusan): J-a untuk cakupan, J-c untuk tanggal pertama.** Dua opsi ini menjawab pertanyaan yang berbeda (kualitas vs waktu); klien memilih salah satu atau kombinasi (J-c dengan janji Phase 3 tertanggal). **Kami tidak memilih diam-diam** — ini tercatat sebagai **Q-1 di Doc 11 §6 dan tetap terbuka sampai ada jawaban tertulis.** Konsekuensi kalau tidak dijawab: Sprint 0–1 tetap jalan (semua opsi butuh Phase 0), tetapi gate Phase 2/Phase 3 tidak bisa dipakai untuk komitmen tanggal.

## 3. Phase 0 — Fondasi (Sprint 0–1, minggu 1–4)

### Sprint 0 — Monorepo, platform, kontrak (±24 dev-day)

**Status 2026-11-18 (diverifikasi lewat `ls`/`grep` di disk, bukan klaim):** scaffold Sprint 0 sudah ada — `apps/api` (22 modul + `app/Platform` + `app/ModuleLoader` glob M-2 + 1 migrasi reliability `0001_Platform_create_reliability_tables.php`), `apps/admin`/`apps/web` (Next.js + middleware + `lib/api.ts`), `packages/api-contracts/openapi.yaml` (18 operationId), `docker/compose.yaml` + profiles + `minio/init-buckets.sh` + `nginx/dev.conf`, 4 workflow CI, `scripts/` lengkap, 12 berkas ADR, 16 runbook, draf `docs/threat-model.md`. **Yang belum:** ketiga lockfile tidak ada, `vendor/`/`node_modules/` tidak ada (nol dependensi terpasang), client TS belum di-generate (`generated/` kosong), migrasi modul masih `.gitkeep`, hanya 1 test (`ModuleLoaderTest`) dan **belum pernah ada suite yang dijalankan**, belum ada commit. Kolom Catatan di bawah menandai per task: **scaffold** (artefak ada), **parsial** (sisa disebutkan), **belum**. Gelombang dependensi + auth 2026-11-18 direncanakan terpisah di `.qoder/plans/2026-11-18-*.md` (sedang ditulis agen lain); peran agen ada di `.qoder/agents/` dengan urutan mengikat: **migrasi DB → fitur → testing → rebuild docker** (Doc 11 §4.4 H).

| ID | Task | Est | Catatan |
|---|---|---|---|
| 0.1 | Scaffold monorepo: `apps/api` (Laravel versi stabil yang kompatibel, PHP supported), `apps/admin` (Next.js+TS), `apps/web` (Next.js+TS), `packages/api-contracts`, `packages/shared-types`, `infrastructure/`, `docs/adr`, `docs/runbooks` | 2 d | **menggantikan** task 0.1 lama ("Init repo Laravel + Inertia + React, satu app") — dibatalkan ADR-003 K-2 / B-1. **Scaffold 2026-11-18:** semua direktori + skeleton ada di disk; `composer.json` masih menyimpang dari Doc 11 §4.4 D (predis tertulis padahal keputusan = ekstensi phpredis; sanctum/spatie/socialite/scramble/sentry/excel belum masuk; phpunit masih ^12.5 padahal baseline 13.4.7) — perbaikan manifest + lockfile masuk 0.17 |
| 0.2 | Docker dev (`docker/compose.yaml` + profiles): PostgreSQL, Redis, MinIO, Mailpit, Adminer, dan Elasticsearch Basic melalui profil opsional `search` + pembungkus perintah `composer dev:up`/`dev:migrate`/`dev:test` (Makefile hanya pembungkus tipis untuk Linux/macOS — host Windows tidak punya `make`) | 1 d | satu perintah onboarding; single-node = SPOF, lihat §11. **Scaffold 2026-11-18:** compose 11 service + healthcheck + profiles + Dockerfile per app ada; **belum pernah `docker compose up` dijalankan** — build menunggu lockfile (0.17) |
| 0.3 | CI: lint + phpstan/larastan + eslint/typecheck kedua app + unit/feature test di service PostgreSQL + **harness Testcontainers** + build 3 image | 2 d | PHPUnit tetap pilihan sah (Blueprint §16 mengizinkan "Pest/PHPUnit"; D-S0-3 tidak dibatalkan) — B-11. **Parsial 2026-11-18:** 4 workflow (`.github/workflows/{api,admin,web,contracts}.yml`) ada di disk, tetapi **belum pernah hijau** — semua job butuh dependensi terpasang (0.17); harness Testcontainers belum ditulis |
| 0.4 | CD: build+push registry, deploy 3 app mandiri, maintenance mode, rollback by tag; staging lebih dulu | 1,5 d | B-1. **Belum 2026-11-18:** tidak ada workflow deploy di disk |
| 0.5 | `packages/api-contracts`: OpenAPI 3.1 sebagai source of truth + generate TS client untuk kedua app + **gerbang PR: controller berubah ⇒ spec ikut berubah** | 2 d | B-2; deliverable `docs/12-api-contract.md`. **Parsial 2026-11-18:** `openapi.yaml` ada (18 operationId terhitung via grep; 18 jalur wajib Blueprint §7 tetap wajib — gate I-7 memeriksa kehadiran, bukan total); `scripts/codegen.sh` + `check-contract-drift.sh` ada tapi client TS belum di-generate; security per operasi + securitySchemes cookie sedang ditambahkan agen lain sesuai Doc 11 §4.4 I (larangan lama dicabut); Scramble hanya alat bantu draf, bukan sumber kebenaran (§4.4 E) |
| 0.6 | Auth (mekanisme **diputuskan 2026-11-18**, ADR-007 (BFF/sesi) amandemen + ADR-012 (RBAC/identitas/dependensi)): Sanctum **session cookie same-site**, dua guard/cookie (`web`/`elzara_web_session`, `admin`/`elzara_admin_session`), **CSRF wajib semua mutasi**, tanpa BFF wajib dan tanpa token browser; register/login/reset hybrid web (email+password, Google, X via `socialiteproviders/twitter` OAuth 2.0, penautan hanya lewat email terverifikasi); login admin email+password + `is_operator=true` + **2FA wajib semua operator**; **RBAC spatie granular per aksi** (`modul.aksi`) + direct grant per user + `role_scopes`, 10 role Blueprint sebagai bundel; `Gate::before` bypass hanya `is_admin` di guard admin dan **wajib menulis `audit_logs`** (`rbac.bypass`, reason, request_id) | 2,5 d | B-10; Blueprint §12. Prasyarat kode auth Sprint 1: **sign-off threat model (P-6)**; Q-2 kini hanya "apakah klien punya IdP/SSO sendiri". **Belum 2026-11-18:** belum ada guard/policy/CSRF di disk; gelombang ini direncanakan di `.qoder/plans/2026-11-18-*.md` |
| 0.7 | Kerangka domain: konvensi exception + RFC 7807 Problem Details + request-id + state-machine runner untuk **4 sumbu** + transactional **outbox** + dispatcher + `idempotency_keys`/`inbox_events` | 2,5 d | B-3/B-7; pondasi semua sprint. `webhook_receipts` **sengaja tidak di sini** — tabel Payments L7 (FK ke `payment_intents`, Doc 09 §9) sehingga band-nya Sprint 2 `0400–0599` (Doc 10 §4.1); Sprint 0 membangun mekanisme dedup `(provider, provider_event_id)` lewat `inbox_events`. **Parsial 2026-11-18:** `ApiProblem`, `StateMachine/`, `Outbox/` (Writer/Dispatcher/QueueRouting), `Statuses/` 4 sumbu, dan migrasi trio reliability ada di disk; test transisi per sumbu + B1–B6 deptrac + suite apa pun **belum pernah dijalankan** |
| 0.8 | Design token + 3 preset tema + komponen dasar (button/input/card/badge/modal/toast/grid) di **dua app** | 2 d | `packages/ui` menyediakan token, preset, konfigurasi brand, dan primitif React untuk storefront. Admin Nuxt UI memakai token yang sama dengan default lavender terang/gelap. Lihat Doc 04 §5 untuk pengujian komponen dan kontras. |
| 0.9 | MinIO: 6 bucket (`product-media`, `request-attachments`, `invoice-documents`, `shipment-documents`, `exports`, `audit-archives`) + presigned PUT + finalize + validasi MIME/magic-byte + signed URL privat + CORS per origin | 1,5 d | B-9; **endpoint presigned harus dijangkau browser** (Blueprint §11). **Parsial 2026-11-18:** `docker/minio/init-buckets.sh` + policies + cors ada di disk; endpoint presigned/finalize/validasi MIME belum ditulis, bucket belum pernah dibuat nyata |
| 0.10 | `localizations` (ID/EN sejak awal) + `feature_flags` + kerangka `settings` admin; semua string lewat resource | 1 d | delta lama §10.5 Sprint 0, tetap berlaku. **Belum 2026-11-18:** migrasi modul masih `.gitkeep` |
| 0.11 | Seeder **Reference** (10 role + permission + flag + `localizations`, idempoten) vs **Demo** (dev/staging saja): akun operator/customer, `media` + 10 SVG orisinal, factories untuk semua tabel Sprint 0 | 1 d | dipakai semua test. **Isi demo katalog/batch/kuotasi menyusul band-nya sendiri** — `products`/`preorder_batches` lahir Sprint 1 (`0200–0399`) dan `quotations` Sprint 5 (`1000–1199`) (Doc 10 §4), jadi menanamnya di Sprint 0 berarti menulis data tanpa tabelnya. Bentuk role/permission mengikuti spatie (`name` + `guard_name='admin'`, pivot resmi) — ADR-012 (RBAC/identitas/dependensi); **belum 2026-11-18** |
| 0.12 | Observability: OpenTelemetry (trace id web→api→job), log JSON terstruktur, correlation id pada order/payment/provider event, dashboard dasar, strategi backup + restore drill PITR | 2 d | Blueprint §14/§15. **Parsial 2026-11-18:** `config/logging.php` + `infrastructure/monitoring/` ada (monitoring masih README-only sampai task render); OTel/dashboard/drill belum |
| 0.13 | `docs/threat-model.md` draf v1 (kontrol edge, auth, webhook, PII, abuse) | 1,5 d | B-14; prasyarat sign-off P-6 (Q-2 kini hanya soal IdP/SSO klien). Draf tersedia. Persetujuan kontrol sesi dikonfirmasi pada 2026-11-20. Lokasi artefak tertulis masih terbuka |
| 0.14 | ADR-004…011 dibuka; **ADR-004 (idempotensi + unique constraint + transactional outbox), ADR-008 (bucket MinIO + CDN) dan ADR-010 (monorepo, CI/CD per aplikasi, deploy mandiri)** ditutup penuh di sprint ini; **ADR-011** ditutup dengan default satu gudang dan dibuka kembali hanya kalau Q-5 menjawab multi-gudang | 1 d | nomor dan topik harus sama dengan peta Doc 11 §3 dan berkas di `docs/adr/`. **Status 2026-11-18:** total **12 berkas ADR (001–012)** ada di disk (terhitung via ls); **ADR-012 (RBAC/identitas/dependensi) ditambahkan dan Accepted** (keputusan pemilik 2026-11-18, register Doc 11 §4.4 A–G); **ADR-007 (BFF/sesi) mekanisme diputuskan 2026-11-18** — Accepted untuk mekanisme, persetujuan kontrol sesi dikonfirmasi pada 2026-11-20. Lokasi artefak dan Q-2 (IdP/SSO klien) masih terbuka; ADR-005/006/009 tetap terbuka karena butuh jawaban klien (P-1…P-6) |
| 0.15 | Keputusan klien: gateway pertama, kurir, provider WA, B-PO-1..18, daftar aset brand | (paralel) | **blocker Sprint 3** |
| 0.16 | Baseline keamanan edge: header HSTS/XFO/nosniff/Referrer/Permissions + CSP per app, diuji otomatis | 1 d | Doc 08 §6; jangan tiru celah CSP situs sumber. **Parsial 2026-11-18:** `docker/nginx/dev.conf` + middleware kedua app ada; `infrastructure/nginx/` masih README-only; uji otomatis header/CSP belum |
| 0.17 | **Instalasi dependensi — container-only, tidak pernah di host.** Langkah: (1) perbaiki `apps/api/composer.json` ke baseline Doc 11 §4.4 D (hapus predis — diganti ekstensi phpredis yang sudah ada di Dockerfile; tambah sanctum 4.5.0, spatie permission 8.5.0 / activitylog 5.1.5 / data 4.23.1, socialite 5.33.0 + socialiteproviders/twitter 4.1.2, scramble 0.13.55, sentry-laravel 4.30.0, maatwebsite/excel 4.0.7; phpunit 13.4.7, phpstan 2.3.6, qossmic/deptrac 2.0.4; horizon ^5.51 sudah mengizinkan 5.52.0); (2) `bash scripts/gen-lockfiles.sh` — menghasilkan tiga lockfile di dalam `node:22` (`npm install --package-lock-only`) dan `php:8.4-cli` (`composer update --lock`); (3) `composer require`/`composer install` tambahan **di dalam kontainer php:8.4**, bukan host; (4) `npm ci` di dalam node:22; (5) jalankan suite test pertama di dalam kontainer | 1 d | **Alasan mengikat:** spatie/laravel-activitylog 5.1.5 minta php ^8.4 dan phpunit 13.4.7 minta php >=8.4.1, sedangkan host PHP 8.3.30 — instalasi host pasti gagal resolve; lockfile host juga bukan baseline image. **Belum 2026-11-18:** ketiga lockfile + `vendor/` + `node_modules/` tidak ada di disk (diverifikasi ls); task ini prasyarat 0.2/0.3 dan seluruh gerbang test |

Gerbang akhir Sprint 0: `composer dev:up` → 3 app jalan → login admin → storefront demo → satu order `awaiting_payment` tercatat (order lahir langsung `awaiting_payment`; `draft` tidak dipakai — Doc 11 §8.3), dan replay `Idempotency-Key` yang sama menghasilkan response identik.

### Sprint 1 — Kontrak final, lingkungan, gerbang kualitas (±15 dev-day + buffer)

| ID | Task | Est | Catatan |
|---|---|---|---|
| 1.1 | Pisahkan permukaan publik vs privat: internal service tidak terekspos internet; admin di network/access policy + MFA | 1,5 d | Blueprint §13 |
| 1.2 | Konvensi API final: cursor pagination, UTC + tampilan TZ, rate limit per akun/IP, strict validation | 1,5 d | Blueprint §7 |
| 1.3 | Environment `local/staging/production` terpisah (DB, bucket, kredensial gateway, domain) + pipeline migrasi expand/contract + compatibility check | 2 d | Doc 10 §10/§11 |
| 1.4 | Matriks otorisasi: 10 role × aksi modul, diuji server-side (bukan hanya menu). Bentuk final (ADR-012 (RBAC/identitas/dependensi)): permission granular per aksi `modul.aksi` (view/create/update/delete + aksi khusus), bundel role via `role_has_permissions`, direct grant per user via `model_has_permissions`, batas lingkup via `role_scopes`; storefront = kepemilikan (ownership) + status akun, tanpa spatie | 2 d | B-10, Blueprint §19; pencabutan permission wajib invalidasi cache spatie via PermissionRegistrar (Doc 06) |
| 1.5 | Harness tes concurrency: Testcontainers + dua klien paralel + fixture stok/kuota; helper assertion "tidak pernah negatif" | 2,5 d | prasyarat gerbang §16 di Sprint 3/5/6 |
| 1.6 | Playwright scaffold 3 journey (RTS, Pre-order, Request) + artefak CI | 1,5 d | B-11 |
| 1.7 | k6 baseline: RPS katalog & checkout di staging → laporan kapasitas teruji pertama ke klien | 1,5 d | **Q-4**; prasyarat ADR-009 |
| 1.8 | Runbook kelompok A (4) — pasangan skenario ↔ file per Doc 11 §8.2: `payment-callback-late.md`, `webhook-replay.md`, `queue-stuck.md`, `emergency-disable-checkout.md` | 2 d | B-12 |
| 1.9 | ADR-005 (gateway & settlement) dan ADR-009 (Waiting Room) **dibuka, tidak ditutup** — butuh Q-7/Q-6/Q-4 | 1 d | Doc 11 §3/§6 |
| 1.10 | Sign-off threat model oleh klien — **prasyarat keras sebelum kode auth Sprint 1 ditulis** (ADR-007 (BFF/sesi) D-6a) | (paralel) | persetujuan pemilik proyek dan klien dikonfirmasi pada 2026-11-20, dengan lokasi artefak masih ditunggu. Q-2 tersisa hanya "apakah klien punya IdP/SSO sendiri" (kalau ya: tambah provider OIDC di jalur login yang sama, bukan mengganti sesi) |

Gerbang akhir Phase 0: kontrak OpenAPI 3.1 di-review klien; CI memerah bila spec tidak ikut berubah; staging bisa dipakai demo; restore drill pernah dijalankan nyata.

## 4. Phase 1 — Ready to Ship MVP (Sprint 2–5, minggu 5–12)

### Sprint 2 — Katalog, varian, stok, media (±20 dev-day)
Skema `products`/`product_variants`/`product_media`/`categories`/`brands`/`collections`/`price_lists` + varian bertingkat · CRUD admin + bulk import CSV · **`stock_items` (agregat per `warehouse_id, sku_id`) + `stock_movements` (ledger immutable) + `warehouses` + `unavailable = damaged + quarantined` dengan CHECK non-negatif** (B-5) · sinkronisasi indeks Elasticsearch Basic lewat job + `multi_match` dengan fuzziness, analyzer prefiks, filter/facet keyword, dan sinonim fandom yang dikurasi · storefront: home (blok statis dulu), listing kategori/koleksi, PDP dengan varian picker + badge mode + ETA + `available_to_sell` · SEO meta + sitemap + OG · audit aksesibilitas komponen produk.
**Terima:** customer mencari nama karakter/fandom dengan salah ketik → filter "Pre-order" → pilih varian → harga/stok/badge/ETA berubah sinkron tanpa reload; reindex <60 s setelah edit; cache katalog boleh stale, angka stok final tidak pernah dari cache.
**Risiko:** varian 3+ level membuat query lambat → `variant_option_values` + index komposit + cache per produk; jangan normalisasi berlebihan.

### Sprint 3 — Cart, checkout, reservasi atomik, payment adapter (±24 dev-day)
`carts`/`cart_items`/`checkout_sessions` + TTL + validasi rule · pemisahan baris per mode & pengelompokan ETA · alamat + validasi wilayah/kodepos · `ShippingRateProvider` (Biteship / RajaOngkir / Manual) + cache tarif · `PaymentProvider` interface + driver Midtrans (atau sesuai 0.15) + driver Fake untuk test · **reservasi atomik kondisional + TTL + worker expiry + urutan lock order→reservation** (B-6, Blueprint §8.1/§8.4) · **`Idempotency-Key` + replay + 409 beda payload** (B-7) · state machine `awaiting_payment → paid / expired` (mesin penuh §Doc 02 §3; `draft` tidak dipakai) + intent expiry job · webhook: verifikasi signature + dedup + ACK cepat + proses async · invoice PDF + halaman order · E2E Playwright RTS · rekonsiliasi harian v1 + daftar kerja reconcile · contract-test suite driver (wajib lolos Midtrans + Fake).
**Terima:** RTS checkout end-to-end di sandbox gateway; webhook replay 3× hanya 1 transisi; gagal bayar → cart utuh; **1000 checkout paralel vs stok 10 ⇒ paling banyak 10 reservasi berhasil, sisanya out-of-stock eksplisit, tidak ada stok negatif**.
**Blocker:** tanpa merchant account → pakai driver Fake + sandbox; jangan tunda sprint karena account (jalur paralel klien, §9).

### Sprint 4 — Orders, warehouse ops, tracking, notifikasi, retur v1 (±22 dev-day)
Admin order (filter/detail/transisi dengan reason/cancel/refund parsial) + `order_charges`/`order_adjustments`/`order_status_history` · `shipments` + pick list per batch/RTS · label + input resi massal (CSV) + scan · `TrackingProvider` + poller job + exception detection (`returned_to_sender`) · modul Notification: template email + driver WA + preferensi per user + subscription event **dengan nama event Blueprint** (Doc 02 §10.5) · halaman "Pesanan Saya" + timeline + tracking + label hasil proyeksi 4 sumbu · retur/refund request dengan foto + **refund idempoten dengan `Idempotency-Key`** · metrik + Sentry + dashboard queue/webhook lag.
**Terima:** order RTS dari bayar sampai delivered lewat staging dengan resi dan notifikasi nyata; CS memproses 1 retur tanpa akses DB; retry refund = satu payout.

### Sprint 5 — Gerbang concurrency & eksepsi uang (penutup Phase 1, ±18 dev-day)
`paid_stock_exception` lengkap (status, antrean dasbor, tiga resolusi refund/realokasi/manual, SLA, audit) (B-6) · reconciliation job terhadap provider · suite Blueprint §16 untuk Phase 1: 1000 paralel/50 stok-10 (diulang), duplicate key 100 paralel, webhook duplikat/out-of-order, **race webhook vs expiry worker**, worker crash setelah side effect · load test burst checkout + burst webhook · security pass (rate limit, header, matriks otorisasi, dependabot, rotasi secret) · performa (LCP mobile, bundle budget, image pipeline, N+1) · runbook kelompok B (4) — pasangan per Doc 11 §8.2: `refund-reconciliation.md`, `object-storage-unreachable.md`, `database-failover.md`, `supplier-failure.md`.
**Terima:** laporan §16 hijau untuk Phase 1; tidak ada temuan oversell dalam 50 iterasi acak; dokumentasi Doc 01 §5.1 sinkron dengan kode.

## 5. Phase 2 — Pre-Order / Group Order (Sprint 6–8, minggu 13–18)

### Sprint 6 — Lifecycle batch & atomisitas kuota (±20 dev-day)
`preorder_batches`/`preorder_batch_items`/`preorder_enrollments` (periode buka/tutup, kuota, deposit policy B-PO-1, due policy, ETA, kurs, mode kirim) · badge/countdown PO di storefront dengan **waktu server** + batas qty per akun (B-PO-6) · **reservasi kuota atomik** Blueprint §8.5 + job expire · admin: `draft → scheduled → open` · **transisi `closing` atomik + endpoint `POST /admin/preorder-batches/{id}/close` idempoten + penolakan enrollment setelah cutoff + snapshot accepted qty** · sinkronisasi quota row dengan state batch dalam protokol lock yang sama (anti-TOCTOU) · halaman landing batch + posisi kuota untuk customer.
**Terima:** **500 enrollment paralel vs kuota 50 ⇒ paling banyak 50 qty diterima**; enrollment yang datang saat `closing` ditolak; tidak ada lintasan `open → closed` tanpa snapshot.

### Sprint 7 — Uang PO: deposit, pelunasan, per-buyer release (±22 dev-day)
Intent deposit + intent pelunasan (ADR-002/U3) + reminder scheduler H-7/H-1/due · status `partially_paid`/`overdue` → `on_hold` → `expired` sesuai B-PO-2b/B-PO-3 · `invoices`/`invoice_lines` per tipe + halaman "tagihan saya" · eksekusi B-PO-3 (deposit hangus) di ledger + audit · **RS-1 gerbang siap-kirim (3 kondisi AND)** dan **RS-2 pelepasan batch per buyer, bukan serempak** — test ditulis lebih dulu karena ini mengubah logika inti · penggabungan ongkir per shipment (B-PO-7) · property test pembulatan deposit/pelunasan/voucher/poin + rekonsiliasi ledger.
**Terima:** satu batch penuh diuji — deposit, telat bayar (`on_hold`), tiba sebagian, lunas, kirim, dan satu batch dibatalkan dengan refund massal; ledger balance 0 selisih; dua buyer dengan nasib berbeda di batch yang sama tampil benar.
**Gate klien:** kebijakan deposit & teks T&C harus final sebelum sprint ini (Doc 02 §15).

### Sprint 8 — Procurement, inbound, invoice tambahan, dashboard batch (±20 dev-day)
`suppliers`/`purchase_orders`/`purchase_order_items`/`supplier_invoices`/`goods_receipts`/`goods_receipt_items` · kebutuhan beli terkonsolidasi dari batch tertutup · GRN + partial GRN + `in_transit` → `received` (+ `GoodsReceived`) · entitas impor minimal: `forwarders`/`consolidations`/`cargo_shipments`/`cargo_items`/`customs_charges` · job terbit invoice `intl_fees` saat GRN dengan estimasi min/maks per batch (B-PO-13; alokasi ke `landed_cost_allocations` menyusul di Phase 4) · laporan umur piutang per tipe + deposit masuk vs pelunasan kurang vs hangus · dashboard batch, ETA (pergeseran + notif massal), antrean exception.
**Terima:** barang tiba → `intl_fees` terbit → buyer melunasi → siap kirim; total alokasi dapat direkonsiliasi ke faktur supplier; ETA yang digeser menghasilkan notifikasi per batch.

## 6. Phase 3 — Request Order (Sprint 9–10, minggu 19–22)

### Sprint 9 — Pengajuan & sourcing (±16 dev-day)
`product_requests` + ULID non-enumerable · form pengajuan (nama barang, referensi foto/link, qty, target harga, negara asal, mode netral) + **upload lampiran presigned PUT → finalize → validasi MIME/magic-byte → signed URL privat** (B-9, Doc 02 §9) + moderasi · enforcement visibility (Policy + scope query + tidak masuk index/search/sitemap) + audit test · antrian kerja `sourcing-ops` + `sourcing_options` + estimasi kurs/biaya · SLA timer 2 hari kerja + escalation.
**Terima:** user A tidak bisa membaca atau menebak URL pengajuan user B (test eksplisit); MinIO mati → pengajuan tetap terkirim tanpa lampiran.

### Sprint 10 — Kuotasi berversi → order (±18 dev-day)
`quotations`/`quotation_items`/`quotation_versions` + optimistic `version` + expiry + kurs snapshot · admin menerbitkan/revisi (revisi = versi baru, versi lama mati) · **accept sekali, ditegakkan server-side; accept versi basi ⇒ `409`** · reservasi stok quote + release saat `expired`/`rejected` · konversi `QuoteAccepted → PlaceOrder(from_quote)` **idempoten** + invoice · halaman "Pengajuan Saya" + riwayat penawaran per versi · jalur lanjut RTS atau PO (impor) sampai fulfillment · optimasi UI "penawaran direvisi, buka versi terbaru".
**Terima:** accept menghasilkan order dengan harga yang terkunci saat penawaran; dua accept paralel = satu order; accept versi lama = 409 dengan pesan yang bisa dibaca; tidak ada invoice ganda saat retry.
**Risiko:** godaan "request semua orang bisa dilihat" — pertahankan privat, itu janji ke buyer.

## 7. Phase 4 — Skala & operasi lanjutan (Sprint 11+, berkelanjutan)

Bukan bagian dari angka 22 minggu; dijalankan sebagai aliran kerja terus-menerus dengan sprint cadangan (lihat buffer §2). Item Blueprint §17 Phase 4:

| Item | Prasyarat | Gerbang |
|---|---|---|
| Cloudflare **Waiting Room** dikonfigurasi terhadap kapasitas teruji + **tes "Waiting Room under load"** dan **tes "Cloudflare bypass / direct API traffic"** (B-8) | hasil k6 §1.7 (Q-4) + plan Cloudflare klien tersedia Waiting Room (Q-6) + ADR-009 | origin RPS/concurrency terjaga sesuai kapasitas teruji; bypass tak sah dibatasi kebijakan akses origin + rate limit |
| Multi-warehouse & alokasi gudang (B-5) | keputusan Q-5 + ADR-011 | `stock_items` key & aturan alokasi di-update; tidak ada double reservation lintas gudang |
| Landed cost otomatis, customs, forwarder penuh (entitas di Doc 11 §4.2) | Sprint 8 selesai + B-PO-13 dijawab | total alokasi = faktur; tidak ada biaya menganggur |
| Reporting lanjutan, cohort, margin, stok aging | Phase 1–3 | export + dasbor `analyst` read-only |
| Refund lanjutan + rekonsiliasi penuh (receivables, settlement) | Sprint 4/5 | antrean exception refund berfungsi |
| Event **outbox/inbox** penuh + evaluasi broker (RabbitMQ/Kafka/NATS) **hanya bila** ada kebutuhan fan-out/throughput nyata (Blueprint §9) | Phase 3 | tidak ada broker yang ditambahkan tanpa kebutuhan |
| Ekstraksi selektif domain service (pertama: Notification, Search, lalu Catalog) | trigger + persetujuan (Doc 01 §9) | tiap service punya DB/ownership + kontrak event sendiri |
| Autoscaling lanjutan, SLO 99,9 %, latihan DR, security review eksternal | §11 register risiko | SLO dinyatakan sebagai target pasca-stabilisasi, **bukan** klaim launch (B-12) |

## 8. Definition of Done — amendemen

Butir 1–7 versi lama tetap berlaku: feature test hijau untuk happy path + minimal 2 alur pengecualian; tidak ada lint/static-analysis error baru; berjalan di `docker compose up` fresh; migrasi backward-compatible + rollback terdokumentasi (gerbang otomatis Doc 10 §10 C1–C8, ceklis Doc 10 §11); changelog + update dokumen saat kontrak berubah; demo staging + catatan keputusan klien; tidak ada kredensial di repo dan tidak ada aset/konten pihak ketiga.

**Ditambahkan sekarang (hard gate, tidak bisa "nanti"):**

| # | Gerbang | Sumber | Status |
|---|---|---|---|
| D-8 | Perubahan controller/DTO apa pun **wajib** disertai perubahan OpenAPI 3.1 di `packages/api-contracts`; CI gagal bila spec tidak ikut berubah; client TS di-regenerate | B-2 / Blueprint §7 | gate PR, sejak Sprint 0 |
| D-9 | **1000 checkout paralel vs stok 10 ⇒ ≤10 reservasi berhasil**, sisanya out-of-stock eksplisit, tidak ada `available` negatif | Blueprint §16 baris 1 (B-11) | Sprint 3 (pertama) & Sprint 5 (regresi) |
| D-10 | **500 enrollment paralel vs kuota 50 ⇒ ≤50 accepted qty** | Blueprint §16 baris 5 | Sprint 6 |
| D-11 | Suite concurrency berjalan di **PostgreSQL nyata** (Testcontainers / service CI). **SQLite tidak memadai** untuk memvalidasi locking PostgreSQL | B-11 / Blueprint §16 tools | Sprint 1.5, wajib hijau tiap sprint 2+ |
| D-12 | Load test **k6/Locust** untuk dua angka di atas + burst checkout/webhook; hasilnya jadi angka kapasitas, bukan asumsi | Blueprint §1/§16 | Sprint 1.7 (baseline), Sprint 5 (burst), Sprint 11+ (Waiting Room) |
| D-13 | **Playwright** untuk tiga journey penuh termasuk jalur pengecualian (expired, over-quota, stale quote) | B-11 | Sprint 3, 6, 10 |
| D-14 | Replay/duplikasi/out-of-order webhook ⇒ **satu** record capture + state valid; race webhook vs expiry ⇒ tidak oversell, late-paid masuk `paid_stock_exception` + kebijakan refund | Blueprint §16 baris 3–4 | Sprint 3 & 5 |
| D-15 | 100 request paralel dengan `Idempotency-Key` sama + payload sama ⇒ satu order/satu charge, response replay konsisten; payload beda ⇒ `409` | Blueprint §16 baris 2 | Sprint 3 |
| D-16 | Accept quote paralel dengan revisi ⇒ satu versi final, accept basi ditolak | Blueprint §16 baris 6 | Sprint 10 |
| D-17 | Worker crash setelah side effect ⇒ retry aman, tidak dobel charge/notifikasi di mana dedup berlaku | Blueprint §16 baris 7 | Sprint 4 & 5 |
| D-18 | MinIO tidak tersedia ⇒ upload gagal dengan aman, checkout tetap berjalan bila tidak bergantung media | Blueprint §16 baris 8 | Sprint 9 |
| D-19 | **Tes "Waiting Room under load"** dan **tes "Cloudflare bypass/direct API traffic"** lolos sebelum ada klaim kapasitas/flash sale | B-8 / Blueprint §16 baris 9–10 | Phase 4 (Sprint 11+); tanpa ini tidak boleh menjanjikan "drop aman" |
| D-20 | Test transisi per **state machine** untuk keempat sumbu + semua status eksepsi; RBAC diuji server-side per role | Blueprint §19 | tiap sprint fitur |
| D-21 | Runbook yang relevan dengan perubahan sudah ada dan pernah dijalankan sekali di staging | B-12 | tiap sprint mulai Sprint 1 |
| D-22 | Tidak ada dokumen/commit/dasbor yang mengklaim HA atau 99,9 % untuk topologi satu node | B-15 | konstan |
| D-23 | **CDN/cache tidak menyimpan atau mengembalikan data pengguna**: tes otomatis bahwa hanya path publik yang boleh ter-cache — `/account/*`, `/admin/*`, dan seluruh API wajib `no-store`; uji spot konfigurasi cache Cloudflare di staging dan produksi. **Butir baru dari pemetaan Blueprint §19 butir 7 (lihat §8.2 di bawah) — sebelumnya tidak ada gerbangnya.** | Blueprint §19 | Sprint 5 (security pass), diulang tiap perubahan aturan cache; pemilik: dev |

### 8.2 Peta Definition of Done Blueprint §19

Sepuluh checkbox Blueprint §19 (production launch DoD), dipetakan ke gerbang kita. Tidak ada butir yang dibiarkan tanpa tindakan: butir yang belum punya gerbang mendapat butir **baru** di seri D- (D-23).

| # | Butir §19 (kutip ringkas) | Gerbang kita (D-n / task sprint / P-n) | Status |
|---|---|---|---|
| 1 | "Ketiga workflow memiliki status diagram, exception paths, dan audit trails" | Doc 02 §3–§5 (tiga mesin + empat sumbu) + §12 (mesin eksepsi per situasi) + audit refund/override/adjustment (Blueprint §13); **D-20** test transisi per state machine | terpetakan — dokumen ada, test jalan tiap sprint fitur |
| 2 | "Semua mutasi finansial/order memiliki idempotency dan concurrency tests" | **D-15** (100 paralel `Idempotency-Key`), **D-14** (race webhook vs expiry), **D-9/D-10**; cakupan operasi = Doc 02 §6 butir 6 (order, intent, accept quote, klaim manual, refund, adjustment) | terpetakan — gerbang aktif sejak Sprint 3 |
| 3 | "Payment reconciliation dan refund exception queue berfungsi" | Sprint 3 (rekonsiliasi harian v1), Sprint 5 (reconciliation job terhadap provider + antrean dasbor `paid_stock_exception` + SLA), Phase 4 §7 (rekonsiliasi penuh receivables/settlement); runbook `refund-reconciliation.md`; **D-21** | terpetakan — v1 Sprint 5, penuh Phase 4 |
| 4 | "Inventory/PO quota tidak oversell pada stress test" | **D-9** (1000 checkout vs stok 10), **D-10** (500 enrollment vs kuota 50), **D-11** (PostgreSQL nyata via Testcontainers), **D-12** (k6) | terpetakan — Sprint 3/6, regresi Sprint 5 |
| 5 | "Role-based access diuji server-side" | task **1.4** (matriks 10 role × aksi modul, diuji server) + **D-20** | terpetakan — gerbang akhir Sprint 1 |
| 6 | "MinIO presigned uploads, malware/file validation, dan private access teruji" | task **0.9** (presigned + validasi MIME/magic-byte + signed URL privat), acceptance Sprint 9 (user A tak bisa membaca/menebak URL user B; MinIO mati → pengajuan tetap jalan), **D-18** | terpetakan — Sprint 9 |
| 7 | "CDN/cache tidak membocorkan data pengguna" | **belum ada gerbangnya di D-8..D-22** → butir baru **D-23** (no-store di `/account/*`, `/admin/*`, API; spot-check aturan cache Cloudflare) | **baru** — Sprint 5, pemilik dev |
| 8 | "Cloudflare WAF, rate limiting, Waiting Room, dan origin bypass diuji" | **D-19** (tes "Waiting Room under load" + "Cloudflare bypass"), task **1.2** (rate limit per akun/IP), task **1.1** (internal tak terekspos), aturan WAF masuk scope `docs/threat-model.md` (0.13) + **P-1** | terpetakan — eksekusi Phase 4, blocker Q-4/Q-6 |
| 9 | "Observability, alerting, DLQ replay, backup/restore, rollback diuji" | task **0.12** (OTel + dashboard + backup PITR + restore drill), runbook `queue-stuck.md` (DLQ replay), `rollback-release.md`, `backup-restore-drill.md`; **D-21** (runbook pernah dijalankan di staging); latihan DR Phase 4 §7 | terpetakan — drill Sprint 0, replay pertama Sprint 1 |
| 10 | "Security review, legal/privacy, tax/invoicing rules dan terms bisnis disetujui" | security review eksternal = Phase 4 §7; legal/privacy + T&C = jalur klien §9 (konten legal/PDP, T&C deposit final sebelum Sprint 7); tax/invoicing = `tax_rule_snapshot` (B-13) + persetujuan B-PO-8/B-PO-12/B-PO-13; sign-off threat model **P-6** | terpetakan — jalur paralel klien, **blocker launch** |


## 9. Jalur paralel (tanggung jawab klien, jangan masuk sprint dev)

Tetap dari versi lama: merchant account + SKA/SNI gateway · kontrak/akun kurir + tarif nego · akun provider WA + approval template · pasokan konten (nama brand, logo, foto produk, deskripsi, kebijakan retur, T&C deposit, halaman panduan, legal/PDP) · persetujuan B-PO-1..**18** (Sprint 0–1) · penanggung jawab operasi harian (siapa input resi, siapa jawab penawaran) · keputusan domain/SSL · daftar aset brand.

**Ditambahkan karena Blueprint:**

| # | Yang klien harus jawab/sediakan | Dibutuhkan sebelum | Ref |
|---|---|---|---|
| P-1 | **Plan Cloudflare + ketersediaan Waiting Room** (fitur berbayar per plan/§10.2 butir 6) dan kebijakan routing/paths yang dilindungi | Sprint 11+ (dan sebelum janji tanggal flash sale) | Q-6, B-8 |
| P-2 | **Semantik settlement gateway**: payout/penalty/refund cost, rekonsiliasi settlement, model auth capture/void per driver | Sprint 3 (driver), final ADR-005 sebelum refund lanjutan | Q-7, Doc 11 §3 |
| P-3 | **Keputusan multi-gudang atau satu gudang** | Sprint 2 (kunci `stock_items`) — default kita satu gudang sampai ada jawaban | Q-5, B-5, ADR-011 |
| P-4 | **Lingkup ledger keuangan**: apakah `payables`/`expenses`/cash-bank ada di platform atau ekspor ke pembukuan klien | Sprint 8 (invoice tambahan) & Phase 4 reporting | Q-3, Doc 11 §2 |
| P-5 | **Angka kapasitas teruji** diterima sebagai basis SLO & Waiting Room (dikeluarkan oleh task 1.7 dan gerbang D-12; klien menyetujui safety margin) | Sprint 11+ | Q-4 |
| P-6 | **Sign-off threat model** (`docs/threat-model.md`) atas kontrol cookie/CSRF yang sudah diputuskan + jawaban **apakah klien punya IdP/SSO sendiri** (Q-2 dipersempit 2026-11-18 — bukan lagi "BFF atau token") | akhir Sprint 1, sebelum kode auth ditulis | B-14, Q-2, ADR-007 (BFF/sesi) |
| P-7 | **Pilihan jadwal J-a / J-b / J-c** tertulis | sebelum akhir Sprint 1 (gate Phase 2/3 commitment) | **Q-1** |
| P-8 | Konfirmasi kebijakan parsial checkout (B-PO-16) & TTL reservasi (B-PO-15) | Sprint 3 | Doc 02 §15 |

## 10. Deliverable dokumentasi per Phase

| Deliverable | Phase/sprint | Butir |
|---|---|---|
| `docs/adr/ADR-004` (idempotency keys + unique constraints + transactional outbox) | 0 / Sprint 0 | B-7, Doc 11 §3 |
| `docs/adr/ADR-005` (reservasi TTL + resolusi late-payment) | 0–1, ditutup setelah P-2/B-PO-15 | B-6 |
| `docs/adr/ADR-006` (gateway & semantik settlement) | 1 dibuka, ditutup saat Q-7 dijawab | Blueprint §18.5 |
| `docs/adr/ADR-007` (BFF/sesi) | 0–1; **mekanisme Accepted 2026-11-18** (sesi cookie same-site, dua guard, CSRF wajib); persetujuan P-6 untuk kontrol sesi dikonfirmasi pada 2026-11-20. Lokasi artefak dan Q-2 IdP/SSO masih terbuka | B-1/B-2, Q-2 |
| `docs/adr/ADR-008` (bucket MinIO privat/publik + delivery CDN) | 0 | B-9 |
| `docs/adr/ADR-009` (cakupan/threshold/bypass Waiting Room) | 4 (dibuka Sprint 1), butuh Q-4/Q-6 | B-8 |
| `docs/adr/ADR-010` (monorepo CI/CD + deploy independen + strategi migrasi) | 0, lampiran Doc 10 §1 M-1..M-4 | B-1 |
| `docs/adr/ADR-011` (alokasi stok parsial / multi-warehouse) | 0 dibuka, final saat Q-5 | B-5 |
| `docs/adr/ADR-012` (RBAC/identitas/dependensi) | 0, **Accepted 2026-11-18** — alasan + alternatif ditolak untuk Doc 11 §4.4 A–G; total berkas ADR kini 12 (001–012) | Doc 11 §4.4 J |
| `docs/12-api-contract.md` (OpenAPI 3.1 + konvensi + client generated) | 0 / Sprint 0–1, dipelihara terus | B-2 |
| `docs/threat-model.md` | 0 draf → sign-off Sprint 1 | B-14 |
| 11 runbook wajib (Blueprint §15) di `docs/runbooks/` — **peta resmi skenario ↔ file: Doc 11 §8.2**: `payment-callback-late.md`, `webhook-replay.md`, `reservation-expiry-race.md`, `batch-over-quota.md`, `queue-stuck.md`, `supplier-failure.md`, `database-failover.md`, `object-storage-unreachable.md`, `cloudflare-waiting-room.md`, `refund-reconciliation.md`, `emergency-disable-checkout.md` (plus lima milik kita yang bukan pengganti: `oversell.md`, `stock-discrepancy.md`, `backup-restore-drill.md`, `data-breach.md`, `rollback-release.md`) | Sprint 1 (4) → Sprint 5 (4) → Sprint 8 (2) → Sprint 11 (1) | B-12, Doc 11 §8.2 |
| Turunan wajib Blueprint lain: **keenam** kandidat dokumen Blueprint §20 sudah punya alamat di kita — peta lengkapnya di **Doc 11 §8.6** (`DATABASE_SCHEMA.md`→Doc 09, `OPENAPI_SPEC.yaml`→Doc 12 + `packages/api-contracts`, `ORDER_STATE_MACHINES.md`→Doc 02 §3–§5 + Doc 11 §8.3, `DEPLOYMENT_DOCKER.md`→Doc 01 §10 + Doc 10 §12, `CLOUDFLARE_RUNBOOK.md`→runbook + ADR-009, `IMPLEMENTATION_TASKS.md`→dokumen ini + plan Sprint 0); tidak ada file terpisah baru | 0–1 | Doc 11 §8.6 |

## 11. Risiko jadwal

Tetap: jalur PO lebih kompleks dari asumsi (+1–2 sprint; mitigasi gate klien Sprint 7, Sprint 9 boleh digeser) · account gateway/kurir/WA lama disetujui (Sprint 3/4 macet; driver Fake + Manual sejak awal) · klien minta "sama persis" dengan situs referensi (rework UI; Doc 04 token + batas Doc 01 §13 disepakati di awal) · 1–2 dev + UAT klien lambat (buffer dua sprint, demo tiap 2 minggu, deadline keputusan klien 48 jam) · godaan refactor ke microservice (kehilangan velocity; ekstraksi butuh trigger + persetujuan).

**Baru (dari Blueprint):**

| Risiko | Dampak | Mitigasi |
|---|---|---|
| **SPOF satu node, tanpa klaim HA** (Blueprint §14.1: Compose single-node = titik gagal tunggal) | insiden penuh saat launch | §11 Doc 01 menuliskan kapasitas/risiko eksplisit; backup PITR + restore drill di Sprint 0; HA hanya diklaim setelah multi-node + replikasi + failover + recovery teruji (**D-22**) — B-15 |
| **Lease lock Redis kedaluwarsa** dan disalahpahami sebagai penjamin kebenaran | oversell/ilusi proteksi | kebenaran selalu dari conditional UPDATE + constraint DB; Redis hanya optimasi hot-key/scheduler; test D-9/D-10 membuktikan tanpa Redis lock pun aman (Blueprint §8.2) |
| **Event hilang tanpa outbox** (DB commit sukses, event tidak pernah terbit) | notifikasi/alloc/stok tidak sinkron | transactional outbox + dispatcher di Sprint 0.7, inbox dedup untuk konsumen, DLQ + replay (Blueprint §9); runbook `queue-stuck.md` |
| **Scope creep dari fitur yang hanya ada di Blueprint** (landed cost otomatis, multi-warehouse, ekstraksi service, event streaming lewat broker, SSO penuh) | kalender melar tanpa nilai bisnis | Phase 4 adalah aliran, bukan MVP; setiap butir butuh trigger tertulis + persetujuan; tidak menambah broker tanpa kebutuhan (Blueprint §9) |
| Kontrak API tidak ikut berubah (DRift spec vs kode) | client TS salah, kedua app pecah | gerbang PR D-8 sejak Sprint 0 |
| Pekerjaan tersembunyi di dua app frontend (design token, auth sesi + CSRF, caching; BFF tidak lagi wajib sejak amandemen 2026-11-18 ADR-007 (BFF/sesi)) | +1–2 sprint di Phase 1 | Sprint 0.8/1.x menjadwalkan eksplisit; review klien per sprint |
| Phase 3 direncanakan di batas minimum (2 sprint dari 2–3) | +1 sprint → 24 minggu | dinyatakan terbuka di §1; buffer §2 dipakai untuk ini |
| Angka kapasitas belum teruji tapi Waiting Room sudah dijanjikan | gagal saat lonjakan drop | D-19; Q-4/Q-6 harus dijawab sebelum janji tanggal |

## 12. Urutan potongan kalau harus memotong

Urutan lama dipertahankan, sekarang dirujuk ke Phase:

1. **Tidak bisa dipotong:** Phase 0–1 (katalog, cart, bayar, kirim, reservasi atomik, idempotensi) — inti bisnis dan inti kebenaran data.
2. **Potongan pertama:** Phase 3 / Request Order (Sprint 9–10) → launch tanpa fitur ini bila klien sanggup mengelola penawaran lewat WA/manual, dengan catatan wajib "sedang kami proses" di storefront. Ini adalah isi opsi **J-c**.
3. **Potongan kedua:** Promo/loyalty multi-rule (voucher stack + poin + tier) → sisakan voucher nominal tetap.
4. **Potongan ketiga:** review terverifikasi, "kabari saya", AB copy slots → backlog pasca-launch.
5. **Potongan keempat:** reporting lanjutan → export CSV manual dulu.
6. **Tidak boleh dipotong diam-diam:** gerbang concurrency D-9/D-10/D-11 dan `Idempotency-Key` — memotong ini sama dengan menjual risiko oversell. Kalau klien memotongnya, itu tercatat sebagai **J-b** dan Blueprint dinyatakan dilanggar sebagian.

## 13. Traceability: Sprint lama (2026-11-16) → Phase/Sprint baru

| Versi lama | Sekarang | Catatan |
|---|---|---|
| Sprint 0 (fondasi Inertia satu app) | Phase 0 / Sprint 0–1 | task 0.1 (scaffold), 0.5 (design token), 13/14 plan implementasi lama **diganti** (ADR-003 K-7); artefak plan lama ditandai `superseded-by-ADR-003`, tidak dihapus |
| Sprint 1 katalog | Phase 1 / Sprint 2 | + `stock_items`/`stock_movements`/`warehouses` (B-5) |
| Sprint 2 cart/checkout/payment | Phase 1 / Sprint 3 | + idempotency, reservasi atomik + TTL + expiry worker, `checkout_sessions` (B-6/B-7) |
| Sprint 3 orders/warehouse/tracking/notifikasi | Phase 1 / Sprint 4 | + label proyeksi 4 sumbu, refund idempoten, nama event Blueprint |
| §10.5 Sprint 3 (4 lane tracking, `money_status` vs `fulfillment_status`, jalur procurement RS-5) | Phase 1 / Sprint 4–5 | sumbu jadi **empat** (B-3); lane menjadi pemilih tahap `fulfillment_status`; RS-5 dinamai `marketplace_purchase` (B-4) |
| §10.5 Sprint 2 (Billing modul nyata, tipe invoice, halaman tagihan, RS-4) | Phase 1 / Sprint 3 + Phase 2 / Sprint 7 | tetap; `intl_fees` kini punya pasangan entitas (Sprint 8) |
| Sprint 4 pre-order | Phase 2 / Sprint 6–7 | + atomisitas kuota §8.5, `closing` eksplisit, RS-1/RS-2 (test lebih dulu) |
| §10.5 Sprint 4 (job `intl_fees` saat GRN, umur piutang per tipe, gerbang 3 kondisi) | Phase 2 / Sprint 7–8 | landed-cost otomatis penuh pindah ke Phase 4 |
| Sprint 5 request order | Phase 3 / Sprint 9–10 | + `quotation_versions` + pinning versi + single-accept server-side + presigned upload (B-9) |
| §10.5 Sprint 6 (Drops, klaim 1× per akun, moderasi UGC) | Phase 1/4 (Drop policy) + Sprint 9 (moderasi lampiran) | kebijakan fairness drop kini wajib dipilih eksplisit (Blueprint §10.3, B-PO-11) |
| Sprint 6 promo/loyalty/content/review | **tidak punya Phase Blueprint** — masuk aliran pasca-Phase-1 (backlog §14) | nilainya nyata, tapi tidak dijamin Blueprint; penjadwalannya mengikuti keputusan Q-1 |
| Sprint 7 hardening/reporting/launch | tersebar: D-9..D-22 di tiap phase + Phase 4 | checklist konfigurasi flag `marketplace_purchase` (B-PO-14) tetap ada di gate Phase 2 |
| §10.5 dampak "+11 hari kerja ≈ 1,5 sprint → 18 minggu" | **digantikan** perhitungan Doc 11 §5 (22–26 minggu) | delta lama tidak salah, tapi dihitung di atas cakupan pra-Blueprint |

## 14. Post-launch backlog (bulan 3+)

Driver payment kedua via contract test · app mobile PWA push · rekomendasi "sering dibeli bareng" · program reseller/tier harga · ekstraksi service pertama (`Search`/`Notification`) bila trigger Doc 01 §9 terjadi · **AI assistant kontekstual (order/invoice/tracking) ±3 hari dengan guard privasi** (dari backlog lama) · jalur `marketplace_purchase` penuh bila Q-1/B-PO-14 mengizinkan.
