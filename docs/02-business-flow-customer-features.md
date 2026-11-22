# Dokumen 02 — Flow Bisnis & Fitur (POV Customer)

**Rujukan induk:** `SNOWEGO_MICROSERVICES_BLUEPRINT.md` v1.0 — flow mengikuti Blueprint §5 (ADR-003 K-1); resolusi konflik di Doc 11.

Tanggal: 2026-11-16 · direvisi 2026-11-17 mengikuti Blueprint (butir mengikat: **B-3, B-6, B-7, B-9, B-10, B-11, B-13**; argumennya ada di Doc 11, tidak diulang di sini) · direvisi 2026-11-18 untuk keputusan identitas/auth pemilik (**Doc 11 §4.4 A/C/I**; ADR-012 (RBAC/identitas/dependensi); ADR-007 (BFF/sesi) hasil amandemen) — alur auth hybrid, sesi, dan perangkat ada di §10.8; semantik tiga jalur bisnis tidak berubah.
Basis: model bisnis hibrida RTS + Pre-order + Request Order, satu penjual, bahasa ID, IDR.
Catatan: dokumen ini mendeskripsikan **alur dan fitur generik** kategori toko merchandise impor. Tidak ada teks, gambar, atau halaman milik pihak lain yang direplikasi; copy final adalah konten klien. Fakta hasil observasi (Doc 05/07/08) tetap fakta (ADR-003 K-4); yang berubah hanya keputusan.

---

## 1. Persona

Dua kolom pertama tidak berubah dari versi lama; kolom ketiga dipaksa oleh model RBAC 10 role Blueprint §4 (B-10). Persona customer tidak punya role; persona admin/operator dipetakan eksplisit karena Blueprint mewajibkan permission ditegakkan di server (policy/gate), bukan hanya menyembunyikan menu. Sejak keputusan 2026-11-18 (Doc 11 §4.4 A), semua persona hidup di **satu tabel `users`**: customer adalah akun ber-`is_operator = false`; persona admin wajib `is_operator = true` + 2FA, dan kolom role di bawah hanya berlaku di **guard `admin`** (spatie, granular per permission — Doc 06 §1). Di storefront, operator yang berbelanja tetap customer biasa: otorisasi web = kepemilikan (policy owner) + status akun, dan aktornya `web` (§10.8).

| Persona | Kebutuhan utama | Titik cemas | Role RBAC (Blueprint §4) |
|---|---|---|---|
| **P1 Kolektor** (fans fandom tertentu, beli 1–5 item per batch) | kepastian kapan barang tiba, varian yang benar (anggota/tipe kartu), histori PO | "sudah bayar deposit, barangnya kapan?" | — (customer) |
| **P2 Pembeli impulsif/dropship kecil** | checkout cepat, ongkir jelas, resi aktif | "pesanan saya dibaca atau tidak?" | — (customer) |
| **P3 Pengaju Request** (barang langka/tiada di katalog) | bisa minta penawaran, harga transparan, tidak dilihat orang lain | "permintaan saya dijawab atau hilang?" | — (customer) |
| **A1 Admin merchandiser** | bikin batch PO, atur harga & varian, tutup batch | stok & ETA tidak pasti | `catalog-manager` + `preorder-ops` |
| **A2 Warehouse/packing** | daftar paket per batch, label, input resi massal | order campur RTS/PO | `warehouse-staff` |
| **A3 CS** | status pesanan + timeline + chat, refund | komplain telat baca | `customer-support` (transisi order: `sales-ops`) |
| **A4 Finance** | rekonsiliasi gateway, laporan deposit vs pelunasan, payout refund | selisih settlement | `finance` |
| **A5 Sourcing officer** *(baru, B-10)* | jawab pengajuan dengan quote berversi | quote lama tak sengaja aktif lagi | `sourcing-ops` |
| **A6 Procurement** *(baru, B-10)* | PO supplier, goods receipt, biaya landed | supplier gagal sebagian | `procurement-staff` |
| **A7 Analis** *(baru, B-10)* | laporan tanpa risiko salah klik | — | `analyst` (read-only) |
| **A8 Owner/superadmin** | pengaturan bisnis, role, flag | — | `superadmin` (bundel permission, bukan bypass; bypass hanya lewat flag `is_admin` berjejak audit — Doc 06 §1.6) |

Catatan implementasi: role lama "Ops" (Doc 06 §1 versi 2026-11-16) **dipecah** menjadi `sales-ops` / `preorder-ops` / `sourcing-ops` — ini perubahan nyata, bukan kosmetik, karena antrean kerja Request Order tidak boleh bisa menyentuh transisi order RTS. Sejak 2026-11-18 hak juga bisa diberikan **langsung per user** (`model_has_permissions`), sehingga "view saja untuk satu orang" tidak lagi memaksa role baru (Doc 06 §1.4; ADR-012 (RBAC/identitas/dependensi) D-3).

---

## 2. Tipe order dan empat sumbu status (B-3)

Blueprint §5.4 menetapkan satu order engine dengan `orders.order_type = ready_stock | preorder | request_order`, dan **empat sumbu status yang terpisah**: `order_status`, `payment_status`, `procurement_status`, `fulfillment_status`. Model dua sumbu lama (`money_status` × `fulfillment_status`, Doc 01 §6 versi 2026-11-16) **diganti**.

| Sumbu | Nilai kanonik (= Doc 11 §8.3) | Pemilik semantik | Catatan |
|---|---|---|---|
| `order_status` | `awaiting_payment · paid · processing · shipped · delivered · completed · on_hold · refund_requested · refund_pending · refunded · disputed · expired · cancelled` | Order | satu enum untuk semua `order_type`; mesin §3–§5 adalah **jalur tempuh** per tipe, bukan enum terpisah. `draft` tidak dipakai — order lahir langsung `awaiting_payment` (cart adalah `carts`/`cart_items`). |
| `payment_status` | `unpaid · partially_paid · paid · overdue · refund_pending · refunded · disputed` | Billing & Payment | isinya = `money_status` lama; **namanya** yang mengikuti Blueprint. `disputed` adalah nilai enum, bukan flag eksepsi saja. |
| `procurement_status` | `not_required · sourcing · requisitioned · po_issued · supplier_confirmed · in_transit · customs · received · failed` | Procurement | sumbu baru Blueprint. Enumerasi ini **keputusan kita** yang sudah disahkan (bukan kandidat lagi); `requisitioned` = PO berstatus `draft`/`pending_approval`, bukan tabel baru (Doc 11 §4.3). |
| `fulfillment_status` | `unprocessed · reserved · awaiting_batch · at_warehouse · ready_to_ship · picking · packed · handed_over · in_transit · delivered · returned` | Fulfillment | order menempuh **subset per lane** (urutan resmi di tabel lane di bawah). `awaiting_batch` dan `ready_to_ship` adalah nilai sumbu ini, bukan `order_status`; `customs` **tidak** ada di sumbu ini — faktanya dijaga `procurement_status`. |

Pemilik nilai final adalah **CHECK di Doc 09**; enumerasi lengkap ada di **Doc 11 §8.3**. Dokumen ini **memakai** daftar itu dan tidak menambah nilai baru — nilai di luar daftar adalah bug, bukan varian.

**Yang terjadi pada konsep lane kita (D4 / Doc 01 §6 lama):** lane **dipertahankan**, tapi statusnya berubah dari "sumbu kedua" menjadi **pemilih daftar tahap yang mengisi sumbu `fulfillment_status`**. Lane tidak pernah tampil sendiri ke customer.

| Lane | Sumbu yang diisi | Tahap khas lane (= urutan per lane, Doc 11 §8.3) |
|---|---|---|
| `rts` (lokal siap-kirim) | `fulfillment_status` | `unprocessed → reserved → picking → packed → handed_over → in_transit → delivered (→ returned)` |
| `import_po` | `fulfillment_status` **dan** `procurement_status` | `unprocessed → awaiting_batch → at_warehouse → ready_to_ship → picking → packed → handed_over → in_transit → delivered (→ returned)`; leg luar negeri (forwarder / consolidation / customs / goods receipt) **tidak** ditulis di sini — itu tahap `procurement_status` |
| `request` | `fulfillment_status` (dipetakan dari state machine request) | setelah quote diterima, sama dengan `rts`; sebelum itu statusnya ada di `product_requests.status` (§5) dan `procurement_status` |
| `marketplace_purchase` *(nama baru, B-4)* | `fulfillment_status` | jalur pendek `unprocessed → handed_over → in_transit → delivered` — kode diterbitkan → checkout di platform pihak ketiga → klaim → verifikasi → tracking dilanjutkan dari platform asal |

