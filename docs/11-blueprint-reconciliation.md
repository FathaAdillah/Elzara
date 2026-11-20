# Dokumen 11 — Rekonsiliasi Blueprint terhadap dokumen 00–10

Tanggal: 2026-11-17 · Status: mengikat sebagai jembatan rujukan.
**Rujukan induk:** `SNOWEGO_MICROSERVICES_BLUEPRINT.md` v1.0 (17-11-2026) — disebut **Blueprint**.
Aturan main (ADR-003 K-1): Blueprint menang atas dokumen 00–10, kecuali **bukti** (K-4) dan daftar **K-5**. Dokumen lain tidak boleh mengulang perdebatan di sini; mereka cukup menunjuk butir `B-n` di §1, atau nomor bagian dokumen ini (`§4.2`, `§5`, `§6 Q-n`) bila butirnya bukan konflik dua arah. **Tidak ada seri `R-n`** — rujukan `R-14` pada draft sebelumnya mengacu ke tabel yang tidak pernah ditulis dan sudah diganti dengan `§4.2`.

Cara baca kolom Bentrok: `—` tidak ada konflik · `⚠` konflik keputusan (perubahan nyata) · `✚` Blueprint menambah cakupan yang sebelumnya tidak ada · `?` butuh jawaban klien.

---

## 1. Bentrok besar yang mengubah arsitektur

| # | Blueprint | Dokumen lama bilang | Resolusi | Bentrok |
|---|---|---|---|---|
| B-1 | §2.1 tiga deployment mandiri: `api` (Laravel) + `admin` (Next.js) + `web` (Next.js); §3.1 monorepo `apps/`+`packages/`+`infrastructure/` | Doc 01 §3/§2 + ADR-001 + D-S0 plan: **satu app Inertia** untuk storefront dan admin | ADR-003 K-2: satu API Laravel **plus dua app Next.js** di satu monorepo. Admin bukan lagi route group `admin/` di app yang sama; ia app sendiri dengan BFF sendiri. | ⚠ |
| B-2 | §7 "OpenAPI 3.1 sebagai source of truth + generated TypeScript client" | Doc 01 §5/D8: tidak ada kontrak API publik yang perlu dipetakan; mutations via Command internal | Doc 01 §5 ditulis ulang: `/api/v1` jadi kontrak resmi; Command (B2) tetap satu-satunya pintu mutasi di sisi server, controller API jadi bungkus tipis. `packages/api-contracts` wajib, dan client TS hasil generate dipakai kedua app. | ⚠ |
| B-3 | §5.4 `orders.order_type` = `ready_stock` / `preorder` / `request_order` + **empat sumbu**: `order_status`, `payment_status`, `procurement_status`, `fulfillment_status` | Doc 01 §6 + Doc 09: **dua sumbu** (`money_status` × `fulfillment_status` per lane) | Empat sumbu adopted. `money_status` kita = `payment_status` Blueprint (nama dihapus); `procurement_status` baru; lane `rts`, `import_po`, `request`, `procurement` tetap sebagai pengisi tahap `fulfillment_status`, bukan sebagai `order_type`. | ⚠ |
| B-4 | §2.2 + §4: domain **Sourcing** (product requests, quotations) dan **Procurement** (supplier PO, goods receipt) sebagai dua hal berbeda | Doc 09 §13 memakai `Procurement` untuk "jalur beli atas nama buyer di marketplace lain" (D5) — **tabrakan nama beda makna** | Modul D5 kita ganti nama menjadi **`MarketplacePurchase`** (tabel `marketplace_purchases`, `claim_codes`, `proof_files`), dan nama `Procurement` dikembalikan ke makna Blueprint (supplier: `suppliers`, `purchase_orders`, `purchase_order_items`, `supplier_invoices`). `goods_receipts` pindah dari Inventory ke Procurement. | ⚠ |
| B-5 | §6.1 model stok: `on_hand`, `reserved`, `damaged`, `in_transit`, `available_to_sell = on_hand − reserved − unavailable`; satu baris agregat per `(warehouse_id, sku_id)` + ledger immutable | Doc 09 `stock_movements` + `reservations` saja; tidak ada baris agregat, tidak ada `damaged`/`in_transit`, tidak ada `unavailable` | Tabel **`stock_items`** ditambahkan sebagai pemegang kebenaran reservasi atomic; ledger tetap `stock_movements` (S-6); `unavailable` didefinisikan = `damaged + quarantined` dan dijaga CHECK non-negatif. | ⚠ |
| B-6 | §8.1–8.5 atomic conditional UPDATE + order lock SKU + retry deadlock + batch quota UPDATE + `paid_stock_exception` | Doc 02/03 menyebut "reservasi dengan TTL"; Doc 09 CHECK tersedia ≥ qty; tidak ada urutan lock, tidak ada resolusi late-payment | Menjadi spesifikasi, bukan catatan: Doc 01 §5.1 baru + Doc 02 §4. Kolom `stock_reservations.expires_at` dan `orders.paid_stock_exception` (status) masuk skema. Deadlock retry dengan backoff + batas retry di level Command. | ✚ |
| B-7 | §6 + §8.3 `idempotency_keys` dengan scope `(actor, operation, key)` + request hash + 409 saat key sama payload beda; §6 Reliability `inbox_events`, `webhook_receipts` | Doc 09 hanya `webhook_events` unik `(provider, provider_event_id)` | Tiga tabel keandalan baru. `webhook_events` lama jadi `webhook_receipts` (payload mentah + verifikasi), `inbox_events` untuk konsumen event, `idempotency_keys` untuk mutasi klien. `Idempotency-Key` header wajib untuk order/charge/refund. | ✚ |
| B-8 | §10 Cloudflare Waiting Room + admission policy + larangan memakainya sebagai jaminan fair-stock; §16 test "Waiting Room under load" dan "Cloudflare bypass" | Tidak ada di Doc 01 §2/§10 maupun Doc 03 | Topologi Doc 01 §2 ditambah lapisan admission; DoD Sprint 7 mendapat dua test baru; runbook `cloudflare-waiting-room.md`. Kapasitas ditentukan load test, bukan angka. | ✚ |
| B-9 | §11 enam bucket MinIO + presigned PUT + finalize + validasi MIME/magic-byte + scan + signed URL privat + CORS per origin | Doc 01 §10 menyebut MinIO + bucket `media-public/media-private`; Doc 02 §3 menyebut upload lampiran | Bucket list diadopsi penuh; alur presigned + finalize jadi spesifikasi fitur upload (Doc 02 §9 dan Sprint 5), dengan catatan eksplisit Blueprint: presigned URL harus memakai endpoint yang bisa dijangkau browser, bukan hostname internal Docker. | ✚ |
| B-10 | §4 RBAC 10 role (superadmin, catalog-manager, sales-ops, preorder-ops, sourcing-ops, warehouse-staff, procurement-staff, finance, customer-support, analyst read-only) | **Dua inventaris lama yang saling tidak konsisten:** Doc 06 §1 menabulasi **8 baris tanggung jawab** (Owner, Merchandiser, Warehouse, CS, Finance, Editor konten, Moderator, dev/ops) sebagai desain organisasi, sedangkan Doc 10 §8 me-seed **5 role** (Admin, Ops, Warehouse, Finance, Support) sebagai data referensi. Draft ini sebelumnya menulis "5 role" untuk keduanya — salah. | Sepuluh role adopted sebagai **satu-satunya set yang di-seed**; permission per `modul.aksi` tetap, `role_user` tetap, penegakan di Laravel policy/gate. Delapan tanggung jawab Doc 06 dipetakan ke sepuluh role itu. **Celah nyata:** Blueprint tidak punya role untuk **Editor konten** (halaman kebijakan ber-versi, FAQ, banner, nav, SEO) dan **Moderator** (ulasan + lampiran user). Keputusan teknis: keduanya menjadi **kumpulan permission di bawah role yang ada** (CMS → catalog-manager; moderasi UGC → customer-support + sourcing-ops), **bukan role ke-11**. Siapa yang memegangnya = keputusan organisasi klien (pertanyaan baru **Q-8**). "Ops" lama terpecah menjadi sales-ops / preorder-ops / sourcing-ops. | ⚠ |
| B-11 | §16 tools: Pest **atau** PHPUnit, Playwright, k6/Locust, Testcontainers, chaos injection, OWASP ZAP; "SQLite tidak cukup" | D-S0-3 memilih PHPUnit; Doc 03 DoD tidak menyebut load test otomatis; Doc 01 §11 menyebut "Load" tapi tanpa alat | PHPUnit tetap sah (Blueprint mengizinkan). Ditambah: suite concurrency nyata di PostgreSQL via Testcontainers/service CI, k6 untuk dua angka acceptance §16 (1000 paralel/500 kuota), Playwright untuk 3 alur. D-S0-3 diperluas, tidak dibatalkan. | ✚ |
| B-12 | §1 "SLO ketersediaan 99.9% setelah sistem matang", metrik p95, queue lag, oversell anomaly; §15 runbook 11 skenario | Doc 01 §11 dashboard minimum (mirip, 8 metrik) tanpa SLO tertulis; runbook hanya backup-restore/deploy/incident | SLO dicantumkan sebagai target pasca-stabilisasi (bukan gerbang launch); runbook 11 skenario jadi deliverable Sprint 7+ dan daftar `docs/runbooks/`. | ✚ |
| B-13 | §6 Money: integer minor units **atau** `NUMERIC(20,4)` dengan pembulatan eksplisit; simpan `currency_code`, `exchange_rate_snapshot`, `tax_rule_snapshot`, `price_snapshot` | S-1 integer rupiah; S-8 snapshot kolom per baris; kurs sebagai settings | Integer dipertahankan (K-5). Empat kolom snapshot **ditambah eksplisit** — kita hanya punya sebagian. `NUMERIC(20,4)` ditolak: dua representasi uang dalam satu basis data adalah sumber bug pembulatan. | — |
| B-14 | §3.1 `docs/threat-model.md` wajib ada di repo | Doc 01 §8 berupa daftar kontrol, tanpa model ancaman | Dokumen baru `docs/threat-model.md` (turunan), dipakai untuk menjawab §7 "OAuth2/OIDC atau session/BFF sesuai threat model". | ✚ |
| B-15 | §14.1 peringatan: Docker Compose single-node = SPOF; "jangan klaim HA tanpa multi-node, replikasi, failover, recovery teruji" | Doc 01 §10 + Doc 03 §8 merencanakan VPS + Docker Compose | Tidak ada klaim HA di dokumen mana pun. Ditambah kalimat kapasitas/risiko di Doc 01 §12: target kami satu node dengan backup teruji + restore drill (Sprint 0) dan pemulihan manual, bukan 99.9% otomatis. | ⚠ |

