# Sprint 0 (Fondasi) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Each task ends with a commit. Do not start a task before the previous one is committed and green.

**Goal:** Membangun fondasi yang membuat Sprint 1–7 bisa dikerjakan dua developer tanpa saling menimpa: app Laravel 12 bertema Inertia/React yang hidup di Docker, struktur modul dengan loader glob, skema Identity + platform dengan penjagaan urutan migrasi, dan tiga mekanisme lintas-modul (outbox, state machine, ApiProblem).

**Architecture:** Modular monolith (ADR-001) dengan 16 modul di `app/Modules/<X>/{Domain,Application,Infrastructure,Contracts,Tests}` plus satu shared kernel `app/Platform/` untuk mekanisme lintas modul. Mutasi hanya lewat Command (B2), event lintas modul hanya membawa ID (B3) dan ditulis ke transactional outbox (B4). Semua perintah PHP dan Node berjalan di container; host Windows hanya dipakai untuk editor dan `git`.

**Tech Stack:** PHP 8.4, Laravel 12, Inertia + React 18 + TypeScript, Tailwind CSS v4 (CSS-first `@theme`), Vite, Postgres 16, Redis 7 + Horizon, MinIO, PHPUnit 11 (bawaan `laravel/laravel` 12), Larastan level 8, Laravel Pint, GitHub Actions, Docker Compose.

