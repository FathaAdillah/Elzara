# Dokumen 10 — Rencana migrasi & urutan skema

Tanggal: 2026-11-17 · Status: siap dieksekusi · Diamandemen 2026-11-18: identitas tunggal + RBAC bentuk spatie (Doc 11 §4.4), alokasi basename Sprint 0 (§4.3), migrasi vendor ditulis ulang (§5.7), resep dua boolean (§6.2) · **Rujukan induk:** SNOWEGO_MICROSERVICES_BLUEPRINT.md v1.0 (ADR-003 K-1).
Prasyarat baca: **Doc 09** (definisi tabel, hasil rename §4.1/tambah §4.2), Doc 01 §3 (aturan B1–B6), Doc 11 (rekonsiliasi), ADR-002, ADR-010 (umbrella migrasi — lihat §4.2), ADR-012 (identitas/RBAC/dependensi), ADR-007 amandemen 2026-11-18 (cookie sesi Sanctum).
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
Monorepo Blueprint §3.1 menaruh aplikasi Laravel di `apps/api/`. File migrasi modul tetap di bawah app itu — `apps/api/app/Modules/<X>/Infrastructure/Database/Migrations/` (B6) — dan **dijalankan sebagai satu daftar terurut global**: Laravel menggabungkan semua path migration (dir modul + `apps/api/database/migrations/` milik framework) dan mengurutkan berdasarkan **basename** file. Konsekuensi yang harus diterima: *basename harus unik di seluruh `apps/api`* — dua file bernama sama di dua modul akan saling menimpa secara senyap. Prefix nomor (§2) menjamin keunikan, dan CI §10/C2 menolak duplikat. **Invariant ini tidak berubah walau path berubah (B-1).**

**M-2 — Loader pakai glob, bukan daftar manual.**
Path dimuat dengan `glob('apps/api/app/Modules/*/Infrastructure/Database/Migrations')` **dan** `apps/api/app/Platform/Infrastructure/Database/Migrations` (trio reliability lintas modul, Doc 01 §3.1), plus `apps/api/database/migrations` yang khusus tabel bawaan framework — semuanya di satu service provider (`app/ModuleLoader/`). Ini keputusan anti-tabrakan: tidak ada berkas bersama yang harus diedit saat modul baru dibuat, sehingga dua PR yang menambah modul berbeda tidak konflik.

**M-3 — Format nama file.**
`NNNN_<module>_<slug>.php` — `NNNN` = nomor urut global 4 digit (0001+), `<module>` = nama modul PascalCase pendek, `<slug>` = kerjaannya. Contoh: `0042_Identity_create_users_table.php` (alokasi Sprint 0, §4.3), `0845_Billing_add_grace_days_to_invoices.php`.

**M-4 — Setelah merge, migrasi beku.**
Migrasi yang sudah masuk `main` **dan** sudah tercatat di `migrations` pada salah satu environment bersama (staging/prod) tidak boleh diedit, diganti nama, atau dihapus — Laravel menyimpan basename, jadi mengubah nama = migrasi itu **jalan dua kali**. Koreksi selalu berupa migrasi baru. Yang belum pernah di-merge bebas diperbaiki di branch sendiri.

**M-5 — Aturan level.**
Sebuah `REFERENCES <t>` hanya boleh menunjuk tabel dengan level lebih rendah dari tabel yang sedang dibuat (Appendix A). Self-FK (`categories.parent_id`, `nav_items.parent_id`) dan FK ke tabel yang sama dibuat dalam satu `CREATE TABLE` — itu sah. **Empat back-edge siklus** (`carts.converted_order_id`, `checkout_sessions.converted_order_id`, `quotations.converted_order_id`, `pages.published_version_id`) sengaja menjadi `bigint` polos **tanpa `REFERENCES`** (Doc 09 §20) supaya graf FK tetap asiklik dan satu urutan linear selalu ada.

**Alternatif yang tidak dipilih, dan harganya.** Siklus FK bukan tidak bisa dibuat di Postgres: tabelnya bisa dibuat tanpa back-pointer lalu kendalanya dipasang belakangan lewat `ALTER TABLE … ADD CONSTRAINT` (atau `DEFERRABLE INITIALLY DEFERRED`), dan itu tetap kompatibel dengan aturan level karena constraint ditambahkan pada migrasi berikutnya, bukan pada `CREATE TABLE`. Kami memilih kolom polos karena (a) pola "jejak, bukan kendala" sudah dipakai konsisten — `user_consents.policy_version` juga kolom polos dengan alasan sama (Doc 09 §2), dan (b) empat kolom itu memang tidak dipakai untuk menegakkan apa pun: ketiganya adalah jejak konversi, dan `pages.published_version_id` menunjuk baris yang *juga* dapat diturunkan dari `content_block_versions` lewat `content_blocks`.

**Konsekuensi yang harus diterima, jangan diabaikan:** integritas rujukan keempat kolom itu tidak dijaga database. Satu jalur yatim nyata ada: `content_block_versions.content_block_id … ON DELETE CASCADE` (S-6 membuat histori versi tidak bisa diedit, tapi barisnya **ikut terhapus** saat bloknya dihapus keras), sehingga `pages.published_version_id` bisa menunjuk versi yang sudah tidak ada. Lapis pembaca **wajib** memperlakukan rujukan yang tidak ketemu sebagai "belum ada versi terbit" dan bukan error 500; test eksplisit untuk kasus ini masuk Daftar Kerja Phase 1. Kalau suatu saat integritas keras dibutuhkan, jalurnya adalah migrasi `ALTER TABLE … ADD CONSTRAINT` baru — bukan mengedit migrasi lama (M-4).

**M-6 — `down()` wajib dan harus benar-benar jalan.**
Kecuali migrasi memang tak bisa dibalik (menghapus kolom berisi data, atau perubahan pada tabel append-only S-6) → kelas harus menandai dirinya irreversibel dengan alasan tertulis, dan CI (§10/C5) memaklumi hanya daftar hitam eksplisit itu.

