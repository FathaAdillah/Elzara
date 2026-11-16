# Dokumen 03 — Rencana Sprint

Tanggal: 2026-11-16
Tim: 1–2 dev (full-stack) + klien sebagai penyedia konten/keputusan.
Cadence: sprint 2 minggu. Baseline awal 7 sprint ≈ 14 minggu; setelah temuan Doc 05/06/07 menjadi **8 sprint + 1 sprint tambahan ≈ 18 minggu** (rincian §10.5) — masih dalam batas buffer bila dua potong yang ditawarkan §11 disetujui.
Metodologi: tiap sprint = sub-proyek sendiri → spec pendek → plan implementasi → build → review → staging demo.

## 0. Cara pakai dokumen ini

Dokumen ini adalah roadmap + gate, bukan pengganti plan implementasi. Setiap sprint dimulai dengan satu spec (`docs/specs/<sprint>-<topik>.md`) yang maksimal 2 halaman, disetujui klien, lalu dipecah ke task. Kapasitas diasumsikan 6–8 jam efektif/dev per hari, dikurangi rapat klien dan pekerjaan konten.

Definition of Done (berlaku semua sprint):
1. Feature test hijau untuk happy path + minimal 2 alur pengecualian;
2. tidak ada lint/static-analysis error baru;
3. berjalan di `docker compose up` fresh (tidak bergantung state dev lokal);
4. migration backward-compatible + rollback didokumentasikan — ceklis khusus di **Doc 10 §11**, penjagaan otomatis di **Doc 10 §10** (C1–C8);
5. entri changelog + update dokumen terkait (arsitektur/bisnis bila kontrak berubah);
6. demo di staging + catatan keputusan klien yang muncul;
7. tidak ada kredensial/rahasia di repo; tidak ada aset/konten pihak ketiga.

## 1. Sprint 0 — Fondasi, DevOps, design system (minggu 1–2)

| ID | Task | Est | Catatan |
|---|---|---|---|
| 0.1 | Init repo Laravel 12 + Inertia + React + TS + Tailwind v4, struktur modul (§3 arsitektur) | 1,5 h | `app/Modules/*` + loader |
| 0.2 | Docker dev (compose.dev): app, nginx, postgres, redis, meilisearch, minio, mailpit, adminer; Makefile `up/migrate/test` | 1 d | one-command onboarding |
| 0.3 | CI: lint, phpstan/larastan, unit+feature test (Postgres service), build image | 1 d | gate merge |
| 0.4 | CD: build+push registry, deploy SSH compose, maintenance mode, rollback by tag | 1 d | staging dulu |
| 0.5 | Design token + 3 preset tema + komponen dasar (button/input/card/badge/modal/toast/grid) | 1,5 d | lihat Doc 04 |
| 0.6 | Identity: register, login session, reset password, role+permission staff, 2FA admin | 1,5 d | |
| 0.7 | Konvensi: domain exception, ApiProblem, state-machine runner, `outbox` + dispatcher, event bus | 1,5 d | pondasi semua sprint |
| 0.8 | Seeder data demo orisinal (10 produk fiktif, 2 batch, 1 kuotasi) + factories | 1 d | dipakai semua test |
| 0.9 | Keputusan klien: gateway pertama, kurir, B-PO-1..14, daftar aset brand | ( paralel ) | **blocker Sprint 2** |
| 0.10 | Baseline keamanan edge: header HSTS/XFO/nosniff/Referrer/Permissions + CSP dengan `script-src 'self'`, diuji otomatis | 0,5 d | daftar persis & sumbernya di Doc 08 §6; jangan tiru celah CSP situs sumber |

Gerbang akhir: `make up` → login admin → buka storefront demo → lihat event tercatat di `order_events`/`outbox`.

## 2. Sprint 1 — Katalog, pencarian, halaman produk (minggu 3–4)

