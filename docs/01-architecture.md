# Dokumen 01 — Arsitektur Platform Elzara

Status: disetujui untuk ditulis (review gate terbuka)
Tanggal: 2026-11-16
Cakupan: topologi, batas modul, model data, flow transaksi, Docker/infra, keamanan, observability, testing, jalur ekstraksi ke microservice.

## 1. Konteks & tujuan

Elzara adalah platform e-commerce satu-penjual untuk merchandise impor (pop-culture/fandom), dengan tiga jalur pemenuhan:

1. **Ready to Ship (RTS)** — stok sudah di Indonesia, langsung diproses.
2. **Pre-order (PO)** — periode PO dibuka/ditutup, bayar deposit lalu pelunasan, kirim setelah barang tiba.
3. **Request Order** — buyer mengajukan permintaan barang, admin membuat penawaran harga privat yang hanya terlihat oleh akun tersebut.

Prinsip desain: fungsi setara kategori (bukan salinan), arsitektur orisinal, dan semua aset/konten disediakan klien.

### 1.1 Keputusan topologi (ringkas)

| Opsi | Isi | Verdict |
|---|---|---|
| A | Modular monolith Laravel + worker/scheduler terpisah, boundary modul tegas, outbox sejak awal | **Dipilih** |
| B | Microservice-lite 4 service + event bus + saga checkout | Roadmap, dipicu 4 kondisi (§9) |
| C | 8–10 service + service mesh + K8s | Ditolak (rasio ops:tim tidak masuk untuk 1–2 dev) |

Alasan: dengan 1–2 dev dan trafik awal kecil, biaya transaksi terdistribusi (saga, konsistensi deposit/pelunasan, auth propagation, 4 pipeline CI/CD) memakan 40–60 % kapasitas tim. Boundary modul yang tegas memberi 80 % manfaat isolasi dengan 20 % biaya ops, dan tetap bisa dipecah nanti.

### 1.2 Non-goals ( sengaja di luar cakupan desain ini)

Multi-vendor/payout, mobile app native, gudang multi-lokasi fisik, ERP akuntansi penuh, marketplace online payment untuk barang digital, harga dinamis/algoritma re-pricing.

## 2. Topologi ter-deploy

```
                     ┌───────────────────────────────────────────┐
Internet ──443──▶    │  nginx / traefik (TLS, rate limit, gzip)  │
                     └───────────────┬───────────────────────────┘
                                     │ fastcgi + static
                     ┌───────────────▼───────────────┐   ┌──────────────────┐
                     │ app  (php-fpm 8.4, opcache)   │   │ worker (Horizon) │
                     │ Inertia SSR + HTTP API        │◀──┤ queues: default, │
                     │ modul-modul Laravel           │   │ payments, notif, │
                     └───────┬───────────┬───────────┘   │ search, reconcile│
                             │           │               └──────────────────┘
                             │           │               ┌──────────────────┐
                     ┌───────▼───┐  ┌────▼─────┐        │ scheduler (cron) │
                     │ postgres16│  │ redis7   │        │ 1 instance saja  │
                     └───────┬───┘  └────┬─────┘        └──────────────────┘
                             │           │
                     ┌───────▼───┐  ┌────▼──────────┐  ┌───────────┐  ┌─────────┐
                     │ meilisearch│  │ minio (S3)    │  │ outbound: │  │ mailpit │
                     └───────────┘  └───────────────┘  │ gateway   │  │ (dev)   │
                                                       │ payment/  │  └─────────┘
                                                       │ kurir/WA  │
                                                       └───────────┘
```

Karakteristik: satu proses web, proses tulis-heavy dipisah ke worker, semua state di Postgres, Redis hanya cache/queue/lock (tidak pernah jadi source of truth), media di object store.

## 3. Batas modul & kepemilikan data

Struktur tiap modul (contoh `Catalog`):

```
app/Modules/Catalog/
  Domain/           entities, value objects, policies, domain events, exceptions
  Application/      Commands (mutasi) + Queries (baca) = satu-satunya pintu masuk
  Infrastructure/   Eloquent models, migrations, driver-specific code, index sync
  Contracts/        interface publik modul + DTO (dikonsumsi modul lain)
  Tests/            unit + feature khusus modul
```

