# ADR-010 — Monorepo, pipeline CI/CD per aplikasi, deploy mandiri, dan strategi migrasi basis data

Tanggal: 2026-11-17 · Status: **Accepted** — dengan adopsi **Doc 10 §1 M-1…M-4 sebagai Lampiran A** di bagian bawah · Menyelesaikan Blueprint §18 butir 9 (Doc 11 §3: "sebagian — Doc 10; ADR-010, Doc 10 §1 M-1…M-4 jadi lampirannya") · Rujukan induk: Blueprint §3.1, §14.3, §7 (ADR-003)

## Konteks

Blueprint §3.1 merekomendasikan **monorepo untuk tim kecil, dengan deploy tetap terpisah**, dan §14.3 menetapkan urutan pipeline: lint → unit → integration → security scans → build images → migration compatibility checks → deploy → smoke tests → rollback. Doc 10 §1 sudah memuat aturan anti-tabrakan dan strategi migrasi kita (M-1…M-13) yang **tidak dibatalkan** Blueprint — justru §14.3 meminta expand/contract yang sama (Doc 11 §2, butir M-1…M-13; K-5 ADR-003).

Yang belum pernah diputuskan di mana pun adalah bentuk **pipeline-nya**, dan itu menjadi pertanyaan nyata begitu B-1/B-2 berlaku: tiga aplikasi dalam satu repo, satu paket kontrak (`packages/api-contracts`) yang dikonsumsi kedua frontend, dan satu basis data yang hanya boleh berubah lewat satu jalur. Tiga bahaya yang harus dicegah, bukan dijanjikan: (a) satu pipeline raksasa yang me-redeploy semuanya termasuk yang tidak berubah; (b) kontrak API dan kode controller yang bergerak terpisah sehingga klien hasil generate salah; (c) dua developer menulis migrasi dengan basename sama sehingga satu saling menimpa secara senyap (M-1).

## Keputusan

**K-1. Satu repo, tiga artefak yang di-deploy mandiri.** Susunan direktori mengikuti Blueprint §3.1: `apps/api` (Laravel), `apps/admin`, `apps/web` (Next.js), `packages/api-contracts` (OpenAPI 3.1 + klien TypeScript hasil generate), `packages/shared-types`, `infrastructure/`, `docs/`, `.github/workflows/`. Satu repo bukan satu build: setiap app punya image sendiri, Dockerfile sendiri, dan siklus rilis sendiri.

**K-2. Pipeline per aplikasi dengan pemicu berbasis path.** Tiga pipeline (api, admin, web) + satu pipeline bersama untuk kontrak/infra. Perubahan di `apps/admin/**` tidak boleh memicu build atau deploy `web`. Konsekuensi yang harus diterima: setiap pipeline wajib men-deklarasikan pola path-nya dengan benar, karena **kesalahan filter path menghasilkan deploy yang diam-diam tidak terjadi** — itu kegagalan yang lebih buruk daripada gagal berisik.

**K-3. Gerbang kontrak: perubahan controller harus membawa perubahan OpenAPI.** Sebuah pull request yang menyentuh route, controller, request validation, atau DTO yang keluar ke klien **harus** menyertakan diff `packages/api-contracts/openapi.yaml` dan klien TypeScript hasil generate yang konsisten; kalau tidak, CI menolak merge. Ini penerjemahan langsung dari Blueprint §7 ("OpenAPI 3.1 sebagai source of truth") dan B-2; tanpa gerbang ini, sumber kebenaran berpindah diam-diam ke kode.

**K-4. Stage wajib, urut, dan tidak dapat dilewati untuk API.** lint (PSR + PHPStan + Deptrac/architecture test untuk aturan B1–B6) → unit → integration terhadap **PostgreSQL dan Redis nyata** (Testcontainers/service container; Blueprint §16 menutup alasan SQLite: ia tidak memvalidasi locking Postgres — ini prasyarat teknis bagi ADR-005) → security scan (dependency + secret) → build image → **migration compatibility check** → deploy → smoke test → rollback tersedia. Suite konkurensi (1 SKU/1.000 paralel; kuota 50/500 paralel) ikut sebagai bagian integration, bukan pekerjaan manual menjelang rilis.

**K-5. Satu jalur migrasi global untuk seluruh repo.** Tidak ada "migrasi milik app X" yang dijalankan terpisah; satu `migrate` menjalankan satu daftar terurut. Detail pengikatnya adalah **M-1…M-4** (Lampiran A): file tetap milik modul, loader memakai glob (tidak ada berkas daftar bersama yang memicu konflik), format basename `NNNN_<module>_<slug>.php`, dan migrasi yang sudah di-merge dibekukan — koreksi selalu berupa file baru. CI menahan keunikan basename (Doc 10 §10/C2).