Penamaan: modul lama kita `Procurement` (jalur beli atas nama buyer di marketplace pihak ketiga) **diganti nama** menjadi `MarketplacePurchase`, karena nama `Procurement` sekarang berarti "beli ke supplier" sesuai Blueprint §2.2/§5.2 (lihat B-4).

**Status yang dilihat customer = turunan (proyeksi read-only) dari keempat sumbu + lane** — bukan sumbu kelima dan bukan kolom yang bisa ditulis bebas (aturan Doc 09 S-5: kolom turunan tidak ditulis; aturan Doc 11 §8.3):

| Kondisi empat sumbu | Label yang dilihat customer |
|---|---|
| `awaiting_payment` + `unpaid` | "Menunggu pembayaran — sisa waktu XX:XX" |
| `paid` + `paid` + `not_required` + `picking` | "Dibayar, sedang disiapkan" |
| `paid` + `partially_paid` + `requisitioned` + `awaiting_batch` | "Deposit diterima, menunggu batch ditutup / barang tiba" |
| `paid` + `partially_paid` + `in_transit`/`customs` (procurement) + `awaiting_batch` | "Barang dalam perjalanan dari supplier / proses impor" |
| `processing` + `paid` + `received` + `ready_to_ship` | "Siap kirim" |
| `shipped` + `paid` + `received` + `shipped` | "Dikirim — resi …" |
| `refund_pending` | "Refund sedang diproses" |
| `expired` + `unpaid` | "Kedaluwarsa — slot/stok sudah dilepas" |
| `paid_stock_exception` (§7.4) | "Pembayaran kami terima, stok sedang kami cek" |

Aturan keras: tidak ada layar customer yang menampilkan hanya satu sumbu. Copy "pesanan kamu sama seperti pesanan biasa" dilarang untuk lane impor/request (aturan lama, tetap berlaku).

---

## 3. Journey 1 — `ready_stock` (RTS)

Mesin status (Blueprint §5.1, diselaraskan ke nilai kanonik Doc 11 §8.3 — `draft` tidak dipakai, order lahir `awaiting_payment`):

```
awaiting_payment → paid → processing → shipped → delivered → completed
kecualian: expired | cancelled | on_hold | refund_requested → refund_pending → refunded | disputed
```

Transisi mundur ditolak oleh state machine (Blueprint §8.3: `paid` tidak pernah kembali ke `awaiting_payment`; `pending` bukan nilai kanonik di sumbu mana pun).

```
Temukan produk (search typo-tolerant / koleksi fandom / filter)
→ PDP: foto, varian bertingkat, stok "ready", ETA kirim 1–2 hari kerja
→ Add to cart → cart drawer: subtotal, estimasi ongkir
→ Checkout: login/guest → alamat (pin / provinsi-kota-kecamatan) → kurir + tarif real → metode bayar (QRIS/VA/e-wallet) → review
   [server membuat checkout_session: snapshot harga, diskon, tax rule, kurs, ongkir, stok/kuota]
→ POST /orders dengan header Idempotency-Key            ← langkah flow, bukan catatan teknis (§6)
→ Reservasi stok atomik + TTL (StockReserved)           ← bila gagal: "stok habis", bukan order kosong
→ Payment intent + instruksi bayar + countdown expiry
   ├── webhook paid (PaymentCaptured) → paid → trigger pick/pack
   ├── tidak bayar sampai TTL → ReservationExpired → stok dilepas → expired
   └── webhook paid datang SETELAH stok dilepas → §7.4 paid_stock_exception
→ processing: pick → pack → label + resi → shipped (email+WA berisi resi)
→ delivered → completed → permintaan review + poin loyalitas
```

Pemetaan dari istilah versi lama: `pending_payment` → `awaiting_payment`; status order `ready_to_ship` → `processing` (istilah `ready_to_ship` sekarang hanya **tahap lane** di `fulfillment_status`); `paid`/`shipped`/`delivered`/`completed` tetap. Konsekuensinya gerbang RS-1 (§11) dirumuskan ulang sebagai **guard pada transisi `paid → processing`**, bukan sebagai kolom status tersendiri.

**Titik keputusan customer-facing yang baru (wajib ada UI, bukan hanya log):**

| # | Titik keputusan | Yang customer lihat | Yang customer bisa pilih |
|---|---|---|---|
| D-1 | Stok habis saat submit | modal "stok habis untuk varian ini" | ubah qty / varian lain / pindah wishlist ("kabari saya") |
| D-2 | `Idempotency-Key` sama + payload beda (`409`) | "keranjang/alamat/ongkir Anda berubah — review ulang" | lanjut dengan key baru setelah re-review (§6 butir 4) |
| D-3 | Bayar tapi stok sudah dilepas | "pembayaran diterima, stok sedang kami cek" | **refund penuh ke metode asal** (baku) / tunggu realokasi / penyelesaian manual CS (§7.4) |
| D-4 | Nominal `intl_fees`/tambahan naik > ambang | rincian + batas atas + checkbox setujui | setujui nominal (re-konsen) atau tolak (RS-4, B-PO-8/B-PO-12) |

Kontrak ke customer (tidak berubah): harga final di review (termasuk ongkir + diskon), resi masuk <24 jam kerja setelah bayar, halaman "Pesanan Saya" selalu bisa membuka status sendiri tanpa menghubungi CS.

---

## 4. Journey 2 — `preorder` (batch / group order)

Mesin status batch (Blueprint §5.2, direproduksi penuh):

```
draft → scheduled → open → closing → closed → purchasing → inbound → received → completed
kecualian: cancelled | failed
```

Aturan yang mengikat flow:
- `closing` **harus atomik** dan **menolak enrollment baru setelah cutoff** — permintaan yang datang di jendela `closing` mendapat `BatchClosed`, bukan antre "akan kami ikutkan".
- Yang di-reservasi customer adalah **kuota batch** (`reserved_qty + qty ≤ quota AND status = 'open' AND opens_at ≤ now() AND closes_at > now()`), **bukan stok fisik** (Blueprint §8.5).
- Checkout batch juga wajib `Idempotency-Key` (Blueprint §7 `POST /orders`) dan guard anti enrollment ganda per (customer, batch item) — dua klik tidak boleh menjadi dua enrollment.
- Status pembayaran dan status order **terpisah** (Blueprint §5.2 butir 3): `payment_status = partially_paid` (DP) dengan `order_status = paid` adalah keadaan sah dan harus bisa dijelaskan ke customer.

```
PDP bertanda "Pre-order" + badge periode (buka s/d X) + ETA estimasi (mis. 6–8 minggu)
→ Add to cart (reservasi kuota batch, TTL mengikuti expiry intent)
→ Checkout: pilih bayar deposit (% atau flat dari batch) / full
→ Deposit captured (PaymentCaptured) → order paid, payment partially_paid, "menunggu batch"
→ Pengingat H-7 / H-1 / jatuh tempo pelunasan (email+WA, tautan bayar langsung)
→ Pelunasan captured → tetap "menunggu batch"
→ Scheduler menutup batch: open → closing → closed, accepted qty dibekukan (BatchClosed)
→ purchasing: kebutuhan beli terkonsolidasi → admin menerbitkan PO supplier
→ inbound → goods receipt (GoodsReceived) → received
→ alokasi biaya aktual (landed cost) + invoice tambahan/pelunasan SESUAI TERMS YANG DISETUJUI (§11 RS-4, B-PO-8)
→ gerbang RS-1 per buyer (3 kondisi AND) → order_status = processing; fulfillment lane import_po maju (at_warehouse → ready_to_ship → picking → packed) → notif "barang tiba"
→ label + resi (handed_over) → shipped / in_transit → delivered → completed
kecualian: cancelled / failed → refund deposit (§4.1)
```

### 4.1 Yang boleh dilakukan customer selama batch hidup
- **Batal enrollment sebelum `closing`** → kuota dilepas atomik (sisi lawan dari UPDATE §8.5), dana mengikuti B-PO-5.
- **Batal setelah `closing`** → tidak bisa; kuota sudah menjadi angka pembelian.
- **Lihat posisi batch**: jumlah peserta, kuota terpakai vs quota, ETA, tahap `procurement_status` lane impor — jawaban langsung atas titik cemas P1 ("barangnya kapan?").
- Batch `cancelled`/`failed`: refund massal satu aksi oleh `finance`; setiap refund adalah operasi idempoten (§8).

