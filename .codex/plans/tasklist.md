# Tasklist milestone Elzara

Snapshot awal 2026-11-19. Bukti runtime lokal diperbarui 2026-11-20. Sumber scope: [Doc 03](../../docs/03-sprint-plan.md), [plan Sprint 0](2026-11-17-sprint-0-fondasi.md), [plan Dependensi/Auth/RBAC](2026-11-18-dependensi-auth-rbac.md) dan [agen knowledge](../agents/knowledge.toml). Centang hanya jika bukti memenuhi kriteria pada baris itu. Jika sebuah bukti kehilangan validitas, buka kembali item terkait dan catat alasannya.

## Milestone proyek

| Milestone | Status | Bukti atau syarat penutupan |
|---|---|---|
| Phase 0 / Sprint 0 (0.1-0.17) | Fondasi lokal berjalan, parsial | Laravel, storefront Next.js, dan admin Nuxt 4 berjalan di Compose lokal. Tiga lockfile tersedia. Migrasi PostgreSQL, codegen, dan health lokal memiliki bukti. Pipeline penuh serta gerbang M1-M4 masih terbuka. |
| Phase 0 / Sprint 1 | Auth admin terverifikasi, parsial | Konfirmasi pemilik dan klien pada 2026-11-20 mencakup cookie sesi, CSRF, 2FA dan pemisahan guard. Lokasi artefak bertanda tangan masih menunggu kiriman. Login admin dan migrasi terkait terverifikasi secara lokal. Storefront auth dan RBAC masih terbuka. |
| Phase 1 / Sprint 2-5 | Belum terbukti | Katalog, checkout, stok, pembayaran, pengiriman dan gerbang concurrency masih memerlukan bukti implementasi sesuai Doc 03. |
| Phase 2 / Sprint 6-8 | Belum terbukti | UI preorder masih scaffold. Lifecycle batch, deposit, kuota dan procurement belum terverifikasi. |
| Phase 3 / Sprint 9-10 | Belum terbukti | Workflow pengajuan, sourcing dan kuotasi belum terverifikasi. |
| Phase 4 / Sprint 11+ | Belum terbukti | Hardening dan operasi lanjutan masih dalam rencana. |

### Gerbang gelombang aktif

- [ ] **M1 | Task 1-5** Migration-db. Hasilkan tiga lockfile, selaraskan manifest, lalu buktikan migrasi Identity/Audit serta jalur backfill di PostgreSQL 16. Fresh migration lulus pada PostgreSQL 16.15 (23 berkas, tanpa nomor ganda), dan ketiga lockfile tersedia. Instalasi Composer dari lockfile lulus di PHP 8.4. Publikasi config paket terverifikasi (P0.02). Keputusan Settings 0077, kompatibilitas Activitylog v5, dan pembuktian backfill legacy masih terbuka.
- [ ] **M2 | Task 6-10** Feature. Catat sign-off sebelum kode auth Sprint 1. Buktikan guard/sesi/CSRF, 2FA, storefront hybrid, RBAC, seeder, kontrak dan klien TS. OpenAPI dan klien TypeScript tersedia. Login admin dan 2FA terverifikasi secara lokal, storefront auth, RBAC dan seeder tetap terbuka.
- [ ] **M3 | Task 11-13** Testing. Buktikan Feature test terarah pada PostgreSQL bila dibutuhkan; controller menutup Deptrac B1-B6, enam gerbang dokumen dan drift nol. Perbaiki aturan inventaris dan auth CI yang sudah usang di `.github/workflows/contracts.yml`.
- [ ] **M4 | Task 14** Docker-rebuild. Sesudah M3, buktikan build ketiga image, Compose sehat, migrasi, seeder idempoten dan endpoint health. Stack HTTP lokal berjalan dengan layanan yang diharapkan sehat dan migrasi exit 0. Build produksi dijalankan melalui Dokploy setelah gerbang M1-M3 terpenuhi. Validasi domain dan TLS produksi serta bukti runtime masih terbuka.

## Urutan kerja dan serah terima

