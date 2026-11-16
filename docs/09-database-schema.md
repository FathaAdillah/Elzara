# Dokumen 09 — Struktur Database (PostgreSQL 16)

Tanggal: 2026-11-16 · Status: **baseline untuk Sprint 0–7** · Mesin: PostgreSQL 16 · Pemilik skema: modul (lihat aturan B1/B6 di Doc 01 §3).

Dokumen ini adalah satu-satunya sumber kebenaran untuk bentuk data. Kalau kode dan dokumen ini bertentangan, dokumen yang salah — perbaiki dokumen lewat pull request, jangan diam-diam lewat migrasi.

Pasangan dokumen: `10-migration-plan.md` (cara mengubah skema ini tanpa saling menggesek).

## 1. Keputusan desain yang mengikat

| # | Keputusan | Alasan |
|---|---|---|
| S-1 | **Uang = `bigint` rupiah utuh**, bukan `numeric`, bukan `float`. Kolom selalu berpasangan dengan `currency char(3) default 'IDR'`. | U1 di Doc 01 §5. Float mustahil diaudit; `numeric` godaan untuk menyimpan desimal yang aturannya bilang tidak ada. `bigint` cukup sampai Rp9,2 kuadriliun. |
| S-2 | **Enum = `varchar` + `CHECK`, bukan `CREATE TYPE ... AS ENUM`.** | Menambah nilai pada Postgres enum butuh DDL dan mengunci tabel; nilai bisnis (tipe invoice, alasan refund) akan bertambah. `CHECK` cukup dan bisa di-`DROP CONSTRAINT` tanpa rewrite. |
| S-3 | **`media` dimiliki modul Content, bukan Catalog** (meluruskan daftar tabel di Doc 01 §3). | Tabelnya polimorfik dan dipakai produk **dan** avatar/banner profil (D6) **dan** bukti transfer procurement **dan** foto ulasan. Menaruhnya di Catalog melanggar B1 karena tiga modul akan menulis ke sana. |
| S-4 | **PK internal `bigint identity`, identitas publik = `*_code varchar unique`.** | Nomor order/invoice dipakai customer, kurir, dan support → tidak boleh membocorkan volume transaksi (id berurutan) dan harus stabil. |
| S-5 | **Kolom turunan tidak boleh ditulis.** Status label customer, `total_billed`, `margin` = view/generated column. | Mencegah state busuk; sesuai B5 (uang & status hanya lewat aggregate). |
| S-6 | **Tabel append-only**: `payment_ledger`, `order_events`, `stock_movements`, `audit_logs`, `tracking_events`, `outbox_events`. Tidak ada `UPDATE` atas baris masa lalu; koreksi = entri baru dengan `reverses_id`. | Bukti audit. Rekonsiliasi (U5) tidak mungkin kalau histori bisa diedit. |
| S-7 | **Soft delete hanya untuk** `users`, `products`, `pages`, `content_block_versions`, `vouchers`. Selain itu pakai `status = 'archived'`. | `deleted_at` di semua tabel membuat unique constraint dan report salah (baris "terhapus" tetap menabrak unique). |
| S-8 | **Snapshot, bukan referensi hidup**, untuk data yang masuk dokumen transaksi: alamat, harga, kuota, nama varian, tarif. | D7. Edit profil/admin tidak boleh mengubah invoice tahun lalu. |
| S-9 | **`timestamptz` selalu**, disimpan UTC, ditampilkan per `users.timezone`. | Toko punya buyer luar negeri (jalur request) dan jam jatuh tempo itu mengikat uang. |
| S-10 | **FK lintas modul boleh di level DB, dilarang di level Eloquent.** Relasi objek hanya intra-modul; antar-modul lewat Contracts/DTO. | B1 + B5. Integritas referensial tetap dijaga Postgres, tapi graph object lintas modul tidak terbentuk → pemisahan skema nanti mekanis. |

### Konvensi

- Nama: tabel `snake_case` plural, kolom `snake_case`, FK `{tabel_singular}_id`, index `idx_{tabel}_{kolom}`, unique `uq_{tabel}_{kolom}`, check `ck_{tabel}_{syarat}`, FK `fk_{tabel}_{referensi}`.
- Setiap tabel punya `id bigint generated always as identity primary key`, `created_at timestamptz not null default now()`, `updated_at timestamptz not null default now()` — **tidak diulang** di definisi bawah. Tabel append-only boleh tidak punya `updated_at`.
- Kolom `*_at` yang null berarti "belum terjadi", bukan "tidak diketahui".
- Tabel yang ditulis admin/staff saja punya `created_by bigint references users(id)` (diisi otomatis oleh trait audit).
- JSONB hanya untuk payload benar-benar bebas bentuk (`content_block_versions.payload`, `webhook_events.body`, `settings.value`). Data yang di-query jangan pernah masuk JSONB.

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
  password_hash    varchar(255)                       null bila akun dibuat lewat admin/import
  kind             varchar(16) NOT NULL DEFAULT 'customer'   customer | staff
  status           varchar(16) NOT NULL DEFAULT 'active'     active | suspended | deletion_requested | deleted
  locale           varchar(5)  NOT NULL DEFAULT 'id'         D6 i18n ID/EN
  timezone         varchar(64) NOT NULL DEFAULT 'Asia/Jakarta'
  avatar_media_id  bigint NULL REFERENCES media(id) ON DELETE SET NULL
  banner_media_id  bigint NULL REFERENCES media(id) ON DELETE SET NULL
  marketing_optin  boolean NOT NULL DEFAULT false
  last_login_at    timestamptz
  deleted_at       timestamptz                        hak hapus (Doc 01 §8)
  --
  CHECK (kind IN ('customer','staff'))
  CHECK (status IN ('active','suspended','deletion_requested','deleted'))
  idx users_kind_status (kind, status)

user_addresses                            [Identity]
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
  lat numeric(9,6)  lng numeric(9,6)              pinpoint (M2 Doc 02 §3.3)
  is_default  boolean NOT NULL DEFAULT false
  is_archived boolean NOT NULL DEFAULT false
  --
  uq user_addresses_default (user_id) WHERE is_default AND NOT is_archived   partial unique: max 1 default
  idx user_addresses_user (user_id)

user_consents                             [Identity]
  user_id   bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  kind      varchar(32) NOT NULL       terms | privacy | po_deposit_forfeit | marketing | age_gate
  policy_version integer NOT NULL      merujuk content_block_versions.version → consent bisa dibuktikan terhadap teks yang berlaku
  granted_at timestamptz NOT NULL
  ip inet, user_agent varchar(255)
  --
  uq user_consents_once (user_id, kind, policy_version)

