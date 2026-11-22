# Dokumen 09 — Struktur Database (PostgreSQL 16)

Tanggal: 2026-11-17 · Status: **baseline Blueprint-selaras untuk Phase 0–4** · Diamandemen 2026-11-18: identitas tunggal `is_operator`/`is_admin`, RBAC bentuk spatie, `user_identities`, `activity_log` (Doc 11 §4.4 A–F) · Mesin: PostgreSQL 16 · Pemilik skema: modul (lihat aturan B1/B6 di Doc 01 §3).

**Rujukan induk:** SNOWEGO_MICROSERVICES_BLUEPRINT.md v1.0 — nama dan makna mengikuti Blueprint (ADR-003 K-1); konflik diselesaikan di Doc 11 §4.

Dokumen ini adalah satu-satunya sumber kebenaran untuk bentuk data. Kalau kode dan dokumen ini bertentangan, dokumen yang salah — perbaiki dokumen lewat pull request, jangan diam-diam lewat migrasi.

Pasangan dokumen: `10-migration-plan.md` (cara mengubah skema ini tanpa saling menggesek). Daftar rename (§4.1), tabel baru (§4.2), dan yang tidak diambil (§4.3) hidup di `11-blueprint-reconciliation.md` — dokumen ini hanya memakai hasilnya.

**Snapshot implementasi 2026-11-22 (K4.2).** Baseline Phase 0–4 di §19 bukan jumlah tabel yang sudah terpasang. `migrate:status` di container API lokal menunjukkan **29 Ran, 0 Pending**, cocok dengan 29 basename Doc 10 §4.3. Katalog PostgreSQL lokal memiliki **28 tabel publik**: Identity 14, Content 2 (`media`, `localizations`), Settings 3, Audit 2, reliability Platform 3, framework 3 (`jobs`, `failed_jobs`, `job_batches`), dan tabel pelacak `migrations`. Modul domain lain masih target desain, bukan hasil migrasi lokal. Slot `0051–0053` belum berupa file; flag pengguna sudah final sejak create 0042. Dua nomor `0075` mempunyai basename berbeda (Content/Identity). Snapshot ini tidak menggantikan enam auditor eksternal, tidak membuktikan rollout legacy, dan tidak mengubah tally desain §19.

## 1. Keputusan desain yang mengikat

| # | Keputusan | Alasan |
|---|---|---|
| S-1 | **Uang = `bigint` rupiah utuh**, bukan `numeric`, bukan `float`. Kolom selalu berpasangan dengan `currency_code char(3) default 'IDR'`. | U1 di Doc 01 §5. Float mustahil diaudit; `numeric` godaan untuk menyimpan desimal yang aturannya bilang tidak ada. `bigint` cukup sampai Rp9,2 kuadriliun. Blueprint §6 mengizinkan alternatif `NUMERIC(20,4)`, tapi kita menolaknya: dua representasi uang dalam satu basis data = sumber bug pembulatan (Doc 11 B-13). |
| S-2 | **Enum = `varchar` + `CHECK`, bukan `CREATE TYPE ... AS ENUM`.** | Menambah nilai pada Postgres enum butuh DDL dan mengunci tabel; nilai bisnis (tipe invoice, alasan refund) akan bertambah. `CHECK` cukup dan bisa di-`DROP CONSTRAINT` tanpa rewrite. |
| S-3 | **`media` dimiliki modul Content, bukan Catalog** (meluruskan daftar tabel di Doc 01 §3). | Tabelnya polimorfik dan dipakai produk **dan** avatar/banner profil (D6) **dan** bukti transfer procurement **dan** foto ulasan **dan** lampiran request order. Menaruhnya di Catalog melanggar B1 karena empat modul akan menulis ke sana. Karena itu lampiran request (`request_attachments` Blueprint) **tidak** dibuat sebagai tabel kedua (Doc 11 §4.2). |
| S-4 | **PK internal `bigint identity`, identitas publik = `*_code varchar unique`.** | Nomor order/invoice dipakai customer, kurir, dan support → tidak boleh membocorkan volume transaksi (id berurutan) dan harus stabil. |
| S-5 | **Kolom turunan tidak boleh ditulis.** Status label customer, `total_billed`, `margin`, `order_status_history` = view/generated column. | Mencegah state busuk; sesuai B5 (uang & status hanya lewat aggregate). Riwayat status tetap tabel append-only `order_events`; `order_status_history` hanya proyeksi di §18. |
| S-6 | **Tabel append-only**: `payment_ledger`, `order_events`, `stock_movements`, `audit_logs`, `tracking_events`, `shipment_stage_events`, `outbox_events`, `inbox_events`. Tidak ada `UPDATE` atas baris masa lalu; koreksi = entri baru dengan `reverses_id`. | Bukti audit. Rekonsiliasi (U5) tidak mungkin kalau histori bisa diedit. |
| S-7 | **Soft delete hanya untuk** `users`, `products`, `pages`, `content_block_versions`, `vouchers`. Selain itu pakai `status = 'archived'`. | `deleted_at` di semua tabel membuat unique constraint dan report salah (baris "terhapus" tetap menabrak unique). |
| S-8 | **Snapshot, bukan referensi hidup**, untuk data yang masuk dokumen transaksi: alamat, harga, kuota, nama varian, tarif. | D7. Edit profil/admin tidak boleh mengubah invoice tahun lalu. |
| S-9 | **`timestamptz` selalu**, disimpan UTC, ditampilkan per `users.timezone`. | Toko punya buyer luar negeri (jalur request) dan jam jatuh tempo itu mengikat uang. |
| S-10 | **FK lintas modul boleh di level DB, dilarang di level Eloquent.** Relasi objek hanya intra-modul; antar-modul lewat Contracts/DTO. | B1 + B5. Integritas referensial tetap dijaga Postgres, tapi graph object lintas modul tidak terbentuk → pemisahan skema nanti mekanis. |
| S-11 | **Aturan penamaan turunan (Doc 11 §4):** nama mengikuti Blueprint bila perbedaannya hanya **prefik/jamak**; **makna** mengikuti Blueprint; **detail kita dipertahankan** di tempat Blueprint hanya menyebut "minimum". | Menyelesaikan tabrakan nama tanpa perdebatan ulang. Contoh: `variants`→`product_variants` (hanya prefik); `Procurement` dikembalikan ke makna Blueprint (supplier PO) sedangkan jalur beli-atas-nama jadi `MarketplacePurchase` (makna beda, bukan sekadar prefik — lihat Doc 11 B-4). |

### Konvensi

- Nama: tabel `snake_case` plural, kolom `snake_case`, FK `{tabel_singular}_id`, index `idx_{tabel}_{kolom}`, unique `uq_{tabel}_{kolom}`, check `ck_{tabel}_{syarat}`, FK `fk_{tabel}_{referensi}`.
- Tabel aplikasi umumnya punya `id bigint generated always as identity primary key`, `created_at timestamptz not null default now()`, `updated_at timestamptz not null default now()` — **tidak diulang** di definisi bawah. Tabel append-only boleh tidak punya `updated_at`. Pivot spatie, `role_scopes`, dan `feature_flag_overrides` tidak mempunyai id/timestamps tersebut; tabel framework mempertahankan bentuk Laravel (`jobs`/`job_batches` memakai epoch integer dan `failed_jobs.failed_at` timestamp tanpa zona).
- Kolom `*_at` yang null berarti "belum terjadi", bukan "tidak diketahui".
- Kolom `kind` pada tabel domain (collections, media, vouchers, warehouses, dst.) adalah klasifikasi isi tabelnya sendiri, **bukan** pembeda populasi `users` — pembeda populasi yang lama sudah dihapus (Doc 11 §4.4 A). Catatan "(bukan pembeda populasi lama)" pada definisi di bawah menandai pembedaan itu supaya sapuan nama mati tidak menyasar kolom yang sah.
- Tabel yang ditulis admin/staff saja punya `created_by bigint references users(id)` (diisi otomatis oleh trait audit).
- JSONB hanya untuk payload benar-benar bebas bentuk (`content_block_versions.payload`, `webhook_receipts.body`, `settings.value`, `price_snapshot`, `tax_rule_snapshot`). Data yang di-query jangan pernah masuk JSONB.
- **Empat sumbu status order** (B-3, BP §5.4) disimpan sebagai kolom terpisah: `order_type`, `order_status`, `payment_status`, `fulfillment_status`, `procurement_status`. Lane pemenuhan (`fulfillment_lane`) tetap sebagai **nilai pengisi** tahap fulfillment, bukan sumbu kelima (Doc 11 B-3).
- **Snapshot mata uang** (B-13) ada pada baris transaksi: `currency_code`, `exchange_rate_snapshot`, `tax_rule_snapshot`, `price_snapshot`.

### Cara baca definisi

```
tabel_name                    [modul pemilik]
  kolom tipe [NOT NULL] [default ...]   keterangan
  --
  CONSTRAINTS & INDEXES
```

## 2. Identity

```
users                                     [Identity]
  public_id        varchar(26) NOT NULL UNIQUE       id eksternal (ULID), untuk API/log
  name             varchar(120) NOT NULL
  email            citext NOT NULL UNIQUE              case-insensitive; normalisasi lower() di layer Domain
  email_verified_at timestamptz
  phone            varchar(24)                        E.164, dinormalisasi sebelum simpan
  phone_verified_at timestamptz
  password_hash    varchar(255)                       null bila akun dibuat lewat admin/import atau hanya lewat login sosial
  is_operator      boolean NOT NULL DEFAULT false     satu-satunya gerbang login admin (selain status active + 2FA wajib);
                                                      tidak memberi hak apa pun di storefront (Doc 11 §4.4 A)
  is_admin         boolean NOT NULL DEFAULT false     bypass RBAC di admin (Gate::before guard admin); setiap pemakaian
                                                      wajib menulis audit_logs (action = rbac.bypass, reason, request_id)
  status           varchar(16) NOT NULL DEFAULT 'active'     active | suspended | deletion_requested | deleted
  locale           varchar(5)  NOT NULL DEFAULT 'id'         D6 i18n ID/EN
  timezone         varchar(64) NOT NULL DEFAULT 'Asia/Jakarta'
  avatar_media_id  bigint NULL REFERENCES media(id) ON DELETE SET NULL
  banner_media_id  bigint NULL REFERENCES media(id) ON DELETE SET NULL
  marketing_optin  boolean NOT NULL DEFAULT false
  last_login_at    timestamptz
  deleted_at       timestamptz                        hak hapus (Doc 01 §9.3)
  --
  CHECK (is_admin = false OR is_operator = true)   ck_users_admin_requires_operator: bypass tanpa hak login admin
                                                   adalah keadaan mustahil di tingkat DB, bukan kemungkinan yang dijaga kode
  CHECK (status IN ('active','suspended','deletion_requested','deleted'))
  idx users_operator_status (is_operator, status)   rename indeks lama users_kind_status (Doc 11 §4.4 A)
  -- Login admin: email + password saja, is_operator = true, status active, 2FA wajib (staff_2fa_secrets).
  --   Tidak ada jalur sosial ke admin. Login storefront: akun active apa pun, termasuk operator;
  --   otorisasi web = kepemilikan (policy owner) + status akun, bukan dua flag di atas.
  -- Kolom pembeda populasi yang lama dihapus total, tidak disimpan berdampingan dengan dua boolean ini
  --   (dua gerbang untuk satu fakta = kelas bug yang membuat keputusan ini diambil, ADR-012 D-1).
  --   Sejarah keputusan: Doc 11 §4.4 A; jalur migrasi expand-backfill-contract: Doc 10 §6.2.
  -- Checkout tamu (Doc 02 §10.3, M2): orders.user_id NOT NULL, jadi tamu tetap satu baris users
  --   anonim per sesi checkout, bukan null dan bukan tabel kedua (Doc 11 §4.4 L):
  --   email = 'guest-<public_id>@guest.invalid' (domain .invalid RFC 2606, tidak bisa diroute),
  --   password_hash NULL, email_verified_at NULL, is_operator/is_admin false, status 'active'.
  --   Baris ini tidak bisa login lewat jalur mana pun: password guard gagal karena hash NULL,
  --   jalur sosial gagal karena tidak ada user_identities dan email .invalid tidak bisa diverifikasi.
  --   Klaim order lewat email+nomor mengisi email asli + verifikasi, lalu baris yang sama menjadi
  --   akun biasa — tidak ada penggabungan dua baris users, karena order sudah menunjuk baris ini.
  --   Pembersihan baris tamu yang tidak pernah diklaim = job retensi (Doc 01 §9.3), bukan hapus manual.

addresses                                 [Identity]      rename user_addresses (Doc 11 §4.1)
  user_id     bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  label       varchar(60)                        "rumah", "kantor"
  recipient   varchar(120) NOT NULL
  phone       varchar(24) NOT NULL
  province    varchar(60) NOT NULL
  city        varchar(60) NOT NULL
  district    varchar(60)
  village     varchar(60)
  postal_code varchar(10) NOT NULL
  address_line text NOT NULL
  lat numeric(9,6)  lng numeric(9,6)              pinpoint (M2 Doc 02 §10.3)
  is_default  boolean NOT NULL DEFAULT false
  is_archived boolean NOT NULL DEFAULT false
  --
  uq addresses_default (user_id) WHERE is_default AND NOT is_archived   partial unique: max 1 default
  idx addresses_user (user_id)

customer_profiles                         [Identity]      tabel baru (BP §6, Doc 11 §4.2)
  user_id     bigint NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE
  display_name varchar(120)
  birthday    date                                 segmentasi & verifikasi umur (age_gate consent)
  gender      varchar(12)                           male | female | other | undisclosed
  tax_id      varchar(40)                           NPWP, terenkripsi at-rest
  referral_code varchar(40)                          kode referral yang dipakai saat mendaftar
  referred_by bigint REFERENCES users(id) ON DELETE SET NULL
  external_ids jsonb NOT NULL DEFAULT '{}'          id sosial/SSO non-kredensial (provider → id)
  notes text
  --
  CHECK (gender IN ('male','female','other','undisclosed') OR gender IS NULL)
  idx customer_profiles_referral (referral_code)

user_consents                             [Identity]
  user_id   bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  kind      varchar(32) NOT NULL       terms | privacy | po_deposit_forfeit | marketing | age_gate
  policy_version integer NOT NULL      merujuk content_block_versions.version → consent bisa dibuktikan terhadap teks yang berlaku (kolom plain: bukti, bukan FK)
  granted_at timestamptz NOT NULL
  ip inet, user_agent varchar(255)
  --
  uq user_consents_once (user_id, kind, policy_version)

otp_challenges                            [Identity]
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  channel varchar(16) NOT NULL         email | whatsapp | sms
  code_hash varchar(255) NOT NULL      jangan simpan kode mentah
  purpose varchar(24) NOT NULL         login | reset_password | verify_phone | verify_email
                                       verify_email ditambah 2026-11-18 (Doc 11 §4.4 C): penggabungan akun sosial
                                       hanya boleh ke email yang sudah terverifikasi, dan tanpa nilai ini tidak ada
                                       jalur yang menghasilkan users.email_verified_at
  attempts smallint NOT NULL DEFAULT 0
  expires_at timestamptz NOT NULL
  consumed_at timestamptz
  --
  idx otp_active (user_id, purpose, expires_at)

user_sessions                              [Identity]      layar "perangkat" di profil (Doc 05 §5.7)
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  session_hash varchar(64) NOT NULL UNIQUE
  ip inet, user_agent varchar(255), device_label varchar(80)
  last_seen_at timestamptz, revoked_at timestamptz
  --
  idx user_sessions_user (user_id) WHERE revoked_at IS NULL

roles                                     [Identity]      10 role Blueprint (Doc 11 B-10); bentuk spatie/laravel-permission (Doc 11 §4.4 B)
  name varchar(48) NOT NULL           kode role Blueprint: superadmin | catalog-manager | sales-ops | preorder-ops |
                                      sourcing-ops | warehouse-staff | procurement-staff | finance |
                                      customer-support | analyst (kolom `code` yang lama diganti `name`)
  guard_name varchar(32) NOT NULL DEFAULT 'admin'   seluruh RBAC admin ber-guard `admin`, bukan default `web`
  description text
  is_system boolean NOT NULL DEFAULT false
  --
  uq roles_name_guard (name, guard_name)
  di-seed (Doc 10 §8), bukan hardcode. Pemisah "pengaju vs pemberi persetujuan"
  (Doc 06 §1) adalah kebutuhan policy + grant terpisah; *.request/*.approve tidak ada
  dalam 76 permission aktual dan tidak boleh ditambahkan tanpa perubahan register Doc 11.
  `superadmin` tidak lagi berarti bypass: bypass hanya lewat users.is_admin dan tercatat
  (Doc 11 §4.4 A/B). Bentuk kolom mengikuti paket supaya tidak perlu fork; kolom ekstra
  kita (description, is_system) tetap hidup.

permissions                               [Identity]      bentuk spatie/laravel-permission (Doc 11 §4.4 B)
  name varchar(64) NOT NULL           <modul>.<aksi> — mis. catalog.view, billing.void, order.force_transition
                                      (kolom `code` yang lama diganti `name`)
  guard_name varchar(32) NOT NULL DEFAULT 'admin'
  group_name varchar(40) NOT NULL      mis. billing, order, catalog (dulu kolom `group`)
  description text
  --
  uq permissions_name_guard (name, guard_name)
  idx permissions_group (group_name)
  Aksi terpisah: view (index/show), create, update, delete + aksi khusus register
  (billing.void, order.force_transition, batch.close, payment.mark_paid_manual).
  Prefix <modul> = kata benda domain tunggal kecil, bukan nama kelas modul; daftar
  kanoniknya di Doc 11 §4.4 B (pemilik daftar — dokumen ini mengutip, tidak menurunkan
  ulang). Aksi khusus hanya boleh ditambah lewat perubahan register itu, bukan diam-diam
  di seeder. Pemberian langsung ke user lewat model_has_permissions (mis. catalog.view
  tanpa create/update/delete). Storefront tidak memakai spatie sama sekali (Doc 11 §4.4 B).

model_has_roles                           [Identity]      pengganti pivot role_user yang lama (Doc 11 §4.4 B); bentuk spatie
  role_id bigint NOT NULL REFERENCES roles(id) ON DELETE CASCADE
  model_type varchar(120) NOT NULL     morph standar paket; satu-satunya model yang dipakai: user Identity
  model_id bigint NOT NULL             kolom morph paket — tidak disederhanakan menjadi user_id
  --
  PRIMARY KEY (role_id, model_id, model_type)
  idx model_has_roles_model (model_type, model_id)
  -- Scope per batch/kategori/gudang TIDAK lagi menempel di pivot ini: pindah ke role_scopes.

model_has_permissions                     [Identity]      pengganti permission_role yang lama (Doc 11 §4.4 B); pemberian langsung ke user
  permission_id bigint NOT NULL REFERENCES permissions(id) ON DELETE CASCADE
  model_type varchar(120) NOT NULL
  model_id bigint NOT NULL
  --
  PRIMARY KEY (permission_id, model_id, model_type)
  idx model_has_permissions_model (model_type, model_id)
  -- Pasangan role-permission pindah ke role_has_permissions; tabel ini melayani hak
  --   per orang (mis. catalog.view saja) tanpa harus membuat role baru per kombinasi.

role_has_permissions                      [Identity]      baru (Doc 11 §4.4 B); bundel permission per role
  permission_id bigint NOT NULL REFERENCES permissions(id) ON DELETE CASCADE
  role_id bigint NOT NULL REFERENCES roles(id) ON DELETE CASCADE
  -- PRIMARY KEY (permission_id, role_id)

role_scopes                               [Identity]      baru (Doc 11 §4.4 B); kolom scope dipindah dari pivot lama
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  role_id bigint NOT NULL REFERENCES roles(id) ON DELETE CASCADE
  scope_type varchar(24)                NULL = global; batch | category | warehouse
  scope_id bigint                        di-query app; bukan FK agar scope bebas bentuk
  --
  UNIQUE INDEX uq_role_scopes_identity (user_id, role_id, COALESCE(scope_type,''), COALESCE(scope_id,0))
  CHECK ck_role_scopes_type (scope_type IN ('batch','category','warehouse'))
  CHECK ck_role_scopes_pair  kedua kolom NULL atau tipe dan id terisi dengan id > 0
  idx_role_scopes_user (user_id)
  -- Satu pasangan user-role boleh punya scope global DAN beberapa scope berbaris
  --   (aturan COALESCE lama dipertahankan). Membatasi permission saja tidak membatasi
  --   baris mana yang boleh dilihat; scope tetap hidup lewat tabel ini.

user_identities                           [Identity]      baru (Doc 11 §4.4 C) — login sosial storefront (Google + X)
  provider varchar(24) NOT NULL          google | x (driver socialiteproviders/twitter, OAuth 2.0)
  provider_user_id varchar(120) NOT NULL  id subjek dari provider
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  linked_at timestamptz NOT NULL DEFAULT now()
  --
  uq user_identities_provider (provider, provider_user_id)
  idx user_identities_user (user_id)
  -- Penggabungan akun HANYA lewat email terverifikasi, bukan email mentah dari provider
  --   (kalau tidak, mendaftar di provider dengan email korban = pengambilalihan akun).
  -- Admin tidak punya jalur sosial (Doc 11 §4.4 A). Provider OIDC menyusul bila Q-2
  --   dijawab "klien punya IdP sendiri" (ADR-007 D-6a), bukan mengganti mekanisme sesi.

staff_2fa_secrets                         [Identity]      2FA admin wajib (Doc 01 §9.4; Doc 11 §4.4 A)
  user_id bigint NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE
  secret_encrypted bytea NOT NULL      terenkripsi dengan app key, jangan pernah plaintext
  confirmed_at timestamptz,
  recovery_hashes text[] NOT NULL DEFAULT '{}'   sepuluh recovery code ter-hash (argon2id); satu kode satu kali pakai,
                                                  entri yang dipakai dihapus. Dulu satu kolom `recovery_hash varchar(255)`
                                                  = hanya satu kode, yang berarti kehilangan 9 dari 10 kode setelah
                                                  pemulihan pertama. Verifikasi membandingkan setiap entri secara konstan.
  -- Migrasi 0075_Identity_wrap_legacy_recovery_hashes membungkus hash SHA-256 lama
  --   menjadi legacy-sha256$<argon2id>; bukan backfill flag pengguna.
```

