# Dependensi, Auth, dan RBAC (Gelombang 2026-11-18) Implementation Plan

> **Untuk pelaksana agen:** plan ini dieksekusi oleh sub-agen sesuai peran di
> `.codex/agents/` (migration-db, feature, testing, docker-rebuild, knowledge).
> Urutan mengikat Doc 11 §4.4 H: **migration-db -> feature -> testing ->
> docker-rebuild**; penulis knowledge berjalan paralel pada berkas disjoint dan
> selesai sebelum testing menjalankan gerbang dokumen. Satu task = satu sub-agen;
> dua penulis tidak pernah memegang direktori yang sama (matriks §1). Setiap task
> berakhir dengan **laporan keluaran nyata**, bukan commit: gelombang ini tidak
> melakukan `git commit` kecuali pemilik proyek meminta (D-DR-10).

**Goal:** memasang set dependensi terverifikasi 2026-11-18 di container, membangkitkan
tiga lockfile, membangun skema auth/RBAC sesuai keputusan pemilik (Doc 11 §4.4 A-J),
lalu membangun mekanisme auth nyata: dua guard + dua cookie sesi same-site + CSRF,
login admin email+password dengan gerbang `is_operator` dan 2FA wajib, login/registrasi
storefront hybrid (email+password, Google, X OAuth 2.0) dengan linking hanya lewat
email terverifikasi, RBAC granular per permission dengan grant langsung dan bypass
`is_admin` yang selalu berjejak di `audit_logs` - semuanya diuji nyata dan ditutup
oleh gerbang rebuild docker.

**Architecture:** modular monolith `apps/api` (satu-satunya penulis database, ADR-001);
mutasi hanya lewat Command (B2); event hanya membawa ID (B3); outbox pada transaksi
yang sama (B4). Auth = Sanctum session cookie same-site (ADR-007 (BFF/sesi) amandemen
2026-11-18), bukan bearer token; `personal_access_tokens` tidak dibuat. RBAC admin =
spatie/laravel-permission bentuk tabel standar, `guard_name = admin`; storefront tanpa
spatie, otorisasi = kepemilikan + status akun (ADR-012 (RBAC/identitas/dependensi)).
Kontrak `packages/api-contracts/openapi.yaml` tetap satu sumber kebenaran; Scramble
hanya draf (Doc 11 §4.4 E).

**Tech Stack:** PHP 8.4 (container `php:8.4` / `composer:2`), Laravel 13.43.0,
Sanctum 4.5.0, Horizon 5.52.0 (constraint `^5.51`, tidak ada v6), Socialite 5.33.0,
socialiteproviders/twitter 4.1.2, spatie/laravel-permission 8.5.0,
spatie/laravel-activitylog 5.1.5, spatie/laravel-data 4.23.1, dedoc/scramble 0.13.55,
sentry/sentry-laravel 4.30.0, maatwebsite/excel 4.0.7, PHPUnit 13.4.7, PHPStan 2.3.6,
Deptrac (lihat D-DR-3), Redis = ekstensi phpredis. Next.js 16 App Router + TS, build
`node:22`, standalone. PostgreSQL 16 nyata untuk semua test (B-11, tanpa SQLite).

**Spesifikasi sumber:** [Doc 11 §4.4 A-J](../../docs/11-blueprint-reconciliation.md)
(register mengikat) · [Doc 11 §8.8](../../docs/11-blueprint-reconciliation.md)
(enam gerbang) · [ADR-012 (RBAC/identitas/dependensi)](../../docs/adr/ADR-012-identitas-rbac-dependensi.md) ·
[ADR-007 (BFF/sesi) amandemen](../../docs/adr/ADR-007-bff-vs-langung.md) ·
[Doc 10 §1/§6/§7/§8/§10](../../docs/10-migration-plan.md) ·
[Doc 09](../../docs/09-database-schema.md) ·
[plan Sprint-0](./2026-11-17-sprint-0-fondasi.md)
(dibaca, tidak disunting; sedang diamandemen agen lain) · peran: `.codex/agents/`.

---

## 1. Ownership matrix (direktori -> task; tidak ada dua penulis di satu jalur)

| Jalur / peran | Task | Direktori/berkas yang dimiliki (TULIS) |
|---|---|---|
| migration-db | 1-5 | `apps/api/composer.json`, `apps/api/composer.lock`, `apps/api/config/{sanctum,permission,activitylog,scramble,sentry}.php`, `apps/api/app/Modules/Identity/Infrastructure/Database/Migrations/**`, `apps/api/app/Modules/Audit/Infrastructure/Database/Migrations/**`, `apps/api/app/Console/Commands/**`, `apps/api/database/migrations/**` |
| feature | 6-10 | `apps/api/app/Modules/Identity/{Domain,Application,Contracts}/**` + `Infrastructure/{Models,Http,Security}/**` + `IdentityServiceProvider.php`, `apps/api/app/Modules/Audit/{Domain,Application,Contracts}/**` + `Infrastructure/{Models,Http}/**` + `AuditServiceProvider.php`, `apps/api/app/Providers/**`, `apps/api/bootstrap/app.php`, `apps/api/routes/**`, `apps/api/config/{auth,session,elzara}.php`, `apps/api/database/seeders/**`, `apps/api/database/factories/**`, `packages/api-contracts/openapi.yaml` + `generated/**`, `packages/shared-types/src/**`, `apps/web/{app,lib,config}/**` + `middleware.ts`, `apps/admin/{app,lib,config}/**` + `middleware.ts` |
| testing | 11-13 | `apps/api/tests/**`, `apps/api/app/Modules/*/Tests/**`, `apps/api/deptrac.yaml`, `apps/api/deptrac-b2.yaml`, `apps/api/phpunit.xml` |
| docker-rebuild | 14 | `docker/compose.yaml`, `docker/profiles/**`, `docker/nginx/dev.conf`, `docker/minio/**`, `docker/.env.example`, `apps/api/Dockerfile`, `apps/web/Dockerfile`, `apps/admin/Dockerfile`, `infrastructure/nginx/**`, `infrastructure/monitoring/**` |
| knowledge (paralel, disjoint) | K1-K3 | K1: `docs/09-database-schema.md` + `docs/01-architecture.md` (trio §19/§3.3/gate H satu penulis). K2: `docs/06-admin-dashboard-requirements.md` + `docs/12-api-contract.md`. K3: `docs/10-migration-plan.md` + `docs/03-sprint-plan.md` + `docs/runbooks/**` |
| controller (bukan sub-agen) | - | `docs/00-overview.md`, `docs/11-blueprint-reconciliation.md`, `docs/adr/**`, `docs/specs/**`, `scripts/**`, `.github/workflows/**`, `.qoder/**`, `.codex/**`, `.agents/**`, berkas root, auditor `C:/temp/**` |

Setiap peran membaca seluruh repo sebagai bukti, tetapi **dilarang menulis** jalur
peran lain sebagaimana tercantum eksplisit di berkas `.codex/agents/<peran>.toml`.
`docker/.env` dan `docker/nginx/certs/**` hanya lokal, tidak pernah di-commit.

## 2. Keputusan teknis gelombang ini (D-DR-…)