**Spesifikasi sumber:** [docs/specs/sprint-0-fondasi.md](file:///C:/laragon/www/elzara/docs/specs/sprint-0-fondasi.md) · skema: [docs/09-database-schema.md](file:///C:/laragon/www/elzara/docs/09-database-schema.md) · aturan migrasi: [docs/10-migration-plan.md](file:///C:/laragon/www/elzara/docs/10-migration-plan.md)

---

## Keputusan teknis sprint ini (D-S0-…)

Di luar keputusan arsitektur yang sudah ada (B1–B6, S-1…S-10, M-1…M-13), plan ini menetapkan delapan hal yang sebelumnya belum diputuskan. Task 5 dan Task 6 ikut menulis kembali Doc 01/09/10 seperlunya supaya dokumen tetap benar.

| # | Keputusan | Alasan |
|---|---|---|
| D-S0-1 | Shared kernel baru `app/Platform/` untuk outbox, state machine, ApiProblem, module loader, dan namespace `App\Platform\` | Doc 01 §3 tidak memberi rumah untuk kode lintas modul; menaruhnya di salah satu modul membuat modul lain bergantung pada modul itu (melanggar B1) |
| D-S0-2 | `password_reset_tokens` jadi tabel Identity resmi (`0008`), bukan pakai driver `cache` | `Password::sendResetLink()` default memakai tabel; link reset harus bisa diaudit dan tidak hilang saat Redis restart. Doc 09 §19 + Appendix A Doc 10 ikut diperbarui (total 86 tabel) |
| D-S0-3 | Runner tes = PHPUnit 11 dengan attribute (`#[Test]`), bukan Pest | nol dependensi tambahan; `laravel/laravel` 12 sudah membawanya, dan CI hanya perlu satu jalur perintah |
| D-S0-4 | 2FA pakai `pragmarx/google2fa` + `bacon/bacon-qr-code`, bukan Fortify | Fortify membawa seluruh scaffolding auth bawaan Laravel (listener, controller, model tambahan) yang bertentangan dengan B2 — mutasi hanya lewat Command kita |
| D-S0-5 | Semua perintah tooling lewat container: `composer dev:*` / `composer ci:*` scripts; `deploy/Makefile` hanya pembungkus tipis | host Windows tidak punya `make` (terverifikasi 2026-11-17), dan target CI harus bisa dijalankan developer dengan hasil identik |
| D-S0-6 | PHP host 8.3 tidak pernah dipakai menjalankan kode app; `composer.json` mengunci `config.platform.php = "8.4.0"` | mencegah lolos-di-host-gagal-di-CI; `composer install` di host harus gagal keras, bukan diam-diam |
| D-S0-7 | Middleware keamanan & CSP ditulis tangan (±40 baris), tanpa paket CSP pihak ketiga | kebutuhan kita = satu daftar header + satu string CSP yang dibangun dari config; menambah dependency untuk itu tidak sepadan |
| D-S0-8 | Task 15 dikirim sebagai skrip + runbook + restore drill di container lokal, bukan "deploy ke VPS live" | VPS, registry, dan SSH key milik klien belum ada (Doc 03 §9 jalur paralel). Yang bisa diverifikasi di sini: skrip jalan `--dry-run` dan restore drill lokal |
| D-S0-10 | DDL Sprint 0 ditulis sebagai `DB::statement` SQL literal, bukan Blueprint | Blueprint tidak bisa mengekspresikan `generated always as identity` (S-4), `citext`, CHECK bernama, atau default `now()` di `timestamptz` (S-9); dengan SQL literal, diff migrasi = diff Doc 09 sehingga peninjau tidak perlu tahu terjemahan grammar (Task 6 Step 2) |
| D-S0-9 | `migrate:rollback` tidak pernah jadi alat utama; rollback = deploy ulang tag image sebelumnya | Doc 10 §9 membolehkan rollback skema hanya untuk migrasi yang belum dipakai kode; `--step` berbasis batch tidak sejalan dengan penomoran kita (Doc 10 §4), jadi instrumennya `migrate:status` + dump pra-rilis |

---

## Task 1 — Verifikasi alat kerja (Doc 03 task 0.2 prasyarat)

**Files:** tidak ada file produk — hanya catatan hasil di `docs/specs/sprint-0-fondasi.md` §4.

- [ ] **Step 1: Pastikan daemon Docker hidup**

Run: `docker info --format 'server={{.ServerVersion}}'`
Expected: `server=29.6.2` (sudah diverifikasi 2026-11-17; bila kosong, start Docker Desktop sebelum lanjut — jangan paksa task berikutnya)

- [ ] **Step 2: Pastikan plugin compose & ekstensi php yang dibutuhkan host ada**

Run: `docker compose version && php -m | grep -iE 'pdo_pgsql|intl|bcmath|zip|gd' | wc -l`
Expected: `Docker Compose version v5.3.1` lalu `5`

- [ ] **Step 3: Catat deviasi di spec supaya tidak jadi rahasia umum**

Tambahkan satu baris ke tabel §4 [docs/specs/sprint-0-fondasi.md](file:///C:/laragon/www/elzara/docs/specs/sprint-0-fondasi.md) bila ada yang berbeda dari hasil di atas, lalu:
```bash
git add docs/specs/sprint-0-fondasi.md
git commit -m "docs(spec): catat hasil verifikasi alat kerja Sprint 0"
```

---

## Task 2 — Image dev & stack compose (Doc 03 task 0.2)

Dibuat sebelum kode app, karena semua task berikutnya menjalankan PHP di dalam container.

**Files:**
- Create: `docker/php/Dockerfile`, `docker/php/php.ini`, `docker/php/entrypoint.sh`, `docker/nginx/default.conf`
- Create: `docker/compose.yaml`, `docker/compose.dev.yaml`
- Create: `.env.example`, `.editorconfig`

- [ ] **Step 1: Dockerfile dev (satu stage, bukan multi-stage — produksi nanti Task 15)**

```dockerfile
# docker/php/Dockerfile
FROM php:8.4-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libzip-dev libpng-dev libicu-dev libpq-dev \
    && docker-php-ext-install pdo_pgsql bcmath gd intl zip opcache \
    && rm -rf /var/lib/apt/lists/*

RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh

WORKDIR /app
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
```

- [ ] **Step 2: `php.ini` dan entrypoint**

```ini
; docker/php/php.ini
memory_limit = 512M
upload_max_filesize = 12M
post_max_size = 12M
date.timezone = UTC
opcache.enable = 1
opcache.validate_timestamps = 1
expose_php = 0
```

```bash
#!/bin/sh
# docker/php/entrypoint.sh
set -e
if [ ! -f /app/.env ] && [ -f /app/.env.example ]; then
  cp /app/.env.example /app/.env
  php artisan key:generate --force >/dev/null 2>&1 || true
fi
exec "$@"
```

- [ ] **Step 3: compose base**

```yaml
# docker/compose.yaml
name: elzara
x-app: &app
  image: elzara/app:dev
  build:
    context: ..
    dockerfile: docker/php/Dockerfile
  working_dir: /app
  depends_on:
    postgres: { condition: service_healthy }
    redis: { condition: service_healthy }
  environment:
    DB_HOST: postgres
    DB_PORT: "5432"
    REDIS_HOST: redis
    QUEUE_CONNECTION: redis
    SESSION_DRIVER: redis
    CACHE_STORE: redis

services:
  postgres:
    image: postgres:16-alpine
    environment:
      POSTGRES_PASSWORD: secret
      POSTGRES_DB: elzara
    volumes: [pgdata:/var/lib/postgresql/data]
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U postgres -d elzara"]
      interval: 2s
      retries: 25

  redis:
    image: redis:7-alpine
    command: ["redis-server", "--appendonly", "yes"]
    volumes: [redisdata:/data]
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 2s
      retries: 25

  # database/redis/meilisearch/minio TIDAK mem-publish port ke host (Doc 01 §10.3);
  # akses lewat `docker compose exec` atau SSH tunnel.
  app:
    <<: *app
    volumes: ["..:/app"]

  node:
    image: node:22-alpine
    working_dir: /app
    volumes: ["..:/app"]
    profiles: ["dev"]

  nginx:
    image: nginx:1.27-alpine
    ports: ["8080:80"]
    volumes:
      - ../:/app:ro
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    depends_on: [app]

volumes:
  pgdata:
  redisdata:
```

- [ ] **Step 4: profil dev**

```yaml
# docker/compose.dev.yaml
services:
  app:
    environment:
      XDEBUG_MODE: "debug"
      MAIL_HOST: mailpit
      MAIL_PORT: "1025"
  mailpit:
    image: axllent/mailpit
    ports: ["8025:8025"]        # UI lokal saja
  adminer:
    image: adminer
    ports: ["8081:8080"]
```

```nginx
# docker/nginx/default.conf
server {
  listen 80;
  root /app/public;
  index index.php;
  client_max_body_size 12M;
  location / { try_files $uri $uri/ /index.php?$query_string; }
  location ~ \.php$ {
    fastcgi_pass app:9000;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /app/public$fastcgi_script_name;
  }
}
```

- [ ] **Step 5: `.env.example` — var yang hilang harus bikin gagal dengan nama var disebut (Doc 01 §10.4)**

Isi minimum: `APP_NAME/ENV/KEY/DEBUG/URL`, `APP_LOCALE=id`, `APP_FAKER_LOCALE=id_ID`, `DB_*`, `REDIS_*`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `FILESYSTEM_DISK=local`, `MAIL_*`, `MEILISEARCH_*`, `AWS_*` (endpoint MinIO), `PAYMENT_DRIVER=manual`, `SHIPPING_DRIVER=manual`, `BRAND_THEME=playful`. Tanpa nilai rahasia apa pun.

- [ ] **Step 6: Build & verifikasi (belum ada app, jadi yang diverifikasi runtime-nya)**

Run: `docker compose -f docker/compose.yaml -f docker/compose.dev.yaml build app`
Expected: image `elzara/app:dev` selesai, tanpa error ekstensi

Run: `docker compose -f docker/compose.yaml -f docker/compose.dev.yaml run --rm app php -v`
Expected: `PHP 8.4.` — bila muncul `8.3`, bind mount host PHP yang jalan; periksa sebelum lanjut

- [ ] **Step 7: Commit**

```bash
git add docker .env.example .editorconfig
git commit -m "feat(infra): image dev + stack compose (postgres16/redis7/nginx/mailpit/adminer)"
```

---

## Task 3 — Scaffold app di dalam container + tema aktif (Doc 03 task 0.1)

**Files:**
- Create: seluruh kerangka `laravel/laravel` 12 di root, `resources/css/app.css`, `resources/js/app.tsx`, `resources/js/Pages/Home.tsx`, `routes/web.php`, `vite.config.ts`
- Modify: `composer.json` (platform + scripts), `package.json`

- [ ] **Step 1: Buat project di path sementara dalam container, salin ke root repo**

`composer create-project` menolak direktori berisi file; repo ini sudah berisi `docs/` dan `.git/`.

Run:
```bash
docker compose -f docker/compose.yaml -f docker/compose.dev.yaml run --rm app sh -c \
  "composer create-project laravel/laravel:^12.0 /tmp/skel --no-interaction && cp -a /tmp/skel/. /app/"
```
Expected: tanpa error; `artisan`, `composer.json`, `config/` muncul di root repo. Laravel mengirim `.gitignore` sendiri yang menimpa punyanya — setelah langkah ini, pastikan baris `/rea-evidence/` (Doc 08 §0) dan `/coverage/` ada kembali di file itu.

- [ ] **Step 2: Buang migrasi bawaan Laravel, karena urutan kita dikendalikan Doc 10**

Run: `ls database/migrations`
Expected (sebelum): `0001_01_01_000000_create_users_table.php` + cache + jobs
Run: `rm database/migrations/*.php && git add -A && git status --short`
Expected: tiga file terhapus dari daftar; tidak ada satu pun migrasi di `database/migrations/` sampai Task 6 menulis ulang yang kita butuh (framework queue + `password_reset_tokens`)

- [ ] **Step 3: Kunci platform PHP 8.4 (D-S0-6) dan scripts tooling (D-S0-5)**

Tambahkan ke `composer.json`:
```json
"config": { "platform": { "php": "8.4.0" } },
"scripts": {
  "dev:up": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml up -d",
  "dev:down": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml down",
  "dev:run": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml run --rm app",
  "ci:test": "@dev:run php artisan test",
  "ci:lint": "@dev:run vendor/bin/pint --test",
  "ci:stan": "@dev:run vendor/bin/phpstan analyse --no-progress",
  "dev:sh": "docker compose -f docker/compose.yaml -f docker/compose.dev.yaml exec app sh"
}
```
`@dev:run <cmd>` adalah cara Composer menjalankan script lain dengan argumen tambahan — ini yang membuat satu jalur perintah berlaku untuk host Windows maupun Linux CI (D-S0-5).

- [ ] **Step 4: Install dependensi frontend & build sekali**

Run: `docker compose run --rm node npm install && npm run build`
Expected: `public/build/manifest.json` ada; tidak ada error Vite

- [ ] **Step 4: Inertia + React + TS + Tailwind v4**

Run:
```bash
docker compose run --rm app composer require inertiajs/inertia-laravel:@2 --no-interaction
docker compose run --rm node npm i react react-dom @inertiajs/react
docker compose run --rm node npm i -D @vitejs/plugin-react typescript tailwindcss @tailwindcss/vite
```
Expected: `package.json` memuat keempatnya; `composer.json` memuat `inertiajs/inertia-laravel`

- [ ] **Step 5: Konfigurasi Inertia + satu halaman terbukti dirender**

Create `resources/views/app.blade.php` (shell Inertia), `resources/js/app.tsx` (`createInertiaApp`), `resources/js/Pages/Home.tsx`, dan `routes/web.php`:
```php
Route::get('/', fn () => Inertia::render('Home', ['brand' => config('brand.name')]));
```
`vite.config.ts` memakai `laravel()`, `react()`, `tailwindcss()`; `resources/css/app.css` dibuka dengan `@import "tailwindcss";`

Run: `docker compose up -d` lalu `curl -s localhost:8080 | grep -o 'id="app"'`
Expected: `id="app"` muncul — kalau kosong, kesalahan ada di build Vite atau root blade, bukan di React

- [ ] **Step 6: `config/brand.php` + `BRAND_THEME` (Doc 04 §1, §3)**

Create `config/brand.php` sesuai Doc 04 §3 dengan nilai placeholder milik sendiri (nama "Elzara", tanpa teks situs mana pun). Read-only untuk sprint ini.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(app): scaffold laravel 12 + inertia/react/tailwind v4 di dalam container"
```

---

## Task 4 — Struktur modul + loader glob (Doc 01 §3, Doc 10 M-1/M-2)

Ini task yang membuat §5.1 Doc 10 berlaku: tidak ada berkas bersama yang harus diedit saat modul bertambah.

**Files:**
- Create: `app/Platform/Modules/ModuleManifest.php`, `app/Platform/Providers/PlatformServiceProvider.php`
- Create: `bootstrap/providers.php` (baris baru), `app/Modules/Identity/Infrastructure/IdentityServiceProvider.php` + folder kerangka Identity
- Test: `tests/Unit/Platform/ModuleManifestTest.php`

- [ ] **Step 1: Tulis test gagal untuk manifest**

```php
<?php
namespace Tests\Unit\Platform;

use App\Platform\Modules\ModuleManifest;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ModuleManifestTest extends TestCase
{
    #[Test]
    public function it_discovers_module_service_providers_by_glob(): void
    {
        $manifest = new ModuleManifest(base_path('app/Modules'));

        $this->assertContains(
            'App\\Modules\\Identity\\Infrastructure\\IdentityServiceProvider',
            $manifest->providers(),
        );
    }

    #[Test]
    public function migration_basenames_are_globally_unique(): void
    {
        // Doc 10 M-1: Laravel mengurutkan semua path migration berdasarkan basename,
        // jadi nama yang sama di dua modul akan saling menimpa secara senyap.
        $paths = (new ModuleManifest(base_path('app/Modules')))->migrationPaths();
        $names = [];
        foreach ($paths as $path) {
            foreach (glob($path.'/*.php') ?: [] as $file) {
                $base = basename($file, '.php');
                $this->assertArrayNotHasKey($base, $names, "duplikat basename: {$base}");
                $names[$base] = true;
            }
        }
    }
}
```

- [ ] **Step 2: Jalankan — pastikan gagal**

Run: `docker compose run --rm app php artisan test --filter=ModuleManifestTest`
Expected: FAIL, class `App\Platform\Modules\ModuleManifest` tidak ditemukan

- [ ] **Step 3: Implementasi manifest**

```php
<?php
namespace App\Platform\Modules;

final class ModuleManifest
{
    public function __construct(private readonly string $modulesPath) {}

    /** @return list<string> nama modul (direktori yang berisi Application+Domain) */
    public function names(): array
    {
        $out = [];
        foreach (glob($this->modulesPath.'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (is_dir($dir.'/Domain') && is_dir($dir.'/Application')) {
                $out[] = basename($dir);
            }
        }
        sort($out);
        return $out;
    }

    /** @return list<class-string> */
    public function providers(): array
    {
        $out = [];
        foreach ($this->names() as $name) {
            $class = "App\\Modules\\{$name}\\Infrastructure\\{$name}ServiceProvider";
            if (class_exists($class)) {
                $out[] = $class;
            }
        }
        return $out;
    }

    /** @return list<string> path migrasi per modul (Doc 10 M-2: glob, bukan daftar manual) */
    public function migrationPaths(): array
    {
        return array_values(array_filter(array_map(
            fn (string $n) => $this->modulesPath."/{$n}/Infrastructure/Database/Migrations",
            $this->names(),
        ), 'is_dir'));
    }

    /** @return list<string> file route per modul, bila ada */
    public function routeFiles(): array
    {
        $out = [];
        foreach ($this->names() as $name) {
            foreach (['web', 'api'] as $group) {
                $file = $this->modulesPath."/{$name}/Infrastructure/routes/{$group}.php";
                if (is_file($file)) {
                    $out[] = $file;
                }
            }
        }
        return $out;
    }
}
```

- [ ] **Step 4: Provider platform + daftarkan**

```php
<?php
namespace App\Platform\Providers;

use App\Platform\Modules\ModuleManifest;
use Illuminate\Support\ServiceProvider;

final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ModuleManifest::class,
            fn () => new ModuleManifest(base_path('app/Modules')),
        );
    }

    public function boot(): void
    {
        $manifest = $this->app->make(ModuleManifest::class);

        foreach ($manifest->migrationPaths() as $path) {
            $this->loadMigrationsFrom($path);
        }
        foreach ($manifest->routeFiles() as $file) {
            // modul menambahkan route-nya sendiri; grup & middleware di dalam file itu
            $this->app->booted(fn () => require $file);
        }
        foreach ($this->modules($manifest) as $provider) {
            $this->app->register($provider, true);
        }
    }

    /** @return list<string> */
    private function modules(ModuleManifest $manifest): array
    {
        return $manifest->providers();
    }
}
```
Daftarkan di `bootstrap/providers.php`: `App\Platform\Providers\PlatformServiceProvider::class`. Namespace `App\Modules\` tidak perlu entri autoload tambahan — `App\` sudah memetakannya ke `app/`.

- [ ] **Step 5: Buat kerangka modul Identity**

```
app/Modules/Identity/
  Domain/            (kosong untuk sekarang, .gitkeep)
  Application/       Commands/ Queries/ (kosong)
  Contracts/         (kosong)
  Infrastructure/    Models/ Database/Migrations/ routes/ IdentityServiceProvider.php
  Tests/             Feature/ Unit/