---

## 2. Yang Blueprint **tidak** ubah (dan tetap jadi aturan kita)

B1–B6 (boundary, Command-only, event bawa ID, outbox, uang via aggregate, namespace modul per B6) — Blueprint §2.2/§9 setuju dan bahkan lebih ketat soal ownership.
S-1…S-10 skema (kecuali penyesuaian B-13) dan M-1…M-13 migrasi — Blueprint §14.3 justru meminta expand/contract dan migration compatibility checks; tidak ada konflik.
ADR-002 model invoice → **bertambah, tidak diganti**: Blueprint §6 mengenal `invoices` + `invoice_lines` + `receivables`; tipe tagihan `deposit | balance | intl_fees | domestic_ship | extra_ship | adjustment` kita tetap, dan `intl_fees` sekarang punya pasangan entitas (customs/landed cost, lihat §4.2: `customs_charges`, `landed_cost_allocations`).
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
| 6 BFF Next vs browser→API langsung | **diputus pemilik 2026-11-18** | ADR-007 amandemen: cookie sesi Sanctum same-site (Bentuk C); persetujuan kontrol sesi dikonfirmasi pada 2026-11-20. Lokasi artefak dan Q-2 IdP masih terbuka |
| 7 MinIO bucket privat/publik + CDN | **baru → ADR-008** | B-9 |
| 8 Waiting Room coverage/threshold/bypass | **baru → ADR-009** | B-8; butuh angka load test |
| 9 monorepo CI/CD + independent deploys + strategi migrasi | sebagian (Doc 10) | **ADR-010** — Doc 10 §1 M-1…M-4 jadi lampirannya |
| 10 inventory allocation (partial fulfillment / multi-warehouse) | **baru → ADR-011** | B-5; default: single warehouse MVP |

Kesimpulan yang harus disampaikan: **delapan ADR (004–011)** adalah pekerjaan dokumentasi yang diminta Blueprint §18, dan empat di antaranya (005, 006, 008, 009) tidak bisa ditutup sendiri oleh dev — butuh keputusan/angka dari klien atau hasil tes. **ADR-012 bukan baris Blueprint §18**: ia keputusan pemilik 2026-11-18 tentang identitas/RBAC/dependensi (§4.4), jadi peta di atas tetap sepuluh baris sementara jumlah berkas ADR di `docs/adr/` dua belas (gate E).

---

## 4. Skema: nama diadopsi, tabel ditambahkan

Aturan turunan (Doc 09 §1 baru): **nama mengikuti Blueprint ketika perbedaan hanya prefik/jamak; makna mengikuti Blueprint; detail kita dipertahankan di tempat Blueprint hanya menyebut "minimum".**

### 4.1 Ganti nama (12)

| Doc 09 lama | Jadi | Alasan Blueprint |
|---|---|---|
| `user_addresses` | `addresses` | §6 Identity |
| `variants` | `product_variants` | §6 Catalog |
| `product_options`/`_values`/`variant_option_values` | tetap | §6 tidak menyebut; kita butuh untuk varian 2 sumbu (Doc 03 §3 Sprint 1) |
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

### 4.2 Tabel baru dari Blueprint (25 + 4 disahkan 2026-11-17)

`customer_profiles`*, `checkout_sessions`, `price_lists`, `price_list_entries`, `product_media`, `brands`, `order_charges`, `order_adjustments`, `preorder_allocations`, `request_attachments`*, `sourcing_options`, `quotation_versions`, `stock_items`, `stock_transfers`, `stocktakes`, `suppliers`, `purchase_orders`, `purchase_order_items`, `supplier_invoices`, `invoice_lines`, `payments`, `receivables`, `forwarders`, `consolidations`, `cargo_shipments`, `cargo_items`, `customs_charges`, `landed_cost_allocations`, `idempotency_keys`, `inbox_events`, `webhook_receipts`.

Empat tabel yang sebelumnya menggantung di Doc 12 §7 kini **disahkan** dan menjadi tanggung jawab Doc 09 + Doc 10:

| Tabel | Dasar | Catatan |
|---|---|---|
| `returns` + `return_items` | **Blueprint §4** — grup Sales punya submenu "Returns"; tanpa keduanya menu itu tidak punya objek | satu baris retur per pengajuan, item per baris order; jalur `refund_requested` → `refunded` di `order_status` (B-3) bergantung padanya |
| `wishlist_items` | **bukan** Blueprint — turunan produk kita (Doc 05 M3, "kabari saya"/favorit PDP) | sah karena Blueprint §4/§6 menyebut "minimum", bukan daftar maksimum; declare sebagai ekstensi agar tidak disangka keharusan |
| `back_in_stock_subscriptions` | sama: turunan Doc 05 M3 | dieksekusi oleh antrian `notifications` (Blueprint §9), tidak menyentuh jalur uang/stok |

`*` = sudah ada padanannya di skema kita sebagai mekanisme polimorfik/append-only (`media` per S-3 melayani lampiran; `order_events` melayani riwayat) — **tidak dibuat ulang**; Doc 09 §20 mencatat alasan deviasi, karena membuat tabel kedua untuk fakta yang sama melanggar B1.