Task: skema `products/variants/categories/collections/media` + varian bertingkat · CRUD admin + bulk import CSV · upload media ke MinIO + re-encode + konversi WebP · atribut & filter facet · sync Meilisearch (job) + sinonim fandom · storefront: home (blok statis dulu), listing kategori/koleksi, PDP dengan varian picker + badge mode + stok · SEO meta + sitemap + OG image · audit aksesibilitas komponen produk.
Terima: customer bisa mencari nama karakter/fandom dengan salah ketik → filter "Pre-order" → pilih varian → harga/stok/badge/ETA berubah sinkron; reindex <60 s setelah edit. (Data uji memakai nama karakter fiktif dari seeder demo kita sendiri.)
Risiko: varian bertingkat 3+ level bikin query lambat → solusi: `variant_option_values` + index komposit + cache per produk; jangan normalisasi berlebihan.

## 3. Sprint 2 — Cart, checkout, payment adapter (minggu 5–6)

Task: `carts` + TTL + validasi rule · pemisahan baris per mode & pengelompokan ETA · alamat + validasi wilayah/kodepos · `ShippingRateProvider` (driver Biteship/RajaOngkir/Manual) + cache tarif · `PaymentProvider` interface + driver Midtrans (atau sesuai 0.9) + fake driver untuk test · state machine `pending_payment → paid/expired` + intent expiry job · invoice PDF + halaman order · E2E Playwright RTS · rekonsiliasi harian v1 + daftar kerja reconcile · contract-test suite driver (wajib lolos Midtrans + Fake).
Terima: RTS checkout end-to-end di sandbox gateway, webhook replay 3× hanya 1 transisi, gagal bayar → cart utuh.
Blocker: tanpa merchant account → pakai driver Fake + sandbox; jangan tunda sprint karena account (itu jalur paralel klien).

## 4. Sprint 3 — Orders, warehouse ops, tracking, notifikasi (minggu 7–8)

Task: admin order (filter/detail/transisi dengan reason/cancel/refund parsial) · `shipments` + pick list per batch/RTS · label + input resi massal (CSV) · `TrackingProvider` + poller job + exception detection (`returned_to_sender`) · modul Notification: template email + driver WA (provider dari 0.9) + preferensi per user + subscription event · halaman "Pesanan Saya" + timeline + tracking · retur/refund request dengan foto · metrik + Sentry + dashboard queue/webhook lag.
Terima: order RTS dari bayar sampai delivered lewat staging, dengan resi dan notifikasi nyata; CS bisa proses 1 retur tanpa akses DB.
Risiko: WA provider butuh template approval → kirim pengajuan template di awal Sprint 3, fallback email-only jangan sampai memblokir demo.

## 5. Sprint 4 — Pre-order: batch, deposit, pelunasan, GRN (minggu 9–10) ⚠️ sprint paling berisiko

Task: `po_batches` (periode, ETA, deposit rules B-PO-1, due policy) · badge/countdown PO di storefront + batas qty per akun · reservasi slot batch (bukan stok fisik) + job expire · intent deposit + intent pelunasan (U3) + reminder scheduler H-7/H-1/due · status `awaiting_batch → on_hold → expired` · admin: buat/tutup batch, GRN saat tiba (`Inventory.CommitStock`), partial GRN per item, batalkan batch + refund massal, report posisi (deposit masuk vs pelunasan kurang vs hangus) · penggabungan ongkir per shipment (B-PO-7) · test uang: property test pembulatan deposit/pelunasan/voucher/poin, rekonsiliasi ledger.
Terima: 1 batch penuh diuji — deposit, telat bayar (on_hold), tiba sebagian, lunas, kirim, dan satu batch dibatalkan dengan refund; ledger balance 0 selisih.
Gate klien: kebijakan deposit & teks T&C harus sudah final sebelum sprint ini (Doc 02 §7).

## 6. Sprint 5 — Request Order / kuotasi privat (minggu 11–12)