```
`IdentityServiceProvider` kosong tapi valid (extends `ServiceProvider`, tanpa isi) supaya manifest menemukannya.

- [ ] **Step 6: Jalankan test — harus hijau, lalu verifikasi route app masih hidup**

Run: `docker compose run --rm app php artisan test --filter=ModuleManifestTest && curl -s -o /dev/null -w '%{http_code}' localhost:8080`
Expected: `OK (2 tests)` lalu `200`

- [ ] **Step 7: Commit**

```bash
git add app/Platform app/Modules bootstrap/providers.php tests/Unit/Platform
git commit -m "feat(platform): struktur modul + loader glob untuk migrasi/route/provider"
```

---

## Task 5 — Pipeline CI + penjagaan skema C1–C8 (Doc 03 task 0.3, Doc 10 §10)

CI ditulis sebelum mekanisme platform supaya C3 (urutan level) langsung aktif.

**Files:**
- Create: `.github/workflows/ci.yml`, `tests/Schema/SchemaGuardsTest.php`, `docs/schema.snapshot.sql`
- Modify: `phpstan.neon` (buat, level 8 + baseline), `composer.json` (dev deps)

- [ ] **Step 1: workflow CI**

```yaml
# .github/workflows/ci.yml
name: ci
on: { pull_request: {}, push: { branches: [main] } }
jobs:
  test:
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:16-alpine
        env: { POSTGRES_PASSWORD: secret, POSTGRES_DB: elzara_test }
        ports: ["5432:5432"]
        options: >-
          --health-cmd "pg_isready -U postgres" --health-interval 2s --health-retries 25
      redis:
        image: redis:7-alpine
        ports: ["6379:6379"]
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.4', extensions: pdo_pgsql,redis,intl,bcmath,zip,gd }
      - uses: actions/setup-node@v4
        with: { node-version: '22' }
      - run: composer install --no-interaction --prefer-dist
      - run: npm ci && npm run build
      - run: vendor/bin/pint --test
      - run: vendor/bin/phpstan analyse --no-progress --memory-limit=1G
      - run: cp .env.example .env && php artisan key:generate --force
      - run: php artisan migrate:fresh --force && php artisan db:seed --force
      - run: php artisan test
      - run: php tests/Schema/guard.php snapshot   # C6: bandingkan docs/schema.snapshot.sql