## 3. Catalog

```
categories                                [Catalog]
  slug varchar(80) NOT NULL UNIQUE       8 nilai awal terverifikasi di Doc 08 §5
  name_key varchar(96) NOT NULL          key i18n → localizations (D6), bukan teks mentah
  parent_id bigint NULL REFERENCES categories(id) ON DELETE SET NULL
  icon_media_id bigint NULL REFERENCES media(id) ON DELETE SET NULL   aset terupload, bukan glyph (Doc 08 §5)
  image_alt_key varchar(96)              gerbang aksesibilitas Doc 04 §7
  position smallint NOT NULL DEFAULT 0
  is_active boolean NOT NULL DEFAULT true
  --
  CHECK (parent_id <> id)
  idx categories_tree (parent_id, position)

brands                                    [Catalog]       tabel baru (BP §6, Doc 11 §4.2)
  slug varchar(80) NOT NULL UNIQUE
  name varchar(120) NOT NULL
  logo_media_id bigint NULL REFERENCES media(id) ON DELETE SET NULL
  country varchar(2)                      ISO negara pembuat
  description_key text
  is_active boolean NOT NULL DEFAULT true
  --
  idx brands_active (is_active)

collections                               [Catalog]       sumbu kedua: fandom/IP (Doc 05 §2)
  slug varchar(80) NOT NULL UNIQUE, name_key varchar(96) NOT NULL
  kind varchar(24) NOT NULL DEFAULT 'fandom'    fandom | ip | kolaborasi | tema   (bukan pembeda populasi lama)
  banner_media_id bigint NULL REFERENCES media(id) ON DELETE SET NULL
  description_key text, is_active boolean NOT NULL DEFAULT true

products                                  [Catalog]
  slug varchar(120) NOT NULL UNIQUE
  name varchar(200) NOT NULL
  brand_id bigint NULL REFERENCES brands(id) ON DELETE SET NULL   dulu kolom brand/series string
  series varchar(120)
  category_id bigint NOT NULL REFERENCES categories(id) ON DELETE RESTRICT
  status varchar(16) NOT NULL DEFAULT 'draft'    draft | active | archived
  origin_country varchar(2) NOT NULL             kode ISO negara — terstruktur (Doc 05 §3 P-5)
  fulfillment_modes varchar(32)[] NOT NULL DEFAULT '{}'    {rts,po,request} — satu produk bisa beberapa mode
  unit_weight_g integer                          untuk estimasi ongkir; berat nyata dari goods receipt
  hs_code varchar(12)                            untuk estimasi bea masuk (D3)
  description text, care_notes text
  return_policy_notes text                       syarat retur per kategori (Doc 02 §8)
  meta_title varchar(120), meta_description varchar(300)
  deleted_at timestamptz
  --
  CHECK (origin_country ~ '^[A-Z]{2}$')
  idx products_cat_status (category_id, status)
  idx products_brand (brand_id) WHERE brand_id IS NOT NULL
  idx products_status (status) WHERE status = 'active'

product_collections                       [Catalog]
  product_id bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE
  collection_id bigint NOT NULL REFERENCES collections(id) ON DELETE CASCADE
  -- PRIMARY KEY (product_id, collection_id)

product_options                           [Catalog]       sumbu varian yang didefinisikan admin (BP §6 tidak menyebut; dipertahankan — Doc 11 §4.1 "tetap")
  product_id bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE
  name varchar(60) NOT NULL              "Ukuran", "Anggota", "Tipe kartu"
  position smallint NOT NULL DEFAULT 0
  -- uq product_options_name (product_id, name)

product_option_values                     [Catalog]
  option_id bigint NOT NULL REFERENCES product_options(id) ON DELETE CASCADE
  value varchar(60) NOT NULL
  position smallint NOT NULL DEFAULT 0
  -- uq option_value (option_id, value)

product_variants                          [Catalog]       rename variants (Doc 11 §4.1); 1 varian = 1 kombinasi yang bisa dibeli
  product_id bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE
  sku varchar(64) NOT NULL UNIQUE
  label varchar(160) NOT NULL            snapshot gabungan nilai utk tampilan ("XL · Jisoo · Holo")
  base_price bigint NOT NULL             harga dasar; harga jual nyata ada di preorder_batch_items / order_items (S-8)
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  track_stock boolean NOT NULL DEFAULT true       false untuk barang made-to-order per batch
  is_active boolean NOT NULL DEFAULT true
  deleted_at timestamptz
  --
  CHECK (base_price >= 0)
  idx product_variants_product (product_id) WHERE is_active

variant_option_values                     [Catalog]
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE
  option_value_id bigint NOT NULL REFERENCES product_option_values(id) ON DELETE RESTRICT
  -- PRIMARY KEY (variant_id, option_value_id)

product_media                             [Catalog]       tabel baru (BP §6, Doc 11 §4.2) — join ordering/caption ke media polimorfik
  product_id bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE
  media_id bigint NOT NULL REFERENCES media(id) ON DELETE CASCADE
  variant_id bigint NULL REFERENCES product_variants(id) ON DELETE SET NULL   media per varian bila perlu
  role varchar(16) NOT NULL DEFAULT 'gallery'      primary | gallery | detail | size_chart
  position smallint NOT NULL DEFAULT 0
  --
  uq product_media_slot (product_id, media_id)
  CHECK (role IN ('primary','gallery','detail','size_chart'))
  idx product_media_product (product_id, role, position)

price_lists                               [Catalog]       tabel baru (BP §6, Doc 11 §4.2)
  code varchar(32) NOT NULL UNIQUE       GENERAL | WHOLESALE | MEMBER | PROMO-IDR
  name varchar(120) NOT NULL
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  kind varchar(16) NOT NULL DEFAULT 'retail'      retail | wholesale | member | contract   (bukan pembeda populasi lama)
  valid_from timestamptz, valid_to timestamptz
  is_active boolean NOT NULL DEFAULT true
  --
  CHECK (valid_to IS NULL OR valid_to > valid_from)
  idx price_lists_active (is_active, currency_code)

price_list_entries                        [Catalog]       tabel baru (BP §6, Doc 11 §4.2)
  price_list_id bigint NOT NULL REFERENCES price_lists(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE CASCADE
  price bigint NOT NULL
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  --
  uq price_list_entry (price_list_id, variant_id)
  CHECK (price >= 0)
  idx price_list_entries_variant (variant_id)
```

## 4. PreOrder (batch sebagai kelas satu — D1)

```
preorder_batches                          [PreOrder]      rename po_batches (Doc 11 §4.1)
  code varchar(24) NOT NULL UNIQUE       "BATCH-2026-11"
  title varchar(140) NOT NULL
  status varchar(20) NOT NULL DEFAULT 'draft'
        draft | open | closed | received | cancelled_by_supplier | archived
  order_window_open_at timestamptz NOT NULL
  order_window_close_at timestamptz NOT NULL
  eta_min_date date, eta_max_date date          ETA yang ditampilkan ke buyer (Doc 07 butir 3)
  -- aturan uang per batch (Doc 01 §5 U3: bukan hardcode)
  deposit_kind varchar(12) NOT NULL DEFAULT 'percent'    percent | flat
  deposit_value bigint NOT NULL                          % ×100 (bp) atau rupiah
  balance_due_days integer NOT NULL DEFAULT 7
  grace_days integer NOT NULL DEFAULT 2
  forfeit_policy varchar(20) NOT NULL DEFAULT 'forfeit'  forfeit | voucher | extend_once   (B-PO-3)
  reminder_offsets integer[] NOT NULL DEFAULT '{7,1}'    hari sebelum jatuh tempo
  max_extensions smallint NOT NULL DEFAULT 1
  -- batas biaya internasional (A1: tagihan kejutan harus berbatas)
  intl_fee_min bigint NOT NULL DEFAULT 0
  intl_fee_max bigint NOT NULL DEFAULT 0
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  closed_at timestamptz, cancelled_at timestamptz, cancel_reason text
  created_by bigint REFERENCES users(id)
  --
  CHECK (order_window_close_at > order_window_open_at)
  CHECK (intl_fee_max >= intl_fee_min)
  CHECK (deposit_kind IN ('percent','flat'))
  CHECK (deposit_value >= 0)
  idx preorder_batches_status_window (status, order_window_close_at)

preorder_batch_items                      [PreOrder]      rename batch_offers (Doc 11 §4.1); penawaran + kuota DALAM satu batch (D1)
  batch_id bigint NOT NULL REFERENCES preorder_batches(id) ON DELETE RESTRICT
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  quota integer NOT NULL                          kuota lembut batch, bukan stok fisik
  reserved_qty integer NOT NULL DEFAULT 0         dijaga UPDATE atomic BP §8.5 (dulu taken_qty)
  price bigint NOT NULL                           snapshot harga pada batch ini (S-8)
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  price_valid_until timestamptz                   D7: harga bisa kedaluwarsa sebelum dikonfirmasi
  status varchar(16) NOT NULL DEFAULT 'open'      open | closed
  opens_at timestamptz, closes_at timestamptz     jendela kuota item (BP §8.5)
  origin_country varchar(2), origin_shop varchar(120)    dari toko/negara mana dibeli (Doc 05 §3 P-5)
  is_active boolean NOT NULL DEFAULT true
  --
  uq preorder_batch_items_slot (batch_id, variant_id)
  CHECK (quota >= 0 AND reserved_qty >= 0 AND reserved_qty <= quota)
  CHECK (price >= 0)
  CHECK (status IN ('open','closed'))
  idx preorder_batch_items_variant (variant_id) WHERE is_active
  -- BP §8.5 atomic quota (TOCTOU-safe; primary correctness control):
  --   UPDATE preorder_batch_items
  --      SET reserved_qty = reserved_qty + :qty
  --    WHERE id = :batch_item_id
  --      AND reserved_qty + :qty <= quota
  --      AND status = 'open'
  --      AND opens_at <= NOW()
  --      AND closes_at >  NOW()
  --   RETURNING id;                       -- 0 baris = kuota habis; enrollment ditolak

preorder_enrollments                      [PreOrder]      rename po_lines (Doc 11 §4.1); posisi satu order_item di sebuah batch
  batch_id bigint NOT NULL REFERENCES preorder_batches(id) ON DELETE RESTRICT
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE
  order_item_id bigint NOT NULL REFERENCES order_items(id) ON DELETE CASCADE
  batch_item_id bigint NOT NULL REFERENCES preorder_batch_items(id) ON DELETE RESTRICT
  qty integer NOT NULL CHECK (qty > 0)
  state varchar(24) NOT NULL DEFAULT 'reserved'
        reserved | deposit_paid | awaiting_balance | on_hold | fully_paid | received | shipped | released | expired | cancelled
  slot_reserved_at timestamptz NOT NULL
  slot_expires_at timestamptz NOT NULL            slot batchexpire, bukan stok fisik (Doc 03 Sprint 3)
  received_qty integer NOT NULL DEFAULT 0         partial goods receipt per item (Doc 06 §3.1)
  --
  uq preorder_enrollments_one_per_item (order_item_id, batch_item_id)
  idx preorder_enrollments_batch_state (batch_id, state)
  idx preorder_enrollments_expiry (slot_expires_at) WHERE state = 'reserved'

preorder_allocations                      [PreOrder]      tabel baru (BP §6, Doc 11 §4.2) — alokasi stok fisik yang TIBA ke enrollment
  batch_id bigint NOT NULL REFERENCES preorder_batches(id) ON DELETE RESTRICT
  enrollment_id bigint NOT NULL REFERENCES preorder_enrollments(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  warehouse_id bigint NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT
  qty_allocated integer NOT NULL CHECK (qty_allocated > 0)
  allocated_at timestamptz NOT NULL DEFAULT now()
  released_at timestamptz
  --
  uq preorder_allocations_enrollment_slot (enrollment_id, warehouse_id)
  idx preorder_allocations_batch (batch_id)

preorder_payment_plans                    [PreOrder]      rename po_payment_plans (Usulan §20 — prefik, Doc 11 §4.1 tidak menyebut eksplisit); rencana pelunasan per order
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE
  batch_id bigint NOT NULL REFERENCES preorder_batches(id) ON DELETE RESTRICT
  deposit_invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL
  balance_invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL
  due_at timestamptz NOT NULL
  reminder_state varchar(24) NOT NULL DEFAULT 'pending'   pending | h7_sent | h1_sent | due_sent
  extension_count smallint NOT NULL DEFAULT 0
  extended_by bigint REFERENCES users(id), extension_reason text
  executed_at timestamptz                          saat kebijakan jatuh tempo dijalankan
  --
  uq preorder_payment_plans_order_batch (order_id, batch_id)
```

## 5. Billing (modul baru — ADR-002)