1. Pilih satu fitur dan ID checklist. Controller memastikan prasyarat, konflik rujukan, dan cakupan penulis.
2. Jika fitur bergantung pada DB, development meminta migration-db memeriksa migrasi Laravel dan, jika tersedia, hasil introspeksi PostgreSQL lokal. Migration-db menyerahkan nama tabel/kolom, tipe, nullable/default, indeks, FK, CHECK, urutan migrasi dan bukti aktual. Development mencocokkan model/query, validator, seeder, dan kontrak dengan hasil itu. Jika spesifikasi dan implementasi berbeda, catat konflik dan selesaikan di pemilik yang tepat.
3. Development menyerahkan titik masuk, perubahan perilaku, file, dan skenario penerimaan. Knowledge berjalan ketika ada dokumen terdampak, dengan kepemilikan berkas terpisah.
4. Testing menjalankan Feature test kelas/file/metode yang mencakup fitur yang baru dikerjakan. Untuk fitur frontend, pilih tes fitur/journey terkait jika tersedia. Jangan gunakan seluruh suite atau filter luas. Gerbang CI, arsitektur, audit dokumen dan release tetap tercatat untuk controller.
5. Setelah bukti tiap item gelombang dan verifikasi fitur lengkap, controller menugaskan docker-rebuild. Setelah keluaran aktual tersedia, tasklist-updater memperbarui kotak dan log. Status fase berikutnya mengikuti prasyarat, termasuk persetujuan atau lingkungan yang disebut di plan.

## Phase 0 | Sprint 0, fondasi

Rujukan terperinci: [plan Sprint 0](2026-11-17-sprint-0-fondasi.md), Task 1-20. Task 11-12 mengikuti amandemen [plan Auth/RBAC](2026-11-18-dependensi-auth-rbac.md). Gunakan Checklist knowledge di bawah untuk K1-K3.

