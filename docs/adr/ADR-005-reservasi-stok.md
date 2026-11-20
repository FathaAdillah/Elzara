# ADR-005 — Reservasi stok ber-TTL dan resolusi pembayaran terlambat

Tanggal: 2026-11-17 · Status: **Accepted** · Menyelesaikan bentrok **B-6** (Doc 11 §1) · Rujukan induk: Blueprint §8.1, §8.2, §8.4, §8.5, §16 (ADR-003)

## Konteks

Doc 02 dan Doc 03 lama hanya menyebut "reservasi dengan TTL" sebagai frase; tidak ada satu pun dokumen yang menentukan **mekanisme** reservasi, **urutan kunci**, atau apa yang terjadi kalau pembayaran sah tiba **setelah** stok dilepaskan. Blueprint §18 butir 4 menuntut ketiganya diputuskan sebelum coding, karena empat hal bersamaan:

- target non-fungsional §1 adalah *zero oversell* pada stok yang dikendalikan sistem, dan §16 memberi angka penerimaan yang keras: 1 SKU × stok 10 × 1.000 checkout paralel ⇒ **maksimal 10 reservasi sukses**, tidak ada `available` negatif; batch kuota 50 × 500 permintaan paralel ⇒ **maksimal 50**.
- jalur stok fisik (`stock_items`) dan jalur kuota batch (`preorder_batch_items`) adalah dua objek uang-versi-stok yang berbeda; keduanya harus atomic dengan cara yang sama.
- kasus tersulit bukan kekurangan stok, tapi **balapan** antara webhook pembayaran dan pekerja kedaluwarsa (§8.4) — dan kita tidak boleh menyelesaikannya dengan menjual barang yang tidak ada.
- reservasi tidak ada artinya tanpa idempotensi; itu porsi **ADR-004**, dan kunci keberhasilan dua ADR ini adalah satu urutan lock yang dipakai kedua sisi.

## Keputusan

1. **Kontrol kebenaran utama = `UPDATE` kondisional atomik di PostgreSQL**, bukan kunci terdistribusi. Bentuknya persis Blueprint §8.1: `UPDATE stock_items SET reserved = reserved + :qty WHERE warehouse_id = :w AND sku_id = :s AND on_hand - reserved - unavailable >= :qty RETURNING id`; **nol baris = stok habis**, transaksi di-rollback. Tidak ada baca-then-tulis.
2. **Definisi ketersediaan ditetapkan sekali:** `available_to_sell = on_hand − reserved − unavailable`, dengan `unavailable = damaged + quarantined` (Doc 11 B-5) dan `CHECK` non-negatif untuk `reserved`, `unavailable`, dan `available` — constraint sebagai pertahanan terakhir (Blueprint §6).
3. **Satu baris agregat per `(warehouse_id, sku_id)`** adalah pemegang kebenaran reservasi. Ledger `stock_movements` tetap append-only (S-6) sebagai sejarah dan dasar rekonsiliasi; ia **bukan** jalur reservasi. Invarian `SUM(stock_movements)` vs `stock_items` dijaga pekerjaan rekonsiliasi berkala, bukan dipercaya buta — kalau dua angka ini bertolak, itu insiden, bukan perbedaan tampilan.
4. **Urutan kunci SKU yang konsisten** untuk cart multi-baris (naik menurut `sku_id`) — sumber deadlock utama adalah dua cart mengunci SKU sama urutan berbeda. Kalau satu baris gagal, seluruh transaksi di-rollback (default); kebijakan *partial checkout* hanya boleh ada sebagai keputusan tertulis berikutnya, bukan sebagai perilaku fallback di kode.
5. **Retry deadlock terbatas di lapis Command**: jumlah percobaan maksimum + jeda eksponensial ber-jitter, dieksekusi di Command `ReserveStock`/`PlaceOrder` (aturan B2 — controller/HTTP tidak memegang logika ini), sehingga client melihat `409`/`429` problem-details setelah batas habis, bukan 500.
6. **TTL reservasi disejalkan dengan kedaluwarsa intent pembayaran**, disimpan di `stock_reservations.expires_at`. Default awal: mengikuti `payment_intents.expires_at` untuk invoice pelunasan (2 jam, warisan aturan U2), dapat dikonfigurasi per jalur. Ini bukan angka sakral — ia harus direview setelah driver gateway dipilih (ADR-006).
7. **Kuota batch dengan pola yang sama**: `UPDATE preorder_batch_items SET reserved_qty = reserved_qty + :qty WHERE id = :i AND reserved_qty + :qty <= quota AND status = 'open' AND opens_at <= NOW() AND closes_at > NOW() RETURNING id` (Blueprint §8.5). Penutupan batch (`CloseBatch`) mengunci baris state batch, menolak enrollment baru, dan membekukan jumlah accepted dalam transaksi yang sama, supaya tidak ada TOCTOU antara cutoff dan reservasi kuota.
8. **Resolusi pembayaran terlambat — aturan intinya: jangan pernah oversell.** Urutannya:
   a. Pekerja kedaluwarsa hanya melepas reservasi kalau order masih **belum dibayar** dan belum ada pembayaran terkonfirmasi; ia mengunci baris order **dan** baris reservasi dalam satu transaksi, memakai **urutan lock yang sama** dengan handler webhook, dan hanya boleh bertransisi lewat CAS/status guard.
   b. Webhook yang datang di dekat ambang kedaluwarsa diproses dengan guard yang sama; hasil balapan ditentukan oleh constraint database, bukan oleh siapa yang sampai lebih dulu di memori aplikasi.
   c. Kalau pembayaran sah tiba setelah stok dilepas: **coba reservasi ulang**; kalau gagal, order ditandai `paid_stock_exception` (kolom/status yang dijanjikan Doc 11 B-6) dan masuk daftar kerja rekonsiliasi. Opsi yang tersedia bagi staf hanyalah refund, realokasi ke batch/gudang lain, atau resolusi manual dengan alasan tercatat. **Opsi "kirim saja walau stok nol" tidak tersedia.**
   d. Pekerjaan rekonsiliasi provider (§8.4 butir 5) berjalan harian terhadap `payment_ledger` (aturan U5), dengan dashboard pengecualian; tidak ada auto-adjust.
