# Dokumen 12 — Kontrak API `/api/v1`

**Rujukan induk:** SNOWEGO_MICROSERVICES_BLUEPRINT.md v1.0 — keputusan di atas mengikuti Blueprint (ADR-003), konflik diselesaikan di Doc 11.

Status: **draf untuk review (dokumen baru, lahir dari B-2/B-14)** · Tanggal: 2026-11-17 · **Amandemen 2026-11-18**: §1.2 G2, §2.2 kelompok S, §2.3, §2.4, §3.1, §4.3, §5.1, dan §8 mengikuti keputusan pemilik tentang auth/RBAC (Doc 11 §4.4; ADR-007 (BFF/sesi) amandemen; ADR-012 (RBAC/identitas/dependensi)). · Pasangan: Doc 01 §4 (aturan arsitektural) dan Doc 11 B-2/B-3/B-6/B-7/B-9/B-13.
Fungsi dokumen ini: memenuhi kewajiban Blueprint §7 ("OpenAPI 3.1 sebagai source of truth") pada level **spec text**. Berkas `packages/api-contracts/openapi.yaml` adalah wujud mesinnya; dokumen ini adalah wujud yang diaudit manusia. Kalau keduanya bertentangan, **yang benar adalah `openapi.yaml`**, dan dokumen ini harus diperbaiki — bukan sebaliknya.