- [x] **P0.01 | Task 1-2 / 0.1** Verifikasi image/toolchain, manifest, module loader dan startup ketiga layanan. Docker menjalankan PHP 8.4.28/Laravel 13.43.0 dan Node 22.23.5 untuk storefront Next.js serta admin Nuxt 4. ModuleServiceProvider mendeteksi 22 modul dan 21 lokasi migrasi modul ditambah Platform tanpa entri tak terduga. ModuleLoaderTest lulus (3 tes, 7 assertions). Health storefront dan admin mengembalikan status ok. API sesi anonim mengembalikan 401 Problem Details.
- [x] **P0.02 | Auth Task 1-2 / 0.17** Resolve lockfile web/admin di Node 22; resolve Composer PHP 8.4 sesuai Doc 11; pastikan paket dan ekstensi cocok, config paket dipublikasikan selektif, dan instalasi dari lockfile berhasil. Ketiga lockfile tersedia. Instalasi dari Composer lockfile lulus di PHP 8.4 dan Feature test Task 7 lulus kembali (5 tes, 45 assertions). Kedua frontend lulus npm ci dan typecheck dengan install-links dan lockfile sinkron. Config permission, activitylog, scramble, dan sentry ditambahkan secara selektif. Config sanctum yang ada dipertahankan. Boot production offline di Docker PHP 8.4.28 lulus dan versi paket cocok dengan lockfile. Scramble docs untuk tamu production mengembalikan 403 dan default PII Sentry false. Vendor migration dan lockfile tetap sama.
- [x] **P0.03 | Task 3 / 0.2** Verifikasi env dan startup Compose lokal melalui HTTP sesuai keputusan pengguna. Storefront `http://localhost:8088` dan dashboard `http://admin.localhost:8088` memakai bind `127.0.0.1:8088` ke port 80 tanpa mount sertifikat. `docker compose config --quiet` dan `nginx -t` lulus. Backend internal, koneksi Mailpit lokal, layanan yang diharapkan sehat, dua worker melayani tujuh queue, dan migrasi keluar dengan kode 0. Validasi TLS produksi tetap di M4.
- [ ] **P0.04 | Task 4-5 / 0.3, 0.5** Validasi OpenAPI 3.1, keberadaan path Blueprint serta operasi baru; perbaiki CI yang menolak skema sesi; hasilkan klien TS; jalankan drift dan buktikan pipeline lint/build. CI mengecek kehadiran masing-masing dari 18 path wajib Blueprint dan mewajibkan `securitySchemes`. Pemeriksaan memverifikasi 18 path wajib dari 33 path dan tiga skema keamanan yang diharapkan. Spectral melaporkan 0 error dan 16 peringatan. Klien TypeScript hasil generate ulang identik byte-per-byte. Run GitHub API 44709298358 gagal pada composer lint. Run contracts 44709296095 tidak memiliki job karena runner context tidak valid. Perbaikannya sudah diverifikasi pada sumber workflow. Workflow contracts 44736742817 hijau pada 4766dd1 untuk OpenAPI, drift, packages dan Compose. Run API 44838862849 lulus lint, unit, contract, dan integration termasuk concurrency. Job security berhenti karena composer audit membutuhkan dependensi terpasang. Workflow sudah memakai composer audit --locked dan hasil CI penuh terbaru masih ditunggu.
- [x] **P0.05 | Task 6 / 0.7a** Audit migrasi reliability yang ada; lengkapi tabel platform yang masih dibutuhkan dan cek urutan, tipe, FK, indeks, constraint, uniqueness serta PostgreSQL 16 fresh-run. Contract test `tests/Contract/FoundationMigrationTest.php` lulus lint dan uji terisolasi PHP 8.4.28/PostgreSQL 16 (1 tes, 43 assertions). Uji menjalankan migrasi 0001/0002/0010/0042/0079 secara berurutan dan memeriksa enam tabel platform/framework, tipe, indeks termasuk indeks parsial, UNIQUE (23505), CHECK (23514), FK (23503), cascade, dan rollback urutan balik. Fresh-run lengkap sebelumnya dan pemeriksaan katalog PostgreSQL lokal 16.16 juga lulus. Berkas migrasi tetap sama.
- [x] **P0.06 | Task 7 / 0.7b** Sambungkan Problem Details dan request ID ke bootstrap Laravel; verifikasi status, media type, ID yang sama di header/body dan penanganan respons non-API lewat Feature test terkait. Uji terarah `tests/Feature/Platform/ApiProblemTest.php` lulus di PHP 8.4.27, 5 tes dan 45 assertions. Respons sesi anonim pada stack lokal terkini tetap berupa HTTP 401 Problem Details.
- [ ] **P0.07 | Task 8 / 0.7c** Pasang transisi empat sumbu dan alasan perubahan. Buktikan jalur valid, penolakan transisi, dan preservasi state lewat pengujian fitur yang mengonsumsi runner. Lint PHP 8.4 lulus. Di PostgreSQL 16, Feature test terarah `FulfillmentLaneTransitionTest` lulus (2 tes, 6 assertions), meliputi penerimaan jalur marketplace dan penolakan RTS dengan status tetap. Sumbu lain dan audit masih terbuka. TransitionSink opsional memakai koneksi yang sama dan membawa reason. Uji terarah lulus (5 tes, 32 assertions) mencakup empat sumbu, penolakan, no-op, rollback dan lane Fulfillment. Dokumen diselaraskan. StateMachineLockTest di PostgreSQL 16 lulus (1 tes, 6 assertions) untuk lock timeout, stale state, dan preservasi state. Integrasi Orders/Audit aktual, matriks penuh, dan bukti DB CHECK yang bersumber dari enum masih terbuka.
- [ ] **P0.08 | Task 9-10 / 0.7d-f** Sambungkan outbox, scheduler, routing, retry dan dedup inbox. Buktikan side effect setelah commit, replay idempoten, penolakan payload beda dan batas kepemilikan modul. Kode lease outbox dan scheduler lulus lint. Dengan Composer lockfile dan PostgreSQL 16, `tests/Feature/Platform/OutboxDispatchTest.php` lulus (3 tes, 13 assertions): filter queue, lease kedaluwarsa, queue null tetap pending, dan lock koneksi kedua dilewati. Task 10 Step 5 selesai. InboxConsumer menjalankan efek pada pengiriman pertama, melewati replay untuk pasangan consumer/event yang sama, dan menjalankan efek untuk consumer berbeda. Rollback savepoint membatalkan efek yang gagal, status failed tersimpan, dan retry dapat dijalankan. Pengujian juga memverifikasi perebutan klaim di PostgreSQL. php artisan test --no-ansi tests/Feature/Platform/InboxConsumerTest.php lulus di PHP 8.4.28 dan PostgreSQL 16 terisolasi (4 tes, 37 assertions). Tabel inbox_events dan UNIQUE (consumer, event_id) sudah disediakan migrasi 0001. SELECT LIMIT 0 pada PostgreSQL lokal 16.16 berhasil. Pemilik menyetujui snapshot respons PostgreSQL pada 2026-11-21. Irisan replay HTTP Task 10 selesai. Migrasi forward 0080 menambah status, body TEXT, dan JSONB header yang diizinkan serta menjaga baris lama. Middleware mendukung alias operasi, guard, dan TTL. IdempotencyGuardTest dan IdempotencyLockTest di PostgreSQL 16 terisolasi lulus (6 tes, 84 assertions), mencakup byte dan ID yang sama tanpa cookie, request identik/berbeda, rollback dan cache 422, rollback penuh dan retry 503, kedaluwarsa, serta dua klien PostgreSQL. Integrasi route Orders, bukti lengkap side effect outbox, dan gerbang perilaku kepemilikan B1-B6 masih terbuka.
- [ ] **P0.09 | Task 11 / Auth Task 3-5** Cocokkan konflik sub-band sebelum memberi nomor; buat migrasi Identity/Audit, relasi dan constraint; siapkan jalur expand/backfill/validate/contract; serahkan pemetaan skema aktual ke development. Lengkapi Settings/Content pada slot kanoniknya. Di PostgreSQL 16.15 sekali pakai, Laravel 13.43.0 pada PHP 8.4.27 mencatat `Ran` untuk 23 berkas migrasi dan `Nothing to migrate` pada pengulangan. Pemeriksaan skema mengonfirmasi CHECK flag dan citext pada pengguna, `recovery_hashes` bertipe `text[]`, target dan request audit wajib serta penyedia Google/X. Nomor tidak duplikat. Kontrak nullability Settings 0077 (Doc 09 dan plan seeder) belum diputuskan dan backfill legacy belum dijalankan. Audit kode menemukan Activitylog v5 memerlukan kolom `attribute_changes` yang belum tersedia pada migrasi 0050. Migrasi forward 0054 menambahkan JSONB nullable untuk Activitylog v5. Uji PostgreSQL 16 lulus (1 tes, 11 assertions) mencakup logger nyata, baris lama dan rollback. Harness regresi menjalankan 24 jalur migrasi di PostgreSQL 16 sekali pakai. Penerapan live, Settings 0077 dan backfill legacy masih terbuka.
- [ ] **P0.10 | Task 19 / 0.13-0.15** Selaraskan threat model, ADR dan keputusan klien. Rekam sign-off pemilik dan klien sebelum implementasi auth Sprint 1. Lacak gateway, kurir, WA, aset brand dan kebijakan PO. Rekonsiliasi host admin terpisah dengan ADR-007 dan threat model (cookie dan CSRF per origin), lalu catat sign-off. Sebelum mengintegrasikan audit auth, selaraskan kontrak `X-Request-Id` 1-128 karakter dari Doc 12 dengan `audit_logs.request_id varchar(26)` dari Doc 09. Catatan threat model bagian 6 merekam konfirmasi pemilik dan klien pada 2026-11-20 untuk cookie sesi, CSRF, 2FA dan pemisahan guard. Lokasi artefak bertanda tangan masih menunggu kiriman.
- [ ] **P0.11 | Task 12 / Auth Task 6-8** Setelah skema dan sign-off tersedia, buat dua guard/sesi, CSRF, login operator dengan 2FA, storefront email/Google/X, linking terverifikasi dan policy kepemilikan. Login admin berjalan: password menerbitkan challenge 5 menit, TOTP atau recovery code menuntaskan sesi, dan logout mencabutnya. Perintah provisioning operator tersedia. Migrasi lokal 0042, 0048, dan 0074 berstatus Ran. Feature test AdminLoginTest di PHP 8.4 dan PostgreSQL 16 lulus (3 tes, 97 assertions). Build lokal API dan admin sehat, nginx -t dan reload lulus. Chrome membuktikan challenge tanpa sesi, TOTP, overview setelah reload, serta pencabutan sesi dan redirect saat logout. Storefront auth dan RBAC masih terbuka.
- [ ] **P0.12 | Auth Task 9-10** Buat bundel permission, direct grant, scope dan audit bypass; integrasikan alur web/admin, OpenAPI dan codegen; buktikan perbedaan akses dan sesi lewat Feature test.
- [ ] **P0.13 | Task 13 / 0.9** Siapkan enam bucket, presigned PUT publik, finalize, validasi MIME/magic-byte, CORS dan URL privat. Uji upload/finalize dan penolakan objek yang tidak sah.
- [ ] **P0.14 | Task 14 / 0.10** Buat locales ID/EN, flag, setting admin dan kill switch checkout. Buktikan perubahan flag memengaruhi alur checkout terkait.
- [ ] **P0.15 | Task 15 / 0.11** Buat ReferenceSeeder idempoten dan DemoSeeder dev/staging, factory dan aset orisinal. Jalankan ReferenceSeeder dua kali pada DB dev dan periksa perubahan baris.
- [x] **P0.16 | Task 16 / 0.8** Lengkapi token/preset, Button/Input/Card/Badge/Modal/Toast/Grid; hubungkan ke kedua app dan periksa aksesibilitas komponen yang digunakan. Storefront memakai primitif React, admin memakai Nuxt UI dengan token yang sama. Uji komponen dan kontras WCAG AA lulus; kedua image Node 22 dibangun dan menghidangkan preset `brand` dalam smoke HTTP lokal.
- [ ] **P0.17 | Task 17 / 0.16** Selaraskan header edge dan CSP tiap app; verifikasi header dan jalur publik/admin dari entry point.
- [ ] **P0.18 | Task 18 / 0.12** Sambungkan log JSON dan korelasi request/job, metrik/trace, backup off-node dan prosedur restore; dokumentasikan hasil drill.
- [ ] **P0.19 | Auth Task 11-14 + K1-K3** Jalankan Feature test terarah, tutup drift/gerbang dokumen dan serahkan bukti skema. Setelah syarat M1-M3 selesai, rebuild tiga image; buktikan Compose, migrasi, seeder dan health lokal. Build tiga image produksi melalui Dokploy dengan `docker/compose.yaml`, domain diarahkan ke service `nginx:8080`. Startup Compose lokal memakai override `docker/profiles/local.yaml` dan dicatat terpisah.
- [ ] **P0.20 | Task 20 / 0.4** Siapkan CD staging per app, registry/tag, maintenance, migrasi kompatibel dan rollback; catat bukti deployment dan restore di lingkungan yang diotorisasi.