---

## 5. Journey 3 — `request_order` (kuotasi privat berversi)

Mesin status (Blueprint §5.3, direproduksi penuh):

```
submitted → reviewing → sourcing → quoted → accepted → ordered → fulfilled
kecualian: rejected | expired | cancelled
```

Aturan quote yang sekarang mengikat (Blueprint §5.3 butir 3–5):
- Quote **berversi**: `quotation_versions` menyimpan setiap revisi dengan harga, kurs snapshot, biaya, DP, lead time, syarat, dan masa berlaku.
- Customer menerima **versi tertentu** (`quote_version`). **Versi lama tidak boleh bisa di-accept setelah ada revisi** → percobaan accept versi basi ditolak server (`409 stale quote version`), dan UI menampilkan "penawaran Anda sudah direvisi, buka versi terbaru".
- **Accept hanya sekali**, ditegakkan server-side: guard `status = 'quoted'` dalam satu UPDATE kondisional + constraint unik pada pencatatan accept. Dua klik cepat / dua device = satu order.
- Accept → order + invoice dibuat **dalam satu transaksi idempoten** (Blueprint §5.3 butir 5, §8.3): key sama + payload sama = hasil sama; tidak ada order ganda kalau permintaan accept di-retry.
- Quote tetap **privat**: hanya pemilik + `sourcing-ops`/`superadmin`; tidak masuk katalog, search, sitemap; URL memakai ULID non-enumerable (aturan lama, tetap).

```
Customer isi form: nama barang, referensi (foto/link), qty, target harga, negara asal, mode RTS/PO netral
→ lampiran diunggah via presigned PUT + finalize (§9)
→ "submitted" → "reviewing" (SLA jawaban 2 hari kerja) → "sourcing"
→ "quoted": penawaran berversi, berlaku s/d, kurs & biaya tersimpan
→ notif "penawaran siap" → customer melihat hanya miliknya
→ accept versi aktif (sekali, server-side) → "accepted" → "ordered" (order + invoice, idempoten)
   ├── "lewati" / kedaluwarsa → "expired", reservasi stok quote dilepas
   └── ditolak staff → "rejected" + alasan
→ payment → procurement → (impor) → fulfillment → "fulfilled"
```

Ekspektasi yang dikelola di UI (tetap): penawaran bukan harga final ongkir (ongkir dihitung saat checkout), dan masa berlaku penawaran adalah harga + stok, bukan hak beli selamanya.

---

## 6. Idempotensi dan snapshot harga sebagai bagian dari flow (B-7, B-13)

Ditulis sebagai langkah yang bisa diamati customer, bukan catatan engineering:

1. **Sebelum** submit, storefront membuat `POST /checkout/sessions`. Server menghitung dan menyimpan snapshot: harga varian, diskon/voucher, `tax_rule_snapshot`, `exchange_rate_snapshot`, `price_snapshot`, ongkir per kandidat kurir, stok/kuota yang terlihat. `checkout_session` berumur pendek dan **tidak dipakai untuk dua order berbeda**.
2. `POST /orders` **wajib** membawa `Idempotency-Key`. Tanpa header → `400`; layar checkout menampilkan "silakan ulangi", bukan order gagal misterius.
3. Key sama + payload sama → **hasil yang sama** (order dan instruksi pembayaran yang sama, response identik). Ini yang membuat tombol "Bayar" yang diklik dua kali atau di-refresh tidak menghasilkan dua tagihan.
4. Key sama + payload **beda** → `409`. Customer melihat "keranjang/alamat/ongkir Anda berubah — review ulang". 409 tidak boleh ditampilkan sebagai error teknis mentah.
5. **Harga, diskon, pajak, ongkir, stok, dan kuota tidak pernah dipercaya dari browser.** Semuanya dihitung ulang server-side dari `checkout_session` yang tervalidasi (Blueprint §7 penutup). Selisih antara angka di layar review dan angka yang ditagih adalah bug penerimaan, bukan edge case.
6. Scope key: `(actor, operation, key)` + hash request + TTL, disimpan di `idempotency_keys`. Operasi yang ikut aturan ini: buat order, buat payment intent, accept quote, klaim pembayaran manual, **refund**, adjustment stok manual.
7. Callback provider: verifikasi signature + merchant ID + nominal + kurs + referensi + keabsahan transisi status, `UNIQUE(provider, provider_event_id)` di `webhook_receipts`, ACK cepat setelah persist, sisanya async (Blueprint §8.3).

---

## 7. Siklus reservasi stok di dalam journey checkout (B-6)

### 7.1 Buat reservasi
Satu `UPDATE` kondisional atomik pada baris agregat `stock_items (warehouse_id, sku_id)`: `reserved += qty` hanya bila `on_hand − reserved − unavailable ≥ qty`, lalu `INSERT stock_reservations` dengan `expires_at = now() + TTL`, order, dan event `StockReserved` (outbox) **dalam transaksi yang sama**. Nol baris = out of stock = rollback. Customer tidak pernah melihat "order dibuat, lalu dibatalkan karena stok".

### 7.2 Multi-SKU dan kegagalan sebagian
Baris dikunci dalam **urutan SKU yang konsisten** (anti-deadlock), retry deadlock dengan backoff pendek dan batas retry di level Command. Bila satu item gagal, **seluruh order gagal** (semua reservasi di-rollback) sampai klien memutuskan kebijakan *partial checkout* eksplisit (B-PO-16).

### 7.3 Worker expiry
- Job periodik membebaskan reservasi yang `expires_at`-nya lewat, **hanya bila** order masih unpaid dan belum ada pembayaran confirmed; baris `order` lalu baris `stock_reservation` dikunci **dalam satu transaksi**.
- Handler webhook pembayaran dan worker expiry memakai **urutan lock yang sama** (order → reservation) plus guard status/CAS. Urutan lock yang identik adalah syarat agar webhook dan expiry tidak saling sandera (Blueprint §8.4 butir 2–3).
- Hasil: reservasi `expired`, stok/kuota kembali, `order_status = expired`, event `ReservationExpired`, notifikasi ke customer dengan opsi "pesan lagi".
- Catatan keras (Blueprint §8.2): lock Redis **bukan** pengganti constraint database; lease-nya bisa kedaluwarsa. Kalau dipakai, itu hanya optimasi hot-key/scheduler.

### 7.4 `paid_stock_exception` — pembayaran sah setelah stok dilepas
Aturan Blueprint §8.4 butir 4: **kita tidak pernah oversell.** Bila webhook `paid` yang sah datang setelah stok dilepas:
1. Coba reservasi ulang secara atomik.
2. Bila gagal → tandai order `paid_stock_exception`; **jangan** paksa transisi `paid → processing`, jangan buat stok negatif.
3. Masuk antrean exception (dasbor operasional) + reconciliation job terhadap provider (Blueprint §8.4 butir 5). Tiga resolusi yang sama-sama sah dan **harus ditawarkan ke customer**: **refund penuh ke metode asal** (baku, tanpa potongan biaya kita), **realokasi** ke stok inbound/gudang lain bila tersedia, atau **penyelesaian manual** oleh `sales-ops` + `finance` dengan alasan tercatat di audit (Blueprint §13).
4. Untuk customer keadaan ini harus terdengar seperti keputusan, bukan kecelakaan: "pembayaran Anda sudah kami terima dan uang tidak akan ditahan; pilih tunggu realokasi atau refund hari ini."

### 7.5 Kuota batch
Mekanika yang sama untuk `preorder_batch_items` (Blueprint §8.5). Penutupan batch mengunci state batch, menolak enrollment baru, dan membekukan jumlah accepted dalam transaksi/protokol lock yang sama supaya tidak ada TOCTOU antara "pembayaran masuk" dan "batch ditutup". Over-quota harus mustahil secara database, bukan dicek di UI.

### 7.6 Beban checkout tinggi
Tidak ada lock global untuk seluruh website: partisi per SKU/batch, rate limiting admission di checkout, pisahkan katalog read-heavy dari checkout write-heavy, batasi connection pool, backpressure. Cache Redis untuk katalog boleh sedikit stale; **cache bukan sumber kebenaran stok final** (Blueprint §8.6).

---

## 8. Journey retur / refund / pembatalan

