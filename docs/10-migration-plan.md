# Dokumen 10 — Rencana migrasi & urutan skema

Tanggal: 2026-11-17 · Status: siap dieksekusi.
Prasyarat baca: **Doc 09** (definisi tabel), Doc 01 §3 (aturan B1–B6), Doc 03 §0 (Definition of Done), ADR-002.
Dokumen ini mengatur **tiga hal**: urutan dibuatnya skema, cara agar dua developer tidak saling menimpa, dan cara mengubah skema tanpa menurunkan layanan.

---

## 0. Tiga masalah yang dipecahkan

| Masalah | Penyebab | Mekanisme di dokumen ini |
|---|---|---|
| Urutan salah → `migrate` gagal di DB kosong | FK lintas modul boleh ada di level DB (S-10), jadi urutan pembuatan tabel **tidak boleh** dibiarkan implisit | §3 + Appendix A: setiap tabel punya **level**; hanya boleh me-reference level yang lebih rendah. Dibuktikan CI (§10), bukan dihafal manusia |
| Dua orang bikin file yang sama/nomor yang sama | alokasi nomor tak terkendali + satu orang mengedit migrasi yang sudah jalan | §5: nomor dari rentang sprint, satu PR satu modul, dan aturan beku-setelah-merge (M-4) |
| Deploy mengubah skema sambil aplikasi jalan | kolom diganti, bukan ditambah/dihapus bertahap | §6 expand–backfill–contract + §11 pola khusus untuk tipe CHECK & view |

---

## 1. Keputusan yang mengikat (M-1 … M-13)

**M-1 — Satu jalur migrasi global, file tetap milik modul.**
File migrasi tinggal di `app/Modules/<X>/Infrastructure/Database/Migrations/` (B6), tapi **dijalankan sebagai satu daftar terurut global**: Laravel menggabungkan semua path migration dan mengurutkan berdasarkan **basename** file. Konsekuensi yang harus diterima: *basename harus unik di seluruh repo* — dua file bernama sama di dua modul akan saling menimpa secara senyap. Prefix nomor (§2) menjamin keunikan, dan CI §10/C2 menolak duplikat.

**M-2 — Loader pakai glob, bukan daftar manual.**
Path dimuat dengan `glob('app/Modules/*/Infrastructure/Database/Migrations')` di satu service provider. Ini keputusan anti-tabrakan: tidak ada berkas bersama yang harus diedit saat modul baru dibuat, sehingga dua PR yang menambah modul berbeda tidak konflik.

**M-3 — Format nama file.**
`NNNN_<module>_<slug>.php` — `NNNN` = nomor urut global 4 digit (0001+), `<module>` = nama modul PascalCase pendek, `<slug>` = kerjaannya. Contoh: `0120_Identity_create_users_table.php`, `0845_Billing_add_intl_fee_window_to_invoices.php`.

**M-4 — Setelah merge, migrasi beku.**
Migrasi yang sudah masuk `main` **dan** sudah tercatat di `migrations` pada salah satu environment bersama (staging/prod) tidak boleh diedit, diganti nama, atau dihapus — Laravel menyimpan basename, jadi mengubah nama = migrasi itu **jalan dua kali**. Koreksi selalu berupa migrasi baru. Yang belum pernah di-merge bebas diperbaiki di branch sendiri.

**M-5 — Aturan level.**
Sebuah `REFERENCES <t>` hanya boleh menunjuk tabel dengan level lebih rendah dari tabel yang sedang dibuat (Appendix A). Self-FK (`categories.parent_id`) dan FK ke tabel yang sama dibuat dalam satu `CREATE TABLE` — itu sah.

**M-6 — `down()` wajib dan harus benar-benar jalan.**
Kecuali migrasi memang tak bisa dibalik (menghapus kolom berisi data, atau perubahan pada tabel append-only S-6) → kelas harus menandai dirinya irreversibel dengan alasan tertulis, dan CI (§10/C5) memaklumi hanya daftar hitam eksplisit itu.