```
invoices                                  [Billing]
  code varchar(24) NOT NULL UNIQUE       INV-2026-000123 (S-4)
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT
  type varchar(20) NOT NULL              deposit | balance | intl_fees | domestic_ship | extra_ship | adjustment
  amount bigint NOT NULL                 total baris; detail per-item ada di invoice_lines (BP §6)
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  status varchar(16) NOT NULL DEFAULT 'open'      open | paid | overdue | void | refunded
  reason varchar(320)                    WAJIB diisi bila type IN (adjustment, extra_ship) — lihat CHECK
  due_at timestamptz                     NULL untuk tagihan yang jatuh temponya tidak berlaku (adjustment instan)
  issued_at timestamptz NOT NULL DEFAULT now()
  paid_at timestamptz, voided_at timestamptz, void_reason varchar(320)
  reconsent_required boolean NOT NULL DEFAULT false    RS-4: nominal bergeser > ambang → buyer konfirmasi ulang
  reconsent_at timestamptz
  batch_id bigint REFERENCES preorder_batches(id) ON DELETE SET NULL
  created_by bigint REFERENCES users(id) NULL         NULL = dibuat sistem (job goods receipt)
  source varchar(16) NOT NULL DEFAULT 'system'        system | admin | job
  --
  CHECK (amount >= 0)
  CHECK (type IN ('deposit','balance','intl_fees','domestic_ship','extra_ship','adjustment'))
  CHECK (status IN ('open','paid','overdue','void','refunded'))
  CHECK (NOT (type IN ('adjustment','extra_ship')) OR reason IS NOT NULL)
  CHECK (reconsent_required = false OR status = 'open')
  uq invoices_one_deposit (order_id) WHERE type = 'deposit'      satu deposit per order
  uq invoices_one_balance (order_id) WHERE type = 'balance'      satu pelunasan pokok per order
  idx invoices_due (status, due_at) WHERE status IN ('open','overdue')   A2: query "cek jatuh tempo"
  idx invoices_order (order_id, type)
  idx invoices_batch (batch_id) WHERE batch_id IS NOT NULL

invoice_lines                             [Billing]       tabel baru (BP §6, Doc 11 §4.2) — rincian satu invoice per item/biaya
  invoice_id bigint NOT NULL REFERENCES invoices(id) ON DELETE CASCADE
  order_item_id bigint NULL REFERENCES order_items(id) ON DELETE RESTRICT   NULL = baris biaya non-item (ongkir/bea)
  variant_id bigint NULL REFERENCES product_variants(id) ON DELETE SET NULL
  description varchar(240) NOT NULL
  qty integer NOT NULL CHECK (qty > 0)
  unit_price bigint NOT NULL CHECK (unit_price >= 0)
  line_total bigint GENERATED ALWAYS AS (qty * unit_price) STORED
  charge_kind varchar(24) NOT NULL DEFAULT 'product'  product | shipping | intl_fee | tax | service_fee | adjustment
  currency_code char(3) NOT NULL DEFAULT 'IDR'        (B-13)
  exchange_rate_snapshot numeric(14,6) NOT NULL DEFAULT 1   (B-13)
  tax_rule_snapshot jsonb NOT NULL DEFAULT '{}'       (B-13)
  price_snapshot jsonb NOT NULL DEFAULT '{}'          (B-13)
  --
  CHECK (charge_kind IN ('product','shipping','intl_fee','tax','service_fee','adjustment'))
  CHECK (exchange_rate_snapshot > 0)
  idx invoice_lines_invoice (invoice_id)

invoice_allocations                       [Billing]       A3: satu intent gabungan → alokasi per invoice
  payment_intent_id bigint NOT NULL REFERENCES payment_intents(id) ON DELETE RESTRICT
  invoice_id bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT
  amount bigint NOT NULL CHECK (amount > 0)
  allocated_at timestamptz NOT NULL DEFAULT now()
  --
  uq alloc_intent_invoice (payment_intent_id, invoice_id)
  idx alloc_invoice (invoice_id)
  -- invariant (dijaga aggregate, bukan DB): SUM(allocation) = intent.amount

receivables                               [Billing]       tabel baru (BP §6, Doc 11 §4.2) — piutang terbuka per invoice
  invoice_id bigint NOT NULL UNIQUE REFERENCES invoices(id) ON DELETE CASCADE
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT
  amount_due bigint NOT NULL CHECK (amount_due >= 0)
  amount_paid bigint NOT NULL DEFAULT 0 CHECK (amount_paid >= 0)
  outstanding bigint GENERATED ALWAYS AS (amount_due - amount_paid) STORED
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  due_at timestamptz
  aging_bucket smallint GENERATED ALWAYS AS (CASE
        WHEN due_at IS NULL THEN NULL
        WHEN due_at >= now() THEN 0
        WHEN now() - due_at <= interval '7 days' THEN 1
        WHEN now() - due_at <= interval '14 days' THEN 2
        WHEN now() - due_at <= interval '30 days' THEN 3
        ELSE 4 END) STORED
  status varchar(16) NOT NULL DEFAULT 'open'   open | settled | written_off
  --
  CHECK (status IN ('open','settled','written_off'))
  CHECK (outstanding >= 0)
  idx receivables_user_status (user_id, status)
  idx receivables_due (due_at) WHERE status = 'open'
```

## 6. Orders

```
orders                                    [Orders]
  code varchar(24) NOT NULL UNIQUE       ELZ-2611-00042 (S-4)
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT
  order_type varchar(16) NOT NULL DEFAULT 'ready_stock'   ready_stock | preorder | request_order  (BP §5.4, B-3)
  order_status varchar(24) NOT NULL DEFAULT 'awaiting_payment'
        awaiting_payment | paid | processing | shipped | delivered | completed | on_hold |
        refund_requested | refund_pending | refunded | disputed | expired | cancelled
        (kanonik Doc 11 §8.3 — dokumen ini pemilik CHECK-nya; `pending_payment`→`awaiting_payment`;
        `confirmed` lama dilipat ke `paid` karena bedanya sudah dijawab payment_status;
        `awaiting_batch`/`ready_to_ship` pindah ke fulfillment_status di bawah)
  payment_status varchar(20) NOT NULL DEFAULT 'unpaid'
        unpaid | partially_paid | paid | overdue | refund_pending | refunded | disputed      (rename money_status, B-3; Doc 01 §6 sumbu 1)
  fulfillment_status varchar(24) NOT NULL DEFAULT 'unprocessed'
        unprocessed | reserved | awaiting_batch | at_warehouse | ready_to_ship | picking | packed |
        handed_over | in_transit | delivered | returned
        (penerima tahap lane — Doc 11 §8.3 urutan per lane; `customs` **keluar** dari sumbu ini,
        faktanya dijaga procurement_status: dua pemilik satu fakta = jejak yang bisa saling bertentangan)
  fulfillment_lane varchar(20) NOT NULL DEFAULT 'rts'
        rts | import_po | request | marketplace_purchase   (D4; `procurement` diganti → B-4)
  procurement_status varchar(24) NOT NULL DEFAULT 'not_required'
        not_required | sourcing | requisitioned | po_issued | supplier_confirmed | in_transit | customs |
        received | failed   (sumbu baru, B-3; Blueprint §5.4 menamai sumbunya tapi tidak enumerasi nilainya —
        enumerasi di bawah ini adalah keputusan dokumen ini, dikutip Doc 02 dan Doc 11 §8.3.
        `requisitioned` = `purchase_orders.status = draft|pending_approval`, bukan tabel `purchase_requisitions` (§20))
  subtotal bigint NOT NULL DEFAULT 0
  discount_total bigint NOT NULL DEFAULT 0
  shipping_total bigint NOT NULL DEFAULT 0
  service_fee_total bigint NOT NULL DEFAULT 0
  intl_fee_total bigint NOT NULL DEFAULT 0          snapshot hasil invoice intl_fees (S-5 tidak berlaku:
                                                    ini kolom dokumen order, diisi saat terbit, tidak dihitung ulang)
  total bigint NOT NULL DEFAULT 0
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  points_earned integer NOT NULL DEFAULT 0, points_redeemed integer NOT NULL DEFAULT 0
  price_lock_expires_at timestamptz                 D7
  placed_at timestamptz, paid_at timestamptz
  cancelled_at timestamptz, cancel_reason varchar(320)
  quote_id bigint REFERENCES quotations(id) ON DELETE SET NULL
  checkout_session_id bigint REFERENCES checkout_sessions(id) ON DELETE SET NULL
  paid_stock_exception varchar(20) NOT NULL DEFAULT 'none'   (B-6: pembayaran sah setelah stok dilepas)
        none | pending | re_reserving | restocked | refunded | reallocated | manual_review | resolved
  paid_stock_exception_at timestamptz
  notes text, admin_notes text
  --
  CHECK (order_type IN ('ready_stock','preorder','request_order'))
  CHECK (order_status IN ('awaiting_payment','paid','processing','shipped','delivered','completed','on_hold','refund_requested','refund_pending','refunded','disputed','expired','cancelled'))
  CHECK (payment_status IN ('unpaid','partially_paid','paid','overdue','refund_pending','refunded','disputed'))
  CHECK (fulfillment_status IN ('unprocessed','reserved','awaiting_batch','at_warehouse','ready_to_ship','picking','packed','handed_over','in_transit','delivered','returned'))
  CHECK (fulfillment_lane IN ('rts','import_po','request','marketplace_purchase'))
  CHECK (procurement_status IN ('not_required','sourcing','requisitioned','po_issued','supplier_confirmed','in_transit','customs','received','failed'))
  CHECK (paid_stock_exception IN ('none','pending','re_reserving','restocked','refunded','reallocated','manual_review','resolved'))
  CHECK (paid_stock_exception = 'none' OR paid_stock_exception_at IS NOT NULL)
  CHECK (subtotal >= 0 AND total >= 0 AND discount_total >= 0)
  idx orders_user_placed (user_id, placed_at DESC)
  idx orders_type (order_type)
  idx orders_order_status (order_status) WHERE order_status IN ('paid','processing','shipped','on_hold','refund_requested','disputed')
  idx orders_fulfillment_open (fulfillment_status) WHERE fulfillment_status IN ('awaiting_batch','at_warehouse','ready_to_ship')
                                             -- dua dari tiga nilai ini pernah hidup di order_status (Doc 10 §6.1)
  idx orders_paid_stock_exception (paid_stock_exception) WHERE paid_stock_exception <> 'none'

order_items                               [Orders]
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  batch_offer_id bigint REFERENCES preorder_batch_items(id) ON DELETE SET NULL    NULL = jalur RTS
  fulfillment_mode varchar(16) NOT NULL   rts | po | request | marketplace_purchase
  qty integer NOT NULL CHECK (qty > 0)
  unit_price bigint NOT NULL              snapshot (S-8)
  currency_code char(3) NOT NULL DEFAULT 'IDR'        (B-13)
  exchange_rate_snapshot numeric(14,6) NOT NULL DEFAULT 1   (B-13)
  tax_rule_snapshot jsonb NOT NULL DEFAULT '{}'       (B-13)
  price_snapshot jsonb NOT NULL DEFAULT '{}'          (B-13) konteks harga penuh saat order
  product_name_snapshot varchar(200) NOT NULL      jangan join ke Catalog untuk cetak invoice (B5/S-8)
  variant_label_snapshot varchar(160) NOT NULL
  discount_amount bigint NOT NULL DEFAULT 0
  line_total bigint GENERATED ALWAYS AS (qty * unit_price - discount_amount) STORED
  split_group varchar(24)                  U4: baris dengan grup sama digabung ke shipment yang sama
  --
  CHECK (fulfillment_mode IN ('rts','po','request','marketplace_purchase'))
  CHECK (exchange_rate_snapshot > 0)
  idx order_items_order (order_id)
  idx order_items_variant (variant_id)

order_charges                             [Orders]        tabel baru (BP §6, Doc 11 §4.2) — biaya tambahan per order (ongkir, service fee, bea)
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE
  kind varchar(24) NOT NULL               shipping | service_fee | intl_fee | customs | insurance | packing   (bukan pembeda populasi lama)
  description varchar(200) NOT NULL
  amount bigint NOT NULL CHECK (amount >= 0)
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL   saat biaya sudah ditagihkan
  occurred_at timestamptz NOT NULL DEFAULT now()
  --
  CHECK (kind IN ('shipping','service_fee','intl_fee','customs','insurance','packing'))
  idx order_charges_order (order_id, kind)

order_adjustments                         [Orders]        tabel baru (BP §6, Doc 11 §4.2) — koreksi manual/negosiasi atas order
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE
  direction varchar(6) NOT NULL           debit | credit
  amount bigint NOT NULL CHECK (amount > 0)
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  reason varchar(320) NOT NULL             wajib (Doc 01 §3.3 AuditThis)
  adjusted_by bigint NOT NULL REFERENCES users(id)
  approved_by bigint REFERENCES users(id)
  occurred_at timestamptz NOT NULL DEFAULT now()
  reverses_id bigint REFERENCES order_adjustments(id)
  --
  CHECK (direction IN ('debit','credit'))
  CHECK (approved_by IS NULL OR approved_by <> adjusted_by)   pemisah pengaju vs pemberi setuju (Doc 06 §1)
  idx order_adjustments_order (order_id)

order_addresses                           [Orders]       D7 snapshot alamat, satu per order
  order_id bigint NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE
  source_address_id bigint NULL            referensi asal untuk audit, TIDAK dipakai untuk membaca
  recipient varchar(120) NOT NULL, phone varchar(24) NOT NULL
  province varchar(60) NOT NULL, city varchar(60) NOT NULL
  district varchar(60), village varchar(60), postal_code varchar(10) NOT NULL
  address_line text NOT NULL
  lat numeric(9,6), lng numeric(9,6)
  snapshot_at timestamptz NOT NULL DEFAULT now()

shipments                                 [Fulfillment]  1 order → n shipment per lane (U4/D4); pemilik kolomnya Orders (`order_id`), pemilik tabelnya Fulfillment (Blueprint §3)
  code varchar(24) NOT NULL UNIQUE       SHP-...
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT
  lane varchar(20) NOT NULL              rts | import_po | request | marketplace_purchase
  stage_id bigint REFERENCES lane_stages(id) ON DELETE SET NULL    tahap saat ini, turunan (S-5: hanya aggregate menulis)
  courier_code varchar(32), service_code varchar(32)
  tracking_number varchar(80), tracking_url varchar(400)
  status varchar(24) NOT NULL DEFAULT 'pending'    pending | label_created | handed_over | in_transit | delivered | failed | returned
  estimated_delivery_date date, shipped_at timestamptz, delivered_at timestamptz
  weight_g integer, dims_cm varchar(24)
  --
  CHECK (lane IN ('rts','import_po','request','marketplace_purchase'))
  idx shipments_order (order_id)
  idx shipments_tracking (tracking_number) WHERE tracking_number IS NOT NULL
  idx shipments_stage (lane, stage_id)

shipment_order_items                      [Fulfillment]  isi shipment (hubungan banyak-ke-banyak)
  shipment_id bigint NOT NULL REFERENCES shipments(id) ON DELETE CASCADE
  order_item_id bigint NOT NULL REFERENCES order_items(id) ON DELETE RESTRICT
  qty integer NOT NULL CHECK (qty > 0)
  -- PRIMARY KEY (shipment_id, order_item_id)

order_events                              [Orders]       append-only (S-6); diproyeksikan jadi view order_status_history (§18)
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE
  actor_kind varchar(12) NOT NULL        system | customer | staff
  actor_id bigint, from_status varchar(24), to_status varchar(24)
  payment_from varchar(20), payment_to varchar(20)   rename money_from/to (B-3)
  reason varchar(320)                    wajib untuk transisi manual
  payload jsonb, occurred_at timestamptz NOT NULL
  reverses_id bigint REFERENCES order_events(id)
  --
  idx order_events_order_time (order_id, occurred_at)

refunds                                   [Payments]     Blueprint §3 menaruh refund di Billing & Payment; barisnya tetap menunjuk `orders` + `invoices`
  code varchar(24) NOT NULL UNIQUE
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT
  invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL
  amount bigint NOT NULL CHECK (amount >= 0)
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  status varchar(20) NOT NULL DEFAULT 'requested'
        requested | approved | rejected | processing | completed | failed
  reason varchar(320) NOT NULL, kind varchar(20) NOT NULL   full | partial | goodwill | shipping_error   (bukan pembeda populasi lama)
  provider_refund_ref varchar(80)
  requested_by bigint REFERENCES users(id), approved_by bigint REFERENCES users(id)
  approved_at timestamptz, completed_at timestamptz
  --
  CHECK (approved_by IS NULL OR approved_by <> requested_by)     pemisah pengaju vs pemberi setuju (Doc 06 §1)
  idx refunds_status (status) WHERE status NOT IN ('completed','rejected')

returns                                   [Orders]       tabel baru (Doc 11 §4.2 — Blueprint §4, grup Sales punya submenu "Returns")
  code varchar(24) NOT NULL UNIQUE       RET-... (S-4)
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT
  requested_by bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT   customer pengaju
  decided_by bigint REFERENCES users(id) ON DELETE SET NULL              staff pemutus; NULL = belum diputus
  status varchar(20) NOT NULL DEFAULT 'requested'
        requested | approved | in_transit | received | refunded | rejected
        (siklus pengajuan → keputusan → perjalanan fisik → uang; TIDAK sama dengan siklus refunds.status, lihat catatan refund_id)
  reason_code varchar(24) NOT NULL       wrong_size | defective | not_as_described | damaged_in_transit |
                                         wrong_item | changed_mind | other
  reason_note text                        penjelasan customer; wajib diisi bila reason_code = 'other'
  customer_expected varchar(16) NOT NULL DEFAULT 'refund'   refund | replace
  refund_id bigint REFERENCES refunds(id) ON DELETE SET NULL
        HANYA tautan, bukan salinan. Retur = peristiwa fisik + persetujuan; refund = peristiwa uang (Doc 12 §7).
        Digabung satu tabel membuat "retur disetujui tapi refund ditolak" tidak bisa diwakili.
        Jalur order_status `refund_requested` → `refund_pending` → `refunded` (Doc 11 §8.3) bergantung baris ini.
  return_tracking_number varchar(80), carrier_code varchar(32)   barang kembali dari customer (lane balik, bukan shipments baru)
  requested_at timestamptz NOT NULL DEFAULT now(), approved_at timestamptz,
  received_at timestamptz, closed_at timestamptz
  decision_note varchar(320)              alasan keputusan; wajib bila status = 'rejected' (Doc 06 §3.4)
  --
  CHECK (status IN ('requested','approved','in_transit','received','refunded','rejected'))
  CHECK (customer_expected IN ('refund','replace'))
  CHECK (reason_code IN ('wrong_size','defective','not_as_described','damaged_in_transit','wrong_item','changed_mind','other'))
  CHECK (status = 'requested' OR (decided_by IS NOT NULL AND approved_at IS NOT NULL))    tidak ada tahap pasca-pengajuan tanpa keputusan
  CHECK (status <> 'rejected' OR decision_note IS NOT NULL)
  CHECK (status NOT IN ('received','refunded') OR received_at IS NOT NULL)                barang diterima = fakta fisik, bukan asumsi
  idx returns_order (order_id)
  idx returns_open (status) WHERE status NOT IN ('refunded','rejected')
  idx returns_user (requested_by, requested_at DESC)
  -- Bukti/foto retur TIDAK punya tabel lampiran sendiri: dipakai `media` polimorfik (S-3) dengan
  --   model_type = 'App\Models\Return' + kind = 'proof' (Doc 11 §4.2 footnote untuk request_attachments).
  -- Jejak audit: siapa mengubah status dicatat audit_logs + order_events (S-6); status itu sendiri tidak mutable di tabel lain.
  -- Tidak ada back-pointer orders.return_id: orders → returns tetap satu arah supaya graf FK asiklik (Doc 10 §1 M-5).

return_items                              [Orders]       isi retur, satu baris per baris order yang dikembalikan
  return_id bigint NOT NULL REFERENCES returns(id) ON DELETE CASCADE
  order_item_id bigint NOT NULL REFERENCES order_items(id) ON DELETE RESTRICT
  qty_requested integer NOT NULL CHECK (qty_requested > 0)
  qty_approved integer NOT NULL DEFAULT 0 CHECK (qty_approved >= 0)
  qty_received integer NOT NULL DEFAULT 0 CHECK (qty_received >= 0)
  condition_code varchar(20)              sellable | damaged | quarantined | scrapped — diisi saat barang tiba;
                                          menentukan kolom stok mana yang naik (B-5) dan ditulis lewat Command
                                          Inventory (K1-only), tidak pernah UPDATE stok dari sisi Orders (B1/B5)
  note varchar(320)
  --
  CHECK (qty_approved <= qty_requested)
  CHECK (condition_code IS NULL OR condition_code IN ('sellable','damaged','quarantined','scrapped'))
  CHECK (qty_received <= qty_approved)
  uq return_line (return_id, order_item_id)
  idx return_items_order_item (order_item_id)
  -- Tautan ke ledger stok tidak disimpan di sini: `stock_movements.ref_type='return_item' + ref_id`
  --   dengan `reason='sale_return'` sudah melayani fakta yang sama (S-3 untuk ledger, B1 satu-fakta-satu-tempat).
```