### Sprint 1 | Kontrak final dan lingkungan

- [ ] **P0.21 | 1.1-1.2** Pisahkan akses publik/admin/internal; selesaikan validasi, cursor pagination, UTC/tampilan zona, dan rate limit. Verifikasi jalur akses dan respons yang berubah.
- [ ] **P0.22 | 1.3-1.4** Isolasi local/staging/production; verifikasi kompatibilitas expand/contract; selesaikan permission per aksi di server setelah sign-off dan uji scope, direct grant serta audit.
- [ ] **P0.23 | 1.5-1.6** Sediakan harness concurrency dengan fixture stok/kuota dan Playwright untuk RTS, PO, dan Request Order. Simpan artefak untuk skenario yang sudah tersedia.
- [ ] **P0.24 | 1.7-1.9** Ukur baseline katalog/checkout di staging yang diotorisasi; lengkapi runbook callback, replay, queue dan kill switch; catat keputusan yang diperlukan untuk gateway/Waiting Room.
- [ ] **P0.25 | 1.10** Catat sign-off threat model dan keputusan IdP/SSO. Tutup Phase 0 setelah gerbang Sprint 0-1 terpenuhi.

## Phase 1 | RTS MVP

### Sprint 2 | Katalog, stok dan media

- [ ] **P1.01** Migrasi katalog, varian/opsi, kategori, brand, koleksi, daftar harga, media, gudang, stok per gudang/SKU dan ledger. Verifikasi CHECK kuantitas nonnegatif dan hitung unavailable.
- [ ] **P1.02** Buat CRUD admin, import CSV tervalidasi, sinkronisasi indeks Elasticsearch Basic lewat job. Buat `multi_match` dengan fuzziness, analyzer prefiks, filter/facet keyword, dan sinonim eksplisit. Verifikasi pencocokan, filter, sinonim, serta hasil indeks saat reindex. Buktikan perubahan indeks di bawah 60 detik sesuai rencana.
- [ ] **P1.03** Buat home, listing, PDP, pilihan varian, badge/ETA/available-to-sell, SEO/sitemap/OG dan aksesibilitas. Uji pencarian salah ketik, filter PO dan harga/stok sinkron.