> Model auth §3.1 memakai sesi Sanctum, guard `web` dan `admin` terpisah, serta CSRF pada setiap mutasi yang memakai sesi. Pada 2026-11-20 pemilik proyek dan klien dikonfirmasi telah menyetujui kontrol cookie, CSRF, 2FA, dan pemisahan guard. Lihat [catatan persetujuan dan Q-2](threat-model.md#6-item-terbuka-yang-menghalangi-penutupan).

---

## 1. Aturan kontrak

### 1.1 Lokasi & sumber kebenaran

| Hal | Aturan |
|---|---|
| Berkas spec | `packages/api-contracts/openapi.yaml` — **OpenAPI 3.1**, satu berkas dengan `$ref` internal ke `components/` (boleh dipecah ke `components/*.yaml` bila melewati ±3.000 baris; hasil akhir tetap satu dokumen gabungan) |
| Dokumen manusia | `docs/12-api-contract.md` (berkas ini) + export READme-ish di `docs/api/` |
| Konsumen | `apps/web` dan `apps/admin`, keduanya melalui client TypeScript ter-generate |
| Aturan tunggal | Bentuk request/response yang **tidak ada di spec** tidak boleh dipakai frontend maupun dipancarkan controller. Tidak ada "endpoint sementara". |

### 1.2 Gerbang CI (wajib, bukan formalitas)

Pipeline menolak PR bila salah satu ini gagal:

| # | Pemeriksaan | Alasan |
|---|---|---|
| G1 | **PR yang mengubah controller/route/form request tanpa mengubah `openapi.yaml` gagal.** Deteksi lewat pemetaan rute ↔ path+operationId yang di-generate dari `routes/api.php`. | ini jawaban langsung atas B-2: spec tidak boleh jadi artefak yang tertinggal |
| G2 | `openapi.yaml` lolos validator 3.1 (spectral + aturan kustom kita: setiap operation wajib punya `operationId`, `tags`, `security`, `4xx` response, `request-id` header). **Pengecualian lama dicabut secara tercatat, bukan dilonggarkan diam-diam:** sebelum 2026-11-18 aturan kustom menerima `security` **atau** `x-elzara-auth` karena ADR-007 (BFF/sesi) belum tertutup; sejak amandemen pemilik (Doc 11 §4.4 I) `securitySchemes` **wajib ada** (cookie sesi `webSession` + `adminSession`, tanda tangan `s2sSignature`, parameter CSRF `X-XSRF-TOKEN`) dan `security` **wajib per operasi** — `public` ditulis eksplisit sebagai `[]`. `x-elzara-auth: public \| customer \| admin \| s2s` tetap dipakai sebagai penanda kategori, dan aksi modul admin wajib menambah `x-elzara-permission: <modul>.<aksi>` (prefix kanonik: Doc 11 §4.4 B) | spec yang tidak lengkap tidak bisa di-generate dengan benar; alasan lama "membekukan security = memilih model autentikasi lewat berkas CI" tidak berlaku lagi karena modelnya sudah diputuskan pemilik, bukan oleh CI |
| G3 | Generate client TS → build kedua app lolos terhadap client hasil generate | mencegah spec yang "sah" tapi tidak bisa dipakai |
| G4 | Diff spec terhadap `main`: tidak ada penghapusan field, perubahan tipe, atau perubahan status code pada path v1 yang sudah rilis tanpa label breaking | melihat breaking change sebelum masuk produksi, bukan setelah |
| G5 | Setiap field bertipe uang di spec adalah `integer` (minor unit) + field `currency` wajib berpasangan | menegakkan Doc 01 §6.1 / S-1 / B-13 di level kontrak, bukan di code review |
| G6 | Setiap operasi `POST`/`PATCH`/`DELETE` pada grup order/payment/inventory/quotation/batch **wajib** mendeklarasikan header `Idempotency-Key` sebagai required | Doc 01 §4 butir 3, B-7 |

### 1.3 Client ter-generate, tidak pernah diedit tangan

- Keluaran generator (`openapi-typescript` + `openapi-fetch`, atau setara) ditulis ke `packages/api-contracts/generated/` dan **di-commit** supaya reviewer melihat delta kontrak sebagai diff yang bisa dibaca.
- Folder `generated/` ditandai "do not edit"; lint rule menolak perubahan manual di path itu.
- Kalau bentuk hasil generate tidak ergonomic (namanya jelek, optional-nya salah), **perbaiki spec-nya**. Edit manual di client = temuan review tingkat tinggi.
- Frontend tidak boleh memanggil API dengan `fetch(url)` langsung. Satu-satunya jalur adalah client ter-generate (pengecualian: upload `PUT` presigned ke MinIO — itu bukan endpoint kita, lihat §3.7).

### 1.4 Versi & kebijakan deprecation

| Aturan | Isi |
|---|---|
| Basis path | `/api/v1`. Versi di path, **bukan** di header, untuk v1→v2. (Header-only versioning membuat caching edge dan debugging manual lebih sulit; kami sadar itu bukan satu-satunya pilihan yang sah.) |
| Non-breaking untuk v1 | menambah field opsional ke response, menambah enum value baru, menambah query param opsional, menambah endpoint baru |
| Breaking untuk v1 | menghapus/mengganti nama field, mengubah tipe, mengubah semantik nilai, mengetatkan validasi sehingga request yang dulu 2xx jadi 4xx, mengubah bentuk pagination |
| Kalau breaking | buka `/api/v2`; `/api/v1` masuk masa deprecated. Response v1 yang deprecated membawa `Deprecation: true`, ` Sunset: <RFC1123>` (minimum **90 hari** setelah pengumuman) dan `Link: <doc>; rel="deprecation"`. |
| Pengumuman | setiap deprecation dicatat di tabel §1.5 dan di-changelog rilis; **tidak ada** deprecation diam-diam. Karena konsumen kita hanya dua aplikasi internal dan satu tim, masa 90 hari itu konservatif — tapi tetap dijalankan supaya kebiasaannya terbentuk sebelum ada konsumen ketiga. |
| Enum baru di response | klien **wajib** menulis handler dengan fallback (`default:`) untuk nilai enum yang belum dikenal; ini aturan kode, bukan cuma saran. Alasannya: S-2 memakai `varchar + CHECK` sehingga nilai baru memang akan datang. |

### 1.5 Register deprecation

| Versi | Status | Dideprekasi sejak | Sunset | Pengganti |
|---|---|---|---|---|
| v1 | aktif | — | — | — |

*(tabel ini kosong sekarang; ia wajib diisi sejak breaking change pertama.)*

---

## 2. Daftar endpoint

Kolom **Sisi**: `publik` (tanpa login) · `customer` (butuh sesi guard `web`) · `admin` (butuh sesi guard `admin`: `is_operator` + 2FA + permission granular, §3.1) · `s2s` (server-to-server: gateway/kurier, tanda tangan/HMAC). Kolom **Idem** = header `Idempotency-Key` wajib (§3.4).

### 2.1 Blueprint §7 — direproduksi penuh (15 baris / 17 operasi)

Ini persis tabel Blueprint §7, tidak dikurangi. Baris pertama dan kedua masing-masing memuat dua path.

| Method | Endpoint | Fungsi | Sisi | Idem | Modul (Doc 01 §3.3) |
|---|---|---|---|---|---|
| GET | `/products`, `/products/{slug}` | Catalog | publik | — | Catalog |
| GET | `/preorder-batches`, `/preorder-batches/{id}` | Open batches | publik | — | PreOrder |
| POST | `/cart/items` | Cart update | customer | ya | Cart |
| POST | `/checkout/sessions` | Checkout snapshot | customer | ya | Cart |
| POST | `/orders` | Create order (idempotent) | customer | ya | Orders |
| GET | `/me/orders/{id}` | Order detail | customer | — | Orders |
| POST | `/payment-intents` | Payment initiation | customer | ya | Payments |
| POST | `/webhooks/payments/{provider}` | Verified provider callback | s2s | — (dedup provider event id) | Payments |
| POST | `/product-requests` | New request | customer | ya | Sourcing |
| POST | `/product-requests/{id}/attachments/presign` | Upload URL | customer | ya | Sourcing + Content(`media`) |
| GET | `/me/quotations/{id}` | Customer quote | customer | — | Sourcing |
| POST | `/quotations/{id}/accept` | Accept versioned quote | customer | ya | Sourcing → Orders |
| POST | `/admin/preorder-batches/{id}/close` | Idempotent batch close | admin | ya | PreOrder |
| POST | `/admin/purchase-orders` | Supplier PO | admin | ya | Procurement |
| POST | `/admin/shipments` | Create fulfillment | admin | ya | Fulfillment |

### 2.2 Tambahan yang dibutuhkan modul kita (Blueprint tidak memuatnya)

Diderive dari daftar modul Doc 01 §3.3. Semua di bawah **baru terhadap Blueprint** — kalau sebuah path sudah ada di §2.1 ia tidak diulang di sini; yang sudah ada tapi butuh detail tambahan ditandai di catatan kelompok.

**A — Cart & checkout lanjutan** · modul Cart
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/cart` | Isi cart + total terhitung server | customer | — |
| DELETE | `/cart/items/{item_id}` | Hapus baris | customer | ya |
| GET | `/checkout/sessions/{checkout_session_id}` | Baca snapshot + masa berlakunya | customer | — |
| POST | `/checkout/sessions/{checkout_session_id}/shipping-options` | Hitung ulang tarif (server-side, `ShippingRateProvider`) | customer | — |
| POST | `/checkout/sessions/{checkout_session_id}/vouchers` | Validasi voucher dengan alasan penolakan | customer | — |

**B — Address book** · modul Identity · tabel `addresses` (rename Doc 11 §4.1)
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/me/addresses` | Daftar buku alamat | customer | — |
| POST | `/me/addresses` | Tambah alamat | customer | ya |
| GET | `/me/addresses/{address_id}` | Detail satu alamat | customer | — |
| PATCH | `/me/addresses/{address_id}` | Ubah alamat | customer | ya |
| DELETE | `/me/addresses/{address_id}` | Hapus alamat (ditolak bila dipakai order aktif) | customer | ya |
| POST | `/me/addresses/{address_id}/set-default` | Jadikan default | customer | ya |

**C — Orders sisi customer** · modul Orders
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/me/orders` | Daftar order (cursor, filter `order_type`/status) | customer | — |
| POST | `/orders/{order_id}/cancel` | Batalkan sebelum kirim, dengan alasan | customer | ya |
| GET | `/orders/{order_id}/tracking` | Timeline per shipment + tahap lane | customer | — |

**D — Retur & refund** · modul Orders + Payments + Billing (Doc 02 §8, Doc 06 §3)
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| POST | `/orders/{order_id}/returns` | Ajukan retur per item + alasan + foto | customer | ya |
| GET | `/me/returns` | Daftar pengajuan retur | customer | — |
| GET | `/me/returns/{return_id}` | Detail + status | customer | — |
| POST | `/me/returns/{return_id}/attachments/presign` | URL unggah bukti foto | customer | ya |
| POST | `/me/returns/{return_id}/cancel` | Tarik pengajuan | customer | ya |
| GET | `/admin/returns` | Antrean retur (filter role) | admin | — |
| POST | `/admin/returns/{return_id}/decision` | Setujui/tolak sebagian atau penuh + alasan wajib | admin | ya |
| POST | `/admin/returns/{return_id}/receipt` | Tandai barang retur diterima (menyentuh stok) | admin | ya |
| POST | `/admin/returns/{return_id}/refund` | Terbitkan refund atas retur yang disetujui | admin | ya |

**E — Ulasan (UGC)** · modul UGC · tabel `reviews`, `review_media`, `review_moderation`
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| POST | `/orders/{order_id}/reviews` | Ulasan; hanya pembeli terverifikasi, hanya order `delivered`/`completed` | customer | ya |
| GET | `/me/reviews` | Ulasan saya + status moderasi | customer | — |
| GET | `/products/{slug}/reviews` | Ulasan terpublikasi per produk (cursor, bisa disortir) | publik | — |
| GET | `/admin/reviews` | Antrean moderasi | admin | — |
| POST | `/admin/reviews/{review_id}/moderation` | approve / reject / request-revision, alasan wajib, append-only | admin | ya |
| POST | `/admin/reviews/{review_id}/reply` | Balasan resmi toko | admin | ya |

**F — Wishlist & "kabari saya"** · modul Catalog/Promotion (Doc 05 §5.5, Doc 03 Sprint 6)
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/me/wishlist` | Daftar favorit per akun (sinkron lintas perangkat) | customer | — |
| POST | `/me/wishlist/items` | Tambah varian ke wishlist | customer | ya |
| DELETE | `/me/wishlist/items/{variant_id}` | Hapus dari wishlist | customer | ya |
| POST | `/me/wishlist/back-in-stock-subscriptions` | Berlangganan notifikasi restock per varian | customer | ya |
| DELETE | `/me/wishlist/back-in-stock-subscriptions/{variant_id}` | Berhenti berlangganan | customer | ya |

**G — Notifikasi & preferensi** · modul Notification
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/me/notifications` | Kotak notifikasi in-app | customer | — |
| POST | `/me/notifications/{notification_id}/read` | Tandai dibaca | customer | ya |
| POST | `/me/notifications/read-all` | Tandai semua dibaca | customer | ya |
| GET | `/me/notification-preferences` | Preferensi kanal (email/WA/in-app) per jenis kejadian | customer | — |
| PATCH | `/me/notification-preferences` | Ubah preferensi | customer | ya |
| GET | `/admin/notifications/logs` | Log pengiriman per kanal untuk diagnosis CS | admin | — |

**H — Jalur beli atas nama & kode klaim** · modul `MarketplacePurchase` (B-4; tabel `marketplace_purchases`, `claim_codes`, `proof_files`)
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/me/marketplace-purchases` | Daftar pembelian atas nama akun saya | customer | — |
| GET | `/me/marketplace-purchases/{marketplace_purchase_id}` | Detail + status + bukti | customer | — |
| POST | `/me/marketplace-purchases/{marketplace_purchase_id}/proofs/presign` | URL unggah bukti bayar/identitas | customer | ya |
| GET | `/marketplace-claims/{claim_code}` | Status sebuah kode klaim (satu-klaim-per-akun, Doc 09 `drop_claims`) | publik (kode = kapabilitas; dibatasi rate limit) | — |
| POST | `/marketplace-claims/{claim_code}/redeem` | Tukarkan kode klaim ke akun ini — sekali, atomic | customer | ya |
| GET | `/admin/marketplace-purchases` | Antrean kerja pembelian pihak ketiga | admin | — |
| POST | `/admin/marketplace-purchases` | Catat pembelian baru + tautan invoice | admin | ya |
| POST | `/admin/marketplace-purchases/{marketplace_purchase_id}/claim-codes` | Terbitkan kode klaim sekali pakai | admin | ya |

**I — Media: finalize & presign** · modul Content (`media`, S-3) + B-9
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| POST | `/media/{media_id}/finalize` | Dipanggil browser **setelah** PUT presigned sukses; menutup upload & memicu validasi async | customer/admin | ya |
| GET | `/media/{media_id}` | Status pipeline: `uploaded` → `validating` → `validated`/`rejected` (+ alasan) | customer/admin | — |
| POST | `/admin/products/{product_id}/media/presign` | URL unggah untuk media katalog | admin | ya |

Catatan kelompok I: Blueprint hanya menyediakan presign untuk lampiran pengajuan (`/product-requests/{id}/attachments/presign`). **Finalize adalah sisi yang hilang dari alur presigned-upload** — tanpa langkah itu server tidak bisa membedakan "browser sudah selesai mengirim" dari "browser gagal di tengah jalan", dan validasi MIME/magic-byte tidak punya titik masuk. Endpoint presign untuk konteks lain (retur, bukti marketplace, media produk) memakai bentuk `<sumber>/{id}/{jenis}/presign`; semuanya mengembalikan baris `media` yang sama, bukan tabel baru (S-3).

**J — Sourcing: pengajuan & kuotasi berversi** · modul Sourcing
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/me/quotations` | Daftar kuotasi milik saya | customer | — |
| GET | `/me/quotations/{quotation_id}/versions` | Riwayat versi + mana yang masih bisa diterima | customer | — |
| GET | `/me/product-requests` | Daftar pengajuan saya + SLA | customer | — |
| GET | `/me/product-requests/{product_request_id}` | Detail + lampiran + status | customer | — |
| GET | `/admin/product-requests` | Antrean incoming requests | admin | — |
| POST | `/admin/product-requests/{product_request_id}/status` | submitted → reviewing → sourcing → rejected | admin | ya |
| POST | `/admin/product-requests/{product_request_id}/sourcing-options` | Simpan opsi supplier + estimasi biaya | admin | ya |
| POST | `/admin/quotations` | Terbitkan kuotasi **versi 1** (expiry, kurs snapshot, DP, lead time, syarat) | admin | ya |
| POST | `/admin/quotations/{quotation_id}/revisions` | Terbitkan versi baru; versi lama otomatis tidak bisa diterima | admin | ya |
| POST | `/admin/quotations/{quotation_id}/reject` | Tolak dengan alasan (tersimpan di riwayat customer) | admin | ya |

Catatan kelompok J: `POST /quotations/{id}/accept` **sudah ada di Blueprint §7** dan tidak diulang di sini; spesifikasi perilakunya (version pinning + expiry + accept sekali) ada di §5.3.

**K — Batch preorder: siklus penuh** · modul PreOrder
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/admin/preorder-batches` | Daftar batch + status lifecycle | admin | — |
| POST | `/admin/preorder-batches` | Buat batch (open/close time, quota, deposit policy, ETA, currency, shipping mode) | admin | ya |
| GET | `/admin/preorder-batches/{batch_id}` | Detail + posisi enrollment | admin | — |
| PATCH | `/admin/preorder-batches/{batch_id}` | Ubah (perubahan ETA memicu notif massal — Doc 06 §3) | admin | ya |
| POST | `/admin/preorder-batches/{batch_id}/open` | `scheduled → open` | admin | ya |
| POST | `/admin/preorder-batches/{batch_id}/cancel` | `cancelled_by_supplier`/`failed` + refund massal terpilih | admin | ya |
| GET | `/admin/preorder-batches/{batch_id}/participants` | Daftar enrollment + status bayar per buyer | admin | — |

Catatan kelompok K: `POST /admin/preorder-batches/{id}/close` **sudah ada di Blueprint §7**; ia wajib idempoten dan atomic (Doc 01 §6.3 K2) — pemanggilan ulang dengan key sama mengembalikan snapshot jumlah accepted yang sama, bukan menutup dua kali.

**L — Goods receipt (GRN)** · modul Procurement (pindah dari Inventory menurut B-4; tabel `goods_receipts`, `goods_receipt_items`)
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/admin/goods-receipts` | Daftar GRN (filter batch/supplier) | admin | — |
| POST | `/admin/goods-receipts` | Catat penerimaan (draft): qty diterima, qty rusak, berat nyata | admin | ya |
| GET | `/admin/goods-receipts/{goods_receipt_id}` | Detail + baris | admin | — |
| PATCH | `/admin/goods-receipts/{goods_receipt_id}` | Koreksi selama masih `draft` | admin | ya |
| POST | `/admin/goods-receipts/{goods_receipt_id}/post` | Posting → menulis `stock_movements` + menaikkan `stock_items.on_hand` (K1-only, tanpa jalur tulis lain) | admin | ya |
| POST | `/admin/goods-receipts/{goods_receipt_id}/reverse` | Balikan append-only (`reverses_id`), tidak menghapus entri lama | admin | ya |

**M — Biaya internasional & alokasi landed cost** · modul Logistics (tabel `customs_charges`, `landed_cost_allocations`)
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| POST | `/admin/customs-charges` | Catat pajak/bea masuk per kiriman kargo | admin | ya |
| GET | `/admin/landed-cost-allocations` | Daftar alokasi + status | admin | — |
| POST | `/admin/landed-cost-allocations/preview` | Hitung simulasi alokasi per order_item **tanpa menulis apa pun** | admin | — |
| POST | `/admin/preorder-batches/{batch_id}/landed-costs/allocate` | Terapkan alokasi; memicu invoice `intl_fees` dalam rentang `estimated_min/max` batch (ADR-002 A1) | admin | ya |

**N — Procurement sisi supplier** · modul Procurement
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/admin/suppliers` | Daftar supplier | admin | — |
| POST | `/admin/suppliers` | Tambah supplier | admin | ya |
| GET | `/admin/purchase-orders` | Daftar PO supplier | admin | — |
| PATCH | `/admin/purchase-orders/{purchase_order_id}/status` | Transisi status PO dengan guard state machine | admin | ya |
| POST | `/admin/supplier-invoices` | Catat invoice supplier (pemasok → kita) | admin | ya |

**O — Inventory admin** · modul Inventory (B-5)
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/admin/stock-items` | Lihat agregat `on_hand/reserved/damaged/in_transit/available_to_sell` per (gudang, SKU) | admin | — |
| POST | `/admin/stock-adjustments` | Penyesuaian manual (alasan wajib + role + audit); **tidak pernah** menulis `stock_items` tanpa `stock_movements` | admin | ya |
| POST | `/admin/stock-transfers` | Transfer antar gudang (`stock_transfers`) | admin | ya |
| POST | `/admin/stocktakes` | Buat/menyelesaikan opname stok (`stocktakes`) | admin | ya |
| POST | `/admin/stock-reservations/{stock_reservation_id}/release` | Lepas reservasi manual (kasus CS/order dibatalkan) | admin | ya |

**P — Fulfillment tambahan** · modul Fulfillment (tabel `packages`, `shipments`, `tracking_events`)
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/admin/shipments` | Daftar shipment + filter pick list | admin | — |
| POST | `/admin/shipments/{shipment_id}/labels` | Buat label via `ShippingRateProvider` | admin | ya |
| POST | `/admin/shipments/{shipment_id}/mark-shipped` | Serahkan ke kurier + transisi `fulfillment_status` | admin | ya |
| POST | `/webhooks/courier/{provider}` | Callback tracking dari kurir, terverifikasi signature | s2s | — (dedup event provider) |

**Q — Billing sisi customer & rekonsiliasi** · modul Billing + Payments (ADR-002)
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/me/invoices` | Daftar tagihan + jatuh tempo (`status = open order by due_at`, ADR-002 A2) | customer | — |
| GET | `/me/invoices/{invoice_id}` | Detail tagihan + jenis + alokasi pembayaran | customer | — |
| POST | `/payment-intents/{payment_intent_id}/status-check` | Tarik status dari provider bila webhook tertunda (rate-limited, tidak pernah menandai lunas sendiri di luar state machine) | customer | ya |
| GET | `/admin/reconciliation/runs` | Hasil rekonsiliasi harian + daftar selisih (U5) | admin | — |
| POST | `/admin/reconciliation/runs` | Jalankan rekonsiliasi manual untuk satu periode | admin | ya |

**R — Infra & observability**
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| GET | `/health` | Liveness — tanpa dependensi eksternal | publik (dikecualikan dari Waiting Room) | — |
| GET | `/health/ready` | Readiness —cek Postgres/Redis/MinIO; **tidak** pernah mengembalikan detail koneksi | publik (tanpa body sensitif) | — |

**S — Auth & sesi** · modul Identity · ditambahkan pada amandemen 2026-11-18 setelah mekanisme sesi diputuskan (ADR-007 (BFF/sesi) amandemen; Doc 11 §4.4 C/I); sudah masuk `openapi.yaml`
| Method | Endpoint | Fungsi | Sisi | Idem |
|---|---|---|---|---|
| POST | `/auth/register` | Registrasi hybrid email+password; sosial lewat redirect | publik | — |
| POST | `/auth/login` | Login storefront email+password (akun `active` apa pun) | publik | — |
| POST | `/auth/logout` | Tutup sesi web + cabut baris `user_sessions` | customer | — |
| POST | `/auth/otp/request` | Minta OTP (`otp_challenges`: login/reset_password/verify_phone); respons anti-enumeration | publik | — |
| POST | `/auth/otp/verify` | Verifikasi OTP; `purpose=login` membuka sesi web | publik | — |
| GET | `/auth/social/{provider}/redirect` | Redirect OAuth `google`/`x` + state (login DAN registrasi) | publik | — |
| GET | `/auth/social/{provider}/callback` | Callback sosial; `user_identities`, penggabungan hanya lewat email terverifikasi | publik | — |
| GET | `/me/sessions` | Daftar sesi/perangkat aktif (cursor) | customer | — |
| DELETE | `/me/sessions/{session_id}` | Cabut sesi perangkat (policy owner; bukan milik sendiri → 404) | customer | — |
| POST | `/auth/password/reset-request` | Token reset sekali-pakai via email; respons anti-enumeration | publik | — |
| POST | `/auth/password/reset-confirm` | Password baru dengan token; sesi web lain dicabut | publik | — |
| POST | `/admin/auth/login` | Verifikasi email dan kata sandi operator aktif yang telah enrol 2FA, lalu terbitkan challenge dengan respons `200` | publik | — |
| POST | `/admin/auth/2fa/verify` | Verifikasi TOTP atau recovery code sekali pakai, lalu buka sesi guard `admin` | publik | — |
| GET | `/admin/auth/session` | Verifikasi sesi admin aktif milik pemanggil | admin | — |
| POST | `/admin/auth/logout` | Tutup sesi admin sendiri | admin | — |
| GET | `/sanctum/csrf-cookie` | Cookie `XSRF-TOKEN` sebelum mutasi pertama; **di luar `/api/v1`** (root host yang sama) | publik | — |

Catatan kelompok S: semua mutasi memakai `X-XSRF-TOKEN` (§3.1). Untuk login dan verifikasi 2FA admin, panggil `/sanctum/csrf-cookie?scope=admin&purpose=login`. Browser menyimpan cookie sementara `elzara_admin_challenge` dan mengirim challenge dari sesi yang sama. Untuk logout, ambil token sesi aktif dari `/sanctum/csrf-cookie?scope=admin`. Pembacaan dan penutupan sesi sendiri memerlukan guard admin dan sesi terverifikasi, tanpa izin modul tambahan. `GET /sanctum/csrf-cookie` memakai path-level server override karena endpoint bawaan Sanctum berada di root.

### 2.3 Ringkasan jumlah

| Kelompok | Operasi |
|---|---|
| Blueprint §7 (§2.1) — 15 baris | 17 |
| Tambahan kita (§2.2, kelompok A–S) | 115 |
| **Total operasi yang dinormakan** | **132** |
| Rute ber-`Idempotency-Key` wajib | 64 (§2.1 10 + §2.2 54; kelompok S tidak menambah — lihat catatannya) |
| Baris per sisi akses (130 baris) | `admin` 55 · `customer` 54 · `publik` 17 · `s2s` 2 · `customer/admin` 2 |

Angka ini adalah hasil menghitung baris di §2.1 dan §2.2 pada dokumen ini, bukan klaim cakupan; kelompok S (15 operasi auth) ditambahkan pada amandemen 2026-11-18 dan angkanya dihitung ulang di sini. Kalau `openapi.yaml` menghasilkan jumlah berbeda, **dokumen ini yang salah** dan harus disamakan (peraturan §1).

### 2.4 Belum dinormakan di dokumen ini (pendaftaran tugas, bukan kelalaian diam-diam)

Grup modul berikut butuh endpoint dan **sengaja belum diberi bentuk** supaya tidak ada kontrak palsu yang bisa diimplementasi:

| Grup | Kenapa menunggu |
|---|---|
| Catalog admin (CRUD produk/varian/kategori/brand/koleksi/price list) | butuh keputusan `price_lists` vs harga inline (Doc 11 §4.2) dan bentuk optimistic lock (Doc 01 §6.3 K6) |
| Promotion (voucher/loyalty/drop, CRUD admin + validasi) | aturan bisnis B-PO di Doc 02 §15 belum dijawab klien |
| Content/CMS, nav, localization, SEO | tidak menyentuh uang; bisa menyusul setelah Phase 1 |
| Search publik | hasil Blueprint §12 (public catalog GET boleh langsung via API/CDN) — perlu keputusan cache policy dulu |
| Reporting/export (`exports` bucket, TTL) | bentuk export async (job → presigned URL di `exports`) belum diputuskan |
| Support (tiket, dispute) | butuh keputusan apakah tiket dipakai di MVP |
| Settings & feature flags admin | rendah risiko, tinggi volume field |

Daftar ini ditutup bertahap lewat PR yang **menambah spec lebih dulu**, sesuai G1. Baris "Auth & sesi" yang sebelumnya menunggu ADR-007 (BFF/sesi) **ditutup pada 2026-11-18**: mekanisme sudah diputuskan pemilik dan 15 operasinya terdaftar di §2.2 kelompok S — penutupan tercatat, bukan penghapusan diam-diam.

---

## 3. Konvensi

### 3.1 Autentikasi & otorisasi — diputuskan 2026-11-18: cookie sesi Sanctum

Blueprint §7 menawarkan dua jalur ("OAuth2/OIDC atau server-side session/BFF sesuai threat model"). Pemilik proyek memutuskannya pada 2026-11-18 melalui amandemen ADR-007 (BFF/sesi): **`laravel/sanctum` dengan `statefulApi` (cookie sesi), bukan bearer token dan bukan BFF wajib**. Register mengikatnya ada di Doc 11 §4.4 C/I; alasan dan alternatif yang ditolak ada di ADR-012 (RBAC/identitas/dependensi) serta ADR-007. Teks lama bagian ini ("belum tertutup, menunggu ADR-007") tidak berlaku lagi dan diganti seluruhnya di bawah.

**Bentuk yang berlaku (Bentuk C, ADR-007 D-1a).** Browser memanggil `/api/v1/...` pada origin yang sama dengan cookie sesi. Route handler/server component Next.js boleh meneruskan cookie yang sama untuk SSR, tetapi **tidak memegang sesi sendiri dan tidak menjadi tempat keputusan otorisasi** — kebenaran otorisasi selalu di Laravel (Policy/Gate per request); menyembunyikan menu di Next.js bukan kontrol (Doc 01 §9.1, B-10). Tidak ada lapis BFF wajib untuk alur sensitif; pengecualian catalog GET publik lewat CDN tetap berlaku (Blueprint §12).

**Kebijakan cookie (ADR-007 D-2a).**

| Cookie | Guard | Atribut | Fungsi |
|---|---|---|---|
| `elzara_web_session` | `web` | `Secure; HttpOnly; SameSite=Lax; Path=/` | Sesi storefront; otorisasi = kepemilikan + status akun |
| `elzara_admin_session` | `admin` | `Secure; HttpOnly; SameSite=Lax; Path=/` | Sesi admin dibuka setelah kebijakan autentikasi environment terpenuhi |
| `XSRF-TOKEN` | — | `Secure; SameSite=Lax; Path=/`; sengaja **TANPA `HttpOnly`** | Dibaca JavaScript untuk header `X-XSRF-TOKEN`; itu desain Sanctum, bukan kelalaian |

**Rasional same-site.** Edge kita satu host dengan pemisah path (`/api/v1/webhooks/`, `/api/`, `/storage/`, `/admin`, `/` — `docker/nginx/dev.conf`), jadi halaman dan API berada pada origin yang sama: cookie sesi adalah **same-site**, `SameSite=Lax` cukup, **tidak ada CORS lintas origin dan tidak ada `SameSite=None`**. `Path=/` wajib karena cookie harus ikut terkirim ke `/api/`; pemisahan admin/storefront dilakukan lewat **nama cookie + guard, bukan lewat path**. Cookie web tidak pernah diterima guard admin, sehingga XSS di storefront tidak menjadi akses admin. Kalau produksi kelak memindahkan admin ke subdomain terpisah, cookie menjadi lintas-site dan **keputusan ini harus dibuka kembali** (ADR-007 D-6a) — itu perubahan topologi, bukan setelan.

**Alur CSRF (wajib pada setiap mutasi, tanpa pengecualian admin).**

1. Klien memanggil `GET /sanctum/csrf-cookie` sebelum mutasi pertama (§2.2 kelompok S).
2. JavaScript membaca cookie `XSRF-TOKEN` dan men-set header `X-XSRF-TOKEN` pada request mutasi.
3. Server menolak mutasi (POST/PATCH/PUT/DELETE) ber-cookie sesi tanpa CSRF valid → `419` (§4.3). Parameter komponen `X-XSRF-TOKEN` di spec bersifat `required` pada semua mutasi sesi.

**Tidak ada token jangka panjang di browser (ADR-007 D-3a).** `personal_access_tokens` **tidak dibuat** — Sanctum dipakai untuk sesi, bukan PAT; tidak ada bearer token di `localStorage` maupun memori aplikasi. Kredensial server-to-server (webhook gateway, worker internal) memakai **tanda tangan/HMAC** per driver + replay window, atau jaringan internal — tidak pernah cookie sesi. `s2s` tetap kategori terpisah di spec (`security: [{ s2sSignature: [] }]`), bukan varian customer/admin.

**Login web hybrid (login DAN registrasi).** Email+password, Google (driver bawaan `laravel/socialite`), dan X (`socialiteproviders/twitter`, OAuth 2.0 — driver `twitter` bawaan Socialite memakai OAuth 1.0a yang sudah ditinggalkan X, jadi tidak dipakai). Identitas sosial disimpan di `user_identities` (`provider`, `provider_user_id`, unik per provider). **Penggabungan akun hanya lewat email terverifikasi**, bukan email mentah dari provider — kalau tidak, siapa pun yang mendaftar di provider dengan email korban bisa mengambil alih akun. OTP (`otp_challenges`) melayani purpose `login | reset_password | verify_phone`; daftar sesi/perangkat (`user_sessions`) bisa dilihat dan dicabut sendiri (§2.2 S).

**Gerbang admin (ADR-007 D-4a, ADR-012 D-1/D-4).** Login admin memakai email dan kata sandi. Operator harus aktif. Alur 2FA menerbitkan challenge ber-TTL pendek dan membuka sesi setelah TOTP atau recovery code sekali pakai terverifikasi. Recovery code disimpan dengan hash Argon2id. Khusus `APP_ENV=local`, `ELZARA_LOCAL_ADMIN_PASSWORD_ONLY_EMAIL` dapat mengizinkan satu email operator membuka sesi setelah verifikasi kata sandi. Pengaturan ini diperiksa lagi pada setiap validasi sesi tersebut. Staging dan production mewajibkan 2FA.

**RBAC admin granular (Doc 11 §4.4 B).** `spatie/laravel-permission` dengan `guard_name = admin`; permission dinamai `<modul>.<aksi>` dengan aksi **terpisah** `view` (index/show), `create`, `update`, `delete`, ditambah aksi khusus `billing.void`, `order.force_transition`, `batch.close`, `payment.mark_paid_manual`. Prefix kanonik (salinan register Doc 11 §4.4 B, bukan daftar baru): Identity→`user`, Catalog→`catalog`, Content→`content`, PreOrder→`batch`, Billing→`billing`, Orders→`order`, Inventory→`inventory`, Cart→`cart`, Payments→`payment`, Fulfillment→`shipment`, Logistics→`logistics`, Promotion→`promotion`, Sourcing→`sourcing`, Procurement→`procurement`, MarketplacePurchase→`marketplace`, UGC→`review`, Settings→`setting`, Notification→`notification`, Support→`ticket`, Audit→`audit`, Reporting→`report`. **Permission bisa diberikan langsung ke user** lewat `model_has_permissions`, jadi "view saja, tanpa create/update/delete" adalah keadaan normal satu baris, bukan role baru per kombinasi. 10 role Blueprint §4 tetap di-seed sebagai bundel; `superadmin` tidak lagi berarti bypass. Scope per batch/kategori/gudang tetap hidup lewat `role_scopes` — membatasi permission tidak membatasi baris mana yang boleh dilihat. **Storefront tidak memakai spatie**: tidak ada role/permission customer; otorisasi web adalah kepemilikan (policy owner) + status akun, dan `is_operator`/`is_admin` tidak memberi hak apa pun di storefront.

**Bypass berjejak (ADR-012 D-2).** `is_admin = true` menyalakan `Gate::before` yang meloloskan semua permission admin — **hanya pada guard admin**, tidak pernah di guard web — dan **setiap pemakaiannya wajib menulis `audit_logs`** (`action = rbac.bypass`, `reason`, `request_id`). `CHECK (is_admin = false OR is_operator = true)` membuat bypass tanpa hak login admin mustahil di tingkat database.

**Header aktor.** `X-Actor-Type` sebelumnya `customer | staff | system`, kini **`web | admin | system`** (Doc 11 §4.4 I): nilai diisi server dari guard/sesi yang dipakai, **tidak pernah** dipercaya dari klien, dan wajib ada di semua log permintaan. Seorang operator yang berbelanja di storefront adalah aktor `web`.

**Semantik 401 vs 403.**

| Situasi | Respons |
|---|---|
| Tanpa sesi / cookie kedaluwarsa / tidak valid (guard mana pun) | `401 UNAUTHENTICATED`, problem `urn:elzara:problem:auth:unauthenticated` — bukan redirect HTML; klien mengulang login |
| Sesi valid tetapi permission `<modul>.<aksi>` tidak dimiliki (dan bukan bypass `is_admin`) | `403 FORBIDDEN`, problem `urn:elzara:problem:authorization:forbidden` |
| Admin: `is_operator = false`, status bukan `active`, atau 2FA belum terkonfirmasi | `403 FORBIDDEN` — ditolak sebelum query apa pun dijalankan |
| Resource privat milik orang lain, bila enumerasi itu sendiri risiko | `404`, bukan `403` (kuotasi privat, §4.3) |

**Wujud di spec.** `openapi.yaml` memuat `securitySchemes` `webSession` + `adminSession` (apiKey in cookie, nama cookie persis di tabel atas) dan `s2sSignature` (penanda HMAC, bukan bearer), parameter `X-XSRF-TOKEN` wajib pada mutasi, `security` per operasi (`public` = `[]` eksplisit), `x-elzara-auth` sebagai penanda kategori, dan `x-elzara-permission` pada aksi modul admin. Pemeriksaan dan logout sesi sendiri cukup dengan sesi operator yang valid. Rute tanpa sesi (`public`) hanya: katalog, batch terbuka, ulasan terpublikasi, lookup kode klaim (rate-limited), health, endpoint auth kelompok S, dan webhook s2s (bertanda tangan). Larangan lama "belum boleh ada securitySchemes" dicabut oleh Doc 11 §4.4 I; G2 (§1.2) sudah diamandemen sesuai keputusan ini.

**Yang masih terbuka, jujur (ADR-007 D-6a).** (1) Tanda tangan threat model atas kontrol cookie/CSRF/2FA/pemisahan guard tetap kewajiban sebelum kode auth Sprint 1 ditulis (`docs/threat-model.md`) — keputusan mekanisme tidak menggantikan review ancaman. (2) Q-2 menyempit menjadi "apakah klien punya IdP/SSO sendiri"; kalau ya, provider OIDC ditambahkan pada jalur login yang sama, bukan mengganti mekanisme sesi. (3) Pemindahan admin ke subdomain terpisah membuka kembali keputusan ini.

### 3.2 Propagasi `request-id`

| Aturan | Isi |
|---|---|
| Header masuk | `X-Request-Id` (1–128 karakter, `[A-Za-z0-9._-]`). Jika klien mengirim, **server memakainya apa adanya**. Jika kosong, server membuat ULID. |
| Header keluar | selalu `X-Request-Id`, termasuk pada 4xx/5xx dan pada response idempotency-replay. |
| Isi lain | nilai yang sama disuntik ke `trace_id` OpenTelemetry, ke kolom `request_id` pada `audit_logs`/`webhook_receipts`, dan ke setiap baris log JSON. |
| Webhook s2s | request-id di-generate server dari `provider_event_id` supaya retry provider tetap ter-korelasi. |
| Klien | **wajib** menampilkan request-id di layar error ("kode referensi: …") — ini yang membuat laporan CS bisa diselesaikan tanpa membuka logs. |

### 3.3 Pagination cursor

Semua daftar yang bisa melewati satu halaman memakai cursor. **Offset dilarang** dan ditolak validator spec.

```json
{
  "data": [ /* … */ ],
  "meta": {
    "limit": 20,
    "has_more": true,
    "next_cursor": "eyJhIjoiMjAyNi0xMS0xNyIsImlkIjoxMjM0NTZ9",
    "prev_cursor": null
  }
}
```

| Aturan | Isi |
|---|---|
| Query | `?limit=` (default 20, maksimum 100; lebih dari itu → 422) dan `?cursor=` (opaque, base64url dari pasangan sort-key + id) |
| Kunci urut | **stabil dan deterministik**: `(created_at DESC, id DESC)` kecuali endpoint mendefinisikan sendiri. Cursor yang tidak deterministik menghasilkan baris hilang/terduplikasi saat ada sisipan — itu bug yang hanya muncul di produksi. |
| Bentuk cursor | opaque. Klien **tidak boleh** mem-parse atau membangun cursor sendiri. |
| Filter | filter yang berubah di tengah halaman membuat hasil berbeda; cursor menyimpan hash parameter filter, dan ketidakcocokan → `400 urn:elzara:problem:pagination:cursor-filter-mismatch`. |
| Tanpa cursor | response tidak punya `total`/`last_page`. Kami sengaja tidak menghitung `COUNT(*)` di jalur daftar — ia murah saat kecil dan menjadi penyebab query lambat pertama saat tabel membesar. Butuh angka agregat? Pakai endpoint Reporting, bukan daftar. |

### 3.4 Idempotensi (`Idempotency-Key`) — B-7

| Aspek | Spesifikasi |
|---|---|
| Header | `Idempotency-Key: <UUIDv4 atau ULID>` — **wajib** pada setiap operasi bertanda "Idem = ya" di §2. Kehilangan header → `400 urn:elzara:problem:idempotency:key-required`. |
| Scope penyimpanan | `(actor_id, operation, idempotency_key)` unik di `idempotency_keys`. `operation` = `operationId` spec, **bukan** URL — supaya `POST /orders` dan `POST /admin/orders` tidak saling menabrak. |
| Yang disimpan | hash SHA-256 dari body ternormalisasi + status + response body + `created_at` + `expires_at` |
| **Key sama + payload sama** | **hasil yang sama**: status code dan body identik dengan respons pertama; header `Idempotency-Replayed: true`. Tidak ada order kedua, tidak ada charge kedua, tidak ada event kedua. |
| **Key sama + payload berbeda** | `409 urn:elzara:problem:idempotency:key-payload-mismatch`. Ini bukan kesalahan yang boleh "di-auto-retry" oleh klien — artinya ada bug di klien. |
| Key sama masih diproses (concurrent) | request kedua memblokir pada row lock/unique constraint sampai pertama selesai, lalu mengembalikan hasil pertama atau 409. **Tidak pernah** dua eksekusi paralel. |
| TTL | 24 jam untuk mutasi customer; 7 hari untuk refund/payout/manual capture; 30 hari untuk batch close & goods receipt. Setelah TTL key boleh dipakai ulang. |
| Response 5xx | **tidak** disimpan sebagai hasil. Key tetap terbuka supaya klien boleh mencoba lagi; inilah gunanya — retry aman setelah crash. |
| Retry dari sisi kami | job internal memakai key yang diturunkan dari ID entitas (`order:{id}:place`), bukan dari `random()`. Key acak di dalam job = idempotensi palsu. |
| Batas | body request yang di-hash dibatasi 256 KB; lebih besar → `413`. |

### 3.5 Rate limit — header yang dijamin

Setiap response (termasuk yang di-cache di edge, kalau lolos) membawa:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 12
X-RateLimit-Reset: 1763366400        # epoch UTC detik
Retry-After: 8                       # hanya pada 429
X-RateLimit-Policy: checkout-per-account
```

| Kebijakan | Cakupan awal (diuji, bukan angka final) |
|---|---|
| Publik (katalog/search) | longgar, terutama dilayani CDN |
| Mutasi cart/checkout | per **akun** (bukan hanya per IP) + burst protection |
| `POST /quotations/{id}/accept` | sangat ketat — ini endpoint yang bot incar |
| Webhook s2s | **tidak** dibatasi per-IP secara longgar; cukup besar untuk gelombang retry provider, dijaga signature + dedup. Rate limit di sini adalah DDOS terhadap diri sendiri. |
| `/admin/*` | per operator (`is_operator`) + alarm anomaly; `analyst` read-only (bundel permission `*.view`) |

Cloudflare Rate Limiting bekerja di edge dan header ini tetap dikendalikan origin sehingga konsisten saat orang menembus Cloudflare (Blueprint §16 test "Cloudflare bypass"). Angka ambang **ditetapkan setelah k6** (Doc 11 §6 Q-4).

### 3.6 Waktu, timezone, dan format

| Aturan | Isi |
|---|---|
| Semua timestamp di kawat | **UTC**, ISO 8601 dengan `Z`, presisi detik atau milidetik: `2026-11-17T04:12:33Z` |
| Yang **tidak** boleh | offset lokal di wire (`+07:00`), naive datetime tanpa zona, epoch integer. `timestamptz` disimpan UTC (S-9) |
| Display | klien merender memakai timezone akun (`users.timezone`, default `Asia/Jakarta`), **bukan** timezone browser diam-diam, karena "jatuh tempo pelunasan" dan "batch closes at" adalah janji ke customer. Zona yang dipakai dikirim balik sebagai `X-Displayed-Timezone` di respons daftar/detail order supaya tidak ada perdebatan saat sengketa. |
| Field tanggal murni (ETA batch, tanggal lahir) | `format: date` (`2026-12-05`), **bukan** timestamp |
| Field durasi | ISO 8601 duration (`PT2H` untuk TTL reservasi) |
| Uang di kawat | `integer` minor unit rupiah + `currency: "IDR"` wajib berpasangan (G5). Tidak pernah float, tidak pernah `NUMERIC` ter-serialisasi sebagai string desimal (Doc 01 §6.1, B-13) |
| ID | string berprefiks ULID: `ord_`, `item_`, `cs_`, `inv_`, `pay_`, `rsv_`, `quo_`, `media_`, `evt_`. **Tidak pernah** PK integer di permukaan API (S-4: id berurutan membocorkan volume transaksi) |

### 3.7 Yang **bukan** bagian kontrak `/api/v1`

`PUT`/`POST` presigned langsung dari browser ke MinIO bukan endpoint aplikasi: tidak ada envelope, tidak ada problem details, dan validasinya adalah `403` dari object store. Klien memperlakukannya sebagai jalur khusus, lalu memanggil `POST /media/{media_id}/finalize` (§2.2 I). Redirect ke payment gateway juga bukan bagian kontrak. Keduanya tetap butuh `X-Request-Id` dikirim lewat query/metadata supaya bisa dikorelasikan.

---

## 4. Error envelope — RFC 7807 problem details

### 4.1 Bentuk

Semua respons non-2xx (`4xx`/`5xx`) **dan** 2xx yang membawa peringatan memakai satu bentuk:

```json
{
  "type": "urn:elzara:problem:inventory:insufficient-stock",
  "title": "Stok tidak mencukupi",
  "status": 422,
  "detail": "Varian 'figures/xxx-blue-L' tersisa 2, diminta 3.",
  "instance": "/api/v1/orders",
  "request_id": "01J4Z9K3K7Q8R2M5N6P7S8T9UV",
  "code": "INSUFFICIENT_STOCK",
  "retryable": false,
  "errors": [
    { "pointer": "/items/1/variant_id", "code": "quantity_exceeds_available", "detail": "tersisa 2" }
  ]
}
```

`Content-Type: application/problem+json`. Field wajib: `type`, `title`, `status`, `code`, `request_id`, `retryable`. `errors` hanya ada kalau ada kegagalan per-field.

### 4.2 Skema `type` URN

`urn:elzara:problem:{kategori}:{kode-kebab-case}`

Kategori yang dipakai (tertutup — menambah kategori baru = PR dokumen ini lebih dulu): `validation`, `auth`, `authorization`, `not-found`, `inventory`, `batch`, `checkout`, `order`, `payment`, `quotation`, `media`, `idempotency`, `pagination`, `rate-limit`, `conflict`, `state`, `external`, `server`.

Alasan URN dan bukan URL: klien memprogram **cabang logika terhadap `code`**, bukan terhadap kalimat. `type` yang berupa URL yang bisa dibuka boleh ditambahkan nanti sebagai superset, tidak sebagai pengganti.

### 4.3 Peta DomainException → kontrak

Ini jembatan Doc 01 §12 (error taxonomy) dan Doc 01 §6 (konkurensi) ke level API. Kolom `retryable` menentukan apakah klien boleh mengulang tanpa perubahan payload.

| DomainException | HTTP | `code` | `type` | Retryable | Kontrak klien yang benar |
|---|---|---|---|---|---|
| `InsufficientStock` | 422 | `INSUFFICIENT_STOCK` | `…:inventory:insufficient-stock` | **tidak** | tampilkan sisa stok + tawarkan qty lain / wishlist (Doc 02 §4) |
| `BatchQuotaExhausted` | 422 | `BATCH_QUOTA_EXHAUSTED` | `…:batch:quota-exhausted` | tidak | "kuota batch habis", jangan buka ulang form kosong |
| `PoPeriodClosed` | 422 | `BATCH_NOT_OPEN` | `…:batch:not-open` | tidak | tampilkan jendela buka berikutnya |
| `StaleCheckoutSnapshot` | 409 | `CHECKOUT_SNAPSHOT_STALE` | `…:checkout:snapshot-stale` | **ya**, setelah re-price | buat `checkout_sessions` baru, tampilkan harga baru, minta konsen bila berubah |
| `QuotationExpired` | 410 | `QUOTATION_EXPIRED` | `…:quotation:expired` | tidak | kuotasi tidak dapat diterima lagi |
| `QuotationVersionStale` | 409 | `QUOTATION_VERSION_STALE` | `…:quotation:version-stale` | tidak | arahkan ke versi terkini (Doc 01 §6.3 K6) |
| `QuotationAlreadyAccepted` | 409 | `QUOTATION_ALREADY_ACCEPTED` | `…:quotation:already-accepted` | tidak | tampilkan order hasil accept pertama (single-accept). Nama kelas ikut konvensi §6.1: exception memakai `Quotation*`, hanya **event** yang memakai `Quote*` |
| `DepositAlreadyPaid` | 409 | `DEPOSIT_ALREADY_PAID` | `…:payment:deposit-already-paid` | tidak | state machine menolak transisi mundur |
| `ReservationAlreadyReleased` | 409 | `RESERVATION_RELEASED` | `…:inventory:reservation-released` | tidak | masuk jalur `paid_stock_exception` di sisi server |
| `PaidStockException` | 500 (bukan untuk publik) | `PAID_STOCK_EXCEPTION` | `…:order:paid-stock-exception` | tidak | order tetap dibuat; uang diterima; lihat Doc 01 §6.5 |
| `IdempotencyKeyReuse` | 409 | `IDEMPOTENCY_KEY_PAYLOAD_MISMATCH` | `…:idempotency:key-payload-mismatch` | tidak | **bug klien** — jangan auto-retry |
| `ConflictRetryExhausted` | 409 | `CONFLICT_RETRY_EXHAUSTED` | `…:conflict:retry-exhausted` | **ya**, dengan key yang sama | retry 1–2× dengan jitter; kalau masih gagal, minta user mengulang manual |
| `InvalidTransition` | 409 | `STATE_TRANSITION_NOT_ALLOWED` | `…:state:transition-not-allowed` | tidak | guard state machine empat sumbu (Doc 01 §5) |
| `MediaValidationFailed` | 422 | `MEDIA_VALIDATION_FAILED` | `…:media:validation-failed` | tidak | MIME/magic-byte/ukuran ditolak saat finalize (B-9) |
| `ClaimCodeRedeemed` | 409 | `CLAIM_CODE_ALREADY_REDEEMED` | `…:order:claim-code-redeemed` | tidak | satu klaim per akun (`drop_claims`) |
| `AddressInvalid` | 422 | `ADDRESS_INVALID` | `…:order:address-invalid` | tidak | wajib per-field `errors[]` dengan `pointer` |
| `VoucherIneligible` | 422 | `VOUCHER_INELIGIBLE` | `…:promotion:voucher-ineligible` | tidak | tampilkan **alasan** (min. belanja / periode / kuota) |
| `TrackingUnavailable` | 502 | `TRACKING_UNAVAILABLE` | `…:external:tracking-unavailable` | **ya** | circuit breaker; jangan render error merah untuk data yang akan datang |
| — validasi form | 422 | `VALIDATION_FAILED` | `…:validation:failed` | tidak | per-field |
| — tanpa identitas | 401 | `UNAUTHENTICATED` | `…:auth:unauthenticated` | setelah login | cookie sesi tidak ada/kedaluwarsa/tidak valid (guard `web` atau `admin` sesuai rute); klien mengulang login — mekanisme sudah diputuskan (§3.1), bukan redirect HTML |
| — CSRF tidak valid | 419 | `CSRF_TOKEN_MISMATCH` | `…:auth:csrf-mismatch` | **ya**, setelah `csrf-cookie` | panggil `GET /sanctum/csrf-cookie`, baca ulang `XSRF-TOKEN`, ulangi mutasi dengan `X-XSRF-TOKEN` baru (§3.1) |
| — permission tidak cukup | 403 | `FORBIDDEN` | `…:authorization:forbidden` | tidak | sesi valid tetapi permission `<modul>.<aksi>` tidak dimiliki dan bukan bypass `is_admin`; khusus admin juga 403 bila `is_operator = false`, status bukan `active`, atau 2FA belum terkonfirmasi (§3.1). 403 **bukan** 404 untuk resource privat milik orang lain, kecuali enumerasi itu sendiri adalah risiko (kuotasi privat → 404) |
| — tidak ditemukan | 404 | `NOT_FOUND` | `…:not-found:resource` | tidak | — |
| — melewati batas | 429 | `RATE_LIMITED` | `…:rate-limit:exceeded` | **ya** setelah `Retry-After` | hormati header (§3.5) |
| — kegagalan kita | 500 | `INTERNAL` | `…:server:internal` | ya | body **tidak pernah** berisi stack trace, SQL, atau PII |
| — sedang maintenance | 503 | `CHECKOUT_DISABLED` | `…:server:checkout-disabled` | ya | kill switch checkout (runbook `emergency-disable-checkout`) |

Untuk **kuotasi privat**: non-pemilik dan non-operator yang tidak berhak menerima `404`, bukan `403`. Perbedaan ini disengaja — 403 mengonfirmasi bahwa kuotasi itu ada, dan itu membocorkan informasi (Doc 01 §9.3, test "kuotasi privat tidak bisa didaftar enumeratif").

---

## 5. Contoh yang dikerjakan penuh (tiga alur sulit)

### 5.1 Checkout dengan `Idempotency-Key` + re-pricing server-side

**Langkah 1 — buka snapshot.**

```http
POST /api/v1/checkout/sessions HTTP/1.1
Host: elzara.example.com
Cookie: elzara_web_session=<opaque>
X-XSRF-TOKEN: <dibaca JS dari cookie XSRF-TOKEN setelah GET /sanctum/csrf-cookie>
Content-Type: application/json
X-Request-Id: 01J4Z9K3K7Q8R2M5N6P7S8T9UV
Idempotency-Key: 3f2a1c6e-8b4d-4f0a-9e2c-71b5d8a4c903

{ "shipping_address_id": "addr_01J4Z9M0QQ", "expected_items_hash": "sha256:9c1e…" }
```

Satu host: halaman dan `/api/v1` berada pada origin yang sama (§3.1), jadi kredensialnya cookie sesi same-site + CSRF — contoh sebelumnya memakai `Authorization: <menunggu ADR-007>` dan host API terpisah, dua-duanya tidak berlaku lagi sejak amandemen 2026-11-18.

`expected_items_hash` adalah hash isi cart seperti yang **dilihat browser**. Kalau server menghitung hash yang berbeda, snapshot tidak dibuka: `409 CHECKOUT_SNAPSHOT_STALE`. Ini cara kita memastikan user menyetujui isi yang benar-benar akan dibeli, bukan yang dirender 10 menit lalu.

**Langkah 2 — buat order.**

```http
POST /api/v1/orders HTTP/1.1
Cookie: elzara_web_session=<opaque>
X-XSRF-TOKEN: <nilai XSRF-TOKEN terkini>
Content-Type: application/json
Idempotency-Key: 5c68a742-2e4a-4f5b-b01b-f66b37c7bdf2
X-Request-Id: 01J4Z9K3K7Q8R2M5N6P7S8T9UV

{
  "checkout_session_id": "cs_01J4Z9P2XK",
  "shipping_address_id": "addr_01J4Z9M0QQ",
  "payment_method": "virtual_account"
}
```

```http
HTTP/1.1 201 Created
Location: /api/v1/me/orders/ord_01J4Z9R7YT
Content-Type: application/json
X-Request-Id: 01J4Z9K3K7Q8R2M5N6P7S8T9UV
X-Displayed-Timezone: Asia/Jakarta

{
  "order_id": "ord_01J4Z9R7YT",
  "order_number": "EZ-20261117-000412",
  "order_type": "preorder",
  "order_status": "awaiting_payment",
  "payment_status": "unpaid",
  "procurement_status": "not_required",
  "fulfillment_status": "awaiting_batch",
  "pricing": {
    "currency": "IDR",
    "items_total": 1450000,
    "discount_total": 100000,
    "shipping_total": 42000,
    "tax_total": 0,
    "grand_total": 1392000,
    "repriced": true,
    "changes_from_snapshot": [
      { "kind": "variant_price", "variant_id": "var_01J4Z8H0AA", "from": 725000, "to": 745000 }
    ]
  },
  "exchange_rate_snapshot": 16450,
  "reservation_expires_at": "2026-11-17T06:12:33Z",
  "invoice": { "invoice_id": "inv_01J4Z9R8Z0", "type": "deposit", "amount": 417600, "due_at": "2026-11-17T06:12:33Z" }
}
```

**Yang dijamin server (wajib ada di test, bukan di komentar):**

1. **Re-pricing mutlak.** `items_total`, `discount_total`, `shipping_total`, `tax_total`, `grand_total` dihitung ulang dari `checkout_sessions` yang tervalidasi + `price_lists` + tarif kurir live. Field apa pun bernama total yang datang dari browser **tidak dibaca sama sekali**. Kalau nominal berubah, order **tetap dibuat** dan `repriced: true` + `changes_from_snapshot[]` memberi tahu klien secara eksplisit — keputusan "lanjutkan/batalkan" ada di tangan user, bukan dikecilkan jadi toast.
2. **Satu transaksi.** `orders` + `order_items` + `stock_reservations` (atau `preorder_enrollments` + kuota batch) + `invoices` + `outbox_events` ditulis bersamaan. `PlaceOrder` tidak mengirim apa pun ke luar proses sebelum `COMMIT` (Doc 01 §6.3 K1).
3. **Kunci stok atau kuota, bukan keduanya.** Item batch menyentuh `preorder_batch_items.reserved_qty`; item RTS menyentuh `stock_items.reserved`.
4. **Replay.** Request identik dengan key sama → `201` dengan **body yang sama persis** + `Idempotency-Replayed: true`; tidak ada order kedua.
5. **Key sama, payload beda** → `409 urn:elzara:problem:idempotency:key-payload-mismatch`.
6. **Stok habis karena balapan** → `422 INSUFFICIENT_STOCK`, dan klien **tidak boleh** mengirim ulang dengan key sama: stoknya memang habis.
7. `reservation_expires_at` dan jatuh tempo invoice **diselaraskan** (Doc 01 §6.4).

### 5.2 Webhook pembayaran — verifikasi, dedup, ACK cepat, proses async

```http
POST /api/v1/webhooks/payments/midtrans HTTP/1.1
Content-Type: application/json
X-<Provider>-Signature: sha256=1f7c…
X-Request-Id: (boleh kosong; server membuat dari provider_event_id)

{ "event_id": "evt_prov_88213", "type": "payment.settlement",
  "order_ref": "EZ-20261117-000412", "amount": 417600, "currency": "IDR",
  "paid_at": "2026-11-17T04:11:58Z", "status": "captured" }
```

```http
HTTP/1.1 200 OK
Content-Type: application/json
X-Request-Id: 01J4Z9S1ABCD…

{ "received": true, "duplicate": false }
```

**Kontrak yang ditegakkan (Blueprint §8.3 + Doc 01 §6.4):**

| Tahap | Aturan | Response kalau gagal |
|---|---|---|
| 1. Transport | hanya POST; body mentah diarsipkan apa adanya sebelum disentuh | — |
| 2. Signature | diverifikasi dengan secret per driver; **replay window** ditegakkan | `400 urn:elzara:problem:payment:signature-invalid` |
| 3. Identitas merchant | merchant account di payload harus cocok dengan konfigurasi driver | `400 …payment:merchant-mismatch` — bukan 403; bagi provider itu "pesan tidak untuk kami" |
| 4. **Persist + dedup** | insert `webhook_receipts` dengan `UNIQUE(provider, provider_event_id)`; tabrakan unique = duplikat → **`200 {"received":true,"duplicate":true}`** | 200 (bukan 409!) — memberi 409 pada duplikat membuat provider mengretry selamanya |
| 5. **ACK cepat** | setelah langkah 4 ter-commit, server menjawab. Target: tanpa panggilan eksternal apa pun di jalur ini. | `503` hanya bila DB mati — supaya provider retry |
| 6. **Proses async** | job di pool `payments`: validasi amount/currency/reference → ambil lock sesuai urutan §6.4 Doc 01 → state guard `WHERE status = 'awaiting_payment'` → `PaymentCaptured` → invoice dibayar → tagihan berikutnya → notifikasi | kegagalan di sini **tidak** mengubah response 200; ia masuk retry/DLQ + metrik |

Yang **tidak** dilakukan di jalur ini, dan itu bagian dari kontrak: tidak ada `UPDATE` stok di dalam handler, tidak ada "coba lagi dengan key baru", dan tidak ada penandaan lunas berdasarkan payload yang belum dicocokkan dengan `payment_intents` lokal. Webhook adalah **klaim**, pencocokan adalah **bukti**.

Kasus spesifik: pembayaran sah dengan reservasi sudah dilepas → **jangan** menganggap stok tersedia; ikuti Doc 01 §6.5 (`paid_stock_exception`). Event `PaymentCaptured` tetap dipancarkan karena uangnya nyata; transisi `order_status` tidak.

### 5.3 `POST /quotations/{id}/accept` — version pinning + expiry + accept sekali

```http
POST /api/v1/quotations/quo_01J4Z7F3BQ/accept HTTP/1.1
Content-Type: application/json
Idempotency-Key: 8b6f0a11-4c2d-4e9a-8f31-2a7d6c0b5e14

{ "quote_version": 3, "accept_terms_token": "tt_01J4Z9V0AA" }
```

`accept_terms_token` adalah token sekali-pakai yang diterbitkan `GET /me/quotations/{id}` **pada versi itu** dan membungkus syarat + kurs + nominal yang ditampilkan ke user. Ini yang membuat "menyetujui harga" benar-benar tertaut ke angka yang dilihat, bukan ke angka saat submit.

```http
HTTP/1.1 201 Created
{ "order_id": "ord_01J4Z9W2CD", "invoice_id": "inv_01J4Z9W2CE",
  "accepted_quote_version": 3, "grand_total": 2850000, "currency": "IDR",
  "payment_status": "unpaid", "order_status": "awaiting_payment" }
```

| Situasi | Hasil |
|---|---|
| Versi 3 = versi terkini, belum expiry, belum pernah diterima | `201` — order + invoice dibuat dalam **satu transaksi idempoten** |
| Versi yang dikirim bukan `current_version` (sudah direvisi admin) | `409 QUOTATION_VERSION_STALE` — **kuotasi lama tidak boleh diterima setelah revisi** (Blueprint §5.3) |
| `expires_at` sudah lewat | `410 QUOTATION_EXPIRED` |
| Sudah pernah diterima (oleh request lain, atau oleh request ini) | `409 QUOTATION_ALREADY_ACCEPTED` dengan `order_id` hasil pertama — single-accept dijaga `UNIQUE(quotation_id)` di sisi tulis, bukan oleh `if` di PHP |
| Token syarat terbit untuk versi lain / sudah terpakai | `409 urn:elzara:problem:quotation:terms-token-stale` |
| Dua accept paralel, key berbeda | salah satu menang; yang lain `409 QUOTATION_ALREADY_ACCEPTED`. Tidak pernah dua order. |
| Item di kuotasi kehabisan stok saat accept | reservasi ulang via K1; gagal → `422 INSUFFICIENT_STOCK`, kuotasi **tetap** bisa diterima nanti bila stok masuk (status `quoted` tidak berubah karena accept yang gagal) |

Test wajib untuk alur ini (Blueprint §16): *"Quote accept concurrent with quote revision → satu versi final; stale accept ditolak."*

---

## 6. Kontrak event (outbox → inbox)

### 6.1 Aturan umum

| Aspek | Spesifikasi |
|---|---|
| Sumber | `outbox_events`, ditulis **di transaksi DB yang sama** dengan mutasi penyebabnya (B4). Tidak ada event yang dipancarkan sebelum commit. |
| Pembungkus | setiap event memakai sampul bersama di bawah; `payload` hanya berisi **ID + timestamp + nilai yang dibutuhkan konsumen**, tidak pernah objek/graph (B3) |
| Nama | `PastTense` / domain-event, satu kata per fakta bisnis, mengikuti Blueprint §2.2 supaya kontrak tetap sah saat modul di-ekstrak (Doc 01 §2.5) |
| Versi | `event_version` integer per nama event. Menambah field opsional = versi yang sama. Mengubah/menghapus field = versi baru, konsumen lama harus tetap bisa memproses |
| Jaminan kirim | **at-least-once.** Publisher bisa mengirim ulang setelah crash; karena itu setiap konsumen **wajib** idempoten |
| Dedup konsumen | `InboxConsumer` mengklaim pasangan `UNIQUE(consumer, event_id)` pada `inbox_events`, lalu menjalankan efek database dan menandai `processed` dalam satu transaksi. Pengiriman ulang untuk pasangan yang sudah `processed` melewati handler dan dicatat sebagai `inbox.dedup_ignored`. Konsumen lain tetap memproses event yang sama dengan klaimnya sendiri. |
| Gagal sementara | Efek database handler dibatalkan ke savepoint. Baris inbox menyimpan status `failed` dan informasi error tanpa payload event. Percobaan ulang dapat mengambil baris tersebut di bawah row lock dan menjalankan handler lagi. |
| Urutan | **tidak dijamin.** Konsumen memutuskan berdasarkan state terkini di DB + status guard, bukan berdasarkan urutan kedatangan. `occurred_at` dipakai untuk audit, bukan untuk mengambil keputusan correctness |
| Idempotensi efek | Jadwalkan efek eksternal melalui outbox atau setelah commit. Saat memanggil provider yang mendukung idempotensi, teruskan `idempotency_key` turunan (`evt:{event_id}:notif:{channel}`) agar percobaan ulang memakai kunci yang sama. Transaksi inbox hanya mencakup efek pada database yang sama. |
| Gagal permanen | retry backoff + jitter berbatas → DLQ → runbook `stuck-job-dlq.md`. Event **tidak pernah** dibuang diam-diam |
| Retensi | `outbox_events` diarsipkan setelah dikonsumsi N hari; `inbox_events` minimal 30 hari (jendela replay broker) |
| Bagian ini = **register kanonik** | Doc 11 §8.4 menunjuk §6.2 sebagai satu-satunya daftar event outbox. Doc 01 §8 (peta per domain), Doc 02 §10.5, Doc 09 `outbox_events.event_name` / `notification_templates`, dan seluruh runbook **mengutip namanya apa adanya**; tidak ada nama lokal, tidak ada daftar kedua |

**Konvensi `Quote*` vs `Quotation*` — keputusan, bukan typo** (Doc 11 §8.4). Ikut Blueprint §2.2: bentuk pendek **`Quote*` dipakai untuk nama event** (`QuoteIssued`, `QuoteAccepted`, `QuoteExpired`), sedangkan **`Quotation*` dipakai untuk nama entitas dan domain exception** (`quotations`, `quotation_versions`, `QuotationVersionStale` 409, `QuotationExpired` 410 di §4.3). Jadi `QuoteExpired` (event: penawaran sudah lewat, dipancarkan ke Notification) dan `QuotationExpired` (jawab HTTP 410 saat klien menyentuh kuotasi yang sudah kedaluwarsa) adalah **dua objek berbeda dengan satu akar kata** — keduanya benar dan keduanya harus ada. Kolom payload tetap memakai nama field bertipe entitas (`quotation_id`, `quote_version`) karena field mengikuti kolom tabel, bukan nama event.

Sampul bersama:

```json
{
  "event_id": "evt_01J4Z9R8Z0ABCDEFGH",
  "event_type": "StockReserved",
  "event_version": 1,
  "occurred_at": "2026-11-17T04:12:33Z",
  "aggregate_type": "stock_reservation",
  "aggregate_id": "rsv_01J4Z9R8Z1",
  "trace_id": "01J4Z9K3K7Q8R2M5N6P7S8T9UV",
  "idempotency_key": "ord:ord_01J4Z9R7YT:reserve",
  "payload": { }
}
```

### 6.2 Katalog event

Kolom **Kunci dedup inbox** adalah nilai yang membuat konsumsi ganda tidak berbahaya; semuanya adalah `event_id`, dan `(consumer, event_id)` adalah unique index-nya — yang didaftarkan di sini adalah **kunci bisnis tambahan** yang dipakai konsumen kalau `event_id` tidak cukup (mis. konsumen yang sama menerima dua event berbeda untuk fakta yang sama).

Tabel ini memuat **24 baris event** (hasil menghitung baris §6.2 pada dokumen ini, bukan klaim dari dokumen lain). Nama di luar daftar ini tidak sah: menambah event = menambah baris di sini lebih dulu (Doc 11 §8.4), baru `outbox_events.event_name` di Doc 09 dan `notification_templates` memakainya.

| Event | Penerbit (modul) | Payload (ringkas) | Jaminan | Kunci dedup inbox + kunci bisnis | Konsumen & efek |
|---|---|---|---|---|---|
| `UserRegistered` | Identity | `user_id`, `email_verified_at?`, `locale`, `created_at` | at-least-once | `(consumer, event_id)` | Notification (email selamat datang), Reporting |
| `ProductUpdated` | Catalog | `product_id`, `variant_ids[]`, `changed_fields[]`, `updated_by`, `occurred_at` | at-least-once | `(consumer, event_id)`; Search juga dedup per `(product_id, occurred_at)` supaya event lama tidak menimpa index baru | Search (reindex), cache Content |
| `StockReserved` | Inventory | `stock_reservation_id`, `order_id`, `warehouse_id`, `sku_id`, `quantity`, `expires_at` | at-least-once | `(consumer, event_id)`; Inventory memastikan satu reservasi per `(order_item_id, operation)` (K5) | Orders (state), Notification |
| `ReservationExpired` | Inventory | `stock_reservation_id`, `order_id`, `sku_id`, `quantity`, `released_at` | at-least-once | `(consumer, event_id)`; **konsumen wajib membaca ulang state order di dalam transaksi + urutan lock §6.4 Doc 01** | Orders, Payments (deteksi `paid_stock_exception`), Notification |
| `PaidStockExceptionRaised` | Orders + Inventory | `order_id`, `checkout_session_id`, `stock_reservation_id`, `variant_id`, `qty_paid`, `qty_available`, `amount_held`, `currency`, `raised_at`, `paid_stock_exception` | at-least-once | `(consumer, event_id)` **plus** kunci bisnis `order_id` di sisi konsumen eksepsi — satu order hanya punya satu eksepsi aktif (`orders.paid_stock_exception <> 'none'`, Doc 09 §6), sehingga replay tidak membuka dua tiket | Notification (beri tahu customer "pembayaran diterima, stok habis"), antrean eksepsi finance/ops pada queue `orders` (runbook `reservation-expiry-race.md` + `payment-callback-late.md`); **tidak** mengubah status order — resolusi tetap lewat Command Orders (B5, Doc 01 §6.5). Bukan `PaidStockException` §4.3, yang adalah domain exception HTTP 500 |
| `OrderPlaced` | Orders | `order_id`, `order_type`, `customer_id`, `grand_total`, `currency`, `item_count`, `expires_at` | at-least-once | `(consumer, event_id)` | Billing (invoice), Notification, Reporting, Promotion (klaim voucher) |
| `OrderCancelled` | Orders | `order_id`, `reason`, `actor_type`, `cancelled_at`, `refund_required` | at-least-once | `(consumer, event_id)` | Inventory (rilis), Payments, Promotion (voucher kembali) |
| `BatchClosed` | PreOrder | `preorder_batch_id`, `closed_at`, `accepted_quantity`, `accepted_enrollment_count` | at-least-once | `(consumer, event_id)`; snapshot jumlah **ikut di payload** supaya konsumen tidak perlu membaca tabel PreOrder | Procurement (rencana PO), Reporting |
| `QuoteAccepted` | Sourcing | `quotation_id`, `quote_version`, `order_id`, `customer_id`, `grand_total`, `currency` | at-least-once | `(consumer, event_id)`; **`UNIQUE(quotation_id)` di sisi Orders** menjadikannya accept-sekali meskipun event dikirim dua kali | Orders, Billing, Notification |
| `GoodsReceived` | Procurement | `goods_receipt_id`, `preorder_batch_id`, `received_at`, `weight_g_total`, `lines[]:{goods_receipt_item_id, sku_id, qty_received, qty_damaged}` | at-least-once | `(consumer, event_id)`; Inventory menulis `stock_movements` **sekali per `(goods_receipt_id, sku_id)`** | Inventory (`on_hand` naik, K1-only), Logistics, Billing (pemicu `intl_fees`) |
| `PaymentCaptured` | Payments | `payment_id`, `payment_intent_id`, `invoice_id`, `order_id`, `amount`, `currency`, `provider`, `provider_event_id`, `captured_at` | at-least-once | `(consumer, event_id)` **dan** `UNIQUE(provider, provider_event_id)` di `webhook_receipts` | Orders (`paid`), Billing, PreOrder (tagihan berikutnya), Notification, Promotion (poin) |
| `RefundCompleted` | Payments | `refund_id`, `payment_id`, `order_id`, `amount`, `currency`, `reason`, `completed_at` | at-least-once | `(consumer, event_id)` | Orders, Inventory (hanya bila belum kirim), Notification |
| `ShipmentDelivered` | Fulfillment | `shipment_id`, `order_id`, `courier`, `tracking_number`, `delivered_at` | at-least-once | `(consumer, event_id)` | Orders (`fulfillment_status`), Notification, UGC (membuka hak ulasan) |
| `NotificationDelivered` | Notification | `notification_log_id`, `channel`, `template_code`, `recipient_user_id`, `delivered_at`, `attempt` | at-least-once | `(consumer, event_id)` | Reporting, Audit |
| `DepositPaid` | PreOrder | `preorder_enrollment_id`, `order_id`, `amount`, `due_balance_at` | at-least-once | `(consumer, event_id)` | Orders, Notification |
| `BalanceInvoiced` | Billing | `invoice_id`, `order_id`, `type`, `amount`, `due_at` | at-least-once | `(consumer, event_id)` | Notification (H-7/H-1), Reporting |
| `QuoteIssued` | Sourcing | `quotation_id`, `quote_version`, `customer_id`, `expires_at` | at-least-once | `(consumer, event_id)` | Notification ("penawaran Anda siap") |
| `QuoteExpired` | Sourcing | `quotation_id`, `expired_at` | at-least-once | `(consumer, event_id)` | Notification, takedown visibilitas |
| `PackageShipped` | Fulfillment | `shipment_id`, `package_ids[]`, `tracking_number` | at-least-once | `(consumer, event_id)`; konsumen juga memakai `(shipment_id, tracking_number)` supaya satu paket yang dilaporkan dua kurir tidak mengirim dua notifikasi | Orders (`fulfillment_status = shipped`/`handed_over`), Notification (kirim resi), Reporting |
| `TrackingUpdated` | Fulfillment | `shipment_id`, `stage_code`, `raw_status`, `observed_at` | at-least-once | `(consumer, event_id)`; konsumen memakai `(shipment_id, stage_code)` supaya event ulang tidak mengirim notif ganda | Notification, Reporting |
| `StockLow` | Inventory | `sku_id`, `warehouse_id`, `available_to_sell`, `threshold` | at-least-once | `(consumer, event_id)` **plus** konsumen memakai jendela anti-spam per `(sku_id, warehouse_id, hari)` | Notification, alert admin, back-in-stock (wishlist) |
| `LandedCostAllocated` | Logistics | `preorder_batch_id`, `goods_receipt_id`, `total_allocated`, `currency`, `invoice_trigger: true \| false` | at-least-once | `(consumer, event_id)` | Billing (terbitkan `intl_fees`), Reporting |
| `ReturnDecided` | Orders | `return_id`, `return_code`, `order_id`, `decision: approved \| rejected`, `customer_expected: refund \| replace`, `lines[]:{return_item_id, order_item_id, qty_requested, qty_approved}`, `decision_note?` | at-least-once | `(consumer, event_id)` **plus** kunci bisnis `return_id` — keputusan kedua untuk retur yang sama harus lewat pembatalan+retur baru, bukan event susulan (`returns.status` hanya meninggalkan `requested` sekali, Doc 09 §6) | Payments (terbitkan `refunds` bila `customer_expected = refund`), Inventory (barang masuk **saat `received`**, bukan saat diputus → `stock_movements.ref_type='return_item'`, `reason='sale_return'`), Notification (setujui/tolak), Reporting |
| `MediaValidated` | Content | `media_id`, `model_type`, `model_id`, `kind`, `outcome: validated \| rejected`, `reason_code?` | at-least-once | `(consumer, event_id)`; konsumen memakai `media_id` saja — satu baris media punya satu hasil validasi (`media.moderation_status`, Doc 09 §15) | pemilik agregat yang menunjuk baris `media` polimorfik yang sama (S-3): Sourcing (lampiran request tampil), UGC (foto ulasan), Catalog (foto produk), Orders (bukti retur `App\Models\Return`), Identity (avatar/bukti transfer). Tidak ada event per jenis lampiran dan tidak ada tabel lampiran per modul |

### 6.3 Yang **tidak** dijanjikan kontrak event ini

- **Tidak ada urutan global.** `PaymentCaptured` boleh tiba setelah `ReservationExpired` untuk order yang sama — justru itu `paid_stock_exception` (Doc 01 §6.5).
- **Tidak ada exactly-once.** Yang kami punya adalah at-least-once + inbox dedup. Klaim "tepat satu kali" akan jadi bohong pada crash pertama.
- **Tidak ada transaksi lintas event.** Bila dua efek harus atomik, keduanya terjadi di satu Command di sisi penerbit, bukan dirangkai dari dua konsumen.
- **Tidak ada event yang wajib untuk correctness stok.** Correctness dijaga K1/K2/K5 di DB. Event melayani notifikasi, reporting, sinkronisasi — semuanya boleh terlambat, tidak boleh salah arah.

---

## 7. Catatan tabel: empat usulan yang kini **disahkan**

Keempat nama di bawah ini sempat kami tandai sebagai usulan. Statusnya berubah pada 2026-11-17: **Doc 11 §4.2 mengesahkan keempatnya**, dan ketiganya (empat tabel) sudah berdiri sebagai definisi nyata di Doc 09 serta terjadwal sebagai migrasi di Doc 10 §4/Appendix A. Tidak ada lagi yang berstatus "belum disahkan":

| Tabel | Disahkan di | Dibangun di | Dasar masing-masing |
|---|---|---|---|
| `returns` + `return_items` | Doc 11 §4.2 (baris pertama tabel "empat tabel disahkan") | Doc 09 §6 (modul Orders) · Doc 10 Sprint 2, level L7/L8 | **Blueprint §4** — grup Sales punya submenu "Returns"; tanpa keduanya submenu itu tidak punya objek. Jalur `refund_requested → refund_pending → refunded` pada `order_status` (Doc 11 §8.3) bergantung padanya. Tetap **terpisah** dari `refunds`: retur adalah peristiwa fisik + persetujuan, refund adalah peristiwa uang; menggabungkannya membuat "retur disetujui tapi refund ditolak" tidak bisa diwakili. Tautannya lewat `returns.refund_id` |
| `wishlist_items` | Doc 11 §4.2 | Doc 09 §8 (modul Cart) · Doc 10 level L4, Sprint 2 | **bukan** Blueprint — turunan produk kita sendiri (Doc 05 M3, "favorit"/"kabari saya" di PDP). Sah karena Blueprint §4/§6 menyebut daftar tabelnya sebagai **minimum**, bukan maksimum; dinyatakan sebagai ekstensi agar tidak disangka keharusan Blueprint |
| `back_in_stock_subscriptions` | Doc 11 §4.2 | Doc 09 §16 (modul Notification) · Doc 10 level L4, Sprint 3 | sama: turunan Doc 05 M3. Dieksekusi antrian `notifications` (nama antrian kanonik: Doc 11 §8.1; Blueprint §9) dan **tidak** menyentuh jalur uang/stok |

Konsekuensinya untuk dokumen ini: endpoint §2.2 kelompok D (retur) dan kelompok F (wishlist / back-in-stock) sekarang menunjuk tabel yang benar-benar ada, bukan skema fiktif. **Jumlah operasi di §2.3 tidak berubah** karena tidak ada satu pun endpoint yang ditambah atau dihapus oleh pengesahan ini — yang berubah hanya status tabelnya.

Yang **tidak** kami usulkan sebagai tabel baru, dan tetap tidak akan: lampiran retur, bukti marketplace, dan media produk — semuanya memakai `media` polimorfik (S-3) dengan `model_type` pemilik agregatnya (bukti retur = `App\Models\Return`, `kind='proof'`), sejalan dengan alasan Doc 11 §4.2 untuk `request_attachments`. Menambah tabel kedua untuk fakta yang sama melanggar B1.

---

## 8. Definisi selesai dokumen ini

Kontrak dianggap menutup kewajiban B-2 kalau semua ini benar — dan kami mencatat statusnya jujur: `openapi.yaml` **sudah ada** (termasuk `securitySchemes` dan endpoint auth kelompok S sejak amandemen 2026-11-18), tetapi **belum satu pun butir di bawah dicentang** karena gerbang CI belum terbukti berjalan menolak PR uji dan jumlah operasi spec belum menyusul §2.3 (spec ditutup bertahap sesuai G1):

- [ ] `packages/api-contracts/openapi.yaml` ada, lolos validator 3.1, dan jumlah operasinya sama dengan §2.3.
- [ ] Enam gerbang CI di §1.2 berjalan di pipeline dan terbukti menolak PR uji (satu PR mengubah controller tanpa spec → harus merah).
- [ ] `securitySchemes` (cookie sesi `webSession`/`adminSession` + CSRF `X-XSRF-TOKEN` + `s2sSignature`) dan `security` per operasi lolos validator G2 hasil amandemen 2026-11-18; pengecualian lama "menunggu ADR-007 (BFF/sesi) tertutup" **dicabut** (Doc 11 §4.4 I), jadi yang diuji sekarang kelengkapannya, bukan menunggu keputusan.
- [ ] §2.4 ditutup bertahap, dan setiap penutupan menambah baris §2.3 (dokumen ini ikut diperbarui, bukan ditinggalkan).
- [ ] Tiga contoh §5 dan dua angka penerimaan Blueprint §16 (10/1.000 dan 50/500) ada sebagai test yang gagal lebih dulu, lalu hijau.
- [ ] Tabel `type` URN §4.3 dipakai apa adanya di kode; tidak ada `code` string yang lahir di controller tanpa terdaftar di sini.

## 9. Dokumen terkait

Doc 01 §4 (aturan kontrak di sisi arsitektur), §5 (empat sumbu status), §6.3–§6.6 (konkurensi), §6.1 (uang & snapshot), §9 (RBAC + MinIO) · Doc 11 B-2, B-3, B-6, B-7, B-9, B-13, dan §4.4 (register keputusan pemilik 2026-11-18) · Doc 09 (bentuk kolom, S-1…S-10) · Doc 10 (M-1…M-13, expand-backfill-contract untuk perubahan kontrak) · `adr/ADR-002-invoice-model.md` · `adr/ADR-007-bff-vs-langung.md` (amandemen 2026-11-18) · `adr/ADR-012-identitas-rbac-dependensi.md` · Blueprint §7, §8.3, §2.2 · `docs/threat-model.md` (konfirmasi pemilik dan klien atas kontrol cookie, CSRF, 2FA, dan pemisahan guard tercatat di §6, ADR-007 D-6a).