| ID | Keputusan | Alasan / rujukan |
|---|---|---|
| D-DR-1 | Sub-band migrasi gelombang ini = `0040-0069` (tetap di band Sprint-0 `0001-0199`), disjoint dari `0001-0006` (Platform/framework) dan `0007-0023` (Sprint-0 Task 11). Plan ini **memiliki** tabel auth/RBAC: `roles`, `permissions`, `users`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`, `role_scopes`, `user_identities`, `staff_2fa_secrets`, `audit_logs`, `activity_log`. Controller wajib mencoret tabel-tabel itu dari Sprint-0 Task 11/12 yang sedang diamandemen; bila tumpang tindih tetap ada saat eksekusi, agen migration-db lapor BLOCKED, bukan menimpa | M-1 basename unik global; Doc 11 §4.4 H pembagian kerja; Doc 10 §5.2 alokasi nomor |
| D-DR-2 | Delta `users` ditulis **expand -> backfill -> contract** tiga berkas terpisah (0051 expand rilis N, command backfill, 0052 validate, 0053 contract rilis N+1) meskipun baseline pra-produksi Doc 10 §6 mengizinkan bentuk kanonik langsung di file create. Direktif pemilik mengikat pola tiga fase supaya aturan backfill dan invarian CHECK terbukti oleh test, bukan hanya oleh niat. File create `users` (0042) membawa kolom `kind` lama sebagai titik expand; hasil akhir di DB kosong identik dengan bentuk kanonik | Doc 10 §6/§6.1 (preseden 1610/1620 satu band beda rilis), M-9/M-13; Doc 11 §4.4 A |
| D-DR-3 | Constraint dependensi: `laravel/framework ^13.42` (resolve 13.43.0), `horizon ^5.51` (resolve 5.52.0, tidak ada v6), `sanctum ^4.5`, `socialite ^5.33`, `socialiteproviders/twitter ^4.1`, `spatie/laravel-permission ^8.5`, `spatie/laravel-activitylog ^5.1`, `spatie/laravel-data ^4.23`, `dedoc/scramble ^0.13`, `sentry/sentry-laravel ^4.30`, `maatwebsite/excel ^4.0`, `phpunit/phpunit ^13.4`, `phpstan/phpstan ^2.3`. `predis/predis` **dihapus** (phpredis sudah di Dockerfile). "RateLimiter" bukan paket - ia `Illuminate\Support\Facades\RateLimiter`. **Dua konflik register-vs-disk dilaporkan, tidak diputuskan diam-diam:** (a) `phpunit` 13.4.7 menuntut php >=8.4.1 sementara `config.platform.php` terkunci `8.4.0` - pin dinaikkan ke versi PHP container terukur (>=8.4.1) sebagai perubahan disengaja yang dilaporkan; (b) register menulis `qossmic/deptrac` 2.0.4 tetapi disk memakai `deptrac/deptrac ^4.7` dan skrip `composer deptrac` menunjuk `vendor/bin/deptrac` - identitas paket **dipertahankan seperti di disk** sampai controller memutus; jangan menukar diam-diam | Doc 11 §4.4 D; ADR-012 D-5; `apps/api/composer.json` terukur |
| D-DR-4 | 2FA TOTP memakai `spomky-labs/otphp:^11.5` (preseden Sprint-0 Task 12 Step 6), diverifikasi dari registry saat eksekusi. Paket ini **tidak ada** di tabel Doc 11 §4.4 D - dilaporkan ke controller untuk diratifikasi ke register, bukan diselundupkan | Sprint-0 Task 12; Doc 11 §4.4 D (register dependensi) |
| D-DR-5 | Dua cookie sesi dipisah lewat middleware `SelectSessionCookie` yang berjalan **sebelum** `StartSession` dan memilih nama cookie dari path (`api/v1/admin*` -> `elzara_admin_session`, selain itu `elzara_web_session`). Guard `web`/`admin` terpisah; `Gate::before` bypass hanya untuk guard admin dan selalu menulis `audit_logs` | ADR-007 (BFF/sesi) D-2a/D-4a; Doc 11 §4.4 A |
| D-DR-6 | Publish vendor **config saja**; migrasi vendor tidak pernah di-publish. Migrasi sanctum `personal_access_tokens` tidak dibuat; migrasi permission/activitylog ditulis ulang di namespace kita (0040-0050) | Doc 11 §4.4 B/D; ADR-007 D-3a; M-1..M-4 |
| D-DR-7 | `roles`/`permissions` lahir langsung berbentuk kanonik `name` + `guard_name` (default `admin`), unik `(name, guard_name)`; kolom morph standar spatie dipertahankan di `model_has_roles`/`model_has_permissions`. Tabel baru tidak punya baris lama, jadi tidak perlu expand/contract | Doc 11 §4.4 B; ADR-012 D-3 |
| D-DR-8 | File create `users` gelombang ini **tidak** memuat FK `avatar_media_id`/`banner_media_id` karena tabel `media` milik Sprint-0 Task 11 (Content) belum ada di disk. Kedua kolom itu menyusul sebagai expand M-13 setelah `media` lahir; dilaporkan sebagai scoping eksplisit, bukan penyimpangan diam-diam | Doc 10 M-5/M-13; Sprint-0 Task 11 |
| D-DR-9 | Test lokal berjalan di container one-shot `composer:2` + `pdo_pgsql` terhadap postgres compose (database `elzara_test`), karena image runtime api dibangun `--no-dev` sehingga `phpunit` tidak ada di dalamnya. CI `.github/workflows/api.yml` adalah gerbang otoritatif yang setara. Celah `--no-dev` vs `dev:test` dilaporkan ke controller (pemilik Dockerfile/workflow), bukan ditambal diam-diam | `apps/api/Dockerfile` baris 14; `phpunit.xml`; workflow api.yml |
| D-DR-10 | Tidak ada `git commit` di task mana pun gelombang ini kecuali pemilik proyek meminta eksplisit. Langkah penutup task = laporan keluaran nyata ke controller | aturan keras gelombang; berbeda dari Sprint-0 yang mengakhiri task dengan commit |

## 3. Urutan eksekusi dan gerbang

| # | Task | Peran | Bergantung pada |
|---|---|---|---|
| 1 | Tiga lockfile di container + bukti tanpa workspace | migration-db | - |
| 2 | Set dependensi terverifikasi + publish config | migration-db | 1 |
| 3 | Migrasi Identity 0040-0048 (roles, permissions, users basis, empat tabel spatie, user_identities, staff_2fa_secrets) | migration-db | 2 |
| 4 | Migrasi Audit 0049-0050 (audit_logs + request_id, activity_log) | migration-db | 3 |
| 5 | Delta users expand/backfill/validate/contract 0051-0053 + command backfill | migration-db | 4 |
| 6 | Dua guard + cookie sesi + CSRF + Sanctum stateful | feature | 5, tanda tangan threat model (ADR-007 D-6a) |
| 7 | Login admin: gerbang is_operator + 2FA wajib + 10 recovery code | feature | 6 |
| 8 | Storefront hybrid + linking email terverifikasi + policy kepemilikan | feature | 6 |
| 9 | RBAC: seeder bundel + grant langsung + role_scopes + Gate::before berjejak | feature | 7, 8 |
| 10 | Frontend kedua app + sinkronisasi kontrak | feature | 9 |
| 11 | Test unit + Schema (CHECK, mapper backfill, recovery code, penamaan permission) | testing | 10, K1-K3 selesai |
| 12 | Test Feature/integration (gerbang admin, 2FA, bypass audit, view-only, scope, linking, CSRF, pemisahan sesi) | testing | 11 |
| 13 | Deptrac B1-B6 + enam gerbang dokumen + drift kontrak | testing | 12 |
| 14 | Gerbang rebuild docker: tiga image konteks root, compose up, migrate+seed, health | docker-rebuild | 13 |
| K1 | Doc 09 §19 + Doc 01 §3.1/§3.3 + konstanta gate H (satu penulis) | knowledge | paralel dengan 3-10 |
| K2 | Doc 06 matriks permission + Doc 12 kontrak auth | knowledge | paralel dengan 6-10 |
| K3 | Doc 10 §8 + Doc 03 + runbook pencabutan hak/cache spatie | knowledge | paralel dengan 9-10 |

Gerbang akhir gelombang = Task 14 hijau **dan** keenam pemeriksa dokumen hijau
(Task 13). Tidak ada klaim selesai tanpa keluaran keduanya.

## 4. Yang sudah ada di disk (jangan re-scaffold)

Terukur 2026-11-18, bukan dari ingatan:

- `apps/api`: kerangka Laravel modular - `artisan`, `bootstrap/app.php` (routing
  `api/v1`, health `/up`, middleware session prepended ke grup api), `routes/{api,web,console}.php`,
  `app/ModuleLoader/ModuleServiceProvider.php` (glob M-2), `app/Platform/{Domain,Http,Infrastructure,Outbox,StateMachine}`,
  `app/Providers/AppServiceProvider.php`, **22 modul** di `app/Modules/` berlapis
  `Domain/Application/Infrastructure/Contracts/Tests` (mayoritas `.gitkeep`),
  `config/{app,cache,database,filesystems,horizon,logging,queue,session}.php`,
  `phpunit.xml` (suite Unit/Feature/Contract/Concurrency, env pgsql `elzara_test`),
  `tests/TestCase.php` + `tests/Unit/ModuleLoaderTest.php`, `Dockerfile` (php:8.4-fpm,
  phpredis via pecl, `composer install --no-dev`, konteks root), `.env.example`.
- **Satu-satunya migrasi di disk:** `app/Platform/Infrastructure/Database/Migrations/0001_Platform_create_reliability_tables.php`.
  `database/migrations/` hanya `.gitkeep`. Tidak ada migrasi Identity/Audit apa pun.
- `apps/web` dan `apps/admin`: Next.js App Router + TS, `app/health/route.ts` sudah ada,
  `apps/admin/app/login/page.tsx`, `apps/admin/lib/{api,nav-groups,session-cookie,session}.ts`,
  `apps/web/app/account/page.tsx`, `apps/web/lib/api.ts`, `middleware.ts`, `Dockerfile`
  node:22 standalone, `package.json` dengan `file:../../packages/*`.
- `packages/{api-contracts,shared-types,ui}`: nama `@elzara/api-client`,
  `@elzara/shared-types`, `@elzara/ui`; `openapi.yaml` (1208 baris, komentar
  securitySchemes wajib sudah ada), `generated/` ada.
- `docker/`: `compose.yaml` (10 service, konteks build `..`, backend internal),
  `profiles/{staging,production}.yaml`, `nginx/dev.conf`, `minio/init-buckets.sh`,
  `.env.example`, `README.md`.
- `scripts/`: `gen-lockfiles.sh`, `dev.sh`, `migrate.sh`, `codegen.sh`,
  `check-contract-drift.sh`, `verify-docs.sh` - semuanya sudah dibaca; perintah di
  plan ini menyalin bentuk nyatanya.
- `.github/workflows/{api,web,admin,contracts}.yml`; `infrastructure/{nginx,monitoring}`.
- `apps/api/composer.json` saat ini: `framework ^13.42`, `horizon ^5.51`,
  **`predis/predis ^3.7` (harus dihapus)**, dev `phpunit ^12.5` (harus naik),
  `deptrac/deptrac ^4.7`, `larastan ^3.15`, `pint ^1.27`; `config.platform.php = 8.4.0`.

**Belum ada di disk (jangan diklaim ada):** `composer.lock`, kedua `package-lock.json`,
`vendor/`, `node_modules/`, `config/auth.php`, `config/elzara.php`, `deptrac.yaml`,
`database/seeders/`, `database/factories/`, migrasi 0007+ , test auth/RBAC apa pun.
Belum ada dependensi terpasang, belum ada test jalan, belum ada yang di-commit.

## 5. Yang masih terbuka (jujur, bukan digantung diam-diam)

| Butir | Status | Dampak ke plan |
|---|---|---|
| Tanda tangan threat model atas cookie/CSRF/2FA/pemisahan guard | **belum ada** (ADR-007 (BFF/sesi) D-6a, kewajiban B-14) | Task 6 tidak boleh dimulai sebelum pemilik proyek + klien menandatangani `docs/threat-model.md`; bila belum, agen feature lapor BLOCKED |
| Q-2: apakah klien punya IdP/SSO sendiri | terbuka (menyempit, bukan lagi BFF-vs-token) | bila ya: provider OIDC ditambahkan pada jalur login yang sama (Task 8), bukan mengganti mekanisme sesi |
| Q-1: jadwal J-a/J-b/J-c | terbuka | tidak mengubah teknis gelombang ini; mengubah urutan sprint berikutnya |
| Q-3 finance, Q-4 kapasitas RPS, Q-5 multi-gudang, Q-6 domain/edge, Q-7 gateway | terbuka | di luar cakupan auth/RBAC; deploy staging/production tetap terhalang (workflow api.yml job deploy) |
| Q-8: pemegang permission CMS + moderasi UGC | terbuka | bundel role di Task 9 bersifat provisional; grant per orang menunggu keputusan klien; jangan mengarang pemilik |
| Konflik register-vs-disk: `qossmic/deptrac` vs `deptrac/deptrac`, pin platform 8.4.0 vs phpunit >=8.4.1, paket 2FA tidak di tabel D | **dilaporkan, menunggu controller** | D-DR-3/D-DR-4; jangan menyimpang diam-diam |
| `scripts/gen-lockfiles.sh`: image `php:8.4-cli` tidak membawa binary composer dan `composer update --lock` tidak membuat lock dari nol | perlu dibuktikan saat eksekusi | Task 1 Step 2: bila gagal, BLOCKED ke controller (pemilik skrip); jalur sementara hanya dengan persetujuan tertulis |
| `docker/.env.example` menyebut `SMTP_HOST=mailpit` tetapi `compose.yaml` tidak punya service mailpit | celah scaffold, dilaporkan | tidak mengubah auth; dev email capture menunggu keputusan controller (docker-rebuild) |
| Sprint-0 Task 11/12 sedang diamandemen agen lain dan masih menyebut tabel auth | tumpang tindih potensial | D-DR-1; migration-db memeriksa disk dulu dan BLOCKED bila bentuk berbeda |

## 6. Yang TIDAK boleh muncul (dijaga gerbang, bukan diingat-ingat)

| Godaan | Kenapa ditolak | Rujukan |
|---|---|---|
| `composer require`/`npm install` di host | PHP host 8.3.30, Node host 26; activitylog/phpunit menuntut 8.4 | Doc 11 §4.4 D, Doc 01 §11.2 |
| `laravel/horizon:^6` | tidak ada v6; constraint tidak pernah resolve | Doc 11 §4.4 D |
| Paket "laravel rate limiter" | ia facade bawaan framework, bukan paket | Doc 11 §4.4 D |
| `predis/predis` berdampingan dengan phpredis | satu driver untuk satu fakta | ADR-012 D-5 alternatif H |
| Bearer token / PAT di browser | sesi cookie same-site; `personal_access_tokens` tidak dibuat | ADR-007 D-3a |
| Jalur sosial ke admin | admin hanya email+password + 2FA | Doc 11 §4.4 A |
| Linking akun bermodal email mentah provider | pengambilalihan akun; hanya email terverifikasi | Doc 11 §4.4 C |
| Bypass `is_admin` tanpa baris `audit_logs` | bypass tak berjejak = lubang audit | ADR-012 D-2 |
| `activity_log` dianggap memenuhi kewajiban audit | tanpa `reason` wajib, tidak append-only | Doc 11 §4.4 F |
| spatie di storefront | dua sumber otorisasi untuk satu orang | Doc 11 §4.4 B |
| Pivot lama `role_user`/`permission_role` (tidak berlaku, diganti bentuk spatie) | paket membaca kolom morph standar | Doc 11 §4.4 B |
| Kolom `kind`/`users.kind` dan indeks `users_kind_status` bertahan setelah contract (lama, diganti dua boolean + `users_operator_status`) | dua gerbang admin = kelas bug yang membuat keputusan ini diambil | ADR-012 D-1 |
| Tabel `staff_users` (tetap tidak dibuat) | dua baris untuk satu orang | Doc 11 §4.3 |
| Angka jumlah tabel tulisan tangan | pemiliknya Doc 09 §19 hasil `verify_schema.py` | Doc 10 §12 |
| Menyunting auditor `C:/temp` / melonggarkan matcher demi hijau | gerbang kehilangan makna | Doc 11 §8.8 |
| `make`, `pnpm`, `jq` di perintah host | tidak terpasang (terukur) | Sprint-0 Task 1 |

---

### Task 1 - Tiga lockfile di container, tanpa workspace (peran: migration-db)

**Files:**
- Create: `apps/api/composer.lock`, `apps/admin/package-lock.json`, `apps/web/package-lock.json`
- Modify: tidak ada berkas sumber; skrip hanya menghasilkan lock.
- Test: tidak ada PHPUnit; gerbangnya keberadaan lock + pemeriksaan layout.

- [ ] **Step 1: Baca skripnya dulu, lalu jalankan**

Run: `bash scripts/gen-lockfiles.sh`
Expected: tiga baris `lockfile dihasilkan:` diikuti `apps/api/composer.lock`,
`apps/admin/package-lock.json`, `apps/web/package-lock.json`. Skrip memakai
`node:22 npm install --package-lock-only` untuk kedua app dan `php:8.4-cli composer
update --lock` untuk api.

- [ ] **Step 2: Bila Step 1 gagal pada bagian composer, berhenti dengan benar**

Image resmi `php:8.4-cli` tidak membawa binary `composer`, dan `composer update --lock`
tidak menciptakan lock dari nol. Bila keluarannya `composer: not found` atau error
lock tidak ada: tulis `BLOCKED` + keluaran persis ke controller (pemilik `scripts/`).
Jangan menyunting skrip. Jalur sementara **hanya bila controller menyetujui tertulis**:

```bash
docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 \
  composer update --no-scripts --no-interaction
```

dan penyimpangan sementara itu disebut di laporan. Lock yang dihasilkan tetap harus
diverifikasi Step 3.

- [ ] **Step 3: Buktikan ketiga lock ada dan tidak ada workspace**

Run: `ls -1 apps/api/composer.lock apps/admin/package-lock.json apps/web/package-lock.json | wc -l` - Expected: `3`.
Run: `ls package.json 2>/dev/null | wc -l` - Expected: `0` (tidak ada root package.json).
Run: `grep -rl '"workspaces"' apps/*/package.json packages/*/package.json 2>/dev/null | wc -l` - Expected: `0`.
Run (nama dependency `file:` cocok dengan nama paket target):

```bash
grep -h '"@elzara/' apps/web/package.json apps/admin/package.json
grep -h '"name"' packages/api-contracts/package.json packages/shared-types/package.json packages/ui/package.json
```

Expected: `@elzara/api-client`, `@elzara/shared-types`, `@elzara/ui` muncul di kedua
sisi dengan nama identik.

- [ ] **Step 4: Gerbang layout**

Run: `python C:/temp/check_layout.py`
Expected: verdict gate I menyebut dua lockfile dan nol workspace (bagian dari
`LULUS — pohon Doc 01 §3.1 = filesystem, dan tujuh aturan layout ditegakkan di berkas`).
Bila merah karena butir lain yang belum dikerjakan task lain, catat butirnya; jangan
melonggarkan matcher.

- [ ] **Step 5: Lapor** - tempel keluaran Step 1/3/4 ke controller. Tanpa commit (D-DR-10).

### Task 2 - Set dependensi terverifikasi + publish config (peran: migration-db)

**Files:**
- Modify: `apps/api/composer.json`, `apps/api/composer.lock`
- Create: `apps/api/config/{sanctum,permission,activitylog,scramble,sentry}.php`
- Test: tidak ada PHPUnit; gerbangnya `composer show` + daftar config.

- [ ] **Step 1: Buktikan PHP container 8.4 dan naikkan pin platform secara disengaja**

Run: `docker run --rm composer:2 php -v | head -1` - Expected: `PHP 8.4.x`.
`phpunit/phpunit` 13.4.7 menuntut php >=8.4.1 sementara pin disk `8.4.0` (D-DR-3a).
Naikkan pin ke versi container terukur (mis. `8.4.1` bila itu yang terukur) dan
**laporkan perubahan pin ini sebagai keputusan disengaja**, bukan efek samping:

```bash
docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 \
  config platform.php 8.4.1
```

Expected: `grep -A2 '"platform"' apps/api/composer.json` menunjukkan angka baru.
Bila versi container < 8.4.1, BLOCKED (phpunit 13 tidak bisa resolve).

- [ ] **Step 2: Hapus predis, pasang set produksi**

```bash
docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 \
  composer remove predis/predis --no-update --no-interaction
docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 \
  composer require "laravel/framework:^13.42" "laravel/sanctum:^4.5" "laravel/horizon:^5.51" \
  "laravel/socialite:^5.33" "socialiteproviders/twitter:^4.1" "spatie/laravel-permission:^8.5" \
  "spatie/laravel-activitylog:^5.1" "spatie/laravel-data:^4.23" "dedoc/scramble:^0.13" \
  "sentry/sentry-laravel:^4.30" "maatwebsite/excel:^4.0" "spomky-labs/otphp:^11.5" --no-interaction
```

Expected: `composer require` sukses tanpa error platform; `spomky-labs/otphp`
ditambahkan sesuai D-DR-4 dan dilaporkan untuk ratifikasi register. Tidak ada paket
"rate limiter" (ia facade bawaan). Horizon tetap `^5.51`.

- [ ] **Step 3: Pasang set dev**

```bash
docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 \
  composer require --dev "phpunit/phpunit:^13.4" "phpstan/phpstan:^2.3" --no-interaction
```

Expected: resolve sukses. `deptrac/deptrac ^4.7` **dipertahankan seperti di disk**
(D-DR-3b); konflik nama dengan register dilaporkan, jangan ditukar diam-diam.

- [ ] **Step 4: Buktikan versi terpasang dan phpredis**

Run:
```bash
docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 \
  composer show laravel/framework laravel/sanctum laravel/horizon spatie/laravel-permission spatie/laravel-activitylog phpunit/phpunit 2>/dev/null | grep -E '^name|^versions'
```
Expected: framework `v13.43.x`, sanctum `v4.5.x`, horizon `v5.52.x`, permission
`8.5.x`, activitylog `5.1.x`, phpunit `13.4.x`.
Run: `grep -c predis apps/api/composer.json` - Expected: `0`.
Run: `grep -n 'pecl install redis' apps/api/Dockerfile` - Expected: satu baris (phpredis sudah di image).
Set `REDIS_CLIENT=phpredis` di `apps/api/.env.example` bila belum ada (berkas contoh, bukan rahasia).

- [ ] **Step 5: Publish config vendor - config saja, migrasi tidak**

Jalankan di container one-shot terhadap vendor yang sudah terpasang (tanpa DB):

```bash
docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 sh -c '
  php artisan vendor:publish --tag=sanctum-config --no-interaction &&
  php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider" --tag=permission-config --no-interaction &&
  php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag=activitylog-config --no-interaction &&
  php artisan vendor:publish --tag=scramble-config --no-interaction &&
  php artisan vendor:publish --provider="Sentry\Laravel\ServiceProvider" --no-interaction'
```

Expected: `config/{sanctum,permission,activitylog,scramble,sentry}.php` lahir. Bila
satu tag tidak dikenal oleh versi paket, pakai bentuk `--provider` penuh dan
**verifikasi hasilnya**; jangan menebak tag lain. Sentry: set
`send_default_pii=false` di `config/sentry.php` (Doc 11 §4.4 D).

- [ ] **Step 6: Buktikan tidak ada migrasi vendor yang ikut ter-publish**

Run: `find apps/api -path '*database/migrations*' -name '*.php' | wc -l` - Expected: `0`.
Run: `grep -rl 'personal_access_tokens' apps/api/config apps/api/database 2>/dev/null | wc -l` - Expected: `0` (tabel itu tidak dibuat).
Bila ada migrasi vendor muncul, hapus berkas itu sebelum lanjut - migrasi ditulis
ulang di namespace kita (Task 3-5). Config sanctum: `stateful` domain dibaca dari
`APP_URL` (same-site, tanpa CORS lintas origin).

- [ ] **Step 7: Regenerasi lock dan lapor**

Run: `bash scripts/gen-lockfiles.sh` (atau jalur sementara yang disetujui controller
untuk bagian composer). Expected: ketiga lock ada dan `composer.lock` memuat set baru.
Tempel keluaran `composer show` + `ls config` ke controller. Tanpa commit.

### Task 3 - Migrasi Identity 0040-0048 (peran: migration-db)

**Files:**
- Create (`apps/api/app/Modules/Identity/Infrastructure/Database/Migrations/`):
  `0040_Identity_create_roles_table.php`, `0041_Identity_create_permissions_table.php`,
  `0042_Identity_create_users_table.php`, `0043_Identity_create_model_has_roles_table.php`,
  `0044_Identity_create_model_has_permissions_table.php`,
  `0045_Identity_create_role_has_permissions_table.php`,
  `0046_Identity_create_role_scopes_table.php`,
  `0047_Identity_create_user_identities_table.php`,
  `0048_Identity_create_staff_2fa_secrets_table.php`
- Test: tidak ada PHPUnit di task ini (milik testing); gerbangnya `migrate --force` nyata.

Prasyarat diperiksa dulu, bukan diasumsikan:

- [ ] **Step 1: Periksa disk dan siapkan database dev**

Run: `ls apps/api/app/Modules/Identity/Infrastructure/Database/Migrations/`
Expected hari ini: hanya `.gitkeep`. Bila sudah ada file `create_users`/`create_roles`
dari Sprint-0 dengan bentuk berbeda, **BLOCKED** ke controller (D-DR-1) - jangan menimpa.

```bash
cp docker/.env.example docker/.env   # isi POSTGRES_PASSWORD, MINIO_*, APP_KEY dev lokal; ELASTICSEARCH_PASSWORD hanya jika menjalankan profil search
docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 php artisan key:generate --show
# tulis APP_KEY yang tercetak ke docker/.env lokal (bukan ke berkas yang di-commit)
docker compose -f docker/compose.yaml up -d postgres redis
```

Expected: `docker compose -f docker/compose.yaml ps` menunjukkan postgres/redis `healthy`.

- [ ] **Step 2: Header wajib di sembilan file (Doc 10 §2, C8)**

Tempel sebelum `return new class` (isi Modul/Tier per file):

```php
<?php // NNNN_Identity_<slug>.php
// Modul   : Identity (Doc 09 §19 - B1) · Sprint : 0 · rilis 2026-11
// Tier    : <level Appendix A> · Expand : <ya/tidak>, contract <nomor file atau tidak diperlukan>
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
```

Konvensi: setiap `Schema::create` diawali `$t->id()` dan diakhiri
`$t->timestampTz('created_at')->useCurrent(); $t->timestampTz('updated_at')->useCurrent();`
kecuali tabel append-only/pivot. `down()` = kebalikan persis (M-6). Constraint bernama
eksplisit. Uang/status tidak disentuh migrasi (M-7).

- [ ] **Step 3: L0 - roles dan permissions berbentuk kanonik (D-DR-7)**

```php
// 0040_Identity_create_roles_table.php · Tier 0
Schema::create('roles', function (Blueprint $t): void {
    $t->id();
    $t->string('name', 80);                       // kode role Blueprint, mis. catalog-manager; pengganti kolom code lama
    $t->string('guard_name', 40)->default('admin');
    $t->text('description')->nullable();
    $t->boolean('is_system')->default(false);
    $t->timestampTz('created_at')->useCurrent();
    $t->timestampTz('updated_at')->useCurrent();
    $t->unique(['name', 'guard_name'], 'uq_roles_name_guard');
});
// 0041_Identity_create_permissions_table.php · Tier 0
Schema::create('permissions', function (Blueprint $t): void {
    $t->id();
    $t->string('name', 64);                       // <modul>.<aksi>; pengganti kolom code lama
    $t->string('guard_name', 40)->default('admin');
    $t->string('group_name', 40);
    $t->text('description')->nullable();
    $t->timestampTz('created_at')->useCurrent();
    $t->timestampTz('updated_at')->useCurrent();
    $t->unique(['name', 'guard_name'], 'uq_permissions_name_guard');
    $t->index(['group_name'], 'idx_permissions_group');
});
```

- [ ] **Step 4: L1 - users bentuk basis (titik expand D-DR-2; FK media ditunda D-DR-8)**

```php
// 0042_Identity_create_users_table.php · Tier 1
DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
Schema::create('users', function (Blueprint $t): void {
    $t->id();
    $t->string('public_id', 26)->unique('uq_users_public_id');
    $t->string('name', 120);
    $t->addColumn('citext', 'email')->unique('uq_users_email');
    $t->timestampTz('email_verified_at')->nullable();
    $t->string('phone', 24)->nullable();
    $t->timestampTz('phone_verified_at')->nullable();
    $t->string('password_hash', 255)->nullable();
    $t->string('kind', 16)->default('customer'); // kolom lama - diganti is_operator/is_admin di 0051-0053, tidak berlaku setelah contract
    $t->string('status', 16)->default('active');
    $t->string('locale', 5)->default('id');
    $t->string('timezone', 64)->default('Asia/Jakarta');
    $t->boolean('marketing_optin')->default(false);
    $t->timestampTz('last_login_at')->nullable();
    $t->softDeletesTz();
    $t->timestampTz('created_at')->useCurrent();
    $t->timestampTz('updated_at')->useCurrent();
    $t->index(['kind', 'status'], 'idx_users_kind_status'); // indeks lama - diganti users_operator_status di 0051
});
DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_kind CHECK (kind IN ('customer','staff'))"); // CHECK lama, tidak berlaku setelah contract 0053
DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_status CHECK (status IN ('active','suspended','deletion_requested','deleted'))");
```

`down()`: `dropIfExists('users')` (ekstensi citext dibiarkan, tidak destruktif).

- [ ] **Step 5: L2 - empat tabel bentuk spatie + role_scopes (kolom morph dipertahankan)**

```php
// 0043_Identity_create_model_has_roles_table.php · Tier 2 (FK roles L0)
Schema::create('model_has_roles', function (Blueprint $t): void {
    $t->unsignedBigInteger('role_id');
    $t->string('model_type', 120);
    $t->unsignedBigInteger('model_id');
    $t->primary(['role_id', 'model_id', 'model_type'], 'pk_model_has_roles');
    $t->index(['model_type', 'model_id'], 'idx_model_has_roles_model');
    $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
});
// 0044_Identity_create_model_has_permissions_table.php · Tier 2 - bentuk sama, permission_id -> permissions
// 0045_Identity_create_role_has_permissions_table.php · Tier 2
Schema::create('role_has_permissions', function (Blueprint $t): void {
    $t->unsignedBigInteger('permission_id');
    $t->unsignedBigInteger('role_id');
    $t->primary(['permission_id', 'role_id'], 'pk_role_has_permissions');
    $t->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
    $t->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
});
// 0046_Identity_create_role_scopes_table.php · Tier 2 (FK users L1, roles L0)
Schema::create('role_scopes', function (Blueprint $t): void {
    $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $t->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
    $t->string('scope_type', 24)->nullable();      // NULL = global
    $t->unsignedBigInteger('scope_id')->nullable(); // kolom polos, bukan FK (lintas modul)
});
DB::statement("CREATE UNIQUE INDEX uq_role_scopes_identity ON role_scopes (user_id, role_id, COALESCE(scope_type, ''), COALESCE(scope_id, 0))");
DB::statement("ALTER TABLE role_scopes ADD CONSTRAINT ck_role_scopes_type CHECK (scope_type IS NULL OR scope_type IN ('batch','category','warehouse'))");
```

- [ ] **Step 6: L2 - user_identities dan staff_2fa_secrets**

```php
// 0047_Identity_create_user_identities_table.php · Tier 2 (FK users L1)
Schema::create('user_identities', function (Blueprint $t): void {
    $t->id();
    $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
    $t->string('provider', 40);
    $t->string('provider_user_id', 190);
    $t->timestampTz('linked_at')->useCurrent();
    $t->timestampTz('created_at')->useCurrent();
    $t->timestampTz('updated_at')->useCurrent();
    $t->unique(['provider', 'provider_user_id'], 'uq_user_identities_provider');
    $t->index('user_id', 'idx_user_identities_user');
});
DB::statement("ALTER TABLE user_identities ADD CONSTRAINT ck_user_identities_provider CHECK (provider IN ('google','twitter'))"); // twitter = driver X OAuth2
// 0048_Identity_create_staff_2fa_secrets_table.php · Tier 2 (FK users L1)
Schema::create('staff_2fa_secrets', function (Blueprint $t): void {
    $t->id();
    $t->foreignId('user_id')->unique('uq_staff_2fa_secrets_user')->constrained('users')->cascadeOnDelete();
    $t->binary('secret_encrypted');               // bytea, tidak pernah plaintext
    $t->timestampTz('confirmed_at')->nullable();
    $t->timestampTz('created_at')->useCurrent();
    $t->timestampTz('updated_at')->useCurrent();
});
DB::statement("ALTER TABLE staff_2fa_secrets ADD COLUMN recovery_hashes text[] NOT NULL DEFAULT '{}'::text[]"); // sepuluh recovery code ter-hash (D-S0-15)
```

- [ ] **Step 7: Jalankan migrasi nyata dan buktikan**

```bash
set -a; . ./docker/.env; set +a
docker run --rm --network elzara_backend -v "$PWD:/w" -w /w/apps/api \
  -e DB_CONNECTION=pgsql -e DB_HOST=postgres -e DB_PORT=5432 \
  -e DB_DATABASE="$POSTGRES_DB" -e DB_USERNAME="$POSTGRES_USER" -e DB_PASSWORD="$POSTGRES_PASSWORD" \
  -e REDIS_HOST=redis -e APP_KEY="$APP_KEY" -e COMPOSER_ALLOW_SUPERUSER=1 \
  composer:2 sh -c 'apk add --no-cache postgresql-dev && docker-php-ext-install pdo_pgsql && php artisan migrate --force'
```

Expected: baris `DONE` untuk 0040-0048 (dan 0001 yang sudah ada).
Run (C2, keluaran harus kosong): `find apps/api -path '*/Migrations/*.php' -printf '%f\n' | sort | uniq -d`
Run (bukti kolom kanonik):
```bash
docker compose -f docker/compose.yaml exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
  -c "\d roles" -c "\d user_identities" -c "\d staff_2fa_secrets"
```
Expected: `roles.name`+`guard_name` dengan unique `uq_roles_name_guard`;
`uq_user_identities_provider`; `recovery_hashes text[]`.

- [ ] **Step 8: Lapor** - tempel keluaran migrate + `\d` + C2. Tanpa commit.

### Task 4 - Migrasi Audit 0049-0050 (peran: migration-db)

**Files:**
- Create (`apps/api/app/Modules/Audit/Infrastructure/Database/Migrations/`):
  `0049_Audit_create_audit_logs_table.php`, `0050_Audit_create_activity_log_table.php`
- Test: gerbangnya `migrate --force` + `\d`.

- [ ] **Step 1: audit_logs append-only dengan request_id (S-6, Doc 12 §3.2, D-S0-15)**

```php
// 0049_Audit_create_audit_logs_table.php · Modul Audit · Tier 2 (FK users L1) · Expand ya
Schema::create('audit_logs', function (Blueprint $t): void {
    $t->id();
    $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
    $t->string('action', 64);
    $t->string('target_type', 64)->nullable();
    $t->unsignedBigInteger('target_id')->nullable();
    $t->jsonb('before')->nullable();
    $t->jsonb('after')->nullable();
    $t->string('reason', 320);                    // NOT NULL; aksi berisiko tanpa reason = tidak terjadi
    $t->string('request_id', 26);                 // ULID (Doc 12 §3.2)
    $t->addColumn('inet', 'ip')->nullable();
    $t->string('user_agent', 255)->nullable();
    $t->timestampTz('occurred_at')->useCurrent(); // append-only: TANPA updated_at
    $t->index(['target_type', 'target_id', 'occurred_at'], 'idx_audit_target');
    $t->index(['actor_id', 'occurred_at'], 'idx_audit_actor_time');
});
DB::statement('ALTER TABLE audit_logs ADD CONSTRAINT ck_audit_logs_reason CHECK (length(trim(reason)) > 0)');
```

- [ ] **Step 2: activity_log kolom standar spatie (bukan pengganti audit_logs, Doc 11 §4.4 F)**

```php
// 0050_Audit_create_activity_log_table.php · Modul Audit · Tier 0 (morph, tanpa FK)
Schema::create('activity_log', function (Blueprint $t): void {
    $t->id();
    $t->string('log_name', 120)->nullable();
    $t->text('description');
    $t->string('subject_type', 120)->nullable();
    $t->unsignedBigInteger('subject_id')->nullable();
    $t->string('event', 80)->nullable();
    $t->string('causer_type', 120)->nullable();
    $t->unsignedBigInteger('causer_id')->nullable();
    $t->jsonb('properties')->nullable();
    $t->uuid('batch_uuid')->nullable();
    $t->timestampTz('created_at')->useCurrent();
    $t->timestampTz('updated_at')->useCurrent();
    $t->index(['subject_type', 'subject_id'], 'idx_activity_log_subject');
    $t->index(['causer_type', 'causer_id'], 'idx_activity_log_causer');
    $t->index('log_name', 'idx_activity_log_log_name');
});
```

- [ ] **Step 3: Jalankan ulang perintah migrate Task 3 Step 7**

Expected: `DONE` untuk 0049-0050. Run:
`docker compose -f docker/compose.yaml exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "\d audit_logs"`
Expected: kolom `request_id character varying(26)` NOT NULL, tanpa `updated_at`,
constraint `ck_audit_logs_reason`. Lapor keluaran. Tanpa commit.

### Task 5 - Delta users: expand, backfill, validate, contract (peran: migration-db)

**Files:**
- Create (`apps/api/app/Modules/Identity/Infrastructure/Database/Migrations/`):
  `0051_Identity_expand_users_operator_flags.php` (rilis N),
  `0052_Identity_validate_users_operator_check.php`,
  `0053_Identity_contract_users_drop_kind.php` (rilis N+1, M-9; kontrak kolom kind lama)
- Create: `apps/api/app/Console/Commands/BackfillOperatorFlags.php`
- Test: gerbangnya migrate + query validasi + backfill idempoten.

- [ ] **Step 1: Expand 0051 - dua boolean + indeks baru + CHECK NOT VALID**

```php
// 0051 · Modul Identity · Tier 1 · Expand ya (rilis N); contract 0053 di rilis N+1
Schema::table('users', function (Blueprint $t): void {
    $t->boolean('is_operator')->default(false);   // default aman, M-13
    $t->boolean('is_admin')->default(false);
    $t->index(['is_operator', 'status'], 'users_operator_status');
});
DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_admin_requires_operator_v2 CHECK (is_admin = false OR is_operator = true) NOT VALID");
```

`down()`: drop constraint `_v2`, drop index `users_operator_status`, dropColumn
`is_admin`, `is_operator`.

- [ ] **Step 2: Command backfill idempoten (Doc 10 §7; aturan backfill mengikat)**

Create `apps/api/app/Console/Commands/BackfillOperatorFlags.php` (ditemukan otomatis
oleh framework dari `app/Console/Commands`):

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class BackfillOperatorFlags extends Command
{
    protected $signature = 'identity:backfill-operator-flags';
    protected $description = 'Backfill struktural non-uang: is_operator dari kind lama, is_admin=false semua baris (Doc 10 §7).';

    public function handle(): int
    {
        if (! Schema::hasColumn('users', 'kind')) { // kind lama tidak berlaku setelah contract 0053
            $this->info('kolom kind lama sudah tidak ada - backfill no-op (idempoten).');

            return self::SUCCESS;
        }

        $total = 0;
        do {
            $affected = DB::update(
                "UPDATE users SET is_operator = (kind = 'staff'), is_admin = false -- kind lama diganti is_operator
                 WHERE id IN (
                     SELECT id FROM users
                     WHERE is_operator IS DISTINCT FROM (kind = 'staff') OR is_admin IS DISTINCT FROM false -- kind lama
                     LIMIT 1000
                 )"
            );
            $total += $affected;
            if ($affected > 0) {
                usleep(200_000); // batch 1000 / jeda 200 ms (Doc 10 §7)
            }
        } while ($affected > 0);

        DB::table('audit_logs')->insert([
            'action' => 'backfill.operator_flags',
            'reason' => 'Backfill is_operator dari kind lama; is_admin=false semua baris; bypass diberikan manual nanti dan diaudit.',
            'request_id' => (string) Str::ulid(),
            'occurred_at' => now(),
        ]);
        $this->info("backfill selesai: {$total} baris diperbarui; is_admin=false untuk setiap baris.");

        return self::SUCCESS;
    }
}
```

Aturan mengikat: `is_operator = true` hanya untuk baris yang nilai lamanya `staff`;
`is_admin = false` untuk **setiap** baris. Bypass `is_admin = true` diberikan manual
belakangan oleh pemilik, dan pemberian itu sendiri menulis `audit_logs` (Task 9).

- [ ] **Step 3: Jalankan expand + backfill, buktikan idempoten**

Run perintah migrate Task 3 Step 7 (Expected: `DONE` 0051), lalu jalankan command
backfill dua kali lewat container one-shot yang sama dengan `php artisan
identity:backfill-operator-flags` menggantikan `php artisan migrate --force`.
Expected run pertama: `backfill selesai: 0 baris diperbarui` di DB kosong (atau N
baris bila ada data demo); run kedua: angka 0 dan tidak ada error (idempoten).
Run query validasi (Doc 10 §6 langkah 3, harus 0):

```bash
docker compose -f docker/compose.yaml exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
  -c 'SELECT count(*) FROM users WHERE NOT (is_admin = false OR is_operator = true);'
```

Expected: `0`.

- [ ] **Step 4: Validate 0052**

```php
// 0052 · hanya membaca, boleh satu file (Doc 10 §6.1 langkah 2)
DB::statement('ALTER TABLE users VALIDATE CONSTRAINT ck_users_admin_requires_operator_v2');
```

Run migrate lagi. Expected: `DONE` 0052; query Step 3 tetap `0`.

- [ ] **Step 5: Contract 0053 (rilis N+1 di header, M-9)**

```php
// 0053 · Modul Identity · Tier 1 · Contract (rilis N+1, bukan rilis yang sama dengan 0051)
DB::statement('ALTER TABLE users DROP CONSTRAINT ck_users_admin_requires_operator_v2');
DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_admin_requires_operator CHECK (is_admin = false OR is_operator = true)");
DB::statement('ALTER TABLE users DROP CONSTRAINT ck_users_kind'); // CHECK kind lama tidak berlaku setelah file ini
Schema::table('users', function (Blueprint $t): void {
    $t->dropIndex('idx_users_kind_status'); // indeks lama diganti users_operator_status (0051)
    $t->dropColumn('kind');                 // kolom lama tidak berlaku setelah contract ini
});
```

`down()` (reversibel karena peta nilai bijektif):

```php
Schema::table('users', function (Blueprint $t): void {
    $t->string('kind', 16)->default('customer'); // pemulihan kolom lama untuk rollback
});
DB::update("UPDATE users SET kind = CASE WHEN is_operator THEN 'staff' ELSE 'customer' END"); // pemulihan nilai kind lama dari is_operator
DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_kind CHECK (kind IN ('customer','staff'))"); // pemulihan CHECK kind lama untuk rollback
Schema::table('users', fn (Blueprint $t) => $t->index(['kind', 'status'], 'idx_users_kind_status')); // pemulihan indeks kind lama untuk rollback
DB::statement('ALTER TABLE users DROP CONSTRAINT ck_users_admin_requires_operator');
DB::statement("ALTER TABLE users ADD CONSTRAINT ck_users_admin_requires_operator_v2 CHECK (is_admin = false OR is_operator = true) NOT VALID");
```

- [ ] **Step 6: Buktikan bentuk akhir dan rollback**

Run migrate lagi (Expected: `DONE` 0053), lalu:
```bash
docker compose -f docker/compose.yaml exec -T postgres psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "\d users"
```
Expected: `is_operator`/`is_admin` boolean NOT NULL default false, indeks
`users_operator_status`, constraint `ck_users_admin_requires_operator`; **tidak ada**
kolom `kind` lama dan tidak ada indeks `users_kind_status` lama.
Run (C5, rollback satu langkah lalu maju lagi): container one-shot yang sama dengan
`php artisan migrate:rollback --step=1` lalu `php artisan migrate --force`.
Expected: keduanya sukses tanpa error. Lapor keluaran. Tanpa commit.

### Task 6 - Dua guard + cookie sesi + CSRF + Sanctum (peran: feature)

**Files:**
- Create: `apps/api/config/auth.php`, `apps/api/config/elzara.php`,
  `apps/api/app/Modules/Identity/Infrastructure/Http/Middleware/SelectSessionCookie.php`,
  `apps/api/app/Modules/Identity/Infrastructure/Models/User.php`,
  `apps/api/app/Modules/Identity/IdentityServiceProvider.php`
- Modify: `apps/api/config/session.php`, `apps/api/bootstrap/app.php`, `apps/api/routes/api.php`
- Test: ditulis testing nanti (Task 12); gerbang task ini `route:list` + typecheck.

Prasyarat mengikat: tanda tangan threat model atas cookie/CSRF/2FA (ADR-007 (BFF/sesi)
D-6a). Bila belum ada, **BLOCKED**, jangan menulis kode auth.

- [ ] **Step 1: config/auth.php dua guard, satu provider**

```php
<?php

declare(strict_types=1);

return [
    'defaults' => ['guard' => 'web', 'passwords' => 'users'],
    'guards' => [
        'web' => ['driver' => 'session', 'provider' => 'users'],
        'admin' => ['driver' => 'session', 'provider' => 'users'],
    ],
    'providers' => [
        'users' => ['driver' => 'eloquent', 'model' => App\Modules\Identity\Infrastructure\Models\User::class],
    ],
    'passwords' => [
        'users' => ['provider' => 'users', 'table' => 'password_reset_tokens', 'expire' => 60, 'throttle' => 60],
    ],
    'password_timeout' => 10800,
];
```

Catatan: broker password belum dipakai gelombang ini (tabel reset milik Sprint-0);
konfigurasi standar disediakan tanpa membangun alur reset.

- [ ] **Step 2: Model User + cookie default storefront**

`User.php`: Eloquent, `protected $table = 'users'`, cast `is_operator`/`is_admin`
boolean, `email_verified_at` datetime, relasi `identities()` hasMany UserIdentity,
`twoFactor()` hasOne StaffTwoFactorSecret; password disimpan di `password_hash`
(mutator `setPasswordAttribute` memakai `Hash::make`, accessor autentikasi memakai
`getAuthPassword()` mengembalikan `password_hash`). `config/session.php`: ubah default
cookie menjadi `env('SESSION_COOKIE', 'elzara_web_session')`; `secure` true,
`http_only` true, `same_site` `lax`, `path` `/` sudah sesuai - jangan diubah ke
`SameSite=None` (same-site, ADR-007 D-2a).

- [ ] **Step 3: Middleware pemilih cookie sebelum StartSession (D-DR-5)**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SelectSessionCookie
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['session.cookie' => $request->is('api/v1/admin*') ? 'elzara_admin_session' : 'elzara_web_session']);

        return $next($request);
    }
}
```

Di `bootstrap/app.php`, prepend middleware ini **paling depan** sebelum
`EncryptCookies`/`StartSession` yang sudah ada; tambah `$middleware->statefulApi();`
(Sanctum `EnsureFrontendRequestsAreStateful` + CSRF). Grup `api` tetap memuat session
seperti scaffold. Tidak ada konfigurasi CORS lintas origin (same-host).

- [ ] **Step 4: config/elzara.php + provider modul**

`config/elzara.php` mengembalikan `['api_prefix' => 'api/v1', 'edge_url' => env('ELZARA_EDGE_URL', 'https://localhost')]`
(dibaca `ModuleServiceProvider` yang sudah ada). `IdentityServiceProvider` mendaftarkan
model morph `user` -> `User::class` (`Relation::enforceMorphMap(['user' => User::class])`)
supaya kolom morph spatie konsisten, dan memuat rute modul. Daftarkan provider di
`bootstrap/providers.php`.

- [ ] **Step 5: Buktikan rute dan CSRF hidup**

Run: `docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 php artisan route:list --path=sanctum`
Expected: `GET sanctum/csrf-cookie` terdaftar.
Run: `docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 php artisan config:clear`
Expected: `INFO Configuration cache cleared successfully.`
Lapor keluaran. Tanpa commit.

### Task 7 - Login admin: gerbang is_operator + 2FA wajib (peran: feature)

**Files:**
- Create (`apps/api/app/Modules/Identity/`):
  `Application/Commands/{LoginOperator,VerifySecondFactor,EnrolSecondFactor,ConfirmSecondFactor}.php`,
  `Infrastructure/Security/TotpGateway.php`,
  `Infrastructure/Http/Controllers/AdminAuthController.php`,
  `Infrastructure/Models/StaffTwoFactorSecret.php`,
  `Domain/InvalidCredentials.php`, `Domain/SecondFactorRequired.php`
- Modify: `apps/api/routes/api.php` (grup admin), `packages/api-contracts/openapi.yaml`
- Test: ditulis testing (Task 12).

- [ ] **Step 1: TotpGateway memakai spomky-labs/otphp (D-DR-4)**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Security;

use OTPHP\TOTP;

final class TotpGateway
{
    public function provision(string $email): array
    {
        $totp = TOTP::create(null, 30, 'sha1', 6);
        $totp->setLabel($email);
        $totp->setIssuer('Elzara');

        return ['secret' => $totp->getSecret(), 'otpauth_uri' => $totp->getProvisioningUri()];
    }

    public function verify(string $secret, string $code): bool
    {
        return TOTP::create($secret, 30, 'sha1', 6)->verify($code, time(), 1);
    }
}
```

Verifikasi API terhadap versi terpasang dulu:
`docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 composer show spomky-labs/otphp | head -3`
Expected: `versions : * v11.5.x`. Bila signature konstruktor berbeda, sesuaikan
dengan bukti `composer show` + kode vendor, bukan menebak.

- [ ] **Step 2: LoginOperator - gerbang is_operator + active, sesi pending 2FA**

Command `LoginOperator::handle(string $email, string $password)`:
1. `RateLimiter::tooManyAttempts('admin-login:'.$email.'|'.request()->ip(), 5)` ->
   lempar `InvalidCredentials` dengan 429 bila lewat (facade bawaan, bukan paket).
2. Cari user via `User::where('email', $email)->first()`; `Hash::check($password, $user->password_hash)`.
3. Tolak bila `status !== 'active'` atau `is_operator !== true` (pesan sama dengan
   password salah - jangan membocorkan keberadaan akun).
4. Simpan `session(['admin.2fa.pending_user_id' => $user->id])`, regenerasi ID sesi,
   kembalikan `SecondFactorRequired` (HTTP 202). **Belum** `Auth::guard('admin')->login()`.
5. `RateLimiter::hit('admin-login:'.$email.'|'.request()->ip(), 60)` setiap percobaan gagal.

- [ ] **Step 3: 2FA wajib + 10 recovery code ter-hash**

`EnrolSecondFactor`: hanya untuk user operator terautentikasi parsial; buat secret
via TotpGateway, simpan `secret_encrypted = Crypt::encryptString($secret)`, bangkitkan
**10** recovery code acak (10 karakter alfanumerik), simpan
`recovery_hashes = array_map(fn ($c) => password_hash($c, PASSWORD_ARGON2ID), $codes)` ke kolom `text[]`;
kembalikan kode plaintext **sekali** di response (tidak disimpan di mana pun).
`ConfirmSecondFactor`: verifikasi satu kode TOTP, isi `confirmed_at`.
`VerifySecondFactor`: terima `code` (TOTP) atau `recovery_code`; recovery valid bila
hash Argon2id-nya cocok melalui `password_verify` di `recovery_hashes`, lalu **hapus hash itu** (satu kali pakai);
setelah valid: `Auth::guard('admin')->login($user)`, `session()->regenerate()`,
hapus `admin.2fa.pending_user_id`, tulis `last_login_at`. Tanpa 2FA terkonfirmasi,
guard admin tidak pernah menerima sesi.

- [ ] **Step 4: Rute admin + spec di langkah yang sama (Doc 11 §4.4 I)**

Tambah di `routes/api.php` grup `prefix('api/v1/admin')` middleware
`['auth:admin']` untuk rute terproteksi, dan rute publik login:
`POST admin/auth/login`, `POST admin/auth/2fa/verify`, `POST admin/auth/2fa/recovery`,
`POST admin/auth/logout` (auth:admin), `POST admin/me/2fa/enrol`,
`POST admin/me/2fa/confirm` (auth:admin). Tambahkan operasi yang sama ke
`openapi.yaml` dengan `x-elzara-auth: admin`, `security: [{cookieAuth: []}]`, dan
`x-elzara-permission` untuk operasi berpermission. Jalankan `bash scripts/codegen.sh`.
Expected: `klien TypeScript sinkron dengan openapi.yaml; stage keduanya dalam satu commit.`

- [ ] **Step 5: Lapor** - `route:list --path=api/v1/admin` menunjukkan rute baru;
tempel keluaran + drift check. Tanpa commit.

### Task 8 - Storefront hybrid + linking terverifikasi + policy (peran: feature)

**Files:**
- Create (`apps/api/app/Modules/Identity/`):
  `Application/Commands/{RegisterCustomer,LoginWithPassword,LoginOrRegisterSocial}.php`,
  `Infrastructure/Http/Controllers/AuthController.php`,
  `Infrastructure/Models/UserIdentity.php`,
  `Infrastructure/Policies/{UserPolicy,UserIdentityPolicy}.php`,
  `Domain/EmailNotVerifiedForLinking.php`
- Modify: `apps/api/routes/api.php`, `apps/api/config/services.php` (buat: kunci google/twitter dari env), `packages/api-contracts/openapi.yaml`
- Test: ditulis testing (Task 12).

- [ ] **Step 1: Daftarkan driver X OAuth2**

Di `IdentityServiceProvider::boot`, dengarkan event
`SocialiteProviders\Manager\SocialiteWasCalled` dan daftarkan
`SocialiteProviders\Twitter\TwitterExtendSocialite` (driver `twitter` bawaan Socialite
adalah OAuth 1.0a yang ditinggalkan X - ditolak, ADR-012 alternatif G). Google memakai
driver bawaan `laravel/socialite`. `config/services.php`:
`'google' => ['client_id' => env('GOOGLE_CLIENT_ID'), 'client_secret' => env('GOOGLE_CLIENT_SECRET'), 'redirect' => env('GOOGLE_REDIRECT_URI')]`,
`'twitter' => ['client_id' => env('TWITTER_CLIENT_ID'), 'client_secret' => env('TWITTER_CLIENT_SECRET'), 'redirect' => env('TWITTER_REDIRECT_URI')]`. Nilai env hanya di `docker/.env` lokal; tambahkan
**nama** variabelnya ke `apps/api/.env.example` (tanpa nilai rahasia).

- [ ] **Step 2: RegisterCustomer + LoginWithPassword (storefront)**

`RegisterCustomer`: buat user `status = active`, `is_operator = false`,
`is_admin = false`, `email_verified_at = null` sampai OTP verifikasi; password via
`Hash::make`; tulis event registrasi ke outbox pada transaksi yang sama (B4, ID saja
B3). `LoginWithPassword`: user `active` apa pun (termasuk operator - mereka tetap
aktor `web` di storefront); `Auth::guard('web')->login()` + regenerasi sesi;
`RateLimiter` login web. **Tidak ada** pemeriksaan spatie di guard web.

- [ ] **Step 3: LoginOrRegisterSocial - linking hanya email terverifikasi**

```php
$social = Socialite::driver($provider)->user(); // stateful: state OAuth divalidasi
$identity = UserIdentity::where('provider', $provider)->where('provider_user_id', $social->getId())->first();
if ($identity !== null) {
    Auth::guard('web')->login($identity->user);
    session()->regenerate();

    return $identity->user;
}
// Google menandai verified_email; X tidak - verifikasi X lewat OTP lokal, bukan klaim provider.
$verifiedByProvider = $provider === 'google' && (bool) data_get($social->user, 'verified_email', false);
$local = User::where('email', $social->getEmail())->first();
if ($local !== null && $verifiedByProvider && $local->email_verified_at !== null) {
    UserIdentity::create(['provider' => $provider, 'provider_user_id' => $social->getId(), 'user_id' => $local->id]);
    Auth::guard('web')->login($local);
    session()->regenerate();

    return $local;
}
if ($local !== null) {
    throw new EmailNotVerifiedForLinking(); // 409 - email mentah provider tidak boleh mengambil alih akun
}
$user = $this->registerFromSocial($social); // email_verified_at null; OTP dulu sebelum linking apa pun
Auth::guard('web')->login($user);
session()->regenerate();

return $user;
```

Rute: `POST auth/register`, `POST auth/login`, `POST auth/logout`,
`GET auth/social/{provider}/redirect`, `GET auth/social/{provider}/callback`
(provider hanya `google|twitter`, divalidasi terhadap CHECK `ck_user_identities_provider`).
`X-Actor-Type` diisi server dari guard (`web`), tidak pernah dipercaya dari klien.

- [ ] **Step 4: Policy kepemilikan storefront**

`UserPolicy::update(User $actor, User $target): bool` -> `$actor->id === $target->id`;
`UserIdentityPolicy::delete(User $actor, UserIdentity $identity): bool` ->
`$actor->id === $identity->user_id`. Daftarkan via `Gate::policy` di provider.
`is_operator`/`is_admin` **tidak memberi hak apa pun** di storefront (Doc 11 §4.4 A).

- [ ] **Step 5: Spec + generate + lapor**

Tambahkan operasi auth ke `openapi.yaml` (`x-elzara-auth: public|customer`), jalankan
`bash scripts/codegen.sh` lalu `bash scripts/check-contract-drift.sh`.
Expected: `tidak ada drift kontrak antara openapi.yaml dan generated/.`
`route:list --path=api/v1/auth` menunjukkan rute baru. Lapor keluaran. Tanpa commit.

### Task 9 - RBAC: bundel role, grant langsung, scope, bypass berjejak (peran: feature)

**Files:**
- Create: `apps/api/database/seeders/ReferenceSeeder.php`,
  `apps/api/database/seeders/Reference/RolePermissionSeeder.php`,
  `apps/api/config/identity.roles.php`,
  `apps/api/app/Modules/Identity/Application/Commands/{GrantPermissionToUser,RevokePermissionFromUser,AssignRoleWithScope}.php`,
  `apps/api/app/Modules/Identity/Application/Queries/PermissionScopeResolver.php`,
  `apps/api/app/Modules/Identity/Infrastructure/Http/Middleware/EnsurePermission.php`,
  `apps/api/app/Modules/Identity/Infrastructure/Http/Controllers/AdminGrantController.php`,
  `apps/api/app/Modules/Audit/Application/AuditLogger.php`,
  `apps/api/app/Modules/Audit/Infrastructure/Models/AuditLog.php`,
  `apps/api/app/Modules/Audit/AuditServiceProvider.php`,
  `apps/api/app/Modules/Identity/Infrastructure/Models/{Role,Permission}.php`
- Modify: `apps/api/app/Modules/Identity/IdentityServiceProvider.php` (Gate::before), `apps/api/routes/api.php`, `packages/api-contracts/openapi.yaml`
- Test: ditulis testing (Task 12).

- [ ] **Step 1: Model spatie guard admin + AuditLogger**

`Role`/`Permission` extends model spatie dengan `protected $guard_name = 'admin';`.
`AuditLogger::record(string $action, string $reason, string $requestId, ?int $actorId = null): void`
menulis `audit_logs` (append-only; `reason` wajib non-kosong). `activity_log` spatie
dibiarkan menulis riwayat model otomatis - ia **bukan** pengganti audit (Doc 11 §4.4 F).

- [ ] **Step 2: Gate::before bypass hanya guard admin, selalu berjejak (D-DR-5)**

Di `IdentityServiceProvider::boot`:

```php
Gate::before(function ($user, string $ability): ?bool {
    if (! auth()->guard('admin')->check()) {
        return null; // bypass tidak berlaku di guard web
    }
    if ((int) auth()->guard('admin')->id() !== (int) $user->id) {
        return null;
    }
    if (! $user->is_admin) {
        return null;
    }
    app(\App\Modules\Audit\Application\AuditLogger::class)->record(
        action: 'rbac.bypass',
        reason: 'is_admin bypass untuk '.$ability,
        requestId: (string) \Illuminate\Support\Str::ulid(),
        actorId: $user->id,
    );

    return true;
});
```

`superadmin` **tidak lagi berarti bypass** - ia bundel permission seperti sembilan
role lain (ADR-012 D-2). Setiap pemakaian bypass = satu baris `audit_logs`.

- [ ] **Step 3: Seeder permission kanonik + 10 role bundel (M-12, Doc 10 §8)**

`config/identity.roles.php` memuat peta prefix kanonik Doc 11 §4.4 B (`user`, `catalog`, `content`, `batch`, `billing`, `order`, `inventory`, `cart`,
`payment`, `shipment`, `report`, `logistics`, `promotion`, `sourcing`, `procurement`,
`marketplace`, `review`, `setting`, `notification`, `ticket`, `audit`; Search tanpa
permission) dan bundel per role. `RolePermissionSeeder`:
- `permissions`: upsert `name = <prefix>.<aksi>` untuk aksi `view/create/update/delete`
  per prefix, plus aksi khusus `billing.void`, `order.force_transition`, `batch.close`,
  `payment.mark_paid_manual`; `guard_name = admin`; `group_name = prefix`. Jumlah
  baris tidak ditulis tangan - ia hasil peta, diverifikasi test.
- `roles`: upsert 10 nama Blueprint (`superadmin`, `catalog-manager`, `sales-ops`,
  `preorder-ops`, `sourcing-ops`, `warehouse-staff`, `procurement-staff`, `finance`,
  `customer-support`, `analyst`), `guard_name = admin`, `is_system = true`.
- `role_has_permissions`: bundel provisional (Q-8 terbuka): `superadmin` semua;
  `catalog-manager` `catalog.*` + `content.view`; `sales-ops` `order.*` + `cart.view`
  + `payment.view` + `user.view`; `preorder-ops` `batch.*` + `order.view` +
  `inventory.view`; `sourcing-ops` `sourcing.*` + `procurement.view` +
  `marketplace.view`; `warehouse-staff` `inventory.*` + `shipment.*` +
  `logistics.view`; `procurement-staff` `procurement.*` + `sourcing.view` +
  `billing.view`; `finance` `billing.*` + `payment.*` + `report.*` + `order.view`;
  `customer-support` `ticket.*` + `user.view` + `order.view` + `review.view`;
  `analyst` `report.*` + `*.view`. Aksi khusus: `billing.void` + `payment.mark_paid_manual`
  -> `finance` + `superadmin`; `order.force_transition` -> `sales-ops` + `superadmin`;
  `batch.close` -> `preorder-ops` + `superadmin`. Matriks ini harus sama persis dengan
  Doc 06 (K2); bila berbeda, BLOCKED, jangan menyimpang diam-diam.
- Idempoten: `upsert` pada kunci alamiah `(name, guard_name)`; jangan menimpa baris
  yang sudah diubah admin (`updated_at <> created_at` dilewati).
`ReferenceSeeder` memanggil `RolePermissionSeeder`; `DatabaseSeeder` memanggil
`ReferenceSeeder` (bentuk `--seed=Kelas` tidak ada di Laravel, Doc 10 §8).

- [ ] **Step 4: Grant langsung + scope + pencabutan lewat cache spatie**

`GrantPermissionToUser`: `$user->givePermissionTo($permission)` (API spatie, menulis
`model_has_permissions` morph + invalidasi cache otomatis). `RevokePermissionFromUser`:
`$user->revokePermissionTo($permission)` - **bukan** `UPDATE`/`DELETE` langsung ke tabel
(cache `PermissionRegistrar`, Doc 11 §4.4 B). `AssignRoleWithScope`:
`$user->assignRole($role)` lalu baris `role_scopes (user_id, role_id, scope_type, scope_id)`.
`PermissionScopeResolver::allowed(string $ability, User $user): ?array` membaca
`role_scopes`: NULL = global; selain itu daftar `scope_id` per `scope_type`; policy
modul memakai resolver ini untuk membatasi **baris**, bukan hanya permission.
`EnsurePermission` middleware: `abort_unless($request->user('admin')->can($ability), 403)`.
Rute admin: `POST admin/users/{user}/permissions`, `DELETE admin/users/{user}/permissions/{permission}`,
`POST admin/users/{user}/roles` dengan `x-elzara-permission: user.update`.

- [ ] **Step 5: Bukti seeder idempoten + spec**

Jalankan `db:seed --class=ReferenceSeeder --force` dua kali (container one-shot pola
Task 3 Step 7). Expected: run kedua nol perubahan baris (buktikan dengan query count
sebelum/sesudah). Tambahkan operasi grant ke `openapi.yaml` + `bash scripts/codegen.sh`
+ `bash scripts/check-contract-drift.sh`. Lapor keluaran. Tanpa commit.

### Task 10 - Frontend kedua app + sinkronisasi kontrak (peran: feature)

**Files:**
- Modify: `apps/admin/app/login/page.tsx`, `apps/admin/lib/{api,session-cookie,session}.ts`,
  `apps/web/app/account/page.tsx`, `apps/web/lib/api.ts`, `packages/shared-types/src/**`
- Create: `apps/admin/app/login/two-factor/page.tsx`, `apps/web/app/auth/callback/page.tsx`
- Test: typecheck container; gerbang drift.

- [ ] **Step 1: Admin login flow di browser**

`apps/admin/app/login/page.tsx`: form email+password -> `GET /sanctum/csrf-cookie`
(origin sama) -> `POST /api/v1/admin/auth/login` dengan header `X-XSRF-TOKEN` dari
cookie `XSRF-TOKEN` -> bila 202, redirect ke `app/login/two-factor/page.tsx` yang
memanggil `POST /api/v1/admin/auth/2fa/verify` (atau `/recovery`). Tidak ada token di
localStorage; sesi hanya cookie `elzara_admin_session`. `lib/session-cookie.ts` yang
sudah ada dipakai untuk membaca nama cookie, jangan membuat helper kedua.

- [ ] **Step 2: Storefront hybrid di browser**

`apps/web/app/account/page.tsx`: tab login/registrasi email+password + tombol
"Masuk dengan Google" / "Masuk dengan X" yang mengarah ke
`/api/v1/auth/social/{provider}/redirect`; `app/auth/callback/page.tsx` menampilkan
status hasil callback (server sudah memegang sesi `elzara_web_session`). Semua mutasi
memanggil `sanctum/csrf-cookie` dulu. `lib/api.ts` memakai klien ter-generate
`@elzara/api-client`, bukan fetch buatan tangan yang menduplikasi kontrak.

- [ ] **Step 3: Typecheck di node:22 + drift**

```bash
docker run --rm -v "$PWD:/w" -w /w/apps/admin node:22 sh -c 'npm ci && npm run typecheck'
docker run --rm -v "$PWD:/w" -w /w/apps/web node:22 sh -c 'npm ci && npm run typecheck'
bash scripts/check-contract-drift.sh
```

Expected: `tsc --noEmit` exit 0 di kedua app; `tidak ada drift kontrak antara
openapi.yaml dan generated/.` Lapor keluaran. Tanpa commit.

### Task 11 - Test unit + Schema (peran: testing; mulai setelah feature selesai)

**Files:**
- Create: `apps/api/tests/Schema/AuthSchemaTest.php`,
  `apps/api/tests/Unit/Identity/OperatorFlagsMapperTest.php`,
  `apps/api/tests/Unit/Identity/RecoveryCodeTest.php`,
  `apps/api/tests/Unit/Identity/PermissionNamingTest.php`,
  `apps/api/app/Modules/Identity/Tests/Unit/BackfillCommandTest.php`
- Modify: `apps/api/phpunit.xml` hanya bila suite Schema perlu ditambahkan (tambah
  `<testsuite name="Schema"><directory>tests/Schema</directory></testsuite>`).
- Test: berkas di atas.

- [ ] **Step 1: Siapkan harness (D-DR-9)**

```bash
docker compose -f docker/compose.yaml up -d postgres redis
set -a; . ./docker/.env; set +a
docker compose -f docker/compose.yaml exec -T postgres psql -U "$POSTGRES_USER" -d postgres -c 'CREATE DATABASE elzara_test;'
```

Expected: `CREATE DATABASE` (atau `already exists` yang diabaikan). Semua test
berjalan terhadap PostgreSQL 16 nyata - tidak ada SQLite (B-11).

- [ ] **Step 2: AuthSchemaTest - invarian CHECK dan bentuk akhir**

Test wajib (metode nyata, bukan placeholder):
- `test_server_adalah_postgres_16`: `SELECT version()` mengandung `PostgreSQL 16`.
- `test_check_menolak_bypass_tanpa_operator`: insert user `is_operator=false`,
  `is_admin=true` -> `QueryException` (CHECK `ck_users_admin_requires_operator`).
- `test_default_dua_boolean_false`: user baru tanpa flag -> keduanya false.
- `test_indeks_operator_ada_dan_indeks_lama_hilang`: `users_operator_status` ada;
  indeks `idx_users_kind_status` lama tidak berlaku lagi (tidak ada di `pg_indexes`).
- `test_kolom_kind_lama_tidak_ada`: `Schema::hasColumn('users', 'kind')` false
  (kind lama diganti dua boolean, contract 0053).
- `test_roles_permissions_unik_name_guard`: insert duplikat `(name, guard_name)` ->
  `QueryException`; `guard_name` default `admin`.
- `test_user_identities_unik_provider`: duplikat `(provider, provider_user_id)` ditolak.
- `test_staff_2fa_recovery_hashes_text_array`: tipe kolom `text[]` di `information_schema`.
- `test_audit_logs_request_id_wajib_dan_reason_nonkosong`: insert tanpa `request_id`
  atau `reason` kosong -> `QueryException`.

- [ ] **Step 3: Unit mapper backfill + recovery + penamaan**

`OperatorFlagsMapperTest`: peta `staff` (lama) -> `is_operator=true`, `customer`
(lama) -> `false`, dan `is_admin=false` untuk **semua** kasus (murni, tanpa DB).
`RecoveryCodeTest`: 10 kode dihasilkan, hash Argon2id cocok, satu kode hanya bisa
dipakai sekali. `PermissionNamingTest`: setiap nama permission cocok regex
`/^[a-z]+\.[a-z_]+$/`, prefix hanya dari daftar kanonik Doc 11 §4.4 B, aksi khusus
hanya empat yang terdaftar. `BackfillCommandTest`: command idempoten (dua kali jalan
-> baris audit bertambah, data tidak berubah; no-op bila kolom kind lama tidak ada).

- [ ] **Step 4: Jalankan suite Unit + Schema**

```bash
docker run --rm --network elzara_backend -v "$PWD:/w" -w /w/apps/api \
  -e DB_HOST=postgres -e DB_DATABASE=elzara_test -e DB_USERNAME="$POSTGRES_USER" -e DB_PASSWORD="$POSTGRES_PASSWORD" \
  -e COMPOSER_ALLOW_SUPERUSER=1 \
  composer:2 sh -c 'apk add --no-cache postgresql-dev && docker-php-ext-install pdo_pgsql && composer install --no-interaction && php artisan test --testsuite=Unit,Schema'
```

Expected: hijau dengan jumlah test + assertions tercetak. Merah = bug kode fitur/
migrasi: tulis reproduksi minimal, lapor controller, **jangan memperbaiki sendiri**.
Tempel keluaran. Tanpa commit.

### Task 12 - Test Feature/integration auth/RBAC (peran: testing)

**Files:**
- Create: `apps/api/tests/Feature/Auth/{AdminLoginGateTest,TwoFactorEnforcementTest,BypassAuditTest,ViewOnlyOperatorTest,RoleScopeTest,SocialLinkingTest,CsrfRequiredTest,SessionSeparationTest}.php`,
  `apps/api/tests/Feature/Auth/CreatesUsers.php` (trait helper pembuat user di area testing)
- Test: berkas di atas.

Catatan kepemilikan: `database/factories/**` milik feature. Bila factory belum ada,
testing **tidak** menulisnya di sana; buat helper user di dalam `tests/Feature/Auth/`
(trait `CreatesUsers`) dan minta feature menambahkan factory bila diperlukan.

- [ ] **Step 1: AdminLoginGateTest**

- Password benar + `is_operator=false` -> 401/403, tidak ada sesi admin.
- `status=suspended` -> ditolak meski operator.
- Operator active + password benar -> 202 pending 2FA, guard admin **belum** check.
- Rate limit: 6 percobaan gagal -> 429.

- [ ] **Step 2: TwoFactorEnforcementTest**

- Sebelum verifikasi TOTP, `Auth::guard('admin')->check()` false.
- TOTP valid -> guard admin check, sesi diregenerasi (ID berubah).
- Recovery code valid -> login; kode yang sama dipakai kedua kali -> ditolak.
- `confirmed_at` null -> login ditolak (2FA wajib, bukan opsional).

- [ ] **Step 3: BypassAuditTest**

- User `is_admin=true` memanggil operasi admin yang tidak di-grant -> lolos **dan**
  satu baris `audit_logs` lahir dengan `action=rbac.bypass`, `reason` non-kosong,
  `request_id` 26 karakter.
- User yang sama di guard web -> bypass tidak berlaku (policy kepemilikan tetap).
- `is_admin=false` tanpa grant -> 403, tidak ada baris bypass.

- [ ] **Step 4: ViewOnlyOperatorTest + RoleScopeTest**

- Grant langsung `catalog.view` tanpa role -> index/show 200, create/update/delete 403.
- Cabut lewat API spatie -> cache ter-invalidasi, akses langsung hilang (bukan UPDATE tabel).
- `role_scopes` batch tertentu -> hanya baris batch itu yang terlihat; scope global
  (NULL) -> semua; pasangan user-role boleh punya global + beberapa scope berbaris.

- [ ] **Step 5: SocialLinkingTest**

- Provider email belum terverifikasi + akun lokal ada -> 409, tidak ada baris
  `user_identities` baru (linking ditolak).
- Email terverifikasi provider + `email_verified_at` lokal terisi -> linking sukses.
- `(provider, provider_user_id)` sama login kedua -> user yang sama, bukan duplikat.
- Driver `twitter` memakai OAuth2 (mock Socialite), bukan OAuth1.

- [ ] **Step 6: CsrfRequiredTest + SessionSeparationTest**

- Mutasi tanpa `X-XSRF-TOKEN` -> 419; dengan token dari `sanctum/csrf-cookie` -> lolos.
- Cookie `elzara_web_session` dikirim ke rute admin -> guard admin menolak (401).
- Cookie `elzara_admin_session` ke rute web -> tidak memberi identitas web.
- Kedua cookie: `Secure`, `HttpOnly`, `SameSite=Lax`, `Path=/` (assert header Set-Cookie).

- [ ] **Step 7: Jalankan suite Feature + Contract**

Perintah harness Task 11 Step 4 dengan `--testsuite=Feature,Contract`.
Expected: hijau, keluaran ditempel. Kegagalan = laporan bug ke controller, bukan
perbaikan diam-diam di kode fitur. Tanpa commit.

### Task 13 - Deptrac B1-B6 + enam gerbang dokumen + drift (peran: testing)

**Files:**
- Create: `apps/api/deptrac.yaml` dan `apps/api/deptrac-b2.yaml` (bila belum ada; bila
  sudah ada dari task lain, rekonsiliasi lewat controller, jangan menimpa diam-diam)
- Test: seluruh suite + arsitektur.

- [ ] **Step 1: deptrac.yaml menegakkan batas modul (B1/B6)**

Tulis lapisan untuk **22 modul** (Audit, Billing, Cart, Catalog, Content, Fulfillment,
Identity, Inventory, Logistics, MarketplacePurchase, Notification, Orders, Payments,
PreOrder, Procurement, Promotion, Reporting, Search, Settings, Sourcing, Support, UGC)
plus Platform, ModuleLoader, Providers, dan satu lapisan `Contracts` gabungan.
Lapisan `Contracts` diletakkan **paling awal** karena deptrac memakai lapisan pertama
yang cocok; dengan begitu antarmuka lintas modul terlepas dari lapisan modul pemiliknya.

```yaml
deptrac:
  paths: [./app]
  layers:
    - name: Contracts
      collectors: [{ type: directory, value: app/Modules/.*/Contracts/.* }]
    - name: Audit
      collectors: [{ type: directory, value: app/Modules/Audit/.* }]
    - name: Billing
      collectors: [{ type: directory, value: app/Modules/Billing/.* }]
    - name: Cart
      collectors: [{ type: directory, value: app/Modules/Cart/.* }]
    - name: Catalog
      collectors: [{ type: directory, value: app/Modules/Catalog/.* }]
    - name: Content
      collectors: [{ type: directory, value: app/Modules/Content/.* }]
    - name: Fulfillment
      collectors: [{ type: directory, value: app/Modules/Fulfillment/.* }]
    - name: Identity
      collectors: [{ type: directory, value: app/Modules/Identity/.* }]
    - name: Inventory
      collectors: [{ type: directory, value: app/Modules/Inventory/.* }]
    - name: Logistics
      collectors: [{ type: directory, value: app/Modules/Logistics/.* }]
    - name: MarketplacePurchase
      collectors: [{ type: directory, value: app/Modules/MarketplacePurchase/.* }]
    - name: Notification
      collectors: [{ type: directory, value: app/Modules/Notification/.* }]
    - name: Orders
      collectors: [{ type: directory, value: app/Modules/Orders/.* }]
    - name: Payments
      collectors: [{ type: directory, value: app/Modules/Payments/.* }]
    - name: PreOrder
      collectors: [{ type: directory, value: app/Modules/PreOrder/.* }]
    - name: Procurement
      collectors: [{ type: directory, value: app/Modules/Procurement/.* }]
    - name: Promotion
      collectors: [{ type: directory, value: app/Modules/Promotion/.* }]
    - name: Reporting
      collectors: [{ type: directory, value: app/Modules/Reporting/.* }]
    - name: Search
      collectors: [{ type: directory, value: app/Modules/Search/.* }]
    - name: Settings
      collectors: [{ type: directory, value: app/Modules/Settings/.* }]
    - name: Sourcing
      collectors: [{ type: directory, value: app/Modules/Sourcing/.* }]
    - name: Support
      collectors: [{ type: directory, value: app/Modules/Support/.* }]
    - name: UGC
      collectors: [{ type: directory, value: app/Modules/UGC/.* }]
    - name: Platform
      collectors: [{ type: directory, value: app/Platform/.* }]
    - name: ModuleLoader
      collectors: [{ type: directory, value: app/ModuleLoader/.* }]
    - name: Providers
      collectors: [{ type: directory, value: app/Providers/.* }]
  ruleset:
    Audit: [Platform, Contracts]
    Billing: [Platform, Contracts]
    Cart: [Platform, Contracts]
    Catalog: [Platform, Contracts]
    Content: [Platform, Contracts]
    Fulfillment: [Platform, Contracts]
    Identity: [Platform, Contracts]
    Inventory: [Platform, Contracts]
    Logistics: [Platform, Contracts]
    MarketplacePurchase: [Platform, Contracts]
    Notification: [Platform, Contracts]
    Orders: [Platform, Contracts]
    Payments: [Platform, Contracts]
    PreOrder: [Platform, Contracts]
    Procurement: [Platform, Contracts]
    Promotion: [Platform, Contracts]
    Reporting: [Platform, Contracts]
    Search: [Platform, Contracts]
    Settings: [Platform, Contracts]
    Sourcing: [Platform, Contracts]
    Support: [Platform, Contracts]
    UGC: [Platform, Contracts]
    Contracts: [Platform]
    Platform: ~
    ModuleLoader: [Platform]
    Providers: [Platform, Contracts]