```

- [ ] **Step 2: Test gagal untuk penjaga urutan (C3)**

Tulis `tests/Schema/SchemaGuardsTest.php` dengan dua test nyata: (a) tidak ada FK di file migrasi yang menunjuk tabel yang belum ada pada saat nomor itu dijalankan — caranya: jalankan `migrate` bertahap lalu assert; (b) tidak ada kolom uang bertipe float/decimal (C4).

- [ ] **Step 3: Implementasi C1–C8 sebagai test + satu skrip snapshot**

Peta pemeriksaan ke implementasi (Doc 10 §10):

| ID | Implementasi |
|---|---|
| C1 | `php artisan migrate:fresh` di `SchemaGuardsTest::setUpBeforeClass` lewat Postgres service CI |
| C2 | test Task 4 Step 1 (`migration_basenames_are_globally_unique`) — sudah ada |
| C3 | `tests/Schema/SchemaGuardsTest::every_foreign_key_target_precedes_its_migration` |
| C4 | `::no_float_and_no_enum_type_columns` — query `information_schema.columns` untuk `numeric/float8/float4` pada nama kolom yang mengandung `amount|price|fee|cost|balance`, dan `pg_type` untuk enum |
| C5 | `::every_reversible_migration_can_roll_back` — daftar hitam irreversibel di `tests/Schema/irreversible.php` |
| C6 | `tests/Schema/guard.php snapshot` / `check` membandingkan dump `information_schema` + constraint + index dengan `docs/schema.snapshot.sql` |
| C7 | `::reference_seeder_is_idempotent` — jalankan `ReferenceSeeder` dua kali, assert jumlah baris per tabel identik |
| C8 | `::every_migration_has_the_required_header` — regex header `Modul/Sprint/Tier/Expand` |

Contoh C4 dan C8 (lengkap, bisa langsung dipakai):
```php
#[Test]
public function no_float_and_no_enum_type_columns(): void
{
    $money = DB::select("
        select table_name, column_name, data_type
        from information_schema.columns
        where (column_name ~* '(amount|price|fee|cost|balance|total)')
          and data_type in ('numeric','double precision','real')
    ");
    $this->assertSame([], $money, 'uang harus bigint rupiah (Doc 09 S-1)');

    $enums = DB::select("select typname from pg_type where typtype = 'e'");
    $this->assertSame([], $enums, 'enum varchar+CHECK, bukan CREATE TYPE (Doc 09 S-2)');
}

#[Test]
public function every_migration_has_the_required_header(): void
{
    $files = glob(base_path('app/Modules/*/Infrastructure/Database/Migrations/*.php')) ?: [];
    $this->assertNotEmpty($files, 'task ini harus dijalankan setelah Task 6');

    foreach ($files as $file) {
        $head = implode('', array_slice(file($file), 0, 12));
        foreach (['Modul', 'Sprint', 'Tier'] as $key) {
            $this->assertMatchesRegularExpression("/^\\/\\/ {$key}\\s*:/m", $head, basename($file));
        }
    }
}
```

- [ ] **Step 4: Larastan level 8**

`composer require --dev larastan`, buat `phpstan.neon` dengan `level: 8`, `paths: [app, database]`, dan `generate-baseline: true` sekali (`vendor/bin/phpstan analyse --generate-baseline`). Baseline ter-commit; jumlah entri baseline tidak boleh naik di PR berikutnya (periksa diff).

- [ ] **Step 5: Verifikasi CI bisa ditiru lokal**

Run: `docker compose run --rm app composer ci:test` — target `ci:test` adalah `@dev:run php artisan test` yang ditulis di Task 3 Step 3
Expected: test yang belum punya materi (C3/C5/C6/C7) boleh di-skip dengan `$this->markTestSkipped()` **sampai Task 6**; setelah Task 6 tidak ada lagi skip di suite schema.

- [ ] **Step 6: Commit**

```bash
git add .github tests/Schema phpstan.neon docs/schema.snapshot.sql composer.json composer.lock phpstan.neon.baseline
git commit -m "feat(ci): pipeline lint/phpstan/test + penjaga skema C1-C8"
```

---

## Task 6 — Skema Sprint 0: migrasi `0001–0021` (Doc 03 task 0.6 bagian skema)

**Files:** Create di `app/Modules/Identity/Infrastructure/Database/Migrations/` dan `app/Platform/` (outbox, audit), `database/migrations/` (framework), `docs/09-database-schema.md`, `docs/10-migration-plan.md`

- [ ] **Step 1: Tentukan nomor & tier dari Appendix A, jangan dari perkiraan**

Rencana file (level → nomor; semua di rentang Sprint 0 `0001–0199`):

| Nomor | File | Modul | Tier |
|---|---|---|---|
| 0001 | `Core_create_jobs_table` | Platform/Laravel | 0 |
| 0002 | `Core_create_failed_jobs_table` | Platform | 0 |
| 0003 | `Core_create_job_batches_table` | Platform | 0 |
| 0004 | `Core_create_citext_extension` | Platform | — (bukan tabel; prasyarat kolom, D-S0-10) |
| 0005 | `Core_create_outbox_events_table` | Platform | 0 |
| 0006 | `Identity_create_roles_table` | Identity | 0 |
| 0007 | `Identity_create_permissions_table` | Identity | 0 |
| 0008 | `Identity_create_permission_role_and_role_user_tables` | Identity | 0 |
| 0009 | `Identity_create_password_reset_tokens_table` | Identity | 0 (D-S0-2) |
| 0010 | `Content_create_media_table` | Content | 0 (S-3) |
| 0011 | `Identity_create_users_table` | Identity | 1 |
| 0012 | `Identity_create_user_addresses_table` | Identity | 2 |
| 0013 | `Identity_create_user_consents_table` | Identity | 2 |
| 0014 | `Identity_create_otp_challenges_table` | Identity | 2 |
| 0015 | `Identity_create_user_sessions_table` | Identity | 2 |
| 0016 | `Identity_create_staff_2fa_secrets_table` | Identity | 2 |
| 0017 | `Settings_create_feature_flags_table` | Settings | 2 |
| 0018 | `Settings_create_settings_table` | Settings | 2 |
| 0019 | `Content_create_localizations_table` | Content | 2 |
| 0020 | `Settings_create_feature_flag_overrides_table` | Settings | 3 |
| 0021 | `Audit_create_audit_logs_table` | Audit | 2 |

`media` (0010) sengaja sebelum `users` (0011) karena `users.avatar_media_id` → S-3. `citext` dibuat sekali di 0004 dan tidak pernah diulang — `create extension` yang diulang di migrasi berikutnya akan gagal di database staging yang sudah memilikinya, dan lolos di database kosong (persis tipe bug yang tidak terlihat).

- [ ] **Step 2: Satu contoh lengkap, sisanya mengikuti bentuknya**

Blueprint Laravel tidak bisa mengekspresikan `generated always as identity` (S-4), `citext` (uniqueness case-insensitive), CHECK bernama, atau `timestamptz` dengan default (`timestamptz` ya, default `now()` tidak). Karena itu **DDL Sprint 0 ditulis sebagai `DB::statement` yang mencerminkan Doc 09 baris per baris** (keputusan baru D-S0-10) — bukan campuran Blueprint + statement.

```php
<?php
// 0011_Identity_create_users_table.php
// Modul  : Identity   (B1: hanya Identity yang menulis tabel ini)
// Sprint : 0 · rilis 2026-01
// Tier   : 1 (FK: media[0010])
// Expand : ya — tidak ada contract di sprint ini

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            create table users (
                id                  bigint generated always as identity primary key,   -- S-4
                public_id           varchar(26)  not null,
                name                varchar(120) not null,
                email               citext       not null,                              -- case-insensitive, normalisasi lower() di Domain
                email_verified_at   timestamptz,
                phone               varchar(24),
                phone_verified_at   timestamptz,
                password_hash       varchar(255),
                kind                varchar(16)  not null default 'customer',
                status              varchar(16)  not null default 'active',
                locale              varchar(5)   not null default 'id',
                timezone            varchar(64)  not null default 'Asia/Jakarta',
                avatar_media_id     bigint,
                banner_media_id     bigint,
                marketing_optin     boolean      not null default false,
                last_login_at       timestamptz,
                deleted_at          timestamptz,                                        -- S-7
                created_at          timestamptz  not null default now(),
                updated_at          timestamptz  not null default now(),                -- S-9
                constraint uq_users_public_id unique (public_id),
                constraint uq_users_email      unique (email),
                constraint ck_users_kind       check (kind in ('customer','staff')),
                constraint ck_users_status     check (status in ('active','suspended','deletion_requested','deleted')),
                constraint fk_users_avatar foreign key (avatar_media_id) references media(id) on delete set null,
                constraint fk_users_banner foreign key (banner_media_id) references media(id) on delete set null
            )
            SQL);

        DB::statement('create index idx_users_kind_status on users (kind, status)');
        // `create extension citext` TIDAK diulang di sini — ia dibuat sekali di 0004 (lihat Step 3)
    }

    public function down(): void
    {
        DB::statement('drop table if exists users');
    }
};
```

Bentuk ini dipakai semua tabel sprint ini: satu `create table` literal yang bisa dibaca berdampingan dengan definisi Doc 09, constraint dinamai persis seperti di dokumen, dan FK sebagai `constraint fk_*` eksplisit (bukan `$table->foreign()`). Keuntungannya langsung terlihat saat meninjau: diff migrasi = diff skema, tanpa terjemahan grammar.

- [ ] **Step 3: Aturan wajib semua file migrasi sprint ini (Doc 10 §2, §5.6)**

- Header komentar `Modul/Sprint/Tier/Expand` — dibaca oleh C8.
- `create extension` hanya di `0004`; tidak ada migrasi lain yang membuat ekstensi. Kolom email bertipe `citext` mengasumsikan 0004 sudah jalan (tier-nya 1, jadi urutan nomor menjaganya).
- Constraint dinamai eksplisit: `ck_*`, `uq_*`, `fk_*`, `idx_*` persis seperti Doc 09.
- Tabel append-only (`outbox_events`, `audit_logs`) tanpa `UPDATE`/`DELETE` di migrasi mana pun.
- Tidak ada `INSERT` data (M-12) — itu seeder Task 12.
- `down()` kebalikan persis (M-6); tidak ada yang irreversibel di rentang ini, jadi C5 harus hijau penuh.

- [ ] **Step 4: Verifikasi urutan benar-benar jalan dari kosong**

Run: `docker compose run --rm app php artisan migrate:fresh --force`
Expected: 21 migrasi (0001–0021), nol error. Kalau muncul `relation "media" does not exist`, nomor tier salah — perbaiki, jangan tambah `--step`.

Run: `docker compose run --rm app php artisan test --filter=SchemaGuardsTest`
Expected: C1–C8 hijau, tidak ada `markTestSkipped` tersisa di suite schema.

- [ ] **Step 5: Regenerate snapshot & perbarui dokumen (langkah yang membuat Doc 09/10 tetap benar)**

```bash
docker compose run --rm app php tests/Schema/guard.php snapshot
git add docs/schema.snapshot.sql
```
Lalu sunting:
- [docs/09-database-schema.md](file:///C:/laragon/www/elzara/docs/09-database-schema.md): tambah `password_reset_tokens` di §2 Identity, update baris Identity 10→11 dan Total 85→86.
- [docs/10-migration-plan.md](file:///C:/laragon/www/elzara/docs/10-migration-plan.md): tambah `password_reset_tokens[...]` ke Appendix A **L0**, dan buat catatan D-S0-2 di §1.
- [docs/01-architecture.md](file:///C:/laragon/www/elzara/docs/01-architecture.md): tambah baris `Platform` di tabel modul §3 dengan isi "shared kernel: outbox, state machine, ApiProblem, module loader (D-S0-1)".

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat(schema): migrasi 0001-0020 kerangka identitas + platform, snapshot & dokumen disamakan"
```

