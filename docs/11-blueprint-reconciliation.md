# Dokumen 11 — Rekonsiliasi Blueprint terhadap dokumen 00–10

Tanggal: 2026-11-17 · Status: mengikat sebagai jembatan rujukan.
**Rujukan induk:** `SNOWEGO_MICROSERVICES_BLUEPRINT.md` v1.0 (17-11-2026) — disebut **Blueprint**.
Aturan main (ADR-003 K-1): Blueprint menang atas dokumen 00–10, kecuali **bukti** (K-4) dan daftar **K-5**. Dokumen lain tidak boleh mengulang perdebatan di sini; mereka cukup menunjuk butir `B-n` atau `R-n` di bawah.

Cara baca kolom Bentrok: `—` tidak ada konflik · `⚠` konflik keputusan (perubahan nyata) · `✚` Blueprint menambah cakupan yang sebelumnya tidak ada · `?` butuh jawaban klien.

---

## 1. Bentrok besar yang mengubah arsitektur

| # | Blueprint | Dokumen lama bilang | Resolusi | Bentrok |
|---|---|---|---|---|
| B-1 | §2.1 tiga deployment mandiri: `commerce-api` (Laravel) + `commerce-admin` (Next.js) + `commerce-web` (Next.js); §3.1 monorepo `apps/`+`packages/`+`infrastructure/` | Doc 01 §3/§2 + ADR-001 + D-S0 plan: **satu app Inertia** untuk storefront dan admin | ADR-003 K-2: satu API Laravel **plus dua app Next.js** di satu monorepo. Admin bukan lagi route group `admin/` di app yang sama; ia app sendiri dengan BFF sendiri. | ⚠ |
| B-2 | §7 "OpenAPI 3.1 sebagai source of truth + generated TypeScript client" | Doc 01 §5/D8: tidak ada kontrak API publik yang perlu dipetakan; mutations via Command internal | Doc 01 §5 ditulis ulang: `/api/v1` jadi kontrak resmi; Command (B2) tetap satu-satunya pintu mutasi di sisi server, controller API jadi bungkus tipis. `packages/api-contracts` wajib, dan client TS hasil generate dipakai kedua app. | ⚠ |
| B-3 | §5.4 `orders.order_type = ready_stock｜preorder｜request_order` + **empat sumbu**: `order_status`, `payment_status`, `procurement_status`, `fulfillment_status` | Doc 01 §6 + Doc 09: **dua sumbu** (`money_status` × `fulfillment_status` per lane) | Empat sumbu adopted. `money_status` kita = `payment_status` Blueprint (nama dihapus); `procurement_status` baru; lane `rts｜import_po｜request｜procurement` tetap sebagai pengisi tahap `fulfillment_status`, bukan sebagai `order_type`. | ⚠ |
| B-4 | §2.2 + §4: domain **Sourcing** (product requests, quotations) dan **Procurement** (supplier PO, goods receipt) sebagai dua hal berbeda | Doc 09 §13 memakai `Procurement` untuk "jalur beli atas nama buyer di marketplace lain" (D5) — **tabrakan nama beda makna** | Modul D5 kita ganti nama menjadi **`MarketplacePurchase`** (tabel `marketplace_purchases`, `claim_codes`, `proof_files`), dan nama `Procurement` dikembalikan ke makna Blueprint (supplier: `suppliers`, `purchase_orders`, `purchase_order_items`, `supplier_invoices`). `goods_receipts` pindah dari Inventory ke Procurement. | ⚠ |
| B-5 | §6.1 model stok: `on_hand`, `reserved`, `damaged`, `in_transit`, `available_to_sell = on_hand − reserved − unavailable`; satu baris agregat per `(warehouse_id, sku_id)` + ledger immutable | Doc 09 `stock_movements` + `reservations` saja; tidak ada baris agregat, tidak ada `damaged`/`in_transit`, tidak ada `unavailable` | Tabel **`stock_items`** ditambahkan sebagai pemegang kebenaran reservasi atomic; ledger tetap `stock_movements` (S-6); `unavailable` didefinisikan = `damaged + quarantined` dan dijaga CHECK non-negatif. | ⚠ |
| B-6 | §8.1–8.5 atomic conditional UPDATE + order lock SKU + retry deadlock + batch quota UPDATE + `paid_stock_exception` | Doc 02/03 menyebut "reservasi dengan TTL"; Doc 09 CHECK tersedia ≥ qty; tidak ada urutan lock, tidak ada resolusi late-payment | Menjadi spesifikasi, bukan catatan: Doc 01 §5.1 baru + Doc 02 §4. Kolom `stock_reservations.expires_at` dan `orders.paid_stock_exception` (status) masuk skema. Deadlock retry dengan backoff + batas retry di level Command. | ✚ |
| B-7 | §6 + §8.3 `idempotency_keys` dengan scope `(actor, operation, key)` + request hash + 409 saat key sama payload beda; §6 Reliability `inbox_events`, `webhook_receipts` | Doc 09 hanya `webhook_events` unik `(provider, provider_event_id)` | Tiga tabel keandalan baru. `webhook_events` lama jadi `webhook_receipts` (payload mentah + verifikasi), `inbox_events` untuk konsumen event, `idempotency_keys` untuk mutasi klien. `Idempotency-Key` header wajib untuk order/charge/refund. | ✚ |
| B-8 | §10 Cloudflare Waiting Room + admission policy + larangan memakainya sebagai jaminan fair-stock; §16 test "Waiting Room under load" dan "Cloudflare bypass" | Tidak ada di Doc 01 §2/§10 maupun Doc 03 | Topologi Doc 01 §2 ditambah lapisan admission; DoD Sprint 7 mendapat dua test baru; runbook `cloudflare-waiting-room.md`. Kapasitas ditentukan load test, bukan angka. | ✚ |
| B-9 | §11 enam bucket MinIO + presigned PUT + finalize + validasi MIME/magic-byte + scan + signed URL privat + CORS per origin | Doc 01 §10 menyebut MinIO + bucket `media-public/media-private`; Doc 02 §3 menyebut upload lampiran | Bucket list diadopsi penuh; alur presigned + finalize jadi spesifikasi fitur upload (Doc 02 §3.5 dan Sprint 5), dengan catatan eksplisit Blueprint: presigned URL harus memakai endpoint yang bisa dijangkau browser, bukan hostname internal Docker. | ✚ |
| B-10 | §4 RBAC 10 role (superadmin, catalog-manager, sales-ops, preorder-ops, sourcing-ops, warehouse-staff, procurement-staff, finance, customer-support, analyst read-only) | Doc 06 §1 + Doc 10 §8: 5 role (Admin, Ops, Warehouse, Finance, Support) | Sepuluh role adopted; `role_user` tetap, permission per `modul.aksi` tetap; pemetaan lama→baru di Doc 06 §1. "Ops" dipecah menjadi sales-ops/preorder-ops/sourcing-ops. | ⚠ |
| B-11 | §16 tools: Pest **atau** PHPUnit, Playwright, k6/Locust, Testcontainers, chaos injection, OWASP ZAP; "SQLite tidak cukup" | D-S0-3 memilih PHPUnit; Doc 03 DoD tidak menyebut load test otomatis; Doc 01 §11 menyebut "Load" tapi tanpa alat | PHPUnit tetap sah (Blueprint mengizinkan). Ditambah: suite concurrency nyata di PostgreSQL via Testcontainers/service CI, k6 untuk dua angka验收 §16 (1000 paralel/500 kuota), Playwright untuk 3 alur. D-S0-3 diperluas, tidak dibatalkan. | ✚ |
| B-12 | §1 "SLO ketersediaan 99.9% setelah sistem matang", metrik p95, queue lag, oversell anomaly; §15 runbook 11 skenario | Doc 01 §11 dashboard minimum (mirip, 8 metrik) tanpa SLO tertulis; runbook hanya backup-restore/deploy/incident | SLO dicantumkan sebagai target pasca-stabilisasi (bukan gerbang launch); runbook 11 skenario jadi deliverable Sprint 7+ dan daftar `docs/runbooks/`. | ✚ |
| B-13 | §6 Money: integer minor units **atau** `NUMERIC(20,4)` dengan pembulatan eksplisit; simpan `currency_code`, `exchange_rate_snapshot`, `tax_rule_snapshot`, `price_snapshot` | S-1 integer rupiah; S-8 snapshot kolom per baris; kurs sebagai settings | Integer dipertahankan (K-5). Empat kolom snapshot **ditambah eksplisit** — kita hanya punya sebagian. `NUMERIC(20,4)` ditolak: dua representasi uang dalam satu basis data adalah sumber bug pembulatan. | — |
| B-14 | §3.1 `docs/threat-model.md` wajib ada di repo | Doc 01 §8 berupa daftar kontrol, tanpa model ancaman | Dokumen baru `docs/threat-model.md` (turunan), dipakai untuk menjawab §7 "OAuth2/OIDC atau session/BFF sesuai threat model". | ✚ |
| B-15 | §14.1 peringatan: Docker Compose single-node = SPOF; "jangan klaim HA tanpa multi-node, replikasi, failover, recovery teruji" | Doc 01 §10 + Doc 03 §8 merencanakan VPS + Docker Compose | Tidak ada klaim HA di dokumen mana pun. Ditambah kalimat kapasitas/risiko di Doc 01 §12: target kami satu node dengan backup teruji + restore drill (Sprint 0) dan pemulihan manual, bukan 99.9% otomatis. | ⚠ |