Tingkat detail mengikuti Blueprint §5.1 (state `refund_pending`, `refunded`, `disputed`), §6 (Billing: `refunds`, `receivables`), §8.3 (idempotensi), §12 (admin), §13 (audit refund), §15 (runbook "refund reconciliation"), §19 (antrean exception refund harus berfungsi sebelum launch). Catatan jujur: Blueprint **tidak** menuliskan mesin status retur tersendiri — spesifikasi di bawah adalah penurunan dari butir-butir itu; bagian yang berupa kebijakan ditandai sebagai keputusan klien (B-PO-10).

**Siapa boleh memulai:** customer dari halaman Pesanan Saya (dalam batas waktu + alasan + foto), `customer-support` atas nama customer, `finance` untuk refund hasil rekonsiliasi, `sales-ops` untuk pembatalan sebelum kirim. Semua mutasi lewat API dengan permission server-side (B-10); tidak ada jalur "langsung ubah kolom".

```
return_requested → under_review → approved → receiving (barang balik) → resolved:
    ├─ resend           (kirim ulang; fulfillment_status kembali ke lane)
    ├─ refund_origin    (refund ke metode asal) → refund_pending → refunded
    ├─ refund_voucher   (voucher; tercatat, tidak lewat gateway)
    └─ rejected         (dengan alasan; customer boleh escalate → disputed)
```

| Aspek | Aturan |
|---|---|
| Money | refund adalah baris `refunds` terhadap `payment` asal; **never over-refund** ditegakkan server (`sum(refund) ≤ captured`). Nominal integer rupiah (S-1, tidak diubah Blueprint). |
| Idempotensi | `POST /refunds` dengan `Idempotency-Key`; retry/double-click = **satu** payout di provider. Dua payout = temuan uang, bukan bug UI. |
| State | transisi refund dijaga state machine; `refunded` tidak bisa kembali ke `paid`. |
| Stok | barang balik layak jual menambah `on_hand` lewat `stock_movements` (adjustment beralasan), bukan edit langsung; rusak → `damaged`, bagian dari `unavailable` (B-5). |
| Audit | refund, price override, quote approval, stock adjustment → audit dengan actor, alasan, before/after (Blueprint §13). |
| Approval ganda | di atas ambang B-PO-10 butuh approver kedua (`finance`/`superadmin`); approver ≠ requester. |
| Customer lihat | "refund sedang diproses" ≠ "belum dibayar"; timeline refund per item untuk refund parsial; nominal + metode + estimasi tanggal sampai. |
| Pembatalan | sebelum bayar = bebas; RTS sudah bayar sebelum kirim = refund dikurangi biaya gateway (B-PO-10 menentukan ambang); PO = mengikuti B-PO-3/B-PO-5; setelah `shipped` hanya lewat jalur retur di atas. |

---

## 9. Upload lampiran Request Order (B-9)

```
Customer pilih file (foto/gambar referensi)
→ POST /product-requests/{id}/attachments/presign   (auth + validasi tipe/ukuran + object key UUID)
→ browser PUT langsung ke MinIO dengan presigned PUT short-lived
→ POST finalize metadata di Laravel (ukuran, mime klaim, hash)
→ async: validasi MIME + magic-byte, scan antivirus bila tersedia, resize, moderasi
→ lampiran aktif hanya setelah validasi lolos
→ pembacaan: signed URL privat berumur pendek (bucket request-attachments privat, Blueprint §11)
```

Aturan yang tampak dari sisi customer:
- Nama file asli **tidak** dipakai sebagai object key; ekstensi tidak dipercaya.
- Ukuran/tipe ditolak dengan alasan yang bisa dibaca ("maks 8 MB", "yang didukung: JPG/PNG/WEBP"), bukan gagal diam-diam.
- Lampiran customer lain **tidak** pernah tampil: privat, hanya pemilik + `sourcing-ops`.
- Upload gagal / MinIO tidak tersedia **tidak boleh** memblokir pengajuan: customer bisa mengirim request tanpa lampiran, dengan UI "lampiran belum terunggah — coba lagi" (Blueprint §16 baris "MinIO unavailable").
- **Caveat wajib (Blueprint §11):** endpoint presigned harus hostname yang benar-benar bisa dijangkau browser, bukan hostname internal Docker; CORS bucket dibatasi ke origin web/admin yang sah. Ini pertanyaan konfigurasi klien + Cloudflare, bukan asumsi dev.

---

## 10. Fitur per area — POV customer

Legenda prioritas: **M1** wajib MVP · **M2** harus ada sebelum launch · **M3** pasca-launch · **M4** opsional/permintaan klien. Kolom **Phase** mengikuti Blueprint §17 dan pemetaannya di Doc 03.

### 10.1 Discovery & katalog
| Fitur | Prio | Phase | Detail & acceptance |
|---|---|---|---|
| Search typo-tolerant + sinonim fandom | M1 | 1 | hasil <300 ms, empty-state menawarkan kategori terdekat |
| Filter facet: kategori, koleksi/IP, tipe barang, harga, mode (RTS/PO), status stok, rating | M1 | 1 | kombinasi filter bisa di-share lewat URL |
| Kategori & koleksi = data, lengkap dengan ikon terupload | M1 | 1 | 8 nilai kategori teramati di shell publik situs sumber (Doc 08 §5 — tetap fakta); slug kategori stabil karena dipakai di URL filter `?category=`; tiap kategori punya ikon + `media.alt` wajib (gerbang aksesibilitas Doc 04 §7) |
| Navigasi utama dari data (admin bisa susun menu tanpa deploy) | M2 | 1 | menu = `nav_items`, cache invalidation otomatis; katalog boleh di-cache CDN, **jangan** cache data customer (Blueprint §12) |
| Halaman koleksi/fandom (banner + grid + deskripsi) | M1 | 1 | dibuat admin dari modul Content |
| Badge mode pada kartu produk ("Pre-order", "Ready", "Limited") + ETA | M1 | 1 | badge terbaca tanpa membuka PDP; label = proyeksi §2 |
| Countdown periode PO pada kartu & PDP | M2 | 2 | **waktu server**, bukan jam browser (Blueprint §10.3: jangan percaya timestamp client) |
| Wishlist (login) / favorit persist | M2 | 3 | guest → dipromosikan login saat pindah akun |
| Riwayat dilihat ("baru dilihat") | M3 | 4 | guest via cookie, 20 item |
| Rekomendasi (sering dibeli bareng / varian lain) | M3 | 4 | aturan sederhana dulu, bukan ML |
| Pagination + infinite di grid; lazy image; LCP <2,5 s 4G | M1 | 1 | audit performa jadi gate hardening (Doc 03 §4 Sprint 5) |

### 10.2 Halaman produk (PDP)
Gallery zoom + video singkat · varian bertingkat (mis. ukuran → anggota → tipe kartu) dengan harga & **`available_to_sell`** per varian (B-5: `on_hand − reserved − unavailable`, bukan `on_hand`) · pilih qty dengan batas batch/kuota per akun · info mode & ETA · blok "cara PO" inline (dari Content, bukan hardcode) · total estimasi (harga × qty) sebelum masuk cart · status "habis" dengan tombol "kabari saya" (M3) · catatan kondisi/impor (mis. "box bisa penyok minor, bukan komplain") · syarat retur per kategori (kartu/photocard = tidak bisa retur kalau segel dibuka) · review & rating (M2) · FAQ per kategori (M3) · share link.

Acceptance kunci: mengganti varian mengubah harga, stok, badge, dan foto sekaligus tanpa reload penuh; harga tidak pernah tampil dua angka berbeda di PDP dan cart; stok yang tampil boleh sedikit stale, **stok final tidak pernah berasal dari cache** (Blueprint §8.6).

### 10.3 Cart & checkout
Cart drawer + halaman cart · pengelompokan baris per `order_type`/batch dengan ETA masing-masing · edit qty dari cart dengan validasi batas batch/stok · hapus & "pindahkan ke wishlist" · input voucher dengan pesan alasan kalau ditolak (expired/min/kategori) · poin loyalitas terpotong (M2) · ongkir multi-kurir dengan perbandingan tarif & estimasi tanggal (M1, driver Manual menampilkan tarif admin) · alamat: saved addresses, pinpoint/map (M2), validasi kodepos-wilayah, nomor HP untuk kurir · metode bayar per driver (QRIS, VA per bank, e-wallet, manual transfer) + batas waktu bayar terlihat countdown · **ringkasan final dihitung server dari `checkout_session`** (§6) · checkbox T&C + checkbox khusus PO/deposit hangus · guest checkout boleh (M2) dengan klaim order lewat email+nomor · review order sebelum bayar · invoice PDF (M2) · retainer: kembali ke cart utuh setelah gagal bayar · tombol submit aman-klik-dobel (`Idempotency-Key` + disable sampai response, dan tetap aman tanpa disable — uji §6 butir 3).