**M-7 — Migrasi tidak pernah menyentuh nilai uang atau status.**
Backfill nominal, koreksi saldo, atau perubahan `money_status` lewat migrasi = melanggar B5/U1. Migrasi hanya DDL + (kalau terpaksa) backfill struktural non-uang (§7).

**M-8 — Satu file = satu transaksi.**
Postgres mendukung DDL transaksional, jadi satu file dianggap atomic. Pengecualian tunggal: `CREATE INDEX CONCURRENTLY` / `DROP INDEX CONCURRENTLY` wajib di file sendiri dengan penanganan transaksi dimatikan, dan tidak boleh bercampur dengan DDL lain.

**M-9 — Expand → backfill → contract dalam tiga rilis terpisah.**
Kontrak rilis: file contract (DROP) tidak boleh berada di rentang nomor rilis yang sama dengan file expand yang menggantikannya. Lihat §6.

**M-10 — View tidak pernah di-ALTER.**
View pelaporan (Doc 09 §18, S-5) dibuat/diganti dengan `CREATE OR REPLACE VIEW`. Setiap perubahan = migrasi baru yang mengulang definisi lengkapnya. Tidak pernah `DROP VIEW` kalau bisa `CREATE OR REPLACE` (view tidak punya dependan materialized di kita).

**M-11 — Enum tetap varchar + CHECK (S-2), dan itu menentukan bentuk migrasi.**
Menambah nilai enum = `DROP CONSTRAINT` lama + `ADD CONSTRAINT` baru dalam satu transaksi (satu file, dua statement). **Tidak pernah** `CREATE TYPE ... ADD VALUE`: tidak bisa dibalik, tidak bisa menghapus nilai, dan di beberapa versi tidak bisa dalam transaksi. Mengubah CHECK juga otomatis memvalidasi seluruh baris — karena itu §6 langkah 2 wajib dijalankan sebelum constraint baru dipasang.

**M-12 — Data referensi di-seed, bukan di-migrasi.**
`lane_stages`, `tracking_lanes`, `roles`, `permissions`, `feature_flags`, `settings`, `notification_templates`, kategori awal: masuk seeder referensi (§8), bukan `INSERT` di migrasi. Satu-satunya pengecualian: menambah kolom `NOT NULL` pada tabel yang sudah berisi data — itu butuh default sementara sesuai §7, bukan seed.

**M-13 — Kolom ditambahkan tanpa `NOT NULL` kecuali kosong.**
Kolom baru pada tabel terisi selalu `NULL` (+ default) saat expand, lalu `SET NOT NULL` dilakukan di file contract setelah backfill selesai dan diverifikasi. Ini yang membuat rilis bisa di-rollback ke kode versi sebelumnya tanpa kehilangan kolom.

---

## 2. Konvensi isi file

Satu file hanya satu jenis kerja: `create`, `add_*_to`, `drop_*_from`, `rename_*`, `backfill_*`, `contract_*`. Kalau sebuah perubahan butuh dua langkah, buat dua file dengan nomor berurutan — diff yang kecil dan dapat dibaca adalah alat anti-tabrakan terbaik.

Kerangka baku (dipakai semua contoh di dokumen ini):

```php
<?php
// 0845_Billing_add_intl_fee_window_to_po_batches.php
// Modul   : Billing (B1: hanya modul ini yang menulis tabel ini)
// Sprint  : 4 · rilis 2026-14
// Tier    : 0 (tidak menambah FK baru)
// Expand  : ya · contract menyusul di rilis 2026-15 (M-9)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_batches', function (Blueprint $t) {
            $t->bigint('intl_fee_min')->nullable();
            $t->bigint('intl_fee_max')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('po_batches', function (Blueprint $t) {
            $t->dropColumn(['intl_fee_min', 'intl_fee_max']);
        });
    }
};
```

Aturan bentuk:

- Tidak ada `DB::statement` untuk hal yang bisa dilakukan Blueprint. `DB::statement` hanya untuk `CREATE OR REPLACE VIEW`, constraint CHECK bernama, dan `COMMENT ON`.
- Constraint **selalu dinamai eksplisit** sesuai Doc 09 §1 (`uq_`, `idx_`, `ck_`, `fk_`). Tanpa nama, Postgres memberi nama sendiri dan `down()` tidak bisa menargetnya.
- `down()` adalah kebalikan persis dari `up()`, dalam urutan terbalik.
- Header komentar di atas wajib: modul, sprint/rilis, tier, dan status expand/contract — itu metadata yang dibaca manusia saat meninjau dan yang membuat §6 bisa ditegakkan.