Aturan boundary (ini yang membuat §9 mungkin):

- **B1** Satu tabel hanya ditulis oleh satu modul. Modul lain baca lewat Query/DTO, tidak lewat relasi Eloquent lintas modul.
- **B2** Mutasi hanya lewat Command. Controller/artisan/job semuanya memanggil Command → ke depan, menambah HTTP API headless tinggal bungkus tipis.
- **B3** Event lintas modul hanya membawa ID + timestamp, bukan objek/graph.
- **B4** Event yang efeknya keluar proses (queue, webhook, notifikasi) ditulis ke `outbox` dalam transaksi yang sama; dispatcher membacanya setelah commit (transactional outbox).
- **B5** Uang dan status hanya berubah lewat aggregate Orders/Payments, tidak pernah lewat `UPDATE` manual.
- **B6** Tiap modul punya namespace tabel/fitur sendiri di migration agar pemisahan skema nanti mekanis.

| Modul | Tanggung jawab | Tabel inti | Kontrak publik (contoh) |
|---|---|---|---|
| Identity | akun customer, alamat, login/OTP, role staff, consent, hapus akun | `users`, `addresses`, `user_consents` | `GetCustomerProfile`, `EnsureAddressValid` |
| Catalog | produk, varian (warna/ukuran/anggota/tipe kartu), kategori, koleksi fandom/IP, atribut, media | `products`, `variants`, `categories`, `collections`, `media` | `FindProductsQuery`, `GetVariantById`, `ProductDTO` |
| Inventory | ledger stok, reservasi batch PO, GRN barang tiba, stok minimum | `stock_movements`, `stock_locations`, `reservations` | `ReserveStock`, `CommitStock`, `AvailableStockQuery` |
| Cart | cart multi-line, TTL, rule validation, gabungan RTS+PO | `carts`, `cart_items` | `CartAggregate`, `ValidateCartCommand` |
| Orders | agregat order, state machine, pemecahan sub-order/shipment, invoice, refund, cancel | `orders`, `order_items`, `shipments`, `order_events` | `PlaceOrder`, `TransitionOrder`, `OrderSummaryDTO` |
| PreOrder | periode PO, aturan deposit, rencana pelunasan, ETA, tutup batch, pelepasan batch | `po_batches`, `po_lines`, `po_payment_plans` | `OpenBatch`, `CloseBatch`, `DepositRulesQuery` |
| Quotes | pengajuan buyer, penawaran admin, masa berlaku, konversi order, enforcement visibility privat | `quote_requests`, `quotes`, `quote_items`, `quote_visibilities` | `SubmitQuoteRequest`, `IssueQuote`, `AcceptQuote` |
| Payments | intent/transaction/refund, webhook idempoten, rekonsiliasi, ledger | `payment_intents`, `payment_transactions`, `payment_ledger`, `webhook_events` | `PaymentProvider` (interface), `ChargeCommand`, `RefundCommand` |
| Shipping | tarif, label/paket, tracking | `parcels`, `shipping_rates_cache`, `tracking_events` | `ShippingRateProvider`, `TrackingProvider`, `CreateLabel` |
| Promotion | voucher, kampanye, poin & tier loyalitas | `vouchers`, `voucher_redemptions`, `loyalty_entries` | `ApplyPromotion`, `AwardPoints` |
| Notification | email, adapter WA, template, preferensi, subscription event | `notification_logs`, `user_preferences` | `NotifyEvent`, `NotificationChannel` |
| Content | blok homepage, banner, nav, halaman panduan, SEO meta | `content_blocks`, `pages`, `nav_items` | `PageBlocksQuery` |
| Search | index Meilisearch, sinonim fandom, facet | — (bukan source of truth) | `SearchProductsQuery`, `ReindexCommand` |
| Reporting | laporan penjualan, status PO, export | materialized views | `SalesReportQuery` |
| Audit | jejak aksi admin (siapa mengubah harga/status apa) | `audit_logs` | `AuditThis` |
| Support | tiket, dispute pesanan | `tickets`, `ticket_messages` | `OpenTicket` |

