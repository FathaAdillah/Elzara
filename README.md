# Elzara Commerce Platform

Tiga aplikasi dalam satu repo, **tiga deploy terpisah**, satu skema database.

```
apps/api          Laravel modular  — satu-satunya pemilik tulis atas data
apps/admin        Next.js (App Router) — dashboard operasional (guard admin + RBAC granular)
apps/web          Next.js (App Router) — storefront publik
packages/         api-contracts (OpenAPI 3.1 + klien TS ter-generate), shared-types, ui
docker/           compose.yaml + profil dev/staging/prod + nginx edge + init MinIO
scripts/          pembungkus perintah yang dijaga CI
infrastructure/   artefak produksi yang dirender (nginx, monitoring)
docs/             arsitektur, skema, kontrak API, ADR, runbook, threat model
.qoder/           skills, agents, dan plan development yang dieksekusi
```

**Baca dulu:** [docs/00-overview.md](docs/00-overview.md) (peta dokumen) lalu
[docs/01-architecture.md](docs/01-architecture.md) §3.1 (layout + aturan B1–B6).
Blueprint `SNOWEGO_MICROSERVICES_BLUEPRINT.md` adalah rujukan induk; setiap deviasi
kita daftarkan di [docs/11-blueprint-reconciliation.md](docs/11-blueprint-reconciliation.md),
bukan dibiarkan hidup diam-diam di dokumen lain.

Elasticsearch self-managed Basic tersedia sebagai service internal opsional melalui profil Compose `search`. PostgreSQL menyimpan katalog dan stok otoritatif. Lihat [topologi layanan](docs/01-architecture.md#111-layanan-per-aplikasi).

## Mulai

```bash
cp docker/.env.example docker/.env      # isi nilai yang ditandai <?...>
scripts/install-deps.sh                 # dependensi PHP di container php:8.4 + ketiga lockfile
scripts/dev.sh                          # compose up profil dev + init 6 bucket MinIO
```

Instalasi dependensi **tidak pernah** berjalan di host: `composer install` dan
`npm ci` hidup di image `php:8.4` / `node:22` (Doc 01 §11.2). Ini syarat resolve, bukan
preferensi: `spatie/laravel-activitylog` 5.1.5 menuntut php ^8.4 dan `phpunit` 13.4.7
php >=8.4.1, sementara host bisa berjalan PHP 8.3. Lockfile dihasilkan di container
yang sama (`scripts/gen-lockfiles.sh`), bukan dengan Node versi host.

## Yang dijaga mesin, bukan dijanjikan manusia

- Kontrak: `packages/api-contracts/openapi.yaml` adalah satu sumber kebenaran; klien
  TypeScript di `generated/` tidak pernah disunting tangan (ADR-010 K-3, D-S0-5).
  Gerbang: `scripts/check-contract-drift.sh`.
- Batas modul: architecture test (Deptrac) menolak import antar modul yang tidak lewat
  `Contracts/`, dan menolak controller yang memakai `Infrastructure/` modul lain.
- Konsistensi dokumen: `scripts/verify-docs.sh` menjalankan enam auditor yang terdaftar
  di [docs/11-blueprint-reconciliation.md](docs/11-blueprint-reconciliation.md) §8.8
  (gate A–I; angka tabel diproduksi parser, bukan ditulis tangan).
- Identitas & auth: satu tabel `users`; `is_operator` gerbang login admin, `is_admin`
  bypass RBAC admin yang **wajib** menulis `audit_logs`. Register mengikatnya
  [docs/11-blueprint-reconciliation.md](docs/11-blueprint-reconciliation.md) §4.4,
  alasannya [docs/adr/ADR-012-identitas-rbac-dependensi.md](docs/adr/ADR-012-identitas-rbac-dependensi.md).

## Status

Sprint 0 (fondasi): **scaffold sudah ada di disk** (22 modul `apps/api`, dua app Next.js,
`packages/`, `docker/`, `scripts/`, workflow CI), tetapi **dependensi belum dipasang,
lockfile belum ada, test belum dijalankan, dan belum ada commit**. Plan eksekusi:
[.qoder/plans/2026-11-17-sprint-0-fondasi.md](.qoder/plans/2026-11-17-sprint-0-fondasi.md)
(20 task, sedang direkonsiliasi dengan scaffold) dan gelombang auth/RBAC 2026-11-18 di
`.qoder/plans/2026-11-18-*.md`. Urutan agen mengikat: migrasi DB → fitur → testing →
rebuild docker (`.qoder/agents/`). Keputusan yang masih menunggu jawaban klien tercatat
sebagai Q-1…Q-8 (Doc 11 §6); jadwal penuh cakupan Blueprint ±22–26 minggu, bukan 18.