---

## 3. Urutan eksekusi

Daftar lengkap ada di **Appendix A**; bentuk kerjanya begini:

| Level | Isi ringkas | Contoh tabel | Boleh FK ke |
|---|---|---|---|
| 0 | tanpa dependan | `media`, `users`-belum-*, `roles`, `permissions`, `courier_services`, `tracking_lanes`, `stock_locations`, `outbox_events` | — |
| 1 | dependan hanya level 0 | `categories`, `collections`, `users`, `lane_stages` | 0 |
| 2 | identitas & konfigurasi | `user_addresses`, `products`(†), `po_batches`, `vouchers`, `settings`, `feature_flags`, `notification_templates` | 0–1 |
| 3–4 | katalog lengkap, batch offer, **orders** | `variants`, `pages`, `grns`, `quotes`, `batch_offers`, `orders` | ≤ level sendiri −1 |
| 5 | **invoices**, cart, baris order & shipment | `invoices`, `carts`, `order_items`, `shipments`, `drop_claims` | 0–4 |
| 6 | baris & turunan | `cart_items`, `payment_intents`, `po_lines`, `parcels`, `reviews`, `tickets`, `procurements` | 0–5 |
| 7 | leaf append-only | `payment_ledger`, `payment_transactions`, `webhook_events`, `grn_items`, `invoice_allocations`, `review_media`, `ticket_messages` | 0–6 |
| 8 | rekonsiliasi (paling hulu dependan) | `reconciliation_items` | 0–7 |

(†) `products` hanya menyentuh `media`/`categories`; letaknya di level 2 karena `categories` ada di level 1.

Tiga fakta yang perlu diketahui sebelum mulai:

1. **Tidak ada siklus di level tabel.** Graf 88 FK-tabel sudah diverifikasi asiklik, jadi satu urutan linear memang ada. Siklus yang terlihat di level *modul* (`Content ↔ Identity`, `Orders ↔ Billing`, `Orders ↔ PreOrder`, `Orders ↔ Shipping`, `Orders ↔ Quotes`) adalah artefak pengelompokan, bukan kendala: di level tabel yang memecahnya adalah `media` (level 0) dan `users` (level 1) yang datang lebih dulu.
2. Karena itu **modul Billing/PreOrder/Payments/Shipping/Quotes tidak "dimiliki lebih dulu"** — urutan nomor mengikuti level tabel, bukan nama modul.
3. `jobs`, `failed_jobs`, `job_batches` dibuat sekali di level 0 oleh migrasi bawaan framework di `database/migrations/` dan tidak pernah disentuh (§11.3 di Doc 09).

---

## 4. Pemetaan sprint → rentang nomor

Nomor dialokasikan per rentang sprint supaya (a) urut kronologis, (b) tiap sprint punya kepala lapang, (c) dua orang di sprint yang sama tidak rebutan satu blok.