### Sprint 3 | Checkout dan pembayaran

- [ ] **P1.04** Buat cart/session dengan TTL, pengelompokan mode/ETA, validasi alamat dan provider tarif dengan cache.
- [ ] **P1.05** Buat reservasi kondisional atomik, expiry worker, idempotency key, payment intent dan driver Fake serta gateway yang disetujui.
- [ ] **P1.06** Verifikasi signature dan dedup webhook, ACK lalu proses async, invoice PDF, halaman order, rekonsiliasi harian dan checkout RTS. Buktikan replay tunggal dan batas 10 reservasi untuk 1.000 checkout dengan stok 10.

### Sprint 4 | Fulfillment dan retur

- [ ] **P1.07** Buat admin order, biaya/penyesuaian, riwayat status, partial refund, shipment/pick list, label dan pengisian resi.
- [ ] **P1.08** Buat tracking provider dan poller, deteksi eksepsi, preferensi notifikasi/email/WA, serta halaman Pesanan Saya dengan timeline.
- [ ] **P1.09** Buat retur dengan foto, refund idempoten dan metrik antrean/webhook. Buktikan order delivered, retur dari UI dan satu payout saat retry.

### Sprint 5 | Concurrency dan pengecualian uang

- [ ] **P1.10** Implementasikan paid-stock exception dengan SLA, audit dan tindakan refund/realokasi/manual. Sambungkan rekonsiliasi provider.
- [ ] **P1.11** Verifikasi reservasi, repeat idempotency, webhook duplikat/out-of-order, race expiry, pemulihan crash, burst load dan nol oversell pada iterasi yang dipersyaratkan.
- [ ] **P1.12** Tutup performa, keamanan dan runbook refund, object storage, DB failover dan supplier; sinkronkan state di Doc 01 dengan implementasi.

## Phase 2 | Pre-Order / Group Order

