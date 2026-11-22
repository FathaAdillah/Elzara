# Backfill flag operator legacy

## Batas dan urutan

Implementasi P0.09 memakai Identity 0051–0053 (Doc 10 §4.3).
Pada baseline 0042 tanpa kolom kind lama, ketiga up/down serta command
`identity:backfill-operator-flags` adalah no-op. Jangan menambahkan kind ke
baseline untuk menjalankan command.

Untuk database legacy berisi kind customer/staff, tanpa flag:

1. Backup database. Rilis N: jalankan hanya path 0051 dengan
   `php artisan migrate --force --path=app/Modules/Identity/Infrastructure/Database/Migrations/0051_Identity_expand_users_operator_flags.php`.
   Flag nullable default false, indeks `users_operator_status`, serta
   CHECK sementara NOT VALID ditambahkan. Indeks ini bukan CONCURRENTLY;
   jadwalkan jendela DDL sesuai ukuran tabel.
2. Jalankan `php artisan identity:backfill-operator-flags`. Ulangi untuk
   membuktikan nol pembaruan. Batch maksimum 1000, jeda 200 ms.
   Semua baris menjadi `is_operator = (kind = 'staff')` dan
   `is_admin = false`, termasuk baris yang sebelumnya mendapat bypass.
   Jangan menjalankan ulang setelah pemberian bypass manual.
3. Pastikan jumlah NULL flag dan admin tanpa operator sama-sama nol.
   Jalankan hanya path 0052. Migrasi menolak gerbang nonnol,
   memvalidasi CHECK; kedua flag tetap nullable sampai contract (M-13).
   Selama koeksistensi, penulis lama harus dihentikan atau didual-write;
   default false saja tidak menyinkronkan staff baru.
4. Cutover kode ke flag; jangan contract pada rilis expand yang sama.
   Rilis N+1: jalankan hanya path 0053. Kedua flag ditetapkan NOT NULL;
   kind, CHECK kind dan indeks
   `idx_users_kind_status` dihapus; CHECK flag memakai nama kanonik
   `ck_users_admin_requires_operator` dengan ekspresi sama seperti 0042.
   Jangan migrate tanpa filter pada legacy sebelum backfill/cutover.

Setiap batch dan akhir pemanggilan menulis `audit_logs` secara transaksional
dengan aksi `backfill.operator_flags`, target_type `users`, target_id `0`
(target kolektif, bukan akun tertentu), actor NULL, reason dan request ULID,
serta `after.updated`. Batch yang tidak bisa menulis audit ikut rollback;
pemanggilan ulang melanjutkan batch yang belum selesai.

Rollback 0053 melepas NOT NULL dan membangun kembali kind dari is_operator. Comment pada CHECK
kanonik menyimpan asal legacy agar down 0053 tidak mengubah baseline 0042
yang bentuk kolomnya identik. Jangan menghapus comment tersebut.
Down 0052 mengembalikan CHECK NOT VALID; down 0051
menghapus flag dan indeksnya. Sebelum down 0051, pastikan tidak ada hibah
bypass manual yang perlu dipertahankan; flag admin tidak punya padanan kind.

## Bukti lokal 2026-11-22

PHP 8.4.28, PostgreSQL 16 (container postgres:16-alpine), Redis 7.
Database terisolasi; tidak menyentuh staging/production. Tidak ada .env lama;
file .env kosong dipakai supaya dotenv saat PHPUnit tidak memberi warning.
Sumber baseline ialah master e8f2ed1. Migrasi baseline tidak diedit.

Inventaris Sprint 0 diambil dari:

```bash
git ls-tree -r --name-only e8f2ed1 apps/api | rg '/00[0-9][0-9]_.*\.php$'
```

Masing-masing path (tanpa prefix apps/api/) diberikan sebagai opsi
`--path` ke artisan migrate. Baseline memuat 29 path; cabang menambah
0051, 0052, 0053 sehingga 32. Tanpa filter, repo memuat 55 migrasi,
termasuk Catalog/Cart/Inventory/Fulfillment; angka 32 bukan total repo.

Hasil aktual:

```text
Fresh Sprint 0: 32 Ran
INFO  Nothing to migrate.
kolom kind lama sudah tidak ada - backfill no-op (idempoten).

Existing baseline master: 29 Ran
ReferenceSeeder DONE
Upgrade: 0051 DONE, 0052 DONE, 0053 DONE; 32 Ran
customer@example.test | f | f
staff@example.test    | t | f
admin@example.test    | t | f
kolom kind lama sudah tidak ada - backfill no-op (idempoten).

Legacy: 0051 DONE
backfill selesai: 1 baris diperbarui; is_admin=false untuk setiap baris.
backfill selesai: 0 baris diperbarui; is_admin=false untuk setiap baris.
0052 DONE, 0053 DONE
customer@example.test | f | f
staff@example.test    | t | f
invalid: 0
audit: backfill.operator_flags | users | 0 | {"updated": 1}
audit: backfill.operator_flags | users | 0 | {"updated": 0}
audit: backfill.operator_flags | users | 0 | {"updated": 0}
Rollback: 0053 DONE, 0052 DONE, 0051 DONE
customer@example.test | customer
staff@example.test    | staff
Re-migrate: 0051 DONE, backfill 1, 0052 DONE, 0053 DONE
```

Existing/legacy memakai dua fixture pengguna SQL terkontrol setelah
ReferenceSeeder. Pada existing, rollback no-op 0053–0051 mengembalikan
29 Ran, lalu command artisan interaktif identity:admin:create menambah
admin@example.test (exit 0) sebelum upgrade ulang menjadi 32 Ran.
Ketiga akun tetap utuh dan command backfill no-op.
Legacy disimulasikan dengan mengganti flag baseline menjadi kind,
ck_users_kind dan idx_users_kind_status, tanpa membuang pengguna.
Skema akhir: dua boolean NOT NULL DEFAULT false, indeks
users_operator_status, CHECK kanonik, tidak ada kind/CHECK/indeks lama.

Tes `tests/Contract/OperatorFlagsMigrationTest.php` mencakup no-op up/down
dan command, 1002 baris (batch 1000 + 2), penghapusan bypass semua baris,
idempotensi, audit, legacy kosong, penolakan kedua gerbang, rollback dan
re-migrate. Seluruh Unit,Schema,Feature,Contract:

```text
Tests: 27 skipped, 241 passed (3380 assertions)
```

Skip: 20 tes MinIO dan 7 Elasticsearch karena endpoint tidak disetel.
Composer lint passed; PHPStan No errors; kedua Deptrac 0 violations,
0 warnings, 0 errors. Enam auditor dokumen exit 0, 64 tes mutasi OK;
coverage LULUS SEBAGIAN (28 daftar), Blueprint asli tidak dibaca.
Gitleaks tidak terpasang pada mesin ini.
Log mentah lokal: /home/ubuntu/p009-evidence.log dan
/home/ubuntu/p009-tests.log; harness /home/ubuntu/p009-evidence.sh dan
/home/ubuntu/p009-fixtures.php. Bukti ini tidak memerlukan UI.