---

## 2. Yang Blueprint **tidak** ubah (dan tetap jadi aturan kita)

B1–B6 (boundary, Command-only, event bawa ID, outbox, uang via aggregate, namespace modul per B6) — Blueprint §2.2/§9 setuju dan bahkan lebih ketat soal ownership.
S-1…S-10 skema (kecuali penyesuaian B-13) dan M-1…M-13 migrasi — Blueprint §14.3 justru meminta expand/contract dan migration compatibility checks; tidak ada konflik.
ADR-002 model invoice → **bertambah, tidak diganti**: Blueprint §6 mengenal `invoices` + `invoice_lines` + `receivables`; tipe tagihan `deposit｜balance｜intl_fees｜domestic_ship｜extra_ship｜adjustment` kita tetap, dan `intl_fees` sekarang punya pasangan entitas (customs/landed cost, lihat R-14).
Batas kekayaan intelektual (Doc 01 §13, Doc 00 §Batas): Blueprint sendiri memasang disclaimer di baris 7 dan §1 bukan sumber aset. Seeder tetap orisinal.
Metode observasi hanya-baca Doc 05/08 dan seluruh **fakta** Doc 08 (K-4): situs sumber adalah App Router dengan satu endpoint klien; Blueprint §3 menggambarkan sesuatu yang berbeda. Kami mengadopsi Blueprint sebagai **resep**, bukan melaporkan dia sebagai kenyataan.