### Sprint 6 | Batch dan kuota

- [ ] **P2.01** Migrasi batch/item/enrollment, kebijakan deposit/due, jadwal server, ETA, batas per akun dan mode pengiriman.
- [ ] **P2.02** Buat admin lifecycle, reservasi/expiry kuota, closing atomik, cutoff, idempotent close dan snapshot accepted qty dalam protokol lock yang sama.
- [ ] **P2.03** Buat landing/countdown dan indikator kuota. Buktikan maksimal 50 dari 500 enrollment, penolakan saat closing dan kewajiban snapshot.

### Sprint 7 | Deposit dan pelunasan

- [ ] **P2.04** Finalisasi kebijakan deposit/T&C. Buat intent deposit/pelunasan, reminder, overdue/on-hold/expiry, invoice per tipe dan ledger deposit hangus.
- [ ] **P2.05** Buat gerbang siap kirim RS-1, pelepasan per buyer RS-2, agregasi ongkir dan pembulatan konsisten.
- [ ] **P2.06** Verifikasi batch normal, telat bayar, tiba sebagian, refund massal dan ledger seimbang termasuk dua buyer dengan status berbeda.

### Sprint 8 | Procurement dan inbound

- [ ] **P2.07** Buat pengadaan dari batch tertutup, supplier/PO/invoice, GRN parsial, event GoodsReceived dan entitas impor minimum.
- [ ] **P2.08** Buat invoice intl_fees dari GRN, estimasi, ageing piutang, dashboard batch, exception queue dan notifikasi perubahan ETA.
- [ ] **P2.09** Buktikan alur inbound hingga siap kirim dan rekonsiliasi terhadap faktur supplier.

## Phase 3 | Request Order

- [ ] **P3.01 | Sprint 9** Migrasi request ber-ULID dan opsi sourcing. Buat form, moderasi, policy/visibility privat, signed attachment dan fallback saat object storage mati.
- [ ] **P3.02 | Sprint 9** Buat antrean sourcing, estimasi kurs/biaya, SLA dua hari kerja dan eskalasi. Uji akses lintas pelanggan dan pengajuan tanpa lampiran saat storage mati.
- [ ] **P3.03 | Sprint 10** Buat quotation/version/item, snapshot kurs/harga, expiry, reservasi stok serta release.
- [ ] **P3.04 | Sprint 10** Buat accept idempoten, tolak versi basi dengan 409, konversi ke order/invoice, riwayat versi, dan tautkan fulfillment RTS atau PO.
- [ ] **P3.05 | Sprint 10** Uji dua accept paralel menghasilkan satu order, retry tanpa invoice ganda, versi lama ditolak dan harga versi diterima terkunci.

## Phase 4 | Skala dan operasi lanjutan

- [ ] **P4.01** Setelah hasil k6, Q-6 dan ADR-009, konfigurasi Waiting Room/rate limit origin dan buktikan kapasitas terjaga dalam pengujian terotorisasi.
- [ ] **P4.02** Setelah Q-5 dan ADR-011, implementasi alokasi multi-gudang dan uji reservasi lintas gudang.
- [ ] **P4.03** Setelah Sprint 8 dan B-PO-13, otomatisasi landed cost, customs dan forwarder; buktikan total alokasi sama dengan faktur.
- [ ] **P4.04** Lengkapi reporting read-only, ekspor, cohort/margin/aging, refund lanjutan, receivables dan settlement.
- [ ] **P4.05** Lengkapi outbox/inbox lintas domain; ukur fan-out/throughput sebelum memutuskan broker; ekstrak Notification, Search, lalu Catalog sesuai trigger dan persetujuan.
- [ ] **P4.06** Ukur SLO, terapkan autoscaling sesuai kebutuhan, jalankan latihan DR dan review keamanan eksternal.

## Checklist knowledge

### K1 | Skema dan arsitektur

- [x] **K1.1** Perbarui blok auth/RBAC Doc 09. Auditor skema lulus dan melaporkan tabel tambahan serta bentuk `users` sesuai Doc 11. Field recovery dan request ID tercantum di Doc 09.
- [x] **K1.2** Cocokkan tally Doc 09 bagian 19 dengan Doc 01 bagian 3.1/3.3. Parser, gate H, dan audit layout menyatakan cocok.
- [ ] **K1.3** Catat keputusan controller mengenai perubahan konstanta gate H, bila diperlukan. Gate H saat ini cocok. Riwayat perubahan konstanta eksternal belum terverifikasi.
- [x] **K1.4** Tutup gerbang skema dan A-H. Pemeriksa skema lulus. Setelah penyesuaian redaksi Doc 11 dan perluasan runbook, audit_sweep3 mencetak verdict LULUS untuk A-H.