**M-7 — Migrasi tidak pernah menyentuh nilai uang atau status.**
Backfill nominal, koreksi saldo, atau perubahan `payment_status` lewat migrasi = melanggar B5/U1. Migrasi hanya DDL + (kalau terpaksa) backfill struktural non-uang (§7).

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
// 0845_Billing_add_grace_days_to_invoices.php
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
        Schema::table('invoices', function (Blueprint $t) {
            $t->integer('grace_days')->nullable();
            $t->timestampTz('reconsent_due_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn(['grace_days', 'reconsent_due_at']);
        });
    }
};
```

Aturan bentuk:

- Tidak ada `DB::statement` untuk hal yang bisa dilakukan Blueprint. `DB::statement` hanya untuk `CREATE OR REPLACE VIEW`, constraint CHECK bernama, indeks parsial/ekspresi (`WHERE …`, `lower(...)`) yang tidak bisa diekspresikan Blueprint, dan `COMMENT ON`.
- Constraint **selalu dinamai eksplisit** sesuai Doc 09 §1 (`uq_`, `idx_`, `ck_`, `fk_`). Tanpa nama, Postgres memberi nama sendiri dan `down()` tidak bisa menargetnya.
- `down()` adalah kebalikan persis dari `up()`, dalam urutan terbalik.
- Header komentar di atas wajib: modul, sprint/rilis, tier, dan status expand/contract — itu metadata yang dibaca manusia saat meninjau dan yang membuat §6 bisa ditegakkan.

---

## 3. Urutan eksekusi

Daftar lengkap ada di **Appendix A**; bentuk kerjanya begini:

| Level | Isi ringkas | Contoh tabel | Boleh FK ke |
|---|---|---|---|
| 0 | tanpa dependan | `media`, `roles`, `permissions`, `warehouses`, `suppliers`, `courier_services`, `tracking_lanes`, `shipping_rates_cache`, `price_lists`, `pages`, `loyalty_tiers`, `outbox_events`, `inbox_events`, `activity_log` | — |
| 1 | dependan hanya level 0 | `users`, `categories`, `brands`, `collections`, `lane_stages`, `content_blocks`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | 0 |
| 2 | identitas & konfigurasi | `addresses`, `customer_profiles`, `role_scopes`, `user_identities`, `products`, `preorder_batches`, `vouchers`, `settings`, `feature_flags`, `notification_templates`, `idempotency_keys`, `audit_logs` | 0–1 |
| 3–4 | katalog lengkap, item batch, **orders**, agregat stok, favorit & kabari-saya | `product_variants`, `preorder_batch_items`, `stock_items`, `orders`, `purchase_orders`, `quotations`, `wishlist_items`, `back_in_stock_subscriptions` | ≤ level sendiri −1 |
| 5 | **invoices**, baris order & shipment | `invoices`, `order_items`, `shipments`, `checkout_sessions`, `cart_items`, `cargo_items` | 0–4 |
| 6 | baris & turunan | `invoice_lines`, `payment_intents`, `preorder_enrollments`, `packages`, `reviews`, `tickets`, `marketplace_purchases` | 0–5 |
| 7 | leaf append-only + pengajuan retur | `payment_ledger`, `payment_transactions`, `payments`, `webhook_receipts`, `goods_receipt_items`, `claim_codes`, `review_media`, `ticket_messages`, `returns` | 0–6 |
| 8 | rekonsiliasi, landed cost & isi retur | `reconciliation_items`, `landed_cost_allocations`, `return_items` | 0–7 |

Tiga fakta yang perlu diketahui sebelum mulai:

1. **Tidak ada siklus di level tabel.** Graf FK antar 122 tabel (hasil parse Doc 09 §19 — angka hidup hanya di sana) sudah diverifikasi asiklik oleh parser yang sama (lihat §12), jadi satu urutan linear memang ada. Siklus yang terlihat di level *modul* (`Content ↔ Identity`, `Orders ↔ Billing`, `Orders ↔ PreOrder`, `Orders ↔ Fulfillment`, `Orders ↔ Sourcing`) adalah artefak pengelompokan, bukan kendala: di level tabel yang memecahnya adalah `media` (level 0) dan `users` (level 1) yang datang lebih dulu, ditambah empat back-edge yang dijadikan kolom polos (M-5).
2. Karena itu **modul Billing/PreOrder/Payments/Fulfillment/Sourcing/Procurement/Logistics tidak "dimiliki lebih dulu"** — urutan nomor mengikuti level tabel, bukan nama modul.
3. `jobs`, `failed_jobs`, `job_batches` dibuat sekali di level 0 oleh migrasi bawaan framework di `apps/api/database/migrations/` dan tidak pernah disentuh (Doc 09 §17).

---

## 4. Pemetaan sprint → rentang nomor

Nomor dialokasikan per rentang sprint supaya (a) urut kronologis, (b) tiap sprint punya kepala lapang, (c) dua orang di sprint yang sama tidak rebutan satu blok. Band di bawah sudah **diturunkan ulang dari himpunan modul Doc 09 hasil rename §4.1 + tambah §4.2 + keputusan pemilik 2026-11-18 (Doc 11 §4.4)** (modul baru: Logistics, Procurement supplier, MarketplacePurchase, dan trio reliability).

| Sprint | Rentang | Yang pertama kali dibuat di rentang ini |
|---|---|---|
| 0 — fondasi | `0001–0199` | framework tables, `outbox_events`, `inbox_events`, `idempotency_keys`, `roles`/`permissions`/`model_has_roles`/`model_has_permissions`/`role_has_permissions`/`role_scopes`, `media`, `users` (dua boolean `is_operator`/`is_admin`) + `addresses`/`customer_profiles`/consent/sesi/OTP/2FA/`user_identities`, `feature_flags`(+overrides), `settings`, `localizations`, `audit_logs` + `activity_log` |
| 1 — katalog | `0200–0399` | `categories`, `brands`, `collections`, `products`, `product_collections`, `product_options`/`_values`, `product_variants`, `variant_option_values`, `product_media`, `price_lists`, `price_list_entries`, `preorder_batches`, `preorder_batch_items` |
| 2 — cart/checkout/billing | `0400–0599` | `carts`, `cart_items`, `checkout_sessions`, `wishlist_items` (L4, Doc 11 §4.2), `orders`, `order_items`, `order_charges`, `order_adjustments`, `order_addresses`, `invoices`, `invoice_lines`, `invoice_allocations`, `receivables`, `payment_intents`/`_transactions`, `payments`, `payment_ledger`, `webhook_receipts`, `refunds`, `returns` (L7), `return_items` (L8) |
| 3 — ops, stok & tracking | `0600–0799` | `warehouses`, `stock_items`, `stock_movements`, `stock_transfers`, `stocktakes`, `shipments`, `shipment_order_items`, `tracking_lanes`, `lane_stages`, `tracking_events`, `shipment_stage_events`, `courier_services`, `shipping_tariff_rules`, `shipping_rates_cache`, `packages`, `stock_reservations`, `order_events`, notification templates/logs/preferences, `back_in_stock_subscriptions` (L4, Doc 11 §4.2) |
| 4 — pre-order penuh + procurement | `0800–0999` | `preorder_enrollments`, `preorder_allocations`, `preorder_payment_plans`, `suppliers`, `purchase_orders`, `purchase_order_items`, `supplier_invoices`, `goods_receipts`, `goods_receipt_items`, `marketplace_purchases`, `claim_codes`, `proof_files`, `reconciliation_runs`/`_items` (DDL kolom jendela biaya di `preorder_batches`) |
| 5 — request order + logistik internasional | `1000–1199` | `product_requests`, `sourcing_options`, `quotations`, `quotation_versions`, `quotation_items`, `quotation_visibility_grants`, `forwarders`, `consolidations`, `cargo_shipments`, `cargo_items`, `customs_charges`, `landed_cost_allocations`, `tickets`, `ticket_messages` |
| 6 — promo & konten & UGC | `1200–1399` | `pages`, `content_blocks`/`_versions`, `nav_items`, `vouchers`, `voucher_redemptions`, `loyalty_tiers`/`_entries`, `drops`, `drop_items`, `drop_claims`, `reviews`, `review_media`, `review_moderation` |
| 7 — hardening & reporting | `1400–1599` | view §18 Doc 09 (termasuk `order_status_history`, `v_landed_cost`), index pelaporan `CONCURRENTLY`, partisi `outbox_events`/`payment_ledger` bila diperlukan |
| pasca-launch | `1600+` | bebas, tetap ikut M-1…M-13 |

Kolom `batch` di tabel `migrations` sengaja diabaikan sebagai mekanisme urutan — urutan kita adalah `NNNN`, dan `batch` hanya mencatat kelompok eksekusi. Konsekuensi: menjalankan `migrate` di DB staging yang sudah maju tidak pernah bergantung pada nomor batch lokal siapa pun.

### 4.1 Invarian yang tetap dijaga walau cakupan berubah

- **B-1 (jalur file):** semua migrasi hidup di bawah `apps/api/`; tidak ada lagi path `app/Modules/...` di root repo. Nomor, basename-unique, dan glob loader (M-1/M-2) tidak berubah sifatnya — hanya akarnya yang pindah.
- Band tetap berbasis level (Appendix A), bukan nama modul. Tabel baru §4.2 disisipkan ke band tempat dependensi level-nya sudah ada lebih dulu; kalau sebuah tabel baru menuntut nomor lebih kecil dari band-nya, itu **salah band**, bukan salah nomor (§5.2, Appendix B).
- **Empat tabel yang disahkan Doc 11 §4.2 (2026-11-17)** ditempatkan persis mengikuti kaidah itu, bukan mengikuti kapan dipikirkan:
  | Tabel | FK langsung | Level (Appendix B) | Modul pemilik | Band |
  |---|---|---|---|---|
  | `wishlist_items` | `users` L1, `product_variants` L3 | **L4** | Cart | Sprint 2 (`0400–0599`), bersama cart/checkout |
  | `back_in_stock_subscriptions` | `users` L1, `product_variants` L3 | **L4** | Notification | Sprint 3 (`0600–0799`), bersama template/log/preferensi |
  | `returns` | `orders` L4, `users` L1, `refunds` L6 | **L7** | Orders | Sprint 2 — nomornya **setelah** file `refunds`, karena FK-nya ke sana |
  | `return_items` | `returns` L7, `order_items` L5 | **L8** | Orders | Sprint 2 — sesudah `returns` |
  Tidak ada satu pun yang menambah back-edge: keempatnya satu arah (tidak ada kolom `orders.return_id`), sehingga graf FK tetap asiklik dan Parser §12 tetap hijau. Nilai `tier:` di header §2 = level pada tabel di atas.

### 4.2 Rekonsiliasi dengan Blueprint §17 (Phase 0–4) & ADR-010

Blueprint §17 memberi **Phase**, bukan Sprint; ini jembatan angkanya. Doc 11 §5 sudah menyatakan cakupan Blueprint menaikkan jalur kritis (bukan 18 minggu lama); dokumen ini tidak menjual tanggal, hanya memetakan band.

| Blueprint Phase | Rentang sprint BP | Band dokumen ini | Isi skema yang lahir |
|---|---|---|---|
| Phase 0 — fondasi | 1–2 | Sprint 0 (`0001–0199`) | monorepo scaffold, RBAC 10 role, reliability trio, konfigurasi |
| Phase 1 — Ready to Ship MVP | 3–5 | Sprint 1–2 (`0200–0599`) | katalog, varian, `stock_items` atomic, cart/checkout, billing, payment |
| Phase 2 — Pre-Order / Group Order | 2–4 | Sprint 3–4 (`0600–0999`) | batch lifecycle + quota atomic, procurement supplier, goods receipt, rekonsiliasi |
| Phase 3 — Request Order | 2–3 | Sprint 5 (`1000–1199`) | request + quotation versi-aman, logistik internasional/landed cost |
| Phase 4 — Scale & advanced ops | berkelanjutan | Sprint 6–7 + pasca-launch (`1200+`) | promo/konten/UGC, reporting, partisi; multi-warehouse & Waiting Room menyusul |

**ADR-010 payung.** Blueprint §18 butir 9 (monorepo CI/CD + independent deploys + strategi migrasi) dipegang **ADR-010** (peta Doc 11 §3). ADR-010 menjadikan **§1 M-1…M-4 dokumen ini sebagai lampirannya** — aturan jalur-global/basename-unique/loader-glob/beku-setelah-merge adalah isi teknis yang dinormakan ADR-010, bukan diputus ulang di ADR. Karena itu bila ADR-010 berubah, yang perlu diselaraskan adalah M-1…M-4 di sini; dan sebaliknya. ADR lain yang menyentuh skema: **ADR-004** (reservation TTL + late-payment, kolom `stock_reservations.expires_at` & `orders.paid_stock_exception`), **ADR-011** (allocation multi-warehouse, default single-warehouse MVP), **ADR-012** (identitas tunggal + RBAC spatie — band tabel di §4.3).

### 4.3 Alokasi basename Sprint 0 untuk baseline baru

Band Sprint 0 tetap `0001–0199`. Plan Dependensi/Auth/RBAC D-DR-1 mengalokasikan sub-band `0040–0069`. Identity memakai `0040–0048` dan memiliki reservasi `0051–0053`. Audit memakai `0049–0050` dan `0054`. Rentang `0055–0069` tetap dicadangkan. Tabel fondasi berikutnya memakai `0070+` supaya FK ke `users` sudah tersedia. Tabel berikut mencatat berkas yang ada di working tree.

| Nomor | Basename | Isi | Level |
|---|---|---|---|
| 0001 | `0001_Platform_create_reliability_tables.php` | outbox_events, inbox_events, idempotency_keys | L0/L2 |
| 0002 | `0002_Platform_create_jobs_table.php` | jobs, failed_jobs, job_batches | L0 |
| 0010 | `0010_Content_create_media_table.php` | media; prasyarat FK avatar/banner pengguna | L0 |
| 0040 | `0040_Identity_create_roles_table.php` | roles dengan name dan guard_name | L0 |
| 0041 | `0041_Identity_create_permissions_table.php` | permissions dengan name dan guard_name | L0 |
| 0042 | `0042_Identity_create_users_table.php` | users dengan is_operator, is_admin, CHECK dan indeks users_operator_status | L1 |
| 0043 | `0043_Identity_create_model_has_roles_table.php` | model_has_roles; morph standar paket | L1 |
| 0044 | `0044_Identity_create_model_has_permissions_table.php` | model_has_permissions; izin langsung | L1 |
| 0045 | `0045_Identity_create_role_has_permissions_table.php` | role_has_permissions; bundel izin | L1 |
| 0046 | `0046_Identity_create_role_scopes_table.php` | role_scopes; batas cakupan role | L2 |
| 0047 | `0047_Identity_create_user_identities_table.php` | user_identities; identitas sosial | L2 |
| 0048 | `0048_Identity_create_staff_2fa_secrets_table.php` | staff_2fa_secrets; rahasia dan recovery 2FA | L2 |
| 0049 | `0049_Audit_create_audit_logs_table.php` | audit_logs append-only dengan reason dan request_id | L2 |
| 0050 | `0050_Audit_create_activity_log_table.php` | activity_log dengan morph paket; tanpa FK | L0 |
| 0054 | `0054_Audit_add_attribute_changes_to_activity_log.php` | tambah `attribute_changes jsonb NULL` untuk Activitylog 5.1.5 | L0 |
| 0070 | `0070_Identity_create_addresses_table.php` | addresses | L2 |
| 0071 | `0071_Identity_create_customer_profiles_table.php` | customer_profiles | L2 |
| 0072 | `0072_Identity_create_user_consents_table.php` | user_consents | L2 |
| 0073 | `0073_Identity_create_otp_challenges_table.php` | otp_challenges | L2 |
| 0074 | `0074_Identity_create_user_sessions_table.php` | user_sessions | L2 |
| 0075 | `0075_Content_create_localizations_table.php` | localizations | L2 |
| 0076 | `0076_Settings_create_feature_flags_table.php` | feature_flags | L2 |
| 0078 | `0078_Settings_create_feature_flag_overrides_table.php` | feature_flag_overrides; setelah feature_flags | L3 |
| 0079 | `0079_Platform_link_idempotency_keys_to_users.php` | FK idempotency_keys.actor_id ke users | L2 |

- `0010` mendahului `0042` untuk FK media. `0040/0041` mendahului pivot `0043–0045`. `0042` mendahului FK Identity, Audit, Content, Settings, dan FK reliability `0079`. Tes C3 di bagian 10 memverifikasi urutan berdasarkan FK.
- Bentuk tabel paket mengikuti keputusan proyek dan ditulis di bawah modul yang memiliki tabel (bagian 5.7). Kolom morph tetap memakai `model_type` dan `model_id` sesuai Doc 11 bagian 4.4 B.
- Migrasi maju `0054` menambah kolom nullable tanpa backfill atau penulisan ulang data lama. `0050` tetap beku. Perubahan ini hanya berlaku pada `activity_log`; jejak aksi sensitif tetap berada di `audit_logs`.
- `0077` disediakan untuk `settings`. Pembuatan berkas tertunda sampai pemilik memutuskan konflik `settings.updated_by`. Doc 09 bagian 15 menetapkan FK ke `users(id)` dengan `NOT NULL`, sedangkan plan Sprint 0 Task 11 dan seeder Task 15 mengasumsikan nilai `NULL` saat bootstrap. Selaraskan keputusan skema dan cara seeding sebelum menambah migrasi.
- Untuk basis data yang sudah berisi data, gunakan urutan expand, backfill, validasi, dan contract di bagian 6.2. Alokasi dan berkas di atas adalah baseline baru. Rollout atas basis data lama belum diverifikasi.

---

## 5. Aturan anti-tabrakan (bagian yang menjawab "usahakan tidak saling bertabrakan")

### 5.1 Kepemilikan
Satu modul direktori = satu pemilik dalam satu waktu. **Satu PR tidak boleh menyentuh file migrasi dua modul berbeda.** Kalau sebuah fitur butuh perubahan di dua modul, pecah PR (mis. PR Orders untuk kolom baru, PR Billing untuk constraint-nya) dan urutkan merge-nya sesuai level: yang ber-level lebih rendah di-merge lebih dulu. Ini bukan birokrasi — ini yang membuat rebase hampir selalu bersih, karena tidak ada satu berkas pun yang diedit dua orang.

### 5.2 Alokasi nomor
Ambil nomor dengan cara: `git pull` → lihat nomor tertinggi yang ada → tambah 1. Jangan pakai `timestamp` Laravel sebagai pengganti; jangan pakai angka bulat cantik dari kepala (`0500`) tanpa memeriksa.
Kalau dua branch terlanjur memakai nomor sama: **yang di-merge kemudian** mengganti nomornya sendiri — aman selama filenya belum pernah jalan di environment bersama (M-4). Kalau ternyata sudah jalan di staging, jangan ganti nama; biarkan dan lanjut ke nomor berikutnya.

### 5.3 Yang tidak boleh disentuh siapa pun
- isi migrasi yang sudah di-merge (M-4) — koreksi lewat migrasi baru;
- `apps/api/database/migrations/` milik framework, kecuali menambah file standar `jobs` di Sprint 0;
- service provider loader glob (M-2) — tidak pernah menambah path manual;
- kolom `id bigint identity` dan `*_code` publik (S-4) — tidak pernah di-rename atau di-retype.

### 5.4 Freeze window
48 jam sebelum demo staging dan sebelum setiap rilis prod: yang boleh masuk hanya migrasi **read-only** (view baru, index `CONCURRENTLY`, `COMMENT ON`). DROP, rename, dan perubahan CHECK ditahan sampai window terbuka. Alasannya praktis: di masa beku, rollback deploy harus bisa dilakukan hanya dengan mengganti kode, tanpa menyentuh skema (M-13 menjamin itu).

### 5.5 Penjagaan mekanis, bukan kesepakatan lisan
- `CODEOWNERS`: tiap `apps/api/app/Modules/<X>/Infrastructure/Database/Migrations/` punya owner; PR menambah modul baru sudah pasti owner-nya pembuatnya.
- Test CI §10/C1–C8 menolak apa pun yang melanggar, termasuk pelanggaran urutan level. Tidak ada aturan di §5 ini yang mengandalkan ingatan.

### 5.6 Aturan khusus tabel append-only (S-6)
`payment_ledger`, `order_events`, `stock_movements`, `audit_logs`, `tracking_events`, `outbox_events`, `inbox_events`, `shipment_stage_events`, `review_moderation`: **tidak pernah** ada migrasi `UPDATE`/`DELETE`. Koreksi selalu baris baru dengan `reverses_id`. Kalau sebuah perubahan bentuk (mis. menambah kolom) diperlukan, itu hanya `ADD COLUMN` nullable — tidak pernah mengubah baris lama.

### 5.7 Migrasi vendor: config boleh dipublish, migrasi TIDAK

`spatie/laravel-permission` 8.5.0, `spatie/laravel-activitylog` 5.1.5, dan `laravel/sanctum` 4.5.0 semuanya membawa migrasi bawaan. Aturan mainnya (Doc 11 §4.4 B/D, ADR-012 D-3/D-5):

- **Migrasi vendor tidak dipublish apa adanya.** `vendor:publish --tag=…-migrations` untuk ketiga paket itu dilarang; bentuk tabelnya **ditulis ulang (re-author)** di bawah namespace modul dan band kita (§4.3): `roles`/`permissions`/pivot spatie → Identity 0040–0046, `activity_log` → Audit 0050. Alasannya keras, bukan gaya: nama file vendor tidak memenuhi M-1/M-3 (basename unik global + nomor + folder modul), dan kolom ekstra kita (`roles.description`, `roles.is_system`, `permissions.group_name`, `permissions.description`, plus `role_scopes` sebagai tabel terpisah) akan memaksa mengedit file vendor — yang dilarang M-4 begitu tercatat di environment bersama.
- **Config boleh dipublish** (`config/permission.php`, `config/activitylog.php`, `config/sanctum.php`) lalu disesuaikan: nama tabel spatie mengikuti default paket, guard RBAC admin adalah `admin` (bukan default `web`), dan cache permission tetap lewat jalur `PermissionRegistrar` (§8).
- **Migrasi `personal_access_tokens` milik Sanctum dikecualikan secara eksplisit — tabel itu tidak dibuat** (ADR-007 D-3a, Doc 09 §20). Sanctum hanya dipakai untuk cookie sesi `statefulApi`; sesi/cache memakai driver redis (Doc 09 §17), jadi tidak ada migrasi Sanctum yang dijalankan sama sekali. Mem-publish migrasi itu = menciptakan permukaan token jangka panjang di browser yang sudah ditolak pemilik (ADR-012 alternatif F).
- `spatie/laravel-activitylog` menuntut **php ^8.4** (Doc 11 §4.4 D): instalasi dependensi hanya di container `php:8.4` (`scripts/gen-lockfiles.sh`), tidak pernah di host PHP 8.3. Ini syarat resolve, bukan preferensi.

---

## 6. Mengubah skema tanpa menurunkan layanan

Urutan baku untuk setiap perubahan bentuk:

1. **Expand** — tambah kolom/tabel/indeks baru, semua `NULL` atau dengan default aman (M-13). Kode lama tidak tahu kolom itu ada → rilis ini bisa di-rollback ke kode sebelumnya tanpa aksi apa pun.
2. **Backfill** — §7. Bukan di file expand.
3. **Validasi** — query pembanding dijalankan (CI/manual) sebelum langkah 4. Contoh untuk perubahan CHECK: `SELECT count(*) FROM invoices WHERE <predicate baru> IS NOT TRUE` harus 0.
4. **Cutover kode** — deploy yang membaca/menulis bentuk baru. Pada titik ini dua bentuk hidup berdampingan; jangan diperpanjang lebih dari satu rilis.
5. **Contract** — rilis berikutnya: `SET NOT NULL`, ganti nama CHECK, `DROP COLUMN`. Boleh di-rollback dengan deploy ulang kode sebelumnya **tanpa** `migrate:rollback` (karena kolomnya masih ada sampai kontrak ini jalan).

Tiga bentuk yang paling sering salah di proyek ini, beserta resepnya:

**Ganti nilai enum (M-11).** Misal menambah tipe invoice `replenishment`:
file A (expand): `ADD CONSTRAINT ck_invoices_type_new CHECK (type IN (…termasuk baru…))` + `DROP CONSTRAINT ck_invoices_type`; satu transaksi, dua statement. Tidak perlu backfill — constraint lama adalah himpunan bagian.
File B tidak ada. Tapi kalau sebaliknya (menghapus nilai), wajib: migrate data dulu (file A = tambah kolom/koreksi baris baru untuk append-only, file B = ganti CHECK).

**Pecah tabel (mis. memisahkan `intl_fees` dari `invoices` ke baris sendiri).** File 1 `create invoice_lines` + backfill dari `invoices` per tipe → file 2 deploy yang menulis dua tempat → file 3 deploy yang hanya membaca tabel baru → file 4 `DROP COLUMN` lama. Jangan menggabungkan 3 dan 4.

**Indeks besar pada tabel terisi.** `CREATE INDEX CONCURRENTLY` di file sendiri (M-8). Ini satu-satunya kategori migrasi yang tidak bisa dibalik dalam transaksi; `down()`-nya `DROP INDEX CONCURRENTLY` dan itu tidak bisa jalan di dalam transaksi, jadi file tersebut menandai diri tanpa penanganan transaksi dan hanya berisi satu statement.

**Rename tabel (§4.1) — khusus baseline Blueprint.** Karena Doc 09 direname sebagai **baseline pra-produksi** (belum ada deploy), rename dilakukan langsung di file `create`, bukan lewat `rename_*` expand/contract. Begitu sistem live, rename memakai pola tiga-file `create → backfill → drop` (M-9), bukan `RENAME` instan, agar kode lama tetap hidup selama satu rilis.

### 6.1 Resep khusus: mengubah CHECK empat sumbu status `orders` (B-3 / Doc 11 §8.3)

**Kenapa tidak bisa "cuma edit".** CHECK di Postgres tidak bisa dimodifikasi — hanya `DROP` + `ADD` (M-11), dan `ADD CONSTRAINT … CHECK` **memindai seluruh tabel** untuk memvalidasi baris lama. `orders` adalah tabel terpanas kita, jadi urutan di bawah ini dibuat supaya (a) tidak ada momen di mana baris sah jadi tidak sah, dan (b) tidak ada satu statement pun yang menahan tabel lebih dari sesaat.

**Dua jalur, pilih sesuai kondisi environment:**

- **Baseline pra-produksi (keadaan kita sekarang).** Tidak ada baris di `orders`. Nilai kanonik Doc 11 §8.3 ditulis **langsung di file `create`** (`04xx_Orders_create_orders_table.php`), DEFAULT `awaiting_payment`, tanpa expand/contract sama sekali. Langkah 1–6 di bawah tidak dijalankan. Ini jawaban untuk dev/staging yang di-`migrate:fresh`.
- **Environment yang sudah terisi** (DB demo yang tidak boleh dibuang, atau perubahan serupa di kemudian hari): ikuti langkah 1–6.

| # | File (contoh nama) | Isi | Kenapa urutannya begitu |
|---|---|---|---|
| 1 | `1610_Orders_widen_four_axis_status_checks.php` (expand, rilis N) | Untuk tiap sumbu: `ALTER TABLE orders DROP CONSTRAINT ck_orders_<sumbu>; ALTER TABLE orders ADD CONSTRAINT ck_orders_<sumbu>_v2 CHECK (<nilai lama> ∪ <nilai kanonik>) NOT VALID;` + `ALTER COLUMN order_status SET DEFAULT 'awaiting_payment'` | CHECK **superset** → baris lama tetap sah, kode baru sudah boleh menulis `awaiting_payment`. `NOT VALID` = tanpa pemindaian, hanya ACCESS EXCLUSIVE sesaat, jadi tidak ada antrian lock di belakang query panjang (M-8: satu file satu jenis kerja). DEFAULT ikut di sini, **bukan** di contract, supaya selisih satu rilis tidak pernah membuat INSERT baru ditolak constraint lama. |
| 2 | `1611_Orders_validate_four_axis_status_checks.php` | `ALTER TABLE orders VALIDATE CONSTRAINT ck_orders_order_status_v2;` (empat statement, boleh satu file karena hanya membaca) | `VALIDATE` hanya mengambil SHARE UPDATE EXCLUSIVE → tulis tetap jalan. File terpisah supaya gagal-validasi tidak menggulung DDL langkah 1. |
| 3 | job (bukan migrasi) `backfill:four-axis-status` | Peta nilai lama → baru (tabel di bawah). Batch 1.000 baris / jeda 200 ms, idempoten ("sudah bernilai baru? lewati"), progres ditulis ke `audit_logs` (`action = backfill.four_axis_status`) | §7: volume tak pasti ⇒ job, bukan migrasi. **M-7 dilanggar secara sadar di sini, dan itu satu-satunya pengecualian yang kami catat:** yang berubah adalah *label struktural*, bukan keputusan bisnis — tidak ada agregat, tidak ada Command, tidak ada nilai uang yang tersentuh. Transisi bisnis tetap hanya lewat Command (B5). |
| 4 | gerbang validasi (CI §10/manual) | `SELECT count(*) FROM orders WHERE order_status NOT IN (<kanonik>)` = 0; `… fulfillment_status NOT IN (<kanonik>)` = 0; `… procurement_status NOT IN (<kanonik>)` = 0 | §6 langkah 3. Jangan lanjut kalau bukan 0 — contract tidak bisa dibalik dengan deploy saja. |
| 5 | rilis N+1 (cutover selesai) | Deploy yang **hanya** membaca/menulis nilai kanonik. Tidak ada DDL. | Setelah ini tidak ada lagi penulis nilai lama. |
| 6 | `1620_Orders_contract_four_axis_status_checks.php` (contract, rilis N+2 — **bukan** rilis yang sama dengan langkah 1, M-9) | `DROP CONSTRAINT ck_orders_<sumbu>_v2; ADD CONSTRAINT ck_orders_<sumbu> CHECK (<kanonik saja>) NOT VALID;` lalu file `1621_…` berisi `VALIDATE CONSTRAINT` keempatnya | Sekarang enum **menyempit** — hanya sah setelah langkah 4 hijau. Nama constraint kembali persis seperti semula, jadi Doc 09 §6 (pemilik CHECK) dan `docs/schema.snapshot.sql` (C6) cocok tanpa edit ganda. |

**Tabel pemetaan nilai (dipakai langkah 3, dan menjadi dasar `down()`):**

| Kolom & nilai lama | Jadi | Catatan |
|---|---|---|
| `order_status = 'pending_payment'` | `order_status = 'awaiting_payment'` | Rename murni, satu-satunya yang benar-benar pergantian nama. |
| `order_status = 'confirmed'` | `order_status = 'paid'` | `confirmed` hilang (Doc 11 §8.3). Bedanya sudah dijawab `payment_status` (`paid` vs `partially_paid`); menyimpan dua kolom untuk satu fakta = jejak ganda. |
| `order_status = 'awaiting_batch'` | `order_status = 'paid'` **+** `fulfillment_status = 'awaiting_batch'` | **Nilai pindah kolom**, bukan rename: kalau hanya diganti di tempat, tahap perjalanan barang bocor ke sumbu order (B-3). |
| `order_status = 'ready_to_ship'` | `order_status = 'processing'` **+** `fulfillment_status = 'ready_to_ship'` | Sama seperti di atas. |
| `fulfillment_status = 'customs'` | `fulfillment_status = 'at_warehouse'` (mundur ke tahap fisik terakhir) **+** `procurement_status = 'customs'` | `customs` **dihapus** dari sumbu fulfillment; faktanya dijaga `procurement_status` (Doc 11 §8.3). Dua pemilik satu fakta = jejak yang bisa saling bertentangan. |
| `procurement_status = 'ready_to_ship'` | `fulfillment_status = 'ready_to_ship'` **+** `procurement_status = 'received'` | `ready_to_ship` keluar dari sumbu procurement (bukan fakta pengadaan). |
| `order_status ∈ {shipped, delivered, completed, on_hold, cancelled, expired, refund_requested, refunded}` | tetap | Tidak ada pekerjaan. |
| baru: `processing`, `refund_pending`, `disputed`, `requisitioned`, `supplier_confirmed`, `failed` | **tidak ada padanan lama** | Hanya ditulis kode pasca-langkah 5. Selama jendela v2 (langkah 1–5) kode **dilarang** menulis nilai tanpa padanan lama — itu yang membuat rollback langkah 5 cukup deploy ulang. |
| `payment_status = 'disputed'` | tidak dipetakan otomatis | Sumber lamanya bukan kolom, tapi keadaan (`refunds`/`webhook_receipts` sengketa + order `on_hold`). Daftar kandidat dikerjakan manual oleh Finance lewat Command, dicatat `audit_logs`; job **tidak** menebak (M-7). |

**Rollback per titik (isilah kalimat changelog §11 butir 10):**

| Kalau gagal di… | Rollback |
|---|---|
| langkah 1 | `migrate:rollback --step=1` aman: `down()` memasang kembali CHECK lama persis (belum ada baris bernilai baru). |
| langkah 2 | `DROP CONSTRAINT …_v2` + pasang CHECK lama; belum ada dampak data. |
| langkah 3 (job jalan sebagian) | jalankan `backfill:four-axis-status --reverse` (peta di atas dibaca terbalik; idempoten karena kunci "sudah bernilai lama?"). Jangan restore backup untuk ini. |
| langkah 5 (kode baru live) | **deploy ulang kode sebelumnya tanpa `migrate:rollback`** — aman hanya karena langkah 3 sudah 100% dan CHECK v2 menerima kedua bentuk. Ini alasan langkah 4 adalah gerbang, bukan formalitas. |
| langkah 6 | `migrate:rollback --step=1` = v2 kembali (melebar lagi) → lalu deploy ulang kode lama. Setelah validasi contract hijau, rollback yang tersisa hanyalah restore `pre-<release>.dump` (§9) — karena itu langkah 6 dijadwalkan di rilis terpisah, bukan di malam yang sama dengan cutover. |

Empat CHECK kanonik itu sendiri adalah milik Doc 09 §6 (Doc 11 §8.3: pemilik nilai final = skema); dokumen ini hanya memindahkan bentuknya, tidak pernah mendefinisikan ulang daftarnya.

### 6.2 Resep khusus: pembeda populasi `users` yang lama → dua boolean (Doc 11 §4.4 A)

Keputusan pemilik: kolom enum pembeda populasi (`kind`, customer | staff — **tidak berlaku** sejak 2026-11-18) dihapus, diganti `is_operator` (gerbang login admin) dan `is_admin` (bypass RBAC, wajib berjejak audit). Indeks `users_kind_status` lama diganti `users_operator_status (is_operator, status)`.

**Dua jalur, pilih sesuai kondisi environment (pola §6.1):**

- **Baseline pra-produksi (keadaan kita sekarang).** `users` belum punya baris. Bentuk baru ditulis **langsung di file create** `0042_Identity_create_users_table.php` (§4.3): dua boolean `NOT NULL DEFAULT false`, `CHECK (is_admin = false OR is_operator = true)`, indeks `users_operator_status`. Kolom lama tidak pernah lahir di migrasi mana pun; langkah 1–7 di bawah tidak dijalankan. Pivot juga langsung memakai nama spatie (0043–0046) — nama lama `role_user`/`permission_role` tidak dibuat lalu di-rename.
- **Environment yang sudah terisi** (DB demo yang tidak boleh dibuang, atau perubahan serupa di kemudian hari): ikuti langkah 1–7. Prinsip zero-downtime: tidak ada satu rilis pun yang membuat kode lama berhenti bekerja, dan DROP baru terjadi dua rilis setelah expand (M-9).

| # | File (contoh nama) | Isi | Kenapa urutannya begitu |
|---|---|---|---|
| 1 | `1630_Identity_add_operator_admin_flags_to_users.php` (expand, rilis N) | `ADD COLUMN is_operator boolean NULL DEFAULT false; ADD COLUMN is_admin boolean NULL DEFAULT false;` (M-13: nullable + default aman) | Kode lama tidak tahu kolom ini ada → rilis N bisa di-rollback tanpa aksi apa pun. Kolom populasi lama masih hidup dan masih dibaca kode lama. |
| 2 | `1631_Identity_create_users_operator_status_index.php` (expand, file sendiri M-8) | `CREATE INDEX CONCURRENTLY users_operator_status ON users (is_operator, status);` | Indeks baru dibangun tanpa menahan tulis. Indeks lama `users_kind_status` sengaja dipertahankan sampai contract supaya query kode lama tetap cepat. |
| 3 | job (bukan migrasi) `backfill:operator-flags` | `is_operator = true` untuk baris yang kolom populasi lamanya bernilai `staff`; `is_operator = false` untuk sisanya; **`is_admin = false` untuk SEMUA baris**. Batch 1.000 baris / jeda 200 ms, idempoten (kunci: "flag sudah terisi? lewati"), progres ditulis ke `audit_logs` (`action = backfill.operator_flags`) | §7: volume users bisa melewati ~50 ribu baris ⇒ job, bukan migrasi. **Bypass tidak di-backfill:** `is_admin = true` diberikan manual oleh pemilik proyek sesudahnya lewat jalur yang tercatat — setiap pemberian menulis `audit_logs` dengan `reason` (S-6, ADR-012 D-2). Job yang menebak siapa yang pantas dapat bypass = lubang eskalasi hak, bukan efisiensi. |
| 4 | gerbang validasi (CI §10/manual) | `SELECT count(*) FROM users WHERE is_operator IS NULL OR is_admin IS NULL` = 0; `SELECT count(*) FROM users WHERE is_admin = true AND is_operator = false` = 0 | §6 langkah 3. Query kedua adalah predikat CHECK baru; kalau bukan 0, contract akan menolak baris yang sah. Jangan lanjut sebelum keduanya 0. |
| 5 | rilis N+1 (cutover kode) | Deploy yang hanya membaca/menulis dua boolean: guard admin memeriksa `is_operator` + 2FA, `Gate::before` memeriksa `is_admin` dan menulis audit `rbac.bypass` per pemakaian. Tidak ada DDL. Kolom lama masih ada, tidak dibaca. | Rollback = deploy ulang kode sebelumnya **tanpa** `migrate:rollback`, karena kolom lama belum di-drop. Jangan perpanjang koeksistensi dua bentuk lebih dari satu rilis (§6 langkah 4). |
| 6 | `1640_Identity_contract_users_operator_admin_flags.php` (contract, rilis N+2 — **bukan** rilis yang sama dengan langkah 1, M-9) | `ALTER COLUMN is_operator SET NOT NULL; ALTER COLUMN is_admin SET NOT NULL; ADD CONSTRAINT ck_users_admin_requires_operator CHECK (is_admin = false OR is_operator = true); DROP COLUMN` kolom populasi lama | CHECK dipasang hanya setelah gerbang 4 hijau; `SET NOT NULL` setelah backfill terverifikasi (M-13). `down()` merekonstruksi kolom lama dari `is_operator` (staff/customer) — tetapi hibah bypass manual tidak bisa direkonstruksi dari enum lama, jadi setelah hibah pertama diberikan file ini menandai diri irreversibel dengan alasan tertulis (M-6); jejak hibah tetap abadi di `audit_logs`. |
| 7 | `1641_Identity_drop_users_kind_status_index.php` (file sendiri M-8) | `DROP INDEX CONCURRENTLY users_kind_status;` (indeks lama; penggantinya sudah dibangun di langkah 2) | Terakhir, setelah tidak ada kode yang membaca kolom lama. `down()` = `CREATE INDEX CONCURRENTLY` indeks lama. Tidak boleh bercampur dengan DDL lain (M-8). |

**Rollback per titik:** langkah 1–2 aman dengan `migrate:rollback --step=1` (belum ada data baru); langkah 3 dibalik dengan job `--reverse` (peta dibaca terbalik, idempoten); langkah 5 cukup deploy ulang kode lama selama kolom lama masih ada; langkah 6–7 hanya boleh dibalik sebelum hibah bypass manual pertama — sesudahnya pemulihan adalah restore dump pra-rilis (§9), dan itu alasan contract dijadwalkan di rilis terpisah.

**Pivot lama ke bentuk spatie (environment terisi).** Nama `role_user`/`permission_role` tidak berlaku (Doc 11 §4.4 B), dan pemetaan datanya **bukan rename satu-satu**:

- Baris `role_user` lama → `model_has_roles` (`user_id` menjadi morph `model_type` + `model_id`) **plus** `role_scopes` untuk baris yang `scope_type`/`scope_id`-nya terisi; scope global tetap satu baris dengan scope NULL.
- Baris `permission_role` lama (pasangan role-permission) → **`role_has_permissions`**, bukan `model_has_permissions`: tabel baru itu melayani pemberian hak langsung ke user, kemampuan yang sebelumnya tidak ada. (Tabel rename Doc 11 §4.4 B menyebut `permission_role` → `model_has_permissions` sebagai garis nama; isi data lamanya mendarat di `role_has_permissions`. Dua hal berbeda: bentuk pivot vs pasangan role-permission.)
- Pola tiga-file `create → backfill → drop` (§6, M-9); setelah backfill, cache permission di-invalidasi lewat `PermissionRegistrar` (§8), bukan `UPDATE` liar ke tabel spatie.

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
| **Reference** (wajib produksi) | `apps/api/database/seeders/Reference/` | data yang membuat aplikasi bisa hidup | `db:seed --class=ReferenceSeeder` saat deploy pertama, idempoten |
| **Demo** (dev/staging saja) | `apps/api/database/seeders/Demo/` | katalog fiktif, order contoh | dev & staging; **tidak pernah** prod |

Isi Reference (semuanya berasal dari Doc 09 + Doc 02, bukan salinan siapa pun):

- `roles` + `permissions` + `role_has_permissions` (RBAC admin, Doc 11 §4.4 B): **10 role Blueprint** (superadmin, catalog-manager, sales-ops, preorder-ops, sourcing-ops, warehouse-staff, procurement-staff, finance, customer-support, analyst — Doc 11 B-10) di-seed sebagai **bundel permission**, bukan sebagai bypass. `superadmin` tidak lagi berarti bypass: bypass hanya lewat `users.is_admin`, dan setiap pemakaiannya tercatat `audit_logs` (Doc 11 §4.4 A). Seluruh baris memakai `guard_name = 'admin'`; `roles.name` berisi kode role Blueprint dan `permissions.name` berisi `<modul>.<aksi>` (kolom `code` lama diganti `name`, unik pada `(name, guard_name)`). Bundel ditulis ke `role_has_permissions`; akun admin bootstrapping diikat lewat `model_has_roles` (pengganti pivot `role_user` lama) dengan `is_operator = true` dan `is_admin = false` — hibah bypass menyusul manual oleh owner, tercatat audit (§6.2 langkah 3). Pemberian hak per orang (mis. `catalog.view` tanpa create/update/delete) lewat `model_has_permissions`; scope per batch/kategori/gudang lewat `role_scopes`. **Storefront tidak memakai spatie**: tidak ada role/permission customer yang di-seed.

  Nama permission memakai prefix kanonik Doc 11 §4.4 B (disalin verbatim — pemilik daftar adalah register itu, bukan seeder):

  | Modul (Doc 09 §19) | Prefix | Modul | Prefix |
  |---|---|---|---|
  | Identity | `user` | Logistics | `logistics` |
  | Catalog | `catalog` | Promotion | `promotion` |
  | Content | `content` | Sourcing | `sourcing` |
  | PreOrder | `batch` | Procurement | `procurement` |
  | Billing | `billing` | MarketplacePurchase | `marketplace` |
  | Orders | `order` | UGC | `review` |
  | Inventory | `inventory` | Settings | `setting` |
  | Cart | `cart` | Notification | `notification` |
  | Payments | `payment` | Support | `ticket` |
  | Fulfillment | `shipment` | Audit | `audit` |
  | Reporting | `report` | Search | — (tanpa tabel, tanpa permission admin) |

  `Lintas modul` (outbox/inbox/idempotency) tidak punya permission admin: ia infrastruktur, bukan layar. Aksi standar per prefix: `view` (index/show), `create`, `update`, `delete`; aksi khusus hanya yang terdaftar di register (`billing.void`, `order.force_transition`, `batch.close`, `payment.mark_paid_manual`) — menambah aksi khusus berarti mengubah Doc 11 §4.4 B dulu, bukan menyisipkannya diam-diam di seeder. Matriks role → permission per layar tetap milik Doc 06; seeder menyalinnya, tidak menurunkannya ulang.

  **Cache permission adalah fakta operasional (Doc 11 §4.4 B, ADR-012):** spatie menyimpan cache permission di `PermissionRegistrar`. Pencabutan maupun penambahan hak — termasuk saat seeder berjalan ulang — **wajib lewat jalur yang meng-invalidasi cache** (API paket / `permission:cache-reset`), tidak pernah `UPDATE`/`DELETE` langsung ke tabel spatie. `UPDATE` liar membuat hak yang sudah dicabut tetap lolos sampai cache kedaluwarsa: itu lubang otorisasi, bukan detail performa. Seeder Reference karena itu memakai upsert pada kunci alamiah `(name, guard_name)` lewat model paket dan memanggil invalidasi cache sekali di akhir.
- `tracking_lanes`: `rts`, `import_po`, `request`, `marketplace_purchase` + `lane_stages` tiap jalur (tahap dikelola admin setelah ini; seed hanya baseline).
- `courier_services` & `shipping_tariff_rules`: **kosong di produksi** — diisi admin dari kontrak nyata klien (Doc 03 §9 jalur paralel). Seeder hanya menanam placeholder yang tidak bisa dipakai checkout.
- `feature_flags`: `marketplace_purchase.enabled` (default off, B-PO-9), `drops`, `reviews`, `quotation_flow` + override kosong.
- `settings`: kurs & ambang deposit per Doc 02 §15 (B-PO-3), window `intl_fee`, TTL intent (U2), preferensi reminder H-7/H-1 (U3/ADR-002 A2).
- `notification_templates` untuk event yang butuh template sebelum layar admin tersedia.
- `categories` awal 8 nilai kategori publik yang **ditulis ulang sendiri** (Doc 08 §5 — hanya strukturnya yang jadi acuan, bukan teks mereka).
- `pages` + `content_block_versions`: 9 halaman kebijakan dengan **kerangka kosong** dan judul netral; isinya milik klien (Doc 00 §Batas pekerjaan).

Seeder Reference harus **idempoten** (`upsert` pada kunci alamiah, bukan `insert`), karena ia berjalan ulang di setiap environment baru dan tidak boleh menimpa hasil edit admin. Aturan turunan: seeder reference tidak pernah menyentuh baris yang sudah diubah admin — periksa `updated_at <> created_at` sebelum menimpa, atau pakai kolom `is_managed_by_seed`.

Seeder Demo (Doc 03 task 0.8): 10 produk fiktif **buatan sendiri** dengan nama karakter/IP tak terdaftar, 2 batch (satu buka, satu tutup), 1 kuotasi, voucher, drop, ulasan termoderasi, dan satu customer demo + satu operator demo (`is_operator = true`, 2FA terkonfirmasi, `is_admin = false` — bypass tidak pernah di-seed, bahkan di demo). Semua gambar dari `assets/demo/*.svg` orisinal berlisensi bebas. Tidak ada foto, nama merek, atau teks dari situs mana pun.

Perintah resmi (Plan Sprint 0 D-S0-5: composer scripts, Makefile hanya pembungkus, dijalankan dari `apps/api/`): `composer db:fresh` = `migrate:fresh` + `db:seed --class=ReferenceSeeder`; `composer db:demo` = yang pertama lalu `db:seed --class=DemoSeeder`. CI hanya memakai yang pertama, kecuali test yang memang butuh data demo. Perlu dicatat: `migrate:fresh --seed` menjalankan `DatabaseSeeder` (bukan `--seed=Kelas` — bentuk itu **tidak ada** di Laravel), jadi `DatabaseSeeder` harus memanggil ReferenceSeeder di environment mana pun ia dipakai — inilah satu-satunya bentuk yang aman untuk pipeline.

---

## 9. Rollback, backup, dan pemulihan

**Sebelum setiap deploy yang membawa migrasi** (di-script `deploy` Sprint 0 task 0.4):
1. `pg_dump -Fc` → simpan dengan nama `pre-<release>`, verifikasi bisa dibaca (`pg_restore --list`).
2. Catat titik: `SELECT max(id), max(migration) FROM migrations` → ditulis ke changelog rilis.
3. `php artisan down` hanya untuk migrasi yang memang butuh; untuk pola §6 tidak perlu.
4. `php artisan migrate --force`, lalu jalankan smoke test (login, cari, checkout dummy ke driver `manual`, satu transisi order).

**Rollback ke rilis sebelumnya:** prioritasnya selalu **deploy ulang kode**, bukan `migrate:rollback`. Alasannya: M-4/M-13 dirancang supaya kode lama tetap hidup dengan skema baru. `rollback` hanya untuk migrasi yang jelas belum dipakai kode apa pun.

**Kalau benar-benar harus mundur skema:** Laravel **tidak punya** `--step-to` (tidak ada perintah yang mengembalikan sampai nomor tertentu). Urutan yang sah hanya memakai flag yang memang ada: `php artisan migrate:status` untuk melihat migrasi yang sudah jalan dan menghitung berapa yang harus dibatalkan, lalu `php artisan migrate:rollback --step=N` dengan N hasil hitung itu — dan hanya selama N migrasi paling belakang belum dipakai kode mana pun. Kalau datanya sudah bersentuhan, jangan mundur: restore dump pra-rilis (§9 langkah 1). Kolom `batch` tidak bisa dipakai sebagai penanda karena urutan kita adalah `NNNN`, bukan batch (§4).

**Pemulihan bencana:** restore `pg_dump` → `migrate --force` (tidak ada yang jalan, karena `migrations` ikut ter-restore) → verifikasi C6 (§10). Latihan restore wajib dilakukan satu kali di Sprint 0 dan satu kali sebelum launch (Doc 03 §8) dan hasilnya didokumentasikan sebagai bukti, bukan niat.

---

## 10. Penjagaan otomatis di CI

Semuanya test nyata (`Tests/Schema`), jadi ia jalan di `composer dev:test` lokal maupun CI Sprint 0 task 0.3:

| ID | Pemeriksaan | Kegagalan berarti |
|---|---|---|
| C1 | `migrate:fresh` di Postgres bersih, satu transaksi global, tanpa error | urutan salah / SQL tidak valid |
| C2 | tidak ada basename migrasi duplikat di seluruh `apps/api` (M-1) | tabrakan senyap |
| C3 | setiap `REFERENCES` di dalam file migrasi menunjuk tabel yang **sudah dibuat pada nomor sebelumnya** dan cocok dengan level Appendix A | melanggar M-5 |
| C4 | tidak ada `float`/`double`/`decimal` untuk kolom uang; tidak ada `CREATE TYPE`/`ADD VALUE` | melanggar S-1/S-2 |
| C5 | untuk setiap file migrasi: `migrate` lalu `rollback --step=1` sukses, kecuali file yang terdaftar di daftar hitam irreversibel (M-6) dengan alasan | `down()` tidak benar |
| C6 | **drift check**: dump `information_schema` + constraint + index dari DB hasil migrasi dibandingkan dengan berkas referensi `docs/schema.snapshot.sql`. Beda = PR harus menyertai pembaruan Doc 09 **dan** snapshot | kode jalan tapi Doc 09 tidak mencerminkan kenyataan |
| C7 | `db:seed --class=ReferenceSeeder` dijalankan dua kali berturut-turut → tidak ada perubahan baris (idempoten, §8) | seeder akan merusak data admin saat deploy ulang |
| C8 | setiap migrasi punya header komentar `Modul/Sprint/Tier/Expand` (§2) | peninjau tidak bisa membuktikan M-9 |

Snapshot di C6 dihasilkan dengan `composer dev:db-snapshot` dan ikut di-commit; diff-nya muncul di PR sebagai review material, jadi perubahan skema selalu terlihat sebagai perubahan teks, bukan kejadian tak kasat mata.

C1–C8 adalah test skema nyata. Di luar itu, **gerbang dokumen A–I + S1–S3** (enam pemeriksa, terdaftar di Doc 11 §8.8) dijalankan lewat `scripts/verify-docs.sh` dan wajib hijau setelah setiap gelombang perubahan dokumen — rinciannya di §12. PR yang mengubah Doc 09/10 wajib menjalankan keduanya; nama gate tidak diciptakan di dokumen ini.

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

## 12. Catatan pemeliharaan: angka tabel & graf FK (rujukan Doc 09 §19)

Doc 09 §19 mencantumkan **Total 122** dan menyatakan angka itu hasil parsing, bukan hitung tangan. Mekanismenya:

1. Satu parser kecil (dipakai di CI dan skrip lokal) membaca blok `nama_tabel [Modul]` di Doc 09 dan membandingkan tiga hal: (a) **jumlah blok tabel unik** == angka `Total` di §19; (b) **jumlah per baris modul** == kolom `Jumlah` §19; (c) **graf FK asiklik** — setiap `REFERENCES t` menunjuk tabel yang benar-benar didefinisikan (self-FK diabaikan, empat back-edge polos M-5 tidak dihitung sebagai edge).
2. Parser yang sama memastikan **tidak ada nama lama §4.1 + §4.4 B** yang masih tersisa di Doc 09 maupun dokumen ini, bahwa setiap tabel §4.2 yang diadopsi benar-benar muncul, dan bahwa tabel keputusan pemilik 2026-11-18 (`model_has_roles`, `model_has_permissions`, `role_has_permissions`, `role_scopes`, `user_identities`, `activity_log`) hadir semua (yang ditolak tercatat di Doc 09 §20, termasuk `personal_access_tokens` yang tidak dibuat).
3. Kalau salah satu meleset, CI §10 gagal sebelum merge — inilah penjaga mengapa draft lama bisa "meleset +3" tanpa ketahuan. Angka 122 bergerak hanya lewat PR yang juga menaikkan §19, menambah baris modul, dan (kalau perlu) menambah level Appendix A.

**Ini gerbang CI, bukan alat bantu lokal:** pemeriksaan jumlah tabel per modul, total §19, keberadaan target FK, dan keasiklikan graf dijalankan terhadap `docs/09-database-schema.md` oleh parser yang sama di pipeline §10, sehingga PR yang menambah tabel tanpa menaikkan §19/Appendix A langsung merah. Tidak ada alat kedua yang boleh menghitung angka ini; dokumen lain mengutip §19, tidak menyalinnya (Doc 11 §4.2).

Bukti jalan terakhir (2026-11-18, setelah keputusan Doc 11 §4.4 A–F masuk): parser melaporkan **125 blok tabel**, **122** di luar 3 blok bawaan `[Laravel]` (`jobs`/`failed_jobs`/`job_batches`), **0** target FK tak dikenal, **0** siklus FK, 0 nama lama §4.1/§4.4 B tersisa, dan keluar `LULUS — 125 tabel, konsisten dengan Doc 11`. Bukti sebelumnya (2026-11-17, setelah empat tabel Doc 11 §4.2 masuk): 121 blok / 118 milik modul — angka itu sudah tidak berlaku sebagai keadaan kini.

**Enam pemeriksa, gerbang A–I + S1–S3 (pemilik daftar: Doc 11 §8.8).** Parser skema di atas hanya satu dari enam; semuanya dijalankan sekaligus oleh `scripts/verify-docs.sh` dan wajib diulang **setelah** perubahan, bukan sebelum:

| Pemeriksa | Gerbang | Yang dibuktikan |
|---|---|---|
| `audit_sweep3.py` | A–H | empat sumbu status, 11 runbook, register event, nama mati, cakupan Blueprint + 12 ADR, sitasi hidup, homoglyph, kepemilikan tabel (Doc 09 §19 == Doc 01 §3.1/§3.3, satu tabel satu modul) |
| `audit_blueprint_coverage.py` | COVER + S1–S3 | 29 daftar tangan + 14 grup/87 submenu §4, 60 tabel minimum §6, 18 endpoint §7 — dibaca langsung dari berkas Blueprint |
| `verify_schema.py` | skema | jumlah blok & per modul §19, graf FK asiklik, nama lama, tabel §20 terdokumentasi, bentuk `users` §4.4 A |
| `check_layout.py` | I | pohon Doc 01 §3.1 = filesystem, basename migrasi M-3 unik/dalam band/pemilik = folder, tujuh antrian + enam bucket + dua lockfile |
| `scan_unicode.py` | G | nol homoglyph non-Latin per berkas (verify-docs.sh memperluas cakupan ke seluruh teks repo) |
| `list_dead_cites.py` | F | nol sitasi `Doc NN §M` yang menunjuk bagian tidak ada |

Konsekuensi untuk rencana ini: alokasi §4.3 diuji gate I terhadap berkas nyata (basename unik, band, modul pemilik = direktori), dan perubahan kepemilikan §19 diuji gate H terhadap Doc 01 — jadi keduanya bukan kesepakatan lisan. Nama gate di tabel ini disalin dari Doc 11 §8.8; dokumen ini tidak menciptakan gate baru.

Konsekuensi untuk Appendix A: tabel baru tidak "disisipkan berdasarkan kapan dipikir" tapi berdasarkan **1 + max(level yang di-reference)**; karena itu tabel seperti `stock_items`, `goods_receipts`, `landed_cost_allocations`, `returns` (karena menunjuk `refunds`), dan trio reliability punya level yang mungkin lebih tinggi dari tabel sezamanya di modul lama.

---

## Appendix A — Urutan pembuatan tabel (resmi)

Level = dependan FK terjauh (sumber: Doc 09 hasil rename §4.1 + tambah §4.2). Kolom kanan = modul pemilik. Tabel baru menyusup ke level yang ditentukan FK-nya, bukan berdasarkan kapan dipikir. Self-FK (`categories.parent_id`, `nav_items.parent_id`) dan empat back-edge polos (M-5) tidak menaikkan level.

```
L0  media[Content]  roles[Identity]  permissions[Identity]  tracking_lanes[Fulfillment]
    courier_services[Fulfillment]  shipping_rates_cache[Fulfillment]  warehouses[Inventory]
    suppliers[Procurement]  forwarders[Logistics]  price_lists[Catalog]
    pages[Content]  loyalty_tiers[Promotion]  outbox_events[Lintas modul]  inbox_events[Lintas modul]
    activity_log[Audit]  (morph, tanpa FK — kolom standar spatie, Doc 11 §4.4 F)
    (+ jobs / failed_jobs / job_batches — bawaan framework, tidak diubah)

L1  users[Identity]  model_has_roles  model_has_permissions  role_has_permissions[Identity]
    categories[Catalog]  brands[Catalog]
    collections[Catalog]  lane_stages[Fulfillment]  content_blocks[Content]

L2  addresses  customer_profiles  user_consents  otp_challenges  user_sessions
    role_scopes  user_identities  staff_2fa_secrets   [Identity]
    products[Catalog]  preorder_batches[PreOrder]  stocktakes[Inventory]
    carts[Cart]  reconciliation_runs[Payments]  shipping_tariff_rules[Fulfillment]
    product_requests[Sourcing]  content_block_versions  nav_items  localizations  [Content]
    feature_flags[Settings]  settings[Settings]  vouchers  drops[Promotion]
    notification_templates  user_preferences  notification_logs[Notification]
    idempotency_keys[Lintas modul]  audit_logs[Audit]

L3  product_collections  product_options  product_variants[Catalog]
    checkout_sessions[Cart]  consolidations[Logistics]  purchase_orders[Procurement]
    quotations[Sourcing]  feature_flag_overrides[Settings]

L4  product_option_values  product_media  price_list_entries[Catalog]
    preorder_batch_items[PreOrder]  orders[Orders]
    wishlist_items[Cart]  back_in_stock_subscriptions[Notification]
    stock_items  stock_movements  stock_transfers[Inventory]
    cargo_shipments[Logistics]  purchase_order_items  supplier_invoices  goods_receipts[Procurement]
    sourcing_options  quotation_versions  quotation_visibility_grants[Sourcing]  drop_items[Promotion]

L5  invoices[Billing]  order_items  order_adjustments  order_addresses  order_events[Orders]
    shipments[Fulfillment]
    cart_items[Cart]  cargo_items[Logistics]  quotation_items[Sourcing]
    loyalty_entries  drop_claims[Promotion]

L6  preorder_enrollments  preorder_payment_plans[PreOrder]
    invoice_lines  receivables[Billing]  order_charges[Orders]  shipment_order_items[Fulfillment]
    refunds[Payments]
    stock_reservations[Inventory]  payment_intents[Payments]
    packages  tracking_events[Fulfillment]  customs_charges[Logistics]
    marketplace_purchases[MarketplacePurchase]  voucher_redemptions[Promotion]
    reviews[UGC]  tickets[Support]

L7  preorder_allocations[PreOrder]  invoice_allocations[Billing]  returns[Orders]
    payment_transactions  payments  payment_ledger  webhook_receipts[Payments]
    shipment_stage_events[Fulfillment]  goods_receipt_items[Procurement]
    claim_codes  proof_files[MarketplacePurchase]
    review_media  review_moderation[UGC]  ticket_messages[Support]

L8  reconciliation_items[Payments]  landed_cost_allocations[Logistics]  return_items[Orders]
```

## Appendix B — Cara meletakkan tabel baru

1. Baca Doc 09, tulis FK-nya. Ambil level = `1 + max(level tabel yang di-reference)`. Self-FK dan back-edge polos (M-5) tidak dihitung.
2. Cari nomor tertinggi di rentang sprint-nya (§4), tambah 1. Kalau level tabelmu menuntut jalan lebih dulu dari nomor itu, kamu salah sprint — bukan salah nomor.
3. Buat file di `apps/api/app/Modules/<pemilik>/Infrastructure/Database/Migrations/`, isi header §2.
4. Tambahkan tabel + levelnya ke Appendix A dokumen ini lewat PR yang sama, dan perbarui Doc 09 §19 + snapshot C6. Parser §12 memvalidasi total, per-modul, dan asiklitas.
5. `composer dev:db-fresh && composer dev:test` (Makefile hanya pembungkus tipis; host Windows tidak punya `make`) — C1/C3/C5/C6 harus hijau sebelum PR dibuka.