## 7. Inventory (B-5 — agregat + ledger, BP §6.1)

```
warehouses                                [Inventory]     rename stock_locations (Doc 11 §4.1)
  code varchar(24) NOT NULL UNIQUE, name varchar(120) NOT NULL
  kind varchar(16) NOT NULL              overseas_hub | in_transit | warehouse | supplier   (bukan pembeda populasi lama)
  country char(2) NOT NULL DEFAULT 'CN'
  is_active boolean NOT NULL DEFAULT true
  --
  CHECK (kind IN ('overseas_hub','in_transit','warehouse','supplier'))

stock_items                               [Inventory]     tabel baru (BP §6.1, Doc 11 §4.2) — kebenaran atomic reservasi
  warehouse_id   bigint NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT
  variant_id     bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  on_hand        integer NOT NULL DEFAULT 0
  reserved       integer NOT NULL DEFAULT 0
  damaged        integer NOT NULL DEFAULT 0
  quarantined    integer NOT NULL DEFAULT 0
  in_transit     integer NOT NULL DEFAULT 0
  available_to_sell integer GENERATED ALWAYS AS (on_hand - reserved - (damaged + quarantined)) STORED
  min_stock      integer NOT NULL DEFAULT 0
  --
  uq stock_items_slot (warehouse_id, variant_id)          satu baris per (warehouse, variant) — BP §6.1
  CHECK (on_hand >= 0 AND reserved >= 0 AND damaged >= 0 AND quarantined >= 0 AND in_transit >= 0)
  CHECK (reserved + damaged + quarantined <= on_hand + in_transit)
  CHECK (available_to_sell = on_hand - reserved - (damaged + quarantined))
  -- `unavailable` = damaged + quarantined (BP §6.1). TIDAK ada kolom bebas ke-5 (Doc 11 B-5).
  -- BP §8.1 atomic reservation dengan conditional UPDATE (primary correctness control):
  --   BEGIN;
  --   UPDATE stock_items
  --      SET reserved = reserved + :qty, updated_at = NOW()
  --    WHERE warehouse_id = :warehouse_id
  --      AND sku_id   = :sku_id                    -- sku_id = product_variants.id di kita
  --      AND on_hand - reserved - (damaged + quarantined) >= :qty
  --   RETURNING id, reserved;                      -- 0 baris = out of stock -> ROLLBACK
  --   INSERT INTO stock_reservations (order_id, variant_id, warehouse_id, qty, status, expires_at)
  --        VALUES (:order_id, :sku_id, :warehouse_id, :qty, 'held', :expires_at);
  --   COMMIT;                                      -- order + outbox event pada transaksi yang sama
  idx stock_items_warehouse (warehouse_id) WHERE (on_hand - reserved) < min_stock

stock_movements                           [Inventory]     append-only (S-6) — ledger immutable, TIDAK diubah jadi agregat
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  warehouse_id bigint NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT
  qty_delta integer NOT NULL             + masuk (goods receipt), - keluar (kirim), pembatalan = entri balik
  component varchar(16) NOT NULL         on_hand | reserved | damaged | quarantined | in_transit   kolom mana yang digeser
  reason varchar(28) NOT NULL
        goods_receipt | goods_receipt_reversal | sale_ship | sale_return | adjustment | damage | quarantine | transfer_in | transfer_out
  ref_type varchar(40), ref_id bigint     mis. goods_receipt / shipment
  actor_id bigint REFERENCES users(id)
  occurred_at timestamptz NOT NULL, reverses_id bigint REFERENCES stock_movements(id)
  --
  CHECK (qty_delta <> 0)
  CHECK (component IN ('on_hand','reserved','damaged','quarantined','in_transit'))
  idx stock_movements_variant (variant_id, occurred_at DESC)
  idx stock_movements_ref (ref_type, ref_id)

stock_reservations                        [Inventory]     rename reservations (Doc 11 §4.1); stok fisik ditahan, bukan slot batch
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  warehouse_id bigint NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT
  order_item_id bigint REFERENCES order_items(id) ON DELETE CASCADE
  qty integer NOT NULL CHECK (qty > 0)
  status varchar(16) NOT NULL DEFAULT 'held'     held | committed | released | expired
  expires_at timestamptz NOT NULL               TTL: "stok reservasi lupa" (Doc 01 §12, B-6)
  committed_at timestamptz, released_at timestamptz
  --
  uq stock_reservations_once (order_item_id, warehouse_id) WHERE status IN ('held','committed')   anti dobel reserve (BP §8.1)
  idx stock_reservations_expiry (expires_at) WHERE status = 'held'
  idx stock_reservations_variant (variant_id, status)
  -- invariant (dijaga ReserveStock + atomic UPDATE stock_items, bukan trigger):
  --   SUM(held+committed qty) per (variant,warehouse) <= on_hand

stock_transfers                           [Inventory]     tabel baru (BP §6.1, Doc 11 §4.2) — gerak stok antar gudang
  code varchar(24) NOT NULL UNIQUE
  from_warehouse_id bigint NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT
  to_warehouse_id bigint NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  qty integer NOT NULL CHECK (qty > 0)
  status varchar(16) NOT NULL DEFAULT 'draft'   draft | in_transit | received | cancelled
  dispatched_at timestamptz, received_at timestamptz
  created_by bigint REFERENCES users(id)
  --
  CHECK (from_warehouse_id <> to_warehouse_id)
  CHECK (status IN ('draft','in_transit','received','cancelled'))
  idx stock_transfers_from (from_warehouse_id, status)

stocktakes                                [Inventory]     tabel baru (BP §6.1, Doc 11 §4.2) — opname fisik vs sistem
  code varchar(24) NOT NULL UNIQUE
  warehouse_id bigint NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT
  status varchar(16) NOT NULL DEFAULT 'open'    open | counted | posted | cancelled
  started_at timestamptz NOT NULL DEFAULT now(), finished_at timestamptz
  counted_json jsonb NOT NULL DEFAULT '[]'      [{variant_id, system_qty, counted_qty, diff}] — hasil opname
  posted_by bigint REFERENCES users(id), notes text
  --
  CHECK (status IN ('open','counted','posted','cancelled'))
  idx stocktakes_warehouse_status (warehouse_id, status)
```

## 8. Cart

```
carts                                     [Cart]
  token varchar(32) UNIQUE               cart guest; terangkat ke user_id saat login
  user_id bigint REFERENCES users(id) ON DELETE CASCADE
  status varchar(16) NOT NULL DEFAULT 'active'    active | converted | abandoned | expired
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  expires_at timestamptz NOT NULL        TTL cart (Doc 01 §12)
  converted_order_id bigint              PLAIN (tanpa REFERENCES): jejak konversi; FK di-break agar graf asiklik (Doc 10 §1 M-5)
  --
  CHECK (user_id IS NOT NULL OR token IS NOT NULL)
  idx carts_expiry (expires_at) WHERE status = 'active'

cart_items                                [Cart]
  cart_id bigint NOT NULL REFERENCES carts(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  batch_offer_id bigint NULL             P1.04: FK ke preorder_batch_items ditunda sampai modul PreOrder tersedia
  qty integer NOT NULL CHECK (qty > 0)
  unit_price_snapshot bigint NOT NULL     harga saat ditambahkan; divalidasi ulang saat checkout (D7)
  added_at timestamptz NOT NULL DEFAULT now()
  --
  uq cart_item_line (cart_id, variant_id, COALESCE(batch_offer_id,0))   unique on expression

checkout_sessions                         [Cart]          tabel baru (BP §6, Doc 11 §4.2) — snapshot pra-order (B-13)
  code varchar(32) NOT NULL UNIQUE       cs_... (dipakai idempotent pada POST /orders)
  user_id bigint REFERENCES users(id) ON DELETE SET NULL   NULL = guest
  cart_id bigint REFERENCES carts(id) ON DELETE SET NULL
  status varchar(16) NOT NULL DEFAULT 'open'   open | locked | converted | expired
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  exchange_rate_snapshot numeric(14,6) NOT NULL DEFAULT 1   (B-13)
  subtotal bigint NOT NULL DEFAULT 0
  shipping_estimate bigint NOT NULL DEFAULT 0
  tax_estimate bigint NOT NULL DEFAULT 0
  snapshot jsonb NOT NULL DEFAULT '{}'    salinan baris + tarif terkunci — dihitung ulang server-side (BP §7)
  expires_at timestamptz NOT NULL
  converted_order_id bigint              PLAIN (tanpa REFERENCES): jejak; FK di-break (graf asiklik)
  --
  CHECK (status IN ('open','locked','converted','expired'))
  CHECK (exchange_rate_snapshot > 0)
  idx checkout_sessions_expiry (expires_at) WHERE status IN ('open','locked')

  P1.04 quote: semua grup mode/ETA memakai paket_besar (parcel standar), berat
  unit_weight_g × qty dari snapshot. Berat kosong → SHIPPING_WEIGHT_MISSING.
  Estimasi ongkir = jumlah tarif termurah tiap grup; pilihan kurir UI sementara
  sampai konfirmasi ongkir/order di P1.05 (belum ada reservasi stok/pembayaran).

wishlist_items                            [Cart]         tabel baru (Doc 11 §4.2) — **bukan** Blueprint; turunan produk Doc 05 M3 ("favorit" / "kabari saya" di PDP)
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  position smallint NOT NULL DEFAULT 0    urutan manual customer; tidak memengaruhi harga maupun stok
  note varchar(200)                       catatan pribadi ("ukuran anak", "beli bulan depan") — boleh kosong
  added_at timestamptz NOT NULL DEFAULT now()
  --
  uq wishlist_user_variant (user_id, variant_id)   satu customer satu kali per varian; sisipan ganda → 409 (Doc 12 §2.2 kelompok F)
  idx wishlist_user_position (user_id, position)
  idx wishlist_variant (variant_id)                minat per varian untuk Reporting, dibaca dari tabelnya sendiri, tidak dari join cart
  -- Dinyatakan sebagai **ekstensi**, bukan keharusan Blueprint (Doc 11 §4.2): nama ini tidak ada di §6 Blueprint,
  --   diadopsi karena Blueprint menyebut daftar tabelnya "minimum", bukan maksimum.
  -- Tidak menyentuh jalur uang/stok: menyimpan favorit tidak memesan apa pun (B5); ketersediaan dibaca lewat v_stock_position (§18).
```

## 9. Payments

```
payment_intents                           [Payments]
  code varchar(24) NOT NULL UNIQUE       PI-...
  invoice_id bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT    ADR-002: kunci = invoice
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT        redundan untuk query cepat
  group_token varchar(32)                beberapa invoice → 1 intent gabungan (A3); NULL = 1:1
  provider varchar(24) NOT NULL          midtrans | xendit | tripay | manual
  method varchar(32)                     qris | bca_va | permata_va | gopay | manual_transfer
  amount bigint NOT NULL, currency_code char(3) NOT NULL DEFAULT 'IDR'
  status varchar(20) NOT NULL DEFAULT 'pending'
        pending | requires_action | authorized | captured | void | expired | failed
  provider_ref varchar(120), provider_payment_id varchar(120)
  expires_at timestamptz NOT NULL        U2 default 2 jam untuk RTS
  captured_at timestamptz, failure_reason varchar(320)
  raw_create_response jsonb
  --
  CHECK (amount > 0)
  CHECK (status IN ('pending','requires_action','authorized','captured','void','expired','failed'))
  idx intents_invoice (invoice_id) WHERE status <> 'expired'
  idx intents_expiry (expires_at) WHERE status IN ('pending','requires_action')
  idx intents_provider (provider, provider_ref)

payment_transactions                      [Payments]
  intent_id bigint NOT NULL REFERENCES payment_intents(id) ON DELETE CASCADE
  kind varchar(20) NOT NULL              auth | capture | cancel | refund | refund_partial | settle | void   (bukan pembeda populasi lama)
  status varchar(16) NOT NULL            pending | succeeded | failed
  amount bigint NOT NULL, currency_code char(3) NOT NULL DEFAULT 'IDR'
  provider_ref varchar(120), occurred_at timestamptz NOT NULL
  raw jsonb
  --
  idx txn_intent (intent_id, occurred_at)

payments                                  [Payments]      tabel baru (BP §6, Doc 11 §4.2) — uang yang benar-benar diterima
  code varchar(24) NOT NULL UNIQUE
  intent_id bigint REFERENCES payment_intents(id) ON DELETE SET NULL
  invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL   pembayaran langsung tanpa intent (manual)
  provider varchar(24) NOT NULL, method varchar(32)
  gross_amount bigint NOT NULL, fee_amount bigint NOT NULL DEFAULT 0, net_amount bigint NOT NULL
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  status varchar(16) NOT NULL DEFAULT 'captured'    captured | refunded | partial_refund | void
  captured_at timestamptz NOT NULL DEFAULT now()
  provider_payment_id varchar(120)
  raw jsonb
  --
  CHECK (net_amount = gross_amount - fee_amount)
  CHECK (status IN ('captured','refunded','partial_refund','void'))
  uq payments_provider_payment (provider, provider_payment_id) WHERE provider_payment_id IS NOT NULL
  idx payments_invoice (invoice_id)

payment_ledger                            [Payments]     append-only (S-6), kunci invoice (ADR-002)
  entry_no bigint GENERATED ALWAYS AS IDENTITY UNIQUE
  invoice_id bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT
  intent_id bigint REFERENCES payment_intents(id) ON DELETE SET NULL
  direction varchar(6) NOT NULL          debit | credit
  amount bigint NOT NULL CHECK (amount > 0)
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  source varchar(16) NOT NULL            gateway | manual | adjustment | reversal | refund
  evidence_media_id bigint REFERENCES media(id) ON DELETE SET NULL   wajib untuk source=manual (A4)
  recorded_by bigint REFERENCES users(id), approved_by bigint REFERENCES users(id)
  occurred_at timestamptz NOT NULL, reverses_entry_no bigint REFERENCES payment_ledger(entry_no)
  memo varchar(320)
  --
  CHECK (direction IN ('debit','credit'))
  CHECK (source IN ('gateway','manual','adjustment','reversal','refund'))
  CHECK (source <> 'manual' OR evidence_media_id IS NOT NULL)          A4: wajib bukti
  CHECK (approved_by IS NULL OR recorded_by IS NULL OR approved_by <> recorded_by)   A4: persetujuan kedua
  idx ledger_invoice (invoice_id, occurred_at)
  idx ledger_time (occurred_at)                                          rekonsiliasi harian (U5)

webhook_receipts                          [Payments]     conversion dari webhook_events (Doc 11 B-7)
  provider varchar(24) NOT NULL, provider_event_id varchar(120) NOT NULL
  provider_payment_id varchar(120)        referensi pembayaran provider (BP §6 UNIQUE(provider, provider_payment_id))
  body jsonb NOT NULL                    payload mentah, utuh (Doc 01 §8)
  signature_valid boolean NOT NULL, headers jsonb
  request_id varchar(26)                        dari provider_event_id, bukan dari header (Doc 12 §3.2)
  received_at timestamptz NOT NULL DEFAULT now()
  status varchar(16) NOT NULL DEFAULT 'received'   received | processed | ignored | failed
  processed_at timestamptz, error text, intent_id bigint REFERENCES payment_intents(id) ON DELETE SET NULL
  --
  uq webhook_receipt_once (provider, provider_event_id)          idempotensi, Dilarang ganda
  idx webhook_status (status, received_at)

reconciliation_runs                       [Payments]
  run_date date NOT NULL, provider varchar(24) NOT NULL
  expected_total bigint, actual_total bigint, diff_total bigint
  status varchar(16) NOT NULL DEFAULT 'open'    open | cleared | escalated
  report_media_id bigint REFERENCES media(id) ON DELETE SET NULL
  reviewed_by bigint REFERENCES users(id), reviewed_at timestamptz, resolution_note text
  --
  uq recon_day_provider (run_date, provider)          U5: tidak ada auto-adjust, selalu lewat manusia

reconciliation_items                      [Payments]
  run_id bigint NOT NULL REFERENCES reconciliation_runs(id) ON DELETE CASCADE
  kind varchar(20) NOT NULL              missing_in_ledger | missing_in_provider | amount_mismatch | duplicate   (bukan pembeda populasi lama)
  ledger_entry_no bigint REFERENCES payment_ledger(entry_no)
  provider_ref varchar(120), expected_amount bigint, actual_amount bigint
  --
  idx recon_items_run (run_id, kind)
```

