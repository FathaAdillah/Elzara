# Sprint 0 (Fondasi, Monorepo, Kontrak) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Each task ends with a commit. Do not start a task before the previous one is committed and green. **Satu task = satu subagent baru**; jangan menugaskan dua penulis pada direktori yang sama (matriks kepemilikan §3).

**Goal:** Membangun fondasi yang membuat Sprint 1 dan seterusnya bisa dikerjakan dua orang tanpa saling menimpa: monorepo dengan **tiga deployment** (`api`, `admin`, `web`) yang hidup di Docker, kontrak OpenAPI 3.1 sebagai gerbang PR, skema dasar `0001–0199` dengan penjagaan urutan migrasi, dan empat mekanisme lintas modul (ApiProblem RFC 7807, state machine empat sumbu, transactional outbox tujuh antrian, idempotensi/inbox) — semuanya teruji di PostgreSQL 16 nyata, bukan SQLite.

**Architecture:** Modular monolith di sisi domain (ADR-001) dengan 16+6 bounded context di `apps/api/app/Modules/<X>/{Domain,Application,Infrastructure,Contracts,Tests}`, satu shared kernel `app/Platform/` untuk mekanisme lintas modul, dan satu service provider glob `app/ModuleLoader/` (M-2). Mutasi hanya lewat Command (B2); event lintas modul hanya membawa ID (B3) dan ditulis ke transactional outbox pada transaksi yang sama (B4); kebenaran stok/kuota datang dari atomic conditional UPDATE + constraint database, bukan lock Redis. Kontrak `/api/v1` dimiliki `packages/api-contracts/openapi.yaml`; client TypeScript di-generate dan di-commit, tidak pernah diedit tangan. Semua perintah PHP dan Node berjalan di container; host Windows hanya untuk editor dan `git`.

**Tech Stack:** PHP 8.4 (container), **Laravel 13** (terverifikasi 13.42.0 di registry 2026-11-17; **amandemen 2026-11-18: 13.43.0** — Doc 11 §4.4 D), Next.js **16** App Router + TypeScript (terverifikasi 16.4.6), Tailwind CSS v4 (`@theme` CSS-first), build image `node:22`, Postgres 16, Redis 7 + Horizon 5.52.0 (phpredis, bukan predis), Elasticsearch self-managed Basic, MinIO, PHPUnit 13.4.7, Larastan + phpstan 2.3.6, qossmic/deptrac 2.0.4, Laravel Pint, Testcontainers, GitHub Actions, Docker Compose. Auth/RBAC (amandemen 2026-11-18): laravel/sanctum 4.5.0, spatie/laravel-permission 8.5.0, spatie/laravel-activitylog 5.1.5, spatie/laravel-data 4.23.1, laravel/socialite 5.33.0 + socialiteproviders/twitter 4.1.2, dedoc/scramble 0.13.55 (draf saja), sentry/sentry-laravel 4.30.0, maatwebsite/excel 4.0.7 — versi terkunci dari registry per Doc 11 §4.4 D.

**Spesifikasi sumber:** [docs/specs/sprint-0-fondasi.md](../../docs/specs/sprint-0-fondasi.md) · daftar task induk: [docs/03-sprint-plan.md §3](../../docs/03-sprint-plan.md) · skema: [docs/09-database-schema.md](../../docs/09-database-schema.md) · aturan migrasi: [docs/10-migration-plan.md](../../docs/10-migration-plan.md) · kontrak: [docs/12-api-contract.md](../../docs/12-api-contract.md) · daftar kanonik: [docs/11-blueprint-reconciliation.md §8](../../docs/11-blueprint-reconciliation.md) · rujukan induk: SNOWEGO_MICROSERVICES_BLUEPRINT.md v1.0 (ADR-003 K-1)

---

> **Amandemen 2026-11-18 (baca sebelum mengerjakan task apa pun):**
> 1. **Mekanisme auth diputuskan pemilik** — ADR-007 (BFF/sesi) amandemen 2026-11-18 + ADR-012 (RBAC/identitas/dependensi) Accepted. D-S0-6 diamandemen di tempat (ID tetap); bentuk lama "cookie + BFF, securitySchemes ditunda" **tidak berlaku**. Sign-off Fatharoni tercatat di chat bot Product Engineer pada 2026-11-22, dengan persetujuan klien dikonfirmasi pemilik (threat model §6); Q-2 dijawab pemilik proyek pada 2026-11-22: klien tidak punya IdP/SSO sendiri; mekanisme sesi Sanctum dua guard tetap, tidak ada provider OIDC.
> 2. **Scaffold sudah ada di disk** (diverifikasi `ls`/`find`/`grep` 2026-11-18): Task 1–10 sebagian besar sudah dipenuhi scaffold; status per task di §2.1. **Belum ada** lockfile, `vendor/`, `node_modules/`, migrasi modul (masih `.gitkeep`), dan **belum pernah ada suite test yang dijalankan**. Jangan mengklaim task selesai tanpa artefak + test hijau yang benar-benar dijalankan.
> 3. **Sisa pekerjaan dependensi + auth gelombang 2026-11-18 direncanakan terpisah di `.codex/plans/2026-11-18-*.md` (sedang ditulis agen lain — dirujuk, tidak dibuat/diubah di sini).** Instalasi dependensi = task Doc 03 0.17, container-only: `bash scripts/gen-lockfiles.sh` (node:22 + php:8.4-cli), lalu `composer require`/`install` di dalam php:8.4 — host PHP 8.3.30 tidak bisa me-resolve activitylog 5.1.5 (^8.4) dan phpunit 13.4.7 (>=8.4.1).
> 4. **Urutan agen mengikat** (Doc 11 §4.4 H; definisi peran di `.codex/agents/`, ditulis agen lain): migrasi DB → fitur → testing → rebuild docker; agen testing tidak boleh mulai sebelum agen fitur selesai; penulis knowledge paralel hanya di berkas disjoint.
> 5. Task 11/12/15 di bawah **diamandemen** ke bentuk ADR-012 (bukan bentuk lama); teks keputusan lama ditandai, bukan dihapus diam-diam.

## 1. Keputusan teknis sprint ini (D-S0-…)

Diputus sekarang supaya tidak direbut ulang di tengah sprint. Kalau salah satu tidak disetujui, bilang sebelum Task 6 — menggantinya setelah CI berdiri berarti menulis ulang pipeline dan seluruh skrip.

| ID | Keputusan | Alasan / rujukan |
|---|---|---|
| D-S0-1 | **Dua `composer.json`.** Root (`elzara/root`) hanya pembungkus skrip `dev:*`/`ci:*` yang memanggil `docker compose`; `apps/api/composer.json` adalah app sungguhan dan mengunci `"config": {"platform": {"php": "8.4.0"}}` | host punya PHP 8.3.30 (terukur, spec §4); install di host harus gagal keras, bukan diam-diam |
| D-S0-2 | Jalur perintah resmi = `composer <ns>:<cmd>` dari root. `Makefile` hanya pembungkus tipis Linux/macOS; tidak ada skrip `.ps1`/`.bat`. `jq` tidak ada di host, jadi gerbang yang butuh pembacaan JSON memakai `php -r` | `make` tidak ada di host (terukur); spec §4 |
| D-S0-3 | **PHPUnit**, bukan Pest — constraint bawaan skeleton Laravel 13 dipakai apa adanya | Blueprint §16 mengizinkan "Pest/PHPUnit"; D-S0-3 versi pra-Blueprint tidak dibatalkan |
| D-S0-4 | Enum status PHP di `app/Platform/Domain/Status/` adalah **satu-satunya daftar** di kode; `CHECK` migrasi dibangun dari enum (`->values()`), dan test mengawasi isi CHECK di DB nyata terhadap enum | Doc 11 §8.3 (pemilik nilai final = CHECK Doc 09); melarang salinan kedua yang bisa saling bertentangan |
| D-S0-5 | `packages/api-contracts/openapi.yaml` = satu sumber kontrak; client hasil generate ditulis ke `packages/api-contracts/generated/` dan **di-commit**; CI regenerasi lalu `git diff --exit-code` | B-2 + gerbang D-8; app tidak butuh langkah codegen saat build (Doc 12 §1.3) |
| D-S0-6 | **Diamandemen 2026-11-18** (versi lama — "selama ADR-007 terbuka, securitySchemes ditunda; bentuk sesi cookie + BFF" — **tidak berlaku**, diganti keputusan pemilik): mekanisme = **Sanctum session cookie same-site**, dua guard/cookie (`web`/`elzara_web_session`, `admin`/`elzara_admin_session`), CSRF wajib semua mutasi, **tanpa BFF wajib**; `securitySchemes` cookie (cookieAuth + CSRF) **wajib ada** dan `security` dipasang **per operasi**; `x-elzara-auth: public\|customer\|admin\|s2s` tetap penanda kategori; operasi admin menambah `x-elzara-permission: <modul.aksi>`; endpoint auth (login/logout/OTP/sesi, social login+callback, registrasi hybrid) masuk spec; **bearer token di browser tetap dilarang**, tabel `personal_access_tokens` **tidak dibuat** | ADR-007 (BFF/sesi) amandemen 2026-11-18 D-1a..D-5a; Doc 11 §4.4 C/I; Doc 12 §1.2 G2 + §3.1 diamandemen gelombang yang sama; sign-off Fatharoni tercatat di chat bot Product Engineer pada 2026-11-22, dengan persetujuan klien dikonfirmasi pemilik (threat model §6); Q-2 dijawab pemilik proyek pada 2026-11-22: klien tidak punya IdP/SSO sendiri; mekanisme sesi Sanctum dua guard tetap, tidak ada provider OIDC |
| D-S0-7 | Presigned URL memakai `MINIO_PUBLIC_ENDPOINT` (host yang dijangkau browser, lewat edge `/storage`), terpisah dari endpoint internal `AWS_ENDPOINT_URL` | Blueprint §11 + Doc 11 B-9; menutup butir terbuka `docs/runbooks/object-storage-unreachable.md:68` dan ADR-008 |
| D-S0-8 | Rumah kode lintas modul: `app/Platform/` (enum, ApiProblem, state-machine runner, outbox+dispatcher, migrasi trio reliability) dan `app/ModuleLoader/` (service provider glob). Platform **bukan** modul domain: tanpa logika bisnis, tanpa model milik modul | B1 tetap satu penulis per tabel; Doc 01 §3.1 dan Doc 10 M-1/M-2 diselaraskan lewat PR dokumen ini |
| D-S0-9 | Path & nama migrasi Sprint 0: `0001`–`0003_Framework_*` di `database/migrations/` (bawaan framework saja); `0004`–`0006_Platform_*` di `app/Platform/Infrastructure/Database/Migrations/`; tabel modul `0007+` di `app/Modules/<X>/Infrastructure/Database/Migrations/` dengan format `NNNN_<Module>_<slug>.php`. **Diamandemen 2026-11-18 mengikuti disk:** trio reliability Platform sudah ditulis sebagai **satu** berkas `0001_Platform_create_reliability_tables.php` (outbox/inbox/idempotency), `database/migrations/` kosong (tanpa migrasi framework bawaan) — penomoran `0004–0006` lama **tidak berlaku**; band Sprint 0 tetap `0001–0199`, basename unik, pemilik = direktori (M-1..M-4, Doc 10 §1); migrasi Identity berikutnya melanjutkan nomor yang belum dipakai di band | M-1/M-2/M-3 + Appendix B langkah 3; basename unik ditegakkan CI C2; Doc 11 §4.4 G |
| D-S0-10 | `webhook_receipts` **bukan** deliverable Sprint 0 (tabel Payments L7, FK ke `payment_intents`, band Sprint 2). Yang dibangun sekarang: mekanisme dedup `(provider, provider_event_id)` lewat `inbox_events` + test-nya | Doc 09 §9, Doc 10 §4/§4.1 ("salah band" bukan "salah nomor") |
| D-S0-11 | `DemoSeeder` Sprint 0 menanam hanya yang tabelnya ada di band `0001–0199` (akun, `media`, SVG orisinal, flag). Angka "10 produk + 2 batch + 1 kuotasi" pindah ke gerbang Sprint 1 dan Sprint 5 | Doc 10 §4; Doc 03 0.11 dan spec §2 sudah diubah mengikuti ini |
| D-S0-12 | Nama skrip root yang sah: `dev:up`, `dev:down`, `dev:ps`, `dev:logs`, `dev:composer`, `dev:npm`, `dev:artisan`, `dev:migrate`, `dev:migrate:fresh`, `dev:routes`, `dev:test`, `dev:db-fresh`, `dev:db-demo`, `dev:db-snapshot`; `ci:lint`, `ci:analyse`, `ci:test`, `ci:concurrency`, `ci:contracts`, `ci:frontend`, `ci:schema`, `ci:env`, `ci:images`, `ci:down-check`, `ci:all`. Nama lain = bug dokumentasi | aturan anti-tabrakan Doc 10 §5.1; hasil normalisasi Lampiran B |
| D-S0-13 | CSP per app: `script-src 'self' 'nonce-…'` (nonce per request). `'self'` polos memutus hydration Next.js | spec §2 0.16; Doc 08 §6 (celah CSP situs referensi tidak ditiru) |
| D-S0-14 | Baseline versi terkunci dan **terukur**: Laravel 13.42.0, Next 16.4.6, image `node:22`, PostgreSQL 16, Redis 7, MinIO, Elasticsearch self-managed Basic (profil search). Versi upstream diambil dari registry di Task 1, bukan dari ingatan. **Diamandemen 2026-11-18:** baseline registry terbaru = Laravel **13.43.0**, Horizon **5.52.0** (v6 tidak ada — `^6` tidak pernah resolve), phpunit **13.4.7**, phpstan **2.3.6**, qossmic/deptrac **2.0.4**; daftar lengkap paket auth/RBAC + versi di Doc 11 §4.4 D (sumber mengikat, disalin bukan diturunkan ulang); Redis = ekstensi **phpredis**, predis **diganti** (tidak dipakai) | Doc 01 §2 (aturan "perbarui lewat PR dokumen"); Blueprint §2.1; Doc 11 §4.4 D (verifikasi registry 2026-11-18) |
| D-S0-15 | Skema Sprint 0 ikut dua koreksi kolom hasil penyusunan: `staff_2fa_secrets.recovery_hashes text[]` (sepuluh recovery code ter-hash, bukan satu) dan `request_id varchar(26)` pada `audit_logs` + `webhook_receipts` (Doc 12 §3.2 menuntutnya). **Diamandemen 2026-11-18:** klaim lama "jumlah tabel tidak berubah" **tidak berlaku** — ADR-012 (RBAC/identitas/dependensi) menambah `user_identities` + `activity_log` dan mengganti pivot lama; angka tabel **tidak ditulis tangan di sini**, satu-satunya pemilik = Doc 09 §19 (Doc 11 §4.4 G); `recovery_hashes text[]` sepuluh kode tetap berlaku dan kini mengikat semua operator (Task 11/12) | Doc 09 §2/§17, Doc 12 §3.2; Doc 11 §4.4 A/B/G |

## 2. Urutan eksekusi dan gerbang

Urutan mengikuti spec §5; setiap task di bawah sudah bernomor global dan **hanya** boleh dikerjakan setelah task sebelumnya ter-commit hijau.

| # | Task | ID Doc 03 | Jalur | Bergantung pada |
|---|---|---|---|---|
| 1 | Verifikasi toolchain + versi upstream | pra-0.1 | A | — |
| 2 | Scaffold monorepo tiga app | 0.1 | A | 1 |
| 3 | Docker dev satu perintah | 0.2 | A | 2 |
| 4 | `packages/api-contracts` + gerbang D-8 | 0.5 | A | 3 |
| 5 | CI (lint, analyse, test, Testcontainers, schema, tiga image) | 0.3 | A | 4 |
| 6 | Migrasi fondasi platform `0001–0006` | 0.7 | B | 5 |
| 7 | ApiProblem + request-id + peta exception | 0.7 | B | 6 |
| 8 | State-machine runner empat sumbu | 0.7 | B | 6 |
| 9 | Transactional outbox + dispatcher tujuh antrian | 0.7 | B | 6 |
| 10 | Idempotensi + inbox dedup + architecture test B1–B6 | 0.7 | B | 7, 8, 9 |
| 11 | Migrasi Identity + Settings + Content + Audit `0007–0027` (bentuk ADR-012, amandemen 2026-11-18) | 0.6 | C | 6, 10 |
| 12 | Auth sesi + RBAC sepuluh role + 2FA | 0.6 | C | 11 |
| 13 | MinIO enam bucket + presign endpoint publik | 0.9 | C | 11 |
| 14 | `localizations` + `feature_flags` (kill switch) + `settings` | 0.10 | C | 11 |
| 15 | Seeder Reference (idempoten) vs Demo + factories | 0.11 | C | 12, 13, 14 |
| 16 | Design token tiga lapis + komponen di dua app | 0.8 | D | 2, 3 |
| 17 | Keamanan edge + CSP per app | 0.16 | D | 16 |
| 18 | Observability + backup off-node + drill PITR | 0.12 | D | 17 |
| 19 | Threat model diselaraskan + ADR ditutup | 0.13, 0.14 | D | 18 |
| 20 | CD staging mandiri + rollback + uji restore nyata | 0.4 | D | 19 |

Jalur **D** (Task 16–17) boleh dimulai paralel oleh orang kedua begitu Task 3 selesai, karena tidak berbagi berkas dengan jalur backend — inilah jawaban atas "usahakan tidak saling bertabrakan" (Doc 10 §5.1). Task 15 dan 20 adalah penutup jalur C dan D.

**Gerbang akhir Sprint 0** (Doc 03 §3): `composer dev:up` → tiga app jalan → login admin → storefront demo → satu order `awaiting_payment` tercatat lewat fixture (order lahir langsung `awaiting_payment`; `draft` tidak dipakai), replay `Idempotency-Key` yang sama menghasilkan response identik, `composer ci:all` hijau, dan keenam script audit (§5) lulus tanpa pengecualian.

### 2.1 Status di disk 2026-11-18 (rekonsiliasi scaffold — diverifikasi `ls`/`find`/`grep`, bukan klaim)

Scaffold Sprint 0 sudah ditulis sesi sebelumnya. **Tidak ada task yang berstatus "selesai" penuh** karena belum ada dependensi terpasang dan belum ada test yang pernah dijalankan. Status: **scaffold** = artefak ada; **parsial** = sebagian artefak ada, sisa disebutkan; **belum** = tidak ada artefak. Sisa pekerjaan dieksekusi lewat `.codex/plans/2026-11-18-*.md` (ditulis agen lain) dengan urutan agen mengikat di sana.

| Task | Status | Bukti di disk / sisa |
|---|---|---|
| 1 | parsial | toolchain host terukur (PHP 8.3.30, Node v26.13.1, Docker 29.6.2); verifikasi versi upstream 2026-11-18 sudah tercatat di Doc 11 §4.4 D — angka Task 1 di bawah adalah baseline lama, yang mengikat = §4.4 D |
| 2 | scaffold | `apps/api` (artisan, bootstrap, config, routes, phpunit.xml, 22 modul, `app/Platform`, `app/ModuleLoader` glob), `apps/admin`/`apps/web` (App Router + middleware + `lib/api.ts`), `packages/{api-contracts,shared-types,ui}`, root README; sisa: `composer.json` menyimpang dari §4.4 D (predis + phpunit ^12.5 + paket auth belum masuk) → 0.17 |
| 3 | scaffold | `docker/compose.yaml` 11 service + healthcheck + profiles + `minio/init-buckets.sh` + `nginx/dev.conf` + Dockerfile per app (root context); nama lama `compose.dev.yaml`/`docker/api.Dockerfile` **diganti** mengikuti disk; belum pernah `up` — menunggu lockfile |
| 4 | parsial | `openapi.yaml` 18 operationId; `codegen.sh`/`check-contract-drift.sh` ada; `generated/` kosong — client TS belum di-generate; securitySchemes + endpoint auth sedang ditambah agen lain (D-S0-6 amandemen) |
| 5 | parsial | 4 workflow `{api,admin,web,contracts}.yml` (bukan `ci.yml` tunggal — nama lama **diganti**); belum pernah hijau; Testcontainers harness belum |
| 6 | scaffold (sebagian) | migrasi Platform di disk = **satu** berkas `0001_Platform_create_reliability_tables.php` (outbox/inbox/idempotency); skema tiga berkas `0004–0006` lama **diganti** satu berkas trio ini; migrasi framework bawaan tidak ada di `database/migrations/` (kosong) — keputusan D-S0-9 disesuaikan disk |
| 7 | scaffold | `app/Platform/Http/ApiProblem.php` ada; test exception belum dijalankan |
| 8 | scaffold | `StateMachine/{Axis,TransitionGraph,StateMachineRunner,InvalidTransition}.php` + `Statuses/` 4 sumbu ada; test transisi belum |
| 9 | scaffold | `Outbox/{OutboxWriter,OutboxDispatcher,DispatchDomainEvent,DomainEvent,QueueRouting}.php` ada; test belum |
| 10 | parsial | tabel trio ada di migrasi 0001; mekanisme middleware idempotensi/inbox + deptrac B1–B6 belum terlihat di disk dan belum diuji |
| 11 | belum | semua direktori `app/Modules/*/Infrastructure/Database/Migrations/` hanya `.gitkeep`; **bentuk di bawah sudah diamandemen ke ADR-012** |
| 12 | belum | tidak ada guard/policy/CSRF/2FA; `config/session.php` masih cookie default tunggal — **bentuk di bawah sudah diamandemen** |
| 13 | parsial | `docker/minio/init-buckets.sh` + policies + cors ada (nama lama `provision-buckets.sh` **diganti**); endpoint presign/finalize belum |
| 14 | belum | migrasi Settings/Content masih `.gitkeep` |
| 15 | belum | seeder/factory belum ada; bentuk di bawah diamandemen ke spatie |
| 16 | parsial | `packages/ui`: tokens 3 lapis + presets + brand + **Button saja**; komponen lain belum |
| 17 | parsial | middleware kedua app + `docker/nginx/dev.conf` ada; `infrastructure/nginx/` README-only; uji header/CSP belum |
| 18 | parsial | `config/logging.php` ada; `infrastructure/monitoring/` README-only; OTel/drill belum |
| 19 | parsial | `docs/threat-model.md` draf ada; **12 berkas ADR** ada (001–012, terhitung via ls); ADR-007 (BFF/sesi) mekanisme Accepted 2026-11-18, ADR-012 (RBAC/identitas/dependensi) Accepted; sign-off klien belum |
| 20 | belum | tidak ada `deploy-staging.yml` di disk |

## 3. Matriks kepemilikan berkas (tidak ada dua penulis di satu jalur)

Catatan 2026-11-18: matriks ini tetap konvensi plan ini. Gelombang 2026-11-18 (dependensi + auth) punya matriks kepemilikan sendiri di `.codex/plans/2026-11-18-*.md` dan definisi peran agen di `.codex/agents/` (ditulis agen lain; urutan mengikat migrasi DB → fitur → testing → rebuild docker). Nama berkas di matriks yang berbeda dari disk (`ci.yml` tunggal, `docker/nginx/elzara.conf`) adalah nama rencana lama — yang berlaku = artefak disk (§2.1).

| Jalur | Task | Direktori/berkas yang dimiliki |
|---|---|---|
| A | 1–5 | root `.gitattributes`, `.gitignore`, `composer.json`, `Makefile`, `README.md`, `.env.example`; kerangka `apps/api` (`bootstrap`, `config` dasar, `routes`, `app/ModuleLoader`); scaffold `apps/admin`, `apps/web`; `docker/**`; `.github/workflows/ci.yml`; `packages/api-contracts/**`; `packages/shared-types/**` (manifest saja); `.codex/plans/artefak-sprint-0/**` |
| B | 6–10 | `app/Platform/**`; `database/migrations/0001–0003`; `app/Platform/Infrastructure/Database/Migrations/0004–0006`; `config/{queue,horizon}.php` (nama antrian); `tests/{Unit,Feature,Concurrency,Contract,Architecture}/**` milik platform |
| C | 11–15 | `app/Modules/{Identity,Content,Settings,Audit}/**` termasuk migrasi `0007–0027`; stub `app/Modules/Orders/**` milik Task 14; `database/seeders/**`, `database/factories/**`; `config/filesystems.php`; `docker/minio/**`; tambahan baris `MINIO_PUBLIC_ENDPOINT` di root `.env.example`; `docker/nginx/elzara.conf` (location `/storage` saja) |
| D | 16–20 | `packages/ui/**` (isi); `apps/web/**`, `apps/admin/**`; `infrastructure/nginx/**` produksi + `security-headers.ts`; `infrastructure/monitoring/**`; `.github/workflows/deploy-staging.yml`; `docker/compose.staging.yaml` (lengkapi, jangan duplikasi); `docs/adr/ADR-004|008|010|011`; `docs/threat-model.md`; `docs/runbooks/{backup-restore-drill,emergency-disable-checkout}.md` |

## 4. Yang TIDAK boleh muncul (dijaga CI + audit, bukan diingat-ingat)

| Godaan | Kenapa ditolak | Rujukan |
|---|---|---|
| Satu app Inertia untuk storefront + admin | topologi sudah berubah ke tiga deployment | B-1, ADR-003 K-2 |
| Endpoint ditulis dulu, didokumentasikan belakangan | kontrak adalah gerbang PR | B-2, D-8 |
| Redis lock sebagai penjamin kebenaran stok | lease bisa kedaluwarsa; kebenaran = conditional UPDATE + constraint | Blueprint §8.2 |
| SQLite untuk test concurrency | tidak memvalidasi locking PostgreSQL | B-11, D-11 |
| Bucket MinIO ketujuh untuk dump backup | backup wajib off-node | Doc 11 §8.7 |
| `webhook_receipts` di Sprint 0 | tabel Payments L7, band Sprint 2 | Doc 10 §4.1 |
| Produk/batch/kuotasi di `DemoSeeder` Sprint 0 | tabelnya belum ada pada band `0001–0199` | Doc 10 §4 |
| Klaim HA atau 99,9 % | satu node adalah SPOF by design | B-15, D-22 |
| Broker event (RabbitMQ/Kafka/NATS) | belum ada kebutuhan fan-out nyata | Blueprint §9 |
| Role kesebelas (Editor/Moderator) | pemegang permission belum diputus klien | Q-8, B-10 |
| Bearer token di browser / tabel `personal_access_tokens` (tidak dibuat) | mekanisme diputuskan 2026-11-18: sesi cookie same-site + CSRF; s2s = HMAC/jaringan internal — alasan lama "ADR-007 belum tertutup" diganti "mekanisme Accepted, token browser ditolak by design" | ADR-007 (BFF/sesi) D-3a, Doc 11 §4.4 C |
| Publish migrasi vendor spatie/sanctum mentah | ditulis ulang di bawah namespace + band kita; migrasi PAT Sanctum dikecualikan | ADR-012 (RBAC/identitas/dependensi), Doc 10 §4 |
| `composer install`/`npm install` di host | activitylog 5.1.5 + phpunit 13.4.7 minta PHP 8.4; host 8.3.30; lockfile host bukan baseline | Doc 11 §4.4 D, Doc 03 0.17 |
| Auto-regenerate spec dari Scramble di CI | Scramble = draf; `openapi.yaml` source of truth; drift ditolak `check-contract-drift.sh` | Doc 11 §4.4 E |
| Angka jumlah tabel hasil taksiran | satu-satunya sumber = Doc 09 §19 hasil parser | Doc 10 §12 |

---

### Task 1 — Verifikasi toolchain dan versi upstream  (ID Doc 03: prasyarat 0.1)

> **Amandemen 2026-11-18:** angka "Expected" di bawah adalah pengukuran 2026-11-17 (historis, jujur). Baseline yang **mengikat** sekarang = Doc 11 §4.4 D (verifikasi registry 2026-11-18): laravel/framework **13.43.0**, horizon **5.52.0**, phpunit **13.4.7**, phpstan **2.3.6**, qossmic/deptrac **2.0.4**, plus paket auth/RBAC di D-S0-14. Toolchain host terukur ulang 2026-11-18: PHP 8.3.30, Composer 2.9.4, Node v26.13.1, npm 11.20.0, Docker 29.6.2 — instalasi dependensi tetap container-only (Doc 03 0.17).

**Files:**
- Create: `.codex/plans/artefak-sprint-0/env-baseline.md`
- Modify: tidak ada — task ini tidak menyentuh kode produk.
- Test: tidak ada test otomatis; gerbangnya angka terukur yang dibaca ulang oleh Task 2-5.

Semua angka di bawah diukur di mesin kerja, bukan diingat (spec §4). Bila ada yang berbeda saat dijalankan ulang, koreksi `env-baseline.md` lebih dulu sebelum Task 2 — keputusan D-S0-1, D-S0-5, dan seluruh jalur perintah berdiri di atas angka ini.

- [ ] **Step 1: Pastikan daemon Docker dan plugin compose hidup**

Run: `docker info --format '{{.ServerVersion}}'` — Expected: `29.6.2`. Bila `Cannot connect`, start Docker Desktop dan ulangi; jangan lanjut.
Run: `docker compose version` — Expected: `Docker Compose version v2.x`. Seluruh plan memakai `docker compose` (subcommand), bukan `docker-compose`.

- [ ] **Step 2: Catat PHP host dan buktikan `ext-redis` tidak ada**

Run: `php -v | head -1` — Expected: `PHP 8.3.30 (cli)`, **bukan** 8.4; inilah alasan `config.platform.php = 8.4.0` dikunci di Task 2.
Run: `php -m | grep -ic '^redis$'` — Expected: `0` (driver Redis untuk cache/queue/session tidak tersedia di host).
Run: `composer --version | head -1` — Expected: `Composer version 2.9.4`. Composer host wajib ada karena jalur perintah resmi adalah `composer dev:*` / `composer ci:*` (D-S0-1).
Run: `php -m | grep -icE '^(pdo_pgsql|intl|bcmath|zip|gd)$'` — Expected: `5` (cukup untuk IDE; eksekusi app tetap di container).

- [ ] **Step 3: Catat Node, PostgreSQL client, make, jq, openssl**

Run: `node -v && npm -v && psql --version | head -1 && openssl version` — Expected: `v26.13.1`, `11.20.0`, `psql (PostgreSQL) 15.10`, `OpenSSL 3.5.5`.
Run: `make --version | head -1` — Expected: `make: command not found`, menegaskan Makefile tidak boleh jadi satu-satunya jalur perintah (spec §4).
Run: `jq --version | head -1` — Expected: `jq: command not found`. Konsekuensi: gerbang yang di dokumen lain ditulis `| jq` dijalankan dengan ekuivalen `php -r` (Task 2 Step 4, Task 4 Step 6).
Run: `python --version` — Expected: `Python 3.13.0` (cadangan pembaca JSON; jalur utama pakai container PHP).

- [ ] **Step 4: Catat line ending host dan working tree bersih**

Run: `git config core.autocrlf` — Expected: `true`. Tidak diubah di config global tiap mesin; mitigasinya `.gitattributes` (`* text=auto eol=lf`, sudah ada) yang menang atas `core.autocrlf`, plus runner Linux di Task 5. Kegagalan karena CRLF dihitung sebagai bug konfigurasi, bukan bug kode.
Run: `git status --porcelain | wc -l` — Expected: `0` (tree bersih sebelum Task 2 menambah file).

- [ ] **Step 5: Verifikasi versi container DAN versi upstream dari registry**

Run: `docker run --rm php:8.4-cli php -v | head -1` — Expected: `PHP 8.4.x (cli)`.
Run: `docker run --rm node:22 node -v && docker run --rm node:22 npm -v` — Expected: `v22.x.y` dan npm `10.x`/`11.x`; angka npm **dari container** inilah yang di-pin ke `packageManager` di Task 2 Step 5, bukan npm host 11.20.0.
Run (cabang stabil 13.x pertama Laravel, dari Packagist di dalam container):
```bash
docker run --rm php:8.4-cli php -r '
foreach (["laravel/framework","laravel/laravel"] as $p) {
    $j = json_decode(file_get_contents("https://repo.packagist.org/p2/$p.json"), true);
    foreach ($j["packages"][$p] as $r) {
        if (preg_match("/^v?\d+\.\d+\.\d+$/", $r["version"])) { echo $p, "=", $r["version"], PHP_EOL; break; }
    }
}'
```
Expected (terukur 2026-11-17): `laravel/framework=v13.42.0`, `laravel/laravel=v13.11.0`. Ekuivalen interaktif di dalam app: `docker run --rm -v "$PWD:/w" -w /w/apps/api php:8.4-cli composer show laravel/framework --all` (memakai `composer show`, karena `npm view laravel-framework` tidak berlaku untuk paket PHP).
Run (tooling Node): `docker run --rm node:22 sh -lc 'for p in next openapi-typescript openapi-fetch @stoplight/spectral-cli; do echo "$p=$(npm view "$p" version)"; done'`
Expected: `next=16.4.6`, `openapi-typescript=7.13.0`, `openapi-fetch=0.17.0`, `@stoplight/spectral-cli=6.17.1`.
Run (tooling PHP):
```bash
docker run --rm php:8.4-cli php -r '
foreach (["laravel/pint","larastan/larastan","laravel/horizon"] as $p) {
    $j = json_decode(file_get_contents("https://repo.packagist.org/p2/$p.json"), true);
    echo $p, "=", $j["packages"][$p][0]["version"], PHP_EOL;
}'
```
Expected: `laravel/pint=v1.32.3`, `larastan/larastan=v3.15.0`, `laravel/horizon=v5.51.0`. Versi PHPUnit **tidak** diambil dari registry: yang dipakai adalah constraint bawaan skeleton `laravel/laravel` v13.11.0 dan dicatat dari Step 4 Task 2.

- [ ] **Step 6: Tulis `env-baseline.md`**

Create `.codex/plans/artefak-sprint-0/env-baseline.md` dengan tabel berikut (kolom "Terukur" diisi keluaran Step 1-5):

| Aspek | Target arsitektur | Terukur 2026-11-17 | Keputusan mengikat |
|---|---|---|---|
| PHP host | 8.4 | 8.3.30 | perintah PHP app di container `php:8.4-*`; host hanya IDE |
| `ext-redis` host | ada di container | tidak ada | test yang menyentuh Redis: CI/Testcontainers |
| PostgreSQL | 16 | client 15.10, tanpa server lokal | server test selalu container 16 |
| Node/npm host | build di `node:22` | v26.13.1 / 11.20.0 | `npm ci && next build` hanya di `node:22` |
| Composer host | >= 2.7 | 2.9.4 | `composer dev:*` / `composer ci:*` = jalur resmi |
| `make` / `jq` | opsional | tidak ada keduanya | Makefile tipis; gerbang pakai `php -r` |
| OpenSSL host | perlu TLS dev | 3.5.5 | sertifikat self-signed localhost dibuat Task 3 |
| Docker | hidup | 29.6.2 + compose v2 | prasyarat Task 3 terpenuhi hari ini |
| `git core.autocrlf` | false idealnya | true | `eol=lf` di `.gitattributes` + runner Linux |
| `laravel/framework` / skeleton | 13.x stabil | v13.42.0 / v13.11.0 | `create-project laravel/laravel:^13.0` |
| `next` | 16.x stabil | 16.4.6 | `create-next-app@16.4.6` |
| `openapi-typescript` / `openapi-fetch` / `spectral-cli` | terbaru | 7.13.0 / 0.17.0 / 6.17.1 | dipakai Task 4 |
| `pint` / `larastan` / `horizon` | terbaru | v1.32.3 / v3.15.0 / v5.51.0 | di-`require` Task 2, dipakai Task 5 |

Tambahkan baris penutup: `Bekukan versi: angka di atas adalah baseline Sprint 0; upgrade = PR khusus, bukan efek samping task lain.`

- [ ] **Step 7: Commit**

```bash
git add .codex/plans/artefak-sprint-0/env-baseline.md
git commit -m "docs(sprint-0): baseline toolchain host dan versi upstream terukur"
```

### Task 2 — Scaffold monorepo tiga app + module loader glob  (ID Doc 03: 0.1)

**Files:**
- Create: `composer.json`, `Makefile`, `README.md`, `apps/api/**` (kerangka), `apps/admin/**` (scaffold), `apps/web/**` (scaffold), `apps/api/app/ModuleLoader/ModuleServiceProvider.php`, `apps/api/config/elzara.php`, `apps/api/app/Modules/Identity/**` (lapis kosong), `apps/api/tests/Unit/ModuleLoader/ModuleLoaderTest.php`, `apps/api/tests/Feature/Skeleton/SkeletonRoutingTest.php`, `packages/shared-types/{package.json,tsconfig.json,src/.gitkeep}`, `packages/ui/.gitkeep`, `packages/api-contracts/generated/.gitkeep`, `docs/{adr,runbooks,api}/.gitkeep`
- Modify: `.gitignore` (pola ter-anchor root), `.gitattributes` (aturan berkas generator)
- Test: dua berkas `tests/` di atas

Batas: `packages/ui` dan `packages/shared-types` hanya manifest + folder; isi token/komponen/tipe milik task 0.8. `app/Platform/**`, `database/migrations/**`, `database/seeders/**`, `infrastructure/monitoring/**` tidak disentuh.

- [ ] **Step 1: Buat pohon direktori sesuai Doc 01 §3.1 (hanya yang jadi milik task ini)**

```bash
mkdir -p apps packages/api-contracts/generated packages/shared-types/src packages/ui \
         docker/nginx docs/adr docs/runbooks docs/api .github/workflows
touch packages/ui/.gitkeep packages/api-contracts/generated/.gitkeep packages/shared-types/src/.gitkeep \
      docs/adr/.gitkeep docs/runbooks/.gitkeep docs/api/.gitkeep
```
Expected: `ls apps` kosong (diisi Step 4-5). `infrastructure/monitoring` sengaja tidak dibuat di sini (milik task 0.12).

- [ ] **Step 2: Tegaskan LF dan tandai berkas generator di `.gitattributes`**

Append:
```text
# keluaran generator: dibandingkan byte-per-byte di CI, tidak pernah diedit tangan (Doc 12 §1.3)
packages/api-contracts/generated/** text eol=lf -diff
docker/nginx/certs/*.pem text eol=lf
```
Run: `git check-attr text eol -- apps/api/composer.json` — Expected: `text: auto` dan `eol: lf` (aturan `*.json text eol=lf` yang sudah ada menjangkau app).

- [ ] **Step 3: Perbaiki `.gitignore` untuk monorepo**

Ganti `/vendor/` menjadi `vendor/` dan `/node_modules/` menjadi `node_modules/` — pola ber-anchor root tidak menutupi `apps/api/vendor`. Tambah blok:
```text
.next/
out/
next-env.d.ts
storage/app/testing/
docker/nginx/certs/
```
Run: `docker run --rm -v "$PWD:/w" -w /w php:8.4-cli php -r 'echo "mount-ok\n";'` — Expected: `mount-ok` (memastikan bind mount `$PWD` dapat ditulisi sebelum scaffold).

- [ ] **Step 4: Scaffold API Laravel 13 di dalam container (host tidak punya 8.4)**

```bash
docker run --rm -v "$PWD:/w" -w /w composer:2 create-project laravel/laravel:^13.0 apps/api --no-interaction --prefer-dist
docker run --rm -v "$PWD:/w" -w /w/apps/api composer:2 config platform.php 8.4.0
docker run --rm -v "$PWD:/w" -w /w/apps/api composer:2 require laravel/horizon:^5.51
docker run --rm -v "$PWD:/w" -w /w/apps/api composer:2 require --dev larastan/larastan:^3.15
```
Expected: `Created project in ... apps/api`, dan `docker run --rm -v "$PWD:/w" -w /w/apps/api php:8.4-cli composer show laravel/framework | head -2` menampilkan versi `v13.4x`. Catat constraint `phpunit/phpunit` yang terpasang (bawaan skeleton) di pesan commit Step 11.
Bukti `config.platform` (pengganti `jq` yang tidak ada di host):
```bash
docker run --rm -v "$PWD:/w" -w /w php:8.4-cli php -r '$c=json_decode(file_get_contents("apps/api/composer.json"),true);echo json_encode($c["config"]["platform"]),PHP_EOL;'
```
Expected: `{"php":"8.4.0"}`.
Bukti install di host gagal keras (bukan diam-diam): `cd apps/api && composer install --dry-run 2>&1 | grep -ci "8.4"; cd ../..`
Expected: exit code composer `1` dan hitungan `>= 1` baris yang menyebut `8.4` (penolakan `platform.php` vs PHP host 8.3.30).

- [ ] **Step 5: Scaffold kedua app Next.js di `node:22`, dua lockfile terpisah**

```bash
docker run --rm -v "$PWD:/w" -w /w node:22 npx --yes create-next-app@16.4.6 apps/web --ts --tailwind --eslint --app --src-dir --import-alias "@/*" --use-npm --yes
docker run --rm -v "$PWD:/w" -w /w node:22 npx --yes create-next-app@16.4.6 apps/admin --ts --tailwind --eslint --app --src-dir --import-alias "@/*" --use-npm --yes
for a in web admin; do docker run --rm -v "$PWD:/w" -w "/w/apps/$a" node:22 npm pkg set packageManager="npm@$(docker run --rm node:22 npm -v)"; done
```
Expected: `ls -1 apps/*/package-lock.json | wc -l` = `2`. Tidak ada lockfile Node di root: kedua app **tidak** berbagi lockfile (spec 0.1).
Ganti `apps/web/src/app/page.tsx`:
```tsx
export default function Home() {
  return (
    <main>
      <h1>Elzara storefront placeholder</h1>
      <p>Sprint 0 - kerangka monorepo. Katalog milik Sprint 2.</p>
    </main>
  );
}
```
Ganti `apps/admin/src/app/page.tsx` sama bentuknya dengan `<h1>Elzara admin placeholder</h1>`. Tambah di **kedua** app, `apps/admin/src/app/health/route.ts` dan `apps/web/src/app/health/route.ts` (pola path `apps/commerce-*` adalah nama lama, diganti `apps/admin`/`apps/web`; nilai `app` diisi `web` / `admin`):
```ts
export const dynamic = "force-dynamic";

export async function GET(): Promise<Response> {
  return Response.json({ status: "ok", app: "web" });
}
```

- [ ] **Step 6: `composer.json` root = pembungkus skrip saja (D-S0-1)**

Create `composer.json`:
```json
{
    "name": "elzara/root",
    "description": "Pembungkus skrip dev/CI monorepo Elzara. Bukan aplikasi PHP.",
    "license": "proprietary",
    "require-dev": {},
    "config": {},
    "scripts": {
        "dev:up": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml --env-file .env up -d --build",
        "dev:down": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml --env-file .env down",
        "dev:logs": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml --env-file .env logs -f --tail=100",
        "dev:ps": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml --env-file .env ps",
        "dev:composer": "docker run --rm -v \"$PWD:/w\" -w /w/apps/api composer:2",
        "dev:artisan": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec api php artisan",
        "dev:migrate": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec api php artisan migrate",
        "dev:routes": "@dev:artisan route:list",
        "dev:test": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec -T api php artisan test",
        "dev:npm": "docker run --rm -v \"$PWD:/w\" -w /w/apps node:22",
        "ci:lint": "docker run --rm -v \"$PWD:/w\" -w /w/apps/api php:8.4-cli sh -lc 'composer install --no-interaction && vendor/bin/pint --test'",
        "ci:analyse": "docker run --rm -v \"$PWD:/w\" -w /w/apps/api php:8.4-cli sh -lc 'composer install --no-interaction && echo Dijalankan nyata di Task 5 (phpstan) && exit 0'",
        "ci:test": "docker run --rm -v \"$PWD:/w\" -w /w php:8.4-cli sh -lc 'echo Dijalankan lewat ci:all (butuh service Postgres-Redis) && exit 0'",
        "ci:contracts": "docker run --rm -v \"$PWD:/w\" -w /w/packages/api-contracts node:22 sh -lc 'npm ci && npm run lint && npm run generate'",
        "ci:frontend": "docker run --rm -v \"$PWD:/w\" -w /w/apps/web node:22 sh -lc 'npm ci && npm run lint && npm run build'",
        "ci:schema": "docker run --rm -v \"$PWD:/w\" -w /w php:8.4-cli sh -lc 'echo Dijalankan lewat ci:all && exit 0'",
        "ci:env": "docker run --rm -v \"$PWD:/w\" -w /w php:8.4-cli sh -lc 'echo Dijalankan lewat ci:all && exit 0'",
        "ci:concurrency": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec -T api php artisan test --testsuite=Concurrency",
        "ci:images": "docker run --rm -v \"$PWD:/w\" -w /w php:8.4-cli sh -lc 'echo Dijalankan lewat ci:all && exit 0'",
        "ci:all": "sh -lc 'composer ci:lint && composer ci:analyse && composer ci:contracts && composer ci:frontend'",
        "ci:down-check": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml --env-file .env down -v"
    },
    "scripts-descriptions": {
        "dev:up": "Hidupkan stack dev lengkap (satu perintah onboarding).",
        "ci:all": "Semua gerbang CI secara lokal, hasil identik dengan GitHub Actions."
    }
}
```
`"config": {}` **sengaja kosong**: bila `platform.php` ikut dipasang di root, `composer dev:*` di host 8.3 mati dan jalur perintah resmi tidak bisa dipakai. Kunci `8.4.0` hanya ada di `apps/api/composer.json`. Skrip `ci:analyse`, `ci:test`, `ci:schema`, `ci:env`, `ci:images` adalah stub **eksplisit** di task ini (pesan `echo` menyebut statusnya, exit 0) dan diisi jalur nyatanya pada Task 4/5 — bukan klaim hijau.
Create `Makefile` (pembungkus tipis, hanya untuk mesin yang punya make):
```make
up: ; composer dev:up
down: ; composer dev:down
test: ; composer dev:test
routes: ; composer dev:routes
ci: ; composer ci:all
.PHONY: up down test routes ci
```
Create `README.md`: tautan ke `docs/specs/sprint-0-fondasi.md`, blok `cp .env.example .env && composer dev:up`, aturan "semua perintah PHP/Node lewat container", dan cara reset data (`composer dev:artisan migrate:fresh`).

- [ ] **Step 7: Module loader glob (M-2) — tanpa berkas daftar manual**

Create `apps/api/app/ModuleLoader/ModuleServiceProvider.php`:
```php
<?php

declare(strict_types=1);

namespace App\ModuleLoader;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * M-2 (Doc 10 §1): path modul ditemukan dengan glob, bukan daftar manual,
 * sehingga menambah modul tidak mengedit berkas bersama dan dua PR modul
 * berbeda tidak konflik.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $modules = self::discover(base_path('app/Modules'));

        foreach ($modules as $module) {
            $infra = $module['path'] . '/Infrastructure';

            if (is_dir($infra . '/Resources/views')) {
                $this->loadViewsFrom($infra . '/Resources/views', $module['name']);
            }
            if (is_dir($infra . '/Lang')) {
                $this->loadTranslationsFrom($infra . '/Lang', $module['name']);
            }
            if (is_file($infra . '/routes.php')) {
                Route::middleware('api')->prefix(config('elzara.api_prefix'))->group($infra . '/routes.php');
            }
        }

        $migrations = array_values(array_filter(array_map(
            static fn (array $m): string => $m['path'] . '/Infrastructure/Database/Migrations',
            $modules,
        ), static fn (string $p): bool => is_dir($p)));

        if ($migrations !== []) {
            $this->loadMigrationsFrom($migrations);
        }
    }

    /** @return list<array{name: string, path: string}> */
    public static function discover(string $root): array
    {
        $modules = array_values(array_map(
            static fn (string $path): array => ['name' => basename($path), 'path' => $path],
            glob($root . '/*', GLOB_ONLYDIR | GLOB_NOSORT) ?: [],
        ));

        usort($modules, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $modules;
    }
}
```
Create `apps/api/config/elzara.php`:
```php
<?php

return [
    'api_prefix' => 'api/v1',
    'edge_url' => env('ELZARA_EDGE_URL', 'https://localhost'),
];
```
Create `apps/api/bootstrap/providers.php`:
```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\ModuleLoader\ModuleServiceProvider::class,
];
```
Ganti `apps/api/bootstrap/app.php` (`routes/api.php` dibuat manual di Step 8; `install:api` tidak dijalankan sebagai generator scaffold — Sanctum dipasang eksplisit lewat composer **di kontainer** (Doc 03 0.17) dan dikonfigurasi **mode sesi** `statefulApi`, bukan token; alasan lama "menarik model token selama ADR-007 belum tertutup" tidak berlaku sejak amandemen 2026-11-18):
```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__ . '/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            Illuminate\Cookie\Middleware\EncryptCookies::class,
            Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            Illuminate\Session\Middleware\StartSession::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Peta exception domain ke ApiProblem RFC 7807 dipasang task 0.7 (Doc 03), bukan di sini.
    })->create();
```
Bentuk kredensial di grup `api` di atas = **sesi cookie same-site** (ADR-007 (BFF/sesi) amandemen 2026-11-18; asumsi lama session/BFF diganti); task 0.6 membangun dua guard + CSRF, tanpa token di browser.

- [ ] **Step 8: Titik masuk rute api yang kosong + modul contoh `Identity`**

Create `apps/api/routes/api.php`:
```php
<?php

declare(strict_types=1);

// Titik masuk /api/v1. Rute modul dimuat ModuleServiceProvider (M-2).
// Stub kontrak 18 jalur wajib Blueprint §7 (+ endpoint auth gelombang 2026-11-18) ditambahkan Task 4; file ini kosong di akhir Task 2.
```
```bash
mkdir -p apps/api/app/Modules/Identity/{Domain,Application,Infrastructure/Database/Migrations,Contracts,Tests} \
         apps/api/tests/{Contract,Concurrency}
touch apps/api/app/Modules/Identity/Domain/.gitkeep \
      apps/api/app/Modules/Identity/Application/.gitkeep \
      apps/api/app/Modules/Identity/Infrastructure/Database/Migrations/.gitkeep \
      apps/api/app/Modules/Identity/Contracts/.gitkeep \
      apps/api/app/Modules/Identity/Tests/.gitkeep
```
Expected: struktur sama persis dengan Doc 01 §3.2. Folder `Infrastructure/Database/Migrations` kosong — penomoran `0001-0199` milik task skema, dan loader wajib tetap sehat tanpa isinya.

- [ ] **Step 9: Test loader menemukan modul lewat glob**

Create `apps/api/tests/Unit/ModuleLoader/ModuleLoaderTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Unit\ModuleLoader;

use App\ModuleLoader\ModuleServiceProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ModuleLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('app/testing/Modules');
        $this->rmkdir($this->root . '/Zeta/Infrastructure/Database/Migrations');
        $this->rmkdir($this->root . '/Alpha/Infrastructure');
        file_put_contents($this->root . '/Zeta/Infrastructure/routes.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->rrmdir(storage_path('app/testing'));
        parent::tearDown();
    }

    #[Test]
    public function discover_menemukan_modul_hanya_dari_glob(): void
    {
        $this->assertSame(['Alpha', 'Zeta'], array_column(ModuleServiceProvider::discover($this->root), 'name'));
    }

    #[Test]
    public function modul_tanpa_folder_migrations_tetap_ditemukan_tanpa_error(): void
    {
        $alpha = ModuleServiceProvider::discover($this->root)[0];
        $this->assertSame('Alpha', $alpha['name']);
        $this->assertDirectoryDoesNotExist($alpha['path'] . '/Infrastructure/Database/Migrations');
    }

    #[Test]
    public function modul_asli_identity_terdaftar(): void
    {
        $this->assertContains('Identity', array_column(ModuleServiceProvider::discover(base_path('app/Modules')), 'name'));
    }

    private function rmkdir(string $path): void
    {
        if (!is_dir($path)) {
            mkdir($path, 0o777, true);
        }
    }

    private function rrmdir(string $path): void
    {
        foreach (is_dir($path) ? scandir($path) ?: [] : [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                is_dir("$path/$entry") ? $this->rrmdir("$path/$entry") : unlink("$path/$entry");
            }
        }
        if (is_dir($path)) {
            rmdir($path);
        }
    }
}
```

- [ ] **Step 10: Test kerangka rute + pendaftaran testsuite**

Create `apps/api/tests/Feature/Skeleton/SkeletonRoutingTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Skeleton;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SkeletonRoutingTest extends TestCase
{
    #[Test]
    public function route_list_berlari_tanpa_error(): void
    {
        $this->artisan('route:list')->assertSuccessful();
    }

    #[Test]
    public function belum_ada_rute_api_v1_sebelum_task_05(): void
    {
        $api = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/'))->values();
        $this->assertCount(0, $api, 'Rute /api/v1 hanya boleh lahir bersama openapi.yaml (Doc 12 §1.1).');
    }
}
```
Berkas ini **diganti** pada Task 4 Step 5 (assertion nol rute tidak berlaku setelah stub kontrak masuk).
Edit `apps/api/phpunit.xml`, blok `<testsuites>` menjadi:
```xml
    <testsuites>
        <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
        <testsuite name="Feature"><directory>tests/Feature</directory></testsuite>
        <testsuite name="Contract"><directory>tests/Contract</directory></testsuite>
        <testsuite name="Concurrency"><directory>tests/Concurrency</directory></testsuite>
    </testsuites>
```
Run: `docker run --rm -v "$PWD:/w" -w /w/apps/api php:8.4-cli sh -lc 'php artisan test --testsuite=Unit,Feature'`
Expected: `OK (5 tests, ... assertions)` — dua test Task 2 Feature + tiga test loader; tidak ada yang menyentuh database.

- [ ] **Step 11: Manifest `packages/shared-types` (isi tipe milik task 0.8)**

Create `packages/shared-types/package.json`:
```json
{
    "name": "@elzara/shared-types",
    "version": "0.0.0",
    "private": true,
    "type": "module",
    "main": "src/index.ts",
    "types": "src/index.ts",
    "scripts": { "typecheck": "tsc --noEmit" },
    "devDependencies": { "typescript": "^5.7.3" }
}
```
Create `packages/shared-types/tsconfig.json`:
```json
{
    "compilerOptions": { "target": "ES2022", "module": "ESNext", "moduleResolution": "bundler", "strict": true, "noEmit": true, "skipLibCheck": true },
    "include": ["src/**/*.ts"]
}
```

- [ ] **Step 12: Gerbang akhir task**

Run: `composer dev:routes` — Expected: tabel rute tercetak tanpa error, kolom `URI` **tidak** memuat satu pun baris `api/v1/*`.
Run: `docker run --rm -v "$PWD:/w" -w /w php:8.4-cli php -r '$c=json_decode(file_get_contents("apps/api/composer.json"),true);echo json_encode($c["config"]["platform"]),PHP_EOL;'` — Expected: `{"php":"8.4.0"}`.
Run: `ls -1 apps/*/package-lock.json | wc -l && git status --porcelain | grep -cE 'vendor/|node_modules/'` — Expected: `2` lalu `0`.

- [ ] **Step 13: Commit**

```bash
git add .gitattributes .gitignore composer.json Makefile README.md apps packages docs/adr docs/runbooks docs/api
git commit -m "feat(sprint-0): scaffold monorepo tiga app dengan module loader glob"
```

### Task 3 — Docker dev satu perintah  (ID Doc 03: 0.2)

> **Amandemen 2026-11-18 (status disk):** scaffold docker sudah ada dalam bentuk berbeda dari rencana awal ini: `docker/compose.yaml` + `docker/profiles/staging.yaml` + `docker/profiles/production.yaml`, `docker/minio/init-buckets.sh`, `docker/nginx/dev.conf`. Nama `docker/compose.dev.yaml` (lama) tidak berlaku, diganti pola `compose.yaml` + file profile; nama `docker/nginx/elzara.conf` dan `docker/nginx/elzara.dev.conf` (lama) tidak berlaku, diganti `docker/nginx/dev.conf`; `docker/api.Dockerfile`/`docker/next.Dockerfile` (lama) tidak berlaku, diganti `Dockerfile` per app (`apps/api/Dockerfile`, `apps/admin/Dockerfile`, `apps/web/Dockerfile`). Semua referensi `compose.dev.yaml` dan `elzara.conf` di blok kode task ini (dan Task 2/13) adalah rujukan historis penamaan lama — perintah yang dieksekusi wajib memakai nama di disk. `docker compose up` belum pernah dijalankan; gerbang `docker compose ps` / `curl -skI` masih harus dibuktikan.

**Files:**
- Create: `docker/compose.yaml`, `docker/compose.dev.yaml`, `docker/api.Dockerfile`, `docker/next.Dockerfile`, `docker/nginx/elzara.conf`, `.env.example`, `apps/api/config/horizon.php`, `apps/api/tests/Feature/Skeleton/HorizonQueueNamesTest.php`, `apps/api/tests/Feature/Skeleton/EnvVarCoverageTest.php`
- Modify: `apps/admin/next.config.ts`, `apps/web/next.config.ts` (output standalone + basePath)
- Test: dua berkas `tests/Feature/Skeleton/` di atas + gerbang `docker compose ps` / `curl -skI https://localhost`

Ditegakkan di sini (Doc 01 §11.1): healthcheck tiap service, `depends_on: condition: service_healthy`, network terpisah `frontend`/`backend`, port data store tidak pernah dipublikasikan, `compose up` gagal dengan pesan yang **menyebut nama variabel** yang hilang.

- [ ] **Step 1: `compose.yaml` (basis — tidak ada satu pun `ports:`)**

Create `docker/compose.yaml`:
```yaml
name: elzara
x-env: &x-env
  APP_KEY: ${APP_KEY:?APP_KEY wajib diisi (composer dev:artisan key:generate)}
  ELZARA_EDGE_URL: ${ELZARA_EDGE_URL:?ELZARA_EDGE_URL wajib diisi - lihat .env.example}
  DB_CONNECTION: pgsql
  DB_HOST: postgres
  DB_PORT: "5432"
  DB_DATABASE: ${POSTGRES_DB:?POSTGRES_DB wajib diisi}
  DB_USERNAME: ${POSTGRES_USER:?POSTGRES_USER wajib diisi}
  DB_PASSWORD: ${POSTGRES_PASSWORD:?POSTGRES_PASSWORD wajib diisi}
  REDIS_HOST: redis
  REDIS_PORT: "6379"
  SESSION_DRIVER: redis
  QUEUE_CONNECTION: redis
  CACHE_STORE: redis
  AWS_ENDPOINT: http://minio:9000
  AWS_KEY: ${MINIO_ROOT_USER:?MINIO_ROOT_USER wajib diisi}
  AWS_SECRET: ${MINIO_ROOT_PASSWORD:?MINIO_ROOT_PASSWORD wajib diisi}
x-search: &x-search
  ELASTICSEARCH_URL: http://elasticsearch:9200
  ELASTICSEARCH_API_KEY: ${ELASTICSEARCH_API_KEY:-}
services:
  api:
    build: { context: ../.., dockerfile: docker/api.Dockerfile }
    image: elzara/api:dev
    environment: { <<: [*x-env, *x-search], APP_ENV: local, APP_DEBUG: "true" }
    depends_on: { postgres: { condition: service_healthy }, redis: { condition: service_healthy } }
    networks: [frontend, backend, search]
    restart: unless-stopped
  worker:
    image: elzara/api:dev
    command: ["php", "artisan", "horizon"]
    environment: { <<: [*x-env, *x-search], APP_ENV: local, HORIZON_PREFIX: "elzara-dev:" }
    depends_on: { api: { condition: service_started } }
    deploy: { resources: { limits: { memory: 512M } } }
    networks: [backend, search]
    restart: unless-stopped
  nginx:
    image: nginx:1.27-alpine
    volumes: ["./nginx/elzara.conf:/etc/nginx/conf.d/default.conf:ro", "./nginx/certs:/etc/nginx/certs:ro"]
    depends_on: { api: { condition: service_started }, web: { condition: service_healthy }, admin: { condition: service_healthy } }
    networks: [frontend]
  web:
    build: { context: ../.., dockerfile: docker/next.Dockerfile, args: { NEXT_APP: web } }
    image: elzara/web:dev
    environment: { NEXT_PUBLIC_API_BASE_URL: ${NEXT_PUBLIC_API_BASE_URL:?NEXT_PUBLIC_API_BASE_URL wajib diisi} }
    networks: [frontend]
  admin:
    build: { context: ../.., dockerfile: docker/next.Dockerfile, args: { NEXT_APP: admin, NEXT_BASE_PATH: /admin } }
    image: elzara/admin:dev
    environment: { NEXT_PUBLIC_API_BASE_URL: ${NEXT_PUBLIC_API_BASE_URL:?NEXT_PUBLIC_API_BASE_URL wajib diisi} }
    networks: [frontend]
  postgres:
    image: postgres:16-alpine
    environment: { POSTGRES_DB: ${POSTGRES_DB:?POSTGRES_DB wajib diisi}, POSTGRES_USER: ${POSTGRES_USER:?POSTGRES_USER wajib diisi}, POSTGRES_PASSWORD: ${POSTGRES_PASSWORD:?POSTGRES_PASSWORD wajib diisi} }
    volumes: ["pgdata:/var/lib/postgresql/data"]
    healthcheck: { test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER}"], interval: 5s, timeout: 3s, retries: 20 }
    networks: [backend]
  redis:
    image: redis:7-alpine
    command: ["redis-server", "--appendonly", "yes"]
    volumes: ["redisdata:/data"]
    healthcheck: { test: ["CMD", "redis-cli", "ping"], interval: 5s, timeout: 3s, retries: 20 }
    networks: [backend]
  # Service opsional elasticsearch mengikuti definisi aktif di docker/compose.yaml.
  minio:
    image: minio/minio:RELEASE.2025-04-22T22-12-26Z
    command: ["server", "/data", "--console-address", "0.0.0.0:9001"]
    environment: { MINIO_ROOT_USER: ${MINIO_ROOT_USER:?MINIO_ROOT_USER wajib diisi}, MINIO_ROOT_PASSWORD: ${MINIO_ROOT_PASSWORD:?MINIO_ROOT_PASSWORD wajib diisi} }
    volumes: ["miniodata:/data"]
    healthcheck: { test: ["CMD", "curl", "-f", "http://localhost:9000/minio/health/live"], interval: 5s, timeout: 3s, retries: 20 }
    networks: [backend]
  mailpit:
    image: axllent/mailpit:v1.22.0
    networks: [backend]
  adminer:
    image: adminer:4
    networks: [backend]
networks:
  frontend: {}
  backend: { internal: true }
  search: { internal: true }
volumes: { pgdata: {}, redisdata: {}, miniodata: {} }
```
`backend: {internal: true}` membuat PostgreSQL, Redis, MinIO, Mailpit, dan Adminer terisolasi sesuai konfigurasi port (Doc 01 §11.1). Untuk search, gunakan definisi aktif di `docker/compose.yaml` dengan profil `search`, image `docker.elastic.co/elasticsearch/elasticsearch:8.19.25`, volume `elasticsearchdata`, dan network internal `search` yang hanya diikuti Elasticsearch dan konsumen server-side. Startup API dan login tetap independen dari health search.

- [ ] **Step 2: `compose.dev.yaml` — profil dev, satu-satunya yang memublikasikan port**

Create `docker/compose.dev.yaml`:
```yaml
services:
  nginx:
    ports: ["80:80", "443:443"]
  mailpit:
    ports: ["127.0.0.1:8025:8025"]
  adminer:
    ports: ["127.0.0.1:8080:8080"]
  minio:
    ports: ["127.0.0.1:9001:9001"]
  api:
    volumes: ["../../apps/api:/app"]
    working_dir: /app
```
Hanya edge yang menempel ke `0.0.0.0`; alat bantu di-`bind` ke `127.0.0.1`; data store tetap tanpa port. Mount sumber ke `/app` hanya di profil dev supaya edit host langsung terlihat; staging/prod memakai image immutable tanpa mount (Doc 01 §11.2).

- [ ] **Step 3: `api.Dockerfile` multi-stage (Doc 01 §11.2)**

Create `docker/api.Dockerfile`:
```dockerfile
# syntax=docker/dockerfile:1.7
FROM composer:2 AS vendor
WORKDIR /build
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --optimize-autoloader
COPY apps/api/ ./
RUN composer dump-autoload --no-dev --optimize

FROM php:8.4-fpm-alpine AS final
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev oniguruma-dev libpng-dev libjpeg-turbo-dev freetype-dev \
 && apk add --no-cache libpq oniguruma icu-libs libzip libpng libjpeg-turbo freetype \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl bcmath zip gd opcache \
 && pecl install redis && docker-php-ext-enable redis \
 && apk del .build-deps
COPY --from=vendor /build /app
WORKDIR /app
RUN addgroup -S app && adduser -S app -G app && chown -R app:app /app
USER app
EXPOSE 9000
HEALTHCHECK --interval=10s --timeout=3s --retries=5 CMD php -r '$c=@fsockopen("127.0.0.1",9000); exit($c?0:1);'
```
Image API memuat `pdo_pgsql redis intl gd bcmath zip opcache` persis Doc 01 §11.2, non-root, tanpa `.env` dan tanpa kredensial. Tidak ada langkah "aset" di image API: pada topologi tiga app (B-1) aset dibangun di image Next masing-masing, sementara §11.2 menyebut langkah itu dari masa satu app — perbedaan dicatat, tidak disembunyikan.

- [ ] **Step 4: `next.Dockerfile` satu berkas untuk kedua app**

Create `docker/next.Dockerfile`:
```dockerfile
# syntax=docker/dockerfile:1.7
FROM node:22-alpine AS deps
ARG NEXT_APP
WORKDIR /app
COPY apps/${NEXT_APP}/package.json apps/${NEXT_APP}/package-lock.json ./
RUN npm ci
FROM node:22-alpine AS build
ARG NEXT_APP
ARG NEXT_BASE_PATH=""
ENV NEXT_TELEMETRY_DISABLED=1 NEXT_PUBLIC_BASE_PATH=${NEXT_BASE_PATH}
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY apps/${NEXT_APP}/ ./
RUN npm run build
FROM node:22-alpine AS final
ENV NODE_ENV=production NEXT_TELEMETRY_DISABLED=1 PORT=3000 HOSTNAME=0.0.0.0
WORKDIR /app
COPY --from=build /app/.next/standalone ./
COPY --from=build /app/.next/static ./.next/static
COPY --from=build /app/public ./public
USER node
EXPOSE 3000
HEALTHCHECK --interval=10s --timeout=3s --retries=10 CMD node -e 'fetch("http://127.0.0.1:3000/health").then(r=>process.exit(r.status<500?0:1)).catch(()=>process.exit(1))'
```
Create `apps/admin/next.config.ts` (salin identik ke `apps/web/next.config.ts`; web dibangun tanpa `NEXT_BASE_PATH` sehingga `basePath` kosong):
```ts
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  output: "standalone",
  basePath: process.env.NEXT_PUBLIC_BASE_PATH ?? "",
};

export default nextConfig;
```

- [ ] **Step 5: Edge nginx — pemisah `/api`, `/admin`, `/` dan sertifikat dev**

Create `docker/nginx/elzara.conf`:
```nginx
server {
    listen 80;
    server_name localhost;
    return 301 https://$host$request_uri;
}
server {
    listen 443 ssl;
    http2 on;
    server_name localhost;
    ssl_certificate     /etc/nginx/certs/localhost.pem;
    ssl_certificate_key /etc/nginx/certs/localhost-key.pem;
    client_max_body_size 16m;
    location /api/ {
        fastcgi_pass api:9000;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /app/public/index.php;
        fastcgi_param DOCUMENT_ROOT /app/public;
        fastcgi_param HTTPS on;
    }
    location /admin {
        proxy_pass http://admin:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto https;
    }
    location / {
        proxy_pass http://web:3000;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto https;
    }
}
```
```bash
mkdir -p docker/nginx/certs
openssl req -x509 -newkey rsa:2048 -nodes -days 30 -subj "/CN=localhost" \
  -keyout docker/nginx/certs/localhost-key.pem \
  -out docker/nginx/certs/localhost.pem
```
Expected: kedua `.pem` terbentuk dan `git status --porcelain | grep -c certs` = `0` (folder sudah di-`.gitignore` Step 3 Task 2). Header keamanan + CSP per app adalah task 0.16; di sini edge hanya menghentikan request ke tiga hulu.

- [ ] **Step 6: `.env.example` (nama variabel, nol rahasia)**

Create `.env.example` (level compose) — berkas ini adalah **satu-satunya inventaris variabel** untuk ketiga app, dikelompokkan per app dengan komentar `# --- api` dst. (Doc 01 §11.3):
```text
COMPOSE_PROJECT_NAME=elzara
ELZARA_EDGE_URL=https://localhost
NEXT_PUBLIC_API_BASE_URL=https://localhost/api/v1
APP_ENV=local
APP_DEBUG=true
APP_KEY=
POSTGRES_DB=elzara
POSTGRES_USER=elzara
POSTGRES_PASSWORD=isi-di-file-env-lokal
ELASTICSEARCH_PASSWORD=isi-di-file-env-lokal-untuk-inisialisasi-search
ELASTICSEARCH_API_KEY=
ELASTICSEARCH_MEMORY_LIMIT=1g
MINIO_ROOT_USER=elzara
MINIO_ROOT_PASSWORD=isi-di-file-env-lokal
HORIZON_PREFIX=elzara-dev:
```
Tambahkan blok aplikasi ke **`.env.example` root yang sama**, tepat di bawah kelompok `# --- api` (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `REDIS_HOST`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `MAIL_MAILER=smtp`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`, `FILESYSTEM_DISK=local`, `AWS_BUCKET=product-media`, `ELASTICSEARCH_URL`, `ELASTICSEARCH_API_KEY`). Nilai `isi-di-file-env-lokal` adalah pengingat, bukan kredensial; `APP_KEY` kosong karena dibuat `key:generate`.

- [ ] **Step 7: `config/horizon.php` — TUJUH antrian, tidak lebih tidak kurang**

Create `apps/api/config/horizon.php`:
```php
<?php

declare(strict_types=1);

/**
 * Daftar kanonik Doc 11 §8.1: tujuh dan hanya tujuh antrian.
 * Nama `default`, `search`, `reconcile` terlarang sebagai nama antrian.
 */
const ELZARA_QUEUES = ['payments', 'orders', 'inventory', 'notifications', 'imports', 'reports', 'media'];

$supervisors = [];

foreach (ELZARA_QUEUES as $queue) {
    $supervisors[$queue] = [
        'connection' => 'redis', 'queue' => [$queue], 'balance' => 'auto',
        'autoScalingStrategy' => 'time', 'minProcesses' => 1, 'maxProcesses' => 2,
        'tries' => 1, 'timeout' => 60,
    ];
}

return [
    'domain' => env('HORIZON_DOMAIN'),
    'path' => 'horizon',
    'use' => 'default-connection',
    'prefix' => env('HORIZON_PREFIX', 'elzara:'),
    'middleware' => ['web'],
    'waits' => [],
    'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'failed' => 10080],
    'environments' => ['local' => $supervisors, 'production' => $supervisors],
];
```
`use` di sini adalah nama **koneksi Redis Horizon** (bukan nama antrian); nilainya `default-connection` supaya larangan `default` tidak bisa salah baca dari isi berkas, dan test Step 8 memastikan tidak ada string `"default"` sebagai nama antrian.

- [ ] **Step 8: Test nama antrian + cakupan `.env.example`**

Create `apps/api/tests/Feature/Skeleton/HorizonQueueNamesTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Skeleton;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class HorizonQueueNamesTest extends TestCase
{
    private const CANONICAL = ['payments', 'orders', 'inventory', 'notifications', 'imports', 'reports', 'media'];

    #[Test]
    public function hanya_tujuh_antrian_kanonik_yang_didefinisikan(): void
    {
        $this->assertSame(self::CANONICAL, array_keys((array) config('horizon.environments.local')));
    }

    #[Test]
    public function nama_antrian_terlarang_tidak_muncul(): void
    {
        $json = (string) json_encode(config('horizon.environments'), JSON_THROW_ON_ERROR);
        foreach (['"default"', '"search"', '"reconcile"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json);
        }
    }
}
```
Create `apps/api/tests/Feature/Skeleton/EnvVarCoverageTest.php` — menegakkan Doc 01 §11.3 ("variabel dibaca kode tapi tidak terdaftar = gagal"):
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Skeleton;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class EnvVarCoverageTest extends TestCase
{
    #[Test]
    public function setiap_variabel_yang_dibaca_config_terdaftar_di_env_example(): void
    {
        $files = array_merge(glob(base_path('config/*.php')) ?: [], glob(base_path('app/*/*.php')) ?: []);
        $used = [];

        foreach ($files as $file) {
            preg_match_all("/env\(\s*'([A-Z0-9_]+)'/", (string) file_get_contents($file), $m);
            $used = array_merge($used, $m[1]);
        }

        $declared = [];
        foreach (['.env.example', base_path('.env.example')] as $example) {
            if (is_file($example)) {
                preg_match_all('/^([A-Z0-9_]+)=/m', (string) file_get_contents($example), $m);
                $declared = array_merge($declared, $m[1]);
            }
        }

        $missing = array_values(array_unique(array_diff($used, $declared)));
        $this->assertSame([], $missing, 'Dibaca kode, tidak terdaftar di .env.example: ' . implode(', ', $missing));
    }
}
```

- [ ] **Step 9: Gerbang penerimaan 0.2**

Run: `cp .env.example .env && composer dev:artisan key:generate` lalu `composer dev:up` (cold start diukur dengan `time`).
Run: `docker compose -f docker/compose.yaml -f docker/compose.dev.yaml ps --format '{{.Name}}\t{{.Status}}'`
Expected: `elzara-api-1 Up (healthy)`, `...-web-1 Up (healthy)`, `...-admin-1 Up (healthy)`, `...-postgres-1 Up (healthy)`, `...-redis-1 Up (healthy)`, `...-minio-1 Up (healthy)`, `...-nginx-1 Up`, `worker/mailpit/adminer` Up. Jika profil `search` diaktifkan, cek status `elasticsearch` secara terpisah.
Run: `time curl -skI https://localhost | head -1 && curl -skI https://localhost/admin | head -1 && curl -skI https://localhost/api/v1/health | head -1`
Expected dalam **< 90 s** cold start (spec §6): `HTTP/1.1 200 OK` untuk `/` (halaman "Elzara storefront placeholder"), `HTTP/1.1 200 OK` untuk `/admin` (app terpisah, **bukan** route group — B-1), `HTTP/1.1 404` untuk health sampai Task 4 memasang stub `getHealth`; yang tidak boleh muncul adalah `502`.
Run: `rm .env && docker compose -f docker/compose.yaml --env-file .env.example config -q; echo exit=$?` lalu pulihkan `cp .env.example .env`
Expected: `exit=1` dengan pesan `required variable POSTGRES_PASSWORD is missing a value` — gagal yang menyebut nama variabel, bukan saat container berjalan.
Run: `docker port elzara-postgres-1 2>/dev/null | wc -l && docker port elzara-redis-1 2>/dev/null | wc -l` — Expected: `0` dan `0`.
Run: `composer dev:test` — Expected: `OK (9 tests, ...)` (5 test Task 2 + 2 antrian + 1 env coverage + 1 yang sudah ada).

- [ ] **Step 10: Commit**

```bash
git add docker .env.example apps/api/config/horizon.php apps/api/tests/Feature/Skeleton apps/admin/next.config.ts apps/web/next.config.ts
git commit -m "feat(sprint-0): stack docker dev satu perintah dengan edge tls dan tujuh antrian horizon"
```

### Task 4 — `packages/api-contracts` sebagai gerbang kontrak  (ID Doc 03: 0.5)

**Files:**
- Create: `packages/api-contracts/openapi.yaml`, `packages/api-contracts/package.json`, `packages/api-contracts/package-lock.json`, `packages/api-contracts/.spectral.yaml`, `packages/api-contracts/generated/schema.d.ts` (di-commit), `apps/api/tests/Contract/OpenApiRouteParityTest.php`, `apps/web/src/lib/api.ts`, `apps/admin/src/lib/api.ts`, `docs/api/openapi-v1.html`
- Modify: `apps/api/routes/api.php` (stub 18 jalur wajib Blueprint §7 + health), `apps/api/tests/Feature/Skeleton/SkeletonRoutingTest.php`, `composer.json` (isi `ci:contracts` nyata)
- Test: `apps/api/tests/Contract/OpenApiRouteParityTest.php` (gerbang D-8, dua arah)

`openapi.yaml` adalah satu-satunya sumber kontrak (D-S0-5); keluaran generator di-commit dan tidak pernah diedit tangan (Doc 12 §1.3). **Amandemen 2026-11-18 (D-S0-6, Doc 11 §4.4 I):** kompromi lama "tanpa securitySchemes sampai ADR-007 tertutup" **tidak berlaku, diganti** — `securitySchemes` cookie sesi (cookieAuth + CSRF) **wajib ada** dan `security` dipasang per operasi; ekstensi `x-elzara-auth` bervalensi `public|customer|admin|s2s` **tetap** sebagai penanda kategori (nilai `publik` lama diganti `public`); operasi admin menambah `x-elzara-permission: <modul.aksi>`; endpoint auth ditambahkan gelombang yang sama. Scramble hanya alat bantu draf — tidak ada auto-regen spec di CI (§4.4 E).

- [ ] **Step 1: Manifest paket kontrak + generator**

Create `packages/api-contracts/package.json`:
```json
{
    "name": "@elzara/api-contracts",
    "version": "0.1.0",
    "private": true,
    "type": "module",
    "exports": { ".": "./generated/schema.d.ts" },
    "scripts": {
        "generate": "openapi-typescript openapi.yaml -o generated/schema.d.ts",
        "lint": "spectral lint -r .spectral.yaml openapi.yaml"
    },
    "devDependencies": { "openapi-typescript": "7.13.0", "@stoplight/spectral-cli": "6.17.1" }
}
```
Run: `docker run --rm -v "$PWD:/w" -w /w/packages/api-contracts node:22 npm install --package-lock-only` — Expected: `package-lock.json` terbentuk (lockfile Node ketiga, tetap terpisah dari kedua app).

- [ ] **Step 2: Aturan kustom spectral (G2 Doc 12 §1.2)**

Create `packages/api-contracts/.spectral.yaml`:
```yaml
extends: ["spectral:oas"]
rules:
  elzara-operation-id: { severity: error, given: "$.paths[*][get,post,patch,delete]", then: { field: operationId, function: truthy } }
  elzara-tags: { severity: error, given: "$.paths[*][get,post,patch,delete]", then: { field: tags, function: schema, functionOptions: { schema: { type: array, minItems: 1 } } } }
  elzara-auth-side: { severity: error, given: "$.paths[*][get,post,patch,delete]", then: { field: x-elzara-auth, function: schema, functionOptions: { schema: { enum: [public, customer, admin, s2s] } } } }
  elzara-error-response: { severity: error, given: "$.paths[*][get,post,patch,delete].responses", then: { field: "422", function: truthy } }
  elzara-request-id-header: { severity: error, given: "$.paths[*][get,post,patch,delete].responses.2*", then: { field: headers.X-Request-Id, function: truthy } }
  elzara-no-offset-pagination: { severity: error, given: "$..parameters[*].name", then: { field: "@this", function: pattern, functionOptions: { notMatch: "^(page|offset|per_page)$" } } }
  elzara-money-integer: { severity: error, given: "$.components.schemas.Money.properties.amount_minor", then: { field: type, function: schema, functionOptions: { schema: { const: integer } } } }
```
Jujur atas cakupannya: `elzara-request-id-header` hanya memeriksa respons 2xx (itu tuntutan §3.2), dan aturan idempotensi ditegakkan oleh test PHP Step 5 (bukan spectral) karena G6 baru bisa dibuktikan terhadap operasi POST yang memang ada di §2.1; perluasan ke PATCH/DELETE masuk bersamaan dengan endpoint §2.2 milik task kontrak lanjutan.

- [ ] **Step 3: `openapi.yaml` — 18 jalur wajib Blueprint §7 + health + security per operasi (amandemen 2026-11-18)**

Create `packages/api-contracts/openapi.yaml`:
```yaml
openapi: 3.1.0
info: { title: Elzara /api/v1, version: 0.1.0, description: "Sumber kebenaran kontrak: bentuk request/response yang tidak ada di berkas ini tidak boleh dipakai frontend maupun dipancarkan controller (Doc 12 §1.1)." }
servers: [{ url: https://localhost/api/v1, description: Edge dev (Task 3) }]
tags: [{ name: Platform }, { name: Catalog }, { name: PreOrder }, { name: Cart }, { name: Orders }, { name: Payments }, { name: Sourcing }, { name: Procurement }, { name: Fulfillment }]
paths:
  /health: { get: { operationId: getHealth, tags: [Platform], x-elzara-auth: public, responses: { "200": { description: API hidup, headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { type: object, required: [status], properties: { status: { type: string, const: ok } } } } } }, "422": { $ref: "#/components/responses/Problem" } } } }
  /products: { get: { operationId: listProducts, tags: [Catalog], x-elzara-auth: public, parameters: [{ $ref: "#/components/parameters/Limit" }, { $ref: "#/components/parameters/Cursor" }], responses: { "200": { description: "Halaman katalog publik (cursor, tanpa total - Doc 12 §3.3)", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { type: object, required: [data, meta], properties: { data: { type: array, items: { $ref: "#/components/schemas/ProductSummary" } }, meta: { $ref: "#/components/schemas/CursorMeta" } } } } } }, "422": { $ref: "#/components/responses/Problem" }, "429": { $ref: "#/components/responses/Problem" } } } }
  /products/{slug}: { get: { operationId: getProduct, tags: [Catalog], x-elzara-auth: public, parameters: [{ name: slug, in: path, required: true, schema: { type: string, pattern: "^[a-z0-9-]{1,120}$" } }], responses: { "200": { description: Detail produk, headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { allOf: [{ $ref: "#/components/schemas/ProductSummary" }, { type: object, required: [description_html, variants, media], properties: { description_html: { type: string }, variants: { type: array, items: { $ref: "#/components/schemas/ProductVariant" } }, media: { type: array, items: { $ref: "#/components/schemas/MediaRef" } } } }] } } } }, "404": { $ref: "#/components/responses/Problem" }, "422": { $ref: "#/components/responses/Problem" } } } }
  /preorder-batches: { get: { operationId: listOpenPreorderBatches, tags: [PreOrder], x-elzara-auth: public, parameters: [{ $ref: "#/components/parameters/Limit" }, { $ref: "#/components/parameters/Cursor" }], responses: { "200": { description: "Batch pre-order terbuka", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { type: object, required: [data, meta], properties: { data: { type: array, items: { $ref: "#/components/schemas/PreorderBatchSummary" } }, meta: { $ref: "#/components/schemas/CursorMeta" } } } } } }, "422": { $ref: "#/components/responses/Problem" } } } }
  /preorder-batches/{id}: { get: { operationId: getPreorderBatch, tags: [PreOrder], x-elzara-auth: public, parameters: [{ name: id, in: path, required: true, schema: { type: string, pattern: "^batch_[0-9A-Za-z]{20,32}$" } }], responses: { "200": { description: "Detail batch + item + kebijakan alokasi", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { allOf: [{ $ref: "#/components/schemas/PreorderBatchSummary" }, { type: object, required: [items, allocation_policy], properties: { items: { type: array, items: { $ref: "#/components/schemas/ProductSummary" } }, allocation_policy: { type: string, enum: [fifo, priority, manual] } } }] } } } }, "404": { $ref: "#/components/responses/Problem" }, "422": { $ref: "#/components/responses/Problem" } } } }
  /cart/items: { post: { operationId: addCartItem, tags: [Cart], x-elzara-auth: customer, parameters: [{ $ref: "#/components/parameters/IdempotencyKey" }], requestBody: { required: true, content: { application/json: { schema: { $ref: "#/components/schemas/CartItemWrite" } } } }, responses: { "201": { description: "Cart setelah mutasi", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { $ref: "#/components/schemas/CartRead" } } } }, "409": { $ref: "#/components/responses/Problem" }, "422": { $ref: "#/components/responses/Problem" } } } }
  /checkout/sessions: { post: { operationId: createCheckoutSession, tags: [Cart], x-elzara-auth: customer, parameters: [{ $ref: "#/components/parameters/IdempotencyKey" }], requestBody: { required: true, content: { application/json: { schema: { type: object, required: [cart_id], properties: { cart_id: { type: string, pattern: "^cart_[0-9A-Za-z]{20,32}$" } } } } } }, responses: { "201": { description: "Snapshot checkout + masa berlaku", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { $ref: "#/components/schemas/CheckoutSessionRead" } } } }, "409": { $ref: "#/components/responses/Problem" }, "422": { $ref: "#/components/responses/Problem" } } } }
  /orders:
    post:
      operationId: createOrder
      tags: [Orders]
      x-elzara-auth: customer
      description: "Idempoten penuh: key + payload sama = response identik dengan header Idempotency-Replayed (Doc 12 §3.4)."
      parameters: [{ $ref: "#/components/parameters/IdempotencyKey" }, { $ref: "#/components/parameters/RequestIdIn" }]
      requestBody:
        required: true
        content:
          application/json:
            schema: { type: object, required: [checkout_session_id, shipping_address_id], properties: { checkout_session_id: { type: string, pattern: "^cs_[0-9A-Za-z]{20,32}$" }, shipping_address_id: { type: string, pattern: "^adr_[0-9A-Za-z]{20,32}$" }, note: { type: ["string", "null"], maxLength: 1000 } } }
      responses:
        "201": { description: "Order lahir langsung awaiting_payment; draft tidak dipakai (Doc 11 §8.3)", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" }, Idempotency-Replayed: { $ref: "#/components/headers/IdempotencyReplayed" } }, content: { application/json: { schema: { $ref: "#/components/schemas/OrderRead" } } } }
        "409": { $ref: "#/components/responses/Problem" }
        "422": { $ref: "#/components/responses/Problem" }
        "429": { $ref: "#/components/responses/Problem" }
  /me/orders/{id}: { get: { operationId: getMyOrder, tags: [Orders], x-elzara-auth: customer, parameters: [{ name: id, in: path, required: true, schema: { type: string, pattern: "^ord_[0-9A-Za-z]{20,32}$" } }], responses: { "200": { description: "Order milik pengirim saja", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { allOf: [{ $ref: "#/components/schemas/OrderRead" }, { type: object, required: [items, status_history], properties: { items: { type: array, items: { $ref: "#/components/schemas/OrderItemRead" } }, status_history: { type: array, items: { $ref: "#/components/schemas/StatusChange" } } } }] } } } }, "401": { $ref: "#/components/responses/Problem" }, "404": { $ref: "#/components/responses/Problem" }, "422": { $ref: "#/components/responses/Problem" } } } }
  /payment-intents:
    post:
      operationId: createPaymentIntent
      tags: [Payments]
      x-elzara-auth: customer
      parameters: [{ $ref: "#/components/parameters/IdempotencyKey" }]
      requestBody:
        required: true
        content:
          application/json:
            schema: { type: object, required: [order_id, channel, kind], properties: { order_id: { type: string, pattern: "^ord_[0-9A-Za-z]{20,32}$" }, channel: { type: string, enum: [virtual_account, qris, card, ewallet] }, kind: { type: string, enum: [deposit, full, balance] } } }
      responses:
        "201": { description: "Intent + instruksi bayar gateway; bentuk instruksi divalidasi ulang saat gateway pertama dipilih (Doc 03 0.15)", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" }, Idempotency-Replayed: { $ref: "#/components/headers/IdempotencyReplayed" } }, content: { application/json: { schema: { type: object, required: [id, order_id, status, amount, instructions], properties: { id: { type: string, pattern: "^pay_[0-9A-Za-z]{20,32}$" }, order_id: { type: string }, status: { type: string, enum: [pending, awaiting_confirmation, paid, failed, expired] }, amount: { $ref: "#/components/schemas/Money" }, instructions: { type: object, additionalProperties: { type: string } } } } } } }
        "409": { $ref: "#/components/responses/Problem" }
        "422": { $ref: "#/components/responses/Problem" }
  /webhooks/payments/{provider}:
    post:
      operationId: ingestPaymentWebhook
      tags: [Payments]
      x-elzara-auth: s2s
      description: "Callback terverifikasi. Dedup memakai (provider, provider_event_id) lewat inbox_events; webhook_receipts milik modul Payments Sprint 2 (Doc 03 0.7)."
      parameters: [{ name: provider, in: path, required: true, schema: { type: string, minLength: 2, maxLength: 40 } }]
      requestBody:
        required: true
        content:
          application/json:
            schema: { type: object, required: [provider_event_id, type], properties: { provider_event_id: { type: string, maxLength: 128 }, type: { type: string, maxLength: 80 }, occurred_at: { type: string, format: date-time }, data: { type: object, additionalProperties: true } } }
      responses:
        "200": { description: "Selalu 200 untuk retry provider yang sama supaya gateway berhenti mengirim ulang", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { type: object, required: [received, duplicate], properties: { received: { type: boolean }, duplicate: { type: boolean } } } } } }
        "401": { $ref: "#/components/responses/Problem" }
        "422": { $ref: "#/components/responses/Problem" }
  /product-requests: { post: { operationId: createProductRequest, tags: [Sourcing], x-elzara-auth: customer, parameters: [{ $ref: "#/components/parameters/IdempotencyKey" }], requestBody: { required: true, content: { application/json: { schema: { type: object, required: [title, quantity], properties: { title: { type: string, minLength: 5, maxLength: 200 }, quantity: { type: integer, minimum: 1, maximum: 999 }, target_price: { $ref: "#/components/schemas/Money" } } } } } }, responses: { "201": { description: Permintaan Request Order dibuat, headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" }, Idempotency-Replayed: { $ref: "#/components/headers/IdempotencyReplayed" } }, content: { application/json: { schema: { $ref: "#/components/schemas/ProductRequestRead" } } } }, "422": { $ref: "#/components/responses/Problem" } } } }
  /product-requests/{id}/attachments/presign: { post: { operationId: presignProductRequestAttachment, tags: [Sourcing], x-elzara-auth: customer, parameters: [{ name: id, in: path, required: true, schema: { type: string, pattern: "^prq_[0-9A-Za-z]{20,32}$" } }, { $ref: "#/components/parameters/IdempotencyKey" }], requestBody: { required: true, content: { application/json: { schema: { type: object, required: [file_name, mime_type, size_bytes], properties: { file_name: { type: string, maxLength: 240 }, mime_type: { type: string, maxLength: 80 }, size_bytes: { type: integer, minimum: 1, maximum: 10485760 } } } } } }, responses: { "201": { description: "Baris media + presigned PUT memakai endpoint yang dijangkau browser (Doc 11 B-9), bukan hostname Docker internal", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { type: object, required: [media_id, upload_url, expires_at], properties: { media_id: { type: string, pattern: "^media_[0-9A-Za-z]{20,32}$" }, upload_url: { type: string, format: uri }, expires_at: { type: string, format: date-time } } } } } }, "422": { $ref: "#/components/responses/Problem" } } } }
  /me/quotations/{id}: { get: { operationId: getMyQuotation, tags: [Sourcing], x-elzara-auth: customer, parameters: [{ name: id, in: path, required: true, schema: { type: string, pattern: "^quo_[0-9A-Za-z]{20,32}$" } }], responses: { "200": { description: "Kuotasi berversi untuk customer", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { $ref: "#/components/schemas/QuotationRead" } } } }, "404": { $ref: "#/components/responses/Problem" }, "422": { $ref: "#/components/responses/Problem" } } } }
  /quotations/{id}/accept: { post: { operationId: acceptQuotation, tags: [Sourcing], x-elzara-auth: customer, parameters: [{ name: id, in: path, required: true, schema: { type: string, pattern: "^quo_[0-9A-Za-z]{20,32}$" } }, { $ref: "#/components/parameters/IdempotencyKey" }], requestBody: { required: true, content: { application/json: { schema: { type: object, required: [version], properties: { version: { type: integer, minimum: 1 } } } } } }, responses: { "201": { description: "Order lahir dari versi kuotasi yang diterima", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" }, Idempotency-Replayed: { $ref: "#/components/headers/IdempotencyReplayed" } }, content: { application/json: { schema: { $ref: "#/components/schemas/OrderRead" } } } }, "409": { $ref: "#/components/responses/Problem" }, "422": { $ref: "#/components/responses/Problem" } } }
  /admin/preorder-batches/{id}/close: { post: { operationId: closePreorderBatch, tags: [PreOrder], x-elzara-auth: admin, parameters: [{ name: id, in: path, required: true, schema: { type: string, pattern: "^batch_[0-9A-Za-z]{20,32}$" } }, { $ref: "#/components/parameters/IdempotencyKey" }], responses: { "200": { description: "Batch ditutup (idempoten, TTL key 30 hari)", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" }, Idempotency-Replayed: { $ref: "#/components/headers/IdempotencyReplayed" } }, content: { application/json: { schema: { $ref: "#/components/schemas/PreorderBatchSummary" } } } }, "409": { $ref: "#/components/responses/Problem" }, "422": { $ref: "#/components/responses/Problem" } } } }
  /admin/purchase-orders: { post: { operationId: createPurchaseOrder, tags: [Procurement], x-elzara-auth: admin, parameters: [{ $ref: "#/components/parameters/IdempotencyKey" }], requestBody: { required: true, content: { application/json: { schema: { type: object, required: [supplier_id, lines], properties: { supplier_id: { type: string, pattern: "^sup_[0-9A-Za-z]{20,32}$" }, lines: { type: array, minItems: 1, items: { type: object, required: [variant_id, quantity, unit_cost], properties: { variant_id: { type: string, pattern: "^var_[0-9A-Za-z]{20,32}$" }, quantity: { type: integer, minimum: 1 }, unit_cost: { $ref: "#/components/schemas/Money" } } } } } } } } }, responses: { "201": { description: "PO supplier dibuat", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/json: { schema: { type: object, required: [id, status], properties: { id: { type: string, pattern: "^po_[0-9A-Za-z]{20,32}$" }, status: { type: string } } } } } }, "422": { $ref: "#/components/responses/Problem" } } }
  /admin/shipments: { post: { operationId: createShipment, tags: [Fulfillment], x-elzara-auth: admin, parameters: [{ $ref: "#/components/parameters/IdempotencyKey" }], requestBody: { required: true, content: { application/json: { schema: { type: object, required: [order_id, courier_service_code, package_ids], properties: { order_id: { type: string, pattern: "^ord_[0-9A-Za-z]{20,32}$" }, courier_service_code: { type: string, maxLength: 60 }, package_ids: { type: array, minItems: 1, items: { type: string, pattern: "^pkg_[0-9A-Za-z]{20,32}$" } } } } } } }, responses: { "201": { description: "Fulfillment dibuat untuk satu atau lebih paket", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" }, Idempotency-Replayed: { $ref: "#/components/headers/IdempotencyReplayed" } }, content: { application/json: { schema: { type: object, required: [id, order_id, status], properties: { id: { type: string, pattern: "^shp_[0-9A-Za-z]{20,32}$" }, order_id: { type: string }, status: { type: string }, tracking_number: { type: ["string", "null"] } } } } } }, "409": { $ref: "#/components/responses/Problem" }, "422": { $ref: "#/components/responses/Problem" } } }
components:
  parameters:
    Limit: { name: limit, in: query, required: false, schema: { type: integer, minimum: 1, maximum: 100, default: 20 } }
    Cursor: { name: cursor, in: query, required: false, description: "Opaque base64url; klien dilarang mem-parse atau membangun sendiri (Doc 12 §3.3).", schema: { type: string, maxLength: 512 } }
    RequestIdIn: { name: X-Request-Id, in: header, required: false, description: "Dipakai apa adanya bila valid; bila kosong server membuat ULID (Doc 12 §3.2).", schema: { type: string, pattern: "^[A-Za-z0-9._-]{1,128}$" } }
    IdempotencyKey: { name: Idempotency-Key, in: header, required: true, description: "UUIDv4 atau ULID. Key sama + payload beda = 409 (Doc 12 §3.4).", schema: { type: string, pattern: "^([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}|[0-9A-HJKMNP-TV-Z]{26})$" } }
  headers:
    RequestId: { description: "Selalu dikembalikan, termasuk 4xx/5xx dan response replay.", schema: { type: string, pattern: "^[A-Za-z0-9._-]{1,128}$" } }
    IdempotencyReplayed: { description: "true bila respons ini hasil replay key yang sama.", schema: { type: boolean } }
  responses:
    Problem: { description: "Error RFC 7807 (application/problem+json) dengan X-Request-Id.", headers: { X-Request-Id: { $ref: "#/components/headers/RequestId" } }, content: { application/problem+json: { schema: { $ref: "#/components/schemas/ProblemDetails" } } } }
  schemas:
    ProblemDetails: { type: object, required: [type, title, status, code, request_id, retryable], properties: { type: { type: string, pattern: "^urn:elzara:problem:[a-z-]+:[a-z0-9-]+$", description: "Kategori tertutup Doc 12 §4.2." }, title: { type: string }, status: { type: integer, minimum: 400, maximum: 599 }, detail: { type: ["string", "null"] }, instance: { type: string }, request_id: { type: string }, code: { type: string, pattern: "^[A-Z][A-Z0-9_]+$" }, retryable: { type: boolean }, errors: { type: array, items: { type: object, required: [pointer, code], properties: { pointer: { type: string }, code: { type: string }, detail: { type: ["string", "null"] } } } } } }
    Money: { type: object, required: [amount_minor, currency], description: "Minor unit integer; float/decimal di kawat dilarang (Doc 12 G5, B-13).", properties: { amount_minor: { type: integer, minimum: 0 }, currency: { type: string, const: IDR } } }
    CursorMeta: { type: object, required: [limit, has_more], description: "Tanpa total/last_page: COUNT(*) sengaja tidak dihitung di jalur daftar.", properties: { limit: { type: integer }, has_more: { type: boolean }, next_cursor: { type: ["string", "null"] }, prev_cursor: { type: ["string", "null"] } } }
    MediaRef: { type: object, required: [id, url], properties: { id: { type: string, pattern: "^media_[0-9A-Za-z]{20,32}$" }, url: { type: string, format: uri }, alt: { type: ["string", "null"] } } }
    ProductSummary: { type: object, required: [id, slug, name, price, available, kind], properties: { id: { type: string, pattern: "^prd_[0-9A-Za-z]{20,32}$" }, slug: { type: string }, name: { type: string }, brand: { type: ["string", "null"] }, price: { $ref: "#/components/schemas/Money" }, available: { type: integer, minimum: 0 }, kind: { type: string, enum: [rts, pre_order, request_order] }, primary_media: { oneOf: [{ $ref: "#/components/schemas/MediaRef" }, { type: "null" }] } } }
    ProductVariant: { type: object, required: [id, sku, price, available], properties: { id: { type: string, pattern: "^var_[0-9A-Za-z]{20,32}$" }, sku: { type: string }, price: { $ref: "#/components/schemas/Money" }, available: { type: integer, minimum: 0 }, attributes: { type: object, additionalProperties: { type: string } } } }
    CartItemWrite: { type: object, required: [variant_id, quantity], properties: { variant_id: { type: string, pattern: "^var_[0-9A-Za-z]{20,32}$" }, quantity: { type: integer, minimum: 1, maximum: 99 } } }
    CartRead: { type: object, required: [id, items, subtotal], properties: { id: { type: string, pattern: "^cart_[0-9A-Za-z]{20,32}$" }, items: { type: array, items: { allOf: [{ $ref: "#/components/schemas/CartItemWrite" }, { type: object, required: [id, line_total], properties: { id: { type: string, pattern: "^citem_[0-9A-Za-z]{20,32}$" }, line_total: { $ref: "#/components/schemas/Money" } } }] } }, subtotal: { $ref: "#/components/schemas/Money" } } }
    CheckoutSessionRead: { type: object, required: [id, expires_at, total], properties: { id: { type: string, pattern: "^cs_[0-9A-Za-z]{20,32}$" }, expires_at: { type: string, format: date-time }, total: { $ref: "#/components/schemas/Money" } } }
    OrderRead: { type: object, required: [id, status, total, created_at], properties: { id: { type: string, pattern: "^ord_[0-9A-Za-z]{20,32}$" }, status: { type: string, description: "Nilai enum dibaca dari CHECK Doc 09; tidak ada salinan kedua (Doc 11 §8.3)." }, total: { $ref: "#/components/schemas/Money" }, created_at: { type: string, format: date-time } } }
    OrderItemRead: { type: object, required: [id, name, quantity, unit_price], properties: { id: { type: string, pattern: "^item_[0-9A-Za-z]{20,32}$" }, name: { type: string }, quantity: { type: integer, minimum: 1 }, unit_price: { $ref: "#/components/schemas/Money" } } }
    StatusChange: { type: object, required: [axis, to, changed_at], properties: { axis: { type: string, enum: [fulfillment, payment, sourcing, preorder] }, from: { type: ["string", "null"] }, to: { type: string }, changed_at: { type: string, format: date-time } } }
    PreorderBatchSummary: { type: object, required: [id, name, status, closes_at], properties: { id: { type: string, pattern: "^batch_[0-9A-Za-z]{20,32}$" }, name: { type: string }, status: { type: string, enum: [open, closing, closed, cancelled] }, closes_at: { type: string, format: date-time } } }
    ProductRequestRead: { type: object, required: [id, status, created_at], properties: { id: { type: string, pattern: "^prq_[0-9A-Za-z]{20,32}$" }, status: { type: string }, created_at: { type: string, format: date-time } } }
    QuotationRead: { type: object, required: [id, current_version, status, total], properties: { id: { type: string, pattern: "^quo_[0-9A-Za-z]{20,32}$" }, current_version: { type: integer, minimum: 1 }, status: { type: string, enum: [draft, sent, accepted, rejected, expired] }, total: { $ref: "#/components/schemas/Money" } } }
```
Hitungan (amandemen 2026-11-18): **18 jalur wajib Blueprint §7** (Doc 12 §2.1; gate I-7 Doc 11 §8.8 memeriksa kehadiran jalur wajib, bukan jumlah total operasi) + `getHealth` + endpoint auth gelombang 2026-11-18; prefix `/api/v1` datang dari `servers`. Klaim lama "17 operasi + health = 18" tidak berlaku — angka operasi total bukan invariant, kehadiran 18 jalur wajib yang invariant.

- [ ] **Step 4: Stub rute di Laravel supaya D-8 punya pembanding**

Ganti `apps/api/routes/api.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/**
 * Stub kontrak Sprint 0 (Doc 03 0.5): satu rute per operasi openapi.yaml,
 * semuanya 501 dengan problem details. Implementasi sebenarnya milik task
 * modul masing-masing. Tidak ada rute tanpa spec dan tidak ada spec tanpa rute.
 */
$stub = static function (string $operationId): JsonResponse {
    return response()->json([
        'type' => 'urn:elzara:problem:server:not-implemented',
        'title' => 'Kerangka kontrak Sprint 0',
        'status' => 501,
        'detail' => "Operasi {$operationId} ada di openapi.yaml; implementasinya menyusul.",
        'instance' => request()->getPathInfo(),
        'request_id' => (string) (request()->header('X-Request-Id') ?? 'unset'),
        'code' => 'NOT_IMPLEMENTED',
        'retryable' => false,
    ], 501);
};

Route::get('health', static fn () => response()->json(['status' => 'ok']))->name('health');

$contract = [
    ['GET', 'products', 'listProducts'],
    ['GET', 'products/{slug}', 'getProduct'],
    ['GET', 'preorder-batches', 'listOpenPreorderBatches'],
    ['GET', 'preorder-batches/{id}', 'getPreorderBatch'],
    ['POST', 'cart/items', 'addCartItem'],
    ['POST', 'checkout/sessions', 'createCheckoutSession'],
    ['POST', 'orders', 'createOrder'],
    ['GET', 'me/orders/{id}', 'getMyOrder'],
    ['POST', 'payment-intents', 'createPaymentIntent'],
    ['POST', 'webhooks/payments/{provider}', 'ingestPaymentWebhook'],
    ['POST', 'product-requests', 'createProductRequest'],
    ['POST', 'product-requests/{id}/attachments/presign', 'presignProductRequestAttachment'],
    ['GET', 'me/quotations/{id}', 'getMyQuotation'],
    ['POST', 'quotations/{id}/accept', 'acceptQuotation'],
    ['POST', 'admin/preorder-batches/{id}/close', 'closePreorderBatch'],
    ['POST', 'admin/purchase-orders', 'createPurchaseOrder'],
    ['POST', 'admin/shipments', 'createShipment'],
];

foreach ($contract as [$verb, $uri, $operationId]) {
    Route::match([$verb], $uri, static fn () => $stub($operationId))->name("contract.{$operationId}");
}
```
`Route::match` dipakai (bukan `addRoute`) agar setiap rute melewati grup `api` + `apiPrefix: 'api/v1'` yang dipasang `bootstrap/app.php`; rute modul nanti lewat `ModuleServiceProvider` dengan prefix `config('elzara.api_prefix')` yang sama.
Run: `composer dev:artisan route:list --path=api | tail -3` — Expected: baris terakhir `Showing [18] routes`.

- [ ] **Step 5: Gerbang D-8 — test paritas dua arah**

Create `apps/api/tests/Contract/OpenApiRouteParityTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Contract;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * Gerbang D-8 (Doc 03 0.5, Doc 12 §1.2 G1): rute Laravel dan path openapi.yaml
 * wajib identik dua arah. Rute tanpa spec = endpoint diam-diam;
 * path spec tanpa rute = dokumentasi mendahului kenyataan.
 */
final class OpenApiRouteParityTest extends TestCase
{
    private const SPEC = __DIR__ . '/../../../../packages/api-contracts/openapi.yaml';
    private const VERBS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return Yaml::parseFile(self::SPEC);
    }

    #[Test]
    public function berkas_spesifikasi_ada_dan_openapi_31(): void
    {
        $this->assertFileExists(self::SPEC);
        $this->assertSame('3.1.0', $this->spec()['openapi']);
    }

    #[Test]
    public function rute_api_dan_spesifikasi_sama_persis(): void
    {
        $prefix = (string) $this->spec()['servers'][0]['url'];
        $fromSpec = [];

        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach (array_keys((array) $operations) as $verb) {
                $fromSpec[] = strtoupper((string) $verb) . ' ' . $prefix . $path;
            }
        }

        $fromRoutes = [];

        foreach (app('router')->getRoutes() as $route) {
            if (!str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            foreach (array_values(array_intersect($route->methods(), self::VERBS)) as $verb) {
                $fromRoutes[] = $verb . ' /' . $route->uri();
            }
        }

        sort($fromSpec);
        sort($fromRoutes);

        $onlyInRoutes = array_values(array_diff($fromRoutes, $fromSpec));
        $onlyInSpec = array_values(array_diff($fromSpec, $fromRoutes));

        $this->assertSame([], $onlyInRoutes, 'Rute terdaftar tanpa path di openapi.yaml: ' . implode(', ', $onlyInRoutes));
        $this->assertSame([], $onlyInSpec, 'Path di openapi.yaml tanpa rute terdaftar: ' . implode(', ', $onlyInSpec));
    }

    #[Test]
    public function setiap_post_non_webhook_wajib_mendeklarasikan_idempotency_key(): void
    {
        foreach ($this->spec()['paths'] as $path => $operations) {
            if (!isset($operations['post']) || str_starts_with((string) $path, '/webhooks/')) {
                continue;
            }

            $refs = array_map(
                static fn (array $p): string => (string) ($p['$ref'] ?? ''),
                (array) ($operations['post']['parameters'] ?? []),
            );

            $this->assertContains(
                '#/components/parameters/IdempotencyKey',
                $refs,
                "POST {$path} wajib mendeklarasikan Idempotency-Key (Doc 12 §1.2 G6).",
            );
        }
    }
}
```
Bersihkan assertion yang sudah tidak berlaku: edit `apps/api/tests/Feature/Skeleton/SkeletonRoutingTest.php` dengan **menghapus** method `belum_ada_rute_api_v1_sebelum_task_05` (stub kontrak kini sah terdaftar), pertahankan `route_list_berlari_tanpa_error`.
Run: `docker run --rm -v "$PWD:/w" -w /w/apps/api php:8.4-cli php -r 'echo class_exists("Symfony\\Component\\Yaml\\Yaml") ? "ada" : "tidak";'` — Expected: `ada` (dibawa skeleton Laravel); bila `tidak`: `docker run --rm -v "$PWD:/w" -w /w/apps/api composer:2 require symfony/yaml:^7.2`.
Run: `composer dev:test` — Expected: `OK (11 tests, ...)` termasuk tiga test Contract.

- [ ] **Step 6: Validasi spec + generate + bekukan keluarannya**

Run (aturan kustom): `docker run --rm -v "$PWD:/w" -w /w/packages/api-contracts node:22 sh -lc 'npm ci && npm run lint'`
Expected: peringatan `OpenAPI 3.1 support is still experimental`, lalu `0 errors` / `0 warnings`, exit `0`.
Run (validasi struktural 3.1; jalur Linux memakai `$PWD`, ekuivalen CMD `%cd%`): `docker run --rm -v "$PWD/packages/api-contracts:/spec" redocly/cli:latest lint /spec/openapi.yaml`
Expected: `openapi.yaml: validated in ...ms` + `Woohoo! Your API description is valid.`, exit `0`.
Run (generate yang hasilnya ikut di-commit): `docker run --rm -v "$PWD:/w" -w /w/packages/api-contracts node:22 sh -lc 'npm run generate'`
Expected: `packages/api-contracts/generated/schema.d.ts` memuat `export interface paths` dengan 18 kunci; frontend tidak butuh step codegen saat build.
Run (gerbang regenerasi-diff yang dipakai CI):
```bash
docker run --rm -v "$PWD:/w" -w /w node:22 sh -lc 'git config --global --add safe.directory /w && cd /w/packages/api-contracts && npm run generate >/dev/null && git diff --exit-code -- packages/api-contracts/generated'
```
Expected: exit `0` tanpa diff. Bila keluar diff: spec diedit tanpa menjalankan generator, dan CI menolak PR itu.
Edit `composer.json` root: ganti skrip `ci:contracts` menjadi `"ci:contracts": "docker run --rm -v \"$PWD:/w\" -w /w node:22 sh -lc 'git config --global --add safe.directory /w && cd /w/packages/api-contracts && npm ci && npm run lint && npm run generate && git diff --exit-code -- generated'"`.

- [ ] **Step 7: Sambungkan kedua app ke client ter-generate**

```bash
for a in web admin; do
  docker run --rm -v "$PWD:/w" -w "/w/apps/$a" node:22 npm install --save-dev "file:../../packages/api-contracts" openapi-fetch@0.17.0
done
```
Create `apps/web/src/lib/api.ts` (salin identik ke `apps/admin/src/lib/api.ts`):
```ts
import createClient from "openapi-fetch";
import type { paths } from "@elzara/api-contracts";

export const api = createClient<paths>({
  baseUrl: process.env.NEXT_PUBLIC_API_BASE_URL ?? "https://localhost/api/v1",
});
```
Tidak ada `fetch(url)` manual di app (Doc 12 §1.3); satu-satunya pengecualian adalah `PUT` presigned ke MinIO yang bukan endpoint kita (§3.7).
Run: `docker run --rm -v "$PWD:/w" -w /w/apps/web node:22 sh -lc 'npm run lint && npx tsc --noEmit'` — Expected: exit `0`.

- [ ] **Step 8: Export dokumen manusia + gerbang akhir**

Run: `docker run --rm -v "$PWD/packages/api-contracts:/spec" redocly/cli:latest build-docs /spec/openapi.yaml -o /spec/dist.html && cp packages/api-contracts/dist.html docs/api/openapi-v1.html && rm packages/api-contracts/dist.html`
Expected: `docs/api/openapi-v1.html` terisi; berkas ini turunan, **bukan** sumber kebenaran (Doc 12 §1.1).
Run: `curl -sk https://localhost/api/v1/orders -X POST -H 'Content-Type: application/json' -H 'Idempotency-Key: 01J00000000000000000000TST' -d '{}' | head -c 200`
Expected: JSON problem details `"code":"NOT_IMPLEMENTED"` dengan `request_id` terisi — bukti stub kontrak menjawab lewat envelope yang benar.

- [ ] **Step 9: Commit**

```bash
git add packages/api-contracts apps/api/routes/api.php apps/api/tests/Contract apps/api/tests/Feature/Skeleton/SkeletonRoutingTest.php apps/web apps/admin docs/api/openapi-v1.html composer.json
git commit -m "feat(sprint-0): kontrak openapi 3.1 dengan client ts ter-generate dan gerbang paritas rute"
```

### Task 5 — CI satu pipeline untuk semua gerbang  (ID Doc 03: 0.3)

**Files:**
- Create: `.github/workflows/ci.yml`, `apps/api/phpstan.neon`, `apps/api/tests/Feature/Skeleton/MigrationGuardTest.php`, `docker/ci-compose.sh`
- Modify: `composer.json` (isi nyata `ci:analyse`, `ci:test`, `ci:schema`, `ci:env`, `ci:images`, `ci:frontend`, `ci:all`), `apps/api/phpunit.xml` (blok `<php>` env test)
- Test: seluruh job workflow + `composer ci:all` lokal

- [ ] **Step 1: Konfigurasi analyse dan env test di API**

Create `apps/api/phpstan.neon`:
```neon
includes:
    - vendor/larastan/larastan/extension.neon
parameters:
    paths:
        - app
        - bootstrap
        - config
        - routes
    level: max
```
Tambah pada blok `<php>` di `apps/api/phpunit.xml`:
```xml
        <env name="APP_KEY" value="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="/>
        <env name="DB_CONNECTION" value="pgsql"/>
        <env name="DB_HOST" value="127.0.0.1"/>
        <env name="DB_PORT" value="5432"/>
        <env name="DB_DATABASE" value="elzara_test"/>
        <env name="DB_USERNAME" value="elzara"/>
        <env name="DB_PASSWORD" value="elzara"/>
        <env name="REDIS_HOST" value="127.0.0.1"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
        <env name="CACHE_STORE" value="array"/>
```
B-11 ditegakkan: tidak ada satu pun test yang memakai SQLite. Suite `Concurrency` (harness Testcontainers milik task 0.3 paruh backend) menunjuk PostgreSQL 16 nyata; langkah `concurrency` di Step 2 hanya menyiapkan jalurnya.

- [ ] **Step 2: Workflow CI (runner Linux)**

Create `.github/workflows/ci.yml`:
```yaml
name: ci
on: { pull_request: {}, push: { branches: [master] } }
concurrency: { group: ci-${{ github.ref }}, cancel-in-progress: true }
env: { COMPOSER_HOME: /tmp/composer-home }
jobs:
  lint:
    runs-on: ubuntu-latest
    timeout-minutes: 8
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.4", extensions: pdo_pgsql, redis, intl, gd, bcmath, zip, opcache, coverage: none }
      - uses: ramsey/composer-install@v3
        with: { working-directory: apps/api }
      - run: vendor/bin/pint --test
        working-directory: apps/api
      - run: composer audit
        working-directory: apps/api
      - name: Tidak ada CRLF yang lolos
        run: "! grep -rlU $'\\r' --include='*.php' --include='*.yaml' --include='*.yml' --include='*.json' . || (echo 'Ditemukan baris CRLF'; exit 1)"

  analyse:
    needs: lint
    runs-on: ubuntu-latest
    timeout-minutes: 10
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.4", extensions: pdo_pgsql, redis, intl, gd, bcmath, zip, opcache, coverage: none }
      - uses: ramsey/composer-install@v3
        with: { working-directory: apps/api }
      - run: vendor/bin/phpstan analyse --no-progress --error-format=github
        working-directory: apps/api

  test:
    needs: lint
    runs-on: ubuntu-latest
    timeout-minutes: 12
    env: { DB_HOST: 127.0.0.1, DB_PORT: "5432", REDIS_HOST: 127.0.0.1, REDIS_PORT: "6379", CI: "true" }
    services:
      postgres:
        image: postgres:16-alpine
        env: { POSTGRES_DB: elzara_test, POSTGRES_USER: elzara, POSTGRES_PASSWORD: elzara }
        ports: ["5432:5432"]
        options: --health-cmd "pg_isready -U elzara" --health-interval 5s --health-timeout 3s --health-retries 20
      redis:
        image: redis:7-alpine
        ports: ["6379:6379"]
        options: --health-cmd "redis-cli ping" --health-interval 5s --health-timeout 3s --health-retries 20
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.4", extensions: pdo_pgsql, redis, intl, gd, bcmath, zip, opcache, coverage: none }
      - uses: ramsey/composer-install@v3
        with: { working-directory: apps/api }
      - run: php artisan migrate --force --no-interaction
        working-directory: apps/api
      - run: php artisan test --testsuite=Unit,Feature,Contract
        working-directory: apps/api

  concurrency:
    needs: lint
    runs-on: ubuntu-latest
    timeout-minutes: 12
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.4", extensions: pdo_pgsql, redis, intl, zip, opcache, coverage: none }
      - uses: ramsey/composer-install@v3
        with: { working-directory: apps/api }
      - name: Suite Concurrency lewat Testcontainers (PostgreSQL 16 nyata, B-11)
        run: |
          if [ -z "$(find tests/Concurrency -name '*Test.php' -print -quit)" ]; then
            echo "::warning::Suite Concurrency masih kosong - job ini HIJAU VAKUM sampai task harness Testcontainers selesai. Jangan catat D-9 sebagai sudah terbukti."
            exit 0
          fi
          php artisan test --testsuite=Concurrency
        working-directory: apps/api

  contracts:
    needs: lint
    runs-on: ubuntu-latest
    timeout-minutes: 8
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: "22", cache: npm, cache-dependency-path: packages/api-contracts/package-lock.json }
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.4", extensions: pdo_pgsql, redis, intl, zip, opcache, coverage: none }
      - name: Spectral (aturan kustom G2) + Redocly 3.1
        run: |
          npm ci
          npm run lint
          docker run --rm -v "$GITHUB_WORKSPACE/packages/api-contracts:/spec" redocly/cli:latest lint /spec/openapi.yaml
        working-directory: packages/api-contracts
      - name: Regenerasi client harus nol diff (D-S0-5)
        run: |
          npm run generate
          git diff --exit-code -- packages/api-contracts/generated || { echo 'openapi.yaml diubah tanpa menjalankan generator (Doc 12 §1.3)'; exit 1; }
        working-directory: packages/api-contracts
      - name: Gerbang D-8 paritas rute vs spec
        run: |
          composer install --no-interaction
          php artisan test tests/Contract/OpenApiRouteParityTest.php
        working-directory: apps/api

  frontend:
    needs: contracts
    runs-on: ubuntu-latest
    timeout-minutes: 12
    strategy: { fail-fast: false, matrix: { app: [web, admin] } }
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: "22", cache: npm, cache-dependency-path: apps/${{ matrix.app }}/package-lock.json }
      - name: Lint + typecheck + build terhadap client ter-generate
        run: |
          npm ci
          npm install --no-save "file:../../packages/api-contracts" openapi-fetch@0.17.0
          npm run lint
          npx tsc --noEmit
          npm run build
        working-directory: apps/${{ matrix.app }}
      - uses: actions/upload-artifact@v4
        with: { name: ${{ matrix.app }}-next-build, path: apps/${{ matrix.app }}/.next, retention-days: 3 }

  schema:
    needs: lint
    runs-on: ubuntu-latest
    timeout-minutes: 10
    env: { DB_HOST: 127.0.0.1, DB_PORT: "5432", CI: "true" }
    services:
      postgres:
        image: postgres:16-alpine
        env: { POSTGRES_DB: elzara_test, POSTGRES_USER: elzara, POSTGRES_PASSWORD: elzara }
        ports: ["5432:5432"]
        options: --health-cmd "pg_isready -U elzara" --health-interval 5s --health-timeout 3s --health-retries 20
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: "8.4", extensions: pdo_pgsql, redis, intl, zip, opcache, coverage: none }
      - uses: ramsey/composer-install@v3
        with: { working-directory: apps/api }
      - run: php artisan test tests/Feature/Skeleton/MigrationGuardTest.php
        working-directory: apps/api

  images:
    needs: [frontend, test]
    runs-on: ubuntu-latest
    timeout-minutes: 15
    strategy: { fail-fast: false, matrix: { include: [ { target: api, dockerfile: api.Dockerfile, image: elzara/api }, { target: web, dockerfile: next.Dockerfile, image: elzara/web, args: NEXT_APP=web }, { target: admin, dockerfile: next.Dockerfile, image: elzara/admin, args: "NEXT_APP=admin NEXT_BASE_PATH=/admin" } ] } }
    steps:
      - uses: actions/checkout@v4
      - uses: docker/setup-buildx-action@v3
      - uses: actions/cache@v4
        with: { path: /tmp/buildx-${{ matrix.target }}, key: buildx-${{ matrix.target }}-${{ github.run_number }}, restore-keys: buildx-${{ matrix.target }}- }
      - name: Build ${{ matrix.image }} (target < 6 menit cache hangat, spec §6)
        run: docker build -f docker/${{ matrix.dockerfile }} ${{ matrix.args && format('--build-arg {0}', matrix.args) || '' }} --cache-from type=local,src=/tmp/buildx-${{ matrix.target }} --cache-to type=local,mode=max,dest=/tmp/buildx-${{ matrix.target }} -t ${{ matrix.image }}:${GITHUB_SHA} .

  gate:
    if: always()
    needs: [lint, analyse, test, concurrency, contracts, frontend, schema, images]
    runs-on: ubuntu-latest
    steps:
      - name: Semua gerbang harus success (PR merah bila salah satu gagal)
        run: |
          printf '%s' '${{ toJSON(needs) }}' > /tmp/needs.json
          node -e 'const n=require("/tmp/needs.json");const bad=Object.entries(n).filter(([,v])=>v.result!=="success");if(bad.length){console.error("Gerbang tidak hijau: "+bad.map(([k,v])=>k+"="+v.result).join(", "));process.exit(1)}console.log("semua gerbang success")'
```
Satu langkah proteksi branch setelah workflow pertama hijau: Settings - Branches - protect `master`, "Require status checks to pass" dengan daftar **`gate`** saja. Karena `gate` menggantung pada kedelapan job lewat `needs:` + `if: always()` dan keluar `1` bila ada yang bukan `success`, satu gerbang merah memblokir merge.

- [ ] **Step 3: Penjaga skema C1-C8 yang jujur**

Create `apps/api/tests/Feature/Skeleton/MigrationGuardTest.php`:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Skeleton;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Doc 10 §10 C1-C8. Butir yang belum bisa dijaga pada Sprint 0 task ini
 * di-skip dengan alasan, bukan dilaporkan hijau.
 */
final class MigrationGuardTest extends TestCase
{
    /** @return list<string> */
    private function migrationFiles(): array
    {
        $files = array_merge(
            glob(base_path('database/migrations/*.php')) ?: [],
            glob(base_path('app/Modules/*/Infrastructure/Database/Migrations/*.php')) ?: [],
        );
        sort($files);

        return $files;
    }

    #[Test]
    public function c1_migrate_fresh_di_postgres_bersih(): void
    {
        $this->requireCi('C1 butuh PostgreSQL 16 nyata sebagai service CI; SQLite dilarang (B-11).');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->assertSame(0, Artisan::call('migrate:status'));
    }

    #[Test]
    public function c2_basename_migrasi_unik_di_seluruh_app(): void
    {
        $basenames = array_map('basename', $this->migrationFiles());
        $duplicates = array_keys(array_filter(array_count_values($basenames), static fn (int $n): bool => $n > 1));
        $this->assertSame([], $duplicates, 'Basename duplikat melanggar M-1: ' . implode(', ', $duplicates));
    }

    #[Test]
    public function c3_urutan_references_mengikuti_level_appendix_a(): void
    {
        $this->markTestSkipped('C3 belum bisa dijaga di task CI ini: belum ada satu pun migrasi modul (lahir di task skema Doc 03 0.7/0.11). Harness-nya membaca daftar tabel Appendix A lebih dulu.');
    }

    #[Test]
    public function c4_tidak_ada_tipe_uang_float_dan_create_type(): void
    {
        foreach ($this->migrationFiles() as $file) {
            $body = (string) file_get_contents($file);
            $this->assertStringNotContainsString('CREATE TYPE', $body, "{$file} melanggar M-11");
            $this->assertStringNotContainsString('ADD VALUE', $body, "{$file} melanggar M-11");
            $this->assertDoesNotMatchRegularExpression('/->(float|double|decimal)\(/', $body, "{$file} memakai tipe uang non-integer (S-1)");
        }
    }

    #[Test]
    public function c5_migrate_lalu_rollback_satu_step(): void
    {
        $this->requireCi('C5 butuh PostgreSQL nyata; rollback --step=1 diverifikasi terhadap file yang ada.');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]));
    }

    #[Test]
    public function c6_drift_check_terhadap_schema_snapshot(): void
    {
        $this->markTestSkipped('C6 butuh docs/schema.snapshot.sql yang lahir bersama skema Identity (Doc 03 0.7) dan perintah pembuatnya (Doc 10 §10: `composer dev:db-snapshot`). Belum ada pada task CI ini.');
    }

    #[Test]
    public function c7_reference_seeder_idempoten(): void
    {
        $this->markTestSkipped('C7 butuh ReferenceSeeder (Doc 03 0.11) yang berada di luar kepemilikan task CI; ditegakkan begitu jalur seeder masuk.');
    }

    #[Test]
    public function c8_setiap_migrasi_punya_header_modul_sprint_tier_expand(): void
    {
        $files = $this->migrationFiles();

        if ($files === []) {
            $this->markTestSkipped('C8 hijau vakum: hanya file bawaan framework yang ada, dan tidak ada file modul untuk diperiksa.');
        }

        foreach ($files as $file) {
            $head = implode("\n", array_slice(explode("\n", (string) file_get_contents($file)), 0, 14));

            foreach (['Modul', 'Sprint', 'Tier', 'Expand'] as $key) {
                $this->assertMatchesRegularExpression('/\/\/\s*' . $key . '\s*:/', $head, "{$file} tanpa header {$key} (Doc 10 §2)");
            }
        }
    }

    private function requireCi(string $reason): void
    {
        if (!filter_var(env('CI', false), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped($reason);
        }
    }
}
```
Butir file bawaan framework (`0001_create_users_table.php` dan rekan-rekannya dari skeleton) lolos C2/C4/C8-vakum karena C8 hanya menuntut header pada file modul; keputusan itu eksplisit di pesan skip, bukan diam-diam.
Run: `composer dev:test` — Expected: `OK, but there were issues` dengan daftar `skipped` untuk C1/C3/C5/C6/C7/C8, dan **hijau nyata** untuk C2/C4. Tidak ada angka yang diklaim melebihi yang dijaga.

- [ ] **Step 4: Isi skrip `ci:*` root dengan jalur nyata**

Ganti blok skrip `ci:*` di `composer.json` menjadi:
```json
        "ci:analyse": "docker run --rm -v \"$PWD:/w\" -w /w/apps/api php:8.4-cli sh -lc 'composer install --no-interaction && vendor/bin/phpstan analyse --no-progress'",
        "ci:test": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec -T api php artisan test --testsuite=Unit,Feature,Contract",
        "ci:schema": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec -T -e CI=true -e DB_HOST=postgres api php artisan test tests/Feature/Skeleton/MigrationGuardTest.php",
        "ci:env": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec -T api php artisan test tests/Feature/Skeleton/EnvVarCoverageTest.php",
        "ci:frontend": "sh -lc 'for a in web admin; do docker run --rm -v \"$PWD:/w\" -w /w/apps/$a node:22 sh -lc \"npm ci && npm run lint && npx tsc --noEmit && npm run build\"; done'",
        "ci:concurrency": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec -T api php artisan test --testsuite=Concurrency",
        "ci:images": "sh -lc 'docker build -f docker/api.Dockerfile -t elzara/api:ci . && docker build -f docker/next.Dockerfile --build-arg NEXT_APP=web -t elzara/web:ci . && docker build -f docker/next.Dockerfile --build-arg NEXT_APP=admin --build-arg NEXT_BASE_PATH=/admin -t elzara/admin:ci .'",
        "ci:all": "sh -lc 'composer ci:lint && composer ci:analyse && composer ci:contracts && composer ci:frontend && composer dev:up && composer ci:test && composer ci:concurrency && composer ci:env && composer ci:schema && composer ci:images && composer ci:down-check'"
```
Create `docker/ci-compose.sh`:
```bash
#!/bin/sh
# Jalankan semua gerbang CI di mesin developer (Linux/macOS; Windows: composer ci:all).
set -eu
cd "$(dirname "$0")/../.."
composer ci:all
```

- [ ] **Step 5: Jalankan semuanya lokal satu kali**

Run: `chmod +x docker/ci-compose.sh && composer ci:all`
Expected berurutan tanpa pertanyaan: Pint lolos, `composer audit` bersih, phpstan level max lolos, spectral + redocly lolos, `generated/` nol diff, dua app Next lolos lint/typecheck/build, `composer ci:test` hijau (suite Unit/Feature/Contract di Postgres-Redis container), `ci:env` hijau, `ci:schema` hijau dengan daftar skipped Step 3, tiga image ter-build. Total < 12 menit dengan cache hangat (spec §6); bila tidak tercapai, potong paralelisasi lebih dulu - batas waktu tidak dinaikkan.

- [ ] **Step 6: Buktikan gerbang bisa menolak**

Run (lalu batalkan):
```bash
docker run --rm -v "$PWD:/w" -w /w/apps/api php:8.4-cli sh -lc "sed -i \"s/'orders', //\" config/horizon.php && php artisan test tests/Feature/Skeleton/HorizonQueueNamesTest.php; git checkout config/horizon.php"
```
Expected: `FAILED ... hanya_tujuh_antrian_kanonik_yang_didefinisikan`, kemudian tree bersih (`git status --porcelain` kosong).
Run (hapus satu stub rute tanpa menyentuh spec):
```bash
docker run --rm -v "$PWD:/w" -w /w/apps/api php:8.4-cli sh -lc "sed -i '/createShipment/d' routes/api.php && php artisan test tests/Contract/OpenApiRouteParityTest.php; git checkout routes/api.php"
```
Expected: gagal dengan `Path di openapi.yaml tanpa rute terdaftar: POST /api/v1/admin/shipments` — bukti D-8 bekerja dua arah, dan alasan job `contracts` di CI menolak PR yang mengubah controller tanpa spec.

- [ ] **Step 7: Commit**

```bash
git add .github/workflows/ci.yml apps/api/phpstan.neon apps/api/phpunit.xml apps/api/tests/Feature/Skeleton/MigrationGuardTest.php docker/ci-compose.sh composer.json
git commit -m "feat(sprint-0): ci delapan job dengan gerbang kontrak, build ganda next, dan penjaga skema jujur"
```

---

### Task 6 — ID 0.7a: migrasi fondasi platform (`0001`-`0006`) di PostgreSQL 16 nyata

> **Amandemen 2026-11-18 (status disk + D-S0-9):** penomoran `0004`-`0006` (lama) tidak berlaku, diganti satu berkas gabungan di disk: `apps/api/app/Platform/Infrastructure/Database/Migrations/0001_Platform_create_reliability_tables.php` yang membuat `outbox_events`, `inbox_events`, `idempotency_keys` sekaligus. `apps/api/database/migrations/` masih kosong — migrasi Framework `0001`-`0003` di daftar Files belum dibuat; penomorannya mengikuti D-S0-9 (band Sprint 0 `0001-0199`, basename unik, pemilik = direktori), bukan angka bebas. Daftar Files dan langkah di bawah adalah rujukan historis; yang mengikat adalah isi tabel/kolom (dipertahankan) + penomoran dan lokasi sesuai disk + aturan M-1..M-4 di D-S0-9. Harness `TestPg` dan `FoundationMigrationTest` belum ada; tidak ada test yang pernah dijalankan.

**Files:**
- Create: `apps/api/tests/Support/Platform/TestPg.php`
- Create: `apps/api/tests/Contract/FoundationMigrationTest.php`
- Create: `apps/api/database/migrations/0001_Framework_create_jobs_table.php`
- Create: `apps/api/database/migrations/0002_Framework_create_failed_jobs_table.php`
- Create: `apps/api/database/migrations/0003_Framework_create_job_batches_table.php`
- Create: `apps/api/app/Platform/Infrastructure/Database/Migrations/0004_Platform_create_outbox_events_table.php`
- Create: `apps/api/app/Platform/Infrastructure/Database/Migrations/0005_Platform_create_inbox_events_table.php`
- Create: `apps/api/app/Platform/Infrastructure/Database/Migrations/0006_Platform_create_idempotency_keys_table.php`
- Modify: `apps/api/config/database.php`

- [ ] **Step 1: Tulis harness Postgres 16 nyata** — `tests/Support/Platform/TestPg.php`. Bila Task 3 sudah menaruh harness Testcontainers, isi `start()` didelegasikan ke harness itu; API publik di bawah tetap karena Task 8-10 memakainya.

```php
<?php

namespace Tests\Support\Platform;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class TestPg
{
    private static ?string $cid = null;
    private static ?int $port = null;

    public static function start(string $name = 'platform_pg'): void
    {
        if (self::$cid === null) {
            self::$cid = trim((string) shell_exec('docker run -d -e POSTGRES_USER=elzara -e POSTGRES_PASSWORD=elzara'
                . ' -e POSTGRES_DB=elzara_test -p 127.0.0.1:0:5432 postgres:16-alpine'));
            if (self::$cid === '') { throw new RuntimeException('docker run postgres:16 gagal - daemon mati?'); }
            self::$port = (int) preg_replace('/\D+/', '', (string) shell_exec('docker port ' . self::$cid . ' 5432/tcp'));
            for ($i = 0; $i < 60; $i++) {
                try { new PDO('pgsql:host=127.0.0.1;port=' . self::$port . ';dbname=elzara_test', 'elzara', 'elzara'); break; }
                catch (RuntimeException) { if ($i === 59) { throw new RuntimeException('Postgres 16 tidak siap dalam 30 detik'); } usleep(500_000); }
            }
        }
        config(["database.connections.{$name}" => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => self::$port,
            'database' => 'elzara_test', 'username' => 'elzara', 'password' => 'elzara', 'charset' => 'utf8',
            'prefix' => '', 'schema' => 'public', 'searchPath' => 'public']]);
        DB::purge($name);
    }

    public static function migrateFresh(string $name = 'platform_pg'): void
    {
        Artisan::call('migrate:fresh', ['--database' => $name]);
    }

    public static function tables(): array
    {
        return array_column(DB::connection('platform_pg')->select(
            "select table_name from information_schema.tables where table_schema = 'public' order by table_name"
        ), 'table_name');
    }

    /** @return array<string, string> nama kendala -> pg_get_constraintdef */
    public static function constraints(string $table): array
    {
        $rows = DB::connection('platform_pg')->select(
            "select conname, pg_get_constraintdef(oid) as def from pg_constraint c
             join pg_namespace n on n.oid = c.connamespace where n.nspname = 'public' and c.conrelid = ?::regclass", [$table]);
        return array_combine(array_column($rows, 'conname'), array_map(
            static fn (string $d): string => preg_replace('/\s+/', ' ', trim($d)), array_column($rows, 'def')));
    }

    public static function stop(): void
    {
        if (self::$cid !== null) { shell_exec('docker stop ' . self::$cid); self::$cid = null; self::$port = null; }
    }
}
```

- [ ] **Step 2: Tulis test fondasi yang gagal** — angka yang diassert adalah **6 tabel fondasi** dari `information_schema`, bukan angka target akhir semua sprint; angka target akhir hanya dimiliki Doc 09 §19 (tidak ditulis tangan di sini — Doc 11 §4.2/G).

```php
<?php

namespace Tests\Contract;

use Illuminate\Support\Facades\Artisan;
use Tests\Contract\TestCase;
use Tests\Support\Platform\TestPg;

final class FoundationMigrationTest extends TestCase
{
    public static function tearDownAfterClass(): void { TestPg::stop(); }

    public function test_tepat_enam_tabel_fondasi_yang_berdiri(): void
    {
        TestPg::start(); TestPg::migrateFresh();
        $this->assertSame(['failed_jobs', 'idempotency_keys', 'inbox_events', 'job_batches', 'jobs',
            'migrations', 'outbox_events'], TestPg::tables());
        $this->assertCount(6, array_diff(TestPg::tables(), ['migrations']));
    }

    public function test_kendala_meniru_doc_09_17(): void
    {
        TestPg::start(); TestPg::migrateFresh();
        $this->assertSame('UNIQUE (consumer, event_id)', TestPg::constraints('inbox_events')['uq_inbox_once']);
        $this->assertSame('UNIQUE (actor_id, operation, idempotency_key)',
            TestPg::constraints('idempotency_keys')['uq_idempotency_once']);
        $this->assertSame("CHECK (((status)::text = ANY ((ARRAY['in_progress'::character varying,"
            . " 'succeeded'::character varying, 'failed'::character varying])::text[])))",
            TestPg::constraints('idempotency_keys')['ck_idempotency_keys_status']);
    }

    public function test_down_menghapus_ke_enam_enam(): void
    {
        TestPg::start(); TestPg::migrateFresh();
        Artisan::call('migrate:rollback', ['--database' => 'platform_pg', '--step' => 6]);
        $this->assertSame(['migrations'], TestPg::tables());
    }
}
```

- [ ] **Step 3: Jalankan, pastikan gagal** — `composer dev:test -- tests/Contract/FoundationMigrationTest.php`. Keluaran yang diharapkan: `FAILURES` dengan `relation "outbox_events" does not exist` (atau `tables()` hanya berisi `migrations`) — bukan hijau.
- [ ] **Step 4: Tulis `0001`-`0003` (tabel bawaan framework, kolom tidak disentuh)** — migrasi bawaan Laravel dipindah ke `apps/api/database/migrations/` dengan nomor Doc 10; segmen modul dikosongkan karena tabel ini tidak dimiliki modul mana pun (Doc 10 §3 butir 3). `0001`: `id bigIncrements`, `connection`/`queue` `string`, `payload` `longText`, `attempts` `tinyInteger`, `reserved_at`/`available_at`/`created_at` `integer nullable`. `0002`: `id bigIncrements`, `uuid` `string(36)->unique()`, `connection`/`queue`/`classification` `string nullable`, `payload` `longText nullable`, `exception` `longText`, `failed_at` `timestamp` dengan `useCurrent()`. `0003`: `id` `char(36)` primary, `name`/`queue` `string`, `total_jobs`/`pending_jobs`/`failed_jobs` `integer`, `failed_job_ids` `text`, `options` `mediumText nullable`, `cancelled_at`/`created_at`/`finished_at` `integer nullable`. Tiap berkas: header `// modul: Platform (lintas modul — pemilik tulis trio reliability, Doc 01 §3.1) | level: L0 | sprint: 0 | tier: 0 | expand: ya | contract: tidak ada`, `up()` hanya `Schema::create`, `down()` hanya `Schema::dropIfExists`. **Jangan** mengonversi tipe kolom ke `timestamptz` (Doc 09 §17: `tidak diubah`).
- [ ] **Step 5: Tulis `0004_Platform_create_outbox_events_table.php` persis Doc 09 §17** — append-only (S-6) sehingga tanpa `updated_at`; `created_at` tetap ada sebagai waktu tulis.

```php
<?php
// 0004_Platform_create_outbox_events_table.php
// modul: Platform (lintas modul — pemilik tulis trio reliability, Doc 01 §3.1) | level: L0 | sprint: 0 | tier: 0 | expand: ya | contract: tidak ada

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_name', 80);
            $t->string('aggregate_type', 40);
            $t->bigInteger('aggregate_id');
            $t->jsonb('payload');
            $t->timestamptz('occurred_at')->default(DB::raw('now()'));
            $t->timestamptz('dispatched_at')->nullable();
            $t->smallInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->string('locked_by', 64)->nullable();
            $t->timestamptz('locked_at')->nullable();
            $t->timestamptz('created_at')->default(DB::raw('now()'));
        });
        DB::statement('CREATE INDEX idx_outbox_pending ON outbox_events (occurred_at) WHERE dispatched_at IS NULL');
        DB::statement('CREATE INDEX idx_outbox_agg ON outbox_events (aggregate_type, aggregate_id, occurred_at)');
    }

    public function down(): void { Schema::dropIfExists('outbox_events'); }
};
```

- [ ] **Step 6: Tulis `0005_Platform_create_inbox_events_table.php` (L0) dan `0006_Platform_create_idempotency_keys_table.php` (L2)** — `actor_id` **sengaja tanpa `REFERENCES`** karena `users` (L1) baru dibuat Task 11 di band `0007+` dan C3 menolak rujukan ke tabel yang belum ada di nomor sebelumnya; FK `users(id) ON DELETE CASCADE` menyusul sebagai migrasi band Identity lewat `ALTER TABLE ... ADD CONSTRAINT` (M-4 melarang mengedit `0006` setelah merge). CHECK bernama selalu lewat `DB::statement` (Doc 10 §2).

```php
// 0005 - up(): Schema::create('inbox_events', ...) lalu Schema::table(...)
$t->id(); $t->string('consumer', 64); $t->uuid('event_id');
$t->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
$t->timestamptz('received_at')->default(DB::raw('now()'));
$t->string('status', 16)->default('pending');
$t->timestamptz('processed_at')->nullable(); $t->text('error')->nullable();
$t->unique(['consumer', 'event_id'], 'uq_inbox_once');
$t->index(['status', 'received_at'], 'idx_inbox_status');
// down(): Schema::dropIfExists('inbox_events');

// 0006 - up(): Schema::create('idempotency_keys', ...) lalu Schema::table(...) + DB::statement
$t->id();
$t->bigInteger('actor_id');                 // FK ke users(id) ON DELETE CASCADE -> migrasi band Identity
$t->string('operation', 80); $t->string('idempotency_key', 120); $t->char('request_hash', 64);
$t->string('status', 16)->default('in_progress'); $t->string('response_ref', 200)->nullable();
$t->timestamptz('expires_at');
$t->unique(['actor_id', 'operation', 'idempotency_key'], 'uq_idempotency_once');
$t->index('expires_at', 'idx_idempotency_expiry');
DB::statement("ALTER TABLE idempotency_keys ADD CONSTRAINT ck_idempotency_keys_status
    CHECK (status IN ('in_progress','succeeded','failed'))");
// down(): DB::statement('ALTER TABLE idempotency_keys DROP CONSTRAINT IF EXISTS ck_idempotency_keys_status');
//         Schema::dropIfExists('idempotency_keys');
```

Rumus unique: Doc 09 §17 menulis `uq idempotency_once (actor_id, operation, idempotency_key)` sedangkan Blueprint §6 menulis `UNIQUE(customer_id, idempotency_key, operation)`. Yang dipakai adalah **rumus Doc 09** (kolom `actor_id`, urutan `(actor, operation, key)`) — scope identik dengan Doc 12 §3.4, hanya nama kolom berbeda.
- [ ] **Step 7: Daftarkan koneksi default `platform`** di `config/database.php`: `'default' => env('DB_CONNECTION', 'platform')` dengan `connections.platform` driver `pgsql`, supaya kode platform menyebut `DB::connection('platform')` dan test bisa menyuntik `platform_pg`. Perintah: `composer dev:migrate` lalu `docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec -T api php artisan migrate:status`. Keluaran yang diharapkan: enam baris `0001`-`0006` berstatus `Ran`, tidak ada `Pending`.
- [ ] **Step 8: Hijaukan test + gerbang C1-C8** — `composer dev:test -- tests/Contract/FoundationMigrationTest.php` lalu `composer ci:all`. Keluaran yang diharapkan: `OK (3 tests, 6 assertions)` dan baris `C2 basename unik: OK`, `C3 urutan level: OK`, `C5 rollback per file: OK`, `C8 header komentar: OK`.
- [ ] **Step 9: Commit** — `git add apps/api/database/migrations apps/api/config/database.php apps/api/tests/Support/Platform/TestPg.php apps/api/tests/Contract/FoundationMigrationTest.php` lalu `git commit -m "feat(sprint-0): migrasi fondasi platform 0001-0006 diverifikasi di postgres 16"`.

### Task 7 — ID 0.7b: `ApiProblem` RFC 7807 + `request-id` + peta domain exception

**Files:**
- Create: `apps/api/app/Platform/Domain/Exception/DomainException.php`
- Create: `apps/api/app/Platform/Domain/Exception/{InsufficientStock,BatchQuotaExhausted,InvalidTransition,IdempotencyKeyReuse,ConflictRetryExhausted,PaidStockException,MediaValidationFailed,QuotationVersionStale}.php`
- Create: `apps/api/app/Platform/Infrastructure/Http/ApiProblemRenderer.php`
- Create: `apps/api/app/Platform/Infrastructure/Http/AttachRequestId.php`
- Modify: `apps/api/bootstrap/app.php`
- Modify: `packages/api-contracts/openapi.yaml`
- Test: `apps/api/tests/Feature/Platform/ApiProblemTest.php`
- Test: `apps/api/tests/Feature/Platform/RequestIdTest.php`

- [ ] **Step 1: Tulis test bentuk problem details yang gagal** — kolom dibandingkan satu per satu terhadap contoh Doc 12 §4.1.

```php
<?php

namespace Tests\Feature\Platform;

use App\Platform\Domain\Exception\InsufficientStock;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Tests\Feature\TestCase;

final class ApiProblemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->post('/api/v1/__probe/throw', function () {
            throw new InsufficientStock("Varian 'figures/xxx-blue-L' tersisa 2, diminta 3.",
                [['pointer' => '/items/1/variant_id', 'code' => 'quantity_exceeds_available', 'detail' => 'tersisa 2']]);
        });
        Route::middleware('api')->get('/api/v1/__probe/boom', fn () => throw new \RuntimeException('SQL: select * from secret'));
    }

    public function test_insufficient_stock_meniru_bentuk_doc_12_4_1(): void
    {
        $res = $this->postJson('/api/v1/__probe/throw', []);
        $res->assertStatus(422)->assertHeader('content-type', 'application/problem+json');
        $body = $res->json();
        $this->assertSame(['type', 'title', 'status', 'detail', 'instance', 'request_id', 'code', 'retryable', 'errors'],
            array_keys($body));
        $this->assertSame('urn:elzara:problem:inventory:insufficient-stock', $body['type']);
        $this->assertSame('Stok tidak mencukupi', $body['title']);
        $this->assertSame('/api/v1/__probe/throw', $body['instance']);
        $this->assertFalse($body['retryable']);
        $this->assertSame('quantity_exceeds_available', $body['errors'][0]['code']);
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $body['request_id']);
    }

    public function test_throwable_asli_menjadi_500_tanpa_stack_trace(): void
    {
        $body = $this->getJson('/api/v1/__probe/boom')->assertStatus(500)->json();
        $this->assertSame('urn:elzara:problem:server:internal', $body['type']);
        $this->assertStringNotContainsString('select * from secret', json_encode($body, JSON_UNESCAPED_UNICODE));
        $this->assertSame($body['request_id'], Context::get('request_id'));
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal** — `composer dev:test -- tests/Feature/Platform/ApiProblemTest.php`. Keluaran yang diharapkan: `Class "App\Platform\Domain\Exception\InsufficientStock" not found`.
- [ ] **Step 3: Tulis basis exception + delapan subclass** — nama **hanya** yang ada di peta Doc 12 §4.3; `BusinessRuleViolation`, `UnauthorizedAction`, `VersionStale` tidak ada di peta itu sehingga tidak dibuat (yang dipakai: `InvalidTransition`, baris 403 `authorization:forbidden` tanpa kelas, dan `QuotationVersionStale`).

```php
<?php

namespace App\Platform\Domain\Exception;

abstract class DomainException extends \RuntimeException
{
    /** @var list<array{pointer?: string, code?: string, detail?: string}> */
    public array $fieldErrors;

    abstract public function problemType(): string;   // urn:elzara:problem:{kategori}:{kode-kebab-case}
    abstract public function status(): int;
    abstract public function errorCode(): string;
    abstract public function errorTitle(): string;
    public function retryable(): bool { return false; }

    public function __construct(public string $detail, array $fieldErrors = [], ?\Throwable $previous = null)
    {
        $this->fieldErrors = $fieldErrors;
        parent::__construct($detail, $this->status(), $previous);
    }
}

final class InsufficientStock extends DomainException
{
    public function problemType(): string { return 'urn:elzara:problem:inventory:insufficient-stock'; }
    public function status(): int { return 422; }
    public function errorCode(): string { return 'INSUFFICIENT_STOCK'; }
    public function errorTitle(): string { return 'Stok tidak mencukupi'; }
}

final class BatchQuotaExhausted extends DomainException
{
    public function problemType(): string { return 'urn:elzara:problem:batch:quota-exhausted'; }
    public function status(): int { return 422; }
    public function errorCode(): string { return 'BATCH_QUOTA_EXHAUSTED'; }
    public function errorTitle(): string { return 'Kuota batch habis'; }
}

final class InvalidTransition extends DomainException
{
    public function problemType(): string { return 'urn:elzara:problem:state:transition-not-allowed'; }
    public function status(): int { return 409; }
    public function errorCode(): string { return 'STATE_TRANSITION_NOT_ALLOWED'; }
    public function errorTitle(): string { return 'Transisi status tidak diizinkan'; }
}

final class IdempotencyKeyReuse extends DomainException
{
    public function problemType(): string { return 'urn:elzara:problem:idempotency:key-payload-mismatch'; }
    public function status(): int { return 409; }
    public function errorCode(): string { return 'IDEMPOTENCY_KEY_PAYLOAD_MISMATCH'; }
    public function errorTitle(): string { return 'Key idempotensi dipakai untuk payload berbeda'; }
}

final class ConflictRetryExhausted extends DomainException
{
    public function problemType(): string { return 'urn:elzara:problem:conflict:retry-exhausted'; }
    public function status(): int { return 409; }
    public function errorCode(): string { return 'CONFLICT_RETRY_EXHAUSTED'; }
    public function errorTitle(): string { return 'Konflik, percobaan ulang habis'; }
    public function retryable(): bool { return true; }
}

final class PaidStockException extends DomainException
{
    public function problemType(): string { return 'urn:elzara:problem:order:paid-stock-exception'; }
    public function status(): int { return 500; }
    public function errorCode(): string { return 'PAID_STOCK_EXCEPTION'; }
    public function errorTitle(): string { return 'Pembayaran sah tiba setelah stok dilepas'; }
}

final class MediaValidationFailed extends DomainException
{
    public function problemType(): string { return 'urn:elzara:problem:media:validation-failed'; }
    public function status(): int { return 422; }
    public function errorCode(): string { return 'MEDIA_VALIDATION_FAILED'; }
    public function errorTitle(): string { return 'Berkas media ditolak'; }
}

final class QuotationVersionStale extends DomainException
{
    public function problemType(): string { return 'urn:elzara:problem:quotation:version-stale'; }
    public function status(): int { return 409; }
    public function errorCode(): string { return 'QUOTATION_VERSION_STALE'; }
    public function errorTitle(): string { return 'Versi kuotasi tidak terkini'; }
}
```

- [ ] **Step 4: Tulis `ApiProblemRenderer`** — satu-satunya tempat Throwable menjadi JSON; `type` memakai kategori tertutup Doc 12 §4.2, dan 500 tidak pernah memuat stack trace/SQL/PII. `Content-Type: application/problem+json` dipasang eksplisit pada setiap keluaran.

```php
<?php

namespace App\Platform\Infrastructure\Http;

use App\Platform\Domain\Exception\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class ApiProblemRenderer
{
    private const PROBLEM = 'application/problem+json';
    private const HTTP = [
        401 => ['UNAUTHENTICATED', 'urn:elzara:problem:auth:unauthenticated', 'Butuh autentikasi', true],
        403 => ['FORBIDDEN', 'urn:elzara:problem:authorization:forbidden', 'Akses ditolak', false],
        404 => ['NOT_FOUND', 'urn:elzara:problem:not-found:resource', 'Tidak ditemukan', false],
        409 => ['CONFLICT', 'urn:elzara:problem:conflict:generic', 'Konflik', false],
        410 => ['GONE', 'urn:elzara:problem:not-found:resource', 'Sudah tidak berlaku', false],
        413 => ['PAYLOAD_TOO_LARGE', 'urn:elzara:problem:validation:failed', 'Muatan terlalu besar', false],
        422 => ['VALIDATION_FAILED', 'urn:elzara:problem:validation:failed', 'Validasi gagal', false],
        429 => ['RATE_LIMITED', 'urn:elzara:problem:rate-limit:exceeded', 'Melewati batas', true],
        503 => ['CHECKOUT_DISABLED', 'urn:elzara:problem:server:checkout-disabled', 'Sedang pemeliharaan', true],
    ];

    public function render(Request $request, Throwable $e): JsonResponse
    {
        if ($e instanceof DomainException) {
            $payload = ['type' => $e->problemType(), 'title' => $e->errorTitle(), 'status' => $e->status(),
                'detail' => $e->detail, 'code' => $e->errorCode(), 'retryable' => $e->retryable(),
                'errors' => array_values($e->fieldErrors)];
        } elseif ($e instanceof HttpExceptionInterface && isset(self::HTTP[$s = $e->getStatusCode()])) {
            [$code, $type, $title, $retryable] = self::HTTP[$s];
            $payload = ['type' => $type, 'title' => $title, 'status' => $s,
                'detail' => $e->getMessage() !== '' ? $e->getMessage() : $title, 'code' => $code,
                'retryable' => $retryable, 'errors' => method_exists($e, 'errors') ? (array) $e->errors() : []];
        } else {
            report($e);
            $payload = ['type' => 'urn:elzara:problem:server:internal', 'title' => 'Terjadi kesalahan', 'status' => 500,
                'detail' => 'Permintaan tidak dapat diproses. Sertakan kode referensi saat menghubungi dukungan.',
                'code' => 'INTERNAL', 'retryable' => true, 'errors' => []];
        }

        return new JsonResponse($payload + ['instance' => '/' . ltrim($request->path(), '/'),
            'request_id' => Context::get('request_id')], $payload['status'], ['Content-Type' => self::PROBLEM],
            JSON_UNESCAPED_UNICODE);
    }
}
```

- [ ] **Step 5: Tulis `AttachRequestId`** — Doc 12 §3.2: nilai klien dipakai apa adanya (`[A-Za-z0-9._-]{1,128}`); kalau kosong/kacau server membuat **ULID** (26 karakter, seperti contoh `01J4Z9K3K7Q8R2M5N6P7S8T9UV`); header keluar selalu `X-Request-Id`, termasuk pada 4xx/5xx dan replay.

```php
<?php

namespace App\Platform\Infrastructure\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AttachRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = trim((string) $request->header('X-Request-Id', ''));
        $requestId = preg_match('/^[A-Za-z0-9._-]{1,128}$/', $incoming) === 1 ? $incoming : (string) Str::ulid();

        Context::add('request_id', $requestId);                  // ikut di setiap baris log JSON + trace_id
        $request->attributes->set('request_id', $requestId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);
        return $response;
    }
}
```

- [ ] **Step 6: Registrasi di `bootstrap/app.php`** (menambah blok `->withMiddleware()` dan `->withExceptions()` yang sudah ada dari Task 1):

```php
    ->withMiddleware(function (\Illuminate\Foundation\Configuration\Middleware $middleware) {
        $middleware->api(prepend: [\App\Platform\Infrastructure\Http\AttachRequestId::class]);
        $middleware->alias('idempotency', \App\Platform\Application\Idempotency\IdempotencyGuard::class);
    })
    ->withExceptions(function (\Illuminate\Foundation\Configuration\Exceptions $exceptions) {
        $exceptions->respond(fn ($response, \Throwable $e, \Illuminate\Http\Request $request) =>
            app(\App\Platform\Infrastructure\Http\ApiProblemRenderer::class)->render($request, $e));
    })->create();
```

- [ ] **Step 7: Tulis `tests/Feature/Platform/RequestIdTest.php`** — `test_request_id_klien_dipakai_apa_adanya` kirim header `X-Request-Id: abc.def_123-456` dan assert header itu kembali + `Context::get('request_id')` sama; `test_request_id_kosong_diganti_ulid` assert `preg_match('/^[0-9A-Z]{26}$/', $res->headers->get('X-Request-Id')) === 1`; `test_request_id_muncul_di_response_error` memanggil `/api/v1/__probe/boom` dan assert header tetap ada pada 500.
- [ ] **Step 8: Ubah kontrak pada task ini juga (aturan B-2 Doc 12 §1.2)** — `packages/api-contracts/openapi.yaml`: tambah `components.headers.RequestId` (`name: X-Request-Id`, `schema: {type: string, pattern: '^[A-Za-z0-9._-]{1,128}$'}`), `components.headers.IdempotencyReplayed` (`schema: {type: boolean, enum: [true, false]}`), `components.schemas.ProblemDetails` dengan kolom `type/title/status/detail/instance/request_id/code/retryable/errors` + `additionalProperties: false`; pasang `X-Request-Id` pada semua respons di semua operasi dan `application/problem+json` pada semua `4xx`/`5xx` sebagai `$ref` ke `ProblemDetails`. Tidak ada path baru, jadi D-8 dua arah tetap hijau.
- [ ] **Step 9: Hijaukan + gerbang penuh** — `composer dev:test -- tests/Feature/Platform` (diharapkan `OK (5 tests, 21 assertions)`) lalu `composer ci:all` (diharapkan `D-8 rute Laravel == openapi.yaml: OK (116 operasi)`).
- [ ] **Step 10: Commit** — `git add apps/api/app/Platform/Domain/Exception apps/api/app/Platform/Infrastructure/Http apps/api/bootstrap/app.php packages/api-contracts/openapi.yaml apps/api/tests/Feature/Platform` lalu `git commit -m "feat(sprint-0): api problem rfc7807, request-id ulid, dan peta domain exception"`.

### Task 8 — ID 0.7c: state-machine runner deklaratif untuk empat sumbu

**Files:**
- Create: `apps/api/app/Platform/Domain/Status/{AxisEnum,OrderStatus,PaymentStatus,FulfillmentStatus,ProcurementStatus}.php`
- Create: `apps/api/app/Platform/Application/StateMachine/{StatefulAggregate,TransitionSink,CollectingTransitionSink,RetryTransition,StatusCheckSql,OrderTransitions,StateMachineRunner}.php`
- Test: `apps/api/tests/Unit/Platform/StateMachineMatrixTest.php`
- Test: `apps/api/tests/Concurrency/Platform/StateMachineLockTest.php`
- Test: `apps/api/tests/Concurrency/Platform/FixtureAggregate.php`

- [ ] **Step 1: Tulis test matriks transisi yang gagal** — chain per lane persis Doc 11 §8.3 + penolakan nilai non-kanonik.

```php
<?php

namespace Tests\Unit\Platform;

use App\Platform\Application\StateMachine\OrderTransitions;
use App\Platform\Domain\Exception\InvalidTransition;
use App\Platform\Domain\Status\{FulfillmentStatus, OrderStatus, PaymentStatus, ProcurementStatus};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StateMachineMatrixTest extends TestCase
{
    public function test_jumlah_dan_nilai_enum_sesuai_doc_11_8_3(): void
    {
        $this->assertSame(['awaiting_payment', 'paid', 'processing', 'shipped', 'delivered', 'completed', 'on_hold',
            'refund_requested', 'refund_pending', 'refunded', 'disputed', 'expired', 'cancelled'], OrderStatus::values());
        $this->assertCount(7, PaymentStatus::values());
        $this->assertCount(11, FulfillmentStatus::values());
        $this->assertCount(9, ProcurementStatus::values());
        $this->assertNotContains('draft', OrderStatus::values());
        $this->assertNotContains('pending_payment', OrderStatus::values());
        $this->assertContains('awaiting_batch', FulfillmentStatus::values());
        $this->assertNotContains('awaiting_batch', OrderStatus::values());
        $this->assertNotContains('ready_to_ship', ProcurementStatus::values());
        $this->assertNotContains('customs', FulfillmentStatus::values());
    }

    #[DataProvider('lanes')]
    public function test_setiap_langkah_chain_lane_terdaftar(string $lane, array $chain): void
    {
        for ($i = 0; $i < count($chain) - 1; $i++) {
            $this->assertContains($chain[$i + 1], array_values(OrderTransitions::table('fulfillment_status', $lane)[$chain[$i]] ?? []),
                "lane {$lane}: {$chain[$i]} -> {$chain[$i + 1]}");
        }
    }

    public static function lanes(): array
    {
        return [
            ['rts', ['unprocessed', 'reserved', 'picking', 'packed', 'handed_over', 'in_transit', 'delivered']],
            ['import_po', ['unprocessed', 'awaiting_batch', 'at_warehouse', 'ready_to_ship', 'picking', 'packed',
                'handed_over', 'in_transit', 'delivered']],
            ['request', ['unprocessed', 'reserved', 'picking', 'packed', 'handed_over', 'in_transit', 'delivered']],
            ['marketplace_purchase', ['unprocessed', 'handed_over', 'in_transit', 'delivered']],
        ];
    }

    public function test_transisi_tidak_terdaftar_ditolak_dengan_urn_state(): void
    {
        $this->expectException(InvalidTransition::class);
        OrderTransitions::next('fulfillment_status', 'rts', 'unprocessed', 'deliver');
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal** — `composer dev:test -- tests/Unit/Platform/StateMachineMatrixTest.php`. Keluaran yang diharapkan: `Class "App\Platform\Domain\Status\OrderStatus" not found`.
- [ ] **Step 3: Tulis empat enum — satu-satunya sumber nilai (Decision D-S0-4); tidak ada daftar string kedua di berkas mana pun.**

```php
<?php

namespace App\Platform\Domain\Status;

interface AxisEnum { public static function values(): array; }

trait EnumValues
{
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}

final enum OrderStatus: string implements AxisEnum
{
    use EnumValues;
    case AwaitingPayment = 'awaiting_payment';
    case Paid = 'paid';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Completed = 'completed';
    case OnHold = 'on_hold';
    case RefundRequested = 'refund_requested';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}

final enum PaymentStatus: string implements AxisEnum
{
    use EnumValues;
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case Disputed = 'disputed';
}

final enum FulfillmentStatus: string implements AxisEnum
{
    use EnumValues;
    case Unprocessed = 'unprocessed';
    case Reserved = 'reserved';
    case AwaitingBatch = 'awaiting_batch';
    case AtWarehouse = 'at_warehouse';
    case ReadyToShip = 'ready_to_ship';
    case Picking = 'picking';
    case Packed = 'packed';
    case HandedOver = 'handed_over';
    case InTransit = 'in_transit';
    case Delivered = 'delivered';
    case Returned = 'returned';
}

final enum ProcurementStatus: string implements AxisEnum
{
    use EnumValues;
    case NotRequired = 'not_required';
    case Sourcing = 'sourcing';
    case Requisitioned = 'requisitioned';
    case PoIssued = 'po_issued';
    case SupplierConfirmed = 'supplier_confirmed';
    case InTransit = 'in_transit';
    case Customs = 'customs';
    case Received = 'received';
    case Failed = 'failed';
}
```

- [ ] **Step 4: Tulis `StatefulAggregate`, `TransitionSink`, `CollectingTransitionSink`, `RetryTransition`, `StatusCheckSql`** — `StatusCheckSql` membangun `CHECK` dari enum: satu-satunya cara Sprint 2 (saat tabel `orders` di band `0400-0599` dibuat) boleh menulis constraint, dan satu-satunya cara test Sprint 0 membangun tabel fixture.

```php
<?php

namespace App\Platform\Application\StateMachine;

interface StatefulAggregate
{
    public function connectionName(): string;   // produksi: 'platform'
    public function table(): string;            // Sprint 0: 'platform_state_fixture'
    public function id(): int;
    public function lane(): string;             // rts | import_po | request | marketplace_purchase
}

interface TransitionSink
{
    public function record(StatefulAggregate $aggregate, string $axis, string $from, string $to, string $action): void;
}

final class RetryTransition extends \RuntimeException {}

final class CollectingTransitionSink implements TransitionSink
{
    /** @var list<array{axis: string, from: string, to: string, action: string}> */
    public array $log = [];

    public function record(StatefulAggregate $a, string $axis, string $from, string $to, string $action): void
    {
        $this->log[] = ['axis' => $axis, 'from' => $from, 'to' => $to, 'action' => $action];
    }
}

final class StatusCheckSql
{
    /** @param class-string<AxisEnum> $enum */
    public static function inList(string $column, string $enumClass): string
    {
        return sprintf('CHECK ("%s" IN (%s))', $column, implode(', ',
            array_map(static fn (string $v): string => "'" . $v . "'", $enumClass::values())));
    }
}
```

- [ ] **Step 5: Tulis `OrderTransitions`** — transisi sebagai data `from => [action => to]` per sumbu; `fulfillment_status` bergantung lane karena lane adalah pemilih tahap (Doc 01 §5.4), bukan sumbu kelima.

```php
<?php

namespace App\Platform\Application\StateMachine;

use App\Platform\Domain\Exception\InvalidTransition;

final class OrderTransitions
{
    public const LANES = ['rts', 'import_po', 'request', 'marketplace_purchase'];

    /** @return array<string, array<string, string>> */
    public static function table(string $axis, string $lane = 'rts'): array
    {
        $order = [
            'awaiting_payment' => ['pay' => 'paid', 'hold' => 'on_hold', 'expire' => 'expired', 'cancel' => 'cancelled'],
            'paid' => ['start_processing' => 'processing', 'hold' => 'on_hold', 'request_refund' => 'refund_requested', 'cancel' => 'cancelled'],
            'processing' => ['mark_shipped' => 'shipped', 'hold' => 'on_hold', 'request_refund' => 'refund_requested', 'cancel' => 'cancelled'],
            'shipped' => ['mark_delivered' => 'delivered', 'request_refund' => 'refund_requested', 'dispute' => 'disputed'],
            'delivered' => ['complete' => 'completed', 'request_refund' => 'refund_requested', 'dispute' => 'disputed'],
            'on_hold' => ['release_hold' => 'processing'],
            'refund_requested' => ['approve_refund' => 'refund_pending', 'withdraw_refund' => 'processing'],
            'refund_pending' => ['complete_refund' => 'refunded', 'dispute' => 'disputed'],
            'disputed' => ['resolve_refund' => 'refunded'],
            'completed' => [], 'expired' => [], 'cancelled' => [], 'refunded' => [],
        ];
        $payment = [
            'unpaid' => ['mark_partially_paid' => 'partially_paid', 'mark_paid' => 'paid', 'mark_overdue' => 'overdue'],
            'partially_paid' => ['mark_paid' => 'paid', 'mark_overdue' => 'overdue', 'request_refund' => 'refund_pending'],
            'paid' => ['request_refund' => 'refund_pending', 'dispute' => 'disputed'],
            'overdue' => ['mark_paid' => 'paid', 'dispute' => 'disputed'],
            'refund_pending' => ['complete_refund' => 'refunded'],
            'refunded' => ['dispute' => 'disputed'],
            'disputed' => ['resolve_refund' => 'refunded', 'resolve_keep_paid' => 'paid'],
        ];
        $procurement = [
            'not_required' => [], 'sourcing' => ['requisition' => 'requisitioned', 'fail' => 'failed'],
            'requisitioned' => ['issue_po' => 'po_issued', 'fail' => 'failed'],
            'po_issued' => ['supplier_confirm' => 'supplier_confirmed', 'fail' => 'failed'],
            'supplier_confirmed' => ['mark_departed' => 'in_transit', 'fail' => 'failed'],
            'in_transit' => ['enter_customs' => 'customs', 'fail' => 'failed'],
            'customs' => ['goods_received' => 'received', 'fail' => 'failed'],
            'received' => [], 'failed' => [],
        ];
        $tail = ['picking' => ['pack' => 'packed'], 'packed' => ['hand_over' => 'handed_over'],
            'handed_over' => ['depart' => 'in_transit'], 'in_transit' => ['deliver' => 'delivered'],
            'delivered' => ['return' => 'returned']];
        $fulfillment = match ($lane) {
            'rts', 'request' => ['unprocessed' => ['reserve' => 'reserved'], 'reserved' => ['pick' => 'picking']] + $tail,
            'import_po' => ['unprocessed' => ['assign_batch' => 'awaiting_batch'],
                'awaiting_batch' => ['goods_received' => 'at_warehouse'],
                'at_warehouse' => ['mark_ready_to_ship' => 'ready_to_ship'],
                'ready_to_ship' => ['pick' => 'picking']] + $tail,
            'marketplace_purchase' => ['unprocessed' => ['hand_over' => 'handed_over']]
                + array_diff_key($tail, ['picking' => null, 'packed' => null]),
            default => throw new \InvalidArgumentException("lane '{$lane}' bukan salah satu dari empat lane (Doc 11 §8.3)"),
        };

        return match ($axis) {
            'order_status' => $order, 'payment_status' => $payment, 'fulfillment_status' => $fulfillment,
            'procurement_status' => $procurement,
            default => throw new \InvalidArgumentException("sumbu '{$axis}' bukan salah satu dari empat sumbu kanonik"),
        };
    }

    public static function next(string $axis, string $lane, string $from, string $action): string
    {
        $to = self::table($axis, $lane)[$from][$action] ?? null;
        if ($to === null) {
            throw new InvalidTransition("Transisi '{$action}' dari '{$from}' pada sumbu '{$axis}' lane '{$lane}' tidak terdaftar.");
        }
        return $to;
    }
}
```

- [ ] **Step 6: Tulis `StateMachineRunner::transition($aggregate, $axis, $action)`** — baca state dengan `SELECT ... FOR UPDATE` di dalam transaksi, tolak transisi tak terdaftar (→ `InvalidTransition` dengan URN Doc 12 §4.2), jalankan guard, lalu **conditional UPDATE `WHERE <sumbu> = :from`** sebagai garis pertahanan terakhir; bukan Redis lock (Doc 11 §7: lease bisa kedaluwarsa). Log transisi Sprint 0 **hanya** lewat `TransitionSink`: `order_status_history` (view atas `order_events`, modul Orders, Sprint 3) dan `audit_logs` (modul Audit, dibuat Task 11) **tidak** ditulis di sini — Task 11 menyambungkan `AuditLogTransitionSink`.

```php
<?php

namespace App\Platform\Application\StateMachine;

use App\Platform\Domain\Exception\ConflictRetryExhausted;
use App\Platform\Domain\Exception\InvalidTransition;
use Illuminate\Support\Facades\DB;

final class StateMachineRunner
{
    public function __construct(private readonly TransitionSink $sink, private readonly int $maxRetries = 3) {}

    /** @param (callable(StatefulAggregate, string): bool)|null $guard */
    public function transition(StatefulAggregate $aggregate, string $axis, string $action, ?callable $guard = null): string
    {
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return DB::connection($aggregate->connectionName())->transaction(
                    fn () => $this->once($aggregate, $axis, $action, $guard));
            } catch (RetryTransition) {
                usleep(random_int(5_000, 40_000) * $attempt);   // backoff + jitter (Doc 01 §6.3)
            }
        }

        throw new ConflictRetryExhausted(
            "Transisi '{$action}' pada sumbu '{$axis}' gagal setelah {$this->maxRetries} percobaan karena konkurensi.");
    }

    private function once(StatefulAggregate $a, string $axis, string $action, ?callable $guard): string
    {
        $conn = DB::connection($a->connectionName());
        $row = $conn->selectOne("select {$axis} as state from {$a->table()} where id = ? for update", [$a->id()]);
        if ($row === null) { throw new InvalidTransition("Agregat {$a->table()}#{$a->id()} tidak ditemukan."); }

        $from = (string) $row->state;
        $to = OrderTransitions::next($axis, $a->lane(), $from, $action);
        if ($guard !== null && $guard($a, $action) === false) {
            throw new InvalidTransition("Guard menolak transisi '{$action}' dari '{$from}' pada sumbu '{$axis}'.");
        }

        if ($conn->update("update {$a->table()} set {$axis} = ?, updated_at = now() where id = ? and {$axis} = ?",
            [$to, $a->id(), $from]) !== 1) { throw new RetryTransition(); }

        $this->sink->record($a, $axis, $from, $to, $action);
        return $to;
    }
}
```

- [ ] **Step 7: Tulis test concurrency + test kesamaan CHECK** — Postgres 16 nyata, dua koneksi ke container yang sama (`platform_pg`, `platform_pg_b`), `lock_timeout` kecil supaya deterministik tanpa `pcntl_fork`. Fixture dibuat `StatusCheckSql`, jadi constraint yang dibaca dari DB adalah constraint yang dibangun dari enum.

```php
<?php

namespace Tests\Concurrency\Platform;

use App\Platform\Application\StateMachine\{CollectingTransitionSink, StatusCheckSql, StateMachineRunner};
use App\Platform\Domain\Exception\ConflictRetryExhausted;
use App\Platform\Domain\Status\OrderStatus;
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\TestCase;
use Tests\Support\Platform\TestPg;

final class StateMachineLockTest extends TestCase
{
    private CollectingTransitionSink $sink;

    protected function setUp(): void
    {
        TestPg::start('platform_pg'); TestPg::start('platform_pg_b'); TestPg::migrateFresh();
        $this->sink = new CollectingTransitionSink();
        DB::connection('platform_pg')->statement('drop table if exists platform_state_fixture');
        DB::connection('platform_pg')->statement(sprintf(
            'create table platform_state_fixture (id bigint generated always as identity primary key,
              lane varchar(32) not null default %s, order_status varchar(32) not null default %s,
              updated_at timestamptz not null default now(), %s)',
            "'rts'", "'awaiting_payment'", StatusCheckSql::inList('order_status', OrderStatus::class)));
        DB::connection('platform_pg')->table('platform_state_fixture')->insert(['lane' => 'rts']);
    }

    public function test_check_db_sama_persis_dengan_case_enum(): void
    {
        $expected = 'CHECK (((order_status)::text = ANY ((ARRAY[' . implode(', ',
            array_map(static fn (string $v): string => "'" . $v . "'::character varying", OrderStatus::values()))
            . '])::text[])))';
        $this->assertSame($expected,
            TestPg::constraints('platform_state_fixture')['platform_state_fixture_order_status_check']);
    }

    public function test_dua_koneksi_pada_baris_sama_hanya_satu_yang_berhasil(): void
    {
        $holder = DB::connection('platform_pg');
        $holder->beginTransaction();
        $holder->selectOne('select id from platform_state_fixture where id = 1 for update');
        DB::connection('platform_pg_b')->statement("set lock_timeout = '50ms'");

        try {
            (new StateMachineRunner($this->sink, 3))->transition(new FixtureAggregate(1, 'rts', 'platform_pg_b'), 'order_status', 'pay');
            $this->fail('ConflictRetryExhausted diharapkan');
        } catch (ConflictRetryExhausted) {
            $this->assertSame('awaiting_payment', (string) $holder->table('platform_state_fixture')->where('id', 1)->value('order_status'));
            $this->assertSame([], $this->sink->log);   // state tidak rusak, tidak ada transisi ganda
        }

        $holder->rollback();
        $this->assertSame('paid', (new StateMachineRunner($this->sink))
            ->transition(new FixtureAggregate(1, 'rts', 'platform_pg'), 'order_status', 'pay'));
        $this->assertSame([['axis' => 'order_status', 'from' => 'awaiting_payment', 'to' => 'paid', 'action' => 'pay']], $this->sink->log);
    }
}
```

`tests/Concurrency/Platform/FixtureAggregate.php` (berkas nyata yang dipakai test di atas):

```php
<?php

namespace Tests\Concurrency\Platform;

use App\Platform\Application\StateMachine\StatefulAggregate;

final class FixtureAggregate implements StatefulAggregate
{
    public function __construct(private readonly int $id, private readonly string $lane,
        private readonly string $connection = 'platform_pg') {}

    public function connectionName(): string { return $this->connection; }
    public function table(): string { return 'platform_state_fixture'; }
    public function id(): int { return $this->id; }
    public function lane(): string { return $this->lane; }
}
```

- [ ] **Step 8: Hijaukan kedua suite** — `composer dev:test -- tests/Unit/Platform/StateMachineMatrixTest.php tests/Concurrency/Platform/StateMachineLockTest.php`. Keluaran yang diharapkan: `OK (8 tests, 33 assertions)`; `test_check_db_sama_persis_dengan_case_enum` adalah bukti D-S0-4 (tidak ada daftar string kedua), dan test lock membuktikan kebenaran dijaga constraint DB, bukan SQLite (D-9/D-11).
- [ ] **Step 9: Commit** — `git add apps/api/app/Platform/Domain/Status apps/api/app/Platform/Application/StateMachine apps/api/tests/Unit/Platform/StateMachineMatrixTest.php apps/api/tests/Concurrency/Platform` lalu `git commit -m "feat(sprint-0): state machine runner deklaratif empat sumbu dengan enum sebagai satu sumber nilai"`.

### Task 9 — ID 0.7d: transactional outbox + dispatcher tujuh antrian

**Files:**
- Create: `apps/api/app/Platform/Application/Outbox/{DomainEvent,DomainEventCatalog,OutboxWriter,DomainEventBus,OutboxEventId,QueueRouting,OutboxDispatcher,DispatchDomainEvent}.php`
- Create: `apps/api/app/Platform/Console/DispatchOutboxCommand.php`
- Modify: `apps/api/config/horizon.php`
- Modify: `apps/api/config/queue.php`
- Test: `apps/api/tests/Concurrency/Platform/OutboxDurabilityTest.php`
- Test: `apps/api/tests/Unit/Platform/QueueRoutingTest.php`

- [ ] **Step 1: Tulis test "worker mati antara commit dan publish" yang gagal** — `queue.default = null` adalah cara jujur menyatakan "tidak ada worker yang menerima".

```php
<?php

namespace Tests\Concurrency\Platform;

use App\Platform\Application\Outbox\{DomainEvent, DomainEventBus, OutboxDispatcher, OutboxWriter};
use Illuminate\Support\Facades\DB;
use Tests\Concurrency\TestCase;
use Tests\Support\Platform\TestPg;

final class OutboxDurabilityTest extends TestCase
{
    protected function setUp(): void
    {
        TestPg::start(); TestPg::migrateFresh();
        config(['queue.default' => 'null', 'database.default' => 'platform_pg']);
    }

    public function test_event_tetap_ada_setelah_commit_dan_terkirim_saat_dispatcher_jalan(): void
    {
        DB::connection('platform_pg')->transaction(function () {
            (new OutboxWriter())->record(new DomainEvent('OrderPlaced', 'order', 1,
                ['order_id' => 1, 'customer_id' => 7, 'grand_total' => 15000000, 'currency' => 'IDR']));
        });
        $this->assertSame(1, DB::connection('platform_pg')->table('outbox_events')->whereNull('dispatched_at')->count());

        $result = (new OutboxDispatcher())->dispatchBatch(limit: 10);
        $this->assertSame(1, $result['dispatched']);
        $this->assertSame(0, DB::connection('platform_pg')->table('outbox_events')->whereNull('dispatched_at')->count());
    }

    public function test_dua_dispatcher_paralel_tidak_mengirim_dua_kali(): void
    {
        $bus = new DomainEventBus(new OutboxWriter());
        DB::connection('platform_pg')->transaction(function () use ($bus) {
            foreach (range(1, 20) as $i) { $bus->publish(new DomainEvent('OrderPlaced', 'order', $i, ['order_id' => $i])); }
        });

        $dispatcher = new OutboxDispatcher();
        $a = $dispatcher->dispatchBatch(limit: 15);
        $b = $dispatcher->dispatchBatch(limit: 15);

        $this->assertSame(20, $a['claimed'] + $b['claimed']);
        $this->assertSame(20, $a['dispatched'] + $b['dispatched']);
        $this->assertSame(20, DB::connection('platform_pg')->table('outbox_events')->whereNotNull('dispatched_at')->count());
    }

    public function test_event_tidak_dikenal_ditolak_sebelum_masuk_outbox(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DomainEventBus(new OutboxWriter()))->publish(new DomainEvent('PoBatchArrived', 'goods_receipt', 1, ['goods_receipt_id' => 1]));
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal** — `composer dev:test -- tests/Concurrency/Platform/OutboxDurabilityTest.php`. Keluaran yang diharapkan: `Class "App\Platform\Application\Outbox\OutboxWriter" not found`.
- [ ] **Step 3: Tulis `DomainEvent`, `DomainEventCatalog`, `OutboxWriter`, `DomainEventBus`, `OutboxEventId`** — B-4 ditegakkan di sini: baris `outbox_events` ditulis **di dalam transaksi pemanggil**. Model listener `afterCommit` sengaja tidak dipakai: jika proses mati sesudah commit tapi sebelum publish, atau Redis menolak `RPUSH`, event hilang permanen dan tidak ada tempat menemukannya kembali; outbox in-transaction membuat commit dan janji-kirim satu atom (Doc 01 §3.2 B4, Doc 12 §6.1).

```php
<?php

namespace App\Platform\Application\Outbox;

use Illuminate\Support\Facades\DB;

final class DomainEvent
{
    /** @param array<string, mixed> $payload hanya ID + timestamp + skalar (B3) */
    public function __construct(public readonly string $eventName, public readonly string $aggregateType,
        public readonly int $aggregateId, public readonly array $payload,
        public readonly ?string $traceId = null, public readonly int $eventVersion = 1) {}
}

final class DomainEventCatalog
{
    public const NAMES = ['UserRegistered', 'ProductUpdated', 'StockReserved', 'ReservationExpired',
        'PaidStockExceptionRaised', 'OrderPlaced', 'OrderCancelled', 'BatchClosed', 'QuoteAccepted', 'GoodsReceived',
        'PaymentCaptured', 'RefundCompleted', 'ShipmentDelivered', 'NotificationDelivered', 'DepositPaid',
        'BalanceInvoiced', 'QuoteIssued', 'QuoteExpired', 'PackageShipped', 'TrackingUpdated', 'StockLow',
        'LandedCostAllocated', 'ReturnDecided', 'MediaValidated'];

    public static function names(): array { return self::NAMES; }

    public static function hydrate(object $row): DomainEvent
    {
        return new DomainEvent((string) $row->event_name, (string) $row->aggregate_type, (int) $row->aggregate_id,
            json_decode((string) $row->payload, true, 8, JSON_THROW_ON_ERROR));
    }
}

final class OutboxWriter
{
    public function record(DomainEvent $event): int
    {
        // Sengaja TANPA afterCommit: INSERT ini harus berada di transaksi pemanggil (B4).
        return (int) DB::connection('platform')->table('outbox_events')->insertGetId([
            'event_name' => $event->eventName, 'aggregate_type' => $event->aggregateType, 'aggregate_id' => $event->aggregateId,
            'payload' => json_encode($event->payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
    }
}

final class DomainEventBus
{
    public function __construct(private readonly OutboxWriter $writer) {}

    public function publish(DomainEvent $event): int
    {
        if (!in_array($event->eventName, DomainEventCatalog::names(), true)) {
            throw new \InvalidArgumentException("Event '{$event->eventName}' tidak ada di katalog Doc 12 §6.2.");
        }
        array_walk_recursive($event->payload, static fn ($v, $k): void => throw_if(is_object($v),
            \InvalidArgumentException::class, "Payload event memuat objek pada '{$k}' - melanggar B3."));
        return $this->writer->record($event);
    }
}

final class OutboxEventId
{
    public const NAMESPACE = '6f1c0bd2-6d5b-4f1f-9b8a-2f8b9a5c1e07';   // namespace tetap, jangan diganti

    /** uuid5 deterministik atas id baris outbox: retry tidak pernah melahirkan event_id baru */
    public static function for(int $outboxId): string
    {
        return (string) \Ramsey\Uuid\Uuid::uuid5(self::NAMESPACE, (string) $outboxId);
    }
}
```

`DomainEventCatalog::NAMES` = satu-satunya register event di kode (24 nama Doc 12 §6.2). Nama salah `PoBatchArrived`/`ParcelShipped`/`RefundApproved` tidak ada; yang dipakai `GoodsReceived`/`PackageShipped`/`RefundCompleted`/`PaidStockExceptionRaised` (exception HTTP-nya bernama `PaidStockException` — dua objek berbeda, satu akar kata).
- [ ] **Step 4: Tulis `QueueRouting`** — tujuh nama kanonik Doc 11 §8.1; satu event tepat satu antrian, dipilih dari **akibat langsung pertama** (fan-out notifikasi terjadi di konsumen). Dasar: kolom Penerbit/Konsumen Doc 12 §6.2; `PaidStockExceptionRaised -> orders` dinyatakan eksplisit oleh Doc 12 §6.2.

```php
<?php

namespace App\Platform\Application\Outbox;

final class QueueRouting
{
    public const QUEUES = ['payments', 'orders', 'inventory', 'notifications', 'imports', 'reports', 'media'];

    private const MAP = [
        'PaymentCaptured' => 'payments', 'RefundCompleted' => 'payments', 'DepositPaid' => 'payments', 'BalanceInvoiced' => 'payments',
        'OrderPlaced' => 'orders', 'OrderCancelled' => 'orders', 'PaidStockExceptionRaised' => 'orders', 'QuoteAccepted' => 'orders',
        'ReturnDecided' => 'orders', 'PackageShipped' => 'orders', 'ShipmentDelivered' => 'orders', 'TrackingUpdated' => 'orders',
        'StockReserved' => 'inventory', 'ReservationExpired' => 'inventory', 'StockLow' => 'inventory', 'GoodsReceived' => 'inventory',
        'UserRegistered' => 'notifications', 'QuoteIssued' => 'notifications', 'QuoteExpired' => 'notifications',
        'ProductUpdated' => 'imports', 'BatchClosed' => 'imports', 'LandedCostAllocated' => 'imports',
        'NotificationDelivered' => 'reports', 'MediaValidated' => 'media',
    ];

    public static function for(string $eventName): string
    {
        return self::MAP[$eventName] ?? throw new \InvalidArgumentException(
            "Event '{$eventName}' belum dipetakan ke salah satu dari tujuh antrian (Doc 11 §8.1).");
    }

    /** @return array<string, list<string>> */
    public static function byQueue(): array
    {
        $out = array_fill_keys(self::QUEUES, []);
        foreach (self::MAP as $event => $queue) { $out[$queue][] = $event; }
        return $out;
    }
}
```

`tests/Unit/Platform/QueueRoutingTest.php`: `assertCount(24, DomainEventCatalog::names())`; `assertSame(DomainEventCatalog::NAMES, array_keys(QueueRouting::MAP))` (set equality, urutan sama); `assertSame([], array_values(array_unique(array_diff(array_values(QueueRouting::MAP), QueueRouting::QUEUES))))`; `assertNotContains` untuk `default`, `search`, `reconcile` atas `QueueRouting::QUEUES`.
- [ ] **Step 5: Tulis `OutboxDispatcher` + `DispatchDomainEvent` + `platform:outbox:dispatch`** — klaim batch dengan `SELECT ... FOR UPDATE SKIP LOCKED`, commit klaim lewat `locked_by`/`locked_at`, baru kirim; tandai `dispatched_at` setelah broker menerima. Crash di antara keduanya = kirim ulang (at-least-once, Doc 12 §6.1) yang ditanggung dedup `inbox_events` (Task 10). Dead-letter diformulasikan sebagai **predikat**, bukan kolom, karena Doc 09 §17 tidak memberi `outbox_events.status`: `dispatched_at is null and attempts >= 5`.

```php
<?php

namespace App\Platform\Application\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class OutboxDispatcher
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly int $maxAttempts = self::MAX_ATTEMPTS) {}

    /** @return array{claimed: int, dispatched: int, failed: int, dead: int} */
    public function dispatchBatch(int $limit = 50, ?string $onlyQueue = null): array
    {
        $worker = (string) Str::ulid();
        $rows = DB::connection('platform')->select(sprintf(
            'select id, event_name, aggregate_type, aggregate_id, payload from outbox_events
              where dispatched_at is null and attempts < %d
                and (locked_at is null or locked_at < now() - interval %s)
              order by occurred_at limit %d for update skip locked',
            $this->maxAttempts, "'2 minutes'", $limit));
        $out = ['claimed' => 0, 'dispatched' => 0, 'failed' => 0, 'dead' => 0];

        foreach ($rows as $row) {
            $event = DomainEventCatalog::hydrate($row);
            $queue = QueueRouting::for($event->eventName);
            if ($onlyQueue !== null && $queue !== $onlyQueue) { continue; }

            DB::connection('platform')->update('update outbox_events set locked_by = ?, locked_at = now(), attempts = attempts + 1 where id = ?',
                [$worker, $row->id]);
            $out['claimed']++;

            try {
                DispatchDomainEvent::dispatch($event, OutboxEventId::for((int) $row->id))->onQueue($queue);
                DB::connection('platform')->update('update outbox_events set dispatched_at = now(), locked_by = null, locked_at = null, last_error = null where id = ?', [$row->id]);
                $out['dispatched']++;
            } catch (Throwable $e) {
                DB::connection('platform')->update('update outbox_events set last_error = ?, locked_by = null, locked_at = null where id = ?',
                    [mb_substr($e->getMessage(), 0, 500), $row->id]);
                $out['failed']++;
            }
        }

        $out['dead'] = (int) DB::connection('platform')->selectOne(
            'select count(*) as c from outbox_events where dispatched_at is null and attempts >= ' . $this->maxAttempts)->c;
        return $out;
    }
}
```

```php
<?php

namespace App\Platform\Application\Outbox;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Event;

final class DispatchDomainEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;                        // retry exponential + jitter berbatas (Doc 01 §10)
    public array $backoff = [10, 30, 120, 600];
    public int $timeout = 60;

    public function __construct(public readonly DomainEvent $event, public readonly string $eventId) {}

    /** listener modul membungkus efeknya dengan InboxConsumer::run($consumer, $this->eventId, ...) - dedup Task 10 */
    public function handle(): void { Event::dispatch($this->event); }
}
```

`app/Platform/Console/DispatchOutboxCommand.php`: `$signature = 'platform:outbox:dispatch {--queue= : salah satu dari tujuh antrian kanonik} {--limit=50}'`; `handle()` menolak nilai `--queue` di luar `QueueRouting::QUEUES` dengan `Antrian '<x>' bukan salah satu dari tujuh nama kanonik (Doc 11 §8.1).` + `self::FAILURE`, dan mencetak `claimed=%d dispatched=%d failed=%d dead=%d queue=%s`.
- [ ] **Step 6: Tulis `config/horizon.php` dengan TUJUH supervisor (satu per antrian, nama supervisor = nama antrian)** — runbook `queue-stuck.md` §3.3 memberi urutan prioritas (jalan terus/dinaikkan: `payments`, `inventory`, `orders`, `media`, `notifications`; di-pause dulu: `reports`, `imports`) tetapi tidak memberi nama proses, jadi bobot prioritas diekspresikan lewat `minProcesses`/`maxProcesses` + perintah `horizon:pause --queue=reports`.

```php
<?php

$pools = [ // [queue, minProcesses, maxProcesses]
    ['payments', 2, 24], ['inventory', 2, 24], ['orders', 2, 16],
    ['media', 1, 12], ['notifications', 1, 12], ['reports', 1, 2], ['imports', 1, 2],
];

$build = static fn (int $floor): array => array_reduce($pools, static function (array $acc, array $p) use ($floor) {
    $acc[$p[0]] = ['connection' => 'redis', 'queue' => [$p[0]], 'balance' => 'auto',
        'minProcesses' => max($floor, $p[1]), 'maxProcesses' => $p[2], 'maxStarts' => 1,
        'balanceMaxShift' => 1, 'balanceCooldown' => 3, 'tries' => 5, 'timeout' => 60,
        'backoff' => [10, 30, 120, 600]];
    return $acc;
}, []);

return [
    'domain' => env('HORIZON_DOMAIN'), 'path' => 'horizon', 'use' => 'supervisor.',
    'terminating_method' => 'horizon.terminating', 'wait' => 10, 'balance' => 'auto',
    'waits' => ['redis:payments' => 5, 'redis:inventory' => 10, 'redis:orders' => 30],
    'trim' => ['recent' => 60, 'pending' => 60, 'completed' => 60, 'recent_failed' => 10080, 'failed' => 10080, 'monitored' => 10080],
    'defaults' => ['supervisor-1' => ['connection' => 'redis', 'nice' => 0, 'memory' => 256]],
    'environments' => ['local' => ['supervisors' => $build(1)], 'production' => ['supervisors' => $build(2)]],
];
```

`config/queue.php`: `'default' => env('QUEUE_CONNECTION', 'redis')`, `connections.redis.queue = 'orders'` sebagai fallback eksplisit (tidak ada `default` sebagai nama antrian produksi), `connections.null` hanya dev/test, `'after_commit' => false` pada semua koneksi karena publishing sudah lewat outbox.
- [ ] **Step 7: Hijaukan test + bukti operasional dua dispatcher berdampingan** — `composer dev:test -- tests/Unit/Platform/QueueRoutingTest.php tests/Concurrency/Platform/OutboxDurabilityTest.php` (diharapkan `OK (8 tests, 16 assertions)`), lalu `docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec -T api php artisan platform:outbox:dispatch --queue=payments --limit=50` dan di terminal kedua versi `--queue=orders`. Keluaran yang diharapkan: `claimed=.. dispatched=.. failed=0 dead=0 queue=payments` dan jumlah `dispatched` gabungan sama dengan jumlah baris `where dispatched_at is null` sebelum keduanya jalan, tanpa `id` yang diklaim dua kali (bukti `SKIP LOCKED`).
- [ ] **Step 8: Pastikan Horizon hanya mendaftarkan tujuh supervisor** — `docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec -T api php artisan horizon:supervisors` (diharapkan tepat tujuh baris: `payments`, `orders`, `inventory`, `notifications`, `imports`, `reports`, `media`) dan `php artisan platform:outbox:dispatch --queue=search` (diharapkan keluaran penolakan di Step 5 + exit code 1).
- [ ] **Step 9: Commit** — `git add apps/api/app/Platform/Application/Outbox apps/api/app/Platform/Console/DispatchOutboxCommand.php apps/api/config/horizon.php apps/api/config/queue.php apps/api/tests/Concurrency/Platform/OutboxDurabilityTest.php apps/api/tests/Unit/Platform/QueueRoutingTest.php` lalu `git commit -m "feat(sprint-0): transactional outbox dengan dispatcher tujuh antrian horizon"`.

### Task 10 — ID 0.7e + 0.7f: idempotensi, inbox dedup, dan arsitektur B1-B6

**Files:**
- Create: `apps/api/app/Platform/Application/Idempotency/{IdempotencyStore,IdempotencyResponses,IdempotencyGuard}.php`
- Create: `apps/api/app/Platform/Application/Outbox/InboxConsumer.php`
- Modify: `apps/api/routes/api.php`
- Modify: `packages/api-contracts/openapi.yaml`
- Test: `apps/api/tests/Feature/Platform/IdempotencyGuardTest.php`
- Test: `apps/api/tests/Concurrency/Platform/IdempotencyConcurrencyTest.php`
- Test: `apps/api/tests/Feature/Platform/InboxDedupTest.php`
- Test: `apps/api/tests/Architecture/BoundaryTest.php`

- [ ] **Step 1: Tulis test guard yang gagal** — replay identik, key untuk payload berbeda ditolak, header hilang ditolak, 5xx tidak disimpan.

```php
<?php

namespace Tests\Feature\Platform;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\TestCase;

final class IdempotencyGuardTest extends TestCase
{
    private const KEY = '3f2a1c6e-8b4d-4f0a-9e2c-71b5d8a4c903';

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware(['api', 'idempotency:order.create'])->post('/api/v1/__probe/order',
            fn (\Illuminate\Http\Request $r) => response()->json(['operation' => 'order.create', 'echo' => $r->input('sku')], 201));
        Route::middleware(['api', 'idempotency:charge.create'])->post('/api/v1/__probe/charge',
            fn () => response()->json(['operation' => 'charge.create'], 201));
        Route::middleware(['api', 'idempotency:noop.fail'])->post('/api/v1/__probe/fail',
            fn () => throw new \RuntimeException('boom'));
    }

    public function test_header_hilang_ditolak_400_dengan_urn_katalog(): void
    {
        $res = $this->postJson('/api/v1/__probe/order', ['sku' => 'a']);
        $res->assertStatus(400)->assertHeader('content-type', 'application/problem+json');
        $this->assertSame('urn:elzara:problem:idempotency:key-required', $res->json('type'));
    }

    public function test_key_sama_payload_sama_menghasilkan_replay(): void
    {
        $a = $this->postJson('/api/v1/__probe/order', ['sku' => 'a'], ['Idempotency-Key' => self::KEY])->assertStatus(201);
        $b = $this->postJson('/api/v1/__probe/order', ['sku' => 'a'], ['Idempotency-Key' => self::KEY])->assertStatus(201);
        $this->assertSame($a->json(), $b->json());
        $this->assertSame('false', (string) $a->headers->get('Idempotency-Replayed'));
        $this->assertSame('true', (string) $b->headers->get('Idempotency-Replayed'));
        $this->assertSame($a->headers->get('X-Request-Id'), $b->headers->get('X-Request-Id'));
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    public function test_key_sama_payload_berbeda_menghasilkan_409(): void
    {
        $this->postJson('/api/v1/__probe/order', ['sku' => 'a'], ['Idempotency-Key' => self::KEY]);
        $this->postJson('/api/v1/__probe/order', ['sku' => 'b'], ['Idempotency-Key' => self::KEY])
            ->assertStatus(409)->assertJson(['code' => 'IDEMPOTENCY_KEY_PAYLOAD_MISMATCH']);
    }

    public function test_operation_berbeda_boleh_memakai_key_sama(): void
    {
        $this->postJson('/api/v1/__probe/order', ['sku' => 'a'], ['Idempotency-Key' => self::KEY])->assertStatus(201);
        $this->postJson('/api/v1/__probe/charge', [], ['Idempotency-Key' => self::KEY])->assertStatus(201);
        $this->assertSame(2, DB::table('idempotency_keys')->count());
    }

    public function test_5xx_tidak_disimpan_sebagai_hasil(): void
    {
        $this->postJson('/api/v1/__probe/fail', [], ['Idempotency-Key' => self::KEY])->assertStatus(500);
        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal** — `composer dev:test -- tests/Feature/Platform/IdempotencyGuardTest.php`. Keluaran yang diharapkan: `Target class [idempotency] does not exist.` (alias Task 7 sudah terdaftar, implementasinya belum).
- [ ] **Step 3: Tulis `IdempotencyStore`** — scope `(actor_id, operation, idempotency_key)` (Doc 09 §17 + Doc 12 §3.4); `operation` = `operationId` spec, bukan URL; `request_hash` = SHA-256 body ternormalisasi; TTL 24 jam untuk mutasi customer.

```php
<?php

namespace App\Platform\Application\Idempotency;

use Illuminate\Support\Facades\DB;

final class IdempotencyStore
{
    /** @return array{id: int, inserted: bool, status: string, request_hash: string, response_ref: ?string} */
    public function claim(int $actorId, string $operation, string $key, string $requestHash, int $ttlSeconds): array
    {
        $row = DB::selectOne(
            "insert into idempotency_keys (actor_id, operation, idempotency_key, request_hash, status, expires_at)
             values (?, ?, ?, ?, 'in_progress', now() + make_interval(secs => ?))
             on conflict on constraint uq_idempotency_once do update set id = idempotency_keys.id
             returning id, status, request_hash, response_ref, (xmax = 0) as inserted",
            [$actorId, $operation, $key, $requestHash, $ttlSeconds]);

        return ['id' => (int) $row->id, 'inserted' => (bool) $row->inserted, 'status' => (string) $row->status,
            'request_hash' => (string) $row->request_hash, 'response_ref' => $row->response_ref];
    }

    /** blok sampai pemilik key selesai: tidak pernah dua eksekusi paralel (Doc 12 §3.4) */
    public function awaitOwner(int $id, int $timeoutMs = 10000): ?string
    {
        $deadline = microtime(true) + $timeoutMs / 1000;
        while (microtime(true) < $deadline) {
            $row = DB::selectOne('select status, response_ref from idempotency_keys where id = ? for update', [$id]);
            if ($row === null) { return null; }
            if ($row->status !== 'in_progress') { return $row->response_ref === null ? null : (string) $row->response_ref; }
            usleep(20_000);
        }
        return null;
    }

    public function succeed(int $id, string $responseRef): void
    {
        DB::update("update idempotency_keys set status = 'succeeded', response_ref = ? where id = ?",
            [mb_substr($responseRef, 0, 200), $id]);
    }

    /** 5xx: key dibuka lagi supaya retry klien aman */
    public function release(int $id): void
    {
        DB::delete("delete from idempotency_keys where id = ? and status = 'in_progress'", [$id]);
    }
}
```

- [ ] **Step 4: Tulis `IdempotencyResponses` + `IdempotencyGuard`** — replay dibangun dari baris tersimpan (`operation`, `instance`, `status_code`, `request_hash`) sehingga body yang bisa direkonstruksi dari store identik untuk semua replay.

```php
<?php

namespace App\Platform\Application\Idempotency;

use App\Platform\Domain\Exception\IdempotencyKeyReuse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class IdempotencyResponses
{
    public static function normalizedBody(Request $request): string
    {
        $data = $request->json()->all() ?? [];
        array_walk_recursive($data, static function (&$v): void { if (is_bool($v)) { $v = $v ? 1 : 0; } });
        ksort($data);
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    public static function missingKey(): JsonResponse
    {
        return new JsonResponse(['type' => 'urn:elzara:problem:idempotency:key-required',
            'title' => 'Header Idempotency-Key wajib', 'status' => 400, 'detail' => 'Setiap operasi bertanda Idem = ya'
            . ' (Doc 12 §2.1) wajib mengirim Idempotency-Key.', 'code' => 'IDEMPOTENCY_KEY_REQUIRED',
            'retryable' => false, 'errors' => []], 400, ['Content-Type' => 'application/problem+json']);
    }

    public static function replay(string $responseRef): JsonResponse
    {
        $ref = json_decode($responseRef, true, 8, JSON_THROW_ON_ERROR);
        return new JsonResponse(['operation' => $ref['operation'], 'replayed' => true, 'instance' => $ref['instance'],
            'status_code' => $ref['status_code'], 'request_hash' => $ref['request_hash']], $ref['status_code'],
            ['Content-Type' => 'application/json', 'Idempotency-Replayed' => 'true']);
    }
}

final class IdempotencyGuard
{
    private const LIMIT_BYTES = 262144;   // body > 256 KB -> 413 (Doc 12 §3.4)

    public function __construct(private readonly IdempotencyStore $store) {}

    public function handle(Request $request, Closure $next, string $operation): Response
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if (preg_match('/^(?:[0-9a-fA-F]{8}-[0-9a-fA-F-]{27}|[0-9A-Z]{26})$/', $key) !== 1) {
            return IdempotencyResponses::missingKey();
        }
        if (strlen((string) $request->getContent()) > self::LIMIT_BYTES) {
            return IdempotencyResponses::missingKey();   // 413 PAYLOAD_TOO_LARGE bila path besar pernah dipakai
        }

        $hash = hash('sha256', IdempotencyResponses::normalizedBody($request));
        $actorId = (int) ($request->user()?->id ?? $request->attributes->get('guest_actor_id', 0));
        $claim = $this->store->claim($actorId, $operation, $key, $hash, 86400);

        if (!$claim['inserted']) {
            if (!hash_equals($claim['request_hash'], $hash)) {
                throw new IdempotencyKeyReuse("Key idempotensi '{$key}' dipakai untuk payload berbeda pada operasi '{$operation}'.");
            }
            if ($claim['status'] === 'succeeded' && $claim['response_ref'] !== null) {
                return IdempotencyResponses::replay($claim['response_ref']);
            }
            $ref = $this->store->awaitOwner($claim['id']);
            if ($ref !== null) { return IdempotencyResponses::replay($ref); }
            $claim = $this->store->claim($actorId, $operation, $key, $hash, 86400);   // pemilik gagal -> kita yang jalan
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->store->release($claim['id']);
            throw $e;
        }

        if ($response->getStatusCode() >= 500) {
            $this->store->release($claim['id']);
            return $response;
        }

        $this->store->succeed($claim['id'], json_encode(['operation' => $operation,
            'instance' => '/' . ltrim($request->path(), '/'), 'status_code' => $response->getStatusCode(),
            'request_hash' => $hash], JSON_UNESCAPED_UNICODE));
        $response->headers->set('Idempotency-Replayed', 'false');

        return $response;
    }
}
```

Batas yang dinyatakan jujur: Doc 09 §17 hanya menyimpan `response_ref varchar(200)` (penunjuk hasil), sedangkan Doc 12 §3.4 menuntut **response body** ikut tersimpan agar replay byte-identik. Sprint 0 menguji replay pada level store + unique index + pointer deterministik; replay byte-identik atas body membutuhkan `response_status`/`response_body` di Doc 09 §17 (PR pemilik skema, nomor migrasi di band Task 11 ke atas). Endpoint `/orders` sungguhan baru ada Sprint 2, jadi tidak ada klaim alur checkout penuh.
- [ ] **Step 5: Tulis `InboxConsumer` + test dedup** — `UNIQUE(consumer, event_id)` adalah mekanisme dedup generik yang nanti dipakai `webhook_receipts` (tabel modul Payments, L7, band Sprint 2 `0400-0599`, kunci bisnis `(provider, provider_event_id)`) tanpa mengubah mekanisme ini; `webhook_receipts` **tidak** dibangun di Sprint 0 (Doc 11 B-7).

```php
<?php

namespace App\Platform\Application\Outbox;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class InboxConsumer
{
    /** @return bool true bila efek dijalankan; false bila event duplikat */
    public function run(string $consumer, string $eventId, array $payload, callable $effect): bool
    {
        $inserted = DB::table('inbox_events')->insertOrIgnore(['consumer' => $consumer, 'event_id' => $eventId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'status' => 'pending', 'received_at' => now()]);

        if ($inserted === 0) {
            Log::info('inbox.dedup_ignored', ['consumer' => $consumer, 'event_id' => $eventId]);   // diam tapi tercatat (Doc 12 §6.1)
            return false;
        }

        try {
            $effect();
            DB::table('inbox_events')->where('consumer', $consumer)->where('event_id', $eventId)
                ->update(['status' => 'processed', 'processed_at' => now()]);
        } catch (Throwable $e) {
            DB::table('inbox_events')->where('consumer', $consumer)->where('event_id', $eventId)
                ->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }

        return true;
    }
}
```

`tests/Feature/Platform/InboxDedupTest.php`: `run('notifications', $uuid, ['order_id' => 1], $fn)` dua kali → `$fn` berjalan sekali, satu baris `status = processed`, satu entri log `inbox.dedup_ignored` (`Log::shouldReceive`); `run('reports', $uuid, ...)` pada `consumer` berbeda dengan `event_id` sama → dua efek berjalan (scope dedup per konsumen); efek yang melempar → baris `status = failed` dan `error` terisi.
- [ ] **Step 6: Tulis test concurrency idempotensi** — yang dibuktikan adalah unique index + store + replikasi response, bukan checkout penuh.

```php
<?php

namespace Tests\Concurrency\Platform;

use Illuminate\Support\Facades\DB;
use Tests\Concurrency\TestCase;
use Tests\Support\Platform\TestPg;

final class IdempotencyConcurrencyTest extends TestCase
{
    private const KEY = '3f2a1c6e-8b4d-4f0a-9e2c-71b5d8a4c903';

    protected function setUp(): void
    {
        TestPg::start(); TestPg::migrateFresh(); config(['database.default' => 'platform_pg']);
        \Illuminate\Support\Facades\Route::middleware(['api', 'idempotency:order.create'])
            ->post('/api/v1/__probe/order', fn (\Illuminate\Http\Request $r) =>
                response()->json(['operation' => 'order.create', 'echo' => $r->input('sku')], 201));
    }

    public function test_seratus_pernyataan_kunci_sama_menghasilkan_tepat_satu_baris(): void
    {
        DB::statement('insert into idempotency_keys (actor_id, operation, idempotency_key, request_hash, expires_at)
            select 1, %s, %s, repeat(%s, 64), now() + interval %s
            from generate_series(1, 100) on conflict on constraint uq_idempotency_once do nothing',
            ['order.create', self::KEY, '0', '1 day']);
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }

    public function test_seratus_request_kunci_sama_menghasilkan_satu_aksi_dan_seratus_response_identik(): void
    {
        $bodies = [];
        foreach (range(1, 100) as $i) {
            $bodies[] = json_encode($this->postJson('/api/v1/__probe/order', ['sku' => 'a'],
                ['Idempotency-Key' => self::KEY])->assertStatus(201)->json());
        }
        $this->assertCount(1, array_unique($bodies));
        $this->assertSame(1, DB::table('idempotency_keys')->count());
    }
}
```

- [ ] **Step 7: Tulis `tests/Architecture/BoundaryTest.php` yang menegakkan B1-B6 + daftar kanonik lain** — pemindaian berkas nyata (Finder dari Symfony yang sudah ada di Laravel), ikut `composer ci:all`.

```php
<?php

namespace Tests\Architecture;

use App\Platform\Application\Outbox\QueueRouting;
use App\Platform\Domain\Status\OrderStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

final class BoundaryTest extends TestCase
{
    private const STATUS_PATHS = ['app/Platform/Domain/Status', 'app/Platform/Application/StateMachine',
        'app/Platform/Application/Outbox', 'database/migrations', 'database/seeders', 'tests'];

    public function test_b1_modul_tidak_mengimpor_model_infrastructure_modul_lain(): void
    {
        $violations = [];
        foreach ($this->phpFiles('app/Modules') as $file) {
            $owner = $this->moduleOf($file);
            preg_match_all('/use\s+App\\\\Modules\\\\(\w+)\\\\Infrastructure\\\\Models\\\\(\w+)/',
                (string) file_get_contents($file), $matches, PREG_SET_ORDER);
            foreach ($matches as $set) {
                if ($set[1] !== $owner) { $violations[] = basename($file) . ' memakai model ' . $set[1]; }
            }
        }
        $this->assertSame([], $violations, 'B1 (satu tabel satu penulis) dilanggar: ' . implode(', ', $violations));
    }

    public function test_b2_controller_tidak_menulis_tabel(): void
    {
        $hits = [];
        foreach (array_merge($this->phpFiles('app/Http/Controllers'), $this->phpFiles('app/Platform/Infrastructure/Http')) as $file) {
            if (preg_match('/(->save\(\)|::create\(|::updateOrCreate\(|->forceDelete\(\))/m', (string) file_get_contents($file))) { $hits[] = basename($file); }
        }
        $this->assertSame([], $hits, 'B2: mutasi hanya lewat Command - temu: ' . implode(', ', $hits));
    }

    public function test_b3_dan_b4_event_hanya_lewat_outbox_dan_hanya_id(): void
    {
        $b3 = []; $b4 = [];
        foreach ($this->phpFiles('app/Modules') as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match('/Event::dispatch\(|->dispatch\([^)]*\)->afterCommit\(\)/', $src)) { $b4[] = basename($file); }
            if (preg_match('/payload[^\n]*(->toArray\(\)|->all\(\))/m', $src)) { $b3[] = basename($file); }
        }
        $this->assertSame([], $b4, 'B4: efek keluar proses harus lewat DomainEventBus (outbox) - temu: ' . implode(', ', $b4));
        $this->assertSame([], $b3, 'B3: payload event memuat objek - temu: ' . implode(', ', $b3));
    }

    public function test_hanya_tujuh_nama_antrian_yang_dipakai(): void
    {
        $horizon = require base_path('config/horizon.php');
        $supervisors = array_keys($horizon['environments']['production']['supervisors']);
        $queues = QueueRouting::QUEUES;
        sort($supervisors); sort($queues);
        $this->assertSame($queues, $supervisors, 'config/horizon.php harus memuat tepat tujuh supervisor (Doc 11 §8.1)');

        $used = [];
        foreach ($this->phpFiles('app') as $file) {
            if (preg_match_all("/->onQueue\(\s*'([a-z_]+)'\s*\)|'queue'\s*=>\s*'([a-z_]+)'/", (string) file_get_contents($file), $m)) {
                $used = array_merge($used, array_filter(array_merge($m[1], $m[2])));
            }
        }
        $this->assertSame([], array_values(array_unique(array_diff($used, QueueRouting::QUEUES))),
            'nama antrian di luar tujuh kanonik - default/search/reconcile terlarang');
    }

    public function test_status_enum_hanya_hidup_di_satu_tempat(): void
    {
        $offenders = [];
        foreach ($this->phpFiles('app') as $file) {
            foreach (self::STATUS_PATHS as $allowed) {
                if (str_contains(str_replace('\\', '/', $file), $allowed)) { continue 2; }
            }
            $src = (string) file_get_contents($file);
            foreach (OrderStatus::values() as $value) {
                if (preg_match("/['\"]" . preg_quote($value, '/') . "['\"]/", $src)) {
                    $offenders[] = str_replace(base_path() . '/', '', $file) . ':' . $value;
                }
            }
        }
        $this->assertSame([], $offenders, 'literal status di luar satu sumber (D-S0-4) - temu: ' . implode(', ', $offenders));
    }

    public function test_register_event_tidak_punya_salinan_kedua(): void
    {
        $copies = [];
        foreach ($this->phpFiles('app') as $file) {
            if (!str_ends_with($file, 'DomainEventCatalog.php')
                && str_contains((string) file_get_contents($file), "'PaidStockExceptionRaised'")) { $copies[] = basename($file); }
        }
        $this->assertSame([], $copies, 'register event hanya di DomainEventCatalog (Doc 11 §8.4) - temu: ' . implode(', ', $copies));
    }

    private function phpFiles(string $dir): array
    {
        $base = base_path($dir);
        return is_dir($base) ? iterator_to_array((new Finder())->in($base)->name('*.php')->files()->ignoreDotFiles(true)) : [];
    }

    private function moduleOf(string $path): string
    {
        preg_match('#Modules/([^/]+)/#', str_replace('\\', '/', $path), $m);
        return $m[1] ?? '';
    }
}
```

- [ ] **Step 8: Pasang guard pada endpoint bertanda Idem = ya + ubah spec di step yang sama (B-2)** — di `routes/api.php` setiap stub dari Task 5 diberi `->middleware('idempotency:<operationId>')` untuk sepuluh operasi Doc 12 §2.1: `POST /cart/items` (`cart.item.add`), `POST /checkout/sessions` (`checkout.session.open`), `POST /orders` (`order.create`), `POST /payment-intents` (`charge.create`), `POST /product-requests` (`request.submit`), `POST /product-requests/{id}/attachments/presign` (`media.presign`), `POST /quotations/{id}/accept` (`quote.accept`), `POST /admin/preorder-batches/{id}/close` (`batch.close`), `POST /admin/purchase-orders` (`po.create`), `POST /admin/shipments` (`shipment.create`), dan `checkout.enabled = false` tetap menghasilkan `503 CHECKOUT_DISABLED` (kill switch, Task Settings). Di `packages/api-contracts/openapi.yaml`: tambah `components.parameters.IdempotencyKey` (`in: header`, `name: Idempotency-Key`, `required: true`, `schema: {type: string, pattern: '^(?:[0-9a-fA-F]{8}-[0-9a-fA-F-]{27}|[0-9A-Z]{26})$'}`), `$ref` itu ke sepuluh operasi, respons `400` `urn:elzara:problem:idempotency:key-required` + `409` `urn:elzara:problem:idempotency:key-payload-mismatch` bertipe `ProblemDetails` (Task 7), dan header `Idempotency-Replayed`. Rute fixture `__probe` hanya ada di test (didaftarkan di `setUp()` instance aplikasi test masing-masing) sehingga tidak pernah terlihat oleh gerbang D-8.
- [ ] **Step 9: Hijaukan semua gerbang** — `composer dev:test -- --testsuite Architecture` (diharapkan `OK (6 tests, 9 assertions)`), lalu `composer dev:test -- tests/Feature/Platform/IdempotencyGuardTest.php tests/Concurrency/Platform/IdempotencyConcurrencyTest.php tests/Feature/Platform/InboxDedupTest.php` (diharapkan `OK (10 tests, 27 assertions)`), lalu `composer ci:all` penuh: `C1-C8: OK`, `D-8 dua arah: OK (116 operasi)`, `larastan level max: No errors`.
- [ ] **Step 10: Commit** — `git add apps/api/app/Platform/Application/Idempotency apps/api/app/Platform/Application/Outbox/InboxConsumer.php apps/api/routes/api.php packages/api-contracts/openapi.yaml apps/api/tests/Feature/Platform apps/api/tests/Concurrency/Platform/IdempotencyConcurrencyTest.php apps/api/tests/Architecture` lalu `git commit -m "feat(sprint-0): idempotency guard, inbox dedup, dan architecture test boundary B1-B6"`.

### Task 11 — ID 0.6a: migrasi Identity + Settings + Content(`media`, `localizations`) + Audit (`audit_logs`, `activity_log`) (`0007`–`0027`)

> **Diamandemen 2026-11-18 (ADR-012 (RBAC/identitas/dependensi), register Doc 11 §4.4 A/B):** bentuk lama task ini — kolom `kind` di `users`, pivot `role_user`/`permission_role`, `roles.code`/`permissions.code`, satu `recovery_hash` — **tidak berlaku, diganti** bentuk di bawah. Status disk: **belum** (semua direktori migrasi modul masih `.gitkeep`, diverifikasi ls). Eksekusi lewat `.codex/plans/2026-11-18-*.md`, agen migrasi DB lebih dulu (urutan mengikat Doc 11 §4.4 H).

Keputusan (tidak digantung): (1) Doc 10 M-1/B-1/Appendix B langkah 3 + arch test B6 menaruh file migrasi modul di `apps/api/app/Modules/<pemilik>/Infrastructure/Database/Migrations/`, glob loader (`app/ModuleLoader`, sudah ada di disk) menyatukannya menjadi satu daftar terurut `NNNN` — nomor `0007`–`0027` tetap global dan basename-unique (C2); band Sprint 0 `0001–0199`, `0001` sudah dipakai trio reliability Platform; (2) satu file = satu tabel (Doc 10 §2); (3) urutan L0 → L1 → L2 → L3 persis Appendix A, tidak ada FK ke tabel bernomor lebih besar (M-5, C3); (4) **pengecualian konvensi PK**: pivot spatie (`model_has_roles`, `model_has_permissions`, `role_has_permissions`) memakai **composite PK asli** + kolom morph `model_type`/`model_id` karena paket membaca bentuk itu (Doc 11 §4.4 B) — bukan unique-index-plus-id seperti pivot lama; (5) semua kolom waktu `timestampTz(...)->useCurrent()` karena S-9 mewajibkan `timestamptz`; (6) `citext` diaktifkan `IF NOT EXISTS` di `0010`; (7) **migrasi vendor ditulis ulang di bawah namespace/band kita, bukan `vendor:publish` mentah**; migrasi `personal_access_tokens` bawaan Sanctum **dikecualikan — tidak dibuat** (ADR-007 (BFF/sesi) D-3a).
Konvensi yang tidak diulang di tiap blok: setiap `Schema::create` diawali `$t->id();` dan diakhiri `$t->timestampTz('created_at')->useCurrent(); $t->timestampTz('updated_at')->useCurrent();` (kecuali `audit_logs` append-only S-6: tanpa `updated_at`; pivot spatie: tanpa timestamps sesuai bentuk paket), `down()` = kebalikan persis `up()` (M-6), khusus `0010` urutan `down()` = `DROP CONSTRAINT ck_users_operator_admin, ck_users_status` lalu `dropIfExists('users')` (ekstensi `citext` dibiarkan, tidak destruktif).

**Files:**
- Create (`.../app/Modules/Identity/Infrastructure/Database/Migrations/`): `0008_Identity_create_roles_table.php`, `0009_Identity_create_permissions_table.php`, `0010_Identity_create_users_table.php`, `0011_Identity_create_role_has_permissions_table.php`, `0012_Identity_create_addresses_table.php`, `0013_Identity_create_customer_profiles_table.php`, `0014_Identity_create_user_consents_table.php`, `0015_Identity_create_otp_challenges_table.php`, `0016_Identity_create_user_sessions_table.php`, `0017_Identity_create_model_has_roles_table.php`, `0018_Identity_create_staff_2fa_secrets_table.php`, `0024_Identity_create_model_has_permissions_table.php`, `0025_Identity_create_role_scopes_table.php`, `0026_Identity_create_user_identities_table.php`
- Create (`.../app/Modules/Content/Infrastructure/Database/Migrations/`): `0007_Content_create_media_table.php`, `0019_Content_create_localizations_table.php` · (`.../Settings/`): `0020_Settings_create_feature_flags_table.php`, `0021_Settings_create_settings_table.php`, `0023_Settings_create_feature_flag_overrides_table.php` · (`.../Audit/`): `0022_Audit_create_audit_logs_table.php`, `0027_Audit_create_activity_log_table.php`
- Test: `apps/api/tests/Schema/ForbiddenTablesTest.php`, `apps/api/tests/Schema/IdentitySchemaTest.php`

- [ ] **Step 1: Tulis test yang gagal lebih dulu** — `ForbiddenTablesTest.php` mengunci larangan eksplisit (Doc 11 §4.3/§4.4, Doc 10 §4, spec §7):

```php
public function test_tabel_terlarang_tidak_dibuat_di_sprint_0(): void {
    // staff_users diganti is_operator (Doc 11 §4.3); role_user/permission_role diganti pivot spatie; PAT tidak dibuat (ADR-007 (BFF/sesi) D-3a)
    foreach (['staff_users','role_user','permission_role','personal_access_tokens','request_attachments','webhook_receipts','payables','expenses'] as $t) { // semua nama lama/terlarang, tidak dibuat
        $this->assertFalse(Schema::hasTable($t), "tabel hantu muncul: {$t}"); } }
public function test_users_punya_check_operator_admin(): void {
    $d = DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname='ck_users_operator_admin'")->d;
    $this->assertStringContainsString('is_admin', $d); $this->assertStringContainsString('is_operator', $d);
    $this->expectException(QueryException::class);   // is_admin=true tanpa is_operator=true harus ditolak DB
    DB::table('users')->insert(['public_id'=>'01FORBIDDEN00000000000000','name'=>'X','email'=>'x@e.test','is_operator'=>false,'is_admin'=>true]); }
```

- [ ] **Step 2: `composer dev:test -- ForbiddenTablesTest`** → merah dengan `SQLSTATE[42P01]: Base table or view not found: users` (bukan `Failed asserting that false is true`), artinya gagal karena skema belum ada, bukan karena assertion longgar.

- [ ] **Step 3: Header wajib di 21 file** (Doc 10 §2, ditegakkan C8) — tempel sebelum `return new class`, isi `Modul`/`Tier` sesuai label tiap blok di langkah 4–8:

```php
<?php // NNNN_<Module>_<slug>.php
// Modul   : <pemilik menurut Doc 09 §19 — B1> · Sprint : 0 · rilis 2026-01
// Tier    : <level Appendix A: 0 | 1 | 2 | 3> · Expand : ya, contract tidak diperlukan
use Illuminate\Database\Migrations\Migration; use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB; use Illuminate\Support\Facades\Schema;
```

- [ ] **Step 4: L0** — `0007` Content Tier 0; `0008`, `0009` Identity Tier 0:

```php
// 0007_Content_create_media_table.php (Doc 09 §15)
$t->uuid('uuid')->default(DB::raw('gen_random_uuid()'))->unique('uq_media_uuid');
$t->string('model_type', 64); $t->bigInteger('model_id'); $t->string('kind', 24); $t->string('disk', 24)->default('s3');
$t->string('path', 512); $t->string('mime', 80)->nullable(); $t->bigInteger('size_bytes')->nullable();
$t->integer('width')->nullable(); $t->integer('height')->nullable(); $t->char('sha256', 64)->nullable();
$t->string('alt_key', 120)->nullable(); $t->smallInteger('position')->default(0); $t->boolean('is_moderated')->default(false);
$t->string('moderation_status', 16)->default('pending');
$t->index(['model_type','model_id','kind','position'], 'idx_media_model'); $t->unique(['disk','path'], 'uq_media_path');
// 0008 roles (bentuk spatie, ditulis ulang milik kita — kolom code lama diganti name, Doc 11 §4.4 B):
//   $t->string('name',80); $t->string('guard_name',40)->default('admin'); $t->text('description')->nullable(); $t->boolean('is_system')->default(false);
//   $t->unique(['name','guard_name'],'uq_roles_name_guard');   // roles.name = kode Blueprint (catalog-manager, dst.)
// 0009 permissions (bentuk spatie): $t->string('name',64); $t->string('guard_name',40)->default('admin'); $t->string('group_name',40); $t->text('description')->nullable();
//   $t->unique(['name','guard_name'],'uq_permissions_name_guard'); $t->index(['group_name'],'idx_permissions_group');   // name = <modul>.<aksi>
```

- [ ] **Step 5: L1** — `0010` users Tier 1 (FK ke `media` Tier 0), `0011` role_has_permissions Tier 1:

```php
// 0010_Identity_create_users_table.php (Doc 09 §2) — DB::statement('CREATE EXTENSION IF NOT EXISTS citext'); di baris pertama up()
$t->string('public_id', 26)->unique('uq_users_public_id'); $t->string('name', 120); $t->addColumn('citext','email')->unique('uq_users_email');
$t->timestampTz('email_verified_at')->nullable(); $t->string('phone', 24)->nullable(); $t->timestampTz('phone_verified_at')->nullable();
$t->string('password_hash', 255)->nullable();
$t->boolean('is_operator')->default(false); $t->boolean('is_admin')->default(false);   // dua boolean ortogonal, pengganti enum kind lama (Doc 11 §4.4 A)
$t->string('status', 16)->default('active');
$t->string('locale', 5)->default('id'); $t->string('timezone', 64)->default('Asia/Jakarta');
$t->foreignId('avatar_media_id')->nullable(); $t->foreignId('banner_media_id')->nullable();
$t->boolean('marketing_optin')->default(false); $t->timestampTz('last_login_at')->nullable(); $t->softDeletesTz();
$t->index(['is_operator','status'], 'users_operator_status');   // index users_kind_status lama diganti ini
$t->foreign('avatar_media_id')->references('id')->on('media')->nullOnDelete(); $t->foreign('banner_media_id')->references('id')->on('media')->nullOnDelete();
// setelah create(): DB::statement('ALTER TABLE users ADD CONSTRAINT ck_users_operator_admin CHECK (is_admin = false OR is_operator = true)');
// DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_status CHECK (status IN ('active','suspended','deletion_requested','deleted'))");
// 0011 role_has_permissions (pivot resmi spatie, pengganti permission_role lama): permission_id+role_id constrained cascadeOnDelete;
//   composite PK (permission_id, role_id) — tanpa id tambahan, bentuk paket (Doc 11 §4.4 B)
```

- [ ] **Step 6: L2 Identity** — `0012`–`0018` Tier 2; FK hanya ke `users` (L1) dan `roles`/`permissions` (L0):

```php
// 0012 addresses: user_id constrained cascadeOnDelete; label(60) N; recipient(120); phone(24); province(60); city(60); district(60) N; village(60) N; postal_code(10); address_line text; lat+lng decimal(9,6) N; is_default+is_archived bool false; index(user_id,'idx_addresses_user')
DB::statement('CREATE UNIQUE INDEX uq_addresses_default ON addresses (user_id) WHERE is_default AND NOT is_archived');
// 0013 customer_profiles: user_id unique('uq_customer_profiles_user') constrained cascadeOnDelete; display_name(120) N; birthday date N; gender(12) N; tax_id(40) N; referral_code(40) N; referred_by foreignId->users nullOnDelete; external_ids jsonb default DB::raw("'{}'::jsonb"); notes text N; index(referral_code,'idx_customer_profiles_referral')
DB::statement("ALTER TABLE customer_profiles ADD CONSTRAINT ck_customer_profiles_gender CHECK (gender IS NULL OR gender IN ('male','female','other','undisclosed'))");
// 0014 user_consents: user_id constrained cascadeOnDelete; kind(32) [kind milik user_consents, bukan users]; policy_version integer (KOLOM POLOS tanpa FK — Doc 09 §2 + M-5); granted_at timestampTz; ip inet N; user_agent(255) N; unique(user_id,kind,policy_version,'uq_user_consents_once')
DB::statement("ALTER TABLE user_consents ADD CONSTRAINT ck_user_consents_kind CHECK (kind IN ('terms','privacy','po_deposit_forfeit','marketing','age_gate'))");
// 0015 otp_challenges: user_id constrained cascadeOnDelete; channel(16); code_hash(255); purpose(24); attempts smallint default 0; expires_at+consumed_at timestampTz (consumed N); index(user_id,purpose,expires_at,'idx_otp_active')
DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT ck_otp_challenges_channel CHECK (channel IN ('email','whatsapp','sms'))");
DB::statement("ALTER TABLE otp_challenges ADD CONSTRAINT ck_otp_challenges_purpose CHECK (purpose IN ('login','reset_password','verify_phone'))");
// 0016 user_sessions: user_id constrained cascadeOnDelete; session_hash(64) unique('uq_user_sessions_hash'); ip inet N; user_agent(255) N; device_label(80) N; last_seen_at+revoked_at timestampTz N
DB::statement('CREATE INDEX idx_user_sessions_user ON user_sessions (user_id) WHERE revoked_at IS NULL');
// 0017 model_has_roles (pivot resmi spatie, pengganti role_user lama — Doc 11 §4.4 B): role_id constrained cascadeOnDelete;
//   model_type(64) + model_id bigInteger (morph, dibaca paket); composite PK (role_id, model_id, model_type); index(model_id, model_type)
// 0018 staff_2fa_secrets: user_id unique('uq_staff_2fa_secrets_user') constrained cascadeOnDelete; secret_encrypted binary (=> bytea, tidak pernah plaintext); confirmed_at timestampTz N;
//   recovery_hashes text[] NOT NULL default '{}' — SEPULUH kode ter-hash (bentuk recovery_hash(255) tunggal lama diganti, D-S0-15 + Doc 09 §2)
```

- [ ] **Step 6b: L2 baru (amandemen 2026-11-18)** — `0024`–`0027`, ditulis ulang milik kita (bukan publish vendor):

```php
// 0024_Identity_create_model_has_permissions_table.php (direct grant per user, resmi spatie — Doc 11 §4.4 B):
//   permission_id constrained cascadeOnDelete; model_type(64) + model_id bigInteger; composite PK (permission_id, model_id, model_type); index(model_id, model_type)
// 0025_Identity_create_role_scopes_table.php (kolom scope pindah ke sini dari pivot lama — Doc 11 §4.4 B):
//   user_id+role_id constrained cascadeOnDelete; scope_type(24) N (NULL = global | batch | category | warehouse); scope_id bigInteger N (kolom polos);
DB::statement("CREATE UNIQUE INDEX uq_role_scopes_identity ON role_scopes (role_id, user_id, COALESCE(scope_type, ''), COALESCE(scope_id, 0))");
DB::statement("ALTER TABLE role_scopes ADD CONSTRAINT ck_role_scopes_type CHECK (scope_type IS NULL OR scope_type IN ('batch','category','warehouse'))");
// 0026_Identity_create_user_identities_table.php (login sosial, milik Identity — Doc 11 §4.4 B):
//   user_id constrained cascadeOnDelete; provider(24) [google|twitter]; provider_user_id(128); linked_at timestampTz useCurrent;
//   unique(provider, provider_user_id, 'uq_user_identities_provider'); index(user_id)
// 0027_Audit_create_activity_log_table.php (spatie activitylog standar, milik Audit — BUKAN pengganti audit_logs, Doc 11 §4.4 F):
//   log_name(64) N index; description text; subject_type(64)+subject_id bigInteger N (morph index); causer_type(64)+causer_id bigInteger N (morph index);
//   properties jsonb N; event(64) N; batch_uuid uuid N index; timestamps standar
```

- [ ] **Step 7: L2 Content/Settings/Audit** — `0019`–`0022` Tier 2:

```php
// 0019 localizations: key(140); group_name(60); locale(5); value text; context(120) N; plural_variant smallint N; updated_by foreignId->users nullOnDelete; index(group_name,locale,'idx_localization_group')
DB::statement("CREATE UNIQUE INDEX uq_localization_key_locale ON localizations (key, locale, COALESCE(plural_variant, 0))");
DB::statement("ALTER TABLE localizations ADD CONSTRAINT ck_localizations_locale CHECK (locale IN ('id','en'))");
DB::statement("ALTER TABLE localizations ADD CONSTRAINT ck_localizations_group CHECK (group_name IN ('shop','admin','email','validation','policy'))");
// 0020 feature_flags: code(64) unique('uq_feature_flags_code'); description(240); default_enabled bool false; requires_restart bool false; updated_by foreignId->users NULL nullOnDelete
// 0021 settings: key(120) unique('uq_settings_key'); group_name(48); value_type(12); value jsonb; is_public bool false; description(240) N; default_value jsonb; updated_by foreignId->users NULL; index(group_name,'idx_settings_group')
DB::statement("ALTER TABLE settings ADD CONSTRAINT ck_settings_value_type CHECK (value_type IN ('string','int','bool','decimal','json'))");
// 0022 audit_logs: actor_id foreignId->users nullOnDelete; action(64); target_type(64); target_id bigInteger; before+after jsonb N; reason(320) NOT NULL; ip inet N; user_agent(255) N; occurred_at timestampTz useCurrent; TANPA updated_at
$t->index(['target_type','target_id','occurred_at'], 'idx_audit_target'); $t->index(['actor_id','occurred_at'], 'idx_audit_actor_time');
DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT ck_audit_logs_reason CHECK (length(trim(reason)) > 0)");
```

- [ ] **Step 8: L3** — `0023_Settings_create_feature_flag_overrides_table.php` Tier 3; FK ke `feature_flags.code` (L2, unik) legal karena hanya menunjuk level lebih rendah:

```php
$t->string('flag_code', 64); $t->string('env', 16)->default('prod'); $t->boolean('enabled'); $t->smallInteger('rollout_percent')->default(100);
$t->foreignId('updated_by')->constrained('users');
$t->foreign('flag_code')->references('code')->on('feature_flags')->cascadeOnDelete();
$t->unique(['flag_code','env'], 'uq_feature_flag_overrides');   // = PRIMARY KEY komposit versi Doc 09, lihat keputusan (4)
DB::statement("ALTER TABLE feature_flag_overrides ADD CONSTRAINT ck_feature_flag_overrides_env CHECK (env IN ('local','staging','prod'))");
DB::statement("ALTER TABLE feature_flag_overrides ADD CONSTRAINT ck_feature_flag_overrides_rollout CHECK (rollout_percent BETWEEN 0 AND 100)");
```

- [ ] **Step 9: Test kelengkapan + bukti PG16 nyata** — `IdentitySchemaTest.php`:

```php
public function test_server_test_adalah_postgres_enam_belas(): void {
    $this->assertStringContainsString('PostgreSQL 16', DB::selectOne('SELECT version() AS v')->v); }   // B-11: bukan SQLite
public function test_semua_tabel_sprint_0_ada_dengan_tipe_kanonik(): void {
    foreach (['media','roles','permissions','users','role_has_permissions','addresses','customer_profiles','user_consents','otp_challenges',
              'user_sessions','model_has_roles','model_has_permissions','role_scopes','user_identities','staff_2fa_secrets','localizations',
              'feature_flags','settings','audit_logs','activity_log','feature_flag_overrides'] as $t) {
        $this->assertTrue(Schema::hasTable($t), $t); }
    $type = fn (string $t, string $c) => DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_name=? AND column_name=?", [$t,$c])->data_type;
    $this->assertSame('bytea', $type('staff_2fa_secrets','secret_encrypted')); $this->assertSame('jsonb', $type('settings','value'));
    $this->assertSame('ARRAY', $type('staff_2fa_secrets','recovery_hashes'));   // text[] sepuluh kode, bukan varchar tunggal
    $this->assertSame('citext', $type('users','email')); $this->assertSame('inet', $type('audit_logs','ip'));
    $this->assertSame('boolean', $type('users','is_operator')); $this->assertSame('boolean', $type('users','is_admin'));
    $this->assertSame('jsonb', $type('activity_log','properties'));
    $this->assertSame('admin', DB::selectOne("SELECT column_default AS d FROM information_schema.columns WHERE table_name='roles' AND column_name='guard_name'")->d ?? 'admin');
    $this->assertSame('0027', substr(DB::selectOne('SELECT migration FROM migrations ORDER BY migration DESC LIMIT 1')->migration, 0, 4)); }
```

- [ ] **Step 10: Gerbang urutan + waktu** — dari DB kosong: `composer dev:migrate:fresh && composer dev:test -- IdentitySchemaTest ForbiddenTablesTest` hijau; C2/C3/C8 hijau; `time composer dev:migrate:fresh` riil **< 40 s** (spec §6); C5 terbukti lewat `composer dev:artisan migrate:rollback --step=21` yang sukses tanpa daftar hitam irreversibel. Perubahan bentuk tabel (pivot baru + `user_identities` + `activity_log`) wajib masuk **Doc 09 §19 + Doc 01 §3.1/§3.3 + disk bersamaan** (gate H/I, Doc 11 §4.4 G) — PR skema dimiliki agen dokumen terkait, bukan ditulis diam-diam di sini.

- [ ] **Step 11: Commit** — regenerasi snapshot lewat jalur Task 5 (`composer dev:db-snapshot` → `docs/schema.snapshot.sql`), tidak menulis angka jumlah tabel di mana pun (Doc 11 §4.2), lalu `git add apps/api/app/Modules/Identity/Infrastructure/Database/Migrations apps/api/app/Modules/Content/Infrastructure/Database/Migrations apps/api/app/Modules/Settings/Infrastructure/Database/Migrations apps/api/app/Modules/Audit/Infrastructure/Database/Migrations apps/api/tests/Schema docs/schema.snapshot.sql` + `git commit -m "feat(sprint-0): skema identity+settings+content+audit 0007-0027 bentuk ADR-012 dengan check kanonik"`.

### Task 12 — ID 0.6b: auth sesi Sanctum dua guard + RBAC spatie sepuluh role + 2FA TOTP semua operator

> **Diamandemen 2026-11-18 (ADR-007 (BFF/sesi) amandemen D-1a..D-5a + ADR-012 (RBAC/identitas/dependensi)):** bentuk lama task ini — "ADR-007 masih terbuka, session/BFF di belakang Next.js, nol securitySchemes, 2FA hanya superadmin, satu recovery code, `users.kind` memisahkan populasi, pivot `role_user`/`permission_role`, wildcard `*.*`" — **tidak berlaku, diganti** bentuk di bawah. Status disk: **belum** (tidak ada guard/policy/CSRF; diverifikasi ls/grep). **Prasyarat keras sebelum kode task ini ditulis: sign-off threat model oleh klien (P-6, ADR-007 (BFF/sesi) D-6a).** Eksekusi lewat `.codex/plans/2026-11-18-*.md` setelah agen migrasi DB selesai (urutan mengikat Doc 11 §4.4 H).

Bentuk sesi (diputuskan, bukan digantung): **Sanctum `statefulApi`, cookie same-site** — satu host, path terpisah (`/api/`, `/admin/`, `/` lewat `docker/nginx/dev.conf`); dua guard/cookie: `web`/`elzara_web_session` dan `admin`/`elzara_admin_session`, keduanya `Secure; HttpOnly; SameSite=Lax; Path=/` (pemisahan lewat **nama cookie + guard**, bukan path — Path=/ wajib supaya cookie sampai ke `/api/`); `XSRF-TOKEN` sengaja **tidak** HttpOnly (dibaca JS untuk header `X-XSRF-TOKEN` — desain Sanctum); `sanctum/csrf-cookie` dipanggil sebelum mutasi pertama; **CSRF valid wajib semua mutasi, tanpa pengecualian admin**; tanpa BFF wajib (route handler Next boleh meneruskan cookie yang sama untuk SSR, tanpa sesi sendiri dan tanpa keputusan otorisasi); tanpa token browser — `personal_access_tokens` **tidak dibuat**; s2s = HMAC/signature atau jaringan internal. `SESSION_DRIVER=redis` (Doc 01 §11.3; tabel `sessions` tidak dipakai, Doc 09 §17). Tidak ada CORS cross-origin, tidak ada `SameSite=None`. Pemilik 10 role + permission = **`ReferenceSeeder` (Task 15)**; tidak ada `RoleSeeder` kedua (M-12).

**Files:**
- Create (`.../app/Modules/Identity/`): `Infrastructure/Models/{User,UserIdentity,CustomerProfile,Consent,OtpChallenge,UserSession,Address,StaffTwoFactorSecret}.php` (Role/Permission = model bawaan spatie/laravel-permission, tabelnya migrasi kita Task 11), `Domain/Events/UserRegistered.php`, `Domain/InvalidCredentials.php`, `Application/Commands/{RegisterCustomer,LoginWithPassword,LoginWithSocial,LinkSocialIdentity,LogoutSession,RequestPasswordReset,ResetPassword,EnrolSecondFactor,ConfirmSecondFactor,VerifySecondFactor,RotateRecoveryCodes}.php`, `Application/DataTransfer/{AuthUserDTO,LoginResultDTO}.php` (spatie/laravel-data, DTO di Contracts/), `Infrastructure/Security/{TotpGateway,LoginThrottle}.php`, `Infrastructure/Http/Controllers/{AuthController,SocialAuthController,SecondFactorController}.php`, `Infrastructure/Http/Middleware/{EnsureOperator,EnsurePermission,ResolveLocale}.php`, `IdentityServiceProvider.php`, `config/{identity.permissions,identity.roles}.php`, `Application/Queries/ListRoles.php`, `Application/Commands/CreateRole.php`, `Infrastructure/Http/Controllers/AdminRoleController.php`, `Tests/Feature/{RbacDenialTest,AdminBypassAuditTest,TwoFactorTest,SocialLoginTest,LoginThrottleTest,CsrfTest}.php`
- Create: `apps/api/tests/Support/SeedsRbacFixture.php`, `apps/api/routes/auth.php`, `apps/api/tests/Architecture/IdentityCommandBoundaryTest.php`
- Modify: `packages/api-contracts/openapi.yaml` (securitySchemes + security per operasi + endpoint auth — gelombang yang sama dengan agen kontrak), `apps/api/bootstrap/app.php`, `apps/api/config/{auth,session,sanctum}.php`, `apps/api/composer.json`, `apps/api/composer.lock` (semua perubahan dependensi **di dalam kontainer php:8.4**, Doc 03 0.17)

- [ ] **Step 1: Test penolakan server-side, gagal lebih dulu** — `RbacDenialTest.php` (bukti B-10: penolakan di Laravel, bukan di menu Next.js) + `AdminBypassAuditTest.php`. Role/permission belum di-seed di task ini (pemilik datanya `ReferenceSeeder` Task 15), jadi test memakai `Tests/Support/SeedsRbacFixture` yang **hanya membaca** `config/identity.roles.php` + `config/identity.permissions.php`:

```php
final class RbacDenialTest extends TestCase { use SeedsRbacFixture;   // setUp(): $this->seedRbac(); parent::setUp();
public function test_customer_ditolak_guard_admin_dan_analyst_tidak_bisa_menambah_role(): void {
    $this->actingAs($this->makeUser(false, null), 'admin')->getJson('/api/v1/admin/roles')->assertStatus(401);   // bukan operator: guard admin menolak
    $analyst = $this->makeUser(true, 'analyst');
    $this->actingAs($analyst, 'admin')->getJson('/api/v1/admin/roles')->assertStatus(200);                       // user.view boleh
    $this->actingAs($analyst, 'admin')->postJson('/api/v1/admin/roles', ['name' => 'editor'])->assertStatus(403); // tidak punya hak asign
    $this->assertSame(10, Role::count()); }                                                                    // tanpa role kesebelas
public function test_cookie_storefront_tidak_diterima_guard_admin(): void {
    $op = $this->makeUser(true, 'sales-ops');
    $this->actingAs($op, 'web')->getJson('/api/v1/admin/roles')->assertStatus(401); } }                        // XSS storefront != akses admin
final class AdminBypassAuditTest extends TestCase { use SeedsRbacFixture;
public function test_is_admin_bypass_wajib_meninggalkan_jejak_audit(): void {
    $root = $this->makeUser(true, null, isAdmin: true);                                                        // tanpa satu pun role
    $this->actingAs($root, 'admin')->getJson('/api/v1/admin/roles')->assertStatus(200);                        // Gate::before lolos
    $this->assertDatabaseHas('audit_logs', ['actor_id' => $root->id, 'action' => 'rbac.bypass']); } }          // reason + request_id wajib terisi
```

- [ ] **Step 2: `composer dev:test -- RbacDenialTest AdminBypassAuditTest`** → merah: trait fixture belum ada dan 404 pada `/api/v1/admin/roles` (bukan 403 — rute itu memang belum ada).

- [ ] **Step 3: Model** — satu tabel `users`; `is_operator`/`is_admin` boolean (pengganti kolom `kind` lama; tabel `staff_users` tetap **tidak dibuat**, Doc 11 §4.3/§4.4 A):

```php
class User extends Authenticatable {
    use HasRoles;                       // spatie/laravel-permission; guard_name default 'admin' — TIDAK dipakai untuk otorisasi storefront
    protected $table = 'users'; protected $hidden = ['password_hash']; protected $guard_name = 'admin';
    public function identities(): HasMany { return $this->hasMany(UserIdentity::class); }        // google|twitter, unique (provider, provider_user_id)
    public function twoFactor(): HasOne { return $this->hasOne(StaffTwoFactorSecret::class, 'user_id'); }
    public function isOperator(): bool { return $this->is_operator && $this->status === 'active'; } }
// UserIdentity, CustomerProfile, Consent (user_consents), OtpChallenge, UserSession, Address, StaffTwoFactorSecret
// (cast ['secret_encrypted' => 'encrypted', 'recovery_hashes' => 'array']).
// Storefront (guard web): otorisasi = Policy berbasis kepemilikan (order/cart/alamat milik user_id sendiri) + status akun; tanpa spatie.
```

`config/identity.permissions.php` = **satu sumber** permission granular `<modul>.<aksi>` yang dipakai Gate dan `ReferenceSeeder` tanpa disalin. Prefiks kanonik per modul = Doc 11 §4.4 B (Identity=`user`, Catalog=`catalog`, Content=`content`, PreOrder=`batch`, Billing=`billing`, Orders=`order`, Settings=`setting`, Audit=`audit`, Payments=`payment`, dst.); aksi dasar `view` (index/show), `create`, `update`, `delete` + aksi khusus `billing.void`, `order.force_transition`, `batch.close`, `payment.mark_paid_manual`. Contoh isi Sprint 0: `user.view`, `user.update`, `user.delete`, `catalog.view`, `catalog.update`, `content.view`, `content.update`, `setting.view`, `setting.update`, `audit.view`, `order.view`, `order.force_transition`, `billing.void`. **Tidak ada wildcard `*.*`** (bentuk lama diganti bundel granular); matriks lengkap role × aksi dimiliki Doc 06 (agen dokumen terkait). Lintas modul (outbox/inbox/idempotency) tidak punya permission admin; Search tidak punya permission admin.

`config/identity.roles.php` = **satu sumber** sepuluh bundel role Blueprint §4 (`name` = kode Blueprint, `guard_name='admin'`): `superadmin` = bundel **semua permission granular** (bukan bypass — decisions lama `superadmin → ['*.*']` tidak berlaku); `analyst` read-only = bundel `*.view` yang relevan; `catalog-manager`, `sales-ops`, `preorder-ops`, `sourcing-ops`, `warehouse-staff`, `procurement-staff`, `finance`, `customer-support` = bundel per matriks Doc 06. Task 15 hanya `firstOrCreate` dari config ini. Fixture test sebelum Task 15:

```php
// apps/api/tests/Support/SeedsRbacFixture.php  (namespace Tests\Support)
trait SeedsRbacFixture {
    protected function seedRbac(): void {
        foreach (config('identity.permissions') as $group => $names) {
            foreach ($names as $p) { Permission::findOrCreate($p, 'admin'); } }
        foreach (config('identity.roles') as $name => $def) {
            $role = Role::findOrCreate($name, 'admin'); $role->syncPermissions($def['permissions']); } }
    protected function makeUser(bool $operator, ?string $roleName, bool $isAdmin = false, string $password = 'rahasia-benar'): User {
        $u = User::create(['public_id' => (string) Str::ulid(), 'name' => 'Fixture', 'email' => strtolower(Str::random(8)).'@fixture.test',
            'is_operator' => $operator, 'is_admin' => $isAdmin, 'status' => 'active', 'locale' => 'id',
            'password_hash' => Hash::hash($password, algo: PASSWORD_ARGON2ID)]);
        if ($roleName !== null) { $u->assignRole($roleName); } return $u; } }
```

- [ ] **Step 4: Guard, cookie, CSRF, Gate** — `config/auth.php`: guard `web` (session, provider users) + guard `admin` (session, provider users, cookie terpisah); `config/session.php` + `config/sanctum.php`: `statefulApi` aktif, cookie `elzara_web_session`/`elzara_admin_session` sesuai guard, `Secure; HttpOnly; SameSite=Lax; Path=/`. Bootstrap: `EnsureFrontendRequestsAreStateful` (Sanctum) + `VerifyCsrfToken` untuk **semua** mutasi (tanpa except admin); `GET /sanctum/csrf-cookie` tersedia. Di `IdentityServiceProvider::boot()`:

```php
Gate::before(function (User $u, string $ability) {           // HANYA guard admin — didaftarkan pada konteks admin
    if (!auth('admin')->check() || auth('admin')->id() !== $u->id) { return null; }
    if (!$u->is_admin) { return null; }
    AuditThis::record('rbac.bypass', reason: 'is_admin gate-before', requestId: request()->attributes->get('request_id'));
    return true; });                                         // setiap pemakaian WAJIB menulis audit_logs (Doc 11 §4.4 A/F)
$this->app['router']->aliasMiddleware('identity.operator', EnsureOperator::class);   // is_operator=true + status=active + 2FA confirmed
$this->app['router']->aliasMiddleware('identity.perm', EnsurePermission::class);     // cek permission spatie granular, deny-by-default
```

Setiap rute `/admin/*` memakai `->middleware(['auth:admin','identity.operator','identity.perm:<modul.aksi>'])` — penolakan terjadi **sebelum query apa pun** (Doc 12 §3.1). Pencabutan permission lewat `removeRole`/`revokePermissionTo` + invalidasi cache via `PermissionRegistrar` — **bukan** UPDATE langsung ke pivot (runbook + Doc 06).

- [ ] **Step 5: Command (B2)** — `RegisterCustomer` menulis user (tanpa kolom populasi lama — `is_operator` default false) + profil + consent + event **`UserRegistered`** (nama hanya dari Doc 12 §6.2) dalam satu transaksi; payload hanya ID + timestamp (B3), kolom persis baris `UserRegistered` Doc 12 §6.2. **Registrasi hybrid web** memakai jalur yang sama dengan login: email+password, Google, X. `LoginWithSocial`/`LinkSocialIdentity`: Socialite driver `google` + `twitter` dari **`socialiteproviders/twitter`** (OAuth 2.0 — driver twitter bawaan OAuth 1.0a sudah ditinggalkan X, tidak dipakai); identitas disimpan di `user_identities`; **penautan ke akun existing hanya bila email provider terverifikasi dan sama dengan email akun** — tanpa itu, buat akun baru, jangan pernah menautkan otomatis. Tidak ada jalur sosial ke guard admin. `LoginWithPassword`: `LoginThrottle` (langkah 7) → `Hash::verifyPassword`; gagal → `Domain/InvalidCredentials` → **401 `UNAUTHENTICATED` / `urn:elzara:problem:auth:unauthenticated`** (Doc 12 §4.3, URN baru tidak dikarang). Sukses untuk operator dengan `twoFactor.confirmed_at` terisi → sesi admin **belum** dimulai, hasil `LoginResultDTO::requiresSecondFactor($challengeToken)` **HTTP 202**. Guard `web`: user aktif apa pun (termasuk operator — `is_operator`/`is_admin` tidak memberi hak ekstra di storefront). `LogoutSession` mengisi `revoked_at` + `session()->invalidate()`; `RequestPasswordReset` selalu 202 (anti-enumerasi) + `otp_challenges(purpose=reset_password, expires_at=+15 menit)`; `ResetPassword` memverifikasi hash, mengisi `consumed_at`, me-revoke seluruh `user_sessions` akun itu.

- [ ] **Step 6: 2FA TOTP — wajib SEMUA operator** (bukan hanya superadmin; keputusan lama tidak berlaku). Pasang di kontainer: `composer dev:composer require "spomky-labs/otphp:^11.5"`. Verifikasi: `composer dev:composer show spomky-labs/otphp` → `versions : * 11.5.x`.

```php
final class TotpGateway {
    public function __construct(private readonly int $leeway = 1, private readonly int $period = 30) {}
    public function provision(User $u): array { $t = TOTP::create(issuerName: config('app.name'), email: $u->email, period: $this->period);
        return ['secret' => $t->getSecret(), 'otpauth_uri' => $t->getProvisioningUri()]; }
    public function verify(string $secret, string $code): bool {
        return TOTP::create(secret: $secret, period: $this->period)->verify($code, now()->getTimestamp(), $this->leeway); }
    public function newRecoveryCodes(): array { return array_map(fn () => sprintf('%s-%s', Str::upper(Str::random(5)), Str::upper(Str::random(5))), range(1, 10)); } }
```

`EnrolSecondFactor` menyimpan `secret_encrypted = Crypt::encryptString($secret)` dan mengembalikan `otpauth_uri` saja (QR digambar client); `ConfirmSecondFactor` memverifikasi satu kode, mengisi `confirmed_at`, menyimpan **sepuluh** recovery code ter-hash Argon2id (`password_hash($code, PASSWORD_ARGON2ID)`) ke `staff_2fa_secrets.recovery_hashes text[]` (bentuk satu `recovery_hash` lama diganti — D-S0-15, Doc 09 §2); `VerifySecondFactor` menerima TOTP **atau** satu recovery code (sekali pakai — kode dihapus dari array setelah dipakai); `RotateRecoveryCodes` mensyaratkan TOTP valid dan menerbitkan sepuluh kode baru (lama mati). `TwoFactorTest` membuktikan: sebelum verify, sesi admin belum aktif; `session()->getId()` berubah setelahnya (session-fixation guard); recovery code kedua kali pakai ditolak.

- [ ] **Step 7: Rate limit + lockout terdokumentasi** — rate limit di edge **dan** `RateLimiter` di API (Illuminate facade, bukan paket composer) untuk login/OTP/reset: **6 percobaan / 60 s per (email, ip)** dan **6 kegagalan berurutan per akun → lockout 15 menit** (kunci cache redis `login:lock:{user_id}`; `users.status` tidak diubah). `LoginThrottleTest`:

```php
final class LoginThrottleTest extends TestCase { use SeedsRbacFixture;
public function test_enam_percobaan_salah_lalu_dibatasi(): void {
    $u = $this->makeUser(false, null);                                            // password: 'rahasia-benar'
    for ($i = 0; $i < 6; $i++) { $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'salah'])->assertStatus(401); }
    $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'salah'])->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED')->assertJsonPath('type', 'urn:elzara:problem:rate-limit:exceeded')
        ->assertHeader('X-RateLimit-Policy', 'login')->assertHeader('X-RateLimit-Remaining', 0)->assertHeader('Retry-After');   // Doc 12 §3.5
    $this->travel(16)->minutes();
    $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'rahasia-benar'])->assertStatus(200); } }
```

`CsrfTest`: mutasi tanpa header `X-XSRF-TOKEN` valid → 419/403 problem details, **termasuk** mutasi admin; `SocialLoginTest`: penautan tanpa email terverifikasi ditolak, tidak menimpa akun existing.

- [ ] **Step 8: Rute + spec diubah di langkah yang sama** (D-8). `routes/auth.php`: `POST auth/register|login|logout|password/forgot|password/reset`, `GET auth/social/{provider}/redirect|callback` (google, twitter), `POST auth/totp/verify|totp/recovery`, `GET /sanctum/csrf-cookie`, plus `POST admin/me/2fa/enrol|confirm|rotate-recovery` dan `GET|POST admin/roles` di belakang `auth:admin + identity.operator` dengan `identity.perm:user.view|user.update`. Spec (`openapi.yaml`, dikoordinasikan dengan agen kontrak gelombang 2026-11-18): **securitySchemes wajib ada** — `cookieAuth` (apiKey in cookie, nama per guard) + skema CSRF; `security` per operasi; `x-elzara-auth: public|customer|admin|s2s` tetap penanda kategori; operasi admin menambah `x-elzara-permission: <modul.aksi>`; operasi yang bisa dilewati bypass tetap wajib menulis `audit_logs`. `X-Actor-Type` = `web|admin|system`, **diisi server dari guard, tidak pernah dipercaya dari client** (nilai lama customer|staff|system diganti, Doc 11 §4.4 I). Skema `AuthUser`: `public_id`, `locale`, `is_operator`, `is_admin`, `roles[]` (sepuluh kode Blueprint) — tanpa kolom populasi lama.

```php
final class AdminRoleController {   // bungkus tipis: validasi -> Command/Query -> DTO (B2, Doc 01 §3.2)
    public function __invoke(ListRoles $list): array { return $list(); }
    public function store(Request $r, CreateRole $cmd): JsonResponse {        // middleware permission sudah menolak lebih dulu
        Bus::dispatch($cmd->validateWith($r)); return response()->json(['status' => 'created'], 201); } }
final class ListRoles { public function __invoke(): array {
    return ['data' => Role::query()->where('guard_name','admin')->orderBy('name')->get(['name','description','is_system'])->all()]; } }
final class CreateRole { public array $data = [];
    public function validateWith(Request $r): self { $this->data = $r->validate(['name' => ['required','string','max:80','unique:roles,name'],
        'description' => ['nullable','string']]); return $this; }             // 'editor' lolos validasi, ditolak Gate
    public function handle(): void { throw new LogicException('katalog role Sprint 0 tertutup: sepuluh saja (Doc 01 §9.1)'); } }
```

- [ ] **Step 9: Architecture test (bukti e)** — `IdentityCommandBoundaryTest.php`: controller hanya memanggil Command/Query, tidak menyentuh `Illuminate\Support\Facades\DB`, tidak punya properti repository.

- [ ] **Step 10: Gerbang** — `composer dev:test -- RbacDenialTest AdminBypassAuditTest TwoFactorTest SocialLoginTest LoginThrottleTest CsrfTest IdentityCommandBoundaryTest OpenApiRouteParityTest` hijau, lalu generate client di container `node:22` dan pastikan `git diff --stat packages/api-contracts` hanya berisi artefak ter-generate.

- [ ] **Step 11: Commit** — `git add apps/api/app/Modules/Identity apps/api/routes/auth.php apps/api/bootstrap/app.php apps/api/config apps/api/tests apps/api/composer.json apps/api/composer.lock packages/api-contracts/openapi.yaml` + `git commit -m "feat(sprint-0): sanctum dua guard + rbac spatie granular + 2fa semua operator + social hybrid (ADR-007/ADR-012)"`.

### Task 13 — ID 0.9: MinIO enam bucket + presign endpoint publik + finalize magic-byte

> **Amandemen P0.13 disetujui pemilik:** gunakan origin publik `https://storage.localhost` (HTTP pada profil lokal), bukan `/storage` yang dihapus proxy; SigV4 menandatangani path dan Host. Ekspektasi PresignHostTest kini host `storage.localhost`, path `/<bucket>/<uuid>.<ext>`, TTL PUT 600 detik / GET 120 detik. Nginx memakai hostname `storage.*` tanpa rewrite. MinIO+mc dibangun dari `docker/minio/Dockerfile` karena image komunitas tidak tersedia. Gunakan sesi operator admin aktif + CSRF + owner reservasi sampai storefront auth/RBAC tersedia. Reuse migrasi media 0010; expand 0084 hanya menambah VersionId immutable untuk menutup replay PUT setelah finalize. CORS source-build memakai origin web/admin melalui environment server, bukan bucket API yang tidak didukung. Detail historis di bawah yang bertentangan digantikan amandemen ini.

> **Amandemen 2026-11-18 (status disk):** skrip provisioning sudah ada sebagai `docker/minio/init-buckets.sh` — nama lama `provision-buckets.sh` tidak berlaku, diganti nama di disk (referensi di bawah sudah diperbarui). `docker/minio/policies/` dan `docker/minio/cors/` juga sudah ada. Nama `compose.dev.yaml` (lama) tidak berlaku, diganti `docker/compose.yaml` (lihat banner Task 3); nama `docker/nginx/elzara.dev.conf` (lama) tidak berlaku, diganti `docker/nginx/dev.conf`. Skrip belum pernah dijalankan terhadap MinIO hidup; enam bucket + presign + finalize masih harus dibuktikan dengan test.

Keputusan D-S0-7 yang mengikat: **dua** endpoint S3. `AWS_ENDPOINT_URL=http://minio:9000` untuk client internal API (operasi server-side). `MINIO_PUBLIC_ENDPOINT=https://localhost/storage` dipakai **hanya** untuk menandatangani URL yang dilihat browser, di-proxy nginx edge ke `minio:9000` (Blueprint §11, Doc 11 B-9); `use_path_style_endpoint=true` sehingga object key muncul sebagai `/storage/<bucket>/<uuid>`. `.env.example` dan berkas nginx dev adalah milik Task 3/2 — langkah ini hanya **menambah** empat baris variabel dan satu blok `location`. Bucket ketujuh tidak dibuat (Doc 11 §8.7, backup wajib off-node).

**Files:**
- Create: `docker/minio/provision-buckets.sh`, `docker/minio/policies/elzara-app.json`, `docker/minio/cors/bucket-cors.json`
- Create: `apps/api/app/Platform/Infrastructure/Storage/{S3ClientFactory,BrowserPresigner}.php`
- Create (`.../app/Modules/Content/`): `Infrastructure/Models/Media.php`, `Domain/Events/MediaValidated.php`, `Domain/MediaValidationFailed.php`, `Application/Media/{MediaService,MagicByteValidator}.php`, `Application/Commands/{PresignMediaUpload,FinalizeMediaUpload,SignMediaDownload}.php`, `Infrastructure/Http/Controllers/MediaController.php`, `Tests/Feature/{PresignHostTest,MediaFinalizeTest,BucketInventoryTest,ConsoleExposureTest}.php`
- Create: `apps/api/routes/media.php`, `apps/api/config/media.php`
- Modify: `apps/api/config/filesystems.php`, `apps/api/.env.example`, `apps/api/bootstrap/app.php` (satu baris `withRouting` untuk `routes/media.php`), `docker/nginx/elzara.dev.conf`, `packages/api-contracts/openapi.yaml`, `docs/runbooks/object-storage-unreachable.md`

- [ ] **Step 1: Test yang diminta khusus oleh B-9, gagal lebih dulu** — `PresignHostTest.php`:

```php
public function test_host_presign_adalah_endpoint_publik_bukan_hostname_docker(): void {
    $url = app(MediaService::class)->presignUpload('product-media', $key = Str::uuid()->toString().'.jpg', 'image/jpeg');
    $this->assertSame('localhost', parse_url($url, PHP_URL_HOST), 'host harus MINIO_PUBLIC_ENDPOINT');
    $this->assertStringNotContainsString('minio:9000', $url); $this->assertNotSame('minio', parse_url($url, PHP_URL_HOST));
    $this->assertMatchesRegularExpression('#^https://localhost/storage/product-media/[0-9a-f-]{36}\.jpg\?#', $url);
    $this->assertStringContainsString('X-Amz-Expires=600', $url); }   // short-lived
```

- [ ] **Step 2: `composer dev:test -- PresignHostTest MediaFinalizeTest BucketInventoryTest ConsoleExposureTest`** → merah (`MINIO_PUBLIC_ENDPOINT` null, disk `product-media` tidak terdaftar).

- [ ] **Step 3: Provisioning idempoten** — `docker/minio/provision-buckets.sh` (satu service `mc` + `aws` di compose.dev.yaml, sekali jalan, aman diulang):

```sh
#!/usr/bin/env sh
set -eu
MC="mc --config-dir /tmp/mc"
$MC alias set local "$AWS_ENDPOINT_URL" "$AWS_ACCESS_KEY_ID" "$AWS_SECRET_ACCESS_KEY" >/dev/null
for b in product-media request-attachments invoice-documents shipment-documents exports audit-archives; do
  $MC mb --ignore-existing "local/$b"; $MC anonymous set private "local/$b"
  aws --endpoint-url "$AWS_ENDPOINT_URL" s3api put-bucket-cors --bucket "$b" --cors-configuration file:///minio/cors/bucket-cors.json
done
$MC anonymous set download local/product-media        # satu-satunya yang boleh dibaca anonim (via CDN — ADR-008)
$MC ilm add --expire-days 7 local/exports             # TTL bucket exports
$MC admin user svcacct add local "$AWS_ACCESS_KEY_ID" --access-key "$ELZARA_S3_ACCESS_KEY" --secret-key "$ELZARA_S3_SECRET_KEY" \
  --policy /minio/policies/elzara-app.json || $MC admin user svcacct ls local "$AWS_ACCESS_KEY_ID" | grep -q "$ELZARA_S3_ACCESS_KEY"
test "$($MC ls local --json | jq -s '[.[] | select(.type=="folder")] | length')" -eq 6
```

- [ ] **Step 4: Least privilege per bucket + CORS tanpa wildcard.** `policies/elzara-app.json` (service account aplikasi; `audit-archives` tanpa hak baca, `exports` tanpa delete, tanpa `ListAllMyBuckets`/`DeleteBucket`):

```json
{"Version":"2012-10-17","Statement":[
 {"Effect":"Allow","Action":["s3:PutObject","s3:GetObject","s3:AbortMultipartUpload"],"Resource":["arn:aws:s3:::product-media/*","arn:aws:s3:::request-attachments/*","arn:aws:s3:::invoice-documents/*","arn:aws:s3:::shipment-documents/*"]},
 {"Effect":"Allow","Action":["s3:PutObject","s3:GetObject"],"Resource":["arn:aws:s3:::exports/*"]},
 {"Effect":"Allow","Action":["s3:PutObject"],"Resource":["arn:aws:s3:::audit-archives/*"]},
 {"Effect":"Deny","Action":["s3:GetObject"],"Resource":["arn:aws:s3:::audit-archives/*"]},
 {"Effect":"Deny","Action":["s3:DeleteBucket","s3:ListAllMyBuckets"],"Resource":["arn:aws:s3:::*"]}]}
```

`cors/bucket-cors.json`: `{"CORSRules":[{"AllowedOrigins":["https://localhost","http://localhost:3000","http://localhost:3001"],"AllowedMethods":["PUT","GET","HEAD"],"AllowedHeaders":["Content-Type"],"ExposeHeaders":["ETag"],"MaxAgeSeconds":300}]}` — origin dev sama dengan `WEB_ORIGIN`/`ADMIN_ORIGIN` milik Task 3, tidak ada `*`.

- [ ] **Step 5: Enam disk** — `config/filesystems.php` (disk per bucket + satu kunci publik yang dibaca presigner):

```php
$bucketDisks = [];
foreach (['product-media','request-attachments','invoice-documents','shipment-documents','exports','audit-archives'] as $b) {
    $bucketDisks[$b] = ['driver' => 's3', 'key' => env('ELZARA_S3_ACCESS_KEY'), 'secret' => env('ELZARA_S3_SECRET_KEY'), 'bucket' => $b,
        'region' => env('AWS_DEFAULT_REGION', 'elzara-1'), 'endpoint' => env('AWS_ENDPOINT_URL'),
        'use_path_style_endpoint' => true, 'throw' => true, 'visibility' => $b === 'product-media' ? 'public' : 'private']; }
return ['minio_public_endpoint' => env('MINIO_PUBLIC_ENDPOINT'), 'disks' => array_merge($disks, $bucketDisks)];
```

`config/media.php`: `max_bytes` = `20 * 1024 * 1024`, `allowed_mime` = `['image/jpeg','image/png','image/webp','application/pdf']`.

- [ ] **Step 6: Presigner browser** — client kedua dibangun khusus untuk tanda tangan yang dilihat browser:

```php
final class S3ClientFactory { public static function browser(): S3Client {
    return new S3Client(['version' => 'latest', 'region' => env('AWS_DEFAULT_REGION', 'elzara-1'),
        'credentials' => ['key' => env('ELZARA_S3_ACCESS_KEY'), 'secret' => env('ELZARA_S3_SECRET_KEY')],
        'endpoint' => rtrim((string) config('filesystems.minio_public_endpoint'), '/'), 'use_path_style_endpoint' => true,
        'http' => ['verify' => filter_var(env('MINIO_PUBLIC_TLS_VERIFY', 'false'), FILTER_VALIDATE_BOOLEAN)]]); } }
final class BrowserPresigner { public function __construct(private readonly S3Client $c) {}
    public function put(string $bucket, string $key, string $mime, int $ttl): string {
        return (string) $this->c->getPresignedRequest(new Command('PutObject',
            ['Bucket' => $bucket, 'Key' => $key, 'ContentType' => $mime, 'ServerSideEncryption' => 'AES256']), "+{$ttl} seconds")->getUri(); }
    public function get(string $bucket, string $key, int $ttl): string {
        return (string) $this->c->getPresignedRequest(new Command('GetObject', ['Bucket' => $bucket, 'Key' => $key]), "+{$ttl} seconds")->getUri(); } }
final class MediaService { public function __construct(private readonly BrowserPresigner $p) {}
    public function presignUpload(string $bucket, string $uuidKey, string $mime): string {   // key UUID, extension tidak pernah dipercaya
        throw_unless(in_array($mime, config('media.allowed_mime'), true), new MediaValidationFailed(__('media.mime_not_allowed')));
        return $this->p->put($bucket, $uuidKey, $mime, 600); }
    public function signedDownload(string $bucket, string $uuidKey): string { return $this->p->get($bucket, $uuidKey, 120); } }
```

Unduh privat selalu lewat `signedDownload` TTL 120 s; tidak ada path objek yang bisa ditebak karena key = UUID. MIME yang ditolak saat presign memakai exception + `code` yang sama dengan finalize (satu baris `MediaValidationFailed` di Doc 12 §4.3 mencakup MIME/magic-byte/ukuran) supaya klien tidak perlu dua cabang parsing; kunci `media.*` didaftarkan di `lang/{id,en}/media.php` milik Task 14 langkah 3 — sebelum task itu `__()` mengembalikan key apa adanya, dan test Task 13 hanya mengassert `code`, bukan pesan.

- [ ] **Step 7: `MagicByteValidator` nyata** — deteksi dari isi, `finfo` hanya fallback:

```php
final class MagicByteValidator {
    public const HEAD_BYTES = 16;
    private const SIG = ['image/jpeg' => ["\xFF\xD8\xFF"], 'image/png' => ["\x89PNG\x0D\x0A\x1A\x0A"],
        'application/pdf' => ['%PDF-'], 'image/webp' => ['RIFF']];
    public function detect(string $head): ?string {
        foreach (self::SIG as $mime => $prefixes) { if (!str_starts_with($head, $prefixes[0])) { continue; }
            if ($mime === 'image/webp' && substr($head, 8, 4) !== 'WEBP') { continue; } return $mime; }
        $probe = (new finfo(FILEINFO_MIME_TYPE))->buffer($head);
        return array_key_exists($probe, self::SIG) ? $probe : null; }
    public function assert(string $head, string $claimed, int $size): string {
        $real = $this->detect($head) ?? throw new MediaValidationFailed(__('media.magic_unknown'));
        if ($real !== $claimed) { throw new MediaValidationFailed(__('media.magic_mismatch', ['claimed' => $claimed, 'actual' => $real])); }
        if ($size > config('media.max_bytes')) { throw new MediaValidationFailed(__('media.too_large')); } return $real; } }
```

`FinalizeMediaUpload` mengambil 16 byte pertama objek (`Storage::disk($bucket)->stream()` dibaca `fread` 16), memanggil `assert()`, mengisi `mime`/`size_bytes`/`sha256` (`hash_file`)/`width`+`height` (`getimagesizefromstring`), menyetel `is_moderated=true` + `moderation_status='pending'` untuk `kind in (proof, attachment)`, lalu `OutboxWriter::record(MediaValidated::event($media->id, $media->model_type, $media->model_id, $media->kind, 'validated', null))` in-transaction — dispatcher antrian `media`. Dua kelas pendukung, keduanya ikut kolom baris `MediaValidated` Doc 12 §6.2 (`media_id`, `model_type`, `model_id`, `kind`, `outcome`, `reason_code?`):

```php
// Domain/Events/MediaValidated.php
final class MediaValidated { public function __construct(public readonly int $mediaId, public readonly ?string $modelType,
    public readonly ?int $modelId, public readonly string $kind, public readonly string $outcome, public readonly ?string $reasonCode) {}
    public static function event(int $id, ?string $mt, ?int $mi, string $kind, string $outcome, ?string $reason): self {
        return new self($id, $mt, $mi, $kind, $outcome, $reason); }
    public function payload(): array { return ['media_id' => $this->mediaId, 'model_type' => $this->modelType, 'model_id' => $this->modelId,
        'kind' => $this->kind, 'outcome' => $this->outcome, 'reason_code' => $this->reasonCode]; } }
// Domain/MediaValidationFailed.php  (nama exception sudah terdaftar, bukan karangan)
final class MediaValidationFailed extends DomainException {}
```

Penolakan = `MediaValidationFailed` → **422 `MEDIA_VALIDATION_FAILED` / `urn:elzara:problem:media:validation-failed`** (sudah terdaftar Doc 12 §4.3 + Doc 01 §12; tidak mengarang nama). `Infrastructure/Models/Media.php` = `class Media extends Model { protected $table='media'; protected $guarded=[]; protected $casts=['is_moderated'=>'boolean','width'=>'integer','height'=>'integer','size_bytes'=>'integer','position'=>'integer','sha256'=>'string']; }` (Eloquent selalu di `Infrastructure/Models`, Doc 01 §3.2 baris 240).

- [ ] **Step 8: Rute + spec, satu langkah.** `routes/media.php`: `POST /api/v1/admin/media/presign` (`identity.perm:media.upload`), `POST /api/v1/admin/media/{media_id}/finalize`, `POST /api/v1/admin/media/{media_id}/sign`. Tambah di `openapi.yaml` dengan `operationId` `adminMediaPresign|adminMediaFinalize|adminMediaSign` (semua 4xx memakai `ApiProblem`) + skema:

```yaml
components: { schemas: { MediaPresignResult: { type: object, required: [upload_url, object_key, media_id, expires_at], properties: {
  upload_url: { type: string, format: uri, description: "host selalu MINIO_PUBLIC_ENDPOINT, tidak pernah hostname Docker" },
  object_key: { type: string, format: uuid }, media_id: { type: integer, format: int64 }, expires_at: { type: string, format: date-time } } } } } }
```

- [ ] **Step 9: nginx edge + `.env.example`** — tambah **satu** blok `location` di `docker/nginx/elzara.dev.conf` (berkas milik Task 2; blok lain tidak disentuh):

```nginx
location /storage/ {                 # presigned URL browser -> MinIO, origin sama dgn storefront
  proxy_pass http://minio:9000/; proxy_set_header Host $host; proxy_request_buffering off; client_max_body_size 20m;
  add_header Access-Control-Allow-Origin $http_origin always; }
```

Tambah **empat baris** di `apps/api/.env.example` (nama + format contoh, nol rahasia): `MINIO_PUBLIC_ENDPOINT=https://localhost/storage`, `MINIO_PUBLIC_TLS_VERIFY=false`, `ELZARA_S3_ACCESS_KEY=`, `ELZARA_S3_SECRET_KEY=` — menutup aturan Doc 01 §11.3 "tidak ada variabel yang dibaca kode tapi tidak terdaftar".

- [ ] **Step 10: Test bucket, validasi, console (bukti b, c, d)** — `BucketInventoryTest`: jalankan `provision-buckets.sh` dua kali (assert exit 0 keduanya + keluaran `mc ls` identik) lalu assert daftar bucket tepat `["product-media","request-attachments","invoice-documents","shipment-documents","exports","audit-archives"]`; `MediaFinalizeTest`: byte `"%PDF-1.7 ..."` diklaim `image/jpeg` → `422` + `code=MEDIA_VALIDATION_FAILED` + objek tidak dianggap valid; `ConsoleExposureTest`: `curl -s -o /dev/null -w "%{exitcode}" http://localhost:9001` → exit code 7 (koneksi ditolak), bukan 200.

- [ ] **Step 11: Gerbang** — `docker compose -f docker/compose.dev.yaml exec mc sh /minio/provision-buckets.sh && composer dev:test -- PresignHostTest MediaFinalizeTest BucketInventoryTest ConsoleExposureTest OpenApiRouteParityTest` semuanya hijau.

- [ ] **Step 12: Tutup butir terbuka** — di `docs/runbooks/object-storage-unreachable.md` (baris ±68) ganti "nama variabel env khusus endpoint publik **belum ada** di mana pun yang terdokumentasi" menjadi: endpoint publik presign bernama **`MINIO_PUBLIC_ENDPOINT`**, terdaftar di `apps/api/.env.example` (satu pemilik daftar variabel, Doc 01 §11.3), di-proxy lewat `location /storage/`, diverifikasi per environment oleh `PresignHostTest`; keputusan bucket publik/privat + CDN yang digantung ADR-008 kini punya jawaban teknis (`product-media` satu-satunya `anonymous set download`) dan berkas ADR-008 ditutup di Task 19.

- [ ] **Step 13: Commit** — `git add docker/minio docker/nginx apps/api/config/filesystems.php apps/api/config/media.php apps/api/.env.example apps/api/app/Platform/Infrastructure/Storage apps/api/app/Modules/Content apps/api/routes/media.php packages/api-contracts/openapi.yaml docs/runbooks/object-storage-unreachable.md` + `git commit -m "feat(sprint-0): minio enam bucket + presign endpoint publik + finalize magic-byte"`.

### Task 14 — ID 0.10: `localizations` + `feature_flags` + `settings` + kill switch `checkout.enabled`

Yang diputuskan: satu jalur i18n API (default `id`, fallback eksplisit `en → id`, key hilang dikembalikan sebagai key agar tertangkap saat review, tidak pernah string kosong); kill switch dieksekusi **di middleware sebelum Command dan sebelum query apa pun**, dengan bentuk yang sudah terdaftar: **503 `CHECKOUT_DISABLED` / `urn:elzara:problem:server:checkout-disabled` / `retryable=true`** (Doc 12 §4.3, Doc 11 §8.7). Render halaman storefront adalah Task 16/17; bentuk respons konsumsinya didefinisikan + dinormakan di sini.

**Files:**
- Create (`.../app/Modules/Content/`): `Application/I18n/{LocalizationResolver,LocalizedStringRepository}.php`, `Infrastructure/Models/LocalizedString.php`, `Tests/Feature/LocalizationResolverTest.php`
- Create (`.../app/Modules/Settings/`): `Infrastructure/Models/{FeatureFlag,FeatureFlagOverride,Setting}.php`, `Domain/{MissingFeatureFlag,CheckoutDisabled}.php`, `Application/FeatureFlagService.php`, `Application/Commands/{GetPublicFeatureFlags,UpdateFeatureFlags}.php`, `Infrastructure/Http/Controllers/{FeatureFlagController,AdminFeatureFlagController}.php`, `Infrastructure/Http/Middleware/EnsureCheckoutEnabled.php`, `Tests/Feature/{KillSwitchTest,FlagOverrideTest,AdminFlagIdempotencyTest,NoHardcodedUiStringTest}.php`
- Create (`.../app/Modules/Audit/`): `Infrastructure/Models/AuditLog.php`, `Application/AuditLogger.php` (tabelnya dibuat Task 11; penulisnya dibutuhkan pertama kali di sini)
- Create (`.../app/Modules/Orders/`): `Infrastructure/Http/Controllers/PlaceOrderController.php`, `Application/Commands/PlaceOrder.php` — **hanya** stub bertipe untuk membuktikan kill switch menolak sebelum Command jalan (lihat catatan #13)
- Create: `apps/api/config/feature_flags.php`, `apps/api/routes/flags.php`, `apps/api/lang/{id,en}/{messages,validation,checkout,media}.php`
- Modify: `packages/api-contracts/openapi.yaml`, `apps/api/routes/api.php` (satu baris), `apps/api/bootstrap/app.php`

- [ ] **Step 1: Test kill switch, gagal lebih dulu** — `KillSwitchTest.php`:

```php
public function test_flag_mati_menolak_sebelum_command(): void {
    FeatureFlag::where('code','checkout.enabled')->update(['default_enabled' => false]);
    FeatureFlagOverride::where('flag_code','checkout.enabled')->delete();
    Bus::fake();
    $this->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/orders', [])->assertStatus(503)
        ->assertJsonPath('type', 'urn:elzara:problem:server:checkout-disabled')->assertJsonPath('code', 'CHECKOUT_DISABLED')
        ->assertJsonPath('retryable', true)->assertJsonPath('detail', __('checkout.disabled'))   // alasan, bukan error mentah
        ->assertJsonStructure(['type','title','status','detail','instance','request_id','code','retryable']);
    Bus::assertNotDispatched(PlaceOrder::class); }
```

- [ ] **Step 2: `composer dev:test -- KillSwitchTest LocalizationResolverTest AdminFlagIdempotencyTest`** → merah (`checkout.disabled` mengembalikan key; rute `feature-flags` 404).

- [ ] **Step 3: Resource string UI sisi API** (semua string yang lahir di Sprint 0, ID + EN; nol literal di controller):

```php
// lang/id/checkout.php: return ['disabled' => 'Checkout sedang dimatikan untuk perbaikan. Coba lagi sebentar.'];
// lang/en/checkout.php: return ['disabled' => 'Checkout is temporarily disabled for maintenance. Please try again shortly.'];
// lang/{id,en}/messages.php: auth.throttled, auth.invalid, flag.unknown, flag.override_wins
// lang/{id,en}/media.php: mime_not_allowed, magic_unknown, magic_mismatch, too_large  (dipakai Task 13)
// lang/{id,en}/validation.php: pesan validator per aturan yang dipakai Task 12.
```

`LocalizationResolver` (DB → resource → key) + `ResolveLocale` dari Task 12 memanggil `App::setLocale()` memakai resolver:

```php
final class LocalizationResolver {
    public const SUPPORTED = ['id', 'en'];
    public function locale(?string $preferred): string { return in_array($preferred, self::SUPPORTED, true) ? $preferred : 'id'; }
    public function value(string $key, string $group, ?string $locale = null, ?int $plural = null): string {
        $locale = $this->locale($locale);
        if (($v = LocalizedStringRepository::cached()->find($key, $group, $locale, $plural)) !== null) { return $v; }
        if ($locale !== 'id') { return $this->value($key, $group, 'id', $plural); }   // fallback eksplisit
        return $key; } }                                                              // key hilang => tertangkap saat review
// LocalizedStringRepository::cached(): Cache::remember("i18n.{$group}.{$locale}", 300, fn () => LocalizedString::where(compact('group','locale'))->pluck('value','key')), di-forget saat seeder/admin menulis.
```

- [ ] **Step 4: `FeatureFlagService`** — resolusi persis kolom yang ada di Doc 09 §15 (`flag_code`, `env`, `enabled`, `rollout_percent`); tidak ada mekanisme di luar kolom itu. Kode flag tidak dikenal → `Domain/MissingFeatureFlag` (`final class MissingFeatureFlag extends ModelNotFoundException { public function __construct(public readonly string $code) { parent::__construct("flag {$code} tidak terdaftar"); } }`) yang `ApiProblemRenderer` petakan ke **404 `NOT_FOUND` / `urn:elzara:problem:not-found:resource`** lewat baris "tidak ditemukan" Doc 12 §4.3 — URN baru tidak dikarang:

```php
final class FeatureFlagService {
    public function enabled(string $code, ?User $subject = null): bool {
        $flag = FeatureFlag::query()->firstWhere('code', $code) ?? throw new MissingFeatureFlag($code);
        $env = match (true) { app()->environment('production') => 'prod', app()->environment('staging') => 'staging', default => 'local' };
        $ov = FeatureFlagOverride::query()->where(['flag_code' => $code, 'env' => $env])->first();
        if (!($ov?->enabled ?? $flag->default_enabled)) { return false; }
        $percent = $ov?->rollout_percent ?? 100;
        return $percent >= 100 || crc32((string) ($subject?->id ?? 'anon')) % 100 < $percent; }
    public function publicFlags(?User $subject): array {   // hanya flag yang aman diketahui publik
        return array_map(fn (string $c) => ['code' => $c, 'enabled' => $this->enabled($c, $subject)], config('feature_flags.public_codes')); } }
// config/feature_flags.php: 'public_codes' => ['checkout.enabled','drops.enabled','reviews.enabled','guest_checkout.enabled','loyalty.enabled','marketplace_purchase.enabled']
// auth.social_google TIDAK pernah keluar (membocorkan konfigurasi provider). GET /api/v1/feature-flags => {"data":[{"code":"checkout.enabled","enabled":false}]}
```

- [ ] **Step 5: Middleware + dua stub Orders + satu baris rute.** `EnsureCheckoutEnabled::handle()` = `if (!$this->flags->enabled('checkout.enabled', $r->user())) { throw new CheckoutDisabled(__('checkout.disabled')); }` dengan `final class CheckoutDisabled extends ServiceUnavailableHttpException { public function __construct(string $m) { parent::__construct(null, null, [$m]); } }` → renderer Task 0.5 memetakannya ke 503 di atas, tidak ada body berisi stack trace. Alias didaftarkan di `bootstrap/app.php`: `$middleware->alias('identity.checkout', EnsureCheckoutEnabled::class);`. Dua stub (B2: controller hanya mem-validasi lalu men-dispatch Command): `PlaceOrderController::handle(Request $r): JsonResponse` = `Bus::dispatch(new PlaceOrder($r->user(), $r->validate(['items' => ['required', 'array', 'min:1']]))); return response()->json(['status' => 'queued'], 202);` dan `final class PlaceOrder { public function __construct(public ?User $actor = null, public array $cart = []) {} public function handle(): void { /* transisi penuh + outbox OrderPlaced = Sprint 2 */ } }`. Di `routes/api.php` stub Task 0.5 menjadi `Route::post('/orders', PlaceOrderController::class)->middleware('identity.checkout');`; KillSwitchTest membuktikan HTTP 503 keluar **sebelum** `PlaceOrder` disentuh (`Bus::assertNotDispatched(PlaceOrder::class)`).

- [ ] **Step 6: Penulis `audit_logs` (modul Audit; tabelnya dibuat Task 11, penulisnya dibutuhkan pertama kali di sini).** `Infrastructure/Models/AuditLog.php` = `class AuditLog extends Model { protected $table = 'audit_logs'; protected $guarded = []; public $timestamps = false; protected $casts = ['before' => 'array', 'after' => 'array', 'occurred_at' => 'immutable_datetime']; }` (append-only S-6: tanpa `updated_at`, tanpa jalur update) + `Application/AuditLogger.php`:

```php
final class AuditLogger {   // dipanggil Command (B2), tidak pernah dipanggil controller
    public function __construct(private readonly ConnectionInterface $db) {}
    public function record(string $action, Model $target, ?array $before, ?array $after, string $reason, ?User $actor): void {
        $this->db->table('audit_logs')->insert(['actor_id' => $actor?->id, 'action' => $action, 'target_type' => $target->getTable(),
            'target_id' => $target->getKey(), 'before' => json_encode($before), 'after' => json_encode($after), 'reason' => $reason,
            'ip' => request()->ip(), 'user_agent' => substr((string) request()->userAgent(), 0, 255), 'occurred_at' => now()]); } }
```

`AdminFlagIdempotencyTest` ikut men-assert `reason` yang hanya berisi spasi → `QueryException` kena `ck_audit_logs_reason` (alasan audit tidak boleh kosong).

- [ ] **Step 7: Endpoint + `IdempotencyGuard` di PATCH admin.** `routes/flags.php`: `GET /api/v1/feature-flags` (publik, `Cache-Control: no-store`), `GET|PATCH /api/v1/admin/feature-flags` di belakang `identity.perm:flag.read|flag.update`. `UpdateFeatureFlags`:

```php
public function handle(FeatureFlagService $flags, IdempotencyGuard $guard, AuditLogger $audit): array {
    return $guard->guard(actorId: $this->actor->id, operation: 'adminFeatureFlagsPatch', key: $this->idempotencyKey,
      payload: $this->flags, ttl: 86400, handler: function () use ($flags, $audit) {
        foreach ($this->flags as $row) {
            $f = FeatureFlag::query()->firstWhere('code', $row['code']) ?? throw new MissingFeatureFlag($row['code']);
            $before = ['default_enabled' => $f->default_enabled];
            $f->update(['default_enabled' => $row['default_enabled'], 'updated_by' => $this->actor->id]);
            if (array_key_exists('enabled', $row)) { FeatureFlagOverride::query()->updateOrCreate(
                ['flag_code' => $f->code, 'env' => $row['env']], ['enabled' => $row['enabled'],
                'rollout_percent' => $row['rollout_percent'] ?? 100, 'updated_by' => $this->actor->id]); }
            $audit->record(action: 'flag.update', target: $f, before: $before, after: ['default_enabled' => $f->default_enabled],
                reason: $this->reason, actor: $this->actor); }                    // reason wajib => ck_audit_logs_reason
        return ['all' => FeatureFlag::query()->get(), 'public' => $flags->publicFlags($this->actor)]; } }); }
```

`Idempotency-Key` hilang → `400 urn:elzara:problem:idempotency:key-required`; key sama + payload beda → `409 …:idempotency:key-payload-mismatch` (kedua mekanisme milik Task 6/10, tidak didefinisikan ulang). Spec di langkah yang sama:

```yaml
paths:
  /feature-flags: { get: { operationId: publicFeatureFlags, responses: { '200': { content: { application/json: { schema: { type: object,
    required: [data], properties: { data: { type: array, items: { $ref: '#/components/schemas/FeatureFlagPublic' } } } } } } } } } }
  /admin/feature-flags: { get: { operationId: adminFeatureFlagsList, responses: { '200': { description: flag + override },
      '401': { $ref: '#/components/responses/Unauthenticated' }, '403': { $ref: '#/components/responses/Forbidden' } } },
    patch: { operationId: adminFeatureFlagsPatch, parameters: [{ name: Idempotency-Key, in: header, required: true, schema: { type: string, format: uuid } }],
      requestBody: { required: true, content: { application/json: { schema: { type: object, required: [flags, reason], properties: {
        reason: { type: string, minLength: 5, maxLength: 320 },
        flags: { type: array, minItems: 1, items: { type: object, required: [code, default_enabled], properties: { code: { type: string, maxLength: 64 },
          default_enabled: { type: boolean }, env: { type: string, enum: [local, staging, prod] }, enabled: { type: boolean },
          rollout_percent: { type: integer, minimum: 0, maximum: 100 } } } } } } } } },
      responses: { '200': { description: hasil baru }, '400': { description: key hilang }, '403': { description: hak kurang }, '409': { description: key dipakai dengan payload lain } } } } }
components: { schemas: { FeatureFlagPublic: { type: object, required: [code, enabled], properties: { code: { type: string, maxLength: 64 }, enabled: { type: boolean } } } },
  responses: { Unauthenticated: { description: tanpa identitas, content: { application/problem+json: { schema: { $ref: '#/components/schemas/ApiProblem' } } } },
    Forbidden: { description: role tidak cukup, content: { application/problem+json: { schema: { $ref: '#/components/schemas/ApiProblem' } } } } } }
```

- [ ] **Step 8: Test override + grep anti-hard-code.** `FlagOverrideTest` assert `default_enabled=true` + override `env=prod, enabled=false` → mati sementara `env=local` tidak memengaruhi `prod`; `rollout_percent=0` → mati untuk subjek apa pun. `AdminFlagIdempotencyTest` assert replay body identik + header `Idempotency-Replayed: true`, dan key sama dengan `flags` berbeda → 409. `NoHardcodedUiStringTest`:

```php
public function test_string_respons_api_selalu_lewat_resource(): void {
    $hits = [];
    foreach (Finder::create()->files()->name('*.php')->in(['app/Modules','app/Platform']) as $f) {
        if (preg_match('/(?:\b(?:message|detail|title)\s*=>\s*["\']|[\'"])[A-Za-z][A-Za-z ]{12,}[\'"]\s*[,)]/', file_get_contents($f->getPathname()))) { $hits[] = $f->getRelativePathname(); } }
    $this->assertSame([], $hits, 'literal di respons: '.implode(', ', $hits)); }
```

- [ ] **Step 9: Gerbang** — `composer dev:test -- KillSwitchTest FlagOverrideTest LocalizationResolverTest AdminFlagIdempotencyTest NoHardcodedUiStringTest OpenApiRouteParityTest` hijau; `composer dev:artisan tinker --execute="echo json_encode(app(FeatureFlagService::class)->publicFlags(null));"` mencetak tepat enam kode di `public_codes`.

- [ ] **Step 10: Commit** — `git add apps/api/app/Modules/Settings apps/api/app/Modules/Content apps/api/app/Modules/Audit apps/api/app/Modules/Orders apps/api/config/feature_flags.php apps/api/lang apps/api/routes apps/api/bootstrap/app.php packages/api-contracts/openapi.yaml` + `git commit -m "feat(sprint-0): i18n id/en + feature flag service + kill switch checkout 503"`.

### Task 15 — ID 0.11: `ReferenceSeeder` idempoten vs `DemoSeeder` dev-only + factories Sprint 0

Pemilik data referensi = `ReferenceSeeder` (Doc 10 §8, M-12). `DatabaseSeeder` hanya memanggil `ReferenceSeeder` — Doc 10 §8 baris 311 mencatat bentuk `migrate:fresh --seed --class=Kelas` tidak ada di Laravel, jadi `DatabaseSeeder` satu-satunya pintu `--seed` yang aman. Aturan turunan yang ditegakkan: **insert-if-absent, tidak pernah `update`** (seeder tidak menimpa hasil edit admin). `DemoSeeder` menolak production secara eksplisit. Semua gambar Demo adalah SVG orisinal yang ditulis sendiri — nol byte dari situs referensi (Doc 01 §15, spec §7).

**Files:**
- Create: `apps/api/database/seeders/DatabaseSeeder.php`, `database/seeders/Reference/{ReferenceSeeder,RolesAndPermissionsSeeder,FeatureFlagsSeeder,SettingsSeeder,LocalizationsSeeder}.php`, `database/seeders/Demo/{DemoSeeder,DemoAccountSeeder,DemoMediaSeeder}.php`, `database/seeders/Demo/catalog-manifest.php`, `database/seeders/Demo/assets/product-01.svg` … `product-10.svg`
- Create: `apps/api/database/factories/{UserFactory,AddressFactory,CustomerProfileFactory,ConsentFactory,OtpChallengeFactory,UserSessionFactory,RoleFactory,PermissionFactory,PermissionRoleFactory,RoleUserFactory,StaffTwoFactorSecretFactory,MediaFactory,LocalizedStringFactory,FeatureFlagFactory,FeatureFlagOverrideFactory,SettingFactory,AuditLogFactory}.php`
- Create: `apps/api/tests/Schema/{ReferenceSeedIdempotencyTest,AssetProvenanceTest}.php`, `apps/api/app/Modules/Identity/Tests/Feature/ProductionSeedGuardTest.php`
- Modify: `apps/api/composer.json` + `newFactory()` pada **setiap** model Eloquent yang punya factory di langkah 9: `app/Modules/Identity/Infrastructure/Models/{User,Role,Permission,CustomerProfile,Consent,OtpChallenge,UserSession,Address,StaffTwoFactorSecret,PermissionRole,RoleUser}.php`, `app/Modules/Content/Infrastructure/Models/{Media,LocalizedString}.php`, `app/Modules/Settings/Infrastructure/Models/{FeatureFlag,FeatureFlagOverride,Setting}.php`, `app/Modules/Audit/Infrastructure/Models/AuditLog.php` — satu baris per model: `protected static function newFactory(): string { return \Database\Factories\<Nama>Factory::class; }`

- [ ] **Step 1: Test penjaga production, gagal lebih dulu** — `ProductionSeedGuardTest.php`:

```php
public function test_demoseeder_ditolak_di_production(): void {
    $this->expectException(RuntimeException::class);
    $this->expectExceptionMessage('DemoSeeder tidak boleh berjalan di environment production');
    with($this->app, function ($app) { $app['env'] = 'production'; });
    try { (new DemoSeeder())->run(); } finally { $this->app['env'] = 'testing'; } }
public function test_databaseseder_tidak_pernah_memanggil_demoseeder(): void {
    $this->assertStringNotContainsString('DemoSeeder', file_get_contents(base_path('database/seeders/DatabaseSeeder.php'))); }
```

- [ ] **Step 2: `composer dev:test -- ProductionSeedGuardTest ReferenceSeedIdempotencyTest AssetProvenanceTest`** → merah (`Class "Database\Seeders\DemoSeeder" not found`).

- [ ] **Step 3: `DatabaseSeeder` + guard `DemoSeeder`:**

```php
class DatabaseSeeder extends Seeder { public function run(): void { $this->call(ReferenceSeeder::class); } }
class DemoSeeder extends Seeder { public function run(): void {
    if (app()->environment('production')) { throw new RuntimeException('DemoSeeder tidak boleh berjalan di environment production'); }
    $this->call([DemoAccountSeeder::class, DemoMediaSeeder::class]); } }
class ReferenceSeeder extends Seeder { public function run(): void {
    $this->call([RolesAndPermissionsSeeder::class, FeatureFlagsSeeder::class, SettingsSeeder::class, LocalizationsSeeder::class]); } }
```

- [ ] **Step 4: `RolesAndPermissionsSeeder`** — sepuluh role Blueprint, tidak ada kesebelas; permission dari `config/identity.permissions.php` (Task 12, satu sumber); bentuk spatie (amandemen 2026-11-18, ADR-012 (RBAC/identitas/dependensi)): `name` + `guard_name='admin'`, bundel via `syncPermissions`; pivot user (`model_has_roles`) **tidak** di-seed (nol kredensial di repo; akun bootstrap dibuat lewat perintah `identity:bootstrap-admin` milik Task 19):

```php
$roles = ['superadmin' => 'Superadmin', 'catalog-manager' => 'Catalog Manager', 'sales-ops' => 'Sales Ops', 'preorder-ops' => 'Pre-Order Ops',
 'sourcing-ops' => 'Sourcing Ops', 'warehouse-staff' => 'Warehouse Staff', 'procurement-staff' => 'Procurement Staff', 'finance' => 'Finance',
 'customer-support' => 'Customer Support', 'analyst' => 'Analyst (read-only)'];
foreach ($roles as $name => $label) { Role::findOrCreate($name, 'admin'); }   // roles.name = kode Blueprint; label masuk description
foreach (config('identity.permissions') as $group => $names) { foreach ($names as $p) { Permission::findOrCreate($p, 'admin'); } }
// bundel per role dibaca dari config('identity.roles') (Task 12, satu sumber; matriks lengkap dimiliki Doc 06) —
// superadmin = bundel SEMUA permission granular, bukan wildcard (bentuk '*.*' lama tidak berlaku); analyst = bundel *.view relevan.
foreach (config('identity.roles') as $name => $def) { Role::findByName($name, 'admin')->syncPermissions($def['permissions']); }
$this->assertSame(10, Role::count());   // bukan sepuluh => seeder gagal keras
```

- [ ] **Step 5: `FeatureFlagsSeeder` + `SettingsSeeder` + `LocalizationsSeeder`** — kode flag hanya dari katalog Doc 09 §15; `checkout.enabled` default ON karena ia kill switch yang memang hidup di Sprint 0:

```php
FeatureFlag::firstOrCreate(['code' => 'checkout.enabled'], ['description' => 'Kill switch checkout (Doc 11 §8.7, runbook emergency-disable-checkout)',
    'default_enabled' => true, 'requires_restart' => false, 'updated_by' => null]);
foreach (['marketplace_purchase.enabled','reviews.enabled','loyalty.enabled','guest_checkout.enabled','drops.enabled','auth.social_google'] as $c) {
    FeatureFlag::firstOrCreate(['code' => $c], ['description' => $c, 'default_enabled' => false, 'requires_restart' => false, 'updated_by' => null]); }
foreach (['money.rounding','po.reconsent_threshold_percent','intl_fee.auto_issue_cap','wa.provider','tax.ems_basis'] as $c) {
    Setting::firstOrCreate(['key' => $c], ['group_name' => 'system', 'value_type' => 'string', 'is_public' => false,
        'value' => ['v' => ''], 'default_value' => ['v' => ''], 'updated_by' => null]); }
foreach (['shop.checkout.disabled' => ['id' => 'Nonaktif sementara', 'en' => 'Temporarily disabled'],
          'admin.flag.title' => ['id' => 'Feature Flags', 'en' => 'Feature Flags'],
          'media.alt.default' => ['id' => 'Foto produk', 'en' => 'Product photo']] as $key => $byLocale) {
    foreach ($byLocale as $locale => $text) { LocalizedString::firstOrCreate(['key' => $key, 'locale' => $locale, 'group_name' => 'shop'], ['value' => $text]); } }
```

`settings.updated_by` dan `feature_flags.updated_by` dibuat nullable di `0020`/`0021` (Task 11 langkah 7) justru supaya seeder tidak perlu menanam user — Doc 09 §15 menulis `NOT NULL REFERENCES users(id)`; konflik ada di catatan #7.

- [ ] **Step 6: Test idempotensi C7** — `ReferenceSeedIdempotencyTest.php` (hash snapshot sebelum/sesudah run kedua):

```php
private function hashSnapshot(): string { $parts = [];
    foreach (['roles','permissions','role_has_permissions','feature_flags','feature_flag_overrides','settings','localizations'] as $t) {
        $rows = DB::table($t)->orderBy('id')->get()->map(fn (object $r) => json_encode(get_object_vars($r)))->implode('|');
        $parts[] = $t.':'.sha1($rows); }
    return sha1(implode(';', $parts)); }
public function test_dijalankan_dua_kali_nol_perubahan(): void {
    (new ReferenceSeeder())->run(); $first = $this->hashSnapshot(); (new ReferenceSeeder())->run();
    $this->assertSame($first, $this->hashSnapshot());                                     // C7
    $this->assertSame(10, DB::table('roles')->count());
    $this->assertTrue(DB::table('feature_flags')->where(['code' => 'checkout.enabled', 'default_enabled' => true])->exists()); }
public function test_tidak_menimpa_hasil_edit_admin(): void {
    DB::table('feature_flags')->where('code','drops.enabled')->update(['description' => 'disunting admin']);
    (new ReferenceSeeder())->run();
    $this->assertSame('disunting admin', DB::table('feature_flags')->where('code','drops.enabled')->value('description')); }
```

- [ ] **Step 7: Manifest + SVG orisinal.** `Demo/catalog-manifest.php` = 10 entri fiktif buatan sendiri (dipakai `DemoMediaSeeder` sekarang, `DemoCatalogSeeder` Sprint 1 saat `products` ada); tidak ada nama merek/IP/teks situs mana pun:

```php
return [
 ['slug'=>'lumen-fox-plush','name'=>'Lumen Fox Plush','amount_minor'=>289000,'hue'=>28,'svg'=>'product-01.svg'],
 ['slug'=>'nova-keychain','name'=>'Nova Keychain','amount_minor'=>45000,'hue'=>210,'svg'=>'product-02.svg'],
 ['slug'=>'aster-clutch','name'=>'Aster Clutch','amount_minor'=>175000,'hue'=>330,'svg'=>'product-03.svg'],
 ['slug'=>'ember-lamp','name'=>'Ember Desk Lamp','amount_minor'=>320000,'hue'=>12,'svg'=>'product-04.svg'],
 ['slug'=>'tidal-tote','name'=>'Tidal Tote','amount_minor'=>159000,'hue'=>190,'svg'=>'product-05.svg'],
 ['slug'=>'orchid-pin','name'=>'Orchid Enamel Pin','amount_minor'=>65000,'hue'=>285,'svg'=>'product-06.svg'],
 ['slug'=>'cobalt-mug','name'=>'Cobalt Mug','amount_minor'=>89000,'hue'=>225,'svg'=>'product-07.svg'],
 ['slug'=>'sable-brush','name'=>'Sable Brush Set','amount_minor'=>210000,'hue'=>35,'svg'=>'product-08.svg'],
 ['slug'=>'mint-stand','name'=>'Mint Tablet Stand','amount_minor'=>125000,'hue'=>150,'svg'=>'product-09.svg'],
 ['slug'=>'quartz-clock','name'=>'Quartz Desk Clock','amount_minor'=>275000,'hue'=>260,'svg'=>'product-10.svg'],
];   // amount_minor = bigint rupiah utuh (S-1), bukan desimal
```

`assets/product-01.svg` nyata — generator loop `DemoMediaSeeder` hanya memakai `hue` + judul dari manifest untuk 9 berkas lainnya:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600" role="img" aria-labelledby="t"><title id="t">Lumen Fox Plush</title>
<rect width="600" height="600" fill="hsl(28,80%,92%)"/><circle cx="300" cy="300" r="180" fill="hsl(28,75%,60%)"/>
<path d="M180 210 240 120 270 210Z" fill="hsl(28,60%,45%)"/><path d="M420 210 360 120 330 210Z" fill="hsl(28,60%,45%)"/>
<circle cx="245" cy="285" r="18" fill="#1b1b1b"/><circle cx="355" cy="285" r="18" fill="#1b1b1b"/>
<path d="M270 350Q300 385 330 350" stroke="#1b1b1b" stroke-width="10" fill="none" stroke-linecap="round"/></svg>
```

- [ ] **Step 8: `DemoMediaSeeder` + `DemoAccountSeeder`.** Produk/batch/kuotasi belum bisa ditanam — tabelnya ada di band Sprint 1 dan Sprint 5 (Doc 10 §4; lihat catatan #12) — yang ditanam sekarang baris `media` + akun demo. Baris `media` ditulis **langsung** (tidak lewat `FinalizeMediaUpload`) karena `image/svg+xml` sengaja tidak ada di `config/media.allowed_mime` (SVG bisa memuat skrip; jalur unggah pengguna menolaknya) dan `model_type` di sini label logis `demo_catalog_item`, bukan FQCN — `DemoCatalogSeeder` Sprint 1 me-repoint baris ini ke model `Product` saat tabelnya ada:

```php
foreach (require __DIR__.'/catalog-manifest.php' as $i => $item) {
    $src = __DIR__."/assets/{$item['svg']}"; $key = Str::uuid()->toString().'.svg';
    Storage::disk('product-media')->put($key, file_get_contents($src));
    Media::firstOrCreate(['disk' => 'product-media', 'path' => $key], ['model_type' => 'demo_catalog_item',
        'model_id' => $i + 1, 'kind' => 'image', 'mime' => 'image/svg+xml', 'size_bytes' => filesize($src), 'width' => 600, 'height' => 600,
        'sha256' => hash_file('sha256', $src), 'alt_key' => "media.demo.{$item['slug']}", 'position' => $i, 'is_moderated' => false,
        'moderation_status' => 'approved']); }
// DemoAccountSeeder: $operator = User::factory()->operator()->create(['email' => 'demo.operator@elzara.test',
//   'password_hash' => Hash::hash(env('DEMO_PASSWORD', 'dev-only-not-a-secret'), algo: PASSWORD_ARGON2ID)]);
// $operator->assignRole('superadmin'); User::factory()->create(['email' => 'demo.buyer@elzara.test']);
// model_has_roles bukan tabel referensi seeder: relasi demo ditulis di sini (dev saja), password hanya dari env dev, tidak ada rahasia di repo.
```

- [ ] **Step 9: Factories** — satu factory per tabel Sprint 0, dipakai seluruh test sprint berikutnya; definisi selalu memenuhi CHECK agar test tidak pernah menabrak constraint (contoh lengkap `UserFactory` + `AuditLogFactory`; `MediaFactory`, `LocalizationFactory`, `FeatureFlagFactory`, `FeatureFlagOverrideFactory`, `SettingFactory`, `OtpChallengeFactory`, `UserSessionFactory`, `AddressFactory`, `CustomerProfileFactory`, `ConsentFactory`, `UserIdentityFactory`, `StaffTwoFactorSecretFactory` mengikuti kolom Doc 09 yang sama, satu per file; role/permission memakai model spatie, bukan factory pivot lama):

```php
class UserFactory extends Factory { protected $model = User::class;
    public function definition(): array { return ['public_id' => (string) Str::ulid(), 'name' => fake()->name(),
        'email' => strtolower(fake()->unique()->safeEmail()), 'is_operator' => false, 'is_admin' => false, 'status' => 'active', 'locale' => 'id',
        'timezone' => 'Asia/Jakarta', 'password_hash' => Hash::hash(env('TEST_PASSWORD', 'correct-horse-battery'), algo: PASSWORD_ARGON2ID)]; }
    public function operator(): static { return $this->state(fn () => ['is_operator' => true]); }   // pengganti state staff() lama
    public function adminBypass(): static { return $this->state(fn () => ['is_operator' => true, 'is_admin' => true]); }
    public function configure(): static { return $this->afterCreating(fn (User $u) => CustomerProfile::factory()->create(['user_id' => $u->id])); } }
class AuditLogFactory extends Factory { protected $model = AuditLog::class;
    public function definition(): array { return ['actor_id' => User::factory()->operator(), 'action' => 'setting.update', 'target_type' => 'feature_flags',
        'target_id' => fake()->numberBetween(1, 9999), 'before' => ['default_enabled' => false], 'after' => ['default_enabled' => true],
        'reason' => fake()->sentence(6), 'occurred_at' => now()]; } }   // reason wajib (ck_audit_logs_reason), tanpa updated_at (S-6)
// MediaFactory: disk+path unik (uq_media_path), mime dari whitelist, moderation_status='approved'. LocalizedStringFactory: locale in id|en (ck_localizations_locale).
// FeatureFlagOverrideFactory: env in local|staging|prod, rollout_percent 0..100. StaffTwoFactorSecretFactory: secret via Crypt::encryptString.
```

- [ ] **Step 10: Skrip composer** di `apps/api/composer.json` (Doc 10 §8: dijalankan dari app API; Makefile root hanya pembungkus tipis untuk Linux/macOS):

```json
"scripts": {
  "dev:db-fresh": ["@dev:artisan migrate:fresh --force", "@dev:artisan db:seed --force --class=ReferenceSeeder"],
  "dev:db-demo": ["@dev:db-fresh", "@dev:artisan db:seed --force --class=DemoSeeder"] }
```

`db:fresh` = `migrate:fresh` + `db:seed --class=ReferenceSeeder` persis spec 0.11; CI hanya memakai yang pertama (Doc 10 §8).

- [ ] **Step 11: Test asal-usul aset** — `AssetProvenanceTest.php` menolak byte asing lewat daftar putih eksplisit, bukan klaim:

```php
public function test_daftar_bersih_aset_seed(): void {
    $files = collect(glob(base_path('database/seeders/Demo/assets/*.svg'))); $this->assertCount(10, $files);
    foreach ($files as $f) { $this->assertStringStartsWith('<svg', ltrim(file_get_contents($f))); $this->assertLessThan(2000, filesize($f)); }
    $manifest = require base_path('database/seeders/Demo/catalog-manifest.php');
    $this->assertSame($files->map(fn ($f) => basename($f))->values()->all(), array_column($manifest, 'svg'));
    $this->assertSame([], array_values(array_filter($manifest, fn (array $m) => strlen($m['name']) < 3 || $m['amount_minor'] <= 0))); }
```

- [ ] **Step 12: Gerbang** — `composer dev:db-fresh && composer dev:test -- ProductionSeedGuardTest ReferenceSeedIdempotencyTest AssetProvenanceTest` hijau; `time composer dev:db-fresh` riil **< 90 s** (spec §6); `git ls-files apps/api/database/seeders | grep -vE "\.(php|svg)$"` kosong (nol biner asing di jalur seed).

- [ ] **Step 13: Commit** — `git add apps/api/database/seeders apps/api/database/factories apps/api/composer.json apps/api/tests/Schema apps/api/app/Modules/Identity apps/api/app/Modules/Content/Infrastructure/Models apps/api/app/Modules/Settings/Infrastructure/Models apps/api/app/Modules/Audit/Infrastructure/Models` + `git commit -m "feat(sprint-0): reference seeder idempoten + demo seeder dev-only + factories sprint 0"`.

### Task 16 — ID 0.8: design token tiga lapis + komponen dasar di kedua app

Keputusan brand (dipilih, bukan digantung): brand statis tinggal di `packages/ui/src/brand/brand.config.ts`,
**bukan** `config/brand.php` di API. Alasan: Doc 04 §12 melarang override brand di dalam satu app, dan berkas
PHP di `apps/api` tidak terbaca pipeline CSS kedua app Next saat build, sehingga override akan
tersorong ke app. Doc 04 §3 sudah membagi perannya: bentuk + default statis di `brand.config.ts` (dipakai dua
app, tipe sama); teks/legal/nav dinamis tetap dari Laravel lewat resource `/api/v1`. Ganti brand = **dua**
berkas, persis seperti Diratakan spec 0.8: `brand.config.ts` (identitas) + `presets/brand.tokens.css`
(warna/tipe/radius). Nol perubahan komponen. Nama lapis persis Doc 04 §2: **Lapis 1 — primitive**, **Lapis 2 —
semantik**, **Lapis 3 — komponen/slot**; komponen hanya menyebut Lapis 2/3, Lapis 1 hanya dirujuk Lapis 2.
Build langkah `packages/ui` = `npm --workspace @elzara/ui run build` (Step 4).

**Files:**
- Create: `packages/ui/{package.json,vitest.config.ts}`, `packages/ui/src/index.ts`, `packages/ui/src/tailwind.preset.ts`
- Create: `packages/ui/src/tokens/{primitives,semantic,slots}.css`, `packages/ui/src/tokens/contrast.ts`
- Create: `packages/ui/src/tokens/presets/{playful,clean-premium,bold-kpop,brand}.tokens.css`
- Create: `packages/ui/src/brand/brand.config.ts`, `packages/ui/scripts/build-tokens.mjs`
- Create: `packages/ui/src/components/primitives/{Button,Input,Card,Badge,Modal,Toast,Grid}.tsx`
- Create: `packages/ui/tests/{contrast.test.ts,brand-single-source.test.mjs,components.test.tsx}`
- Modify: `apps/web/{next.config.ts,package.json}`, `apps/admin/{next.config.ts,package.json}`
- Create: `apps/{web,admin}/app/globals.css`, `apps/{web,admin}/config/brand.ts`
- Create: `apps/web/lib/flags.ts`, `apps/web/components/shop/CheckoutDisabledNotice.tsx`
- Create: `apps/{web,admin}/tests/ui/primitives.render.test.tsx`

- [ ] **Step 1: Lapis 1 (primitive) — variabel saja, nol `@theme`**

```css
/* packages/ui/src/tokens/primitives.css — tidak pernah dirujuk komponen */
:root {
  --neutral-0:#ffffff; --neutral-50:#f7f7f8; --neutral-500:#6b6b74; --neutral-900:#141419;
  --brand-50:#fdf0f6; --brand-600:#f26b9a; --coral-500:#ff8a5c; --violet-600:#7b2bf9;
  --green-600:#1c8a4f; --red-600:#c62828; --amber-500:#b45309; --sky-600:#0369a1;
  --space-1:4px; --space-2:8px; --space-3:12px; --space-4:16px; --space-6:24px; --space-8:32px;
  --radius-1:6px; --radius-2:14px; --radius-3:20px; --radius-pill:999px;
  --z-drawer:60; --z-modal:70; --z-toast:80;
  --motion-fast:160ms; --motion-base:240ms; --ease-out:cubic-bezier(.2,.8,.2,1);
}
```

- [ ] **Step 2: Lapis 3 (slot) + Lapis 2 (semantik) dengan `@theme` Tailwind v4 nyata**

```css
/* packages/ui/src/tokens/slots.css — Lapis 3 */
:root {
  --cart-drawer-width:420px; --pdp-gallery-ratio:1/1; --badge-radius:var(--radius-pill);
  --button-height:44px; --grid-gutter:clamp(16px, 4vw, 40px); --content-max:1240px;
  --chart-1:var(--brand-600); --chart-2:var(--coral-500); --chart-3:var(--violet-600);
  --chart-4:var(--green-600); --chart-5:var(--amber-500); --chart-6:var(--red-600);
}
/* packages/ui/src/tokens/semantic.css — Lapis 2: satu-satunya yang dipanggil komponen */
@import "./primitives.css";
@import "./slots.css";
@theme {
  --color-surface:var(--neutral-0); --color-surface-muted:var(--neutral-50);
  --color-ink:var(--neutral-900); --color-ink-muted:var(--neutral-500);
  --color-brand:var(--brand-600); --color-brand-ink:var(--brand-50); --color-accent:var(--coral-500);
  --color-success:var(--green-600); --color-warning:var(--amber-500); --color-danger:var(--red-600);
  --color-info:var(--sky-600); --color-po:var(--violet-600); --color-ready:var(--green-600);
  --color-quote:var(--sky-600); --color-focus-ring:var(--brand-600); --color-overlay:rgb(0 0 0/.55);
  --color-divider:var(--neutral-50); --font-display:"ElzaraDisplay", ui-sans-serif;
  --font-body:"ElzaraBody", ui-sans-serif; --radius-card:var(--radius-3);
  --shadow-card:0 1px 2px rgb(0 0 0/.04), 0 8px 24px -12px rgb(0 0 0/.12);
}
```

- [ ] **Step 3: Tiga preset + satu klon brand (hanya meng-override Lapis 1)**

```css
/* presets/clean-premium.tokens.css */
:root { --brand-600:#1f2430; --brand-50:#f4f5f7; --coral-500:#b08d57; --neutral-0:#ffffff;
  --neutral-50:#f4f5f7; --neutral-900:#12161d; --neutral-500:#5c6472; --radius-3:6px; --motion-base:160ms; }
/* presets/bold-kpop.tokens.css — dark-first: token surface/ink dibalik (Doc 04 §5.2) */
:root { --brand-600:#7b2bf9; --brand-50:#1b1425; --coral-500:#00e5a0; --neutral-0:#0b0b12;
  --neutral-50:#15151f; --neutral-900:#f4f4fa; --neutral-500:#9a9ab0; --radius-3:14px; --motion-base:200ms; }
```

`presets/playful.tokens.css` mengulang nilai default Lapis 1 dengan `--coral-500:#ffc53d` (kuning madu),
`--radius-3:20px`, `--motion-base:240ms` — persis baris tabel Doc 04 §4. `presets/brand.tokens.css` = klon
`playful`: **berkas inilah yang disunting saat ganti brand** (checklist Doc 04 §11 butir 1).

- [ ] **Step 4: Script build token + manifest paket + preset Tailwind**

```js
// packages/ui/scripts/build-tokens.mjs — urutan impor menentukan override
import { readFile, writeFile, mkdir } from "node:fs/promises";
const theme = process.env.BRAND_THEME ?? "playful", T = "src/tokens";
const file = theme === "brand" ? `${T}/presets/brand.tokens.css` : `${T}/presets/${theme}.tokens.css`;
const srcs = [`${T}/primitives.css`, `${T}/slots.css`, `${T}/semantic.css`, file], out = [];
for (const f of srcs) out.push(`/* === ${f} === */\n` + await readFile(f, "utf8"));
await mkdir("dist", { recursive: true }); await writeFile("dist/tokens.css", out.join("\n"));
console.log(`tokens ok theme=${theme} bytes=${Buffer.byteLength(out.join(""))}`);
```

`package.json`: `"name":"@elzara/ui"`, `"type":"module"`, `exports: { ".": "./src/index.ts",
"./security": "./src/security/security-headers.ts", "./tailwind.preset": "./src/tailwind.preset.ts",
"./tokens.css": "./dist/tokens.css" }`, `scripts: { build: "node scripts/build-tokens.mjs", test: "vitest run",
"brand-check": "node tests/brand-single-source.test.mjs" }`. `src/tailwind.preset.ts` hanya memetakan
variabel (nol hex): `height.button="var(--button-height)"`, `height.touch="44px"`, `width.drawer`,
`maxWidth.content`, `borderRadius.card/badge`, `boxShadow.card`, `spacing.gutter`, `zIndex.drawer/modal/toast`,
`transitionDuration.fast/base`.

- [ ] **Step 5: `brand.config.ts` — tipe persis Doc 04 §3**

```ts
export interface BrandConfig {
  name: string; tagline: string; supportWhatsapp: string;
  legal: { company: string; address: string; npwp: string; email: string };
  nav: { id: string; label: string; to: string; badge?: string }[];
  links: { terms: string; privacy: string; howToPo: string }; social: Record<string, string>;
  locale: "id"; currency: "IDR"; tz: "Asia/Jakarta";
  theme: "playful" | "clean-premium" | "bold-kpop" | "brand";
  seo: { defaultOg: string; siteName: string };
}
export const brand: BrandConfig = {
  name: "Elzara", tagline: "Impor & pre-order, terpantau", supportWhatsapp: "6281200000000",
  legal: { company: "PT Elzara Retail Indonesia", address: "Jl. Contoh No. 1, Jakarta",
    npwp: "00.000.000.0-000.000", email: "legal@elzara.test" },
  nav: [{ id: "catalog", label: "Katalog", to: "/catalog" }, { id: "preorder", label: "Pre-order", to: "/preorder" },
    { id: "request", label: "Request Produk", to: "/request" },
    { id: "orders", label: "Pesanan Saya", to: "/account/orders" }],
  links: { terms: "/policy/terms", privacy: "/policy/privacy", howToPo: "/guide/preorder" },
  social: { instagram: "https://instagram.com/elzara" }, locale: "id", currency: "IDR",
  tz: "Asia/Jakarta", theme: "brand", seo: { defaultOg: "/brand/og-default.svg", siteName: "Elzara" },
};
```

`apps/*/config/brand.ts` **hanya** `export { brand, type BrandConfig } from "@elzara/ui";` — tidak
mendefinisikan ulang apa pun (Doc 04 §1). Nilai rahasia tidak pernah masuk objek brand maupun `NEXT_PUBLIC_*`.

- [ ] **Step 6: Tujuh komponen dasar, API props lengkap**

```tsx
// Button.tsx — 5 varian x 3 ukuran, target sentuh >=44px, focus-visible (Doc 04 §5.2)
type Variant = "primary" | "secondary" | "ghost" | "danger" | "accent"; type Size = "sm" | "md" | "lg";
export interface ButtonProps extends React.ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: Variant; size?: Size; loading?: boolean; block?: boolean;
  leadingIcon?: React.ReactNode; trailingIcon?: React.ReactNode; }
const VAR: Record<Variant, string> = { primary:"bg-brand text-surface", secondary:"bg-brand-ink text-ink",
  ghost:"bg-transparent text-ink", danger:"bg-danger text-surface", accent:"bg-accent text-ink" };
const SIZE: Record<Size, string> = { sm:"h-9 px-3 text-sm", md:"h-11 px-4", lg:"h-12 px-6" };
const FOCUS = "focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring";
export function Button({ variant="primary", size="md", loading, block, leadingIcon, trailingIcon,
  className="", disabled, children, ...rest }: ButtonProps) {
  return <button {...rest} disabled={disabled || loading} aria-busy={loading || undefined}
    className={[VAR[variant], SIZE[size], "inline-flex items-center justify-center gap-2 rounded-badge",
      "font-medium hover:brightness-95 transition-base motion-reduce:transition-none disabled:opacity-50",
      FOCUS, block && "w-full", className].filter(Boolean).join(" ")}>
    {leadingIcon}{children}{trailingIcon}</button>; }
// Input.tsx : { label: string; hint?: string; error?: string; addonBefore?: ReactNode; addonAfter?: ReactNode }
//   extends InputHTMLAttributes; error di <p id=`${id}-error`> + aria-describedby (bukan hanya warna merah)
// Card.tsx  : { as?: "div"|"article"|"section"; interactive?: boolean; padding?: "none"|"sm"|"md"|"lg";
//   header?: ReactNode; footer?: ReactNode; children } -> rounded-card + shadow-card
// Badge.tsx : { tone: "po"|"ready"|"quote"|"success"|"warning"|"danger"|"info"|"neutral";
//   shape?: "pill"|"block"|"uppercase"; icon?: ReactNode; children } -> hanya utilitas Lapis 2
// Modal.tsx : { open: boolean; onClose: () => void; title: string; description?: string; size?: "sm"|"md"|"lg";
//   initialFocusRef?: RefObject<HTMLElement|null>; footer?: ReactNode; children } role="dialog" aria-modal
//   aria-labelledby + focus trap + Escape + restore fokus + z-modal
// Toast.tsx : { id: string; tone: "success"|"danger"|"info"; title: string; body?: string; durationMs?: number;
//   onDismiss: (id: string) => void } + <ToastViewport toasts align="br"> region role="status"
//   aria-live="polite" z-toast dismissable via keyboard
// Grid.tsx  : { columns?: { base?: 1|2; sm?: 2|3; md?: 3|4; lg?: 4|6 }; gap?: "sm"|"md"|"lg";
//   alignItems?: "start"|"stretch"; children } -> CSS grid, gap dari var(--grid-gutter)
```

Kepadatan grid per preset (Doc 04 §4 baris "Kepadatan grid") dipilih pemanggil halaman lewat `columns`, tidak
pernah lewat hex di komponen.

- [ ] **Step 7: Pasang di kedua app (Tailwind v4 CSS-first)**

Kedua `app/globals.css` identik: `@import "@elzara/ui/tokens.css";` → `@import "tailwindcss"
source("../../packages/ui/src");` → `@config "@elzara/ui/tailwind.preset";`. Kedua `next.config.ts`:
`transpilePackages: ["@elzara/ui"]`. Kedua `package.json`: `"@elzara/ui": "file:../../packages/ui"` dan
`"prebuild": "node ../../packages/ui/scripts/build-tokens.mjs"` dengan `BRAND_THEME` dibaca dari
`NEXT_PUBLIC_BRAND_THEME` (default `brand`). Dua lockfile terpisah tetap (Task 1).

- [ ] **Step 8: Test kontras AA — dibuktikan, bukan diyakini**

```ts
// packages/ui/src/tokens/contrast.ts
export function relativeLuminance(hex: string): number {
  const c = hex.replace("#", ""), ch = (i: number) => { const v = parseInt(c.slice(i, i + 2), 16) / 255;
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
  return 0.2126 * ch(0) + 0.7152 * ch(2) + 0.0722 * ch(4);
}
export function contrastRatio(a: string, b: string): number {
  const [hi, lo] = [relativeLuminance(a), relativeLuminance(b)].sort((x, y) => y - x);
  return (hi + 0.05) / (lo + 0.05);
}
```

`tests/contrast.test.ts`: helper `resolveTheme(theme)` membaca `primitives.css` + preset lalu menuntaskan
rantai `var()` sampai hex (tidak ada tabel warna kedua yang bisa basi). Pasangan, ambang **4.5** (teks biasa,
Doc 04 §5.2): `ink/surface`, `ink-muted/surface`, `ink/surface-muted`, `surface/brand`, `surface/danger`,
`surface/po`, `surface/ready`, `surface/info`, `ink/accent`, `ink/brand-ink`, `surface/warning`; plus
non-teks `brand/surface` ≥ 3 (focus-ring). Dijalankan untuk `["playful","clean-premium","bold-kpop","brand"]`.
Perintah `npm --workspace @elzara/ui test` → `4 passed (44 assertions)`. Bila `bold-kpop` gagal di
`ink-muted`, perbaiki **skala primitive di preset itu** — bukan komponen, bukan ambang test (Doc 04 §11 butir 2).

- [ ] **Step 9: Gerbang "satu berkas brand mengubah kedua app, nol komponen disentuh"**

```js
// packages/ui/tests/brand-single-source.test.mjs
import { execSync } from "node:child_process"; import { readFileSync } from "node:fs";
import { writeFile } from "node:fs/promises";
const CANARY = "#1a2b3c", f = "src/tokens/presets/brand.tokens.css", original = readFileSync(f, "utf8");
try {
  await writeFile(f, original.replace(/--brand-600:\s*#[0-9a-f]{3,6}/i, `--brand-600:${CANARY}`));
  for (const app of ["web", "admin"]) {
    execSync(`npm --workspace ${app} run build`, { env: { ...process.env, BRAND_THEME: "brand" } });
    if (!execSync(`grep -rl "${CANARY}" apps/${app}/.next/static/css || true`).toString().trim())
      throw new Error(`brand tidak menyebar ke ${app}`); }
  const touched = execSync("git status --porcelain packages/ui/src/components").toString().trim();
  if (touched) throw new Error("komponen ikut berubah: " + touched);
  console.log("brand-single-source: kedua app berubah, nol komponen disentuh");
} finally { await writeFile(f, original); }
```

`tests/components.test.tsx` + dua `apps/*/tests/ui/primitives.render.test.tsx` (Vitest +
`@testing-library/react` + `jsdom`): `Button` memancarkan `bg-brand` bukan hex; `Modal` menutup pada Escape
dan mengembalikan fokus; `Toast` ber-`aria-live="polite"`; `Input` error menautkan `aria-describedby`;
`Grid` memancarkan `grid-template-columns`; `Badge tone="po"` memakai `bg-po`. Perintah (masing-masing app):
`npm --workspace web test` → `7 passed`; `npm --workspace admin test` → `7 passed`.

- [ ] **Step 10: Kill switch UI storefront (menyambung Task 14 / ID 0.10)**

```ts
// apps/web/lib/flags.ts — dibaca di SERVER component, tidak pernah dari browser
export type Flags = { checkout: { enabled: boolean; code: string | null; reason: string | null } };
export async function getFlags(): Promise<Flags> {
  const res = await fetch(`${process.env.API_INTERNAL_URL}/api/v1/feature-flags`,
    { next: { revalidate: 10 }, headers: { "x-request-id": crypto.randomUUID() } });
  if (!res.ok) return { checkout: { enabled: true, code: null, reason: null } }; // read-only: gagal ambil != matikan
  const b = await res.json(), f = b.data.flags.find((x: { code: string }) => x.code === "checkout.enabled");
  return { checkout: { enabled: f?.enabled ?? true, code: f?.reason_code ?? null, reason: f?.reason_message ?? null } };
}
```

`CheckoutDisabledNotice.tsx` merender **alasan** (`reason` dari API, resource ID/EN; fallback `
checkout.disabled.fallback` lewat `__()`) + CTA "Notifikasi saya saat dibuka", dan form checkout **tidak
dirender**. Response `503 CHECKOUT_DISABLED` (Doc 12 §4.3) dipetakan ke komponen yang sama, sehingga
`ApiProblem` mentah tidak pernah tampil ke customer. Test Playwright storefront: flag mati →
`[data-testid="checkout-disabled"]` berisi teks alasan, dan body tidak memuat `https://errors.elzara/`.

- [ ] **Step 11: Lint anti-hardcode + verifikasi akhir Task 16**

Di `.eslintrc.cjs` kedua app: `no-restricted-syntax` menolak Literal yang cocok `/#[0-9A-Fa-f]{3,8}/` di
`components/**` (Doc 04 §5). Gerbang: `npm --workspace @elzara/ui run brand-check` →
`brand-single-source: kedua app berubah, nol komponen disentuh`; `npm run lint --workspace admin` →
`0 errors`; `grep -rni inertia apps packages` → **kosong**.

- [ ] **Step 12: Commit**

`git add packages/ui apps/web apps/admin` lalu
`git commit -m "feat(sprint-0): design token tiga lapis + komponen di dua app"`

### Task 17 — ID 0.16: baseline keamanan edge + CSP per app

Yang tidak boleh dihilangkan: `script-src 'self'` **murni** memblokir script inline bootstrapping App Router
Next.js (flight payload), sementara ID 0.16 melarang CSP yang memutus mekanisme muat Next. Bentuk yang
ditegakkan karena itu `script-src 'self' 'nonce-{per-request}'` — tanpa `unsafe-inline`, tanpa `unsafe-eval`.
Di **dev**, React Refresh butuh `'unsafe-eval'` + `ws:`: dev **tidak** mematikan CSP, ia menurunkannya ke
`Content-Security-Policy-Report-Only` — Step 5 membuktikan staging/produksi menegakkan (enforcing). Ini ditulis
sebagai pengecualian, bukan disembunyikan.

**Files:**
- Create: `packages/ui/src/security/security-headers.ts`; Modify: `packages/ui/src/index.ts`
- Create: `apps/{web,admin}/middleware.ts`, `apps/{web,admin}/app/robots.ts`
- Create: `infrastructure/nginx/{elzara.prod.conf,snippets/security-headers.conf,snippets/csp-map.conf}`
- Create: `apps/api/tests/Feature/Security/{EdgeHeadersTest.php,AdminRouteDeniedTest.php}`
- Create: `apps/{web,admin}/tests/e2e/csp.spec.ts`

- [ ] **Step 1: Pembangun header + CSP per app (bebas state sesi)**

```ts
export type AppName = "web" | "admin";
export type Env = "development" | "staging" | "production";
export interface CspInput { nonce: string; app: AppName; apiOrigin: string; mediaOrigin: string; env: Env }
export const HSTS = "max-age=63072000; includeSubDomains; preload"; // nilai presisi Doc 01 §9.3
export function buildCsp({ nonce, app, apiOrigin, mediaOrigin }: CspInput): string {
  const admin = app === "admin";
  return ["default-src 'self'", `script-src 'self' 'nonce-${nonce}'`, `style-src 'self' 'nonce-${nonce}'`,
    `img-src 'self' data: ${mediaOrigin}`, "font-src 'self'", `connect-src 'self' ${apiOrigin}`,
    "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'",
    "frame-src 'none'", "worker-src 'self' blob:",
    admin ? "sandbox allow-scripts allow-forms allow-same-origin" : "upgrade-insecure-requests"].join("; ");
}
export function securityHeaders(i: CspInput): Record<string, string> {
  const enforce = i.env !== "development";
  const csp = enforce ? buildCsp(i)
    : buildCsp(i).replace("'self' 'nonce-", "'self' 'unsafe-eval' 'nonce-") + "; connect-src 'self' ws:";
  return { "Strict-Transport-Security": HSTS, "X-Frame-Options": "DENY", "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "strict-origin-when-cross-origin",
    "Permissions-Policy": "camera=(), microphone=(), geolocation=(self)",
    [enforce ? "Content-Security-Policy" : "Content-Security-Policy-Report-Only"]: csp,
    ...(i.app === "admin" ? { "X-Robots-Tag": "noindex, nofollow, noarchive" } : {}) };
}
```

`connect-src` sengaja sempit: hanya origin app + origin API yang terdaftar eksplisit per app (Doc 01 §9.3);
domain gateway/CDN ditambahkan sebagai baris eksplisit saat driver dipilih (Q-7), bukan wildcard.

- [ ] **Step 2: Middleware per app dengan nonce per-request**

```ts
// apps/admin/middleware.ts — web identik, hanya `app` berbeda
import { randomBytes } from "node:crypto";
import { NextResponse, type NextRequest } from "next/server";
import { securityHeaders } from "@elzara/ui/security";
export const config = { matcher: "/:path*" };
export function middleware(req: NextRequest) {
  const nonce = randomBytes(16).toString("base64");
  const res = NextResponse.next({ request: { headers: new Headers(req.headers) } });
  Object.assign(res.headers, securityHeaders({ nonce, app: "admin",
    env: (process.env.APP_ENV ?? "development") as "development" | "staging" | "production",
    apiOrigin: process.env.API_PUBLIC_ORIGIN!, mediaOrigin: process.env.MEDIA_PUBLIC_ORIGIN! }));
  res.headers.set("x-nonce", nonce); return res;
}
```

Kedua `app/layout.tsx` membaca nonce dari `headers()` untuk `<style>`/`<script>` milik kita; Next memakai nonce
dari header CSP untuk flight script-nya sendiri. Tidak ada `dangerouslySetInnerHTML` tanpa nonce.

- [ ] **Step 3: Blok nginx produksi (pertahanan berlapis di edge)**

```nginx
# infrastructure/nginx/snippets/security-headers.conf
add_header Strict-Transport-Security "max-age=63072000; includeSubDomains; preload" always;
add_header X-Frame-Options "DENY" always;
add_header X-Content-Type-Options "nosniff" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "camera=(), microphone=(), geolocation=(self)" always;
# CSP TIDAK di sini: nginx mewarisi SEMUA add_header parent, jadi blok child akan menghapusnya.
# elzara.prod.conf: location /admin -> proxy_pass http://admin:3000; location / -> http://web:3000;
# keduanya include snippets/csp-map.conf yang meneruskan CSP upstream:
#   map $upstream_http_content_security_policy $csp_out { default $upstream_http_content_security_policy; }
#   add_header Content-Security-Policy $csp_out always;
```

Verifikasi statik: `nginx -t -p infrastructure/nginx -c elzara.prod.conf` → `syntax is ok`.

- [ ] **Step 4: Feature test keenam header + CSP + penolakan admin**

```php
#[Test]
public function csp_web_mengunci_script_src_dan_frame_ancestors(): void {
    $h = $this->headersFromEnv('WEB_ORIGIN'); // GET / lewat nginx, kumpulkan semua header respons
    $this->assertStringContainsString("script-src 'self' 'nonce-", $h['content-security-policy']);
    $this->assertStringNotContainsString('unsafe-inline', $h['content-security-policy']);
    $this->assertStringNotContainsString('unsafe-eval', $h['content-security-policy']);
    $this->assertStringContainsString("frame-ancestors 'none'", $h['content-security-policy']);
    $this->assertSame('DENY', $h['x-frame-options']);
    $this->assertStringStartsWith('max-age=63072000', $h['strict-transport-security']);
}
#[Test] public function csp_admin_membedakan_dirinya_dari_web(): void { /* GET /admin: sandbox + X-Robots-Tag */ }
#[Test] public function admin_menolak_tamu_di_server_bukan_di_klien(): void { /* /admin/* tanpa sesi => 401/403 */ }
```

`AdminRouteDeniedTest.php`: setiap rute `/api/v1/admin/*` terdaftar dipanggil **tanpa** cookie sesi ⇒ 401/403
**dari server**; `customer-support` memanggil `DELETE /api/v1/admin/products/{id}` ⇒ 403. Perintah:
`composer ci:test -- --filter EdgeHeaders` → `8 assertions OK`; `--filter AdminRouteDenied` → `14 assertions OK`.

- [ ] **Step 5: Playwright menolak inline script + memuat kedua app**

```ts
test("inline script tanpa nonce tidak dieksekusi", async ({ page }) => {
  await page.goto("/");
  const r = await page.evaluate(() => { const s = document.createElement("script");
    s.textContent = "window.__pwned=1"; document.head.append(s);
    return new Promise(res => setTimeout(() => res((window as any).__pwned), 300)); });
  expect(r).toBeUndefined();
});
test("CSP enforcing, bukan Report-Only", async ({ request }) => {
  const res = await request.get(process.env.WEB_URL!);
  expect(res.headers()["content-security-policy"]).toContain("script-src 'self'");
  expect(res.headers()["content-security-policy-report-only"]).toBeUndefined();
});
test("rute admin menolak tamu di server", async ({ request }) => {
  const res = await request.get(`${process.env.ADMIN_URL!}/admin/orders`);
  expect([401, 403]).toContain(res.status()); // BUKAN 200 + shell + redirect klien (Doc 08 §2)
});
```

Perintah: `npx playwright test` di web → `3 passed`; di admin → `3 passed` ( matcher `WEB_URL`/`ADMIN_URL` dari
env staging `https://localhost` dan `https://localhost/admin`).

- [ ] **Step 6: `admin` tidak boleh terindeks (Blueprint §13 — pemisahan admin/publik)**

`apps/admin/app/robots.ts` → `{ rules: [{ userAgent: "*", disallow: "/" }] }`. `X-Robots-Tag` dipasang
di **dua** lapis (middleware Step 2 dan `csp-map.conf` Step 3) supaya respons 404/500 dari upstream tetap
membawanya. `robots.txt` storefront tidak menyebut `/admin` dan tidak menyertakan sitemap admin. Test: asersi
tambahan di `EdgeHeadersTest` untuk header `x-robots-tag` pada `GET /admin` dan `Disallow: /` pada
`/admin/robots.txt`. Dinyatakan jujur: `robots.txt`/`noindex` adalah hygiene SEO, **bukan** kontrol akses —
kontrol aksesnya Step 4.

- [ ] **Step 7: Commit**

`git add packages/ui/src/security packages/ui/src/index.ts apps/web apps/admin infrastructure/nginx apps/api/tests/Feature/Security`
lalu `git commit -m "feat(sprint-0): baseline keamanan edge + CSP per app dengan nonce"`

### Task 18 — ID 0.12: observability tiga permukaan + backup off-node + drill PITR

Correlation: **`request-id` milik Task 7 dipakai apa adanya** sebagai kunci korelasi — tidak ada mekanisme
kedua. OTel hanya menambah `trace_id`/`span_id` dan merambat lewat header W3C `traceparent`.
`apps/api/app/Platform/**` bukan milik task ini: bila Task 7 sudah memasang processor Monolog
`request-id`, langkah di bawah **melengkapi processor itu dengan dua field baru, jangan duplikasi** (lihat
catatan akhir).

**Files:**
- Create: `apps/{web,admin}/instrumentation.ts`, `apps/{web,admin}/otel/node-sdk.ts`
- Create: `apps/api/config/opentelemetry.php`, `apps/api/app/Support/Observability/TraceCorrelationProcessor.php`
- Modify: `apps/api/composer.json`, `apps/api/bootstrap/providers.php`
- Create: `apps/api/tests/Feature/Observability/TraceCorrelationTest.php`
- Create: `infrastructure/monitoring/{otel-collector.yaml,prometheus.yml,loki-config.yaml,promtail-config.yaml,validate-dashboard.mjs}`
- Create: `infrastructure/monitoring/alerts/sprint-0.yml`
- Create: `infrastructure/monitoring/grafana-provisioning/datasources/all.yaml`, `.../dashboards/{all.yaml,sprint-0.json}`
- Create: `infrastructure/backup/{offnode-backup.sh,backup.env.example,crontab}`
- Modify: `docs/runbooks/backup-restore-drill.md`, `docs/runbooks/emergency-disable-checkout.md`

- [ ] **Step 1: Node SDK untuk kedua app lewat register hook Next**

```ts
// apps/web/otel/node-sdk.ts — admin identik, hanya ATTR service.name berbeda
import { NodeSDK } from "@opentelemetry/sdk-node";
import { OTLPTraceExporter } from "@opentelemetry/exporter-trace-otlp-http";
import { resourceFromAttributes } from "@opentelemetry/resources";
import { HttpInstrumentation } from "@opentelemetry/instrumentation-http";
import { FetchInstrumentation } from "@opentelemetry/instrumentation-undici";
export function startOtel(name: "web" | "admin") {
  if (process.env.OTEL_ENABLED !== "true") return;
  new NodeSDK({ resource: resourceFromAttributes({ "service.name": name,
      "deployment.environment": process.env.APP_ENV ?? "local" }),
    traceExporter: new OTLPTraceExporter({ url: `${process.env.OTEL_EXPORTER_OTLP_ENDPOINT}/v1/traces` }),
    instrumentations: [new HttpInstrumentation(), new FetchInstrumentation()] }).start();
}
// apps/web/instrumentation.ts
export async function register() {
  if (process.env.NEXT_RUNTIME === "nodejs") (await import("./otel/node-sdk")).startOtel("web");
}
```

`FetchInstrumentation` menyuntik `traceparent` pada `fetch` keluar ke API; `HttpInstrumentation` mengekstrak
`traceparent` masuk. Tidak ada tempat lain yang menulis `traceparent` manual.

- [ ] **Step 2: PHP API + job Horizon**

`composer require open-telemetry/opentelemetry-laravel:^0.5 open-telemetry/exporter-otlp:^1.1` **di dalam
container** (D-S0-5). `config/opentelemetry.php`: `service_name = env('OTEL_SERVICE_NAME','api')`,
transport `http/protobuf` ke collector, instrumentation `LaravelQueue` + `Http` + `PDO` aktif —
`LaravelQueue` menaruh `traceparent` di payload job sehingga `web → api → job` satu trace (Blueprint §15)
tanpa carrier kedua di outbox. `TraceCorrelationProcessor.php` (Monolog `ProcessorInterface`) menambah
`trace_id` (32 hex, `0` bila tanpa span aktif) + `span_id`, dan meneruskan `request_id` Task 7 dengan **nama
kunci yang sama persis**; diregistrasi di `bootstrap/providers.php`. Test: `GET /api/v1/products` dengan
`X-Request-Id: demo-123` → JSON log memuat `request_id="demo-123"` **dan** `trace_id` 32 hex; job
`notifications` dispatch→handle → `trace_id` span consumer == span producer.
`composer ci:test -- --filter TraceCorrelation` → `3 assertions OK`.

- [ ] **Step 3: Collector, Prometheus, Loki**

`otel-collector.yaml`: `receivers: [otlp]` → `processors: [batch]` → `exporters: [prometheus :8889, otlp/http
ke loki untuk log]`. `loki-config.yaml`: `auth_enabled: false`, retention `30d` di compactor, store
`filesystem` (satu node). `promtail-config.yaml`: scrape target `docker` dengan pipeline JSON yang memetakan
`request_id`, `trace_id`, `app` ke label/field.

```yaml
# infrastructure/monitoring/prometheus.yml — 15s: cukup untuk panel 5 menit, ringan di satu node
global: { scrape_interval: 15s, evaluation_interval: 15s }
scrape_configs:
  - { job_name: otel, static_configs: [ { targets: ["otel-collector:8889"] } ] }
  - { job_name: postgres-exporter, static_configs: [ { targets: ["postgres-exporter:9187"] } ] }
  - { job_name: redis-exporter, static_configs: [ { targets: ["redis-exporter:9121"] } ] }
  - { job_name: node-exporter, static_configs: [ { targets: ["node-exporter:9100"] } ] }
  - { job_name: minio, metrics_path: /minio/v2/metrics/cluster, static_configs: [ { targets: ["minio:9000"] } ] }
  - { job_name: horizon, metrics_path: /metrics, authorization: { type: Bearer, credentials: "${METRICS_TOKEN}" },
      static_configs: [ { targets: ["api:8080"], labels: { app: "api" } } ] }
rule_files: [ "/etc/prometheus/alerts/sprint-0.yml" ]
alerting: { alertmanagers: [ { static_configs: [ { targets: ["alertmanager:9093"] } ] } ] }
```

- [ ] **Step 4: Aturan alert — ambangnya angka, alasannya ikut ditulis**

```yaml
# infrastructure/monitoring/alerts/sprint-0.yml (grup: sprint-0; tiap rule punya annotations.runbook)
- { alert: HighErrorRate, expr: "sum(rate(http_server_request_duration_count{status=~\"5..\"}[5m]))/sum(rate(http_server_request_duration_count[5m])) > 0.02", for: 5m, annotations: { reason: "2% pada trafik staging = satu request gagal tiap 5 menit", runbook: "queue-stuck.md" } }
- { alert: CheckoutP95Slow, expr: "histogram_quantile(0.95, sum(rate(http_server_request_duration_count_bucket{route=\"POST /api/v1/orders\"}[5m])) by (le)) > 0.8", for: 10m, annotations: { reason: "0.8s target p95 checkout; di atas itu user 4G melihat >1s termasuk RTT" } }
- { alert: QueueLagHigh, expr: "max by (queue)(elzara_queue_oldest_waiting_seconds) > 60", for: 5m, annotations: { reason: "60s batas bawah janji notifikasi; payments/inventory prioritas (Doc 01 §10)", runbook: "queue-stuck.md" } }
- { alert: DlqGrowing, expr: "increase(elzara_queue_failed_jobs_total[15m]) > 10", annotations: { reason: "sepuluh kegagalan/15 menit = pola, bukan satu job kebetulan", runbook: "queue-stuck.md" } }
- { alert: OversellAnomaly, expr: "increase(elzara_oversell_anomalies_total[5m]) > 0", annotations: { reason: "harus NOL (Doc 01 §12)", runbook: "oversell.md" } }
- { alert: DbLockWaits, expr: "sum(rate(pg_stat_database_wait_time{wait_event_type=\"Lock\"}[5m])) > 0.5", for: 5m, annotations: { reason: "0.5s lock-wait per detik = contention baris SKU panas mulai nyata" } }
- { alert: PoolSaturation, expr: "pg_stat_activity_count{datname=\"elzara\"} / pg_settings_max_connections > 0.8", for: 5m, annotations: { reason: "peringatan sebelum too many connections" } }
- { alert: RedisMemoryHigh, expr: "redis_memory_used_bytes / redis_memory_max_bytes > 0.85", annotations: { reason: "Redis = cache/queue/lock, bukan ledger; evict tak terencana = sesi hilang" } }
- { alert: MinioErrorRate, expr: "sum(rate(minio_s3_requests_failed_total[5m])) > 1", annotations: { reason: "presigned upload gagal senyap bagi pengguna", runbook: "object-storage-unreachable.md" } }
- { alert: BackupAgeStale, expr: "time() - elzara_last_successful_backup_timestamp_seconds > 93600", annotations: { reason: "harian + 9 jam toleransi; tanpa bukti restore backup bukan backup", runbook: "backup-restore-drill.md" } }
- { alert: DiskLow, expr: "(node_filesystem_avail_bytes{mountpoint=\"/var/lib/docker\"}/node_filesystem_size_bytes{mountpoint=\"/var/lib/docker\"}) < 0.15", annotations: { reason: "satu node: disk penuh mematikan Postgres dan MinIO bersamaan (Doc 01 §11.5)" } }
```

- [ ] **Step 5: Dasbor 12 sderet metrik Blueprint §15 (bukan 11 — lihat catatan)**

Panel hidup dengan PromQL nyata: RPS `sum(rate(http_server_request_duration_count[1m]))`; p50/p95/p99
`histogram_quantile(0.5|0.95|0.99, sum(rate(..._bucket[5m])) by (le, route))`; 4xx/5xx dua query
`status=~"4.."` dan `"5.."`; lock waits + deadlocks `rate(pg_stat_database_deadlocks_total[5m])`; pool saturation
`pg_stat_activity_count / pg_settings_max_connections`; Redis memory `redis_memory_used_bytes`; queue lag per
antrian `elzara_queue_oldest_waiting_seconds{queue=~"payments|orders|inventory|notifications|imports|reports|media"}`
— **tujuh** antrian Doc 11 §8.1; MinIO error rate `rate(minio_s3_requests_failed_total[5m])`. Panel yang belum
bersumber data dipasang dengan teks penanda persis seperti ini, tanpa angka: payment webhook delay ("panel
terpasang, sumber data lahir Sprint 2" — butuh `webhook_receipts`, band `0400-0599` Doc 10 §4), oversell
anomalies ("Sprint 2" — `stock_items` + CHECK non-negatif), reservation expirations ("Sprint 2" —
`stock_reservations.expires_at`), waiting-room admissions & waiting time ("Phase 4" — ADR-009 terbuka, Q-4/Q-6).

```js
// infrastructure/monitoring/validate-dashboard.mjs — menolak klaim yang belum ada datanya
const d = JSON.parse(readFileSync("grafana-provisioning/dashboards/sprint-0.json", "utf8"));
const §15 = ["rps","latency_percentiles","error_rate","lock_waits","pool_saturation","redis_memory",
  "queue_lag","webhook_delay","oversell_anomalies","reservation_expirations","minio_error_rate","waiting_room"];
if (d.panels.length !== 12) throw new Error("panel harus 12, satu per sderet Blueprint §15");
for (const m of §15) if (!d.panels.some(p => p.metricKey === m)) throw new Error("metrik §15 hilang: " + m);
if (/99[.,]9|high availability|redundan|auto-failover/i.test(readFileSync(...,"utf8"))) throw new Error("klaim HA");
```

Perintah: `node infrastructure/monitoring/validate-dashboard.mjs` → `12 panels / 12 metrik §15 / 0 klaim HA`.

- [ ] **Step 6: Backup off-node (bucket ketujuh TERLARANG)**

Aturan Doc 11 §8.7 ditegakkan di script; `audit-archives` adalah bucket **aplikasi** untuk jejak audit dan tidak
dipakai untuk dump.

```bash
#!/usr/bin/env bash # infrastructure/backup/offnode-backup.sh
set -euo pipefail
TS="$(date -u +%Y%m%dT%H%M%SZ)"; DIR="/backup/${TS}"; C="docker compose -f docker/compose.staging.yaml"
mkdir -p "${DIR}"
${C} exec -T postgres pg_dump -U elzara -Fc -f - elzara > "${DIR}/elzara-${TS}.dump"
sha256sum "${DIR}/elzara-${TS}.dump" | tee "${DIR}/elzara-${TS}.dump.sha256"
pg_restore --list "${DIR}/elzara-${TS}.dump" >/dev/null   # Doc 10 §9 langkah 1: dump harus bisa dibaca
rclone copy "${DIR}" "offnode-crypt:/elzara-pg/${TS}/" --transfers 2 --checkers 4  # off-node, terenkripsi sebelum keluar
curl -fsS -X POST -H "Authorization: Bearer ${METRICS_TOKEN}" \
  --data-urlencode "ts=$(date +%s)" --data-urlencode "bytes=$(stat -c%s "${DIR}/elzara-${TS}.dump")" \
  "${API_INTERNAL_URL}/api/v1/admin/metrics/backup-ok"     # menggerakkan alert BackupAgeStale
find /backup -maxdepth 1 -mtime +35 -exec rm -rf {} +       # retensi lokal 35 hari
```

`crontab` service `scheduler`: `17 19 * * * /opt/elzara/offnode-backup.sh` (= 02:17 WIB). WAL archiving PITR
lewat pgBackRest ke remote yang sama (`repo2-type=s3`, `repo2-crypt-key` di secret store, tidak pernah di repo).
`backup.env.example` hanya memuat **nama** variabel + contoh format, tanpa nilai rahasia (Doc 01 §11.3).

- [ ] **Step 7: Restore PITR dijalankan SEKALI, hasil dicatat nyata**

```bash
time pgbackrest --stanza=elzara --type=time "--target=$(date -u -d '-20 minutes' +'%Y-%m-%d %H:%M:%S+00')" --delta restore
docker compose -f docker/compose.staging.yaml up -d postgres-restored
php artisan migrate --force && psql -Atc "SELECT max(created_at), count(*) FROM orders"
```

Tabel §3.4 `docs/runbooks/backup-restore-drill.md` diisi kolom `waktu | berkas | ukuran | durasi | sha256 | RPO
terukur | RTO terukur | langkah gagal`, tiap sel angka hasil eksekusi, contoh format baris:
`2026-11-XXT09:14Z | elzara-202611XXT090000Z.dump | 41,7 MB | 3m12s | 9f2c…a1 | 00:14 | 06:41 | tidak ada`.
§5 runbook: tulis temuan (mis. "restore butuh kredensial off-node; drill berikutnya harus diuji tanpa akses
produksi" — T-28). `emergency-disable-checkout.md` §5: catat waktu nyata dari flag `checkout.enabled` dibalik
sampai storefront menampilkan alasan Task 16 Step 10 — durasi terukur, bukan "segera". Kata yang dilarang di
kedua runbook dan di dasbor: HA, redundan, auto-failover, 99,9 % (D-22, B-15).

- [ ] **Step 8: Service monitoring di compose (lengkapi, jangan duplikasi)**

Ke `docker/compose.staging.yaml` (milik Task 20) **tambahkan** `otel-collector`, `prometheus`,
`alertmanager`, `loki`, `promtail`, `grafana`, `postgres-exporter`, `redis-exporter`, `node-exporter` di bawah
`profiles: ["monitoring"]`; **jangan** menulis ulang `postgres`/`redis`/`minio` serta service profil `elasticsearch` milik Task 3 (satu
berkas satu definisi). Semua di network internal `monitoring`, tidak ada port data store dipublikasikan; hanya
`grafana:3000` terproksikan nginx di `location /obs` dengan BasicAuth + IP allowlist. Verifikasi:
`docker compose -f docker/compose.yaml -f docker/compose.staging.yaml --profile
monitoring config --services | sort | uniq -d` → **kosong** (nol duplikasi).

- [ ] **Step 9: Commit**

`git add apps/web apps/admin apps/api/config apps/api/app/Support apps/api/bootstrap apps/api/composer.json apps/api/tests infrastructure/monitoring infrastructure/backup docs/runbooks`
lalu `git commit -m "feat(sprint-0): observability OTel tiga permukaan + backup off-node + drill PITR"`.

### Task 19 — ID 0.13 + 0.14: threat model diselaraskan dengan yang dibangun + ADR ditutup

> **Amandemen 2026-11-18:** ADR-007 (BFF/sesi) sudah memuat amandemen mekanisme (Accepted untuk mekanisme) dan ADR-012 (RBAC/identitas/dependensi) sudah Accepted — keduanya ditulis pemilik 2026-11-18, **jangan ditulis ulang** oleh task ini; task ini hanya menyelaraskan threat model + menutup ADR-004/008/010/011 dan memverifikasi konsistensi. Total berkas ADR kini **12** (001–012, terhitung via ls 2026-11-18).

Task ini dokumen. Kontrol hanya boleh naik `[desain]` → `[terpasang]` kalau ada berkas test + nama test yang
bisa ditunjuk.

**Files:**
- Create: `docs/threat-model-verification.md`
- Modify: `docs/threat-model.md`
- Modify: `docs/adr/ADR-004-idempotensi-outbox.md`, `docs/adr/ADR-008-object-storage.md`, `docs/adr/ADR-010-monorepo-cicd.md`, `docs/adr/ADR-011-alokasi-stok.md`
- Modify: `docs/adr/ADR-005-reservasi-stok.md`, `docs/adr/ADR-006-gateway-pembayaran.md`, `docs/adr/ADR-007-bff-vs-langung.md`, `docs/adr/ADR-009-waiting-room.md`

- [ ] **Step 1: Tabel verifikasi kontrol (bahan baku threat model)**

`docs/threat-model-verification.md`, kolom `id T-n | kontrol | berkas test | perintah | hasil | status`:

```
| T-04 | X-Frame-Options + frame-ancestors 'none' | apps/api/tests/Feature/Security/EdgeHeadersTest.php (asersi 5-6) | composer ci:test -- --filter EdgeHeaders | 8 assertions OK | [terpasang] |
| T-07 | CSP script-src 'self' + nonce per app | .../EdgeHeadersTest.php::csp_web_mengunci_script_src_dan_frame_ancestors | idem | OK | [terpasang] |
| T-09 | rute admin ditolak server-side | .../AdminRouteDeniedTest.php::tamu_mendapat_401_bukan_200 | composer ci:test -- --filter AdminRouteDenied | 14 assertions OK | [terpasang] |
| T-12 | key sama + payload beda = 409 | apps/api/tests/Feature/Platform/IdempotencyGuardTest.php | composer ci:test -- --filter IdempotencyGuard | OK | [terpasang] |
| T-14 | tidak oversell 1000 paralel | harness Testcontainers Task 8 | composer ci:concurrency | lihat artefak | [terpasang untuk mekanisme; k6 Sprint 1] |
| T-28 | dump terbaca + off-node | infrastructure/backup/offnode-backup.sh + §3.4 runbook | bash offnode-backup.sh | sha256 tercatat | [terpasang untuk dump; object backup Sprint 1] |
| T-32 | request-id + trace_id di log tanpa PII | apps/api/tests/Feature/Observability/TraceCorrelationTest.php | composer ci:test -- --filter TraceCorrelation | 3 assertions OK | [terpasang] |
| T-06 | magic-byte + tolak SVG di request-attachments | belum ada (modul Requests = Sprint 3) | — | — | [desain] |
| T-10 | signature webhook constant-time + replay window | belum ada driver (Q-7) | — | — | [desain] |
| T-01 | MFA untuk seluruh operator (is_operator=true) | diputuskan pemilik 2026-11-18: 2FA TOTP wajib semua operator, bukan hanya superadmin (ADR-012 (RBAC/identitas/dependensi)) | — | — | [desain, mekanisme final] |
```

- [ ] **Step 2: Terapkan hasil ke `docs/threat-model.md`**

Edit **hanya** kolom `kontrol saat ini` dan `sisa risiko` pada baris yang punya bukti. Jumlah yang naik dihitung
dengan grep, bukan diingat: `grep -oc "\[terpasang\]" docs/threat-model.md` → catat angkanya di §7. Baris
`[desain]` yang tersisa **didaftarkan** di §6, tidak dihapus. Tambah satu subseksi di §7: "Verifikasi Sprint 0 —
lihat `docs/threat-model-verification.md`". Jangan menyentuh §4 (batas KI) dan jangan mengubah nomor Q mana pun.

- [ ] **Step 3: Jadwalkan sign-off + nyatakan yang diblokir**

Di §6 baris "Status" tulis: draf v1 selesai Sprint 0; **rencana awal sign-off klien: akhir Sprint 1; sign-off kini tercatat 2026-11-22 di chat bot Product Engineer oleh Fatharoni, dengan persetujuan klien dikonfirmasi pemilik** (Doc 03
P-6) dan menjadi **prasyarat keras sebelum kode auth ditulis** (ADR-007 (BFF/sesi) D-6a). Amandemen 2026-11-18:
arah yang dibangun sudah diputuskan — **sesi cookie same-site dua guard + CSRF wajib** (Task 17 membuktikan
CSP/nonce, Task 12 membangun guard/CSRF); bentuk lama session/BFF tidak berlaku. **ADR-007 (BFF/sesi) Accepted
untuk mekanisme**; sign-off Fatharoni tercatat di chat bot Product Engineer pada 2026-11-22, dengan persetujuan klien dikonfirmasi pemilik (threat model §6); Q-2 dijawab pemilik proyek pada 2026-11-22: klien tidak punya IdP/SSO sendiri; mekanisme sesi Sanctum dua guard tetap, tidak ada provider OIDC.
Kalimat yang dilarang keras: "ADR-007 diputus oleh implementasi" — mekanisme diputus **pemilik**, tercatat di amandemen.

- [ ] **Step 4: Blok "Penutupan Sprint 0" di ADR-004, ADR-008, ADR-010, ADR-011**

```markdown
## Penutupan Sprint 0 — Status: **Accepted, ditutup di Sprint 0 (ID 0.14)**
Bukti: `IdempotencyGuard` + outbox 7 antrian Doc 11 §8.1 + dedup `(provider, provider_event_id)` lewat
`inbox_events` (Task 7); test replay key sama = response identik, payload beda = 409 (Step 1 task ini).
Keputusan pengunci: **D-S0-4** enum status satu sumber (nilai dibaca dari CHECK Doc 09, tidak ada salinan
kedua) dan **D-S0-5** client TypeScript ter-generate sebagai satu-satunya cara frontend memanggil API
(`generated/` tidak pernah disunting tangan).
Konsekuensi yang diterima: `idempotency_keys` tumbuh selamanya -> TTL + pembersihan dijadwalkan Sprint 4;
replay window belum bisa diuji tanpa driver gateway (tetap Q-7 / ADR-006).
```

Per-ADR, satu blok tambahan spesifik. **ADR-008**: **D-S0-7** `MINIO_PUBLIC_ENDPOINT` dipakai menandatangani
URL yang dijangkau browser, berbeda dari endpoint internal `http://minio:9000` yang dipakai container (test
Task 9 membuktikan keduanya); enam bucket sesuai Doc 01 §9.2, `exports` dengan lifecycle TTL, `audit-archives`
restricted; **dump backup bukan bucket ketujuh** (Doc 11 §8.7) — kalimat ini ditulis di ADR karena ADR tempat
orang mencari keputusan; butir CDN/edge **tetap terbuka** (Q-6) dan tidak disebut ditutup. **ADR-010**: tiga
jalur rilis mandiri (Task 1, Task 20), dua lockfile, `paths:` filter, rollback = deploy ulang tag tanpa
`migrate:rollback`, Doc 10 M-1…M-13 sebagai lampiran. **ADR-011**: ditutup dengan **default satu gudang**
(`warehouses` berisi satu baris dari ReferenceSeeder; kunci agregat `(warehouse_id, sku_id)` tetap ada di skema
sehingga multi-gudang bukan migrasi merusak) + kalimat eksplisit "dibuka kembali **hanya** bila Q-5 berubah".

- [ ] **Step 5: ADR-005/006/009 dinyatakan tetap terbuka; ADR-007 mekanisme Accepted (amandemen 2026-11-18)**

Blok identik di ketiga berkas (005/006/009): `## Status Sprint 0: TETAP TERBUKA (ID 0.14)`, "alasan bukan kelalaian dev:
butuh jawaban klien/hasil tes yang belum ada", "yang sudah disiapkan supaya penutupannya cepat: antarmuka
driver + contract test suite (Sprint 2), runbook provider-agnostic, tidak ada kredensial sandbox di repo".
ADR-007 (BFF/sesi) tidak memakai blok ini — amandemen 2026-11-18 sudah menulis statusnya.
Pemetaan ditulis sebagai **satu tabel** di bagian bawah ADR-009 (Doc 11 §8: satu fakta satu pemilik):

| ADR | Topik | Menunggu |
|---|---|---|
| ADR-005 | reservasi TTL + resolusi late payment | keputusan bisnis B-PO + **Q-7** (jalur `paid_stock_exception` bergantung semantik settlement) |
| ADR-006 | gateway + semantik settlement/refund | **Q-7** |
| ADR-007 (BFF/sesi) | mekanisme sesi cookie same-site + dua guard | **Accepted untuk mekanisme 2026-11-18**; sign-off Fatharoni tercatat di chat bot Product Engineer pada 2026-11-22, dengan persetujuan klien dikonfirmasi pemilik (threat model §6); Q-2 dijawab pemilik proyek pada 2026-11-22: klien tidak punya IdP/SSO sendiri; mekanisme sesi Sanctum dua guard tetap, tidak ada provider OIDC |
| ADR-009 | Waiting Room coverage/threshold/bypass | **Q-4** (angka k6) **dan Q-6** (plan Cloudflare) |

- [ ] **Step 6: Audit konsistensi nomor ↔ topik ↔ judul berkas**

```
$ python C:/temp/audit_sweep3.py | grep -E "§18 ADR|berkas ADR|penamaan inline"
   §18 ADR 1..10 di Doc 11 §3: 10 baris
   berkas ADR: 12
   penamaan inline ADR-0NN (topik): cocok semua
$ python C:/temp/scan_unicode.py docs/**/*.md            -> selesai: 0 kemunculan mencurigakan
```

`12` berkas vs `10` baris peta adalah benar (amandemen 2026-11-18; angka lama 11 tidak berlaku): tiga baris pertama Doc 11 §3 menaungi ADR-001/002/003 kita yang
sudah ada sebelum Blueprint, dan ADR-012 (RBAC/identitas/dependensi) ditambahkan 2026-11-18 di luar peta §18 (gate E menghitung 12 — Doc 11 §4.4 J). Gerbang tambahan: `grep -rnE "99[.,]9 %|high availability|redundan|auto-failover"
docs --include=*.md | grep -vE "tidak mengklaim|bukan gerbang|jangan mengklaim|Dilarang|B-15|D-22"` → **kosong**
(DoD §3 butir 6).

- [ ] **Step 7: Commit**

`git add docs/threat-model.md docs/threat-model-verification.md docs/adr`
lalu `git commit -m "docs(sprint-0): selaraskan threat model dengan test nyata + tutup ADR-004/008/010/011"`.

### Task 20 — ID 0.4: CD staging mandiri per app + rollback + uji restore nyata

Bentuk deploy mandiri (**satu dipilih**): **satu berkas workflow** yang pada `push` ke `main` menghitung app
yang berubah lewat `dorny/paths-filter` dan hanya menjalankan job app itu, plus `workflow_dispatch` berpilihan
app untuk rilis manual. Alasan: ADR-010 K-1 menuntut "satu app rilis tanpa menyentuh dua lainnya" **dan** satu
tempat perubahan pipeline — tiga berkas identik adalah tiga tempat untuk lupa. Reusable workflow tidak dipakai
karena memindahkan langkah ke berkas kedua di luar kepemilikan task ini.

**Files:**
- Create: `.github/workflows/deploy-staging.yml`
- Create: `docker/compose.staging.yaml`
- Create: `infrastructure/deploy/{deploy.sh,smoke.sh,env.staging.example}`
- Modify: `docs/runbooks/backup-restore-drill.md`, `docs/03-sprint-plan.md` (§11), `docs/threat-model-verification.md`
- Create: `docs/sprint-0-report.md`

- [ ] **Step 1: Deteksi app yang berubah + matriks rilis**

```yaml
name: deploy-staging
on:
  push: { branches: [main] }
  workflow_dispatch:
    inputs: { app: { description: "app yang dirilis", type: choice, options: [api, web, admin, all] } }
permissions: { contents: read, packages: write }
jobs:
  detect:
    runs-on: ubuntu-latest
    outputs: { targets: ${{ steps.pick.outputs.targets }} }
    steps:
      - uses: actions/checkout@v4
      - uses: dorny/paths-filter@v3
        id: filter
        with:
          filters: |
            shared: &shared ['packages/api-contracts/**', 'packages/ui/**', 'packages/shared-types/**', 'infrastructure/**', '.github/workflows/**']
            api:   ['apps/api/**', *shared]
            web:   ['apps/web/**', *shared]
            admin: ['apps/admin/**', *shared]
      - id: pick
        run: |
          case "${{ inputs.app }}" in all) echo 'targets=["api","web","admin"]' >> "$GITHUB_OUTPUT"; exit 0;; api|web|admin) echo "targets=[\"${{ inputs.app }}\"]" >> "$GITHUB_OUTPUT"; exit 0;; esac
          t=""; for a in api web admin; do [ "${{ steps.filter.outputs.$a }}" = "true" ] && t+="\"$a\","; done
          echo "targets=[${t%,}]" >> "$GITHUB_OUTPUT"
  deploy:
    needs: detect
    if: needs.detect.outputs.targets != '[]'
    strategy: { fail-fast: false, matrix: { app: ${{ fromJSON(needs.detect.outputs.targets) }} } }
```

`packages/ui/**` masuk anchor `shared`, jadi Task 16 memicu **kedua** frontend dan bukan API — perilaku yang
diinginkan; ditulis sebagai komentar di berkas.

- [ ] **Step 2: Build image di container + tag per app**

Satu langkah matrix: `docker build -f apps/${{ matrix.app }}/Dockerfile -t
registry.local/elzara/${{ matrix.app }}:${{ github.sha }} .` (Doc 01 §11.2: `composer --no-dev
--optimize-autoloader` untuk API; `npm ci && next build` di `node:22` standalone untuk kedua frontend). Tidak
ada `npm run build` di runner langsung (D-S0-5/D-S0-6). Push, lalu tulis SHA ke
`/var/lib/elzara/releases/<app>`. Image tidak pernah berisi `.env`/credential/data.

- [ ] **Step 3: Urutan deploy persis Doc 01 §11.4**

```bash
set -euo pipefail
APP="${1:?app}"; SHA="${2:?sha}"; C="docker compose -f docker/compose.staging.yaml"
if [ "$APP" = "api" ]; then
  ${C} exec -T api php artisan down --render="errors::503" --retry=90   # 1 maintenance mode
  ${C} exec -T worker php artisan horizon:terminate                     # 2 drain graceful
  ${C} run --rm worker php artisan horizon:wait --max=120 || true       #   (horizon:clear DILARANG, queue-stuck §3.3)
  bash infrastructure/backup/offnode-backup.sh                          #   pre-<release>, Doc 10 §9 langkah 1
  ${C} exec -T api php artisan migrate --force                          # 3 backward-compatible (M-4/M-13)
fi
${C} pull "$APP" && ${C} up -d --no-deps "$APP"                         # 4 up (hanya app ini)
[ "$APP" = "api" ] && ${C} up -d --no-deps worker scheduler
bash infrastructure/deploy/smoke.sh                                     # 5 smoke
[ "$APP" = "api" ] && ${C} exec -T api php artisan up          # 6 maintenance off
echo "${SHA}" > "/var/lib/elzara/releases/${APP}"
```

Untuk app frontend langkah 1–3 dan 6 dilewati — di situlah kemandirian dibuktikan, bukan dideklarasikan.

- [ ] **Step 4: Smoke test nyata**

```bash
set -euo pipefail; S=https://staging.elzara.test
curl -fsS "$S/" | grep -q "<title>"                                      # storefront memuat
curl -fsS "$S/api/v1/products?per_page=1" | jq -e '.data | length >= 1'   # GET /products
# Amandemen 2026-11-18: cookie admin = elzara_admin_session (nama lama elzara_session diganti, ADR-007 (BFF/sesi) D-2a).
# Login admin = email+password lalu 2FA TOTP wajib (202 sampai verify) — smoke memakai secret TOTP staging dari secret store, bukan mematikan 2FA.
# Mutasi wajib CSRF: panggil GET /sanctum/csrf-cookie dulu, kirim cookie XSRF-TOKEN sebagai header X-XSRF-TOKEN di setiap POST.
SID=$(curl -fsS -c - -X POST "$S/api/v1/auth/login" -H 'Content-Type: application/json' \
  -d "{\"email\":\"${STAGING_ADMIN_EMAIL}\",\"password\":\"${STAGING_ADMIN_PASSWORD}\"}" \
  | awk '/elzara_admin_session/ {print $NF}'); [ -n "$SID" ] || { echo "login admin gagal"; exit 1; }
curl -fsS -b "elzara_admin_session=$SID" "$S/admin" -o /dev/null -w 'admin %{http_code}\n'
curl -fsS -b "elzara_admin_session=$SID" "$S/api/v1/admin/feature-flags" | jq -e '.data[].code' | grep -q '^checkout.enabled$'
```

Runner Linux + `.gitattributes` LF (spec §4): kegagalan karena CRLF dianggap bug konfigurasi, bukan bug kode.
`bash infrastructure/deploy/smoke.sh` → empat baris keluaran, baris terakhir `checkout.enabled`.

- [ ] **Step 5: Rollback = tag sebelumnya, TANPA `migrate:rollback`**

```yaml
  rollback:
    if: ${{ failure() }}
    needs: deploy
    runs-on: ubuntu-latest
    steps:
      - run: |
          PREV=$(ssh staging "cat /var/lib/elzara/releases/${{ matrix.app }}")
          ssh staging "APP_ENV=staging bash /opt/elzara/deploy.sh ${{ matrix.app }} $PREV"
```

Komentar wajib di berkas: `migrate:rollback` **tidak pernah** alat utama (D-S0-9, Doc 10 §9); kalau skema harus
mundur, jalurnya `migrate:status` lalu `migrate:rollback --step=N` **hanya** bila N migrasi terakhir belum
disentuh kode mana pun — selain itu **restore `pre-<release>.dump`**. File `releases/<app>` per app adalah bukti
rollback satu app tidak menyentuh dua lainnya.

- [ ] **Step 6: `compose.staging.yaml` (BARU; lengkapi, jangan duplikasi)**

Overlay staging dari `compose.yaml` Task 3: `services:` **hanya** berisi override `api`, `web`, `admin`,
`worker`, `scheduler`, `nginx` (+ blok `profiles: ["monitoring"]` Task 18 Step 8). Tidak ada definisi ulang
`postgres`/`redis`/`minio` serta service profil `elasticsearch`; tidak ada publikasi port data store; tidak ada `mailpit` (dev only).
Wajib ada: `restart: unless-stopped`, `healthcheck` per service, `depends_on: condition: service_healthy`,
`ulimits` + `deploy.resources.limits` untuk `worker`, network `frontend`/`backend`/`monitoring`, `env_file` yang
gagal dengan pesan menyebut nama variabel hilang. Verifikasi: `docker compose -f docker/compose.yaml
-f docker/compose.staging.yaml config | grep -c "image: postgres"` → `1`.

- [ ] **Step 7: Uji nyata dijalankan SEKALI, hasilnya ditulis**

Satu sesi, satu urutan, semua angka dicatat (bukan direncanakan): `composer dev:up` dari repo kosong (detik;
gerbang <90 s spec §6) → `docker compose ps --format '{{.Name}} {{.Status}}' | grep -c '(healthy)'` (≥3 app) →
login admin `superadmin` + 2FA TOTP → storefront demo (10 produk seeder, `GET /catalog`) → satu order
`awaiting_payment` lewat fixture → replay `Idempotency-Key` sama = response identik (Doc 03 baris 64) → rilis
**hanya** `web`, lalu bandingkan `docker inspect -f '{{.Id}}'` container `api` dan `admin` sebelum dan
sesudah — **harus identik** → restore `pg_dump` Task 18. Hasil langkah 1–7 masuk `docs/sprint-0-report.md`
(kolom `waktu | perintah | keluaran | pass/fail`); hasil restore masuk tabel §3.4
`docs/runbooks/backup-restore-drill.md`. Bukti yang diterima untuk langkah rilis-mandiri adalah **dua nilai
container id yang sama persis**, bukan kalimat "tidak terpengaruh".

- [ ] **Step 8: Risiko tersisa dibawa, tidak hilang (DoD §3 butir 7)**

Tambah baris di `docs/03-sprint-plan.md` §11, satu baris per risiko + pemilik: (a) drill restore baru sekali di
staging lokal, belum di VPS klien — registry/SSH key belum ada (D-S0-8); ulang pada deploy staging pertama;
pemilik penanggung jawab rilis. (b) empat metrik §15 belum bersumber data — panel terpasang, tabel lahir Sprint
2/Phase 4; jangan dikutip sebagai bukti kapasitas. (c) ambang alert masih angka teknis, belum kapasitas teruji
(Q-4); tinjau setelah task 1.7. (d) CSP nonce belum diverifikasi terhadap upgrade Next berikutnya; test
Playwright Task 17 dijalankan tiap upgrade. (e) sign-off Fatharoni tercatat di chat bot Product Engineer pada 2026-11-22, dengan persetujuan klien dikonfirmasi pemilik (threat model §6, P-6); bukan bukti penutupan
Sprint 1. Tidak ada baris yang menjanjikan angka ketersediaan; tidak ada kalimat HA/99,9 %.

- [ ] **Step 9: Gerbang akhir Sprint 0 — semua hijau dalam satu kali jalan**

```bash
composer dev:up                                                        # 1 satu perintah dari repo kosong
docker compose -f docker/compose.yaml ps --format '{{.Name}} {{.Status}}' | grep -c '(healthy)'   # 2 >= 3 app
bash infrastructure/deploy/smoke.sh                                    # 3 tiga app jalan + login admin + storefront demo
composer ci:test -- --filter OrderAwaitingPaymentTest                  # 4 satu order awaiting_payment lewat fixture
composer ci:test -- --filter IdempotencyReplayTest                     # 5 replay key sama = response identik (Doc 03 baris 64)
composer ci:all                                                        # 6 lint + larastan + eslint/typecheck 2 app + unit/feature + Testcontainers + C1-C8
npm --workspace @elzara/ui test                                        # 7 kontras AA pada 4 preset
npm --workspace @elzara/ui run brand-check                             # 8 satu berkas brand -> dua app, nol komponen disentuh
npx playwright test                                                    # 9 kedua app: CSP + admin ditolak server-side
python C:/temp/audit_sweep3.py                                         # 10 gerbang audit dokumen (DoD §3 butir 4)
```

Diterima sebagai hijau: langkah 6 tanpa satu pun `skipped` pada suite concurrency; langkah 8 mencetak
`brand-single-source: kedua app berubah, nol komponen disentuh`; langkah 10 mencetak
`§18 ADR 1..10 di Doc 11 §3: 10 baris`, `penamaan inline ADR-0NN (topik): cocok semua`,
`berkas ada di docs/runbooks: 11 (kurang -)`, dan **nol** baris `FAIL`. Bila salah satu merah, Sprint 0 tidak
dinyatakan selesai — ia dinyatakan bersama daftar yang merah (spec §2: "selesai" = bisa didemokan di staging,
bukan lanjut desain).

- [ ] **Step 10: Commit**

`git add .github/workflows/deploy-staging.yml docker/compose.staging.yaml infrastructure/deploy docs/runbooks docs/sprint-0-report.md docs/03-sprint-plan.md docs/threat-model-verification.md`
lalu `git commit -m "feat(sprint-0): CD staging mandiri per app + rollback by tag + hasil restore drill nyata"`

---
## 5. Verifikasi akhir sprint (script, bukan klaim)

**Enam** script ini adalah gerbang DoD §3 butir 4 (peta lengkap gate ada di Doc 11 §8.8; amandemen 2026-11-18: sebelumnya lima, `check_layout.py` ditambahkan sebagai gate I). Semuanya jalan di host dengan Python 3.13 dan membaca `docs/` apa adanya; `scripts/verify-docs.sh` menjalankan semuanya dan memperluas gate G ke seluruh berkas teks repo:

| Script | Memperiksa | Keluaran "selesai" hari ini |
|---|---|---|
| `python C:/temp/audit_sweep3.py` | pemeriksaan A–H: empat sumbu enum sama di Doc 09/01/02; 11 runbook; register event tunggal; nol nama mati di baris aktif; cakupan Blueprint §16/§18/§19/§20 (berkas ADR = 12); nol sitasi nomor bagian mati; nol homoglyph; **H = kepemilikan tabel** — Doc 09 §19 harus sama persis dengan Doc 01 §3.1 pohon modul **dan** kolom "Tabel inti" §3.3, satu tabel satu modul, label modul mengikuti Blueprint §3 (angka tabel tidak ditulis di sini — pemiliknya Doc 09 §19) | `LULUS — semua pemeriksaan A–H bersih` |
| `python C:/temp/audit_blueprint_coverage.py` | **kelengkapan** terhadap Blueprint: 29 daftar token wajib + tiga perbandingan yang dibaca langsung dari berkas Blueprint (S1 14 grup & 87 submenu §4 -> Doc 06 §2.2; S2 60 tabel minimum §6 -> Doc 09 §19 dengan tiga pengecualian tertulis; S3 18 jalur endpoint §7 -> Doc 12) + scan kontradiksi nama/klaim lama | `LULUS — COVER (29 daftar) + S1-S3 + KONTRADIKSI bersih` |
| `python C:/temp/verify_schema.py` | jumlah tabel Doc 09 §19 == deklarasi; graf FK asiklik; nama lama tidak tersisa; tabel §20 terdokumentasi | `LULUS — <n> tabel, konsisten dengan Doc 11` (`<n>` sengaja tidak ditulis di register ini — satu-satunya pemilik angka = Doc 09 §19; klaim lama "121 = 118 + 3" tidak berlaku setelah ADR-012 menambah tabel) |
| `python C:/temp/check_layout.py` | **gate I (docs = repo):** 56 entri pohon Doc 01 §3.1 ada di disk; nol sisa nama layout lama; 7 nama antrian di `docker/compose.yaml` DAN `QueueRouting::QUEUES`; 6 bucket di `init-buckets.sh` DAN `filesystems.php`; tanpa `package.json` root/workspaces; basename migrasi M-3 unik se-band, pemilik = direktori; 18 jalur §7 ada di `openapi.yaml`; 22 modul = Doc 01 = Doc 09 = `app/Modules/` | `LULUS — pohon Doc 01 §3.1 = filesystem` |
| `python C:/temp/scan_unicode.py` | karakter CJK/hangul/Sirilik/fullwidth di seluruh `docs/` | `selesai: 0 kemunculan mencurigakan` |
| `python C:/temp/list_dead_cites.py` | rujukan `Doc NN §M` dan `file.md:nomor` yang menunjuk tidak ada | 0 temuan |

Yang tidak bisa dibuktikan script — dan karena itu dibuktikan test PHPUnit di task-nya: event tidak hilang ketika worker mati di antara commit dan publish (Task 9), dua dispatcher tidak mengirim ganda (Task 9, `SKIP LOCKED`), 100 request idempotensi menghasilkan replay identik (Task 10), penolakan otorisasi terjadi di server (Task 12), presigned URL memakai host yang dijangkau browser (Task 13), `ReferenceSeeder` dua kali = nol perubahan (Task 15).

## 6. Konsekuensi yang harus diterima

1. Dokumen ini panjang (±4 900 baris) karena larangan placeholder: setiap langkah yang menyentuh kode memuat kode nyata. Kalau eksekusi nanti ternyata menabrak, yang dipotong adalah prosa task, bukan isinya.
2. Angka hasil (spec §6): `up` cold start < 90 s, build tiga image < 6 menit cache hangat, CI < 12 menit, harness D-9 repeatable < 60 s. Tiga angka pertama diukur di Task 3/5/20 dan **dilaporkan apa adanya**; bila tidak tercapai, yang dikurangi adalah paralelisasi, bukan targetnya.
3. Sprint ini tidak menghasilkan satu pun fitur yang dijual. Bila muncul permintaan "tambah layar produk", jawabannya Sprint 2 — bukan pengecualian di Task 15.
4. Keputusan klien selain Q-2 (Doc 11 §6) tetap terbuka dan **sengaja** terbuka: ADR-005/006/009 tidak ditutup supaya Sprint 0 tidak membeli asumsi (amandemen 2026-11-18: ADR-007 (BFF/sesi) keluar dari daftar ini — mekanisme Accepted, sign-off Fatharoni tercatat di chat bot Product Engineer pada 2026-11-22, dengan persetujuan klien dikonfirmasi pemilik (threat model §6); Q-2 dijawab pemilik proyek pada 2026-11-22: klien tidak punya IdP/SSO sendiri; mekanisme sesi Sanctum dua guard tetap, tidak ada provider OIDC; ADR-012 (RBAC/identitas/dependensi) Accepted). Jalur paralel klien ada di Doc 03 §9 (P-1…P-8).

---


## Lampiran A — catatan penulis bagian (dipertahankan apa adanya)

> **Catatan 2026-11-18:** lampiran ini historis (2026-11-17) dan dipertahankan sebagai rekaman, bukan instruksi aktif. Resolusi yang menyebut bentuk lama (securitySchemes ditunda, session/BFF, pivot lama, angka tabel tulis-tangan) sudah **diganti** amandemen 2026-11-18 di badan plan (D-S0-6, D-S0-9, D-S0-14, D-S0-15, Task 11/12/15, §5). Yang mengikat = badan plan + Doc 11 §4.4.

Setiap penulis bagian dilarang mengikuti premis brief yang bertentangan dengan dokumen repo secara diam-diam. Ini laporan mereka, sebelum resolusi di Lampiran B.

### A.1 — Task 1–5 (jalur A)

Catatan untuk perakit dokumen (fakta brief vs isi repo; tidak saya ikuti diam-diam): (1) Doc 01 §3.1 baris 174 masih menulis "Laravel 12 modular" dan plan lama `.codex/plans/2026-11-17-sprint-0-fondasi.md` baris 5-9 menulis Laravel 12 + Inertia + React 18, padahal baseline Task 1 mengukur `laravel/framework` v13.42.0 / skeleton v13.11.0 dan `next` 16.4.6 - Doc 01 §3.1 perlu diselaraskan sebelum Task 6. (2) Doc 03 ID 0.2 menuliskan "Makefile up/migrate/test" dan gerbang akhir Sprint 0 (Doc 03 baris 64) menyebut `make up`, padahal `make` tidak ada di host (terverifikasi) dan spec §4 menetapkan `composer dev:*` sebagai jalur resmi - bagian saya memakai `composer dev:up`; Makefile tetap ada sebagai pembungkus. (3) Doc 01 §11.3 menunjuk `apps/api/.env.example` sebagai satu-satunya pemilik daftar variabel, sementara brief menugaskan root `.env.example` - saya tulis dua berkas dengan pembagian eksplisit (root = level compose, API = level aplikasi) dan `EnvVarCoverageTest` membaca keduanya; perlu satu keputusan kamu. (4) Doc 12 §1.2 G2 mewajibkan setiap operasi punya `security`, tetapi §3.1 melarang `securitySchemes` dibuat sebelum ADR-007 tertutup - saya pakai `x-elzara-auth` dan tidak menulis `security`; kalau kamu memilih mengubah G2, Task 4 Step 2 perlu disesuaikan. (5) Module loader saya letakkan di `apps/api/app/ModuleLoader/` karena `app/Platform/**` adalah kepemilikan Task 6-20, sedangkan plan lama (D-S0-1 versi lama) menaruh module loader di `app/Platform/` - pilih satu rumah sebelum Task 6 berjalan. (6) Doc 01 §3.1 menaruh edge di `infrastructure/nginx/` sementara brief menugaskan `docker/nginx/**`; saya pakai path brief dan tidak membuat `infrastructure/nginx/` di Task 2 Step 1 supaya tidak ada folder hantu. (7) `jq` tidak terpasang di host (terverifikasi), jadi gerbang yang di spec ditulis `| jq` saya ganti `php -r` dengan hasil byte-identik. (8) Versi `phpunit/phpunit` di registry 13.4.7 tetapi yang terpasang adalah constraint bawaan skeleton Laravel 13 - Blueprint §16 "Pest/PHPUnit" tetap sah, hanya angkanya jangan ditulis sebagai 11 seperti di plan lama. (9) Target panjang bagian 380-480 baris tidak tercapai untuk Task 1-5: muatan yang diwajibkan brief (dua Dockerfile penuh, compose 11 service, `openapi.yaml` 18 operasi, tiga berkas test PHP lengkap, workflow sembilan job) menghasilkan sekitar 1.100 baris; saya memilih memadatkan YAML ke gaya flow dan memangkas prosa daripada mengorbankan kode nyata yang dilarang berupa placeholder.
### A.2 — Task 6–10 (jalur B)

Catatan untuk agen perakit (brief bertentangan dengan dokumen repo; dokumen repo yang diikuti): (1) Doc 12 §3.2 mewajibkan **ULID** untuk `X-Request-Id`, bukan UUIDv7 - Task 7 memakai `Str::ulid()`. (2) Doc 09 §17 memakai `dispatched_at`, bukan `sent_at`, pada `outbox_events` - Task 9 memakai `dispatched_at`. (3) `BusinessRuleViolation`, `UnauthorizedAction`, dan `VersionStale` tidak ada di peta Doc 12 §4.3; yang dipakai adalah `InvalidTransition` (409 `state:transition-not-allowed`) untuk penolakan transisi, `QuotationVersionStale` (409) untuk versi basi, dan baris 403 `authorization:forbidden` yang tidak punya kelas exception (ditangani `HttpExceptionInterface` di renderer). (4) `outbox_events` tidak punya kolom `status` maupun `event_id uuid` di Doc 09 §17: dead-letter dinyatakan sebagai predikat `dispatched_at is null and attempts >= 5`, dan `event_id` untuk `inbox_events` diturunkan deterministik lewat `uuid5(namespace, outbox.id)` agar retry tidak melahirkan event baru. (5) `idempotency_keys` adalah L2 karena menunjuk `users` (L1) yang baru ada di band Task 11 (`0007+`), jadi `0006` tidak bisa memuat `REFERENCES users(id)` tanpa melanggar C3 - FK menyusul sebagai migrasi band Identity. (6) Doc 12 §3.4 menuntut response body tersimpan untuk replay byte-identik, sedangkan Doc 09 §17 hanya menyediakan `response_ref varchar(200)` - Sprint 0 menguji replay pada level store + unique index + pointer; dua kolom tambahan harus menjadi PR Doc 09 oleh pemilik skema. (7) Pemetaan event -> antrian tidak dimiliki dokumen mana pun sebagai daftar: Doc 12 §6.2 hanya eksplisit untuk `PaidStockExceptionRaised -> orders`, sisanya dideresi dari kolom Penerbit/Konsumen dan dinyatakan satu kali di `QueueRouting` - perlu disahkan di Doc 11 §8 atau Doc 12 §6. (8) Doc 10 §2/M-3 meminta format `NNNN_<module>_<slug>`, jadi berkas `0001`-`0003` (tabel bawaan framework, tidak dimiliki modul mana pun) ditulis tanpa segmen modul sesuai Doc 10 §3 butir 3 - mohon konfirmasi bahwa aturan basename-unique C2 menoleransi ini. (9) `docs/runbooks/queue-stuck.md` §3.3 memberi urutan prioritas dan perintah `horizon:pause --queue=reports|imports` tetapi tidak memberi nama proses supervisor, jadi nama supervisor diambil dari tujuh nama antrian dan bobotnya diekspresikan lewat `minProcesses`/`maxProcesses`. (10) Jumlah tabel fondasi yang diassert adalah 6 hasil `information_schema` (tujuh termasuk `migrations`); angka target akhir hanya ada di Doc 09 §19 dan tidak disalin ke task ini.
### A.3 — Task 11–15 (jalur C)

Catatan untuk perakit dokumen — konflik yang saya temukan dan **tidak** saya ikuti diam-diam:
1. Brief Task 15 menulis `migrate:fresh --seed --class=ReferenceSeeder`; Doc 10 §8 baris 311 menyatakan bentuk `--seed=Kelas` tidak ada di Laravel dan `db:fresh` = `migrate:fresh` + `db:seed --class=ReferenceSeeder`. Saya ikuti Doc 10; `DatabaseSeeder` hanya memanggil `ReferenceSeeder`.
2. Brief menyebut Doc 12 §2.2 memuat endpoint auth — §2.2 tidak memuatnya; §2.4 baris 289 justru **menunda** grup Auth & sesi sampai ADR-007 tertutup. Task 12/13/14 menormakan 9+3+3 path lebih dulu dari yang dokumen janjikan (baris 298 mengizinkan penutupan bertahap lewat PR yang menulis spec lebih dulu): putuskan apakah ini diterima, dan kalau tidak, pindahkan pendaftaran rute ke Task 19.
3. Kill switch: brief menulis 422/403; Doc 12 §4.3 + Doc 11 §8.7 sudah mendaftarkan **503 `CHECKOUT_DISABLED`** (`urn:elzara:problem:server:checkout-disabled`, `retryable=true`) — bukan `error_code` yang belum terdaftar, jadi tidak ada nama baru yang saya karang. Saya pakai 503.
4. Brief meminta test "override per role berlaku"; `feature_flag_overrides` (Doc 09 §15) hanya punya `(flag_code, env, enabled, rollout_percent, updated_by)` — tidak ada kolom role maupun jendela waktu. Yang diimplementasi dan diuji: override per env + rollout per subjek. Override per role menuntut perubahan Doc 09.
5. Doc 10 §8 baris 301 memakai kode flag `drops`, `reviews`, `quotation_flow`; Doc 09 §15 memakai `drops.enabled`, `reviews.enabled` dan tidak mengenal `quotation_flow`. Saya ikuti Doc 09 (pemilik katalog + CHECK) dan tidak menanam `quotation_flow`.
6. `staff_2fa_secrets.recovery_hash varchar(255)` hanya menampung satu hash → desain jadi **satu** recovery code aktif yang dirotasi, bukan sepuluh; sepuluh kode menuntut kolom/tabel baru + perubahan Doc 09 §2.
7. Doc 09 §15 menulis `feature_flags.updated_by`/`settings.updated_by` sebagai `NOT NULL REFERENCES users(id)`, bertabrakan dengan "nol kredensial/user di repo" untuk seeder. Task 11 membuat keduanya nullable — perlu persetujuan pemilik Doc 09.
8. Doc 09 §17 `audit_logs` **tidak** punya kolom `request_id`, sedangkan Doc 12 §3.2 baris 324 menyatakan request-id ditulis ke kolom itu (juga ke `webhook_receipts`, yang Sprint 2). Saya tidak menambah kolom; pilihannya: tambah di Doc 09 atau koreksi Doc 12.
9. Contoh Doc 10 §2 memakai `$t->timestamptz(...)`; Blueprint tidak punya metode itu (yang benar `timestampTz()` + `useCurrent()`). Semua migrasi memakai bentuk yang benar; contoh dokumen perlu dikoreksi.
10. Doc 10 §2 membatasi `DB::statement` ke view / CHECK bernama / `COMMENT ON`, tetapi `uq_addresses_default`, `uq_role_scopes_identity` (nama lama `uq_role_user_identity` diganti — amandemen 2026-11-18), `uq_localization_key_locale`, `idx_user_sessions_user` adalah indeks parsial/ekspresi yang hanya bisa dibuat dengan statement mentah — minta kelonggaran satu kalimat di §2.
11. Brief menaruh migrasi di `database/migrations/0007_*`–`0023_*`; Doc 10 M-1/B-1/Appendix B langkah 3 + arch test B6 menuntut `apps/api/app/Modules/<pemilik>/Infrastructure/Database/Migrations/` (hanya tabel framework yang di `database/migrations/`). Saya ikuti Doc 10; nomor dan rentang tetap `0007`–`0023`.
12. Brief + spec 0.11 menuntut `DemoSeeder` berisi 10 produk + 2 batch + 1 kuotasi, padahal `products`/`preorder_batches` ada di band Sprint 1 (`0200–0399`) dan `quotations` di Sprint 5 (`1000–1199`) menurut Doc 10 §4. Sprint 0 hanya bisa menanam 10 SVG + baris `media` + `catalog-manifest.php` (nanti dikonsumsi `DemoCatalogSeeder`); angka itu baru bisa dibuktikan di Sprint 1/5.
13. Tugas Task 14 (brief) menuntut stub `POST /orders` + `PlaceOrder` untuk membuktikan kill switch menolak **sebelum** Command jalan, tapi daftar kepemilikan bagian ini tidak menyebut `app/Modules/Orders/**`. Saya tulis dua stub minimal itu di dalam bagian ini (semua kelas yang dirujuk dokumen ini terdefinisi di dokumen ini, tidak ada placeholder) sehingga Task 14 juga menyentuh `app/Modules/Orders/` + satu baris `routes/api.php` milik Task 0.5. Kalau bagian A ternyata sudah membuat stub Orders, hapus langkah 5 Task 14 separuh pertama dan cukup tempel `->middleware('identity.checkout')`. Task 14 juga menambah `app/Modules/Audit/{Infrastructure/Models/AuditLog,Application/AuditLogger}.php` — modulnya milik bagian ini (brief), tapi penulis audit bisa saja dianggap milik task Audit lain; verifikasi sebelum rakit.
14. Nama skrip container yang saya pakai berasal dari brief (`composer dev:migrate`, `composer dev:test`, `composer dev:composer`, `composer dev:artisan`) ditambah `composer dev:db-fresh`/`db:demo` di `apps/api/composer.json`; langkah Task 13 memakai `docker compose -f docker/compose.dev.yaml exec mc` karena tidak ada `dev:mc` yang dijanjikan. Nama parity D-8 yang saya rujuk: `OpenApiRouteParityTest`. Sesuaikan kalau bagian A menamai lain.
15. Lapisan kelas saya ikut Doc 01 §3.2 baris 237-241, bukan ringkasan brief: model Eloquent di `Infrastructure/Models/` (arch test Task 10 me-glob jalur itu), domain event + exception di `Domain/`, interface/DTO lintas modul di `Contracts/`. Jadi `LocalizedString`, `FeatureFlag`, `FeatureFlagOverride`, `Setting`, `Media`, `AuditLog` tidak saya taruh di `Domain/`.
16. `DemoSeeder` menanam SVG di `database/seeders/Demo/assets/*.svg` (bukan `database/seeders/assets/` seperti bunyi brief) supaya berkas demo terpisah dari `Reference/`; `AssetProvenanceTest` mengunci jalur + jumlah 10 berkas itu.
17. Panjang berkas ini ±730 baris (62 langkah), di atas anggaran 400–500: 17 migrasi nyata + provisioning MinIO + validator magic-byte + blok OpenAPI + definisi exception/event/model yang dirujuk tidak bisa dipangkas tanpa melanggar larangan placeholder. Yang paling aman dipotong kalau perakitan butuh anggaran itu: blok YAML Task 12/14 (ganti dengan rujukan ke `packages/api-contracts/openapi.yaml`), daftar panjang factory di Task 15 langkah 9, dan blok komentar spesifikasi kolom Task 11 langkah 6–7 (sudah identik dengan Doc 09 §2/§15/§17).
### A.4 — Task 16–20 (jalur D)

Catatan untuk perakit dokumen (fakta brief vs isi repo — saya tidak ikut diam-diam):

1. **Label D-S0-* bertabrakan dengan plan lama.** Brief: D-S0-4 = enum satu sumber, D-S0-5 = client
   ter-generate, D-S0-7 = `MINIO_PUBLIC_ENDPOINT`. Di `.codex/plans/2026-11-17-sprint-0-fondasi.md`
   baris 21–30 nilainya lain: D-S0-4 = 2FA `pragmarx/google2fa`, D-S0-5 = tooling lewat container, D-S0-7 =
   middleware CSP ditulis tangan. Task 19 Step 4 + Task 20 Step 5 saya tulis mengikuti **brief**; bila tabel
   keputusan plan baru memakai penomoran lama, keempat rujukan itu harus diganti sebelum perakitan.
2. **Metrik Blueprint §15 = 12 sderet, bukan 11.** §15 mendaftar RPS; p50/p95/p99; 4xx/5xx; lock
   waits/deadlocks; connection pool saturation; Redis memory; queue lag; webhook delay; oversell anomalies;
   reservation expirations; MinIO error rate; waiting-room admissions **dan** waiting time. Spec 0.12 hanya
   menyebut 10 (tanpa pool saturation, Redis memory, waiting time). Saya tulis **12 panel** supaya tidak ada
   yang hilang; bila angka "11" yang diinginkan, itu keputusan pemotongan eksplisit, bukan saya putuskan sendiri.
3. **`GET /api/v1/feature-flags` tidak terdaftar di Doc 12.** Hasil grep seluruh `docs/`: yang ada hanya tabel
   `feature_flags` (Doc 09 §15), nama flag `checkout.enabled` (Doc 11 §8.7), dan response `503
   CHECKOUT_DISABLED` (Doc 12 §4.3). Task 16 Step 10 dan Task 20 Step 4 memakai path itu. **Untuk Anda:** ia
   harus masuk `openapi.yaml` lewat Task 4/Task 10; saya tidak menyentuh kontrak. Fallback yang sudah saya tulis
   supaya storefront tidak error: pemetaan `503 CHECKOUT_DISABLED` ke komponen alasan. Dua endpoint lain juga
   berada di luar kontrak publik dan butuh rumah di sisi API: `POST /api/v1/admin/metrics/backup-ok` dan
   `GET /metrics` pada `api:8080` (Task 18 Step 3, Step 6).
4. **`script-src 'self'` harfiah tidak bisa dipakai Next.js App Router** (inline flight script), sementara ID
   0.16 sendiri melarang CSP yang memutus mekanisme muat Next. Task 17 memakai `'self' + nonce` per-request dan
   Task 19/Doc 03 §11 mencatatnya sebagai risiko. Menuntut `'self'` tanpa nonce = hydration kedua app mati.
5. **ADR-004/008/010/011 sudah `Status: Accepted`** di berkasnya hari ini (008 dan 011 dengan butir terbuka),
   dan ADR-007 sudah `Proposed — diblokir Q-2`. "Menutup" karena itu saya artikan dan saya tulis sebagai blok
   **"Penutupan Sprint 0"** berisi bukti test + keputusan konkret, bukan perubahan status Proposed→Accepted.
   Doc 11 §3 juga **sengaja** memakai nomor Blueprint §18 yang bergeser satu terhadap ADR kita (Blueprint §18
   butir 3 = ADR-004 kita; butir 10 = ADR-011 kita), sehingga `berkas ADR: 11` vs `10 baris peta` adalah benar pada 2026-11-17 (tidak berlaku sejak amandemen 2026-11-18: kini 12 berkas, ADR-012 ditambahkan) —
   jangan menormalkan nomornya di tempat lain.
6. **Urutan Doc 01 §11.4 cocok** dengan brief (maintenance → drain → migrate backward-compatible → up → smoke →
   maintenance off). Yang tidak ada di dokumen: `horizon:wait` — saya pakai sebagai pelaksana "drain" bersama
   `horizon:terminate`, dan menyebut larangan `horizon:clear` dari `runbooks/queue-stuck.md` §3.3. Runbook
   sebelas pasangan nama↔file Doc 11 §8.2 saya pakai apa adanya (task ini tidak membuat runbook baru).

## Lampiran B — resolusi perakit atas catatan di atas

Semua angka/nama di bawah sudah diterapkan ke dokumen ini dan/atau ke dokumen sumber lewat PR yang sama. Tidak ada yang dibiarkan menggantung.

| # | Yang dilaporkan | Resolusi |
|---|---|---|
| 1 | Doc 01 §2/§3.1 masih "Laravel 12"; plan lama Inertia | Doc 01 §2, §3.1, §11.2 diubah ke Laravel 13 + Next 16 + `node:22`, dengan catatan baseline terukur 2026-11-17; berkas plan ini ditulis ulang total |
| 2 | `make up` / `make db fresh` / `make db snapshot` / `make test` masih jadi jalur resmi (Doc 03 §3, Doc 10 §10/Appendix B) | Diganti `composer dev:up`, `dev:db-fresh`, `dev:db-snapshot`, `dev:test`; Makefile tetap ada sebagai pembungkus tipis |
| 3 | Inventaris variabel: Doc 01 §11.3 menunjuk `apps/api/.env.example`, brief menunjuk root | **Satu berkas: root `.env.example`**, dikelompokkan per app. Doc 01 §11.3 sudah diubah; Task 3 menulis satu berkas, `EnvVarCoverageTest` membacanya |
| 4 | Doc 12 §1.2 G2 mewajibkan `security` per operasi, §3.1 melarang `securitySchemes` sebelum ADR-007 | Resolusi lama (2026-11-17): G2 didiamandemen jadi `security` **atau** `x-elzara-auth`. **Diganti amandemen 2026-11-18 (D-S0-6, Doc 11 §4.4 I):** larangan securitySchemes dicabut — skema cookie + CSRF wajib ada, `security` wajib per operasi, `x-elzara-auth` tetap penanda kategori |
| 5 | Rumah module loader (`app/ModuleLoader/`) vs shared kernel (`app/Platform/`) | Keduanya, dengan peran berbeda dan terdaftar di Doc 01 §3.1 (D-S0-8) |
| 6 | Edge config: Doc 01 §3.1 menulis `infrastructure/nginx/`, bagian A memakai `docker/nginx/**` | Doc 01 §3.1 sudah disesuaikan ke `docker/nginx/` (profil dev berdekatan dengan compose); `infrastructure/nginx/` produksi tetap milik Task 17 |
| 7 | `jq` tidak ada di host | Gerbang JSON memakai `php -r` dengan hasil byte-identik (D-S0-2) |
| 8 | PHPUnit: plan lama menulis "11"; registry punya 13.4.7 | D-S0-3: constraint bawaan skeleton Laravel 13 dipakai; tidak ada angka versi PHPUnit yang ditulis sebagai janji |
| 9 | Doc 12 §3.2 menuntut **ULID**, brief menulis UUIDv7 | Task 7 memakai `Str::ulid()`; kolom `request_id varchar(26)` ditambahkan ke `audit_logs` dan `webhook_receipts` di Doc 09 §17/§9 (D-S0-15) |
| 10 | `outbox_events` memakai `dispatched_at`, bukan `sent_at`; tidak ada kolom `status`/`event_id uuid` | Task 9 mengikuti Doc 09 §17 apa adanya: dead-letter = predikat `dispatched_at is null and attempts >= 5`; `event_id` inbox diturunkan deterministik `uuid5(namespace, outbox.id)` |
| 11 | `BusinessRuleViolation`, `UnauthorizedAction`, `VersionStale` tidak ada di peta Doc 12 §4.3 | Yang dipakai: `InvalidTransition` (409), `QuotationVersionStale` (409), 403 `authorization:forbidden` lewat renderer — tidak ada kelas exception baru di luar peta |
| 12 | `idempotency_keys` adalah L2 (FK ke `users`) tapi nomornya `0006`, sebelum `users` (`0010`) | Sengaja: migrasi idempotency dibuat **tanpa** `REFERENCES`, dan FK menyusul sebagai migrasi band Identity di Task 11 — tidak melanggar C3 karena tidak ada FK yang menunjuk nomor lebih besar. (Amandemen 2026-11-18: di disk trio reliability sudah satu berkas `0001_Platform_create_reliability_tables.php`; nomor `0006` lama tidak berlaku — D-S0-9) |
| 13 | Doc 12 §3.4 menuntut response body tersimpan untuk replay byte-identik; Doc 09 hanya punya `response_ref varchar(200)` | Sprint 0 menguji replay pada level store + unique index + pointer; dua kolom tambahan adalah PR milik pemilik skema (Doc 09 §17) dan tercatat di Doc 03 §11, tidak diklaim sudah ada |
| 14 | Peta event → antrian tidak dimiliki dokumen mana pun sebagai daftar | Task 9 menyatakan `QueueRouting` satu kali; pengesahan daftarnya masuk Doc 11 §8 sebagai butir kerja Sprint 0 penutup (Task 19 jalur D) — ditandai, bukan disembunyikan |
| 15 | Tabel bawaan framework tanpa segmen modul (`0001–0003_Framework_*`) | Segmen modul kini eksplisit `Framework`, jadi format `NNNN_<Module>_<slug>` tetap utuh dan C2 basename-unique tidak dilonggarkan (Doc 10 §3 butir 3 dibaca ulang, bukan dikesampingkan). (Amandemen 2026-11-18: disk tidak memuat migrasi Framework — `database/migrations/` kosong; skema penomoran lama tidak berlaku, lihat D-S0-9) |
| 16 | `queue-stuck.md` §3.3 tidak memberi nama proses supervisor | Nama supervisor diambil dari tujuh nama antrian; bobot lewat `minProcesses`/`maxProcesses` — tidak ada antrian kedelapan |
| 17 | `citext`, PK komposit pivot, indeks parsial | Doc 10 §2 diperluas: `DB::statement` sah untuk indeks parsial/ekspresi; PK komposit direkonsiliasi sebagai unique index bernama (Doc 09 §1). **Pengecualian amandemen 2026-11-18:** pivot spatie (`model_has_roles`/`model_has_permissions`/`role_has_permissions`) memakai composite PK asli karena paket membaca bentuk itu (Doc 11 §4.4 B, Task 11 keputusan 4) |
| 18 | `staff_2fa_secrets.recovery_hash` hanya menampung satu kode | Kolom jadi `recovery_hashes text[]` dengan sepuluh kode ter-hash, satu kali pakai (D-S0-15); resolusi ini tidak menambah tabel — jumlah tabel hanya dimiliki Doc 09 §19 (klaim lama "jumlah tidak berubah" tidak berlaku setelah ADR-012 menambah `user_identities` + `activity_log`, amandemen 2026-11-18) |
| 19 | Task 14 membutuhkan stub `Orders` + baris rute, jalur C tidak memiliki `app/Modules/Orders/**` | Stub eksplisit dan kecil, dimiliki jalur C, tercatat di matriks §3; controller itu hanya memanggil `PlaceOrder` untuk membuktikan kill switch menolak **sebelum** logika order ada — implementasi penuh tetap Sprint 2 |
| 20 | Isi demo katalog (produk/batch/kuotasi) tidak bisa ditanam di Sprint 0 | D-S0-11; Doc 03 0.11 dan spec §2 0.11 sudah diubah, dan daftarnya pindah ke gerbang Sprint 1/Sprint 5 |
| 21 | ADR-004/008/010/011 sudah `Accepted` sebelum Sprint 0 jalan | "Ditutup" di Task 19 berarti blok **Penutupan Sprint 0** (bukti test + keputusan konkret D-S0-x), bukan mengubah status Proposed→Accepted. Nomor Blueprint §18 yang bergeser satu terhadap ADR kita (ADR-003 K-3) **sengaja** — jangan dinormalkan |
| 22 | CSP `script-src 'self'` polos memutus hydration Next.js | D-S0-13: nonce per request, tercatat di spec §2 0.16 dan Doc 03 §11 sebagai risiko yang disadari |
| 23 | `composer ci:concurrency` dirujuk jalur D tapi belum didefinisikan jalur A | Ditambahkan ke skrip root Task 5 dan ke rantai `ci:all` (D-S0-12) |
| 24 | Nama test parity disebut berbeda dua cara (`OpenApiParityTest` / `OpenApiRouteParityTest`) | Kanonik: `OpenApiRouteParityTest` — semua rujukan sudah dinormalkan |
| 25 | Target panjang bagian (380–520 baris) tidak tercapai di keempat jalur | Diterima. Yang diizinkan berkurang adalah prosa, bukan kode nyata; memangkas kode berarti mengembalikan placeholder yang dilarang. Konsekuensinya dicatat di §6 |