### 3.1 Delta arsitektur setelah observasi fitur kategori ini (bukti: `05-customer-feature-inventory.md`)

Enam hal yang berubah dari desain awal karena temuan di sisi customer. Semua tetap di dalam topologi Opsi A (modul baru, bukan service baru).

| # | Delta | Modul terdampak | Efek ke skema |
|---|---|---|---|
| D1 | **Katalog terikat batch.** Produk ditampilkan di bawah periode batch, dan PDP memperlihatkan jendela buka/tutup + ETA. Artinya "batch" naik jadi kelas satu yang menaungi penawaran, bukan hanya atribut order. | PreOrder, Catalog | `batch_offers` (batch_id × product_id × variant_id × kuota × harga snapshot × asal negara/toko) — satu varian bisa muncul di beberapa batch dengan harga & kuota berbeda |
| D2 | **Invoice sebagai entitas terpisah** (DP, pelunasan, biaya leg internasional, ongkir domestik, penyesuaian) dengan jatuh tempo sendiri → lihat **ADR-002** | Billing (baru, tetangga Payments), Orders, PreOrder | `invoices`, `invoice_id` di `payment_intents`, ledger berkey invoice |
| D3 | **Biaya internasional ditentukan kemudian.** Berat/pajak leg luar negeri baru pasti setelah barang bergerak, jadi nominalnya tidak bisa dikunci saat PO dibuka — dibuat otomatis saat GRN dengan batas atas per batch. | Billing, Inventory, Notification | kolom `estimated_min/max` di konfigurasi batch + job penerbit invoice |
| D4 | **Tracking per-lane, bukan satu mesin linear.** Empat jalur (Siap Kirim lokal, PO impor, Request, Procurement) punya tahapan sendiri; status uang terpisah dari status pemenuhan. | Shipping, Orders | `tracking_lanes`, `lane_stages`, `shipment_stage_events`; `orders` memecah `money_status` vs `fulfillment_status` |
| D5 | **Jalur procurement pihak ketiga** (beli atas nama buyer di marketplace lain) — bila dipakai klien. Ini menambah dokumen: kode klaim, bukti bayar, penautan ke invoice kita. Di balik feature flag; bisa dimatikan tanpa merusak flow lain. | Procurement (baru, opsional) | `procurements`, `procurement_codes`, `proof_files` |
| D6 | **Konten & konfigurasi lebih berat dari asumsi awal**: 9 halaman kebijakan ber-versi, i18n ID/EN sejak hari pertama, toggle fitur per provider (mis. login sosial) dipegang admin, media profil user (avatar+banner), drop satu-klaim-per-akun, dan ulasan yang termoderasi + terkunci pada order selesai. | Content, Identity, Promotion, UGC (baru), Settings (baru) | `content_block_versions` (+ tanggal berlaku), `localizations`, `feature_flags`, `reviews` + `review_moderation`, `drop_claims` unik (drop_id, account_id) |
| D7 | **Snapshot alamat + masa berlaku harga.** Alamat order dibekukan saat checkout (edit profil tidak mengubah riwayat), dan harga/kuota punya masa berlaku karena kurs & biaya impor bisa berubah sebelum pembelian dikonfirmasi. | Orders, Cart, Billing | `order_addresses` (data tersalin, bukan FK live), `price_lock_expires_at`, ambang re-konsen pada invoice yang terbit belakangan (RS-4 di Doc 02) |
| D8 | **Admin satu-aplikasi dengan storefront, dan tidak ada API JSON publik.** Hasil REA struktural (Doc 08 §3–§4): panel admin situs sumber memakai layout + komponen tabel generik yang sama dengan halaman customer (satu aplikasi, satu origin), dan seluruh data katalog/tagihan mengalir lewat Server Components — satu-satunya endpoint klien adalah asisten AI. Ini mengonfirmasi Opsi A dan menutup pertanyaan "perlu SPA admin terpisah?". | Frontend, Identity, Reporting | satu SPA Inertia dengan route group `admin` berjaga middleware server-side (§8); tidak ada kontrak API sumber yang perlu dipetakan — kontrak publik §5 tetap satu-satunya sumber kebenaran |