| Sprint | Rentang | Yang pertama kali dibuat di rentang ini |
|---|---|---|
| 0 — fondasi | `0001–0199` | framework tables, `outbox_events`, `roles/permissions/role_user/permission_role`, `media`, `users` + alamat/consent/sesi/OTP/2FA, `feature_flags`(+overrides), `settings`, `localizations`, `audit_logs` |
| 1 — katalog | `0200–0399` | `categories`, `collections`, `products`, `product_collections`, `product_options`/`_values`, `variants`, `variant_option_values`, `po_batches`, `batch_offers` |
| 2 — cart/checkout/billing | `0400–0599` | `orders`, `order_items`, `order_addresses`, `carts`, `cart_items`, `invoices`, `invoice_allocations`, `payment_intents`/`_transactions`, `payment_ledger`, `webhook_events`, `refunds` |
| 3 — ops & tracking | `0600–0799` | `shipments`, `shipment_order_items`, `tracking_lanes`, `lane_stages`, `tracking_events`, `shipment_stage_events`, `courier_services`, `shipping_tariff_rules`, `shipping_rates_cache`, `parcels`, `stock_*`, `grns`/`grn_items`, `procurements`, `procurement_codes`, `proof_files`, notification templates/logs/preferences |
| 4 — pre-order penuh | `0800–0999` | `po_lines`, `po_payment_plans`, `reconciliation_runs`/`_items`, job penerbit invoice `intl_fees` (DDL: kolom jendela biaya di `po_batches`) |
| 5 — request order | `1000–1199` | `quote_requests`, `quotes`, `quote_items`, `quote_visibility_grants`, `tickets`, `ticket_messages` |
| 6 — promo & konten & UGC | `1200–1399` | `pages`, `content_blocks`/`_versions`, `nav_items`, `vouchers`, `voucher_redemptions`, `loyalty_tiers`/`_entries`, `drops`, `drop_items`, `drop_claims`, `reviews`, `review_media`, `review_moderation` |
| 7 — hardening & reporting | `1400–1599` | view §18 Doc 09, index pelaporan `CONCURRENTLY`, partisi `outbox_events`/`payment_ledger` bila diperlukan |
| pasca-launch | `1600+` | bebas, tetap ikut M-1…M-13 |

Kolom `batch` di tabel `migrations` sengaja diabaikan sebagai mekanisme urutan — urutan kita adalah `NNNN`, dan `batch` hanya mencatat kelompok eksekusi. Konsekuensi: menjalankan `migrate` di DB staging yang sudah maju tidak pernah bergantung pada nomor batch lokal siapa pun.

---

## 5. Aturan anti-tabrakan (bagian yang menjawab "usahakan tidak saling bertabrakan")

### 5.1 Kepemilikan
Satu modul direktori = satu pemilik dalam satu waktu. **Satu PR tidak boleh menyentuh file migrasi dua modul berbeda.** Kalau sebuah fitur butuh perubahan di dua modul, pecah PR (mis. PR Orders untuk kolom baru, PR Billing untuk constraint-nya) dan urutkan merge-nya sesuai level: yang ber-level lebih rendah di-merge lebih dulu. Ini bukan birokrasi — ini yang membuat rebase hampir selalu bersih, karena tidak ada satu berkas pun yang diedit dua orang.

### 5.2 Alokasi nomor
Ambil nomor dengan cara: `git pull` → lihat nomor tertinggi yang ada → tambah 1. Jangan pakai `timestamp` Laravel sebagai pengganti; jangan pakai angka bulat cantik dari kepala (`0500`) tanpa memeriksa.
Kalau dua branch terlanjur memakai nomor sama: **yang di-merge kemudian** mengganti nomornya sendiri — aman selama filenya belum pernah jalan di environment bersama (M-4). Kalau ternyata sudah jalan di staging, jangan ganti nama; biarkan dan lanjut ke nomor berikutnya.

### 5.3 Yang tidak boleh disentuh siapa pun
- isi migrasi yang sudah di-merge (M-4) — koreksi lewat migrasi baru;
- `database/migrations/` milik framework, kecuali menambah file standar `jobs` di Sprint 0;
- service provider loader glob (M-2) — tidak pernah menambah path manual;
- kolom `id bigint identity` dan `*_code` publik (S-4) — tidak pernah di-rename atau di-retype.

### 5.4 Freeze window
48 jam sebelum demo staging dan sebelum setiap rilis prod: yang boleh masuk hanya migrasi **read-only** (view baru, index `CONCURRENTLY`, `COMMENT ON`). DROP, rename, dan perubahan CHECK ditahan sampai window terbuka. Alasannya praktis: di masa beku, rollback deploy harus bisa dilakukan hanya dengan mengganti kode, tanpa menyentuh skema (M-13 menjamin itu).

### 5.5 Penjagaan mekanis, bukan kesepakatan lisan
- `CODEOWNERS`: tiap `app/Modules/<X>/Infrastructure/Database/Migrations/` punya owner; PR menambah modul baru sudah pasti owner-nya pembuatnya.
- Test CI §10/C1–C8 menolak apa pun yang melanggar, termasuk pelanggaran urutan level. Tidak ada aturan di §5 ini yang mengandalkan ingatan.