---

## Task 7 — Domain exception & ApiProblem (Doc 03 task 0.7, Doc 01 §11)

**Files:** Create `app/Platform/Exceptions/{DomainException,ValidationException}.php`, `app/Platform/Http/ApiProblem.php`, `app/Platform/Http/ApiProblemRenderer.php`, `bootstrap/app.php` (modifikasi), `tests/Feature/Platform/ApiProblemTest.php`

- [ ] **Step 1: Test gagal**

```php
<?php
namespace Tests\Feature\Platform;

use App\Platform\Exceptions\DomainException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ApiProblemTest extends TestCase
{
    #[Test]
    public function domain_exception_becomes_a_problem_json_for_xhr(): void
    {
        \Illuminate\Support\Facades\Route::get('/_probe/problem', function () {
            throw new class extends DomainException {
                public string $code = 'po_period_closed';
                public int $status = 422;
                public ?string $detail = 'Periode batch sudah ditutup.';
            };
        });

        $response = $this->withHeaders(['Accept' => 'application/json', 'X-Inertia' => 'true'])
            ->getJson('/_probe/problem');

        $response->assertStatus(422)->assertJsonStructure([
            'type', 'title', 'status', 'code', 'detail', 'request_id',
        ]);
    }
}
```

- [ ] **Step 2: Implementasi**

```php
<?php
namespace App\Platform\Exceptions;

abstract class DomainException extends \RuntimeException
{
    public string $code = 'domain_error';
    public int $status = 422;
    public ?string $detail = null;

    /** @param array<string,mixed> $meta */
    public function __construct(?string $detail = null, public array $meta = [])
    {
        parent::__construct($detail ?? $this->detail ?? static::class);
        if ($detail !== null) { $this->detail = $detail; }
    }
}
```

```php
<?php
namespace App\Platform\Http;

final class ApiProblem
{
    /** @param array<string,mixed> $extra */
    public static function render(
        string $code, int $status, ?string $detail, array $extra = [],
    ): \Illuminate\Http\JsonResponse {
        return response()->json(array_replace([
            'type' => 'https://elzara.test/problems/' . $code,
            'title' => ucfirst(str_replace('_', ' ', $code)),
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'request_id' => request()->header('X-Request-Id', 'unknown'),
        ], $extra), $status);
    }
}
```

- [ ] **Step 3: Registrasi handler Laravel 12 di `bootstrap/app.php`**

```php
->withExceptions(function (\Illuminate\Foundation\Configuration\Exceptions $e): void {
    $e->renderable(function (App\Platform\Exceptions\DomainException $ex, \Illuminate\Http\Request $req) {
        if ($req->expectsJson() || $req->header('X-Inertia')) {
            return App\Platform\Http\ApiProblem::render($ex->code, $ex->status, $ex->detail, $ex->meta);
        }
        return back()->withErrors([$ex->code => $ex->detail ?? $ex->getMessage()])->withInput();
    });
})
```
Untuk request non-JSON, Inertia menerima error lewat `errors` flash — itu jalur yang dipakai form.

- [ ] **Step 4: Hijaukan & commit**

Run: `docker compose run --rm app php artisan test --filter=ApiProblemTest`
Expected: `OK (1 test)`
```bash
git add app/Platform bootstrap/app.php tests/Feature/Platform
git commit -m "feat(platform): domain exception + ApiProblem untuk xhr/inertia"
```

---

## Task 8 — Transactional outbox + dispatcher Horizon (Doc 01 B4, Doc 09 §17)

**Files:** Create `app/Platform/Outbox/{OutboxMessage,OutboxRecorder,OutboxDispatcher}.php`, `app/Platform/Console/DispatchOutboxCommand.php`, `routes/console.php` (jadwal), `tests/Feature/Platform/OutboxTest.php`

- [ ] **Step 1: Test gagal — event tidak hilang walau worker mati, dan replay idempoten**

```php
#[Test]
public function event_written_in_the_same_transaction_is_not_dispatched_before_commit(): void
{
    DB::beginTransaction();
    (app(OutboxRecorder::class))->record('PaymentCaptured', ['intent_id' => 1, 'order_id' => 1]);
    $this->assertSame(0, app(OutboxDispatcher::class)->dispatchPending(10), 'belum commit = belum boleh keluar');
    DB::commit();
    $this->assertSame(1, app(OutboxDispatcher::class)->dispatchPending(10));
    $this->assertSame(0, app(OutboxDispatcher::class)->dispatchPending(10), 'sekali terkirim tidak dua kali');
}

#[Test]
public function payload_carries_ids_only(): void
{
    $row = /* ambil baris outbox yang baru direcord */;
    $this->assertSame(['intent_id', 'order_id'], array_keys($row->payload));
}
```

- [ ] **Step 2: Recorder + dispatcher dengan claim `FOR UPDATE SKIP LOCKED`**

```php
<?php
namespace App\Platform\Outbox;

final class OutboxDispatcher
{
    public function __construct(private readonly \Illuminate\Contracts\Events\Dispatcher $events) {}

    /** @return int jumlah event yang dikirim pada panggilan ini */
    public function dispatchPending(int $limit = 100): int
    {
        $sent = 0;
        DB::transaction(function () use ($limit, &$sent) {
            $rows = OutboxMessage::query()
                ->whereNull('dispatched_at')
                ->where(fn ($q) => $q->whereNull('locked_at')->orWhere('locked_at', '<', now()->subMinutes(2)))
                ->orderBy('occurred_at')
                ->limit($limit)
                ->lockForUpdate()
                ->skipLocked()
                ->get();

            foreach ($rows as $row) {
                $this->events->broadcast($row->event_name, $row->payload);
                $row->markDispatched();
                $sent++;
            }
        });
        return $sent;
    }
}
```
`OutboxMessage` = model Eloquent milik Platform (tabel `outbox_events`, Doc 09 §17) dengan `markDispatched()` yang mengisi `dispatched_at`; kolom `attempts` dan `last_error` dipakai ketika `broadcast()` melempar exception — backoff eksponensial, maksimal 5 percobaan lalu baris dibiarkan `dispatched_at = null` dan muncul di dashboard antrian (Doc 01 §11).

Klaim baris adalah satu-satunya penjaga duplikasi, jadi `lockForUpdate()` + `skipLocked()` harus ada di statement yang sama. Bila Postgres menolak kombinasi itu (mis. karena versi), dispatcher berubah menjadi race: dua worker mengirim event yang sama. Test `never_dispatches_an_event_twice_with_two_concurrent_workers` wajib lulus sebelum task ini dianggap selesai.