## 10. Fulfillment (domestik) & Logistik Internasional

```
courier_services                          [Fulfillment]
  courier_code varchar(32) NOT NULL      jne | jnt | tiki | siio | icp | manual
  service_code varchar(32) NOT NULL      YES, JTR, ...
  country char(2) NOT NULL DEFAULT 'ID'
  name varchar(80) NOT NULL
  supports_label boolean NOT NULL DEFAULT false
  supports_tracking boolean NOT NULL DEFAULT true
  is_active boolean NOT NULL DEFAULT true
  -- uq courier_service (courier_code, service_code, country)

shipping_tariff_rules                     [Fulfillment]      Doc 06 §3.5: tarif per tier paket
  courier_code varchar(32) NOT NULL, service_code varchar(32) NOT NULL
  origin_country char(2) NOT NULL DEFAULT 'ID'
  dest_province varchar(60) NOT NULL DEFAULT '*'      '*' = semua
  package_tier varchar(24) NOT NULL        kemeja | kaos | figure | buku | paket_besar
  weight_from_g integer NOT NULL, weight_to_g integer
  base_amount bigint NOT NULL, per_500g_amount bigint NOT NULL DEFAULT 0
  effective_from date NOT NULL, effective_to date
  created_by bigint NOT NULL REFERENCES users(id)
  --
  CHECK (weight_to_g IS NULL OR weight_to_g > weight_from_g)
  CHECK (effective_to IS NULL OR effective_to >= effective_from)
  idx tariff_lookup (origin_country, dest_province, courier_code, service_code, weight_from_g)
  -- tumpang tindih rentang dicegah oleh aplikasi + test, bukan DB (rentang bisa bersentuhan)

shipping_rates_cache                      [Fulfillment]
  cache_key varchar(160) NOT NULL UNIQUE     origin|dest|weight|tier|courier
  amount bigint NOT NULL, provider varchar(24) NOT NULL
  rates jsonb NOT NULL                       ekstensi P1.04: daftar lengkap layanan, mata uang, dan ETA nullable; satu baris per request. amount = tarif minimum (0 untuk daftar kosong)
  fetched_at timestamptz NOT NULL, expires_at timestamptz NOT NULL
  -- idx rates_expiry (expires_at)

  -- P1.04: dest pada cache_key adalah SHA-256 tujuan lengkap + namespace driver/config (termasuk origin postcode), agar panjang <=160 dan tidak bertabrakan lintas provider.
  -- Manual: rentang berat [weight_from_g, weight_to_g), tanggal efektif inklusif; provinsi spesifik mengalahkan '*'.
  -- Tarif = base_amount + ceil((berat - weight_from_g)/500) * per_500g_amount. ETA Manual null; cache default 30 menit.

packages                                  [Fulfillment]      rename parcels (Doc 11 §4.1)
  shipment_id bigint NOT NULL REFERENCES shipments(id) ON DELETE CASCADE
  label_media_id bigint REFERENCES media(id) ON DELETE SET NULL    PDF label
  waybill_number varchar(80)
  weight_g integer, length_cm smallint, width_cm smallint, height_cm smallint
  declared_value bigint, insurance_amount bigint
  created_at timestamptz NOT NULL DEFAULT now()
  -- uq packages_shipment (shipment_id)

tracking_lanes                            [Fulfillment]      D4: 4 jalur, admin bisa kelola
  code varchar(20) NOT NULL UNIQUE        rts | import_po | request | marketplace_purchase
  name_key varchar(80) NOT NULL
  is_active boolean NOT NULL DEFAULT true

lane_stages                               [Fulfillment]      tahap per lane, dikelola admin (Doc 06 §3.5)
  lane varchar(20) NOT NULL REFERENCES tracking_lanes(code) ON DELETE RESTRICT
  code varchar(40) NOT NULL              ordered | paid | at_warehouse | handed_to_forwarder |
                                         in_intl_transit | customs | arrived | ready_to_ship |
                                         shipped | out_for_delivery | delivered | exception | returned
  label_key varchar(80) NOT NULL
  position smallint NOT NULL
  is_terminal boolean NOT NULL DEFAULT false
  is_exception boolean NOT NULL DEFAULT false
  --
  uq lane_stage (lane, code)
  uq lane_stage_position (lane, position)
  CHECK (lane IN ('rts','import_po','request','marketplace_purchase'))
  -- Kolom ini adalah **label tampilan** bernarasi admin, bukan sumbu status order (Doc 11 §8.3).
  --   `handed_to_forwarder`/`in_intl_transit`/`customs`/`arrived` TIDAK dipetakan ke fulfillment_status —
  --   tahap luar negeri itu milik procurement_status (sourcing … po_issued … in_transit … customs … received);
  --   `ready_to_ship`/`packed`/`handed_over`/`in_transit`/`delivered`/`returned` dipetakan ke fulfillment_status.
  --   Tidak ada CHECK di sini karena admin menambah tahap; pemetaan hidup di kode Orders/Fulfillment, satu arah saja.

tracking_events                           [Fulfillment]     append-only (S-6) — kurir mentah
  shipment_id bigint NOT NULL REFERENCES shipments(id) ON DELETE CASCADE
  source varchar(24) NOT NULL            biteship | rajaongkir | manual | courier_webhook
  occurred_at timestamptz NOT NULL, status_raw varchar(80), description text
  raw jsonb, fetched_at timestamptz NOT NULL
  -- idx tracking_events_shipment (shipment_id, occurred_at DESC)

shipment_stage_events                     [Fulfillment]     append-only — tahap ternormalisasi kita (D4)
  shipment_id bigint NOT NULL REFERENCES shipments(id) ON DELETE CASCADE
  stage_id bigint NOT NULL REFERENCES lane_stages(id) ON DELETE RESTRICT
  occurred_at timestamptz NOT NULL, source_event_id bigint REFERENCES tracking_events(id) ON DELETE SET NULL
  actor_id bigint REFERENCES users(id)              diisi bila admin menandai manual
  note varchar(320)
  --
  uq stage_once (shipment_id, stage_id)             satu tahap tidak boleh terjadi dua kali
  idx stage_events_shipment (shipment_id, occurred_at)

forwarders                                [Logistics] tabel baru (BP §6, Doc 11 §4.2) — jasa forwarder leg internasional
  name varchar(140) NOT NULL
  contact varchar(140)
  origin_country char(2) NOT NULL, dest_country char(2) NOT NULL DEFAULT 'ID'
  service_types varchar(32)[] NOT NULL DEFAULT '{}'   air | sea | road | consolidation
  is_active boolean NOT NULL DEFAULT true
  notes text
  --
  idx forwarders_active (is_active)

consolidations                            [Logistics] tabel baru (BP §6, Doc 11 §4.2) — penggabungan kiriman per batch/gudang
  code varchar(24) NOT NULL UNIQUE
  forwarder_id bigint REFERENCES forwarders(id) ON DELETE SET NULL
  batch_id bigint REFERENCES preorder_batches(id) ON DELETE SET NULL
  origin_warehouse_id bigint REFERENCES warehouses(id) ON DELETE SET NULL
  status varchar(16) NOT NULL DEFAULT 'open'    open | sealed | shipped | received | cancelled
  sealed_at timestamptz, received_at timestamptz
  --
  CHECK (status IN ('open','sealed','shipped','received','cancelled'))
  idx consolidations_status (status)

cargo_shipments                           [Logistics] tabel baru (BP §6, Doc 11 §4.2) — satu leg/kontainer/airway
  code varchar(24) NOT NULL UNIQUE
  consolidation_id bigint REFERENCES consolidations(id) ON DELETE SET NULL
  forwarder_id bigint REFERENCES forwarders(id) ON DELETE SET NULL
  mode varchar(12) NOT NULL DEFAULT 'air'   air | sea | road
  awb_or_bl varchar(60)
  weight_g_total integer, declared_value bigint, currency_code char(3) NOT NULL DEFAULT 'IDR'
  departed_at timestamptz, arrived_at timestamptz, status varchar(16) NOT NULL DEFAULT 'in_transit'
        in_transit | arrived | customs | released | delivered
  --
  CHECK (mode IN ('air','sea','road'))
  CHECK (status IN ('in_transit','arrived','customs','released','delivered'))
  idx cargo_shipments_status (status)

cargo_items                               [Logistics] tabel baru (BP §6, Doc 11 §4.2) — isi manifest cargo
  cargo_shipment_id bigint NOT NULL REFERENCES cargo_shipments(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  qty integer NOT NULL CHECK (qty > 0)
  hs_code varchar(12)
  value bigint, currency_code char(3) NOT NULL DEFAULT 'IDR'
  --
  idx cargo_items_shipment (cargo_shipment_id)

customs_charges                           [Logistics] tabel baru (BP §6, Doc 11 §4.2) — bea masuk, PPh, PPN
  cargo_shipment_id bigint NOT NULL REFERENCES cargo_shipments(id) ON DELETE CASCADE
  kind varchar(24) NOT NULL               import_duty | vat | pph | service | handling   (bukan pembeda populasi lama)
  amount bigint NOT NULL CHECK (amount >= 0)
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL   saat ditagihkan ke buyer (intl_fees)
  occurred_at timestamptz NOT NULL DEFAULT now()
  --
  CHECK (kind IN ('import_duty','vat','pph','service','handling'))
  idx customs_charges_shipment (cargo_shipment_id, kind)

landed_cost_allocations                   [Logistics] tabel baru (BP §6, Doc 11 §4.2) — alokasi biaya per unit
  cargo_shipment_id bigint NOT NULL REFERENCES cargo_shipments(id) ON DELETE CASCADE
  goods_receipt_item_id bigint REFERENCES goods_receipt_items(id) ON DELETE SET NULL
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  allocated_amount bigint NOT NULL CHECK (allocated_amount >= 0)
  basis varchar(16) NOT NULL DEFAULT 'qty'        qty | value | weight
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  --
  CHECK (basis IN ('qty','value','weight'))
  idx landed_cost_shipment (cargo_shipment_id, variant_id)
```

## 11. Procurement (supplier — BP §4) & MarketplacePurchase (D5)

```
suppliers                                 [Procurement]   tabel baru (BP §6, Doc 11 §4.2) — pemasok
  code varchar(24) NOT NULL UNIQUE
  name varchar(160) NOT NULL
  country char(2) NOT NULL
  contact_person varchar(120), contact_email varchar(160), contact_phone varchar(24)
  payment_terms_days integer NOT NULL DEFAULT 0
  is_active boolean NOT NULL DEFAULT true
  notes text
  --
  idx suppliers_active (is_active)

purchase_orders                           [Procurement]   tabel baru (BP §6, Doc 11 §4.2)
  code varchar(24) NOT NULL UNIQUE       PO-...
  supplier_id bigint NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT
  batch_id bigint REFERENCES preorder_batches(id) ON DELETE SET NULL   PO untuk menutup kuota batch
  total bigint NOT NULL DEFAULT 0, currency_code char(3) NOT NULL DEFAULT 'IDR'
  status varchar(20) NOT NULL DEFAULT 'draft'   draft | pending_approval | issued | confirmed | partially_received | received | closed | cancelled
        `draft`/`pending_approval` = **requisition** (Doc 11 §4.3 — tidak ada tabel purchase_requisitions;
        sisi order menjadikannya procurement_status = `requisitioned`, §6). Lebar kolom 16 → 20 karena
        `partially_received` sudah 18 karakter dan `pending_approval` 16 — sebelumnya tidak muat.
  expected_at date, issued_at timestamptz, closed_at timestamptz
  created_by bigint REFERENCES users(id)
  approved_by bigint REFERENCES users(id), approved_at timestamptz   pemisah pengaju vs penyetuju requisition (Doc 06 §1)
  --
  CHECK (total >= 0)
  CHECK (status IN ('draft','pending_approval','issued','confirmed','partially_received','received','closed','cancelled'))
  CHECK (status IN ('draft','pending_approval','cancelled') OR approved_by IS NOT NULL)
  CHECK (approved_by IS NULL OR approved_by <> created_by)
  idx purchase_orders_supplier (supplier_id, status)

purchase_order_items                      [Procurement]   tabel baru (BP §6, Doc 11 §4.2)
  purchase_order_id bigint NOT NULL REFERENCES purchase_orders(id) ON DELETE CASCADE
  variant_id bigint REFERENCES product_variants(id) ON DELETE SET NULL   NULL = barang di luar katalog
  description varchar(240) NOT NULL
  qty_ordered integer NOT NULL CHECK (qty_ordered > 0)
  qty_received integer NOT NULL DEFAULT 0 CHECK (qty_received >= 0)
  unit_cost bigint NOT NULL CHECK (unit_cost >= 0)
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  --
  CHECK (qty_received <= qty_ordered)
  idx po_items_po (purchase_order_id)

supplier_invoices                         [Procurement]   tabel baru (BP §6, Doc 11 §4.2) — tagihan dari pemasok
  code varchar(24) NOT NULL UNIQUE
  purchase_order_id bigint NOT NULL REFERENCES purchase_orders(id) ON DELETE RESTRICT
  supplier_id bigint NOT NULL REFERENCES suppliers(id) ON DELETE RESTRICT
  amount bigint NOT NULL CHECK (amount >= 0)
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  status varchar(16) NOT NULL DEFAULT 'received'  received | verified | settled | disputed
  invoice_number_provider varchar(60)
  received_at timestamptz NOT NULL DEFAULT now(), settled_at timestamptz
  --
  CHECK (status IN ('received','verified','settled','disputed'))
  idx supplier_invoices_po (purchase_order_id)

goods_receipts                            [Procurement]   rename grns (Doc 11 §4.1) + pindah dari Inventory (Doc 11 B-4)
  code varchar(24) NOT NULL UNIQUE
  purchase_order_id bigint REFERENCES purchase_orders(id) ON DELETE SET NULL
  batch_id bigint REFERENCES preorder_batches(id) ON DELETE SET NULL
  warehouse_id bigint NOT NULL REFERENCES warehouses(id) ON DELETE RESTRICT
  received_at timestamptz NOT NULL DEFAULT now()
  weight_g_total integer                      berat nyata → dasar invoice intl_fees (A1/D3)
  declared_value bigint, tax_amount bigint, ems_amount bigint      pemecahan biaya leg internasional
  status varchar(16) NOT NULL DEFAULT 'draft'   draft | posted | reversed
  posted_by bigint REFERENCES users(id), notes text
  --
  CHECK (status IN ('draft','posted','reversed'))
  idx goods_receipts_po (purchase_order_id)
  idx goods_receipts_batch (batch_id)

goods_receipt_items                       [Procurement]   rename grn_items (Doc 11 §4.1)
  goods_receipt_id bigint NOT NULL REFERENCES goods_receipts(id) ON DELETE CASCADE
  purchase_order_item_id bigint REFERENCES purchase_order_items(id) ON DELETE SET NULL
  enrollment_id bigint REFERENCES preorder_enrollments(id) ON DELETE SET NULL   link ke sisi buyer bila receipt menutup batch
  qty_expected integer NOT NULL, qty_received integer NOT NULL, qty_damaged integer NOT NULL DEFAULT 0
  weight_g integer
  --
  CHECK (qty_received >= 0 AND qty_damaged >= 0)
  idx goods_receipt_items_po (purchase_order_item_id)
  idx goods_receipt_items_receipt (goods_receipt_id)

marketplace_purchases                     [MarketplacePurchase] rename procurements (Doc 11 §4.1/B-4) — jalur beli atas nama buyer (D5, di balik feature flag)
  code varchar(24) NOT NULL UNIQUE
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT
  order_item_id bigint NOT NULL REFERENCES order_items(id) ON DELETE RESTRICT
  marketplace varchar(40) NOT NULL       rakuten | yahoo | taobao | mercari | other
  listing_url varchar(600) NOT NULL
  listing_price bigint, listing_currency char(3)
  paid_amount bigint NOT NULL             berapa kita keluar
  paid_at timestamptz
  buyer_account_ref varchar(120)          akun pembeli, disamarkan (bukan kredensial, lihat Doc 00 §Batas)
  status varchar(24) NOT NULL DEFAULT 'pending'
        pending | purchased | code_issued | in_transit | arrived | linked | cancelled | disputed
  created_by bigint NOT NULL REFERENCES users(id)
  --
  CHECK (paid_amount >= 0)
  idx marketplace_purchases_status (status) WHERE status NOT IN ('linked','cancelled')
  idx marketplace_purchases_order (order_id)

claim_codes                               [MarketplacePurchase] rename procurement_codes (Doc 11 §4.1/B-4); kode klaim, sekali pakai (RS-5)
  marketplace_purchase_id bigint NOT NULL REFERENCES marketplace_purchases(id) ON DELETE RESTRICT
  code_hash varchar(255) NOT NULL UNIQUE   jangan simpan kode klaim mentah (satu kode = satu paket)
  status varchar(24) NOT NULL DEFAULT 'issued'
        issued | used_by_buyer | reported | verified | linked_to_shipment | voided
  issued_at timestamptz NOT NULL DEFAULT now()
  used_at timestamptz, used_by bigint REFERENCES users(id)
  verified_by bigint REFERENCES users(id), verified_at timestamptz
  matched_order_number varchar(24)          verifikasi butuh nomor order kita cocok (Doc 06 §3.6)
  matched_package_ref varchar(120)
  shipment_id bigint REFERENCES shipments(id) ON DELETE SET NULL
  void_reason varchar(320)
  --
  uq claim_codes_one_use (marketplace_purchase_id) WHERE status <> 'voided'    satu kode aktif per pembelian
  idx claim_codes_status (status)

proof_files                               [MarketplacePurchase] nama tetap (Doc 11 §4.1); tabel menunjuk media, bukan byte
  marketplace_purchase_id bigint NOT NULL REFERENCES marketplace_purchases(id) ON DELETE CASCADE
  media_id bigint NOT NULL REFERENCES media(id) ON DELETE RESTRICT
  kind varchar(24) NOT NULL              payment_proof | listing_snapshot | chat_log | receipt   (bukan pembeda populasi lama)
  uploaded_by bigint NOT NULL REFERENCES users(id), uploaded_at timestamptz NOT NULL DEFAULT now()
  verified boolean NOT NULL DEFAULT false, verified_by bigint REFERENCES users(id)
  -- PRIMARY KEY (marketplace_purchase_id, media_id)
```

