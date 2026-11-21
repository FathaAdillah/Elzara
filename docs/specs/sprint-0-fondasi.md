# Spec Sprint 0 — Monorepo, platform, kontrak

Target: Phase 0, Sprint 0 (Doc 03 §3) · Daftar task induk: **Doc 03 §3 tabel Sprint 0, ID 0.1–0.17** — dokumen ini tidak membuat ID baru.
Rujukan yang mengikat: **Blueprint** v1.0 · **Doc 11** (rekonsiliasi + §8 daftar kanonik) · ADR-003 · Doc 01 §3.1 (layout) · Doc 09 (skema, pemilik CHECK) · Doc 10 §4 (rentang migrasi `0001–0199`) · Doc 12 (kontrak `/api/v1` + register event).
Status spec: **draf untuk persetujuan** — "selesai" di bawah berarti bisa didemokan di staging, bukan "lanjut desain".

**Amandemen 2026-11-18:** (1) mekanisme auth diputuskan pemilik — ADR-007 (BFF/sesi) amandemen 2026-11-18 + ADR-012 (RBAC/identitas/dependensi) Accepted; baris 0.6 dan keputusan plan D-S0-6 yang membekukan "session/BFF selama ADR-007 terbuka" **tidak berlaku lagi** dan diamandemen di tempat, ID tetap. (2) Scaffold Sprint 0 sudah ada di disk — rekonsiliasi per task di §2.1, aturan migrasi sisa di §2.2. (3) Task 0.17 (instalasi dependensi container-only) ditambahkan di Doc 03 dan diikuti di sini. (4) Gelombang dependensi + auth 2026-11-18 direncanakan terpisah di **`.qoder/plans/2026-11-18-*.md` (sedang ditulis agen lain — dirujuk, bukan dibuat di sini)**; peran agen di `.qoder/agents/` dengan urutan mengikat **migrasi DB → fitur → testing → rebuild docker** (Doc 11 §4.4 H).

## 1. Tujuan sprint

Keluar dari sprint ini bukan fitur yang dijual ke customer, tapi kondisi bahwa **Sprint 1 dan seterusnya bisa dikerjakan dua orang tanpa saling menimpa**:

1. **Tiga deployment berdiri** dalam satu monorepo — `apps/api` (Laravel), `apps/admin` (Next.js), `apps/web` (Next.js) — masing-masing punya jalur build dan rilis sendiri (B-1, ADR-010).
2. **Kontrak API adalah gerbang, bukan dokumentasi susulan** — `packages/api-contracts/openapi.yaml` jadi source of truth, client TypeScript di-generate, dan PR yang mengubah controller tanpa mengubah spec ditolak CI (B-2, gerbang D-8).
3. **Skema dasar berdiri dengan penjagaan urutan** — migrasi `0001–0199`, C1–C8 hijau, angka tabel hanya dari Doc 09 §19.
4. **Keputusan kanonik Doc 11 §8 hidup di kode, bukan di prose** — tujuh antrian Horizon, sebelas runbook, empat sumbu status dengan nilai dari CHECK Doc 09, dan register event Doc 12 §6.2.
5. **Konkurensi bisa diuji sejak hari pertama** — harness Testcontainers di PostgreSQL nyata; tidak ada test kebenaran stok/kuota yang berlari di SQLite (B-11, D-11).
6. Komponen UI bertema token sudah ada **di kedua app** untuk dipakai sprint berikutnya.

**Non-goal sprint ini:** katalog, cart, payment, tracking, laporan, Waiting Room, multi-gudang, broker event, dan klaim ketersediaan apa pun. Bila muncul permintaan "tambah layar produk", itu Sprint 2. Bila muncul "tambah RabbitMQ", itu Phase 4 dengan trigger tertulis (Doc 01 §2.5).

## 2. Acceptance criteria per task

ID mengikuti Doc 03 §3. Kolom "Selesai bila" adalah gerbang penerimaan, bukan deskripsi pekerjaan.