Konsekuensi biaya: **+11 hari kerja** di jalur kritis (rincian di Doc 03 §10.5 → jadwal menjadi ±18 minggu), ditambah 0,5 hari untuk baseline header keamanan dari Doc 08 §6 (task 0.10, Sprint 0). Angka lama "+4–6 hari terserap buffer" di baris ini sudah usang dan diganti. Yang **tidak** berubah: topologi, jumlah proses container, dan fakta bahwa boundary B1–B6 tetap berlaku — modul Billing/Procurement/UGC/Settings ikut aturan yang sama.

## 4. Katalog domain event

| Event | Dipancarkan oleh | Konsumen | Efek |
|---|---|---|---|
| `OrderPlaced` | Orders | Inventory, Notification, Reporting | reservasi stok, email invoice, metrik |
| `PaymentCaptured` | Payments | Orders, PreOrder, Notification, Promotion | transisi `paid`, aktifkan plan pelunasan, award poin |
| `DepositPaid` | PreOrder | Orders, Notification | konfirmasi PO aktif + tanggal pelunasan |
| `BalanceInvoiced` | PreOrder | Notification | tagihan pelunasan (H-7, H-1, jatuh tempo) |
| `PoBatchClosed` | PreOrder | Inventory, Reporting | PO tidak menerima order baru |
| `PoBatchArrived` | PreOrder | Inventory, Orders, Notification | GRN stok, sub-order jadi `ready_to_ship`, WA "barang tiba" |
| `ParcelShipped` | Shipping | Orders, Notification | transisi `shipped`, kirim resi |
| `TrackingUpdated` | Shipping | Notification | update timeline + alert exception kurir |
| `QuoteIssued` | Quotes | Notification | notifikasi "penawaran Anda siap" |
| `QuoteAccepted` | Quotes | Orders, Cart | convert ke order (RTS atau PO sesuai batch) |
| `QuoteExpired` | Quotes | Notification | takedown visibility |
| `RefundApproved` | Payments | Orders, Inventory | stok kembali bila belum kirim |
| `StockLow` | Inventory | Notification, admin alert | restock alert |

## 5. Model uang (dipakai RTS, PO, dan Quote)

```
payment_intents   1 intent = 1 tagihan (total order, ATAU deposit, ATAU pelunasan)
  ├─ provider            midtrans|xendit|tripay|manual
  ├─ amount, currency    integer minor-unit IDR (satuan terbawah = rupiah utuh, hindari float)
  ├─ status              pending|requires_action|authorized|captured|void|expired|failed
  └─ expires_at
payment_transactions   1 intent → many percobaan/callback (auth, capture, cancel, refund partial)
payment_ledger         entri append-only: debit/kredit per intent → dipakai rekonsiliasi & audit
webhook_events         payload mentah + signature valid + unique (provider, event_id)
```

Aturan:
- **U1** Semua jumlah disimpan sebagai integer rupiah; persentase/fee dihitung dengan `bcdec` lalu dibulatkan satu arah (half-up) dan selisihnya tercatat di ledger, tidak hilang.
- **U2** RTS: satu intent lunas, kedaluwarsa 2 jam.
- **U3** PO: intent deposit (persentase atau flat dari batch) → setelah `captured`, order aktif; intent pelunasan dibuat dengan jatuh tempo dari batch. Belum lunas sampai `due_date` → `on_hold` → `expired`. Default kebijakan saat `expired` = deposit hangus (lihat B-PO-3 di Doc 02), didahului reminder H-7/H-1 dan bisa diperpanjang sekali oleh admin dengan alasan yang tercatat di audit. Nilai ini konfigurasi per batch, bukan hardcode.
- **U4** Cart campuran: satu pembayaran gabungan dengan `fulfillment_split` yang menghasilkan beberapa shipment; order tetap satu, shipment per jalur. Alasannya: menggabungkan dua tagihan ke customer lebih buruk daripada memecah paket.
- **U5** Rekonsiliasi harian: bandingkan `payment_ledger` vs settlement report provider → selisih masuk daftar kerja admin; tidak ada auto-adjust.