## 12. Sourcing (jalur request order — BP §6 Request)

```
product_requests                          [Sourcing]        rename quote_requests (Doc 11 §4.1); pengajuan buyer (Doc 06 §3.3)
  code varchar(24) NOT NULL UNIQUE
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  product_name_wanted varchar(240) NOT NULL
  brand varchar(120), series varchar(120), character_name varchar(120)
  target_url varchar(500)                 tautan marketplace asal
  desired_qty integer NOT NULL CHECK (desired_qty > 0)
  budget_max bigint, currency_code char(3) NOT NULL DEFAULT 'IDR'
  reference_media_id bigint REFERENCES media(id) ON DELETE SET NULL   lampiran = media polimorfik (S-3), bukan request_attachments
  notes text
  status varchar(24) NOT NULL DEFAULT 'new'
        new | in_review | quoted | accepted | declined | expired | withdrawn
  sla_due_at timestamptz                  SLA respons (Doc 06 §3.3)
  requested_at timestamptz NOT NULL DEFAULT now(), closed_at timestamptz
  assigned_to bigint REFERENCES users(id)
  --
  idx product_requests_status_sla (status, sla_due_at)
  idx product_requests_user (user_id, requested_at DESC)

sourcing_options                          [Sourcing]        tabel baru (BP §6, Doc 11 §4.2) — alternatif sourcing yang dinilai admin
  product_request_id bigint NOT NULL REFERENCES product_requests(id) ON DELETE CASCADE
  variant_id bigint REFERENCES product_variants(id) ON DELETE SET NULL
  source_marketplace varchar(40), source_url varchar(600)
  estimated_cost bigint, currency_code char(3) NOT NULL DEFAULT 'IDR'
  eta_days smallint
  status varchar(16) NOT NULL DEFAULT 'candidate'  candidate | selected | rejected
  evaluated_by bigint REFERENCES users(id), notes text
  --
  CHECK (status IN ('candidate','selected','rejected'))
  idx sourcing_options_request (product_request_id, status)

quotations                                [Sourcing]        rename quotes (Doc 11 §4.1); penawaran privat admin
  code varchar(24) NOT NULL UNIQUE
  product_request_id bigint NOT NULL REFERENCES product_requests(id) ON DELETE RESTRICT
  owner_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT   visibility privat ditegakkan di Policy
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  current_version integer NOT NULL DEFAULT 1
  status varchar(20) NOT NULL DEFAULT 'draft'  draft | issued | accepted | declined | expired | superseded
  issued_by bigint NOT NULL REFERENCES users(id), issued_at timestamptz
  accepted_at timestamptz
  converted_order_id bigint               PLAIN (tanpa REFERENCES): order hasil konversi; FK di-break agar graf asiklik (Doc 10 §1 M-5)
  buyer_note text, internal_note text
  --
  CHECK (status IN ('draft','issued','accepted','declined','expired','superseded'))
  idx quotations_owner_status (owner_user_id, status)

quotation_versions                        [Sourcing]        tabel baru (BP §6, Doc 11 §4.2) — versi penawaran + anti terima versi lama (BP §16)
  quotation_id bigint NOT NULL REFERENCES quotations(id) ON DELETE CASCADE
  version integer NOT NULL
  status varchar(16) NOT NULL DEFAULT 'draft'  draft | issued | accepted | declined | superseded | expired
  total bigint NOT NULL CHECK (total >= 0), currency_code char(3) NOT NULL DEFAULT 'IDR'
  service_fee bigint NOT NULL DEFAULT 0
  eta_min_date date, eta_max_date date
  valid_until timestamptz NOT NULL        masa berlaku penawaran versi ini
  is_current boolean NOT NULL DEFAULT false
  optimistic_version integer NOT NULL DEFAULT 0    tolak tulis basi (409) — BP §8.2
  issued_by bigint REFERENCES users(id), issued_at timestamptz
  accepted_at timestamptz
  --
  uq quotation_versions_num (quotation_id, version)
  uq quotation_versions_current (quotation_id) WHERE is_current          max satu versi current
  uq quotation_versions_once_accepted (quotation_id) WHERE status = 'accepted'
  CHECK (status IN ('draft','issued','accepted','declined','superseded','expired'))
  CHECK (status <> 'accepted' OR is_current = true)                       versi lama tak bisa "diterima" lagi
  idx quotation_versions_quote (quotation_id, version DESC)
  -- "versi lama tidak bisa diterima setelah revisi" ditegakkan DB:
  --   hanya baris is_current=true boleh ber-status 'accepted' (CHECK di atas)
  --   + maksimal satu 'accepted' per quotation (partial unique di atas)

quotation_items                           [Sourcing]        rename quote_items (Doc 11 §4.1)
  quotation_version_id bigint NOT NULL REFERENCES quotation_versions(id) ON DELETE CASCADE
  variant_id bigint REFERENCES product_variants(id) ON DELETE SET NULL   NULL = barang belum ada di katalog
  description varchar(240) NOT NULL
  qty integer NOT NULL CHECK (qty > 0)
  unit_price bigint NOT NULL CHECK (unit_price >= 0)
  line_total bigint GENERATED ALWAYS AS (qty * unit_price) STORED
  currency_code char(3) NOT NULL DEFAULT 'IDR'        (B-13)
  exchange_rate_snapshot numeric(14,6) NOT NULL DEFAULT 1   (B-13)
  tax_rule_snapshot jsonb NOT NULL DEFAULT '{}'       (B-13)
  price_snapshot jsonb NOT NULL DEFAULT '{}'          (B-13)
  --
  CHECK (exchange_rate_snapshot > 0)
  idx quotation_items_version (quotation_version_id)

quotation_visibility_grants               [Sourcing]        rename quote_visibility_grants (Usulan §20 — prefik); siapa dari staff boleh melihat (Doc 01 §3: enforcement privat)
  quotation_id bigint NOT NULL REFERENCES quotations(id) ON DELETE CASCADE
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  granted_by bigint NOT NULL REFERENCES users(id), granted_at timestamptz NOT NULL DEFAULT now()
  -- PRIMARY KEY (quotation_id, user_id)
```

## 13. Promotion (voucher, loyalitas, drop terbatas — Doc 01 §2.1)

Tiga mesin promo berdiri sendiri, tidak menempel ke Orders: **voucher** (diskon berkode), **loyalitas** (poin + tier, ledger append-only S-6), dan **drop** (rilis stok terbatas dengan kuota atomic, mirip `preorder_batch_items` tapi untuk barang RTS). Flag `drops.enabled`, `loyalty.enabled` (lihat §15 `settings`) menggerbang tiap fitur. Uang semuanya integer rupiah (S-9). Satu-satunya soft delete di modul ini adalah `vouchers` (S-7).

```
loyalty_tiers                             [Promotion]     tabel baru (Doc 01 §2.1); tangga loyalitas, tanpa dependan (Doc 10 L0)
  code varchar(32) NOT NULL UNIQUE        bronze | silver | gold | platinum
  name varchar(80) NOT NULL
  rank smallint NOT NULL UNIQUE           urutan naik; acuan promosi/demosi
  min_lifetime_spend bigint NOT NULL DEFAULT 0    ambang rupiah kumulatif untuk masuk tier
  points_multiplier_bp integer NOT NULL DEFAULT 10000   pengali poin ×10000 (10000 = 1×)
  perks jsonb NOT NULL DEFAULT '{}'            shipping_voucher, early_access_drop, dsb
  is_active boolean NOT NULL DEFAULT true
  --
  CHECK (rank > 0 AND min_lifetime_spend >= 0 AND points_multiplier_bp > 0)
  idx loyalty_tiers_rank (rank) WHERE is_active

vouchers                                  [Promotion]     rename-ish (Doc 01 §2.1); kupon berkode, satu-satunya soft delete modul ini (S-7)
  code varchar(40) NOT NULL UNIQUE        kode yang diketik buyer, mis. "ELZARA10"
  name varchar(120) NOT NULL
  kind varchar(16) NOT NULL               percent | fixed | free_shipping   (Doc 02 §4 potongan; bukan pembeda populasi lama)
  value bigint NOT NULL DEFAULT 0         % ×100 (bp) untuk percent, rupiah untuk fixed; 0 untuk free_shipping
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  min_subtotal bigint NOT NULL DEFAULT 0  ambang belanja (alasan penolakan VoucherIneligible, Doc 12)
  redeemable_from timestamptz NOT NULL, redeemable_until timestamptz NOT NULL
  usage_limit integer,                    NULL = tak terbatas; dipakai untuk anti-abuse
  usage_limit_per_user smallint NOT NULL DEFAULT 1
  stackable boolean NOT NULL DEFAULT false    boleh digabung voucher lain?
  applies_to jsonb NOT NULL DEFAULT '{}'  filter: category_ids[], brand_ids[], product_ids[], batch_ids[]
  status varchar(16) NOT NULL DEFAULT 'active'  draft | active | paused | expired
  created_by bigint REFERENCES users(id)
  deleted_at timestamptz                  S-7: soft delete khusus vouchers
  --
  CHECK (kind IN ('percent','fixed','free_shipping'))
  CHECK (kind <> 'percent' OR (value >= 0 AND value <= 10000))
  CHECK (value >= 0 AND min_subtotal >= 0)
  CHECK (redeemable_until > redeemable_from)
  CHECK (status IN ('draft','active','paused','expired'))
  idx vouchers_window_status (redeemable_from, redeemable_until) WHERE status = 'active' AND deleted_at IS NULL

voucher_redemptions                       [Promotion]     redeem tercatat + bisa dibatalkan (Doc 10 L6)
  voucher_id bigint NOT NULL REFERENCES vouchers(id) ON DELETE RESTRICT
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT
  adjustment_id bigint REFERENCES order_adjustments(id) ON DELETE SET NULL    baris diskon yang dihasilkan voucher ini (Doc 10 L5 → tabel ini L6)
  checkout_session_id bigint REFERENCES checkout_sessions(id) ON DELETE SET NULL   validasi pra-order (Doc 02 §6)
  discount_amount bigint NOT NULL DEFAULT 0     potongan rupiah yang benar-benar diterapkan
  status varchar(16) NOT NULL DEFAULT 'redeemed'  redeemed | reverted
  redeemed_at timestamptz NOT NULL DEFAULT now(), reverted_at timestamptz
  --
  CHECK (status IN ('redeemed','reverted'))
  CHECK (discount_amount >= 0)
  uq voucher_redemption_once (voucher_id, order_id)   satu voucher satu kali per order
  idx voucher_redemptions_user (user_id, redeemed_at DESC)
  idx voucher_redemptions_voucher (voucher_id, status)
  -- kuota global ditegakkan lewat COUNT aktif per voucher di dalam transaksi order-place
  -- (usage_limit), bukan kolom agregat — konsisten S-5; revert tidak menghapus baris (audit).

loyalty_entries                           [Promotion]     ledger poin append-only (S-6); saldo = SUM( Doc 01 §2.1)
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  tier_id bigint REFERENCES loyalty_tiers(id) ON DELETE SET NULL    tier saat transaksi dicatat
  points integer NOT NULL                 delta bertanda: earn (+), redeem/spend (-), expire (-), adjust (±)
  reason varchar(24) NOT NULL             earn_order | redeem_voucher | expire | manual_adjust | signup_bonus
  order_id bigint REFERENCES orders(id) ON DELETE SET NULL    sumber poin (nullable untuk adjust manual)
  expires_at timestamptz                  masa kedaluwarsa batch poin (Doc 02 §4 poin hangus)
  occurred_at timestamptz NOT NULL DEFAULT now()
  actor_id bigint REFERENCES users(id)   NULL = sistem, terisi untuk manual_adjust
  --
  CHECK (reason IN ('earn_order','redeem_voucher','expire','manual_adjust','signup_bonus'))
  CHECK (reason <> 'manual_adjust' OR actor_id IS NOT NULL)   penyesuaian manual harus punya actor (Doc 06)
  idx loyalty_entries_user_time (user_id, occurred_at DESC)
  idx loyalty_entries_expiry (expires_at) WHERE points > 0 AND expires_at IS NOT NULL
  -- S-6: tanpa UPDATE/DELETE; koreksi lewat baris penyesuaian baru, bukan edit saldo.

drops                                     [Promotion]     rilis stok terbatas (Doc 01 §2.1); bukan batch preorder
  code varchar(32) NOT NULL UNIQUE        "DROP-GW-2026-11"
  title varchar(140) NOT NULL
  status varchar(20) NOT NULL DEFAULT 'draft'  draft | scheduled | live | sold_out | closed | cancelled
  opens_at timestamptz NOT NULL, closes_at timestamptz   jendela drop
  queue_enabled boolean NOT NULL DEFAULT false    pakai waiting-room Cloudflare (Doc 01 §3.1)
  max_per_user smallint NOT NULL DEFAULT 1    batas unit per akun selama drop
  mode varchar(16) NOT NULL DEFAULT 'fcfs'    fcfs | lottery   (Doc runbooks cloudflare-waiting-room)
  created_by bigint REFERENCES users(id)
  cancelled_at timestamptz, cancel_reason text
  --
  CHECK (closes_at > opens_at)
  CHECK (status IN ('draft','scheduled','live','sold_out','closed','cancelled'))
  CHECK (mode IN ('fcfs','lottery'))
  CHECK (max_per_user > 0)
  idx drops_status_window (status, opens_at)

drop_items                                [Promotion]     kuota per varian DALAM sebuah drop; atomic (Doc 10 L4)
  drop_id bigint NOT NULL REFERENCES drops(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  qty_total integer NOT NULL              stok terbatas yang dilepas pada drop ini
  claimed_qty integer NOT NULL DEFAULT 0  dijaga UPDATE atomic di bawah
  price bigint NOT NULL                   snapshot harga drop (S-8); rupiah
  currency_code char(3) NOT NULL DEFAULT 'IDR'
  status varchar(16) NOT NULL DEFAULT 'open'   open | closed
  --
  uq drop_items_slot (drop_id, variant_id)
  CHECK (qty_total > 0 AND claimed_qty >= 0 AND claimed_qty <= qty_total)
  CHECK (price >= 0)
  CHECK (status IN ('open','closed'))
  idx drop_items_variant (variant_id, drop_id)
  -- klon pola BP §8.5 (TOCTOU-safe; primary correctness control):
  --   UPDATE drop_items
  --      SET claimed_qty = claimed_qty + :qty
  --    WHERE id = :drop_item_id
  --      AND claimed_qty + :qty <= qty_total
  --      AND status = 'open'
  --   RETURNING id;                       -- 0 baris = kuota drop habis

drop_claims                               [Promotion]     klaim satu-per-akun untuk lottery/akses drop (Doc 12 §2.4, Doc 10 Appendix A)
  drop_id bigint NOT NULL REFERENCES drops(id) ON DELETE CASCADE
  account_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE   "akun" = user login (satu klaim per akun)
  entry_code varchar(40) NOT NULL UNIQUE  kode yang dibagikan ke buyer utk cek status (Doc 12 marketplace-claims status)
  status varchar(20) NOT NULL DEFAULT 'entered'  entered | waitlist | won | fulfilled | forfeited | lost
  won_at timestamptz                        ditetapkan oleh worker lottery (Doc runbooks)
  fulfillment_deadline timestamptz          batas winner untuk mengubah kemenangan jadi order
  order_id bigint REFERENCES orders(id) ON DELETE SET NULL    order hasil konversi klaim yang menang (Doc 10 L4)
  entered_at timestamptz NOT NULL DEFAULT now()
  --
  CHECK (status IN ('entered','waitlist','won','fulfilled','forfeited','lost'))
  CHECK (status <> 'fulfilled' OR order_id IS NOT NULL)   fulfilled tanpa order tak sah
  uq drop_claims_one_per_account (drop_id, account_id)   satu klaim per akun per drop (Doc 01 §3.1 D6)
  idx drop_claims_entry (entry_code)
  idx drop_claims_draw (drop_id, status, won_at) WHERE status = 'won'
```