### 5.6 Aturan khusus tabel append-only (S-6)
`payment_ledger`, `order_events`, `stock_movements`, `audit_logs`, `tracking_events`, `outbox_events`, `shipment_stage_events`, `review_moderation`: **tidak pernah** ada migrasi `UPDATE`/`DELETE`. Koreksi selalu baris baru dengan `reverses_id`. Kalau sebuah perubahan bentuk (mis. menambah kolom) diperlukan, itu hanya `ADD COLUMN` nullable — tidak pernah mengubah baris lama.

---

## 6. Mengubah skema tanpa menurunkan layanan

Urutan baku untuk setiap perubahan bentuk:

1. **Expand** — tambah kolom/tabel/indeks baru, semua `NULL` atau dengan default aman (M-13). Kode lama tidak tahu kolom itu ada → rilis ini bisa di-rollback ke kode sebelumnya tanpa aksi apa pun.
2. **Backfill** — §7. Bukan di file expand.
3. **Validasi** — query pembanding dijalankan (CI/manual) sebelum langkah 4. Contoh untuk perubahan CHECK: `SELECT count(*) FROM invoices WHERE <predicate baru> IS NOT TRUE` harus 0.
4. **Cutover kode** — deploy yang membaca/menulis bentuk baru. Pada titik ini dua bentuk hidup berdampingan; jangan diperpanjang lebih dari satu rilis.
5. **Contract** — rilis berikutnya: `SET NOT NULL`, ganti nama CHECK, `DROP COLUMN`. Boleh di-rollback dengan deploy ulang kode sebelumnya **tanpa** `migrate --rollback` (karena kolomnya masih ada sampai kontrak ini jalan).

Tiga bentuk yang paling sering salah di proyek ini, beserta resepnya:

**Ganti nilai enum (M-11).** Misal menambah tipe invoice `replenishment`:
file A (expand): `ADD CONSTRAINT ck_invoices_type_new CHECK (type IN (…termasuk baru…))` + `DROP CONSTRAINT ck_invoices_type`; satu transaksi, dua statement. Tidak perlu backfill — constraint lama adalah himpunan bagian.
File B tidak ada. Tapi kalau sebaliknya (menghapus nilai), wajib: migrate data dulu (file A = tambah kolom/koreksi baris baru untuk append-only, file B = ganti CHECK).

**Pecah tabel (mis. `intl_fees` keluar dari `invoices`).** File 1 `create invoice_fee_lines` + backfill dari `invoices` per tipe → file 2 deploy yang menulis dua tempat → file 3 deploy yang hanya membaca tabel baru → file 4 `DROP COLUMN` lama. Jangan menggabungkan 3 dan 4.

**Indeks besar pada tabel terisi.** `CREATE INDEX CONCURRENTLY` di file sendiri (M-8). Ini satu-satunya kategori migrasi yang tidak bisa dibalik dalam transaksi; `down()`-nya `DROP INDEX CONCURRENTLY` dan itu tidak bisa jalan di dalam transaksi, jadi file tersebut menandai diri tanpa penanganan transaksi dan hanya berisi satu statement.

---

## 7. Backfill

- **Bukan di migrasi** kalau volumenya bisa melewati ~50 ribu baris atau waktunya tidak pasti. Migrasi hanya memanggil job dan `return`, lalu deploy berikutnya menunggu status job selesai (dipantau di Horizon).
- Job backfill **idempoten**: kunci pada "sudah terisi atau belum", bukan pada nomor iterasi; bisa diulang setelah crash tanpa dobel.
- Ukuran batch 1.000 baris dengan jeda 200 ms; jangan `UPDATE … tanpa WHERE` di tabel produksi besar (menahan lock lama, membuat replica terlambat).
- Catat progres di `audit_logs` (`action = backfill.<nama>`, `reason` berisi tiket) supaya hasil §9 bisa diaudit.
- Backfill **uang tidak dilakukan dengan job biasa** — mengikuti B5, perubahan nominal lewat Command dan tercatat di `payment_ledger`.