otp_challenges                            [Identity]
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  channel varchar(16) NOT NULL         email | whatsapp | sms
  code_hash varchar(255) NOT NULL      jangan simpan kode mentah
  purpose varchar(24) NOT NULL         login | reset_password | verify_phone
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

roles / permissions / role_user / permission_role      [Identity]
  roles: code varchar(48) UNIQUE, name varchar(80), description text, is_system boolean
  permissions: code varchar(64) UNIQUE, group varchar(40)      mis. billing.void, order.force_transition
  role_user: role_id + user_id + scope (mis. batch_id NULL = global)
  permission_role: permission_id + role_id
  --
  ROLE ADMIN 7–8 role Doc 06 §1 di-seed, bukan hardcode. Pemisah "pengaju vs pemberi persetujuan"
  (Doc 06 §1) ditegakkan oleh permission berpasangan: *.request vs *.approve.

staff_2fa_secrets                         [Identity]      2FA admin (Doc 01 §8)
  user_id bigint NOT NULL UNIQUE REFERENCES users(id) ON DELETE CASCADE
  secret_encrypted bytea NOT NULL      terenkripsi dengan app key, jangan pernah plaintext
  confirmed_at timestamptz, recovery_hash varchar(255)
```

## 3. Catalog

```
categories                                [Catalog]
  slug varchar(80) NOT NULL UNIQUE       8 nilai awal terverifikasi di Doc 08 §5
  name_key varchar(96) NOT NULL          key i18n → localizations (D6), bukan teks mentah
  parent_id bigint NULL REFERENCES categories(id) ON DELETE SET NULL
  icon_media_id bigint NULL REFERENCES media(id) ON SET NULL   aset terupload, bukan glyph (Doc 08 §5)
  image_alt_key varchar(96)              gerbang aksesibilitas Doc 04 §7
  position smallint NOT NULL DEFAULT 0
  is_active boolean NOT NULL DEFAULT true
  --
  CHECK (parent_id <> id)
  idx categories_tree (parent_id, position)

collections                               [Catalog]       sumbu kedua: fandom/IP (Doc 05 §2)
  slug varchar(80) NOT NULL UNIQUE, name_key varchar(96) NOT NULL
  kind varchar(24) NOT NULL DEFAULT 'fandom'    fandom | ip | kolaborasi | tema
  banner_media_id bigint NULL REFERENCES media(id) ON DELETE SET NULL
  description_key text, is_active boolean NOT NULL DEFAULT true

products                                  [Catalog]
  slug varchar(120) NOT NULL UNIQUE
  name varchar(200) NOT NULL
  brand varchar(100), series varchar(120)
  category_id bigint NOT NULL REFERENCES categories(id) ON DELETE RESTRICT
  status varchar(16) NOT NULL DEFAULT 'draft'    draft | active | archived
  origin_country varchar(2) NOT NULL             kode ISO negara — terstruktur (Doc 05 §3 P-5)
  fulfillment_modes varchar(32)[] NOT NULL DEFAULT '{}'    {rts,po,request} — satu produk bisa beberapa mode
  unit_weight_g integer                          untuk estimasi ongkir; berat nyata dari GRN
  hs_code varchar(12)                            untuk estimasi bea masuk (D3)
  description text, care_notes text
  return_policy_notes text                       syarat retur per kategori (Doc 02 §3.2)
  meta_title varchar(120), meta_description varchar(300)
  deleted_at timestamptz
  --
  CHECK (origin_country ~ '^[A-Z]{2}$')
  idx products_cat_status (category_id, status)
  idx products_status (status) WHERE status = 'active'

product_collections                       [Catalog]
  product_id bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE
  collection_id bigint NOT NULL REFERENCES collections(id) ON DELETE CASCADE
  -- PRIMARY KEY (product_id, collection_id)

product_options                           [Catalog]       sumbu varian yang didefinisikan admin
  product_id bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE
  name varchar(60) NOT NULL              "Ukuran", "Anggota", "Tipe kartu"
  position smallint NOT NULL DEFAULT 0
  -- uq product_options_name (product_id, name)

product_option_values                     [Catalog]
  option_id bigint NOT NULL REFERENCES product_options(id) ON DELETE CASCADE
  value varchar(60) NOT NULL
  position smallint NOT NULL DEFAULT 0
  -- uq option_value (option_id, value)

variants                                  [Catalog]       1 varian = 1 kombinasi yang bisa dibeli
  product_id bigint NOT NULL REFERENCES products(id) ON DELETE CASCADE
  sku varchar(64) NOT NULL UNIQUE
  label varchar(160) NOT NULL            snapshot gabungan nilai utk tampilan ("XL · Jisoo · Holo")
  base_price bigint NOT NULL             harga dasar; harga jual nyata ada di batch_offers / order_items (S-8)
  currency char(3) NOT NULL DEFAULT 'IDR'
  track_stock boolean NOT NULL DEFAULT true       false untuk barang made-to-order per batch
  min_stock integer NOT NULL DEFAULT 0            pemicu StockLow
  is_active boolean NOT NULL DEFAULT true
  deleted_at timestamptz
  --
  CHECK (base_price >= 0)
  idx variants_product (product_id) WHERE is_active

variant_option_values                     [Catalog]
  variant_id bigint NOT NULL REFERENCES variants(id) ON DELETE CASCADE
  option_value_id bigint NOT NULL REFERENCES product_option_values(id) ON DELETE RESTRICT
  -- PRIMARY KEY (variant_id, option_value_id)

media                                     [Content]       lihat S-3
  uuid uuid NOT NULL DEFAULT gen_random_uuid() UNIQUE
  model_type varchar(64) NOT NULL        App\Models\... (Product, User, Review, ProcurementProof)
  model_id bigint NOT NULL
  kind varchar(24) NOT NULL              image | video | file | icon | label_pdf | proof
  disk varchar(24) NOT NULL DEFAULT 's3'
  path varchar(512) NOT NULL
  mime varchar(80), size_bytes bigint, width integer, height integer
  sha256 char(64)                         deteksi duplikat + integritas
  alt_key varchar(120)                     alt text wajib untuk gambar produk (Doc 04 §7)
  position smallint NOT NULL DEFAULT 0
  is_moderated boolean NOT NULL DEFAULT false    media user masuk antrean moderasi (Doc 06 §3.9)
  moderation_status varchar(16) NOT NULL DEFAULT 'pending'  pending | approved | rejected
  --
  idx media_model (model_type, model_id, kind, position)
  uq media_path (disk, path)