## 14. UGC (ulasan — D6)

```
reviews                                   [UGC]
  id, product_id NOT NULL REFERENCES products(id) ON DELETE CASCADE
  variant_id NULL REFERENCES product_variants(id) ON DELETE SET NULL
  user_id NOT NULL REFERENCES users(id) ON DELETE RESTRICT
  order_item_id bigint NOT NULL REFERENCES order_items(id) ON DELETE RESTRICT
  rating smallint NOT NULL               1..5
  title varchar(140), body text
  is_verified_purchase boolean NOT NULL DEFAULT true     selalu true karena gerbangnya order_item selesai
  status varchar(16) NOT NULL DEFAULT 'pending'          pending | approved | rejected | flagged
  published_at timestamptz, is_locked boolean NOT NULL DEFAULT true     terkunci setelah balasan seller/moderasi
  --
  -- (S-5) tidak ada kolom agregat rating di tabel ini; rata-rata per produk dibaca dari view v_product_ratings
  uq review_per_order_item (order_item_id)      satu ulasan per baris order selesai
  CHECK (rating BETWEEN 1 AND 5)
  idx reviews_product_published (product_id, published_at DESC) WHERE status = 'approved'
  idx reviews_moderation_queue (status, created_at) WHERE status = 'pending'

review_media                              [UGC]
  review_id bigint NOT NULL REFERENCES reviews(id) ON DELETE CASCADE
  media_id bigint NOT NULL REFERENCES media(id) ON DELETE CASCADE
  position smallint NOT NULL DEFAULT 0
  moderation_status varchar(16) NOT NULL DEFAULT 'pending'
  -- PRIMARY KEY (review_id, media_id)

review_moderation                         [UGC]           append-only (S-6)
  review_id bigint NOT NULL REFERENCES reviews(id) ON DELETE CASCADE
  actor_id bigint NOT NULL REFERENCES users(id)
  action varchar(16) NOT NULL            approve | reject | flag | restore | request_edit
  reason varchar(320), occurred_at timestamptz NOT NULL DEFAULT now()
  -- idx review_mod_queue (occurred_at DESC)
```

## 15. Content, i18n, Settings

```
media                                     [Content]       lihat S-3 — pemilik Content, dipakai lintas modul
  uuid uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE
  model_type varchar(64) NOT NULL        App\Models\... (Product, User, Review, MarketplacePurchaseProof)
  model_id bigint NOT NULL
  kind varchar(24) NOT NULL              image | video | file | icon | label_pdf | proof | attachment   (bukan pembeda populasi lama)
  disk varchar(24) NOT NULL DEFAULT 's3'
  path varchar(512) NOT NULL
  object_version varchar(255)            nullable; versi objek MinIO untuk pembacaan version-pinned (0084)
  mime varchar(80), size_bytes bigint, width integer, height integer
  sha256 char(64)                         deteksi duplikat + integritas
  alt_key varchar(120)                     alt text wajib untuk gambar produk (Doc 04 §7)
  position smallint NOT NULL DEFAULT 0
  is_moderated boolean NOT NULL DEFAULT false    media user masuk antrean moderasi (Doc 06 §3.9)
  moderation_status varchar(16) NOT NULL DEFAULT 'pending'  pending | approved | rejected
  --
  idx media_model (model_type, model_id, kind, position)
  uq media_path (disk, path)

pages                                     [Content]
  slug varchar(140) NOT NULL             9 halaman publik terverifikasi (Doc 08 §2) + draft
  kind varchar(20) NOT NULL DEFAULT 'policy'    policy | guide | landing | about   (bukan pembeda populasi lama)
  published_version_id bigint            PLAIN (tanpa REFERENCES): blok→versi→halaman membentuk cycle; FK di-break (Doc 10 §1 M-5)
  is_indexable boolean NOT NULL DEFAULT false    kebijakan & panduan boleh diindeks, draft tidak
  seo_title varchar(120), seo_description varchar(300)
  --
  uq pages_slug (slug)

content_blocks                            [Content]       blok dalam sebuah halaman
  page_id bigint NOT NULL REFERENCES pages(id) ON DELETE CASCADE
  block_key varchar(64) NOT NULL         "hero", "faq_group_1", "refund_clause_3"
  block_type varchar(24) NOT NULL        rich_text | faq | hero_banner | step_list | table
  position smallint NOT NULL DEFAULT 0
  is_active boolean NOT NULL DEFAULT true
  -- uq block_key_per_page (page_id, block_key)

content_block_versions                    [Content]       D6: ber-versi + tanggal berlaku
  content_block_id bigint NOT NULL REFERENCES content_blocks(id) ON DELETE CASCADE
  version integer NOT NULL
  locale varchar(5) NOT NULL DEFAULT 'id'
  payload jsonb NOT NULL                  struktur per block_type, divalidasi di aplikasi
  effective_from timestamptz NOT NULL
  effective_to timestamptz                NULL = berlaku terus
  change_note varchar(320)
  published_by bigint NOT NULL REFERENCES users(id)
  published_at timestamptz NOT NULL DEFAULT now()
  --
  uq block_version (content_block_id, locale, version)
  CHECK (effective_to IS NULL OR effective_to > effective_from)
  idx block_effective (content_block_id, locale, effective_from DESC)
  -- consent dibuktikan ke versi ini (user_consents.policy_version)

nav_items                                 [Content]       Doc 02 §10.1: menu dari data, tanpa deploy
  label_key varchar(80) NOT NULL
  target_type varchar(16) NOT NULL        page | category | collection | url | batch
  page_id bigint REFERENCES pages(id) ON DELETE CASCADE
  category_id bigint REFERENCES categories(id) ON DELETE CASCADE
  collection_id bigint REFERENCES collections(id) ON DELETE CASCADE
  external_url varchar(400)
  parent_id bigint REFERENCES nav_items(id) ON DELETE SET NULL
  position smallint NOT NULL DEFAULT 0, is_active boolean NOT NULL DEFAULT true
  open_in_new_tab boolean NOT NULL DEFAULT false
  --
  CHECK (num_nonnulls(page_id, category_id, collection_id, external_url) = 1)   fungsi bawaan PG16
  idx nav_items_tree (parent_id, position)

localizations                             [Content]       D6 i18n ID/EN sejak Sprint 0
  key varchar(140) NOT NULL
  group_name varchar(60) NOT NULL         shop | admin | email | validation | policy
  locale varchar(5) NOT NULL              id | en
  value text NOT NULL
  context varchar(120)                    catatan penerjemah
  plural_variant smallint                 0 other | 1 one | 2 few ...
  updated_by bigint REFERENCES users(id), updated_at timestamptz NOT NULL DEFAULT now()
  --
  uq localization_key_locale (key, locale, COALESCE(plural_variant,0))
  idx localization_group (group_name, locale)

feature_flags                             [Settings]      D6: toggle per provider dipegang admin
  code varchar(64) NOT NULL UNIQUE        auth.social_google, marketplace_purchase.enabled, reviews.enabled,
                                          loyalty.enabled, guest_checkout.enabled, drops.enabled,
                                          checkout.enabled (kill switch — Doc 11 §8.7; runbook emergency-disable-checkout)
  description varchar(240) NOT NULL
  default_enabled boolean NOT NULL DEFAULT false
  requires_restart boolean NOT NULL DEFAULT false
  updated_by bigint REFERENCES users(id), updated_at timestamptz NOT NULL DEFAULT now()

feature_flag_overrides                    [Settings]
  flag_code varchar(64) NOT NULL REFERENCES feature_flags(code) ON DELETE CASCADE
  env varchar(16) NOT NULL DEFAULT 'prod'   local | staging | prod
  enabled boolean NOT NULL
  rollout_percent smallint NOT NULL DEFAULT 100   0..100
  updated_by bigint NOT NULL REFERENCES users(id)
  -- PRIMARY KEY (flag_code, env)

settings                                  [Settings]
  key varchar(120) NOT NULL UNIQUE        money.rounding, po.reconsent_threshold_percent,
                                          intl_fee.auto_issue_cap, wa.provider, tax.ems_basis
  group_name varchar(48) NOT NULL
  value_type varchar(12) NOT NULL         string | int | bool | decimal | json
  value jsonb NOT NULL                    {"v": ...} — dibungkus supaya `null` sah
  is_public boolean NOT NULL DEFAULT false  boleh dibaca storefront tanpa login
  description varchar(240), default_value jsonb NOT NULL
  updated_by bigint REFERENCES users(id)  NULL = system
  --
  CHECK (value_type IN ('string','int','bool','decimal','json'))
  idx settings_group (group_name)
```

## 16. Notification & Support

```
notification_templates                    [Notification]
  channel varchar(16) NOT NULL           email | whatsapp | in_app | sms
  code varchar(64) NOT NULL              order_placed, deposit_paid, balance_due_h7,
                                         batch_arrived, shipped, quote_issued, otp_login
                                         (kode template = turunan nama event Doc 12 §6.2, bukan daftar kedua;
                                         `event_name` di notification_logs memakai nama register apa adanya)
  locale varchar(5) NOT NULL DEFAULT 'id'
  subject varchar(200), body text NOT NULL    placeholder teregas: {{order_code}}, {{due_at}}
  version integer NOT NULL DEFAULT 1
  is_active boolean NOT NULL DEFAULT true
  wa_template_id varchar(80)              id template provider (butuh approval — Doc 00 §Asumsi)
  updated_by bigint NOT NULL REFERENCES users(id)
  --
  uq template_channel_code_locale_ver (channel, code, locale, version)

user_preferences                          [Notification]
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  event_code varchar(64) NOT NULL
  channel varchar(16) NOT NULL
  enabled boolean NOT NULL DEFAULT true
  -- PRIMARY KEY (user_id, event_code, channel)

notification_logs                         [Notification]  append-only (S-6)
  user_id bigint REFERENCES users(id) ON DELETE SET NULL
  recipient varchar(200) NOT NULL         email/no-HP terenkripsi at-rest (Doc 01 §9.3)
  channel varchar(16) NOT NULL, template_code varchar(64) NOT NULL, template_version integer NOT NULL
  event_name varchar(64) NOT NULL, aggregate_id bigint
  status varchar(16) NOT NULL DEFAULT 'queued'   queued | sent | delivered | failed | bounced | suppressed
  provider_message_id varchar(120), failure_reason varchar(320)
  attempts smallint NOT NULL DEFAULT 0
  queued_at timestamptz NOT NULL DEFAULT now(), sent_at timestamptz
  --
  idx notif_status_time (status, queued_at DESC)
  idx notif_user (user_id, queued_at DESC)

back_in_stock_subscriptions               [Notification]  tabel baru (Doc 11 §4.2) — turunan Doc 05 M3, **bukan** Blueprint; daftar "kabari saya"
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES product_variants(id) ON DELETE RESTRICT
  channel varchar(16) NOT NULL            email | whatsapp | in_app  (kanal notifikasi — nilai sama dengan notification_templates.channel)
  status varchar(16) NOT NULL DEFAULT 'active'   active | notified | cancelled | expired
  last_checked_at timestamptz             disentuh pemanggil antrian, bukan oleh customer
  notified_at timestamptz, cancelled_at timestamptz
  consent_at timestamptz                  jejak persetujuan opt-in (Doc 01 §9 pemasaran; tanpa ini status tidak boleh 'active')
  --
  CHECK (channel IN ('email','whatsapp','in_app'))
  CHECK (status IN ('active','notified','cancelled','expired'))
  CHECK (consent_at IS NOT NULL OR status <> 'active')
  uq bis_user_variant_channel (user_id, variant_id, channel)   UNIQUE pasangan + kanal: satu orang satu pesan per kanal per varian
  idx bis_variant_active (variant_id, status) WHERE status = 'active'
  -- Dieksekusi antrian `notifications` (Doc 11 §8.1 nama antrian; Blueprint §9): worker berjalan saat
  --   `available_to_sell` varian berbalik > 0 (dibaca dari v_stock_position §18, ditulis modul Inventory — B1),
  --   mengirim lewat notification_logs dengan kunci idempoten turunan `evt:{variant_id}:bis:{user_id}:{channel}`.
  --   Tabel ini TIDAK menyentuh jalur uang/stok: tidak ada reservasi, tidak ada order di balik subscription.

tickets                                   [Support]
  code varchar(24) NOT NULL UNIQUE
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT
  order_id bigint REFERENCES orders(id) ON DELETE SET NULL
  invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL
  kind varchar(24) NOT NULL              question | damage_claim | lost_parcel | double_payment |   (bukan pembeda populasi lama)
                                         address_change | return_request | other
  subject varchar(200) NOT NULL, body text NOT NULL
  status varchar(16) NOT NULL DEFAULT 'open'   open | pending_customer | pending_us | resolved | closed
  priority varchar(12) NOT NULL DEFAULT 'normal'
  sla_due_at timestamptz
  reported_amount bigint                  untuk double_payment (RS-6)
  assigned_to bigint REFERENCES users(id)
  opened_at timestamptz NOT NULL DEFAULT now(), closed_at timestamptz
  --
  CHECK (kind IN ('question','damage_claim','lost_parcel','double_payment','address_change','return_request','other'))
  idx tickets_status_sla (status, sla_due_at)
  idx tickets_user (user_id, opened_at DESC)

ticket_messages                           [Support]
  ticket_id bigint NOT NULL REFERENCES tickets(id) ON DELETE CASCADE
  author_id bigint REFERENCES users(id)   NULL = sistem
  body text NOT NULL
  is_internal boolean NOT NULL DEFAULT false   catatan staff tidak terlihat customer
  media_ids bigint[]
  created_at timestamptz NOT NULL DEFAULT now()
  -- idx ticket_msg (ticket_id, created_at)
```

## 17. Fondasi lintas modul & Reliability (BP §6, Doc 11 B-7)