Total setelah rekonsiliasi: estimasi awal di dokumen ini **85 + ±21 = ±106**; angka **114 tabel** (117 blok termasuk 3 tabel bawaan `[Laravel]`) adalah hasil parse Doc 09 **sebelum** pengesahan empat tabel terakhir di atas, dan kini bukan angka yang kami pegang — **Doc 09 §19** adalah satu-satunya sumber angka, dan parser yang sama (cek jumlah per modul, target FK, keasiklikan graf — Doc 10 §12) wajib dijalankan ulang setiap kali Doc 09 berubah. Setiap dokumen lain yang menyebut jumlah tabel harus mengutip §19, tidak boleh mengulang angkanya. (Selisih 106→114 dulu berasal dari tabel yang di estimasi awal hanya disebut sekadangnya dan baru dijabarkan saat Doc 09 ditulis: Promotion 7 tabel yang badannya kosong, `v_landed_cost`, dst.)

### 4.3 Yang tidak diambil dari Blueprint §6, dengan alasan tertulis

`payables`, `expenses`, cash/bank (hanya muncul di menu admin §4 Billing & Finance, bukan di §6): buku besar keuangan ganda di luar kebutuhan platform commerce kita; rekomendasi = export ke akuntansi klien, bukan tabel baru. **Butuh keputusan klien** (§6 pertanyaan Q-3).

`staff_users` (§6 Identity): **kita tidak membuat tabel keduanya.** Satu baris `users` memuat kedua populasi; pembedanya sejak 2026-11-18 adalah **`users.is_operator`** (sebelumnya `users.kind = customer | staff`, lihat §4.4), dan satu-satunya atribut ekstra staff (`2FA`, `staff_2fa_secrets`) sudah punya tempat sendiri. Dua tabel untuk satu orang adalah sumber bug "staf yang juga customer" (dan menghapus staff berarti menghapus dua jejak yang seharusnya terpisah). Deviasi ini juga dicatat di Doc 09 §20 supaya parser pembanding Blueprint tidak menandainya sebagai kelalaian diam-diam. Konsekuensi yang diterima: Blueprint §6 menyebutnya "tabel minimum", jadi secara harfiah kita kurang satu nama — angka itu harus dibaca sebagai daftar peran, bukan keharusan fisik tabel.

`purchase_requisitions` (menu §4 Procurement, tidak ada di tabel §6): **tidak dibuat sebagai tabel.** Requisition dimodelkan sebagai `purchase_orders.status = draft | pending_approval` + stage `requisitioned` pada `procurement_status` (§8.4). Menambah entitas kedua untuk "PO yang belum disetujui" melanggar aturan satu-fakta-satu-tempat (B1).

### 4.4 Keputusan pemilik 2026-11-18 — identitas, auth, RBAC, dan dependensi

Ini register mengikat untuk gelombang development berikutnya. Dokumen lain **menyalin** aturan di sini, tidak menurunkannya ulang; ADR-012 memuat alasan dan alternatif yang ditolak, ADR-007 diamandemen untuk mekanisme sesi.

**A. Satu tabel `users` untuk admin dan storefront.** `kind` **dihapus**, diganti dua boolean:

| Kolom | Aturan |
|---|---|
| `is_operator boolean NOT NULL DEFAULT false` | satu-satunya syarat boleh login ke admin dashboard (selain akun `active` + 2FA) |
| `is_admin boolean NOT NULL DEFAULT false` | bypass RBAC di admin dashboard |

- `CHECK (is_admin = false OR is_operator = true)` — bypass tanpa hak login admin adalah keadaan mustahil, bukan kemungkinan yang dijaga kode.
- Indeks `users_kind_status` diganti `users_operator_status (is_operator, status)`.
- **Login admin:** email + password saja, `is_operator = true`, 2FA wajib (`staff_2fa_secrets`, 10 recovery code ter-hash). Tidak ada jalur sosial ke admin.
- **Login web:** akun `active` apa pun, termasuk operator. `is_operator`/`is_admin` **tidak memberi hak apa pun di storefront** — otorisasi web adalah kepemilikan (policy owner) + status akun.
- `is_admin = true` menyalakan `Gate::before` yang meloloskan semua permission admin; setiap pemakaiannya **wajib** menulis `audit_logs` (`action = rbac.bypass`, `reason`, `request_id`). Bypass tidak boleh berlaku di guard web.
- `staff_users` tetap tidak dibuat (§4.3).

**B. RBAC admin = `spatie/laravel-permission`, granular per permission.** Bentuk tabel mengikuti spatie (`name`, `guard_name`), bukan bentuk pivot lama kita:

| Lama (tidak berlaku) | Baru |
|---|---|
| Pivot lama `role_user` | `model_has_roles` |
| Pivot lama `permission_role` | `model_has_permissions` |
| — | `role_has_permissions` |
| kolom `scope_type`/`scope_id` di pivot lama `role_user` | tabel `role_scopes (user_id, role_id, scope_type, scope_id)` |

- Permission dinamai `<modul>.<aksi>` dengan aksi **terpisah**: `view` (index/show), `create`, `update`, `delete`, ditambah aksi khusus (`billing.void`, `order.force_transition`, `batch.close`, `payment.mark_paid_manual`). Prefix `<modul>` adalah **kata benda domain tunggal kecil**, bukan nama kelas modul, dan daftarnya kanonik di sini supaya Doc 06 dan `openapi.yaml` tidak bisa berbeda:

| Modul (Doc 09 §19) | Prefix permission | Modul | Prefix |
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

  `Lintas modul` (outbox/inbox/idempotency) tidak punya permission admin: ia infrastruktur, bukan layar. Aksi khusus hanya boleh ditambahkan lewat perubahan register ini, bukan diam-diam di seeder.
- **Permission bisa diberikan langsung ke user** lewat `model_has_permissions`, jadi seorang operator boleh punya `catalog.view` tanpa `catalog.create/update/delete`. Role adalah bundel, bukan satu-satunya jalur pemberian hak.
- 10 role Blueprint §4 tetap di-seed (Doc 10 §8) sebagai bundel permission. `superadmin` **tidak lagi berarti bypass**: bypass hanya lewat `is_admin`, dan itu tercatat.
- Scope per batch/kategori/gudang tetap hidup lewat `role_scopes`; membatasi permission saja tidak membatasi baris mana yang boleh dilihat.
- **Storefront tidak memakai spatie.** Tidak ada role/permission customer; menambahkannya berarti dua sumber otorisasi untuk satu orang.
- Bentuk kolomnya mengikuti paket supaya tidak perlu fork: `roles` dan `permissions` memakai **`name` + `guard_name`** (kolom `code` yang lama **diganti** `name`; `roles.name` berisi kode role Blueprint seperti `catalog-manager`, `permissions.name` berisi `<modul>.<aksi>`), unik pada `(name, guard_name)`. Kolom ekstra kita tetap hidup: `roles.description`, `roles.is_system`, `permissions.group_name`, `permissions.description`. `guard_name` untuk seluruh RBAC admin adalah **`admin`** (bukan default `web`), karena guard storefront tidak memakai spatie.
- `model_has_roles` / `model_has_permissions` mempertahankan kolom morph standar paket (`model_type`, `model_id`, `role_id`/`permission_id`, PK komposit + indeks morph) — hanya `users` yang menjadi model, tapi bentuknya tidak boleh disederhanakan menjadi `user_id` saja karena paket membaca kolom morph.
- `role_has_permissions (permission_id, role_id)` PK komposit; `role_scopes (user_id, role_id, scope_type, scope_id)` dengan `scope_type NULL = global | batch | category | warehouse` dan PK komposit yang memakai `COALESCE(scope_type,'')`/`COALESCE(scope_id,0)` seperti aturan lama, supaya satu pasangan user-role bisa punya scope global dan beberapa scope berbaris.
- `user_identities (provider, provider_user_id, user_id)` unik pada `(provider, provider_user_id)`; `activity_log` memakai kolom standar spatie (`log_name`, `description`, `subject_type`/`subject_id`, `causer_type`/`causer_id`, `properties` jsonb, `event`, `batch_uuid`). Keduanya milik modul yang sudah ada: `user_identities` → **Identity**, `activity_log` → **Audit** (Doc 09 §19).
- Cache permission spatie adalah fakta operasional: pencabutan hak wajib lewat jalur yang meng-invalidasi cache (`PermissionRegistrar`), bukan `UPDATE` langsung ke tabel — ini masuk runbook dan Doc 06, bukan hanya catatan skema.