```

## 4. PreOrder (batch sebagai kelas satu — D1)

```
po_batches                                [PreOrder]
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
  currency char(3) NOT NULL DEFAULT 'IDR'
  closed_at timestamptz, cancelled_at timestamptz, cancel_reason text
  created_by bigint REFERENCES users(id)
  --
  CHECK (order_window_close_at > order_window_open_at)
  CHECK (intl_fee_max >= intl_fee_min)
  CHECK (deposit_kind IN ('percent','flat'))
  CHECK (deposit_value >= 0)
  idx po_batches_status_window (status, order_window_close_at)

batch_offers                              [PreOrder]      penawaran varian DALAM satu batch (D1)
  batch_id bigint NOT NULL REFERENCES po_batches(id) ON DELETE RESTRICT
  variant_id bigint NOT NULL REFERENCES variants(id) ON DELETE RESTRICT
  quota integer NOT NULL                          kuota lembut batch, bukan stok fisik
  taken_qty integer NOT NULL DEFAULT 0            dijaga oleh reservation, dinaikkan saat order
  price bigint NOT NULL                           snapshot harga pada batch ini (S-8)
  currency char(3) NOT NULL DEFAULT 'IDR'
  price_valid_until timestamptz                   D7: harga bisa kedaluwarsa sebelum dikonfirmasi
  origin_country varchar(2), origin_shop varchar(120)    dari toko/negara mana dibeli (Doc 05 §3 P-5)
  is_active boolean NOT NULL DEFAULT true
  --
  uq batch_offers_slot (batch_id, variant_id)
  CHECK (taken_qty >= 0 AND (quota IS NULL OR taken_qty <= quota))
  idx batch_offers_variant (variant_id) WHERE is_active

po_lines                                  [PreOrder]      posisi satu order_item di sebuah batch
  batch_id bigint NOT NULL REFERENCES po_batches(id) ON DELETE RESTRICT
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE
  order_item_id bigint NOT NULL REFERENCES order_items(id) ON DELETE CASCADE
  batch_offer_id bigint NOT NULL REFERENCES batch_offers(id) ON DELETE RESTRICT
  qty integer NOT NULL
  state varchar(24) NOT NULL DEFAULT 'reserved'
        reserved | deposit_paid | awaiting_balance | on_hold | fully_paid | received | shipped | released | expired | cancelled
  slot_reserved_at timestamptz NOT NULL
  slot_expires_at timestamptz NOT NULL            slot batchexpire, bukan stok fisik (Doc 03 Sprint 3)
  received_qty integer NOT NULL DEFAULT 0         partial GRN per item (Doc 06 §3.1)
  --
  uq po_lines_one_per_item (order_item_id, batch_offer_id)
  idx po_lines_batch_state (batch_id, state)
  idx po_lines_expiry (slot_expires_at) WHERE state = 'reserved'

po_payment_plans                          [PreOrder]      rencana pelunasan per order
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE
  batch_id bigint NOT NULL REFERENCES po_batches(id) ON DELETE RESTRICT
  deposit_invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL
  balance_invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL
  due_at timestamptz NOT NULL
  reminder_state varchar(24) NOT NULL DEFAULT 'pending'   pending | h7_sent | h1_sent | due_sent
  extension_count smallint NOT NULL DEFAULT 0
  extended_by bigint REFERENCES users(id), extension_reason text
  executed_at timestamptz                          saat kebijakan jatuh tempo dijalankan
  --
  uq po_plan_order_batch (order_id, batch_id)
```

## 5. Billing (modul baru — ADR-002)

```
invoices                                  [Billing]
  code varchar(24) NOT NULL UNIQUE       INV-2026-000123 (S-4)
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT
  type varchar(20) NOT NULL              deposit | balance | intl_fees | domestic_ship | extra_ship | adjustment
  amount bigint NOT NULL
  currency char(3) NOT NULL DEFAULT 'IDR'
  status varchar(16) NOT NULL DEFAULT 'open'      open | paid | overdue | void | refunded
  reason varchar(320)                    WAJIB diisi bila type IN (adjustment, extra_ship) — lihat CHECK
  due_at timestamptz                     NULL untuk tagihan yang jatuh temponya tidak berlaku (adjustment instan)
  issued_at timestamptz NOT NULL DEFAULT now()
  paid_at timestamptz, voided_at timestamptz, void_reason varchar(320)
  reconsent_required boolean NOT NULL DEFAULT false    RS-4: nominal bergeser > ambang → buyer konfirmasi ulang
  reconsent_at timestamptz
  batch_id bigint REFERENCES po_batches(id) ON DELETE SET NULL
  created_by bigint REFERENCES users(id) NULL         NULL = dibuat sistem (job GRN)
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

invoice_allocations                       [Billing]       A3: satu intent gabungan → alokasi per invoice
  payment_intent_id bigint NOT NULL REFERENCES payment_intents(id) ON DELETE RESTRICT
  invoice_id bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT
  amount bigint NOT NULL CHECK (amount > 0)
  allocated_at timestamptz NOT NULL DEFAULT now()
  --
  uq alloc_intent_invoice (payment_intent_id, invoice_id)
  idx alloc_invoice (invoice_id)
  -- invariant (dijaga aggregate, bukan DB): SUM(allocation) = intent.amount
```

## 6. Orders

```
orders                                    [Orders]
  code varchar(24) NOT NULL UNIQUE       ELZ-2611-00042 (S-4)
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT
  status varchar(24) NOT NULL DEFAULT 'pending_payment'
        pending_payment | paid | awaiting_batch | ready_to_ship | shipped | delivered |
        completed | on_hold | cancelled | expired | refund_requested | refunded
  money_status varchar(20) NOT NULL DEFAULT 'unpaid'
        unpaid | partially_paid | paid | overdue | refund_pending | refunded      (Doc 01 §6 sumbu 1)
  primary_lane varchar(16) NOT NULL DEFAULT 'rts'   rts | import_po | request | procurement  (D4)
  subtotal bigint NOT NULL DEFAULT 0
  discount_total bigint NOT NULL DEFAULT 0
  shipping_total bigint NOT NULL DEFAULT 0
  service_fee_total bigint NOT NULL DEFAULT 0
  intl_fee_total bigint NOT NULL DEFAULT 0          snapshot hasil invoice intl_fees (S-5 tidak berlaku:
                                                    ini kolom dokumen order, diisi saat terbit, tidak dihitung ulang)
  total bigint NOT NULL DEFAULT 0
  currency char(3) NOT NULL DEFAULT 'IDR'
  points_earned integer NOT NULL DEFAULT 0, points_redeemed integer NOT NULL DEFAULT 0
  price_lock_expires_at timestamptz                 D7
  placed_at timestamptz, paid_at timestamptz
  cancelled_at timestamptz, cancel_reason varchar(320)
  quote_id bigint REFERENCES quotes(id) ON DELETE SET NULL
  notes text, admin_notes text
  --
  CHECK (subtotal >= 0 AND total >= 0 AND discount_total >= 0)
  CHECK (money_status IN ('unpaid','partially_paid','paid','overdue','refund_pending','refunded'))
  CHECK (primary_lane IN ('rts','import_po','request','procurement'))
  idx orders_user_placed (user_id, placed_at DESC)
  idx orders_status (status) WHERE status IN ('awaiting_batch','ready_to_ship','shipped','on_hold')