- [ ] **K1.5** Rekonsiliasi algoritme recovery code dengan controller. Doc 09 menetapkan Argon2id, sementara Task 7 dan agen feature menetapkan SHA-256.

### K2 | Matriks admin dan kontrak auth

- [ ] **K2.1** Samakan matriks permission Doc 06 dengan Doc 11 dan bundel aktual Task 9, termasuk grant langsung, scope dan pencabutan cache. S1 lulus untuk struktur menu. Bundel aktual masih menunggu M2.
- [ ] **K2.2** Selaraskan Doc 12, OpenAPI dan klien TS. Dokumen sudah mencatat cookie, CSRF, metadata izin, aktor dan endpoint auth. Audit layout mengonfirmasi semua path Blueprint wajib. Validasi kontrak dan drift masih terbuka.
- [ ] **K2.3** Jalankan coverage Blueprint dan layout. Cakupan Blueprint lulus. Layout I-6 masih terbuka karena pemeriksa eksternal menghitung pemilik modul sebagai Infrastructure pada jalur migrasi kanonik (C:/temp/check_layout.py:147). Pemeriksaan independen: 23 migrasi dengan pemilik dan rentang valid, tanpa duplikat.

### K3 | Migrasi, rencana sprint dan runbook RBAC

- [x] **K3.1** Pertahankan konteks perubahan pivot pada Doc 10 bagian 8 dan selaraskan sub-band dengan D-DR-1. Bagian 8 memakai pivot paket dan bagian 4.3 mencatat basename migrasi fresh yang aktual. Pemeriksaan Unicode dan sitasi lulus.
- [x] **K3.2** Dokumentasikan prosedur pencabutan hak dan pemberian bypass manual dengan audit di runbook. `PermissionRegistrar` tercantum di Doc 10. Runbook dan indeks sudah tersedia di [runbook RBAC](../../docs/runbooks/rbac-access-changes.md) dan [indeks runbook](../../docs/runbooks/README.md). Uji runtime staging masih terbuka.
- [x] **K3.3** Tautkan task auth/RBAC di Doc 03 ke plan gelombang dan catat keputusan sesi. Doc 03 task 0.6 menyebut plan gelombang dan Sanctum same-site.
- [x] **K3.4** Jalankan audit sitasi dan Unicode. Auditor tidak mengeluarkan temuan sitasi. Pemindaian Unicode melaporkan nol temuan.

### Penutupan knowledge

- [ ] **K4.1** Catat ulang keenam hasil auditor setelah perbaikan. Pada snapshot ini pembungkus keluar dengan kode 0 walaupun A-H mencetak temuan gate D. Periksa verdict setiap auditor.
- [ ] **K4.2** Cocokkan dokumen dengan keluaran migrasi dan daftar rute nyata dari M1/M2 sebelum testing menutup gerbang dokumen.

## Pemutakhiran

Sesi utama dapat meminta subagen `tasklist-updater` memperbarui ID tertentu setelah pekerjaan diserahkan. Cantumkan tanggal, rujukan artefak atau perintah, dan hasil aktual. Untuk langkah parsial atau terblokir, pertahankan kotak kosong dan catat syarat yang tersisa. Simpan log di bawah untuk perubahan setelah snapshot.