Task: `quote_requests/quotes/quote_items` + ULID non-enumerable · enforcement visibility (Policy + scope + tidak masuk index/search/sitemap) + audit test · admin: antrian pengajuan, terbitkan penawaran (harga/unit, qty, mode, berlaku s/d), tolak dengan alasan · reservasi stok quote + release saat expired/declined · accept → `Orders.PlaceOrder(from_quote)` · halaman "Pengajuan Saya" + notif · SLA timer + escalation.
Terima: user A tidak bisa membaca/menebak URL quote user B (test eksplisit), accept quote menghasilkan order dengan harga yang dikunci saat penawaran.
Risiko: godaan "request semua orang bisa lihat" — pertahankan privat, itu janji ke buyer.

## 7. Sprint 6 — Promo, loyalitas, konten, review (minggu 13–14)

Task: voucher (min belanja, per kategori/koleksi/produk, per-user, periode, kuota) + validasi dengan alasan · poin & tier + penebusan di checkout + ledger poin · kampanye/banners + homepage block editor (Content) + nav dinamis · review & rating (hanya pembeli terverifikasi, moderasi admin, foto) · FAQ + halaman panduan dari Content · wishlist + "kabari saya" stok kembali · AB-friendly copy slots (lihat Doc 04 §6).
Terima: stackable rules teruji (voucher + poin + diskon batch), editor konten dipakai klien sendiri tanpa dev.

## 8. Sprint 7 — Hardening, reporting, launch (minggu 15–16, + buffer)

Task: laporan (penjualan, margin per varian, umur piutang pelunasan, stok menipis, export CSV) · load test 100 order/menit + burst webhook · security pass (rate limit, header, authorization matrix, dependabot, secret rotation, backup restore drill nyata) · performa (LCP mobile, bundle budget, image pipeline, query N+1) · SEO final + analytics + disclaimer → konsensus klien · migrasi data produk/akun dari klien (bukan scraping) · runbook insiden + on-call sederhana · UAT dengan klien (skrip 12 skenario dari Doc 02 §2–§4) · go-live checklist · pendampingan (hypercare) 2 minggu pasca-launch.

## 9. Jalur paralel (tanggung jawab klien, jangan masuk sprint dev)

Merchant account + SKA/SNI gateway · kontrak/akun kurir + tarif nego · akun provider WA · pasokan konten: nama brand, logo, foto produk, deskripsi, kebijakan retur, T&C deposit, halaman panduan, legal/PDP · persetujuan B-PO-1..14 (Sprint 0–1) · penanggung jawab operasi harian (siapa input resi, siapa jawab penawaran) · keputusan domain/SSL.

## 10. Risiko jadwal

| Risiko | Dampak | Mitigasi |
|---|---|---|
| Jalur PO ternyata lebih kompleks dari asumsi | +1–2 sprint | Sprint 4 punya gate klien; kalau molor, Sprint 5 (quotes) boleh digeser |
| Akun gateway/kurir/WA lama disetujui | Sprint 2/3 macet | driver Fake + Manual sejak awal; integrasi nyata jadi task terpisah |
| Klien minta "sama persis" dengan situs referensi | rework UI | Doc 04: tema = token, dan batas Doc 01 §13 sudah disepakati di awal |
| 1–2 dev + UAT klien lambat | jadwal ambles | buffer 2 sprint, demo tiap 2 minggu, keputusan klien punya deadline 48 jam |
| Godaan refactor ke microservice | kehilangan velocity | Doc 01 §9: ekstraksi butuh trigger + persetujuan |

## 10.5 Revisi sprint akibat temuan observasi (Doc 05/06 + ADR-002)