order_items                               [Orders]
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES variants(id) ON DELETE RESTRICT
  batch_offer_id bigint REFERENCES batch_offers(id) ON DELETE SET NULL    NULL = jalur RTS
  fulfillment_mode varchar(16) NOT NULL   rts | po | request | procurement
  qty integer NOT NULL CHECK (qty > 0)
  unit_price bigint NOT NULL              snapshot (S-8)
  unit_price_snapshot_currency char(3) NOT NULL DEFAULT 'IDR'
  product_name_snapshot varchar(200) NOT NULL      jangan join ke Catalog untuk cetak invoice (B5/S-8)
  variant_label_snapshot varchar(160) NOT NULL
  discount_amount bigint NOT NULL DEFAULT 0
  line_total bigint GENERATED ALWAYS AS (qty * unit_price - discount_amount) STORED
  split_group varchar(24)                  U4: baris dengan grup sama digabung ke shipment yang sama
  --
  idx order_items_order (order_id)
  idx order_items_variant (variant_id)

order_addresses                           [Orders]       D7 snapshot alamat, satu per order
  order_id bigint NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE
  source_address_id bigint NULL            referensi asal untuk audit, TIDAK dipakai untuk membaca
  recipient varchar(120) NOT NULL, phone varchar(24) NOT NULL
  province varchar(60) NOT NULL, city varchar(60) NOT NULL
  district varchar(60), village varchar(60), postal_code varchar(10) NOT NULL
  address_line text NOT NULL
  lat numeric(9,6), lng numeric(9,6)
  snapshot_at timestamptz NOT NULL DEFAULT now()

shipments                                 [Orders]       1 order → n shipment per lane (U4/D4)
  code varchar(24) NOT NULL UNIQUE       SHP-...
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT
  lane varchar(16) NOT NULL              rts | import_po | request | procurement
  stage_id bigint REFERENCES lane_stages(id) ON DELETE SET NULL    tahap saat ini, turunan (S-5: hanya aggregate menulis)
  courier_code varchar(32), service_code varchar(32)
  tracking_number varchar(80), tracking_url varchar(400)
  status varchar(24) NOT NULL DEFAULT 'pending'    pending | label_created | handed_over | in_transit | delivered | failed | returned
  estimated_delivery_date date, shipped_at timestamptz, delivered_at timestamptz
  weight_g integer, dims_cm varchar(24)
  --
  CHECK (lane IN ('rts','import_po','request','procurement'))
  idx shipments_order (order_id)
  idx shipments_tracking (tracking_number) WHERE tracking_number IS NOT NULL
  idx shipments_stage (lane, stage_id)

shipment_order_items                      [Orders]       isi paket (hubungan banyak-ke-banyak)
  shipment_id bigint NOT NULL REFERENCES shipments(id) ON DELETE CASCADE
  order_item_id bigint NOT NULL REFERENCES order_items(id) ON DELETE RESTRICT
  qty integer NOT NULL CHECK (qty > 0)
  -- PRIMARY KEY (shipment_id, order_item_id)

order_events                              [Orders]       append-only (S-6)
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE CASCADE
  actor_kind varchar(12) NOT NULL        system | customer | staff
  actor_id bigint, from_status varchar(24), to_status varchar(24)
  money_from varchar(20), money_to varchar(20)
  reason varchar(320)                    wajib untuk transisi manual
  payload jsonb, occurred_at timestamptz NOT NULL
  reverses_id bigint REFERENCES order_events(id)
  --
  idx order_events_order_time (order_id, occurred_at)

refunds                                   [Orders]
  code varchar(24) NOT NULL UNIQUE
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT
  invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL
  amount bigint NOT NULL CHECK (amount >= 0)
  currency char(3) NOT NULL DEFAULT 'IDR'
  status varchar(20) NOT NULL DEFAULT 'requested'
        requested | approved | rejected | processing | completed | failed
  reason varchar(320) NOT NULL, kind varchar(20) NOT NULL   full | partial | goodwill | shipping_error
  provider_refund_ref varchar(80)
  requested_by bigint REFERENCES users(id), approved_by bigint REFERENCES users(id)
  approved_at timestamptz, completed_at timestamptz
  --
  CHECK (approved_by IS NULL OR approved_by <> requested_by)     pemisah pengaju vs pemberi setuju (Doc 06 §1)
  idx refunds_status (status) WHERE status NOT IN ('completed','rejected')
```

## 7. Inventory

```
stock_locations                           [Inventory]
  code varchar(24) NOT NULL UNIQUE, name varchar(120) NOT NULL
  kind varchar(16) NOT NULL              overseas_hub | in_transit | warehouse | supplier
  country varchar(2) NOT NULL DEFAULT 'CN'
  is_active boolean NOT NULL DEFAULT true

stock_movements                           [Inventory]    append-only (S-6)
  variant_id bigint NOT NULL REFERENCES variants(id) ON DELETE RESTRICT
  location_id bigint NOT NULL REFERENCES stock_locations(id) ON DELETE RESTRICT
  qty_delta integer NOT NULL             + masuk (GRN), - keluar (kirim), pembatalan = entri balik
  reason varchar(28) NOT NULL
        grn | grn_reversal | sale_ship | sale_return | adjustment | damage | transfer_in | transfer_out
  ref_type varchar(40), ref_id bigint     mis. grn / shipment
  actor_id bigint REFERENCES users(id)
  occurred_at timestamptz NOT NULL, reverses_id bigint REFERENCES stock_movements(id)
  --
  CHECK (qty_delta <> 0)
  idx stock_movements_variant (variant_id, occurred_at DESC)
  idx stock_movements_ref (ref_type, ref_id)

reservations                              [Inventory]    stok fisik ditahan, bukan slot batch
  variant_id bigint NOT NULL REFERENCES variants(id) ON DELETE RESTRICT
  location_id bigint NOT NULL REFERENCES stock_locations(id) ON DELETE RESTRICT
  order_item_id bigint REFERENCES order_items(id) ON DELETE CASCADE
  qty integer NOT NULL CHECK (qty > 0)
  status varchar(16) NOT NULL DEFAULT 'held'     held | committed | released | expired
  expires_at timestamptz NOT NULL               TTL: "stok reservasi lupa" (Doc 01 §12)
  committed_at timestamptz, released_at timestamptz
  --
  idx reservations_expiry (expires_at) WHERE status = 'held'
  idx reservations_variant (variant_id, status)
  -- invariant: SUM(held+committed qty) per (variant,location) <= on_hand (dijaga ReserveStock, SELECT ... FOR UPDATE)