---

## 8. Seeder

Dua kelompok yang tidak boleh dicampur, karena dipakai di tempat berbeda:

| Kelompok | Folder | Isi | Dijalankan di |
|---|---|---|---|
| **Reference** (wajib produksi) | `database/seeders/Reference/` | data yang membuat aplikasi bisa hidup | `db:seed --class=ReferenceSeeder` saat deploy pertama, idempoten |
| **Demo** (dev/staging saja) | `database/seeders/Demo/` | katalog fiktif, order contoh | dev & staging; **tidak pernah** prod |

Isi Reference (semuanya berasal dari Doc 09 + Doc 02, bukan salinan siapa pun):

- `roles`: Admin, Ops, Warehouse, Finance, Support · `permissions` per modul-aksi sesuai Doc 06 · `role_user` untuk akun admin bootstrapping.
- `tracking_lanes`: `rts`, `import_po`, `request`, `procurement` + `lane_stages` tiap jalur (tahap dikelola admin setelah ini; seed hanya baseline).
- `courier_services` & `shipping_tariff_rules`: **kosong di produksi** — diisi admin dari kontrak nyata klien (Doc 03 §9 jalur paralel). Seeder hanya menanam placeholder yang tidak bisa dipakai checkout.
- `feature_flags`: `procurement_lane` (default off, B-PO-9), `drops`, `reviews`, `quote_flow` + override kosong.
- `settings`: kurs & ambang deposit per Doc 02 §2.2 (B-PO-3), window `intl_fee`, TTL intent (U2), preferensi reminder H-7/H-1 (U3/ADR-002 A2).
- `notification_templates` untuk event yang butuh template sebelum layar admin tersedia.
- `categories` awal 8 nilai kategori publik yang **ditulis ulang sendiri** (Doc 08 §5 — hanya strukturnya yang jadi acuan, bukan teks mereka).
- `pages` + `content_block_versions`: 9 halaman kebijakan dengan **kerangka kosong** dan judul netral; isinya milik klien (Doc 00 §Batas pekerjaan).

Seeder Reference harus **idempoten** (`upsert` pada kunci alamiah, bukan `insert`), karena ia berjalan ulang di setiap environment baru dan tidak boleh menimpa hasil edit admin. Aturan turunan: seeder reference tidak pernah menyentuh baris yang sudah diubah admin — periksa `updated_at <> created_at` sebelum menimpa, atau pakai kolom `is_managed_by_seed`.

Seeder Demo (Doc 03 task 0.8): 10 produk fiktif **buatan sendiri** dengan nama karakter/IP tak terdaftar, 2 batch (satu buka, satu tutup), 1 kuotasi, voucher, drop, ulasan termoderasi, dan satu customer + satu staff demo. Semua gambar dari `assets/demo/*.svg` orisinal berlisensi bebas. Tidak ada foto, nama merek, atau teks dari situs mana pun.

Perintah resmi (Plan Sprint 0 D-S0-5: composer scripts, Makefile hanya pembungkus): `composer db:fresh` = `migrate:fresh` + `db:seed --class=ReferenceSeeder`; `composer db:demo` = yang pertama lalu `db:seed --class=DemoSeeder`. CI hanya memakai yang pertama, kecuali test yang memang butuh data demo. (`migrate:fresh --seed` menjalankan `DatabaseSeeder`, jadi ia harus tetap benar di kedua kelompok environment — ia satu-satunya bentuk yang dipakai pipeline.)

---

## 9. Rollback, backup, dan pemulihan

**Sebelum setiap deploy yang membawa migrasi** (di-script `deploy` Sprint 0 task 0.4):
1. `pg_dump -Fc` → simpan dengan nama `pre-<release>`, verifikasi bisa dibaca (`pg_restore --list`).
2. Catat titik: `SELECT max(id), max(migration) FROM migrations` → ditulis ke changelog rilis.
3. `php artisan down` hanya untuk migrasi yang memang butuh; untuk pola §6 tidak perlu.
4. `php artisan migrate --force`, lalu jalankan smoke test (login, cari, checkout dummy ke driver `manual`, satu transisi order).