**C. Auth = Sanctum session cookie, bukan bearer token.** `laravel/sanctum` dengan `statefulApi`; edge kita satu host dengan pemisah path (`/api/`, `/admin/`, `/` — `docker/nginx/dev.conf`), jadi cookie **same-site**: `Secure; HttpOnly; SameSite=Lax` + CSRF wajib, tanpa CORS lintas origin dan tanpa `SameSite=None`.

- `personal_access_tokens` **tidak dibuat**: tidak ada token jangka panjang di browser (ADR-007 D-4). Sanctum dipakai untuk sesi, bukan PAT.
- Sesi admin dan storefront **dipisah** (nama cookie + guard berbeda) — ADR-007 D-4; satu cookie yang berlaku di keduanya akan membuat XSS storefront menjadi akses admin.
- **Hybrid web:** email+password, Google (`laravel/socialite` driver bawaan), X (`socialiteproviders/twitter`, OAuth 2.0 — driver `twitter` bawaan Socialite memakai OAuth 1.0a yang sudah ditinggalkan X). Registrasi mengikuti jalur yang sama.
- Tabel baru **`user_identities`** (`provider`, `provider_user_id`, `user_id`, unik per provider) menyimpan identitas sosial. Penggabungan akun hanya lewat **email terverifikasi**, bukan email mentah dari provider — kalau tidak, siapa pun yang mendaftar di provider dengan email korban bisa mengambil alih akun.
- Konsekuensi skema dari aturan itu (ditemukan saat gelombang knowledge 2026-11-18): `otp_challenges.purpose` yang lama hanya `login | reset_password | verify_phone` **tidak punya jalur verifikasi email**, jadi tambah **`verify_email`** pada daftar purpose. Tanpa nilai ini, "email terverifikasi" tidak bisa diproduksi oleh sistem kita sendiri dan aturan penggabungan akun hanya jadi slogan. `users.email_verified_at` sudah ada dan tetap satu-satunya tempat fakta itu disimpan.
- ADR-007: mekanisme diputuskan pemilik 2026-11-18; **Q-2 menyempit** menjadi "apakah klien punya IdP/SSO sendiri", bukan lagi "BFF atau token".

**D. Dependensi — versi dibaca dari registry 2026-11-18, bukan dari ingatan.**

| Paket | Versi | Catatan yang mengikat |
|---|---|---|
| `laravel/framework` | 13.43.0 | php ^8.3; baseline naik dari 13.42.0 yang diukur 2026-11-17 |
| `laravel/sanctum` | 4.5.0 | sesi cookie; PAT tidak dipakai |
| `laravel/horizon` | 5.52.0 | **tidak ada v6** — menulis `^6` berarti constraint yang tidak pernah resolve |
| `laravel/socialite` | 5.33.0 | driver google |
| `socialiteproviders/twitter` | 4.1.2 | OAuth 2.0 untuk X |
| `spatie/laravel-permission` | 8.5.0 | php ^8.3; bentuk tabel spatie (butir B) |
| `spatie/laravel-activitylog` | 5.1.5 | **php ^8.4** — host PHP 8.3 tidak bisa memasang ini |
| `spatie/laravel-data` | 4.23.1 | DTO di `Contracts/`, bukan di controller |
| `dedoc/scramble` | 0.13.55 | alat bantu draf spec (butir E) |
| `sentry/sentry-laravel` | 4.30.0 | `send_default_pii=false`; scrub PII sebelum kirim (threat-model) |
| `maatwebsite/excel` | 4.0.7 | import/export lewat antrian `imports`/`reports`, bukan sinkron di request |
| `phpunit/phpunit` | 13.4.7 | **php >=8.4.1**; `laravel/framework` 13 mengizinkan `^11.5.50 \|\| ^12.5.8 \|\| ^13.0.3`, dan `nunomaduro/collision` 8.9.6 hanya menolak `<11.5.50 \|\| >=14` — jadi 13 tidak bentrok |
| `phpstan/phpstan` / `deptrac/deptrac` | 2.3.6 / 4.7.2 | gerbang lint + architecture test B1–B6. **`qossmic/deptrac` sudah abandoned dan digantikan `deptrac/deptrac`** (registry 2026-11-18); constraint `^4.7` yang sudah ada di `apps/api/composer.json` benar, nama `qossmic` yang salah |
| `larastan/larastan` / `laravel/pint` / `nunomaduro/collision` | 3.15.0 / 1.32.3 / 8.9.6 | larastan 3.15 mendukung `laravel/framework ^13`; ketiganya sudah ada di kerangka, versi dinaikkan ke yang terverifikasi |

- **Redis:** ekstensi `phpredis` (sudah dipasang di `apps/api/Dockerfile`), bukan `predis` — satu driver, bukan dua.
- **"Laravel RateLimiter" bukan paket composer.** Ia `Illuminate\Support\Facades\RateLimiter` bawaan framework; menulisnya sebagai dependensi berarti mencari paket yang tidak ada.
- Instalasi dependensi **hanya di container** `php:8.4` / `node:22` (`scripts/gen-lockfiles.sh`). Dua paket di tabel ini menuntut PHP 8.4, jadi aturan itu bukan preferensi gaya — ia syarat resolve.

**E. Scramble tidak membalik arah kontrak.** `packages/api-contracts/openapi.yaml` tetap satu-satunya sumber kebenaran (Blueprint §7, ADR-010 K-3). `scramble:export` menghasilkan **draf** yang direview manusia lalu digabungkan ke spec; gerbang `scripts/check-contract-drift.sh` tetap menolak PR yang mengubah controller tanpa mengubah spec. Spec tidak pernah di-generate ulang secara otomatis di CI.

**F. `activity_log` bukan pengganti `audit_logs`.**

| Tabel | Pemilik | Isi | Kewajiban |
|---|---|---|---|
| `audit_logs` | Audit | aksi berisiko admin: `action`, `reason` wajib, `before`/`after`, `request_id`, append-only (S-6) | ditulis `AuditThis`; **tanpa baris ini aksi berisiko dianggap tidak terjadi** |
| `activity_log` (baru) | Audit | riwayat perubahan model otomatis dari spatie (created/updated/deleted, properties) | boleh kosong untuk aksi berisiko; tidak memenuhi kewajiban audit |

**G. Jumlah tabel berubah karena butir A–F.** Angka kepemilikan **tidak ditulis tangan di dokumen mana pun**: ia diproduksi ulang oleh `verify_schema.py` dan dibaca dari Doc 09 §19. Gate H dan gate I membandingkan Doc 09 §19 dengan Doc 01 §3.1/§3.3 dan `app/Modules/` di disk, jadi rename pivot dan dua tabel baru harus masuk ketiganya sekaligus atau gerbang gagal.

**H. Pembagian kerja agen** ada di `.qoder/agents/` (migrasi DB → fitur → testing → rebuild docker; penulis knowledge berjalan paralel pada berkas yang tidak bersentuhan). Urutan itu mengikat: agen testing tidak boleh mulai sebelum agen fitur selesai, dan rebuild docker adalah gerbang terakhir, bukan pekerjaan sampingan.