---

## 3. Peta ADR Blueprint §18 → ADR kita

Blueprint bernomor ADR 001–010 dengan makna berbeda dari ADR kita. Aturan K-3 berlaku; ini petanya supaya tidak ambigu:

| Blueprint §18 | Status | ADR/tulisan kita yang menaungi |
|---|---|---|
| 1 modular-first vs microservices | sudah diputus | ADR-001 (tetap) + ADR-003 K-2 (UI dipecah) |
| 2 Postgres source of truth, Redis bukan ledger | sudah diputus | Doc 09 S-1/S-5/S-6, Doc 01 §5 |
| 3 idempotency + unique + transactional outbox | **baru → tulis ADR-004** | B-7; `idempotency_keys`/`inbox_events` |
| 4 reservation TTL + late-payment resolution | **baru → ADR-005** | B-6 |
| 5 gateway & settlement/reconciliation semantics | sebagian (ADR-002, Doc 01 U5) | **ADR-006** saat driver dipilih; blocker klien (Doc 03 §9) |
| 6 BFF Next vs browser→API langsung | **baru → ADR-007** | B-1/B-2; wajib sebelum Sprint 1 |
| 7 MinIO bucket privat/publik + CDN | **baru → ADR-008** | B-9 |
| 8 Waiting Room coverage/threshold/bypass | **baru → ADR-009** | B-8; butuh angka load test |
| 9 monorepo CI/CD + independent deploys + strategi migrasi | sebagian (Doc 10) | **ADR-010** — Doc 10 §1 M-1…M-4 jadi lampirannya |
| 10 inventory allocation (partial fulfillment / multi-warehouse) | **baru → ADR-011** | B-5; default: single warehouse MVP |

Kesimpulan yang harus disampaikan: **delapan ADR baru (004–011)** adalah pekerjaan dokumentasi yang diminta Blueprint §18, dan empat di antaranya (005, 006, 008, 009) tidak bisa ditutup sendiri oleh dev — butuh keputusan/angka dari klien atau hasil tes.

---

## 4. Skema: nama diadopsi, tabel ditambahkan

Aturan turunan (Doc 09 §1 baru): **nama mengikuti Blueprint ketika perbedaan hanya prefik/jamak; makna mengikuti Blueprint; detail kita dipertahankan di tempat Blueprint hanya menyebut "minimum".**

### 4.1 Ganti nama (12)