9. **Cache bukan sumber kebenaran stok.** Redis boleh melayani katalog yang basi sesaat; nilai stok final selalu hasil baca/tulis `stock_items` di dalam transaksi (Blueprint §8.6). Tidak ada lock global untuk seluruh situs; partisi panas ada per SKU/batch, ditambah admission dan rate limit di checkout.

## Alternatif yang ditolak

| | Ditolak | Alasan kalah |
|---|---|---|
| A | **Kunci terdistribusi Redis sebagai mekanisme kebenaran** (redlock-ish) | Blueprint §1 dan §8.2 eksplisit melarang: "distributed lock bukan satu-satunya mekanisme kebenaran". Lease bisa kedaluwarsa sebelum pemiliknya selesai (GC pause, jaringan, worker di-restart saat deploy), dan dua proses pegang "kunci" sama ⇒ oversell yang tidak tertangkap constraint apa pun. Lock Redis hanya boleh dipakai sebagai **optimasi** hot key/scheduler, setelah pagar DB ada |
| B | `SELECT … FOR UPDATE` lalu `UPDATE` nilai stok | Benar secara semantik tapi memperpanjang durasi lock dan membuka jendela deadlock lebih lebar daripada `UPDATE` kondisional satu statement; Blueprint §8.2 menempatkan `FOR UPDATE` untuk "perubahan state kompleks", bukan untuk jalur panas reservasi |
| C | Optimistic version pada baris stok | Menolak penulis yang sah di trafik tinggi ⇒ gagal-then-retry pada 1.000 paralel berarti rasio kegagalan tinggi; cocok untuk `quotation_versions` dan sunting admin, bukan stok |
| D | Kurangi `on_hand` langsung saat order (tanpa `reserved`) | Kehilangan jejak "dipesan tapi belum dibayar", membatalkan pelepasan saat kedaluwarsa, dan membuat stok fisik vs stok tampil berbeda saat pick |
| E | Menyelesaikan late payment dengan mengirim barang + pesan nanti | Melanggar nol-oversell secara sistematis dan memindahkan kerugian ke janji ETA pelanggan; satu-satunya jalur sah adalah `paid_stock_exception` |

## Konsekuensi

**Mahal:** satu baris agregat per SKU-per-gudang adalah titik panas — pada SKU drop, semua writers antri di sana. Itu harga yang kita terima sadar sebagai ganti kebenaran; mitigasi yang sah hanyalah admission/queue di tepi (ADR-009) dan pemisahan baca-tulis, bukan pengenduran pagar.

**Wajib ada agar keputusan ini tidak membusuk:** `stock_items` dan `stock_reservations.expires_at` masuk Doc 09; test konkurensi nyata di PostgreSQL (Testcontainers, bukan SQLite — Blueprint §16 menutup alasan "lampau cepat di CI") dan dua test balapan di §16 (webhook vs expiry; late paid → exception). Angka penerimaannya adalah definisi selesai, bukan aspirasi.

**Jadi mustahil:** menjual stok yang tidak dimiliki, double-reserve unit yang sama di dua tempat (lihat aturan alokasi **ADR-011**), dan klaim "sistem anti-oversell" tanpa tes 1.000 paralel.

**Kalau diabaikan:** efeknya bukan bug kecil tapi kerugian finansial — barang terkirim tanpa stok, ETA batch bohong, dan refund massal setelah drop.

## Pertanyaan yang masih terbuka

Berapa TTL reservasi yang benar per jalur setelah gateway dipilih (ADR-006) dan berapa lama grace window pembayaran sebelum `paid_stock_exception` resmi? Kedua angka itu kebijakan bisnis; default sementara kami: TTL = expiry intent, tanpa grace window (langsung exception) supaya tidak ada ruang tawar-menawar di dalam kode.