**Rollback ke rilis sebelumnya:** prioritasnya selalu **deploy ulang kode**, bukan `migrate:rollback`. Alasannya: M-4/M-13 dirancang supaya kode lama tetap hidup dengan skema baru. `rollback` hanya untuk migrasi yang jelas belum dipakai kode apa pun.

**Kalau benar-benar harus mundur skema:** Laravel tidak punya `--step-to` (tidak ada perintah yang mengembalikan sampai nomor tertentu). Urutan yang sah: `php artisan migrate:status` untuk melihat migrasi yang sudah jalan dan menghitung berapa yang harus dibatalkan, lalu `migrate:rollback --step=N` dengan N hasil hitung itu — dan hanya selama N migrasi paling belakang belum dipakai kode mana pun. Kalau datanya sudah bersentuhan, jangan mundur: restore dump pra-rilis (§9 langkah 1). Kolom `batch` tidak bisa dipakai sebagai penanda karena urutan kita adalah `NNNN`, bukan batch (§4).

**Pemulihan bencana:** restore `pg_dump` → `migrate --force` (tidak ada yang jalan, karena `migrations` ikut ter-restore) → verifikasi C6 (§10). Latihan restore wajib dilakukan satu kali di Sprint 0 dan satu kali sebelum launch (Doc 03 §8) dan hasilnya didokumentasikan sebagai bukti, bukan niat.

---

## 10. Penjagaan otomatis di CI

Semuanya test nyata (`Tests/Schema`), jadi ia jalan di `make test` lokal maupun CI Sprint 0 task 0.3:

| ID | Pemeriksaan | Kegagalan berarti |
|---|---|---|
| C1 | `migrate:fresh` di Postgres bersih, satu transaksi global, tanpa error | urutan salah / SQL tidak valid |
| C2 | tidak ada basename migrasi duplikat di seluruh repo (M-1) | tabrakan senyap |
| C3 | setiap `REFERENCES` di dalam file migrasi menunjuk tabel yang **sudah dibuat pada nomor sebelumnya** dan cocok dengan level Appendix A | melanggar M-5 |
| C4 | tidak ada `float`/`double`/`decimal` untuk kolom uang; tidak ada `CREATE TYPE`/`ADD VALUE` | melanggar S-1/S-2 |
| C5 | untuk setiap file migrasi: `migrate` lalu `rollback --step=1` sukses, kecuali file yang terdaftar di daftar hitam irreversibel (M-6) dengan alasan | `down()` tidak benar |
| C6 | **drift check**: dump `information_schema` + constraint + index dari DB hasil migrasi dibandingkan dengan berkas referensi `docs/schema.snapshot.sql`. Beda = PR harus menyertai pembaruan Doc 09 **dan** snapshot | kode jalan tapi Doc 09 tidak mencerminkan kenyataan |
| C7 | `db:seed --class=ReferenceSeeder` dijalankan dua kali berturut-turut → tidak ada perubahan baris (idempoten, §8) | seeder akan merusak data admin saat deploy ulang |
| C8 | setiap migrasi punya header komentar `Modul/Sprint/Tier/Expand` (§2) | peninjau tidak bisa membuktikan M-9 |

Snapshot di C6 dihasilkan dengan `make db snapshot` dan ikut di-commit; diff-nya muncul di PR sebagai review material, jadi perubahan skema selalu terlihat sebagai perubahan teks, bukan kejadian tak kasat mata.

---

## 11. Checklist peninjau (Definition of Done khusus migrasi)