**I. Kontrak API ikut terbuka, dan header aktor tidak lagi menyebut populasi.** Karena mekanisme sesi sudah diputus (butir C, ADR-007 amandemen 2026-11-18), larangan lama "tidak boleh ada `securitySchemes` sebelum ADR-007 tertutup" **dicabut**: `openapi.yaml` sekarang wajib memuat `securitySchemes` cookie sesi (`cookieAuth` + CSRF) dan `security` per operasi, dan `x-elzara-auth` tetap dipakai sebagai penanda kategori (`public | customer | admin | s2s`). Doc 12 §1.2 G2 dan §3.1 diamandemen pada gelombang yang sama — bukan dibiarkan menyebut keputusan yang sudah ada.

- `X-Actor-Type` berubah dari `customer | staff | system` menjadi **`web | admin | system`**: `staff` bukan lagi populasi di database (butir A), dan seorang operator yang berbelanja di storefront adalah aktor `web`. Nilai diisi server dari guard/sesi yang dipakai, tidak pernah dipercaya dari klien.
- Endpoint auth (login/logout/OTP/sesi, login sosial + callback, registrasi hybrid) **ditambahkan** ke spec; 18 jalur Blueprint §7 tetap wajib ada seluruhnya (gate I-7 memeriksa keberadaan, bukan jumlah total), jadi penambahan tidak boleh menggantikan satu pun jalur Blueprint.
- Aksi modul admin wajib menyatakan permission lewat `x-elzara-permission: <modul>.<aksi>` supaya matriks Doc 06 dan spec dapat diperiksa bersama. Pembacaan dan penutupan sesi sendiri (`getAdminSession`, `logoutAdmin`) selalu tersedia bagi pemilik sesi admin yang terverifikasi. Aksi modul yang memakai bypass `is_admin` tetap menulis `audit_logs` (butir A).

**J. Jumlah ADR jadi 12.** ADR-012 (`identitas-rbac-dependensi`) memuat alasan + alternatif ditolak untuk butir A–G; ADR-007 diamandemen untuk mekanisme sesi. Gate E menghitung berkas `docs/adr/ADR-*` dan menuntut **12** dengan nomor 001–012 hadir semua; kata kunci topiknya harus membedakan keduanya (`bff/browser/sesi/token` untuk 007, `rbac/permission/is_operator/is_admin/dependensi/identitas` untuk 012) — kata generik "auth" sengaja tidak dipakai sebagai pembeda karena keduanya menyentuhnya.

**K. Kerangka yang sudah ada belum lengkap untuk auth — ini daftar kekurangannya, bukan asumsi.** Dibaca dari disk 2026-11-18 (`apps/api/composer.json`, `apps/api/config/`, `apps/api/bootstrap/app.php`):

| Kekurangan | Yang harus dilakukan |
|---|---|
| `composer.json` masih meminta `predis/predis ^3.7` | **hapus** — keputusan butir D memakai ekstensi phpredis; dua driver Redis adalah dua sumber kebenaran |
| `phpunit/phpunit ^12.5` | naikkan ke `^13.0` (13.4.7 terverifikasi, tidak bentrok dengan collision 8.9.6) |
| sanctum, socialite, socialiteproviders/twitter, spatie/laravel-permission, spatie/laravel-activitylog, spatie/laravel-data, dedoc/scramble, sentry/sentry-laravel, maatwebsite/excel **belum ada** | pasang di container `php:8.4` sesuai butir D |
| **tidak ada `config/auth.php`** | buat dengan dua guard `web` dan `admin` (driver session, provider users yang sama) + provider eloquent `App\Modules\Identity\...` sesuai struktur modul |
| `bootstrap/app.php` menempel middleware session manual, **tanpa `statefulApi()` dan tanpa CSRF** | pakai `$middleware->statefulApi()` (Sanctum) dan aktifkan `ValidateCsrfToken` untuk mutasi; jangan menulis ulang daftar middleware cookie secara manual |
| `config/session.php` hanya punya **satu** nama cookie | Laravel tidak menyediakan dua cookie sesi secara native. Mekanismenya: middleware permukaan yang berjalan **sebelum** `StartSession` menyetel `config(['session.cookie' => ...])` berdasarkan path — `/admin` dan `/api/v1/admin/*` → `elzara_admin_session`, sisanya → `elzara_web_session`. Kedua cookie `Path=/` sehingga keduanya terkirim ke `/api/`; yang memisah adalah **nama** yang dibaca server, bukan path cookie |

Dua guard pada satu session store sebenarnya sudah punya kunci terpisah (`login_web_*` vs `login_admin_*`), tetapi itu **tidak cukup**: satu cookie berarti XSS storefront membaca sesi yang juga memuat login admin. Pemisahan nama cookie adalah kontrol yang nyata, dan karena itu ia ditulis di sini sebagai mekanisme, bukan diserahkan ke improvisasi agen fitur.

**L. Checkout tamu tetap satu baris `users`, bukan null dan bukan tabel kedua.** Ditemukan saat memverifikasi sitasi 2026-11-18: `orders.user_id` `NOT NULL REFERENCES users(id)` (Doc 09 §9) dan `idempotency_keys.actor_id` `NOT NULL` dengan unique `(actor_id, operation, idempotency_key)` — sementara Doc 02 §10.3 mengizinkan checkout tamu. Keduanya hanya bisa hidup bersama kalau tamu punya baris `users` sendiri. Konvensinya mengikat:

| Aspek | Ketentuan |
|---|---|
| Kapan dibuat | satu baris anonim per sesi checkout tamu, bukan per request dan bukan satu baris global |
| `email` | `guest-<public_id>@guest.invalid` — domain `.invalid` dijamin tidak bisa diroute (RFC 2606), jadi tidak pernah bertabrakan dengan email orang sungguhan; `citext UNIQUE` tetap terjaga |
| Kolom lain | `password_hash NULL`, `email_verified_at NULL`, `is_operator = false`, `is_admin = false`, `status = 'active'` |
| Tidak bisa login | password guard gagal karena hash NULL; jalur sosial gagal karena tidak ada baris `user_identities` dan email `.invalid` tidak mungkin menerima OTP verifikasi (butir C) |
| Klaim order | email+nomor (Doc 02 §10.3) mengisi email asli lalu memverifikasinya; **baris yang sama** menjadi akun biasa. Tidak ada penggabungan dua baris `users`, karena order sudah menunjuk baris ini sejak dibuat |
| Pembersihan | job retensi untuk baris tamu yang tidak pernah diklaim (Doc 01 §9.3); bukan hapus manual, dan bukan alasan membuat `actor_id` nullable |

Yang ditolak: `actor_id` nullable (unique Postgres memperlakukan NULL sebagai berbeda, jadi tamu bisa menduplikasi kunci tanpa batas) dan tabel `guest_actors` kedua (dua tabel untuk satu fakta aktor, kelas bug yang sama dengan tabel lama `staff_users` yang tidak dibuat, lihat §4.3). Kolom `is_guest` **tidak** ditambah: penandanya adalah domain email yang sudah reserved, dan menambah boolean berarti menambah fakta yang bisa berbeda dari emailnya.

---

## 5. Jadwal: konsekuensi jujur

Blueprint §17 memberi rentang sprint, bukan minggu: Phase 0 = 1–2, Phase 1 = 3–5, Phase 2 = 2–4, Phase 3 = 2–3, Phase 4 = berkelanjutan. Jumlah minimum untuk Phase 0–3 = **8 sprint**, maksimum **14 sprint**. Plan lama (Doc 03) menjanjikan Sprint 0–7 + 1 = 8 sprint ≈ ±18 minggu, dan itu sekarang berdiri di atas cakupan yang lebih kecil daripada Blueprint:

Pekerjaan baru yang tidak ada di 18 minggu itu: app Next.js kedua (admin) + BFF + generated client (B-1/B-2), `packages/api-contracts` + OpenAPI sebagai gerbang PR (B-2), model stok agregat + transfer + stocktake (B-5), concurrency & load test nyata dengan Testcontainers/k6 (B-11), empat sumbu status + state machine test per transisi (B-3), landed cost/customs/forwarder (§4.2: `forwarders`, `consolidations`, `cargo_shipments`, `cargo_items`, `customs_charges`, `landed_cost_allocations`), Waiting Room + bypass test (B-8), threat model + 11 runbook (B-14/B-12).

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
| Q-8 | RBAC: siapa memegang permission CMS (halaman kebijakan, banner, SEO) dan moderasi UGC (ulasan, lampiran request) di dalam 10 role Blueprint? (B-10) | Struktur tim klien; Blueprint tidak menyediakan role untuk keduanya |

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
| 10-migration-plan | **sedang**: path `apps/api/database/...`, band modul baru untuk 21 tabel, tier Appendix A dihitung ulang | B-1, §4 |
| specs/sprint-0 + plan implementasi | **tulis ulang** (ADR-003 K-7): monorepo scaffold, OpenAPI pipeline, dua app, Testcontainers | B-1,B-2,B-11 |
| baru | `12-api-contract.md`, `threat-model.md`, `adr/ADR-004…011`, `runbooks/*` | B-2,B-14,B-12, §3 |

Urja: Doc 01 → 09 → 10 → 02 → 04 → 03 → 05–08 → spec/plan → ADR baru. Alasannya: skema adalah tumpuan semua dokumen fungsional, dan plan implementasi adalah yang paling terakhir berubah karena bergantung pada semuanya.

---

## 8. Daftar kebenaran kanonik (satu nilai untuk satu fakta di semua dokumen)

Bagian ini lahir dari sapuan kedua (2026-11-17): setelah semua dokumen ditulis ulang mengikuti Blueprint, ternyata **nilai yang sama tertulis berbeda di empat dokumen** — terparah pada enum status order. Aturan mainnya: **satu fakta punya satu pemilik**; dokumen lain hanya boleh mengutip, tidak boleh menuliskan ulang daftar nilainya. Kalau butuh variasi (mis. urutan per lane), tulis sebagai turunan dari daftar di bawah, bukan sebagai daftar baru.

### 8.1 Nama antrian Horizon — pemilik: Blueprint §9, didaftar di Doc 01 §10

Tujuh dan hanya tujuh: `payments` · `orders` · `inventory` · `notifications` · `imports` · `reports` · `media`.
Daftar lama (`default`, `search`, `reconcile`) **tidak berlaku**; siapa pun yang menulis perintah Horizon/migrasi memakai tujuh nama ini. Yang bukan tujuh nama itu tidak boleh muncul sebagai antrian produksi — kalau sebuah pekerjaan butuh pool sendiri, itu perubahan Blueprint §9 dan harus lewat dokumen ini lebih dulu. Prioritas saat macet dan larangan `horizon:clear` per antrian ada di `runbooks/queue-stuck.md` §3.3.

### 8.2 Sebelas runbook wajib (§15) → nama file yang benar-benar ada

| # | Blueprint §15 | File | Status |
|---|---|---|---|
| 1 | payment captured but order unpaid | `payment-callback-late.md` | ada |
| 2 | duplicate webhook | `webhook-replay.md` | ada |
| 3 | expired reservation but late payment | `reservation-expiry-race.md` | ada |
| 4 | batch over-quota | `batch-over-quota.md` | ada |
| 5 | stuck job/DLQ | `queue-stuck.md` | ada |
| 6 | supplier failure | `supplier-failure.md` | ada |
| 7 | database failover | `database-failover.md` | ada |
| 8 | MinIO unavailable | `object-storage-unreachable.md` | ada |
| 9 | Cloudflare waiting room misconfiguration | `cloudflare-waiting-room.md` | ada |
| 10 | refund reconciliation | `refund-reconciliation.md` | ada |
| 11 | emergency disable checkout | `emergency-disable-checkout.md` | ada |

Lima file lain milik kita dan **bukan** pengganti angka 1–11: `oversell.md`, `stock-discrepancy.md`, `backup-restore-drill.md`, `data-breach.md`, `rollback-release.md`. Setiap rujukan ke "11 runbook" di dokumen lain memakai pasangan nama Blueprint ↔ file ini, bukan nama perkiraan.

### 8.3 Empat sumbu status order — pemilik nilai final: **Doc 09** (CHECK), pemilik semantik: Blueprint §5

Nama kolom mengikuti Blueprint §5.4. Isinya adalah gabungan "nilai Blueprint §5" + stage lane kita; nilai lama yang tidak ada di daftar ini adalah bug, bukan varian.

| Sumbu | Nilai kanonik | Deviasi dari Blueprint, dengan alasan |
|---|---|---|
| `order_status` | `awaiting_payment · paid · processing · shipped · delivered · completed · on_hold · refund_requested · refund_pending · refunded · disputed · expired · cancelled` | `draft` (§5.1) **tidak dipakai**: cart adalah `carts`/`cart_items`, order lahir langsung `awaiting_payment`. `on_hold` dan `refund_requested` ekstensi kita (tahan manual CS/fraud; pengajuan retur customer sebelum refund disetujui). |
| `payment_status` | `unpaid · partially_paid · paid · overdue · refund_pending · refunded · disputed` | = isi `money_status` lama + `disputed` (Blueprint §5.1 daftar dia sebagai eksepsi; kita sempat menaruhnya sebagai flag saja). |
| `fulfillment_status` | `unprocessed · reserved · awaiting_batch · at_warehouse · ready_to_ship · picking · packed · handed_over · in_transit · delivered · returned` | `awaiting_batch` dan `ready_to_ship` **dipindah dari `order_status`** ke sini, karena keduanya adalah tahap perjalanan barang, bukan status pembayaran/order (aturan B-3: lane mengisi sumbu fulfillment). `customs` **dihapus** dari sumbu ini — faktanya dijaga `procurement_status`, dua pemilik satu fakta = jejak yang bisa saling bertentangan. |
| `procurement_status` | `not_required · sourcing · requisitioned · po_issued · supplier_confirmed · in_transit · customs · received · failed` | Blueprint §5.4 menamai sumbunya tapi tidak enumerasi nilainya; ini keputusan kita (Doc 02 memakainya sebagai kandidat, Doc 09 memutuskan). `failed` wajib ada karena §5.2 butir 8 menjanjikan "completion/refund jika gagal sourcing". `ready_to_ship` keluar (bukan fakta procurement). `requisitioned` = PO berstatus `draft`/`pending_approval`, bukan tabel baru (§4.3). |

**Urutan per lane** (`fulfillment_lane`, pemilih tahap — bukan sumbu kelima):
- `rts`: `unprocessed → reserved → picking → packed → handed_over → in_transit → delivered (→ returned)`
- `import_po`: `unprocessed → awaiting_batch → at_warehouse → ready_to_ship → picking → packed → handed_over → in_transit → delivered (→ returned)`; tahap luar negeri (`sourcing … customs … received`) **tidak** ditulis di sini, itu milik `procurement_status`.
- `request`: sama dengan `rts` setelah kuotasi diterima; sebelum itu statusnya ada di `product_requests.status` (§5.3) dan `procurement_status`.
- `marketplace_purchase` (D5): jalur pendek `unprocessed → handed_over → in_transit → delivered` karena barang berangkat dari penjual pihak ketiga.

Status yang **dilihat customer** tetap turunan dari keempat sumbu + lane (S-5: kolom turunan tidak ditulis).

### 8.4 Register event — pemilik: **Doc 12 §6.2**

Doc 12 §6.2 "Katalog event" adalah satu-satunya daftar event outbox. Doc 01 §8 (peta event per domain), Doc 02 §10.5 (notifikasi per event), Doc 09 `notification_templates.event_name`, dan runbook **mengutip namanya apa adanya**; tidak boleh ada "nama lokal".

**Tabel `order_status_history` tidak ada.** Yang namanya begitu adalah **view** di atas `order_events` (tabel append-only milik Orders, Doc 09 §18/S-5), bukan tabel kedua yang menyimpan riwayat yang sama. Dokumen yang mendaftar artefak boleh menuliskannya, tapi sebagai proyeksi (`order_events` → `order_status_history`), bukan sebagai tabel yang dibuat migrasi. Aturan umumnya: satu fakta riwayat punya satu tabel pemilik; dokumen lain hanya menyebut view-nya sebagai pembacaan.

