# ADR-011 — Kebijakan alokasi stok: satu gudang sebagai default MVP, parsial fulfillment, dan pintu multi-gudang

Tanggal: 2026-11-17 · Status: **Accepted dengan satu butir terbuka** — default satu gudang berlaku sekarang; multi-gudang **diblokir** jawaban klien (Doc 11 §6 Q-5) · Menyelesaikan bentrok **B-5** (Doc 11 §3: Blueprint §18 butir 10, "baru → ADR-011; default single warehouse MVP") · Rujukan induk: Blueprint §6.1, §6 (Inventory), §5.4, §8.1, §17 Phase 4 (ADR-003)

## Konteks

Blueprint §6.1 memperkenalkan bentuk stok yang tidak ada di skema lama kita: baris agregat per lokasi dengan empat kolom (`on_hand`, `reserved`, `damaged`, `in_transit`), definisi `available_to_sell = on_hand − reserved − unavailable`, dan ledger `stock_movements` yang immutable. Doc 09 lama hanya memiliki ledger + reservasi, tanpa baris agregat dan tanpa konsep tidak-tersedia (Doc 11 B-5). Sejak rekonsiliasi, **ADR-005** sudah menetapkan baris agregat itu sebagai pemegang kebenaran reservasi — dan di titik inilah pertanyaan yang belum terjawab muncul: agregat itu berkunci per apa?

Tiga tekanan membuat ini bukan pertanyaan akademis:

1. Blueprint §6.1 mengunci kunci baris sebagai `(warehouse_id, sku_id)`, sedangkan gudang fisik adalah entitas `warehouses` (hasil rename `stock_locations`, Doc 11 §4.1). Kalau multi-gudang aktif sejak awal, setiap reservasi harus **memilih gudang sebelum** transaksi atomik — itu keputusan alokasi, bukan detail query.
2. Blueprint §5.4 menegaskan satu order multi-item dapat membutuhkan **split shipment dan split procurement**; dengan satu gudang pun kita sudah perlu aturan apa artinya "sebagian item siap".
3. Model kita punya dua "stok" yang berbeda secara fundamental dan tidak boleh dicampur: stok fisik (`stock_items`) untuk jalur RTS, dan kuota batch (`preorder_batch_items`) untuk jalur pre-order, yang dijanjikan tidak menyentuh stok fisik sampai barang benar-benar tiba. Menyatukan keduanya dalam satu kebijakan alokasi adalah cara paling cepat menghasilkan oversell.

Biaya tersembunyi dari multi-gudang juga nyata secara operasional: blueprint sendiri menaruh multi-warehouse di Phase 4 (§17), bukan MVP, sementara asumsi kita di Doc 01 §14 adalah "gudang fisik tunggal".

## Keputusan

**K-1. Default MVP: satu gudang.** Tabel `warehouses` diisi satu baris aktif sejak seed, dan **struktur kunci tetap** `(warehouse_id, sku_id)` di `stock_items`. Konsekuensi penting dari pilihan ini: baris agregat kita **sudah** benar untuk dunia multi-gudang, sehingga mengaktifkan gudang kedua nanti tidak mengubah kunci, tidak mengubah migrasi inti, dan tidak mengubah bentuk UPDATE di ADR-005 — yang berubah hanyalah **cara gudang dipilih**. Inilah alasan keputusan hari ini bukan "menunda desain", melainkan "memakai desain yang tidak perlu dibuka kembali".

**K-2. Definisi ketersediaan ditetapkan satu kali dan hanya di satu tempat.** `available_to_sell = on_hand − reserved − unavailable`, dengan `unavailable = damaged + quarantined` (Doc 11 B-5), dijaga CHECK non-negatif untuk `reserved`, `unavailable`, dan `available`. Angka yang ditampilkan ke storefront boleh datang dari cache sesaat (Blueprint §8.6), tetapi angka yang **dipakai untuk memutuskan reservasi** selalu hasil baca-tulis di dalam transaksi.

**K-3. Untuk jalur pre-order, objek yang dialokasikan adalah kuota batch, bukan unit fisik.** Tidak ada baris `stock_items` yang bergerak sampai `goods_receipts` dicatat; setelah barang tiba, penerimaan itulah yang menaikkan `on_hand` lewat `stock_movements` dan melepas kebutuhan kuota. Aturan ini melindungi dari kegagalan yang paling mahal secara reputasi: menjual stok fisik yang sebenarnya masih di laut.

**K-4. Pemenuhan parsial diizinkan pada level pengiriman, tidak pada level atomicity reservasi.** Cart multi-baris tetap all-or-nothing dalam satu transaksi (kebijakan *partial checkout* eksplisit ditolak oleh ADR-005 butir 4), tetapi setelah pembayaran sah satu order boleh menghasilkan beberapa `packages`/`shipments` bertahap — tiap pengiriman melepas sebagian reservasi dan mencatat `stock_movements` per paket. Sisa reservasi yang tidak terkirim karena barang kurang harus **dilepaskan**, bukan dibiarkan menggantung.

