# Spec Sprint 0 — Fondasi, DevOps, design system

Target: minggu 1–2 · Sumber task: Doc 03 §1 · Skema & urutan migrasi: Doc 09 + Doc 10 §4 (rentang `0001–0199`)
Status spec: **draf untuk persetujuan** — tanda "selesai" di bawah berarti bisa didemokan di staging, bukan "lanjut desain".

## 1. Tujuan sprint

Keluar dari sprint ini bukan fitur yang dijual ke customer, tapi kondisi bahwa **sprint 1–7 bisa dikerjakan oleh dua orang tanpa saling menimpa**: satu perintah menghidupkan semuanya, satu pipeline menolak kode rusak, skema dasar berdiri dengan penjagaan urutan, dan komponen UI bertema token sudah ada untuk dipakai sprint berikutnya.

Non-goal sprint ini: katalog, cart, payment, tracking, laporan apa pun. Bila muncul permintaan "tambah layar produk", itu Sprint 1.

## 2. Acceptance criteria per task

| ID | Task | Selesai bila |
|---|---|---|
| 0.1 | Repo Laravel 12 + Inertia + React + TS + Tailwind v4, struktur `app/Modules/<X>/{Domain,Application,Infrastructure,Contracts,Tests}` | `composer create-project` di branch bersih; loader modul mendaftarkan path view/migration/route lewat **glob** (Doc 10 M-2) tanpa file daftar manual; `php artisan route:list` kosong tapi hidup; 1 modul contoh (`Identity`) terisi kerangka |
| 0.2 | Docker dev: app, nginx, postgres 16, redis 7, meilisearch, minio, mailpit, adminer | `docker compose up` dari repo kosong → `https://localhost` memuat halaman Inertia; tidak ada langkah manual selain `make up`. Semua perintah php berjalan **di dalam container** (lihat §4) |
| 0.3 | CI: lint, phpstan/larastan level max, unit+feature (Postgres service), build image, cek skema C1–C8 Doc 10 §10 | PR yang melanggar salah satunya di-blokir; pipeline < 8 menit; workflow jalan di runner Linux sehingga CRLF tidak pernah jadi penyebab gagal |
| 0.4 | CD staging: build+push registry, deploy SSH compose, maintenance mode, rollback by tag | Satu perintah dari tag → staging hidup; rollback = deploy ulang tag sebelumnya tanpa `migrate:rollback`; langkah restore `pg_dump` **dijalankan nyata sekali** dan hasilnya dicatat |
| 0.5 | Design token 3 lapis + 3 preset tema + komponen dasar (button/input/card/badge/modal/toast/grid) | Mengganti brand = mengubah `config/brand.php` + satu token file, tanpa menyentuh komponen; Doc 04 §checklist diikuti dan semua butirnya lulus; kontras AA untuk teks biasa |
| 0.6 | Identity: register, login session, reset password, role+permission staff, 2FA admin | Migrasi `0001–0199` §5 ada; login staff vs customer dipisah `users.kind`; 2FA TOTP wajib untuk role Admin dengan recovery code; percobaan login dibatasi; tidak ada kredensial di repo |
| 0.7 | Konvensi lintas modul: domain exception, `ApiProblem`, state-machine runner deklaratif, transactional outbox + dispatcher, bus event | `outbox_events` (Doc 10 Appendix A L0) ditulis dalam transaksi yang sama lalu dikirim dispatcher dengan retry + lock; test membuktikan event tidak hilang saat worker mati; B1–B6 punya test architecture (namespace/dependency) yang menolak relasi Eloquent lintas modul |
| 0.8 | Seeder: **Reference** (idempoten, wajib prod) vs **Demo** (dev/staging) — Doc 10 §8 | `composer db:fresh` = `migrate:fresh` + `db:seed --class=ReferenceSeeder`; seeder reference dijalankan dua kali → nol perubahan (C7 hijau); Demo berisi 10 produk fiktif + 2 batch + 1 kuotasi, semua gambar SVG orisinal |
| 0.10 | Baseline keamanan edge: HSTS, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, CSP dengan `script-src 'self'` | Header diuji otomatis lewat feature test; CSP **tidak** melarang Inertia; halaman admin dilindungi server-side (bukan hanya menu tersembunyi) — temuan Doc 08 §2 yang tidak kita tiru |
| 0.9 | (paralel, milik klien) gateway pertama, kurir, B-PO-1…14, aset brand, penanggung jawab konten | Blocker **Sprint 2**, bukan Sprint 0. Keputusannya tercatat di Doc 02 §2.2/§7, bukan tersirat dari kode |