| Tanggal | ID | Perubahan dan bukti |
|---|---|---|
| 2026-11-19 | Snapshot | Review working tree, pemeriksaan Docker dan enam auditor. Suite aplikasi tidak dijalankan. |
| 2026-11-19 | P0.06 | Selesai. Feature test API Problem Details dan request ID lulus di PHP 8.4.27 (5 tes, 45 assertions). |
| 2026-11-19 | K3.2 | Dokumentasi selesai. Runbook dan indeks tersedia, pengujian runtime staging masih terbuka. |
| 2026-11-19 | K3.1 | Selesai. Doc 10 bagian 4.3 mengikuti basename migrasi fresh aktual; Unicode dan sitasi lulus. |
| 2026-11-19 | P0.02 | Parsial. Tiga lockfile tersedia, instalasi Composer dari lockfile lulus di PHP 8.4, dan Task 7 lulus kembali. Publikasi config paket masih terbuka. |
| 2026-11-19 | P0.07 | Parsial. Lint PHP 8.4 dan Feature test lane fulfillment di PostgreSQL 16 lulus (2 tes, 6 assertions). Sumbu lain dan audit masih terbuka. |
| 2026-11-19 | P0.08 | Parsial. Kode lease outbox dan scheduler lulus lint. Feature test terarah masih berjalan. |
| 2026-11-19 | P0.02 | Parsial. npm ci dan typecheck kedua frontend lulus dengan install-links dan lockfile sinkron. Publikasi config paket masih terbuka. |
| 2026-11-19 | K1.4 | Selesai. Pemeriksa skema dan audit A-H lulus setelah penyesuaian redaksi Doc 11 dan perluasan runbook. |
| 2026-11-19 | K2.3 | Dibuka kembali untuk Layout I-6. Pemeriksa eksternal salah membaca nama pemilik; pemeriksaan independen memvalidasi 23 migrasi. |
| 2026-11-19 | P0.08 | Parsial. Feature test outbox lulus dengan Composer lockfile di PostgreSQL 16 (3 tes, 13 assertions). Inbox dan idempotensi masih terbuka. |
| 2026-11-20 | P0.10 | Konfirmasi persetujuan pemilik dan klien tercatat di threat model bagian 6. Lokasi artefak bertanda tangan masih menunggu kiriman. |
| 2026-11-20 | P0.11 | Parsial. Feature test admin lulus (3 tes, 97 assertions). OpenAPI codegen lulus (0 error, 16 peringatan yang sudah ada). API dan admin Compose sehat; login 200, overview anonim 302, API sesi anonim 401, CSRF 204 khusus challenge, dan login tanpa CSRF 419. Chrome memverifikasi TOTP, persistensi sesi dan logout. Operator sementara dihapus (1). Storefront auth dan RBAC masih terbuka. |
| 2026-11-20 | P0.08 | Parsial. InboxConsumer Task 10 Step 5 lulus Feature test PostgreSQL 16 (4 tes, 37 assertions). Replay HTTP menunggu keputusan penyimpanan respons. |
| 2026-11-20 | P0.01 | Selesai. Runtime ketiga app dan module loader diverifikasi di Docker lokal; ModuleLoaderTest lulus (3 tes, 7 assertions). |
| 2026-11-20 | P0.03 | Selesai untuk HTTP lokal pada localhost dan admin.localhost port 8088. Compose, Nginx, health dan migrasi lulus. TLS produksi tetap terbuka di M4. |
| 2026-11-20 | P0.06 | Konfirmasi ulang. API sesi anonim pada stack lokal mengembalikan 401 Problem Details. |
| 2026-11-20 | P0.02 | Selesai. Config paket dipublikasikan selektif dan boot production offline di PHP 8.4.28 lulus. Lockfile, npm ci, dan typecheck frontend terverifikasi. |
| 2026-11-20 | P0.04 | Parsial. Run API 44709298358 gagal pada composer lint. Runner context pada workflow contracts sudah diperbaiki setelah run 44709296095 tanpa job. Verifikasi CI baru masih terbuka. |
| 2026-11-20 | P0.09 | Parsial. Audit kode menemukan migrasi 0050 belum menyediakan attribute_changes untuk Activitylog v5. |
| 2026-11-20 | P0.05 | Selesai. FoundationMigrationTest lulus di PHP 8.4.28 dan PostgreSQL 16 terisolasi (1 tes, 43 assertions). Fresh-run lengkap dan katalog lokal PostgreSQL 16.16 juga terverifikasi. |
| 2026-11-20 | P0.04 | Parsial. Workflow contracts 44736742817 hijau pada 4766dd1. Pipeline API masih diverifikasi. |
| 2026-11-20 | P0.07 | Parsial. Runner dan TransitionSink opsional lulus 5 tes, 32 assertions. Integrasi Orders/Audit tetap terbuka. |
| 2026-11-20 | P0.09 | Parsial. Migrasi 0054 lulus uji terarah (1 tes, 11 assertions) dan fresh-run 24 jalur pada PostgreSQL 16 sekali pakai. |
| 2026-11-20 | Audit P0.01-P4.06 | Bukti dan dependensi ditinjau sampai P4.06. Area hilir masih memiliki scaffold atau prasyarat terbuka. Butir terbuka tetap mengikuti bukti verifikasi masing-masing. |
| 2026-11-21 | P0.04 | Parsial. Contracts hijau. API 44838862849 lulus lint, unit, contract dan integration termasuk concurrency. Audit security beralih ke --locked. Hasil CI penuh terbaru masih ditunggu. |
| 2026-11-21 | P0.07 | Parsial. Lock timeout dan stale state diuji di PostgreSQL 16 (1 tes, 6 assertions). Integrasi Orders/Audit dan bukti DB CHECK dari enum masih terbuka. |
| 2026-11-21 | P0.08 | Parsial. Snapshot replay disetujui. Migrasi 0080 dan middleware replay teruji di PostgreSQL 16 terisolasi (6 tes, 84 assertions). Route konsumen, side effect outbox, dan gerbang perilaku B1-B6 masih terbuka. |