**K-5. Multi-gudang ditunda, dan pintunya terbuka lewat satu pertanyaan klien (Q-5).** Bila klien menjawab "multi-gudang sejak MVP", dua hal berikut menjadi pekerjaan wajib yang **hari ini sengaja tidak kita desain** — inilah biaya konkret yang harus dipertimbangkan klien sebelum menjawab:

- **Pemilihan gudang pada saat reservasi.** Setiap baris cart harus punya satu tujuan gudang sebelum UPDATE kondisional, yang berarti kebijakan alokasi (dekat-customer tercepat, gudang dengan `available_to_sell` terbesar, prioritas gudang impor, atau gabungan berbobot) harus dieksekusi **di luar** transaksi reservasi tetapi dengan data yang tidak boleh basi — dan jawabannya mengubah bentuk API: `warehouse_id` harus masuk kontrak publik karena klien (browser) perlu tahu apa yang sedang ia lihat, bukan ditentukan diam-diam oleh server setelah order jadi.
- **Pencegahan double-reservasi lintas gudang.** Unit yang sama tidak boleh tertahan di dua gudang. Ini bukan sekadar "jumlahkan per gudang lalu pilih": kalau stok tersedia dihitung sebagai **total lintas gudang** sementara reservasi ditulis ke satu baris per gudang, maka reservasi bisa lolos di dua permintaan berbeda secara bersamaan pada gudang yang sama, atau permintaan berikutnya gagal di gudang terpilih meski agregat SKU masih tampak cukup. Bentuk aman yang kita terima sebagai rencana (kalau Q-5 berbunyi "ya"): reservasi **selalu per `(warehouse_id, sku_id)`** — tanpa baris agregat lintas gudang yang boleh di-reserve — ditambah penguncian sesuai urutan SKU dari ADR-005 dan, bila satu baris cart boleh dilayan dari dua gudang, satu keputusan arsitektur baru tentang bagaimana satu baris order dipartisi menjadi beberapa baris pengiriman.

## Alternatif yang ditolak

| | Ditolak | Alasan kalah |
|---|---|---|
| A | `stock_items` berkunci `(sku_id)` saja, tanpa gudang | Murah sekarang dan **memaksa** rewrite kunci + UPDATE reservasi nanti; menunda Q-5 justru jadi keputusan satu-arah yang mahal, bertentangan dengan Blueprint §6.1 |
| B | Multi-gudang penuh sejak MVP (alokasi, transfer, stocktake, prioritas picking) | Bertentangan dengan Blueprint §17 yang menaruh multi-warehouse di Phase 4 dan dengan asumsi Doc 01 §14 (gudang tunggal); menambah pekerjaan di jalur kritis (checkout) tanpa permintaan klien yang terbukti |
| C | Hitung ketersediaan sebagai agregat **lintas gudang** dan reserve ke gudang mana pun yang tersedia | Celah double-reservation yang paling mudah terjadi dan paling sulit dibuktikan salahnya di produksi; bukan sekadar tidak efisien, tapi tidak benar |
| D | Penuhi sebagian dengan mengorbankan atomicity reservasi (reserve item yang ada, lewati yang habis) | Melanggar ADR-005 butir 4 dan mengubah janji cart menjadi teka-teki; kalau klien menginginkan partial checkout, itu harus ADR baru dengan kebijakan harga/ongkir yang eksplisit |
| E | Pakai kuota batch sebagai penahan stok fisik juga | Menghapus batas antara "janji pada batch" dan "unit di rak", membuat `stock_movements` tidak bisa diaudit dan menutup jalur transfer/stocktake nanti |

## Konsekuensi

**Sudah terkunci oleh keputusan ini:** kunci `stock_items` per gudang; `unavailable` sebagai konsep skema (bukan hanya kolom `damaged`); larangan menyentuh stok fisik dari jalur pre-order; dan pemisahan "parsial boleh di pengiriman, tidak boleh di reservasi".

**Menjadi pekerjaan tambahan kalau Q-5 dijawab "multi-gudang":** kolom `warehouse_id` di `order_items`/`packages` dan di kontrak API, modul pemilihan gudang (calon tempat tinggalnya Inventory, diekspos sebagai kontrak karena Orders dan Cart membutuhkannya), pekerjaan rekonsiliasi stok per lokasi di atas `stock_transfers` dan `stocktakes` (dua tabel baru di Doc 11 §4.2), plus satu suite konkurensi baru: dua reservasi paralel pada SKU yang sama di dua gudang berbeda.

**Menjadi tidak mungkin:** mengklaim "zero oversell" untuk multi-lokasi hanya dengan menambah baris `warehouse_id` dan menjumlahkan stok — tanpa aturan di butir 2 K-5, sistem akan oversell di persis satu konfigurasi yang paling sering dipakai orang (drop dengan permintaan tinggi).

**Yang harus dicek manusia sebelum kode pertama:** apakah klien benar-benar punya dua lokasi fisik (gudang lokal + hub impor) atau hanya **satu** lokasi dengan barang yang sedang dalam perjalanan — karena untuk kasus kedua, `in_transit` sebagai kolom di satu gudang sudah cukup, dan multi-gudang tidak perlu ada di MVP sama sekali. Pertanyaan inilah yang kami ajukan sebagai Q-5, dan jawabannya menentukan apakah K-5 tetap tertunda atau naik menjadi ADR-012.