grns                                      [Inventory]    Goods Received Note saat batch tiba
  code varchar(24) NOT NULL UNIQUE
  batch_id bigint NOT NULL REFERENCES po_batches(id) ON DELETE RESTRICT
  location_id bigint NOT NULL REFERENCES stock_locations(id) ON DELETE RESTRICT
  received_at timestamptz NOT NULL DEFAULT now()
  weight_g_total integer                      berat nyata → dasar invoice intl_fees (A1/D3)
  declared_value bigint, tax_amount bigint, ems_amount bigint      pemecahan biaya leg internasional
  status varchar(16) NOT NULL DEFAULT 'draft'   draft | posted | reversed
  posted_by bigint REFERENCES users(id), notes text
  --
  idx grns_batch (batch_id)

grn_items                                 [Inventory]
  grn_id bigint NOT NULL REFERENCES grns(id) ON DELETE CASCADE
  po_line_id bigint NOT NULL REFERENCES po_lines(id) ON DELETE RESTRICT
  qty_expected integer NOT NULL, qty_received integer NOT NULL, qty_damaged integer NOT NULL DEFAULT 0
  weight_g integer
  --
  CHECK (qty_received >= 0 AND qty_damaged >= 0)
  idx grn_items_po_line (po_line_id)
```

## 8. Cart

```
carts                                     [Cart]
  token varchar(32) UNIQUE               cart guest; terangkat ke user_id saat login
  user_id bigint REFERENCES users(id) ON DELETE CASCADE
  status varchar(16) NOT NULL DEFAULT 'active'    active | converted | abandoned | expired
  currency char(3) NOT NULL DEFAULT 'IDR'
  expires_at timestamptz NOT NULL        TTL cart (Doc 01 §12)
  converted_order_id bigint REFERENCES orders(id) ON DELETE SET NULL
  --
  CHECK (user_id IS NOT NULL OR token IS NOT NULL)
  idx carts_expiry (expires_at) WHERE status = 'active'