**K-6. Perubahan skema berjalan expand → backfill → contract dalam rilis terpisah** (M-9), mengikuti §14.3 "migration compatibility checks"; karena API dan frontend di-deploy terpisah, kontrak rilis harus mengasumsikan **kombinasi** versi yang berjalan bersamaan, bukan versi seragam.

**K-7. Lingkungan `local` / `staging` / `production` dipisahkan penuh** — database, bucket object storage, kredensial gateway, dan domain masing-masing (Blueprint §14.3); kunci idempotensi dan nomor urut dokumen tidak boleh berbagi ruang antar lingkungan (pernyataan yang sudah diterima di ADR-004).

## Alternatif yang ditolak

| | Ditolak | Alasan kalah |
|---|---|---|
| A | Monorepo dengan **satu pipeline** yang build-deploy ketiganya sekaligus | Sederhana di hari pertama, tapi menghilangkan deploy mandiri yang dijanjikan Blueprint §20 ("ketiga aplikasi tetap independently deployable sejak awal"); satu bug frontend menunda rilis API, dan durasi pipeline tumbuh tanpa manfaat |
| B | Polirepo (satu repo per aplikasi) | Memecahkan isolasi pipeline secara alami, tapi membayar dengan duplikasi tooling, versioning `api-contracts` lintas repo, dan koordinasi release tiga arah untuk tim 1–2 dev — persis biaya yang ingin dihindari §3.1 |
| C | Kontrak API ditulis **di luar** kode (spec manual yang diperbarui "berkala") | Sumber kebenaran jadi dua; drift tidak terdeteksi CI, dan klien hasil generate salah tanpa tanda apa pun — kegagalan yang baru terlihat di produksi |
| D | Schema-per-service sejak sekarang (satu jalur migrasi per aplikasi) | Blueprint §2.1 menegaskan sisi domain masih modular monolith dengan satu PostgreSQL; memisah jalur migrasi berarti transaksi lokal checkout–order–invoice hilang (aturan ADR-001) |
| E | Menjalankan migrasi otomatis sebagai bagian dari deploy tanpa checkpoint | Tidak bisa dibalik dengan aman; melanggar semangat M-6/M-9 dan Doc 10 §9 (rollback, backup, pemulihan) |

## Konsekuensi

**Yang jadi wajib:** satu berkas OpenAPI sebagai artefak yang di-review seperti kode; `packages/api-contracts` menjadi choke point — perubahan kecil di sana memicu build kedua frontend; arsitektur test (Deptrac) ikut CI sejak Sprint 0 karena tanpa itu aturan B1–B6 hanya menjadi dokumen.

**Yang jadi mahal:** menjaga tiga pipeline tetap hijau berarti setiap perubahan kontrak punya biaya perputaran lebih tinggi daripada "edit controller lalu selesai"; itu harga yang kita bayar untuk mendapat klien TypeScript yang tidak pernah bohong.

**Yang jadi tidak mungkin:** (1) mengklaim HA di MVP — satu node tetap satu titik kegagalan (Doc 11 B-15), jadi pipeline kami berakhir di "deploy + smoke + rollback teruji", bukan multi-region; (2) menggabungkan perubahan skema yang merusak kontrak dengan rilis fitur dalam satu PR; (3) memperbaiki migrasi yang sudah di-merge dengan mengeditnya (M-4).

**Ketergantungan jadwal:** kalau pemilik proyek memilih opsi **J-b** (Doc 11 §5) demi menahan 18 minggu, `admin` berhenti menjadi artefak ter-deploy-mandiri dan menjadi route group Inertia sementara — ADR ini harus diamandemen, bukan ditafsirkan ulang diam-diam, karena K-1 dan K-2 kehilangan satu dari tiga pipeline.

## Lampiran A — empat aturan migrasi yang diadopsi dari Doc 10 §1

| # | Aturan (Doc 10 §1) | Isi yang mengikat di CI |
|---|---|---|
| M-1 | Satu jalur migrasi global, file tetap milik modul | File migrasi tinggal di bawah `apps/api/` pada direktori modulnya, tetapi dijalankan sebagai satu daftar terurut berdasarkan basename ⇒ **basename harus unik di seluruh repo**; dua file bernama sama saling menimpa secara senyap |
| M-2 | Loader memakai glob, bukan daftar manual | Path dimuat dengan glob atas `…/Infrastructure/Database/Migrations` di satu service provider; tidak ada berkas bersama yang diedit saat modul baru dibuat, sehingga dua PR modul berbeda tidak konflik |
| M-3 | Format nama file | `NNNN_<module>_<slug>.php`, `NNNN` nomor urut global 4 digit, `<module>` PascalCase pendek; contoh: `0120_Identity_create_users_table.php` |
| M-4 | Setelah merge, migrasi beku | Migrasi yang sudah masuk `main` dan tercatat di tabel `migrations` pada lingkungan bersama tidak boleh diedit/di-rename/dihapus (Laravel menyimpan basename ⇒ mengganti nama = berjalan dua kali); koreksi selalu berupa migrasi baru |