## 3. Definition of Done sprint

1. Semua acceptance §2 hijau; `make ci` locally = hasil CI.
2. Onboarding developer baru: clone → `make up` → `make test` < 20 menit, tanpa tanya orang.
3. Doc 09 dan Appendix A Doc 10 cocok dengan DB nyata (C6 hijau dengan snapshot ter-commit).
4. Tidak ada aset/teks/konten situs referensi di repo; tidak ada kredensial di repo; `README` menyebut cara reset data.
5. Catatan risiko Sprint 0 yang belum selesai dibawa ke Doc 03 §10, tidak hilang dari pembicaraan.

## 4. Deviasi lingkungan yang harus diputuskan sebelum task 0.1

Ditemukan saat menyiapkan mesin kerja (2026-11-17), dan masing-masing mengubah cara sprint ini dikerjakan:

| Fakta lokal | Dampak | Keputusan yang diusulkan |
|---|---|---|
| PHP lokal **8.3.30**; target arsitektur **8.4** | kode yang diuji di 8.3 bisa lolos padahal 8.4 berubah, dan sebaliknya | **semua perintah PHP berjalan di container 8.4**; PHP host hanya untuk IDE. `composer.json` mengunci `"platform": {"php": "8.4.0"}` supaya `composer install` di host gagal dengan pesan jelas, bukan diam-diam |
| Postgres lokal **15.10**; target **16** | fitur 16 (mis. perilaku replikasi/partisi) tidak teruji | test hanya lewat container 16; `psql` host dipakai sebagai client, bukan server |
| Ekstensi `phpredis` tidak terpasang di host, `make` tidak ada | cache/queue driver `redis` tidak bisa diuji dari host; Makefile tidak bisa jadi satu-satunya entry di Windows | driver `redis` hanya dipakai di container; jalur resmi semua perintah adalah `composer dev:*`/`composer ci:*` script, Makefile hanya pembungkus tipis untuk Linux/macOS |
| Docker Desktop daemon mati saat mulai mesin kerja | task 0.2 tidak bisa diverifikasi sebelum daemon hidup | sudah diperbaiki saat persiapan (daemon hidup, `docker info` 29.6.2) — masuk sebagai prasyarat, bukan asumsi |

Bila salah satu keputusan di atas tidak disetujui, bilang sekarang — menggantinya setelah task 0.3 berjalan berarti menulis ulang CI dan semua script.

## 5. Yang dikerjakan duluan (urutan intra-sprint)

`persiapan lingkungan` → 0.1 → 0.2 → 0.3 → **0.7** → 0.6 → 0.8 → 0.5 → 0.10 → 0.4

Dasar urutan: 0.7 (outbox + state machine + konvensi) dipakai oleh 0.6 dan oleh semua sprint sesudahnya; 0.3 harus ada sebelum 0.7 supaya C1–C8 langsung menjaga skema sejak hari pertama; 0.5 dan 0.10 bisa dikerjakan orang kedua paralel sejak 0.1 selesai karena tidak berbagi file dengan jalur backend.

## 6. Ukuran hasil (supaya bisa dinilai, bukan dirasakan)

- `docker compose up` → halaman pertama **< 60 s** pada cold start.
- `make test` penuh **< 4 menit** lokal, CI **< 8 menit**.
- Tidak ada satu pun file konfigurasi yang harus diedit dua orang pada sprint yang sama (dibuktikan §5.1 Doc 10 + M-2).