cart_items                                [Cart]
  cart_id bigint NOT NULL REFERENCES carts(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES variants(id) ON DELETE RESTRICT
  batch_offer_id bigint REFERENCES batch_offers(id) ON DELETE SET NULL
  qty integer NOT NULL CHECK (qty > 0)
  unit_price_snapshot bigint NOT NULL     harga saat ditambahkan; divalidasi ulang saat checkout (D7)
  added_at timestamptz NOT NULL DEFAULT now()
  --
  uq cart_item_line (cart_id, variant_id, COALESCE(batch_offer_id,0))   unique on expression
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
  amount bigint NOT NULL, currency char(3) NOT NULL DEFAULT 'IDR'
  status varchar(20) NOT NULL DEFAULT 'pending'
        pending | requires_action | authorized | captured | void | expired | failed
  provider_ref varchar(120), idempotency_key varchar(80) NOT NULL UNIQUE
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
  kind varchar(20) NOT NULL              auth | capture | cancel | refund | refund_partial | settle | void
  status varchar(16) NOT NULL            pending | succeeded | failed
  amount bigint NOT NULL, currency char(3) NOT NULL DEFAULT 'IDR'
  provider_ref varchar(120), occurred_at timestamptz NOT NULL
  raw jsonb
  --
  idx txn_intent (intent_id, occurred_at)

payment_ledger                            [Payments]     append-only (S-6), kunci invoice (ADR-002)
  entry_no bigint GENERATED ALWAYS AS IDENTITY UNIQUE
  invoice_id bigint NOT NULL REFERENCES invoices(id) ON DELETE RESTRICT
  intent_id bigint REFERENCES payment_intents(id) ON DELETE SET NULL
  direction varchar(6) NOT NULL          debit | credit
  amount bigint NOT NULL CHECK (amount > 0)
  currency char(3) NOT NULL DEFAULT 'IDR'
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

webhook_events                            [Payments]
  provider varchar(24) NOT NULL, provider_event_id varchar(120) NOT NULL
  body jsonb NOT NULL                    payload mentah, utuh (Doc 01 §8)
  signature_valid boolean NOT NULL, headers jsonb
  received_at timestamptz NOT NULL DEFAULT now()
  status varchar(16) NOT NULL DEFAULT 'received'   received | processed | ignored | failed
  processed_at timestamptz, error text, intent_id bigint REFERENCES payment_intents(id) ON DELETE SET NULL
  --
  uq webhook_once (provider, provider_event_id)          idempotensi, Dilarang ganda
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
  kind varchar(20) NOT NULL              missing_in_ledger | missing_in_provider | amount_mismatch | duplicate
  ledger_entry_no bigint REFERENCES payment_ledger(entry_no)
  provider_ref varchar(120), expected_amount bigint, actual_amount bigint
  --
  idx recon_items_run (run_id, kind)
```

## 10. Shipping

```
courier_services                          [Shipping]
  courier_code varchar(32) NOT NULL      jne | jnt | tiki | siio | icp | manual
  service_code varchar(32) NOT NULL      YES, JTR, ...
  country char(2) NOT NULL DEFAULT 'ID'
  name varchar(80) NOT NULL
  supports_label boolean NOT NULL DEFAULT false
  supports_tracking boolean NOT NULL DEFAULT true
  is_active boolean NOT NULL DEFAULT true
  -- uq courier_service (courier_code, service_code, country)

shipping_tariff_rules                     [Shipping]      Doc 06 §3.5: tarif per tier paket
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

shipping_rates_cache                      [Shipping]
  cache_key varchar(160) NOT NULL UNIQUE     origin|dest|weight|tier|courier
  amount bigint NOT NULL, provider varchar(24) NOT NULL
  fetched_at timestamptz NOT NULL, expires_at timestamptz NOT NULL
  -- idx rates_expiry (expires_at)

parcels                                   [Shipping]
  shipment_id bigint NOT NULL REFERENCES shipments(id) ON DELETE CASCADE
  label_media_id bigint REFERENCES media(id) ON DELETE SET NULL    PDF label
  waybill_number varchar(80)
  weight_g integer, length_cm smallint, width_cm smallint, height_cm smallint
  declared_value bigint, insurance_amount bigint
  created_at timestamptz NOT NULL DEFAULT now()
  -- uq parcels_shipment (shipment_id)

tracking_lanes                            [Shipping]      D4: 4 jalur, admin bisa kelola
  code varchar(16) NOT NULL UNIQUE        rts | import_po | request | procurement
  name_key varchar(80) NOT NULL
  is_active boolean NOT NULL DEFAULT true

lane_stages                               [Shipping]      tahap per lane, dikelola admin (Doc 06 §3.5)
  lane varchar(16) NOT NULL REFERENCES tracking_lanes(code) ON DELETE RESTRICT
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
  CHECK (lane IN ('rts','import_po','request','procurement'))

tracking_events                           [Shipping]     append-only (S-6) — kurir mentah
  shipment_id bigint NOT NULL REFERENCES shipments(id) ON DELETE CASCADE
  source varchar(24) NOT NULL            biteship | rajaongkir | manual | courier_webhook
  occurred_at timestamptz NOT NULL, status_raw varchar(80), description text
  raw jsonb, fetched_at timestamptz NOT NULL
  -- idx tracking_events_shipment (shipment_id, occurred_at DESC)

shipment_stage_events                     [Shipping]     append-only — tahap ternormalisasi kita (D4)
  shipment_id bigint NOT NULL REFERENCES shipments(id) ON DELETE CASCADE
  stage_id bigint NOT NULL REFERENCES lane_stages(id) ON DELETE RESTRICT
  occurred_at timestamptz NOT NULL, source_event_id bigint REFERENCES tracking_events(id) ON DELETE SET NULL
  actor_id bigint REFERENCES users(id)              diisi bila admin menandai manual
  note varchar(320)
  --
  uq stage_once (shipment_id, stage_id)             satu tahap tidak boleh terjadi dua kali
  idx stage_events_shipment (shipment_id, occurred_at)
```

## 11. Promotion & Drop

```
vouchers                                  [Promotion]
  code varchar(40) NOT NULL UNIQUE, name varchar(120) NOT NULL
  kind varchar(20) NOT NULL              percent | fixed | free_shipping | free_service_fee
  value bigint NOT NULL                  rupiah, atau basis poin untuk percent (1000 = 10,00 %)
  cap_amount bigint                       plafon diskon untuk percent
  min_spend bigint NOT NULL DEFAULT 0
  usage_limit integer, per_user_limit integer NOT NULL DEFAULT 1
  used_count integer NOT NULL DEFAULT 0
  scope_type varchar(20) NOT NULL DEFAULT 'all'   all | category | collection | product | batch
  scope_ids bigint[]                      di-query by app; index GIN
  valid_from timestamptz NOT NULL, valid_to timestamptz NOT NULL
  applies_to_invoice_types varchar(20)[]  mis. voucher hanya untuk deposit
  status varchar(16) NOT NULL DEFAULT 'active'    active | paused | expired
  created_by bigint NOT NULL REFERENCES users(id)
  --
  CHECK (kind IN ('percent','fixed','free_shipping','free_service_fee'))
  CHECK (valid_to > valid_from)
  CHECK (used_count >= 0 AND (usage_limit IS NULL OR used_count <= usage_limit))
  idx vouchers_window (status, valid_from, valid_to)
  idx vouchers_scope (scope_type, GIN(scope_ids))

voucher_redemptions                       [Promotion]
  voucher_id bigint NOT NULL REFERENCES vouchers(id) ON DELETE RESTRICT
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT
  order_id bigint NOT NULL REFERENCES orders(id) ON DELETE RESTRICT
  invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL
  discount_amount bigint NOT NULL CHECK (discount_amount >= 0)
  redeemed_at timestamptz NOT NULL DEFAULT now()
  reversed_at timestamptz
  --
  idx redeem_voucher_user (voucher_id, user_id) WHERE reversed_at IS NULL     enforcement per_user_limit
  idx redeem_order (order_id)

loyalty_entries                           [Promotion]
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  points_delta integer NOT NULL           + earn, - redeem, pembatalan = entri balik
  reason varchar(24) NOT NULL             order_earn | redeem | manual_adjust | expiry | reversal
  order_id bigint REFERENCES orders(id) ON DELETE SET NULL
  expires_at timestamptz, occurred_at timestamptz NOT NULL DEFAULT now()
  actor_id bigint REFERENCES users(id)
  reverses_id bigint REFERENCES loyalty_entries(id)
  --
  CHECK (points_delta <> 0)
  idx loyalty_user_time (user_id, occurred_at DESC)
  idx loyalty_expiry (expires_at) WHERE expires_at IS NOT NULL

loyalty_tiers                             [Promotion]
  code varchar(24) NOT NULL UNIQUE, name_key varchar(64) NOT NULL
  min_points integer NOT NULL, benefits jsonb NOT NULL DEFAULT '{}'
  position smallint NOT NULL
  -- CHECK (min_points >= 0)

drops                                     [Promotion]     D6: rilis terbatas
  code varchar(24) NOT NULL UNIQUE, name varchar(140) NOT NULL
  starts_at timestamptz NOT NULL, ends_at timestamptz NOT NULL
  claim_rule varchar(24) NOT NULL DEFAULT 'one_per_account_per_item'
  status varchar(16) NOT NULL DEFAULT 'scheduled'   scheduled | live | closed
  banner_media_id bigint REFERENCES media(id) ON DELETE SET NULL
  rules_text text, created_by bigint NOT NULL REFERENCES users(id)
  -- CHECK (ends_at > starts_at)
  idx drops_window (status, starts_at, ends_at)

drop_items                                [Promotion]
  drop_id bigint NOT NULL REFERENCES drops(id) ON DELETE CASCADE
  variant_id bigint NOT NULL REFERENCES variants(id) ON DELETE RESTRICT
  quota integer NOT NULL, claimed_count integer NOT NULL DEFAULT 0
  max_per_claim integer NOT NULL DEFAULT 1
  --
  uq drop_item (drop_id, variant_id)
  CHECK (claimed_count >= 0 AND claimed_count <= quota)

drop_claims                               [Promotion]     anti klaim ganda — kunci bisnis D6
  drop_id bigint NOT NULL REFERENCES drops(id) ON DELETE RESTRICT
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  drop_item_id bigint NOT NULL REFERENCES drop_items(id) ON DELETE RESTRICT
  qty integer NOT NULL DEFAULT 1
  claimed_at timestamptz NOT NULL DEFAULT now()
  order_id bigint REFERENCES orders(id) ON DELETE SET NULL
  released_at timestamptz
  --
  uq drop_claim_one_per_account (drop_item_id, user_id)      persis "drop_claims unik (drop_id, account_id)" di D6
```

## 12. Quotes (jalur request order)

```
quote_requests                            [Quotes]        pengajuan buyer (Doc 06 §3.3)
  code varchar(24) NOT NULL UNIQUE
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  product_name_wanted varchar(240) NOT NULL
  brand varchar(120), series varchar(120), character_name varchar(120)
  target_url varchar(500)                 tautan marketplace asal
  desired_qty integer NOT NULL CHECK (desired_qty > 0)
  budget_max bigint, currency char(3) NOT NULL DEFAULT 'IDR'
  reference_media_id bigint REFERENCES media(id) ON DELETE SET NULL
  notes text
  status varchar(24) NOT NULL DEFAULT 'new'
        new | in_review | quoted | accepted | declined | expired | withdrawn
  sla_due_at timestamptz                  SLA respons (Doc 06 §3.3)
  requested_at timestamptz NOT NULL DEFAULT now(), closed_at timestamptz
  assigned_to bigint REFERENCES users(id)
  --
  idx quote_req_status_sla (status, sla_due_at)
  idx quote_req_user (user_id, requested_at DESC)

quotes                                    [Quotes]        penawaran privat admin
  code varchar(24) NOT NULL UNIQUE
  quote_request_id bigint NOT NULL REFERENCES quote_requests(id) ON DELETE RESTRICT
  owner_user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT   visibility privat ditegakkan di Policy
  total bigint NOT NULL CHECK (total >= 0), currency char(3) NOT NULL DEFAULT 'IDR'
  service_fee bigint NOT NULL DEFAULT 0    pertanyaan terbuka Doc 07: apakah sudah termasuk (B-PO)
  eta_min_date date, eta_max_date date
  valid_until timestamptz NOT NULL        masa berlaku penawaran
  status varchar(20) NOT NULL DEFAULT 'draft'  draft | issued | accepted | declined | expired | superseded
  issued_by bigint NOT NULL REFERENCES users(id), issued_at timestamptz
  accepted_at timestamptz, converted_order_id bigint REFERENCES orders(id) ON DELETE SET NULL
  supersedes_quote_id bigint REFERENCES quotes(id)
  buyer_note text, internal_note text
  --
  CHECK (valid_until > COALESCE(issued_at, created_at))
  idx quotes_owner_status (owner_user_id, status)
  idx quotes_expiry (valid_until) WHERE status = 'issued'

quote_items                               [Quotes]
  quote_id bigint NOT NULL REFERENCES quotes(id) ON DELETE CASCADE
  variant_id bigint REFERENCES variants(id) ON DELETE SET NULL   NULL = barang belum ada di katalog
  description varchar(240) NOT NULL
  qty integer NOT NULL CHECK (qty > 0)
  unit_price bigint NOT NULL CHECK (unit_price >= 0)
  line_total bigint GENERATED ALWAYS AS (qty * unit_price) STORED
  -- idx quote_items_quote (quote_id)

quote_visibility_grants                   [Quotes]        siapa dari staff boleh melihat (Doc 01 §3: enforcement privat)
  quote_id bigint NOT NULL REFERENCES quotes(id) ON DELETE CASCADE
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE
  granted_by bigint NOT NULL REFERENCES users(id), granted_at timestamptz NOT NULL DEFAULT now()
  -- PRIMARY KEY (quote_id, user_id)
```

## 13. Procurement (jalur beli atas nama — D5, di balik feature flag)

```
procurements                              [Procurement]
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
  idx procurements_status (status) WHERE status NOT IN ('linked','cancelled')
  idx procurements_order (order_id)

procurement_codes                         [Procurement]   kode klaim, sekali pakai (RS-5)
  procurement_id bigint NOT NULL REFERENCES procurements(id) ON DELETE RESTRICT
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
  uq codes_one_use (procurement_id) WHERE status <> 'voided'    satu kode aktif per procurement
  idx codes_status (status)

proof_files                               [Procurement]
  procurement_id bigint NOT NULL REFERENCES procurements(id) ON DELETE CASCADE
  media_id bigint NOT NULL REFERENCES media(id) ON DELETE RESTRICT
  kind varchar(24) NOT NULL              payment_proof | listing_snapshot | chat_log | receipt
  uploaded_by bigint NOT NULL REFERENCES users(id), uploaded_at timestamptz NOT NULL DEFAULT now()
  verified boolean NOT NULL DEFAULT false, verified_by bigint REFERENCES users(id)
  -- PRIMARY KEY (procurement_id, media_id)
```

## 14. UGC (ulasan — D6)

```
reviews                                   [UGC]
  id, product_id NOT NULL REFERENCES products(id) ON DELETE CASCADE
  variant_id NULL REFERENCES variants(id) ON DELETE SET NULL
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
pages                                     [Content]
  slug varchar(140) NOT NULL             9 halaman publik terverifikasi (Doc 08 §2) + draft
  kind varchar(20) NOT NULL DEFAULT 'policy'    policy | guide | landing | about
  published_version_id bigint REFERENCES content_block_versions(id) ON DELETE SET NULL
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

nav_items                                 [Content]       Doc 02 §3.1: menu dari data, tanpa deploy
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
  code varchar(64) NOT NULL UNIQUE        auth.social_google, procurement.enabled, reviews.enabled,
                                          loyalty.enabled, guest_checkout.enabled, drops.enabled
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
  updated_by bigint NOT NULL REFERENCES users(id)
  --
  CHECK (value_type IN ('string','int','bool','decimal','json'))
  idx settings_group (group_name)

media — lihat §3 (dimiliki Content, lihat S-3)
```

## 16. Notification & Support

```
notification_templates                    [Notification]
  channel varchar(16) NOT NULL           email | whatsapp | in_app | sms
  code varchar(64) NOT NULL              order_placed, deposit_paid, balance_due_h7,
                                         batch_arrived, shipped, quote_issued, otp_login
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
  recipient varchar(200) NOT NULL         email/no-HP terenkripsi at-rest (Doc 01 §8)
  channel varchar(16) NOT NULL, template_code varchar(64) NOT NULL, template_version integer NOT NULL
  event_name varchar(64) NOT NULL, aggregate_id bigint
  status varchar(16) NOT NULL DEFAULT 'queued'   queued | sent | delivered | failed | bounced | suppressed
  provider_message_id varchar(120), failure_reason varchar(320)
  attempts smallint NOT NULL DEFAULT 0
  queued_at timestamptz NOT NULL DEFAULT now(), sent_at timestamptz
  --
  idx notif_status_time (status, queued_at DESC)
  idx notif_user (user_id, queued_at DESC)

tickets                                   [Support]
  code varchar(24) NOT NULL UNIQUE
  user_id bigint NOT NULL REFERENCES users(id) ON DELETE RESTRICT
  order_id bigint REFERENCES orders(id) ON DELETE SET NULL
  invoice_id bigint REFERENCES invoices(id) ON DELETE SET NULL
  kind varchar(24) NOT NULL              question | damage_claim | lost_parcel | double_payment |
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

## 17. Fondasi lintas modul

```
outbox_events                             [semua modul]   B4 — transactional outbox, sejak Sprint 0
  event_name varchar(80) NOT NULL        OrderPlaced, PaymentCaptured, PoBatchArrived, ... (Doc 01 §4)
  aggregate_type varchar(40) NOT NULL, aggregate_id bigint NOT NULL
  payload jsonb NOT NULL                 ID + timestamp saja (B3) — tanpa graph objek
  occurred_at timestamptz NOT NULL DEFAULT now()
  dispatched_at timestamptz, attempts smallint NOT NULL DEFAULT 0
  last_error text, locked_by varchar(64), locked_at timestamptz
  --
  idx outbox_pending (occurred_at) WHERE dispatched_at IS NULL
  idx outbox_agg (aggregate_type, aggregate_id, occurred_at)

audit_logs                                [Audit]         append-only (S-6) — wajib untuk mutasi admin
  actor_id bigint REFERENCES users(id) ON DELETE SET NULL
  action varchar(64) NOT NULL            invoice.void, batch.close, price.update, manual_mark_paid, ...
  target_type varchar(64) NOT NULL, target_id bigint NOT NULL
  before jsonb, after jsonb              diff, bukan salinan penuh
  reason varchar(320) NOT NULL           wajib untuk aksi berisiko (Doc 01 §8 AuditThis)
  ip inet, user_agent varchar(255), occurred_at timestamptz NOT NULL DEFAULT now()
  --
  idx audit_target (target_type, target_id, occurred_at DESC)
  idx audit_actor_time (actor_id, occurred_at DESC)

jobs / failed_jobs / job_batches          [Laravel]       tabel bawaan queue — tidak diubah
sessions, cache — driver redis, tabel tidak dipakai (Doc 01 §10)
```

## 18. View pelaporan (read-only, S-5)

```sql
-- semuanya VIEW biasa atau MATERIALIZED VIEW yang di-refresh terjadwal; tidak pernah ditulis aplikasi.

v_stock_position          (variant_id, location_id, on_hand, reserved, available, min_stock, is_low)
                          = SUM(stock_movements.qty_delta) di-pivot per status reservation
v_order_settlements       (order_id, billed_total, received_total, refunded_total, outstanding,
                          overdue_invoice_count, next_due_at)
                          ringkasan ADR-002 "order_settlements" — sengaja view, bukan tabel (S-5)
v_receivable_aging        (invoice_type, bucket_0_7, bucket_8_14, bucket_15_30, bucket_30_plus, amount_total)
v_batch_progress          (batch_id, offers, slots_taken, deposits_paid, balances_open,
                          received_qty, expected_qty, eta_max_date)
v_sales_daily             (day, gross, discount, shipping_revenue, intl_fee_revenue,
                          service_fee_revenue, orders, aov)
v_product_ratings         (product_id, avg_rating, review_count, with_photo_count)
v_ledger_vs_provider      (day, provider, ledger_total, settlement_total, diff)   -- isi reconciliation_runs
```

Aturan view: nama diawali `v_`, tidak punya primary key, hanya boleh dibaca lewat modul **Reporting**. Query report storefront/admin tidak pernah menyentuh tabel modul lain langsung (B1/B6), jadi nanti saat Reporting diekstrak jadi service (Doc 01 §9) yang ikut hanya view-nya.

## 19. Ringkasan kepemilikan

| Modul | Tabel | Jumlah |
|---|---|---|
| Identity | users, user_addresses, user_consents, otp_challenges, user_sessions, roles, permissions, role_user, permission_role, staff_2fa_secrets | 10 |
| Catalog | categories, collections, products, product_collections, product_options, product_option_values, variants, variant_option_values | 8 |
| Content | media, pages, content_blocks, content_block_versions, nav_items, localizations | 6 |
| PreOrder | po_batches, batch_offers, po_lines, po_payment_plans | 4 |
| Billing | invoices, invoice_allocations | 2 |
| Orders | orders, order_items, order_addresses, shipments, shipment_order_items, order_events, refunds | 7 |
| Inventory | stock_locations, stock_movements, reservations, grns, grn_items | 5 |
| Cart | carts, cart_items | 2 |
| Payments | payment_intents, payment_transactions, payment_ledger, webhook_events, reconciliation_runs, reconciliation_items | 6 |
| Shipping | courier_services, shipping_tariff_rules, shipping_rates_cache, parcels, tracking_lanes, lane_stages, tracking_events, shipment_stage_events | 8 |
| Promotion | vouchers, voucher_redemptions, loyalty_entries, loyalty_tiers, drops, drop_items, drop_claims | 7 |
| Quotes | quote_requests, quotes, quote_items, quote_visibility_grants | 4 |
| Procurement | procurements, procurement_codes, proof_files | 3 |
| UGC | reviews, review_media, review_moderation | 3 |
| Settings | feature_flags, feature_flag_overrides, settings | 3 |
| Notification | notification_templates, user_preferences, notification_logs | 3 |
| Support | tickets, ticket_messages | 2 |
| Audit | audit_logs | 1 |
| Lintas modul | outbox_events | 1 |
| **Total** | | **85** |

Ditambah tabel bawaan framework: `migrations`, `jobs`, `failed_jobs`, `cache`(opsional), `sessions`(opsional).

## 20. Yang sengaja TIDAK ada di skema ini

- **Tabel multitenant / `company_id`.** Satu toko per deployment (Doc 01 §1). Kalau nanti jadi platform multi-klien, itu keputusan arsitektur baru, bukan kolom tambahan.
- **Kolom `status_label` untuk customer.** Label = turunan `money_status` × `fulfillment_status` × lane (Doc 01 §6); menyimpannya = sumber bug.
- **Kredensial gateway/kurir di DB.** Secret hanya env (Doc 01 §8). Yang disimpan hanya `provider`/`method` dan referensi respons.
- **Harga dalam desimal / kurs live.** Nilai tukar masuk sebagai `settings` + snapshot di batch, bukan kolom per-baris yang dihitung ulang.
- **`proof_files` berisi byte.** File di object storage, tabel hanya menunjuk `media_id`.
- **Soft delete di tabel uang.** Invoice/payment_ledger tidak punya `deleted_at`; pembatalan = status `void`/entri reversal (S-6).
- **Data pribadi customer sumber.** Skema ini kosong; isi hanya dari klien Anda (Doc 00 §Batas).