| Doc 09 lama | Jadi | Alasan Blueprint |
|---|---|---|
| `user_addresses` | `addresses` | §6 Identity |
| `variants` | `product_variants` | §6 Catalog |
| `product_options`/`_values`/`variant_option_values` | tetap | §6 tidak menyebut; kita butuh untuk varian 2 sumbu (Doc 03 §10.5 Sprint 1) |
| `po_batches` | `preorder_batches` | §6 Pre-Order |
| `batch_offers` | `preorder_batch_items` | §6 + §8.5 (kuota per item batch) |
| `po_lines` | `preorder_enrollments` | §6 |
| `quote_requests` | `product_requests` | §6 Request |
| `quotes` | `quotations` | §6 |
| `quote_items` | `quotation_items` | §6 |
| `stock_locations` | `warehouses` | §6 Inventory |
| `reservations` | `stock_reservations` | §6 + §8.1 |
| `grns`/`grn_items` | `goods_receipts`/`goods_receipt_items` | §6 Procurement |
| `parcels` | `packages` | §6 Logistics |
| `order_events` | tetap (+ diproyeksikan sebagai `order_status_history` view) | §6 Sales; riwayat = turunan, S-5 |
| `procurements`/`procurement_codes`/`proof_files` (D5) | `marketplace_purchases`/`claim_codes`/`proof_files`, modul `MarketplacePurchase` | B-4 (tabrakan nama) |

### 4.2 Tabel baru dari Blueprint (25)

`customer_profiles`*, `checkout_sessions`, `price_lists`, `price_list_entries`, `product_media`, `brands`, `order_charges`, `order_adjustments`, `preorder_allocations`, `request_attachments`*, `sourcing_options`, `quotation_versions`, `stock_items`, `stock_transfers`, `stocktakes`, `suppliers`, `purchase_orders`, `purchase_order_items`, `supplier_invoices`, `invoice_lines`, `payments`, `receivables`, `forwarders`, `consolidations`, `cargo_shipments`, `cargo_items`, `customs_charges`, `landed_cost_allocations`, `idempotency_keys`, `inbox_events`, `webhook_receipts`.

`*` = sudah ada padanannya di skema kita sebagai mekanisme polimorfik/append-only (`media` per S-3 melayani lampiran; `order_events` melayani riwayat) — **tidak dibuat ulang**; Doc 09 §20 mencatat alasan deviasi, karena membuat tabel kedua untuk fakta yang sama melanggar B1.

Total setelah rekonsiliasi: **85 + ±21 tabel baru = ±106** (angka final dihitung Doc 09 saat ditulis ulang, bukan di sini).

### 4.3 Yang tidak diambil dari Blueprint §6, dengan alasan tertulis

`payables`, `expenses`, cash/bank (hanya muncul di menu admin §4 Billing & Finance, bukan di §6): buku besar keuangan ganda di luar kebutuhan platform commerce kita; rekomendasi = export ke akuntansi klien, bukan tabel baru. **Butuh keputusan klien** (§6 pertanyaan Q-3).

---

## 5. Jadwal: konsekuensi jujur

Blueprint §17 memberi rentang sprint, bukan minggu: Phase 0 = 1–2, Phase 1 = 3–5, Phase 2 = 2–4, Phase 3 = 2–3, Phase 4 = berkelanjutan. Jumlah minimum untuk Phase 0–3 = **8 sprint**, maksimum **14 sprint**. Plan lama (Doc 03) menjanjikan Sprint 0–7 + 1 = 8 sprint ≈ ±18 minggu, dan itu sekarang berdiri di atas cakupan yang lebih kecil daripada Blueprint:

Pekerjaan baru yang tidak ada di 18 minggu itu: app Next.js kedua (admin) + BFF + generated client (B-1/B-2), `packages/api-contracts` + OpenAPI sebagai gerbang PR (B-2), model stok agregat + transfer + stocktake (B-5), concurrency & load test nyata dengan Testcontainers/k6 (B-11), empat sumbu status + state machine test per transisi (B-3), landed cost/customs/forwarder (R-14), Waiting Room + bypass test (B-8), threat model + 11 runbook (B-14/B-12).

Estimasi saya setelah perubahan ini: **±22–26 minggu** (11–13 sprint) untuk cakupan Blueprint penuh dengan tim 1–2 dev, bukan 18. Angka 18 minggu hanya bertahan kalau salah satu dari ini disetujui:

