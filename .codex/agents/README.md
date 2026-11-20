# .codex/agents/

Definisi peran sub-agen pelaksana proyek Elzara. Satu berkas = satu peran. Controller
(sesi utama) yang menugaskan; agen tidak menugaskan dirinya sendiri dan tidak menulis
di luar matriks kepemilikannya.

Di chat Codex untuk repo ini, minta delegasi secara eksplisit, misalnya
`Delegasikan pembaruan Doc 09 ke subagen knowledge.`

## Urutan mengikat (Doc 11 §4.4 H)

```
migration-db  ->  feature  ->  testing  ->  docker-rebuild
     |               |
     +--- knowledge (paralel, berkas disjoint) ---+
```

1. `migration-db.toml` - dependensi + lockfile + skema auth/RBAC. Pertama.
2. `feature.toml` - guard, sesi, CSRF, login admin/storefront, RBAC, seeder, kontrak.
   Hanya mulai setelah migration-db selesai (skema + dependensi terpasang).
3. `testing.toml` - unit/integration/Architecture + enam gerbang dokumen. **Tidak boleh
   mulai sebelum feature selesai.** Ia menguji apa yang sudah ada, bukan membangun fitur.
4. `docker-rebuild.toml` - **gerbang terakhir**, bukan pekerjaan sampingan: rebuild tiga
   image dari konteks root, compose up, migrate + seed di container, health endpoint,
   laporan keluaran nyata. Hanya jalan setelah testing hijau.
5. `knowledge.toml` - penulis dokumen. Berjalan **paralel** dengan 1-2 pada berkas yang
   tidak bersentuhan. Dua penulis knowledge tidak pernah memegang berkas yang sama;
   trio Doc 09 §19 + Doc 01 §3.3 + konstanta gate H dipegang satu penulis sekaligus.
   Semua penulis knowledge selesai sebelum testing menjalankan gerbang dokumen.

## Siklus per fitur

Sebelum melanjutkan development, controller meminta commit-push mengaudit
perubahan awal, membuat commit per tujuan, dan push baseline sesuai tujuan Git
yang disepakati. Setelah baseline terkirim, tasklist-updater membaca checklist
dan bukti untuk menentukan irisan berikutnya. Migration-db memeriksa tabel dan
menulis migrasi jika perlu. Feature mengerjakan irisan yang ditetapkan dan
knowledge menyelaraskan dokumen yang terkena dampak.

Testing memverifikasi skenario fitur dan hasil endpoint yang berubah. Jika ada
regresi, feature dan testing bisa bekerja pada file yang berbeda. Docker-rebuild
memperbarui service yang terdampak setelah pengujian lulus. Tasklist-updater
mencatat bukti yang sudah diperoleh. Commit-push menyiapkan commit fitur,
memverifikasi push, dan melaporkan perubahan, bukti, serta pekerjaan terbuka.

## Yang dimiliki controller (bukan agen mana pun)

- `docs/00-overview.md`, `docs/11-blueprint-reconciliation.md`, `docs/adr/**`,
  `docs/specs/**` - register mengikat dan artefak sign-off klien.
- `scripts/**` (termasuk `verify-docs.sh`, `gen-lockfiles.sh`, `dev.sh`, `migrate.sh`,
  `codegen.sh`, `check-contract-drift.sh`) dan `.github/workflows/**`.
- `.codex/plans/**`, `.codex/agents/**`, `.agents/skills/**`, `.qoder/**`, berkas root.
- Skrip auditor di `C:/temp` (di luar repo): `verify_schema.py`, `audit_sweep3.py`,
  `audit_blueprint_coverage.py`, `check_layout.py`, `scan_unicode.py`,
  `list_dead_cites.py`. **Tidak ada agen yang boleh menyuntingnya atau melonggarkan
  matcher-nya.** Perubahan harapan hanya boleh disengaja, lewat controller, dan dilaporkan.

## Aturan bersama semua peran

- Prosa dokumen berbahasa Indonesia; ASCII (tanda `§` mengikuti konvensi sitasi repo).
  Dilarang CJK/hangul/Sirilik/fullwidth dan smart quote. Buktikan dengan
  `python C:/temp/scan_unicode.py <berkas>` -> `selesai: 0 kemunculan mencurigakan`.
- Semua instalasi dependensi dan eksekusi PHP/Node di container (`php:8.4` / `node:22`
  / `composer:2`), tidak pernah di host (PHP host 8.3.30, Node host 26). Tidak ada
  `make`, `pnpm`, atau `jq` di host - jangan mengarang perintah yang memakainya.
- Tidak ada `git commit` kecuali pemilik proyek meminta secara eksplisit.
- Tidak ada kredensial di berkas yang di-commit. `docker/.env` hanya lokal; contoh
  hanya nama variabel (`docker/.env.example`, `apps/api/.env.example`).
- Situs referensi pihak ketiga (Doc 07/Doc 08) bersifat **read-only**: tidak menyalin
  teks/foto/merek mereka, tidak menulis apa pun ke sana.
- Nama mati hanya boleh muncul di baris yang membawa konteks rename (lama, diganti, sebelumnya, tidak berlaku, tidak dibuat): `role_user`, `permission_role`, `kind`, `users.kind`, `users_kind_status`, `staff_users`, `personal_access_tokens`.
- Penamaan ADR inline: `ADR-007 (BFF/sesi)` dan `ADR-012 (RBAC/identitas/dependensi)`.
  Jangan menukar kata topik keduanya; kata generik "auth" bukan pembeda.
- Jumlah tabel tidak ditulis tangan di mana pun - pemiliknya Doc 09 §19 hasil
  `verify_schema.py`.
- **Tidak pernah mengklaim "verified" tanpa menjalankan perintahnya dan melihat
  keluarannya.** Tempel keluaran nyata di laporan, bukan ringkasan niat.
- Bila terblokir: tulis `BLOCKED` + fakta spesifik yang hilang + berkas yang terdampak,
  lapor ke controller. Jangan menyimpang diam-diam dari register kanonik
  (Doc 11 §4.4 A-J, Doc 11 §8, Doc 10 M-1..M-13).