# ADR-002 — Amandemen model uang: invoice sebagai entitas terpisah dari order

Tanggal: 2026-11-16 · Status: **Accepted** 2026-11-17 (pemilik proyek memilih jalur penuh; implementasi masuk Sprint 2 & 4, skema di Doc 09 §5–6, urutan migrasi di Doc 10) · Dipicu oleh: `docs/05-customer-feature-inventory.md` §5.5

**Catatan jadwal (amandemen 2026-11-17, ADR-003 K-5):** "jalur penuh" yang dipilih saat ADR ini diterima masih dihitung dalam ±18 minggu **pra-Blueprint**; angka itu sudah **diamandemen** menjadi ±22–26 minggu untuk cakupan Blueprint penuh, dan 18 minggu hanya bertahan pada opsi J-b/J-c (Doc 11 §5, keputusan terbuka Q-1). Tidak ada butir keputusan ADR ini yang berubah karena jadwal: model invoice tetap terpisah dari order, dan Sprint 2 & 4 hanyalah penomoran sementara sampai Q-1 dijawab.

## Konteks

Doc 01 §5 mengasumsikan pemetaan sederhana: 1 order RTS = 1 intent lunas; 1 order PO = 1 intent deposit + 1 intent pelunasan.

Observasi atas fitur sejenis di kategori ini memperlihatkan model tagihan yang lebih kaya: satu order dapat memancarkan **beberapa invoice berbeda-jenis dengan jatuh tempo sendiri-sendiri**, yaitu (a) DP harga barang, (b) pelunasan harga barang, (c) biaya leg internasional (pajak/impor + pengiriman luar negeri) yang biasanya baru bisa ditetapkan setelah barang bergerak, dan (d) ongkir domestik — ditambah tagihan tambahan seperti ongkir ulang setelah retur atau biaya perbaikan alamat.

Dampak kalau kita bertahan di asumsi lama: biaya leg-internasional harus "ditempel" ke intent pelunasan dengan angka yang belum diketahui saat order dibuat, sehingga tidak bisa diaudit, tidak bisa diingatkan terpisah, dan rekonsiliasi jadi kabur.

## Alternatif

| | A. Intent saja (desain lama) | B. Invoice → banyak intent (diusulkan) | C. Ledger penuh double-entry |
|---|---|---|---|
| Fleksibilitas tagihan tambahan | buruk (harus ubah nominal intent) | baik (invoice baru dengan tipe + alasan) | sangat baik |
| Kompleksitas | rendah | sedang | tinggi, tidak sepadan untuk 1–2 dev |
| Bukti audit per jenis biaya | lemah | kuat (invoice = dokumen) | kuat |
| Rekonsiliasi gateway | cukup | cukup | lebih dari kebutuhan |

## Keputusan

Ambil **B**, dengan struktur:

```
orders  1 order
  └─ invoices            1..n per order, bertipe: deposit | balance | intl_fees | domestic_ship | extra_ship | adjustment
       ├─ amount, due_at, status(open|paid|overdue|void|refunded), reason (wajib bila tipe adjustment/extra)
       ├─ payment_intents   1 invoice → 1 intent aktif (+ riwayat percobaan)
       └─ payment_ledger    entri append-only per invoice
order_settlements        ringkasan: total ditagih vs total diterima vs refund
```

Aturan turunannya:
1. **A1** Nominal `intl_fees` boleh ditetapkan setelah GRN (karena berat/pajak nyata belum diketahui saat PO dibuka); invoice tipe ini dibuat otomatis oleh job saat batch ditandai tiba, dengan rentang maksimum dari konfigurasi batch (agar tidak ada "tagihan kejutan" tanpa batas).
2. **A2** Satu invoice = satu jatuh tempo = satu reminder track. Halaman customer "cek jatuh tempo" menjadi query sederhana: `invoices where status=open order by due_at`.
3. **A3** Pembayaran menggabungkan beberapa invoice hanya kalau gateway mendukung; default kita: **satu invoice satu pembayaran**, digabung di UI sebagai "bayar semua tunggakan" yang secara teknis membuat satu intent gabungan dengan alokasi per invoice. Alokasi disimpan di ledger supaya refund sebagian tetap mungkin.
4. **A4** Tandai-lunas-manual diperbolehkan (transfer bank/EDM) tetapi wajib bukti + role Finance + persetujuan kedua di atas ambang; ia menulis ledger dengan `source=manual`.
5. **A5** Kebijakan deposit (Doc 02 B-PO-3) dioperasikan pada invoice bertipe `deposit`: status `expired` → tindakan sesuai konfigurasi batch (hangus / voucher / perpanjangan sekali yang dicatat di audit).
6. **A6**checkout tetap menghasilkan satu order; invoice dibuat dalam transaksi yang sama (`deposit`/`lunas`) dan sisanya dibuat kemudian oleh batch job atau admin.

## Konsekuensi

- Skema: tambah `invoices` + kolom `invoice_id` di `payment_intents`; `payment_ledger` berkey ke invoice, bukan order.
- Modul **Payments** tetap punya intent; kepemilikan "siapa menagih apa" pindah ke modul baru kecil: **Billing** (di dalam kelompok Orders/PreOrder, bukan service sendiri).
- Sprint 2 meluaskan cakupan: "billing center" customer (daftar tagihan + bayar) naik dari sekadar "invoice PDF".
- Sprint 4 bertambah: job pembuatan invoice `intl_fees` saat GRN + laporan umur piutang per tipe.
- Estimasi: +1,5 hari (Sprint 2) dan +2 hari (Sprint 4); terserap buffer 2 sprint di Doc 03 §1.

## Pertanyaan terbuka

Berapa lamanya masa reminder sebelum jatuh tempo, dan apakah biaya internasional boleh ditagih sebelum customer konfirmasi nominal? (Klien harus memutuskan; default kami: tampilkan estimasi saat PO, tagih nilai nyata saat tiba, dengan batas atas dari konfigurasi batch.)