Koreksi yang ditugaskan dari aturan ini:
- `PoBatchArrived` → **`GoodsReceived`** (Blueprint §2.2; sudah dipakai Doc 01/12). Muncul di Doc 09 `notification_templates` dan `runbooks/stock-discrepancy.md`.
- `ParcelShipped` → **`PackageShipped`** (konsisten dengan rename §4.1 `parcels`→`packages`); ada di Doc 01 §8 dan Doc 12 §6.2.
- **`PaidStockExceptionRaised`** resmi jadi event (Order/Inventory → notifikasi + antrean eksepsi finance/ops). Yang bernama `PaidStockException` di Doc 12 §4.3 tetap **domain exception** untuk HTTP, bukan event — dua objek berbeda, satu akar kata.
- Keluarga kuotasi mengikuti konvensi Blueprint §2.2 yang memakai `Quote*` untuk **event** (`QuoteIssued`, `QuoteAccepted`, `QuoteExpired`) sementara `Quotation*` dipakai untuk **entitas/exception** (`quotations`, `quotation_versions`, `QuotationExpired` 410). Aturan ini ditulis eksplisit di Doc 12 §6 supaya tidak dianggap typo.

### 8.5 Nama modul — pemilik: **Blueprint §3** (tabel Domain Service), dan daftar tabel per modul — pemilik: **Doc 09 §19**

Sapuan ketiga (2026-11-17) menemukan satu kosakata dipakai dua nama di dokumen yang sama: Blueprint §3 menamai domain service-nya `Sourcing`, `Fulfillment`, `Logistics`, sedangkan draft awal kami menulis `Quotes`, `Shipping`, `IntlLogistics`. Aturan kanoniknya:

| Nama modul (kanonik) | Nama lama yang **tidak berlaku** | Alasan Blueprint §3 |
|---|---|---|
| `Sourcing` | `Quotes` | domain service "Request, **Sourcing**, Quotation" — kuotasi adalah hasil, sourcing adalah domainnya |
| `Fulfillment` | `Shipping` | domain service "Fulfillment, Shipment, Tracking" — mencakup pick/pack/label/tracking, bukan hanya kirim |
| `Logistics` | `IntlLogistics` | domain service "Logistics/Forwarder/Cargo" — §3 tidak memberi kata "International" |

Akibat rename itu dua kepemilikan tabel ikut dipindah, dan **Doc 09 §19 adalah satu-satunya pemilik pemetaan tabel → modul** (118 tabel; angka total itu dijaga pemeriksa, bukan dihitung manual):

- `shipments` + `shipment_order_items` → **Fulfillment**. Meski kolom `order_id`-nya ditulis modul Orders, tabelnya adalah agregat pengiriman; Blueprint §3 menaruh shipment di Fulfillment.
- `refunds` → **Payments**. Blueprint §3 menaruh refund di "Billing & Payment"; Orders hanya memicu permintaan.
- `Search` dan `Reporting` **tidak punya tabel** (indeks Elasticsearch dan read model adalah salinan, bukan sumber kebenaran) — jadi baris §3.3 Doc 01 untuk keduanya ditulis sebagai penjelasan, bukan daftar.

Cerminnya: **Doc 01 §3.1 (pohon `app/Modules/`) dan §3.3 (kolom "Tabel inti") tidak boleh punya pendapat sendiri** — isinya dibangun ulang dari §19. Yang bukan tabel dan tidak boleh muncul di pohon: `staff_users` (→ `users.is_operator`, keputusan 2026-11-18; kolom `kind` yang lama diganti dua boolean), `request_attachments` (→ `media` polimorfik, S-3), `order_status_history` (→ view), dan trio reliability `outbox_events`/`inbox_events`/`idempotency_keys` (→ `app/Platform/`, bukan modul Audit; M-1). Gerbang H di `audit_sweep3.py` membandingkan ketiganya dan gagal kalau ada tabel yang diklaim dua modul.

**Label menu ≠ label modul, dan itu bukan inkonsistensi.** Blueprint §4 (menu admin) memakai nama kelompok berbahasa Inggris untuk **orang yang mengklik**: `International Logistics`, `Billing & Finance`, `Request/Sourcing`, `Pre-Order`. Blueprint §3 (domain service) memakai nama untuk **modul kode**: `Logistics`, `Payments`/`Billing`, `Sourcing`, `PreOrder`. Keduanya disalin verbatim dari tempatnya masing-masing — Doc 06 §2.2 menjaga label §4, Doc 01 §3.1/§3.3 dan Doc 09 §19 menjaga label §3. "Menyamakan" salah satunya (mis. mengganti grup menu `International Logistics` menjadi `Logistics`) merusak dua rujukan sekaligus dan tidak diminta Blueprint. Peta lengkap 14 grup → nav → modul ada di Doc 06 §2.2.

**Nama direktori aplikasi dan layout root (disahkan 2026-11-18, saat scaffold dipasang).** Blueprint §3.1 menulis `apps/commerce-api|commerce-admin|commerce-web`; repo ini memakai **`apps/api`, `apps/admin`, `apps/web`**, `docker/` dan `scripts/` di **root** (bukan `infrastructure/docker`), dan plan development di **`.qoder/plans/`** (spec tetap di `docs/specs/` karena ia artefak sign-off klien, bukan catatan kerja). Ini keputusan nama, bukan keputusan arsitektur: tiga deploy mandiri, satu penulis per tabel, dua lockfile, dan `packages/api-contracts` sebagai satu sumber kontrak **tidak berubah** — ADR-010 K-1/K-2 hanya membaca nama direktorinya saja. Konsekuensi yang diterima: 551 rujukan di 19 dokumen digeser sekali jalan oleh skrip (`rename_apps.py`), dan siapa pun yang mengutip `apps/commerce-*` di dokumen baru sedang menulis nama yang salah. Gate I di Doc 11 §8.8 menolak kalau pohon Doc 01 §3.1 dan isi repo berbeda arah, termasuk kalau nama lama masih muncul satu baris pun.

### 8.6 Enam "next document candidates" Blueprint §20 → artefak kita

| Blueprint §20 | Ada di kami | Bentuk |
|---|---|---|
| `DATABASE_SCHEMA.md` | `docs/09-database-schema.md` | ditulis ulang penuh (B-3/B-5/B-6/B-7/B-13) |
| `OPENAPI_SPEC.yaml` | `docs/12-api-contract.md` (kontrak + konvensi) dan artefak fisik `packages/api-contracts/openapi.yaml` yang **dibuat di Sprint 0** (Doc 03 §4 task 0.3, gerbang D-8) | dokumen ≠ berkas; keduanya wajib ada |
| `ORDER_STATE_MACHINES.md` | `docs/02-business-flow-customer-features.md` §4–§6 (tiga mesin + empat sumbu) + §8.3 di dokumen ini | tidak dibuat file terpisah: mesin tanpa alur bisnisnya hanya mengulang CHECK |
| `DEPLOYMENT_DOCKER.md` | `docs/01-architecture.md` §10 + `docs/10-migration-plan.md` §12 (CI) + berkas `docker/compose*.yaml` | sama, dipecah karena topologi dan pipeline adalah dua pembaca berbeda |
| `CLOUDFLARE_RUNBOOK.md` | `docs/runbooks/cloudflare-waiting-room.md` + `docs/adr/ADR-009-waiting-room.md` | runbook = saat rusak, ADR = saat memutuskan |
| `IMPLEMENTATION_TASKS.md` | `docs/03-sprint-plan.md` + `.qoder/plans/2026-11-17-sprint-0-fondasi.md` | plan Sprint 0 adalah potongan pertama yang dieksekusi |