> **Amandemen:** U1–U5 di atas ditulis sebelum observasi fitur kategori ini. **ADR-002** menggantikannya dengan model `orders → invoices → payment_intents` (satu order dapat memancarkan beberapa invoice bertipe `deposit | balance | intl_fees | domestic_ship | extra_ship | adjustment`, masing-masing dengan jatuh tempo dan reminder sendiri). Aturan U1 (integer rupiah), U4 (pembayaran gabungan dengan alokasi per invoice) dan U5 (rekonsiliasi) tetap berlaku; U2/U3 berubah seperti di ADR.

## 6. State machine order

Model status dipecah dua sumbu (delta D4 di §3.1) supaya tidak meledak jadi status kombinasi:
- `money_status`: `unpaid | partially_paid | paid | overdue | refund_pending | refunded`
- `fulfillment_status`: per **lane** (`rts | import_po | request | procurement`), tiap lane punya daftar tahapnya sendiri yang dikelola admin (`tracking_lanes`, `lane_stages`) — lane lokal tidak melewati tahap leg luar negeri.
- Label yang dilihat customer (mis. pada daftar pesanan) adalah turunan dari kedua sumbu + lane, bukan kolom ketiga yang bisa ditulis bebas.

Diagram di bawah menggambarkan lane impor (PO); lane RTS memotong bagian `awaiting_batch`, lane procurement menambah tahap pembelian pihak ketiga sebelum `ready_to_ship`.

```
                 ┌──────── expired (intent kedaluwarsa)
pending_payment ─┤
                 └─▶ paid ─▶ awaiting_batch(PO) ─▶ ready_to_ship ─▶ shipped ─▶ delivered ─▶ completed
                    │            │  (PoBatchArrived)      ▲                        
                    │            └──── on_hold ◀── BalanceOverdue          
                    ├───── cancelled (before ship)  
                    └───── refund_requested ⇄ refunded / on_hold (dispute)
```

- Transisi didefinisikan deklaratif (config per status + guard + action), setiap transisi menulis `order_events` (actor, from, to, reason) dan men-queue notifikasi via outbox.
- `awaiting_batch` hanya untuk sub-order PO; sub-order RTS langsung `ready_to_ship`.
- Tidak ada transisi yang menghapus jejak; koreksi = transisi balik dengan reason.

## 7. Flow kunci (sequence)

### 7.1 Checkout PO dengan deposit
```
Customer → Cart: tambah item PO (varian + qty)
       → Checkout: alamat, kurir (ShippingRateProvider), metode bayar (PaymentProvider.listMethods)
Storefront → Orders.PlaceOrder (1 DB transaction):
              validasi cart → hitung promo → snapshot harga varian
              → Inventory.ReserveStock(mode=po)
              → Orders buat order + sub-order PO + shipment
              → PreOrderDepositRulesQuery(batch) → buat intent deposit
              → tulis outbox OrderPlaced
       → Payments.CreateCharge(driver) → menghasilkan payment_url / string QRIS
Customer bayar → Gateway → webhook → Payments: verify signature → simpan webhook_events
              → idempoten guard → PaymentCaptured → Orders.Transition(paid)
              → PreOrder buat intent pelunasan due_date → Notification (email+WA)
... PoBatchClosed → PoBatchArrived (GRN) → Inventory.CommitStock → sub-order ready_to_ship
              → Notification "barang tiba, siap dikirim"
Warehouse → Shipping.CreateLabel → ParcelShipped → TrackingUpdated → delivered → completed
```

### 7.2 Request Order (kuotasi privat)
```
Customer → Quotes.SubmitQuoteRequest(item, ref foto/link, target price)
Admin    → daftar pengajuan → IssueQuote(harga per item, qty tersedia, berlaku s/d, mode RTS/PO)
           → baris quote mengunci stok (Inventory.ReserveStock(mode=quote))
Visibility: hanya user_id pemilik + role staff yang bisa GET; middleware Policy + scope query
           (tidak ada di catalog/search/sitemap, URL tidak bisa ditebak karena pakai ULID)
Customer → AcceptQuote → Quotes → Orders.PlaceOrder(from_quote) → bayar intent
         → DeclineQuote / QuoteExpired → release reservation
```