- [ ] **Step 3: Jalur kirim: Horizon queue + schedule 5 detik**

```php
// routes/console.php
Schedule::command('platform:dispatch-outbox --limit=200')->everyFiveSeconds()->withoutOverlapping();
```
`DispatchOutboxCommand` memanggil dispatcher, logging jumlah, dan mengembalikan `SUCCESS` bahkan saat kosong.

- [ ] **Step 4: Test integrasi: event yang gagal dispatch tidak menandai dispatched**

Tambahkan test yang melempar exception dari listener lalu assert `dispatched_at` tetap `null` dan `attempts` naik.

- [ ] **Step 5: Hijaukan & commit**

```bash
git add app/Platform routes/console.php tests/Feature/Platform
git commit -m "feat(platform): transactional outbox + dispatcher claim-based"
```

---

## Task 9 — State machine runner deklaratif (Doc 01 §6)

Tabel order belum ada (Sprint 2), jadi sprint ini mengirim **mesin + config + test transisi**, bukan order.

**Files:** Create `app/Platform/StateMachine/{StateMachine,TransitionConfig,TransitionResult}.php`, `config/state-machines.php`, `tests/Unit/Platform/StateMachineTest.php`

- [ ] **Step 1: Test semua transisi valid & invalid untuk lane impor (dari diagram Doc 01 §6)**

```php
#[Test]
#[DataProvider('transitions')]
public function declared_transitions(string $from, string $event, ?string $to): void
{
    $machine = StateMachine::fromConfig(config('state-machines.order_import_lane'));
    $result = $machine->apply($from, $event);

    $to === null
        ? $this->assertFalse($result->allowed, "{$from}+{$event} harus ditolak")
        : $this->assertSame($to, $result->to);
}

public static function transitions(): array
{
    return [
        ['pending_payment', 'payment_captured', 'awaiting_batch'],
        ['pending_payment', 'intent_expired', 'expired'],
        ['awaiting_batch', 'batch_arrived', 'ready_to_ship'],
        ['awaiting_batch', 'balance_overdue', 'on_hold'],
        ['on_hold', 'payment_captured', 'ready_to_ship'],
        ['shipped', 'payment_captured', null],   // tidak boleh mundur
        ['completed', 'cancelled', null],
    ];
}
```

- [ ] **Step 2: Implementasi runner**

```php
<?php
namespace App\Platform\StateMachine;

final class StateMachine
{
    /** @param array<string, array<string, array{to: string, guards?: list<string>, actions?: list<string>}>> $map */
    private function __construct(private readonly array $map, private readonly array $guards, private readonly array $actions) {}

    /** @param array{transitions: array, guards?: array, actions?: array} $config */
    public static function fromConfig(array $config): self
    {
        return new self($config['transitions'], $config['guards'] ?? [], $config['actions'] ?? []);
    }

    public function apply(string $from, string $event, array $context = []): TransitionResult
    {
        $edge = $this->map[$from][$event] ?? null;
        if ($edge === null) {
            return TransitionResult::rejected($from, $event);
        }
        foreach ($edge['guards'] ?? [] as $name) {
            if (!($this->guards[$name])($context)) {
                return TransitionResult::blocked($from, $event, $name);
            }
        }
        foreach ($edge['actions'] ?? [] as $name) {
            ($this->actions[$name])($context);
        }
        return TransitionResult::moved($from, $edge['to'], $event);
    }
}
```

- [ ] **Step 3: `config/state-machines.php` berisi empat lane (rts/import_po/request/procurement)**

Nilai `to` ditulis persis mengikuti diagram Doc 01 §6; guard/action direferensikan sebagai nama string yang di-resolve lewat container saat Sprint 2/3 (Sprint 0 hanya menguji jalur tanpa guard).

- [ ] **Step 4: Hijaukan & commit**

```bash
git add app/Platform/StateMachine config/state-machines.php tests/Unit/Platform
git commit -m "feat(platform): state machine runner deklaratif + empat lane terdefinisi config"
```

---

## Task 10 — Test arsitektur B1–B6 (Doc 01 §3)

Guard yang mencegah dua developer merusak batas modul sambil mengerjakan fitur berbeda.

**Files:** Create `tests/Architecture/BoundaryTest.php`

- [ ] **Step 1: Test (a) tidak ada relasi Eloquent lintas modul (B1/S-10), (b) tidak ada kelas modul mengimpor `Infrastructure` modul lain (B1/B6)**

```php
<?php
namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class BoundaryTest extends TestCase
{
    private const RELATIONS = ['belongsTo', 'hasOne', 'hasMany', 'morphOne', 'morphMany', 'morphTo'];

    #[Test]
    public function no_eloquent_relation_crosses_module_boundary(): void
    {
        $violations = [];

        foreach (glob(base_path('app/Modules/*/Infrastructure/Models/*.php')) ?: [] as $file) {
            $source = file_get_contents($file);
            $owner = explode('/', str_replace('\\', '/', $file))[4]; // app/Modules/<Owner>/…
            $uses = $this->useMap($source);

            foreach (self::RELATIONS as $relation) {
                preg_match_all(
                    '/\$this->'.$relation.'\(\s*([A-Za-z0-9_\\\\]+)::class/',
                    $source,
                    $matches,
                    PREG_SET_ORDER,
                );
                foreach ($matches as [, $alias]) {
                    $fqcn = $uses[$alias] ?? null;
                    if ($fqcn === null) {
                        continue; // morphTo tanpa target eksplisit = relasi polimorfik internal
                    }
                    if (!str_starts_with($fqcn, 'App\\Modules\\'.$owner.'\\')) {
                        $violations[] = basename($file)." -> {$relation}() {$fqcn}";
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "B1/S-10 dilanggar: relasi Eloquent menulis tabel modul lain.\n".implode("\n", $violations),
        );
    }

    /** Peta alias use di awal file: ['User' => 'App\Modules\Identity\…\User', …] */
    private function useMap(string $source): array
    {
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?;/m', $source, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as [, $fqcn, $alias]) {
            $short = $alias ?: substr(strrchr('\\'.$fqcn, '\\'), 1);
            $out[$short] = $fqcn;
        }
        return $out;
    }
}
```
Cara membacanya: untuk setiap model modul, setiap deklarasi `$this->hasMany(Varian::class, …)` diselesaikan aliasnya lewat statement `use` di file yang sama; kalau targetnya kelas di modul lain, itu pelanggaran B1 dan test gagal dengan daftar `file -> relation()FQCN`. Nama modul diambil dari segmen path keempat (`app/Modules/<Owner>/…`) sehingga pemilik relasi diketahui tanpa refleksi. Relasi `morphTo` tanpa target eksplisit sengaja diabaikan — itu relasi polimorfik internal, bukan penulis lintas modul.

- [ ] **Step 2: Test (c) tidak ada `UPDATE`/`DELETE` di tabel append-only dari kode modul (Doc 10 §5.6, Doc 09 S-6)** — regex atas `app/Modules/*/` untuk `->where(...)->update(` dan `->delete()` pada `OutboxMessage`, `PaymentLedger`, `OrderEvent`, `StockMovement`, `AuditLog`, `TrackingEvent`. Yang sah hanya soft delete pada `users/products/pages/content_block_versions/vouchers` (S-7).

- [ ] **Step 3: Test (d) mutasi hanya lewat Command (B2)** — assert tidak ada kelas di `app/Modules/*/Application/` selain `*Command.php` yang memanggil `::create(`, `->save(`, `->update(` langsung di luar aggregate, dengan whitelist eksplisit di file test.

- [ ] **Step 4: Hijaukan & commit**

```bash
git add tests/Architecture
git commit -m "test(architecture): jaga batas B1-B6 dan append-only di level kode"
```

---

## Task 11 — Fitur Identity: register, login, reset, RBAC, 2FA (Doc 03 task 0.6)

**Files:** Create di `app/Modules/Identity/`: `Domain/`, `Application/Commands/{RegisterCustomer,LoginWithPassword,RequestPasswordReset,ResetPassword,EnableStaffTwoFactor,VerifyStaffTwoFactor}.php`, `Application/Queries/`, `Infrastructure/Models/*`, `Infrastructure/Http/Controllers/`, `Contracts/`; routes modul; `tests/Feature/Identity/*`