```
outbox_events                             [Lintas modul]   B4 — transactional outbox, sejak Sprint 0
  event_name varchar(80) NOT NULL        OrderPlaced, PaymentCaptured, GoodsReceived, ... — daftar kanonik event
                                         HANYA di Doc 12 §6.2 (pemilik register, Doc 11 §8.4); dokumen ini mengutip namanya apa adanya,
                                         tanpa nama lokal. `PoBatchArrived` (bekas) = `GoodsReceived`.
  aggregate_type varchar(40) NOT NULL, aggregate_id bigint NOT NULL
  payload jsonb NOT NULL                 ID + timestamp saja (B3) — tanpa graph objek
  occurred_at timestamptz NOT NULL DEFAULT now()
  dispatched_at timestamptz, attempts smallint NOT NULL DEFAULT 0
  last_error text, locked_by varchar(64), locked_at timestamptz
  request_id varchar(128), trace_id char(32)   korelasi request/trace W3C asal event (P0.18); NULL untuk baris lama
  --
  idx outbox_pending (occurred_at) WHERE dispatched_at IS NULL
  idx outbox_agg (aggregate_type, aggregate_id, occurred_at)

inbox_events                              [Lintas modul]  tabel baru (BP §6, Doc 11 B-7) — dedup konsumsi event
  consumer varchar(64) NOT NULL          nama worker/daemon pemakan event
  event_id uuid NOT NULL                 id event dari outbox/provider
  payload jsonb NOT NULL DEFAULT '{}'
  received_at timestamptz NOT NULL DEFAULT now()
  status varchar(16) NOT NULL DEFAULT 'pending'   pending | processed | failed | ignored
  processed_at timestamptz, error text
  --
  uq inbox_once (consumer, event_id)                at-least-once delivery aman (BP §8.2)
  idx inbox_status (status, received_at)

idempotency_keys                          [Lintas modul]  tabel baru (BP §6, Doc 11 B-7) — mutasi klien
  actor_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE   tamu = user anonim tunggal per sesi (konvensi di §2, Doc 11 §4.4 L)
  operation varchar(80) NOT NULL         order.create, charge.create, refund.create, ...
  idempotency_key varchar(120) NOT NULL  header Idempotency-Key
  request_hash char(64) NOT NULL         SHA-256 identitas request HTTP (Doc 12 §3.4); key sama + hash beda → 409
  status varchar(16) NOT NULL DEFAULT 'in_progress'  in_progress | succeeded | failed
  response_ref varchar(200)              referensi hasil lama (mis. order code)
  response_status smallint NULL         status HTTP asli, 200 sampai 499
  response_body text NULL               isi respons HTTP asli, termasuk string kosong
  response_headers jsonb NULL           objek berisi Content-Type, Location, dan X-Request-Id yang tersedia (nilai string)
  expires_at timestamptz NOT NULL        TTL
  --
  uq idempotency_once (actor_id, operation, idempotency_key)       scope BP §6 (actor, operation, key)
  CHECK (status IN ('in_progress','succeeded','failed'))
  CHECK ck_idempotency_response_snapshot  tiga kolom snapshot semuanya NULL atau semuanya terisi dengan status 200–499
  CHECK ck_idempotency_response_headers   snapshot hanya menyimpan tiga nama header di atas
  idx idempotency_expiry (expires_at)

audit_logs                                [Audit]         append-only (S-6) — wajib untuk mutasi admin
  actor_id bigint REFERENCES users(id) ON DELETE SET NULL
  action varchar(64) NOT NULL            invoice.void, batch.close, price.update, manual_mark_paid, rbac.bypass, ...
  target_type varchar(64) NOT NULL, target_id bigint NOT NULL
  before jsonb, after jsonb              diff, bukan salinan penuh
  reason varchar(320) NOT NULL           wajib untuk aksi berisiko (Doc 01 §3.3 AuditThis)
  ip inet, user_agent varchar(255), occurred_at timestamptz NOT NULL DEFAULT now()
  request_id varchar(26) NOT NULL               ULID yang sama dengan header X-Request-Id (Doc 12 §3.2)
  --
  CHECK ck_audit_logs_reason (length(trim(reason)) > 0)
  idx audit_target (target_type, target_id, occurred_at DESC)
  idx audit_actor_time (actor_id, occurred_at DESC)
  -- Wajib juga untuk SETIAP pemakaian bypass users.is_admin: action = rbac.bypass + reason
  --   + request_id (Doc 11 §4.4 A, ADR-012 D-2). Tanpa baris ini aksi berisiko dianggap
  --   tidak terjadi; activity_log di bawah tidak menggantikan kewajiban ini.

activity_log                              [Audit]         baru (Doc 11 §4.4 F) — riwayat model otomatis spatie/laravel-activitylog
  log_name varchar(120)                  nama log (mis. default, admin)
  description text NOT NULL              ringkasan perubahan (created/updated/deleted)
  subject_type varchar(120), subject_id bigint     morph model yang berubah
  causer_type varchar(120), causer_id bigint       morph pelaku (user/admin/sistem)
  properties jsonb                       metadata tambahan aktivitas paket
  attribute_changes jsonb NULL           perubahan atribut model oleh Activitylog 5.1.5 (0054); baris lama dapat tetap NULL
  event varchar(64)                      created | updated | deleted | ...
  batch_uuid uuid                        pengelompokan satu operasi batch
  --
  idx activity_log_subject (subject_type, subject_id)
  idx activity_log_causer (causer_type, causer_id)
  idx activity_log_log_name (log_name)
  -- Kolom mengikuti bentuk standar paket (tidak di-fork); migrasinya ditulis ulang di
  --   band modul Audit, bukan dipublish dari vendor (Doc 10 §5.7).
  -- BUKAN pengganti audit_logs (Doc 11 §4.4 F): tanpa reason wajib, tidak append-only S-6,
  --   boleh kosong untuk aksi berisiko. Kewajiban audit tetap di audit_logs (AuditThis);
  --   klaim "sudah teraudit" yang hanya bersandar pada tabel ini tidak sah.

jobs / failed_jobs / job_batches          [Laravel]       tabel bawaan queue — tidak diubah
sessions, cache — driver redis, tabel tidak dipakai (Doc 01 §10)
```

## 18. View pelaporan (read-only, S-5)

```sql
-- semuanya VIEW biasa atau MATERIALIZED VIEW yang di-refresh terjadwal; tidak pernah ditulis aplikasi.

order_status_history      (order_id, occurred_at, actor_kind, from_status, to_status, payment_from, payment_to, reason)
                          = proyeksi order_events (append-only). Sengaja VIEW, bukan tabel (S-5, Doc 11 §4.1) —
                          riwayat status hanya ditulis lewat event, tidak pernah di-UPDATE.
v_stock_position          (variant_id, warehouse_id, on_hand, reserved, damaged, quarantined, in_transit, available_to_sell, min_stock, is_low)
                          dari stock_items + warehouses; tidak menghitung ulang dari ledger (B-5).
v_order_settlements       (order_id, billed_total, received_total, refunded_total, outstanding,
                          overdue_invoice_count, next_due_at)
                          ringkasan ADR-002 "order_settlements" — sengaja view, bukan tabel (S-5)
v_receivable_aging        (invoice_type, bucket_0_7, bucket_8_14, bucket_15_30, bucket_30_plus, amount_total)
                          dari receivables.aging_bucket.
v_batch_progress          (batch_id, offers, slots_taken, deposits_paid, balances_open,
                          received_qty, expected_qty, eta_max_date)
v_landed_cost             (cargo_shipment_id, variant_id, purchase_cost, intl_freight, customs, landed_total)
                          dari landed_cost_allocations + customs_charges + cargo_shipments (B-5/Phase 4).
v_sales_daily             (day, gross, discount, shipping_revenue, intl_fee_revenue,
                          service_fee_revenue, orders, aov)
v_product_ratings         (product_id, avg_rating, review_count, with_photo_count)
v_ledger_vs_provider      (day, provider, ledger_total, settlement_total, diff)   -- isi reconciliation_runs
```

Aturan view: nama diawali `v_` (kecuali `order_status_history` yang mempertahankan nama kontrak laporan), tidak punya primary key, hanya boleh dibaca lewat modul **Reporting**. Query report storefront/admin tidak pernah menyentuh tabel modul lain langsung (B1/B6), jadi nanti saat Reporting diekstrak jadi service (Doc 01 §9) yang ikut hanya view-nya.

## 19. Ringkasan kepemilikan

| Modul | Tabel | Jumlah |
|---|---|---|
| Identity | users, addresses, customer_profiles, user_consents, otp_challenges, user_sessions, roles, permissions, model_has_roles, model_has_permissions, role_has_permissions, role_scopes, user_identities, staff_2fa_secrets | 14 |
| Catalog | categories, brands, collections, products, product_collections, product_options, product_option_values, product_variants, variant_option_values, product_media, price_lists, price_list_entries | 12 |
| Content | media, pages, content_blocks, content_block_versions, nav_items, localizations | 6 |
| PreOrder | preorder_batches, preorder_batch_items, preorder_enrollments, preorder_allocations, preorder_payment_plans | 5 |
| Billing | invoices, invoice_lines, invoice_allocations, receivables | 4 |
| Orders | orders, order_items, order_charges, order_adjustments, order_addresses, order_events, returns, return_items | 8 |
| Inventory | warehouses, stock_items, stock_movements, stock_reservations, stock_transfers, stocktakes | 6 |
| Cart | carts, cart_items, checkout_sessions, wishlist_items | 4 |
| Payments | payment_intents, payment_transactions, payments, payment_ledger, refunds, webhook_receipts, reconciliation_runs, reconciliation_items | 8 |
| Fulfillment | courier_services, shipping_tariff_rules, shipping_rates_cache, packages, shipments, shipment_order_items, tracking_lanes, lane_stages, tracking_events, shipment_stage_events | 10 |
| Logistics | forwarders, consolidations, cargo_shipments, cargo_items, customs_charges, landed_cost_allocations | 6 |
| Promotion | vouchers, voucher_redemptions, loyalty_entries, loyalty_tiers, drops, drop_items, drop_claims | 7 |
| Sourcing | product_requests, sourcing_options, quotations, quotation_versions, quotation_items, quotation_visibility_grants | 6 |
| Procurement | suppliers, purchase_orders, purchase_order_items, supplier_invoices, goods_receipts, goods_receipt_items | 6 |
| MarketplacePurchase | marketplace_purchases, claim_codes, proof_files | 3 |
| UGC | reviews, review_media, review_moderation | 3 |
| Settings | feature_flags, feature_flag_overrides, settings | 3 |
| Notification | notification_templates, user_preferences, notification_logs, back_in_stock_subscriptions | 4 |
| Support | tickets, ticket_messages | 2 |
| Audit | audit_logs, activity_log | 2 |
| Lintas modul | outbox_events, inbox_events, idempotency_keys | 3 |
| **Total** | | **122** |

Ditambah tabel bawaan framework: `migrations`, `jobs`, `failed_jobs`, `job_batches`, `cache`(opsional), `sessions`(opsional).

Angka 122 adalah hasil parsing isi dokumen ini oleh skrip pemeriksa yang sama (Doc 10 §12), bukan hitung tangan — draft lama meleset +3 tanpa ketahuan. Empat tabel terakhir datang dari pengesahan Doc 11 §4.2 (2026-11-17): `returns` + `return_items` (Orders, 6 → 8), `wishlist_items` (Cart, 3 → 4), `back_in_stock_subscriptions` (Notification, 3 → 4); 114 → 118. Parser itu juga yang membuktikan graf FK tetap **asiklik** (0 siklus) setelah empat tabel itu masuk, dan menolak setiap nama lama §4.1 yang tersisa. Perubahan jumlah apa pun wajib menjalankan ulang skrip itu, bukan menaksir.

Delta 2026-11-18 (keputusan pemilik, Doc 11 §4.4 A–F): Identity melepas dua pivot lama (`role_user`, `permission_role` — tidak berlaku) dan menerima lima tabel pengganti/baru (`model_has_roles`, `model_has_permissions`, `role_has_permissions`, `role_scopes`, `user_identities`): 11 → 14. Audit menerima `activity_log` (bukan pengganti `audit_logs`): 1 → 2. Total 118 → 122; parser melaporkan 125 blok deklarasi termasuk 3 tabel bawaan `[Laravel]` (`jobs`/`failed_jobs`/`job_batches`). Angka pada paragraf ini diproduksi ulang oleh `verify_schema.py` yang dijalankan 2026-11-18 setelah perubahan, bukan disalin dari ingatan; ia juga memverifikasi 0 target FK tak dikenal dan 0 siklus setelah dua tabel baru masuk. `users` kehilangan kolom pembeda populasi yang lama dan mendapat `is_operator`/`is_admin` + CHECK + indeks `users_operator_status` (rename dari `users_kind_status` yang lama); `roles`/`permissions` mengganti `code` menjadi `name` + `guard_name` tanpa mengubah jumlah tabel.

Empat kolom back-edge polos yang disebutkan di §20 tetap **empat** — keempat tabel baru tidak menambah back-edge: `returns` menunjuk `orders`/`refunds`/`users` satu arah, `return_items` menunjuk `returns`/`order_items`, dan dua tabel turunan Doc 05 hanya menunjuk `users`/`product_variants`. Tidak ada baris order yang menunjuk retur, jadi tidak ada siklus yang perlu di-break.

## 20. Yang sengaja TIDAK ada di skema ini

- **`request_attachments` sebagai tabel.** Lampiran request order dilayani `media` polimorfik (S-3) dengan `kind='attachment'` via `product_requests.reference_media_id`. Membuat tabel kedua untuk fakta yang sama melanggar B1 (Doc 11 §4.2 tanda `*`).
- **`order_status_history` sebagai tabel.** Riwayat = turunan `order_events`; ia tetap **view** (§18). Menyimpan salinan akan mengizinkan edit histori yang sudah menjadi bukti audit (S-5/S-6, Doc 11 §4.1).
- **`payables`, `expenses`, dan cash/bank.** Buku besar ganda di luar kebutuhan platform commerce kita; rekomendasi = export ke akuntansi klien, bukan tabel baru. Menunggu keputusan klien **Q-3** (Doc 11 §4.3).
- **`staff_users` — tidak dibuat.** Kita tidak membuat tabel keduanya: satu baris `users` memuat kedua populasi, dan pembedanya sejak 2026-11-18 adalah **`users.is_operator`** (kolom `kind` lama diganti dua boolean `is_operator`/`is_admin`, Doc 11 §4.4 A; `users.kind = customer | staff` tidak berlaku lagi). Atribut ekstra staff (`2FA`, `staff_2fa_secrets`) sudah punya tempat sendiri. Dua tabel untuk satu orang adalah sumber bug "staf yang juga customer". Alasan lengkap + konsekuensi (Blueprint §6 menyebutnya "tabel minimum" → secara harfiah kita kurang satu nama; angka itu dibaca sebagai daftar peran, bukan keharusan fisik tabel) ada di **Doc 11 §4.3**; entri ini ada supaya parser pembanding tidak menandainya sebagai kelalaian diam-diam.
- **`personal_access_tokens` — tidak dibuat.** Sanctum dipakai untuk **cookie sesi same-site** (`statefulApi`), bukan personal access token: tidak ada token jangka panjang di browser (ADR-007 D-3a, Doc 11 §4.4 C). Satu-satunya migrasi bawaan Sanctum justru membuat tabel ini, jadi migrasi vendor itu dikecualikan secara eksplisit (Doc 10 §5.7); sesi admin/storefront dipisah lewat nama cookie + guard (ADR-007 D-2a/D-4a), dan kredensial server-to-server memakai tanda tangan/HMAC atau jaringan internal, bukan token browser. Menambahkan tabel ini kelak = membuka kembali keputusan ADR-007, bukan detail konfigurasi.
- **`purchase_requisitions`.** Tidak dibuat sebagai tabel. Requisition dimodelkan sebagai `purchase_orders.status = draft | pending_approval` (§11) + tahap `requisitioned` pada `orders.procurement_status` (§6) — entitas kedua untuk "PO yang belum disetujui" melanggar aturan satu-fakta-satu-tempat (B1). Dasar: Doc 11 §4.3.
- **Kolom bebas `unavailable` ke-5 di `stock_items`.** `unavailable` **didefinisikan** = `damaged + quarantined` dan dijaga CHECK non-negatif (Doc 11 B-5), bukan disimpan sebagai kolom terpisah yang bisa melenceng.
- **Kolom `status_label` untuk customer.** Label = turunan `payment_status` × `fulfillment_status` × lane (Doc 01 §6); menyimpannya = sumber bug.
- **Uang desimal / kurs live.** Blueprint §6 mengizinkan `NUMERIC(20,4)`; kita **tolak** karena dua representasi uang dalam satu basis data adalah sumber bug pembulatan (Doc 11 B-13). Kurs hanya lewat `settings` + snapshot `exchange_rate_snapshot` per baris.
- **Kredensial gateway/kurir di DB.** Secret hanya env (Doc 01 §9.3). Yang disimpan hanya `provider`/`method` dan referensi respons.
- **`proof_files` berisi byte.** File di object storage, tabel hanya menunjuk `media_id`.
- **Soft delete di tabel uang.** Invoice/payment_ledger tidak punya `deleted_at`; pembatalan = status `void`/entri reversal (S-6).
- **Data pribadi customer sumber.** Skema ini kosong; isi hanya dari klien Anda (Doc 00 §Batas).
- **FK keras untuk back-edge siklus.** `carts.converted_order_id`, `checkout_sessions.converted_order_id`, `quotations.converted_order_id`, `pages.published_version_id` adalah `bigint` biasa (tanpa `REFERENCES`) — snapshot jejak, bukan kendala integritas; memecah graf agar tetap asiklik (Doc 10 §1 M-5).

### Usulan (belum di Doc 11 §4)

Nama-nama ini mengikuti aturan S-11 (prefik/jamak) tapi tidak tercantum eksplisit di tabel rename §4.1 — dinyatakan sebagai usulan, bukan klaim Blueprint:

- **Label modul ikut nama domain service Blueprint §3**: `Fulfillment` (bekas label `Shipping`), `Sourcing` (bekas `Quotes`), `Logistics` (bekas `IntlLogistics`). Satu vocabularium dipakai Doc 01 §3.3, Doc 09 §19, Doc 10 Appendix A dan direktori `app/Modules/<X>/`; pemisahan `Logistics` (forwarders/consolidations/cargo/customs/landed_cost) dari `Fulfillment` domestik **tetap dua modul** supaya kepemilikan jelas, meski Blueprint §4 menaruh keduanya dalam satu area menu.
- **`preorder_payment_plans`** (dari `po_payment_plans`) dan **`quotation_visibility_grants`** (dari `quote_visibility_grants`) — rename prefik mengikuti `preorder_*`/`quotation_*`; §4.1 tidak menyebut keduanya karena Blueprint §6 juga tidak memuat tabel ini.
- **Nilai lane `marketplace_purchase`** (menggantikan `procurement`) pada `fulfillment_lane`, `shipments.lane`, `order_items.fulfillment_mode`, `tracking_lanes.code`, `lane_stages.lane` — konsekuensi langsung dari B-4 (modul D5 berganti nama). Nilai `import_po` tetap untuk jalur supplier PO.
- **`idempotency_keys.actor_id` wajib `NOT NULL`.** Tamu checkout memakai user anonim per sesi (§2, Doc 11 §4.4 L), bukan null, supaya scope `(actor, operation, key)` Blueprint tetap berfungsi tanpa baris nullable yang merusak unique.