### 8.7 Dua hal yang Blueprint sebut tapi tidak beri tempat: dump backup & kill switch checkout

**Dump backup bukan bucket aplikasi.** Blueprint §11 mendaftar **enam** bucket (`product-media`, `request-attachments`, `invoice-documents`, `shipment-documents`, `exports`, `audit-archives`) dan itu daftar objek **yang dibaca aplikasi**, bukan daftar semua wadah yang boleh ada. Blueprint §13 menuntut "Backup database PITR/WAL, object backup" — dan di node tunggal (B-15) menaruh dump di MinIO yang hidup di node yang sama berarti backup ikut mati bersama sumbernya. Aturan kita: **WAL/base backup dan dump volume disimpan di tujuan OFF-NODE** (objek storage penyedia lain, enkripsi sebelum keluar node, kredensial terpisah), BUKAN sebagai bucket ketujuh di MinIO produksi; `backups` hanya boleh dipakai di staging/local. Doc 01 §11 (backup scheduler) dan `runbooks/backup-restore-drill.md` mengikuti aturan ini. Konsekuensi yang diterima: restore drill jadi lebih lambat (tarik dari luar) dan butuh biaya bulanan kecil — itu harga dari backup yang benar-benar menyelamatkan.

**Flag kill switch checkout namanya `checkout.enabled`.** Katalog flag dimiliki Doc 09 §15 dan konvensi namanya adalah titik + sufiks `.enabled` (`marketplace_purchase.enabled`, `guest_checkout.enabled`, `reviews.enabled`). Jadi yang dimatikan saat insiden adalah `checkout.enabled = false`; ejaan `checkout_disabled` adalah nama **respons** (`503 CHECKOUT_DISABLED` di Doc 12 §4.3), bukan nama flag. Runbook `emergency-disable-checkout.md` dan Doc 12 menunjuk satu nama ini.

### 8.8 Gerbang verifikasi (supaya klaim "sesuai Blueprint" bisa diulang, bukan dipercaya)

Enam pemeriksa di luar repo (dipakai saat sapuan, bukan deliverable). Semua jalan di host dengan Python 3.13 dan membaca `docs/` apa adanya; tidak satu pun memotong kata atau melonggarkan pencocokan untuk membuat hasilnya hijau. Yang bisa berubah kalau dokumen berubah adalah **harapannya**, dan itu harus disengaja.

| Gate | Memperiksa | Sumber kebenaran yang dibandingkan |
|---|---|---|
| A | empat sumbu enum status sama di Doc 09/01/02 + nol nilai status mati di baris aktif | Doc 09 CHECK = §8.3 |
| B | sebelas runbook wajib terpetakan **dan** berkasnya ada | Blueprint §15 = §8.2 |
| C | register event tunggal + pemakaian nama di luar register | Doc 12 §6.2 = §8.4 |
| D | nol nama tabel/objek mati di baris aktif (rename §1/§4.1 dikecualikan dengan konteks) | Doc 11 §1, §4.1 |
| E | cakupan Blueprint: §16 test 1–10, §19 DoD 10, §18 ADR 10, §20 enam kandidat, §4 menu+role, §9 antrian, §11 bucket, §2.2 domain; **jumlah berkas ADR = 12 (001–012 hadir semua)** dan topik inline tidak salah pasang | Blueprint §4/§9/§11/§16/§18/§19/§20 + `docs/adr/` |
| F | nol sitasi nomor bagian yang menunjuk bagian tidak ada | judul `##`/`###` nyata di tiap dokumen |
| G | nol homoglyph non-Latin (CJK/hangul/Sirilik/fullwidth) di seluruh `docs/` | — |
| **H** | **kepemilikan tabel**: Doc 09 §19 (jumlahnya diproduksi `verify_schema.py`, satu tabel satu modul) == Doc 01 §3.3 **dan** pohon `app/Modules/` §3.1 tidak menyebut tabel milik modul lain | Blueprint §3 (label modul) + §19 = §8.5 |
| **S1–S3** | **kelengkapan Blueprint**: 14 grup + 87 submenu §4 verbatim ada di Doc 06 §2.2; 60 tabel minimum §6 ada di Doc 09 §19 kecuali tiga pengecualian yang wajib tertulis di §8.5; 18 jalur endpoint §7 ada di Doc 12 | berkas Blueprint itu sendiri, dibaca langsung (bukan daftar tangan) |
| **I** | **dokumen = repo**: 56 entri pohon Doc 01 §3.1 benar-benar ada di disk; nol sisa nama layout lama (`commerce-*`, `infrastructure/docker`) di seluruh berkas; tujuh nama antrian sama di `docker/compose.yaml` **dan** `QueueRouting::QUEUES`; enam bucket §9.2 ada di `init-buckets.sh` **dan** `filesystems.php`; nol root `package.json`/config workspace dengan `file:` kedua app menunjuk paket yang namanya cocok; basename migrasi sesuai M-3, unik, dalam band, dan modul pemilik = direktorinya; 18 jalur §7 masuk `openapi.yaml`; 22 modul = Doc 01 = Doc 09 = `app/Modules/` | pohon Doc 01 §3.1 + Doc 11 §8.1/§8.5 + ADR-010 + berkas nyata di repo |

Perintahnya: `verify_schema.py` (jumlah blok tabel, graf FK asiklik, nama lama tidak tersisa, tabel §20 terdokumentasi), `audit_sweep3.py` (gate A–H), `audit_blueprint_coverage.py` (29 daftar COVER + S1–S3 + scan kontradiksi), `check_layout.py` (gate I), `scan_unicode.py` (gate G berdiri sendiri, dipakai per-berkas), `list_dead_cites.py` (gate F). Verdict yang diharapkan: `LULUS — semua pemeriksaan A–H bersih`, `LULUS — COVER (29 daftar) + S1-S3 + KONTRADIKSI bersih`, `LULUS — <n> tabel, konsisten dengan Doc 11`, `LULUS — pohon Doc 01 §3.1 = filesystem, dan tujuh aturan layout ditegakkan di berkas`, `selesai: 0 kemunculan mencurigakan`, dan 0 temuan.

**`<n>` sengaja tidak ditulis di sini.** Jumlah blok tabel dan jumlah tabel milik kita diproduksi ulang oleh `verify_schema.py` dari Doc 09 §19 (blok = tabel milik kita + tabel bawaan queue); register ini tidak mengulang angkanya karena salinan angka adalah cara paling mudah membuat dokumen bohong setelah skema berubah. Yang boleh berubah di pemeriksa adalah **harapan eksplisitnya** (mis. konstanta jumlah di gate H), dan perubahan itu harus disengaja serta disebut di commit — bukan dilonggarkan pencocokannya. Keputusan §4.4 A–F mengubah jumlah tabel, jadi gelombang knowledge 2026-11-18 wajib memperbarui Doc 09 §19, Doc 01 §3.3, dan konstanta gate H **bersamaan**, lalu menjalankan ulang keenam pemeriksa.

Sejak scaffold dipasang, `scripts/verify-docs.sh` menjalankan semuanya sekaligus dan **memperluas cakupan gate G ke seluruh berkas teks repo** (kode, config, skrip), bukan hanya `docs/`: mode kegagalan homoglyph tidak memilih direktori, dan ia ditemukan pertama kali di prosa dokumen hanya karena itu satu-satunya yang ada saat itu.

Aturan turunannya masuk Doc 10 §12 dan Doc 03 DoD: **setiap angka, nama enum, atau jumlah runbook yang dikutip di satu dokumen harus bisa diproduksi ulang oleh skrip**, kalau tidak, dokumen tidak boleh menyebutnya "sudah diverifikasi". Gate ini bukan artefak sekali pakai — tiap sapuan dokumen berikutnya dijalankan **setelah** perubahan, bukan sebelum, dan tiap temuan baru menambah gate (H lahir dari sapuan ketiga, 2026-11-17: tiga modul punya dua nama dan tiga tabel punya dua pemilik di dokumen yang sama).