```

Tidak ada modul yang boleh bergantung pada `Infrastructure` modul lain (B1 satu
penulis per tabel); lintas modul hanya via `Contracts`; migrasi tetap di direktori
modul pemilik (B6, juga dibuktikan test Schema Task 11).

- [ ] **Step 1b: deptrac-b2.yaml menegakkan arah lapisan dalam modul (B2)**

Deptrac memakai lapisan pertama yang cocok per token, jadi aturan intra-modul
`Http -> Application` butuh konfigurasi kedua dengan lapisan arsitektur, bukan
lapisan modul:

```yaml
deptrac:
  paths: [./app/Modules]
  layers:
    - name: Http
      collectors: [{ type: directory, value: app/Modules/.*/Infrastructure/Http/.* }]
    - name: Application
      collectors: [{ type: directory, value: app/Modules/.*/Application/.* }]
    - name: Domain
      collectors: [{ type: directory, value: app/Modules/.*/Domain/.* }]
    - name: Infrastructure
      collectors: [{ type: directory, value: app/Modules/.*/Infrastructure/.* }]
  ruleset:
    Http: [Application]
    Application: [Domain, Infrastructure]
    Infrastructure: [Application, Domain]
    Domain: ~
```

B3/B4/B5 dibuktikan test perilaku (event ID saja, outbox satu transaksi, uang/status
via agregat) yang sudah ada di suite Platform/Concurrency.

- [ ] **Step 2: Jalankan Deptrac (dua konfigurasi) + lint + analyse**

```bash
docker run --rm -v "$PWD:/w" -w /w/apps/api -e COMPOSER_ALLOW_SUPERUSER=1 composer:2 \
  sh -c 'composer install --no-interaction && composer deptrac && php vendor/bin/deptrac analyse --config-file=deptrac-b2.yaml --cache-file=.deptrac-b2.cache && composer lint && composer analyse'