### 10.4 Akun & pesanan saya
Login hybrid email+password / Google / X (OAuth 2.0) untuk login **dan** registrasi, OTP (email/WA) untuk login tanpa password dan reset, reset password — alur lengkap, penggabungan akun, sesi/perangkat, dan batas admin ada di §10.8 (keputusan 2026-11-18; social login sebelumnya ditargetkan M3 opsional, kini jalur resmi) · profil + alamat (utama/alternat, label rumah/kantor) · daftar pesanan dengan filter status (label proyeksi §2) + mode · detail order: timeline visual per event, **empat sumbu ditampilkan apa adanya tapi dengan bahasa manusia** (uang, pengadaan, pengiriman bisa berbeda dan harus dijelaskan), barang per shipment, invoice + bukti bayar, tombol bayar pelunasan, input/no resi + link tracking, tombol batalkan (selama kebijakan memperbolehkan), ajukan retur/refund dengan foto · riwayat Request Order & penawaran (dengan versi) · wishlist · preferensi notifikasi (email/WA/none per event) · unduh data & hapus akun (M3).

### 10.5 Tracking & komunikasi
Timeline per shipment dengan event kurir (label dibuat, in transit, out for delivery, delivered, exception) · fallback kalau API tracking mati: status manual admin + catatan "info terakhir …" · kanal: email wajib, WA untuk status uang & pengiriman, deep-link ke halaman order.

**Trigger notifikasi per lane transaksi, dengan nama register event (Daftar kanonik: Doc 12 §6.2; aturan penamaan: Doc 11 §8.4)** — event adalah pemicu, notifikasi adalah konsumen via outbox; handler harus idempoten karena delivery minimal satu kali:

| Event kanonik (Blueprint) | Dipublikasikan oleh | Lane relevan | Notifikasi ke customer |
|---|---|---|---|
| `OrderPlaced` | Order | semua | "pesanan dibuat, menunggu pembayaran" (email, in-app) |
| `StockReserved` | Inventory | `rts` | senyap — hanya countdown "stok dijaga sampai XX:XX" |
| `ReservationExpired` | Inventory | `rts`, `request` | "stok/slot dilepas karena belum dibayar — pesan lagi" |
| `PaymentCaptured` | Billing & Payment | semua | "pembayaran diterima" (email+WA+in-app) |
| `OrderCancelled` | Order | semua | "pesanan dibatalkan" + alasan |
| `BatchClosed` | Preorder | `import_po` | "batch ditutup, kuota Anda terkunci" |
| `GoodsReceived` | Procurement | `import_po`, `request` | "barang tiba di gudang" (per buyer, RS-2) |
| `QuoteAccepted` | Sourcing | `request` | "penawaran vN diterima — invoice terbit" |
| `ShipmentDelivered` | Fulfillment | semua | "sampai — silakan beri ulasan" |
| `RefundCompleted` | Billing & Payment | semua | "refund selesai ke metode asal" |
| `ProductUpdated` | Catalog | `rts` | senyap (invalidasi cache/ISR) |
| `NotificationDelivered` | Notification | semua | — (observability/konfirmasi internal) |
| `PackageShipped` | Fulfillment | semua | "dikirim + resi" |
| `TrackingUpdated` | Fulfillment | semua | exception kurir >3 hari |
| `BalanceInvoiced` | Billing | `import_po`, `request` | reminder H-7/H-1/jatuh tempo via scheduler tagihan; `on_hold` |
| `QuoteIssued` | Sourcing | `request` | "penawaran siap" |
| `QuoteExpired` | Sourcing | `request` | "penawaran kedaluwarsa" |
| `PaidStockExceptionRaised` | Order/Inventory | `rts` | §7.4 (email+WA+in-app) |

Daftar kanonik nama event adalah **Doc 12 §6.2** (pemilik register; aturan di Doc 11 §8.4) — dokumen ini mengutip namanya apa adanya, tidak menamai ulang dan tidak menambah "nama lokal". Catatan konvensi: `QuoteIssued`/`QuoteAccepted`/`QuoteExpired` adalah **event**, sedangkan `QuotationExpired` (HTTP 410) adalah **exception** — dua objek berbeda, satu akar kata. `PaidStockExceptionRaised` resmi sebagai event (yang bernama `PaidStockException` di Doc 12 §4.3 tetap domain exception untuk HTTP). Satu-satunya pemicu notifikasi yang belum ada di register adalah "stok wishlist kembali" (M3): usulan namanya `StockAvailable`, masih menunggu pengesahan pemilik register Doc 12 §6.2. Aturan anti-spam ikut mengikat: satu notifikasi per kejadian per kanal (dedup pada `(event_id, channel, recipient)`), dan retry worker **tidak boleh** menghasilkan WA dobel (Blueprint §16 baris "worker crash after side effect").

**Matriks notifikasi** (kolom "owner copy" = klien) — tetap dari versi lama, hanya trigger-nya diselaraskan:

| Event | Email | WA | In-app | Trigger |
|---|---|---|---|---|
| Order dibuat (belum bayar) | ✔ | – | ✔ | `OrderPlaced` |
| Deposit/lunas diterima | ✔ | ✔ | ✔ | `PaymentCaptured` |
| Tagihan pelunasan | ✔ | ✔ | ✔ | `BalanceInvoiced` + scheduler pengingat H-7/H-1/jatuh tempo |
| Batch ditutup | ✔ | – | ✔ | `BatchClosed` |
| Barang tiba, siap kirim | ✔ | ✔ | ✔ | `GoodsReceived` (+ gerbang RS-1 per buyer) |
| Dikirim + resi | ✔ | ✔ | ✔ | `PackageShipped` |
| Exception kurir >3 hari | ✔ | ✔ | – | `TrackingUpdated` |
| Refund diproses / selesai | ✔ | ✔ | ✔ | `ReturnDecided` → `RefundCompleted` |
| Penawaran diterbitkan | ✔ | ✔ | ✔ | `QuoteIssued` |
| Penawaran kedaluwarsa | ✔ | – | ✔ | `QuoteExpired` |
| Stok/slot dilepas (kedaluwarsa) | ✔ | – | ✔ | `ReservationExpired` |
| Bayar setelah stok dilepas | ✔ | ✔ | ✔ | `PaidStockExceptionRaised` |
| Stok wishlist kembali | ✔ | – | ✔ | `StockAvailable` (M3; usulan — belum ada di register Doc 12 §6.2) |

### 10.6 Trust & konten (sering diremehkan, dampaknya konversi)
Halaman "Cara Belanja / Cara PO" (M1, dari Content) · halaman ongkir & estimasi waktu per kurir (M1) · kebijakan deposit & hangusnya (M1, ditulis + muncul saat checkout) · FAQ (M2) · profil toko & kontak/WA bisnis (M1) · review bertanda "pembeli terverifikasi" (M2) · Galeri/batch sebelumnya (M3) · blog/kabar rilis (M4) · badge keamanan pembayaran (dari gateway, bukan klaim sendiri).

### 10.7 Aksesibilitas & locale
Kontras WCAG AA, focus-visible, label form, alt untuk gambar produk, target sentuh ≥44 px, navigasi keyboard di cart/checkout, `prefers-reduced-motion` untuk countdown & animasi · format IDR `Rp1.250.000` tanpa desimal, tanggal & zona Asia/Jakarta, nomor HP format `+62` · semua string UI lewat resource file sehingga tema/brand lain bisa menyesuaikan nada bahasa tanpa ubah komponen.

### 10.8 Auth hybrid, akun, sesi, dan perangkat (keputusan pemilik 2026-11-18)

Register mengikat: Doc 11 §4.4 A/C/I; alasan di ADR-012 (RBAC/identitas/dependensi); mekanisme cookie di ADR-007 (BFF/sesi) amandemen 2026-11-18. Bagian ini **keputusan**, bukan hasil observasi (fakta observasi auth ada di Doc 05 §6). Semantik tiga jalur bisnis (§3–§5) tidak berubah karena keputusan ini; yang berubah hanya cara masuk akun dan otorisasi web.