| Opsi | Isi | Yang hilang |
|---|---|---|
| **J-a** | Terima 22–26 minggu, semua Blueprint masuk | — |
| **J-b** | Tahan 18 minggu dengan memotong **Phase 4** (Waiting Room, multi-warehouse, landed cost otomatis, ekstraksi service) dan **admin Next.js terpisah** → admin jadi route group Inertia sampai Sprint 10 | B-1, B-5 multi-warehouse, B-8 — Blueprint dilanggar sebagian, harus dinyatakan ke klien |
| **J-c** | Tahan 18 minggu dengan memotong jalur `request_order` (Phase 3) dan `marketplace_purchase` dari MVP | Blueprint §5.3 — sesuai Doc 03 §11 butir 2, opsi ini sudah tersedia |

Rekomendasi saya: **J-a untuk cakupan, J-c untuk tanggal pertama** — yaitu luncurkan Phase 0–2 lebih dulu (RTS + PO penuh, ±18–20 minggu) dan susulkan Phase 3. Tiga opsi ini adalah keputusan pemilik proyek + klien, bukan keputusan teknis (Doc 11 §6 Q-1).

---

## 6. Pertanyaan yang harus dijawab sebelum dokumen turunan final

| # | Pertanyaan | Kenapa tidak bisa dijawab dev sendiri |
|---|---|---|
| Q-1 | Jadwal: J-a / J-b / J-c? | Komitmen waktu ke klien |
| Q-2 | Auth model: OIDC/token vs session+BFF (Blueprint §7 "sesuai threat model") | Bergantung jawaban Q-4 dan kebijakan SSO klien; ADR-007 |
| Q-3 | Finance: perlu `payables`/`expenses`/cash-bank dalam platform, atau export ke pembukuan klien? | Menambah satu modul ERP; bukan cakupan Doc 00 §Non-goals |
| Q-4 | Kapasitas teruji berapa RPS/concurrent? (prasyarat Waiting Room & SLO) | Baru bisa dijawab setelah k6 jalan di staging |
| Q-5 | Multi-warehouse sejak MVP atau satu gudang? (ADR-011) | Menentukan `stock_items` key & aturan allocation |
| Q-6 | Domain/edge: Cloudflare plan klien tersedia Waiting Room? Fitur ini berbayar per §10.2 butir 6 | Kontrak & biaya klien |
| Q-7 | Gateway & settlement semantics (Blueprint §8.4, ADR-006) | Akun & kontrak gateway milik klien (Doc 03 §9) |

---

## 7. Daftar pekerjaan turunan (yang dikerjakan setelah Doc 11 disetujui)

| Dokumen | Sifat perubahan | Butir |
|---|---|---|
| 00-overview | indeks + status + rujukan induk + jadwal | semua |
| 01-architecture | **berat**: topologi 3 app + monorepo, kontrak API, empat sumbu status, concurrency §5.1, admission/edge, MinIO, observability/SLO, risiko HA | B-1,B-2,B-3,B-5,B-6,B-8,B-9,B-12,B-13,B-15 |
| 02-business-flow | **berat**: journey per order_type, idempotensi, late-payment, returns, upload presigned, status customer = turunan 4 sumbu | B-3,B-6,B-7,B-9 |
| 03-sprint-plan | **berat**: Sprint 0–7 → Phase 0–4; DoD + concurrency/load test; jalur paralel +7 | §5, B-8,B-11 |
| 04-frontend-theme | **berat**: Inertia → Next.js dua app; token tetap 3 lapis; prosedur ganti brand per app | B-1 |
| 05,06,07,08 | **ringan**: catatan "fakta tetap; keputusan dari Blueprint"; Doc 06 role 10; Doc 08 K-4 | B-10, K-4 |
| 09-database-schema | **berat**: rename §4.1, tambah §4.2, kolom snapshot B-13, `paid_stock_exception` | B-3,B-5,B-6,B-7,B-13 |
| 10-migration-plan | **sedang**: path `apps/commerce-api/database/...`, band modul baru untuk 21 tabel, tier Appendix A dihitung ulang | B-1, §4 |
| specs/sprint-0 + plan implementasi | **tulis ulang** (ADR-003 K-7): monorepo scaffold, OpenAPI pipeline, dua app, Testcontainers | B-1,B-2,B-11 |
| baru | `12-api-contract.md`, `threat-model.md`, `adr/ADR-004…011`, `runbooks/*` | B-2,B-14,B-12, §3 |

Urja: Doc 01 → 09 → 10 → 02 → 04 → 03 → 05–08 → spec/plan → ADR baru. Alasannya: skema adalah tumpuan semua dokumen fungsional, dan plan implementasi adalah yang paling terakhir berubah karena bergantung pada semuanya.