```

Expected: `composer deptrac` exit 0 (ruleset modul); deptrac konfigurasi kedua exit 0
(B2); `pint --test` exit 0; PHPStan level max exit 0. Tempel keluaran. Pelanggaran =
laporan bug ke controller dengan berkas + baris, bukan pelonggaran ruleset diam-diam.

- [ ] **Step 3: Enam gerbang dokumen (K1-K3 sudah selesai)**

Run: `ELZARA_AUDITORS_DIR=C:/temp bash scripts/verify-docs.sh`
Expected exit 0 dengan verdict persis:
`LULUS — semua pemeriksaan A–H bersih`;
`LULUS — COVER (29 daftar) + S1-S3 + KONTRADIKSI bersih`;
`LULUS — <n> tabel, konsisten dengan Doc 11` (`<n>` dibaca dari keluaran);
`LULUS — pohon Doc 01 §3.1 = filesystem, dan tujuh aturan layout ditegakkan di berkas`;
`selesai: 0 kemunculan mencurigakan`; 0 temuan `list_dead_cites.py`.
Bila merah: triase (temuan nyata -> kembalikan ke knowledge/controller; jangan
menyunting `C:/temp`, jangan melonggarkan matcher). Perubahan harapan auditor hanya
lewat controller, disengaja, dilaporkan.

- [ ] **Step 4: Drift kontrak + homoglyph berkas baru**

Run: `bash scripts/check-contract-drift.sh` - Expected: `tidak ada drift kontrak antara openapi.yaml dan generated/.`
Run: `python C:/temp/scan_unicode.py apps/api/tests apps/api/app/Modules/Identity apps/api/app/Modules/Audit` - Expected: `selesai: 0 kemunculan mencurigakan`.
Lapor seluruh keluaran. Tanpa commit.

### Task 14 - Gerbang rebuild docker (peran: docker-rebuild; TERAKHIR)

**Files:**
- Modify (bila perlu, sesuai temuan): `docker/compose.yaml`, `docker/nginx/dev.conf`, `apps/*/Dockerfile`
- Lokal saja: `docker/.env`, `docker/nginx/certs/**`
- Test: health endpoint nyata + `compose ps`.

- [ ] **Step 1: Prasyarat dev**

```bash
cp docker/.env.example docker/.env   # bila belum; isi nilai dev lokal + APP_KEY
mkcert -install && mkcert localhost  # simpan localhost.pem + localhost-key.pem di docker/nginx/certs/
```

Bila `mkcert` tidak ada: BLOCKED (jangan menurunkan TLS diam-diam).

- [ ] **Step 2: Build tiga image dari konteks ROOT**

```bash
docker build -f apps/api/Dockerfile -t elzara/api:dev .
docker build -f apps/web/Dockerfile -t elzara/web:dev .
docker build -f apps/admin/Dockerfile -t elzara/admin:dev .
docker save elzara/api:dev | tar -t | grep -E '\.env$|\.env\.example$|\.pem$|\.key$' \
  && { echo "image berisi berkas env/secret - build ditolak"; exit 1; } || true
```

Expected: ketiga build sukses; pemeriksaan isi image tidak menemukan env/secret.
Konteks root wajib karena `file:../../packages/*` (ADR-010 K-1).

- [ ] **Step 3: Stack hidup + enam bucket**

Run: `bash scripts/dev.sh`
Expected: `compose up -d --build` sukses, `init-buckets.sh` menyebut enam bucket
(`product-media`, `request-attachments`, `invoice-documents`, `shipment-documents`,
`exports`, `audit-archives`), `docker compose -f docker/compose.yaml ps` menunjukkan
semua service healthy/running.

- [ ] **Step 4: Migrasi + seeder di container api**

```bash
bash scripts/migrate.sh
docker compose -f docker/compose.yaml exec -T api php artisan db:seed --class=ReferenceSeeder --force
docker compose -f docker/compose.yaml exec -T api php artisan migrate:status
```

Expected: migrate selesai tanpa error; `migrate:status` semua `Ran`; seeder sukses
dan idempoten (jalankan dua kali, nol perubahan).

- [ ] **Step 5: Health endpoint - keluaran nyata**

```bash
curl -fsS http://localhost/nginx-healthz
curl -fsSk https://localhost/health
curl -fsSk https://localhost/admin/health
docker compose -f docker/compose.yaml exec -T api php -r '$c = @fsockopen("127.0.0.1", 9000); exit($c ? 0 : 1);' && echo api-fpm-ok
docker compose -f docker/compose.yaml exec -T worker php artisan horizon:status
```

Expected: `ok`; JSON `{"status":"ok","app":"web"}`; JSON `{"status":"ok","app":"admin"}`;
`api-fpm-ok`; `running`. Tempel semuanya apa adanya.

- [ ] **Step 6: Gerbang penutup gelombang**

Run: `python C:/temp/check_layout.py` - Expected: gate I hijau (tujuh antrian di
`compose.yaml`, enam bucket di `init-buckets.sh`, dua lockfile, nol workspace).
Lapor ke controller: gelombang selesai hanya bila Task 13 + Task 14 keduanya hijau
dengan keluaran nyata. Tanpa commit (D-DR-10).

### Task K1 - Doc 09 §19 + Doc 01 cermin + konstanta gate H (peran: knowledge, paralel)

**Files:**
- Modify: `docs/09-database-schema.md`, `docs/01-architecture.md`
- Test: `verify_schema.py` + gate H/I.

- [ ] **Step 1:** Perbarui blok Doc 09: `users` dua boolean + CHECK + indeks
  `users_operator_status` (kolom `kind` lama dan indeks `users_kind_status` lama
  diganti, tulis dengan konteks rename); `roles`/`permissions` `name`+`guard_name`;
  empat tabel spatie + `role_scopes`; `user_identities` (Identity); `activity_log`
  (Audit); `staff_2fa_secrets.recovery_hashes text[]`; `audit_logs.request_id`.
  Pivot lama `role_user`/`permission_role` tidak berlaku lagi - diganti bentuk spatie.
- [ ] **Step 2:** §19 diproduksi ulang oleh `python C:/temp/verify_schema.py`, bukan
  dihitung tangan; Doc 01 §3.3 disamakan persis (gate H); pohon §3.1 tetap benar.
- [ ] **Step 3:** Konstanta harapan gate H di auditor berubah **hanya lewat controller**,
  disengaja, dilaporkan; trio §19/§3.3/konstanta berubah bersamaan (Doc 11 §8.8).
- [ ] **Step 4:** Run `python C:/temp/verify_schema.py` dan `python C:/temp/audit_sweep3.py`;
  Expected: `LULUS — <n> tabel, konsisten dengan Doc 11` dan gate H bersih. Tempel keluaran.

### Task K2 - Doc 06 matriks permission + Doc 12 kontrak auth (peran: knowledge, paralel)

**Files:**
- Modify: `docs/06-admin-dashboard-requirements.md`, `docs/12-api-contract.md`
- Test: gate E/S1 + drift.

- [ ] **Step 1:** Doc 06 §2.2: 14 grup/87 submenu verbatim tetap; matriks permission
  `<modul>.<aksi>` disamakan persis dengan prefix kanonik Doc 11 §4.4 B dan bundel
  Task 9; 10 role Blueprint; catatan grant langsung + `role_scopes` + pencabutan
  lewat cache `PermissionRegistrar`.
- [ ] **Step 2:** Doc 12: `securitySchemes` cookie (`cookieAuth`) + CSRF, `security`
  per operasi, `x-elzara-auth` kategori, `x-elzara-permission` operasi admin,
  `X-Actor-Type: web | admin | system`; endpoint auth baru didaftar; 18 jalur
  Blueprint §7 tetap ada seluruhnya (gate I-7 memeriksa keberadaan).
- [ ] **Step 3:** Run `python C:/temp/audit_blueprint_coverage.py` dan
  `python C:/temp/check_layout.py`; Expected: `LULUS — COVER (29 daftar) + S1-S3 +
  KONTRADIKSI bersih` dan gate I hijau. Tempel keluaran.

### Task K3 - Doc 10 §8 + Doc 03 + runbook cache spatie (peran: knowledge, paralel)

**Files:**
- Modify: `docs/10-migration-plan.md`, `docs/03-sprint-plan.md`, `docs/runbooks/**`
- Test: gate F + G.

- [ ] **Step 1:** Doc 10 §8: referensi pivot lama (`role_user` untuk bootstrapping -
  lama, diganti `model_has_roles`) diperbarui ke bentuk spatie dengan konteks rename;
  §4 band tetap, sub-band 0040-0069 gelombang ini dicatat sebagai alokasi D-DR-1.
- [ ] **Step 2:** Runbook baru/terkini: pencabutan hak admin wajib lewat jalur yang
  meng-invalidasi cache spatie (`PermissionRegistrar`), bukan UPDATE tabel; prosedur
  pemberian bypass `is_admin` manual yang dirinya menulis `audit_logs`.
- [ ] **Step 3:** Doc 03: task auth/RBAC menunjuk plan ini sebagai pemilik; tidak ada
  lagi janji "BFF dulu" (ADR-007 (BFF/sesi) sudah diamandemen).
- [ ] **Step 4:** Run `python C:/temp/list_dead_cites.py` dan
  `python C:/temp/scan_unicode.py docs/10-migration-plan.md docs/03-sprint-plan.md docs/runbooks`;
  Expected: 0 temuan dan `selesai: 0 kemunculan mencurigakan`. Tempel keluaran.

---

## 7. Verifikasi akhir gelombang (script, bukan klaim)

| Gerbang | Perintah | Keluaran yang diharapkan |
|---|---|---|
| Skema | `python C:/temp/verify_schema.py` | `LULUS — <n> tabel, konsisten dengan Doc 11` |
| A-H | `python C:/temp/audit_sweep3.py` | `LULUS — semua pemeriksaan A–H bersih` |
| COVER+S1-S3 | `python C:/temp/audit_blueprint_coverage.py` | `LULUS — COVER (29 daftar) + S1-S3 + KONTRADIKSI bersih` |
| Layout (I) | `python C:/temp/check_layout.py` | `LULUS — pohon Doc 01 §3.1 = filesystem, dan tujuh aturan layout ditegakkan di berkas` |
| Homoglyph (G) | `python C:/temp/scan_unicode.py <berkas>` | `selesai: 0 kemunculan mencurigakan` |
| Sitasi (F) | `python C:/temp/list_dead_cites.py` | 0 temuan |
| Semua sekaligus | `ELZARA_AUDITORS_DIR=C:/temp bash scripts/verify-docs.sh` | exit 0 |
| Kontrak | `bash scripts/check-contract-drift.sh` | `tidak ada drift kontrak antara openapi.yaml dan generated/.` |
| Arsitektur | `composer deptrac` + `deptrac analyse --config-file=deptrac-b2.yaml` di container | exit 0 keduanya; B1/B6 via ruleset modul, B2 via lapisan arsitektur, B3-B5 via test perilaku |
| Perilaku | `php artisan test --testsuite=Unit,Feature,Contract,Schema` di container | hijau, jumlah test tercetak |
| Runtime | `bash scripts/dev.sh` + `bash scripts/migrate.sh` + curl health | semua healthy, `ok`, `api-fpm-ok`, `running` |

Yang tidak bisa dibuktikan script dan karena itu dibuktikan test PHPUnit: invarian
CHECK di database nyata, gerbang `is_operator`, 2FA wajib, bypass menulis baris audit,
view-only tidak bisa mutasi, scope membatasi baris, linking menolak email mentah,
CSRF wajib, cookie storefront ditolak guard admin.

## 8. Konsekuensi yang harus diterima

1. Plan ini panjang karena larangan placeholder: setiap langkah yang menyentuh kode
   memuat kode nyata atau perintah nyata dengan expected output.
2. Sub-band 0040-0069 dan kepemilikan tabel auth oleh plan ini menuntut controller
   mencoret tumpang tindih dari Sprint-0 Task 11/12 yang sedang diamandemen; tanpa
   itu, dua plan akan menulis tabel yang sama (D-DR-1, BLOCKED bila terjadi).
3. Konflik register-vs-disk (deptrac, pin platform, paket 2FA, skrip lockfile,
   mailpit) **dilaporkan terbuka**, tidak diputuskan diam-diam oleh agen pelaksana.
4. Tanda tangan threat model adalah prasyarat Task 6, bukan formalitas yang bisa
   dilewati demi jadwal (ADR-007 (BFF/sesi) D-6a).
5. Tidak ada commit di gelombang ini kecuali pemilik meminta; laporan keluaran nyata
   adalah artefak serah terima antar peran.