- [ ] **Step 1: Test gagal untuk lima perilaku berisiko**

```php
#[Test] public function customer_cannot_be_created_with_duplicate_email_case_insensitively(): void
#[Test] public function staff_login_requires_second_factor_when_enabled(): void
#[Test] public function recovery_code_works_once_and_is_consumed(): void
#[Test] public function password_reset_token_is_single_use_and_hashed_in_db(): void
#[Test] public function guest_cannot_reach_admin_route(): void   // ← temuan Doc 08 §2 yang tidak kita tiru
```

`guest_cannot_reach_admin_route` wajib ada di Sprint 0: situs referensi mengembalikan **HTTP 200 ke guest** untuk semua shell (Doc 08 §2); kita menolak itu dan menjaganya dengan test, bukan dengan menyembunyikan menu.

- [ ] **Step 2: Model + Commands sesuai B2; tidak ada Controller yang menulis Eloquent langsung**

`User` (fillable tanpa `password_hash` — setter eksplisit `hashPassword()`), `Role::admin/ops/warehouse/finance/support`, `Permission` per `modul.aksi` (Doc 06), policy `staff_only`, throttle `RateLimiter::for('login')`.

- [ ] **Step 3: 2FA (D-S0-4)**

```php
$secret = (new Google2FA)->generateSecretKey(32);
// simpan encrypted di staff_2fa_secrets.secret_encrypted; recovery_code[] di-hash bcrypt
$uri = (new Google2FA)->getQRCodeUrl(config('brand.name'), $user->email, $secret);
// provisioning image dibuat bacon-qr-code di controller, tidak pernah disimpan sebagai file user
```
Validasi `verifyKey` dengan window 1 + `useRecoveryCode()` yang menghapus satu kode dari array (single-use).

- [ ] **Step 4: Route web + Inertia pages minimal** (`resources/js/Pages/Auth/{Login,Register,ForgotPassword,ResetPassword,TwoFactor}.tsx`) — form memakai `useForm` Inertia; tidak ada komponen brand-specific di sini (Task 13).

- [ ] **Step 5: Hijaukan, snapshot skema (Task 5 C6), commit**

```bash
docker compose run --rm app php artisan test
docker compose run --rm app php tests/Schema/guard.php snapshot
git add -A
git commit -m "feat(identity): register/login/reset + rbac staff + 2fa dengan recovery code"
```

---

## Task 12 — Seeder Reference vs Demo (Doc 03 task 0.8, Doc 10 §8)

**Files:** Create `database/seeders/{ReferenceSeeder,DemoSeeder}.php`, `database/seeders/Reference/{RolesAndPermissions,FeatureFlags,Settings}Seeder.php`, `database/seeders/Demo/{Catalog,Batches,Quote}Seeder.php`, `database/seeders/Factories/*`, test C7

- [ ] **Step 1: Test idempoten lebih dulu (C7)** — jalankan `ReferenceSeeder` dua kali, assert jumlah baris tiap tabel referensi identik dan nilai yang diubah admin tidak tertimpa.

- [ ] **Step 2: Reference seeder = upsert pada kunci alamiah**

```php
Role::upsert(['name'], [['name' => 'Admin'], ['name' => 'Ops'], ['name' => 'Warehouse'], ['name' => 'Finance'], ['name' => 'Support']]);
Setting::upsert(['group','key'], [
  ['group' => 'po', 'key' => 'deposit_mode', 'value' => 'percent', 'cast' => 'string'],
  ['group' => 'po', 'key' => 'deposit_percent', 'value' => '30', 'cast' => 'int'],
  ['group' => 'po', 'key' => 'balance_reminder_days', 'value' => '[7,1]', 'cast' => 'json'],
]);
FeatureFlag::upsert(['code'], [['code' => 'procurement_lane', 'enabled' => false, 'description' => 'B-PO-9']], ['enabled']);
```
`courier_services` & tarif: **tidak disemen di produksi** dengan nilai yang bisa dipakai checkout (Doc 10 §8) — placeholder yang ditolak validator.

- [ ] **Step 3: Demo seeder 10 produk fiktif + 2 batch + 1 kuotasi**

Semua nama karakter/IP dibuat sendiri, gambar dari `assets/demo/*.svg` orisinal, harga integer rupiah realistis. Tidak ada satu pun string/foto dari situs referensi.

- [ ] **Step 4: Entri composer/Makefile** — `composer db:fresh` = `migrate:fresh` + `db:seed --class=ReferenceSeeder`; `composer db:demo` = tambah `db:seed --class=DemoSeeder` (dev/staging saja). Dua-duanya membungkus `@dev:run`, bukan memanggil artisan di host.

- [ ] **Step 5: Hijaukan & commit**

```bash
git add database .github
git commit -m "feat(seeds): reference seeder idempoten + demo katalog fiktif orisinal"
```

---

## Task 13 — Design token 3 lapis & komponen dasar (Doc 03 task 0.5, Doc 04)

**Files:** Create `resources/css/themes/brand.tokens.css`, `resources/css/themes/presets/{playful,clean-premium,bold-kpop}.css`, `resources/css/utilities.css`, `resources/js/lib/{tokens.ts,formatting.ts}`, `resources/js/Components/ui/{Button,Input,Card,Badge,Modal,Toast,Grid}.tsx`, `resources/js/Components/ui/index.ts`, `tests/Feature/Theme/BrandSwapTest.php`

- [ ] **Step 1: Test "ganti brand tanpa menyentuh komponen"**

```php
#[Test]
public function swapping_brand_config_changes_tokens_without_touching_components(): void
{
    foreach (['playful', 'clean-premium', 'bold-kpop'] as $theme) {
        config(['brand.theme' => $theme]);

        // preset harus menyelesaikan satu berkas token, tanpa salinan ganda di app.css
        $preset = resource_path("css/themes/presets/{$theme}.css");
        $this->assertFileExists($preset);
        $css = file_get_contents($preset);
        $this->assertMatchesRegularExpression('/--color-brand:\s*var\(--brand-/', $css);

        // dan brand.tokens.css wajib menunjuk preset yang sedang aktif
        $this->assertStringContainsString(
            "@import './presets/{$theme}.css';",
            file_get_contents(resource_path('css/themes/brand.tokens.css')),
        );
    }

    // komponen tidak boleh tahu brand: tanpa hex literal, tanpa nama toko di className/label
    $needle = strtolower(config('brand.name'));
    foreach (glob(resource_path('js/Components/ui/*.tsx')) ?: [] as $file) {
        $source = strtolower(file_get_contents($file));
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-f]{3,8}\b/', $source, basename($file));
        $this->assertStringNotContainsString($needle, $source, basename($file).' menyebut nama brand');
    }
}
```
Perhatikan dua arah bukti: test ini membaca berkas CSS dari disk (bukan hasil render browser), jadi ia menjaga struktur token; dan ia membaca `brand.name` dari config lalu mencari string itu di komponen, jadi ia menjaga "komponen tidak tahu brand". Verifikasi visual kontras AA tetap butuh browser — itu Step 4, tidak bisa digantikan test ini.

- [ ] **Step 2: Token Lapis 1 (primitive) & Lapis 2 (semantik) sesuai Doc 04 §2**

`brand.tokens.css` memakai `@theme` dengan hanya menyebut semantik; preset mengisi primitif (`--neutral-*`, `--brand-*`, `--coral-*`, `--violet-*`, `--green-*`, `--red-*`) dan semantik menunjuknya.

- [ ] **Step 3: Komponen dasar dengan varian yang menunjuk token saja**

```tsx
// resources/js/Components/ui/Button.tsx
type Variant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'po';
const classes: Record<Variant, string> = {
  primary:   'bg-brand text-white hover:brightness-110',
  secondary: 'bg-surface-muted text-ink border border-divider',
  ghost:     'text-ink hover:bg-surface-muted',
  danger:    'bg-danger text-white',
  po:        'bg-po text-white',
};
export function Button({ variant = 'primary', size = 'md', className = '', ...rest }: Props) {
  return <button className={cn(classes[variant], sizes[size], 'rounded-[--radius-pill] h-[--button-height] focus-visible:ring-2 ring-[--focus-ring]', className)} {...rest} />;
}
```
Tujuh komponen ini saja cakupan Sprint 0 (Doc 03 task 0.5); `shop/*` dan `admin/*` menyusul saat halamannya dibuat.