| Sprint | Perubahan | Est |
|---|---|---|
| 0 | + `localizations` (ID/EN sejak awal) dan `feature_flags` + kerangka `settings` admin; semua string UI lewat resource, tidak ada copy hardcode | +1 d |
| 1 | + `batch_offers`: PDP terikat batch (jendela buka/tutup, kuota, harga snapshot, asal negara); editor varian 2 sumbu (mis. karakter × ukuran) dengan kuota per sel | +1,5 d |
| 2 | **Billing** jadi modul nyata: `invoices` (tipe + jatuh tempo) + halaman "tagihan saya" (daftar, filter tipe, aksi bayar, cek jatuh tempo), ledger berkey invoice · **RS-4 masa berlaku harga**: `price_lock_expires_at` di cart/quote + ambang re-konsen saat nominal invoice naik + layar "lapor bayar ganda/dibelum-lunas" (F11) | +1,5 d → +2,5 d |
| 3 | + `tracking_lanes`/`lane_stages` (4 jalur) dengan pemisahan `money_status` vs `fulfillment_status`; status manual per tahap sebagai jalur sah untuk kurir tanpa API · **jalur procurement RS-5**: siklus kode single-use + antrian verifikasi + penautan shipment (bisa jadi flag, tapi skema & state dibuat sekarang) | +1 d → +2,5 d |
| 4 | + job terbit invoice `intl_fees` saat GRN (dengan estimasi min/maks per batch) + laporan umur piutang per tipe + eksekusi B-PO-3 di atas invoice `deposit` · **RS-1 gerbang siap kirim (3 kondisi AND)** dan **RS-2 pelepasan batch per buyer, bukan serempak** — ini mengubah logika inti Sprint 4, jadi tulis test-nya lebih dulu | +1,5 d → +3 d |
| 6 | + **Drops** (jadwal, klaim 1× per akun per item, laporan) dan **UGC moderation** (antrean ulasan, gerbang "hanya order selesai", moderasi lampiran user) | +1,5 d |
| 7 | + checklist konfigurasi flag procurement (B-PO-9) agar keputusan klien tercatat, bukan tersirat dari kode mati | +0,5 d |
| backlog | + **Procurement atas-nama** sebagai sprint sendiri (±4–5 hari) hanya kalau klien memakai jalur itu; + asisten AI kontekstual (order/invoice/tracking) ±3 hari dengan guard privasi | — |

Total dampak ke jalur kritis ≈ **+11 hari kerja ≈ 1,5 sprint** (rincian: Doc 05/06 + aturan terkonfirmasi Doc 07 RS-1…RS-6) → jadwal menjadi **8 sprint + 1 sprint tambahan ≈ 18 minggu** (+buffer 2 sprint). Karena ini melewati buffer, keputusan ada di tangan Anda: (a) terima 18 minggu, (b) potong Drop & moderasi ulasan dari MVP (Doc 03 §11), atau (c) matikan jalur procurement atas-nama di MVP bila klien tidak memakainya. Jangan dipaksakan 16 minggu sambil membawa semuanya — itu cara paling andal untuk meleset.

## 11. Prioritas kalau harus memotong (urutan dari paling akhir dipotong)

1. **MVP hard**: Sprint 0–4 (katalog, cart, bayar, kirim, PO) — tidak bisa dipotong, ini inti bisnis.
2. Request Order (Sprint 5) → bisa launch tanpa fitur ini kalau klien sanggup kelola penawaran lewat WA/manual, tapi wajib ada catatan "sedang kami proses" di storefront.
3. Promo/loyalty (Sprint 6) → potong voucher multi-rule, sisakan fixed-amount voucher.
4. Review terverifikasi, "kabari saya", AB slots → backlog pasca-launch.
5. Reporting lanjutan → export CSV manual dulu.

## 12. Post-launch backlog (bulan 3+)

Driver payment kedua via contract test · multi-bahasa (EN) · app mobile PWA push · rekomendasi "sering dibeli bareng" · gudang multi-lokasi · program reseller/tier harga · ekstraksi service pertama (`Search`/`Notification`) bila trigger Doc 01 §9 terjadi.