1. File berada di direktori modul yang menulis tabel itu (B1), dan PR tidak menyentuh modul lain (§5.1).
2. Nomor mengikuti §4 dan tidak mendahului level tabelnya (Appendix A); C3 hijau.
3. Header §2 lengkap; status expand/contract ditulis, dan contract-nya **tidak** di rilis yang sama (M-9).
4. Constraint bernama eksplisit; `down()` kebalikan persis; C5 hijau.
5. Tidak ada `INSERT` data referensi (M-12), tidak ada `UPDATE` nilai uang (M-7), tidak ada `UPDATE/DELETE` di tabel append-only (§5.6).
6. Kolom baru nullable atau dengan default aman (M-13); kalau butuh `SET NOT NULL`, itu file contract di rilis berikutnya.
7. Indeks besar memakai `CONCURRENTLY` di file sendiri (M-8).
8. Doc 09 diperbarui pada tabel yang sama, snapshot C6 di-regenerate, entri changelog dibuat.
9. Kalau migrasi ini menyentuh invoice/payment: tidak ada jalur kode yang mengubah nominal di luar Command Payments/Billing (B5).
10. Rollback didokumentasikan dalam satu kalimat di changelog rilis: "aman, tinggal deploy ulang kode" **atau** "perlu `migrate:rollback --step=N` (N dari `migrate:status`) karena …" **atau** "restore `pre-<release>.dump`".

---

## Appendix A — Urutan pembuatan tabel (resmi)

Level = dependan FK terjauh. Kolom kanan = modul pemilik. Tabel baru menyusup ke level yang ditentukan FK-nya, bukan berdasarkan kapan dipikir.

```
L0  media[Content]  permissions[Identity]  roles[Identity]  permission_role[Identity]  role_user[Identity]
    content_blocks[Content]  outbox_events[Lintas modul]  tracking_lanes[Shipping]  courier_services[Shipping]
    stock_locations[Inventory]  loyalty_tiers[Promotion]  shipping_rates_cache[Shipping]
    (+ jobs / failed_jobs / job_batches — bawaan framework, tidak diubah)

L1  users[Identity]  categories[Catalog]  collections[Catalog]  lane_stages[Shipping]

L2  user_addresses  user_consents  user_sessions  otp_challenges  staff_2fa_secrets   [Identity]
    products[Catalog]  content_block_versions  localizations   [Content]
    po_batches[PreOrder]  vouchers  drops   [Promotion]  quote_requests[Quotes]
    feature_flags  settings   [Settings]  notification_templates  user_preferences  notification_logs [Notification]
    audit_logs[Audit]  reconciliation_runs[Payments]  shipping_tariff_rules[Shipping]

L3  product_options  variants  product_collections   [Catalog]  pages[Content]
    grns[Inventory]  quotes[Quotes]  feature_flag_overrides[Settings]

L4  product_option_values  [Catalog]  orders[Orders]  batch_offers[PreOrder]  stock_movements[Inventory]
    drop_items[Promotion]  quote_items  quote_visibility_grants  [Quotes]  nav_items[Content]

L5  invoices[Billing]  carts[Cart]  variant_option_values[Catalog]
    order_items  order_addresses  shipments  order_events   [Orders]
    drop_claims  loyalty_entries   [Promotion]

L6  cart_items[Cart]  reservations[Inventory]  refunds[Orders]  shipment_order_items[Orders]
    payment_intents[Payments]  po_lines  po_payment_plans  [PreOrder]
    procurements[Procurement]  voucher_redemptions[Promotion]  parcels  tracking_events  [Shipping]
    tickets[Support]  reviews[UGC]

L7  invoice_allocations[Billing]  grn_items[Inventory]
    payment_transactions  payment_ledger  webhook_events   [Payments]
    procurement_codes  proof_files   [Procurement]
    shipment_stage_events[Shipping]  ticket_messages[Support]  review_media  review_moderation  [UGC]

L8  reconciliation_items[Payments]
```

## Appendix B — Cara meletakkan tabel baru

1. Baca Doc 09, tulis FK-nya. Ambil level = `1 + max(level tabel yang di-reference)`.
2. Cari nomor tertinggi di rentang sprint-nya (§4), tambah 1. Kalau level tabelmu menuntut jalan lebih dulu dari nomor itu, kamu salah sprint — bukan salah nomor.
3. Buat file di direktori modul pemilik (B1), isi header §2.
4. Tambahkan tabel + levelnya ke Appendix A dokumen ini lewat PR yang sama, dan perbarui Doc 09 §19 + snapshot C6.
5. `make db fresh && make test` — C1/C3/C5/C6 harus hijau sebelum PR dibuka.