**Model identitas.** Satu tabel `users` untuk storefront dan admin. Kolom populasi `kind` yang lama dihapus, diganti `is_operator` / `is_admin` (Doc 06 §1.1). Customer = akun `active` apa pun; `is_operator`/`is_admin` **tidak memberi hak apa pun di storefront** — otorisasi web adalah kepemilikan (policy owner: order, invoice, request, alamat, favorit hanya miliknya) + status akun. Storefront **tidak memakai spatie**: tidak ada role/permission customer. Operator yang berbelanja adalah aktor `web`; header `X-Actor-Type` (`web | admin | system`) diisi server dari guard/sesi, tidak pernah dipercaya dari klien.

**Registrasi hybrid (tiga jalur, semuanya resmi):**

1. **Email+password:** buat akun → kirim tautan/OTP verifikasi email → `email_verified_at` diisi setelah verifikasi kita sendiri sukses → consent T&C dicatat (`user_consents`, Doc 09 §2). Password di-hash, tidak pernah disimpan mentah.
2. **Google** (`laravel/socialite` driver bawaan) dan **X** (`socialiteproviders/twitter`, **OAuth 2.0** — driver `twitter` bawaan Socialite memakai OAuth 1.0a yang sudah ditinggalkan X, tidak dipasang). Callback provider membawa `provider_user_id`, email, dan klaim `email_verified`. Identitas sosial disimpan di `user_identities (provider, provider_user_id, user_id)`, unik per provider (Doc 09 §2).
3. **Pencocokan email saat callback sosial** — di sinilah risiko takeover hidup, jadi aturannya keras:
   - `user_identities` sudah ada → login langsung (bila akun `active`).
   - Belum ada, tapi email cocok dengan akun existing: **jangan langsung tautkan.** Penggabungan akun hanya lewat **email terverifikasi**: bila email akun kita sudah terverifikasi (`email_verified_at` terisi) **dan** provider mengklaim verified, identitas baru boleh ditautkan ke akun itu. Bila salah satu syarat tidak terpenuhi, tolak penggabungan otomatis dan arahkan user masuk dengan email+password lalu selesaikan verifikasi — bukan menggabungkan bermodal klaim mentah provider.
   - Belum ada akun sama sekali → buat `users` baru + `user_identities`; `password_hash` null (user bisa set password lewat alur reset). **`email_verified_at` hanya diisi bila provider mengklaim verified DAN alur verifikasi kita sendiri (tautan/OTP ke email itu) sukses.** Klaim provider saja tidak cukup.
   - Alasan jujur: siapa pun bisa mendaftar di provider memakai email korban; kalau klaim email mentah dipercaya, akun korban bisa diambil alih. Konsekuensi UX yang diterima: registrasi sosial **tidak instan-verified** — user tetap harus menyelesaikan verifikasi email kita, dan sampai itu terjadi akun belum bisa dipakai untuk aksi yang mensyaratkan email terverifikasi.

**Login hybrid:** email+password, Google, X — ketiganya untuk login maupun registrasi (jalur yang sama, bukan dua sistem). OTP tanpa password lewat `otp_challenges` (channel email/whatsapp/sms; purpose `login | reset_password | verify_phone`; kode di-hash, ada `attempts` + `expires_at` + `consumed_at` — satu kali pakai, Doc 09 §2). Akun `suspended`/`deletion_requested`/`deleted` ditolak dengan pesan yang bisa dibaca, bukan redirect diam-diam. Rate limit login/OTP/reset wajib di edge **dan** `RateLimiter` API (ADR-007 (BFF/sesi) D-5a). Toggle per-provider sosial diatur dari admin (observasi Doc 05 §6) dan **hanya berlaku di storefront** — tidak ada jalur sosial ke admin dan tidak akan pernah ada.

**Reset password:** lupa password → OTP/tautan ke email terverifikasi → set password baru → **cabut semua sesi lain** (`user_sessions.revoked_at`) kecuali sesi yang sedang mereset; notifikasi "password berubah" dikirim. Reset atas nama user oleh CS mengirim alur reset ke email user, tidak pernah menampilkan password baru (Doc 06 §3.10).

**Sesi & perangkat:** transport = **Sanctum session cookie** (`statefulApi`), same-site karena edge satu host dengan pemisah path. Cookie storefront `elzara_web_session` (guard `web`), `Secure; HttpOnly; SameSite=Lax; Path=/` — `Path=/` wajib supaya cookie ikut terkirim ke `/api/`; pemisahan dari admin lewat nama cookie + guard, bukan path. CSRF wajib pada setiap mutasi; cookie `XSRF-TOKEN` sengaja dibaca JavaScript (header `X-XSRF-TOKEN`), endpoint `sanctum/csrf-cookie` dipanggil sebelum mutasi pertama. `personal_access_tokens` **tidak dibuat**; tidak ada bearer token di browser. Setiap login menulis baris `user_sessions` (`session_hash`, ip, `user_agent`, `device_label`, `last_seen_at`, `revoked_at` — Doc 09 §2). Layar "keamanan perangkat" di profil (observasi Doc 05 §5.7) = daftar sesi aktif + keluar per perangkat + **keluar semua perangkat** (revoke semua baris sesi user itu). Logout = revoke sesi server + bersihkan cookie; mencabut sesi dari admin (Doc 06 §3.10) menulis `revoked_at` yang sama, jadi satu mekanisme untuk dua sisi.

**Batas admin (tidak bisa ditawar):** sesi web tidak pernah diterima guard `admin`. Customer biasa (`is_operator = false`) tidak punya jalur ke `/admin` sama sekali. Akun operator **tidak otomatis masuk admin dari storefront**: masuk admin wajib login terpisah di guard `admin` dengan email+password + 2FA wajib (`staff_2fa_secrets`, 10 recovery code ter-hash — Doc 06 §1.1). Cookie admin `elzara_admin_session` terpisah; XSS di storefront tidak menjadi akses admin. Bypass `is_admin` hanya berlaku di guard admin dan wajib berjejak `audit_logs` (`rbac.bypass`) — tidak pernah di web (Doc 06 §1.6).

**Yang tetap tidak berubah:** guest checkout (M2) dengan klaim order lewat email+nomor (§10.3); checkout/login sebagai prasyarat keranjang, favorit, dan akun; privasi request/quote (§5); hak hapus akun dengan legal-hold transaksi (§10.4). Persetujuan kontrol sesi dikonfirmasi pada 2026-11-20. Lokasi artefak bertanda tangan pemilik proyek dan klien masih terbuka; Q-2 sudah dijawab pemilik proyek pada 2026-11-22: klien tidak punya IdP/SSO sendiri, sesi Sanctum dua guard tetap dan tidak ada provider OIDC (ADR-007 (BFF/sesi) D-6a).

---

## 11. Delta bisnis pasca-observasi yang tetap berlaku

Fakta Doc 05/07/08 tidak diubah (ADR-003 K-4). Yang berubah hanya nama dan penempatannya.

**Model tagihan** (ADR-002, tidak dibatalkan Blueprint — Blueprint §6 justru punya `invoices`, `invoice_lines`, `receivables`):

| Tipe tagihan | Kapan nominal pasti | Contoh pemicu | Padanan Blueprint |
|---|---|---|---|
| `deposit` | saat order dibuat (dari aturan batch) | PO dibuka | §5.2 butir 3 |
| `balance` | saat order dibuat | harga barang dikurangi deposit | §5.2 butir 3 |
| `intl_fees` | **setelah barang bergerak/goods receipt** | pajak impor + biaya pos internasional; estimasi + batas atas saat PO, nyata saat tiba | `customs_charges`, `landed_cost_allocations` (§6 Logistics) |
| `domestic_ship` | saat order/pengiriman | ongkir lokal per shipment | §6 Logistics |
| `extra_ship` | insidental | kirim ulang setelah retur/alamat salah | §8 retur (turunan kita); Blueprint §13 hanya mewajibkan audit refund, tidak menspesifikasi kirim ulang |
| `adjustment` | manual admin beralasan | koreksi lebih bayar, goodwill | `order_adjustments` (§6 Sales) |

Dampak journey: halaman "tagihan saya" tetap pusat aksi, dan copy reminder harus menyebut tipe tagihan.