## 8. Keamanan (ringkas, detail di dokumen implementasi)

- Session Inertia: cookie `Secure/HttpOnly/SameSite=Lax`, CSRF aktif, OTP/limit login per IP.
- Webhook: `X-Signature` diverifikasi dengan secret per driver; raw payload diarsipkan; endpoint publik hanya menerima POST dari allowlist + retry-safe (idempoten).
- Otorisasi: Policy per resource; endpoint admin di balik role + 2FA; `AuditThis` di setiap mutasi admin.
- Data pribadi: enkripsi-at-rest untuk alamat/no HP di export, retensi & hak hapus, tidak menaruh PII di log.
- Input: validasi form-request + escaping; tidak pernah render HTML dari konten user (review/foto request order) tanpa sanitizer; upload gambar dibatasi tipe/ukuran + diproses ulang (re-encode) untuk buang payload.
- Permintaan harga/kuota: rate limit, honeypot, dan tidak mengizinkan enumerasi produk privat.
- Secrets hanya env (bukan file ter-commit), rotasi berkala, tidak ada credential customer pihak ketiga di repo/manapun.
- **Baseline header di edge** (dipasang sejak Sprint 0, diverifikasi test, bukan nanti-nanti): HSTS `max-age=63072000; includeSubDomains; preload`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=(self)`, dan CSP `frame-ancestors 'none'; base-uri 'self'; object-src 'none'; form-action 'self'; script-src 'self'`. Catatan: situs sumber memakai set yang sama **tanpa `script-src`** — itu celah yang tidak kita tiru (Doc 08 §6).
- **Proteksi rute admin server-side.** `/admin/*` wajib dijaga middleware role + Policy sebelum render (Inertia props tidak boleh terisi untuk tamu), bukan disembunyikan dari menu saja. Situs sumber mengembalikan shell HTTP 200 untuk seluruh rute admin/dashboard dan baru redirect di klien (Doc 08 §3) — pola itu sengaja kita tinggalkan.

## 9. Jalur ekstraksi ke topologi B (microservice-lite)

Pemicu (salah satu sudah terjadi → mulai pecah):
1. ≥3 dev aktif dengan konflik deploy;
2. order >50/jam selama 2 minggu berturut-turut;
3. brand/tenant kedua;
4. kebutuhan multi-vendor/payout;
5. satu modul butuh scaling atau bahasa lain (mis. search/rekomendasi).

Urutan pecah (dari yang paling murah risikonya): `Search` → `Notification` → `Payments` → `Shipping` → `Quotes` → `Orders` (terakhir, karena paling banyak transaksi).

Langkah per service: (a) tunjukkan modul itu hanya memakai kontraknya sendiri — hapus akses lintas modul yang tersisa; (b) pindahkan tabel ke skema/database sendiri; (c) ganti dispatch event in-proc dengan publisher `outbox` → Redis Stream; (d) expose Command sebagai HTTP + mTLS/token, storefront memanggil via client generated; (e) jalankan dua mode (mono + service) paralel dengan flag read-only shadow selama 1 sprint; (f) cut-over + rollback dengan replay outbox. Catatan: setelah `Orders` jadi service terpisah, flow deposit/pelunasan wajib jadi saga dengan compensating refund — itu konsekuensi yang harus diterima, bukan bug implementasi.

## 10. Docker & infra

### 10.1 File layout
```
docker/
  compose.yaml            # base + profiles
  compose.dev.yaml        # xdebug, mailpit, adminer, bind mount
  compose.prod.yaml       # restart policy, limits, no dev tools
  nginx/default.conf
  php/Dockerfile          # multi-stage: vendor → build(node) → runtime
  php/php.ini, www.conf
  horizon/horizon.conf    # queue mapping per profil
deploy/
  Makefile                # up, down, migrate, seed-demo, test, logs, deploy, rollback
  ci/github-actions.yml
  runbook/backup-restore.md, deploy.md, incident.md
```

### 10.2 Dockerfile (kerangka, multi-stage)
```
FROM composer:2 AS vendor      # composer install --no-dev --optimize-autoloader
FROM node:22 AS frontend       # npm ci && npm run build (Vite → public/build)
FROM php:8.4-fpm AS runtime    # ext: pdo_pgsql redis intl gd bcmath zip opcache
  copy vendor + app + public/build ; user non-root ; healthcheck artisan tinker-ish /-fpm-ping
```
Image tidak pernah berisi `.env`, credential, atau data. Tag `elzara/app:${GIT_SHA}` + `:latest`, registry privat; rollback = tag sebelumnya.

### 10.3 Service & profil
```
services:
  app            # php-fpm (2 replicas prod via scale, session di redis)
  nginx          # edge prod (atau traefik + letsencrypt)
  worker         # horizon: default, payments, notifications, search, reconcile
  scheduler      # cron: jatuh tempo pelunasan, tutup batch, expire intent, reindex, rekonsiliasi
  postgres:16    # volume pgdata
  redis:7        # appendonly yes, volume redisdata
  meilisearch:1  # volume dumps
  minio          # bucket: media-public, media-private, backups
profiles:
  dev: [mailpit, adminer, xdebug on app]
  prod: []       # dev tools absen
```
Wajib ada: `healthcheck` tiap service, `depends_on: condition: service_healthy`, `restart: unless-stopped` di prod, `ulimits` + `deploy.resources.limits` untuk `worker` (Horizon paling gampang menghabiskan RAM VPS), network terpisah `frontend`/`backend`, port postgres/redis/meilisearch/minio **tidak** dipublikasikan ke host (admin akses lewat SSH tunnel), backup volume lewat job `scheduler` ke bucket `backups` terenkripsi.

### 10.4 Env var (minimal)
`APP_ENV/KEY/CURL/CIPHER`, `APP_URL`, `ASSET_URL`, `DB_*`, `REDIS_*` + `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`, `CACHE_STORE=redis`, `QUEUE_SECRET`, `MEILISEARCH_*`, `FILESYSTEM_DISK=s3` + `AWS_*` (endpoint MinIO), `MAIL_*`, `PAYMENT_DRIVER`, `MIDTRANS_SERVER_KEY/CLIENT_KEY`, `XENDIT_*`, `TRIPAY_*`, `SHIPPING_DRIVER`, `BITESHIP_KEY`/`RAJAONGKIR_KEY`, `WA_GATEWAY_*`, `BACKUP_*`. Kontrak: tidak ada var yang wajib tapi tidak terdokumentasi — `compose up` gagal dengan pesan menyebut nama var yang hilang.

### 10.5 Deploy
`main` → CI (lint, static analyse, test, build image, push) → SSH `docker compose -f compose.yaml -f compose.prod.yaml pull --build && migrate --force && up -d` dengan urutan: maintenance mode → drain Horizon → migrate (backward-compatible saja, expand-migrate-contract) → up → smoke check → maintenance off. Rollback: tag image lama + `up -d`; migration hanya boleh non-destruktif dalam 1 rilis.

## 11. Observability, error handling, testing

**Error taxonomy:** domain exception (`InsufficientStock`, `PoPeriodClosed`, `QuoteExpired`, `DepositAlreadyPaid`, `TrackingUnavailable`), `ApiProblem{code,status,detail}` untuk frontend, kegagalan eksternal → retry exponential backoff (max 5) → dead-letter + alert. Tidak ada auto-approve pembayaran; state tidak pasti → antrean rekonsiliasi admin.

**Logging/metric:** JSON terstruktur (request-id, user-id hash, order-id, module, event), level per channel; Sentry-klas untuk exception; dashboard minimum: webhook lag, queue depth, rate gagal bayar per provider, intent expired vs captured, order stuck >24h di `paid`, jumlah PO menunggu pelunasan, latensi P95 checkout, error rate search.

**Testing:**
| Lapisan | Sasaran | Contoh wajib |
|---|---|---|
| Unit | domain, money math, state machine, policy | property test pembulatan deposit 30 %/flat/persentase + voucher bertingkat; semua transisi valid/invalid |
| Feature | endpoint Inertia + authz | guest vs owner vs staff pada halaman quote privat; replay webhook 3× = 1 transisi |
| Contract driver | `PaymentProvider`, `ShippingProvider` | suite yang sama harus lolos untuk Midtrans & Xendit & manual; jadi gerbang ganti driver tanpa QA ulang |
| Integration | outbox/queue/jobs | `PoBatchArrived` menghasilkan GRN + shipment + notifikasi, idempoten |
| E2E smoke | 3 alur (§7.1, RTS+tracking, quote→accept) | Playwright terhadap staging dengan data seeder orisinal |
| Load | checkout & webhook burst | 100 order/menit, 3× replay webhook, tidak ada double-capture |

Gate kualitas per PR: lint + static analyse + unit/feature hijau; modul uang/order menambah test untuk setiap transisi atau bug yang diperbaiki. Data demo = seeder orisinal (nama produk/gambar milik sendiri), tidak pernah data atau konten pihak ketiga.

## 12. Risiko & mitigasi utama

| Risiko | Mitigasi |
|---|---|
| Deposit/pelunasan tidak cocok dengan settlement gateway | ledger + rekonsiliasi harian + laporan selisih sejak Sprint 2 |
| Barang PO tidak tiba / supplier gagal | batch punya status `cancelled_by_supplier`, refund massal 1 aksi, template komunikasi |
| Stok reservasi "lupa" (cart mati, quote basi) | job expire reservation + constraint TTL, metrik reservation tanpa owner |
| Kurir lokal tidak punya API andal | driver `Manual` adalah warga kelas satu (bukan afterthought) di `ShippingProvider` |
| Tim 2 dev ikut godaan microservice | §9: ekstraksi butuh persetujuan + trigger terukur, bukan inisiatif sprint |
| Klien minta "sama persis" dengan situs referensi | batas §13, frontend pakai token/aset orisinal, konten dari klien |
| Origin mati saat puncak (situs sumber ketahuan mengembalikan Cloudflare 522 pada satu navigasi — Doc 08 §1) | VPS sendiri dengan margin kapasitas, health check + status page, jangan tiru pola cache-busting `?dpl=` yang membatalkan seluruh cache CDN tiap deploy |

## 13. Batas kekayaan intelektual (mengikat proyek)

Yang kita tiru adalah **kategori fitur dan alur bisnis**, bukan ekspresi. Dilarang: mengambil/duplikasi teks marketing, foto produk, ilustrasi, ikon, logo/trade name, font berlisensi, CSS/kode, maupun screenshot sebagai aset. Storefront memakai tema and komponen orisinal (§04). Data pribadi, akun, dan isi halaman privat milik customer pihak lain tidak diakses, tidak dikumpulkan, tidak dipakai sebagai bahan desain. Klien menyediakan daftar aset sendiri: nama brand, logo, foto produk, deskripsi, kebijakan, dan legal.

## 14. Asumsi yang belum dikonfirmasi

Katalog <10.000 SKU & <50 order/jam tahun pertama · trafik 1 gateway aktif di MVP (driver kedua menyusul) · tanpa PCI (kartu/QRIS ditangani gateway) · bahasa ID + IDR saja, multi-bahasa pasca-launch · gudang fisik tunggal · tim 1–2 dev + klien sebagai penyedia konten/persetujuan · VPS tunggal 4 vCPU/8 GB sebagai target infra.

## 15. Dokumen terkait

`00-overview.md` (asumsi & batas), `02-business-flow-customer-features.md`, `03-sprint-plan.md`, `04-frontend-theme-guide.md`, `09-database-schema.md` (skema fisik dari semua tabel di §3 dan §5), `10-migration-plan.md` (urutan pembuatan, aturan anti-tabrakan, zero-downtime), `adr/ADR-001-topologi.md`, `adr/ADR-002-invoice-model.md`.