| ID | Task | Selesai bila |
|---|---|---|
| 0.1 | Scaffold monorepo | `apps/api`, `apps/admin`, `apps/web`, `packages/api-contracts`, `packages/shared-types`, `packages/ui`, `infrastructure/`, `docs/adr`, `docs/runbooks` ada di branch bersih; **tiga** `package.json` dan lockfile terpisah — dua app Next tidak berbagi lockfile; `composer.json` API mengunci `"platform": {"php": "8.4.0"}` supaya install di host 8.3 gagal dengan pesan jelas, bukan diam-diam; tidak ada port data store dipublikasikan ke host |
| 0.2 | Docker dev (`docker/compose.yaml` + profiles — nama `compose.dev.yaml` di spec lama **tidak berlaku**, diganti 2026-11-18 mengikuti disk) | satu perintah dari repo kosong menghidupkan api + admin + web + postgres 16 + redis 7 + minio + mailpit + adminer; Elasticsearch Basic diuji lewat profil opsional `search`; `https://localhost` memuat storefront dan `https://localhost/admin` memuat admin (B-1 — admin **bukan** route group di app yang sama); PostgreSQL/Redis/Elasticsearch/MinIO tidak terjangkau dari laptop (Doc 01 §11.1); semua perintah php dan node berjalan **di dalam container** (§4) |
| 0.3 | CI | lint + phpstan/larastan level max (API) + eslint/typecheck **kedua** app + unit/feature di service PostgreSQL nyata + **harness Testcontainers** hijau + build tiga image + cek skema C1–C8 (Doc 10 §10); PR yang melanggar salah satunya di-blokir; workflow berlari di runner Linux sehingga CRLF tidak pernah jadi penyebab gagal; PHPUnit tetap sah (Blueprint §16 mengizinkan "Pest/PHPUnit", D-S0-3 tidak dibatalkan) |
| 0.4 | CD staging | tiga app di-deploy **mandiri** — satu app bisa rilis tanpa menyentuh dua lainnya; urutan maintenance mode → drain Horizon → migrate backward-compatible → up → smoke → maintenance off (Doc 01 §11.4); rollback = deploy ulang tag sebelumnya tanpa `migrate:rollback`; langkah restore `pg_dump` **dijalankan nyata sekali** dan hasilnya dicatat di `docs/runbooks/backup-restore-drill.md` |
| 0.5 | `packages/api-contracts` | `openapi.yaml` 3.1 lolos validasi; client TS hasil generate dipakai kedua app dan **tidak pernah** diedit tangan (Doc 12 §1.3); gerbang D-8 aktif sebagai test yang membandingkan rute terdaftar di Laravel dengan path di spec dan gagal bila berbeda; **18 jalur wajib Blueprint §7** (Doc 12 §2.1; gate I-7 Doc 11 §8.8 memeriksa kehadiran jalur wajib, bukan jumlah total operasi) minimal berdiri sebagai stub bertipe; **amandemen 2026-11-18:** securitySchemes cookie sesi (cookieAuth + CSRF) **wajib ada** dan `security` dipasang per operasi — larangan lama dicabut (Doc 11 §4.4 I, ADR-007 (BFF/sesi) D-5a); `x-elzara-auth` tetap penanda kategori (`public | customer | admin | s2s`); operasi admin mendeklarasikan `x-elzara-permission: <modul.aksi>`; endpoint auth (login/logout/OTP/sesi, social login+callback, registrasi hybrid) ditambahkan ke spec; Scramble hanya menghasilkan **draf** yang di-review manusia lalu di-merge ke spec — tidak ada auto-regen di CI (§4.4 E) |
| 0.6 | Auth + RBAC (**diamandemen 2026-11-18** — mekanisme diputuskan, ADR-007 (BFF/sesi) amandemen + ADR-012 (RBAC/identitas/dependensi)) | Sanctum `statefulApi`, sesi cookie **same-site** satu host path-terpisah (`/api/`, `/admin/`, `/`); dua guard/cookie: `web`/`elzara_web_session` dan `admin`/`elzara_admin_session`, keduanya Secure; HttpOnly; SameSite=Lax; Path=/ (pemisahan lewat **nama cookie + guard**, bukan path); XSRF-TOKEN sengaja tidak HttpOnly (dibaca JS untuk header X-XSRF-TOKEN — desain Sanctum); `sanctum/csrf-cookie` dipanggil sebelum mutasi pertama; **CSRF valid wajib untuk semua mutasi, tanpa pengecualian admin**; tanpa BFF wajib, tanpa token browser — tabel `personal_access_tokens` **tidak dibuat** (migrasi bawaan Sanctum dikecualikan); register/login/reset hybrid **web**: email+password, Google, X via `socialiteproviders/twitter` (OAuth 2.0 — driver twitter bawaan OAuth 1.0a sudah ditinggalkan X), penautan akun hanya lewat **email terverifikasi**; login **admin** hanya email+password, `is_operator=true` + `status=active` + **2FA TOTP wajib semua operator** (bukan hanya superadmin — keputusan lama tidak berlaku), recovery code **sepuluh** buah ter-hash di `staff_2fa_secrets.recovery_hashes text[]` (Doc 09 §2), sekali pakai per kode; tidak ada jalur sosial ke admin; cookie storefront tidak pernah diterima guard admin; `users` satu tabel dengan `is_operator`/`is_admin` boolean NOT NULL DEFAULT false + CHECK (`is_admin = false OR is_operator = true`) — kolom `kind` dan index `users_kind_status` **diganti** (`users_operator_status`), tabel `staff_users` tetap **tidak dibuat** (Doc 11 §4.3/§4.4 A); `is_operator`/`is_admin` tidak memberi apa-apa di storefront — otorisasi web = kepemilikan + status akun, tanpa spatie; RBAC admin = spatie/laravel-permission granular per aksi `modul.aksi` + direct grant per user (`model_has_permissions`) + `role_scopes`; **sepuluh** role Blueprint §4 di-seed sebagai bundel (`superadmin`, `catalog-manager`, `sales-ops`, `preorder-ops`, `sourcing-ops`, `warehouse-staff`, `procurement-staff`, `finance`, `customer-support`, `analyst` read-only), `guard_name='admin'`; `superadmin` **bukan** bypass — bypass hanya `is_admin` via `Gate::before` di guard admin dan **setiap pemakaian menulis `audit_logs`** (`rbac.bypass`, reason, request_id); test membuktikan penolakan terjadi di server, bukan hanya penyembunyian menu Next.js (B-10); pencabutan permission wajib invalidasi cache spatie via PermissionRegistrar. **Prasyarat keras:** sign-off threat model (P-6) sebelum kode auth ditulis; Q-2 tersisa hanya "apakah klien punya IdP/SSO sendiri" |
| 0.7 | Kerangka domain | `ApiProblem` RFC 7807 + `request-id` + domain exception sesuai peta Doc 12 §4.3; state-machine runner deklaratif untuk **empat sumbu** yang nilai enumnya dibaca dari satu sumber yang sama dengan CHECK Doc 09 — tidak ada salinan kedua (Doc 11 §8.3); transactional outbox + dispatcher di **tujuh** antrian Doc 11 §8.1 (`payments`, `orders`, `inventory`, `notifications`, `imports`, `reports`, `media`); `idempotency_keys` dan `inbox_events` terisi dan ditegakkan (B-7) — **`webhook_receipts` tidak ada di Sprint 0**: tabel itu milik modul Payments dengan FK `intent_id → payment_intents` (Doc 09 §9), level L7, band Sprint 2 `0400–0599` (Doc 10 §4 dan aturan "salah band" §4.1). Yang dibangun sekarang adalah **mekanisme** dedup `(provider, provider_event_id)` lewat `inbox_events` + test-nya, supaya Sprint 2 tinggal memakainya; test membuktikan event tidak hilang ketika worker mati di antara commit dan publish |
| 0.8 | Design token + komponen di **dua app** | token tiga lapis (Doc 04) berada di `packages/ui`; ganti brand = mengubah satu berkas konfigurasi brand dan satu token file tanpa menyentuh komponen; preset tema terpasang di kedua app; komponen dasar (button, input, card, badge, modal, toast, grid) punya test kontras AA |
| 0.9 | MinIO | **enam** bucket Doc 01 §9.2 dibuat dengan policy least privilege: `product-media`, `request-attachments`, `invoice-documents`, `shipment-documents`, `exports` (lifecycle TTL), `audit-archives`; alur presigned PUT → finalize → validasi MIME/magic-byte berjalan; **presigned URL memakai endpoint yang benar-benar bisa dijangkau browser**, bukan hostname Docker internal (Blueprint §11, ditegaskan Doc 11 B-9) — ada test khusus untuk ini; CORS per origin; console MinIO tidak terekspos publik; **tidak ada bucket ketujuh** untuk dump backup (Doc 11 §8.7) |
| 0.10 | `localizations` + `feature_flags` + `settings` | ID/EN sejak awal, semua string UI lewat resource di kedua app; `feature_flags.code` memuat `checkout.enabled` sebagai **kill switch** (Doc 11 §8.7) dengan test: flag mati ⇒ `POST /orders` ditolak dan storefront menampilkan alasannya, bukan error mentah |
| 0.11 | Seeder orisinal | `composer dev:db-fresh` = `migrate:fresh` + `--class=ReferenceSeeder`; seeder reference dijalankan dua kali -> nol perubahan (C7 hijau); `ReferenceSeeder` menanam 10 role + permission (bentuk spatie: `name` + `guard_name='admin'`, pivot resmi `role_has_permissions` — ADR-012 (RBAC/identitas/dependensi)) + `checkout.enabled` + `localizations`; `DemoSeeder` (dev/staging saja) menanam akun operator/customer, 10 gambar **SVG orisinal** + baris `media`, dan factories untuk seluruh tabel Sprint 0 dipakai semua test. **10 produk + 2 batch + 1 kuotasi tidak ditanam di Sprint 0**: tabelnya ada di band Sprint 1 dan Sprint 5 (Doc 10 §4), sehingga menanamnya sekarang = menulis data tanpa skema. Angka itu pindah ke gerbang Sprint 1/5 dan tercatat di §7 |
| 0.12 | Observability | trace ID merambat web → api → job (OpenTelemetry); log JSON terstruktur; correlation id pada order/payment/provider event; dasbor dasar memuat metrik Blueprint §15 (RPS, p50/p95/p99, 4xx/5xx, lock waits, queue lag, webhook delay, oversell anomaly, reservation expirations, MinIO error rate, waiting-room admissions); **backup ke tujuan off-node** terdokumentasi dan restore PITR dijalankan sekali |
| 0.13 | `docs/threat-model.md` draf v1 | ada dan mencakup edge, auth, webhook, PII, abuse; isinya dipakai untuk **sign-off kontrol cookie/CSRF yang sudah diputuskan** (ADR-007 (BFF/sesi) D-6a — mekanisme tidak lagi menunggu dokumen ini, tetapi kode auth Sprint 1 menunggu sign-off-nya); sign-off klien dijadwalkan akhir Sprint 1 (Doc 03 P-6); Q-2 tersisa hanya soal IdP/SSO klien |
| 0.14 | ADR | **12 berkas ADR (001–012)** ada di `docs/adr/` (amandemen 2026-11-18: sebelumnya 11); **ADR-004, ADR-008, ADR-010 ditutup** di sprint ini dan **ADR-011 ditutup dengan default satu gudang** (dibuka lagi hanya bila Q-5 berubah); **ADR-012 (RBAC/identitas/dependensi) ditambahkan dan Accepted 2026-11-18**; **ADR-007 (BFF/sesi) Accepted untuk mekanisme 2026-11-18** — sisa terbuka hanya sign-off threat model + Q-2 IdP/SSO; ADR-005, 006, 009 tetap terbuka karena menunggu jawaban klien — nomor dan topiknya harus sama dengan Doc 11 §3 dan dengan judul berkasnya |
| 0.15 | (paralel, milik klien) gateway pertama, kurir, provider WA, B-PO-1…18, daftar aset brand, plan Cloudflare | tercatat sebagai jalur paralel Doc 03 §9 (P-1…P-8), **bukan** pekerjaan dev; blocker Sprint 3, bukan Sprint 0 |
| 0.16 | Baseline keamanan edge | header HSTS, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy` + CSP **per app** dengan `script-src 'self' 'nonce-…'` (nonce per request — `script-src 'self'` polos memutus hydration Next.js, dan §0.16 sendiri melarang CSP yang mematikan mekanisme muat app), diuji otomatis lewat feature test; CSP tidak melarang mekanisme muat yang dipakai Next.js; halaman admin dilindungi server-side — temuan Doc 08 §2 yang **tidak** kita tiru |
| 0.17 | Instalasi dependensi (container-only, **baru 2026-11-18**, Doc 03 0.17) | `apps/api/composer.json` sesuai baseline Doc 11 §4.4 D (phpredis via ekstensi, bukan predis; sanctum/spatie/socialite+twitter/scramble/sentry/excel masuk; phpunit 13.4.7, phpstan 2.3.6, qossmic/deptrac 2.0.4); `bash scripts/gen-lockfiles.sh` menghasilkan **tiga lockfile** di dalam `node:22` (`npm install --package-lock-only --ignore-scripts --no-audit --no-fund`) dan `php:8.4-cli` (`composer update --lock --no-scripts --no-interaction`); `composer require`/`install` tambahan dan `npm ci` hanya di dalam kontainer (php:8.4 / node:22); suite test pertama **dijalankan nyata** di dalam kontainer dan hasilnya dicatat. Alasan mengikat: spatie/laravel-activitylog 5.1.5 minta php ^8.4, phpunit 13.4.7 minta php >=8.4.1, host PHP 8.3.30 — instalasi host pasti gagal; lockfile host bukan baseline image |

### 2.1 Rekonsiliasi dengan disk (2026-11-18, diverifikasi `ls`/`find`/`grep` — bukan klaim)

Status per task terhadap scaffold yang sudah ada. "Scaffold" = artefak ada di disk; **bukan** berarti acceptance §2 hijau — belum ada dependensi terpasang dan belum ada test yang pernah dijalankan.

| ID | Status di disk | Sisa pekerjaan |
|---|---|---|
| 0.1 | scaffold — semua direktori + skeleton ada; `composer.json` mengunci platform php 8.4.0 | manifest menyimpang dari Doc 11 §4.4 D (predis tertulis, paket auth/RBAC belum masuk, phpunit ^12.5); lockfile belum ada → 0.17 |
| 0.2 | scaffold — `docker/compose.yaml` (11 service + healthcheck) + profiles + Dockerfile per app + `nginx/dev.conf` | belum pernah `up`; build menunggu lockfile (0.17); nama berkas final = `compose.yaml`, bukan `compose.dev.yaml` yang tertulis di baris 0.2 (penamaan lama, tidak berlaku) |
| 0.3 | parsial — 4 workflow `{api,admin,web,contracts}.yml` ada | belum pernah hijau; harness Testcontainers belum ditulis; semua job menunggu 0.17 |
| 0.4 | belum — tidak ada workflow deploy di disk | seluruh task |
| 0.5 | parsial — `openapi.yaml` 18 operationId; `codegen.sh` + `check-contract-drift.sh` ada | client TS belum di-generate (`generated/` hanya `.gitkeep`); securitySchemes + security per operasi + endpoint auth sedang ditambahkan agen lain (Doc 11 §4.4 I) |
| 0.6 | belum — tidak ada guard/policy/CSRF/2FA di disk | seluruh task; gelombang ini di `.qoder/plans/2026-11-18-*.md` |
| 0.7 | parsial — `ApiProblem`, `StateMachine/`, `Outbox/` (+`QueueRouting`), `Statuses/` 4 sumbu, migrasi `0001_Platform_create_reliability_tables.php` (outbox/inbox/idempotency) ada | test transisi + test outbox/idempotensi + deptrac B1–B6 belum ditulis/dijalankan |
| 0.8 | parsial — tokens 3 lapis + presets + brand config ada di `packages/ui` | komponen baru Button; input/card/badge/modal/toast/grid + test kontras belum |
| 0.9 | parsial — `docker/minio/init-buckets.sh` + policies + cors ada | presigned/finalize/validasi MIME belum; bucket belum pernah dibuat nyata |
| 0.10 | belum — migrasi modul masih `.gitkeep` | seluruh task |
| 0.11 | belum | seluruh task (bentuk spatie, lihat baris 0.11) |
| 0.12 | selesai di repo — `traceparent` W3C web/admin → API → job (outbox membawa `request_id`/`trace_id`); log JSON stderr dengan redaksi PII; `/metrics` Prometheus internal; `infrastructure/monitoring/` (alert + dasbor Grafana); WAL-G terenkripsi ke tujuan off-node + drill PITR lokal lulus | drill ulang dari bucket off-node staging Dokploy (host belum ada); emit metrik reservasi/payment/Waiting Room ditambahkan oleh modul pemiliknya; ekspor OTLP ke kolektor belum dipilih |
| 0.13 | parsial — `docs/threat-model.md` ada sebagai draf | review isi terhadap kontrol cookie/CSRF final + sign-off klien |
| 0.14 | scaffold — 12 berkas ADR (001–012) + 16 runbook ada di disk (terhitung via ls) | status Accept per ADR dirawat di berkasnya masing-masing; sign-off P-6 menunggu |
| 0.15 | milik klien | tidak berubah |
| 0.16 | parsial — `docker/nginx/dev.conf` + middleware kedua app ada; `infrastructure/nginx/` README-only | uji otomatis header/CSP belum |
| 0.17 | belum — ketiga lockfile, `vendor/`, `node_modules/` tidak ada (diverifikasi ls) | seluruh task; prasyarat 0.2/0.3 dan semua gerbang test |

### 2.2 Aturan migrasi untuk sisa Sprint 0 (amandemen 2026-11-18, ADR-012 (RBAC/identitas/dependensi))

Berlaku untuk migrasi Identity yang belum ditulis (direktori `Infrastructure/Database/Migrations/` tiap modul masih `.gitkeep`; satu-satunya migrasi di disk = trio reliability milik Platform):

- **M-1..M-4 (Doc 10 §1, ADR-010):** basename `NNNN_<module>_<slug>.php`; band Sprint 0 `0001–0199`; loader **glob** (`app/ModuleLoader` sudah menerapkannya — bukan daftar file edit-tangan); pemilik modul = direktori tempat migrasi berada.
- Bentuk baru wajib: `users.is_operator`/`is_admin` boolean NOT NULL DEFAULT false + CHECK (`is_admin = false OR is_operator = true`); index `users_operator_status (is_operator, status)` — index `users_kind_status` **diganti**, kolom `kind` **tidak dibuat**; `roles`/`permissions` pakai `name` + `guard_name='admin'` (kolom `code` lama **diganti** `name`), unique `(name, guard_name)`; pivot resmi spatie `model_has_roles`/`model_has_permissions`/`role_has_permissions` — pivot lama `role_user` dan `permission_role` **tidak dibuat**; kolom scope pindah ke `role_scopes (user_id, role_id, scope_type, scope_id)` dengan composite PK COALESCE; tabel baru `user_identities` (milik Identity, unique `(provider, provider_user_id)`) dan `activity_log` (milik Audit, kolom standar spatie, jsonb `properties`) — `activity_log` **bukan pengganti** `audit_logs`.
- **Migrasi vendor ditulis ulang di bawah namespace/band kita, bukan dipublish mentah** (`vendor:publish` migration spatie/sanctum tidak dipakai apa adanya); migrasi `personal_access_tokens` bawaan Sanctum **dikecualikan — tidak dibuat** (ADR-007 (BFF/sesi) D-3a).
- Perubahan bentuk tabel wajib masuk **Doc 09 §19 + Doc 01 §3.1/§3.3 + disk secara bersamaan** (gate H/I, Doc 11 §4.4 G); angka tabel tidak pernah ditulis tangan di dokumen mana pun.

## 3. Definition of Done sprint

1. Semua acceptance §2 hijau; `composer ci:all` secara lokal menghasilkan hal yang sama dengan CI.
2. Onboarding developer baru: clone → satu perintah `up` → test hijau < 20 menit, tanpa harus bertanya ke orang.
3. Doc 09 §19 dan Appendix A Doc 10 cocok dengan DB nyata (C6 hijau dengan snapshot ter-commit); **tidak ada dokumen lain yang menyalin angka tabel** (Doc 11 §4.2).
4. Script audit lulus tanpa pengecualian: empat sumbu sama di Doc 09/01/02, sebelas runbook ada sebagai berkas, register event tunggal di Doc 12 §6.2, nol nama mati di baris aktif, nol sitasi nomor bagian yang menunjuk heading tidak ada, nol karakter non-Latin di seluruh `docs/`.
5. Tidak ada aset/teks/konten situs referensi di repo; tidak ada kredensial di repo maupun di `.env.example`; README menyebut cara reset data.
6. Tidak ada satu pun kalimat di dokumen, commit, atau dasbor yang mengklaim HA atau 99,9 % untuk topologi satu node (**D-22**, B-15).
7. Risiko Sprint 0 yang belum selesai dibawa ke Doc 03 §11, tidak hilang dari pembicaraan.

## 4. Deviasi lingkungan yang harus diputuskan sebelum task 0.1

Diukur di mesin kerja 2026-11-17 (`php -v`, `psql --version`, `node -v`, `docker info`, `php -m`, `make --version`), bukan diingat-ingat. Masing-masing mengubah cara sprint ini dikerjakan:

| Fakta lokal | Target arsitektur | Dampak | Keputusan |
|---|---|---|---|
| PHP host **8.3.30** | PHP 8.4 (Doc 01 §11.2) | kode yang lulus di 8.3 bisa gagal di 8.4 dan sebaliknya | **semua perintah PHP di container 8.4**; PHP host hanya untuk IDE; `"platform": {"php": "8.4.0"}` dikunci di `composer.json` |
| Ekstensi host: `pdo_pgsql` ada, **`redis` tidak** | `ext-redis` di container | driver cache/queue/session `redis` tidak bisa diuji dari host | `redis` hanya dipakai di container; test yang menyentuh Redis berjalan di CI/Testcontainers |
| PostgreSQL host **15.10** | PostgreSQL 16 | perilaku locking/MVCC yang diuji di 15 bukan yang dipakai produksi | server test selalu container 16; `psql` host hanya sebagai client |
| Node host **v26.13.1**, npm 11.20 | `node:22` untuk build image Next.js | build di host menghasilkan artefak yang tidak sama dengan image | `npm ci && next build` **hanya** di container `node:22`; `packageManager` di-pin di tiap app |
| `make` tidak ada | — | Makefile tidak bisa jadi satu-satunya jalur perintah di Windows | jalur resmi adalah `composer dev:*` / `composer ci:*`; Makefile hanya pembungkus tipis untuk Linux/macOS |
| Docker daemon **29.6.2 hidup** | — | prasyarat task 0.2 terpenuhi hari ini | dicatat sebagai prasyarat, bukan asumsi diam-diam |
| Repo sudah LF (commit `chore: normalisasi line endings ke LF`) | runner Linux | CRLF bisa muncul lagi dari editor Windows | `.gitattributes` ditegakkan; kegagalan karena CRLF dianggap bug konfigurasi, bukan bug kode |

Bila ada keputusan di atas yang tidak disetujui, bilang sekarang — menggantinya setelah task 0.3 berjalan berarti menulis ulang CI dan seluruh script.

## 5. Yang dikerjakan duluan (urutan intra-sprint)

`persiapan lingkungan` → **0.1** → **0.17** → **0.2** → **0.5** → **0.3** → **0.7** → 0.6 → 0.9 → 0.10 → 0.11 → 0.14 → 0.13 → 0.8 → 0.16 → 0.12 → 0.4

Dasar urutan:
- **0.17 sebelum 0.2/0.3** (amandemen 2026-11-18) — build image memakai `composer install` dengan lock dan `npm ci`; keduanya gagal tanpa lockfile, dan resolve dependensi hanya sah di kontainer php:8.4/node:22 (host PHP 8.3.30). Tanpa 0.17 tidak ada test yang bisa dijalankan.
- Untuk gelombang 2026-11-18 (migrasi Identity + auth), urutan agen **mengikat**: migrasi DB → fitur → testing → rebuild docker; agen testing tidak boleh mulai sebelum agen fitur selesai (Doc 11 §4.4 H; peran di `.qoder/agents/`, rencana di `.qoder/plans/2026-11-18-*.md`).
- **0.5 sebelum 0.3** — gerbang D-8 tidak bisa dijaga CI kalau spec OpenAPI belum ada; mendahulukan 0.3 lalu menambah 0.5 berarti pipeline ditulis dua kali.
- **0.7 sebelum 0.6** — outbox, idempotensi, dan state-machine dipakai modul Identity dan semua sprint sesudahnya; C1–C8 dari 0.3 harus menjaga skema sejak hari pertama.
- Jalur frontend (**0.8**, **0.16**) serta **0.12/0.4** bisa dikerjakan orang kedua paralel sejak 0.1 selesai karena tidak berbagi berkas dengan jalur backend. Inilah jawaban konkret untuk "usahakan tidak saling bertabrakan" (aturan kepemilikan Doc 10 §5.1).

## 6. Ukuran hasil (supaya bisa dinilai, bukan dirasakan)

- `up` dari repo kosong → halaman pertama storefront **< 90 s** cold start. Angka lama 60 s disusun untuk satu app Inertia; sekarang tiga proses Node/PHP, dan itu harus diakui di angka.
- Build tiga image **< 6 menit** dengan cache hangat; CI penuh **< 12 menit** (naik dari 8 menit karena dua app Next + Testcontainers + validasi spec). Bila tidak tercapai, paralelisasi dipotong lebih dulu — batas waktu tidak dinaikkan lagi.
- Harness concurrency menghasilkan D-9 (1000 checkout paralel vs stok 10 ⇒ ≤10 reservasi, `available` tidak pernah negatif) secara **repeatable** dalam < 60 s.
- Tidak ada satu pun berkas konfigurasi yang harus diedit dua orang pada sprint yang sama.

## 7. Yang secara eksplisit TIDAK boleh muncul di Sprint 0

| Godaan | Kenapa ditolak | Rujukan |
|---|---|---|
| Satu app Inertia untuk storefront + admin | keputusan topologi sudah berubah ke tiga deployment | B-1, ADR-003 K-2 |
| Menulis endpoint dulu, mendokumentasikan belakangan | kontrak adalah gerbang PR | B-2, D-8 |
| Redis lock sebagai penjamin kebenaran stok | lease bisa kedaluwarsa; kebenaran dari conditional UPDATE + constraint database | Blueprint §8.2, Doc 03 §11 |
| SQLite untuk test concurrency | tidak memvalidasi locking PostgreSQL | B-11, D-11 |
| Bucket MinIO ketujuh untuk dump backup | backup wajib off-node; bucket aplikasi berada di node yang sama | Doc 11 §8.7 |
| `payables` / `expenses` / cash-bank | lingkup ledger keuangan belum dijawab klien | Q-3, Doc 11 §4.3 |
| Klaim HA atau 99,9 % | satu node adalah SPOF by design | B-15, D-22 |
| Broker event (RabbitMQ/Kafka/NATS) | belum ada kebutuhan fan-out nyata | Blueprint §9, Doc 03 §7 |
| Isi demo katalog (produk, batch, kuotasi) ditanam di Sprint 0 | tabelnya belum ada pada band `0001–0199`; data tanpa skema bukan deliverable | Doc 10 §4, Doc 03 0.11 |
| Role kesebelas untuk Editor konten atau Moderator | pemegang permission-nya belum diputus klien | Q-8, B-10 |
| Token browser / tabel `personal_access_tokens` (tidak dibuat) | browser memakai sesi cookie same-site + CSRF; s2s pakai HMAC/jaringan internal | ADR-007 (BFF/sesi) D-3a, Doc 11 §4.4 C |
| Publish migrasi vendor spatie/sanctum mentah-mentah | ditulis ulang di bawah namespace + band kita; migrasi PAT Sanctum dikecualikan | ADR-012 (RBAC/identitas/dependensi), Doc 10 §4 |
| `composer install`/`npm install` di host | activitylog 5.1.5 + phpunit 13.4.7 minta PHP 8.4; host 8.3.30; lockfile host bukan baseline image | Doc 11 §4.4 D, task 0.17 |
| Auto-regenerate spec dari Scramble di CI | Scramble = alat bantu draf; `openapi.yaml` source of truth; drift ditolak `check-contract-drift.sh` | Doc 11 §4.4 E |