**Aturan operasional terkonfirmasi (Doc 07), tetap:**
- **RS-1 Gerbang "siap kirim" (F4/F6).** Sub-order pindah ke tahap siap-kirim hanya bila tiga hal benar sekaligus: barang tersedia (goods receipt untuk impor / stok untuk lokal), **seluruh invoice wajib** bertipe `balance`/`intl_fees`/`domestic_ship` lunas, dan packing selesai. Dalam istilah Blueprint: **guard pada transisi `paid → processing`**, bukan kolom status tunggal — wajib punya test sendiri.
- **RS-2 Batch maju per buyer (F5).** Buyer yang belum melunasi tidak menahan buyer lain dalam batch yang sama; notifikasi "barang tiba" dan tombol bayar pelunasan dikirim per akun; pelepasan batch parsial.
- **RS-3 Tagihan impor hanya untuk lane impor (F3).** Ready stock tidak boleh memunculkan invoice TAX/EMS; gabungan TAX/EMS boleh jadi satu invoice dengan dua sub-rincian agar bisa dijelaskan.
- **RS-4 Harga punya masa berlaku (F9).** Harga dikunci saat order dibuat; untuk invoice yang terbit belakangan (`intl_fees`) dan untuk penawaran request, tampilkan estimasi + batas atas + tombol "saya setujui nominal ini" sebelum bayar; larang pembayaran otomatis atas nominal yang naik melewati ambang (default ±5 % → re-konsen). **Diperkuat Blueprint §5.2 butir 7** ("additional invoice/pelunasan **berdasarkan persetujuan terms**") → konsekuensinya di B-PO-8 (§15).
- **RS-5 Kode klaim = satu paket, satu kali pakai (F7/F8).** Aturannya tidak berubah; hanya nama modulnya (B-4).
- **RS-6 Laporan pembayaran ganda (F11).** Form "sudah bayar tapi belum lunas / terpotong dua kali" masuk antrean Finance; jangan pernah menampilkan tombol "bayar lagi" tanpa peringatan bahwa sistem sedang menunggu konfirmasi gateway. Sekarang juga punya alasan teknis: replay webhook dijaga `webhook_receipts` + `UNIQUE(provider, provider_event_id)` (B-7).

**Drop & klaim.** Barang tertentu dijual sebagai drop dengan aturan satu klaim per akun per item. Blueprint §10.3 sekarang **memaksa kita memilih kebijakan eksplisit** (first-successful-reservation / lotre / FIFO dengan layanan alokasi khusus yang durable dan atomik) dan **melarang** menganggap timestamp browser, Redis list sederhana, urutan kedatangan HTTP, atau Waiting Room sebagai bukti fair. Konsekuensi UI: menjelaskan nasib klaim setelah kedaluwarsa, dan tidak pernah menjanjikan "dapat antrean lebih duluan".

**Ulasan.** Hak menulis ulasan muncul setelah pesanan `completed`, ulasan tampil hanya setelah moderasi admin. Konsisten dengan aturan upload §9 (lampiran user juga dimoderasi).

**i18n.** Bahasa tampilan di profil (ID/EN), semua string lewat resource — tidak berubah.

---

## 12. Alur pengecualian (wajib ada UI + statusnya)

| Situasi | Sistem | Customer lihat |
|---|---|---|
| Bayar tapi webhook hilang | rekonsiliasi + tombol "saya sudah bayar" → klaim manual CS; dedup `webhook_receipts` | status "menunggu verifikasi pembayaran", bukan "belum bayar" |
| Bayar 2× / double capture | ledger deteksi → auto-refund kelebihan **sebagai satu operasi idempoten** | notif refund + catatan |
| `Idempotency-Key` sama, payload beda | `409`, tidak ada order kedua | "keranjang/ongkir Anda berubah — review ulang" (§6 butir 4) |
| Stok berubah saat checkout (RTS laris) | reservasi atomik gagal → order tidak dibuat; snapshot + guard `InsufficientStock` | modal "stok habis, ubah qty / pindah wishlist" |
| **Bayar setelah stok dilepas** | `paid_stock_exception`; coba reservasi ulang; **tidak pernah oversell** | "pembayaran diterima, stok sedang kami cek" + pilihan refund / realokasi / manual (§7.4) |
| Enrollment datang saat `closing` | ditolak atomik, kuota tidak naik | "batch baru saja ditutup" + batch berikutnya |
| Percobaan over-quota | UPDATE kondisional kuota menolak | "kuota batch penuh" (tidak pernah "menunggu konfirmasi") |
| Batch molor ETA | ETA batch digeser admin + notif massal per batch | ETA baru + alasan singkat |
| Batch batal (supplier gagal) | `cancelled` / `failed`, refund massal 1 aksi | pilihan refund asal / voucher insentif (B-PO-4) |
| Accept quote versi lama | `409 stale quote version` | "penawaran direvisi, buka versi terbaru" (§5) |
| Accept quote dua kali cepat | guard state + constraint unik → satu order | satu invoice, satu tombol bayar |
| Sebagian item tiba (partial GRN) | shipment pecah per item tiba | "3 dari 5 item tiba, sisanya menyusul" |
| Pelunasan tidak dibayar | `on_hold` → reminder → `expired` sesuai B-PO-2b/3 | status jelas + apa yang terjadi dengan deposit |
| Paket returned ke toko (alamat salah) | status `returned_to_sender`, biaya kirim ulang | tombol "perbaiki alamat & bayar kirim ulang" |
| Retur diterima sebagian | refund per item | timeline refund per item |
| Request Order ditolak admin | keputusan + alasan, reservasi quote dilepas | "tidak bisa kami penuhi karena …" |
| Kurir tanpa API/berita jelek | driver Manual sebagai fallback kelas satu | status manual + last update + catatan |
| MinIO / lampiran gagal | upload gagal aman, request tetap bisa dibuat tanpa lampiran | "lampiran belum terunggah — coba lagi" (§9) |
| Sedang di Waiting Room | admission control di edge, bukan di database | halaman antre + posisi/estimasi bila tersedia; checkout yang sudah admitted diprioritaskan (B-8) |
| Checkout dimatikan darurat | flag "nonaktifkan checkout" (Blueprint §15) | "tutup sementara untuk pembayaran baru", cart tetap utuh |

---

## 13. Backoffice minimum agar janji customer bisa ditepati

Daftar fungsi dipertahankan; kolom kanan adalah rekonsiliasi dengan RBAC 10 role Blueprint §4 (B-10) — bukan lagi "admin" satu-satu. Sejak keputusan 2026-11-18, permission granular `<modul>.<aksi>` lewat spatie (guard `admin`) dan bisa diberikan langsung per user di luar bundel role (Doc 06 §1.3–§1.4; Doc 11 §4.4 B). Semua permission ditegakkan di server (policy/gate Laravel), bukan hanya menyembunyikan menu Next.js.

| Area backoffice | Owner role | Catatan |
|---|---|---|
| Katalog & varian (bulk import CSV, harga per varian, foto) | `catalog-manager` | Blueprint §4 Catalog; `price_lists` untuk harga berbasis waktu |
| Batch PO: buat, ETA, deposit rules, tutup, tiba/GRN, batalkan, report posisi (deposit masuk vs pelunasan kurang vs hangus) | `preorder-ops` | penutupan lewat `POST /admin/preorder-batches/{id}/close` idempoten (Blueprint §7) |
| Antrian pengajuan, terbitkan quote **berversi**, tolak dengan alasan, approve | `sourcing-ops` | optimistic `version` → 409 pada tulis basi (Blueprint §8.2) |
| Procurement supplier: master, purchase requisition, PO, supplier invoice, **goods receipt** | `procurement-staff` | `GoodsReceived` memicu terbitnya invoice `intl_fees` |
| Manajemen order (filter, detail, transisi dengan reason, cancel, refund parsial) | `sales-ops` | transisi hanya lewat state machine |
| Warehouse: pick list per batch/RTS, label, input resi massal + scan, adjustment & stocktake | `warehouse-staff` | adjustment beralasan, tidak pernah edit stok langsung |
| Rekonsiliasi pembayaran + antrean exception (`paid_stock_exception`, double capture, selisih settlement) | `finance` | Blueprint §8.4 butir 5, §19 |
| Retur, tiket CS, dispute | `customer-support` | Blueprint §4 CRM & Support |
| Voucher/kampanye + poin; konten (blok homepage, banner, halaman, menu) | `catalog-manager` / `superadmin` | Blueprint §4 Marketing & CMS |
| Laporan: penjualan, margin per varian, profitabilitas batch, umur piutang, stok menipis, export CSV | `analyst` (read-only) | Blueprint §4 Reports |
| Sistem: staff, role/permission, settings, webhook, audit log, jobs/DLQ, feature flags | `superadmin` | Blueprint §4 System |