- [ ] **Step 4: Aset & kontras** — `public/brand/{logo.svg,logo-mark.svg,favicon set,og-default.png}` orisinal; periksa kontras AA untuk `ink`/`surface` tiap preset dengan skrip kecil di test Step 1 (atau catatan manual di PR bila alat belum ada — jangan klaim lulus tanpa bukti).

- [ ] **Step 5: Hijaukan & commit**

```bash
git add resources public/brand tests/Feature/Theme
git commit -m "feat(theme): token 3 lapis + 3 preset + komponen ui dasar bebas brand"
```

---

## Task 14 — Baseline keamanan edge + CSP (Doc 03 task 0.10, Doc 08 §6)

**Files:** Create `app/Http/Middleware/SecurityHeaders.php`, `config/security.php`, `tests/Feature/Security/EdgeHeadersTest.php`

- [ ] **Step 1: Test header & CSP**

```php
#[Test]
public function baseline_headers_are_present_on_every_response(): void
{
    $h = $this->get('/')->headers->all();
    foreach (['strict-transport-security', 'x-content-type-options', 'x-frame-options', 'referrer-policy', 'permissions-policy', 'content-security-policy'] as $name) {
        $this->assertArrayHasKey($name, $h, "header {$name} hilang");
    }
    $this->assertStringContainsString("script-src 'self'", $h['content-security-policy'][0]);
}

#[Test]
public function csp_does_not_break_inertia(): void
{
    // Inertia memasang <script type="module" src="/build/...">; CSP harus mengizinkan
    // self + inline hanya bila Vite memakai nonce, dan tidak memblokir worker/font/gambar.
    $this->withoutMiddleware(); // tidak relevan — assert dari respons nyata
    $html = $this->get('/')->content();
    $this->assertStringNotContainsString('Refused to execute', $html);
}
```
Untuk test kedua, yang membuktikan adalah **browser**: jalankan `npm run build` lalu muat halaman lewat Playwright/Chrome MCP dan pastikan console bersih dari pelanggaran CSP. Bila browser tidak tersedia di mesin ini, tandai verifikasi itu "belum dilakukan" di PR — jangan tulis "lulus" (Doc 03 §0 DoD).

- [ ] **Step 2: Middleware**

```php
<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class SecurityHeaders
{
    public function handle(Request $req, Closure $next)
    {
        $res = $next($req);
        foreach (config('security.headers') as $name => $value) {
            if ($value !== null) { $res->headers->set($name, $value); }
        }
        $res->headers->set('Content-Security-Policy', $this->csp($req));
        return $res;
    }

    private function csp(Request $req): string
    {
        $src = config('security.csp');
        $parts = [];
        foreach ($src as $directive => $sources) {
            $parts[] = $directive.' '.implode(' ', $sources);
        }
        $parts[] = "frame-ancestors ".$src['frame-ancestors'][0];
        return implode('; ', $parts).'; report-uri '.route('csp.report');
    }
}
```
`config/security.php` menyimpan baseline dari Doc 08 §6 (HSTS `max-age=31536000; includeSubDomains`, `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` tanpa kamera/mikrofon/geolokasi) plus `script-src 'self'` dan `connect-src` yang mengizinkan gateway saat aktif (ditambah saat Sprint 2, bukan di-tebak sekarang).

- [ ] **Step 3: Proteksi admin server-side (bukan sekadar menyembunyikan menu)**

`Route::middleware(['auth','staff'])->prefix('admin')` + `abort_unless($user->isStaff(), 403)`; test Step 1 Task 11 membuktikan guest tidak dapat 200 untuk shell admin.

- [ ] **Step 4: Hijaukan & commit**

```bash
git add app/Http/Middleware config/security.php tests/Feature/Security
git commit -m "feat(security): baseline header edge + CSP script-src 'self' + gerbang admin server-side"
```

---

## Task 15 — CD staging: skrip deploy, rollback by tag, restore drill (Doc 03 task 0.4)

**Files:** Create `deploy/bin/deploy.sh`, `deploy/bin/restore-drill.sh`, `deploy/Makefile`, `deploy/runbook/{deploy.md,backup-restore.md,incident.md}`, `docker/compose.prod.yaml`

- [ ] **Step 1: Skrip deploy dengan urutan yang sudah diputuskan Doc 01 §10.5**

```bash
#!/usr/bin/env bash
# deploy/bin/deploy.sh <git-sha> [--dry-run]
set -euo pipefail
SHA="${1:?git sha required}"
MODE="${2:-}"                      # "dry-run" = cetak langkah saja, tidak menyentuh apa pun

run() {
  if [ "$MODE" = "dry-run" ]; then echo "==> (dry-run) $*"; return 0; fi
  echo "==> $*"
  "$@"
}

run php artisan down --render=maintenance --secret="${MAINT_TOKEN}"
run php artisan horizon:pause
run pg_dump -Fc "${DB_NAME}" > "/backups/pre-${SHA}.dump"
run pg_restore --list "/backups/pre-${SHA}.dump" > /dev/null
run docker compose -f docker/compose.yaml -f docker/compose.prod.yaml pull
run docker compose run --rm app php artisan migrate --force
run docker compose -f docker/compose.yaml -f docker/compose.prod.yaml up -d
run curl -fsS localhost:8080 > /dev/null
run curl -fsS localhost:8080/login > /dev/null
run php artisan up

if [ "$MODE" = "dry-run" ]; then
  echo "dry-run selesai: ${MODE} — tidak ada image di-pull, tidak ada SSH dijalankan, tidak ada DB disentuh."
fi
```
Empat langkah pertama (`down`, `horizon:pause`, `pg_dump`, `pg_restore --list`) adalah urutan wajib Doc 01 §10.5: maintenance → drain → backup → verifikasi backup terbaca. Backup disimpan **sebelum** `migrate` dan hanya dihapus setelah 7 hari (retensi di `deploy/bin/rotate-backups.sh`, Task 15 Step 2). `MAINT_TOKEN` dipakai ulang oleh visitor maintenance mode, jadi ia tidak pernah dicetak.

- [ ] **Step 2: Runbook + rollback**

`deploy/runbook/deploy.md` mencatat satu kalimat rollback per rilis (Doc 10 §11 butir 10). `restore-drill.sh` menguji `pg_dump` → restore ke database sementara → `migrate` → `guard.php check` → hapus. Jalankan drill sekali di container lokal, catat tanggal & hasilnya di runbook; dokumentasi tanpa bukti drill = task belum selesai.

- [ ] **Step 3: `compose.prod.yaml`** — `restart: unless-stopped`, `deploy.resources.limits` untuk `worker`, profil dev tools absen, healthcheck tiap service, `depends_on: condition: service_healthy`, dan tidak ada `ports:` untuk postgres/redis/meilisearch/minio.

- [ ] **Step 4: Verifikasi parsial yang jujur (D-S0-8)**

Run: `bash deploy/bin/deploy.sh HEAD --dry-run`
Expected: seluruh langkah tercetak, tidak ada perintah yang menyentuh registry/SSH/VPS.
Bila SSH key, registry, dan VPS dari klien belum ada → **tandai task ini `blocked-on-client`** di Doc 03 §9 dan lanjut; deploy live pertama masuk Sprint 1, bukan jadi alasan membuka `--force` ke infrastruktur yang belum disetujui.

- [ ] **Step 5: Commit**

```bash
git add deploy docker/compose.prod.yaml
git commit -m "feat(cd): skrip deploy expand-only + runbook + restore drill terverifikasi"
```

---

## Penutup plan — apa yang membuktikan sprint ini selesai

1. `docker compose up` + `composer ci:*` dari clone baru → hijau, < 4 menit lokal (Spec §6).
2. `docs/schema.snapshot.sql` cocok dengan DB nyata (C6) dan Doc 09/10 sudah disamakan lewat Task 6 Step 5.
3. `guest_cannot_reach_admin_route` hijau — penyimpangan dari situs referensi yang kita sengaja buat (Doc 08 §2).
4. Tidak ada file konfigurasi yang diedit dua orang pada sprint yang sama (D-S0-1, loader glob Task 4).
5. Restore drill punya tanggal dan hasil, bukan niat (Task 15 Step 2).

Urutan eksekusi plan = urutan task. Task 15 adalah satu-satunya yang bisa `blocked-on-client`; sisanya terverifikasi penuh di mesin ini.