Tetap dari versi lama: tarif manual per kurir/zona kalau tanpa API, 2FA admin, audit trail, export CSV.

---

## 14. Metrik & definisi sukses

| Metrik | Baseline target | Sumber aturan |
|---|---|---|
| Oversell pada stok/kuota terkendali | **0** (nol, bukan "rendah") | Blueprint §1, §8, §16 |
| `paid_stock_exception` diselesaikan < 1 hari kerja | ≥95 % | §7.4 |
| Duplicate order karena retry/idempotensi | 0 | §6 |
| Conversion (visit→order paid) | ≥1,5 % (RTS) / ≥0,8 % blended | lokal |
| Cart→paid (RTS) | ≥45 % | lokal |
| Deposit→pelunasan tepat waktu | ≥85 % | lokal |
| Rata-rata hari telat pelunasan | ≤2 hari | lokal |
| Waktu buat label sejak dibayar | RTS <24 jam kerja; PO <48 jam sejak tiba | lokal |
| Penawaran dijawab dalam SLA | ≥95 % ≤2 hari kerja | lokal |
| Tiket "di mana pesanan saya?" | <8 % order | lokal |
| Refund rate | <2 % | lokal |
| LCP mobile / error rate checkout | <2,5 s / <0,5 % | lokal + Doc 04 §7 |

---

## 15. Keputusan yang menunggu jawaban klien (B-PO, dicek ulang terhadap Blueprint)

| ID | Butir | Kata Blueprint | Status |
|---|---|---|---|
| B-PO-1 | Besar deposit (% default 30 / flat) | §5.2 butir 1 menyebut "deposit policy" per batch, tidak menentukan angka | **tetap milik klien** |
| B-PO-2 | Jatuh tempo pelunasan 7 hari setelah tiba | tidak dibahas | **tetap** |
| B-PO-2b | Toleransi `on_hold` 3 hari / `expired` 7 hari | tidak dibahas | **tetap** |
| B-PO-3 | Deposit hangus bila telat/expired | §5.2 butir 8 hanya mengatur refund bila **gagal sourcing** | **tetap**, tapi wajib ditulis di T&C + muncul 2× saat checkout |
| B-PO-4 | Deposit refundable bila batch batal (metode asal / voucher 110 %) | §5.2 butir 8 + §6 `refunds` mengonfirmasi jalur refund; voucher insentif tidak disebut | **sebagian dijawab**: refund ke metode asal jadi pilihan baku |
| B-PO-5 | Customer boleh batalkan PO sebelum batch ditutup? | tidak diputuskan; tapi §8.5 memaksa pelepasan kuota jadi operasi atomik nyata | **tetap**, + kewajiban teknis (kuota harus bisa dilepas) |
| B-PO-5b *(baru)* | Setelah `closing`, boleh batal? | `closing` membekukan accepted qty | harus diputuskan bersama B-PO-5 |
| B-PO-6 | Min order per batch, max qty per akun (anti-tengkul, default 6/varian) | §10.3 mendukung per-account limits + abuse detection | **didukung**, angka tetap klien |
| B-PO-7 | Gabung ongkir RTS+PO → kirim terpisah per lane, ongkir per shipment | §5.4: order multi-item dapat membutuhkan **split shipments** | **dikonfirmasi Blueprint** (dulu asumsi kita, sekarang spesifikasi) |
| B-PO-8 | Biaya internasional boleh ditagih tanpa konfirmasi? (default lama: ya, dalam batas atas) | §5.2 butir 7: invoice tambahan **berdasarkan persetujuan terms** | **BERUBAH** → default jadi "ya, hanya bila terms disetujui di muka + batas atas + re-konsen saat nominal lewat ambang" |
| B-PO-9 | Jalur procurement atas-nama dipakai di toko klien? | tidak dikenal sebagai `order_type`; tetap sah sebagai lane `marketplace_purchase` | **tetap**, dengan nama baru (B-4) |
| B-PO-10 | Ambang refund yang butuh persetujuan kedua (+ biaya gateway yang boleh dipotong) | §13 mewajibkan audit refund, tidak menetapkan ambang | **tetap**, ambang milik klien |
| B-PO-11 | Drop: first-come atau lotre | §10.3 **mewajibkan salah satu dipilih dan ditegakkan server-side**, melarang Waiting Room/timestamp client sebagai bukti fair | **kerangkanya dijawab, pilihannya tetap klien**; FIFO ketat = layanan alokasi tersendiri (dampak jadwal) |
| B-PO-12 | Ambang kenaikan nominal `intl_fees` yang memaksa re-konsen | §5.2 butir 7 + §6 (kurs/`tax_rule_snapshot`) mendukung mekanisme, bukan angka | **tetap**, didorong oleh berubahnya B-PO-8 |
| B-PO-13 | Dasar hitung TAX/EMS (riil per berat/pajak atau flat per batch) | §6 memodelkan `customs_charges` + `landed_cost_allocations`; §17 menaruh landed cost di **Phase 4** | **BERUBAH sebagian**: arah resmi = biaya aktual + alokasi per unit; MVP boleh flat **dengan syarat** tetap bisa dialokasikan (Doc 03 §7) |
| B-PO-14 | Apakah jalur RS-5 dipakai di toko klien (masuk MVP atau tidak)? | tidak diputuskan Blueprint | **tetap**, dan sekarang jadi salah satu tuas pemotong jadwal (Doc 03 §2 opsi J-c) |
| B-PO-15 *(baru)* | TTL reservasi stok & masa berlaku `checkout_session` | §8.4 butir 1: TTL reservasi dan expiry intent **harus diselaraskan** | perlu angka klien (default teknis sementara: 2 jam) |
| B-PO-16 *(baru)* | Kebijakan sebagian: satu SKU habis di cart multi-SKU | §8.1 mengizinkan "explicit partial checkout policy" sebagai opsi | perlu keputusan: semua-or-nothing (default) atau parsial |
| B-PO-17 *(baru)* | Tenggang versi quote lama setelah revisi | §5.3 hanya melarang accept versi lama | default: langsung tidak berlaku, tanpa tenggang |
| B-PO-18 *(baru)* | Resolusi baku `paid_stock_exception` (refund otomatis / realokasi / manual) + SLA-nya | §8.4 butir 4 mewajibkan salah satu dan melarang oversell | perlu kebijakan klien |

Tiga yang paling menentukan jadwal: **B-PO-13**, **B-PO-14**, **B-PO-11**. Yang berubah arah karena Blueprint: **B-PO-8** dan **B-PO-13** (plus B-PO-4 yang kini punya jalur baku).

Daftar pertanyaan teknis-jadwal (Cloudflare Waiting Room, gateway settlement, multi-warehouse, ledger keuangan, kapasitas teruji, pilihan jadwal) tidak diulang di sini — itu **Doc 11 §6 Q-1…Q-7** dan jalur paralel klien di Doc 03 §9. Khusus "model auth": mekanismenya sudah diputus 2026-11-18 (cookie sesi Sanctum + hybrid sosial, §10.8); Q-2 sudah dijawab pemilik proyek pada 2026-11-22: klien tidak punya IdP/SSO sendiri; sesi Sanctum dua guard tetap, tidak ada provider OIDC (ADR-007 (BFF/sesi) D-6a).

---

## 16. Di luar cakupan

Aplikasi mobile, multi-tenant, marketplace seller mandiri, pricing dinamis, program afiliasi, COD/transfer manual tanpa gateway sebagai jalur utama, fitur sosial komunitas (forum/komunitas — **bukan** login sosial, yang sejak 2026-11-18 justru jalur resmi §10.8), dan setiap bentuk pengumpulan data atau konten dari situs pihak lain.

Berubah dari versi lama: **multi-gudang tidak lagi otomatis di luar cakupan** — Blueprint §6.1/§17 memperlakukannya sebagai keputusan Phase 4 dan pertanyaan klien (Doc 11 §6 Q-5). Sementara jawabannya belum ada, **default kita satu gudang** dan kunci `stock_items` dihitung ulang saat keputusan jatuh (B-5, ADR-011).
